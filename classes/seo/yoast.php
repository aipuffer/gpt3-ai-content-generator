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

/** The native Yoast update ability does not accept a focus keyphrase; expose only this missing field. */
function register_mcp_focus_ability_logic(): void
{
    if (!function_exists('wp_register_ability') || !function_exists('YoastSEO') || !YoastSEO()->helpers->indexable->should_index_indexables()
        || !class_exists('Yoast\\WP\\SEO\\Builders\\Indexable_Builder')) {
        return;
    }
    wp_register_ability('ai-puffer/yoast-set-focus-keyphrase', [
        'label' => __('Set Yoast focus keyphrase', 'gpt3-ai-content-generator'),
        'description' => __('Set or clear only the Yoast focus keyphrase of one post or page. An empty string clears it. Does not recalculate SEO/readability scores or change the post title, content or publication status. Read back with Yoast SEO data after approval.', 'gpt3-ai-content-generator'),
        'category' => \WPAICG\Mcp\Abilities::CATEGORY,
        'input_schema' => ['type' => 'object', 'required' => ['post_id', 'focus_keyphrase'], 'additionalProperties' => false,
            'properties' => ['post_id' => ['type' => 'integer', 'minimum' => 1],
                'focus_keyphrase' => ['type' => 'string', 'maxLength' => 191]]],
        'output_schema' => ['type' => 'object', 'required' => ['post_id', 'focus_keyphrase', 'analysis_refreshed', 'analysis_note'],
            'additionalProperties' => false, 'properties' => ['post_id' => ['type' => 'integer'], 'focus_keyphrase' => ['type' => 'string'],
                'analysis_refreshed' => ['type' => 'boolean'], 'analysis_note' => ['type' => 'string']]],
        'permission_callback' => __NAMESPACE__ . '\\can_update_mcp_focus_keyphrase_logic',
        'execute_callback' => __NAMESPACE__ . '\\update_mcp_focus_keyphrase_logic',
        'meta' => ['public' => true, 'show_in_rest' => true,
            'annotations' => ['readonly' => false, 'destructive' => null, 'idempotent' => true]],
    ]);
}

function can_update_mcp_focus_keyphrase_logic($input): bool
{
    $id = is_array($input) ? ($input['post_id'] ?? null) : null;
    $post = is_int($id) ? get_post($id) : null;
    return $post && !in_array($post->post_type, ['revision', 'attachment'], true) && $post->post_status !== 'trash'
        && function_exists('YoastSEO') && YoastSEO()->helpers->indexable->should_index_indexables()
        && YoastSEO()->helpers->capability->current_user_can('wpseo_edit_advanced_metadata')
        && current_user_can('edit_post', $id);
}

/** Verify both the stored value and Yoast's read model; an unconfirmed write is never reported as success. */
function update_mcp_focus_keyphrase_logic(array $input)
{
    if (!can_update_mcp_focus_keyphrase_logic($input)) {
        return new \WP_Error('permission_denied', __('You cannot edit this post’s Yoast SEO data.', 'gpt3-ai-content-generator'));
    }
    if (!isset($input['focus_keyphrase']) || !is_string($input['focus_keyphrase']) || mb_strlen($input['focus_keyphrase']) > 191) {
        return new \WP_Error('invalid_input', __('Provide a focus keyphrase of up to 191 characters.', 'gpt3-ai-content-generator'));
    }
    $id = $input['post_id'];
    $value = sanitize_text_field($input['focus_keyphrase']);
    $builder = YoastSEO()->classes->get('Yoast\\WP\\SEO\\Builders\\Indexable_Builder');
    $indexable = YoastSEO()->classes->get('Yoast\\WP\\SEO\\Repositories\\Indexable_Repository')->find_by_id_and_type($id, 'post');
    if (!$indexable) {
        return new \WP_Error('yoast_data_unavailable', __('Yoast SEO data is unavailable for this post.', 'gpt3-ai-content-generator'));
    }
    \WPSEO_Meta::set_value('focuskw', $value, $id);
    if ((string) get_post_meta($id, '_yoast_wpseo_focuskw', true) !== $value) {
        return new \WP_Error('yoast_save_unconfirmed', __('The focus keyphrase save could not be confirmed.', 'gpt3-ai-content-generator'));
    }
    $indexable = $builder->build_for_id_and_type($id, 'post', $indexable);
    if (!$indexable || (string) $indexable->primary_focus_keyword !== $value) {
        return new \WP_Error('yoast_readback_unconfirmed', __('The keyphrase was saved but Yoast’s SEO data refresh could not be confirmed. Check the post before retrying.', 'gpt3-ai-content-generator'));
    }
    return ['post_id' => $id, 'focus_keyphrase' => $value, 'analysis_refreshed' => false,
        'analysis_note' => __('The keyphrase is saved. Yoast’s analysis scores have not been recalculated; run its analysis in the editor to refresh them.', 'gpt3-ai-content-generator')];
}
