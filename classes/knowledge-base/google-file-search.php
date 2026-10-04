<?php

namespace WPAICG\Vector\GoogleFileSearch;

use WPAICG\AIPKit_Providers;
use WPAICG\Core\AIPKit_Event_Webhooks;
use WPAICG\Core\Providers\Google\FileSearch\GoogleFileSearchClient;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Starts and reconciles asynchronous Google File Search ingestion jobs.
 *
 * The custom data-source row is the durable job record. This lets admin
 * requests poll Google without holding a PHP worker open while Google chunks
 * and indexes a document.
 */
final class GoogleFileSearchIngestionService
{
    private const PROVIDER = 'Google';
    private const PENDING_FILE_PREFIX = 'aipkit_google_pending_';
    public const RECONCILE_HOOK = 'aipkit_google_file_search_reconcile_jobs';
    private const RECONCILE_BATCH_SIZE = 20;
    private const MAX_PROCESSING_AGE = 86400;
    private static bool $hooks_registered = false;

    /** @var \wpdb */
    private $wpdb;

    /** @var string */
    private $table_name;

    /** @var GoogleFileSearchClient */
    private $client;

    public function __construct(?GoogleFileSearchClient $client = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'aipkit_vector_data_source';
        $this->client = $client ?: new GoogleFileSearchClient();
    }

    public static function register_hooks(): void
    {
        if (self::$hooks_registered) {
            return;
        }
        self::$hooks_registered = true;
        add_action(self::RECONCILE_HOOK, [__CLASS__, 'reconcile_pending_jobs']);
    }

