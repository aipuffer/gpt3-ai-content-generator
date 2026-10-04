<?php

namespace WPAICG\Vector\Providers;

use WPAICG\Vector\AIPKit_Vector_Base_Provider_Strategy;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Pinecone vector provider strategy and protocol implementation.
 */
class AIPKit_Vector_Pinecone_Strategy extends AIPKit_Vector_Base_Provider_Strategy {
    protected $api_key;
    protected $base_api_url = 'https://api.pinecone.io'; // Controller plane API

    public function __construct() {
    }

    /**
     * @return bool|\WP_Error
     */
    public function connect(array $config) {
        return \WPAICG\Vector\Providers\Pinecone\Methods\connect_logic($this, $config);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function create_index_if_not_exists(string $index_name, array $index_config) {
        return \WPAICG\Vector\Providers\Pinecone\Methods\create_index_if_not_exists_logic($this, $index_name, $index_config);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function upsert_vectors(string $index_name, array $vectors_data) {
        return \WPAICG\Vector\Providers\Pinecone\Methods\upsert_vectors_logic($this, $index_name, $vectors_data);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function query_vectors(string $index_name, array $query_vector_param, int $top_k, array $filter = []) {
        return \WPAICG\Vector\Providers\Pinecone\Methods\query_vectors_logic($this, $index_name, $query_vector_param, $top_k, $filter);
    }

    /**
     * @return bool|\WP_Error
     */
    public function delete_vectors(string $index_name, array $vector_ids) {
        return \WPAICG\Vector\Providers\Pinecone\Methods\delete_vectors_logic($this, $index_name, $vector_ids);
    }

    /**
     * @return bool|\WP_Error
     */
    public function delete_index(string $index_name) {
        return \WPAICG\Vector\Providers\Pinecone\Methods\delete_index_logic($this, $index_name);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function list_indexes(?int $limit = null, ?string $order = null, ?string $after = null, ?string $before = null) {
        return \WPAICG\Vector\Providers\Pinecone\Methods\list_indexes_logic($this, $limit, $order, $after, $before);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function describe_index(string $index_name) {
        return \WPAICG\Vector\Providers\Pinecone\Methods\describe_index_logic($this, $index_name);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function upload_file_for_vector_store(string $file_path, string $original_filename, string $purpose = 'user_data') {
        return \WPAICG\Vector\Providers\Pinecone\Methods\upload_file_for_vector_store_logic($this, $file_path, $original_filename, $purpose);
    }

    public function get_api_key(): ?string { return $this->api_key; }
    public function get_base_api_url(): string { return $this->base_api_url; }
    public function get_is_connected_status(): bool { return $this->is_connected; }
    public function set_is_connected_status(bool $status): void { $this->is_connected = $status; }
    public function set_api_key(string $key): void { $this->api_key = $key; }

}

namespace WPAICG\Vector\Providers\Pinecone\Methods;

use WPAICG\Vector\Providers\AIPKit_Vector_Pinecone_Strategy;
use WP_Error;

/**
 * Logic for the _request method of AIPKit_Vector_Pinecone_Strategy.
 *
 * @param AIPKit_Vector_Pinecone_Strategy $strategyInstance The instance of the strategy class.
 * @param string $method HTTP method (GET, POST, DELETE, PATCH).
 * @param string $path API path (e.g., '/indexes').
 * @param array $body Request body for POST/PATCH requests.
 * @param string|null $index_host_url Optional. If provided, this URL is used as the base instead of controller API.
 * @return array|WP_Error Decoded JSON response or WP_Error.
 */
function _request_logic(AIPKit_Vector_Pinecone_Strategy $strategyInstance, string $method, string $path, array $body = [], ?string $index_host_url = null) {
    if (!$strategyInstance->get_is_connected_status() && empty($index_host_url) && $path !== '/indexes?limit=1' /* Allow initial connection test */) {
        return new WP_Error('not_connected_pinecone', __('Not connected to Pinecone. Call connect() first or provide index host URL.', 'gpt3-ai-content-generator'));
    }

    $url = ($index_host_url ? rtrim($index_host_url, '/') : $strategyInstance->get_base_api_url()) . $path;
    $api_key = $strategyInstance->get_api_key();

    $headers = [
        'Api-Key'       => $api_key,
        'Accept'        => 'application/json',
        'Content-Type'  => 'application/json',
    ];

    $request_args = [
        'method'  => strtoupper($method),
        'headers' => $headers,
        'timeout' => 60, // Standard timeout
    ];

    if (!empty($body) && in_array(strtoupper($method), ['POST', 'PATCH'], true)) {
        $request_args['body'] = wp_json_encode($body);
    }

    $response = wp_remote_request($url, $request_args);

    if (is_wp_error($response)) {
        return $response;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);

    // For DELETE, Pinecone returns 202 (Accepted) or 204 (No Content) on success.
    if (strtoupper($method) === 'DELETE' && in_array($status_code, [200, 202, 204], true)) {
        return ['deleted' => true];
    }
    if (strtoupper($method) === 'PATCH' && $status_code === 202) {
        return ['status' => 'accepted'];
    }

    $decoded_response = $strategyInstance->decode_json($response_body, 'Pinecone Vector Store');

    if ($status_code >= 400) {
        $error_msg = $strategyInstance->parse_error_response($decoded_response ?: $response_body, $status_code, 'Pinecone Vector Store');
        /* translators: %1$d: HTTP status code, %2$s: Error message from the API. */
        return new WP_Error('pinecone_api_error', sprintf(__('Pinecone API Error (%1$d): %2$s', 'gpt3-ai-content-generator'), $status_code, $error_msg));
    }

    if (is_wp_error($decoded_response)) {
        return $decoded_response;
    }
    return $decoded_response;
}

/**
 * Logic for the connect method of AIPKit_Vector_Pinecone_Strategy.
 *
 * @param AIPKit_Vector_Pinecone_Strategy $strategyInstance The instance of the strategy class.
 * @param array $config Configuration array. Must include 'api_key'.
 * @return bool|WP_Error True on success, WP_Error on failure.
 */
function connect_logic(AIPKit_Vector_Pinecone_Strategy $strategyInstance, array $config) {
    if (empty($config['api_key'])) {
        return new WP_Error('missing_api_key_pinecone', __('Pinecone API Key is required.', 'gpt3-ai-content-generator'));
    }
    $strategyInstance->set_api_key($config['api_key']);
    $strategyInstance->set_is_connected_status(true); // Assume connected if key is present, then test

    // Test connection by trying to list a single index
    $test_list_response = $strategyInstance->list_indexes(1);
    if (is_wp_error($test_list_response)) {
        $strategyInstance->set_is_connected_status(false);
        return $test_list_response;
    }
    return true;
}

/**
 * Logic for the create_index_if_not_exists method of AIPKit_Vector_Pinecone_Strategy.
 *
 * @param AIPKit_Vector_Pinecone_Strategy $strategyInstance The instance of the strategy class.
 * @param string $index_name The name of the index to create.
 * @param array $index_config Configuration for the index.
 * @return array|WP_Error The index object or WP_Error on failure.
 */
function create_index_if_not_exists_logic(AIPKit_Vector_Pinecone_Strategy $strategyInstance, string $index_name, array $index_config) {
    $list_response = list_indexes_logic($strategyInstance);
    if (!is_wp_error($list_response) && is_array($list_response)) {
        foreach ($list_response as $existing_index) {
            if (isset($existing_index['name']) && $existing_index['name'] === $index_name) {
                return describe_index_logic($strategyInstance, $index_name);
            }
        }
    }

    $path = '/indexes';
    $body = [
        'name' => $index_name,
        'dimension' => absint($index_config['dimension'] ?? 1536),
        'metric' => strtolower($index_config['metric'] ?? 'cosine'),
        'spec' => $index_config['spec'] ?? [
            'serverless' => [
                'cloud' => $index_config['cloud'] ?? 'aws',
                'region' => $index_config['region'] ?? 'us-east-1'
            ]
        ]
    ];
    if (isset($index_config['deletion_protection'])) $body['deletion_protection'] = $index_config['deletion_protection'];

    $response = _request_logic($strategyInstance, 'POST', $path, $body);

    if (is_wp_error($response)) {
        return $response;
    }

    if (is_array($response) && isset($response['name'])) {
        sleep(15); // Wait for index to become available
        return describe_index_logic($strategyInstance, $index_name);
    }
    return new WP_Error('pinecone_create_unknown_error', __('Unknown error creating Pinecone index.', 'gpt3-ai-content-generator'));
}

/**
 * Logic for the delete_index method of AIPKit_Vector_Pinecone_Strategy.
 *
 * @param AIPKit_Vector_Pinecone_Strategy $strategyInstance The instance of the strategy class.
 * @param string $index_name The name of the index to delete.
 * @return bool|WP_Error True on success, WP_Error on failure.
 */
function delete_index_logic(AIPKit_Vector_Pinecone_Strategy $strategyInstance, string $index_name) {
    $path = '/indexes/' . urlencode($index_name);
    $response = _request_logic($strategyInstance, 'DELETE', $path);

    if (is_wp_error($response)) {
        return $response;
    }
    return isset($response['deleted']) && $response['deleted'] === true;
}

/**
 * Logic for the delete_vectors method of AIPKit_Vector_Pinecone_Strategy.
 *
 * @param AIPKit_Vector_Pinecone_Strategy $strategyInstance The instance of the strategy class.
 * @param string $index_name The name of the index.
 * @param array $vector_ids An array of vector IDs or a selector with ids/filter/deleteAll.
 * @return bool|WP_Error True on success, WP_Error on failure.
 */
function delete_vectors_logic(AIPKit_Vector_Pinecone_Strategy $strategyInstance, string $index_name, array $vector_ids) {
    $index_description = get_index_overview_logic($strategyInstance, $index_name);
    if (is_wp_error($index_description)) return $index_description;
    $host = $index_description['host'] ?? null;
    if (empty($host)) return new WP_Error('missing_host_pinecone_delete_vectors', __('Index host not found for delete vectors.', 'gpt3-ai-content-generator'));
    $path = '/vectors/delete';
    if (isset($vector_ids['ids']) && is_array($vector_ids['ids'])) {
        $body = ['ids' => array_values($vector_ids['ids'])];
    } elseif (isset($vector_ids['filter']) && is_array($vector_ids['filter'])) {
        $body = ['filter' => $vector_ids['filter']];
    } elseif (!empty($vector_ids['deleteAll'])) {
        $body = ['deleteAll' => true];
    } else {
        $body = ['ids' => array_values($vector_ids)];
    }
    if (isset($vector_ids['namespace']) && is_string($vector_ids['namespace']) && $vector_ids['namespace'] !== '') {
        $body['namespace'] = $vector_ids['namespace'];
    }

    $response = _request_logic($strategyInstance, 'POST', $path, $body, 'https://' . $host);
    if (is_wp_error($response)) return $response;
    return empty($response); // Successful delete returns empty JSON object {}
}

/**
 * Fetches basic index metadata from the controller plane and caches it.
 *
 * @param AIPKit_Vector_Pinecone_Strategy $strategyInstance The instance of the strategy class.
 * @param string $index_name The name of the index to describe.
 * @return array|WP_Error Index overview or WP_Error.
 */
function get_index_overview_logic(AIPKit_Vector_Pinecone_Strategy $strategyInstance, string $index_name) {
    $cache_key = 'aipkit_pinecone_index_overview_' . md5($index_name);
    $cache_group = 'aipkit_pinecone';
    static $request_cache = [];

    if (isset($request_cache[$cache_key])) {
        return $request_cache[$cache_key];
    }

    $cached = wp_cache_get($cache_key, $cache_group);
    if (false !== $cached) {
        $request_cache[$cache_key] = $cached;
        return $cached;
    }

    $transient = get_transient($cache_key);
    if (false !== $transient) {
        wp_cache_set($cache_key, $transient, $cache_group, HOUR_IN_SECONDS);
        $request_cache[$cache_key] = $transient;
        return $transient;
    }

    $path = '/indexes/' . urlencode($index_name);
    $description = _request_logic($strategyInstance, 'GET', $path);
    if (!is_wp_error($description)) {
        $ttl = (int) apply_filters('aipkit_pinecone_index_overview_cache_ttl', HOUR_IN_SECONDS, $index_name, $description);
        if ($ttl > 0) {
            set_transient($cache_key, $description, $ttl);
            wp_cache_set($cache_key, $description, $cache_group, $ttl);
        }
    }

    $request_cache[$cache_key] = $description;
    return $description;
}

/**
 * Logic for the describe_index method of AIPKit_Vector_Pinecone_Strategy.
 *
 * @param AIPKit_Vector_Pinecone_Strategy $strategyInstance The instance of the strategy class.
 * @param string $index_name The name of the index to describe.
 * @return array|WP_Error Index details or WP_Error.
 */
function describe_index_logic(AIPKit_Vector_Pinecone_Strategy $strategyInstance, string $index_name) {
    $description = get_index_overview_logic($strategyInstance, $index_name);
    if (is_wp_error($description)) {
        return $description;
    }

    // Now get stats from the data plane
    $host = $description['host'] ?? null;
    if (empty($host)) {
        // This is not a fatal error, just means we can't get stats. Return what we have.
        $description['total_vector_count'] = 'No Host';
        return $description;
    }

    // The stats endpoint is /describe_index_stats
    $stats_response = _request_logic($strategyInstance, 'POST', '/describe_index_stats', [], 'https://' . $host);
    if (is_wp_error($stats_response)) {
        $description['total_vector_count'] = 'Error';
    } else {
        // Merge stats into the description object
        $description['total_vector_count'] = $stats_response['totalVectorCount'] ?? $stats_response['total_vector_count'] ?? 0;
        if (isset($stats_response['namespaces'])) {
            $description['namespaces'] = $stats_response['namespaces'];
        }
    }

    return $description;
}

/**
 * Logic for the list_indexes method of AIPKit_Vector_Pinecone_Strategy.
 * REVISED: This now only fetches the list of index names for performance.
 * Detailed stats are fetched on-demand when a user selects an index in the UI.
 *
 * @param AIPKit_Vector_Pinecone_Strategy $strategyInstance The instance of the strategy class.
 * @param int|null $limit Max items.
 * @param string|null $order Sort order.
 * @param string|null $after Cursor for next page.
 * @param string|null $before Cursor for previous page.
 * @return array|WP_Error List of indexes or WP_Error.
 */
function list_indexes_logic(AIPKit_Vector_Pinecone_Strategy $strategyInstance, ?int $limit = null, ?string $order = null, ?string $after = null, ?string $before = null) {
    $path = '/indexes';
    $list_response = _request_logic($strategyInstance, 'GET', $path);

    if (is_wp_error($list_response)) {
        return $list_response;
    }

    $indexes_data = $list_response['indexes'] ?? [];
    if (empty($indexes_data) && is_array($list_response)) { // Fallback for older API format
        if (isset($list_response['collections'])) {
            $indexes_data = $list_response['collections'];
        } else {
            $indexes_data = $list_response;
        }
    }

    $formatted_indexes = [];
    if (is_array($indexes_data)) {
        foreach ($indexes_data as $index_obj_from_list) {
            $index_name = is_string($index_obj_from_list) ? $index_obj_from_list : ($index_obj_from_list['name'] ?? null);
            if (!$index_name) {
                continue;
            }

            // Only return basic info available from the list call.
            // Details like stats will be fetched on demand.
            $formatted_indexes[] = [
                'id'   => $index_name,
                'name' => $index_name,
                'dimension' => $index_obj_from_list['dimension'] ?? null,
                'metric'    => $index_obj_from_list['metric'] ?? null,
                'host'      => $index_obj_from_list['host'] ?? null,
                'status'    => $index_obj_from_list['status'] ?? null,
                'spec'      => $index_obj_from_list['spec'] ?? null,
            ];
        }
    }
    return $formatted_indexes;
}

/**
 * Logic for the query_vectors method of AIPKit_Vector_Pinecone_Strategy.
 *
 * @param AIPKit_Vector_Pinecone_Strategy $strategyInstance The instance of the strategy class.
 * @param string $index_name The name of the index.
 * @param array $query_vector_param The query vector data.
 * @param int $top_k Number of nearest neighbors to return.
 * @param array $filter Optional metadata filter.
 * @return array|WP_Error Array of matching vectors or WP_Error.
 */
function query_vectors_logic(AIPKit_Vector_Pinecone_Strategy $strategyInstance, string $index_name, array $query_vector_param, int $top_k, array $filter = []) {
    $index_description = get_index_overview_logic($strategyInstance, $index_name);
    if (is_wp_error($index_description)) return $index_description;
    $host = $index_description['host'] ?? null;
    if (empty($host)) return new WP_Error('missing_host_pinecone_query', __('Index host not found for query operation.', 'gpt3-ai-content-generator'));

    if (!isset($query_vector_param['vector']) || !is_array($query_vector_param['vector'])) {
        return new WP_Error('invalid_query_vector_structure', __('Query vector must contain a "vector" key with an array of embeddings.', 'gpt3-ai-content-generator'));
    }

    $body = [
        'vector' => $query_vector_param['vector'],
        'topK' => $top_k,
        'includeMetadata' => true,
        'includeValues' => false
    ];
    if (!empty($filter)) {
        $body['filter'] = $filter;
    }
    if (isset($query_vector_param['namespace']) && !empty($query_vector_param['namespace'])) {
        $body['namespace'] = $query_vector_param['namespace'];
    }

    $response = _request_logic($strategyInstance, 'POST', '/query', $body, 'https://' . $host);
    if (is_wp_error($response)) return $response;

    $matches = $response['matches'] ?? [];
    $results = [];
    foreach($matches as $match) {
        $results[] = [
            'id' => $match['id'] ?? null,
            'score' => $match['score'] ?? null,
            'metadata' => $match['metadata'] ?? [],
        ];
    }
    return $results;
}

/**
 * Logic for the upload_file_for_vector_store method of AIPKit_Vector_Pinecone_Strategy.
 * Pinecone does not support direct file uploads for vector store creation/population in the same way OpenAI does.
 * Vectors must be generated and upserted.
 *
 * @param AIPKit_Vector_Pinecone_Strategy $strategyInstance The instance of the strategy class.
 * @param string $file_path Absolute path to the file.
 * @param string $original_filename The original filename.
 * @param string $purpose Purpose of the file (unused for Pinecone).
 * @return array|WP_Error Always returns WP_Error as not applicable.
 */
function upload_file_for_vector_store_logic(AIPKit_Vector_Pinecone_Strategy $strategyInstance, string $file_path, string $original_filename, string $purpose = 'user_data') {
    return new WP_Error('not_applicable_pinecone_file_upload', __('Direct file upload is not applicable for Pinecone. Generate embeddings and upsert vectors.', 'gpt3-ai-content-generator'));
}

/**
 * Logic for the upsert_vectors method of AIPKit_Vector_Pinecone_Strategy.
 *
 * @param AIPKit_Vector_Pinecone_Strategy $strategyInstance The instance of the strategy class.
 * @param string $index_name The name of the index.
 * @param array $vectors_data Data containing vectors and optional namespace.
 * @return array|WP_Error Result of the upsert operation or WP_Error.
 */
function upsert_vectors_logic(AIPKit_Vector_Pinecone_Strategy $strategyInstance, string $index_name, array $vectors_data) {
    $index_description = get_index_overview_logic($strategyInstance, $index_name);
    if (is_wp_error($index_description)) return $index_description;
    $host = $index_description['host'] ?? null;
    if (empty($host)) return new WP_Error('missing_host_pinecone_upsert', __('Index host not found for upsert operation.', 'gpt3-ai-content-generator'));

    $path = '/vectors/upsert';
    $vectors_list = $vectors_data['vectors'] ?? $vectors_data;
    if (!is_array($vectors_list)) {
        return new WP_Error('pinecone_upsert_invalid_vectors', __('Invalid vectors payload for upsert.', 'gpt3-ai-content-generator'));
    }

    // Optional: Dimension pre-validation (if dimension available from index description)
    $expected_dim = isset($index_description['dimension']) ? (int) $index_description['dimension'] : null;
    if ($expected_dim && $expected_dim > 0) {
        foreach ($vectors_list as $vec) {
            $values = $vec['values'] ?? $vec['vector'] ?? null;
            if (is_array($values) && count($values) !== $expected_dim) {
                /* translators: %1$d: expected dimension, %2$d: actual dimension */
                return new WP_Error('vector_dimension_mismatch', sprintf(__('Vector dimension mismatch. Expected %1$d, got %2$d.', 'gpt3-ai-content-generator'), $expected_dim, count($values)));
            }
        }
    } else {
        // Fallback internal consistency check: ensure all vectors have same length
        $first_len = null;
        foreach ($vectors_list as $vec) {
            $values = $vec['values'] ?? $vec['vector'] ?? null;
            if (!is_array($values)) continue;
            $len = count($values);
            if ($first_len === null) { $first_len = $len; }
            if ($first_len !== $len) {
                return new WP_Error('vector_dimension_inconsistent', __('Vectors have inconsistent dimensions in the upsert payload.', 'gpt3-ai-content-generator'));
            }
        }
    }

    // Batch upsert to avoid oversized requests/timeouts
    $namespace = $vectors_data['namespace'] ?? null;
    $batch_size = apply_filters('aipkit_pinecone_upsert_batch_size', 100, $index_name);
    $all_results = [];
    $total = count($vectors_list);
    for ($i = 0; $i < $total; $i += $batch_size) {
        $chunk = array_slice($vectors_list, $i, $batch_size);
        $body = ['vectors' => $chunk];
        if ($namespace) { $body['namespace'] = $namespace; }
        $resp = _request_logic($strategyInstance, 'POST', $path, $body, 'https://' . $host);
        if (is_wp_error($resp)) return $resp;
        $all_results[] = $resp;
    }

    // Return last response plus summary
    $last = end($all_results);
    if (is_array($last)) {
        $last['aipkit_batches'] = count($all_results);
    }
    return $last;
}
