<?php

namespace WPAICG\Core\Providers\OpenRouter;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/contracts.php';
require_once __DIR__ . '/chat-completions.php';

class OpenRouterUrlBuilder {

    /**
     * @return string|\WP_Error
     */
    public static function build(string $operation, array $params) {
        return Methods\build_logic_for_url_builder($operation, $params);
    }
}

class OpenRouterPayloadFormatter {

    public static function format_chat(string $instructions, array $history, array $ai_params, string $model): array {
        return Methods\format_chat_logic_for_payload_formatter($instructions, $history, $ai_params, $model);
    }

    public static function format_sse(array $messages, string $system_instruction, array $ai_params, string $model): array {
        return Methods\format_sse_logic_for_payload_formatter($messages, $system_instruction, $ai_params, $model);
    }
}

class OpenRouterResponseParser {

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
}

namespace WPAICG\Core\Providers\OpenRouter\Methods;

use WPAICG\Core\Providers\OpenRouterProviderStrategy;
use WP_Error;
use function WPAICG\Core\Providers\Shared\extract_sse_event_blocks;
use function WPAICG\Core\Providers\Shared\decode_event_type_sse_event_block;

/**
 * Attach image inputs to the latest user message in OpenRouter Responses input format.
 *
 * @param array $input_array Existing input message array.
 * @param array $image_inputs Image payload array from chat/frontend flow.
 * @return array
 */
function _openrouter_attach_image_inputs(array $input_array, array $image_inputs): array {
    if (empty($image_inputs)) {
        return $input_array;
    }

    $last_key = array_key_last($input_array);
    if ($last_key === null || !isset($input_array[$last_key]['role']) || $input_array[$last_key]['role'] !== 'user') {
        return $input_array;
    }

    $current_content = $input_array[$last_key]['content'] ?? '';
    $user_text = '';
    if (is_string($current_content)) {
        $user_text = $current_content;
    } elseif (is_array($current_content)) {
        foreach ($current_content as $part) {
            if (!is_array($part)) {
                continue;
            }
            $part_type = $part['type'] ?? '';
            if (($part_type === 'text' || $part_type === 'input_text') && isset($part['text']) && is_string($part['text'])) {
                $user_text = $part['text'];
                break;
            }
        }
    }

    $content_parts = [];
    $has_valid_image = false;
    if ($user_text !== '') {
        $content_parts[] = [
            'type' => 'input_text',
            'text' => $user_text,
        ];
    }

    foreach ($image_inputs as $image_input) {
        if (!is_array($image_input)) {
            continue;
        }
        $mime_type = isset($image_input['type']) ? sanitize_text_field((string) $image_input['type']) : '';
        $base64_data = isset($image_input['base64']) ? trim((string) $image_input['base64']) : '';
        if ($mime_type === '' || $base64_data === '') {
            continue;
        }

        $content_parts[] = [
            'type' => 'input_image',
            'image_url' => 'data:' . $mime_type . ';base64,' . $base64_data,
        ];
        $has_valid_image = true;
    }

    if ($has_valid_image) {
        $input_array[$last_key]['content'] = $content_parts;
    }

    return $input_array;
}

/**
 * Normalizes a provider-routing toggle without treating an unknown value as
 * an instruction to weaken an existing default.
 *
 * @param mixed  $value Raw setting value.
 * @param string $default Normalized fallback value.
 */
function normalize_routing_toggle_logic($value, string $default): string {
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (!is_scalar($value)) {
        return $default;
    }

    $normalized = strtolower(trim((string) $value));
    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
        return '1';
    }
    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
        return '0';
    }

    return $default;
}

/**
 * Sanitizes one OpenRouter model identifier used as an ordered fallback.
 * OpenRouter IDs may include family aliases (`~`), variants (`:`), and `/`.
 *
 * @param mixed $value Raw model identifier.
 */
function sanitize_routing_model_id_logic($value): string {
    if (!is_scalar($value)) {
        return '';
    }

    $model_id = trim(sanitize_text_field((string) $value));
    if (
        $model_id === ''
        || strlen($model_id) > 200
        || !preg_match('/^[A-Za-z0-9~][A-Za-z0-9._~:\/-]*$/', $model_id)
    ) {
        return '';
    }

    return $model_id;
}

/**
 * Sanitizes the persisted OpenRouter provider-routing settings.
 *
 * @param array<string, mixed> $settings Raw provider settings.
 * @return array<string, string>
 */
function sanitize_routing_settings_logic(array $settings): array {
    $data_collection = isset($settings['data_collection']) && is_scalar($settings['data_collection'])
        ? strtolower(trim((string) $settings['data_collection']))
        : 'allow';
    $normalized = [
        'allow_fallbacks' => normalize_routing_toggle_logic($settings['allow_fallbacks'] ?? '1', '1'),
        'require_parameters' => normalize_routing_toggle_logic($settings['require_parameters'] ?? '0', '0'),
        'data_collection' => $data_collection === 'deny' ? 'deny' : 'allow',
        'zdr' => normalize_routing_toggle_logic($settings['zdr'] ?? '0', '0'),
    ];

    $seen_models = [];
    for ($position = 1; $position <= 3; $position++) {
        $key = 'fallback_model_' . $position;
        $model_id = sanitize_routing_model_id_logic($settings[$key] ?? '');
        $lookup_id = strtolower($model_id);
        if ($model_id !== '' && isset($seen_models[$lookup_id])) {
            $model_id = '';
        }
        if ($model_id !== '') {
            $seen_models[$lookup_id] = true;
        }
        $normalized[$key] = $model_id;
    }

    return $normalized;
}

/**
 * Builds an opaque, site-specific OpenRouter session key for one chatbot
 * conversation. Raw WordPress user, guest, bot, and conversation identifiers
 * are never returned or sent to OpenRouter.
 */
function build_session_stickiness_id_logic($bot_id, $conversation_uuid): string {
    $bot_id = absint($bot_id);
    $conversation_uuid = substr(sanitize_key((string) $conversation_uuid), 0, 191);
    if ($bot_id <= 0 || $conversation_uuid === '' || !function_exists('wp_salt')) {
        return '';
    }

    $site_secret = (string) wp_salt('auth');
    if ($site_secret === '') {
        return '';
    }

    return 'aipkit_chat_' . hash_hmac(
        'sha256',
        $bot_id . '|' . $conversation_uuid,
        $site_secret
    );
}

/**
 * Builds the OpenRouter Responses extensions for routing and model failover.
 * Default-valued provider preferences are omitted so upgraded sites retain
 * the gateway's existing behavior until an administrator opts into a policy.
 *
 * @param array<string, mixed> $settings Raw provider settings.
 * @param array<string, mixed> $request_context Internal chatbot request context.
 * @return array<string, mixed>
 */
function build_routing_request_options_logic(array $settings, string $primary_model, array $request_context = []): array {
    $settings = sanitize_routing_settings_logic($settings);
    $provider_preferences = [];

    if ($settings['allow_fallbacks'] === '0') {
        $provider_preferences['allow_fallbacks'] = false;
    }
    if ($settings['require_parameters'] === '1') {
        $provider_preferences['require_parameters'] = true;
    }
    if ($settings['data_collection'] === 'deny') {
        $provider_preferences['data_collection'] = 'deny';
    }
    if ($settings['zdr'] === '1') {
        $provider_preferences['zdr'] = true;
    }

    $request_options = [];
    if (!empty($provider_preferences)) {
        $request_options['provider'] = $provider_preferences;
    }

    $primary_lookup = strtolower(trim($primary_model));
    $fallback_models = [];
    for ($position = 1; $position <= 3; $position++) {
        $model_id = $settings['fallback_model_' . $position];
        if ($model_id === '' || strtolower($model_id) === $primary_lookup) {
            continue;
        }
        $fallback_models[] = $model_id;
    }
    if (!empty($fallback_models)) {
        $request_options['models'] = $fallback_models;
    }

    $session_id = build_session_stickiness_id_logic(
        $request_context['bot_id'] ?? 0,
        $request_context['conversation_uuid'] ?? ''
    );
    if ($session_id !== '') {
        $request_options['session_id'] = $session_id;
    }

    return $request_options;
}

/**
 * Reads the global OpenRouter policy and returns request-ready extensions.
 *
 * @param array<string, mixed> $request_context Internal chatbot request context.
 * @return array<string, mixed>
 */
function get_saved_routing_request_options_logic(string $primary_model, array $request_context = []): array {
    $settings = [];
    if (class_exists(\WPAICG\AIPKit_Providers::class)) {
        $provider_data = \WPAICG\AIPKit_Providers::get_provider_data('OpenRouter');
        if (is_array($provider_data)) {
            $settings = $provider_data;
        }
    }

    return build_routing_request_options_logic($settings, $primary_model, $request_context);
}

/**
 * Returns the subset of routing preferences supported by the dedicated Image
 * API. Privacy, strict-parameter, and cross-model fallback controls are text
 * Responses features and must not leak into the image request contract.
 *
 * @return array<string, mixed>
 */
function get_saved_image_routing_preferences_logic(): array {
    $settings = [];
    if (class_exists(\WPAICG\AIPKit_Providers::class)) {
        $provider_data = \WPAICG\AIPKit_Providers::get_provider_data('OpenRouter');
        if (is_array($provider_data)) {
            $settings = $provider_data;
        }
    }

    $settings = sanitize_routing_settings_logic($settings);
    return $settings['allow_fallbacks'] === '0'
        ? ['allow_fallbacks' => false]
        : [];
}

/**
 * Shared formatting logic for OpenRouter Responses API payloads.
 *
 * @param string $instructions System instructions.
 * @param array  $history Conversation history.
 * @param array  $ai_params AI parameters.
 * @param string $model Model name.
 * @return array The formatted payload base.
 */
