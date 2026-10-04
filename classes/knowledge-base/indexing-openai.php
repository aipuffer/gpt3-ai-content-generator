<?php

namespace WPAICG\Vector\PostProcessor\OpenAI;

use WPAICG\AIPKit_Providers;
use WPAICG\Vector\PostProcessor\Base\AIPKit_Vector_Post_Processor_Base;
use WPAICG\Vector\AIPKit_Vector_Provider_Strategy_Factory;
use WPAICG\AutoGPT\Cron\AIPKit_Option_Lock;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

if (!class_exists(AIPKit_Vector_Post_Processor_Base::class)) {
    $base_class_path = WPAICG_PLUGIN_DIR . 'classes/knowledge-base/indexing.php';
    if (file_exists($base_class_path)) {
        require_once $base_class_path;
    }
}

/**
 * Handles fetching OpenAI API configuration for post processing.
 */
class OpenAIConfig {

    /**
     * @return mixed[]|\WP_Error
     */
    public function get_config() {
        if (!class_exists(\WPAICG\AIPKit_Providers::class)) {
            $providers_path = WPAICG_PLUGIN_DIR . 'classes/ai/settings.php';
            if (file_exists($providers_path)) require_once $providers_path;
            else return new WP_Error('dependency_missing_config_openai', 'AIPKit_Providers class not found for OpenAI config.');
        }
        $openai_data = AIPKit_Providers::get_provider_data('OpenAI');
        if (empty($openai_data['api_key'])) {
            return new WP_Error('missing_openai_key_config', __('OpenAI API Key is not configured in global settings.', 'gpt3-ai-content-generator'));
        }
        return [
            'api_key'     => $openai_data['api_key'],
            // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Provider-specific API transport.
            'base_url'    => $openai_data['base_url'] ?? 'https://api.openai.com',
            'api_version' => $openai_data['api_version'] ?? 'v1',
        ];
    }
}

/**
 * Handles indexing WordPress post content into OpenAI Vector Stores.
 */
class OpenAIPostProcessor extends AIPKit_Vector_Post_Processor_Base
{
    public const RECONCILE_HOOK = 'aipkit_openai_post_reconcile';
    private const RECONCILE_CURSOR = 'aipkit_openai_post_reconcile_cursor';
    private static $hooks_registered = false;
    private $vector_store_manager;
    private $config_handler;

    public function __construct()
    {
        parent::__construct();
        $this->vector_store_manager = $this->create_vector_store_manager();
        $this->config_handler = new OpenAIConfig();
    }

    private static function get_validated_table_identifier(string $table_name): string
    {
        $table_name = trim($table_name);
        if ($table_name === '' || !preg_match('/^[A-Za-z0-9_]+$/', $table_name)) {
            return '';
        }

        return '`' . $table_name . '`';
    }

    /**
     * Indexes a single post's content to a specified OpenAI Vector Store.
     * @param int $post_id The ID of the post to index.
     * @param string $vector_store_id The ID of the target OpenAI Vector Store.
     * @param string|null $vector_store_name_for_log Optional name of the store for logging purposes.
     * @return array ['status' => 'success'|'error', 'message' => string, 'file_id' => string|null, 'batch_id' => string|null]
     */
    public function index_single_post_to_store(int $post_id, string $vector_store_id, ?string $vector_store_name_for_log = null): array
    {
        $lock_name = self::lock_name($post_id, $vector_store_id);
        $token = self::acquire_lock($lock_name);
        if ($token === '') {
            return ['status' => 'error', 'message' => __('This source is already being updated. Please try again shortly.', 'gpt3-ai-content-generator')];
        }
        try {
            $pending = $this->find_pending_job($post_id, $vector_store_id);
            if ($pending) {
                self::schedule_reconciliation();
                return $this->pending_result($pending);
            }
            return $this->start_indexing($post_id, $vector_store_id, $vector_store_name_for_log);
        } finally {
            AIPKit_Option_Lock::release($lock_name, $token);
        }
    }

