<?php

namespace WPAICG\Usage;

use WPAICG\Dashboard\Ajax\BaseDashboardAjaxHandler;
use WPAICG\Core\AIPKit_Payload_Sanitizer;
use WPAICG\Chat\Storage\LogStorage;
use WPAICG\Chat\Storage\LogCronManager;
use WPAICG\Chat\Utils\LogConfig;
use WPAICG\Core\Moderation\AIPKit_Global_Security_Settings;
use WPAICG\AIPKit_Role_Manager;
use WPAICG\Stats\AIPKit_Stats;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/** Shared Usage access checks and date presets. */
abstract class AIPKit_Usage_Ajax_Handler extends BaseDashboardAjaxHandler
{
    protected function resolve_stats_days($raw_days): int
    {
        $days = absint($raw_days);
        $allowed = [7, 30, 90];
        if (!in_array($days, $allowed, true)) {
            $days = 30;
        }
        return $days;
    }

    protected function ensure_stats_access(): bool
    {
        $permission_check = $this->check_module_access_permissions('stats', 'aipkit_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return false;
        }

        return true;
    }

    protected function get_stats_post_data(): ?array
    {
        if (!$this->ensure_stats_access()) {
            return null;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in ensure_stats_access().
        return wp_unslash($_POST);
    }
}

/** Manages Usage conversation logs, privacy controls and settings. */
class AIPKit_Log_Ajax_Handler extends AIPKit_Usage_Ajax_Handler
{
    public function ajax_delete_stats_requests()
    {
        $post_data = $this->get_stats_post_data();
        if ($post_data === null) { return; }
        $ids = $post_data['request_ids'] ?? [];
        if (!is_array($ids)) { $ids = []; }
        $deleted = (new AIPKit_Stats())->delete_provider_requests($ids);
        if (is_wp_error($deleted)) { $this->send_wp_error($deleted); return; }
        wp_send_json_success(['deleted' => $deleted]);
    }

    public function ajax_get_stats_requests()
    {
        $post_data = $this->get_stats_post_data();
        if ($post_data === null) {
            return;
        }

        $days = in_array($post_data['days'] ?? 7, [0, '0'], true)
            ? 0 : $this->resolve_stats_days($post_data['days'] ?? 7);
        $per_page = 20;
        $page = isset($post_data['page']) ? absint($post_data['page']) : 1;
        $page = max(1, min(intdiv(PHP_INT_MAX, $per_page), $page));
        $offset = ($page - 1) * $per_page;
        $stats = new AIPKit_Stats();
        $requests = $stats->get_recent_provider_requests($days, $per_page + 1, $offset);
        if (is_wp_error($requests)) {
            $this->send_wp_error($requests);
            return;
        }

        wp_send_json_success([
            'requests' => array_slice($requests, 0, $per_page),
            'hasMore' => count($requests) > $per_page,
            'page' => $page,
        ]);
    }

    private function get_stats_time_range(int $days): array
    {
        $wp_timezone = wp_timezone();
        $end_datetime = new \DateTime('now', $wp_timezone);
        $start_datetime = new \DateTime("-{$days} days", $wp_timezone);
        $start_datetime->setTime(0, 0, 0);

        return [
            'start_ts' => $start_datetime->getTimestamp(),
            'end_ts' => $end_datetime->getTimestamp(),
        ];
    }

    /**
     * Builds the privacy-safe IP blocking state for a conversation detail response.
     * The raw address is never returned to the browser.
     *
     * @param array<string,mixed> $log_row
     * @return array{can_block:bool,is_blocked:bool,reason:string}
     */
    private function build_stats_ip_block_state(array $log_row): array
    {
        $state = [
            'can_block' => false,
            'is_blocked' => false,
            'reason' => '',
        ];

        if (!class_exists(AIPKit_Role_Manager::class) || !AIPKit_Role_Manager::user_can_manage_settings()) {
            $state['reason'] = 'permission_denied';
            return $state;
        }

        if (!class_exists(AIPKit_Global_Security_Settings::class)) {
            $state['reason'] = 'security_settings_unavailable';
            return $state;
        }

        if (AIPKit_Global_Security_Settings::is_ip_anonymization_enabled()) {
            $state['reason'] = 'ip_anonymization_enabled';
            return $state;
        }

        $ip_address = isset($log_row['ip_address']) ? trim((string) $log_row['ip_address']) : '';
        if (filter_var($ip_address, FILTER_VALIDATE_IP) === false) {
            $state['reason'] = 'ip_unavailable';
            return $state;
        }

        $state['can_block'] = true;
        $state['is_blocked'] = AIPKit_Global_Security_Settings::is_ip_blocked($ip_address);
        return $state;
    }

