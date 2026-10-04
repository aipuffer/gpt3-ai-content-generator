<?php

namespace WPAICG\Core\Stream\Processor;

use WPAICG\Core\Stream\Formatter\SSEResponseFormatter;
use WPAICG\Chat\Storage\LogStorage;
use WPAICG\Core\Providers\ProviderStrategyInterface;
use WPAICG\Core\TokenManager\AIPKit_Token_Manager;
use WPAICG\Core\Providers\ProviderStrategyFactory;
use WPAICG\Core\Providers\OpenAI\OpenAIApiMode;
use WPAICG\AIPKit_Providers;
use WPAICG\Core\AIPKit_Payload_Sanitizer;
use WPAICG\Core\AIPKit_HTTP_Request;
use WPAICG\Core\AIPKit_Event_Webhooks;
use WPAICG\Core\TokenManager\Constants\GuestTableConstants;
use WPAICG\Lib\Streaming\ProcessorTriggers;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

require_once dirname(__DIR__) . '/runtime-diagnostics.php';
require_once dirname(__DIR__) . '/ai/http.php';

/**
 * Handles the actual SSE connection, chunked streaming, and final logging of the bot response.
 * Uses Provider Strategies for provider-specific logic.
 */
class SSEStreamProcessor {

    private $formatter;
    private $log_storage;
    private ?AIPKit_Token_Manager $token_manager = null;
    private ?ProviderStrategyInterface $strategy = null;

    public $current_provider = null;
    public $current_model    = null;
    public $current_conversation_uuid = null;
    public $current_bot_message_id = null;
    public $incomplete_sse_buffer  = '';
    public $data_sent_to_frontend  = false;
    public $full_bot_response      = '';
    public $final_usage_data       = null;
    public $log_base_data          = [];
    public $error_occurred         = false;
    public $stream_parse_error     = null;
    public $stream_error_data      = [];
    public $request_payload_log    = null;
    public $current_openai_response_id = null;
    public $used_previous_openai_response_id = false;
    public $current_google_interaction_id = null;
    public $used_previous_google_interaction_id = false;
    public $google_conversation_state_enabled = false;
    public $grounding_metadata = null;
    public $citations = null;
    public $current_stream_context = 'chat';
    public $vector_search_scores = []; // Store vector search scores for logging

    public function __construct(SSEResponseFormatter $formatter, LogStorage $log_storage) {
        $this->formatter   = $formatter;
        $this->log_storage = $log_storage;
        if (!class_exists(\WPAICG\Core\TokenManager\AIPKit_Token_Manager::class)) {
            $token_manager_path = WPAICG_PLUGIN_DIR . 'classes/usage/tokens.php';
            if (file_exists($token_manager_path)) {
                require_once $token_manager_path;
            }
        }
        if (class_exists(\WPAICG\Core\TokenManager\AIPKit_Token_Manager::class)) {
            $this->token_manager = new \WPAICG\Core\TokenManager\AIPKit_Token_Manager();
        }
    }

    // --- Getters and Setters for state properties ---
    public function get_log_storage(): LogStorage { return $this->log_storage; }
    public function get_token_manager(): ?AIPKit_Token_Manager { return $this->token_manager; }
    public function get_formatter(): SSEResponseFormatter { return $this->formatter; }
    public function get_strategy(): ?ProviderStrategyInterface { return $this->strategy; }
    public function set_strategy(ProviderStrategyInterface $strategy): void { $this->strategy = $strategy; }
    public function get_current_provider(): ?string { return $this->current_provider; }
    public function get_current_model(): ?string { return $this->current_model; }
    public function get_current_conversation_uuid(): ?string { return $this->current_conversation_uuid; }
    public function get_current_bot_message_id(): ?string { return $this->current_bot_message_id; }
    public function get_incomplete_sse_buffer(): string { return $this->incomplete_sse_buffer; }
    public function set_incomplete_sse_buffer(string $buffer): void { $this->incomplete_sse_buffer = $buffer; }
    public function append_to_incomplete_sse_buffer(string $chunk): void { $this->incomplete_sse_buffer .= $chunk; }
    public function get_data_sent_to_frontend_status(): bool { return $this->data_sent_to_frontend; }
    public function set_data_sent_to_frontend_status(bool $status): void { $this->data_sent_to_frontend = $status; }
    public function get_full_bot_response(): string { return $this->full_bot_response; }
    public function append_to_full_bot_response(string $delta): void { $this->full_bot_response .= $delta; }
    public function get_final_usage_data(): ?array { return $this->final_usage_data; }
    public function set_final_usage_data(?array $data): void { $this->final_usage_data = $data; }
    public function get_log_base_data(): array { return $this->log_base_data; }
    public function get_error_occurred_status(): bool { return $this->error_occurred; }
    public function set_error_occurred_status(bool $status): void { $this->error_occurred = $status; }
    public function get_request_payload_log(): ?array { return $this->request_payload_log; }
    public function set_request_payload_log(?array $payload): void { $this->request_payload_log = $payload; }
    public function get_current_openai_response_id(): ?string { return $this->current_openai_response_id; }
    public function set_current_openai_response_id(?string $id): void { $this->current_openai_response_id = $id; }
    public function get_used_previous_openai_response_id_status(): bool { return $this->used_previous_openai_response_id; }
    public function get_current_google_interaction_id(): ?string { return $this->current_google_interaction_id; }
    public function set_current_google_interaction_id(?string $id): void { $this->current_google_interaction_id = $id; }
    public function get_used_previous_google_interaction_id_status(): bool { return $this->used_previous_google_interaction_id; }
    public function get_google_conversation_state_enabled_status(): bool { return $this->google_conversation_state_enabled; }
    public function get_grounding_metadata(): ?array { return $this->grounding_metadata; }
    public function set_grounding_metadata(?array $metadata): void { $this->grounding_metadata = $metadata; }
    public function get_citations(): ?array { return $this->citations; }
    public function append_citations(array $citations): void {
        if ($this->citations === null) {
            $this->citations = [];
        }

        $seen = [];
        foreach ($this->citations as $existing_citation) {
            $encoded = wp_json_encode($existing_citation);
            if (is_string($encoded)) {
                $seen[$encoded] = true;
            }
        }

        foreach ($citations as $citation) {
            if (!is_array($citation) || empty($citation)) {
                continue;
            }

            $encoded = wp_json_encode($citation);
            if (!is_string($encoded) || isset($seen[$encoded])) {
                continue;
            }

            $seen[$encoded] = true;
            $this->citations[] = $citation;
        }
    }
    public function get_current_stream_context(): string { return $this->current_stream_context; }
    public function get_vector_search_scores(): array {
        return $this->vector_search_scores;
    }
    public function set_vector_search_scores(array $scores): void {
        $this->vector_search_scores = $scores;
    }

