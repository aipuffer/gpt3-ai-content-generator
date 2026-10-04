<?php

namespace WPAICG\Speech;

use WPAICG\AIPKit_Providers;
use WPAICG\Chat\Utils\Utils as ChatUtils;
use WP_Error;
use WPAICG\Core\Models\AIPKit_Model_Catalog;
use WPAICG\Core\Providers\OpenAI\OpenAIUrlBuilder;
use WPAICG\Core\Providers\Google\Interactions\GoogleInteractionsTtsAdapter;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AIPKit_Speech_Manager
 * Main class for handling Text-to-Speech (TTS) functionality.
 * Routes cleaned text and provider-specific options to the selected TTS strategy.
 */
class AIPKit_Speech_Manager
{
    /** A request belongs to its original visitor and chatbot, independently of guest session IDs. */
    private static function request_owner(int $bot_id): string
    {
        $address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        return wp_hash('speech|' . $bot_id . '|' . (get_current_user_id() ? 'user:' . get_current_user_id() : 'guest:' . $address));
    }

    /** Keep the dispatch marker before calling a paid provider. Replays only check status. */
    public static function run_cloud_request(string $id, int $bot_id, string $operation, callable $dispatch)
    {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $id)) {
            return new WP_Error('invalid_operation_id', __('Reload the chatbot before trying again.', 'gpt3-ai-content-generator'), ['status' => 400]);
        }
        if (!class_exists('\\WPAICG\\AutoGPT\\Cron\\AIPKit_Option_Lock')) {
            require_once WPAICG_PLUGIN_DIR . 'classes/automations/lock.php';
        }
        $key = 'aipkit_speech_request_' . hash('sha256', $id);
        $lock = \WPAICG\AutoGPT\Cron\AIPKit_Option_Lock::acquire($key . '_lock', 600);
        $unknown = new WP_Error('cloud_outcome_unknown', __('The audio request may have completed. Check its status before starting another request.', 'gpt3-ai-content-generator'), ['status' => 409, 'cloud_operation_id' => $id]);
        if ($lock === '') { return $unknown; }
        try {
            if (get_transient($key)) { return $unknown; }
            $record = ['owner' => self::request_owner($bot_id), 'operation' => $operation];
            set_transient($key, $record, DAY_IN_SECONDS);
            if (get_transient($key) !== $record) {
                return new WP_Error('speech_request_storage_failed', __('The audio request could not be saved. Please try again.', 'gpt3-ai-content-generator'), ['status' => 503]);
            }
            return $dispatch();
        } catch (\Throwable $error) {
            return $unknown;
        } finally {
            \WPAICG\AutoGPT\Cron\AIPKit_Option_Lock::release($key . '_lock', $lock);
        }
    }

    public static function request_status(string $id, int $bot_id)
    {
        $record = get_transient('aipkit_speech_request_' . hash('sha256', $id));
        if (!is_array($record) || !hash_equals($record['owner'] ?? '', self::request_owner($bot_id))) {
            return new WP_Error('speech_request_missing', __('This audio request is unavailable. Ask the site administrator to check usage before trying again.', 'gpt3-ai-content-generator'), ['status' => 404]);
        }
        try {
            $status = \WPAICG\Cloud\Connection::request_status($id);
            return ['state' => sanitize_key($status['state'] ?? 'unknown')];
        } catch (\RuntimeException $error) {
            if ($error->getMessage() === 'request_not_found') {
                return new WP_Error('speech_request_missing', __('This audio request is unavailable. Ask the site administrator to check usage before trying again.', 'gpt3-ai-content-generator'), ['status' => 404]);
            }
            return new WP_Error('speech_status_unavailable', __('The audio status could not be checked. Try checking again later.', 'gpt3-ai-content-generator'), ['status' => 503]);
        }
    }

    public function __construct()
    {
        // Potentially load required dependencies or setup initial state
        // Ensure ChatUtils class is loaded if not using autoloading
        if (!class_exists(ChatUtils::class)) {
            $utils_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/formatting.php';
            if (file_exists($utils_path)) {
                require_once $utils_path;
            }
        }
    }

    /**
     * Register hooks for AJAX actions, etc.
     */
    public function init_hooks()
    {
        // The AJAX handler is now in ConversationAjaxHandler
    }

    /**
     * Converts text to speech using the specified provider.
     * Cleans the text before sending it to the TTS API.
     * @param string $text The text to convert.
     * @param array $options Optional parameters (provider, voice, speed, format etc.).
     *                       Must contain 'provider' and 'voice'.
     *                       For ElevenLabs, can also contain 'elevenlabs_model_id'.
     *                       For OpenAI, can also contain 'openai_model_id'.
     *                       For Google, can also contain 'google_model_id'.
     * @return string|WP_Error Base64 encoded audio data string or WP_Error on failure.
     */
    public function text_to_speech(string $text, array $options = [])
    {
        // 1. Get required options
        $provider = $options['provider'] ?? null;
        $voice_id = $options['voice'] ?? null;
        // Get ElevenLabs model ID if provider is ElevenLabs
        $elevenlabs_model_id = ($provider === 'ElevenLabs' && isset($options['elevenlabs_model_id']))
                                ? $options['elevenlabs_model_id']
                                : null;
        // Get OpenAI model ID if provider is OpenAI
        $openai_model_id = ($provider === 'OpenAI' && isset($options['openai_model_id']))
                                ? $options['openai_model_id']
                                : null;
        $google_model_id = ($provider === 'Google' && isset($options['google_model_id']))
                                ? $options['google_model_id']
                                : null;

        if (empty($provider) || empty($voice_id)) {
            return new WP_Error('missing_tts_options', __('TTS Provider and Voice ID are required.', 'gpt3-ai-content-generator'));
        }
        if (empty($text)) {
            return new WP_Error('empty_tts_text', __('Text cannot be empty for speech generation.', 'gpt3-ai-content-generator'));
        }

        // --- Clean the text before sending to TTS API ---
        if (class_exists(ChatUtils::class)) {
            $cleaned_text = ChatUtils::aipkit_clean_text_for_tts($text);
            if (empty($cleaned_text)) {
                return new WP_Error('empty_cleaned_tts_text', __('Text became empty after cleaning formatting.', 'gpt3-ai-content-generator'));
            }
        } else {
            $cleaned_text = $text; // Fallback if utils class missing
        }
        // --- End: Clean Text ---

        // 3. Get provider strategy
        $strategy = AIPKit_TTS_Provider_Strategy_Factory::get_strategy($provider);
        if (is_wp_error($strategy)) {
            return $strategy;
        }

        // 4. Get API credentials and specific settings
        $api_params = [];
        // Ensure Providers class is loaded
        if (!class_exists(\WPAICG\AIPKit_Providers::class)) {
            $providers_path = WPAICG_PLUGIN_DIR . 'classes/ai/settings.php';
            if (file_exists($providers_path)) {
                require_once $providers_path;
            } else {
                return new WP_Error('dependency_missing', __('Internal configuration error (Providers).', 'gpt3-ai-content-generator'));
            }
        }
        // Fetch provider data using the loaded class
        $provider_data = AIPKit_Providers::get_provider_data($provider);
        $api_params['api_key'] = $provider_data['api_key'] ?? null;
        $api_params['base_url'] = $provider_data['base_url'] ?? null; // Pass base URL if needed
        $api_params['api_version'] = $provider_data['api_version'] ?? null; // Pass API version if needed

        if ($provider !== 'AIPufferCloud' && empty($api_params['api_key'])) {
            /* translators: %s: The provider name that was attempted to be used for TTS generation. */
            return new WP_Error('missing_api_key', sprintf(__('API Key for %s provider is missing in main settings.', 'gpt3-ai-content-generator'), $provider), ['status' => 500]);
        }

        // 5. Prepare synthesis options (pass voice, potentially speed, format etc.)
        $synthesis_options = [
            'voice' => $voice_id,
            'format' => $options['format'] ?? ($provider === 'Google' ? 'wav' : 'mp3'),
        ];
        if ($provider === 'AIPufferCloud') { $synthesis_options['cloud_operation_id'] = $options['cloud_operation_id'] ?? ''; }
        // Add ElevenLabs model ID to synthesis options if available
        if ($provider === 'ElevenLabs' && !empty($elevenlabs_model_id)) {
            $synthesis_options['model_id'] = $elevenlabs_model_id;
        }
        // Add OpenAI model ID to synthesis options if available
        if ($provider === 'OpenAI' && !empty($openai_model_id)) {
            $synthesis_options['model_id'] = $openai_model_id; // Pass the OpenAI TTS model ID
        }
        if ($provider === 'Google' && !empty($google_model_id)) {
            $synthesis_options['model_id'] = $google_model_id;
        }
        if ($provider === 'AIPufferCloud' && !empty($options['cloud_model_id'])) {
            $synthesis_options['model_id'] = $options['cloud_model_id'];
        }
        // Add OpenAI speed if available
        if ($provider === 'OpenAI' && isset($options['speed'])) {
            $synthesis_options['speed'] = $options['speed'];
        }

        // 6. Call strategy's generate method using the CLEANED text
        $result = $strategy->generate_speech($cleaned_text, $api_params, $synthesis_options);

        // 7. Handle result (strategy should return base64 string or WP_Error)
        if (is_wp_error($result)) {
            return $result;
        }

        return $result;
    }
}

