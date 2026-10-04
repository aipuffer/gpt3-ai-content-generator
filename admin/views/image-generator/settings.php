<?php

/**
 * Partial: Image Generator Settings
 * Renders module-level settings for the Image Generator.
 */

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

use WPAICG\Chat\Storage\BotSettingsManager;
use WPAICG\Images\AIPKit_Image_Settings_Ajax_Handler;

$settings_data = AIPKit_Image_Settings_Ajax_Handler::get_settings();
$settings_nonce = wp_create_nonce('aipkit_image_generator_settings_nonce');

$token_settings = $settings_data['token_management'] ?? [];
$custom_css = $settings_data['common']['custom_css'] ?? '';
$frontend_display_settings = $settings_data['frontend_display'] ?? [];
$allowed_models_str = $frontend_display_settings['allowed_models'] ?? '';
$ui_text_settings = $settings_data['ui_text'] ?? [];
$ui_text_defaults = AIPKit_Image_Settings_Ajax_Handler::get_default_ui_text_settings();

$default_css_template = "/* --- AIPKit Image Generator Custom CSS Example --- */
.aipkit_image_generator_public_wrapper.aipkit-theme-custom {
    --aipkit-image-surface: #ffffff;
    --aipkit-image-surface-subtle: #f5f7fa;
    --aipkit-image-surface-raised: #ffffff;
    --aipkit-image-border: #d7dee8;
    --aipkit-image-border-strong: #bdc8d6;
    --aipkit-image-text: #253246;
    --aipkit-image-muted: #66758a;
    --aipkit-image-faint: #91a0b4;
    --aipkit-image-accent: #356ae6;
    --aipkit-image-accent-hover: #2858ca;
}
";

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
$token_limit_primary_action_type = $token_settings['token_limit_primary_action_type'] ?? $default_token_limit_actions['primary_type'];
$token_limit_primary_action_label = $token_settings['token_limit_primary_action_label'] ?? $default_token_limit_actions['primary_label'];
$token_limit_primary_action_url = $token_settings['token_limit_primary_action_url'] ?? $default_token_limit_actions['primary_url'];
$token_limit_secondary_action_type = $token_settings['token_limit_secondary_action_type'] ?? $default_token_limit_actions['secondary_type'];
$token_limit_secondary_action_label = $token_settings['token_limit_secondary_action_label'] ?? $default_token_limit_actions['secondary_label'];
$token_limit_secondary_action_url = $token_settings['token_limit_secondary_action_url'] ?? $default_token_limit_actions['secondary_url'];

$role_limits_raw = $token_settings['token_role_limits'] ?? [];
$role_limits = is_string($role_limits_raw) ? json_decode($role_limits_raw, true) : $role_limits_raw;
if (!is_array($role_limits)) {
    $role_limits = [];
}

$guest_limit_value = ($guest_limit === null) ? '' : (string) $guest_limit;
$user_limit_value = ($user_limit === null) ? '' : (string) $user_limit;
$primary_action_show_label = $token_limit_primary_action_type !== 'none';
$primary_action_show_url = $token_limit_primary_action_type === 'custom_url';
$secondary_action_show_label = $token_limit_secondary_action_type !== 'none';
$secondary_action_show_url = $token_limit_secondary_action_type === 'custom_url';

$get_ui_text_value = static function (string $key) use ($ui_text_settings, $ui_text_defaults): string {
    $value = isset($ui_text_settings[$key]) ? (string) $ui_text_settings[$key] : '';
    if ($value === '' && isset($ui_text_defaults[$key])) {
        return (string) $ui_text_defaults[$key];
    }
    return $value;
};