    public function ajax_get_stats_logs()
    {
        $post_data = $this->get_stats_post_data();
        if ($post_data === null) {
            return;
        }
        $days = $this->resolve_stats_days($post_data['days'] ?? 30);
        $range = $this->get_stats_time_range($days);

        $page = isset($post_data['page']) ? absint($post_data['page']) : 1;
        $per_page = isset($post_data['per_page']) ? absint($post_data['per_page']) : 20;
        if ($page < 1) {
            $page = 1;
        }
        if ($per_page < 1) {
            $per_page = 20;
        }
        if ($per_page > 100) {
            $per_page = 100;
        }
        $offset = ($page - 1) * $per_page;

        $filters = [
            'start_ts' => $range['start_ts'],
            'end_ts' => $range['end_ts'],
        ];

        $bot_id_raw = isset($post_data['bot_id']) ? sanitize_text_field($post_data['bot_id']) : '';
        if ($bot_id_raw !== '') {
            $filters['bot_id'] = $bot_id_raw;
        }

        $module = isset($post_data['module']) ? sanitize_key($post_data['module']) : '';
        if ($module !== '') {
            $filters['module'] = $module;
        }

        $search = isset($post_data['search']) ? sanitize_text_field($post_data['search']) : '';
        if ($search !== '') {
            $filters['search'] = $search;
        }

        if (!class_exists(LogStorage::class)) {
            $this->send_wp_error(new WP_Error('missing_log_storage', __('Log storage is unavailable.', 'gpt3-ai-content-generator')));
            return;
        }

        $log_storage = new LogStorage();
        $logs = $log_storage->get_logs($filters, $per_page, $offset);
        $total_logs = $log_storage->count_logs($filters);
        $total_pages = $per_page > 0 ? (int) ceil($total_logs / $per_page) : 1;

        wp_send_json_success([
            'logs' => $logs ?: [],
            'pagination' => [
                'total_logs' => (int) $total_logs,
                'total_pages' => $total_pages,
                'current_page' => $page,
                'per_page' => $per_page,
            ],
        ]);
    }

