<?php

namespace WPAICG\AutoGPT\Cron;

use WPAICG\AutoGPT\Cron\EventProcessor\MainEvents;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/content-indexing-trigger.php';
require_once __DIR__ . '/content-writing-trigger.php';

/**
 * Handles processing of cron events for Automated Tasks by dispatching to modular logic.
 */
class AIPKit_Automated_Task_Event_Processor
{
    public const MAIN_CRON_HOOK = 'aipkit_process_automated_task_queue';

    /**
     * Callback for task-specific cron events. Triggers processing for that task.
     */
    public static function trigger_task_event(int $task_id)
    {
        if (class_exists(AIPKit_Automation_Runner::class)) {
            AIPKit_Automation_Runner::run_wp_cron_task($task_id);
            return;
        }

        MainEvents\trigger_task_event_logic($task_id);
    }

    /**
     * Executes task trigger logic after the shared runner has acquired its lock.
     */
    public static function process_task_trigger(int $task_id, bool $schedule_queue_event = true): bool
    {
        return MainEvents\trigger_task_event_logic($task_id, $schedule_queue_event);
    }

    /**
     * Callback for the main cron hook. Processes items from the task queue.
     */
    public static function process_task_queue_event()
    {
        if (class_exists(AIPKit_Automation_Runner::class)) {
            AIPKit_Automation_Runner::run_wp_cron_queue();
            return;
        }

        MainEvents\process_task_queue_event_logic();
    }

    /**
     * Processes one automatic, time-budgeted worker pass after the shared runner
     * acquires its lock.
     *
     * @return array<string, int|bool>
     */
    public static function process_queue_batch(bool $schedule_follow_up = true, string $source = 'wp_cron'): array
    {
        return MainEvents\process_task_queue_event_logic($schedule_follow_up, $source);
    }
}

namespace WPAICG\AutoGPT\Cron\EventProcessor\Processor;

// Import all required processor logic files
use WPAICG\AutoGPT\Cron\EventProcessor\Processor\ContentIndexing;
use WPAICG\AutoGPT\Cron\EventProcessor\Processor\ContentWriting;
use WPAICG\AutoGPT\Cron\EventProcessor\Processor\CommentReply;
use WPAICG\AutoGPT\Cron\EventProcessor\Processor\ContentEnhancement;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/content-indexing-worker.php';
require_once __DIR__ . '/content-writing-worker.php';
require_once __DIR__ . '/comment-reply-worker.php';
if (file_exists(__DIR__ . '/content-enhancement-worker.php')) {
    require_once __DIR__ . '/content-enhancement-worker.php';
}

/**
 * Sub-dispatcher that routes a single queue item to the correct processor logic.
 *
 * @param array $item The queue item from the database.
 * @return array ['status' => 'success'|'error', 'message' => '...']
 */
function process_queue_item_logic(array $item): array
{
    $item_task_type = $item['task_type'];
    $item_config = json_decode($item['item_config'], true) ?: [];

    if (strncmp($item_task_type, 'content_writing', strlen('content_writing')) === 0) {
        return ContentWriting\process_content_writing_item_logic($item_config, $item);
    } elseif ($item_task_type === 'content_indexing') {
        $provider = $item_config['target_store_provider'] ?? null;
        switch ($provider) {
            case 'openai':
                return ContentIndexing\process_openai_indexing_logic($item, $item_config);
            case 'google':
                return ContentIndexing\process_google_indexing_logic($item, $item_config);
            case 'pinecone':
                return ContentIndexing\process_pinecone_indexing_logic($item, $item_config);
            case 'qdrant':
                return ContentIndexing\process_qdrant_indexing_logic($item, $item_config);
            case 'chroma':
                return ContentIndexing\process_chroma_indexing_logic($item, $item_config);
            case 'local':
                return ContentIndexing\process_local_indexing_logic($item, $item_config);
            default:
                return ['status' => 'error', 'message' => "Unsupported provider '{$provider}' for content_indexing task."];
        }
    } elseif ($item_task_type === 'community_reply_comments') {
        return CommentReply\process_comment_reply_item_logic($item, $item_config);
    } elseif ($item_task_type === 'enhance_existing_content') {
        return ContentEnhancement\process_enhancement_item_logic($item, $item_config);
    } else {
        return ['status' => 'error', 'message' => "Unsupported task type: {$item_task_type}"];
    }
}

namespace WPAICG\AutoGPT\Cron\EventProcessor\Helpers;

use WPAICG\Vector\PostProcessor\OpenAI\OpenAIPostProcessor;
use WPAICG\Vector\PostProcessor\Google\GooglePostProcessor;
use WPAICG\Vector\PostProcessor\Pinecone\PineconePostProcessor;
use WPAICG\Vector\PostProcessor\Qdrant\QdrantPostProcessor;
use WPAICG\Vector\PostProcessor\Chroma\ChromaPostProcessor;
use WPAICG\Vector\PostProcessor\Base\AIPKit_Vector_Post_Processor_Base;
use WPAICG\Vector\Extraction\AIPKit_Knowledge_Content_Extractor;
use WPAICG\Core\AIPKit_AI_Caller;
use WPAICG\ContentWriter\AIPKit_Content_Writer_Output_Cleaner;
use WPAICG\ContentWriter\Prompt\AIPKit_Content_Writer_System_Instruction_Builder;
use WPAICG\Core\AIPKit_Event_Webhooks;
use WPAICG\AutoGPT\Cron\AIPKit_Automated_Task_Event_Processor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ensures all processor-related classes are loaded.
 *
 * @return void
 */
