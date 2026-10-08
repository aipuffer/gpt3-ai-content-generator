<?php

namespace {
    if (!defined('ABSPATH')) {
        exit;
    }
}

namespace WPAICG\Images\Manager {

use WP_Error;

use WPAICG\Chat\Storage\LogStorage;
use WPAICG\Images\AIPKit_Image_Settings_Ajax_Handler;
use WPAICG\Images\AIPKit_Image_Storage_Helper;
use WPAICG\Images\AIPKit_Image_Manager;
use WPAICG\Images\AIPKit_Image_Provider_Strategy_Factory;
use WPAICG\AIPKit_Providers;
use WPAICG\Utils\AIPKit_Prompt_Sanitizer;
use WPAICG\Core\AIPKit_Event_Webhooks;
use WPAICG\Core\AIPKit_Payload_Sanitizer;

function constructor_logic(AIPKit_Image_Manager $managerInstance): void
{
    if (!class_exists(LogStorage::class)) {
        $log_storage_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/logs.php';
        if (file_exists($log_storage_path)) {
            require_once $log_storage_path;
        }
    }
    if (class_exists(LogStorage::class)) {
        $managerInstance->set_log_storage(new LogStorage());
    } else {
        $managerInstance->set_log_storage(null);
    }

    if (!class_exists(AIPKit_Image_Settings_Ajax_Handler::class)) {
        $settings_handler_path = WPAICG_PLUGIN_DIR . 'classes/image-generator/settings-actions.php';
        if (file_exists($settings_handler_path)) {
            require_once $settings_handler_path;
        } else {
            return;
        }
    }
    if (class_exists(AIPKit_Image_Settings_Ajax_Handler::class)) {
        $managerInstance->set_settings_ajax_handler(new AIPKit_Image_Settings_Ajax_Handler());
    } else {
        $managerInstance->set_settings_ajax_handler(null);
    }
    if (!class_exists(AIPKit_Image_Storage_Helper::class)) {
        $storage_helper_path = WPAICG_PLUGIN_DIR . 'classes/image-generator/storage.php';
        if (file_exists($storage_helper_path)) {
            require_once $storage_helper_path;
        }
    }

    if (!class_exists(\WPAICG\Core\TokenManager\AIPKit_Token_Manager::class)) {
        $token_manager_path = WPAICG_PLUGIN_DIR . 'classes/usage/tokens.php';
        if (file_exists($token_manager_path)) {
            require_once $token_manager_path;
        }
    }
    if (class_exists(\WPAICG\Core\TokenManager\AIPKit_Token_Manager::class)) {
        $managerInstance->set_token_manager(new \WPAICG\Core\TokenManager\AIPKit_Token_Manager());
    } else {
        $managerInstance->set_token_manager(null);
    }
}

function init_hooks_logic(AIPKit_Image_Manager $managerInstance): void
{
    add_action('wp_ajax_aipkit_generate_image', [$managerInstance, 'ajax_generate_image']);
    add_action('wp_ajax_nopriv_aipkit_generate_image', [$managerInstance, 'ajax_generate_image']);
    add_action('wp_ajax_aipkit_delete_generated_image', [$managerInstance, 'ajax_delete_generated_image']);
    add_action('wp_ajax_aipkit_toggle_generated_media_favorite', [$managerInstance, 'ajax_toggle_generated_media_favorite']);
    add_action('wp_ajax_aipkit_load_more_image_history', [$managerInstance, 'ajax_load_more_image_history']);
    add_action('wp_ajax_aipkit_check_video_status', [$managerInstance, 'ajax_check_video_status']);
    add_action('wp_ajax_nopriv_aipkit_check_video_status', [$managerInstance, 'ajax_check_video_status']);
    foreach (['wp_ajax_', 'wp_ajax_nopriv_'] as $prefix) {
        add_action($prefix . 'aipkit_image_request_status', [$managerInstance, 'ajax_image_request_status']);
        add_action($prefix . 'aipkit_image_nonce', [$managerInstance, 'ajax_image_nonce']);
    }

    $settings_ajax_handler = $managerInstance->get_settings_ajax_handler();
    if ($settings_ajax_handler && method_exists($settings_ajax_handler, 'ajax_save_image_settings')) {
        add_action('wp_ajax_aipkit_save_image_settings', [$settings_ajax_handler, 'ajax_save_image_settings']);
    }
}

function get_image_settings_logic(AIPKit_Image_Manager $managerInstance): array
{
    $image_settings_cache = $managerInstance->get_image_settings_cache();
    if ($image_settings_cache === null) {
        if (class_exists(AIPKit_Image_Settings_Ajax_Handler::class)) {
            $settings_cache = AIPKit_Image_Settings_Ajax_Handler::get_settings();
        } else {
            $settings_cache = AIPKit_Image_Settings_Ajax_Handler::get_default_settings();
        }
        $managerInstance->set_image_settings_cache($settings_cache);
        return $settings_cache;
    }
    return $image_settings_cache;
}

/**
 * @return mixed[]|\WP_Error
 */
function generate_image_logic(AIPKit_Image_Manager $managerInstance, string $prompt, array $options = [], ?int $wp_user_id = null)
{
    $prompt = AIPKit_Prompt_Sanitizer::sanitize($prompt);
    $provider_raw = $options['provider'] ?? 'openai';

    $provider_normalized = AIPKit_Providers::normalize_provider_label((string) $provider_raw);
    if (!in_array($provider_normalized, ['OpenAI', 'OpenRouter', 'Azure', 'Google', 'xAI', 'Pexels', 'Pixabay', 'Replicate', 'AIPufferCloud'], true)) {
        $provider_normalized = 'OpenAI';
    }

    $all_settings = $managerInstance->get_image_settings();
    $provider_module_defaults = $provider_normalized === 'AIPufferCloud' ? []
        : ($all_settings['defaults'][$provider_normalized] ?? ($all_settings['defaults']['OpenAI'] ?? []));
    $final_options = array_merge($provider_module_defaults, $options);
    $final_options['provider'] = $provider_normalized;

    $api_params = AIPKit_Providers::get_provider_data($provider_normalized);
    if ($provider_normalized !== 'AIPufferCloud' && empty($api_params['api_key'])) {
        /* translators: %s: The provider name that was attempted to be used for image generation. */
        return new WP_Error('missing_api_key', sprintf(__('%s API Key is missing.', 'gpt3-ai-content-generator'), $provider_normalized), ['status' => 400]);
    }
    if ($provider_normalized === 'Azure' && empty($api_params['endpoint'])) {
        return new WP_Error('missing_azure_endpoint', __('Azure Endpoint/Region URL is required for image generation.', 'gpt3-ai-content-generator'), ['status' => 400]);
    }

    $strategy = AIPKit_Image_Provider_Strategy_Factory::get_strategy($provider_normalized);
    if (is_wp_error($strategy)) {
        return $strategy;
    }

    if (empty($final_options['model']) && $provider_normalized === 'OpenAI') {
        $final_options['model'] = AIPKit_Providers::get_default_openai_image_model();
    } elseif (empty($final_options['model']) && $provider_normalized === 'OpenRouter') {
        $openrouter_image_models = class_exists(AIPKit_Providers::class) ? AIPKit_Providers::get_openrouter_image_models() : [];
        $first_openrouter_model = '';
        if (is_array($openrouter_image_models) && !empty($openrouter_image_models)) {
            foreach ($openrouter_image_models as $openrouter_model) {
                if (!is_array($openrouter_model) || empty($openrouter_model['id'])) {
                    continue;
                }
                $candidate_id = sanitize_text_field((string) $openrouter_model['id']);
                if ($candidate_id === '' || in_array($candidate_id, ['openrouter/auto', 'auto'], true)) {
                    continue;
                }
                $first_openrouter_model = $candidate_id;
                break;
            }
        }
        $final_options['model'] = $first_openrouter_model !== ''
            ? $first_openrouter_model
            : 'google/' . AIPKit_Providers::get_default_google_image_model();
    } elseif (empty($final_options['model']) && $provider_normalized === 'Google') {
        $final_options['model'] = AIPKit_Providers::get_default_google_image_model();
    } elseif (empty($final_options['model']) && $provider_normalized === 'xAI') {
        $final_options['model'] = AIPKit_Providers::get_default_xai_image_model();
    }
    if ($provider_normalized === 'OpenAI') {
        $final_options['model'] = AIPKit_Providers::normalize_openai_image_model(
            isset($final_options['model']) ? (string) $final_options['model'] : null
        );
    }
    if (
        $provider_normalized === 'Google'
        && strpos(strtolower((string) ($final_options['model'] ?? '')), 'veo') === false
    ) {
        $final_options['model'] = AIPKit_Providers::normalize_google_image_model(
            isset($final_options['model']) ? (string) $final_options['model'] : null
        );
    }
    if (empty($final_options['size'])) {
        $final_options['size'] = '1024x1024';
    }
    if (empty($final_options['n'])) {
        $final_options['n'] = 1;
    }
    if ($provider_normalized === 'xAI') {
        $final_options['model'] = AIPKit_Providers::normalize_xai_image_model(
            isset($final_options['model']) ? (string) $final_options['model'] : null
        );
        if (!isset($final_options['response_format'])) {
            $final_options['response_format'] = 'b64_json';
        }
    }

    if (
        $provider_normalized === 'OpenAI'
        && AIPKit_Providers::is_openai_gpt_image_model((string) ($final_options['model'] ?? ''))
    ) {
        if (isset($final_options['response_format']) && !isset($final_options['output_format'])) {
            if ($final_options['response_format'] === 'b64_json') {
                $final_options['output_format'] = 'png';
            }
            unset($final_options['response_format']);
        }
    }

    $result_from_strategy = $strategy->generate_image($prompt, $api_params, $final_options);

    if (is_wp_error($result_from_strategy)) {
        return $result_from_strategy;
    }

    if ($wp_user_id !== null && class_exists(AIPKit_Image_Storage_Helper::class) && isset($result_from_strategy['images'])) {
        $saved_image_data = [];
        $meta_list = isset($final_options['aipkit_attachment_meta_list']) && is_array($final_options['aipkit_attachment_meta_list'])
            ? array_values($final_options['aipkit_attachment_meta_list'])
            : [];
        foreach ($result_from_strategy['images'] as $index => $image_item) {
            $image_options = $final_options;
            if (!empty($meta_list[$index]) && is_array($meta_list[$index])) {
                $image_options['aipkit_attachment_meta'] = $meta_list[$index];
            }
            $attachment_id_or_error = AIPKit_Image_Storage_Helper::save_image_to_media_library(
                $image_item,
                $prompt,
                $image_options,
                $wp_user_id
            );
            if (!is_wp_error($attachment_id_or_error) && $attachment_id_or_error) {
                $image_item['attachment_id'] = $attachment_id_or_error;
                $image_item['media_library_url'] = wp_get_attachment_url($attachment_id_or_error);
            }
            $saved_image_data[] = $image_item;
        }
        $result_from_strategy['images'] = $saved_image_data;
    }

    $managerInstance->emit_generated_event($prompt, $result_from_strategy, $final_options, $wp_user_id);

    return $result_from_strategy;
}

/**
 * Emits the canonical image generation event without affecting the caller flow.
 *
 * @param AIPKit_Image_Manager  $managerInstance
 * @param string                $prompt
 * @param array<string, mixed>  $result
 * @param array<string, mixed>  $options
 * @param int|null              $user_id
 * @param string|null           $session_id
 * @return void
 */
function emit_generated_event_logic(
    AIPKit_Image_Manager $managerInstance,
    string $prompt,
    array $result,
    array $options = [],
    ?int $user_id = null,
    ?string $session_id = null
): void {
    if (!class_exists(AIPKit_Event_Webhooks::class)) {
        return;
    }

    $images = isset($result['images']) && is_array($result['images']) ? array_values($result['images']) : [];
    $videos = isset($result['videos']) && is_array($result['videos']) ? array_values($result['videos']) : [];
    $output_count = count($images) + count($videos);
    if ($output_count < 1) {
        return;
    }

    $sanitized_outputs = AIPKit_Payload_Sanitizer::sanitize_payload_if_array([
        'images' => $images,
        'videos' => $videos,
    ]);

    $provider = sanitize_text_field((string) ($options['provider'] ?? ''));
    $model = sanitize_text_field((string) ($options['model'] ?? ''));
    $mode = sanitize_key((string) ($options['image_mode'] ?? 'generate'));
    $media_type = !empty($videos) ? 'video' : 'image';
    $source_module = sanitize_key((string) ($options['aipkit_event_module'] ?? 'image_generator'));
    if ($source_module === '') {
        $source_module = 'image_generator';
    }
    $source_origin = sanitize_key((string) ($options['aipkit_event_origin'] ?? 'shared_image_manager'));
    if ($source_origin === '') {
        $source_origin = 'shared_image_manager';
    }

    $payload = [
        'prompt' => $prompt,
        'provider' => $provider,
        'model' => $model,
        'mode' => $mode,
        'media_type' => $media_type,
        'output_count' => $output_count,
        'outputs' => $sanitized_outputs,
        'usage' => isset($result['usage']) && is_array($result['usage']) ? $result['usage'] : null,
        'actor' => [
            'type' => $user_id ? 'user' : 'guest',
        ],
    ];

    if ($user_id) {
        $payload['actor']['user_id'] = $user_id;
    } elseif (!empty($session_id)) {
        $payload['actor']['session_id'] = sanitize_text_field($session_id);
    }

    $output_ids = [];
    foreach ($images as $image) {
        if (!is_array($image)) {
            continue;
        }
        $candidate = $image['attachment_id'] ?? ($image['media_library_url'] ?? ($image['url'] ?? ''));
        if ($candidate === null || $candidate === '') {
            continue;
        }
        $output_ids[] = (string) $candidate;
    }
    foreach ($videos as $video) {
        if (!is_array($video)) {
            continue;
        }
        $candidate = $video['url'] ?? ($video['file_uri'] ?? '');
        if ($candidate === '') {
            continue;
        }
        $output_ids[] = (string) $candidate;
    }

    AIPKit_Event_Webhooks::emit(
        'image.generated',
        $payload,
        [
            'module' => $source_module,
            'origin' => $source_origin,
            'resource' => [
                'type' => 'image_generation',
                'id' => sha1($source_module . '|' . $source_origin . '|' . $provider . '|' . $model . '|' . $mode . '|' . $prompt . '|' . implode('|', $output_ids)),
            'label' => $prompt !== '' ? wp_trim_words($prompt, 8, '...') : __('Image generation', 'gpt3-ai-content-generator'),
        ],
        'meta' => [
            'source_module' => $source_module,
            'source_origin' => $source_origin,
            'provider' => $provider,
            'model' => $model,
            'mode' => $mode,
            'media_type' => $media_type,
            'output_count' => $output_count,
        ],
        'idempotency_key' => sha1(implode('|', [
            'image.generated',
            $source_module,
            $source_origin,
            $provider,
            $model,
            $mode,
            $prompt,
            $media_type,
                implode('|', $output_ids),
            ])),
        ]
    );
}

}

