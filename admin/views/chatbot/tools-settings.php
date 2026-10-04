<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bot_id = $initial_active_bot_id;
$current_provider_for_this_bot = isset($current_provider_for_this_bot)
    ? (string) $current_provider_for_this_bot
    : 'OpenAI';
$rt_disabled_by_plan = isset($rt_disabled_by_plan)
    ? (bool) $rt_disabled_by_plan
    : !(isset($is_pro_plan) && $is_pro_plan);
$realtime_voice_toggle_value = (!$rt_disabled_by_plan && ($enable_realtime_voice ?? '0') === '1')
    ? '1'
    : '0';
$stt_controls_hidden_for_tools = false;
$xai_web_search_enabled_val = isset($xai_web_search_enabled_val) && in_array($xai_web_search_enabled_val, ['0', '1'], true)
    ? $xai_web_search_enabled_val
    : '0';

$web_tool_providers = [
    'OpenAI' => [
        'slug' => 'openai', 'setting' => 'openai_web_search_enabled',
        'toggle_class' => 'aipkit_openai_web_search_enable_toggle', 'value' => $openai_web_search_enabled_val,
    ],
    'Google' => [
        'slug' => 'google', 'setting' => 'google_search_grounding_enabled',
        'toggle_class' => 'aipkit_google_search_grounding_enable_toggle', 'value' => $google_search_grounding_enabled_val,
    ],
    'Claude' => [
        'slug' => 'claude', 'setting' => 'claude_web_search_enabled',
        'toggle_class' => 'aipkit_claude_web_search_enable_toggle', 'value' => $claude_web_search_enabled_val,
    ],
    'OpenRouter' => [
        'slug' => 'openrouter', 'setting' => 'openrouter_web_search_enabled',
        'toggle_class' => 'aipkit_openrouter_web_search_enable_toggle', 'value' => $openrouter_web_search_enabled_val,
    ],
    'xAI' => [
        'slug' => 'xai', 'setting' => 'xai_web_search_enabled',
        'toggle_class' => 'aipkit_xai_web_search_enable_toggle', 'value' => $xai_web_search_enabled_val,
    ],
];
$is_current_provider_web_enabled = ($web_tool_providers[$current_provider_for_this_bot]['value'] ?? '0') === '1';

$tools_master_options = [
    'file_upload'    => [
        'label'    => __('File upload', 'gpt3-ai-content-generator'),
        'hint'     => __('Allow users to upload documents.', 'gpt3-ai-content-generator'),
        'enabled'  => ($file_upload_toggle_value ?? '0') === '1',
        'disabled' => !$can_enable_file_upload,
    ],
    'web_search'     => [
        'label'    => __('Web search', 'gpt3-ai-content-generator'),
        'hint'     => __('Use online sources in responses.', 'gpt3-ai-content-generator'),
        'enabled'  => $is_current_provider_web_enabled,
        'disabled' => false,
    ],
    'image_analysis' => [
        'label'    => __('Image analysis', 'gpt3-ai-content-generator'),
        'hint'     => __('Let users attach images in chat.', 'gpt3-ai-content-generator'),
        'enabled'  => ($enable_image_upload ?? '0') === '1',
        'disabled' => false,
    ],
    'image_generation' => [
        'label'    => __('Image generation', 'gpt3-ai-content-generator'),
        'hint'     => __('Generate images using chat.', 'gpt3-ai-content-generator'),
        'enabled'  => ($enable_image_generation ?? '0') === '1',
        'disabled' => false,
    ],
    'speech_to_text' => [
        'label'    => __('Speech to text', 'gpt3-ai-content-generator'),
        'hint'     => __('Capture voice input from users.', 'gpt3-ai-content-generator'),
        'enabled'  => ($enable_voice_input ?? '0') === '1',
        'disabled' => false,
    ],
    'text_to_speech' => [
        'label'    => __('Text to speech', 'gpt3-ai-content-generator'),
        'hint'     => __('Read assistant replies aloud.', 'gpt3-ai-content-generator'),
        'enabled'  => ($tts_enabled ?? '0') === '1',
        'disabled' => false,
    ],
    'realtime_voice' => [
        'label'    => __('Realtime voice', 'gpt3-ai-content-generator'),
        'hint'     => __('Let users talk with the chatbot.', 'gpt3-ai-content-generator'),
        'enabled'  => $realtime_voice_toggle_value === '1',
        'disabled' => $rt_disabled_by_plan,
    ],
];