function load_required_classes_logic(): void
{
    $classes_to_load = [
        AIPKit_Knowledge_Content_Extractor::class => WPAICG_PLUGIN_DIR . 'classes/knowledge-base/extraction.php',
        AIPKit_Vector_Post_Processor_Base::class => WPAICG_PLUGIN_DIR . 'classes/knowledge-base/indexing.php',
        OpenAIPostProcessor::class => WPAICG_PLUGIN_DIR . 'classes/knowledge-base/indexing-openai.php',
        GooglePostProcessor::class => WPAICG_PLUGIN_DIR . 'classes/knowledge-base/indexing-google.php',
        PineconePostProcessor::class => WPAICG_PLUGIN_DIR . 'classes/knowledge-base/indexing-pinecone.php',
        QdrantPostProcessor::class => WPAICG_PLUGIN_DIR . 'classes/knowledge-base/indexing-qdrant.php',
        ChromaPostProcessor::class => WPAICG_PLUGIN_DIR . 'classes/knowledge-base/indexing-chroma.php',
        AIPKit_AI_Caller::class => WPAICG_PLUGIN_DIR . 'classes/ai/caller.php',
        AIPKit_Content_Writer_Output_Cleaner::class => WPAICG_PLUGIN_DIR . 'classes/content-writer/output.php',
        \WPAICG\ContentWriter\AIPKit_Content_Writer_Block_Converter::class => WPAICG_PLUGIN_DIR . 'classes/content-writer/blocks.php',
        AIPKit_Content_Writer_System_Instruction_Builder::class => WPAICG_PLUGIN_DIR . 'classes/content-writer/prompts.php',
    ];

    foreach ($classes_to_load as $class_name => $file_path) {
        if (!class_exists($class_name) && file_exists($file_path)) {
            require_once $file_path;
        }
    }
}

/**
 * Builds a compact queue-item payload summary for webhook delivery.
 *
 * @param array<string, mixed> $item_config
 * @return array<string, mixed>
 */
function build_queue_item_event_summary_logic(array $item_config): array
{
    $summary = [];

    $summary_map = [
        'content_title' => 'content_title',
        'post_id' => 'post_id',
        'comment_id' => 'comment_id',
        'cw_generation_mode' => 'generation_mode',
        'target_store_provider' => 'target_store_provider',
        'target_store_id' => 'target_store_id',
        'scheduled_gmt_time' => 'scheduled_gmt_time',
    ];

    foreach ($summary_map as $config_key => $payload_key) {
        if (!array_key_exists($config_key, $item_config)) {
            continue;
        }
        $summary[$payload_key] = $item_config[$config_key];
    }

    if (!empty($item_config['content_keywords'])) {
        $summary['content_keywords'] = sanitize_text_field((string) $item_config['content_keywords']);
    }

    return $summary;
}

/**
 * Builds AI metadata for automated task queue webhook payloads.
 *
 * @param array<string, mixed> $item_config
 * @return array<string, mixed>
 */
function build_queue_item_ai_payload_logic(array $item_config): array
{
    $ai = [];
    $provider = sanitize_text_field((string) ($item_config['ai_provider'] ?? ''));
    $model = sanitize_text_field((string) ($item_config['ai_model'] ?? ''));

    if ($provider !== '') {
        $ai['provider'] = $provider;
    }

    if ($model !== '') {
        $ai['model'] = $model;
    }

    return $ai;
}

/**
 * Emits the canonical automated-task queue item completed event for a final item state.
 *
 * @param int         $item_id
 * @param string      $db_status
 * @param string|null $status_message
 * @return void
 */