    public function ajax_get_stats_log_detail()
    {
        $post_data = $this->get_stats_post_data();
        if ($post_data === null) {
            return;
        }
        $log_id = isset($post_data['log_id']) ? absint($post_data['log_id']) : 0;
        if (!$log_id) {
            $this->send_wp_error(new WP_Error('missing_log_id', __('Log ID is required.', 'gpt3-ai-content-generator')));
            return;
        }

        if (!class_exists(LogStorage::class)) {
            $this->send_wp_error(new WP_Error('missing_log_storage', __('Log storage is unavailable.', 'gpt3-ai-content-generator')));
            return;
        }

        $log_storage = new LogStorage();
        $log_row = $log_storage->get_log_by_id($log_id);
        if (!$log_row) {
            $this->send_wp_error(new WP_Error('log_not_found', __('Log entry not found.', 'gpt3-ai-content-generator')));
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'aipkit_chat_logs';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Chat log lookup by primary key on a plugin-owned custom table.
        $messages_json = $wpdb->get_var($wpdb->prepare("SELECT messages FROM {$table_name} WHERE id = %d", $log_id));

        $messages = [];
        if (!empty($messages_json)) {
            $conversation_data = json_decode($messages_json, true);
            if (is_array($conversation_data) && isset($conversation_data['messages']) && is_array($conversation_data['messages'])) {
                $messages = $conversation_data['messages'];
            } elseif (is_array($conversation_data)) {
                $messages = $conversation_data;
            }
        }

        $messages = $this->sanitize_stats_message_payloads($messages);
        $messages = $this->hydrate_stats_vector_score_chunks($messages);
        $log_row['messages'] = $messages;
        $log_row['message_count'] = $log_row['message_count'] ?? count($messages);
        $log_row['ip_block'] = $this->build_stats_ip_block_state($log_row);
        unset($log_row['ip_address']);

        wp_send_json_success($log_row);
    }

    /**
     * Blocks or unblocks the IP stored on a selected conversation log.
     */
    public function ajax_set_stats_ip_block()
    {
        $post_data = $this->get_stats_post_data();
        if ($post_data === null) {
            return;
        }

        if (!class_exists(AIPKit_Role_Manager::class) || !AIPKit_Role_Manager::user_can_manage_settings()) {
            $this->send_wp_error(new WP_Error('permission_denied', __('You do not have permission to manage blocked IP addresses.', 'gpt3-ai-content-generator')));
            return;
        }

        if (!class_exists(AIPKit_Global_Security_Settings::class)) {
            $this->send_wp_error(new WP_Error('security_settings_unavailable', __('Security settings are unavailable.', 'gpt3-ai-content-generator')));
            return;
        }

        if (AIPKit_Global_Security_Settings::is_ip_anonymization_enabled()) {
            $this->send_wp_error(new WP_Error('ip_anonymization_enabled', __('IP blocking from conversation logs is unavailable while IP anonymization is enabled.', 'gpt3-ai-content-generator')));
            return;
        }

        $log_id = isset($post_data['log_id']) ? absint($post_data['log_id']) : 0;
        $block_action = isset($post_data['block_action']) ? sanitize_key($post_data['block_action']) : '';
        if (!$log_id || !in_array($block_action, ['block', 'unblock'], true)) {
            $this->send_wp_error(new WP_Error('invalid_ip_block_request', __('A valid conversation and action are required.', 'gpt3-ai-content-generator')));
            return;
        }

        if (!class_exists(LogStorage::class)) {
            $this->send_wp_error(new WP_Error('missing_log_storage', __('Log storage is unavailable.', 'gpt3-ai-content-generator')));
            return;
        }

        $log_storage = new LogStorage();
        $log_row = $log_storage->get_log_by_id($log_id);
        if (!$log_row) {
            $this->send_wp_error(new WP_Error('log_not_found', __('Log entry not found.', 'gpt3-ai-content-generator')));
            return;
        }

        $ip_address = isset($log_row['ip_address']) ? trim((string) $log_row['ip_address']) : '';
        if (filter_var($ip_address, FILTER_VALIDATE_IP) === false) {
            $this->send_wp_error(new WP_Error('ip_unavailable', __('This conversation does not contain a valid IP address.', 'gpt3-ai-content-generator')));
            return;
        }

        $should_block = $block_action === 'block';
        if (!AIPKit_Global_Security_Settings::set_ip_blocked($ip_address, $should_block)) {
            $this->send_wp_error(new WP_Error('ip_block_update_failed', __('The blocked IP list could not be updated.', 'gpt3-ai-content-generator')));
            return;
        }

        wp_send_json_success([
            'message' => $should_block
                ? __('IP address blocked.', 'gpt3-ai-content-generator')
                : __('IP address unblocked.', 'gpt3-ai-content-generator'),
            'ip_block' => $this->build_stats_ip_block_state($log_row),
        ]);
    }

    /**
     * Redacts media bytes and other sensitive payload fields before Usage details reach the browser.
     *
     * @param array<int,array<string,mixed>> $messages
     * @return array<int,array<string,mixed>>
     */
    private function sanitize_stats_message_payloads(array $messages): array
    {
        foreach ($messages as &$message) {
            if (!is_array($message)) {
                continue;
            }

            foreach (['request_payload', 'response_data'] as $payload_field) {
                if (array_key_exists($payload_field, $message)) {
                    $message[$payload_field] = AIPKit_Payload_Sanitizer::sanitize_payload_if_array($message[$payload_field]);
                }
            }
        }
        unset($message);

        return $messages;
    }

    /**
     * Adds chunk labels to older vector score entries when the matching source log is available.
     *
     * @param array<int,array<string,mixed>> $messages
     * @return array<int,array<string,mixed>>
     */
    private function hydrate_stats_vector_score_chunks(array $messages): array
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'aipkit_vector_data_source';
        $table_identifier = self::validated_table_identifier($table_name);
        if ($table_identifier === '') {
            return $messages;
        }
        $chunk_lookup_cache = [];

        foreach ($messages as &$message) {
            if (!is_array($message)) {
                continue;
            }

            if (empty($message['vector_search_scores']) || !is_array($message['vector_search_scores'])) {
                continue;
            }

            foreach ($message['vector_search_scores'] as &$score_item) {
                if (!is_array($score_item)) {
                    continue;
                }

                $has_chunk_data = !empty($score_item['total_chunks'])
                    && (isset($score_item['chunk_number']) || isset($score_item['chunk_index']));
                if ($has_chunk_data) {
                    continue;
                }

                $provider = isset($score_item['provider']) ? sanitize_text_field((string) $score_item['provider']) : '';
                if (!in_array($provider, ['Pinecone', 'Qdrant'], true)) {
                    continue;
                }

                $result_id = isset($score_item['result_id']) ? sanitize_text_field((string) $score_item['result_id']) : '';
                if ($result_id === '') {
                    continue;
                }

                $store_id = '';
                if ($provider === 'Pinecone') {
                    $store_id = isset($score_item['index_name']) ? sanitize_text_field((string) $score_item['index_name']) : '';
                } elseif ($provider === 'Qdrant') {
                    $store_id = isset($score_item['collection_name']) ? sanitize_text_field((string) $score_item['collection_name']) : '';
                }
                if ($store_id === '') {
                    continue;
                }

                $cache_key = $provider . '|' . $store_id . '|' . $result_id;
                if (!array_key_exists($cache_key, $chunk_lookup_cache)) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read-only lookup by indexed custom-table columns; table identifier is plugin-owned, validated, and backticked above.
                    $chunk_lookup_cache[$cache_key] = $wpdb->get_row($wpdb->prepare(
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table identifier is plugin-owned, validated, and backticked before interpolation for pre-WP-6.2 compatibility.
                        "SELECT message, post_title FROM {$table_identifier} WHERE provider = %s AND vector_store_id = %s AND file_id = %s ORDER BY timestamp DESC LIMIT 1",
                        $provider,
                        $store_id,
                        $result_id
                    ), ARRAY_A);
                }

                $source_row = $chunk_lookup_cache[$cache_key];
                if (empty($source_row) || !is_array($source_row)) {
                    continue;
                }

                $message_text = isset($source_row['message']) ? (string) $source_row['message'] : '';
                if (preg_match('/chunk\s+(\d+)\s*\/\s*(\d+)/i', $message_text, $matches)) {
                    $chunk_number = max(1, (int) $matches[1]);
                    $total_chunks = max(1, (int) $matches[2]);
                    $score_item['chunk_index'] = $chunk_number - 1;
                    $score_item['chunk_number'] = $chunk_number;
                    $score_item['total_chunks'] = $total_chunks;
                }

                if (empty($score_item['file_name']) && !empty($source_row['post_title'])) {
                    $score_item['file_name'] = sanitize_text_field((string) $source_row['post_title']);
                }
            }
            unset($score_item);
        }
        unset($message);

