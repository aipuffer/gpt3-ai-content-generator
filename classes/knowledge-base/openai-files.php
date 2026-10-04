<?php


namespace WPAICG\Dashboard\Ajax;

use WPAICG\Vector\AIPKit_Vector_Store_Manager;
use WPAICG\Vector\AIPKit_Vector_Store_Registry;
use WPAICG\aipkit_dashboard;

// Shared OpenAI utility functions are loaded by Vector_Store_Ajax_Handlers_Loader.

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Handles AJAX requests for OpenAI Vector Store file operations.
 * Delegates logic to namespaced functions in the operation module.
 */
class AIPKit_OpenAI_Vector_Store_Files_Ajax_Handler extends BaseDashboardAjaxHandler
{
    private $vector_store_manager;
    private $vector_store_registry;
    private $data_source_table_name;
    private $wpdb;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->data_source_table_name = $wpdb->prefix . 'aipkit_vector_data_source';

        $this->vector_store_manager = $this->create_vector_store_manager();
        $this->vector_store_registry = $this->create_vector_store_registry();

        if (!class_exists(\WPAICG\Vector\AIPKit_Vector_Provider_Strategy_Factory::class)) {
            $factory_path = WPAICG_PLUGIN_DIR . 'classes/knowledge-base/provider-contracts.php';
            if (file_exists($factory_path)) {
                require_once $factory_path;
            }
        }
        if (!class_exists(\WPAICG\Includes\AIPKit_Upload_Utils::class)) {
            $upload_utils_path = WPAICG_PLUGIN_DIR . 'classes/security/uploads.php';
            if (file_exists($upload_utils_path)) {
                require_once $upload_utils_path;
            }
        }
        if (!class_exists(aipkit_dashboard::class)) {
            $dashboard_path = WPAICG_PLUGIN_DIR . 'classes/admin/dashboard.php';
            if (file_exists($dashboard_path)) {
                require_once $dashboard_path;
            }
        }
    }

    public function get_vector_store_manager(): ?AIPKit_Vector_Store_Manager
    {
        return $this->vector_store_manager;
    }
    public function get_vector_store_registry(): ?AIPKit_Vector_Store_Registry
    {
        return $this->vector_store_registry;
    }
    public function get_wpdb(): \wpdb
    {
        return $this->wpdb;
    }
    public function get_data_source_table_name(): string
    {
        return $this->data_source_table_name;
    }

    public function ajax_add_text_to_vector_store_openai()
    {
        $permission_check = $this->check_any_module_access_permissions(
            ['sources', 'chatbot'],
            'aipkit_vector_store_nonce_openai'
        );
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        \WPAICG\Dashboard\Ajax\OpenAI\HandlerFiles\do_ajax_add_text_to_vector_store_openai_logic($this);
    }

    public function ajax_upload_and_add_file_to_store_direct_openai()
    {
        $permission_check = $this->check_any_module_access_permissions(['sources', 'chatbot'], 'aipkit_vector_store_nonce_openai');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        \WPAICG\Dashboard\Ajax\OpenAI\HandlerFiles\do_ajax_upload_and_add_file_to_store_direct_openai_logic($this);
    }

    public function ajax_get_openai_file_batch_status()
    {
        $permission_check = $this->check_any_module_access_permissions(['sources', 'chatbot'], 'aipkit_vector_store_nonce_openai');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        \WPAICG\Dashboard\Ajax\OpenAI\HandlerFiles\do_ajax_get_openai_file_batch_status_logic($this);
    }
}

namespace WPAICG\Dashboard\Ajax\OpenAI\HandlerFiles;

use WPAICG\Dashboard\Ajax\AIPKit_OpenAI_Vector_Store_Files_Ajax_Handler;
use WPAICG\Vector\AIPKit_Vector_Provider_Strategy_Factory;
use WP_Error;
use WPAICG\aipkit_dashboard;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles the logic for adding text content to an OpenAI Vector Store.
 * Called by AIPKit_OpenAI_Vector_Store_Files_Ajax_Handler::ajax_add_text_to_vector_store_openai().
 *
 * @param AIPKit_OpenAI_Vector_Store_Files_Ajax_Handler $handler_instance
 * @return void
 */
