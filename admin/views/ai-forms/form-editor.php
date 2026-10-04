<?php

/**
 * Partial: AI Form Editor (Main Orchestrator)
 * UI for creating or editing an AI Form. Implements a modern 3-column layout for an improved user experience.
 */

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

use WPAICG\aipkit_dashboard;
use WPAICG\AIPKIT_AI_Settings;

// --- Get available providers (always show, lock via disabled when not eligible) ---
$providers = \WPAICG\AIPKit_Providers::get_text_generation_providers(true, true, true);
$aipkit_paid_editor_view = WPAICG_PLUGIN_DIR . 'lib/views/ai-forms/editor.php';
$is_pro = class_exists('\\WPAICG\\aipkit_dashboard') && aipkit_dashboard::is_pro_plan() && file_exists($aipkit_paid_editor_view);
if ($is_pro) {
    require_once $aipkit_paid_editor_view;
}
// --- Get global AI param defaults ---
$global_ai_params = [];
if (class_exists('\\WPAICG\\AIPKIT_AI_Settings')) {
    $global_ai_params = AIPKIT_AI_Settings::get_ai_parameters();
}
$default_temp = $global_ai_params['temperature'] ?? 1.0;
$default_max_tokens = $global_ai_params['max_completion_tokens'] ?? 4000;
$default_top_p = $global_ai_params['top_p'] ?? 1.0;
$default_frequency_penalty = $global_ai_params['frequency_penalty'] ?? 0.0;
$default_presence_penalty = $global_ai_params['presence_penalty'] ?? 0.0;
$upgrade_url = admin_url('admin.php?page=wpaicg-pricing');
$connected_apps_manage_url = admin_url('admin.php?page=wpaicg&aipkit_module=settings&aipkit_settings_page=apps');
$connected_apps_supported_destinations = [
    [
        'name' => 'Slack',
        'logo_url' => WPAICG_PLUGIN_URL . 'admin/images/apps/slack.svg',
    ],
    [
        'name' => 'HubSpot',
        'logo_url' => WPAICG_PLUGIN_URL . 'admin/images/apps/hubspot.svg',
    ],
    [
        'name' => 'Notion',
        'logo_url' => WPAICG_PLUGIN_URL . 'admin/images/apps/notion.svg',
    ],
    [
        'name' => 'Pipedrive',
        'logo_url' => WPAICG_PLUGIN_URL . 'admin/images/apps/pipedrive.svg',
    ],
    [
        'name' => 'Zapier',
        'logo_url' => WPAICG_PLUGIN_URL . 'admin/images/apps/zapier.svg',
    ],
    [
        'name' => 'Make',
        'logo_url' => WPAICG_PLUGIN_URL . 'admin/images/apps/make.svg',
    ],
    [
        'name' => 'n8n',
        'logo_url' => WPAICG_PLUGIN_URL . 'admin/images/apps/n8n.svg',
    ],
];
?>
<div class="aipkit_form_editor">
    <form id="aipkit_ai_form_editor_form" onsubmit="return false;">
        <input type="hidden" id="aipkit_ai_form_id" name="form_id" value="">
        <input type="hidden" id="aipkit_ai_form_template_key" name="template_key" value="">

        <div class="aipkit_form_editor_shell">
            <div class="aipkit_form_editor_shell_header">
                <div class="aipkit_form_editor_shell_identity">
                    <div class="aipkit_ai_forms_header_title_editor" id="aipkit_ai_forms_header_title_editor" style="display: none;">
                        <button
                            type="button"
                            class="aipkit_ai_forms_title_display"
                            id="aipkit_ai_forms_title_display"
                            aria-label="<?php esc_attr_e('Edit form title', 'gpt3-ai-content-generator'); ?>"
                        >
                            <span class="aipkit_ai_forms_title_text" id="aipkit_ai_forms_title_text"><?php esc_html_e('New Form', 'gpt3-ai-content-generator'); ?></span>
                            <span class="dashicons dashicons-edit" aria-hidden="true"></span>
                        </button>
                        <input
                            type="text"
                            id="aipkit_ai_form_title"
                            name="title"
                            class="aipkit_form-input aipkit_ai_forms_title_input"
                            placeholder="<?php esc_attr_e('New Form', 'gpt3-ai-content-generator'); ?>"
                            required
                            style="display: none;"
                        >
                    </div>
                    <span id="aipkit_ai_forms_status" class="aipkit_training_status aipkit_global_status_area aipkit_form_editor_shell_status" aria-live="polite"></span>
                </div>
                <div class="aipkit_form_editor_shell_controls">
                    <div id="aipkit_ai_forms_editor_actions" class="aipkit_form_editor_actions aipkit_form_editor_actions--header aipkit_form_editor_actions--shell" style="display: none;">
                        <button type="button" id="aipkit_generate_ai_form_btn" class="aipkit_btn aipkit_btn-secondary aipkit_form_editor_action_btn aipkit_form_editor_action_btn--generate">
                            <span class="dashicons dashicons-admin-customizer" aria-hidden="true"></span>
                            <span class="aipkit_btn-text"><?php esc_html_e('Generate with AI', 'gpt3-ai-content-generator'); ?></span>
                        </button>
                        <button type="button" id="aipkit_preview_ai_form_btn" class="aipkit_btn aipkit_btn-secondary aipkit_form_editor_action_btn aipkit_form_editor_action_btn--preview" disabled>
                            <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                            <span class="aipkit_btn-text"><?php esc_html_e('Preview', 'gpt3-ai-content-generator'); ?></span>
                            <span class="aipkit_spinner" style="display:none;"></span>
                        </button>
                        <button type="button" id="aipkit_cancel_edit_ai_form_btn" class="aipkit_btn aipkit_btn-secondary aipkit_form_editor_action_btn aipkit_form_editor_action_btn--cancel">
                            <span class="aipkit_btn-text"><?php esc_html_e('Cancel', 'gpt3-ai-content-generator'); ?></span>
                        </button>
                        <button type="button" id="aipkit_save_ai_form_btn" class="aipkit_btn aipkit_btn-primary aipkit_form_editor_action_btn aipkit_form_editor_action_btn--save">
                            <span class="dashicons dashicons-saved" aria-hidden="true"></span>
                            <span class="aipkit_btn-text"><?php esc_html_e('Save', 'gpt3-ai-content-generator'); ?></span>
                            <span class="aipkit_spinner" style="display:none;"></span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="aipkit_form_editor_layout_wrapper">

            <div class="aipkit_form_editor_col_left">
                <div class="aipkit_form_designer_left_controls_wrapper">
                    <div class="aipkit_sub_container" id="aipkit_ai_form_elements_palette">
                        <div class="aipkit_accordion-group">
                            <div class="aipkit_accordion">
                                <div class="aipkit_accordion-header aipkit_active">
                                    <?php esc_html_e('Form Elements', 'gpt3-ai-content-generator'); ?>
                                </div>
                                <div class="aipkit_accordion-content aipkit_active">
                                    <div class="aipkit_form_element_item" data-element-type="text-input" draggable="true">
                                        <span class="dashicons dashicons-edit-large"></span>
                                        <?php esc_html_e('Text Input', 'gpt3-ai-content-generator'); ?>
                                    </div>
                                    <div class="aipkit_form_element_item" data-element-type="textarea" draggable="true">
                                        <span class="dashicons dashicons-text"></span>
                                        <?php esc_html_e('Text Area', 'gpt3-ai-content-generator'); ?>
                                    </div>
                                    <div class="aipkit_form_element_item" data-element-type="select" draggable="true">
                                        <span class="dashicons dashicons-menu-alt"></span>
                                        <?php esc_html_e('Dropdown', 'gpt3-ai-content-generator'); ?>
                                    </div>
                                    <div class="aipkit_form_element_item" data-element-type="checkbox" draggable="true">
                                        <span class="dashicons dashicons-yes"></span>
                                        <?php esc_html_e('Checkbox', 'gpt3-ai-content-generator'); ?>
                                    </div>
                                    <div class="aipkit_form_element_item" data-element-type="radio-button" draggable="true">
                                        <span class="dashicons dashicons-marker"></span>
                                        <?php esc_html_e('Radio Buttons', 'gpt3-ai-content-generator'); ?>
                                    </div>
                                    <?php if ($is_pro): ?>
                                        <?php aipkit_render_paid_ai_form_editor_panel('upload-elements'); ?>
                                    <?php else: ?>
                                        <div class="aipkit_form_element_item aipkit-pro-feature-locked" draggable="false">
                                            <span class="dashicons dashicons-media-default"></span>
                                            <?php esc_html_e('File Upload', 'gpt3-ai-content-generator'); ?>
                                            <a class="aipkit_pro_tag aipkit_pro_badge" href="<?php echo esc_url($upgrade_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Pro', 'gpt3-ai-content-generator'); ?></a>
                                        </div>
                                        <div class="aipkit_form_element_item aipkit-pro-feature-locked" draggable="false">
                                            <span class="dashicons dashicons-format-image"></span>
                                            <?php esc_html_e('Image Upload', 'gpt3-ai-content-generator'); ?>
                                            <a class="aipkit_pro_tag aipkit_pro_badge" href="<?php echo esc_url($upgrade_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Pro', 'gpt3-ai-content-generator'); ?></a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="aipkit_accordion">
                                <div class="aipkit_accordion-header">
                                    <?php esc_html_e('Layouts', 'gpt3-ai-content-generator'); ?>
                                </div>
                                <div class="aipkit_accordion-content">
                                    <div class="aipkit_form_element_item" data-layout-type="1-col" draggable="true">
                                        <span class="dashicons dashicons-align-wide"></span>
                                        <?php esc_html_e('Single Column', 'gpt3-ai-content-generator'); ?>
                                    </div>
                                    <div class="aipkit_form_element_item" data-layout-type="2-col-50-50" draggable="true">
                                        <span class="dashicons dashicons-editor-table"></span>
                                        <?php esc_html_e('2 Columns', 'gpt3-ai-content-generator'); ?>
                                    </div>
                                    <div class="aipkit_form_element_item" data-layout-type="3-col-33-33-33" draggable="true">
                                        <span class="dashicons dashicons-editor-table"></span>
                                        <?php esc_html_e('3 Columns', 'gpt3-ai-content-generator'); ?>
                                    </div>
                                </div>
                            </div>

                            <div class="aipkit_accordion">
                                <div class="aipkit_accordion-header">
                                    <?php esc_html_e('Multi-Step', 'gpt3-ai-content-generator'); ?>
                                    <?php if (!$is_pro): ?>
                                        <a
                                            href="<?php echo esc_url($upgrade_url); ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="aipkit_pro_tag aipkit_pro_badge"
                                            onclick="event.stopPropagation();"
                                        ><?php esc_html_e('Pro', 'gpt3-ai-content-generator'); ?></a>
                                    <?php endif; ?>
                                </div>
                                <div class="aipkit_accordion-content">
                                    <div class="aipkit_popover_options_list aipkit_ai_form_conversation_ui_list">
    <?php if ($is_pro) : ?>
        <?php aipkit_render_paid_ai_form_editor_panel('conversation'); ?>
    <?php else : ?>
        <div class="aipkit_popover_option_row aipkit_ai_form_multistep_upgrade_notice">
            <div class="aipkit_popover_option_main aipkit_popover_option_main--stacked aipkit_ai_form_multistep_upgrade_inner">
                <div class="aipkit_ai_form_multistep_upgrade_copy">
                    <span class="aipkit_popover_option_label"><?php esc_html_e('Turn forms into guided step-by-step flows.', 'gpt3-ai-content-generator'); ?></span>
                    <p class="aipkit_form-help"><?php esc_html_e('Show one step at a time with navigation and conditional branching.', 'gpt3-ai-content-generator'); ?></p>
                </div>
                <a id="aipkit_ai_form_multistep_upgrade_btn" class="aipkit_btn aipkit_ai_form_upgrade_btn aipkit_pro_upgrade_button" href="<?php echo esc_url($upgrade_url); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e('Upgrade', 'gpt3-ai-content-generator'); ?>
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>
                                </div>
                            </div>

                            <?php
                            $aipkit_ai_forms_workflow_renderer_registered = has_action('aipkit_ai_forms_editor_left_accordion_after_multistep');
                            do_action('aipkit_ai_forms_editor_left_accordion_after_multistep', $is_pro, $upgrade_url);
                            ?>
                            <?php if (!$is_pro && false === $aipkit_ai_forms_workflow_renderer_registered): ?>
                                <div class="aipkit_accordion aipkit_ai_forms_workflow_accordion">
                                    <div class="aipkit_accordion-header">
                                        <?php esc_html_e('Workflow', 'gpt3-ai-content-generator'); ?>
                                        <a
                                            href="<?php echo esc_url($upgrade_url); ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="aipkit_pro_tag aipkit_pro_badge"
                                            onclick="event.stopPropagation();"
                                        ><?php esc_html_e('Pro', 'gpt3-ai-content-generator'); ?></a>
                                    </div>
                                    <div class="aipkit_accordion-content">
                                        <div class="aipkit_popover_options_list aipkit_ai_forms_workflow_panel_body">
                                            <div class="aipkit_popover_option_row aipkit_ai_form_multistep_upgrade_notice">
                                                <div class="aipkit_popover_option_main aipkit_popover_option_main--stacked aipkit_ai_form_multistep_upgrade_inner">
                                                    <div class="aipkit_ai_form_multistep_upgrade_copy">
                                                        <span class="aipkit_popover_option_label"><?php esc_html_e('Connect AI Forms into guided workflows.', 'gpt3-ai-content-generator'); ?></span>
                                                        <p class="aipkit_form-help"><?php esc_html_e('Send AI output and submitted answers into the next form, with conditional routes.', 'gpt3-ai-content-generator'); ?></p>
                                                    </div>
                                                    <a class="aipkit_btn aipkit_ai_form_upgrade_btn aipkit_pro_upgrade_button" href="<?php echo esc_url($upgrade_url); ?>" target="_blank" rel="noopener noreferrer">
                                                        <?php esc_html_e('Upgrade', 'gpt3-ai-content-generator'); ?>
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="aipkit_accordion">
                                <div class="aipkit_accordion-header">
                                    <?php esc_html_e('Labels', 'gpt3-ai-content-generator'); ?>
                                </div>
                                <div class="aipkit_accordion-content">
                                    <?php include __DIR__ . '/labels-config.php'; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="aipkit_sub_container" id="aipkit_ai_form_element_settings_panel" style="display:none;">
                        <div class="aipkit_sub_container_header">
                            <h5 class="aipkit_sub_container_title">
                                <?php esc_html_e('Element settings', 'gpt3-ai-content-generator'); ?>
                                <span id="aipkit_settings_panel_element_type"></span>
                            </h5>
                        </div>
                        <div class="aipkit_sub_container_body">
                            <div id="aipkit_settings_panel_fields">
                            </div>
                            <button type="button" id="aipkit_settings_panel_close_btn" class="aipkit_btn aipkit_btn-secondary"><?php esc_html_e('Done', 'gpt3-ai-content-generator'); ?></button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="aipkit_form_editor_col_center">
                <div class="aipkit_ai_form_designer_area" id="aipkit_ai_form_designer_area">
                    <div class="aipkit_form_designer_placeholder" id="aipkit_form_designer_placeholder">
                        <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                        <span class="aipkit_form_designer_placeholder_title"><?php esc_html_e("This form doesn't have any fields yet", 'gpt3-ai-content-generator'); ?></span>
                        <span class="aipkit_form_designer_placeholder_hint"><?php esc_html_e('Drag a field from the left into this area to add it to your form.', 'gpt3-ai-content-generator'); ?></span>
                    </div>
                </div>
            </div>

            <div class="aipkit_form_editor_col_right">
                <?php include __DIR__ . '/editor-settings.php'; ?>
            </div>

            </div>
        </div>

        <div
            class="aipkit-modal-overlay aipkit_ai_form_knowledge_base_settings_modal"
            id="aipkit_ai_form_knowledge_base_settings_modal"
            aria-hidden="true"
        >
            <div
                class="aipkit-modal-content aipkit-modal-shell aipkit_ai_form_modal_content aipkit_ai_form_knowledge_base_settings_modal_content"
                role="dialog"
                aria-modal="true"
                aria-labelledby="aipkit_ai_form_knowledge_base_settings_modal_title"
                aria-describedby="aipkit_ai_form_knowledge_base_settings_modal_description"
            >
                <div class="aipkit-modal-header aipkit-modal-shell-header aipkit_ai_form_modal_header">
                    <div class="aipkit-modal-shell-intro">
                        <h2 class="aipkit-modal-shell-title" id="aipkit_ai_form_knowledge_base_settings_modal_title">
                            <?php esc_html_e('Knowledge base settings', 'gpt3-ai-content-generator'); ?>
                        </h2>
                        <p class="aipkit-modal-shell-copy" id="aipkit_ai_form_knowledge_base_settings_modal_description">
                            <?php esc_html_e('Configure the source and retrieval limits used for this form.', 'gpt3-ai-content-generator'); ?>
                        </p>
                    </div>
                    <button
                        type="button"
                        class="aipkit-modal-close-btn aipkit-modal-shell-close aipkit_ai_form_knowledge_base_settings_cancel"
                        aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"
                    >
                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    </button>
                </div>
                <div class="aipkit-modal-body aipkit-modal-shell-body aipkit_ai_form_modal_body aipkit_ai_form_knowledge_base_settings_modal_body">
                    <div data-aipkit-settings-panel="context">
                        <?php include __DIR__ . '/vector-config.php'; ?>
                    </div>
                </div>
                <div class="aipkit-modal-footer aipkit_ai_form_modal_footer">
                    <div class="aipkit_ai_form_modal_footer_actions">
                        <button type="button" id="aipkit_ai_form_knowledge_base_settings_cancel_btn" class="aipkit_btn aipkit_btn-secondary aipkit_ai_form_knowledge_base_settings_cancel">
                            <?php esc_html_e('Cancel', 'gpt3-ai-content-generator'); ?>
                        </button>
                        <button type="button" id="aipkit_ai_form_knowledge_base_settings_save_btn" class="aipkit_btn aipkit_btn-primary">
                            <?php esc_html_e('Save', 'gpt3-ai-content-generator'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div
            class="aipkit-modal-overlay aipkit_ai_form_web_search_settings_modal"
            id="aipkit_ai_form_web_search_settings_modal"
            aria-hidden="true"
        >
            <div
                class="aipkit-modal-content aipkit-modal-shell aipkit_ai_form_modal_content aipkit_ai_form_web_search_settings_modal_content"
                role="dialog"
                aria-modal="true"
                aria-labelledby="aipkit_ai_form_web_search_settings_modal_title"
                aria-describedby="aipkit_ai_form_web_search_settings_modal_description"
            >
                <div class="aipkit-modal-header aipkit-modal-shell-header aipkit_ai_form_modal_header">
                    <div class="aipkit-modal-shell-intro">
                        <h2 class="aipkit-modal-shell-title" id="aipkit_ai_form_web_search_settings_modal_title">
                            <?php esc_html_e('Web search settings', 'gpt3-ai-content-generator'); ?>
                        </h2>
                        <p
                            class="aipkit-modal-shell-copy"
                            id="aipkit_ai_form_web_search_settings_modal_description"
                            data-editable-copy="<?php esc_attr_e('Configure provider-specific search options for this form.', 'gpt3-ai-content-generator'); ?>"
                            data-readonly-copy="<?php esc_attr_e('Review how web search works for this provider.', 'gpt3-ai-content-generator'); ?>"
                        >
                            <?php esc_html_e('Configure provider-specific search options for this form.', 'gpt3-ai-content-generator'); ?>
                        </p>
                    </div>
                    <button
                        type="button"
                        class="aipkit-modal-close-btn aipkit-modal-shell-close aipkit_ai_form_web_search_settings_cancel"
                        aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"
                    >
                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    </button>
                </div>
                <div class="aipkit-modal-body aipkit-modal-shell-body aipkit_ai_form_modal_body aipkit_ai_form_web_search_settings_modal_body">
                    <div data-aipkit-settings-panel="tools">
                        <?php include __DIR__ . '/tools-config.php'; ?>
                    </div>
                </div>
                <div class="aipkit-modal-footer aipkit_ai_form_modal_footer">
                    <div class="aipkit_ai_form_modal_footer_actions">
                        <button
                            type="button"
                            id="aipkit_ai_form_web_search_settings_cancel_btn"
                            class="aipkit_btn aipkit_btn-secondary aipkit_ai_form_web_search_settings_cancel"
                            data-cancel-label="<?php esc_attr_e('Cancel', 'gpt3-ai-content-generator'); ?>"
                            data-close-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"
                        >
                            <?php esc_html_e('Cancel', 'gpt3-ai-content-generator'); ?>
                        </button>
                        <button type="button" id="aipkit_ai_form_web_search_settings_save_btn" class="aipkit_btn aipkit_btn-primary">
                            <?php esc_html_e('Save', 'gpt3-ai-content-generator'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

    </form>

    <div
        class="aipkit-modal-overlay aipkit_ai_form_model_settings_modal"
        id="aipkit_ai_form_model_settings_modal"
        aria-hidden="true"
    >
        <div
            class="aipkit-modal-content aipkit-modal-shell aipkit_ai_form_modal_content aipkit_ai_form_model_settings_modal_content"
            role="dialog"
            aria-modal="true"
            aria-labelledby="aipkit_ai_form_model_settings_modal_title"
        >
            <div class="aipkit-modal-header aipkit-modal-shell-header aipkit_ai_form_modal_header">
                <div class="aipkit-modal-shell-intro">
                    <h2 class="aipkit-modal-shell-title" id="aipkit_ai_form_model_settings_modal_title">
                        <?php esc_html_e('Model settings', 'gpt3-ai-content-generator'); ?>
                    </h2>
                </div>
                <button
                    type="button"
                    class="aipkit-modal-close-btn aipkit-modal-shell-close aipkit_ai_form_model_settings_cancel"
                    aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"
                >
                    <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                </button>
            </div>
            <div class="aipkit-modal-body aipkit-modal-shell-body aipkit_ai_form_modal_body aipkit_ai_form_model_settings_modal_body">
                <div class="aipkit_ai_form_model_settings_list">
                    <div class="aipkit_ai_form_model_settings_row">
                        <div class="aipkit_ai_form_model_settings_copy">
                            <label class="aipkit_ai_form_model_settings_label" for="aipkit_ai_form_temperature"><?php esc_html_e('Temperature', 'gpt3-ai-content-generator'); ?></label>
                            <span class="aipkit_ai_form_model_settings_helper"><?php esc_html_e('More varied output.', 'gpt3-ai-content-generator'); ?></span>
                        </div>
                        <input type="number" id="aipkit_ai_form_temperature" name="temperature" form="aipkit_ai_form_editor_form" class="aipkit_form-input aipkit_ai_form_model_settings_control aipkit_ai_form_model_number" min="0" max="2" step="0.1" value="<?php echo esc_attr($default_temp); ?>" inputmode="decimal" />
                    </div>
                    <div class="aipkit_ai_form_model_settings_row">
                        <div class="aipkit_ai_form_model_settings_copy">
                            <label class="aipkit_ai_form_model_settings_label" for="aipkit_ai_form_max_tokens"><?php esc_html_e('Max tokens', 'gpt3-ai-content-generator'); ?></label>
                            <span class="aipkit_ai_form_model_settings_helper"><?php esc_html_e('Response length limit.', 'gpt3-ai-content-generator'); ?></span>
                        </div>
                        <input type="number" id="aipkit_ai_form_max_tokens" name="max_tokens" form="aipkit_ai_form_editor_form" class="aipkit_form-input aipkit_ai_form_model_settings_control aipkit_ai_form_model_number" min="1" max="128000" step="1" value="<?php echo esc_attr($default_max_tokens); ?>" inputmode="numeric" />
                    </div>
                    <div class="aipkit_ai_form_model_settings_row">
                        <div class="aipkit_ai_form_model_settings_copy">
                            <label class="aipkit_ai_form_model_settings_label" for="aipkit_ai_form_top_p"><?php esc_html_e('Top P', 'gpt3-ai-content-generator'); ?></label>
                            <span class="aipkit_ai_form_model_settings_helper"><?php esc_html_e('Sampling diversity.', 'gpt3-ai-content-generator'); ?></span>
                        </div>
                        <input type="number" id="aipkit_ai_form_top_p" name="top_p" form="aipkit_ai_form_editor_form" class="aipkit_form-input aipkit_ai_form_model_settings_control aipkit_ai_form_model_number" min="0" max="1" step="0.01" value="<?php echo esc_attr($default_top_p); ?>" inputmode="decimal" />
                    </div>
                    <div class="aipkit_ai_form_model_settings_row">
                        <div class="aipkit_ai_form_model_settings_copy">
                            <label class="aipkit_ai_form_model_settings_label" for="aipkit_ai_form_frequency_penalty"><?php esc_html_e('Frequency penalty', 'gpt3-ai-content-generator'); ?></label>
                            <span class="aipkit_ai_form_model_settings_helper"><?php esc_html_e('Reduce repeated wording.', 'gpt3-ai-content-generator'); ?></span>
                        </div>
                        <input type="number" id="aipkit_ai_form_frequency_penalty" name="frequency_penalty" form="aipkit_ai_form_editor_form" class="aipkit_form-input aipkit_ai_form_model_settings_control aipkit_ai_form_model_number" min="0" max="2" step="0.1" value="<?php echo esc_attr($default_frequency_penalty); ?>" inputmode="decimal" />
                    </div>
                    <div class="aipkit_ai_form_model_settings_row">
                        <div class="aipkit_ai_form_model_settings_copy">
                            <label class="aipkit_ai_form_model_settings_label" for="aipkit_ai_form_presence_penalty"><?php esc_html_e('Presence penalty', 'gpt3-ai-content-generator'); ?></label>
                            <span class="aipkit_ai_form_model_settings_helper"><?php esc_html_e('Encourage new topics.', 'gpt3-ai-content-generator'); ?></span>
                        </div>
                        <input type="number" id="aipkit_ai_form_presence_penalty" name="presence_penalty" form="aipkit_ai_form_editor_form" class="aipkit_form-input aipkit_ai_form_model_settings_control aipkit_ai_form_model_number" min="0" max="2" step="0.1" value="<?php echo esc_attr($default_presence_penalty); ?>" inputmode="decimal" />
                    </div>
                    <div class="aipkit_ai_form_model_settings_row aipkit_ai_form_reasoning_effort_field" hidden>
                        <div class="aipkit_ai_form_model_settings_copy">
                            <label class="aipkit_ai_form_model_settings_label" for="aipkit_ai_form_reasoning_effort"><?php esc_html_e('Reasoning', 'gpt3-ai-content-generator'); ?></label>
                            <span class="aipkit_ai_form_model_settings_helper"><?php esc_html_e('More effort for hard tasks.', 'gpt3-ai-content-generator'); ?></span>
                        </div>
                        <select id="aipkit_ai_form_reasoning_effort" name="reasoning_effort" form="aipkit_ai_form_editor_form" class="aipkit_form-input aipkit_ai_form_model_settings_control">
                            <option value="none"><?php esc_html_e('None', 'gpt3-ai-content-generator'); ?></option>
                            <option value="low"><?php esc_html_e('Low', 'gpt3-ai-content-generator'); ?></option>
                            <option value="medium"><?php esc_html_e('Medium', 'gpt3-ai-content-generator'); ?></option>
                            <option value="high"><?php esc_html_e('High', 'gpt3-ai-content-generator'); ?></option>
                            <option value="xhigh"><?php esc_html_e('XHigh', 'gpt3-ai-content-generator'); ?></option>
                            <option value="max"><?php esc_html_e('Max', 'gpt3-ai-content-generator'); ?></option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="aipkit-modal-footer aipkit_ai_form_modal_footer">
                <div class="aipkit_ai_form_modal_footer_actions">
                    <button type="button" id="aipkit_ai_form_model_settings_cancel_btn" class="aipkit_btn aipkit_btn-secondary aipkit_ai_form_model_settings_cancel">
                        <?php esc_html_e('Cancel', 'gpt3-ai-content-generator'); ?>
                    </button>
                    <button type="button" id="aipkit_ai_form_model_settings_save_btn" class="aipkit_btn aipkit_btn-primary">
                        <?php esc_html_e('Save', 'gpt3-ai-content-generator'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div
        class="aipkit-modal-overlay aipkit_ai_form_prompt_modal"
        id="aipkit_ai_form_prompt_modal"
        aria-hidden="true"
    >
        <div
            class="aipkit-modal-content aipkit-modal-shell aipkit_ai_form_modal_content aipkit_ai_form_prompt_modal_content"
            role="dialog"
            aria-modal="true"
            aria-labelledby="aipkit_ai_form_prompt_modal_title"
            aria-describedby="aipkit_ai_form_prompt_modal_description"
        >
            <div class="aipkit-modal-header aipkit-modal-shell-header aipkit_ai_form_modal_header">
                <div class="aipkit-modal-shell-intro">
                    <h2 class="aipkit-modal-shell-title" id="aipkit_ai_form_prompt_modal_title">
                        <?php esc_html_e('Prompt Editor', 'gpt3-ai-content-generator'); ?>
                    </h2>
                    <p class="aipkit-modal-shell-copy" id="aipkit_ai_form_prompt_modal_description">
                        <?php esc_html_e('Edit the full prompt in a larger view.', 'gpt3-ai-content-generator'); ?>
                    </p>
                </div>
                <button
                    type="button"
                    class="aipkit-modal-close-btn aipkit-modal-shell-close aipkit_ai_form_prompt_modal_close"
                    aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"
                >
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </div>
            <div class="aipkit-modal-body aipkit-modal-shell-body aipkit_ai_form_modal_body aipkit_ai_form_prompt_modal_body">
                <div class="aipkit_ai_form_modal_field">
                    <textarea
                        id="aipkit_ai_form_prompt_modal_textarea"
                        class="aipkit_builder_textarea aipkit_builder_textarea_large aipkit_ai_form_modal_textarea aipkit_ai_form_prompt_modal_textarea"
                        rows="14"
                        aria-label="<?php esc_attr_e('Prompt', 'gpt3-ai-content-generator'); ?>"
                    ></textarea>
                </div>
            </div>
            <div class="aipkit-modal-footer aipkit_ai_form_modal_footer">
                <span class="aipkit_builder_char_count aipkit_ai_form_prompt_count">
                    <?php esc_html_e('0 characters', 'gpt3-ai-content-generator'); ?>
                </span>
                <div class="aipkit_ai_form_modal_footer_actions">
                    <button type="button" id="aipkit_ai_form_prompt_done_btn" class="aipkit_btn aipkit_btn-primary aipkit_ai_form_prompt_modal_close">
                        <?php esc_html_e('Done', 'gpt3-ai-content-generator'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div
        class="aipkit-modal-overlay aipkit_ai_form_generator_modal"
        id="aipkit_ai_form_generator_modal"
        aria-hidden="true"
    >
        <div
            class="aipkit-modal-content aipkit-modal-shell aipkit-modal-shell--compact aipkit_ai_form_modal_content aipkit_ai_form_generator_modal_content"
            role="dialog"
            aria-modal="true"
            aria-labelledby="aipkit_ai_form_generator_modal_title"
            aria-describedby="aipkit_ai_form_generator_modal_description"
        >
            <div class="aipkit-modal-header aipkit-modal-shell-header aipkit_ai_form_modal_header">
                <div class="aipkit-modal-shell-intro">
                    <h2 class="aipkit-modal-shell-title" id="aipkit_ai_form_generator_modal_title">
                        <?php esc_html_e('Generate Form with AI', 'gpt3-ai-content-generator'); ?>
                    </h2>
                    <p class="aipkit-modal-shell-copy" id="aipkit_ai_form_generator_modal_description">
                        <?php esc_html_e('Describe the form you want to build.', 'gpt3-ai-content-generator'); ?>
                    </p>
                </div>
                <button
                    type="button"
                    class="aipkit-modal-close-btn aipkit-modal-shell-close aipkit_ai_form_generator_modal_close"
                    aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"
                >
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </div>
            <div class="aipkit-modal-body aipkit-modal-shell-body aipkit_ai_form_modal_body aipkit_ai_form_generator_modal_body">
                <div class="aipkit_ai_form_modal_field">
                    <div class="aipkit_ai_form_modal_field_header">
                        <div class="aipkit_ai_form_modal_field_copy">
                            <label class="aipkit_ai_form_modal_label" for="aipkit_ai_form_generator_prompt">
                                <?php esc_html_e('Description', 'gpt3-ai-content-generator'); ?>
                            </label>
                            <span class="aipkit_ai_form_modal_help">
                                <?php esc_html_e('Mention the goal, audience, fields, and options.', 'gpt3-ai-content-generator'); ?>
                            </span>
                        </div>
                        <button
                            type="button"
                            id="aipkit_ai_form_generator_inspire_btn"
                            class="aipkit_btn aipkit_btn-secondary aipkit_ai_form_generator_inspire_btn"
                        >
                            <span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
                            <span><?php esc_html_e('Inspire Me', 'gpt3-ai-content-generator'); ?></span>
                        </button>
                    </div>
                    <div class="aipkit_builder_textarea_wrap aipkit_ai_form_generator_textarea_wrap">
                        <textarea
                            id="aipkit_ai_form_generator_prompt"
                            class="aipkit_builder_textarea aipkit_builder_textarea_large aipkit_ai_form_modal_textarea aipkit_ai_form_generator_prompt"
                            rows="6"
                            placeholder="<?php esc_attr_e('e.g., Create a blog brief generator with fields for topic, audience, tone, keywords, and target length.', 'gpt3-ai-content-generator'); ?>"
                        ></textarea>
                    </div>
                </div>
                <div id="aipkit_ai_form_generator_status" class="aipkit_ai_form_modal_status aipkit_form-help" aria-live="polite"></div>
            </div>
            <div class="aipkit-modal-footer aipkit_ai_form_modal_footer">
                <span class="aipkit_ai_form_modal_footer_note">
                    <?php esc_html_e('Review the draft before saving.', 'gpt3-ai-content-generator'); ?>
                </span>
                <div class="aipkit_ai_form_modal_footer_actions">
                    <button type="button" id="aipkit_ai_form_generator_cancel_btn" class="aipkit_btn aipkit_btn-secondary aipkit_ai_form_generator_modal_close">
                        <?php esc_html_e('Cancel', 'gpt3-ai-content-generator'); ?>
                    </button>
                    <button type="button" id="aipkit_ai_form_generate_draft_btn" class="aipkit_btn aipkit_btn-primary">
                        <span class="aipkit_btn-text"><?php esc_html_e('Generate', 'gpt3-ai-content-generator'); ?></span>
                        <span class="aipkit_spinner" style="display:none;"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
