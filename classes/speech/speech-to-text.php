<?php

namespace WPAICG\STT;

use WP_Error;
use WPAICG\Core\AIPKit_HTTP_Request;
use WPAICG\AIPKit_Providers;
use WPAICG\Chat\Storage\BotStorage;
use WPAICG\Utils\AIPKit_CORS_Manager;
use WPAICG\Lib\Chat\FrontendPermissions;
use WPAICG\Core\Models\AIPKit_Model_Catalog;
use WPAICG\Core\Providers\OpenAI\OpenAIUrlBuilder;
use WPAICG\Core\Providers\Google\Interactions\GoogleInteractionsSttAdapter;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AIPKit_STT_Manager
 * Main class for handling Speech-to-Text (STT) functionality.
 */
class AIPKit_STT_Manager
{
    private $bot_storage;

    public function __construct()
    {
        // Load dependencies or setup initial state if needed
        // Ensure BotStorage exists and instantiate
        if (!class_exists(\WPAICG\Chat\Storage\BotStorage::class)) {
            return;
        }
        $this->bot_storage = new BotStorage();
    }

    /**
     * Register hooks (e.g., for AJAX actions related to STT).
     */
    public function init_hooks()
    {
        add_action('wp_ajax_aipkit_transcribe_audio', [$this, 'ajax_transcribe_audio']);
        add_action('wp_ajax_nopriv_aipkit_transcribe_audio', [$this, 'ajax_transcribe_audio']);
    }

    /**
     * Transcribes audio data to text using the specified provider.
     *
     * @param string $audio_data Base64 encoded audio data OR raw binary data.
     * @param string $audio_format The format of the audio (e.g., 'wav', 'mp3').
     * @param array $options Optional parameters including 'provider', 'bot_id'.
     *                       May also contain a provider-specific STT model ID.
     * @return string|WP_Error Transcribed text string or WP_Error on failure.
     */
    public function speech_to_text(string $audio_data, string $audio_format, array $options = [])
    {
        $requested_provider = isset($options['provider'])
            ? sanitize_text_field((string) $options['provider'])
            : '';
        $stt_provider = '';
        $bot_settings = [];
        if (!empty($options['bot_id'])) {
            $bot_settings = $this->bot_storage->get_chatbot_settings(absint($options['bot_id']));
            $stt_provider = isset($bot_settings['stt_provider'])
                ? sanitize_text_field((string) $bot_settings['stt_provider'])
                : '';
        }
        $provider = $stt_provider !== ''
            ? $stt_provider
            : ($requested_provider !== '' ? $requested_provider : AIPKit_Providers::get_current_provider());

        $valid_stt_providers = ['OpenAI', 'Google', 'Azure', 'AIPufferCloud'];
        if (!in_array($provider, $valid_stt_providers, true)) {
            $provider = 'OpenAI';
        }

        if (!empty($bot_settings)) {
            $model_key_by_provider = [
                'OpenAI' => 'stt_openai_model_id',
                'Google' => 'stt_google_model_id',
                'Azure' => 'stt_azure_model_id',
                'AIPufferCloud' => 'stt_cloud_model_id',
            ];
            $model_key = $model_key_by_provider[$provider] ?? '';
            if ($model_key !== '' && isset($bot_settings[$model_key])) {
                $options['stt_model'] = (string) $bot_settings[$model_key];
            }
        }

        // 2. Get Provider Strategy
        $strategy = AIPKit_STT_Provider_Strategy_Factory::get_strategy($provider);
        if (is_wp_error($strategy)) {
            return $strategy;
        }

        // 3. Get API credentials for the *selected STT provider*
        $provider_data = AIPKit_Providers::get_provider_data($provider);
        $api_params = [
            'api_key' => $provider_data['api_key'] ?? null,
            'base_url' => $provider_data['base_url'] ?? null,
            'api_version' => $provider_data['api_version'] ?? null,
            'azure_endpoint' => $provider_data['endpoint'] ?? null,
            'stt_model' => $options['stt_model'] ?? null,
        ];
        if ($provider !== 'AIPufferCloud' && empty($api_params['api_key'])) {
            /* translators: %s is the STT provider name */
            return new WP_Error('missing_stt_api_key', sprintf(__('API Key for STT provider %s is missing.', 'gpt3-ai-content-generator'), $provider));
        }
        if ($provider === 'Azure' && empty($api_params['azure_endpoint'])) {
            return new WP_Error('missing_stt_endpoint', __('Azure Endpoint/Region URL is required for STT.', 'gpt3-ai-content-generator'));
        }

        // 4. Validate format support
        $supported_formats = $strategy->get_supported_formats();
        if (!in_array(strtolower($audio_format), $supported_formats)) {
            /* translators: %1$s is the audio format, %2$s is the provider name */
            return new WP_Error('unsupported_stt_format', sprintf(__('Audio format "%1$s" is not supported by %2$s STT.', 'gpt3-ai-content-generator'), $audio_format, $provider));
        }

        return $strategy->transcribe_audio($audio_data, $audio_format, $api_params, $options);
    }

