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
 * Handles AJAX requests for Pinecone Vector Store operations.
 * Keeps shared operations here and delegates paid uploads to lib.
 */
class AIPKit_Vector_Store_Pinecone_Ajax_Handler extends BaseDashboardAjaxHandler
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
    public function _get_pinecone_config()
    {
        if (!class_exists(\WPAICG\AIPKit_Providers::class)) {
            $providers_path = WPAICG_PLUGIN_DIR . 'classes/ai/settings.php';
            if (file_exists($providers_path)) {
                require_once $providers_path;
            } else {
                return new WP_Error('dependency_missing', 'AIPKit_Providers class not found for Pinecone config.');
            }
        }
        $pinecone_data = AIPKit_Providers::get_provider_data('Pinecone');
        if (empty($pinecone_data['api_key'])) {
            return new WP_Error('missing_pinecone_config', __('Pinecone API Key is not configured in global settings.', 'gpt3-ai-content-generator'));
        }
        return ['api_key' => $pinecone_data['api_key']];
    }

    public function _log_vector_data_source_entry(array $log_data): void
    {
        $defaults = [
            'user_id' => get_current_user_id(), 'timestamp' => current_time('mysql', 1),
            'provider' => 'Pinecone',
            'vector_store_id' => 'unknown', 'vector_store_name' => null,
            'post_id' => null, 'post_title' => null, 'status' => 'info', 'message' => '',
            'indexed_content' => null,
            'file_id' => null,
            'batch_id' => null,
            'embedding_provider' => null, 'embedding_model' => null,
            'source_type_for_log' => null,
        ];
        $data_to_insert = wp_parse_args($log_data, $defaults);

        $source_type = $data_to_insert['source_type_for_log'] ?? ($data_to_insert['post_id'] ? 'wordpress_post' : 'unknown');
        $should_truncate = true;
        if (in_array($source_type, ['text_entry_global_form', 'file_upload_global_form', 'text_entry_pinecone_direct', 'chatbot_training_text', 'chatbot_training_qa'], true)) {
            $should_truncate = false;
        }

        if ($should_truncate && is_string($data_to_insert['indexed_content']) && mb_strlen($data_to_insert['indexed_content']) > 1000) {
            $data_to_insert['indexed_content'] = mb_substr($data_to_insert['indexed_content'], 0, 997) . '...';
        }
        unset($data_to_insert['source_type_for_log']);

        $this->wpdb->insert($this->data_source_table_name, $data_to_insert);

    }

    public function get_vector_store_manager(): ?AIPKit_Vector_Store_Manager
    {
        return $this->vector_store_manager;
    }
    public function get_vector_store_registry(): ?AIPKit_Vector_Store_Registry
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

    public function ajax_list_indexes_pinecone()
    {
        $permission_check = $this->check_any_module_access_permissions(
            ['sources', 'chatbot'],
            'aipkit_vector_store_pinecone_nonce'
        );
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        \WPAICG\Dashboard\Ajax\Pinecone\HandlerIndexes\do_ajax_list_indexes_logic($this);
    }
    public function ajax_create_index_pinecone()
    {
        $permission_check = $this->check_any_module_access_permissions(
            ['sources', 'chatbot'],
            'aipkit_vector_store_pinecone_nonce'
        );
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        \WPAICG\Dashboard\Ajax\Pinecone\HandlerIndexes\do_ajax_create_index_logic($this);
    }

    public function ajax_upsert_to_pinecone_index()
    {
        $permission_check = $this->check_any_module_access_permissions(
            ['sources', 'chatbot'],
            'aipkit_vector_store_pinecone_nonce'
        );
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        \WPAICG\Dashboard\Ajax\Pinecone\HandlerIndexes\do_ajax_upsert_to_index_logic($this);
    }
    public function ajax_upload_file_and_upsert_to_pinecone()
    {
        $permission_check = $this->check_any_module_access_permissions(['sources', 'chatbot'], 'aipkit_vector_store_pinecone_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        if (!aipkit_dashboard::is_pro_plan()) {
            $this->send_wp_error(new WP_Error('pro_feature_pinecone_upload', __('File upload to Pinecone is a Pro feature. Please upgrade.', 'gpt3-ai-content-generator'), ['status' => 403]));
            return;
        }

        $fn_file_path = WPAICG_LIB_DIR . 'knowledge-base/upload-vectors.php';
        if (!file_exists($fn_file_path)) {
            $this->send_wp_error(new WP_Error(
                'required_files_missing_pinecone_upload',
                __('Some required files seem to be missing for Pinecone file uploads. Please reinstall the Pro version of AI Puffer and try again.', 'gpt3-ai-content-generator'),
                ['status' => 500]
            ));
            return;
        }

        require_once $fn_file_path;
        \WPAICG\Lib\VectorStores\FileUpload\Pinecone\dispatch_upload_action($this);
    }
    public function ajax_delete_index_pinecone()
    {
        $permission_check = $this->check_any_module_access_permissions(['sources', 'chatbot'], 'aipkit_vector_store_pinecone_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        \WPAICG\Dashboard\Ajax\Pinecone\HandlerIndexes\do_ajax_delete_index_logic($this);
    }
}

