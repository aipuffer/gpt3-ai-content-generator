<?php

namespace WPAICG\Vector\PostProcessor\Qdrant;

use WPAICG\AIPKit_Providers;
use WPAICG\Vector\PostProcessor\Base\AIPKit_Vector_Post_Processor_Base;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

if (!class_exists(AIPKit_Vector_Post_Processor_Base::class)) {
    $base_class_path = WPAICG_PLUGIN_DIR . 'classes/knowledge-base/indexing.php';
    if (file_exists($base_class_path)) {
        require_once $base_class_path;
    }
}

/**
 * Handles fetching Qdrant API configuration for post processing.
 */
class QdrantConfig {

    /**
     * @return mixed[]|\WP_Error
     */
    public function get_config() {
        if (!class_exists(\WPAICG\AIPKit_Providers::class)) {
            $providers_path = WPAICG_PLUGIN_DIR . 'classes/ai/settings.php';
            if (file_exists($providers_path)) require_once $providers_path;
            else return new WP_Error('dependency_missing_config_qdrant', 'AIPKit_Providers class not found for Qdrant config.');
        }
        $qdrant_data = AIPKit_Providers::get_provider_data('Qdrant');
        if (empty($qdrant_data['url'])) {
            return new WP_Error('missing_qdrant_url_config', __('Qdrant URL is not configured in global settings.', 'gpt3-ai-content-generator'));
        }
        if (empty($qdrant_data['api_key'])) {
            return new WP_Error('missing_qdrant_api_key_config', __('Qdrant API Key is not configured in global settings (required for Qdrant Cloud).', 'gpt3-ai-content-generator'));
        }
        return ['url' => $qdrant_data['url'], 'api_key' => $qdrant_data['api_key']];
    }
}

/**
 * Handles embedding generation for Qdrant post processing.
 */
class QdrantEmbeddingHandler {
    private $ai_caller;

    public function __construct() {
        if (!class_exists(\WPAICG\Core\AIPKit_AI_Caller::class)) {
            $ai_caller_path = WPAICG_PLUGIN_DIR . 'classes/ai/caller.php';
            if (file_exists($ai_caller_path)) require_once $ai_caller_path;
        }
        if (class_exists(\WPAICG\Core\AIPKit_AI_Caller::class)) {
            $this->ai_caller = new \WPAICG\Core\AIPKit_AI_Caller();
        }
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function generate_embedding(string $content_string, string $embedding_provider, string $embedding_model) {
        return $this->generate_embeddings([$content_string], $embedding_provider, $embedding_model);
    }

    /**
     * @param array<int,string> $content_strings
     * @return mixed[]|\WP_Error
     */
    public function generate_embeddings(array $content_strings, string $embedding_provider, string $embedding_model, array $embedding_options = []) {
        if (!$this->ai_caller) {
            return new WP_Error('ai_caller_missing_qdrant_embed', 'AI Caller component is not available for Qdrant embeddings.');
        }

        $content_strings = array_values($content_strings);
        if (empty($content_strings)) {
            return new WP_Error('embedding_failed_qdrant_embed', 'No content provided for Qdrant embeddings.');
        }

        $embedding_options['model'] = $embedding_model;
        $embedding_result = $this->ai_caller->generate_embeddings($embedding_provider, $content_strings, $embedding_options);
        if (!is_wp_error($embedding_result) && isset($embedding_result['embeddings']) && is_array($embedding_result['embeddings']) && count($embedding_result['embeddings']) === count($content_strings)) {
            return $embedding_result;
        }

        if (is_wp_error($embedding_result) && \WPAICG\Core\AIPKit_HTTP_Request::batch_error_data($embedding_result)['stop_batch']) {
            return $embedding_result;
        }

        if (count($content_strings) === 1) {
            return is_wp_error($embedding_result) ? $embedding_result : new WP_Error('embedding_failed_qdrant_embed', 'No embeddings returned for Qdrant.');
        }

        $embeddings = [];
        foreach ($content_strings as $content_string) {
            $single_result = $this->ai_caller->generate_embeddings($embedding_provider, $content_string, $embedding_options);
            if (is_wp_error($single_result)) {
                return $single_result;
            }
            if (empty($single_result['embeddings'][0]) || !is_array($single_result['embeddings'][0])) {
                return new WP_Error('embedding_failed_qdrant_embed', 'No embeddings returned for Qdrant.');
            }
            $embeddings[] = $single_result['embeddings'][0];
        }

        return ['embeddings' => $embeddings, 'usage' => null];
    }
}

/**
 * Handles indexing WordPress post content into Qdrant Collections.
 */
class QdrantPostProcessor extends AIPKit_Vector_Post_Processor_Base
{
    private const EMBEDDING_BATCH_SIZE = 50;

