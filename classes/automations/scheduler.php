<?php

namespace WPAICG\AutoGPT\Cron;

use WPAICG\AutoGPT\Cron\Scheduler\Schedule;
use WPAICG\AutoGPT\Cron\Scheduler\Batch;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
* Handles cron scheduling for individual Automated Tasks.
*/
class AIPKit_Automated_Task_Scheduler
{
    /**
    * Gets the hook name for a specific task's cron event.
    */
    public static function get_task_specific_cron_hook(int $task_id): string
    {
        return Schedule\get_task_specific_cron_hook_logic($task_id);
    }

    /**
    * Schedules or re-schedules a specific task's cron event.
    */
    public static function schedule_task_event(int $task_id, string $frequency, string $status)
    {
        Schedule\schedule_task_event_logic($task_id, $frequency, $status);
    }

    /**
    * Clears the scheduled cron event for a specific task.
    */
    public static function clear_task_event(int $task_id)
    {
        Schedule\clear_task_event_logic($task_id);
    }

    /**
    * Clears all task-specific cron events. Used on plugin deactivation.
    */
    public static function clear_all_task_events()
    {
        Batch\clear_all_task_events_logic();
    }

    /**
    * Removes task-specific cron events that no longer belong to active tasks.
    */
    public static function prune_orphaned_task_events()
    {
        Batch\prune_orphaned_task_events_logic();
    }

    /**
    * Re-schedules all active tasks. Typically called on plugin activation or update.
    */
    public static function reschedule_all_active_tasks()
    {
        Batch\reschedule_all_active_tasks_logic();
    }

    /**
     * Calculates the first authoritative database run timestamp for a task.
     */
    public static function calculate_initial_run_timestamp(string $frequency): ?int
    {
        return Scheduler\Utils\calculate_initial_run_timestamp_logic($frequency);
    }

    /**
     * Calculates the next authoritative database run timestamp after execution.
     */
    public static function calculate_next_run_timestamp(string $frequency, ?int $previous_run_timestamp = null): ?int
    {
        return Scheduler\Utils\calculate_next_run_timestamp_logic($frequency, $previous_run_timestamp);
    }
}

namespace WPAICG\AutoGPT\Cron\Scheduler\Utils;

/**
 * Returns the interval, in seconds, for a recurring automation frequency.
 *
 * @param string $frequency WordPress cron schedule slug.
 * @return int Interval in seconds, or zero for one-time/invalid schedules.
 */
function get_frequency_interval_logic(string $frequency): int
{
    if ($frequency === 'one-time') {
        return 0;
    }

    $schedules = wp_get_schedules();
    return isset($schedules[$frequency]['interval'])
        ? absint($schedules[$frequency]['interval'])
        : 0;
}

/**
 * Calculates the first authoritative database run time for an active task.
 *
 * @param string $frequency WordPress cron schedule slug.
 * @return int|null UTC timestamp, or null for an invalid schedule.
 */
function calculate_initial_run_timestamp_logic(string $frequency): ?int
{
    if ($frequency === 'one-time') {
        return time() + 10;
    }

    if (get_frequency_interval_logic($frequency) <= 0) {
        return null;
    }

    return time() + 30;
}

/**
 * Advances a recurring task from its authoritative database run time.
 * Missed intervals are skipped so a delayed runner does not replay a burst.
 *
 * @param string   $frequency             WordPress cron schedule slug.
 * @param int|null $previous_run_timestamp Previous scheduled UTC timestamp.
 * @return int|null Next UTC timestamp, or null for one-time/invalid schedules.
 */
function calculate_next_run_timestamp_logic(string $frequency, ?int $previous_run_timestamp = null): ?int
{
    $interval = get_frequency_interval_logic($frequency);
    if ($interval <= 0) {
        return null;
    }

    $now = time();
    $base = $previous_run_timestamp && $previous_run_timestamp > 0
        ? $previous_run_timestamp
        : $now;
    $next = $base + $interval;

    if ($next <= $now) {
        $missed_intervals = (int) floor(($now - $next) / $interval) + 1;
        $next += $missed_intervals * $interval;
    }

    return $next;
}

