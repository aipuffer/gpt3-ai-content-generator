<?php
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.
$hide_stt_controls = false;
$cloud_stt_models = \WPAICG\Cloud\Connection::media_models('transcribe');
$cloud_tts_models = \WPAICG\Cloud\Connection::media_models('speech_generate');
$stt_provider_options = [
    '' => __('Select a provider', 'gpt3-ai-content-generator'),
    'OpenAI' => __('OpenAI', 'gpt3-ai-content-generator'),
    'Google' => __('Google', 'gpt3-ai-content-generator'),
    'AIPufferCloud' => __('AI Puffer', 'gpt3-ai-content-generator'),
];
$hide_stt_provider_field = count($stt_provider_options) <= 1;
$selected_stt_provider_for_ui = array_key_exists((string) $stt_provider, $stt_provider_options)
    ? (string) $stt_provider
    : '';
$default_stt_model = \WPAICG\Chat\Storage\BotSettingsManager::get_default_model_id('OpenAISTT');
$default_google_stt_model = \WPAICG\AIPKit_Providers::normalize_google_stt_model('');
$stt_model_fields = [
    'OpenAI' => ['name' => 'stt_openai_model_id', 'models' => $openai_stt_models, 'value' => $stt_openai_model_id, 'default' => $default_stt_model],
    'Google' => ['name' => 'stt_google_model_id', 'models' => $google_stt_models, 'value' => $stt_google_model_id, 'default' => $default_google_stt_model],
];
$stt_model_fields['AIPufferCloud'] = ['name' => 'stt_cloud_model_id', 'models' => $cloud_stt_models, 'value' => $stt_cloud_model_id, 'default' => ''];
$tts_provider_fields = [
    'Google' => [
        'slug' => 'google', 'voice' => $google_tts_voices, 'model' => $google_tts_models,
        'voice_value' => $tts_google_voice_id, 'model_value' => $tts_google_model_id,
    ],
    'OpenAI' => [
        'slug' => 'openai', 'voice' => $openai_tts_voices, 'model' => $openai_tts_models,
        'voice_value' => $tts_openai_voice_id, 'model_value' => $tts_openai_model_id,
    ],
    'ElevenLabs' => [
        'slug' => 'elevenlabs', 'voice' => $elevenlabs_tts_voices, 'model' => $elevenlabs_tts_models,
        'voice_value' => $tts_elevenlabs_voice_id, 'model_value' => $tts_elevenlabs_model_id,
    ],
];
$tts_provider_fields['AIPufferCloud'] = ['slug' => 'cloud',
        'voice' => array_map(static function ($voice) { return ['id' => $voice, 'name' => ucfirst($voice)]; }, \WPAICG\Cloud\Connection::media_capabilities('speech_generate', (string) $tts_cloud_model_id)['voices'] ?? []),
        'voice_value' => $tts_cloud_voice_id, 'model' => $cloud_tts_models, 'model_value' => $tts_cloud_model_id];
