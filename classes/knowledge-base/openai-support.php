<?php

namespace WPAICG\Dashboard\Ajax\OpenAI;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Logs an entry for OpenAI vector store related events.
 *
 * @param \wpdb $wpdb WordPress database object.
 * @param string $data_source_table_name The name of the data source log table.
 * @param array $log_data Data for the log entry.
 * @return void
 */
function _aipkit_openai_vs_files_log_vector_data_source_entry(\wpdb $wpdb, string $data_source_table_name, array $log_data): void {
    $defaults = [
        'user_id' => get_current_user_id(),
        'timestamp' => current_time('mysql', 1),
        'provider' => 'OpenAI',
        'vector_store_id' => 'unknown',
        'vector_store_name' => null,
        'post_id' => null,
        'post_title' => null,
        'status' => 'info',
        'message' => '',
        'indexed_content' => null,
        'file_id' => null,
        'batch_id' => null,
        'embedding_provider' => null,
        'embedding_model' => null,
        'source_type_for_log' => null,
    ];
    $data_to_insert = wp_parse_args($log_data, $defaults);

    $source_type = $data_to_insert['source_type_for_log'] ?? ($data_to_insert['post_id'] ? 'wordpress_post' : 'unknown');
    $should_truncate = true;
    if (in_array($source_type, ['text_entry_global_form', 'file_upload_global_form'])) {
        $should_truncate = false;
    }

    if ($should_truncate && is_string($data_to_insert['indexed_content']) && mb_strlen($data_to_insert['indexed_content']) > 1000) {
        $data_to_insert['indexed_content'] = mb_substr($data_to_insert['indexed_content'], 0, 997) . '...';
    }
    unset($data_to_insert['source_type_for_log']);

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Reason: Necessary insert operation into a custom table for logging. Cache is invalidated below.
    $result = $wpdb->insert($data_source_table_name, $data_to_insert);

    // Invalidate cache for this specific file entry after insert
    if ($result && !empty($data_to_insert['file_id'])) {
        $cache_key = 'openai_log_entry_' . $data_to_insert['file_id'];
        wp_cache_delete($cache_key, 'aipkit_vector_logs');
    }
}

/**
 * Creates a temporary file from a string of content.
 *
 * @param string $content_string The content to write to the file.
 * @param string $filename_prefix Prefix for the temporary filename.
 * @return string|WP_Error The path to the temporary file or WP_Error on failure.
 */
function _aipkit_openai_vs_files_create_temp_file_from_string(string $content_string, string $filename_prefix = 'aipkit-content')
{
    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    WP_Filesystem();
    global $wp_filesystem;

    if (is_wp_error($wp_filesystem)) {
        return $wp_filesystem;
    }
    if (!$wp_filesystem) {
        return new WP_Error('filesystem_init_failed', __('Could not initialize the WordPress filesystem.', 'gpt3-ai-content-generator'));
    }

    $temp_file_path = wp_tempnam($filename_prefix, get_temp_dir());
    if ($temp_file_path === false) {
        return new WP_Error('temp_file_creation_failed', __('Could not create temporary file for content.', 'gpt3-ai-content-generator'));
    }

    $final_temp_file_path = dirname($temp_file_path) . '/' . basename($temp_file_path, '.tmp') . '.txt';

    if ($wp_filesystem->move($temp_file_path, $final_temp_file_path, true)) { // true to overwrite
        $temp_file_path = $final_temp_file_path;
    } else {
        // If move fails, clean up original and return error
        $wp_filesystem->delete($temp_file_path);
        return new WP_Error('temp_file_rename_failed', __('Could not rename temporary file.', 'gpt3-ai-content-generator'));
    }

    // Use WP_Filesystem::put_contents() for writing
    $bytes_written = $wp_filesystem->put_contents($temp_file_path, $content_string);
    if ($bytes_written === false) {
        $wp_filesystem->delete($temp_file_path);
        return new WP_Error('temp_file_write_failed', __('Could not write content to temporary file.', 'gpt3-ai-content-generator'));
    }
    return $temp_file_path;
}
