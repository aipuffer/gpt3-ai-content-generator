<?php

namespace WPAICG\Vector\Extraction;

use DOMDocument;
use DOMNode;
use DOMXPath;
use WP_Post;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Extracts the primary human-readable content used for knowledge-base indexing.
 *
 * This core extractor intentionally handles public post content only. Advanced
 * field selection and provider-specific rules remain separate concerns.
 */
final class AIPKit_Knowledge_Content_Extractor
{
    private const BUILDER_META_KEYS = [
        '_elementor_data',
        '_elementor_edit_mode',
        '_fl_builder_data',
        '_fl_builder_enabled',
        'panels_data',
        '_wpb_vc_js_status',
        '_et_pb_use_builder',
        '_fusion_builder_status',
    ];
    private const BUILDER_SHORTCODE_PREFIXES = [
        'vc_',
        'et_pb_',
        'fusion_',
        'fl_builder_',
        'siteorigin_',
        'nectar_',
        'colibri_',
    ];
    private const WEAK_CONTENT_MIN_CHARS = 180;
    private const WEAK_CONTENT_RAW_MIN_CHARS = 1000;
    private const WEAK_CONTENT_TEXT_RATIO = 0.18;
    private const RENDERED_CONTENT_MIN_CHARS = 160;
    private const RENDERED_CONTENT_MIN_GAIN = 80;
    private const RENDERED_CONTENT_MIN_RATIO = 1.35;
    private const HTTP_TIMEOUT_SECONDS = 8;
    private const HTTP_MAX_REDIRECTS = 3;
    private const HTTP_MAX_RESPONSE_BYTES = 2097152;
    private const MAX_CONTENT_CHARS = 500000;

    /**
     * Extract the best available primary content for a post.
     *
     * @return array{
     *     content:string,
     *     source:string,
     *     diagnostics:array{
     *         raw_chars:int,
     *         standard_chars:int,
     *         weak_standard_content:bool,
     *         builder_content_detected:bool,
     *         extraction_mode:string,
     *         rendered_fallback_attempted:bool,
     *         rendered_fallback_used:bool,
     *         rendered_chars:int,
     *         fallback_reason:string,
     *         content_truncated:bool
     *     }
     * }
     */
    public static function extract(WP_Post $post): array
    {
        $raw_content = (string) $post->post_content;
        $standard_content = self::extract_standard_content($raw_content, $post);
        $weak_standard_content = self::is_weak_content($raw_content, $standard_content);
        $builder_content_detected = self::has_builder_content_signal($post, $raw_content);
        $content = $standard_content;
        $source = 'standard';
        $rendered_content = '';
        $fallback_attempted = false;
        $fallback_used = false;
        $should_attempt_fallback = $weak_standard_content || $builder_content_detected;
        $fallback_reason = 'standard_content_sufficient';
        if ($weak_standard_content) {
            $fallback_reason = 'weak_standard_content';
        } elseif ($builder_content_detected) {
            $fallback_reason = 'builder_content_detected';
        }

        if ($should_attempt_fallback && self::can_fetch_rendered_content($post)) {
            $fallback_attempted = true;
            $rendered_result = self::fetch_rendered_content($post);
            $rendered_content = $rendered_result['content'];
            $fallback_reason = $rendered_result['reason'];

            $should_use_rendered_content = self::is_materially_better($rendered_content, $standard_content);
            if ($should_use_rendered_content) {
                $content = $rendered_content;
                $source = 'rendered';
                $fallback_used = true;
                $fallback_reason = 'rendered_content_selected';
            } elseif ($rendered_content !== '') {
                $fallback_reason = 'rendered_content_not_better';
            }
        } elseif ($should_attempt_fallback) {
            $fallback_reason = 'rendered_fallback_not_allowed';
        }

        $content_truncated = self::string_length($content) > self::MAX_CONTENT_CHARS;
        if ($content_truncated) {
            $content = self::string_substr($content, 0, self::MAX_CONTENT_CHARS);
        }

        $result = [
            'content' => $content,
            'source' => $source,
            'diagnostics' => [
                'raw_chars' => self::string_length($raw_content),
                'standard_chars' => self::string_length($standard_content),
                'weak_standard_content' => $weak_standard_content,
                'builder_content_detected' => $builder_content_detected,
                'extraction_mode' => 'auto',
                'rendered_fallback_attempted' => $fallback_attempted,
                'rendered_fallback_used' => $fallback_used,
                'rendered_chars' => self::string_length($rendered_content),
                'fallback_reason' => $fallback_reason,
                'content_truncated' => $content_truncated,
            ],
        ];

        /**
         * Filters the primary content extraction result before indexing.
         *
         * Extensions may replace the content or add diagnostics, but should
         * preserve the top-level content, source, and diagnostics keys.
         *
         * @param array   $result Extraction result.
         * @param WP_Post $post   Post being indexed.
         */
        $filtered_result = apply_filters('aipkit_knowledge_base_content_extraction_result', $result, $post);
        if (is_array($filtered_result) && isset($filtered_result['content']) && is_scalar($filtered_result['content'])) {
            $result = $filtered_result;
            $result['content'] = self::normalize_text((string) $result['content']);
            $result['source'] = isset($result['source']) && is_scalar($result['source'])
                ? sanitize_key((string) $result['source'])
                : $source;
            $result['diagnostics'] = isset($result['diagnostics']) && is_array($result['diagnostics'])
                ? $result['diagnostics']
                : [];
        }

        if (self::string_length($result['content']) > self::MAX_CONTENT_CHARS) {
            $result['content'] = self::string_substr($result['content'], 0, self::MAX_CONTENT_CHARS);
            $result['diagnostics']['content_truncated'] = true;
        }

        /**
         * Fires after primary content extraction has completed.
         *
         * @param array   $diagnostics Extraction diagnostics.
         * @param WP_Post $post        Post being indexed.
         * @param array   $result      Complete extraction result.
         */
        do_action('aipkit_knowledge_base_content_extraction_diagnostics', $result['diagnostics'], $post, $result);

        return $result;
    }

