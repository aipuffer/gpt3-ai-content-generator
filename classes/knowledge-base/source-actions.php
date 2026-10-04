<?php

namespace WPAICG\KnowledgeBase;

use WPAICG\Dashboard\Ajax\BaseDashboardAjaxHandler;
use WPAICG\AIPKit_Providers;
use WPAICG\Vector\AIPKit_Vector_Store_Manager;
use WPAICG\Vector\AIPKit_Vector_Store_Registry;
use WPAICG\Vector\PostProcessor\OpenAI\OpenAIPostProcessor;
use WPAICG\Vector\PostProcessor\Google\GooglePostProcessor;
use WPAICG\Vector\PostProcessor\Pinecone\PineconePostProcessor;
use WPAICG\Core\Providers\Google\FileSearch\GoogleFileSearchClient;
use WPAICG\Vector\GoogleFileSearch\GoogleFileSearchIngestionService;
use WPAICG\Vector\PostProcessor\Qdrant\QdrantPostProcessor;
use WPAICG\Vector\PostProcessor\Chroma\ChromaPostProcessor;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/** Manages Knowledge Base sources and indexing settings. */
class AIPKit_Source_Ajax_Handler extends BaseDashboardAjaxHandler
{
    private $vector_store_manager;
    private $openai_post_processor;
    private $google_post_processor;
    private $pinecone_post_processor;
    private $qdrant_post_processor;
    private $chroma_post_processor;

    public function __construct()
    {
        if (class_exists(\WPAICG\Vector\AIPKit_Vector_Store_Manager::class)) {
            $this->vector_store_manager = new AIPKit_Vector_Store_Manager();
        }
        if (class_exists(OpenAIPostProcessor::class)) {
            $this->openai_post_processor = new OpenAIPostProcessor();
        }
        if (class_exists(GooglePostProcessor::class)) {
            $this->google_post_processor = new GooglePostProcessor();
        }
        if (class_exists(PineconePostProcessor::class)) {
            $this->pinecone_post_processor = new PineconePostProcessor();
        }
        if (class_exists(QdrantPostProcessor::class)) {
            $this->qdrant_post_processor = new QdrantPostProcessor();
        }
        if (class_exists(ChromaPostProcessor::class)) {
            $this->chroma_post_processor = new ChromaPostProcessor();
        }
    }

    /**
     * Builds provider-specific selectors that remove every chunk for a WordPress post.
     */
    private static function build_post_chunk_delete_selector(string $provider, int $post_id): ?array
    {
        if ($post_id <= 0) {
            return null;
        }

        if ($provider === 'Pinecone') {
            return ['filter' => ['$or' => [
                ['post_id' => ['$eq' => (string) $post_id]],
                ['post_id' => ['$eq' => $post_id]],
            ]]];
        }

        if ($provider === 'Qdrant' || $provider === 'Local') {
            return ['filter' => ['should' => [
                ['key' => 'post_id', 'match' => ['value' => (string) $post_id]],
            ]]];
        }

        if ($provider === 'Chroma') {
            return ['where' => ['$or' => [
                ['post_id' => (string) $post_id],
                ['post_id' => $post_id],
            ]]];
        }

        return null;
    }

    /**
     * Builds selectors that remove every chunk belonging to a text/source parent id.
     */
    private static function build_parent_chunk_delete_selector(string $provider, string $parent_vector_id): ?array
    {
        if ($parent_vector_id === '') {
            return null;
        }

        if ($provider === 'Pinecone') {
            return ['filter' => ['$or' => [
                ['parent_vector_id' => ['$eq' => $parent_vector_id]],
                ['vector_id' => ['$eq' => $parent_vector_id]],
            ]]];
        }

        if ($provider === 'Qdrant' || $provider === 'Local') {
            return ['filter' => ['should' => [
                ['key' => 'parent_vector_id', 'match' => ['value' => $parent_vector_id]],
                ['key' => 'vector_id', 'match' => ['value' => $parent_vector_id]],
            ]]];
        }

        if ($provider === 'Chroma') {
            return ['where' => ['$or' => [
                ['parent_vector_id' => $parent_vector_id],
                ['vector_id' => $parent_vector_id],
            ]]];
        }

        return null;
    }

