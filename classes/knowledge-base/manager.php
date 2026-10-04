<?php

namespace WPAICG\Vector;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Connects vector-store providers and dispatches their supported operations.
 */
class AIPKit_Vector_Store_Manager
{
    public function __construct() {
        // Ensure the factory class is available (should be loaded by DependencyLoader)
        if (!class_exists(AIPKit_Vector_Provider_Strategy_Factory::class)) {
            $factory_path = __DIR__ . '/provider-contracts.php';
            if (file_exists($factory_path)) {
                require_once $factory_path;
            }
        }
    }

    /** Match supported embedding output sizes to storage before generating vectors. */
    public function embedding_options(string $embedding_provider, string $model, string $provider, array $targets, array $config = [])
    {
        $options = ['model' => $model];
        $policy = \WPAICG\AIPKit_Providers::embedding_dimension_policy($embedding_provider, $model);
        if ($policy === null) { return $options; }
        $dimension = null;
        foreach (array_unique($targets) as $target) {
            $description = $this->describe_single_index($provider, (string) $target, $config);
            if (is_wp_error($description)) { return $description; }
            $size = $description['dimensions'] ?? $description['dimension'] ?? $description['config']['params']['vectors']['size'] ?? null;
            // Empty Chroma collections acquire their dimension from the first vectors.
            if ($size === null && strtolower($provider) === 'chroma' && ($description['total_vector_count'] ?? null) === 0) { $size = $policy['default']; }
            $size = (int) $size;
            $supported = isset($policy['sizes']) ? in_array($size, $policy['sizes'], true) : ($size >= $policy['min'] && $size <= $policy['max']);
            if (!$supported) {
                return new WP_Error('embedding_dimensions_unsupported', sprintf(
                    /* translators: 1: embedding model; 2: store dimension. */
                    __('The embedding model %1$s does not support the store dimension (%2$d). Choose a compatible model or store.', 'gpt3-ai-content-generator'), $model, $size
                ));
            }
            if ($dimension !== null && $dimension !== $size) {
                return new WP_Error('embedding_store_dimensions_mismatch', __('Selected stores must use the same embedding dimensions.', 'gpt3-ai-content-generator'));
            }
            $dimension = $size;
        }
        if ($dimension === null) { return new WP_Error('embedding_store_missing', __('Choose a knowledge store before generating embeddings.', 'gpt3-ai-content-generator')); }
        if ($policy['parameter'] !== null) { $options[$policy['parameter']] = $dimension; }
        return $options;
    }

    /**
     * Logic for creating an index in the specified vector store if it doesn't already exist.
     *
     * @param string $provider The vector store provider (e.g., 'Pinecone', 'Qdrant', 'OpenAI').
     * @param string $index_name The name of the index to create.
     * @param array $index_config Provider-specific configuration for the index.
     * @param array $provider_config Provider-specific connection/API configuration.
     * @return array|WP_Error The store object (array) on success, WP_Error on failure.
     */
    public function create_index_if_not_exists(string $provider, string $index_name, array $index_config, array $provider_config)
    {
        $strategy = $this->get_connected_strategy($provider, $provider_config);
        if (is_wp_error($strategy)) {
            return $strategy;
        }
        return $strategy->create_index_if_not_exists($index_name, $index_config);
    }

    /**
     * Logic for adding or updating vectors in the specified index.
     *
     * @param string $provider The vector store provider.
     * @param string $index_name The name of the index.
     * @param array $vectors An array of vector objects/data to upsert.
     * @param array $provider_config Provider-specific connection/API configuration.
     * @return array|WP_Error Result of the upsert operation or WP_Error.
     */
    public function upsert_vectors(string $provider, string $index_name, array $vectors, array $provider_config)
    {
        $strategy = $this->get_connected_strategy($provider, $provider_config);
        if (is_wp_error($strategy)) {
            return $strategy;
        }
        return $strategy->upsert_vectors($index_name, $vectors);
    }

    /**
     * Logic for querying vectors from the specified index.
     *
     * @param string $provider The vector store provider.
     * @param string $index_name The name of the index.
     * @param array $query_vector The vector to query against.
     * @param int $top_k The number of nearest neighbors to return.
     * @param array $filter Optional metadata filter.
     * @param array $provider_config Provider-specific connection/API configuration.
     * @return array|WP_Error Array of matching vectors or WP_Error.
     */
    public function query_vectors(string $provider, string $index_name, array $query_vector, int $top_k, array $filter = [], array $provider_config = [])
    {
        $strategy = $this->get_connected_strategy($provider, $provider_config);
        if (is_wp_error($strategy)) {
            return $strategy;
        }
        return $strategy->query_vectors($index_name, $query_vector, $top_k, $filter);
    }