/**
 * Interface for Text-to-Speech (TTS) Provider Strategies.
 * Defines the contract for generating speech using different services.
 */
interface AIPKit_TTS_Provider_Strategy_Interface {

    /**
     * Generate speech audio from text.
     *
     * @param string $text The text to synthesize.
     * @param array $api_params Provider-specific API connection parameters (key, region, etc.).
     * @param array $options Synthesis options (voice, speed, format, etc.).
     * @return string|WP_Error Base64 encoded audio data string or WP_Error on failure.
     */
    public function generate_speech(string $text, array $api_params, array $options);

    /**
     * Get the list of available voices for this provider.
     *
     * @param array $api_params Provider-specific API connection parameters.
     * @return array|WP_Error Array of voice objects/data or WP_Error on failure.
     */
    public function get_voices(array $api_params);

    /**
     * Get the supported audio output formats for this provider.
     *
     * @return array List of supported formats (e.g., ['mp3', 'wav', 'ogg']).
     */
    public function get_supported_formats(): array;

     /**
     * Get provider-specific request options for wp_remote_request or cURL.
     * @param string $operation The operation ('generate_speech', 'voices', etc.).
     * @return array Request options.
     */
    public function get_request_options(string $operation): array;

     /**
     * Get API headers required for the request.
     * @param string $api_key (May not be needed for all providers in headers)
     * @param string $operation ('generate_speech', 'voices', etc.)
     * @return array Key-value array of headers.
     */
    public function get_api_headers(string $api_key, string $operation): array;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This file intentionally uses core WordPress hook names.

/**
 * Abstract Base class for TTS Provider Strategies.
 * Provides common helper methods (optional).
 */
abstract class AIPKit_TTS_Base_Provider_Strategy implements AIPKit_TTS_Provider_Strategy_Interface {

