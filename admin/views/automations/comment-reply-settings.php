<?php
/** Shared Automations comment-reply source, filters and instructions. */
if (!defined('ABSPATH')) {
    exit;
}

require_once WPAICG_PLUGIN_DIR . 'admin/views/automations/prompt-editor.php';

function aipkit_render_automation_comment_reply_panel(string $aipkit_panel, array $aipkit_view_data = []): void
{
    if ($aipkit_panel === 'source') {
        $all_selectable_post_types = $aipkit_view_data['all_selectable_post_types'] ?? [];
        $reply_actions = [
            'approve' => __('Approve', 'gpt3-ai-content-generator'),
            'hold' => __('Hold for moderation', 'gpt3-ai-content-generator'),
        ];
        ?>
        <div id="aipkit_task_cc_source_settings" class="aipkit_cc_source_panel">
            <div class="aipkit_cc_source_grid">
                <div class="aipkit_cc_setting_row aipkit_cc_setting_row--content-types">
                    <label class="aipkit_cc_field_label" for="aipkit_task_comment_reply_post_types"><?php esc_html_e('Content types', 'gpt3-ai-content-generator'); ?></label>
                    <select id="aipkit_task_comment_reply_post_types" name="post_types_for_comments[]" class="aipkit_form-input aipkit_cc_multi_select" data-aipkit-checklist-style="inline-checkboxes" multiple size="5">
                        <?php foreach ($all_selectable_post_types as $slug => $pt_obj): ?>
                            <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($pt_obj->label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="aipkit_cc_setting_row aipkit_cc_setting_row--reply-action">
                    <label class="aipkit_cc_field_label" for="aipkit_task_comment_reply_action"><?php esc_html_e('Action on reply', 'gpt3-ai-content-generator'); ?></label>
                    <select id="aipkit_task_comment_reply_action" name="reply_action" class="aipkit_form-input aipkit_cc_select" data-aipkit-segmented-select>
                        <?php foreach ($reply_actions as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($value, 'hold'); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="aipkit_cc_setting_row aipkit_cc_setting_row--checkbox">
                    <label id="aipkit_task_comment_reply_no_replies_label" class="aipkit_cc_field_label" for="aipkit_task_comment_reply_no_replies"><?php esc_html_e('Top-level comments only', 'gpt3-ai-content-generator'); ?></label>
                    <input class="aipkit_cc_setting_checkbox" type="checkbox" id="aipkit_task_comment_reply_no_replies" name="no_reply_to_replies" value="1" aria-labelledby="aipkit_task_comment_reply_no_replies_label" checked>
                </div>
            </div>
        </div>
        <?php
        return;
    }
    if ($aipkit_panel !== 'filters') {
        return;
    }
    ?>
    <div id="aipkit_task_config_comment_reply_main" class="aipkit_task_config_section">
        <?php
        $aipkit_cc_prompt_items = \WPAICG\AutoGPT\Helpers\AIPKit_AutoGPT_Prompt_Definitions::get_comment_reply_prompt_items();
        $aipkit_cc_reply_prompt_item = $aipkit_cc_prompt_items[0] ?? [];
        if ($aipkit_cc_reply_prompt_item) {
            $aipkit_cc_reply_prompt_item['static_placeholders'] = true;
        }
        ?>
        <div
            id="aipkit_task_config_comment_reply_settings"
            data-aipkit-inline-prompts="comments"
            data-aipkit-inline-prompt-layout="rows"
        >
            <div class="aipkit_cc_filter_rows">
                <div class="aipkit_cc_setting_row">
                    <label class="aipkit_cc_field_label" for="aipkit_task_comment_reply_include_keywords"><?php esc_html_e('Only reply if comment contains', 'gpt3-ai-content-generator'); ?></label>
                    <textarea id="aipkit_task_comment_reply_include_keywords" name="include_keywords" class="aipkit_form-input aipkit_cc_textarea" rows="1" placeholder="<?php esc_attr_e('e.g., question, help, how to', 'gpt3-ai-content-generator'); ?>"></textarea>
                </div>

                <div class="aipkit_cc_setting_row">
                    <label class="aipkit_cc_field_label" for="aipkit_task_comment_reply_exclude_keywords"><?php esc_html_e('Do not reply if comment contains', 'gpt3-ai-content-generator'); ?></label>
                    <textarea id="aipkit_task_comment_reply_exclude_keywords" name="exclude_keywords" class="aipkit_form-input aipkit_cc_textarea" rows="1" placeholder="<?php esc_attr_e('e.g., spam, offer, http', 'gpt3-ai-content-generator'); ?>"></textarea>
                </div>

                <?php if ($aipkit_cc_reply_prompt_item) : ?>
                    <div
                        class="aipkit_cc_setting_row aipkit_cc_setting_row--reply-prompt aipkit_autogpt_content_field"
                        data-aipkit-content-field
                        data-aipkit-prompt-key="reply"
                        data-aipkit-instruction-label="<?php esc_attr_e('Reply Prompt', 'gpt3-ai-content-generator'); ?>"
                    >
                        <span class="aipkit_cc_field_label"><?php esc_html_e('Reply Prompt', 'gpt3-ai-content-generator'); ?></span>
                        <button
                            type="button"
                            class="aipkit_autogpt_content_prompt_trigger"
                            data-aipkit-row-prompt-toggle
                            aria-controls="aipkit_task_cc_instructions_modal"
                            aria-haspopup="dialog"
                        >
                            <span
                                data-aipkit-row-prompt-status
                                data-built-in-label="<?php esc_attr_e('Instructions', 'gpt3-ai-content-generator'); ?>"
                                data-custom-label="<?php esc_attr_e('Custom instructions', 'gpt3-ai-content-generator'); ?>"
                            ><?php esc_html_e('Instructions', 'gpt3-ai-content-generator'); ?></span>
                            <span class="dashicons dashicons-edit aipkit_autogpt_content_prompt_icon" aria-hidden="true"></span>
                        </button>

                        <div
                            id="aipkit_task_cc_reply_instruction_panel"
                            class="aipkit_autogpt_content_prompt_panel"
                            data-aipkit-row-prompt-panel
                            hidden
                        >
                            <?php
                            aipkit_render_automation_prompt_editor([$aipkit_cc_reply_prompt_item]);
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div
                id="aipkit_task_cc_instructions_modal"
                class="aipkit_autogpt_instructions_modal"
                data-aipkit-instructions-modal
                data-title-template="<?php
                /* translators: %s: content field name. */
                echo esc_attr(__('%s instructions', 'gpt3-ai-content-generator'));
                ?>"
                aria-hidden="true"
                hidden
            >
                <div
                    class="aipkit_autogpt_instructions_modal_panel"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="aipkit_task_cc_instructions_modal_title"
                    aria-describedby="aipkit_task_cc_instructions_modal_description"
                >
                    <div class="aipkit_autogpt_instructions_modal_header">
                        <div class="aipkit_autogpt_instructions_modal_heading">
                            <h2 id="aipkit_task_cc_instructions_modal_title" data-aipkit-instructions-modal-title>
                                <?php esc_html_e('Reply Prompt instructions', 'gpt3-ai-content-generator'); ?>
                            </h2>
                            <p id="aipkit_task_cc_instructions_modal_description">
                                <?php esc_html_e('Tell AI how to reply to every matching comment.', 'gpt3-ai-content-generator'); ?>
                            </p>
                        </div>
                        <button
                            type="button"
                            class="aipkit_autogpt_instructions_modal_close"
                            data-aipkit-instructions-modal-close
                            aria-label="<?php esc_attr_e('Close instructions', 'gpt3-ai-content-generator'); ?>"
                        >
                            <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                        </button>
                    </div>
                    <div class="aipkit_autogpt_instructions_modal_body" data-aipkit-instructions-modal-body></div>
                    <div class="aipkit_autogpt_instructions_modal_footer">
                        <button type="button" class="aipkit_btn aipkit_btn-secondary" data-aipkit-instructions-modal-cancel>
                            <?php esc_html_e('Cancel', 'gpt3-ai-content-generator'); ?>
                        </button>
                        <button type="button" class="aipkit_btn aipkit_btn-primary" data-aipkit-instructions-modal-save>
                            <?php esc_html_e('Save', 'gpt3-ai-content-generator'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}