    /**
     * AJAX handler for transcription requests from the frontend.
     */
    public function ajax_transcribe_audio()
    {
        // Handle cross-origin embed requests before nonce validation.
        AIPKit_CORS_Manager::handle_preflight_request();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The frontend nonce is verified below before processing audio payloads.
        $post_data = wp_unslash($_POST);
        $bot_id = isset($post_data['bot_id']) ? absint($post_data['bot_id']) : 0;

        if ($bot_id > 0) {
            $is_cross_origin = AIPKit_CORS_Manager::is_cross_origin();

            if ($is_cross_origin) {
                $is_pro = class_exists('\WPAICG\aipkit_dashboard') && \WPAICG\aipkit_dashboard::is_pro_plan();
                if ($is_pro && !class_exists(FrontendPermissions::class)) {
                    $permissions_path = WPAICG_PLUGIN_DIR . 'lib/chatbot/embed-cors.php';
                    if (file_exists($permissions_path)) {
                        require_once $permissions_path;
                    }
                }
                if (!$is_pro || !class_exists(FrontendPermissions::class)) {
                    wp_send_json_error([
                        'message' => __('Embed feature is not available with your current plan.', 'gpt3-ai-content-generator'),
                        'code'    => 'embed_not_available',
                    ], 403);
                    return;
                }
                $origin_error = FrontendPermissions::check_embed_origin();
                if (is_wp_error($origin_error)) {
                    wp_send_json_error([
                        'message' => $origin_error->get_error_message(),
                        'code'    => $origin_error->get_error_code(),
                    ], 403);
                    return;
                }
            }
        }

        // Use frontend nonce check as this is called from chat UI
        if (!check_ajax_referer('aipkit_frontend_chat_nonce', '_ajax_nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed (nonce).', 'gpt3-ai-content-generator'), 'code' => 'nonce_failure'], 403);
            return;
        }

        if (empty($bot_id)) {
            wp_send_json_error(['message' => __('Bot ID is required for transcription.', 'gpt3-ai-content-generator')], 400);
            return;
        }

        if (get_post_type($bot_id) !== \WPAICG\Chat\Admin\AdminSetup::POST_TYPE || get_post_status($bot_id) !== 'publish') {
            wp_send_json_error(['message' => __('Invalid chatbot specified.', 'gpt3-ai-content-generator')], 400);
            return;
        }
        $bot_settings = $this->bot_storage ? $this->bot_storage->get_chatbot_settings($bot_id) : [];
        if (!$bot_settings) {
            wp_send_json_error(['message' => __('Invalid chatbot specified.', 'gpt3-ai-content-generator')], 400);
            return;
        }
        if (($bot_settings['enable_voice_input'] ?? '0') !== '1') {
            wp_send_json_error(['message' => __('Voice input is not enabled for this chatbot.', 'gpt3-ai-content-generator')], 403);
            return;
        }
        if (!in_array($bot_settings['stt_provider'] ?? '', ['OpenAI', 'Google', 'Azure', 'AIPufferCloud'], true)) {
            wp_send_json_error(['message' => __('Select a speech recognition provider for this chatbot.', 'gpt3-ai-content-generator')], 400);
            return;
        }
        $rate_check = \WPAICG\Core\TokenManager\AIPKit_Token_Manager::check_public_request_rate();
        if (is_wp_error($rate_check)) {
            wp_send_json_error(['message' => $rate_check->get_error_message(), 'code' => $rate_check->get_error_code()], $rate_check->get_error_data()['status']);
            return;
        }

        require_once WPAICG_PLUGIN_DIR . 'classes/chatbot/pricing-context.php';
        $user_id = get_current_user_id() ?: null;
        $session_id = isset($post_data['session_id']) ? sanitize_text_field($post_data['session_id']) : null;
        $tokens = new \WPAICG\Core\TokenManager\AIPKit_Token_Manager();
        $usage_context = \WPAICG\Chat\Core\Pricing\build_speech_pricing_context_logic($bot_settings, 'stt');
        $allowance = $tokens->check_and_reset_tokens($user_id, $session_id, $bot_id, 'chat', $usage_context);
        if (is_wp_error($allowance)) {
            $error_data = $allowance->get_error_data();
            wp_send_json_error(['message' => $allowance->get_error_message(), 'code' => $allowance->get_error_code()], is_array($error_data) ? (int) ($error_data['status'] ?? 400) : 400);
            return;
        }

        // Prepare options early
        $options = ['bot_id' => $bot_id, 'provider' => $bot_settings['stt_provider']];
        if (isset($post_data['language'])) {
            $options['language'] = sanitize_text_field((string) $post_data['language']);
        }

        $audio_data_binary = '';
        $audio_format = 'webm'; // default fallback

        // 1. Preferred path: multipart file upload (avoids large base64 payloads that WAFs like WordFence can flag)
        $audio_file = (isset($_FILES['audio_file']) && is_array($_FILES['audio_file']))
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Frontend nonce is checked above and the upload array is validated before use.
            ? $_FILES['audio_file']
            : null;
        if (
            is_array($audio_file)
            && isset($audio_file['tmp_name'])
            && is_string($audio_file['tmp_name'])
            && $audio_file['tmp_name'] !== ''
            && is_uploaded_file($audio_file['tmp_name'])
        ) {
            $tmp_name = $audio_file['tmp_name'];
            $file_size = isset($audio_file['size']) ? (int) $audio_file['size'] : 0;
            $original_name = sanitize_file_name((string) ($audio_file['name'] ?? 'audio'));

            // Allow filtering max size; default 4MB
            $max_size = (int) apply_filters('aipkit_stt_max_audio_bytes', 4 * 1024 * 1024);
            if ($file_size <= 0) {
                wp_send_json_error(['message' => __('Uploaded audio file is empty.', 'gpt3-ai-content-generator')], 400);
                return;
            }
            if ($file_size > $max_size) {
                /* translators: %d: maximum file size in bytes */
                wp_send_json_error(['message' => sprintf(__('Audio file too large. Max size: %d bytes.', 'gpt3-ai-content-generator'), $max_size)], 413);
                return;
            }

            // MIME detection
            $mime = function_exists('mime_content_type') ? mime_content_type($tmp_name) : ((isset($audio_file['type']) && is_string($audio_file['type'])) ? $audio_file['type'] : '');
            $allowed_mime_map = [
                'audio/webm' => 'webm',
                'audio/wav' => 'wav',
                'audio/x-wav' => 'wav',
                'audio/vnd.wave' => 'wav',
                'audio/mpeg' => 'mp3',
                'audio/mp3' => 'mp3',
                'audio/aiff' => 'aiff',
                'audio/x-aiff' => 'aiff',
                'audio/aac' => 'aac',
                'audio/flac' => 'flac',
                'audio/x-flac' => 'flac',
                'audio/ogg' => 'ogg',
                'audio/ogg; codecs=opus' => 'ogg',
                'audio/mp4' => 'mp4',
                'video/mp4' => 'mp4',
                'application/mp4' => 'mp4',
                'audio/m4a' => 'm4a',
                'audio/x-m4a' => 'm4a',
            ];
            if (isset($allowed_mime_map[$mime])) {
                $audio_format = $allowed_mime_map[$mime];
            } else {
                // Fallback: attempt extension parse
                $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
                if (in_array($ext, ['webm', 'wav', 'mp3', 'aiff', 'aac', 'flac', 'ogg', 'mp4', 'm4a'], true)) {
                    $audio_format = $ext;
                } else {
                    wp_send_json_error(['message' => __('Unsupported audio MIME type.', 'gpt3-ai-content-generator')], 400);
                    return;
                }
            }

            $audio_data_binary = file_get_contents($tmp_name);
            if ($audio_data_binary === false || $audio_data_binary === '') {
                wp_send_json_error(['message' => __('Failed to read uploaded audio file.', 'gpt3-ai-content-generator')], 400);
                return;
            }
        }
        // 2. Legacy path: base64 data (kept for backward compatibility with older frontends)
        else {
            $audio_base64 = isset($post_data['audio_data']) ? (string) $post_data['audio_data'] : '';
            if (strpos($audio_base64, 'base64,') !== false) {
                $audio_base64 = (string) substr($audio_base64, strpos($audio_base64, 'base64,') + 7);
            }
            $audio_data_binary = base64_decode($audio_base64, true); // strict mode
            $audio_format = isset($post_data['audio_format']) ? sanitize_text_field((string) $post_data['audio_format']) : 'webm';
            if (empty($audio_data_binary)) {
                wp_send_json_error(['message' => __('Invalid or empty audio data received.', 'gpt3-ai-content-generator')], 400);
                return;
            }
            // Enforce size check on decoded data too
            $max_size = (int) apply_filters('aipkit_stt_max_audio_bytes', 4 * 1024 * 1024);
            if (strlen($audio_data_binary) > $max_size) {
                /* translators: %d: maximum file size in bytes */
                wp_send_json_error(['message' => sprintf(__('Audio data too large after decoding. Max size: %d bytes.', 'gpt3-ai-content-generator'), $max_size)], 413);
                return;
            }
        }

        // Call the main STT method
        $operation_id = sanitize_text_field($post_data['operation_id'] ?? '');
        if (($bot_settings['stt_provider'] ?? '') === 'AIPufferCloud') { $options['cloud_operation_id'] = $operation_id; }
        $dispatch = function () use ($audio_data_binary, $audio_format, $options) { return $this->speech_to_text($audio_data_binary, $audio_format, $options); };
        $transcription_result = ($bot_settings['stt_provider'] ?? '') === 'AIPufferCloud'
            ? \WPAICG\Speech\AIPKit_Speech_Manager::run_cloud_request($operation_id, $bot_id, 'transcribe', $dispatch)
            : $dispatch();

        if (is_wp_error($transcription_result)) {
            $error_data = $transcription_result->get_error_data();
            $status_code = is_array($error_data)
                ? (int) ($error_data['status'] ?? $error_data['status_code'] ?? 500)
                : 500;
            $payload = ['message' => $transcription_result->get_error_message(), 'code' => $transcription_result->get_error_code()];
            if (!empty($error_data['cloud_operation_id'])) { $payload['operation_id'] = $error_data['cloud_operation_id']; }
            wp_send_json_error($payload, $status_code);
        } else {
            $usage_context = \WPAICG\Chat\Core\Pricing\build_speech_pricing_context_logic($bot_settings, 'stt', $transcription_result);
            $tokens->record_token_usage($user_id, $session_id, $bot_id, $usage_context['fallback_units'], 'chat', $usage_context);
            wp_send_json_success(['transcription' => $transcription_result]);
        }
    }
}

