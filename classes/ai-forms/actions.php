<?php

namespace WPAICG\AIForms\Admin;

use WPAICG\Dashboard\Ajax\BaseDashboardAjaxHandler;
use WPAICG\AIForms\Storage\AIPKit_AI_Form_Storage;
use WPAICG\Includes\AIPKit_Shared_Assets_Manager;
use WPAICG\Lib\AIForms\Shortcode as ProShortcode;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Handles AJAX requests for AI Form management (Create, Read, Update, Delete).
 * Owns form management actions; AI blueprint generation is loaded separately.
 */
class AIPKit_AI_Form_Ajax_Handler extends BaseDashboardAjaxHandler
{
    private $form_storage;

    public function __construct()
    {
        if (class_exists(AIPKit_AI_Form_Storage::class)) {
            $this->form_storage = new AIPKit_AI_Form_Storage();
        }
    }

    /**
     * Registers the AJAX hooks for this handler.
     */
    public function register_ajax_hooks()
    {
        add_action('wp_ajax_aipkit_save_ai_form', [$this, 'ajax_save_ai_form']);
        add_action('wp_ajax_aipkit_list_ai_forms', [$this, 'ajax_list_ai_forms']);
        add_action('wp_ajax_aipkit_get_ai_form', [$this, 'ajax_get_ai_form']);
        add_action('wp_ajax_aipkit_generate_ai_form_from_prompt', [$this, 'ajax_generate_ai_form_from_prompt']);
        add_action('wp_ajax_aipkit_delete_ai_form', [$this, 'ajax_delete_ai_form']);
        add_action('wp_ajax_aipkit_delete_selected_ai_forms', [$this, 'ajax_delete_selected_ai_forms']);
        add_action('wp_ajax_aipkit_duplicate_ai_form', [$this, 'ajax_duplicate_ai_form']);
        add_action('wp_ajax_aipkit_get_form_preview', [$this, 'ajax_get_form_preview']);
        add_action('wp_ajax_aipkit_export_all_ai_forms', [$this, 'ajax_export_all_ai_forms']);
        add_action('wp_ajax_aipkit_export_selected_ai_forms', [$this, 'ajax_export_selected_ai_forms']);
        add_action('wp_ajax_aipkit_import_ai_forms', [$this, 'ajax_import_ai_forms']);
    }

    /**
     * Provides access to the form storage dependency for logic functions.
     * @return AIPKit_AI_Form_Storage|null
     */
    public function get_form_storage(): ?AIPKit_AI_Form_Storage
    {
        return $this->form_storage;
    }

    /** Check the shared nonce and module access before any form action. */
    private function can_manage_forms(): bool
    {
        $permission_check = $this->check_module_access_permissions('ai-forms', 'aipkit_manage_ai_forms_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return false;
        }
        return true;
    }

    /**
     * AJAX: Saves or updates an AI Form.
     */
    public function ajax_save_ai_form()
    {
        if (!$this->can_manage_forms()) {
            return;
        }

        \WPAICG\Admin\Ajax\AIForms\do_ajax_save_form_logic($this);
    }

    /**
     * AJAX: Lists all AI Forms.
     */
    public function ajax_list_ai_forms()
    {
        if (!$this->can_manage_forms()) {
            return;
        }

        \WPAICG\Admin\Ajax\AIForms\do_ajax_list_forms_logic($this);
    }

    /**
     * AJAX: Gets a single AI Form's data for editing.
     */
    public function ajax_get_ai_form()
    {
        if (!$this->can_manage_forms()) {
            return;
        }

        \WPAICG\Admin\Ajax\AIForms\do_ajax_get_form_logic($this);
    }

    /**
     * AJAX: Generates an AI Form draft from a natural-language prompt.
     */
    public function ajax_generate_ai_form_from_prompt()
    {
        if (!$this->can_manage_forms()) {
            return;
        }

        require_once WPAICG_PLUGIN_DIR . 'classes/ai-forms/blueprints.php';
        \WPAICG\Admin\Ajax\AIForms\do_ajax_generate_form_from_prompt_logic($this);
    }

    /**
     * AJAX: Deletes an AI Form.
     */
    public function ajax_delete_ai_form()
    {
        if (!$this->can_manage_forms()) {
            return;
        }

        \WPAICG\Admin\Ajax\AIForms\do_ajax_delete_form_logic($this);
    }

    /**
     * AJAX: Deletes the explicitly selected AI Forms.
     */
    public function ajax_delete_selected_ai_forms()
    {
        if (!$this->can_manage_forms()) {
            return;
        }

        \WPAICG\Admin\Ajax\AIForms\do_ajax_delete_selected_forms_logic($this);
    }

    /**
     * AJAX: Duplicates an AI Form.
     */
    public function ajax_duplicate_ai_form()
    {
        if (!$this->can_manage_forms()) {
            return;
        }

        \WPAICG\Admin\Ajax\AIForms\do_ajax_duplicate_form_logic($this);
    }

    /**
     * AJAX: Exports all AI Forms.
     * @since 2.1
     */
    public function ajax_export_all_ai_forms()
    {
        if (!$this->can_manage_forms()) {
            return;
        }

        \WPAICG\Admin\Ajax\AIForms\do_ajax_export_all_forms_logic($this);
    }

    /**
     * AJAX: Exports the explicitly selected AI Forms.
     */
    public function ajax_export_selected_ai_forms()
    {
        if (!$this->can_manage_forms()) {
            return;
        }

        \WPAICG\Admin\Ajax\AIForms\do_ajax_export_selected_forms_logic($this);
    }

