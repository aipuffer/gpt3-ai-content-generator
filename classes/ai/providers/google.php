<?php

namespace WPAICG\Core\Providers\Google;

use WPAICG\Core\Providers\Google\Interactions\GoogleInteractionsErrorParser;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/contracts.php';
require_once __DIR__ . '/google-interactions.php';
require_once __DIR__ . '/google-file-search.php';

class GoogleUrlBuilder {

    /**
     * @return string|\WP_Error
     */
    public static function build(string $operation, array $params) {
        return Methods\build_logic_for_url_builder($operation, $params);
    }
}

class GooglePayloadFormatter {
    public static function format_embeddings($input, array $options): array {
        return Methods\format_embeddings_logic_for_payload_formatter($input, $options);
    }
}

class GoogleResponseParser {
    public static function parse_error($response_body, int $status_code): string {
        $parsed = GoogleInteractionsErrorParser::parse($response_body, $status_code);
        return (string) $parsed['message'];
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public static function parse_embeddings(array $decoded_response) {
        return Methods\parse_embeddings_logic_for_response_parser($decoded_response);
    }
}

namespace WPAICG\Core\Providers;

use WPAICG\AIPKit_Providers;
use WPAICG\Core\Providers\Google\Interactions\GoogleInteractionsClient;
use WPAICG\Core\Providers\Google\Interactions\GoogleInteractionsResponseParser;
use WPAICG\Core\Providers\Google\Interactions\GoogleInteractionsStreamParser;
use WPAICG\Core\Providers\Google\Interactions\GoogleInteractionsTextAdapter;
use WPAICG\Core\Providers\Google\Interactions\GoogleInteractionsUrlBuilder;

class GoogleProviderStrategy extends BaseProviderStrategy {

    /**
     * @return string|\WP_Error
     */
    public function build_api_url(string $operation, array $params) {
        if ($operation === 'chat' || $operation === 'stream') {
            return GoogleInteractionsUrlBuilder::build($params);
        }
        return Google\Methods\build_api_url_logic($this, $operation, $params);
    }

    public function get_api_headers(string $api_key, string $operation): array {
        if ($operation === 'chat' || $operation === 'stream') {
            return GoogleInteractionsClient::headers($api_key, $operation === 'stream');
        }
        return Google\Methods\get_api_headers_logic($this, $api_key, $operation);
    }

