<?php
/**
 * Shared image provider family and its OpenAI image / Google video helpers.
 * Loaded after provider-contracts.php; strategies keep their provider-specific protocols.
 */

namespace WPAICG\Images\Providers\OpenAI;

use WP_Error;
use WPAICG\AIPKit_Providers;
use WPAICG\Utils\AIPKit_Prompt_Sanitizer;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles building API URLs specific to the OpenAI Image Generation provider.
 */
class OpenAIImageUrlBuilder {

    const IMAGES_GENERATIONS_ENDPOINT = '/images/generations';
    const IMAGES_EDITS_ENDPOINT = '/images/edits';

    /**
     * Build the full API endpoint URL for OpenAI image generation.
     *
     * @param string $operation Expected to be 'images/generations' or 'images/edits'.
     * @param array  $params Required parameters (base_url, api_version).
     * @return string|WP_Error The full URL or WP_Error.
     */
    public static function build(string $operation = 'images/generations', array $params = []) {
        // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Provider-specific API transport.
        $base_url = !empty($params['base_url']) ? rtrim($params['base_url'], '/') : 'https://api.openai.com';
        $api_version = !empty($params['api_version']) ? $params['api_version'] : 'v1';

        if (empty($base_url)) {
            return new WP_Error("missing_base_url_openai_image", __('OpenAI Base URL is required for images.', 'gpt3-ai-content-generator'));
        }
        if (empty($api_version)) {
            return new WP_Error("missing_api_version_openai_image", __('OpenAI API Version is required for images.', 'gpt3-ai-content-generator'));
        }

        switch ($operation) {
            case 'images/generations':
                $endpoint = self::IMAGES_GENERATIONS_ENDPOINT;
                break;
            case 'images/edits':
                $endpoint = self::IMAGES_EDITS_ENDPOINT;
                break;
            default:
                $endpoint = null;
                break;
        }

        if ($endpoint === null) {
            // translators: %s is the operation name
            return new WP_Error('unsupported_operation_openai_image', sprintf(__('Operation "%s" not supported for OpenAI Image URL Builder.', 'gpt3-ai-content-generator'), esc_html($operation)));
        }

        // Check if base_url already includes the version path segment
        $version_segment = '/' . trim($api_version, '/');
        if (strpos($base_url, $version_segment) !== false) {
            return $base_url . $endpoint;
        } else {
            return $base_url . $version_segment . $endpoint;
        }
    }
}

/**
 * Handles formatting request payloads for the OpenAI Image Generation API.
 */
class OpenAIPayloadFormatter
{
    private const OPENAI_EDIT_ALLOWED_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    /**
     * Formats the payload for OpenAI image generation.
     *
     * @param string $prompt The text prompt.
     * @param array  $options Generation options including 'model', 'n', 'size', 'quality', 'style', 'response_format', 'user', etc.
     * @return array The formatted request body data.
     */
    public static function format(string $prompt, array $options): array
    {
        $prompt = AIPKit_Prompt_Sanitizer::sanitize($prompt);
        $model = AIPKit_Providers::normalize_openai_image_model(
            isset($options['model']) ? (string) $options['model'] : null
        );

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
        ];

        // Apply options based on the selected model
        if (AIPKit_Providers::is_openai_gpt_image_model($model)) {
            $payload['n'] = 1; // GPT Image models only support n=1
            if (isset($options['size'])) {
                $payload['size'] = $options['size'];
            }

            if (isset($options['quality']) && in_array($options['quality'], ['low', 'medium', 'high', 'auto'], true)) {
                $payload['quality'] = $options['quality'];
            }

            // GPT Image models use output_format (png, jpeg, webp), not response_format
            if (isset($options['output_format']) && in_array($options['output_format'], ['png', 'jpeg', 'webp'], true)) {
                $payload['output_format'] = $options['output_format'];
            } else {
                $payload['output_format'] = 'png';
            } // Default to png if not specified for GPT Image models

            // Additional GPT Image parameters if present in options
            if (
                isset($options['background'])
                && in_array($options['background'], ['opaque', 'auto'], true)
            ) {
                $payload['background'] = $options['background'];
            } elseif (
                isset($options['background'])
                && $options['background'] === 'transparent'
                && in_array($payload['output_format'], ['png', 'webp'], true)
                && AIPKit_Providers::openai_image_model_supports_transparent_background($model)
            ) {
                $payload['background'] = $options['background'];
            }
            if (isset($options['moderation']) && in_array($options['moderation'], ['auto', 'low'], true)) {
                $payload['moderation'] = $options['moderation'];
            }
            if (
                isset($options['output_compression'])
                && in_array($payload['output_format'], ['jpeg', 'webp'], true)
            ) {
                $payload['output_compression'] = max(0, min(absint($options['output_compression']), 100));
            }

        } else {
            $n = isset($options['n']) ? absint($options['n']) : 1;
            $payload['n'] = max(1, min($n, 10));
            if (isset($options['size'])) {
                $payload['size'] = $options['size'];
            }
            if (isset($options['response_format'])) {
                $payload['response_format'] = $options['response_format'];
            }
        }

        // Common parameter for all models
        if (!empty($options['user'])) {
            $payload['user'] = sanitize_text_field($options['user']);
        }

