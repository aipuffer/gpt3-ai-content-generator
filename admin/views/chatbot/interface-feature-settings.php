<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bot_id = $initial_active_bot_id;
$bot_settings = $active_bot_settings;
$enable_fullscreen = $bot_settings['enable_fullscreen']
    ?? \WPAICG\Chat\Storage\BotSettingsManager::DEFAULT_ENABLE_FULLSCREEN;
$enable_download = $bot_settings['enable_download']
    ?? \WPAICG\Chat\Storage\BotSettingsManager::DEFAULT_ENABLE_DOWNLOAD;
$enable_conversation_starters = $bot_settings['enable_conversation_starters']
    ?? \WPAICG\Chat\Storage\BotSettingsManager::DEFAULT_ENABLE_CONVERSATION_STARTERS;
$enable_conversation_sidebar = $bot_settings['enable_conversation_sidebar']
    ?? \WPAICG\Chat\Storage\BotSettingsManager::DEFAULT_ENABLE_CONVERSATION_SIDEBAR;
$enable_consent_compliance = $bot_settings['enable_consent_compliance']
    ?? \WPAICG\Chat\Storage\BotSettingsManager::DEFAULT_ENABLE_CONSENT_COMPLIANCE;
$enable_consent_compliance = in_array($enable_consent_compliance, ['0', '1'], true)
    ? $enable_consent_compliance
    : \WPAICG\Chat\Storage\BotSettingsManager::DEFAULT_ENABLE_CONSENT_COMPLIANCE;
$consent_toggle_id = 'aipkit_bot_' . $bot_id . '_enable_consent_compliance';
$consent_toggle_display_id = $consent_toggle_id . '_display';
$consent_toggle_value = ($consent_feature_available && $enable_consent_compliance === '1') ? '1' : '0';

