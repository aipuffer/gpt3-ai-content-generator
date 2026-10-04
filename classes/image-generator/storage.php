<?php


namespace WPAICG\Images;

use WPAICG\AIPKit_Providers;
use WPAICG\Utils\AIPKit_Prompt_Sanitizer;
use WP_Error;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/** Prepares an attachment or local image for provider input. */
trait AIPKit_Image_Input_Trait
{
    /** Shared visual context for image metadata; uses the caller's explicitly selected model. */
    private function get_image_context_for_attachment(int $attachment_id, string $provider, \WPAICG\Core\AIPKit_AI_Caller $ai_caller, string $model, array $params = [])
    {
        if (!AIPKit_Providers::model_supports_image_input($provider, $model)) { return ''; }
        $file = $this->get_attachment_image_path($attachment_id);
        $key = 'aipkit_img_ctx_' . md5($attachment_id . '|' . $provider . '|' . $model . '|' . ($file ? filemtime($file) : 0));
        $cached = get_transient($key);
        if (is_string($cached) && $cached !== '') { return $cached; }
        $image = $this->get_attachment_image_payload($attachment_id);
        if (!$image) { return ''; }
        $params['image_inputs'] = [$image];
        $params['max_completion_tokens'] = 256;
        $response = $ai_caller->make_standard_call($provider, $model, [['role' => 'user', 'content' => 'Describe the image in one short sentence for SEO context. Return only the description.']], $params, null, ['attachment_id' => $attachment_id]);
        if (is_wp_error($response)) { return $response; }
        $context = trim(preg_replace('/\s+/', ' ', (string) ($response['content'] ?? '')));
        if ($context !== '') { set_transient($key, $context, 30 * MINUTE_IN_SECONDS); }
        return $context;
    }

    private function get_attachment_image_path(int $attachment_id): string
    {
        $original_path = (string) get_attached_file($attachment_id);
        if ($original_path === '') {
            return '';
        }

        $meta = wp_get_attachment_metadata($attachment_id);
        if (is_array($meta) && !empty($meta['sizes']['medium']['file'])) {
            $dir = trailingslashit(pathinfo($original_path, PATHINFO_DIRNAME));
            $medium_path = $dir . $meta['sizes']['medium']['file'];
            if (file_exists($medium_path)) {
                return $medium_path;
            }
        }

        return file_exists($original_path) ? $original_path : '';
    }

