<?php
/**
 * Automation schedule and publishing settings.
 */

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

$cw_post_statuses = isset($cw_post_statuses) && is_array($cw_post_statuses)
    ? $cw_post_statuses
    : [];
$aipkit_autogpt_post_status_labels = [
    'draft' => __('Save as draft', 'gpt3-ai-content-generator'),
    'publish' => __('Publish immediately', 'gpt3-ai-content-generator'),
    'pending' => __('Publish pending review', 'gpt3-ai-content-generator'),
    'private' => __('Save as private', 'gpt3-ai-content-generator'),
];
?>
<input type="hidden" id="aipkit_autogpt_task_status_input" name="task_status" value="active">

<div class="aipkit_cw_publishing_panel aipkit_post_settings_redesigned">
    <div class="aipkit_cw_publishing_row aipkit_autogpt_question_row aipkit_task_schedule_row">
        <div class="aipkit_cw_panel_label_wrap">
            <label class="aipkit_cw_panel_label aipkit_autogpt_question" for="aipkit_autogpt_task_status_toggle">
                <?php esc_html_e('Schedule', 'gpt3-ai-content-generator'); ?>
            </label>
            <span class="aipkit_autogpt_question_helper">
                <?php esc_html_e('Start this automation after creating it.', 'gpt3-ai-content-generator'); ?>
            </span>
        </div>
        <div class="aipkit_cw_publishing_row_actions aipkit_task_schedule_toggle_actions">
            <label class="aipkit_switch">
                <input
                    type="checkbox"
                    id="aipkit_autogpt_task_status_toggle"
                    class="aipkit_toggle_switch"
                    checked
                >
                <span class="aipkit_switch_slider"></span>
            </label>
        </div>
    </div>

    <?php
$frequencies = isset($frequencies) && is_array($frequencies) ? $frequencies : [];
$default_frequency = array_key_exists('daily', $frequencies)
    ? 'daily'
    : (array_key_first($frequencies) ?: '');
?>
<div class="aipkit_task_schedule_frequency">
    <div class="aipkit_cw_publishing_row aipkit_autogpt_question_row aipkit_task_schedule_frequency_row">
        <label class="aipkit_cw_panel_label aipkit_autogpt_question" for="aipkit_automated_task_frequency">
            <?php esc_html_e('How often should it run?', 'gpt3-ai-content-generator'); ?>
        </label>
        <div class="aipkit_cw_publishing_row_actions">
            <select id="aipkit_automated_task_frequency" name="task_frequency" class="aipkit_post_settings_select aipkit_form-input aipkit_cw_publishing_select aipkit_cw_blended_chevron_select aipkit_task_schedule_select aipkit_task_schedule_frequency_select">
            <?php foreach ($frequencies as $value => $label): ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($value, $default_frequency); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
            </select>
        </div>
    </div>
