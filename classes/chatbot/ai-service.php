<?php
/**
 * Non-streaming chatbot requests: dependencies, context, provider parameters and responses.
 */

namespace WPAICG\Chat\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Non-streaming chatbot request service.
 * Prepares chat-specific context (history, instructions) and uses the generic
 * AIPKit_AI_Caller service to interact with AI services for non-streaming responses.
 */
class AIService
{
    private $ai_caller;
    private $log_storage;
    private $vector_store_manager;
    private ?\WPAICG\Vector\PostProcessor\Pinecone\PineconePostProcessor $pinecone_post_processor;
    private ?\WPAICG\Vector\PostProcessor\Qdrant\QdrantPostProcessor $qdrant_post_processor;

    public function __construct()
    {
        AIService\constructor($this);
    }

    // --- Setters for constructor to set properties ---
    public function set_ai_caller(?\WPAICG\Core\AIPKit_AI_Caller $caller): void
    {
        $this->ai_caller = $caller;
    }
    public function set_log_storage(?\WPAICG\Chat\Storage\LogStorage $storage): void
    {
        $this->log_storage = $storage;
    }
    public function set_vector_store_manager(?\WPAICG\Vector\AIPKit_Vector_Store_Manager $manager): void
    {
        $this->vector_store_manager = $manager;
    }
    public function set_pinecone_post_processor(?\WPAICG\Vector\PostProcessor\Pinecone\PineconePostProcessor $processor): void
    {
        $this->pinecone_post_processor = $processor;
    }
    public function set_qdrant_post_processor(?\WPAICG\Vector\PostProcessor\Qdrant\QdrantPostProcessor $processor): void
    {
        $this->qdrant_post_processor = $processor;
    }
    // --- End Setters ---

    // --- Getters for logic files to access properties ---
    public function get_ai_caller(): ?\WPAICG\Core\AIPKit_AI_Caller
    {
        return $this->ai_caller;
    }
    public function get_vector_store_manager(): ?\WPAICG\Vector\AIPKit_Vector_Store_Manager
    {
        return $this->vector_store_manager;
    }
    public function get_log_storage(): ?\WPAICG\Chat\Storage\LogStorage
    {
        return $this->log_storage;
    }
    public function get_pinecone_post_processor(): ?\WPAICG\Vector\PostProcessor\Pinecone\PineconePostProcessor
    {
        return $this->pinecone_post_processor;
    }
    public function get_qdrant_post_processor(): ?\WPAICG\Vector\PostProcessor\Qdrant\QdrantPostProcessor
    {
        return $this->qdrant_post_processor;
    }
    // --- End Getters ---
    /**
     * @return mixed[]|\WP_Error
     */
    public function generate_response(
        string $user_message,
        array $bot_settings,
        array $history,
        int $post_id = 0,
        ?string $frontend_previous_openai_response_id = null,
        bool $frontend_openai_web_search_active = false,
        bool $frontend_google_search_grounding_active = false,
        ?array $image_inputs_for_service = null,
        ?string $frontend_active_openai_vs_id = null,
        ?string $frontend_active_pinecone_index_name = null,
        ?string $frontend_active_pinecone_namespace = null,
        ?string $frontend_active_qdrant_collection_name = null,
        ?string $frontend_active_qdrant_file_upload_context_id = null,
        ?string $frontend_active_chroma_collection_name = null,
        ?string $frontend_active_chroma_file_upload_context_id = null,
        ?string $frontend_active_claude_file_id = null,
        ?string $conversation_uuid = null
    ) {
        return AIService\generate_response(
            $this,
            $user_message,
            $bot_settings,
            $history,
            $post_id,
            $frontend_previous_openai_response_id,
            $frontend_openai_web_search_active,
            $frontend_google_search_grounding_active,
            $image_inputs_for_service,
            $frontend_active_openai_vs_id,
            $frontend_active_pinecone_index_name,
            $frontend_active_pinecone_namespace,
            $frontend_active_qdrant_collection_name,
            $frontend_active_qdrant_file_upload_context_id,
            $frontend_active_chroma_collection_name,
            $frontend_active_chroma_file_upload_context_id,
            $frontend_active_claude_file_id,
            $conversation_uuid
        );
    }

}

namespace WPAICG\Chat\Core\AIService;

use WP_Error;
use WPAICG\Core\AIPKit_AI_Caller;
use WPAICG\Chat\Storage\LogStorage;

/**
 * Logic for the AIService constructor.
 * Initializes dependencies.
 *
 * @param \WPAICG\Chat\Core\AIService $serviceInstance The instance of the AIService class.
 * @return void
 */
function constructor(\WPAICG\Chat\Core\AIService $serviceInstance): void {
    if (!class_exists(\WPAICG\Core\AIPKit_AI_Caller::class)) {
        // Set properties to null or handle error appropriately
        $serviceInstance->set_ai_caller(null);
        $serviceInstance->set_log_storage(null);
        $serviceInstance->set_vector_store_manager(null);
        $serviceInstance->set_pinecone_post_processor(null);
        $serviceInstance->set_qdrant_post_processor(null);
        return;
    }
    $serviceInstance->set_ai_caller(new AIPKit_AI_Caller());

    if (!class_exists(\WPAICG\Chat\Storage\LogStorage::class)) {
         $serviceInstance->set_log_storage(null);
    } else {
        $serviceInstance->set_log_storage(new LogStorage());
    }

    if (!class_exists(\WPAICG\Vector\AIPKit_Vector_Store_Manager::class)) {
        // Vector_Store_Manager is loaded by DependencyLoader, so a require_once here might be redundant
        // but we check to ensure it's available.
        $manager_path = WPAICG_PLUGIN_DIR . 'classes/knowledge-base/manager.php';
        if (file_exists($manager_path) && !class_exists(\WPAICG\Vector\AIPKit_Vector_Store_Manager::class)) { // Check if not already loaded
            require_once $manager_path;
        }
    }
    if (class_exists(\WPAICG\Vector\AIPKit_Vector_Store_Manager::class)) {
        $serviceInstance->set_vector_store_manager(new \WPAICG\Vector\AIPKit_Vector_Store_Manager());
    } else {
        $serviceInstance->set_vector_store_manager(null);
    }

    // Pinecone Post Processor
    if (class_exists(\WPAICG\Vector\PostProcessor\Pinecone\PineconePostProcessor::class)) {
        $serviceInstance->set_pinecone_post_processor(new \WPAICG\Vector\PostProcessor\Pinecone\PineconePostProcessor());
    } else {
        $serviceInstance->set_pinecone_post_processor(null);
    }

    // Qdrant Post Processor
    if (class_exists(\WPAICG\Vector\PostProcessor\Qdrant\QdrantPostProcessor::class)) {
        $serviceInstance->set_qdrant_post_processor(new \WPAICG\Vector\PostProcessor\Qdrant\QdrantPostProcessor());
    } else {
         $serviceInstance->set_qdrant_post_processor(null);
    }
}

