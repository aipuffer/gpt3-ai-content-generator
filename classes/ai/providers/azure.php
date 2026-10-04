<?php
namespace WPAICG\Core\Providers\Azure\Methods;

use WPAICG\Core\Providers\AzureProviderStrategy;
use WPAICG\Core\Providers\Azure\AzureUrlBuilder; // For constants if any, or direct call
use WP_Error;
use WPAICG\Core\Providers\Azure\AzurePayloadFormatter; // For direct call
use WPAICG\Core\Providers\Azure\AzureResponseParser;
use function WPAICG\Core\Providers\Shared\extract_sse_event_blocks;
use function WPAICG\Core\Providers\Shared\decode_data_only_sse_event_block;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared formatting logic, previously a private static method in AzurePayloadFormatter.
 *
 * @param string $instructions System instructions.
 * @param array  $history Conversation history.
 * @param array  $ai_params AI parameters (temperature, max_tokens, etc.).
 * @return array The formatted payload base.
 */
function _shared_format_logic(string $instructions, array $history, array $ai_params): array {
    $messages = [];
    if (!empty($instructions)) {
        $messages[] = ['role' => 'system', 'content' => $instructions];
    }
    foreach ($history as $msg) {
        $role = ($msg['role'] === 'bot') ? 'assistant' : $msg['role']; // Map 'bot' to 'assistant'
        $content = isset($msg['content']) ? trim($msg['content']) : '';
        if ($content !== '' && in_array($role, ['system', 'user', 'assistant'], true)) {
            if ($role === 'system' && !empty($instructions)) continue; // Avoid duplicate system message
            $messages[] = ['role' => $role, 'content' => $content];
        }
    }

    $body_data = ['messages' => $messages];

    // Map AIPKit standard AI params to Chat Completions API params
    $param_map = [
        'temperature' => 'temperature',
        'max_completion_tokens' => 'max_completion_tokens', // API uses 'max_tokens'
        'top_p' => 'top_p',
        'stop' => 'stop',
        // Azure specific params can be added here if they differ from OpenAI Chat Completions
    ];

    foreach ($param_map as $aipkit_key => $api_key) {
        if (isset($ai_params[$aipkit_key])) {
            $value = $ai_params[$aipkit_key];
            if (in_array($api_key, ['temperature', 'top_p'])) {
                $body_data[$api_key] = floatval($value);
            } elseif ($api_key === 'max_tokens') {
                $body_data[$api_key] = absint($value);
            } elseif ($api_key === 'stop' && !empty($value)) {
                $body_data[$api_key] = is_string($value) ? [$value] : (is_array($value) ? $value : null);
                if (empty($body_data[$api_key])) unset($body_data[$api_key]); // Remove if value results in empty
            }
        }
    }
    // unset top_p
    unset($body_data['top_p']);
    // unset temperature
    unset($body_data['temperature']);

    return $body_data;
}

/**
 * Logic for the build static method of AzureUrlBuilder.
 *
 * @param string $operation ('chat', 'stream', 'deployments', 'models', 'embeddings')
 * @param array  $params Required parameters (azure_endpoint, api_version_authoring, api_version_inference, deployment)
 * @return string|WP_Error The full URL or WP_Error.
 */