    /**
     * AJAX handler to delete a single vector data source entry from both the vector DB and local log.
     * @since NEXT_VERSION
     */
    public function ajax_delete_vector_data_source_entry()
    {
        $permission_check = $this->check_any_module_access_permissions(
            ['sources', 'chatbot'],
            'aipkit_nonce'
        );
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked above.
        $post_data = wp_unslash($_POST);

        $provider_raw = isset($post_data['provider']) ? sanitize_text_field($post_data['provider']) : '';
        $provider = AIPKit_Providers::normalize_provider_label($provider_raw);
        $allowed_providers = ['OpenAI', 'Google', 'Pinecone', 'Qdrant', 'Chroma', 'Local'];
        if (!in_array($provider, $allowed_providers, true)) {
            $this->send_wp_error(new WP_Error('invalid_provider_delete_vector', __('Invalid or unsupported provider for this action.', 'gpt3-ai-content-generator')));
            return;
        }
        
        $store_id   = isset($post_data['store_id']) ? sanitize_text_field($post_data['store_id']) : '';
        $vector_id  = isset($post_data['vector_id']) ? sanitize_text_field($post_data['vector_id']) : '';
        $log_entry_id = isset($post_data['log_id']) ? absint($post_data['log_id']) : 0;

        if (empty($provider) || empty($store_id) || empty($log_entry_id)) {
            $this->send_wp_error(new WP_Error('missing_params_delete_vector', __('Missing required parameters for vector deletion.', 'gpt3-ai-content-generator')));
            return;
        }

        global $wpdb;
        $data_source_table_name = $wpdb->prefix . 'aipkit_vector_data_source';
        $data_source_table_identifier = self::validated_table_identifier($data_source_table_name);
        if ($data_source_table_identifier === '') {
            $this->send_wp_error(new WP_Error('invalid_vector_table_identifier', __('Invalid vector data source table.', 'gpt3-ai-content-generator')));
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table lookup for admin delete action; table identifier is plugin-owned, validated, and backticked above.
        $log_entry = $wpdb->get_row($wpdb->prepare("SELECT id, provider, vector_store_id, file_id, post_id, status FROM {$data_source_table_identifier} WHERE id = %d LIMIT 1", $log_entry_id), ARRAY_A);

        if (!$log_entry) {
            do_action('aipkit_vector_source_log_deleted', $log_entry_id);
            wp_send_json_success(['message' => __('Log entry was not found, it might have been already deleted.', 'gpt3-ai-content-generator')]);
            return;
        }

        if ((string) ($log_entry['provider'] ?? '') !== $provider || (string) ($log_entry['vector_store_id'] ?? '') !== $store_id) {
            $this->send_wp_error(new WP_Error('mismatched_delete_vector_log', __('Source details do not match the selected log entry.', 'gpt3-ai-content-generator')));
            return;
        }

        if ($vector_id === '' && !empty($log_entry['file_id'])) {
            $vector_id = (string) $log_entry['file_id'];
        }

        if ($provider === 'Google' && strtolower((string) ($log_entry['status'] ?? '')) === 'processing') {
            $this->send_wp_error(new WP_Error(
                'google_file_search_delete_processing',
                __('Wait for Google File Search indexing to finish before deleting this source.', 'gpt3-ai-content-generator'),
                ['status' => 409]
            ));
            return;
        }

        $post_chunk_delete_selector = self::build_post_chunk_delete_selector($provider, absint($log_entry['post_id'] ?? 0));
        $can_delete_post_chunks = $post_chunk_delete_selector !== null;
        $is_failed_log_only = $vector_id === '' && strtolower((string) ($log_entry['status'] ?? '')) === 'failed';
        if ($vector_id === '' && !$is_failed_log_only && !$can_delete_post_chunks) {
            $this->send_wp_error(new WP_Error('missing_vector_id_delete_vector', __('Missing vector ID for vector deletion.', 'gpt3-ai-content-generator')));
            return;
        }

        if ($provider === 'Google' && $vector_id !== '' && !GoogleFileSearchIngestionService::is_pending_file_id($vector_id)) {
            $provider_config = AIPKit_Providers::get_provider_data('Google');
            if (empty($provider_config['api_key'])) {
                $this->send_wp_error(new WP_Error('missing_google_api_key_delete_document', __('Google API key is missing.', 'gpt3-ai-content-generator')));
                return;
            }
            $provider_config['api_version'] = 'v1beta';
            $google_client = new GoogleFileSearchClient();
            $delete_result = $google_client->delete_document($provider_config, $store_id, $vector_id, true);
            if (is_wp_error($delete_result)) {
                $error_data = $delete_result->get_error_data();
                $http_status = is_array($error_data) ? (int) ($error_data['status'] ?? 0) : 0;
                if ($http_status !== 404) {
                    $this->send_wp_error($delete_result);
                    return;
                }
            }
        } elseif ($provider !== 'Google' && ($vector_id !== '' || $can_delete_post_chunks)) {
            // Ensure Vector Store Manager is loaded
            if (!class_exists('\\WPAICG\\Vector\\AIPKit_Vector_Store_Manager')) {
                $this->send_wp_error(new WP_Error('vsm_missing_delete_vector', __('Vector management component is not available.', 'gpt3-ai-content-generator')));
                return;
            }
            $vector_store_manager = new \WPAICG\Vector\AIPKit_Vector_Store_Manager();

            // Get provider config
            $provider_config = \WPAICG\AIPKit_Providers::get_provider_data($provider);
            if ($provider === 'Local') {
                $provider_config = []; // Built-in store: nothing to connect to.
            } elseif ($provider === 'Chroma') {
                if (empty($provider_config['url'])) {
                    $this->send_wp_error(new WP_Error('missing_chroma_url_delete_vector', __('Chroma URL is missing.', 'gpt3-ai-content-generator')));
                    return;
                }
            } elseif (empty($provider_config['api_key'])) {
                /* translators: %s: Provider name. */
                $this->send_wp_error(new WP_Error('missing_api_key_delete_vector', sprintf(__('API key for %s is missing.', 'gpt3-ai-content-generator'), $provider)));
                return;
            }

            // 1. Delete from external vector store
            $delete_selector = $post_chunk_delete_selector ?: (self::build_parent_chunk_delete_selector($provider, $vector_id) ?: [$vector_id]);
            $delete_result = $vector_store_manager->delete_vectors($provider, $store_id, $delete_selector, $provider_config);

            // We proceed even if the external deletion fails, as the vector might not exist there anymore but the log does.
            // We will log the error if one occurs.
            if (is_wp_error($delete_result)) {
                // This is not a fatal error for the process, so we just log it and continue to delete from local DB.
            }
        }

        // 2. Delete from local database log
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table delete for admin action.
        $deleted_rows = $wpdb->delete(
            $data_source_table_name,
            ['id' => $log_entry_id],
            ['%d']
        );

        if ($deleted_rows === false) {
             $this->send_wp_error(new WP_Error('db_delete_failed_vector_log', __('Failed to delete the log entry from the local database.', 'gpt3-ai-content-generator')));
             return;
        }
        if ($deleted_rows === 0) {
            // This could mean it was already deleted, which is a success state for the user.
            do_action('aipkit_vector_source_log_deleted', $log_entry_id);
            wp_send_json_success(['message' => __('Log entry was not found, it might have been already deleted.', 'gpt3-ai-content-generator')]);
            return;
        }

        do_action('aipkit_vector_source_log_deleted', $log_entry_id);

        wp_send_json_success(['message' => __('Vector record and log entry deleted successfully.', 'gpt3-ai-content-generator')]);
    }

    /**
     * AJAX handler to re-index a single vector data source entry from a WordPress post.
     * @since 2.4.2
     */
    public function ajax_reindex_vector_data_source_entry() {
        $permission_check = $this->check_any_module_access_permissions(
            ['sources', 'chatbot'],
            'aipkit_nonce'
        );
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // Sanitize all inputs.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified by check_module_access_permissions() above.
        $post_data = wp_unslash($_POST);
        $provider_raw = isset($post_data['provider']) ? sanitize_text_field($post_data['provider']) : '';
        $provider = AIPKit_Providers::normalize_provider_label($provider_raw);
        $store_id = isset($post_data['store_id']) ? sanitize_text_field($post_data['store_id']) : '';
        $vector_id = isset($post_data['vector_id']) ? sanitize_text_field($post_data['vector_id']) : '';
        $log_id = isset($post_data['log_id']) ? absint($post_data['log_id']) : 0;
        $post_id = isset($post_data['post_id']) ? absint($post_data['post_id']) : 0;
        $embedding_provider = isset($post_data['embedding_provider']) ? sanitize_key($post_data['embedding_provider']) : '';
        $embedding_model = isset($post_data['embedding_model']) ? sanitize_text_field($post_data['embedding_model']) : '';

        // Validate required parameters
        if (empty($provider) || empty($store_id) || empty($vector_id) || empty($log_id) || empty($post_id)) {
            $this->send_wp_error(new WP_Error('missing_params_reindex', __('Missing required parameters for re-indexing.', 'gpt3-ai-content-generator')));
            return;
        }

        $post_processor = null;
        switch ($provider) {
            case 'OpenAI':
                $post_processor = $this->openai_post_processor;
                break;
            case 'Google':
                $post_processor = $this->google_post_processor;
                break;
            case 'Pinecone':
                $post_processor = $this->pinecone_post_processor;
                break;
            case 'Qdrant':
                $post_processor = $this->qdrant_post_processor;
                break;
            case 'Chroma':
                $post_processor = $this->chroma_post_processor;
                break;
            case 'Local':
                if (!class_exists(\WPAICG\Vector\PostProcessor\Local\LocalPostProcessor::class)) {
                    require_once WPAICG_PLUGIN_DIR . 'classes/knowledge-base/indexing-local.php';
                }
                $post_processor = new \WPAICG\Vector\PostProcessor\Local\LocalPostProcessor();
                break;
            default:
                $this->send_wp_error(new WP_Error('invalid_provider_reindex', __('Invalid provider for re-indexing.', 'gpt3-ai-content-generator')));
                return;
        }
        if (!$post_processor || ($provider !== 'Google' && !$this->vector_store_manager)) {
            $this->send_wp_error(new WP_Error('vsm_missing_reindex', __('Vector processing components are not available.', 'gpt3-ai-content-generator')));
            return;
        }
        if (
            in_array($provider, ['Pinecone', 'Qdrant', 'Chroma', 'Local'], true)
            && (empty($embedding_provider) || empty($embedding_model))
        ) {
            $this->send_wp_error(new WP_Error('missing_embedding_config_reindex', __('Embedding provider and model are required for re-indexing.', 'gpt3-ai-content-generator')));
            return;
        }

        // OpenAI owns safe replacement and keeps the previous file/log until ready.
        $provider_config = $provider === 'Local' ? [] : AIPKit_Providers::get_provider_data($provider);
        if ($provider === 'Local') {
            // Local has no external storage credentials.
        } elseif ($provider === 'Chroma') {
            if (empty($provider_config['url'])) {
                $this->send_wp_error(new WP_Error('missing_chroma_url_reindex', __('Chroma URL is missing.', 'gpt3-ai-content-generator')));
                return;
            }
        } elseif (empty($provider_config['api_key'])) {
            /* translators: %s: Provider name. */
             $this->send_wp_error(new WP_Error('missing_api_key_reindex', sprintf(__('API key for %s is missing.', 'gpt3-ai-content-generator'), $provider)));
             return;
        }
        if ($provider === 'Google') {
            $provider_config['api_version'] = 'v1beta';
            $delete_result = (new GoogleFileSearchClient())->delete_document($provider_config, $store_id, $vector_id, true);
            if (is_wp_error($delete_result)) {
                $error_data = $delete_result->get_error_data();
                $http_status = is_array($error_data) ? (int) ($error_data['status'] ?? 0) : 0;
                if ($http_status !== 404) {
                    $this->send_wp_error($delete_result);
                    return;
                }
            }
        } elseif ($provider !== 'OpenAI') {
            if (!$this->vector_store_manager) {
                $this->send_wp_error(new WP_Error('vsm_missing_reindex', __('Vector processing components are not available.', 'gpt3-ai-content-generator')));
                return;
            }
            $delete_selector = self::build_post_chunk_delete_selector($provider, $post_id) ?: [$vector_id];
            $delete_result = $this->vector_store_manager->delete_vectors($provider, $store_id, $delete_selector, $provider_config);
            if (is_wp_error($delete_result)) {
                // The source may have already been removed remotely; the fresh index below remains authoritative.
            }
        }

        global $wpdb;
        $data_source_table_name = $wpdb->prefix . 'aipkit_vector_data_source';
        if ($provider !== 'OpenAI') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Other providers retain their existing synchronous replacement flow.
            $deleted_source_log = $wpdb->delete($data_source_table_name, ['id' => $log_id], ['%d']);
            if ($deleted_source_log !== false) {
                do_action('aipkit_vector_source_log_deleted', $log_id);
            }
        }

        // Step 2: Re-index the post
        $reindex_result = null;
        switch ($provider) {
            case 'OpenAI':
                $reindex_result = $this->openai_post_processor->index_single_post_to_store($post_id, $store_id);
                break;
            case 'Google':
                $reindex_result = $this->google_post_processor->index_single_post_to_store($post_id, $store_id);
                break;
            case 'Pinecone':
                $reindex_result = $this->pinecone_post_processor->index_single_post_to_index($post_id, $store_id, $embedding_provider, $embedding_model);
                break;
            case 'Qdrant':
                $reindex_result = $this->qdrant_post_processor->index_single_post_to_collection($post_id, $store_id, $embedding_provider, $embedding_model);
                break;
            case 'Chroma':
                $reindex_result = $this->chroma_post_processor->index_single_post_to_collection($post_id, $store_id, $embedding_provider, $embedding_model);
                break;
            case 'Local':
                $reindex_result = $post_processor->index_single_post_to_store($post_id, $store_id, $embedding_provider, $embedding_model);
                break;
            default:
                $this->send_wp_error(new WP_Error('invalid_provider_reindex', __('Invalid provider for re-indexing.', 'gpt3-ai-content-generator')));
                return;
        }

        if (isset($reindex_result['status']) && $reindex_result['status'] === 'success') {
            $processing = !empty($reindex_result['processing']);
            wp_send_json_success([
                'processing' => $processing,
                'job_id' => (int) ($reindex_result['job_id'] ?? 0),
                'message' => $processing
                    ? __('Submitted — processing in background', 'gpt3-ai-content-generator')
                    : __('Content successfully re-indexed.', 'gpt3-ai-content-generator'),
            ]);
        } else {
            $error_message = $reindex_result['message'] ?? __('An unknown error occurred during re-indexing.', 'gpt3-ai-content-generator');
            $this->send_wp_error(new WP_Error('reindex_failed', 'Re-indexing failed: ' . $error_message));
        }
    }