function _shared_format_logic(string $instructions, array $history, array $ai_params, string $model): array {
    $input_array = [];
    if (!empty($instructions)) {
        $input_array[] = ['role' => 'system', 'content' => $instructions];
    }

    foreach ($history as $msg) {
        if (!is_array($msg)) {
            continue;
        }
        $raw_role = $msg['role'] ?? '';
        $role = ($raw_role === 'bot') ? 'assistant' : $raw_role;
        if (!in_array($role, ['system', 'user', 'assistant', 'developer'], true)) {
            continue;
        }
        if ($role === 'system' && !empty($instructions)) {
            continue;
        }

        $content = $msg['content'] ?? '';
        if (is_string($content)) {
            $content = trim($content);
            if ($content === '') {
                continue;
            }
        } elseif (is_array($content)) {
            if (empty($content)) {
                continue;
            }
        } else {
            continue;
        }

        $input_array[] = ['role' => $role, 'content' => $content];
    }

    if (!empty($ai_params['image_inputs']) && is_array($ai_params['image_inputs'])) {
        $input_array = _openrouter_attach_image_inputs($input_array, $ai_params['image_inputs']);
    }

    // OpenRouter Responses is stateless: send the complete input on every call
    // and intentionally do not forward OpenAI `previous_response_id` or `store` state.
    $body_data = [
        'model' => $model,
        'input' => $input_array,
    ];

    $openrouter_session_context = isset($ai_params['openrouter_session_context'])
        && is_array($ai_params['openrouter_session_context'])
        ? $ai_params['openrouter_session_context']
        : [];
    foreach (get_saved_routing_request_options_logic($model, $openrouter_session_context) as $routing_key => $routing_value) {
        $body_data[$routing_key] = $routing_value;
    }

    $param_map = [
        'temperature'           => 'temperature',
        'max_completion_tokens' => 'max_output_tokens',
        'top_p'                 => 'top_p',
        'top_k'                 => 'top_k',
        'presence_penalty'      => 'presence_penalty',
        'frequency_penalty'     => 'frequency_penalty',
        'top_logprobs'          => 'top_logprobs',
    ];

    foreach ($param_map as $aipkit_key => $api_key) {
        if (!isset($ai_params[$aipkit_key])) {
            continue;
        }

        $value = $ai_params[$aipkit_key];
        if ($api_key === 'max_output_tokens' || $api_key === 'top_logprobs') {
            $body_data[$api_key] = absint($value);
            continue;
        }

        $body_data[$api_key] = floatval($value);
    }

    if (isset($ai_params['reasoning']) && is_array($ai_params['reasoning'])) {
        $reasoning = sanitize_reasoning_request_logic($model, $ai_params['reasoning']);
        if (!empty($reasoning)) {
            $body_data['reasoning'] = $reasoning;
        }
    }

    $capability_map = get_capability_map_logic();
    $model_supports_web_search = model_supports_web_search_tool_logic($model);
    $bot_allows_web_search = !empty($ai_params['web_search_tool_config']['enabled']);
    $frontend_requests_web_search = !empty($ai_params['frontend_web_search_active']);
    if (
        !empty($capability_map['web_search_tool']) &&
        $model_supports_web_search &&
        $bot_allows_web_search &&
        $frontend_requests_web_search
    ) {
        $web_search_tool = ['type' => 'openrouter:web_search'];
        $web_search_parameters = sanitize_web_search_tool_config_logic(
            is_array($ai_params['web_search_tool_config'] ?? null)
                ? $ai_params['web_search_tool_config']
                : []
        );
        if (!empty($web_search_parameters)) {
            $web_search_tool['parameters'] = $web_search_parameters;
        }
        $body_data['tools'] = [$web_search_tool];
    }

    return $body_data;
}

/**
 * Logic for the build_api_url method of OpenRouterProviderStrategy.
 *
 * @param OpenRouterProviderStrategy $strategyInstance The instance of the strategy class.
 * @param string $operation ('chat', 'models', 'stream')
 * @param array  $params Required parameters (base_url, api_version).
 * @return string|WP_Error The full URL or WP_Error.
 */
function build_api_url_logic(OpenRouterProviderStrategy $strategyInstance, string $operation, array $params) {
    return \WPAICG\Core\Providers\OpenRouter\OpenRouterUrlBuilder::build($operation, $params);
}

/**
 * Logic for the build_sse_payload method of OpenRouterProviderStrategy.
 *
 * @param OpenRouterProviderStrategy $strategyInstance The instance of the strategy class.
 * @param array $messages Formatted messages/input/contents array.
 * @param string|array|null $system_instruction Formatted system instruction.
 * @param array $ai_params AI parameters.
 * @param string $model Target model/deployment.
 * @return array The formatted request body data for SSE.
 */
function build_sse_payload_logic(
    OpenRouterProviderStrategy $strategyInstance,
    array $messages,
    $system_instruction,
    array $ai_params,
    string $model
): array {
    return \WPAICG\Core\Providers\OpenRouter\OpenRouterPayloadFormatter::format_sse($messages, $system_instruction, $ai_params, $model);
}

/**
 * Logic for the build static method of OpenRouterUrlBuilder.
 *
 * @param string $operation ('chat', 'models', 'stream', 'embeddings', 'embedding_models', 'image_models')
 * @param array  $params Required parameters (base_url, api_version).
 * @return string|WP_Error The full URL or WP_Error.
 */
function build_logic_for_url_builder(string $operation, array $params) {
    $base_url = !empty($params['base_url']) ? rtrim($params['base_url'], '/') : '';
    $api_version = !empty($params['api_version']) ? $params['api_version'] : '';

    if (empty($base_url)) return new WP_Error("missing_base_url_OpenRouter_logic", __('OpenRouter Base URL is required.', 'gpt3-ai-content-generator'));

    $paths = [
        'responses'        => '/responses',
        'models'           => '/models',
        'embeddings'       => '/embeddings',
        'embedding_models' => '/embeddings/models',
        'image_models'     => '/images/models',
    ];

    // OpenRouter runtime chat/stream now targets Responses API.
    $path_key = ($operation === 'chat' || $operation === 'stream') ? 'responses' : $operation;
    $path_segment = $paths[$path_key] ?? null;

    if ($path_segment === null) {
        // translators: %s is the operation name (e.g., "chat" or "stream")
        return new WP_Error('unsupported_operation_OpenRouter_logic', sprintf(__('Operation "%s" not supported for OpenRouter.', 'gpt3-ai-content-generator'), $operation));
    }

    $full_path = $path_segment;
    // Prepend version if it's provided and *not* already in the base URL
    if (!empty($api_version) && strpos($base_url, '/' . trim($api_version, '/')) === false) {
        $full_path = '/' . trim($api_version, '/') . $path_segment;
    }

    return $base_url . $full_path;
}

/**
 * Returns default OpenRouter provider capability map used for runtime guards.
 *
 * Important: this is provider-level default. Model-level resolution may narrow
 * capabilities when synced metadata explicitly indicates limitations.
 *
 * @return array<string, bool>
 */
function get_capability_map_logic(): array {
    return [
        'chat' => true,
        'stream' => true,
        'reasoning' => false,
        'tools' => false,
        'web_search_tool' => true,
        'image_input' => false,
        'image_output' => false,
        'image_generation' => false,
        'embeddings' => false,
    ];
}

/**
 * Sanitizes OpenRouter's model-level reasoning descriptor.
 *
 * A null supported_efforts value means the gateway accepts every normalized
 * effort. An omitted supported_efforts key means the model does not expose an
 * effort selector, even when reasoning itself may be enabled by default.
 *
 * @param mixed $value Raw reasoning metadata.
 * @return array<string, mixed>
 */
function sanitize_reasoning_metadata_logic($value): array {
    if (!is_array($value)) {
        return [];
    }

    $normalized = [];
    foreach (['mandatory', 'default_enabled', 'supports_max_tokens'] as $boolean_key) {
        if (array_key_exists($boolean_key, $value)) {
            $normalized[$boolean_key] = (bool) $value[$boolean_key];
        }
    }

    $allowed_api_efforts = ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];
    if (array_key_exists('supported_efforts', $value)) {
        if ($value['supported_efforts'] === null) {
            $normalized['supported_efforts'] = null;
        } elseif (is_array($value['supported_efforts'])) {
            $supported_efforts = [];
            foreach ($value['supported_efforts'] as $effort) {
                if (!is_string($effort)) {
                    continue;
                }
                $effort = sanitize_key($effort);
                if (in_array($effort, $allowed_api_efforts, true)) {
                    $supported_efforts[] = $effort;
                }
            }
            $normalized['supported_efforts'] = array_values(array_unique($supported_efforts));
        }
    }

    if (isset($value['default_effort']) && is_string($value['default_effort'])) {
        $default_effort = sanitize_key($value['default_effort']);
        if (in_array($default_effort, $allowed_api_efforts, true)) {
            $normalized['default_effort'] = $default_effort;
        }
    }

    return $normalized;
}

/**
 * Maps OpenRouter's extended effort vocabulary onto the plugin's stable UI.
 */
function map_api_reasoning_effort_to_ui_logic(string $effort): string {
    if ($effort === 'minimal') {
        return 'low';
    }
    if ($effort === 'max') {
        return 'xhigh';
    }
    return $effort;
}

/**
 * Builds the reasoning-effort UI and request rule from synchronized metadata.
 *
 * @param array<string, mixed> $metadata Model metadata.
 * @return array{supported: bool, allowed: array<int, string>, default: string, mandatory: bool, api_effort_map: array<string, string>}
 */
function get_reasoning_rule_from_metadata_logic(array $metadata): array {
    $unsupported = [
        'supported' => false,
        'allowed' => [],
        'default' => '',
        'mandatory' => false,
        'api_effort_map' => [],
    ];

    $model_id = strtolower(trim((string) ($metadata['id'] ?? '')));
    if (
        $model_id === 'openrouter/auto'
        || $model_id === 'auto'
        || strpos($model_id, 'auto-router') !== false
    ) {
        return $unsupported;
    }

    $has_reasoning_metadata = array_key_exists('reasoning', $metadata);
    $reasoning = $has_reasoning_metadata
        ? sanitize_reasoning_metadata_logic($metadata['reasoning'])
        : [];
    $mandatory = !empty($reasoning['mandatory']);
    $api_effort_map = [];

    if ($has_reasoning_metadata) {
        if (!array_key_exists('supported_efforts', $reasoning)) {
            return $unsupported;
        }

        $api_efforts = $reasoning['supported_efforts'];
        if ($api_efforts === null) {
            $api_efforts = ['low', 'medium', 'high', 'xhigh'];
        }

        foreach ($api_efforts as $api_effort) {
            $ui_effort = map_api_reasoning_effort_to_ui_logic((string) $api_effort);
            if (!in_array($ui_effort, ['low', 'medium', 'high', 'xhigh'], true)) {
                continue;
            }
            // Prefer the exact normalized gateway value when both an alias and
            // the UI value are advertised by the model.
            if (!isset($api_effort_map[$ui_effort]) || $api_effort === $ui_effort) {
                $api_effort_map[$ui_effort] = (string) $api_effort;
            }
        }
    } else {
        $supported_parameters = normalize_capability_list_logic(
            $metadata['supported_parameters']
                ?? ($metadata['supportedParameters']
                    ?? ($metadata['supported_sampling_parameters'] ?? []))
        );
        if (
            !in_array('reasoning', $supported_parameters, true)
            && !in_array('reasoning_effort', $supported_parameters, true)
        ) {
            return $unsupported;
        }
        foreach (['low', 'medium', 'high', 'xhigh'] as $effort) {
            $api_effort_map[$effort] = $effort;
        }
    }

    $effort_values = array_keys($api_effort_map);
    if (empty($effort_values)) {
        return $unsupported;
    }

    $allowed = $mandatory ? $effort_values : array_merge(['none'], $effort_values);
    $default = '';
    if (!empty($reasoning['default_effort'])) {
        $candidate_default = map_api_reasoning_effort_to_ui_logic((string) $reasoning['default_effort']);
        if (in_array($candidate_default, $allowed, true)) {
            $default = $candidate_default;
        }
    }
    if ($default === '' && !$mandatory && empty($reasoning['default_enabled'])) {
        $default = 'none';
    }
    if ($default === '') {
        $default = in_array('medium', $allowed, true) ? 'medium' : $effort_values[0];
    }

    return [
        'supported' => true,
        'allowed' => array_values(array_unique($allowed)),
        'default' => $default,
        'mandatory' => $mandatory,
        'api_effort_map' => $api_effort_map,
    ];
}