function build_logic_for_url_builder(string $operation, array $params) {
    $azure_endpoint = !empty($params['azure_endpoint']) ? rtrim($params['azure_endpoint'], '/') : '';
    $deployment_name = !empty($params['deployment']) ? $params['deployment'] : '';

    if (empty($azure_endpoint)) return new WP_Error('missing_azure_endpoint_logic', __('Azure endpoint is required.', 'gpt3-ai-content-generator'));

    $api_version = '';
    if ($operation === 'deployments' || $operation === 'models') {
        $api_version = $params['azure_authoring_version'] ?? '2023-03-15-preview';
    } else {
        $api_version = $params['azure_inference_version'] ?? '2024-02-01'; // Default to inference for chat/stream/embeddings
    }

    if (empty($api_version)) return new WP_Error('missing_azure_api_version_logic', __('Azure API Version is required.', 'gpt3-ai-content-generator'));

    $paths = [
        'chat'        => '/chat/completions',
        'deployments' => '/openai/deployments',
        'models'      => '/openai/models',
        'embeddings'  => '/embeddings',
    ];
    $path_key = ($operation === 'stream') ? 'chat' : $operation;
    $path_segment = $paths[$path_key] ?? null;

    if ($path_segment === null) {
        /* translators: %s: The name of the API operation (e.g., 'chat', 'embeddings'). */
        return new WP_Error('unsupported_operation_Azure_logic', sprintf(__('Operation "%s" not supported for Azure.', 'gpt3-ai-content-generator'), $operation));
    }

    $query_param = '?api-version=' . urlencode($api_version);

    if ($operation === 'deployments' || $operation === 'models') {
        return $azure_endpoint . $path_segment . $query_param;
    } elseif ($operation === 'chat' || $operation === 'stream' || $operation === 'embeddings') {
        if (empty($deployment_name)) return new WP_Error('missing_azure_deployment_logic', __('Azure deployment name is required for this operation.', 'gpt3-ai-content-generator'));
        return $azure_endpoint . '/openai/deployments/' . urlencode($deployment_name) . $path_segment . $query_param;
    } else {
        /* translators: %s: The name of the API operation. */
        return new WP_Error('unhandled_azure_operation_logic', sprintf(__('Unhandled Azure operation path building for: %s', 'gpt3-ai-content-generator'), $operation));
    }
}

/**
 * Logic for the format_chat_payload method of AzureProviderStrategy.
 *
 * @param AzureProviderStrategy $strategyInstance The instance of the strategy class.
 * @param string $user_message The user's message (already included in history for Azure).
 * @param string $instructions System instructions.
 * @param array  $history Conversation history.
 * @param array  $ai_params AI parameters (temperature, max_tokens, etc.).
 * @param string $model The target model/deployment ID (unused here as payload formatter handles it).
 * @return array The formatted request body data.
 */
function format_chat_payload_logic(
    AzureProviderStrategy $strategyInstance,
    string $user_message,
    string $instructions,
    array $history,
    array $ai_params,
    string $model
): array {
    // The $user_message for Azure is typically part of the $history for the formatter
    // The original strategy passed $user_message, but the formatter's format_chat uses instructions and history
    // Let's adjust to ensure the latest user message is part of history if not already.
    $final_history = $history;
    if(!empty($user_message)){ // if $user_message is not empty and meant to be the last message
        $last_msg = end($final_history);
        if(!$last_msg || $last_msg['role'] !== 'user' || $last_msg['content'] !== $user_message){
             $final_history[] = ['role' => 'user', 'content' => $user_message];
        }
    }
    return \WPAICG\Core\Providers\Azure\AzurePayloadFormatter::format_chat($instructions, $final_history, $ai_params, $model);
}

/**
 * Logic for the format_chat static method of AzurePayloadFormatter.
 *
 * @param string $instructions System instructions.
 * @param array  $history Conversation history.
 * @param array  $ai_params AI parameters.
 * @param string $model Model/deployment ID (not used directly by Azure format logic, but kept for signature).
 * @return array The formatted payload.
 */
function format_chat_logic_for_payload_formatter(string $instructions, array $history, array $ai_params, string $model): array {
    return _shared_format_logic($instructions, $history, $ai_params);
}

/**
 * Logic for the format_embeddings static method of AzurePayloadFormatter.
 *
 * @param string|array $input The input text or array of texts.
 * @param array  $options Embedding options (dimensions, user). Model is in URL.
 * @return array The formatted request body data.
 */