    /**
     * AJAX handler to fetch global vector sources for the Sources module.
     * @since NEXT_VERSION
     */
    public function ajax_get_global_vector_sources()
    {
        $permission_check = $this->check_module_access_permissions('sources', 'aipkit_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked above.
        $post_data = wp_unslash($_POST);

        $provider_map = $this->get_vector_source_provider_map();
        $filters = $this->get_vector_sources_filters($post_data, $provider_map);
        [$where_sql, $params] = $this->build_vector_sources_where($filters);

        global $wpdb;
        $table_name = $wpdb->prefix . 'aipkit_vector_data_source';
        $total_logs = null;
        if (empty($filters['cursor_mode'])) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Legacy exact pagination path; cursor-mode module requests skip this full count.
            $total_logs = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE {$where_sql}", ...$params));
        }

        $query_limit = !empty($filters['cursor_mode']) ? $filters['per_page'] + 1 : $filters['per_page'];
        $query_offset = !empty($filters['cursor_mode']) ? 0 : $filters['offset'];
        $logs_params = array_merge($params, [$query_limit, $query_offset]);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name and assembled WHERE clause are internal and scalar values are prepared below.
        $logs = $wpdb->get_results($wpdb->prepare("SELECT id, timestamp, provider, status, message, indexed_content, post_id, post_title, file_id, batch_id, embedding_provider, embedding_model, vector_store_id, vector_store_name FROM {$table_name} WHERE {$where_sql} ORDER BY timestamp DESC, id DESC LIMIT %d OFFSET %d", ...$logs_params), ARRAY_A);
        $has_more = !empty($filters['cursor_mode']) && count((array) $logs) > $filters['per_page'];
        if ($has_more) {
            array_pop($logs);
        }
        if (is_array($logs)) {
            $openai_store_names = [];
            if (class_exists(AIPKit_Vector_Store_Registry::class)) {
                foreach (AIPKit_Vector_Store_Registry::get_registered_stores_by_provider('OpenAI') as $store) {
                    if (is_object($store)) {
                        $store = (array) $store;
                    }
                    if (!is_array($store)) {
                        continue;
                    }
                    $store_id = isset($store['id']) ? (string) $store['id'] : '';
                    $store_name = isset($store['name']) ? (string) $store['name'] : '';
                    if ($store_id !== '' && $store_name !== '') {
                        $openai_store_names[$store_id] = $store_name;
                    }
                }
            }

            foreach ($logs as &$log) {
                $log['is_user_upload'] = $this->is_vector_source_user_upload($log);
                $log['embedding_model_label'] = \WPAICG\Core\Models\AIPKit_Model_Registry::get_model_display_name(
                    (string) ($log['embedding_provider'] ?? ''),
                    (string) ($log['embedding_model'] ?? '')
                );

                $provider = strtolower((string) ($log['provider'] ?? ''));
                $store_id = (string) ($log['vector_store_id'] ?? '');
                $stored_name = (string) ($log['vector_store_name'] ?? '');
                if (
                    $provider === 'openai'
                    && $store_id !== ''
                    && ($stored_name === '' || $stored_name === $store_id)
                    && isset($openai_store_names[$store_id])
                ) {
                    $log['vector_store_name'] = $openai_store_names[$store_id];
                }
            }
            unset($log);
        }

        $total_pages = empty($filters['cursor_mode']) && $filters['per_page'] > 0 ? (int) ceil((int) $total_logs / $filters['per_page']) : 0;
        $next_cursor = null;
        if ($has_more && !empty($logs)) {
            $last_log = end($logs);
            $next_cursor = [
                'timestamp' => (string) ($last_log['timestamp'] ?? ''),
                'id' => (int) ($last_log['id'] ?? 0),
            ];
        }

        wp_send_json_success([
            'logs' => $logs ?: [],
            'pagination' => [
                'total_logs' => $total_logs,
                'total_pages' => $total_pages,
                'current_page' => $filters['page'],
                'cursor_mode' => !empty($filters['cursor_mode']),
                'has_previous' => $filters['page'] > 1,
                'has_more' => $has_more,
                'next_cursor' => $next_cursor,
                'item_count' => count((array) $logs),
            ],
            'providers' => $this->get_available_vector_source_providers($provider_map),
        ]);
    }