$render_display_feature_control = static function (array $feature): void {
    $feature_id = isset($feature['id']) ? (string) $feature['id'] : '';
    $feature_key = isset($feature['key']) ? (string) $feature['key'] : '';
    $feature_label = isset($feature['label']) ? (string) $feature['label'] : '';
    $feature_hint = isset($feature['hint']) ? (string) $feature['hint'] : '';
    $is_checked = !empty($feature['checked']);
    $is_disabled = !empty($feature['disabled']);
    $is_hidden = !empty($feature['hidden']);
    $show_toggle = !array_key_exists('show_toggle', $feature) || !empty($feature['show_toggle']);
    $row_class = isset($feature['row_class']) ? (string) $feature['row_class'] : '';
    $input_class = isset($feature['input_class']) ? (string) $feature['input_class'] : '';
    $extra_content = isset($feature['extra']) ? (string) $feature['extra'] : '';
    $panel_target = isset($feature['panel_target']) ? (string) $feature['panel_target'] : '';
    $is_expandable = $panel_target !== '' && $extra_content !== '';
    ?>
    <div
        class="aipkit_interface_feature_row aipkit_display_settings_row<?php echo $is_expandable ? ' aipkit_interface_feature_row--expandable' : ''; ?><?php echo $row_class !== '' ? ' ' . esc_attr($row_class) : ''; ?><?php echo $is_disabled ? ' is-disabled' : ''; ?>"
        <?php echo $is_hidden ? ' hidden' : ''; ?>
        <?php echo $is_expandable ? ' data-aipkit-inline-settings-row data-aipkit-inline-settings-target="' . esc_attr($panel_target) . '"' : ''; ?>
    >
        <?php if ($show_toggle) : ?>
            <label class="aipkit_interface_feature_label aipkit_settings_big_checkbox<?php echo $is_disabled ? ' is-disabled' : ''; ?>" for="<?php echo esc_attr($feature_id); ?>">
                <input
                    type="checkbox"
                    id="<?php echo esc_attr($feature_id); ?>"
                    class="aipkit_interface_control_option<?php echo $input_class !== '' ? ' ' . esc_attr($input_class) : ''; ?>"
                    value="<?php echo esc_attr($feature_key); ?>"
                    <?php checked($is_checked); ?>
                    <?php disabled($is_disabled); ?>
                />
                <span class="aipkit_settings_big_checkbox_box" aria-hidden="true">
                    <span class="dashicons dashicons-saved"></span>
                </span>
        <?php else : ?>
            <div class="aipkit_interface_feature_label aipkit_settings_big_checkbox is-disabled">
        <?php endif; ?>
            <span class="aipkit_interface_feature_text">
                <span class="aipkit_interface_feature_title aipkit_popover_option_label">
                    <?php echo esc_html($feature_label); ?>
                </span>
                <?php if ($feature_hint !== '') : ?>
                    <span class="aipkit_interface_feature_hint">
                        <?php echo esc_html($feature_hint); ?>
                    </span>
                <?php endif; ?>
            </span>
        <?php if ($show_toggle) : ?>
            </label>
        <?php else : ?>
            </div>
        <?php endif; ?>
        <?php if ($extra_content !== '') : ?>
            <div class="aipkit_interface_feature_action">
                <?php echo $extra_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
        <?php endif; ?>
        <?php if ($is_expandable) : ?>
            <div
                class="aipkit_interface_feature_inline_panel"
                data-aipkit-inline-settings-host="<?php echo esc_attr($panel_target); ?>"
                hidden
            ></div>
        <?php endif; ?>
    </div>
    <?php
};
?>
<div class="aipkit_general_features_section">
    <div class="aipkit_interface_feature_rows aipkit_display_settings_rows" data-aipkit-interface-controls>
        <?php
        if ($consent_feature_available) {
            $consent_extra = sprintf(
                '<button type="button" class="aipkit_popover_option_btn aipkit_consent_config_btn aipkit_consent_config_btn--inline aipkit_interface_feature_expand_btn" data-feature="consent_notice" data-aipkit-inline-settings-toggle aria-expanded="false" aria-controls="aipkit_consent_panel" aria-label="%2$s" title="%2$s"%1$s><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>',
                ($enable_consent_compliance === '1') ? ' aria-disabled="false"' : ' disabled aria-disabled="true"',
                esc_attr__('Consent settings', 'gpt3-ai-content-generator')
            );
        } else {
            $consent_extra = sprintf(
                '<a class="aipkit_tools_enabled_item_upgrade aipkit_popover_upgrade_link aipkit_pro_upgrade_button" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
                esc_url($pricing_url),
                esc_html__('Upgrade', 'gpt3-ai-content-generator')
            );
        }
        $render_display_feature_control([
            'id' => 'aipkit_bot_' . $bot_id . '_download_feature',
            'key' => 'enable_download',
            'label' => __('Download', 'gpt3-ai-content-generator'),
            'hint' => __('Let users download the conversation.', 'gpt3-ai-content-generator'),
            'checked' => (string) $enable_download === '1',
        ]);
        $render_display_feature_control([
            'id' => $consent_toggle_display_id,
            'key' => 'enable_consent_compliance',
            'label' => __('Consent', 'gpt3-ai-content-generator'),
            'hint' => __('Require consent before chat.', 'gpt3-ai-content-generator'),
            'checked' => $consent_toggle_value === '1',
            'disabled' => !$consent_feature_available,
            'show_toggle' => $consent_feature_available,
            'row_class' => 'aipkit_interface_control_item--consent' . ($consent_feature_available ? '' : ' aipkit_interface_feature_row--upgrade'),
            'extra' => $consent_extra,
            'panel_target' => $consent_feature_available ? 'aipkit_consent_panel' : '',
        ]);
        $render_display_feature_control([
            'id' => 'aipkit_bot_' . $bot_id . '_fullscreen_feature',
            'key' => 'enable_fullscreen',
            'label' => __('Fullscreen', 'gpt3-ai-content-generator'),
            'hint' => __('Allow the chat window to expand.', 'gpt3-ai-content-generator'),
            'checked' => (string) $enable_fullscreen === '1',
        ]);
        $render_display_feature_control([
            'id' => 'aipkit_bot_' . $bot_id . '_sidebar_feature',
            'key' => 'enable_conversation_sidebar',
            'label' => __('Sidebar', 'gpt3-ai-content-generator'),
            'hint' => __('Show conversation history in inline chat.', 'gpt3-ai-content-generator'),
            'checked' => (string) $enable_conversation_sidebar === '1',
            'disabled' => $popup_enabled === '1',
            'hidden' => $popup_enabled === '1',
            'row_class' => 'aipkit_interface_control_item--sidebar',
            'input_class' => 'aipkit_interface_control_option--sidebar',
        ]);
        $starters_extra = sprintf(
            '<button type="button" class="aipkit_popover_option_btn aipkit_starters_config_btn aipkit_starters_config_btn--inline aipkit_interface_feature_expand_btn" data-feature="conversation_starters" data-aipkit-inline-settings-toggle aria-expanded="false" aria-controls="aipkit_starters_panel" aria-label="%2$s" title="%2$s"%1$s><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>',
            ((string) $enable_conversation_starters === '1') ? ' aria-disabled="false"' : ' disabled aria-disabled="true"',
            esc_attr__('Starter question settings', 'gpt3-ai-content-generator')
        );
        $render_display_feature_control([
            'id' => 'aipkit_bot_' . $bot_id . '_starters_feature',
            'key' => 'enable_conversation_starters',
            'label' => __('Suggested questions', 'gpt3-ai-content-generator'),
            'hint' => __('Show first questions visitors can tap.', 'gpt3-ai-content-generator'),
            'checked' => (string) $enable_conversation_starters === '1',
            'row_class' => 'aipkit_interface_control_item--starters',
            'extra' => $starters_extra,
            'panel_target' => 'aipkit_starters_panel',
        ]);
        ?>
    </div>
    <div
        class="aipkit_interface_control_hidden_fields aipkit_sidebar_toggle_group"
        id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_sidebar_group"
        aria-hidden="true"
    >
        <span class="aipkit_interface_toggle_label screen-reader-text">
            <?php esc_html_e('Sidebar', 'gpt3-ai-content-generator'); ?>
        </span>
        <select
            id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_enable_download"
            name="enable_download"
            class="aipkit_form-input aipkit_popover_option_select aipkit_toggle_switch_select aipkit_interface_control_hidden_select"
        >
            <option value="1" <?php selected($enable_download, '1'); ?>><?php esc_html_e('Yes', 'gpt3-ai-content-generator'); ?></option>
            <option value="0" <?php selected($enable_download, '0'); ?>><?php esc_html_e('No', 'gpt3-ai-content-generator'); ?></option>
        </select>
        <select
            id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_enable_fullscreen"
            name="enable_fullscreen"
            class="aipkit_form-input aipkit_popover_option_select aipkit_toggle_switch_select aipkit_interface_control_hidden_select"
        >
            <option value="1" <?php selected($enable_fullscreen, '1'); ?>><?php esc_html_e('Yes', 'gpt3-ai-content-generator'); ?></option>
            <option value="0" <?php selected($enable_fullscreen, '0'); ?>><?php esc_html_e('No', 'gpt3-ai-content-generator'); ?></option>
        </select>
        <select
            id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_enable_conversation_sidebar"
            name="enable_conversation_sidebar"
            class="aipkit_form-input aipkit_popover_option_select aipkit_toggle_switch_select aipkit_sidebar_toggle_switch aipkit_interface_control_hidden_select"
            <?php disabled($popup_enabled === '1'); ?>
        >
            <option value="1" <?php selected($enable_conversation_sidebar, '1'); ?>><?php esc_html_e('Yes', 'gpt3-ai-content-generator'); ?></option>
            <option value="0" <?php selected($enable_conversation_sidebar, '0'); ?>><?php esc_html_e('No', 'gpt3-ai-content-generator'); ?></option>
        </select>
        <select
            id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_enable_conversation_starters"
            name="enable_conversation_starters"
            class="aipkit_form-input aipkit_popover_option_select aipkit_toggle_switch_select aipkit_starters_toggle_switch aipkit_interface_control_hidden_select"
        >
            <option value="1" <?php selected($enable_conversation_starters, '1'); ?>><?php esc_html_e('Yes', 'gpt3-ai-content-generator'); ?></option>
            <option value="0" <?php selected($enable_conversation_starters, '0'); ?>><?php esc_html_e('No', 'gpt3-ai-content-generator'); ?></option>
        </select>
        <select
            id="<?php echo esc_attr($consent_toggle_id); ?>"
            name="enable_consent_compliance"
            class="aipkit_form-input aipkit_popover_option_select aipkit_toggle_switch_select aipkit_consent_toggle_switch aipkit_interface_control_hidden_select"
            <?php disabled(!$consent_feature_available); ?>
        >
            <option value="1" <?php selected($consent_toggle_value, '1'); ?>><?php esc_html_e('Yes', 'gpt3-ai-content-generator'); ?></option>
            <option value="0" <?php selected($consent_toggle_value, '0'); ?>><?php esc_html_e('No', 'gpt3-ai-content-generator'); ?></option>
        </select>
    </div>
</div>
