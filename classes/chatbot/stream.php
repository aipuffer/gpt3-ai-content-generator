<?php

namespace WPAICG\Core\Stream\Contexts\Chat;

use WPAICG\Chat\Storage\BotStorage;
use WPAICG\Chat\Storage\LogStorage;
use WPAICG\Core\TokenManager\AIPKit_Token_Manager;
use WPAICG\Chat\Core\AIService as ChatAIService;
use WP_Error;
use WPAICG\Lib\Chat\Stream as PaidStream;
use WPAICG\Chat\Admin\AdminSetup;
use WPAICG\Core\AIPKit_Content_Moderator;
use WPAICG\AIPKit_Providers;
use WPAICG\AIPKIT_AI_Settings;
use WPAICG\Core\AIPKit_Instruction_Manager;
use WPAICG\Core\Providers\OpenAI\OpenAIStatefulConversationHelper;
use WPAICG\Chat\Storage\BotSettingsManager;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Handles processing stream requests specifically for the 'chat' context.
 */
class SSEChatStreamContextHandler
{
    private $bot_storage;
    private $log_storage;
    private $token_manager;
    private $ai_service_for_helper;

    public function __construct(
        BotStorage $bot_storage,
        LogStorage $log_storage,
        AIPKit_Token_Manager $token_manager
    ) {
        $this->bot_storage = $bot_storage;
        $this->log_storage = $log_storage;
        $this->token_manager = $token_manager;

        if (!class_exists(ChatAIService::class)) {
            $ai_service_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/ai-service.php';
            if (file_exists($ai_service_path)) {
                require_once $ai_service_path;
            }
        }
        if (class_exists(ChatAIService::class)) {
            $this->ai_service_for_helper = new ChatAIService();
        } else {
            $this->ai_service_for_helper = null;
        }

        $process_chat_dependencies = [
            AdminSetup::class => WPAICG_PLUGIN_DIR . 'classes/chatbot/admin.php',
            AIPKit_Content_Moderator::class => WPAICG_PLUGIN_DIR . 'classes/security/moderation.php',
            AIPKit_Providers::class => WPAICG_PLUGIN_DIR . 'classes/ai/settings.php',
            AIPKIT_AI_Settings::class => WPAICG_PLUGIN_DIR . 'classes/settings/ai.php',
            AIPKit_Instruction_Manager::class => WPAICG_PLUGIN_DIR . 'classes/ai/instructions.php',
            OpenAIStatefulConversationHelper::class => WPAICG_PLUGIN_DIR . 'classes/ai/providers/openai.php',
            BotSettingsManager::class => WPAICG_PLUGIN_DIR . 'classes/chatbot/bots.php',
        ];
        foreach ($process_chat_dependencies as $class => $path) {
            if (!class_exists($class) && file_exists($path)) {
                require_once $path;
            }
        }
    }

