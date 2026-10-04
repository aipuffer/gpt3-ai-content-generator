<?php


namespace WPAICG\SEO\Yoast;

use WPAICG\SEO\AIPKit_Base_SEO_Handler;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handler for Yoast SEO plugin interactions.
 * Owns its metadata operations in this module.
 */
class AIPKit_Yoast_Handler extends AIPKit_Base_SEO_Handler
{
    protected const LOGIC_NAMESPACE = __NAMESPACE__;
}

/**
 * Logic to get the Yoast SEO focus keyword for a post.
 *
 * @param int $post_id The ID of the post.
 * @return string|null The focus keyword or null if not set.
 */
function get_focus_keyword_logic(int $post_id): ?string
{
    if (empty($post_id)) {
        return null;
    }
    $kw = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
    return is_string($kw) && !empty($kw) ? $kw : null;
}

/**
 * Logic to update the Yoast SEO focus keyword for a post.
 *
 * @param int $post_id The ID of the post.
 * @param string $keyword The new focus keyword.
 * @return bool True on success, false on failure.
 */
function update_focus_keyword_logic(int $post_id, string $keyword): bool
{
    if (empty($post_id) || !is_string($keyword)) {
        return false;
    }
    $result = update_post_meta($post_id, '_yoast_wpseo_focuskw', sanitize_text_field($keyword));
    return $result !== false;
}

/**
 * Logic to update the Yoast SEO meta description for a post.
 *
 * @param int $post_id The ID of the post.
 * @param string $description The new meta description.
 * @return bool True on success, false on failure.
 */
function update_meta_description_logic(int $post_id, string $description): bool
{
    if (empty($post_id) || !is_string($description)) {
        return false;
    }
    $description = clamp_yoast_meta_description_logic($description);

    // Yoast meta keys are prefixed with _yoast_wpseo_
    $result = update_post_meta($post_id, '_yoast_wpseo_metadesc', sanitize_text_field($description));
    return $result !== false;
}

function clamp_yoast_meta_description_logic(string $description): string
{
    $max_length = 156;
    $description = html_entity_decode(wp_strip_all_tags($description), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8');
    $description = trim((string) preg_replace('/\s+/u', ' ', str_replace(['"', "'"], '', $description)));

    $length = function_exists('mb_strlen') ? mb_strlen($description, 'UTF-8') : strlen($description);
    if ($description === '' || $length <= $max_length) {
        return $description;
    }

    $cut = function_exists('mb_substr') ? mb_substr($description, 0, $max_length, 'UTF-8') : substr($description, 0, $max_length);
    $space_position = function_exists('mb_strrpos') ? mb_strrpos($cut, ' ', 0, 'UTF-8') : strrpos($cut, ' ');
    if ($space_position !== false && $space_position >= 90) {
        $cut = function_exists('mb_substr') ? mb_substr($cut, 0, (int) $space_position, 'UTF-8') : (string) substr($cut, 0, (int) $space_position);
    }

    return rtrim(trim((string) $cut), " \t\n\r\0\x0B,;:-");
}