/**
 * Logic for determining the AI provider and model for a chat interaction.
 *
 * @param \WPAICG\Chat\Core\AIService|null $serviceInstance The instance of the AIService class (can be null if called statically).
 * @param array $bot_settings Settings for the specific bot.
 * @return array ['provider' => string, 'model' => string]
 */
function determine_provider_model(?\WPAICG\Chat\Core\AIService $serviceInstance, array $bot_settings): array {
    $provider = !empty($bot_settings['provider']) ? $bot_settings['provider'] : null;
    $model = !empty($bot_settings['model']) ? $bot_settings['model'] : null;

    // A Cloud model is a billable saved choice; an unset choice must remain unset.
    if ($provider === 'AIPufferCloud') {
        return ['provider' => $provider, 'model' => $model ?: ''];
    }

    if (empty($provider) || empty($model)) {
        // Ensure AIPKit_Providers class is available
        if (!class_exists(\WPAICG\AIPKit_Providers::class)) {
            $providers_path = WPAICG_PLUGIN_DIR . 'classes/ai/settings.php';
            if (file_exists($providers_path)) {
                require_once $providers_path;
            } else {
                return ['provider' => 'OpenAI', 'model' => '']; // Fallback
            }
        }
        $allowed_providers = empty($provider) ? [] : [(string) $provider];
        $new_ai_selection = \WPAICG\AIPKit_Providers::get_new_text_generation_selection($allowed_providers, true);
        if (empty($provider)) {
            $provider = $new_ai_selection['provider'] ?? 'OpenAI';
        }
        if (empty($model)) {
            $model = $new_ai_selection['model'] ?? '';
        }
    }
    return ['provider' => $provider ?: 'OpenAI', 'model' => $model ?: ''];
}

/**
 * Orchestrates the generation of an AI response for the chat.
 *
 * @param \WPAICG\Chat\Core\AIService $serviceInstance The instance of the AIService class.
 * @param string $user_message The user's input message.
 * @param array  $bot_settings Settings for the specific bot.
 * @param array  $history The pre-fetched and limited conversation history.
 * @param int    $post_id The ID of the post/page where the chat is embedded.
 * @param string|null $frontend_previous_openai_response_id The last OpenAI response ID from frontend.
 * @param bool   $frontend_openai_web_search_active Flag for OpenAI web search.
 * @param bool   $frontend_google_search_grounding_active Flag for Google Search Grounding.
 * @param array|null $image_inputs_for_service Optional array of image data.
 * @param string|null $frontend_active_openai_vs_id Optional active OpenAI Vector Store ID from frontend.
 * @param string|null $frontend_active_pinecone_index_name Optional active Pinecone index name from frontend.
 * @param string|null $frontend_active_pinecone_namespace Optional active Pinecone namespace from frontend.
 * @param string|null $frontend_active_qdrant_collection_name Optional active Qdrant collection name from frontend.
 * @param string|null $frontend_active_qdrant_file_upload_context_id Optional active Qdrant file context ID from frontend.
 * @param string|null $frontend_active_chroma_collection_name Optional active Chroma collection name from frontend.
 * @param string|null $frontend_active_chroma_file_upload_context_id Optional active Chroma file context ID from frontend.
 * @param string|null $frontend_active_claude_file_id Optional active Claude file ID from frontend.
 * @param string|null $conversation_uuid Optional chatbot conversation UUID for provider routing affinity.
 * @return array|WP_Error Response data or WP_Error.
 */