    public function initialize_stream_state(string $provider, string $model, string $conversation_uuid, ?string $bot_message_id, array $base_log_data, string $stream_context, bool $used_previous_openai_id, bool $google_state_enabled, bool $used_previous_google_id): void {
        $this->current_provider = $provider; $this->current_model = $model;
        $this->current_conversation_uuid = $conversation_uuid; $this->current_bot_message_id = $bot_message_id;
        $this->incomplete_sse_buffer = '';
        $this->data_sent_to_frontend = false; $this->full_bot_response = ''; $this->final_usage_data = null;
        $this->log_base_data = $base_log_data; $this->current_stream_context = $stream_context;
        $this->stream_parse_error = null;
        $this->stream_error_data = [];
        $this->error_occurred = false; $this->request_payload_log = null; $this->current_openai_response_id = null;
        $this->used_previous_openai_response_id = $used_previous_openai_id;
        $this->current_google_interaction_id = null;
        $this->google_conversation_state_enabled = $google_state_enabled;
        $this->used_previous_google_interaction_id = $used_previous_google_id;
        $this->grounding_metadata = null; $this->citations = null;
        // NOTE: vector_search_scores should NOT be reset here as they are set before streaming starts
    }
    // --- End Getters and Setters ---

    /**
     * Main entry point to start the streaming process.
     * Now delegates to the namespaced start_stream_logic function.
     */
    public function start_stream(string $provider, string $model, string $user_message, array $history, string $system_instruction_filtered, array $api_params, array $ai_params, string $conversation_uuid, array $base_log_data) {
        start_stream_logic($this, $provider, $model, $user_message, $history, $system_instruction_filtered, $api_params, $ai_params, $conversation_uuid, $base_log_data);
    }

    public function curl_stream_callback_public_wrapper($ch, string $chunk): int {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_stream_callback_logic -- Reason: Using cURL for streaming.
        return curl_stream_callback_logic($this, $ch, $chunk);
    }
}

/**
 * Starts the SSE stream processing.
 *
 * @param \WPAICG\Core\Stream\Processor\SSEStreamProcessor $processorInstance The instance of the processor class.
 * @param string $provider AI provider.
 * @param string $model AI model.
 * @param string $user_message User's message.
 * @param array  $history Conversation history.
 * @param string $system_instruction_filtered Processed system instruction.
 * @param array  $api_params API connection parameters.
 * @param array  $ai_params AI generation parameters.
 * @param string $conversation_uuid Conversation UUID.
 * @param array  $base_log_data Base data for logging.
 * @return void
 * @throws \Exception If strategy cannot be obtained or URL build fails.
 */
