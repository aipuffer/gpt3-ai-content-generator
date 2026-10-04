<?php
/**
 * Partial: Chatbot Custom Theme Modal.
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
$dimension_fields = [
    'container_max_width' => [__('Inline max width', 'gpt3-ai-content-generator'), 200, 1200, 1, 'px'],
    'popup_width' => [__('Popup width', 'gpt3-ai-content-generator'), 200, 1000, 10, 'px'],
    'container_height' => [__('Initial height', 'gpt3-ai-content-generator'), 100, 1000, 10, 'px'],
    'container_min_height' => [__('Min height', 'gpt3-ai-content-generator'), 50, 800, 10, 'px'],
    'container_max_height' => [__('Max height', 'gpt3-ai-content-generator'), 10, 100, 1, '%'],
];
?>

<div
    class="aipkit-modal-overlay aipkit_custom_theme_modal"
    id="aipkit_custom_theme_modal"
    aria-hidden="true"
>
    <div
        class="aipkit-modal-content aipkit-modal-shell aipkit_custom_theme_modal_content"
        role="dialog"
        aria-modal="true"
        aria-labelledby="aipkit_custom_theme_modal_title"
    >
        <div class="aipkit-modal-header aipkit-modal-shell-header aipkit_custom_theme_modal_header">
            <h2 class="aipkit-modal-title aipkit-modal-shell-title" id="aipkit_custom_theme_modal_title">
                <?php esc_html_e('Custom theme', 'gpt3-ai-content-generator'); ?>
            </h2>
            <button
                type="button"
                class="aipkit-modal-close-btn aipkit-modal-shell-close aipkit_custom_theme_modal_close"
                aria-label="<?php esc_attr_e('Close custom theme settings', 'gpt3-ai-content-generator'); ?>"
            >
                <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
            </button>
        </div>

        <div class="aipkit-modal-body aipkit_custom_theme_modal_body">
            <div
                class="aipkit_custom_theme_settings_container"
                id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_custom_theme_settings_container"
                data-defaults="<?php echo esc_attr(wp_json_encode($custom_theme_defaults)); ?>"
            >
                <input
                    type="hidden"
                    name="custom_theme_settings[secondary_color]"
                    value="<?php echo $esc_cts_val_attr('secondary_color'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
                >
                <input
                    type="hidden"
                    name="custom_theme_settings[accent_color]"
                    value="<?php echo $esc_cts_val_attr('accent_color'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
                >

                <section class="aipkit_custom_theme_section" aria-labelledby="aipkit_custom_theme_appearance_title">
                    <h3 class="aipkit_custom_theme_section_title" id="aipkit_custom_theme_appearance_title">
                        <?php esc_html_e('Appearance', 'gpt3-ai-content-generator'); ?>
                    </h3>

                    <div class="aipkit_custom_theme_field aipkit_custom_theme_field--accent">
                        <div class="aipkit_custom_theme_field_copy">
                            <label class="aipkit_custom_theme_field_label" for="cts_primary_color_<?php echo esc_attr($bot_id); ?>">
                                <?php esc_html_e('Accent color', 'gpt3-ai-content-generator'); ?>
                            </label>
                            <span class="aipkit_custom_theme_field_help">
                                <?php esc_html_e('Header, launcher, send button, and user messages.', 'gpt3-ai-content-generator'); ?>
                            </span>
                        </div>
                        <div class="aipkit_custom_theme_color_control">
                            <input
                                type="color"
                                id="cts_primary_color_<?php echo esc_attr($bot_id); ?>"
                                name="custom_theme_settings[primary_color]"
                                class="aipkit_form-input aipkit_color_picker_input aipkit_custom_theme_color_picker"
                                value="<?php echo $esc_cts_val_attr('primary_color'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
                            >
                            <input
                                type="text"
                                class="aipkit_custom_theme_hex_input"
                                data-aipkit-custom-theme-hex
                                value="<?php echo $esc_cts_val_attr('primary_color'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
                                maxlength="7"
                                pattern="#[0-9A-Fa-f]{6}"
                                aria-label="<?php esc_attr_e('Accent color hex value', 'gpt3-ai-content-generator'); ?>"
                                autocomplete="off"
                                spellcheck="false"
                            >
                        </div>
                    </div>

                    <div class="aipkit_custom_theme_field">
                        <label class="aipkit_custom_theme_field_label" for="cts_font_family_<?php echo esc_attr($bot_id); ?>">
                            <?php esc_html_e('Font family', 'gpt3-ai-content-generator'); ?>
                        </label>
                        <select
                            id="cts_font_family_<?php echo esc_attr($bot_id); ?>"
                            name="custom_theme_settings[font_family]"
                            class="aipkit_popover_option_select aipkit_custom_theme_select"
                        >
                            <?php foreach ($font_families as $name => $stack) : ?>
                                <option value="<?php echo esc_attr($stack); ?>" <?php selected($get_cts_val('font_family'), $stack); ?>>
                                    <?php echo esc_html($name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="aipkit_custom_theme_field aipkit_custom_theme_field--slider aipkit_custom_theme_field--wide">
                        <div class="aipkit_custom_theme_slider_heading">
                            <label class="aipkit_custom_theme_field_label" for="cts_bubble_border_radius_<?php echo esc_attr($bot_id); ?>">
                                <?php esc_html_e('Bubble radius', 'gpt3-ai-content-generator'); ?>
                            </label>
                            <span id="cts_bubble_border_radius_<?php echo esc_attr($bot_id); ?>_value" class="aipkit_custom_theme_slider_value"></span>
                        </div>
                        <input
                            type="range"
                            id="cts_bubble_border_radius_<?php echo esc_attr($bot_id); ?>"
                            name="custom_theme_settings[bubble_border_radius]"
                            class="aipkit_form-input aipkit_range_slider aipkit_popover_slider aipkit_custom_theme_slider"
                            min="0"
                            max="50"
                            step="1"
                            data-suffix="px"
                            value="<?php echo $esc_cts_val_attr('bubble_border_radius'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
                        >
                    </div>
                </section>

                <section class="aipkit_custom_theme_section aipkit_custom_theme_section--sizing" aria-labelledby="aipkit_custom_theme_sizing_title">
                    <h3 class="aipkit_custom_theme_section_title" id="aipkit_custom_theme_sizing_title">
                        <?php esc_html_e('Sizing', 'gpt3-ai-content-generator'); ?>
                    </h3>
                    <div class="aipkit_custom_theme_sizing_grid">
                        <?php foreach ($dimension_fields as $key => [$label, $min, $max, $step, $suffix]) : ?>
                            <?php $field_id = 'cts_' . $key . '_' . $bot_id; ?>
                            <div class="aipkit_custom_theme_field aipkit_custom_theme_field--slider">
                                <div class="aipkit_custom_theme_slider_heading">
                                    <label class="aipkit_custom_theme_field_label" for="<?php echo esc_attr($field_id); ?>">
                                        <?php echo esc_html($label); ?>
                                    </label>
                                    <span id="<?php echo esc_attr($field_id); ?>_value" class="aipkit_custom_theme_slider_value"></span>
                                </div>
                                <input
                                    type="range"
                                    id="<?php echo esc_attr($field_id); ?>"
                                    name="custom_theme_settings[<?php echo esc_attr($key); ?>]"
                                    class="aipkit_form-input aipkit_range_slider aipkit_popover_slider aipkit_custom_theme_slider"
                                    min="<?php echo esc_attr($min); ?>"
                                    max="<?php echo esc_attr($max); ?>"
                                    step="<?php echo esc_attr($step); ?>"
                                    data-suffix="<?php echo esc_attr($suffix); ?>"
                                    value="<?php echo $esc_cts_val_attr($key); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
                                >
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <div class="aipkit_custom_theme_modal_footer">
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
    </div>
</div>