namespace WPAICG\Images\Manager\Ajax {

use WP_Error;

use WPAICG\Images\AIPKit_Image_Manager;
use WPAICG\AIPKit_Providers;
use WPAICG\AIPKit_Role_Manager;
use WPAICG\Core\Moderation\AIPKit_Global_Security_Settings;
use WPAICG\Core\TokenManager\Constants\GuestTableConstants;
use WPAICG\Core\AIPKit_Content_Moderator;
use WPAICG\Utils\AIPKit_Prompt_Sanitizer;
use function WPAICG\Images\Manager\Utils\parse_edit_source_image_upload_logic;
use WPAICG\Shortcodes\AIPKit_Image_Generator_Shortcode;
use WP_Query;
use WPAICG\Images\Providers\Google\GoogleVideoResponseParser;

function ajax_generate_image_logic(AIPKit_Image_Manager $managerInstance): void
{
    // Unslash all POST data at the beginning for security
    $post_data = wp_unslash($_POST);
    // Sanitize SERVER variable
    $client_ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : null;

    $user_id = get_current_user_id();
    $is_logged_in = $user_id > 0;
    $session_id_from_post = isset($post_data['session_id']) ? sanitize_text_field($post_data['session_id']) : null;
    $session_id_for_guest = $is_logged_in
        ? null
        : (class_exists(AIPKit_Global_Security_Settings::class)
            ? AIPKit_Global_Security_Settings::resolve_guest_session_id($session_id_from_post, $client_ip)
            : (!empty($session_id_from_post) ? $session_id_from_post : $client_ip));

    $request_time = time();
    $conversation_uuid = 'imagegen-' . $request_time . '-' . wp_generate_password(12, false);
    $bot_response_message_id = null;

    $nonce_action = 'aipkit_nonce';
    if (isset($post_data['_ajax_nonce']) && wp_verify_nonce(sanitize_key($post_data['_ajax_nonce']), 'aipkit_image_generator_nonce')) {
        $nonce_action = 'aipkit_image_generator_nonce';
    } elseif (!check_ajax_referer($nonce_action, '_ajax_nonce', false)) {
        $error_response = new WP_Error('nonce_failure', __('Security check failed (nonce).', 'gpt3-ai-content-generator'), ['status' => 403]);
        $managerInstance->log_image_generation_attempt($conversation_uuid, $post_data['prompt'] ?? '', $post_data, $error_response, null, $user_id, $session_id_for_guest, $client_ip);
        $managerInstance->send_wp_error($error_response);
        return;
    }

    // Published shortcode requests use their signed public policy, just like guest requests.
    // Role permissions govern the dashboard tool, not whether a visitor is signed in.
    if ($nonce_action === 'aipkit_nonce' && (!$is_logged_in || !AIPKit_Role_Manager::user_can_access_module($managerInstance::MODULE_SLUG))) {
        $error_response = new WP_Error('permission_denied', __('You do not have permission to use the Image Generator.', 'gpt3-ai-content-generator'), ['status' => 403]);
        $managerInstance->log_image_generation_attempt($conversation_uuid, $post_data['prompt'] ?? '', $post_data, $error_response, null, $user_id, $session_id_for_guest, $client_ip);
        $managerInstance->send_wp_error($error_response);
        return;
    }

    $rate_check = \WPAICG\Core\TokenManager\AIPKit_Token_Manager::check_public_request_rate();
    if (is_wp_error($rate_check)) { $managerInstance->send_wp_error($rate_check); return; }

    $policy = $managerInstance::read_public_policy($post_data['selection_policy'] ?? '');
    if (is_wp_error($policy)) { $managerInstance->send_wp_error($policy); return; }
    $provider = AIPKit_Providers::normalize_provider_label(sanitize_text_field($post_data['provider'] ?? ''));
    $selected_model = sanitize_text_field($post_data['model'] ?? '');
    $image_mode = sanitize_key($post_data['image_mode'] ?? 'generate');
    $allowed = $policy['allowed'] ?? ($managerInstance->get_image_settings()['frontend_display']['allowed_models'] ?? '');
    $allowed = array_filter(array_map('trim', explode(',', strtolower((string) $allowed))));
    if (!in_array($provider, ['OpenAI', 'OpenRouter', 'Azure', 'Google', 'xAI', 'Replicate', 'AIPufferCloud'], true)
        || $selected_model === '' || !in_array($image_mode, ['generate', 'edit'], true)
        || (!empty($policy['provider']) && $policy['provider'] !== $provider)
        || (!empty($policy['model']) && $policy['model'] !== $selected_model)
        || ($policy['mode'] !== 'both' && $policy['mode'] !== $image_mode)
        || ($allowed && !in_array(strtolower($provider), $allowed, true) && !in_array(strtolower($selected_model), $allowed, true))) {
        $managerInstance->send_wp_error(new WP_Error('image_selection_not_allowed', __('This model or operation is not available in this image generator.', 'gpt3-ai-content-generator'), ['status' => 403]));
        return;
    }

    $prompt = isset($post_data['prompt']) ? AIPKit_Prompt_Sanitizer::sanitize($post_data['prompt']) : '';
    if (empty($prompt)) {
        $error_response = new WP_Error('missing_prompt', __('Image prompt cannot be empty.', 'gpt3-ai-content-generator'), ['status' => 400]);
        $managerInstance->log_image_generation_attempt($conversation_uuid, $prompt, $post_data, $error_response, null, $user_id, $session_id_for_guest, $client_ip);
        $managerInstance->send_wp_error($error_response);
        return;
    }

    if (class_exists(AIPKit_Content_Moderator::class)) {
        $moderation_context = [
            'client_ip' => $client_ip,
            'bot_settings' => ['provider' => $provider], // Provide a minimal settings array for the check
            'module' => 'image_generator',
        ];
        $moderation_check = AIPKit_Content_Moderator::check_content($prompt, $moderation_context);
        if (is_wp_error($moderation_check)) {
            // Log the moderation failure and send error response
            $managerInstance->log_image_generation_attempt($conversation_uuid, $prompt, $post_data, $moderation_check, null, $user_id, $session_id_for_guest, $client_ip);
            $managerInstance->send_wp_error($moderation_check);
            return;
        }
    }

    $source_image_payload = null;
    if ($image_mode === 'edit') {
        $edit_provider = strtolower($provider);
        if (!in_array($edit_provider, ['google', 'openai', 'openrouter', 'xai', 'aipuffercloud'], true)) {
            $provider_error = new WP_Error(
                'image_edit_provider_unsupported',
                __('The selected provider does not support image editing.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
            $managerInstance->log_image_generation_attempt($conversation_uuid, $prompt, $post_data, $provider_error, null, $user_id, $session_id_for_guest, $client_ip);
            $managerInstance->send_wp_error($provider_error);
            return;
        }

        $source_image_payload = parse_edit_source_image_upload_logic($_FILES, $provider, $selected_model);
        if (is_wp_error($source_image_payload)) {
            $managerInstance->log_image_generation_attempt($conversation_uuid, $prompt, $post_data, $source_image_payload, null, $user_id, $session_id_for_guest, $client_ip);
            $managerInstance->send_wp_error($source_image_payload);
            return;
        }
    }

    $num_images_to_generate = max(1, min(10, (int) ($policy['n'] ?? 1)));
    $pricing_operation = $image_mode;

    if (strtolower($provider) === 'google' && $selected_model !== '') {
        $google_video_model_ids = [];
        if (class_exists('\WPAICG\AIPKit_Providers')) {
            $google_video_models = \WPAICG\AIPKit_Providers::get_google_video_models();
            if (!empty($google_video_models)) {
                $google_video_model_ids = wp_list_pluck($google_video_models, 'id');
            }
        }

        if (in_array($selected_model, $google_video_model_ids, true) || strpos($selected_model, 'veo') !== false) {
            $pricing_operation = 'video_generate';
        }
    }

    $token_manager = $managerInstance->get_token_manager();
    if ($token_manager) {
        $context_id_for_token_check = GuestTableConstants::IMG_GEN_GUEST_CONTEXT_ID;
        $token_check_result = $token_manager->check_and_reset_tokens(
            $user_id ?: null,
            $session_id_for_guest,
            $context_id_for_token_check,
            'image_generator',
            [
                'provider' => $provider,
                'model' => $selected_model,
                'operation' => $pricing_operation,
                'usage_data' => [
                    'unit_count' => $num_images_to_generate,
                    'image_count' => $num_images_to_generate,
                    'total_units' => $num_images_to_generate,
                ],
                'fallback_units' => $num_images_to_generate * $managerInstance::TOKENS_PER_IMAGE,
            ]
        );

        if (is_wp_error($token_check_result)) {
            $managerInstance->log_image_generation_attempt($conversation_uuid, $prompt, $post_data, $token_check_result, null, $user_id, $session_id_for_guest, $client_ip);
            $managerInstance->send_wp_error($token_check_result);
            return;
        }
    }

    // --- MODIFICATION: Changed user identifier format ---
    $user_identifier = $is_logged_in ? (string)$user_id : 'guest';

    $runtime_options = array_filter([
        'image_mode' => $image_mode,
        'provider' => $provider,
        'model' => $selected_model !== '' ? $selected_model : null,
        'size' => $policy['size'] ?? null,
        'n' => $num_images_to_generate,
        'response_format' => isset($post_data['response_format']) ? sanitize_text_field($post_data['response_format']) : 'url',
        'user' => $user_identifier,
        'aipkit_event_module' => 'image_generator',
        'aipkit_event_origin' => 'image_generator_ajax',
    ], function ($value) { return $value !== null; });

    if ($image_mode === 'edit' && is_array($source_image_payload)) {
        $runtime_options['source_image'] = $source_image_payload;
    }

    $request_options_for_log = $runtime_options;
    if (isset($request_options_for_log['source_image']) && is_array($request_options_for_log['source_image'])) {
        $request_options_for_log['source_image'] = [
            'mime_type' => $request_options_for_log['source_image']['mime_type'] ?? '',
            'size_bytes' => $request_options_for_log['source_image']['size_bytes'] ?? 0,
            'file_name' => $request_options_for_log['source_image']['file_name'] ?? '',
        ];
    }

    if (
        strtolower($provider) === 'openai'
        && AIPKit_Providers::is_openai_gpt_image_model((string) ($runtime_options['model'] ?? ''))
    ) {
        $runtime_options['output_format'] = 'png';
        unset($runtime_options['response_format']);
    }

    if ($provider === 'AIPufferCloud') {
        $operation_id = sanitize_text_field($post_data['operation_id'] ?? '');
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $operation_id)) {
            $managerInstance->send_wp_error(new WP_Error('invalid_operation_id', __('Reload the image generator before trying again.', 'gpt3-ai-content-generator'))); return;
        }
        $lock = $managerInstance::lock_operation($operation_id);
        $result = new WP_Error('cloud_outcome_unknown', __('This image request may already be processing. Check its status before starting a new request.', 'gpt3-ai-content-generator'), ['cloud_operation_id' => $operation_id]);
        if ($lock !== '') {
            try {
                if (!get_transient($managerInstance::operation_key($operation_id))) {
                    $managerInstance::save_operation($operation_id, ['provider' => $provider]);
                    $runtime_options['cloud_operation_id'] = $operation_id;
                    $result = $managerInstance->generate_image($prompt, $runtime_options, $is_logged_in ? $user_id : null);
                }
            } catch (\Throwable $error) {
                $result = new WP_Error('cloud_outcome_unknown', __('The image response was interrupted. Check its status before starting a new request.', 'gpt3-ai-content-generator'), ['cloud_operation_id' => $operation_id]);
            } finally {
                $managerInstance::unlock_operation($operation_id, $lock);
            }
        }
    } else {
        $result = $managerInstance->generate_image($prompt, $runtime_options, $is_logged_in ? $user_id : null);
    }
    $images_array = [];
    $videos_array = [];
    $usage_data = null;

    if (!is_wp_error($result)) {
        // Check if this is an async video operation
        if (isset($result['status']) && $result['status'] === 'processing') {
            try {
                $managerInstance::save_operation($result['operation_name'], [
                    'provider' => $provider, 'model' => $selected_model, 'prompt' => $prompt,
                    'session_id' => $session_id_for_guest, 'conversation_uuid' => $conversation_uuid,
                    'options' => $request_options_for_log, 'state' => 'processing',
                ]);
            } catch (\RuntimeException $error) {
                $managerInstance->send_wp_error(new WP_Error('image_operation_storage_failed', __('The video started, but its progress could not be saved. Contact the site administrator before starting another video.', 'gpt3-ai-content-generator'))); return;
            }

            // Log the attempt as processing
            $managerInstance->log_image_generation_attempt(
                $conversation_uuid,
                $prompt,
                $request_options_for_log,
                $result,
                null, // No usage data yet
                $user_id,
                $session_id_for_guest,
                $client_ip,
                null,
                !$is_logged_in ? null : implode(', ', wp_get_current_user()->roles),
                $bot_response_message_id
            );

            // Return async operation info
            wp_send_json_success([
                'status' => 'processing',
                'operation_name' => $result['operation_name'],
                'message' => $result['message']
            ]);
            return;
        }

        // Handle completed generation (images or videos)
        $images_array = $result['images'] ?? [];
        $videos_array = $result['videos'] ?? [];
        $usage_data = $result['usage'] ?? null;

        $media_generated_count = count($images_array) + count($videos_array);
        $tokens_to_record = $usage_data['total_tokens'] ?? ($media_generated_count * $managerInstance::TOKENS_PER_IMAGE);

        if ($token_manager && $tokens_to_record > 0) {
            $context_id_for_token_record = GuestTableConstants::IMG_GEN_GUEST_CONTEXT_ID;
            $token_manager->record_token_usage(
                $user_id ?: null,
                $session_id_for_guest,
                $context_id_for_token_record,
                $tokens_to_record,
                'image_generator',
                [
                    'provider' => $provider,
                    'model' => $selected_model,
                    'operation' => !empty($videos_array) ? 'video_generate' : $pricing_operation,
                    'usage_data' => array_merge(
                        is_array($usage_data) ? $usage_data : [],
                        !empty($videos_array)
                            ? ['unit_count' => count($videos_array), 'video_count' => count($videos_array)]
                            : ['unit_count' => $media_generated_count, 'image_count' => $media_generated_count]
                    ),
                ]
            );
        }
    }

    $user_wp_role = !$is_logged_in ? null : implode(', ', wp_get_current_user()->roles);
    $managerInstance->log_image_generation_attempt(
        $conversation_uuid,
        $prompt,
        $request_options_for_log,
        $result,
        $usage_data,
        $user_id,
        $session_id_for_guest,
        $client_ip,
        null,
        $user_wp_role,
        $bot_response_message_id
    );

    if (is_wp_error($result)) {
        $managerInstance->send_wp_error($result);
    } else {
        // Return appropriate success response
        if (!empty($videos_array)) {
            wp_send_json_success([
                /* translators: %d: Number of videos generated. */
                'message' => sprintf(_n('%d video generated successfully.', '%d videos generated successfully.', count($videos_array), 'gpt3-ai-content-generator'), count($videos_array)),
                'videos' => $videos_array
            ]);
        } else {
            wp_send_json_success([
                /* translators: %d: Number of images generated. */
                'message' => sprintf(_n('%d image generated successfully.', '%d images generated successfully.', count($images_array), 'gpt3-ai-content-generator'), count($images_array)),
                'images' => $images_array
            ]);
        }
    }
}

function ajax_delete_generated_image_logic(): void
{
    check_ajax_referer('aipkit_image_generator_nonce', '_ajax_nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => __('You must be logged in to delete media.', 'gpt3-ai-content-generator')], 403);
        return;
    }
    $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
    if (empty($attachment_id)) {
        wp_send_json_error(['message' => __('Invalid media ID.', 'gpt3-ai-content-generator')], 400);
        return;
    }

    $post_author_id = get_post_field('post_author', $attachment_id);
    if (get_current_user_id() != $post_author_id) {
        if (!\WPAICG\AIPKit_Role_Manager::user_can_manage_others_content()) {
            wp_send_json_error(['message' => __('You do not have permission to delete this media.', 'gpt3-ai-content-generator')], 403);
            return;
        }
    }

    // Check if this is an AI-generated image or video
    $is_aipkit_image = get_post_meta($attachment_id, '_aipkit_generated_image', true);
    $is_aipkit_video = get_post_meta($attachment_id, '_aipkit_generated_video', true);

    if ($is_aipkit_image !== '1' && $is_aipkit_video !== '1') {
        wp_send_json_error(['message' => __('This media was not generated by AI Power.', 'gpt3-ai-content-generator')], 403);
        return;
    }

    $deleted = wp_delete_attachment($attachment_id, true);

    if ($deleted === false) {
        $media_type = ($is_aipkit_video === '1') ? __('video', 'gpt3-ai-content-generator') : __('image', 'gpt3-ai-content-generator');
        /* translators: %s: media type (image or video) */
        wp_send_json_error(['message' => sprintf(__('Failed to delete the %s from the media library.', 'gpt3-ai-content-generator'), $media_type)], 500);
    } else {
        $media_type = ($is_aipkit_video === '1') ? __('Video', 'gpt3-ai-content-generator') : __('Image', 'gpt3-ai-content-generator');
        /* translators: %s: media type (Image or Video) */
        wp_send_json_success(['message' => sprintf(__('%s deleted successfully.', 'gpt3-ai-content-generator'), $media_type)]);
    }
}

function ajax_toggle_generated_media_favorite_logic(): void
{
    check_ajax_referer('aipkit_image_generator_nonce', '_ajax_nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => __('You must be logged in to favorite media.', 'gpt3-ai-content-generator')], 403);
        return;
    }

    $post_data = wp_unslash($_POST);
    $attachment_id = isset($post_data['attachment_id']) ? absint($post_data['attachment_id']) : 0;
    if ($attachment_id <= 0) {
        wp_send_json_error(['message' => __('Invalid media ID.', 'gpt3-ai-content-generator')], 400);
        return;
    }

    $post_author_id = (int) get_post_field('post_author', $attachment_id);
    if (get_current_user_id() !== $post_author_id && !\WPAICG\AIPKit_Role_Manager::user_can_manage_others_content()) {
        wp_send_json_error(['message' => __('You do not have permission to favorite this media.', 'gpt3-ai-content-generator')], 403);
        return;
    }

    $is_aipkit_image = get_post_meta($attachment_id, '_aipkit_generated_image', true) === '1';
    $is_aipkit_video = get_post_meta($attachment_id, '_aipkit_generated_video', true) === '1';
    if (!$is_aipkit_image && !$is_aipkit_video) {
        wp_send_json_error(['message' => __('This media was not generated by AI Power.', 'gpt3-ai-content-generator')], 403);
        return;
    }

    $is_favorite = isset($post_data['favorite']) && $post_data['favorite'] === '1';
    if ($is_favorite) {
        update_post_meta($attachment_id, AIPKit_Image_Generator_Shortcode::FAVORITE_META_KEY, '1');
    } else {
        delete_post_meta($attachment_id, AIPKit_Image_Generator_Shortcode::FAVORITE_META_KEY);
    }

    wp_send_json_success([
        'attachment_id' => $attachment_id,
        'favorite' => $is_favorite,
    ]);
}

function ajax_load_more_image_history_logic(): void
{
    check_ajax_referer('aipkit_image_generator_nonce', '_ajax_nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => __('You must be logged in to view history.', 'gpt3-ai-content-generator')], 403);
        return;
    }

    $page = max(1, isset($_POST['page']) ? absint($_POST['page']) : 1);
    $requested_filter = isset($_POST['filter'])
        ? sanitize_key(wp_unslash((string) $_POST['filter']))
        : 'all';
    $shortcode_mode = isset($_POST['shortcode_mode']) ? sanitize_key(wp_unslash((string) $_POST['shortcode_mode'])) : 'both';
    if (!in_array($shortcode_mode, ['generate', 'edit', 'both'], true)) {
        $shortcode_mode = 'both';
    }
    $allow_edit_action = in_array($shortcode_mode, ['edit', 'both'], true);
    $user_id = get_current_user_id();
    if (!class_exists(AIPKit_Image_Generator_Shortcode::class)) {
        $shortcode_path = WPAICG_PLUGIN_DIR . 'classes/image-generator/shortcode.php';
        if (file_exists($shortcode_path)) {
            require_once $shortcode_path;
        }
    }
    if (!class_exists(AIPKit_Image_Generator_Shortcode::class)) {
        wp_send_json_error(['message' => __('Image history renderer is unavailable.', 'gpt3-ai-content-generator')], 500);
        return;
    }

    $filter = AIPKit_Image_Generator_Shortcode::normalize_history_filter($requested_filter);
    $query = new WP_Query(AIPKit_Image_Generator_Shortcode::build_history_query_args($user_id, $page, $filter));

    ob_start();
    if ($query->have_posts()) {
        while ($query->have_posts()) : $query->the_post();
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_history_item() returns plugin-generated markup with escaped dynamic values.
            echo AIPKit_Image_Generator_Shortcode::render_history_item((int) get_the_ID(), $allow_edit_action);
        endwhile;
    }
    $html_items = ob_get_clean();
    wp_reset_postdata();

    $has_more = ($page < $query->max_num_pages);

    wp_send_json_success([
        'html' => $html_items,
        'filter' => $filter,
        'page' => $page,
        'max_pages' => max(1, (int) $query->max_num_pages),
        'has_more' => $has_more,
    ]);
}

function ajax_check_video_status_logic(AIPKit_Image_Manager $managerInstance): void
{
    // The nonce is checked before request parameters are used.
    $post_data = wp_unslash($_POST);
    if (!isset($post_data['_ajax_nonce']) || !wp_verify_nonce(sanitize_key($post_data['_ajax_nonce']), 'aipkit_image_generator_nonce')) {
        $managerInstance->send_wp_error(new WP_Error('nonce_failure', __('Security check failed (nonce).', 'gpt3-ai-content-generator'), ['status' => 403])); return;
    }
    if (get_current_user_id() && !AIPKit_Role_Manager::user_can_access_module($managerInstance::MODULE_SLUG)) {
        $managerInstance->send_wp_error(new WP_Error('permission_denied', __('You do not have permission to check video status.', 'gpt3-ai-content-generator'), ['status' => 403])); return;
    }
    $rate = \WPAICG\Core\TokenManager\AIPKit_Token_Manager::check_public_request_rate();
    if (is_wp_error($rate)) { $managerInstance->send_wp_error($rate); return; }
    $id = sanitize_text_field($post_data['operation_name'] ?? '');
    $record = $managerInstance::read_operation($id);
    if (!$record || ($record['provider'] ?? '') !== 'Google') {
        $managerInstance->send_wp_error(new WP_Error('image_operation_missing', __('This video request is unavailable or belongs to another visitor.', 'gpt3-ai-content-generator'), ['status' => 404])); return;
    }
    $lock = $managerInstance::lock_operation($id);
    if ($lock === '') { wp_send_json_success(['status' => 'processing', 'message' => __('Video completion is being checked.', 'gpt3-ai-content-generator')]); return; }
    try {
        // Re-read after acquiring the lock: another poll may have finished while we waited.
        $record = $managerInstance::read_operation($id);
        $result = complete_video_operation_logic($managerInstance, $id, $record);
    } catch (\Throwable $error) {
        $result = new WP_Error('video_completion_interrupted', __('Video completion was interrupted. Contact the site administrator before starting another video.', 'gpt3-ai-content-generator'));
    } finally {
        $managerInstance::unlock_operation($id, $lock);
    }
    if (is_wp_error($result)) { $managerInstance->send_wp_error($result); return; }
    wp_send_json_success($result);
}

/** Runs under the operation lock. JSON delivery happens only after releasing it. */
function complete_video_operation_logic(AIPKit_Image_Manager $managerInstance, string $id, array $record)
{
    if (isset($record['result'])) { return $record['result']; }
    if (($record['state'] ?? '') !== 'processing') {
        return new WP_Error('video_completion_interrupted', __('This video completion needs review. Contact the site administrator before starting another video.', 'gpt3-ai-content-generator'));
    }
    $api_params = AIPKit_Providers::get_provider_data('Google');
    if (empty($api_params['api_key'])) { return new WP_Error('missing_api_key', __('Google API Key is not configured.', 'gpt3-ai-content-generator')); }
    $result = GoogleVideoResponseParser::check_operation_status($id, $record['model'], $api_params, $record['prompt'], get_current_user_id() ?: null, static function () use ($managerInstance, $id, &$record): void {
        // Persist before the first download: an interrupted finalization must not run twice.
        $record['state'] = 'finalizing';
        $managerInstance::save_operation($id, $record);
    });
    if (is_wp_error($result)) { return $result; }
    if (($result['status'] ?? '') === 'processing') {
        $record['state'] = 'processing';
        $managerInstance::save_operation($id, $record);
        return $result;
    }
    if (($result['status'] ?? '') !== 'completed' || empty($result['videos'])) {
        return new WP_Error('invalid_video_result', __('The video result could not be read. Contact the site administrator before starting another video.', 'gpt3-ai-content-generator'));
    }
    $usage = $result['usage'] ?? [];
    $count = count($result['videos']);
    $tokens = $managerInstance->get_token_manager();
    if ($tokens) {
        $tokens->record_token_usage(get_current_user_id() ?: null, $record['session_id'], GuestTableConstants::IMG_GEN_GUEST_CONTEXT_ID,
            (int) ($usage['total_tokens'] ?? ($count * $managerInstance::TOKENS_PER_IMAGE)), 'image_generator', [
                'provider' => 'Google', 'model' => $record['model'], 'operation' => 'video_generate',
                'idempotency_key' => 'image-video:' . hash('sha256', $id),
                'usage_data' => array_merge($usage, ['unit_count' => $count, 'video_count' => $count]),
            ]);
    }
    $managerInstance->emit_generated_event($record['prompt'], $result, $record['options'], get_current_user_id() ?: null, $record['session_id']);
    $record['state'] = 'completed';
    $record['result'] = $result;
    $managerInstance::save_operation($id, $record);
    return $result;
}

}

