<?php

namespace WPAICG\Core\Stream\Contexts\ContentWriter;

if (!defined('ABSPATH')) {
    exit;
}

use WPAICG\Chat\Storage\LogStorage;
use WPAICG\Core\AIPKit_AI_Caller;
use WPAICG\Vector\AIPKit_Vector_Store_Manager;
use WPAICG\AIPKit_Providers;
use WPAICG\AIPKIT_AI_Settings;
use WPAICG\Lib\ContentWriter\StreamTriggers;
use WP_Error;

/**
 * Handles processing stream requests specifically for the 'content_writer' context.
 */
class SSEContentWriterStreamContextHandler
{
    private $log_storage;
    private $ai_caller;
    private $vector_store_manager;

    public function __construct(LogStorage $log_storage)
    {
        $this->log_storage = $log_storage;

        // Dependencies should be loaded by AIPKit_Dependency_Loader.
        // No late require_once calls here.
        if (class_exists(\WPAICG\Core\AIPKit_AI_Caller::class)) {
            $this->ai_caller = new AIPKit_AI_Caller();
        }

        if (class_exists(\WPAICG\Vector\AIPKit_Vector_Store_Manager::class)) {
            $this->vector_store_manager = new AIPKit_Vector_Store_Manager();
        }
    }

    // Getter for LogStorage, used by the logic function
    public function get_log_storage(): LogStorage
    {
        return $this->log_storage;
    }

    public function get_ai_caller(): ?AIPKit_AI_Caller
    {
        return $this->ai_caller;
    }

    public function get_vector_store_manager(): ?AIPKit_Vector_Store_Manager
    {
        return $this->vector_store_manager;
    }

    /**
     * Processes a content writer stream request.
     * @param array $cached_data Contains 'user_message', 'system_instruction', 'provider', 'model', 'ai_params', 'conversation_uuid', 'user_id'.
     * @param array $get_params  Original $_GET parameters (not directly used here as data comes from cache).
     * @return array|WP_Error Prepared data or WP_Error.
     */
    public function process(array $cached_data, array $get_params)
    {
        return process_content_writer_logic($this, $cached_data, $get_params);
    }
}

/**
 * Processes a content writer stream request.
 *
 * @param \WPAICG\Core\Stream\Contexts\ContentWriter\SSEContentWriterStreamContextHandler $handlerInstance The instance of the context handler.
 * @param array $cached_data Contains 'user_message', 'system_instruction', 'provider', 'model', 'ai_params', 'conversation_uuid', 'user_id', and new fields 'generate_meta_description', 'custom_meta_prompt'.
 * @param array $get_params  Original $_GET parameters.
 * @return array|WP_Error Prepared data for SSEStreamProcessor or WP_Error.
 */
