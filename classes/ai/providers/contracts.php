<?php

namespace WPAICG\Core\Providers;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Interface for AI Provider Strategies.
 * Defines the contract for handling provider-specific logic.
 */
interface ProviderStrategyInterface {
    public function validate_chatbot_features(array $settings, bool $has_image = false, bool $has_web_search = false): ?WP_Error;

    public function validate_stream_completion(): ?WP_Error;


    /**
     * Build the full API endpoint URL for a given operation.
     *
     * @param string $operation ('chat', 'models', 'stream', 'deployments', 'embeddings', etc.)
     * @param array  $params Required parameters (api_key, base_url, api_version, model, deployment, etc.)
     * @return string|WP_Error The full URL or WP_Error.
     */
    public function build_api_url(string $operation, array $params);

    /**
     * Get necessary HTTP headers for API requests.
     *
     * @param string $api_key The API key for the provider.
     * @param string $operation The specific operation being performed.
     * @return array Key-value array of headers.
     */
    public function get_api_headers(string $api_key, string $operation): array;

    /**
     * Format the payload (messages, instructions) for a standard chat request.
     *
     * @param string $user_message The user's message.
     * @param string $instructions System instructions.
     * @param array  $history Conversation history.
     * @param array  $ai_params AI parameters (temperature, max_tokens, etc.).
     * @param string $model The target model/deployment ID.
     * @return array|WP_Error The formatted request body data or a validation error.
     */
    public function format_chat_payload(string $user_message, string $instructions, array $history, array $ai_params, string $model);

    /**
     * Parse the response from a standard chat request.
     *
     * @param array $decoded_response The decoded JSON response body.
     * @param array $request_data The original request data sent.
     * @return array|WP_Error ['content' => string, 'usage' => array|null] or WP_Error.
     */
    public function parse_chat_response(array $decoded_response, array $request_data);

    /**
     * Parse an error response from the provider.
     *
     * @param mixed $response_body The raw or decoded error response body.
     * @param int $status_code The HTTP status code.
     * @return string A user-friendly error message.
     */
    public function parse_error_response($response_body, int $status_code): string;

    /**
     * Fetch the list of available models/deployments.
     *
     * @param array $api_params Connection parameters (api_key, base_url, etc.).
     * @return array|WP_Error Formatted list [['id' => ..., 'name' => ...]] or WP_Error.
     */
    public function get_models(array $api_params);

    /**
     * Build the payload for an SSE (streaming) chat request.
     *
     * @param array $messages Formatted messages/input/contents array.
     * @param string|array|null $system_instruction Formatted system instruction.
     * @param array $ai_params AI parameters.
     * @param string $model Target model/deployment.
     * @return array|WP_Error The formatted request body data or a validation error.
     */
    public function build_sse_payload(array $messages, $system_instruction, array $ai_params, string $model);

    /**
     * Parse a chunk of data received from an SSE stream.
     *
     * @param string $sse_chunk The raw chunk received from the stream.
     * @param string &$current_buffer The reference to the incomplete buffer for this provider.
     * @return array {
     *     'delta'      => string|null, // Text delta
     *     'usage'      => array|null,  // Token usage data
     *     'is_error'   => bool,        // Fatal error flag
     *     'is_warning' => bool,        // Non-fatal warning/block flag
     *     'is_done'    => bool         // End signal flag
     * }
     */
    public function parse_sse_chunk(string $sse_chunk, string &$current_buffer): array;

     /**
     * Get provider-specific request options for wp_remote_request or cURL.
     *
     * @param string $operation The operation ('chat', 'stream', 'models').
     * @return array Additional options (e.g., method, user-agent, timeout).
     */
    public function get_request_options(string $operation): array;

    /**
     * Build shared HTTP error metadata, including Retry-After when present.
     *
     * @param mixed $response WordPress HTTP API response.
     * @return array<string, int>
     */
    public function build_http_error_data_with_retry_after($response, int $status_code): array;

    /**
     * Format headers array into the ['Header: Value', ...] format needed by cURL.
     *
     * @param array $headers Key-value array of headers.
     * @return array Indexed array of header strings.
     */
    public function format_headers_for_curl(array $headers): array;

