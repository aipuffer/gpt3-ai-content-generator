<?php

namespace WPAICG\Vector\Providers;

use WPAICG\Vector\AIPKit_Vector_Base_Provider_Strategy;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Chroma Vector Store Provider Strategy.
 */
class AIPKit_Vector_Chroma_Strategy extends AIPKit_Vector_Base_Provider_Strategy
{
    protected $api_key;
    protected $chroma_url;
    protected $tenant = 'default_tenant';
    protected $database = 'default_database';

    /**
     * @return bool|\WP_Error
     */
    public function connect(array $config)
    {
        return \WPAICG\Vector\Providers\Chroma\Methods\connect_logic($this, $config);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function create_index_if_not_exists(string $index_name, array $index_config)
    {
        return \WPAICG\Vector\Providers\Chroma\Methods\create_index_if_not_exists_logic($this, $index_name, $index_config);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function upsert_vectors(string $index_name, array $vectors_data)
    {
        return \WPAICG\Vector\Providers\Chroma\Methods\upsert_vectors_logic($this, $index_name, $vectors_data);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function query_vectors(string $index_name, array $query_vector_param, int $top_k, array $filter = [])
    {
        return \WPAICG\Vector\Providers\Chroma\Methods\query_vectors_logic($this, $index_name, $query_vector_param, $top_k, $filter);
    }

    /**
     * @return bool|\WP_Error
     */
    public function delete_vectors(string $index_name, array $vector_ids_or_filter)
    {
        return \WPAICG\Vector\Providers\Chroma\Methods\delete_vectors_logic($this, $index_name, $vector_ids_or_filter);
    }

    /**
     * @return bool|\WP_Error
     */
    public function delete_index(string $index_name)
    {
        return \WPAICG\Vector\Providers\Chroma\Methods\delete_index_logic($this, $index_name);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function list_indexes(?int $limit = null, ?string $order = null, ?string $after = null, ?string $before = null)
    {
        return \WPAICG\Vector\Providers\Chroma\Methods\list_indexes_logic($this, $limit, $order, $after, $before);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function describe_index(string $index_name)
    {
        return \WPAICG\Vector\Providers\Chroma\Methods\describe_index_logic($this, $index_name);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function upload_file_for_vector_store(string $file_path, string $original_filename, string $purpose = 'user_data')
    {
        return \WPAICG\Vector\Providers\Chroma\Methods\upload_file_for_vector_store_logic($this, $file_path, $original_filename, $purpose);
    }

    public function get_api_key(): ?string
    {
        return $this->api_key;
    }

    public function get_chroma_url(): ?string
    {
        return $this->chroma_url;
    }

    public function get_tenant(): string
    {
        return $this->tenant ?: 'default_tenant';
    }

    public function get_database(): string
    {
        return $this->database ?: 'default_database';
    }

    public function get_is_connected_status(): bool
    {
        return $this->is_connected;
    }

    public function set_api_key(?string $key): void
    {
        $this->api_key = $key ? trim($key) : null;
    }

    public function set_chroma_url(?string $url): void
    {
        $url = $url ? rtrim(trim($url), '/') : null;
        if ($url && substr_compare($url, '/api/v2', -strlen('/api/v2')) === 0) {
            $url = (string) substr($url, 0, -7);
        }
        $this->chroma_url = $url;
    }

    public function set_tenant(?string $tenant): void
    {
        $tenant = $tenant ? trim($tenant) : '';
        $this->tenant = $tenant !== '' ? $tenant : 'default_tenant';
    }

    public function set_database(?string $database): void
    {
        $database = $database ? trim($database) : '';
        $this->database = $database !== '' ? $database : 'default_database';
    }

    public function set_is_connected_status(bool $status): void
    {
        $this->is_connected = $status;
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function decode_json_public_wrapper(string $json_string, string $context)
    {
        return parent::decode_json($json_string, $context);
    }

    public function parse_error_response_public_wrapper($response_body, int $status_code, string $context): string
    {
        return parent::parse_error_response($response_body, $status_code, $context);
    }
}

namespace WPAICG\Vector\Providers\Chroma\Methods;

use WPAICG\Vector\Providers\AIPKit_Vector_Chroma_Strategy;
use WP_Error;

/**
 * @return mixed[]|\WP_Error
 */
function _request_logic(AIPKit_Vector_Chroma_Strategy $strategyInstance, string $method, string $path, array $body = [], array $query_params = [])
{
    $chroma_url = $strategyInstance->get_chroma_url();
    if (empty($chroma_url)) {
        return new WP_Error('chroma_url_not_set', __('Chroma URL not configured in strategy.', 'gpt3-ai-content-generator'));
    }

    $path = '/' . ltrim($path, '/');
    $url = rtrim($chroma_url, '/') . $path;
    if (!empty($query_params)) {
        $url = add_query_arg($query_params, $url);
    }

    $headers = [
        'Accept'       => 'application/json',
        'Content-Type' => 'application/json',
    ];
    $api_key = $strategyInstance->get_api_key();
    if (!empty($api_key)) {
        $headers['x-chroma-token'] = $api_key;
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
    $trimmed_body = trim((string) $response_body_raw);
    $decoded_response = [];

    if ($trimmed_body !== '') {
        $decoded = json_decode($trimmed_body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            if ($status_code >= 400) {
                $error_msg = $strategyInstance->parse_error_response_public_wrapper($response_body_raw, $status_code, 'Chroma Vector Store');
                /* translators: %1$d: HTTP status code, %2$s: Error message from the API. */
                return new WP_Error('chroma_api_error', sprintf(__('Chroma API Error (%1$d): %2$s', 'gpt3-ai-content-generator'), $status_code, $error_msg), ['status' => $status_code]);
            }
            /* translators: %s: JSON parse error. */
            return new WP_Error('chroma_json_decode_error', sprintf(__('Failed to parse JSON response from Chroma Vector Store. Error: %s', 'gpt3-ai-content-generator'), json_last_error_msg()), ['status' => $status_code]);
        }
        $decoded_response = is_array($decoded) ? $decoded : ['value' => $decoded];
    }

    if ($status_code >= 400) {
        $error_msg = $strategyInstance->parse_error_response_public_wrapper($decoded_response ?: $response_body_raw, $status_code, 'Chroma Vector Store');
        /* translators: %1$d: HTTP status code, %2$s: Error message from the API. */
        return new WP_Error('chroma_api_error', sprintf(__('Chroma API Error (%1$d): %2$s', 'gpt3-ai-content-generator'), $status_code, $error_msg), ['status' => $status_code]);
    }

    if (strtoupper($method) === 'DELETE' && in_array($status_code, [200, 202, 204], true) && empty($decoded_response)) {
        return ['deleted' => true];
    }

    return !empty($decoded_response) ? $decoded_response : ['status' => 'ok'];
}

function collection_base_path_logic(AIPKit_Vector_Chroma_Strategy $strategyInstance): string
{
    return '/api/v2/tenants/' . rawurlencode($strategyInstance->get_tenant()) .
        '/databases/' . rawurlencode($strategyInstance->get_database()) .
        '/collections';
}

function collection_records_path_logic(AIPKit_Vector_Chroma_Strategy $strategyInstance, string $collection_id, string $operation): string
{
    return collection_base_path_logic($strategyInstance) . '/' . rawurlencode($collection_id) . '/' . ltrim($operation, '/');
}

/**
 * @return mixed[]|\WP_Error
 */
function resolve_collection_logic(AIPKit_Vector_Chroma_Strategy $strategyInstance, string $collection_name_or_id)
{
    $target = trim($collection_name_or_id);
    if ($target === '') {
        return new WP_Error('chroma_collection_name_missing', __('Chroma collection name is required.', 'gpt3-ai-content-generator'), ['status' => 400]);
    }

    $collections = list_indexes_logic($strategyInstance, null, null, null, null);
    if (is_wp_error($collections)) {
        return $collections;
    }

    foreach ($collections as $collection) {
        $collection_id = isset($collection['id']) ? (string) $collection['id'] : '';
        $collection_name = isset($collection['name']) ? (string) $collection['name'] : '';
        if ($collection_id === $target || $collection_name === $target) {
            return $collection;
        }
    }

    /* translators: %s: Chroma collection name. */
    return new WP_Error('chroma_collection_not_found', sprintf(__('Chroma collection "%s" was not found.', 'gpt3-ai-content-generator'), esc_html($target)), ['status' => 404]);
}

function flatten_metadata_logic(array $metadata, string $prefix = ''): array
{
    $flat = [];
    foreach ($metadata as $key => $value) {
        $key = trim((string) $key);
        if ($key === '') {
            continue;
        }
        $flat_key = $prefix === '' ? $key : $prefix . '_' . $key;

        if ($value === null) {
            continue;
        }
        if (is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            $flat[$flat_key] = $value;
            continue;
        }
        if (is_array($value)) {
            $all_scalar = true;
            foreach ($value as $item) {
                if (!is_bool($item) && !is_int($item) && !is_float($item) && !is_string($item)) {
                    $all_scalar = false;
                    break;
                }
            }
            if ($all_scalar) {
                $encoded = wp_json_encode(array_values($value));
                if (is_string($encoded)) {
                    $flat[$flat_key] = $encoded;
                }
            } else {
                $flat = array_merge($flat, flatten_metadata_logic($value, $flat_key));
            }
            continue;
        }

        $encoded = wp_json_encode($value);
        if (is_string($encoded)) {
            $flat[$flat_key] = $encoded;
        }
    }

    return $flat;
}

/**
 * @return mixed[]|\WP_Error
 */
function prepare_vectors_for_chroma_logic(array $vectors_data)
{
    if (isset($vectors_data['ids'], $vectors_data['embeddings']) && is_array($vectors_data['ids']) && is_array($vectors_data['embeddings'])) {
        return validate_chroma_columnar_payload_logic($vectors_data);
    }

    $vectors_list = $vectors_data['vectors'] ?? ($vectors_data['points'] ?? $vectors_data);
    if (!is_array($vectors_list)) {
        return new WP_Error('chroma_upsert_invalid_vectors', __('Invalid vectors payload for Chroma upsert.', 'gpt3-ai-content-generator'));
    }

    $ids = [];
    $embeddings = [];
    $documents = [];
    $metadatas = [];
    $uris = [];
    $has_uri = false;

    foreach ($vectors_list as $item) {
        if (!is_array($item)) {
            continue;
        }

        $id = isset($item['id']) && (string) $item['id'] !== '' ? (string) $item['id'] : wp_generate_uuid4();
        $embedding = $item['values'] ?? ($item['vector'] ?? ($item['embedding'] ?? null));
        if (!is_array($embedding) || empty($embedding)) {
            return new WP_Error('chroma_vector_missing_embedding', __('Each Chroma vector must include an embedding array.', 'gpt3-ai-content-generator'));
        }

        $raw_metadata = [];
        if (isset($item['metadata']) && is_array($item['metadata'])) {
            $raw_metadata = $item['metadata'];
        } elseif (isset($item['payload']) && is_array($item['payload'])) {
            $raw_metadata = $item['payload'];
        }
        $metadata = flatten_metadata_logic($raw_metadata);

        $document = $item['document'] ?? ($item['content'] ?? ($item['text'] ?? null));
        if ($document === null) {
            $document = $metadata['original_content'] ?? ($metadata['text_content'] ?? null);
        }

        $ids[] = $id;
        $embeddings[] = array_values($embedding);
        $documents[] = $document !== null ? (string) $document : null;
        $metadatas[] = !empty($metadata) ? $metadata : null;

        if (isset($item['uri']) && (string) $item['uri'] !== '') {
            $uris[] = (string) $item['uri'];
            $has_uri = true;
        } else {
            $uris[] = null;
        }
    }

    $payload = [
        'ids'        => $ids,
        'embeddings' => $embeddings,
        'documents'  => $documents,
        'metadatas'  => $metadatas,
    ];
    if ($has_uri) {
        $payload['uris'] = $uris;
    }

    return validate_chroma_columnar_payload_logic($payload);
}

/**
 * @return mixed[]|\WP_Error
 */
function validate_chroma_columnar_payload_logic(array $payload)
{
    $ids = $payload['ids'] ?? [];
    $embeddings = $payload['embeddings'] ?? [];

    if (empty($ids) || empty($embeddings) || count($ids) !== count($embeddings)) {
        return new WP_Error('chroma_columnar_payload_mismatch', __('Chroma IDs and embeddings must be non-empty arrays with matching lengths.', 'gpt3-ai-content-generator'));
    }

    $expected_dim = null;
    foreach ($embeddings as $idx => $embedding) {
        if (!is_array($embedding) || empty($embedding)) {
            return new WP_Error('chroma_embedding_invalid', __('Each Chroma embedding must be a non-empty array.', 'gpt3-ai-content-generator'));
        }
        $payload['embeddings'][$idx] = array_values($embedding);
        $dim = count($embedding);
        if ($expected_dim === null) {
            $expected_dim = $dim;
        } elseif ($dim !== $expected_dim) {
            return new WP_Error('chroma_vector_dimension_inconsistent', __('Vectors have inconsistent dimensions in the Chroma upsert payload.', 'gpt3-ai-content-generator'));
        }
    }

    if (isset($payload['metadatas']) && is_array($payload['metadatas'])) {
        foreach ($payload['metadatas'] as $idx => $metadata) {
            $payload['metadatas'][$idx] = is_array($metadata) ? flatten_metadata_logic($metadata) : null;
        }
    }

    foreach (['documents', 'metadatas', 'uris'] as $optional_key) {
        if (isset($payload[$optional_key]) && is_array($payload[$optional_key]) && count($payload[$optional_key]) !== count($ids)) {
            /* translators: %s: Chroma payload field name. */
            return new WP_Error('chroma_columnar_optional_payload_mismatch', sprintf(__('Chroma "%s" must have the same length as IDs.', 'gpt3-ai-content-generator'), $optional_key));
        }
    }

    return $payload;
}

function normalize_chroma_score_logic($distance): ?float
{
    if (!is_numeric($distance)) {
        return null;
    }

    $distance = max(0.0, (float) $distance);
    return round(1 / (1 + $distance), 6);
}

/**
 * @return bool|\WP_Error
 */
function connect_logic(AIPKit_Vector_Chroma_Strategy $strategyInstance, array $config)
{
    if (empty($config['url'])) {
        return new WP_Error('missing_chroma_url', __('Chroma URL is required for connection.', 'gpt3-ai-content-generator'));
    }

    $strategyInstance->set_chroma_url($config['url']);
    $strategyInstance->set_api_key($config['api_key'] ?? null);
    $strategyInstance->set_tenant($config['tenant'] ?? null);
    $strategyInstance->set_database($config['database'] ?? null);
    $strategyInstance->set_is_connected_status(false);

    $path = '/api/v2/tenants/' . rawurlencode($strategyInstance->get_tenant()) . '/databases/' . rawurlencode($strategyInstance->get_database());
    $database_response = _request_logic($strategyInstance, 'GET', $path);

    if (is_wp_error($database_response)) {
        /* translators: %1$s: Chroma URL, %2$s: error message. */
        return new WP_Error('chroma_connection_failed', sprintf(__('Failed to connect to Chroma at %1$s. Error: %2$s', 'gpt3-ai-content-generator'), esc_html((string) $strategyInstance->get_chroma_url()), $database_response->get_error_message()), $database_response->get_error_data());
    }

    if (isset($database_response['name']) || isset($database_response['id']) || ($database_response['status'] ?? '') === 'ok') {
        $strategyInstance->set_is_connected_status(true);
        return true;
    }

    $collections_response = _request_logic($strategyInstance, 'GET', collection_base_path_logic($strategyInstance), [], ['limit' => 1]);
    if (!is_wp_error($collections_response) && is_array($collections_response)) {
        $strategyInstance->set_is_connected_status(true);
        return true;
    }

    return new WP_Error('chroma_connection_unexpected_response', __('Unexpected response while connecting to Chroma. Please check URL, tenant, database, and API key.', 'gpt3-ai-content-generator'));
}

/**
 * @return mixed[]|\WP_Error
 */
function create_index_if_not_exists_logic(AIPKit_Vector_Chroma_Strategy $strategyInstance, string $index_name, array $index_config)
{
    $existing = describe_index_logic($strategyInstance, $index_name);
    if (!is_wp_error($existing)) {
        return $existing;
    }

    $error_data = $existing->get_error_data();
    $status = is_array($error_data) ? (int) ($error_data['status'] ?? 0) : 0;
    if ($status !== 404) {
        return $existing;
    }

    $body = ['name' => $index_name];
    foreach (['metadata', 'configuration', 'schema', 'get_or_create'] as $optional_key) {
        if (array_key_exists($optional_key, $index_config)) {
            $body[$optional_key] = $index_config[$optional_key];
        }
    }

    $create_response = _request_logic($strategyInstance, 'POST', collection_base_path_logic($strategyInstance), $body);
    if (is_wp_error($create_response)) {
        return $create_response;
    }

    if (isset($create_response['name']) || isset($create_response['id'])) {
        return $create_response;
    }

    return describe_index_logic($strategyInstance, $index_name);
}

/**
 * @return bool|\WP_Error
 */
function delete_index_logic(AIPKit_Vector_Chroma_Strategy $strategyInstance, string $index_name)
{
    $collection = resolve_collection_logic($strategyInstance, $index_name);
    if (is_wp_error($collection)) {
        return $collection;
    }

    $collection_id = isset($collection['id']) ? (string) $collection['id'] : '';
    if ($collection_id === '') {
        return new WP_Error('chroma_collection_id_missing', __('Chroma collection ID is missing for delete collection operation.', 'gpt3-ai-content-generator'));
    }

    $response = _request_logic($strategyInstance, 'DELETE', collection_base_path_logic($strategyInstance) . '/' . rawurlencode($collection_id));
    if (is_wp_error($response)) {
        $error_data = $response->get_error_data();
        $status = is_array($error_data) ? (int) ($error_data['status'] ?? 0) : 0;
        $collection_name = isset($collection['name']) ? (string) $collection['name'] : $index_name;
        if ($status === 404 && $collection_name !== '' && $collection_name !== $collection_id) {
            $fallback_response = _request_logic($strategyInstance, 'DELETE', collection_base_path_logic($strategyInstance) . '/' . rawurlencode($collection_name));
            if (!is_wp_error($fallback_response)) {
                return isset($fallback_response['deleted']) || ($fallback_response['status'] ?? '') === 'ok' || is_array($fallback_response);
            }
        }
    }
    if (is_wp_error($response)) {
        return $response;
    }

    return isset($response['deleted']) || ($response['status'] ?? '') === 'ok' || is_array($response);
}

/**
 * @return bool|\WP_Error
 */
function delete_vectors_logic(AIPKit_Vector_Chroma_Strategy $strategyInstance, string $index_name, array $vector_ids_or_filter)
{
    $collection = resolve_collection_logic($strategyInstance, $index_name);
    if (is_wp_error($collection)) {
        return $collection;
    }

    $collection_id = isset($collection['id']) ? (string) $collection['id'] : '';
    if ($collection_id === '') {
        return new WP_Error('chroma_collection_id_missing', __('Chroma collection ID is missing for delete operation.', 'gpt3-ai-content-generator'));
    }

    if (isset($vector_ids_or_filter['ids']) && is_array($vector_ids_or_filter['ids'])) {
        $body = ['ids' => $vector_ids_or_filter['ids']];
    } elseif (isset($vector_ids_or_filter['where']) && is_array($vector_ids_or_filter['where'])) {
        $body = ['where' => $vector_ids_or_filter['where']];
        if (isset($vector_ids_or_filter['where_document']) && is_array($vector_ids_or_filter['where_document'])) {
            $body['where_document'] = $vector_ids_or_filter['where_document'];
        }
    } else {
        $body = ['ids' => array_values($vector_ids_or_filter)];
    }

    if (empty($body['ids']) && empty($body['where'])) {
        return new WP_Error('chroma_delete_missing_selector', __('Chroma delete requires IDs or a metadata filter.', 'gpt3-ai-content-generator'));
    }

    $response = _request_logic($strategyInstance, 'POST', collection_records_path_logic($strategyInstance, $collection_id, 'delete'), $body);
    if (is_wp_error($response)) {
        return $response;
    }

    return isset($response['deleted']) || ($response['status'] ?? '') === 'ok' || is_array($response);
}

/**
 * @return mixed[]|\WP_Error
 */
function describe_index_logic(AIPKit_Vector_Chroma_Strategy $strategyInstance, string $index_name)
{
    $collection = resolve_collection_logic($strategyInstance, $index_name);
    if (is_wp_error($collection)) {
        return $collection;
    }

    $collection_id = isset($collection['id']) ? (string) $collection['id'] : '';
    if ($collection_id === '') {
        return new WP_Error('chroma_collection_id_missing', __('Chroma collection ID is missing.', 'gpt3-ai-content-generator'));
    }

    $description = _request_logic($strategyInstance, 'GET', collection_base_path_logic($strategyInstance) . '/' . rawurlencode($collection_id));
    if (is_wp_error($description)) {
        $error_data = $description->get_error_data();
        $status = is_array($error_data) ? (int) ($error_data['status'] ?? 0) : 0;
        $collection_name = isset($collection['name']) ? (string) $collection['name'] : $index_name;
        if ($status === 404 && $collection_name !== '' && $collection_name !== $collection_id) {
            $fallback_description = _request_logic($strategyInstance, 'GET', collection_base_path_logic($strategyInstance) . '/' . rawurlencode($collection_name));
            if (!is_wp_error($fallback_description)) {
                $description = $fallback_description;
                $collection_id = isset($fallback_description['id']) ? (string) $fallback_description['id'] : $collection_id;
            }
        }
    }
    if (is_wp_error($description)) {
        return $description;
    }

    $count_response = _request_logic($strategyInstance, 'GET', collection_records_path_logic($strategyInstance, $collection_id, 'count'));
    $count_value = !is_wp_error($count_response)
        ? ($count_response['value'] ?? ($count_response['count'] ?? null))
        : null;
    if ($count_value !== null && is_numeric($count_value)) {
        $description['total_vector_count'] = (int) $count_value;
        $description['vectors_count'] = (int) $count_value;
    }

    $description['id'] = $description['id'] ?? $collection_id;
    $description['name'] = $description['name'] ?? ($collection['name'] ?? $index_name);
    $description['tenant'] = $description['tenant'] ?? $strategyInstance->get_tenant();
    $description['database'] = $description['database'] ?? $strategyInstance->get_database();

    return $description;
}

/**
 * @return mixed[]|\WP_Error
 */
function list_indexes_logic(AIPKit_Vector_Chroma_Strategy $strategyInstance, ?int $limit = null, ?string $order = null, ?string $after = null, ?string $before = null)
{
    $query_params = [];
    if ($limit !== null && $limit > 0) {
        $query_params['limit'] = $limit;
    }
    if ($after !== null && is_numeric($after)) {
        $query_params['offset'] = absint($after);
    }

    $response = _request_logic($strategyInstance, 'GET', collection_base_path_logic($strategyInstance), [], $query_params);
    if (is_wp_error($response)) {
        return $response;
    }

    $collections_data = $response;
    if (isset($response['collections']) && is_array($response['collections'])) {
        $collections_data = $response['collections'];
    } elseif (isset($response['data']) && is_array($response['data'])) {
        $collections_data = $response['data'];
    }

    $formatted_collections = [];
    if (is_array($collections_data)) {
        foreach ($collections_data as $collection) {
            if (!is_array($collection)) {
                continue;
            }
            $name = isset($collection['name']) ? (string) $collection['name'] : '';
            $id = isset($collection['id']) ? (string) $collection['id'] : $name;
            if ($name === '' && $id === '') {
                continue;
            }
            $formatted_collections[] = [
                'id'        => $id,
                'name'      => $name !== '' ? $name : $id,
                'dimension' => $collection['dimension'] ?? null,
                'metadata'  => $collection['metadata'] ?? [],
                'tenant'    => $collection['tenant'] ?? $strategyInstance->get_tenant(),
                'database'  => $collection['database'] ?? $strategyInstance->get_database(),
            ];
        }
    }

    return $formatted_collections;
}

/**
 * @return mixed[]|\WP_Error
 */
function query_vectors_logic(AIPKit_Vector_Chroma_Strategy $strategyInstance, string $index_name, array $query_vector_param, int $top_k, array $filter = [])
{
    $collection = resolve_collection_logic($strategyInstance, $index_name);
    if (is_wp_error($collection)) {
        return $collection;
    }

    $collection_id = isset($collection['id']) ? (string) $collection['id'] : '';
    if ($collection_id === '') {
        return new WP_Error('chroma_collection_id_missing', __('Chroma collection ID is missing for query operation.', 'gpt3-ai-content-generator'));
    }

    $vector_values = $query_vector_param['vector'] ?? ($query_vector_param['query'] ?? ($query_vector_param ?: null));
    if (!is_array($vector_values) || empty($vector_values)) {
        return new WP_Error('invalid_chroma_query_vector', __('Invalid query vector provided for Chroma search.', 'gpt3-ai-content-generator'));
    }

    $body = [
        'query_embeddings' => [array_values($vector_values)],
        'n_results'        => max(1, $top_k),
        'include'          => ['documents', 'metadatas', 'distances'],
    ];

    if (!empty($filter)) {
        $filter_handled = false;
        if (isset($filter['where']) && is_array($filter['where'])) {
            $body['where'] = $filter['where'];
            $filter_handled = true;
        }
        if (isset($filter['where_document']) && is_array($filter['where_document'])) {
            $body['where_document'] = $filter['where_document'];
            $filter_handled = true;
        }
        if (isset($filter['ids']) && is_array($filter['ids'])) {
            $body['ids'] = $filter['ids'];
            $filter_handled = true;
        }
        if (!$filter_handled) {
            $body['where'] = $filter;
        }
    }

    $response = _request_logic($strategyInstance, 'POST', collection_records_path_logic($strategyInstance, $collection_id, 'query'), $body);
    if (is_wp_error($response)) {
        return $response;
    }

    $ids = $response['ids'][0] ?? [];
    $distances = $response['distances'][0] ?? [];
    $documents = $response['documents'][0] ?? [];
    $metadatas = $response['metadatas'][0] ?? [];
    $results = [];

    foreach ($ids as $idx => $id) {
        $metadata = isset($metadatas[$idx]) && is_array($metadatas[$idx]) ? $metadatas[$idx] : [];
        $document = $documents[$idx] ?? null;
        if ($document !== null && !isset($metadata['original_content'])) {
            $metadata['original_content'] = (string) $document;
        }

        $distance = $distances[$idx] ?? null;
        $score = normalize_chroma_score_logic($distance);
        if ($score !== null) {
            $metadata['chroma_distance'] = (float) $distance;
        }

        $results[] = [
            'id'         => $id,
            'score'      => $score,
            'distance'   => $distance,
            'metadata'   => $metadata,
            'collection' => $collection['name'] ?? $index_name,
        ];
    }

    return $results;
}

/**
 * @return mixed[]|\WP_Error
 */
function upload_file_for_vector_store_logic(AIPKit_Vector_Chroma_Strategy $strategyInstance, string $file_path, string $original_filename, string $purpose = 'user_data')
{
    return new WP_Error('chroma_file_upload_not_applicable', __('Direct file upload is not supported by the Chroma vector strategy. Files must be parsed, chunked, embedded, and upserted as records.', 'gpt3-ai-content-generator'));
}

/**
 * @return mixed[]|\WP_Error
 */
function upsert_vectors_logic(AIPKit_Vector_Chroma_Strategy $strategyInstance, string $index_name, array $vectors_data)
{
    $collection = resolve_collection_logic($strategyInstance, $index_name);
    if (is_wp_error($collection)) {
        return $collection;
    }

    $collection_id = isset($collection['id']) ? (string) $collection['id'] : '';
    if ($collection_id === '') {
        return new WP_Error('chroma_collection_id_missing', __('Chroma collection ID is missing for upsert operation.', 'gpt3-ai-content-generator'));
    }

    $payload = prepare_vectors_for_chroma_logic($vectors_data);
    if (is_wp_error($payload)) {
        return $payload;
    }

    $batch_size = (int) apply_filters('aipkit_chroma_upsert_batch_size', 100, $index_name);
    $batch_size = max(1, $batch_size);
    $total = count($payload['ids']);
    $batches = 0;

    for ($i = 0; $i < $total; $i += $batch_size) {
        $chunk_body = [
            'ids'        => array_slice($payload['ids'], $i, $batch_size),
            'embeddings' => array_slice($payload['embeddings'], $i, $batch_size),
        ];
        foreach (['documents', 'metadatas', 'uris'] as $optional_key) {
            if (isset($payload[$optional_key])) {
                $chunk_body[$optional_key] = array_slice($payload[$optional_key], $i, $batch_size);
            }
        }

        $response = _request_logic($strategyInstance, 'POST', collection_records_path_logic($strategyInstance, $collection_id, 'upsert'), $chunk_body);
        if (is_wp_error($response)) {
            return $response;
        }
        $batches++;
    }

    return [
        'status' => 'success',
        'upserted_count' => $total,
        'aipkit_batches' => $batches,
    ];
}