$known_image_model_ids = [];
foreach ($available_image_models as $provider_group => $models) {
    foreach ($models as $model) {
        $model_id = isset($model['id']) ? (string) $model['id'] : '';
        if ($model_id === '') {
            continue;
        }
        $known_image_model_ids[] = $model_id;
    }
}

$file_upload_locked_by_plan = !$is_pro_plan && !$can_enable_file_upload;
$realtime_voice_locked_by_plan = !$is_pro_plan && $rt_disabled_by_plan;

$render_tool_upgrade_button = static function () use ($pricing_url): void {
    ?>
    <a
        class="aipkit_tools_enabled_item_upgrade aipkit_popover_upgrade_link aipkit_pro_upgrade_button"
        href="<?php echo esc_url($pricing_url); ?>"
        target="_blank"
        rel="noopener noreferrer"
    >
        <?php esc_html_e('Upgrade', 'gpt3-ai-content-generator'); ?>
    </a>
    <?php
};

$render_tool_enable_control = static function (string $tool_key, array $tool_option, string $input_id, string $extra_label_class = '') use ($is_pro_plan): void {
    $is_disabled = !empty($tool_option['disabled']);
    $is_plan_locked = !$is_pro_plan
        && $is_disabled
        && in_array($tool_key, ['file_upload', 'realtime_voice'], true);
    ?>
    <div class="aipkit_tools_enable_control">
        <?php if (!$is_plan_locked) : ?>
            <label class="aipkit_tools_enable_label aipkit_settings_big_checkbox<?php echo $is_disabled ? ' is-disabled' : ''; ?><?php echo $extra_label_class !== '' ? ' ' . esc_attr($extra_label_class) : ''; ?>" for="<?php echo esc_attr($input_id); ?>">
                <input
                    type="checkbox"
                    id="<?php echo esc_attr($input_id); ?>"
                    class="aipkit_tools_enabled_option"
                    value="<?php echo esc_attr($tool_key); ?>"
                    data-tool-key="<?php echo esc_attr($tool_key); ?>"
                    data-static-disabled="<?php echo $is_disabled ? '1' : '0'; ?>"
                    <?php checked(!empty($tool_option['enabled'])); ?>
                    <?php disabled($is_disabled); ?>
                />
                <span class="aipkit_settings_big_checkbox_box" aria-hidden="true">
                    <span class="dashicons dashicons-saved"></span>
                </span>
        <?php else : ?>
            <div class="aipkit_tools_enable_label aipkit_settings_big_checkbox is-disabled<?php echo $extra_label_class !== '' ? ' ' . esc_attr($extra_label_class) : ''; ?>">
        <?php endif; ?>
            <span class="aipkit_tools_feature_text">
                <span class="aipkit_tools_feature_label aipkit_popover_option_label">
                    <?php echo esc_html($tool_option['label']); ?>
                </span>
                <span class="aipkit_tools_feature_hint">
                    <?php echo esc_html($tool_option['hint']); ?>
                </span>
            </span>
        <?php if (!$is_plan_locked) : ?>
            </label>
        <?php else : ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
};
?>