    public function get_bot_storage(): BotStorage
    {
        return $this->bot_storage;
    }
    public function get_log_storage(): LogStorage
    {
        return $this->log_storage;
    }
    public function get_token_manager(): AIPKit_Token_Manager
    {
        return $this->token_manager;
    }
    public function get_ai_service_for_helper(): ?ChatAIService
    {
        return $this->ai_service_for_helper;
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function process(array $cached_data, array $get_params)
    {
        return process_chat_logic($this, $cached_data, $get_params);
    }
}

/**
* Main orchestrator function for processing a chat stream request.
*
* @param \WPAICG\Core\Stream\Contexts\Chat\SSEChatStreamContextHandler $handlerInstance The instance of the context handler.
* @param array $cached_data Contains message data and optional provider file context.
* @param array $get_params Original $_GET parameters.
* @return array|WP_Error Prepared data for SSEStreamProcessor or WP_Error.
*/
function process_chat_logic(
    SSEChatStreamContextHandler $handlerInstance,
    array $cached_data,
    array $get_params
) {
    // 1. Extract Parameters
    $params = Process\extract_request_params_logic($cached_data, $get_params);

    // 2. Validate Stream Requirements
    $validation_result = Process\validate_stream_requirements_logic(
        $params['bot_id'],
        $params['conversation_uuid'],
        $params['user_id'],
        $params['session_id'],
        $params['user_message_text'],
        $params['image_inputs']
    );
    if (is_wp_error($validation_result)) {
        return $validation_result;
    }

    $bot_storage = $handlerInstance->get_bot_storage();
    if (!$bot_storage) {
        return new WP_Error('dependency_missing_bot_storage_moderation', 'Bot storage is unavailable for moderation.', ['status' => 500]);
    }

    $bot_settings = $bot_storage->get_chatbot_settings($params['bot_id']);
    if (empty($bot_settings)) {
        return new WP_Error('settings_load_failure_moderation', __('Could not load chatbot configuration.', 'gpt3-ai-content-generator'), ['status' => 500]);
    }

    if (class_exists(PaidStream::class)) {
        $file_context_check = PaidStream::apply_uploaded_file_context($params, $bot_settings);
        if (is_wp_error($file_context_check)) { return $file_context_check; }
    } elseif (!empty(array_filter(array_intersect_key($params, array_flip([
        'active_file_context_token', 'active_openai_vs_id', 'active_pinecone_index_name',
        'active_pinecone_namespace', 'active_qdrant_collection_name', 'active_qdrant_file_upload_context_id',
        'active_chroma_collection_name', 'active_chroma_file_upload_context_id', 'active_claude_file_id',
    ]))))) {
        return new WP_Error('chat_upload_unavailable', __('File upload is unavailable.', 'gpt3-ai-content-generator'), ['status' => 403]);
    }

    $active_google_document = null;
    if (!empty($params['active_google_file_context_token'])) {
        if (
            !class_exists('\WPAICG\aipkit_dashboard')
            || !\WPAICG\aipkit_dashboard::is_pro_plan()
        ) {
            return new WP_Error(
                'google_chat_file_pro_required',
                __('Google document upload requires a Pro plan.', 'gpt3-ai-content-generator'),
                ['status' => 403]
            );
        }
        if (!class_exists(PaidStream::class)) {
            return new WP_Error('google_chat_file_context_missing', __('Google file context validation is unavailable.', 'gpt3-ai-content-generator'), ['status' => 500]);
        }
        $active_google_document = PaidStream::google_document($params, $bot_settings);
        if (is_wp_error($active_google_document)) {
            return $active_google_document;
        }
    }

    $is_resume_after_form_submission = false;
    $form_resume_user_message_id = null;
    if (!empty($params['resume_after_form_submission'])) {
        if (!class_exists(PaidStream::class)) {
            $missing_token = $params['form_resume_token'] === '';
            return new WP_Error(
                $missing_token ? 'missing_form_resume_token' : 'invalid_form_resume_token',
                $missing_token ? __('Form resume token is missing.', 'gpt3-ai-content-generator') : __('Form resume token is invalid or expired.', 'gpt3-ai-content-generator'),
                ['status' => 403]
            );
        }
        $resume_result = PaidStream::prepare_form_resume($params, $handlerInstance->get_log_storage());
        if (is_wp_error($resume_result)) {
            return $resume_result;
        }
        $is_resume_after_form_submission = true;
        $form_resume_user_message_id = $resume_result['message_id'];
    }

    if (!$is_resume_after_form_submission && class_exists(PaidStream::class)) {
        $pending_form_error = PaidStream::pending_form_error($params);
        if (is_wp_error($pending_form_error)) {
            return $pending_form_error;
        }
    }

    // 3. Token Check
    $token_manager = $handlerInstance->get_token_manager();
    if (!$token_manager) {
        return new WP_Error('dependency_missing_token_manager', 'Token manager is unavailable.', ['status' => 500]);
    }
    $rate_check = AIPKit_Token_Manager::check_public_request_rate();
    if (is_wp_error($rate_check)) { return $rate_check; }
    $token_check_result = Process\run_token_check_logic(
        $token_manager,
        $params['user_id'],
        $params['session_id'],
        $params['bot_id'],
        $bot_settings,
        $params['user_message_text'],
        $params['image_inputs'],
        $handlerInstance->get_log_storage()
    );
    if (is_wp_error($token_check_result)) {
        return $token_check_result;
    }

    // 4. Content Moderation
    $moderation_result = Process\run_content_moderation_logic($params['user_message_text'], $params['client_ip'], $bot_settings, $handlerInstance->get_log_storage(), $params['bot_id'], $params['user_id'], $params['session_id']);
    if (is_wp_error($moderation_result)) {
        return $moderation_result;
    }

    // 5. Log User Message & Determine if New Session
    $base_log_data = [
        'bot_id' => $params['bot_id'], 'user_id' => $params['user_id'], 'session_id' => $params['session_id'],
        'conversation_uuid' => $params['conversation_uuid'], 'module' => 'chat', 'is_guest' => ($params['user_id'] === 0),
        'role' => ($params['user_id'] > 0 && class_exists('WP_User') && ($u = get_user_by('id', $params['user_id'])) && isset($u->roles) && is_array($u->roles)) ? implode(', ', $u->roles) : 'guest',
        'ip_address' => $params['client_ip'], 'form_id' => null,
        'user_message_id_from_client' => $params['client_user_message_id'],
    ];
    $bot_message_id_for_stream = 'aipkit-msg-' . uniqid('', true);
    $base_log_data['bot_message_id'] = $bot_message_id_for_stream;

    if ($is_resume_after_form_submission) {
        $user_log_result = [
            'log_id' => 0,
            'message_id' => $form_resume_user_message_id,
            'is_new_session' => false,
        ];
    } else {
        $user_log_result = Process\log_user_message_logic($handlerInstance->get_log_storage(), $base_log_data, $params['user_message_text'], $params['image_inputs'], time());
        if (is_wp_error($user_log_result)) {
            return $user_log_result;
        }
    }
    $is_new_session = $user_log_result['is_new_session'] ?? false;

    // 6. Trigger Processing
    $ai_service_for_triggers = $handlerInstance->get_ai_service_for_helper();
    if (!$ai_service_for_triggers) {
        return new WP_Error('dependency_missing_aiservice_triggers', 'AI Service component missing for triggers.', ['status' => 500]);
    }

    if (function_exists('\WPAICG\Chat\Core\AIService\determine_provider_model')) {
        $provider_model_info = \WPAICG\Chat\Core\AIService\determine_provider_model($ai_service_for_triggers, $bot_settings);
    } else {
        $provider_model_info = ['provider' => $bot_settings['provider'] ?? null, 'model' => $bot_settings['model'] ?? null];
    }

    if (!$is_resume_after_form_submission) {
        Process\emit_chatbot_user_events_logic(
            $handlerInstance->get_log_storage(),
            [
                'bot_id' => $params['bot_id'],
                'user_id' => $params['user_id'],
                'conversation_uuid' => $params['conversation_uuid'],
                'user_message_text' => $params['user_message_text'],
                'current_provider' => $provider_model_info['provider'] ?? null,
                'current_model_id' => $provider_model_info['model'] ?? null,
                'base_log_data' => $base_log_data,
            ],
            $user_log_result,
            $provider_model_info
        );
    }

    $trigger_context = [
        'bot_id' => $params['bot_id'], 'bot_settings' => $bot_settings, 'user_id' => $params['user_id'], 'session_id' => $params['session_id'],
        'conversation_uuid' => $params['conversation_uuid'], 'module' => 'chat',
        'client_ip' => $params['client_ip'], 'post_id' => $params['post_id'],
        'user_message_text' => $params['user_message_text'],
        'system_instruction_for_ai' => $bot_settings['instructions'] ?? '',
        'user_wp_role' => $base_log_data['role'],
        'current_provider' => $provider_model_info['provider'], 'current_model_id' => $provider_model_info['model'],
        'base_log_data' => $base_log_data, 'log_storage' => $handlerInstance->get_log_storage()
    ];

    if ($is_resume_after_form_submission) {
        $resume_instruction = PaidStream::build_form_submission_resume_instruction_logic(
            isset($params['form_submission_context']) && is_array($params['form_submission_context']) ? $params['form_submission_context'] : []
        );
        if ($resume_instruction !== '') {
            $trigger_context['system_instruction_for_ai'] = trim((string) $trigger_context['system_instruction_for_ai'] . "\n\n" . $resume_instruction);
        }
    }

    $initial_trigger_reply_data = null;

    if ($is_new_session) {
        $session_trigger_result = class_exists(PaidStream::class)
            ? PaidStream::process_session_start_trigger_logic($trigger_context)
            : Process\neutral_trigger_result_logic($trigger_context, []);
        if (is_wp_error($session_trigger_result)) {
            return $session_trigger_result;
        }
        // Update context based on session trigger result for user_message triggers
        $trigger_context['system_instruction_for_ai'] = $session_trigger_result['modified_context_data']['system_instruction'] ?? $trigger_context['system_instruction_for_ai'];
        $trigger_context['final_user_message_for_ai'] = $session_trigger_result['modified_context_data']['user_message_text'] ?? $trigger_context['user_message_text'];
        $initial_trigger_reply_data = $session_trigger_result['message_to_user'] ? $session_trigger_result : null;
    } else {
        $trigger_context['final_user_message_for_ai'] = $trigger_context['user_message_text'];
        $trigger_context['final_system_instruction_for_ai'] = $trigger_context['system_instruction_for_ai'];
    }

    $history_for_triggers = $handlerInstance->get_log_storage()->get_conversation_thread_history($params['user_id'] ?: null, $params['session_id'], $params['bot_id'], $params['conversation_uuid']);
    $user_message_count = 0;
    foreach ($history_for_triggers as $history_item) {
        if (is_array($history_item) && sanitize_key((string) ($history_item['role'] ?? '')) === 'user') {
            $user_message_count++;
        }
    }
    $trigger_context['user_message_count'] = $user_message_count;
    if (count($history_for_triggers) > 0 && end($history_for_triggers)['role'] === 'user') {
        array_pop($history_for_triggers);
    }
    $max_msgs_for_history = isset($bot_settings['max_messages']) ? absint($bot_settings['max_messages']) : 15;
    if (count($history_for_triggers) > $max_msgs_for_history) {
        $history_for_triggers = array_slice($history_for_triggers, -$max_msgs_for_history);
    }
    $trigger_context['final_history_for_ai'] = $history_for_triggers;

    if ($is_resume_after_form_submission) {
        $user_message_trigger_result = Process\neutral_trigger_result_logic($trigger_context, $trigger_context['final_history_for_ai'], $trigger_context['final_user_message_for_ai']);
    } else {
        $user_message_trigger_result = class_exists(PaidStream::class)
            ? PaidStream::process_user_message_trigger_logic($trigger_context)
            : Process\neutral_trigger_result_logic($trigger_context, $trigger_context['final_history_for_ai']);
        if (is_wp_error($user_message_trigger_result)) {
            return $user_message_trigger_result;
        }
        if (!$initial_trigger_reply_data && $user_message_trigger_result['message_to_user']) { // Only overwrite if session didn't provide one
            $initial_trigger_reply_data = $user_message_trigger_result;
        }
    }

    // 7. Build AI Request Data
    $request_data_for_ai = Process\build_ai_request_data_for_stream_logic(
        $ai_service_for_triggers->get_ai_caller(),
        $ai_service_for_triggers->get_vector_store_manager(),
        $user_message_trigger_result['modified_context_data']['user_message_text'],
        $bot_settings,
        $provider_model_info['provider'],
        $provider_model_info['model'],
        $user_message_trigger_result['modified_context_data']['current_history'],
        $user_message_trigger_result['modified_context_data']['system_instruction'],
        $params['post_id'],
        $params['image_inputs'],
        $params['frontend_previous_openai_response_id'],
        $params['frontend_previous_google_interaction_id'],
        $params['frontend_openai_web_search_active'],
        $params['frontend_google_search_grounding_active'],
        $params['active_openai_vs_id'],
        $params['active_pinecone_index_name'],
        $params['active_pinecone_namespace'],
        $params['active_qdrant_collection_name'],
        $params['active_qdrant_file_upload_context_id'],
        $params['active_chroma_collection_name'],
        $params['active_chroma_file_upload_context_id'],
        $params['active_claude_file_id'],
        $active_google_document
    );
    if (is_wp_error($request_data_for_ai)) {
        return $request_data_for_ai;
    }

    $openrouter_bot_id = absint($params['bot_id'] ?? 0);
    $openrouter_conversation_uuid = sanitize_key((string) ($params['conversation_uuid'] ?? ''));
    if (
        ($request_data_for_ai['provider'] ?? '') === 'OpenRouter'
        && ($bot_settings['openrouter_session_stickiness'] ?? '0') === '1'
        && $openrouter_bot_id > 0
        && $openrouter_conversation_uuid !== ''
    ) {
        $request_data_for_ai['ai_params']['openrouter_session_context'] = [
            'bot_id' => $openrouter_bot_id,
            'conversation_uuid' => $openrouter_conversation_uuid,
        ];
    }

    // 8. Construct Final Input for SSEStreamProcessor
    return Process\construct_sse_processor_input_logic(
        $request_data_for_ai,
        $params['conversation_uuid'],
        $base_log_data,
        $bot_message_id_for_stream,
        $initial_trigger_reply_data
    );
}

namespace WPAICG\Core\Stream\Contexts\Chat\Process;

use WPAICG\Chat\Admin\AdminSetup;
use WP_Error;
use WPAICG\Lib\Chat\Stream as PaidStream;
use WPAICG\Core\TokenManager\AIPKit_Token_Manager;
use WPAICG\Core\AIPKit_Content_Moderator;
use WPAICG\Chat\Storage\LogStorage;
use WPAICG\Core\AIPKit_Payload_Sanitizer;
use WPAICG\Core\AIPKit_Event_Webhooks;
use WPAICG\Core\AIPKit_AI_Caller;
use WPAICG\Core\AIPKit_OpenAI_Reasoning;
use WPAICG\Core\AIPKit_OpenRouter_Reasoning;
use WPAICG\Vector\AIPKit_Vector_Store_Manager;
use WPAICG\Core\AIPKit_Instruction_Manager;
use WPAICG\AIPKit_Providers;
use WPAICG\AIPKIT_AI_Settings;
use WPAICG\Core\Providers\OpenAI\OpenAIStatefulConversationHelper;
use WPAICG\Chat\Storage\BotSettingsManager;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Extracts and sanitizes request parameters for chat stream processing.
 *
 * @param array $cached_data Data retrieved from the SSE cache.
 * @param array $get_params Original $_GET parameters from the SSE request.
 * @return array An associative array of extracted and sanitized parameters.
 */
function extract_request_params_logic(array $cached_data, array $get_params): array
{
    return [
        'user_id'            => get_current_user_id(),
        'bot_id'             => isset($get_params['bot_id']) ? absint($get_params['bot_id']) : 0,
        'session_id'         => isset($get_params['session_id']) ? sanitize_text_field(wp_unslash($get_params['session_id'])) : '',
        'conversation_uuid'  => isset($get_params['conversation_uuid']) ? sanitize_key($get_params['conversation_uuid']) : '',
        'post_id'            => isset($get_params['post_id']) ? absint($get_params['post_id']) : 0,
        'user_message_text'  => $cached_data['user_message'] ?? '',
        'image_inputs'       => $cached_data['image_inputs'] ?? null,
        'client_ip'          => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : null,
        'frontend_previous_openai_response_id' => isset($get_params['previous_openai_response_id']) ? sanitize_text_field($get_params['previous_openai_response_id']) : null,
        'frontend_previous_google_interaction_id' => isset($get_params['previous_google_interaction_id']) ? sanitize_text_field($get_params['previous_google_interaction_id']) : null,
        'frontend_openai_web_search_active' => (isset($get_params['frontend_web_search_active']) && $get_params['frontend_web_search_active'] === 'true'),
        'frontend_google_search_grounding_active' => (isset($get_params['frontend_google_search_grounding_active']) && $get_params['frontend_google_search_grounding_active'] === 'true'),
        'client_user_message_id' => $cached_data['client_user_message_id'] ?? null,
        'resume_after_form_submission' => !empty($cached_data['resume_after_form_submission']),
        'form_submission_context' => isset($cached_data['form_submission_context']) && is_array($cached_data['form_submission_context']) ? $cached_data['form_submission_context'] : [],
        'form_resume_token' => isset($cached_data['form_resume_token']) ? sanitize_text_field((string) $cached_data['form_resume_token']) : '',
        'active_file_context_token' => (string) ($cached_data['active_file_context_token'] ?? ''),
        'active_openai_vs_id' => $cached_data['active_openai_vs_id'] ?? ($get_params['active_openai_vs_id'] ?? null),
        'active_pinecone_index_name' => $cached_data['active_pinecone_index_name'] ?? ($get_params['active_pinecone_index_name'] ?? null),
        'active_pinecone_namespace' => $cached_data['active_pinecone_namespace'] ?? ($get_params['active_pinecone_namespace'] ?? null),
        'active_qdrant_collection_name' => $cached_data['active_qdrant_collection_name'] ?? ($get_params['active_qdrant_collection_name'] ?? null),
        'active_qdrant_file_upload_context_id' => $cached_data['active_qdrant_file_upload_context_id'] ?? ($get_params['active_qdrant_file_upload_context_id'] ?? null),
        'active_chroma_collection_name' => $cached_data['active_chroma_collection_name'] ?? ($get_params['active_chroma_collection_name'] ?? null),
        'active_chroma_file_upload_context_id' => $cached_data['active_chroma_file_upload_context_id'] ?? ($get_params['active_chroma_file_upload_context_id'] ?? null),
        'active_claude_file_id' => isset($cached_data['active_claude_file_id'])
            ? sanitize_text_field((string) $cached_data['active_claude_file_id'])
            : (isset($get_params['active_claude_file_id']) ? sanitize_text_field(wp_unslash($get_params['active_claude_file_id'])) : null),
        // Keep this signed token out of the EventSource URL; it is accepted only
        // from the nonce-protected message cache request.
        'active_google_file_context_token' => isset($cached_data['active_google_file_context_token'])
            ? sanitize_text_field((string) $cached_data['active_google_file_context_token'])
            : null,
    ];
}

/**
 * Validates the essential requirements for a chat stream request.
 *
 * @param int         $bot_id            The ID of the chatbot.
 * @param string      $conversation_uuid The UUID of the conversation.
 * @param int|null    $user_id           The ID of the logged-in user, or null for guests.
 * @param string|null $session_id        The session ID for guests.
 * @param string      $user_message_text The user's text message.
 * @param array|null  $image_inputs      Processed image input data.
 * @return true|WP_Error True if validation passes, WP_Error otherwise.
 */
function validate_stream_requirements_logic(
    int $bot_id,
    string $conversation_uuid,
    ?int $user_id,
    ?string $session_id,
    string $user_message_text,
    ?array $image_inputs
) {
    // Ensure AdminSetup is loaded for POST_TYPE constant
    if (!class_exists(AdminSetup::class)) {
        $admin_setup_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/admin.php';
        if (file_exists($admin_setup_path)) {
            require_once $admin_setup_path;
        } else {
            return new WP_Error(
                'dependency_missing_validator_logic',
                __('Chat system component (AdminSetup) missing.', 'gpt3-ai-content-generator'),
                ['status' => 500, 'failed_module' => 'chat_stream_context', 'failed_operation' => 'load_admin_setup']
            );
        }
    }

    if (empty($bot_id) || get_post_type($bot_id) !== AdminSetup::POST_TYPE || get_post_status($bot_id) !== 'publish') {
        return new WP_Error('invalid_bot_id_stream_req', __('Invalid chatbot specified.', 'gpt3-ai-content-generator'), ['status' => 400, 'failed_module' => 'chat_stream_context', 'failed_operation' => 'validate_bot_id']);
    }
    if (empty($conversation_uuid)) {
        return new WP_Error('missing_conversation_uuid_stream_req', __('Conversation ID is missing.', 'gpt3-ai-content-generator'), ['status' => 400, 'failed_module' => 'chat_stream_context', 'failed_operation' => 'validate_conv_uuid']);
    }
    if (!$user_id && empty($session_id)) {
        return new WP_Error('missing_session_id_stream_req', __('Session ID is missing for guest.', 'gpt3-ai-content-generator'), ['status' => 400, 'failed_module' => 'chat_stream_context', 'failed_operation' => 'validate_session_id']);
    }
    if (empty($user_message_text) && empty($image_inputs)) {
        return new WP_Error('empty_content_stream_req', __('Message or image cannot be empty.', 'gpt3-ai-content-generator'), ['status' => 400, 'failed_module' => 'chat_stream_context', 'failed_operation' => 'validate_content']);
    }
    return true;
}

require_once WPAICG_PLUGIN_DIR . 'classes/chatbot/pricing-context.php';

/**
 * Performs token limit checks for a chat stream request.
 * Dispatches a 'system_error_occurred' trigger if the token check fails.
 *
 * @param AIPKit_Token_Manager $token_manager Instance of the token manager.
 * @param int|null    $user_id          User ID, or null for guests.
 * @param string|null $session_id       Session ID for guests.
 * @param int         $bot_id           Bot ID for the current chat context.
 * @param array<string, mixed> $bot_settings Bot configuration used to estimate priced usage.
 * @param string $user_message_text Current user message for conservative token estimation.
 * @param array<int, mixed>|null $image_inputs Optional image inputs for multimodal estimation.
 * @param LogStorage|null $log_storage    Instance of LogStorage (for trigger manager).
 * @return true|WP_Error True if token check passes or not applicable, WP_Error if limit exceeded.
 */
function run_token_check_logic(
    AIPKit_Token_Manager $token_manager,
    ?int $user_id,
    ?string $session_id,
    int $bot_id,
    array $bot_settings,
    string $user_message_text,
    ?array $image_inputs,
    ?LogStorage $log_storage
) {
    $usage_context = \WPAICG\Chat\Core\Pricing\build_chat_pricing_check_context_logic(
        $bot_id,
        $bot_settings,
        $user_message_text,
        $image_inputs
    );
    $token_check_result = $token_manager->check_and_reset_tokens($user_id ?: null, $session_id, $bot_id, 'chat', $usage_context);

    if (is_wp_error($token_check_result)) {
        if (class_exists(PaidStream::class)) {
            PaidStream::validation_error($token_check_result, $log_storage, $bot_id, $user_id, $session_id, 'chat_stream_context', 'token_check', 429);
        }
        // Return the original error from token manager, including status code
        $token_error_data = $token_check_result->get_error_data();
        if (!is_array($token_error_data)) {
            $token_error_data = [];
        }
        if (!isset($token_error_data['status'])) {
            $token_error_data['status'] = 429;
        }
        return new WP_Error($token_check_result->get_error_code(), $token_check_result->get_error_message(), $token_error_data);
    }
    return true; // Token check passed
}

/**
 * Performs content moderation checks (global blocklists and OpenAI moderation).
 * Dispatches a 'system_error_occurred' trigger if moderation fails.
 *
 * @param string $user_message_text The user's text message.
 * @param string|null $client_ip The client's IP address.
 * @param array $bot_settings Settings for the current bot.
 * @param LogStorage|null $log_storage Instance of LogStorage for triggers.
 * @param int $bot_id The ID of the current bot.
 * @param int|null $user_id The ID of the user, or null for guests.
 * @param string|null $session_id The session ID for guests.
 * @return true|WP_Error True if moderation passes, WP_Error otherwise.
 */
function run_content_moderation_logic(
    string $user_message_text,
    ?string $client_ip,
    array $bot_settings,
    ?LogStorage $log_storage,
    int $bot_id,
    ?int $user_id,
    ?string $session_id
) {

    if (!class_exists(AIPKit_Content_Moderator::class)) {
        return true; // Fail open if moderator class is missing.
    }

    $moderation_context = [
        'client_ip' => $client_ip,
        'bot_settings' => $bot_settings,
        'module' => 'chat',
    ];
    $moderation_check = AIPKit_Content_Moderator::check_content($user_message_text, $moderation_context);

    if (is_wp_error($moderation_check)) {
        if (class_exists(PaidStream::class)) {
            PaidStream::validation_error($moderation_check, $log_storage, $bot_id, $user_id, $session_id, 'chat_content_moderation', 'check_user_message', 400);
        }
        // Return the original WP_Error, ensuring status code is preserved
        $status_code_from_error = is_array($moderation_check->get_error_data()) && isset($moderation_check->get_error_data()['status'])
                                  ? (int)$moderation_check->get_error_data()['status']
                                  : 400;
        return new WP_Error($moderation_check->get_error_code(), $moderation_check->get_error_message(), ['status' => $status_code_from_error]);
    }
    return true; // Moderation passed
}

/**
* Logs the initial user message for a chat stream.
*
* @param LogStorage $log_storage Instance of LogStorage.
* @param array $base_log_data Base log data (bot_id, user_id, session_id, conversation_uuid, module, is_guest, role, ip_address, bot_message_id FOR BOT, user_message_id_from_client FOR USER).
* @param string $user_message_text The user's text message.
* @param array|null $image_inputs Processed image input data.
* @param int $request_timestamp The timestamp of the request.
* @return array|WP_Error Result from LogStorage::log_message or WP_Error on failure.
*/
function log_user_message_logic(
    LogStorage $log_storage,
    array $base_log_data,
    string $user_message_text,
    ?array $image_inputs,
    int $request_timestamp
) {
    $log_user_data = [
        'bot_id'            => $base_log_data['bot_id'],
        'user_id'           => $base_log_data['user_id'],
        'session_id'        => $base_log_data['session_id'],
        'conversation_uuid' => $base_log_data['conversation_uuid'],
        'module'            => $base_log_data['module'],
        'is_guest'          => $base_log_data['is_guest'],
        'role'              => $base_log_data['role'],
        'ip_address'        => $base_log_data['ip_address'] ?? null,
        'ip_anonymize'      => $base_log_data['ip_anonymize'] ?? false,
        'message_role'      => 'user',
        'message_content'   => $user_message_text,
        'timestamp'         => $request_timestamp,
        'message_id'        => $base_log_data['user_message_id_from_client'] ?? null,
    ];
    unset($log_user_data['bot_message_id'], $log_user_data['user_message_id_from_client']);

    if (!empty($image_inputs)) {
        $response_data_for_log = AIPKit_Payload_Sanitizer::sanitize_payload_if_array([
            'type' => 'user_image_upload',
            'images' => $image_inputs,
        ]);
        $log_user_data['response_data'] = $response_data_for_log;
    }

    $user_log_result = $log_storage->log_message($log_user_data);

    if ($user_log_result === false) {
        return new WP_Error('user_log_failed_logic', __('Failed to log user message.', 'gpt3-ai-content-generator'), ['status' => 500]);
    }
    return $user_log_result; // Contains ['log_id', 'message_id', 'is_new_session']
}

/**
 * Emits the canonical chatbot user-side events after the user message is stored.
 *
 * @param LogStorage $log_storage
 * @param array<string, mixed> $context
 * @param array<string, mixed> $user_log_result
 * @param array<string, mixed> $provider_model_info
 * @return void
 */
function emit_chatbot_user_events_logic(
    LogStorage $log_storage,
    array $context,
    array $user_log_result,
    array $provider_model_info = []
): void {
    if (!class_exists(AIPKit_Event_Webhooks::class)) {
        return;
    }

    $log_id = absint($user_log_result['log_id'] ?? 0);
    $message_id = sanitize_key((string) ($user_log_result['message_id'] ?? ''));
    $conversation_uuid = sanitize_key((string) ($context['conversation_uuid'] ?? ($context['base_log_data']['conversation_uuid'] ?? '')));
    $bot_id = absint($context['bot_id'] ?? 0);
    $user_id = absint($context['user_id'] ?? 0);
    $actor_type = $user_id > 0 ? 'user' : 'guest';

    if ($log_id <= 0 || $message_id === '' || $conversation_uuid === '' || $bot_id <= 0) {
        return;
    }

    $conversation_log = $log_storage->get_log_by_id($log_id);
    $bot_name = sanitize_text_field((string) ($conversation_log['bot_name'] ?? get_the_title($bot_id)));
    $message_count = absint($conversation_log['message_count'] ?? 1);
    $message_text = sanitize_textarea_field((string) ($context['user_message_text'] ?? ''));
    $provider = sanitize_text_field((string) ($provider_model_info['provider'] ?? ($context['current_provider'] ?? '')));
    $model = sanitize_text_field((string) ($provider_model_info['model'] ?? ($context['current_model_id'] ?? '')));

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
            'type' => $actor_type,
        ],
        'message' => [
            'id' => $message_id,
            'text' => $message_text,
        ],
        'ai' => [
            'provider' => $provider,
            'model' => $model,
        ],
    ];

    if ($user_id > 0) {
        $payload['actor']['user_id'] = $user_id;
    }

    if (!empty($user_log_result['is_new_session'])) {
        AIPKit_Event_Webhooks::emit(
            'chatbot.session_started',
            $payload,
            [
                'module' => 'chatbot',
                'origin' => 'frontend_session_started',
                'resource' => [
                    'type' => 'conversation',
                    'id' => $conversation_uuid,
                    'label' => $bot_name !== ''
                        ? sprintf(
                            /* translators: %s: chatbot name */
                            __('Conversation started for %s', 'gpt3-ai-content-generator'),
                            $bot_name
                        )
                        : __('Chat session started', 'gpt3-ai-content-generator'),
                ],
                'meta' => [
                    'bot_id' => $bot_id,
                    'conversation_uuid' => $conversation_uuid,
                    'message_id' => $message_id,
                    'message_count' => $message_count,
                    'is_guest' => $user_id > 0 ? 0 : 1,
                ],
                'idempotency_key' => sha1(implode('|', [
                    'chatbot.session_started',
                    (string) $bot_id,
                    $conversation_uuid,
                    $message_id,
                    $user_id > 0 ? (string) $user_id : 'guest',
                ])),
            ]
        );
    }

    AIPKit_Event_Webhooks::emit(
        'chatbot.user_message_submitted',
        $payload,
        [
            'module' => 'chatbot',
            'origin' => 'frontend_user_message',
            'resource' => [
                'type' => 'conversation_message',
                'id' => $message_id,
                'label' => $bot_name !== ''
                    ? sprintf(
                        /* translators: %s: chatbot name */
                        __('User message to %s', 'gpt3-ai-content-generator'),
                        $bot_name
                    )
                    : __('Chat user message', 'gpt3-ai-content-generator'),
            ],
            'meta' => [
                'bot_id' => $bot_id,
                'conversation_uuid' => $conversation_uuid,
                'message_id' => $message_id,
                'message_count' => $message_count,
                'is_guest' => $user_id > 0 ? 0 : 1,
            ],
            'idempotency_key' => sha1(implode('|', [
                'chatbot.user_message_submitted',
                (string) $bot_id,
                $conversation_uuid,
                $message_id,
                $user_id > 0 ? (string) $user_id : 'guest',
            ])),
        ]
    );
}

