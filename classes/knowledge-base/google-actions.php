<?php

namespace WPAICG\Dashboard\Ajax;

use WPAICG\AIPKit_Providers;
use WPAICG\Core\Providers\Google\FileSearch\GoogleFileSearchClient;
use WPAICG\Vector\AIPKit_Vector_Store_Registry;
use WPAICG\Vector\GoogleFileSearch\GoogleFileSearchIngestionService;
use WPAICG\Vector\PostProcessor\Google\GooglePostProcessor;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Knowledge Base management endpoints for Google File Search.
 */
final class AIPKit_Google_File_Search_Ajax_Handler extends BaseDashboardAjaxHandler
{
    private const NONCE_ACTION = 'aipkit_google_file_search_nonce';

    /** @var GoogleFileSearchClient */
    private $client;

    /** @var GoogleFileSearchIngestionService */
    private $ingestion_service;

    /** @var GooglePostProcessor|null */
    private $post_processor;

    public function __construct()
    {
        $this->client = new GoogleFileSearchClient();
        $this->ingestion_service = new GoogleFileSearchIngestionService($this->client);
        $this->post_processor = class_exists(GooglePostProcessor::class)
            ? new GooglePostProcessor()
            : null;
    }

    public function ajax_list_stores(): void
    {
        if (!$this->authorize(['sources', 'chatbot', 'vector_content_indexer'])) {
            return;
        }
        $connection = $this->get_connection();
        if (is_wp_error($connection)) {
            $this->send_wp_error($connection);
            return;
        }
        $stores = $this->client->list_all_stores($connection);
        if (is_wp_error($stores)) {
            $this->send_wp_error($stores);
            return;
        }
        $stores = AIPKit_Vector_Store_Registry::replace_provider_cache('Google', $stores);
        wp_send_json_success(['stores' => $stores]);
    }

    public function ajax_create_store(): void
    {
        if (!$this->authorize(['sources', 'chatbot'])) {
            return;
        }
        $post_data = $this->post_data();
        $display_name = isset($post_data['name']) ? sanitize_text_field((string) $post_data['name']) : '';
        if ($display_name === '') {
            $this->send_wp_error(new WP_Error('google_file_search_store_name_missing', __('Enter a store name.', 'gpt3-ai-content-generator'), ['status' => 400]));
            return;
        }
        $connection = $this->get_connection();
        if (is_wp_error($connection)) {
            $this->send_wp_error($connection);
            return;
        }
        $store = $this->client->create_store($connection, $display_name);
        if (is_wp_error($store)) {
            $this->send_wp_error($store);
            return;
        }
        AIPKit_Vector_Store_Registry::add_registered_store('Google', $store);
        wp_send_json_success([
            'store' => $store,
            'message' => __('Google store created.', 'gpt3-ai-content-generator'),
        ]);
    }

    public function ajax_delete_store(): void
    {
        if (!$this->authorize(['sources', 'chatbot'])) {
            return;
        }
        $post_data = $this->post_data();
        $store_name = isset($post_data['store_name']) ? sanitize_text_field((string) $post_data['store_name']) : '';
        if ($store_name === '') {
            $this->send_wp_error(new WP_Error('google_file_search_store_missing', __('Select a Google store.', 'gpt3-ai-content-generator'), ['status' => 400]));
            return;
        }
        $connection = $this->get_connection();
        if (is_wp_error($connection)) {
            $this->send_wp_error($connection);
            return;
        }
        $deleted = $this->client->delete_store($connection, $store_name, true);
        if (is_wp_error($deleted)) {
            $this->send_wp_error($deleted);
            return;
        }
        AIPKit_Vector_Store_Registry::remove_registered_store('Google', $store_name);
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Local source records are removed after the remote store is deleted.
        $wpdb->delete(
            $wpdb->prefix . 'aipkit_vector_data_source',
            ['provider' => 'Google', 'vector_store_id' => $store_name],
            ['%s', '%s']
        );
        wp_send_json_success(['message' => __('Google store deleted.', 'gpt3-ai-content-generator')]);
    }

