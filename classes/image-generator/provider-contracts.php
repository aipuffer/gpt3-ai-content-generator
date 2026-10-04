<?php

namespace WPAICG\Images;

use WPAICG\AIPKit_Providers;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Interface for Image Generation Provider Strategies.
 * Defines the contract for generating images using different services.
 * REVISED: Changed generate_image return type hint to array|WP_Error.
 */
interface AIPKit_Image_Provider_Strategy_Interface {

    /**
     * Generate an image based on a text prompt.
     *
     * @param string $prompt The text prompt describing the image.
     * @param array $api_params Provider-specific API connection parameters (key, region, etc.).
     * @param array $options Generation options (size, quality, style, number of images, etc.).
     * @return array|WP_Error Array of image data objects ([['url'=>..., 'b64_json'=>...], ...]) or WP_Error on failure.
     */
    public function generate_image(string $prompt, array $api_params, array $options = []);

    /**
     * Get the supported image sizes for this provider.
     *
     * @return array List of supported sizes (e.g., ['1024x1024', '512x512']).
     */
    public function get_supported_sizes(): array;

     /**
     * Get provider-specific request options for wp_remote_request or cURL.
     * @param string $operation The operation (e.g., 'generate').
     * @return array Request options.
     */
    public function get_request_options(string $operation): array;

     /**
     * Get API headers required for the request.
     * @param string $api_key API key.
     * @param string $operation The operation (e.g., 'generate').
     * @return array Key-value array of headers.
     */
    public function get_api_headers(string $api_key, string $operation): array;
}

/**
 * Abstract Base class for Image Provider Strategies.
 * Provides common helper methods (optional).
 * REVISED: Changed generate_image return type hint to array|WP_Error.
 */
abstract class AIPKit_Image_Base_Provider_Strategy implements AIPKit_Image_Provider_Strategy_Interface
{
    /**
     * Common helper to parse JSON, returning a WP_Error on failure.
     * @param string $json_string The JSON string to decode.
     * @param string $context Context for error messages (e.g., "OpenAI Image").
     * @return array|WP_Error Decoded array or WP_Error.
     */
    protected function decode_json(string $json_string, string $context)
    {
        if (trim($json_string) === '') {
            return [];
        }
        $decoded = json_decode($json_string, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            /* translators: %1$s: Context for the error (e.g., "OpenAI Image"), %2$s: JSON error message. */
            $error_message = sprintf(__('Failed to parse JSON response from %1$s. Error: %2$s', 'gpt3-ai-content-generator'), $context, json_last_error_msg());
            return new WP_Error('json_decode_error', $error_message);
        }
        return is_array($decoded) ? $decoded : [];
    }

    /**
    * Common helper to parse API errors. Can be overridden by specific strategies.
    * @param mixed $response_body Raw or decoded response body.
    * @param int $status_code HTTP status code.
    * @param string $context Provider context (e.g., "OpenAI Image").
    * @return string User-friendly error message.
    */
    protected function parse_error_response($response_body, int $status_code, string $context): string
    {
        /* translators: %s: Context for the error (e.g., "OpenAI Image"). */
        $message = sprintf(__('An unknown error occurred with %s.', 'gpt3-ai-content-generator'), $context);
        $decoded = is_string($response_body) ? json_decode($response_body, true) : $response_body;

        if (is_array($decoded)) {
            if (!empty($decoded['error']['message'])) {
                $message = $decoded['error']['message'];
            } elseif (!empty($decoded['error']) && is_string($decoded['error'])) {
                $message = $decoded['error'];
            } elseif (!empty($decoded['detail'])) {
                $message = is_string($decoded['detail']) ? $decoded['detail'] : json_encode($decoded['detail']);
            } elseif (!empty($decoded['message'])) {
                $message = $decoded['message'];
            }
        } elseif (is_string($response_body)) {
            $message = substr($response_body, 0, 200);
        }

        return trim($message);
    }

    /**
     * Get default request options for wp_remote_request or cURL. Providers can override.
     * @param string $operation The operation (e.g., 'generate').
     * @return array Request options.
     */
    public function get_request_options(string $operation): array
    {
        return [
           'method'     => 'POST',
           'timeout'    => 120, // Image generation can take longer
           'user-agent' => 'AIPKit/' . (defined('WPAICG_VERSION') ? WPAICG_VERSION : '1.0') . '; ' . get_bloginfo('url'),
           // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This is the existing core WordPress TLS hook.
           'sslverify'  => apply_filters('https_local_ssl_verify', true),
        ];
    }

    /**
     * Get default API headers. Specific strategies can override.
     * @param string $api_key API key.
     * @param string $operation The operation (e.g., 'generate').
     * @return array Key-value array of headers.
     */
    public function get_api_headers(string $api_key, string $operation): array
    {
        return [
            'Content-Type' => 'application/json',
            // Specific providers might add Authorization here
        ];
    }

    // --- Abstract methods from the interface must be implemented by concrete classes ---
    /**
     * @return mixed[]|\WP_Error
     */
    abstract public function generate_image(string $prompt, array $api_params, array $options = []);
    abstract public function get_supported_sizes(): array;
}

