<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial with local view variables.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bot_id = isset($initial_active_bot_id) ? absint($initial_active_bot_id) : 0;
$saved_theme_key = isset($saved_theme)
    ? (string) $saved_theme
    : \WPAICG\Chat\Storage\BotSettingsManager::DEFAULT_THEME;
$selected_preset_key = isset($selected_theme_preset_key)
    ? sanitize_key((string) $selected_theme_preset_key)
    : (($saved_theme_key === 'custom')
        ? \WPAICG\Chat\Storage\BotSettingsManager::DEFAULT_THEME_PRESET_KEY
        : '');
$custom_theme_disabled = isset($aipkit_hide_custom_theme) ? (bool) $aipkit_hide_custom_theme : false;

$built_in_theme_colors = [
    'light' => [
        'primary' => '#ffffff',
        'secondary' => '#111111',
    ],
    'dark' => [
        'primary' => '#111111',
        'secondary' => '#006cff',
    ],
    'chatgpt' => [
        'primary' => '#10a37f',
        'secondary' => '#343541',
    ],
];

$widget_color_options = [];
$add_widget_color_option = function (
    string $theme_value,
    string $preset_key,
    string $label,
    string $primary,
    string $secondary,
    bool $is_custom_editor = false,
    bool $is_disabled = false
) use (&$widget_color_options): void {
    $signature = $theme_value . '|' . $preset_key;
    if ($label === '' || isset($widget_color_options[$signature])) {
        return;
    }

    $icon_color = static function (string $color): string {
        $hex = ltrim(trim($color), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return '#ffffff';
        }
        $red = hexdec(substr($hex, 0, 2));
        $green = hexdec(substr($hex, 2, 2));
        $blue = hexdec(substr($hex, 4, 2));
        $brightness = (($red * 299) + ($green * 587) + ($blue * 114)) / 1000;
        return $brightness > 190 ? '#111111' : '#ffffff';
    };
    $primary_color = $primary !== '' ? $primary : '#0B5FFF';

    $widget_color_options[$signature] = [
        'signature' => $signature,
        'theme' => $theme_value,
        'preset' => $preset_key,
        'label' => $label,
        'primary' => $primary_color,
        'secondary' => $secondary !== '' ? $secondary : '#F1F5FF',
        'icon_color' => $icon_color($primary_color),
        'is_custom_editor' => $is_custom_editor,
        'disabled' => $is_disabled,
    ];
};

foreach ($available_themes as $theme_key => $theme_name) {
    $theme_key = (string) $theme_key;
    if ($theme_key === 'custom') {
        continue;
    }

    $theme_colors = $built_in_theme_colors[$theme_key] ?? [
        'primary' => '#0B5FFF',
        'secondary' => '#F1F5FF',
    ];

    $add_widget_color_option(
        $theme_key,
        '',
        (string) $theme_name,
        $theme_colors['primary'],
        $theme_colors['secondary']
    );
}

if (isset($available_themes['custom']) && !empty($custom_theme_presets)) {
    foreach ($custom_theme_presets as $preset) {
        if (!is_array($preset)) {
            continue;
        }

        $preset_key = isset($preset['key']) ? sanitize_key((string) $preset['key']) : '';
        $preset_label = isset($preset['label']) ? (string) $preset['label'] : '';
        if ($preset_key === '' || $preset_label === '') {
            continue;
        }

        $add_widget_color_option(
            'custom',
            $preset_key,
            $preset_label,
            isset($preset['primary']) ? (string) $preset['primary'] : '',
            isset($preset['secondary']) ? (string) $preset['secondary'] : '',
            false,
            $custom_theme_disabled
        );
    }
}

if (isset($available_themes['custom'])) {
    $custom_theme_defaults = class_exists(\WPAICG\Chat\Storage\BotSettingsManager::class)
        ? \WPAICG\Chat\Storage\BotSettingsManager::get_custom_theme_defaults()
        : [];
    $active_custom_settings = isset($active_bot_settings['custom_theme_settings']) && is_array($active_bot_settings['custom_theme_settings'])
        ? $active_bot_settings['custom_theme_settings']
        : [];
    $custom_primary = isset($active_custom_settings['primary_color'])
        ? (string) $active_custom_settings['primary_color']
        : (string) ($custom_theme_defaults['primary_color'] ?? '#0B5FFF');
    $custom_secondary = isset($active_custom_settings['secondary_color'])
        ? (string) $active_custom_settings['secondary_color']
        : (string) ($custom_theme_defaults['secondary_color'] ?? '#F1F5FF');

    $add_widget_color_option(
        'custom',
        '',
        (string) $available_themes['custom'],
        $custom_primary,
        $custom_secondary,
        true,
        $custom_theme_disabled
    );
}

