<?php
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

$reasoning_options = [
    'none' => __('None', 'gpt3-ai-content-generator'),
    'low' => __('Low', 'gpt3-ai-content-generator'),
    'medium' => __('Medium', 'gpt3-ai-content-generator'),
    'high' => __('High', 'gpt3-ai-content-generator'),
    'xhigh' => __('XHigh', 'gpt3-ai-content-generator'),
    'max' => __('Max', 'gpt3-ai-content-generator'),
];
?>

<div class="aipkit_popover_options_list aipkit_cw_advanced_options_list">
    <div class="aipkit_popover_option_row">
        <div class="aipkit_popover_option_main">
            <div class="aipkit_cw_settings_option_text">
                <label class="aipkit_popover_option_label" for="aipkit_content_writer_temperature">
                    <?php esc_html_e('Temperature', 'gpt3-ai-content-generator'); ?>
                </label>
            </div>
            <input
                type="number"
                id="aipkit_content_writer_temperature"
                name="ai_temperature"
                class="aipkit_form-input aipkit_autosave_trigger aipkit_popover_option_input aipkit_cw_advanced_options_control"
                min="0"
                max="2"
                step="0.1"
                value="<?php echo esc_attr($default_temperature); ?>"
                inputmode="decimal"
            />
        </div>
    </div>

    <div class="aipkit_popover_option_row aipkit_cw_reasoning_effort_field" hidden>
        <div class="aipkit_popover_option_main">
            <div class="aipkit_cw_settings_option_text">
                <label class="aipkit_popover_option_label" for="aipkit_content_writer_reasoning_effort">
                    <?php esc_html_e('Reasoning', 'gpt3-ai-content-generator'); ?>
                </label>
            </div>
            <select
                id="aipkit_content_writer_reasoning_effort"
                name="reasoning_effort"
                class="aipkit_form-input aipkit_autosave_trigger aipkit_popover_option_select aipkit_cw_advanced_options_control"
            >
                <?php foreach ($reasoning_options as $reasoning_value => $reasoning_label) : ?>
                    <option value="<?php echo esc_attr($reasoning_value); ?>" <?php selected($reasoning_value, 'none'); ?>>
                        <?php echo esc_html($reasoning_label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</div>
