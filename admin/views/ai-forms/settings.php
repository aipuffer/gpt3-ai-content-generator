<?php

/**
 * Partial: AI Forms Settings
 * Renders module-level settings for AI Forms.
 */

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

if (class_exists('\\WPAICG\\AIForms\\Admin\\AIPKit_AI_Form_Settings_Ajax_Handler')) {
    $settings_data = \WPAICG\AIForms\Admin\AIPKit_AI_Form_Settings_Ajax_Handler::get_settings();
} else {
    $settings_data = ['token_management' => [], 'custom_theme' => [], 'frontend_display' => []];
}

use WPAICG\Chat\Storage\BotSettingsManager;

$token_settings = $settings_data['token_management'] ?? [];
$custom_theme_settings = $settings_data['custom_theme'] ?? [];
$custom_css = $custom_theme_settings['custom_css'] ?? '';
$frontend_display_settings = $settings_data['frontend_display'] ?? [];
$allowed_providers_str = $frontend_display_settings['allowed_providers'] ?? '';
$allowed_models_str = $frontend_display_settings['allowed_models'] ?? '';

$default_css_template = "/* --- AIPKit AI Forms Custom CSS Example --- */
.aipkit-ai-form-wrapper.aipkit-theme-custom {
    background-color: #f0f4f8;
    border: 1px solid #d1d9e4;
    color: #2c3e50;
}
.aipkit-ai-form-wrapper.aipkit-theme-custom h5 {
    color: #2c3e50;
    border-bottom: 1px solid #d1d9e4;
}
.aipkit-ai-form-wrapper.aipkit-theme-custom .aipkit_btn-primary {
    background-color: #3498db;
    border-color: #2980b9;
}
.aipkit-ai-form-wrapper.aipkit-theme-custom .aipkit_btn-primary:hover {
    background-color: #2980b9;
}
";

$settings_nonce = wp_create_nonce('aipkit_ai_forms_settings_nonce');

$default_reset_period = BotSettingsManager::DEFAULT_TOKEN_RESET_PERIOD;
$default_limit_message = BotSettingsManager::get_default_token_limit_message();
$default_limit_mode = BotSettingsManager::DEFAULT_TOKEN_LIMIT_MODE;
$default_token_limit_actions = BotSettingsManager::get_default_token_limit_action_settings();
$token_limit_action_options = BotSettingsManager::get_token_limit_action_options();

$guest_limit = $token_settings['token_guest_limit'] ?? null;
$user_limit = $token_settings['token_user_limit'] ?? null;
$reset_period = $token_settings['token_reset_period'] ?? $default_reset_period;
$limit_message = $token_settings['token_limit_message'] ?? $default_limit_message;
$limit_mode = $token_settings['token_limit_mode'] ?? $default_limit_mode;
$role_limits = $token_settings['token_role_limits'] ?? [];
$token_limit_primary_action_type = $token_settings['token_limit_primary_action_type'] ?? $default_token_limit_actions['primary_type'];
$token_limit_primary_action_label = $token_settings['token_limit_primary_action_label'] ?? $default_token_limit_actions['primary_label'];
$token_limit_primary_action_url = $token_settings['token_limit_primary_action_url'] ?? $default_token_limit_actions['primary_url'];
$token_limit_secondary_action_type = $token_settings['token_limit_secondary_action_type'] ?? $default_token_limit_actions['secondary_type'];
$token_limit_secondary_action_label = $token_settings['token_limit_secondary_action_label'] ?? $default_token_limit_actions['secondary_label'];
$token_limit_secondary_action_url = $token_settings['token_limit_secondary_action_url'] ?? $default_token_limit_actions['secondary_url'];

