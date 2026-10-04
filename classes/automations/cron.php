<?php

namespace WPAICG\AutoGPT;

use WPAICG\AutoGPT\Cron\Init;
use WPAICG\AutoGPT\Cron\AIPKit_Automated_Task_Scheduler;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Orchestrates cron scheduling and processing for Automated Tasks using helper classes.
 */
class AIPKit_Automated_Task_Cron
{
    public const MAIN_CRON_HOOK = 'aipkit_process_automated_task_queue';

    /**
     * Initializes the main cron hook and ensures task-specific actions are hooked.
     */
    public static function init()
    {
        // 1. Ensure Dashboard is loaded for module status check
        if (!Init\ensure_dashboard_loaded_logic()) {
            return; // Critical dependency missing
        }

        // 2. Check if AutoGPT module is active. If not, stop here.
        if (!Init\check_module_status_logic(self::MAIN_CRON_HOOK)) {
            return;
        }

        global $wpdb;
        $tasks_table_name = $wpdb->prefix . 'aipkit_automated_tasks';

        // 3. Ensure the database table exists. If not, stop here.
        if (!Init\ensure_table_exists_logic($wpdb, $tasks_table_name, self::MAIN_CRON_HOOK)) {
            return;
        }

        if (class_exists(AIPKit_Automated_Task_Scheduler::class)) {
            AIPKit_Automated_Task_Scheduler::prune_orphaned_task_events();
        }

        // 4. Evaluate current task status vs saved option to see if main cron needs an update
        $status_flags = Init\evaluate_status_flags_logic($wpdb, $tasks_table_name);

        // 5. Register or un-register the main hourly cron hook based on flags
        Init\register_main_cron_hook_logic(self::MAIN_CRON_HOOK, $status_flags['active_task_count'], $status_flags['pending_queue_count'], $status_flags['did_active_tasks_exist']);

        // 6. Attach the action for the main queue processing hook
        Init\attach_main_hook_action_logic(self::MAIN_CRON_HOOK);

        // 7. Attach actions for each individual task's cron event
        Init\attach_individual_task_hooks_logic($wpdb, $tasks_table_name);
    }
}

namespace WPAICG\AutoGPT\Cron\Init;

use WPAICG\aipkit_dashboard;
use WPAICG\AutoGPT\Cron\AIPKit_Automated_Task_Event_Processor;
use WPAICG\AutoGPT\Cron\AIPKit_Automated_Task_Scheduler;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ensures the aipkit_dashboard class is loaded.
 *
 * @return bool True on success, false if class cannot be loaded.
 */
function ensure_dashboard_loaded_logic(): bool
{
    if (!class_exists(aipkit_dashboard::class)) {
        $dashboard_path = WPAICG_PLUGIN_DIR . 'classes/admin/dashboard.php';
        if (file_exists($dashboard_path)) {
            require_once $dashboard_path;
        } else {
            return false;
        }
    }
    return true;
}

/**
 * Checks if the AutoGPT module is active and handles state transitions.
 *
 * @param string $main_cron_hook The name of the main cron hook to clear if necessary.
 * @return bool True if the module is active and initialization should proceed, false otherwise.
 */
function check_module_status_logic(string $main_cron_hook): bool
{
    $option_key_autogpt_active = 'aipkit_autogpt_module_was_active';

    $module_settings = aipkit_dashboard::get_module_settings();
    $is_autogpt_currently_active = !empty($module_settings['autogpt']);
    $was_autogpt_active = (bool) get_option($option_key_autogpt_active, false);

    if (!$is_autogpt_currently_active) {
        if (wp_next_scheduled($main_cron_hook)) {
            wp_clear_scheduled_hook($main_cron_hook);
        }
        if (class_exists(AIPKit_Automated_Task_Scheduler::class)) {
            AIPKit_Automated_Task_Scheduler::clear_all_task_events();
        }
        update_option($option_key_autogpt_active, false, 'no');
        return false; // Stop further initialization
    }

    if (!$was_autogpt_active) {
        update_option($option_key_autogpt_active, true, 'no');
    }

    return true; // Continue initialization
}

/**
 * Verifies that the automated tasks database table exists.
 *
 * @param \wpdb $wpdb The WordPress database object.
 * @param string $tasks_table_name The name of the tasks table.
 * @param string $main_cron_hook The name of the main cron hook to clear if necessary.
 * @return bool True if the table exists, false otherwise.
 */
function ensure_table_exists_logic(\wpdb $wpdb, string $tasks_table_name, string $main_cron_hook): bool
{
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct query to a custom table. Caches will be invalidated.
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tasks_table_name));

    if ($table_exists !== $tasks_table_name) {
        if (wp_next_scheduled($main_cron_hook)) {
            wp_clear_scheduled_hook($main_cron_hook);
        }
        if (class_exists(AIPKit_Automated_Task_Scheduler::class)) {
            AIPKit_Automated_Task_Scheduler::clear_all_task_events();
        }
        return false;
    }
    return true;
}

/**
 * Quotes a plugin-owned table identifier after strict validation.
 *
 * @param string $table_name Table name.
 * @return string Backticked table identifier, or an empty string when invalid.
 */
function aipkit_get_validated_table_identifier_logic(string $table_name): string
{
    $table_name = trim($table_name);
    if ($table_name === '' || !preg_match('/^[A-Za-z0-9_]+$/', $table_name)) {
        return '';
    }

    return '`' . $table_name . '`';
}

