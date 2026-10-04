<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

$render_display_field = static function (string $name, string $label, $value, array $field = []) use ($bot_id): void {
    $field_id = 'aipkit_bot_' . $bot_id . '_' . ($field['id'] ?? $name);
    $is_select = isset($field['options']);
    $input_type = $field['type'] ?? 'text';
    $row_class = $field['row_class'] ?? 'aipkit_popover_option_row aipkit_interface_cell aipkit_interface_cell--popup';
    $label_class = 'aipkit_popover_option_label' . (empty($field['chat_text']) ? ' aipkit_interface_popup_inline_label' : '');
    $field_class = $field['class'] ?? ($is_select
        ? 'aipkit_popover_option_select aipkit_popover_option_input--framed'
        : 'aipkit_popover_option_input aipkit_popover_option_input--framed');
    ?>
    <div class="<?php echo esc_attr($row_class); ?>">
        <div class="aipkit_popover_option_main">
            <label class="<?php echo esc_attr($label_class); ?>" for="<?php echo esc_attr($field_id); ?>">
                <?php echo esc_html($label); ?>
            </label>
            <?php if ($is_select) : ?>
                <select id="<?php echo esc_attr($field_id); ?>" name="<?php echo esc_attr($name); ?>" class="<?php echo esc_attr($field_class); ?>">
                    <?php foreach ($field['options'] as $option_value => $option_label) : ?>
                        <option value="<?php echo esc_attr($option_value); ?>" <?php selected($value, $option_value); ?>><?php echo esc_html($option_label); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else : ?>
                <input
                    type="<?php echo esc_attr($input_type); ?>"
                    id="<?php echo esc_attr($field_id); ?>"
                    <?php if (empty($field['unnamed'])) : ?>name="<?php echo esc_attr($name); ?>"<?php endif; ?>
                    class="<?php echo esc_attr($field_class); ?>"
                    value="<?php echo $input_type === 'url' ? esc_url($value) : esc_attr($value); ?>"
                    <?php foreach ($field['attributes'] ?? [] as $attribute => $attribute_value) : ?>
                        <?php echo esc_attr($attribute); ?><?php if ($attribute_value !== true) : ?>="<?php echo esc_attr($attribute_value); ?>"<?php endif; ?>
                    <?php endforeach; ?>
                >
            <?php endif; ?>
        </div>
    </div>
    <?php
};
?>
<div class="aipkit_settings_panel_body" data-aipkit-settings-panel="appearance">
    <div class="aipkit_popover_options_list aipkit_interface_options aipkit_display_settings_rows">
        <div
            class="aipkit_interface_feature_row aipkit_interface_feature_row--expandable aipkit_display_settings_row aipkit_display_settings_row--chat-text"
            data-aipkit-inline-settings-row
            data-aipkit-static-inline-settings-row
        >
            <div class="aipkit_interface_feature_label">
                <span class="aipkit_display_settings_icon" aria-hidden="true">
                    <span class="dashicons dashicons-editor-textcolor"></span>
                </span>
                <span class="aipkit_interface_feature_text">
                    <span class="aipkit_interface_feature_title aipkit_popover_option_label">
                        <?php esc_html_e('Chat text', 'gpt3-ai-content-generator'); ?>
                    </span>
                    <span class="aipkit_interface_feature_hint">
                        <?php esc_html_e('Edit labels and messages visitors see.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </span>
            </div>
            <div class="aipkit_interface_feature_action">
                <button
                    type="button"
                    class="aipkit_popover_option_btn aipkit_display_settings_toggle aipkit_interface_feature_expand_btn"
                    data-aipkit-inline-settings-toggle
                    data-aipkit-static-inline-settings-toggle
                    aria-expanded="false"
                    aria-controls="aipkit_display_chat_text_panel"
                    aria-label="<?php esc_attr_e('Toggle Chat text settings', 'gpt3-ai-content-generator'); ?>"
                >
                    <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                </button>
            </div>
            <div
                id="aipkit_display_chat_text_panel"
                class="aipkit_interface_feature_inline_panel aipkit_display_inline_panel"
                hidden
            >
                <div class="aipkit_display_fields_grid aipkit_display_fields_grid--chat-text">
                    <?php $render_display_field('bot_name', __('Chatbot name', 'gpt3-ai-content-generator'), $bot_name_value, [
                        'id' => 'name',
                        'row_class' => 'aipkit_popover_option_row aipkit_interface_cell aipkit_interface_cell--text aipkit_interface_cell--identity aipkit_bot_name_group',
                        'chat_text' => true,
                        'class' => 'aipkit_form-input aipkit_bot_name_input aipkit_popover_option_input aipkit_popover_option_input--wide aipkit_popover_option_input--framed',
                    ]); ?>
                    <?php $render_display_field('greeting', __('Greeting', 'gpt3-ai-content-generator'), $saved_greeting_value, [
                        'row_class' => 'aipkit_popover_option_row aipkit_interface_cell aipkit_interface_cell--text aipkit_interface_cell--identity',
                        'chat_text' => true,
                        'class' => 'aipkit_form-input aipkit_popover_option_input aipkit_popover_option_input--wide aipkit_popover_option_input--framed',
                        'attributes' => ['placeholder' => __('Hello there!', 'gpt3-ai-content-generator'), 'autocomplete' => 'off', 'data-lpignore' => 'true', 'data-1p-ignore' => 'true', 'data-form-type' => 'other'],
                    ]); ?>
                    <?php $render_display_field('subgreeting', __('Subgreeting', 'gpt3-ai-content-generator'), $saved_subgreeting_value, [
                        'row_class' => 'aipkit_popover_option_row aipkit_interface_cell aipkit_interface_cell--text aipkit_interface_cell--identity',
                        'chat_text' => true,
                        'class' => 'aipkit_form-input aipkit_popover_option_input aipkit_popover_option_input--wide aipkit_popover_option_input--framed',
                        'attributes' => ['placeholder' => __('How can I help you today?', 'gpt3-ai-content-generator'), 'autocomplete' => 'off', 'data-lpignore' => 'true', 'data-1p-ignore' => 'true', 'data-form-type' => 'other'],
                    ]); ?>
                    <?php $render_display_field('input_placeholder', __('Placeholder', 'gpt3-ai-content-generator'), $saved_placeholder, [
                        'row_class' => 'aipkit_popover_option_row aipkit_interface_cell aipkit_interface_cell--text',
                        'chat_text' => true,
                        'class' => 'aipkit_popover_option_input aipkit_popover_option_input--wide aipkit_popover_option_input--framed',
                        'attributes' => ['placeholder' => __('Type your message...', 'gpt3-ai-content-generator'), 'autocomplete' => 'off', 'data-lpignore' => 'true', 'data-1p-ignore' => 'true', 'data-form-type' => 'other'],
                    ]); ?>
                    <?php $render_display_field('footer_text', __('Footer', 'gpt3-ai-content-generator'), $saved_footer_text, [
                        'row_class' => 'aipkit_popover_option_row aipkit_interface_cell aipkit_interface_cell--text',
                        'chat_text' => true,
                        'class' => 'aipkit_popover_option_input aipkit_popover_option_input--wide aipkit_popover_option_input--framed',
                        'attributes' => ['placeholder' => __('Powered by AI', 'gpt3-ai-content-generator'), 'autocomplete' => 'off', 'data-lpignore' => 'true', 'data-1p-ignore' => 'true', 'data-form-type' => 'other'],
                    ]); ?>
                    <?php $render_display_field('custom_typing_text', __('Typing text', 'gpt3-ai-content-generator'), $custom_typing_text, [
                        'row_class' => 'aipkit_popover_option_row aipkit_interface_cell aipkit_interface_cell--text',
                        'chat_text' => true,
                        'class' => 'aipkit_popover_option_input aipkit_popover_option_input--wide aipkit_popover_option_input--framed',
                        'attributes' => ['placeholder' => __('Thinking', 'gpt3-ai-content-generator'), 'autocomplete' => 'off', 'data-lpignore' => 'true', 'data-1p-ignore' => 'true', 'data-form-type' => 'other'],
                    ]); ?>
                    <?php $render_display_field('retrieving_context_text', __('Status text', 'gpt3-ai-content-generator'), $retrieving_context_text, [
                        'row_class' => 'aipkit_popover_option_row aipkit_interface_cell aipkit_interface_cell--text',
                        'chat_text' => true,
                        'class' => 'aipkit_popover_option_input aipkit_popover_option_input--wide aipkit_popover_option_input--framed',
                        'attributes' => ['placeholder' => __('Retrieving context...', 'gpt3-ai-content-generator'), 'autocomplete' => 'off', 'data-lpignore' => 'true', 'data-1p-ignore' => 'true', 'data-form-type' => 'other'],
                    ]); ?>
                </div>
            </div>
        </div>
    </div>
</div>
<div
    class="aipkit_settings_panel_body"
    data-aipkit-settings-panel="popup"
    data-aipkit-popup-options
    <?php echo $quick_popup_enabled ? '' : 'hidden'; ?>
>
    <?php
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
    ?>
    <div class="aipkit_interface_section aipkit_interface_section--popup">
        <div class="aipkit_interface_popup_settings" id="aipkit_builder_popup_settings_panel">
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
                    class="aipkit_popover_option_select aipkit_popover_option_input--framed aipkit_popup_hint_toggle_switch aipkit_popup_hint_toggle_select"
                >
                    <option value="1" <?php selected($popup_label_enabled, '1'); ?>><?php esc_html_e('Yes', 'gpt3-ai-content-generator'); ?></option>
                    <option value="0" <?php selected($popup_label_enabled, '0'); ?>><?php esc_html_e('No', 'gpt3-ai-content-generator'); ?></option>
                </select>
            </div>
            <div class="aipkit_interface_feature_rows aipkit_display_settings_rows aipkit_display_popup_rows">
                <div
                    class="aipkit_interface_feature_row aipkit_interface_feature_row--expandable aipkit_display_settings_row aipkit_display_settings_row--launcher"
                    data-aipkit-inline-settings-row
                    data-aipkit-static-inline-settings-row
                >
                    <div class="aipkit_interface_feature_label">
                        <span class="aipkit_display_settings_icon" aria-hidden="true">
                            <span class="dashicons dashicons-admin-comments"></span>
                        </span>
                        <span class="aipkit_interface_feature_text">
                            <span class="aipkit_interface_feature_title aipkit_popover_option_label">
                                <?php esc_html_e('Popup', 'gpt3-ai-content-generator'); ?>
                            </span>
                            <span class="aipkit_interface_feature_hint">
                                <?php esc_html_e('Set position, size, and auto-open behavior.', 'gpt3-ai-content-generator'); ?>
                            </span>
                        </span>
                    </div>
                    <div class="aipkit_interface_feature_action">
                        <button
                            type="button"
                            class="aipkit_popover_option_btn aipkit_display_settings_toggle aipkit_interface_feature_expand_btn"
                            data-aipkit-inline-settings-toggle
                            data-aipkit-static-inline-settings-toggle
                            aria-expanded="false"
                            aria-controls="aipkit_display_launcher_panel"
                            aria-label="<?php esc_attr_e('Toggle Popup settings', 'gpt3-ai-content-generator'); ?>"
                        >
                            <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                        </button>
                    </div>
                    <div
                        id="aipkit_display_launcher_panel"
                        class="aipkit_interface_feature_inline_panel aipkit_display_inline_panel"
                        hidden
                    >
                        <div class="aipkit_interface_popup_grid aipkit_display_fields_grid aipkit_display_fields_grid--launcher">
                            <?php $render_display_field('popup_position', __('Position', 'gpt3-ai-content-generator'), $popup_position, [
                                'options' => ['bottom-right' => __('Bottom Right', 'gpt3-ai-content-generator'), 'bottom-left' => __('Bottom Left', 'gpt3-ai-content-generator'), 'top-right' => __('Top Right', 'gpt3-ai-content-generator'), 'top-left' => __('Top Left', 'gpt3-ai-content-generator')],
                            ]); ?>
                            <?php $render_display_field('popup_icon_style', __('Icon style', 'gpt3-ai-content-generator'), $popup_icon_style, [
                                'options' => ['circle' => __('Circle', 'gpt3-ai-content-generator'), 'square' => __('Square', 'gpt3-ai-content-generator'), 'none' => __('Original', 'gpt3-ai-content-generator')],
                            ]); ?>
                            <?php $render_display_field('popup_icon_size', __('Size', 'gpt3-ai-content-generator'), $popup_icon_size, [
                                'options' => ['small' => __('Small', 'gpt3-ai-content-generator'), 'medium' => __('Medium', 'gpt3-ai-content-generator'), 'large' => __('Large', 'gpt3-ai-content-generator'), 'xlarge' => __('X-Large', 'gpt3-ai-content-generator')],
                            ]); ?>
                            <div class="aipkit_popover_option_row aipkit_interface_cell aipkit_interface_cell--popup">
                                <div class="aipkit_popover_option_main">
                                    <label class="aipkit_popover_option_label aipkit_interface_popup_inline_label" for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_popup_delay">
                                        <?php esc_html_e('Auto-open', 'gpt3-ai-content-generator'); ?>
                                    </label>
                                    <select
                                        id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_popup_delay"
                                        name="popup_delay"
                                        class="aipkit_popover_option_select aipkit_popover_option_input--framed"
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
                            <?php $render_display_field('header_online_text', __('Online text', 'gpt3-ai-content-generator'), $saved_header_online_text, [
                                'attributes' => ['placeholder' => __('Online', 'gpt3-ai-content-generator'), 'autocomplete' => 'off', 'data-lpignore' => 'true', 'data-1p-ignore' => 'true', 'data-form-type' => 'other'],
                            ]); ?>
                            <?php $render_display_field('popup_icon_custom_url_visible', __('Custom icon URL', 'gpt3-ai-content-generator'), $popup_icon_type === 'custom' ? $aipkit_popup_custom_icon_url_value : '', [
                                'row_class' => 'aipkit_popover_option_row aipkit_interface_cell aipkit_interface_cell--popup aipkit_interface_cell--custom-icon',
                                'class' => 'aipkit_popover_option_input aipkit_popover_option_input--framed aipkit_popup_icon_custom_url_display',
                                'type' => 'url',
                                'unnamed' => true,
                                'attributes' => ['placeholder' => __('https://example.com/icon.png', 'gpt3-ai-content-generator'), 'autocomplete' => 'off', 'data-lpignore' => 'true', 'data-1p-ignore' => 'true', 'data-form-type' => 'other', 'data-aipkit-popup-icon-url-display' => true],
                            ]); ?>
                        </div>
                    </div>
                </div>
                <div
                    class="aipkit_interface_feature_row aipkit_interface_feature_row--expandable aipkit_display_settings_row aipkit_display_settings_row--welcome aipkit_popup_hint_behavior_row<?php echo ($popup_label_enabled === '1') ? '' : ' is-disabled'; ?>"
                    data-aipkit-inline-settings-row
                    data-aipkit-static-inline-settings-row
                >
                    <div class="aipkit_interface_feature_label">
                        <span class="aipkit_display_settings_icon" aria-hidden="true">
                            <span class="dashicons dashicons-megaphone"></span>
                        </span>
                        <span class="aipkit_interface_feature_text">
                            <span class="aipkit_interface_feature_title aipkit_popover_option_label">
                                <?php esc_html_e('Welcome message', 'gpt3-ai-content-generator'); ?>
                            </span>
                            <span class="aipkit_interface_feature_hint aipkit_popup_hint_enabled_copy" <?php echo ($popup_label_enabled === '1') ? '' : 'hidden'; ?>>
                                <?php esc_html_e('Control when the message appears.', 'gpt3-ai-content-generator'); ?>
                            </span>
                            <span class="aipkit_interface_feature_hint aipkit_popup_hint_disabled_copy" <?php echo ($popup_label_enabled === '1') ? 'hidden' : ''; ?>>
                                <?php esc_html_e('Turn it on in the main screen first.', 'gpt3-ai-content-generator'); ?>
                            </span>
                        </span>
                    </div>
                    <div class="aipkit_interface_feature_action">
                        <button
                            type="button"
                            class="aipkit_popover_option_btn aipkit_display_settings_toggle aipkit_interface_feature_expand_btn"
                            data-aipkit-inline-settings-toggle
                            data-aipkit-static-inline-settings-toggle
                            aria-expanded="false"
                            aria-controls="aipkit_display_welcome_panel"
                            aria-label="<?php esc_attr_e('Toggle Welcome message settings', 'gpt3-ai-content-generator'); ?>"
                            <?php disabled($popup_label_enabled !== '1'); ?>
                        >
                            <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                        </button>
                    </div>
                    <div
                        id="aipkit_display_welcome_panel"
                        class="aipkit_interface_feature_inline_panel aipkit_display_inline_panel"
                        hidden
                    >
                        <div class="aipkit_interface_popup_grid aipkit_display_fields_grid aipkit_display_fields_grid--welcome aipkit_popup_hint_conditional_row" <?php echo ($popup_label_enabled === '1') ? '' : 'hidden'; ?>>
                            <?php $render_display_field('popup_label_mode', __('When to show', 'gpt3-ai-content-generator'), $popup_label_mode, [
                                'id' => 'popup_label_mode_deploy',
                                'options' => ['on_delay' => __('After delay', 'gpt3-ai-content-generator'), 'always' => __('Immediately', 'gpt3-ai-content-generator'), 'until_open' => __('Until opened', 'gpt3-ai-content-generator'), 'until_dismissed' => __('Until closed', 'gpt3-ai-content-generator')],
                            ]); ?>
                            <?php $render_display_field('popup_label_frequency', __('Repeat', 'gpt3-ai-content-generator'), $popup_label_frequency, [
                                'id' => 'popup_label_frequency_deploy',
                                'options' => ['once_per_visitor' => __('Once per visitor', 'gpt3-ai-content-generator'), 'once_per_session' => __('Once per session', 'gpt3-ai-content-generator'), 'always' => __('Every time', 'gpt3-ai-content-generator')],
                            ]); ?>
                            <?php $render_display_field('popup_label_size', __('Size', 'gpt3-ai-content-generator'), $popup_label_size, [
                                'id' => 'popup_label_size_deploy',
                                'options' => ['small' => __('Small', 'gpt3-ai-content-generator'), 'medium' => __('Medium', 'gpt3-ai-content-generator'), 'large' => __('Large', 'gpt3-ai-content-generator'), 'xlarge' => __('Extra large', 'gpt3-ai-content-generator')],
                            ]); ?>
                            <?php $render_display_field('popup_label_delay_seconds', __('Delay (sec)', 'gpt3-ai-content-generator'), $popup_label_delay_seconds, [
                                'id' => 'popup_label_delay_seconds_deploy',
                                'type' => 'number',
                                'attributes' => ['min' => '0', 'step' => '1'],
                            ]); ?>
                            <?php $render_display_field('popup_label_auto_hide_seconds', __('Auto-hide (sec)', 'gpt3-ai-content-generator'), $popup_label_auto_hide_seconds, [
                                'id' => 'popup_label_auto_hide_seconds_deploy',
                                'type' => 'number',
                                'attributes' => ['min' => '0', 'step' => '1'],
                            ]); ?>
                            <?php $render_display_field('popup_label_dismissible', __('Closable', 'gpt3-ai-content-generator'), $popup_label_dismissible, [
                                'id' => 'popup_label_dismissible_deploy',
                                'options' => ['1' => __('Yes', 'gpt3-ai-content-generator'), '0' => __('No', 'gpt3-ai-content-generator')],
                            ]); ?>
                            <?php $render_display_field('popup_label_show_on_desktop', __('Desktop', 'gpt3-ai-content-generator'), $popup_label_show_on_desktop, [
                                'id' => 'popup_label_show_on_desktop_deploy',
                                'options' => ['1' => __('Yes', 'gpt3-ai-content-generator'), '0' => __('No', 'gpt3-ai-content-generator')],
                            ]); ?>
                            <?php $render_display_field('popup_label_show_on_mobile', __('Mobile', 'gpt3-ai-content-generator'), $popup_label_show_on_mobile, [
                                'id' => 'popup_label_show_on_mobile_deploy',
                                'options' => ['1' => __('Yes', 'gpt3-ai-content-generator'), '0' => __('No', 'gpt3-ai-content-generator')],
                            ]); ?>
                            <?php $render_display_field('popup_label_version', __('Version', 'gpt3-ai-content-generator'), $popup_label_version, [
                                'id' => 'popup_label_version_deploy',
                                'attributes' => ['placeholder' => 'v1'],
                            ]); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