    /**
     * Provider label map for vector sources.
     *
     * @return array<string, string>
     */
    private function get_vector_source_provider_map(): array
    {
        return [
            'openai' => 'OpenAI',
            'google' => 'Google',
            'pinecone' => 'Pinecone',
            'qdrant' => 'Qdrant',
            'chroma' => 'Chroma',
            'local' => 'Local',
        ];
    }

    /**
     * Detect frontend/chat file uploads for Knowledgebase source rows.
     *
     * @param array<string, mixed> $log Source log row.
     */
    private function is_vector_source_user_upload(array $log): bool
    {
        $provider = strtolower((string) ($log['provider'] ?? ''));
        $file_id = (string) ($log['file_id'] ?? '');
        $batch_id = (string) ($log['batch_id'] ?? '');
        $store_name = (string) ($log['vector_store_name'] ?? '');

        if ($provider === 'openai') {
            return strncmp($store_name, 'chat_file_', strlen('chat_file_')) === 0;
        }
        if ($provider === 'pinecone') {
            return strncmp($file_id, 'chatfile_', strlen('chatfile_')) === 0 || strncmp($batch_id, 'pinecone_chat_file_', strlen('pinecone_chat_file_')) === 0;
        }
        if ($provider === 'qdrant') {
            return strncmp($batch_id, 'qdrant_chat_file_', strlen('qdrant_chat_file_')) === 0 || strncmp($file_id, 'qdrant_chat_file_', strlen('qdrant_chat_file_')) === 0;
        }
        if ($provider === 'chroma') {
            return strncmp($batch_id, 'chroma_chat_file_', strlen('chroma_chat_file_')) === 0 || strncmp($file_id, 'chroma_chat_file_', strlen('chroma_chat_file_')) === 0;
        }

        return false;
    }

