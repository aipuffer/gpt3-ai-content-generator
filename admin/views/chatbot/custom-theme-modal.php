<?php
/**
 * Size and font: the side panel for the chat button's shape and size, the custom theme's font and corners,
 * and the chat window's size. Rows follow Answer style: presets, with Custom showing the saved number.
 * The custom theme script opens and closes it as a dialog; Light, Dark and ChatGPT keep their own font and
 * sizes, so those rows are switched off with a note. The custom color is set from the Brand color menu.
 */
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-local variables only.

use WPAICG\Chat\Storage\BotSettingsManager;

$custom_theme_defaults = BotSettingsManager::get_custom_theme_defaults();
$get_cts_val = static function (string $key) use ($bot_settings, $custom_theme_defaults) {
    $custom_settings = $bot_settings['custom_theme_settings'] ?? [];
    return $custom_settings[$key] ?? ($custom_theme_defaults[$key] ?? '');
};
$esc_cts_val_attr = static function (string $key) use ($get_cts_val): string {
    return esc_attr((string) $get_cts_val($key));
};
$font_families = [
    __('Inherit from page', 'gpt3-ai-content-generator') => 'inherit',
    __('System', 'gpt3-ai-content-generator') => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif',
    'Arial' => 'Arial, Helvetica, sans-serif',
    'Verdana' => 'Verdana, Geneva, sans-serif',
    'Tahoma' => 'Tahoma, Geneva, sans-serif',
    'Trebuchet MS' => '"Trebuchet MS", Helvetica, sans-serif',
    'Times New Roman' => '"Times New Roman", Times, serif',
    'Georgia' => 'Georgia, serif',
    'Garamond' => 'Garamond, serif',
    'Courier New' => '"Courier New", Courier, monospace',
    'Brush Script MT' => '"Brush Script MT", cursive',
];
// Presets for the custom theme's numbers; any other saved number shows as Custom with its field.
$aipkit_size_presets = [
    'bubble_border_radius' => [
        'title' => __('Corners', 'gpt3-ai-content-generator'),
        'hint' => __('How round the window and messages are.', 'gpt3-ai-content-generator'),
        'unit' => 'px', 'min' => 0, 'max' => 50, 'placement' => '',
        'options' => ['4' => __('Sharp', 'gpt3-ai-content-generator'), '10' => __('Soft', 'gpt3-ai-content-generator'), '16' => __('Round', 'gpt3-ai-content-generator')],
    ],
    'popup_width' => [
        'title' => __('Width', 'gpt3-ai-content-generator'),
        'hint' => __('On larger screens. Phones use the full width.', 'gpt3-ai-content-generator'),
        'unit' => 'px', 'min' => 200, 'max' => 1000, 'placement' => 'popup',
        'options' => ['340' => __('Narrow', 'gpt3-ai-content-generator'), '380' => __('Standard', 'gpt3-ai-content-generator'), '440' => __('Wide', 'gpt3-ai-content-generator')],
    ],
    'container_max_width' => [
        'title' => __('Width', 'gpt3-ai-content-generator'),
        'hint' => __('The widest it gets inside your page.', 'gpt3-ai-content-generator'),
        'unit' => 'px', 'min' => 200, 'max' => 1200, 'placement' => 'inline',
        'options' => ['640' => __('Narrow', 'gpt3-ai-content-generator'), '896' => __('Standard', 'gpt3-ai-content-generator'), '1200' => __('Wide', 'gpt3-ai-content-generator')],
    ],
    'container_height' => [
        'title' => __('Height', 'gpt3-ai-content-generator'),
        'hint' => __('How tall it opens.', 'gpt3-ai-content-generator'),
        'unit' => 'px', 'min' => 100, 'max' => 1000, 'placement' => '',
        'options' => ['520' => __('Short', 'gpt3-ai-content-generator'), '620' => __('Standard', 'gpt3-ai-content-generator'), '720' => __('Tall', 'gpt3-ai-content-generator')],
    ],
];
$aipkit_icon_style_options = [
    'circle' => __('Circle', 'gpt3-ai-content-generator'),
    'square' => __('Square', 'gpt3-ai-content-generator'),
    'none' => __('Icon only', 'gpt3-ai-content-generator'),
];
$aipkit_icon_size_options = [
    'small' => __('Small', 'gpt3-ai-content-generator'),
    'medium' => __('Medium', 'gpt3-ai-content-generator'),
    'large' => __('Large', 'gpt3-ai-content-generator'),
    'xlarge' => __('Extra large', 'gpt3-ai-content-generator'),
];
$aipkit_panel_popup_hidden = !empty($quick_popup_enabled) ? '' : ' hidden';
$aipkit_panel_inline_hidden = !empty($quick_popup_enabled) ? ' hidden' : '';
$render_panel_segmented = static function (string $field, string $label_id, array $options, string $value) use ($bot_id): void {
    ?>
    <div class="aipkit_segmented" role="group" aria-labelledby="<?php echo esc_attr($label_id); ?>" data-aipkit-segmented-for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_<?php echo esc_attr($field); ?>">
        <?php foreach ($options as $option_value => $option_label) : ?>
            <button type="button" class="aipkit_segmented_option" data-value="<?php echo esc_attr($option_value); ?>" aria-pressed="<?php echo $value === (string) $option_value ? 'true' : 'false'; ?>"><?php echo esc_html($option_label); ?></button>
        <?php endforeach; ?>
    </div>
    <select id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_<?php echo esc_attr($field); ?>" name="<?php echo esc_attr($field); ?>" class="aipkit_look_saved_field" aria-hidden="true" tabindex="-1" hidden>
        <?php foreach ($options as $option_value => $option_label) : ?>
            <option value="<?php echo esc_attr($option_value); ?>" <?php selected($value, (string) $option_value); ?>><?php echo esc_html($option_label); ?></option>
        <?php endforeach; ?>
    </select>
    <?php
};
// A custom theme number as presets plus Custom; the number field is the saved field.
$render_size_preset_row = static function (string $key, array $row) use ($bot_id, $get_cts_val, $aipkit_panel_popup_hidden, $aipkit_panel_inline_hidden): void {
    $field_id = 'cts_' . $key . '_' . $bot_id;
    $label_id = $field_id . '_label';
    $placement_attributes = $row['placement'] === 'popup'
        ? ' data-aipkit-popup-only-control' . $aipkit_panel_popup_hidden
        : ($row['placement'] === 'inline' ? ' data-aipkit-inline-only-control' . $aipkit_panel_inline_hidden : '');
    ?>
    <div class="aipkit_answer_style_row aipkit_answer_style_row--inline"<?php echo $placement_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed attributes. ?>>
        <div class="aipkit_answer_style_copy">
            <span class="aipkit_answer_style_title" id="<?php echo esc_attr($label_id); ?>"><?php echo esc_html($row['title']); ?></span>
            <span class="aipkit_answer_style_hint"><?php echo esc_html($row['hint']); ?></span>
        </div>
        <div class="aipkit_answer_style_control">
            <div class="aipkit_segmented" role="group" aria-labelledby="<?php echo esc_attr($label_id); ?>" data-aipkit-segmented-for="<?php echo esc_attr($field_id); ?>">
                <?php foreach ($row['options'] as $option_value => $option_label) : ?>
                    <button type="button" class="aipkit_segmented_option" data-value="<?php echo esc_attr((string) $option_value); ?>" aria-pressed="false"><?php echo esc_html($option_label); ?></button>
                <?php endforeach; ?>
                <button type="button" class="aipkit_segmented_option" data-custom aria-pressed="false"><?php esc_html_e('Custom', 'gpt3-ai-content-generator'); ?></button>
            </div>
            <label class="aipkit_answer_style_custom" data-aipkit-segmented-custom-field hidden>
                <span class="screen-reader-text"><?php echo esc_html($row['title']); ?></span>
                <input
                    type="number"
                    id="<?php echo esc_attr($field_id); ?>"
                    name="custom_theme_settings[<?php echo esc_attr($key); ?>]"
                    min="<?php echo esc_attr((string) $row['min']); ?>"
                    max="<?php echo esc_attr((string) $row['max']); ?>"
                    step="1"
                    value="<?php echo esc_attr((string) $get_cts_val($key)); ?>"
                />
                <span class="aipkit_answer_style_custom_hint" aria-hidden="true"><?php echo esc_html($row['unit']); ?></span>
            </label>
        </div>
    </div>
    <?php
};
?>

