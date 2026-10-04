<?php

namespace WPAICG\Core\Providers;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/contracts.php';
require_once __DIR__ . '/chat-completions.php';

/**
 * Claude (Anthropic) provider strategy.
 */
class ClaudeProviderStrategy extends BaseProviderStrategy {

    /**
     * @return string|\WP_Error
     */
    public function build_api_url(string $operation, array $params) {
        return \WPAICG\Core\Providers\Claude\Methods\build_api_url_logic($this, $operation, $params);
    }

    public function get_api_headers(string $api_key, string $operation): array {
        return \WPAICG\Core\Providers\Claude\Methods\get_api_headers_logic($this, $api_key, $operation);
    }

    public function format_chat_payload(string $user_message, string $instructions, array $history, array $ai_params, string $model): array {
        return \WPAICG\Core\Providers\Claude\Methods\format_chat_payload_logic($this, $user_message, $instructions, $history, $ai_params, $model);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function parse_chat_response(array $decoded_response, array $request_data) {
        return \WPAICG\Core\Providers\Claude\Methods\parse_chat_response_logic($this, $decoded_response, $request_data);
    }

    public function parse_error_response($response_body, int $status_code): string {
        return \WPAICG\Core\Providers\Claude\Methods\parse_error_response_logic($this, $response_body, $status_code);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function get_models(array $api_params) {
        return \WPAICG\Core\Providers\Claude\Methods\get_models_logic($this, $api_params);
    }

    public function build_sse_payload(array $messages, $system_instruction, array $ai_params, string $model): array {
        return \WPAICG\Core\Providers\Claude\Methods\build_sse_payload_logic($this, $messages, $system_instruction, $ai_params, $model);
    }

    public function parse_sse_chunk(string $sse_chunk, string &$current_buffer): array {
        return \WPAICG\Core\Providers\Claude\Methods\parse_sse_chunk_logic($this, $sse_chunk, $current_buffer);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function generate_embeddings($input, array $api_params, array $options = []) {
        return new WP_Error(
            'embeddings_not_supported_claude',
            __('Embedding generation is not supported for Claude in this provider strategy.', 'gpt3-ai-content-generator')
        );
    }
}

namespace WPAICG\Core\Providers\Claude\Methods;

use WPAICG\Core\Providers\ClaudeProviderStrategy;
use WP_Error;
use function WPAICG\Core\Providers\Shared\extract_sse_event_blocks;
use function WPAICG\Core\Providers\Shared\decode_event_type_sse_event_block;


/**
 * Shared payload formatter for Anthropic Messages API.
 */
function build_claude_payload_shared(
    string $instructions,
    array $history,
    string $user_message,
    array $ai_params,
    string $model,
    bool $stream = false
): array {
    $messages = [];

    foreach ($history as $msg) {
        if (!is_array($msg)) {
            continue;
        }
        $role = $msg['role'] ?? '';
        if ($role === 'bot') {
            $role = 'assistant';
        }
        if (!in_array($role, ['user', 'assistant'], true)) {
            continue;
        }

        $content = isset($msg['content']) ? trim((string) $msg['content']) : '';
        if ($content === '') {
            continue;
        }

        $messages[] = [
            'role' => $role,
            'content' => $content,
        ];
    }

    if ($user_message !== '') {
        $last = end($messages);
        $is_duplicate_last_user = is_array($last)
            && ($last['role'] ?? '') === 'user'
            && trim((string) ($last['content'] ?? '')) === trim($user_message);
        if (!$is_duplicate_last_user) {
            $messages[] = [
                'role' => 'user',
                'content' => trim($user_message),
            ];
        }
    }

    $claude_file_ids = [];
    if (!empty($ai_params['claude_file_ids']) && is_array($ai_params['claude_file_ids'])) {
        foreach ($ai_params['claude_file_ids'] as $file_id_candidate) {
            $file_id = sanitize_text_field((string) $file_id_candidate);
            if ($file_id !== '' && preg_match('/^file_[a-zA-Z0-9_-]+$/', $file_id)) {
                $claude_file_ids[] = $file_id;
            }
        }
        $claude_file_ids = array_values(array_unique($claude_file_ids));
    }

    $has_image_inputs = !empty($ai_params['image_inputs']) && is_array($ai_params['image_inputs']);

    if ($has_image_inputs || !empty($claude_file_ids)) {
        $last_user_index = null;
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                $last_user_index = $i;
                break;
            }
        }

        if ($last_user_index === null) {
            $messages[] = ['role' => 'user', 'content' => ''];
            $last_user_index = count($messages) - 1;
        }

        $existing_content = $messages[$last_user_index]['content'] ?? '';
        $content_blocks = [];

        if (is_string($existing_content) && trim($existing_content) !== '') {
            $content_blocks[] = [
                'type' => 'text',
                'text' => trim($existing_content),
            ];
        }

        if ($has_image_inputs) {
            foreach ($ai_params['image_inputs'] as $image_input) {
                if (!is_array($image_input)) {
                    continue;
                }
                $base64_data = $image_input['base64'] ?? '';
                $media_type = $image_input['type'] ?? '';
                if ($base64_data === '' || $media_type === '') {
                    continue;
                }

                $content_blocks[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => (string) $media_type,
                        'data' => (string) $base64_data,
                    ],
                ];
            }
        }

        foreach ($claude_file_ids as $claude_file_id) {
            $content_blocks[] = [
                'type' => 'document',
                'source' => [
                    'type' => 'file',
                    'file_id' => $claude_file_id,
                ],
            ];
        }

        if (!empty($content_blocks)) {
            $messages[$last_user_index]['content'] = $content_blocks;
        }
    }

