<?php

namespace WPAICG\Core\Providers;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/contracts.php';
require_once __DIR__ . '/chat-completions.php';

class XAIProviderStrategy extends BaseProviderStrategy {
    /**
     * @return string|\WP_Error
     */
    public function build_api_url(string $operation, array $params) {
        return \WPAICG\Core\Providers\XAI\Methods\build_api_url_logic($this, $operation, $params);
    }

    public function get_api_headers(string $api_key, string $operation): array {
        return \WPAICG\Core\Providers\XAI\Methods\get_api_headers_logic($this, $api_key, $operation);
    }

    public function format_chat_payload(string $user_message, string $instructions, array $history, array $ai_params, string $model): array {
        return \WPAICG\Core\Providers\XAI\Methods\format_chat_payload_logic($this, $user_message, $instructions, $history, $ai_params, $model);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function parse_chat_response(array $decoded_response, array $request_data) {
        return \WPAICG\Core\Providers\XAI\Methods\parse_chat_response_logic($this, $decoded_response, $request_data);
    }

    public function parse_error_response($response_body, int $status_code): string {
        return \WPAICG\Core\Providers\XAI\Methods\parse_error_response_logic($this, $response_body, $status_code);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function get_models(array $api_params) {
        return \WPAICG\Core\Providers\XAI\Methods\get_models_logic($this, $api_params);
    }

    public function build_sse_payload(array $messages, $system_instruction, array $ai_params, string $model): array {
        return \WPAICG\Core\Providers\XAI\Methods\build_sse_payload_logic($this, $messages, $system_instruction, $ai_params, $model);
    }

    public function parse_sse_chunk(string $sse_chunk, string &$current_buffer): array {
        return \WPAICG\Core\Providers\XAI\Methods\parse_sse_chunk_logic($this, $sse_chunk, $current_buffer);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function generate_embeddings($input, array $api_params, array $options = []) {
        return new WP_Error(
            'embeddings_not_supported_xai',
            __('Embedding generation is not supported by the xAI provider strategy.', 'gpt3-ai-content-generator'),
            ['status' => 501]
        );
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function decode_json_public(string $json_string, string $context) {
        return $this->decode_json($json_string, $context);
    }
}

namespace WPAICG\Core\Providers\XAI\Methods;

use WPAICG\Core\Providers\XAIProviderStrategy;
use WP_Error;
use function WPAICG\Core\Providers\Shared\extract_sse_event_blocks;
use function WPAICG\Core\Providers\Shared\decode_event_type_sse_event_block;

/**
 * @param XAIProviderStrategy $strategyInstance
 * @param string $operation
 * @param array<string, mixed> $params
 * @return string|\WP_Error
 */
function build_api_url_logic(XAIProviderStrategy $strategyInstance, string $operation, array $params) {
    // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Provider-specific API transport.
    $base_url = !empty($params['base_url']) ? rtrim((string) $params['base_url'], '/') : 'https://api.x.ai';
    $api_version = !empty($params['api_version']) ? trim((string) $params['api_version'], '/') : 'v1';

    if ($base_url === '') {
        return new WP_Error('missing_base_url_xai', __('xAI Base URL is required.', 'gpt3-ai-content-generator'));
    }
    if ($api_version === '') {
        return new WP_Error('missing_api_version_xai', __('xAI API Version is required.', 'gpt3-ai-content-generator'));
    }

    $paths = [
        'chat' => '/responses',
        'stream' => '/responses',
        'responses' => '/responses',
        'models' => '/models',
        'language_models' => '/language-models',
    ];

    if (!isset($paths[$operation])) {
        return new WP_Error(
            'unsupported_operation_xai',
            sprintf(
                /* translators: %s: The operation name. */
                __('Operation "%s" is not supported for xAI.', 'gpt3-ai-content-generator'),
                esc_html($operation)
            )
        );
    }

    $version_segment = '/' . $api_version;
    $path = $paths[$operation];

    if (strpos($base_url, $version_segment) !== false) {
        return $base_url . $path;
    }

    return $base_url . $version_segment . $path;
}

/**
 * @param XAIProviderStrategy $strategyInstance
 * @param array<int, array<string, mixed>> $messages
 * @param string|array|null $system_instruction
 * @param array<string, mixed> $ai_params
 * @param string $model
 * @return array<string, mixed>
 */
function build_sse_payload_logic(
    XAIProviderStrategy $strategyInstance,
    array $messages,
    $system_instruction,
    array $ai_params,
    string $model
): array {
    $instructions = is_string($system_instruction) ? $system_instruction : '';
    return xai_format_responses_payload($instructions, $messages, '', $ai_params, $model, true);
}

/**
 * @param XAIProviderStrategy $strategyInstance
 * @param string $user_message
 * @param string $instructions
 * @param array<int, array<string, mixed>> $history
 * @param array<string, mixed> $ai_params
 * @param string $model
 * @return array<string, mixed>
 */
function format_chat_payload_logic(
    XAIProviderStrategy $strategyInstance,
    string $user_message,
    string $instructions,
    array $history,
    array $ai_params,
    string $model
): array {
    return xai_format_responses_payload($instructions, $history, $user_message, $ai_params, $model, false);
}

/**
 * @param string $instructions
 * @param array<int, array<string, mixed>> $messages
 * @param string $user_message
 * @param array<string, mixed> $ai_params
 * @param string $model
 * @param bool $stream
 * @return array<string, mixed>
 */
function xai_format_responses_payload(
    string $instructions,
    array $messages,
    string $user_message,
    array $ai_params,
    string $model,
    bool $stream
): array {
    $has_image_inputs = !empty($ai_params['image_inputs']) && is_array($ai_params['image_inputs']);
    $body = [
        'model' => $model,
        'input' => xai_build_input_messages($instructions, $messages, $user_message, $ai_params),
        'store' => isset($ai_params['xai_store_conversation']) ? xai_truthy($ai_params['xai_store_conversation']) : false,
    ];

    if ($stream) {
        $body['stream'] = true;
    }

    if (!$has_image_inputs && !empty($ai_params['xai_previous_response_id']) && is_string($ai_params['xai_previous_response_id'])) {
        $body['previous_response_id'] = $ai_params['xai_previous_response_id'];
        $body['store'] = true;
    }
    if ($has_image_inputs) {
        $body['store'] = false;
    }

    $tools = xai_build_tools($ai_params);
    if (!empty($tools)) {
        $body['tools'] = $tools;
    }

    if (isset($ai_params['temperature'])) {
        $body['temperature'] = floatval($ai_params['temperature']);
    }
    if (isset($ai_params['top_p'])) {
        $body['top_p'] = floatval($ai_params['top_p']);
    }
    if (isset($ai_params['max_completion_tokens'])) {
        $body['max_output_tokens'] = absint($ai_params['max_completion_tokens']);
    } elseif (isset($ai_params['max_output_tokens'])) {
        $body['max_output_tokens'] = absint($ai_params['max_output_tokens']);
    }
    if (isset($ai_params['xai_reasoning']) && is_array($ai_params['xai_reasoning'])) {
        $body['reasoning'] = $ai_params['xai_reasoning'];
    }

    return $body;
}

function get_api_headers_logic(XAIProviderStrategy $strategyInstance, string $api_key, string $operation): array {
    $headers = [
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer ' . $api_key,
    ];

    if ($operation === 'stream') {
        $headers['Accept'] = 'text/event-stream';
        $headers['Cache-Control'] = 'no-cache';
    }

    return $headers;
}

/**
 * @param XAIProviderStrategy $strategyInstance
 * @param array<string, mixed> $api_params
 * @return array<int, array<string, mixed>>|WP_Error
 */
function get_models_logic(XAIProviderStrategy $strategyInstance, array $api_params) {
    $url = $strategyInstance->build_api_url('language_models', $api_params);
    if (is_wp_error($url)) {
        return $url;
    }

    $headers = $strategyInstance->get_api_headers($api_params['api_key'] ?? '', 'models');
    $options = $strategyInstance->get_request_options('models');
    $options['method'] = 'GET';

    $response = wp_remote_get($url, array_merge($options, ['headers' => $headers]));
    if (is_wp_error($response)) {
        return $response;
    }

    $status_code = (int) wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if (in_array($status_code, [404, 405], true)) {
        $fallback_url = $strategyInstance->build_api_url('models', $api_params);
        if (is_wp_error($fallback_url)) {
            return $fallback_url;
        }
        $response = wp_remote_get($fallback_url, array_merge($options, ['headers' => $headers]));
        if (is_wp_error($response)) {
            return $response;
        }
        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
    }

    if ($status_code !== 200) {
        $error_msg = $strategyInstance->parse_error_response($body, $status_code);
        return new WP_Error(
            'api_error_xai_models',
            sprintf('xAI API Error (HTTP %d): %s', $status_code, esc_html($error_msg)),
            ['status' => $status_code]
        );
    }

    $decoded = $strategyInstance->decode_json_public($body, 'xAI Models');
    if (is_wp_error($decoded)) {
        return $decoded;
    }

    $raw_models = [];
    if (isset($decoded['models']) && is_array($decoded['models'])) {
        $raw_models = $decoded['models'];
    } elseif (isset($decoded['data']) && is_array($decoded['data'])) {
        $raw_models = $decoded['data'];
    } elseif (xai_array_is_list($decoded)) {
        $raw_models = $decoded;
    }

    return xai_format_model_list($raw_models);
}

/**
 * @param array<mixed> $array
 */
function xai_array_is_list(array $array): bool {
    $expected_key = 0;
    foreach ($array as $key => $_value) {
        if ($key !== $expected_key) {
            return false;
        }
        $expected_key++;
    }

    return true;
}

/**
 * @param array<int, mixed> $raw_models
 * @return array<int, array<string, mixed>>
 */
function xai_format_model_list(array $raw_models): array {
    $formatted = [];

    foreach ($raw_models as $model) {
        if (is_string($model)) {
            $formatted[] = ['id' => $model, 'name' => $model];
            continue;
        }
        if (!is_array($model)) {
            continue;
        }

        $id = $model['id'] ?? $model['model'] ?? $model['name'] ?? null;
        if (!is_string($id) || trim($id) === '') {
            continue;
        }

        $name = $model['name'] ?? $model['display_name'] ?? $id;
        $item = [
            'id' => $id,
            'name' => is_string($name) && trim($name) !== '' ? $name : $id,
            'status' => $model['status'] ?? null,
            'version' => $model['version'] ?? null,
        ];

        $metadata_keys = [
            'aliases',
            'created',
            'fingerprint',
            'input_modalities',
            'output_modalities',
            'owned_by',
            'prompt_text_token_price',
            'cached_prompt_text_token_price',
            'completion_text_token_price',
            'prompt_image_token_price',
            'search_price',
        ];
        foreach ($metadata_keys as $metadata_key) {
            if (array_key_exists($metadata_key, $model)) {
                $item[$metadata_key] = $model[$metadata_key];
            }
        }

        $formatted[] = $item;
    }

    usort($formatted, fn ($a, $b) => strcasecmp($a['name'] ?? '', $b['name'] ?? ''));

    return $formatted;
}

/**
 * Normalizes common truthy settings without treating absent settings as enabled.
 */
function xai_truthy($value): bool {
    return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'yes';
}

/**
 * Builds xAI Responses API input messages. xAI does not support the OpenAI-only
 * top-level "instructions" field, so system/developer guidance stays in input.
 *
 * @param string $instructions
 * @param array<int, array<string, mixed>> $history
 * @param string $user_message
 * @param array<string, mixed> $ai_params
 * @return array<int, array<string, mixed>>
 */
function xai_build_input_messages(string $instructions, array $history, string $user_message, array $ai_params): array {
    $input = [];

    if (trim($instructions) !== '') {
        $input[] = [
            'role' => 'system',
            'content' => trim($instructions),
        ];
    }

    foreach ($history as $message) {
        if (!is_array($message)) {
            continue;
        }

        $role = isset($message['role']) ? (string) $message['role'] : 'user';
        $role = ($role === 'bot') ? 'assistant' : $role;
        if (!in_array($role, ['system', 'developer', 'user', 'assistant'], true)) {
            continue;
        }
        if ($role === 'system' && trim($instructions) !== '') {
            continue;
        }

        $content = $message['content'] ?? '';
        if (is_array($content)) {
            $normalized_parts = xai_normalize_content_parts($content);
            if (!empty($normalized_parts)) {
                $input[] = ['role' => $role, 'content' => $normalized_parts];
            }
            continue;
        }

        $content = trim((string) $content);
        if ($content !== '') {
            $input[] = ['role' => $role, 'content' => $content];
        }
    }

    if (trim($user_message) !== '') {
        $input[] = [
            'role' => 'user',
            'content' => trim($user_message),
        ];
    }

    xai_attach_image_inputs($input, $ai_params);

    return $input;
}

/**
 * @param array<int, mixed> $parts
 * @return array<int, array<string, mixed>>
 */
function xai_normalize_content_parts(array $parts): array {
    $normalized = [];

    foreach ($parts as $part) {
        if (!is_array($part)) {
            continue;
        }

        $type = isset($part['type']) ? (string) $part['type'] : '';
        if (in_array($type, ['input_text', 'text'], true) && isset($part['text'])) {
            $normalized[] = [
                'type' => 'input_text',
                'text' => (string) $part['text'],
            ];
            continue;
        }

        if (in_array($type, ['input_image', 'image_url'], true)) {
            $image_url = '';
            if (isset($part['image_url']) && is_string($part['image_url'])) {
                $image_url = $part['image_url'];
            } elseif (isset($part['image_url']['url']) && is_string($part['image_url']['url'])) {
                $image_url = $part['image_url']['url'];
            }

            if ($image_url !== '') {
                $normalized[] = [
                    'type' => 'input_image',
                    'image_url' => $image_url,
                ];
            }
        }
    }

    return $normalized;
}

/**
 * @param array<int, array<string, mixed>> $input
 * @param array<string, mixed> $ai_params
 */
function xai_attach_image_inputs(array &$input, array $ai_params): void {
    if (empty($ai_params['image_inputs']) || !is_array($ai_params['image_inputs'])) {
        return;
    }

    $last_user_index = null;
    for ($i = count($input) - 1; $i >= 0; $i--) {
        if (($input[$i]['role'] ?? '') === 'user') {
            $last_user_index = $i;
            break;
        }
    }

    if ($last_user_index === null) {
        $input[] = ['role' => 'user', 'content' => ''];
        $last_user_index = array_key_last($input);
    }

    $existing_content = $input[$last_user_index]['content'] ?? '';
    $text_content = '';
    if (is_string($existing_content)) {
        $text_content = $existing_content;
    } elseif (is_array($existing_content)) {
        foreach ($existing_content as $part) {
            if (is_array($part) && in_array(($part['type'] ?? ''), ['text', 'input_text'], true) && isset($part['text'])) {
                $text_content = (string) $part['text'];
                break;
            }
        }
    }

    $content_parts = [
        [
            'type' => 'input_text',
            'text' => $text_content,
        ],
    ];

    foreach ($ai_params['image_inputs'] as $image_input) {
        if (!is_array($image_input) || empty($image_input['base64']) || empty($image_input['type'])) {
            continue;
        }

        $content_parts[] = [
            'type' => 'input_image',
            'image_url' => 'data:' . (string) $image_input['type'] . ';base64,' . (string) $image_input['base64'],
        ];
    }

    $input[$last_user_index]['content'] = $content_parts;
}

/**
 * @param array<string, mixed> $ai_params
 * @return array<int, array<string, mixed>>
 */
function xai_build_tools(array $ai_params): array {
    $tools = [];
    $tool_config = [];

    if (isset($ai_params['xai_web_search_tool_config']) && is_array($ai_params['xai_web_search_tool_config'])) {
        $tool_config = $ai_params['xai_web_search_tool_config'];
    } elseif (isset($ai_params['web_search_tool_config']) && is_array($ai_params['web_search_tool_config'])) {
        $tool_config = $ai_params['web_search_tool_config'];
    }

    $bot_allows_web_search = isset($tool_config['enabled']) && $tool_config['enabled'] === true;
    $frontend_requests_web_search = isset($ai_params['frontend_web_search_active']) && $ai_params['frontend_web_search_active'] === true;

    if ($bot_allows_web_search && $frontend_requests_web_search) {
        $tools[] = ['type' => 'web_search'];
    }

    return $tools;
}

/**
 * @param array<string, mixed> $usage
 * @param array<string, mixed> $response_context
 * @return array<string, mixed>
 */
function xai_normalize_usage(array $usage, array $response_context = []): array {
    $input_tokens = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
    $output_tokens = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
    $total_tokens = (int) ($usage['total_tokens'] ?? ($input_tokens + $output_tokens));
    $server_side_tool_usage = xai_extract_server_side_tool_usage($response_context, $usage);
    $provider_raw = $usage;
    if (!empty($server_side_tool_usage) && !isset($provider_raw['server_side_tool_usage'])) {
        $provider_raw['server_side_tool_usage'] = $server_side_tool_usage;
    }

    $normalized = [
        'input_tokens' => $input_tokens,
        'output_tokens' => $output_tokens,
        'total_tokens' => $total_tokens,
        'provider_raw' => $provider_raw,
    ];

    if (!empty($server_side_tool_usage)) {
        $normalized['server_side_tool_usage'] = $server_side_tool_usage;
        $normalized['server_side_tool_units'] = xai_sum_numeric_tool_usage($server_side_tool_usage);
    }

    return $normalized;
}

/**
 * Normalize response usage when token or server-side tool counters are present.
 * A null result leaves earlier stream usage intact.
 *
 * @param array<string, mixed> $response
 * @return array<string, mixed>|null
 */
function xai_extract_response_usage(array $response): ?array {
    if (isset($response['usage']) && is_array($response['usage'])) {
        return xai_normalize_usage($response['usage'], $response);
    }
    if (!empty(xai_extract_server_side_tool_usage($response))) {
        return xai_normalize_usage([], $response);
    }

    return null;
}

/**
 * Extracts xAI server-side tool usage counts from Responses payloads.
 *
 * @param array<string, mixed> $response_context
 * @param array<string, mixed> $usage
 * @return array<string, mixed>
 */
function xai_extract_server_side_tool_usage(array $response_context, array $usage = []): array {
    $candidates = [$response_context, $usage];

    foreach ($candidates as $candidate) {
        if (isset($candidate['server_side_tool_usage']) && is_array($candidate['server_side_tool_usage'])) {
            return $candidate['server_side_tool_usage'];
        }
        if (isset($candidate['response']['server_side_tool_usage']) && is_array($candidate['response']['server_side_tool_usage'])) {
            return $candidate['response']['server_side_tool_usage'];
        }
    }

    return [];
}

/**
 * @param mixed $tool_usage
 */
function xai_sum_numeric_tool_usage($tool_usage): int {
    if (is_numeric($tool_usage)) {
        return max(0, (int) $tool_usage);
    }
    if (!is_array($tool_usage)) {
        return 0;
    }

    $total = 0;
    foreach ($tool_usage as $value) {
        $total += xai_sum_numeric_tool_usage($value);
    }

    return $total;
}

/**
 * @param array<string, mixed> $response
 */
function xai_extract_response_text(array $response): string {
    if (isset($response['output_text']) && is_string($response['output_text'])) {
        return trim($response['output_text']);
    }

    $chunks = [];
    if (isset($response['output']) && is_array($response['output'])) {
        foreach ($response['output'] as $output_item) {
            if (!is_array($output_item)) {
                continue;
            }
            if (isset($output_item['content']) && is_string($output_item['content'])) {
                $chunks[] = $output_item['content'];
                continue;
            }
            if (empty($output_item['content']) || !is_array($output_item['content'])) {
                continue;
            }
            foreach ($output_item['content'] as $content_part) {
                if (!is_array($content_part)) {
                    continue;
                }
                $type = isset($content_part['type']) ? (string) $content_part['type'] : '';
                if (in_array($type, ['output_text', 'text'], true) && isset($content_part['text'])) {
                    $chunks[] = (string) $content_part['text'];
                } elseif ($type === 'refusal' && isset($content_part['refusal'])) {
                    $chunks[] = (string) $content_part['refusal'];
                }
            }
        }
    }

    if (empty($chunks) && isset($response['choices'][0]['message']['content'])) {
        $fallback_content = $response['choices'][0]['message']['content'];
        if (is_string($fallback_content)) {
            $chunks[] = $fallback_content;
        }
    }

    return trim(implode('', $chunks));
}

/**
 * Recursively extracts URL citations from Responses payloads and tool events.
 *
 * @param mixed $value
 * @return array<int, array<string, string>>
 */
function xai_extract_citations($value): array {
    $citations = [];
    xai_collect_citations($value, $citations);
    return xai_dedupe_citations($citations);
}

/**
 * @param mixed $value
 * @param array<int, array<string, string>> $citations
 */
function xai_collect_citations($value, array &$citations): void {
    if (!is_array($value)) {
        return;
    }

    if (isset($value['url']) && is_string($value['url']) && preg_match('#^https?://#i', $value['url']) === 1) {
        $citations[] = [
            'url' => $value['url'],
            'title' => isset($value['title']) && is_string($value['title']) ? $value['title'] : $value['url'],
            'snippet' => isset($value['snippet']) && is_string($value['snippet'])
                ? $value['snippet']
                : (isset($value['description']) && is_string($value['description']) ? $value['description'] : ''),
        ];
    }

    foreach ($value as $child) {
        xai_collect_citations($child, $citations);
    }
}

/**
 * @param array<int, array<string, string>> $citations
 * @return array<int, array<string, string>>
 */
function xai_dedupe_citations(array $citations): array {
    $seen = [];
    $deduped = [];

    foreach ($citations as $citation) {
        $url = $citation['url'] ?? '';
        if ($url === '' || isset($seen[$url])) {
            continue;
        }
        $seen[$url] = true;
        $deduped[] = $citation;
    }

    return $deduped;
}

/**
 * @param XAIProviderStrategy $strategyInstance
 * @param array<string, mixed> $decoded_response
 * @param array<string, mixed> $request_data
 * @return array<string, mixed>|WP_Error
 */
function parse_chat_response_logic(
    XAIProviderStrategy $strategyInstance,
    array $decoded_response,
    array $request_data
) {
    if (($decoded_response['status'] ?? '') === 'failed') {
        $message = $decoded_response['error']['message'] ?? __('xAI response failed.', 'gpt3-ai-content-generator');
        $code = $decoded_response['error']['code'] ?? 'xai_failed_response';
        return new WP_Error((string) $code, (string) $message);
    }

    $content = xai_extract_response_text($decoded_response);
    $is_incomplete = ($decoded_response['status'] ?? '') === 'incomplete';
    if ($content === '' && !$is_incomplete) {
        return new WP_Error(
            'invalid_response_structure_xai',
            __('Unexpected response structure from xAI Responses API.', 'gpt3-ai-content-generator')
        );
    }

    if ($is_incomplete) {
        $reason = $decoded_response['incomplete_details']['reason'] ?? 'unknown';
        if ($content !== '') {
            $content .= sprintf(' (%s: %s)', __('Incomplete', 'gpt3-ai-content-generator'), $reason);
        } else {
            return new WP_Error(
                'xai_incomplete_response',
                sprintf(
                    /* translators: %s: The reason why the response was incomplete. */
                    __('Response incomplete due to: %s', 'gpt3-ai-content-generator'),
                    (string) $reason
                )
            );
        }
    }

    $usage = xai_extract_response_usage($decoded_response);

    $return_data = [
        'content' => $content,
        'usage' => $usage,
    ];

    if (!empty($decoded_response['id']) && is_string($decoded_response['id'])) {
        $return_data['xai_response_id'] = $decoded_response['id'];
    }

    $citations = xai_extract_citations($decoded_response);
    if (!empty($citations)) {
        $return_data['citations'] = $citations;
    }

    return $return_data;
}

function xai_error_value_to_string($value): string {
    if (is_string($value)) {
        return trim($value);
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    if (is_array($value)) {
        $encoded = wp_json_encode($value);
        return is_string($encoded) ? trim($encoded) : '';
    }

    return '';
}

/**
 * @param array<string, mixed> $error
 */
function xai_extract_error_object_message(array $error): string {
    $message = '';
    foreach (['message', 'detail', 'description', 'reason'] as $message_key) {
        if (!empty($error[$message_key])) {
            $message = xai_error_value_to_string($error[$message_key]);
            break;
        }
    }

    $metadata = [];
    foreach (['code', 'type', 'param'] as $metadata_key) {
        if (!empty($error[$metadata_key])) {
            $metadata_value = xai_error_value_to_string($error[$metadata_key]);
            if ($metadata_value !== '') {
                $metadata[] = ucfirst($metadata_key) . ': ' . $metadata_value;
            }
        }
    }

    if ($message === '' && !empty($metadata)) {
        return implode(' ', $metadata);
    }
    if ($message !== '' && !empty($metadata)) {
        return $message . ' (' . implode(', ', $metadata) . ')';
    }

    return $message;
}

/**
 * @param array<string, mixed> $decoded
 */
function xai_extract_error_message(array $decoded): string {
    if (!empty($decoded['error'])) {
        if (is_string($decoded['error'])) {
            return trim($decoded['error']);
        }
        if (is_array($decoded['error'])) {
            $message = xai_extract_error_object_message($decoded['error']);
            if ($message !== '') {
                return $message;
            }
        }
    }

    foreach (['message', 'detail', 'description'] as $message_key) {
        if (!empty($decoded[$message_key])) {
            return xai_error_value_to_string($decoded[$message_key]);
        }
    }

    if (!empty($decoded['errors']) && is_array($decoded['errors'])) {
        foreach ($decoded['errors'] as $error_item) {
            if (is_string($error_item)) {
                return trim($error_item);
            }
            if (is_array($error_item)) {
                $message = xai_extract_error_object_message($error_item);
                if ($message !== '') {
                    return $message;
                }
            }
        }
    }

    foreach (['code', 'type', 'status'] as $metadata_key) {
        if (!empty($decoded[$metadata_key])) {
            return ucfirst($metadata_key) . ': ' . xai_error_value_to_string($decoded[$metadata_key]);
        }
    }

    return '';
}

function xai_parse_error_response_body($response_body, int $status_code, string $fallback_message): string {
    $decoded = is_string($response_body) ? json_decode($response_body, true) : $response_body;
    $message = '';

    if (is_array($decoded)) {
        $message = xai_extract_error_message($decoded);
    } elseif (is_string($response_body) && trim($response_body) !== '') {
        $message = trim(substr($response_body, 0, 500));
    }

    if ($message === '' && $status_code === 429) {
        return __('Rate limit or quota exceeded. Please wait and retry, or check your xAI Console rate limits and billing.', 'gpt3-ai-content-generator');
    }
    if ($message === '') {
        return $fallback_message;
    }

    return trim((string) $message);
}

function parse_error_response_logic(XAIProviderStrategy $strategyInstance, $response_body, int $status_code): string {
    return xai_parse_error_response_body(
        $response_body,
        $status_code,
        __('An unknown xAI API error occurred.', 'gpt3-ai-content-generator')
    );
}


/**
 * @param XAIProviderStrategy $strategyInstance
 * @return array<string, mixed>
 */
function parse_sse_chunk_logic(XAIProviderStrategy $strategyInstance, string $sse_chunk, string &$current_buffer): array {
    $current_buffer .= $sse_chunk;
    $result = [
        'delta' => null,
        'usage' => null,
        'is_error' => false,
        'is_warning' => false,
        'is_done' => false,
        'status' => null,
        'citations' => null,
        'xai_response_id' => null,
    ];

    foreach (extract_sse_event_blocks($current_buffer) as $event_block) {
        $event = decode_event_type_sse_event_block($event_block);
        if ($event === null) {
            continue;
        }

        $should_stop = xai_apply_sse_event($strategyInstance, $event, $result);
        if ($should_stop) {
            return $result;
        }
    }

    return $result;
}

/**
 * @param XAIProviderStrategy $strategyInstance
 * @param array<string, mixed> $event
 * @param array<string, mixed> $result
 */
function xai_apply_sse_event(XAIProviderStrategy $strategyInstance, array $event, array &$result): bool {
    $event_type = isset($event['event']) && is_string($event['event']) ? $event['event'] : 'message';
    $payload = isset($event['payload']) && is_array($event['payload']) ? $event['payload'] : [];

    if ($event_type === '[DONE]') {
        $result['is_done'] = true;
        return false;
    }

    if (in_array($event_type, ['ping', 'keepalive'], true)) {
        return false;
    }

    if ($event_type === 'error' || isset($payload['error'])) {
        $result['delta'] = $strategyInstance->parse_error_response($payload, 500);
        $result['is_error'] = true;
        return true;
    }

    $event_citations = xai_extract_citations($payload);
    if (!empty($event_citations)) {
        $result['citations'] = xai_dedupe_citations(array_merge(
            is_array($result['citations']) ? $result['citations'] : [],
            $event_citations
        ));
    }

    switch ($event_type) {
        case 'response.created':
        case 'response.in_progress':
        case 'response.queued':
        case 'response.web_search_call.in_progress':
        case 'response.web_search_call.searching':
        case 'response.web_search_call.completed':
        case 'response.function_call_arguments.delta':
        case 'response.function_call_arguments.done':
            $result['status'] = xai_build_sse_status($event_type, $payload);
            return false;

        case 'response.output_text.delta':
            $delta = isset($payload['delta']) ? (string) $payload['delta'] : '';
            if ($delta !== '') {
                $result['delta'] = ($result['delta'] ?? '') . $delta;
            }
            return false;

        case 'response.refusal.delta':
            $refusal = isset($payload['delta']) ? (string) $payload['delta'] : '';
            if ($refusal !== '') {
                $result['delta'] = ($result['delta'] ?? '') . sprintf(' (%s: %s)', __('Refusal', 'gpt3-ai-content-generator'), $refusal);
                $result['is_warning'] = true;
            }
            return false;

        case 'response.completed':
        case 'response.incomplete':
            $response = isset($payload['response']) && is_array($payload['response']) ? $payload['response'] : $payload;
            $result['is_done'] = true;
            $usage = xai_extract_response_usage($response);
            if ($usage !== null) {
                $result['usage'] = $usage;
            }
            if (!empty($response['id']) && is_string($response['id'])) {
                $result['xai_response_id'] = $response['id'];
            }
            if ($event_type === 'response.incomplete') {
                $reason = $response['incomplete_details']['reason'] ?? 'unknown';
                $result['delta'] = ($result['delta'] ?? '') . sprintf(' (%s: %s)', __('Incomplete', 'gpt3-ai-content-generator'), (string) $reason);
                $result['is_warning'] = true;
            }
            return false;

        case 'response.failed':
            $response = isset($payload['response']) && is_array($payload['response']) ? $payload['response'] : $payload;
            $message = $response['error']['message'] ?? __('xAI response failed.', 'gpt3-ai-content-generator');
            $result['delta'] = sprintf(' (%s: %s)', __('Error', 'gpt3-ai-content-generator'), (string) $message);
            $result['is_error'] = true;
            return true;

        case 'message':
            return xai_apply_message_event($payload, $result);

        default:
            return false;
    }
}

/**
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $result
 */
function xai_apply_message_event(array $payload, array &$result): bool {
    if (isset($payload['choices'][0]['delta']['content'])) {
        $delta = $payload['choices'][0]['delta']['content'];
        if (is_string($delta) && $delta !== '') {
            $result['delta'] = ($result['delta'] ?? '') . $delta;
        }
    }

    if (isset($payload['choices'][0]['finish_reason']) && $payload['choices'][0]['finish_reason'] !== null) {
        $result['is_done'] = true;
    }

    $usage = xai_extract_response_usage($payload);
    if ($usage !== null) {
        $result['usage'] = $usage;
    }

    return false;
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function xai_build_sse_status(string $event_type, array $payload): array {
    $status = ['type' => $event_type];

    if (isset($payload['response']['status'])) {
        $status['status'] = $payload['response']['status'];
    }
    if (isset($payload['response']['id'])) {
        $status['response_id'] = $payload['response']['id'];
    }
    if (isset($payload['item_id'])) {
        $status['item_id'] = $payload['item_id'];
    }
    if (isset($payload['output_index'])) {
        $status['output_index'] = $payload['output_index'];
    }

    return $status;
}

if (function_exists('add_filter')) {
    add_filter('aipkit_chat_image_input_validation_error', __NAMESPACE__ . '\\xai_validate_chat_image_inputs', 10, 6);
}

/**
 * @param mixed $validation_error Existing validation error.
 * @param mixed $image_inputs Normalized image input payload.
 * @param mixed $provider Selected provider.
 * @param mixed $model Selected model.
 * @return mixed
 */
function xai_validate_chat_image_inputs($validation_error, $image_inputs, $provider, $model = '', $bot_settings = [], $flow = '') {
    if (is_wp_error($validation_error) || (string) $provider !== 'xAI') {
        return $validation_error;
    }

    if (!is_array($image_inputs)) {
        return new WP_Error(
            'xai_invalid_image_payload',
            __('Invalid image upload payload for xAI.', 'gpt3-ai-content-generator'),
            ['status' => 400]
        );
    }

    if (!class_exists('\WPAICG\AIPKit_Providers') && defined('WPAICG_PLUGIN_DIR')) {
        $providers_path = WPAICG_PLUGIN_DIR . 'classes/ai/settings.php';
        if (file_exists($providers_path)) {
            require_once $providers_path;
        }
    }
    if (class_exists('\WPAICG\AIPKit_Providers') && !\WPAICG\AIPKit_Providers::xai_model_supports_image_input((string) $model)) {
        return new WP_Error(
            'xai_model_no_image_input',
            __('The selected xAI model does not support image analysis.', 'gpt3-ai-content-generator'),
            ['status' => 400]
        );
    }

    $allowed_mime_types = [
        'image/jpeg' => true,
        'image/jpg' => true,
        'image/png' => true,
    ];
    foreach ($image_inputs as $image_input) {
        if (!is_array($image_input)) {
            return new WP_Error(
                'xai_invalid_image_payload',
                __('Invalid image upload payload for xAI.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }

        $mime_type = isset($image_input['type']) ? strtolower(trim((string) $image_input['type'])) : '';
        if ($mime_type === '' || !isset($allowed_mime_types[$mime_type])) {
            return new WP_Error(
                'xai_unsupported_image_type',
                __('xAI image analysis supports JPG and PNG images only.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }
    }

    return $validation_error;
}
