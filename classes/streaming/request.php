<?php

namespace WPAICG\Core\Stream\Request;

use WPAICG\Core\Stream\Cache\AIPKit_SSE_Message_Cache;
use WPAICG\Chat\Storage\LogStorage;
use WPAICG\Core\TokenManager\AIPKit_Token_Manager;
use WPAICG\Core\AIPKit_AI_Caller;
use WPAICG\Vector\AIPKit_Vector_Store_Manager;
use WPAICG\AIForms\Storage\AIPKit_AI_Form_Storage;
use WPAICG\Core\Stream\Contexts\Chat\SSEChatStreamContextHandler;
use WPAICG\Core\Stream\Contexts\ContentWriter\SSEContentWriterStreamContextHandler;
use WPAICG\Core\Stream\Contexts\AIForms\SSEAIFormsStreamContextHandler;
use WP_Error;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Validates the incoming SSE request parameters and prepares data for streaming.
 * Retrieves cached data based on cache_key and routes to context-specific processors.
 *
 * @param \WPAICG\Core\Stream\Request\SSERequestHandler $handlerInstance The instance of the SSERequestHandler.
 * @param array $get_params $_GET parameters from the SSE request.
 * @return array|WP_Error Prepared data for SSEStreamProcessor or WP_Error.
 */