    /**
     * Common helper to parse JSON, returning a WP_Error on failure.
     * @param string $json_string The JSON string to decode.
     * @param string $context Context for error messages (e.g., "OpenAI TTS").
     * @return array|WP_Error Decoded array or WP_Error.
     */
    protected function decode_json(string $json_string, string $context) {
        // Prevent decoding empty strings which result in null
        if (trim($json_string) === '') {
            return [];
        }
        $decoded = json_decode($json_string, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // translators: %1$s is the context label (e.g., provider name); %2$s is the JSON error message
            $error_message = sprintf(__('Failed to parse JSON response from %1$s. Error: %2$s', 'gpt3-ai-content-generator'), $context, json_last_error_msg());
            return new WP_Error('json_decode_error', $error_message);
        }
        // Ensure it's an array, even if JSON was valid but not an object/array (e.g. "true")
        return is_array($decoded) ? $decoded : [];
    }

     /**
     * Common helper to parse API errors. Can be overridden by specific strategies.
     * @param mixed $response_body Raw or decoded response body.
     * @param int $status_code HTTP status code.
     * @param string $context Provider context (e.g., "OpenAI TTS").
     * @return string User-friendly error message.
     */
    protected function parse_error_response($response_body, int $status_code, string $context): string {
        /* translators: %s: Context for the error (e.g., "OpenAI TTS"). */
        $message = sprintf(__('An unknown error occurred with %s.', 'gpt3-ai-content-generator'), $context);
        $decoded = is_string($response_body) ? json_decode($response_body, true) : $response_body;

        if (is_array($decoded)) {
            // Check common error structures
            if (!empty($decoded['error']['message'])) {
                $message = $decoded['error']['message'];
            } elseif (!empty($decoded['detail'])) {
                $message = is_string($decoded['detail']) ? $decoded['detail'] : json_encode($decoded['detail']);
            } elseif (!empty($decoded['message'])) {
                $message = $decoded['message'];
            }
        } elseif (is_string($response_body) && strlen($response_body) < 500) { // Show raw body if short
             $message = $response_body;
        }

        return trim($message);
    }

    /**
     * Get default request options for wp_remote_request or cURL. Providers can override.
     * @param string $operation The operation ('generate_speech', 'voices', etc.).
     * @return array Request options.
     */
    public function get_request_options(string $operation): array {
         return [
            'method'     => 'POST', // Default to POST, override for GET operations like 'voices'
            'timeout'    => 60, // Default timeout
            'user-agent' => 'AIPKit/' . (defined('WPAICG_VERSION') ? WPAICG_VERSION : '1.0') . '; ' . get_bloginfo('url'),
            'sslverify'  => apply_filters('https_local_ssl_verify', true),
        ];
    }

    /**
     * Get default API headers. Specific strategies can override if needed.
     * @param string $api_key (May not be needed for all providers in headers)
     * @param string $operation ('generate_speech', 'voices', etc.)
     * @return array Key-value array of headers.
     */
    public function get_api_headers(string $api_key, string $operation): array {
         return [
             'Content-Type' => 'application/json',
             // Specific providers like OpenAI/ElevenLabs would override this
             // to add Authorization headers if necessary. Google uses API key in URL.
         ];
    }

    // Abstract methods from the interface must be implemented by concrete classes
    /**
     * @return string|\WP_Error
     */
    abstract public function generate_speech(string $text, array $api_params, array $options);
    /**
     * @return mixed[]|\WP_Error
     */
    abstract public function get_voices(array $api_params);
    abstract public function get_supported_formats(): array;
}

/**
 * Factory for creating Text-to-Speech Provider Strategy instances.
 * Uses singleton pattern for instances.
 */
class AIPKit_TTS_Provider_Strategy_Factory {

    /** @var array<string, AIPKit_TTS_Provider_Strategy_Interface> */
    private static $instances = [];

