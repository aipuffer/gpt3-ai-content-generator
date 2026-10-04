<?php
/** Shared Automations prompt fields, libraries and placeholder controls. */
if (!defined('ABSPATH')) {
    exit;
}

function aipkit_render_automation_prompt_editor(array $aipkit_inline_prompt_items): void
{
    $aipkit_render_inline_library_options = static function (array $options): void {
        foreach ($options as $option) {
            if (empty($option['label']) || empty($option['prompt'])) {
                continue;
            }
            printf(
                '<option value="%s">%s</option>',
                esc_attr((string) $option['prompt']),
                esc_html((string) $option['label'])
            );
        }
    };
    ?>

    <div class="aipkit_autogpt_prompt_editor_stack" data-aipkit-prompt-editor-stack>
        <?php foreach ($aipkit_inline_prompt_items as $aipkit_inline_prompt_item) : ?>
            <?php
            $aipkit_inline_prompt_key = sanitize_key((string) ($aipkit_inline_prompt_item['key'] ?? ''));
            $aipkit_inline_prompt_textarea = isset($aipkit_inline_prompt_item['textarea']) && is_array($aipkit_inline_prompt_item['textarea'])
                ? $aipkit_inline_prompt_item['textarea']
                : [];
            $aipkit_inline_prompt_textarea_id = (string) ($aipkit_inline_prompt_textarea['id'] ?? '');
            $aipkit_inline_prompt_textarea_name = (string) ($aipkit_inline_prompt_textarea['name'] ?? '');
            $aipkit_inline_prompt_textarea_value = (string) ($aipkit_inline_prompt_textarea['value'] ?? '');
            $aipkit_inline_prompt_textarea_placeholder = (string) ($aipkit_inline_prompt_textarea['placeholder'] ?? '');
            $aipkit_inline_prompt_visibility_control = (string) ($aipkit_inline_prompt_item['visibility_control'] ?? '');
            $aipkit_inline_prompt_library = isset($aipkit_inline_prompt_item['library']) && is_array($aipkit_inline_prompt_item['library'])
                ? $aipkit_inline_prompt_item['library']
                : [];
            $aipkit_inline_prompt_library_select_id = (string) ($aipkit_inline_prompt_library['select_id'] ?? '');
            $aipkit_inline_prompt_library_options = isset($aipkit_inline_prompt_library['options']) && is_array($aipkit_inline_prompt_library['options'])
                ? $aipkit_inline_prompt_library['options']
                : [];
            $aipkit_inline_prompt_library_default = (string) ($aipkit_inline_prompt_library['default_prompt'] ?? $aipkit_inline_prompt_textarea_value);
            $aipkit_inline_prompt_placeholders = isset($aipkit_inline_prompt_item['placeholders']) && is_array($aipkit_inline_prompt_item['placeholders'])
                ? $aipkit_inline_prompt_item['placeholders']
                : [];
            $aipkit_inline_prompt_extra_placeholders = isset($aipkit_inline_prompt_item['placeholders_extra']) && is_array($aipkit_inline_prompt_item['placeholders_extra'])
                ? $aipkit_inline_prompt_item['placeholders_extra']
                : [];
            $aipkit_inline_prompt_extra_label = (string) ($aipkit_inline_prompt_item['placeholders_extra_label'] ?? '');
            $aipkit_inline_prompt_extra_class = (string) ($aipkit_inline_prompt_item['placeholders_extra_class'] ?? 'aipkit-product-placeholders');
            $aipkit_inline_prompt_static_placeholders = !empty($aipkit_inline_prompt_item['static_placeholders']);
            $aipkit_inline_prompt_type = (string) ($aipkit_inline_prompt_item['placeholders_prompt_type'] ?? $aipkit_inline_prompt_key);
            $aipkit_inline_prompt_placeholders_id = (string) ($aipkit_inline_prompt_item['placeholders_id'] ?? '');

            if ($aipkit_inline_prompt_key === '' || $aipkit_inline_prompt_textarea_id === '' || $aipkit_inline_prompt_textarea_name === '') {
                continue;
            }
            ?>
            <div
                class="aipkit_autogpt_prompt_editor_item"
                data-aipkit-inline-prompt-item
                data-aipkit-prompt-key="<?php echo esc_attr($aipkit_inline_prompt_key); ?>"
                <?php echo $aipkit_inline_prompt_visibility_control !== '' ? ' data-aipkit-visibility-control="' . esc_attr($aipkit_inline_prompt_visibility_control) . '"' : ''; ?>
                hidden
            >
                <div class="aipkit_cw_prompt_editor">
                    <?php if ($aipkit_inline_prompt_library_select_id !== '') : ?>
                        <div class="aipkit_cw_prompt_editor_toolbar aipkit_autogpt_prompt_template_toolbar">
                            <select
                                id="<?php echo esc_attr($aipkit_inline_prompt_library_select_id); ?>"
                                class="aipkit_cw_prompt_template_select aipkit_cw_prompt_library_select"
                                data-aipkit-prompt-target="<?php echo esc_attr($aipkit_inline_prompt_textarea_id); ?>"
                                data-aipkit-prompt-library-label="<?php esc_attr_e('Prompt library', 'gpt3-ai-content-generator'); ?>"
                                title="<?php esc_attr_e('Choose an instruction template', 'gpt3-ai-content-generator'); ?>"
                            >
                                <option value="<?php echo esc_attr($aipkit_inline_prompt_library_default); ?>"><?php esc_html_e('Default', 'gpt3-ai-content-generator'); ?></option>
                                <?php $aipkit_render_inline_library_options($aipkit_inline_prompt_library_options); ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <textarea
                        id="<?php echo esc_attr($aipkit_inline_prompt_textarea_id); ?>"
                        name="<?php echo esc_attr($aipkit_inline_prompt_textarea_name); ?>"
                        class="aipkit_cw_prompt_editor_textarea aipkit_autosave_trigger"
                        data-aipkit-inline-prompt-textarea
                        placeholder="<?php echo esc_attr($aipkit_inline_prompt_textarea_placeholder); ?>"
                    ><?php echo esc_textarea($aipkit_inline_prompt_textarea_value); ?></textarea>

                    <div class="aipkit_autogpt_inline_prompt_footer">
                        <?php if ($aipkit_inline_prompt_placeholders || $aipkit_inline_prompt_extra_placeholders) : ?>
                            <span
                                class="aipkit_cw_prompt_editor_placeholders"
                                data-prompt-type="<?php echo esc_attr($aipkit_inline_prompt_type); ?>"
                                data-aipkit-hide-placeholder-label="true"
                                data-copy-title="<?php esc_attr_e('Click to copy', 'gpt3-ai-content-generator'); ?>"
                                <?php echo $aipkit_inline_prompt_static_placeholders ? ' data-aipkit-static-placeholders="true"' : ''; ?>
                                <?php echo $aipkit_inline_prompt_placeholders_id !== '' ? ' id="' . esc_attr($aipkit_inline_prompt_placeholders_id) . '"' : ''; ?>
                            >
                                <?php foreach ($aipkit_inline_prompt_placeholders as $aipkit_inline_prompt_placeholder) : ?>
                                    <code class="aipkit-placeholder" title="<?php esc_attr_e('Click to copy', 'gpt3-ai-content-generator'); ?>"><?php echo esc_html((string) $aipkit_inline_prompt_placeholder); ?></code>
                                <?php endforeach; ?>
                                <?php if ($aipkit_inline_prompt_extra_placeholders) : ?>
                                    <span class="<?php echo esc_attr($aipkit_inline_prompt_extra_class); ?>" style="display:none;">
                                        <?php if ($aipkit_inline_prompt_extra_label !== '') : ?>
                                            <span><?php echo esc_html($aipkit_inline_prompt_extra_label); ?></span>
                                        <?php endif; ?>
                                        <?php foreach ($aipkit_inline_prompt_extra_placeholders as $aipkit_inline_prompt_placeholder) : ?>
                                            <code class="aipkit-placeholder" title="<?php esc_attr_e('Click to copy', 'gpt3-ai-content-generator'); ?>"><?php echo esc_html((string) $aipkit_inline_prompt_placeholder); ?></code>
                                        <?php endforeach; ?>
                                    </span>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                        <button type="button" class="aipkit_autogpt_inline_prompt_reset" data-aipkit-inline-prompt-reset>
                            <?php esc_html_e('Reset to default', 'gpt3-ai-content-generator'); ?>
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php
}
