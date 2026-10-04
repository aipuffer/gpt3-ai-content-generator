<?php


namespace WPAICG\Dashboard\Ajax;

use WPAICG\Vector\AIPKit_Vector_Store_Manager;
use WPAICG\Vector\AIPKit_Vector_Store_Registry;

// Shared OpenAI utility functions are loaded by Vector_Store_Ajax_Handlers_Loader.


if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Handles AJAX requests for OpenAI Vector Store management (list, create and delete).
 * Delegates logic to namespaced functions.
 */
class AIPKit_OpenAI_Vector_Stores_Ajax_Handler extends BaseDashboardAjaxHandler
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


    public function ajax_list_vector_stores_openai()
    {
        $permission_check = $this->check_any_module_access_permissions(
            ['sources', 'chatbot', 'vector_content_indexer'],
            'aipkit_vector_store_nonce_openai'
        );
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        \WPAICG\Dashboard\Ajax\OpenAI\HandlerStores\do_ajax_list_vector_stores_openai_logic($this);
    }

    public function ajax_create_vector_store_openai()
    {
        $permission_check = $this->check_any_module_access_permissions(
            ['sources', 'chatbot'],
            'aipkit_vector_store_nonce_openai'
        );
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        \WPAICG\Dashboard\Ajax\OpenAI\HandlerStores\do_ajax_create_vector_store_openai_logic($this);
    }

    public function ajax_delete_vector_store_openai()
    {
        $permission_check = $this->check_any_module_access_permissions(
            ['sources', 'chatbot'],
            'aipkit_vector_store_nonce_openai'
        );
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        \WPAICG\Dashboard\Ajax\OpenAI\HandlerStores\do_ajax_delete_vector_store_openai_logic($this);
    }
}

namespace WPAICG\Dashboard\Ajax\OpenAI\HandlerStores;

use WPAICG\Dashboard\Ajax\AIPKit_OpenAI_Vector_Stores_Ajax_Handler;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles the logic for creating an OpenAI Vector Store.
 * Called by AIPKit_OpenAI_Vector_Stores_Ajax_Handler::ajax_create_vector_store_openai().
 *
 * @param AIPKit_OpenAI_Vector_Stores_Ajax_Handler $handler_instance
 * @return void
 */