    /**
     * Normalize filters for vector source queries.
     *
     * @param array<string, mixed> $post_data Raw POST data.
     * @param array<string, string> $provider_map Provider key => label.
     * @return array<string, mixed>
     */
    private function get_vector_sources_filters(array $post_data, array $provider_map): array
    {
        $page = isset($post_data['page']) ? max(1, absint($post_data['page'])) : 1;
        $per_page = isset($post_data['per_page']) ? absint($post_data['per_page']) : 10;
        $per_page = min(100, max(1, $per_page));

        $provider_key = isset($post_data['provider']) ? sanitize_key($post_data['provider']) : '';
        $provider_label = $provider_key && isset($provider_map[$provider_key]) ? $provider_map[$provider_key] : '';

        $status_filter = isset($post_data['status']) ? sanitize_key($post_data['status']) : '';
        $allowed_statuses = ['indexed', 'failed', 'processing', 'queued', 'skipped_already_indexed', 'success'];
        if ($status_filter && !in_array($status_filter, $allowed_statuses, true)) {
            $status_filter = '';
        }

        $type_filter = isset($post_data['type']) ? sanitize_key($post_data['type']) : '';
        $allowed_types = ['site', 'text', 'file'];
        if ($type_filter && !in_array($type_filter, $allowed_types, true)) {
            $type_filter = '';
        }

        $general_settings = get_option('aipkit_training_general_settings', []);
        $hide_user_uploads = isset($general_settings['hide_user_uploads'])
            ? (bool) $general_settings['hide_user_uploads']
            : true;

        return [
            'page' => $page,
            'per_page' => $per_page,
            'offset' => ($page - 1) * $per_page,
            'provider_label' => $provider_label,
            'status' => $status_filter,
            'type' => $type_filter,
            'search' => isset($post_data['search']) ? sanitize_text_field($post_data['search']) : '',
            'store_id' => isset($post_data['store_id']) ? sanitize_text_field($post_data['store_id']) : '',
            'hide_user_uploads' => $hide_user_uploads,
            'cursor_mode' => isset($post_data['cursor_mode']) && sanitize_text_field($post_data['cursor_mode']) === '1',
            'cursor_timestamp' => isset($post_data['cursor_timestamp']) ? sanitize_text_field($post_data['cursor_timestamp']) : '',
            'cursor_id' => isset($post_data['cursor_id']) ? absint($post_data['cursor_id']) : 0,
        ];
    }

