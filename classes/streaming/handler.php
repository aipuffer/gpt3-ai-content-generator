<?php

namespace WPAICG\Core\Stream\Handler;

use WPAICG\Core\Stream\Formatter\SSEResponseFormatter;
use WPAICG\Core\Stream\Request\SSERequestHandler;
use WPAICG\Core\Stream\Processor\SSEStreamProcessor;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Handles the AJAX request for STREAMING messages using Server-Sent Events (SSE).
 * This class acts as the entry point for the SSE request, orchestrating validation,
 * caching (if needed), and stream processing.
 */
class SSEHandler {

    private $request_handler;
    private $stream_processor;
    private $response_formatter;

    public function __construct() {
        // Dependencies should be loaded by AIPKit_Dependency_Loader.
        // Constructors now assume classes are available.

        $log_storage_instance = class_exists(\WPAICG\Chat\Storage\LogStorage::class)
            ? new \WPAICG\Chat\Storage\LogStorage()
            : null;

        $this->response_formatter = class_exists(\WPAICG\Core\Stream\Formatter\SSEResponseFormatter::class)
            ? new \WPAICG\Core\Stream\Formatter\SSEResponseFormatter()
            : null;

        $this->request_handler = ($log_storage_instance && class_exists(\WPAICG\Core\Stream\Request\SSERequestHandler::class))
            ? new \WPAICG\Core\Stream\Request\SSERequestHandler($log_storage_instance)
            : null;

        $this->stream_processor = ($this->response_formatter && $log_storage_instance && class_exists(\WPAICG\Core\Stream\Processor\SSEStreamProcessor::class))
            ? new \WPAICG\Core\Stream\Processor\SSEStreamProcessor($this->response_formatter, $log_storage_instance)
            : null;
    }

    // Getters for externalized logic
    public function get_response_formatter(): ?SSEResponseFormatter { return $this->response_formatter; }
    public function get_request_handler(): ?SSERequestHandler { return $this->request_handler; }
    public function get_stream_processor(): ?SSEStreamProcessor { return $this->stream_processor; }


    public function ajax_cache_sse_message() {
        // Ensure dependencies are met before calling logic
        if ($this->response_formatter) { // Check one, assuming others fine if this one is
            \WPAICG\Core\Stream\Handler\Ajax\ajax_cache_sse_message_logic($this);
        } else {
             wp_send_json_error(['message' => __('SSE service not ready.', 'gpt3-ai-content-generator')], 503);
        }
    }

    public function ajax_frontend_chat_stream() {
        // Ensure dependencies are met before calling logic
        if ($this->response_formatter && $this->request_handler && $this->stream_processor) {
            \WPAICG\Core\Stream\Handler\Ajax\ajax_frontend_chat_stream_logic($this);
        } else {
            // Attempt to send an SSE error if possible, otherwise just exit.
            if ($this->response_formatter) {
                $this->response_formatter->set_sse_headers();
                $this->response_formatter->send_sse_error(__('SSE service components not fully initialized.', 'gpt3-ai-content-generator'));
            }
            exit;
        }
    }
}

namespace WPAICG\Core\Stream\Handler\Ajax;

use WPAICG\Chat\Core\Validation\ChatImageInputValidator;
use WPAICG\Core\Stream\Handler\SSEHandler;
use WPAICG\Core\Stream\Cache\AIPKit_SSE_Message_Cache;
use WPAICG\Utils\AIPKit_CORS_Manager;
use WPAICG\Lib\Streaming\TriggerHandler;