function do_ajax_create_vector_store_openai_logic(AIPKit_OpenAI_Vector_Stores_Ajax_Handler $handler_instance): void
{
    // Permission check already done by the handler calling this

    $vector_store_manager = $handler_instance->get_vector_store_manager();
    $vector_store_registry = $handler_instance->get_vector_store_registry();

    if (!$vector_store_manager || !$vector_store_registry) {
        $handler_instance->send_wp_error(new WP_Error('manager_not_ready', __('Vector Store Manager or Registry not available.', 'gpt3-ai-content-generator'), ['status' => 500]));
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
    $store_name = isset($post_data['name']) ? sanitize_text_field($post_data['name']) : '';
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $source_type = isset($post_data['source_type']) ? sanitize_key($post_data['source_type']) : 'backend_ai_training_panel_create';

    if (empty($store_name)) {
        $handler_instance->send_wp_error(new WP_Error('missing_name', __('Vector Store name is required.', 'gpt3-ai-content-generator'), ['status' => 400]));
        return;
    }

    $index_config = ['metadata' => ['source_type' => $source_type]];

    $store_result = $vector_store_manager->create_index_if_not_exists('OpenAI', $store_name, $index_config, $openai_config);
    if (is_wp_error($store_result)) {
        $handler_instance->send_wp_error($store_result);
        return;
    }

    $vector_store_registry->add_registered_store('OpenAI', $store_result);
    wp_send_json_success(['store' => $store_result, 'message' => __('Vector Store created.', 'gpt3-ai-content-generator')]);
}

/**
 * Handles the logic for deleting an OpenAI Vector Store.
 * Called by AIPKit_OpenAI_Vector_Stores_Ajax_Handler::ajax_delete_vector_store_openai().
 *
 * @param AIPKit_OpenAI_Vector_Stores_Ajax_Handler $handler_instance
 * @return void
 */
function do_ajax_delete_vector_store_openai_logic(AIPKit_OpenAI_Vector_Stores_Ajax_Handler $handler_instance): void
{
    // Permission check already done by the handler calling this

    $vector_store_manager = $handler_instance->get_vector_store_manager();
    $vector_store_registry = $handler_instance->get_vector_store_registry();
    $wpdb = $handler_instance->get_wpdb();
    $data_source_table_name = $handler_instance->get_data_source_table_name();


    if (!$vector_store_manager || !$vector_store_registry) {
        $handler_instance->send_wp_error(new WP_Error('manager_not_ready', __('Vector Store Manager or Registry not available.', 'gpt3-ai-content-generator'), ['status' => 500]));
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
    $store_id = isset($post_data['store_id']) ? sanitize_text_field($post_data['store_id']) : '';
    if (empty($store_id)) {
        $handler_instance->send_wp_error(new WP_Error('missing_store_id', __('Vector Store ID is required.', 'gpt3-ai-content-generator'), ['status' => 400]));
        return;
    }

    $delete_result = $vector_store_manager->delete_index('OpenAI', $store_id, $openai_config);
    if (is_wp_error($delete_result)) {
        $handler_instance->send_wp_error($delete_result);
        return;
    }

    $vector_store_registry->remove_registered_store('OpenAI', $store_id);

    // Invalidate the cache for this store's logs before deleting
    $cache_key = 'openai_logs_' . sanitize_key($store_id);
    wp_cache_delete($cache_key, 'aipkit_vector_logs');

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Necessary delete operation on a custom table after an API action. Cache was invalidated above.
    $wpdb->delete($data_source_table_name, ['provider' => 'OpenAI', 'vector_store_id' => $store_id], ['%s', '%s']);

    wp_send_json_success(['message' => __('Vector Store deleted successfully.', 'gpt3-ai-content-generator')]);
}

/**
 * Handles the logic for listing OpenAI Vector Stores.
 * Called by AIPKit_OpenAI_Vector_Stores_Ajax_Handler::ajax_list_vector_stores_openai().
 *
 * @param AIPKit_OpenAI_Vector_Stores_Ajax_Handler $handler_instance
 * @return void
 */
function do_ajax_list_vector_stores_openai_logic(AIPKit_OpenAI_Vector_Stores_Ajax_Handler $handler_instance): void
{
    // Permission check already done by the handler calling this

    $vector_store_manager = $handler_instance->get_vector_store_manager();
    $vector_store_registry = $handler_instance->get_vector_store_registry();

    if (!$vector_store_manager || !$vector_store_registry) {
        $handler_instance->send_wp_error(new WP_Error('manager_not_ready', __('Vector Store Manager or Registry not available.', 'gpt3-ai-content-generator'), ['status' => 500]));
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
    $limit  = isset($post_data['limit']) ? absint($post_data['limit']) : 20;
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $order  = isset($post_data['order']) && in_array($post_data['order'], ['asc', 'desc']) ? sanitize_key($post_data['order']) : 'desc';
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $after  = isset($post_data['after']) && !empty($post_data['after']) ? sanitize_text_field($post_data['after']) : null;
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $before = isset($post_data['before']) && !empty($post_data['before']) ? sanitize_text_field($post_data['before']) : null;
    $limit = max(1, min($limit, 100));

    $response = $vector_store_manager->list_all_indexes('OpenAI', $openai_config, $limit, $order, $after, $before);

    if (is_wp_error($response)) {
        $handler_instance->send_wp_error($response);
        return;
    }

    // Filter out expired stores.
    $stores_from_api = $response['data'] ?? [];
    $active_stores_data = [];
    if (is_array($stores_from_api)) {
        foreach ($stores_from_api as $store_item) {
            // Only include stores that are not 'expired'.
            // Assume stores without a status or with other statuses (e.g., 'in_progress', 'completed') should be included.
            if (isset($store_item['status']) && $store_item['status'] === 'expired') {
                continue;
            }
            $active_stores_data[] = $store_item;
        }
    }
    // Replace the original data with filtered data for registry update and client response
    $response['data'] = $active_stores_data;


    // Only update the full registry if it's a likely full sync attempt
    $is_full_sync_attempt = (empty($after) && empty($before) && $limit >= 100);
    if (isset($response['data']) && is_array($response['data']) && $is_full_sync_attempt) {
        $response['data'] = $vector_store_registry->replace_provider_cache('OpenAI', $response['data']);
    }

    wp_send_json_success([
        'stores' => $response['data'] ?? [], // This will now be the filtered list
        'first_id' => $response['first_id'] ?? null,
        'last_id' => $response['last_id'] ?? null,
        'has_more' => $response['has_more'] ?? false,
    ]);
}