namespace WPAICG\Images\Manager\Log {

use WPAICG\Images\AIPKit_Image_Manager;

/**
 * @param mixed[]|\WP_Error $result
 */
function log_image_generation_attempt_logic(
    AIPKit_Image_Manager $managerInstance,
    string $conversation_uuid,
    string $extracted_prompt,
    array $request_options_for_log,
    $result,
    ?array $usage_data,
    ?int $user_id,
    ?string $session_id,
    ?string $client_ip,
    ?int $bot_id_for_log = null,
    ?string $user_wp_role = null,
    ?string $bot_response_message_id = null
): void {
    $log_storage = $managerInstance->get_log_storage();
    if (!$log_storage) {
        return;
    }
    $is_error = is_wp_error($result);
    $response_data_for_log = [];
    $message_content = '';
    $request_options_for_log_safe = $request_options_for_log;
    if (isset($request_options_for_log_safe['source_image'])) {
        if (is_array($request_options_for_log_safe['source_image'])) {
            $request_options_for_log_safe['source_image'] = [
                'mime_type' => $request_options_for_log_safe['source_image']['mime_type'] ?? '',
                'size_bytes' => $request_options_for_log_safe['source_image']['size_bytes'] ?? 0,
                'file_name' => $request_options_for_log_safe['source_image']['file_name'] ?? '',
            ];
        } else {
            unset($request_options_for_log_safe['source_image']);
        }
    }
    $logged_request_payload = ['prompt' => $extracted_prompt, 'options' => $request_options_for_log_safe];
    $model_used = $request_options_for_log['model'] ?? 'default_image_model';
    $provider_used = $request_options_for_log['provider'] ?? 'OpenAI';
    if ($is_error) {
        $message_content = "Error generating image: " . $result->get_error_message();
        $response_data_for_log['error_code'] = $result->get_error_code();
    } else {
        $images = $result['images'] ?? [];
        $prompt_snippet = esc_html(mb_substr($extracted_prompt, 0, 50));
        $message_content = sprintf('[Image generated for prompt: "%s..."]', $prompt_snippet);
        $response_data_for_log['type'] = 'image';
        $response_data_for_log['images'] = [];
        foreach ($images as $img_data) {
            $response_data_for_log['images'][] = [ 'url' => $img_data['url'] ?? null, 'revised_prompt' => $img_data['revised_prompt'] ?? null, 'has_b64' => isset($img_data['b64_json']), 'attachment_id' => $img_data['attachment_id'] ?? null, 'media_library_url' => $img_data['media_library_url'] ?? null ];
        }
    }
    if ($bot_response_message_id === null) {
        $bot_response_message_id = 'aipkit-img-err-' . time() . '-' . wp_generate_password(8, false);
    }

    $log_data = [
        'bot_id'             => $bot_id_for_log, 'user_id'            => $user_id ?: null, 'session_id'         => $session_id, 'conversation_uuid' => $conversation_uuid,
        'module'             => $bot_id_for_log ? 'chat' : $managerInstance::MODULE_SLUG, 'is_guest'           => ($user_id === 0 || $user_id === null), 'role'               => $user_wp_role,
        'ip_address'         => $client_ip,
        'message_role'       => 'bot', 'message_content'    => $message_content, 'timestamp'          => time(),
        'ai_provider'        => $provider_used, 'ai_model'           => $model_used, 'usage'              => $usage_data,
        'message_id'         => $bot_response_message_id,
        'request_payload'    => $logged_request_payload, 'response_data'      => $response_data_for_log,
    ];
    $log_storage->log_message($log_data);
}

}

