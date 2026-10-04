<?php

namespace WPAICG\Core\Providers\OpenAI;

use WPAICG\Core\AIPKit_OpenAI_Reasoning;
use WPAICG\Core\Providers\Traits\ChatCompletionsPayloadTrait;
use WPAICG\Core\Providers\Traits\ChatCompletionsResponseParserTrait;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/chat-completions.php';

/**
 * Normalizes the OpenAI-compatible chat API mode.
 *
 * Missing and unknown values deliberately fall back to Responses API so
 * existing installations keep their current request contract.
 */
final class OpenAIApiMode
{
    public const RESPONSES = 'responses';
    public const CHAT_COMPLETIONS = 'chat_completions';

    public static function normalize($value): string
    {
        return is_string($value) && $value === self::CHAT_COMPLETIONS
            ? self::CHAT_COMPLETIONS
            : self::RESPONSES;
    }

    public static function is_chat_completions($value): bool
    {
        return self::normalize($value) === self::CHAT_COMPLETIONS;
    }
}

/**
 * Adapts the shared Chat Completions contract for custom OpenAI-compatible
 * endpoints without changing the default OpenAI Responses implementation.
 */
final class OpenAIChatCompletionsAdapter
{
    use ChatCompletionsPayloadTrait;
    use ChatCompletionsResponseParserTrait;

    public function format_chat(
        string $instructions,
        array $history,
        string $user_message,
        array $ai_params,
        string $model
    ): array {
        $payload = $this->format_chat_completions_payload(
            $instructions,
            $history,
            $user_message,
            $ai_params,
            $model,
            true
        );

        return $this->apply_compatible_extensions($payload, $ai_params, $model);
    }

    public function format_sse(
        array $messages,
        string $instructions,
        array $ai_params,
        string $model
    ): array {
        $payload = $this->format_sse_chat_completions_payload(
            $messages,
            $instructions,
            $ai_params,
            $model,
            true,
            false
        );

        $payload = $this->apply_compatible_extensions($payload, $ai_params, $model);
        $payload['stream_options'] = ['include_usage' => true];

        return $payload;
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function parse_chat(array $decoded_response, array $request_data)
    {
        return $this->parse_chat_response($decoded_response, $request_data);
    }

    private function apply_compatible_extensions(array $payload, array $ai_params, string $model): array
    {
        if (isset($payload['max_tokens'])) {
            $payload['max_completion_tokens'] = $payload['max_tokens'];
            unset($payload['max_tokens']);
        }

        if (
            !empty($ai_params['reasoning']['effort'])
            && ($ai_params['reasoning']['effort'] !== 'none'
                || AIPKit_OpenAI_Reasoning::is_gpt_6_astra($model)
                || AIPKit_OpenAI_Reasoning::is_gpt_6_sol_or_luna($model))
        ) {
            $payload['reasoning_effort'] = sanitize_key((string) $ai_params['reasoning']['effort']);
        }

        if (!empty($ai_params['image_inputs']) && is_array($ai_params['image_inputs'])) {
            $payload = $this->attach_image_inputs($payload, $ai_params['image_inputs']);
        }

        return AIPKit_OpenAI_Reasoning::normalize_payload_for_model($payload, $model, true);
    }

    private function attach_image_inputs(array $payload, array $image_inputs): array
    {
        if (empty($payload['messages']) || !is_array($payload['messages'])) {
            return $payload;
        }

        $last_message_key = array_key_last($payload['messages']);
        if (
            $last_message_key === null
            || ($payload['messages'][$last_message_key]['role'] ?? '') !== 'user'
        ) {
            return $payload;
        }

        $existing_content = $payload['messages'][$last_message_key]['content'] ?? '';
        $content_parts = [];
        if (is_string($existing_content) && $existing_content !== '') {
            $content_parts[] = ['type' => 'text', 'text' => $existing_content];
        }

        foreach ($image_inputs as $image_input) {
            if (!is_array($image_input) || empty($image_input['type']) || empty($image_input['base64'])) {
                continue;
            }

            $image_url = [
                'url' => 'data:' . $image_input['type'] . ';base64,' . $image_input['base64'],
            ];
            if (!empty($image_input['detail'])) {
                $image_url['detail'] = sanitize_key((string) $image_input['detail']);
            }
            $content_parts[] = [
                'type' => 'image_url',
                'image_url' => $image_url,
            ];
        }

        if (!empty($content_parts)) {
            $payload['messages'][$last_message_key]['content'] = $content_parts;
        }

        return $payload;
    }
}

class OpenAIUrlBuilder {

    const MODERATION_ENDPOINT = '/moderations';
    const SPEECH_ENDPOINT = '/audio/speech';
    const TRANSCRIPTION_ENDPOINT = '/audio/transcriptions';
    const IMAGES_ENDPOINT = '/images/generations';
    const FILES_ENDPOINT = '/files';
    const EMBEDDINGS_ENDPOINT = '/embeddings';
    const VECTOR_STORES_ENDPOINT = '/vector_stores';

    /**
     * @return string|\WP_Error
     */
    public static function build(string $operation, array $params) {
        return Methods\build_logic_for_url_builder($operation, $params);
    }
}

class OpenAIPayloadFormatter {

    public static function format_chat(
        string $instructions,
        array $history,
        array $ai_params,
        string $model,
        bool $use_openai_conversation_state = false,
        ?string $previous_response_id = null
    ): array {
        return Methods\format_chat_logic_for_payload_formatter($instructions, $history, $ai_params, $model, $use_openai_conversation_state, $previous_response_id);
    }

    public static function format_sse(
        array $messages,
        $system_instruction,
        array $ai_params,
        string $model,
        bool $use_openai_conversation_state = false,
        ?string $previous_response_id = null
    ): array {
        return Methods\format_sse_logic_for_payload_formatter($messages, $system_instruction, $ai_params, $model, $use_openai_conversation_state, $previous_response_id);
    }

    public static function format_moderation(string $text): array {
        return Methods\format_moderation_logic_for_payload_formatter($text);
    }

    public static function format_embeddings($input, array $options): array {
        return Methods\format_embeddings_logic_for_payload_formatter($input, $options);
    }
}

class OpenAIResponseParser {

    /**
     * @return mixed[]|\WP_Error
     */
    public static function parse_chat(array $decoded_response) {
        return Methods\parse_chat_logic_for_response_parser($decoded_response);
    }

    public static function parse_error($response_body, int $status_code): string {
        return Methods\parse_error_logic_for_response_parser($response_body, $status_code);
    }

    public static function parse_sse_chunk(string $sse_chunk, string &$current_buffer): array {
        return Methods\parse_sse_chunk_logic_for_response_parser($sse_chunk, $current_buffer);
    }

    public static function parse_moderation(array $decoded_response): bool {
        return Methods\parse_moderation_logic_for_response_parser($decoded_response);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public static function parse_embeddings(array $decoded_response) {
        return Methods\parse_embeddings_logic_for_response_parser($decoded_response);
    }
}

class OpenAIStatefulConversationHelper {