    $payload = [
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => isset($ai_params['max_completion_tokens'])
            ? max(1, absint($ai_params['max_completion_tokens']))
            : 4096,
    ];

    if ($instructions !== '') {
        $payload['system'] = $instructions;
    }

    // These model families reject custom sampling controls; omit saved values from their requests.
    $fixed_sampling = preg_match('/^claude-(?:sonnet-5|opus-4-[78])(?:-\d{8})?$/iD', $model) === 1;
    if (!$fixed_sampling) {
        $temperature = isset($ai_params['temperature']) && is_numeric($ai_params['temperature'])
            ? floatval($ai_params['temperature'])
            : null;
        $top_p = isset($ai_params['top_p']) && is_numeric($ai_params['top_p'])
            ? max(0.0, min(1.0, floatval($ai_params['top_p'])))
            : null;
        $temperature_is_default = $temperature !== null && abs($temperature - 1.0) < 0.00001;
        $top_p_is_non_default = $top_p !== null && abs($top_p - 1.0) >= 0.00001;

        // Anthropic rejects payloads that include both temperature and top_p.
        if ($top_p_is_non_default && ($temperature === null || $temperature_is_default)) {
            $payload['top_p'] = $top_p;
        } elseif ($temperature !== null) {
            $payload['temperature'] = $temperature;
        } elseif ($top_p !== null) {
            $payload['top_p'] = $top_p;
        }

    }

    if (!empty($ai_params['stop'])) {
        if (is_string($ai_params['stop'])) {
            $payload['stop_sequences'] = [trim($ai_params['stop'])];
        } elseif (is_array($ai_params['stop'])) {
            $stop_sequences = array_values(array_filter(array_map(
                static fn($item) => is_string($item) ? trim($item) : '',
                $ai_params['stop']
            )));
            if (!empty($stop_sequences)) {
                $payload['stop_sequences'] = $stop_sequences;
            }
        }
    }

    $bot_allows_web_search = isset($ai_params['web_search_tool_config']['enabled'])
        && $ai_params['web_search_tool_config']['enabled'] === true;
    $frontend_requests_web_search = !isset($ai_params['frontend_web_search_active'])
        || $ai_params['frontend_web_search_active'] === true;

