<?php

namespace WPAICG\Vector;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * AIPKit_Vector_Store_Registry
 *
 * Manages a WordPress option to store a list/cache of vector stores
 * from raw vector providers and hosted knowledge providers.
 */
class AIPKit_Vector_Store_Registry {

    const OPTION_NAME = 'aipkit_vector_stores_registry';
    private const PROVIDER_CACHE_CONFIG = [
        'OpenAI' => [
            'option' => '',
            'model_key' => 'OpenAIVectorStores',
        ],
        'Pinecone' => [
            'option' => 'aipkit_pinecone_index_list',
            'model_key' => 'PineconeIndexes',
        ],
        'Qdrant' => [
            'option' => 'aipkit_qdrant_collection_list',
            'model_key' => 'QdrantCollections',
        ],
        'Chroma' => [
            'option' => 'aipkit_chroma_collection_list',
            'model_key' => 'ChromaCollections',
        ],
        'Google' => [
            'option' => 'aipkit_google_file_search_store_list',
            'model_key' => 'GoogleFileSearchStores',
        ],
    ];

    /**
     * Gets all registered vector stores from the option.
     *
     * @return array An associative array where keys are provider names
     *               (e.g., 'OpenAI', 'Pinecone') and values are arrays of store objects.
     *               Example: ['OpenAI' => [['id' => 'vs_1', 'name' => 'Store 1'], ...]]
     */
    public static function get_all_registered_stores(): array {
        return get_option(self::OPTION_NAME, []);
    }

    /**
     * Gets registered vector stores for a specific provider.
     *
     * @param string $provider The provider name (e.g., 'OpenAI', 'Pinecone').
     * @return array An array of store objects for the given provider.
     */
    public static function get_registered_stores_by_provider(string $provider): array {
        $all_stores = self::get_all_registered_stores();
        return $all_stores[$provider] ?? [];
    }

    /**
     * Updates the list of registered stores for a specific provider.
     * This will overwrite any existing stores for that provider.
     *
     * @param string $provider The provider name.
     * @param array $stores_list An array of store objects (e.g., from API response).
     *                           Each object should ideally have at least 'id' and 'name'.
     *                           For Pinecone, 'id' will be the 'name' of the index.
     * @param bool $publish_model_registry Whether to mirror this write to the universal registry.
     */
    public static function update_registered_stores_for_provider(string $provider, array $stores_list, bool $publish_model_registry = true): void {
        self::replace_provider_cache($provider, $stores_list, $publish_model_registry);
    }

    /**
     * Replaces the full provider cache with the exact incoming list.
     *
     * @param string $provider Provider name.
     * @param array<int, mixed> $stores_list Live stores/indexes/collections.
     * @param bool $publish_model_registry Whether to mirror this write to the universal registry.
     * @return array<int, array<string, mixed>> Normalized stores written to cache.
     */
    public static function replace_provider_cache(string $provider, array $stores_list, bool $publish_model_registry = true): array {
        $provider = self::normalize_provider_name($provider);
        if ($provider === '') {
            return [];
        }

        $normalized_stores = self::normalize_stores_for_provider($provider, $stores_list);
        $all_stores = self::get_all_registered_stores();
        $all_stores[$provider] = $normalized_stores;
        update_option(self::OPTION_NAME, $all_stores, false);
        wp_cache_delete(self::OPTION_NAME, 'options');

        self::persist_provider_option_cache($provider, $normalized_stores);
        self::clear_provider_runtime_cache($provider);
        if ($publish_model_registry) {
            self::publish_model_registry_catalog($provider, $normalized_stores);
        }

        return $normalized_stores;
    }

    /**
     * Adds or updates a single registered store for a specific provider.
     *
     * @param string $provider The provider name.
     * @param array $store_data The store data object (must include 'id', or 'name' for Pinecone).
     */
    public static function add_registered_store(string $provider, array $store_data): void {
        $provider = self::normalize_provider_name($provider);
        $store_data = self::normalize_store_for_provider($provider, $store_data);
        if ($provider === '' || empty($store_data)) {
            return;
        }

        $provider_stores = self::get_registered_stores_by_provider($provider);
        $provider_stores = self::normalize_stores_for_provider($provider, $provider_stores);

        $found = false;
        foreach ($provider_stores as $key => $existing_store) {
            if (self::store_matches_id($existing_store, (string) $store_data['id'])) {
                $provider_stores[$key] = array_merge($existing_store, $store_data, ['provider' => $provider]);
                $found = true;
                break;
            }
        }

        if (!$found) {
            $provider_stores[] = $store_data;
        }

        self::replace_provider_cache($provider, $provider_stores);
    }

