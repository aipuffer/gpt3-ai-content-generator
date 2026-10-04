<?php

namespace WPAICG\AutoGPT\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Returns whether the given AutoGPT task type is Pro-only.
 */
function task_type_requires_pro_plan(string $task_type): bool
{
    return in_array(
        $task_type,
        [
            'content_writing_rss',
            'content_writing_url',
            'content_writing_gsheets',
            'enhance_existing_content',
        ],
        true
    );
}

/**
 * Returns whether the current site is running an active Pro plan.
 */
function is_pro_plan_active(): bool
{
    return class_exists('\WPAICG\aipkit_dashboard') && \WPAICG\aipkit_dashboard::is_pro_plan();
}

/** Convert provider errors without losing the queue's stop/recovery instructions. */
function provider_error_result(\WP_Error $error): array
{
    return array_merge(\WPAICG\Core\AIPKit_AI_Caller::batch_error_data($error), [
        'status' => 'error',
        'message' => $error->get_error_message(),
    ]);
}

/** Clear a provider hold only after an explicit administrator retry/resume. */
function clear_provider_hold(int $task_id): bool
{
    global $wpdb;
    $table = $wpdb->prefix . 'aipkit_automated_tasks';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Current task recovery state.
    $config_json = $wpdb->get_var($wpdb->prepare('SELECT task_config FROM ' . esc_sql($table) . ' WHERE id = %d', $task_id));
    $config = json_decode((string) $config_json, true);
    if (!is_array($config) || !isset($config['_aipkit_provider_hold'])) { return true; }
    unset($config['_aipkit_provider_hold']);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit task recovery; no credential or API changes.
    return $wpdb->update($table, ['task_config' => wp_json_encode($config)], ['id' => $task_id], ['%s'], ['%d']) !== false;
}