function emit_queue_status_event_logic(int $item_id, string $db_status, ?string $status_message = null): void
{
    if (!class_exists(AIPKit_Event_Webhooks::class)) {
        return;
    }

    if ($db_status !== 'completed') {
        return;
    }

    global $wpdb;
    $queue_table_name = $wpdb->prefix . 'aipkit_automated_task_queue';
    $tasks_table_name = $wpdb->prefix . 'aipkit_automated_tasks';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom queue lookup for event payload construction.
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT q.*, t.task_name
             FROM " . esc_sql($queue_table_name) . " q
             LEFT JOIN " . esc_sql($tasks_table_name) . " t ON q.task_id = t.id
             WHERE q.id = %d
             LIMIT 1",
            $item_id
        ),
        ARRAY_A
    );

    if (!is_array($row) || empty($row)) {
        return;
    }

    $item_config = json_decode((string) ($row['item_config'] ?? ''), true);
    if (!is_array($item_config)) {
        $item_config = [];
    }

    $generated_post_id = null;
    $message_to_parse = (string) ($row['error_message'] ?? $status_message ?? '');
    if ($db_status === 'completed' && preg_match('/ID:\s*(\d+)/', $message_to_parse, $matches) === 1) {
        $generated_post_id = (int) $matches[1];
    }

    $event_name = 'task.item_completed';
    $resource_label = !empty($row['task_name'])
        ? sprintf(
            /* translators: %s: automated task name */
            __('Queue item for %s', 'gpt3-ai-content-generator'),
            (string) $row['task_name']
        )
        : __('Automated task queue item', 'gpt3-ai-content-generator');

    $payload = [
        'task' => [
            'id' => isset($row['task_id']) ? (int) $row['task_id'] : 0,
            'name' => (string) ($row['task_name'] ?? ''),
            'type' => (string) ($row['task_type'] ?? ''),
        ],
        'queue_item' => [
            'id' => (int) ($row['id'] ?? 0),
            'target_identifier' => (string) ($row['target_identifier'] ?? ''),
            'status' => $db_status,
            'attempts' => (int) ($row['attempts'] ?? 0),
            'added_at' => (string) ($row['added_at'] ?? ''),
            'last_attempt_time' => (string) ($row['last_attempt_time'] ?? ''),
        ],
        'result' => [
            'message' => $message_to_parse,
        ],
        'item' => build_queue_item_event_summary_logic($item_config),
    ];
    $ai_payload = build_queue_item_ai_payload_logic($item_config);
    if (!empty($ai_payload)) {
        $payload['ai'] = $ai_payload;
    }

    if ($generated_post_id) {
        $payload['result']['generated_post_id'] = $generated_post_id;
    }

    $event_meta = [
        'task_id' => isset($row['task_id']) ? (int) $row['task_id'] : 0,
        'task_type' => (string) ($row['task_type'] ?? ''),
        'queue_status' => $db_status,
    ];
    if (!empty($ai_payload['provider'])) {
        $event_meta['ai_provider'] = $ai_payload['provider'];
    }
    if (!empty($ai_payload['model'])) {
        $event_meta['ai_model'] = $ai_payload['model'];
    }

    AIPKit_Event_Webhooks::emit(
        $event_name,
        $payload,
        [
            'module' => 'automated_tasks',
            'origin' => 'queue_processor',
            'resource' => [
                'type' => 'queue_item',
                'id' => (int) ($row['id'] ?? 0),
                'label' => $resource_label,
            ],
            'meta' => $event_meta,
            'idempotency_key' => sha1(implode('|', [
                $event_name,
                (string) ($row['id'] ?? 0),
                $db_status,
                (string) ($row['attempts'] ?? 0),
                $message_to_parse,
            ])),
        ]
    );
}

/**
 * Updates the status and error message of a specific queue item.
 *
 * @param int $itemId The ID of the queue item.
 * @param string $status The new status ('processing', 'completed', 'failed', 'success').
 * @param string|null $errorMessage The error message, if status is 'failed'.
 * @return void
 */
function update_queue_status_logic(int $itemId, string $status, ?string $errorMessage = null): void
{
    global $wpdb;
    $queue_table_name = $wpdb->prefix . 'aipkit_automated_task_queue';
    $db_status = $status;

    $update_data = [];
    $formats = [];

    if ($status === 'success') {
        $update_data['status'] = 'completed'; // Set final DB status to 'completed'
        $update_data['error_message'] = $errorMessage; // Store the success message (which has post ID)
        $formats = ['%s', '%s'];
        $db_status = 'completed';
    } else {
        if ($status === 'error') {
            $update_data['status'] = 'failed'; // Standardize DB status to 'failed'
            $db_status = 'failed';
        } else {
            $update_data['status'] = $status;
            $db_status = $status;
        }
        $formats[] = '%s';

        if ($status === 'processing') {
            $update_data['last_attempt_time'] = current_time('mysql', 1);
            $formats[] = '%s';
            $update_data['error_message'] = $errorMessage;
            $formats[] = '%s';
        } elseif ($status === 'failed' || $status === 'error') {
            $update_data['error_message'] = $errorMessage;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct update to a custom table. Caches will be invalidated.
            $wpdb->query($wpdb->prepare("UPDATE " . esc_sql($queue_table_name) . " SET attempts = attempts + 1 WHERE id = %d", $itemId));
            $formats[] = '%s';
        }
    }
    $update_data['sort_priority'] = $db_status === 'processing' ? 2 : 1;
    $formats[] = '%d';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct update to a custom table. Caches will be invalidated.
    $wpdb->update(
        $queue_table_name,
        $update_data,
        ['id' => $itemId],
        $formats,
        ['%d']
    );

    emit_queue_status_event_logic($itemId, $db_status, $errorMessage);
}

/** Link a submitted provider job without introducing a second upload or queue state. */
function defer_indexing_queue_item_logic(array $item, array $result): bool
{
    global $wpdb;
    $job_id = absint($result['job_id'] ?? 0);
    $config = json_decode((string) ($item['item_config'] ?? ''), true);
    if (!$job_id || !is_array($config) || ($item['task_type'] ?? '') !== 'content_indexing') {
        return false;
    }
    $config['_aipkit_indexing_job'] = $job_id;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Persist the source identity on the claimed queue item before releasing the worker.
    return $wpdb->update(
        $wpdb->prefix . 'aipkit_automated_task_queue',
        ['item_config' => wp_json_encode($config)],
        ['id' => absint($item['id']), 'status' => 'processing'],
        ['%s'],
        ['%d', '%s']
    ) !== false;
}

