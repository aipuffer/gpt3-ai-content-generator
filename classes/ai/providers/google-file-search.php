<?php

namespace WPAICG\Core\Providers\Google\FileSearch;

use WP_Error;
use WPAICG\Core\Providers\Google\Interactions\GoogleModelCapabilityClassifier;
use WPAICG\Core\Providers\Google\Interactions\GoogleInteractionsErrorParser;
use WPAICG\Core\AIPKit_HTTP_Request;

if (!defined('ABSPATH')) {
    exit;
}

final class GoogleFileSearchUrlBuilder
{
    // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Provider-specific API transport.
    public const DEFAULT_BASE_URL = 'https://generativelanguage.googleapis.com';
    public const API_VERSION = 'v1beta';

    /**
     * Build one File Search resource URL without putting credentials in it.
     *
     * @param string               $operation stores, store, documents, document, import_file, operation, or upload_start.
     * @param array<string, mixed> $connection Google connection values.
     * @param array<string, mixed> $params Resource and pagination values.
     * @return string|WP_Error
     */
    public static function build(string $operation, array $connection = [], array $params = [])
    {
        $base_url = self::base_url($connection);
        if (is_wp_error($base_url)) {
            return $base_url;
        }

        $api_root = $base_url . '/' . self::API_VERSION;
        if ($operation === 'stores') {
            return self::with_list_query($api_root . '/fileSearchStores', $params);
        }

        $store_name = self::normalize_store_name((string) ($params['store_name'] ?? ''));
        if (is_wp_error($store_name)) {
            return $store_name;
        }

        if ($operation === 'store') {
            return self::with_force_query($api_root . '/' . $store_name, $params);
        }
        if ($operation === 'documents') {
            return self::with_list_query($api_root . '/' . $store_name . '/documents', $params);
        }
        if ($operation === 'import_file') {
            return $api_root . '/' . $store_name . ':importFile';
        }
        if ($operation === 'upload_start') {
            return $base_url . '/upload/' . self::API_VERSION . '/' . $store_name . ':uploadToFileSearchStore';
        }
        if ($operation === 'document') {
            $document_name = self::normalize_document_name((string) ($params['document_name'] ?? ''), $store_name);
            if (is_wp_error($document_name)) {
                return $document_name;
            }
            return self::with_force_query($api_root . '/' . $document_name, $params);
        }
        if ($operation === 'operation') {
            $operation_name = self::normalize_operation_name((string) ($params['operation_name'] ?? ''), $store_name);
            if (is_wp_error($operation_name)) {
                return $operation_name;
            }
            return $api_root . '/' . $operation_name;
        }

        return new WP_Error(
            'google_file_search_unknown_operation',
            __('Unsupported Google File Search operation.', 'gpt3-ai-content-generator')
        );
    }

    /**
     * @return string|WP_Error
     */
    public static function normalize_store_name(string $store_name)
    {
        $store_name = trim($store_name, "/ \t\n\r\0\x0B");
        if (strpos($store_name, 'fileSearchStores/') === 0) {
            $store_name = (string) substr($store_name, strlen('fileSearchStores/'));
        }
        if (!self::is_resource_id($store_name, 40)) {
            return new WP_Error(
                'google_file_search_invalid_store_name',
                __('Google store names must contain only lowercase letters, numbers, and internal hyphens.', 'gpt3-ai-content-generator')
            );
        }

        return 'fileSearchStores/' . $store_name;
    }

    /**
     * @return string|WP_Error
     */
    public static function normalize_document_name(string $document_name, string $expected_store_name = '')
    {
        $document_name = trim($document_name, "/ \t\n\r\0\x0B");
        $matches = [];
        if (!preg_match('#^fileSearchStores/([^/]+)/documents/([^/]+)$#', $document_name, $matches)) {
            return new WP_Error(
                'google_file_search_invalid_document_name',
                __('A complete Google File Search document resource name is required.', 'gpt3-ai-content-generator')
            );
        }
        if (!self::is_resource_id($matches[1], 40) || !self::is_resource_id($matches[2], 40)) {
            return new WP_Error(
                'google_file_search_invalid_document_name',
                __('The Google File Search document resource name is invalid.', 'gpt3-ai-content-generator')
            );
        }

        if ($expected_store_name !== '') {
            $normalized_store = self::normalize_store_name($expected_store_name);
            if (is_wp_error($normalized_store) || $normalized_store !== 'fileSearchStores/' . $matches[1]) {
                return new WP_Error(
                    'google_file_search_document_store_mismatch',
                    __('The Google File Search document does not belong to the selected store.', 'gpt3-ai-content-generator')
                );
            }
        }

        return 'fileSearchStores/' . $matches[1] . '/documents/' . $matches[2];
    }

    /**
     * @return string|WP_Error
     */
    public static function normalize_file_name(string $file_name)
    {
        $file_name = trim($file_name, "/ \t\n\r\0\x0B");
        if (strpos($file_name, 'files/') !== 0) {
            return new WP_Error(
                'google_file_search_invalid_file_name',
                __('A complete Google Files API resource name is required.', 'gpt3-ai-content-generator')
            );
        }
        $file_id = (string) substr($file_name, strlen('files/'));
        if ($file_id === '' || strlen($file_id) > 256 || !preg_match('/^[A-Za-z0-9._~-]+$/', $file_id)) {
            return new WP_Error(
                'google_file_search_invalid_file_name',
                __('The Google Files API resource name is invalid.', 'gpt3-ai-content-generator')
            );
        }

        return 'files/' . $file_id;
    }

