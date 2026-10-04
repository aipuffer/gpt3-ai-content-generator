<?php

namespace WPAICG\AutoGPT\Cron;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/lock.php';

/**
 * Shared automation runner used by WordPress cron and the external server endpoint.
 */
class AIPKit_Automation_Runner
{
    private const LOCK_OPTION = 'aipkit_automation_runner_lock_v1';
    private const LOCK_TTL = 30 * MINUTE_IN_SECONDS;
    private const BUSY_RETRY_DELAY = 30;
    private const MAX_DUE_TASKS = 10;

    /**
     * Runs one due task when its task-specific WordPress cron event fires.
     */
    public static function run_wp_cron_task(int $task_id): array
    {
        $result = self::run('wp_cron', $task_id, false, true);
        if (!empty($result['busy'])) {
            $hook = AIPKit_Automated_Task_Scheduler::get_task_specific_cron_hook($task_id);
            wp_schedule_single_event(time() + self::BUSY_RETRY_DELAY, $hook, [$task_id]);
        }
        return $result;
    }

    /**
     * Runs one existing queue batch when the WordPress queue cron event fires.
     */
    public static function run_wp_cron_queue(): array
    {
        $result = self::run('wp_cron', null, true, true, false);
        if (!empty($result['busy'])) {
            wp_schedule_single_event(
                time() + self::BUSY_RETRY_DELAY,
                AIPKit_Automated_Task_Event_Processor::MAIN_CRON_HOOK
            );
        }
        return $result;
    }

    /**
     * Runs due tasks and one queue batch from the authenticated server endpoint.
     */
    public static function run_server_cron(): array
    {
        return self::run('server_cron', null, true, false);
    }

    /**
     * Removes a stale runtime lock during plugin deactivation.
     */
    public static function clear_lock(): void
    {
        delete_option(self::LOCK_OPTION);
    }

    /**
     * Executes a bounded runner pass.
     *
     * @param string   $source               Runner source.
     * @param int|null $specific_task_id     Optional task ID for a task-specific cron wakeup.
     * @param bool     $process_queue         Whether to process one queue batch.
     * @param bool     $schedule_follow_up    Whether task/queue logic may schedule WordPress cron follow-ups.
     * @param bool     $process_due_tasks     Whether to scan and trigger due tasks.
     * @return array<string, mixed>
     */
    private static function run(
        string $source,
        ?int $specific_task_id,
        bool $process_queue,
        bool $schedule_follow_up,
        bool $process_due_tasks = true
    ): array {
        $started_at = microtime(true);
        $lock_token = self::acquire_lock();
        if ($lock_token === '') {
            return self::build_result($source, true, 0, 0, 0, false, $started_at);
        }

        $triggered_tasks = 0;
        $failed_tasks = 0;
        $queue_result = [
            'processed_items' => 0,
            'failed_items' => 0,
            'has_remaining_items' => false,
            'recovered_items' => 0,
        ];
        $additional_queue_results = [];

        try {
            if ($process_due_tasks) {
                foreach (self::get_due_task_ids($specific_task_id) as $task_id) {
                    try {
                        $triggered = AIPKit_Automated_Task_Event_Processor::process_task_trigger(
                            $task_id,
                            $schedule_follow_up
                        );
                        if ($triggered) {
                            ++$triggered_tasks;
                        } else {
                            ++$failed_tasks;
                        }
                    } catch (\Throwable $throwable) {
                        unset($throwable);
                        ++$failed_tasks;

                    }
                }
            }

            if ($process_queue) {
                $queue_result = AIPKit_Automated_Task_Event_Processor::process_queue_batch(
                    $schedule_follow_up,
                    $source
                );
                $additional_queue_results = apply_filters(
                    'aipkit_automation_runner_additional_queue_results',
                    [],
                    $source,
                    $schedule_follow_up
                );
                foreach ((array) $additional_queue_results as $additional_queue_result) {
                    if (is_array($additional_queue_result) && !empty($additional_queue_result['has_remaining_items'])) {
                        $queue_result['has_remaining_items'] = true;
                        break;
                    }
                }
            }
        } finally {
            self::release_lock($lock_token);
        }

        return self::build_result(
            $source,
            false,
            $triggered_tasks,
            $failed_tasks,
            absint($queue_result['processed_items'] ?? 0),
            !empty($queue_result['has_remaining_items']),
            $started_at,
            absint($queue_result['failed_items'] ?? 0),
            absint($queue_result['recovered_items'] ?? 0),
            is_array($additional_queue_results) ? $additional_queue_results : []
        );
    }

    /**
     * Returns due active task IDs using the database schedule as authority.
     *
     * @return int[]
     */
    private static function get_due_task_ids(?int $specific_task_id = null): array
    {
        global $wpdb;
        $tasks_table_name = $wpdb->prefix . 'aipkit_automated_tasks';
        $now_gmt = current_time('mysql', true);

        if ($specific_task_id !== null) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed lookup in a plugin-owned task table.
            $task_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT id FROM " . esc_sql($tasks_table_name) . " WHERE id = %d AND status = %s AND (next_run_time IS NULL OR next_run_time <= %s) LIMIT 1",
                    $specific_task_id,
                    'active',
                    $now_gmt
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded indexed scan in a plugin-owned task table.
            $task_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT id FROM " . esc_sql($tasks_table_name) . " WHERE status = %s AND (next_run_time IS NULL OR next_run_time <= %s) ORDER BY next_run_time ASC, id ASC LIMIT %d",
                    'active',
                    $now_gmt,
                    self::MAX_DUE_TASKS
                )
            );
        }

        return array_values(array_filter(array_map('absint', (array) $task_ids)));
    }

    /**
     * Acquires an atomic option-backed lock and recovers stale locks.
     */
    private static function acquire_lock(): string
    {
        return AIPKit_Option_Lock::acquire(self::LOCK_OPTION, self::LOCK_TTL);
    }

    /**
     * Releases only the lock owned by this runner invocation.
     */
    private static function release_lock(string $token): void
    {
        AIPKit_Option_Lock::release(self::LOCK_OPTION, $token);
    }

    /**
     * Builds the stable runner response returned by the server endpoint.
     *
     * @return array<string, mixed>
     */
    private static function build_result(
        string $source,
        bool $busy,
        int $triggered_tasks,
        int $failed_tasks,
        int $processed_items,
        bool $has_remaining_items,
        float $started_at,
        int $failed_items = 0,
        int $recovered_items = 0,
        array $additional_queue_results = []
    ): array {
        return [
            'status' => $busy ? 'busy' : 'success',
            'source' => sanitize_key($source),
            'busy' => $busy,
            'triggered_tasks' => $triggered_tasks,
            'failed_tasks' => $failed_tasks,
            'processed_items' => $processed_items,
            'failed_items' => $failed_items,
            'recovered_items' => $recovered_items,
            'additional_queues' => $additional_queue_results,
            'has_remaining_items' => $has_remaining_items,
            'timestamp' => time(),
            'duration_ms' => (int) round((microtime(true) - $started_at) * 1000),
        ];
    }
}