    /**
     * Get the strategy instance for a given TTS provider.
     *
     * @param string $provider Provider name ('OpenAI', 'Google', 'ElevenLabs').
     * @return AIPKit_TTS_Provider_Strategy_Interface|WP_Error The strategy instance or WP_Error if unsupported.
     */
    public static function get_strategy(string $provider) {
        if (isset(self::$instances[$provider])) {
            return self::$instances[$provider];
        }

        if (
            class_exists(AIPKit_Providers::class)
            && !AIPKit_Providers::provider_supports_capability($provider, 'tts')
        ) {
            return new WP_Error(
                'tts_provider_not_supported',
                sprintf(
                    /* translators: %s: The provider name. */
                    __('Text-to-speech is not supported by %s in this integration.', 'gpt3-ai-content-generator'),
                    esc_html($provider)
                ),
                ['status' => 501]
            );
        }

        // Instantiate the strategy (Ensure classes use the correct namespace)
        $class_name = null;
        switch ($provider) {
            case 'OpenAI':     $class_name = AIPKit_TTS_OpenAI_Provider_Strategy::class; break; // Correct class name
            case 'Google':     $class_name = AIPKit_TTS_Google_Provider_Strategy::class; break;
            case 'ElevenLabs': $class_name = AIPKit_TTS_ElevenLabs_Provider_Strategy::class; break;
            case 'AIPufferCloud': $class_name = AIPKit_TTS_Cloud_Provider_Strategy::class; break;
            default:
                /* translators: %s: The provider name that was attempted to be used for TTS generation. */
                return new WP_Error('unsupported_tts_provider_strategy', sprintf(__('TTS Provider strategy "%s" is not supported.', 'gpt3-ai-content-generator'), esc_html($provider)));
        }

        if (class_exists($class_name)) {
            self::$instances[$provider] = new $class_name();
        } else {
            /* translators: %s: The provider name that was attempted to be used for TTS generation. */
            return new WP_Error('tts_strategy_instantiation_failed', sprintf(__('Failed to load TTS strategy for provider: %s', 'gpt3-ai-content-generator'), esc_html($provider)));
        }

        return self::$instances[$provider];
    }
}

/**
 * OpenAI Text-to-Speech Provider Strategy.
 * Implements voice fetching and speech generation using OpenAI's TTS API.
 * Uses the selected model ID, with the canonical catalog default as fallback.
 */
class AIPKit_TTS_OpenAI_Provider_Strategy extends AIPKit_TTS_Base_Provider_Strategy {

    /**
     * Constructor. Ensures necessary component classes are loaded.
     */
    public function __construct() {
        if (!class_exists(OpenAIUrlBuilder::class)) {
            $url_builder_file = WPAICG_PLUGIN_DIR . 'classes/ai/providers/openai.php';
            if (file_exists($url_builder_file)) {
                require_once $url_builder_file;
            }
        }
    }

    /**
     * Generates speech audio using OpenAI TTS API.
     *
     * @param string $text The text to synthesize.
     * @param array $api_params Must include 'api_key'. Optional: 'base_url', 'api_version'.
     * @param array $options Must include 'voice' (voice ID) and 'format' (e.g., 'mp3', 'opus').
     *                       May include 'speed' (0.25 to 4.0) and 'model_id'.
     * @return string|WP_Error Base64 encoded audio data string or WP_Error on failure.
     */
    public function generate_speech(string $text, array $api_params, array $options) {
        $api_key = $api_params['api_key'] ?? null;
        $voice = $options['voice'] ?? null;
        $output_format = strtolower($options['format'] ?? 'mp3');
        $speed = isset($options['speed']) ? floatval($options['speed']) : 1.0;
        $model_id = isset($options['model_id']) && !empty($options['model_id'])
                    ? $options['model_id']
                    : AIPKit_Model_Catalog::get_default_id('OpenAITTS');

        if (empty($api_key)) return new WP_Error('openai_tts_missing_key', __('OpenAI API Key is required.', 'gpt3-ai-content-generator'));
        if (empty($voice)) return new WP_Error('openai_tts_missing_voice', __('OpenAI Voice is required.', 'gpt3-ai-content-generator'));
        if (empty($text)) return new WP_Error('openai_tts_empty_text', __('Text cannot be empty.', 'gpt3-ai-content-generator'));
        if (!in_array($voice, array_column($this->get_voices(), 'id'))) return new WP_Error('openai_tts_invalid_voice', __('Invalid OpenAI voice selected.', 'gpt3-ai-content-generator'));
        if (!in_array($output_format, $this->get_supported_formats())) $output_format = 'mp3'; // Default to mp3 if invalid format provided
        $speed = max(0.25, min($speed, 4.0)); // Clamp speed

        // Use OpenAIUrlBuilder for consistency
        if (!class_exists(OpenAIUrlBuilder::class)) {
             return new WP_Error('openai_tts_dependency_missing', __('OpenAI URL Builder component is missing.', 'gpt3-ai-content-generator'), ['status' => 500]);
        }

        $url_builder_params = [
            // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Provider-specific API transport.
            'base_url' => $api_params['base_url'] ?? 'https://api.openai.com',
            'api_version' => $api_params['api_version'] ?? 'v1',
        ];
        $url = OpenAIUrlBuilder::build('audio/speech', $url_builder_params); // Correct operation key based on API structure
        if (is_wp_error($url)) return $url;

        $request_body = [
            'model' => $model_id, // Use the selected/default TTS model ID
            'input' => $text,
            'voice' => $voice,
            'response_format' => $output_format,
            'speed' => $speed,
        ];
        // --- END UPDATE ---

        $request_args = $this->get_request_options('generate_speech');
        $request_args['method'] = 'POST';
        $request_args['headers'] = $this->get_api_headers($api_key, 'generate_speech');
        $request_args['body'] = wp_json_encode($request_body);

        $response = wp_remote_post($url, $request_args);

        if (is_wp_error($response)) {
            return new WP_Error('openai_tts_http_error', __('HTTP error during speech generation.', 'gpt3-ai-content-generator'), ['status' => 503]);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response); // This should be the raw audio data on success

        if ($status_code !== 200) {
            // Try to parse error from body (likely JSON)
            $error_msg = $this->parse_error_response($body, $status_code, 'OpenAI TTS Speech');
            /* translators: %1$d: HTTP status code, %2$s: Error message from the API. */
            return new WP_Error('openai_tts_api_error', sprintf(__('OpenAI Speech API Error (%1$d): %2$s', 'gpt3-ai-content-generator'), $status_code, $error_msg), ['status' => $status_code]);
        }

        if (empty($body)) {
             return new WP_Error('openai_tts_no_audio', __('OpenAI API returned success but no audio data.', 'gpt3-ai-content-generator'), ['status' => 500]);
        }

        // Return base64 encoded audio data
        return base64_encode($body);
    }