        return $messages;
    }

    public function ajax_export_stats_logs()
    {
        $post_data = $this->get_stats_post_data();
        if ($post_data === null) {
            return;
        }
        $filters = $this->build_stats_log_filters($post_data);
        $limit = isset($post_data['limit']) ? absint($post_data['limit']) : 1000;
        if ($limit < 1) {
            $limit = 1000;
        }
        if ($limit > 5000) {
            $limit = 5000;
        }

        if (!class_exists(LogStorage::class)) {
            $this->send_wp_error(new WP_Error('missing_log_storage', __('Log storage is unavailable.', 'gpt3-ai-content-generator')));
            return;
        }

        $log_storage = new LogStorage();
        $logs = $log_storage->get_logs($filters, $limit, 0);
        $total_logs = $log_storage->count_logs($filters);

        $headers = [
            'log_id' => __('Log ID', 'gpt3-ai-content-generator'),
            'date' => __('Date', 'gpt3-ai-content-generator'),
            'user' => __('User', 'gpt3-ai-content-generator'),
            'source' => __('Source', 'gpt3-ai-content-generator'),
            'module' => __('Module', 'gpt3-ai-content-generator'),
            'messages' => __('Messages', 'gpt3-ai-content-generator'),
            'tokens' => __('Tokens', 'gpt3-ai-content-generator'),
            'preview' => __('Preview', 'gpt3-ai-content-generator'),
            'conversation_uuid' => __('Conversation UUID', 'gpt3-ai-content-generator'),
        ];

        $lines = [];
        $lines[] = implode(',', array_map([$this, 'escape_csv_field'], array_values($headers)));

        if (!empty($logs)) {
            foreach ($logs as $log_row) {
                $date = !empty($log_row['last_message_ts']) ? gmdate('Y-m-d H:i:s', (int) $log_row['last_message_ts']) : '';
                $preview = isset($log_row['last_message_content']) ? (string) $log_row['last_message_content'] : '';
                $line = [
                    $log_row['id'] ?? '',
                    $date,
                    $log_row['user_display_name'] ?? '',
                    $log_row['bot_name'] ?? '',
                    $log_row['module'] ?? '',
                    $log_row['message_count'] ?? '',
                    $log_row['total_conversation_tokens'] ?? '',
                    $preview,
                    $log_row['conversation_uuid'] ?? '',
                ];
                $lines[] = implode(',', array_map([$this, 'escape_csv_field'], $line));
            }
        }

        $csv = implode("\r\n", $lines);
        $filename = sprintf('aipkit-logs-%s.csv', gmdate('Ymd-His'));
        $message = __('Export ready.', 'gpt3-ai-content-generator');
        if ($total_logs > $limit) {
            $message = sprintf(
                /* translators: %d: number of exported rows */
                __('Exported first %d logs (filtered).', 'gpt3-ai-content-generator'),
                $limit
            );
        }

        wp_send_json_success([
            'csv' => $csv,
            'filename' => $filename,
            'message' => $message,
        ]);
    }