namespace WPAICG\Images\Manager\Utils {

use WP_Error;


/**
 * Validate and normalize the source image upload for image-edit mode.
 *
 * @param array $files_data Raw $_FILES data.
 * @return array|WP_Error Normalized image payload or WP_Error on invalid input.
 */
function parse_edit_source_image_upload_logic(array $files_data, string $provider = '', string $model = '')
{
    $constraints = \WPAICG\Images\AIPKit_Image_Provider_Strategy_Factory::edit_upload_constraints($provider, $model);
    $allowed_mime_types = $constraints['allowedMimeTypes'];
    $max_size_bytes = $constraints['maxBytes'];

    if (!isset($files_data['source_image']) || !is_array($files_data['source_image'])) {
        return new WP_Error(
            'missing_source_image',
            __('Please upload an image to edit.', 'gpt3-ai-content-generator'),
            ['status' => 400]
        );
    }

    $file = $files_data['source_image'];
    if (isset($file['name']) && is_array($file['name'])) {
        return new WP_Error(
            'multiple_source_images_not_supported',
            __('Only one source image is supported in this version.', 'gpt3-ai-content-generator'),
            ['status' => 400]
        );
    }

    $error_code = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
    if ($error_code !== UPLOAD_ERR_OK) {
        $error_message = __('Source image upload failed. Please try again.', 'gpt3-ai-content-generator');
        $status_code = 400;
        if ($error_code === UPLOAD_ERR_NO_FILE) {
            $error_message = __('Please upload an image to edit.', 'gpt3-ai-content-generator');
        } elseif ($error_code === UPLOAD_ERR_INI_SIZE || $error_code === UPLOAD_ERR_FORM_SIZE) {
            $error_message = $constraints['tooLargeMessage'];
            $status_code = 413;
        }
        return new WP_Error(
            'source_image_upload_failed',
            $error_message,
            ['status' => $status_code]
        );
    }

    $tmp_name = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
    if ($tmp_name === '' || !is_readable($tmp_name)) {
        return new WP_Error(
            'source_image_upload_failed',
            __('Source image upload failed. Please try again.', 'gpt3-ai-content-generator'),
            ['status' => 400]
        );
    }

    $reported_size = isset($file['size']) ? (int) $file['size'] : 0;
    if ($reported_size <= 0) {
        return new WP_Error(
            'invalid_source_image',
            __('Invalid source image file.', 'gpt3-ai-content-generator'),
            ['status' => 400]
        );
    }
    if ($reported_size > $max_size_bytes) {
        return new WP_Error(
            'source_image_too_large',
            $constraints['tooLargeMessage'],
            ['status' => 413]
        );
    }

    $file_name = sanitize_file_name((string) ($file['name'] ?? 'image'));
    $file_info = wp_check_filetype_and_ext($tmp_name, $file_name);
    $mime_type = isset($file_info['type']) ? strtolower((string) $file_info['type']) : '';

    if ($mime_type === '' && function_exists('mime_content_type')) {
        $detected_mime = mime_content_type($tmp_name);
        if (is_string($detected_mime) && $detected_mime !== '') {
            $mime_type = strtolower($detected_mime);
        }
    }

    if (!in_array($mime_type, $allowed_mime_types, true)) {
        return new WP_Error(
            'invalid_source_image_type',
            $constraints['invalidTypeMessage'],
            ['status' => 400]
        );
    }

    $binary = file_get_contents($tmp_name);
    if (!is_string($binary) || $binary === '') {
        return new WP_Error(
            'invalid_source_image',
            __('Invalid source image file.', 'gpt3-ai-content-generator'),
            ['status' => 400]
        );
    }

    $actual_size = strlen($binary);
    if ($actual_size <= 0) {
        return new WP_Error(
            'invalid_source_image',
            __('Invalid source image file.', 'gpt3-ai-content-generator'),
            ['status' => 400]
        );
    }
    if ($actual_size > $max_size_bytes) {
        return new WP_Error(
            'source_image_too_large',
            $constraints['tooLargeMessage'],
            ['status' => 413]
        );
    }

    return [
        'mime_type' => $mime_type,
        'base64_data' => base64_encode($binary),
        'size_bytes' => $actual_size,
        'file_name' => $file_name,
    ];
}

function send_wp_error_logic(WP_Error $error): void
{
    $error_data = $error->get_error_data();
    $status = is_array($error_data) && isset($error_data['status']) ? $error_data['status'] : 400;
    $payload = [
        'message' => $error->get_error_message(),
        'code' => $error->get_error_code(),
    ];

    if (is_array($error_data) && !empty($error_data['quota_notice']) && is_array($error_data['quota_notice'])) {
        $payload['quota_notice'] = $error_data['quota_notice'];
    }
    if (is_array($error_data) && !empty($error_data['cloud_operation_id'])) {
        $payload['operation_id'] = $error_data['cloud_operation_id'];
    }

    wp_send_json_error($payload, $status);
}

}