$ui_text_fields = [
    [
        'id' => 'aipkit_image_ui_text_generate_label',
        'name' => 'ui_text_generate_label',
        'key' => 'generate_label',
        'label' => __('Generate action', 'gpt3-ai-content-generator'),
        'helper' => __('Accessible label for the send button.', 'gpt3-ai-content-generator'),
    ],
    [
        'id' => 'aipkit_image_ui_text_edit_label',
        'name' => 'ui_text_edit_label',
        'key' => 'edit_label',
        'label' => __('Edit action', 'gpt3-ai-content-generator'),
        'helper' => __('Accessible label for the send button with an attachment.', 'gpt3-ai-content-generator'),
    ],
    [
        'id' => 'aipkit_image_ui_text_generate_placeholder',
        'name' => 'ui_text_generate_placeholder',
        'key' => 'generate_placeholder',
        'label' => __('Generate placeholder', 'gpt3-ai-content-generator'),
        'helper' => __('Prompt field hint.', 'gpt3-ai-content-generator'),
    ],
    [
        'id' => 'aipkit_image_ui_text_edit_placeholder',
        'name' => 'ui_text_edit_placeholder',
        'key' => 'edit_placeholder',
        'label' => __('Edit placeholder', 'gpt3-ai-content-generator'),
        'helper' => __('Edit prompt hint.', 'gpt3-ai-content-generator'),
    ],
    [
        'id' => 'aipkit_image_ui_text_history_title',
        'name' => 'ui_text_history_title',
        'key' => 'history_title',
        'label' => __('History title', 'gpt3-ai-content-generator'),
        'helper' => __('User history heading.', 'gpt3-ai-content-generator'),
    ],
];
?>
<form id="aipkit_image_generator_settings_form" class="aipkit_ai_forms_settings_form aipkit_image_generator_settings_form" onsubmit="return false;">
    <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($settings_nonce); ?>">
    <div class="aipkit_ai_forms_settings_page" data-aipkit-settings-module-tab-scope="image-generator">
        <div class="aipkit_image_generator_settings_tabs" role="tablist" aria-label="<?php esc_attr_e('Image Generator settings', 'gpt3-ai-content-generator'); ?>" data-aipkit-settings-module-tabs="image-generator">
            <button
                type="button"
                class="aipkit_image_generator_settings_tab aipkit_active"
                id="aipkit_image_generator_settings_section_tab_shortcode"
                role="tab"
                aria-selected="true"
                aria-controls="aipkit_image_generator_settings_section_shortcode"
                data-aipkit-settings-module-tab="shortcode"
            >
                <span class="dashicons dashicons-shortcode" aria-hidden="true"></span>
                <span><?php esc_html_e('Shortcode', 'gpt3-ai-content-generator'); ?></span>
            </button>
            <button
                type="button"
                class="aipkit_image_generator_settings_tab"
                id="aipkit_image_generator_settings_section_tab_limits"
                role="tab"
                aria-selected="false"
                aria-controls="aipkit_image_generator_settings_section_limits"
                data-aipkit-settings-module-tab="limits"
                tabindex="-1"
            >
                <span class="dashicons dashicons-chart-pie" aria-hidden="true"></span>
                <span><?php esc_html_e('Limits', 'gpt3-ai-content-generator'); ?></span>
            </button>
            <button
                type="button"
                class="aipkit_image_generator_settings_tab"
                id="aipkit_image_generator_settings_section_tab_ui_text"
                role="tab"
                aria-selected="false"
                aria-controls="aipkit_image_generator_settings_section_ui_text"
                data-aipkit-settings-module-tab="ui-text"
                tabindex="-1"
            >
                <span class="dashicons dashicons-editor-textcolor" aria-hidden="true"></span>
                <span><?php esc_html_e('UI Text', 'gpt3-ai-content-generator'); ?></span>
            </button>
            <button
                type="button"
                class="aipkit_image_generator_settings_tab"
                id="aipkit_image_generator_settings_section_tab_custom_css"
                role="tab"
                aria-selected="false"
                aria-controls="aipkit_image_generator_settings_section_custom_css"
                data-aipkit-settings-module-tab="custom-css"
                tabindex="-1"
            >
                <span class="dashicons dashicons-editor-code" aria-hidden="true"></span>
                <span><?php esc_html_e('Custom CSS', 'gpt3-ai-content-generator'); ?></span>
            </button>
            <button
                type="button"
                class="aipkit_image_generator_settings_tab"
                id="aipkit_image_generator_settings_section_tab_frontend_models"
                role="tab"
                aria-selected="false"
                aria-controls="aipkit_image_generator_settings_section_frontend_models"
                data-aipkit-settings-module-tab="frontend-models"
                tabindex="-1"
            >
                <span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
                <span><?php esc_html_e('Frontend Models', 'gpt3-ai-content-generator'); ?></span>
            </button>
        </div>
        <div id="aipkit_image_generator_settings_content" class="aipkit_image_generator_settings_content">
        <?php
        $aipkit_settings_section_variant = 'ai-forms-modern';
        $aipkit_token_limits_section_id_prefix = 'aipkit_image_generator_settings_section';
        $aipkit_token_limits_field_id_prefix = 'aipkit_image_token';
        $aipkit_token_limits_field_name_prefix = 'image_token';
        $aipkit_settings_section_initially_hidden = true;
        include WPAICG_PLUGIN_DIR . 'admin/views/shared/token-limits-settings-section.php';
        unset($aipkit_settings_section_initially_hidden);
        ?>

        <section
            class="aipkit_ai_forms_settings_surface aipkit_ai_forms_settings_tab_panel"
            id="aipkit_image_generator_settings_section_ui_text"
            role="tabpanel"
            aria-labelledby="aipkit_image_generator_settings_section_tab_ui_text"
            data-aipkit-settings-module-tab-panel="ui-text"
            hidden
        >
            <div class="aipkit_ai_forms_settings_surface_header">
                <div>
                    <h3 class="aipkit_ai_forms_settings_surface_title"><?php esc_html_e('UI text', 'gpt3-ai-content-generator'); ?></h3>
                    <p class="aipkit_ai_forms_settings_surface_helper"><?php esc_html_e('Frontend labels and placeholders.', 'gpt3-ai-content-generator'); ?></p>
                </div>
            </div>
            <div class="aipkit_ai_forms_settings_surface_body">
                <?php foreach ($ui_text_fields as $field) : ?>
                    <div class="aipkit_ai_forms_settings_row">
                        <label class="aipkit_form-label" for="<?php echo esc_attr($field['id']); ?>">
                            <?php echo esc_html($field['label']); ?>
                            <span class="aipkit_form-label-helper"><?php echo esc_html($field['helper']); ?></span>
                        </label>
                        <input
                            type="text"
                            id="<?php echo esc_attr($field['id']); ?>"
                            name="<?php echo esc_attr($field['name']); ?>"
                            class="aipkit_form-input aipkit_ai_forms_settings_control aipkit_ai_forms_settings_control--wide aipkit_autosave_trigger"
                            value="<?php echo esc_attr($get_ui_text_value($field['key'])); ?>"
                            placeholder="<?php echo esc_attr($ui_text_defaults[$field['key']] ?? ''); ?>"
                        />
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <?php
        $aipkit_custom_css_section_id_prefix = 'aipkit_image_generator_settings_section';
        $aipkit_custom_css_field_id = 'aipkit_image_generator_custom_css';
        $aipkit_custom_css_header_helper = __('Theme overrides for Custom image theme.', 'gpt3-ai-content-generator');
        $aipkit_custom_css_label_helper = __('Applies to the Custom theme.', 'gpt3-ai-content-generator');
        include WPAICG_PLUGIN_DIR . 'admin/views/shared/custom-css-settings-section.php';
        ?>

        <?php
        $aipkit_frontend_models_section_id_prefix = 'aipkit_image_generator_settings_section';
        $aipkit_frontend_models_textarea_id = 'aipkit_image_gen_frontend_models';
        $aipkit_frontend_models_providers_textarea_id = '';
        $aipkit_frontend_models_selector_id = 'aipkit_image_gen_models_selector';
        $aipkit_frontend_models_empty_all_selected = $allowed_models_str === '';
        include WPAICG_PLUGIN_DIR . 'admin/views/shared/frontend-models-settings-section.php';
        unset($aipkit_settings_section_variant);
        ?>

        <section
            class="aipkit_ai_forms_settings_surface aipkit_ai_forms_settings_tab_panel"
            id="aipkit_image_generator_settings_section_shortcode"
            role="tabpanel"
            aria-labelledby="aipkit_image_generator_settings_section_tab_shortcode"
            data-aipkit-settings-module-tab-panel="shortcode"
        >
            <div class="aipkit_ai_forms_settings_surface_header">
                <div>
                    <h3 class="aipkit_ai_forms_settings_surface_title"><?php esc_html_e('Shortcode', 'gpt3-ai-content-generator'); ?></h3>
                    <p class="aipkit_ai_forms_settings_surface_helper"><?php esc_html_e('Only this copy changes. Saved Image Generator settings stay unchanged.', 'gpt3-ai-content-generator'); ?></p>
                </div>
            </div>
            <div class="aipkit_image_generator_shortcode_configurator" id="aipkit_image_generator_shortcode_configurator">
                <div class="aipkit_image_generator_shortcode_preview_block" data-aipkit-shortcode-preview-block>
                    <div class="aipkit_image_generator_shortcode_live_label">
                        <span class="aipkit_image_generator_shortcode_live_dot" aria-hidden="true"></span>
                        <span><?php esc_html_e('Updates live', 'gpt3-ai-content-generator'); ?></span>
                    </div>
                    <div class="aipkit_image_generator_shortcode_preview_row">
                        <output class="aipkit_image_generator_shortcode_preview" data-shortcode=""></output>
                        <button
                            type="button"
                            class="aipkit_image_generator_shortcode_variant_copy"
                            data-shortcode=""
                            data-copied-label="<?php esc_attr_e('Copied', 'gpt3-ai-content-generator'); ?>"
                            aria-label="<?php esc_attr_e('Copy shortcode', 'gpt3-ai-content-generator'); ?>"
                        >
                            <span class="aipkit_image_generator_shortcode_copy_default">
                                <span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
                                <span class="aipkit_image_generator_shortcode_copy_text"><?php esc_html_e('Copy shortcode', 'gpt3-ai-content-generator'); ?></span>
                            </span>
                            <span class="aipkit_image_generator_shortcode_copy_success">
                                <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                                <span class="aipkit_image_generator_shortcode_copy_text"><?php esc_html_e('Copied', 'gpt3-ai-content-generator'); ?></span>
                            </span>
                            <span class="screen-reader-text" data-aipkit-shortcode-copy-live aria-live="polite"></span>
                        </button>
                    </div>
                    <span class="screen-reader-text" data-aipkit-shortcode-announcer aria-live="polite" aria-atomic="true"></span>
                </div>

                <div class="aipkit_ai_forms_settings_surface_body aipkit_image_generator_shortcode_options">
                    <div class="aipkit_ai_forms_settings_row aipkit_image_generator_shortcode_option">
                        <span class="aipkit_form-label">
                            <?php esc_html_e('Allow visitor model selection', 'gpt3-ai-content-generator'); ?>
                            <span class="aipkit_form-label-helper"><?php esc_html_e('Uses models enabled under Frontend Models.', 'gpt3-ai-content-generator'); ?></span>
                        </span>
                        <label class="aipkit_switch">
                            <input type="checkbox" data-aipkit-shortcode-option="allow-model-selection" class="aipkit_toggle_switch aipkit_image_generator_shortcode_input" value="1" aria-label="<?php esc_attr_e('Allow visitor model selection', 'gpt3-ai-content-generator'); ?>" checked>
                            <span class="aipkit_switch_slider"></span>
                        </label>
                    </div>
                    <div class="aipkit_ai_forms_settings_row aipkit_image_generator_shortcode_option">
                        <span class="aipkit_form-label">
                            <?php esc_html_e('Show user history', 'gpt3-ai-content-generator'); ?>
                            <span class="aipkit_form-label-helper"><?php esc_html_e('Displays saved generations for logged-in users.', 'gpt3-ai-content-generator'); ?></span>
                        </span>
                        <label class="aipkit_switch">
                            <input type="checkbox" data-aipkit-shortcode-option="show-history" class="aipkit_toggle_switch aipkit_image_generator_shortcode_input" value="1" aria-label="<?php esc_attr_e('Show user history', 'gpt3-ai-content-generator'); ?>">
                            <span class="aipkit_switch_slider"></span>
                        </label>
                    </div>
                    <div class="aipkit_ai_forms_settings_row aipkit_image_generator_shortcode_option">
                        <label class="aipkit_form-label" for="aipkit_image_generator_shortcode_mode">
                            <?php esc_html_e('Available actions', 'gpt3-ai-content-generator'); ?>
                            <span class="aipkit_form-label-helper"><?php esc_html_e('Choose what visitors can do.', 'gpt3-ai-content-generator'); ?></span>
                        </label>
                        <select id="aipkit_image_generator_shortcode_mode" data-aipkit-shortcode-option="mode" class="aipkit_form-input aipkit_ai_forms_settings_control aipkit_image_generator_shortcode_input">
                            <option value="generate"><?php esc_html_e('Generate images', 'gpt3-ai-content-generator'); ?></option>
                            <option value="edit"><?php esc_html_e('Edit images', 'gpt3-ai-content-generator'); ?></option>
                            <option value="both" selected><?php esc_html_e('Generate and edit', 'gpt3-ai-content-generator'); ?></option>
                        </select>
                    </div>
                    <div class="aipkit_ai_forms_settings_row aipkit_image_generator_shortcode_option">
                        <label class="aipkit_form-label" for="aipkit_image_generator_shortcode_theme"><?php esc_html_e('Theme', 'gpt3-ai-content-generator'); ?></label>
                        <select id="aipkit_image_generator_shortcode_theme" data-aipkit-shortcode-option="theme" class="aipkit_form-input aipkit_ai_forms_settings_control aipkit_image_generator_shortcode_input">
                            <option value="light" selected><?php esc_html_e('Light', 'gpt3-ai-content-generator'); ?></option>
                            <option value="dark"><?php esc_html_e('Dark', 'gpt3-ai-content-generator'); ?></option>
                            <option value="custom"><?php esc_html_e('Custom', 'gpt3-ai-content-generator'); ?></option>
                        </select>
                    </div>
                    <div class="aipkit_ai_forms_settings_row aipkit_image_generator_shortcode_option">
                        <label class="aipkit_form-label" for="aipkit_image_generator_shortcode_font"><?php esc_html_e('Font', 'gpt3-ai-content-generator'); ?></label>
                        <select id="aipkit_image_generator_shortcode_font" data-aipkit-shortcode-option="font" class="aipkit_form-input aipkit_ai_forms_settings_control aipkit_image_generator_shortcode_input">
                            <option value="system" selected><?php esc_html_e('System UI', 'gpt3-ai-content-generator'); ?></option>
                            <option value="theme"><?php esc_html_e('Match site theme', 'gpt3-ai-content-generator'); ?></option>
                        </select>
                    </div>
                </div>

                <div class="aipkit_image_generator_shortcode_footer">
                    <button type="button" class="aipkit_image_generator_shortcode_reset"><?php esc_html_e('Reset to defaults', 'gpt3-ai-content-generator'); ?></button>
                </div>
            </div>
        </section>
        </div>
    </div>
</form>