function process_initial_request_logic(
    \WPAICG\Core\Stream\Request\SSERequestHandler $handlerInstance,
    array $get_params
) {
    $cache_key = isset($get_params['cache_key']) ? sanitize_key($get_params['cache_key']) : '';
    if (empty($cache_key)) {
        return new WP_Error(
            'missing_cache_key',
            __('Message cache key is missing.', 'gpt3-ai-content-generator'),
            ['status' => 400, 'failed_module' => 'sse_handler', 'failed_operation' => 'cache_key_validation']
        );
    }

    $sse_message_cache = $handlerInstance->get_sse_message_cache();
    $cached_content_result = $sse_message_cache->get($cache_key);

    if (is_wp_error($cached_content_result)) {
        $error_data = $cached_content_result->get_error_data() ?: [];
        $error_data['failed_module'] = $error_data['failed_module'] ?? 'sse_cache';
        $error_data['failed_operation'] = $error_data['failed_operation'] ?? 'get_cached_message';
        $error_data['status_code'] = $error_data['status_code'] ?? ($cached_content_result->get_error_code() === 'sse_cache_expired' ? 410 : 404);
        return new WP_Error(
            $cached_content_result->get_error_code(),
            $cached_content_result->get_error_message(),
            $error_data
        );
    }
    $sse_message_cache->delete($cache_key);

    $stream_context = 'chat'; // Default context
    $cached_data_decoded_for_handler = null;

    if (is_string($cached_content_result)) {
        $outer_decoded = json_decode($cached_content_result, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($outer_decoded)) {
            if (isset($outer_decoded['stream_context'])) {
                // Top-level stream_context found (this means it's likely a direct cache from non-chat modules)
                $stream_context = $outer_decoded['stream_context'];
                $cached_data_decoded_for_handler = $outer_decoded;
            } elseif (isset($outer_decoded['user_message']) && is_string($outer_decoded['user_message'])) {
                // The 'user_message' field might itself be a JSON string containing the actual context data
                // This happens when AI Forms or Content Writer cache their data via the generic ajax_cache_sse_message action.
                $nested_decoded = json_decode($outer_decoded['user_message'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($nested_decoded) && isset($nested_decoded['stream_context'])) {
                    $stream_context = $nested_decoded['stream_context'];
                    $cached_data_decoded_for_handler = $nested_decoded; // Use the inner decoded data
                    // Preserve outer context overrides without copying unrelated cached fields.
                    foreach ([
                        'active_file_context_token',
                        'active_openai_vs_id',
                        'active_pinecone_index_name',
                        'active_pinecone_namespace',
                        'active_qdrant_collection_name',
                        'active_qdrant_file_upload_context_id',
                        'active_chroma_collection_name',
                        'active_chroma_file_upload_context_id',
                        'active_claude_file_id',
                        'active_google_file_context_token',
                        'client_user_message_id',
                        'image_inputs',
                    ] as $context_key) {
                        if (isset($outer_decoded[$context_key])) {
                            $cached_data_decoded_for_handler[$context_key] = $outer_decoded[$context_key];
                        }
                    }

                } else {
                    // 'user_message' was not valid JSON or didn't contain 'stream_context', treat as chat.
                    $cached_data_decoded_for_handler = $outer_decoded;
                }
            } else {
                // No 'stream_context' at top level, and 'user_message' is not a string to parse.
                // Treat as chat context using the outer decoded data.
                $cached_data_decoded_for_handler = $outer_decoded;
            }
        } else {
            // $cached_content_result was not a JSON string (e.g., older simple string cache for chat)
            $cached_data_decoded_for_handler = ['user_message' => $cached_content_result, 'image_inputs' => null];
        }
    } else {
        // Fallback if $cached_content_result was not a string (should not happen with current cache logic)
        $cached_data_decoded_for_handler = ['user_message' => '', 'image_inputs' => null];
    }

    // Route to specific context handlers
    if ($stream_context === 'chat') {
        $chat_handler = $handlerInstance->get_chat_context_handler();
        if (!$chat_handler) {
            return new WP_Error(
                'handler_missing_chat',
                'Chat stream handler component missing.',
                ['status' => 500, 'failed_module' => 'chat_stream_context', 'failed_operation' => 'get_context_handler']
            );
        }
        return $chat_handler->process($cached_data_decoded_for_handler, $get_params);
    } elseif ($stream_context === 'content_writer') {
        $user_id = get_current_user_id();
        if (!$user_id || (!\WPAICG\AIPKit_Role_Manager::user_can_access_module('content-writer') && !\WPAICG\AIPKit_Role_Manager::user_can_access_module('autogpt'))) {
            return new WP_Error('content_writer_forbidden', __('You do not have permission to use Content Writer.', 'gpt3-ai-content-generator'), ['status' => 403]);
        }
        $cached_data_decoded_for_handler['user_id'] = $user_id;
        $content_writer_handler = $handlerInstance->get_content_writer_context_handler();
        if (!$content_writer_handler) {
            return new WP_Error(
                'handler_missing_cw',
                'Content writer stream handler component missing.',
                ['status' => 500, 'failed_module' => 'content_writer_stream_context', 'failed_operation' => 'get_context_handler']
            );
        }
        return $content_writer_handler->process($cached_data_decoded_for_handler, $get_params);
    } elseif ($stream_context === 'ai_forms') {
        $ai_forms_handler = $handlerInstance->get_ai_forms_context_handler();
        if (!$ai_forms_handler) {
            return new WP_Error(
                'handler_missing_aif',
                'AI Forms stream handler component missing.',
                ['status' => 500, 'failed_module' => 'ai_forms_stream_context', 'failed_operation' => 'get_context_handler']
            );
        }
        return $ai_forms_handler->process($cached_data_decoded_for_handler, $get_params);
    } else {
        return new WP_Error(
            'unsupported_stream_context',
            __('Unsupported stream context.', 'gpt3-ai-content-generator'),
            ['status' => 400, 'failed_module' => $stream_context, 'failed_operation' => 'resolve_stream_context']
        );
    }
}

/**
 * Handles initial validation, data retrieval, and payload formatting for SSE requests.
 * Routes requests to context-specific handlers.
 */
class SSERequestHandler
{
    private $sse_message_cache;
    // Context Handlers
    private $chat_context_handler;
    private $content_writer_context_handler;
    private $ai_forms_context_handler;

    public function __construct(?LogStorage $log_storage_passed = null)
    {
        // Assemble the context dependencies supplied by the dependency loader.

        $log_storage = $log_storage_passed;
        if (!$log_storage && class_exists(LogStorage::class)) {
            $log_storage = new LogStorage();
        }

        $this->sse_message_cache = class_exists(AIPKit_SSE_Message_Cache::class)
            ? new AIPKit_SSE_Message_Cache()
            : null;

        $token_manager = class_exists(AIPKit_Token_Manager::class)
            ? new AIPKit_Token_Manager()
            : null;

        $ai_caller = class_exists(AIPKit_AI_Caller::class)
            ? new AIPKit_AI_Caller()
            : null;

        $vector_store_manager = class_exists(AIPKit_Vector_Store_Manager::class)
            ? new AIPKit_Vector_Store_Manager()
            : null;

        $ai_form_storage = class_exists(AIPKit_AI_Form_Storage::class)
            ? new AIPKit_AI_Form_Storage()
            : null;

        // Instantiate Context Handlers
        $bot_storage_for_chat_handler = class_exists(\WPAICG\Chat\Storage\BotStorage::class) ? new \WPAICG\Chat\Storage\BotStorage() : null;
        if ($log_storage && $token_manager && $bot_storage_for_chat_handler && class_exists(SSEChatStreamContextHandler::class)) {
            $this->chat_context_handler = new SSEChatStreamContextHandler($bot_storage_for_chat_handler, $log_storage, $token_manager);
        } else {
            $this->chat_context_handler = null;
        }

        if ($log_storage && class_exists(SSEContentWriterStreamContextHandler::class)) {
            $this->content_writer_context_handler = new SSEContentWriterStreamContextHandler($log_storage);
        } else {
            $this->content_writer_context_handler = null;
        }

        $this->ai_forms_context_handler = (
            $log_storage && $ai_form_storage && $token_manager &&
            $ai_caller && $vector_store_manager &&
            class_exists(SSEAIFormsStreamContextHandler::class)
        ) ? new SSEAIFormsStreamContextHandler(
            $log_storage,
            $ai_form_storage,
            $token_manager,
            $ai_caller,
            $vector_store_manager
        )
          : null;
    }

    // Getters for externalized logic
    public function get_sse_message_cache(): ?AIPKit_SSE_Message_Cache
    {
        return $this->sse_message_cache;
    }
    public function get_chat_context_handler(): ?SSEChatStreamContextHandler
    {
        return $this->chat_context_handler;
    }
    public function get_content_writer_context_handler(): ?SSEContentWriterStreamContextHandler
    {
        return $this->content_writer_context_handler;
    }
    public function get_ai_forms_context_handler(): ?SSEAIFormsStreamContextHandler
    {
        return $this->ai_forms_context_handler;
    }

    /**
     * @return mixed[]|WP_Error
     */
    public function process_initial_request(array $get_params)
    {
        return process_initial_request_logic($this, $get_params);
    }
}
