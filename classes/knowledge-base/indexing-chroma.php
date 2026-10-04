<?php

namespace WPAICG\Vector\PostProcessor\Chroma;

use WPAICG\AIPKit_Providers;
use WPAICG\Core\AIPKit_AI_Caller;
use WPAICG\Vector\PostProcessor\Base\AIPKit_Vector_Post_Processor_Base;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists(AIPKit_Vector_Post_Processor_Base::class)) {
    $aipkit_base_class_path = WPAICG_PLUGIN_DIR . 'classes/knowledge-base/indexing.php';
    if (file_exists($aipkit_base_class_path)) {
        require_once $aipkit_base_class_path;
    }
}

/**
 * Handles fetching Chroma API configuration for post processing.
 */
class ChromaConfig
{
    /**
     * @return mixed[]|\WP_Error
     */
    public function get_config()
    {
        if (!class_exists(\WPAICG\AIPKit_Providers::class)) {
            $providers_path = WPAICG_PLUGIN_DIR . 'classes/ai/settings.php';
            if (file_exists($providers_path)) {
                require_once $providers_path;
            } else {
                return new WP_Error('dependency_missing_config_chroma', 'AIPKit_Providers class not found for Chroma config.');
            }
        }

        $chroma_data = AIPKit_Providers::get_provider_data('Chroma');
        if (empty($chroma_data['url'])) {
            return new WP_Error('missing_chroma_url_config', __('Chroma URL is not configured in global settings.', 'gpt3-ai-content-generator'));
        }

        return [
            'url' => $chroma_data['url'],
            'api_key' => $chroma_data['api_key'] ?? '',
            'tenant' => $chroma_data['tenant'] ?? 'default_tenant',
            'database' => $chroma_data['database'] ?? 'default_database',
        ];
    }
}

/**
 * Handles embedding generation for Chroma post processing.
 */
class ChromaEmbeddingHandler
{
    private $ai_caller;

    public function __construct()
    {
        if (!class_exists(\WPAICG\Core\AIPKit_AI_Caller::class)) {
            $ai_caller_path = WPAICG_PLUGIN_DIR . 'classes/ai/caller.php';
            if (file_exists($ai_caller_path)) {
                require_once $ai_caller_path;
            }
        }
        if (class_exists(\WPAICG\Core\AIPKit_AI_Caller::class)) {
            $this->ai_caller = new AIPKit_AI_Caller();
        }
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function generate_embedding(string $content_string, string $embedding_provider, string $embedding_model)
    {
        $embedding_result = $this->generate_embeddings([$content_string], $embedding_provider, $embedding_model);
        if (is_wp_error($embedding_result)) {
            return $embedding_result;
        }

        return $embedding_result;
    }

    /**
     * @param array<int,string> $content_strings
     * @return mixed[]|\WP_Error
     */
    public function generate_embeddings(array $content_strings, string $embedding_provider, string $embedding_model, array $embedding_options = [])
    {
        if (!$this->ai_caller) {
            return new WP_Error('ai_caller_missing_chroma_embed', 'AI Caller component is not available for Chroma embeddings.');
        }

        $content_strings = array_values($content_strings);
        if (empty($content_strings)) {
            return new WP_Error('embedding_failed_chroma_embed', 'No content provided for Chroma embeddings.');
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
            return is_wp_error($embedding_result) ? $embedding_result : new WP_Error('embedding_failed_chroma_embed', 'No embeddings returned for Chroma.');
        }

        $embeddings = [];
        foreach ($content_strings as $content_string) {
            $single_result = $this->ai_caller->generate_embeddings($embedding_provider, $content_string, $embedding_options);
            if (is_wp_error($single_result)) {
                return $single_result;
            }
            if (empty($single_result['embeddings'][0]) || !is_array($single_result['embeddings'][0])) {
                return new WP_Error('embedding_failed_chroma_embed', 'No embeddings returned for Chroma.');
            }
            $embeddings[] = $single_result['embeddings'][0];
        }

        return ['embeddings' => $embeddings, 'usage' => null];
    }
}

/**
 * Handles indexing WordPress post content into Chroma collections.
 */
class ChromaPostProcessor extends AIPKit_Vector_Post_Processor_Base
{
    private const EMBEDDING_BATCH_SIZE = 50;

    private $vector_store_manager;
    private $config_handler;
    private $embedding_handler;

    public function __construct()
    {
        parent::__construct();
        $this->vector_store_manager = $this->create_vector_store_manager();

        $this->config_handler = new ChromaConfig();

        $this->embedding_handler = new ChromaEmbeddingHandler();
    }