$guest_limit_value = ($guest_limit === null) ? '' : (string)$guest_limit;
$user_limit_value = ($user_limit === null) ? '' : (string)$user_limit;
$primary_action_show_label = $token_limit_primary_action_type !== 'none';
$primary_action_show_url = $token_limit_primary_action_type === 'custom_url';
$secondary_action_show_label = $token_limit_secondary_action_type !== 'none';
$secondary_action_show_url = $token_limit_secondary_action_type === 'custom_url';
?>
<form id="aipkit_ai_forms_settings_form" class="aipkit_ai_forms_settings_form">
    <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($settings_nonce); ?>">
    <div class="aipkit_ai_forms_settings_page" data-aipkit-settings-module-tab-scope="ai-forms">
        <div class="aipkit_ai_forms_settings_tabs" role="tablist" aria-label="<?php esc_attr_e('AI Forms settings', 'gpt3-ai-content-generator'); ?>" data-aipkit-settings-module-tabs="ai-forms">
            <button
                type="button"
                class="aipkit_ai_forms_settings_tab aipkit_active"
                id="aipkit_ai_forms_settings_section_tab_limits"
                role="tab"
                aria-selected="true"
                aria-controls="aipkit_ai_forms_settings_section_limits"
                data-aipkit-settings-module-tab="limits"
            >
                <span class="dashicons dashicons-chart-pie" aria-hidden="true"></span>
                <span><?php esc_html_e('Limits', 'gpt3-ai-content-generator'); ?></span>
            </button>
            <button
                type="button"
                class="aipkit_ai_forms_settings_tab"
                id="aipkit_ai_forms_settings_section_tab_custom_css"
                role="tab"
                aria-selected="false"
                aria-controls="aipkit_ai_forms_settings_section_custom_css"
                data-aipkit-settings-module-tab="custom-css"
                tabindex="-1"
            >
                <span class="dashicons dashicons-editor-code" aria-hidden="true"></span>
                <span><?php esc_html_e('Custom CSS', 'gpt3-ai-content-generator'); ?></span>
            </button>
            <button
                type="button"
                class="aipkit_ai_forms_settings_tab"
                id="aipkit_ai_forms_settings_section_tab_frontend_models"
                role="tab"
                aria-selected="false"
                aria-controls="aipkit_ai_forms_settings_section_frontend_models"
                data-aipkit-settings-module-tab="frontend-models"
                tabindex="-1"
            >
                <span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
                <span><?php esc_html_e('Frontend Models', 'gpt3-ai-content-generator'); ?></span>
            </button>
        </div>
        <div id="aipkit_ai_forms_settings_content" class="aipkit_ai_forms_settings_content">
        <?php
        $aipkit_settings_section_variant = 'ai-forms-modern';
        $aipkit_token_limits_section_id_prefix = 'aipkit_ai_forms_settings_section';
        $aipkit_token_limits_field_id_prefix = 'aipkit_aiforms_token';
        $aipkit_token_limits_field_name_prefix = 'aiforms_token';
        include WPAICG_PLUGIN_DIR . 'admin/views/shared/token-limits-settings-section.php';
        ?>

        <?php
        $aipkit_custom_css_section_id_prefix = 'aipkit_ai_forms_settings_section';
        $aipkit_custom_css_field_id = 'aipkit_aiforms_custom_css';
        $aipkit_custom_css_header_helper = __('Theme overrides for Custom form theme.', 'gpt3-ai-content-generator');
        $aipkit_custom_css_label_helper = __('Applies to forms using the Custom theme.', 'gpt3-ai-content-generator');
        include WPAICG_PLUGIN_DIR . 'admin/views/shared/custom-css-settings-section.php';
        ?>

        <?php
        $aipkit_frontend_models_section_id_prefix = 'aipkit_ai_forms_settings_section';
        $aipkit_frontend_models_textarea_id = 'aipkit_aiforms_frontend_models';
        $aipkit_frontend_models_providers_textarea_id = 'aipkit_aiforms_frontend_providers';
        $aipkit_frontend_models_selector_id = 'aipkit_ai_forms_models_selector';
        unset($aipkit_frontend_models_empty_all_selected);
        include WPAICG_PLUGIN_DIR . 'admin/views/shared/frontend-models-settings-section.php';
        unset($aipkit_settings_section_variant);
        ?>
        </div>
    </div>
</form>