/**
 * Interface for Speech-to-Text (STT) Provider Strategies.
 * Defines the contract for transcribing audio using different services.
 */
interface AIPKit_STT_Provider_Strategy_Interface {

    /**
     * Transcribe audio data to text.
     *
     * @param string $audio_data Binary audio data string (decoded from base64 if needed).
     * @param string $audio_format The format/extension of the audio (e.g., 'wav', 'mp3', 'ogg', 'flac').
     * @param array $api_params Provider-specific API connection parameters (key, region, etc.).
     * @param array $options Transcription options (language, model, etc.).
     * @return string|WP_Error The transcribed text or WP_Error on failure.
     */
    public function transcribe_audio(string $audio_data, string $audio_format, array $api_params, array $options = []);

    /**
     * Get the supported audio input formats for this provider.
     *
     * @return array List of supported formats (e.g., ['wav', 'mp3', 'ogg']).
     */
    public function get_supported_formats(): array;

     /**
     * Get provider-specific request options for wp_remote_request or cURL.
     * @param string $operation The operation (e.g., 'transcribe').
     * @return array Request options.
     */
    public function get_request_options(string $operation): array;

     /**
     * Get API headers required for the request.
     * @param string $api_key (May not be needed for all providers in headers)
     * @param string $operation (e.g., 'transcribe')
     * @return array Key-value array of headers.
     */
    public function get_api_headers(string $api_key, string $operation): array;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This file intentionally uses core WordPress hook names.

/**
 * Abstract Base class for STT Provider Strategies.
 * Provides common helper methods (optional).
 */
abstract class AIPKit_STT_Base_Provider_Strategy implements AIPKit_STT_Provider_Strategy_Interface {