    private function get_attachment_image_payload(int $attachment_id): array
    {
        $file_path = $this->get_attachment_image_path($attachment_id);
        if ($file_path !== '') {
            $payload = $this->get_image_payload_from_path($file_path);
            if (!empty($payload)) {
                return $payload;
            }
        }

        $image_url = wp_get_attachment_image_url($attachment_id, 'medium');
        if (empty($image_url)) {
            return [];
        }

        $response = wp_remote_get($image_url, ['timeout' => 15]);
        if (is_wp_error($response)) {
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        if ($body === '') {
            return [];
        }

        $content_type = (string) wp_remote_retrieve_header($response, 'content-type');
        $mime_type = trim(strtok($content_type, ';'));
        if ($mime_type === '' || strpos($mime_type, 'image/') !== 0) {
            return [];
        }

        return [
            'base64' => base64_encode($body),
            'type' => $mime_type,
        ];
    }

    private function get_image_payload_from_path(string $file_path): array
    {
        if (!is_readable($file_path)) {
            return [];
        }

        $file_size = filesize($file_path);
        if ($file_size !== false && $file_size > 50 * 1024 * 1024) {
            return [];
        }

        $image_bytes = file_get_contents($file_path);
        if ($image_bytes === false) {
            return [];
        }

        $mime_type = wp_get_image_mime($file_path);
        if (empty($mime_type)) {
            $filetype = wp_check_filetype($file_path);
            $mime_type = $filetype['type'] ?? '';
        }

        if ($mime_type === '' || strpos($mime_type, 'image/') !== 0) {
            return [];
        }

        return [
            'base64' => base64_encode($image_bytes),
            'type' => $mime_type,
        ];
    }
}

/**
 * AIPKit_Image_Storage_Helper
 *
 * Helper class for saving generated images to the WordPress Media Library
 * and managing related metadata.
 */
class AIPKit_Image_Storage_Helper
{
    /**
     * Saves a generated image to the media library.
     *
     * @param array   $image_data_item    An item from the API response's 'images' array (containing 'url' or 'b64_json').
     * @param string  $original_prompt    The original prompt used for generation.
     * @param array   $generation_options The final options used for generation (provider, model, size, etc.).
     * @param int|null $wp_user_id         The WordPress user ID to associate with the attachment, or null for system.
     *
     * @return int|WP_Error The attachment ID on success, or WP_Error on failure.
     */
    public static function save_image_to_media_library(array $image_data_item, string $original_prompt, array $generation_options, ?int $wp_user_id)
    {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        $image_content_binary = null;
        $attachment_meta = [];
        if (isset($generation_options['aipkit_attachment_meta']) && is_array($generation_options['aipkit_attachment_meta'])) {
            $attachment_meta = $generation_options['aipkit_attachment_meta'];
        }
        $filename_seed = $attachment_meta['filename_base'] ?? substr($original_prompt, 0, 50);
        $filename_base = sanitize_title_with_dashes($filename_seed);
        if ($filename_base !== '' && strlen($filename_base) > 60) {
            $filename_base = substr($filename_base, 0, 60);
        }
        $filename_base = $filename_base ?: 'ai-generated-image';
        $extension = 'png'; // Default extension
        $mime_type = 'image/png'; // Default mime type

        // Determine content and type
        if (!empty($image_data_item['b64_json'])) {
            $image_content_binary = base64_decode($image_data_item['b64_json']);
            if ($image_content_binary === false) {
                return new WP_Error('image_decode_failed', __('Failed to decode base64 image data.', 'gpt3-ai-content-generator'));
            }
            // Prefer provider-parsed data URI mime type when available.
            $allowed_data_uri_mime_map = [
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
            ];
            $parsed_mime_type = isset($image_data_item['mime_type']) ? strtolower(sanitize_text_field((string) $image_data_item['mime_type'])) : '';
            if ($parsed_mime_type !== '' && isset($allowed_data_uri_mime_map[$parsed_mime_type])) {
                $mime_type = $parsed_mime_type;
                $extension = $allowed_data_uri_mime_map[$parsed_mime_type];
            }

            // Determine extension from output_format for GPT Image models.
            if (
                AIPKit_Providers::is_openai_gpt_image_model((string) ($generation_options['model'] ?? ''))
                && !empty($generation_options['output_format'])
            ) {
                $extension = strtolower($generation_options['output_format']); // png, jpeg, webp
                if ($extension === 'jpeg') {
                    $extension = 'jpg';
                } // common alias
                $mime_type = 'image/' . ($extension === 'jpg' ? 'jpeg' : $extension);
            }
        } elseif (!empty($image_data_item['url'])) {
            $response = wp_safe_remote_get($image_data_item['url'], ['timeout' => 60]);
            if (is_wp_error($response)) {
                return new WP_Error('image_download_failed', __('Failed to download image from URL: ', 'gpt3-ai-content-generator') . $response->get_error_message());
            }
            $image_content_binary = wp_remote_retrieve_body($response);
            if (empty($image_content_binary)) {
                return new WP_Error('image_download_empty', __('Downloaded image content is empty.', 'gpt3-ai-content-generator'));
            }
            // Try to determine extension from URL or Content-Type header
            $url_parts = wp_parse_url($image_data_item['url']);
            $url_path = $url_parts['path'] ?? '';
            $url_extension = pathinfo($url_path, PATHINFO_EXTENSION);
            if (!empty($url_extension) && in_array(strtolower($url_extension), ['png', 'jpg', 'jpeg', 'webp', 'gif'])) {
                $extension = strtolower($url_extension);
                $mime_type = 'image/' . ($extension === 'jpg' ? 'jpeg' : $extension);
            } else {
                $content_type_header = wp_remote_retrieve_header($response, 'content-type');
                if ($content_type_header) {
                    if (strpos($content_type_header, 'image/png') !== false) {
                        $extension = 'png';
                        $mime_type = 'image/png';
                    } elseif (strpos($content_type_header, 'image/jpeg') !== false) {
                        $extension = 'jpg';
                        $mime_type = 'image/jpeg';
                    } elseif (strpos($content_type_header, 'image/webp') !== false) {
                        $extension = 'webp';
                        $mime_type = 'image/webp';
                    } elseif (strpos($content_type_header, 'image/gif') !== false) {
                        $extension = 'gif';
                        $mime_type = 'image/gif';
                    }
                }
            }
        } else {
            return new WP_Error('no_image_data', __('No image URL or base64 data provided.', 'gpt3-ai-content-generator'));
        }

        $filename = $filename_base . '-' . time() . '.' . $extension;

        // Upload bits
        $upload = wp_upload_bits($filename, null, $image_content_binary);
        if (!empty($upload['error'])) {
            return new WP_Error('image_upload_failed', __('Failed to upload image: ', 'gpt3-ai-content-generator') . $upload['error']);
        }

        // Prepare attachment data
        $attachment_title_raw = $attachment_meta['title'] ?? substr($original_prompt, 0, 100);
        $attachment_title = sanitize_text_field($attachment_title_raw) ?: __('Image', 'gpt3-ai-content-generator');
        $attachment_caption = isset($attachment_meta['caption']) ? sanitize_text_field($attachment_meta['caption']) : '';
        $attachment_description = isset($attachment_meta['description'])
            ? sanitize_textarea_field($attachment_meta['description'])
            : $original_prompt;
        $attachment = [
            'guid'           => $upload['url'],
            'post_mime_type' => $mime_type,
            'post_title'     => $attachment_title,
            'post_content'   => $attachment_description,
            'post_status'    => 'inherit',
        ];
        if ($attachment_caption !== '') {
            $attachment['post_excerpt'] = $attachment_caption;
        }
        if ($wp_user_id && $wp_user_id > 0) {
            $attachment['post_author'] = $wp_user_id;
        }

        // Insert attachment
        $attach_id = wp_insert_attachment($attachment, $upload['file']);
        if (is_wp_error($attach_id)) {
            wp_delete_file($upload['file']); // Clean up uploaded file
            return $attach_id;
        }

        // Generate attachment metadata
        $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);

