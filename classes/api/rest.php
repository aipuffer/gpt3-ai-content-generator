<?php

namespace WPAICG\REST\Handlers;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WPAICG\Core\AIPKit_AI_Caller;
use WPAICG\Core\AIPKit_Instruction_Manager;
use WPAICG\AIPKit_Providers;
use WPAICG\AIPKIT_AI_Settings;
use WPAICG\Utils\AIPKit_Prompt_Sanitizer;
use WPAICG\Images\AIPKit_Image_Manager;
use WPAICG\Chat\Storage\BotStorage;
use WPAICG\Chat\Core\AIService as ChatAIService;
use WPAICG\Vector\AIPKit_Vector_Store_Manager;
use WPAICG\Vector\AIPKit_Vector_Text_Ingestion_Service;
use WPAICG\Chat\Storage\LogStorage;
use WPAICG\AutoGPT\Cron\AIPKit_Automation_Runner;
use WPAICG\AutoGPT\Cron\AIPKit_Automation_Server_Cron;
use WPAICG\Includes\DependencyLoaders\Automated_Task_Cron_Helpers_Loader;
use WPAICG\Includes\DependencyLoaders\Automated_Task_Dependencies_Loader;
use WPAICG\Includes\DependencyLoaders\Content_Writer_Dependencies_Loader;

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/ai/http.php';

/**
 * Base class for REST API Endpoint Handlers.
 * Provides common utility methods like permission checks.
 */
abstract class AIPKit_REST_Base_Handler {

    /**
     * Retrieve the stored public API key from WordPress options.
     * This key is used to authenticate external requests to the plugin's REST API.
     *
     * @return string The stored public API key, or an empty string if not set.
     */
    protected static function get_stored_public_api_key(): string {
        $opts = get_option('aipkit_options', []);
        $api_keys = $opts['api_keys'] ?? [];
        return isset($api_keys['public_api_key']) ? trim($api_keys['public_api_key']) : '';
    }

    /**
     * Returns whether external REST API access is explicitly enabled.
     */
    protected static function is_public_api_enabled(): bool {
        $opts = get_option('aipkit_options', []);
        $api_keys = isset($opts['api_keys']) && is_array($opts['api_keys']) ? $opts['api_keys'] : [];

        // Backward compatibility for installations saved before the explicit
        // enabled flag was introduced.
        if (!array_key_exists('public_api_enabled', $api_keys)) {
            return trim((string) ($api_keys['public_api_key'] ?? '')) !== '';
        }

        return (string) $api_keys['public_api_enabled'] === '1';
    }

    /**
     * Checks permissions for the REST API request.
     * Verifies if a public API key is configured and if the submitted key matches.
     * The key can be submitted either as 'aipkit_api_key' in the request parameters
     * or as a Bearer token in the 'Authorization' header.
     *
     * @param WP_REST_Request $request The current REST API request object.
     * @return bool|WP_Error True if the request has permission, WP_Error otherwise.
     */
    public function check_permissions(WP_REST_Request $request) {
        if (!self::is_public_api_enabled()) {
            return new WP_Error(
                'rest_aipkit_api_access_disabled',
                __('REST API access is disabled.', 'gpt3-ai-content-generator'),
                array('status' => 403)
            );
        }

        $stored_key = self::get_stored_public_api_key();

        // If no key is configured in settings, deny access.
        if (empty($stored_key)) {
            return new WP_Error(
                'rest_aipkit_no_api_key_configured',
                __('Public API access is not configured. Please set an API key in AIPKit settings.', 'gpt3-ai-content-generator'),
                array('status' => 403)
            );
        }

        // Check 'Authorization: Bearer <token>' header first.
        $auth_header = $request->get_header('Authorization');
        if (!empty($auth_header) && strpos(strtolower($auth_header), 'bearer ') === 0) {
            $submitted_key_header = trim((string) substr($auth_header, 7));
            if (hash_equals($stored_key, $submitted_key_header)) {
                return true;
            }
        }

        // Check 'aipkit_api_key' request parameter as a fallback.
        $submitted_key_param = $request->get_param('aipkit_api_key');
        if (!empty($submitted_key_param) && is_string($submitted_key_param)) {
            if (hash_equals($stored_key, $submitted_key_param)) {
                return true;
            }
        }

        return new WP_Error(
            'rest_aipkit_invalid_api_key',
            __('Invalid or missing API Key.', 'gpt3-ai-content-generator'),
            array('status' => 401)
        );
    }

    /**
     * Helper to send a WP_Error object as a REST API error response.
     *
     * @param WP_Error $error The WP_Error object.
     * @return WP_Error A WP_Error object formatted for REST response.
     */
    protected function send_wp_error_response(WP_Error $error): WP_Error {
        return new WP_Error(
            $error->get_error_code(),
            $error->get_error_message(),
            \WPAICG\Core\AIPKit_HTTP_Request::public_error_data($error)
        );
    }
}

/**
 * Handles REST API requests for text generation.
 */
class AIPKit_REST_Text_Handler extends AIPKit_REST_Base_Handler
{
    /**
     * Define arguments for the TEXT generation endpoint.
     */
    public function get_endpoint_args(): array
    {
        return array(
            'provider' => array(
                'description' => __('The AI provider to use for text generation.', 'gpt3-ai-content-generator'),
                'type'        => 'string',
                'enum'        => ['aipuffercloud', 'openai', 'azure', 'google', 'openrouter', 'claude', 'deepseek', 'xai', 'ollama'],
                'required'    => true,
            ),
            'model' => array(
                'description' => __('The specific text model or deployment ID.', 'gpt3-ai-content-generator'),
                'type'        => 'string',
                'required'    => true,
            ),
            'messages' => array(
                'description' => __('An array of message objects (role/content).', 'gpt3-ai-content-generator'),
                'type'        => 'array',
                'required'    => true,
                'items'       => array(
                    'type'       => 'object',
                    'properties' => array(
                        'role'    => array('type' => 'string', 'enum' => ['system', 'user', 'assistant'], 'required' => true),
                        'content' => array('type' => 'string', 'required' => true),
                    ),
                ),
            ),
            'stream' => array(
                'description' => __('Whether to stream the response (currently not supported via this endpoint).', 'gpt3-ai-content-generator'),
                'type'        => 'boolean',
                'default'     => false,
            ),
            'system_instruction' => array(
                'description' => __('Optional system instructions for the AI. Supports [date] and [username] placeholders.', 'gpt3-ai-content-generator'),
                'type'        => 'string',
            ),
            'ai_params' => array(
                'description' => __('Optional AI parameters (temperature, max_tokens, etc.).', 'gpt3-ai-content-generator'),
                'type'        => 'object',
            ),
            'aipkit_api_key' => array(
                'description' => __('API Key for accessing this endpoint (if required by settings). Send as parameter or Authorization: Bearer header.', 'gpt3-ai-content-generator'),
                'type'        => 'string',
            ),
        );
    }

