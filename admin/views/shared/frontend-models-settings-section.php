<?php
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Shared view partial configured by parent templates.

$aipkit_frontend_models_section_id_prefix = isset($aipkit_frontend_models_section_id_prefix)
    ? preg_replace('/[^A-Za-z0-9_]/', '', (string) $aipkit_frontend_models_section_id_prefix)
    : '';
$aipkit_frontend_models_textarea_id = isset($aipkit_frontend_models_textarea_id)
    ? preg_replace('/[^A-Za-z0-9_]/', '', (string) $aipkit_frontend_models_textarea_id)
    : '';
$aipkit_frontend_models_selector_id = isset($aipkit_frontend_models_selector_id)
    ? preg_replace('/[^A-Za-z0-9_]/', '', (string) $aipkit_frontend_models_selector_id)
    : '';
$aipkit_frontend_models_providers_textarea_id = isset($aipkit_frontend_models_providers_textarea_id)
    ? preg_replace('/[^A-Za-z0-9_]/', '', (string) $aipkit_frontend_models_providers_textarea_id)
    : '';
$aipkit_frontend_models_empty_all_selected_attr = isset($aipkit_frontend_models_empty_all_selected)
    ? ' data-empty-all-selected="' . ($aipkit_frontend_models_empty_all_selected ? '1' : '0') . '"'
    : '';
$aipkit_settings_section_is_modern = isset($aipkit_settings_section_variant)
    && $aipkit_settings_section_variant === 'ai-forms-modern';
$aipkit_settings_section_classes = $aipkit_settings_section_is_modern
    ? 'aipkit_ai_forms_settings_surface aipkit_ai_forms_settings_tab_panel'
    : 'aipkit_ai_forms_settings_block aipkit_settings_module_tab_panel';
$aipkit_settings_section_header_class = $aipkit_settings_section_is_modern
    ? 'aipkit_ai_forms_settings_surface_header'
    : 'aipkit_ai_forms_settings_block_header';
$aipkit_settings_section_title_class = $aipkit_settings_section_is_modern
    ? 'aipkit_ai_forms_settings_surface_title'
    : 'aipkit_ai_forms_settings_block_title';
$aipkit_settings_section_helper_class = $aipkit_settings_section_is_modern
    ? 'aipkit_ai_forms_settings_surface_helper'
    : 'aipkit_ai_forms_settings_block_helper';
$aipkit_settings_section_body_class = $aipkit_settings_section_is_modern
    ? 'aipkit_ai_forms_settings_surface_body'
    : 'aipkit_ai_forms_settings_block_body';
?>
<section
    class="<?php echo esc_attr($aipkit_settings_section_classes); ?>"
    id="<?php echo esc_attr($aipkit_frontend_models_section_id_prefix . '_frontend_models'); ?>"
    role="tabpanel"
    aria-labelledby="<?php echo esc_attr($aipkit_frontend_models_section_id_prefix . '_tab_frontend_models'); ?>"
    data-aipkit-settings-module-tab-panel="frontend-models"
    hidden
>
    <div class="<?php echo esc_attr($aipkit_settings_section_header_class); ?>">
        <div>
            <h3 class="<?php echo esc_attr($aipkit_settings_section_title_class); ?>"><?php esc_html_e('Frontend Models', 'gpt3-ai-content-generator'); ?></h3>
            <p class="<?php echo esc_attr($aipkit_settings_section_helper_class); ?>"><?php esc_html_e('Restrict providers and models shown to visitors.', 'gpt3-ai-content-generator'); ?></p>
        </div>
    </div>
    <div class="<?php echo esc_attr($aipkit_settings_section_body_class); ?>">
        <div class="aipkit_ai_forms_settings_row aipkit_ai_forms_settings_row--wide">
            <div class="aipkit_form-label">
                <?php esc_html_e('Allowed Models', 'gpt3-ai-content-generator'); ?>
                <span class="aipkit_form-label-helper"><?php esc_html_e('Leave empty to show all models.', 'gpt3-ai-content-generator'); ?></span>
            </div>
            <textarea
                id="<?php echo esc_attr($aipkit_frontend_models_textarea_id); ?>"
                name="frontend_models"
                class="aipkit_autosave_trigger"
                rows="4"
                hidden
                placeholder="<?php esc_attr_e('Select models below or leave empty for all', 'gpt3-ai-content-generator'); ?>"
            ><?php echo esc_textarea($allowed_models_str); ?></textarea>
            <?php if ($aipkit_frontend_models_providers_textarea_id !== '') : ?>
                <textarea
                    id="<?php echo esc_attr($aipkit_frontend_models_providers_textarea_id); ?>"
                    name="frontend_providers"
                    class="aipkit_autosave_trigger"
                    rows="2"
                    hidden
                ><?php echo esc_textarea($allowed_providers_str ?? ''); ?></textarea>
            <?php endif; ?>
            <div
                id="<?php echo esc_attr($aipkit_frontend_models_selector_id); ?>"
                class="aipkit_models_selector"
                data-initial-value="<?php echo esc_attr($allowed_models_str); ?>"<?php echo $aipkit_frontend_models_empty_all_selected_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attribute string is generated from a boolean above. ?>
            >
                <div class="aipkit_models_selector-loading">
                    <?php esc_html_e('Loading model list...', 'gpt3-ai-content-generator'); ?>
                </div>
            </div>
        </div>
    </div>
</section>