namespace WPAICG\AutoGPT\Cron\Scheduler\Schedule;

/**
* Gets the hook name for a specific task's cron event.
*
* @param int $task_id The ID of the task.
* @return string The cron hook name.
*/
function get_task_specific_cron_hook_logic(int $task_id): string
{
    return 'aipkit_automated_task_' . $task_id;
}

/**
* Clears every scheduled event for a task-specific hook, including older
* events that may have been saved with malformed or unexpected arguments.
*
* @param string $hook The task-specific hook name.
* @return void
*/
function clear_task_hook_events_logic(string $hook): void
{
    if (!function_exists('_get_cron_array')) {
        return;
    }

    $cron_events = _get_cron_array();
    if (!is_array($cron_events)) {
        return;
    }

    foreach ($cron_events as $timestamp => $events) {
        if (!is_array($events) || empty($events[$hook]) || !is_array($events[$hook])) {
            continue;
        }

        foreach ($events[$hook] as $event) {
            $args = isset($event['args']) && is_array($event['args']) ? $event['args'] : [];
            wp_unschedule_event((int) $timestamp, $hook, $args);
        }
    }
}

/**
* Clears the scheduled cron event for a specific task and updates the database.
*
* @param int $task_id The ID of the task.
* @return void
*/
function clear_task_event_logic(int $task_id): void
{
    global $wpdb;
    $tasks_table_name = $wpdb->prefix . 'aipkit_automated_tasks';
    $hook = get_task_specific_cron_hook_logic($task_id);
    $current_schedule_args = [$task_id];

    clear_task_hook_events_logic($hook);

    // Keep the exact-argument clear as a safe fallback if direct cron scanning is unavailable.
    wp_clear_scheduled_hook($hook, $current_schedule_args);

    // Update the database to reflect the change
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct update to a custom table. Caches will be invalidated.
    $wpdb->update(
        $tasks_table_name,
        ['next_run_time' => null],
        ['id' => $task_id],
        ['%s'],
        ['%d']
    );
}

/**
* Schedules or re-schedules a specific task's cron event and updates the database.
* Only clears and reschedules if the status or frequency requires it.
*
* @param int $task_id The ID of the task.
* @param string $frequency The desired frequency (e.g., 'hourly', 'daily', 'one-time').
* @param string $status The current status of the task ('active' or 'paused').
* @return void
*/
function schedule_task_event_logic(int $task_id, string $frequency, string $status): void
{
    global $wpdb;
    $tasks_table_name = $wpdb->prefix . 'aipkit_automated_tasks';
    $hook = get_task_specific_cron_hook_logic($task_id);
    $current_schedule_args = [$task_id];

    if ($status === 'active') {
        clear_task_hook_events_logic($hook);

        $first_run_timestamp = \WPAICG\AutoGPT\Cron\Scheduler\Utils\calculate_initial_run_timestamp_logic($frequency);

        if (!$first_run_timestamp) {
            clear_task_event_logic($task_id);
            return;
        }

        if ($frequency === 'one-time') {
            wp_schedule_single_event($first_run_timestamp, $hook, $current_schedule_args);
        } else {
            wp_schedule_event($first_run_timestamp, $frequency, $hook, $current_schedule_args);
        }

        // The database time is authoritative. WordPress cron is only one way to wake the runner.
        $next_run_datetime_gmt = gmdate('Y-m-d H:i:s', $first_run_timestamp);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct update to a custom table. Caches will be invalidated.
        $wpdb->update(
            $tasks_table_name,
            ['next_run_time' => $next_run_datetime_gmt],
            ['id' => $task_id],
            ['%s'],
            ['%d']
        );

    } else { // Status is not 'active'
        // `clear_task_event_logic` will be called, which handles both clearing the hook and updating the DB.
        clear_task_event_logic($task_id);
    }
}

namespace WPAICG\AutoGPT\Cron\Scheduler\Batch;