/**
 * Evaluates the current state of active tasks and pending queue items against the previously known state.
 *
 * @param \wpdb $wpdb The WordPress database object.
 * @param string $tasks_table_name The name of the tasks table.
 * @return array An associative array with 'active_task_count', 'pending_queue_count', and 'did_active_tasks_exist'.
 */
function evaluate_status_flags_logic(\wpdb $wpdb, string $tasks_table_name): array
{
    $option_key_tasks_exist = 'aipkit_active_tasks_exist';
    $queue_table_name = $wpdb->prefix . 'aipkit_automated_task_queue';
    $tasks_table_identifier = aipkit_get_validated_table_identifier_logic($tasks_table_name);
    $queue_table_identifier = aipkit_get_validated_table_identifier_logic($queue_table_name);

    if ($tasks_table_identifier === '' || $queue_table_identifier === '') {
        return [
            'active_task_count' => 0,
            'pending_queue_count' => 0,
            'did_active_tasks_exist' => (bool) get_option($option_key_tasks_exist, false),
        ];
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query to a custom table; table identifier is plugin-owned, validated, and backticked above.
    $active_task_count = (int) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$tasks_table_identifier} WHERE status = %s LIMIT 1", 'active'));

    // Also check for pending items in the queue - these need processing even if no tasks are active
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query to a custom table; table identifier is plugin-owned, validated, and backticked above.
    $pending_queue_count = (int) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$queue_table_identifier} WHERE status = %s LIMIT 1", 'pending'));
    if (!$pending_queue_count) {
        require_once __DIR__ . '/events.php';
        $pending_queue_count = (int) \WPAICG\AutoGPT\Cron\EventProcessor\Helpers\has_waiting_indexing_queue_items_logic();
    }

    $did_active_tasks_exist = (bool) get_option($option_key_tasks_exist, false);

    return [
        'active_task_count' => $active_task_count,
        'pending_queue_count' => $pending_queue_count,
        'did_active_tasks_exist' => $did_active_tasks_exist,
    ];
}

/**
 * Schedules or unschedules the main queue processing cron hook based on active task status and pending queue items.
 *
 * @param string $main_cron_hook The name of the main cron hook.
 * @param int $active_task_count The current number of active tasks.
 * @param int $pending_queue_count The current number of pending queue items.
 * @param bool $did_active_tasks_exist Whether active tasks existed during the last check.
 * @return void
 */
function register_main_cron_hook_logic(string $main_cron_hook, int $active_task_count, int $pending_queue_count, bool $did_active_tasks_exist): void
{
    $option_key_tasks_exist = 'aipkit_active_tasks_exist';
    $has_work_to_do = ($active_task_count > 0) || ($pending_queue_count > 0);

    if (!$has_work_to_do) {
        if (wp_next_scheduled($main_cron_hook)) {
            wp_clear_scheduled_hook($main_cron_hook);
        }
        update_option($option_key_tasks_exist, false, 'no');
    } else { // Active tasks exist OR pending queue items exist
        // Update the flag if state has changed.
        if (!$did_active_tasks_exist) {
            update_option($option_key_tasks_exist, true, 'no');
        }
        // Schedule the main queue processing event if it's not already scheduled.
        if (!wp_next_scheduled($main_cron_hook)) {
            wp_schedule_event(time(), 'hourly', $main_cron_hook);
        }
    }
}

/**
 * Attaches the main cron hook action to its callback function.
 *
 * @param string $main_cron_hook The name of the main cron hook.
 * @return void
 */
function attach_main_hook_action_logic(string $main_cron_hook): void
{
    if (!class_exists(AIPKit_Automated_Task_Event_Processor::class)) {
        return;
    }

    // Ensure action is only added once per request lifecycle.
    if (!has_action($main_cron_hook, [AIPKit_Automated_Task_Event_Processor::class, 'process_task_queue_event'])) {
        add_action($main_cron_hook, [AIPKit_Automated_Task_Event_Processor::class, 'process_task_queue_event']);
    }
}

/**
 * Attaches the WordPress action for each individual task-specific cron hook.
 *
 * @param \wpdb $wpdb The WordPress database object.
 * @param string $tasks_table_name The name of the automated tasks table.
 * @return void
 */
function attach_individual_task_hooks_logic(\wpdb $wpdb, string $tasks_table_name): void
{
    if (!class_exists(AIPKit_Automated_Task_Scheduler::class) || !class_exists(AIPKit_Automated_Task_Event_Processor::class)) {
        return;
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct query to a custom table. Caches will be invalidated.
    $all_tasks = $wpdb->get_results("SELECT id FROM " . esc_sql($tasks_table_name), ARRAY_A);

    if ($all_tasks) {
        foreach ($all_tasks as $task) {
            $task_id = (int)$task['id'];
            $task_specific_hook = AIPKit_Automated_Task_Scheduler::get_task_specific_cron_hook($task_id);

            // Ensure action is only added once per request lifecycle.
            if (!has_action($task_specific_hook, [AIPKit_Automated_Task_Event_Processor::class, 'trigger_task_event'])) {
                add_action($task_specific_hook, [AIPKit_Automated_Task_Event_Processor::class, 'trigger_task_event'], 10, 1);
            }
        }
    }
}
