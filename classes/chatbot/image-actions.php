<?php


namespace WPAICG\Chat\Admin\Ajax;

use WPAICG\Images\AIPKit_Image_Manager;
use WPAICG\Chat\Storage\LogStorage;
use WPAICG\Core\TokenManager\AIPKit_Token_Manager;
use WPAICG\Core\TokenManager\Constants\GuestTableConstants;
use WPAICG\Chat\Storage\BotStorage;
use WPAICG\Utils\AIPKit_Prompt_Sanitizer;
use WP_Error;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Handles AJAX requests for generating images triggered from within a chatbot conversation.
 * Uses the frontend nonce as the request originates from the chat UI.
 * UPDATED: Implements token checking and recording against chatbot limits.
 * MODIFIED: Logs the original user message before processing image generation.
 * ADDED: Retrieves and uses the chatbot's specific image model setting.
 */
class ChatbotImageAjaxHandler extends BaseAjaxHandler
{
    private $log_storage;
    private $image_manager;
    private $token_manager;
    private $bot_storage;

    public function __construct()
    {
        // Instantiate dependencies
        if (!class_exists(LogStorage::class)) {
            return;
        }
        if (!class_exists(AIPKit_Image_Manager::class)) {
            return;
        }
        if (!class_exists(\WPAICG\Core\TokenManager\AIPKit_Token_Manager::class)) {
            $token_manager_path = WPAICG_PLUGIN_DIR . 'classes/usage/tokens.php'; // Updated path
            if (file_exists($token_manager_path)) {
                require_once $token_manager_path;
            } else {
                return;
            }
        }
        if (!class_exists(BotStorage::class)) {
            $bot_storage_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/bots.php';
            if (file_exists($bot_storage_path)) {
                require_once $bot_storage_path;
            } else {
                return;
            }
        }


        $this->log_storage = new LogStorage();
        $this->image_manager = new AIPKit_Image_Manager();
        $this->token_manager = new \WPAICG\Core\TokenManager\AIPKit_Token_Manager();
        $this->bot_storage = new BotStorage();
    }

