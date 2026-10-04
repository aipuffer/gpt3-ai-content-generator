<?php

namespace WPAICG\Vector\PostProcessor\Google;

use WPAICG\AIPKit_Providers;
use WPAICG\Core\Providers\Google\FileSearch\GoogleFileSearchClient;
use WPAICG\Vector\GoogleFileSearch\GoogleFileSearchIngestionService;
use WPAICG\Vector\AIPKit_Vector_Store_Registry;
use WPAICG\Vector\PostProcessor\Base\AIPKit_Vector_Post_Processor_Base;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Indexes WordPress content as persistent Google File Search documents.
 */
final class GooglePostProcessor extends AIPKit_Vector_Post_Processor_Base
{
    /** @var GoogleFileSearchClient */
    private $client;

    /** @var GoogleFileSearchIngestionService */
    private $ingestion_service;

    public function __construct()
    {
        parent::__construct();
        $this->client = new GoogleFileSearchClient();
        $this->ingestion_service = new GoogleFileSearchIngestionService($this->client);
    }

    /**
     * @return array{status:string,message:string,job_id?:int,operation_name?:string}
     */
    public function index_single_post_to_store(
        int $post_id,
        string $store_name,
        ?string $store_display_name = null
    ): array {
        $post = get_post($post_id);
        if (!$post) {
            return [
                'status' => 'error',
                'message' => __('Post not found.', 'gpt3-ai-content-generator'),
            ];
        }

        $connection = AIPKit_Providers::get_provider_data('Google');
        if (empty($connection['api_key'])) {
            return [
                'status' => 'error',
                'message' => __('Add a Google API key before indexing WordPress content.', 'gpt3-ai-content-generator'),
            ];
        }
        $connection['api_version'] = 'v1beta';

        $cleanup = $this->delete_existing_documents($connection, $post_id, $store_name);
        if (is_wp_error($cleanup)) {
            return [
                'status' => 'error',
                'message' => $cleanup->get_error_message(),
            ];
        }

        $contents = $this->get_post_content_as_string($post_id);
        if (is_wp_error($contents) || trim((string) $contents) === '') {
            return [
                'status' => 'error',
                'message' => is_wp_error($contents)
                    ? $contents->get_error_message()
                    : __('Post content is empty.', 'gpt3-ai-content-generator'),
            ];
        }

        $store_display_name = $this->resolve_store_display_name($store_name, $store_display_name);
        $result = $this->ingestion_service->start(
            $store_name,
            $store_display_name,
            (string) $contents,
            'wordpress-post-' . $post_id . '.txt',
            'text/plain',
            [
                'source_type' => 'wordpress_post',
                'post_id' => $post_id,
                'post_title' => (string) $post->post_title,
                'indexed_content' => (string) $contents,
                'extraction_fingerprint' => $this->get_last_extraction_fingerprint($post_id),
                'message' => __('WordPress post content submitted for indexing.', 'gpt3-ai-content-generator'),
            ],
            [
                'custom_metadata' => [
                    'source' => 'wordpress',
                    'post_type' => (string) $post->post_type,
                ],
            ]
        );
        if (is_wp_error($result)) {
            return [
                'status' => 'error',
                'message' => $result->get_error_message(),
            ];
        }
        if (sanitize_key((string) ($result['status'] ?? 'processing')) === 'failed') {
            return [
                'status' => 'error',
                'message' => sanitize_text_field((string) (
                    $result['message'] ?? __('Google File Search indexing failed.', 'gpt3-ai-content-generator')
                )),
            ];
        }

        return [
            'status' => 'success',
            'processing' => (string) ($result['status'] ?? 'processing') !== 'indexed',
            'message' => __('WordPress content was submitted to Google File Search.', 'gpt3-ai-content-generator'),
            'job_id' => (int) ($result['job_id'] ?? 0),
            'operation_name' => (string) ($result['operation_name'] ?? ''),
        ];
    }

    private function resolve_store_display_name(string $store_name, ?string $display_name): string
    {
        $display_name = trim((string) $display_name);
        if ($display_name !== '' && $display_name !== $store_name) {
            return $display_name;
        }
        if (class_exists(AIPKit_Vector_Store_Registry::class)) {
            foreach (AIPKit_Vector_Store_Registry::get_registered_stores_by_provider('Google') as $store) {
                if (!is_array($store) || (string) ($store['id'] ?? '') !== $store_name) {
                    continue;
                }
                $name = trim((string) ($store['name'] ?? ''));
                if ($name !== '') {
                    return $name;
                }
            }
        }
        return $store_name;
    }

    /**
     * @param array<string, mixed> $connection
     * @return true|WP_Error
     */
    private function delete_existing_documents(array $connection, int $post_id, string $store_name)
    {
        global $wpdb;
        $table_identifier = preg_match('/^[A-Za-z0-9_]+$/', $this->data_source_table_name)
            ? '`' . $this->data_source_table_name . '`'
            : '';
        if ($table_identifier === '') {
            return new WP_Error(
                'google_file_search_invalid_log_table',
                __('The knowledge base log is unavailable.', 'gpt3-ai-content-generator')
            );
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned table identifier is validated and backticked; identifier placeholders are unavailable on the minimum supported WordPress version.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, file_id, status FROM {$table_identifier} WHERE provider = %s AND vector_store_id = %s AND post_id = %d ORDER BY id DESC",
                'Google',
                $store_name,
                $post_id
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        foreach ((array) $rows as $row) {
            $file_id = (string) ($row['file_id'] ?? '');
            $status = sanitize_key((string) ($row['status'] ?? ''));
            if ($status === 'processing') {
                return new WP_Error(
                    'google_file_search_post_already_processing',
                    __('This post is already being indexed by Google File Search.', 'gpt3-ai-content-generator'),
                    ['status' => 409]
                );
            }
            if ($file_id !== '' && !GoogleFileSearchIngestionService::is_pending_file_id($file_id)) {
                $deleted = $this->client->delete_document($connection, $store_name, $file_id, true);
                if (is_wp_error($deleted)) {
                    $error_data = $deleted->get_error_data();
                    $http_status = is_array($error_data) ? (int) ($error_data['status'] ?? 0) : 0;
                    if ($http_status !== 404) {
                        return $deleted;
                    }
                }
            }
        }

        if (!empty($rows)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table cleanup after remote documents have been removed.
            $wpdb->delete(
                $this->data_source_table_name,
                ['provider' => 'Google', 'vector_store_id' => $store_name, 'post_id' => $post_id],
                ['%s', '%s', '%d']
            );
        }

        return true;
    }
}