    /**
     * @return string|WP_Error
     */
    public static function normalize_operation_name(string $operation_name, string $expected_store_name = '')
    {
        $operation_name = trim($operation_name, "/ \t\n\r\0\x0B");
        $matches = [];
        if (!preg_match('#^fileSearchStores/([^/]+)/(upload/)?operations/([^/]+)$#', $operation_name, $matches)) {
            return new WP_Error(
                'google_file_search_invalid_operation_name',
                __('A complete Google File Search operation resource name is required.', 'gpt3-ai-content-generator')
            );
        }
        if (
            !self::is_resource_id($matches[1], 40)
            || strlen($matches[3]) > 256
            || !preg_match('/^[A-Za-z0-9._~-]+$/', $matches[3])
        ) {
            return new WP_Error(
                'google_file_search_invalid_operation_name',
                __('The Google File Search operation resource name is invalid.', 'gpt3-ai-content-generator')
            );
        }

        if ($expected_store_name !== '') {
            $normalized_store = self::normalize_store_name($expected_store_name);
            if (is_wp_error($normalized_store) || $normalized_store !== 'fileSearchStores/' . $matches[1]) {
                return new WP_Error(
                    'google_file_search_operation_store_mismatch',
                    __('The Google File Search operation does not belong to the selected store.', 'gpt3-ai-content-generator')
                );
            }
        }

        return 'fileSearchStores/' . $matches[1] . '/' . ($matches[2] ?? '') . 'operations/' . $matches[3];
    }

    /**
     * Validate a resumable upload URL before sending document bytes to it.
     */
    public static function is_trusted_upload_url(string $upload_url, array $connection = []): bool
    {
        $upload_parts = wp_parse_url($upload_url);
        $base_url = self::base_url($connection);
        $base_parts = is_wp_error($base_url) ? false : wp_parse_url($base_url);
        if (!is_array($upload_parts) || !is_array($base_parts)) {
            return false;
        }

        $upload_scheme = strtolower((string) ($upload_parts['scheme'] ?? ''));
        $upload_host = strtolower((string) ($upload_parts['host'] ?? ''));
        $base_host = strtolower((string) ($base_parts['host'] ?? ''));
        $upload_port = self::effective_port($upload_parts, $upload_scheme);
        $base_scheme = strtolower((string) ($base_parts['scheme'] ?? ''));
        $base_port = self::effective_port($base_parts, $base_scheme);
        $has_credentials = isset($upload_parts['user']) || isset($upload_parts['pass']);
        $is_local_http = $upload_scheme === 'http' && in_array($upload_host, ['localhost', '127.0.0.1', '::1'], true);

        return ($upload_scheme === 'https' || $is_local_http)
            && $upload_host !== ''
            && $upload_host === $base_host
            && $upload_port === $base_port
            && !$has_credentials;
    }