$tts_field_labels = ['voice' => __('Voice', 'gpt3-ai-content-generator'), 'model' => __('Model', 'gpt3-ai-content-generator')];
$tts_empty_labels = ['voice' => __('-- Select Voice --', 'gpt3-ai-content-generator'), 'model' => __('-- Select Model (Optional) --', 'gpt3-ai-content-generator')];
?>
<div class="aipkit_popover_options_list" data-cloud-audio-models="<?php echo esc_attr(wp_json_encode(array_merge($cloud_stt_models, $cloud_tts_models))); ?>">
    <div class="aipkit_popover_option_group aipkit_audio_feature_group aipkit_audio_feature_group--stt">
        <div class="aipkit_popover_option_row aipkit_audio_toggle_row aipkit_audio_toggle_row--stt">
            <div class="aipkit_popover_option_main">
                <label
                    class="aipkit_popover_option_label"
                    for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_enable_voice_input_sheet"

                >
                    <?php esc_html_e('Speech to text', 'gpt3-ai-content-generator'); ?>
                </label>
                <div class="aipkit_popover_option_actions">
                    <select
                        id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_enable_voice_input_sheet"
                        name="enable_voice_input"
                        class="aipkit_popover_option_select aipkit_popover_option_select--compact aipkit_voice_input_toggle_switch"
                    >
                        <option value="1" <?php selected($enable_voice_input, '1'); ?>><?php esc_html_e('Yes', 'gpt3-ai-content-generator'); ?></option>
                        <option value="0" <?php selected($enable_voice_input, '0'); ?>><?php esc_html_e('No', 'gpt3-ai-content-generator'); ?></option>
                    </select>
                </div>
            </div>
        </div>
        <div
            class="aipkit_popover_option_row aipkit_stt_provider_row"
            data-stt-controls-hidden="<?php echo $hide_stt_controls ? '1' : '0'; ?>"
            data-stt-default-provider="OpenAI"
            data-stt-default-model="<?php echo esc_attr($default_stt_model); ?>"
            style="display: <?php echo ($enable_voice_input === '1' && !$hide_stt_controls) ? 'block' : 'none'; ?>;"
        >
            <div class="aipkit_popover_option_main">
                <div class="aipkit_audio_settings_grid aipkit_audio_settings_grid--stt aipkit_stt_provider_conditional_row" style="display: <?php echo ($enable_voice_input === '1' && !$hide_stt_controls) ? 'grid' : 'none'; ?>;">
                    <div class="aipkit_audio_settings_field aipkit_audio_settings_field--provider<?php echo $hide_stt_provider_field ? ' aipkit_audio_settings_field--hidden' : ''; ?>">
                        <label
                            class="aipkit_popover_option_label"
                            for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_stt_provider_sheet"
                        >
                            <?php esc_html_e('Provider', 'gpt3-ai-content-generator'); ?>
                        </label>
                        <select
                            id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_stt_provider_sheet"
                            name="stt_provider"
                            class="aipkit_popover_option_select aipkit_popover_option_select--compact aipkit_stt_provider_select"
                        >
                            <?php foreach ($stt_provider_options as $stt_provider_key => $stt_provider_label) : ?>
                                <option value="<?php echo esc_attr($stt_provider_key); ?>" <?php selected($selected_stt_provider_for_ui, $stt_provider_key); ?>>
                                    <?php echo esc_html($stt_provider_label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php foreach ($stt_model_fields as $stt_field_provider => $stt_field) : ?>
                    <div class="aipkit_audio_settings_field aipkit_audio_settings_field--model<?php echo ($stt_field_provider === 'OpenAI' && $hide_stt_provider_field) ? ' aipkit_audio_settings_field--wide' : ''; ?> aipkit_stt_model_field" data-stt-provider="<?php echo esc_attr($stt_field_provider); ?>" style="display: <?php echo $selected_stt_provider_for_ui === $stt_field_provider ? 'flex' : 'none'; ?>;">
                        <label class="aipkit_popover_option_label" for="aipkit_bot_<?php echo esc_attr($bot_id . '_' . $stt_field['name']); ?>_sheet">
                            <?php esc_html_e('Model', 'gpt3-ai-content-generator'); ?>
                        </label>
                        <select id="aipkit_bot_<?php echo esc_attr($bot_id . '_' . $stt_field['name']); ?>_sheet" name="<?php echo esc_attr($stt_field['name']); ?>" class="aipkit_popover_option_select aipkit_popover_option_select--compact" data-aipkit-universal-model-provider="<?php echo esc_attr($stt_field_provider); ?>" data-aipkit-universal-model-capability="stt">
                            <?php
                            $found_stt_model = false;
                            foreach (!empty($stt_field['models']) ? $stt_field['models'] : [] as $model) {
                                $model_id_val = $model['id'] ?? '';
                                $model_name_val = $model['name'] ?? $model_id_val;
                                if ($stt_field_provider === 'Google') {
                                    $model_id_val = (string) $model_id_val;
                                    $model_name_val = (string) $model_name_val;
                                }
                                if ($model_id_val === $stt_field['value']) {
                                    $found_stt_model = true;
                                }
                                echo '<option value="' . esc_attr($model_id_val) . '" ' . selected($stt_field['value'], $model_id_val, false) . '>' . esc_html($model_name_val) . '</option>';
                            }
                            // STT keeps saved models even when they are absent from a nonempty catalog.
                            if (!$found_stt_model && !empty($stt_field['value'])) {
                                echo '<option value="' . esc_attr($stt_field['value']) . '" selected>' . esc_html($stt_field['value']) . '</option>';
                            } elseif (empty($stt_field['models']) && empty($stt_field['value'])) {
                                echo '<option value="' . esc_attr($stt_field['default']) . '" selected>' . esc_html($stt_field['default']) . ' (Default)</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="aipkit_popover_option_group aipkit_audio_feature_group aipkit_audio_feature_group--tts">
            <div class="aipkit_popover_option_row aipkit_audio_toggle_row aipkit_audio_toggle_row--tts">
                <div class="aipkit_popover_option_main">
                    <label
                        class="aipkit_popover_option_label"
                        for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_tts_enabled_sheet"

                    >
                        <?php esc_html_e('Text to speech', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <div class="aipkit_popover_option_actions">
                        <select
                            id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_tts_enabled_sheet"
                            name="tts_enabled"
                            class="aipkit_popover_option_select aipkit_popover_option_select--compact aipkit_tts_toggle_switch"
                        >
                            <option value="1" <?php selected($tts_enabled, '1'); ?>><?php esc_html_e('Yes', 'gpt3-ai-content-generator'); ?></option>
                            <option value="0" <?php selected($tts_enabled, '0'); ?>><?php esc_html_e('No', 'gpt3-ai-content-generator'); ?></option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="aipkit_popover_option_row aipkit_tts_provider_row aipkit_tts_settings_row" style="display: <?php echo $tts_enabled === '1' ? 'block' : 'none'; ?>;">
                <div class="aipkit_popover_option_main">
                    <div class="aipkit_audio_settings_grid aipkit_audio_settings_grid--tts aipkit_tts_conditional_settings" style="display: <?php echo $tts_enabled === '1' ? 'grid' : 'none'; ?>;">
                        <div class="aipkit_audio_settings_field aipkit_audio_settings_field--provider">
                            <label
                                class="aipkit_popover_option_label"
                                for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_tts_provider_sheet"
                            >
                                <?php esc_html_e('Provider', 'gpt3-ai-content-generator'); ?>
                            </label>
                            <select
                                id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_tts_provider_sheet"
                                name="tts_provider"
                                class="aipkit_popover_option_select aipkit_popover_option_select--compact aipkit_tts_provider_select"
                            >
                                <option value="" <?php selected($tts_provider, ''); ?>><?php esc_html_e('Select a provider', 'gpt3-ai-content-generator'); ?></option>
                                <?php foreach ($tts_providers as $provider_name): ?>
                                    <option value="<?php echo esc_attr($provider_name); ?>" <?php selected($tts_provider, $provider_name); ?>><?php echo esc_html($provider_name === 'AIPufferCloud' ? __('AI Puffer', 'gpt3-ai-content-generator') : $provider_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="aipkit_audio_settings_field aipkit_tts_auto_play_container">
                            <label
                                class="aipkit_popover_option_label"
                                for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_tts_auto_play_sheet"
                            >
                                <?php esc_html_e('Auto play', 'gpt3-ai-content-generator'); ?>
                            </label>
                            <select
                                id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_tts_auto_play_sheet"
                                name="tts_auto_play"
                                class="aipkit_popover_option_select aipkit_popover_option_select--compact"
                            >
                                <option value="1" <?php selected($tts_auto_play, '1'); ?>><?php esc_html_e('Yes', 'gpt3-ai-content-generator'); ?></option>
                                <option value="0" <?php selected($tts_auto_play, '0'); ?>><?php esc_html_e('No', 'gpt3-ai-content-generator'); ?></option>
                            </select>
                        </div>
                        <?php foreach ($tts_provider_fields as $tts_field_provider => $tts_fields) : ?>
                            <?php foreach ($tts_field_labels as $tts_field_type => $tts_field_label) :
                                $tts_field_name = 'tts_' . $tts_fields['slug'] . '_' . $tts_field_type . '_id';
                                $tts_choices = $tts_fields[$tts_field_type];
                                $tts_value = $tts_fields[$tts_field_type . '_value'];
                                $tts_is_elevenlabs = $tts_field_provider === 'ElevenLabs';
                                $tts_is_google_voice = $tts_field_provider === 'Google' && $tts_field_type === 'voice';
                                $tts_is_openai_model = $tts_field_provider === 'OpenAI' && $tts_field_type === 'model';
                            ?>
                            <div class="aipkit_audio_settings_field aipkit_tts_field aipkit_tts_<?php echo esc_attr($tts_fields['slug'] . '_' . $tts_field_type); ?>_row" data-provider="<?php echo esc_attr($tts_field_provider); ?>" style="display: <?php echo ($tts_enabled === '1' && $tts_provider === $tts_field_provider) ? 'flex' : 'none'; ?>;">
                                <label class="aipkit_popover_option_label" for="aipkit_bot_<?php echo esc_attr($bot_id . '_' . $tts_field_name); ?>_sheet">
                                    <?php echo esc_html($tts_field_label); ?>
                                </label>
                                <select id="aipkit_bot_<?php echo esc_attr($bot_id . '_' . $tts_field_name); ?>_sheet" name="<?php echo esc_attr($tts_field_name); ?>" class="aipkit_popover_option_select aipkit_popover_option_select--compact" <?php if ($tts_field_type === 'model') : ?>data-aipkit-universal-model-provider="<?php echo esc_attr($tts_field_provider); ?>" data-aipkit-universal-model-capability="tts"<?php endif; ?>>
                                    <?php if ($tts_is_elevenlabs) : ?>
                                        <option value=""><?php echo esc_html($tts_empty_labels[$tts_field_type]); ?></option>
                                    <?php endif; ?>
                                    <?php
                                    if (!empty($tts_choices) && is_array($tts_choices)) {
                                        foreach ($tts_choices as $choice) {
                                            if (($tts_is_elevenlabs || $tts_is_google_voice) && !isset($choice['id'], $choice['name'])) {
                                                continue;
                                            }
                                            $choice_id = $tts_is_openai_model ? ($choice['id'] ?? '') : $choice['id'];
                                            $choice_label = $tts_is_openai_model ? ($choice['name'] ?? $choice_id) : $choice['name'];
                                            if ($tts_is_google_voice && !empty($choice['style'])) {
                                                $choice_label .= ' — ' . $choice['style'];
                                            }
                                            echo '<option value="' . esc_attr($choice_id) . '" ' . selected($tts_value, $choice_id, false) . '>' . esc_html($choice_label) . '</option>';
                                        }
                                    } elseif (($tts_is_elevenlabs || $tts_is_openai_model) && !empty($tts_value)) {
                                        // These TTS fields retain a saved choice only when their catalog is empty.
                                        echo '<option value="' . esc_attr($tts_value) . '" selected>' . esc_html($tts_value) . ' (Saved)</option>';
                                    } elseif ($tts_is_openai_model) {
                                        $default_tts_model = \WPAICG\Chat\Storage\BotSettingsManager::get_default_model_id('OpenAITTS');
                                        echo '<option value="' . esc_attr($default_tts_model) . '" selected>' . esc_html($default_tts_model) . ' (Default)</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div>

    <div class="aipkit_popover_option_group aipkit_audio_feature_group aipkit_audio_feature_group--realtime">
        <div class="aipkit_popover_option_row aipkit_audio_toggle_row aipkit_audio_toggle_row--realtime">
            <div class="aipkit_popover_option_main">
                <label
                    class="aipkit_popover_option_label"
                    for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_enable_realtime_voice_sheet"
                >
                    <?php esc_html_e('Realtime voice', 'gpt3-ai-content-generator'); ?>
                </label>
                <div class="aipkit_popover_option_actions">
                    <select
                        id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_enable_realtime_voice_sheet"
                        name="enable_realtime_voice"
                        class="aipkit_popover_option_select aipkit_popover_option_select--compact aipkit_enable_realtime_voice_toggle"
                        <?php echo $rt_controls_disabled ? 'disabled' : ''; ?>
                    >
                        <option value="1" <?php selected($enable_realtime_voice, '1'); ?>><?php esc_html_e('Yes', 'gpt3-ai-content-generator'); ?></option>
                        <option value="0" <?php selected($enable_realtime_voice, '0'); ?>><?php esc_html_e('No', 'gpt3-ai-content-generator'); ?></option>
                    </select>
                    <?php if ($rt_disabled_by_plan) : ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wpaicg-pricing')); ?>" class="aipkit_popover_upgrade_link aipkit_pro_upgrade_button" title="<?php esc_attr_e('Upgrade', 'gpt3-ai-content-generator'); ?>"><?php esc_html_e('Upgrade', 'gpt3-ai-content-generator'); ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php
        $realtime_settings_view = defined('WPAICG_LIB_DIR') ? WPAICG_LIB_DIR . 'views/chatbot/realtime-settings.php' : '';
        if ($is_pro_plan && $realtime_settings_view !== '' && is_file($realtime_settings_view)) {
            include $realtime_settings_view;
        }
        ?>
        </div>
    </div>
