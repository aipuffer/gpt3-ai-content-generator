<?php

namespace WPAICG\Core\Providers\Shared;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decodes provider SSE blocks that use optional event fields and JSON data lines.
 *
 * @return array<string, mixed>|null
 */
function decode_event_type_sse_event_block(string $event_block): ?array
{
    $event_type = null;
    $event_data_lines = [];

    foreach (preg_split("/\r?\n/", $event_block) as $line) {
        $line = rtrim((string) $line, "\r");

        if ($line === '' || $line[0] === ':' || strpos($line, ':') === false) {
            continue;
        }

        [$field, $value] = explode(':', $line, 2);
        $field = trim($field);
        $value = ltrim((string) $value, ' ');

        if ($field === 'event') {
            $event_type = trim($value);
        } elseif ($field === 'data') {
            $event_data_lines[] = $value;
        }
    }

    if (empty($event_data_lines)) {
        return null;
    }

    $event_data = implode("\n", $event_data_lines);
    if ($event_data === '[DONE]') {
        return [
            'event' => '[DONE]',
            'payload' => null,
        ];
    }

    $decoded_data = json_decode($event_data, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded_data)) {
        return null;
    }

    if (($event_type === null || $event_type === '') && isset($decoded_data['type']) && is_string($decoded_data['type'])) {
        $event_type = $decoded_data['type'];
    }

    return [
        'event' => ($event_type === null || $event_type === '') ? 'message' : $event_type,
        'payload' => $decoded_data,
    ];
}

/**
 * Decodes provider SSE blocks that only consume JSON data lines.
 *
 * @return array<string, mixed>|null
 */
function decode_data_only_sse_event_block(string $event_block): ?array
{
    $event_data_lines = [];

    foreach (preg_split("/\r?\n/", $event_block) as $line) {
        $line = rtrim((string) $line, "\r");

        if ($line === '' || $line[0] === ':' || strpos($line, ':') === false) {
            continue;
        }

        [$field, $value] = explode(':', $line, 2);
        if (trim($field) === 'data') {
            $event_data_lines[] = ltrim((string) $value, ' ');
        }
    }

    if (empty($event_data_lines)) {
        return null;
    }

    $event_data = trim(implode("\n", $event_data_lines));
    if ($event_data === '[DONE]') {
        return [
            'kind' => 'done',
            'payload' => null,
        ];
    }

    $decoded_data = json_decode($event_data, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded_data)) {
        return null;
    }

    return [
        'kind' => 'payload',
        'payload' => $decoded_data,
    ];
}

/**
 * Extract complete SSE event blocks from the buffer while leaving any partial tail intact.
 *
 * @param string $current_buffer Stream buffer, updated to the unconsumed suffix.
 * @param int $max_events Positive limit on nonempty blocks; zero or negative consumes all complete blocks.
 * @return array<int, string>
 */
function extract_sse_event_blocks(string &$current_buffer, int $max_events = 0): array
{
    $event_blocks = [];

    while (preg_match("/\r?\n\r?\n/", $current_buffer, $separator_match, PREG_OFFSET_CAPTURE) === 1) {
        $separator_offset = (int) $separator_match[0][1];
        $separator_length = strlen((string) $separator_match[0][0]);
        $event_block = (string) substr($current_buffer, 0, $separator_offset);
        $current_buffer = (string) substr($current_buffer, $separator_offset + $separator_length);

        if (trim($event_block) !== '') {
            $event_blocks[] = $event_block;
            if ($max_events > 0 && count($event_blocks) >= $max_events) {
                break;
            }
        }
    }

    return $event_blocks;
}

namespace WPAICG\Core\Providers\Traits;

use WP_Error;
use function WPAICG\Core\Providers\Shared\extract_sse_event_blocks;

/**
 * Trait for formatting payloads compatible with OpenAI Chat Completions API.
 */
trait ChatCompletionsPayloadTrait {

