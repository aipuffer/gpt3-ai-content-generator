<?php
/**
 * Shared chatbot AJAX access checks and JSON errors.
 */
namespace WPAICG\Chat\Admin\Ajax\Traits;

use WP_Error;
use WPAICG\AIPKit_Role_Manager;
use WPAICG\Utils\AIPKit_CORS_Manager;
use WPAICG\Lib\Chat\FrontendPermissions;

if (!defined('ABSPATH')) {
    exit;
}

trait Trait_CheckModuleAccess {
    /**
     * Helper to check nonce and module-specific access via Role Manager.
     * Use this for actions within a module that don't require full admin rights.
     *
     * @param string $module_slug The slug of the module to check access for.
     * @param string $nonce_action The nonce action string. Defaults to 'aipkit_nonce'.
     * @return bool|WP_Error True if permissions are valid, WP_Error otherwise.
     */
    protected function check_module_access_permissions(string $module_slug, string $nonce_action = 'aipkit_nonce') {
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
}

trait Trait_CheckFrontendPermissions {
    /**
     * Helper to check nonce for FRONTEND actions (can be called by admin JS).
     * Now includes CORS checking for embedded chatbots.
     *
     * @param string $nonce_action The nonce action string.
     * @return bool|WP_Error True if permissions are valid, WP_Error otherwise.
     */
    protected function check_frontend_permissions(string $nonce_action = 'aipkit_frontend_chat_nonce') {
        // Handle preflight OPTIONS request
        AIPKit_CORS_Manager::handle_preflight_request();

        if (class_exists(FrontendPermissions::class)) {
            $origin_error = FrontendPermissions::check_embed_origin();
            if (is_wp_error($origin_error)) {
                return $origin_error;
            }
        }

        // Use check_ajax_referer for standard WP behavior, checking $_REQUEST
        if (!check_ajax_referer($nonce_action, '_ajax_nonce', false)) {
            return new WP_Error('nonce_failure', __('Security check failed (nonce).', 'gpt3-ai-content-generator'), ['status' => 403]);
        }
        // No capability check here, as it's primarily for frontend/guest use,
        // but can be called by admin JS (e.g., sidebar). Specific methods might add checks.
        return true;
    }
}

trait Trait_SendWPError {
    /**
     * Helper to send WP_Error as a standard JSON error response.
     *
     * @param WP_Error $error The WP_Error object.
     */
    protected function send_wp_error(WP_Error $error) {
        $error_data_for_json_response = [ // Renamed to avoid confusion with WP_Error's internal data
            'message' => $error->get_error_message(),
            'code' => $error->get_error_code(),
        ];
        $wp_error_internal_data = $error->get_error_data(); // This is the $data param passed to new WP_Error
        if (is_array($wp_error_internal_data) && !empty($wp_error_internal_data['cloud_operation_id'])) {
            $error_data_for_json_response['operation_id'] = $wp_error_internal_data['cloud_operation_id'];
        }
        foreach (['stop_batch', 'outcome_unknown'] as $flag) {
            if (!empty($wp_error_internal_data[$flag])) { $error_data_for_json_response[$flag] = true; }
        }
        $status_code = isset($wp_error_internal_data['status']) && is_int($wp_error_internal_data['status'])
                       ? $wp_error_internal_data['status']
                       : 400; // Default to 400 Bad Request if not specified

        wp_send_json_error($error_data_for_json_response, $status_code);
    }
}

namespace WPAICG\Chat\Admin\Ajax;

use WPAICG\Chat\Admin\Ajax\Traits\Trait_CheckModuleAccess;
use WPAICG\Chat\Admin\Ajax\Traits\Trait_CheckFrontendPermissions;
use WPAICG\Chat\Admin\Ajax\Traits\Trait_SendWPError;

/**
 * Base class for Chat Admin AJAX Handlers.
 * Provides common access checks and error handling by using traits.
 */
abstract class BaseAjaxHandler {

    use Trait_CheckModuleAccess;
    use Trait_CheckFrontendPermissions;
    use Trait_SendWPError;

    protected $required_capability = 'aipkit_manage_settings'; // Default capability
}