</div>


    <div class="aipkit_post_settings_chunk aipkit_post_settings_chunk--publishing" data-aipkit-task-publishing>
        <div class="aipkit_post_settings_chunk_body">
            <div class="aipkit_post_status_row aipkit_cw_publishing_row aipkit_autogpt_question_row">
                <div class="aipkit_cw_panel_label_wrap">
                    <label class="aipkit_cw_panel_label aipkit_autogpt_question" for="aipkit_task_cw_post_status">
                        <?php esc_html_e('Status', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <span class="aipkit_autogpt_question_helper">
                        <?php esc_html_e('Choose how generated posts are saved.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
                <div class="aipkit_cw_publishing_row_actions">
                    <select id="aipkit_task_cw_post_status" name="post_status" class="aipkit_post_settings_select aipkit_form-input aipkit_cw_publishing_select aipkit_cw_blended_chevron_select">
                        <?php foreach ($cw_post_statuses as $status_val => $status_label): ?>
                            <option value="<?php echo esc_attr($status_val); ?>" <?php selected($status_val, 'publish'); ?>><?php echo esc_html($aipkit_autogpt_post_status_labels[$status_val] ?? $status_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div id="aipkit_task_cw_schedule_options_wrapper" class="aipkit_post_smart_schedule_container aipkit_task_schedule_options" hidden>
                <div class="aipkit_cw_publishing_row aipkit_autogpt_question_row aipkit_task_publishing_timing_row">
                    <div class="aipkit_cw_panel_label_wrap">
                        <span id="aipkit_task_cw_publishing_timing_label" class="aipkit_cw_panel_label aipkit_autogpt_question">
                            <?php esc_html_e('Publishing timing', 'gpt3-ai-content-generator'); ?>
                        </span>
                        <span class="aipkit_autogpt_question_helper">
                            <?php esc_html_e('Choose when posts are published.', 'gpt3-ai-content-generator'); ?>
                        </span>
                    </div>
                    <div class="aipkit_cw_publishing_row_actions aipkit_task_publishing_timing_actions">
                        <div class="aipkit_post_smart_schedule_options" role="radiogroup" aria-labelledby="aipkit_task_cw_publishing_timing_label">
                            <label class="aipkit_post_schedule_radio">
                                <input type="radio" name="schedule_mode" value="immediate" checked>
                                <span class="aipkit_post_schedule_radio_text"><?php esc_html_e('Immediately', 'gpt3-ai-content-generator'); ?></span>
                            </label>
                            <label class="aipkit_post_schedule_radio">
                                <input type="radio" name="schedule_mode" value="smart">
                                <span class="aipkit_post_schedule_radio_text"><?php esc_html_e('Smart schedule', 'gpt3-ai-content-generator'); ?></span>
                            </label>
                            <label class="aipkit_post_schedule_radio aipkit_schedule_from_input_option aipkit_task_schedule_from_input_option">
                                <input type="radio" name="schedule_mode" value="from_input">
                                <span class="aipkit_post_schedule_radio_text"><?php esc_html_e('Input dates', 'gpt3-ai-content-generator'); ?></span>
                                <span
                                    class="aipkit_popover_warning is-visible"
                                    data-tooltip="<?php echo esc_attr__("Use when your input includes a publish date (Bulk/CSV or Google Sheets).\n\nAccepted formats: YYYY-MM-DD HH:MM[:SS], YYYY/MM/DD HH:MM, MM/DD/YYYY HH:MM, DD/MM/YYYY HH:MM, or ISO 8601.\n\nTimes use the site timezone unless an offset/Z is provided.", 'gpt3-ai-content-generator'); ?>"
                                    tabindex="0"
                                    aria-label="<?php echo esc_attr__('Show date format help', 'gpt3-ai-content-generator'); ?>"
                                >
                                    <span class="dashicons dashicons-info" aria-hidden="true"></span>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>

                <div id="aipkit_task_cw_smart_schedule_fields" class="aipkit_post_smart_schedule_fields" hidden>
                    <div class="aipkit_cw_publishing_row aipkit_autogpt_question_row aipkit_task_smart_schedule_row">
                        <div class="aipkit_cw_panel_label_wrap">
                            <label class="aipkit_cw_panel_label aipkit_autogpt_question" for="aipkit_task_cw_smart_schedule_start_datetime">
                                <?php esc_html_e('Start date/time', 'gpt3-ai-content-generator'); ?>
                            </label>
                            <span class="aipkit_autogpt_question_helper">
                                <?php esc_html_e('When scheduling begins.', 'gpt3-ai-content-generator'); ?>
                            </span>
                        </div>
                        <div class="aipkit_cw_publishing_row_actions">
                            <input type="datetime-local" id="aipkit_task_cw_smart_schedule_start_datetime" name="smart_schedule_start_datetime" class="aipkit_post_settings_input aipkit_form-input aipkit_cw_publishing_input">
                        </div>
                    </div>
                    <div class="aipkit_cw_publishing_row aipkit_autogpt_question_row aipkit_task_smart_schedule_row">
                        <div class="aipkit_cw_panel_label_wrap">
                            <label class="aipkit_cw_panel_label aipkit_autogpt_question" for="aipkit_task_cw_smart_schedule_interval_value">
                                <?php esc_html_e('Frequency', 'gpt3-ai-content-generator'); ?>
                            </label>
                            <span class="aipkit_autogpt_question_helper">
                                <?php esc_html_e('Publish one post every.', 'gpt3-ai-content-generator'); ?>
                            </span>
                        </div>
                        <div class="aipkit_cw_publishing_row_actions aipkit_task_schedule_interval_actions">
                            <div class="aipkit_post_smart_schedule_interval">
                                <div class="aipkit_autogpt_schedule_interval_stepper">
                                    <button type="button" data-aipkit-schedule-interval-step="-1" aria-label="<?php esc_attr_e('Use a shorter publishing interval', 'gpt3-ai-content-generator'); ?>">−</button>
                                    <input type="number" id="aipkit_task_cw_smart_schedule_interval_value" name="smart_schedule_interval_value" value="1" min="1" class="aipkit_post_settings_input aipkit_post_settings_input--number aipkit_form-input aipkit_cw_publishing_input aipkit_cw_publishing_input--number">
                                    <button type="button" data-aipkit-schedule-interval-step="1" aria-label="<?php esc_attr_e('Use a longer publishing interval', 'gpt3-ai-content-generator'); ?>">+</button>
                                </div>
                                <select id="aipkit_task_cw_smart_schedule_interval_unit" name="smart_schedule_interval_unit" class="aipkit_post_settings_select aipkit_form-input aipkit_cw_publishing_select aipkit_cw_blended_chevron_select">
                                    <option value="hours"><?php esc_html_e('Hours', 'gpt3-ai-content-generator'); ?></option>
                                    <option value="days"><?php esc_html_e('Days', 'gpt3-ai-content-generator'); ?></option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php
$cw_available_post_types = isset($cw_available_post_types) && is_array($cw_available_post_types) ? $cw_available_post_types : [];
$cw_users_for_author = isset($cw_users_for_author) && is_array($cw_users_for_author) ? $cw_users_for_author : [];
$cw_wp_categories = isset($cw_wp_categories) && is_array($cw_wp_categories) ? $cw_wp_categories : [];
$cw_current_user_id = isset($cw_current_user_id) ? (int) $cw_current_user_id : 0;
?>

<div
    class="aipkit_autogpt_post_settings_inline"
    data-aipkit-autogpt-schedule-section="content_writing"
    hidden
>
    <div class="aipkit_cw_publishing_row aipkit_autogpt_question_row aipkit_task_post_setting_row">
        <div class="aipkit_cw_panel_label_wrap">
            <label class="aipkit_cw_panel_label aipkit_autogpt_question" for="aipkit_task_cw_post_content_format">
                <?php esc_html_e('Content format', 'gpt3-ai-content-generator'); ?>
            </label>
        </div>
        <div class="aipkit_cw_publishing_row_actions">
            <select
                id="aipkit_task_cw_post_content_format"
                name="post_content_format"
                class="aipkit_post_settings_select aipkit_form-input aipkit_autosave_trigger aipkit_cw_publishing_select aipkit_cw_blended_chevron_select"
            >
                <option value="gutenberg"><?php esc_html_e('Gutenberg', 'gpt3-ai-content-generator'); ?></option>
                <option value="html"><?php esc_html_e('Classic', 'gpt3-ai-content-generator'); ?></option>
            </select>
        </div>
    </div>

    <div class="aipkit_cw_publishing_row aipkit_autogpt_question_row aipkit_task_post_setting_row">
        <div class="aipkit_cw_panel_label_wrap">
            <label class="aipkit_cw_panel_label aipkit_autogpt_question" for="aipkit_task_cw_post_type">
                <?php esc_html_e('Post Type', 'gpt3-ai-content-generator'); ?>
            </label>
        </div>
        <div class="aipkit_cw_publishing_row_actions">
            <select
                id="aipkit_task_cw_post_type"
                name="post_type"
                class="aipkit_post_settings_select aipkit_form-input aipkit_autosave_trigger aipkit_cw_publishing_select aipkit_cw_blended_chevron_select"
            >
                <?php foreach ($cw_available_post_types as $pt_slug => $pt_obj) : ?>
                    <option value="<?php echo esc_attr($pt_slug); ?>" <?php selected($pt_slug, 'post'); ?>>
                        <?php echo esc_html($pt_obj->label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="aipkit_cw_publishing_row aipkit_autogpt_question_row aipkit_task_post_setting_row">
        <div class="aipkit_cw_panel_label_wrap">
            <label class="aipkit_cw_panel_label aipkit_autogpt_question" for="aipkit_task_cw_post_author">
                <?php esc_html_e('Author', 'gpt3-ai-content-generator'); ?>
            </label>
        </div>
        <div class="aipkit_cw_publishing_row_actions">
            <select
                id="aipkit_task_cw_post_author"
                name="post_author"
                class="aipkit_post_settings_select aipkit_form-input aipkit_autosave_trigger aipkit_cw_publishing_select aipkit_cw_blended_chevron_select"
            >
                <?php foreach ($cw_users_for_author as $user) : ?>
                    <option value="<?php echo esc_attr($user->ID); ?>" <?php selected($user->ID, $cw_current_user_id); ?>>
                        <?php echo esc_html($user->display_name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="aipkit_cw_publishing_row aipkit_autogpt_question_row aipkit_task_post_setting_row">
        <div class="aipkit_cw_panel_label_wrap">
            <label class="aipkit_cw_panel_label aipkit_autogpt_question" for="aipkit_task_cw_post_categories">
                <?php esc_html_e('Categories', 'gpt3-ai-content-generator'); ?>
            </label>
        </div>
        <div class="aipkit_cw_publishing_row_actions">
            <?php
            $aipkit_category_dropdown_config = [
                'id'              => 'aipkit_task_cw_post_categories',
                'panel_id'        => 'aipkit_task_cw_categories_panel',
                'categories'      => $cw_wp_categories,
                'wrapper_classes' => 'aipkit_autogpt_finish_categories',
                'button_classes'  => 'aipkit_cw_blended_chevron_btn',
                'select_classes'  => 'aipkit_autosave_trigger',
            ];
            require WPAICG_PLUGIN_DIR . 'admin/views/shared/category-dropdown.php';
            ?>
        </div>
    </div>
</div>

</div>