<div class="aipkit_tools_feature_rows aipkit_display_settings_rows">
    <div class="aipkit_tools_feature_row aipkit_popover_option_row aipkit_popover_option_row--file-upload<?php echo $can_enable_file_upload ? '' : ' aipkit_popover_option_row--disabled'; ?><?php echo $file_upload_locked_by_plan ? ' aipkit_tools_feature_row--upgrade' : ''; ?><?php echo !empty($tools_master_options['file_upload']['enabled']) ? ' aipkit_tools_feature_row--is-enabled' : ''; ?>" data-aipkit-tool-key="file_upload">
        <div class="aipkit_tools_feature_left">
            <?php $render_tool_enable_control('file_upload', $tools_master_options['file_upload'], 'aipkit_bot_' . $bot_id . '_file_upload_tool_toggle'); ?>
        </div>
        <div class="aipkit_tools_feature_right">
            <select
                id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_enable_file_upload_tools"
                name="enable_file_upload"
                class="aipkit_popover_option_select aipkit_tools_toggle_select aipkit_tools_state_field aipkit_file_upload_toggle_select aipkit_file_upload_toggle_switch"
                data-is-pro-plan="<?php echo esc_attr($is_pro_plan ? 'true' : 'false'); ?>"
                aria-hidden="true"
                tabindex="-1"


                <?php disabled(!$can_enable_file_upload); ?>
            >
                <option value="1" <?php selected($file_upload_toggle_value, '1'); ?>><?php esc_html_e('Yes', 'gpt3-ai-content-generator'); ?></option>
                <option value="0" <?php selected($file_upload_toggle_value, '0'); ?>><?php esc_html_e('No', 'gpt3-ai-content-generator'); ?></option>
            </select>
            <?php if ($file_upload_locked_by_plan) : ?>
                <?php $render_tool_upgrade_button(); ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="aipkit_tools_feature_row aipkit_popover_option_row aipkit_image_analysis_popover_row<?php echo !empty($tools_master_options['image_analysis']['enabled']) ? ' aipkit_tools_feature_row--is-enabled' : ''; ?>" data-aipkit-tool-key="image_analysis" style="<?php echo \WPAICG\AIPKit_Providers::model_supports_image_input($current_provider_for_this_bot, (string) ($active_bot_settings['model'] ?? '')) ? '' : 'display:none;'; ?>">
        <div class="aipkit_tools_feature_left">
            <?php $render_tool_enable_control('image_analysis', $tools_master_options['image_analysis'], 'aipkit_bot_' . $bot_id . '_image_analysis_tool_toggle'); ?>
        </div>
        <div class="aipkit_tools_feature_right">
            <select
                id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_enable_image_upload_tools"
                name="enable_image_upload"
                class="aipkit_popover_option_select aipkit_tools_toggle_select aipkit_tools_state_field aipkit_image_analysis_select aipkit_image_analysis_checkbox"
                aria-hidden="true"
                tabindex="-1"
            >
                <option value="1" <?php selected($enable_image_upload, '1'); ?>><?php esc_html_e('Yes', 'gpt3-ai-content-generator'); ?></option>
                <option value="0" <?php selected($enable_image_upload, '0'); ?>><?php esc_html_e('No', 'gpt3-ai-content-generator'); ?></option>
            </select>
        </div>
    </div>

    <div class="aipkit_tools_feature_row aipkit_popover_option_row aipkit_tools_feature_row--image-generation aipkit_tools_feature_row--expandable<?php echo (($enable_image_generation ?? '0') === '1') ? ' aipkit_tools_feature_row--is-enabled' : ''; ?>" data-aipkit-tool-key="image_generation" data-aipkit-inline-settings-row data-aipkit-inline-settings-target="aipkit_builder_image_generation_settings_modal">
        <div class="aipkit_tools_feature_left">
            <?php $render_tool_enable_control('image_generation', $tools_master_options['image_generation'], 'aipkit_bot_' . $bot_id . '_image_generation_tool_toggle'); ?>
        </div>
        <div class="aipkit_tools_feature_right aipkit_tools_feature_right--image-generation">
            <button
                type="button"
                class="aipkit_popover_option_btn aipkit_image_generation_config_btn aipkit_tools_options_btn aipkit_interface_feature_expand_btn"
                data-aipkit-inline-settings-toggle
                aria-expanded="false"
                aria-controls="aipkit_builder_image_generation_settings_modal"
                aria-label="<?php esc_attr_e('Image generation settings', 'gpt3-ai-content-generator'); ?>"
                aria-disabled="<?php echo (($enable_image_generation ?? '0') === '1') ? 'false' : 'true'; ?>"
                title="<?php esc_attr_e('Settings', 'gpt3-ai-content-generator'); ?>"
                <?php disabled(($enable_image_generation ?? '0') !== '1'); ?>
            >
                <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
            </button>
            <div
                id="aipkit_builder_image_generation_settings_modal"
                class="aipkit_builder_image_generation_settings_modal"
                aria-hidden="true"
            >
                <div
                    class="aipkit-modal-content"
                    role="dialog"
                    aria-modal="false"
                >
                    <div class="aipkit_inline_settings_body aipkit_settings_image_body">
                        <div class="aipkit_tools_image_generation_controls aipkit_tools_image_generation_panel">
                            <div class="aipkit_tools_image_generation_field">
                                <label
                                    class="aipkit_popover_option_label"
                                    for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_chat_image_model_id_tools"
                                >
                                    <?php esc_html_e('Model', 'gpt3-ai-content-generator'); ?>
                                </label>
                                <select
                                    id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_chat_image_model_id_tools"
                                    name="chat_image_model_id"
                                    class="aipkit_form-input aipkit_popover_option_select aipkit_tools_image_model_select"
                                    data-aipkit-universal-model-combined="1" data-aipkit-universal-model-capability="image_generation"
                                >
                                    <option value=""><?php esc_html_e('Select model', 'gpt3-ai-content-generator'); ?></option>
                                    <?php foreach ($available_image_models as $provider_group => $models) : ?>
                                        <optgroup label="<?php echo esc_attr($provider_group); ?>" data-provider="<?php echo esc_attr($provider_group); ?>">
                                            <?php foreach ($models as $model) : ?>
                                                <option
                                                    value="<?php echo esc_attr($model['id']); ?>"
                                                    data-provider="<?php echo esc_attr($provider_group); ?>"
                                                    data-provider-group="<?php echo esc_attr($provider_group); ?>"
                                                    data-model="<?php echo esc_attr($model['id']); ?>"
                                                    <?php selected($chat_image_model_id, $model['id']); ?>
                                                >
                                                    <?php echo esc_html($model['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                    <?php if (!array_key_exists('Replicate', $available_image_models)) : ?>
                                        <optgroup label="Replicate" data-provider="Replicate"></optgroup>
                                    <?php endif; ?>
                                    <?php if (!empty($chat_image_model_id) && !in_array((string) $chat_image_model_id, $known_image_model_ids, true)) : ?>
                                        <option value="<?php echo esc_attr($chat_image_model_id); ?>" data-provider="manual" data-provider-label="<?php esc_attr_e('Current', 'gpt3-ai-content-generator'); ?>" data-model="<?php echo esc_attr($chat_image_model_id); ?>" selected="selected">
                                            <?php echo esc_html((string) $chat_image_model_id); ?>
                                        </option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="aipkit_tools_image_generation_field">
                                <label
                                    class="aipkit_popover_option_label"
                                    for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_image_triggers_tools"
                                >
                                    <?php esc_html_e('Trigger phrases', 'gpt3-ai-content-generator'); ?>
                                </label>
                                <input
                                    type="text"
                                    id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_image_triggers_tools"
                                    name="image_triggers"
                                    class="aipkit_form-input aipkit_popover_option_input aipkit_popover_option_input--wide aipkit_popover_option_input--framed"
                                    placeholder="/image, /generate"
                                    value="<?php echo esc_attr($image_triggers); ?>"
                                    aria-label="<?php esc_attr_e('Image generation triggers', 'gpt3-ai-content-generator'); ?>"
                                />
                            </div>
                        </div>
            <select
                id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_image_generation_visibility_tools"
                name="enable_image_generation"
                class="aipkit_form-input aipkit_popover_option_select aipkit_tools_image_generation_toggle aipkit_tools_state_field aipkit_tools_state_field_hidden"
                aria-hidden="true"
                tabindex="-1"
            >
                <option value="1" <?php selected($enable_image_generation ?? '0', '1'); ?>><?php esc_html_e('Yes', 'gpt3-ai-content-generator'); ?></option>
                <option value="0" <?php selected($enable_image_generation ?? '0', '0'); ?>><?php esc_html_e('No', 'gpt3-ai-content-generator'); ?></option>
            </select>
            <?php
            $aipkit_notice_id = 'aipkit_tools_image_provider_warning_' . (string) $bot_id;
            $aipkit_notice_class = 'aipkit_tools_image_provider_warning';
            $aipkit_notice_context = __('generate images in this chatbot', 'gpt3-ai-content-generator');
            $aipkit_notice_model_source = 'aipkit_bot_' . $bot_id . '_chat_image_model_id_tools';
            include WPAICG_PLUGIN_DIR . 'admin/views/shared/provider-key-notice.php';
            ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php foreach ($web_tool_providers as $web_tool_provider_name => $web_tool_provider) : ?>
    <div class="aipkit_tools_feature_row aipkit_popover_option_row aipkit_web_search_toggle_<?php echo esc_attr($web_tool_provider['slug']); ?> aipkit_tools_feature_row--expandable<?php echo ($web_tool_provider['value'] === '1') ? ' aipkit_tools_feature_row--is-enabled' : ''; ?>" data-aipkit-tool-key="web_search" data-aipkit-inline-settings-row data-aipkit-inline-settings-target="aipkit_builder_web_settings_modal" style="<?php echo ($current_provider_for_this_bot === $web_tool_provider_name) ? '' : 'display:none;'; ?>">
        <div class="aipkit_tools_feature_left">
            <?php $render_tool_enable_control('web_search', $tools_master_options['web_search'], 'aipkit_bot_' . $bot_id . '_web_search_tool_toggle_' . $web_tool_provider['slug']); ?>
        </div>
        <div class="aipkit_tools_feature_right">
            <select
                id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_<?php echo esc_attr($web_tool_provider['setting']); ?>_tools"
                name="<?php echo esc_attr($web_tool_provider['setting']); ?>"
                class="aipkit_popover_option_select aipkit_tools_toggle_select aipkit_tools_state_field <?php echo esc_attr($web_tool_provider['toggle_class']); ?>"
                aria-hidden="true"
                tabindex="-1"
            >
                <option value="1" <?php selected($web_tool_provider['value'], '1'); ?>><?php esc_html_e('Yes', 'gpt3-ai-content-generator'); ?></option>
                <option value="0" <?php selected($web_tool_provider['value'], '0'); ?>><?php esc_html_e('No', 'gpt3-ai-content-generator'); ?></option>
            </select>
            <button
                type="button"
                class="aipkit_popover_option_btn aipkit_web_search_config_btn aipkit_tools_options_btn aipkit_interface_feature_expand_btn"
                data-aipkit-inline-settings-toggle
                data-web-provider="<?php echo esc_attr($web_tool_provider['slug']); ?>"
                aria-expanded="false"
                aria-controls="aipkit_builder_web_settings_modal"
                aria-label="<?php esc_attr_e('Web search settings', 'gpt3-ai-content-generator'); ?>"
                aria-disabled="<?php echo ($web_tool_provider['value'] === '1') ? 'false' : 'true'; ?>"
                title="<?php esc_attr_e('Settings', 'gpt3-ai-content-generator'); ?>"
                <?php disabled($web_tool_provider['value'] !== '1'); ?>
            >
                <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
            </button>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="aipkit_tools_feature_row aipkit_popover_option_row aipkit_audio_toggle_voice_input_row aipkit_tools_feature_row--expandable<?php echo ($enable_voice_input === '1') ? ' aipkit_tools_feature_row--is-enabled' : ''; ?>" data-aipkit-tool-key="speech_to_text" data-aipkit-inline-settings-row data-aipkit-inline-settings-target="aipkit_builder_audio_settings_modal">
        <div class="aipkit_tools_feature_left">
            <?php $render_tool_enable_control('speech_to_text', $tools_master_options['speech_to_text'], 'aipkit_bot_' . $bot_id . '_speech_to_text_tool_toggle'); ?>
        </div>
        <div class="aipkit_tools_feature_right">
            <select
                id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_enable_voice_input_tools"
                name="enable_voice_input"
                class="aipkit_popover_option_select aipkit_tools_toggle_select aipkit_tools_state_field aipkit_voice_input_toggle_switch"
                aria-hidden="true"
                tabindex="-1"
            >
                <option value="1" <?php selected($enable_voice_input, '1'); ?>><?php esc_html_e('Yes', 'gpt3-ai-content-generator'); ?></option>
                <option value="0" <?php selected($enable_voice_input, '0'); ?>><?php esc_html_e('No', 'gpt3-ai-content-generator'); ?></option>
            </select>
            <button
                type="button"
                class="aipkit_popover_option_btn aipkit_audio_settings_config_btn aipkit_tools_options_btn aipkit_interface_feature_expand_btn"
                data-aipkit-inline-settings-toggle
                data-audio-feature="stt"
                aria-expanded="false"
                aria-controls="aipkit_builder_audio_settings_modal"
                aria-label="<?php esc_attr_e('Speech to text settings', 'gpt3-ai-content-generator'); ?>"
                aria-disabled="<?php echo ($enable_voice_input === '1' && !$stt_controls_hidden_for_tools) ? 'false' : 'true'; ?>"
                title="<?php esc_attr_e('Settings', 'gpt3-ai-content-generator'); ?>"
                style="<?php echo $stt_controls_hidden_for_tools ? 'display:none;' : ''; ?>"
                <?php disabled($enable_voice_input !== '1' || $stt_controls_hidden_for_tools); ?>
            >
                <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
            </button>
        </div>
    </div>

    <div class="aipkit_tools_feature_row aipkit_popover_option_row aipkit_audio_toggle_tts_row aipkit_tools_feature_row--expandable<?php echo ($tts_enabled === '1') ? ' aipkit_tools_feature_row--is-enabled' : ''; ?>" data-aipkit-tool-key="text_to_speech" data-aipkit-inline-settings-row data-aipkit-inline-settings-target="aipkit_builder_audio_settings_modal">
        <div class="aipkit_tools_feature_left">
            <?php $render_tool_enable_control('text_to_speech', $tools_master_options['text_to_speech'], 'aipkit_bot_' . $bot_id . '_text_to_speech_tool_toggle'); ?>
        </div>
        <div class="aipkit_tools_feature_right">
            <select
                id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_tts_enabled_tools"
                name="tts_enabled"
                class="aipkit_popover_option_select aipkit_tools_toggle_select aipkit_tools_state_field aipkit_tts_toggle_switch"
                aria-hidden="true"
                tabindex="-1"
            >
                <option value="1" <?php selected($tts_enabled, '1'); ?>><?php esc_html_e('Yes', 'gpt3-ai-content-generator'); ?></option>
                <option value="0" <?php selected($tts_enabled, '0'); ?>><?php esc_html_e('No', 'gpt3-ai-content-generator'); ?></option>
            </select>
            <button
                type="button"
                class="aipkit_popover_option_btn aipkit_audio_settings_config_btn aipkit_tools_options_btn aipkit_interface_feature_expand_btn"
                data-aipkit-inline-settings-toggle
                data-audio-feature="tts"
                aria-expanded="false"
                aria-controls="aipkit_builder_audio_settings_modal"
                aria-label="<?php esc_attr_e('Text to speech settings', 'gpt3-ai-content-generator'); ?>"
                aria-disabled="<?php echo ($tts_enabled === '1') ? 'false' : 'true'; ?>"
                title="<?php esc_attr_e('Settings', 'gpt3-ai-content-generator'); ?>"
                <?php disabled($tts_enabled !== '1'); ?>
            >
                <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
            </button>
        </div>
    </div>

    <div
        class="aipkit_tools_feature_row aipkit_popover_option_row aipkit_audio_toggle_realtime_row<?php echo $realtime_voice_locked_by_plan ? ' aipkit_popover_option_row--disabled aipkit_tools_feature_row--upgrade' : ' aipkit_tools_feature_row--expandable'; ?><?php echo ($realtime_voice_toggle_value === '1') ? ' aipkit_tools_feature_row--is-enabled' : ''; ?>"
        data-aipkit-tool-key="realtime_voice"
        <?php echo $realtime_voice_locked_by_plan ? '' : 'data-aipkit-inline-settings-row data-aipkit-inline-settings-target="aipkit_builder_audio_settings_modal"'; ?>
    >
        <div class="aipkit_tools_feature_left">
            <?php $render_tool_enable_control('realtime_voice', $tools_master_options['realtime_voice'], 'aipkit_bot_' . $bot_id . '_realtime_voice_tool_toggle'); ?>
        </div>
        <div class="aipkit_tools_feature_right">
            <select
                id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_enable_realtime_voice_tools"
                name="enable_realtime_voice"
                class="aipkit_popover_option_select aipkit_tools_toggle_select aipkit_tools_state_field aipkit_enable_realtime_voice_toggle"
                aria-hidden="true"
                tabindex="-1"
                <?php echo $rt_disabled_by_plan ? 'disabled' : ''; ?>
            >
                <option value="1" <?php selected($realtime_voice_toggle_value, '1'); ?>><?php esc_html_e('Yes', 'gpt3-ai-content-generator'); ?></option>
                <option value="0" <?php selected($realtime_voice_toggle_value, '0'); ?>><?php esc_html_e('No', 'gpt3-ai-content-generator'); ?></option>
            </select>
            <?php if ($realtime_voice_locked_by_plan) : ?>
                <?php $render_tool_upgrade_button(); ?>
            <?php else : ?>
                <button
                    type="button"
                    class="aipkit_popover_option_btn aipkit_audio_settings_config_btn aipkit_tools_options_btn aipkit_interface_feature_expand_btn"
                    data-aipkit-inline-settings-toggle
                    data-audio-feature="realtime"
                    aria-expanded="false"
                    aria-controls="aipkit_builder_audio_settings_modal"
                    aria-label="<?php esc_attr_e('Realtime voice settings', 'gpt3-ai-content-generator'); ?>"
                    aria-disabled="<?php echo ($realtime_voice_toggle_value === '1') ? 'false' : 'true'; ?>"
                    title="<?php esc_attr_e('Settings', 'gpt3-ai-content-generator'); ?>"
                    <?php disabled($realtime_voice_toggle_value !== '1'); ?>
                >
                    <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<div
    id="aipkit_builder_web_settings_modal"
    class="aipkit_builder_web_settings_modal"
    aria-hidden="true"
>
    <div
        class="aipkit-modal-content"
        role="dialog"
        aria-modal="false"
    >
        <div class="aipkit_inline_settings_body aipkit_settings_web_body">
            <?php include __DIR__ . '/web-settings-panel.php'; ?>
        </div>
    </div>
</div>

<div
    id="aipkit_builder_audio_settings_modal"
    class="aipkit_builder_audio_settings_modal"
    aria-hidden="true"
>
    <div
        class="aipkit-modal-content"
        role="dialog"
        aria-modal="false"
    >
        <div class="aipkit_inline_settings_body aipkit_settings_audio_body">
            <span class="aipkit_popover_status_inline aipkit_tts_sync_status" aria-live="polite"></span>
            <?php include __DIR__ . '/audio-settings-panel.php'; ?>
        </div>
    </div>
</div>