    public function format_chat_payload(string $user_message, string $instructions, array $history, array $ai_params, string $model) {
        $model = AIPKit_Providers::normalize_google_text_model($model);
        return GoogleInteractionsTextAdapter::build($user_message, $instructions, $history, $ai_params, $model);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function parse_chat_response(array $decoded_response, array $request_data) {
        return GoogleInteractionsResponseParser::parse_text($decoded_response);
    }

    public function parse_error_response($response_body, int $status_code): string {
        return Google\Methods\parse_error_response_logic($this, $response_body, $status_code);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function get_models(array $api_params) {
        return Google\Methods\get_models_logic($this, $api_params);
    }

    public function build_sse_payload(array $messages, $system_instruction, array $ai_params, string $model) {
        $ai_params['stream'] = true;
        $model = AIPKit_Providers::normalize_google_text_model($model);
        return GoogleInteractionsTextAdapter::build('', (string) $system_instruction, $messages, $ai_params, $model);
    }

    public function parse_sse_chunk(string $sse_chunk, string &$current_buffer): array {
        return GoogleInteractionsStreamParser::parse($sse_chunk, $current_buffer);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function generate_embeddings($input, array $api_params, array $options = []) {
        return Google\Methods\generate_embeddings_logic($this, $input, $api_params, $options);
    }

    public function format_google_model_list_public(array $raw_models): array {
        return Google\Methods\format_google_model_list_logic($this, $raw_models);
    }
}

namespace WPAICG\Core\Providers\Google\Methods;

use WP_Error;
use WPAICG\Core\Providers\GoogleProviderStrategy;
use WPAICG\Core\Providers\Google\GooglePayloadFormatter;
use WPAICG\Core\Providers\Google\GoogleResponseParser;
use WPAICG\Core\Providers\Google\GoogleUrlBuilder;

/**
 * Build a remaining specialized Gemini API URL (models or embeddings).
 * Generative text uses GoogleInteractionsUrlBuilder instead.
 *
 * @return string|WP_Error
 */
function build_api_url_logic(GoogleProviderStrategy $strategy_instance, string $operation, array $params)
{
    return GoogleUrlBuilder::build($operation, $params);
}

/**
 * @return string|WP_Error
 */
function build_logic_for_url_builder(string $operation, array $params)
{
    $base_url = isset($params['base_url']) ? rtrim((string) $params['base_url'], '/') : '';
    $api_version = isset($params['api_version']) ? trim((string) $params['api_version'], '/') : '';
    $model_id = isset($params['model']) ? (string) $params['model'] : '';

    if ($base_url === '') {
        return new WP_Error('missing_base_url_Google_logic', __('Google Base URL is required.', 'gpt3-ai-content-generator'));
    }
    if ($api_version === '') {
        return new WP_Error('missing_api_version_Google_logic', __('Google API Version is required.', 'gpt3-ai-content-generator'));
    }

    $paths = [
        'models' => '/models',
        'embedContent' => '/models/{model}:embedContent',
        'batchEmbedContents' => '/models/{model}:batchEmbedContents',
    ];
    if (!isset($paths[$operation])) {
        return new WP_Error(
            'unsupported_operation_Google_logic',
            sprintf(
                /* translators: %s: Google API operation name. */
                __('Operation "%s" is not supported by the specialized Google API adapter.', 'gpt3-ai-content-generator'),
                $operation
            )
        );
    }

    $full_path = '/' . $api_version . $paths[$operation];
    if ($operation !== 'models') {
        if ($model_id === '') {
            return new WP_Error('missing_google_model_logic', __('A Google embedding model is required.', 'gpt3-ai-content-generator'));
        }
        if (strpos($model_id, 'models/') === 0) {
            $model_id = (string) substr($model_id, 7);
        }
        $full_path = str_replace('{model}', rawurlencode($model_id), $full_path);
    }

    $url = $base_url . $full_path;
    if ($operation === 'models') {
        if (!empty($params['pageSize'])) {
            $url = add_query_arg('pageSize', absint($params['pageSize']), $url);
        }
        if (!empty($params['pageToken'])) {
            $url = add_query_arg('pageToken', (string) $params['pageToken'], $url);
        }
    }

    return $url;
}

function format_embeddings_logic_for_payload_formatter($input, array $options): array
{
    $texts_to_embed = [];
    if (is_array($input)) {
        foreach ($input as $item) {
            if (is_scalar($item) && trim((string) $item) !== '') {
                $texts_to_embed[] = trim((string) $item);
            }
        }
    } elseif (is_scalar($input) && trim((string) $input) !== '') {
        $texts_to_embed[] = trim((string) $input);
    }
    if (empty($texts_to_embed)) {
        $texts_to_embed[] = '';
    }

    $model_for_body = isset($options['model']) ? (string) $options['model'] : '';
    if ($model_for_body !== '' && strpos($model_for_body, 'models/') !== 0) {
        $model_for_body = 'models/' . $model_for_body;
    }

    $request_options = [];
    if (isset($options['taskType']) && is_string($options['taskType'])) {
        $request_options['taskType'] = $options['taskType'];
    }
    if (isset($options['title']) && is_string($options['title'])) {
        $request_options['title'] = $options['title'];
    }
    if (isset($options['outputDimensionality']) && is_int($options['outputDimensionality'])) {
        $request_options['outputDimensionality'] = $options['outputDimensionality'];
    }

    if (count($texts_to_embed) > 1) {
        $requests = [];
        foreach ($texts_to_embed as $text) {
            $requests[] = array_merge([
                'model' => $model_for_body,
                'content' => ['parts' => [['text' => $text]]],
            ], $request_options);
        }
        return ['requests' => $requests];
    }

    return array_merge([
        'model' => $model_for_body,
        'content' => ['parts' => [['text' => $texts_to_embed[0]]]],
    ], $request_options);
}

function format_google_model_list_logic(GoogleProviderStrategy $strategy_instance, array $raw_models): array
{
    $formatted = [];
    foreach ($raw_models as $model) {
        if (!is_array($model) || empty($model['name'])) {
            continue;
        }
        $model_id = (string) $model['name'];
        if (strpos($model_id, 'models/') === 0) {
            $model_id = (string) substr($model_id, 7);
        }
        $formatted[] = [
            'id' => $model_id,
            'name' => $model['displayName'] ?? $model_id,
            'version' => $model['version'] ?? '',
            'supportedGenerationMethods' => $model['supportedGenerationMethods'] ?? [],
        ];
    }
    return $formatted;
}

/**
 * @return mixed[]|WP_Error
 */
function generate_embeddings_logic(
    GoogleProviderStrategy $strategy_instance,
    $input,
    array $api_params,
    array $options = []
) {
    $model_id = $options['model'] ?? '';
    if ($model_id === '') {
        return new WP_Error('missing_google_embedding_model_logic', __('Google embedding model ID is required.', 'gpt3-ai-content-generator'));
    }

    $payload = GooglePayloadFormatter::format_embeddings($input, $options);
    $operation = isset($payload['requests']) ? 'batchEmbedContents' : 'embedContent';
    $url = GoogleUrlBuilder::build($operation, array_merge($api_params, ['model' => $model_id]));
    if (is_wp_error($url)) {
        return $url;
    }

    $headers = $strategy_instance->get_api_headers((string) ($api_params['api_key'] ?? ''), $operation);
    $request_options = $strategy_instance->get_request_options($operation);
    $response = wp_remote_post($url, array_merge($request_options, [
        'headers' => $headers,
        'body' => wp_json_encode($payload),
    ]));
    if (is_wp_error($response)) {
        return new WP_Error('google_embedding_http_error_logic', __('HTTP error during embedding generation.', 'gpt3-ai-content-generator'));
    }

    $status_code = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    $decoded_response = $strategy_instance->decode_json($body, 'Google Embeddings');
    if ($status_code !== 200 || is_wp_error($decoded_response)) {
        $error_message = is_wp_error($decoded_response)
            ? $decoded_response->get_error_message()
            : GoogleResponseParser::parse_error($body, $status_code);
        return new WP_Error(
            'google_embedding_api_error_logic',
            sprintf(
                /* translators: 1: HTTP status code, 2: Google API error. */
                __('Google Embeddings API Error (%1$d): %2$s', 'gpt3-ai-content-generator'),
                $status_code,
                esc_html($error_message)
            ),
            $strategy_instance->build_http_error_data_with_retry_after($response, $status_code)
        );
    }

    return GoogleResponseParser::parse_embeddings($decoded_response);
}

function get_api_headers_logic(GoogleProviderStrategy $strategy_instance, string $api_key, string $operation): array
{
    return [
        'Content-Type' => 'application/json',
        'x-goog-api-key' => $api_key,
    ];
}

/**
 * @return mixed[]|WP_Error
 */
function get_models_logic(GoogleProviderStrategy $strategy_instance, array $api_params)
{
    $all_results = [];
    $next_page_token = null;

    do {
        $page_params = array_merge($api_params, ['pageSize' => 100]);
        if ($next_page_token !== null) {
            $page_params['pageToken'] = $next_page_token;
        }
        $url = GoogleUrlBuilder::build('models', $page_params);
        if (is_wp_error($url)) {
            return $url;
        }

        $options = $strategy_instance->get_request_options('models');
        $options['method'] = 'GET';
        $response = wp_remote_get($url, array_merge($options, [
            'headers' => $strategy_instance->get_api_headers((string) ($api_params['api_key'] ?? ''), 'models'),
        ]));
        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($status_code !== 200) {
            return new WP_Error(
                'api_error_google_models_logic',
                sprintf('Google API Error (HTTP %d): %s', $status_code, esc_html(GoogleResponseParser::parse_error($body, $status_code)))
            );
        }

        $decoded = $strategy_instance->decode_json($body, 'Google Models');
        if (is_wp_error($decoded)) {
            return $decoded;
        }
        $all_results = array_merge(
            $all_results,
            format_google_model_list_logic($strategy_instance, $decoded['models'] ?? [])
        );
        $next_page_token = !empty($decoded['nextPageToken']) ? (string) $decoded['nextPageToken'] : null;
    } while ($next_page_token !== null);

    usort($all_results, static function (array $left, array $right): int {
        return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
    });
    return $all_results;
}

/**
 * @return mixed[]|WP_Error
 */
function parse_embeddings_logic_for_response_parser(array $decoded_response)
{
    $embeddings = [];
    if (isset($decoded_response['embedding']['values']) && is_array($decoded_response['embedding']['values'])) {
        $embeddings[] = $decoded_response['embedding']['values'];
    } elseif (isset($decoded_response['embeddings']) && is_array($decoded_response['embeddings'])) {
        foreach ($decoded_response['embeddings'] as $embedding_item) {
            if (isset($embedding_item['values']) && is_array($embedding_item['values'])) {
                $embeddings[] = $embedding_item['values'];
            } elseif (isset($embedding_item['embedding']) && is_array($embedding_item['embedding'])) {
                $embeddings[] = $embedding_item['embedding'];
            }
        }
    }

    if (empty($embeddings)) {
        return new WP_Error('google_embedding_no_data_logic', __('No embedding data found in Google response.', 'gpt3-ai-content-generator'));
    }
    return ['embeddings' => $embeddings, 'usage' => null];
}

function parse_error_response_logic(
    GoogleProviderStrategy $strategy_instance,
    $response_body,
    int $status_code
): string {
    return GoogleResponseParser::parse_error($response_body, $status_code);
}
