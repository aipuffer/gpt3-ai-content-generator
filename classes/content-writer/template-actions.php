<?php

namespace WPAICG\ContentWriter\Ajax;

use WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
* Handles AJAX actions for Content Writer templates.
* Delegates logic to namespaced functions.
*/
class AIPKit_Content_Writer_Template_Ajax_Handler extends AIPKit_Content_Writer_Base_Ajax_Action
{
    private $template_manager;
    public const NONCE_ACTION = 'aipkit_content_writer_template_nonce';

    public function __construct()
    {
        parent::__construct();
        if (class_exists(AIPKit_Content_Writer_Template_Manager::class)) {
            $this->template_manager = new AIPKit_Content_Writer_Template_Manager();
        }
    }

    // Public getter for the externalized logic to access the dependency
    public function get_template_manager(): ?AIPKit_Content_Writer_Template_Manager
    {
        return $this->template_manager;
    }

    /**
    * AJAX: Saves or updates a template.
    */
    public function ajax_save_template()
    {
        if ($this->check_template_permissions()) {
            Template\ajax_save_template_logic($this);
        }
    }

    /**
    * AJAX: Renames an owned custom template without changing its configuration.
    */
    public function ajax_rename_template()
    {
        if ($this->check_template_permissions()) {
            Template\ajax_rename_template_logic($this);
        }
    }

    /**
    * AJAX: Deletes a template.
    */
    public function ajax_delete_template()
    {
        if ($this->check_template_permissions()) {
            Template\ajax_delete_template_logic($this);
        }
    }

    /**
    * AJAX: Lists all templates for the current user.
    */
    public function ajax_list_templates()
    {
        if ($this->check_template_permissions()) {
            Template\ajax_list_templates_logic($this);
        }
    }

    /**
    * AJAX: Resets starter templates for the current user.
    */
    public function ajax_reset_starter_templates()
    {
        if ($this->check_template_permissions()) {
            Template\ajax_reset_starter_templates_logic($this);
        }
    }
    /** Checks the nonce and module access before any template operation. */
    private function check_template_permissions(): bool
    {
        $permission_check = $this->check_module_access_permissions('content-writer', self::NONCE_ACTION);
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return false;
        }

        return true;
    }
}

namespace WPAICG\ContentWriter\Ajax\Template;

use WPAICG\ContentWriter\Ajax\AIPKit_Content_Writer_Template_Ajax_Handler;
use WP_Error;

/**
* Handles the logic for saving or updating a content writer template.
*
* @param AIPKit_Content_Writer_Template_Ajax_Handler $handler
* @return void
*/
function ajax_save_template_logic(AIPKit_Content_Writer_Template_Ajax_Handler $handler): void
{
    // Permission check is done in the calling method
    if (!$handler->get_template_manager()) {
        $handler->send_wp_error(new WP_Error('manager_missing', 'Template manager unavailable.'), 500);
        return;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $template_id = isset($_POST['template_id']) && !empty($_POST['template_id']) ? absint($_POST['template_id']) : 0;
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $template_name = isset($_POST['template_name']) ? sanitize_text_field(wp_unslash($_POST['template_name'])) : '';
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $config_json = isset($_POST['config']) ? wp_kses_post(wp_unslash($_POST['config'])) : '{}';
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $template_type = isset($_POST['template_type']) ? sanitize_key($_POST['template_type']) : 'content_writer';
    $config = json_decode($config_json, true);

    if (empty($template_name)) {
        $handler->send_wp_error(new WP_Error('missing_name', 'Template name is required.'), 400);
        return;
    }
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($config)) {
        $handler->send_wp_error(new WP_Error('invalid_config', 'Invalid template configuration data.'), 400);
        return;
    }

    $template_manager = $handler->get_template_manager();

    if ($template_id > 0) {
        $result = $template_manager->update_template($template_id, $template_name, $config);
    } else {
        $result = $template_manager->create_template($template_name, $config, $template_type);
    }

    if (is_wp_error($result)) {
        $handler->send_wp_error($result);
    } else {
        $new_template_id = ($template_id > 0) ? $template_id : $result;
        $message = ($template_id > 0) ? __('Template updated successfully.', 'gpt3-ai-content-generator') : __('Template saved successfully.', 'gpt3-ai-content-generator');

        $saved_template = $template_manager->get_template($new_template_id);
        $response_config = [];
        if (!is_wp_error($saved_template) && isset($saved_template['config'])) {
            $response_config = $saved_template['config'];
        } else {
            $response_config = $config;
            if (isset($response_config['post_categories']) && is_string($response_config['post_categories'])) {
                $response_config['post_categories'] = array_map('absint', array_filter(explode(',', $response_config['post_categories'])));
            } elseif (isset($response_config['post_categories']) && is_array($response_config['post_categories'])) {
                $response_config['post_categories'] = array_map('absint', $response_config['post_categories']);
            } else {
                $response_config['post_categories'] = [];
            }
        }

        wp_send_json_success([
        'message' => $message,
        'template_id' => $new_template_id,
        'template_name' => $template_name,
        'template_config' => $response_config
        ]);
    }
}