    /**
     * Common helper to parse JSON, returning a WP_Error on failure.
     * @param string $json_string The JSON string to decode.
     * @param string $context Context for error messages (e.g., "OpenAI STT").
     * @return array|WP_Error Decoded array or WP_Error.
     */
    protected function decode_json(string $json_string, string $context) {
        if (trim($json_string) === '') return [];
        $decoded = json_decode($json_string, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            /* translators: %1$s is the context label (e.g., provider name); %2$s is the JSON error message. */
            $error_message = sprintf(__('Failed to parse JSON response from %1$s. Error: %2$s', 'gpt3-ai-content-generator'), $context, json_last_error_msg());
            return new WP_Error('json_decode_error', $error_message);
        }
        return is_array($decoded) ? $decoded : [];
    }

     /**
     * Common helper to parse API errors. Can be overridden by specific strategies.
     * @param mixed $response_body Raw or decoded response body.
     * @param int $status_code HTTP status code.
     * @param string $context Provider context (e.g., "OpenAI STT").
     * @return string User-friendly error message.
     */
    protected function parse_error_response($response_body, int $status_code, string $context): string {
        /* translators: %s: Context for the error (e.g., "OpenAI STT"). */
        $message = sprintf(__('An unknown error occurred with %s.', 'gpt3-ai-content-generator'), $context);
        $decoded = is_string($response_body) ? json_decode($response_body, true) : $response_body;
        if (is_array($decoded)) {
            if (!empty($decoded['error']['message'])) $message = $decoded['error']['message'];
            elseif (!empty($decoded['detail'])) $message = is_string($decoded['detail']) ? $decoded['detail'] : json_encode($decoded['detail']);
            elseif (!empty($decoded['message'])) $message = $decoded['message'];
        } elseif (is_string($response_body)) {
             $message = substr($response_body, 0, 200);
        }
        return trim($message);
    }

    /**
     * Get default request options for wp_remote_request or cURL. Providers can override.
     * @param string $operation The operation (e.g., 'transcribe').
     * @return array Request options.
     */
    public function get_request_options(string $operation): array {
         return [
            'method'     => 'POST',
            'timeout'    => 60,
            'user-agent' => 'AIPKit/' . (defined('WPAICG_VERSION') ? WPAICG_VERSION : '1.0') . '; ' . get_bloginfo('url'),
            'sslverify'  => apply_filters('https_local_ssl_verify', true),
        ];
    }