function start_stream_logic(
    \WPAICG\Core\Stream\Processor\SSEStreamProcessor $processorInstance,
    string $provider,
    string $model,
    string $user_message,
    array  $history,
    string $system_instruction_filtered,
    array  $api_params,
    array  $ai_params,
    string $conversation_uuid,
    array  $base_log_data
): void {
    $formatter = $processorInstance->get_formatter();

    if ($provider === 'Google') {
        $model = AIPKit_Providers::normalize_google_text_model($model);
    }
    if ($provider === 'OpenAI') {
        $openai_provider_data = AIPKit_Providers::get_provider_data('OpenAI');
        $openai_api_mode = OpenAIApiMode::normalize($openai_provider_data['api_mode'] ?? null);
        $api_params['api_mode'] = $openai_api_mode;
        $ai_params['api_mode'] = $openai_api_mode;

        if (OpenAIApiMode::is_chat_completions($openai_api_mode)) {
            $ai_params['store_conversation'] = '0';
            $ai_params['use_openai_conversation_state'] = false;
            unset(
                $ai_params['previous_response_id'],
                $ai_params['vector_store_tool_config'],
                $ai_params['web_search_tool_config'],
                $ai_params['frontend_web_search_active']
            );
        }
    }

    $triggers = class_exists(ProcessorTriggers::class)
        ? new ProcessorTriggers($processorInstance, $base_log_data, $provider, $model)
        : null;

    try {
        $strategy = ProviderStrategyFactory::get_strategy($provider);
        if (is_wp_error($strategy)) {
            if ($triggers) {
                $triggers->error($strategy->get_error_code(), $strategy->get_error_message(), 'get_strategy_for_stream', null, true);
            }
            throw new \Exception($strategy->get_error_message()); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $processorInstance->set_strategy($strategy);

        $processorInstance->initialize_stream_state(
            $provider,
            $model,
            $conversation_uuid,
            ($base_log_data['bot_message_id'] ?? null),
            $base_log_data,
            ($base_log_data['module'] ?? 'chat'),
            ($provider === 'OpenAI' && !empty($ai_params['previous_response_id'])),
            ($provider === 'Google' && !empty($ai_params['use_google_conversation_state'])),
            ($provider === 'Google' && !empty($ai_params['google_previous_interaction_id']))
        );

        if (empty($processorInstance->get_current_bot_message_id())) {
            throw new \Exception('Internal error: Missing bot message ID for stream.');
        }

        $url_operation = 'stream';
        $url_params = array_merge($api_params, ['model' => $model, 'deployment' => $model]);
        $endpoint_url = $strategy->build_api_url($url_operation, $url_params);
        if (is_wp_error($endpoint_url)) {
            if ($triggers) {
                $triggers->error($endpoint_url->get_error_code(), $endpoint_url->get_error_message(), 'build_stream_url', null, true);
            }
            throw new \Exception($endpoint_url->get_error_message()); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $headers = $strategy->get_api_headers($api_params['api_key'], 'stream');
        $curl_headers = $strategy->format_headers_for_curl($headers);

        $messages_for_strategy_payload = $history;
        if (($processorInstance->get_current_stream_context() === 'content_writer' || $processorInstance->get_current_stream_context() === 'ai_forms') && !empty($user_message)) {
            $messages_for_strategy_payload[] = ['role' => 'user', 'content' => $user_message];
        }

        $final_ai_params = array_merge($ai_params, $api_params);
        if ($provider === 'Google' && !isset($final_ai_params['store_conversation'])) {
            $google_provider_data = AIPKit_Providers::get_provider_data('Google');
            $final_ai_params['store_conversation'] = $google_provider_data['store_conversation'] ?? '0';
        }

        $curl_post_data = $strategy->build_sse_payload(
            $messages_for_strategy_payload,
            $system_instruction_filtered,
            $final_ai_params,
            $model
        );
        if (is_wp_error($curl_post_data)) {
            throw new \Exception($curl_post_data->get_error_message()); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $sanitized_curl_post_data_for_log = AIPKit_Payload_Sanitizer::sanitize_for_logging($curl_post_data);
        $processorInstance->set_request_payload_log([
            'provider' => $provider, 'model' => $model, 'payload_sent' => $sanitized_curl_post_data_for_log,
        ]);

        $curl_post_data = apply_filters('aipkit_ai_query', $curl_post_data, $provider, $model, $history, $system_instruction_filtered, $api_params, $ai_params);
        $claude_beta_header_detector = '\WPAICG\Core\Providers\Claude\Methods\claude_payload_requires_files_beta_header';
        $claude_requires_files_beta_header = $provider === 'Claude'
            && is_array($curl_post_data)
            && function_exists($claude_beta_header_detector)
            && $claude_beta_header_detector($curl_post_data);
        if ($claude_requires_files_beta_header) {
            $headers['anthropic-beta'] = 'files-api-2025-04-14';
            $curl_headers = $strategy->format_headers_for_curl($headers);
        }
        $curl_post_json = json_encode($curl_post_data);
        // Post-encode sanitize: avoid environment-driven over-precision
        if (is_string($curl_post_json) && strpos($curl_post_json, 'score_threshold') !== false) {
            $curl_post_json = preg_replace_callback(
                '/("score_threshold"\s*:\s*)(-?\d+(?:\.\d+)?(?:[eE][+\-]?\d+)?)/',
                function ($m) {
                    $val = (float)$m[2];
                    if ($val <= 0) { $val = 0.0; }
                    elseif ($val >= 1) { $val = 1.0; }
                    else { $val = round($val, 6); }
                    $formatted = rtrim(rtrim(number_format($val, 6, '.', ''), '0'), '.');
                    if ($formatted === '' || $formatted === '-0') { $formatted = '0'; }
                    return $m[1] . $formatted;
                },
                $curl_post_json
            );
        }

        $curl_options_base = $strategy->get_request_options('stream');
        $stream_context = $processorInstance->get_current_stream_context();
        $timeout_base = isset($curl_options_base['timeout']) ? (int) $curl_options_base['timeout'] : 120;
        if (in_array($stream_context, ['ai_forms', 'content_writer'], true)) {
            $timeout_base = max($timeout_base, 300);
        }
        $timeout_base = (int) apply_filters(
            'aipkit_stream_timeout',
            $timeout_base,
            $provider,
            $model,
            $stream_context,
            $api_params,
            $ai_params
        );
        if ($timeout_base < 0) {
            $timeout_base = 0;
        }
        $transport_error = '';
        $transport_error_code = '';
        if (AIPKit_HTTP_Request::has_curl()) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- Reason: Using cURL for streaming.
            $ch = curl_init();
            if ($ch === false) {
                throw new \RuntimeException(__('Could not initialize the AI connection.', 'gpt3-ai-content-generator'));
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Reason: Using cURL for streaming.
            curl_setopt($ch, CURLOPT_URL, $endpoint_url);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Reason: Using cURL for streaming.
            curl_setopt($ch, CURLOPT_POST, true);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Reason: Using cURL for streaming.
            curl_setopt($ch, CURLOPT_POSTFIELDS, $curl_post_json);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Reason: Using cURL for streaming.
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_headers);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Reason: Using cURL for streaming.
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Reason: Using cURL for streaming.
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, [$processorInstance, 'curl_stream_callback_public_wrapper']);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Reason: Using cURL for streaming.
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Reason: Using cURL for streaming.
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout_base);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Reason: Using cURL for streaming.
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $curl_options_base['sslverify'] ?? true);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Reason: Using cURL for streaming.
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, ($curl_options_base['sslverify'] ?? true) ? 2 : 0);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Reason: Using cURL for streaming.
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            if (!empty($curl_options_base['user-agent'])) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Reason: Using cURL for streaming.
                curl_setopt($ch, CURLOPT_USERAGENT, $curl_options_base['user-agent']);
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec -- Reason: Using cURL for streaming.
            curl_exec($ch);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- Reason: Using cURL for streaming.
            $final_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_errno -- Reason: Using cURL for streaming.
            $curl_error_num  = curl_errno($ch);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error -- Reason: Using cURL for streaming.
            $curl_error_msg  = curl_error($ch);
            $ch = null;

        } else {
            // Keep the same provider payload, parser, logging and usage accounting. The
            // socket transport returns the answer when complete; no second AI request.
            $response = AIPKit_HTTP_Request::request($endpoint_url, [
                'method' => 'POST',
                'headers' => $headers,
                'body' => $curl_post_json,
                'timeout' => $timeout_base,
                'redirection' => 0,
                'sslverify' => $curl_options_base['sslverify'] ?? true,
                'user-agent' => $curl_options_base['user-agent'] ?? 'AI Puffer',
            ], true);
            $final_http_code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
            $curl_error_num = 0;
            $curl_error_msg = '';
            if (is_wp_error($response)) {
                $transport_error = $response->get_error_message();
                $transport_error_code = 'http_request_failed';
            } else {
                $parse_error = null;
                if (process_stream_chunk_logic($processorInstance, (string) wp_remote_retrieve_body($response), $final_http_code, $parse_error) < 0) {
                    $transport_error = $parse_error ?? __('The AI response was interrupted.', 'gpt3-ai-content-generator');
                    $transport_error_code = 'stream_response_error';
                }
            }
        }
        if ($curl_error_num) {
            // An intentional parser abort also produces CURLE_WRITE_ERROR.
            // Preserve the provider error already sent to the browser in logs/triggers.
            $transport_error = $processorInstance->stream_parse_error ?? $curl_error_msg;
            $transport_error_code = $processorInstance->stream_parse_error !== null ? 'stream_response_error' : 'curl_error_' . $curl_error_num;
        }

        if ($final_http_code < 400 && $transport_error === '') {
            $completion_error = $strategy->validate_stream_completion();
            if (is_wp_error($completion_error)) {
                $transport_error = $completion_error->get_error_message();
                $transport_error_code = $completion_error->get_error_code();
            }
        }

        if (!$processorInstance->get_error_occurred_status() && !empty($processorInstance->get_full_bot_response())) {
            log_bot_response_logic($processorInstance);
        }

        if ($transport_error !== '') {
            $transport_error_code = $processorInstance->stream_error_data['provider_error_code'] ?? $transport_error_code;
            $error_message = "Connection Error: {$transport_error}";
            if (!$processorInstance->get_error_occurred_status()) {
                $formatter->send_sse_error($error_message, false);
                $processorInstance->set_error_occurred_status(true);
            }
            log_bot_error_logic($processorInstance, $error_message);
            if ($triggers) {
                $triggers->error($transport_error_code, $transport_error, $curl_error_num ? 'stream_curl_execution' : 'stream_http_execution', $final_http_code);
            }
        } elseif ($final_http_code >= 400 && !$processorInstance->get_data_sent_to_frontend_status() && !$processorInstance->get_error_occurred_status()) {
            $api_error_message = $strategy->parse_error_response(trim($processorInstance->get_incomplete_sse_buffer()), $final_http_code);
            $formatter->send_sse_error("API Error: {$api_error_message}", false);
            $processorInstance->set_error_occurred_status(true);
            log_bot_error_logic($processorInstance, "API Error ({$final_http_code}): {$api_error_message}");
            if ($triggers) {
                $triggers->error('api_error_http_' . $final_http_code, $api_error_message, 'stream_api_response', $final_http_code);
            }
        } elseif ($final_http_code == 200 && !$processorInstance->get_data_sent_to_frontend_status() && empty($processorInstance->get_full_bot_response()) && !$processorInstance->get_error_occurred_status()) {
            $no_data_error_msg = "Connection error: no data received from AI.";
            $formatter->send_sse_error($no_data_error_msg, false);
            $processorInstance->set_error_occurred_status(true);
            log_bot_error_logic($processorInstance, $no_data_error_msg);
            if ($triggers) {
                $triggers->error('no_data_received', $no_data_error_msg, 'stream_empty_response', $final_http_code);
            }
        } elseif (!$processorInstance->get_error_occurred_status()) {
            $done_data = ['finished' => true];
            if ($processorInstance->get_grounding_metadata() !== null) {
                $done_data['grounding_metadata'] = $processorInstance->get_grounding_metadata();
            }
            if ($processorInstance->get_citations() !== null) {
                $done_data['citations'] = $processorInstance->get_citations();
            }
            $formatter->send_sse_event('done', $done_data);
        } else {
            $formatter->send_sse_done();
        }

    } catch (\Throwable $e) {
        $processorInstance->set_error_occurred_status(true);
        $reference = \WPAICG\RuntimeDiagnostics::report($e, 'ai_stream');
        /* translators: %s: Reference matching the PHP server error log. */
        $error_message_final = $e instanceof \Exception ? $e->getMessage() : sprintf(__('The AI request could not be completed. Please contact the site administrator. Reference: %s', 'gpt3-ai-content-generator'), $reference);
        $error_code_final = is_int($e->getCode()) && $e->getCode() !== 0 ? $e->getCode() : 500;
        $formatter->set_sse_headers();
        $formatter->send_sse_error($error_message_final);

        if ($triggers) {
            $triggers->error('stream_processor_exception', $error_message_final, 'stream_setup_exception', $error_code_final);
        }

        $formatter->send_sse_done();
    } finally {
        if ($formatter) {
            $formatter->finish_request();
        }
        exit;
    }
}

