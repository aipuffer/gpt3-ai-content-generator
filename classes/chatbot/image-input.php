<?php


namespace WPAICG\Chat\Core\Validation;

use WP_Error;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Shared validator for frontend chat image payloads.
 */
class ChatImageInputValidator
{
    private const MAX_IMAGE_SIZE_MB = 20;
    private const MAX_IMAGE_SIZE_BYTES = 20971520; // 20MB
    private const MAX_IMAGE_COUNT = 4;
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Validate and resize inline images for providers with bounded request bodies. */
    public static function prepare_inline_images(array $images)
    {
        if (!$images || count($images) > self::MAX_IMAGE_COUNT) {
            return new WP_Error('invalid_image_input', __('Upload between one and four images.', 'gpt3-ai-content-generator'), ['status' => 400]);
        }
        $result = [];
        foreach ($images as $image) {
            $raw = is_string($image['base64'] ?? null) ? base64_decode($image['base64'], true) : false;
            $info = $raw !== false && strlen($raw) <= self::MAX_IMAGE_SIZE_BYTES ? @getimagesizefromstring($raw) : false;
            if (!$info || !in_array($info['mime'], self::ALLOWED_MIME_TYPES, true) || $info['mime'] !== ($image['type'] ?? '') || $info[0] * $info[1] > 40000000) {
                return new WP_Error('invalid_image_input', __('Upload a valid JPG, PNG, or WebP image of up to 20 MB and 40 megapixels.', 'gpt3-ai-content-generator'), ['status' => 400]);
            }
            if (max($info[0], $info[1]) > 1024 || strlen($raw) > 250000) {
                if (!function_exists('wp_tempnam')) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
                $temporary = wp_tempnam('aipkit-image-input');
                $output = $temporary ? $temporary . '.jpg' : '';
                try {
                    if (!$temporary || file_put_contents($temporary, $raw) === false) { throw new \RuntimeException(); }
                    $editor = wp_get_image_editor($temporary);
                    if (is_wp_error($editor) || (max($info[0], $info[1]) > 1024 && is_wp_error($editor->resize(1024, 1024, false)))) { throw new \RuntimeException(); }
                    $editor->set_quality(75);
                    $saved = $editor->save($output, 'image/jpeg');
                    if (is_wp_error($saved) || !is_readable($output)) { throw new \RuntimeException(); }
                    $raw = file_get_contents($output);
                    if ($raw === false || strlen($raw) > 250000) { throw new \RuntimeException(); }
                    $info['mime'] = 'image/jpeg';
                } catch (\Throwable $error) {
                    return new WP_Error('image_resize_failed', __('This image could not be prepared. Upload a smaller JPG, PNG, or WebP image.', 'gpt3-ai-content-generator'), ['status' => 400]);
                } finally {
                    if ($temporary && file_exists($temporary)) { wp_delete_file($temporary); }
                    if ($output && file_exists($output)) { wp_delete_file($output); }
                }
            }
            $result[] = ['type' => $info['mime'], 'base64' => base64_encode($raw)];
        }
        return $result;
    }

    /**
     * Parses and validates image payloads received from frontend JSON.
     *
     * @param string|null $image_inputs_json Frontend JSON payload.
     * @return array|WP_Error|null Normalized image input array, WP_Error on invalid payload, or null when empty.
     */
    public static function parse_and_validate(?string $image_inputs_json)
    {
        if (empty($image_inputs_json)) {
            return null;
        }

        $decoded = json_decode($image_inputs_json, true);
        if (!is_array($decoded) || empty($decoded)) {
            return new WP_Error(
                'invalid_image_payload',
                __('Invalid image upload payload.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }

        if (count($decoded) > self::MAX_IMAGE_COUNT) {
            return new WP_Error(
                'too_many_images',
                sprintf(
                    /* translators: %d: max image count */
                    __('Too many images uploaded. Max images: %d.', 'gpt3-ai-content-generator'),
                    self::MAX_IMAGE_COUNT
                ),
                ['status' => 400]
            );
        }

        $normalized = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                return new WP_Error(
                    'invalid_image_payload',
                    __('Invalid image upload payload.', 'gpt3-ai-content-generator'),
                    ['status' => 400]
                );
            }

            $mime_type = isset($item['mime_type']) ? strtolower(sanitize_text_field((string) $item['mime_type'])) : '';
            $base64_data = isset($item['base64_data']) ? trim((string) $item['base64_data']) : '';

            if ($mime_type === '' || $base64_data === '') {
                return new WP_Error(
                    'invalid_image_payload',
                    __('Invalid image upload payload.', 'gpt3-ai-content-generator'),
                    ['status' => 400]
                );
            }

            if (strncmp($base64_data, 'data:', strlen('data:')) === 0) {
                $parts = explode('base64,', $base64_data, 2);
                if (count($parts) === 2) {
                    $base64_data = $parts[1];
                }
            }

            if (!in_array($mime_type, self::ALLOWED_MIME_TYPES, true)) {
                return new WP_Error(
                    'invalid_image_type',
                    __('Invalid image type. Allowed: JPG, PNG, WEBP.', 'gpt3-ai-content-generator'),
                    ['status' => 400]
                );
            }

            $base64_data = preg_replace('/\s+/', '', $base64_data);
            if (!is_string($base64_data) || $base64_data === '') {
                return new WP_Error(
                    'invalid_image_payload',
                    __('Invalid image data provided.', 'gpt3-ai-content-generator'),
                    ['status' => 400]
                );
            }

            $decoded_binary = base64_decode($base64_data, true);
            if ($decoded_binary === false || $decoded_binary === '') {
                return new WP_Error(
                    'invalid_image_payload',
                    __('Invalid image data provided.', 'gpt3-ai-content-generator'),
                    ['status' => 400]
                );
            }

            $binary_size = strlen($decoded_binary);
            if ($binary_size > self::MAX_IMAGE_SIZE_BYTES) {
                return new WP_Error(
                    'image_too_large',
                    sprintf(
                        /* translators: %d: max image size in MB */
                        __('Image is too large. Max size: %dMB.', 'gpt3-ai-content-generator'),
                        self::MAX_IMAGE_SIZE_MB
                    ),
                    ['status' => 413]
                );
            }

            $normalized[] = [
                'type' => $mime_type,
                'base64' => $base64_data,
                'field_id' => isset($item['field_id']) ? sanitize_key((string) $item['field_id']) : '',
                'filename' => isset($item['filename']) ? sanitize_file_name((string) $item['filename']) : '',
                'size' => $binary_size,
            ];
        }

        return $normalized;
    }
}
