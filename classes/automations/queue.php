<?php

namespace WPAICG\AutoGPT\Cron;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

require_once __DIR__ . '/lock.php';

/**
 * Handles queueing content for automated tasks, specifically for content indexing.
 */
class AIPKit_Automated_Task_Content_Queuer
{
    /**
     * Queues initial content for indexing based on task configuration.
     */
    public static function maybe_queue_initial_indexing_content(int $task_id, array $task_config, bool $force_all = false)
    {
        $lock_token = self::acquire_producer_lock($task_id);
        if ($lock_token === '') {
            return;
        }

        try {
            Queuer\maybe_queue_initial_indexing_content_logic($task_id, $task_config, $force_all);
        } finally {
            self::release_producer_lock($task_id, $lock_token);
        }
    }

    /**
     * Queues new or updated content for indexing since the last run time.
     */
    public static function queue_new_or_updated_indexing_content(int $task_id, array $task_config, ?string $last_run_time)
    {
        $lock_token = self::acquire_producer_lock($task_id);
        if ($lock_token === '') {
            return ['last_run_time' => $last_run_time ?: '1970-01-01 00:00:00'];
        }

        try {
            return Queuer\queue_new_or_updated_indexing_content_logic($task_id, $task_config, $last_run_time);
        } finally {
            self::release_producer_lock($task_id, $lock_token);
        }
    }

    /** Removes persistent and legacy queue-materialization state for a deleted task. */
    public static function clear_task_state(int $task_id): void
    {
        if ($task_id <= 0) {
            return;
        }

        foreach (
            [
                'aipkit_initial_queue_cursor_v2_',
                'aipkit_initial_queue_done_v2_',
                'aipkit_initial_queue_force_v2_',
                'aipkit_incremental_queue_state_v2_',
                'aipkit_queue_producer_lock_v2_',
            ] as $option_prefix
        ) {
            delete_option($option_prefix . $task_id);
        }

        delete_transient('aipkit_initial_queue_page_' . $task_id);
        delete_transient('aipkit_initial_queue_done_' . $task_id);
    }

    /**
     * Acquires a short-lived, database-backed producer lock for this task.
     *
     * add_option() is atomic because option names are unique, preventing the
     * task event and queue worker from materializing the same window together.
     */
    private static function acquire_producer_lock(int $task_id): string
    {
        $lock_key = 'aipkit_queue_producer_lock_v2_' . $task_id;
        return AIPKit_Option_Lock::acquire($lock_key, 300);
    }

    /** Releases the producer lock after a bounded queueing pass. */
    private static function release_producer_lock(int $task_id, string $token): void
    {
        AIPKit_Option_Lock::release('aipkit_queue_producer_lock_v2_' . $task_id, $token);
    }
}

namespace WPAICG\AutoGPT\Cron\Queuer\Helpers;

/** Filters a scan batch without changing its pagination cursor. */
function filter_indexing_category_posts_logic(array $post_ids, array $task_config): array
{
    $categories = array_values(array_filter(array_map('absint', (array) ($task_config['indexing_categories'] ?? []))));
    if (!$categories || !$post_ids) {
        return $post_ids;
    }
    $allowed = $categories;
    foreach ($categories as $category) {
        $children = get_term_children($category, 'category');
        if (!is_wp_error($children)) {
            $allowed = array_merge($allowed, $children);
        }
    }
    $allowed = array_unique(array_map('absint', $allowed));
    _prime_post_caches(array_map('absint', $post_ids), false, true);
    return array_values(array_filter($post_ids, static function ($post_id) use ($allowed) {
        $post_type = get_post_type($post_id);
        return $post_type && (!is_object_in_taxonomy($post_type, 'category') || has_term($allowed, 'category', $post_id));
    }));
}

/**
 * Constructs the item_config array for a content indexing task.
 *
 * @param array $task_config The configuration array of the parent task.
 * @return array The specific configuration for the queue item.
 */
function build_index_item_config_logic(array $task_config): array
{
    return [
        'target_store_id' => $task_config['target_store_id'] ?? '',
        'target_store_provider' => $task_config['target_store_provider'] ?? 'openai',
        'embedding_provider' => $task_config['embedding_provider'] ?? null,
        'embedding_model'    => $task_config['embedding_model'] ?? null,
        'source_context'     => $task_config['source_context'] ?? '',
        'chatbot_id'         => isset($task_config['chatbot_id']) ? absint($task_config['chatbot_id']) : 0,
    ];
}