        // Add custom meta data
        update_post_meta($attach_id, '_aipkit_generated_image', '1');
        update_post_meta($attach_id, '_aipkit_image_prompt', $original_prompt);
        if (isset($generation_options['provider'])) {
            update_post_meta($attach_id, '_aipkit_image_provider', sanitize_text_field($generation_options['provider']));
        }
        if (isset($generation_options['model'])) {
            update_post_meta($attach_id, '_aipkit_image_model', sanitize_text_field($generation_options['model']));
        }
        if (isset($generation_options['quality'])) {
            update_post_meta($attach_id, '_aipkit_image_quality', sanitize_text_field($generation_options['quality']));
        }
        if (isset($generation_options['style'])) {
            update_post_meta($attach_id, '_aipkit_image_style', sanitize_text_field($generation_options['style']));
        }
        if (isset($image_data_item['revised_prompt'])) {
            update_post_meta($attach_id, '_aipkit_image_revised_prompt', AIPKit_Prompt_Sanitizer::sanitize($image_data_item['revised_prompt']));
        }
        if (isset($image_data_item['photographer'])) {
            update_post_meta($attach_id, '_aipkit_image_photographer', sanitize_text_field($image_data_item['photographer']));
        }
        $alt_text = '';
        if (!empty($attachment_meta['alt'])) {
            $alt_text = sanitize_text_field($attachment_meta['alt']);
        } elseif (isset($image_data_item['alt'])) {
            $alt_text = sanitize_text_field($image_data_item['alt']);
        }
        if ($alt_text !== '') {
            update_post_meta($attach_id, '_aipkit_image_alt_text', $alt_text);
            update_post_meta($attach_id, '_wp_attachment_image_alt', $alt_text);
        }

        return $attach_id;
    }
}