$preferred_color_signatures = [
    'custom|ocean',
    'dark|',
    'light|',
    'custom|lagoon',
    'custom|ember',
    'chatgpt|',
];

$ordered_widget_color_options = [];
foreach ($preferred_color_signatures as $preferred_signature) {
    if (isset($widget_color_options[$preferred_signature])) {
        $ordered_widget_color_options[$preferred_signature] = $widget_color_options[$preferred_signature];
    }
}
foreach ($widget_color_options as $signature => $option) {
    if (!isset($ordered_widget_color_options[$signature])) {
        $ordered_widget_color_options[$signature] = $option;
    }
}

$selected_color_signature = $saved_theme_key === 'custom'
    ? 'custom|' . $selected_preset_key
    : $saved_theme_key . '|';

$visible_widget_color_options = [];
foreach ($ordered_widget_color_options as $signature => $option) {
    if (!empty($option['is_custom_editor'])) {
        continue;
    }
    $visible_widget_color_options[$signature] = $option;
    if (count($visible_widget_color_options) >= 5) {
        break;
    }
}

if (
    isset($ordered_widget_color_options[$selected_color_signature]) &&
    empty($ordered_widget_color_options[$selected_color_signature]['is_custom_editor']) &&
    !isset($visible_widget_color_options[$selected_color_signature])
) {
    $visible_color_signatures = array_keys($visible_widget_color_options);
    $last_visible_signature = end($visible_color_signatures);
    if ($last_visible_signature !== false) {
        unset($visible_widget_color_options[$last_visible_signature]);
    }
    $priority_visible_widget_color_options = [];
    foreach (['custom|ocean', 'dark|', 'light|'] as $priority_signature) {
        if (isset($visible_widget_color_options[$priority_signature])) {
            $priority_visible_widget_color_options[$priority_signature] = $visible_widget_color_options[$priority_signature];
            unset($visible_widget_color_options[$priority_signature]);
        }
    }
    $visible_widget_color_options = $priority_visible_widget_color_options
        + [$selected_color_signature => $ordered_widget_color_options[$selected_color_signature]]
        + $visible_widget_color_options;
}

$more_widget_color_options = [];
foreach ($ordered_widget_color_options as $signature => $option) {
    if (!isset($visible_widget_color_options[$signature])) {
        $more_widget_color_options[$signature] = $option;
    }
}

$render_widget_color_radio = static function (array $option, string $variant) use ($bot_id, $selected_color_signature): void {
    $is_selected = $option['signature'] === $selected_color_signature;
    $is_disabled = !empty($option['disabled']);
    $swatch_classes = 'aipkit_widget_color_swatch';
    if ($option['theme'] === 'light' && $option['preset'] === '') {
        $swatch_classes .= ' aipkit_widget_color_swatch--light';
    }
    ?>
    <input
        type="radio"
        class="aipkit_interface_theme_radio"
        name="aipkit_theme_choice_<?php echo esc_attr($bot_id); ?>"
        value="<?php echo esc_attr($option['theme']); ?>"
        data-theme-value="<?php echo esc_attr($option['theme']); ?>"
        data-preset-key="<?php echo esc_attr($option['preset']); ?>"
        data-primary="<?php echo esc_attr($option['primary']); ?>"
        data-icon-color="<?php echo esc_attr($option['icon_color']); ?>"
        <?php checked($is_selected, true); ?>
        <?php disabled($is_disabled); ?>
    />
    <span
        class="<?php echo esc_attr($swatch_classes); ?>"
        style="--aipkit-widget-color-primary: <?php echo esc_attr($option['primary']); ?>;"
        aria-hidden="true"
    ></span>
    <?php if ($variant === 'menu') : ?>
        <span class="aipkit_popover_multiselect_text"><?php echo esc_html($option['label']); ?></span>
    <?php endif; ?>
    <?php
};
?>