/**
 * Returns remaining capacity in a bounded per-task active queue window.
 *
 * The derived-table LIMIT prevents this request from counting an arbitrarily
 * large backlog merely to decide whether another producer batch may be added.
 */
function get_queue_window_capacity_logic(
    \wpdb $wpdb,
    string $queue_table_name,
    int $task_id,
    int $window_size = 1000
): int {
    $window_size = max(1, $window_size);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bounded active-row count over a plugin-owned queue table.
    $active_count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM (SELECT id FROM " . esc_sql($queue_table_name) . " WHERE task_id = %d AND status IN ('pending', 'processing') LIMIT %d) AS aipkit_bounded_queue",
            $task_id,
            $window_size
        )
    );
    return max(0, $window_size - $active_count);
}

/**
 * Inserts a single item into the automated task queue.
 *
 * @param \wpdb $wpdb The WordPress database object.
 * @param string $queue_table_name The name of the queue table.
 * @param int $task_id The ID of the parent task.
 * @param int $post_id The ID of the post to be processed.
 * @param string $task_type The type of task (e.g., 'content_indexing').
 * @param array $item_config The specific configuration for this queue item.
 * @return bool True on successful insertion, false otherwise.
 */
function insert_item_into_queue_logic(
    \wpdb $wpdb,
    string $queue_table_name,
    int $task_id,
    int $post_id,
    string $task_type,
    array $item_config
): bool {
    $encoded_item_config = wp_json_encode($item_config);
    if ($encoded_item_config === false) {
        $encoded_item_config = '{}';
    }

    // Avoid stacking duplicate content-indexing jobs for the same post while a prior one is still waiting/running.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom queue lookup to prevent duplicate pending indexing work.
    $existing_item_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id
             FROM " . esc_sql($queue_table_name) . "
             WHERE task_id = %d
               AND target_identifier = %s
               AND task_type = %s
               AND item_config = %s
               AND status IN ('pending', 'processing')
             LIMIT 1",
            $task_id,
            (string) $post_id,
            $task_type,
            $encoded_item_config
        )
    );
    if (!empty($existing_item_id)) {
        return false;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct insert to a custom table. Caches will be invalidated.
    $inserted = $wpdb->insert(
        $queue_table_name,
        [
            'task_id' => $task_id,
            'target_identifier' => $post_id,
            'task_type' => $task_type,
            'item_config' => $encoded_item_config,
            'status' => 'pending',
            'added_at' => current_time('mysql', 1),
            'sort_priority' => 1,
        ],
        ['%d', '%s', '%s', '%s', '%s', '%s', '%d']
    );
    return (bool) $inserted;
}

/**
 * Updates the 'index_existing_now_flag' in a task's configuration to '0'.
 *
 * @param \wpdb $wpdb The WordPress database object.
 * @param string $tasks_table_name The name of the tasks table.
 * @param int $task_id The ID of the task to update.
 * @param array $task_config The configuration array of the task.
 * @return void
 */
function update_task_flag_logic(\wpdb $wpdb, string $tasks_table_name, int $task_id, array $task_config): void
{
    if (isset($task_config['index_existing_now_flag']) && $task_config['index_existing_now_flag'] === '1') {
        $task_config['index_existing_now_flag'] = '0'; // Mark as processed
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct update to a custom table. Caches will be invalidated.
        $wpdb->update(
            $tasks_table_name,
            ['task_config' => wp_json_encode($task_config)],
            ['id' => $task_id]
        );
    }
}

namespace WPAICG\AutoGPT\Cron\Queuer;

use WP_Query;

/**
 * Queues a bounded window of initial content using a durable keyset cursor.
 *
 * A task never materializes its full site into the queue. The worker refills
 * this window as rows are processed, keeping memory, query time, and database
 * growth bounded even on sites with millions of posts.
 */