    /**
     * @param array $api_params Ignored for OpenAI voices.
     * @return array List of voice objects.
     */
    public function get_voices(array $api_params = []) {
        return AIPKit_Model_Catalog::get_seed_rows('OpenAIVoices');
    }

    /**
     * Returns supported audio formats for OpenAI TTS.
     */
    public function get_supported_formats(): array {
        // Based on OpenAI documentation
        return ['mp3', 'opus', 'aac', 'flac', 'wav', 'pcm'];
    }

     /**
     * Override get_api_headers to include Authorization.
     */
    public function get_api_headers(string $api_key, string $operation): array {
        $headers = parent::get_api_headers($api_key, $operation); // Get base headers (Content-Type)
        $headers['Authorization'] = 'Bearer ' . $api_key; // Add OpenAI specific key
        // Accept header is generally not needed for OpenAI TTS, response type is dictated by request format
        return $headers;
    }
}

/** Cloud TTS keeps the existing strategy return value: base64 MP3 audio. */
class AIPKit_TTS_Cloud_Provider_Strategy extends AIPKit_TTS_Base_Provider_Strategy
{
    public function generate_speech(string $text, array $api_params, array $options)
    {
        if (($options['format'] ?? 'mp3') !== 'mp3' || !is_string($options['voice'] ?? null) || $options['voice'] === '') {
            return new WP_Error('cloud_speech_options_unsupported', __('Choose a Cloud voice and MP3 output.', 'gpt3-ai-content-generator'));
        }
        $model = $options['model_id'] ?? (\WPAICG\Cloud\Connection::media_models('speech_generate')[0]['id'] ?? '');
        $operation_id = !empty($options['cloud_operation_id']) ? $options['cloud_operation_id'] : wp_generate_uuid4();
        try { $result = \WPAICG\Cloud\Connection::media_request('speech_generate', ['text' => $text, 'voice' => $options['voice'], 'model' => $model, 'operationId' => $operation_id]); }
        catch (\RuntimeException $error) {
            $code = $error->getMessage();
            $status = $error->getCode() >= 400 && $error->getCode() < 600 ? $error->getCode() : 503;
            $strategy = \WPAICG\Core\Providers\ProviderStrategyFactory::get_strategy('AIPufferCloud');
            return new WP_Error($code, is_wp_error($strategy) ? __('Cloud could not generate speech.', 'gpt3-ai-content-generator')
                : $strategy->parse_error_response(wp_json_encode(['code' => $code]), $status), array_merge(\WPAICG\Cloud\Connection::request_error_data($code), ['status' => $status, 'cloud_operation_id' => $operation_id]));
        }
        $audio = $result['audio'] ?? null;
        if (!is_array($audio) || ($audio['mime'] ?? '') !== 'audio/mpeg' || !is_string($audio['data'] ?? null)
            || strlen($audio['data']) > 7 * 1024 * 1024 || base64_decode($audio['data'], true) === false) {
            return new WP_Error('cloud_speech_invalid_response', __('The audio response could not be read. Check its status before starting another request.', 'gpt3-ai-content-generator'), ['status' => 502, 'cloud_operation_id' => $operation_id]);
        }
        return $audio['data'];
    }

