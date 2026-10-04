<?php

namespace WPAICG\Core;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * AIPKit_Payload_Sanitizer
 *
 * Utility class for sanitizing API request payloads before logging or storage.
 * Specifically redacts base64 image data.
 */
class AIPKit_Payload_Sanitizer {

    /**
     * Redacts an encoded image string with metadata about size/mime.
     *
     * @param string $encoded_data The encoded payload.
     * @param string $label Label describing the source key.
     * @param string $mime Mime type.
     * @return string
     */
    private static function redact_encoded_value(string $encoded_data, string $label, string $mime = 'unknown/unknown'): string {
        $len = strlen($encoded_data);
        return "[REDACTED {$label}_size={$len} mime={$mime}]";
    }

    /**
     * If the given string is a base64 data URI, replace payload bytes with redaction marker.
     *
     * @param string $value Raw string value.
     * @return string|null Redacted data URI string or null when value is not a base64 data URI.
     */
    private static function redact_data_uri_if_needed(string $value): ?string {
        if (strpos($value, 'data:') !== 0 || strpos($value, ';base64,') === false) {
            return null;
        }

        $base64_marker_pos = strpos($value, ';base64,');
        if ($base64_marker_pos === false) {
            return null;
        }

        $mime = (string) substr($value, 5, $base64_marker_pos - 5);
        $base64_payload = (string) substr($value, $base64_marker_pos + 8);
        $len = strlen($base64_payload);

        return "data:{$mime};base64,[REDACTED base64_size={$len}]";
    }

    /**
     * Sanitizes a single image-input object where encoded bytes may exist under common keys.
     *
     * @param array $image_input
     * @return void
     */
    private static function sanitize_image_input_object(array &$image_input): void {
        $mime = isset($image_input['type']) && is_string($image_input['type']) && $image_input['type'] !== ''
            ? $image_input['type']
            : (isset($image_input['mimeType']) && is_string($image_input['mimeType']) && $image_input['mimeType'] !== ''
                ? $image_input['mimeType']
                : 'unknown/unknown');

        if (isset($image_input['base64']) && is_string($image_input['base64'])) {
            $image_input['base64'] = self::redact_encoded_value($image_input['base64'], 'base64', $mime);
        }
        if (isset($image_input['base64_data']) && is_string($image_input['base64_data'])) {
            $image_input['base64_data'] = self::redact_encoded_value($image_input['base64_data'], 'base64_data', $mime);
        }
        if (isset($image_input['b64_json']) && is_string($image_input['b64_json'])) {
            $image_input['b64_json'] = self::redact_encoded_value($image_input['b64_json'], 'b64_json', $mime);
        }

        // Some payloads carry data URIs under url/image_url keys.
        if (isset($image_input['url']) && is_string($image_input['url'])) {
            $redacted_data_uri = self::redact_data_uri_if_needed($image_input['url']);
            if ($redacted_data_uri !== null) {
                $image_input['url'] = $redacted_data_uri;
            }
        }
        if (isset($image_input['image_url']) && is_string($image_input['image_url'])) {
            $redacted_data_uri = self::redact_data_uri_if_needed($image_input['image_url']);
            if ($redacted_data_uri !== null) {
                $image_input['image_url'] = $redacted_data_uri;
            }
        }
    }

    /**
     * Sanitizes a collection of image payload items.
     *
     * @param array $images
     * @return void
     */
    private static function sanitize_image_collection(array &$images): void {
        foreach ($images as &$image_item_ref) {
            if (is_array($image_item_ref)) {
                self::sanitize_image_input_object($image_item_ref);
                continue;
            }

            if (!is_string($image_item_ref) || $image_item_ref === '') {
                continue;
            }

            $redacted_data_uri = self::redact_data_uri_if_needed($image_item_ref);
            if ($redacted_data_uri !== null) {
                $image_item_ref = $redacted_data_uri;
                continue;
            }

            // URLs are not encoded payload bytes.
            if (preg_match('#^https?://#i', $image_item_ref) === 1) {
                continue;
            }

            // Ollama REST image field uses raw base64 strings.
            $image_item_ref = '[REDACTED image_data_size=' . strlen($image_item_ref) . ']';
        }
        unset($image_item_ref);
    }