namespace WPAICG\Images {

use WP_Error;

/**
 * AIPKit_Image_Manager (Facade)
 * Main class for handling image generation functionality.
 * Delegates logic to namespaced functions.
 */
class AIPKit_Image_Manager
{
    public const MODULE_SLUG = 'image_generator';
    public const TOKENS_PER_IMAGE = 2000;

    private $log_storage;
    private $settings_ajax_handler;
    private $image_settings_cache = null;
    private $token_manager;

    public static function sign_public_policy(array $policy): string
    {
        $encoded = base64_encode(wp_json_encode($policy));
        return $encoded . ':' . wp_hash('image_selection|' . $encoded);
    }

    /** Cached shortcode policy is independent of the expiring request nonce. */
    public static function read_public_policy($signed)
    {
        $parts = is_string($signed) && strlen($signed) <= 16384 ? explode(':', $signed, 2) : [];
        if (count($parts) === 2 && hash_equals(wp_hash('image_selection|' . $parts[0]), $parts[1])) {
            $policy = json_decode((string) base64_decode($parts[0], true), true);
            if (is_array($policy) && in_array($policy['mode'] ?? '', ['generate', 'edit', 'both'], true)) { return $policy; }
        }
        return new WP_Error('image_policy_invalid', __('Reload this image generator to refresh its settings. If the problem continues, clear the page cache.', 'gpt3-ai-content-generator'), ['status' => 403]);
    }

