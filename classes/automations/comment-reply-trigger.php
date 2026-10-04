<?php


namespace WPAICG\AutoGPT\Cron\EventProcessor\Trigger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Queues new, top-level comments for the Auto-Reply task.
 *
 * @param int $task_id The ID of the task.
 * @param array $task_config The configuration of the task.
 * @param string|null $last_run_time The last time the task was run in 'Y-m-d H:i:s' format.
 * @return void
 */
function trigger_comment_reply_task_logic(int $task_id, array $task_config, ?string $last_run_time = null): void
{
    global $wpdb;
    $queue_table_name = $wpdb->prefix . 'aipkit_automated_task_queue';

    // Get comments that are new since the last run, approved, and optionally top-level.
    $args = [
        'status' => 'approve',
        'type' => 'comment',
        'number' => 50, // Process up to 50 new comments per run to avoid overload.
        'orderby' => 'comment_date_gmt',
        'order' => 'ASC',
    ];
    // Conditionally filter for top-level comments
    if (isset($task_config['no_reply_to_replies']) && $task_config['no_reply_to_replies'] === '1') {
        $args['parent'] = 0;
    }

    if ($last_run_time) {
        $args['date_query'] = [['column' => 'comment_date_gmt', 'after' => $last_run_time, 'inclusive' => false]];
    }
    // Filter by post type
    $post_types = $task_config['post_types_for_comments'] ?? ['post'];
    if (!empty($post_types)) {
        $args['post_type'] = $post_types;
    }

    $comments_to_process = get_comments($args);
    $queued_count = 0;

    if (empty($comments_to_process)) {
        return;
    }

    foreach ($comments_to_process as $comment) {
        $comment_id = $comment->comment_ID;
        // Check if this comment has already been queued or replied to by this task
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reason: Direct query to a custom table. Caches will be invalidated.
        $existing_item = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$queue_table_name} WHERE task_id = %d AND target_identifier = %s LIMIT 1",
            $task_id,
            $comment_id
        ));

        // Also check if we've already replied to this comment to prevent loops
        $existing_reply = get_comments([
            'parent' => $comment_id,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Reason: The meta/tax query is essential for the feature's functionality. Its performance impact is considered acceptable as the query is highly specific, paginated, cached, or runs in a non-critical admin/cron context.
            'meta_key' => '_aipkit_automated_reply',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Reason: The meta/tax query is essential for the feature's functionality. Its performance impact is considered acceptable as the query is highly specific, paginated, cached, or runs in a non-critical admin/cron context.
            'meta_value' => $task_id,
            'count' => true
        ]);

        if ($existing_item || $existing_reply > 0) {
            continue;
        }

        // The item_config for this queue item is the main task_config.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct insert to a custom table.
        $inserted = $wpdb->insert(
            $queue_table_name,
            [
                'task_id' => $task_id,
                'target_identifier' => $comment_id,
                'task_type' => 'community_reply_comments',
                'item_config' => wp_json_encode($task_config),
                'status' => 'pending',
                'added_at' => current_time('mysql', 1)
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s']
        );
        if ($inserted) {
            $queued_count++;
        }
    }
}