<div class="aipkit_widget_color_block">
    <span class="aipkit_widget_designer_label"><?php esc_html_e('Widget color', 'gpt3-ai-content-generator'); ?></span>
    <div
        class="aipkit_popover_multiselect aipkit_interface_theme_dropdown aipkit_widget_color_picker"
        data-aipkit-theme-dropdown
        data-placeholder="<?php echo esc_attr__('Select color', 'gpt3-ai-content-generator'); ?>"
    >
        <div class="aipkit_widget_color_choices" role="radiogroup" aria-label="<?php esc_attr_e('Widget color', 'gpt3-ai-content-generator'); ?>">
            <?php foreach ($visible_widget_color_options as $option) : ?>
                <label class="aipkit_widget_color_choice" title="<?php echo esc_attr($option['label']); ?>">
                    <?php $render_widget_color_radio($option, 'swatch'); ?>
                    <span class="screen-reader-text"><?php echo esc_html($option['label']); ?></span>
                </label>
            <?php endforeach; ?>
            <?php if (!empty($more_widget_color_options)) : ?>
                <div class="aipkit_widget_color_more">
                    <button
                        type="button"
                        id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_theme_dropdown_btn"
                        class="aipkit_popover_multiselect_btn aipkit_widget_color_more_btn"
                        aria-expanded="false"
                        aria-controls="aipkit_bot_<?php echo esc_attr($bot_id); ?>_theme_dropdown_panel"
                        title="<?php esc_attr_e('More colors', 'gpt3-ai-content-generator'); ?>"
                    >
                        <span class="aipkit_popover_multiselect_label screen-reader-text">
                            <?php esc_html_e('More colors', 'gpt3-ai-content-generator'); ?>
                        </span>
                        <span class="aipkit_widget_color_more_icon" aria-hidden="true">+</span>
                    </button>
                    <div
                        id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_theme_dropdown_panel"
                        class="aipkit_popover_multiselect_panel aipkit_interface_theme_panel aipkit_widget_color_more_panel"
                        role="menu"
                        hidden
                    >
                        <div class="aipkit_popover_multiselect_options aipkit_popover_multiselect_options--unbounded aipkit_interface_theme_options aipkit_widget_color_menu_options">
                            <?php foreach ($more_widget_color_options as $option) : ?>
                                <?php if (!empty($option['is_custom_editor'])) : ?>
                                    <div class="aipkit_popover_multiselect_item aipkit_interface_theme_item aipkit_interface_theme_item--custom aipkit_widget_color_menu_item aipkit_widget_color_menu_item--custom">
                                        <label class="aipkit_widget_color_menu_item_main">
                                            <?php $render_widget_color_radio($option, 'menu'); ?>
                                        </label>
                                        <span class="aipkit_widget_color_menu_trailing">
                                            <span class="aipkit_widget_color_menu_check dashicons dashicons-yes" aria-hidden="true"></span>
                                            <button
                                                type="button"
                                                class="aipkit_popover_option_btn aipkit_theme_config_btn aipkit_theme_config_btn--inline"
                                                aria-expanded="false"
                                                aria-controls="aipkit_custom_theme_modal"
                                                aria-label="<?php esc_attr_e('Edit custom theme', 'gpt3-ai-content-generator'); ?>"
                                                data-aipkit-theme-custom-edit
                                                title="<?php esc_attr_e('Edit custom theme', 'gpt3-ai-content-generator'); ?>"
                                                <?php echo $custom_theme_disabled ? 'hidden' : ''; ?>
                                                <?php disabled($custom_theme_disabled); ?>
                                            >
                                                <span class="dashicons dashicons-edit" aria-hidden="true"></span>
                                            </button>
                                        </span>
                                    </div>
                                <?php else : ?>
                                    <label class="aipkit_popover_multiselect_item aipkit_interface_theme_item aipkit_widget_color_menu_item">
                                        <?php $render_widget_color_radio($option, 'menu'); ?>
                                        <span class="aipkit_widget_color_menu_check dashicons dashicons-yes" aria-hidden="true"></span>
                                    </label>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <select
            id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_theme"
            name="theme"
            class="aipkit_popover_option_select aipkit_theme_hidden_select"
        >
            <?php foreach ($ordered_widget_color_options as $option) : ?>
                <option
                    value="<?php echo esc_attr($option['theme']); ?>"
                    data-preset-key="<?php echo esc_attr($option['preset']); ?>"
                    data-primary="<?php echo esc_attr($option['primary']); ?>"
                    data-secondary="<?php echo esc_attr($option['secondary']); ?>"
                    data-icon-color="<?php echo esc_attr($option['icon_color']); ?>"
                    <?php selected($option['signature'] === $selected_color_signature, true); ?>
                    <?php disabled(!empty($option['disabled'])); ?>
                >
                    <?php echo esc_html($option['label']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input
            type="hidden"
            id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_theme_preset_key"
            name="theme_preset_key"
            value="<?php echo esc_attr($selected_preset_key); ?>"
        />
    </div>
</div>