    /**
     * Define the schema for the TEXT generation response.
     */
    public function get_item_schema(): array
    {
        return array(
           '$schema'    => 'http://json-schema.org/draft-04/schema#',
           'title'      => 'aipkit_generate_response',
           'type'       => 'object',
           'properties' => array(
               'content' => array(
                   'description' => esc_html__('The generated AI response content.', 'gpt3-ai-content-generator'),
                   'type'        => 'string',
                   'readonly'    => true,
               ),
               'usage' => array(
                   'description' => esc_html__('Token usage information.', 'gpt3-ai-content-generator'),
                   'type'        => ['object', 'null'],
                   'properties' => array(
                       'input_tokens' => array( 'type' => 'integer' ),
                       'output_tokens' => array( 'type' => 'integer' ),
                       'total_tokens' => array( 'type' => 'integer' ),
                   ),
                   'readonly' => true,
               ),
               'model' => array(
                   'description' => esc_html__('The model used for the response.', 'gpt3-ai-content-generator'),
                   'type'        => 'string',
                   'readonly'    => true,
               ),
                'provider' => array(
                   'description' => esc_html__('The provider used for the response.', 'gpt3-ai-content-generator'),
                   'type'        => 'string',
                   'readonly'    => true,
               ),
           ),
        );
    }