function generate_response(
    \WPAICG\Chat\Core\AIService $serviceInstance,
    string $user_message,
    array $bot_settings,
    array $history,
    int $post_id = 0,
    ?string $frontend_previous_openai_response_id = null,
    bool $frontend_openai_web_search_active = false,
    bool $frontend_google_search_grounding_active = false,
    ?array $image_inputs_for_service = null,
    ?string $frontend_active_openai_vs_id = null,
    ?string $frontend_active_pinecone_index_name = null,
    ?string $frontend_active_pinecone_namespace = null,
    ?string $frontend_active_qdrant_collection_name = null,
    ?string $frontend_active_qdrant_file_upload_context_id = null,
    ?string $frontend_active_chroma_collection_name = null,
    ?string $frontend_active_chroma_file_upload_context_id = null,
    ?string $frontend_active_claude_file_id = null,
    ?string $conversation_uuid = null
) {
    $ai_caller = $serviceInstance->get_ai_caller();
    $vector_store_manager = $serviceInstance->get_vector_store_manager(); // Get Vector Store Manager

    $validation_result = GenerateResponse\validate_request_logic($ai_caller, $user_message, $image_inputs_for_service, $bot_settings);
    if (is_wp_error($validation_result)) {
        return $validation_result;
    }

    $provider_info = determine_provider_model($serviceInstance, $bot_settings);
    $main_provider = $provider_info['provider'];
    $model = $provider_info['model'];
    $feature_strategy = \WPAICG\Core\Providers\ProviderStrategyFactory::get_strategy($main_provider);
    if (is_wp_error($feature_strategy)) { return $feature_strategy; }
    $feature_error = $feature_strategy->validate_chatbot_features($bot_settings, !empty($image_inputs_for_service), $frontend_openai_web_search_active || $frontend_google_search_grounding_active);
    if ($feature_error) { return $feature_error; }
    if (empty($model)) {
        return new WP_Error('missing_model_orchestrator', __('Chatbot AI Model or Deployment Name is missing in settings.', 'gpt3-ai-content-generator'));
    }

    if (!empty($image_inputs_for_service)) {
        /**
         * Allow provider-specific image capability validation for chat requests.
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
            $image_inputs_for_service,
            $main_provider,
            $model,
            $bot_settings,
            'non_stream'
        );
        if (is_wp_error($image_validation_error)) {
            return $image_validation_error;
        }
    }

    $im_load_result = GenerateResponse\load_instruction_manager_logic();
    if (is_wp_error($im_load_result)) {
        return $im_load_result;
    }

    $vector_search_scores = []; // Initialize array to capture vector search scores
    $all_formatted_results_for_instruction = GenerateResponse\prepare_vector_search_context_logic(
        $ai_caller, // Pass AI Caller
        $vector_store_manager, // Pass Vector Store Manager
        $user_message,
        $bot_settings,
        $main_provider,
        $frontend_active_openai_vs_id,
        $frontend_active_pinecone_index_name,
        $frontend_active_pinecone_namespace,
        $frontend_active_qdrant_collection_name,
        $frontend_active_qdrant_file_upload_context_id,
        $frontend_active_chroma_collection_name,
        $frontend_active_chroma_file_upload_context_id,
        $vector_search_scores // Pass reference to capture scores
    );

    if (is_wp_error($all_formatted_results_for_instruction)) { return $all_formatted_results_for_instruction; }

    $base_instructions = $bot_settings['instructions'] ?? '';
    $instruction_context_for_logging = [
        'bot_settings' => $bot_settings,
        'post_id' => $post_id,
        'vector_search_results' => $all_formatted_results_for_instruction,
        'vector_search_scores' => $vector_search_scores // Add vector search scores for logging
    ];
    $instructions_processed = GenerateResponse\build_final_system_instruction_logic($bot_settings, $post_id, $base_instructions, $all_formatted_results_for_instruction);

    $messages_prep_result = GenerateResponse\prepare_messages_for_api_logic($history, $user_message);
    $messages_payload = $messages_prep_result['messages_payload'];
    $last_openai_response_id_from_history = $messages_prep_result['last_openai_response_id_from_history'];

    $ai_params_override_from_config = [];
    if (isset($bot_settings['temperature'])) {
        $ai_params_override_from_config['temperature'] = floatval($bot_settings['temperature']);
    }
    if (isset($bot_settings['max_completion_tokens'])) {
        $ai_params_override_from_config['max_completion_tokens'] = absint($bot_settings['max_completion_tokens']);
    }
    if (!empty($image_inputs_for_service)) {
        $ai_params_override_from_config['image_inputs'] = $image_inputs_for_service;
    }

    $final_ai_params_result = GenerateResponse\prepare_final_ai_params_logic(
        $ai_params_override_from_config,
        $bot_settings,
        $main_provider,
        $model,
        $frontend_previous_openai_response_id,
        $last_openai_response_id_from_history,
        $messages_payload,
        $frontend_openai_web_search_active,
        $frontend_google_search_grounding_active,
        $frontend_active_openai_vs_id,
        $frontend_active_claude_file_id
    );
    $final_ai_params = $final_ai_params_result['final_ai_params'];
    $actual_previous_response_id_to_use = $final_ai_params_result['actual_previous_response_id_to_use'];

    $openrouter_conversation_uuid = sanitize_key((string) $conversation_uuid);
    $openrouter_bot_id = absint($bot_settings['bot_id'] ?? 0);
    $openrouter_session_stickiness = ($bot_settings['openrouter_session_stickiness'] ?? '0') === '1';
    if (
        $main_provider === 'OpenRouter'
        && $openrouter_session_stickiness
        && $openrouter_bot_id > 0
        && $openrouter_conversation_uuid !== ''
    ) {
        $final_ai_params['openrouter_session_context'] = [
            'bot_id' => $openrouter_bot_id,
            'conversation_uuid' => $openrouter_conversation_uuid,
        ];
    }

    $ai_call_result = GenerateResponse\execute_ai_call_logic(
        $ai_caller,
        $main_provider,
        $model,
        $messages_payload,
        $final_ai_params,
        $instructions_processed,
        $instruction_context_for_logging
    );

    if (is_wp_error($ai_call_result)) {
        $triggers_enabled = false;
        if (class_exists('\WPAICG\aipkit_dashboard')) {
            $triggers_enabled = \WPAICG\aipkit_dashboard::is_pro_plan();
        }
        if (function_exists(__NAMESPACE__ . '\\GenerateResponse\\handle_ai_call_error_logic')) {
            GenerateResponse\handle_ai_call_error_logic(
                $ai_call_result,
                $triggers_enabled,
                $serviceInstance->get_log_storage(),
                [],
                $main_provider,
                $model,
                $bot_settings['bot_id'] ?? 0
            );
        }
        return $ai_call_result;
    }

    return GenerateResponse\finalize_ai_response_logic(
        $ai_call_result,
        $main_provider,
        $model,
        $history,
        $base_instructions,
        $final_ai_params,
        $actual_previous_response_id_to_use
    );
}

namespace WPAICG\Chat\Core\AIService\GenerateResponse;

use WP_Error;
use WPAICG\AIPKit_Providers;
use WPAICG\Chat\Storage\BotSettingsManager;

/**
 * Validates the initial request parameters for generate_response.
 *
 * @param \WPAICG\Core\AIPKit_AI_Caller|null $ai_caller Instance of AI Caller.
 * @param string $user_message The user's input message.
 * @param array|null $image_inputs_for_service Optional array of image data.
 * @param array $bot_settings Settings for the specific bot.
 * @return true|WP_Error True if valid, WP_Error otherwise.
 */
function validate_request_logic(
    ?\WPAICG\Core\AIPKit_AI_Caller $ai_caller,
    string $user_message,
    ?array $image_inputs_for_service,
    array $bot_settings
) {
    if (!$ai_caller) {
        return new WP_Error('ai_caller_missing_validation', 'AI Caller component is not available for request validation.');
    }
    if (empty($user_message) && empty($image_inputs_for_service)) {
        return new WP_Error('empty_content_validation', __('User message or image cannot be empty.', 'gpt3-ai-content-generator'));
    }
    if (empty($bot_settings['bot_id'])) {
        return new WP_Error('missing_bot_id_validation', __('Bot ID is missing in settings for request validation.', 'gpt3-ai-content-generator'));
    }
    return true;
}

/**
 * Ensures the AIPKit_Instruction_Manager class is loaded.
 *
 * @return true|WP_Error True if loaded, WP_Error otherwise.
 */
function load_instruction_manager_logic()
{
    if (!class_exists(\WPAICG\Core\AIPKit_Instruction_Manager::class)) {
        $manager_path = WPAICG_PLUGIN_DIR . 'classes/ai/instructions.php';
        if (file_exists($manager_path)) {
            require_once $manager_path;
        } else {
            return new WP_Error('internal_error_im_load', 'Instruction processing component missing (load logic).');
        }
    }
    if (!class_exists(\WPAICG\Core\AIPKit_Instruction_Manager::class)) { // Double check after require_once
        return new WP_Error('internal_error_im_not_loaded', 'InstructionManager class still not available after attempting load.');
    }
    return true;
}

/**
 * Prepares the vector search context for non-streaming chat interactions.
 *
 * This function serves as a wrapper for the centralized vector context building logic,
 * specifically for use in non-streaming chat scenarios.
 *
 * @param \WPAICG\Core\AIPKit_AI_Caller|null $ai_caller Instance of AI Caller, or null.
 * @param \WPAICG\Vector\AIPKit_Vector_Store_Manager|null $vector_store_manager Instance of Vector Store Manager, or null.
 * @param string $user_message The user's current message.
 * @param array  $bot_settings The settings of the current bot.
 * @param string $main_provider The main AI provider being used for the chat.
 * @param string|null $frontend_active_openai_vs_id Optional active OpenAI Vector Store ID from frontend.
 * @param string|null $frontend_active_pinecone_index_name Optional active Pinecone index name from frontend.
 * @param string|null $frontend_active_pinecone_namespace Optional active Pinecone namespace from frontend.
 * @param string|null $frontend_active_qdrant_collection_name Optional active Qdrant collection name.
 * @param string|null $frontend_active_qdrant_file_upload_context_id Optional active Qdrant file context ID.
 * @param string|null $frontend_active_chroma_collection_name Optional active Chroma collection name.
 * @param string|null $frontend_active_chroma_file_upload_context_id Optional active Chroma file context ID.
 * @param array|null &$vector_search_scores_output Optional reference to capture vector search scores for logging.
 * @return string|WP_Error Context text, an empty result, or the retrieval error.
 */
function prepare_vector_search_context_logic(
    ?\WPAICG\Core\AIPKit_AI_Caller $ai_caller,
    ?\WPAICG\Vector\AIPKit_Vector_Store_Manager $vector_store_manager,
    string $user_message,
    array $bot_settings,
    string $main_provider,
    ?string $frontend_active_openai_vs_id = null,
    ?string $frontend_active_pinecone_index_name = null,
    ?string $frontend_active_pinecone_namespace = null,
    ?string $frontend_active_qdrant_collection_name = null,
    ?string $frontend_active_qdrant_file_upload_context_id = null,
    ?string $frontend_active_chroma_collection_name = null,
    ?string $frontend_active_chroma_file_upload_context_id = null,
    ?array &$vector_search_scores_output = null
) {
    if (!$ai_caller || !$vector_store_manager) {
        return "";
    }

    // Call the centralized logic function
    return \WPAICG\Core\Stream\Vector\build_vector_search_context_logic(
        $ai_caller,
        $vector_store_manager,
        $user_message,
        $bot_settings,
        $main_provider,
        $frontend_active_openai_vs_id,
        $frontend_active_pinecone_index_name,
        $frontend_active_pinecone_namespace,
        $frontend_active_qdrant_collection_name,
        $frontend_active_qdrant_file_upload_context_id,
        $frontend_active_chroma_collection_name,
        $frontend_active_chroma_file_upload_context_id,
        $vector_search_scores_output
    );
}

/**
 * Builds the final system instruction string using AIPKit_Instruction_Manager.
 *
 * @param array $bot_settings Settings of the specific bot.
 * @param int $post_id ID of the current post.
 * @param string $base_instructions The user-defined base instructions.
 * @param string $all_formatted_results_for_instruction Pre-formatted string of vector search results.
 * @return string The fully constructed system instruction string.
 */
function build_final_system_instruction_logic(
    array $bot_settings,
    int $post_id,
    string $base_instructions,
    string $all_formatted_results_for_instruction
): string {
    $instruction_context = [
        'base_instructions' => $base_instructions,
        'bot_settings' => $bot_settings,
        'post_id' => $post_id
    ];
    if (!empty($all_formatted_results_for_instruction)) {
        $instruction_context['vector_search_results'] = trim($all_formatted_results_for_instruction);
    }
    return \WPAICG\Core\AIPKit_Instruction_Manager::build_instructions($instruction_context);
}

/**
 * Prepares the messages array for the API call and extracts relevant info for stateful OpenAI.
 *
 * @param array $history Conversation history.
 * @param string $user_message_text The latest user message.
 * @return array Contains 'messages_payload', 'latest_user_message_obj_for_stateful', 'last_openai_response_id_from_history'.
 */
function prepare_messages_for_api_logic(array $history, string $user_message_text): array
{
    $messages_payload = [];
    $latest_user_message_obj_for_stateful = null;
    $last_openai_response_id_from_history = null;

    foreach ($history as $msg) {
        $role = ($msg['role'] === 'bot') ? 'assistant' : $msg['role'];
        $content = isset($msg['content']) ? trim($msg['content']) : '';
        if ($content !== '' && in_array($role, ['system', 'user', 'assistant'])) {
            $messages_payload[] = ['role' => $role, 'content' => $content];
            if ($role === 'user' && $msg['content'] === $user_message_text) { // Assuming history includes the current user message for this logic
                $latest_user_message_obj_for_stateful = ['role' => 'user', 'content' => $content];
            }
        }
        if (($role === 'assistant' || $role === 'bot') && isset($msg['openai_response_id']) && !empty($msg['openai_response_id'])) {
            $last_openai_response_id_from_history = $msg['openai_response_id'];
        }
    }
    // History contains previous turns; append the current user message.
    if (!empty($user_message_text)) {
        $messages_payload[] = ['role' => 'user', 'content' => $user_message_text];
        $latest_user_message_obj_for_stateful = ['role' => 'user', 'content' => $user_message_text];
    }

    return [
        'messages_payload' => $messages_payload,
        'latest_user_message_obj_for_stateful' => $latest_user_message_obj_for_stateful,
        'last_openai_response_id_from_history' => $last_openai_response_id_from_history
    ];
}

/**
 * Prepares the final AI parameters, including provider-specific adjustments.
 * Orchestrates calls to sub-module logic functions.
 *
 * @param array $ai_params_override Initial AI parameter overrides from bot settings or image inputs.
 * @param array $bot_settings Bot settings.
 * @param string $main_provider The main AI provider.
 * @param string $model The selected AI model.
 * @param string|null $frontend_previous_openai_response_id Previous OpenAI response ID from frontend.
 * @param string|null $last_openai_response_id_from_history Last OpenAI response ID from history.
 * @param array &$messages_payload_ref Reference to the messages payload (can be modified for OpenAI stateful).
 * @param bool $frontend_openai_web_search_active Flag for OpenAI web search.
 * @param bool $frontend_google_search_grounding_active Flag for Google Search Grounding.
 * @param string|null $frontend_active_openai_vs_id Active OpenAI Vector Store ID.
 * @param string|null $frontend_active_claude_file_id Active Claude file ID.
 * @return array ['final_ai_params' => array, 'actual_previous_response_id_to_use' => string|null]
 */
function prepare_final_ai_params_logic(
    array $ai_params_override,
    array $bot_settings,
    string $main_provider,
    string $model,
    ?string $frontend_previous_openai_response_id,
    ?string $last_openai_response_id_from_history,
    array &$messages_payload_ref, // Pass by reference
    bool $frontend_openai_web_search_active,
    bool $frontend_google_search_grounding_active,
    ?string $frontend_active_openai_vs_id,
    ?string $frontend_active_claude_file_id
): array {
    // Ensure dependencies are loaded (already handled in original file, repeated here for safety if this file were called standalone)
    if (!class_exists(AIPKit_Providers::class)) {
        $path = WPAICG_PLUGIN_DIR . 'classes/ai/settings.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }
    if (!class_exists(BotSettingsManager::class)) {
        $path = WPAICG_PLUGIN_DIR . 'classes/chatbot/bots.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }

    $final_ai_params = $ai_params_override; // Start with overrides (temperature, max_tokens, image_inputs)
    $actual_previous_response_id_to_use = null;

    if ($main_provider === 'OpenAI') {
        $actual_previous_response_id_to_use = AiParams\apply_openai_stateful_conversation_logic(
            $final_ai_params,
            $messages_payload_ref, // Pass by reference
            $bot_settings,
            $frontend_previous_openai_response_id,
            $last_openai_response_id_from_history
        );

        // Get vector store IDs from bot settings
        $vector_store_ids_to_use_for_tool = $bot_settings['openai_vector_store_ids'] ?? [];
        if ($frontend_active_openai_vs_id && !in_array($frontend_active_openai_vs_id, $vector_store_ids_to_use_for_tool, true)) {
            $vector_store_ids_to_use_for_tool[] = $frontend_active_openai_vs_id;
        }

        AiParams\apply_openai_vector_tool_config_logic(
            $final_ai_params,
            $bot_settings,
            $vector_store_ids_to_use_for_tool,
            null // ai_service not needed for this function
        );
        AiParams\apply_openai_web_search_logic(
            $final_ai_params,
            $bot_settings,
            $frontend_openai_web_search_active
        );
        AiParams\apply_openai_reasoning_logic(
            $final_ai_params,
            $bot_settings,
            $model
        );
    } elseif ($main_provider === 'Claude') {
        AiParams\apply_claude_web_search_logic(
            $final_ai_params,
            $bot_settings,
            $frontend_openai_web_search_active
        );
        if (function_exists(__NAMESPACE__ . '\\AiParams\\apply_claude_file_context_logic')) {
            AiParams\apply_claude_file_context_logic($final_ai_params, $bot_settings, $frontend_active_claude_file_id);
        }
    } elseif ($main_provider === 'AIPufferCloud') {
        $reasoning_effort = \WPAICG\Cloud\Connection::reasoning_effort((string) $model, $bot_settings['reasoning_effort'] ?? '');
        if ($reasoning_effort !== '') {
            $final_ai_params['reasoning'] = ['effort' => $reasoning_effort];
        }
    } elseif ($main_provider === 'OpenRouter') {
        AiParams\apply_openrouter_web_search_logic(
            $final_ai_params,
            $bot_settings,
            $frontend_openai_web_search_active
        );
        AiParams\apply_openrouter_reasoning_logic(
            $final_ai_params,
            $bot_settings,
            $model
        );
    } elseif ($main_provider === 'xAI') {
        AiParams\apply_xai_web_search_logic(
            $final_ai_params,
            $bot_settings,
            $frontend_openai_web_search_active
        );
    } elseif ($main_provider === 'Google') {
        AiParams\apply_google_file_search_tool_config_logic(
            $final_ai_params,
            $bot_settings
        );
        AiParams\apply_google_search_grounding_logic(
            $final_ai_params,
            $bot_settings,
            $frontend_google_search_grounding_active
        );
    } elseif ($main_provider === 'Ollama' && function_exists(__NAMESPACE__ . '\\AiParams\\apply_ollama_thinking_logic')) {
        AiParams\apply_ollama_thinking_logic(
            $final_ai_params,
            $bot_settings
        );
    }

    return [
        'final_ai_params' => $final_ai_params,
        'actual_previous_response_id_to_use' => $actual_previous_response_id_to_use
    ];
}

/**
 * Executes the AI call using AIPKit_AI_Caller.
 *
 * @param \WPAICG\Core\AIPKit_AI_Caller $ai_caller Instance of AI Caller.
 * @param string $main_provider The main AI provider.
 * @param string $model The selected AI model.
 * @param array $messages_payload The prepared messages payload for the API.
 * @param array $final_ai_params The final AI parameters.
 * @param string $instructions_processed The processed system instruction.
 * @param array $instruction_context_for_logging Context used for instruction building (for logging).
 * @return array|WP_Error The result from AI Caller.
 */
function execute_ai_call_logic(
    \WPAICG\Core\AIPKit_AI_Caller $ai_caller,
    string $main_provider,
    string $model,
    array $messages_payload,
    array $final_ai_params,
    string $instructions_processed,
    array $instruction_context_for_logging
) {
    return $ai_caller->make_standard_call(
        $main_provider,
        $model,
        $messages_payload,
        $final_ai_params,
        $instructions_processed,
        $instruction_context_for_logging // Pass context for logging
    );
}

/**
 * Applies final filters to the successful AI response and prepares the return structure.
 *
 * @param array $ai_call_success_result The successful result from AI Caller.
 * @param string $main_provider The main AI provider.
 * @param string $model The selected AI model.
 * @param array $history Conversation history (used by the filter).
 * @param string $base_instructions Base system instructions (used by the filter).
 * @param array $final_ai_params Final AI parameters (used by the filter).
 * @param string|null $actual_previous_response_id_to_use OpenAI stateful ID, if used.
 * @return array The final response structure.
 */
function finalize_ai_response_logic(
    array $ai_call_success_result,
    string $main_provider,
    string $model,
    array $history,
    string $base_instructions,
    array $final_ai_params,
    ?string $actual_previous_response_id_to_use
): array {
    $final_instructions_for_filter = $ai_call_success_result['request_payload_log']['system_instruction'] ?? $base_instructions;

    $ai_call_success_result['content'] = apply_filters(
        'aipkit_ai_response',
        $ai_call_success_result['content'],
        null, // Stream type is null for non-streaming
        $main_provider,
        $model,
        $history,
        $final_instructions_for_filter,
        null, // SSE chunk data (null here)
        $final_ai_params
    );

    if ($actual_previous_response_id_to_use !== null && ($main_provider === 'OpenAI' && ($final_ai_params['use_openai_conversation_state'] ?? false))) {
        $ai_call_success_result['used_previous_response_id'] = true;
    }

    return $ai_call_success_result;
}

namespace WPAICG\Chat\Core\AIService\GenerateResponse\AiParams;

use WPAICG\Chat\Storage\BotSettingsManager;
use WPAICG\Core\AIPKit_OpenAI_Reasoning;
use WPAICG\Core\AIPKit_OpenRouter_Reasoning;

/**
 * Applies OpenAI stateful conversation parameters.
 *
 * @param array &$final_ai_params Reference to the final AI parameters array to be modified.
 * @param array &$messages_payload_ref Reference to the messages payload array (can be modified).
 * @param array $bot_settings Bot settings.
 * @param string|null $frontend_previous_openai_response_id Previous OpenAI response ID from frontend.
 * @param string|null $last_openai_response_id_from_history Last OpenAI response ID from history.
 * @return string|null The actual previous response ID used, or null.
 */
function apply_openai_stateful_conversation_logic(
    array &$final_ai_params,
    array &$messages_payload_ref,
    array $bot_settings,
    ?string $frontend_previous_openai_response_id,
    ?string $last_openai_response_id_from_history
): ?string {
    $actual_previous_response_id_to_use = null;
    $use_openai_conv_state = ($bot_settings['openai_conversation_state_enabled'] ?? '0') === '1';

    if ($use_openai_conv_state) {
        $final_ai_params['use_openai_conversation_state'] = true;
        if (!empty($frontend_previous_openai_response_id)) {
            $actual_previous_response_id_to_use = $frontend_previous_openai_response_id;
        } elseif (!empty($last_openai_response_id_from_history)) {
            $actual_previous_response_id_to_use = $last_openai_response_id_from_history;
        }

        if ($actual_previous_response_id_to_use !== null) {
            $final_ai_params['previous_response_id'] = $actual_previous_response_id_to_use;
            $latest_user_message_obj = end($messages_payload_ref);
            if ($latest_user_message_obj && ($latest_user_message_obj['role'] === 'user')) {
                $messages_payload_ref = [$latest_user_message_obj];
            }
        }
    }
    return $actual_previous_response_id_to_use;
}

/**
 * Applies OpenAI Vector Store tool configuration to AI parameters.
 *
 * @param array &$final_ai_params Reference to the final AI parameters array to be modified.
 * @param array $bot_settings Bot settings.
 */
function apply_openai_vector_tool_config_logic(&$final_ai_params, $bot_settings, $vector_store_ids_to_use_for_tool, $ai_service)
{
    // Vector store IDs should be prepared by the caller; just normalize here
    $vector_store_ids_to_use_for_tool = array_unique(array_filter($vector_store_ids_to_use_for_tool));
    $vector_top_k_openai = absint($bot_settings['vector_store_top_k'] ?? 3);
    $vector_top_k_openai = max(1, min($vector_top_k_openai, 20));

    if (($bot_settings['enable_vector_store'] ?? '0') === '1' &&
        ($bot_settings['vector_store_provider'] ?? '') === 'openai' &&
        !empty($vector_store_ids_to_use_for_tool)) {

        // Convert confidence threshold percentage (0-100) to OpenAI score threshold (0.0-1.0)
        // OpenAI expects ranking_options.score_threshold in the file_search tool for server-side filtering
        $confidence_threshold_percent = (int)($bot_settings['vector_store_confidence_threshold'] ?? 20);
        // Convert to 0.0-1.0 scale and round to fixed 6 decimals, with exact endpoints for 0 and 100
        if ($confidence_threshold_percent <= 0) {
            $openai_score_threshold = 0.0;
        } elseif ($confidence_threshold_percent >= 100) {
            $openai_score_threshold = 1.0;
        } else {
            $openai_score_threshold = round($confidence_threshold_percent / 100, 6);
        }

        $final_ai_params['vector_store_tool_config'] = [
            'type'             => 'file_search',
            'vector_store_ids' => $vector_store_ids_to_use_for_tool,
            'max_num_results'  => $vector_top_k_openai,
            'ranking_options'  => [
                'score_threshold' => $openai_score_threshold
            ]
        ];
    }
}

/**
 * Applies Google File Search tool configuration to AI parameters.
 *
 * @param array<string, mixed> $final_ai_params
 * @param array<string, mixed> $bot_settings
 */
function apply_google_file_search_tool_config_logic(array &$final_ai_params, array $bot_settings): void
{
    if (
        ($bot_settings['enable_vector_store'] ?? '0') !== '1'
        || ($bot_settings['vector_store_provider'] ?? '') !== 'google'
    ) {
        return;
    }

    $store_names = isset($bot_settings['google_file_search_store_names'])
        && is_array($bot_settings['google_file_search_store_names'])
        ? $bot_settings['google_file_search_store_names']
        : [];
    $store_names = array_values(array_unique(array_filter(array_map('sanitize_text_field', $store_names))));
    if (empty($store_names)) {
        return;
    }

    $top_k = absint($bot_settings['vector_store_top_k'] ?? 3);
    $final_ai_params['google_file_search_tool_config'] = [
        'file_search_store_names' => $store_names,
        'top_k' => max(1, min($top_k, 20)),
    ];
}

/**
 * Applies OpenAI Web Search tool configuration to AI parameters.
 *
 * @param array &$final_ai_params Reference to the final AI parameters array to be modified.
 * @param array $bot_settings Bot settings.
 * @param bool $frontend_openai_web_search_active Flag for OpenAI web search.
 */
function apply_openai_web_search_logic(
    array &$final_ai_params,
    array $bot_settings,
    bool $frontend_openai_web_search_active
): void {
    // Ensure BotSettingsManager constants are available
    if (!class_exists(BotSettingsManager::class)) {
        $bsm_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/bots.php';
        if (file_exists($bsm_path)) {
            require_once $bsm_path;
        } else {
            return;
        }
    }

    $bot_allows_openai_web_search = (isset($bot_settings['openai_web_search_enabled']) && $bot_settings['openai_web_search_enabled'] === '1');

    if ($bot_allows_openai_web_search) {
        $final_ai_params['web_search_tool_config'] = [
            'enabled' => true,
            'search_context_size' => $bot_settings['openai_web_search_context_size'] ?? BotSettingsManager::DEFAULT_OPENAI_WEB_SEARCH_CONTEXT_SIZE,
        ];
        if (($bot_settings['openai_web_search_loc_type'] ?? 'none') === 'approximate') {
            $user_location = array_filter([
                'country' => $bot_settings['openai_web_search_loc_country'] ?? null,
                'city' => $bot_settings['openai_web_search_loc_city'] ?? null,
                'region' => $bot_settings['openai_web_search_loc_region'] ?? null,
                'timezone' => $bot_settings['openai_web_search_loc_timezone'] ?? null
            ]);
            if (!empty($user_location)) {
                $final_ai_params['web_search_tool_config']['user_location'] = $user_location;
            }
        }
        $final_ai_params['frontend_web_search_active'] = $frontend_openai_web_search_active;
    }
}

/**
 * Applies Claude Web Search tool configuration to AI parameters.
 *
 * @param array &$final_ai_params Reference to the final AI parameters array to be modified.
 * @param array $bot_settings Bot settings.
 * @param bool $frontend_web_search_active Flag for frontend web search toggle.
 */
function apply_claude_web_search_logic(
    array &$final_ai_params,
    array $bot_settings,
    bool $frontend_web_search_active
): void {
    if (!class_exists(BotSettingsManager::class)) {
        $bsm_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/bots.php';
        if (file_exists($bsm_path)) {
            require_once $bsm_path;
        } else {
            return;
        }
    }

    $bot_allows_claude_web_search = (isset($bot_settings['claude_web_search_enabled']) && $bot_settings['claude_web_search_enabled'] === '1');
    if (!$bot_allows_claude_web_search) {
        return;
    }

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

    $web_search_config = [
        'enabled' => true,
        'type' => 'web_search_20250305',
    ];

    $max_uses = isset($bot_settings['claude_web_search_max_uses'])
        ? absint($bot_settings['claude_web_search_max_uses'])
        : BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_MAX_USES;
    $web_search_config['max_uses'] = max(1, min($max_uses, 20));

    $allowed_domains = $split_domains($bot_settings['claude_web_search_allowed_domains'] ?? '');
    $blocked_domains = $split_domains($bot_settings['claude_web_search_blocked_domains'] ?? '');
    if (!empty($allowed_domains)) {
        $web_search_config['allowed_domains'] = $allowed_domains;
    } elseif (!empty($blocked_domains)) {
        $web_search_config['blocked_domains'] = $blocked_domains;
    }

    if (($bot_settings['claude_web_search_loc_type'] ?? 'none') === 'approximate') {
        $user_location = array_filter([
            'country' => $bot_settings['claude_web_search_loc_country'] ?? null,
            'city' => $bot_settings['claude_web_search_loc_city'] ?? null,
            'region' => $bot_settings['claude_web_search_loc_region'] ?? null,
            'timezone' => $bot_settings['claude_web_search_loc_timezone'] ?? null,
        ]);
        if (!empty($user_location)) {
            $user_location['type'] = 'approximate';
            $web_search_config['user_location'] = $user_location;
        }
    }

    $cache_ttl = $bot_settings['claude_web_search_cache_ttl'] ?? BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_CACHE_TTL;
    if (in_array($cache_ttl, ['5m', '1h'], true)) {
        $web_search_config['cache_control'] = [
            'type' => 'ephemeral',
            'ttl' => $cache_ttl,
        ];
    }

    $final_ai_params['web_search_tool_config'] = $web_search_config;
    $final_ai_params['frontend_web_search_active'] = $frontend_web_search_active;
}

/**
 * Applies OpenRouter web search server-tool configuration to AI parameters.
 *
 * @param array &$final_ai_params Reference to the final AI parameters array to be modified.
 * @param array $bot_settings Bot settings.
 * @param bool $frontend_web_search_active Flag for frontend web search toggle.
 */
function apply_openrouter_web_search_logic(
    array &$final_ai_params,
    array $bot_settings,
    bool $frontend_web_search_active
): void {
    if (!class_exists(BotSettingsManager::class)) {
        $bsm_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/bots.php';
        if (file_exists($bsm_path)) {
            require_once $bsm_path;
        } else {
            return;
        }
    }

    $bot_allows_openrouter_web_search = (isset($bot_settings['openrouter_web_search_enabled']) && $bot_settings['openrouter_web_search_enabled'] === '1');
    if (!$bot_allows_openrouter_web_search) {
        return;
    }

    $web_search_config = [
        'enabled' => true,
    ];

    $engine = isset($bot_settings['openrouter_web_search_engine']) ? sanitize_key((string) $bot_settings['openrouter_web_search_engine']) : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_ENGINE;
    if (in_array($engine, ['native', 'exa', 'firecrawl', 'parallel', 'perplexity'], true)) {
        $web_search_config['engine'] = $engine;
    }

    $max_results = isset($bot_settings['openrouter_web_search_max_results'])
        ? absint($bot_settings['openrouter_web_search_max_results'])
        : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_MAX_RESULTS;
    $web_search_config['max_results'] = max(1, min($max_results, 25));
    $max_uses = isset($bot_settings['openrouter_web_search_max_uses'])
        ? absint($bot_settings['openrouter_web_search_max_uses'])
        : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_MAX_USES;
    $web_search_config['max_uses'] = max(1, min($max_uses, 10));
    $max_total_results = isset($bot_settings['openrouter_web_search_max_total_results'])
        ? absint($bot_settings['openrouter_web_search_max_total_results'])
        : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_MAX_TOTAL_RESULTS;
    $web_search_config['max_total_results'] = max(1, min($max_total_results, 100));

    $context_size = isset($bot_settings['openrouter_web_search_context_size'])
        ? sanitize_key((string) $bot_settings['openrouter_web_search_context_size'])
        : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_CONTEXT_SIZE;
    if (in_array($context_size, ['low', 'medium', 'high'], true)) {
        $web_search_config['search_context_size'] = $context_size;
    }
    $web_search_config['allowed_domains'] = $bot_settings['openrouter_web_search_allowed_domains'] ?? '';
    $web_search_config['excluded_domains'] = $bot_settings['openrouter_web_search_excluded_domains'] ?? '';

    $final_ai_params['web_search_tool_config'] = $web_search_config;
    $final_ai_params['frontend_web_search_active'] = $frontend_web_search_active;
}

/**
 * Applies xAI Responses API web search parameters.
 *
 * @param array<string, mixed> $final_ai_params
 * @param array<string, mixed> $bot_settings
 * @param bool $frontend_web_search_active
 */
function apply_xai_web_search_logic(array &$final_ai_params, array $bot_settings, bool $frontend_web_search_active): void
{
    if (($bot_settings['xai_web_search_enabled'] ?? '0') !== '1') {
        return;
    }

    $final_ai_params['xai_web_search_tool_config'] = ['enabled' => true];
    $final_ai_params['frontend_web_search_active'] = $frontend_web_search_active;
}

/**
 * Applies Google Search Grounding parameters.
 *
 * @param array &$final_ai_params Reference to the final AI parameters array to be modified.
 * @param array $bot_settings Bot settings.
 * @param bool $frontend_google_search_grounding_active Flag for Google Search Grounding.
 */
function apply_google_search_grounding_logic(
    array &$final_ai_params,
    array $bot_settings,
    bool $frontend_google_search_grounding_active
): void {
    $bot_allows_google_grounding = (isset($bot_settings['google_search_grounding_enabled']) && $bot_settings['google_search_grounding_enabled'] === '1');

    $knowledge_state = BotSettingsManager::get_knowledge_capability_state(
        (string) ($bot_settings['provider'] ?? 'Google'),
        (string) ($bot_settings['vector_store_provider'] ?? ''),
        (string) ($bot_settings['enable_vector_store'] ?? '0'),
        $bot_allows_google_grounding ? '1' : '0'
    );

    if ($bot_allows_google_grounding && !$knowledge_state['google_search_conflict']) {
        $final_ai_params['frontend_google_search_grounding_active'] = $frontend_google_search_grounding_active;
    }
}

/**
 * Applies OpenAI Reasoning parameters if the model is compatible.
 *
 * @param array &$final_ai_params Reference to the final AI parameters array to be modified.
 * @param array $bot_settings Bot settings.
 * @param string $model The selected AI model name.
 */
function apply_openai_reasoning_logic(
    array &$final_ai_params,
    array $bot_settings,
    string $model
): void {
    $reasoning_effort = AIPKit_OpenAI_Reasoning::normalize_effort_for_model(
        (string) $model,
        $bot_settings['reasoning_effort'] ?? ''
    );

    if ($reasoning_effort !== '') {
        $final_ai_params['reasoning'] = ['effort' => $reasoning_effort];
    }
}

/**
 * Applies the selected model's normalized OpenRouter reasoning effort.
 *
 * @param array<string, mixed> $final_ai_params
 * @param array<string, mixed> $bot_settings
 */
function apply_openrouter_reasoning_logic(
    array &$final_ai_params,
    array $bot_settings,
    string $model
): void {
    $reasoning_effort = AIPKit_OpenRouter_Reasoning::normalize_effort_for_model(
        $model,
        $bot_settings['reasoning_effort'] ?? ''
    );
    if ($reasoning_effort !== '') {
        $final_ai_params['reasoning'] = ['effort' => $reasoning_effort];
    }
}