        return $payload;
    }

    /**
     * Build multipart payload data for OpenAI image edits endpoint.
     *
     * @param string $prompt Prompt text.
     * @param array  $options Runtime options (must include source_image and model for edit flow).
     * @return array|WP_Error {
     *   @type string $body         Raw multipart body.
     *   @type string $content_type Content-Type header including boundary.
     * }
     */
    public static function format_edit_multipart(string $prompt, array $options)
    {
        $prompt = AIPKit_Prompt_Sanitizer::sanitize($prompt);
        $model = isset($options['model'])
            ? sanitize_text_field((string) $options['model'])
            : AIPKit_Providers::get_default_openai_image_model();
        if (!self::supports_edit_model($model)) {
            return new WP_Error(
                'openai_edit_model_not_supported',
                __('Selected OpenAI model does not support image editing.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }

        $source_image = $options['source_image'] ?? null;
        if (!is_array($source_image) || empty($source_image['base64_data']) || empty($source_image['mime_type'])) {
            return new WP_Error(
                'openai_edit_missing_source_image',
                __('Source image is required for OpenAI edit mode.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }

        $image_binary = base64_decode((string) $source_image['base64_data'], true);
        if (!is_string($image_binary) || $image_binary === '') {
            return new WP_Error(
                'openai_edit_invalid_source_image',
                __('Invalid source image payload for OpenAI edit mode.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }

        $mime_type = sanitize_text_field((string) $source_image['mime_type']);
        if (!in_array($mime_type, self::OPENAI_EDIT_ALLOWED_MIME_TYPES, true)) {
            return new WP_Error(
                'openai_edit_invalid_source_mime_type',
                __('Selected source image format is not supported for OpenAI edit mode. Allowed: PNG, JPG, WEBP.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }
        $file_name = sanitize_file_name((string) ($source_image['file_name'] ?? 'source-image.png'));
        if ($file_name === '') {
            $file_name = 'source-image.png';
        }

        $fields = [
            'model' => $model,
            'prompt' => $prompt,
            'n' => '1',
        ];

        if (!empty($options['size'])) {
            $fields['size'] = sanitize_text_field((string) $options['size']);
        }

        // GPT-image models use output_format. Keep png default for stable media save.
        $fields['output_format'] = !empty($options['output_format'])
            ? sanitize_text_field((string) $options['output_format'])
            : 'png';

        if (!empty($options['quality'])) {
            $fields['quality'] = sanitize_text_field((string) $options['quality']);
        }
        $background = !empty($options['background'])
            ? sanitize_text_field((string) $options['background'])
            : '';
        if (in_array($background, ['opaque', 'auto'], true)) {
            $fields['background'] = $background;
        } elseif (
            $background === 'transparent'
            && in_array($fields['output_format'], ['png', 'webp'], true)
            && AIPKit_Providers::openai_image_model_supports_transparent_background($model)
        ) {
            $fields['background'] = $background;
        }
        if (AIPKit_Providers::is_openai_gpt_image_model($model) && !empty($options['input_fidelity'])) {
            $input_fidelity = sanitize_text_field((string) $options['input_fidelity']);
            if (in_array($input_fidelity, ['low', 'high'], true)) {
                $fields['input_fidelity'] = $input_fidelity;
            }
        }
        if (!empty($options['output_compression'])) {
            $compression = absint($options['output_compression']);
            $fields['output_compression'] = (string) max(0, min($compression, 100));
        }
        if (!empty($options['user'])) {
            $fields['user'] = sanitize_text_field((string) $options['user']);
        }

        $boundary = '----AIPKitFormBoundary' . wp_generate_password(24, false, false);
        $eol = "\r\n";
        $body = '';

        foreach ($fields as $field_name => $field_value) {
            $body .= '--' . $boundary . $eol;
            $body .= 'Content-Disposition: form-data; name="' . $field_name . '"' . $eol . $eol;
            $body .= $field_value . $eol;
        }

        $body .= '--' . $boundary . $eol;
        $body .= 'Content-Disposition: form-data; name="image"; filename="' . $file_name . '"' . $eol;
        $body .= 'Content-Type: ' . $mime_type . $eol . $eol;
        $body .= $image_binary . $eol;
        $body .= '--' . $boundary . '--' . $eol;

        return [
            'body' => $body,
            'content_type' => 'multipart/form-data; boundary=' . $boundary,
        ];
    }

    /**
     * Check whether a model is supported for OpenAI image edit in plugin V1.
     *
     * @param string $model Model ID.
     * @return bool
     */
    public static function supports_edit_model(string $model): bool
    {
        return AIPKit_Providers::is_openai_gpt_image_model($model);
    }
}

/**
 * Handles parsing responses from the OpenAI Image Generation API.
 */
class OpenAIImageResponseParser {

    // Token cost estimates for OpenAI image generation (when API doesn't provide usage data)
    const OPENAI_LEGACY_IMAGE_TOKENS_PER_IMAGE = 2000;
    const GPT_IMAGE_TOKENS_PER_IMAGE = 2500;

    /**
     * Parses the successful response from OpenAI Image Generation API.
     *
     * @param array $decoded_response The decoded JSON response body.
     * @param string $model The model used for generation (for fallback token estimation).
     * @param string $prompt The original prompt (for token estimation).
     * @return array Array of image data objects and usage.
     *               Structure: ['images' => [['url'=>..., 'b64_json'=>..., 'revised_prompt'=>...], ...], 'usage' => array|null]
     */
    public static function parse(array $decoded_response, string $model = '', string $prompt = ''): array {
        $images = [];
        $usage = $decoded_response['usage'] ?? null; // Capture usage if available

        if (isset($decoded_response['data']) && is_array($decoded_response['data'])) {
            foreach ($decoded_response['data'] as $imageData) {
                $images[] = [
                    'url'            => $imageData['url'] ?? null,
                    'b64_json'       => $imageData['b64_json'] ?? null,
                    'revised_prompt' => $imageData['revised_prompt'] ?? null,
                ];
            }
        }

        // If OpenAI didn't provide usage data, create fallback estimation
        if ($usage === null && !empty($images)) {
            $usage = self::estimate_token_usage($images, $model, $prompt);
        }

        return ['images' => $images, 'usage' => $usage];
    }

    /**
     * Estimates token usage when OpenAI API doesn't provide it.
     *
     * @param array $images Array of generated images.
     * @param string $model The model used for generation.
     * @param string $prompt The original prompt.
     * @return array Estimated usage data.
     */
    private static function estimate_token_usage(array $images, string $model, string $prompt): array {
        $num_images = count($images);

        // Estimate tokens per image based on model
        $tokens_per_image = AIPKit_Providers::is_openai_gpt_image_model($model)
            ? self::GPT_IMAGE_TOKENS_PER_IMAGE
            : self::OPENAI_LEGACY_IMAGE_TOKENS_PER_IMAGE;

        // Estimate input tokens based on prompt length (rough approximation)
        $prompt_words = str_word_count($prompt);
        $estimated_input_tokens = max(1, intval($prompt_words * 1.3)); // Rough token-to-word ratio

        // Output tokens are based on images generated
        $estimated_output_tokens = $num_images * $tokens_per_image;
        $total_tokens = $estimated_input_tokens + $estimated_output_tokens;

        return [
            'input_tokens' => $estimated_input_tokens,
            'output_tokens' => $estimated_output_tokens,
            'total_tokens' => $total_tokens,
            'provider_raw' => [
                'source' => 'estimated_openai_cost',
                'model' => $model,
                'images_generated' => $num_images,
                'tokens_per_image' => $tokens_per_image,
                'prompt_tokens_estimated' => $estimated_input_tokens,
            ],
        ];
    }

    /**
     * Parses error response from OpenAI API.
     *
     * @param mixed $response_body The raw or decoded error response body.
     * @param int $status_code The HTTP status code.
     * @return string A user-friendly error message.
     */
    public static function parse_error($response_body, int $status_code): string {
        $message = __('An unknown API error occurred.', 'gpt3-ai-content-generator');
        $decoded = is_string($response_body) ? json_decode($response_body, true) : $response_body;

        if (is_array($decoded) && !empty($decoded['error']['message'])) {
            $message = $decoded['error']['message'];
            if (!empty($decoded['error']['code'])) { $message .= ' (Code: ' . $decoded['error']['code'] . ')'; }
            if (!empty($decoded['error']['type'])) { $message .= ' Type: ' . $decoded['error']['type']; }
        } elseif (is_string($response_body)) {
             $message = substr($response_body, 0, 200); // Raw snippet if not JSON
        }

        return trim($message);
    }
}

namespace WPAICG\Images\Providers\Google;

use WP_Error;

/**
 * Handles building API URLs specific to Google Video Generation models (Veo 3).
 */
class GoogleVideoUrlBuilder {

    /**
     * Build the full API endpoint URL for a given Google Video Generation model.
     *
     * @param string $model_id The provider-specific video model ID.
     * @param array  $api_params Required parameters (base_url, api_version, api_key).
     * @param string $operation The operation type ('generate' or 'poll').
     * @return string|WP_Error The full URL or WP_Error.
     */
    public static function build(string $model_id, array $api_params, string $operation = 'generate') {
        // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Provider-specific API transport.
        $base_url = !empty($api_params['base_url']) ? rtrim($api_params['base_url'], '/') : 'https://generativelanguage.googleapis.com';
        $api_version = !empty($api_params['api_version']) ? $api_params['api_version'] : 'v1beta';
        $api_key = !empty($api_params['api_key']) ? $api_params['api_key'] : '';

        if (empty($api_key)) {
            return new WP_Error('missing_google_api_key_for_video_url', __('Google API key is required for video URL construction.', 'gpt3-ai-content-generator'));
        }

        // Handle different operations
        if ($operation === 'generate') {
            // For video generation, use predictLongRunning endpoint for any supported video model
            $endpoint_suffix = ':predictLongRunning';
            // Construct path: /v1beta/models/MODEL_ID:predictLongRunning
            $full_path = '/' . trim($api_version, '/') . '/models/' . urlencode($model_id) . $endpoint_suffix;
            $url_with_key = $base_url . $full_path . '?key=' . urlencode($api_key);

        } elseif ($operation === 'poll') {
            // For polling operation status, we need the operation name passed as model_id
            $operation_name = $model_id; // In this case, model_id is actually the operation name
            $full_path = '/' . trim($api_version, '/') . '/' . $operation_name;
            $url_with_key = $base_url . $full_path . '?key=' . urlencode($api_key);

        } else {
            /* translators: %s: operation name */
            return new WP_Error('unsupported_video_operation', sprintf(__('Unsupported video operation: %s', 'gpt3-ai-content-generator'), $operation));
        }

        return $url_with_key;
    }
}

/**
 * Handles formatting request payloads for Google Video Generation models (Veo 3).
 */
class GoogleVideoPayloadFormatter {

    /**
     * Formats the payload for Google Video Generation API.
     *
     * @param string $prompt The text prompt.
     * @param array  $options Generation options including 'model' (full ID), 'aspect_ratio', 'negative_prompt', etc.
     * @return array The formatted request body data.
     */
    public static function format(string $prompt, array $options): array {
        // Build instances; many video models accept prompt-only instances
        $instance = [ 'prompt' => $prompt ];
        $payload = [ 'instances' => [$instance] ];

        // Optional parameters, applied generically. Models may ignore unknowns.
        $parameters = [];
        if (isset($options['aspect_ratio'])) {
            $parameters['aspectRatio'] = $options['aspect_ratio'];
        }
        if (isset($options['negative_prompt']) && !empty($options['negative_prompt'])) {
            $parameters['negativePrompt'] = $options['negative_prompt'];
        }
        if (isset($options['person_generation'])) {
            $parameters['personGeneration'] = $options['person_generation'];
        }
        if (!empty($parameters)) {
            $payload['parameters'] = $parameters;
        }

        return $payload;
    }
}

/**
 * Handles parsing responses from Google Video Generation models (Veo 3).
 */
class GoogleVideoResponseParser {

    const VEO_TOKENS_PER_VIDEO = 3000; // Define hardcoded token cost for Veo video generation
    const MAX_POLL_ATTEMPTS = 60; // Maximum polling attempts (10 minutes at 10-second intervals)
    const POLL_INTERVAL = 10; // Polling interval in seconds

    /**
     * Parses the initial response from Google Video Generation API - returns operation info for async polling.
     *
     * @param array  $decoded_response The decoded JSON response body from initial request.
     * @param string $model_id The model ID used for the request.
     * @param array  $api_params API connection parameters for polling.
     * @return array|WP_Error Array with operation info for async polling or completed video data.
     */
    public static function parse(array $decoded_response, string $model_id, array $api_params) {

        // Extract operation name from initial response
        $operation_name = $decoded_response['name'] ?? null;

        if (empty($operation_name)) {
            return new WP_Error('no_operation_name', __('No operation name found in Google Video API response.', 'gpt3-ai-content-generator'));
        }

        return [
            'status' => 'processing',
            'operation_name' => $operation_name,
            'model_id' => $model_id,
            'api_params' => $api_params,
            'message' => __('Video generation started. Please wait...', 'gpt3-ai-content-generator')
        ];
    }

    /**
     * Check the status of a video generation operation.
     *
     * @param string $operation_name The operation name to check.
     * @param string $model_id The model ID used for the request.
     * @param array  $api_params API connection parameters.
     * @param string $prompt The original prompt used for generation (optional, for completed operations).
     * @param int|null $user_id The WordPress user ID (optional, for completed operations).
     * @return array|WP_Error Status info or completed video data.
     */
    public static function check_operation_status(string $operation_name, string $model_id, array $api_params, string $prompt = '', ?int $user_id = null, ?callable $before_complete = null) {

        // Poll the operation status
        $poll_result = self::poll_operation($operation_name, $api_params);

        if (is_wp_error($poll_result)) {
            return $poll_result;
        }

        // Check if operation is done
        if (isset($poll_result['done']) && $poll_result['done'] === true) {

            // Operation completed, extract video data
            if (isset($poll_result['response']['generateVideoResponse']['generatedSamples'])) {
                $samples = $poll_result['response']['generateVideoResponse']['generatedSamples'];

                if ($before_complete) { $before_complete(); }
                $videos = [];
                if (is_array($samples) && !empty($samples)) {
                    foreach ($samples as $sample) {
                        if (isset($sample['video']['uri'])) {

                            // Download the video file and get the URL - now includes prompt and user info
                            $video_url = self::download_video($sample['video']['uri'], $api_params, $prompt, $user_id, $model_id);
                            if (!is_wp_error($video_url)) {
                                $videos[] = ['url' => $video_url, 'type' => 'video'];
                            } else {
                                return $video_url;
                            }
                        }
                    }
                } else {
                    return new WP_Error('no_video_samples', __('No video samples found in API response.', 'gpt3-ai-content-generator'));
                }

                // Calculate usage for video generation
                $usage = null;
                $num_videos_generated = count($videos);
                if ($num_videos_generated > 0) {
                    $total_tokens_for_video = $num_videos_generated * self::VEO_TOKENS_PER_VIDEO;
                    $usage = [
                        'input_tokens'  => 0,
                        'output_tokens' => $total_tokens_for_video,
                        'total_tokens'  => $total_tokens_for_video,
                        'provider_raw'  => [
                            'source' => 'hardcoded_veo_cost',
                            'videos_generated' => $num_videos_generated,
                            'cost_per_video_tokens' => self::VEO_TOKENS_PER_VIDEO,
                        ],
                    ];
                }
                                return ['videos' => $videos, 'usage' => $usage, 'status' => 'completed'];

            } else {
                return new WP_Error('no_video_in_response', __('No video data found in completed Google Video API response.', 'gpt3-ai-content-generator'));
            }
        }

        // Check for error in response
        if (isset($poll_result['error'])) {
            $error_message = $poll_result['error']['message'] ?? __('Unknown error occurred during video generation.', 'gpt3-ai-content-generator');
            return new WP_Error('google_video_generation_error', $error_message);
        }

        // Operation still in progress
        return [
            'status' => 'processing',
            'operation_name' => $operation_name,
            'message' => __('Video generation in progress...', 'gpt3-ai-content-generator')
        ];
    }

    /**
     * Polls the operation status.
     *
     * @param string $operation_name The operation name to poll.
     * @param array  $api_params API connection parameters.
     * @return array|WP_Error The polling response or WP_Error.
     */
    private static function poll_operation(string $operation_name, array $api_params) {

        $poll_url = GoogleVideoUrlBuilder::build($operation_name, $api_params, 'poll');

        if (is_wp_error($poll_url)) {
            return $poll_url;
        }

        $response = wp_remote_get($poll_url, [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('poll_http_error', __('HTTP error during Google Video API polling.', 'gpt3-ai-content-generator'));
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        $decoded_response = json_decode($body, true);

        if ($status_code !== 200 || empty($decoded_response)) {
            /* translators: %d: HTTP status code */
            return new WP_Error('poll_api_error', sprintf(__('Google Video API polling error (%d).', 'gpt3-ai-content-generator'), $status_code));
        }

        return $decoded_response;
    }

    /**
     * Downloads the video from the provided URI.
     *
     * @param string $video_uri The URI of the video to download.
     * @param array  $api_params API connection parameters.
     * @param string $prompt The original prompt used for generation.
     * @param int|null $user_id The WordPress user ID to associate with the attachment.
     * @param string $model_id The model ID used for generation.
     * @return string|WP_Error The local video URL or WP_Error on failure.
     */
    private static function download_video(string $video_uri, array $api_params, string $prompt = '', ?int $user_id = null, string $model_id = '') {

        $api_key = $api_params['api_key'] ?? '';

        // Download the video file
        $response = wp_remote_get($video_uri, [
            'timeout' => 120, // Longer timeout for video download
            'headers' => [
                'x-goog-api-key' => $api_key,
            ],
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('video_download_error', __('Failed to download video from Google API.', 'gpt3-ai-content-generator'));
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200) {
            /* translators: %d: HTTP status code */
            return new WP_Error('video_download_http_error', sprintf(__('Video download failed with status %d.', 'gpt3-ai-content-generator'), $status_code));
        }

        $video_data = wp_remote_retrieve_body($response);
        $video_size = strlen($video_data);

        if (empty($video_data)) {
            return new WP_Error('empty_video_data', __('Downloaded video data is empty.', 'gpt3-ai-content-generator'));
        }

        // Check available memory before proceeding
        $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $memory_usage = memory_get_usage(true);
        $available_memory = $memory_limit - $memory_usage;

        if ($memory_limit > 0 && $video_size > $available_memory * 0.8) { // Reserve 20% when PHP has a finite memory limit.
            return new WP_Error('insufficient_memory', __('Insufficient memory to process video file.', 'gpt3-ai-content-generator'));
        }

        // Save video to WordPress uploads directory
        $upload_dir = wp_upload_dir();

        // Check for upload directory errors
        if (!empty($upload_dir['error'])) {
            return new WP_Error('upload_dir_error', __('Upload directory error: ', 'gpt3-ai-content-generator') . $upload_dir['error']);
        }

        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $filesystem_ready = WP_Filesystem();
        global $wp_filesystem;
        if (!$filesystem_ready || !$wp_filesystem) {
            return new WP_Error('filesystem_init_failed', __('Could not initialize filesystem.', 'gpt3-ai-content-generator'));
        }

        // Check if upload directory is writable
        if (!$wp_filesystem->is_writable($upload_dir['path'])) {
            return new WP_Error('upload_dir_not_writable', __('Upload directory is not writable.', 'gpt3-ai-content-generator'));
        }

        $filename = 'veo3_video_' . time() . '_' . wp_generate_password(8, false) . '.mp4';
        $file_path = trailingslashit($upload_dir['path']) . $filename;

        // Write video data to file
        $result = $wp_filesystem->put_contents($file_path, $video_data, FS_CHMOD_FILE);

        if ($result === false) {
            return new WP_Error('video_save_error', __('Failed to save video file to uploads directory.', 'gpt3-ai-content-generator'));
        }

        // Create attachment in media library
        $attachment_title = !empty($prompt) ? substr($prompt, 0, 100) : 'Veo 3 Generated Video';
        $attachment = [
            'guid'           => $upload_dir['url'] . '/' . $filename,
            'post_mime_type' => 'video/mp4',
            'post_title'     => $attachment_title,
            'post_content'   => $prompt ?: '',
            'post_status'    => 'inherit'
        ];

        // Set author if user ID is provided
        if ($user_id && $user_id > 0) {
            $attachment['post_author'] = $user_id;
        }

        $attachment_id = wp_insert_attachment($attachment, $file_path);

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        // Generate metadata for the attachment
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $attachment_data = [];
        try {
            $attachment_data = wp_generate_attachment_metadata($attachment_id, $file_path);

            wp_update_attachment_metadata($attachment_id, $attachment_data);
        } catch (\Exception $e) {
            // Metadata is optional; keep the successfully saved video usable.
        }

        // Add custom meta to identify this as an AI-generated video
        add_post_meta($attachment_id, '_aipkit_generated_video', '1');
        add_post_meta($attachment_id, '_aipkit_video_model', $model_id);
        add_post_meta($attachment_id, '_aipkit_video_provider', 'Google');

        // Add prompt and size metadata (similar to images)
        if (!empty($prompt)) {
            add_post_meta($attachment_id, '_aipkit_video_prompt', $prompt);
        }

        // Add size information if available from metadata
        if (isset($attachment_data['width']) && isset($attachment_data['height'])) {
            $size_string = $attachment_data['width'] . 'x' . $attachment_data['height'];
            add_post_meta($attachment_id, '_aipkit_video_size', $size_string);
        }

        // Add duration if available
        if (isset($attachment_data['length'])) {
            add_post_meta($attachment_id, '_aipkit_video_duration', $attachment_data['length']);
        }

        $final_url = wp_get_attachment_url($attachment_id);

        return $final_url;
    }

    /**
     * Parses error response from Google Video API.
     */
    public static function parse_error(string $response_body, int $status_code): string {
        $decoded = json_decode($response_body, true);

        if (isset($decoded['error']['message'])) {
            return $decoded['error']['message'];
        }

        /* translators: %d: HTTP status code */
        return sprintf(__('Google Video API returned status %d', 'gpt3-ai-content-generator'), $status_code);
    }
}

namespace WPAICG\Images\Providers;

use WPAICG\AIPKit_Providers;
use WPAICG\Images\AIPKit_Image_Base_Provider_Strategy;
use WPAICG\Images\Providers\OpenAI\OpenAIImageUrlBuilder;
use WPAICG\Images\Providers\OpenAI\OpenAIPayloadFormatter;
use WPAICG\Images\Providers\OpenAI\OpenAIImageResponseParser;
use WP_Error;
use WPAICG\Utils\AIPKit_Prompt_Sanitizer;
use WPAICG\Core\Providers\Google\Interactions\GoogleInteractionsClient;
use WPAICG\Core\Providers\Google\Interactions\GoogleInteractionsImageAdapter;
use WPAICG\Core\Providers\Google\Interactions\GoogleInteractionsResponseParser;
use WPAICG\Images\Providers\Google\GoogleVideoPayloadFormatter;
use WPAICG\Images\Providers\Google\GoogleVideoResponseParser;
use WPAICG\Images\Providers\Google\GoogleVideoUrlBuilder;

/**
 * OpenAI Image Generation Provider Strategy.
 * Implements generation and edit flows using OpenAI Images API.
 * Delegates logic to specialized component classes.
 */
class AIPKit_Image_OpenAI_Provider_Strategy extends AIPKit_Image_Base_Provider_Strategy {

    /**
     * Generate an image based on a text prompt using OpenAI image models.
     *
     * @param string $prompt The text prompt describing the image.
     * @param array $api_params API connection parameters ('api_key', 'base_url', 'api_version').
     * @param array $options Generation options merged with defaults ('model', 'n', 'size', 'quality', 'response_format', 'style', 'user', etc.).
     * @return array|WP_Error Array containing 'images' => [['url'=>..., 'b64_json'=>..., 'revised_prompt'=>...], ...], 'usage' => array|null or WP_Error on failure.
     */
    public function generate_image(string $prompt, array $api_params, array $options = []) {
        $api_key = $api_params['api_key'] ?? null;
        if (empty($api_key)) return new WP_Error('openai_image_missing_key', __('OpenAI API Key is required for image generation.', 'gpt3-ai-content-generator'));
        if (empty($prompt)) return new WP_Error('openai_image_missing_prompt', __('Prompt cannot be empty for image generation.', 'gpt3-ai-content-generator'));
        $image_mode = isset($options['image_mode']) && $options['image_mode'] === 'edit' ? 'edit' : 'generate';

        // --- Build URL using image-specific builder ---
        $url_builder_params = [
            // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Provider-specific API transport.
            'base_url' => $api_params['base_url'] ?? 'https://api.openai.com',
            'api_version' => $api_params['api_version'] ?? 'v1',
        ];
        $operation = $image_mode === 'edit' ? 'images/edits' : 'images/generations';
        $url = OpenAIImageUrlBuilder::build($operation, $url_builder_params);
        if (is_wp_error($url)) return $url;

        $headers_array = $this->get_api_headers($api_key, $image_mode);
        $request_options = $this->get_request_options($image_mode);
        $selected_model = isset($options['model']) ? (string) $options['model'] : AIPKit_Providers::get_default_openai_image_model();
        if (AIPKit_Providers::is_openai_gpt_image_model($selected_model)) {
            $request_options['timeout'] = max((int) ($request_options['timeout'] ?? 120), 240);
        }

        if ($image_mode === 'edit') {
            if (empty($options['source_image']) || !is_array($options['source_image'])) {
                return new WP_Error(
                    'openai_edit_missing_source_image',
                    __('Source image is required for OpenAI edit mode.', 'gpt3-ai-content-generator'),
                    ['status' => 400]
                );
            }
            $edit_model = AIPKit_Providers::normalize_openai_image_model(
                isset($options['model']) && is_string($options['model']) ? $options['model'] : null
            );
            $options['model'] = $edit_model;
            if (!OpenAIPayloadFormatter::supports_edit_model($edit_model)) {
                return new WP_Error(
                    'openai_edit_model_not_supported',
                    __('Selected OpenAI model does not support image editing.', 'gpt3-ai-content-generator'),
                    ['status' => 400]
                );
            }

            $multipart_data = OpenAIPayloadFormatter::format_edit_multipart($prompt, $options);
            if (is_wp_error($multipart_data)) {
                return $multipart_data;
            }

            $headers_array['Content-Type'] = $multipart_data['content_type'];
            $request_args = array_merge($request_options, [
                'headers' => $headers_array,
                'body' => $multipart_data['body'],
                'data_format' => 'body',
            ]);
        } else {
            // --- Build Payload using image-specific formatter ---
            $payload = OpenAIPayloadFormatter::format($prompt, $options);
            // --- End Build Payload ---

            $request_body_json = wp_json_encode($payload);
            $request_args = array_merge($request_options, [
                'headers' => $headers_array,
                'body' => $request_body_json,
                'data_format' => 'body',
            ]);
        }

        $response = wp_remote_post($url, $request_args);

        if (is_wp_error($response)) {
            return new WP_Error('openai_image_http_error', __('HTTP error during image generation.', 'gpt3-ai-content-generator'));
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        $decoded_response = $this->decode_json($body, 'OpenAI Image Generation'); // Uses base class helper

        if ($status_code !== 200 || is_wp_error($decoded_response)) {
            $error_msg = is_wp_error($decoded_response)
                        ? $decoded_response->get_error_message()
                        : OpenAIImageResponseParser::parse_error($body, $status_code); // Use image-specific error parser

            /* translators: %1$d: HTTP status code, %2$s: Error message from the API. */
            return new WP_Error('openai_image_api_error', sprintf(__('OpenAI Image API Error (%1$d): %2$s', 'gpt3-ai-content-generator'), $status_code, $error_msg));
        }

        // --- Parse response using image-specific parser ---
        $parsed_data = OpenAIImageResponseParser::parse(
            $decoded_response,
            isset($options['model']) ? (string) $options['model'] : AIPKit_Providers::get_default_openai_image_model(),
            $prompt
        );
        if (!isset($parsed_data['images']) || !is_array($parsed_data['images'])) { // Check if 'images' key exists and is an array

            return new WP_Error('openai_image_no_data_parsed', __('OpenAI API returned success but image data structure is invalid.', 'gpt3-ai-content-generator'));
        }
        // --- End Parse ---

        return $parsed_data; // Returns ['images' => [...], 'usage' => ...]
    }

    /**
     * Get the supported image sizes for OpenAI image generation.
     */
    public function get_supported_sizes(): array {
        return ['1024x1024', '1792x1024', '1024x1792', '1536x1024', '1024x1536', '512x512', '256x256'];
    }

    /**
     * Get API headers required for OpenAI Image requests.
     */
    public function get_api_headers(string $api_key, string $operation): array {
         $headers = ['Authorization' => 'Bearer ' . $api_key];
         if ($operation !== 'edit') {
             $headers['Content-Type'] = 'application/json';
         }
         return $headers;
    }
}

/**
 * Azure Image Generation Provider Strategy.
 * Implements generation using Azure OpenAI image deployments.
 */
class AIPKit_Image_Azure_Provider_Strategy extends AIPKit_Image_Base_Provider_Strategy
{
    /**
     * Build the full API endpoint URL for Azure image generation.
     *
     * @param string $operation Expected to be 'images/generations'.
     * @param array  $params Required parameters ('azure_endpoint', 'deployment', 'api_version_images').
     * @return string|WP_Error The full URL or WP_Error.
     */
    private function build_api_url(string $operation, array $params)
    {
        $endpoint = $params['azure_endpoint'] ?? '';
        $deployment_name = $params['deployment'] ?? '';
        $api_version = $params['api_version_images'] ?? '2025-04-01-preview';
        if ($api_version === '' || $api_version === '2024-04-01-preview') {
            $api_version = '2025-04-01-preview';
        }

        if (empty($endpoint)) {
            return new WP_Error('azure_image_missing_endpoint', __('Azure Endpoint is required.', 'gpt3-ai-content-generator'));
        }
        if (empty($deployment_name)) {
            return new WP_Error('azure_image_missing_deployment', __('Azure Deployment Name (model) is required.', 'gpt3-ai-content-generator'));
        }

        return rtrim($endpoint, '/') . '/openai/deployments/' . urlencode($deployment_name) . '/images/generations?api-version=' . urlencode($api_version);
    }

    /**
     * Generate an image based on a text prompt using Azure image deployments.
     *
     * @param string $prompt The text prompt describing the image.
     * @param array $api_params API connection parameters ('api_key', 'endpoint', 'api_version_images').
     * @param array $options Generation options ('model' which is deployment, 'n', 'size', 'quality', 'output_format', etc.).
     * @return array|WP_Error Array containing 'images' and 'usage' or WP_Error on failure.
     */
    public function generate_image(string $prompt, array $api_params, array $options = [])
    {
        $prompt = AIPKit_Prompt_Sanitizer::sanitize($prompt);
        $api_key = $api_params['api_key'] ?? null;
        if (empty($api_key)) {
            return new WP_Error('azure_image_missing_key', __('Azure API Key is required for image generation.', 'gpt3-ai-content-generator'));
        }

        $url_params = [
            'azure_endpoint' => $api_params['endpoint'] ?? '',
            'deployment' => $options['model'] ?? '',
            'api_version_images' => $api_params['api_version_images'] ?? '2025-04-01-preview',
        ];

        $url = $this->build_api_url('images/generations', $url_params);
        if (is_wp_error($url)) {
            return $url;
        }

        // Build a payload compatible with Azure OpenAI image generation.
        $payload = [
            'model' => sanitize_text_field((string) ($options['model'] ?? '')),
            'prompt' => $prompt,
            'n' => max(1, min(isset($options['n']) ? absint($options['n']) : 1, 10)),
        ];

        $size = $this->sanitize_size($options['size'] ?? '');
        if ($size !== '') {
            $payload['size'] = $size;
        }

        $quality = $this->sanitize_choice($options['quality'] ?? '', ['auto', 'low', 'medium', 'high']);
        if ($quality !== '') {
            $payload['quality'] = $quality;
        }

        $output_format = $this->sanitize_choice($options['output_format'] ?? '', ['png', 'jpeg']);
        $background = $this->sanitize_choice($options['background'] ?? '', ['auto', 'transparent']);
        if ($background === 'transparent') {
            $output_format = 'png';
        }
        if ($output_format !== '') {
            $payload['output_format'] = $output_format;
        }
        if ($background !== '') {
            $payload['background'] = $background;
        }
        if ($output_format === 'jpeg' && isset($options['output_compression']) && $options['output_compression'] !== '') {
            $payload['output_compression'] = max(0, min(absint($options['output_compression']), 100));
        }
        if (!empty($options['user'])) {
            $payload['user'] = sanitize_text_field((string) $options['user']);
        }

        $headers_array = $this->get_api_headers($api_key, 'generate');
        $request_options = $this->get_request_options('generate');
        $request_body_json = wp_json_encode($payload);

        $request_args = array_merge($request_options, [
            'headers' => $headers_array,
            'body' => $request_body_json,
        ]);

        $response = wp_remote_post($url, $request_args);

        if (is_wp_error($response)) {
            return new WP_Error('azure_image_http_error', __('HTTP error during Azure image generation.', 'gpt3-ai-content-generator'));
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded_response = $this->decode_json($body, 'Azure Image Generation');

        if ($status_code !== 200 || is_wp_error($decoded_response)) {
            $error_msg = is_wp_error($decoded_response) ? $decoded_response->get_error_message() : $this->parse_error_response($body, $status_code, 'Azure Image');
            /* translators: %1$d: HTTP status code, %2$s: error message */
            return new WP_Error('azure_image_api_error', sprintf(__('Azure Image API Error (%1$d): %2$s', 'gpt3-ai-content-generator'), $status_code, $error_msg));
        }

        // The response structure is the same as OpenAI's
        $images = [];
        if (isset($decoded_response['data']) && is_array($decoded_response['data'])) {
            foreach ($decoded_response['data'] as $imageData) {
                $images[] = [
                    'url'            => $imageData['url'] ?? null,
                    'b64_json'       => $imageData['b64_json'] ?? null,
                    'revised_prompt' => $imageData['revised_prompt'] ?? null,
                ];
            }
        }
        $estimated_usage = null;
        if (!empty($images)) {
            $num_images = count($images);
            $prompt_words = str_word_count($prompt);
            $estimated_input_tokens = max(1, (int)($prompt_words * 1.3));
            $tokens_per_image = 2000;
            $estimated_output_tokens = $num_images * $tokens_per_image;
            $total_tokens = $estimated_input_tokens + $estimated_output_tokens;

            $estimated_usage = [
                'input_tokens' => $estimated_input_tokens,
                'output_tokens' => $estimated_output_tokens,
                'total_tokens' => $total_tokens,
                'provider_raw' => [
                    'source' => 'estimated_azure_image_cost',
                    'model' => $options['model'] ?? '',
                    'images_generated' => $num_images,
                    'tokens_per_image' => $tokens_per_image,
                    'prompt_tokens_estimated' => $estimated_input_tokens,
                ],
            ];
        }

        return ['images' => $images, 'usage' => $estimated_usage];
    }

    /**
     * Get the supported image sizes for Azure image generation.
     */
    public function get_supported_sizes(): array
    {
        return ['1024x1024', '1536x1024', '1024x1536'];
    }

    /**
     * Get API headers required for Azure requests.
     */
    public function get_api_headers(string $api_key, string $operation): array
    {
        return [
            'Content-Type' => 'application/json',
            'api-key' => $api_key,
        ];
    }

    private function sanitize_choice($value, array $allowed): string
    {
        $value = sanitize_key(is_scalar($value) ? (string) $value : '');

        return in_array($value, $allowed, true) ? $value : '';
    }

    private function sanitize_size($value): string
    {
        $size = sanitize_text_field(is_scalar($value) ? (string) $value : '');
        if ($size === '') {
            return '';
        }
        if ($size === 'auto') {
            return $size;
        }
        if (!preg_match('/^([1-9][0-9]{2,4})x([1-9][0-9]{2,4})$/', $size, $matches)) {
            return '';
        }

        $width = (int) $matches[1];
        $height = (int) $matches[2];
        $fixed_sizes = [
            '1024x1024',
            '1536x1024',
            '1024x1536',
        ];
        if (in_array($size, $fixed_sizes, true)) {
            return $size;
        }

        $long_edge = max($width, $height);
        $short_edge = min($width, $height);
        $pixel_count = $width * $height;

        if ($width % 16 !== 0 || $height % 16 !== 0) {
            return '';
        }
        if ($long_edge > 3840 || $short_edge <= 0 || ($long_edge / $short_edge) > 3) {
            return '';
        }
        if ($pixel_count < 655360 || $pixel_count > 8294400) {
            return '';
        }

        return $size;
    }
}

/**
 * Google media strategy: Interactions for Gemini images and the specialized
 * long-running API for Veo videos.
 */
class AIPKit_Image_Google_Provider_Strategy extends AIPKit_Image_Base_Provider_Strategy
{
    /**
     * @return array<string, mixed>|WP_Error
     */
    public function generate_image(string $prompt, array $api_params, array $options = [])
    {
        $api_key = isset($api_params['api_key']) ? trim((string) $api_params['api_key']) : '';
        $model_id = isset($options['model']) ? trim((string) $options['model']) : '';
        if ($api_key === '') {
            return new WP_Error('google_missing_key', __('Google API Key is required for generation.', 'gpt3-ai-content-generator'));
        }
        if ($model_id === '') {
            return new WP_Error('google_missing_model', __('Google model ID is required.', 'gpt3-ai-content-generator'));
        }
        if (trim($prompt) === '') {
            return new WP_Error('google_missing_prompt', __('Prompt cannot be empty for generation.', 'gpt3-ai-content-generator'));
        }

        // Veo remains on its specialized asynchronous operation API.
        if ($this->is_video_model($model_id)) {
            return $this->generate_video($prompt, $api_params, $options);
        }

        // Transparently converts removed Imagen IDs and unknown stale IDs to
        // the current default, without requiring users to resave settings.
        $model_id = AIPKit_Providers::normalize_google_image_model($model_id);
        $options['model'] = $model_id;
        $image_mode = ($options['image_mode'] ?? 'generate') === 'edit' ? 'edit' : 'generate';
        if ($image_mode === 'edit' && !$this->supports_image_editing_model($model_id)) {
            return new WP_Error(
                'google_model_not_supported_for_edit',
                __('Selected Google model does not support image editing.', 'gpt3-ai-content-generator'),
                ['status' => 400, 'status_code' => 400]
            );
        }

        $request = GoogleInteractionsImageAdapter::build($prompt, $model_id, $options);
        if (is_wp_error($request)) {
            return $request;
        }

        $connection = [
            'api_key' => $api_key,
            'base_url' => $api_params['base_url'] ?? '',
            'api_version' => 'v1beta',
            'timeout' => 180,
        ];
        $image_count = max(1, min(4, (int) ($options['n'] ?? 1)));
        $images = [];
        $usage = self::empty_usage();
        $has_usage = false;
        $client = new GoogleInteractionsClient();

        for ($index = 0; $index < $image_count; $index++) {
            $result = $client->create($connection, $model_id, $request['input'], $request['options']);
            if (is_wp_error($result)) {
                return $result;
            }
            $result = GoogleInteractionsResponseParser::require_image_result($result);
            if (is_wp_error($result)) {
                return $result;
            }

            foreach ($result['image_outputs'] as $image_output) {
                $images[] = [
                    'b64_json' => (string) $image_output['data'],
                    'mime_type' => isset($image_output['mime_type'])
                        ? sanitize_mime_type((string) $image_output['mime_type'])
                        : 'image/png',
                ];
            }
            if (!empty($result['usage']) && is_array($result['usage'])) {
                $has_usage = true;
                self::merge_usage($usage, $result['usage']);
            }
        }

        return [
            'images' => $images,
            'usage' => $has_usage ? $usage : null,
        ];
    }

    private function is_video_model(string $model_id): bool
    {
        $video_models = AIPKit_Providers::get_google_video_models();
        foreach ($video_models as $model) {
            $candidate = is_array($model) ? (string) ($model['id'] ?? '') : (string) $model;
            if ($candidate !== '' && $candidate === $model_id) {
                return true;
            }
        }

        return strpos(strtolower($model_id), 'veo') !== false;
    }

    private function supports_image_editing_model(string $model_id): bool
    {
        return AIPKit_Providers::is_supported_google_image_model($model_id);
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    private function generate_video(string $prompt, array $api_params, array $options)
    {
        $model_id = $options['model'] ?? null;

        $url = GoogleVideoUrlBuilder::build($model_id, $api_params, 'generate');
        if (is_wp_error($url)) {
            return $url;
        }
        $payload = GoogleVideoPayloadFormatter::format($prompt, $options);
        if (empty($payload)) {
            return new WP_Error('google_video_payload_error', __('Failed to format payload for Google video model: ', 'gpt3-ai-content-generator') . $model_id);
        }

        $request_args = array_merge($this->get_request_options('generate'), [
            'headers' => $this->get_api_headers($api_params['api_key'] ?? '', 'generate'),
            'body' => wp_json_encode($payload),
            'data_format' => 'body',
            'timeout' => 120,
        ]);
        $response = wp_remote_post($url, $request_args);
        if (is_wp_error($response)) {
            return new WP_Error('google_video_http_error', __('HTTP error during Google video generation.', 'gpt3-ai-content-generator'));
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded_response = $this->decode_json($body, 'Google Video Generation');
        if ($status_code !== 200 || is_wp_error($decoded_response)) {
            $error_msg = is_wp_error($decoded_response)
                ? $decoded_response->get_error_message()
                : GoogleVideoResponseParser::parse_error($body, $status_code);
            return new WP_Error(
                'google_video_api_error',
                sprintf(
                    /* translators: %1$d: HTTP status code, %2$s: Error message from the API. */
                    __('Google Video API Error (%1$d): %2$s', 'gpt3-ai-content-generator'),
                    $status_code,
                    $error_msg
                )
            );
        }

        $parse_result = GoogleVideoResponseParser::parse($decoded_response, $model_id, $api_params);
        if (is_wp_error($parse_result)) {
            return $parse_result;
        }
        if (($parse_result['status'] ?? '') === 'processing') {
            return [
                'status' => 'processing',
                'operation_name' => $parse_result['operation_name'],
                'message' => $parse_result['message'],
            ];
        }

        return $parse_result;
    }

    public function get_supported_sizes(): array
    {
        return ['1024x1024', '1536x1024', '1024x1536', '1024x768', '768x1024'];
    }

    /**
     * Veo uses API-key query authentication; image requests use Interactions headers.
     */
    public function get_api_headers(string $api_key, string $operation): array
    {
        return ['Content-Type' => 'application/json'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function empty_usage(): array
    {
        return [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'cached_tokens' => 0,
            'thought_tokens' => 0,
            'tool_use_tokens' => 0,
            'provider_raw' => [],
        ];
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $incoming
     */
    private static function merge_usage(array &$target, array $incoming): void
    {
        foreach (['input_tokens', 'output_tokens', 'total_tokens', 'cached_tokens', 'thought_tokens', 'tool_use_tokens'] as $key) {
            $target[$key] += (int) ($incoming[$key] ?? 0);
        }
        if (isset($incoming['provider_raw'])) {
            $target['provider_raw'][] = $incoming['provider_raw'];
        }
    }
}

/**
 * Pexels Image Provider Strategy.
 * Fetches images from Pexels based on a search query.
 */
class AIPKit_Image_Pexels_Provider_Strategy extends AIPKit_Image_Base_Provider_Strategy
{
    /**
     * Generate an image by searching Pexels.
     *
     * @param string $prompt The search query.
     * @param array $api_params API connection parameters.
     * @param array $options Generation options (orientation, size, color, n, page).
     * @return array|WP_Error Array of image data objects or WP_Error on failure.
     */
    public function generate_image(string $prompt, array $api_params, array $options = [])
    {
        $api_key = $api_params['api_key'] ?? null;
        if (empty($api_key)) {
            return new WP_Error('pexels_missing_key', __('Pexels API Key is required.', 'gpt3-ai-content-generator'));
        }

        $query_args = [
            'query' => urlencode($prompt),
            'per_page' => isset($options['n']) ? absint($options['n']) : 1,
        ];

        if (isset($options['page']) && $options['page'] > 0) {
            $query_args['page'] = absint($options['page']);
        }
        if (!empty($options['orientation']) && $options['orientation'] !== 'none' && in_array($options['orientation'], ['landscape', 'portrait', 'square'])) {
            $query_args['orientation'] = $options['orientation'];
        }
        if (!empty($options['size']) && $options['size'] !== 'none' && in_array($options['size'], ['large', 'medium', 'small'])) {
            $query_args['size'] = $options['size'];
        }
        if (!empty($options['color'])) {
            $query_args['color'] = $options['color'];
        }

        $base_url = 'https://api.pexels.com/v1/search';
        $url = add_query_arg($query_args, $base_url);

        $headers = $this->get_api_headers($api_key, 'generate');
        $request_options = $this->get_request_options('generate');
        $request_args = array_merge($request_options, ['headers' => $headers]);

        $response = wp_remote_get($url, $request_args);

        if (is_wp_error($response)) {
            return new WP_Error('pexels_http_error', __('HTTP error during Pexels API request.', 'gpt3-ai-content-generator'));
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
            $error_msg = $this->parse_error_response($body, $status_code, 'Pexels');
            /* translators: %1$d: HTTP status code, %2$s: Error message from the API. */
            return new WP_Error('pexels_api_error', sprintf(__('Pexels API Error (%1$d): %2$s', 'gpt3-ai-content-generator'), $status_code, $error_msg));
        }

        $decoded_response = $this->decode_json($body, 'Pexels Image Search');
        if (is_wp_error($decoded_response)) {
            return $decoded_response;
        }

        $images = [];
        if (isset($decoded_response['photos']) && is_array($decoded_response['photos'])) {
            foreach ($decoded_response['photos'] as $photo) {
                $images[] = [
                    'url' => $photo['src']['large2x'] ?? ($photo['src']['large'] ?? ($photo['src']['original'] ?? '')),
                    'b64_json' => null,
                    'revised_prompt' => null, // Not applicable for Pexels
                    'photographer' => $photo['photographer'] ?? null,
                    'alt' => $photo['alt'] ?? null,
                ];
            }
        }

        return ['images' => $images, 'usage' => null];
    }

    /**
     * Get the supported image sizes for this provider. Pexels uses descriptive sizes.
     */
    public function get_supported_sizes(): array
    {
        return ['large', 'medium', 'small'];
    }

    /**
     * Get provider-specific request options.
     */
    public function get_request_options(string $operation): array
    {
        $options = parent::get_request_options($operation);
        $options['method'] = 'GET';
        return $options;
    }

    /**
     * Get API headers required for the request.
     */
    public function get_api_headers(string $api_key, string $operation): array
    {
        $headers = parent::get_api_headers($api_key, $operation);
        unset($headers['Content-Type']); // Not needed for GET request
        $headers['Authorization'] = $api_key;
        return $headers;
    }
}

/**
 * Pixabay Image Provider Strategy.
 * Fetches images from Pixabay based on a search query.
 */
class AIPKit_Image_Pixabay_Provider_Strategy extends AIPKit_Image_Base_Provider_Strategy
{
    /**
     * Generate an image by searching Pixabay.
     *
     * @param string $prompt The search query.
     * @param array $api_params API connection parameters.
     * @param array $options Generation options (orientation, image_type, category, n, page).
     * @return array|WP_Error Array of image data objects or WP_Error on failure.
     */
    public function generate_image(string $prompt, array $api_params, array $options = [])
    {
        $api_key = $api_params['api_key'] ?? null;
        if (empty($api_key)) {
            return new WP_Error('pixabay_missing_key', __('Pixabay API Key is required.', 'gpt3-ai-content-generator'));
        }

        $num_images_to_fetch = isset($options['n']) ? absint($options['n']) : 1;
        // Pixabay API requires per_page to be between 3 and 200.
        $per_page_for_api = max(3, min($num_images_to_fetch, 200));

        $query_args = [
            'key' => $api_key,
            'q' => urlencode($prompt),
            'per_page' => $per_page_for_api,
            'safesearch' => 'true', // Always use safesearch
        ];

        if (isset($options['page']) && $options['page'] > 0) {
            $query_args['page'] = absint($options['page']);
        }
        if (!empty($options['orientation']) && $options['orientation'] !== 'all') {
            $query_args['orientation'] = $options['orientation'];
        }
        if (!empty($options['image_type']) && $options['image_type'] !== 'all') {
            $query_args['image_type'] = $options['image_type'];
        }
        if (!empty($options['category'])) {
            $query_args['category'] = $options['category'];
        }

        $base_url = 'https://pixabay.com/api/';
        $url = add_query_arg($query_args, $base_url);

        $headers = $this->get_api_headers($api_key, 'generate');
        $request_options = $this->get_request_options('generate');
        $request_args = array_merge($request_options, ['headers' => $headers]);

        $response = wp_remote_get($url, $request_args);

        if (is_wp_error($response)) {
            return new WP_Error('pixabay_http_error', __('HTTP error during Pixabay API request.', 'gpt3-ai-content-generator'));
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
            $error_msg = $this->parse_error_response($body, $status_code, 'Pixabay');
            /* translators: %1$d: HTTP status code, %2$s: Error message from the API. */
            return new WP_Error('pixabay_api_error', sprintf(__('Pixabay API Error (%1$d): %2$s', 'gpt3-ai-content-generator'), $status_code, $error_msg));
        }

        $decoded_response = $this->decode_json($body, 'Pixabay Image Search');
        if (is_wp_error($decoded_response)) {
            return $decoded_response;
        }

        $images = [];
        if (isset($decoded_response['hits']) && is_array($decoded_response['hits'])) {
            // Slice the results to match the originally requested number of images.
            $hits_to_process = array_slice($decoded_response['hits'], 0, $num_images_to_fetch);

            foreach ($hits_to_process as $hit) {
                $images[] = [
                    'url' => $hit['largeImageURL'] ?? ($hit['webformatURL'] ?? ''),
                    'b64_json' => null,
                    'revised_prompt' => null, // Not applicable
                    'photographer' => $hit['user'] ?? null,
                    'alt' => $hit['tags'] ?? null,
                ];
            }
        }

        return ['images' => $images, 'usage' => null];
    }

    /**
     * Get the supported image sizes for this provider.
     */
    public function get_supported_sizes(): array
    {
        return [];
    }

    /**
     * Get provider-specific request options.
     */
    public function get_request_options(string $operation): array
    {
        $options = parent::get_request_options($operation);
        $options['method'] = 'GET';
        return $options;
    }

    /**
     * Get API headers required for the request.
     */
    public function get_api_headers(string $api_key, string $operation): array
    {
        $headers = parent::get_api_headers($api_key, $operation);
        unset($headers['Content-Type']); // Not needed for GET request
        return $headers;
    }
}

/**
 * Replicate Image Generation Provider Strategy.
 * Handles the asynchronous prediction workflow of the Replicate API.
 */
class AIPKit_Image_Replicate_Provider_Strategy extends AIPKit_Image_Base_Provider_Strategy
{
    private const POLLING_INTERVAL = 2; // seconds
    private const POLLING_TIMEOUT_ITERATIONS = 30; // 30 iterations * 2s = 60s timeout
    private const OPTION_FIELD_ALIASES = [
        'aspect_ratio' => ['aspect_ratio'],
        'width' => ['width'],
        'height' => ['height'],
        'negative_prompt' => ['negative_prompt'],
        'guidance' => ['guidance', 'guidance_scale'],
        'num_inference_steps' => ['num_inference_steps', 'num_steps', 'steps'],
        'seed' => ['seed'],
        'output_format' => ['output_format'],
        'output_quality' => ['output_quality', 'quality'],
        'disable_safety_checker' => ['disable_safety_checker'],
    ];

    /**
     * Get API headers required for Replicate requests.
     */
    public function get_api_headers(string $api_key, string $operation): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ];
        if ($operation === 'create_prediction') {
            // Use sync mode and wait up to 50 seconds. This is a tradeoff.
            // A web request can't block forever. Many models will finish in this time.
            $headers['Prefer'] = 'wait=50';
        }
        return $headers;
    }

    /**
     * Get provider-specific request options. Replicate uses GET for polling.
     */
    public function get_request_options(string $operation): array
    {
        $options = parent::get_request_options($operation);
        if ($operation === 'get_prediction' || $operation === 'models') {
            $options['method'] = 'GET';
        }
        // For the creation request, we increase the timeout to match the `Prefer: wait` header value.
        if ($operation === 'create_prediction') {
            $options['timeout'] = 60; // Slightly more than the Prefer:wait value
        }
        return $options;
    }

    /**
     * Get the list of available text-to-image models from Replicate's collection.
     * @return mixed[]|\WP_Error
     */
    public function get_models(array $api_params)
    {
        $api_key = $api_params['api_key'] ?? null;
        if (empty($api_key)) {
            return new WP_Error('replicate_missing_key', __('Replicate API Key is required.', 'gpt3-ai-content-generator'));
        }

        $url = 'https://api.replicate.com/v1/collections/text-to-image';
        $headers = $this->get_api_headers($api_key, 'models');
        $request_options = $this->get_request_options('models');

        $response = wp_remote_get($url, array_merge($request_options, ['headers' => $headers]));
        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
            return new WP_Error('replicate_models_api_error', 'Failed to fetch models: ' . $this->parse_error_response($body, $status_code, 'Replicate Models'));
        }

        $decoded = $this->decode_json($body, 'Replicate Models');
        if (is_wp_error($decoded)) {
            return $decoded;
        }

        $raw_models = $decoded['models'] ?? [];

        // Format to standard structure
        $formatted_models = [];
        foreach ($raw_models as $model) {
            if (!empty($model['latest_version']['id'])) {
                $owner = sanitize_text_field((string) ($model['owner'] ?? ''));
                $name = sanitize_text_field((string) ($model['name'] ?? ''));
                if ($owner === '' || $name === '') {
                    continue;
                }

                $openapi_schema = isset($model['latest_version']['openapi_schema']) && is_array($model['latest_version']['openapi_schema'])
                    ? $model['latest_version']['openapi_schema']
                    : $this->fetch_model_openapi_schema($api_key, $owner, $name);
                $input_schema = $this->extract_input_schema_metadata($openapi_schema);
                $formatted_model = [
                    'id' => $owner . '/' . $name . ':' . sanitize_text_field((string) $model['latest_version']['id']),
                    'name' => $owner . '/' . $name
                ];
                if (!empty($input_schema['fields'])) {
                    $formatted_model['input_schema'] = $input_schema;
                }
                $formatted_models[] = $formatted_model;
            }
        }
        return $formatted_models;
    }

    private function fetch_model_openapi_schema(string $api_key, string $owner, string $name): array
    {
        $url = 'https://api.replicate.com/v1/models/' . rawurlencode($owner) . '/' . rawurlencode($name);
        $headers = $this->get_api_headers($api_key, 'models');
        $request_options = $this->get_request_options('models');
        $request_options['timeout'] = min((int) ($request_options['timeout'] ?? 120), 12);

        $response = wp_remote_get($url, array_merge($request_options, ['headers' => $headers]));
        if (is_wp_error($response)) {
            return [];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return [];
        }

        $decoded = $this->decode_json(wp_remote_retrieve_body($response), 'Replicate Model Schema');
        if (is_wp_error($decoded)) {
            return [];
        }

        return isset($decoded['latest_version']['openapi_schema']) && is_array($decoded['latest_version']['openapi_schema'])
            ? $decoded['latest_version']['openapi_schema']
            : [];
    }

    private function extract_input_schema_metadata(array $openapi_schema): array
    {
        $components = isset($openapi_schema['components']['schemas']) && is_array($openapi_schema['components']['schemas'])
            ? $openapi_schema['components']['schemas']
            : [];
        $properties = $components['Input']['properties'] ?? [];
        if (!is_array($properties)) {
            return ['fields' => []];
        }

        $fields = [];
        foreach (self::OPTION_FIELD_ALIASES as $canonical_field => $input_names) {
            foreach ($input_names as $input_name) {
                if (!isset($properties[$input_name]) || !is_array($properties[$input_name])) {
                    continue;
                }
                $field_schema = $this->resolve_openapi_property_schema($properties[$input_name], $components);
                $normalized = $this->normalize_input_schema_field($field_schema);
                $normalized['input_name'] = $input_name;
                $fields[$canonical_field] = $normalized;
                break;
            }
        }

        return ['fields' => $fields];
    }

    private function resolve_openapi_property_schema(array $property, array $components): array
    {
        $resolved = [];

        if (!empty($property['$ref']) && is_string($property['$ref'])) {
            $ref_name = $this->get_openapi_ref_name($property['$ref']);
            if ($ref_name !== '' && isset($components[$ref_name]) && is_array($components[$ref_name])) {
                $resolved = array_merge($resolved, $this->resolve_openapi_property_schema($components[$ref_name], $components));
            }
        }

        foreach (['allOf', 'anyOf', 'oneOf'] as $compound_key) {
            if (empty($property[$compound_key]) || !is_array($property[$compound_key])) {
                continue;
            }
            foreach ($property[$compound_key] as $sub_schema) {
                if (!is_array($sub_schema)) {
                    continue;
                }
                $resolved = array_merge($resolved, $this->resolve_openapi_property_schema($sub_schema, $components));
            }
        }

        $own_values = $property;
        unset($own_values['$ref'], $own_values['allOf'], $own_values['anyOf'], $own_values['oneOf']);

        return array_merge($resolved, $own_values);
    }

    private function get_openapi_ref_name(string $ref): string
    {
        $parts = explode('/', $ref);
        $name = end($parts);

        return is_string($name) ? sanitize_text_field($name) : '';
    }

    private function normalize_input_schema_field(array $schema): array
    {
        $field = [];
        foreach (['type', 'title', 'description', 'default'] as $key) {
            if (isset($schema[$key]) && is_scalar($schema[$key])) {
                $field[$key] = sanitize_text_field((string) $schema[$key]);
            }
        }
        if (isset($schema['enum']) && is_array($schema['enum'])) {
            $field['enum'] = array_values(array_filter(array_map(
                static fn ($value) => is_scalar($value) ? sanitize_text_field((string) $value) : '',
                $schema['enum']
            ), static fn ($value) => $value !== ''));
        }
        foreach (['minimum', 'maximum'] as $key) {
            if (isset($schema[$key]) && is_numeric($schema[$key])) {
                $field[$key] = (float) $schema[$key];
            }
        }

        return $field;
    }

    /**
     * Generate an image by creating and polling a prediction on Replicate.
     * @return mixed[]|\WP_Error
     */
    public function generate_image(string $prompt, array $api_params, array $options = [])
    {
        $api_key = $api_params['api_key'] ?? null;
        if (empty($api_key)) {
            return new WP_Error('replicate_missing_key', __('Replicate API Key is required.', 'gpt3-ai-content-generator'));
        }
        if (empty($options['model'])) {
            return new WP_Error('replicate_missing_model', __('Replicate model/version ID is required.', 'gpt3-ai-content-generator'));
        }

        // 1. Create Prediction (using sync mode via headers)
        $input_params = ['prompt' => $prompt];
        $schema_fields = $this->get_synced_model_input_fields((string) $options['model']);

        // Get Replicate settings to check for disable_safety_checker
        if (class_exists('\WPAICG\Images\AIPKit_Image_Settings_Ajax_Handler')) {
            $image_settings = \WPAICG\Images\AIPKit_Image_Settings_Ajax_Handler::get_settings();
            $replicate_settings = $image_settings['replicate'] ?? [];
            $disable_safety_checker = $replicate_settings['disable_safety_checker'] ?? true;

            // Add disable_safety_checker to input if enabled
            if ($disable_safety_checker && (empty($schema_fields) || isset($schema_fields['disable_safety_checker']))) {
                $input_params['disable_safety_checker'] = true;
            }
        } else {
            // Fallback: disable safety checker by default if settings class not available
            if (empty($schema_fields) || isset($schema_fields['disable_safety_checker'])) {
                $input_params['disable_safety_checker'] = true;
            }
        }

        $input_params = $this->apply_schema_validated_input_options($input_params, $options, $schema_fields);

        $create_payload = [
            'version' => explode(':', $options['model'])[1] ?? $options['model'],
            'input' => $input_params
        ];

        $create_url = 'https://api.replicate.com/v1/predictions';
        $create_headers = $this->get_api_headers($api_key, 'create_prediction');
        $create_options = $this->get_request_options('create_prediction');

        $create_response = wp_remote_post($create_url, array_merge($create_options, ['headers' => $create_headers, 'body' => json_encode($create_payload)]));

        if (is_wp_error($create_response)) {
            return $create_response;
        }

        $create_status_code = wp_remote_retrieve_response_code($create_response);
        $create_body = wp_remote_retrieve_body($create_response);

        $create_decoded = $this->decode_json($create_body, 'Replicate Create Prediction');
        if (is_wp_error($create_decoded)) {
            return $create_decoded;
        }

        if ($create_status_code >= 300) {
            return new WP_Error('replicate_create_error', 'Failed to create prediction: ' . $this->parse_error_response($create_body, $create_status_code, 'Replicate Create Prediction'));
        }

        $status = $create_decoded['status'] ?? 'unknown';

        if ($status === 'succeeded') {
            // Finished in sync mode, process result directly.
            return $this->format_successful_response($create_decoded);
        } elseif ($status === 'starting' || $status === 'processing') {
            // Timed out, must poll.
            $get_url = $create_decoded['urls']['get'] ?? null;
            if (!$get_url) {
                return new WP_Error('replicate_no_get_url', 'Replicate API did not return a URL to get the prediction status after sync timeout.');
            }
            return $this->poll_for_result($api_key, $get_url);
        } elseif ($status === 'failed' || $status === 'canceled') {
            return new WP_Error('replicate_prediction_failed_initial', 'Prediction failed or was canceled. Error: ' . ($create_decoded['error'] ?? 'Unknown reason.'));
        } else {
            return new WP_Error('replicate_unknown_status', 'Received unknown prediction status: ' . esc_html($status));
        }
    }

    private function get_synced_model_input_fields(string $model): array
    {
        if (!class_exists('\WPAICG\AIPKit_Providers')) {
            return [];
        }

        $target_model = strtolower(trim($model));
        if ($target_model === '') {
            return [];
        }

        $models = \WPAICG\AIPKit_Providers::get_replicate_models();
        if (!is_array($models)) {
            return [];
        }

        foreach ($models as $model_row) {
            if (!is_array($model_row)) {
                continue;
            }
            if (strtolower(trim((string) ($model_row['id'] ?? ''))) !== $target_model) {
                continue;
            }

            $schema = $model_row['input_schema'] ?? ($model_row['replicate_input_schema'] ?? []);
            if (!is_array($schema)) {
                return [];
            }
            $fields = $schema['fields'] ?? $schema;

            return is_array($fields) ? $fields : [];
        }

        return [];
    }

    private function apply_schema_validated_input_options(array $input_params, array $options, array $schema_fields): array
    {
        if (empty($schema_fields)) {
            return $input_params;
        }

        $requested_inputs = [];
        if (isset($options['replicate_input_options']) && is_array($options['replicate_input_options'])) {
            $requested_inputs = $options['replicate_input_options'];
        }

        foreach (array_keys(self::OPTION_FIELD_ALIASES) as $canonical_field) {
            if ($canonical_field === 'disable_safety_checker') {
                continue;
            }

            $field_schema = $schema_fields[$canonical_field] ?? null;
            if (!is_array($field_schema)) {
                continue;
            }

            $input_name = sanitize_key((string) ($field_schema['input_name'] ?? $canonical_field));
            if ($input_name === '') {
                continue;
            }

            $raw_value = null;
            if (array_key_exists($input_name, $requested_inputs)) {
                $raw_value = $requested_inputs[$input_name];
            } elseif (array_key_exists($canonical_field, $options)) {
                $raw_value = $options[$canonical_field];
            }

            $value = $this->sanitize_schema_input_value($canonical_field, $raw_value, $field_schema);
            if ($value !== null) {
                $input_params[$input_name] = $value;
            }
        }

        return $input_params;
    }

    private function sanitize_schema_input_value(string $field, $value, array $schema_field)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (in_array($field, ['aspect_ratio', 'output_format'], true)) {
            $value = $field === 'output_format'
                ? sanitize_key((string) $value)
                : sanitize_text_field((string) $value);
            if ($value === '') {
                return null;
            }
            $fallback_allowed = $field === 'output_format'
                ? ['webp', 'png', 'jpg', 'jpeg']
                : ['1:1', '16:9', '21:9', '3:2', '2:3', '4:3', '3:4', '4:5', '5:4', '9:16', '1:2', '2:1', '3:1', '1:3'];
            $enum = isset($schema_field['enum']) && is_array($schema_field['enum'])
                ? array_map('strval', $schema_field['enum'])
                : [];
            $allowed = !empty($enum) ? $enum : $fallback_allowed;

            return in_array($value, $allowed, true) ? $value : null;
        }

        if ($field === 'negative_prompt') {
            $value = AIPKit_Prompt_Sanitizer::sanitize($value);
            if ($value === '') {
                return null;
            }

            return function_exists('mb_substr') ? mb_substr($value, 0, 1000) : substr($value, 0, 1000);
        }

        if ($field === 'guidance') {
            return $this->sanitize_schema_float_value($value, $schema_field, 0.0, 30.0);
        }

        if (in_array($field, ['width', 'height'], true)) {
            return $this->sanitize_schema_int_value($value, $schema_field, 64, 4096);
        }

        if ($field === 'num_inference_steps') {
            return $this->sanitize_schema_int_value($value, $schema_field, 1, 100);
        }

        if ($field === 'seed') {
            return $this->sanitize_schema_int_value($value, $schema_field, 0, 2147483647);
        }

        if ($field === 'output_quality') {
            return $this->sanitize_schema_int_value($value, $schema_field, 0, 100);
        }

        return null;
    }

    private function sanitize_schema_int_value($value, array $schema_field, int $fallback_min, int $fallback_max): ?int
    {
        $value = is_scalar($value) ? (string) wp_unslash($value) : '';
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        $number = absint($value);
        $minimum = isset($schema_field['minimum']) && is_numeric($schema_field['minimum'])
            ? (int) $schema_field['minimum']
            : $fallback_min;
        $maximum = isset($schema_field['maximum']) && is_numeric($schema_field['maximum'])
            ? (int) $schema_field['maximum']
            : $fallback_max;

        return ($number >= $minimum && $number <= $maximum) ? $number : null;
    }

    private function sanitize_schema_float_value($value, array $schema_field, float $fallback_min, float $fallback_max): ?float
    {
        $value = is_scalar($value) ? (string) wp_unslash($value) : '';
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        $number = (float) $value;
        $minimum = isset($schema_field['minimum']) && is_numeric($schema_field['minimum'])
            ? (float) $schema_field['minimum']
            : $fallback_min;
        $maximum = isset($schema_field['maximum']) && is_numeric($schema_field['maximum'])
            ? (float) $schema_field['maximum']
            : $fallback_max;

        return ($number >= $minimum && $number <= $maximum) ? $number : null;
    }

    /**
     * Polls the Replicate API for a prediction result.
     * @param string $api_key
     * @param string $get_url
     * @return array|WP_Error
     */
    private function poll_for_result(string $api_key, string $get_url)
    {
        $poll_headers = $this->get_api_headers($api_key, 'get_prediction');
        $poll_options = $this->get_request_options('get_prediction');
        for ($i = 0; $i < self::POLLING_TIMEOUT_ITERATIONS; $i++) {
            sleep(self::POLLING_INTERVAL);

            $poll_response = wp_remote_get($get_url, array_merge($poll_options, ['headers' => $poll_headers]));
            if (is_wp_error($poll_response)) {
                return $poll_response;
            }

            $poll_status_code = wp_remote_retrieve_response_code($poll_response);
            $poll_body = wp_remote_retrieve_body($poll_response);

            $poll_decoded = $this->decode_json($poll_body, 'Replicate Poll Prediction');
            if ($poll_status_code >= 300) {
                return new WP_Error('replicate_poll_error', 'Error polling prediction: ' . $this->parse_error_response($poll_body, $poll_status_code, 'Replicate Poll Prediction'));
            }

            $status = $poll_decoded['status'] ?? 'unknown';
            if ($status === 'succeeded') {
                return $this->format_successful_response($poll_decoded);
            } elseif ($status === 'failed' || $status === 'canceled') {
                return new WP_Error('replicate_prediction_failed', 'Prediction failed or was canceled. Error: ' . ($poll_decoded['error'] ?? 'Unknown reason.'));
            }
            // Continue polling if status is 'starting' or 'processing'
        }
        return new WP_Error('replicate_timeout', 'Prediction timed out after ' . (self::POLLING_TIMEOUT_ITERATIONS * self::POLLING_INTERVAL) . ' seconds.');
    }

    /**
     * Formats a successful prediction response into the standard structure.
     * @param array $decoded_response
     * @return array|WP_Error
     */
    private function format_successful_response(array $decoded_response)
    {
        $output = $decoded_response['output'] ?? null;
        if (!$output) {
            return new WP_Error('replicate_no_output', 'Prediction succeeded but no output was found.');
        }

        $image_urls = is_array($output) ? $output : [$output];
        $images = array_map(function ($item) {
            if (is_array($item) && isset($item['url'])) {
                $url = $item['url'];
            } else {
                $url = $item;
            }
            return ['url' => $url, 'b64_json' => null];
        }, $image_urls);
        $images = array_filter($images, fn ($image) => !empty($image['url']));
        if (empty($images)) {
            return new WP_Error('replicate_no_output_url', 'Prediction succeeded but no image URL was found.');
        }

        $predict_time = $decoded_response['metrics']['predict_time'] ?? 0;
        $estimated_tokens = round($predict_time * 500);
        $usage = ['total_tokens' => $estimated_tokens];

        return ['images' => $images, 'usage' => $usage];
    }

    /**
     * Sizes are model-specific on Replicate, so we return an empty array.
     */
    public function get_supported_sizes(): array
    {
        return [];
    }
}

/**
 * OpenRouter Image Generation Provider Strategy.
 * Uses OpenRouter's dedicated Image API and its synchronized capability schema.
 */
class AIPKit_Image_OpenRouter_Provider_Strategy extends AIPKit_Image_Base_Provider_Strategy
{
    /**
     * Supported source mime types for OpenRouter edit mode.
     *
     * @var array<int, string>
     */
    private const EDIT_ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * Raster response formats supported by the plugin's Media Library path.
     *
     * @var array<int, string>
     */
    private const GENERATED_ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * Build OpenRouter image generation endpoint URL.
     *
     * @param array $api_params Provider api params.
     * @return string|WP_Error
     */
    private function build_api_url(array $api_params)
    {
        // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Provider-specific API transport.
        $base_url = isset($api_params['base_url']) ? esc_url_raw((string) $api_params['base_url']) : 'https://openrouter.ai/api';
        $api_version = isset($api_params['api_version']) ? sanitize_text_field((string) $api_params['api_version']) : 'v1';

        if ($base_url === '') {
            return new WP_Error('openrouter_image_missing_base_url', __('OpenRouter Base URL is required.', 'gpt3-ai-content-generator'));
        }
        if ($api_version === '') {
            $api_version = 'v1';
        }

        $base_url = rtrim($base_url, '/');
        if (strpos($base_url, '/' . trim($api_version, '/')) === false) {
            $base_url .= '/' . trim($api_version, '/');
        }

        return $base_url . '/images';
    }

    /**
     * Maps legacy plugin WxH values to the dedicated Image API aspect ratio.
     *
     * @param string $size Size formatted like "1024x1024".
     * @return string|null
     */
    private function map_size_to_aspect_ratio(string $size): ?string
    {
        $size = strtolower(trim($size));
        if ($size === '') {
            return null;
        }

        $size_map = [
            '1024x1024' => '1:1',
            '1536x1024' => '3:2',
            '1024x1536' => '2:3',
            '1024x768' => '4:3',
            '768x1024' => '3:4',
            '1792x1024' => '16:9',
            '1024x1792' => '9:16',
        ];

        return $size_map[$size] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function get_model_parameter_schema(string $model_id): array
    {
        $model_id = sanitize_text_field($model_id);
        if ($model_id === '') {
            return [];
        }

        $resolver_fn = '\WPAICG\Core\Providers\OpenRouter\Methods\model_image_parameter_schema_logic';
        if (!function_exists($resolver_fn)) {
            $capability_file = WPAICG_PLUGIN_DIR . 'classes/ai/providers/openrouter.php';
            if (file_exists($capability_file)) {
                require_once $capability_file;
            }
        }

        if (!function_exists($resolver_fn)) {
            return [];
        }

        $schema = call_user_func($resolver_fn, $model_id);
        return is_array($schema) ? $schema : [];
    }

    /**
     * Return the canonical enum value accepted by a capability descriptor.
     *
     * @param mixed $value Candidate value.
     * @param array<string, mixed> $descriptor Capability descriptor.
     */
    private function normalize_enum_parameter($value, array $descriptor): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $value = sanitize_text_field((string) $value);
        if ($value === '') {
            return '';
        }

        foreach (($descriptor['values'] ?? []) as $allowed_value) {
            if (is_scalar($allowed_value) && strcasecmp((string) $allowed_value, $value) === 0) {
                return sanitize_text_field((string) $allowed_value);
            }
        }

        return '';
    }

    /**
     * Normalize an integer against an Image Models API range descriptor.
     *
     * @param mixed $value Candidate value.
     * @param array<string, mixed> $descriptor Capability descriptor.
     * @return int|null
     */
    private function normalize_range_parameter($value, array $descriptor): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }
        $value = (int) $value;
        $minimum = isset($descriptor['min']) && is_numeric($descriptor['min']) ? (int) $descriptor['min'] : PHP_INT_MIN;
        $maximum = isset($descriptor['max']) && is_numeric($descriptor['max']) ? (int) $descriptor['max'] : PHP_INT_MAX;
        return max($minimum, min($value, $maximum));
    }

    /**
     * Check if selected OpenRouter model supports image output.
     *
     * @param string $model_id Model id.
     * @return bool
     */
    private function model_supports_image_output(string $model_id): bool
    {
        $model_id = sanitize_text_field($model_id);
        if ($model_id === '') {
            return false;
        }

        $resolver_fn = '\WPAICG\Core\Providers\OpenRouter\Methods\model_supports_image_output_logic';
        if (!function_exists($resolver_fn)) {
            $capability_file = WPAICG_PLUGIN_DIR . 'classes/ai/providers/openrouter.php';
            if (file_exists($capability_file)) {
                require_once $capability_file;
            }
        }

        if (!function_exists($resolver_fn)) {
            return true; // Fallback compatibility.
        }

        return (bool) call_user_func($resolver_fn, $model_id);
    }

    /**
     * Check if selected OpenRouter model supports image editing.
     * Image edit requires both image_input and image_output capabilities.
     *
     * @param string $model_id Model id.
     * @return bool
     */
    private function model_supports_image_editing(string $model_id): bool
    {
        $model_id = sanitize_text_field($model_id);
        if ($model_id === '') {
            return false;
        }

        $resolver_fn = '\WPAICG\Core\Providers\OpenRouter\Methods\model_supports_image_editing_logic';
        if (!function_exists($resolver_fn)) {
            $capability_file = WPAICG_PLUGIN_DIR . 'classes/ai/providers/openrouter.php';
            if (file_exists($capability_file)) {
                require_once $capability_file;
            }
        }

        if (!function_exists($resolver_fn)) {
            return true; // Fallback compatibility.
        }

        return (bool) call_user_func($resolver_fn, $model_id);
    }

    /**
     * Build one dedicated Image API input reference.
     *
     * @param array  $source_image Source image payload from upload parser.
     * @return array|WP_Error
     */
    private function build_input_reference(array $source_image)
    {
        $mime_type = isset($source_image['mime_type']) ? strtolower(sanitize_text_field((string) $source_image['mime_type'])) : '';
        if ($mime_type === '' || !in_array($mime_type, self::EDIT_ALLOWED_MIME_TYPES, true)) {
            return new WP_Error(
                'openrouter_image_edit_invalid_mime_type',
                __('Selected source image format is not supported for OpenRouter edit mode. Allowed: PNG, JPG, WEBP, GIF.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }

        $base64_data = isset($source_image['base64_data']) ? (string) $source_image['base64_data'] : '';
        if ($base64_data === '') {
            return new WP_Error(
                'openrouter_image_edit_missing_source_data',
                __('Source image is required for OpenRouter edit mode.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }

        $decoded_binary = base64_decode($base64_data, true);
        if (!is_string($decoded_binary) || $decoded_binary === '') {
            return new WP_Error(
                'openrouter_image_edit_invalid_source_data',
                __('Invalid source image payload for OpenRouter edit mode.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }

        return [
            'type' => 'image_url',
            'image_url' => [
                'url' => 'data:' . $mime_type . ';base64,' . $base64_data,
            ],
        ];
    }

    /**
     * Parse image blocks from OpenRouter response into storage-friendly format.
     *
     * @param array $decoded_response Decoded OpenRouter response.
     * @return array<int, array<string, mixed>>
     */
    private function parse_images(array $decoded_response): array
    {
        $images = [];
        $image_blocks = isset($decoded_response['data']) && is_array($decoded_response['data'])
            ? $decoded_response['data']
            : [];

        foreach ($image_blocks as $image_block) {
            if (!is_array($image_block)) {
                continue;
            }

            $base64_data = isset($image_block['b64_json']) && is_string($image_block['b64_json'])
                ? trim($image_block['b64_json'])
                : '';
            if ($base64_data === '' || base64_decode($base64_data, true) === false) {
                continue;
            }

            $mime_type = isset($image_block['media_type']) && is_string($image_block['media_type'])
                ? strtolower(sanitize_text_field($image_block['media_type']))
                : '';
            if ($mime_type === '') {
                $mime_type = 'image/png';
            }
            if (!in_array($mime_type, self::GENERATED_ALLOWED_MIME_TYPES, true)) {
                continue;
            }

            $images[] = [
                'url' => null,
                'b64_json' => $base64_data,
                'mime_type' => $mime_type,
                'revised_prompt' => null,
            ];
        }

        return $images;
    }

    /**
     * Find non-raster media types in otherwise valid response image blocks.
     *
     * @param array $decoded_response Decoded OpenRouter response.
     * @return array<int, string>
     */
    private function get_unsupported_response_media_types(array $decoded_response): array
    {
        $unsupported = [];
        $image_blocks = isset($decoded_response['data']) && is_array($decoded_response['data'])
            ? $decoded_response['data']
            : [];

        foreach ($image_blocks as $image_block) {
            if (!is_array($image_block) || empty($image_block['b64_json']) || !is_string($image_block['b64_json'])) {
                continue;
            }
            if (base64_decode(trim($image_block['b64_json']), true) === false) {
                continue;
            }

            $mime_type = isset($image_block['media_type']) && is_string($image_block['media_type'])
                ? strtolower(sanitize_text_field($image_block['media_type']))
                : 'image/png';
            if ($mime_type !== '' && !in_array($mime_type, self::GENERATED_ALLOWED_MIME_TYPES, true)) {
                $unsupported[] = $mime_type;
            }
        }

        return array_values(array_unique($unsupported));
    }

    /**
     * Build a dedicated Image API payload from normalized plugin options.
     *
     * A non-empty synchronized schema is authoritative. When no dedicated
     * schema has been synchronized yet, conservative documented fallbacks keep
     * existing installations functional until their next model sync.
     *
     * @param string $prompt Sanitized prompt.
     * @param string $model Model id.
     * @param string $image_mode Generate or edit.
     * @param array<string, mixed> $options Runtime options.
     * @param array<string, array<string, mixed>> $parameter_schema Model capability descriptors.
     * @return array<string, mixed>|WP_Error
     */
    private function build_image_payload(string $prompt, string $model, string $image_mode, array $options, array $parameter_schema)
    {
        $payload = [
            'model' => $model,
            'prompt' => $prompt,
        ];
        $image_routing_fn = '\\WPAICG\\Core\\Providers\\OpenRouter\\Methods\\get_saved_image_routing_preferences_logic';
        if (function_exists($image_routing_fn)) {
            $provider_preferences = call_user_func($image_routing_fn);
            if (is_array($provider_preferences) && !empty($provider_preferences)) {
                $payload['provider'] = $provider_preferences;
            }
        }
        $has_authoritative_schema = !empty($parameter_schema);
        $supports_parameter = static function (string $parameter) use ($parameter_schema, $has_authoritative_schema): bool {
            return !$has_authoritative_schema || isset($parameter_schema[$parameter]);
        };

        if ($image_mode === 'edit') {
            if (!$supports_parameter('input_references')) {
                return new WP_Error(
                    'openrouter_image_edit_model_unsupported',
                    __('Selected OpenRouter model does not support image editing.', 'gpt3-ai-content-generator'),
                    ['status' => 400]
                );
            }
            $source_image = isset($options['source_image']) && is_array($options['source_image'])
                ? $options['source_image']
                : null;
            if (!is_array($source_image)) {
                return new WP_Error(
                    'openrouter_image_edit_missing_source',
                    __('Source image is required for OpenRouter edit mode.', 'gpt3-ai-content-generator'),
                    ['status' => 400]
                );
            }
            $input_reference = $this->build_input_reference($source_image);
            if (is_wp_error($input_reference)) {
                return $input_reference;
            }
            $payload['input_references'] = [$input_reference];
        }

        $requested_count = isset($options['n']) ? max(1, min(absint($options['n']), 10)) : 1;
        if ($requested_count > 1 && $supports_parameter('n')) {
            $count_descriptor = isset($parameter_schema['n']) && is_array($parameter_schema['n'])
                ? $parameter_schema['n']
                : ['type' => 'range', 'min' => 1, 'max' => 10];
            $normalized_count = $this->normalize_range_parameter($requested_count, $count_descriptor);
            if ($normalized_count !== null && $normalized_count > 1) {
                $payload['n'] = $normalized_count;
            }
        }

        $fallback_enums = [
            'aspect_ratio' => ['auto', '1:1', '1:2', '2:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '9:19.5', '19.5:9', '9:20', '20:9', '9:21', '21:9', '1:4', '4:1', '1:8', '8:1'],
            'resolution' => ['512', '1K', '2K', '4K'],
            'quality' => ['auto', 'low', 'medium', 'high'],
            'output_format' => ['png', 'jpeg', 'webp'],
            'background' => ['auto', 'transparent', 'opaque'],
        ];
        $normalize_enum = function (string $parameter, $value) use ($parameter_schema, $fallback_enums): string {
            $descriptor = isset($parameter_schema[$parameter]) && is_array($parameter_schema[$parameter])
                ? $parameter_schema[$parameter]
                : ['type' => 'enum', 'values' => $fallback_enums[$parameter] ?? []];
            return $this->normalize_enum_parameter($value, $descriptor);
        };

        $aspect_ratio = isset($options['aspect_ratio']) ? $options['aspect_ratio'] : '';
        if ($aspect_ratio === '' && !empty($options['size']) && is_string($options['size'])) {
            $aspect_ratio = $this->map_size_to_aspect_ratio($options['size']) ?? '';
        }
        if ($aspect_ratio !== '' && $supports_parameter('aspect_ratio')) {
            $aspect_ratio = $normalize_enum('aspect_ratio', $aspect_ratio);
            if ($aspect_ratio !== '') {
                $payload['aspect_ratio'] = $aspect_ratio;
            }
        }

        $resolution = $options['resolution'] ?? ($options['image_size'] ?? '');
        if (is_string($resolution) && strtolower(trim($resolution)) === '0.5k') {
            $resolution = '512';
        }
        if ($resolution !== '' && $supports_parameter('resolution')) {
            $resolution = $normalize_enum('resolution', $resolution);
            if ($resolution !== '') {
                $payload['resolution'] = $resolution;
            }
        }

        if (!isset($payload['aspect_ratio']) && $supports_parameter('size') && !empty($options['size']) && is_string($options['size'])) {
            $size = strtolower(sanitize_text_field($options['size']));
            if (preg_match('/^(?:512|1k|2k|4k|[1-9][0-9]{2,4}x[1-9][0-9]{2,4})$/i', $size) === 1) {
                $payload['size'] = strtoupper($size);
            }
        }

        foreach (['quality', 'output_format', 'background'] as $parameter) {
            if (!$supports_parameter($parameter) || empty($options[$parameter])) {
                continue;
            }
            if (
                $parameter === 'output_format'
                && !in_array(strtolower((string) $options[$parameter]), ['png', 'jpeg', 'jpg', 'webp'], true)
            ) {
                continue;
            }
            $normalized_value = $normalize_enum($parameter, $options[$parameter]);
            if ($normalized_value !== '') {
                $payload[$parameter] = $normalized_value;
            }
        }

        if (
            $supports_parameter('output_compression')
            && isset($options['output_compression'])
            && $options['output_compression'] !== ''
            && (!isset($payload['output_format']) || in_array($payload['output_format'], ['jpeg', 'webp'], true))
        ) {
            $compression_descriptor = isset($parameter_schema['output_compression']) && is_array($parameter_schema['output_compression'])
                ? $parameter_schema['output_compression']
                : ['type' => 'range', 'min' => 0, 'max' => 100];
            $compression = $this->normalize_range_parameter($options['output_compression'], $compression_descriptor);
            if ($compression !== null) {
                $payload['output_compression'] = $compression;
            }
        }

        if ($supports_parameter('seed') && isset($options['seed']) && is_numeric($options['seed'])) {
            $payload['seed'] = max(0, (int) $options['seed']);
        }

        return $payload;
    }

    /**
     * Generate image(s) via OpenRouter.
     *
     * @param string $prompt Prompt text.
     * @param array  $api_params Provider API params.
     * @param array  $options Runtime options.
     * @return array|WP_Error
     */
    public function generate_image(string $prompt, array $api_params, array $options = [])
    {
        $api_key = isset($api_params['api_key']) ? sanitize_text_field((string) $api_params['api_key']) : '';
        $model = isset($options['model']) ? sanitize_text_field((string) $options['model']) : '';
        $clean_prompt = AIPKit_Prompt_Sanitizer::sanitize($prompt);
        $image_mode = isset($options['image_mode']) && $options['image_mode'] === 'edit' ? 'edit' : 'generate';

        if ($api_key === '') {
            return new WP_Error('openrouter_image_missing_key', __('OpenRouter API Key is required for image generation.', 'gpt3-ai-content-generator'));
        }
        if ($model === '') {
            return new WP_Error('openrouter_image_missing_model', __('OpenRouter image model is required.', 'gpt3-ai-content-generator'));
        }
        if ($clean_prompt === '') {
            return new WP_Error('openrouter_image_missing_prompt', __('Prompt cannot be empty for image generation.', 'gpt3-ai-content-generator'));
        }
        if ($image_mode === 'edit') {
            if (!$this->model_supports_image_editing($model)) {
                return new WP_Error(
                    'openrouter_image_edit_model_unsupported',
                    __('Selected OpenRouter model does not support image editing.', 'gpt3-ai-content-generator'),
                    ['status' => 400]
                );
            }
        } elseif (!$this->model_supports_image_output($model)) {
            return new WP_Error('openrouter_image_model_unsupported', __('Selected OpenRouter model does not support image output.', 'gpt3-ai-content-generator'), ['status' => 400]);
        }

        $url = $this->build_api_url($api_params);
        if (is_wp_error($url)) {
            return $url;
        }

        $payload = $this->build_image_payload(
            $clean_prompt,
            $model,
            $image_mode,
            $options,
            $this->get_model_parameter_schema($model)
        );
        if (is_wp_error($payload)) {
            return $payload;
        }

        $headers = $this->get_api_headers($api_key, 'generate');
        $request_options = $this->get_request_options('generate');
        $request_args = array_merge($request_options, [
            'headers' => $headers,
            'body' => wp_json_encode($payload),
            'data_format' => 'body',
        ]);

        $response = wp_remote_post($url, $request_args);
        if (is_wp_error($response)) {
            return new WP_Error('openrouter_image_http_error', __('HTTP error during OpenRouter image generation.', 'gpt3-ai-content-generator'));
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded_response = $this->decode_json($body, 'OpenRouter Image Generation');
        if ($status_code !== 200 || is_wp_error($decoded_response)) {
            $error_message = is_wp_error($decoded_response)
                ? $decoded_response->get_error_message()
                : $this->parse_error_response($body, $status_code, 'OpenRouter Image');
            /* translators: %1$d: HTTP status code, %2$s: error message */
            return new WP_Error('openrouter_image_api_error', sprintf(__('OpenRouter Image API Error (%1$d): %2$s', 'gpt3-ai-content-generator'), $status_code, $error_message));
        }

        $images = $this->parse_images($decoded_response);
        $requested_count = isset($options['n']) ? max(1, min(absint($options['n']), 10)) : 1;
        if ($requested_count > 0 && count($images) > $requested_count) {
            $images = array_slice($images, 0, $requested_count);
        }
        if (empty($images)) {
            $unsupported_media_types = $this->get_unsupported_response_media_types($decoded_response);
            if (!empty($unsupported_media_types)) {
                return new WP_Error(
                    'openrouter_image_unsupported_media_type',
                    __('OpenRouter returned a non-raster image that cannot be saved safely to the WordPress Media Library. Select a raster-capable model or PNG, JPEG, or WEBP output.', 'gpt3-ai-content-generator'),
                    ['status' => 400, 'media_types' => $unsupported_media_types]
                );
            }
            return new WP_Error('openrouter_image_no_data', __('OpenRouter API returned success but no image data was found.', 'gpt3-ai-content-generator'));
        }

        $usage_data = null;
        if (isset($decoded_response['usage']) && is_array($decoded_response['usage'])) {
            $prompt_tokens = absint($decoded_response['usage']['prompt_tokens'] ?? 0);
            $completion_tokens = absint($decoded_response['usage']['completion_tokens'] ?? 0);
            $total_tokens = absint($decoded_response['usage']['total_tokens'] ?? ($prompt_tokens + $completion_tokens));
            $usage_data = [
                'input_tokens' => $prompt_tokens,
                'output_tokens' => $completion_tokens,
                'total_tokens' => $total_tokens,
                'provider_raw' => $decoded_response['usage'],
            ];
            if (isset($decoded_response['usage']['cost']) && is_numeric($decoded_response['usage']['cost'])) {
                $usage_data['cost'] = (float) $decoded_response['usage']['cost'];
            }
        }

        return [
            'images' => $images,
            'usage' => $usage_data,
        ];
    }

    /**
     * OpenRouter image sizes vary by model. Keep empty to avoid invalid hardcoded constraints.
     *
     * @return array
     */
    public function get_supported_sizes(): array
    {
        return [];
    }

    /**
     * Dedicated image generation can legitimately exceed the shared 120 second
     * image timeout for high-resolution models.
     *
     * @param string $operation Operation name.
     * @return array
     */
    public function get_request_options(string $operation): array
    {
        $options = parent::get_request_options($operation);
        if ($operation === 'generate') {
            $options['timeout'] = 180;
        }
        return $options;
    }

    /**
     * OpenRouter request headers.
     *
     * @param string $api_key API key.
     * @param string $operation Operation name.
     * @return array
     */
    public function get_api_headers(string $api_key, string $operation): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
            'HTTP-Referer' => get_bloginfo('url'),
            'X-OpenRouter-Title' => get_bloginfo('name'),
        ];
    }
}

/**
 * xAI Image Generation Provider Strategy.
 *
 * Uses xAI's JSON Images API for both generation and edits.
 */
class AIPKit_Image_XAI_Provider_Strategy extends AIPKit_Image_Base_Provider_Strategy
{
    private const EDIT_ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png'];

    /**
     * @return string|\WP_Error
     */
    private function build_api_url(string $operation, array $api_params)
    {
        // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Provider-specific API transport.
        $base_url = !empty($api_params['base_url']) ? rtrim((string) $api_params['base_url'], '/') : 'https://api.x.ai';
        $api_version = !empty($api_params['api_version']) ? trim((string) $api_params['api_version'], '/') : 'v1';

        if ($base_url === '') {
            return new WP_Error('xai_image_missing_base_url', __('xAI Base URL is required for images.', 'gpt3-ai-content-generator'));
        }
        if ($api_version === '') {
            return new WP_Error('xai_image_missing_api_version', __('xAI API Version is required for images.', 'gpt3-ai-content-generator'));
        }

        $paths = [
            'generate' => '/images/generations',
            'edit' => '/images/edits',
            'models' => '/image-generation-models',
        ];
        if (!isset($paths[$operation])) {
            return new WP_Error(
                'xai_image_unsupported_operation',
                sprintf(
                    /* translators: %s: Operation name. */
                    __('Operation "%s" is not supported for xAI images.', 'gpt3-ai-content-generator'),
                    esc_html($operation)
                )
            );
        }

        $version_segment = '/' . $api_version;
        if (strpos($base_url, $version_segment) !== false) {
            return $base_url . $paths[$operation];
        }

        return $base_url . $version_segment . $paths[$operation];
    }

    private function map_size_to_aspect_ratio(string $size): ?string
    {
        $size = strtolower(trim($size));
        if ($size === '') {
            return null;
        }

        $size_map = [
            '1024x1024' => '1:1',
            '512x512' => '1:1',
            '256x256' => '1:1',
            '1536x1024' => '3:2',
            '1024x1536' => '2:3',
            '1024x768' => '4:3',
            '768x1024' => '3:4',
            '1792x1024' => '16:9',
            '1024x1792' => '9:16',
            '1920x1080' => '16:9',
            '1080x1920' => '9:16',
        ];

        return $size_map[$size] ?? null;
    }

    private function normalize_resolution($value): string
    {
        $resolution = is_string($value) ? strtolower(trim($value)) : '';
        return in_array($resolution, ['1k', '2k'], true) ? $resolution : '';
    }

    private function parse_xai_error_response($response_body, int $status_code, string $context): string
    {
        $parser_function = 'WPAICG\\Core\\Providers\\XAI\\Methods\\xai_parse_error_response_body';
        if (!function_exists($parser_function) && defined('WPAICG_PLUGIN_DIR')) {
            $parser_path = WPAICG_PLUGIN_DIR . 'classes/ai/providers/xai.php';
            if (file_exists($parser_path)) {
                require_once $parser_path;
            }
        }

        if (function_exists($parser_function)) {
            return $parser_function(
                $response_body,
                $status_code,
                sprintf(
                    /* translators: %s: Provider context. */
                    __('An unknown error occurred with %s.', 'gpt3-ai-content-generator'),
                    $context
                )
            );
        }

        if ($status_code === 429) {
            return __('Rate limit or quota exceeded. Please wait and retry, or check your xAI Console rate limits and billing.', 'gpt3-ai-content-generator');
        }

        return $this->parse_error_response($response_body, $status_code, $context);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    private function build_source_image_payload(array $source_image)
    {
        $mime_type = isset($source_image['mime_type']) ? strtolower(sanitize_text_field((string) $source_image['mime_type'])) : '';
        if ($mime_type === 'image/jpg') {
            $mime_type = 'image/jpeg';
        }
        if ($mime_type === '' || !in_array($mime_type, self::EDIT_ALLOWED_MIME_TYPES, true)) {
            return new WP_Error(
                'xai_image_edit_invalid_mime_type',
                __('Selected source image format is not supported for xAI edit mode. Allowed: PNG, JPG.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }

        $base64_data = isset($source_image['base64_data']) ? preg_replace('/\s+/', '', (string) $source_image['base64_data']) : '';
        if (!is_string($base64_data) || $base64_data === '') {
            return new WP_Error(
                'xai_image_edit_missing_source_data',
                __('Source image is required for xAI edit mode.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }

        $decoded_binary = base64_decode($base64_data, true);
        if (!is_string($decoded_binary) || $decoded_binary === '') {
            return new WP_Error(
                'xai_image_edit_invalid_source_data',
                __('Invalid source image payload for xAI edit mode.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }

        return [
            'type' => 'image_url',
            'url' => 'data:' . $mime_type . ';base64,' . $base64_data,
        ];
    }

    /**
     * @return mixed[]|\WP_Error
     */
    private function build_payload(string $prompt, array $options, string $image_mode)
    {
        $model = isset($options['model']) ? sanitize_text_field((string) $options['model']) : '';
        if ($model === '') {
            return new WP_Error('xai_image_missing_model', __('xAI image model is required.', 'gpt3-ai-content-generator'));
        }

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
        ];

        if ($image_mode === 'edit') {
            $source_image = isset($options['source_image']) && is_array($options['source_image'])
                ? $options['source_image']
                : null;
            if (!is_array($source_image)) {
                return new WP_Error(
                    'xai_image_edit_missing_source',
                    __('Source image is required for xAI edit mode.', 'gpt3-ai-content-generator'),
                    ['status' => 400]
                );
            }

            $image_payload = $this->build_source_image_payload($source_image);
            if (is_wp_error($image_payload)) {
                return $image_payload;
            }
            $payload['image'] = $image_payload;
        } else {
            $n = isset($options['n']) ? absint($options['n']) : 1;
            $payload['n'] = max(1, min($n, 10));
        }

        $aspect_ratio = '';
        if (!empty($options['aspect_ratio']) && is_string($options['aspect_ratio'])) {
            $aspect_ratio = sanitize_text_field($options['aspect_ratio']);
        } elseif ($image_mode !== 'edit' && !empty($options['size']) && is_string($options['size'])) {
            $aspect_ratio = $this->map_size_to_aspect_ratio($options['size']) ?? '';
        }
        if ($aspect_ratio !== '') {
            $payload['aspect_ratio'] = $aspect_ratio;
        }

        $resolution = '';
        if (isset($options['resolution'])) {
            $resolution = $this->normalize_resolution($options['resolution']);
        } elseif (isset($options['image_size'])) {
            $resolution = $this->normalize_resolution($options['image_size']);
        }
        if ($resolution !== '') {
            $payload['resolution'] = $resolution;
        }

        $response_format = isset($options['response_format']) ? sanitize_text_field((string) $options['response_format']) : 'b64_json';
        $payload['response_format'] = $response_format === 'url' ? 'url' : 'b64_json';

        return $payload;
    }

    /**
     * @param array<string, mixed> $usage
     * @param int $image_count
     * @return array<string, mixed>
     */
    private function normalize_usage(array $usage, int $image_count): array
    {
        $normalized = [
            'unit_count' => $image_count,
            'image_count' => $image_count,
            'total_units' => $image_count,
            'provider_raw' => $usage,
        ];

        $input_tokens = isset($usage['input_tokens']) ? absint($usage['input_tokens']) : absint($usage['prompt_tokens'] ?? 0);
        $output_tokens = isset($usage['output_tokens']) ? absint($usage['output_tokens']) : absint($usage['completion_tokens'] ?? 0);
        $total_tokens = isset($usage['total_tokens']) ? absint($usage['total_tokens']) : 0;
        if ($total_tokens <= 0 && ($input_tokens > 0 || $output_tokens > 0)) {
            $total_tokens = $input_tokens + $output_tokens;
        }

        if ($input_tokens > 0) {
            $normalized['input_tokens'] = $input_tokens;
        }
        if ($output_tokens > 0) {
            $normalized['output_tokens'] = $output_tokens;
        }
        if ($total_tokens > 0) {
            $normalized['total_tokens'] = $total_tokens;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $decoded_response
     * @param string $prompt
     * @return array<string, mixed>
     */
    private function parse_response(array $decoded_response, string $prompt): array
    {
        $images = [];
        $data = isset($decoded_response['data']) && is_array($decoded_response['data'])
            ? $decoded_response['data']
            : [];

        foreach ($data as $image_data) {
            if (!is_array($image_data)) {
                continue;
            }

            $image_item = [
                'url' => null,
                'b64_json' => null,
                'mime_type' => null,
                'revised_prompt' => $image_data['revised_prompt'] ?? null,
            ];

            if (!empty($image_data['b64_json']) && is_string($image_data['b64_json'])) {
                $image_item['b64_json'] = $image_data['b64_json'];
                $image_item['mime_type'] = 'image/png';
            } elseif (!empty($image_data['url']) && is_string($image_data['url'])) {
                $image_url = $image_data['url'];
                if (preg_match('#^data:(image/[^;]+);base64,#i', $image_url, $matches) === 1) {
                    $image_item['b64_json'] = (string) substr($image_url, strpos($image_url, ',') + 1);
                    $image_item['mime_type'] = strtolower(sanitize_text_field((string) $matches[1]));
                    $image_item['url'] = null;
                } else {
                    $image_item['url'] = esc_url_raw($image_url);
                }
            }

            if ($image_item['url'] === null && $image_item['b64_json'] === null) {
                continue;
            }

            if ($image_item['revised_prompt'] === null && isset($image_data['prompt']) && is_string($image_data['prompt'])) {
                $image_item['revised_prompt'] = $image_data['prompt'];
            }
            if ($image_item['revised_prompt'] === null && $prompt !== '') {
                $image_item['revised_prompt'] = $prompt;
            }
            if (array_key_exists('respect_moderation', $image_data)) {
                $image_item['respect_moderation'] = (bool) $image_data['respect_moderation'];
            }

            $images[] = $image_item;
        }

        $usage_raw = isset($decoded_response['usage']) && is_array($decoded_response['usage'])
            ? $decoded_response['usage']
            : [];
        if (isset($decoded_response['cost_in_usd_ticks']) && !isset($usage_raw['cost_in_usd_ticks'])) {
            $usage_raw['cost_in_usd_ticks'] = $decoded_response['cost_in_usd_ticks'];
        }
        if (isset($decoded_response['model']) && !isset($usage_raw['model'])) {
            $usage_raw['model'] = $decoded_response['model'];
        }

        return [
            'images' => $images,
            'usage' => $this->normalize_usage($usage_raw, count($images)),
        ];
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function generate_image(string $prompt, array $api_params, array $options = [])
    {
        $api_key = isset($api_params['api_key']) ? sanitize_text_field((string) $api_params['api_key']) : '';
        $clean_prompt = AIPKit_Prompt_Sanitizer::sanitize($prompt);
        $image_mode = isset($options['image_mode']) && $options['image_mode'] === 'edit' ? 'edit' : 'generate';

        if ($api_key === '') {
            return new WP_Error('xai_image_missing_key', __('xAI API Key is required for image generation.', 'gpt3-ai-content-generator'));
        }
        if ($clean_prompt === '') {
            return new WP_Error('xai_image_missing_prompt', __('Prompt cannot be empty for image generation.', 'gpt3-ai-content-generator'));
        }

        $payload = $this->build_payload($clean_prompt, $options, $image_mode);
        if (is_wp_error($payload)) {
            return $payload;
        }

        $url = $this->build_api_url($image_mode, $api_params);
        if (is_wp_error($url)) {
            return $url;
        }

        $request_args = array_merge($this->get_request_options($image_mode), [
            'headers' => $this->get_api_headers($api_key, $image_mode),
            'body' => wp_json_encode($payload),
            'data_format' => 'body',
        ]);

        $response = wp_remote_post($url, $request_args);
        if (is_wp_error($response)) {
            return new WP_Error('xai_image_http_error', __('HTTP error during xAI image generation.', 'gpt3-ai-content-generator'));
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded_response = $this->decode_json($body, 'xAI Image Generation');

        if ($status_code !== 200 || is_wp_error($decoded_response)) {
            $error_message = is_wp_error($decoded_response)
                ? $decoded_response->get_error_message()
                : $this->parse_xai_error_response($body, $status_code, 'xAI Image');
            return new WP_Error(
                'xai_image_api_error',
                sprintf(
                    /* translators: %1$d: HTTP status code, %2$s: API error message. */
                    __('xAI Image API Error (%1$d): %2$s', 'gpt3-ai-content-generator'),
                    $status_code,
                    $error_message
                ),
                ['status' => $status_code]
            );
        }

        $parsed = $this->parse_response($decoded_response, $clean_prompt);
        if (empty($parsed['images'])) {
            return new WP_Error('xai_image_no_data', __('xAI API returned success but no image data was found.', 'gpt3-ai-content-generator'));
        }

        return $parsed;
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function get_models(array $api_params)
    {
        $api_key = isset($api_params['api_key']) ? sanitize_text_field((string) $api_params['api_key']) : '';
        if ($api_key === '') {
            return new WP_Error('xai_image_models_missing_key', __('xAI API Key is required to sync image models.', 'gpt3-ai-content-generator'));
        }

        $url = $this->build_api_url('models', $api_params);
        if (is_wp_error($url)) {
            return $url;
        }

        $options = array_merge($this->get_request_options('models'), [
            'method' => 'GET',
            'headers' => $this->get_api_headers($api_key, 'models'),
        ]);
        unset($options['body']);

        $response = wp_remote_get($url, $options);
        if (is_wp_error($response)) {
            return new WP_Error('xai_image_models_http_error', __('HTTP error during xAI image model sync.', 'gpt3-ai-content-generator'));
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded_response = $this->decode_json($body, 'xAI Image Models');

        if ($status_code !== 200 || is_wp_error($decoded_response)) {
            $error_message = is_wp_error($decoded_response)
                ? $decoded_response->get_error_message()
                : $this->parse_xai_error_response($body, $status_code, 'xAI Image Models');
            return new WP_Error(
                'xai_image_models_api_error',
                sprintf(
                    /* translators: %1$d: HTTP status code, %2$s: API error message. */
                    __('xAI Image Models API Error (%1$d): %2$s', 'gpt3-ai-content-generator'),
                    $status_code,
                    $error_message
                ),
                ['status' => $status_code]
            );
        }

        $raw_models = [];
        if (isset($decoded_response['models']) && is_array($decoded_response['models'])) {
            $raw_models = $decoded_response['models'];
        } elseif (isset($decoded_response['data']) && is_array($decoded_response['data'])) {
            $raw_models = $decoded_response['data'];
        } elseif (self::is_list_array($decoded_response)) {
            $raw_models = $decoded_response;
        }

        $formatted = [];
        foreach ($raw_models as $model) {
            if (is_string($model)) {
                $formatted[] = ['id' => $model, 'name' => $model];
                continue;
            }
            if (!is_array($model)) {
                continue;
            }

            $id = $model['id'] ?? $model['model'] ?? $model['name'] ?? null;
            if (!is_string($id) || trim($id) === '') {
                continue;
            }

            $name = $model['name'] ?? $model['display_name'] ?? $id;
            $item = [
                'id' => $id,
                'name' => is_string($name) && trim($name) !== '' ? $name : $id,
            ];
            foreach (['aliases', 'created', 'fingerprint', 'image_price', 'input_modalities', 'max_prompt_length', 'output_modalities', 'owned_by', 'version'] as $metadata_key) {
                if (array_key_exists($metadata_key, $model)) {
                    $item[$metadata_key] = $model[$metadata_key];
                }
            }
            $formatted[] = $item;
        }

        usort($formatted, static fn(array $a, array $b): int => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        return $formatted;
    }

    public function get_supported_sizes(): array
    {
        return ['1024x1024', '1536x1024', '1024x1536', '1024x768', '768x1024', '1792x1024', '1024x1792'];
    }

    public function get_request_options(string $operation): array
    {
        $options = parent::get_request_options($operation);
        $options['timeout'] = $operation === 'models' ? 60 : 180;
        return $options;
    }

    public function get_api_headers(string $api_key, string $operation): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ];
    }

    private static function is_list_array(array $array): bool
    {
        $expected_key = 0;
        foreach ($array as $key => $_value) {
            if ($key !== $expected_key) {
                return false;
            }
            $expected_key++;
        }
        return true;
    }
}

/** Published Cloud image operations use the shared image strategy contract. */
class AIPKit_Image_Cloud_Provider_Strategy extends AIPKit_Image_Base_Provider_Strategy
{
    public function generate_image(string $prompt, array $api_params, array $options = [])
    {
        $edit = ($options['image_mode'] ?? 'generate') === 'edit';
        $operation = $edit ? 'image_edit' : 'image_generate';
        $model = $options['model'] ?? '';
        if (!in_array($model, wp_list_pluck(\WPAICG\Cloud\Connection::media_models($operation), 'id'), true)) {
            return new WP_Error('cloud_image_model_unavailable', __('This Cloud image model is unavailable. Sync Cloud models in Settings.', 'gpt3-ai-content-generator'));
        }
        if (($options['size'] ?? '1024x1024') !== '1024x1024' || (int) ($options['n'] ?? 1) !== 1) {
            return new WP_Error('cloud_image_options_unsupported', __('Cloud images use one 1024 × 1024 image per request.', 'gpt3-ai-content-generator'));
        }
        $operation_id = $options['cloud_operation_id'] ?? wp_generate_uuid4();
        $body = ['prompt' => $prompt, 'model' => $model, 'operationId' => $operation_id];
        if ($edit) {
            $source = $options['source_image'] ?? null;
            if (!is_array($source) || !is_string($source['base64_data'] ?? null) || $source['base64_data'] === '') {
                return new WP_Error('cloud_image_source_missing', __('Upload one image to edit.', 'gpt3-ai-content-generator'));
            }
            $constraints = \WPAICG\Images\AIPKit_Image_Provider_Strategy_Factory::edit_upload_constraints('AIPufferCloud', $model);
            if (!in_array($source['mime_type'] ?? '', $constraints['allowedMimeTypes'], true)) {
                return new WP_Error('cloud_image_source_unsupported', $constraints['invalidTypeMessage']);
            }
            $source_bytes = base64_decode($source['base64_data'], true);
            if ($source_bytes === false || $source_bytes === '') {
                return new WP_Error('cloud_image_source_unsupported', __('The source image could not be read.', 'gpt3-ai-content-generator'));
            }
            if (max((int) ($source['size_bytes'] ?? 0), strlen($source_bytes)) > $constraints['maxBytes']) {
                return new WP_Error('cloud_image_source_unsupported', $constraints['tooLargeMessage']);
            }
            $body['image'] = $source['base64_data'];
        }
        try {
            $result = \WPAICG\Cloud\Connection::media_request($operation, $body);
        } catch (\RuntimeException $error) {
            $code = $error->getMessage();
            $uncertain = in_array($code, ['cloud_outcome_unknown', 'cloud_request_failed', 'already_dispatched_or_final', 'gateway_aborted', 'gateway_response_unverified'], true);
            $message = $uncertain
                ? __('The Cloud image response was interrupted. The request may have completed and used credits. Check its status before starting a new request.', 'gpt3-ai-content-generator')
                : ($code === 'catalog_model_unavailable'
                ? __('This Cloud image model is unavailable. Sync Cloud models and choose another model.', 'gpt3-ai-content-generator')
                : (in_array($code, \WPAICG\Cloud\Connection::BILLING_CODES, true)
                ? \WPAICG\Cloud\Connection::billing_message($code)
                : __('Cloud could not generate the image. Check the selected model and try again.', 'gpt3-ai-content-generator')));
            return new WP_Error($code, $message, array_merge(\WPAICG\Cloud\Connection::request_error_data($code), ['cloud_operation_id' => $operation_id]));
        }
        $image = $result['image'] ?? null;
        if (!is_array($image) || !in_array($image['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp'], true)
            || !is_string($image['data'] ?? null) || strlen($image['data']) > 11 * 1024 * 1024
            || base64_decode($image['data'], true) === false) {
            return new WP_Error('cloud_image_invalid_response', __('Cloud completed the request, but its image could not be read. Check its status before starting a new request.', 'gpt3-ai-content-generator'), ['cloud_operation_id' => $operation_id, 'stop_batch' => true]);
        }
        return ['images' => [['b64_json' => $image['data'], 'mime_type' => $image['mime']]], 'usage' => null];
    }

    public function get_supported_sizes(): array { return ['1024x1024']; }
}