/**
 * cURL callback function to process stream chunks.
 *
 * @param \WPAICG\Core\Stream\Processor\SSEStreamProcessor $processorInstance The instance of the processor class.
 * @param resource $ch cURL handle.
 * @param string $chunk The data chunk.
 * @return int Length of the processed chunk.
 */
function curl_stream_callback_logic(\WPAICG\Core\Stream\Processor\SSEStreamProcessor $processorInstance, $ch, string $chunk): int {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- Native callback supplies the HTTP status to the common parser.
    return process_stream_chunk_logic($processorInstance, $chunk, (int) curl_getinfo($ch, CURLINFO_HTTP_CODE));
}

/** Parses provider events independently of the HTTP transport. */
function process_stream_chunk_logic(\WPAICG\Core\Stream\Processor\SSEStreamProcessor $processorInstance, string $chunk, int $http_code, ?string &$parse_error = null): int {
    $chunk_len = strlen($chunk);
    if ($chunk_len === 0 || !$processorInstance->get_strategy()) return 0;

    $data_sent_to_frontend = $processorInstance->get_data_sent_to_frontend_status();
    $incomplete_sse_buffer_ref = $processorInstance->get_incomplete_sse_buffer(); // Get initial value
    $formatter = $processorInstance->get_formatter();

    if (!$formatter) {
        return -1;
    }

    if ($http_code >= 400 && !$data_sent_to_frontend) {
        $processorInstance->append_to_incomplete_sse_buffer($chunk);
        return $chunk_len;
    }
    if ($http_code >= 400 && $data_sent_to_frontend) {
         return $chunk_len;
    }

    $parsed = $processorInstance->get_strategy()->parse_sse_chunk($chunk, $incomplete_sse_buffer_ref);
    $processorInstance->set_incomplete_sse_buffer($incomplete_sse_buffer_ref); // Update buffer in processor instance

    if ($parsed['usage'] !== null && is_array($parsed['usage'])) {
        $processorInstance->set_final_usage_data($parsed['usage']);
    }
    if (isset($parsed['openai_response_id']) && !empty($parsed['openai_response_id'])) {
        $processorInstance->set_current_openai_response_id($parsed['openai_response_id']);
        $formatter->send_sse_event('openai_response_id', ['id' => $parsed['openai_response_id']]);
    }
    if (
        isset($parsed['interaction_id'])
        && is_string($parsed['interaction_id'])
        && $parsed['interaction_id'] !== ''
        && $processorInstance->get_current_provider() === 'Google'
        && $processorInstance->get_google_conversation_state_enabled_status()
    ) {
        $processorInstance->set_current_google_interaction_id($parsed['interaction_id']);
        $formatter->send_sse_event('google_interaction_id', ['id' => $parsed['interaction_id']]);
    }
    if (isset($parsed['grounding_metadata']) && is_array($parsed['grounding_metadata'])) {
        $processorInstance->set_grounding_metadata($parsed['grounding_metadata']);
        $formatter->send_sse_event('grounding_metadata', $parsed['grounding_metadata']);
    }
    if (isset($parsed['citations']) && is_array($parsed['citations']) && !empty($parsed['citations'])) {
        $processorInstance->append_citations($parsed['citations']);
        $formatter->send_sse_event('citations', $parsed['citations']);
    }
    if (isset($parsed['status_event']) && is_array($parsed['status_event'])) {
        $formatter->send_sse_event('status', $parsed['status_event']);
    }
    if (isset($parsed['status']) && is_array($parsed['status'])) {
        $formatter->send_sse_event('status', $parsed['status']);
        $processorInstance->set_data_sent_to_frontend_status(true);
    }

    if ($parsed['is_error'] && $parsed['delta']) {
         $parse_error = $parsed['delta'];
         $processorInstance->stream_parse_error = $parse_error;
         $processorInstance->stream_error_data = is_array($parsed['error_data'] ?? null) ? $parsed['error_data'] : [];
         if (!$processorInstance->get_error_occurred_status()) {
            $formatter->send_sse_error($parsed['delta'], false, $processorInstance->stream_error_data);
            $processorInstance->set_error_occurred_status(true);
            // Error handler will call log_bot_error_logic and dispatch trigger
         }
         return -1; // Signal error to cURL
    }
    if ($parsed['is_warning'] && $parsed['delta']) {
         $formatter->send_sse_error($parsed['delta'], true);
         $processorInstance->append_to_full_bot_response($parsed['delta']);
         $processorInstance->set_data_sent_to_frontend_status(true);
    }
    if ($parsed['delta'] !== null && !$parsed['is_error'] && !$parsed['is_warning']) {
        $processorInstance->append_to_full_bot_response($parsed['delta']);
        $formatter->send_sse_data(['delta' => $parsed['delta']]);
        $processorInstance->set_data_sent_to_frontend_status(true);
    }

    if (connection_aborted()) {
        if (!$processorInstance->get_error_occurred_status()) { // Only set error if not already set
            $processorInstance->set_error_occurred_status(true);
        }
        return -1;
    }

    return $chunk_len;
}