    private function start_indexing(int $post_id, string $vector_store_id, ?string $vector_store_name_for_log): array
    {
        $post_obj = get_post($post_id);
        $post_title_for_log = $post_obj ? $post_obj->post_title : 'N/A';

        $log_entry_base = [
            'provider' => 'OpenAI', 'vector_store_id' => $vector_store_id, 'vector_store_name' => $vector_store_name_for_log ?: $vector_store_id,
            'post_id' => $post_id, 'post_title' => $post_title_for_log,
            'source_type_for_log' => 'wordpress_post'
        ];

        $return_error = function (string $error_msg, ?string $file_id = null, ?string $batch_id = null) use ($log_entry_base): array {
            $failure_log = array_merge($log_entry_base, [
                'status' => 'failed',
                'message' => $error_msg,
            ]);
            if ($file_id !== null) {
                $failure_log['file_id'] = $file_id;
            }
            if ($batch_id !== null) {
                $failure_log['batch_id'] = $batch_id;
            }
            $this->log_event($failure_log);

            return ['status' => 'error', 'message' => $error_msg, 'file_id' => $file_id, 'batch_id' => $batch_id];
        };

        if (!$this->config_handler || !$this->vector_store_manager) {
            $error_msg = __('OpenAI processing components not available.', 'gpt3-ai-content-generator');
            return $return_error($error_msg);
        }

        $openai_config = $this->config_handler->get_config();
        if (is_wp_error($openai_config)) {
            $error_msg = $openai_config->get_error_message();
            return $return_error($error_msg);
        }

        $strategy = AIPKit_Vector_Provider_Strategy_Factory::get_strategy('OpenAI');
        if (is_wp_error($strategy) || !method_exists($strategy, 'delete_openai_file_object') || !method_exists($strategy, 'upload_file_for_vector_store')) {
            $error_msg = __('OpenAI file processing strategy not available.', 'gpt3-ai-content-generator');
            return $return_error($error_msg);
        }
        $connect_result = $strategy->connect($openai_config);
        if (is_wp_error($connect_result) || $connect_result === false) {
            $error_msg = is_wp_error($connect_result)
                ? $connect_result->get_error_message()
                : __('Failed to connect to OpenAI vector store.', 'gpt3-ai-content-generator');
            return $return_error('Connection error: ' . $error_msg);
        }

        $content_string_or_error = $this->get_post_content_as_string($post_id);
        if (is_wp_error($content_string_or_error) || empty(trim($content_string_or_error))) {
            $error_msg = is_wp_error($content_string_or_error) ? 'Content retrieval error: ' . $content_string_or_error->get_error_message() : __('Post content is empty.', 'gpt3-ai-content-generator');
            return $return_error($error_msg);
        }

        $log_entry_base['indexed_content'] = $content_string_or_error;

        $temp_file_result = $this->create_temp_file_from_string($content_string_or_error, 'post-' . $post_id . '-vs-' . $vector_store_id . '-'); // Call base method
        if (is_wp_error($temp_file_result)) {
            $error_msg = 'Temp file error: ' . $temp_file_result->get_error_message();
            return $return_error($error_msg);
        }

        try {
            $upload_result = $strategy->upload_file_for_vector_store($temp_file_result, basename($temp_file_result), 'user_data');
        } finally {
            wp_delete_file($temp_file_result);
        }

        if (is_wp_error($upload_result) || !isset($upload_result['id'])) {
            $err_msg = is_wp_error($upload_result) ? $upload_result->get_error_message() : 'Missing file ID in response.';
            return $return_error('Upload error: ' . $err_msg);
        }
        $uploaded_file_id = $upload_result['id'];

        $batch_result = $this->vector_store_manager->upsert_vectors('OpenAI', $vector_store_id, ['file_ids' => [$uploaded_file_id]], $openai_config);
        if (is_wp_error($batch_result)) {
            $strategy->delete_openai_file_object($uploaded_file_id);
            $error_msg = 'Batch add error: ' . $batch_result->get_error_message();
            return $return_error($error_msg, $uploaded_file_id);
        }
        $batch_id = (string) ($batch_result['id'] ?? '');
        if ($batch_id === '') {
            $this->delete_existing_openai_files([$uploaded_file_id], $vector_store_id, $openai_config, $strategy);
            return $return_error(__('OpenAI did not return an indexing batch ID. The previous source was kept.', 'gpt3-ai-content-generator'));
        }
        $job = array_merge($log_entry_base, [
            'status' => 'processing',
            'message' => __('OpenAI is processing this source. Any previous version remains available until the replacement is ready.', 'gpt3-ai-content-generator'),
            'file_id' => $uploaded_file_id,
            'batch_id' => $batch_id,
        ]);
        $job_id = $this->log_event($job);
        if ($job_id < 1) {
            $this->delete_existing_openai_files([$uploaded_file_id], $vector_store_id, $openai_config, $strategy);
            return ['status' => 'error', 'message' => __('Could not save indexing progress. The previous source was kept.', 'gpt3-ai-content-generator')];
        }
        self::schedule_reconciliation();
        $job['id'] = $job_id;
        return $this->pending_result($job);
    }