    private static function extract_standard_content(string $raw_content, WP_Post $post): string
    {
        $content = $raw_content;
        $should_execute_shortcodes = (bool) apply_filters(
            'aipkit_knowledge_base_index_execute_shortcodes',
            false,
            $post
        );

        if (!$should_execute_shortcodes) {
            $content = self::strip_shortcode_tags_preserve_content($content);
        }

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Apply WordPress's standard public content pipeline before indexing.
        $content = apply_filters('the_content', $content);

        return self::normalize_fragment((string) $content);
    }

    /**
     * Normalize a trusted content fragment without executing its shortcodes.
     */
    public static function normalize_fragment(string $content): string
    {
        $without_executable_markup = preg_replace(
            '/<(script|style|noscript|template|svg|canvas|form)\b[^>]*>.*?<\/\1>/is',
            ' ',
            $content
        );
        if (is_string($without_executable_markup)) {
            $content = $without_executable_markup;
        }

        $content = self::strip_shortcode_tags_preserve_content($content);
        $content = wp_strip_all_tags($content, true);
        $content = self::strip_shortcode_tags_preserve_content($content);

        return self::normalize_text($content);
    }

    /**
     * Remove registered and recognized builder shortcode markup without
     * discarding enclosed text.
     *
     * WordPress core's strip_shortcodes() removes an entire enclosing shortcode,
     * including its human-readable inner content. Builder layouts commonly nest
     * their actual copy inside those tags, so indexing needs the safer behavior.
     * Builder tags are recognized even when their plugin has not registered its
     * shortcodes in the current admin, AJAX, cron, or WP-CLI request.
     */
    private static function strip_shortcode_tags_preserve_content(string $content): string
    {
        global $shortcode_tags;

        if (strpos($content, '[') === false) {
            return $content;
        }

        $matches = [];
        preg_match_all('@\[([^<>&/\[\]\x00-\x20=]++)@', $content, $matches);
        $registered_tags = is_array($shortcode_tags) ? $shortcode_tags : [];
        $tag_names = array_values(
            array_unique(
                array_filter(
                    $matches[1] ?? [],
                    static function ($tag_name) use ($registered_tags, $content): bool {
                        return isset($registered_tags[$tag_name]) || self::is_builder_shortcode_tag((string) $tag_name, $content);
                    }
                )
            )
        );
        if (empty($tag_names)) {
            return $content;
        }

        $content = do_shortcodes_in_html_tags($content, true, $tag_names);
        $pattern = get_shortcode_regex($tag_names);

        // Repeat to unwrap nested builder shortcodes one level at a time.
        for ($iteration = 0; $iteration < 10; $iteration++) {
            $previous_content = $content;
            $content = preg_replace_callback(
                "/$pattern/",
                static function (array $shortcode_match): string {
                    if ($shortcode_match[1] === '[' && $shortcode_match[6] === ']') {
                        return substr($shortcode_match[0], 1, -1);
                    }

                    $inner_content = isset($shortcode_match[5]) ? (string) $shortcode_match[5] : '';
                    return $shortcode_match[1] . $inner_content . $shortcode_match[6];
                },
                $content
            );
            if (!is_string($content)) {
                return $previous_content;
            }
            if ($content === $previous_content) {
                break;
            }
        }

        return unescape_invalid_shortcodes($content);
    }

