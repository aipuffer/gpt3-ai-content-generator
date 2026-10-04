<?php
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- These closures are local to the Content Writer form partial.

$aipkit_cw_render_prompt_library_options = static function (array $options, string $mode = ''): void {
    foreach ($options as $option) {
        if (empty($option['label']) || empty($option['prompt'])) {
            continue;
        }
        echo '<option value="' . esc_attr((string) $option['prompt']) . '"';
        if ($mode !== '') {
            echo ' data-aipkit-mode="' . esc_attr($mode) . '"';
        }
        echo '>' . esc_html((string) $option['label']) . '</option>';
    }
};

$aipkit_cw_render_inline_prompt = static function (array $item) use ($aipkit_cw_render_prompt_library_options): void {
    $key = sanitize_key((string) ($item['key'] ?? ''));
    $label = (string) ($item['label'] ?? '');
    $textarea = is_array($item['textarea'] ?? null) ? $item['textarea'] : [];
    $library = is_array($item['library'] ?? null) ? $item['library'] : [];
    $toggle = is_array($item['toggle'] ?? null) ? $item['toggle'] : [];
    $has_toggle = !empty($toggle['id']) && !empty($toggle['name']);
    $is_update_only = !empty($toggle['update_only']);
    $is_enabled = !$has_toggle || !empty($toggle['checked']);
    $panel_id = 'aipkit_cw_' . $key . '_prompt_inline_editor';
    $requirement_id = $panel_id . '_requirement';
    $row_id = (string) ($item['row_id'] ?? '');
    $button_id = (string) ($item['button_id'] ?? '');
    $button_class = trim('aipkit_cw_prompt_instructions ' . (string) ($item['button_class'] ?? ''));
    $placeholders = is_array($item['placeholders'] ?? null) ? $item['placeholders'] : [];
    /* translators: %s: prompt field label. */
    $include_aria_label = sprintf(__('Include %s', 'gpt3-ai-content-generator'), $label);
    /* translators: %s: prompt field label. */
    $preset_aria_label = sprintf(__('%s preset', 'gpt3-ai-content-generator'), $label);
    ?>
    <div
        class="aipkit_cw_prompt_field<?php echo $is_enabled ? '' : ' is-disabled'; ?>"
        data-aipkit-cw-prompt-field
        data-aipkit-prompt-key="<?php echo esc_attr($key); ?>"
        <?php echo $row_id !== '' ? ' id="' . esc_attr($row_id) . '"' : ''; ?>
    >
        <div class="aipkit_cw_prompt_field_row">
            <span class="aipkit_cw_prompt_field_label_wrap">
                <span class="aipkit_cw_prompt_field_label"><?php echo esc_html($label); ?></span>
            </span>
            <span class="aipkit_cw_prompt_field_controls">
                <button
                    type="button"
                    <?php echo $button_id !== '' ? ' id="' . esc_attr($button_id) . '"' : ''; ?>
                    class="<?php echo esc_attr($button_class); ?>"
                    data-aipkit-cw-prompt-toggle
                    aria-controls="<?php echo esc_attr($panel_id); ?>"
                    aria-expanded="false"
                    <?php echo $is_enabled ? '' : ' hidden disabled aria-disabled="true"'; ?>
                >
                    <span><?php esc_html_e('Instructions', 'gpt3-ai-content-generator'); ?></span>
                    <span class="dashicons dashicons-edit" aria-hidden="true"></span>
                </button>
                <?php if ($has_toggle) : ?>
                    <label class="aipkit_switch<?php echo $is_update_only ? ' aipkit_prompt_update_only is-readonly' : ''; ?>" aria-label="<?php echo esc_attr($include_aria_label); ?>">
                        <input
                            type="checkbox"
                            id="<?php echo esc_attr((string) $toggle['id']); ?>"
                            name="<?php echo esc_attr((string) $toggle['name']); ?>"
                            class="aipkit_toggle_switch aipkit_autosave_trigger <?php echo esc_attr((string) ($toggle['class'] ?? '')); ?>"
                            value="1"
                            <?php checked(!empty($toggle['checked'])); ?>
                            <?php echo $is_update_only ? ' aria-disabled="true" tabindex="-1"' : ''; ?>
                        >
                        <span class="aipkit_switch_slider"></span>
                    </label>
                <?php endif; ?>
            </span>
        </div>
        <div id="<?php echo esc_attr($panel_id); ?>" class="aipkit_cw_prompt_inline_editor" data-aipkit-cw-prompt-editor hidden>
            <div class="aipkit_cw_prompt_editor">
                <div class="aipkit_cw_prompt_inline_header">
                    <span class="aipkit_cw_prompt_inline_label"><?php esc_html_e('Preset', 'gpt3-ai-content-generator'); ?></span>
                    <button type="button" class="aipkit_cw_prompt_library_link" data-aipkit-cw-prompt-library-link><?php esc_html_e('Library', 'gpt3-ai-content-generator'); ?></button>
                </div>
                <select
                    id="<?php echo esc_attr((string) ($library['select_id'] ?? '')); ?>"
                    class="aipkit_cw_prompt_inline_select aipkit_cw_prompt_library_select"
                    data-aipkit-prompt-target="<?php echo esc_attr((string) ($textarea['id'] ?? '')); ?>"
                    aria-label="<?php echo esc_attr($preset_aria_label); ?>"
                >
                    <option value="<?php echo esc_attr((string) ($library['default_prompt'] ?? '')); ?>" data-aipkit-mode="both"><?php esc_html_e('Default', 'gpt3-ai-content-generator'); ?></option>
                    <?php $aipkit_cw_render_prompt_library_options($library['options'] ?? [], !empty($library['update_options']) ? 'create' : 'both'); ?>
                    <?php $aipkit_cw_render_prompt_library_options($library['update_options'] ?? [], 'update'); ?>
                    <option value="__aipkit_custom__" data-aipkit-custom-option hidden><?php esc_html_e('Custom', 'gpt3-ai-content-generator'); ?></option>
                </select>
                <div class="aipkit_builder_textarea_wrap aipkit_cw_prompt_textarea_wrap">
                    <textarea
                        id="<?php echo esc_attr((string) ($textarea['id'] ?? '')); ?>"
                        name="<?php echo esc_attr((string) ($textarea['name'] ?? '')); ?>"
                        class="aipkit_cw_prompt_inline_textarea aipkit_autosave_trigger"
                        rows="6"
                        placeholder="<?php echo esc_attr((string) ($textarea['placeholder'] ?? '')); ?>"
                    ><?php echo esc_textarea((string) ($textarea['value'] ?? '')); ?></textarea>
                    <button
                        type="button"
                        class="aipkit_builder_icon_btn aipkit_builder_textarea_expand aipkit_cw_prompt_expand"
                        data-aipkit-cw-prompt-expand
                        aria-haspopup="dialog"
                        aria-controls="aipkit_cw_prompt_editor_modal"
                        aria-expanded="false"
                        aria-label="<?php esc_attr_e('Expand prompt editor', 'gpt3-ai-content-generator'); ?>"
                    >
                        <span class="dashicons dashicons-editor-expand" aria-hidden="true"></span>
                    </button>
                </div>
                <span class="aipkit_cw_prompt_variable_chips" data-prompt-type="<?php echo esc_attr((string) ($item['placeholders_prompt_type'] ?? $key)); ?>">
                    <?php foreach ($placeholders as $placeholder) : ?>
                        <?php
                        /* translators: %s: prompt variable placeholder, for example {topic}. */
                        $copy_aria_label = sprintf(__('Copy %s', 'gpt3-ai-content-generator'), (string) $placeholder);
                        ?>
                        <button
                            type="button"
                            class="aipkit_cw_prompt_variable_chip"
                            data-aipkit-prompt-variable="<?php echo esc_attr((string) $placeholder); ?>"
                            aria-label="<?php echo esc_attr($copy_aria_label); ?>"
                        ><?php echo esc_html((string) $placeholder); ?></button>
                    <?php endforeach; ?>
                </span>
                <div
                    id="<?php echo esc_attr($requirement_id); ?>"
                    class="aipkit_cw_prompt_requirement"
                    data-aipkit-cw-prompt-requirement
                    role="status"
                    aria-live="polite"
                    hidden
                ></div>
            </div>
        </div>
    </div>
    <?php
};