    private static function operation_owner(): string
    {
        // Guest identity is independent of a caller-supplied session ID.
        $address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        return wp_hash('image_owner|' . (get_current_user_id() ? 'user:' . get_current_user_id() : 'guest:' . $address));
    }

    public static function operation_key(string $id): string
    {
        return 'aipkit_image_operation_' . hash('sha256', $id);
    }

    public static function read_operation(string $id): array
    {
        $record = get_transient(self::operation_key($id));
        return is_array($record) && hash_equals($record['owner'] ?? '', self::operation_owner()) ? $record : [];
    }

    public static function save_operation(string $id, array $record): void
    {
        $record['owner'] = self::operation_owner();
        $key = self::operation_key($id);
        set_transient($key, $record, DAY_IN_SECONDS);
        if (get_transient($key) !== $record) { throw new \RuntimeException('image_operation_storage_failed'); }
    }

    public static function lock_operation(string $id): string
    {
        if (!class_exists('\\WPAICG\\AutoGPT\\Cron\\AIPKit_Option_Lock')) {
            require_once WPAICG_PLUGIN_DIR . 'classes/automations/lock.php';
        }
        return \WPAICG\AutoGPT\Cron\AIPKit_Option_Lock::acquire(self::operation_key($id) . '_lock', 600);
    }