function do_ajax_add_text_to_vector_store_openai_logic(AIPKit_OpenAI_Vector_Store_Files_Ajax_Handler $handler_instance): void
{
    // Permission check already done by the handler calling this

    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    WP_Filesystem();
    global $wp_filesystem;

    if (is_wp_error($wp_filesystem) || !$wp_filesystem) {
        $error = is_wp_error($wp_filesystem) ? $wp_filesystem : new WP_Error('filesystem_init_failed', __('Could not initialize the WordPress filesystem.', 'gpt3-ai-content-generator'));
        $handler_instance->send_wp_error($error, 500);
        return;
    }

    $vector_store_manager = $handler_instance->get_vector_store_manager();
    $vector_store_registry = $handler_instance->get_vector_store_registry();

    if (!$vector_store_manager || !$vector_store_registry) {
        $handler_instance->send_wp_error(new WP_Error('manager_not_ready', __('Vector Store components not available.', 'gpt3-ai-content-generator'), ['status' => 500]));
        return;
    }

    $openai_config = $handler_instance->_get_openai_config();
    if (is_wp_error($openai_config)) {
        $handler_instance->send_wp_error($openai_config);
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $post_data = wp_unslash($_POST);
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $target_store_id = isset($post_data['target_store_id']) ? sanitize_text_field($post_data['target_store_id']) : '';
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $text_content = isset($post_data['text_content']) ? wp_kses_post(wp_unslash($post_data['text_content'])) : '';
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $source_type = isset($post_data['source_type']) ? sanitize_key($post_data['source_type']) : 'text_entry_global_form';

    if (empty($target_store_id) || $target_store_id === '_create_new_') {
        $handler_instance->send_wp_error(new WP_Error('no_target_store_text_direct', __('Please select an existing store for text content.', 'gpt3-ai-content-generator'), ['status' => 400]));
        return;
    }
    if (empty($text_content)) {
        $handler_instance->send_wp_error(new WP_Error('no_text_content_direct', __('Text content cannot be empty.', 'gpt3-ai-content-generator'), ['status' => 400]));
        return;
    }

    $actual_store_id = $target_store_id;
    $final_store_name = '';
    $store_details = $vector_store_manager->describe_single_index('OpenAI', $actual_store_id, $openai_config);
    if (!is_wp_error($store_details)) {
        $final_store_name = $store_details['name'] ?? $actual_store_id;
    }

    $temp_file_path_result = \WPAICG\Dashboard\Ajax\OpenAI\_aipkit_openai_vs_files_create_temp_file_from_string($text_content, 'text-content-');
    if (is_wp_error($temp_file_path_result)) {
        $handler_instance->send_wp_error($temp_file_path_result);
        return;
    }
    $temp_file_path = $temp_file_path_result;

    $strategy = AIPKit_Vector_Provider_Strategy_Factory::get_strategy('OpenAI');
    if (is_wp_error($strategy) || !method_exists($strategy, 'upload_file_for_vector_store')) {
        $wp_filesystem->delete($temp_file_path);
        $handler_instance->send_wp_error(new WP_Error('strategy_error_text_direct', __('File upload component not available for text content.', 'gpt3-ai-content-generator'), ['status' => 500]));
        return;
    }
    $strategy->connect($openai_config);
    $upload_result = $strategy->upload_file_for_vector_store($temp_file_path, basename($temp_file_path), 'user_data');
    $wp_filesystem->delete($temp_file_path);

    if (is_wp_error($upload_result) || !isset($upload_result['id'])) {
        $err_msg = is_wp_error($upload_result) ? $upload_result->get_error_message() : 'Missing file ID in upload response for text.';
        $handler_instance->send_wp_error(new WP_Error('file_upload_failed_text_direct', 'Failed to upload text content as file: ' . $err_msg, ['status' => 500]));
        return;
    }
    $file_id_to_add = $upload_result['id'];

    add_uploaded_file_to_store($handler_instance, $openai_config, $actual_store_id, $file_id_to_add, [
        'vector_store_name' => $final_store_name,
        'status' => 'indexed',
        'message' => 'Text content submitted for indexing.',
        'indexed_content' => $text_content,
        'source_type_for_log' => $source_type
    ], __('Text content uploaded and added to vector store. Processing is asynchronous.', 'gpt3-ai-content-generator'));
}

/**
 * Handles the logic for fetching an OpenAI Vector Store batch status.
 * Called by AIPKit_OpenAI_Vector_Store_Files_Ajax_Handler::ajax_get_openai_file_batch_status().
 *
 * @param AIPKit_OpenAI_Vector_Store_Files_Ajax_Handler $handler_instance
 * @return void
 */
function do_ajax_get_openai_file_batch_status_logic(AIPKit_OpenAI_Vector_Store_Files_Ajax_Handler $handler_instance): void
{
    $openai_config = $handler_instance->_get_openai_config();
    if (is_wp_error($openai_config)) {
        $handler_instance->send_wp_error($openai_config);
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $post_data = wp_unslash($_POST);
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $store_id = isset($post_data['store_id']) ? sanitize_text_field($post_data['store_id']) : '';
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $batch_id = isset($post_data['batch_id']) ? sanitize_text_field($post_data['batch_id']) : '';

    if (empty($store_id) || empty($batch_id)) {
        $handler_instance->send_wp_error(new WP_Error('missing_batch_id', __('Vector Store ID and Batch ID are required.', 'gpt3-ai-content-generator'), ['status' => 400]));
        return;
    }

    if (!class_exists(AIPKit_Vector_Provider_Strategy_Factory::class)) {
        $factory_path = WPAICG_PLUGIN_DIR . 'classes/knowledge-base/provider-contracts.php';
        if (file_exists($factory_path)) {
            require_once $factory_path;
        }
    }

    $strategy = AIPKit_Vector_Provider_Strategy_Factory::get_strategy('OpenAI');
    if (is_wp_error($strategy) || !method_exists($strategy, 'retrieve_file_batch')) {
        $handler_instance->send_wp_error(new WP_Error('openai_strategy_missing', __('OpenAI vector strategy not available.', 'gpt3-ai-content-generator'), ['status' => 500]));
        return;
    }

    $strategy->connect($openai_config);
    $batch = $strategy->retrieve_file_batch($store_id, $batch_id);
    if (is_wp_error($batch)) {
        $handler_instance->send_wp_error($batch);
        return;
    }

    $source = is_array($batch)
        ? (new \WPAICG\Vector\PostProcessor\OpenAI\OpenAIPostProcessor())->refresh_batch($store_id, $batch_id, $batch)
        : null;

    $status = '';
    if (is_array($batch) && isset($batch['status'])) {
        $status = sanitize_key($batch['status']);
    }

    wp_send_json_success([
        'status' => $status,
        'indexing_status' => $source['status'] ?? '',
        'batch' => $batch,
    ]);
}

/**
 * Handles the logic for uploading a file and adding it directly to an OpenAI Vector Store.
 * Called by AIPKit_OpenAI_Vector_Store_Files_Ajax_Handler::ajax_upload_and_add_file_to_store_direct_openai().
 *
 * @param AIPKit_OpenAI_Vector_Store_Files_Ajax_Handler $handler_instance
 * @return void
 */
function do_ajax_upload_and_add_file_to_store_direct_openai_logic(AIPKit_OpenAI_Vector_Store_Files_Ajax_Handler $handler_instance): void
{
    // Permission check already done by the handler calling this

    $vector_store_manager = $handler_instance->get_vector_store_manager();
    $vector_store_registry = $handler_instance->get_vector_store_registry();

    if (!$vector_store_manager || !$vector_store_registry) {
        $handler_instance->send_wp_error(new WP_Error('manager_not_ready', __('Vector Store components not available.', 'gpt3-ai-content-generator'), ['status' => 500]));
        return;
    }

    if (!aipkit_dashboard::is_pro_plan()) {
        $handler_instance->send_wp_error(new WP_Error('pro_feature_openai_upload_direct', __('Direct file upload and add to OpenAI store is a Pro feature. Please upgrade.', 'gpt3-ai-content-generator'), ['status' => 403]));
        return;
    }

    $paid_path = WPAICG_LIB_DIR . 'knowledge-base/upload-openai.php';
    if (!file_exists($paid_path)) {
        $handler_instance->send_wp_error(new WP_Error('required_files_missing_openai_upload', __('Required components for OpenAI file uploads are missing. Please reinstall the Pro version of AI Puffer.', 'gpt3-ai-content-generator'), ['status' => 500]));
        return;
    }
    require_once $paid_path;
    \WPAICG\Lib\VectorStores\FileUpload\OpenAI\upload_and_add_file($handler_instance);
}

/**
 * Submits an uploaded file, records its source and refreshes the store cache.
 * Used by both shared text entry and paid direct file uploads.
 */
function add_uploaded_file_to_store(
    AIPKit_OpenAI_Vector_Store_Files_Ajax_Handler $handler_instance,
    array $openai_config,
    string $store_id,
    $file_id,
    array $log_data,
    string $success_message
): void {
    $manager = $handler_instance->get_vector_store_manager();
    $batch = $manager->upsert_vectors('OpenAI', $store_id, ['file_ids' => [$file_id]], $openai_config);
    if (is_wp_error($batch)) {
        $handler_instance->send_wp_error($batch);
        return;
    }

    $log_data['vector_store_id'] = $store_id;
    $log_data['file_id'] = $file_id;
    $log_data['batch_id'] = $batch['id'] ?? null;
    \WPAICG\Dashboard\Ajax\OpenAI\_aipkit_openai_vs_files_log_vector_data_source_entry(
        $handler_instance->get_wpdb(),
        $handler_instance->get_data_source_table_name(),
        $log_data
    );

    $store = $manager->describe_single_index('OpenAI', $store_id, $openai_config);
    if (!is_wp_error($store) && is_array($store) && isset($store['id'])) {
        $handler_instance->get_vector_store_registry()->add_registered_store('OpenAI', $store);
    }

    wp_send_json_success(['message' => $success_message, 'store_id' => $store_id, 'batch' => $batch]);
}
