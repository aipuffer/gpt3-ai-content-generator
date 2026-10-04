<?php

namespace WPAICG\Dashboard\Ajax;

use WP_Error;
use WPAICG\AIPKit_Providers;
use WPAICG\aipkit_dashboard;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles AJAX requests for Chroma collection operations.
 */
class AIPKit_Vector_Store_Chroma_Ajax_Handler extends BaseDashboardAjaxHandler
{
    private $vector_store_manager;
    private $vector_store_registry;
    private $ai_caller;
    private $data_source_table_name;
    private $wpdb;

    public function __construct()
    {
        $dependencies = $this->bootstrap_vector_store_ajax_dependencies();
        $this->wpdb = $dependencies['wpdb'];
        $this->data_source_table_name = $dependencies['data_source_table_name'];
        $this->vector_store_manager = $dependencies['vector_store_manager'];
        $this->vector_store_registry = $dependencies['vector_store_registry'];
        $this->ai_caller = $dependencies['ai_caller'];
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function _get_chroma_config()
    {
        if (!class_exists(\WPAICG\AIPKit_Providers::class)) {
            $providers_path = WPAICG_PLUGIN_DIR . 'classes/ai/settings.php';
            if (file_exists($providers_path)) {
                require_once $providers_path;
            } else {
                return new WP_Error('dependency_missing', __('AIPKit_Providers class not found for Chroma config.', 'gpt3-ai-content-generator'));
            }
        }

        $chroma_data = AIPKit_Providers::get_provider_data('Chroma');
        if (empty($chroma_data['url'])) {
            return new WP_Error('missing_chroma_url', __('Chroma URL is not configured in global settings.', 'gpt3-ai-content-generator'));
        }

        return [
            'url' => $chroma_data['url'],
            'api_key' => $chroma_data['api_key'] ?? '',
            'tenant' => $chroma_data['tenant'] ?? 'default_tenant',
            'database' => $chroma_data['database'] ?? 'default_database',
        ];
    }

    public function _log_vector_data_source_entry(array $log_data): void
    {
        $defaults = [
            'user_id' => get_current_user_id(),
            'timestamp' => current_time('mysql', 1),
            'provider' => 'Chroma',
            'vector_store_id' => 'unknown',
            'vector_store_name' => null,
            'post_id' => null,
            'post_title' => null,
            'status' => 'info',
            'message' => '',
            'indexed_content' => null,
            'file_id' => null,
            'batch_id' => null,
            'embedding_provider' => null,
            'embedding_model' => null,
            'source_type_for_log' => null,
        ];
        $data_to_insert = wp_parse_args($log_data, $defaults);

        $source_type = $data_to_insert['source_type_for_log'] ?? ($data_to_insert['post_id'] ? 'wordpress_post' : 'unknown');
        $should_truncate = !in_array($source_type, ['text_entry_global_form', 'file_upload_global_form', 'text_entry_chroma_direct', 'file_upload_chroma_direct', 'chatbot_training_text', 'chatbot_training_qa'], true);
        if ($should_truncate && is_string($data_to_insert['indexed_content']) && mb_strlen($data_to_insert['indexed_content']) > 1000) {
            $data_to_insert['indexed_content'] = mb_substr($data_to_insert['indexed_content'], 0, 997) . '...';
        }
        unset($data_to_insert['source_type_for_log']);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $this->wpdb->insert($this->data_source_table_name, $data_to_insert);
        if (!$result) {
            return;
        }

        $store_id = $log_data['vector_store_id'] ?? null;
        if ($store_id) {
            $cache_group = 'aipkit_vector_logs';
            $cache_key_logs = 'chroma_logs_' . sanitize_key($store_id);
            $cache_key_count = 'chroma_logs_count_' . sanitize_key($store_id);
            wp_cache_delete($cache_key_count, $cache_group);
            for ($i = 1; $i <= 5; $i++) {
                wp_cache_delete($cache_key_logs . '_page_' . $i, $cache_group);
            }
        }
    }

    public function ajax_list_collections_chroma()
    {
        $permission_check = $this->check_any_module_access_permissions(['sources', 'chatbot'], 'aipkit_vector_store_chroma_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        \WPAICG\Dashboard\Ajax\Chroma\HandlerCollections\_aipkit_chroma_ajax_list_collections_logic($this);
    }

    public function ajax_create_collection_chroma()
    {
        $permission_check = $this->check_any_module_access_permissions(['sources', 'chatbot'], 'aipkit_vector_store_chroma_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        \WPAICG\Dashboard\Ajax\Chroma\HandlerCollections\_aipkit_chroma_ajax_create_collection_logic($this);
    }

    public function ajax_delete_collection_chroma()
    {
        $permission_check = $this->check_any_module_access_permissions(['sources', 'chatbot'], 'aipkit_vector_store_chroma_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        \WPAICG\Dashboard\Ajax\Chroma\HandlerCollections\_aipkit_chroma_ajax_delete_collection_logic($this);
    }

    public function ajax_upsert_to_chroma_collection()
    {
        $permission_check = $this->check_any_module_access_permissions(['sources', 'chatbot'], 'aipkit_vector_store_chroma_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        \WPAICG\Dashboard\Ajax\Chroma\HandlerCollections\_aipkit_chroma_ajax_upsert_to_collection_logic($this);
    }

    public function ajax_upload_file_and_upsert_to_chroma()
    {
        $permission_check = $this->check_any_module_access_permissions(['sources', 'chatbot'], 'aipkit_vector_store_chroma_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        if (!$this->vector_store_manager || !$this->ai_caller || !class_exists(\WPAICG\Includes\AIPKit_Upload_Utils::class)) {
            $this->send_wp_error(new WP_Error('deps_missing_chroma_upload', __('Required components for Chroma file upload are missing.', 'gpt3-ai-content-generator'), ['status' => 500]));
            return;
        }

        if (!class_exists(aipkit_dashboard::class) || !aipkit_dashboard::is_pro_plan()) {
            $this->send_wp_error(new WP_Error('pro_feature_chroma_upload', __('File upload to Chroma is a Pro feature. Please upgrade.', 'gpt3-ai-content-generator'), ['status' => 403]));
            return;
        }

        $fn_file_path = WPAICG_LIB_DIR . 'knowledge-base/upload-vectors.php';
        if (!file_exists($fn_file_path)) {
            $this->send_wp_error(new WP_Error(
                'required_files_missing_chroma_upload',
                __('Some required files seem to be missing for Chroma file uploads. Please reinstall the Pro version of AI Puffer and try again.', 'gpt3-ai-content-generator'),
                ['status' => 500]
            ));
            return;
        }

        require_once $fn_file_path;
        \WPAICG\Lib\VectorStores\FileUpload\Chroma\dispatch_upload_action($this);
    }

    public function get_vector_store_manager(): ?\WPAICG\Vector\AIPKit_Vector_Store_Manager
    {
        return $this->vector_store_manager;
    }

    public function get_vector_store_registry(): ?\WPAICG\Vector\AIPKit_Vector_Store_Registry
    {
        return $this->vector_store_registry;
    }

    public function get_ai_caller(): ?\WPAICG\Core\AIPKit_AI_Caller
    {
        return $this->ai_caller;
    }

    public function get_wpdb(): \wpdb
    {
        return $this->wpdb;
    }

    public function get_data_source_table_name(): string
    {
        return $this->data_source_table_name;
    }
}

namespace WPAICG\Dashboard\Ajax\Chroma\HandlerCollections;

use WP_Error;
use WPAICG\Dashboard\Ajax\AIPKit_Vector_Store_Chroma_Ajax_Handler;
use WPAICG\Vector\AIPKit_Vector_Text_Ingestion_Service;

if (!defined('ABSPATH')) {
    exit;
}

function _aipkit_chroma_ajax_create_collection_logic(AIPKit_Vector_Store_Chroma_Ajax_Handler $handler_instance): void
{
    $vector_store_manager = $handler_instance->get_vector_store_manager();
    $vector_store_registry = $handler_instance->get_vector_store_registry();

    if (!$vector_store_manager || !$vector_store_registry) {
        $handler_instance->send_wp_error(new WP_Error('manager_not_ready_create_chroma', __('Vector Store components not available for Chroma.', 'gpt3-ai-content-generator'), ['status' => 500]));
        return;
    }

    $chroma_config = $handler_instance->_get_chroma_config();
    if (is_wp_error($chroma_config)) {
        $handler_instance->send_wp_error($chroma_config);
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $post_data = wp_unslash($_POST);
    $collection_name = isset($post_data['name']) ? sanitize_text_field($post_data['name']) : '';

    if ($collection_name === '') {
        $handler_instance->send_wp_error(new WP_Error('missing_name_chroma_create', __('Collection name is required.', 'gpt3-ai-content-generator'), ['status' => 400]));
        return;
    }

    $create_result = $vector_store_manager->create_index_if_not_exists('Chroma', $collection_name, [], $chroma_config);
    if (is_wp_error($create_result)) {
        $handler_instance->send_wp_error($create_result);
        return;
    }

    $collection = is_array($create_result) ? $create_result : ['name' => $collection_name, 'id' => $collection_name];
    $collection['name'] = $collection['name'] ?? $collection_name;
    $collection = _aipkit_chroma_normalize_collection_for_ui($collection);
    $vector_store_registry->add_registered_store('Chroma', $collection);

    wp_send_json_success([
        'collection' => $collection,
        'message' => __('Chroma collection created/verified.', 'gpt3-ai-content-generator'),
    ]);
}

function _aipkit_chroma_ajax_delete_collection_logic(AIPKit_Vector_Store_Chroma_Ajax_Handler $handler_instance): void
{
    $vector_store_manager = $handler_instance->get_vector_store_manager();
    $vector_store_registry = $handler_instance->get_vector_store_registry();
    $wpdb = $handler_instance->get_wpdb();
    $data_source_table_name = $handler_instance->get_data_source_table_name();

    if (!$vector_store_manager || !$vector_store_registry) {
        $handler_instance->send_wp_error(new WP_Error('manager_not_ready_delete_chroma', __('Vector Store components not available for Chroma.', 'gpt3-ai-content-generator'), ['status' => 500]));
        return;
    }

    $chroma_config = $handler_instance->_get_chroma_config();
    if (is_wp_error($chroma_config)) {
        $handler_instance->send_wp_error($chroma_config);
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $collection_name = isset($_POST['collection_name']) ? sanitize_text_field(wp_unslash($_POST['collection_name'])) : '';
    if ($collection_name === '') {
        $handler_instance->send_wp_error(new WP_Error('missing_name_delete_chroma', __('Collection name is required for deletion.', 'gpt3-ai-content-generator'), ['status' => 400]));
        return;
    }

    $delete_result = $vector_store_manager->delete_index('Chroma', $collection_name, $chroma_config);
    if (is_wp_error($delete_result)) {
        $handler_instance->send_wp_error($delete_result);
        return;
    }

    $vector_store_registry->remove_registered_store('Chroma', $collection_name);
    wp_cache_delete('chroma_logs_' . sanitize_key($collection_name), 'aipkit_vector_logs');
    wp_cache_delete('chroma_logs_count_' . sanitize_key($collection_name), 'aipkit_vector_logs');

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->delete($data_source_table_name, ['provider' => 'Chroma', 'vector_store_id' => $collection_name], ['%s', '%s']);

    wp_send_json_success(['message' => __('Chroma collection deleted successfully.', 'gpt3-ai-content-generator')]);
}

function _aipkit_chroma_normalize_collection_for_ui(array $collection): array
{
    $api_id = isset($collection['id']) ? (string) $collection['id'] : '';
    $name = isset($collection['name']) && (string) $collection['name'] !== ''
        ? (string) $collection['name']
        : $api_id;

    $collection['chroma_id'] = $api_id;
    $collection['id'] = $name;
    $collection['name'] = $name;
    $collection['provider'] = 'Chroma';

    return $collection;
}

function _aipkit_chroma_ajax_list_collections_logic(AIPKit_Vector_Store_Chroma_Ajax_Handler $handler_instance): void
{
    $vector_store_manager = $handler_instance->get_vector_store_manager();
    $vector_store_registry = $handler_instance->get_vector_store_registry();

    if (!$vector_store_manager || !$vector_store_registry) {
        $handler_instance->send_wp_error(new WP_Error('manager_not_ready_list_chroma', __('Vector Store components not available for Chroma.', 'gpt3-ai-content-generator'), ['status' => 500]));
        return;
    }

    $chroma_config = $handler_instance->_get_chroma_config();
    if (is_wp_error($chroma_config)) {
        $handler_instance->send_wp_error($chroma_config);
        return;
    }

    $response = $vector_store_manager->list_all_indexes('Chroma', $chroma_config, 100);
    if (is_wp_error($response)) {
        $handler_instance->send_wp_error($response);
        return;
    }

    $detailed_collections = [];
    foreach ((array) $response as $collection_summary) {
        if (!is_array($collection_summary)) {
            continue;
        }
        $collection_name = $collection_summary['name'] ?? ($collection_summary['id'] ?? null);
        if (!$collection_name) {
            continue;
        }
        $details = $vector_store_manager->describe_single_index('Chroma', (string) $collection_name, $chroma_config);
        $collection = is_wp_error($details) ? $collection_summary : array_merge($collection_summary, $details);
        $detailed_collections[] = _aipkit_chroma_normalize_collection_for_ui($collection);
    }

    $detailed_collections = $vector_store_registry->replace_provider_cache('Chroma', $detailed_collections);

    wp_send_json_success([
        'collections' => $detailed_collections,
        'message' => __('Chroma collections synced successfully.', 'gpt3-ai-content-generator'),
    ]);
}

function _aipkit_chroma_ajax_upsert_to_collection_logic(AIPKit_Vector_Store_Chroma_Ajax_Handler $handler_instance): void
{
    $vector_store_manager = $handler_instance->get_vector_store_manager();
    if (!$vector_store_manager) {
        $handler_instance->send_wp_error(new WP_Error('manager_not_ready_upsert_chroma', __('Vector Store Manager not available for Chroma upsert.', 'gpt3-ai-content-generator'), ['status' => 500]));
        return;
    }

    $chroma_config = $handler_instance->_get_chroma_config();
    if (is_wp_error($chroma_config)) {
        $handler_instance->send_wp_error($chroma_config);
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $post_data = wp_unslash($_POST);
    $collection_name = isset($post_data['collection_name']) ? sanitize_text_field($post_data['collection_name']) : '';
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON string, decoded and validated below.
    $vectors_json = isset($post_data['vectors']) ? $post_data['vectors'] : '';
    $embedding_provider = isset($post_data['embedding_provider']) ? sanitize_key($post_data['embedding_provider']) : null;
    $embedding_model = isset($post_data['embedding_model']) ? sanitize_text_field($post_data['embedding_model']) : null;
    $original_text_content = isset($post_data['original_text_content']) ? wp_kses_post($post_data['original_text_content']) : null;
    $text_content = isset($post_data['text_content']) ? wp_kses_post($post_data['text_content']) : '';

    if ($collection_name === '') {
        $handler_instance->send_wp_error(new WP_Error('missing_collection_name_chroma_upsert', __('Chroma collection name is required.', 'gpt3-ai-content-generator'), ['status' => 400]));
        return;
    }

    if ($text_content !== '') {
        if (empty($embedding_provider) || empty($embedding_model)) {
            $handler_instance->send_wp_error(new WP_Error('missing_embedding_config_chroma_text', __('Embedding provider and model are required for Chroma text indexing.', 'gpt3-ai-content-generator'), ['status' => 400]));
            return;
        }

        $metadata = _aipkit_chroma_decode_text_metadata($post_data);
        if (is_wp_error($metadata)) {
            $handler_instance->send_wp_error($metadata);
            return;
        }

        $service = new AIPKit_Vector_Text_Ingestion_Service($vector_store_manager, $handler_instance->get_ai_caller());
        $result = $service->ingest_text('Chroma', $collection_name, $text_content, $embedding_provider, $embedding_model, $metadata, $chroma_config);
        $content_for_log = $original_text_content !== null ? $original_text_content : $text_content;

        if (is_wp_error($result)) {
            $handler_instance->_log_vector_data_source_entry([
                'vector_store_id' => $collection_name,
                'vector_store_name' => $collection_name,
                'status' => 'failed',
                'message' => 'Text content indexing failed: ' . $result->get_error_message(),
                'embedding_provider' => $embedding_provider,
                'embedding_model' => $embedding_model,
                'indexed_content' => $content_for_log,
                'file_id' => isset($metadata['vector_id']) ? (string) $metadata['vector_id'] : null,
                'source_type_for_log' => isset($metadata['source']) ? (string) $metadata['source'] : 'text_entry_global_form',
            ]);
            $handler_instance->send_wp_error($result);
            return;
        }

        $chunk_count = (int) ($result['total_chunks'] ?? 0);
        $handler_instance->_log_vector_data_source_entry([
            'vector_store_id' => $collection_name,
            'vector_store_name' => $collection_name,
            'status' => 'indexed',
            'message' => sprintf('Text content submitted for indexing. Chunks: %d.', $chunk_count),
            'embedding_provider' => $result['embedding_provider'] ?? $embedding_provider,
            'embedding_model' => $embedding_model,
            'indexed_content' => $content_for_log,
            'file_id' => $result['parent_vector_id'] ?? null,
            'source_type_for_log' => $result['source_type'] ?? 'text_entry_global_form',
        ]);

        wp_send_json_success([
            'message' => __('Text content upserted to Chroma successfully.', 'gpt3-ai-content-generator'),
            'result' => $result,
        ]);
        return;
    }

    if ($vectors_json === '') {
        $handler_instance->send_wp_error(new WP_Error('missing_vectors_chroma_upsert', __('Records data is required for Chroma upsert.', 'gpt3-ai-content-generator'), ['status' => 400]));
        return;
    }

    $records = json_decode($vectors_json, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($records) || empty($records)) {
        $handler_instance->send_wp_error(new WP_Error('invalid_records_json_chroma_upsert', __('Invalid or empty records JSON format for Chroma.', 'gpt3-ai-content-generator'), ['status' => 400]));
        return;
    }

    $result = $vector_store_manager->upsert_vectors('Chroma', $collection_name, ['points' => $records], $chroma_config);

    $first_record = is_array($records[0] ?? null) ? $records[0] : [];
    $record_id = $first_record['id'] ?? null;
    $metadata = isset($first_record['payload']) && is_array($first_record['payload'])
        ? $first_record['payload']
        : (isset($first_record['metadata']) && is_array($first_record['metadata']) ? $first_record['metadata'] : []);
    $source_type_for_log = $metadata['source'] ?? 'unknown';
    $wp_post_id_for_log = null;
    $wp_post_title_for_log = null;
    $content_for_log = null;

    if ($source_type_for_log === 'wordpress_post') {
        if (!empty($metadata['post_id'])) {
            $wp_post_id_for_log = absint($metadata['post_id']);
        }
        if ($wp_post_id_for_log) {
            $wp_post_title_for_log = get_the_title($wp_post_id_for_log) ?: 'Post ' . $wp_post_id_for_log;
        }
        $content_for_log = $original_text_content;
    } elseif (in_array($source_type_for_log, ['text_entry_global_form', 'file_upload_global_form', 'text_entry_chroma_direct', 'chatbot_training_text', 'chatbot_training_qa'], true) && $original_text_content !== null) {
        $content_for_log = $original_text_content;
        if ($source_type_for_log === 'file_upload_global_form' && !empty($metadata['filename'])) {
            $wp_post_title_for_log = sanitize_file_name((string) $metadata['filename']);
        }
    }

    if (is_wp_error($result)) {
        $handler_instance->_log_vector_data_source_entry([
            'vector_store_id' => $collection_name,
            'vector_store_name' => $collection_name,
            'post_id' => $wp_post_id_for_log,
            'post_title' => $wp_post_title_for_log,
            'status' => 'failed',
            'message' => 'Chroma upsert failed: ' . $result->get_error_message(),
            'embedding_provider' => $embedding_provider,
            'embedding_model' => $embedding_model,
            'indexed_content' => $content_for_log,
            'file_id' => $record_id,
            'source_type_for_log' => $source_type_for_log,
        ]);
        $handler_instance->send_wp_error($result);
        return;
    }

    $upserted_count = isset($result['upserted_count']) ? absint($result['upserted_count']) : count($records);
    $handler_instance->_log_vector_data_source_entry([
        'vector_store_id' => $collection_name,
        'vector_store_name' => $collection_name,
        'post_id' => $wp_post_id_for_log,
        'post_title' => $wp_post_title_for_log,
        'status' => 'indexed',
        'message' => 'Chroma records upserted. Count: ' . $upserted_count,
        'embedding_provider' => $embedding_provider,
        'embedding_model' => $embedding_model,
        'indexed_content' => $content_for_log,
        'file_id' => $record_id,
        'source_type_for_log' => $source_type_for_log,
    ]);

    wp_send_json_success([
        'message' => __('Records upserted to Chroma successfully.', 'gpt3-ai-content-generator'),
        'result' => $result,
    ]);
}

/**
 * @param array<string,mixed> $post_data
 * @return array<string,mixed>|WP_Error
 */
function _aipkit_chroma_decode_text_metadata(array $post_data)
{
    $metadata = [];
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON string, decoded and sanitized by the ingestion service.
    $metadata_json = isset($post_data['metadata']) ? (string) $post_data['metadata'] : '';
    if ($metadata_json !== '') {
        $decoded = json_decode($metadata_json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return new WP_Error('invalid_chroma_text_metadata', __('Invalid metadata JSON for Chroma text indexing.', 'gpt3-ai-content-generator'), ['status' => 400]);
        }
        $metadata = $decoded;
    }
    if (!empty($post_data['source_type'])) {
        $metadata['source'] = sanitize_key((string) $post_data['source_type']);
    }
    if (!empty($post_data['vector_id'])) {
        $metadata['vector_id'] = sanitize_text_field((string) $post_data['vector_id']);
    }
    if (!empty($post_data['source_context'])) {
        $metadata['source_context'] = sanitize_text_field((string) $post_data['source_context']);
    }

    return $metadata;
}