<div
    class="aipkit-modal-overlay aipkit_custom_theme_modal"
    id="aipkit_custom_theme_modal"
    aria-hidden="true"
>
    <div
        class="aipkit_custom_theme_modal_content"
        role="dialog"
        aria-modal="true"
        aria-labelledby="aipkit_custom_theme_modal_title"
    >
        <div class="aipkit_custom_theme_modal_header">
            <div class="aipkit_custom_theme_modal_heading">
                <h2 class="aipkit_custom_theme_modal_title" id="aipkit_custom_theme_modal_title">
                    <?php esc_html_e('Size and font', 'gpt3-ai-content-generator'); ?>
                </h2>
                <p class="aipkit_custom_theme_modal_hint"><?php esc_html_e('Fine details. The defaults suit most sites.', 'gpt3-ai-content-generator'); ?></p>
            </div>
            <button
                type="button"
                class="aipkit-modal-close-btn aipkit_custom_theme_modal_close"
                aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"
            >
                <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
            </button>
        </div>

        <div class="aipkit_custom_theme_modal_body">
            <section class="aipkit_custom_theme_group" aria-labelledby="aipkit_size_group_button" data-aipkit-popup-only-control<?php echo $aipkit_panel_popup_hidden; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A fixed attribute. ?>>
                <h3 class="aipkit_answer_style_group_title" id="aipkit_size_group_button"><?php esc_html_e('Chat button', 'gpt3-ai-content-generator'); ?></h3>
                <div class="aipkit_answer_style_row aipkit_answer_style_row--inline">
                    <span class="aipkit_answer_style_title" id="aipkit_look_icon_style_title"><?php esc_html_e('Shape', 'gpt3-ai-content-generator'); ?></span>
                    <div class="aipkit_answer_style_control"><?php $render_panel_segmented('popup_icon_style', 'aipkit_look_icon_style_title', $aipkit_icon_style_options, (string) $popup_icon_style); ?></div>
                </div>
                <div class="aipkit_answer_style_row aipkit_answer_style_row--inline">
                    <span class="aipkit_answer_style_title" id="aipkit_look_icon_size_title"><?php esc_html_e('Size', 'gpt3-ai-content-generator'); ?></span>
                    <div class="aipkit_answer_style_control"><?php $render_panel_segmented('popup_icon_size', 'aipkit_look_icon_size_title', $aipkit_icon_size_options, (string) $popup_icon_size); ?></div>
                </div>
            </section>

            <p class="aipkit_custom_theme_builtin_note" data-aipkit-theme-builtin-note hidden>
                <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                <span><?php esc_html_e('Light, Dark and ChatGPT keep their own font and size. Pick another color under Brand to change them.', 'gpt3-ai-content-generator'); ?></span>
            </p>

            <div
                class="aipkit_custom_theme_settings_container"
                id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_custom_theme_settings_container"
                data-defaults="<?php echo esc_attr(wp_json_encode($custom_theme_defaults)); ?>"
            >
                <?php // Colors are chosen under Brand; they are saved with the rest of the custom theme. ?>
                <?php foreach (['primary_color', 'secondary_color', 'accent_color'] as $color_key) : ?>
                    <input type="hidden" name="custom_theme_settings[<?php echo esc_attr($color_key); ?>]" value="<?php echo $esc_cts_val_attr($color_key); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
                <?php endforeach; ?>

                <fieldset class="aipkit_custom_theme_group" data-aipkit-theme-fields>
                    <legend class="aipkit_answer_style_group_title"><?php esc_html_e('Text', 'gpt3-ai-content-generator'); ?></legend>
                    <div class="aipkit_answer_style_row aipkit_answer_style_row--inline">
                        <div class="aipkit_answer_style_copy">
                            <label class="aipkit_answer_style_title" for="cts_font_family_<?php echo esc_attr($bot_id); ?>"><?php esc_html_e('Font', 'gpt3-ai-content-generator'); ?></label>
                            <span class="aipkit_answer_style_hint"><?php esc_html_e('Inherit uses your site\'s font.', 'gpt3-ai-content-generator'); ?></span>
                        </div>
                        <select id="cts_font_family_<?php echo esc_attr($bot_id); ?>" name="custom_theme_settings[font_family]" class="aipkit_look_select">
                            <?php foreach ($font_families as $name => $stack) : ?>
                                <option value="<?php echo esc_attr($stack); ?>" <?php selected($get_cts_val('font_family'), $stack); ?>><?php echo esc_html($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php $render_size_preset_row('bubble_border_radius', $aipkit_size_presets['bubble_border_radius']); ?>
                </fieldset>

                <fieldset class="aipkit_custom_theme_group" data-aipkit-theme-fields>
                    <legend class="aipkit_answer_style_group_title"><?php esc_html_e('Chat window', 'gpt3-ai-content-generator'); ?></legend>
                    <?php
                    foreach (['popup_width', 'container_max_width', 'container_height'] as $preset_key) {
                        $render_size_preset_row($preset_key, $aipkit_size_presets[$preset_key]);
                    }
                    ?>
                    <div class="aipkit_answer_style_row aipkit_answer_style_row--inline">
                        <div class="aipkit_answer_style_copy">
                            <span class="aipkit_answer_style_title"><?php esc_html_e('Fit small screens', 'gpt3-ai-content-generator'); ?></span>
                            <span class="aipkit_answer_style_hint"><?php esc_html_e('Keeps the window inside the screen.', 'gpt3-ai-content-generator'); ?></span>
                        </div>
                        <?php // Two limits on one aligned grid: label, number, unit. ?>
                        <div class="aipkit_answer_style_control aipkit_size_fit">
                            <label class="aipkit_answer_style_custom">
                                <span class="aipkit_answer_style_custom_hint"><?php esc_html_e('At most', 'gpt3-ai-content-generator'); ?></span>
                                <input type="number" id="cts_container_max_height_<?php echo esc_attr($bot_id); ?>" name="custom_theme_settings[container_max_height]" min="10" max="100" step="1" value="<?php echo $esc_cts_val_attr('container_max_height'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
                                <span class="aipkit_answer_style_custom_hint"><?php esc_html_e('% of screen', 'gpt3-ai-content-generator'); ?></span>
                            </label>
                            <label class="aipkit_answer_style_custom">
                                <span class="aipkit_answer_style_custom_hint"><?php esc_html_e('At least', 'gpt3-ai-content-generator'); ?></span>
                                <input type="number" id="cts_container_min_height_<?php echo esc_attr($bot_id); ?>" name="custom_theme_settings[container_min_height]" min="50" max="800" step="1" value="<?php echo $esc_cts_val_attr('container_min_height'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
                                <span class="aipkit_answer_style_custom_hint">px</span>
                            </label>
                        </div>
                    </div>
                </fieldset>

                <div class="aipkit_custom_theme_reset_row">
                    <span
                        id="aipkit_reset_theme_status_<?php echo esc_attr($bot_id); ?>"
                        class="aipkit_custom_theme_reset_status"
                        data-base-class="aipkit_custom_theme_reset_status"
                        aria-live="polite"
                    ></span>
                    <button
                        type="button"
                        class="aipkit_reset_custom_theme_btn"
                        data-bot-id="<?php echo esc_attr($bot_id); ?>"
                        data-success-message="<?php esc_attr_e('Defaults restored.', 'gpt3-ai-content-generator'); ?>"
                        data-error-message="<?php esc_attr_e('Could not restore defaults.', 'gpt3-ai-content-generator'); ?>"
                    >
                        <?php esc_html_e('Reset to defaults', 'gpt3-ai-content-generator'); ?>
                    </button>
                </div>
            </div>
        </div>

        <div class="aipkit_custom_theme_modal_footer">
            <button type="button" class="aipkit_btn aipkit_btn-primary" data-aipkit-theme-panel-done><?php esc_html_e('Done', 'gpt3-ai-content-generator'); ?></button>
        </div>
    </div>
</div>
