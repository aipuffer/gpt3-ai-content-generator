<?php

namespace WPAICG\SEO\RankMath;

use WPAICG\SEO\AIPKit_Base_SEO_Handler;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handler for Rank Math SEO plugin interactions.
 * Owns its metadata operations in this module.
 */
class AIPKit_Rank_Math_Handler extends AIPKit_Base_SEO_Handler
{
    protected const LOGIC_NAMESPACE = __NAMESPACE__;
}

/**
 * Logic to get the Rank Math focus keyword for a post.
 *
 * @param int $post_id The ID of the post.
 * @return string|null The focus keyword or null if not set.
 */
function get_focus_keyword_logic(int $post_id): ?string
{
    if (empty($post_id)) {
        return null;
    }
    $kw = get_post_meta($post_id, 'rank_math_focus_keyword', true);
    return is_string($kw) && !empty($kw) ? $kw : null;
}

/**
 * Logic to update the Rank Math focus keyword(s) for a post.
 *
 * @param int $post_id The ID of the post.
 * @param string $keyword The new focus keyword or comma-separated keywords.
 * @return bool True on success, false on failure.
 */
function update_focus_keyword_logic(int $post_id, string $keyword): bool
{
    if (empty($post_id) || !is_string($keyword)) {
        return false;
    }
    $result = update_post_meta($post_id, 'rank_math_focus_keyword', sanitize_text_field($keyword));
    return $result !== false;
}

/**
 * Logic to update the Rank Math meta description for a post.
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
    $result = update_post_meta($post_id, 'rank_math_description', sanitize_text_field($description));
    return $result !== false;
}