function maybe_queue_initial_indexing_content_logic(int $task_id, array $task_config, bool $force_all = false): void
{
    global $wpdb;
    $queue_table_name = $wpdb->prefix . 'aipkit_automated_task_queue';
    $tasks_table_name = $wpdb->prefix . 'aipkit_automated_tasks';
    $cursor_key = 'aipkit_initial_queue_cursor_v2_' . $task_id;
    $completed_key = 'aipkit_initial_queue_done_v2_' . $task_id;
    $force_key = 'aipkit_initial_queue_force_v2_' . $task_id;
    $legacy_page_key = 'aipkit_initial_queue_page_' . $task_id;
    $legacy_completed_key = 'aipkit_initial_queue_done_' . $task_id;

    if ($force_all) {
        delete_option($cursor_key);
        delete_option($completed_key);
        update_option($force_key, 'yes', false);
        delete_transient($legacy_page_key);
        delete_transient($legacy_completed_key);
    }

    $initial_indexing_requested = ($task_config['index_existing_now_flag'] ?? '0') === '1';
    $force_scan_requested = get_option($force_key, '') === 'yes';
    $is_completed = get_option($completed_key, '') === 'yes';
    if (!$is_completed && get_transient($legacy_completed_key)) {
        update_option($completed_key, 'yes', false);
        $is_completed = true;
    }
    if (!$force_all && ($is_completed || (!$initial_indexing_requested && !$force_scan_requested))) {
        return;
    }

    $available_capacity = Helpers\get_queue_window_capacity_logic($wpdb, $queue_table_name, $task_id);
    if ($available_capacity <= 0) {
        return;
    }
    $batch_size = min(200, $available_capacity);
    $cursor_state = get_option($cursor_key, []);
    if (!is_array($cursor_state)) {
        $cursor_state = [];
    }
    $item_config = Helpers\build_index_item_config_logic($task_config);
    $specific_post_ids = isset($task_config['specific_post_ids']) && is_array($task_config['specific_post_ids'])
        ? array_values(array_filter(array_map('absint', $task_config['specific_post_ids'])))
        : [];

    if (!empty($specific_post_ids)) {
        $specific_offset = ($cursor_state['mode'] ?? '') === 'specific' ? absint($cursor_state['offset'] ?? 0) : 0;
        $specific_batch = array_slice($specific_post_ids, $specific_offset, $batch_size);
        if (!empty($specific_batch)) {
            $query = new WP_Query([
                'post_type' => !empty($task_config['post_types']) ? $task_config['post_types'] : 'any',
                'post_status' => 'publish',
                'post__in' => $specific_batch,
                'posts_per_page' => count($specific_batch),
                'fields' => 'ids',
                'orderby' => 'post__in',
                'no_found_rows' => true,
            ]);
            foreach (Helpers\filter_indexing_category_posts_logic((array) $query->posts, $task_config) as $post_id) {
                Helpers\insert_item_into_queue_logic($wpdb, $queue_table_name, $task_id, absint($post_id), 'content_indexing', $item_config);
            }
            $specific_offset += count($specific_batch);
        }

        if ($specific_offset < count($specific_post_ids)) {
            update_option($cursor_key, ['mode' => 'specific', 'offset' => $specific_offset], false);
            return;
        }

        complete_initial_queue_scan_logic($wpdb, $tasks_table_name, $task_id, $task_config, $cursor_key, $completed_key, $force_key, $force_all);
        return;
    }

    $last_post_id = ($cursor_state['mode'] ?? '') === 'all' ? absint($cursor_state['post_id'] ?? 0) : 0;
    if ($last_post_id <= 0 && (int) get_transient($legacy_page_key) > 1) {
        // Upgrade an in-progress page cursor without restarting a very large scan.
        // The task_id index is ordered by the primary key, so this lookup remains bounded.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-row migration lookup in a plugin-owned queue table.
        $last_post_id = absint($wpdb->get_var($wpdb->prepare("SELECT target_identifier FROM {$queue_table_name} WHERE task_id = %d AND task_type = %s ORDER BY id DESC LIMIT 1", $task_id, 'content_indexing')));
        delete_transient($legacy_page_key);
    }

    $post_types = array_values(array_filter(array_map('sanitize_key', (array) ($task_config['post_types'] ?? ['post']))));
    if (empty($post_types)) {
        $post_types = ['post'];
    }
    $post_type_placeholders = implode(',', array_fill(0, count($post_types), '%s'));
    $query_args = array_merge([$last_post_id, 'publish'], $post_types, [$batch_size]);
    $posts_query = "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_status = %s AND post_type IN ($post_type_placeholders) ORDER BY ID ASC LIMIT %d";
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bounded keyset scan of WordPress post IDs with prepared post types.
    $post_ids = $wpdb->get_col($wpdb->prepare($posts_query, $query_args));

    foreach (Helpers\filter_indexing_category_posts_logic((array) $post_ids, $task_config) as $post_id) {
        Helpers\insert_item_into_queue_logic($wpdb, $queue_table_name, $task_id, absint($post_id), 'content_indexing', $item_config);
    }

    if (count((array) $post_ids) >= $batch_size) {
        $last_post_id = absint(end($post_ids));
        update_option($cursor_key, ['mode' => 'all', 'post_id' => $last_post_id], false);
        return;
    }

    complete_initial_queue_scan_logic($wpdb, $tasks_table_name, $task_id, $task_config, $cursor_key, $completed_key, $force_key, $force_all);
}

