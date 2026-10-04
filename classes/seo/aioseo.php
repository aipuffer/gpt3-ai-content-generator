<?php


namespace WPAICG\SEO\AIOSEO;

use WPAICG\SEO\AIPKit_Base_SEO_Handler;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handler for All in One SEO (AIOSEO) plugin interactions.
 * Owns its metadata operations in this module.
 */
class AIPKit_AIOSEO_Handler extends AIPKit_Base_SEO_Handler
{
    protected const LOGIC_NAMESPACE = __NAMESPACE__;
}

/**
 * Logic to get the AIOSEO keyphrase for a post.
 * UPDATED: Reads from the dedicated `wp_aioseo_posts` table with a fallback to post meta.
 *
 * @param int $post_id The ID of the post.
 * @return string|null The focus keyphrase or null if not set.
 */
function get_focus_keyword_logic(int $post_id): ?string
{
    if (empty($post_id)) {
        return null;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'aioseo_posts';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: This is a one-time read operation.
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reason: This is a one-time read operation.
        $keyphrases_json = $wpdb->get_var($wpdb->prepare("SELECT keyphrases FROM {$table_name} WHERE post_id = %d", $post_id));
        if (!empty($keyphrases_json)) {
            $keyphrases_data = json_decode($keyphrases_json, true);
            if (is_array($keyphrases_data) && !empty($keyphrases_data['focus']['keyphrase'])) {
                return $keyphrases_data['focus']['keyphrase'];
            }
        }
    }

    // Fallback to checking post meta if table read fails or yields no result
    $keywords_data_meta = get_post_meta($post_id, '_aioseo_keywords', true);
    if (is_array($keywords_data_meta) && !empty($keywords_data_meta['focus']['keyphrase'])) {
        return $keywords_data_meta['focus']['keyphrase'];
    }
    // Handle case where meta is a JSON string
    if (is_string($keywords_data_meta)) {
        $keywords_data_decoded = json_decode($keywords_data_meta, true);
        if (is_array($keywords_data_decoded) && !empty($keywords_data_decoded['focus']['keyphrase'])) {
            return $keywords_data_decoded['focus']['keyphrase'];
        }
    }

    return null;
}

/**
 * Logic to update the AIOSEO keyphrase for a post.
 * UPDATED: Saves the keyphrase to the dedicated `wp_aioseo_posts` table and also updates the `_aioseo_keywords` post meta for compatibility.
 *
 * @param int $post_id The ID of the post.
 * @param string $keyword The new focus keyphrase.
 * @return bool True on success, false on failure.
 */
function update_focus_keyword_logic(int $post_id, string $keyword): bool
{
    if (empty($post_id) || !is_string($keyword)) {
        return false;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'aioseo_posts';
    $sanitized_keyword = sanitize_text_field($keyword);

    // --- Prepare the data structure ---
    $keyphrases_data = [];
    $existing_row = null;

    // Check if the AIOSEO table exists
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct query to a custom table. Caches will be invalidated.
    $table_exists = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name);

    if ($table_exists) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reason: Direct query to a custom table. Caches will be invalidated.
        $existing_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE post_id = %d", $post_id));
        if ($existing_row && !empty($existing_row->keyphrases)) {
            $keyphrases_data = json_decode($existing_row->keyphrases, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $keyphrases_data = []; // Reset if JSON is corrupt
            }
        }
    }

    // AIOSEO expects this structure. We only set the keyphrase, it calculates the rest.
    $keyphrases_data['focus']['keyphrase'] = $sanitized_keyword;
    if (!isset($keyphrases_data['focus']['analysis'])) {
        $keyphrases_data['focus']['analysis'] = new \stdClass();
    }
    if (!isset($keyphrases_data['additional'])) {
        $keyphrases_data['additional'] = [];
    }

    // --- Update the database table ---
    $db_update_success = true; // Assume success if table doesn't exist, as meta will be updated
    if ($table_exists) {
        $keyphrases_json = wp_json_encode($keyphrases_data);
        if (is_wp_error($keyphrases_json)) {
            return false;
        }

        if ($existing_row) {
            // Update existing row
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct update to a custom table. Caches will be invalidated.
            $result = $wpdb->update(
                $table_name,
                ['keyphrases' => $keyphrases_json, 'updated' => current_time('mysql', 1)],
                ['post_id' => $post_id],
                ['%s', '%s'],
                ['%d']
            );
        } else {
            // Insert new row
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct insert to a custom table. Caches will be invalidated.
            $result = $wpdb->insert(
                $table_name,
                [
                    'post_id' => $post_id,
                    'keyphrases' => $keyphrases_json,
                    'created' => current_time('mysql', 1),
                    'updated' => current_time('mysql', 1),
                ],
                ['%d', '%s', '%s', '%s']
            );
        }
        $db_update_success = ($result !== false);
    }

    // --- Also update post meta, which AIOSEO may use as a trigger ---
    $meta_update_success = (update_post_meta($post_id, '_aioseo_keywords', $keyphrases_data) !== false);

    return $db_update_success && $meta_update_success;
}

/**
 * Logic to update the AIOSEO meta description for a post.
 * Saves to AIOSEO's custom post table and keeps the legacy post meta mirror for compatibility.
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

    $sanitized_description = sanitize_text_field($description);
    $meta_update_success = update_post_meta($post_id, '_aioseo_description', $sanitized_description) !== false;

    global $wpdb;
    $table_name = $wpdb->prefix . 'aioseo_posts';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct query to AIOSEO's custom table.
    $table_exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) === $table_name);
    if (!$table_exists) {
        return $meta_update_success;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reason: Direct query to AIOSEO's custom table.
    $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table_name} WHERE post_id = %d", $post_id));
    $timestamp = current_time('mysql', 1);

    if ($existing_id) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct update to AIOSEO's custom table.
        $result = $wpdb->update(
            $table_name,
            [
                'description' => $sanitized_description,
                'updated' => $timestamp,
            ],
            ['post_id' => $post_id],
            ['%s', '%s'],
            ['%d']
        );
    } else {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct insert to AIOSEO's custom table.
        $result = $wpdb->insert(
            $table_name,
            [
                'post_id' => $post_id,
                'description' => $sanitized_description,
                'created' => $timestamp,
                'updated' => $timestamp,
            ],
            ['%d', '%s', '%s', '%s']
        );
    }

    return $meta_update_success && $result !== false;
}