    /**
     * Builds WHERE clause and params for vector source queries.
     *
     * @param array<string, mixed> $filters Normalized filters.
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function build_vector_sources_where(array $filters): array
    {
        global $wpdb;

        $where_clauses = ['(post_id IS NOT NULL OR file_id IS NOT NULL OR indexed_content IS NOT NULL)'];
        $params = [];

        if (!empty($filters['provider_label'])) {
            $where_clauses[] = 'provider = %s';
            $params[] = $filters['provider_label'];
        }

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'processing') {
                $where_clauses[] = '(status = %s OR status = %s)';
                $params[] = 'processing';
                $params[] = 'queued';
            } elseif ($filters['status'] === 'indexed') {
                $where_clauses[] = '(status = %s OR status = %s OR status = %s)';
                $params[] = 'indexed';
                $params[] = 'skipped_already_indexed';
                $params[] = 'success';
            } else {
                $where_clauses[] = 'status = %s';
                $params[] = $filters['status'];
            }
        }

        if (!empty($filters['type'])) {
            if ($filters['type'] === 'site') {
                $where_clauses[] = '(' . implode(' OR ', [
                    '(post_id IS NOT NULL)',
                    '(message LIKE %s)',
                    '(file_id LIKE %s)',
                    '(provider = %s AND message LIKE %s AND message LIKE %s)',
                ]) . ')';
                $params[] = '%wordpress post content submitted for indexing%';
                $params[] = 'wp_post_%';
                $params[] = 'Qdrant';
                $params[] = '%points upserted to qdrant%';
                $params[] = '%post id:%';
            } elseif ($filters['type'] === 'text') {
                $where_clauses[] = '(' . implode(' OR ', [
                    '(message LIKE %s)',
                    '(file_id LIKE %s)',
                    '(provider = %s AND message LIKE %s AND (post_id IS NULL OR post_id = 0) AND message NOT LIKE %s)',
                    '(provider = %s AND message LIKE %s AND (post_id IS NULL OR post_id = 0))',
                ]) . ')';
                $params[] = '%text content submitted for indexing%';
                $params[] = 'text_%';
                $params[] = 'Qdrant';
                $params[] = '%points upserted to qdrant%';
                $params[] = '%post id:%';
                $params[] = 'Chroma';
                $params[] = '%chroma records upserted%';
            } elseif ($filters['type'] === 'file') {
                $where_clauses[] = '(' . implode(' OR ', [
                    '(message LIKE %s)',
                    '(message LIKE %s)',
                    '(message LIKE %s)',
                    '(message LIKE %s)',
                    '(file_id LIKE %s)',
                    '(message LIKE %s)',
                    '(file_id LIKE %s)',
                ]) . ')';
                $params[] = '%file content submitted for indexing%';
                $params[] = '%file content embedded and upserted%';
                $params[] = '%original filename:%';
                $params[] = '%file uploaded%';
                $params[] = 'pinecone_file_%';
                $params[] = '%file chunk embedded%';
                $params[] = 'chroma_file_%';
            }
        }

        if (!empty($filters['hide_user_uploads'])) {
            $where_clauses[] = 'NOT (' . implode(' OR ', [
                '(provider = %s AND COALESCE(vector_store_name, \'\') LIKE %s)',
                '(provider = %s AND COALESCE(file_id, \'\') LIKE %s)',
                '(provider = %s AND (COALESCE(batch_id, \'\') LIKE %s OR COALESCE(file_id, \'\') LIKE %s))',
                '(provider = %s AND (COALESCE(batch_id, \'\') LIKE %s OR COALESCE(file_id, \'\') LIKE %s))',
            ]) . ')';
            $params[] = 'OpenAI';
            $params[] = 'chat_file_%';
            $params[] = 'Pinecone';
            $params[] = 'chatfile_%';
            $params[] = 'Qdrant';
            $params[] = 'qdrant_chat_file_%';
            $params[] = 'qdrant_chat_file_%';
            $params[] = 'Chroma';
            $params[] = 'chroma_chat_file_%';
            $params[] = 'chroma_chat_file_%';
        }

        if (!empty($filters['search'])) {
            $like = '%' . $wpdb->esc_like($filters['search']) . '%';
            $where_clauses[] = '(message LIKE %s OR post_title LIKE %s OR file_id LIKE %s OR vector_store_name LIKE %s OR indexed_content LIKE %s)';
            $params = array_merge($params, array_fill(0, 5, $like));
        }

        if (!empty($filters['store_id'])) {
            $where_clauses[] = 'vector_store_id = %s';
            $params[] = $filters['store_id'];
        }

        if (!empty($filters['cursor_mode']) && !empty($filters['cursor_timestamp']) && !empty($filters['cursor_id'])) {
            $where_clauses[] = '(timestamp < %s OR (timestamp = %s AND id < %d))';
            $params[] = $filters['cursor_timestamp'];
            $params[] = $filters['cursor_timestamp'];
            $params[] = $filters['cursor_id'];
        }

        return [implode(' AND ', $where_clauses), $params];
    }

    /**
     * Returns provider options without scanning the source table.
     *
     * @param array<string, string> $provider_map Provider key => label.
     * @return array<int, array<string, string>>
     */
    private function get_available_vector_source_providers(array $provider_map): array
    {
        $providers = [];
        foreach ($provider_map as $key => $label) {
            $providers[] = [
                'key' => $key,
                'label' => $label,
            ];
        }

        return $providers;
    }