/**
 * Emits the canonical AI Forms event after the generated response is fully available.
 *
 * @param array<string, mixed> $log_base_data
 * @param string $full_bot_response
 * @param string|null $current_provider
 * @param string|null $current_model
 * @param string|null $current_conversation_uuid
 * @param string|null $current_bot_message_id
 * @return void
 */
function emit_ai_forms_form_submitted_event_logic(
    array $log_base_data,
    string $full_bot_response,
    ?string $current_provider,
    ?string $current_model,
    ?string $current_conversation_uuid,
    ?string $current_bot_message_id
): void {
    if (!class_exists(AIPKit_Event_Webhooks::class)) {
        return;
    }

    $form_event_meta = isset($log_base_data['aipkit_form_event_meta']) && is_array($log_base_data['aipkit_form_event_meta'])
        ? $log_base_data['aipkit_form_event_meta']
        : [];

    $form_id = absint($form_event_meta['form_id'] ?? ($log_base_data['form_id'] ?? 0));
    $form_name = sanitize_text_field((string) ($form_event_meta['form_name'] ?? ''));
    $submission_count = absint($form_event_meta['submission_count'] ?? 0);
    $submitted_inputs = isset($form_event_meta['inputs']) && is_array($form_event_meta['inputs'])
        ? $form_event_meta['inputs']
        : [];
    $user_id = !empty($log_base_data['user_id']) ? absint($log_base_data['user_id']) : 0;
    $conversation_uuid = sanitize_key((string) ($current_conversation_uuid ?? ''));
    $response_text = sanitize_textarea_field($full_bot_response);

    $payload = [
        'form' => [
            'id' => $form_id,
            'name' => $form_name,
        ],
        'submission' => [
            'id' => $conversation_uuid,
            'count' => $submission_count,
        ],
        'actor' => [
            'type' => !empty($log_base_data['is_guest']) ? 'guest' : 'user',
        ],
        'ai' => [
            'provider' => sanitize_text_field((string) ($current_provider ?: ($form_event_meta['ai']['provider'] ?? ''))),
            'model' => sanitize_text_field((string) ($current_model ?: ($form_event_meta['ai']['model'] ?? ''))),
        ],
        'inputs' => $submitted_inputs,
        'response' => [
            'text' => $response_text,
        ],
    ];

    if ($user_id > 0) {
        $payload['actor']['user_id'] = $user_id;
    }

    AIPKit_Event_Webhooks::emit(
        'form.submitted',
        $payload,
        [
            'module' => 'ai_forms',
            'origin' => 'frontend_submission_completed',
            'resource' => [
                'type' => 'form_submission',
                'id' => $conversation_uuid !== '' ? $conversation_uuid : sanitize_key((string) ($current_bot_message_id ?? '')),
                'label' => $form_name !== ''
                    ? sprintf(
                        /* translators: %s: AI Form title */
                        __('Submission for %s', 'gpt3-ai-content-generator'),
                        $form_name
                    )
                    : __('AI Form submission', 'gpt3-ai-content-generator'),
            ],
            'meta' => [
                'form_id' => $form_id,
                'ai_provider' => sanitize_text_field((string) ($current_provider ?: ($form_event_meta['ai']['provider'] ?? ''))),
                'ai_model' => sanitize_text_field((string) ($current_model ?: ($form_event_meta['ai']['model'] ?? ''))),
                'is_guest' => !empty($log_base_data['is_guest']) ? 1 : 0,
                'conversation_uuid' => $conversation_uuid,
            ],
            'idempotency_key' => sha1(implode('|', [
                'form.submitted',
                'completed',
                (string) $form_id,
                $conversation_uuid,
                $current_bot_message_id ?: '',
                $user_id > 0 ? (string) $user_id : 'guest',
            ])),
        ]
    );
}