    if ($bot_allows_web_search && $frontend_requests_web_search) {
        $tool = [
            'name' => 'web_search',
            'type' => $ai_params['web_search_tool_config']['type'] ?? 'web_search_20250305',
        ];

        if (!empty($ai_params['web_search_tool_config']['allowed_domains'])
            && is_array($ai_params['web_search_tool_config']['allowed_domains'])
        ) {
            $tool['allowed_domains'] = array_values(array_filter(array_map(
                static fn($domain) => is_string($domain) ? trim($domain) : '',
                $ai_params['web_search_tool_config']['allowed_domains']
            )));
        }

        if (!empty($ai_params['web_search_tool_config']['blocked_domains'])
            && is_array($ai_params['web_search_tool_config']['blocked_domains'])
        ) {
            $tool['blocked_domains'] = array_values(array_filter(array_map(
                static fn($domain) => is_string($domain) ? trim($domain) : '',
                $ai_params['web_search_tool_config']['blocked_domains']
            )));
        }

        if (!empty($ai_params['web_search_tool_config']['max_uses'])) {
            $tool['max_uses'] = absint($ai_params['web_search_tool_config']['max_uses']);
        }

        if (!empty($ai_params['web_search_tool_config']['user_location'])
            && is_array($ai_params['web_search_tool_config']['user_location'])
        ) {
            $user_location = array_filter($ai_params['web_search_tool_config']['user_location']);
            if (!empty($user_location)) {
                if (!isset($user_location['type'])) {
                    $user_location['type'] = 'approximate';
                }
                $tool['user_location'] = $user_location;
            }
        }

        if (!empty($ai_params['web_search_tool_config']['cache_control'])
            && is_array($ai_params['web_search_tool_config']['cache_control'])
        ) {
            $cache_control = $ai_params['web_search_tool_config']['cache_control'];
            $cache_type = isset($cache_control['type']) ? sanitize_text_field((string) $cache_control['type']) : '';
            $cache_ttl = isset($cache_control['ttl']) ? sanitize_text_field((string) $cache_control['ttl']) : '';
            if ($cache_type === 'ephemeral' && in_array($cache_ttl, ['5m', '1h'], true)) {
                $tool['cache_control'] = [
                    'type' => 'ephemeral',
                    'ttl' => $cache_ttl,
                ];
            }
        }

        $payload['tools'] = [$tool];
    }

    if ($stream) {
        $payload['stream'] = true;
    }

    return $payload;
}

/**
 * Detects whether a Claude payload references Files API document blocks.
 *
 * @param array $payload Claude Messages API payload.
 * @return bool
 */
