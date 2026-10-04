<?php

namespace WPAICG\Dashboard\Ajax;

use WP_Error;
use WPAICG\AIPKit_Providers;
use WPAICG\AIPKit_Role_Manager;
use WPAICG\Vector\AIPKit_Vector_Store_Manager;
use WPAICG\Vector\AIPKit_Vector_Store_Registry;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Base class for Dashboard AJAX Handlers.
 * Provides common permission checks.
 */
abstract class BaseDashboardAjaxHandler {

    protected static function validated_table_identifier(string $table_name): string
    {
        $table_name = trim($table_name);
        if ($table_name === '' || !preg_match('/^[A-Za-z0-9_]+$/', $table_name)) {
            return '';
        }

        return '`' . $table_name . '`';
    }

    protected $required_capability = 'aipkit_manage_settings'; // Default capability for most dashboard actions

    /**
     * Helper to check nonce and module-specific access via Role Manager.
     * Use this for actions within a module that don't require full admin rights.
     *
     * @param string $module_slug The slug of the module to check access for.
     * @param string $nonce_action The nonce action string.
     * @return bool|WP_Error True if permissions are valid, WP_Error otherwise.
     */
    public function check_module_access_permissions(string $module_slug, string $nonce_action = 'aipkit_nonce') {
        // 1. Check nonce first
        if (!check_ajax_referer($nonce_action, '_ajax_nonce', false)) {
            return new WP_Error('nonce_failure', __('Security check failed (nonce).', 'gpt3-ai-content-generator'), ['status' => 403]);
        }

        // 2. Check if the user can access the specified module
        if (!AIPKit_Role_Manager::user_can_access_module($module_slug)) {
             return new WP_Error('permission_denied', __('You do not have permission to perform this action.', 'gpt3-ai-content-generator'), ['status' => 403]);
        }

        // 3. If both checks pass
        return true;
    }

    /**
     * Helper to check nonce and allow access to any of the provided modules.
     *
     * @param array<int, string> $module_slugs Module slugs to check access for.
     * @param string $nonce_action The nonce action string.
     * @return bool|WP_Error True if permissions are valid, WP_Error otherwise.
     */
    public function check_any_module_access_permissions(array $module_slugs, string $nonce_action = 'aipkit_nonce') {
        if (!check_ajax_referer($nonce_action, '_ajax_nonce', false)) {
            return new WP_Error('nonce_failure', __('Security check failed (nonce).', 'gpt3-ai-content-generator'), ['status' => 403]);
        }

        foreach ($module_slugs as $module_slug) {
            if (AIPKit_Role_Manager::user_can_access_module($module_slug)) {
                return true;
            }
        }

        return new WP_Error('permission_denied', __('You do not have permission to perform this action.', 'gpt3-ai-content-generator'), ['status' => 403]);
    }

    /**
     * Helper to send WP_Error as a standard JSON error response.
     *
     * @param WP_Error $error The WP_Error object.
     */
    public function send_wp_error(WP_Error $error) {
        $error_data = [
            'message' => $error->get_error_message(),
            'code'    => $error->get_error_code(),
        ];
        // Extract status code from error data if set, otherwise default
        $error_data_payload = $error->get_error_data();
        $status_code = isset($error_data_payload['status']) && is_int($error_data_payload['status'])
                       ? $error_data_payload['status']
                       : 400; // Default to 400 Bad Request if not specified

        // Attach sanitized details for debugging (without huge payloads)
        $details = [];
        if (is_array($error_data_payload)) {
            $details = $error_data_payload;
            if (isset($details['log_data']) && is_array($details['log_data'])) {
                $log = $details['log_data'];
                // Compute content length and truncate indexed_content if present
                if (isset($log['indexed_content']) && is_string($log['indexed_content'])) {
                    $content = $log['indexed_content'];
                    $details['log_data']['indexed_content_length'] = function_exists('mb_strlen') ? mb_strlen($content) : strlen($content);
                    // Truncate to avoid massive responses
                    $max = 1000;
                    if ((function_exists('mb_strlen') ? mb_strlen($content) : strlen($content)) > $max) {
                        $details['log_data']['indexed_content'] = (function_exists('mb_substr') ? mb_substr($content, 0, $max) : substr($content, 0, $max)) . '...';
                    }
                }
            }
        }

        if (!empty($details)) {
            $error_data['details'] = $details;
        }

        wp_send_json_error($error_data, $status_code);
    }

    protected function create_vector_store_manager(): ?AIPKit_Vector_Store_Manager {
        return $this->ensure_plugin_class(
            AIPKit_Vector_Store_Manager::class,
            'classes/knowledge-base/manager.php'
        )
            ? new AIPKit_Vector_Store_Manager()
            : null;
    }

    protected function create_vector_store_registry(): ?AIPKit_Vector_Store_Registry {
        return $this->ensure_plugin_class(
            AIPKit_Vector_Store_Registry::class,
            'classes/knowledge-base/stores.php'
        )
            ? new AIPKit_Vector_Store_Registry()
            : null;
    }

    /**
     * @return mixed[]|\WP_Error
     */
    public function _get_openai_config() {
        $this->ensure_plugin_class(AIPKit_Providers::class, 'classes/ai/settings.php');
        $openai_data = AIPKit_Providers::get_provider_data('OpenAI');
        if (empty($openai_data['api_key'])) {
            return new WP_Error('missing_openai_key', __('OpenAI API Key is not configured in global settings.', 'gpt3-ai-content-generator'));
        }
        return [
            'api_key' => $openai_data['api_key'],
            // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Provider-specific API transport.
            'base_url' => $openai_data['base_url'] ?? 'https://api.openai.com',
            'api_version' => $openai_data['api_version'] ?? 'v1',
        ];
    }

    /**
     * Shared setup for vector-store dashboard handlers.
     *
     * @return array<string, mixed>
     */
    protected function bootstrap_vector_store_ajax_dependencies(): array {
        global $wpdb;

        $dependencies = [
            'wpdb' => $wpdb,
            'data_source_table_name' => $wpdb->prefix . 'aipkit_vector_data_source',
            'vector_store_manager' => $this->create_vector_store_manager(),
            'vector_store_registry' => $this->create_vector_store_registry(),
            'ai_caller' => null,
        ];

        if ($this->ensure_plugin_class(\WPAICG\Core\AIPKit_AI_Caller::class, 'classes/ai/caller.php')) {
            $dependencies['ai_caller'] = new \WPAICG\Core\AIPKit_AI_Caller();
        }

        $this->ensure_plugin_class(\WPAICG\Includes\AIPKit_Upload_Utils::class, 'classes/security/uploads.php');
        $this->ensure_plugin_class(\WPAICG\aipkit_dashboard::class, 'classes/admin/dashboard.php');

        return $dependencies;
    }

    protected function ensure_plugin_class(string $class_name, string $relative_path): bool {
        if (!class_exists($class_name)) {
            $class_path = WPAICG_PLUGIN_DIR . ltrim($relative_path, '/');
            if (file_exists($class_path)) {
                require_once $class_path;
            }
        }

        return class_exists($class_name);
    }
}