    public function get_voices(array $api_params) {
        $voices = [];
        foreach (\WPAICG\Cloud\Connection::media_models('speech_generate') as $model) {
            if (!empty($api_params['model_id']) && $model['id'] !== $api_params['model_id']) { continue; }
            $voices = array_merge($voices, $model['capabilities']['voices'] ?? []);
        }
        return array_map(static function ($voice) { return ['id' => $voice, 'name' => ucfirst($voice)]; }, array_values(array_unique($voices)));
    }
    public function get_supported_formats(): array { return ['mp3']; }
}

/**
 * Gemini text-to-speech strategy backed by the Google Interactions API.
 */
class AIPKit_TTS_Google_Provider_Strategy extends AIPKit_TTS_Base_Provider_Strategy
{
    private const SAMPLE_RATE = 24000;
    private const CHANNELS = 1;
    private const BITS_PER_SAMPLE = 16;

    /**
     * @return string|WP_Error Base64-encoded WAV audio.
     */
    public function generate_speech(string $text, array $api_params, array $options)
    {
        $api_key = isset($api_params['api_key']) ? trim((string) $api_params['api_key']) : '';
        if ($api_key === '') {
            return new WP_Error(
                'google_tts_missing_key',
                __('Google API Key is required for speech generation.', 'gpt3-ai-content-generator')
            );
        }
        if (trim($text) === '') {
            return new WP_Error(
                'google_tts_empty_text',
                __('Text cannot be empty for speech generation.', 'gpt3-ai-content-generator')
            );
        }

        $model = AIPKit_Providers::normalize_google_tts_model($options['model_id'] ?? '');
        $voice = AIPKit_Providers::normalize_google_tts_voice($options['voice'] ?? '');
        $result = GoogleInteractionsTtsAdapter::generate(
            [
                'api_key' => $api_key,
                'base_url' => $api_params['base_url'] ?? '',
                'api_version' => 'v1beta',
                'timeout' => 120,
            ],
            $model,
            $text,
            $voice
        );
        if (is_wp_error($result)) {
            return $result;
        }

        $audio = $result['audio_outputs'][0] ?? [];
        $encoded_audio = is_array($audio) && isset($audio['data'])
            ? trim((string) $audio['data'])
            : '';
        $raw_audio = $encoded_audio !== '' ? base64_decode($encoded_audio, true) : false;
        if ($raw_audio === false || $raw_audio === '') {
            return new WP_Error(
                'google_tts_invalid_audio',
                __('Google returned invalid speech audio data.', 'gpt3-ai-content-generator'),
                ['status' => 502, 'status_code' => 502]
            );
        }

        if (substr($raw_audio, 0, 4) === 'RIFF' && substr($raw_audio, 8, 4) === 'WAVE') {
            return $encoded_audio;
        }

        return base64_encode(self::pcm_to_wav($raw_audio));
    }

    /**
     * Gemini voices are a documented static catalog and do not require a sync request.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_voices(array $api_params)
    {
        return AIPKit_Providers::get_google_tts_voices();
    }

    public function get_supported_formats(): array
    {
        return ['wav'];
    }

    private static function pcm_to_wav(string $pcm): string
    {
        $data_length = strlen($pcm);
        $block_align = self::CHANNELS * (self::BITS_PER_SAMPLE / 8);
        $byte_rate = self::SAMPLE_RATE * $block_align;

        return 'RIFF'
            . pack('V', 36 + $data_length)
            . 'WAVEfmt '
            . pack(
                'VvvVVvv',
                16,
                1,
                self::CHANNELS,
                self::SAMPLE_RATE,
                $byte_rate,
                $block_align,
                self::BITS_PER_SAMPLE
            )
            . 'data'
            . pack('V', $data_length)
            . $pcm;
    }
}

/**
 * ElevenLabs Text-to-Speech Provider Strategy.
 * Implements voice fetching and speech generation.
 * ADDED: get_models method to fetch ElevenLabs synthesis models.
 * UPDATED: generate_speech to use model_id from options in the request body.
 */
class AIPKit_TTS_ElevenLabs_Provider_Strategy extends AIPKit_TTS_Base_Provider_Strategy {