    /**
     * @return string|WP_Error
     */
    private static function base_url(array $connection)
    {
        $base_url = isset($connection['base_url']) && is_string($connection['base_url'])
            ? rtrim(trim($connection['base_url']), '/')
            : self::DEFAULT_BASE_URL;
        if ($base_url === '') {
            $base_url = self::DEFAULT_BASE_URL;
        }

        $parts = wp_parse_url($base_url);
        if (!is_array($parts)) {
            return self::invalid_base_url_error();
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $has_credentials = isset($parts['user']) || isset($parts['pass']);
        $has_query_or_fragment = isset($parts['query']) || isset($parts['fragment']);
        $is_local_http = $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if (($scheme !== 'https' && !$is_local_http) || $host === '' || $has_credentials || $has_query_or_fragment) {
            return self::invalid_base_url_error();
        }

        return $base_url;
    }

    private static function invalid_base_url_error(): WP_Error
    {
        return new WP_Error(
            'google_file_search_invalid_base_url',
            __('Google File Search requires an HTTPS endpoint or a local development endpoint.', 'gpt3-ai-content-generator')
        );
    }

    private static function is_resource_id(string $resource_id, int $max_length): bool
    {
        $length = strlen($resource_id);
        return $length >= 1
            && $length <= $max_length
            && (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $resource_id);
    }

    /**
     * @param array<string, mixed> $url_parts
     */
    private static function effective_port(array $url_parts, string $scheme): int
    {
        if (isset($url_parts['port']) && is_numeric($url_parts['port'])) {
            return (int) $url_parts['port'];
        }

        return $scheme === 'https' ? 443 : 80;
    }

    private static function with_list_query(string $url, array $params): string
    {
        $query = [];
        if (isset($params['page_size'])) {
            $query['pageSize'] = max(1, min(20, (int) $params['page_size']));
        }
        if (isset($params['page_token']) && is_string($params['page_token']) && trim($params['page_token']) !== '') {
            $query['pageToken'] = trim($params['page_token']);
        }

        return empty($query) ? $url : $url . '?' . self::query_string($query);
    }

    private static function with_force_query(string $url, array $params): string
    {
        if (!array_key_exists('force', $params)) {
            return $url;
        }

        return $url . '?' . self::query_string(['force' => !empty($params['force']) ? 'true' : 'false']);
    }

    /**
     * @param array<string, scalar> $query
     */
    private static function query_string(array $query): string
    {
        $parts = [];
        foreach ($query as $key => $value) {
            $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }
        return implode('&', $parts);
    }
}

final class GoogleFileSearchRequestBuilder
{
    private const MAX_DISPLAY_NAME_LENGTH = 512;
    private const MAX_CUSTOM_METADATA = 20;
    private const MAX_METADATA_FILTER_LENGTH = 4096;

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|WP_Error
     */
    public static function build_create_store(string $display_name, array $options = [])
    {
        $display_name = self::normalize_display_name($display_name);
        if (is_wp_error($display_name)) {
            return $display_name;
        }

        $payload = ['displayName' => $display_name];
        if (isset($options['embedding_model'])) {
            $embedding_model = self::normalize_embedding_model($options['embedding_model']);
            if (is_wp_error($embedding_model)) {
                return $embedding_model;
            }
            if ($embedding_model !== '') {
                $payload['embeddingModel'] = $embedding_model;
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|WP_Error
     */
    public static function build_import_file(string $file_name, array $options = [])
    {
        $file_name = GoogleFileSearchUrlBuilder::normalize_file_name($file_name);
        if (is_wp_error($file_name)) {
            return $file_name;
        }

        $payload = ['fileName' => $file_name];
        return self::append_ingestion_options($payload, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|WP_Error
     */
    public static function build_upload_metadata(string $display_name, string $mime_type, array $options = [])
    {
        $display_name = self::normalize_display_name($display_name);
        if (is_wp_error($display_name)) {
            return $display_name;
        }
        $mime_type = sanitize_mime_type($mime_type);
        if ($mime_type === '') {
            return new WP_Error(
                'google_file_search_invalid_mime_type',
                __('A valid MIME type is required for Google File Search uploads.', 'gpt3-ai-content-generator')
            );
        }

        $payload = [
            'displayName' => $display_name,
            'mimeType' => $mime_type,
        ];
        return self::append_ingestion_options($payload, $options);
    }

    /**
     * Build the Interactions-native File Search tool declaration.
     *
     * @param array<int, string>   $store_names
     * @param array<string, mixed> $options model, top_k, and metadata_filter.
     * @return array<string, mixed>|WP_Error
     */
    public static function build_tool(array $store_names, array $options = [])
    {
        $normalized_names = [];
        foreach ($store_names as $store_name) {
            if (!is_string($store_name)) {
                return new WP_Error(
                    'google_file_search_invalid_tool_store',
                    __('Every Google File Search tool store must be a resource name.', 'gpt3-ai-content-generator')
                );
            }
            $normalized_name = GoogleFileSearchUrlBuilder::normalize_store_name($store_name);
            if (is_wp_error($normalized_name)) {
                return $normalized_name;
            }
            $normalized_names[$normalized_name] = true;
        }
        $normalized_names = array_keys($normalized_names);
        if (empty($normalized_names)) {
            return new WP_Error(
                'google_file_search_missing_tool_store',
                __('Select at least one Google store.', 'gpt3-ai-content-generator')
            );
        }

        $model = isset($options['model']) && is_string($options['model']) ? trim($options['model']) : '';
        if (
            $model !== ''
            && class_exists(GoogleModelCapabilityClassifier::class)
            && !GoogleModelCapabilityClassifier::supports_file_search($model)
        ) {
            return new WP_Error(
                'google_file_search_model_not_supported',
                __('The selected Google model does not support File Search.', 'gpt3-ai-content-generator')
            );
        }

        $tool = [
            'type' => 'file_search',
            'file_search_store_names' => $normalized_names,
        ];

        if (isset($options['top_k'])) {
            if (!is_numeric($options['top_k'])) {
                return new WP_Error(
                    'google_file_search_invalid_top_k',
                    __('Google File Search results limit must be a number.', 'gpt3-ai-content-generator')
                );
            }
            $top_k = (int) $options['top_k'];
            if ($top_k < 1 || $top_k > 20) {
                return new WP_Error(
                    'google_file_search_invalid_top_k',
                    __('Google File Search results limit must be between 1 and 20.', 'gpt3-ai-content-generator')
                );
            }
            $tool['top_k'] = $top_k;
        }

        if (isset($options['metadata_filter'])) {
            if (!is_string($options['metadata_filter'])) {
                return new WP_Error(
                    'google_file_search_invalid_metadata_filter',
                    __('Google File Search metadata filter must be text.', 'gpt3-ai-content-generator')
                );
            }
            $metadata_filter = trim($options['metadata_filter']);
            if (
                strlen($metadata_filter) > self::MAX_METADATA_FILTER_LENGTH
                || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $metadata_filter)
            ) {
                return new WP_Error(
                    'google_file_search_invalid_metadata_filter',
                    __('Google File Search metadata filter contains invalid control characters or is too long.', 'gpt3-ai-content-generator')
                );
            }
            if ($metadata_filter !== '') {
                $tool['metadata_filter'] = $metadata_filter;
            }
        }

        return $tool;
    }

    /**
     * @param mixed $metadata Associative values or API-native metadata entries.
     * @return array<int, array<string, mixed>>|WP_Error
     */
    public static function normalize_custom_metadata($metadata)
    {
        if (!is_array($metadata)) {
            return new WP_Error(
                'google_file_search_invalid_metadata',
                __('Google File Search metadata must be an array.', 'gpt3-ai-content-generator')
            );
        }
        if (empty($metadata)) {
            return [];
        }

        $entries = self::is_associative($metadata) ? self::metadata_map_to_entries($metadata) : $metadata;
        if (count($entries) > self::MAX_CUSTOM_METADATA) {
            return new WP_Error(
                'google_file_search_metadata_limit',
                __('Google File Search supports at most 20 metadata entries per document.', 'gpt3-ai-content-generator')
            );
        }

        $normalized = [];
        $seen = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                return new WP_Error(
                    'google_file_search_invalid_metadata_entry',
                    __('Every Google File Search metadata entry must contain a key and value.', 'gpt3-ai-content-generator')
                );
            }
            $key = isset($entry['key']) && is_string($entry['key'])
                ? trim(wp_strip_all_tags($entry['key']))
                : '';
            if ($key === '' || strlen($key) > 128 || preg_match('/[\x00-\x1F\x7F]/', $key)) {
                return new WP_Error(
                    'google_file_search_invalid_metadata_key',
                    __('Google File Search metadata keys must be non-empty plain text.', 'gpt3-ai-content-generator')
                );
            }
            if (isset($seen[$key])) {
                return new WP_Error(
                    'google_file_search_duplicate_metadata_key',
                    __('Google File Search metadata keys must be unique.', 'gpt3-ai-content-generator')
                );
            }

            $value_result = self::normalize_metadata_value($entry);
            if (is_wp_error($value_result)) {
                return $value_result;
            }
            $seen[$key] = true;
            $normalized[] = array_merge(['key' => $key], $value_result);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     * @return array<string, mixed>|WP_Error
     */
    private static function append_ingestion_options(array $payload, array $options)
    {
        if (array_key_exists('custom_metadata', $options)) {
            $metadata = self::normalize_custom_metadata($options['custom_metadata']);
            if (is_wp_error($metadata)) {
                return $metadata;
            }
            if (!empty($metadata)) {
                $payload['customMetadata'] = $metadata;
            }
        }

        if (array_key_exists('chunking_config', $options)) {
            $chunking_config = self::normalize_chunking_config($options['chunking_config']);
            if (is_wp_error($chunking_config)) {
                return $chunking_config;
            }
            if (!empty($chunking_config)) {
                $payload['chunkingConfig'] = $chunking_config;
            }
        }

        return $payload;
    }

    /**
     * @param mixed $chunking_config
     * @return array<string, mixed>|WP_Error
     */
    private static function normalize_chunking_config($chunking_config)
    {
        if (!is_array($chunking_config)) {
            return new WP_Error(
                'google_file_search_invalid_chunking',
                __('Google File Search chunking configuration must be an array.', 'gpt3-ai-content-generator')
            );
        }
        if (empty($chunking_config)) {
            return [];
        }

        $white_space = $chunking_config['whiteSpaceConfig']
            ?? $chunking_config['white_space_config']
            ?? $chunking_config;
        if (!is_array($white_space)) {
            return new WP_Error(
                'google_file_search_invalid_chunking',
                __('Google File Search whitespace chunking configuration is invalid.', 'gpt3-ai-content-generator')
            );
        }

        $max_tokens = $white_space['maxTokensPerChunk'] ?? $white_space['max_tokens_per_chunk'] ?? null;
        $max_overlap = $white_space['maxOverlapTokens'] ?? $white_space['max_overlap_tokens'] ?? 0;
        if (!is_numeric($max_tokens) || !is_numeric($max_overlap)) {
            return new WP_Error(
                'google_file_search_invalid_chunking',
                __('Google File Search chunk sizes must be numeric.', 'gpt3-ai-content-generator')
            );
        }
        $max_tokens = (int) $max_tokens;
        $max_overlap = (int) $max_overlap;
        if ($max_tokens < 1 || $max_overlap < 0 || $max_overlap >= $max_tokens) {
            return new WP_Error(
                'google_file_search_invalid_chunking',
                __('Google File Search overlap must be non-negative and smaller than the chunk size.', 'gpt3-ai-content-generator')
            );
        }

        return [
            'whiteSpaceConfig' => [
                'maxTokensPerChunk' => $max_tokens,
                'maxOverlapTokens' => $max_overlap,
            ],
        ];
    }

    /**
     * @param mixed $embedding_model
     * @return string|WP_Error
     */
    private static function normalize_embedding_model($embedding_model)
    {
        if (!is_string($embedding_model)) {
            return new WP_Error(
                'google_file_search_invalid_embedding_model',
                __('Google File Search embedding model must be text.', 'gpt3-ai-content-generator')
            );
        }
        $embedding_model = trim($embedding_model);
        if ($embedding_model === '') {
            return '';
        }
        if (strpos($embedding_model, 'models/') === 0) {
            $embedding_model = (string) substr($embedding_model, strlen('models/'));
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $embedding_model)) {
            return new WP_Error(
                'google_file_search_invalid_embedding_model',
                __('The Google File Search embedding model ID is invalid.', 'gpt3-ai-content-generator')
            );
        }

        return 'models/' . $embedding_model;
    }

    /**
     * @return string|WP_Error
     */
    private static function normalize_display_name(string $display_name)
    {
        $display_name = trim(wp_strip_all_tags($display_name));
        if ($display_name === '' || strlen($display_name) > self::MAX_DISPLAY_NAME_LENGTH) {
            return new WP_Error(
                'google_file_search_invalid_display_name',
                __('Google File Search display names must contain between 1 and 512 characters.', 'gpt3-ai-content-generator')
            );
        }

        return $display_name;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<int, array<string, mixed>>
     */
    private static function metadata_map_to_entries(array $metadata): array
    {
        $entries = [];
        foreach ($metadata as $key => $value) {
            $entries[] = ['key' => (string) $key, 'value' => $value];
        }
        return $entries;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>|WP_Error
     */
    private static function normalize_metadata_value(array $entry)
    {
        $value = $entry['value'] ?? null;
        if (array_key_exists('stringValue', $entry) || array_key_exists('string_value', $entry)) {
            $value = $entry['stringValue'] ?? $entry['string_value'];
        } elseif (array_key_exists('numericValue', $entry) || array_key_exists('numeric_value', $entry)) {
            $numeric_value = $entry['numericValue'] ?? $entry['numeric_value'];
            if (!is_numeric($numeric_value)) {
                return self::invalid_metadata_value_error();
            }
            return ['numericValue' => 0 + $numeric_value];
        } elseif (array_key_exists('stringListValue', $entry) || array_key_exists('string_list_value', $entry)) {
            $value = $entry['stringListValue'] ?? $entry['string_list_value'];
            if (is_array($value) && isset($value['values'])) {
                $value = $value['values'];
            }
        }

        if (is_int($value) || is_float($value)) {
            return ['numericValue' => $value];
        }
        if (is_bool($value)) {
            return ['stringValue' => $value ? 'true' : 'false'];
        }
        if (is_string($value)) {
            return ['stringValue' => trim(wp_strip_all_tags($value))];
        }
        if (is_array($value)) {
            $values = [];
            foreach ($value as $list_value) {
                if (!is_scalar($list_value)) {
                    return self::invalid_metadata_value_error();
                }
                $values[] = trim(wp_strip_all_tags((string) $list_value));
            }
            return ['stringListValue' => ['values' => $values]];
        }

        return self::invalid_metadata_value_error();
    }

    private static function invalid_metadata_value_error(): WP_Error
    {
        return new WP_Error(
            'google_file_search_invalid_metadata_value',
            __('Google File Search metadata values must be text, numbers, or lists of text.', 'gpt3-ai-content-generator')
        );
    }

    /**
     * @param array<mixed> $value
     */
    private static function is_associative(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }
}

final class GoogleFileSearchErrorParser
{
    /**
     * Convert google.rpc.Code values returned inside long-running operations
     * into the HTTP status used by the rest of the provider error contract.
     */
    public static function http_status_from_rpc_code(int $rpc_code): int
    {
        $status_map = [
            1 => 499, // CANCELLED.
            2 => 500, // UNKNOWN.
            3 => 400, // INVALID_ARGUMENT.
            4 => 504, // DEADLINE_EXCEEDED.
            5 => 404, // NOT_FOUND.
            6 => 409, // ALREADY_EXISTS.
            7 => 403, // PERMISSION_DENIED.
            8 => 429, // RESOURCE_EXHAUSTED.
            9 => 400, // FAILED_PRECONDITION.
            10 => 409, // ABORTED.
            11 => 400, // OUT_OF_RANGE.
            12 => 501, // UNIMPLEMENTED.
            13 => 500, // INTERNAL.
            14 => 503, // UNAVAILABLE.
            15 => 500, // DATA_LOSS.
            16 => 401, // UNAUTHENTICATED.
        ];

        return $status_map[$rpc_code] ?? 500;
    }

    /**
     * @param string|array<mixed> $response_body
     * @param mixed               $retry_after_header
     */
    public static function to_wp_error($response_body, int $status_code, $retry_after_header = ''): WP_Error
    {
        $parsed = GoogleInteractionsErrorParser::parse($response_body, $status_code);
        $data = [
            'status' => $status_code,
            'status_code' => $status_code,
            'provider_code' => $parsed['code'],
            'provider_status' => $parsed['status'],
            'provider_details' => $parsed['details'],
        ];
        $retry_after = GoogleInteractionsErrorParser::parse_retry_after($retry_after_header);
        if ($retry_after !== null) {
            $data['retry_after'] = $retry_after;
        }

        return new WP_Error(
            self::error_code_for_status($status_code),
            sprintf(
                /* translators: %1$d: HTTP status code. %2$s: Google API error message. */
                __('Google File Search API Error (HTTP %1$d): %2$s', 'gpt3-ai-content-generator'),
                $status_code,
                $parsed['message']
            ),
            $data
        );
    }

    private static function error_code_for_status(int $status_code): string
    {
        if ($status_code === 429) {
            return 'google_file_search_rate_limited';
        }
        if ($status_code === 401 || $status_code === 403) {
            return 'google_file_search_auth_error';
        }
        if ($status_code === 400) {
            return 'google_file_search_invalid_request';
        }
        if ($status_code === 404) {
            return 'google_file_search_not_found';
        }
        if ($status_code >= 500) {
            return 'google_file_search_server_error';
        }

        return 'google_file_search_api_error';
    }
}

final class GoogleFileSearchClient
{
    public const MAX_DOCUMENT_BYTES = 104857600;

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $options
     * @return array<string, mixed>|WP_Error
     */
    public function create_store(array $connection, string $display_name, array $options = [])
    {
        $payload = GoogleFileSearchRequestBuilder::build_create_store($display_name, $options);
        if (is_wp_error($payload)) {
            return $payload;
        }
        $url = GoogleFileSearchUrlBuilder::build('stores', $connection);
        if (is_wp_error($url)) {
            return $url;
        }
        $response = $this->request_json($connection, 'POST', $url, $payload);
        if (is_wp_error($response)) {
            return $response;
        }

        return self::normalize_store($response);
    }

    /**
     * @param array<string, mixed> $connection
     * @return array{data:array<int, array<string, mixed>>,next_page_token:string}|WP_Error
     */
    public function list_stores(array $connection, int $page_size = 20, string $page_token = '')
    {
        $url = GoogleFileSearchUrlBuilder::build(
            'stores',
            $connection,
            ['page_size' => $page_size, 'page_token' => $page_token]
        );
        if (is_wp_error($url)) {
            return $url;
        }
        $response = $this->request_json($connection, 'GET', $url);
        if (is_wp_error($response)) {
            return $response;
        }
        if (isset($response['fileSearchStores']) && !is_array($response['fileSearchStores'])) {
            return self::malformed_resource_error('store list');
        }

        $stores = [];
        foreach ((array) ($response['fileSearchStores'] ?? []) as $store) {
            if (is_array($store)) {
                $normalized_store = self::normalize_store($store);
                if (is_wp_error($normalized_store)) {
                    return $normalized_store;
                }
                $stores[] = $normalized_store;
            }
        }
        return [
            'data' => $stores,
            'next_page_token' => is_string($response['nextPageToken'] ?? null)
                ? (string) $response['nextPageToken']
                : '',
        ];
    }

    /**
     * @param array<string, mixed> $connection
     * @return array<int, array<string, mixed>>|WP_Error
     */
    public function list_all_stores(array $connection, int $max_items = 1000)
    {
        $max_items = max(1, min(1000, $max_items));
        $stores = [];
        $page_token = '';
        do {
            $page = $this->list_stores($connection, min(20, $max_items - count($stores)), $page_token);
            if (is_wp_error($page)) {
                return $page;
            }
            $stores = array_merge($stores, $page['data']);
            $page_token = (string) $page['next_page_token'];
        } while ($page_token !== '' && count($stores) < $max_items);

        return array_slice($stores, 0, $max_items);
    }

    /**
     * @param array<string, mixed> $connection
     * @return array<string, mixed>|WP_Error
     */
    public function get_store(array $connection, string $store_name)
    {
        $url = GoogleFileSearchUrlBuilder::build('store', $connection, ['store_name' => $store_name]);
        if (is_wp_error($url)) {
            return $url;
        }
        $response = $this->request_json($connection, 'GET', $url);
        return is_wp_error($response) ? $response : self::normalize_store($response);
    }

    /**
     * @param array<string, mixed> $connection
     * @return true|WP_Error
     */
    public function delete_store(array $connection, string $store_name, bool $force = true)
    {
        $url = GoogleFileSearchUrlBuilder::build(
            'store',
            $connection,
            ['store_name' => $store_name, 'force' => $force]
        );
        if (is_wp_error($url)) {
            return $url;
        }
        $response = $this->request_json($connection, 'DELETE', $url);
        return is_wp_error($response) ? $response : true;
    }

    /**
     * @param array<string, mixed> $connection
     * @return array{data:array<int, array<string, mixed>>,next_page_token:string}|WP_Error
     */
    public function list_documents(
        array $connection,
        string $store_name,
        int $page_size = 20,
        string $page_token = ''
    ) {
        $url = GoogleFileSearchUrlBuilder::build(
            'documents',
            $connection,
            [
                'store_name' => $store_name,
                'page_size' => $page_size,
                'page_token' => $page_token,
            ]
        );
        if (is_wp_error($url)) {
            return $url;
        }
        $response = $this->request_json($connection, 'GET', $url);
        if (is_wp_error($response)) {
            return $response;
        }
        if (isset($response['documents']) && !is_array($response['documents'])) {
            return self::malformed_resource_error('document list');
        }

        $documents = [];
        foreach ((array) ($response['documents'] ?? []) as $document) {
            if (is_array($document)) {
                $normalized_document = self::normalize_document($document);
                if (is_wp_error($normalized_document)) {
                    return $normalized_document;
                }
                $documents[] = $normalized_document;
            }
        }
        return [
            'data' => $documents,
            'next_page_token' => is_string($response['nextPageToken'] ?? null)
                ? (string) $response['nextPageToken']
                : '',
        ];
    }

    /**
     * @param array<string, mixed> $connection
     * @return array<int, array<string, mixed>>|WP_Error
     */
    public function list_all_documents(array $connection, string $store_name, int $max_items = 1000)
    {
        $max_items = max(1, min(1000, $max_items));
        $documents = [];
        $page_token = '';
        do {
            $page = $this->list_documents(
                $connection,
                $store_name,
                min(20, $max_items - count($documents)),
                $page_token
            );
            if (is_wp_error($page)) {
                return $page;
            }
            $documents = array_merge($documents, $page['data']);
            $page_token = (string) $page['next_page_token'];
        } while ($page_token !== '' && count($documents) < $max_items);

        return array_slice($documents, 0, $max_items);
    }

    /**
     * @param array<string, mixed> $connection
     * @return array<string, mixed>|WP_Error
     */
    public function get_document(array $connection, string $store_name, string $document_name)
    {
        $url = GoogleFileSearchUrlBuilder::build(
            'document',
            $connection,
            ['store_name' => $store_name, 'document_name' => $document_name]
        );
        if (is_wp_error($url)) {
            return $url;
        }
        $response = $this->request_json($connection, 'GET', $url);
        return is_wp_error($response) ? $response : self::normalize_document($response);
    }

    /**
     * @param array<string, mixed> $connection
     * @return true|WP_Error
     */
    public function delete_document(
        array $connection,
        string $store_name,
        string $document_name,
        bool $force = true
    ) {
        $url = GoogleFileSearchUrlBuilder::build(
            'document',
            $connection,
            [
                'store_name' => $store_name,
                'document_name' => $document_name,
                'force' => $force,
            ]
        );
        if (is_wp_error($url)) {
            return $url;
        }
        $response = $this->request_json($connection, 'DELETE', $url);
        return is_wp_error($response) ? $response : true;
    }

    /**
     * Import a temporary Files API object into a persistent File Search store.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $options
     * @return array<string, mixed>|WP_Error
     */
    public function import_file(array $connection, string $store_name, string $file_name, array $options = [])
    {
        $payload = GoogleFileSearchRequestBuilder::build_import_file($file_name, $options);
        if (is_wp_error($payload)) {
            return $payload;
        }
        $url = GoogleFileSearchUrlBuilder::build('import_file', $connection, ['store_name' => $store_name]);
        if (is_wp_error($url)) {
            return $url;
        }
        $response = $this->request_json($connection, 'POST', $url, $payload);
        return is_wp_error($response) ? $response : $this->normalize_operation($response);
    }

    /**
     * Upload in-memory content through Google's resumable two-request protocol.
     * Pro file handlers remain under lib/ and may provide a streaming transport;
     * this shared primitive also serves free text/Q&A/content ingestion.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $options
     * @return array<string, mixed>|WP_Error
     */
    public function upload_bytes(
        array $connection,
        string $store_name,
        string $contents,
        string $display_name,
        string $mime_type,
        array $options = []
    ) {
        $content_length = strlen($contents);
        if ($content_length < 1 || $content_length > self::MAX_DOCUMENT_BYTES) {
            return new WP_Error(
                'google_file_search_invalid_upload_size',
                __('Google File Search uploads must contain data and cannot exceed 100 MB.', 'gpt3-ai-content-generator')
            );
        }

        $metadata = GoogleFileSearchRequestBuilder::build_upload_metadata($display_name, $mime_type, $options);
        if (is_wp_error($metadata)) {
            return $metadata;
        }
        $url = GoogleFileSearchUrlBuilder::build('upload_start', $connection, ['store_name' => $store_name]);
        if (is_wp_error($url)) {
            return $url;
        }

        $api_key = self::api_key($connection);
        if (is_wp_error($api_key)) {
            return $api_key;
        }
        $metadata_body = wp_json_encode($metadata);
        if (!is_string($metadata_body)) {
            return self::json_encode_error();
        }
        $start_response = $this->perform_http_request(
            $url,
            [
                'method' => 'POST',
                'timeout' => self::timeout($connection),
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $api_key,
                    'X-Goog-Upload-Protocol' => 'resumable',
                    'X-Goog-Upload-Command' => 'start',
                    'X-Goog-Upload-Header-Content-Length' => (string) $content_length,
                    'X-Goog-Upload-Header-Content-Type' => sanitize_mime_type($mime_type),
                ],
                'body' => $metadata_body,
                'data_format' => 'body',
            ]
        );
        if (is_wp_error($start_response)) {
            return $start_response;
        }
        $start_error = self::http_error_from_response($start_response);
        if ($start_error !== null) {
            return $start_error;
        }

        $upload_url = wp_remote_retrieve_header($start_response, 'x-goog-upload-url');
        $upload_url = is_string($upload_url) ? trim($upload_url) : '';
        if ($upload_url === '' || !GoogleFileSearchUrlBuilder::is_trusted_upload_url($upload_url, $connection)) {
            return new WP_Error(
                'google_file_search_invalid_upload_url',
                __('Google did not return a trusted resumable upload URL.', 'gpt3-ai-content-generator'),
                ['status' => 502, 'status_code' => 502]
            );
        }

        $upload_response = $this->perform_http_request(
            $upload_url,
            [
                'method' => 'POST',
                'timeout' => self::timeout($connection),
                'headers' => [
                    'Content-Length' => (string) $content_length,
                    'Content-Type' => sanitize_mime_type($mime_type),
                    'X-Goog-Upload-Offset' => '0',
                    'X-Goog-Upload-Command' => 'upload, finalize',
                ],
                'body' => $contents,
                'data_format' => 'body',
            ]
        );
        if (is_wp_error($upload_response)) {
            return $upload_response;
        }
        $decoded = $this->decode_http_response($upload_response);
        return is_wp_error($decoded) ? $decoded : $this->normalize_operation($decoded);
    }

    /**
     * Poll one long-running import or upload operation without blocking/sleeping.
     *
     * @param array<string, mixed> $connection
     * @return array<string, mixed>|WP_Error
     */
    public function get_operation(array $connection, string $store_name, string $operation_name)
    {
        $url = GoogleFileSearchUrlBuilder::build(
            'operation',
            $connection,
            ['store_name' => $store_name, 'operation_name' => $operation_name]
        );
        if (is_wp_error($url)) {
            return $url;
        }
        $response = $this->request_json($connection, 'GET', $url);
        return is_wp_error($response) ? $response : $this->normalize_operation($response);
    }

    /**
     * @param array<string, mixed> $raw_store
     * @return array<string, mixed>|WP_Error
     */
    public static function normalize_store(array $raw_store)
    {
        $resource_name = is_string($raw_store['name'] ?? null) ? trim((string) $raw_store['name']) : '';
        $normalized_resource_name = GoogleFileSearchUrlBuilder::normalize_store_name($resource_name);
        if (is_wp_error($normalized_resource_name) || $normalized_resource_name !== $resource_name) {
            return self::malformed_resource_error('store');
        }
        $display_name = is_string($raw_store['displayName'] ?? null) ? trim((string) $raw_store['displayName']) : '';
        return [
            'id' => $resource_name,
            'name' => $display_name !== '' ? $display_name : $resource_name,
            'provider' => 'Google',
            'resource_name' => $resource_name,
            'display_name' => $display_name,
            'create_time' => (string) ($raw_store['createTime'] ?? ''),
            'update_time' => (string) ($raw_store['updateTime'] ?? ''),
            'active_documents_count' => (int) ($raw_store['activeDocumentsCount'] ?? 0),
            'pending_documents_count' => (int) ($raw_store['pendingDocumentsCount'] ?? 0),
            'failed_documents_count' => (int) ($raw_store['failedDocumentsCount'] ?? 0),
            'size_bytes' => (int) ($raw_store['sizeBytes'] ?? 0),
            'embedding_model' => (string) ($raw_store['embeddingModel'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $raw_document
     * @return array<string, mixed>|WP_Error
     */
    public static function normalize_document(array $raw_document)
    {
        $resource_name = is_string($raw_document['name'] ?? null) ? trim((string) $raw_document['name']) : '';
        $normalized_resource_name = GoogleFileSearchUrlBuilder::normalize_document_name($resource_name);
        if (is_wp_error($normalized_resource_name) || $normalized_resource_name !== $resource_name) {
            return self::malformed_resource_error('document');
        }
        $display_name = is_string($raw_document['displayName'] ?? null) ? trim((string) $raw_document['displayName']) : '';
        return [
            'id' => $resource_name,
            'name' => $display_name !== '' ? $display_name : $resource_name,
            'provider' => 'Google',
            'resource_name' => $resource_name,
            'display_name' => $display_name,
            'custom_metadata' => is_array($raw_document['customMetadata'] ?? null)
                ? $raw_document['customMetadata']
                : [],
            'create_time' => (string) ($raw_document['createTime'] ?? ''),
            'update_time' => (string) ($raw_document['updateTime'] ?? ''),
            'state' => sanitize_key((string) ($raw_document['state'] ?? '')),
            'size_bytes' => (int) ($raw_document['sizeBytes'] ?? 0),
            'mime_type' => sanitize_mime_type((string) ($raw_document['mimeType'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>|WP_Error
     */
    private function request_json(array $connection, string $method, string $url, ?array $body = null)
    {
        $api_key = self::api_key($connection);
        if (is_wp_error($api_key)) {
            return $api_key;
        }
        $args = [
            'method' => strtoupper($method),
            'timeout' => self::timeout($connection),
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $api_key,
            ],
        ];
        if ($body !== null) {
            $encoded = wp_json_encode($body);
            if (!is_string($encoded)) {
                return self::json_encode_error();
            }
            $args['body'] = $encoded;
            $args['data_format'] = 'body';
        }

        $response = $this->perform_http_request($url, $args);
        return is_wp_error($response) ? $response : $this->decode_http_response($response);
    }

    /**
     * @return array|WP_Error
     */
    private function perform_http_request(string $url, array $args)
    {
        $response = class_exists(AIPKit_HTTP_Request::class)
            ? AIPKit_HTTP_Request::request($url, $args, true)
            : wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return new WP_Error(
                'google_file_search_http_error',
                sprintf(
                    /* translators: %s: Transport error returned by the WordPress HTTP API. */
                    __('Google File Search request failed: %s', 'gpt3-ai-content-generator'),
                    $response->get_error_message()
                ),
                ['status' => 503, 'status_code' => 503]
            );
        }

        return $response;
    }

    /**
     * @param array $response WordPress HTTP response.
     * @return array<string, mixed>|WP_Error
     */
    private function decode_http_response(array $response)
    {
        $error = self::http_error_from_response($response);
        if ($error !== null) {
            return $error;
        }
        $body = (string) wp_remote_retrieve_body($response);
        if (trim($body) === '') {
            return [];
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error(
                'google_file_search_invalid_json',
                __('Google returned an invalid JSON File Search response.', 'gpt3-ai-content-generator'),
                ['status' => 502, 'status_code' => 502]
            );
        }

        return $decoded;
    }

    /**
     * @param array $response WordPress HTTP response.
     */
    private static function http_error_from_response(array $response): ?WP_Error
    {
        $status_code = (int) wp_remote_retrieve_response_code($response);
        if ($status_code >= 200 && $status_code < 300) {
            return null;
        }

        return GoogleFileSearchErrorParser::to_wp_error(
            (string) wp_remote_retrieve_body($response),
            $status_code > 0 ? $status_code : 500,
            wp_remote_retrieve_header($response, 'retry-after')
        );
    }

    /**
     * @param array<string, mixed> $operation
     * @return array<string, mixed>|WP_Error
     */
    private function normalize_operation(array $operation)
    {
        $name = is_string($operation['name'] ?? null) ? trim((string) $operation['name']) : '';
        if ($name === '') {
            return new WP_Error(
                'google_file_search_malformed_operation',
                __('Google returned a malformed File Search operation.', 'gpt3-ai-content-generator'),
                ['status' => 502, 'status_code' => 502]
            );
        }
        $done = !empty($operation['done']);
        if ($done && isset($operation['error']) && is_array($operation['error'])) {
            $rpc_code = isset($operation['error']['code']) && is_numeric($operation['error']['code'])
                ? (int) $operation['error']['code']
                : 2;
            $status_code = GoogleFileSearchErrorParser::http_status_from_rpc_code($rpc_code);
            $error = GoogleFileSearchErrorParser::to_wp_error(['error' => $operation['error']], $status_code);
            $error_data = $error->get_error_data();
            $error->add_data(array_merge(
                is_array($error_data) ? $error_data : [],
                ['operation_failed' => true]
            ));
            return $error;
        }

        return [
            'name' => $name,
            'done' => $done,
            'metadata' => is_array($operation['metadata'] ?? null) ? $operation['metadata'] : [],
            'response' => is_array($operation['response'] ?? null) ? $operation['response'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $connection
     * @return string|WP_Error
     */
    private static function api_key(array $connection)
    {
        $api_key = isset($connection['api_key']) && is_string($connection['api_key'])
            ? trim($connection['api_key'])
            : '';
        if ($api_key === '') {
            return new WP_Error(
                'google_file_search_missing_api_key',
                __('A Google API key is required for File Search.', 'gpt3-ai-content-generator'),
                ['status' => 400, 'status_code' => 400]
            );
        }

        return $api_key;
    }

    /**
     * @param array<string, mixed> $connection
     */
    private static function timeout(array $connection): int
    {
        return isset($connection['timeout']) && is_numeric($connection['timeout'])
            ? max(1, min(300, (int) $connection['timeout']))
            : 120;
    }

    private static function json_encode_error(): WP_Error
    {
        return new WP_Error(
            'google_file_search_json_encode_error',
            __('Failed to encode the Google File Search request.', 'gpt3-ai-content-generator'),
            ['status' => 500, 'status_code' => 500]
        );
    }

    private static function malformed_resource_error(string $resource_type): WP_Error
    {
        return new WP_Error(
            'google_file_search_malformed_' . sanitize_key($resource_type),
            sprintf(
                /* translators: %s: File Search resource type, such as store or document list. */
                __('Google returned a malformed File Search %s.', 'gpt3-ai-content-generator'),
                $resource_type
            ),
            ['status' => 502, 'status_code' => 502]
        );
    }
}