    /**
     * Formats the payload for chat completions.
     *
     * @param string $instructions System instructions.
     * @param array  $history      Conversation history [{role: 'user'|'assistant', content: '...'}].
     * @param string $user_message The latest user message.
     * @param array  $ai_params    AI parameters (temperature, max_tokens, etc.).
     * @param string $model        Model name (required by some providers in payload).
     * @param bool   $include_model Whether to include the 'model' key in the payload.
     * @return array The formatted payload.
     */
    protected function format_chat_completions_payload(
        string $instructions,
        array $history,
        string $user_message,
        array $ai_params,
        string $model,
        bool $include_model = false
    ): array {
        $messages = [];
        if (!empty($instructions)) {
            $messages[] = ['role' => 'system', 'content' => $instructions];
        }
        foreach ($history as $msg) {
            $role = ($msg['role'] === 'bot') ? 'assistant' : $msg['role'];
            $content = isset($msg['content']) ? trim($msg['content']) : '';
            if ($content !== '' && in_array($role, ['system', 'user', 'assistant'])) {
                $messages[] = ['role' => $role, 'content' => $content];
            }
        }
        // Add the latest user message if provided
        if (!empty($user_message)) {
            $messages[] = ['role' => 'user', 'content' => $user_message];
        }

        $body_data = ['messages' => $messages];

        if ($include_model) {
            $body_data['model'] = $model;
        }

        // Map AIPKit standard AI params to Chat Completions API params
        $param_map = [
            'temperature' => 'temperature',
            'max_completion_tokens' => 'max_tokens', // API uses 'max_tokens'
            'top_p' => 'top_p',
            'stop' => 'stop',
        ];

        foreach ($param_map as $aipkit_key => $api_key) {
            if (isset($ai_params[$aipkit_key])) {
                $value = $ai_params[$aipkit_key];
                if (in_array($api_key, ['temperature', 'top_p'])) {
                    $body_data[$api_key] = floatval($value);
                } elseif ($api_key === 'max_tokens') {
                    $body_data[$api_key] = absint($value);
                } elseif ($api_key === 'stop' && !empty($value)) {
                    // Ensure 'stop' is an array or null
                    $body_data[$api_key] = is_string($value) ? [$value] : (is_array($value) ? $value : null);
                    if (empty($body_data[$api_key])) unset($body_data[$api_key]);
                }
            }
        }

        return $body_data;
    }

     /**
     * Formats the payload for SSE chat completions.
     *
     * @param array  $messages     Formatted messages array.
     * @param string $instructions System instructions.
     * @param array  $ai_params    AI parameters.
     * @param string $model        Model name.
     * @param bool   $include_model Whether to include the 'model' key in the payload.
     * @param bool   $request_usage Whether to request usage data in the stream.
     * @return array The formatted SSE payload.
     */
    protected function format_sse_chat_completions_payload(
        array $messages,
        string $instructions,
        array $ai_params,
        string $model,
        bool $include_model = false,
        bool $request_usage = true
    ): array {
        // Use the base formatter, passing empty user message as it's already in $messages
        $payload = $this->format_chat_completions_payload($instructions, $messages, '', $ai_params, $model, $include_model);

        // Add stream flag
        $payload['stream'] = true;

        // Add usage request if desired and supported (OpenAI API specific)
        if ($request_usage
            && !($this instanceof \WPAICG\Core\Providers\OpenAIProviderStrategy)
            && ($this instanceof \WPAICG\Core\Providers\AzureProviderStrategy || $this instanceof \WPAICG\Core\Providers\OpenRouterProviderStrategy)
        ) {
             $payload['stream_options'] = ['include_usage' => true];
        }


        return $payload;
    }
}

/**
 * Trait for parsing responses from OpenAI Chat Completions compatible APIs.
 */
trait ChatCompletionsResponseParserTrait {

