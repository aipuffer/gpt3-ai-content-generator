<?php

namespace WPAICG\ContentWriter;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Removes assistant-style chat tails from generated article content.
 */
class AIPKit_Content_Writer_Output_Cleaner
{
    private const META_DESCRIPTION_MAX_LENGTH = 156;

    public static function clean_title(string $title, string $focus_keyword = ''): string
    {
        $title = trim(str_replace(["\n", "\r"], ' ', $title));
        $title = preg_replace('/\s+/u', ' ', $title);

        return self::normalize_leading_focus_keyword_casing((string) $title, $focus_keyword);
    }

    public static function clean_meta_description(string $description, int $max_length = self::META_DESCRIPTION_MAX_LENGTH): string
    {
        $max_length = max(40, $max_length);
        $description = trim(str_replace(['"', "'"], '', $description));
        $description = html_entity_decode(wp_strip_all_tags($description), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8');
        $description = trim((string) preg_replace('/\s+/u', ' ', $description));

        if ($description === '' || self::text_length($description) <= $max_length) {
            return $description;
        }

        $cut = self::text_substr($description, 0, $max_length);
        $sentence_floor = max(40, min(110, $max_length - 10));
        if (preg_match('/^(.{' . $sentence_floor . ',' . $max_length . '}[.!?]).*$/us', $cut, $sentence_match)) {
            $sentence_cut = trim((string) ($sentence_match[1] ?? ''));
            if ($sentence_cut !== '' && self::text_length($sentence_cut) <= $max_length) {
                return $sentence_cut;
            }
        }

        if (preg_match('/^(.{90,' . $max_length . '})[,;:].*$/us', $cut, $clause_match)) {
            $clause_cut = rtrim(trim((string) ($clause_match[1] ?? '')), " \t\n\r\0\x0B,;:-");
            if ($clause_cut !== '' && self::text_length($clause_cut) <= $max_length) {
                return $clause_cut;
            }
        }

        $space_position = function_exists('mb_strrpos') ? mb_strrpos($cut, ' ', 0, 'UTF-8') : strrpos($cut, ' ');
        if ($space_position !== false && $space_position >= 90) {
            $cut = self::text_substr($cut, 0, (int) $space_position);
        }

        return rtrim(trim($cut), " \t\n\r\0\x0B,;:-");
    }

    public static function clean_article_content(string $content, string $focus_keyword = ''): string
    {
        $content = self::remove_assistant_followup($content);
        return self::normalize_leading_focus_keyword_casing($content, $focus_keyword);
    }

    public static function convert_basic_markdown_to_html(string $content): string
    {
        $html = $content;
        $html = preg_replace('/^#\s+(.*)$/m', '<h1>$1</h1>', $html);
        $html = preg_replace('/^##\s+(.*)$/m', '<h2>$1</h2>', (string) $html);
        $html = preg_replace('/^###\s+(.*)$/m', '<h3>$1</h3>', (string) $html);
        $html = preg_replace('/^####\s+(.*)$/m', '<h4>$1</h4>', (string) $html);
        $html = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', (string) $html);
        $html = preg_replace('/(?<!\*)\*(?!\*|_)(.*?)(?<!\*|_)\*(?!\*)/s', '<em>$1</em>', (string) $html);
        $html = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', (string) $html);

        return (string) $html;
    }

    public static function remove_assistant_followup(string $content): string
    {
        if (trim($content) === '') {
            return $content;
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        $marker_pattern = '/(?:^|\n|\s*<p[^>]*>\s*)(?:If\s+you\s+(?:want|would\s+like|need)|If\s+you(?:\'|\x{2019})d\s+like|Would\s+you\s+like|I\s+can\s+also)\b/iu';

        if (!preg_match_all($marker_pattern, $normalized, $matches, PREG_OFFSET_CAPTURE)) {
            return $content;
        }

        $markers = array_reverse($matches[0]);
        $content_length = strlen($normalized);

        foreach ($markers as $marker) {
            $position = (int) $marker[1];
            if (!self::is_near_article_end($position, $content_length)) {
                continue;
            }

            $tail = (string) substr($normalized, $position);
            if (!self::looks_like_assistant_followup($tail)) {
                continue;
            }

            $cleaned = rtrim((string) substr($normalized, 0, $position));
            return $cleaned !== '' ? $cleaned : $content;
        }

        return $content;
    }

    public static function normalize_leading_focus_keyword_casing(string $content, string $focus_keyword): string
    {
        $focus_keyword = trim(wp_strip_all_tags($focus_keyword));
        if ($content === '' || $focus_keyword === '') {
            return $content;
        }

        $replacement = self::sentence_case_keyphrase($focus_keyword);
        if ($replacement === $focus_keyword) {
            return $content;
        }

        $quoted_keyword = preg_quote($focus_keyword, '/');
        $boundary = '(?![\p{L}\p{N}])';

        $content = preg_replace_callback(
            '/(<(?:p|li|blockquote)\b[^>]*>\s*)' . $quoted_keyword . $boundary . '/u',
            static function (array $match) use ($replacement): string {
                return (string) ($match[1] ?? '') . $replacement;
            },
            $content
        );

        $content = preg_replace_callback(
            '/(^|\n\s*\n)(\s*)' . $quoted_keyword . $boundary . '/u',
            static function (array $match) use ($replacement): string {
                return (string) ($match[1] ?? '') . (string) ($match[2] ?? '') . $replacement;
            },
            (string) $content
        );

        return (string) $content;
    }

    private static function sentence_case_keyphrase(string $keyphrase): string
    {
        $keyphrase = trim($keyphrase);
        if ($keyphrase === '') {
            return '';
        }

        if (!preg_match('/^([^\p{L}\p{N}]*)([\p{L}\p{N}][\p{L}\p{N}\'’-]*)(.*)$/u', $keyphrase, $match)) {
            return $keyphrase;
        }

        $prefix = (string) ($match[1] ?? '');
        $first_word = (string) ($match[2] ?? '');
        $rest = (string) ($match[3] ?? '');
        $lower_first_word = strtolower($first_word);
        $common_acronyms = ['ai', 'api', 'csv', 'css', 'erp', 'gpt', 'html', 'llm', 'php', 'rss', 'saas', 'seo', 'ui', 'url', 'ux'];

        if (in_array($lower_first_word, $common_acronyms, true)) {
            $first_word = strtoupper($first_word);
        } elseif (preg_match('/^\p{Ll}.*\p{Lu}/u', $first_word)) {
            return $keyphrase;
        } else {
            if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
                $first_char = mb_substr($first_word, 0, 1);
                $first_word = mb_strtoupper($first_char) . mb_substr($first_word, 1);
            } else {
                $first_word = strtoupper(substr($first_word, 0, 1)) . substr($first_word, 1);
            }
        }

        return $prefix . $first_word . $rest;
    }

    private static function text_length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    private static function text_substr(string $text, int $start, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($text, $start, $length, 'UTF-8') : (string) substr($text, $start, $length);
    }

    private static function is_near_article_end(int $position, int $content_length): bool
    {
        if ($content_length <= 0) {
            return false;
        }

        return $position >= (int) floor($content_length * 0.55) || ($content_length - $position) <= 2000;
    }

    private static function looks_like_assistant_followup(string $tail): bool
    {
        $plain_tail = html_entity_decode(wp_strip_all_tags($tail), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8');
        $plain_tail = strtolower(trim((string) preg_replace('/\s+/', ' ', $plain_tail)));

        if ($plain_tail === '') {
            return false;
        }

        $patterns = [
            '/\bi\s+can\s+(?:also\s+)?(?:turn|rewrite|convert|create|make|adapt|expand|format|help)\b/i',
            '/\bwould\s+you\s+like\s+me\s+to\b/i',
            '/\bif\s+you\s+(?:want|would\s+like|need),?\s+i\s+can\b/i',
            '/\bturn\s+this\s+into\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $plain_tail)) {
                return true;
            }
        }

        if (
            preg_match('/\bif\s+you\s+(?:want|would\s+like|need)\b/i', $plain_tail)
            && preg_match('/\b(?:recipe-style|seo\s+blog\s+post|conversational|beginner-friendly|instructions)\b/i', $plain_tail)
            && preg_match('/<\s*(?:ul|ol|li)\b/i', $tail)
        ) {
            return true;
        }

        return false;
    }
}