    public static function prepare_parameters_and_history(
        array $ai_params,
        array $history,
        array $bot_settings,
        ?string $frontend_previous_openai_response_id
    ): array {
        return Methods\prepare_parameters_and_history_logic($ai_params, $history, $bot_settings, $frontend_previous_openai_response_id);
    }
}

namespace WPAICG\Core\Providers\OpenAI\Methods;

use WPAICG\Core\Providers\OpenAIProviderStrategy;
use WPAICG\Core\Providers\OpenAI\OpenAIUrlBuilder;
use WP_Error;
use WPAICG\Core\Providers\OpenAI\OpenAIPayloadFormatter;
use WPAICG\Core\AIPKit_OpenAI_Reasoning;
use WPAICG\Core\Providers\OpenAI\OpenAIResponseParser;
use WPAICG\Core\Providers\OpenAI\OpenAIApiMode;
use WPAICG\Core\Providers\OpenAI\OpenAIChatCompletionsAdapter;
use function WPAICG\Core\Providers\Shared\extract_sse_event_blocks;
use function WPAICG\Core\Providers\Shared\decode_event_type_sse_event_block;
use WPAICG\AIPKit_Providers; // Required for get_provider_data

/**
 * Logic for the build_api_url method of OpenAIProviderStrategy.
 *
 * @param OpenAIProviderStrategy $strategyInstance The instance of the strategy class.
 * @param string $operation ('responses', 'models', 'moderation', 'audio/speech', 'audio/transcriptions', 'images/generations', 'files', 'embeddings', etc.)
 * @param array  $params Required parameters (api_key, base_url, api_version, model, deployment, etc.)
 * @return string|WP_Error The full URL or WP_Error.
 */
function build_api_url_logic(OpenAIProviderStrategy $strategyInstance, string $operation, array $params) {
    $url_operation_key = $operation;
    if ($operation === 'chat' || $operation === 'stream') {
        $url_operation_key = OpenAIApiMode::is_chat_completions($params['api_mode'] ?? null)
            ? 'chat_completions'
            : 'responses';
    }
    return OpenAIUrlBuilder::build($url_operation_key, $params);
}

/**
 * Logic for the build_sse_payload method of OpenAIProviderStrategy.
 *
 * @param OpenAIProviderStrategy $strategyInstance The instance of the strategy class.
 * @param array $messages Formatted messages/input/contents array.
 * @param string|array|null $system_instruction Formatted system instruction.
 * @param array $ai_params AI parameters.
 * @param string $model Target model/deployment.
 * @return array The formatted request body data for SSE.
 */
function build_sse_payload_logic(
    OpenAIProviderStrategy $strategyInstance,
    array $messages,
    $system_instruction,
    array $ai_params,
    string $model
): array {
    if (OpenAIApiMode::is_chat_completions($ai_params['api_mode'] ?? null)) {
        $adapter = new OpenAIChatCompletionsAdapter();
        $instructions = is_string($system_instruction) ? $system_instruction : '';
        return $adapter->format_sse($messages, $instructions, $ai_params, $model);
    }

    $use_openai_conversation_state = $ai_params['use_openai_conversation_state'] ?? false;
    $previous_response_id = $ai_params['previous_response_id'] ?? null;
    // Web search config is already part of $ai_params if set by SSERequestHandler
    return OpenAIPayloadFormatter::format_sse($messages, $system_instruction, $ai_params, $model, $use_openai_conversation_state, $previous_response_id);
}

/**
 * Logic for the build static method of OpenAIUrlBuilder.
 * @return string|\WP_Error
 */
function build_logic_for_url_builder(string $operation, array $params) {
    // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Provider-specific API transport.
    $base_url = !empty($params['base_url']) ? rtrim($params['base_url'], '/') : 'https://api.openai.com';
    $api_version = !empty($params['api_version']) ? $params['api_version'] : 'v1';

    if (empty($base_url)) return new WP_Error("missing_base_url_OpenAI_logic", __('OpenAI Base URL is required.', 'gpt3-ai-content-generator'));
    if (empty($api_version)) return new WP_Error("missing_api_version_OpenAI_logic", __('OpenAI API Version is required.', 'gpt3-ai-content-generator'));

    $paths = [
        'responses'           => '/responses',
        'chat_completions'    => '/chat/completions',
        'models'              => '/models',
        'moderation'          => OpenAIUrlBuilder::MODERATION_ENDPOINT,
        'audio/speech'        => OpenAIUrlBuilder::SPEECH_ENDPOINT,
        'audio/transcriptions'=> OpenAIUrlBuilder::TRANSCRIPTION_ENDPOINT,
        'images/generations'  => OpenAIUrlBuilder::IMAGES_ENDPOINT,
        'files'               => OpenAIUrlBuilder::FILES_ENDPOINT,
        'files_id'            => OpenAIUrlBuilder::FILES_ENDPOINT . '/{file_id}', // Added for specific file deletion
        'embeddings'          => OpenAIUrlBuilder::EMBEDDINGS_ENDPOINT,
        'vector_stores'       => OpenAIUrlBuilder::VECTOR_STORES_ENDPOINT,
        'vector_stores_id'    => OpenAIUrlBuilder::VECTOR_STORES_ENDPOINT . '/{vector_store_id}',
        'vector_stores_id_search' => OpenAIUrlBuilder::VECTOR_STORES_ENDPOINT . '/{vector_store_id}/search',
        'vector_stores_id_file_batches' => OpenAIUrlBuilder::VECTOR_STORES_ENDPOINT . '/{vector_store_id}/file_batches',
        'vector_stores_id_file_batches_id' => OpenAIUrlBuilder::VECTOR_STORES_ENDPOINT . '/{vector_store_id}/file_batches/{batch_id}',
        'vector_stores_id_files_id' => OpenAIUrlBuilder::VECTOR_STORES_ENDPOINT . '/{vector_store_id}/files/{file_id}',
        'vector_stores_id_files'    => OpenAIUrlBuilder::VECTOR_STORES_ENDPOINT . '/{vector_store_id}/files',
    ];

    $path_template = $paths[$operation] ?? null;

    if ($path_template === null) {
        // translators: %s is the operation name (e.g. 'models', 'files')
        return new WP_Error('unsupported_operation_OpenAI_logic', sprintf(__('Operation "%s" not supported for OpenAI URL Builder.', 'gpt3-ai-content-generator'), esc_html($operation)));
    }

    $path = $path_template;
    if (strpos($path, '{vector_store_id}') !== false) {
        if (empty($params['vector_store_id'])) return new WP_Error('missing_vector_store_id_logic', __('Vector Store ID is required for this operation.', 'gpt3-ai-content-generator'));
        $path = str_replace('{vector_store_id}', urlencode($params['vector_store_id']), $path);
    }
    if (strpos($path, '{batch_id}') !== false) {
        if (empty($params['batch_id'])) return new WP_Error('missing_batch_id_logic', __('Batch ID is required for this operation.', 'gpt3-ai-content-generator'));
        $path = str_replace('{batch_id}', urlencode($params['batch_id']), $path);
    }
    if (strpos($path, '{file_id}') !== false) {
         if (empty($params['file_id'])) return new WP_Error('missing_file_id_logic', __('File ID is required for this operation.', 'gpt3-ai-content-generator'));
         $path = str_replace('{file_id}', urlencode($params['file_id']), $path);
    }

    $version_segment = '/' . trim($api_version, '/');
    $url = '';
    if (strpos($base_url, $version_segment) !== false) {
        $url = $base_url . $path;
    } else {
        $url = $base_url . $version_segment . $path;
    }

    $query_args = [];
    if ($operation === 'vector_stores' || $operation === 'vector_stores_id_files') { // vector_stores_id_files also supports pagination
        if (isset($params['limit']) && is_numeric($params['limit'])) $query_args['limit'] = intval($params['limit']);
        if (isset($params['order']) && in_array($params['order'], ['asc', 'desc'])) $query_args['order'] = $params['order'];
        if (isset($params['after']) && !empty($params['after'])) $query_args['after'] = sanitize_text_field($params['after']);
        if (isset($params['before']) && !empty($params['before'])) $query_args['before'] = sanitize_text_field($params['before']);
    }
    if ($operation === 'vector_stores_id_files' && isset($params['filter']) && in_array($params['filter'], ['in_progress', 'completed', 'failed', 'cancelled'])) {
        $query_args['filter'] = sanitize_key($params['filter']);
    }


    if (!empty($query_args)) {
        $url = add_query_arg($query_args, $url);
    }
    return $url;
}

/**
 * Extract citations from a completed OpenAI Responses payload.
 *
 * @param array<string, mixed> $decoded_response
 * @return array<int, array<string, mixed>>
 */
function extract_openai_citations_from_response_logic_for_response_parser(array $decoded_response): array {
    $output = $decoded_response['output'] ?? null;
    if (!is_array($output)) {
        return [];
    }

    $annotation_citations = extract_openai_annotation_citations_from_output_logic_for_response_parser($output);
    if (!empty($annotation_citations)) {
        return dedupe_openai_citations_logic_for_response_parser($annotation_citations);
    }

    return dedupe_openai_citations_logic_for_response_parser(
        extract_openai_source_citations_from_output_logic_for_response_parser($output)
    );
}

/**
 * Extract citations from a streamed OpenAI event payload.
 *
 * @param array<string, mixed> $payload
 * @return array<int, array<string, mixed>>
 */
function extract_openai_citations_from_event_payload_logic_for_response_parser(array $payload, bool $allow_source_fallback = false): array {
    $annotation_citations = [];

    if (isset($payload['part']) && is_array($payload['part'])) {
        $annotation_citations = array_merge(
            $annotation_citations,
            extract_openai_citations_from_output_text_block_logic_for_response_parser($payload['part'])
        );
    }

    if (isset($payload['item']) && is_array($payload['item'])) {
        $annotation_citations = array_merge(
            $annotation_citations,
            extract_openai_annotation_citations_from_output_item_logic_for_response_parser($payload['item'])
        );
    }

    if (isset($payload['output']) && is_array($payload['output'])) {
        $annotation_citations = array_merge(
            $annotation_citations,
            extract_openai_annotation_citations_from_output_logic_for_response_parser($payload['output'])
        );
    }

    if (isset($payload['response']) && is_array($payload['response'])) {
        $response = $payload['response'];

        if (isset($response['output']) && is_array($response['output'])) {
            $annotation_citations = array_merge(
                $annotation_citations,
                extract_openai_annotation_citations_from_output_logic_for_response_parser($response['output'])
            );
        }

        if (isset($response['annotations']) && is_array($response['annotations'])) {
            $annotation_citations = array_merge(
                $annotation_citations,
                extract_openai_citations_from_annotations_logic_for_response_parser(
                    $response['annotations'],
                    isset($response['text']) && is_string($response['text']) ? $response['text'] : ''
                )
            );
        }
    }

    if (!empty($annotation_citations)) {
        return dedupe_openai_citations_logic_for_response_parser($annotation_citations);
    }

    if (!$allow_source_fallback) {
        return [];
    }

    $source_citations = [];

    if (isset($payload['item']) && is_array($payload['item'])) {
        $source_citations = array_merge(
            $source_citations,
            extract_openai_source_citations_from_output_item_logic_for_response_parser($payload['item'])
        );
    }

    if (isset($payload['output']) && is_array($payload['output'])) {
        $source_citations = array_merge(
            $source_citations,
            extract_openai_source_citations_from_output_logic_for_response_parser($payload['output'])
        );
    }

    if (isset($payload['response']['output']) && is_array($payload['response']['output'])) {
        $source_citations = array_merge(
            $source_citations,
            extract_openai_source_citations_from_output_logic_for_response_parser($payload['response']['output'])
        );
    }

    return dedupe_openai_citations_logic_for_response_parser($source_citations);
}

/**
 * Extract annotation-based citations from a list of output items.
 *
 * @param array<int, mixed> $output
 * @return array<int, array<string, mixed>>
 */
function extract_openai_annotation_citations_from_output_logic_for_response_parser(array $output): array {
    $citations = [];

    foreach ($output as $output_item) {
        if (!is_array($output_item)) {
            continue;
        }

        $citations = array_merge(
            $citations,
            extract_openai_annotation_citations_from_output_item_logic_for_response_parser($output_item)
        );
    }

    return $citations;
}

/**
 * Extract annotation-based citations from one OpenAI output item.
 *
 * @param array<string, mixed> $output_item
 * @return array<int, array<string, mixed>>
 */
function extract_openai_annotation_citations_from_output_item_logic_for_response_parser(array $output_item): array {
    $citations = [];
    $item_type = isset($output_item['type']) && is_string($output_item['type']) ? $output_item['type'] : '';

    if ($item_type === 'message' && !empty($output_item['content']) && is_array($output_item['content'])) {
        foreach ($output_item['content'] as $content_part) {
            if (!is_array($content_part)) {
                continue;
            }

            $citations = array_merge(
                $citations,
                extract_openai_citations_from_output_text_block_logic_for_response_parser($content_part)
            );
        }
    }

    return $citations;
}

/**
 * Extract fallback source citations from a list of output items.
 *
 * @param array<int, mixed> $output
 * @return array<int, array<string, mixed>>
 */
function extract_openai_source_citations_from_output_logic_for_response_parser(array $output): array {
    $citations = [];

    foreach ($output as $output_item) {
        if (!is_array($output_item)) {
            continue;
        }

        $citations = array_merge(
            $citations,
            extract_openai_source_citations_from_output_item_logic_for_response_parser($output_item)
        );
    }

    return $citations;
}

/**
 * Extract fallback source citations from a single OpenAI output item.
 *
 * @param array<string, mixed> $output_item
 * @return array<int, array<string, mixed>>
 */
function extract_openai_source_citations_from_output_item_logic_for_response_parser(array $output_item): array {
    $item_type = isset($output_item['type']) && is_string($output_item['type']) ? $output_item['type'] : '';

    if ($item_type !== 'web_search_call') {
        return [];
    }

    return extract_openai_citations_from_web_search_call_logic_for_response_parser($output_item);
}

/**
 * Extract citations from one output_text content block.
 *
 * @param array<string, mixed> $content_part
 * @return array<int, array<string, mixed>>
 */
function extract_openai_citations_from_output_text_block_logic_for_response_parser(array $content_part): array {
    if (($content_part['type'] ?? '') !== 'output_text' || empty($content_part['annotations']) || !is_array($content_part['annotations'])) {
        return [];
    }

    $full_text = isset($content_part['text']) && is_string($content_part['text']) ? $content_part['text'] : '';

    return extract_openai_citations_from_annotations_logic_for_response_parser($content_part['annotations'], $full_text);
}

/**
 * Extract citations from an annotations list.
 *
 * @param array<int, mixed> $annotations
 * @param string $full_text
 * @return array<int, array<string, mixed>>
 */
function extract_openai_citations_from_annotations_logic_for_response_parser(array $annotations, string $full_text = ''): array {
    $citations = [];

    foreach ($annotations as $annotation) {
        if (!is_array($annotation)) {
            continue;
        }

        $normalized = normalize_openai_citation_logic_for_response_parser($annotation, $full_text);
        if ($normalized !== null) {
            $citations[] = $normalized;
        }
    }

    return $citations;
}

/**
 * Normalize one OpenAI annotation/source into a stable frontend-safe citation structure.
 *
 * @param array<string, mixed> $citation
 * @param string $full_text
 * @return array<string, mixed>|null
 */
function normalize_openai_citation_logic_for_response_parser(array $citation, string $full_text = ''): ?array {
    $type = isset($citation['type']) && is_string($citation['type']) ? trim($citation['type']) : '';
    $normalized = [];

    if ($type !== '') {
        $normalized['type'] = $type === 'url_citation' ? 'char_location' : $type;
        if (in_array($type, ['file_citation', 'file_path'], true)) {
            $normalized['source_type'] = 'knowledge_base';
        }
    }

    if (isset($citation['url']) && is_string($citation['url']) && trim($citation['url']) !== '') {
        $normalized['url'] = trim($citation['url']);
    } elseif (isset($citation['uri']) && is_string($citation['uri']) && trim($citation['uri']) !== '') {
        $normalized['url'] = trim($citation['uri']);
    }

    if (isset($citation['title']) && is_string($citation['title']) && trim($citation['title']) !== '') {
        $normalized['title'] = trim($citation['title']);
        $normalized['source_title'] = trim($citation['title']);
    }

    if (isset($citation['filename']) && is_string($citation['filename']) && trim($citation['filename']) !== '') {
        $normalized['document_title'] = trim($citation['filename']);
        $normalized['source_type'] = 'knowledge_base';
        if (!isset($normalized['title'])) {
            $normalized['title'] = trim($citation['filename']);
        }
    }

    $start_index = null;
    if (isset($citation['start_index']) && is_numeric($citation['start_index'])) {
        $start_index = (int) $citation['start_index'];
    } elseif (isset($citation['start_char_index']) && is_numeric($citation['start_char_index'])) {
        $start_index = (int) $citation['start_char_index'];
    }

    $end_index = null;
    if (isset($citation['end_index']) && is_numeric($citation['end_index'])) {
        $end_index = (int) $citation['end_index'];
    } elseif (isset($citation['end_char_index']) && is_numeric($citation['end_char_index'])) {
        $end_index = (int) $citation['end_char_index'];
    }

    if ($start_index !== null) {
        $normalized['start_char_index'] = $start_index;
    }
    if ($end_index !== null) {
        $normalized['end_char_index'] = $end_index;
    }

    if (isset($citation['index']) && is_numeric($citation['index'])) {
        $normalized['document_index'] = (int) $citation['index'];
    }

    $cited_text = '';
    foreach (['text', 'quote', 'snippet', 'excerpt'] as $field) {
        if (isset($citation[$field]) && is_string($citation[$field]) && trim($citation[$field]) !== '') {
            $cited_text = trim($citation[$field]);
            break;
        }
    }
    if ($cited_text === '' && $full_text !== '' && $start_index !== null && $end_index !== null && $end_index > $start_index) {
        $cited_text = extract_openai_text_slice_logic_for_response_parser($full_text, $start_index, $end_index);
    }
    if ($cited_text !== '') {
        $normalized['cited_text'] = $cited_text;
    }

    $has_reference = isset($normalized['url'])
        || isset($normalized['title'])
        || isset($normalized['document_title'])
        || isset($normalized['cited_text'])
        || isset($normalized['document_index']);

    return $has_reference ? $normalized : null;
}

/**
 * Extract web search sources from a completed web_search_call item when include data is available.
 *
 * @param array<string, mixed> $web_search_call
 * @return array<int, array<string, mixed>>
 */
function extract_openai_citations_from_web_search_call_logic_for_response_parser(array $web_search_call): array {
    $action = $web_search_call['action'] ?? null;
    if (!is_array($action) || empty($action['sources']) || !is_array($action['sources'])) {
        return [];
    }

    $citations = [];
    foreach ($action['sources'] as $source) {
        if (!is_array($source)) {
            continue;
        }

        $normalized = normalize_openai_source_logic_for_response_parser($source);
        if ($normalized !== null) {
            $citations[] = $normalized;
        }
    }

    return $citations;
}

/**
 * Normalize a web search source include entry.
 *
 * @param array<string, mixed> $source
 * @return array<string, mixed>|null
 */
function normalize_openai_source_logic_for_response_parser(array $source): ?array {
    $source_payload = isset($source['source']) && is_array($source['source']) ? $source['source'] : $source;

    $url = null;
    foreach (['url', 'uri', 'link'] as $field) {
        if (isset($source_payload[$field]) && is_string($source_payload[$field]) && trim($source_payload[$field]) !== '') {
            $url = trim($source_payload[$field]);
            break;
        }
    }

    $title = null;
    foreach (['title', 'source_title', 'website_title', 'name'] as $field) {
        if (isset($source_payload[$field]) && is_string($source_payload[$field]) && trim($source_payload[$field]) !== '') {
            $title = trim($source_payload[$field]);
            break;
        }
    }

    $snippet = null;
    foreach (['snippet', 'excerpt', 'text', 'summary'] as $field) {
        if (isset($source_payload[$field]) && is_string($source_payload[$field]) && trim($source_payload[$field]) !== '') {
            $snippet = trim($source_payload[$field]);
            break;
        }
    }

    if ($url === null && $title === null && $snippet === null) {
        return null;
    }

    $normalized = ['type' => 'url_citation'];
    if ($url !== null) {
        $normalized['url'] = $url;
    }
    if ($title !== null) {
        $normalized['title'] = $title;
        $normalized['source_title'] = $title;
    }
    if ($snippet !== null) {
        $normalized['cited_text'] = $snippet;
    }

    return $normalized;
}

/**
 * Return a best-effort UTF-8-safe text slice from a response body.
 *
 * @param string $text
 * @param int $start_index
 * @param int $end_index
 * @return string
 */
function extract_openai_text_slice_logic_for_response_parser(string $text, int $start_index, int $end_index): string {
    if ($end_index <= $start_index) {
        return '';
    }

    $length = $end_index - $start_index;

    if (function_exists('mb_substr')) {
        return trim((string) mb_substr($text, $start_index, $length, 'UTF-8'));
    }

    return trim((string) substr($text, $start_index, $length));
}

/**
 * Deduplicate citations while preserving order.
 *
 * @param array<int, array<string, mixed>> $citations
 * @return array<int, array<string, mixed>>
 */
function dedupe_openai_citations_logic_for_response_parser(array $citations): array {
    $deduped = [];
    $seen = [];

    foreach ($citations as $citation) {
        if (!is_array($citation) || empty($citation)) {
            continue;
        }

        $reference_key = build_openai_citation_reference_key_logic_for_response_parser($citation);
        if ($reference_key !== '') {
            if (isset($seen[$reference_key])) {
                $existing_index = $seen[$reference_key];
                $existing_citation = $deduped[$existing_index];

                if (score_openai_citation_logic_for_response_parser($citation) > score_openai_citation_logic_for_response_parser($existing_citation)) {
                    $deduped[$existing_index] = merge_openai_citation_entries_logic_for_response_parser($existing_citation, $citation);
                } else {
                    $deduped[$existing_index] = merge_openai_citation_entries_logic_for_response_parser($citation, $existing_citation);
                }
                continue;
            }

            $seen[$reference_key] = count($deduped);
            $deduped[] = $citation;
            continue;
        }

        $encoded = wp_json_encode($citation);
        if (!is_string($encoded) || isset($seen[$encoded])) {
            continue;
        }
        $seen[$encoded] = true;
        $deduped[] = $citation;
    }

    return $deduped;
}

/**
 * Build a loose reference key so multiple annotations for the same source collapse into one source row.
 *
 * @param array<string, mixed> $citation
 * @return string
 */
function build_openai_citation_reference_key_logic_for_response_parser(array $citation): string {
    $parts = [
        isset($citation['url']) && is_string($citation['url']) ? trim($citation['url']) : '',
        isset($citation['document_title']) && is_string($citation['document_title']) ? trim($citation['document_title']) : '',
        isset($citation['title']) && is_string($citation['title']) ? trim($citation['title']) : '',
        isset($citation['source_title']) && is_string($citation['source_title']) ? trim($citation['source_title']) : '',
    ];

    $parts = array_values(array_filter($parts, static function ($value) {
        return $value !== '';
    }));

    return empty($parts) ? '' : implode('|', $parts);
}

/**
 * Prefer citations with excerpts/locations over bare source-only rows.
 *
 * @param array<string, mixed> $citation
 * @return int
 */
function score_openai_citation_logic_for_response_parser(array $citation): int {
    $score = 0;

    if (!empty($citation['url'])) {
        $score += 2;
    }
    if (!empty($citation['title']) || !empty($citation['document_title']) || !empty($citation['source_title'])) {
        $score += 2;
    }
    if (!empty($citation['cited_text'])) {
        $score += 4;
    }
    if (isset($citation['start_char_index']) || isset($citation['end_char_index'])) {
        $score += 2;
    }
    if (isset($citation['document_index'])) {
        $score += 1;
    }

    return $score;
}

/**
 * Merge two source rows without dropping richer metadata already captured.
 *
 * @param array<string, mixed> $base
 * @param array<string, mixed> $preferred
 * @return array<string, mixed>
 */
function merge_openai_citation_entries_logic_for_response_parser(array $base, array $preferred): array {
    return array_merge($base, $preferred);
}

/**
 * Logic for the format_chat_payload method of OpenAIProviderStrategy.
 *
 * @param OpenAIProviderStrategy $strategyInstance The instance of the strategy class.
 * @param string $user_message The user's message.
 * @param string $instructions System instructions.
 * @param array  $history Conversation history.
 * @param array  $ai_params AI parameters (temperature, max_tokens, etc.).
 * @param string $model The target model/deployment ID.
 * @return array The formatted request body data.
 */
function format_chat_payload_logic(
    OpenAIProviderStrategy $strategyInstance,
    string $user_message,
    string $instructions,
    array $history,
    array $ai_params,
    string $model
): array {
    if (OpenAIApiMode::is_chat_completions($ai_params['api_mode'] ?? null)) {
        $adapter = new OpenAIChatCompletionsAdapter();
        return $adapter->format_chat($instructions, $history, $user_message, $ai_params, $model);
    }

    $use_openai_conversation_state = $ai_params['use_openai_conversation_state'] ?? false;
    $previous_response_id = $ai_params['previous_response_id'] ?? null;
    // Web search config is already part of $ai_params if set by AIService/SSERequestHandler
    return OpenAIPayloadFormatter::format_chat($instructions, $history, $ai_params, $model, $use_openai_conversation_state, $previous_response_id);
}

/**
 * Applies the shared Responses storage, search-tool and generation options.
 * Message construction and stream-only fields stay with their formatters.
 */
function apply_responses_options_logic_for_payload_formatter(
    array $body_data,
    array $ai_params,
    bool $use_openai_conversation_state,
    ?string $previous_response_id
): array {
    if ($use_openai_conversation_state) {
        $body_data['store'] = true;
    } else {
        $store_conversation_globally = isset($ai_params['store_conversation']) && $ai_params['store_conversation'] === '1';
        $body_data['store'] = $store_conversation_globally;
    }

    if ($use_openai_conversation_state && $previous_response_id !== null) {
        $body_data['previous_response_id'] = $previous_response_id;
    }

    $tools = [];
    if (isset($ai_params['vector_store_tool_config']) &&
        is_array($ai_params['vector_store_tool_config']) &&
        $ai_params['vector_store_tool_config']['type'] === 'file_search' &&
        isset($ai_params['vector_store_tool_config']['vector_store_ids']) &&
        is_array($ai_params['vector_store_tool_config']['vector_store_ids']) &&
        !empty($ai_params['vector_store_tool_config']['vector_store_ids'])
    ) {
        $file_search_tool = [
            'type' => 'file_search',
            'vector_store_ids' => $ai_params['vector_store_tool_config']['vector_store_ids'],
            'max_num_results' => $ai_params['vector_store_tool_config']['max_num_results'] ?? 3
        ];

        // Add ranking_options only when filtering is requested. OpenAI's hosted
        // file_search can return empty results if score_threshold is sent without
        // an explicit ranker, while omitting ranking_options preserves defaults.
        if (isset($ai_params['vector_store_tool_config']['ranking_options']) &&
            is_array($ai_params['vector_store_tool_config']['ranking_options'])) {
            $ranking_opts = $ai_params['vector_store_tool_config']['ranking_options'];
            if (isset($ranking_opts['score_threshold'])) {
                $st = floatval($ranking_opts['score_threshold']);
                if ($st <= 0) {
                    unset($ranking_opts['score_threshold']);
                } else {
                    $ranking_opts['score_threshold'] = ($st >= 1) ? 1.0 : round($st, 6);
                    if (empty($ranking_opts['ranker']) || !is_string($ranking_opts['ranker'])) {
                        $ranking_opts['ranker'] = 'auto';
                    }
                }
            }
            if (!empty($ranking_opts)) {
                $file_search_tool['ranking_options'] = $ranking_opts;
            }
        }

        $tools[] = $file_search_tool;
    }
    $bot_allows_web_search = isset($ai_params['web_search_tool_config']['enabled']) && $ai_params['web_search_tool_config']['enabled'] === true;
    $frontend_requests_web_search = isset($ai_params['frontend_web_search_active']) && $ai_params['frontend_web_search_active'] === true;

    if ($bot_allows_web_search && $frontend_requests_web_search) {
        $web_search_tool = ['type' => 'web_search_preview'];
        if (isset($ai_params['web_search_tool_config']['search_context_size']) && !empty($ai_params['web_search_tool_config']['search_context_size'])) {
            $web_search_tool['search_context_size'] = $ai_params['web_search_tool_config']['search_context_size'];
        }
        if (isset($ai_params['web_search_tool_config']['user_location']) && is_array($ai_params['web_search_tool_config']['user_location']) && !empty(array_filter($ai_params['web_search_tool_config']['user_location']))) {
            $web_search_tool['user_location'] = array_filter($ai_params['web_search_tool_config']['user_location']);
            if (!isset($web_search_tool['user_location']['type'])) {
                $web_search_tool['user_location']['type'] = 'approximate';
            }
        }
        $tools[] = $web_search_tool;
        $body_data['include'] = ['web_search_call.action.sources'];
    }

    if (!empty($tools)) {
        $body_data['tools'] = $tools;
    }

    if (isset($ai_params['temperature'])) {
        $body_data['temperature'] = floatval($ai_params['temperature']);
    }
    if (isset($ai_params['max_completion_tokens'])) {
        $body_data['max_output_tokens'] = absint($ai_params['max_completion_tokens']);
    }
    if (isset($ai_params['top_p'])) {
        $body_data['top_p'] = floatval($ai_params['top_p']);
    }
    return $body_data;
}

/**
 * Logic for the format_chat static method of OpenAIPayloadFormatter.
 */
function format_chat_logic_for_payload_formatter(
    string $instructions,
    array $history,
    array $ai_params,
    string $model,
    bool $use_openai_conversation_state = false,
    ?string $previous_response_id = null
): array {
    $input_array = [];

    if ($use_openai_conversation_state && $previous_response_id !== null) {
        $last_message = end($history);
        if ($last_message && $last_message['role'] === 'user') {
            $input_array[] = ['role' => 'user', 'content' => trim($last_message['content'])];
        } else {
            if (empty($instructions)) {
                $input_array[] = ['role' => 'system', 'content' => 'Continue the conversation.'];
            }
        }
        if (!empty($instructions)) {
            if (empty($input_array) || $input_array[0]['role'] !== 'system') {
                array_unshift($input_array, ['role' => 'system', 'content' => $instructions]);
            }
        }
    } else {
        if (!empty($instructions)) {
            $input_array[] = ['role' => 'system', 'content' => $instructions];
        }
        foreach ($history as $msg) {
            $role = ($msg['role'] === 'bot') ? 'assistant' : $msg['role'];
            $content = isset($msg['content']) ? trim($msg['content']) : '';
            if ($content !== '' && in_array($role, ['system', 'user', 'assistant'], true)) {
                if ($role === 'system' && !empty($instructions)) {
                    continue;
                }
                $input_array[] = ['role' => $role, 'content' => $content];
            }
        }
    }

    if (!empty($ai_params['image_inputs']) && is_array($ai_params['image_inputs'])) {
        $last_message_key = array_key_last($input_array);
        if ($last_message_key !== null && isset($input_array[$last_message_key]['role']) && $input_array[$last_message_key]['role'] === 'user') {
            $user_text_content = '';
            if (is_string($input_array[$last_message_key]['content'])) {
                $user_text_content = $input_array[$last_message_key]['content'];
            } elseif (is_array($input_array[$last_message_key]['content'])) {
                foreach ($input_array[$last_message_key]['content'] as $part) {
                    if (isset($part['type']) && ($part['type'] === 'text' || $part['type'] === 'input_text') && isset($part['text'])) {
                        $user_text_content = $part['text'];
                        break;
                    }
                }
            }
            $new_content_parts = [];
            if (!empty($user_text_content) || empty($ai_params['image_inputs'])) {
                $new_content_parts[] = ['type' => 'input_text', 'text' => $user_text_content];
            }
            foreach ($ai_params['image_inputs'] as $image_input) {
                if (isset($image_input['base64']) && isset($image_input['type'])) {
                    $image_payload = [
                        'type' => 'input_image',
                        'image_url' => 'data:' . $image_input['type'] . ';base64,' . $image_input['base64']
                    ];
                    if (!empty($image_input['detail'])) {
                        $image_payload['detail'] = $image_input['detail'];
                    }
                    $new_content_parts[] = $image_payload;
                }
            }
            if (!empty($new_content_parts)) {
                $input_array[$last_message_key]['content'] = $new_content_parts;
            } elseif (empty($user_text_content) && !empty($ai_params['image_inputs'])) {
                if (empty($input_array[$last_message_key]['content']) && !empty($ai_params['image_inputs'])) {
                    $input_array[$last_message_key]['content'] = [['type' => 'input_text', 'text' => '']];
                    foreach ($ai_params['image_inputs'] as $image_input) {
                        if (isset($image_input['base64']) && isset($image_input['type'])) {
                            $image_payload = [
                                'type' => 'input_image',
                                'image_url' => 'data:' . $image_input['type'] . ';base64,' . $image_input['base64']
                            ];
                            if (!empty($image_input['detail'])) {
                                $image_payload['detail'] = $image_input['detail'];
                            }
                            $input_array[$last_message_key]['content'][] = $image_payload;
                        }
                    }
                }
            }
        }
    }

    $body_data = [
        'model' => $model,
        'input' => $input_array,
    ];

    $body_data = apply_responses_options_logic_for_payload_formatter($body_data, $ai_params, $use_openai_conversation_state, $previous_response_id);

    if (isset($ai_params['reasoning']) && is_array($ai_params['reasoning'])) {
        $body_data['reasoning'] = $ai_params['reasoning'];
    }
    return AIPKit_OpenAI_Reasoning::normalize_payload_for_model($body_data, $model);
}

/**
 * Logic for the format_embeddings static method of OpenAIPayloadFormatter.
 */
function format_embeddings_logic_for_payload_formatter($input, array $options): array {
    $payload = [
        'input' => $input,
        'model' => $options['model'] ?? AIPKit_Providers::get_default_model_id('OpenAIEmbedding'),
    ];

    if (isset($options['dimensions']) && is_int($options['dimensions']) && $options['dimensions'] > 0) {
        $payload['dimensions'] = $options['dimensions'];
    }
    if (isset($options['encoding_format']) && in_array($options['encoding_format'], ['float', 'base64'])) {
        $payload['encoding_format'] = $options['encoding_format'];
    }
    if (isset($options['user']) && is_string($options['user'])) {
        $payload['user'] = $options['user'];
    }

    return $payload;
}

/**
 * Logic for the format_moderation static method of OpenAIPayloadFormatter.
 */
function format_moderation_logic_for_payload_formatter(string $text): array {
    return ['input' => $text];
}

/**
 * Logic for the format_sse static method of OpenAIPayloadFormatter.
 */
function format_sse_logic_for_payload_formatter(
    array $messages,
    $system_instruction,
    array $ai_params,
    string $model,
    bool $use_openai_conversation_state = false,
    ?string $previous_response_id = null
): array {
    $input_array = [];
    $instructions_text = is_string($system_instruction) ? $system_instruction : '';

    if ($use_openai_conversation_state && $previous_response_id !== null) {
        $last_message = end($messages);
        if ($last_message && $last_message['role'] === 'user') {
            $input_array[] = ['role' => 'user', 'content' => trim($last_message['content'])];
        } else {
            if (empty($instructions_text)) {
                $input_array[] = ['role' => 'system', 'content' => 'Continue the conversation.'];
            }
        }
        if (!empty($instructions_text)) {
            if (empty($input_array) || $input_array[0]['role'] !== 'system') {
                array_unshift($input_array, ['role' => 'system', 'content' => $instructions_text]);
            }
        }
    } else {
        if (!empty($instructions_text)) {
            $input_array[] = ['role' => 'system', 'content' => $instructions_text];
        }
        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = isset($msg['content']) ? trim($msg['content']) : '';
            $api_role = ($role === 'bot') ? 'assistant' : $role;
            if ($content !== '' && in_array($api_role, ['system', 'assistant', 'user'], true)) {
                if ($api_role === 'system' && !empty($instructions_text)) {
                    continue;
                }
                $input_array[] = ['role' => $api_role, 'content' => $content];
            }
        }
    }

