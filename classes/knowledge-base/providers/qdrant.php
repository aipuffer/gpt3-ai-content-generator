<?php

namespace WPAICG\Vector\Providers;

use WPAICG\Vector\AIPKit_Vector_Base_Provider_Strategy;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Qdrant vector provider strategy and protocol implementation.
 */
class AIPKit_Vector_Qdrant_Strategy extends AIPKit_Vector_Base_Provider_Strategy {
    protected $api_key;
    protected $qdrant_url;

    public function __construct() {
    }

    /** Apply credentials locally; the next operation authenticates with the provider. */
    public function configure(array $config) {
        return \WPAICG\Vector\Providers\Qdrant\Methods\configure_logic($this, $config);
    }

    /**
     * @return bool|\WP_Error
     */
    public function connect(array $config) {
        return \WPAICG\Vector\Providers\Qdrant\Methods\connect_logic($this, $config);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function create_index_if_not_exists(string $index_name, array $index_config) {
        return \WPAICG\Vector\Providers\Qdrant\Methods\create_index_if_not_exists_logic($this, $index_name, $index_config);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function upsert_vectors(string $index_name, array $vectors_data) {
        return \WPAICG\Vector\Providers\Qdrant\Methods\upsert_vectors_logic($this, $index_name, $vectors_data);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function query_vectors(string $index_name, array $query_vector_param, int $top_k, array $filter = []) {
        return \WPAICG\Vector\Providers\Qdrant\Methods\query_vectors_logic($this, $index_name, $query_vector_param, $top_k, $filter);
    }

    /**
     * @return bool|\WP_Error
     */
    public function delete_vectors(string $index_name, array $vector_ids_or_filter) {
        return \WPAICG\Vector\Providers\Qdrant\Methods\delete_vectors_logic($this, $index_name, $vector_ids_or_filter);
    }

    /**
     * @return bool|\WP_Error
     */
    public function delete_index(string $index_name) {
        return \WPAICG\Vector\Providers\Qdrant\Methods\delete_index_logic($this, $index_name);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function list_indexes(?int $limit = null, ?string $order = null, ?string $after = null, ?string $before = null) {
        return \WPAICG\Vector\Providers\Qdrant\Methods\list_indexes_logic($this, $limit, $order, $after, $before);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function describe_index(string $index_name) {
        return \WPAICG\Vector\Providers\Qdrant\Methods\describe_index_logic($this, $index_name);
    }

    /** Reuse the same collection configuration for embedding preparation and search. */
    public function describe_index_for_embeddings(string $index_name) {
        $description = \WPAICG\Vector\Providers\Qdrant\Methods\collection_metadata_logic($this, $index_name);
        if (is_wp_error($description)) { return $description; }
        $vector = \WPAICG\Vector\Providers\Qdrant\Methods\collection_vector_config_logic($description);
        if (!is_wp_error($vector)) { $description['dimensions'] = $vector['size']; }
        return $description;
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function upload_file_for_vector_store(string $file_path, string $original_filename, string $purpose = 'user_data') {
        return \WPAICG\Vector\Providers\Qdrant\Methods\upload_file_for_vector_store_logic($this, $file_path, $original_filename, $purpose);
    }

    public function get_api_key(): ?string { return $this->api_key; }
    public function get_qdrant_url(): ?string { return $this->qdrant_url; }
    public function get_is_connected_status(): bool { return $this->is_connected; }

    public function set_api_key(?string $key): void { $this->api_key = $key; }
    public function set_qdrant_url(?string $url): void { $this->qdrant_url = $url ? rtrim($url, '/') : null; }
    public function set_is_connected_status(bool $status): void { $this->is_connected = $status; }

    /**
     * @return mixed[]|\WP_Error
     */
    public function decode_json_public_wrapper(string $json_string, string $context) {
        return parent::decode_json($json_string, $context);
    }
    public function parse_error_response_public_wrapper($response_body, int $status_code, string $context): string {
        return parent::parse_error_response($response_body, $status_code, $context);
    }
}

namespace WPAICG\Vector\Providers\Qdrant\Methods;

use WPAICG\Vector\Providers\AIPKit_Vector_Qdrant_Strategy;
use WP_Error;

/**
 * Logic for the _request method of AIPKit_Vector_Qdrant_Strategy.
 *
 * @param AIPKit_Vector_Qdrant_Strategy $strategyInstance The instance of the strategy class.
 * @param string $method HTTP method (GET, POST, PUT, DELETE, PATCH).
 * @param string $path API path (e.g., '/collections').
 * @param array $body Request body for POST/PUT/PATCH requests.
 * @param array $query_params Query parameters for the request.
 * @return array|WP_Error Decoded JSON response or WP_Error.
 */
function _request_logic(AIPKit_Vector_Qdrant_Strategy $strategyInstance, string $method, string $path, array $body = [], array $query_params = []) {
    if (!$strategyInstance->get_is_connected_status() && $path !== '/collections') {
        return new WP_Error('not_connected_qdrant', __('Not connected to Qdrant. Call connect() first.', 'gpt3-ai-content-generator'));
    }

    $qdrant_url = $strategyInstance->get_qdrant_url();
    $api_key = $strategyInstance->get_api_key();
    if (empty($qdrant_url)) { // Qdrant URL is essential
        return new WP_Error('qdrant_url_not_set', __('Qdrant URL not configured in strategy.', 'gpt3-ai-content-generator'));
    }

    $url = $qdrant_url . $path;
    if (!empty($query_params)) {
        $url = add_query_arg($query_params, $url);
    }

    $headers = ['Content-Type' => 'application/json'];
    if (!empty($api_key)) {
        $headers['api-key'] = $api_key;
    }

    $request_args = [
        'method'  => strtoupper($method),
        'headers' => $headers,
        'timeout' => 60,
    ];

    if (!empty($body) && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
        $request_args['body'] = wp_json_encode($body);
    }

    $response = wp_remote_request($url, $request_args);

    if (is_wp_error($response)) {
        return $response;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $response_body_raw = wp_remote_retrieve_body($response);

    $decoded_response = $strategyInstance->decode_json_public_wrapper($response_body_raw, 'Qdrant Vector Store');

    if ($status_code >= 400) {
        $error_msg = $strategyInstance->parse_error_response_public_wrapper($decoded_response ?: $response_body_raw, $status_code, 'Qdrant Vector Store');
        /* translators: %1$d: HTTP status code, %2$s: Error message from the API. */
        return new WP_Error('qdrant_api_error', sprintf(__('Qdrant API Error (%1$d): %2$s', 'gpt3-ai-content-generator'), $status_code, $error_msg), ['status' => $status_code]);
    }

    if (is_wp_error($decoded_response)) {
        return $decoded_response;
    }

    if (isset($decoded_response['result'])) {
        return is_array($decoded_response['result']) ? $decoded_response['result'] : ['result_data' => $decoded_response['result'], 'status' => $decoded_response['status'] ?? 'ok'];
    } elseif (is_array($decoded_response) && !empty($decoded_response)) {
        return $decoded_response;
    }
    return ['status' => 'ok', 'message' => 'Operation successful with no specific content returned.'];
}

/**
 * Initialize Qdrant credentials without a network probe.
 *
 * @param AIPKit_Vector_Qdrant_Strategy $strategyInstance The instance of the strategy class.
 * @param array $config Configuration array. Must include 'url' and 'api_key'.
 * @return bool|WP_Error True on success, WP_Error on failure.
 */
function configure_logic(AIPKit_Vector_Qdrant_Strategy $strategyInstance, array $config) {
    if (empty($config['url'])) {
        return new WP_Error('missing_qdrant_url', __('Qdrant URL is required for connection.', 'gpt3-ai-content-generator'));
    }
    if (empty($config['api_key'])) {
        return new WP_Error('missing_qdrant_api_key', __('Qdrant API Key is required for connection.', 'gpt3-ai-content-generator'));
    }
    $strategyInstance->set_qdrant_url($config['url']);
    $strategyInstance->set_api_key($config['api_key']);
    $strategyInstance->set_is_connected_status(true);
    return true;
}

/** Validate a connection explicitly, outside normal operations. */
function connect_logic(AIPKit_Vector_Qdrant_Strategy $strategyInstance, array $config) {
    $configured = configure_logic($strategyInstance, $config);
    if (is_wp_error($configured)) { return $configured; }
    $strategyInstance->set_is_connected_status(false);

    // The _request method called by list_indexes will determine actual connectivity
    // List collections is used as a lightweight connection test
    $test_connection = _request_logic($strategyInstance, 'GET', '/collections');

    if (is_wp_error($test_connection)) {
        /* translators: %1$s: The URL of the Qdrant instance, %2$s: The specific connection error message. */
        $error_message = sprintf(__('Failed to connect to Qdrant at %1$s. Error: %2$s', 'gpt3-ai-content-generator'), esc_html($strategyInstance->get_qdrant_url()), $test_connection->get_error_message());
        return new WP_Error('qdrant_connection_failed', $error_message, $test_connection->get_error_data());
    }
    if (isset($test_connection['collections']) && is_array($test_connection['collections'])) {
        $strategyInstance->set_is_connected_status(true);
        return true;
    }

    /* translators: %s: The URL of the Qdrant instance. */
    $error_message = sprintf(__('Unexpected response while connecting to Qdrant at %s. Please check URL and API key.', 'gpt3-ai-content-generator'), esc_html($strategyInstance->get_qdrant_url()));
    return new WP_Error('qdrant_connection_unexpected_response', $error_message);
}

/**
 * Logic for the create_index_if_not_exists method of AIPKit_Vector_Qdrant_Strategy.
 *
 * @param AIPKit_Vector_Qdrant_Strategy $strategyInstance The instance of the strategy class.
 * @param string $index_name The name of the index (collection) to create.
 * @param array $index_config Configuration for the index.
 * @return array|WP_Error The collection object or WP_Error on failure.
 */
function create_index_if_not_exists_logic(AIPKit_Vector_Qdrant_Strategy $strategyInstance, string $index_name, array $index_config) {
    $describe_response = describe_index_logic($strategyInstance, $index_name);
    if (!is_wp_error($describe_response) && isset($describe_response['status'])) {
        return $describe_response;
    } elseif (is_wp_error($describe_response) && $describe_response->get_error_data()['status'] !== 404) {
        return $describe_response;
    }

    $path = '/collections/' . urlencode($index_name);
    $metric = ucfirst(strtolower($index_config['metric'] ?? 'Cosine'));
    if (!in_array($metric, ['Cosine', 'Euclid', 'Dot'])) {
        $metric = 'Cosine';
    }
    $vector_params = ['size' => absint($index_config['dimension'] ?? 1536), 'distance' => $metric];
    $body = ['vectors' => $vector_params];
    if (isset($index_config['hnsw_config'])) $body['hnsw_config'] = $index_config['hnsw_config'];
    if (isset($index_config['wal_config'])) $body['wal_config'] = $index_config['wal_config'];
    if (isset($index_config['optimizers_config'])) $body['optimizers_config'] = $index_config['optimizers_config'];

    $create_response = _request_logic($strategyInstance, 'PUT', $path, $body);
    if (is_wp_error($create_response)) {
        return $create_response;
    }
    if (is_array($create_response) && ($create_response['status'] ?? null) === 'ok') {
        sleep(1);
        return describe_index_logic($strategyInstance, $index_name);
    }
    return new WP_Error('qdrant_create_unknown_error', __('Unknown error creating Qdrant collection.', 'gpt3-ai-content-generator'));
}

/**
 * Logic for the delete_index method of AIPKit_Vector_Qdrant_Strategy.
 *
 * @param AIPKit_Vector_Qdrant_Strategy $strategyInstance The instance of the strategy class.
 * @param string $index_name The name of the index (collection) to delete.
 * @return bool|WP_Error True on success, WP_Error on failure.
 */
function delete_index_logic(AIPKit_Vector_Qdrant_Strategy $strategyInstance, string $index_name) {
    $path = '/collections/' . urlencode($index_name);
    $response = _request_logic($strategyInstance, 'DELETE', $path);
    delete_transient(collection_metadata_cache_key_logic($strategyInstance, $index_name));

    if (is_wp_error($response)) {
        return $response;
    }
    if (isset($response['status']) && $response['status'] === 'ok') {
        return true;
    }
    if (isset($response['result_data']) && $response['result_data'] === true) {
        return true;
    }
    if (isset($response['deleted']) && $response['deleted'] === true) { // Compatibility with Pinecone response if _request logic normalizes
        return true;
    }
    return false;
}

/**
 * Logic for the delete_vectors method of AIPKit_Vector_Qdrant_Strategy.
 *
 * @param AIPKit_Vector_Qdrant_Strategy $strategyInstance The instance of the strategy class.
 * @param string $index_name The name of the index (collection).
 * @param array $vector_ids_or_filter Array of vector IDs or a filter object.
 * @return bool|WP_Error True on success, WP_Error on failure.
 */
function delete_vectors_logic(AIPKit_Vector_Qdrant_Strategy $strategyInstance, string $index_name, array $vector_ids_or_filter) {
    $path = '/collections/' . urlencode($index_name) . '/points/delete';
    $body = [];

    if (isset($vector_ids_or_filter['points']) && is_array($vector_ids_or_filter['points'])) {
        $body['points'] = $vector_ids_or_filter['points'];
    } elseif (isset($vector_ids_or_filter['filter']) && is_array($vector_ids_or_filter['filter'])) {
        $body['filter'] = normalize_filter_payload_keys_logic($vector_ids_or_filter['filter']);
        $ensure_indexes = ensure_payload_indexes_for_filter_logic($strategyInstance, $index_name, $body['filter']);
        if (is_wp_error($ensure_indexes)) {
            return $ensure_indexes;
        }
    } else {
        $body['points'] = $vector_ids_or_filter;
    }

    $response = _request_logic($strategyInstance, 'POST', $path, $body);
    if (is_wp_error($response)) return $response;
    if (($response['status'] ?? '') === 'ok') {
        return true;
    }
    $operation_status = $response['result']['status'] ?? ($response['status'] ?? '');
    return in_array($operation_status, ['acknowledged', 'completed'], true);
}

/** Resolve the sole dense vector without guessing between multiple vector spaces. */
function collection_vector_config_logic(array $description, ?string $requested_name = null) {
    $vectors = $description['config']['params']['vectors'] ?? null;
    if (!is_array($vectors) || $vectors === []) {
        return new WP_Error('qdrant_vector_config_missing', __('Could not read the collection vector configuration. Refresh the collection and try again.', 'gpt3-ai-content-generator'));
    }
    if (isset($vectors['size']) && is_numeric($vectors['size']) && (int) $vectors['size'] > 0) {
        if ($requested_name !== null && $requested_name !== '') {
            return new WP_Error('qdrant_vector_name_invalid', __('The selected vector name does not exist in this collection.', 'gpt3-ai-content-generator'));
        }
        return ['size' => (int) $vectors['size'], 'name' => null];
    }
    if ($requested_name === null) {
        if (count($vectors) !== 1) {
            return new WP_Error('qdrant_vector_config_ambiguous', __('This collection has multiple named vectors. Use a collection with one dense vector for this knowledge base.', 'gpt3-ai-content-generator'));
        }
        $requested_name = (string) array_key_first($vectors);
    }
    $config = $vectors[$requested_name] ?? null;
    if (!is_array($config) || !isset($config['size']) || !is_numeric($config['size']) || (int) $config['size'] <= 0) {
        return new WP_Error('qdrant_vector_config_missing', __('Could not read the collection vector configuration. Refresh the collection and try again.', 'gpt3-ai-content-generator'));
    }
    return ['size' => (int) $config['size'], 'name' => $requested_name];
}

/** Isolate metadata by server, credentials and collection without storing the API key. */
function collection_metadata_cache_key_logic(AIPKit_Vector_Qdrant_Strategy $strategyInstance, string $index_name): string {
    return 'aipkit_qdrant_meta_' . hash('sha256', wp_json_encode([
        $strategyInstance->get_qdrant_url(), $strategyInstance->get_api_key(), $index_name,
    ]));
}

/** Cache only valid vector configuration and payload indexes, never point counts or payloads. */
function cache_collection_metadata_logic(AIPKit_Vector_Qdrant_Strategy $strategyInstance, string $index_name, array $description): void {
    $key = collection_metadata_cache_key_logic($strategyInstance, $index_name);
    $vectors = $description['config']['params']['vectors'] ?? [];
    if (!is_array($vectors) || !$vectors) { delete_transient($key); return; }
    $names = isset($vectors['size']) ? [null] : array_keys($vectors);
    foreach ($names as $name) {
        if (is_wp_error(collection_vector_config_logic($description, $name === null ? null : (string) $name))) {
            delete_transient($key);
            return;
        }
    }
    set_transient($key, [
        'config' => ['params' => ['vectors' => $vectors]],
        'payload_schema' => is_array($description['payload_schema'] ?? null) ? $description['payload_schema'] : [],
    ], 5 * MINUTE_IN_SECONDS);
}

/** Search and filter preparation do not need the expensive exact point count. */
function collection_metadata_logic(AIPKit_Vector_Qdrant_Strategy $strategyInstance, string $index_name, bool $refresh = false, bool &$cache_hit = false) {
    $key = collection_metadata_cache_key_logic($strategyInstance, $index_name);
    $cached = $refresh ? false : get_transient($key);
    $cache_hit = is_array($cached) && isset($cached['config']['params']['vectors']);
    if ($cache_hit && $strategyInstance->get_is_connected_status()) { return $cached; }
    $cache_hit = false;
    if ($refresh) { delete_transient($key); }
    $description = _request_logic($strategyInstance, 'GET', '/collections/' . urlencode($index_name));
    if (!is_wp_error($description)) { cache_collection_metadata_logic($strategyInstance, $index_name, $description); }
    return $description;
}

/**
 * Logic for the describe_index method of AIPKit_Vector_Qdrant_Strategy.
 * Describes a Qdrant collection.
 *
 * @param AIPKit_Vector_Qdrant_Strategy $strategyInstance The instance of the strategy class.
 * @param string $index_name The name of the index (collection).
 * @return array|WP_Error Collection details or WP_Error.
 */
function describe_index_logic(AIPKit_Vector_Qdrant_Strategy $strategyInstance, string $index_name) {
    // First, get the main collection info like config
    $path = '/collections/' . urlencode($index_name);
    delete_transient(collection_metadata_cache_key_logic($strategyInstance, $index_name));
    $description_response = _request_logic($strategyInstance, 'GET', $path);

    if (is_wp_error($description_response)) {
        return $description_response;
    }

    $result = $description_response['result'] ?? $description_response;

    if (!is_array($result)) {
        return new WP_Error('qdrant_describe_malformed', __('Malformed response when describing Qdrant collection.', 'gpt3-ai-content-generator'));
    }
    cache_collection_metadata_logic($strategyInstance, $index_name, $result);

    $vector_config = collection_vector_config_logic($result);
    if (!is_wp_error($vector_config)) {
        $result['dimensions'] = $vector_config['size'];
    }

    // Now, get the *exact* vector count using the dedicated count endpoint
    $count_path = '/collections/' . urlencode($index_name) . '/points/count';
    // Using an empty body is fine for a basic exact count
    $count_body = ['exact' => true];
    $count_response = _request_logic($strategyInstance, 'POST', $count_path, $count_body);

    if (!is_wp_error($count_response) && isset($count_response['count'])) {
        // The count response is like: {"result": {"count": 123}, "status": "ok"}
        // _request_logic returns the "result" part.
        $result['vectors_count'] = $count_response['count'];
    }

    // Add name and ID for consistency with other providers and registry format
    $result['name'] = $index_name;
    $result['id'] = $index_name;

    return $result;
}

/**
 * Normalizes legacy filter keys before sending a request to Qdrant.
 *
 * @param array<string,mixed> $filter
 * @return array<string,mixed>
 */
function normalize_filter_payload_keys_logic(array $filter): array {
    if (isset($filter['key']) && is_string($filter['key'])) {
        if (strncmp($filter['key'], 'payload.', strlen('payload.')) === 0) {
            $filter['key'] = (string) substr($filter['key'], 8);
        }
        if (is_keyword_payload_field_logic($filter['key']) && isset($filter['match']) && is_array($filter['match'])) {
            if (array_key_exists('value', $filter['match']) && is_scalar($filter['match']['value'])) {
                $filter['match']['value'] = (string) $filter['match']['value'];
            }
            if (isset($filter['match']['any']) && is_array($filter['match']['any'])) {
                $filter['match']['any'] = array_map(static function ($value): string {
                    return is_scalar($value) ? (string) $value : '';
                }, $filter['match']['any']);
                $filter['match']['any'] = array_values(array_filter($filter['match']['any'], static function (string $value): bool {
                    return $value !== '';
                }));
            }
        }
    }

    foreach ($filter as $key => $value) {
        if (is_array($value)) {
            $filter[$key] = normalize_filter_payload_keys_logic($value);
        }
    }

    return $filter;
}

/**
 * Ensures Qdrant payload indexes exist for every field used in a filter.
 *
 * Qdrant strict mode can reject filtered delete/search calls unless the filtered
 * payload keys are indexed. This keeps existing callers provider-agnostic while
 * making filtered re-index/delete/search work on strict collections.
 *
 * @param array<string,mixed> $filter
 * @return true|WP_Error
 */
function ensure_payload_indexes_for_filter_logic(AIPKit_Vector_Qdrant_Strategy $strategyInstance, string $collection_name, array $filter) {
    $conditions = extract_payload_filter_conditions_logic($filter);
    if (empty($conditions)) {
        return true;
    }

    $description = collection_metadata_logic($strategyInstance, $collection_name);
    if (is_wp_error($description)) { return $description; }
    $payload_schema = is_array($description['payload_schema'] ?? null) ? $description['payload_schema'] : [];

    foreach ($conditions as $condition) {
        $field_name = isset($condition['key']) && is_string($condition['key']) ? trim($condition['key']) : '';
        if ($field_name === '' || isset($payload_schema[$field_name])) {
            continue;
        }

        $schema = infer_payload_index_schema_logic($field_name, $condition);
        $path = '/collections/' . urlencode($collection_name) . '/index';
        $response = _request_logic(
            $strategyInstance,
            'PUT',
            $path,
            [
                'field_name' => $field_name,
                'field_schema' => $schema,
            ],
            ['wait' => 'true']
        );

        if (is_wp_error($response)) {
            $message = strtolower($response->get_error_message());
            if (strpos($message, 'already exists') === false && strpos($message, 'already has') === false) {
                return $response;
            }
        }

        $payload_schema[$field_name] = ['data_type' => $schema];
        $description['payload_schema'] = $payload_schema;
        cache_collection_metadata_logic($strategyInstance, $collection_name, $description);
    }

    return true;
}

/**
 * @param array<string,mixed> $filter
 * @return array<int,array<string,mixed>>
 */
function extract_payload_filter_conditions_logic(array $filter): array {
    $conditions = [];
    if (isset($filter['key']) && is_string($filter['key'])) {
        $conditions[] = $filter;
    }

    foreach (['must', 'should', 'must_not'] as $group_key) {
        if (empty($filter[$group_key]) || !is_array($filter[$group_key])) {
            continue;
        }
        foreach ($filter[$group_key] as $child_filter) {
            if (is_array($child_filter)) {
                $conditions = array_merge($conditions, extract_payload_filter_conditions_logic($child_filter));
            }
        }
    }

    if (isset($filter['filter']) && is_array($filter['filter'])) {
        $conditions = array_merge($conditions, extract_payload_filter_conditions_logic($filter['filter']));
    }

    return $conditions;
}

/**
 * @param array<string,mixed> $condition
 */
function infer_payload_index_schema_logic(string $field_name, array $condition): string {
    if (is_keyword_payload_field_logic($field_name)) {
        return 'keyword';
    }

    if (isset($condition['range']) && is_array($condition['range'])) {
        return 'float';
    }

    $match_value = null;
    if (isset($condition['match']) && is_array($condition['match']) && array_key_exists('value', $condition['match'])) {
        $match_value = $condition['match']['value'];
    }

    if (is_bool($match_value)) {
        return 'bool';
    }
    if (is_int($match_value)) {
        return 'integer';
    }
    if (is_float($match_value)) {
        return 'float';
    }

    return 'keyword';
}

function is_keyword_payload_field_logic(string $field_name): bool {
    return in_array($field_name, ['post_id', 'source', 'parent_vector_id', 'vector_id', 'file_upload_context_id', 'filename', 'original_filename', 'session_id', 'user_id'], true);
}

/**
 * Logic for the list_indexes method of AIPKit_Vector_Qdrant_Strategy.
 * Lists Qdrant collections.
 *
 * @param AIPKit_Vector_Qdrant_Strategy $strategyInstance The instance of the strategy class.
 * @param int|null $limit Max items (unused by Qdrant list collections).
 * @param string|null $order Sort order (unused by Qdrant list collections).
 * @param string|null $after Cursor for next page (unused).
 * @param string|null $before Cursor for previous page (unused).
 * @return array|WP_Error List of collections or WP_Error.
 */
function list_indexes_logic(AIPKit_Vector_Qdrant_Strategy $strategyInstance, ?int $limit = null, ?string $order = null, ?string $after = null, ?string $before = null) {
    $response = _request_logic($strategyInstance, 'GET', '/collections');
    if (is_wp_error($response)) return $response;

    $collections_data = $response['collections'] ?? [];
    $formatted_collections = [];
    if (is_array($collections_data)) {
        foreach ($collections_data as $collection) {
            if (isset($collection['name'])) {
                $formatted_collections[] = [
                    'id' => $collection['name'],
                    'name' => $collection['name'],
                ];
            }
        }
    }
    return $formatted_collections;
}

/**
 * Logic for the query_vectors method of AIPKit_Vector_Qdrant_Strategy.
 *
 * @param AIPKit_Vector_Qdrant_Strategy $strategyInstance The instance of the strategy class.
 * @param string $index_name The name of the index (collection).
 * @param array $query_vector_param The query vector parameters.
 * @param int $top_k Number of nearest neighbors to return.
 * @param array $filter Optional metadata filter.
 * @return array|WP_Error Array of matching vectors or WP_Error.
 */
function query_vectors_logic(AIPKit_Vector_Qdrant_Strategy $strategyInstance, string $index_name, array $query_vector_param, int $top_k, array $filter = []) {
    $path = '/collections/' . urlencode($index_name) . '/points/search';
    $vector_values = $query_vector_param['vector'] ?? ($query_vector_param['query'] ?? ($query_vector_param ?: null));

    if (!is_array($vector_values) || empty($vector_values)) {
        return new WP_Error('invalid_qdrant_query_vector', __('Invalid query vector provided for Qdrant search.', 'gpt3-ai-content-generator'));
    }

    $body = [
        'vector' => $vector_values,
        'limit' => $top_k,
        'with_payload' => true,
        'with_vector' => $query_vector_param['with_vector'] ?? false,
    ];
    $cache_hit = false;
    $description = collection_metadata_logic($strategyInstance, $index_name, false, $cache_hit);
    if (is_wp_error($description)) { return $description; }
    if (!empty($filter)) {
        $body['filter'] = normalize_filter_payload_keys_logic($filter);
    }
    if (isset($query_vector_param['score_threshold'])) $body['score_threshold'] = floatval($query_vector_param['score_threshold']);
    if (isset($query_vector_param['offset'])) $body['offset'] = absint($query_vector_param['offset']);
    if (isset($query_vector_param['prefetch']) && is_array($query_vector_param['prefetch'])) {
        $body['prefetch'] = $query_vector_param['prefetch'];
    }

    // A collection can be recreated or an alias changed externally. Refresh stale metadata
    // once on a configuration error; never retry authentication, throttling or server errors.
    for ($attempt = 0; $attempt < 2; ++$attempt) {
        $vector_config = collection_vector_config_logic($description, isset($query_vector_param['using']) && is_string($query_vector_param['using']) ? $query_vector_param['using'] : null);
        if (is_wp_error($vector_config)) {
            $response = $vector_config;
        } else {
            $body['vector'] = $vector_config['name'] === null ? $vector_values : ['name' => $vector_config['name'], 'vector' => $vector_values];
            if (!empty($body['filter'])) {
                $ensure_indexes = ensure_payload_indexes_for_filter_logic($strategyInstance, $index_name, $body['filter']);
                if (is_wp_error($ensure_indexes)) { return $ensure_indexes; }
            }
            $response = _request_logic($strategyInstance, 'POST', $path, $body);
        }
        $status = is_wp_error($response) ? ($response->get_error_data()['status'] ?? 0) : 0;
        if (!is_wp_error($response) || !$cache_hit || $attempt > 0
            || (!is_wp_error($vector_config) && !in_array($status, [400, 404], true))) { break; }
        $fresh = collection_metadata_logic($strategyInstance, $index_name, true);
        if (is_wp_error($fresh)) { return $fresh; }
        if (($fresh['config']['params']['vectors'] ?? []) === ($description['config']['params']['vectors'] ?? [])
            && ($fresh['payload_schema'] ?? []) === ($description['payload_schema'] ?? [])) { break; }
        $description = $fresh;
    }
    if (is_wp_error($response)) return $response;

    $points = (is_array($response) && isset($response['points']) && is_array($response['points'])) ? $response['points'] : ((is_array($response) && !isset($response['status'])) ? $response : []);
    $results = [];
    if (is_array($points)) {
        foreach($points as $point) {
            $results[] = [
                'id' => $point['id'] ?? null,
                'score' => $point['score'] ?? null,
                'metadata' => $point['payload'] ?? [],
                'vector' => $point['vector'] ?? null,
                // Annotate collection for downstream aggregation/UX
                'collection' => $index_name,
            ];
        }
    }
    return $results;
}

/**
 * Logic for the upload_file_for_vector_store method of AIPKit_Vector_Qdrant_Strategy.
 * Qdrant does not support direct file uploads for vector store creation in the same way OpenAI does.
 * Content must be processed, embedded, and then vectors upserted.
 *
 * @param AIPKit_Vector_Qdrant_Strategy $strategyInstance The instance of the strategy class.
 * @param string $file_path Absolute path to the file.
 * @param string $original_filename The original filename.
 * @param string $purpose Purpose of the file (unused for Qdrant).
 * @return array|WP_Error Always returns WP_Error as not applicable.
 */
function upload_file_for_vector_store_logic(AIPKit_Vector_Qdrant_Strategy $strategyInstance, string $file_path, string $original_filename, string $purpose = 'user_data') {
    return new WP_Error('not_applicable_qdrant_file_upload', __('Direct file upload for vector store creation is not applicable for Qdrant. Prepare content and upsert vectors.', 'gpt3-ai-content-generator'));
}

/**
 * Logic for the upsert_vectors method of AIPKit_Vector_Qdrant_Strategy.
 *
 * @param AIPKit_Vector_Qdrant_Strategy $strategyInstance The instance of the strategy class.
 * @param string $index_name The name of the index (collection).
 * @param array $vectors_data Data containing vectors to upsert.
 * @return array|WP_Error Result of the upsert operation or WP_Error.
 */
function upsert_vectors_logic(AIPKit_Vector_Qdrant_Strategy $strategyInstance, string $index_name, array $vectors_data) {
    $path = '/collections/' . urlencode($index_name) . '/points';
    $body = [];
    $query_params = [];

    if (isset($vectors_data['points']) && is_array($vectors_data['points'])) {
        $body['points'] = $vectors_data['points'];
    } else {
        $body['points'] = $vectors_data;
    }
    foreach ($body['points'] as &$point) {
        if (isset($point['values']) && !isset($point['vector'])) {
            $point['vector'] = $point['values'];
            unset($point['values']);
        }
        if (isset($point['metadata']) && !isset($point['payload'])) {
            $point['payload'] = $point['metadata'];
            unset($point['metadata']);
        }
        $point_id = $point['id'] ?? null;
        $needs_uuid = false;
        if ($point_id === null || $point_id === '') {
            $needs_uuid = true;
        } elseif (is_int($point_id)) {
            $needs_uuid = false;
        } elseif (is_string($point_id) && ctype_digit($point_id)) {
            $needs_uuid = false;
        } elseif (is_string($point_id) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $point_id)) {
            $needs_uuid = false;
        } else {
            $needs_uuid = true;
        }
        if ($needs_uuid) {
            $new_id = wp_generate_uuid4();
            $point['id'] = $new_id;
            if (isset($point['payload']['vector_id']) && $point['payload']['vector_id'] === $point_id) {
                $point['payload']['vector_id'] = $new_id;
            }
        }
    }
    unset($point);

    $desc = describe_index_logic($strategyInstance, $index_name);
    if (is_wp_error($desc)) { return $desc; }
    $vector_config = collection_vector_config_logic($desc);
    if (is_wp_error($vector_config)) { return $vector_config; }
    foreach ($body['points'] as &$point) {
        $values = $point['vector'] ?? null;
        if ($vector_config['name'] !== null && is_array($values) && array_key_exists($vector_config['name'], $values)) {
            $values = $values[$vector_config['name']];
        }
        if (!is_array($values) || count($values) !== $vector_config['size']) {
            /* translators: 1: expected dimension; 2: actual dimension. */
            return new WP_Error('qdrant_vector_dimension_mismatch', sprintf(__('Vector dimension mismatch. Expected %1$d, got %2$d.', 'gpt3-ai-content-generator'), $vector_config['size'], is_array($values) ? count($values) : 0));
        }
        $point['vector'] = $vector_config['name'] === null ? $values : [$vector_config['name'] => $values];
    }
    unset($point);

    // Add wait=true by default (filterable)
    $wait_default = apply_filters('aipkit_qdrant_upsert_wait', true, $index_name);
    if (isset($vectors_data['wait'])) {
        $query_params['wait'] = ($vectors_data['wait'] === true || $vectors_data['wait'] === 'true') ? 'true' : 'false';
    } elseif ($wait_default === true) {
        $query_params['wait'] = 'true';
    }

    // Batch upserts
    $batch_size = apply_filters('aipkit_qdrant_upsert_batch_size', 100, $index_name);
    $all_results = [];
    $total = count($body['points']);
    for ($i = 0; $i < $total; $i += $batch_size) {
        $chunk_points = array_slice($body['points'], $i, $batch_size);
        $chunk_body = ['points' => $chunk_points];
        $resp = _request_logic($strategyInstance, 'PUT', $path, $chunk_body, $query_params);
        if (is_wp_error($resp)) return $resp;
        $all_results[] = $resp;
    }

    $last = end($all_results);
    if (is_array($last)) {
        $last['aipkit_batches'] = count($all_results);
    }
    return $last;
}