    /**
     * @param array<string, mixed> $log_data
     * @param array<string, mixed> $ingestion_options
     * @return array<string, mixed>|WP_Error
     */
    public function start(
        string $store_name,
        string $store_display_name,
        string $contents,
        string $display_name,
        string $mime_type,
        array $log_data = [],
        array $ingestion_options = []
    ) {
        $connection = $this->get_connection();
        if (is_wp_error($connection)) {
            return $connection;
        }

        $store_name = sanitize_text_field($store_name);
        $display_name = sanitize_file_name($display_name);
        if ($store_name === '' || $display_name === '') {
            return new WP_Error(
                'google_file_search_missing_ingestion_target',
                __('A Google store and document name are required.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }

        $source_type = isset($log_data['source_type'])
            ? sanitize_key((string) $log_data['source_type'])
            : 'text_entry_global_form';
        $extraction_fingerprint = self::sanitize_extraction_fingerprint(
            $log_data['extraction_fingerprint'] ?? ''
        );
        $row = $this->prepare_log_row($store_name, $store_display_name, $log_data, $source_type);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Durable job state is stored in the plugin's custom table.
        $inserted = $this->wpdb->insert($this->table_name, $row);
        if ($inserted === false) {
            return new WP_Error(
                'google_file_search_job_log_failed',
                __('Could not create the Google File Search ingestion job.', 'gpt3-ai-content-generator'),
                ['status' => 500]
            );
        }

        $job_id = (int) $this->wpdb->insert_id;
        do_action('aipkit_vector_source_log_changed', $job_id, $row, $extraction_fingerprint);
        $pending_file_id = self::PENDING_FILE_PREFIX . $job_id;
        $this->update_job($job_id, ['file_id' => $pending_file_id]);

        $display_name = $this->make_unique_display_name($display_name, $job_id);
        $custom_metadata = isset($ingestion_options['custom_metadata']) && is_array($ingestion_options['custom_metadata'])
            ? $ingestion_options['custom_metadata']
            : [];
        $custom_metadata['aipkit_log_id'] = $job_id;
        $custom_metadata['source_type'] = $source_type;
        if (!empty($row['post_id'])) {
            $custom_metadata['post_id'] = (int) $row['post_id'];
        }
        $ingestion_options['custom_metadata'] = $custom_metadata;

        $operation = $this->client->upload_bytes(
            $connection,
            $store_name,
            $contents,
            $display_name,
            $mime_type,
            $ingestion_options
        );
        if (is_wp_error($operation)) {
            $this->mark_failed($job_id, $operation->get_error_message());
            return $operation;
        }

        $operation_name = (string) ($operation['name'] ?? '');
        if ($operation_name === '') {
            $error = new WP_Error(
                'google_file_search_missing_operation_name',
                __('Google did not return an ingestion operation name.', 'gpt3-ai-content-generator'),
                ['status' => 502]
            );
            $this->mark_failed($job_id, $error->get_error_message());
            return $error;
        }

        $this->update_job($job_id, ['batch_id' => $operation_name]);

        if (!empty($operation['done'])) {
            $status = $this->refresh($job_id, $operation);
            if (!is_wp_error($status)) {
                return $status;
            }
        }

        self::schedule_reconciliation();
        return [
            'job_id' => $job_id,
            'operation_name' => $operation_name,
            'status' => 'processing',
            'done' => false,
        ];
    }

    /**
     * @param array<string, mixed>|null $known_operation
     * @return array<string, mixed>|WP_Error
     */
    public function refresh(int $job_id, ?array $known_operation = null)
    {
        $job = $this->get_job($job_id);
        if (is_wp_error($job)) {
            return $job;
        }

        $status = sanitize_key((string) ($job['status'] ?? ''));
        if ($status === 'indexed') {
            return $this->status_payload($job, true);
        }
        if ($status === 'failed') {
            return $this->status_payload($job, true);
        }

        $operation_name = (string) ($job['batch_id'] ?? '');
        if ($operation_name === '') {
            return new WP_Error(
                'google_file_search_job_operation_missing',
                __('The Google File Search ingestion job has no operation name.', 'gpt3-ai-content-generator'),
                ['status' => 409]
            );
        }

        $connection = $this->get_connection();
        if (is_wp_error($connection)) {
            return $connection;
        }
        $operation = $known_operation ?: $this->client->get_operation(
            $connection,
            (string) $job['vector_store_id'],
            $operation_name
        );
        if (is_wp_error($operation)) {
            $error_data = $operation->get_error_data();
            if (is_array($error_data) && !empty($error_data['operation_failed'])) {
                $this->mark_failed($job_id, $operation->get_error_message());
                $job['status'] = 'failed';
                $job['message'] = $operation->get_error_message();
                return $this->status_payload($job, true);
            }
            return $operation;
        }
        if (empty($operation['done'])) {
            return $this->status_payload($job, false);
        }

        $document = $this->resolve_document($connection, $job, $operation);
        if (is_wp_error($document)) {
            return $document;
        }
        if ($document === null) {
            return $this->status_payload($job, false);
        }

        $document_state = sanitize_key((string) ($document['state'] ?? ''));
        if ($document_state === 'state_failed') {
            $message = __('Google could not index this File Search document.', 'gpt3-ai-content-generator');
            $this->mark_failed($job_id, $message);
            $job['status'] = 'failed';
            $job['message'] = $message;
            return $this->status_payload($job, true);
        }
        if ($document_state !== '' && $document_state !== 'state_active') {
            return $this->status_payload($job, false);
        }

        $document_name = (string) ($document['resource_name'] ?? $document['id'] ?? '');
        if ($document_name === '') {
            return $this->status_payload($job, false);
        }

        $completion_message = trim((string) ($job['message'] ?? ''));
        if (stripos($completion_message, 'indexing completed') === false) {
            $completion_message .= ($completion_message !== '' ? ' ' : '')
                . __('Google File Search indexing completed.', 'gpt3-ai-content-generator');
        }
        $this->update_job(
            $job_id,
            [
                'status' => 'indexed',
                'message' => $completion_message,
                'file_id' => $document_name,
            ]
        );
        $job['status'] = 'indexed';
        $job['message'] = $completion_message;
        $job['file_id'] = $document_name;
        $this->emit_indexed_event($job);

        return $this->status_payload($job, true);
    }

    public static function is_pending_file_id(string $file_id): bool
    {
        return strpos($file_id, self::PENDING_FILE_PREFIX) === 0;
    }

    /**
     * Reconciles asynchronous jobs without requiring an administrator to keep
     * the Sources screen open. Transient provider errors leave jobs pending for
     * the next run; abandoned jobs are failed after one day.
     */
    public static function reconcile_pending_jobs(): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aipkit_vector_data_source';
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table_name)) {
            return;
        }
        $table_identifier = '`' . $table_name . '`';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bounded reconciliation queries against a validated and backticked plugin-owned table; identifier placeholders are unavailable on the minimum supported WordPress version.
        $jobs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, timestamp FROM {$table_identifier} WHERE provider = %s AND status = %s ORDER BY id ASC LIMIT %d",
                self::PROVIDER,
                'processing',
                self::RECONCILE_BATCH_SIZE
            ),
            ARRAY_A
        );
        if (empty($jobs)) {
            return;
        }

        $service = new self();
        $now = time();
        foreach ($jobs as $job) {
            $job_id = absint($job['id'] ?? 0);
            if ($job_id < 1) {
                continue;
            }
            $created_at = strtotime((string) ($job['timestamp'] ?? '') . ' UTC');
            if ($created_at && ($now - $created_at) > self::MAX_PROCESSING_AGE) {
                $service->mark_failed(
                    $job_id,
                    __('Google File Search indexing did not finish within one day.', 'gpt3-ai-content-generator')
                );
                continue;
            }
            $service->refresh($job_id);
        }

        $has_pending_jobs = (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM {$table_identifier} WHERE provider = %s AND status = %s LIMIT 1",
                self::PROVIDER,
                'processing'
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ($has_pending_jobs) {
            self::schedule_reconciliation(60);
        }
    }

    private static function schedule_reconciliation(int $delay = 30): void
    {
        if (!wp_next_scheduled(self::RECONCILE_HOOK)) {
            wp_schedule_single_event(time() + max(10, $delay), self::RECONCILE_HOOK);
        }
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    private function get_connection()
    {
        $connection = AIPKit_Providers::get_provider_data('Google');
        if (empty($connection['api_key'])) {
            return new WP_Error(
                'google_file_search_missing_api_key',
                __('Add a Google API key in AI settings before using File Search.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }
        $connection['api_version'] = 'v1beta';
        return $connection;
    }

    /**
     * @param array<string, mixed> $log_data
     * @return array<string, mixed>
     */
    private function prepare_log_row(
        string $store_name,
        string $store_display_name,
        array $log_data,
        string $source_type
    ): array {
        $indexed_content = isset($log_data['indexed_content'])
            ? (string) $log_data['indexed_content']
            : null;
        if (
            $indexed_content !== null
            && !in_array($source_type, ['text_entry_global_form', 'chatbot_training_text', 'chatbot_training_qa'], true)
            && mb_strlen($indexed_content) > 1000
        ) {
            $indexed_content = mb_substr($indexed_content, 0, 997) . '...';
        }

        return [
            'user_id' => get_current_user_id(),
            'timestamp' => current_time('mysql', true),
            'provider' => self::PROVIDER,
            'vector_store_id' => $store_name,
            'vector_store_name' => $store_display_name !== '' ? $store_display_name : $store_name,
            'post_id' => !empty($log_data['post_id']) ? absint($log_data['post_id']) : null,
            'post_title' => isset($log_data['post_title']) ? sanitize_text_field((string) $log_data['post_title']) : null,
            'status' => 'processing',
            'message' => isset($log_data['message'])
                ? sanitize_text_field((string) $log_data['message'])
                : __('Content submitted to Google File Search for indexing.', 'gpt3-ai-content-generator'),
            'indexed_content' => $indexed_content,
            'file_id' => self::PENDING_FILE_PREFIX,
            'batch_id' => null,
            'embedding_provider' => null,
            'embedding_model' => null,
        ];
    }

    private function make_unique_display_name(string $display_name, int $job_id): string
    {
        $extension = pathinfo($display_name, PATHINFO_EXTENSION);
        $basename = pathinfo($display_name, PATHINFO_FILENAME);
        $suffix = '-aipkit-' . $job_id;
        $max_basename_length = max(1, 500 - strlen($suffix) - strlen($extension));
        $basename = substr($basename !== '' ? $basename : 'document', 0, $max_basename_length);
        return $basename . $suffix . ($extension !== '' ? '.' . $extension : '');
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    private function get_job(int $job_id)
    {
        if ($job_id < 1) {
            return new WP_Error(
                'google_file_search_invalid_job',
                __('A valid Google File Search job is required.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }
        $table_identifier = $this->validated_table_identifier();
        if ($table_identifier === '') {
            return new WP_Error('google_file_search_invalid_log_table', __('The knowledge base log is unavailable.', 'gpt3-ai-content-generator'), ['status' => 500]);
        }
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned table identifier is validated and backticked; identifier placeholders are unavailable on the minimum supported WordPress version.
        $job = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT id, user_id, provider, vector_store_id, vector_store_name, post_id, post_title, status, message, indexed_content, file_id, batch_id FROM {$table_identifier} WHERE id = %d AND provider = %s LIMIT 1",
                $job_id,
                self::PROVIDER
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if (!is_array($job)) {
            return new WP_Error(
                'google_file_search_job_not_found',
                __('The Google File Search ingestion job was not found.', 'gpt3-ai-content-generator'),
                ['status' => 404]
            );
        }
        return $job;
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $job
     * @param array<string, mixed> $operation
     * @return array<string, mixed>|null|WP_Error
     */
    private function resolve_document(array $connection, array $job, array $operation)
    {
        $store_name = (string) $job['vector_store_id'];
        $document_name = $this->find_document_name($operation['response'] ?? [], $store_name);
        if ($document_name !== '') {
            $document = $this->client->get_document($connection, $store_name, $document_name);
            if (!is_wp_error($document)) {
                return $document;
            }
            $error_data = $document->get_error_data();
            $status = is_array($error_data) ? (int) ($error_data['status'] ?? 0) : 0;
            if ($status !== 404) {
                return $document;
            }
        }

        $documents = $this->client->list_all_documents($connection, $store_name);
        if (is_wp_error($documents)) {
            return $documents;
        }
        foreach ($documents as $document) {
            if ($this->document_matches_job($document, (int) $job['id'])) {
                return $document;
            }
        }
        return null;
    }

    /**
     * @param mixed $value
     */
    private function find_document_name($value, string $store_name): string
    {
        if (is_string($value) && strpos($value, $store_name . '/documents/') === 0) {
            return $value;
        }
        if (!is_array($value)) {
            return '';
        }
        foreach ($value as $child) {
            $match = $this->find_document_name($child, $store_name);
            if ($match !== '') {
                return $match;
            }
        }
        return '';
    }

    /**
     * @param array<string, mixed> $document
     */
    private function document_matches_job(array $document, int $job_id): bool
    {
        foreach ((array) ($document['custom_metadata'] ?? []) as $entry) {
            if (!is_array($entry) || (string) ($entry['key'] ?? '') !== 'aipkit_log_id') {
                continue;
            }
            foreach (['numericValue', 'stringValue', 'numeric_value', 'string_value'] as $value_key) {
                if (isset($entry[$value_key]) && (string) $entry[$value_key] === (string) $job_id) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $updates
     */
    private function update_job(int $job_id, array $updates): bool
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Durable job state is stored in the plugin's custom table.
        $updated = $this->wpdb->update($this->table_name, $updates, ['id' => $job_id], null, ['%d']) !== false;
        if ($updated) {
            $job = $this->get_job($job_id);
            if (is_array($job)) {
                do_action('aipkit_vector_source_log_changed', $job_id, $job, '');
            }
        }

        return $updated;
    }

    private static function sanitize_extraction_fingerprint($fingerprint): string
    {
        if (!is_scalar($fingerprint)) {
            return '';
        }

        $fingerprint = strtolower(trim((string) $fingerprint));

        return preg_match('/^[a-f0-9]{64}$/', $fingerprint) ? $fingerprint : '';
    }

    private function mark_failed(int $job_id, string $message): void
    {
        $this->update_job(
            $job_id,
            [
                'status' => 'failed',
                'message' => sanitize_text_field($message),
            ]
        );
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function status_payload(array $job, bool $done): array
    {
        return [
            'job_id' => (int) ($job['id'] ?? 0),
            'operation_name' => (string) ($job['batch_id'] ?? ''),
            'status' => sanitize_key((string) ($job['status'] ?? 'processing')),
            'done' => $done,
            'document_name' => self::is_pending_file_id((string) ($job['file_id'] ?? ''))
                ? ''
                : (string) ($job['file_id'] ?? ''),
            'message' => (string) ($job['message'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $job
     */
    private function emit_indexed_event(array $job): void
    {
        if (!class_exists(AIPKit_Event_Webhooks::class)) {
            return;
        }
        $job_id = (int) ($job['id'] ?? 0);
        $post_id = (int) ($job['post_id'] ?? 0);
        AIPKit_Event_Webhooks::emit(
            'kb.source_indexed',
            [
                'source' => [
                    'log_id' => $job_id,
                    'post_id' => $post_id,
                    'post_title' => (string) ($job['post_title'] ?? ''),
                    'post_url' => $post_id > 0 ? get_permalink($post_id) : '',
                ],
                'provider' => self::PROVIDER,
                'store' => [
                    'id' => (string) ($job['vector_store_id'] ?? ''),
                    'name' => (string) ($job['vector_store_name'] ?? ''),
                ],
                'status' => 'indexed',
                'message' => (string) ($job['message'] ?? ''),
                'file_id' => (string) ($job['file_id'] ?? ''),
                'batch_id' => (string) ($job['batch_id'] ?? ''),
                'embedding' => ['provider' => '', 'model' => ''],
            ],
            [
                'module' => 'knowledge_base',
                'origin' => 'google_file_search_ingestion',
                'resource' => [
                    'type' => 'vector_source',
                    'id' => $job_id,
                    'label' => (string) ($job['post_title'] ?? __('KB source', 'gpt3-ai-content-generator')),
                ],
                'meta' => [
                    'provider' => self::PROVIDER,
                    'vector_store_id' => (string) ($job['vector_store_id'] ?? ''),
                    'post_id' => $post_id,
                    'log_status' => 'indexed',
                ],
                'idempotency_key' => sha1('kb.source_indexed|google|' . $job_id),
            ]
        );
    }

    private function validated_table_identifier(): string
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $this->table_name)
            ? '`' . $this->table_name . '`'
            : '';
    }
}