    /**
     * Get default API headers. Specific strategies can override.
     * @param string $api_key (May not be needed for all providers in headers)
     * @param string $operation (e.g., 'transcribe')
     * @return array Key-value array of headers.
     */
    public function get_api_headers(string $api_key, string $operation): array {
         return [
             // Content-Type might be multipart/form-data or audio/* depending on provider/method
             // Override in specific strategies.
         ];
    }

    /**
     * Transcribe audio data to text.
     *
     * @param string $audio_data Binary audio data string (decoded from base64 if needed).
     * @param string $audio_format The format/extension of the audio (e.g., 'wav', 'mp3', 'ogg', 'flac').
     * @param array $api_params Provider-specific API connection parameters (key, region, etc.).
     * @param array $options Transcription options (language, model, etc.).
     * @return string|WP_Error The transcribed text or WP_Error on failure.
     */
    abstract public function transcribe_audio(string $audio_data, string $audio_format, array $api_params, array $options = []);
    abstract public function get_supported_formats(): array;
}

/**
 * Factory for creating Speech-to-Text Provider Strategy instances.
 * Uses singleton pattern for instances.
 */
class AIPKit_STT_Provider_Strategy_Factory {

    /** @var array<string, AIPKit_STT_Provider_Strategy_Interface> */
    private static $instances = [];

    /**
     * Get the strategy instance for a given STT provider.
     *
     * @param string $provider Provider name ('OpenAI', 'Google', 'Azure').
     * @return AIPKit_STT_Provider_Strategy_Interface|WP_Error The strategy instance or WP_Error if unsupported.
     */
    public static function get_strategy(string $provider) {
        if (isset(self::$instances[$provider])) {
            return self::$instances[$provider];
        }

        if (
            class_exists(AIPKit_Providers::class)
            && !AIPKit_Providers::provider_supports_capability($provider, 'stt')
        ) {
            return new WP_Error(
                'stt_provider_not_supported',
                sprintf(
                    /* translators: %s: The provider name. */
                    __('Speech-to-text is not supported by %s in this integration.', 'gpt3-ai-content-generator'),
                    esc_html($provider)
                ),
                ['status' => 501]
            );
        }

        // Instantiate the strategy
        $class_name = null;
        switch ($provider) {
            case 'OpenAI':     $class_name = AIPKit_STT_OpenAI_Provider_Strategy::class; break;
            case 'Google':     $class_name = AIPKit_STT_Google_Provider_Strategy::class; break;
            case 'Azure':      $class_name = AIPKit_STT_Azure_Provider_Strategy::class; break; // Added Azure class name
            case 'AIPufferCloud': $class_name = AIPKit_STT_Cloud_Provider_Strategy::class; break;
            default:
                /* translators: %s: The provider name. */
                return new WP_Error('unsupported_stt_provider_strategy', sprintf(__('STT Provider strategy "%s" is not supported.', 'gpt3-ai-content-generator'), esc_html($provider)));
        }

        if (class_exists($class_name)) {
            self::$instances[$provider] = new $class_name();
        } else {
            /* translators: %s: The provider name. */
            return new WP_Error('stt_strategy_instantiation_failed', sprintf(__('Failed to load STT strategy for provider: %s', 'gpt3-ai-content-generator'), esc_html($provider)));
        }

        return self::$instances[$provider];
    }
}

/** Cloud STT returns plain transcript text through the existing strategy contract. */
class AIPKit_STT_Cloud_Provider_Strategy extends AIPKit_STT_Base_Provider_Strategy
{
    public function transcribe_audio(string $audio_data, string $audio_format, array $api_params, array $options = [])
    {
        $mime = ['webm' => 'audio/webm', 'mp3' => 'audio/mpeg', 'mpeg' => 'audio/mpeg', 'wav' => 'audio/wav',
            'ogg' => 'audio/ogg', 'mp4' => 'audio/mp4', 'm4a' => 'audio/x-m4a'][strtolower($audio_format)] ?? '';
        $model = $options['stt_model'] ?? (\WPAICG\Cloud\Connection::media_models('transcribe')[0]['id'] ?? '');
        $limits = \WPAICG\Cloud\Connection::media_capabilities('transcribe', $model);
        if ($mime === '' || (!empty($limits['maxInputBytes']) && strlen($audio_data) > $limits['maxInputBytes'])) {
            return new WP_Error('cloud_audio_unsupported', __('This recording is too large or uses an unsupported format. Record a shorter clip.', 'gpt3-ai-content-generator'), ['status' => 413]);
        }
        $operation_id = !empty($options['cloud_operation_id']) ? $options['cloud_operation_id'] : wp_generate_uuid4();
        try { $result = \WPAICG\Cloud\Connection::media_request('transcribe', ['audio' => base64_encode($audio_data), 'mime' => $mime, 'model' => $model, 'operationId' => $operation_id]); }
        catch (\RuntimeException $error) {
            $code = $error->getMessage();
            $status = $error->getCode() >= 400 && $error->getCode() < 600 ? $error->getCode() : 503;
            $strategy = \WPAICG\Core\Providers\ProviderStrategyFactory::get_strategy('AIPufferCloud');
            return new WP_Error($code, is_wp_error($strategy) ? __('Cloud could not transcribe this audio.', 'gpt3-ai-content-generator')
                : $strategy->parse_error_response(wp_json_encode(['code' => $code]), $status), array_merge(\WPAICG\Cloud\Connection::request_error_data($code), ['status' => $status, 'cloud_operation_id' => $operation_id]));
        }
        if (!is_string($result['text'] ?? null) || strlen($result['text']) > 100000) {
            return new WP_Error('cloud_transcript_invalid_response', __('The transcript could not be read. Check its status before starting another request.', 'gpt3-ai-content-generator'), ['status' => 502, 'cloud_operation_id' => $operation_id]);
        }
        return $result['text'];
    }