    /**
     * Generate embeddings for the given input text(s).
     *
     * @param string|array $input The input text or array of texts.
     * @param array $api_params Provider-specific API connection parameters.
     * @param array $options Embedding options (model, dimensions, encoding_format, etc.).
     * @return array|WP_Error An array of embedding vectors or WP_Error on failure.
     *                        Example success: ['embeddings' => [[0.1, ...], [0.2, ...]], 'usage' => [...]]
     */
    public function generate_embeddings($input, array $api_params, array $options = []);
}

/**
 * Abstract Base class for Provider Strategies.
 * Provides common helper methods.
 */
abstract class BaseProviderStrategy implements ProviderStrategyInterface
{
    /** Provider-specific validation before Chatbot builds a request. */
    public function validate_chatbot_features(array $settings, bool $has_image = false, bool $has_web_search = false): ?WP_Error
    {
        return null;
    }

    /** Providers with a required terminal event validate it here after transport ends. */
    public function validate_stream_completion(): ?WP_Error
    {
        return null;
    }

    /**
     * Common helper to parse JSON, returning a WP_Error on failure.
     * @param string $json_string The JSON string to decode.
     * @param string $context Context for error messages (e.g., "OpenAI Models").
     * @return array|WP_Error Decoded array or WP_Error.
     */
    public function decode_json(string $json_string, string $context)
    {
        if (trim($json_string) === '') {
            return [];
        }
        $decoded = json_decode($json_string, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            /* translators: %1$s: The context of the API call (e.g., "OpenAI Models"), %2$s: The specific JSON error message from PHP. */
            $error_message = sprintf(__('Failed to parse JSON response from %1$s. Error: %2$s', 'gpt3-ai-content-generator'), $context, json_last_error_msg());
            return new WP_Error('json_decode_error', $error_message);
        }
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Common helper to format model lists based on specified ID and name keys.
     * @param array $raw_models The array of raw model data from the API.
     * @param string $id_key The key in the raw data representing the model ID.
     * @param string $name_key The key in the raw data representing the display name.
     * @return array Formatted list [['id' => ..., 'name' => ...]].
     */
    public function format_model_list(array $raw_models, string $id_key = 'id', string $name_key = 'id'): array
    {
        $formatted = [];
        foreach ($raw_models as $model) {
            if (!is_array($model)) {
                continue;
            }
            $id = $model[$id_key] ?? null;
            if (!empty($id)) {
                $name = $model[$name_key] ?? $id;
                $formatted_item = [
                    'id'   => $id,
                    'name' => $name,
                    'status' => $model['status'] ?? null,
                    'version' => $model['version'] ?? null,
                ];
                if (isset($model['details']) && is_array($model['details'])) {
                    $formatted_item['details'] = $model['details'];
                }
                $formatted[] = $formatted_item;
            }
        }
        usort($formatted, fn ($a, $b) => strcasecmp($a['name'] ?? '', $b['name'] ?? ''));
        return $formatted;
    }

    /**
     * Format headers array into the ['Header: Value', ...] format needed by cURL.
     * @param array $headers Associative array of headers.
     * @return array Indexed array of header strings.
     */
    public function format_headers_for_curl(array $headers): array
    {
        $result = [];
        foreach ($headers as $k => $v) {
            $result[] = $k . ': ' . $v;
        }
        return $result;
    }

    /**
     * Get default request options for wp_remote_request/cURL. Providers can override.
     * @param string $operation The operation ('chat', 'stream', 'models').
     * @return array Request options.
     */
    public function get_request_options(string $operation): array
    {
        return [
           'method'     => 'POST',
           'timeout'    => ($operation === 'stream') ? 120 : 60,
           'user-agent' => 'AIPKit/' . (defined('WPAICG_VERSION') ? WPAICG_VERSION : '1.0') . '; ' . get_bloginfo('url'),
           // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress SSL verification hook.
           'sslverify'  => apply_filters('https_local_ssl_verify', true),
        ];
    }

    /**
     * Build WP_Error data with retry timing when the provider returns Retry-After.
     *
     * @param mixed $response WordPress HTTP API response.
     * @return array<string, int>
     */
    public function build_http_error_data_with_retry_after($response, int $status_code): array
    {
        $error_data = ['status' => $status_code];
        $retry_after_header = wp_remote_retrieve_header($response, 'retry-after');
        if (is_array($retry_after_header)) {
            $retry_after_header = reset($retry_after_header);
        }
        if (is_numeric($retry_after_header)) {
            $error_data['retry_after'] = (int) ceil((float) $retry_after_header);
        } elseif (is_string($retry_after_header) && $retry_after_header !== '') {
            $retry_after_time = strtotime($retry_after_header);
            if ($retry_after_time !== false) {
                $error_data['retry_after'] = max(1, $retry_after_time - time());
            }
        }

        return $error_data;
    }

    // --- Abstract methods to be implemented by concrete strategies ---
    /**
     * @return string|\WP_Error
     */
    abstract public function build_api_url(string $operation, array $params);
    abstract public function get_api_headers(string $api_key, string $operation): array;
    abstract public function format_chat_payload(string $user_message, string $instructions, array $history, array $ai_params, string $model);
    /**
     * @return mixed[]|\WP_Error
     */
    abstract public function parse_chat_response(array $decoded_response, array $request_data);
    abstract public function parse_error_response($response_body, int $status_code): string; // Kept abstract, to be implemented by each strategy
    /**
     * @return mixed[]|\WP_Error
     */
    abstract public function get_models(array $api_params);
    abstract public function build_sse_payload(array $messages, $system_instruction, array $ai_params, string $model);
    abstract public function parse_sse_chunk(string $sse_chunk, string &$current_buffer): array;
    /**
     * @return mixed[]|\WP_Error
     */
    abstract public function generate_embeddings($input, array $api_params, array $options = []);
}

/**
 * Creates and caches text-provider strategies using their existing class names.
 */
class ProviderStrategyFactory
{
    /** @var array<string, ProviderStrategyInterface> */
    private static $instances = [];