    public function ajax_delete_stats_log()
    {
        $post_data = $this->get_stats_post_data();
        if ($post_data === null) {
            return;
        }
        $log_id = isset($post_data['log_id']) ? absint($post_data['log_id']) : 0;
        if (!$log_id) {
            $this->send_wp_error(new WP_Error('missing_log_id', __('Log ID is required.', 'gpt3-ai-content-generator')));
            return;
        }

        if (!class_exists(LogStorage::class)) {
            $this->send_wp_error(new WP_Error('missing_log_storage', __('Log storage is unavailable.', 'gpt3-ai-content-generator')));
            return;
        }

        $log_storage = new LogStorage();
        $log_row = $log_storage->get_log_by_id($log_id);
        if (!$log_row) {
            $this->send_wp_error(new WP_Error('log_not_found', __('Log entry not found.', 'gpt3-ai-content-generator')));
            return;
        }

        $result = $log_storage->delete_single_conversation(
            $log_row['user_id'] ?? null,
            $log_row['session_id'] ?? null,
            $log_row['bot_id'] ?? null,
            $log_row['conversation_uuid'] ?? ''
        );
        if (is_wp_error($result)) {
            $this->send_wp_error($result);
            return;
        }

        wp_send_json_success([
            'message' => __('Conversation deleted.', 'gpt3-ai-content-generator'),
            'log_id' => $log_id,
        ]);
    }

    public function ajax_delete_stats_logs()
    {
        $post_data = $this->get_stats_post_data();
        if ($post_data === null) {
            return;
        }
        $filters = $this->build_stats_log_filters($post_data);

        if (!class_exists(LogStorage::class)) {
            $this->send_wp_error(new WP_Error('missing_log_storage', __('Log storage is unavailable.', 'gpt3-ai-content-generator')));
            return;
        }

        $log_storage = new LogStorage();
        $batch_size = 500;
        $max_batches = 200;
        $total_deleted = 0;
        $batch = 0;

        do {
            $deleted = $log_storage->delete_logs($filters, $batch_size);
            if ($deleted === false) {
                $this->send_wp_error(new WP_Error('delete_failed', __('Failed to delete logs.', 'gpt3-ai-content-generator')));
                return;
            }
            $total_deleted += (int) $deleted;
            $batch++;
        } while ($deleted === $batch_size && $batch < $max_batches);

        $message = sprintf(
            /* translators: %d: number of log entries deleted */
            _n('%d log deleted.', '%d logs deleted.', $total_deleted, 'gpt3-ai-content-generator'),
            number_format_i18n($total_deleted)
        );

        if ($batch >= $max_batches && $deleted === $batch_size) {
            $message = __('Log deletion reached the maximum batch limit. Please run again to continue.', 'gpt3-ai-content-generator');
        }

        wp_send_json_success([
            'message' => $message,
            'deleted' => $total_deleted,
        ]);
    }