    public function get_supported_formats(): array { return ['webm', 'mp3', 'mpeg', 'wav', 'ogg', 'mp4', 'm4a']; }
}

/**
 * OpenAI Speech-to-Text Provider Strategy.
 * Implements transcription using OpenAI API.
 * Allows specifying the transcription model via options.
 * Uses the shared multipart HTTP transport.
 */
class AIPKit_STT_OpenAI_Provider_Strategy extends AIPKit_STT_Base_Provider_Strategy
{
    /**
    * Constructor. Ensures necessary component classes are loaded.
    */
    public function __construct()
    {
        if (!class_exists(OpenAIUrlBuilder::class)) {
            $url_builder_file = WPAICG_PLUGIN_DIR . 'classes/ai/providers/openai.php';
            if (file_exists($url_builder_file)) {
                require_once $url_builder_file;
            }
        }
    }

    /**
     * Transcribe audio data to text using OpenAI API via the shared multipart transport.
     *
     * @param string $audio_data Binary audio data string.
     * @param string $audio_format The format/extension of the audio (e.g., 'wav', 'mp3', 'webm').
     * @param array $api_params Must include 'api_key'. Optional: 'base_url', 'api_version'.
     * @param array $options Transcription options (e.g., language, stt_model).
     * @return string|WP_Error The transcribed text or WP_Error on failure.
     */
    public function transcribe_audio(string $audio_data, string $audio_format, array $api_params, array $options = [])
    {
        $api_key = $api_params['api_key'] ?? null;
        if (empty($api_key)) {
            return new WP_Error('openai_stt_missing_key', __('OpenAI API Key is required for transcription.', 'gpt3-ai-content-generator'));
        }

        // Ensure URL builder is loaded
        if (!class_exists(OpenAIUrlBuilder::class)) {
            return new WP_Error('openai_stt_dependency_missing', __('OpenAI URL Builder component is missing.', 'gpt3-ai-content-generator'), ['status' => 500]);
        }

        // Build URL using the builder
        $url_builder_params = [
            // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Provider-specific API transport.
            'base_url' => $api_params['base_url'] ?? 'https://api.openai.com',
            'api_version' => $api_params['api_version'] ?? 'v1',
        ];
        $url = OpenAIUrlBuilder::build('audio/transcriptions', $url_builder_params); // Use correct operation key
        if (is_wp_error($url)) {
            return $url;
        }

        // --- Prepare temporary file ---
        $tmp_filename = wp_tempnam('openai_stt_upload');
        if ($tmp_filename === false) {
            return new WP_Error('stt_tmp_file_error', __('Could not create temporary file for audio upload.', 'gpt3-ai-content-generator'), ['status' => 500]);
        }
        $write_result = file_put_contents($tmp_filename, $audio_data);
        if ($write_result === false) {
            wp_delete_file($tmp_filename); // Clean up
            return new WP_Error('stt_tmp_write_error', __('Could not write audio data to temporary file.', 'gpt3-ai-content-generator'), ['status' => 500]);
        }

        $effective_filename = 'audio.' . strtolower($audio_format);

        $file = [
            'path' => $tmp_filename,
            'filename' => $effective_filename,
            'type' => function_exists('mime_content_type') ? (mime_content_type($tmp_filename) ?: 'application/octet-stream') : 'application/octet-stream',
        ];
        // --- End temporary file ---

        $stt_model = !empty($options['stt_model'])
            ? sanitize_text_field($options['stt_model'])
            : AIPKit_Model_Catalog::get_default_id('OpenAISTT');
        $stt_model = AIPKit_Model_Catalog::sanitize_openai_file_transcription_model($stt_model);

        // --- Prepare multipart fields ---
        $post_fields = [
            'model' => $stt_model, // *** Use dynamic model ***
        ];
        if (!empty($options['language'])) {
            $post_fields['language'] = sanitize_text_field($options['language']);
        }

        $request_options = $this->get_request_options('transcribe');
        $request_options['headers'] = $this->get_api_headers($api_key, 'transcribe');
        try {
            $response = AIPKit_HTTP_Request::multipart($url, $post_fields, ['file' => $file], $request_options, true);
        } finally {
            wp_delete_file($tmp_filename);
        }
        if (is_wp_error($response)) {
            return $response;
        }
        $body = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);

