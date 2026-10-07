<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The Look tab: brand, the chat button (floating chat only), interface text and fine details.
// The whole tab is the popup settings root, so every chat-button field below saves with the popup settings.
$bot_id = $initial_active_bot_id;
$bot_settings = $active_bot_settings;
$bot_name_value = isset($active_bot_name_value)
    ? (string) $active_bot_name_value
    : (($active_bot_post && isset($active_bot_post->post_title)) ? (string) $active_bot_post->post_title : '');
$saved_greeting_value = isset($saved_greeting)
    ? (string) $saved_greeting
    : (($active_bot_settings['greeting'] ?? ''));
$saved_subgreeting_value = isset($saved_subgreeting)
    ? (string) $saved_subgreeting
    : (($active_bot_settings['subgreeting'] ?? ''));
$saved_footer_text = $bot_settings['footer_text'] ?? '';
$saved_placeholder = $bot_settings['input_placeholder'] ?? __('Type your message...', 'gpt3-ai-content-generator');
$custom_typing_text = $bot_settings['custom_typing_text'] ?? '';
$retrieving_context_text = $bot_settings['retrieving_context_text'] ?? '';

$aipkit_popup_default_icon_url = esc_url(WPAICG_LOGO_URL);
$aipkit_validate_url = static function ($url) {
    $url = trim((string)$url);
    if ($url === '') {
        return false;
    }
    if (function_exists('wp_http_validate_url')) {
        return (bool) wp_http_validate_url($url);
    }
    return (bool) filter_var($url, FILTER_VALIDATE_URL);
};
$aipkit_popup_custom_icon_url_value = '';
if ($popup_icon_type === 'custom') {
    $popup_icon_candidate = trim((string)$popup_icon_value);
    if ($aipkit_validate_url($popup_icon_candidate)) {
        $aipkit_popup_custom_icon_url_value = $popup_icon_candidate;
    } else {
        $aipkit_popup_custom_icon_url_value = $aipkit_popup_default_icon_url;
    }
}

$aipkit_header_avatar_custom_url_value = '';
if ($saved_header_avatar_type === 'custom') {
    $header_avatar_candidate = trim((string)$saved_header_avatar_url);
    if ($header_avatar_candidate === '' && !empty($saved_header_avatar_value)) {
        $header_avatar_candidate = trim((string)$saved_header_avatar_value);
    }
    if ($aipkit_validate_url($header_avatar_candidate)) {
        $aipkit_header_avatar_custom_url_value = $header_avatar_candidate;
    } else {
        $aipkit_header_avatar_custom_url_value = $aipkit_popup_default_icon_url;
    }
}

$aipkit_popup_auto_open_options = [
    0 => __('Off', 'gpt3-ai-content-generator'),
    3 => __('3 sec', 'gpt3-ai-content-generator'),
    5 => __('5 sec', 'gpt3-ai-content-generator'),
    10 => __('10 sec', 'gpt3-ai-content-generator'),
    15 => __('15 sec', 'gpt3-ai-content-generator'),
    30 => __('30 sec', 'gpt3-ai-content-generator'),
    60 => __('60 sec', 'gpt3-ai-content-generator'),
];
$aipkit_current_popup_delay = absint($popup_delay);

$aipkit_position_options = [
    'bottom-right' => __('Bottom right', 'gpt3-ai-content-generator'),
    'bottom-left' => __('Bottom left', 'gpt3-ai-content-generator'),
    'top-right' => __('Top right', 'gpt3-ai-content-generator'),
    'top-left' => __('Top left', 'gpt3-ai-content-generator'),
];
$aipkit_bubble_mode_options = [
    'on_delay' => __('After a delay', 'gpt3-ai-content-generator'),
    'always' => __('Right away', 'gpt3-ai-content-generator'),
    'until_open' => __('Until opened', 'gpt3-ai-content-generator'),
    'until_dismissed' => __('Until closed', 'gpt3-ai-content-generator'),
];
$aipkit_bubble_frequency_options = [
    'once_per_visitor' => __('Once per visitor', 'gpt3-ai-content-generator'),
    'once_per_session' => __('Once per visit', 'gpt3-ai-content-generator'),
    'always' => __('Every page', 'gpt3-ai-content-generator'),
];
$aipkit_bubble_size_options = [
    'small' => __('Small', 'gpt3-ai-content-generator'),
    'medium' => __('Medium', 'gpt3-ai-content-generator'),
    'large' => __('Large', 'gpt3-ai-content-generator'),
    'xlarge' => __('Extra large', 'gpt3-ai-content-generator'),
];
$aipkit_popup_hidden = $quick_popup_enabled ? '' : ' hidden';