    /**
     * Generates speech audio using ElevenLabs API.
     *
     * @param string $text The text to synthesize.
     * @param array $api_params Must include 'api_key'.
     * @param array $options Must include 'voice' (voice_id) and 'format' (e.g., 'mp3').
     *                       May include 'model_id' for ElevenLabs specific synthesis model.
     * @return string|WP_Error Base64 encoded audio data string or WP_Error on failure.
     */
    public function generate_speech(string $text, array $api_params, array $options) {
        $api_key = $api_params['api_key'] ?? null;
        $voice_id = $options['voice'] ?? null; // This is voice_id
        $synthesis_model_id = $options['model_id'] ?? null; // This is the synthesis model_id

        // --- START: Format Handling ---
        $format_from_options = strtolower(trim($options['format'] ?? ''));
        $supported_formats = $this->get_supported_formats();
        $output_format = 'mp3_44100_128'; // Default valid format

        if (!empty($format_from_options)) {
            if (in_array($format_from_options, $supported_formats, true)) {
                // If the passed format is already a valid ElevenLabs specific format
                $output_format = $format_from_options;
            } elseif ($format_from_options === 'mp3') {
                // If generic 'mp3' is passed, use a default specific mp3 format
                $output_format = 'mp3_44100_128'; // Or another mp3_... from the list
            }
        }
        // --- END: Format Handling ---

        if (empty($api_key)) return new WP_Error('elevenlabs_tts_missing_key', __('ElevenLabs API Key is required.', 'gpt3-ai-content-generator'));
        if (empty($voice_id)) return new WP_Error('elevenlabs_tts_missing_voice', __('ElevenLabs Voice ID is required.', 'gpt3-ai-content-generator'));
        if (empty($text)) return new WP_Error('elevenlabs_tts_empty_text', __('Text cannot be empty.', 'gpt3-ai-content-generator'));

        $base_url = !empty($api_params['base_url']) ? rtrim($api_params['base_url'], '/') : 'https://api.elevenlabs.io';
        $api_version = !empty($api_params['api_version']) ? trim($api_params['api_version'], '/') : 'v1';
        $url = "{$base_url}/{$api_version}/text-to-speech/{$voice_id}";

        // Add output_format (ElevenLabs specific) to query string
        $url = add_query_arg('output_format', $output_format, $url);

        $request_body = [
            'text' => $text,
        ];
        // Add model_id to the body if provided
        if (!empty($synthesis_model_id)) {
            $request_body['model_id'] = $synthesis_model_id;
        }

        $request_args = $this->get_request_options('generate_speech');
        $request_args['method'] = 'POST';
        $request_args['headers'] = $this->get_api_headers($api_key, 'generate_speech');
        $request_args['body'] = wp_json_encode($request_body);

        $response = wp_remote_post($url, $request_args);

        if (is_wp_error($response)) {
            return new WP_Error('elevenlabs_tts_http_error', __('HTTP error during speech generation.', 'gpt3-ai-content-generator'), ['status' => 503]);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
            $error_msg = $this->parse_error_response($body, $status_code, 'ElevenLabs TTS Speech');
            /* translators: %1$d: HTTP status code, %2$s: Error message from the API. */
            return new WP_Error('elevenlabs_tts_api_error', sprintf(__('ElevenLabs Speech API Error (%1$d): %2$s', 'gpt3-ai-content-generator'), $status_code, $error_msg), ['status' => $status_code]);
        }

        if (empty($body)) {
            return new WP_Error('elevenlabs_tts_no_audio', __('ElevenLabs API returned success but no audio data.', 'gpt3-ai-content-generator'), ['status' => 500]);
        }

        return base64_encode($body);
    }