        // Handle API errors (non-200 status)
        if ($status_code !== 200) {
            $error_msg = $this->parse_error_response($body, $status_code, 'OpenAI STT');
            /* translators: %1$d: HTTP status code, %2$s: Error message from the API. */
            return new WP_Error('openai_stt_api_error', sprintf(__('OpenAI STT API Error (%1$d): %2$s', 'gpt3-ai-content-generator'), $status_code, $error_msg), ['status' => $status_code]);
        }

        // Parse successful response
        $decoded_response = $this->decode_json($body, 'OpenAI STT');
        if (is_wp_error($decoded_response)) {
            return new WP_Error($decoded_response->get_error_code(), $decoded_response->get_error_message(), ['status' => 500]);
        }

        if (isset($decoded_response['text'])) {
            return $decoded_response['text'];
        } else {
            return new WP_Error('openai_stt_no_text', __('Transcription successful but no text found in response.', 'gpt3-ai-content-generator'), ['status' => 500]);
        }
    }

    /**
     * Get supported audio input formats for OpenAI STT.
     */
    public function get_supported_formats(): array
    {
        // Based on OpenAI Whisper documentation (subject to change)
        return ['flac', 'm4a', 'mp3', 'mp4', 'mpeg', 'mpga', 'oga', 'ogg', 'wav', 'webm'];
    }

    /**
     * Get API headers required for OpenAI STT requests.
     * Content-Type is supplied by the multipart transport.
     */
    public function get_api_headers(string $api_key, string $operation): array
    {
        return [
            'Authorization' => 'Bearer ' . $api_key,
        ];
    }

}

/**
 * Gemini speech-to-text strategy backed by the Google Interactions API.
 */
class AIPKit_STT_Google_Provider_Strategy extends AIPKit_STT_Base_Provider_Strategy
{
    /**
     * @return string|WP_Error
     */
    public function transcribe_audio(string $audio_data, string $audio_format, array $api_params, array $options = [])
    {
        $api_key = isset($api_params['api_key']) ? trim((string) $api_params['api_key']) : '';
        if ($api_key === '') {
            return new WP_Error(
                'google_stt_missing_key',
                __('Google API Key is required for transcription.', 'gpt3-ai-content-generator'),
                ['status' => 400, 'status_code' => 400]
            );
        }

        $model = AIPKit_Providers::normalize_google_stt_model($options['stt_model'] ?? '');
        $result = GoogleInteractionsSttAdapter::transcribe(
            [
                'api_key' => $api_key,
                'base_url' => $api_params['base_url'] ?? '',
                'api_version' => 'v1beta',
                'timeout' => 120,
            ],
            $model,
            $audio_data,
            $audio_format,
            isset($options['language']) ? sanitize_text_field((string) $options['language']) : ''
        );
        if (is_wp_error($result)) {
            return $result;
        }

        return trim((string) ($result['content'] ?? ''));
    }

    public function get_supported_formats(): array
    {
        return GoogleInteractionsSttAdapter::supported_formats();
    }
}

/**
 * Azure Speech-to-Text Provider Strategy.
 * Implements transcription using Azure AI Services Speech to Text REST API.
 */