    /**
     * Indexes a single post's content to a specified Chroma collection.
     *
     * @param int $post_id The ID of the post to index.
     * @param string $collection_name The name of the target Chroma collection.
     * @param string $embedding_provider_key Key of the provider for embeddings.
     * @param string $embedding_model The specific embedding model to use.
     * @return array{status:string,message:string}
     */
    public function index_single_post_to_collection(int $post_id, string $collection_name, string $embedding_provider_key, string $embedding_model): array
    {
        $post_obj = get_post($post_id);
        $post_title_for_log = $post_obj ? $post_obj->post_title : 'N/A';
        $provider_lookup = sanitize_key((string) strtolower($embedding_provider_key));
        $embedding_provider_normalized = AIPKit_Providers::resolve_embedding_provider_name(
            $provider_lookup,
            'chroma_post_processor'
        );

        $base_log = [
            'provider' => 'Chroma',
            'vector_store_id' => $collection_name,
            'vector_store_name' => $collection_name,
            'post_id' => $post_id,
            'post_title' => $post_title_for_log,
            'embedding_provider' => $provider_lookup,
            'embedding_model' => $embedding_model,
            'source_type_for_log' => 'wordpress_post',
        ];

        if (!is_string($embedding_provider_normalized) || $embedding_provider_normalized === '') {
            $error_msg = __('Invalid embedding provider for Chroma indexing.', 'gpt3-ai-content-generator');
            $this->log_event(array_merge($base_log, [
                'status' => 'failed',
                'message' => $error_msg,
            ]));
            return ['status' => 'error', 'message' => $error_msg];
        }

        $chroma_record_id = 'wp_post_' . $post_id;
        $log_entry_base = array_merge($base_log, [
            'embedding_provider' => $embedding_provider_normalized,
            'file_id' => $chroma_record_id,
        ]);

        $return_error = function ($error, string $message = '') use ($log_entry_base): array {
            return $this->indexing_error($error, $log_entry_base, $message);
        };

        if (!$this->embedding_handler || !$this->vector_store_manager || !$this->config_handler) {
            return $return_error(__('Chroma processing components not available.', 'gpt3-ai-content-generator'));
        }

        $chroma_api_config = $this->config_handler->get_config();
        if (is_wp_error($chroma_api_config)) {
            return $return_error($chroma_api_config->get_error_message());
        }

        $content_string_or_error = $this->get_post_content_as_string($post_id);
        if (is_wp_error($content_string_or_error)) {
            return $return_error('Content retrieval error: ' . $content_string_or_error->get_error_message());
        }
        $log_entry_base['indexed_content'] = $content_string_or_error;

        if (trim($content_string_or_error) === '') {
            return $return_error(__('Post content is empty for Chroma.', 'gpt3-ai-content-generator'));
        }

        $chunks = $this->prepare_embedding_chunks(
            $content_string_or_error,
            $embedding_model,
            $provider_lookup,
            $collection_name,
            'chroma',
            'Chroma'
        );
        if (is_wp_error($chunks)) {
            return $return_error($chunks);
        }

        $embedding_batch_size = $this->resolve_embedding_batch_size(
            $embedding_provider_normalized,
            'Chroma',
            $embedding_model,
            $collection_name,
            $post_id,
            self::EMBEDDING_BATCH_SIZE
        );

        $records_to_upsert = [];
        $chunk_batches = array_chunk($chunks, $embedding_batch_size);
        $total_chunks = count($chunks);
        $embedding_options = $this->vector_store_manager->embedding_options($embedding_provider_normalized, $embedding_model, 'Chroma', [$collection_name], $chroma_api_config);
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
                return $return_error(__('Embedding result count did not match Chroma chunk count.', 'gpt3-ai-content-generator'));
            }

            foreach ($chunk_batch as $offset => $chunk) {
                $chunk_index = (int) ($chunk['index'] ?? 0);
                $record_id = $total_chunks === 1 ? $chroma_record_id : $chroma_record_id . '_chunk_' . $chunk_index;
                $metadata = [
                    'source' => 'wordpress_post',
                    'post_id' => (string) $post_id,
                    'title' => $post_title_for_log,
                    'type' => get_post_type($post_id),
                    'url' => get_permalink($post_id),
                    'vector_id' => $record_id,
                    'parent_vector_id' => $chroma_record_id,
                    'chunk_index' => $chunk_index,
                    'total_chunks' => $total_chunks,
                    'char_start' => (int) ($chunk['start'] ?? 0),
                    'char_end' => (int) ($chunk['end'] ?? 0),
                ];
                $records_to_upsert[] = [
                    'id' => $record_id,
                    'vector' => $embedding_vectors[$offset],
                    'payload' => $metadata,
                    'document' => (string) ($chunk['text'] ?? ''),
                ];
            }
        }

        $delete_existing_result = $this->vector_store_manager->delete_vectors(
            'Chroma',
            $collection_name,
            ['where' => ['$or' => [
                ['post_id' => (string) $post_id],
                ['post_id' => $post_id],
            ]]],
            $chroma_api_config
        );
        if (is_wp_error($delete_existing_result)) {
            return $return_error('Deleting existing Chroma chunks failed: ' . $delete_existing_result->get_error_message());
        }

        $upsert_result = $this->vector_store_manager->upsert_vectors('Chroma', $collection_name, ['points' => $records_to_upsert], $chroma_api_config);
        if (is_wp_error($upsert_result)) {
            return $return_error('Upsert to Chroma failed: ' . $upsert_result->get_error_message());
        }

        $this->log_event(array_merge($log_entry_base, [
            'status' => 'indexed',
            'message' => sprintf('WordPress post content chunked and submitted for indexing. Chunks: %d.', $total_chunks),
        ]));
        update_post_meta($post_id, '_aipkit_indexed_to_vs_' . sanitize_key($collection_name), '1');
        update_post_meta($post_id, '_aipkit_vector_id_for_vs_' . sanitize_key($collection_name), $chroma_record_id);

        return ['status' => 'success', 'message' => 'Post content indexed to Chroma.'];
    }
}