    /**
     * Removes a single registered store for a specific provider by its ID.
     * For Pinecone, $store_id is the index name.
     *
     * @param string $provider The provider name.
     * @param string $store_id The ID of the store to remove.
     */
    public static function remove_registered_store(string $provider, string $store_id): void {
        $provider = self::normalize_provider_name($provider);
        $store_id = trim($store_id);
        if ($provider === '' || $store_id === '') {
            return;
        }

        $provider_stores = self::get_registered_stores_by_provider($provider);
        if (empty($provider_stores)) {
            $provider_stores = self::get_provider_option_cache($provider);
        }

        $updated_provider_stores = array_values(array_filter(
            self::normalize_stores_for_provider($provider, $provider_stores),
            static function (array $store) use ($store_id): bool {
                return !self::store_matches_id($store, $store_id);
            }
        ));

        self::replace_provider_cache($provider, $updated_provider_stores);
    }

    /**
     * Clears all registered stores for a specific provider or all providers.
     *
     * @param string|null $provider If null, clears all stores. Otherwise, clears for the specified provider.
     */
    public static function clear_registered_stores(?string $provider = null): void {
        if ($provider === null) {
            delete_option(self::OPTION_NAME);
            wp_cache_delete(self::OPTION_NAME, 'options');
            foreach (array_keys(self::PROVIDER_CACHE_CONFIG) as $provider_name) {
                self::persist_provider_option_cache($provider_name, []);
                self::clear_provider_runtime_cache($provider_name);
                self::invalidate_model_registry_catalog($provider_name);
            }
        } else {
            $provider = self::normalize_provider_name($provider);
            $all_stores = self::get_all_registered_stores();
            if (isset($all_stores[$provider])) {
                unset($all_stores[$provider]);
                update_option(self::OPTION_NAME, $all_stores, false);
                wp_cache_delete(self::OPTION_NAME, 'options');
            }
            self::persist_provider_option_cache($provider, []);
            self::clear_provider_runtime_cache($provider);
            self::invalidate_model_registry_catalog($provider);
        }
    }

    private static function normalize_provider_name(string $provider): string {
        $provider = strtolower(trim($provider));
        $map = [
            'openai' => 'OpenAI',
            'pinecone' => 'Pinecone',
            'qdrant' => 'Qdrant',
            'chroma' => 'Chroma',
            'google' => 'Google',
            'google_file_search' => 'Google',
            'google file search' => 'Google',
        ];

        return $map[$provider] ?? '';
    }

    /**
     * @param array<int, mixed> $stores_list
     * @return array<int, array<string, mixed>>
     */
    private static function normalize_stores_for_provider(string $provider, array $stores_list): array {
        $normalized = [];
        $seen = [];
        foreach ($stores_list as $store_data) {
            $store = self::normalize_store_for_provider($provider, $store_data);
            if (empty($store)) {
                continue;
            }
            $id = (string) $store['id'];
            if (isset($seen[$id])) {
                $normalized[$seen[$id]] = array_merge($normalized[$seen[$id]], $store);
                continue;
            }
            $seen[$id] = count($normalized);
            $normalized[] = $store;
        }

        return $normalized;
    }