/**
 * Builds all necessary data for the AI stream request.
 *
 * @param AIPKit_AI_Caller $ai_caller Instance of AI Caller.
 * @param AIPKit_Vector_Store_Manager $vector_store_manager Instance of Vector Store Manager.
 * @param string $final_user_message_for_ai The user's message after trigger processing.
 * @param array $bot_settings Bot settings.
 * @param string $main_provider_for_ai The main AI provider for the chat.
 * @param string $model_id_for_ai The selected AI model.
 * @param array $final_history_for_ai Conversation history after trigger processing.
 * @param string $system_instruction_after_triggers System instruction after trigger processing.
 * @param int $post_id Current post ID.
 * @param array|null $image_inputs Processed image inputs.
 * @param string|null $frontend_previous_openai_response_id Previous OpenAI response ID.
 * @param string|null $frontend_previous_google_interaction_id Previous Google interaction ID.
 * @param bool $frontend_openai_web_search_active Flag for OpenAI web search.
 * @param bool $frontend_google_search_grounding_active Flag for Google Search Grounding.
 * @param string|null $frontend_active_openai_vs_id Active OpenAI Vector Store ID.
 * @param string|null $frontend_active_pinecone_index_name Active Pinecone index name.
 * @param string|null $frontend_active_pinecone_namespace Active Pinecone namespace.
 * @param string|null $frontend_active_qdrant_collection_name Active Qdrant collection name.
 * @param string|null $frontend_active_qdrant_file_upload_context_id Active Qdrant file context ID.
 * @param string|null $frontend_active_chroma_collection_name Active Chroma collection name.
 * @param string|null $frontend_active_chroma_file_upload_context_id Active Chroma file context ID.
 * @param string|null $frontend_active_claude_file_id Active Claude file ID.
 * @param array<string, mixed>|null $frontend_active_google_document Verified Google document input.
 * @return array|WP_Error Prepared data array or WP_Error.
 */