    public function ajax_save_stats_settings()
    {
        $post_data = $this->get_stats_post_data();
        if ($post_data === null) {
            return;
        }
        $enable_pruning = isset($post_data['enable_pruning']) && $post_data['enable_pruning'] === '1';
        $retention_period = isset($post_data['retention_period_days']) ? floatval($post_data['retention_period_days']) : 90;
        $customer_dashboard_page_url = isset($post_data['customer_dashboard_page_url'])
            ? esc_url_raw(trim((string) $post_data['customer_dashboard_page_url']))
            : '';
        $customer_buycredits_url = isset($post_data['customer_buycredits_url'])
            ? esc_url_raw(trim((string) $post_data['customer_buycredits_url']))
            : '';

        if (!LogConfig::is_valid_period($retention_period)) {
            $retention_period = 90;
        }

        $is_pro = class_exists('\\WPAICG\\aipkit_dashboard')
            ? \WPAICG\aipkit_dashboard::is_pro_plan()
            : false;
        if ($enable_pruning && !$is_pro) {
            $this->send_wp_error(new WP_Error('pro_required', __('Auto-delete logs is a Pro feature.', 'gpt3-ai-content-generator')));
            return;
        }

        $settings = [
            'enable_pruning' => $enable_pruning && $is_pro,
            'retention_period_days' => $retention_period,
        ];
        update_option('aipkit_log_settings', $settings, 'no');
        if ($customer_dashboard_page_url === '') {
            delete_option('aipkit_token_dashboard_page_url');
        } else {
            update_option('aipkit_token_dashboard_page_url', $customer_dashboard_page_url, 'no');
        }
        if ($customer_buycredits_url === '') {
            delete_option('aipkit_token_shop_page_url');
        } else {
            update_option('aipkit_token_shop_page_url', $customer_buycredits_url, 'no');
        }

        if (class_exists(LogCronManager::class)) {
            if ($enable_pruning && $is_pro) {
                LogCronManager::schedule_event();
            } else {
                LogCronManager::unschedule_event();
            }
        }

        wp_send_json_success([
            'message' => __('Settings saved.', 'gpt3-ai-content-generator'),
        ]);
    }

    public function ajax_get_stats_log_cron_status()
    {
        if (!$this->ensure_stats_access()) {
            return;
        }

        $log_settings = LogConfig::get_log_settings();
        $enable_pruning = (bool) ($log_settings['enable_pruning'] ?? false);
        $is_pro = class_exists('\\WPAICG\\aipkit_dashboard') ? \WPAICG\aipkit_dashboard::is_pro_plan() : false;

        $cron_hook = LogCronManager::HOOK_NAME;
        $next_scheduled = wp_next_scheduled($cron_hook);

        // Repair a missing event when pruning is enabled so the UI reflects an
        // actionable schedule instead of contradicting the saved setting.
        if ($enable_pruning && $is_pro && $next_scheduled === false) {
            LogCronManager::schedule_event();
            $next_scheduled = wp_next_scheduled($cron_hook);
        }

        $is_cron_active = $next_scheduled !== false;

        $state = 'disabled';
        $status_text = __('Disabled', 'gpt3-ai-content-generator');
        if ($enable_pruning) {
            if ($is_cron_active) {
                $state = 'scheduled';
                $status_text = (int) $next_scheduled <= time()
                    ? __('Next run is due', 'gpt3-ai-content-generator')
                    : sprintf(
                        /* translators: %s: time until the next log-pruning run. */
                        __('Next run in %s', 'gpt3-ai-content-generator'),
                        human_time_diff(time(), (int) $next_scheduled)
                    );
            } else {
                $state = 'pending';
                $status_text = __('Scheduling next run…', 'gpt3-ai-content-generator');
            }
        }

        $last_run_option = get_option('aipkit_log_pruning_last_run', '');
        $last_run_label = $last_run_option
            ? sprintf(
                /* translators: %s: last log-pruning run time. */
                __('Last run %s', 'gpt3-ai-content-generator'),
                wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_run_option))
            )
            : __('No cleanup has run yet', 'gpt3-ai-content-generator');

        wp_send_json_success([
            'state' => $state,
            'status_text' => $status_text,
            'last_run_label' => $last_run_label,
        ]);
    }

    private function build_stats_log_filters(array $post_data): array
    {
        $days = $this->resolve_stats_days($post_data['days'] ?? 30);
        $range = $this->get_stats_time_range($days);

        $filters = [
            'start_ts' => $range['start_ts'],
            'end_ts' => $range['end_ts'],
        ];

        $bot_id_raw = isset($post_data['bot_id']) ? sanitize_text_field($post_data['bot_id']) : '';
        if ($bot_id_raw !== '') {
            $filters['bot_id'] = $bot_id_raw;
        }

        $module = isset($post_data['module']) ? sanitize_key($post_data['module']) : '';
        if ($module !== '') {
            $filters['module'] = $module;
        }

        $search = isset($post_data['search']) ? sanitize_text_field($post_data['search']) : '';
        if ($search !== '') {
            $filters['search'] = $search;
        }

        return $filters;
    }

    /**
     * @param string|int|float|null $value
     */
    private function escape_csv_field($value): string
    {
        $string_value = (string) $value;
        $string_value = str_replace(["\r\n", "\n", "\r"], ' ', $string_value);
        $string_value = str_replace('"', '""', $string_value);
        return '"' . $string_value . '"';
    }
}