    /**
     * @param string $provider Provider name, including its canonical case.
     * @return ProviderStrategyInterface|WP_Error
     */
    public static function get_strategy(string $provider)
    {
        if (isset(self::$instances[$provider])) {
            return self::$instances[$provider];
        }

        $strategies = [
            'AIPufferCloud' => ['ai/providers/aipuffer-cloud.php', AIPufferCloudProviderStrategy::class],
            'OpenAI' => ['ai/providers/openai.php', OpenAIProviderStrategy::class],
            'OpenRouter' => ['ai/providers/openrouter.php', OpenRouterProviderStrategy::class],
            'Google' => ['ai/providers/google.php', GoogleProviderStrategy::class],
            'Azure' => ['ai/providers/azure.php', AzureProviderStrategy::class],
            'Claude' => ['ai/providers/claude.php', ClaudeProviderStrategy::class],
            'DeepSeek' => ['ai/providers/deepseek.php', DeepSeekProviderStrategy::class],
            'xAI' => ['ai/providers/xai.php', XAIProviderStrategy::class],
            'Ollama' => ['ai/providers/ollama.php', AIPKit_Ollama_Strategy::class],
        ];
        if (!isset($strategies[$provider])) {
            /* translators: %s: The name of the AI provider. */
            return new WP_Error('unsupported_provider_strategy', sprintf(__('Provider strategy for "%s" is not defined in loader map.', 'gpt3-ai-content-generator'), esc_html($provider)));
        }

        [$relative_path, $strategy_class] = $strategies[$provider];
        $strategy_file = dirname(__DIR__, 2) . '/' . $relative_path;
        if (!file_exists($strategy_file)) {
            /* translators: %s: The name of the AI provider (e.g., 'OpenAI'). */
            return new WP_Error('strategy_file_not_found', sprintf(__('Strategy file not found for provider: %s', 'gpt3-ai-content-generator'), esc_html($provider)));
        }
        require_once $strategy_file;

        if (!class_exists($strategy_class)) {
            /* translators: %s: The name of the AI provider. */
            return new WP_Error('strategy_instantiation_failed', sprintf(__('Failed to load strategy for provider: %s', 'gpt3-ai-content-generator'), esc_html($provider)));
        }

        self::$instances[$provider] = new $strategy_class();
        return self::$instances[$provider];
    }
}