    public static function register_hooks(): void
    {
        if (self::$hooks_registered) {
            return;
        }
        self::$hooks_registered = true;
        add_action(self::RECONCILE_HOOK, [self::class, 'reconcile_pending_jobs']);
        add_action('aipkit_deactivate_background_workers', [self::class, 'deactivate']);
        // Resume durable jobs after a plugin reactivation, without a permanent cron job.
        add_action('admin_init', [self::class, 'resume_pending_jobs']);
        add_filter('aipkit_automation_runner_additional_queue_results', [self::class, 'run_from_automation'], 20);
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::RECONCILE_HOOK);
        delete_option(self::RECONCILE_CURSOR);
    }

    public static function resume_pending_jobs(): void
    {
        if (!wp_next_scheduled(self::RECONCILE_HOOK) && (new self())->pending_jobs()) {
            self::schedule_reconciliation();
        }
    }

    private static function schedule_reconciliation(): void
    {
        if (!wp_next_scheduled(self::RECONCILE_HOOK)) {
            wp_schedule_single_event(time() + 30, self::RECONCILE_HOOK);
        }
    }

    public static function reconcile_pending_jobs(): void
    {
        $processor = new self();
        $jobs = $processor->pending_jobs((int) get_option(self::RECONCILE_CURSOR, 0));
        if (!$jobs) {
            $jobs = $processor->pending_jobs();
        }
        if (!$jobs) {
            delete_option(self::RECONCILE_CURSOR);
            return;
        }
        // Schedule before remote calls so a killed PHP worker does not strand jobs.
        self::schedule_reconciliation();
        $started = microtime(true);
        foreach ($jobs as $job) {
            // Advance before remote work so one stalled source cannot starve later jobs.
            update_option(self::RECONCILE_CURSOR, (int) $job['id'], false);
            try {
                $processor->refresh_job((int) $job['id']);
            } catch (\Throwable $error) {
                // The durable row and scheduled follow-up retain recovery state.
                break;
            }
            if (microtime(true) - $started >= 20) {
                break;
            }
        }
    }

    public static function run_from_automation(array $results): array
    {
        self::reconcile_pending_jobs();
        $results['openai_indexing'] = ['has_remaining_items' => !empty((new self())->pending_jobs())];
        return $results;
    }

    /** Reconcile a durable source row; safe to call from cron or admin polling. */
    public function refresh_job(int $job_id, ?array $known_batch = null): void
    {
        $job = $this->get_job($job_id);
        if (!$job || $job['status'] !== 'processing') {
            return;
        }
        $lock_name = self::lock_name((int) $job['post_id'], (string) $job['vector_store_id']);
        $token = self::acquire_lock($lock_name);
        if ($token === '') {
            return;
        }
        try {
            $job = $this->get_job($job_id);
            if (!$job || $job['status'] !== 'processing') {
                return;
            }
            $config = $this->config_handler ? $this->config_handler->get_config() : null;
            $strategy = AIPKit_Vector_Provider_Strategy_Factory::get_strategy('OpenAI');
            if (!$config || is_wp_error($config) || is_wp_error($strategy)) {
                return;
            }
            $connected = $strategy->connect($config);
            if (is_wp_error($connected) || $connected === false) {
                return;
            }
            $meta_key = '_aipkit_openai_file_id_for_vs_' . sanitize_key($job['vector_store_id']);
            $cleanup_key = self::cleanup_meta_key((int) $job['id']);
            $old_ids = get_post_meta((int) $job['post_id'], $cleanup_key, true);
            if (!is_array($old_ids)) {
                $batch = $known_batch ?: $strategy->retrieve_file_batch($job['vector_store_id'], $job['batch_id']);
                if (is_wp_error($batch)) {
                    // A temporary provider error must never delete the working source.
                    return;
                }
                $status = (string) ($batch['status'] ?? '');
                $counts = (array) ($batch['file_counts'] ?? []);
                $ready = $status === 'completed' && (int) ($counts['completed'] ?? 0) === 1
                    && (int) ($counts['failed'] ?? 0) === 0 && (int) ($counts['cancelled'] ?? 0) === 0
                    && (int) ($counts['in_progress'] ?? 0) === 0;
                if (!$ready) {
                    $created = strtotime($job['timestamp'] . ' UTC');
                    if (in_array($status, ['failed', 'cancelled', 'completed'], true)
                        || ($created && time() - $created > DAY_IN_SECONDS)) {
                        $cleanup = $this->delete_existing_openai_files([$job['file_id']], $job['vector_store_id'], $config, $strategy);
                        if (is_wp_error($cleanup)) {
                            return;
                        }
                        $this->finish_job($job, 'failed', __('OpenAI could not finish indexing. The previous source was kept; you can retry this update.', 'gpt3-ai-content-generator'));
                    }
                    return;
                }
                $old_ids = array_values(array_diff(
                    $this->get_existing_file_ids_for_post((int) $job['post_id'], $job['vector_store_id'], $meta_key),
                    [$job['file_id']]
                ));
                // Legacy sources may have only a meta pointer and no source log.
                // Persist confirmed readiness and cleanup identities before replacing that pointer.
                update_post_meta((int) $job['post_id'], $cleanup_key, $old_ids);
                if (get_post_meta((int) $job['post_id'], $cleanup_key, true) !== $old_ids) {
                    return;
                }
            }
            // Persist the ready replacement before touching the previous files.
            update_post_meta((int) $job['post_id'], $meta_key, $job['file_id']);
            if (get_post_meta((int) $job['post_id'], $meta_key, true) !== $job['file_id']) {
                return;
            }
            update_post_meta((int) $job['post_id'], '_aipkit_indexed_to_vs_' . sanitize_key($job['vector_store_id']), '1');
            $cleanup_ids = array_slice($old_ids, 0, 5);
            $cleanup = $this->delete_existing_openai_files($cleanup_ids, $job['vector_store_id'], $config, $strategy);
            if (is_wp_error($cleanup)) {
                // Keep the durable job pending so cleanup is retried without a new upload.
                return;
            }
            $this->delete_existing_log_entries_for_post((int) $job['post_id'], $job['vector_store_id'], $cleanup_ids);
            if (count($old_ids) > count($cleanup_ids)) {
                update_post_meta((int) $job['post_id'], $cleanup_key, array_slice($old_ids, count($cleanup_ids)));
                return;
            }
            $this->finish_job($job, 'indexed', __('OpenAI indexing completed.', 'gpt3-ai-content-generator'));
        } finally {
            AIPKit_Option_Lock::release($lock_name, $token);
        }
    }

    private static function lock_name(int $post_id, string $store_id): string
    {
        return 'aipkit_openai_index_lock_' . md5($post_id . '|' . $store_id);
    }

    private static function cleanup_meta_key(int $job_id): string
    {
        return '_aipkit_openai_replacement_cleanup_' . $job_id;
    }

    /** Use the authenticated admin batch poll to finish the same persisted job. */
    public function refresh_batch(string $store_id, string $batch_id, array $batch): ?array
    {
        global $wpdb;
        $table = self::get_validated_table_identifier($this->data_source_table_name);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Exact job lookup using validated table and authenticated provider batch identifiers.
        $job_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE provider = %s AND status = %s AND vector_store_id = %s AND batch_id = %s AND post_id > 0 LIMIT 1", 'OpenAI', 'processing', $store_id, $batch_id));
        if ($job_id > 0) {
            $this->refresh_job($job_id, $batch);
            return $this->get_job($job_id);
        }
        return null;
    }

    private static function acquire_lock(string $name): string
    {
        require_once WPAICG_PLUGIN_DIR . 'classes/automations/lock.php';
        return AIPKit_Option_Lock::acquire($name, 15 * MINUTE_IN_SECONDS);
    }

    private function pending_result(array $job): array
    {
        return ['status' => 'success', 'processing' => true, 'job_id' => (int) $job['id'], 'message' => __('Source submitted; OpenAI indexing is still processing.', 'gpt3-ai-content-generator'), 'file_id' => $job['file_id'], 'batch_id' => $job['batch_id']];
    }

    /** @return array<int,array<string,mixed>> */
    private function pending_jobs(int $after_id = 0): array
    {
        global $wpdb;
        $table = self::get_validated_table_identifier($this->data_source_table_name);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bounded query against a validated plugin-owned identifier for WordPress 6.0 compatibility.
        return $wpdb->get_results($wpdb->prepare("SELECT id FROM {$table} WHERE provider = %s AND status = %s AND post_id > 0 AND id > %d ORDER BY id ASC LIMIT 5", 'OpenAI', 'processing', $after_id), ARRAY_A) ?: [];
    }

    private function find_pending_job(int $post_id, string $store_id): ?array
    {
        global $wpdb;
        $table = self::get_validated_table_identifier($this->data_source_table_name);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Exact pending-source query against a validated plugin-owned identifier.
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE provider = %s AND status = %s AND post_id = %d AND vector_store_id = %s ORDER BY id DESC LIMIT 1", 'OpenAI', 'processing', $post_id, $store_id), ARRAY_A) ?: null;
    }

    private function get_job(int $job_id): ?array
    {
        global $wpdb;
        $table = self::get_validated_table_identifier($this->data_source_table_name);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Exact durable-job read against a validated plugin-owned identifier.
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND provider = %s AND post_id > 0", $job_id, 'OpenAI'), ARRAY_A) ?: null;
    }

    private function finish_job(array $job, string $status, string $message): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Update a durable plugin-owned source job only while it is processing.
        $updated = $wpdb->update($this->data_source_table_name, ['status' => $status, 'message' => $message], ['id' => (int) $job['id'], 'status' => 'processing']);
        if ($updated !== 1) {
            return;
        }
        $job['status'] = $status;
        $job['message'] = $message;
        delete_post_meta((int) $job['post_id'], self::cleanup_meta_key((int) $job['id']));
        do_action('aipkit_vector_source_log_changed', (int) $job['id'], $job, '');
        $this->emit_source_indexed_event((int) $job['id'], $job);
    }

    /**
     * Gets every known OpenAI file ID for a post/store pair.
     *
     * Meta is the fast path, while the data-source table lets incremental indexing
     * replace files that were created by older or alternate indexing paths.
     *
     * @param int $post_id WordPress post ID.
     * @param string $vector_store_id OpenAI vector store ID.
     * @param string $meta_key Post meta key that stores the current OpenAI file ID.
     * @return string[]
     */
    private function get_existing_file_ids_for_post(int $post_id, string $vector_store_id, string $meta_key): array
    {
        global $wpdb;

        $file_ids = [];
        $meta_file_id = get_post_meta($post_id, $meta_key, true);
        if (is_string($meta_file_id) && trim($meta_file_id) !== '') {
            $file_ids[] = trim($meta_file_id);
        }

        $data_source_table_identifier = self::get_validated_table_identifier($this->data_source_table_name);
        if ($data_source_table_identifier === '') {
            return array_values(array_unique($file_ids));
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table identifier is plugin-owned, validated, and backticked before interpolation for pre-WP-6.2 compatibility.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table lookup for replacing prior OpenAI source files; table identifier is plugin-owned, validated, and backticked above.
        $logged_file_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT file_id
                 FROM {$data_source_table_identifier}
                 WHERE provider = %s
                   AND vector_store_id = %s
                   AND post_id = %d
                   AND status = %s
                   AND file_id IS NOT NULL
                   AND file_id <> %s
                 ORDER BY id DESC",
                'OpenAI',
                $vector_store_id,
                $post_id,
                'indexed',
                ''
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if (is_array($logged_file_ids)) {
            foreach ($logged_file_ids as $logged_file_id) {
                if (is_string($logged_file_id) && trim($logged_file_id) !== '') {
                    $file_ids[] = trim($logged_file_id);
                }
            }
        }

        return array_values(array_unique($file_ids));
    }

    /**
     * Detaches and deletes files only after replacement readiness or a failed new upload.
     *
     * @param string[] $file_ids OpenAI file IDs.
     * @param string $vector_store_id OpenAI vector store ID.
     * @param array $openai_config Provider config.
     * @param object $strategy Connected OpenAI vector provider strategy.
     * @return true|WP_Error
     */
    private function delete_existing_openai_files(array $file_ids, string $vector_store_id, array $openai_config, object $strategy)
    {
        $cleanup_errors = [];

        foreach ($file_ids as $file_id) {
            if (!is_string($file_id) || trim($file_id) === '') {
                continue;
            }

            $file_id = trim($file_id);
            $detached = false;
            $deleted = false;
            $detach_result = $this->vector_store_manager->delete_vectors('OpenAI', $vector_store_id, [$file_id], $openai_config);
            if ($detach_result === true || $this->is_not_found_error($detach_result)) {
                $detached = true;
            }

            $delete_file_result = method_exists($strategy, 'delete_openai_file_object')
                ? $strategy->delete_openai_file_object($file_id)
                : new WP_Error('openai_delete_file_unavailable', __('OpenAI file delete method is unavailable.', 'gpt3-ai-content-generator'));

            if ($delete_file_result === true || $this->is_not_found_error($delete_file_result)) {
                $deleted = true;
            }

            if (!$detached || !$deleted) {
                $error_messages = [];
                if (is_wp_error($detach_result)) {
                    $error_messages[] = $detach_result->get_error_message();
                }
                if (is_wp_error($delete_file_result)) {
                    $error_messages[] = $delete_file_result->get_error_message();
                }
                $cleanup_errors[] = $file_id . ': ' . implode(' | ', array_unique($error_messages));
            }
        }

        if (!empty($cleanup_errors)) {
            return new WP_Error(
                'openai_existing_file_cleanup_failed',
                implode('; ', $cleanup_errors)
            );
        }

        return true;
    }

    /**
     * Removes superseded local source rows so the source table reflects current files.
     *
     * @param int $post_id WordPress post ID.
     * @param string $vector_store_id OpenAI vector store ID.
     * @param string[] $file_ids Replaced OpenAI file IDs.
     */
    private function delete_existing_log_entries_for_post(int $post_id, string $vector_store_id, array $file_ids): void
    {
        global $wpdb;

        $file_ids = array_values(array_filter(array_map('trim', $file_ids)));
        if (empty($file_ids)) {
            return;
        }

        $data_source_table_identifier = self::get_validated_table_identifier($this->data_source_table_name);
        if ($data_source_table_identifier === '') {
            return;
        }

        foreach ($file_ids as $file_id) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table identifier is plugin-owned, validated, and backticked before interpolation for pre-WP-6.2 compatibility.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table cleanup for superseded OpenAI source rows; table identifier is plugin-owned, validated, and backticked above.
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$data_source_table_identifier}
                 WHERE provider = %s
                   AND vector_store_id = %s
                   AND post_id = %d
                   AND status = %s
                   AND file_id = %s",
                    'OpenAI',
                    $vector_store_id,
                    $post_id,
                    'indexed',
                    $file_id
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
    }

    /**
     * Treat already-removed remote files as a successful cleanup.
     *
     * @param mixed $result Result from an OpenAI cleanup call.
     * @return bool
     */
    private function is_not_found_error($result): bool
    {
        if (!is_wp_error($result)) {
            return false;
        }
        $data = $result->get_error_data();
        return (is_array($data) && (int) ($data['status'] ?? 0) === 404)
            || strpos($result->get_error_message(), '(404)') !== false;
    }
}