/**
 * Emits the canonical chatbot response event after the full response is stored.
 *
 * @param LogStorage $log_storage
 * @param array<string, mixed> $log_base_data
 * @param string $full_bot_response
 * @param string|null $current_provider
 * @param string|null $current_model
 * @param string|null $current_conversation_uuid
 * @param string|null $current_bot_message_id
 * @param array<string, mixed> $bot_log_result
 * @return void
 */
function emit_chatbot_response_generated_event_logic(
    LogStorage $log_storage,
    array $log_base_data,
    string $full_bot_response,
    ?string $current_provider,
    ?string $current_model,
    ?string $current_conversation_uuid,
    ?string $current_bot_message_id,
    array $bot_log_result
): void {
    if (!class_exists(AIPKit_Event_Webhooks::class)) {
        return;
    }

    $bot_id = absint($log_base_data['bot_id'] ?? 0);
    $user_id = absint($log_base_data['user_id'] ?? 0);
    $conversation_uuid = sanitize_key((string) ($current_conversation_uuid ?? ''));
    $response_id = sanitize_key((string) ($current_bot_message_id ?? ''));
    $log_id = absint($bot_log_result['log_id'] ?? 0);

    if ($bot_id <= 0 || $conversation_uuid === '' || $response_id === '' || $log_id <= 0) {
        return;
    }

    $conversation_log = $log_storage->get_log_by_id($log_id);
    $bot_name = sanitize_text_field((string) ($conversation_log['bot_name'] ?? get_the_title($bot_id)));
    $message_count = absint($conversation_log['message_count'] ?? 0);
    $history = $log_storage->get_conversation_thread_history(
        $user_id > 0 ? $user_id : null,
        $user_id > 0 ? null : sanitize_text_field((string) ($log_base_data['session_id'] ?? '')),
        $bot_id,
        $conversation_uuid
    );

    $last_user_message = [];
    if (!empty($history)) {
        for ($index = count($history) - 1; $index >= 0; $index--) {
            $history_item = $history[$index] ?? [];
            if (!is_array($history_item)) {
                continue;
            }

            if (sanitize_key((string) ($history_item['role'] ?? '')) === 'user') {
                $last_user_message = $history_item;
                break;
            }
        }
    }

    $payload = [
        'bot' => [
            'id' => $bot_id,
            'name' => $bot_name,
        ],
        'conversation' => [
            'id' => $conversation_uuid,
            'message_count' => $message_count,
        ],
        'actor' => [
            'type' => !empty($log_base_data['is_guest']) ? 'guest' : 'user',
        ],
        'response' => [
            'id' => $response_id,
            'text' => sanitize_textarea_field($full_bot_response),
        ],
        'ai' => [
            'provider' => sanitize_text_field((string) ($current_provider ?? '')),
            'model' => sanitize_text_field((string) ($current_model ?? '')),
        ],
    ];

    if ($user_id > 0) {
        $payload['actor']['user_id'] = $user_id;
    }

    if (!empty($last_user_message)) {
        $payload['user_message'] = [
            'id' => sanitize_key((string) ($last_user_message['message_id'] ?? '')),
            'text' => sanitize_textarea_field((string) ($last_user_message['content'] ?? '')),
        ];
    }

    AIPKit_Event_Webhooks::emit(
        'chatbot.response_generated',
        $payload,
        [
            'module' => 'chatbot',
            'origin' => 'frontend_response_completed',
            'resource' => [
                'type' => 'conversation_message',
                'id' => $response_id,
                'label' => $bot_name !== ''
                    ? sprintf(
                        /* translators: %s: chatbot name */
                        __('Response from %s', 'gpt3-ai-content-generator'),
                        $bot_name
                    )
                    : __('Chat response generated', 'gpt3-ai-content-generator'),
            ],
            'meta' => [
                'bot_id' => $bot_id,
                'conversation_uuid' => $conversation_uuid,
                'message_id' => $response_id,
                'message_count' => $message_count,
                'is_guest' => !empty($log_base_data['is_guest']) ? 1 : 0,
            ],
            'idempotency_key' => sha1(implode('|', [
                'chatbot.response_generated',
                (string) $bot_id,
                $conversation_uuid,
                $response_id,
                $user_id > 0 ? (string) $user_id : 'guest',
            ])),
        ]
    );
}