    public static function unlock_operation(string $id, string $token): void
    {
        \WPAICG\AutoGPT\Cron\AIPKit_Option_Lock::release(self::operation_key($id) . '_lock', $token);
    }

    public function ajax_image_nonce(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The policy validator checks its length and signature; changing signed bytes would invalidate it. This issues a nonce for cached guest pages.
        $policy = self::read_public_policy(wp_unslash($_POST['selection_policy'] ?? ''));
        if (is_wp_error($policy)) { $this->send_wp_error($policy); return; }
        nocache_headers();
        wp_send_json_success(['nonce' => wp_create_nonce('aipkit_image_generator_nonce')]);
    }

    public function ajax_image_request_status(): void
    {
        if (!check_ajax_referer('aipkit_image_generator_nonce', '_ajax_nonce', false)) {
            $this->send_wp_error(new WP_Error('nonce_failure', __('Security check failed (nonce).', 'gpt3-ai-content-generator'), ['status' => 403])); return;
        }
        if (get_current_user_id() && !\WPAICG\AIPKit_Role_Manager::user_can_access_module(self::MODULE_SLUG)) {
            $this->send_wp_error(new WP_Error('permission_denied', __('You cannot access this image request.', 'gpt3-ai-content-generator'), ['status' => 403])); return;
        }
        $id = sanitize_text_field(wp_unslash($_POST['operation_id'] ?? ''));
        $record = self::read_operation($id);
        if (!$record || ($record['provider'] ?? '') !== 'AIPufferCloud') {
            $this->send_wp_error(new WP_Error('image_operation_missing', __('The request status is unavailable. It may still have completed. Check your credits before starting a new request.', 'gpt3-ai-content-generator'), ['status' => 404])); return;
        }
        $rate = \WPAICG\Core\TokenManager\AIPKit_Token_Manager::check_public_request_rate();
        if (is_wp_error($rate)) { $this->send_wp_error($rate); return; }
        try {
            $status = \WPAICG\Cloud\Connection::request_status($id);
        } catch (\RuntimeException $error) {
            $this->send_wp_error(new WP_Error('image_status_unavailable', __('The request status could not be checked. Check again before starting another request.', 'gpt3-ai-content-generator')));
            return;
        }
        wp_send_json_success(['state' => sanitize_key($status['state'] ?? 'unknown')]);
    }