/**
 * Resolves the synchronized reasoning-effort rule for an OpenRouter model.
 *
 * @return array{supported: bool, allowed: array<int, string>, default: string, mandatory: bool, api_effort_map: array<string, string>}
 */
function get_reasoning_rule_logic(string $model_id): array {
    $metadata = get_model_metadata_logic($model_id);
    if (!is_array($metadata)) {
        return get_reasoning_rule_from_metadata_logic(['id' => $model_id]);
    }
    return get_reasoning_rule_from_metadata_logic($metadata);
}

/**
 * Normalizes the saved five-level setting to the model's exact API effort.
 */
function normalize_reasoning_effort_logic(string $model_id, $effort): string {
    if (!is_string($effort)) {
        return '';
    }

    $ui_effort = map_api_reasoning_effort_to_ui_logic(sanitize_key($effort));
    $rule = get_reasoning_rule_logic($model_id);
    if (!$rule['supported']) {
        return '';
    }

    if ($ui_effort === 'none') {
        if (!$rule['mandatory']) {
            return 'none';
        }
        $ui_effort = $rule['default'];
    }

    if (!in_array($ui_effort, $rule['allowed'], true)) {
        return '';
    }

    return $rule['api_effort_map'][$ui_effort] ?? $ui_effort;
}

/**
 * Sanitizes the reasoning object before it reaches OpenRouter.
 *
 * @param array<string, mixed> $reasoning Raw request setting.
 * @return array{effort: string}|array{}
 */
function sanitize_reasoning_request_logic(string $model_id, array $reasoning): array {
    $effort = normalize_reasoning_effort_logic($model_id, $reasoning['effort'] ?? '');
    return $effort === '' ? [] : ['effort' => $effort];
}

/**
 * Normalizes a mixed list value into lowercase unique strings.
 *
 * @param mixed $value Raw list value.
 * @return array<int, string>
 */
function normalize_capability_list_logic($value): array {
    if (!is_array($value)) {
        return [];
    }

    $normalized = [];
    foreach ($value as $entry) {
        if (!is_string($entry)) {
            continue;
        }
        $entry = strtolower(trim($entry));
        if ($entry !== '') {
            $normalized[] = $entry;
        }
    }

    return array_values(array_unique($normalized));
}

/**
 * Normalizes capability descriptors returned by OpenRouter's Image Models API.
 *
 * @param mixed $value Raw supported_parameters descriptor map.
 * @return array<string, array<string, mixed>>
 */
function normalize_supported_parameter_schema_logic($value): array {
    if (!is_array($value)) {
        return [];
    }

    $normalized = [];
    foreach ($value as $parameter => $descriptor) {
        if (!is_string($parameter) || !is_array($descriptor)) {
            continue;
        }
        $parameter = strtolower(trim($parameter));
        if ($parameter === '') {
            continue;
        }

        $type = isset($descriptor['type']) && is_string($descriptor['type'])
            ? strtolower(trim($descriptor['type']))
            : '';
        if (!in_array($type, ['enum', 'range', 'boolean'], true)) {
            continue;
        }

        $normalized_descriptor = ['type' => $type];
        if ($type === 'enum') {
            $values = [];
            foreach (($descriptor['values'] ?? []) as $enum_value) {
                if (!is_scalar($enum_value)) {
                    continue;
                }
                $enum_value = sanitize_text_field((string) $enum_value);
                if ($enum_value !== '') {
                    $values[] = $enum_value;
                }
            }
            $values = array_values(array_unique($values));
            if (empty($values)) {
                continue;
            }
            $normalized_descriptor['values'] = $values;
        } elseif ($type === 'range') {
            if (isset($descriptor['min']) && is_numeric($descriptor['min'])) {
                $normalized_descriptor['min'] = 0 + $descriptor['min'];
            }
            if (isset($descriptor['max']) && is_numeric($descriptor['max'])) {
                $normalized_descriptor['max'] = 0 + $descriptor['max'];
            }
        }

        $normalized[$parameter] = $normalized_descriptor;
    }

    return $normalized;
}

/**
 * Sanitizes capability payload from stored metadata.
 *
 * @param mixed $capabilities Raw stored capabilities.
 * @return array<string, bool>
 */
function sanitize_capabilities_payload_logic($capabilities): array {
    if (!is_array($capabilities)) {
        return [];
    }

    // Preserve synchronized model registries created before the OpenRouter
    // server-tool migration without keeping the deprecated key active.
    if (!array_key_exists('web_search_tool', $capabilities) && array_key_exists('web_search_plugin', $capabilities)) {
        $capabilities['web_search_tool'] = (bool) $capabilities['web_search_plugin'];
    }

    $allowed_keys = array_keys(get_capability_map_logic());
    $sanitized = [];
    foreach ($allowed_keys as $key) {
        if (!array_key_exists($key, $capabilities)) {
            continue;
        }
        $sanitized[$key] = (bool) $capabilities[$key];
    }

    return $sanitized;
}

/**
 * Resolves model-level capabilities from OpenRouter model metadata.
 *
 * @param array<string, mixed> $metadata Synchronized model metadata.
 * @return array<string, bool>
 */
function resolve_model_capabilities_from_metadata_logic(array $metadata): array {
    $resolved = get_capability_map_logic();
    // Model-level image output should be opt-in from model metadata signals.
    $resolved['image_output'] = false;
    $resolved['image_generation'] = false;

    // If model already contains normalized capability payload, use it as base.
    $stored_capabilities = sanitize_capabilities_payload_logic($metadata['capabilities'] ?? null);
    if (!empty($stored_capabilities)) {
        $resolved = array_merge($resolved, $stored_capabilities);
    }

    $input_modalities = normalize_capability_list_logic(
        $metadata['input_modalities'] ?? ($metadata['architecture']['input_modalities'] ?? [])
    );
    if (!empty($input_modalities)) {
        $resolved['image_input'] = in_array('image', $input_modalities, true)
            || in_array('image_url', $input_modalities, true)
            || in_array('input_image', $input_modalities, true);
    }

    $output_modalities = normalize_capability_list_logic(
        $metadata['output_modalities'] ?? ($metadata['architecture']['output_modalities'] ?? [])
    );
    if (!empty($output_modalities)) {
        $supports_text_output = in_array('text', $output_modalities, true)
            || in_array('output_text', $output_modalities, true);
        $supports_image_output = in_array('image', $output_modalities, true)
            || in_array('image_url', $output_modalities, true)
            || in_array('output_image', $output_modalities, true);
        $resolved['chat'] = $supports_text_output;
        $resolved['stream'] = $supports_text_output;
        $resolved['image_output'] = $supports_image_output;
        $resolved['image_generation'] = $supports_image_output;
    }

    $supported_features = normalize_capability_list_logic(
        $metadata['supported_features'] ?? ($metadata['supportedFeatures'] ?? [])
    );
    if (!empty($supported_features)) {
        $supports_tools_feature = in_array('tools', $supported_features, true)
            || in_array('tool_use', $supported_features, true)
            || in_array('function_calling', $supported_features, true)
            || in_array('function-calling', $supported_features, true)
            || in_array('plugins', $supported_features, true);

        $supports_web_feature = in_array('web_search', $supported_features, true)
            || in_array('web-search', $supported_features, true)
            || in_array('web', $supported_features, true)
            || $supports_tools_feature;

        $supports_image_generation_feature = in_array('image_generation', $supported_features, true)
            || in_array('image-generation', $supported_features, true);

        // Treat explicit supported_features payload as authoritative for these capabilities.
        $resolved['tools'] = $supports_tools_feature;
        $resolved['web_search_tool'] = $supports_web_feature;
        $resolved['image_generation'] = $supports_image_generation_feature || $resolved['image_generation'];
        $resolved['image_output'] = $resolved['image_generation'];

        if (in_array('embeddings', $supported_features, true) || in_array('embedding', $supported_features, true)) {
            $resolved['embeddings'] = true;
        }
    }

    $supported_parameters = normalize_capability_list_logic(
        $metadata['supported_parameters']
            ?? ($metadata['supportedParameters']
                ?? ($metadata['supported_sampling_parameters'] ?? []))
    );
    if (!empty($supported_parameters)) {
        $supports_tools_param = in_array('plugins', $supported_parameters, true)
            || in_array('tools', $supported_parameters, true)
            || in_array('tool_choice', $supported_parameters, true)
            || in_array('parallel_tool_calls', $supported_parameters, true);

        if (empty($supported_features)) {
            // If we do not have explicit feature metadata, use parameter metadata directly.
            $resolved['tools'] = $supports_tools_param;
            $resolved['web_search_tool'] = $supports_tools_param;
        } else {
            // If feature metadata exists, treat parameter metadata as supplementary.
            $resolved['tools'] = !empty($resolved['tools']) || $supports_tools_param;
            $resolved['web_search_tool'] = !empty($resolved['web_search_tool']) || $supports_tools_param;
        }
    }

    $reasoning_rule = get_reasoning_rule_from_metadata_logic($metadata);
    $resolved['reasoning'] = $reasoning_rule['supported'];

    // Keep compatibility with OpenRouter pricing hints.
    if (array_key_exists('pricing_web_search', $metadata)) {
        $resolved['web_search_tool'] = true;
    }

    // Resolve image output strictly:
    // - If explicit image metadata exists (output modalities or features), trust it.
    // - Otherwise, use conservative legacy heuristics (model id/name patterns + Auto Router).
    $supports_image_output_by_modality = in_array('image', $output_modalities, true)
        || in_array('image_url', $output_modalities, true)
        || in_array('output_image', $output_modalities, true);

    $supports_image_generation_by_feature = in_array('image_generation', $supported_features, true)
        || in_array('image-generation', $supported_features, true)
        || in_array('image_output', $supported_features, true)
        || in_array('image-output', $supported_features, true);

    $model_id_l = strtolower((string) ($metadata['id'] ?? ''));
    $model_name_l = strtolower((string) ($metadata['name'] ?? ''));
    $image_haystack = trim($model_id_l . ' ' . $model_name_l);
    $is_auto_router = $model_id_l === 'openrouter/auto'
        || $model_id_l === 'auto'
        || strpos($model_id_l, 'auto-router') !== false
        || strpos($model_name_l, 'auto router') !== false;

    $looks_like_image_model = strpos($image_haystack, 'image') !== false
        || strpos($image_haystack, 'gpt-image') !== false
        || strpos($image_haystack, 'flux') !== false
        || strpos($image_haystack, 'stable-diffusion') !== false
        || strpos($image_haystack, 'sdxl') !== false
        || strpos($image_haystack, 'riverflow') !== false
        || strpos($image_haystack, 'imagen') !== false
        || strpos($image_haystack, 'nano banana') !== false
        || strpos($image_haystack, 'nano-banana') !== false;

    $has_explicit_image_metadata = !empty($output_modalities) || !empty($supported_features);
    if ($is_auto_router) {
        $supports_image = true;
        $resolved['image_output'] = true;
        $resolved['image_generation'] = true;
    } elseif ($has_explicit_image_metadata) {
        $supports_image = $supports_image_output_by_modality || $supports_image_generation_by_feature;
        $resolved['image_output'] = $supports_image;
        $resolved['image_generation'] = $supports_image;
    } else {
        $supports_image = $looks_like_image_model;
        $resolved['image_output'] = $supports_image;
        $resolved['image_generation'] = $supports_image;
    }

    // Keep aliases aligned.
    if ($resolved['image_output'] !== $resolved['image_generation']) {
        $resolved['image_output'] = $resolved['image_generation'] || $resolved['image_output'];
        $resolved['image_generation'] = $resolved['image_output'];
    }

    return $resolved;
}

