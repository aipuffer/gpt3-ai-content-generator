<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This partial only reads local template variables.
?>

<div class="aipkit_cw_ai_row aipkit_cw_core_status_row" data-aipkit-cw-core-status-row>
    <div class="aipkit_cw_panel_label_wrap">
        <label class="aipkit_cw_panel_label" for="aipkit_content_writer_post_status">
            <?php esc_html_e('Status', 'gpt3-ai-content-generator'); ?>
        </label>
    </div>
    <div class="aipkit_cw_ai_control aipkit_cw_ai_control--compact">
        <select
            id="aipkit_content_writer_post_status"
            name="post_status"
            class="aipkit_post_settings_select aipkit_form-input aipkit_cw_core_status_select aipkit_cw_blended_chevron_select aipkit_autosave_trigger"
            data-aipkit-cw-fit-selected
        >
            <?php foreach ($post_statuses as $status_val => $status_label): ?>
                <option value="<?php echo esc_attr($status_val); ?>" <?php selected($status_val, 'draft'); ?>>
                    <?php echo esc_html($status_label); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<div
    id="aipkit_cw_schedule_options_wrapper"
    class="aipkit_cw_schedule_setting"
    data-aipkit-cw-schedule
    hidden
>
    <button
        type="button"
        class="aipkit_cw_ai_row aipkit_cw_schedule_toggle"
        data-aipkit-cw-schedule-toggle
        aria-expanded="false"
        aria-controls="aipkit_cw_schedule_panel"
    >
        <span class="aipkit_cw_panel_label">
            <?php esc_html_e('Schedule', 'gpt3-ai-content-generator'); ?>
        </span>
        <span class="aipkit_cw_schedule_summary">
            <span data-aipkit-cw-schedule-value><?php esc_html_e('Publish immediately', 'gpt3-ai-content-generator'); ?></span>
            <span class="aipkit_cw_schedule_chevron" aria-hidden="true"></span>
        </span>
    </button>

    <div
        id="aipkit_cw_schedule_panel"
        class="aipkit_cw_schedule_panel"
        data-aipkit-cw-schedule-panel
        hidden
    >
        <div class="aipkit_post_smart_schedule_options">
            <label class="aipkit_post_schedule_radio">
                <input class="aipkit_autosave_trigger" type="radio" name="schedule_mode" value="immediate" checked>
                <span class="aipkit_post_schedule_radio_text"><?php esc_html_e('Publish immediately', 'gpt3-ai-content-generator'); ?></span>
            </label>
            <label class="aipkit_post_schedule_radio">
                <input class="aipkit_autosave_trigger" type="radio" name="schedule_mode" value="smart">
                <span class="aipkit_post_schedule_radio_text"><?php esc_html_e('Smart schedule', 'gpt3-ai-content-generator'); ?></span>
            </label>
            <label class="aipkit_post_schedule_radio aipkit_schedule_from_input_option">
                <input class="aipkit_autosave_trigger" type="radio" name="schedule_mode" value="from_input">
                <span class="aipkit_post_schedule_radio_text"><?php esc_html_e('Use dates from input', 'gpt3-ai-content-generator'); ?></span>
            </label>
        </div>

        <div id="aipkit_cw_smart_schedule_fields" class="aipkit_post_smart_schedule_fields" hidden>
            <div class="aipkit_post_smart_schedule_field">
                <label class="aipkit_cw_panel_label" for="aipkit_cw_smart_schedule_start_datetime">
                    <?php esc_html_e('Start date and time', 'gpt3-ai-content-generator'); ?>
                </label>
                <input
                    type="datetime-local"
                    id="aipkit_cw_smart_schedule_start_datetime"
                    name="smart_schedule_start_datetime"
                    class="aipkit_post_settings_input aipkit_form-input aipkit_cw_schedule_input aipkit_autosave_trigger"
                >
            </div>
            <div class="aipkit_post_smart_schedule_field">
                <label class="aipkit_cw_panel_label" for="aipkit_cw_smart_schedule_interval_value">
                    <?php esc_html_e('Publish one post every', 'gpt3-ai-content-generator'); ?>
                </label>
                <div class="aipkit_post_smart_schedule_interval">
                    <input
                        type="number"
                        id="aipkit_cw_smart_schedule_interval_value"
                        name="smart_schedule_interval_value"
                        value="1"
                        min="1"
                        class="aipkit_post_settings_input aipkit_post_settings_input--number aipkit_form-input aipkit_cw_schedule_input aipkit_cw_schedule_input--number aipkit_autosave_trigger"
                    >
                    <select
                        id="aipkit_cw_smart_schedule_interval_unit"
                        name="smart_schedule_interval_unit"
                        aria-label="<?php esc_attr_e('Interval unit', 'gpt3-ai-content-generator'); ?>"
                        class="aipkit_post_settings_select aipkit_form-input aipkit_cw_schedule_select aipkit_cw_blended_chevron_select aipkit_autosave_trigger"
                    >
                        <option value="hours"><?php esc_html_e('Hours', 'gpt3-ai-content-generator'); ?></option>
                        <option value="days"><?php esc_html_e('Days', 'gpt3-ai-content-generator'); ?></option>
                    </select>
                </div>
            </div>
        </div>

        <p class="aipkit_post_schedule_hint aipkit_schedule_from_input_help" hidden></p>
        <p class="aipkit_cw_schedule_validation" data-aipkit-cw-schedule-validation role="alert" hidden></p>
    </div>
</div>