function build_ai_request_data_for_stream_logic(
    AIPKit_AI_Caller $ai_caller,
    AIPKit_Vector_Store_Manager $vector_store_manager,
    string $final_user_message_for_ai,
    array $bot_settings,
    string $main_provider_for_ai,
    string $model_id_for_ai,
    array $final_history_for_ai,
    string $system_instruction_after_triggers,
    int $post_id,
    ?array $image_inputs,
    ?string $frontend_previous_openai_response_id,
    ?string $frontend_previous_google_interaction_id,
    bool $frontend_openai_web_search_active,
    bool $frontend_google_search_grounding_active,
    ?string $frontend_active_openai_vs_id,
    ?string $frontend_active_pinecone_index_name,
    ?string $frontend_active_pinecone_namespace,
    ?string $frontend_active_qdrant_collection_name,
    ?string $frontend_active_qdrant_file_upload_context_id,
    ?string $frontend_active_chroma_collection_name,
    ?string $frontend_active_chroma_file_upload_context_id,
    ?string $frontend_active_claude_file_id,
    ?array $frontend_active_google_document
) {

    // Ensure dependencies for sub-logics are loaded
    if (!class_exists(AIPKit_Instruction_Manager::class)) {
        return new WP_Error('dependency_missing_instr_mgr', 'Instruction Manager component is missing.');
    }
    if (!class_exists(AIPKit_Providers::class) || !class_exists(AIPKIT_AI_Settings::class)) {
        return new WP_Error('dependency_missing_global_settings', 'Global settings components (Providers/AI_Settings) are missing.');
    }
    if ($main_provider_for_ai === 'OpenAI' && !class_exists(OpenAIStatefulConversationHelper::class)) {
        return new WP_Error('dependency_missing_openai_helper', 'OpenAI Stateful Helper component is missing.');
    }
    $feature_strategy = \WPAICG\Core\Providers\ProviderStrategyFactory::get_strategy($main_provider_for_ai);
    if (is_wp_error($feature_strategy)) { return $feature_strategy; }
    $feature_error = $feature_strategy->validate_chatbot_features($bot_settings, !empty($image_inputs), $frontend_openai_web_search_active || $frontend_google_search_grounding_active);
    if ($feature_error) { return $feature_error; }

    if (!empty($image_inputs)) {
        /**
         * Allow provider-specific image capability validation for chat stream requests.
         *
         * Return a WP_Error to block request when selected provider/model
         * cannot accept image inputs.
         *
         * @param mixed $validation_error Existing validation error (null by default).
         * @param array $image_inputs Normalized image input payload.
         * @param string $provider Selected provider.
         * @param string $model Selected model.
         * @param array $bot_settings Bot settings.
         * @param string $flow Request flow identifier.
         */
        $image_validation_error = apply_filters(
            'aipkit_chat_image_input_validation_error',
            null,
            $image_inputs,
            $main_provider_for_ai,
            $model_id_for_ai,
            $bot_settings,
            'stream'
        );
        if (is_wp_error($image_validation_error)) {
            return $image_validation_error;
        }
    }

    $all_formatted_results_for_instruction = "";
    $vector_search_scores = []; // Initialize array to capture vector search scores
    if (function_exists('\WPAICG\Core\Stream\Vector\build_vector_search_context_logic')) {
        $all_formatted_results_for_instruction = \WPAICG\Core\Stream\Vector\build_vector_search_context_logic(
            $ai_caller,
            $vector_store_manager,
            $final_user_message_for_ai,
            $bot_settings,
            $main_provider_for_ai,
            $frontend_active_openai_vs_id,
            $frontend_active_pinecone_index_name,
            $frontend_active_pinecone_namespace,
            $frontend_active_qdrant_collection_name,
            $frontend_active_qdrant_file_upload_context_id,
            $frontend_active_chroma_collection_name,
            $frontend_active_chroma_file_upload_context_id,
            $vector_search_scores // Pass reference to capture scores
        );
    }

    if (is_wp_error($all_formatted_results_for_instruction)) { return $all_formatted_results_for_instruction; }

    $instruction_context = [
        'base_instructions' => $system_instruction_after_triggers,
        'bot_settings' => $bot_settings,
        'post_id' => $post_id
    ];
    if (!empty($all_formatted_results_for_instruction)) {
        $instruction_context['vector_search_results'] = trim($all_formatted_results_for_instruction);
    }
    $instructions_built = AIPKit_Instruction_Manager::build_instructions($instruction_context);
    $instructions_filtered_for_api = apply_filters('aipkit_system_instruction', $instructions_built, $main_provider_for_ai, $model_id_for_ai, $final_user_message_for_ai, $final_history_for_ai, $bot_settings, $bot_settings['session_id'] ?? null);

    $global_ai_params = AIPKIT_AI_Settings::get_ai_parameters();
    $ai_params_for_payload = [
        'temperature' => isset($bot_settings['temperature']) ? floatval($bot_settings['temperature']) : floatval($global_ai_params['temperature'] ?? 1.0),
        'max_completion_tokens' => isset($bot_settings['max_completion_tokens']) ? absint($bot_settings['max_completion_tokens']) : absint($global_ai_params['max_completion_tokens'] ?? 4000),
    ];
    $global_only_keys = ['top_p', 'stop'];
    foreach ($global_only_keys as $k) {
        if (isset($global_ai_params[$k])) {
            $ai_params_for_payload[$k] = $global_ai_params[$k];
        }
    }
    if (!empty($image_inputs)) {
        $ai_params_for_payload['image_inputs'] = $image_inputs;
    }

    // Make a mutable copy of history for potential modification by stateful helper
    $history_for_stateful_check = $final_history_for_ai;

    if ($main_provider_for_ai === 'OpenAI' && class_exists(OpenAIStatefulConversationHelper::class)) {
        $stateful_result = OpenAIStatefulConversationHelper::prepare_parameters_and_history(
            $ai_params_for_payload, // Passed by value, modified array returned
            $history_for_stateful_check, // Passed by value, modified array returned
            $bot_settings,
            $frontend_previous_openai_response_id
        );
        $ai_params_for_payload = $stateful_result['ai_params'];
        $history_for_stateful_check = $stateful_result['history']; // Update history if stateful logic modified it
    }

    if ($main_provider_for_ai === 'OpenAI') {
        $knowledge_uses_openai = ($bot_settings['enable_vector_store'] ?? '0') === '1'
            && ($bot_settings['vector_store_provider'] ?? '') === 'openai';
        $vector_store_ids_to_use = $knowledge_uses_openai
            ? ($bot_settings['openai_vector_store_ids'] ?? [])
            : [];
        if (class_exists(PaidStream::class)) {
            $vector_store_ids_to_use = PaidStream::add_openai_file_context($vector_store_ids_to_use, $bot_settings, $frontend_active_openai_vs_id);
        }
        $vector_store_ids_to_use = array_unique(array_filter($vector_store_ids_to_use));
        $vector_top_k_openai = absint($bot_settings['vector_store_top_k'] ?? 3);
        $vector_top_k_openai = max(1, min($vector_top_k_openai, 20));
        if (!empty($vector_store_ids_to_use)) {
            // Get confidence threshold and convert to OpenAI score threshold
            $confidence_threshold_percent = (int)($bot_settings['vector_store_confidence_threshold'] ?? 20);
            $openai_score_threshold = round($confidence_threshold_percent / 100, 4); // Round to avoid precision issues

            $ai_params_for_payload['vector_store_tool_config'] = [
                'type' => 'file_search',
                'vector_store_ids' => $vector_store_ids_to_use,
                'max_num_results' => $vector_top_k_openai,
                'ranking_options' => [
                    'score_threshold' => $openai_score_threshold
                ]
            ];
        }
        if (($bot_settings['openai_web_search_enabled'] ?? '0') === '1') {
            $ai_params_for_payload['web_search_tool_config'] = ['enabled' => true, 'search_context_size' => $bot_settings['openai_web_search_context_size'] ?? BotSettingsManager::DEFAULT_OPENAI_WEB_SEARCH_CONTEXT_SIZE];
            if (($bot_settings['openai_web_search_loc_type'] ?? 'none') === 'approximate') {
                $user_loc = array_filter(['country' => $bot_settings['openai_web_search_loc_country'] ?? null, 'city' => $bot_settings['openai_web_search_loc_city'] ?? null, 'region' => $bot_settings['openai_web_search_loc_region'] ?? null, 'timezone' => $bot_settings['openai_web_search_loc_timezone'] ?? null]);
                if (!empty($user_loc)) {
                    $ai_params_for_payload['web_search_tool_config']['user_location'] = $user_loc;
                }
            }
            $ai_params_for_payload['frontend_web_search_active'] = $frontend_openai_web_search_active;
        }
        $reasoning_effort = AIPKit_OpenAI_Reasoning::normalize_effort_for_model(
            (string) $model_id_for_ai,
            $bot_settings['reasoning_effort'] ?? ''
        );
        if ($reasoning_effort !== '') {
            $ai_params_for_payload['reasoning'] = ['effort' => $reasoning_effort];
        }
    } elseif ($main_provider_for_ai === 'Claude') {
        if (($bot_settings['claude_web_search_enabled'] ?? '0') === '1') {
            $web_search_config = [
                'enabled' => true,
                'type' => 'web_search_20250305',
            ];

            $claude_max_uses = isset($bot_settings['claude_web_search_max_uses'])
                ? absint($bot_settings['claude_web_search_max_uses'])
                : BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_MAX_USES;
            $claude_max_uses = max(1, min($claude_max_uses, 20));
            $web_search_config['max_uses'] = $claude_max_uses;

            $split_domains = static function ($domains_raw): array {
                if (!is_string($domains_raw) || trim($domains_raw) === '') {
                    return [];
                }
                $parts = preg_split('/[\r\n,]+/', $domains_raw);
                if (!is_array($parts)) {
                    return [];
                }
                $domains = array_values(array_filter(array_map(static function ($part) {
                    $domain = strtolower(trim((string) $part));
                    if ($domain === '') {
                        return '';
                    }
                    $domain = preg_replace('/^https?:\/\//', '', $domain);
                    $domain = trim((string) $domain, " \t\n\r\0\x0B/");
                    if ($domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)) {
                        return '';
                    }
                    return $domain;
                }, $parts)));
                return array_values(array_unique($domains));
            };

            $allowed_domains = $split_domains($bot_settings['claude_web_search_allowed_domains'] ?? '');
            $blocked_domains = $split_domains($bot_settings['claude_web_search_blocked_domains'] ?? '');
            if (!empty($allowed_domains)) {
                $web_search_config['allowed_domains'] = $allowed_domains;
            } elseif (!empty($blocked_domains)) {
                $web_search_config['blocked_domains'] = $blocked_domains;
            }

            if (($bot_settings['claude_web_search_loc_type'] ?? 'none') === 'approximate') {
                $claude_user_location = array_filter([
                    'country' => $bot_settings['claude_web_search_loc_country'] ?? null,
                    'city' => $bot_settings['claude_web_search_loc_city'] ?? null,
                    'region' => $bot_settings['claude_web_search_loc_region'] ?? null,
                    'timezone' => $bot_settings['claude_web_search_loc_timezone'] ?? null,
                ]);
                if (!empty($claude_user_location)) {
                    $claude_user_location['type'] = 'approximate';
                    $web_search_config['user_location'] = $claude_user_location;
                }
            }

            $cache_ttl = $bot_settings['claude_web_search_cache_ttl'] ?? BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_CACHE_TTL;
            if (in_array($cache_ttl, ['5m', '1h'], true)) {
                $web_search_config['cache_control'] = [
                    'type' => 'ephemeral',
                    'ttl' => $cache_ttl,
                ];
            }

            $ai_params_for_payload['web_search_tool_config'] = $web_search_config;
            $ai_params_for_payload['frontend_web_search_active'] = $frontend_openai_web_search_active;
        }
        if (class_exists(PaidStream::class)) {
            PaidStream::apply_document_context($ai_params_for_payload, 'Claude', $bot_settings, $frontend_active_claude_file_id, null);
        }
    } elseif ($main_provider_for_ai === 'AIPufferCloud') {
        $reasoning_effort = \WPAICG\Cloud\Connection::reasoning_effort((string) $model_id_for_ai, $bot_settings['reasoning_effort'] ?? '');
        if ($reasoning_effort !== '') {
            $ai_params_for_payload['reasoning'] = ['effort' => $reasoning_effort];
        }
    } elseif ($main_provider_for_ai === 'OpenRouter') {
        $reasoning_effort = AIPKit_OpenRouter_Reasoning::normalize_effort_for_model(
            (string) $model_id_for_ai,
            $bot_settings['reasoning_effort'] ?? ''
        );
        if ($reasoning_effort !== '') {
            $ai_params_for_payload['reasoning'] = ['effort' => $reasoning_effort];
        }
        if (($bot_settings['openrouter_web_search_enabled'] ?? '0') === '1') {
            $web_search_config = ['enabled' => true];

            $openrouter_engine = isset($bot_settings['openrouter_web_search_engine'])
                ? sanitize_key((string) $bot_settings['openrouter_web_search_engine'])
                : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_ENGINE;
            if (in_array($openrouter_engine, ['native', 'exa', 'firecrawl', 'parallel', 'perplexity'], true)) {
                $web_search_config['engine'] = $openrouter_engine;
            }

            $openrouter_max_results = isset($bot_settings['openrouter_web_search_max_results'])
                ? absint($bot_settings['openrouter_web_search_max_results'])
                : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_MAX_RESULTS;
            $web_search_config['max_results'] = max(1, min($openrouter_max_results, 25));
            $openrouter_max_uses = isset($bot_settings['openrouter_web_search_max_uses'])
                ? absint($bot_settings['openrouter_web_search_max_uses'])
                : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_MAX_USES;
            $web_search_config['max_uses'] = max(1, min($openrouter_max_uses, 10));
            $openrouter_max_total_results = isset($bot_settings['openrouter_web_search_max_total_results'])
                ? absint($bot_settings['openrouter_web_search_max_total_results'])
                : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_MAX_TOTAL_RESULTS;
            $web_search_config['max_total_results'] = max(1, min($openrouter_max_total_results, 100));
            $openrouter_context_size = isset($bot_settings['openrouter_web_search_context_size'])
                ? sanitize_key((string) $bot_settings['openrouter_web_search_context_size'])
                : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_CONTEXT_SIZE;
            if (in_array($openrouter_context_size, ['low', 'medium', 'high'], true)) {
                $web_search_config['search_context_size'] = $openrouter_context_size;
            }
            $web_search_config['allowed_domains'] = $bot_settings['openrouter_web_search_allowed_domains'] ?? '';
            $web_search_config['excluded_domains'] = $bot_settings['openrouter_web_search_excluded_domains'] ?? '';

            $ai_params_for_payload['web_search_tool_config'] = $web_search_config;
            $ai_params_for_payload['frontend_web_search_active'] = $frontend_openai_web_search_active;
        }
    } elseif ($main_provider_for_ai === 'xAI') {
        if (($bot_settings['xai_web_search_enabled'] ?? '0') === '1') {
            $ai_params_for_payload['xai_web_search_tool_config'] = ['enabled' => true];
            $ai_params_for_payload['frontend_web_search_active'] = $frontend_openai_web_search_active;
        }
    } elseif ($main_provider_for_ai === 'Google') {
        if (class_exists(PaidStream::class)) {
            PaidStream::apply_document_context($ai_params_for_payload, 'Google', $bot_settings, null, $frontend_active_google_document);
        }
        $google_store_names = isset($bot_settings['google_file_search_store_names'])
            && is_array($bot_settings['google_file_search_store_names'])
            ? $bot_settings['google_file_search_store_names']
            : [];
        $google_store_names = array_values(array_unique(array_filter(array_map('sanitize_text_field', $google_store_names))));
        if (
            ($bot_settings['enable_vector_store'] ?? '0') === '1'
            && ($bot_settings['vector_store_provider'] ?? '') === 'google'
            && !empty($google_store_names)
        ) {
            $google_top_k = absint($bot_settings['vector_store_top_k'] ?? 3);
            $ai_params_for_payload['google_file_search_tool_config'] = [
                'file_search_store_names' => $google_store_names,
                'top_k' => max(1, min($google_top_k, 20)),
            ];
        }
        $use_google_conversation_state = ($bot_settings['google_conversation_state_enabled'] ?? '0') === '1';
        $ai_params_for_payload['use_google_conversation_state'] = $use_google_conversation_state;
        if ($use_google_conversation_state) {
            $ai_params_for_payload['store_conversation'] = '1';
            if (!empty($frontend_previous_google_interaction_id)) {
                $ai_params_for_payload['google_previous_interaction_id'] = $frontend_previous_google_interaction_id;
            }
        }
        if (($bot_settings['google_search_grounding_enabled'] ?? '0') === '1') {
            $ai_params_for_payload['frontend_google_search_grounding_active'] = $frontend_google_search_grounding_active;
        }
    } elseif ($main_provider_for_ai === 'Ollama' && function_exists('\\WPAICG\\Chat\\Core\\AIService\\GenerateResponse\\AiParams\\apply_ollama_thinking_logic')) {
        \WPAICG\Chat\Core\AIService\GenerateResponse\AiParams\apply_ollama_thinking_logic($ai_params_for_payload, $bot_settings);
    }

    $provData = AIPKit_Providers::get_provider_data($main_provider_for_ai);
    $api_params_for_stream = [
        'api_key' => $provData['api_key'] ?? '', 'base_url' => $provData['base_url'] ?? '', 'api_version' => $provData['api_version'] ?? '',
        'azure_endpoint' => ($main_provider_for_ai === 'Azure') ? ($provData['endpoint'] ?? '') : '',
        'azure_inference_version' => ($main_provider_for_ai === 'Azure') ? ($provData['api_version_inference'] ?? '2025-01-01-preview') : '',
        'azure_authoring_version' => ($main_provider_for_ai === 'Azure') ? ($provData['api_version_authoring'] ?? '2023-03-15-preview') : '',
    ];
    // Ollama doesn't require an API key, so skip validation for it
    if ($main_provider_for_ai !== 'Ollama' && empty($api_params_for_stream['api_key'])) {
        /* translators: %s: The name of the AI provider (e.g., OpenAI, Google). */
        return new WP_Error('missing_api_key', sprintf(__('API key missing for %s.', 'gpt3-ai-content-generator'), $main_provider_for_ai), ['status' => 400]);
    }
    if ($main_provider_for_ai === 'Azure' && empty($api_params_for_stream['azure_endpoint'])) {
        return new WP_Error('missing_azure_endpoint', __('Azure endpoint is missing.', 'gpt3-ai-content-generator'), ['status' => 400]);
    }

    // The history for the API call should include the current user message
    $final_history_for_api_call = $history_for_stateful_check;
    if (!empty($final_user_message_for_ai)) {
        $final_history_for_api_call[] = ['role' => 'user', 'content' => $final_user_message_for_ai];
    }

    return [
        'provider'                      => $main_provider_for_ai,
        'model'                         => $model_id_for_ai,
        'user_message'                  => $final_user_message_for_ai, // Still needed for some strategy formatters
        'history'                       => $final_history_for_api_call,
        'system_instruction_filtered'   => $instructions_filtered_for_api,
        'api_params'                    => $api_params_for_stream,
        'ai_params'                     => $ai_params_for_payload,
        'vector_search_scores'          => $vector_search_scores, // Include captured vector search scores
    ];
}

