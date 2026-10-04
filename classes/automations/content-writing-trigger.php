<?php

namespace WPAICG\AutoGPT\Cron\EventProcessor\Trigger;

use WPAICG\AutoGPT\Cron\EventProcessor\Trigger\Modules as ContentWritingModules;

if (!defined('ABSPATH')) {
    exit;
}

require_once WPAICG_PLUGIN_DIR . 'classes/automations/module-triggers.php';

/**
 * Triggers the queuing logic for a scheduled content writing task.
 *
 * @param int $task_id The ID of the task.
 * @param array $task_config The configuration of the task.
 * @return void
 */
function trigger_content_writing_task_logic(int $task_id, array $task_config): void
{
    global $wpdb;

    // Check if there are already pending or processing items for this task
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct query to a custom table. Caches will be invalidated.
    $existing_items_count = $wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM {$wpdb->prefix}aipkit_automated_task_queue WHERE task_id = %d AND status IN ('pending', 'processing') LIMIT 1",
        $task_id
    ));

    if ($existing_items_count > 0) {
        return;
    }

    $generation_mode = $task_config['cw_generation_mode'] ?? 'single';
    $topics_to_queue = [];
    $scraped_contexts = [];

    // 1. Generate items based on the generation mode
    switch ($generation_mode) {
        case 'rss':
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct query to a custom table. Caches will be invalidated.
            $last_run_time_from_db = $wpdb->get_var($wpdb->prepare("SELECT last_run_time FROM {$wpdb->prefix}aipkit_automated_tasks WHERE id = %d", $task_id));
            $topics_to_queue = ContentWritingModules\rss_mode_generate_items_logic($task_id, $task_config, $last_run_time_from_db);
            break;
        case 'gsheets':
            $topics_to_queue = ContentWritingModules\gsheets_mode_generate_items_logic($task_id, $task_config);
            break;
        case 'url':
            $result = ContentWritingModules\url_mode_generate_items_logic($task_id, $task_config);
            if (is_wp_error($result)) {
                $topics_to_queue = $result;
            } else {
                $topics_to_queue = $result['topics'];
                $scraped_contexts = $result['contexts'];
            }
            break;
        default: // 'single', 'bulk', 'csv'
            $topics_to_queue = ContentWritingModules\manual_mode_generate_items_logic($task_config);
            break;
    }

    if (is_wp_error($topics_to_queue)) {
        return;
    }
    if (empty($topics_to_queue)) {
        return;
    }

    // 2. Loop through generated items and queue them
    $item_index = 0;
    foreach ($topics_to_queue as $index => $item_data) {
        $item_specific_config = ContentWritingModules\prepare_item_config_logic($item_data, $task_config, $scraped_contexts);
        $item_specific_config['task_id'] = $task_id;
        if (empty($item_specific_config['content_title'])) {
            continue;
        }

        $scheduled_gmt_time = ContentWritingModules\compute_item_schedule_gmt_logic($item_data, $task_config, $item_index, $generation_mode);
        if (is_wp_error($scheduled_gmt_time)) {
            return;
        }
        if ($scheduled_gmt_time) {
            $item_specific_config['scheduled_gmt_time'] = $scheduled_gmt_time;
        }

        $target_identifier = ContentWritingModules\generate_target_identifier_logic($item_data, $task_id, $index);
        if ($generation_mode !== 'bulk' && $generation_mode !== 'csv' && $generation_mode !== 'single') {
            if (ContentWritingModules\is_duplicate_topic_logic($task_id, $target_identifier)) {
                continue;
            }
        }

        if (ContentWritingModules\insert_topic_into_queue_logic($task_id, $target_identifier, $item_specific_config)) {
            $item_index++;
        }
    }
}