    public function ajax_add_text(): void
    {
        if (!$this->authorize(['sources', 'chatbot'])) {
            return;
        }
        $post_data = $this->post_data();
        $store_name = isset($post_data['target_store_id']) ? sanitize_text_field((string) $post_data['target_store_id']) : '';
        $text = isset($post_data['text_content']) ? wp_kses_post((string) $post_data['text_content']) : '';
        $source_type = isset($post_data['source_type']) ? sanitize_key((string) $post_data['source_type']) : 'text_entry_global_form';
        if ($store_name === '' || trim($text) === '') {
            $this->send_wp_error(new WP_Error('google_file_search_text_missing', __('Select a store and enter text to index.', 'gpt3-ai-content-generator'), ['status' => 400]));
            return;
        }
        $store_display_name = $this->resolve_store_display_name($store_name);
        $result = $this->ingestion_service->start(
            $store_name,
            $store_display_name,
            $text,
            'knowledge-text.txt',
            'text/plain',
            [
                'source_type' => $source_type,
                'indexed_content' => $text,
                'message' => __('Text content submitted for indexing.', 'gpt3-ai-content-generator'),
            ],
            ['custom_metadata' => ['source' => $source_type]]
        );
        if (is_wp_error($result)) {
            $this->send_wp_error($result);
            return;
        }
        wp_send_json_success(array_merge($result, [
            'message' => __('Text submitted to Google File Search.', 'gpt3-ai-content-generator'),
        ]));
    }

    public function ajax_index_wp_content(): void
    {
        if (!$this->authorize(['sources', 'vector_content_indexer'])) {
            return;
        }
        if (!$this->post_processor) {
            $this->send_wp_error(new WP_Error('google_file_search_post_processor_missing', __('Google WordPress indexing is unavailable.', 'gpt3-ai-content-generator'), ['status' => 500]));
            return;
        }
        $post_data = $this->post_data();
        $store_name = isset($post_data['target_store_id']) ? sanitize_text_field((string) $post_data['target_store_id']) : '';
        $post_ids = isset($post_data['post_ids']) ? array_values(array_filter(array_map('absint', (array) $post_data['post_ids']))) : [];
        if (strpos($store_name, 'fileSearchStores/') !== 0 || empty($post_ids)) {
            $this->send_wp_error(new WP_Error('google_file_search_posts_missing', __('Select a store and at least one WordPress item.', 'gpt3-ai-content-generator'), ['status' => 400]));
            return;
        }
        foreach ($post_ids as $post_id) {
            if (!current_user_can('edit_post', $post_id)) {
                $this->send_wp_error(new WP_Error('google_file_search_post_forbidden', __('You do not have permission to index one or more selected items.', 'gpt3-ai-content-generator'), ['status' => 403]));
                return;
            }
        }
        $store_display_name = $this->resolve_store_display_name($store_name);
        $jobs = [];
        foreach ($post_ids as $post_id) {
            $result = $this->post_processor->index_single_post_to_store($post_id, $store_name, $store_display_name);
            if (($result['status'] ?? '') !== 'success') {
                $this->send_wp_error(new WP_Error('google_file_search_post_index_failed', (string) ($result['message'] ?? __('Google WordPress indexing failed.', 'gpt3-ai-content-generator')), ['status' => 400]));
                return;
            }
            $jobs[] = $result;
        }
        wp_send_json_success([
            'jobs' => $jobs,
            'message' => __('WordPress content submitted to Google File Search.', 'gpt3-ai-content-generator'),
        ]);
    }

    public function ajax_get_job_status(): void
    {
        if (!$this->authorize(['sources', 'chatbot', 'vector_content_indexer'])) {
            return;
        }
        $post_data = $this->post_data();
        $job_id = isset($post_data['job_id']) ? absint($post_data['job_id']) : 0;
        $result = $this->ingestion_service->refresh($job_id);
        if (is_wp_error($result)) {
            $this->send_wp_error($result);
            return;
        }
        wp_send_json_success($result);
    }

    /**
     * @param array<int, string> $modules
     */
    private function authorize(array $modules): bool
    {
        $permission = $this->check_any_module_access_permissions($modules, self::NONCE_ACTION);
        if (is_wp_error($permission)) {
            $this->send_wp_error($permission);
            return false;
        }
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function post_data(): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Every public handler verifies the provider nonce before reading POST data.
        return is_array($_POST) ? wp_unslash($_POST) : [];
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    private function get_connection()
    {
        $connection = AIPKit_Providers::get_provider_data('Google');
        if (empty($connection['api_key'])) {
            return new WP_Error('google_file_search_missing_api_key', __('Add a Google API key in AI settings before using File Search.', 'gpt3-ai-content-generator'), ['status' => 400]);
        }
        $connection['api_version'] = 'v1beta';
        return $connection;
    }

    private function resolve_store_display_name(string $store_name): string
    {
        foreach (AIPKit_Vector_Store_Registry::get_registered_stores_by_provider('Google') as $store) {
            if (!is_array($store) || (string) ($store['id'] ?? '') !== $store_name) {
                continue;
            }
            return (string) ($store['name'] ?? $store_name);
        }
        return $store_name;
    }
}