use WPAICG\AutoGPT\Cron\Scheduler\Schedule;

/**
* Clears all task-specific cron events. Used on plugin deactivation.
*
* @return void
*/
function clear_all_task_events_logic(): void
{
    global $wpdb;
    $tasks_table_name = $wpdb->prefix . 'aipkit_automated_tasks';
    $task_hook_prefix = 'aipkit_automated_task_';

    if (function_exists('_get_cron_array')) {
        $cron_events = _get_cron_array();
        if (is_array($cron_events)) {
            foreach ($cron_events as $timestamp => $events) {
                if (!is_array($events)) {
                    continue;
                }
                foreach ($events as $hook => $hook_events) {
                    if (strpos((string) $hook, $task_hook_prefix) !== 0 || !is_array($hook_events)) {
                        continue;
                    }
                    foreach ($hook_events as $event) {
                        $args = isset($event['args']) && is_array($event['args']) ? $event['args'] : [];
                        wp_unschedule_event((int) $timestamp, (string) $hook, $args);
                    }
                }
            }
        }
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct query to a custom table. Caches will be invalidated.
    if ($wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($tasks_table_name) . "'") != $tasks_table_name) {
        return;
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct query to a custom table. Caches will be invalidated.
    $task_ids = $wpdb->get_col("SELECT id FROM " . esc_sql($tasks_table_name));
    if ($task_ids) {
        foreach ($task_ids as $task_id) {
            Schedule\clear_task_event_logic(absint($task_id));
        }
    }
}

/**
 * Removes task-specific cron events that do not belong to a real active task.
 *
 * @return void
 */
function prune_orphaned_task_events_logic(): void
{
    global $wpdb;

    if (!function_exists('_get_cron_array')) {
        return;
    }

    $tasks_table_name = $wpdb->prefix . 'aipkit_automated_tasks';
    $active_task_ids = [];

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct query to a custom table for cron cleanup.
    $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tasks_table_name));
    if ($table_exists === $tasks_table_name) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct query to a custom table for cron cleanup.
        $active_task_ids = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT id FROM ' . esc_sql($tasks_table_name) . ' WHERE status = %s',
                'active'
            )
        );
        $active_task_ids = array_map('absint', (array) $active_task_ids);
    }

    $active_task_ids = array_flip(array_filter($active_task_ids));
    $cron_events = _get_cron_array();
    if (!is_array($cron_events)) {
        return;
    }

    foreach ($cron_events as $events) {
        if (!is_array($events)) {
            continue;
        }
        foreach ($events as $hook => $hook_events) {
            $hook = (string) $hook;
            if (!preg_match('/^aipkit_automated_task_(\d+)$/', $hook, $matches)) {
                continue;
            }

            $task_id = absint($matches[1]);
            if ($task_id > 0 && isset($active_task_ids[$task_id])) {
                continue;
            }

            Schedule\clear_task_hook_events_logic($hook);
        }
    }
}

/**
* Re-schedules all active tasks. Typically called on plugin activation or update.
*
* @return void
*/
function reschedule_all_active_tasks_logic(): void
{
    global $wpdb;
    $tasks_table_name = $wpdb->prefix . 'aipkit_automated_tasks';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct query to a custom table. Caches will be invalidated.
    if ($wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($tasks_table_name) . "'") != $tasks_table_name) {
        return;
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct query to a custom table. Caches will be invalidated.
    $active_tasks = $wpdb->get_results("SELECT id, task_config, task_type FROM " . esc_sql($tasks_table_name) . " WHERE status = 'active'", ARRAY_A);
    if ($active_tasks) {
        foreach ($active_tasks as $task) {
            $config = json_decode($task['task_config'], true);
            $frequency = $task['task_type'] === 'content_indexing'
            ? ($config['indexing_frequency'] ?? 'daily')
            : ($config['task_frequency'] ?? 'daily');
            Schedule\schedule_task_event_logic((int)$task['id'], $frequency, 'active');
        }
    }
}
