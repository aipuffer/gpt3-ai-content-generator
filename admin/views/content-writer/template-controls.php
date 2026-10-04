<?php

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="aipkit_cw_template_controls">
    <div class="aipkit_cw_template_field">
        <label class="aipkit_cw_panel_label" for="aipkit_cw_template_select">
            <?php esc_html_e('Template', 'gpt3-ai-content-generator'); ?>
        </label>
        <div
            id="aipkit_cw_template_dropdown"
            class="aipkit_popover_multiselect aipkit_settings_model_dropdown aipkit_cw_template_dropdown"
        >
            <button
                type="button"
                id="aipkit_cw_template_picker_btn"
                class="aipkit_popover_multiselect_btn aipkit_settings_select_picker_btn aipkit_cw_blended_chevron_btn"
                aria-expanded="false"
                aria-controls="aipkit_cw_template_picker_panel"
            >
                <span
                    id="aipkit_cw_template_picker_label"
                    class="aipkit_popover_multiselect_label aipkit_settings_select_picker_label"
                >
                    <?php esc_html_e('Select template', 'gpt3-ai-content-generator'); ?>
                </span>
            </button>
            <div
                id="aipkit_cw_template_picker_panel"
                class="aipkit_popover_multiselect_panel aipkit_settings_model_dropdown_panel aipkit_cw_template_dropdown_panel"
                role="menu"
                hidden
            >
                <div class="aipkit_cw_template_search">
                    <span class="dashicons dashicons-search" aria-hidden="true"></span>
                    <input
                        type="search"
                        id="aipkit_cw_template_search"
                        class="aipkit_form-input"
                        placeholder="<?php esc_attr_e('Search templates', 'gpt3-ai-content-generator'); ?>"
                        aria-label="<?php esc_attr_e('Search templates', 'gpt3-ai-content-generator'); ?>"
                        autocomplete="off"
                    >
                </div>
                <div
                    id="aipkit_cw_template_picker_list"
                    class="aipkit_popover_multiselect_options aipkit_settings_select_picker_list"
                    role="listbox"
                    aria-label="<?php esc_attr_e('Template list', 'gpt3-ai-content-generator'); ?>"
                >
                    <div class="aipkit_settings_select_picker_empty">
                        <?php esc_html_e('Loading templates...', 'gpt3-ai-content-generator'); ?>
                    </div>
                </div>
                <div class="aipkit_cw_template_footer">
                    <button type="button" id="aipkit_cw_save_as_template_btn" class="aipkit_cw_template_footer_action">
                        <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                        <span><?php esc_html_e('New template', 'gpt3-ai-content-generator'); ?></span>
                    </button>
                    <button type="button" id="aipkit_cw_reset_starter_templates_btn" class="aipkit_cw_template_footer_action">
                        <span class="dashicons dashicons-update" aria-hidden="true"></span>
                        <span><?php esc_html_e('Restore starter templates', 'gpt3-ai-content-generator'); ?></span>
                    </button>
                </div>
                <div id="aipkit_cw_tpl_inline_panel" class="aipkit_cw_tpl_inline_panel" hidden>
                    <div id="aipkit_cw_tpl_inline_title" class="aipkit_cw_tpl_inline_title"></div>
                    <div id="aipkit_cw_tpl_inline_field" class="aipkit_cw_tpl_inline_field" hidden>
                        <input
                            type="text"
                            id="aipkit_cw_tpl_inline_input"
                            class="aipkit_form-input"
                            autocomplete="off"
                        >
                    </div>
                    <p id="aipkit_cw_tpl_inline_error" class="aipkit_cw_tpl_inline_error" hidden></p>
                    <div class="aipkit_cw_tpl_inline_actions">
                        <button type="button" id="aipkit_cw_tpl_inline_cancel" class="aipkit_cw_tpl_inline_btn">
                            <?php esc_html_e('Cancel', 'gpt3-ai-content-generator'); ?>
                        </button>
                        <button type="button" id="aipkit_cw_tpl_inline_confirm" class="aipkit_cw_tpl_inline_btn aipkit_cw_tpl_inline_btn--primary">
                            <?php esc_html_e('Save', 'gpt3-ai-content-generator'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <select id="aipkit_cw_template_select" name="cw_template_id" class="aipkit_form-input screen-reader-text">
            <option value=""><?php esc_html_e('-- Select Template --', 'gpt3-ai-content-generator'); ?></option>
            <?php // Options will be populated by JS ?>
        </select>
    </div>
</div>