    /**
     * AJAX: Retrieves CPTs and their fields/taxonomies for indexing settings UI.
     * @since 2.4.0
     */
    public function ajax_get_cpt_indexing_options()
    {
        $permission_check = $this->check_any_module_access_permissions(
            ['settings', 'sources'],
            'aipkit_ai_training_settings_nonce'
        );
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        $cpt_data = [];
        $post_types = get_post_types(['public' => true], 'objects');
        unset($post_types['attachment']);

        foreach ($post_types as $cpt) {
            $taxonomies = get_object_taxonomies($cpt->name, 'objects');
            $public_taxonomies = [];
            foreach ($taxonomies as $tax) {
                if ($tax->public) {
                    $public_taxonomies[$tax->name] = $tax->label;
                }
            }

            $cpt_data[$cpt->name] = [
                'label'      => $cpt->label,
                'fields'     => $this->get_public_meta_keys_for_post_type($cpt->name),
                'taxonomies' => $public_taxonomies,
                'basic_labels' => [
                    'source_url' => __('Source URL', 'gpt3-ai-content-generator'),
                    'title'      => __('Title', 'gpt3-ai-content-generator'),
                    'excerpt'    => __('Excerpt', 'gpt3-ai-content-generator'),
                    'content'    => __('Content', 'gpt3-ai-content-generator'),
                ]
            ];

            if ($cpt->name === 'product' && class_exists('WooCommerce')) {
                $cpt_data[$cpt->name]['woo_attributes'] = [
                    'sku'        => __('SKU', 'gpt3-ai-content-generator'),
                    'price'      => __('Price', 'gpt3-ai-content-generator'),
                    'stock'      => __('Stock Status', 'gpt3-ai-content-generator'),
                    'dimensions' => __('Weight & Dimensions', 'gpt3-ai-content-generator'),
                    'attributes' => __('Product Attributes', 'gpt3-ai-content-generator'),
                ];
            }
        }

        $saved_settings = get_option('aipkit_indexing_field_settings', []);

        $response_data = [
            'cpt_data'       => $cpt_data,
            'saved_settings' => $saved_settings,
        ];
        
        wp_send_json_success($response_data);
    }