    /**
     * AJAX: Gets the rendered HTML and required assets for an AI Form preview.
     */
    public function ajax_get_form_preview()
    {
        // 1. Security Check
        if (!$this->can_manage_forms()) {
            return;
        }

        // 2. Get and validate form_id
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce is verified in check_module_access_permissions() above.
        $form_id = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;
        if (empty($form_id)) {
            $this->send_wp_error(new \WP_Error('id_required', __('Form ID is required for preview.', 'gpt3-ai-content-generator'), ['status' => 400]));
            return;
        }

        // 3. Construct and render the shortcode
        $shortcode = '[aipkit_ai_form id="' . $form_id . '"]';
        $rendered_html = do_shortcode($shortcode);

        // 4. Get asset URLs for frontend loading
        $dist_url = WPAICG_PLUGIN_URL . 'dist/';
        $version = defined('WPAICG_VERSION') ? WPAICG_VERSION : '1.0.0';

        $assets = [
            'css' => [
                'public-ai-forms' => $dist_url . 'css/public-ai-forms.bundle.css?ver=' . $version,
            ],
            'js' => [
                'public-ai-forms' => $dist_url . 'js/public-ai-forms.bundle.js?ver=' . $version,
            ],
        ];

        $is_pro_plan = class_exists('\\WPAICG\\aipkit_dashboard') && \WPAICG\aipkit_dashboard::is_pro_plan();
        if (class_exists(ProShortcode::class)) {
            $assets = ProShortcode::add_preview_assets($assets);
        }

        // 5. Generate the public config object that would normally be localized
        $frontend_display_settings = [];
        if (class_exists('\\WPAICG\\AIForms\\Admin\\AIPKit_AI_Form_Settings_Ajax_Handler')) {
            $all_settings = \WPAICG\AIForms\Admin\AIPKit_AI_Form_Settings_Ajax_Handler::get_settings();
            $frontend_display_settings = $all_settings['frontend_display'] ?? [];
        }

        $public_config = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'ajaxNonce' => wp_create_nonce('aipkit_frontend_chat_nonce'),
            'is_user_logged_in' => is_user_logged_in(),
            'is_pro_plan' => $is_pro_plan,
            'allowed_models' => $frontend_display_settings['allowed_models'] ?? '',
            'text' => [
                'processing' => __('Processing...', 'gpt3-ai-content-generator'),
                'error' => __('An error occurred.', 'gpt3-ai-content-generator'),
                'saveAsPost' => __('Save', 'gpt3-ai-content-generator'),
            ]
        ];

        $asset_urls = class_exists(AIPKit_Shared_Assets_Manager::class)
            ? AIPKit_Shared_Assets_Manager::get_public_asset_urls()
            : [];

        // 5b. Get models data
        $models = [];
        if (class_exists('\\WPAICG\\AIPKit_Providers')) {
            $models = [
                'openai'     => \WPAICG\AIPKit_Providers::get_openai_models(),
                'google'     => \WPAICG\AIPKit_Providers::get_google_models(),
                'claude'     => \WPAICG\AIPKit_Providers::get_claude_models(),
                'openrouter' => \WPAICG\AIPKit_Providers::get_openrouter_models(),
                'azure'      => \WPAICG\AIPKit_Providers::get_azure_deployments(),
            ];
            if (class_exists(ProShortcode::class)) {
                $models = array_merge($models, ProShortcode::get_model_lists());
            }
            $models['deepseek'] = \WPAICG\AIPKit_Providers::get_deepseek_models();
            $models['xai'] = \WPAICG\AIPKit_Providers::get_xai_models();
        }

        // 6. Send the response
        wp_send_json_success([
            'html'   => $rendered_html,
            'assets' => $assets,
            'assetUrls' => $asset_urls,
            'config' => $public_config,
            'models' => $models, // Add models to response
        ]);
    }

    /**
     * AJAX: Imports AI Forms from a JSON file.
     * @since 2.1
     */
    public function ajax_import_ai_forms()
    {
        if (!$this->can_manage_forms()) {
            return;
        }

        \WPAICG\Admin\Ajax\AIForms\do_ajax_import_forms_logic($this);
    }
}

namespace WPAICG\Admin\Ajax\AIForms;

use WPAICG\AIForms\Admin\AIPKit_AI_Form_Admin_Setup;
use WPAICG\AIForms\Admin\AIPKit_AI_Form_Ajax_Handler;
use WPAICG\Core\AIPKit_OpenAI_Reasoning;
use WPAICG\Lib\AIForms\StorageSettings;
use WPAICG\Lib\Integrations\Recipes\AIPKit_Stored_Recipes;
use WPAICG\Utils\AIPKit_Prompt_Sanitizer;
use WP_Error;
use function WPAICG\AIForms\Storage\Methods\get_label_defaults;
use function WPAICG\AIForms\Storage\Methods\aipkit_structure_has_elements;

/**
 * Handles the logic for deleting an AI form.
 * Called by AIPKit_AI_Form_Ajax_Handler::ajax_delete_ai_form().
 *
 * @param AIPKit_AI_Form_Ajax_Handler $handler_instance
 * @return void
 */