/**
 * Factory for creating Image Generation Provider Strategy instances.
 * Uses singleton pattern for instances.
 * Ensures relevant sub-components are loaded when a strategy is requested.
 */
class AIPKit_Image_Provider_Strategy_Factory
{
    /** Shared upload contract for the renderer and every image entry point. */
    public static function edit_upload_constraints(string $provider, string $model = ''): array
    {
        $types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $max = 10 * 1024 * 1024;
        if ($provider === 'OpenAI') { $types = ['image/jpeg', 'image/png', 'image/webp']; }
        if ($provider === 'xAI') { $types = ['image/jpeg', 'image/png']; }
        if ($provider === 'AIPufferCloud') {
            $caps = [];
            foreach (\WPAICG\Cloud\Connection::media_models('image_edit') as $candidate) {
                if ($candidate['id'] === $model) { $caps = $candidate['capabilities'] ?? []; break; }
            }
            $types = array_values(array_intersect(['image/jpeg', 'image/png', 'image/webp'], array_map(static function ($format) { return 'image/' . $format; }, $caps['inputFormats'] ?? [])));
            $max = min($max, max(0, (int) ($caps['maxInputBytes'] ?? 0)));
        }
        $extensions = implode(', ', array_map(static function ($mime) { return $mime === 'image/jpeg' ? 'JPG' : strtoupper(substr($mime, 6)); }, $types));
        return [
            'allowedMimeTypes' => $types, 'maxBytes' => $max,
            /* translators: %s: Supported image extensions. */
            'invalidTypeMessage' => sprintf(__('Invalid image type. Allowed types: %s.', 'gpt3-ai-content-generator'), $extensions),
            /* translators: %s: Maximum image file size. */
            'tooLargeMessage' => sprintf(__('Source image is too large. Maximum allowed size is %s.', 'gpt3-ai-content-generator'), size_format($max, 2)),
        ];
    }
    /** @var array<string, AIPKit_Image_Provider_Strategy_Interface> */
    private static $instances = [];

    /**
     * Get the strategy instance for a given Image Generation provider.
     *
     * @param string $provider Provider name ('OpenAI', 'Azure', 'Replicate', 'Google').
     * @return AIPKit_Image_Provider_Strategy_Interface|WP_Error The strategy instance or WP_Error if unsupported.
     */
    public static function get_strategy(string $provider)
    {
        if (isset(self::$instances[$provider])) {
            return self::$instances[$provider];
        }

        if (
            class_exists(AIPKit_Providers::class)
            && !AIPKit_Providers::provider_supports_capability($provider, 'image_generation')
        ) {
            return new WP_Error(
                'image_provider_not_supported',
                sprintf(
                    /* translators: %s: The provider name. */
                    __('Image generation is not supported by %s in this integration.', 'gpt3-ai-content-generator'),
                    esc_html($provider)
                ),
                ['status' => 501]
            );
        }

        $strategies = [
            'OpenAI'     => Providers\AIPKit_Image_OpenAI_Provider_Strategy::class,
            'Azure'      => Providers\AIPKit_Image_Azure_Provider_Strategy::class,
            'Google'     => Providers\AIPKit_Image_Google_Provider_Strategy::class,
            'OpenRouter' => Providers\AIPKit_Image_OpenRouter_Provider_Strategy::class,
            'xAI'        => Providers\AIPKit_Image_XAI_Provider_Strategy::class,
            'Pexels'     => Providers\AIPKit_Image_Pexels_Provider_Strategy::class,
            'Pixabay'    => Providers\AIPKit_Image_Pixabay_Provider_Strategy::class,
            'Replicate'  => Providers\AIPKit_Image_Replicate_Provider_Strategy::class,
            'AIPufferCloud' => Providers\AIPKit_Image_Cloud_Provider_Strategy::class,
        ];

        if (!isset($strategies[$provider])) {
            /* translators: %s: The provider key that was attempted to be used for image generation. */
            return new WP_Error('unsupported_image_provider_key', sprintf(__('Provider key "%s" is not configured for image strategy loading.', 'gpt3-ai-content-generator'), esc_html($provider)));
        }

        $class_name = $strategies[$provider];
        $strategy_file = __DIR__ . '/providers.php';
        if (!file_exists($strategy_file)) {
            /* translators: %s: The provider name that was attempted to be used for image generation. */
            return new WP_Error('image_strategy_file_not_found', sprintf(__('Image Strategy file not found for provider: %s', 'gpt3-ai-content-generator'), esc_html($provider)));
        }
        require_once $strategy_file;

        if (class_exists($class_name)) {
            self::$instances[$provider] = new $class_name();
        } else {
            /* translators: %s: The provider name that was attempted to be used for image generation. */
            return new WP_Error('image_strategy_instantiation_failed', sprintf(__('Failed to load Image Generation strategy for provider: %s', 'gpt3-ai-content-generator'), esc_html($provider)));
        }

        return self::$instances[$provider];
    }
}