/**
 * Logs the final successful bot response.
 *
 * @param \WPAICG\Core\Stream\Processor\SSEStreamProcessor $processorInstance The instance of the processor class.
 * @return void
 */
function log_bot_response_logic(\WPAICG\Core\Stream\Processor\SSEStreamProcessor $processorInstance): void
{
    $full_bot_response = $processorInstance->get_full_bot_response();
    $log_base_data = $processorInstance->get_log_base_data();
    $error_occurred = $processorInstance->get_error_occurred_status();
    $current_bot_message_id = $processorInstance->get_current_bot_message_id();
    $log_storage = $processorInstance->get_log_storage();
    $current_provider = $processorInstance->get_current_provider();
    $current_model = $processorInstance->get_current_model();
    $final_usage_data = $processorInstance->get_final_usage_data();
    $request_payload_log = $processorInstance->get_request_payload_log();
    $current_stream_context = $processorInstance->get_current_stream_context();
    $current_conversation_uuid = $processorInstance->get_current_conversation_uuid();
    $current_openai_response_id = $processorInstance->get_current_openai_response_id();
    $used_previous_openai_response_id = $processorInstance->get_used_previous_openai_response_id_status();
    $current_google_interaction_id = $processorInstance->get_current_google_interaction_id();
    $used_previous_google_interaction_id = $processorInstance->get_used_previous_google_interaction_id_status();
    $citations = $processorInstance->get_citations();
    $token_manager = $processorInstance->get_token_manager();
    $vector_search_scores = $processorInstance->get_vector_search_scores();

    if (!$log_storage) {
        return;
    }

    if (!empty($full_bot_response) && !empty($log_base_data) && !$error_occurred && !empty($current_bot_message_id)) {
        $log_bot_data = array_merge($log_base_data, [
            'message_role'    => 'bot',
            'message_content' => $full_bot_response,
            'timestamp'       => time(),
            'ai_provider'     => $current_provider,
            'ai_model'        => $current_model,
            'usage'           => $final_usage_data,
            'message_id'      => $current_bot_message_id,
            'request_payload' => $request_payload_log,
        ]);

        if ($current_provider === 'OpenAI') {
            if ($current_openai_response_id) {
                $log_bot_data['openai_response_id'] = $current_openai_response_id;
            }
            if ($used_previous_openai_response_id) {
                $log_bot_data['used_previous_response_id'] = true;
            }
        }
        if ($current_provider === 'Google') {
            if ($current_google_interaction_id) {
                $log_bot_data['google_interaction_id'] = $current_google_interaction_id;
            }
            if ($used_previous_google_interaction_id) {
                $log_bot_data['used_previous_google_interaction_id'] = true;
            }
        }
        $persistable_citations = filter_persistable_citations_logic($citations ?? [], $current_provider);
        if (!empty($persistable_citations)) {
            $log_bot_data['citations'] = $persistable_citations;
        }
        if (!empty($vector_search_scores)) {
            $log_bot_data['vector_search_scores'] = $vector_search_scores;
        }
        $bot_log_result = $log_storage->log_message($log_bot_data);

        $tokens_consumed = $final_usage_data['total_tokens'] ?? 0;
        if ($token_manager && $tokens_consumed > 0) { // Check if token_manager is available
            $module_for_tokens = $current_stream_context;
            $context_id_for_tokens = null;

            if ($module_for_tokens === 'chat' && !empty($log_base_data['bot_id'])) {
                $context_id_for_tokens = $log_base_data['bot_id'];
            } elseif ($module_for_tokens === 'ai_forms') {
                if ($log_base_data['is_guest']) {
                    $context_id_for_tokens = GuestTableConstants::AI_FORMS_GUEST_CONTEXT_ID;
                } else {
                    $context_id_for_tokens = null; // Correct for logged-in users with a generic AI Forms limit
                }
            } elseif ($module_for_tokens === 'content_writer') {
                if ($log_base_data['is_guest']) {
                    $context_id_for_tokens = GuestTableConstants::CONTENT_WRITER_GUEST_CONTEXT_ID;
                } else {
                    $context_id_for_tokens = null;
                }
            }

            if ($context_id_for_tokens !== null || !$log_base_data['is_guest']) {
                $usage_context = [
                    'provider' => $current_provider,
                    'model' => $current_model,
                    'usage_data' => is_array($final_usage_data) ? $final_usage_data : [],
                ];

                if ($module_for_tokens === 'chat' && !empty($log_base_data['bot_id'])) {
                    $usage_context['operation'] = 'chat';
                } elseif ($module_for_tokens === 'ai_forms') {
                    $usage_context['operation'] = 'form_submit';
                    if (!empty($log_base_data['form_id'])) {
                        $usage_context['form_id'] = absint($log_base_data['form_id']);
                        $usage_context['pricing_scope_type'] = 'ai_form';
                        $usage_context['pricing_scope_id'] = absint($log_base_data['form_id']);
                    }
                }

                $token_manager->record_token_usage(
                    $log_base_data['user_id'],
                    $log_base_data['session_id'],
                    $context_id_for_tokens,
                    $tokens_consumed,
                    $module_for_tokens,
                    $usage_context
                );
            }
        }

        if ($current_stream_context === 'chat' && is_array($bot_log_result)) {
            emit_chatbot_response_generated_event_logic(
                $log_storage,
                $log_base_data,
                $full_bot_response,
                $current_provider,
                $current_model,
                $current_conversation_uuid,
                $current_bot_message_id,
                $bot_log_result
            );
        }

        if ($current_stream_context === 'content_writer' && class_exists(AIPKit_Event_Webhooks::class)) {
            AIPKit_Event_Webhooks::emit(
                'content.generated',
                [
                    'content' => $full_bot_response,
                    'conversation' => [
                        'id' => $current_conversation_uuid,
                    ],
                    'ai' => [
                        'provider' => $current_provider,
                        'model' => $current_model,
                    ],
                    'actor' => [
                        'type' => !empty($log_base_data['is_guest']) ? 'guest' : 'user',
                        'user_id' => !empty($log_base_data['user_id']) ? (int) $log_base_data['user_id'] : null,
                    ],
                ],
                [
                    'module' => 'content_writer',
                    'origin' => 'direct_stream',
                    'resource' => [
                        'type' => 'content_generation',
                        'id' => $current_conversation_uuid ?: $current_bot_message_id,
                        'label' => __('Generated content', 'gpt3-ai-content-generator'),
                    ],
                    'meta' => [
                        'provider' => $current_provider,
                        'model' => $current_model,
                        'conversation_uuid' => $current_conversation_uuid,
                    ],
                    'idempotency_key' => sha1(implode('|', [
                        'content.generated',
                        'direct_stream',
                        (string) $current_conversation_uuid,
                        (string) $current_bot_message_id,
                        $current_provider ?: '',
                        $current_model ?: '',
                    ])),
                ]
            );
        }

        if ($current_stream_context === 'ai_forms') {
            emit_ai_forms_form_submitted_event_logic(
                $log_base_data,
                $full_bot_response,
                $current_provider,
                $current_model,
                $current_conversation_uuid,
                $current_bot_message_id
            );
        }

    } elseif (empty($current_bot_message_id)) {
        // Cannot log bot response because current_bot_message_id is empty. This indicates an internal error state.
    } elseif ($error_occurred) {
        // Skipped logging a successful response because an error was flagged earlier in the process.
    } elseif (empty($full_bot_response)) {
        log_bot_error_logic($processorInstance, "(Empty Response)");
    }
}