/** True when provider jobs, rather than PHP workers, own processing queue items. */
function has_waiting_indexing_queue_items_logic(): bool
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded queue existence query; the marker is an internal JSON key, not user SQL.
    return (bool) $wpdb->get_var($wpdb->prepare(
        'SELECT 1 FROM ' . esc_sql($wpdb->prefix . 'aipkit_automated_task_queue') . ' WHERE status = %s AND task_type = %s AND item_config LIKE %s LIMIT 1',
        'processing', 'content_indexing', '%' . $wpdb->esc_like('"_aipkit_indexing_job":') . '%'
    ));
}

/** Read confirmed source results locally. Never call a provider or resubmit content. */
function reconcile_indexing_queue_items_logic(): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'aipkit_automated_task_queue';
    $cursor_option = 'aipkit_indexing_completion_cursor';
    $cursor = absint(get_option($cursor_option, 0));
    $items = [];
    for ($pass = 0; $pass < 2; ++$pass) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded, cursor-based check of plugin-owned asynchronous queue items.
        $items = $wpdb->get_results($wpdb->prepare(
            'SELECT id, task_id, target_identifier, item_config FROM ' . esc_sql($table) . ' WHERE status = %s AND task_type = %s AND item_config LIKE %s AND id > %d ORDER BY id ASC LIMIT 20',
            'processing', 'content_indexing', '%' . $wpdb->esc_like('"_aipkit_indexing_job":') . '%', $cursor
        ), ARRAY_A) ?: [];
        if ($items || !$cursor) {
            break;
        }
        $cursor = 0;
    }
    $summary = ['completed' => 0, 'failed' => 0, 'task_ids' => []];
    foreach ($items as $item) {
        $cursor = absint($item['id']);
        $config = json_decode((string) $item['item_config'], true) ?: [];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact source record lookup; no remote work is repeated.
        $source = $wpdb->get_row($wpdb->prepare(
            'SELECT status, message, provider, post_id, vector_store_id FROM ' . esc_sql($wpdb->prefix . 'aipkit_vector_data_source') . ' WHERE id = %d',
            absint($config['_aipkit_indexing_job'] ?? 0)
        ), ARRAY_A);
        $matches = $source
            && (int) $source['post_id'] === (int) $item['target_identifier']
            && strtolower($source['provider']) === strtolower((string) ($config['target_store_provider'] ?? ''))
            && $source['vector_store_id'] === (string) ($config['target_store_id'] ?? '');
        if ($matches && in_array($source['status'], ['processing', 'queued'], true)) {
            continue;
        }
        $ready = $matches && $source['status'] === 'indexed';
        $message = $matches ? (string) $source['message'] : __('The indexing result is no longer available. Check Sources before retrying.', 'gpt3-ai-content-generator');
        update_queue_status_logic($cursor, $ready ? 'success' : 'error', $message);
        ++$summary[$ready ? 'completed' : 'failed'];
        $summary['task_ids'][] = absint($item['task_id']);
    }
    if ($items) {
        update_option($cursor_option, $cursor, false);
    } else {
        delete_option($cursor_option);
    }
    return $summary;
}

/** Held tasks retain pending rows, including one-time tasks that are normally paused. */
function unblocked_queue_condition_logic(): string
{
    global $wpdb;
    $tasks = $wpdb->prefix . 'aipkit_automated_tasks';
    $queue = $wpdb->prefix . 'aipkit_automated_task_queue';
    return $wpdb->prepare(" AND NOT EXISTS (SELECT 1 FROM " . esc_sql($tasks) . " held_task WHERE held_task.id = " . esc_sql($queue) . ".task_id AND held_task.task_config LIKE %s)", '%' . $wpdb->esc_like('"_aipkit_provider_hold":') . '%');
}

/** Pause future triggers and retain pending work until the user explicitly resumes it. */
function hold_task_for_provider_logic(int $task_id, array $result): void
{
    global $wpdb;
    $table = $wpdb->prefix . 'aipkit_automated_tasks';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Preserve the current task configuration while holding queued work.
    $json = $wpdb->get_var($wpdb->prepare('SELECT task_config FROM ' . esc_sql($table) . ' WHERE id = %d', $task_id));
    $config = json_decode((string) $json, true);
    if (!is_array($config)) { return; }
    $config['_aipkit_provider_hold'] = sanitize_key((string) ($result['provider_error_code'] ?? $result['code'] ?? 'provider_refusal'));
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Hold both scheduled production and existing queue work.
    $wpdb->update($table, ['task_config' => wp_json_encode($config), 'status' => 'paused', 'next_run_time' => null], ['id' => $task_id], ['%s', '%s', '%s'], ['%d']);
    \WPAICG\AutoGPT\Cron\AIPKit_Automated_Task_Scheduler::clear_task_event($task_id);
}

/**
 * Checks if there are more pending items in the queue and schedules
 * an immediate one-off event to process them.
 *
 * @return void
 */
function maybe_reschedule_queue_logic(): void
{
    global $wpdb;
    $queue_table_name = $wpdb->prefix . 'aipkit_automated_task_queue';
    $main_cron_hook = AIPKit_Automated_Task_Event_Processor::MAIN_CRON_HOOK;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The helper returns a prepared predicate; the remaining values are prepared here and the queue identifier is escaped.
    $remaining_items = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM " . esc_sql($queue_table_name) . " WHERE status = %s" . unblocked_queue_condition_logic() . " LIMIT 1", 'pending'));

    if ($remaining_items > 0 || has_waiting_indexing_queue_items_logic()) {
        wp_schedule_single_event(time() + 30, $main_cron_hook);
    }
}