    if (!empty($ai_params['image_inputs']) && is_array($ai_params['image_inputs'])) {
        $last_message_key = array_key_last($input_array);
        if ($last_message_key !== null && isset($input_array[$last_message_key]['role']) && $input_array[$last_message_key]['role'] === 'user') {
            $user_text_content = '';
            if (is_string($input_array[$last_message_key]['content'])) {
                $user_text_content = $input_array[$last_message_key]['content'];
            } elseif (is_array($input_array[$last_message_key]['content'])) {
                foreach ($input_array[$last_message_key]['content'] as $part) {
                    if (isset($part['type']) && ($part['type'] === 'text' || $part['type'] === 'input_text') && isset($part['text'])) {
                        $user_text_content = $part['text'];
                        break;
                    }
                }
            }
            $new_content_parts = [];
            if (!empty($user_text_content) || empty($ai_params['image_inputs'])) {
                $new_content_parts[] = ['type' => 'input_text', 'text' => $user_text_content];
            }
            foreach ($ai_params['image_inputs'] as $image_input) {
                if (isset($image_input['base64']) && isset($image_input['type'])) {
                    $new_content_parts[] = [
                        'type' => 'input_image',
                        'image_url' => 'data:' . $image_input['type'] . ';base64,' . $image_input['base64']
                    ];
                }
            }
            if (!empty($new_content_parts)) {
                $input_array[$last_message_key]['content'] = $new_content_parts;
            } elseif (empty($user_text_content) && !empty($ai_params['image_inputs'])) {
                if (empty($input_array[$last_message_key]['content']) && !empty($ai_params['image_inputs'])) {
                    $input_array[$last_message_key]['content'] = [['type' => 'input_text', 'text' => '']];
                    foreach ($ai_params['image_inputs'] as $image_input) {
                        if (isset($image_input['base64']) && isset($image_input['type'])) {
                            $input_array[$last_message_key]['content'][] = [
                                'type' => 'input_image',
                                'image_url' => 'data:' . $image_input['type'] . ';base64,' . $image_input['base64']
                            ];
                        }
                    }
                }
            }
        }
    }