/** Completes the durable producer scan and clears the one-time task flag. */
function complete_initial_queue_scan_logic(
    \wpdb $wpdb,
    string $tasks_table_name,
    int $task_id,
    array $task_config,
    string $cursor_key,
    string $completed_key,
    string $force_key,
    bool $force_all
): void {
    delete_option($cursor_key);
    delete_option($force_key);
    update_option($completed_key, 'yes', false);
    if (!$force_all) {
        Helpers\update_task_flag_logic($wpdb, $tasks_table_name, $task_id, $task_config);
    }
}

/**
 * Queues a bounded, resumable window of posts modified since the last scan.
 *
 * @return array{last_run_time:string} Safe watermark for the parent task row.
 */
function queue_new_or_updated_indexing_content_logic(int $task_id, array $task_config, ?string $last_run_time): array
{
    global $wpdb;
    $queue_table_name = $wpdb->prefix . 'aipkit_automated_task_queue';
    $state_key = 'aipkit_incremental_queue_state_v2_' . $task_id;
    $state = get_option($state_key, []);
    if (!is_array($state) || empty($state['since']) || empty($state['until'])) {
        $state = [
            'since' => $last_run_time ?: '1970-01-01 00:00:00',
            'until' => current_time('mysql', true),
            'cursor_modified' => $last_run_time ?: '1970-01-01 00:00:00',
            'cursor_id' => 0,
        ];
    }

    $available_capacity = Helpers\get_queue_window_capacity_logic($wpdb, $queue_table_name, $task_id);
    if ($available_capacity <= 0) {
        update_option($state_key, $state, false);
        return ['last_run_time' => (string) $state['since']];
    }
    $batch_size = min(200, $available_capacity);
    $post_types = array_values(array_filter(array_map('sanitize_key', (array) ($task_config['post_types'] ?? ['post']))));
    if (empty($post_types)) {
        $post_types = ['post'];
    }

    $post_type_placeholders = implode(',', array_fill(0, count($post_types), '%s'));
    $query_args = array_merge(
        ['publish'],
        $post_types,
        [
            (string) $state['since'],
            (string) $state['until'],
            (string) $state['cursor_modified'],
            (string) $state['cursor_modified'],
            absint($state['cursor_id'] ?? 0),
            $batch_size,
        ]
    );
    $posts_query = "SELECT ID, post_modified_gmt FROM {$wpdb->posts} WHERE post_status = %s AND post_type IN ($post_type_placeholders) AND post_modified_gmt > %s AND post_modified_gmt <= %s AND (post_modified_gmt > %s OR (post_modified_gmt = %s AND ID > %d)) ORDER BY post_modified_gmt ASC, ID ASC LIMIT %d";
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bounded keyset scan of WordPress posts with prepared post types and watermarks.
    $posts = $wpdb->get_results($wpdb->prepare($posts_query, $query_args), ARRAY_A);
    $item_config = Helpers\build_index_item_config_logic($task_config);
    $matching_ids = Helpers\filter_indexing_category_posts_logic(array_column((array) $posts, 'ID'), $task_config);
    foreach ($matching_ids as $post_id) {
        Helpers\insert_item_into_queue_logic($wpdb, $queue_table_name, $task_id, absint($post_id), 'content_indexing', $item_config);
    }

    if (count((array) $posts) >= $batch_size) {
        $last_post = end($posts);
        $state['cursor_modified'] = (string) ($last_post['post_modified_gmt'] ?? $state['cursor_modified']);
        $state['cursor_id'] = absint($last_post['ID'] ?? $state['cursor_id']);
        update_option($state_key, $state, false);
        return ['last_run_time' => (string) $state['since']];
    }

    delete_option($state_key);
    return ['last_run_time' => (string) $state['until']];
}