/**
 * Returns the automatic queue-worker policy for the current wake-up source.
 *
 * The time budget is the primary limit. The item caps are final guards for
 * very fast jobs, including expensive generative work.
 *
 * @return array{time_budget_seconds:int,max_items:int,max_expensive_items:int,stale_processing_seconds:int,recovery_limit:int}
 */
function get_automatic_worker_policy_logic(string $source): array
{
    $source = sanitize_key($source);
    $is_server_cron = $source === 'server_cron';
    $policy = [
        'time_budget_seconds' => $is_server_cron ? 50 : 20,
        'max_items' => $is_server_cron ? 50 : 20,
        'max_expensive_items' => 5,
        'stale_processing_seconds' => 30 * MINUTE_IN_SECONDS,
        'recovery_limit' => 100,
    ];

    $php_execution_limit = (int) ini_get('max_execution_time');
    if ($php_execution_limit > 0) {
        $policy['time_budget_seconds'] = min(
            $policy['time_budget_seconds'],
            max(5, $php_execution_limit - 5)
        );
    }

    /**
     * Filters the internal automatic worker policy for advanced deployments.
     *
     * This is deliberately not exposed as a numeric UI setting: the bounded
     * defaults protect shared hosting and provider rate limits.
     *
     * @param array<string, int> $policy Automatic worker policy.
     * @param string             $source Worker source, such as wp_cron or server_cron.
     */
    $filtered_policy = apply_filters('aipkit_automation_worker_policy', $policy, $source);
    if (is_array($filtered_policy)) {
        $policy = array_merge($policy, $filtered_policy);
    }

    return [
        'time_budget_seconds' => max(5, min(120, absint($policy['time_budget_seconds'] ?? 20))),
        'max_items' => max(1, min(100, absint($policy['max_items'] ?? 20))),
        'max_expensive_items' => max(1, min(10, absint($policy['max_expensive_items'] ?? 5))),
        'stale_processing_seconds' => max(
            15 * MINUTE_IN_SECONDS,
            min(DAY_IN_SECONDS, absint($policy['stale_processing_seconds'] ?? (30 * MINUTE_IN_SECONDS)))
        ),
        'recovery_limit' => max(1, min(500, absint($policy['recovery_limit'] ?? 100))),
    ];
}

/**
 * Decides whether another expensive item is likely to fit safely in this pass.
 *
 * The observed average adapts to the site's provider and task complexity. A
 * multiplier plus a small margin prevents a single slow request from causing
 * the worker to start another call too close to its execution deadline.
 *
 * @param array<string, int> $policy Worker policy.
 */
function should_continue_after_expensive_item_logic(
    array $policy,
    int $processed_expensive_items,
    float $expensive_processing_seconds,
    float $elapsed_seconds
): bool {
    $maximum = absint($policy['max_expensive_items'] ?? 1);
    if ($processed_expensive_items >= $maximum) {
        return false;
    }

    $average_seconds = $expensive_processing_seconds / max(1, $processed_expensive_items);
    $predicted_next_seconds = max(2.0, ($average_seconds * 1.5) + 2.0);
    $remaining_seconds = max(0.0, absint($policy['time_budget_seconds'] ?? 0) - $elapsed_seconds);

    return $remaining_seconds >= $predicted_next_seconds;
}

/**
 * Returns whether a queue item may involve multiple long-running AI calls.
 */
function is_expensive_queue_task_logic(string $task_type): bool
{
    return strncmp($task_type, 'content_writing', strlen('content_writing')) === 0
        || $task_type === 'enhance_existing_content'
        || $task_type === 'community_reply_comments';
}

/**
 * Detects a provider rate-limit response so the worker stops starting more work.
 * The failed item remains visible for the existing manual retry flow.
 *
 * @param array<string, mixed> $result Queue processor result.
 */
function is_rate_limited_result_logic(array $result): bool
{
    $message = strtolower((string) ($result['message'] ?? ''));
    if ($message === '') {
        return false;
    }

    return strpos($message, 'rate limit') !== false
        || strpos($message, 'too many requests') !== false
        || preg_match('/\b429\b/', $message) === 1;
}

namespace WPAICG\AutoGPT\Cron\EventProcessor\MainEvents;

use WPAICG\AutoGPT\Cron\EventProcessor\Trigger;
use WPAICG\AutoGPT\Cron\EventProcessor\Helpers;
use WPAICG\AutoGPT\Cron\AIPKit_Automated_Task_Scheduler;
use WPAICG\AutoGPT\Cron\EventProcessor\Processor;
use WPAICG\AutoGPT\Cron\AIPKit_Automated_Task_Content_Queuer;

if (!defined('ABSPATH')) {
    exit;
}

use WPAICG\AutoGPT\Cron\AIPKit_Automated_Task_Event_Processor;