/**
 * Finds synchronized OpenRouter metadata for a specific model id.
 *
 * @param string $model_id OpenRouter model id.
 * @return array<string, mixed>|null
 */
function get_model_metadata_logic(string $model_id): ?array {
    $model_id = sanitize_text_field($model_id);
    if ($model_id === '') {
        return null;
    }

    $synced_models = \WPAICG\Core\Models\AIPKit_Model_Registry::get_legacy_model_list('OpenRouter');
    if (!is_array($synced_models)) {
        return null;
    }

    foreach ($synced_models as $model) {
        if (!is_array($model)) {
            continue;
        }
        $candidate_id = isset($model['id']) ? sanitize_text_field((string) $model['id']) : '';
        if ($candidate_id !== '' && $candidate_id === $model_id) {
            return $model;
        }
    }

    return null;
}

/**
 * Resolves model-level capabilities for an OpenRouter model id.
 *
 * @param string $model_id OpenRouter model id.
 * @return array<string, bool>
 */
function resolve_model_capabilities_logic(string $model_id): array {
    $metadata = get_model_metadata_logic($model_id);
    if (!is_array($metadata)) {
        return get_capability_map_logic();
    }

    return resolve_model_capabilities_from_metadata_logic($metadata);
}

/**
 * Checks whether selected OpenRouter model appears to support the web search server tool.
 *
 * Decision policy:
 * - If synced metadata explicitly declares support (`supported_features` / `supported_parameters`), trust it.
 * - If metadata exists and explicitly excludes tool/web capabilities, return false.
 * - If metadata is missing, use conservative provider defaults.
 *
 * @param string $model_id OpenRouter model id.
 * @return bool
 */
function model_supports_web_search_tool_logic(string $model_id): bool {
    $resolved = resolve_model_capabilities_logic($model_id);
    return !empty($resolved['web_search_tool']);
}

/**
 * Checks whether selected OpenRouter model appears to support image output.
 *
 * @param string $model_id OpenRouter model id.
 * @return bool
 */
function model_supports_image_output_logic(string $model_id): bool {
    $resolved = resolve_model_capabilities_logic($model_id);
    return !empty($resolved['image_output']);
}

/**
 * Returns the normalized dedicated Image API parameter schema for a model.
 *
 * @param string $model_id OpenRouter model id.
 * @return array<string, array<string, mixed>>
 */
function model_image_parameter_schema_logic(string $model_id): array {
    $metadata = get_model_metadata_logic($model_id);
    if (!is_array($metadata)) {
        return [];
    }

    $schema = $metadata['supported_parameter_schema'] ?? [];
    return is_array($schema) ? normalize_supported_parameter_schema_logic($schema) : [];
}

/**
 * Checks whether selected OpenRouter model appears to support image editing.
 * Edit support requires both image input and image output capabilities.
 *
 * @param string $model_id OpenRouter model id.
 * @return bool
 */
function model_supports_image_editing_logic(string $model_id): bool {
    $resolved = resolve_model_capabilities_logic($model_id);
    if (empty($resolved['image_input']) || empty($resolved['image_output'])) {
        return false;
    }

    $parameter_schema = model_image_parameter_schema_logic($model_id);
    return empty($parameter_schema) || isset($parameter_schema['input_references']);
}

/**
 * Normalizes domain filters accepted by the OpenRouter web search server tool.
 *
 * @param mixed $raw_domains Comma/newline-delimited string or a list of domains.
 * @return array<int, string>
 */
function normalize_web_search_domains_logic($raw_domains): array {
    $domains = is_array($raw_domains)
        ? $raw_domains
        : preg_split('/[\s,]+/', (string) $raw_domains, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($domains)) {
        return [];
    }

    $normalized = [];
    foreach ($domains as $domain) {
        if (!is_string($domain)) {
            continue;
        }
        $domain = strtolower(trim(sanitize_text_field($domain)));
        if ($domain === '' || strpos($domain, '*') !== false) {
            continue;
        }

        $host = wp_parse_url(
            preg_match('#^https?://#i', $domain) ? $domain : 'https://' . ltrim($domain, '.'),
            PHP_URL_HOST
        );
        if (!is_string($host) || $host === '') {
            continue;
        }
        $host = strtolower(rtrim($host, '.'));
        if (
            $host === ''
            || strpos($host, '.') === false
            || strlen($host) > 253
            || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host)
        ) {
            continue;
        }
        $normalized[] = $host;
    }

    return array_values(array_unique($normalized));
}

/**
 * Sanitizes web search server-tool configuration for OpenRouter Responses payloads.
 *
 * @param array $raw_config Raw web search tool config.
 * @return array Sanitized web search tool parameters.
 */
function sanitize_web_search_tool_config_logic(array $raw_config): array {
    $sanitized = [];

    if (isset($raw_config['max_results'])) {
        $max_results = absint($raw_config['max_results']);
        if ($max_results > 0) {
            $sanitized['max_results'] = max(1, min($max_results, 25));
        }
    }

    if (isset($raw_config['max_uses'])) {
        $max_uses = absint($raw_config['max_uses']);
        if ($max_uses > 0) {
            $sanitized['max_uses'] = max(1, min($max_uses, 10));
        }
    }

    if (isset($raw_config['max_total_results'])) {
        $max_total_results = absint($raw_config['max_total_results']);
        if ($max_total_results > 0) {
            $sanitized['max_total_results'] = max(1, min($max_total_results, 100));
        }
    }

    if (!empty($raw_config['engine'])) {
        $engine = sanitize_key((string) $raw_config['engine']);
        if (in_array($engine, ['native', 'exa', 'firecrawl', 'parallel', 'perplexity'], true)) {
            $sanitized['engine'] = $engine;
        }
    }

    if (!empty($raw_config['search_context_size'])) {
        $search_context_size = sanitize_key((string) $raw_config['search_context_size']);
        if (in_array($search_context_size, ['low', 'medium', 'high'], true)) {
            $sanitized['search_context_size'] = $search_context_size;
        }
    }

    $allowed_domains = normalize_web_search_domains_logic($raw_config['allowed_domains'] ?? []);
    $excluded_domains = normalize_web_search_domains_logic($raw_config['excluded_domains'] ?? []);
    if (!empty($allowed_domains)) {
        $sanitized['allowed_domains'] = $allowed_domains;
    } elseif (!empty($excluded_domains)) {
        // OpenRouter engines apply these filters differently. Avoid sending
        // conflicting lists: an allowlist always takes precedence.
        $sanitized['excluded_domains'] = $excluded_domains;
    }

    return $sanitized;
}

/**
 * Logic for the format_chat_payload method of OpenRouterProviderStrategy.
 *
 * @param OpenRouterProviderStrategy $strategyInstance The instance of the strategy class.
 * @param string $user_message The user's message (already included in history for OpenRouter).
 * @param string $instructions System instructions.
 * @param array  $history Conversation history.
 * @param array  $ai_params AI parameters (temperature, max_tokens, etc.).
 * @param string $model Model name (required by OpenRouter in payload).
 * @return array The formatted request body data.
 */
function format_chat_payload_logic(
    OpenRouterProviderStrategy $strategyInstance,
    string $user_message,
    string $instructions,
    array $history,
    array $ai_params,
    string $model
): array {
    // The latest user_message should be part of the history for format_chat
    $final_history = $history;
    if (!empty($user_message)) {
        $last_msg = end($final_history);
        if (!$last_msg || $last_msg['role'] !== 'user' || $last_msg['content'] !== $user_message) {
            $final_history[] = ['role' => 'user', 'content' => $user_message];
        }
    }
    return \WPAICG\Core\Providers\OpenRouter\OpenRouterPayloadFormatter::format_chat($instructions, $final_history, $ai_params, $model);
}

/**
 * Logic for the format_chat static method of OpenRouterPayloadFormatter.
 *
 * @param string $instructions System instructions.
 * @param array  $history Conversation history.
 * @param array  $ai_params AI parameters (temperature, max_tokens, etc.).
 * @param string $model Model name.
 * @return array The formatted payload.
 */
function format_chat_logic_for_payload_formatter(string $instructions, array $history, array $ai_params, string $model): array {
    return _shared_format_logic($instructions, $history, $ai_params, $model);
}

/**
 * Logic for the format_sse static method of OpenRouterPayloadFormatter.
 *
 * @param array  $messages Formatted messages array (user/assistant).
 * @param string $system_instruction System instructions.
 * @param array  $ai_params AI parameters.
 * @param string $model Model name.
 * @return array The formatted SSE payload.
 */
function format_sse_logic_for_payload_formatter(array $messages, string $system_instruction, array $ai_params, string $model): array {
    // The history array already contains user/assistant messages; system instructions are merged by shared formatter.
    $payload = _shared_format_logic($system_instruction, $messages, $ai_params, $model);
    $payload['stream'] = true;
    return $payload;
}

/**
 * Logic for the generate_embeddings method of OpenRouterProviderStrategy.
 *
 * @param OpenRouterProviderStrategy $strategyInstance The instance of the strategy class.
 * @param string|array $input The input text or array of texts.
 * @param array $api_params Provider-specific API connection parameters.
 * @param array $options Embedding options (model, dimensions, encoding_format, etc.).
 * @return array|WP_Error ['embeddings' => array, 'usage' => array|null] or WP_Error.
 */