function claude_payload_requires_files_beta_header(array $payload): bool
{
    if (empty($payload['messages']) || !is_array($payload['messages'])) {
        return false;
    }

    foreach ($payload['messages'] as $message_item) {
        if (!is_array($message_item)) {
            continue;
        }

        $content_blocks = $message_item['content'] ?? null;
        if (!is_array($content_blocks)) {
            continue;
        }

        foreach ($content_blocks as $content_block) {
            if (!is_array($content_block) || (($content_block['type'] ?? '') !== 'document')) {
                continue;
            }

            $source = $content_block['source'] ?? null;
            if (!is_array($source)) {
                continue;
            }

            if (($source['type'] ?? '') === 'file' && !empty($source['file_id'])) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Build Anthropic API URL.
 * @return string|\WP_Error
 */
function build_api_url_logic(ClaudeProviderStrategy $strategyInstance, string $operation, array $params) {
    $base_url = !empty($params['base_url']) ? rtrim((string) $params['base_url'], '/') : '';
    if ($base_url === '') {
        return new WP_Error('missing_base_url_claude', __('Claude base URL is required.', 'gpt3-ai-content-generator'));
    }

    $paths = [
        'chat' => '/v1/messages',
        'stream' => '/v1/messages',
        'models' => '/v1/models',
    ];

    if (!isset($paths[$operation])) {
        /* translators: %s: Operation name. */
        return new WP_Error('unsupported_operation_claude', sprintf(__('Operation "%s" is not supported for Claude.', 'gpt3-ai-content-generator'), $operation));
    }

    $path = $paths[$operation];
    if (strpos($base_url, '/v1') === (strlen($base_url) - 3) && strpos($path, '/v1/') === 0) {
        $path = substr($path, 3);
    }

    return $base_url . $path;
}

/**
 * Build Claude streaming payload.
 */
function build_sse_payload_logic(
    ClaudeProviderStrategy $strategyInstance,
    array $messages,
    $system_instruction,
    array $ai_params,
    string $model
): array {
    $instructions = is_string($system_instruction) ? $system_instruction : '';
    return build_claude_payload_shared($instructions, $messages, '', $ai_params, $model, true);
}

/**
 * Normalize one Claude citation payload into a stable, frontend-safe structure.
 *
 * @param array<string, mixed> $citation
 * @param array<string, mixed> $extra
 * @return array<string, mixed>|null
 */
function normalize_claude_citation_logic_for_response_parser(array $citation, array $extra = []): ?array
{
    $normalized = [];
    $string_fields = [
        'type',
        'cited_text',
        'document_title',
        'title',
        'website_title',
        'source_title',
        'url',
    ];
    $integer_fields = [
        'document_index',
        'start_char_index',
        'end_char_index',
        'start_page_number',
        'end_page_number',
        'start_block_index',
        'end_block_index',
        'content_block_index',
    ];

    foreach ($string_fields as $field) {
        if (isset($citation[$field]) && is_string($citation[$field]) && trim($citation[$field]) !== '') {
            $normalized[$field] = trim($citation[$field]);
        }
    }

    if (!isset($normalized['url']) && isset($citation['source']) && is_array($citation['source'])) {
        if (isset($citation['source']['url']) && is_string($citation['source']['url']) && trim($citation['source']['url']) !== '') {
            $normalized['url'] = trim($citation['source']['url']);
        }
        if (!isset($normalized['title']) && isset($citation['source']['title']) && is_string($citation['source']['title']) && trim($citation['source']['title']) !== '') {
            $normalized['title'] = trim($citation['source']['title']);
        }
    }

    foreach ($integer_fields as $field) {
        $value = $extra[$field] ?? ($citation[$field] ?? null);
        if ($value === null || $value === '') {
            continue;
        }
        if (is_numeric($value)) {
            $normalized[$field] = (int) $value;
        }
    }

    if (empty($normalized)) {
        return null;
    }

    $has_meaningful_reference = isset($normalized['cited_text'])
        || isset($normalized['url'])
        || isset($normalized['document_title'])
        || isset($normalized['title'])
        || isset($normalized['document_index']);

    return $has_meaningful_reference ? $normalized : null;
}

/**
 * Extract citations from a Claude text content block.
 *
 * @param array<string, mixed> $block
 * @param array<string, mixed> $extra
 * @return array<int, array<string, mixed>>
 */
function extract_claude_citations_from_text_block_logic_for_response_parser(array $block, array $extra = []): array
{
    if (($block['type'] ?? '') !== 'text' || empty($block['citations']) || !is_array($block['citations'])) {
        return [];
    }

    $citations = [];
    foreach ($block['citations'] as $citation) {
        if (!is_array($citation)) {
            continue;
        }
        $normalized = normalize_claude_citation_logic_for_response_parser($citation, $extra);
        if ($normalized !== null) {
            $citations[] = $normalized;
        }
    }

    return $citations;
}

/**
 * Extract citations from a Claude streaming delta payload.
 *
 * @param array<string, mixed> $delta
 * @param array<string, mixed> $extra
 * @return array<int, array<string, mixed>>
 */
function extract_claude_citations_from_delta_logic_for_response_parser(array $delta, array $extra = []): array
{
    $raw_citations = [];

    if (isset($delta['citation']) && is_array($delta['citation'])) {
        $raw_citations[] = $delta['citation'];
    }
    if (isset($delta['citations']) && is_array($delta['citations'])) {
        foreach ($delta['citations'] as $citation) {
            if (is_array($citation)) {
                $raw_citations[] = $citation;
            }
        }
    }

    if (empty($raw_citations)) {
        return [];
    }

    $citations = [];
    foreach ($raw_citations as $citation) {
        $normalized = normalize_claude_citation_logic_for_response_parser($citation, $extra);
        if ($normalized !== null) {
            $citations[] = $normalized;
        }
    }

    return $citations;
}

/**
 * Build non-stream Claude payload.
 */
function format_chat_payload_logic(
    ClaudeProviderStrategy $strategyInstance,
    string $user_message,
    string $instructions,
    array $history,
    array $ai_params,
    string $model
): array {
    return build_claude_payload_shared($instructions, $history, $user_message, $ai_params, $model, false);
}

/**
 * Build Anthropic request headers.
 */
function get_api_headers_logic(ClaudeProviderStrategy $strategyInstance, string $api_key, string $operation): array {
    $anthropic_version = '2023-06-01';

    if (class_exists('\WPAICG\AIPKit_Providers')) {
        $provider_data = \WPAICG\AIPKit_Providers::get_provider_data('Claude');
        if (!empty($provider_data['api_version'])) {
            $anthropic_version = sanitize_text_field((string) $provider_data['api_version']);
        }
    }

    $headers = [
        'Content-Type' => 'application/json',
        'x-api-key' => $api_key,
        'anthropic-version' => $anthropic_version,
    ];

    // Note: files beta header is injected conditionally by callers only when
    // the payload contains Claude document blocks sourced from Files API.

    if ($operation === 'stream') {
        $headers['Accept'] = 'text/event-stream';
        $headers['Cache-Control'] = 'no-cache';
    }

    return $headers;
}

/**
 * Fetch Claude models.
 * @return mixed[]|\WP_Error
 */
function get_models_logic(ClaudeProviderStrategy $strategyInstance, array $api_params) {
    $url = $strategyInstance->build_api_url('models', $api_params);
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

    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($status_code !== 200) {
        $error_msg = $strategyInstance->parse_error_response($body, $status_code);
        return new WP_Error(
            'api_error_claude_models',
            sprintf('Claude API Error (HTTP %d): %s', $status_code, esc_html($error_msg))
        );
    }

    $decoded = $strategyInstance->decode_json($body, 'Claude Models');
    if (is_wp_error($decoded)) {
        return $decoded;
    }

    $raw_models = $decoded['data'] ?? [];
    if (!is_array($raw_models)) {
        return [];
    }

    return $strategyInstance->format_model_list($raw_models, 'id', 'display_name');
}

/**
 * Map one normalized Claude SSE event into an internal typed event.
 *
 * @param array<string, mixed> $decoded_event
 * @return array<string, mixed>
 */
function map_sse_event_logic_for_response_parser(array $decoded_event): array
{
    $event_type = isset($decoded_event['event']) && is_string($decoded_event['event']) ? $decoded_event['event'] : 'message';
    $payload = isset($decoded_event['payload']) && is_array($decoded_event['payload']) ? $decoded_event['payload'] : [];

    if ($event_type === '[DONE]') {
        return [
            'kind' => 'done',
            'event' => $event_type,
        ];
    }

    if ($event_type === 'ping') {
        return [
            'kind' => 'skip',
            'event' => $event_type,
        ];
    }

    if ($event_type === 'error' || isset($payload['error'])) {
        return [
            'kind' => 'error',
            'event' => $event_type,
            'message' => parse_claude_stream_error_message_logic_for_response_parser($payload),
        ];
    }

    switch ($event_type) {
        case 'message_start':
            return [
                'kind' => 'message_start',
                'event' => $event_type,
                'usage' => extract_claude_sse_usage_logic_for_response_parser($payload),
                'status' => build_claude_sse_status_logic_for_response_parser($event_type, $payload),
            ];

        case 'message_delta':
            $warning_text = null;
            $stop_reason = $payload['delta']['stop_reason'] ?? null;
            if ($stop_reason === 'max_tokens') {
                $warning_text = __('Claude stopped because the maximum output token limit was reached.', 'gpt3-ai-content-generator');
            }

            return [
                'kind' => 'message_delta',
                'event' => $event_type,
                'usage' => extract_claude_sse_usage_logic_for_response_parser($payload),
                'status' => build_claude_sse_status_logic_for_response_parser($event_type, $payload),
                'warning_text' => $warning_text,
            ];

        case 'message_stop':
            return [
                'kind' => 'completion',
                'event' => $event_type,
                'status' => build_claude_sse_status_logic_for_response_parser($event_type, $payload),
            ];

        case 'content_block_start':
            $content_block = isset($payload['content_block']) && is_array($payload['content_block'])
                ? $payload['content_block']
                : [];
            $tool_error_message = extract_claude_tool_error_message_logic_for_response_parser($content_block);
            if ($tool_error_message !== null) {
                return [
                    'kind' => 'error',
                    'event' => $event_type,
                    'message' => $tool_error_message,
                ];
            }

            $content_block_type = $content_block['type'] ?? '';
            if (in_array($content_block_type, ['tool_use', 'server_tool_use', 'web_search_tool_result', 'thinking'], true)) {
                return [
                    'kind' => 'status',
                    'event' => $event_type,
                    'status' => build_claude_sse_status_logic_for_response_parser($event_type, $payload),
                ];
            }

            return [
                'kind' => 'skip',
                'event' => $event_type,
            ];

        case 'content_block_delta':
            $delta = isset($payload['delta']) && is_array($payload['delta']) ? $payload['delta'] : [];
            $delta_type = $delta['type'] ?? '';

            if ($delta_type === 'text_delta' && isset($delta['text']) && $delta['text'] !== '') {
                return [
                    'kind' => 'delta',
                    'event' => $event_type,
                    'text' => (string) $delta['text'],
                ];
            }

            if ($delta_type === 'citations_delta') {
                $citations = extract_claude_citations_from_delta_logic_for_response_parser(
                    $delta,
                    isset($payload['index']) ? ['content_block_index' => (int) $payload['index']] : []
                );

                if (!empty($citations)) {
                    return [
                        'kind' => 'citations',
                        'event' => $event_type,
                        'citations' => $citations,
                    ];
                }
            }

            if (in_array($delta_type, ['input_json_delta', 'thinking_delta', 'signature_delta', 'citations_delta'], true)) {
                return [
                    'kind' => 'skip',
                    'event' => $event_type,
                ];
            }

            return [
                'kind' => 'skip',
                'event' => $event_type,
            ];

        case 'content_block_stop':
        default:
            return [
                'kind' => 'skip',
                'event' => $event_type,
            ];
    }
}

/**
 * Build a compact status payload for Claude tool/search lifecycle events.
 *
 * @param string $event_type
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function build_claude_sse_status_logic_for_response_parser(string $event_type, array $payload): array
{
    $status = ['type' => $event_type];

    if (isset($payload['message']['id'])) {
        $status['message_id'] = $payload['message']['id'];
    }
    if (isset($payload['message']['model'])) {
        $status['model'] = $payload['message']['model'];
    }
    if (isset($payload['index'])) {
        $status['index'] = $payload['index'];
    }
    if (isset($payload['delta']['stop_reason'])) {
        $status['stop_reason'] = $payload['delta']['stop_reason'];
    }
    if (isset($payload['delta']['stop_sequence'])) {
        $status['stop_sequence'] = $payload['delta']['stop_sequence'];
    }
    if (isset($payload['content_block']['type'])) {
        $status['content_block_type'] = $payload['content_block']['type'];
    }
    if (isset($payload['content_block']['name'])) {
        $status['name'] = $payload['content_block']['name'];
    }
    if (isset($payload['content_block']['id'])) {
        $status['tool_use_id'] = $payload['content_block']['id'];
    }
    if (isset($payload['content_block']['tool_use_id'])) {
        $status['tool_use_id'] = $payload['content_block']['tool_use_id'];
    }

    return $status;
}

/**
 * Normalize Claude usage data into the shared public parse shape.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>|null
 */
function extract_claude_sse_usage_logic_for_response_parser(array $payload): ?array
{
    $usage = null;

    if (isset($payload['message']['usage']) && is_array($payload['message']['usage'])) {
        $usage = $payload['message']['usage'];
    } elseif (isset($payload['usage']) && is_array($payload['usage'])) {
        $usage = $payload['usage'];
    }

    if ($usage === null) {
        return null;
    }

    $input_tokens = isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : null;
    $output_tokens = isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : null;
    $total_tokens = isset($usage['total_tokens']) ? (int) $usage['total_tokens'] : null;

    return [
        'input_tokens' => $input_tokens,
        'output_tokens' => $output_tokens,
        'total_tokens' => $total_tokens,
        'provider_raw' => $usage,
    ];
}

/**
 * Parse Claude in-stream error payloads without depending on the provider strategy object.
 *
 * @param array<string, mixed> $payload
 * @return string
 */
function parse_claude_stream_error_message_logic_for_response_parser(array $payload): string
{
    $message = __('An unknown API error occurred.', 'gpt3-ai-content-generator');

    if (!empty($payload['error']['message'])) {
        $message = (string) $payload['error']['message'];
        if (!empty($payload['error']['type'])) {
            $message .= ' Type: ' . (string) $payload['error']['type'];
        }
    } elseif (!empty($payload['message'])) {
        $message = (string) $payload['message'];
    }

    return trim($message);
}

/**
 * Extract a user-facing tool error message from Claude content blocks when Anthropic returns a 200 body with a tool error.
 *
 * @param array<string, mixed> $content_block
 * @return string|null
 */
function extract_claude_tool_error_message_logic_for_response_parser(array $content_block): ?string
{
    if (($content_block['type'] ?? '') !== 'web_search_tool_result') {
        return null;
    }

    $content = $content_block['content'] ?? null;
    $error_payload = null;

    if (is_array($content) && isset($content['type']) && $content['type'] === 'web_search_tool_result_error') {
        $error_payload = $content;
    } elseif (is_array($content)) {
        foreach ($content as $item) {
            if (is_array($item) && (($item['type'] ?? '') === 'web_search_tool_result_error')) {
                $error_payload = $item;
                break;
            }
        }
    }

    if ($error_payload === null) {
        return null;
    }

    $error_code = isset($error_payload['error_code']) ? (string) $error_payload['error_code'] : 'unknown';

    switch ($error_code) {
        case 'too_many_requests':
            $detail = __('rate limit exceeded', 'gpt3-ai-content-generator');
            break;
        case 'invalid_input':
            $detail = __('invalid search query', 'gpt3-ai-content-generator');
            break;
        case 'max_uses_exceeded':
            $detail = __('maximum web search uses exceeded', 'gpt3-ai-content-generator');
            break;
        case 'query_too_long':
            $detail = __('search query is too long', 'gpt3-ai-content-generator');
            break;
        case 'unavailable':
            $detail = __('service temporarily unavailable', 'gpt3-ai-content-generator');
            break;
        default:
            $detail = str_replace('_', ' ', $error_code);
            break;
    }

    return sprintf(
        /* translators: %s: human-readable Claude web search error detail. */
        __('Claude web search failed: %s.', 'gpt3-ai-content-generator'),
        $detail
    );
}

/**
 * Parse Claude non-stream response.
 * @return mixed[]|\WP_Error
 */
function parse_chat_response_logic(
    ClaudeProviderStrategy $strategyInstance,
    array $decoded_response,
    array $request_data
) {
    if (isset($decoded_response['error'])) {
        return new WP_Error(
            'claude_api_error',
            $strategyInstance->parse_error_response($decoded_response, 500)
        );
    }

    $content_parts = [];
    $citations = [];
    if (!empty($decoded_response['content']) && is_array($decoded_response['content'])) {
        foreach ($decoded_response['content'] as $block) {
            if (!is_array($block)) {
                continue;
            }
            $tool_error_message = extract_claude_tool_error_message_logic_for_response_parser($block);
            if ($tool_error_message !== null) {
                return new WP_Error(
                    'claude_tool_error',
                    $tool_error_message
                );
            }
            if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
                $content_parts[] = (string) $block['text'];
                $citations = array_merge(
                    $citations,
                    extract_claude_citations_from_text_block_logic_for_response_parser($block)
                );
            }
        }
    }

    $content = trim(implode('', $content_parts));
    if ($content === '') {
        return new WP_Error(
            'invalid_response_structure_claude',
            __('Unexpected response structure from Claude API.', 'gpt3-ai-content-generator')
        );
    }

    $usage = null;
    if (!empty($decoded_response['usage']) && is_array($decoded_response['usage'])) {
        $input_tokens = (int) ($decoded_response['usage']['input_tokens'] ?? 0);
        $output_tokens = (int) ($decoded_response['usage']['output_tokens'] ?? 0);
        $usage = [
            'input_tokens' => $input_tokens,
            'output_tokens' => $output_tokens,
            'total_tokens' => $input_tokens + $output_tokens,
            'provider_raw' => $decoded_response['usage'],
        ];
    }

    $return_data = [
        'content' => $content,
        'usage' => $usage,
    ];

    if (!empty($citations)) {
        $return_data['citations'] = $citations;
    }

    return $return_data;
}

/**
 * Parse Claude error response.
 */
function parse_error_response_logic(ClaudeProviderStrategy $strategyInstance, $response_body, int $status_code): string {
    $message = __('An unknown API error occurred.', 'gpt3-ai-content-generator');
    $decoded = is_string($response_body) ? json_decode($response_body, true) : $response_body;

    if (is_array($decoded)) {
        if (!empty($decoded['error']['message'])) {
            $message = (string) $decoded['error']['message'];
            if (!empty($decoded['error']['type'])) {
                $message .= ' Type: ' . (string) $decoded['error']['type'];
            }
        } elseif (!empty($decoded['message'])) {
            $message = (string) $decoded['message'];
        }
    } elseif (is_string($response_body)) {
        $message = substr($response_body, 0, 300);
    }

    return trim($message);
}


/**
 * Parse Claude SSE chunks.
 */
function parse_sse_chunk_logic(
    ClaudeProviderStrategy $strategyInstance,
    string $sse_chunk,
    string &$current_buffer
): array {
    $current_buffer .= $sse_chunk;

    $result = [
        'delta' => null,
        'usage' => null,
        'is_error' => false,
        'is_warning' => false,
        'is_done' => false,
        'status' => null,
        'citations' => null,
    ];

    foreach (extract_sse_event_blocks($current_buffer) as $event_block) {
        $decoded_event = decode_event_type_sse_event_block($event_block);
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
 * Apply an internal Claude event to the flattened parse result used by the stream processor.
 *
 * @param array<string, mixed> $mapped_event
 * @param array<string, mixed> $result
 * @return bool True when parsing should stop immediately.
 */
function reduce_sse_event_logic_for_response_parser(array $mapped_event, array &$result): bool
{
    $kind = isset($mapped_event['kind']) && is_string($mapped_event['kind']) ? $mapped_event['kind'] : 'skip';

    switch ($kind) {
        case 'message_start':
        case 'message_delta':
            if (isset($mapped_event['usage']) && is_array($mapped_event['usage'])) {
                $result['usage'] = merge_claude_sse_usage_logic_for_response_parser(
                    isset($result['usage']) && is_array($result['usage']) ? $result['usage'] : null,
                    $mapped_event['usage']
                );
            }
            if (isset($mapped_event['status']) && is_array($mapped_event['status'])) {
                $result['status'] = $mapped_event['status'];
            }
            if (!empty($mapped_event['warning_text']) && is_string($mapped_event['warning_text'])) {
                if ($result['delta'] === null) {
                    $result['delta'] = '';
                }
                $result['delta'] .= $mapped_event['warning_text'];
                $result['is_warning'] = true;
            }
            return false;

        case 'status':
            if (isset($mapped_event['status']) && is_array($mapped_event['status'])) {
                $result['status'] = $mapped_event['status'];
            }
            return false;

        case 'delta':
            $text = isset($mapped_event['text']) ? (string) $mapped_event['text'] : '';
            if ($text !== '') {
                if ($result['delta'] === null) {
                    $result['delta'] = '';
                }
                $result['delta'] .= $text;
            }
            return false;

        case 'citations':
            if (isset($mapped_event['citations']) && is_array($mapped_event['citations']) && !empty($mapped_event['citations'])) {
                $existing_citations = isset($result['citations']) && is_array($result['citations'])
                    ? $result['citations']
                    : [];
                $result['citations'] = array_merge($existing_citations, $mapped_event['citations']);
            }
            return false;

        case 'warning':
            if (isset($mapped_event['status']) && is_array($mapped_event['status'])) {
                $result['status'] = $mapped_event['status'];
            }
            $text = isset($mapped_event['text']) ? (string) $mapped_event['text'] : '';
            if ($text !== '') {
                if ($result['delta'] === null) {
                    $result['delta'] = '';
                }
                $result['delta'] .= $text;
                $result['is_warning'] = true;
            }
            return false;

        case 'completion':
            $result['is_done'] = true;
            if (isset($mapped_event['status']) && is_array($mapped_event['status'])) {
                $result['status'] = $mapped_event['status'];
            }
            return false;

        case 'error':
            $result['delta'] = isset($mapped_event['message']) ? (string) $mapped_event['message'] : '';
            $result['is_error'] = true;
            return true;

        case 'done':
            $result['is_done'] = true;
            return false;

        default:
            return false;
    }
}

/**
 * Merge Claude usage updates while preserving input token counts from message_start when later cumulative updates omit them.
 *
 * @param array<string, mixed>|null $existing_usage
 * @param array<string, mixed> $incoming_usage
 * @return array<string, mixed>
 */
function merge_claude_sse_usage_logic_for_response_parser(?array $existing_usage, array $incoming_usage): array
{
    $existing_raw = isset($existing_usage['provider_raw']) && is_array($existing_usage['provider_raw'])
        ? $existing_usage['provider_raw']
        : [];
    $incoming_raw = isset($incoming_usage['provider_raw']) && is_array($incoming_usage['provider_raw'])
        ? $incoming_usage['provider_raw']
        : [];

    $input_tokens = null;
    if (array_key_exists('input_tokens', $incoming_raw)) {
        $input_tokens = (int) $incoming_raw['input_tokens'];
    } elseif ($existing_usage !== null && isset($existing_usage['input_tokens'])) {
        $input_tokens = (int) $existing_usage['input_tokens'];
    }

    $output_tokens = null;
    if (array_key_exists('output_tokens', $incoming_raw)) {
        $output_tokens = (int) $incoming_raw['output_tokens'];
    } elseif ($existing_usage !== null && isset($existing_usage['output_tokens'])) {
        $output_tokens = (int) $existing_usage['output_tokens'];
    }

    $total_tokens = null;
    if (array_key_exists('total_tokens', $incoming_raw)) {
        $total_tokens = (int) $incoming_raw['total_tokens'];
    } elseif ($input_tokens !== null || $output_tokens !== null) {
        $total_tokens = (int) ($input_tokens ?? 0) + (int) ($output_tokens ?? 0);
    }

    return [
        'input_tokens' => $input_tokens ?? 0,
        'output_tokens' => $output_tokens ?? 0,
        'total_tokens' => $total_tokens ?? 0,
        'provider_raw' => array_merge($existing_raw, $incoming_raw),
    ];
}