    /**
     * Recursively redacts common base64 keys and data URIs.
     *
     * @param array $node
     * @return void
     */
    private static function sanitize_known_base64_recursive(array &$node): void {
        $content_type = isset($node['type']) && is_string($node['type'])
            ? strtolower($node['type'])
            : '';
        if (
            in_array($content_type, ['audio', 'document', 'image', 'video'], true)
            && isset($node['data'])
            && is_string($node['data'])
        ) {
            $mime = isset($node['mime_type']) && is_string($node['mime_type']) && $node['mime_type'] !== ''
                ? $node['mime_type']
                : 'unknown/unknown';
            $node['data'] = self::redact_encoded_value($node['data'], 'data', $mime);
        }

        foreach ($node as $key => &$value) {
            if (is_array($value)) {
                if ((string)$key === 'images') {
                    self::sanitize_image_collection($value);
                }
                self::sanitize_known_base64_recursive($value);
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $key_lower = strtolower((string)$key);
            if (in_array($key_lower, ['base64', 'base64_data', 'b64_json'], true)) {
                $value = self::redact_encoded_value($value, $key_lower);
                continue;
            }

            $redacted_data_uri = self::redact_data_uri_if_needed($value);
            if ($redacted_data_uri !== null) {
                $value = $redacted_data_uri;
            }
        }
        unset($value);
    }

    /**
     * Sanitizes payload only when it is an array.
     *
     * @param mixed $payload
     * @return mixed
     */
    public static function sanitize_payload_if_array($payload) {
        if (!is_array($payload)) {
            return $payload;
        }

        return self::sanitize_for_logging($payload);
    }

    /**
     * Sanitizes image_inputs within a payload array for logging purposes.
     * Replaces base64 data with a redaction notice and size.
     *
     * @param array $payload The payload to sanitize.
     * @return array The sanitized payload.
     */
    public static function sanitize_for_logging(array $payload): array {
        $sanitized_payload = $payload;

        // Sanitize direct image_inputs key (often in $final_ai_params or similar)
        if (isset($sanitized_payload['image_inputs']) && is_array($sanitized_payload['image_inputs'])) {
            self::sanitize_image_collection($sanitized_payload['image_inputs']);
        }
        
        // Sanitize OpenAI/Azure formatted message content (input[...]['content'][...]['image_url'])
        // Note: 'input' is typically the key in OpenAI 'Responses' API payload.
        // Chat Completions API used by Azure/OpenRouter uses 'messages'.
        $message_list_key = isset($sanitized_payload['input']) ? 'input' : (isset($sanitized_payload['messages']) ? 'messages' : null);

        if ($message_list_key && isset($sanitized_payload[$message_list_key]) && is_array($sanitized_payload[$message_list_key])) {
            foreach ($sanitized_payload[$message_list_key] as &$message_ref) { // Use reference
                // Ollama vision payload: messages[].images[].
                if (isset($message_ref['images']) && is_array($message_ref['images'])) {
                    self::sanitize_image_collection($message_ref['images']);
                }

                if (isset($message_ref['content']) && is_array($message_ref['content'])) {
                    foreach ($message_ref['content'] as &$part_ref) { // Use reference
                        if (isset($part_ref['type']) && ($part_ref['type'] === 'input_image' || $part_ref['type'] === 'image_url') && isset($part_ref['image_url'])) { // OpenAI 'Responses' API or Chat Completions image_url part
                            $image_url_to_check = is_array($part_ref['image_url']) && isset($part_ref['image_url']['url'])
                                                  ? $part_ref['image_url']['url']
                                                  : (is_string($part_ref['image_url']) ? $part_ref['image_url'] : null);

                            $redacted_data_uri = is_string($image_url_to_check)
                                ? self::redact_data_uri_if_needed($image_url_to_check)
                                : null;
                            if ($redacted_data_uri !== null) {
                                if (is_array($part_ref['image_url'])) {
                                    $part_ref['image_url']['url'] = $redacted_data_uri;
                                } else {
                                    $part_ref['image_url'] = $redacted_data_uri;
                                }
                            }
                        }
                    }
                    unset($part_ref); 
                }
            }
            unset($message_ref); 
        }
        
        // Sanitize Google Gemini formatted message content (contents[...]['parts'][...]['inlineData'])
        if (isset($sanitized_payload['contents']) && is_array($sanitized_payload['contents'])) {
            foreach ($sanitized_payload['contents'] as &$message_ref) { // Use reference
                if (isset($message_ref['parts']) && is_array($message_ref['parts'])) {
                    foreach ($message_ref['parts'] as &$part_ref) { // Use reference
                        if (isset($part_ref['inlineData']['data'])) {
                            $inline_data_value = is_string($part_ref['inlineData']['data']) ? $part_ref['inlineData']['data'] : wp_json_encode($part_ref['inlineData']['data']);
                            $len = is_string($inline_data_value) ? strlen($inline_data_value) : 0;
                            $mime = $part_ref['inlineData']['mimeType'] ?? 'unknown/unknown';
                            $part_ref['inlineData']['data'] = "[REDACTED base64_size={$len} mime={$mime}]";
                        }
                    }
                    unset($part_ref); 
                }
            }
            unset($message_ref); 
        }

        // Generic image arrays (e.g. response_data.images, source image payloads).
        if (isset($sanitized_payload['images']) && is_array($sanitized_payload['images'])) {
            self::sanitize_image_collection($sanitized_payload['images']);
        }

        // Final recursive pass for known base64 keys and data URIs nested deeper in payloads.
        self::sanitize_known_base64_recursive($sanitized_payload);

        return $sanitized_payload;
    }
}