namespace WPAICG\Dashboard\Ajax\Pinecone\HandlerIndexes;

use WP_Error;
use WPAICG\Dashboard\Ajax\AIPKit_Vector_Store_Pinecone_Ajax_Handler;
use WPAICG\Vector\AIPKit_Vector_Text_Ingestion_Service;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles the logic for creating a Pinecone index.
 * Called by AIPKit_Vector_Store_Pinecone_Ajax_Handler::ajax_create_index_pinecone().
 *
 * @param AIPKit_Vector_Store_Pinecone_Ajax_Handler $handler_instance
 * @return void
 */
function do_ajax_create_index_logic(AIPKit_Vector_Store_Pinecone_Ajax_Handler $handler_instance): void
{
    $vector_store_manager = $handler_instance->get_vector_store_manager();
    $vector_store_registry = $handler_instance->get_vector_store_registry();

    if (!$vector_store_manager || !$vector_store_registry) {
        $handler_instance->send_wp_error(new WP_Error('manager_not_ready_create_pinecone', __('Vector Store components not available.', 'gpt3-ai-content-generator'), ['status' => 500]));
        return;
    }

    $pinecone_config = $handler_instance->_get_pinecone_config();
    if (is_wp_error($pinecone_config)) {
        $handler_instance->send_wp_error($pinecone_config);
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $post_data = wp_unslash($_POST);
    $index_name = isset($post_data['name']) ? sanitize_text_field($post_data['name']) : '';
    $dimension = isset($post_data['dimension']) ? absint($post_data['dimension']) : 0;
    $metric = isset($post_data['metric']) ? sanitize_text_field($post_data['metric']) : 'cosine';
    $spec_cloud = isset($post_data['spec_cloud']) ? sanitize_text_field($post_data['spec_cloud']) : 'aws';
    $spec_region = isset($post_data['spec_region']) ? sanitize_text_field($post_data['spec_region']) : 'us-east-1';

    if (empty($index_name)) {
        $handler_instance->send_wp_error(new WP_Error('missing_name_pinecone_create', __('Pinecone index name is required.', 'gpt3-ai-content-generator'), ['status' => 400]));
        return;
    }
    if ($dimension <= 0) {
        $handler_instance->send_wp_error(new WP_Error('invalid_dimension_pinecone_create', __('Vector dimension must be a positive integer.', 'gpt3-ai-content-generator'), ['status' => 400]));
        return;
    }
    if (!in_array(strtolower($metric), ['cosine', 'euclidean', 'dotproduct'], true)) {
        $metric = 'cosine';
    }

    $index_create_config = [
        'dimension' => $dimension,
        'metric' => strtolower($metric),
        'spec' => [
            'serverless' => [
                'cloud' => strtolower($spec_cloud),
                'region' => strtolower($spec_region),
            ]
        ]
    ];

    $create_result = $vector_store_manager->create_index_if_not_exists('Pinecone', $index_name, $index_create_config, $pinecone_config);
    if (is_wp_error($create_result)) {
        $handler_instance->send_wp_error($create_result);
        return;
    }

    if (is_array($create_result) && isset($create_result['name'])) {
        $vector_store_registry->add_registered_store('Pinecone', $create_result);
        wp_send_json_success(['index' => $create_result, 'message' => __('Pinecone index created/verified successfully.', 'gpt3-ai-content-generator')]);
    } else {
        $handler_instance->send_wp_error(new WP_Error('pinecone_create_malformed_response', __('Malformed response after Pinecone index creation.', 'gpt3-ai-content-generator')));
    }
}

/**
 * Handles the logic for deleting a Pinecone index.
 * Called by AIPKit_Vector_Store_Pinecone_Ajax_Handler::ajax_delete_index_pinecone().
 *
 * @param AIPKit_Vector_Store_Pinecone_Ajax_Handler $handler_instance
 * @return void
 */
function do_ajax_delete_index_logic(AIPKit_Vector_Store_Pinecone_Ajax_Handler $handler_instance): void
{
    $vector_store_manager = $handler_instance->get_vector_store_manager();
    $vector_store_registry = $handler_instance->get_vector_store_registry();
    $wpdb = $handler_instance->get_wpdb();
    $data_source_table_name = $handler_instance->get_data_source_table_name();

    if (!$vector_store_manager || !$vector_store_registry) {
        $handler_instance->send_wp_error(new WP_Error('manager_not_ready_delete_pinecone', __('Vector Store components not available.', 'gpt3-ai-content-generator'), ['status' => 500]));
        return;
    }

    $pinecone_config = $handler_instance->_get_pinecone_config();
    if (is_wp_error($pinecone_config)) {
        $handler_instance->send_wp_error($pinecone_config);
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $index_name = isset($_POST['index_name']) ? sanitize_text_field(wp_unslash($_POST['index_name'])) : '';
    if (empty($index_name)) {
        $handler_instance->send_wp_error(new WP_Error('missing_index_name_delete_pinecone', __('Pinecone index name is required for deletion.', 'gpt3-ai-content-generator'), ['status' => 400]));
        return;
    }

    $delete_result = $vector_store_manager->delete_index('Pinecone', $index_name, $pinecone_config);
    if (is_wp_error($delete_result)) {
        $handler_instance->send_wp_error($delete_result);
        return;
    }

    $vector_store_registry->remove_registered_store('Pinecone', $index_name);

    // Invalidate the cache for this index's logs before deleting
    $cache_key = 'pinecone_logs_' . sanitize_key($index_name);
    $cache_group = 'aipkit_vector_logs';
    wp_cache_delete($cache_key, $cache_group);

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->delete($data_source_table_name, ['provider' => 'Pinecone', 'vector_store_id' => $index_name], ['%s', '%s']);

    wp_send_json_success(['message' => __('Pinecone index deleted successfully.', 'gpt3-ai-content-generator')]);
}

/**
 * Handles the logic for listing Pinecone indexes.
 * Called by AIPKit_Vector_Store_Pinecone_Ajax_Handler::ajax_list_indexes_pinecone().
 *
 * @param AIPKit_Vector_Store_Pinecone_Ajax_Handler $handler_instance
 * @return void
 */
function do_ajax_list_indexes_logic(AIPKit_Vector_Store_Pinecone_Ajax_Handler $handler_instance): void {
    $vector_store_manager = $handler_instance->get_vector_store_manager();
    $vector_store_registry = $handler_instance->get_vector_store_registry();

    if (!$vector_store_manager || !$vector_store_registry) {
        $handler_instance->send_wp_error(new WP_Error('manager_not_ready_list_pinecone', __('Vector Store components not available for Pinecone.', 'gpt3-ai-content-generator'), ['status' => 500]));
        return;
    }

    $pinecone_config = $handler_instance->_get_pinecone_config();
    if (is_wp_error($pinecone_config)) {
        $handler_instance->send_wp_error($pinecone_config);
        return;
    }

    $response = $vector_store_manager->list_all_indexes('Pinecone', $pinecone_config);
    if (is_wp_error($response)) {
        $handler_instance->send_wp_error($response);
        return;
    }

    // Enrich: fetch detailed stats for each index so total_vector_count is available
    $detailed_indexes = [];
    if (is_array($response)) {
        foreach ($response as $index_summary) {
            $index_name = $index_summary['name'] ?? $index_summary['id'] ?? null;
            if (!$index_name) {
                continue;
            }
            $details = $vector_store_manager->describe_single_index('Pinecone', $index_name, $pinecone_config);
            if (!is_wp_error($details)) {
                // Merge summary fields into details to keep any list-only fields
                $detailed_indexes[] = array_merge($index_summary, $details);
            } else {
                // Fallback to summary if describe fails for any index
                $detailed_indexes[] = $index_summary;
            }
        }
    }

    $detailed_indexes = $vector_store_registry->replace_provider_cache('Pinecone', $detailed_indexes);

    wp_send_json_success([
        'indexes' => $detailed_indexes,
        'message' => __('Pinecone indexes synced successfully.', 'gpt3-ai-content-generator')
    ]);
}

/**
 * Handles the logic for upserting vectors to a Pinecone index.
 * Called by AIPKit_Vector_Store_Pinecone_Ajax_Handler::ajax_upsert_to_pinecone_index().
 *
 * @param AIPKit_Vector_Store_Pinecone_Ajax_Handler $handler_instance
 * @return void
 */
function do_ajax_upsert_to_index_logic(AIPKit_Vector_Store_Pinecone_Ajax_Handler $handler_instance): void
{
    $vector_store_manager = $handler_instance->get_vector_store_manager();

    if (!$vector_store_manager) {
        $handler_instance->send_wp_error(new WP_Error('manager_not_ready_pinecone_upsert', __('Vector Store Manager not available.', 'gpt3-ai-content-generator'), ['status' => 500]));
        return;
    }

    $pinecone_config = $handler_instance->_get_pinecone_config();
    if (is_wp_error($pinecone_config)) {
        $handler_instance->send_wp_error($pinecone_config);
        return;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $post_data = wp_unslash($_POST);
    $index_name = isset($post_data['index_name']) ? sanitize_text_field($post_data['index_name']) : '';
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON string, decoded and validated below.
    $vectors_json = isset($post_data['vectors']) ? $post_data['vectors'] : '';
    $embedding_provider = isset($post_data['embedding_provider']) ? sanitize_key($post_data['embedding_provider']) : null;
    $embedding_model = isset($post_data['embedding_model']) ? sanitize_text_field($post_data['embedding_model']) : null;
    $original_text_content = isset($post_data['original_text_content']) ? wp_kses_post($post_data['original_text_content']) : null;
    $text_content = isset($post_data['text_content']) ? wp_kses_post($post_data['text_content']) : '';

    if (empty($index_name)) {
        $handler_instance->send_wp_error(new WP_Error('missing_index_name_pinecone', __('Pinecone index name is required.', 'gpt3-ai-content-generator'), ['status' => 400]));
        return;
    }

    if ($text_content !== '') {
        if (empty($embedding_provider) || empty($embedding_model)) {
            $handler_instance->send_wp_error(new WP_Error('missing_embedding_config_pinecone_text', __('Embedding provider and model are required for Pinecone text indexing.', 'gpt3-ai-content-generator'), ['status' => 400]));
            return;
        }

        $metadata = _aipkit_pinecone_decode_text_metadata($post_data);
        if (is_wp_error($metadata)) {
            $handler_instance->send_wp_error($metadata);
            return;
        }

        $service = new AIPKit_Vector_Text_Ingestion_Service($vector_store_manager, $handler_instance->get_ai_caller());
        $result = $service->ingest_text('Pinecone', $index_name, $text_content, $embedding_provider, $embedding_model, $metadata, $pinecone_config);
        $content_for_log = $original_text_content !== null ? $original_text_content : $text_content;

        if (is_wp_error($result)) {
            $handler_instance->_log_vector_data_source_entry([
                'vector_store_id' => $index_name, 'vector_store_name' => $index_name,
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
            'vector_store_id' => $index_name, 'vector_store_name' => $index_name,
            'status' => 'indexed', 'message' => sprintf('Text content submitted for indexing. Chunks: %d.', $chunk_count),
            'embedding_provider' => $result['embedding_provider'] ?? $embedding_provider,
            'embedding_model' => $embedding_model,
            'indexed_content' => $content_for_log,
            'file_id' => $result['parent_vector_id'] ?? null,
            'source_type_for_log' => $result['source_type'] ?? 'text_entry_global_form',
        ]);

        wp_send_json_success([
            'message' => __('Text content upserted to Pinecone successfully.', 'gpt3-ai-content-generator'),
            'result' => $result,
        ]);
        return;
    }

    if (empty($vectors_json)) {
        $handler_instance->send_wp_error(new WP_Error('missing_vectors_pinecone', __('Vectors data is required for Pinecone upsert.', 'gpt3-ai-content-generator'), ['status' => 400]));
        return;
    }
    $vectors = json_decode($vectors_json, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($vectors) || empty($vectors)) {
        $handler_instance->send_wp_error(new WP_Error('invalid_vectors_json_pinecone', __('Invalid or empty vectors JSON format.', 'gpt3-ai-content-generator'), ['status' => 400]));
        return;
    }

    $result = $vector_store_manager->upsert_vectors('Pinecone', $index_name, $vectors, $pinecone_config);

    $pinecone_vector_id = $vectors[0]['id'] ?? null;
    $source_type_for_log = $vectors[0]['metadata']['source'] ?? 'unknown';
    $wp_post_id_for_log = null;
    $wp_post_title_for_log = null;
    $content_for_log = null;

    if ($source_type_for_log === 'wordpress_post' && isset($vectors[0]['metadata']['post_id'])) {
        $wp_post_id_for_log = absint($vectors[0]['metadata']['post_id']);
        $wp_post_title_for_log = get_the_title($wp_post_id_for_log) ?: 'Post ' . $wp_post_id_for_log;
        $content_for_log = $original_text_content;
    } elseif (in_array($source_type_for_log, ['text_entry_global_form', 'file_upload_global_form', 'text_entry_pinecone_direct']) && $original_text_content !== null) {
        $content_for_log = $original_text_content;
        if ($source_type_for_log === 'file_upload_global_form' && isset($vectors[0]['metadata']['filename'])) {
            $wp_post_title_for_log = sanitize_file_name($vectors[0]['metadata']['filename']);
        }
    }


    if (is_wp_error($result)) {
        $handler_instance->_log_vector_data_source_entry([
            'vector_store_id' => $index_name, 'vector_store_name' => $index_name,
            'post_id' => $wp_post_id_for_log, 'post_title' => $wp_post_title_for_log,
            'status' => 'failed', 'message' => 'Upsert failed: ' . $result->get_error_message(),
            'embedding_provider' => $embedding_provider, 'embedding_model' => $embedding_model,
            'indexed_content' => $content_for_log,
            'file_id' => $pinecone_vector_id,
            'source_type_for_log' => $source_type_for_log
        ]);
        $handler_instance->send_wp_error($result);
    } else {
        $handler_instance->_log_vector_data_source_entry([
            'vector_store_id' => $index_name, 'vector_store_name' => $index_name,
            'post_id' => $wp_post_id_for_log, 'post_title' => $wp_post_title_for_log,
            'status' => 'indexed', 'message' => 'Vectors upserted. Count: ' . ($result['upserted_count'] ?? count($vectors)),
            'embedding_provider' => $embedding_provider, 'embedding_model' => $embedding_model,
            'indexed_content' => $content_for_log,
            'file_id' => $pinecone_vector_id,
            'source_type_for_log' => $source_type_for_log
        ]);
        wp_send_json_success(['message' => __('Vectors upserted to Pinecone successfully.', 'gpt3-ai-content-generator'), 'result' => $result]);
    }
}

/**
 * @param array<string,mixed> $post_data
 * @return array<string,mixed>|WP_Error
 */
function _aipkit_pinecone_decode_text_metadata(array $post_data)
{
    $metadata = [];
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON string, decoded and sanitized by the ingestion service.
    $metadata_json = isset($post_data['metadata']) ? (string) $post_data['metadata'] : '';
    if ($metadata_json !== '') {
        $decoded = json_decode($metadata_json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return new WP_Error('invalid_pinecone_text_metadata', __('Invalid metadata JSON for Pinecone text indexing.', 'gpt3-ai-content-generator'), ['status' => 400]);
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
