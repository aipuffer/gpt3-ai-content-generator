<?php
/** Automations content fields and their instructions. */
if (!defined('ABSPATH')) {
    exit;
}

use WPAICG\ContentWriter\AIPKit_Content_Writer_Prompts;

require_once WPAICG_PLUGIN_DIR . 'admin/views/automations/prompt-editor.php';

function aipkit_render_automation_content_settings(): void
{
    $aipkit_cw_all_prompt_items = AIPKit_Content_Writer_Prompts::get_autogpt_content_writing_prompt_items();
    $aipkit_cw_text_prompt_items = array_values(array_filter(
        $aipkit_cw_all_prompt_items,
        static function (array $item): bool {
            return in_array((string) ($item['key'] ?? ''), ['title', 'content', 'meta', 'keyword', 'excerpt', 'tags'], true);
        }
    ));

    $aipkit_cw_field_labels = [
        'title' => __('Title', 'gpt3-ai-content-generator'),
        'content' => __('Article content', 'gpt3-ai-content-generator'),
        'meta' => __('Meta description', 'gpt3-ai-content-generator'),
        'keyword' => __('Focus keyword', 'gpt3-ai-content-generator'),
        'excerpt' => __('Excerpt', 'gpt3-ai-content-generator'),
        'tags' => __('Tags', 'gpt3-ai-content-generator'),
    ];

    foreach ($aipkit_cw_text_prompt_items as &$aipkit_cw_prompt_item) {
        $aipkit_cw_prompt_key = (string) ($aipkit_cw_prompt_item['key'] ?? '');
        if (isset($aipkit_cw_field_labels[$aipkit_cw_prompt_key])) {
            $aipkit_cw_prompt_item['label'] = $aipkit_cw_field_labels[$aipkit_cw_prompt_key];
        }
        if (!empty($aipkit_cw_prompt_item['toggle']['name'])) {
            $aipkit_cw_prompt_item['visibility_control'] = (string) $aipkit_cw_prompt_item['toggle']['name'];
        }
    }
    unset($aipkit_cw_prompt_item);

    ?>

    <div
        class="aipkit_autogpt_content_fields"
        data-aipkit-inline-prompts="writing"
        data-aipkit-inline-prompt-layout="rows"
    >
        <?php foreach ($aipkit_cw_text_prompt_items as $aipkit_cw_prompt_item) : ?>
            <?php
            $aipkit_cw_prompt_key = sanitize_key((string) ($aipkit_cw_prompt_item['key'] ?? ''));
            $aipkit_cw_prompt_label = (string) ($aipkit_cw_prompt_item['label'] ?? '');
            $aipkit_cw_prompt_toggle = isset($aipkit_cw_prompt_item['toggle']) && is_array($aipkit_cw_prompt_item['toggle'])
                ? $aipkit_cw_prompt_item['toggle']
                : [];
            $aipkit_cw_has_toggle = !empty($aipkit_cw_prompt_toggle['id']) && !empty($aipkit_cw_prompt_toggle['name']);
            $aipkit_cw_toggle_checked = !$aipkit_cw_has_toggle || !empty($aipkit_cw_prompt_toggle['checked']);
            $aipkit_cw_visibility_control = (string) ($aipkit_cw_prompt_item['visibility_control'] ?? '');
            $aipkit_cw_prompt_panel_id = 'aipkit_task_cw_' . $aipkit_cw_prompt_key . '_instruction_panel';
            if ($aipkit_cw_prompt_key === '' || $aipkit_cw_prompt_label === '') {
                continue;
            }
            ?>
            <div
                class="aipkit_autogpt_content_field"
                data-aipkit-content-field
                data-aipkit-prompt-key="<?php echo esc_attr($aipkit_cw_prompt_key); ?>"
                <?php echo $aipkit_cw_visibility_control !== '' ? ' data-aipkit-visibility-control="' . esc_attr($aipkit_cw_visibility_control) . '"' : ''; ?>
            >
                <div class="aipkit_autogpt_content_field_row">
                    <span class="aipkit_autogpt_content_field_label_wrap">
                        <span class="aipkit_autogpt_content_field_label"><?php echo esc_html($aipkit_cw_prompt_label); ?></span>
                        <?php if (!$aipkit_cw_has_toggle) : ?>
                            <span class="aipkit_autogpt_content_field_helper"><?php esc_html_e('Always included', 'gpt3-ai-content-generator'); ?></span>
                        <?php endif; ?>
                    </span>
                    <div class="aipkit_autogpt_content_field_controls">
                        <button
                            type="button"
                            class="aipkit_autogpt_content_prompt_trigger"
                            data-aipkit-row-prompt-toggle
                            aria-controls="aipkit_task_cw_instructions_modal"
                            aria-haspopup="dialog"
                            <?php echo $aipkit_cw_toggle_checked ? '' : ' hidden'; ?>
                        >
                            <span
                                data-aipkit-row-prompt-status
                                data-built-in-label="<?php esc_attr_e('Instructions', 'gpt3-ai-content-generator'); ?>"
                                data-custom-label="<?php esc_attr_e('Custom instructions', 'gpt3-ai-content-generator'); ?>"
                            ><?php esc_html_e('Instructions', 'gpt3-ai-content-generator'); ?></span>
                            <span class="dashicons dashicons-edit aipkit_autogpt_content_prompt_icon" aria-hidden="true"></span>
                        </button>

                        <?php if ($aipkit_cw_has_toggle) : ?>
                            <label class="aipkit_switch aipkit_autogpt_content_field_switch" aria-label="<?php
                            /* translators: %s: content field name. */
                            echo esc_attr(sprintf(__('Include %s', 'gpt3-ai-content-generator'), $aipkit_cw_prompt_label));
                            ?>">
                                <input
                                    type="checkbox"
                                    id="<?php echo esc_attr((string) $aipkit_cw_prompt_toggle['id']); ?>"
                                    name="<?php echo esc_attr((string) $aipkit_cw_prompt_toggle['name']); ?>"
                                    value="1"
                                    <?php checked(!empty($aipkit_cw_prompt_toggle['checked'])); ?>
                                >
                                <span class="aipkit_switch_slider"></span>
                            </label>
                        <?php else : ?>
                            <label
                                class="aipkit_switch aipkit_autogpt_content_field_switch aipkit_autogpt_content_field_switch--required"
                                aria-label="<?php
                                /* translators: %s: content field name. */
                                echo esc_attr(sprintf(__('%s is always included', 'gpt3-ai-content-generator'), $aipkit_cw_prompt_label));
                                ?>"
                                title="<?php esc_attr_e('Always included', 'gpt3-ai-content-generator'); ?>"
                            >
                                <input type="checkbox" checked disabled>
                                <span class="aipkit_switch_slider"></span>
                            </label>
                        <?php endif; ?>
                    </div>
                </div>

                <div
                    id="<?php echo esc_attr($aipkit_cw_prompt_panel_id); ?>"
                    class="aipkit_autogpt_content_prompt_panel"
                    data-aipkit-row-prompt-panel
                    hidden
                >
                    <?php
                    aipkit_render_automation_prompt_editor([$aipkit_cw_prompt_item]);
                    ?>
                </div>
            </div>
        <?php endforeach; ?>

        <div
            id="aipkit_task_cw_instructions_modal"
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
                aria-labelledby="aipkit_task_cw_instructions_modal_title"
                aria-describedby="aipkit_task_cw_instructions_modal_description"
            >
                <div class="aipkit_autogpt_instructions_modal_header">
                    <div class="aipkit_autogpt_instructions_modal_heading">
                        <h2 id="aipkit_task_cw_instructions_modal_title" data-aipkit-instructions-modal-title>
                            <?php esc_html_e('Instructions', 'gpt3-ai-content-generator'); ?>
                        </h2>
                        <p id="aipkit_task_cw_instructions_modal_description">
                            <?php esc_html_e('Tell AI how to write this part of every post.', 'gpt3-ai-content-generator'); ?>
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
<?php
}