function generate_embeddings_logic(
    OpenRouterProviderStrategy $strategyInstance,
    $input,
    array $api_params,
    array $options = []
) {
    $model = isset($options['model']) ? sanitize_text_field((string) $options['model']) : '';
    if ($model === '') {
        return new WP_Error(
            'missing_openrouter_embedding_model_logic',
            __('OpenRouter embedding model ID is required.', 'gpt3-ai-content-generator')
        );
    }

    $url = $strategyInstance->build_api_url('embeddings', $api_params);
    if (is_wp_error($url)) {
        return $url;
    }

    $headers = $strategyInstance->get_api_headers($api_params['api_key'] ?? '', 'embeddings');
    $request_options = $strategyInstance->get_request_options('embeddings');

    $payload = [
        'model' => $model,
        'input' => $input,
    ];

    if (isset($options['dimensions']) && absint($options['dimensions']) > 0) {
        $payload['dimensions'] = absint($options['dimensions']);
    }

    if (isset($options['encoding_format'])) {
        $encoding_format = sanitize_key((string) $options['encoding_format']);
        if (in_array($encoding_format, ['float', 'base64'], true)) {
            $payload['encoding_format'] = $encoding_format;
        }
    }

    $request_body_json = wp_json_encode($payload);
    $response = wp_remote_post(
        $url,
        array_merge(
            $request_options,
            [
                'headers' => $headers,
                'body'    => $request_body_json,
            ]
        )
    );

    if (is_wp_error($response)) {
        return new WP_Error(
            'openrouter_embedding_http_error_logic',
            __('HTTP error during OpenRouter embedding generation.', 'gpt3-ai-content-generator')
        );
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $decoded_response = $strategyInstance->decode_json($body, 'OpenRouter Embeddings');

    if ($status_code !== 200 || is_wp_error($decoded_response)) {
        $error_msg = is_wp_error($decoded_response)
            ? $decoded_response->get_error_message()
            : $strategyInstance->parse_error_response($body, $status_code);
        $error_data = $strategyInstance->build_http_error_data_with_retry_after($response, $status_code);
        /* translators: %1$d: HTTP status code, %2$s: API error message. */
        return new WP_Error(
            'openrouter_embedding_api_error_logic',
            sprintf(
                /* translators: 1: HTTP status code, 2: API error message. */
                __('OpenRouter Embeddings API Error (%1$d): %2$s', 'gpt3-ai-content-generator'),
                $status_code,
                esc_html($error_msg)
            ),
            $error_data
        );
    }

    $embeddings = [];
    if (isset($decoded_response['data']) && is_array($decoded_response['data'])) {
        foreach ($decoded_response['data'] as $item) {
            if (isset($item['embedding']) && is_array($item['embedding'])) {
                $embeddings[] = $item['embedding'];
            }
        }
    }

    if (empty($embeddings)) {
        return new WP_Error(
            'openrouter_embedding_no_data_logic',
            __('No embedding data found in OpenRouter response.', 'gpt3-ai-content-generator')
        );
    }

    $usage = null;
    if (isset($decoded_response['usage']) && is_array($decoded_response['usage'])) {
        $usage = [
            'input_tokens'  => $decoded_response['usage']['input_tokens'] ?? $decoded_response['usage']['prompt_tokens'] ?? 0,
            'total_tokens'  => $decoded_response['usage']['total_tokens'] ?? 0,
            'provider_raw'  => $decoded_response['usage'],
        ];
    }

    return [
        'embeddings' => $embeddings,
        'usage' => $usage,
    ];
}

/**
 * Logic for the get_api_headers method of OpenRouterProviderStrategy.
 *
 * @param OpenRouterProviderStrategy $strategyInstance The instance of the strategy class.
 * @param string $api_key The API key for the provider.
 * @param string $operation The specific operation being performed.
 * @return array Key-value array of headers.
 */
function get_api_headers_logic(OpenRouterProviderStrategy $strategyInstance, string $api_key, string $operation): array {
    $headers = [
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer ' . $api_key,
        'HTTP-Referer' => get_bloginfo('url'),
        'X-OpenRouter-Title' => 'AIPKit',
    ];
    if ($operation === 'stream') {
        $headers['Accept'] = 'text/event-stream';
        $headers['Cache-Control'] = 'no-cache';
    }
    return $headers;
}

/**
 * Logic for fetching OpenRouter embedding models from /embeddings/models.
 *
 * @param OpenRouterProviderStrategy $strategyInstance The OpenRouter strategy instance.
 * @param array $api_params Connection parameters (api_key, base_url, api_version).
 * @return array|WP_Error Formatted list [['id' => ..., 'name' => ...]] or WP_Error.
 */
function get_embedding_models_logic(OpenRouterProviderStrategy $strategyInstance, array $api_params) {
    $url = $strategyInstance->build_api_url('embedding_models', $api_params);
    if (is_wp_error($url)) {
        return $url;
    }

    $headers = $strategyInstance->get_api_headers($api_params['api_key'] ?? '', 'embedding_models');
    $options = $strategyInstance->get_request_options('embedding_models');
    $options['method'] = 'GET';

    $response = wp_remote_get($url, array_merge($options, ['headers' => $headers]));
    if (is_wp_error($response)) {
        return $response;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    if ($status_code !== 200) {
        $error_msg = $strategyInstance->parse_error_response($body, $status_code);
        /* translators: %1$d: HTTP status code, %2$s: API error message. */
        return new WP_Error(
            'api_error_openrouter_embedding_models_logic',
            sprintf(
                /* translators: 1: HTTP status code, 2: API error message. */
                __('OpenRouter Embedding Models API Error (HTTP %1$d): %2$s', 'gpt3-ai-content-generator'),
                $status_code,
                esc_html($error_msg)
            )
        );
    }

    $decoded = $strategyInstance->decode_json($body, 'OpenRouter Embedding Models');
    if (is_wp_error($decoded)) {
        return $decoded;
    }

    $raw_models = $decoded['data'] ?? [];
    if (!is_array($raw_models)) {
        return [];
    }

    $formatted = [];
    foreach ($raw_models as $model) {
        if (is_string($model)) {
            $id = sanitize_text_field($model);
            if ($id === '') {
                continue;
            }
            $formatted[] = ['id' => $id, 'name' => $id];
            continue;
        }

        if (!is_array($model)) {
            continue;
        }

        $id = isset($model['id']) ? sanitize_text_field((string) $model['id']) : '';
        if ($id === '') {
            continue;
        }
        $name = isset($model['name']) ? sanitize_text_field((string) $model['name']) : $id;
        $formatted[] = ['id' => $id, 'name' => $name];
    }

    usort(
        $formatted,
        static fn(array $a, array $b): int => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''))
    );

    return $formatted;
}

/**
 * Logic for the get_models method of OpenRouterProviderStrategy.
 *
 * @param OpenRouterProviderStrategy $strategyInstance The instance of the strategy class.
 * @param array $api_params Connection parameters (api_key, base_url, etc.).
 * @return array|WP_Error Formatted list [['id' => ..., 'name' => ...]] or WP_Error.
 */
function get_models_logic(OpenRouterProviderStrategy $strategyInstance, array $api_params) {
    $url = $strategyInstance->build_api_url('models', $api_params);
    if (is_wp_error($url)) {
        return $url;
    }

    $headers = $strategyInstance->get_api_headers($api_params['api_key'] ?? '', 'models');
    $options = $strategyInstance->get_request_options('models');
    $options['method'] = 'GET';

    // The public model catalog does not authenticate the supplied key.
    if (!empty($api_params['verify_credentials'])) {
        $key_url = preg_replace('~/models/?$~', '/key', $url);
        $key_response = wp_remote_get($key_url, array_merge($options, ['headers' => $headers]));
        if (is_wp_error($key_response)) { return $key_response; }
        $key_status = wp_remote_retrieve_response_code($key_response);
        if ($key_status !== 200) {
            return new WP_Error('openrouter_key_rejected', $strategyInstance->parse_error_response(wp_remote_retrieve_body($key_response), $key_status), ['status' => $key_status]);
        }
        $key_body = $strategyInstance->decode_json(wp_remote_retrieve_body($key_response), 'OpenRouter Key');
        if (is_wp_error($key_body)) { return $key_body; }
        if (!is_array($key_body['data'] ?? null) || !array_key_exists('label', $key_body['data'])) {
            return new WP_Error('openrouter_key_response', __('OpenRouter returned an unexpected key response. Try again.', 'gpt3-ai-content-generator'));
        }
    }

    $response = wp_remote_get($url, array_merge($options, ['headers' => $headers]));
    if (is_wp_error($response)) {
        return $response;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($status_code !== 200) {
        $error_msg = $strategyInstance->parse_error_response($body, $status_code);
        return new WP_Error('api_error_openrouter_models_logic', sprintf('OpenRouter API Error (HTTP %d): %s', $status_code, esc_html($error_msg)));
    }

    // decode_json is public in BaseProviderStrategy
    $decoded = $strategyInstance->decode_json($body, 'OpenRouter Models');
    if (is_wp_error($decoded)) {
        return $decoded;
    }

    $raw_models = $decoded['data'] ?? [];
    if (!is_array($raw_models)) {
        return [];
    }

    return format_models_logic($raw_models);
}

/**
 * Excludes models whose advertised output format is exclusively non-raster.
 *
 * WordPress Media Library storage in the image modules expects raster bytes.
 * Models without an output_format descriptor remain available because their
 * default response may still be a supported raster format.
 *
 * @param array<int, array<string, mixed>> $models Formatted Image API models.
 * @return array<int, array<string, mixed>>
 */
function filter_raster_image_models_logic(array $models): array {
    $raster_formats = ['png', 'jpeg', 'jpg', 'webp'];

    return array_values(array_filter($models, static function (array $model) use ($raster_formats): bool {
        $descriptor = $model['supported_parameter_schema']['output_format'] ?? null;
        if (!is_array($descriptor) || empty($descriptor['values']) || !is_array($descriptor['values'])) {
            return true;
        }

        $advertised_formats = [];
        foreach ($descriptor['values'] as $format) {
            if (is_scalar($format)) {
                $advertised_formats[] = strtolower(trim((string) $format));
            }
        }

        return !empty(array_intersect($raster_formats, $advertised_formats));
    }));
}

/**
 * Logic for syncing OpenRouter image-output models.
 *
 * Uses OpenRouter's dedicated Image Models API so the synchronized rows carry
 * the exact capability descriptors used by the Image API payload validator.
 *
 * @param OpenRouterProviderStrategy $strategyInstance The instance of the strategy class.
 * @param array $api_params Connection parameters (api_key, base_url, etc.).
 * @return array|WP_Error
 */
function get_image_models_logic(OpenRouterProviderStrategy $strategyInstance, array $api_params) {
    $url = $strategyInstance->build_api_url('image_models', $api_params);
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
            'api_error_openrouter_image_models_logic',
            sprintf('OpenRouter Image Models API Error (HTTP %d): %s', $status_code, esc_html($error_msg)),
            ['status' => $status_code]
        );
    }

    $decoded = $strategyInstance->decode_json($body, 'OpenRouter Image Models');
    if (is_wp_error($decoded)) {
        return $decoded;
    }

    $raw_models = $decoded['data'] ?? [];
    if (!is_array($raw_models)) {
        return [];
    }

    return filter_raster_image_models_logic(format_models_logic($raw_models));
}