    /**
     * Handles the TEXT generation request.
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error on failure.
     */
    public function handle_request(WP_REST_Request $request)
    {
        $params = $request->get_params();
        $provider_raw = $params['provider'] ?? null;
        $model = $params['model'] ?? null;
        $messages = $params['messages'] ?? null;
        $stream = filter_var($params['stream'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $system_instruction = $params['system_instruction'] ?? null;
        $ai_params_override = $params['ai_params'] ?? [];

        if (empty($provider_raw)) {
            return $this->send_wp_error_response(new WP_Error('rest_aipkit_missing_param', __('Missing required parameter: provider', 'gpt3-ai-content-generator'), ['status' => 400]));
        }
        if (empty($model)) {
            return $this->send_wp_error_response(new WP_Error('rest_aipkit_missing_param', __('Missing required parameter: model', 'gpt3-ai-content-generator'), ['status' => 400]));
        }
        if (empty($messages) || !is_array($messages)) {
            return $this->send_wp_error_response(new WP_Error('rest_aipkit_invalid_param', __('Invalid or missing parameter: messages (must be an array)', 'gpt3-ai-content-generator'), ['status' => 400]));
        }
        if (!is_array($ai_params_override)) {
            return $this->send_wp_error_response(new WP_Error('rest_aipkit_invalid_param', __('Invalid parameter type: ai_params (must be an object)', 'gpt3-ai-content-generator'), ['status' => 400]));
        }
        if ($system_instruction !== null && !is_string($system_instruction)) {
            return $this->send_wp_error_response(new WP_Error('rest_aipkit_invalid_param', __('Invalid parameter type: system_instruction (must be a string)', 'gpt3-ai-content-generator'), ['status' => 400]));
        }
        if ($system_instruction !== null) {
            $system_instruction = AIPKit_Prompt_Sanitizer::sanitize($system_instruction);
        }

        $provider = AIPKit_Providers::normalize_provider_label((string) $provider_raw);
        if (!in_array($provider, AIPKit_Providers::get_text_generation_providers(), true)) {
            /* translators: %s is the invalid provider name */
            return $this->send_wp_error_response(new WP_Error('rest_aipkit_invalid_param', sprintf(__('Invalid provider specified: %s', 'gpt3-ai-content-generator'), $provider_raw), ['status' => 400]));
        }
        foreach ($messages as $index => $msg) {
            if (!is_array($msg) || empty($msg['role']) || !in_array($msg['role'], ['system', 'user', 'assistant']) || !isset($msg['content']) || !is_string($msg['content'])) {
                /* translators: %d is the index of the message in the array */
                return $this->send_wp_error_response(new WP_Error('rest_aipkit_invalid_message_format', sprintf(__('Invalid message format at index %d.', 'gpt3-ai-content-generator'), $index), ['status' => 400]));
            }
            $messages[$index]['role'] = sanitize_key($msg['role']);
            $messages[$index]['content'] = AIPKit_Prompt_Sanitizer::sanitize($msg['content']);
        }
        if ($stream) {
            return $this->send_wp_error_response(new WP_Error('rest_aipkit_streaming_not_supported', __('Streaming responses are not supported via this REST endpoint.', 'gpt3-ai-content-generator'), ['status' => 400]));
        }
        if (!class_exists(AIPKit_AI_Caller::class) || !class_exists(AIPKit_Instruction_Manager::class) || !class_exists(AIPKit_Providers::class) || !class_exists(AIPKIT_AI_Settings::class)) {
            return $this->send_wp_error_response(new WP_Error('rest_aipkit_internal_error', __('Internal server error.', 'gpt3-ai-content-generator'), ['status' => 500]));
        }

        $ai_caller = new AIPKit_AI_Caller(false, 'rest_api');
        $global_ai_params = AIPKIT_AI_Settings::get_ai_parameters();
        $final_ai_params = array_merge($global_ai_params, $ai_params_override);
        if (isset($final_ai_params['temperature'])) {
            $final_ai_params['temperature'] = max(0.0, min(2.0, floatval($final_ai_params['temperature'])));
        }
        if (isset($final_ai_params['max_completion_tokens'])) {
            $final_ai_params['max_completion_tokens'] = max(1, min(128000, absint($final_ai_params['max_completion_tokens'])));
        }
        $instruction_context = ['base_instructions' => $system_instruction ?? ''];
        $instructions_processed = AIPKit_Instruction_Manager::build_instructions($instruction_context);

        $result = $ai_caller->make_standard_call($provider, $model, $messages, $final_ai_params, $instructions_processed);

        if (is_wp_error($result)) {
            return $this->send_wp_error_response($result);
        }

        $response_data = [ 'content' => $result['content'] ?? '', 'usage' => $result['usage'] ?? null, 'provider' => $provider, 'model' => $model ];
        if (isset($response_data['usage']['provider_raw'])) {
            unset($response_data['usage']['provider_raw']);
        }
        return new WP_REST_Response($response_data, 200);
    }
}

/**
 * Handles REST API requests for image generation.
 */
class AIPKit_REST_Image_Handler extends AIPKit_REST_Base_Handler {

    /**
     * Define arguments for the IMAGE generation endpoint.
     */
    public function get_endpoint_args(): array {
        return array(
            'prompt' => array(
                'description' => __('A text description of the desired image(s).', 'gpt3-ai-content-generator'),
                'type'        => 'string',
                'required'    => true,
                'sanitize_callback' => static function ($value, $request = null, $param = null): string {
                    return AIPKit_Prompt_Sanitizer::sanitize($value);
                },
            ),
            'provider' => array(
                'description' => __('The AI image provider to use.', 'gpt3-ai-content-generator'),
                'type'        => 'string',
                'enum'        => ['aipuffercloud', 'openai', 'openrouter', 'azure', 'google'],
                'default'     => 'openai',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'model' => array(
                'description' => __('The provider-specific image model ID.', 'gpt3-ai-content-generator'),
                'type'        => 'string',
                'required'    => false,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'n' => array(
                'description' => __('The number of images to generate.', 'gpt3-ai-content-generator'),
                'type'        => 'integer',
                'default'     => 1,
                'minimum'     => 1,
                'maximum'     => 10,
                'sanitize_callback' => 'absint',
            ),
            'size' => array(
                'description' => __('The size of the generated images.', 'gpt3-ai-content-generator'),
                'type'        => 'string',
                'required'    => false,
                'enum'        => ['256x256', '512x512', '1024x1024', '1792x1024', '1024x1792', '1536x1024', '1024x1536', '1024x768', '768x1024'],
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'quality' => array(
                'description' => __('The quality of the image for supported provider/model combinations.', 'gpt3-ai-content-generator'),
                'type'        => 'string',
                'enum'        => ['auto', 'low', 'medium', 'high', 'standard', 'hd'],
                'required'    => false,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'aspect_ratio' => array(
                'description' => __('The generated image aspect ratio when supported by the selected model.', 'gpt3-ai-content-generator'),
                'type'        => 'string',
                'required'    => false,
                'enum'        => ['auto', '1:1', '1:2', '2:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '9:19.5', '19.5:9', '9:20', '20:9', '9:21', '21:9', '1:4', '4:1', '1:8', '8:1'],
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'resolution' => array(
                'description' => __('The normalized output resolution tier when supported.', 'gpt3-ai-content-generator'),
                'type'        => 'string',
                'required'    => false,
                'enum'        => ['512', '1K', '2K', '4K'],
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'output_format' => array(
                'description' => __('The generated raster image format when supported.', 'gpt3-ai-content-generator'),
                'type'        => 'string',
                'required'    => false,
                'enum'        => ['png', 'jpeg', 'webp'],
                'sanitize_callback' => 'sanitize_key',
            ),
            'output_compression' => array(
                'description' => __('JPEG or WebP compression from 0 to 100 when supported.', 'gpt3-ai-content-generator'),
                'type'        => 'integer',
                'required'    => false,
                'minimum'     => 0,
                'maximum'     => 100,
                'sanitize_callback' => 'absint',
            ),
            'background' => array(
                'description' => __('The generated image background mode when supported.', 'gpt3-ai-content-generator'),
                'type'        => 'string',
                'required'    => false,
                'enum'        => ['auto', 'transparent', 'opaque'],
                'sanitize_callback' => 'sanitize_key',
            ),
            'seed' => array(
                'description' => __('A deterministic generation seed when supported.', 'gpt3-ai-content-generator'),
                'type'        => 'integer',
                'required'    => false,
                'minimum'     => 0,
                'sanitize_callback' => 'absint',
            ),
             'style' => array(
                'description' => __('The style of the generated images when supported by the selected model.', 'gpt3-ai-content-generator'),
                'type'        => 'string',
                'enum'        => ['vivid', 'natural'],
                'required'    => false,
                'sanitize_callback' => 'sanitize_text_field',
            ),
             'response_format' => array(
                'description' => __('The format in which the generated images are returned (url or b64_json).', 'gpt3-ai-content-generator'),
                'type'        => 'string',
                'enum'        => ['url', 'b64_json'],
                'default'     => 'url',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'aipkit_api_key' => array(
                'description' => __('API Key for accessing this endpoint (if required by settings).', 'gpt3-ai-content-generator'),
                'type'        => 'string',
            ),
        );
    }

    /**
     * Define the schema for the IMAGE generation response.
     */
    public function get_item_schema(): array {
         return array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'aipkit_image_generate_response',
            'type'       => 'object',
            'properties' => array(
                'images' => array(
                    'description' => esc_html__( 'An array of generated image data objects.', 'gpt3-ai-content-generator' ),
                    'type'        => 'array',
                    'readonly'    => true,
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'url'            => array( 'type' => ['string', 'null'], 'description' => 'URL of the generated image, valid for 60 minutes.' ),
                            'b64_json'       => array( 'type' => ['string', 'null'], 'description' => 'Base64 encoded JSON data of the image.' ),
                            'mime_type'      => array( 'type' => ['string', 'null'], 'description' => 'Media type of the generated image.' ),
                            'revised_prompt' => array( 'type' => ['string', 'null'], 'description' => 'Revised prompt used by the model (if applicable).' ),
                        ),
                    ),
                ),
                'message' => array(
                    'description' => esc_html__( 'A status message indicating success or failure count.', 'gpt3-ai-content-generator' ),
                    'type'        => 'string',
                    'readonly'    => true,
                ),
                 'usage' => array(
                    'description' => esc_html__( 'Token usage information (if available).', 'gpt3-ai-content-generator' ),
                    'type'        => ['object', 'null'],
                    'properties'  => array(
                        'input_tokens' => array('type' => 'integer'),
                        'output_tokens' => array('type' => 'integer'),
                        'total_tokens' => array('type' => 'integer'),
                        'cost' => array('type' => 'number'),
                    ),
                    'readonly'    => true,
                ),
            ),
        );
    }

    /**
     * Handles the IMAGE generation request.
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error on failure.
     */
    public function handle_request(WP_REST_Request $request) {
        if (!class_exists(AIPKit_Image_Manager::class)) {
             return $this->send_wp_error_response(new WP_Error('rest_aipkit_internal_error', __('Internal server error: Image generation component not loaded.', 'gpt3-ai-content-generator'), ['status' => 500]));
        }

        $params = $request->get_params();
        $prompt = isset($params['prompt']) ? AIPKit_Prompt_Sanitizer::sanitize($params['prompt']) : '';
        $options = [
            'provider'        => isset($params['provider']) ? sanitize_text_field($params['provider']) : 'openai',
            'model'           => isset($params['model']) ? sanitize_text_field($params['model']) : null,
            'size'            => isset($params['size']) ? sanitize_text_field($params['size']) : null,
            'n'               => isset($params['n']) ? absint($params['n']) : 1,
            'quality'         => isset($params['quality']) ? sanitize_text_field($params['quality']) : null,
            'aspect_ratio'    => isset($params['aspect_ratio']) ? sanitize_text_field($params['aspect_ratio']) : null,
            'resolution'      => isset($params['resolution']) ? sanitize_text_field($params['resolution']) : null,
            'output_format'   => isset($params['output_format']) ? sanitize_key($params['output_format']) : null,
            'output_compression' => isset($params['output_compression']) ? absint($params['output_compression']) : null,
            'background'      => isset($params['background']) ? sanitize_key($params['background']) : null,
            'seed'            => isset($params['seed']) ? absint($params['seed']) : null,
            'style'           => isset($params['style']) ? sanitize_text_field($params['style']) : null,
            'response_format' => isset($params['response_format']) ? sanitize_text_field($params['response_format']) : 'url',
            'user'            => 'rest_api_user',
            'aipkit_event_module' => 'rest_api',
            'aipkit_event_origin' => 'rest_image_request',
        ];

        if (empty($prompt)) {
             return $this->send_wp_error_response(new WP_Error('rest_aipkit_missing_prompt', __('Missing required parameter: prompt', 'gpt3-ai-content-generator'), ['status' => 400]));
        }

        if (strtolower($options['provider']) === 'aipuffercloud') {
            if (empty($options['model'])) {
                return $this->send_wp_error_response(new WP_Error('rest_aipkit_cloud_image_model_required', __('Choose a Cloud image model before generating.', 'gpt3-ai-content-generator'), ['status' => 400]));
            }
            if ($options['response_format'] !== 'b64_json') {
                return $this->send_wp_error_response(new WP_Error('rest_aipkit_cloud_image_format', __('Cloud images require response_format=b64_json in REST requests.', 'gpt3-ai-content-generator'), ['status' => 400]));
            }
            foreach (['quality', 'aspect_ratio', 'resolution', 'output_format', 'output_compression', 'background', 'seed', 'style'] as $unsupported_option) {
                if ($options[$unsupported_option] !== null) {
                    return $this->send_wp_error_response(new WP_Error('rest_aipkit_cloud_image_option', __('The selected Cloud image model does not support this image option.', 'gpt3-ai-content-generator'), ['status' => 400]));
                }
            }
        }

        $image_manager = new AIPKit_Image_Manager();
        $result = $image_manager->generate_image($prompt, $options);

        if (is_wp_error($result)) {
            return $this->send_wp_error_response($result);
        }
        $response_data = [
            'images' => $result['images'] ?? [],
            'usage' => $result['usage'] ?? null,
            /* translators: %d is the count of images generated */
            'message' => sprintf(_n('%d image generated successfully.', '%d images generated successfully.', count($result['images'] ?? []), 'gpt3-ai-content-generator'), count($result['images'] ?? [])),
        ];
        return new WP_REST_Response($response_data, 200);
    }
}

/**
 * Handles REST API requests for generating embeddings.
 */
class AIPKit_REST_Embeddings_Handler extends AIPKit_REST_Base_Handler
{
    /**
     * Define arguments for the EMBEDDINGS generation endpoint.
     */
    public function get_endpoint_args(): array
    {
        $embedding_provider_keys = AIPKit_Providers::get_embedding_provider_keys('rest_embeddings_endpoint_args');

        return array(
            'provider' => array(
                'description' => __('The AI provider key for embeddings (from enabled embedding providers).', 'gpt3-ai-content-generator'),
                'type'        => 'string',
                'enum'        => $embedding_provider_keys,
                'required'    => true,
            ),
            'model' => array(
                'description' => __('The specific embedding model ID (or Azure deployment ID).', 'gpt3-ai-content-generator'),
                'type'        => 'string',
                'required'    => true,
            ),
            'input' => array(
                'description' => __('Input text(s) to embed. String or array of strings.', 'gpt3-ai-content-generator'),
                'type'        => ['string', 'array'],
                'required'    => true,
                'items'       => ['type' => 'string'],
            ),
            'dimensions' => array(
                'description' => __('(OpenAI/OpenRouter text-embedding-3+, Azure) Number of dimensions for output embeddings.', 'gpt3-ai-content-generator'),
                'type'        => 'integer',
                'required'    => false,
            ),
            'encoding_format' => array(
                'description' => __('(OpenAI/OpenRouter) Format to return embeddings: float or base64.', 'gpt3-ai-content-generator'),
                'type'        => 'string',
                'enum'        => ['float', 'base64'],
                'default'     => 'float',
            ),
            'task_type' => array(
                'description' => __('(Google Gemini Embeddings) Optimized task type for embeddings.', 'gpt3-ai-content-generator'),
                'type'        => 'string',
                'enum'        => ['SEMANTIC_SIMILARITY', 'CLASSIFICATION', 'CLUSTERING', 'RETRIEVAL_DOCUMENT', 'RETRIEVAL_QUERY', 'QUESTION_ANSWERING', 'FACT_VERIFICATION', 'CODE_RETRIEVAL_QUERY'],
                'required'    => false,
            ),
            'output_dimensionality' => array(
                'description' => __('(Google Gemini Embeddings) Output dimension size for embeddings.', 'gpt3-ai-content-generator'),
                'type'        => 'integer',
                'required'    => false,
            ),
            'user' => array(
                'description' => __('(OpenAI, Azure) End-user identifier for abuse monitoring.', 'gpt3-ai-content-generator'),
                'type'        => 'string',
                'required'    => false,
            ),
            'aipkit_api_key' => array(
                'description' => __('API Key for accessing this endpoint (if required by settings).', 'gpt3-ai-content-generator'),
                'type'        => 'string',
            ),
        );
    }

    /**
     * Define the schema for the EMBEDDINGS generation response.
     */
    public function get_item_schema(): array
    {
        return array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'aipkit_embeddings_response',
            'type'       => 'object',
            'properties' => array(
                'embeddings' => array(
                    'description' => esc_html__('An array of embedding vectors (arrays of floats).', 'gpt3-ai-content-generator'),
                    'type'        => 'array',
                    'items'       => array('type' => 'array', 'items' => array('type' => 'number')),
                    'readonly'    => true,
                ),
                'usage' => array(
                    'description' => esc_html__('Token usage information.', 'gpt3-ai-content-generator'),
                    'type'        => ['object', 'null'],
                    'properties'  => array(
                        'input_tokens' => array('type' => 'integer'),
                        'total_tokens' => array('type' => 'integer'),
                    ),
                    'readonly'    => true,
                ),
                'model' => array(
                    'description' => esc_html__('The model used for the embeddings.', 'gpt3-ai-content-generator'),
                    'type'        => 'string',
                    'readonly'    => true,
                ),
                'provider' => array(
                    'description' => esc_html__('The provider used for the embeddings.', 'gpt3-ai-content-generator'),
                    'type'        => 'string',
                    'readonly'    => true,
                ),
            ),
        );
    }

    /**
     * Handles the EMBEDDINGS generation request.
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error on failure.
     */
    public function handle_request(WP_REST_Request $request)
    {
        $params = $request->get_params();
        $provider_raw = $params['provider'] ?? null;
        $model = $params['model'] ?? null;
        $input = $params['input'] ?? null;

        if (empty($provider_raw)) {
            return $this->send_wp_error_response(new WP_Error('rest_aipkit_missing_param', __('Missing required parameter: provider', 'gpt3-ai-content-generator'), ['status' => 400]));
        }
        if (empty($model)) {
            return $this->send_wp_error_response(new WP_Error('rest_aipkit_missing_param', __('Missing required parameter: model', 'gpt3-ai-content-generator'), ['status' => 400]));
        }
        if (empty($input)) {
            return $this->send_wp_error_response(new WP_Error('rest_aipkit_missing_param', __('Missing required parameter: input', 'gpt3-ai-content-generator'), ['status' => 400]));
        }
        if (!is_string($input) && !is_array($input)) {
            return $this->send_wp_error_response(new WP_Error('rest_aipkit_invalid_param', __('Invalid parameter type: input (must be a string or array of strings)', 'gpt3-ai-content-generator'), ['status' => 400]));
        }
        if (is_array($input)) {
            foreach ($input as $idx => $text) {
                if (!is_string($text)) {
                    /* translators: %s is the index of the input array */
                    return $this->send_wp_error_response(new WP_Error('rest_aipkit_invalid_param', sprintf(__('Invalid input array item at index %d (must be a string)', 'gpt3-ai-content-generator'), $idx), ['status' => 400]));
                }
            }
        }

        $provider = AIPKit_Providers::resolve_embedding_provider_name(
            $provider_raw,
            'rest_embeddings_handle_request'
        );
        if ($provider === null) {
            /* translators: %s is the provider name */
            return $this->send_wp_error_response(new WP_Error('rest_aipkit_invalid_param', sprintf(__('Invalid provider specified for embeddings: %s', 'gpt3-ai-content-generator'), $provider_raw), ['status' => 400]));
        }

        if (!class_exists(AIPKit_AI_Caller::class)) {
            return $this->send_wp_error_response(new WP_Error('rest_aipkit_internal_error', __('Internal server error.', 'gpt3-ai-content-generator'), ['status' => 500]));
        }

        $ai_caller = new AIPKit_AI_Caller(false, 'rest_api');
        $embedding_options = [
            'model' => sanitize_text_field($model)
        ];

        if ($provider === 'OpenAI' || $provider === 'Azure' || $provider === 'OpenRouter') {
            if (isset($params['dimensions'])) {
                $embedding_options['dimensions'] = absint($params['dimensions']);
            }
            if (isset($params['user'])) {
                $embedding_options['user'] = sanitize_text_field($params['user']);
            }
            if (($provider === 'OpenAI' || $provider === 'OpenRouter') && isset($params['encoding_format'])) {
                $embedding_options['encoding_format'] = sanitize_key($params['encoding_format']);
            }
        }
        if ($provider === 'AIPufferCloud' && isset($params['dimensions'])) {
            $embedding_options['dimensions'] = absint($params['dimensions']);
        }
        if ($provider === 'Google') {
            if (isset($params['task_type'])) {
                $task_type = strtoupper(sanitize_text_field((string) $params['task_type']));
                $allowed_task_types = [
                    'SEMANTIC_SIMILARITY',
                    'CLASSIFICATION',
                    'CLUSTERING',
                    'RETRIEVAL_DOCUMENT',
                    'RETRIEVAL_QUERY',
                    'QUESTION_ANSWERING',
                    'FACT_VERIFICATION',
                    'CODE_RETRIEVAL_QUERY',
                ];
                if (in_array($task_type, $allowed_task_types, true)) {
                    $embedding_options['taskType'] = $task_type;
                }
            }
            if (isset($params['output_dimensionality'])) {
                $embedding_options['outputDimensionality'] = absint($params['output_dimensionality']);
            }
        }

        $result = $ai_caller->generate_embeddings($provider, $input, $embedding_options);

        if (is_wp_error($result)) {
            return $this->send_wp_error_response($result);
        }

        $response_data = [
            'embeddings' => $result['embeddings'] ?? [],
            'usage'      => $result['usage'] ?? null,
            'provider'   => $provider,
            'model'      => $model,
        ];
        return new WP_REST_Response($response_data, 200);
    }
}

/**
 * Handles REST API requests for interacting with a specific chatbot.
 */
class AIPKit_REST_Chat_Handler extends AIPKit_REST_Base_Handler
{
    private $bot_storage;
    private $ai_service;

    public function __construct()
    {
        // These dependencies are loaded by the main plugin loader.
        if (class_exists(BotStorage::class)) {
            $this->bot_storage = new BotStorage();
        }
        if (class_exists(ChatAIService::class)) {
            $this->ai_service = new ChatAIService();
        }
    }

    /**
     * Define arguments for the chatbot message endpoint.
     */
    public function get_endpoint_args(): array
    {
        return array(
            'bot_id' => array(
                'description' => __('The ID of the chatbot to interact with.', 'gpt3-ai-content-generator'),
                'type'        => 'integer',
                'required'    => true,
                'validate_callback' => function ($param) { return is_numeric($param); }
            ),
            'messages' => array(
                'description' => __('An array of message objects representing the conversation history.', 'gpt3-ai-content-generator'),
                'type'        => 'array',
                'required'    => true,
                'items'       => array(
                    'type'       => 'object',
                    'properties' => array(
                        'role'    => array('type' => 'string', 'enum' => ['user', 'assistant'], 'required' => true),
                        'content' => array('type' => 'string', 'required' => true),
                    ),
                ),
            ),
            'conversation_uuid' => array(
                'description' => __('An optional stable conversation identifier used for provider session stickiness.', 'gpt3-ai-content-generator'),
                'type' => 'string',
                'required' => false,
                'sanitize_callback' => 'sanitize_key',
            ),
            'aipkit_api_key' => array(
                'description' => __('API Key for accessing this endpoint.', 'gpt3-ai-content-generator'),
                'type'        => 'string',
            ),
        );
    }

    /**
     * Define the schema for the chatbot message response.
     */
    public function get_item_schema(): array
    {
        return array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'aipkit_chat_message_response',
            'type'       => 'object',
            'properties' => array(
                'reply' => array(
                    'description' => esc_html__('The chatbot\'s generated reply.', 'gpt3-ai-content-generator'),
                    'type'        => 'string',
                    'readonly'    => true,
                ),
                'usage' => array(
                    'description' => esc_html__('Token usage information for the interaction.', 'gpt3-ai-content-generator'),
                    'type'        => ['object', 'null'],
                    'readonly'    => true,
                ),
                'bot_id' => array(
                    'description' => esc_html__('The ID of the bot that replied.', 'gpt3-ai-content-generator'),
                    'type'        => 'integer',
                    'readonly'    => true,
                ),
                'model' => array(
                    'description' => esc_html__('The model used for the response.', 'gpt3-ai-content-generator'),
                    'type'        => 'string',
                    'readonly'    => true,
                ),
            ),
        );
    }

    /**
     * Handles the chatbot message request.
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error on failure.
     */
    public function handle_request(WP_REST_Request $request)
    {
        if (!$this->bot_storage || !$this->ai_service) {
            return $this->send_wp_error_response(new WP_Error('rest_aipkit_internal_error', __('Internal server error: Chat components not loaded.', 'gpt3-ai-content-generator'), ['status' => 500]));
        }

        $bot_id = (int) $request->get_param('bot_id');
        $messages = $request->get_param('messages');
        $conversation_uuid = sanitize_key((string) $request->get_param('conversation_uuid'));

        // Basic validation
        if (empty($messages) || !is_array($messages)) {
            return $this->send_wp_error_response(new WP_Error('rest_aipkit_invalid_messages', __('The "messages" parameter must be a non-empty array.', 'gpt3-ai-content-generator'), ['status' => 400]));
        }

        $user_message_obj = end($messages);
        if (!$user_message_obj || $user_message_obj['role'] !== 'user' || empty($user_message_obj['content'])) {
            return $this->send_wp_error_response(new WP_Error('rest_aipkit_invalid_last_message', __('The last message in the array must be from the "user" role and have content.', 'gpt3-ai-content-generator'), ['status' => 400]));
        }

        $user_message_text = $user_message_obj['content'];
        $history = array_slice($messages, 0, -1);

        // Fetch bot settings
        $bot_settings = $this->bot_storage->get_chatbot_settings($bot_id);
        if (empty($bot_settings)) {
            return $this->send_wp_error_response(new WP_Error('rest_aipkit_bot_not_found', __('The specified chatbot was not found.', 'gpt3-ai-content-generator'), ['status' => 404]));
        }

        // Call the core AI service to get a response
        $ai_result = $this->ai_service->generate_response(
            $user_message_text,
            $bot_settings,
            $history,
            0, // post_id is not relevant for this REST context
            null, // frontend_previous_openai_response_id
            false, // frontend_openai_web_search_active
            false, // frontend_google_search_grounding_active
            null, // image_inputs_for_service
            null, // frontend_active_openai_vs_id
            null, // frontend_active_pinecone_index_name
            null, // frontend_active_pinecone_namespace
            null, // frontend_active_qdrant_collection_name
            null, // frontend_active_qdrant_file_upload_context_id
            null, // frontend_active_chroma_collection_name
            null, // frontend_active_chroma_file_upload_context_id
            null, // frontend_active_claude_file_id
            $conversation_uuid !== '' ? $conversation_uuid : null
        );

        if (is_wp_error($ai_result)) {
            return $this->send_wp_error_response($ai_result);
        }

        require_once dirname(__DIR__) . '/usage/ledger.php';
        \WPAICG\Core\TokenManager\Ledger\AIPKit_Ledger_Repository::record_provider_request(
            (string) ($bot_settings['provider'] ?? ''), (string) ($bot_settings['model'] ?? ''),
            $ai_result['usage'] ?? null, 'rest_api', 'text'
        );

        $response_data = [
            'reply'    => $ai_result['content'] ?? '',
            'usage'    => $ai_result['usage'] ?? null,
            'bot_id'   => $bot_id,
            'model'    => $bot_settings['model'] ?? 'unknown',
        ];

        return new WP_REST_Response($response_data, 200);
    }
}

/**
 * Handles REST API requests for interacting with Vector Stores (upserting data).
 */
class AIPKit_REST_Vector_Store_Handler extends AIPKit_REST_Base_Handler
{
    private $ai_caller;
    private $vector_store_manager;

    public function __construct()
    {
        if (class_exists(AIPKit_AI_Caller::class)) {
            $this->ai_caller = new AIPKit_AI_Caller(false, 'rest_api');
        }
        if (class_exists(AIPKit_Vector_Store_Manager::class)) {
            $this->vector_store_manager = new AIPKit_Vector_Store_Manager();
        }
    }

    public function get_endpoint_args(): array
    {
        $embedding_provider_keys = AIPKit_Providers::get_embedding_provider_keys('rest_vector_store_endpoint_args');

        return array(
            'provider' => array(
                'description' => __('The vector database provider.', 'gpt3-ai-content-generator'),
                'type'        => 'string',
                'enum'        => ['local', 'pinecone', 'qdrant', 'chroma'],
                'required'    => true,
            ),
            'target_id' => array(
                'description' => __('The name of the target index (for Pinecone) or collection (for Qdrant/Chroma).', 'gpt3-ai-content-generator'),
                'type'        => 'string',
                'required'    => true,
            ),
            'vectors' => array(
                'description' => __('An array of objects to be embedded and upserted.', 'gpt3-ai-content-generator'),
                'type'        => 'array',
                'required'    => true,
                'items'       => array(
                    'type'       => 'object',
                    'properties' => array(
                        'id'       => array('type' => 'string', 'description' => 'A unique ID for the vector. If omitted, one will be generated.', 'required' => false),
                        'content'  => array('type' => 'string', 'description' => 'The text content to be embedded.', 'required' => true),
                        'metadata' => array('type' => 'object', 'description' => 'Key-value metadata to store with the vector.', 'required' => false),
                        'uri'      => array('type' => 'string', 'description' => 'Optional URI associated with the vector.', 'required' => false),
                    ),
                ),
            ),
            'embedding_provider' => array(
                'description' => __('The AI provider to use for generating embeddings, including Local stores.', 'gpt3-ai-content-generator'),
                'type'        => 'string',
                'enum'        => $embedding_provider_keys,
                'required'    => false,
            ),
            'embedding_model' => array(
                'description' => __('The specific model ID to use for generating embeddings.', 'gpt3-ai-content-generator'),
                'type'        => 'string',
                'required'    => false,
            ),
            'namespace' => array(
                 'description' => __('(Pinecone only) The namespace to upsert vectors into.', 'gpt3-ai-content-generator'),
                 'type'        => 'string',
                 'required'    => false,
            ),
            'aipkit_api_key' => array(
                'description' => __('API Key for accessing this endpoint.', 'gpt3-ai-content-generator'),
                'type'        => 'string',
            ),
        );
    }

    public function get_item_schema(): array
    {
         return array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'aipkit_vector_upsert_response',
            'type'       => 'object',
            'properties' => array(
                'upserted_count' => array(
                    'description' => esc_html__('The number of vectors successfully processed and sent for upserting.', 'gpt3-ai-content-generator'),
                    'type'        => 'integer',
                    'readonly'    => true,
                ),
                'status' => array(
                    'description' => esc_html__('The final status from the vector database provider.', 'gpt3-ai-content-generator'),
                    'type'        => 'string',
                    'readonly'    => true,
                ),
            ),
        );
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function handle_request(WP_REST_Request $request)
    {
        if (!$this->ai_caller || !$this->vector_store_manager) {
            return $this->send_wp_error_response(new WP_Error('rest_aipkit_internal_error', __('Internal server error: Vector components not loaded.', 'gpt3-ai-content-generator'), ['status' => 500]));
        }

        $params = $request->get_params();
        $provider_key = sanitize_key((string) ($params['provider'] ?? ''));
        $provider_map = [
            'local' => 'Local',
            'pinecone' => 'Pinecone',
            'qdrant'  => 'Qdrant',
            'chroma'  => 'Chroma',
        ];
        if (!isset($provider_map[$provider_key])) {
            return $this->send_wp_error_response(new WP_Error('rest_aipkit_invalid_vector_provider', __('Invalid vector database provider.', 'gpt3-ai-content-generator'), ['status' => 400]));
        }
        $provider_normalized = $provider_map[$provider_key];
        $target_id = $params['target_id'];
        $vectors_data = $params['vectors'];
        $embedding_provider_key = (string) ($params['embedding_provider'] ?? '');
        $embedding_model = (string) ($params['embedding_model'] ?? '');
        $namespace = $params['namespace'] ?? null;
        if ($provider_key === 'local') {
            if (!class_exists(\WPAICG\Vector\Providers\AIPKit_Vector_Local_Strategy::class)) {
                require_once WPAICG_PLUGIN_DIR . 'classes/knowledge-base/providers/local.php';
            }
            $local_store = (new \WPAICG\Vector\Providers\AIPKit_Vector_Local_Strategy())->get_store(\WPAICG\Vector\Providers\AIPKit_Vector_Local_Strategy::store_id((string) $target_id));
            if (!$local_store) {
                return $this->send_wp_error_response(new WP_Error('rest_aipkit_local_store_missing', __('Knowledge base not found.', 'gpt3-ai-content-generator'), ['status' => 404]));
            }
            $target_id = $local_store['id'];
        }
        if ($embedding_provider_key === '' || $embedding_model === '') {
            return $this->send_wp_error_response(new WP_Error('rest_aipkit_missing_embedding', __('The "embedding_provider" and "embedding_model" parameters are required.', 'gpt3-ai-content-generator'), ['status' => 400]));
        }

        if (empty($vectors_data) || !is_array($vectors_data)) {
            return $this->send_wp_error_response(new WP_Error('rest_aipkit_invalid_vectors', __('The "vectors" parameter must be a non-empty array.', 'gpt3-ai-content-generator'), ['status' => 400]));
        }

        foreach ($vectors_data as $item) {
            if (!is_array($item) || !isset($item['content']) || !is_scalar($item['content']) || trim((string) $item['content']) === '') {
                return $this->send_wp_error_response(new WP_Error('rest_aipkit_no_content', __('Each object in the "vectors" array must have a non-empty "content" key.', 'gpt3-ai-content-generator'), ['status' => 400]));
            }
        }

        $provider_config = $provider_key === 'local' ? [] : AIPKit_Providers::get_provider_data($provider_normalized);
        $ingestion_service = new AIPKit_Vector_Text_Ingestion_Service($this->vector_store_manager, $this->ai_caller);
        $results = [];
        $total_upserted = 0;
        $total_chunks = 0;
        $options = [];
        if ($provider_key === 'pinecone' && is_scalar($namespace) && (string) $namespace !== '') {
            $options['namespace'] = (string) $namespace;
        }

        foreach (array_values($vectors_data) as $source_index => $item) {
            $metadata = isset($item['metadata']) && is_array($item['metadata']) ? $item['metadata'] : [];
            if (isset($item['id']) && is_scalar($item['id']) && (string) $item['id'] !== '') {
                $metadata['vector_id'] = (string) $item['id'];
            }
            if (isset($item['uri']) && is_scalar($item['uri']) && (string) $item['uri'] !== '') {
                $metadata['uri'] = (string) $item['uri'];
            }

            $result = $ingestion_service->ingest_text(
                $provider_normalized,
                (string) $target_id,
                (string) $item['content'],
                (string) $embedding_provider_key,
                (string) $embedding_model,
                $metadata,
                $provider_config,
                $options
            );

            if (is_wp_error($result)) {
                $error = $this->send_wp_error_response($result);
                $error->add_data(array_merge($error->get_error_data(), [
                    'completed_sources' => count($results),
                    'failed_source_index' => $source_index,
                    'source_count' => count($vectors_data),
                    'results' => $results,
                    'upserted_count' => $total_upserted,
                    'total_chunks' => $total_chunks,
                ]));
                return $error;
            }

            $total_upserted += (int) ($result['upserted_count'] ?? 0);
            $total_chunks += (int) ($result['total_chunks'] ?? 0);
            $results[] = [
                'parent_vector_id' => $result['parent_vector_id'] ?? null,
                'upserted_count' => (int) ($result['upserted_count'] ?? 0),
                'total_chunks' => (int) ($result['total_chunks'] ?? 0),
            ];
        }

        $response_data = [
            'upserted_count' => $total_upserted,
            'status' => 'success',
            'source_count' => count($vectors_data),
            'total_chunks' => $total_chunks,
            'results' => $results,
        ];

        return new WP_REST_Response($response_data, 200);
    }
}

/**
 * Handles REST API requests for retrieving chatbot conversation logs.
 */
class AIPKit_REST_Logs_Handler extends AIPKit_REST_Base_Handler
{
    private $log_storage;

    public function __construct()
    {
        if (class_exists(LogStorage::class)) {
            $this->log_storage = new LogStorage();
        }
    }

    /**
     * Define arguments for the logs endpoint.
     */
    public function get_endpoint_args(): array
    {
        return array(
            'page' => array(
                'description' => __('The page number for pagination.', 'gpt3-ai-content-generator'),
                'type'        => 'integer',
                'default'     => 1,
                'sanitize_callback' => 'absint',
            ),
            'per_page' => array(
                'description' => __('The number of conversation logs to return per page.', 'gpt3-ai-content-generator'),
                'type'        => 'integer',
                'default'     => 20,
                'sanitize_callback' => 'absint',
            ),
            'bot_id' => array(
                'description' => __('Filter logs for a specific chatbot ID.', 'gpt3-ai-content-generator'),
                'type'        => 'integer',
                'sanitize_callback' => 'absint',
            ),
            'user_search' => array(
                'description' => __("Search for logs by a user's display name, username, or email.", 'gpt3-ai-content-generator'),
                'type'        => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'message_search' => array(
                'description' => __('Search for logs where the conversation contains specific text.', 'gpt3-ai-content-generator'),
                'type'        => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'aipkit_api_key' => array(
                'description' => __('API Key for accessing this endpoint.', 'gpt3-ai-content-generator'),
                'type'        => 'string',
            ),
        );
    }

    /**
     * Define the schema for the logs response.
     */
    public function get_item_schema(): array
    {
        return array(
           '$schema'    => 'http://json-schema.org/draft-04/schema#',
           'title'      => 'aipkit_logs_response',
           'type'       => 'array',
           'items'      => array(
                'type' => 'object',
                'properties' => array(
                    'id' => array('type' => 'string'),
                    'bot_id' => array('type' => ['string', 'null']),
                    'user_id' => array('type' => ['string', 'null']),
                    'session_id' => array('type' => ['string', 'null']),
                    'conversation_uuid' => array('type' => 'string'),
                    'messages' => array('type' => 'string', 'description' => 'A JSON string of the full conversation history.'),
                    'message_count' => array('type' => 'string'),
                    'last_message_ts' => array('type' => 'string'),
                    'created_at' => array('type' => 'string', 'format' => 'date-time'),
                    'bot_name' => array('type' => 'string'),
                    'user_display_name' => array('type' => 'string'),
                ),
            ),
        );
    }


    /**
     * Handles the logs request.
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error on failure.
     */
    public function handle_request(WP_REST_Request $request)
    {
        if (!$this->log_storage) {
            return $this->send_wp_error_response(new WP_Error('rest_aipkit_internal_error', __('Internal server error: Log storage component not loaded.', 'gpt3-ai-content-generator'), ['status' => 500]));
        }

        $params = $request->get_params();
        $filters = [];
        if (!empty($params['bot_id'])) {
            $filters['bot_id'] = $params['bot_id'];
        }
        if (!empty($params['user_search'])) {
            $filters['user_name'] = $params['user_search'];
        }
        if (!empty($params['message_search'])) {
            $filters['message_like'] = $params['message_search'];
        }

        $page = $params['page'];
        $per_page = min(100, $params['per_page']);
        $offset = ($page - 1) * $per_page;

        $total_logs = $this->log_storage->count_logs($filters);
        $logs = $this->log_storage->get_raw_conversations_for_export($filters, $per_page, $offset);

        $response = new WP_REST_Response($logs);
        $response->header('X-WP-Total', $total_logs);
        $response->header('X-WP-TotalPages', ceil($total_logs / $per_page));

        return $response;
    }
}

/**
 * Authenticated REST handler that directly wakes AI Puffer automations.
 */
class AIPKit_REST_Automations_Handler
{
    /**
     * Checks the dedicated server-cron secret without using the public API key.
     *
     * @return true|WP_Error
     */
    public function check_permissions(WP_REST_Request $request)
    {
        self::ensure_automation_dependencies();
        if (!class_exists(AIPKit_Automation_Server_Cron::class)) {
            return new WP_Error(
                'aipkit_server_cron_unavailable',
                __('AI Puffer server cron is unavailable.', 'gpt3-ai-content-generator'),
                ['status' => 503]
            );
        }

        return AIPKit_Automation_Server_Cron::check_request($request);
    }

    /**
     * Runs due tasks and one existing queue-worker batch directly.
     */
    public function handle_request(WP_REST_Request $request)
    {
        unset($request);
        self::ensure_automation_dependencies();
        if (!class_exists(AIPKit_Automation_Runner::class)) {
            return new WP_Error(
                'aipkit_automation_runner_unavailable',
                __('The AI Puffer automation runner is unavailable.', 'gpt3-ai-content-generator'),
                ['status' => 503]
            );
        }

        $result = AIPKit_Automation_Runner::run_server_cron();
        AIPKit_Automation_Server_Cron::record_run($result);

        $response = new WP_REST_Response($result, 200);
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        return $response;
    }

    /**
     * Loads automation-only dependencies for a frontend REST request.
     */
    private static function ensure_automation_dependencies(): void
    {
        Content_Writer_Dependencies_Loader::load();
        Automated_Task_Dependencies_Loader::load();
        Automated_Task_Cron_Helpers_Loader::load();
    }
}

// The paid handler extends the shared base and retains its own plan check.
$aipkit_rest_embed_path = WPAICG_PLUGIN_DIR . 'lib/chatbot/embed-api.php';
if (is_file($aipkit_rest_embed_path)) {
    require_once $aipkit_rest_embed_path;
}

namespace WPAICG\REST;

use WP_REST_Controller;
use WPAICG\REST\Handlers\AIPKit_REST_Text_Handler;
use WPAICG\REST\Handlers\AIPKit_REST_Image_Handler;
use WPAICG\REST\Handlers\AIPKit_REST_Embeddings_Handler;
use WPAICG\REST\Handlers\AIPKit_REST_Chat_Handler;
use WPAICG\REST\Handlers\AIPKit_REST_Vector_Store_Handler;
use WPAICG\REST\Handlers\AIPKit_REST_Chatbot_Embed_Handler; // NEW: Embed handler
use WPAICG\REST\Handlers\AIPKit_REST_Logs_Handler; // NEW: Logs handler
use WPAICG\REST\Handlers\AIPKit_REST_Automations_Handler;


/**
 * REST API Controller for AIPKit Public Interactions.
 * Registers routes and delegates handling to specific handler classes.
 */
class AIPKit_REST_Controller extends WP_REST_Controller
{
    protected $namespace = 'aipkit/v1';
    protected $rest_base_generate = 'generate';
    protected $rest_base_images = 'images/generate';
    protected $rest_base_embeddings = 'embeddings';
    protected $rest_base_chat = 'chat';
    protected $rest_base_vectors = 'vector-stores';
    protected $rest_base_chatbot_embed = 'chatbots'; // NEW
    protected $rest_base_logs = 'logs'; // NEW
    protected $rest_base_automations = 'automations/run';

    private $text_handler;
    private $image_handler;
    private $embeddings_handler;
    private $chat_handler;
    private $vector_store_handler;
    private $chatbot_embed_handler; // NEW
    private $logs_handler; // NEW
    private $automations_handler;
    private $base_handler; // For permission check

    public function __construct()
    {
        $this->namespace = 'aipkit/v1';
        $this->rest_base_generate = 'generate';
        $this->rest_base_images = 'images/generate';
        $this->rest_base_embeddings = 'embeddings';
        $this->rest_base_chat = 'chat';
        $this->rest_base_vectors = 'vector-stores';
        $this->rest_base_chatbot_embed = 'chatbots'; // NEW
        $this->rest_base_logs = 'logs'; // NEW
        $this->rest_base_automations = 'automations/run';

        // Instantiate handlers
        $this->text_handler = new AIPKit_REST_Text_Handler();

        $this->image_handler = new AIPKit_REST_Image_Handler();

        $this->embeddings_handler = new AIPKit_REST_Embeddings_Handler();

        $this->chat_handler = new AIPKit_REST_Chat_Handler();

        $this->vector_store_handler = new AIPKit_REST_Vector_Store_Handler();

        if (class_exists(AIPKit_REST_Chatbot_Embed_Handler::class)) {
            $this->chatbot_embed_handler = new AIPKit_REST_Chatbot_Embed_Handler();
        }

        $this->logs_handler = new AIPKit_REST_Logs_Handler();

        $this->automations_handler = new AIPKit_REST_Automations_Handler();

        $this->base_handler = $this->text_handler;

    }

    private function is_chatbot_embed_feature_available(): bool
    {
        return class_exists('\WPAICG\aipkit_dashboard') && \WPAICG\aipkit_dashboard::is_pro_plan();
    }

    /**
     * Register text, image, embeddings, and chat generation routes.
     */
    public function register_routes()
    {
        if ($this->automations_handler) {
            register_rest_route(
                $this->namespace,
                '/' . $this->rest_base_automations,
                array(
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => array($this->automations_handler, 'handle_request'),
                    'permission_callback' => array($this->automations_handler, 'check_permissions'),
                )
            );
        }

        if ($this->chatbot_embed_handler && $this->is_chatbot_embed_feature_available()) {
            register_rest_route(
                $this->namespace,
                '/' . $this->rest_base_chatbot_embed . '/(?P<bot_id>\d+)/embed-config',
                array(
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => array($this->chatbot_embed_handler, 'handle_request'),
                    'permission_callback' => '__return_true',
                    'args'                => $this->chatbot_embed_handler->get_endpoint_args(),
                )
            );
        }

        if ($this->text_handler && $this->base_handler) {
            register_rest_route(
                $this->namespace,
                '/' . $this->rest_base_generate,
                array(
                    array(
                        'methods'             => \WP_REST_Server::CREATABLE,
                        'callback'            => array($this->text_handler, 'handle_request'),
                        'permission_callback' => array($this->base_handler, 'check_permissions'),
                        'args'                => $this->text_handler->get_endpoint_args(),
                    ),
                    'schema' => array($this->text_handler, 'get_item_schema'),
                )
            );
        }

        if ($this->image_handler && $this->base_handler) {
            register_rest_route(
                $this->namespace,
                '/' . $this->rest_base_images,
                array(
                    array(
                        'methods'             => \WP_REST_Server::CREATABLE,
                        'callback'            => array($this->image_handler, 'handle_request'),
                        'permission_callback' => array($this->base_handler, 'check_permissions'),
                        'args'                => $this->image_handler->get_endpoint_args(),
                    ),
                    'schema' => array($this->image_handler, 'get_item_schema'),
                )
            );
        }

        if ($this->embeddings_handler && $this->base_handler) {
            register_rest_route(
                $this->namespace,
                '/' . $this->rest_base_embeddings,
                array(
                    array(
                        'methods'             => \WP_REST_Server::CREATABLE,
                        'callback'            => array($this->embeddings_handler, 'handle_request'),
                        'permission_callback' => array($this->base_handler, 'check_permissions'),
                        'args'                => $this->embeddings_handler->get_endpoint_args(),
                    ),
                    'schema' => array($this->embeddings_handler, 'get_item_schema'),
                )
            );
        }

        if ($this->chat_handler && $this->base_handler) {
            register_rest_route(
                $this->namespace,
                '/' . $this->rest_base_chat . '/(?P<bot_id>\d+)/message',
                array(
                    array(
                        'methods'             => \WP_REST_Server::CREATABLE,
                        'callback'            => array($this->chat_handler, 'handle_request'),
                        'permission_callback' => array($this->base_handler, 'check_permissions'),
                        'args'                => $this->chat_handler->get_endpoint_args(),
                    ),
                    'schema' => array($this->chat_handler, 'get_item_schema'),
                )
            );
        }

        if ($this->vector_store_handler && $this->base_handler) {
            register_rest_route(
                $this->namespace,
                '/' . $this->rest_base_vectors . '/upsert',
                array(
                    array(
                        'methods'             => \WP_REST_Server::CREATABLE,
                        'callback'            => array($this->vector_store_handler, 'handle_request'),
                        'permission_callback' => array($this->base_handler, 'check_permissions'),
                        'args'                => $this->vector_store_handler->get_endpoint_args(),
                    ),
                    'schema' => array($this->vector_store_handler, 'get_item_schema'),
                )
            );
        }

        if ($this->logs_handler && $this->base_handler) {
            register_rest_route(
                $this->namespace,
                '/' . $this->rest_base_logs,
                array(
                    array(
                        'methods'             => \WP_REST_Server::READABLE,
                        'callback'            => array($this->logs_handler, 'handle_request'),
                        'permission_callback' => array($this->base_handler, 'check_permissions'),
                        'args'                => $this->logs_handler->get_endpoint_args(),
                    ),
                    'schema' => array($this->logs_handler, 'get_item_schema'),
                )
            );
        }
    }

}