function format_embeddings_logic_for_payload_formatter($input, array $options): array {
    $payload = [
        'input' => $input,
    ];
    if (isset($options['dimensions']) && is_int($options['dimensions']) && $options['dimensions'] > 0) {
        $payload['dimensions'] = $options['dimensions'];
    }
    if (isset($options['user']) && is_string($options['user'])) {
        $payload['user'] = $options['user'];
    }
    return $payload;
}

/**
 * Logic for the format_sse static method of AzurePayloadFormatter.
 *
 * @param array  $messages Formatted messages array (user/assistant).
 * @param string $system_instruction System instructions.
 * @param array  $ai_params AI parameters.
 * @param string $model Model/deployment ID.
 * @param bool   $request_usage Whether to request usage (Azure specific stream option).
 * @return array The formatted SSE payload.
 */
function format_sse_logic_for_payload_formatter(array $messages, string $system_instruction, array $ai_params, string $model, bool $request_usage = true): array {
    // Convert message roles for history argument for _shared_format_logic
    $history_for_shared_format = array_map(function($msg) {
        if ($msg['role'] === 'bot') $msg['role'] = 'assistant'; // Ensure role consistency for shared formatter
        return $msg;
    }, $messages);

    $payload = _shared_format_logic($system_instruction, $history_for_shared_format, $ai_params);
    $payload['stream'] = true;
    if ($request_usage) {
        // Azure specific stream option for usage
        $payload['stream_options'] = ['include_usage' => true];
    }
    return $payload;
}

/**
 * Logic for the generate_embeddings method of AzureProviderStrategy.
 *
 * @param AzureProviderStrategy $strategyInstance The instance of the strategy class.
 * @param string|array $input The input text or array of texts.
 * @param array $api_params Provider-specific API connection parameters.
 * @param array $options Embedding options (model - deployment ID, dimensions, user).
 * @return array|WP_Error An array of embedding vectors or WP_Error on failure.
 */