    /**
     * Parses a standard Chat Completions API response.
     *
     * @param array $decoded_response The decoded JSON response.
     * @param array $request_data     The original request data, including the selected model.
     * @return array|WP_Error ['content' => string, 'usage' => array|null] or WP_Error.
     */
    public function parse_chat_response(array $decoded_response, array $request_data) {
        $content = null;
        $choice = (isset($decoded_response['choices'][0]) && is_array($decoded_response['choices'][0]))
            ? $decoded_response['choices'][0]
            : [];
        $message = (isset($choice['message']) && is_array($choice['message']))
            ? $choice['message']
            : [];
        $has_content_field = array_key_exists('content', $message);

        // Standard Chat Completions structure
        if ($has_content_field) {
            $content = $message['content'] === null ? '' : trim((string) $message['content']);
        } elseif (isset($choice['delta']) && is_array($choice['delta']) && array_key_exists('content', $choice['delta']) && $choice['delta']['content'] !== null) { // Handle potential stream-like response structure
            $content = trim((string) $choice['delta']['content']);
        } elseif (array_key_exists('text', $choice) && $choice['text'] !== null) { // Handle older text field if present
             $content = trim((string) $choice['text']);
        }

        if ($content === null || $content === '') {
             // Check for specific errors like content filters before declaring invalid structure
             if (isset($choice['finish_reason']) && $choice['finish_reason'] === 'content_filter') {
                 return new WP_Error('content_filter', __('Response blocked due to content filtering.', 'gpt3-ai-content-generator'));
             }

            $finish_reason = isset($choice['finish_reason']) ? (string) $choice['finish_reason'] : '';
            $error_data = [
                'finish_reason' => $finish_reason,
            ];
            if (isset($decoded_response['usage']) && is_array($decoded_response['usage'])) {
                $error_data['usage'] = $decoded_response['usage'];
            }

            if ($content === '' || in_array($finish_reason, ['length', 'insufficient_system_resource'], true)) {
                return new WP_Error(
                    'empty_response_chatcompletion',
                    $this->get_empty_chat_completion_message($finish_reason, $message, $decoded_response, $request_data),
                    $error_data
                );
            }

            return new WP_Error(
                'invalid_response_structure_chatcompletion',
                __('Unexpected response structure from Chat Completions API.', 'gpt3-ai-content-generator'),
                $error_data
            );
        }

        // Extract usage (standard Chat Completion format)
        $usage = null;
        if (isset($decoded_response['usage']) && is_array($decoded_response['usage'])) {
            $usage = [
                'input_tokens'  => $decoded_response['usage']['prompt_tokens'] ?? 0,
                'output_tokens' => $decoded_response['usage']['completion_tokens'] ?? 0,
                'total_tokens'  => $decoded_response['usage']['total_tokens'] ?? 0,
                'provider_raw' => $decoded_response['usage'], // Include raw provider usage
            ];
        }

        return ['content' => $content, 'usage' => $usage];
    }

    private function get_empty_chat_completion_message(string $finish_reason, array $message, array $decoded_response, array $request_data): string {
        $has_reasoning_output = !empty($message['reasoning_content']);
        $reasoning_tokens = $decoded_response['usage']['completion_tokens_details']['reasoning_tokens'] ?? 0;
        if (is_numeric($reasoning_tokens) && (int) $reasoning_tokens > 0) {
            $has_reasoning_output = true;
        }

        $model = strtolower((string) ($request_data['model'] ?? ''));
        $provider_label = (strpos($model, 'deepseek') !== false || $has_reasoning_output)
            ? 'DeepSeek'
            : __('The AI provider', 'gpt3-ai-content-generator');

        if ($finish_reason === 'length') {
            if ($has_reasoning_output) {
                return sprintf(
                    /* translators: %s: AI provider name. */
                    __('%s returned no final content because reasoning consumed the output budget. Disable thinking/reasoning for this task or increase max tokens.', 'gpt3-ai-content-generator'),
                    $provider_label
                );
            }

            return __('The AI provider returned no final content before reaching the max token limit. Increase max tokens or reduce the prompt size.', 'gpt3-ai-content-generator');
        }

        if ($finish_reason === 'insufficient_system_resource') {
            return sprintf(
                /* translators: %s: AI provider name. */
                __('%s could not produce a final answer because the provider reported insufficient system resources. Please retry, or switch models if it continues.', 'gpt3-ai-content-generator'),
                $provider_label
            );
        }

        return __('The AI provider returned an empty final response. Please retry, increase max tokens, or disable thinking/reasoning if the selected model supports it.', 'gpt3-ai-content-generator');
    }
}