    /**
     * Fetches the list of available voices from the ElevenLabs API.
     * API Docs: https://elevenlabs.io/docs/api-reference/get-voices
     *
     * @param array $api_params Must include 'api_key'. Optional: 'base_url', 'api_version'.
     * @return array|WP_Error Array of voice objects/data or WP_Error on failure.
     *                        Voice object structure: ['id' => string, 'name' => string, 'gender' => string (optional), 'accent' => string (optional)]
     */
    public function get_voices(array $api_params) {
        $api_key = $api_params['api_key'] ?? null;
        if (empty($api_key)) {
            return new WP_Error('elevenlabs_tts_missing_key', __('ElevenLabs API Key is required to fetch voices.', 'gpt3-ai-content-generator'));
        }

        $base_url = !empty($api_params['base_url']) ? rtrim($api_params['base_url'], '/') : 'https://api.elevenlabs.io';
        $api_version = !empty($api_params['api_version']) ? trim($api_params['api_version'], '/') : 'v1';
        $url = $base_url . '/' . $api_version . '/voices';

        $request_args = $this->get_request_options('voices');
        $request_args['method'] = 'GET';
        $request_args['headers'] = $this->get_api_headers($api_key, 'voices'); // Use the overridden get_api_headers

        $response = wp_remote_get($url, $request_args);

        if (is_wp_error($response)) {
            return new WP_Error('elevenlabs_tts_http_error', __('HTTP error fetching ElevenLabs voices.', 'gpt3-ai-content-generator'), ['status' => 503]);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
             $error_msg = $this->parse_error_response($body, $status_code, 'ElevenLabs Voices');
             /* translators: %1$d: HTTP status code, %2$s: Error message from the API. */
             return new WP_Error('elevenlabs_tts_api_error', sprintf(__('ElevenLabs Voices API Error (%1$d): %2$s', 'gpt3-ai-content-generator'), $status_code, $error_msg), ['status' => $status_code]);
        }

        $decoded = $this->decode_json($body, 'ElevenLabs Voices');
        if (is_wp_error($decoded)) {
             return new WP_Error($decoded->get_error_code(), $decoded->get_error_message(), ['status' => 500]);
        }

        $voices_raw = $decoded['voices'] ?? [];
        $formatted_voices = [];
        if (is_array($voices_raw)) {
             foreach ($voices_raw as $voice) {
                if (!isset($voice['voice_id']) || !isset($voice['name'])) continue;

                $labels = $voice['labels'] ?? [];
                $gender = strtolower($labels['gender'] ?? '');
                $accent = $labels['accent'] ?? '';
                $description = $labels['description'] ?? '';

                $display_name = $voice['name'];
                $details = [];
                if ($gender) $details[] = ucfirst($gender);
                if ($accent) $details[] = ucfirst($accent);
                if ($description) $details[] = ucfirst($description);
                if (!empty($details)) $display_name .= ' (' . implode(', ', $details) . ')';

                $formatted_voices[] = [
                    'id' => $voice['voice_id'],
                    'name' => $display_name,
                    'gender' => $gender,
                    'accent' => $accent,
                    'category' => $voice['category'] ?? '',
                ];
            }
            usort($formatted_voices, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        }

        return $formatted_voices;
    }

    /**
     * NEW: Fetches the list of available synthesis models from the ElevenLabs API.
     * API Docs: https://elevenlabs.io/docs/api-reference/get-models
     *
     * @param array $api_params Must include 'api_key'. Optional: 'base_url', 'api_version'.
     * @return array|WP_Error Array of model objects/data or WP_Error on failure.
     *                        Model object structure: ['model_id' => string, 'name' => string, ...]
     */
    public function get_models(array $api_params) {
        $api_key = $api_params['api_key'] ?? null;
        if (empty($api_key)) {
            return new WP_Error('elevenlabs_tts_missing_key', __('ElevenLabs API Key is required to fetch models.', 'gpt3-ai-content-generator'));
        }

        $base_url = !empty($api_params['base_url']) ? rtrim($api_params['base_url'], '/') : 'https://api.elevenlabs.io';
        $api_version = !empty($api_params['api_version']) ? trim($api_params['api_version'], '/') : 'v1';
        $url = $base_url . '/' . $api_version . '/models';

        $request_args = $this->get_request_options('models'); // Use base options
        $request_args['method'] = 'GET';
        $request_args['headers'] = $this->get_api_headers($api_key, 'models'); // Use the overridden get_api_headers

        $response = wp_remote_get($url, $request_args);

        if (is_wp_error($response)) {
            return new WP_Error('elevenlabs_tts_http_error', __('HTTP error fetching ElevenLabs models.', 'gpt3-ai-content-generator'), ['status' => 503]);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
             $error_msg = $this->parse_error_response($body, $status_code, 'ElevenLabs Models');
             /* translators: %1$d: HTTP status code, %2$s: Error message from the API. */
             return new WP_Error('elevenlabs_tts_api_error', sprintf(__('ElevenLabs Models API Error (%1$d): %2$s', 'gpt3-ai-content-generator'), $status_code, $error_msg), ['status' => $status_code]);
        }

        $decoded_models = $this->decode_json($body, 'ElevenLabs Models');
        if (is_wp_error($decoded_models)) {
             return new WP_Error($decoded_models->get_error_code(), $decoded_models->get_error_message(), ['status' => 500]);
        }

        // The API returns an array of model objects directly
        $formatted_models = [];
        if (is_array($decoded_models)) {
             foreach ($decoded_models as $model) {
                if (!isset($model['model_id'])) continue;
                // We can include more fields if needed by the UI
                $formatted_models[] = [
                    'id'   => $model['model_id'],
                    'name' => $model['name'] ?? $model['model_id'],
                    // Optionally add other fields from the response like 'description', 'can_do_text_to_speech'
                    'can_do_text_to_speech' => $model['can_do_text_to_speech'] ?? false,
                    'description' => $model['description'] ?? '',
                ];
            }
            // Filter out models that cannot do text-to-speech
            $formatted_models = array_filter($formatted_models, fn($m) => $m['can_do_text_to_speech'] === true);
            usort($formatted_models, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        }

        return $formatted_models;
    }

    /**
     * Returns supported audio formats for ElevenLabs.
     */
    public function get_supported_formats(): array {
        // From ElevenLabs documentation (as of late 2023 / early 2024)
        // https://elevenlabs.io/docs/api-reference/text-to-speech#output_format
        return [
            'mp3_22050_32', 'mp3_44100_32', 'mp3_44100_64', 'mp3_44100_96', 'mp3_44100_128', 'mp3_44100_192',
            'pcm_8000', 'pcm_16000', 'pcm_22050', 'pcm_24000', 'pcm_44100', 'pcm_48000',
            'ulaw_8000', 'alaw_8000', // Added alaw_8000 as per user's error message list
            'opus_48000_32', 'opus_48000_64', 'opus_48000_96', 'opus_48000_128', 'opus_48000_192' // Added opus formats
        ];
    }

    /**
     * Override get_api_headers to include xi-api-key for ElevenLabs.
     */
    public function get_api_headers(string $api_key, string $operation): array {
        $headers = parent::get_api_headers($api_key, $operation); // Get base headers (Content-Type)
        $headers['xi-api-key'] = $api_key; // Add ElevenLabs specific key
        if ($operation === 'generate_speech') {
            // ElevenLabs often expects 'application/json' for request but returns 'audio/mpeg' for response
            // The parent sets Content-Type. Accept is managed by wp_remote_post based on response.
            $headers['Accept'] = 'audio/mpeg'; // Or other audio formats if supported
        } elseif ($operation === 'voices' || $operation === 'models') {
            $headers['Accept'] = 'application/json';
        }
        return $headers;
    }
}
