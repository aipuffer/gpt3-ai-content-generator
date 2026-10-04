<?php

namespace WPAICG\Core\Providers; 

use WP_Error;
use WPAICG\Core\Providers\Traits\ChatCompletionsPayloadTrait; 
use WPAICG\Core\Providers\Traits\ChatCompletionsResponseParserTrait; 
use WPAICG\Core\Providers\Traits\ChatCompletionsSSEParserTrait; 
use WPAICG\Core\Models\AIPKit_Model_Catalog;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * DeepSeek Provider Strategy.
 * Uses standard Chat Completions format.
 * Uses traits for payload formatting, response parsing, and SSE parsing.
 * @since NEXT_VERSION
 */
class DeepSeekProviderStrategy extends BaseProviderStrategy {
    use ChatCompletionsPayloadTrait;
    use ChatCompletionsResponseParserTrait;
    use ChatCompletionsSSEParserTrait;

    /**
     * @return string|\WP_Error
     */
    public function build_api_url(string $operation, array $params) {
        $base_url = !empty($params['base_url']) ? rtrim($params['base_url'], '/') : '';
        $api_version = !empty($params['api_version']) ? $params['api_version'] : ''; 

        if (empty($base_url)) return new WP_Error("missing_base_url_DeepSeek", __('DeepSeek Base URL is required.', 'gpt3-ai-content-generator'));

        $paths = [
            'chat'   => '/chat/completions', 
            'models' => '/models',
        ];
        $path_key = ($operation === 'stream') ? 'chat' : $operation;
        $path_segment = $paths[$path_key] ?? null;

        if ($path_segment === null) {
            /* translators: %s: The operation name (e.g., "chat", "models"). */
            return new WP_Error('unsupported_operation_DeepSeek', sprintf(__('Operation "%s" not supported for DeepSeek.', 'gpt3-ai-content-generator'), $operation));
        }

        $full_path = $path_segment;
        if (!empty($api_version) && strpos($base_url, '/' . trim($api_version, '/')) === false) {
            $full_path = '/' . trim($api_version, '/') . $path_segment;
        }

        return $base_url . $full_path;
    }