/**
 * Logs an error message for the bot response.
 *
 * @param \WPAICG\Core\Stream\Processor\SSEStreamProcessor $processorInstance The instance of the processor class.
 * @param string $error_message The error message to log.
 * @return void
 */
function log_bot_error_logic(\WPAICG\Core\Stream\Processor\SSEStreamProcessor $processorInstance, string $error_message): void {
    $log_storage = $processorInstance->get_log_storage();
    $log_base_data = $processorInstance->get_log_base_data();
    $current_bot_message_id = $processorInstance->get_current_bot_message_id();
    $current_provider = $processorInstance->get_current_provider();
    $current_model = $processorInstance->get_current_model();
    $request_payload_log = $processorInstance->get_request_payload_log();
    $current_openai_response_id = $processorInstance->get_current_openai_response_id();
    $used_previous_openai_response_id = $processorInstance->get_used_previous_openai_response_id_status();
    $current_google_interaction_id = $processorInstance->get_current_google_interaction_id();
    $used_previous_google_interaction_id = $processorInstance->get_used_previous_google_interaction_id_status();
    $citations = $processorInstance->get_citations();

    if (!$log_storage) {
        return;
    }

    if (!empty($log_base_data) && !empty($current_bot_message_id)) {
         $log_error_data = array_merge($log_base_data, [
            'message_role'    => 'bot',
            'message_content' => "Error: " . $error_message,
            'timestamp'       => time(),
            'ai_provider'     => $current_provider,
            'ai_model'        => $current_model,
            'usage'           => null,
            'message_id'      => $current_bot_message_id,
            'request_payload' => $request_payload_log,
         ]);

        if ($current_provider === 'OpenAI') {
            if ($current_openai_response_id) $log_error_data['openai_response_id'] = $current_openai_response_id;
            if ($used_previous_openai_response_id) $log_error_data['used_previous_response_id'] = true;
        }
        if ($current_provider === 'Google') {
            if ($current_google_interaction_id) $log_error_data['google_interaction_id'] = $current_google_interaction_id;
            if ($used_previous_google_interaction_id) $log_error_data['used_previous_google_interaction_id'] = true;
        }
        $persistable_citations = filter_persistable_citations_logic($citations ?? [], $current_provider);
        if (!empty($persistable_citations)) {
            $log_error_data['citations'] = $persistable_citations;
        }
         $log_storage->log_message($log_error_data);
    }
}

/**
 * Do not persist Google Search links or Search Suggestions. Google's search
 * attribution is rendered only for the live response. File Search citations
 * remain eligible for conversation history because they refer to user-owned
 * knowledge-base content rather than Google Search results.
 *
 * @param array<int, mixed> $citations Parsed provider citations.
 * @return array<int, mixed>
 */
function filter_persistable_citations_logic(array $citations, string $provider): array
{
    if ($provider !== 'Google') {
        return $citations;
    }

    return array_values(array_filter(
        $citations,
        static function ($citation): bool {
            return is_array($citation) && ($citation['type'] ?? '') !== 'url_citation';
        }
    ));
}