/**
 * Renames an owned custom template without replacing its saved configuration.
 */
function ajax_rename_template_logic(AIPKit_Content_Writer_Template_Ajax_Handler $handler): void
{
    $template_manager = $handler->get_template_manager();
    if (!$template_manager) {
        $handler->send_wp_error(new WP_Error('manager_missing', __('Template manager unavailable.', 'gpt3-ai-content-generator')), 500);
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The calling handler verifies the nonce.
    $template_id = isset($_POST['template_id']) ? absint($_POST['template_id']) : 0;
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The calling handler verifies the nonce.
    $template_name = isset($_POST['template_name']) ? sanitize_text_field(wp_unslash($_POST['template_name'])) : '';

    if ($template_id < 1 || $template_name === '') {
        $handler->send_wp_error(new WP_Error('invalid_template_rename', __('Choose a template and enter a name.', 'gpt3-ai-content-generator')), 400);
        return;
    }

    $result = $template_manager->rename_template($template_id, $template_name);
    if (is_wp_error($result)) {
        $handler->send_wp_error($result);
        return;
    }

    wp_send_json_success([
        'template_id' => $template_id,
        'template_name' => $template_name,
    ]);
}

/**
* Handles the logic for deleting a template.
*
* @param AIPKit_Content_Writer_Template_Ajax_Handler $handler
* @return void
*/
function ajax_delete_template_logic(AIPKit_Content_Writer_Template_Ajax_Handler $handler): void
{
    // Permission check done in caller
    if (!$handler->get_template_manager()) {
        $handler->send_wp_error(new WP_Error('manager_missing', 'Template manager unavailable.'), 500);
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $template_id = isset($_POST['template_id']) ? absint($_POST['template_id']) : 0;
    if (empty($template_id)) {
        $handler->send_wp_error(new WP_Error('missing_id', 'Template ID is required.'), 400);
        return;
    }

    $result = $handler->get_template_manager()->delete_template($template_id);
    if (is_wp_error($result)) {
        $handler->send_wp_error($result);
    } else {
        $user_id = get_current_user_id();
        if ($user_id) {
            \WPAICG\ContentWriter\TemplateManagerMethods\remove_cw_starter_template_id_for_user($user_id, $template_id);
        }
        wp_send_json_success(['message' => __('Template deleted successfully.', 'gpt3-ai-content-generator')]);
    }
}

/**
* Handles the logic for listing all templates for the current user.
*
* @param AIPKit_Content_Writer_Template_Ajax_Handler $handler
* @return void
*/
function ajax_list_templates_logic(AIPKit_Content_Writer_Template_Ajax_Handler $handler): void
{
    // Permission check done in caller
    if (!$handler->get_template_manager()) {
        $handler->send_wp_error(new WP_Error('manager_missing', 'Template manager unavailable.'), 500);
        return;
    }

    \WPAICG\ContentWriter\TemplateManagerMethods\ensure_starter_templates_exist_logic(
        $handler->get_template_manager()
    );
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $template_type = isset($_POST['template_type']) ? sanitize_key($_POST['template_type']) : 'content_writer';
    $templates = $handler->get_template_manager()->get_templates_for_user($template_type);
    wp_send_json_success(['templates' => $templates]);
}

/**
 * Handles the logic for resetting starter templates.
 *
 * @param AIPKit_Content_Writer_Template_Ajax_Handler $handler
 * @return void
 */
function ajax_reset_starter_templates_logic(AIPKit_Content_Writer_Template_Ajax_Handler $handler): void
{
    // Permission check done in caller
    if (!$handler->get_template_manager()) {
        $handler->send_wp_error(new WP_Error('manager_missing', 'Template manager unavailable.'), 500);
        return;
    }

    $result = $handler->get_template_manager()->reset_starter_templates();
    if (is_wp_error($result)) {
        $handler->send_wp_error($result);
        return;
    }

    wp_send_json_success([
        'message' => __('Starter templates reset.', 'gpt3-ai-content-generator'),
        'template_ids' => $result,
    ]);
}