    public function get_api_headers(string $api_key, string $operation): array {
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

    public function format_chat_payload(string $user_message, string $instructions, array $history, array $ai_params, string $model): array {
       $payload = $this->format_chat_completions_payload($instructions, $history, $user_message, $ai_params, $model, true);
       return $this->apply_deepseek_thinking_control($payload, $ai_params);
    }

    public function parse_error_response($response_body, int $status_code): string {
        $message = __('An unknown API error occurred.', 'gpt3-ai-content-generator');
        $decoded = is_string($response_body) ? json_decode($response_body, true) : $response_body;

        if (is_array($decoded)) {
            if (!empty($decoded['error']['message'])) {
                $message = $decoded['error']['message'];
                if (!empty($decoded['error']['code'])) { $message .= ' (Code: ' . $decoded['error']['code'] . ')'; }
                if (!empty($decoded['error']['type'])) { $message .= ' Type: ' . $decoded['error']['type']; }
            } elseif (!empty($decoded['message'])) { 
                $message = $decoded['message'];
            }
        } elseif (is_string($response_body)) {
             $message = substr($response_body, 0, 200); 
        }

        return trim($message);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function get_models(array $api_params) {
        $url = $this->build_api_url('models', $api_params);
        if (is_wp_error($url)) return $url;

        $headers = $this->get_api_headers($api_params['api_key'] ?? '', 'models');
        $options = $this->get_request_options('models');
        $options['method'] = 'GET';

        $response = wp_remote_get($url, array_merge($options, ['headers' => $headers]));
        if (is_wp_error($response)) return $response;

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
            $error_msg = $this->parse_error_response($body, $status_code);
            return new WP_Error('api_error_deepseek_models', sprintf('DeepSeek API Error (HTTP %d): %s', $status_code, $error_msg));
        }

        $decoded = $this->decode_json($body, 'DeepSeek Models');
        if (is_wp_error($decoded)) return $decoded;

        $raw_models = $decoded['data'] ?? [];
        if (!is_array($raw_models)) {
            return [];
        }

        $models = [];
        foreach ($raw_models as $model) {
            if (!is_array($model)) {
                continue;
            }

            $model_id = isset($model['id']) ? sanitize_text_field((string) $model['id']) : '';
            if ($model_id === '' || strpos($model_id, 'deepseek-') !== 0 || AIPKit_Model_Catalog::is_deprecated_id('DeepSeek', $model_id)) {
                continue;
            }

            $models[] = [
                'id' => $model_id,
                'name' => self::get_model_display_name($model_id),
                'owned_by' => sanitize_text_field((string) ($model['owned_by'] ?? 'deepseek')),
            ];
        }

        return self::sort_model_rows($models);
    }

    /**
     * @param array<int, array<string, string>> $models
     * @return array<int, array<string, string>>
     */
    private static function sort_model_rows(array $models): array {
        $priority = [];
        foreach (AIPKit_Model_Catalog::get_seed_ids('DeepSeek') as $index => $model_id) {
            $priority[$model_id] = ($index + 1) * 10;
        }

        usort($models, static function(array $a, array $b) use ($priority): int {
            $a_id = (string) ($a['id'] ?? '');
            $b_id = (string) ($b['id'] ?? '');
            $a_priority = $priority[$a_id] ?? 50;
            $b_priority = $priority[$b_id] ?? 50;

            if ($a_priority !== $b_priority) {
                return $a_priority <=> $b_priority;
            }

            return strcasecmp((string) ($a['name'] ?? $a_id), (string) ($b['name'] ?? $b_id));
        });

        return $models;
    }

    private static function get_model_display_name(string $model_id): string {
        $known_names = [];
        foreach (AIPKit_Model_Catalog::get_seed_rows('DeepSeek') as $row) {
            if (!is_array($row) || empty($row['id'])) {
                continue;
            }
            $known_names[(string) $row['id']] = (string) ($row['name'] ?? $row['id']);
        }

        if (isset($known_names[$model_id])) {
            return $known_names[$model_id];
        }

        $label = preg_replace('/^deepseek-/i', 'DeepSeek ', $model_id);
        $label = str_replace(['-', '_'], ' ', (string) $label);
        return ucwords($label);
    }

    public function build_sse_payload(array $messages, $system_instruction, array $ai_params, string $model): array {
        $payload = $this->format_sse_chat_completions_payload($messages, $system_instruction, $ai_params, $model, true, false);
        return $this->apply_deepseek_thinking_control($payload, $ai_params);
    }

    /**
     * DeepSeek V4 thinking can spend the completion budget before final content
     * is emitted. Content generation and rewrite flows expect final text, so keep
     * thinking off unless a caller deliberately provides a DeepSeek thinking or
     * reasoning parameter.
     */
    private function apply_deepseek_thinking_control(array $payload, array $ai_params): array {
        if (array_key_exists('thinking', $ai_params)) {
            $thinking = $this->normalize_deepseek_thinking_param($ai_params['thinking']);
            if ($thinking !== null) {
                $payload['thinking'] = $thinking;
                return $payload;
            }
        }

        if (array_key_exists('reasoning', $ai_params)) {
            $thinking = $this->normalize_deepseek_reasoning_param($ai_params['reasoning']);
            if ($thinking !== null) {
                $payload['thinking'] = $thinking;
                return $payload;
            }
        }

        $payload['thinking'] = ['type' => 'disabled'];
        return $payload;
    }

    private function normalize_deepseek_thinking_param($value): ?array {
        if (is_array($value)) {
            $type = isset($value['type']) ? strtolower(trim((string) $value['type'])) : '';
            if (!in_array($type, ['enabled', 'disabled'], true)) {
                return null;
            }

            $normalized = ['type' => $type];
            if ($type === 'enabled' && isset($value['budget_tokens']) && is_numeric($value['budget_tokens'])) {
                $budget_tokens = absint($value['budget_tokens']);
                if ($budget_tokens > 0) {
                    $normalized['budget_tokens'] = $budget_tokens;
                }
            }
            return $normalized;
        }

        if (is_bool($value)) {
            return ['type' => $value ? 'enabled' : 'disabled'];
        }

        if (is_string($value) || is_numeric($value)) {
            $normalized_value = strtolower(trim((string) $value));
            if (in_array($normalized_value, ['1', 'true', 'yes', 'on', 'enabled', 'enable'], true)) {
                return ['type' => 'enabled'];
            }
            if (in_array($normalized_value, ['0', 'false', 'no', 'off', 'disabled', 'disable', 'none'], true)) {
                return ['type' => 'disabled'];
            }
        }

        return null;
    }

    private function normalize_deepseek_reasoning_param($value): ?array {
        if (is_array($value)) {
            if (isset($value['thinking'])) {
                return $this->normalize_deepseek_thinking_param($value['thinking']);
            }

            $effort = strtolower(trim((string) ($value['effort'] ?? $value['type'] ?? '')));
            if ($effort === '' || $effort === 'none' || $effort === 'disabled') {
                return ['type' => 'disabled'];
            }

            return ['type' => 'enabled'];
        }

        if (is_string($value) || is_numeric($value) || is_bool($value)) {
            $thinking = $this->normalize_deepseek_thinking_param($value);
            if ($thinking !== null) {
                return $thinking;
            }

            $effort = strtolower(trim((string) $value));
            if ($effort !== '' && $effort !== 'none') {
                return ['type' => 'enabled'];
            }
        }

        return null;
    }

    /**
     * Generate embeddings for the given input text(s).
     * DeepSeek API primarily focuses on chat completions and does not have a standard
     * /embeddings endpoint like OpenAI or Google.
     *
     * @param string|array $input The input text or array of texts.
     * @param array $api_params Provider-specific API connection parameters.
     * @param array $options Embedding options (model, dimensions, encoding_format, etc.).
     * @return array|WP_Error Always returns a WP_Error indicating not supported.
     */
    public function generate_embeddings($input, array $api_params, array $options = []) {
        return new WP_Error(
            'embeddings_not_supported_deepseek',
            __('Dedicated embedding generation is not supported for DeepSeek via this strategy.', 'gpt3-ai-content-generator')
        );
    }
}
