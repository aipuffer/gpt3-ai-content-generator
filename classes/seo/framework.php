<?php


namespace WPAICG\SEO\Framework;

use WPAICG\SEO\AIPKit_Base_SEO_Handler;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handler for The SEO Framework plugin interactions.
 * Owns its metadata operations in this module.
 */
class AIPKit_Framework_Handler extends AIPKit_Base_SEO_Handler
{
    protected const LOGIC_NAMESPACE = __NAMESPACE__;
}

/**
 * Logic to get the focus keyword for a post with The SEO Framework.
 * The SEO Framework does not have this feature.
 *
 * @param int $post_id The ID of the post.
 * @return string|null Always returns null.
 */
function get_focus_keyword_logic(int $post_id): ?string
{
    // The SEO Framework does not have a concept of a focus keyword.
    return null;
}

/**
 * Logic to update the focus keyword for a post with The SEO Framework.
 * NOTE: The SEO Framework does not have a native "focus keyword" feature like other plugins.
 * This is a placeholder and currently returns false.
 *
 * @param int $post_id The ID of the post.
 * @param string $keyword The new focus keyword.
 * @return bool Always returns false as this feature is not supported.
 */
function update_focus_keyword_logic(int $post_id, string $keyword): bool
{
    // The SEO Framework does not have a concept of a focus keyword.
    // We could potentially save it to a custom meta key for internal use, but that's out of scope.
    return false;
}

/**
 * Logic to update The SEO Framework meta description for a post.
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
    // The SEO Framework uses _genesis_description.
    $result = update_post_meta($post_id, '_genesis_description', sanitize_text_field($description));
    return $result !== false;
}
