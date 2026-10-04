<?php

namespace WPAICG\Vector;

use WPAICG\AIPKit_Providers;
use WPAICG\Core\AIPKit_AI_Caller;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Chunks, embeds, and upserts plain text into external vector stores.
 */
class AIPKit_Vector_Text_Ingestion_Service
{
    private const EMBEDDING_BATCH_SIZE = 20;

    private ?AIPKit_Vector_Store_Manager $vector_store_manager;
    private ?AIPKit_AI_Caller $ai_caller;

    public function __construct(?AIPKit_Vector_Store_Manager $vector_store_manager = null, ?AIPKit_AI_Caller $ai_caller = null)
    {
        $this->vector_store_manager = $vector_store_manager;
        $this->ai_caller = $ai_caller;
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $provider_config
     * @param array<string,mixed> $options
     * @return array<string,mixed>|WP_Error
     */
    public function ingest_text(
        string $provider,
        string $target_id,
        string $text,
        string $embedding_provider_key,
        string $embedding_model,
        array $metadata,
        array $provider_config,
        array $options = []
    ) {
        $provider_label = self::normalize_provider_label($provider);
        if ($provider_label === '') {
            return new WP_Error('invalid_vector_text_provider', __('Unsupported vector provider for text ingestion.', 'gpt3-ai-content-generator'), ['status' => 400]);
        }
        if (!$this->vector_store_manager || !$this->ai_caller) {
            return new WP_Error('vector_text_ingestion_deps_missing', __('Vector text ingestion components are not available.', 'gpt3-ai-content-generator'), ['status' => 500]);
        }
        if (trim($target_id) === '') {
            return new WP_Error('missing_vector_text_target', __('Vector store target is required.', 'gpt3-ai-content-generator'), ['status' => 400]);
        }
        if (trim($text) === '') {
            return new WP_Error('missing_vector_text_content', __('Text content cannot be empty.', 'gpt3-ai-content-generator'), ['status' => 400]);
        }
        if (trim($embedding_provider_key) === '' || trim($embedding_model) === '') {
            return new WP_Error('missing_vector_text_embedding_config', __('Embedding provider and model are required.', 'gpt3-ai-content-generator'), ['status' => 400]);
        }

        if (!class_exists(AIPKit_Providers::class)) {
            $providers_path = WPAICG_PLUGIN_DIR . 'classes/ai/settings.php';
            if (file_exists($providers_path)) {
                require_once $providers_path;
            }
        }
        if (!class_exists(AIPKit_Providers::class)) {
            return new WP_Error('vector_text_providers_missing', __('Provider registry is not available.', 'gpt3-ai-content-generator'), ['status' => 500]);
        }

        $embedding_provider_lookup = sanitize_key((string) strtolower($embedding_provider_key));
        $embedding_provider_normalized = AIPKit_Providers::resolve_embedding_provider_name(
            $embedding_provider_lookup,
            'vector_text_ingestion'
        );
        if (!is_string($embedding_provider_normalized) || $embedding_provider_normalized === '') {
            return new WP_Error('invalid_vector_text_embedding_provider', __('Invalid embedding provider for text ingestion.', 'gpt3-ai-content-generator'), ['status' => 400]);
        }

        $chunks = AIPKit_Vector_Text_Chunker::chunk_for_embeddings(
            $text,
            $embedding_model,
            $embedding_provider_lookup,
            $target_id,
            strtolower($provider_label)
        );
        if (empty($chunks)) {
            return new WP_Error('vector_text_chunking_failed', __('Could not prepare text chunks for indexing.', 'gpt3-ai-content-generator'), ['status' => 400]);
        }

        $parent_vector_id = self::resolve_parent_vector_id($metadata);
        $source_type = self::resolve_source_type($metadata);
        $metadata = self::normalize_metadata($metadata);
        $metadata['source'] = $source_type;

        $delete_selector = self::build_parent_delete_selector($provider_label, $parent_vector_id);
        $namespace = isset($options['namespace']) && is_scalar($options['namespace'])
            ? sanitize_text_field((string) $options['namespace'])
            : '';
        if ($provider_label === 'Pinecone' && $namespace !== '') {
            $delete_selector['namespace'] = $namespace;
        }

        $embedding_options = $this->vector_store_manager->embedding_options($embedding_provider_normalized, $embedding_model, $provider_label, [$target_id], $provider_config);
        if (is_wp_error($embedding_options)) { return $embedding_options; }

        $total_chunks = count($chunks);
        $total_upserted = 0;
        $prepared_records = [];
        $first_record_id = null;
        $last_upsert_result = [];
        if (!class_exists(AIPKit_Vector_Embedding_Batch_Policy::class)) {
            $batch_policy_path = WPAICG_PLUGIN_DIR . 'classes/knowledge-base/embedding-batches.php';
            if (file_exists($batch_policy_path)) {
                require_once $batch_policy_path;
            }
        }
        $batch_size = class_exists(AIPKit_Vector_Embedding_Batch_Policy::class)
            ? AIPKit_Vector_Embedding_Batch_Policy::resolve_batch_size(
                $embedding_provider_normalized,
                [
                    'source' => 'text_ingestion',
                    'vector_provider' => $provider_label,
                    'embedding_model' => $embedding_model,
                    'target_id' => $target_id,
                ],
                self::EMBEDDING_BATCH_SIZE
            )
            : self::EMBEDDING_BATCH_SIZE;
        $batch_size = (int) apply_filters(
            'aipkit_vector_text_ingestion_embedding_batch_size',
            $batch_size,
            $provider_label,
            $embedding_provider_normalized,
            $embedding_model,
            $target_id
        );
        $batch_size = max(1, min(100, $batch_size));

        foreach (array_chunk($chunks, $batch_size) as $chunk_batch) {
            $chunk_texts = array_map(static function (array $chunk): string {
                return (string) ($chunk['text'] ?? '');
            }, $chunk_batch);

            $embedding_result = $this->generate_embeddings($chunk_texts, $embedding_provider_normalized, $embedding_options);
            if (is_wp_error($embedding_result)) {
                return $embedding_result;
            }

            $embedding_vectors = $embedding_result['embeddings'] ?? [];
            if (!is_array($embedding_vectors) || count($embedding_vectors) !== count($chunk_batch)) {
                return new WP_Error('vector_text_embedding_count_mismatch', __('Embedding result count did not match chunk count.', 'gpt3-ai-content-generator'), ['status' => 500]);
            }

            foreach ($chunk_batch as $offset => $chunk) {
                $vector = $embedding_vectors[$offset];
                if (!is_array($vector) || !$vector || count(array_filter($vector, static function ($value): bool { return is_numeric($value) && is_finite((float) $value); })) !== count($vector)) {
                    return new WP_Error('vector_text_embedding_invalid', __('The provider returned an invalid embedding vector.', 'gpt3-ai-content-generator'));
                }
                $record = self::build_record(
                    $provider_label,
                    $parent_vector_id,
                    $chunk,
                    $embedding_vectors[$offset],
                    $metadata,
                    $total_chunks
                );
                if ($first_record_id === null) {
                    $first_record_id = (string) ($record['id'] ?? '');
                }
                $prepared_records[] = $record;
            }

        }

        // Embed the entire replacement before changing existing searchable content.
        if ($provider_label === 'Local') {
            $last_upsert_result = $this->vector_store_manager->upsert_vectors($provider_label, $target_id, [
                'points' => $prepared_records,
                'replace_parent_id' => $parent_vector_id,
            ], $provider_config);
            if (is_wp_error($last_upsert_result)) { return $last_upsert_result; }
            $total_upserted = count($prepared_records);
        } else {
            require_once WPAICG_PLUGIN_DIR . 'classes/automations/lock.php';
            $lock_name = 'aipkit_vector_replace_' . md5($provider_label . '|' . $target_id . '|' . $namespace . '|' . $parent_vector_id);
            $lock_token = \WPAICG\AutoGPT\Cron\AIPKit_Option_Lock::acquire($lock_name, 30 * MINUTE_IN_SECONDS);
            if ($lock_token === '') {
                return new WP_Error('vector_text_update_busy', __('This source is already being updated. Please retry indexing.', 'gpt3-ai-content-generator'));
            }
            try {
                $attempted_ids = [];
                foreach (array_chunk($prepared_records, $batch_size) as $records) {
                    $attempted_ids = array_merge($attempted_ids, array_column($records, 'id'));
                    $upsert_payload = $provider_label === 'Pinecone' ? ['vectors' => $records] : ['points' => $records];
                    if ($provider_label === 'Pinecone' && $namespace !== '') { $upsert_payload['namespace'] = $namespace; }
                    $upsert_result = $this->vector_store_manager->upsert_vectors($provider_label, $target_id, $upsert_payload, $provider_config);
                    if (is_wp_error($upsert_result)) {
                        // Only this attempt's IDs are removed, including a possibly partial failed batch.
                        $selector = $provider_label === 'Qdrant' ? ['points' => $attempted_ids] : ['ids' => $attempted_ids];
                        if ($provider_label === 'Pinecone' && $namespace !== '') { $selector['namespace'] = $namespace; }
                        $this->vector_store_manager->delete_vectors($provider_label, $target_id, $selector, $provider_config);
                        return $upsert_result;
                    }
                    $last_upsert_result = is_array($upsert_result) ? $upsert_result : [];
                    $total_upserted += count($records);
                }
                $lock = get_option($lock_name, []);
                if (!is_array($lock) || ($lock['token'] ?? '') !== $lock_token) {
                    return new WP_Error('vector_text_update_lock_lost', __('The source is being updated by another request. Please retry indexing.', 'gpt3-ai-content-generator'));
                }
                // New records have distinct IDs. Remove older chunks only after all writes succeeded.
                if ($provider_label === 'Pinecone') {
                    $delete_selector['filter'] = ['$and' => [$delete_selector['filter'], ['vector_id' => ['$nin' => $attempted_ids]]]];
                } elseif ($provider_label === 'Qdrant') {
                    $delete_selector['filter']['must_not'] = [['has_id' => $attempted_ids]];
                } else {
                    $delete_selector['where'] = ['$and' => [$delete_selector['where'], ['vector_id' => ['$nin' => $attempted_ids]]]];
                }
                $deleted = $this->vector_store_manager->delete_vectors($provider_label, $target_id, $delete_selector, $provider_config);
                if (is_wp_error($deleted)) { return $deleted; }
                if ($deleted === false) { return new WP_Error('vector_text_cleanup_failed', __('Replacement chunks were saved, but older chunks could not be removed. Retry indexing this source.', 'gpt3-ai-content-generator')); }
            } finally {
                \WPAICG\AutoGPT\Cron\AIPKit_Option_Lock::release($lock_name, $lock_token);
            }
        }

        return [
            'provider' => $provider_label,
            'target_id' => $target_id,
            'parent_vector_id' => $parent_vector_id,
            'first_record_id' => $first_record_id ?: $parent_vector_id,
            'source_type' => $source_type,
            'total_chunks' => $total_chunks,
            'upserted_count' => $total_upserted,
            'embedding_provider' => $embedding_provider_normalized,
            'embedding_model' => $embedding_model,
            'upsert_result' => $last_upsert_result,
        ];
    }

    /**
     * @param array<int,string> $content_strings
     * @return array<string,mixed>|WP_Error
     */
    private function generate_embeddings(array $content_strings, string $embedding_provider, array $embedding_options)
    {
        $content_strings = array_values(array_filter($content_strings, static function (string $content): bool {
            return $content !== '';
        }));
        if (empty($content_strings)) {
            return new WP_Error('vector_text_empty_embedding_batch', __('No text chunks were available for embedding.', 'gpt3-ai-content-generator'), ['status' => 400]);
        }

        $embedding_result = $this->ai_caller->generate_embeddings($embedding_provider, $content_strings, $embedding_options);
        if (!is_wp_error($embedding_result) && isset($embedding_result['embeddings']) && is_array($embedding_result['embeddings']) && count($embedding_result['embeddings']) === count($content_strings)) {
            return $embedding_result;
        }

        if (is_wp_error($embedding_result) && AIPKit_AI_Caller::batch_error_data($embedding_result)['stop_batch']) { return $embedding_result; }

        if (count($content_strings) === 1) {
            return is_wp_error($embedding_result)
                ? $embedding_result
                : new WP_Error('vector_text_embedding_failed', __('No embeddings were returned for the text chunk.', 'gpt3-ai-content-generator'), ['status' => 500]);
        }

        $embeddings = [];
        foreach ($content_strings as $content_string) {
            $single_result = $this->ai_caller->generate_embeddings($embedding_provider, $content_string, $embedding_options);
            if (is_wp_error($single_result)) {
                return $single_result;
            }
            if (empty($single_result['embeddings'][0]) || !is_array($single_result['embeddings'][0])) {
                return new WP_Error('vector_text_embedding_failed', __('No embeddings were returned for a text chunk.', 'gpt3-ai-content-generator'), ['status' => 500]);
            }
            $embeddings[] = $single_result['embeddings'][0];
        }

        return ['embeddings' => $embeddings, 'usage' => null];
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private static function resolve_parent_vector_id(array $metadata): string
    {
        foreach (['parent_vector_id', 'vector_id', 'id'] as $key) {
            if (!empty($metadata[$key]) && is_scalar($metadata[$key])) {
                $id = self::sanitize_vector_id((string) $metadata[$key]);
                if ($id !== '') {
                    return $id;
                }
            }
        }

        return 'text_' . wp_generate_uuid4();
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private static function resolve_source_type(array $metadata): string
    {
        $source = !empty($metadata['source']) && is_scalar($metadata['source'])
            ? sanitize_key((string) $metadata['source'])
            : 'text_entry_global_form';

        return $source !== '' ? $source : 'text_entry_global_form';
    }

    private static function sanitize_vector_id(string $id): string
    {
        $id = sanitize_text_field($id);
        $id = (string) preg_replace('/[^A-Za-z0-9_.:-]/', '_', $id);
        $id = trim($id, '_.:-');
        if ($id === '') {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($id, 0, 80) : substr($id, 0, 80);
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private static function normalize_metadata(array $metadata): array
    {
        $normalized = [];
        foreach ($metadata as $key => $value) {
            $key = sanitize_key((string) $key);
            if ($key === '' || $key === 'original_content') {
                continue;
            }
            if (is_bool($value) || is_int($value) || is_float($value)) {
                $normalized[$key] = $value;
            } elseif (is_scalar($value)) {
                $normalized[$key] = sanitize_text_field((string) $value);
            }
        }

        return $normalized;
    }

    /**
     * @param array{text?:string,start?:int,end?:int,index?:int} $chunk
     * @param array<int|float> $embedding
     * @param array<string,mixed> $base_metadata
     * @return array<string,mixed>
     */
    private static function build_record(
        string $provider_label,
        string $parent_vector_id,
        array $chunk,
        array $embedding,
        array $base_metadata,
        int $total_chunks
    ): array {
        $chunk_index = (int) ($chunk['index'] ?? 0);
        $chunk_text = (string) ($chunk['text'] ?? '');
        $record_id = wp_generate_uuid4();
        $metadata = array_merge($base_metadata, [
            'vector_id' => $record_id,
            'parent_vector_id' => $parent_vector_id,
            'chunk_index' => $chunk_index,
            'total_chunks' => $total_chunks,
            'char_start' => (int) ($chunk['start'] ?? 0),
            'char_end' => (int) ($chunk['end'] ?? 0),
        ]);

        if ($provider_label !== 'Chroma') {
            $metadata['original_content'] = $chunk_text;
        }

        if ($provider_label === 'Pinecone') {
            return [
                'id' => $record_id,
                'values' => $embedding,
                'metadata' => $metadata,
            ];
        }

        $record = [
            'id' => $record_id,
            'vector' => $embedding,
            'payload' => $metadata,
        ];
        if ($provider_label === 'Chroma') {
            $record['document'] = $chunk_text;
            if (!empty($metadata['uri']) && is_scalar($metadata['uri'])) {
                $record['uri'] = (string) $metadata['uri'];
            }
        }

        return $record;
    }

    /**
     * @return array<string,mixed>
     */
    private static function build_parent_delete_selector(string $provider_label, string $parent_vector_id): array
    {
        if ($provider_label === 'Pinecone') {
            return ['filter' => ['$or' => [
                ['parent_vector_id' => ['$eq' => $parent_vector_id]],
                ['vector_id' => ['$eq' => $parent_vector_id]],
            ]]];
        }

        if ($provider_label === 'Qdrant' || $provider_label === 'Local') {
            return ['filter' => ['should' => [
                ['key' => 'parent_vector_id', 'match' => ['value' => $parent_vector_id]],
                ['key' => 'vector_id', 'match' => ['value' => $parent_vector_id]],
            ]]];
        }

        return ['where' => ['$or' => [
            ['parent_vector_id' => $parent_vector_id],
            ['vector_id' => $parent_vector_id],
        ]]];
    }

    private static function normalize_provider_label(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if ($provider === 'pinecone') {
            return 'Pinecone';
        }
        if ($provider === 'qdrant') {
            return 'Qdrant';
        }
        if ($provider === 'chroma') {
            return 'Chroma';
        }
        if ($provider === 'local') {
            return 'Local';
        }

        return '';
    }
}