    $body_data = [
        'model'    => $model,
        'input'    => $input_array,
        'stream'   => true,
    ];

    $body_data = apply_responses_options_logic_for_payload_formatter($body_data, $ai_params, $use_openai_conversation_state, $previous_response_id);

    if (!empty($system_instruction) && is_array($system_instruction)) { // System instruction can be an object for Responses API
        $body_data['instructions'] = $system_instruction;
    }

    if (isset($ai_params['reasoning']) && is_array($ai_params['reasoning'])) {
        $body_data['reasoning'] = $ai_params['reasoning'];
    }
    return AIPKit_OpenAI_Reasoning::normalize_payload_for_model($body_data, $model);
}

/**
 * Logic for the generate_embeddings method of OpenAIProviderStrategy.
 *
 * @param OpenAIProviderStrategy $strategyInstance The instance of the strategy class.
 * @param string|array $input The input text or array of texts.
 * @param array $api_params Provider-specific API connection parameters.
 * @param array $options Embedding options (model, dimensions, encoding_format, user).
 * @return array|WP_Error An array of embedding vectors or WP_Error on failure.
 */
function generate_embeddings_logic(
    OpenAIProviderStrategy $strategyInstance,
    $input,
    array $api_params,
    array $options = []
) {
    // URL Builder requires base_url and api_version to be passed from $api_params
    $url_builder_params = [
        // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Provider-specific API transport.
        'base_url' => $api_params['base_url'] ?? 'https://api.openai.com',
        'api_version' => $api_params['api_version'] ?? 'v1',
    ];
    $url = OpenAIUrlBuilder::build('embeddings', $url_builder_params);
    if (is_wp_error($url)) {
        return $url;
    }

    $headers = $strategyInstance->get_api_headers($api_params['api_key'], 'embeddings');
    $request_options = $strategyInstance->get_request_options('embeddings');
    $payload = OpenAIPayloadFormatter::format_embeddings($input, $options);
    $request_body_json = wp_json_encode($payload);

    $response = wp_remote_post($url, array_merge($request_options, ['headers' => $headers, 'body' => $request_body_json]));

    if (is_wp_error($response)) {
        return new WP_Error('openai_embedding_http_error_logic', __('HTTP error during embedding generation.', 'gpt3-ai-content-generator'));
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $decoded_response = $strategyInstance->decode_json_public($body, 'OpenAI Embeddings'); // Call public wrapper

    if ($status_code !== 200 || is_wp_error($decoded_response)) {
        $error_msg = is_wp_error($decoded_response)
                    ? $decoded_response->get_error_message()
                    : OpenAIResponseParser::parse_error($body, $status_code);
        $error_data = $strategyInstance->build_http_error_data_with_retry_after($response, $status_code);
        /* translators: %1$d: HTTP status code, %2$s: Error message from the API. */
        return new WP_Error('openai_embedding_api_error_logic', sprintf(__('OpenAI Embeddings API Error (%1$d): %2$s', 'gpt3-ai-content-generator'), $status_code, esc_html($error_msg)), $error_data);
    }
    return OpenAIResponseParser::parse_embeddings($decoded_response);
}

/**
 * Logic for the get_api_headers method of OpenAIProviderStrategy.
 *
 * @param OpenAIProviderStrategy $strategyInstance The instance of the strategy class.
 * @param string $api_key The API key for the provider.
 * @param string $operation The specific operation being performed.
 * @return array Key-value array of headers.
 */
function get_api_headers_logic(OpenAIProviderStrategy $strategyInstance, string $api_key, string $operation): array {
    $headers = [
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer ' . $api_key,
    ];
    if ($operation === 'stream') {
        $headers['Accept'] = 'text/event-stream';
        $headers['Cache-Control'] = 'no-cache';
    }
    // OpenAI-Beta header for specific features like vector stores, assistants
    if (strpos($operation, 'vector_stores') === 0 || strpos($operation, 'assistants') === 0 || $operation === 'files') {
        $headers['OpenAI-Beta'] = 'assistants=v2';
    }

    return $headers;
}

/**
 * Logic for the get_models method of OpenAIProviderStrategy.
 *
 * @param OpenAIProviderStrategy $strategyInstance The instance of the strategy class.
 * @param array $api_params Connection parameters (api_key, base_url, etc.).
 * @return array|WP_Error Formatted list [['id' => ..., 'name' => ...]] or WP_Error.
 */
function get_models_logic(OpenAIProviderStrategy $strategyInstance, array $api_params) {
    // URL Builder requires base_url and api_version to be passed from $api_params
    $url_builder_params = [
        // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Provider-specific API transport.
        'base_url' => $api_params['base_url'] ?? 'https://api.openai.com',
        'api_version' => $api_params['api_version'] ?? 'v1',
    ];
    $url = OpenAIUrlBuilder::build('models', $url_builder_params);
    if (is_wp_error($url)) {
        return $url;
    }

    $headers = $strategyInstance->get_api_headers($api_params['api_key'], 'models'); // Call instance method
    $options = $strategyInstance->get_request_options('models'); // Call instance method
    $options['method'] = 'GET'; // Override method for GET request

    $response = wp_remote_get($url, array_merge($options, ['headers' => $headers]));
    if (is_wp_error($response)) {
        return $response;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($status_code !== 200) {
        $error_msg = $strategyInstance->parse_error_response($body, $status_code); // Call instance method
        return new WP_Error('api_error_openai_models_logic', sprintf('OpenAI API Error (HTTP %d): %s', $status_code, esc_html($error_msg)));
    }

    $decoded = $strategyInstance->decode_json_public($body, 'OpenAI Models'); // Call public wrapper on instance
    if (is_wp_error($decoded)) {
        return $decoded;
    }

    $raw_models = $decoded['data'] ?? [];

    // Return the flat, formatted list. Grouping will be handled by the AJAX handler.
    return $strategyInstance->format_model_list($raw_models);
}

/**
 * Maps a normalized upstream OpenAI SSE event into an internal typed event.
 *
 * @param array<string, mixed> $decoded_event
 * @return array<string, mixed>
 */
function map_sse_event_logic_for_response_parser(array $decoded_event): array {
    $event_type = isset($decoded_event['event']) && is_string($decoded_event['event']) ? $decoded_event['event'] : 'message';
    $payload = isset($decoded_event['payload']) && is_array($decoded_event['payload']) ? $decoded_event['payload'] : [];

    if ($event_type === '[DONE]') {
        return [
            'kind' => 'done',
            'event' => $event_type,
        ];
    }

    if (in_array($event_type, ['ping', 'keepalive'], true)) {
        return [
            'kind' => 'skip',
            'event' => $event_type,
        ];
    }

    if ($event_type === 'error' || isset($payload['error'])) {
        return [
            'kind' => 'error',
            'event' => $event_type,
            'message' => parse_error_logic_for_response_parser($payload, 500),
        ];
    }

    if (
        $event_type === 'message'
        && (isset($payload['choices']) || isset($payload['usage']))
    ) {
        return map_chat_completions_sse_event_logic_for_response_parser($payload);
    }

    $annotation_citations = extract_openai_citations_from_event_payload_logic_for_response_parser($payload, false);

    switch ($event_type) {
        case 'response.created':
        case 'response.in_progress':
        case 'response.queued':
        case 'response.web_search_call.in_progress':
        case 'response.web_search_call.searching':
        case 'response.web_search_call.completed':
        case 'response.file_search_call.in_progress':
        case 'response.file_search_call.searching':
        case 'response.file_search_call.completed':
        case 'response.image_generation_call.in_progress':
        case 'response.image_generation_call.generating':
        case 'response.image_generation_call.completed':
            return [
                'kind' => 'status',
                'event' => $event_type,
                'status' => build_sse_status_logic_for_response_parser($event_type, $payload),
            ];

        case 'response.output_text.delta':
            $delta_text = isset($payload['delta']) ? (string) $payload['delta'] : '';
            if ($delta_text === '') {
                return [
                    'kind' => 'skip',
                    'event' => $event_type,
                ];
            }

            return [
                'kind' => 'delta',
                'event' => $event_type,
                'text' => $delta_text,
            ];

        case 'response.refusal.delta':
            $refusal_text = isset($payload['delta']) ? (string) $payload['delta'] : '';
            if ($refusal_text === '') {
                return [
                    'kind' => 'skip',
                    'event' => $event_type,
                ];
            }

            return [
                'kind' => 'warning',
                'event' => $event_type,
                'text' => sprintf(' (%s: %s)', __('Refusal', 'gpt3-ai-content-generator'), $refusal_text),
            ];

        case 'response.content_part.done':
        case 'response.output_item.done':
            if (!empty($annotation_citations)) {
                return [
                    'kind' => 'citations',
                    'event' => $event_type,
                    'citations' => $annotation_citations,
                ];
            }

            return [
                'kind' => 'skip',
                'event' => $event_type,
            ];

        case 'response.completed':
        case 'response.incomplete':
            $completion_citations = extract_openai_citations_from_event_payload_logic_for_response_parser($payload, true);
            $warning_text = null;
            if ($event_type === 'response.incomplete') {
                $reason = $payload['response']['incomplete_details']['reason'] ?? 'unknown';
                $warning_text = sprintf(' (%s: %s)', __('Incomplete', 'gpt3-ai-content-generator'), $reason);
            }

            return [
                'kind' => 'completion',
                'event' => $event_type,
                'usage' => extract_sse_usage_logic_for_response_parser($payload),
                'response_id' => $payload['response']['id'] ?? null,
                'warning_text' => $warning_text,
                'citations' => $completion_citations,
            ];

        case 'response.failed':
            $error_message = $payload['response']['error']['message'] ?? __('Response failed', 'gpt3-ai-content-generator');

            return [
                'kind' => 'error',
                'event' => $event_type,
                'message' => sprintf(' (%s: %s)', __('Error', 'gpt3-ai-content-generator'), $error_message),
            ];

        default:
            return [
                'kind' => 'skip',
                'event' => $event_type,
            ];
    }
}

/**
 * Maps a data-only Chat Completions event into the same internal stream shape.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function map_chat_completions_sse_event_logic_for_response_parser(array $payload): array {
    $choice = isset($payload['choices'][0]) && is_array($payload['choices'][0])
        ? $payload['choices'][0]
        : [];
    $delta = isset($choice['delta']) && is_array($choice['delta'])
        ? $choice['delta']
        : [];
    $text = isset($delta['content']) && $delta['content'] !== null
        ? (string) $delta['content']
        : '';
    $finish_reason = isset($choice['finish_reason']) && is_string($choice['finish_reason'])
        ? $choice['finish_reason']
        : '';
    $warning_text = $finish_reason === 'content_filter'
        ? sprintf(' (%s)', __('Warning: Content Filtered', 'gpt3-ai-content-generator'))
        : '';
    $usage = null;

    if (isset($payload['usage']) && is_array($payload['usage'])) {
        $usage = [
            'input_tokens' => $payload['usage']['prompt_tokens'] ?? 0,
            'output_tokens' => $payload['usage']['completion_tokens'] ?? 0,
            'total_tokens' => $payload['usage']['total_tokens'] ?? 0,
            'provider_raw' => $payload['usage'],
        ];
    }

    return [
        'kind' => 'chat_completion',
        'event' => 'message',
        'text' => $text,
        'usage' => $usage,
        'warning_text' => $warning_text,
    ];
}

/**
 * Builds the status payload that gets forwarded to the public stream formatter.
 *
 * @param string $type
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function build_sse_status_logic_for_response_parser(string $type, array $payload): array {
    $status = ['type' => $type];

    if (isset($payload['response']['status'])) {
        $status['status'] = $payload['response']['status'];
    }
    if (isset($payload['response']['id'])) {
        $status['response_id'] = $payload['response']['id'];
    }
    if (isset($payload['item_id'])) {
        $status['item_id'] = $payload['item_id'];
    }
    if (isset($payload['output_index'])) {
        $status['output_index'] = $payload['output_index'];
    }

    return $status;
}

/**
 * Extracts usage information from a completion event payload using the existing public parse shape.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>|null
 */
function extract_sse_usage_logic_for_response_parser(array $payload): ?array {
    if (!isset($payload['response']['usage']) || !is_array($payload['response']['usage'])) {
        return null;
    }

    $usage = $payload['response']['usage'];

    return [
        'input_tokens' => $usage['input_tokens'] ?? 0,
        'output_tokens' => $usage['output_tokens'] ?? 0,
        'total_tokens' => $usage['total_tokens'] ?? 0,
        'provider_raw' => $usage,
    ];
}

/**
 * Logic for the moderate_text method of OpenAIProviderStrategy.
 *
 * @param OpenAIProviderStrategy $strategyInstance The instance of the strategy class.
 * @param string $text The input text to moderate.
 * @param array $api_params API connection parameters.
 * @return bool|WP_Error True if flagged, false if not, WP_Error on API error.
 */
function moderate_text_logic(OpenAIProviderStrategy $strategyInstance, string $text, array $api_params)
{
    // URL Builder requires base_url and api_version to be passed from $api_params
    $url_builder_params = [
        // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Provider-specific API transport.
        'base_url' => $api_params['base_url'] ?? 'https://api.openai.com',
        'api_version' => $api_params['api_version'] ?? 'v1',
    ];
    $url = OpenAIUrlBuilder::build('moderation', $url_builder_params);
    if (is_wp_error($url)) {
        return $url;
    }

    $headers = $strategyInstance->get_api_headers($api_params['api_key'], 'moderation');
    $options = $strategyInstance->get_request_options('moderation');
    $payload_data = OpenAIPayloadFormatter::format_moderation($text);
    $payload_json = wp_json_encode($payload_data);

    $response = wp_remote_post($url, array_merge($options, ['headers' => $headers, 'body' => $payload_json]));

    if (is_wp_error($response)) {
        return new WP_Error('moderation_http_request_failed_logic', __('Moderation check failed (HTTP).', 'gpt3-ai-content-generator'));
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);

    if ($status_code >= 400) {
        $error_message = OpenAIResponseParser::parse_error($response_body, $status_code);
        /* translators: %1$d: HTTP status code, %2$s: Error message from the API. */
        return new WP_Error('moderation_api_error_logic', sprintf(__('Moderation check failed (API %1$d): %2$s', 'gpt3-ai-content-generator'), $status_code, esc_html($error_message)));
    }

    $decoded = $strategyInstance->decode_json_public($response_body, 'OpenAI Moderation'); // Call public wrapper
    if (is_wp_error($decoded)) {
        return $decoded;
    }

    return OpenAIResponseParser::parse_moderation($decoded);
}

/**
 * Logic for the parse_chat_response method of OpenAIProviderStrategy.
 *
 * @param OpenAIProviderStrategy $strategyInstance The instance of the strategy class.
 * @param array $decoded_response The decoded JSON response body.
 * @param array $request_data The original request data sent.
 * @return array|WP_Error ['content' => string, 'usage' => array|null] or WP_Error.
 */
function parse_chat_response_logic(
    OpenAIProviderStrategy $strategyInstance,
    array $decoded_response,
    array $request_data
) {
    if (isset($decoded_response['choices']) && is_array($decoded_response['choices'])) {
        $adapter = new OpenAIChatCompletionsAdapter();
        return $adapter->parse_chat($decoded_response, $request_data);
    }

    $parsed = OpenAIResponseParser::parse_chat($decoded_response);
    // Add OpenAI specific 'id' to the parsed response if available
    if (!is_wp_error($parsed) && isset($decoded_response['id'])) {
        $parsed['openai_response_id'] = $decoded_response['id'];
    }
    return $parsed;
}

/**
 * Logic for the parse_chat static method of OpenAIResponseParser.
 * @return mixed[]|\WP_Error
 */
function parse_chat_logic_for_response_parser(array $decoded_response) {
    $content = null;
    $usage = null;
    $citations = extract_openai_citations_from_response_logic_for_response_parser($decoded_response);

    if (isset($decoded_response['output']) && is_array($decoded_response['output'])) {
        foreach ($decoded_response['output'] as $output_item) {
            if (isset($output_item['type']) && $output_item['type'] === 'message' &&
                !empty($output_item['content'][0]['type']) && $output_item['content'][0]['type'] === 'output_text' &&
                isset($output_item['content'][0]['text'])) {
                $content = trim($output_item['content'][0]['text']);
                break;
            }
        }
    }

    if (isset($decoded_response['status']) && $decoded_response['status'] === 'failed' && isset($decoded_response['error']['message'])) {
         return new WP_Error($decoded_response['error']['code'] ?? 'openai_failed_response_logic', $decoded_response['error']['message']);
    }
    if (isset($decoded_response['status']) && $decoded_response['status'] === 'incomplete' && isset($decoded_response['incomplete_details']['reason'])) {
         $reason = $decoded_response['incomplete_details']['reason'];
         if ($content !== null) {
             $content .= sprintf(' (%s: %s)', __('Incomplete', 'gpt3-ai-content-generator'), $reason);
         } else {
            /* translators: %s: The reason why the response was incomplete. */
            return new WP_Error('openai_incomplete_response_logic', sprintf(__('Response incomplete due to: %s', 'gpt3-ai-content-generator'), $reason));
         }
    }

    if (isset($decoded_response['usage']) && is_array($decoded_response['usage'])) {
        $usage = [
            'input_tokens'  => $decoded_response['usage']['input_tokens'] ?? 0,
            'output_tokens' => $decoded_response['usage']['output_tokens'] ?? 0,
            'total_tokens'  => $decoded_response['usage']['total_tokens'] ?? 0,
            'provider_raw' => $decoded_response['usage'],
        ];
    }

    if ($content === null) {
         return new WP_Error('invalid_response_structure_openai_logic', __('Unexpected response structure from OpenAI Responses API.', 'gpt3-ai-content-generator'));
    }

    // openai_response_id will be added by the caller (OpenAIProviderStrategy::parse_chat_response)
    $return_data = ['content' => $content, 'usage' => $usage];
    if (!empty($citations)) {
        $return_data['citations'] = $citations;
    }

    return $return_data;
}

/**
 * Logic for the parse_embeddings static method of OpenAIResponseParser.
 * @return mixed[]|\WP_Error
 */
function parse_embeddings_logic_for_response_parser(array $decoded_response) {
    $embeddings = [];
    if (isset($decoded_response['data']) && is_array($decoded_response['data'])) {
        foreach ($decoded_response['data'] as $item) {
            if (isset($item['embedding']) && is_array($item['embedding'])) {
                $embeddings[] = $item['embedding'];
            }
        }
    }

    if (empty($embeddings)) {
        return new WP_Error('openai_embedding_no_data_logic', __('No embedding data found in OpenAI response.', 'gpt3-ai-content-generator'));
    }

    $usage = null;
    if (isset($decoded_response['usage']) && is_array($decoded_response['usage'])) {
        $usage = [
            'input_tokens'  => $decoded_response['usage']['prompt_tokens'] ?? 0,
            'total_tokens'  => $decoded_response['usage']['total_tokens'] ?? 0,
            'provider_raw' => $decoded_response['usage'],
        ];
    }

    return ['embeddings' => $embeddings, 'usage' => $usage];
}

/**
 * Logic for the parse_error_response method of OpenAIProviderStrategy.
 *
 * @param OpenAIProviderStrategy $strategyInstance The instance of the strategy class.
 * @param mixed $response_body The raw or decoded error response body.
 * @param int $status_code The HTTP status code.
 * @return string A user-friendly error message.
 */
function parse_error_response_logic(
    OpenAIProviderStrategy $strategyInstance,
    $response_body,
    int $status_code
): string {
    return OpenAIResponseParser::parse_error($response_body, $status_code);
}

/**
 * Logic for the parse_error static method of OpenAIResponseParser.
 */
function parse_error_logic_for_response_parser($response_body, int $status_code): string {
    $message = __('An unknown API error occurred.', 'gpt3-ai-content-generator');
    $decoded = null;
    if (is_string($response_body)) {
        $decoded = json_decode($response_body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return trim(substr($response_body, 0, 200));
        }
    } elseif (is_array($response_body)) {
        $decoded = $response_body;
    }

    if (is_array($decoded)) {
        $error_code = '';
        if (!empty($decoded['error']['message'])) {
            $message = (string) $decoded['error']['message'];
            $error_code = (string) ($decoded['error']['code'] ?? '');
        } elseif (!empty($decoded['messages'][0]['message'])) {
            $message = (string) $decoded['messages'][0]['message'];
            $error_code = (string) ($decoded['messages'][0]['errorCode'] ?? '');
        } elseif (!empty($decoded['message'])) {
            $message = (string) $decoded['message'];
            $error_code = (string) ($decoded['errorCode'] ?? '');
        }
        if ($error_code !== '') {
            $message .= ' (Code: ' . $error_code . ')';
        }
    }
    return trim($message);
}

/**
 * Logic for the parse_moderation static method of OpenAIResponseParser.
 */
function parse_moderation_logic_for_response_parser(array $decoded_response): bool {
    return isset($decoded_response['results'][0]['flagged']) && $decoded_response['results'][0]['flagged'] === true;
}

/**
 * Logic for the parse_sse_chunk method of OpenAIProviderStrategy.
 *
 * @param OpenAIProviderStrategy $strategyInstance The instance of the strategy class.
 * @param string $sse_chunk The raw chunk received from the stream.
 * @param string &$current_buffer The reference to the incomplete buffer for this provider.
 * @return array Result containing delta, usage, flags, and potentially openai_response_id.
 */
function parse_sse_chunk_logic(
    OpenAIProviderStrategy $strategyInstance,
    string $sse_chunk,
    string &$current_buffer
): array {
    $parsed = OpenAIResponseParser::parse_sse_chunk($sse_chunk, $current_buffer);
    // Check if the parsed result contains the 'openai_response_id' (added by OpenAIResponseParser::parse_sse_chunk)
    // and propagate it if present. This key is specific to OpenAI's Responses API.
    // Note: $parsed['raw_completion_event_data'] is no longer directly exposed by this method.
    // OpenAIResponseParser::parse_sse_chunk directly adds 'openai_response_id' to $parsed if found.
    return $parsed;
}


/**
 * Logic for the parse_sse_chunk static method of OpenAIResponseParser.
 */
function parse_sse_chunk_logic_for_response_parser(string $sse_chunk, string &$current_buffer): array {
    $current_buffer .= $sse_chunk;
    $result = [
        'delta' => null,
        'usage' => null,
        'is_error' => false,
        'is_warning' => false,
        'is_done' => false,
        'openai_response_id' => null,
        'status' => null,
        'citations' => null,
    ];

    foreach (extract_sse_event_blocks($current_buffer) as $event_block) {
        $decoded_event = decode_event_type_sse_event_block($event_block);
        if ($decoded_event === null) {
            continue;
        }

        $mapped_event = map_sse_event_logic_for_response_parser($decoded_event);
        $should_stop = reduce_sse_event_logic_for_response_parser($mapped_event, $result);
        if ($should_stop) {
            return $result;
        }
    }

    return $result;
}

/**
 * Logic for the prepare_parameters_and_history static method of OpenAIStatefulConversationHelper.
 */
function prepare_parameters_and_history_logic(
    array $ai_params,
    array $history,
    array $bot_settings,
    ?string $frontend_previous_openai_response_id
): array {
    if (!class_exists(\WPAICG\AIPKit_Providers::class)) {
        $providers_path = WPAICG_PLUGIN_DIR . 'classes/ai/settings.php';
        if (file_exists($providers_path)) {
            require_once $providers_path;
        } else {
            return ['ai_params' => $ai_params, 'history' => $history];
        }
    }

    $provDataOpenAI = AIPKit_Providers::get_provider_data('OpenAI');
    $api_mode = OpenAIApiMode::normalize($provDataOpenAI['api_mode'] ?? null);
    $ai_params['api_mode'] = $api_mode;

    if (OpenAIApiMode::is_chat_completions($api_mode)) {
        $ai_params['store_conversation'] = '0';
        $ai_params['use_openai_conversation_state'] = false;
        unset($ai_params['previous_response_id']);
        return ['ai_params' => $ai_params, 'history' => $history];
    }

    $ai_params['store_conversation'] = $provDataOpenAI['store_conversation'] ?? '0';
    $ai_params['use_openai_conversation_state'] = ($bot_settings['openai_conversation_state_enabled'] ?? '0') === '1';

    if ($ai_params['use_openai_conversation_state']) {
        $actual_previous_response_id_to_use = null;
        if (!empty($frontend_previous_openai_response_id)) {
            $actual_previous_response_id_to_use = $frontend_previous_openai_response_id;
        } elseif (!empty($history)) {
            $last_bot_msg_with_id = null;
            for ($i = count($history) - 1; $i >= 0; $i--) {
                if (($history[$i]['role'] === 'bot' || $history[$i]['role'] === 'assistant') && !empty($history[$i]['openai_response_id'])) {
                    $last_bot_msg_with_id = $history[$i]['openai_response_id'];
                    break;
                }
            }
            if ($last_bot_msg_with_id) {
                $actual_previous_response_id_to_use = $last_bot_msg_with_id;
            }
        }

        if ($actual_previous_response_id_to_use) {
            $ai_params['previous_response_id'] = $actual_previous_response_id_to_use;
            $latest_user_message_obj = end($history);
            if ($latest_user_message_obj && ($latest_user_message_obj['role'] === 'user' || $latest_user_message_obj['role'] === 'customer')) {
                $history = [$latest_user_message_obj];
            }
        }
    }

    return ['ai_params' => $ai_params, 'history' => $history];
}

/**
 * Applies an internal typed event to the flattened parse result expected by the stream processor.
 *
 * @param array<string, mixed> $mapped_event
 * @param array<string, mixed> $result
 * @return bool True when parsing should stop immediately.
 */
function reduce_sse_event_logic_for_response_parser(array $mapped_event, array &$result): bool {
    $kind = isset($mapped_event['kind']) && is_string($mapped_event['kind']) ? $mapped_event['kind'] : 'skip';

    if (isset($mapped_event['citations']) && is_array($mapped_event['citations']) && !empty($mapped_event['citations'])) {
        $existing_citations = isset($result['citations']) && is_array($result['citations'])
            ? $result['citations']
            : [];
        $result['citations'] = merge_openai_sse_citations_logic_for_response_parser($existing_citations, $mapped_event['citations']);
    }

    switch ($kind) {
        case 'status':
            if (isset($mapped_event['status']) && is_array($mapped_event['status'])) {
                $result['status'] = $mapped_event['status'];
            }
            return false;

        case 'citations':
            return false;

        case 'delta':
            $text = isset($mapped_event['text']) ? (string) $mapped_event['text'] : '';
            if ($text !== '') {
                if ($result['delta'] === null) {
                    $result['delta'] = '';
                }
                $result['delta'] .= $text;
            }
            return false;

        case 'chat_completion':
            $text = isset($mapped_event['text']) ? (string) $mapped_event['text'] : '';
            if ($text !== '') {
                if ($result['delta'] === null) {
                    $result['delta'] = '';
                }
                $result['delta'] .= $text;
            }
            if (isset($mapped_event['usage']) && is_array($mapped_event['usage'])) {
                $result['usage'] = $mapped_event['usage'];
            }
            if (!empty($mapped_event['warning_text'])) {
                if ($result['delta'] === null) {
                    $result['delta'] = '';
                }
                $result['delta'] .= (string) $mapped_event['warning_text'];
                $result['is_warning'] = true;
            }
            return false;

        case 'warning':
            $text = isset($mapped_event['text']) ? (string) $mapped_event['text'] : '';
            if ($text !== '') {
                if ($result['delta'] === null) {
                    $result['delta'] = '';
                }
                $result['delta'] .= $text;
                $result['is_warning'] = true;
            }
            return false;

        case 'completion':
            $result['is_done'] = true;
            if (isset($mapped_event['usage']) && is_array($mapped_event['usage'])) {
                $result['usage'] = $mapped_event['usage'];
            }
            if (!empty($mapped_event['response_id']) && is_string($mapped_event['response_id'])) {
                $result['openai_response_id'] = $mapped_event['response_id'];
            }
            if (!empty($mapped_event['warning_text']) && is_string($mapped_event['warning_text'])) {
                if ($result['delta'] === null) {
                    $result['delta'] = '';
                }
                $result['delta'] .= $mapped_event['warning_text'];
                $result['is_warning'] = true;
            }
            return false;

        case 'error':
            $message = isset($mapped_event['message']) ? (string) $mapped_event['message'] : '';
            $result['delta'] = $message;
            $result['is_error'] = true;
            return true;

        case 'done':
            $result['is_done'] = true;
            return false;

        default:
            return false;
    }
}

/**
 * Merge citations while preserving order and removing duplicates.
 *
 * @param array<int, array<string, mixed>> $existing
 * @param array<int, array<string, mixed>> $incoming
 * @return array<int, array<string, mixed>>
 */
function merge_openai_sse_citations_logic_for_response_parser(array $existing, array $incoming): array {
    return dedupe_openai_citations_logic_for_response_parser(array_merge($existing, $incoming));
}

namespace WPAICG\Core\Providers;

if (!class_exists(BaseProviderStrategy::class)) {
    $aipkit_base_strategy_path = __DIR__ . '/contracts.php';
    if (file_exists($aipkit_base_strategy_path)) {
        require_once $aipkit_base_strategy_path;
    } else {
        return;
    }
}

class OpenAIProviderStrategy extends BaseProviderStrategy {

    /**
     * @return string|\WP_Error
     */
    public function build_api_url(string $operation, array $params) {
        return OpenAI\Methods\build_api_url_logic($this, $operation, $params);
    }

    public function get_api_headers(string $api_key, string $operation): array {
        return OpenAI\Methods\get_api_headers_logic($this, $api_key, $operation);
    }

    public function format_chat_payload(string $user_message, string $instructions, array $history, array $ai_params, string $model): array {
        return OpenAI\Methods\format_chat_payload_logic($this, $user_message, $instructions, $history, $ai_params, $model);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function parse_chat_response(array $decoded_response, array $request_data) {
        return OpenAI\Methods\parse_chat_response_logic($this, $decoded_response, $request_data);
    }

    public function parse_error_response($response_body, int $status_code): string {
        return OpenAI\Methods\parse_error_response_logic($this, $response_body, $status_code);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function get_models(array $api_params) {
        return OpenAI\Methods\get_models_logic($this, $api_params);
    }

    public function build_sse_payload(array $messages, $system_instruction, array $ai_params, string $model): array {
        return OpenAI\Methods\build_sse_payload_logic($this, $messages, $system_instruction, $ai_params, $model);
    }

    public function parse_sse_chunk(string $sse_chunk, string &$current_buffer): array {
        return OpenAI\Methods\parse_sse_chunk_logic($this, $sse_chunk, $current_buffer);
    }

    /**
     * @return bool|\WP_Error
     */
    public function moderate_text(string $text, array $api_params) {
        return OpenAI\Methods\moderate_text_logic($this, $text, $api_params);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function generate_embeddings($input, array $api_params, array $options = []) {
        return OpenAI\Methods\generate_embeddings_logic($this, $input, $api_params, $options);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function decode_json_public(string $json_string, string $context) {
        return $this->decode_json($json_string, $context);
    }
}
