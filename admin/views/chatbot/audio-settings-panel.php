<?php
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

/*
 * Voice side panel: one card per voice option. Each card's head holds its switch and a line about it; its
 * settings open under it, one card at a time (admin/js/chatbot/audio.js). Live voice conversation is Pro:
 * Free plans see Upgrade, and its settings come from the paid add-on.
 */
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
$tts_empty_labels = ['voice' => __('-- Select Voice --', 'gpt3-ai-content-generator'), 'model' => __('-- Select Model (Optional) --', 'gpt3-ai-content-generator')];

// Live voice conversation's settings come from the paid add-on.
$realtime_settings_view = defined('WPAICG_LIB_DIR') ? WPAICG_LIB_DIR . 'views/chatbot/realtime-settings.php' : '';
$has_realtime_settings = $is_pro_plan && $realtime_settings_view !== '' && is_file($realtime_settings_view);

// The three options: what saves their switch, and how their card reads.
$voice_cards = [
    'stt' => [
        'tool' => 'speech_to_text', 'icon' => 'microphone', 'name' => 'enable_voice_input',
        'row_class' => 'aipkit_audio_toggle_voice_input_row', 'field_class' => 'aipkit_voice_input_toggle_switch',
        'value' => $enable_voice_input === '1' ? '1' : '0', 'locked' => false, 'disabled' => false, 'has_body' => true,
    ],
    'tts' => [
        'tool' => 'text_to_speech', 'icon' => 'controls-volumeon', 'name' => 'tts_enabled',
        'row_class' => 'aipkit_audio_toggle_tts_row', 'field_class' => 'aipkit_tts_toggle_switch',
        'value' => $tts_enabled === '1' ? '1' : '0', 'locked' => false, 'disabled' => false, 'has_body' => true,
    ],
    'realtime' => [
        'tool' => 'realtime_voice', 'icon' => 'phone', 'name' => 'enable_realtime_voice',
        'row_class' => 'aipkit_audio_toggle_realtime_row', 'field_class' => 'aipkit_enable_realtime_voice_toggle',
        'value' => $realtime_voice_toggle_value, 'locked' => $realtime_voice_locked_by_plan, 'disabled' => $rt_disabled_by_plan,
        'has_body' => $has_realtime_settings,
    ],
];
$render_voice_head = static function (string $card_key, array $card) use ($bot_id, $tools_master_options, $render_tool_upgrade_button): void {
    $option = $tools_master_options[$card['tool']];
    $base_id = 'aipkit_bot_' . $bot_id . '_voice_' . $card_key;
    $is_on = $card['value'] === '1';
    ?>
    <div class="aipkit_voice_option_head <?php echo esc_attr($card['row_class']); ?>" data-aipkit-tool-key="<?php echo esc_attr($card['tool']); ?>">
        <button
            type="button"
            class="aipkit_voice_option_open"
            id="<?php echo esc_attr($base_id); ?>_open"
            <?php if ($card['has_body']) : ?>
                aria-expanded="false"
                aria-controls="<?php echo esc_attr($base_id); ?>_body"
            <?php endif; ?>
            data-aipkit-voice-open
            <?php disabled(!$is_on || $card['locked'] || !$card['has_body']); ?>
        >
            <span class="aipkit_feature_icon dashicons dashicons-<?php echo esc_attr($card['icon']); ?>" aria-hidden="true"></span>
            <span class="aipkit_voice_option_copy">
                <span class="aipkit_voice_option_title">
                    <?php echo esc_html($option['label']); ?>
                    <?php if ($card['locked']) : ?>
                        <span class="aipkit_paid_feature_badge aipkit_pro_badge"><?php esc_html_e('Pro', 'gpt3-ai-content-generator'); ?></span>
                    <?php endif; ?>
                </span>
                <span class="aipkit_voice_option_summary" data-aipkit-voice-summary data-default-summary="<?php echo esc_attr($option['hint']); ?>"><?php echo esc_html($option['hint']); ?></span>
            </span>
        </button>
        <?php if ($card['locked']) : ?>
            <?php $render_tool_upgrade_button(); ?>
        <?php else : ?>
            <?php // A label, so a click on the slider reaches the hidden checkbox. ?>
            <label class="aipkit_switch aipkit_voice_option_switch">
                <input
                    type="checkbox"
                    id="<?php echo esc_attr($base_id); ?>_switch"
                    class="aipkit_tools_enabled_option"
                    value="<?php echo esc_attr($card['tool']); ?>"
                    data-tool-key="<?php echo esc_attr($card['tool']); ?>"
                    data-static-disabled="<?php echo $card['disabled'] ? '1' : '0'; ?>"
                    aria-label="<?php echo esc_attr($option['label']); ?>"
                    <?php checked($is_on); ?>
                    <?php disabled($card['disabled']); ?>
                />
                <span class="aipkit_switch_slider" aria-hidden="true"></span>
            </label>
            <?php if ($card['has_body']) : ?>
                <span class="aipkit_voice_option_chevron dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
            <?php endif; ?>
        <?php endif; ?>
        <select
            name="<?php echo esc_attr($card['name']); ?>"
            class="aipkit_tools_toggle_select aipkit_tools_state_field <?php echo esc_attr($card['field_class']); ?>"
            aria-hidden="true"
            tabindex="-1"
            hidden
            <?php disabled($card['disabled']); ?>
        >
            <option value="1" <?php selected($card['value'], '1'); ?>><?php esc_html_e('Yes', 'gpt3-ai-content-generator'); ?></option>
            <option value="0" <?php selected($card['value'], '0'); ?>><?php esc_html_e('No', 'gpt3-ai-content-generator'); ?></option>
        </select>
    </div>
    <?php
};
// A row's words: its name (a label when it names one field) and a hint.
$render_voice_copy = static function (string $title, string $hint = '', string $for = ''): void {
    ?>
    <span class="aipkit_answer_style_copy">
        <?php if ($for !== '') : ?>
            <label class="aipkit_answer_style_title" for="<?php echo esc_attr($for); ?>"><?php echo esc_html($title); ?></label>
        <?php else : ?>
            <span class="aipkit_answer_style_title"><?php echo esc_html($title); ?></span>
        <?php endif; ?>
        <?php if ($hint !== '') : ?>
            <span class="aipkit_answer_style_hint"><?php echo esc_html($hint); ?></span>
        <?php endif; ?>
    </span>
    <?php
};
?>
<div class="aipkit_voice_cards" data-cloud-audio-models="<?php echo esc_attr(wp_json_encode(array_merge($cloud_stt_models, $cloud_tts_models))); ?>">
    <section class="aipkit_voice_option" data-aipkit-voice-option="stt">
        <?php $render_voice_head('stt', $voice_cards['stt']); ?>
        <div class="aipkit_voice_option_body" id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_voice_stt_body" role="region" aria-labelledby="aipkit_bot_<?php echo esc_attr($bot_id); ?>_voice_stt_open" hidden>
            <div
                class="aipkit_stt_provider_row"
                data-stt-controls-hidden="<?php echo $hide_stt_controls ? '1' : '0'; ?>"
                data-stt-default-provider="OpenAI"
                data-stt-default-model="<?php echo esc_attr($default_stt_model); ?>"
                style="display: <?php echo ($enable_voice_input === '1' && !$hide_stt_controls) ? 'block' : 'none'; ?>;"
            >
                <div class="aipkit_voice_rows aipkit_stt_provider_conditional_row" style="display: <?php echo ($enable_voice_input === '1' && !$hide_stt_controls) ? 'grid' : 'none'; ?>;">
                    <?php // The model picker settles in this row and chooses the provider too; it names the row Model. ?>
                    <div class="aipkit_answer_style_row aipkit_answer_style_row--inline aipkit_audio_settings_field aipkit_audio_settings_field--provider<?php echo $hide_stt_provider_field ? ' aipkit_audio_settings_field--hidden' : ''; ?>">
                        <?php $render_voice_copy(__('Model', 'gpt3-ai-content-generator'), __('Turns what visitors say into text.', 'gpt3-ai-content-generator'), 'aipkit_bot_' . $bot_id . '_stt_provider_sheet'); ?>
                        <select
                            id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_stt_provider_sheet"
                            name="stt_provider"
                            class="aipkit_look_select aipkit_stt_provider_select"
                        >
                            <?php foreach ($stt_provider_options as $stt_provider_key => $stt_provider_label) : ?>
                                <option value="<?php echo esc_attr($stt_provider_key); ?>" <?php selected($selected_stt_provider_for_ui, $stt_provider_key); ?>>
                                    <?php echo esc_html($stt_provider_label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php foreach ($stt_model_fields as $stt_field_provider => $stt_field) : ?>
                    <div class="aipkit_answer_style_row aipkit_answer_style_row--inline aipkit_audio_settings_field aipkit_stt_model_field" data-stt-provider="<?php echo esc_attr($stt_field_provider); ?>" style="display: <?php echo $selected_stt_provider_for_ui === $stt_field_provider ? 'flex' : 'none'; ?>;">
                        <?php $render_voice_copy(__('Model', 'gpt3-ai-content-generator'), '', 'aipkit_bot_' . $bot_id . '_' . $stt_field['name'] . '_sheet'); ?>
                        <select id="aipkit_bot_<?php echo esc_attr($bot_id . '_' . $stt_field['name']); ?>_sheet" name="<?php echo esc_attr($stt_field['name']); ?>" class="aipkit_look_select" data-aipkit-universal-model-provider="<?php echo esc_attr($stt_field_provider); ?>" data-aipkit-universal-model-capability="stt">
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
    </section>

    <section class="aipkit_voice_option" data-aipkit-voice-option="tts">
        <?php $render_voice_head('tts', $voice_cards['tts']); ?>
        <div class="aipkit_voice_option_body" id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_voice_tts_body" role="region" aria-labelledby="aipkit_bot_<?php echo esc_attr($bot_id); ?>_voice_tts_open" hidden>
            <span class="aipkit_popover_status_inline aipkit_tts_sync_status" aria-live="polite"></span>
            <div class="aipkit_tts_provider_row aipkit_tts_settings_row" style="display: <?php echo $tts_enabled === '1' ? 'block' : 'none'; ?>;">
                <div class="aipkit_voice_rows aipkit_tts_conditional_settings" style="display: <?php echo $tts_enabled === '1' ? 'grid' : 'none'; ?>;">
                    <?php
                    // Each provider's voice (its model row hands over to the picker in the Model row below).
                    foreach ($tts_provider_fields as $tts_field_provider => $tts_fields) :
                        foreach (['voice', 'model'] as $tts_field_type) :
                            $tts_field_name = 'tts_' . $tts_fields['slug'] . '_' . $tts_field_type . '_id';
                            $tts_field_id = 'aipkit_bot_' . $bot_id . '_' . $tts_field_name . '_sheet';
                            $tts_choices = $tts_fields[$tts_field_type];
                            $tts_value = $tts_fields[$tts_field_type . '_value'];
                            $tts_is_elevenlabs = $tts_field_provider === 'ElevenLabs';
                            $tts_is_google_voice = $tts_field_provider === 'Google' && $tts_field_type === 'voice';
                            $tts_is_openai_model = $tts_field_provider === 'OpenAI' && $tts_field_type === 'model';
                            ?>
                            <div class="aipkit_answer_style_row aipkit_answer_style_row--inline aipkit_tts_field aipkit_tts_<?php echo esc_attr($tts_fields['slug'] . '_' . $tts_field_type); ?>_row" data-provider="<?php echo esc_attr($tts_field_provider); ?>" style="display: <?php echo ($tts_enabled === '1' && $tts_provider === $tts_field_provider) ? 'flex' : 'none'; ?>;">
                                <?php
                                $tts_field_type === 'voice'
                                    ? $render_voice_copy(__('Voice', 'gpt3-ai-content-generator'), __('How replies sound.', 'gpt3-ai-content-generator'), $tts_field_id)
                                    : $render_voice_copy(__('Model', 'gpt3-ai-content-generator'), '', $tts_field_id);
                                ?>
                                <select id="<?php echo esc_attr($tts_field_id); ?>" name="<?php echo esc_attr($tts_field_name); ?>" class="aipkit_look_select" <?php if ($tts_field_type === 'model') : ?>data-aipkit-universal-model-provider="<?php echo esc_attr($tts_field_provider); ?>" data-aipkit-universal-model-capability="tts"<?php endif; ?>>
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
                    <?php // The model picker settles in this row and chooses the provider too; it names the row Model. ?>
                    <div class="aipkit_answer_style_row aipkit_answer_style_row--inline aipkit_audio_settings_field aipkit_audio_settings_field--provider">
                        <?php $render_voice_copy(__('Model', 'gpt3-ai-content-generator'), '', 'aipkit_bot_' . $bot_id . '_tts_provider_sheet'); ?>
                        <select
                            id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_tts_provider_sheet"
                            name="tts_provider"
                            class="aipkit_look_select aipkit_tts_provider_select"
                        >
                            <option value="" <?php selected($tts_provider, ''); ?>><?php esc_html_e('Select a provider', 'gpt3-ai-content-generator'); ?></option>
                            <?php foreach ($tts_providers as $provider_name): ?>
                                <option value="<?php echo esc_attr($provider_name); ?>" <?php selected($tts_provider, $provider_name); ?>><?php echo esc_html($provider_name === 'AIPufferCloud' ? __('AI Puffer', 'gpt3-ai-content-generator') : $provider_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="aipkit_answer_style_row aipkit_answer_style_row--switch aipkit_tts_auto_play_container">
                        <label class="aipkit_answer_style_switch_label" for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_tts_auto_play_sheet">
                            <?php $render_voice_copy(__('Play automatically', 'gpt3-ai-content-generator'), __('Off: visitors tap to listen.', 'gpt3-ai-content-generator')); ?>
                            <span class="aipkit_switch">
                                <input
                                    type="checkbox"
                                    id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_tts_auto_play_sheet"
                                    name="tts_auto_play"
                                    value="1"
                                    <?php checked($tts_auto_play, '1'); ?>
                                />
                                <span class="aipkit_switch_slider" aria-hidden="true"></span>
                            </span>
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="aipkit_voice_option" data-aipkit-voice-option="realtime">
        <?php $render_voice_head('realtime', $voice_cards['realtime']); ?>
        <?php if ($has_realtime_settings) : ?>
            <div class="aipkit_voice_option_body" id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_voice_realtime_body" role="region" aria-labelledby="aipkit_bot_<?php echo esc_attr($bot_id); ?>_voice_realtime_open" hidden>
                <?php include $realtime_settings_view; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