/**
 * @param array<int, mixed> $raw_models
 * @return array<int, array<string, mixed>>
 */
function format_models_logic(array $raw_models): array {
    $formatted = [];
    foreach ($raw_models as $model) {
        if (!is_array($model)) {
            continue;
        }

        $id = isset($model['id']) ? sanitize_text_field((string) $model['id']) : '';
        if ($id === '') {
            continue;
        }

        $name = isset($model['name']) ? sanitize_text_field((string) $model['name']) : $id;
        $item = [
            'id'   => $id,
            'name' => $name,
            'status'  => isset($model['status']) ? sanitize_text_field((string) $model['status']) : null,
            'version' => isset($model['version']) ? sanitize_text_field((string) $model['version']) : null,
        ];

        $supported_parameters = [];
        $supported_parameter_schema = [];
        $raw_supported_parameters = $model['supported_parameters']
            ?? $model['supportedParameters']
            ?? $model['supported_sampling_parameters']
            ?? [];
        if (is_array($raw_supported_parameters)) {
            $is_list = empty($raw_supported_parameters)
                || array_keys($raw_supported_parameters) === range(0, count($raw_supported_parameters) - 1);
            if ($is_list) {
                foreach ($raw_supported_parameters as $param) {
                    if (!is_string($param)) {
                        continue;
                    }
                    $param = strtolower(trim($param));
                    if ($param !== '') {
                        $supported_parameters[] = $param;
                    }
                }
            } else {
                $supported_parameter_schema = normalize_supported_parameter_schema_logic($raw_supported_parameters);
                $supported_parameters = array_keys($supported_parameter_schema);
            }
            $supported_parameters = array_values(array_unique($supported_parameters));
        }
        if (!empty($supported_parameters)) {
            $item['supported_parameters'] = $supported_parameters;
        }
        if (!empty($supported_parameter_schema)) {
            $item['supported_parameter_schema'] = $supported_parameter_schema;
        }

        $input_modalities = [];
        $raw_input_modalities = [];
        if (isset($model['input_modalities']) && is_array($model['input_modalities'])) {
            $raw_input_modalities = $model['input_modalities'];
        } elseif (isset($model['architecture']['input_modalities']) && is_array($model['architecture']['input_modalities'])) {
            $raw_input_modalities = $model['architecture']['input_modalities'];
        }
        foreach ($raw_input_modalities as $modality) {
            if (!is_string($modality)) {
                continue;
            }
            $modality = strtolower(trim($modality));
            if ($modality !== '') {
                $input_modalities[] = $modality;
            }
        }
        $input_modalities = array_values(array_unique($input_modalities));
        if (!empty($input_modalities)) {
            $item['input_modalities'] = $input_modalities;
        }

        $output_modalities = [];
        $raw_output_modalities = [];
        if (isset($model['output_modalities']) && is_array($model['output_modalities'])) {
            $raw_output_modalities = $model['output_modalities'];
        } elseif (isset($model['architecture']['output_modalities']) && is_array($model['architecture']['output_modalities'])) {
            $raw_output_modalities = $model['architecture']['output_modalities'];
        }
        foreach ($raw_output_modalities as $modality) {
            if (!is_string($modality)) {
                continue;
            }
            $modality = strtolower(trim($modality));
            if ($modality !== '') {
                $output_modalities[] = $modality;
            }
        }
        $output_modalities = array_values(array_unique($output_modalities));
        if (!empty($output_modalities)) {
            $item['output_modalities'] = $output_modalities;
        }

        $supported_features = [];
        $raw_supported_features = $model['supported_features'] ?? $model['supportedFeatures'] ?? [];
        if (is_array($raw_supported_features)) {
            foreach ($raw_supported_features as $feature) {
                if (!is_string($feature)) {
                    continue;
                }
                $feature = strtolower(trim($feature));
                if ($feature !== '') {
                    $supported_features[] = $feature;
                }
            }
            $supported_features = array_values(array_unique($supported_features));
        }
        if (!empty($supported_features)) {
            $item['supported_features'] = $supported_features;
        }

        if (array_key_exists('reasoning', $model)) {
            $item['reasoning'] = sanitize_reasoning_metadata_logic($model['reasoning']);
        }

        if (array_key_exists('supports_streaming', $model)) {
            $item['supports_streaming'] = (bool) $model['supports_streaming'];
        }
        if (!empty($model['endpoints']) && is_string($model['endpoints'])) {
            $endpoints_path = sanitize_text_field($model['endpoints']);
            if ($endpoints_path !== '') {
                $item['endpoints'] = $endpoints_path;
            }
        }

        // Keep a tiny pricing hint for runtime feature guards.
        if (isset($model['pricing']) && is_array($model['pricing']) && isset($model['pricing']['web_search'])) {
            $item['pricing_web_search'] = sanitize_text_field((string) $model['pricing']['web_search']);
        }

        // Centralized normalized capability contract consumed across modules.
        $item['capabilities'] = resolve_model_capabilities_from_metadata_logic($item);

        $formatted[] = $item;
    }

    usort(
        $formatted,
        static fn(array $a, array $b): int => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''))
    );

    return $formatted;
}

/**
 * Extracts OpenRouter Responses URL citations from a completed response.
 *
 * @param array<string, mixed> $response Responses API response object.
 * @return array<int, array<string, mixed>>
 */
function extract_citations_from_response_logic_for_response_parser(array $response): array {
    if (isset($response['response']) && is_array($response['response'])) {
        $response = $response['response'];
    }

    $citations = [];
    $output = isset($response['output']) && is_array($response['output']) ? $response['output'] : [];
    foreach ($output as $output_item) {
        if (!is_array($output_item) || empty($output_item['content']) || !is_array($output_item['content'])) {
            continue;
        }
        foreach ($output_item['content'] as $content_item) {
            if (!is_array($content_item) || empty($content_item['annotations']) || !is_array($content_item['annotations'])) {
                continue;
            }
            $full_text = isset($content_item['text']) && is_string($content_item['text'])
                ? $content_item['text']
                : '';
            foreach ($content_item['annotations'] as $annotation) {
                if (!is_array($annotation)) {
                    continue;
                }
                $normalized = normalize_citation_logic_for_response_parser($annotation, $full_text);
                if ($normalized !== null) {
                    $citations[] = $normalized;
                }
            }
        }
    }

    return dedupe_citations_logic_for_response_parser($citations);
}

/**
 * Extracts citations exposed by incremental and completion SSE payloads.
 *
 * @param array<string, mixed> $payload Event payload.
 * @return array<int, array<string, mixed>>
 */
function extract_citations_from_event_payload_logic_for_response_parser(array $payload): array {
    $citations = [];

    if (isset($payload['annotation']) && is_array($payload['annotation'])) {
        $normalized = normalize_citation_logic_for_response_parser($payload['annotation']);
        if ($normalized !== null) {
            $citations[] = $normalized;
        }
    }

    foreach (['part', 'item'] as $container_key) {
        $container = isset($payload[$container_key]) && is_array($payload[$container_key])
            ? $payload[$container_key]
            : null;
        if ($container === null) {
            continue;
        }

        $content_items = $container_key === 'part'
            ? [$container]
            : (isset($container['content']) && is_array($container['content']) ? $container['content'] : []);
        foreach ($content_items as $content_item) {
            if (!is_array($content_item) || empty($content_item['annotations']) || !is_array($content_item['annotations'])) {
                continue;
            }
            $full_text = isset($content_item['text']) && is_string($content_item['text'])
                ? $content_item['text']
                : '';
            foreach ($content_item['annotations'] as $annotation) {
                if (!is_array($annotation)) {
                    continue;
                }
                $normalized = normalize_citation_logic_for_response_parser($annotation, $full_text);
                if ($normalized !== null) {
                    $citations[] = $normalized;
                }
            }
        }
    }

    if (isset($payload['response']) && is_array($payload['response'])) {
        $citations = array_merge(
            $citations,
            extract_citations_from_response_logic_for_response_parser($payload['response'])
        );
    }

    return dedupe_citations_logic_for_response_parser($citations);
}

/**
 * Normalizes an OpenRouter URL annotation into the citation shape shared by the frontend.
 *
 * @param array<string, mixed> $annotation OpenRouter annotation.
 * @param string $full_text Full output text used for an index-based excerpt fallback.
 * @return array<string, mixed>|null
 */
function normalize_citation_logic_for_response_parser(array $annotation, string $full_text = ''): ?array {
    if (($annotation['type'] ?? '') !== 'url_citation') {
        return null;
    }

    $raw_url = isset($annotation['url']) && is_string($annotation['url'])
        ? trim($annotation['url'])
        : '';
    $url = esc_url_raw($raw_url, ['http', 'https']);
    if ($url === '') {
        return null;
    }

    $normalized = [
        'type' => 'char_location',
        'url' => $url,
    ];

    if (!empty($annotation['title']) && is_string($annotation['title'])) {
        $title = sanitize_text_field($annotation['title']);
        if ($title !== '') {
            $normalized['title'] = $title;
            $normalized['source_title'] = $title;
        }
    }

    $start_index = isset($annotation['start_index']) && is_numeric($annotation['start_index'])
        ? max(0, (int) $annotation['start_index'])
        : null;
    $end_index = isset($annotation['end_index']) && is_numeric($annotation['end_index'])
        ? max(0, (int) $annotation['end_index'])
        : null;
    if ($start_index !== null) {
        $normalized['start_char_index'] = $start_index;
    }
    if ($end_index !== null) {
        $normalized['end_char_index'] = $end_index;
    }

    $cited_text = '';
    foreach (['content', 'text', 'snippet', 'excerpt'] as $field) {
        if (!empty($annotation[$field]) && is_string($annotation[$field])) {
            $cited_text = trim(wp_strip_all_tags($annotation[$field]));
            if ($cited_text !== '') {
                break;
            }
        }
    }
    if ($cited_text === '' && $full_text !== '' && $start_index !== null && $end_index !== null && $end_index > $start_index) {
        $length = $end_index - $start_index;
        $cited_text = function_exists('mb_substr')
            ? mb_substr($full_text, $start_index, $length)
            : substr($full_text, $start_index, $length);
        $cited_text = trim(wp_strip_all_tags((string) $cited_text));
    }
    if ($cited_text !== '') {
        $normalized['cited_text'] = $cited_text;
    }

    return $normalized;
}

