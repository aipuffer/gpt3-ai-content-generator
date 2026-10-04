<?php


namespace WPAICG\Chat\Frontend\Ajax;

use WPAICG\Chat\Admin\Ajax\Traits\Trait_CheckFrontendPermissions;
use WPAICG\Chat\Admin\Ajax\Traits\Trait_SendWPError;
use WP_Error;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Handles AJAX requests for chatbot form submissions from the frontend.
 */
class ChatFormSubmissionAjaxHandler {

    use Trait_CheckFrontendPermissions;
    use Trait_SendWPError;

    private $bot_storage;

    public function __construct() {
        if (class_exists(\WPAICG\Chat\Storage\BotStorage::class)) {
            $this->bot_storage = new \WPAICG\Chat\Storage\BotStorage();
        } else {
            $this->bot_storage = null;
        }
    }

    /**
     * AJAX handler for 'aipkit_handle_form_submission'.
     */
    public function ajax_handle_form_submission(): void {

        $permission_check = $this->check_frontend_permissions();
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_frontend_permissions().
        $post_data = wp_unslash($_POST);
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_frontend_permissions().
        $bot_id = isset($post_data['bot_id']) ? absint($post_data['bot_id']) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_frontend_permissions().
        $form_id = isset($post_data['form_id']) ? sanitize_text_field($post_data['form_id']) : '';
        $form_title = isset($post_data['form_title']) ? sanitize_text_field($post_data['form_title']) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_frontend_permissions().
        $trigger_id = isset($post_data['trigger_id']) ? sanitize_key($post_data['trigger_id']) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_frontend_permissions().
        $submitted_data_json = isset($post_data['submitted_data']) ? wp_kses_post($post_data['submitted_data']) : '{}';
        // Optional compatibility payloads from newer frontend bundles.
        $submitted_data_display_json = isset($post_data['submitted_data_display']) ? wp_kses_post($post_data['submitted_data_display']) : '{}';
        $submitted_data_labels_json = isset($post_data['submitted_data_labels']) ? wp_kses_post($post_data['submitted_data_labels']) : '{}';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_frontend_permissions().
        $conversation_uuid = isset($post_data['conversation_uuid']) ? sanitize_key($post_data['conversation_uuid']) : '';

        $user_id = get_current_user_id();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_frontend_permissions().
        $session_id_from_post = isset($post_data['session_id']) ? sanitize_text_field($post_data['session_id']) : '';

        $final_session_id = '';
        if (!$user_id) {
            if (!empty($session_id_from_post)) {
                $final_session_id = $session_id_from_post;
            }
        }

        $post_id_from_request = isset($post_data['post_id']) ? absint($post_data['post_id']) : 0;

        if (empty($bot_id) || empty($form_id) || empty($conversation_uuid)) {
            $this->send_wp_error(new WP_Error('missing_params', __('Missing required parameters (bot, form, or conversation ID).', 'gpt3-ai-content-generator'), ['status' => 400]));
            return;
        }

        $submitted_data = json_decode($submitted_data_json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($submitted_data)) {
            $this->send_wp_error(new WP_Error('invalid_submitted_data', __('Invalid submitted form data.', 'gpt3-ai-content-generator'), ['status' => 400]));
            return;
        }
        $submitted_data_display = json_decode($submitted_data_display_json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($submitted_data_display)) {
            $submitted_data_display = [];
        }
        $submitted_data_labels = json_decode($submitted_data_labels_json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($submitted_data_labels)) {
            $submitted_data_labels = [];
        }

        $sanitize_recursive_values = static function ($value) use (&$sanitize_recursive_values) {
            if (is_array($value)) {
                $sanitized = [];
                foreach ($value as $key => $item) {
                    $sanitized_key = sanitize_text_field((string) $key);
                    if ($sanitized_key === '') {
                        continue;
                    }
                    $sanitized[$sanitized_key] = $sanitize_recursive_values($item);
                }
                return $sanitized;
            }
            return sanitize_text_field((string) $value);
        };
        $sanitize_labels_map = static function (array $labels): array {
            $sanitized = [];
            foreach ($labels as $key => $label) {
                $sanitized_key = sanitize_text_field((string) $key);
                if ($sanitized_key === '') {
                    continue;
                }
                $sanitized[$sanitized_key] = sanitize_text_field((string) $label);
            }
            return $sanitized;
        };

        $submitted_data_display = $sanitize_recursive_values($submitted_data_display);
        $submitted_data_labels = $sanitize_labels_map($submitted_data_labels);

        // Backward-compatible fallbacks for older frontend bundles.
        if (empty($submitted_data_display) && !empty($submitted_data)) {
            $submitted_data_display = $sanitize_recursive_values($submitted_data);
        }
        if (empty($submitted_data_labels) && !empty($submitted_data)) {
            foreach ($submitted_data as $field_key => $_unused) {
                $sanitized_key = sanitize_text_field((string) $field_key);
                if ($sanitized_key !== '') {
                    $submitted_data_labels[$sanitized_key] = $sanitized_key;
                }
            }
        }

        if (!$user_id && empty($final_session_id)) {
             $this->send_wp_error(new WP_Error('missing_identifier', __('User or Session ID is required for guests.', 'gpt3-ai-content-generator'), ['status' => 400]));
             return;
        }

        if (class_exists(\WPAICG\Lib\Chat\FormActions::class)) {
            $handler = new \WPAICG\Lib\Chat\FormActions($this->bot_storage, function (WP_Error $error): void {
                $this->send_wp_error($error);
            });
            $handler->submit(compact(
                'bot_id',
                'form_id',
                'form_title',
                'trigger_id',
                'submitted_data',
                'submitted_data_json',
                'submitted_data_display',
                'submitted_data_labels',
                'conversation_uuid',
                'user_id',
                'final_session_id',
                'post_id_from_request'
            ));
            return;
        }

        wp_send_json_success(['message' => __('Form submitted.', 'gpt3-ai-content-generator') . ' (' . __('Triggers not active or fully available.', 'gpt3-ai-content-generator') . ')']);
    }
}