    /**
     * AJAX: Generates an image based on a prompt from the chat.
     */
    public function ajax_chat_generate_image()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing  -- Nonce is checked by $this->check_frontend_permissions() at the start of the method.
        $permission_check = $this->check_frontend_permissions();
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        $user_id = get_current_user_id();
        $is_logged_in = $user_id > 0;
        $client_ip = isset($_SERVER['REMOTE_ADDR']) ? filter_var(wp_unslash($_SERVER['REMOTE_ADDR']), FILTER_VALIDATE_IP) : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_frontend_permissions method.
        $bot_id = isset($_POST['bot_id']) ? absint($_POST['bot_id']) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_frontend_permissions method.
        $session_id_from_post = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : null;
        $session_id = $is_logged_in ? null : $session_id_from_post;
        if (!$is_logged_in && empty($session_id) && class_exists('\WPAICG\Chat\Frontend\Shortcode\Configurator')) {
            $session_id = \WPAICG\Chat\Frontend\Shortcode\Configurator::get_guest_uuid();
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_frontend_permissions method.
        $conversation_uuid = isset($_POST['conversation_uuid']) ? sanitize_key($_POST['conversation_uuid']) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is checked in check_frontend_permissions(); AIPKit_Prompt_Sanitizer preserves literal HTML while sanitizing prompt text.
        $prompt = isset($_POST['prompt']) ? AIPKit_Prompt_Sanitizer::sanitize(wp_unslash($_POST['prompt'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is checked in check_frontend_permissions(); AIPKit_Prompt_Sanitizer preserves literal HTML while sanitizing prompt text.
        $original_user_text = isset($_POST['original_user_text']) ? AIPKit_Prompt_Sanitizer::sanitize(wp_unslash($_POST['original_user_text'])) : $prompt;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in
        $user_message_id_from_post = isset($_POST['user_message_id']) ? sanitize_key($_POST['user_message_id']) : ('aipkit-client-umsg-' . wp_generate_password(12, false));

        $request_time = time();
        $user_wp_role = !$is_logged_in ? null : implode(', ', wp_get_current_user()->roles);

        if (empty($bot_id)) {
            $this->send_wp_error(new WP_Error('missing_bot_id', __('Bot ID is required.', 'gpt3-ai-content-generator'), ['status' => 400]));
            return;
        }
        if (empty($conversation_uuid)) {
            $this->send_wp_error(new WP_Error('missing_conv_uuid', __('Conversation ID is required.', 'gpt3-ai-content-generator'), ['status' => 400]));
            return;
        }
        if (!$is_logged_in && empty($session_id)) {
            $this->send_wp_error(new WP_Error('missing_session_id', __('Session ID is missing for guest.', 'gpt3-ai-content-generator'), ['status' => 400]));
            return;
        }

        if (get_post_type($bot_id) !== \WPAICG\Chat\Admin\AdminSetup::POST_TYPE || get_post_status($bot_id) !== 'publish') {
            $this->send_wp_error(new WP_Error('invalid_bot_id', __('Invalid chatbot specified.', 'gpt3-ai-content-generator'), ['status' => 400]));
            return;
        }
        $bot_settings = $this->bot_storage->get_chatbot_settings($bot_id);
        if (!$bot_settings || ($bot_settings['enable_image_generation'] ?? '0') !== '1') {
            $this->send_wp_error(new WP_Error('chat_image_generation_disabled', __('Image generation is not enabled for this chatbot.', 'gpt3-ai-content-generator'), ['status' => 403]));
            return;
        }
        $rate_check = \WPAICG\Core\TokenManager\AIPKit_Token_Manager::check_public_request_rate();
        if (is_wp_error($rate_check)) { $this->send_wp_error($rate_check); return; }

        $base_log_data = [
            'bot_id'             => $bot_id,
            'user_id'            => $user_id ?: null,
            'session_id'         => $session_id,
            'conversation_uuid'  => $conversation_uuid,
            'module'             => 'chat',
            'is_guest'           => $is_logged_in ? 0 : 1,
            'role'               => $user_wp_role,
            'ip_address'         => $client_ip,
        ];

        $selected_image_model = $bot_settings['chat_image_model_id'] ?? \WPAICG\Chat\Storage\BotSettingsManager::get_default_model_id('OpenAIImage');
        $provider_for_image = '';

        $replicate_model_ids = [];
        if (class_exists('\WPAICG\AIPKit_Providers')) {
            $replicate_models = \WPAICG\AIPKit_Providers::get_replicate_models();
            if (!empty($replicate_models)) {
                $replicate_model_ids = wp_list_pluck($replicate_models, 'id');
            }
        }

        // Get OpenRouter image models for detection
        $openrouter_image_model_ids = [];
        if (class_exists('\WPAICG\AIPKit_Providers')) {
            $openrouter_image_models = \WPAICG\AIPKit_Providers::get_openrouter_image_models();
            if (!empty($openrouter_image_models)) {
                $openrouter_image_model_ids = wp_list_pluck($openrouter_image_models, 'id');
            }
        }

        // Get Azure image models for detection
        $azure_model_ids = [];
        if (class_exists('\WPAICG\AIPKit_Providers')) {
            $azure_models = \WPAICG\AIPKit_Providers::get_azure_image_models();
            if (!empty($azure_models)) {
                $azure_model_ids = wp_list_pluck($azure_models, 'id');
            }
        }

        // Determine Google provider using synced Google Image Models
        $google_image_model_ids = [];
        if (class_exists('\\WPAICG\\AIPKit_Providers')) {
            $google_image_models = \WPAICG\AIPKit_Providers::get_google_image_models();
            if (!empty($google_image_models)) {
                $google_image_model_ids = wp_list_pluck($google_image_models, 'id');
            }
        }

        // Determine xAI provider using synced/default xAI Image Models
        $xai_image_model_ids = [];
        if (class_exists('\\WPAICG\\AIPKit_Providers')) {
            $xai_image_models = \WPAICG\AIPKit_Providers::get_xai_image_models();
            if (!empty($xai_image_models)) {
                $xai_image_model_ids = wp_list_pluck($xai_image_models, 'id');
            }
        }
        if (strpos($selected_image_model, 'aipuffer/') === 0 || in_array($selected_image_model, wp_list_pluck(\WPAICG\Cloud\Connection::media_models('image_generate'), 'id'), true)) {
            $provider_for_image = 'AIPufferCloud';
        } elseif (in_array($selected_image_model, $openrouter_image_model_ids, true)) {
            $provider_for_image = 'OpenRouter';
        } elseif (in_array($selected_image_model, $google_image_model_ids, true)) {
            $provider_for_image = 'Google';
        } elseif (in_array($selected_image_model, $azure_model_ids, true)) {
            $provider_for_image = 'Azure';
        } elseif (in_array($selected_image_model, $xai_image_model_ids, true)) {
            $provider_for_image = 'xAI';
        } elseif (in_array($selected_image_model, $replicate_model_ids, true)) {
            $provider_for_image = 'Replicate';
        }
        if ($provider_for_image === '' && in_array($selected_image_model, \WPAICG\AIPKit_Providers::get_openai_image_model_ids(), true)) {
            $provider_for_image = 'OpenAI';
        }
        if ($provider_for_image === '') {
            $this->send_wp_error(new WP_Error('chat_image_model_unavailable', __('The selected image model is unavailable. Sync models or select another image model.', 'gpt3-ai-content-generator'), ['status' => 400]));
            return;
        }

        if ($this->token_manager) {
            $module_for_token_check = $is_logged_in ? 'chat' : 'image_generator';
            $context_id_for_token_check = $is_logged_in ? $bot_id : GuestTableConstants::IMG_GEN_GUEST_CONTEXT_ID;
            $token_check_result = $this->token_manager->check_and_reset_tokens(
                $user_id ?: null,
                $session_id,
                $context_id_for_token_check,
                $module_for_token_check,
                [
                    'pricing_module' => 'image_generator',
                    'provider' => $provider_for_image,
                    'model' => $selected_image_model,
                    'operation' => 'generate',
                    'usage_data' => [
                        'unit_count' => 1,
                        'image_count' => 1,
                        'total_units' => 1,
                    ],
                    'fallback_units' => AIPKit_Image_Manager::TOKENS_PER_IMAGE,
                ]
            );

            if (is_wp_error($token_check_result)) {
                $user_command_log_data = array_merge($base_log_data, [
                   'message_role'    => 'user',
                   'message_content' => $original_user_text,
                   'timestamp'       => $request_time,
                   'message_id'      => $user_message_id_from_post,
                ]);
                $this->log_storage->log_message($user_command_log_data);
                $this->log_image_generation_attempt(
                    $conversation_uuid,
                    $prompt,
                    [],
                    $token_check_result,
                    null,
                    $user_id,
                    $session_id,
                    $client_ip,
                    $bot_id,
                    $user_wp_role
                );
                $this->send_wp_error($token_check_result);
                return;
            }
        }

        $user_command_log_data = array_merge($base_log_data, [
            'message_role'    => 'user',
            'message_content' => $original_user_text,
            'timestamp'       => $request_time,
            'message_id'      => $user_message_id_from_post,
            'ai_provider'     => null,
            'ai_model'        => null,
            'usage'           => null,
            'request_payload' => ['command' => $original_user_text, 'extracted_prompt' => $prompt],
            'response_data'   => null,
        ]);
        $bot_response_message_id = $this->log_storage->log_message($user_command_log_data)['message_id'] ?? ('aipkit-bot-msg-' . uniqid());

        // --- MODIFICATION: Add user identifier to options ---
        $user_identifier = $is_logged_in ? (string)$user_id : 'guest';

        $generation_options_from_main_settings = \WPAICG\Images\AIPKit_Image_Settings_Ajax_Handler::get_settings();
        $provider_module_defaults = $generation_options_from_main_settings['defaults'][$provider_for_image] ?? ($generation_options_from_main_settings['defaults']['OpenAI'] ?? []);
        $final_generation_options = array_merge(
            $provider_module_defaults,
            [
                'provider' => $provider_for_image,
                'model'    => $selected_image_model,
                'n'        => 1,
                'user'     => $user_identifier, // ADDED
                'aipkit_event_module' => 'chatbot',
                'aipkit_event_origin' => 'chatbot_image_ajax',
            ]
        );
        if (
            $provider_for_image === 'OpenAI'
            && \WPAICG\AIPKit_Providers::is_openai_gpt_image_model((string) ($final_generation_options['model'] ?? ''))
        ) {
            unset($final_generation_options['response_format']);
            $final_generation_options['output_format'] = 'png';
        }

        $result = $this->image_manager->generate_image($prompt, $final_generation_options, $is_logged_in ? $user_id : null);
        $images_array = [];
        $usage_data = null;

        if (!is_wp_error($result)) {
            $images_array = $result['images'] ?? [];
            $usage_data = $result['usage'] ?? null;
            $images_generated_count = count($images_array);
            $tokens_to_record_for_chatbot = $usage_data['total_tokens'] ?? ($images_generated_count * AIPKit_Image_Manager::TOKENS_PER_IMAGE);

            if ($this->token_manager && $tokens_to_record_for_chatbot > 0) {
                $context_id_for_token_record = $is_logged_in ? $bot_id : GuestTableConstants::IMG_GEN_GUEST_CONTEXT_ID;
                $module_to_record_against = $is_logged_in ? 'chat' : 'image_generator';
                $this->token_manager->record_token_usage(
                    $user_id ?: null,
                    $session_id,
                    $context_id_for_token_record,
                    $tokens_to_record_for_chatbot,
                    $module_to_record_against,
                    [
                        'pricing_module' => 'image_generator',
                        'provider' => $provider_for_image,
                        'model' => $selected_image_model,
                        'operation' => 'generate',
                        'usage_data' => array_merge(
                            is_array($usage_data) ? $usage_data : [],
                            [
                                'unit_count' => $images_generated_count,
                                'image_count' => $images_generated_count,
                            ]
                        ),
                    ]
                );
            }
        }

        $user_wp_role = !$is_logged_in ? null : implode(', ', wp_get_current_user()->roles);
        $this->log_image_generation_attempt(
            $conversation_uuid,
            $prompt,
            $final_generation_options,
            $result,
            $usage_data,
            $user_id,
            $session_id,
            $client_ip,
            $bot_id,
            $user_wp_role,
            $bot_response_message_id
        );

        if (is_wp_error($result)) {
            $this->send_wp_error($result);
        } else {
            wp_send_json_success([
                'type' => 'image',
                'images' => $images_array,
                'prompt' => $prompt,
                'message_id' => $bot_response_message_id,
            ]);
        }
    }

    /**
     * @param mixed[]|\WP_Error $result
     */
    private function log_image_generation_attempt(string $conversation_uuid, string $extracted_prompt, array $request_options_for_log, $result, ?array $usage_data, ?int $user_id, ?string $session_id, ?string $client_ip, int $bot_id_for_log, ?string $user_wp_role, ?string $bot_response_message_id = null)
    {
        if (!$this->log_storage) {
            return;
        }
        $is_error = is_wp_error($result);
        $response_data = [];
        $message_content = '';
        $logged_request_payload = ['prompt' => $extracted_prompt, 'options' => $request_options_for_log,];
        $model_used = $request_options_for_log['model'] ?? 'unknown_image_model';
        $provider_used = $request_options_for_log['provider'] ?? 'UnknownProvider';
        if ($is_error) {
            $message_content = "Error generating image: " . $result->get_error_message();
            $response_data['error_code'] = $result->get_error_code();
        } else {
            $images = $result['images'] ?? [];
            $prompt_snippet = esc_html(mb_substr($extracted_prompt, 0, 50));
            $message_content = sprintf('[Image generated for prompt: "%s..."]', $prompt_snippet);
            $response_data['type'] = 'image';
            $response_data['images'] = [];
            foreach ($images as $img_data) {
                $response_data['images'][] = [ 'url' => $img_data['url'] ?? null, 'revised_prompt' => $img_data['revised_prompt'] ?? null, 'has_b64' => isset($img_data['b64_json']), 'attachment_id' => $img_data['attachment_id'] ?? null, 'media_library_url' => $img_data['media_library_url'] ?? null ];
            }
        }
        if ($bot_response_message_id === null) {
            $bot_response_message_id = 'aipkit-img-err-' . time() . '-' . wp_generate_password(8, false);
        }
        $log_data = [
            'bot_id'             => $bot_id_for_log, 'user_id'            => $user_id ?: null, 'session_id'         => $session_id, 'conversation_uuid' => $conversation_uuid,
            'module'             => 'chat', 'is_guest'           => ($user_id === 0 || $user_id === null), 'role'               => $user_wp_role,
            'ip_address'         => $client_ip,
            'message_role'       => 'bot', 'message_content'    => $message_content, 'timestamp'          => time(),
            'ai_provider'        => $provider_used, 'ai_model'           => $model_used, 'usage'              => $usage_data,
            'message_id'         => $bot_response_message_id,
            'request_payload'    => $logged_request_payload, 'response_data'      => $response_data,
        ];
        $log_result = $this->log_storage->log_message($log_data);
    }
}