if (file_exists(__DIR__ . '/comment-reply-trigger.php')) {
    require_once __DIR__ . '/comment-reply-trigger.php';
}
if (file_exists(__DIR__ . '/content-enhancement-trigger.php')) {
    require_once __DIR__ . '/content-enhancement-trigger.php';
}

/**
 * Callback for task-specific cron events. Triggers processing for that task.
 *
 * @param int $task_id The ID of the task to trigger.
 * @param bool $schedule_queue_event Whether to wake the queue through WordPress cron.
 * @return bool True when a supported active task was triggered.
 */
function trigger_task_event_logic(int $task_id, bool $schedule_queue_event = true): bool
{
    global $wpdb;
    $tasks_table_name = $wpdb->prefix . 'aipkit_automated_tasks';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct query to a custom table. Caches will be invalidated.
    $task = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . esc_sql($tasks_table_name) . " WHERE id = %d AND status = 'active'", $task_id), ARRAY_A);
    if (!$task) {

        return false;
    }

    $task_config = json_decode($task['task_config'], true) ?: [];
    $task_type = $task['task_type'];
    $frequency = $task_type === 'content_indexing'
        ? ($task_config['indexing_frequency'] ?? 'daily')
        : ($task_config['task_frequency'] ?? 'daily');
    $task_run_state = [];

    $supported_task = true;
    if (strncmp($task_type, 'content_writing', strlen('content_writing')) === 0) {
        Trigger\trigger_content_writing_task_logic($task_id, $task_config);
    } elseif ($task_type === 'content_indexing') {
        $task_run_state = Trigger\trigger_content_indexing_task_logic($task_id, $task_config, $task['last_run_time']);
    } elseif ($task_type === 'community_reply_comments') {
        Trigger\trigger_comment_reply_task_logic($task_id, $task_config, $task['last_run_time']);
    } elseif ($task_type === 'enhance_existing_content') {
        Trigger\trigger_content_enhancement_task_logic($task_id, $task_config, $task['last_run_time']);
    } else {
        $supported_task = false;

    }

    if ($schedule_queue_event && class_exists('\WPAICG\AutoGPT\Cron\AIPKit_Automated_Task_Event_Processor')) {
        // Schedule a one-off event to start processing the queue almost immediately,
        // instead of calling it directly and risking a timeout before the parent cron can update its run times.
        wp_schedule_single_event(time() + 10, AIPKit_Automated_Task_Event_Processor::MAIN_CRON_HOOK);

        // For one-time tasks, also ensure the main hourly cron stays scheduled while there are pending items
        if ($frequency === 'one-time' && !wp_next_scheduled(AIPKit_Automated_Task_Event_Processor::MAIN_CRON_HOOK)) {
            wp_schedule_event(time(), 'hourly', AIPKit_Automated_Task_Event_Processor::MAIN_CRON_HOOK);
        }
    }

    // Advance the authoritative database schedule independently of WordPress's cron array.
    $previous_next_timestamp = !empty($task['next_run_time'])
        ? strtotime((string) $task['next_run_time'] . ' UTC')
        : null;
    $next_timestamp = AIPKit_Automated_Task_Scheduler::calculate_next_run_timestamp(
        $frequency,
        $previous_next_timestamp ?: null
    );
    $next_run_datetime_gmt = $next_timestamp ? gmdate('Y-m-d H:i:s', $next_timestamp) : null;

    $update_data = [
        'last_run_time' => isset($task_run_state['last_run_time'])
            ? (string) $task_run_state['last_run_time']
            : current_time('mysql', 1),
        'next_run_time' => $next_run_datetime_gmt,
    ];
    $update_formats = ['%s', '%s'];

    if ($frequency === 'one-time') {
        $update_data['status'] = 'paused';
        $update_formats[] = '%s';
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct update to a custom table. Caches will be invalidated.
    $wpdb->update(
        $tasks_table_name,
        $update_data,
        ['id' => $task_id],
        $update_formats,
        ['%d']
    );

    return $supported_task;
}

/**
 * Callback for the main cron hook. Processes items from the task queue.
 *
 * @param bool $schedule_follow_up Whether to schedule another WordPress cron queue event.
 * @param string $source Worker wake-up source.
 * @return array<string, int|bool> Processing summary.
 */