    public function __construct()
    {
        Manager\constructor_logic($this);
    }

    public function init_hooks()
    {
        Manager\init_hooks_logic($this);
    }

    public function get_image_settings(): array
    {
        return Manager\get_image_settings_logic($this);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function generate_image(string $prompt, array $options = [], ?int $wp_user_id = null)
    {
        return Manager\generate_image_logic($this, $prompt, $options, $wp_user_id);
    }

    public function emit_generated_event(
        string $prompt,
        array $result,
        array $options = [],
        ?int $user_id = null,
        ?string $session_id = null
    ): void {
        Manager\emit_generated_event_logic($this, $prompt, $result, $options, $user_id, $session_id);
    }

    public function ajax_generate_image()
    {
        Manager\Ajax\ajax_generate_image_logic($this);
    }

    public function ajax_delete_generated_image()
    {
        Manager\Ajax\ajax_delete_generated_image_logic();
    }

    public function ajax_toggle_generated_media_favorite()
    {
        Manager\Ajax\ajax_toggle_generated_media_favorite_logic();
    }

    public function ajax_load_more_image_history()
    {
        Manager\Ajax\ajax_load_more_image_history_logic();
    }

    public function ajax_check_video_status()
    {
        Manager\Ajax\ajax_check_video_status_logic($this);
    }

    /**
     * @param mixed[]|\WP_Error $result
     */
    public function log_image_generation_attempt(
        string $conversation_uuid,
        string $extracted_prompt,
        array $request_options_for_log,
        $result,
        ?array $usage_data,
        ?int $user_id,
        ?string $session_id,
        ?string $client_ip,
        ?int $bot_id_for_log = null,
        ?string $user_wp_role = null,
        ?string $bot_response_message_id = null
    ) {
        Manager\Log\log_image_generation_attempt_logic($this, $conversation_uuid, $extracted_prompt, $request_options_for_log, $result, $usage_data, $user_id, $session_id, $client_ip, $bot_id_for_log, $user_wp_role, $bot_response_message_id);
    }

    public function send_wp_error(WP_Error $error)
    {
        Manager\Utils\send_wp_error_logic($error);
    }

    // --- Getters and Setters for externalized logic functions ---
    public function get_log_storage()
    {
        return $this->log_storage;
    }
    public function set_log_storage($storage)
    {
        $this->log_storage = $storage;
    }
    public function get_settings_ajax_handler()
    {
        return $this->settings_ajax_handler;
    }
    public function set_settings_ajax_handler($handler)
    {
        $this->settings_ajax_handler = $handler;
    }
    public function get_image_settings_cache()
    {
        return $this->image_settings_cache;
    }
    public function set_image_settings_cache($cache)
    {
        $this->image_settings_cache = $cache;
    }
    public function get_token_manager()
    {
        return $this->token_manager;
    }
    public function set_token_manager($manager)
    {
        $this->token_manager = $manager;
    }
    // --- End Getters and Setters ---
}

}