// One labelled text field in the Interface card; popup_only fields hide inside a page.
$render_text_field = static function (string $name, string $label, string $value, string $placeholder = '', array $field = []) use ($bot_id, $aipkit_popup_hidden): void {
    $field_id = 'aipkit_bot_' . $bot_id . '_' . ($field['id'] ?? $name);
    $is_popup_only = !empty($field['popup_only']);
    ?>
    <div class="aipkit_look_field"<?php echo $is_popup_only ? ' data-aipkit-popup-only-control' . $aipkit_popup_hidden : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed attributes. ?>>
        <label class="aipkit_look_field_label" for="<?php echo esc_attr($field_id); ?>"><?php echo esc_html($label); ?></label>
        <input
            type="text"
            id="<?php echo esc_attr($field_id); ?>"
            name="<?php echo esc_attr($name); ?>"
            class="<?php echo esc_attr(trim('aipkit_look_input ' . ($field['class'] ?? ''))); ?>"
            value="<?php echo esc_attr($value); ?>"
            <?php if ($placeholder !== '') : ?>placeholder="<?php echo esc_attr($placeholder); ?>"<?php endif; ?>
            autocomplete="off"
            data-lpignore="true"
            data-1p-ignore="true"
            data-form-type="other"
        />
    </div>
    <?php
};
?>
<div class="aipkit_look" id="aipkit_builder_popup_settings_panel" data-aipkit-widget-designer data-aipkit-feature-panels>
        <div class="aipkit_popup_visual_state" hidden aria-hidden="true">
            <?php foreach ($popup_icons as $icon_key => $svg_html) : ?>
                <?php
                $radio_id = 'aipkit_bot_' . absint($bot_id) . '_popup_icon_deploy_' . sanitize_key($icon_key);
                $icon_checked = ($popup_icon_type !== 'custom' && $popup_icon_value === $icon_key);
                ?>
                <label class="aipkit_option_card" for="<?php echo esc_attr($radio_id); ?>">
                    <input
                        type="radio"
                        id="<?php echo esc_attr($radio_id); ?>"
                        name="popup_icon_default"
                        value="<?php echo esc_attr($icon_key); ?>"
                        <?php checked($icon_checked); ?>
                    />
                    <?php
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    echo $svg_html;
                    ?>
                </label>
            <?php endforeach; ?>
            <?php $popup_custom_radio_id = 'aipkit_bot_' . absint($bot_id) . '_popup_icon_deploy_custom'; ?>
            <label class="aipkit_option_card aipkit_option_card--custom-url" for="<?php echo esc_attr($popup_custom_radio_id); ?>">
                <input
                    type="radio"
                    id="<?php echo esc_attr($popup_custom_radio_id); ?>"
                    name="popup_icon_default"
                    value="__custom__"
                    <?php checked($popup_icon_type, 'custom'); ?>
                />
                <span><?php esc_html_e('Custom', 'gpt3-ai-content-generator'); ?></span>
            </label>
            <div class="aipkit_popup_icon_custom_input_container" <?php echo ($popup_icon_type === 'custom') ? '' : 'hidden'; ?>>
                <input
                    type="url"
                    id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_popup_icon_custom_url_deploy"
                    name="popup_icon_custom_url"
                    data-default-url="<?php echo esc_url($aipkit_popup_default_icon_url); ?>"
                    value="<?php echo ($popup_icon_type === 'custom') ? esc_url($aipkit_popup_custom_icon_url_value) : ''; ?>"
                />
            </div>
            <?php $header_inherit_radio_id = 'aipkit_bot_' . absint($bot_id) . '_header_avatar_icon_deploy_inherit'; ?>
            <label class="aipkit_option_card" for="<?php echo esc_attr($header_inherit_radio_id); ?>">
                <input
                    type="radio"
                    id="<?php echo esc_attr($header_inherit_radio_id); ?>"
                    name="header_avatar_default"
                    value="__inherit__"
                    <?php checked($saved_header_avatar_type, 'inherit'); ?>
                />
                <span><?php esc_html_e('Match widget icon', 'gpt3-ai-content-generator'); ?></span>
            </label>
            <?php foreach ($popup_icons as $icon_key => $svg_html) : ?>
                <?php
                $radio_id = 'aipkit_bot_' . absint($bot_id) . '_header_avatar_icon_deploy_' . sanitize_key($icon_key);
                $icon_checked = ($saved_header_avatar_type === 'default' && $saved_header_avatar_value === $icon_key);
                ?>
                <label class="aipkit_option_card" for="<?php echo esc_attr($radio_id); ?>">
                    <input
                        type="radio"
                        id="<?php echo esc_attr($radio_id); ?>"
                        name="header_avatar_default"
                        value="<?php echo esc_attr($icon_key); ?>"
                        <?php checked($icon_checked); ?>
                    />
                    <?php
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    echo $svg_html;
                    ?>
                </label>
            <?php endforeach; ?>
            <?php $header_custom_radio_id = 'aipkit_bot_' . absint($bot_id) . '_header_avatar_icon_deploy_custom'; ?>
            <label class="aipkit_option_card aipkit_option_card--custom-url" for="<?php echo esc_attr($header_custom_radio_id); ?>">
                <input
                    type="radio"
                    id="<?php echo esc_attr($header_custom_radio_id); ?>"
                    name="header_avatar_default"
                    value="__custom__"
                    <?php checked($saved_header_avatar_type, 'custom'); ?>
                />
                <span><?php esc_html_e('Custom', 'gpt3-ai-content-generator'); ?></span>
            </label>
            <div class="aipkit_header_avatar_custom_input_container" <?php echo ($saved_header_avatar_type === 'custom') ? '' : 'hidden'; ?>>
                <input
                    type="url"
                    id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_header_avatar_url_deploy"
                    name="header_avatar_url"
                    data-default-url="<?php echo esc_url($aipkit_popup_default_icon_url); ?>"
                    value="<?php echo ($saved_header_avatar_type === 'custom') ? esc_url($aipkit_header_avatar_custom_url_value) : ''; ?>"
                />
            </div>
            <select
                id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_popup_label_enabled"
                name="popup_label_enabled"
                class="aipkit_popup_hint_toggle_switch"
            >
                <option value="1" <?php selected($popup_label_enabled, '1'); ?>><?php esc_html_e('Yes', 'gpt3-ai-content-generator'); ?></option>
                <option value="0" <?php selected($popup_label_enabled, '0'); ?>><?php esc_html_e('No', 'gpt3-ai-content-generator'); ?></option>
            </select>
        </div>

    <?php // No row clipping here: the More colors menu opens past the card's edge. ?>
    <section class="aipkit_settings_card" aria-labelledby="aipkit_look_brand_title">
        <div class="aipkit_settings_card_header">
            <h3 class="aipkit_settings_card_title" id="aipkit_look_brand_title"><?php esc_html_e('Brand', 'gpt3-ai-content-generator'); ?></h3>
            <p class="aipkit_settings_card_hint"><?php esc_html_e('How your chatbot looks everywhere.', 'gpt3-ai-content-generator'); ?></p>
        </div>
        <div class="aipkit_card_rows">
        <div class="aipkit_look_row aipkit_look_row--wide">
            <span class="aipkit_feature_icon dashicons dashicons-art" aria-hidden="true"></span>
            <span class="aipkit_look_row_copy">
                <span class="aipkit_look_row_title" id="aipkit_look_color_title"><?php esc_html_e('Color', 'gpt3-ai-content-generator'); ?></span>
                <span class="aipkit_look_row_hint"><?php esc_html_e('The button, the header and your visitors\' messages.', 'gpt3-ai-content-generator'); ?></span>
            </span>
            <div class="aipkit_look_row_control">
                <?php include __DIR__ . '/widget-colors.php'; ?>
            </div>
        </div>
        <div class="aipkit_look_row aipkit_look_row--wide" data-aipkit-popup-only-control<?php echo $aipkit_popup_hidden; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A fixed attribute. ?>>
            <span class="aipkit_feature_icon dashicons dashicons-admin-users" aria-hidden="true"></span>
            <span class="aipkit_look_row_copy">
                <span class="aipkit_look_row_title"><?php esc_html_e('Chat photo', 'gpt3-ai-content-generator'); ?></span>
                <span class="aipkit_look_row_hint"><?php esc_html_e('In the chat header and beside replies.', 'gpt3-ai-content-generator'); ?></span>
                <span class="aipkit_widget_profile_status screen-reader-text" data-aipkit-avatar-link-status>
                    <?php
                    if ($saved_header_avatar_type === 'inherit') {
                        esc_html_e('Matches widget icon', 'gpt3-ai-content-generator');
                    } elseif ($saved_header_avatar_type === 'custom') {
                        esc_html_e('Custom image', 'gpt3-ai-content-generator');
                    } else {
                        esc_html_e('Separate icon', 'gpt3-ai-content-generator');
                    }
                    ?>
                </span>
            </span>
            <span class="aipkit_look_row_control">
                <span
                    class="aipkit_widget_avatar_preview"
                    data-aipkit-avatar-quick-preview
                    data-avatar-initial="<?php echo esc_attr($quick_header_avatar_initial); ?>"
                    aria-hidden="true"
                >
                    <?php if ($quick_header_avatar_url !== '') : ?>
                        <img src="<?php echo esc_url($quick_header_avatar_url); ?>" alt="" class="aipkit_widget_avatar_img" />
                    <?php elseif ($quick_header_avatar_icon_html !== '') : ?>
                        <span class="aipkit_widget_avatar_icon" aria-hidden="true">
                            <?php
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            echo $quick_header_avatar_icon_html;
                            ?>
                        </span>
                    <?php else : ?>
                        <span class="aipkit_widget_avatar_initial" aria-hidden="true"><?php echo esc_html($quick_header_avatar_initial); ?></span>
                    <?php endif; ?>
                </span>
                <button
                    type="button"
                    class="aipkit_look_button"
                    data-aipkit-avatar-use-widget
                    <?php echo ($saved_header_avatar_type === 'inherit') ? 'hidden' : ''; ?>
                >
                    <?php esc_html_e('Use button icon', 'gpt3-ai-content-generator'); ?>
                </button>
                <button type="button" class="aipkit_look_button" data-aipkit-avatar-quick-upload>
                    <?php esc_html_e('Upload', 'gpt3-ai-content-generator'); ?>
                </button>
            </span>
        </div>
        </div>
    </section>

    <section class="aipkit_settings_card aipkit_feature_card" aria-labelledby="aipkit_look_button_title" data-aipkit-popup-only-control<?php echo $aipkit_popup_hidden; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A fixed attribute. ?>>
        <div class="aipkit_settings_card_header">
            <h3 class="aipkit_settings_card_title" id="aipkit_look_button_title"><?php esc_html_e('Chat button', 'gpt3-ai-content-generator'); ?></h3>
            <p class="aipkit_settings_card_hint"><?php esc_html_e('The button in the corner of your site.', 'gpt3-ai-content-generator'); ?></p>
        </div>
        <div class="aipkit_card_rows">
        <?php if (!empty($popup_icons)) : ?>
            <div class="aipkit_look_row aipkit_look_row--wide">
                <span class="aipkit_feature_icon dashicons dashicons-format-chat" aria-hidden="true"></span>
                <span class="aipkit_look_row_copy">
                    <span class="aipkit_look_row_title" id="aipkit_look_icon_title"><?php esc_html_e('Icon', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_look_row_hint"><?php esc_html_e('On the chat button.', 'gpt3-ai-content-generator'); ?></span>
                </span>
                <div class="aipkit_look_row_control aipkit_widget_icon_choices" role="radiogroup" aria-labelledby="aipkit_look_icon_title" data-aipkit-widget-icon-quick>
                    <?php foreach ($popup_icons as $icon_key => $svg_html) : ?>
                        <?php
                        $quick_icon_id = 'aipkit_bot_' . absint($bot_id) . '_quick_widget_icon_' . sanitize_key($icon_key);
                        $quick_icon_checked = ($popup_icon_type !== 'custom' && $popup_icon_value === $icon_key);
                        ?>
                        <label class="aipkit_widget_icon_choice" for="<?php echo esc_attr($quick_icon_id); ?>" title="<?php echo esc_attr(ucwords(str_replace('-', ' ', $icon_key))); ?>">
                            <input
                                type="radio"
                                id="<?php echo esc_attr($quick_icon_id); ?>"
                                name="aipkit_widget_icon_quick"
                                value="<?php echo esc_attr($icon_key); ?>"
                                <?php checked($quick_icon_checked); ?>
                            />
                            <span class="aipkit_widget_icon_choice_visual" aria-hidden="true">
                                <?php
                                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                echo $svg_html;
                                ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                    <?php $quick_custom_icon_url = ($popup_icon_type === 'custom' && !empty($popup_icon_value)) ? $popup_icon_value : ''; ?>
                    <button
                        type="button"
                        class="aipkit_widget_icon_choice aipkit_widget_icon_upload_btn<?php echo ($popup_icon_type === 'custom') ? ' is-selected' : ''; ?>"
                        data-aipkit-widget-icon-upload
                        aria-pressed="<?php echo ($popup_icon_type === 'custom') ? 'true' : 'false'; ?>"
                        aria-label="<?php esc_attr_e('Upload widget icon', 'gpt3-ai-content-generator'); ?>"
                        title="<?php esc_attr_e('Upload widget icon', 'gpt3-ai-content-generator'); ?>"
                    >
                        <span class="aipkit_widget_icon_choice_visual aipkit_widget_icon_choice_visual--custom" data-aipkit-widget-icon-custom-visual aria-hidden="true">
                            <?php if ($quick_custom_icon_url !== '') : ?>
                                <img src="<?php echo esc_url($quick_custom_icon_url); ?>" alt="" class="aipkit_widget_icon_custom_img" />
                            <?php else : ?>
                                <span class="dashicons dashicons-upload"></span>
                            <?php endif; ?>
                        </span>
                    </button>
                </div>
            </div>
        <?php endif; ?>
        <div class="aipkit_look_row aipkit_look_row--wide">
            <span class="aipkit_feature_icon dashicons dashicons-move" aria-hidden="true"></span>
            <span class="aipkit_look_row_copy">
                <span class="aipkit_look_row_title" id="aipkit_look_position_label"><?php esc_html_e('Position', 'gpt3-ai-content-generator'); ?></span>
                <span class="aipkit_look_row_hint"><?php esc_html_e('Where the button sits.', 'gpt3-ai-content-generator'); ?></span>
            </span>
            <div class="aipkit_look_row_control">
                <div class="aipkit_segmented" role="group" aria-labelledby="aipkit_look_position_label" data-aipkit-segmented-for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_popup_position">
                    <?php foreach ($aipkit_position_options as $aipkit_option_value => $aipkit_option_label) : ?>
                        <button
                            type="button"
                            class="aipkit_segmented_option aipkit_look_corner_option"
                            data-value="<?php echo esc_attr((string) $aipkit_option_value); ?>"
                            aria-pressed="<?php echo ((string) $popup_position === (string) $aipkit_option_value) ? 'true' : 'false'; ?>"
                            aria-label="<?php echo esc_attr($aipkit_option_label); ?>"
                            title="<?php echo esc_attr($aipkit_option_label); ?>"
                        ><span class="aipkit_look_corner" data-corner="<?php echo esc_attr((string) $aipkit_option_value); ?>" aria-hidden="true"></span></button>
                    <?php endforeach; ?>
                </div>
                <select id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_popup_position" name="popup_position" class="aipkit_look_saved_field" aria-hidden="true" tabindex="-1" hidden>
                    <?php foreach ($aipkit_position_options as $aipkit_option_value => $aipkit_option_label) : ?>
                        <option value="<?php echo esc_attr((string) $aipkit_option_value); ?>" <?php selected((string) $popup_position, (string) $aipkit_option_value); ?>><?php echo esc_html($aipkit_option_label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php
        $render_feature_row([
            'key' => 'bubble',
            'icon' => 'megaphone',
            'title' => __('Welcome bubble', 'gpt3-ai-content-generator'),
            'hint' => $popup_label_text,
            'state' => $popup_label_enabled === '1',
        ]);
        ?>
        <div class="aipkit_look_row">
            <span class="aipkit_feature_icon dashicons dashicons-clock" aria-hidden="true"></span>
            <span class="aipkit_look_row_copy">
                <label class="aipkit_look_row_title" for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_popup_delay"><?php esc_html_e('Open automatically', 'gpt3-ai-content-generator'); ?></label>
                <span class="aipkit_look_row_hint"><?php esc_html_e('Opens the chat for new visitors.', 'gpt3-ai-content-generator'); ?></span>
            </span>
            <select
                id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_popup_delay"
                name="popup_delay"
                data-saved-value-label="<?php
                /* translators: %d: Number of seconds before the chat opens automatically. */
                esc_attr_e('%d sec', 'gpt3-ai-content-generator');
                ?>"
                class="aipkit_look_select"
            >
                <?php if (!array_key_exists($aipkit_current_popup_delay, $aipkit_popup_auto_open_options)) : ?>
                    <option value="<?php echo esc_attr($aipkit_current_popup_delay); ?>" selected="selected">
                        <?php
                        printf(
                            /* translators: %d: number of seconds */
                            esc_html__('%d sec', 'gpt3-ai-content-generator'),
                            absint($aipkit_current_popup_delay)
                        );
                        ?>
                    </option>
                <?php endif; ?>
                <?php foreach ($aipkit_popup_auto_open_options as $delay_value => $delay_label) : ?>
                    <option value="<?php echo esc_attr((string) $delay_value); ?>" <?php selected($aipkit_current_popup_delay, $delay_value); ?>>
                        <?php echo esc_html($delay_label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        </div>
    </section>

    <section class="aipkit_settings_card" aria-labelledby="aipkit_look_interface_title">
        <div class="aipkit_settings_card_header">
            <h3 class="aipkit_settings_card_title" id="aipkit_look_interface_title"><?php esc_html_e('Interface', 'gpt3-ai-content-generator'); ?></h3>
            <p class="aipkit_settings_card_hint"><?php esc_html_e('The text visitors see in the chat.', 'gpt3-ai-content-generator'); ?></p>
        </div>
        <div class="aipkit_look_fields" data-aipkit-settings-panel="appearance">
            <?php
            $render_text_field('bot_name', __('Chatbot name', 'gpt3-ai-content-generator'), $bot_name_value, '', ['id' => 'name', 'class' => 'aipkit_bot_name_input']);
            $render_text_field('greeting', __('Greeting', 'gpt3-ai-content-generator'), $saved_greeting_value, __('Hello there!', 'gpt3-ai-content-generator'));
            $render_text_field('subgreeting', __('Subgreeting', 'gpt3-ai-content-generator'), $saved_subgreeting_value, __('How can I help you today?', 'gpt3-ai-content-generator'));
            $render_text_field('input_placeholder', __('Message box hint', 'gpt3-ai-content-generator'), $saved_placeholder, __('Type your message...', 'gpt3-ai-content-generator'));
            $render_text_field('custom_typing_text', __('Typing text', 'gpt3-ai-content-generator'), $custom_typing_text, __('Thinking', 'gpt3-ai-content-generator'));
            $render_text_field('retrieving_context_text', __('Status text', 'gpt3-ai-content-generator'), $retrieving_context_text, __('Retrieving context...', 'gpt3-ai-content-generator'));
            $render_text_field('footer_text', __('Footer', 'gpt3-ai-content-generator'), $saved_footer_text, __('Powered by AI', 'gpt3-ai-content-generator'));
            $render_text_field('header_online_text', __('Online text', 'gpt3-ai-content-generator'), $saved_header_online_text, __('Online', 'gpt3-ai-content-generator'), ['popup_only' => true]);
            ?>
        </div>
    </section>

    <section class="aipkit_settings_card aipkit_feature_card aipkit_feature_card--answer" aria-label="<?php esc_attr_e('Size and font', 'gpt3-ai-content-generator'); ?>">
        <?php
        $render_feature_row([
            'key' => 'size',
            'icon' => 'admin-appearance',
            'title' => __('Size and font', 'gpt3-ai-content-generator'),
            'hint' => __('Font, corners, button shape and window size.', 'gpt3-ai-content-generator'),
            'button_attributes' => ' data-aipkit-theme-settings-open aria-haspopup="dialog" aria-expanded="false" aria-controls="aipkit_custom_theme_modal"',
        ]);
        ?>
    </section>

    <?php $render_drawer_start('bubble', __('Welcome bubble', 'gpt3-ai-content-generator'), __('Invite visitors to start a chat.', 'gpt3-ai-content-generator')); ?>
        <div class="aipkit_look_switch_card">
            <label class="aipkit_look_switch_label" for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_popup_label_enabled_look">
                <span class="aipkit_look_row_copy">
                    <span class="aipkit_look_row_title"><?php esc_html_e('Show welcome bubble', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_look_row_hint"><?php esc_html_e('A short line next to the chat button.', 'gpt3-ai-content-generator'); ?></span>
                </span>
                <span class="aipkit_switch">
                    <input
                        type="checkbox"
                        id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_popup_label_enabled_look"
                        class="aipkit_popup_hint_toggle_checkbox"
                        data-aipkit-feature-switch
                        <?php checked($popup_label_enabled, '1'); ?>
                    />
                    <span class="aipkit_switch_slider" aria-hidden="true"></span>
                </span>
            </label>
            <div class="aipkit_widget_launcher_message">
                <label class="aipkit_look_field_label" for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_popup_label_text_main"><?php esc_html_e('Message', 'gpt3-ai-content-generator'); ?></label>
                <input
                    type="text"
                    id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_popup_label_text_main"
                    name="popup_label_text"
                    class="aipkit_look_input"
                    value="<?php echo esc_attr($popup_label_text); ?>"
                    maxlength="60"
                    placeholder="<?php esc_attr_e('Need help? Ask me!', 'gpt3-ai-content-generator'); ?>"
                    autocomplete="off"
                    data-lpignore="true"
                    data-1p-ignore="true"
                    data-form-type="other"
                    <?php disabled($popup_label_enabled !== '1'); ?>
                />
            </div>
        </div>
        <?php // The popup script hides these while the bubble is off. ?>
        <div class="aipkit_look_panel_rows aipkit_popup_hint_conditional_row"<?php echo ($popup_label_enabled === '1') ? '' : ' hidden'; ?>>
            <?php // Long choices: a dropdown keeps them beside the name, like Open automatically. ?>
            <div class="aipkit_look_panel_row aipkit_look_bubble_mode">
                <span class="aipkit_look_row_copy">
                    <label class="aipkit_look_row_title" for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_popup_label_mode"><?php esc_html_e('When it appears', 'gpt3-ai-content-generator'); ?></label>
                    <span class="aipkit_look_row_hint"><?php esc_html_e('A short wait feels less pushy.', 'gpt3-ai-content-generator'); ?></span>
                </span>
                <div class="aipkit_look_row_control">
                    <select id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_popup_label_mode" name="popup_label_mode" class="aipkit_look_select">
                        <?php foreach ($aipkit_bubble_mode_options as $aipkit_option_value => $aipkit_option_label) : ?>
                            <option value="<?php echo esc_attr((string) $aipkit_option_value); ?>" <?php selected((string) $popup_label_mode, (string) $aipkit_option_value); ?>><?php echo esc_html($aipkit_option_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="aipkit_look_seconds aipkit_look_bubble_delay">
                        <span><?php esc_html_e('Wait', 'gpt3-ai-content-generator'); ?></span>
                        <input type="number" id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_popup_label_delay_seconds" name="popup_label_delay_seconds" class="aipkit_look_input aipkit_look_input--number" min="0" step="1" value="<?php echo esc_attr((string) $popup_label_delay_seconds); ?>" />
                        <span><?php esc_html_e('sec', 'gpt3-ai-content-generator'); ?></span>
                    </label>
                </div>
            </div>
            <div class="aipkit_look_panel_row">
                <span class="aipkit_look_row_copy">
                    <label class="aipkit_look_row_title" for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_popup_label_frequency"><?php esc_html_e('How often', 'gpt3-ai-content-generator'); ?></label>
                    <span class="aipkit_look_row_hint"><?php esc_html_e('Visitors who closed it won\'t see it again until the next time.', 'gpt3-ai-content-generator'); ?></span>
                </span>
                <div class="aipkit_look_row_control">
                    <select id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_popup_label_frequency" name="popup_label_frequency" class="aipkit_look_select">
                        <?php foreach ($aipkit_bubble_frequency_options as $aipkit_option_value => $aipkit_option_label) : ?>
                            <option value="<?php echo esc_attr((string) $aipkit_option_value); ?>" <?php selected((string) $popup_label_frequency, (string) $aipkit_option_value); ?>><?php echo esc_html($aipkit_option_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="aipkit_look_panel_row">
                <span class="aipkit_look_row_copy">
                    <label class="aipkit_look_row_title" for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_popup_label_auto_hide_seconds"><?php esc_html_e('Hide after', 'gpt3-ai-content-generator'); ?></label>
                    <span class="aipkit_look_row_hint"><?php esc_html_e('0 keeps it until the visitor acts.', 'gpt3-ai-content-generator'); ?></span>
                </span>
                <span class="aipkit_look_seconds">
                    <input type="number" id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_popup_label_auto_hide_seconds" name="popup_label_auto_hide_seconds" class="aipkit_look_input aipkit_look_input--number" min="0" step="1" value="<?php echo esc_attr((string) $popup_label_auto_hide_seconds); ?>" />
                    <span><?php esc_html_e('sec', 'gpt3-ai-content-generator'); ?></span>
                </span>
            </div>
            <label class="aipkit_look_panel_row" for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_popup_label_dismissible">
                <span class="aipkit_look_row_copy">
                    <span class="aipkit_look_row_title"><?php esc_html_e('Visitors can close it', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_look_row_hint"><?php esc_html_e('Shows a small ×.', 'gpt3-ai-content-generator'); ?></span>
                </span>
                <span class="aipkit_switch">
                    <input type="checkbox" id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_popup_label_dismissible" name="popup_label_dismissible" value="1" <?php checked($popup_label_dismissible, '1'); ?> />
                    <span class="aipkit_switch_slider" aria-hidden="true"></span>
                </span>
            </label>
            <div class="aipkit_look_panel_row">
                <span class="aipkit_look_row_copy">
                    <span class="aipkit_look_row_title"><?php esc_html_e('Show on', 'gpt3-ai-content-generator'); ?></span>
                </span>
                <span class="aipkit_look_chips">
                    <label class="aipkit_look_chip">
                        <input type="checkbox" name="popup_label_show_on_desktop" value="1" <?php checked($popup_label_show_on_desktop, '1'); ?> />
                        <span><?php esc_html_e('Desktop', 'gpt3-ai-content-generator'); ?></span>
                    </label>
                    <label class="aipkit_look_chip">
                        <input type="checkbox" name="popup_label_show_on_mobile" value="1" <?php checked($popup_label_show_on_mobile, '1'); ?> />
                        <span><?php esc_html_e('Mobile', 'gpt3-ai-content-generator'); ?></span>
                    </label>
                </span>
            </div>
            <div class="aipkit_look_panel_row">
                <span class="aipkit_look_row_copy">
                    <span class="aipkit_look_row_title" id="aipkit_look_bubble_size_title"><?php esc_html_e('Size', 'gpt3-ai-content-generator'); ?></span>
                </span>
                <div class="aipkit_segmented" role="group" aria-labelledby="aipkit_look_bubble_size_title" data-aipkit-segmented-for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_popup_label_size">
                    <?php foreach ($aipkit_bubble_size_options as $aipkit_option_value => $aipkit_option_label) : ?>
                        <button type="button" class="aipkit_segmented_option" data-value="<?php echo esc_attr((string) $aipkit_option_value); ?>" aria-pressed="<?php echo ((string) $popup_label_size === (string) $aipkit_option_value) ? 'true' : 'false'; ?>"><?php echo esc_html($aipkit_option_label); ?></button>
                    <?php endforeach; ?>
                </div>
                <select id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_popup_label_size" name="popup_label_size" class="aipkit_look_saved_field" aria-hidden="true" tabindex="-1" hidden>
                    <?php foreach ($aipkit_bubble_size_options as $aipkit_option_value => $aipkit_option_label) : ?>
                        <option value="<?php echo esc_attr((string) $aipkit_option_value); ?>" <?php selected((string) $popup_label_size, (string) $aipkit_option_value); ?>><?php echo esc_html($aipkit_option_label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php // The saved version names the bubble a visitor has seen; a new one shows it to everyone again. ?>
            <div class="aipkit_look_panel_row">
                <span class="aipkit_look_row_copy">
                    <span class="aipkit_look_row_title"><?php esc_html_e('Show it again', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_look_row_hint" data-aipkit-popup-hint-again-status aria-live="polite"><?php esc_html_e('Changed the message? Show it to everyone who already saw or closed it.', 'gpt3-ai-content-generator'); ?></span>
                </span>
                <button type="button" class="aipkit_look_button" data-aipkit-popup-hint-show-again data-done-text="<?php esc_attr_e('Done. Everyone will see it again.', 'gpt3-ai-content-generator'); ?>"><?php esc_html_e('Show again', 'gpt3-ai-content-generator'); ?></button>
                <input type="hidden" id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_popup_label_version" name="popup_label_version" value="<?php echo esc_attr((string) $popup_label_version); ?>" />
            </div>
        </div>
    <?php $render_drawer_end(); ?>

    <?php // Size and font: the custom theme's settings with the button's shape and size, as a side panel. ?>
    <?php if ($active_bot_post && !$aipkit_hide_custom_theme) : ?>
        <?php include __DIR__ . '/custom-theme-modal.php'; ?>
    <?php endif; ?>
</div>