class AIPKit_STT_Azure_Provider_Strategy extends AIPKit_STT_Base_Provider_Strategy
{
    /**
     * Transcribe audio data to text using Azure Speech Service API via the shared multipart transport.
     * API Reference: https://learn.microsoft.com/en-us/azure/ai-services/speech-service/rest-speech-to-text#speech-to-text-rest-api-v31
     * Endpoint Example: {endpoint}/speechtotext/transcriptions:transcribe?api-version=...
     *
     * @param string $audio_data Binary audio data string.
     * @param string $audio_format The format/extension of the audio (e.g., 'wav', 'mp3'). Matched against get_supported_formats().
     * @param array $api_params Must include 'api_key' and 'azure_endpoint'. Optional: 'stt_model'.
     * @param array $options Transcription options (e.g., 'language').
     * @return string|WP_Error Transcribed text or WP_Error.
     */
    public function transcribe_audio(string $audio_data, string $audio_format, array $api_params, array $options = [])
    {
        $api_key = $api_params['api_key'] ?? null;
        $endpoint = $api_params['azure_endpoint'] ?? null;
        $azure_model_id = $api_params['stt_model'] ?? null; // Optional model identifier from settings

        if (empty($api_key)) {
            return new WP_Error('azure_stt_missing_key', __('Azure Subscription Key is required.', 'gpt3-ai-content-generator'));
        }
        if (empty($endpoint)) {
            return new WP_Error('azure_stt_missing_endpoint', __('Azure Endpoint/Region URL is required.', 'gpt3-ai-content-generator'));
        }
        if (!in_array(strtolower($audio_format), $this->get_supported_formats())) {
            /* translators: %s is the audio format */
            return new WP_Error('azure_stt_unsupported_format', sprintf(__('Audio format "%s" is not supported by Azure STT.', 'gpt3-ai-content-generator'), $audio_format));
        }
        // --- Prepare temporary upload file ---
        $tmp_filename = wp_tempnam('azure_stt_upload');
        if ($tmp_filename === false) {
            return new WP_Error('stt_tmp_file_error', __('Could not create temporary file for audio upload.', 'gpt3-ai-content-generator'), ['status' => 500]);
        }
        if (file_put_contents($tmp_filename, $audio_data) === false) {
            wp_delete_file($tmp_filename);
            return new WP_Error('stt_tmp_write_error', __('Could not write audio data to temporary file.', 'gpt3-ai-content-generator'), ['status' => 500]);
        }
        $effective_filename = 'audio.' . strtolower($audio_format);
        $file = [
            'path' => $tmp_filename,
            'filename' => $effective_filename,
            'type' => function_exists('mime_content_type') ? (mime_content_type($tmp_filename) ?: 'application/octet-stream') : 'application/octet-stream',
        ];
        // --- End temporary file ---

        // --- Build URL ---
        // Example: https://YOUR_REGION.api.cognitive.microsoft.com/speechtotext/transcriptions:transcribe?api-version=2024-11-15
        $api_version = '2024-11-15'; // Use a recent, stable version
        $url = rtrim($endpoint, '/') . '/speechtotext/transcriptions:transcribe?api-version=' . $api_version;
        // Add language if provided in options
        if (!empty($options['language'])) {
            $url = add_query_arg('language', sanitize_text_field($options['language']), $url);
        } else {
            // Default to en-US if not provided (Azure requires language)
            $url = add_query_arg('language', 'en-US', $url);
        }

        // --- Prepare Request Definition (JSON part of multipart) ---
        $definition = [
            'displayName' => 'AIPKit Transcription ' . current_time('mysql', 1),
            'description' => 'Transcription requested by AIPKit plugin.',
            'locale' => !empty($options['language']) ? sanitize_text_field($options['language']) : 'en-US',
            'properties' => [
                'wordLevelTimestampsEnabled' => false,
                'diarizationEnabled' => false, // Keep simple for now
                // Add more properties like 'punctuationMode', 'profanityFilterMode' if needed
            ]
        ];
        // Add model identifier if provided in options/api_params
        if (!empty($azure_model_id)) {
            $definition['model'] = ['self' => $azure_model_id]; // Assuming it's a full model URI, adjust if it's just an ID
        }
        $definition_json = wp_json_encode($definition);

        // --- Prepare multipart fields ---
        $post_fields = [
            'definition' => $definition_json,
        ];

        $request_options = $this->get_request_options('transcribe');
        $request_options['headers'] = $this->get_api_headers($api_key, 'transcribe');
        $request_options['connect_timeout'] = 20;
        try {
            $response = AIPKit_HTTP_Request::multipart($url, $post_fields, ['audio' => $file], $request_options, true);
        } finally {
            wp_delete_file($tmp_filename);
        }
        if (is_wp_error($response)) {
            return $response;
        }
        $body = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);

        // Handle API errors (non-200/202 status)
        if ($status_code < 200 || $status_code >= 300) {
            $error_msg = $this->parse_error_response($body, $status_code, 'Azure STT');
            /* translators: %1$d: HTTP status code, %2$s: API error message. */
            return new WP_Error('azure_stt_api_error', sprintf(__('Azure STT API Error (%1$d): %2$s', 'gpt3-ai-content-generator'), $status_code, $error_msg), ['status' => $status_code]);
        }

        // Parse successful response (status code 200)
        $decoded_response = $this->decode_json($body, 'Azure STT');
        if (is_wp_error($decoded_response)) {
            return new WP_Error($decoded_response->get_error_code(), $decoded_response->get_error_message(), ['status' => 500]);
        }

        // Extract transcription text
        $transcribed_text = $decoded_response['combinedPhrases'][0]['text'] ?? null;

        if ($transcribed_text !== null) {
            return trim($transcribed_text);
        } else {
            return new WP_Error('azure_stt_no_text', __('Transcription successful but no text found in response.', 'gpt3-ai-content-generator'), ['status' => 500]);
        }
    }

    /**
     * Get supported audio input formats for Azure STT.
     */
    public function get_supported_formats(): array
    {
        // Common formats supported by Azure Speech Service REST API v3.1
        return ['wav', 'mp3', 'ogg', 'flac', 'mp4', 'webm']; // webm often uses opus or vorbis
    }

    /**
     * Get API headers required for Azure STT requests.
     * Content-Type is supplied by the multipart transport.
     */
    public function get_api_headers(string $api_key, string $operation): array
    {
        return [
            'Ocp-Apim-Subscription-Key' => $api_key,
        ];
    }

}