    private static function is_builder_shortcode_tag(string $tag_name, string $content = ''): bool
    {
        $normalized_tag_name = strtolower($tag_name);
        foreach (self::BUILDER_SHORTCODE_PREFIXES as $prefix) {
            if (strpos($normalized_tag_name, $prefix) === 0) {
                return true;
            }
        }

        // Some builders use unprefixed, compound tags. Require a layout
        // attribute as well, so ordinary bracketed prose and citations survive.
        if (!preg_match('/^[a-z][a-z0-9]*(?:[_-][a-z0-9]+)+$/i', $tag_name)) {
            return false;
        }

        return (bool) preg_match(
            '/(?<!\[)\[' . preg_quote($tag_name, '/')
            . '\s+[^\]\r\n]*\b(?:image_url|image_size|animation_type|animation_movement_type|column_padding|el_class|text_align|font_size|background_color)\s*=/i',
            $content
        );
    }

    private static function is_weak_content(string $raw_content, string $standard_content): bool
    {
        $standard_length = self::string_length($standard_content);
        if ($standard_length < self::WEAK_CONTENT_MIN_CHARS) {
            return true;
        }

        $raw_length = self::string_length($raw_content);
        if ($raw_length < self::WEAK_CONTENT_RAW_MIN_CHARS) {
            return false;
        }

        return ($standard_length / max(1, $raw_length)) < self::WEAK_CONTENT_TEXT_RATIO;
    }

    /**
     * Detect common builder storage without exposing or indexing private data.
     *
     * Builder metadata is used only as a signal to compare the stored editor
     * content with the same public page. The rendered page still has to be
     * materially richer before it replaces the standard extraction result.
     */
    private static function has_builder_content_signal(WP_Post $post, string $raw_content): bool
    {
        foreach (self::BUILDER_META_KEYS as $meta_key) {
            if (!metadata_exists('post', $post->ID, $meta_key)) {
                continue;
            }

            $meta_value = get_post_meta($post->ID, $meta_key, true);
            if ((is_scalar($meta_value) && trim((string) $meta_value) !== '' && (string) $meta_value !== '0')
                || (is_array($meta_value) && $meta_value !== [])
                || is_object($meta_value)
            ) {
                return true;
            }
        }

        $matches = [];
        preg_match_all('@\[([^<>&/\[\]\x00-\x20=]++)@', $raw_content, $matches);
        foreach ($matches[1] ?? [] as $tag_name) {
            if (self::is_builder_shortcode_tag((string) $tag_name, $raw_content)) {
                return true;
            }
        }

        return false;
    }

    private static function can_fetch_rendered_content(WP_Post $post): bool
    {
        if ($post->post_password !== '') {
            return false;
        }

        if (function_exists('is_post_publicly_viewable')) {
            if (!is_post_publicly_viewable($post)) {
                return false;
            }
        } elseif ($post->post_status !== 'publish') {
            return false;
        }

        $permalink = get_permalink($post);
        if (!is_string($permalink) || $permalink === '') {
            return false;
        }

        return self::is_same_origin_url($permalink, home_url('/'));
    }