/**
 * Deduplicates citation rows by URL while preserving richer metadata.
 *
 * @param array<int, array<string, mixed>> $citations Citation list.
 * @return array<int, array<string, mixed>>
 */
function dedupe_citations_logic_for_response_parser(array $citations): array {
    $deduped = [];
    $url_indexes = [];

    foreach ($citations as $citation) {
        if (!is_array($citation) || empty($citation['url']) || !is_string($citation['url'])) {
            continue;
        }
        $key = strtolower(trim($citation['url']));
        if (isset($url_indexes[$key])) {
            $index = $url_indexes[$key];
            foreach ($citation as $field => $value) {
                if (!isset($deduped[$index][$field]) || $deduped[$index][$field] === '') {
                    $deduped[$index][$field] = $value;
                }
            }
            continue;
        }
        $url_indexes[$key] = count($deduped);
        $deduped[] = $citation;
    }

    return $deduped;
}

/**
 * Maps a normalized OpenRouter SSE event into an internal typed event.
 *
 * @param array<string, mixed> $decoded_event
 * @return array<string, mixed>
 */
function map_sse_event_logic_for_response_parser(array $decoded_event): array {
    $event_type = isset($decoded_event['event']) && is_string($decoded_event['event']) ? $decoded_event['event'] : 'message';
    $payload = isset($decoded_event['payload']) && is_array($decoded_event['payload']) ? $decoded_event['payload'] : [];

    if ($event_type === '[DONE]') {
        return [
            'kind' => 'done',
            'event' => $event_type,
        ];
    }

    if (in_array($event_type, ['ping', 'keepalive'], true)) {
        return [
            'kind' => 'skip',
            'event' => $event_type,
        ];
    }

    if ($event_type === 'error' || $event_type === 'response.error' || isset($payload['error'])) {
        $error_details = extract_error_details_logic_for_response_parser($payload);
        return [
            'kind' => 'error',
            'event' => $event_type,
            'message' => $error_details['message'],
        ];
    }

    $event_citations = extract_citations_from_event_payload_logic_for_response_parser($payload);

    switch ($event_type) {
        case 'response.created':
        case 'response.in_progress':
        case 'response.queued':
        case 'response.web_search_call.in_progress':
        case 'response.web_search_call.searching':
        case 'response.web_search_call.completed':
        case 'response.file_search_call.in_progress':
        case 'response.file_search_call.searching':
        case 'response.file_search_call.completed':
        case 'response.image_generation_call.in_progress':
        case 'response.image_generation_call.generating':
        case 'response.image_generation_call.completed':
        case 'response.output_item.added':
        case 'response.content_part.added':
        case 'response.output_text.done':
        case 'response.reasoning.delta':
        case 'response.reasoning.done':
        case 'response.function_call_arguments.delta':
        case 'response.function_call_arguments.done':
        case 'tool.preliminary_result':
        case 'tool.result':
            return [
                'kind' => 'status',
                'event' => $event_type,
                'status' => build_sse_status_logic_for_response_parser($event_type, $payload),
            ];

        case 'response.output_text.annotation.added':
        case 'response.content_part.done':
        case 'response.output_item.done':
            if (!empty($event_citations)) {
                return [
                    'kind' => 'citations',
                    'event' => $event_type,
                    'citations' => $event_citations,
                ];
            }

            return [
                'kind' => 'status',
                'event' => $event_type,
                'status' => build_sse_status_logic_for_response_parser($event_type, $payload),
            ];

        case 'response.output_text.delta':
        case 'response.content_part.delta':
            $delta_text = isset($payload['delta']) ? (string) $payload['delta'] : '';
            if ($delta_text === '') {
                return [
                    'kind' => 'skip',
                    'event' => $event_type,
                ];
            }

            return [
                'kind' => 'delta',
                'event' => $event_type,
                'text' => $delta_text,
            ];

        case 'response.refusal.delta':
            $refusal_text = isset($payload['delta']) ? (string) $payload['delta'] : '';
            if ($refusal_text === '') {
                return [
                    'kind' => 'skip',
                    'event' => $event_type,
                ];
            }

            return [
                'kind' => 'warning',
                'event' => $event_type,
                'text' => sprintf(' (%s: %s)', __('Refusal', 'gpt3-ai-content-generator'), $refusal_text),
            ];

        case 'response.done':
        case 'response.completed':
        case 'response.incomplete':
            $warning_text = null;
            if ($event_type === 'response.incomplete') {
                $reason = $payload['response']['incomplete_details']['reason'] ?? 'unknown';
                $warning_text = sprintf(' (%s: %s)', __('Incomplete', 'gpt3-ai-content-generator'), $reason);
            }

            return [
                'kind' => 'completion',
                'event' => $event_type,
                'usage' => extract_sse_usage_logic_for_response_parser($payload),
                'status' => build_sse_status_logic_for_response_parser($event_type, $payload),
                'warning_text' => $warning_text,
                'citations' => $event_citations,
            ];

        case 'response.failed':
            $error_details = extract_error_details_logic_for_response_parser($payload);

            return [
                'kind' => 'error',
                'event' => $event_type,
                'message' => $error_details['message'],
            ];

        default:
            // Backward-compat fallback for chat-completions stream shape.
            return map_chat_completions_fallback_sse_event_logic_for_response_parser($payload, $event_type);
    }
}

/**
 * Builds the status payload that gets forwarded to the public stream formatter.
 *
 * @param string $type
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function build_sse_status_logic_for_response_parser(string $type, array $payload): array {
    $status = ['type' => $type];

    if (isset($payload['response']['status'])) {
        $status['status'] = $payload['response']['status'];
    }
    if (isset($payload['response']['id'])) {
        $status['response_id'] = $payload['response']['id'];
    } elseif (isset($payload['response_id'])) {
        $status['response_id'] = $payload['response_id'];
    }
    if (isset($payload['item_id'])) {
        $status['item_id'] = $payload['item_id'];
    }
    if (isset($payload['output_index'])) {
        $status['output_index'] = $payload['output_index'];
    }
    if (isset($payload['call_id'])) {
        $status['call_id'] = $payload['call_id'];
    }
    if (isset($payload['item']['id'])) {
        $status['item_id'] = $payload['item']['id'];
    }
    if (isset($payload['item']['type'])) {
        $status['item_type'] = $payload['item']['type'];
    }
    if (isset($payload['item']['status'])) {
        $status['item_status'] = $payload['item']['status'];
    }
    if (isset($payload['item']['name'])) {
        $status['name'] = $payload['item']['name'];
    }

    return $status;
}

/**
 * Extracts usage information from a completion payload using the existing public parse shape.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>|null
 */
function extract_sse_usage_logic_for_response_parser(array $payload): ?array {
    if (isset($payload['response']['usage']) && is_array($payload['response']['usage'])) {
        $usage = $payload['response']['usage'];
    } elseif (isset($payload['usage']) && is_array($payload['usage'])) {
        $usage = $payload['usage'];
    } else {
        return null;
    }

    return [
        'input_tokens' => $usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0,
        'output_tokens' => $usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0,
        'total_tokens' => $usage['total_tokens'] ?? 0,
        'provider_raw' => $usage,
    ];
}

/**
 * Handles legacy chat-completions-shaped fallback stream payloads.
 *
 * @param array<string, mixed> $payload
 * @param string $event_type
 * @return array<string, mixed>
 */
function map_chat_completions_fallback_sse_event_logic_for_response_parser(array $payload, string $event_type): array {
    $usage = extract_sse_usage_logic_for_response_parser($payload);
    $delta_text = null;
    if (isset($payload['choices'][0]['delta']['content'])) {
        $delta_text = (string) $payload['choices'][0]['delta']['content'];
        if ($delta_text === '') {
            $delta_text = null;
        }
    }

    $warning_text = null;
    if (isset($payload['choices'][0]['finish_reason']) && $payload['choices'][0]['finish_reason'] === 'content_filter') {
        $warning_text = sprintf(' (%s)', __('Warning: Content Filtered', 'gpt3-ai-content-generator'));
    }

    if ($delta_text === null && $usage === null && $warning_text === null) {
        return [
            'kind' => 'skip',
            'event' => $event_type,
        ];
    }

    return [
        'kind' => 'legacy_chunk',
        'event' => $event_type,
        'usage' => $usage,
        'text' => $delta_text,
        'warning_text' => $warning_text,
    ];
}

/**
 * Logic for the parse_chat_response method of OpenRouterProviderStrategy.
 *
 * @param OpenRouterProviderStrategy $strategyInstance The instance of the strategy class.
 * @param array $decoded_response The decoded JSON response body.
 * @param array $request_data The original request data sent (unused here).
 * @return array|WP_Error ['content' => string, 'usage' => array|null] or WP_Error.
 */
function parse_chat_response_logic(
    OpenRouterProviderStrategy $strategyInstance,
    array $decoded_response,
    array $request_data
) {
    return \WPAICG\Core\Providers\OpenRouter\OpenRouterResponseParser::parse_chat($decoded_response);
}

/**
 * Logic for the parse_chat static method of OpenRouterResponseParser.
 *
 * @param array $decoded_response The decoded JSON response.
 * @return array|WP_Error ['content' => string, 'usage' => array|null] or WP_Error.
 */
