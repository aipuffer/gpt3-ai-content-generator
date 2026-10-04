<?php

namespace WPAICG\ContentWriter\Ajax;

use WPAICG\ContentWriter\AIPKit_Content_Writer_Prompt_Library_Manager;
use WPAICG\Dashboard\Ajax\BaseDashboardAjaxHandler;
use WPAICG\Utils\AIPKit_Prompt_Sanitizer;
use WP_Error;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * AJAX handler for global prompt library CRUD endpoints.
 */
class AIPKit_Content_Writer_Prompt_Library_Ajax_Handler extends BaseDashboardAjaxHandler
{
    public const NONCE_ACTION = 'aipkit_nonce';

    /**
     * Modules allowed to manage prompt presets.
     *
     * @var array<int, string>
     */
    private const ALLOWED_MODULES = [
        'content-writer',
        'autogpt',
        'ai-forms',
        'bulk_assistant',
        'row_assistant',
        'classic_editor_assistant',
        'block_editor_assistant',
        'chatbot',
    ];

    private ?AIPKit_Content_Writer_Prompt_Library_Manager $prompt_library_manager = null;

    public function __construct()
    {
        if (class_exists(AIPKit_Content_Writer_Prompt_Library_Manager::class)) {
            $this->prompt_library_manager = new AIPKit_Content_Writer_Prompt_Library_Manager();
        }
    }

    public function ajax_list_prompt_library()
    {
        $request_check = $this->check_request();
        if (is_wp_error($request_check)) {
            $this->send_wp_error($request_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in check_request().
        $post_data = wp_unslash($_POST);
        $prompt_type = isset($post_data['prompt_type']) ? sanitize_key((string) $post_data['prompt_type']) : '';

        $include_builtin = $this->read_post_bool($post_data, 'include_builtin', true);
        $include_custom = $this->read_post_bool($post_data, 'include_custom', true);

        $result = $this->prompt_library_manager->get_library_entries(
            $prompt_type !== '' ? $prompt_type : null,
            $include_builtin,
            $include_custom
        );
        if (is_wp_error($result)) {
            $this->send_wp_error($result);
            return;
        }

        wp_send_json_success($result);
    }

    public function ajax_create_prompt_library_item()
    {
        $request_check = $this->check_request();
        if (is_wp_error($request_check)) {
            $this->send_wp_error($request_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in check_request().
        $post_data = wp_unslash($_POST);
        $prompt_type = isset($post_data['prompt_type']) ? sanitize_key((string) $post_data['prompt_type']) : '';
        $label = isset($post_data['label']) ? sanitize_text_field((string) $post_data['label']) : '';
        $prompt = isset($post_data['prompt']) ? AIPKit_Prompt_Sanitizer::sanitize($post_data['prompt']) : '';

        $item = $this->prompt_library_manager->create_custom_prompt(
            $prompt_type,
            $label,
            $prompt,
            get_current_user_id()
        );
        if (is_wp_error($item)) {
            $this->send_wp_error($item);
            return;
        }

        $prompt_type_data = $this->prompt_library_manager->get_library_entries((string) ($item['type'] ?? ''), true, true);
        if (is_wp_error($prompt_type_data)) {
            $this->send_wp_error($prompt_type_data);
            return;
        }

        wp_send_json_success([
            'item'       => $item,
            'type_data'  => $prompt_type_data,
        ]);
    }

    public function ajax_update_prompt_library_item()
    {
        $request_check = $this->check_request();
        if (is_wp_error($request_check)) {
            $this->send_wp_error($request_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in check_request().
        $post_data = wp_unslash($_POST);
        $prompt_id = isset($post_data['prompt_id']) ? sanitize_key((string) $post_data['prompt_id']) : '';
        $updates = [];

        if (array_key_exists('prompt_type', $post_data)) {
            $updates['type'] = sanitize_key((string) $post_data['prompt_type']);
        }
        if (array_key_exists('label', $post_data)) {
            $updates['label'] = sanitize_text_field((string) $post_data['label']);
        }
        if (array_key_exists('prompt', $post_data)) {
            $updates['prompt'] = AIPKit_Prompt_Sanitizer::sanitize($post_data['prompt']);
        }

        $item = $this->prompt_library_manager->update_custom_prompt($prompt_id, $updates);
        if (is_wp_error($item)) {
            $this->send_wp_error($item);
            return;
        }

        $prompt_type_data = $this->prompt_library_manager->get_library_entries((string) ($item['type'] ?? ''), true, true);
        if (is_wp_error($prompt_type_data)) {
            $this->send_wp_error($prompt_type_data);
            return;
        }

        wp_send_json_success([
            'item'      => $item,
            'type_data' => $prompt_type_data,
        ]);
    }

    public function ajax_delete_prompt_library_item()
    {
        $request_check = $this->check_request();
        if (is_wp_error($request_check)) {
            $this->send_wp_error($request_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in check_request().
        $post_data = wp_unslash($_POST);
        $prompt_id = isset($post_data['prompt_id']) ? sanitize_key((string) $post_data['prompt_id']) : '';
        $prompt_type = isset($post_data['prompt_type']) ? sanitize_key((string) $post_data['prompt_type']) : '';

        $deleted = $this->prompt_library_manager->delete_custom_prompt($prompt_id);
        if (is_wp_error($deleted)) {
            $this->send_wp_error($deleted);
            return;
        }

        $response = [
            'prompt_id' => $prompt_id,
            'deleted'   => true,
        ];

        if ($prompt_type !== '') {
            $prompt_type_data = $this->prompt_library_manager->get_library_entries($prompt_type, true, true);
            if (!is_wp_error($prompt_type_data)) {
                $response['type_data'] = $prompt_type_data;
            }
        }

        wp_send_json_success($response);
    }

    /**
     * @return bool|\WP_Error
     */
    private function check_request()
    {
        $permission_check = $this->check_any_module_access_permissions(self::ALLOWED_MODULES, self::NONCE_ACTION);
        if (is_wp_error($permission_check)) {
            return $permission_check;
        }

        if (!$this->prompt_library_manager) {
            return new WP_Error(
                'manager_missing',
                __('Prompt library manager is unavailable.', 'gpt3-ai-content-generator'),
                ['status' => 500]
            );
        }

        return true;
    }

    private function read_post_bool(array $post_data, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $post_data)) {
            return $default;
        }

        $value = $post_data[$key];
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return $default;
        }

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