function process_task_queue_event_logic(bool $schedule_follow_up = true, string $source = 'wp_cron'): array
{
    global $wpdb;
    $queue_table_name = $wpdb->prefix . 'aipkit_automated_task_queue';

    Helpers\load_required_classes_logic();
    $policy = Helpers\get_automatic_worker_policy_logic($source);
    $started_at = microtime(true);
    $indexing_results = Helpers\reconcile_indexing_queue_items_logic();
    $recovered_items = recover_stale_queue_items_logic(
        $wpdb,
        $queue_table_name,
        $policy['stale_processing_seconds'],
        $policy['recovery_limit']
    );
    $processed_task_ids = $indexing_results['task_ids'];
    $processed_items = 0;
    $failed_items = $indexing_results['failed'];
    $processed_expensive_items = 0;
    $expensive_processing_seconds = 0.0;
    $task_cursor_option = 'aipkit_automation_queue_task_cursor_v1';
    $task_cursor = absint(get_option($task_cursor_option, 0));

    while ($processed_items < $policy['max_items']) {
        if ($processed_items > 0 && (microtime(true) - $started_at) >= $policy['time_budget_seconds']) {
            break;
        }

        $item = claim_next_queue_item_logic($wpdb, $queue_table_name, $task_cursor);
        if (empty($item)) {
            break;
        }

        $task_cursor = absint($item['task_id'] ?? 0);
        $processed_task_ids[] = $task_cursor;
        $is_expensive_item = Helpers\is_expensive_queue_task_logic((string) ($item['task_type'] ?? ''));
        $item_started_at = microtime(true);
        // Observe the original provider error before a worker wraps its message or keeps a partial result.
        $provider_refusal = null;
        $capture_refusal = static function ($code, $message, $data) use (&$provider_refusal): void {
            // Do not construct WP_Error here: it would recursively emit this hook.
            if (is_array($data) && (!empty($data['stop_batch']) || in_array((int) ($data['status_code'] ?? $data['status'] ?? 0), [401, 402, 403, 429], true)) && $provider_refusal === null) {
                $provider_refusal = ['code' => $code, 'message' => $message, 'data' => $data];
            }
        };
        add_action('wp_error_added', $capture_refusal, 10, 3);
        try {
            $result = Processor\process_queue_item_logic($item);
        } catch (\Throwable $throwable) {
            $result = [
                'status' => 'error',
                'message' => sprintf(
                    'Unexpected queue processing error: %s',
                    sanitize_text_field($throwable->getMessage())
                ),
            ];
        } finally {
            remove_action('wp_error_added', $capture_refusal, 10);
        }

        if (!is_array($result) || !isset($result['status'])) {
            $result = [
                'status' => 'error',
                'message' => 'The queue processor returned an invalid result.',
            ];
        }

        if ($provider_refusal !== null && empty($result['stop_batch'])) {
            $error = new \WP_Error($provider_refusal['code'], $provider_refusal['message'], $provider_refusal['data']);
            $result = array_merge($result, \WPAICG\AutoGPT\Helpers\provider_error_result($error));
        }
        if (!empty($result['pause_task'])) {
            Helpers\hold_task_for_provider_logic($task_cursor, $result);
            $result['message'] .= ' ' . __('Task paused. Resolve the provider issue, then resume the task or retry the failed item.', 'gpt3-ai-content-generator');
        }

        $result_status = sanitize_key((string) $result['status']);
        if (!in_array($result_status, ['success', 'error', 'failed'], true)) {
            $result = [
                'status' => 'error',
                'message' => 'The queue processor returned an unsupported status.',
            ];
            $result_status = 'error';
        } else {
            $result['status'] = $result_status;
        }

        if ($result_status === 'success' && !empty($result['processing']) && ($item['task_type'] ?? '') === 'content_indexing') {
            if (Helpers\defer_indexing_queue_item_logic($item, $result)) {
                $result_status = 'processing';
            } else {
                $result_status = 'error';
                $result['message'] = __('Could not save indexing progress. Check Sources before retrying.', 'gpt3-ai-content-generator');
            }
        }

        Helpers\update_queue_status_logic($item['id'], $result_status, $result['message'] ?? null);
        ++$processed_items;

        if (in_array($result_status, ['error', 'failed'], true)) {
            ++$failed_items;

        }

        if ($is_expensive_item) {
            ++$processed_expensive_items;
            $expensive_processing_seconds += microtime(true) - $item_started_at;
        }
        if ($provider_refusal !== null || !empty($result['stop_batch']) || Helpers\is_rate_limited_result_logic($result)) {
            break;
        }
        if (
            $is_expensive_item
            && !Helpers\should_continue_after_expensive_item_logic(
                $policy,
                $processed_expensive_items,
                $expensive_processing_seconds,
                microtime(true) - $started_at
            )
        ) {
            break;
        }
    }

    if ($processed_items > 0) {
        update_option($task_cursor_option, $task_cursor, false);
    }

    refill_content_indexing_queue_windows_logic(array_values(array_unique(array_filter($processed_task_ids))));
    if ($schedule_follow_up) {
        Helpers\maybe_reschedule_queue_logic();
    }

    // A bounded existence check avoids an expensive exact count on very large queues.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Values are prepared below, including the helper predicate; table identifiers are escaped.
    $has_remaining_items = (bool) $wpdb->get_var(
        $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The helper returns a prepared predicate; the remaining values are prepared here and the queue identifier is escaped.
            "SELECT 1 FROM " . esc_sql($queue_table_name) . " WHERE status = %s" . Helpers\unblocked_queue_condition_logic() . " LIMIT 1",
            'pending'
        )
    );

    return [
        'processed_items' => $processed_items + $indexing_results['completed'] + $indexing_results['failed'],
        'failed_items' => $failed_items,
        'has_remaining_items' => $has_remaining_items || Helpers\has_waiting_indexing_queue_items_logic(),
        'recovered_items' => $recovered_items,
    ];
}

/**
 * Atomically claims the next pending queue row.
 *
 * @return array<string, mixed>|null
 */