function process_content_writer_logic(
    \WPAICG\Core\Stream\Contexts\ContentWriter\SSEContentWriterStreamContextHandler $handlerInstance,
    array $cached_data,
    array $get_params
) {
    // Access dependencies via the handler instance
    $log_storage = $handlerInstance->get_log_storage();

    // Parameter extraction
    $user_id            = get_current_user_id();
    $conversation_uuid  = $cached_data['conversation_uuid'] ?? wp_generate_uuid4();
    $user_message       = $cached_data['user_message'] ?? '';
    $system_instruction = $cached_data['system_instruction'] ?? '';
    $provider           = $cached_data['provider'] ?? '';
    $model              = $cached_data['model'] ?? '';
    $ai_params_from_cache = $cached_data['ai_params'] ?? [];
    $client_ip          = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : null;
    $user_wp_role       = $user_id ? implode(', ', wp_get_current_user()->roles) : null;

    $initial_request_details = $cached_data['initial_request_details'] ?? [];

    $triggers = class_exists(StreamTriggers::class)
        ? new StreamTriggers($log_storage, $user_id, $provider, $model)
        : null;

    // Validation
    if (empty($user_message)) {
        $error = new WP_Error('empty_message_cw_logic', __('Content writer prompt cannot be empty.', 'gpt3-ai-content-generator'));
        if ($triggers) {
            $triggers->error($error, 'validate_input');
        }
        return $error;
    }
    if (empty($provider) || empty($model)) {
        $error = new WP_Error('missing_provider_model_cw_logic', __('Provider or Model is missing for content writer.', 'gpt3-ai-content-generator'));
        if ($triggers) {
            $triggers->error($error, 'validate_provider_model');
        }
        return $error;
    }

    // Log User Request
    $base_log_data = [
        'bot_id'            => null,
        'user_id'           => $user_id,
        'session_id'        => null,
        'conversation_uuid' => $conversation_uuid,
        'module'            => 'content_writer',
        'is_guest'          => 0,
        'role'              => $user_wp_role,
        'ip_address'        => $client_ip,
    ];
    $bot_message_id = 'aif-cw-msg-' . uniqid('', true);
    $base_log_data['bot_message_id'] = $bot_message_id;

    // The initial request details are already in the cached data
    $log_user_data = array_merge($base_log_data, [
        'message_role'       => 'user',
        'message_content'    => "Content Writer Request: " . esc_html($initial_request_details['title'] ?? 'Untitled'),
        'timestamp'          => time(),
        'ai_provider'       => $provider,
        'ai_model'          => $model,
        'request_payload'   => $initial_request_details
    ]);
    $log_storage->log_message($log_user_data);

    // AI Parameters
    $global_ai_params = AIPKIT_AI_Settings::get_ai_parameters();
    $ai_params_for_payload = array_merge($global_ai_params, $ai_params_from_cache);
    $ai_params_for_payload['temperature'] = $ai_params_for_payload['temperature'] ?? 1.0;
    unset($ai_params_for_payload['top_p']);

    $ai_params_for_payload['model_id_for_grounding'] = $model;

    $ai_caller = $handlerInstance->get_ai_caller();
    $vector_store_manager = $handlerInstance->get_vector_store_manager();
    $is_vector_enabled = ($cached_data['enable_vector_store'] ?? '0') === '1';

    if ($is_vector_enabled && $ai_caller && $vector_store_manager) {
        // Always attempt to build vector context and capture scores for logging (OpenAI, Pinecone, Qdrant, Chroma)
        if (!function_exists('\WPAICG\Core\Stream\Vector\build_vector_search_context_logic')) {
            $vector_logic_path = WPAICG_PLUGIN_DIR . 'classes/knowledge-base/retrieval-context.php';
            if (file_exists($vector_logic_path)) {
                require_once $vector_logic_path;
            }
        }
        $collected_vector_search_scores = [];
        if (function_exists('\WPAICG\Core\Stream\Vector\build_vector_search_context_logic')) {
            $vector_context = \WPAICG\Core\Stream\Vector\build_vector_search_context_logic(
                $ai_caller,
                $vector_store_manager,
                $user_message,
                $cached_data,
                $provider,
                null, // active OpenAI VS id (none from CW UI)
                $cached_data['pinecone_index_name'] ?? null,
                null, // pinecone namespace (optional)
                $cached_data['qdrant_collection_name'] ?? null,
                null, // qdrant file upload context id (optional)
                $cached_data['chroma_collection_name'] ?? null,
                null, // chroma file upload context id (optional)
                $collected_vector_search_scores
            );
            if (is_wp_error($vector_context)) { return $vector_context; }
            if (!empty($vector_context)) {
                $system_instruction = $vector_context . "\n\n---\n\n" . $system_instruction;
            }
            if (!empty($collected_vector_search_scores)) {
                // Attach to AI params for visibility in sanitized request payload logs
                $ai_params_for_payload['vector_search_scores'] = $collected_vector_search_scores;
            }
        }

    }
    // Hosted provider tools do not require the local vector manager.
    $vector_provider = $cached_data['vector_store_provider'] ?? 'openai';
    if ($is_vector_enabled && $provider === 'OpenAI' && $vector_provider === 'openai') {
        $openai_vs_ids = $cached_data['openai_vector_store_ids'] ?? [];
        if (!empty($openai_vs_ids) && is_array($openai_vs_ids)) {
            $vector_top_k = isset($cached_data['vector_store_top_k']) ? absint($cached_data['vector_store_top_k']) : 3;
            $vector_top_k = max(1, min($vector_top_k, 20));
            $confidence_threshold_percent = (int)($cached_data['vector_store_confidence_threshold'] ?? 20);
            $openai_score_threshold = round($confidence_threshold_percent / 100, 4);

            $ai_params_for_payload['vector_store_tool_config'] = [
                'type'             => 'file_search',
                'vector_store_ids' => $openai_vs_ids,
                'max_num_results'  => $vector_top_k,
                'ranking_options'  => [
                    'score_threshold' => $openai_score_threshold
                ],
            ];
        }
    } elseif ($is_vector_enabled && $provider === 'Google' && $vector_provider === 'google') {
        $store_names = $cached_data['google_file_search_store_names'] ?? [];
        if (!empty($store_names) && is_array($store_names)) {
            $vector_top_k = isset($cached_data['vector_store_top_k']) ? absint($cached_data['vector_store_top_k']) : 3;
            $ai_params_for_payload['google_file_search_tool_config'] = [
                'file_search_store_names' => array_values(array_unique(array_filter(array_map('sanitize_text_field', $store_names)))),
                'top_k' => max(1, min($vector_top_k, 20)),
            ];
        }
    }

    // API Parameters
    $provData = AIPKit_Providers::get_provider_data($provider);
    $api_params_for_stream = [
        'api_key' => $provData['api_key'] ?? '', 'base_url' => $provData['base_url'] ?? '', 'api_version' => $provData['api_version'] ?? '',
        'azure_endpoint' => ($provider === 'Azure') ? ($provData['endpoint'] ?? '') : '',
        'azure_inference_version' => ($provider === 'Azure') ? ($provData['api_version_inference'] ?? '2025-01-01-preview') : '',
        'azure_authoring_version' => ($provider === 'Azure') ? ($provData['api_version_authoring'] ?? '2023-03-15-preview') : '',
    ];
    // Ollama doesn't require an API key, so skip validation for it
    if ($provider !== 'Ollama' && empty($api_params_for_stream['api_key'])) {
        /* translators: %s: The name of the AI provider (e.g., OpenAI, Google). */
        $error = new WP_Error('missing_api_key_cw_logic', sprintf(__('API key missing for %s (Content Writer).', 'gpt3-ai-content-generator'), $provider), ['status' => 400]);
        if ($triggers) {
            $triggers->error($error, 'get_api_key');
        }
        return $error;
    }
    if ($provider === 'Azure' && empty($api_params_for_stream['azure_endpoint'])) {
        $error = new WP_Error('missing_azure_endpoint_cw_logic', __('Azure endpoint is missing (Content Writer).', 'gpt3-ai-content-generator'), ['status' => 400]);
        if ($triggers) {
            $triggers->error($error, 'get_azure_endpoint');
        }
        return $error;
    }

    $result = [
        'provider' => $provider, 'model' => $model, 'user_message' => $user_message, 'history' => [],
        'system_instruction_filtered' => $system_instruction,
        'api_params' => $api_params_for_stream, 'ai_params' => $ai_params_for_payload,
        'conversation_uuid' => $conversation_uuid, 'base_log_data' => $base_log_data, 'bot_message_id' => $bot_message_id,
    ];

    // Expose captured vector search scores at the top-level for logging parity with Chat/AI Forms
    if (isset($collected_vector_search_scores) && is_array($collected_vector_search_scores) && !empty($collected_vector_search_scores)) {
        $result['vector_search_scores'] = $collected_vector_search_scores;
    }

    return $result;
}