/**
* AJAX handler for caching the user message or context data before starting the SSE stream.
*
* @param \WPAICG\Core\Stream\Handler\SSEHandler $handlerInstance The instance of the SSEHandler class.
* @return void Sends JSON response.
*/
function ajax_cache_sse_message_logic(\WPAICG\Core\Stream\Handler\SSEHandler $handlerInstance): void
{
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Frontend nonce is verified below before processing request data.
    $post_data = wp_unslash($_POST);

    // --- Handle preflight OPTIONS request ---
    AIPKit_CORS_Manager::handle_preflight_request();

    // --- CORS Check ---
    $bot_id = 0;
    if (isset($post_data['bot_id'])) {
        $bot_id = absint($post_data['bot_id']);
    }

    if ($bot_id > 0) {
        // Check if this is a cross-origin request (actual embed usage)
        $is_cross_origin = AIPKit_CORS_Manager::is_cross_origin();

        if ($is_cross_origin) {
            // This is a cross-origin request, check embed feature availability
            if (class_exists('\WPAICG\aipkit_dashboard') &&
                \WPAICG\aipkit_dashboard::is_pro_plan()) {

                $origin_allowed = AIPKit_CORS_Manager::check_and_set_cors_headers($bot_id);
                if (!$origin_allowed) {
                    wp_send_json_error([
                        'message' => __('This domain is not permitted to access the chatbot.', 'gpt3-ai-content-generator'),
                        'code'    => 'cors_denied'
                    ], 403);
                    return;
                }
            } else {
                // Embed feature not available but this is a cross-origin request
                wp_send_json_error([
                    'message' => __('Embed feature is not available with your current plan.', 'gpt3-ai-content-generator'),
                    'code'    => 'embed_not_available'
                ], 403);
                return;
            }
        }
        // For same-origin requests, no additional CORS checks needed
    }

    // --- MODIFICATION: Improved Nonce Check and Error Reporting ---
    if (!isset($post_data['_ajax_nonce']) || !wp_verify_nonce(sanitize_key((string) $post_data['_ajax_nonce']), 'aipkit_frontend_chat_nonce')) {
        $error_data_for_response = [
            'message' => __('Your session has expired or the request is invalid. Please refresh the page and try again.', 'gpt3-ai-content-generator'),
            'code'    => 'nonce_failure_cache_sse' // Specific code for nonce failure
        ];
        wp_send_json_error($error_data_for_response, 403);
        return;
    }

    $raw_user_message = isset($post_data['message']) ? (string) $post_data['message'] : '';

    // Custom sanitization for code content - preserve code structure while ensuring security
    $user_message = wp_check_invalid_utf8($raw_user_message);
    $user_message = str_replace(chr(0), '', $user_message); // Remove null bytes

    $image_inputs_json = isset($post_data['image_inputs']) ? (string) $post_data['image_inputs'] : null;
    $client_user_message_id = isset($post_data['user_client_message_id']) ? sanitize_key((string) $post_data['user_client_message_id']) : null;
    $active_openai_vs_id = isset($post_data['active_openai_vs_id']) ? sanitize_text_field((string) $post_data['active_openai_vs_id']) : null;
    $active_pinecone_index_name = isset($post_data['active_pinecone_index_name']) ? sanitize_text_field((string) $post_data['active_pinecone_index_name']) : null;
    $active_pinecone_namespace = isset($post_data['active_pinecone_namespace']) ? sanitize_text_field((string) $post_data['active_pinecone_namespace']) : null;
    $active_qdrant_collection_name = isset($post_data['active_qdrant_collection_name']) ? sanitize_text_field((string) $post_data['active_qdrant_collection_name']) : null;
    $active_qdrant_file_upload_context_id = isset($post_data['active_qdrant_file_upload_context_id']) ? sanitize_text_field((string) $post_data['active_qdrant_file_upload_context_id']) : null;
    $active_chroma_collection_name = isset($post_data['active_chroma_collection_name']) ? sanitize_text_field((string) $post_data['active_chroma_collection_name']) : null;
    $active_chroma_file_upload_context_id = isset($post_data['active_chroma_file_upload_context_id']) ? sanitize_text_field((string) $post_data['active_chroma_file_upload_context_id']) : null;
    $active_claude_file_id = isset($post_data['active_claude_file_id']) ? sanitize_text_field((string) $post_data['active_claude_file_id']) : null;
    $active_google_file_context_token = isset($post_data['active_google_file_context_token'])
        ? sanitize_text_field(wp_unslash((string) $post_data['active_google_file_context_token']))
        : null;
    $resume_after_form_submission = isset($post_data['resume_after_form_submission']) && (string) $post_data['resume_after_form_submission'] === '1';
    $form_resume_token = isset($post_data['form_resume_token']) ? sanitize_text_field((string) $post_data['form_resume_token']) : '';
    $form_submission_context = [];

    if ($resume_after_form_submission && isset($post_data['form_submission_context']) && is_string($post_data['form_submission_context'])) {
        $form_submission_context_raw = wp_kses_post($post_data['form_submission_context']);
        $decoded_form_submission_context = json_decode($form_submission_context_raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_form_submission_context)) {
            $sanitize_recursive = static function ($value) use (&$sanitize_recursive) {
                if (is_array($value)) {
                    $sanitized = [];
                    foreach ($value as $key => $item) {
                        $sanitized_key = sanitize_text_field((string) $key);
                        if ($sanitized_key === '') {
                            continue;
                        }
                        $sanitized[$sanitized_key] = $sanitize_recursive($item);
                    }
                    return $sanitized;
                }
                return sanitize_text_field((string) $value);
            };
            $form_submission_context = $sanitize_recursive($decoded_form_submission_context);
        }
    }

    if (!class_exists(ChatImageInputValidator::class)) {
        $validator_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/image-input.php';
        if (file_exists($validator_path)) {
            require_once $validator_path;
        }
    }

    $image_inputs_data = null;
    if ($image_inputs_json) {
        if (!class_exists(ChatImageInputValidator::class)) {
            $error_data_for_response = [
                'message' => __('Image validation component is unavailable.', 'gpt3-ai-content-generator'),
                'code' => 'image_validator_missing',
            ];
            wp_send_json_error($error_data_for_response, 500);
            return;
        }

        $image_validation_result = ChatImageInputValidator::parse_and_validate($image_inputs_json);
        if (is_wp_error($image_validation_result)) {
            $error_data_for_response = [
                'message' => $image_validation_result->get_error_message(),
                'code' => $image_validation_result->get_error_code(),
            ];
            $error_status = is_array($image_validation_result->get_error_data()) && isset($image_validation_result->get_error_data()['status'])
                ? absint($image_validation_result->get_error_data()['status'])
                : 400;
            wp_send_json_error($error_data_for_response, $error_status);
            return;
        }

        if (is_array($image_validation_result)) {
            $image_inputs_data = $image_validation_result;
        }
    }

    if (empty($user_message) && empty($image_inputs_data)) {
        $error_data_for_response = [
            'message' => __('Data to cache (message or image) cannot be empty.', 'gpt3-ai-content-generator'),
            'code' => 'empty_data_to_cache'
        ];
        wp_send_json_error($error_data_for_response, 400);
        return;
    }

    $data_to_cache_structured = [
        'user_message' => $user_message,
        'image_inputs' => $image_inputs_data,
        'client_user_message_id' => $client_user_message_id
    ];
    if ($resume_after_form_submission) {
        $data_to_cache_structured['resume_after_form_submission'] = true;
        if (!empty($form_submission_context)) {
            $data_to_cache_structured['form_submission_context'] = $form_submission_context;
        }
        if ($form_resume_token !== '') {
            $data_to_cache_structured['form_resume_token'] = $form_resume_token;
        }
    }
    foreach ([
        'active_file_context_token' => isset($post_data['active_file_context_token']) ? sanitize_text_field((string) $post_data['active_file_context_token']) : null,
        'active_openai_vs_id' => $active_openai_vs_id,
        'active_pinecone_index_name' => $active_pinecone_index_name,
        'active_pinecone_namespace' => $active_pinecone_namespace,
        'active_qdrant_collection_name' => $active_qdrant_collection_name,
        'active_qdrant_file_upload_context_id' => $active_qdrant_file_upload_context_id,
        'active_chroma_collection_name' => $active_chroma_collection_name,
        'active_chroma_file_upload_context_id' => $active_chroma_file_upload_context_id,
        'active_claude_file_id' => $active_claude_file_id,
        'active_google_file_context_token' => $active_google_file_context_token,
    ] as $context_key => $context_value) {
        if ($context_value) {
            $data_to_cache_structured[$context_key] = $context_value;
        }
    }

    $data_to_cache = wp_json_encode($data_to_cache_structured);

    if ($data_to_cache === false) {
        $error_data_for_response = [
            'message' => __('Failed to encode data for caching. The message may contain invalid characters.', 'gpt3-ai-content-generator'),
            'code' => 'json_encode_failed'
        ];
        wp_send_json_error($error_data_for_response, 400);
        return;
    }

    if (!class_exists(AIPKit_SSE_Message_Cache::class)) {
        $cache_path = WPAICG_PLUGIN_DIR . 'classes/streaming/cache.php';
        if (file_exists($cache_path)) {
            require_once $cache_path;
        } else {
            $error_data_for_response = [
                'message' => __('Cache component missing.', 'gpt3-ai-content-generator'),
                'code' => 'cache_component_missing'
            ];
            wp_send_json_error($error_data_for_response, 500);
            return;
        }
    }
    $sse_message_cache = new AIPKit_SSE_Message_Cache();

    $cache_key_result = $sse_message_cache->set($data_to_cache);

    if (is_wp_error($cache_key_result)) {
        $error_data_for_response = [
            'message' => $cache_key_result->get_error_message(),
            'code'    => $cache_key_result->get_error_code()
        ];
        $error_status = $cache_key_result->get_error_data()['status'] ?? 500;
        wp_send_json_error($error_data_for_response, $error_status);
    } else {
        wp_send_json_success(['cache_key' => $cache_key_result]);
    }
}