function parse_chat_logic_for_response_parser(array $decoded_response) {
    $content = null;
    $usage = null;
    $citations = extract_citations_from_response_logic_for_response_parser($decoded_response);

    if (
        (isset($decoded_response['status']) && $decoded_response['status'] === 'failed')
        || isset($decoded_response['error'])
    ) {
        $error_details = extract_error_details_logic_for_response_parser($decoded_response);
        $error_data = [];
        if ($error_details['error_type'] !== '') {
            $error_data['error_type'] = $error_details['error_type'];
        }
        return new WP_Error('openrouter_failed_response_logic', $error_details['message'], $error_data);
    }

    if (isset($decoded_response['output_text']) && is_string($decoded_response['output_text'])) {
        $output_text = trim($decoded_response['output_text']);
        if ($output_text !== '') {
            $content = $output_text;
        }
    }

    if ($content === null && isset($decoded_response['output']) && is_array($decoded_response['output'])) {
        $parts = [];
        foreach ($decoded_response['output'] as $output_item) {
            if (!is_array($output_item) || ($output_item['type'] ?? '') !== 'message') {
                continue;
            }
            if (empty($output_item['content']) || !is_array($output_item['content'])) {
                continue;
            }
            foreach ($output_item['content'] as $content_item) {
                if (!is_array($content_item)) {
                    continue;
                }
                $part_type = $content_item['type'] ?? '';
                if (($part_type === 'output_text' || $part_type === 'text') && isset($content_item['text']) && is_string($content_item['text'])) {
                    $parts[] = $content_item['text'];
                }
            }
        }
        if (!empty($parts)) {
            $joined = trim(implode('', $parts));
            if ($joined !== '') {
                $content = $joined;
            }
        }
    }

    // Backward-compat fallback for chat-completions shaped payloads.
    if ($content === null) {
        if (isset($decoded_response['choices'][0]['message']['content']) && is_string($decoded_response['choices'][0]['message']['content'])) {
            $content = trim($decoded_response['choices'][0]['message']['content']);
        } elseif (isset($decoded_response['choices'][0]['delta']['content']) && is_string($decoded_response['choices'][0]['delta']['content'])) {
            $content = trim($decoded_response['choices'][0]['delta']['content']);
        } elseif (isset($decoded_response['choices'][0]['text']) && is_string($decoded_response['choices'][0]['text'])) {
            $content = trim($decoded_response['choices'][0]['text']);
        }
    }

    if (isset($decoded_response['status']) && $decoded_response['status'] === 'incomplete') {
        $reason = $decoded_response['incomplete_details']['reason'] ?? 'unknown';
        if ($content !== null && $content !== '') {
            $content .= sprintf(' (%s: %s)', __('Incomplete', 'gpt3-ai-content-generator'), $reason);
        } else {
            /* translators: %s: The reason why the OpenRouter response was incomplete. */
            return new WP_Error('openrouter_incomplete_response_logic', sprintf(__('Response incomplete due to: %s', 'gpt3-ai-content-generator'), $reason));
        }
    }

    $usage_source = null;
    if (isset($decoded_response['usage']) && is_array($decoded_response['usage'])) {
        $usage_source = $decoded_response['usage'];
    } elseif (isset($decoded_response['response']['usage']) && is_array($decoded_response['response']['usage'])) {
        $usage_source = $decoded_response['response']['usage'];
    }
    if ($usage_source !== null) {
        $usage = [
            'input_tokens'  => $usage_source['input_tokens'] ?? $usage_source['prompt_tokens'] ?? 0,
            'output_tokens' => $usage_source['output_tokens'] ?? $usage_source['completion_tokens'] ?? 0,
            'total_tokens'  => $usage_source['total_tokens'] ?? 0,
            'provider_raw'  => $usage_source,
        ];
    }

    if ($content === null) {
        if (isset($decoded_response['choices'][0]['finish_reason']) && $decoded_response['choices'][0]['finish_reason'] === 'content_filter') {
            return new WP_Error('content_filter_logic', __('Response blocked due to content filtering.', 'gpt3-ai-content-generator'));
        }
        return new WP_Error('invalid_response_structure_openrouter_logic', __('Unexpected response structure from OpenRouter API.', 'gpt3-ai-content-generator'));
    }

    $parsed = ['content' => $content, 'usage' => $usage];
    if (!empty($citations)) {
        $parsed['citations'] = $citations;
    }

    return $parsed;
}

/**
 * Logic for the parse_error_response method of OpenRouterProviderStrategy.
 *
 * @param OpenRouterProviderStrategy $strategyInstance The instance of the strategy class.
 * @param mixed $response_body The raw or decoded error response body.
 * @param int $status_code The HTTP status code.
 * @return string A user-friendly error message.
 */
function parse_error_response_logic(
    OpenRouterProviderStrategy $strategyInstance,
    $response_body,
    int $status_code
): string {
    return \WPAICG\Core\Providers\OpenRouter\OpenRouterResponseParser::parse_error($response_body, $status_code);
}

/**
 * Logic for the parse_error static method of OpenRouterResponseParser.
 *
 * @param mixed $response_body The raw or decoded error response body.
 * @param int $status_code The HTTP status code.
 * @return string A user-friendly error message.
 */
function parse_error_logic_for_response_parser($response_body, int $status_code): string {
    $error_details = extract_error_details_logic_for_response_parser($response_body);
    return $error_details['message'];
}

/**
 * Extracts the current OpenRouter error envelope from standard and Responses SSE payloads.
 *
 * OpenRouter exposes the stable provider error category as `error_type`. Responses
 * streaming failures wrap the same envelope under `response`, while pre-stream and
 * standard failures expose it at the top level.
 *
 * @param mixed $response_body Raw JSON, decoded error payload, or Responses SSE payload.
 * @return array{message: string, error_type: string}
 */
function extract_error_details_logic_for_response_parser($response_body): array {
    $default_message = __('An unknown API error occurred.', 'gpt3-ai-content-generator');
    $decoded = is_string($response_body) ? json_decode($response_body, true) : $response_body;

    if (!is_array($decoded)) {
        $raw_message = is_string($response_body) ? trim(substr($response_body, 0, 200)) : '';
        return [
            'message' => $raw_message !== '' ? $raw_message : $default_message,
            'error_type' => '',
        ];
    }

    $envelope = isset($decoded['response']) && is_array($decoded['response'])
        ? $decoded['response']
        : $decoded;
    $error = isset($envelope['error']) && is_array($envelope['error'])
        ? $envelope['error']
        : [];

    $message = $default_message;
    if (!empty($error['message']) && is_string($error['message'])) {
        $message = $error['message'];
    } elseif (!empty($envelope['detail'])) {
        $message = is_string($envelope['detail'])
            ? $envelope['detail']
            : (string) wp_json_encode($envelope['detail']);
    } elseif (!empty($envelope['message']) && is_string($envelope['message'])) {
        $message = $envelope['message'];
    }

    $error_code = '';
    if (isset($error['code']) && (is_string($error['code']) || is_numeric($error['code']))) {
        $error_code = sanitize_text_field((string) $error['code']);
    }

    $error_type = '';
    $raw_error_type = $envelope['error_type']
        ?? ($error['error_type'] ?? ($error['metadata']['error_type'] ?? ''));
    if (is_string($raw_error_type)) {
        $error_type = sanitize_key($raw_error_type);
    }

    $qualifiers = [];
    if ($error_code !== '') {
        $qualifiers[] = sprintf('Code: %s', $error_code);
    }
    if ($error_type !== '' && $error_type !== sanitize_key($error_code)) {
        $qualifiers[] = sprintf('Type: %s', $error_type);
    }
    if (!empty($qualifiers)) {
        $message .= ' (' . implode('; ', $qualifiers) . ')';
    }

    return [
        'message' => trim($message),
        'error_type' => $error_type,
    ];
}

/**
 * Logic for the parse_sse_chunk method of OpenRouterProviderStrategy.
 *
 * @param OpenRouterProviderStrategy $strategyInstance The instance of the strategy class.
 * @param string $sse_chunk The raw chunk received from the stream.
 * @param string &$current_buffer The reference to the incomplete buffer for this provider.
 * @return array Result containing delta, usage, flags.
 */
function parse_sse_chunk_logic(
    OpenRouterProviderStrategy $strategyInstance,
    string $sse_chunk,
    string &$current_buffer
): array {
    return \WPAICG\Core\Providers\OpenRouter\OpenRouterResponseParser::parse_sse_chunk($sse_chunk, $current_buffer);
}


/**
 * Logic for the parse_sse_chunk static method of OpenRouterResponseParser.
 *
 * @param string $sse_chunk The raw chunk received.
 * @param string &$current_buffer Reference to the incomplete buffer.
 * @return array Result containing delta, usage, flags.
 */
function parse_sse_chunk_logic_for_response_parser(string $sse_chunk, string &$current_buffer): array {
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
 * Applies an internal typed event to the flattened parse result expected by the stream processor.
 *
 * @param array<string, mixed> $mapped_event
 * @param array<string, mixed> $result
 * @return bool True when parsing should stop immediately.
 */
function reduce_sse_event_logic_for_response_parser(array $mapped_event, array &$result): bool {
    $kind = isset($mapped_event['kind']) && is_string($mapped_event['kind']) ? $mapped_event['kind'] : 'skip';

    if (isset($mapped_event['citations']) && is_array($mapped_event['citations']) && !empty($mapped_event['citations'])) {
        $existing_citations = isset($result['citations']) && is_array($result['citations'])
            ? $result['citations']
            : [];
        $result['citations'] = dedupe_citations_logic_for_response_parser(
            array_merge($existing_citations, $mapped_event['citations'])
        );
    }

    switch ($kind) {
        case 'status':
            if (isset($mapped_event['status']) && is_array($mapped_event['status'])) {
                $result['status'] = $mapped_event['status'];
            }
            return false;

        case 'citations':
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

        case 'warning':
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
            if (isset($mapped_event['usage']) && is_array($mapped_event['usage'])) {
                $result['usage'] = $mapped_event['usage'];
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

        case 'legacy_chunk':
            if (isset($mapped_event['usage']) && is_array($mapped_event['usage'])) {
                $result['usage'] = $mapped_event['usage'];
            }
            if (!empty($mapped_event['text']) && is_string($mapped_event['text'])) {
                if ($result['delta'] === null) {
                    $result['delta'] = '';
                }
                $result['delta'] .= $mapped_event['text'];
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

namespace WPAICG\Core\Providers;

class OpenRouterProviderStrategy extends BaseProviderStrategy {

    /**
     * @return string|\WP_Error
     */
    public function build_api_url(string $operation, array $params) {
        return OpenRouter\Methods\build_api_url_logic($this, $operation, $params);
    }

    public function get_api_headers(string $api_key, string $operation): array {
        return OpenRouter\Methods\get_api_headers_logic($this, $api_key, $operation);
    }

    public function format_chat_payload(string $user_message, string $instructions, array $history, array $ai_params, string $model): array {
        return OpenRouter\Methods\format_chat_payload_logic($this, $user_message, $instructions, $history, $ai_params, $model);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function parse_chat_response(array $decoded_response, array $request_data) {
        return OpenRouter\Methods\parse_chat_response_logic($this, $decoded_response, $request_data);
    }

    public function parse_error_response($response_body, int $status_code): string {
        return OpenRouter\Methods\parse_error_response_logic($this, $response_body, $status_code);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function get_models(array $api_params) {
        return OpenRouter\Methods\get_models_logic($this, $api_params);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function get_image_models(array $api_params) {
        return OpenRouter\Methods\get_image_models_logic($this, $api_params);
    }

    public function build_sse_payload(array $messages, $system_instruction, array $ai_params, string $model): array {
        return OpenRouter\Methods\build_sse_payload_logic($this, $messages, $system_instruction, $ai_params, $model);
    }

    public function parse_sse_chunk(string $sse_chunk, string &$current_buffer): array {
        return OpenRouter\Methods\parse_sse_chunk_logic($this, $sse_chunk, $current_buffer);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function generate_embeddings($input, array $api_params, array $options = []) {
        return OpenRouter\Methods\generate_embeddings_logic($this, $input, $api_params, $options);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function get_embedding_models(array $api_params) {
        return OpenRouter\Methods\get_embedding_models_logic($this, $api_params);
    }
}