/**
 * Trait for parsing Server-Sent Events (SSE) from OpenAI Chat Completions compatible APIs.
 * Provider strategies may supply their own protocol-specific parser.
 */
trait ChatCompletionsSSEParserTrait {

    /**
     * Parses an SSE chunk from a Chat Completions compatible stream.
     *
     * @param string $sse_chunk       The raw chunk received.
     * @param string &$current_buffer Reference to the incomplete buffer.
     * @return array Result containing delta, usage, flags.
     */
    public function parse_sse_chunk(string $sse_chunk, string &$current_buffer): array {
        $current_buffer .= $sse_chunk;
        $result = ['delta' => null, 'usage' => null, 'is_error' => false, 'is_warning' => false, 'is_done' => false];

        // Consume one block at a time so a fatal provider error preserves subsequent bytes.
        while ($event_blocks = extract_sse_event_blocks($current_buffer, 1)) {
            $event_block = $event_blocks[0];
            $event_data_json = null;

            foreach (explode("\n", $event_block) as $line) {
                $line = rtrim($line, "\r");
                if (empty($line) || strpos($line, ':') === false) continue;
                [$field, $value] = explode(':', $line, 2);
                $field = trim($field);
                $value = trim($value);
                if ($field === 'data') {
                    $event_data_json = $value;
                    break; // Found data line for this block
                }
            }

            if ($event_data_json === '[DONE]') {
                $result['is_done'] = true;
                continue; // Process next block if any
            }

            if ($event_data_json) {
                $decoded = json_decode($event_data_json, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    // Check for API error structure within the data payload
                    if (isset($decoded['error'])) {
                        $result['delta'] = $this->parse_error_response($decoded, 500); // Use the strategy's error parser
                        $result['is_error'] = true;
                        return $result; // Fatal error, stop processing
                    }

                    // Check for usage data (often comes at the end in stream_options)
                    if (isset($decoded['usage']) && is_array($decoded['usage'])) {
                        $result['usage'] = [
                            'input_tokens'  => $decoded['usage']['prompt_tokens'] ?? 0,
                            'output_tokens' => $decoded['usage']['completion_tokens'] ?? 0,
                            'total_tokens'  => $decoded['usage']['total_tokens'] ?? 0,
                            'provider_raw' => $decoded['usage'],
                        ];
                    }

                    // Check for content delta
                    if (isset($decoded['choices'][0]['delta']['content'])) {
                        $delta_text = $decoded['choices'][0]['delta']['content'];
                        if ($result['delta'] === null) { $result['delta'] = ''; }
                        $result['delta'] .= $delta_text;
                    }

                    // Check for finish reason which might indicate content filtering or other warnings
                    if (isset($decoded['choices'][0]['finish_reason'])) {
                        $finish_reason = $decoded['choices'][0]['finish_reason'];
                        if ($finish_reason === 'content_filter') {
                            if ($result['delta'] === null) { $result['delta'] = ''; }
                            $result['delta'] .= sprintf(' (%s)', __('Warning: Content Filtered', 'gpt3-ai-content-generator'));
                            $result['is_warning'] = true;
                        }
                    }
                }
            }
        } // End while loop processing blocks

        return $result;
    }
}