/**
 * AJAX handler for processing STREAMING messages using Server-Sent Events (SSE).
 *
 * @param \WPAICG\Core\Stream\Handler\SSEHandler $handlerInstance The instance of the SSEHandler class.
 * @return void
 */
function ajax_frontend_chat_stream_logic(SSEHandler $handlerInstance): void {
    $response_formatter = $handlerInstance->get_response_formatter();
    $request_handler    = $handlerInstance->get_request_handler();
    $stream_processor   = $handlerInstance->get_stream_processor();

    // --- Handle preflight OPTIONS request ---
    AIPKit_CORS_Manager::handle_preflight_request();

    // --- CORS Check ---
    $bot_id = 0;
    if (isset($_GET['bot_id'])) {
        $bot_id = absint(wp_unslash($_GET['bot_id']));
    }

    if ($bot_id > 0) {
        $origin_allowed = AIPKit_CORS_Manager::check_and_set_cors_headers($bot_id);
        if (!$origin_allowed) {
            $response_formatter->set_sse_headers();
            $response_formatter->send_sse_error(__('This domain is not permitted to access the chatbot.', 'gpt3-ai-content-generator'));
            $response_formatter->send_sse_done();
            exit;
        }
    }

    $response_formatter->set_sse_headers();

    // --- FIX for PHPCS NonceVerification ---
    // Perform nonce check at the top of the AJAX handler before using any user input.
    if (!check_ajax_referer('aipkit_frontend_chat_nonce', '_ajax_nonce', false)) {
        $response_formatter->send_sse_error(__('Security check failed. Please refresh the page and try again.', 'gpt3-ai-content-generator'));
        $response_formatter->send_sse_done();
        exit;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified above. All $_GET usage is now considered safe.
    $get_data = wp_unslash($_GET);
    // --- END FIX ---

    $trigger_handler = class_exists(TriggerHandler::class) ? new TriggerHandler() : null;

    if (!$request_handler || !$stream_processor) {
        $error_message = __('Server error: Stream processing components not ready.', 'gpt3-ai-content-generator');
        $response_formatter->send_sse_error($error_message);
        if ($trigger_handler) {
            $trigger_handler->component_error($get_data, $error_message);
        }
        $response_formatter->send_sse_done();
        exit;
    }

    try {
        $processed_data = $request_handler->process_initial_request($get_data);

        if (is_wp_error($processed_data)) {
            $error_data = $processed_data->get_error_data() ?: [];
            $status_code = is_array($error_data) && isset($error_data['status']) && is_int($error_data['status']) ? $error_data['status'] : 500;
            $user_facing_message = $processed_data->get_error_message();
            $client_error_payload = [];
            if (is_array($error_data) && !empty($error_data['quota_notice']) && is_array($error_data['quota_notice'])) {
                $client_error_payload['quota_notice'] = $error_data['quota_notice'];
            }

            if ($trigger_handler) {
                $trigger_handler->request_error($get_data, $processed_data);
            }

            if ($trigger_handler && $trigger_handler->send_control_response($processed_data, $response_formatter)) {
                exit;
            } elseif (!empty($client_error_payload)) {
                $response_formatter->send_sse_error($user_facing_message, false, $client_error_payload);
                $response_formatter->send_sse_done();
                exit;
            } else {
                throw new \Exception($user_facing_message, $status_code);
            }
        }

        if ($trigger_handler) {
            $trigger_handler->send_initial_reply($processed_data, $response_formatter);
        }

        if (empty($processed_data['bot_message_id'])) {
            throw new \Exception('Internal error: Missing generated message ID for stream.', 500);
        }

        $base_log_data_with_msg_id = $processed_data['base_log_data'] ?? [];
        if (empty($base_log_data_with_msg_id['bot_message_id'])) {
            $base_log_data_with_msg_id['bot_message_id'] = $processed_data['bot_message_id'];
        }

        $response_formatter->send_sse_event('message_start', ['message_id' => $processed_data['bot_message_id']]);

        // Set vector search scores in the stream processor for logging
        if (isset($processed_data['vector_search_scores']) && is_array($processed_data['vector_search_scores'])) {
            $stream_processor->set_vector_search_scores($processed_data['vector_search_scores']);
        }

        $stream_processor->start_stream(
            $processed_data['provider'], $processed_data['model'],
            $processed_data['user_message'], $processed_data['history'],
            $processed_data['system_instruction_filtered'],
            $processed_data['api_params'], $processed_data['ai_params'],
            $processed_data['conversation_uuid'], $base_log_data_with_msg_id
        );

    } catch (\Throwable $e) {
        require_once dirname(__DIR__) . '/runtime-diagnostics.php';
        $reference = \WPAICG\RuntimeDiagnostics::report($e, 'chatbot_stream_preparation');
        /* translators: %s: Reference matching the PHP server error log. */
        $error_message_final = $e instanceof \Exception ? $e->getMessage() : sprintf(__('The AI request could not be completed. Please contact the site administrator. Reference: %s', 'gpt3-ai-content-generator'), $reference);
        if (!$response_formatter->get_headers_sent_status()) $response_formatter->set_sse_headers();
        $response_formatter->send_sse_error($error_message_final);

        if ($trigger_handler) {
            $trigger_handler->exception($get_data, $e);
        }

        $response_formatter->send_sse_done();
    } finally {
        if ($response_formatter) {
            $response_formatter->finish_request();
        }
        // Ensure script exits after sending all SSE data or handling errors
        exit;
    }
}