    /**
     * Logic for deleting vectors from the specified index by their IDs.
     *
     * @param string $provider The vector store provider.
     * @param string $index_name The name of the index.
     * @param array $vector_ids An array of vector IDs to delete.
     * @param array $provider_config Provider-specific connection/API configuration.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function delete_vectors(string $provider, string $index_name, array $vector_ids, array $provider_config)
    {
        $strategy = $this->get_connected_strategy($provider, $provider_config);
        if (is_wp_error($strategy)) {
            return $strategy;
        }
        return $strategy->delete_vectors($index_name, $vector_ids);
    }

    /**
     * Logic for deleting an entire index.
     *
     * @param string $provider The vector store provider.
     * @param string $index_name The name of the index to delete.
     * @param array $provider_config Provider-specific connection/API configuration.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function delete_index(string $provider, string $index_name, array $provider_config)
    {
        $strategy = $this->get_connected_strategy($provider, $provider_config);
        if (is_wp_error($strategy)) {
            return $strategy;
        }
        return $strategy->delete_index($index_name);
    }

    /**
     * Logic for listing available indexes (or collections) for a given provider.
     *
     * @param string $provider The vector store provider.
     * @param array $provider_config Provider-specific connection/API configuration.
     * @param int|null $limit The maximum number of items to return.
     * @param string|null $order The order of items ('asc' or 'desc').
     * @param string|null $after A cursor for use in pagination.
     * @param string|null $before A cursor for use in pagination.
     * @return array|WP_Error An array of index names or index detail objects, or WP_Error on failure.
     */
    public function list_all_indexes(string $provider, array $provider_config, ?int $limit = 20, ?string $order = 'desc', ?string $after = null, ?string $before = null)
    {
        $strategy = $this->get_connected_strategy($provider, $provider_config);
        if (is_wp_error($strategy)) {
            return $strategy;
        }
        return $strategy->list_indexes($limit, $order, $after, $before);
    }

    /**
     * Logic for describing an index (or collection), returning its configuration and status.
     *
     * @param string $provider The vector store provider.
     * @param string $index_name The name of the index/collection.
     * @param array $provider_config Provider-specific connection/API configuration.
     * @return array|WP_Error An array containing index details, or WP_Error if not found or on failure.
     */
    public function describe_single_index(string $provider, string $index_name, array $provider_config)
    {
        $strategy = $this->get_connected_strategy($provider, $provider_config);
        if (is_wp_error($strategy)) {
            return $strategy;
        }
        return $strategy->describe_index($index_name);
    }

    /**
     * Logic for listing files in a specific vector store (primarily for OpenAI).
     *
     * @param string $provider The vector store provider (should be 'OpenAI').
     * @param string $vector_store_id The ID of the vector store.
     * @param array $provider_config Provider-specific connection/API configuration.
     * @param array $query_params Optional query parameters for listing.
     * @return array|WP_Error List of file objects or WP_Error.
     */
    public function list_files_in_store(string $provider, string $vector_store_id, array $provider_config, array $query_params = [])
    {
        $strategy = $this->get_connected_strategy($provider, $provider_config);
        if (is_wp_error($strategy)) {
            return $strategy;
        }
        if (method_exists($strategy, 'list_vector_store_files')) {
            return $strategy->list_vector_store_files($vector_store_id, $query_params);
        }
        return new WP_Error('method_not_supported', __('Listing files is not supported by this provider strategy.', 'gpt3-ai-content-generator'));
    }

    /**
     * Helper to get and connect a strategy.
     *
     * @param string $provider The vector store provider name.
     * @param array $provider_config Provider-specific connection/API configuration.
     * @return AIPKit_Vector_Provider_Strategy_Interface|WP_Error The connected strategy instance or WP_Error.
     */
    private function get_connected_strategy(string $provider, array $provider_config)
    {
        if (!class_exists(AIPKit_Vector_Provider_Strategy_Factory::class)) {
            // This should ideally be caught by the main class constructor or dependency loader
            return new WP_Error('factory_missing', __('Vector Provider Strategy Factory is not available.', 'gpt3-ai-content-generator'));
        }

        $strategy = AIPKit_Vector_Provider_Strategy_Factory::get_strategy($provider);
        if (is_wp_error($strategy)) {
            return $strategy;
        }

        $connect_result = $strategy->connect($provider_config);
        if (is_wp_error($connect_result) || $connect_result === false) {
            /* translators: %s is the vector store provider name */
            return is_wp_error($connect_result) ? $connect_result : new WP_Error('connection_failed', sprintf(__('Failed to connect to %s vector store.', 'gpt3-ai-content-generator'), $provider));
        }
        return $strategy;
    }
}