function generate_embeddings_logic(
    AzureProviderStrategy $strategyInstance,
    $input,
    array $api_params,
    array $options = []
) {
    $deployment_id = $options['model'] ?? ''; // For Azure, 'model' in options is the deployment ID
    if (empty($deployment_id)) {
        return new WP_Error('missing_azure_embedding_deployment_logic', __('Azure embedding deployment ID (model) is required.', 'gpt3-ai-content-generator'));
    }

    // Add deployment_id to $api_params for the URL builder
    $url_params = array_merge($api_params, ['deployment' => $deployment_id]);
    $url = AzureUrlBuilder::build('embeddings', $url_params);
    if (is_wp_error($url)) {
        return $url;
    }

    $headers = $strategyInstance->get_api_headers($api_params['api_key'], 'embeddings');
    $request_options = $strategyInstance->get_request_options('embeddings');
    $payload = AzurePayloadFormatter::format_embeddings($input, $options);
    $request_body_json = wp_json_encode($payload);

    $response = wp_remote_post($url, array_merge($request_options, ['headers' => $headers, 'body' => $request_body_json]));

    if (is_wp_error($response)) {
        return new WP_Error('azure_embedding_http_error_logic', __('HTTP error during Azure embedding generation.', 'gpt3-ai-content-generator'));
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    $decoded_response = $strategyInstance->decode_json_public($body, 'Azure Embeddings'); // Call public wrapper

    if ($status_code !== 200 || is_wp_error($decoded_response)) {
        $error_msg = is_wp_error($decoded_response)
                    ? $decoded_response->get_error_message()
                    : AzureResponseParser::parse_error($body, $status_code); // Call static method
        $error_data = $strategyInstance->build_http_error_data_with_retry_after($response, $status_code);
        /* translators: %1$d: HTTP status code, %2$s: API error message. */
        return new WP_Error('azure_embedding_api_error_logic', sprintf(__('Azure Embeddings API Error (%1$d): %2$s', 'gpt3-ai-content-generator'), $status_code, esc_html($error_msg)), $error_data);
    }

    return AzureResponseParser::parse_embeddings($decoded_response); // Call static method
}

/**
 * Logic for the get_api_headers method of AzureProviderStrategy.
 *
 * @param AzureProviderStrategy $strategyInstance The instance of the strategy class.
 * @param string $api_key The API key for the provider.
 * @param string $operation The specific operation being performed.
 * @return array Key-value array of headers.
 */
function get_api_headers_logic(AzureProviderStrategy $strategyInstance, string $api_key, string $operation): array {
    $headers = [
        'Content-Type' => 'application/json',
        'api-key' => $api_key,
    ];
    if ($operation === 'stream') {
        $headers['Accept'] = 'text/event-stream';
        $headers['Cache-Control'] = 'no-cache';
    }
    return $headers;
}

/**
 * Logic for the get_models method of AzureProviderStrategy.
 * Fetches Azure OpenAI deployments.
 *
 * @param AzureProviderStrategy $strategyInstance The instance of the strategy class.
 * @param array $api_params Connection parameters (api_key, azure_endpoint, azure_authoring_version).
 * @return array|WP_Error Formatted list [['id' => ..., 'name' => ...]] or WP_Error.
 */
function get_models_logic(AzureProviderStrategy $strategyInstance, array $api_params) {
    $url = $strategyInstance->build_api_url('deployments', $api_params);
    if (is_wp_error($url)) {
        return $url;
    }

    $headers = $strategyInstance->get_api_headers($api_params['api_key'], 'deployments');
    $options = $strategyInstance->get_request_options('models'); // 'models' operation type for general request options
    $options['method'] = 'GET'; // Override method

    $response = wp_remote_get($url, array_merge($options, ['headers' => $headers]));
    if (is_wp_error($response)) {
        return $response;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($status_code !== 200) {
        $error_msg = $strategyInstance->parse_error_response($body, $status_code);
        return new WP_Error('api_error_azure_deployments_logic', sprintf('Azure API Error (HTTP %d): %s', $status_code, esc_html($error_msg)));
    }

    $decoded = $strategyInstance->decode_json_public($body, 'Azure Deployments'); // Call public wrapper
    if (is_wp_error($decoded)) {
        return $decoded;
    }

    $raw_deployments = $decoded['data'] ?? [];
    $formatted = [];
    foreach ($raw_deployments as $dep) {
        $dep_id   = $dep['id'] ?? null; // Deployment name is the 'id' for Azure
        $model_name = $dep['model'] ?? null; // Underlying model name
        $status = $dep['status'] ?? '';
        if (!empty($dep_id) && $status === 'succeeded') { // Only include succeeded deployments
            $display_name = $dep_id;
            if ($model_name && $model_name !== $dep_id) {
                $display_name .= " ({$model_name})";
            }
            $formatted[] = [
                'id'      => $dep_id,
                'name'    => $display_name,
                'status'  => $status,
                'model'   => $model_name // Keep original model name for filtering
            ];
        }
    }
    usort($formatted, fn($a, $b) => strcmp($a['id'] ?? '', $b['id'] ?? ''));
    return $formatted;
}

/**
 * Maps a normalized Azure SSE event into an internal typed event.
 *
 * @param array<string, mixed> $decoded_event
 * @return array<string, mixed>
 */
function map_sse_event_logic_for_response_parser(array $decoded_event): array {
    $kind = isset($decoded_event['kind']) && is_string($decoded_event['kind']) ? $decoded_event['kind'] : 'payload';
    $payload = isset($decoded_event['payload']) && is_array($decoded_event['payload']) ? $decoded_event['payload'] : [];

    if ($kind === 'done') {
        return ['kind' => 'done'];
    }

    if (isset($payload['error'])) {
        return [
            'kind' => 'error',
            'message' => parse_error_logic_for_response_parser($payload, 500),
        ];
    }

    $delta_text = null;
    if (isset($payload['choices'][0]['delta']['content'])) {
        $delta_text = (string) $payload['choices'][0]['delta']['content'];
        if ($delta_text === '') {
            $delta_text = null;
        }
    }

    $usage = extract_sse_usage_logic_for_response_parser($payload);
    $warning_text = extract_sse_warning_logic_for_response_parser($payload);

    if ($delta_text === null && $usage === null && $warning_text === null) {
        return ['kind' => 'skip'];
    }

    return [
        'kind' => 'chunk',
        'delta_text' => $delta_text,
        'usage' => $usage,
        'warning_text' => $warning_text,
    ];
}

/**
 * Extracts token usage from an Azure SSE payload.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>|null
 */
function extract_sse_usage_logic_for_response_parser(array $payload): ?array {
    if (!isset($payload['usage']) || !is_array($payload['usage'])) {
        return null;
    }

    $usage = $payload['usage'];

    return [
        'input_tokens' => $usage['prompt_tokens'] ?? 0,
        'output_tokens' => $usage['completion_tokens'] ?? 0,
        'total_tokens' => $usage['total_tokens'] ?? 0,
        'provider_raw' => $usage,
    ];
}

/**
 * Extracts user-visible warning text from an Azure SSE payload when the stream is explicitly blocked.
 *
 * @param array<string, mixed> $payload
 * @return string|null
 */
function extract_sse_warning_logic_for_response_parser(array $payload): ?string {
    $choice = $payload['choices'][0] ?? null;
    if (!is_array($choice)) {
        return null;
    }

    $finish_reason = $choice['finish_reason'] ?? null;
    if ($finish_reason === 'content_filter') {
        return sprintf(' (%s)', __('Warning: Content Filtered', 'gpt3-ai-content-generator'));
    }

    return null;
}

/**
 * Logic for the parse_chat static method of AzureResponseParser.
 *
 * @param array $decoded_response The decoded JSON response.
 * @return array|WP_Error ['content' => string, 'usage' => array|null] or WP_Error.
 */
function parse_chat_logic_for_response_parser(array $decoded_response) {
    $content = null;
    $usage = null;

    if (!empty($decoded_response['choices'][0]['message']['content'])) {
        $content = trim($decoded_response['choices'][0]['message']['content']);
    } elseif (!empty($decoded_response['choices'][0]['delta']['content'])) {
        $content = trim($decoded_response['choices'][0]['delta']['content']);
    } elseif (!empty($decoded_response['choices'][0]['text'])) {
        $content = trim($decoded_response['choices'][0]['text']);
    }

    if ($content === null) {
        if (isset($decoded_response['choices'][0]['finish_reason']) && $decoded_response['choices'][0]['finish_reason'] === 'content_filter') {
            return new WP_Error('content_filter_logic', __('Response blocked due to content filtering.', 'gpt3-ai-content-generator'));
        }
        return new WP_Error('invalid_response_structure_azure_logic', __('Unexpected response structure from Azure API.', 'gpt3-ai-content-generator'));
    }

    if (isset($decoded_response['usage']) && is_array($decoded_response['usage'])) {
        $usage = [
            'input_tokens'  => $decoded_response['usage']['prompt_tokens'] ?? 0,
            'output_tokens' => $decoded_response['usage']['completion_tokens'] ?? 0,
            'total_tokens'  => $decoded_response['usage']['total_tokens'] ?? 0,
            'provider_raw' => $decoded_response['usage'],
        ];
    }

    return ['content' => $content, 'usage' => $usage];
}

/**
 * Logic for the parse_embeddings static method of AzureResponseParser.
 *
 * @param array $decoded_response The decoded JSON response body.
 * @return array|WP_Error ['embeddings' => array, 'usage' => array] or WP_Error.
 */
function parse_embeddings_logic_for_response_parser(array $decoded_response) {
    $embeddings = [];
    if (isset($decoded_response['data']) && is_array($decoded_response['data'])) {
        foreach ($decoded_response['data'] as $item) {
            if (isset($item['embedding']) && is_array($item['embedding'])) {
                $embeddings[] = $item['embedding'];
            }
        }
    }

    if (empty($embeddings)) {
        if (isset($decoded_response['error']['code']) && $decoded_response['error']['code'] === 'ContentFilter') {
            return new WP_Error('azure_embedding_content_filter_logic', __('Input blocked by Azure content filter.', 'gpt3-ai-content-generator'));
        }
        return new WP_Error('azure_embedding_no_data_logic', __('No embedding data found in Azure response.', 'gpt3-ai-content-generator'));
    }

    $usage = null;
    if (isset($decoded_response['usage']) && is_array($decoded_response['usage'])) {
        $usage = [
            'input_tokens'  => $decoded_response['usage']['prompt_tokens'] ?? 0,
            'total_tokens'  => $decoded_response['usage']['total_tokens'] ?? 0,
            'provider_raw' => $decoded_response['usage'],
        ];
    }

    return ['embeddings' => $embeddings, 'usage' => $usage];
}

/**
 * Logic for the parse_error static method of AzureResponseParser.
 *
 * @param mixed $response_body The raw or decoded error response body.
 * @param int $status_code The HTTP status code.
 * @return string A user-friendly error message.
 */
function parse_error_logic_for_response_parser($response_body, int $status_code): string {
    $message = __('An unknown API error occurred.', 'gpt3-ai-content-generator');
    $decoded = is_string($response_body) ? json_decode($response_body, true) : $response_body;

    if (is_array($decoded)) {
        if (!empty($decoded['error']['message'])) {
            $message = $decoded['error']['message'];
            if (!empty($decoded['error']['code'])) { $message .= ' (Code: ' . $decoded['error']['code'] . ')';}
            if (!empty($decoded['error']['innererror']['code'])) { $message .= ' InnerCode: ' . $decoded['error']['innererror']['code']; }
        } elseif (!empty($decoded['message'])) {
            $message = $decoded['message'];
        }
    } elseif (is_string($response_body)) {
         $message = substr($response_body, 0, 200);
    }

    return trim($message);
}

require_once __DIR__ . '/chat-completions.php';

/**
 * Logic for the parse_sse_chunk static method of AzureResponseParser.
 *
 * @param string $sse_chunk The raw chunk received.
 * @param string &$current_buffer Reference to the incomplete buffer.
 * @return array Result containing delta, usage, flags.
 */
function parse_sse_chunk_logic_for_response_parser(string $sse_chunk, string &$current_buffer): array {
    $current_buffer .= $sse_chunk;
    $result = ['delta' => null, 'usage' => null, 'is_error' => false, 'is_warning' => false, 'is_done' => false];

    foreach (extract_sse_event_blocks($current_buffer) as $event_block) {
        $decoded_event = decode_data_only_sse_event_block($event_block);
        if ($decoded_event === null) {
            continue;
        }

        $mapped_event = map_sse_event_logic_for_response_parser($decoded_event);
        if (reduce_sse_event_logic_for_response_parser($mapped_event, $result)) {
            return $result;
        }
    }

    return $result;
}

/**
 * Applies an internal typed Azure SSE event to the flattened parse result expected by the stream processor.
 *
 * @param array<string, mixed> $mapped_event
 * @param array<string, mixed> $result
 * @return bool True when parsing should stop immediately.
 */
function reduce_sse_event_logic_for_response_parser(array $mapped_event, array &$result): bool {
    $kind = isset($mapped_event['kind']) && is_string($mapped_event['kind']) ? $mapped_event['kind'] : 'skip';

    switch ($kind) {
        case 'chunk':
            if (isset($mapped_event['usage']) && is_array($mapped_event['usage'])) {
                $result['usage'] = $mapped_event['usage'];
            }

            if (!empty($mapped_event['delta_text']) && is_string($mapped_event['delta_text'])) {
                if ($result['delta'] === null) {
                    $result['delta'] = '';
                }
                $result['delta'] .= $mapped_event['delta_text'];
            }

            if (!empty($mapped_event['warning_text']) && is_string($mapped_event['warning_text'])) {
                if ($result['delta'] === null) {
                    $result['delta'] = '';
                }
                $result['delta'] .= $mapped_event['warning_text'];
                $result['is_warning'] = true;
            }
            return false;

        case 'error':
            $message = isset($mapped_event['message']) ? (string) $mapped_event['message'] : '';
            $result['delta'] = $message;
            $result['is_error'] = true;
            return true;

        case 'done':
            $result['is_done'] = true;
            return false;

        default:
            return false;
    }
}

namespace WPAICG\Core\Providers\Azure;

class AzureUrlBuilder {

    /**
     * @return string|\WP_Error
     */
    public static function build(string $operation, array $params) {
        return Methods\build_logic_for_url_builder($operation, $params);
    }
}

class AzurePayloadFormatter {

    public static function format_chat(string $instructions, array $history, array $ai_params, string $model): array {
        return Methods\format_chat_logic_for_payload_formatter($instructions, $history, $ai_params, $model);
    }

    public static function format_sse(array $messages, string $instructions, array $ai_params, string $model, bool $request_usage = true): array {
        return Methods\format_sse_logic_for_payload_formatter($messages, $instructions, $ai_params, $model, $request_usage);
    }

    public static function format_embeddings($input, array $options): array {
        return Methods\format_embeddings_logic_for_payload_formatter($input, $options);
    }
}

class AzureResponseParser {

    /**
     * @return mixed[]|\WP_Error
     */
    public static function parse_chat(array $decoded_response) {
        return Methods\parse_chat_logic_for_response_parser($decoded_response);
    }

    public static function parse_error($response_body, int $status_code): string {
        return Methods\parse_error_logic_for_response_parser($response_body, $status_code);
    }

    public static function parse_sse_chunk(string $sse_chunk, string &$current_buffer): array {
        return Methods\parse_sse_chunk_logic_for_response_parser($sse_chunk, $current_buffer);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public static function parse_embeddings(array $decoded_response) {
        return Methods\parse_embeddings_logic_for_response_parser($decoded_response);
    }
}

namespace WPAICG\Core\Providers;

require_once __DIR__ . '/contracts.php';

class AzureProviderStrategy extends BaseProviderStrategy {

    /**
     * @return string|\WP_Error
     */
    public function build_api_url(string $operation, array $params) {
        return Azure\AzureUrlBuilder::build($operation, $params);
    }

    public function get_api_headers(string $api_key, string $operation): array {
        return Azure\Methods\get_api_headers_logic($this, $api_key, $operation);
    }

    public function format_chat_payload(string $user_message, string $instructions, array $history, array $ai_params, string $model): array {
        return Azure\Methods\format_chat_payload_logic($this, $user_message, $instructions, $history, $ai_params, $model);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function parse_chat_response(array $decoded_response, array $request_data) {
        return Azure\AzureResponseParser::parse_chat($decoded_response);
    }

    public function parse_error_response($response_body, int $status_code): string {
        return Azure\AzureResponseParser::parse_error($response_body, $status_code);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function get_models(array $api_params) {
        return Azure\Methods\get_models_logic($this, $api_params);
    }

    public function build_sse_payload(array $messages, $system_instruction, array $ai_params, string $model): array {
        return Azure\AzurePayloadFormatter::format_sse($messages, $system_instruction, $ai_params, $model, true);
    }

    public function parse_sse_chunk(string $sse_chunk, string &$current_buffer): array {
        return Azure\AzureResponseParser::parse_sse_chunk($sse_chunk, $current_buffer);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function generate_embeddings($input, array $api_params, array $options = []) {
        return Azure\Methods\generate_embeddings_logic($this, $input, $api_params, $options);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function decode_json_public(string $json_string, string $context) {
        return $this->decode_json($json_string, $context);
    }
}