    /**
     * AJAX: Saves site-wide CPT indexing settings; requires Settings access.
     * @since 2.4.0
     */
    public function ajax_save_cpt_indexing_options()
    {
        $permission_check = $this->check_module_access_permissions(
            'settings',
            'aipkit_ai_training_settings_nonce'
        );
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified by check_module_access_permissions() above.
        $post_data = wp_unslash($_POST);
        $settings_json = isset($post_data['settings']) ? (string) $post_data['settings'] : '{}';
        $settings = json_decode($settings_json, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($settings)) {
            $this->send_wp_error(new WP_Error('invalid_json', __('Invalid settings format.', 'gpt3-ai-content-generator')));
            return;
        }

        // General settings are stored separately from provider indexing settings.
        $general_settings = get_option('aipkit_training_general_settings', []);
        $general_dirty = false;
        if (isset($settings['hide_user_uploads'])) {
            $general_settings['hide_user_uploads'] = (bool) $settings['hide_user_uploads'];
            unset($settings['hide_user_uploads']);
            $general_dirty = true;
        }
        if (isset($settings['show_index_button'])) {
            $general_settings['show_index_button'] = (bool) $settings['show_index_button'];
            unset($settings['show_index_button']);
            $general_dirty = true;
        }
        $batch_settings_class = '\\WPAICG\\Lib\\VectorStores\\FileUpload\\AIPKit_Vector_File_Ingestion_Batch_Settings';
        $batch_settings_path = defined('WPAICG_LIB_DIR')
            ? WPAICG_LIB_DIR . 'knowledge-base/ingestion-settings.php'
            : '';
        if (!class_exists($batch_settings_class) && $batch_settings_path && file_exists($batch_settings_path)) {
            require_once $batch_settings_path;
        }

        // Chunking settings (Pro only)
        if (isset($settings['chunk_avg_chars_per_token']) || isset($settings['chunk_max_tokens_per_chunk']) || isset($settings['chunk_overlap_tokens'])) {
            // Only allow saving if Pro
            $is_pro = \WPAICG\aipkit_dashboard::is_pro_plan();
            if ($is_pro && class_exists($batch_settings_class)) {
                $chunk_result = $batch_settings_class::apply_chunking_settings($general_settings, $settings);
                if (is_wp_error($chunk_result)) {
                    $this->send_wp_error($chunk_result);
                    return;
                }
                $general_dirty = $chunk_result || $general_dirty;
            }
            // Remove from specific settings payload
            unset($settings['chunk_avg_chars_per_token'], $settings['chunk_max_tokens_per_chunk'], $settings['chunk_overlap_tokens']);
        }
        // OpenAI File Search chunking settings (Pro only)
        if (
            isset($settings['openai_file_search_chunking_mode']) ||
            isset($settings['openai_file_search_max_chunk_size_tokens']) ||
            isset($settings['openai_file_search_chunk_overlap_tokens'])
        ) {
            $is_pro = \WPAICG\aipkit_dashboard::is_pro_plan();
            if ($is_pro && class_exists($batch_settings_class)) {
                $chunk_result = $batch_settings_class::apply_openai_chunking_settings($general_settings, $settings);
                if (is_wp_error($chunk_result)) {
                    $this->send_wp_error($chunk_result);
                    return;
                }
                $general_dirty = $chunk_result || $general_dirty;
            }
            unset($settings['openai_file_search_chunking_mode'], $settings['openai_file_search_max_chunk_size_tokens'], $settings['openai_file_search_chunk_overlap_tokens']);
        }

        // File upload embedding batch settings (Pro only; implementation lives under lib).
        if (class_exists($batch_settings_class) && $batch_settings_class::has_payload_keys($settings)) {
            $batch_result = $batch_settings_class::apply_payload_to_general_settings(
                $general_settings,
                $settings,
                \WPAICG\aipkit_dashboard::is_pro_plan()
            );
            if (is_wp_error($batch_result)) {
                $this->send_wp_error($batch_result);
                return;
            }
            if ($batch_result === true) {
                $general_dirty = true;
            }
            $settings = $batch_settings_class::remove_payload_keys($settings);
        }

        if ($general_dirty) {
            update_option('aipkit_training_general_settings', $general_settings);
        }

        if (empty($settings)) {
            wp_send_json_success(['message' => __('Indexing settings saved successfully.', 'gpt3-ai-content-generator')]);
            return;
        }

        // Sanitize the settings array
        $sanitized_settings = [];
        foreach ($settings as $cpt => $cpt_settings) {
            $cpt = sanitize_key($cpt);
            $sanitized_settings[$cpt] = [
                'fields' => [],
                'taxonomies' => [],
                'woo_attributes' => [],
                'basic_labels' => [],
            ];
            
            // Handle basic labels
            if (isset($cpt_settings['basic_labels']) && is_array($cpt_settings['basic_labels'])) {
                $allowed_basic_labels = ['source_url', 'title', 'excerpt', 'content'];
                foreach ($cpt_settings['basic_labels'] as $key => $label) {
                    if (in_array($key, $allowed_basic_labels)) {
                        $sanitized_settings[$cpt]['basic_labels'][sanitize_key($key)] = sanitize_text_field($label);
                    }
                }
            }
            
            if (isset($cpt_settings['fields']) && is_array($cpt_settings['fields'])) {
                foreach ($cpt_settings['fields'] as $key => $config) {
                    // Preserve original meta key (may include ":" etc.)
                    $key = is_string($key) ? wp_unslash($key) : $key;
                    // Ensure enabled is properly converted to boolean
                    $enabled = isset($config['enabled']) && $config['enabled'];
                    $sanitized_settings[$cpt]['fields'][$key] = [
                        'enabled' => (bool) $enabled,
                        'label'   => sanitize_text_field($config['label'] ?? ''),
                    ];
                }
            }
            if (isset($cpt_settings['taxonomies']) && is_array($cpt_settings['taxonomies'])) {
                foreach ($cpt_settings['taxonomies'] as $key => $config) {
                    // Preserve original taxonomy slug key
                    $key = is_string($key) ? wp_unslash($key) : $key;
                    // Ensure enabled is properly converted to boolean
                    $enabled = isset($config['enabled']) && $config['enabled'];
                    $sanitized_settings[$cpt]['taxonomies'][$key] = [
                        'enabled' => (bool) $enabled,
                        'label'   => sanitize_text_field($config['label'] ?? ''),
                    ];
                }
            }
            if (isset($cpt_settings['woo_attributes']) && is_array($cpt_settings['woo_attributes'])) {
                foreach ($cpt_settings['woo_attributes'] as $key => $config) {
                    // Preserve original key
                    $key = is_string($key) ? wp_unslash($key) : $key;
                    // Ensure enabled is properly converted to boolean
                    $enabled = isset($config['enabled']) && $config['enabled'];
                    $sanitized_settings[$cpt]['woo_attributes'][$key] = [
                        'enabled' => (bool) $enabled,
                        'label'   => sanitize_text_field($config['label'] ?? ''),
                    ];
                }
            }
        }

        update_option('aipkit_indexing_field_settings', $sanitized_settings, 'no');

        wp_send_json_success(['message' => __('Indexing settings saved successfully.', 'gpt3-ai-content-generator')]);
    }

    /**
     * Fetches public meta keys for a given post type by sampling recent posts.
     * @param string $post_type
     * @param int $limit
     * @return array
     */
    private function get_public_meta_keys_for_post_type(string $post_type, int $limit = 10): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Efficiently sampling meta keys.
        $keys = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s AND meta_key NOT LIKE %s ORDER BY pm.meta_id DESC LIMIT 100",
            $post_type,
            $wpdb->esc_like('_') . '%'
        ));
        $formatted_keys = [];
        if ($keys) {
            foreach ($keys as $key) {
                $formatted_keys[$key] = ucwords(str_replace(['_', '-'], ' ', $key));
            }
        }
        return $formatted_keys;
    }
}