function claim_next_queue_item_logic(\wpdb $wpdb, string $queue_table_name, int $after_task_id = 0): ?array
{
    for ($claim_attempt = 0; $claim_attempt < 3; ++$claim_attempt) {
        if ($after_task_id > 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Values are prepared below, including the helper predicate; table identifiers are escaped.
            $item = $wpdb->get_row(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The helper returns a prepared predicate; the remaining values are prepared here and the queue identifier is escaped.
                    "SELECT * FROM " . esc_sql($queue_table_name) . " WHERE status = %s AND task_id > %d" . Helpers\unblocked_queue_condition_logic() . " ORDER BY task_id ASC, id ASC LIMIT 1",
                    'pending',
                    $after_task_id
                ),
                ARRAY_A
            );
        } else {
            $item = null;
        }
        if (!is_array($item) || empty($item['id'])) {
            // Wrap to the first task after reaching the end of the task-ID sequence.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Values are prepared below, including the helper predicate; table identifiers are escaped.
            $item = $wpdb->get_row(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The helper returns a prepared predicate; the remaining values are prepared here and the queue identifier is escaped.
                    "SELECT * FROM " . esc_sql($queue_table_name) . " WHERE status = %s" . Helpers\unblocked_queue_condition_logic() . " ORDER BY task_id ASC, id ASC LIMIT 1",
                    'pending'
                ),
                ARRAY_A
            );
        }
        if (!is_array($item) || empty($item['id'])) {
            return null;
        }

        $claimed_at = current_time('mysql', true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional update atomically claims a plugin-owned queue row.
        $claimed = $wpdb->query(
            $wpdb->prepare(
                "UPDATE " . esc_sql($queue_table_name) . " SET status = %s, last_attempt_time = %s, sort_priority = %d WHERE id = %d AND status = %s",
                'processing',
                $claimed_at,
                2,
                absint($item['id']),
                'pending'
            )
        );
        if ($claimed === 1) {
            $item['status'] = 'processing';
            $item['last_attempt_time'] = $claimed_at;
            $item['sort_priority'] = 2;
            return $item;
        }
        $after_task_id = absint($item['task_id'] ?? $after_task_id);
    }

    return null;
}

/**
 * Returns abandoned processing rows to pending in small, bounded chunks.
 */
function recover_stale_queue_items_logic(
    \wpdb $wpdb,
    string $queue_table_name,
    int $stale_after_seconds,
    int $recovery_limit
): int {
    $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, $stale_after_seconds));
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded recovery update in a plugin-owned queue table.
    $recovered = $wpdb->query(
        $wpdb->prepare(
            "UPDATE " . esc_sql($queue_table_name) . " SET status = %s, sort_priority = %d WHERE status = %s AND NOT (task_type = %s AND COALESCE(item_config, '') LIKE %s) AND ((last_attempt_time IS NOT NULL AND last_attempt_time < %s) OR (last_attempt_time IS NULL AND added_at < %s)) LIMIT %d",
            'pending',
            1,
            'processing',
            'content_indexing',
            '%' . $wpdb->esc_like('"_aipkit_indexing_job":') . '%',
            $cutoff,
            $cutoff,
            max(1, $recovery_limit)
        )
    );
    $recovered = max(0, (int) $recovered);

    return $recovered;
}

/** Refills bounded initial-indexing windows after the worker creates capacity. */
function refill_content_indexing_queue_windows_logic(array $task_ids): void
{
    if (empty($task_ids) || !class_exists(AIPKit_Automated_Task_Content_Queuer::class)) {
        return;
    }

    global $wpdb;
    $tasks_table_name = $wpdb->prefix . 'aipkit_automated_tasks';
    $placeholders = implode(',', array_fill(0, count($task_ids), '%d'));
    $params = array_merge(['content_indexing'], $task_ids);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bounded lookup of the task rows represented in the just-processed worker batch.
    $tasks = $wpdb->get_results($wpdb->prepare("SELECT id, task_config, status, last_run_time FROM {$tasks_table_name} WHERE task_type = %s AND id IN ($placeholders)", ...$params), ARRAY_A);
    foreach ((array) $tasks as $task) {
        $task_id = absint($task['id'] ?? 0);
        $task_config = json_decode((string) ($task['task_config'] ?? ''), true);
        if ($task_id <= 0 || !is_array($task_config) || isset($task_config['_aipkit_provider_hold'])) {
            continue;
        }
        $initial_requested = ($task_config['index_existing_now_flag'] ?? '0') === '1';
        $force_requested = get_option('aipkit_initial_queue_force_v2_' . $task_id, '') === 'yes';
        if ($initial_requested || $force_requested) {
            AIPKit_Automated_Task_Content_Queuer::maybe_queue_initial_indexing_content($task_id, $task_config, false);
        }

        $incremental_requested = ($task_config['only_new_updated_flag'] ?? '0') === '1';
        if ($incremental_requested && ($task['status'] ?? '') === 'active') {
            $run_state = AIPKit_Automated_Task_Content_Queuer::queue_new_or_updated_indexing_content(
                $task_id,
                $task_config,
                isset($task['last_run_time']) ? (string) $task['last_run_time'] : null
            );
            if (!empty($run_state['last_run_time']) && $run_state['last_run_time'] !== ($task['last_run_time'] ?? null)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Updates the safe watermark for the plugin-owned task row after a bounded refill.
                $wpdb->update(
                    $tasks_table_name,
                    ['last_run_time' => (string) $run_state['last_run_time']],
                    ['id' => $task_id],
                    ['%s'],
                    ['%d']
                );
            }
        }
    }
}