/**
 * Assembles the final array expected by SSEStreamProcessor::start_stream.
 *
 * @param array $request_data_for_ai Data prepared by build_ai_request_data_for_stream_logic.
 * @param string $conversation_uuid The UUID of the conversation.
 * @param array $base_log_data Base data for logging.
 * @param string $bot_message_id The generated ID for the bot's message.
 * @param array|null $initial_trigger_reply_data Optional data for an initial reply from triggers.
 * @return array The final input array for SSEStreamProcessor.
 */
function construct_sse_processor_input_logic(
    array $request_data_for_ai,
    string $conversation_uuid,
    array $base_log_data,
    string $bot_message_id,
    ?array $initial_trigger_reply_data
): array {
    $return_data = [
        'provider'                      => $request_data_for_ai['provider'],
        'model'                         => $request_data_for_ai['model'],
        'user_message'                  => $request_data_for_ai['user_message'],
        'history'                       => $request_data_for_ai['history'],
        'system_instruction_filtered'   => $request_data_for_ai['system_instruction_filtered'],
        'api_params'                    => $request_data_for_ai['api_params'],
        'ai_params'                     => $request_data_for_ai['ai_params'],
        'vector_search_scores'          => $request_data_for_ai['vector_search_scores'] ?? [], // Include vector search scores
        'conversation_uuid'             => $conversation_uuid,
        'base_log_data'                 => $base_log_data, // This already includes bot_message_id for the upcoming bot reply
        'bot_message_id'                => $bot_message_id, // Explicitly pass it as well
    ];

    if ($initial_trigger_reply_data) {
        $return_data['initial_trigger_reply_data'] = $initial_trigger_reply_data;
    }
    return $return_data;
}

/** Keeps the unmodified request usable when no paid trigger changes it. */
function neutral_trigger_result_logic(array $context, array $history, ?string $message = null): array
{
    return [
        'status' => 'processed',
        'message_to_user' => null,
        'message_id' => null,
        'modified_context_data' => [
            'system_instruction' => $context['system_instruction_for_ai'],
            'user_message_text' => $message ?? $context['user_message_text'],
            'current_history' => $history,
        ],
        'stop_ai_processing' => false,
        'display_form_event_data' => null,
    ];
}