function do_ajax_delete_form_logic(AIPKit_AI_Form_Ajax_Handler $handler_instance): void
{
    $form_storage = $handler_instance->get_form_storage();

    if (!$form_storage) {
        $handler_instance->send_wp_error(new WP_Error('storage_missing', __('Form storage component is not available.', 'gpt3-ai-content-generator')), 500);
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in the calling class method.
    $form_id = isset($_POST['form_id']) ? absint(wp_unslash($_POST['form_id'])) : 0;
    if (empty($form_id)) {
        $handler_instance->send_wp_error(new WP_Error('id_required', __('Form ID is required for deletion.', 'gpt3-ai-content-generator')), 400);
        return;
    }

    $deleted = $form_storage->delete_form($form_id);
    if ($deleted) {
        $extra_messages = apply_filters('aipkit_ai_forms_after_delete_form_messages', [], $form_id, $form_storage);
        $message = __('Form deleted successfully.', 'gpt3-ai-content-generator');
        if (is_array($extra_messages) && !empty($extra_messages)) {
            $message .= ' ' . implode(' ', array_map('sanitize_text_field', $extra_messages));
        }
        wp_send_json_success(['message' => $message]);
    } else {
        $handler_instance->send_wp_error(new WP_Error('delete_failed', __('Failed to delete form.', 'gpt3-ai-content-generator')), 500);
    }
}

/**
 * Deletes only the AI Forms explicitly supplied by the user.
 *
 * @param AIPKit_AI_Form_Ajax_Handler $handler_instance
 * @return void
 */
function do_ajax_delete_selected_forms_logic(AIPKit_AI_Form_Ajax_Handler $handler_instance): void
{
    $form_storage = $handler_instance->get_form_storage();
    if (!$form_storage) {
        $handler_instance->send_wp_error(new WP_Error('storage_missing', __('Form storage component is not available.', 'gpt3-ai-content-generator')), 500);
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in the calling class method.
    $raw_form_ids = isset($_POST['form_ids']) ? sanitize_text_field(wp_unslash($_POST['form_ids'])) : '';
    $form_ids = json_decode($raw_form_ids, true);
    $form_ids = is_array($form_ids)
        ? array_values(array_unique(array_filter(array_map('absint', $form_ids))))
        : [];

    if (empty($form_ids)) {
        $handler_instance->send_wp_error(new WP_Error('form_ids_required', __('Select at least one form to delete.', 'gpt3-ai-content-generator')), 400);
        return;
    }

    $deleted_ids = [];
    $failed_ids = [];
    foreach ($form_ids as $form_id) {
        if (get_post_type($form_id) !== AIPKit_AI_Form_Admin_Setup::POST_TYPE) {
            $failed_ids[] = $form_id;
            continue;
        }

        if (!$form_storage->delete_form($form_id)) {
            $failed_ids[] = $form_id;
            continue;
        }

        apply_filters('aipkit_ai_forms_after_delete_form_messages', [], $form_id, $form_storage);
        $deleted_ids[] = $form_id;
    }

    if (empty($deleted_ids)) {
        $handler_instance->send_wp_error(new WP_Error('delete_selected_failed', __('The selected forms could not be deleted.', 'gpt3-ai-content-generator')), 500);
        return;
    }

    $error_message = '';
    if (!empty($failed_ids)) {
        $error_message = sprintf(
            /* translators: %d is the number of forms that could not be deleted. */
            _n('%d form could not be deleted.', '%d forms could not be deleted.', count($failed_ids), 'gpt3-ai-content-generator'),
            count($failed_ids)
        );
    }

    wp_send_json_success([
        'error_message' => $error_message,
        'deleted_ids' => $deleted_ids,
        'failed_ids' => $failed_ids,
    ]);
}

/**
 * Handles the logic for duplicating an AI form.
 * Called by AIPKit_AI_Form_Ajax_Handler::ajax_duplicate_ai_form().
 *
 * @param AIPKit_AI_Form_Ajax_Handler $handler_instance
 * @return void
 */
function do_ajax_duplicate_form_logic(AIPKit_AI_Form_Ajax_Handler $handler_instance): void
{
    $form_storage = $handler_instance->get_form_storage();

    if (!$form_storage) {
        $handler_instance->send_wp_error(new WP_Error('storage_missing', __('Form storage component is not available.', 'gpt3-ai-content-generator')), 500);
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in the calling class method.
    $form_id_to_duplicate = isset($_POST['form_id']) ? absint(wp_unslash($_POST['form_id'])) : 0;
    if (empty($form_id_to_duplicate)) {
        $handler_instance->send_wp_error(new WP_Error('id_required', __('Form ID is required for duplication.', 'gpt3-ai-content-generator')), 400);
        return;
    }

    // Get all data from the original form
    $original_form_data = $form_storage->get_form_data($form_id_to_duplicate);
    if (is_wp_error($original_form_data)) {
        $handler_instance->send_wp_error($original_form_data);
        return;
    }

    // The get_form_data() returns 'structure' as a PHP array, but the save function expects 'form_structure' as a JSON string.
    if (isset($original_form_data['structure']) && is_array($original_form_data['structure'])) {
        // Re-encode with flags to preserve Unicode characters, preventing them from becoming gibberish on some servers.
        $original_form_data['form_structure'] = wp_json_encode($original_form_data['structure'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        unset($original_form_data['structure']); // Remove the old PHP array key
    }

    // Prepare data for the new form
    $new_title = $original_form_data['title'] . ' (Copy)';

    // The get_form_data() result is now compatible with the settings array needed by save_form_settings(),
    // which is called by create_form().
    $result = $form_storage->create_form($new_title, $original_form_data);

    if (is_wp_error($result)) {
        $handler_instance->send_wp_error($result);
    } else {
        wp_send_json_success(['message' => __('Form duplicated successfully.', 'gpt3-ai-content-generator'), 'new_form_id' => $result]);
    }
}

/**
 * Sends an AI Forms export for the supplied validated scope.
 *
 * @param AIPKit_AI_Form_Ajax_Handler $handler_instance
 * @return void
 */
function send_ai_forms_export(
    AIPKit_AI_Form_Ajax_Handler $handler_instance,
    array $form_ids,
    string $scope
): void
{
    $form_storage = $handler_instance->get_form_storage();

    if (!$form_storage) {
        $handler_instance->send_wp_error(new WP_Error('storage_missing', __('Form storage component is not available.', 'gpt3-ai-content-generator')), 500);
        return;
    }

    $form_ids = array_values(array_unique(array_filter(array_map('absint', $form_ids))));

    if (empty($form_ids)) {
        wp_send_json_error(['message' => __('No forms found to export.', 'gpt3-ai-content-generator')], 404);
        return;
    }

    $exported_forms = [];
    foreach ($form_ids as $form_id) {
        if (get_post_type($form_id) !== AIPKit_AI_Form_Admin_Setup::POST_TYPE) {
            continue;
        }
        $form_data = $form_storage->get_form_data($form_id);
        if (!is_wp_error($form_data)) {
            $form_data = apply_filters('aipkit_ai_forms_prepare_form_export_data', $form_data, [
                'scope' => $scope,
                'form_id' => (int) $form_id,
                'form_ids' => array_map('intval', $form_ids),
            ]);
            // Remove keys that are not needed for export/import
            unset($form_data['id']);
            unset($form_data['status']);
            $exported_forms[] = $form_data;
        }
    }

    if (empty($exported_forms)) {
        wp_send_json_error(['message' => __('No valid forms found to export.', 'gpt3-ai-content-generator')], 404);
        return;
    }

    wp_send_json_success(['forms' => $exported_forms]);
}

/**
 * Handles the logic for exporting all AI forms.
 * Called by AIPKit_AI_Form_Ajax_Handler::ajax_export_all_ai_forms().
 *
 * @param AIPKit_AI_Form_Ajax_Handler $handler_instance
 * @return void
 */
function do_ajax_export_all_forms_logic(AIPKit_AI_Form_Ajax_Handler $handler_instance): void
{
    $form_storage = $handler_instance->get_form_storage();

    if (!$form_storage) {
        $handler_instance->send_wp_error(new WP_Error('storage_missing', __('Form storage component is not available.', 'gpt3-ai-content-generator')), 500);
        return;
    }

    $all_forms_list = $form_storage->get_forms_list(['posts_per_page' => -1]);
    send_ai_forms_export(
        $handler_instance,
        wp_list_pluck($all_forms_list['forms'], 'id'),
        'all'
    );
}

/**
 * Handles the logic for exporting selected AI forms.
 * Called by AIPKit_AI_Form_Ajax_Handler::ajax_export_selected_ai_forms().
 *
 * @param AIPKit_AI_Form_Ajax_Handler $handler_instance
 * @return void
 */
function do_ajax_export_selected_forms_logic(AIPKit_AI_Form_Ajax_Handler $handler_instance): void
{
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in the calling class method.
    $raw_form_ids = isset($_POST['form_ids']) ? sanitize_text_field(wp_unslash($_POST['form_ids'])) : '';
    $form_ids = json_decode($raw_form_ids, true);
    if (!is_array($form_ids) || empty($form_ids)) {
        $handler_instance->send_wp_error(new WP_Error('form_ids_required', __('Select at least one form to export.', 'gpt3-ai-content-generator')), 400);
        return;
    }

    send_ai_forms_export($handler_instance, $form_ids, 'selected');
}

/**
 * Handles the logic for fetching a single AI form's data.
 * Called by AIPKit_AI_Form_Ajax_Handler::ajax_get_ai_form().
 *
 * @param AIPKit_AI_Form_Ajax_Handler $handler_instance
 * @return void
 */
function do_ajax_get_form_logic(AIPKit_AI_Form_Ajax_Handler $handler_instance): void
{
    $form_storage = $handler_instance->get_form_storage();

    if (!$form_storage) {
        $handler_instance->send_wp_error(new WP_Error('storage_missing', __('Form storage component is not available.', 'gpt3-ai-content-generator')), 500);
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in the calling class method.
    $form_id = isset($_POST['form_id']) ? absint(wp_unslash($_POST['form_id'])) : 0;
    if (empty($form_id)) {
        $handler_instance->send_wp_error(new WP_Error('id_required', __('Form ID is required.', 'gpt3-ai-content-generator')), 400);
        return;
    }

    $form_data = $form_storage->get_form_data($form_id);
    if (is_wp_error($form_data)) {
        $handler_instance->send_wp_error($form_data);
    } else {
        $connected_apps = get_ai_form_connected_apps($form_id);

        wp_send_json_success([
            'form' => $form_data,
            'connected_apps' => $connected_apps,
        ]);
    }
}

/**
 * Handles the logic for importing AI forms from a JSON file.
 * Called by AIPKit_AI_Form_Ajax_Handler::ajax_import_ai_forms().
 *
 * @param AIPKit_AI_Form_Ajax_Handler $handler_instance
 * @return void
 */
function do_ajax_import_forms_logic(AIPKit_AI_Form_Ajax_Handler $handler_instance): void
{
    $form_storage = $handler_instance->get_form_storage();

    if (!$form_storage) {
        $handler_instance->send_wp_error(new WP_Error('storage_missing_import', __('Form storage component is not available.', 'gpt3-ai-content-generator')), 500);
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in the calling class method.
    $forms_json = isset($_POST['forms_json']) ? wp_kses_post(wp_unslash($_POST['forms_json'])) : '{}';
    if (empty($forms_json)) {
        $handler_instance->send_wp_error(new WP_Error('no_data_import', __('No form data received for import.', 'gpt3-ai-content-generator')), 400);
        return;
    }

    $forms_to_import = json_decode($forms_json, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($forms_to_import)) {
        $handler_instance->send_wp_error(new WP_Error('invalid_json_import', __('Invalid JSON data received for import.', 'gpt3-ai-content-generator')), 400);
        return;
    }

    $imported_count = 0;
    $failed_count = 0;
    $imported_records = [];

    foreach ($forms_to_import as $form_data) {
        $title = isset($form_data['title']) ? sanitize_text_field($form_data['title']) : 'Imported Form';

        // Sanitize settings before creating the form
        // This is a minimal sanitization; a more robust one could be implemented.
        $settings = $form_data;
        unset($settings['title'], $settings['id'], $settings['status']); // Remove fields not used in creation

        // The export file has 'structure' as an array, but the save function expects 'form_structure' as a JSON string.
        if (isset($settings['structure']) && is_array($settings['structure'])) {
            // Re-encode with flags to preserve Unicode characters, preventing them from becoming gibberish on some servers.
            $settings['form_structure'] = wp_json_encode($settings['structure'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            unset($settings['structure']); // Remove the old PHP array key
        }

        $settings = apply_filters('aipkit_ai_forms_import_form_settings_before_create', $settings, $form_data, $forms_to_import, $form_storage);

        // Append "(Imported)" to avoid direct title conflicts, making management easier.
        $new_title = $title . ' (Imported)';

        $result = $form_storage->create_form($new_title, $settings);

        if (is_wp_error($result)) {
            $failed_count++;
        } else {
            $imported_count++;
            $imported_records[] = [
                'new_id' => (int) $result,
                'source' => $form_data,
                'title' => $title,
            ];
        }
    }
    /* translators: %d is the number of forms imported */
    $message = sprintf(_n('%d form was imported successfully.', '%d forms were imported successfully.', $imported_count, 'gpt3-ai-content-generator'), $imported_count);

    if ($failed_count > 0) {
        /* translators: %d is the number of forms that failed to import */
        $message .= ' ' . sprintf(_n('%d form failed to import.','%d forms failed to import.',$failed_count,'gpt3-ai-content-generator'),$failed_count);
    }

    $extra_messages = apply_filters('aipkit_ai_forms_import_result_messages', [], $imported_records, $forms_to_import, $form_storage);
    if (is_array($extra_messages) && !empty($extra_messages)) {
        $message .= ' ' . implode(' ', array_map('sanitize_text_field', $extra_messages));
    }

    wp_send_json_success(['message' => $message, 'imported_count' => $imported_count, 'failed_count' => $failed_count]);
}

/**
 * Handles the logic for listing all AI forms with dynamic querying.
 * Called by AIPKit_AI_Form_Ajax_Handler::ajax_list_ai_forms().
 * UPDATED: Handles pagination, search, and sorting parameters.
 * UPDATED: Optimized to prevent N+1 queries by fetching all post meta in a single query.
 *
 * @param AIPKit_AI_Form_Ajax_Handler $handler_instance
 * @return void
 */
function do_ajax_list_forms_logic(AIPKit_AI_Form_Ajax_Handler $handler_instance): void
{
    $form_storage = $handler_instance->get_form_storage();
    if (!$form_storage) {
        $handler_instance->send_wp_error(new WP_Error('storage_missing', __('Form storage component is not available.', 'gpt3-ai-content-generator')), 500);
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in the calling class method.
    $post_data = wp_unslash($_POST);
    $paged = isset($post_data['page']) ? absint($post_data['page']) : 1;
    $per_page = isset($post_data['per_page']) ? absint($post_data['per_page']) : 10;
    $per_page = in_array($per_page, [10, 25, 50, 100], true) ? $per_page : 10;
    $search = isset($post_data['search']) ? sanitize_text_field($post_data['search']) : '';
    $sort_by = isset($post_data['sort_by']) ? sanitize_key($post_data['sort_by']) : 'modified';
    $sort_order_raw = isset($post_data['sort_order']) ? strtoupper(sanitize_key($post_data['sort_order'])) : 'DESC';
    $sort_order = in_array($sort_order_raw, ['ASC', 'DESC']) ? $sort_order_raw : 'ASC';
    $provider_filter = isset($post_data['filter_provider']) ? sanitize_text_field($post_data['filter_provider']) : 'all';

    // Whitelist sortable columns
    $allowed_sort_keys = ['id', 'title', 'provider', 'model', 'date', 'modified'];
    if (!in_array($sort_by, $allowed_sort_keys)) {
        $sort_by = 'title';
    }
    // WP_Query uses 'ID' instead of 'id'
    if ($sort_by === 'id') {
        $sort_by = 'ID';
    }

    $args = [
        'paged'          => $paged,
        'posts_per_page' => $per_page,
        'search'         => $search,
        'orderby'        => $sort_by,
        'order'          => $sort_order,
        'filter_provider' => $provider_filter,
    ];

    $result = $form_storage->get_forms_list($args);

    // The result from get_forms_list_logic is now an array with 'forms' and 'pagination' keys
    wp_send_json_success($result);
}

/**
 * Handles the logic for saving an AI form.
 * Called by AIPKit_AI_Form_Ajax_Handler::ajax_save_ai_form().
 *
 * @param AIPKit_AI_Form_Ajax_Handler $handler_instance
 * @return void
 */
function do_ajax_save_form_logic(AIPKit_AI_Form_Ajax_Handler $handler_instance): void
{

    $form_storage = $handler_instance->get_form_storage();

    if (!$form_storage) {
        $handler_instance->send_wp_error(new WP_Error('storage_missing', __('Form storage component is not available.', 'gpt3-ai-content-generator')), 500);
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in the calling class method.
    $post_data = wp_unslash($_POST);

    $form_id = isset($post_data['form_id']) && !empty($post_data['form_id']) ? absint($post_data['form_id']) : null;
    $allow_empty_structure = isset($post_data['allow_empty_structure']) && $post_data['allow_empty_structure'] === '1';
    $title = isset($post_data['title']) ? sanitize_text_field($post_data['title']) : '';
    $template_key = isset($post_data['template_key']) ? sanitize_key($post_data['template_key']) : '';
    $allowed_template_keys = ['lead_capture', 'customer_feedback', 'book_appointment', 'support_request', 'waitlist_signup'];
    if (!in_array($template_key, $allowed_template_keys, true)) {
        $template_key = '';
    }
    $prompt_template = isset($post_data['prompt_template']) ? AIPKit_Prompt_Sanitizer::sanitize($post_data['prompt_template']) : '';
    $form_structure_json = isset($post_data['form_structure']) ? wp_kses_post($post_data['form_structure']) : '[]';

    if (
        array_key_exists('workflow_config', $post_data)
        && !apply_filters('aipkit_ai_forms_allow_workflow_config_save', false, $post_data, $form_id)
    ) {
        $handler_instance->send_wp_error(new WP_Error('workflow_requires_pro', __('AI Form workflows require a Pro plan.', 'gpt3-ai-content-generator')), 403);
        return;
    }

    // Process labels - now receiving as a JSON string from JavaScript
    $default_labels = get_label_defaults();

    $submitted_labels = [];
    if (isset($post_data['labels']) && !empty($post_data['labels'])) {
        $labels_json = $post_data['labels'];
        $decoded_labels = json_decode($labels_json, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_labels)) {
            $submitted_labels = $decoded_labels;
        }
    }

    $existing_labels = [];
    if (!empty($form_id)) {
        $existing_labels_json = get_post_meta($form_id, '_aipkit_ai_form_labels', true);
        $decoded_existing_labels = json_decode($existing_labels_json, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_existing_labels)) {
            $existing_labels = $decoded_existing_labels;
        }
    }

    // Merge defaults: use submitted value if present, otherwise preserve existing saved values, otherwise use default.
    $final_labels = [];
    foreach ($default_labels as $key => $default_value) {
        $has_submitted_value = array_key_exists($key, $submitted_labels);
        $submitted_value = $has_submitted_value ? trim((string) $submitted_labels[$key]) : '';
        $existing_value = isset($existing_labels[$key]) ? trim((string) $existing_labels[$key]) : '';
        if ($has_submitted_value) {
            $final_value = !empty($submitted_value) ? $submitted_value : $default_value;
        } elseif (!empty($existing_value)) {
            $final_value = $existing_value;
        } else {
            $final_value = $default_value;
        }
        $final_labels[sanitize_key($key)] = sanitize_text_field($final_value);
    }

    // --- Get AI config fields from POST ---
    $ai_provider = isset($post_data['ai_provider']) ? sanitize_text_field($post_data['ai_provider']) : null;
    $ai_model = isset($post_data['ai_model']) ? sanitize_text_field($post_data['ai_model']) : null;
    $temperature = isset($post_data['temperature']) ? sanitize_text_field($post_data['temperature']) : null;
    $max_tokens = isset($post_data['max_tokens']) ? absint($post_data['max_tokens']) : null;
    $top_p = isset($post_data['top_p']) ? sanitize_text_field($post_data['top_p']) : null;
    $frequency_penalty = isset($post_data['frequency_penalty']) ? sanitize_text_field($post_data['frequency_penalty']) : null;
    $presence_penalty = isset($post_data['presence_penalty']) ? sanitize_text_field($post_data['presence_penalty']) : null;
    $reasoning_effort = AIPKit_OpenAI_Reasoning::sanitize_effort($post_data['reasoning_effort'] ?? '');
    if ($reasoning_effort === '') {
        $reasoning_effort = 'none';
    }
    $conversation_ui_preset = class_exists(StorageSettings::class)
        ? StorageSettings::get_submitted_conversation_ui_preset($post_data)
        : null;

    // --- Get Vector config fields from POST ---
    $enable_vector_store = isset($post_data['enable_vector_store']) && $post_data['enable_vector_store'] === '1' ? '1' : '0';
    $vector_store_provider = isset($post_data['vector_store_provider']) ? sanitize_key($post_data['vector_store_provider']) : 'openai';
    if (!in_array($vector_store_provider, ['local', 'openai', 'google', 'pinecone', 'qdrant', 'chroma'], true)) {
        $vector_store_provider = 'openai';
    }
    $openai_vector_store_ids = isset($post_data['openai_vector_store_ids']) && is_array($post_data['openai_vector_store_ids']) ? array_map('sanitize_text_field', $post_data['openai_vector_store_ids']) : [];
    $google_file_search_store_names = isset($post_data['google_file_search_store_names']) && is_array($post_data['google_file_search_store_names'])
        ? array_values(array_unique(array_filter(array_map(
            static function ($store_name): string {
                $store_name = sanitize_text_field((string) $store_name);
                return strpos($store_name, 'fileSearchStores/') === 0 ? $store_name : '';
            },
            $post_data['google_file_search_store_names']
        ))))
        : [];
    $pinecone_index_name = isset($post_data['pinecone_index_name']) ? sanitize_text_field($post_data['pinecone_index_name']) : '';
    $qdrant_collection_name = isset($post_data['qdrant_collection_name']) ? sanitize_text_field($post_data['qdrant_collection_name']) : '';
    $chroma_collection_name = isset($post_data['chroma_collection_name']) ? sanitize_text_field($post_data['chroma_collection_name']) : '';
    $local_store_id = isset($post_data['local_store_id']) ? sanitize_text_field($post_data['local_store_id']) : '';
    $vector_embedding_provider = isset($post_data['vector_embedding_provider']) ? sanitize_key($post_data['vector_embedding_provider']) : 'openai';
    $vector_embedding_model = isset($post_data['vector_embedding_model']) ? sanitize_text_field($post_data['vector_embedding_model']) : '';
    $vector_store_top_k = isset($post_data['vector_store_top_k']) ? absint($post_data['vector_store_top_k']) : 3;
    $vector_store_confidence_threshold = isset($post_data['vector_store_confidence_threshold']) ? absint($post_data['vector_store_confidence_threshold']) : 20;

    // --- Get Web Search config fields from POST ---
    $openai_web_search_enabled = isset($post_data['openai_web_search_enabled']) && $post_data['openai_web_search_enabled'] === '1' ? '1' : '0';
    $claude_web_search_enabled = isset($post_data['claude_web_search_enabled']) && $post_data['claude_web_search_enabled'] === '1' ? '1' : '0';
    $openrouter_web_search_enabled = isset($post_data['openrouter_web_search_enabled']) && $post_data['openrouter_web_search_enabled'] === '1' ? '1' : '0';
    $xai_web_search_enabled = isset($post_data['xai_web_search_enabled']) && $post_data['xai_web_search_enabled'] === '1' ? '1' : '0';
    $google_search_grounding_enabled = isset($post_data['google_search_grounding_enabled']) && $post_data['google_search_grounding_enabled'] === '1' ? '1' : '0';
    if ($vector_store_provider === 'google' && $ai_provider !== 'Google') {
        wp_send_json_error(['message' => __('Google File Search requires Google as the AI provider.', 'gpt3-ai-content-generator')], 400);
        return;
    }
    if ($vector_store_provider === 'google' && $enable_vector_store === '1' && $google_search_grounding_enabled === '1') {
        wp_send_json_error(['message' => __('Google File Search and Google Search cannot be enabled together.', 'gpt3-ai-content-generator')], 400);
        return;
    }
    if ($vector_store_provider === 'google' && $enable_vector_store === '1' && empty($google_file_search_store_names)) {
        wp_send_json_error(['message' => __('Select at least one Google store.', 'gpt3-ai-content-generator')], 400);
        return;
    }

    // OpenAI Web Search sub-settings
    $openai_web_search_context_size = isset($post_data['openai_web_search_context_size']) ? sanitize_text_field($post_data['openai_web_search_context_size']) : 'medium';
    $openai_web_search_loc_type = isset($post_data['openai_web_search_loc_type']) ? sanitize_text_field($post_data['openai_web_search_loc_type']) : 'none';
    $openai_web_search_loc_country = isset($post_data['openai_web_search_loc_country']) ? sanitize_text_field($post_data['openai_web_search_loc_country']) : '';
    $openai_web_search_loc_city = isset($post_data['openai_web_search_loc_city']) ? sanitize_text_field($post_data['openai_web_search_loc_city']) : '';
    $openai_web_search_loc_region = isset($post_data['openai_web_search_loc_region']) ? sanitize_text_field($post_data['openai_web_search_loc_region']) : '';
    $openai_web_search_loc_timezone = isset($post_data['openai_web_search_loc_timezone']) ? sanitize_text_field($post_data['openai_web_search_loc_timezone']) : '';

    // Claude Web Search sub-settings
    $claude_web_search_max_uses = isset($post_data['claude_web_search_max_uses']) ? absint($post_data['claude_web_search_max_uses']) : 5;
    $claude_web_search_max_uses = max(1, min($claude_web_search_max_uses, 20));
    $claude_web_search_loc_type = isset($post_data['claude_web_search_loc_type']) ? sanitize_text_field($post_data['claude_web_search_loc_type']) : 'none';
    if (!in_array($claude_web_search_loc_type, ['none', 'approximate'], true)) {
        $claude_web_search_loc_type = 'none';
    }
    $claude_web_search_loc_country = isset($post_data['claude_web_search_loc_country']) ? sanitize_text_field($post_data['claude_web_search_loc_country']) : '';
    $claude_web_search_loc_city = isset($post_data['claude_web_search_loc_city']) ? sanitize_text_field($post_data['claude_web_search_loc_city']) : '';
    $claude_web_search_loc_region = isset($post_data['claude_web_search_loc_region']) ? sanitize_text_field($post_data['claude_web_search_loc_region']) : '';
    $claude_web_search_loc_timezone = isset($post_data['claude_web_search_loc_timezone']) ? sanitize_text_field($post_data['claude_web_search_loc_timezone']) : '';
    $normalize_domains = static function (string $domains_raw): string {
        $parts = preg_split('/[\r\n,]+/', $domains_raw);
        if (!is_array($parts)) {
            return '';
        }
        $domains = [];
        foreach ($parts as $part) {
            $domain = strtolower(trim((string) $part));
            if ($domain === '') {
                continue;
            }
            $domain = preg_replace('/^https?:\/\//', '', $domain);
            $domain = trim((string) $domain, " \t\n\r\0\x0B/");
            if ($domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)) {
                continue;
            }
            $domains[] = $domain;
        }
        $domains = array_unique($domains);
        return implode("\n", $domains);
    };
    $claude_web_search_allowed_domains = isset($post_data['claude_web_search_allowed_domains']) ? $normalize_domains((string) $post_data['claude_web_search_allowed_domains']) : '';
    $claude_web_search_blocked_domains = isset($post_data['claude_web_search_blocked_domains']) ? $normalize_domains((string) $post_data['claude_web_search_blocked_domains']) : '';
    if ($claude_web_search_allowed_domains !== '') {
        $claude_web_search_blocked_domains = '';
    }
    $claude_web_search_cache_ttl = isset($post_data['claude_web_search_cache_ttl']) ? sanitize_text_field($post_data['claude_web_search_cache_ttl']) : 'none';
    if (!in_array($claude_web_search_cache_ttl, ['none', '5m', '1h'], true)) {
        $claude_web_search_cache_ttl = 'none';
    }

    // OpenRouter Web Search sub-settings
    $openrouter_web_search_engine = isset($post_data['openrouter_web_search_engine']) ? sanitize_key((string) $post_data['openrouter_web_search_engine']) : 'auto';
    if (!in_array($openrouter_web_search_engine, ['auto', 'native', 'exa', 'firecrawl', 'parallel', 'perplexity'], true)) {
        $openrouter_web_search_engine = 'auto';
    }
    $openrouter_web_search_max_results = isset($post_data['openrouter_web_search_max_results']) ? absint($post_data['openrouter_web_search_max_results']) : 5;
    $openrouter_web_search_max_results = max(1, min($openrouter_web_search_max_results, 25));
    $openrouter_web_search_max_uses = isset($post_data['openrouter_web_search_max_uses']) ? absint($post_data['openrouter_web_search_max_uses']) : 1;
    $openrouter_web_search_max_uses = max(1, min($openrouter_web_search_max_uses, 10));
    $openrouter_web_search_max_total_results = isset($post_data['openrouter_web_search_max_total_results']) ? absint($post_data['openrouter_web_search_max_total_results']) : 10;
    $openrouter_web_search_max_total_results = max(1, min($openrouter_web_search_max_total_results, 100));
    $openrouter_web_search_context_size = isset($post_data['openrouter_web_search_context_size']) ? sanitize_key((string) $post_data['openrouter_web_search_context_size']) : 'auto';
    if (!in_array($openrouter_web_search_context_size, ['auto', 'low', 'medium', 'high'], true)) {
        $openrouter_web_search_context_size = 'auto';
    }
    $openrouter_web_search_allowed_domains = isset($post_data['openrouter_web_search_allowed_domains']) ? $normalize_domains((string) $post_data['openrouter_web_search_allowed_domains']) : '';
    $openrouter_web_search_excluded_domains = isset($post_data['openrouter_web_search_excluded_domains']) ? $normalize_domains((string) $post_data['openrouter_web_search_excluded_domains']) : '';
    if ($openrouter_web_search_allowed_domains !== '') {
        $openrouter_web_search_excluded_domains = '';
    }

    $decoded_structure = json_decode($form_structure_json, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded_structure)) {
        $handler_instance->send_wp_error(new WP_Error('invalid_structure_json', __('Invalid form structure data submitted.', 'gpt3-ai-content-generator')), 400);
        return;
    }
    if ($form_id && !$allow_empty_structure && !aipkit_structure_has_elements($decoded_structure)) {
        $existing_structure_json = get_post_meta($form_id, '_aipkit_ai_form_structure', true);
        $existing_structure = json_decode((string) $existing_structure_json, true);
        if (is_array($existing_structure) && aipkit_structure_has_elements($existing_structure)) {
            $handler_instance->send_wp_error(
                new WP_Error(
                    'empty_structure_confirmation_required',
                    __('This form currently has no fields. Confirm saving to overwrite and remove the existing fields.', 'gpt3-ai-content-generator')
                ),
                400
            );
            return;
        }
    }

    if (empty($title)) {
        $handler_instance->send_wp_error(new WP_Error('title_required', __('Form title cannot be empty.', 'gpt3-ai-content-generator')), 400);
        return;
    }
    if (empty($prompt_template)) {
        $handler_instance->send_wp_error(new WP_Error('prompt_required', __('Prompt template is required.', 'gpt3-ai-content-generator')), 400);
        return;
    }

    $settings = [
        'template_key' => $template_key,
        'prompt_template' => $prompt_template,
        'form_structure'  => $form_structure_json,
        'ai_provider' => $ai_provider,
        'ai_model' => $ai_model,
        'temperature' => $temperature,
        'max_tokens' => $max_tokens,
        'top_p' => $top_p,
        'frequency_penalty' => $frequency_penalty,
        'presence_penalty' => $presence_penalty,
        'reasoning_effort' => $reasoning_effort,
        'conversation_ui_preset' => $conversation_ui_preset,
        // Vector settings
        'enable_vector_store' => $enable_vector_store,
        'vector_store_provider' => $vector_store_provider,
        'openai_vector_store_ids' => $openai_vector_store_ids,
        'google_file_search_store_names' => $google_file_search_store_names,
        'pinecone_index_name' => $pinecone_index_name,
        'qdrant_collection_name' => $qdrant_collection_name,
        'chroma_collection_name' => $chroma_collection_name,
        'local_store_ids' => $local_store_id !== '' ? [$local_store_id] : [],
        'vector_embedding_provider' => $vector_embedding_provider,
        'vector_embedding_model' => $vector_embedding_model,
        'vector_store_top_k' => $vector_store_top_k,
        'vector_store_confidence_threshold' => $vector_store_confidence_threshold,
        // Web Search settings
        'openai_web_search_enabled' => $openai_web_search_enabled,
        'claude_web_search_enabled' => $claude_web_search_enabled,
        'openrouter_web_search_enabled' => $openrouter_web_search_enabled,
        'xai_web_search_enabled' => $xai_web_search_enabled,
        'google_search_grounding_enabled' => $google_search_grounding_enabled,
        // OpenAI Web Search sub-settings
        'openai_web_search_context_size' => $openai_web_search_context_size,
        'openai_web_search_loc_type' => $openai_web_search_loc_type,
        'openai_web_search_loc_country' => $openai_web_search_loc_country,
        'openai_web_search_loc_city' => $openai_web_search_loc_city,
        'openai_web_search_loc_region' => $openai_web_search_loc_region,
        'openai_web_search_loc_timezone' => $openai_web_search_loc_timezone,
        // Claude Web Search sub-settings
        'claude_web_search_max_uses' => $claude_web_search_max_uses,
        'claude_web_search_loc_type' => $claude_web_search_loc_type,
        'claude_web_search_loc_country' => $claude_web_search_loc_country,
        'claude_web_search_loc_city' => $claude_web_search_loc_city,
        'claude_web_search_loc_region' => $claude_web_search_loc_region,
        'claude_web_search_loc_timezone' => $claude_web_search_loc_timezone,
        'claude_web_search_allowed_domains' => $claude_web_search_allowed_domains,
        'claude_web_search_blocked_domains' => $claude_web_search_blocked_domains,
        'claude_web_search_cache_ttl' => $claude_web_search_cache_ttl,
        // OpenRouter Web Search sub-settings
        'openrouter_web_search_engine' => $openrouter_web_search_engine,
        'openrouter_web_search_max_results' => $openrouter_web_search_max_results,
        'openrouter_web_search_max_uses' => $openrouter_web_search_max_uses,
        'openrouter_web_search_max_total_results' => $openrouter_web_search_max_total_results,
        'openrouter_web_search_context_size' => $openrouter_web_search_context_size,
        'openrouter_web_search_allowed_domains' => $openrouter_web_search_allowed_domains,
        'openrouter_web_search_excluded_domains' => $openrouter_web_search_excluded_domains,
        // Save protection flags
        'allow_empty_structure' => $allow_empty_structure,
        // Labels
        'labels' => $final_labels,
    ];

    $settings = apply_filters('aipkit_ai_forms_sanitize_save_settings', $settings, $post_data, $form_id);
    if (is_wp_error($settings)) {
        $handler_instance->send_wp_error($settings, 400);
        return;
    }
    if (!is_array($settings)) {
        $handler_instance->send_wp_error(new WP_Error('invalid_save_settings', __('Invalid form settings submitted.', 'gpt3-ai-content-generator')), 400);
        return;
    }

    if ($form_id) {
        $updated_post_id = wp_update_post([
            'ID' => $form_id,
            'post_title' => $title,
        ], true);

        if (is_wp_error($updated_post_id)) {
            $handler_instance->send_wp_error($updated_post_id);
            return;
        }
        $saved = $form_storage->save_form_settings($form_id, $settings);
        if (!$saved) {
            $handler_instance->send_wp_error(new WP_Error('save_failed', __('Unable to save form settings.', 'gpt3-ai-content-generator')), 500);
            return;
        }
        $connected_apps = get_ai_form_connected_apps($form_id);
        wp_send_json_success([
            'message' => __('Form updated successfully.', 'gpt3-ai-content-generator'),
            'form_id' => $form_id,
            'connected_apps' => $connected_apps,
        ]);
    } else {
        $result = $form_storage->create_form($title, $settings);
        if (is_wp_error($result)) {
            $handler_instance->send_wp_error($result);
        } else {
            $new_form_id = absint($result);
            $connected_apps = get_ai_form_connected_apps($new_form_id);
            wp_send_json_success([
                'message' => __('Form created successfully.', 'gpt3-ai-content-generator'),
                'form_id' => $new_form_id,
                'connected_apps' => $connected_apps,
            ]);
        }
    }
}

/** Keep the response shape stable when the paid integration owner is absent. */
function get_ai_form_connected_apps(int $form_id): array
{
    return class_exists(AIPKit_Stored_Recipes::class)
        && method_exists(AIPKit_Stored_Recipes::class, 'get_ai_form_connected_apps_payload')
        ? AIPKit_Stored_Recipes::get_ai_form_connected_apps_payload($form_id)
        : ['count' => 0, 'summary' => '', 'recipes' => []];
}