    /**
     * @return array{content:string,reason:string}
     */
    private static function fetch_rendered_content(WP_Post $post): array
    {
        $permalink = get_permalink($post);
        if (!is_string($permalink) || $permalink === '') {
            return ['content' => '', 'reason' => 'missing_permalink'];
        }

        $response = wp_safe_remote_get(
            $permalink,
            [
                'timeout' => self::HTTP_TIMEOUT_SECONDS,
                'redirection' => self::HTTP_MAX_REDIRECTS,
                'limit_response_size' => self::HTTP_MAX_RESPONSE_BYTES,
                'user-agent' => 'AI Puffer Knowledge Base Indexer/' . (defined('WPAICG_VERSION') ? WPAICG_VERSION : 'unknown'),
            ]
        );

        if (is_wp_error($response)) {
            return ['content' => '', 'reason' => 'rendered_request_failed'];
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        if ($status_code < 200 || $status_code >= 300) {
            return ['content' => '', 'reason' => 'rendered_response_not_successful'];
        }

        $content_type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        if ($content_type !== '' && strpos($content_type, 'text/html') === false && strpos($content_type, 'application/xhtml+xml') === false) {
            return ['content' => '', 'reason' => 'rendered_response_not_html'];
        }

        $body = (string) wp_remote_retrieve_body($response);
        if ($body === '') {
            return ['content' => '', 'reason' => 'rendered_response_empty'];
        }

        $content = self::extract_semantic_html_content($body);
        if ($content === '') {
            return ['content' => '', 'reason' => 'rendered_semantic_content_missing'];
        }

        return ['content' => $content, 'reason' => 'rendered_content_available'];
    }

    private static function extract_semantic_html_content(string $html): string
    {
        if (!class_exists(DOMDocument::class)) {
            return self::extract_semantic_html_without_dom($html);
        }

        $previous_libxml_state = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous_libxml_state);

        if (!$loaded) {
            return '';
        }

        $xpath = new DOMXPath($document);
        $excluded_nodes = $xpath->query('//script|//style|//noscript|//template|//svg|//canvas|//nav|//header|//footer|//aside|//form');
        if ($excluded_nodes !== false) {
            for ($index = $excluded_nodes->length - 1; $index >= 0; $index--) {
                $node = $excluded_nodes->item($index);
                if ($node instanceof DOMNode && $node->parentNode instanceof DOMNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        $candidate_query = '//main|//article|//*[@role="main"]'
            . '|//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]'
            . '|//*[contains(concat(" ", normalize-space(@class), " "), " post-content ")]'
            . '|//*[contains(concat(" ", normalize-space(@class), " "), " page-content ")]'
            . '|//*[contains(concat(" ", normalize-space(@class), " "), " main-content ")]'
            . '|//*[contains(concat(" ", normalize-space(@class), " "), " content-area ")]'
            . '|//*[contains(concat(" ", normalize-space(@class), " "), " site-main ")]'
            . '|//*[@id="main" or @id="content" or @id="primary"]';
        $candidate_nodes = $xpath->query($candidate_query);
        if ($candidate_nodes === false || $candidate_nodes->length === 0) {
            return '';
        }

        $best_content = '';
        foreach ($candidate_nodes as $candidate_node) {
            $candidate_content = self::normalize_text((string) $candidate_node->textContent);
            if (self::string_length($candidate_content) > self::string_length($best_content)) {
                $best_content = $candidate_content;
            }
        }

        return $best_content;
    }

    private static function extract_semantic_html_without_dom(string $html): string
    {
        $matches = [];
        preg_match_all('/<(main|article)\b[^>]*>(.*?)<\/\1>/is', $html, $matches);
        if (empty($matches[2])) {
            return '';
        }

        $best_content = '';
        foreach ($matches[2] as $candidate_html) {
            $candidate_html = preg_replace(
                '/<(script|style|noscript|template|svg|canvas|nav|header|footer|aside|form)\b[^>]*>.*?<\/\1>/is',
                ' ',
                (string) $candidate_html
            );
            $candidate_content = self::normalize_text(wp_strip_all_tags((string) $candidate_html, true));
            if (self::string_length($candidate_content) > self::string_length($best_content)) {
                $best_content = $candidate_content;
            }
        }

        return $best_content;
    }

    private static function is_materially_better(string $rendered_content, string $standard_content): bool
    {
        $rendered_length = self::string_length($rendered_content);
        $standard_length = self::string_length($standard_content);
        $minimum_length = $standard_length === 0
            ? self::RENDERED_CONTENT_MIN_CHARS
            : max(self::RENDERED_CONTENT_MIN_CHARS, $standard_length + self::RENDERED_CONTENT_MIN_GAIN);

        if ($rendered_length < $minimum_length) {
            return false;
        }

        if ($standard_length === 0) {
            return true;
        }

        return ($rendered_length / $standard_length) >= self::RENDERED_CONTENT_MIN_RATIO;
    }

    private static function is_same_origin_url(string $url, string $site_url): bool
    {
        $url_parts = wp_parse_url($url);
        $site_parts = wp_parse_url($site_url);
        if (!is_array($url_parts) || !is_array($site_parts)) {
            return false;
        }

        $scheme = strtolower((string) ($url_parts['scheme'] ?? ''));
        $site_scheme = strtolower((string) ($site_parts['scheme'] ?? ''));
        $host = strtolower((string) ($url_parts['host'] ?? ''));
        $site_host = strtolower((string) ($site_parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $scheme !== $site_scheme || $host === '' || $host !== $site_host) {
            return false;
        }

        $port = isset($url_parts['port']) ? (int) $url_parts['port'] : ($scheme === 'https' ? 443 : 80);
        $site_port = isset($site_parts['port']) ? (int) $site_parts['port'] : ($site_scheme === 'https' ? 443 : 80);

        return $port === $site_port;
    }

    private static function normalize_text(string $content): string
    {
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = preg_replace('/\s+/u', ' ', $content);

        return trim(is_string($normalized) ? $normalized : $content);
    }

    private static function string_length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private static function string_substr(string $value, int $start, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, $start, $length) : substr($value, $start, $length);
    }
}