    /**
     * @param mixed $store_data
     * @return array<string, mixed>
     */
    private static function normalize_store_for_provider(string $provider, $store_data): array {
        if ($provider === '') {
            return [];
        }
        if (is_string($store_data) || is_numeric($store_data)) {
            $store_data = [
                'id' => (string) $store_data,
                'name' => (string) $store_data,
            ];
        }
        if (!is_array($store_data)) {
            return [];
        }

        $store_data['provider'] = $provider;
        $name = isset($store_data['name']) && is_scalar($store_data['name'])
            ? trim((string) $store_data['name'])
            : '';
        $id = isset($store_data['id']) && is_scalar($store_data['id'])
            ? trim((string) $store_data['id'])
            : '';

        if ($provider === 'Chroma') {
            $collection_name = isset($store_data['collection_name']) && is_scalar($store_data['collection_name'])
                ? trim((string) $store_data['collection_name'])
                : '';
            if ($name === '' && $collection_name !== '') {
                $name = $collection_name;
            }
        }

        if ($provider === 'Google') {
            $resource_name = isset($store_data['resource_name']) && is_scalar($store_data['resource_name'])
                ? trim((string) $store_data['resource_name'])
                : '';
            if ($resource_name === '' && isset($store_data['name']) && is_scalar($store_data['name'])) {
                $raw_name = trim((string) $store_data['name']);
                if (strpos($raw_name, 'fileSearchStores/') === 0) {
                    $resource_name = $raw_name;
                }
            }
            $display_name = isset($store_data['display_name']) && is_scalar($store_data['display_name'])
                ? trim((string) $store_data['display_name'])
                : '';
            if ($display_name === '' && isset($store_data['displayName']) && is_scalar($store_data['displayName'])) {
                $display_name = trim((string) $store_data['displayName']);
            }
            if ($resource_name !== '') {
                $id = $resource_name;
                $name = $display_name !== '' ? $display_name : $resource_name;
                $store_data['resource_name'] = $resource_name;
                $store_data['display_name'] = $display_name;
            }
        }

        if ($id === '' && $name !== '') {
            $id = $name;
        }
        if ($name === '' && $id !== '') {
            $name = $id;
        }
        if ($id === '') {
            return [];
        }

        $store_data['id'] = $id;
        if ($name !== '') {
            $store_data['name'] = $name;
        }

        return $store_data;
    }

    /**
     * @return array<int, mixed>
     */
    private static function get_provider_option_cache(string $provider): array {
        $config = self::PROVIDER_CACHE_CONFIG[$provider] ?? null;
        if (empty($config['option'])) {
            return [];
        }

        $cached = get_option((string) $config['option'], []);
        return is_array($cached) ? $cached : [];
    }

    /**
     * @param array<int, array<string, mixed>> $stores
     */
    private static function persist_provider_option_cache(string $provider, array $stores): void {
        $config = self::PROVIDER_CACHE_CONFIG[$provider] ?? null;
        if (empty($config['option'])) {
            return;
        }

        $option_name = (string) $config['option'];
        update_option($option_name, $stores, 'no');
        wp_cache_delete($option_name, 'options');
    }

    private static function clear_provider_runtime_cache(string $provider): void {
        $config = self::PROVIDER_CACHE_CONFIG[$provider] ?? null;
        if (!empty($config['model_key'])) {
            delete_transient('aipkit_' . strtolower((string) $config['model_key']) . '_models_cache');
        }

        if (class_exists('\\WPAICG\\AIPKit_Providers') && method_exists('\\WPAICG\\AIPKit_Providers', 'clear_model_caches')) {
            \WPAICG\AIPKit_Providers::clear_model_caches();
        }
    }

    /**
     * Mirror a confirmed provider target list into the universal registry.
     *
     * @param array<int, array<string, mixed>> $stores
     */
    private static function publish_model_registry_catalog(string $provider, array $stores): void {
        $config = self::PROVIDER_CACHE_CONFIG[$provider] ?? null;
        $catalog_key = is_array($config) ? (string) ($config['model_key'] ?? '') : '';
        if ($catalog_key === '' || !class_exists('\\WPAICG\\Core\\Models\\AIPKit_Model_Registry')) {
            return;
        }

        $connection = [];
        if (class_exists('\\WPAICG\\AIPKit_Providers')) {
            $connection = \WPAICG\AIPKit_Providers::get_provider_data($provider);
        }
        \WPAICG\Core\Models\AIPKit_Model_Registry::publish_catalogs(
            $provider,
            [$catalog_key => $stores],
            [
                'sync_scope' => $catalog_key,
                'connection' => is_array($connection) ? $connection : [],
            ]
        );
    }

    /**
     * Remove targets tied to connection details that are no longer current.
     */
    private static function invalidate_model_registry_catalog(string $provider): void {
        $config = self::PROVIDER_CACHE_CONFIG[$provider] ?? null;
        $catalog_key = is_array($config) ? (string) ($config['model_key'] ?? '') : '';
        if ($catalog_key === '' || !class_exists('\\WPAICG\\Core\\Models\\AIPKit_Model_Registry')) {
            return;
        }
        \WPAICG\Core\Models\AIPKit_Model_Registry::invalidate_catalogs($provider, [$catalog_key]);
    }

    /**
     * @param array<string, mixed> $store
     */
    private static function store_matches_id(array $store, string $store_id): bool {
        $candidates = [
            $store['id'] ?? '',
            $store['name'] ?? '',
            $store['chroma_id'] ?? '',
            $store['collection_name'] ?? '',
            $store['resource_name'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && (string) $candidate === $store_id) {
                return true;
            }
        }

        return false;
    }
}
