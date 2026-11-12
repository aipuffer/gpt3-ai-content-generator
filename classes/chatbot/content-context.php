<?php
/**
 * Chatbot page eligibility and permission-aware content snippets.
 */

namespace WPAICG\Chat\Core\ContentAware;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Checks page location and the supported post-type filter.
 *
 * @param int $post_id The post ID passed from the frontend.
 * @return bool True if the context is suitable, false otherwise.
 */
function is_suitable_page(int $post_id): bool {
     if ($post_id <= 0) {
        return false;
     }

     $post = get_post($post_id);
     if (!$post) {
         return false;
     }

     $front_page_id = (int) get_option('page_on_front');
     $posts_page_id = (int) get_option('page_for_posts');

     if ($post_id === $front_page_id || $post_id === $posts_page_id) {
        return false;
     }

     $allowed_post_types = apply_filters('aipkit_content_aware_post_types', ['post', 'page', 'product']);
     if (!in_array($post->post_type, $allowed_post_types, true)) {
        return false;
     }
     return true;
}

/**
 * Logic for the get_content_snippet static method of AIPKit_Content_Aware.
 *
 * @param int $post_id The ID of the post/page.
 * @return string|null The formatted content snippet or null if not applicable/found.
 */
function get_content_snippet(int $post_id): ?string {
    if (!is_suitable_page($post_id)) {
        return null;
    }

    $post = get_post($post_id);
    if (!$post || !in_array($post->post_status, ['publish', 'private'])) {
        return null;
    }

    // A frontend-supplied post ID must not bypass WordPress read access.
    if (post_password_required($post)
        || ($post->post_status === 'private' && !current_user_can('read_post', $post_id))) {
        return null;
    }

    $content = '';
    if (has_excerpt($post)) {
        $content = trim(get_the_excerpt($post));
    }

    if (empty($content) && !empty($post->post_content)) {
         $content_raw = $post->post_content;
         // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress content filter.
         $content_filtered = apply_filters('the_content', $content_raw);
         $content_stripped = wp_strip_all_tags(strip_shortcodes($content_filtered));
         $content = trim(preg_replace('/\s+/', ' ', $content_stripped));
         // MAX_EXCERPT_LENGTH is a constant in the class, access it via class name
         $content = mb_substr($content, 0, \WPAICG\Chat\Core\AIPKit_Content_Aware::MAX_EXCERPT_LENGTH);
    }

    if (empty($content)) {
        return null;
    }

    return sprintf(
        "## Current Page Content Snippet:\n%s\n##\n",
        $content
    );
}

namespace WPAICG\Chat\Core;

/**
 * Provides readable page snippets for chatbot context.
 */
class AIPKit_Content_Aware {

    const MAX_EXCERPT_LENGTH = 1500;

    /** Small public-only starter context; no rendering, shortcode execution, crawl or AI request. */
    public static function website_basics(array $page_ids): array {
        $plain = static function (string $text, int $limit): string {
            return mb_substr(trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags(strip_shortcodes($text)))), 0, $limit);
        };
        $name = $plain((string) get_option('blogname', ''), 150);
        $description = $plain((string) get_option('blogdescription', ''), 300);
        $facts = ['name' => $name, 'description' => $description];
        $questions = $name !== '' ? [__('What is this website called?', 'gpt3-ai-content-generator')] : [];
        $has_content = $description !== '';
        // Do not expose raw children of membership/custom/dynamic blocks. Only plain static
        // core text and its layout containers belong in this small automatic starter context.
        $static_text = static function (array $blocks) use (&$static_text): string {
            $text = '';
            foreach ($blocks as $block) {
                $type = $block['blockName'] ?? null;
                if ($type !== null && !in_array($type, ['core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/quote', 'core/table', 'core/preformatted', 'core/verse', 'core/group', 'core/columns', 'core/column'], true)) { continue; }
                if (!empty($block['attrs']['metadata']['bindings'])) { continue; }
                $text .= ' ' . (!empty($block['innerBlocks']) ? $static_text($block['innerBlocks']) : (string) ($block['innerHTML'] ?? ''));
            }
            return $text;
        };
        foreach (array_slice(array_unique(array_map('absint', $page_ids)), 0, 2) as $id) {
            $page = get_post($id);
            if (!$page || $page->post_type !== 'page' || $page->post_status !== 'publish' || $page->post_password !== '' || !is_post_type_viewable('page')) { continue; }
            $text = $plain($static_text(parse_blocks((string) $page->post_content)), 1100);
            if ($text === '') { continue; }
            $facts['pages'][] = ['title' => $plain($page->post_title, 100), 'text' => $text];
            $has_content = true;
            if (count($questions) < 2) {
                /* translators: %s: public page title. */
                $questions[] = sprintf(__('What does the “%s” page say?', 'gpt3-ai-content-generator'), $plain($page->post_title, 100));
            }
        }
        if ($has_content) { array_unshift($questions, __('What is this website about?', 'gpt3-ai-content-generator')); }
        return ['context' => $name !== '' || $has_content ? wp_json_encode($facts, JSON_UNESCAPED_UNICODE) : '', 'questions' => array_slice($questions, 0, 3)];
    }


    public static function get_content_snippet(int $post_id): ?string {
        return ContentAware\get_content_snippet($post_id);
    }

}
