<?php


namespace WPAICG\Dashboard\Ajax;

use WP_Error;
use WPAICG\AIPKit_Providers;
use WPAICG\Vector\AIPKit_Vector_Store_Manager;
use WPAICG\Vector\AIPKit_Vector_Store_Registry;
use WPAICG\aipkit_dashboard;


if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Handles AJAX requests for Qdrant Vector Store operations.
 * Keeps shared operations here and delegates paid uploads to lib.
 */
class AIPKit_Vector_Store_Qdrant_Ajax_Handler extends BaseDashboardAjaxHandler
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
    public function _get_qdrant_config()
    {
        if (!class_exists(\WPAICG\AIPKit_Providers::class)) {
            $providers_path = WPAICG_PLUGIN_DIR . 'classes/ai/settings.php';
            if (file_exists($providers_path)) {
                require_once $providers_path;
            } else {
                return new WP_Error('dependency_missing', 'AIPKit_Providers class not found for Qdrant config.');
            }
        }
        $qdrant_data = AIPKit_Providers::get_provider_data('Qdrant');
        if (empty($qdrant_data['url'])) {
            return new WP_Error('missing_qdrant_url', __('Qdrant URL is not configured in global settings.', 'gpt3-ai-content-generator'));
        }
        if (empty($qdrant_data['api_key'])) {
            return new WP_Error('missing_qdrant_api_key', __('Qdrant API Key is not configured in global settings (required for Qdrant Cloud).', 'gpt3-ai-content-generator'));
        }
        return ['url' => $qdrant_data['url'], 'api_key' => $qdrant_data['api_key']];
    }

    /**
     * Logs collection activity and invalidates the affected log caches.
     * @param array $log_data
     */
    public function _log_vector_data_source_entry(array $log_data): void
    {
        $defaults = [
            'user_id' => get_current_user_id(),
            'timestamp' => current_time('mysql', 1),
            'provider' => 'Qdrant',
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
        $should_truncate = !in_array(
            $source_type,
            ['text_entry_global_form', 'file_upload_global_form', 'text_entry_qdrant_direct', 'file_upload_qdrant_direct', 'chatbot_training_text', 'chatbot_training_qa'],
            true
        );

        if ($should_truncate && is_string($data_to_insert['indexed_content']) && mb_strlen($data_to_insert['indexed_content']) > 1000) {
            $data_to_insert['indexed_content'] = mb_substr($data_to_insert['indexed_content'], 0, 997) . '...';
        }
        unset($data_to_insert['source_type_for_log']);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $this->wpdb->insert($this->data_source_table_name, $data_to_insert);
        if ($result) {
            $provider = $log_data['provider'] ?? 'Qdrant';
            $store_id = $log_data['vector_store_id'] ?? null;
            if ($store_id) {
                $cache_key_logs = strtolower($provider) . '_logs_' . sanitize_key($store_id);
                $cache_key_count = strtolower($provider) . '_logs_count_' . sanitize_key($store_id);
                $cache_group = 'aipkit_vector_logs';
                wp_cache_delete($cache_key_count, $cache_group);
                for ($i = 1; $i <= 5; $i++) {
                    wp_cache_delete($cache_key_logs . '_page_' . $i, $cache_group);
                }
            }
        }
    }


    public function ajax_list_collections_qdrant()
    {
        $permission_check = $this->check_any_module_access_permissions(
            ['sources', 'chatbot'],
            'aipkit_vector_store_qdrant_nonce'
        );
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        \WPAICG\Dashboard\Ajax\Qdrant\HandlerCollections\_aipkit_qdrant_ajax_list_collections_logic($this);
    }

    public function ajax_create_collection_qdrant()
    {
        $permission_check = $this->check_any_module_access_permissions(
            ['sources', 'chatbot'],
            'aipkit_vector_store_qdrant_nonce'
        );
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        \WPAICG\Dashboard\Ajax\Qdrant\HandlerCollections\_aipkit_qdrant_ajax_create_collection_logic($this);
    }

    public function ajax_delete_collection_qdrant()
    {
        $permission_check = $this->check_any_module_access_permissions(['sources', 'chatbot'], 'aipkit_vector_store_qdrant_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        \WPAICG\Dashboard\Ajax\Qdrant\HandlerCollections\_aipkit_qdrant_ajax_delete_collection_logic($this);
    }

    public function ajax_upsert_to_qdrant_collection()
    {
        $permission_check = $this->check_any_module_access_permissions(
            ['sources', 'chatbot'],
            'aipkit_vector_store_qdrant_nonce'
        );
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        \WPAICG\Dashboard\Ajax\Qdrant\HandlerCollections\_aipkit_qdrant_ajax_upsert_to_collection_logic($this);
    }

    public function ajax_upload_file_and_upsert_to_qdrant()
    {
        $permission_check = $this->check_any_module_access_permissions(['sources', 'chatbot'], 'aipkit_vector_store_qdrant_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        if (!$this->vector_store_manager || !$this->ai_caller || !class_exists(\WPAICG\Includes\AIPKit_Upload_Utils::class)) {
            $this->send_wp_error(new WP_Error('deps_missing_qdrant_upload', __('Required components for Qdrant file upload are missing.', 'gpt3-ai-content-generator'), ['status' => 500]));
            return;
        }

        if (!aipkit_dashboard::is_pro_plan()) {
            $this->send_wp_error(new WP_Error('pro_feature_qdrant_upload', __('File upload to Qdrant is a Pro feature. Please upgrade.', 'gpt3-ai-content-generator'), ['status' => 403]));
            return;
        }

        $fn_file_path = WPAICG_LIB_DIR . 'knowledge-base/upload-vectors.php';
        if (!file_exists($fn_file_path)) {
            $this->send_wp_error(new WP_Error(
                'required_files_missing_qdrant_upload',
                __('Some required files seem to be missing for Qdrant file uploads. Please reinstall the Pro version of AI Puffer and try again.', 'gpt3-ai-content-generator'),
                ['status' => 500]
            ));
            return;
        }

        require_once $fn_file_path;
        \WPAICG\Lib\VectorStores\FileUpload\Qdrant\dispatch_upload_action($this);
    }
    public function get_vector_store_manager(): ?AIPKit_Vector_Store_Manager
    {
        return $this->vector_store_manager;
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
    public function get_vector_store_registry(): ?AIPKit_Vector_Store_Registry
    {
        return $this->vector_store_registry;
    }

}

namespace WPAICG\Dashboard\Ajax\Qdrant\HandlerCollections;

use WP_Error;
use WPAICG\Dashboard\Ajax\AIPKit_Vector_Store_Qdrant_Ajax_Handler;
use WPAICG\Vector\AIPKit_Vector_Text_Ingestion_Service;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles the logic for creating a Qdrant collection.
 * Called by AIPKit_Vector_Store_Qdrant_Ajax_Handler::ajax_create_collection_qdrant().
 *
 * @param AIPKit_Vector_Store_Qdrant_Ajax_Handler $handler_instance
 * @return void
 */
function _aipkit_qdrant_ajax_create_collection_logic(AIPKit_Vector_Store_Qdrant_Ajax_Handler $handler_instance): void
{
    $vector_store_manager = $handler_instance->get_vector_store_manager();
    $vector_store_registry = $handler_instance->get_vector_store_registry();

    if (!$vector_store_manager || !$vector_store_registry) {
        $error_message = __('Vector Store components not available for Qdrant Create.', 'gpt3-ai-content-generator');
        $handler_instance->send_wp_error(new WP_Error('manager_not_ready_create_qdrant', $error_message, ['status' => 500]));
        return;
    }

    $qdrant_config = $handler_instance->_get_qdrant_config();
    if (is_wp_error($qdrant_config)) {
        $handler_instance->send_wp_error($qdrant_config);
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $post_data = wp_unslash($_POST);
    $collection_name = isset($post_data['name']) ? sanitize_text_field($post_data['name']) : '';
    $dimension = isset($post_data['dimension']) ? absint($post_data['dimension']) : 0;
    $metric = isset($post_data['metric']) ? sanitize_text_field($post_data['metric']) : 'Cosine'; // Default metric

    if (empty($collection_name)) {
        $error_message = __('Collection name is required.', 'gpt3-ai-content-generator');
        $handler_instance->send_wp_error(new WP_Error('missing_name_qdrant_create', $error_message, ['status' => 400]));
        return;
    }
    if ($dimension <= 0) {
        $error_message = __('Vector dimension must be a positive integer.', 'gpt3-ai-content-generator');
        $handler_instance->send_wp_error(new WP_Error('invalid_dimension_qdrant_create', $error_message, ['status' => 400]));
        return;
    }
    if (!in_array(ucfirst(strtolower($metric)), ['Cosine', 'Euclid', 'Dot'])) { // Validate metric
        $metric = 'Cosine';
    }

    $index_config = ['dimension' => $dimension, 'metric' => $metric];

    $create_result = $vector_store_manager->create_index_if_not_exists('Qdrant', $collection_name, $index_config, $qdrant_config);

    if (is_wp_error($create_result)) {
        $handler_instance->send_wp_error($create_result);
        return;
    }

    // Check for a successful Qdrant collection description structure
    if (is_array($create_result) && isset($create_result['status']) && in_array($create_result['status'], ['green', 'yellow', 'red']) && isset($create_result['config'])) {
        $vector_store_registry->add_registered_store('Qdrant', $create_result);
        $log_message = __('Qdrant collection created/verified.', 'gpt3-ai-content-generator');
        wp_send_json_success(['collection' => $create_result, 'message' => $log_message]);
    } else {
        $log_message = __('Qdrant collection creation response was malformed.', 'gpt3-ai-content-generator');
        $handler_instance->send_wp_error(new WP_Error('qdrant_create_malformed_response', $log_message));
    }
}

/**
 * Handles the logic for deleting a Qdrant collection.
 * Called by AIPKit_Vector_Store_Qdrant_Ajax_Handler::ajax_delete_collection_qdrant().
 *
 * @param AIPKit_Vector_Store_Qdrant_Ajax_Handler $handler_instance
 * @return void
 */
function _aipkit_qdrant_ajax_delete_collection_logic(AIPKit_Vector_Store_Qdrant_Ajax_Handler $handler_instance): void
{
    $vector_store_manager = $handler_instance->get_vector_store_manager();
    $vector_store_registry = $handler_instance->get_vector_store_registry();
    $wpdb = $handler_instance->get_wpdb();
    $data_source_table_name = $handler_instance->get_data_source_table_name();

    if (!$vector_store_manager || !$vector_store_registry) {
        $handler_instance->send_wp_error(new WP_Error('manager_not_ready_delete_qdrant', __('Vector Store components not available.', 'gpt3-ai-content-generator'), ['status' => 500]));
        return;
    }

    $qdrant_config = $handler_instance->_get_qdrant_config();
    if (is_wp_error($qdrant_config)) {
        $handler_instance->send_wp_error($qdrant_config);
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $collection_name = isset($_POST['collection_name']) ? sanitize_text_field(wp_unslash($_POST['collection_name'])) : '';
    if (empty($collection_name)) {
        $handler_instance->send_wp_error(new WP_Error('missing_name_delete_qdrant', __('Collection name is required for deletion.', 'gpt3-ai-content-generator'), ['status' => 400]));
        return;
    }

    $delete_result = $vector_store_manager->delete_index('Qdrant', $collection_name, $qdrant_config);
    if (is_wp_error($delete_result)) {
        $handler_instance->send_wp_error($delete_result);
        return;
    }

    $vector_store_registry->remove_registered_store('Qdrant', $collection_name);

    // Invalidate the cache for this collection's logs before deleting
    $cache_key = 'qdrant_logs_' . sanitize_key($collection_name);
    $cache_group = 'aipkit_vector_logs';
    wp_cache_delete($cache_key, $cache_group);

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->delete($data_source_table_name, ['provider' => 'Qdrant', 'vector_store_id' => $collection_name], ['%s', '%s']);

    wp_send_json_success(['message' => __('Qdrant collection deleted successfully.', 'gpt3-ai-content-generator')]);
}

/**
 * Handles the logic for listing Qdrant collections.
 * Called by AIPKit_Vector_Store_Qdrant_Ajax_Handler::ajax_list_collections_qdrant().
 *
 * @param AIPKit_Vector_Store_Qdrant_Ajax_Handler $handler_instance
 * @return void
 */
function _aipkit_qdrant_ajax_list_collections_logic(AIPKit_Vector_Store_Qdrant_Ajax_Handler $handler_instance): void {
    $vector_store_manager = $handler_instance->get_vector_store_manager();
    $vector_store_registry = $handler_instance->get_vector_store_registry();

    if (!$vector_store_manager || !$vector_store_registry) {
        $handler_instance->send_wp_error(new WP_Error('manager_not_ready_list_qdrant', __('Vector Store components not available for Qdrant.', 'gpt3-ai-content-generator'), ['status' => 500]));
        return;
    }

    $qdrant_config = $handler_instance->_get_qdrant_config();
    if (is_wp_error($qdrant_config)) {
        $handler_instance->send_wp_error($qdrant_config);
        return;
    }

    $response = $vector_store_manager->list_all_indexes('Qdrant', $qdrant_config);
    if (is_wp_error($response)) {
        $handler_instance->send_wp_error($response);
        return;
    }

    $detailed_collections = [];
    if (is_array($response)) {
        foreach ($response as $collection_summary) {
            if (!is_array($collection_summary)) {
                continue;
            }
            $collection_name = $collection_summary['name'] ?? ($collection_summary['id'] ?? null);
            if ($collection_name) {
                $details = $vector_store_manager->describe_single_index('Qdrant', $collection_name, $qdrant_config);
                $detailed_collections[] = is_wp_error($details) ? $collection_summary : array_merge($collection_summary, $details);
            }
        }
    }

    $detailed_collections = $vector_store_registry->replace_provider_cache('Qdrant', $detailed_collections);

    wp_send_json_success(['collections' => $detailed_collections, 'message' => __('Qdrant collections synced successfully.', 'gpt3-ai-content-generator')]);
}

/**
 * Handles the logic for upserting points to a Qdrant collection.
 * Called by AIPKit_Vector_Store_Qdrant_Ajax_Handler::ajax_upsert_to_qdrant_collection().
 *
 * @param AIPKit_Vector_Store_Qdrant_Ajax_Handler $handler_instance
 * @return void
 */
function _aipkit_qdrant_ajax_upsert_to_collection_logic(AIPKit_Vector_Store_Qdrant_Ajax_Handler $handler_instance): void
{
    $vector_store_manager = $handler_instance->get_vector_store_manager();

    if (!$vector_store_manager) {
        $handler_instance->send_wp_error(new WP_Error('manager_not_ready_upsert_qdrant', __('Vector Store Manager not available for Qdrant upsert.', 'gpt3-ai-content-generator'), ['status' => 500]));
        return;
    }

    $qdrant_config = $handler_instance->_get_qdrant_config();
    if (is_wp_error($qdrant_config)) {
        $handler_instance->send_wp_error($qdrant_config);
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

    if (empty($collection_name)) {
        $handler_instance->send_wp_error(new WP_Error('missing_collection_name_qdrant_upsert', __('Qdrant collection name is required.', 'gpt3-ai-content-generator'), ['status' => 400]));
        return;
    }

    if ($text_content !== '') {
        if (empty($embedding_provider) || empty($embedding_model)) {
            $handler_instance->send_wp_error(new WP_Error('missing_embedding_config_qdrant_text', __('Embedding provider and model are required for Qdrant text indexing.', 'gpt3-ai-content-generator'), ['status' => 400]));
            return;
        }

        $metadata = _aipkit_qdrant_decode_text_metadata($post_data);
        if (is_wp_error($metadata)) {
            $handler_instance->send_wp_error($metadata);
            return;
        }

        $service = new AIPKit_Vector_Text_Ingestion_Service($vector_store_manager, $handler_instance->get_ai_caller());
        $result = $service->ingest_text('Qdrant', $collection_name, $text_content, $embedding_provider, $embedding_model, $metadata, $qdrant_config);
        $content_for_log = $original_text_content !== null ? $original_text_content : $text_content;

        if (is_wp_error($result)) {
            $handler_instance->_log_vector_data_source_entry([
                'vector_store_id' => $collection_name, 'vector_store_name' => $collection_name,
                'status' => 'failed', 'message' => 'Text content indexing failed: ' . $result->get_error_message(),
                'embedding_provider' => $embedding_provider, 'embedding_model' => $embedding_model,
                'indexed_content' => $content_for_log,
                'file_id' => isset($metadata['vector_id']) ? (string) $metadata['vector_id'] : null,
                'source_type_for_log' => isset($metadata['source']) ? (string) $metadata['source'] : 'text_entry_global_form',
            ]);
            $handler_instance->send_wp_error($result);
            return;
        }

        $chunk_count = (int) ($result['total_chunks'] ?? 0);
        $handler_instance->_log_vector_data_source_entry([
            'vector_store_id' => $collection_name, 'vector_store_name' => $collection_name,
            'status' => 'indexed', 'message' => sprintf('Text content submitted for indexing. Chunks: %d.', $chunk_count),
            'embedding_provider' => $result['embedding_provider'] ?? $embedding_provider,
            'embedding_model' => $embedding_model,
            'indexed_content' => $content_for_log,
            'file_id' => $result['parent_vector_id'] ?? null,
            'source_type_for_log' => $result['source_type'] ?? 'text_entry_global_form',
        ]);

        wp_send_json_success([
            'message' => __('Text content upserted to Qdrant successfully.', 'gpt3-ai-content-generator'),
            'result' => $result,
        ]);
        return;
    }

    if (empty($vectors_json)) {
        $handler_instance->send_wp_error(new WP_Error('missing_vectors_qdrant_upsert', __('Points data is required for Qdrant upsert.', 'gpt3-ai-content-generator'), ['status' => 400]));
        return;
    }

    $points = json_decode($vectors_json, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($points) || empty($points)) {
        $handler_instance->send_wp_error(new WP_Error('invalid_points_json_qdrant_upsert', __('Invalid or empty points JSON format for Qdrant.', 'gpt3-ai-content-generator'), ['status' => 400]));
        return;
    }

    $result = $vector_store_manager->upsert_vectors('Qdrant', $collection_name, ['points' => $points], $qdrant_config);

    $qdrant_point_id = $points[0]['id'] ?? null;
    // Prioritize 'payload' for source, then 'metadata' (as JS sends 'metadata')
    $source_type_for_log = $points[0]['payload']['source'] ?? ($points[0]['metadata']['source'] ?? 'unknown');
    $wp_post_id_for_log = null;
    $wp_post_title_for_log = null;
    $content_for_log = null;

    if ($source_type_for_log === 'wordpress_post') {
        // Check 'payload' first, then 'metadata' for 'post_id'
        if (isset($points[0]['payload']['post_id'])) {
            $wp_post_id_for_log = absint($points[0]['payload']['post_id']);
        } elseif (isset($points[0]['metadata']['post_id'])) {
            $wp_post_id_for_log = absint($points[0]['metadata']['post_id']);
        }

        if ($wp_post_id_for_log) {
            $wp_post_title_for_log = get_the_title($wp_post_id_for_log) ?: 'Post ' . $wp_post_id_for_log;
        }
        $content_for_log = $original_text_content; // For WP posts, the full content was passed as original_text_content
    } elseif (in_array($source_type_for_log, ['text_entry_global_form', 'file_upload_global_form', 'text_entry_qdrant_direct']) && $original_text_content !== null) {
        $content_for_log = $original_text_content;
        if ($source_type_for_log === 'file_upload_global_form' && isset($points[0]['metadata']['filename'])) { // JS sends filename in metadata
            $wp_post_title_for_log = sanitize_file_name($points[0]['metadata']['filename']);
        } elseif ($source_type_for_log === 'file_upload_global_form' && isset($points[0]['payload']['filename'])) { // Fallback check
            $wp_post_title_for_log = sanitize_file_name($points[0]['payload']['filename']);
        }
    }


    if (is_wp_error($result)) {
        $handler_instance->_log_vector_data_source_entry([
            'vector_store_id' => $collection_name, 'vector_store_name' => $collection_name,
            'post_id' => $wp_post_id_for_log, 'post_title' => $wp_post_title_for_log,
            'status' => 'failed', 'message' => 'Qdrant upsert failed: ' . $result->get_error_message(),
            'embedding_provider' => $embedding_provider, 'embedding_model' => $embedding_model,
            'indexed_content' => $content_for_log,
            'file_id' => $qdrant_point_id,
            'source_type_for_log' => $source_type_for_log
        ]);
        $handler_instance->send_wp_error($result);
    } else {
        $handler_instance->_log_vector_data_source_entry([
            'vector_store_id' => $collection_name, 'vector_store_name' => $collection_name,
            'post_id' => $wp_post_id_for_log, 'post_title' => $wp_post_title_for_log,
            'status' => 'indexed', 'message' => 'Points upserted to Qdrant. Status: ' . ($result['status'] ?? 'unknown'),
            'embedding_provider' => $embedding_provider, 'embedding_model' => $embedding_model,
            'indexed_content' => $content_for_log,
            'file_id' => $qdrant_point_id,
            'source_type_for_log' => $source_type_for_log
        ]);
        wp_send_json_success(['message' => __('Points upserted to Qdrant successfully.', 'gpt3-ai-content-generator'), 'result' => $result]);
    }
}

/**
 * @param array<string,mixed> $post_data
 * @return array<string,mixed>|WP_Error
 */
function _aipkit_qdrant_decode_text_metadata(array $post_data)
{
    $metadata = [];
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON string, decoded and sanitized by the ingestion service.
    $metadata_json = isset($post_data['metadata']) ? (string) $post_data['metadata'] : '';
    if ($metadata_json !== '') {
        $decoded = json_decode($metadata_json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return new WP_Error('invalid_qdrant_text_metadata', __('Invalid metadata JSON for Qdrant text indexing.', 'gpt3-ai-content-generator'), ['status' => 400]);
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