    private $vector_store_manager;
    private $config_handler;
    private $embedding_handler;

    public function __construct()
    {
        parent::__construct();
        $this->vector_store_manager = $this->create_vector_store_manager();

        $this->config_handler = new QdrantConfig();
        $this->embedding_handler = new QdrantEmbeddingHandler();
    }

    /**
     * Indexes a single post's content to a specified Qdrant collection.
     *
     * @param int $post_id The ID of the post to index.
     * @param string $collection_name The name of the target Qdrant collection.
     * @param string $embedding_provider_key Key of the provider for embeddings.
     * @param string $embedding_model The specific embedding model to use.
     * @return array ['status' => 'success'|'error', 'message' => string]
     */
    public function index_single_post_to_collection(int $post_id, string $collection_name, string $embedding_provider_key, string $embedding_model): array
    {
        $post_obj = get_post($post_id);
        $post_title_for_log = $post_obj ? $post_obj->post_title : 'N/A';

        $provider_lookup = sanitize_key((string) strtolower($embedding_provider_key));
        $embedding_provider_normalized = AIPKit_Providers::resolve_embedding_provider_name(
            $provider_lookup,
            'qdrant_post_processor'
        );
        $base_failure_log = [
            'provider' => 'Qdrant',
            'vector_store_id' => $collection_name,
            'vector_store_name' => $collection_name,
            'post_id' => $post_id,
            'post_title' => $post_title_for_log,
            'embedding_provider' => $provider_lookup,
            'embedding_model' => $embedding_model,
            'source_type_for_log' => 'wordpress_post',
        ];
        if (!is_string($embedding_provider_normalized) || $embedding_provider_normalized === '') {
            $this->log_event(array_merge($base_failure_log, [
                'status' => 'failed',
                'message' => __('Invalid embedding provider for Qdrant indexing.', 'gpt3-ai-content-generator'),
            ]));
            return [
                'status' => 'error',
                'message' => __('Invalid embedding provider for Qdrant indexing.', 'gpt3-ai-content-generator'),
            ];
        }
        $qdrant_parent_id = 'wp_post_' . $post_id;


        $log_entry_base = [
            'provider' => 'Qdrant', 'vector_store_id' => $collection_name, 'vector_store_name' => $collection_name,
            'post_id' => $post_id, 'post_title' => $post_title_for_log,
            'embedding_provider' => $embedding_provider_normalized, 'embedding_model' => $embedding_model,
            'file_id' => $qdrant_parent_id,
            'source_type_for_log' => 'wordpress_post'
        ];

        $return_error = function ($error, string $message = '') use ($log_entry_base): array {
            return $this->indexing_error($error, $log_entry_base, $message);
        };

        if (!$this->embedding_handler || !$this->vector_store_manager || !$this->config_handler) {
            $error_msg = __('Qdrant processing components not available.', 'gpt3-ai-content-generator');
            return $return_error($error_msg);
        }

        $qdrant_api_config = $this->config_handler->get_config();
        if (is_wp_error($qdrant_api_config)) {
            $error_msg = $qdrant_api_config->get_error_message();
            return $return_error($error_msg);
        }

        $content_string_or_error = $this->get_post_content_as_string($post_id);
        if (is_wp_error($content_string_or_error)) {
            $error_msg = 'Content retrieval error: ' . $content_string_or_error->get_error_message();
            return $return_error($error_msg);
        }
        $log_entry_base['indexed_content'] = $content_string_or_error;

        if (empty(trim($content_string_or_error))) {
            $error_msg = __('Post content is empty for Qdrant.', 'gpt3-ai-content-generator');
            return $return_error($error_msg);
        }

        $chunks = $this->prepare_embedding_chunks(
            $content_string_or_error,
            $embedding_model,
            $provider_lookup,
            $collection_name,
            'qdrant',
            'Qdrant'
        );
        if (is_wp_error($chunks)) {
            return $return_error($chunks);
        }

        $embedding_batch_size = $this->resolve_embedding_batch_size(
            $embedding_provider_normalized,
            'Qdrant',
            $embedding_model,
            $collection_name,
            $post_id,
            self::EMBEDDING_BATCH_SIZE
        );

        $points_to_upsert = [];
        $chunk_batches = array_chunk($chunks, $embedding_batch_size);
        $total_chunks = count($chunks);
        $embedding_options = $this->vector_store_manager->embedding_options($embedding_provider_normalized, $embedding_model, 'Qdrant', [$collection_name], $qdrant_api_config);
        if (is_wp_error($embedding_options)) { return $return_error($embedding_options); }

        foreach ($chunk_batches as $chunk_batch) {
            $chunk_texts = array_map(static function ($chunk): string {
                return (string) ($chunk['text'] ?? '');
            }, $chunk_batch);
            $embedding_result = $this->embedding_handler->generate_embeddings($chunk_texts, $embedding_provider_normalized, $embedding_model, $embedding_options);
            if (is_wp_error($embedding_result)) {
                return $return_error($embedding_result, 'Embedding failed: ' . $embedding_result->get_error_message());
            }

            $embedding_vectors = $embedding_result['embeddings'] ?? [];
            if (!is_array($embedding_vectors) || count($embedding_vectors) !== count($chunk_batch)) {
                return $return_error(__('Embedding result count did not match Qdrant chunk count.', 'gpt3-ai-content-generator'));
            }

            foreach ($chunk_batch as $offset => $chunk) {
                $chunk_index = (int) ($chunk['index'] ?? 0);
                $point_id = wp_generate_uuid4();
                $chunk_text = (string) ($chunk['text'] ?? '');
                $payload = [
                    'source' => 'wordpress_post',
                    'post_id' => (string) $post_id,
                    'title' => $post_title_for_log,
                    'type' => get_post_type($post_id),
                    'url' => get_permalink($post_id),
                    'vector_id' => $point_id,
                    'parent_vector_id' => $qdrant_parent_id,
                    'chunk_index' => $chunk_index,
                    'total_chunks' => $total_chunks,
                    'char_start' => (int) ($chunk['start'] ?? 0),
                    'char_end' => (int) ($chunk['end'] ?? 0),
                    'original_content' => $chunk_text,
                ];
                $points_to_upsert[] = [
                    'id' => $point_id,
                    'vector' => $embedding_vectors[$offset],
                    'payload' => $payload,
                ];
            }
        }

        $delete_existing_result = $this->vector_store_manager->delete_vectors(
            'Qdrant',
            $collection_name,
            ['filter' => ['must' => [
                ['key' => 'post_id', 'match' => ['value' => (string) $post_id]],
            ]]],
            $qdrant_api_config
        );
        if (is_wp_error($delete_existing_result)) {
            return $return_error('Deleting existing Qdrant chunks failed: ' . $delete_existing_result->get_error_message());
        }

        $upsert_result = $this->vector_store_manager->upsert_vectors('Qdrant', $collection_name, ['points' => $points_to_upsert], $qdrant_api_config);
        if (is_wp_error($upsert_result)) {
            $error_msg = 'Upsert to Qdrant failed: ' . $upsert_result->get_error_message();
            return $return_error($error_msg);
        }

        $this->log_event(array_merge($log_entry_base, ['status' => 'indexed', 'message' => sprintf('WordPress post content chunked and submitted for indexing. Chunks: %d.', $total_chunks)]));
        update_post_meta($post_id, '_aipkit_indexed_to_vs_' . sanitize_key($collection_name), '1');
        update_post_meta($post_id, '_aipkit_vector_id_for_vs_' . sanitize_key($collection_name), $qdrant_parent_id);

        return ['status' => 'success', 'message' => 'Post content indexed to Qdrant.'];
    }
}
