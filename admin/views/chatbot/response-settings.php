<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

use WPAICG\Chat\Storage\BotSettingsManager;
use WPAICG\Core\AIPKit_OpenAI_Reasoning;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bot_id = $initial_active_bot_id;
$bot_settings = $active_bot_settings;
$saved_temperature = isset($bot_settings['temperature'])
    ? floatval($bot_settings['temperature'])
    : BotSettingsManager::DEFAULT_TEMPERATURE;
$saved_max_tokens = isset($bot_settings['max_completion_tokens'])
    ? absint($bot_settings['max_completion_tokens'])
    : BotSettingsManager::DEFAULT_MAX_COMPLETION_TOKENS;
$saved_max_messages = isset($bot_settings['max_messages'])
    ? absint($bot_settings['max_messages'])
    : BotSettingsManager::DEFAULT_MAX_MESSAGES;
$reasoning_effort = isset($bot_settings['reasoning_effort'])
    ? sanitize_text_field($bot_settings['reasoning_effort'])
    : BotSettingsManager::DEFAULT_REASONING_EFFORT;
$reasoning_effort = AIPKit_OpenAI_Reasoning::sanitize_effort($reasoning_effort);
$reasoning_options = ['none', 'low', 'medium', 'high', 'xhigh', 'max'];
$reasoning_labels = [
    __('none', 'gpt3-ai-content-generator'),
    __('low', 'gpt3-ai-content-generator'),
    __('med', 'gpt3-ai-content-generator'),
    __('high', 'gpt3-ai-content-generator'),
    __('xhigh', 'gpt3-ai-content-generator'),
    __('max', 'gpt3-ai-content-generator'),
];
if (!in_array($reasoning_effort, $reasoning_options, true)) {
    $reasoning_effort = BotSettingsManager::DEFAULT_REASONING_EFFORT;
}
$reasoning_label_text = $current_provider_for_this_bot === 'Ollama'
    ? __('Thinking', 'gpt3-ai-content-generator')
    : __('Reasoning', 'gpt3-ai-content-generator');

$saved_temperature = max(0.0, min($saved_temperature, 2.0));
$saved_max_tokens = max(1, min($saved_max_tokens, 128000));
$saved_max_messages = max(1, min($saved_max_messages, 1024));
?>
<div class="aipkit_popover_options_list aipkit_behavior_compact_options aipkit_model_compact_options">
    <div class="aipkit_behavior_compact_row">
        <div class="aipkit_behavior_compact_cell">
            <label class="aipkit_popover_option_label" for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_temperature">
                <?php esc_html_e('Temperature', 'gpt3-ai-content-generator'); ?>
            </label>
            <input
                type="number"
                id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_temperature"
                name="temperature"
                class="aipkit_form-input"
                min="0"
                max="2"
                step="0.1"
                value="<?php echo esc_attr($saved_temperature); ?>"
            />
        </div>
        <div class="aipkit_behavior_compact_cell">
            <label class="aipkit_popover_option_label" for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_max_completion_tokens">
                <?php esc_html_e('Context', 'gpt3-ai-content-generator'); ?>
            </label>
            <input
                type="number"
                id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_max_completion_tokens"
                name="max_completion_tokens"
                class="aipkit_form-input"
                min="1"
                max="128000"
                step="1"
                value="<?php echo esc_attr($saved_max_tokens); ?>"
            />
        </div>
        <div class="aipkit_behavior_compact_cell">
            <label class="aipkit_popover_option_label" for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_max_messages">
                <?php esc_html_e('Messages', 'gpt3-ai-content-generator'); ?>
            </label>
            <input
                type="number"
                id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_max_messages"
                name="max_messages"
                class="aipkit_form-input"
                min="1"
                max="1024"
                step="1"
                value="<?php echo esc_attr($saved_max_messages); ?>"
            />
        </div>
        <div
            class="aipkit_behavior_compact_cell aipkit_stateful_convo_group"
            data-provider="OpenAI"
            style="<?php echo ($current_provider_for_this_bot === 'OpenAI') ? '' : 'display:none;'; ?>"
        >
            <label
                class="aipkit_popover_option_label"
                for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_openai_conversation_state_enabled_select"

            >
                <?php esc_html_e('Session memory', 'gpt3-ai-content-generator'); ?>
            </label>
            <select
                id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_openai_conversation_state_enabled_select"
                name="openai_conversation_state_enabled"
                class="aipkit_form-input aipkit_popover_option_select aipkit_openai_conversation_state_enable_toggle aipkit_stateful_convo_checkbox"
            >
                <option value="1" <?php selected($openai_conversation_state_enabled_val, '1'); ?>>
                    <?php esc_html_e('Yes', 'gpt3-ai-content-generator'); ?>
                </option>
                <option value="0" <?php selected($openai_conversation_state_enabled_val, '0'); ?>>
                    <?php esc_html_e('No', 'gpt3-ai-content-generator'); ?>
                </option>
            </select>
        </div>
        <div
            class="aipkit_behavior_compact_cell aipkit_stateful_convo_group"
            data-provider="Google"
            style="<?php echo ($current_provider_for_this_bot === 'Google') ? '' : 'display:none;'; ?>"
        >
            <label
                class="aipkit_popover_option_label"
                for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_google_conversation_state_enabled_select"
            >
                <?php esc_html_e('Session memory', 'gpt3-ai-content-generator'); ?>
            </label>
            <select
                id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_google_conversation_state_enabled_select"
                name="google_conversation_state_enabled"
                class="aipkit_form-input aipkit_popover_option_select aipkit_google_conversation_state_enable_toggle aipkit_stateful_convo_checkbox"
            >
                <option value="1" <?php selected($google_conversation_state_enabled_val, '1'); ?>>
                    <?php esc_html_e('Yes', 'gpt3-ai-content-generator'); ?>
                </option>
                <option value="0" <?php selected($google_conversation_state_enabled_val, '0'); ?>>
                    <?php esc_html_e('No', 'gpt3-ai-content-generator'); ?>
                </option>
            </select>
        </div>
        <div
            class="aipkit_behavior_compact_cell aipkit_stateful_convo_group"
            data-provider="OpenRouter"
            style="<?php echo ($current_provider_for_this_bot === 'OpenRouter') ? '' : 'display:none;'; ?>"
        >
            <label
                class="aipkit_popover_option_label"
                for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_openrouter_session_stickiness_select"
                data-tooltip="<?php esc_attr_e('Keeps this chatbot on the same OpenRouter provider to improve prompt cache affinity. This does not store conversation state.', 'gpt3-ai-content-generator'); ?>"
            >
                <?php esc_html_e('Session stickiness', 'gpt3-ai-content-generator'); ?>
            </label>
            <select
                id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_openrouter_session_stickiness_select"
                name="openrouter_session_stickiness"
                class="aipkit_form-input aipkit_popover_option_select aipkit_openrouter_session_stickiness_toggle aipkit_stateful_convo_checkbox"
            >
                <option value="1" <?php selected($openrouter_session_stickiness_val, '1'); ?>>
                    <?php esc_html_e('Yes', 'gpt3-ai-content-generator'); ?>
                </option>
                <option value="0" <?php selected($openrouter_session_stickiness_val, '0'); ?>>
                    <?php esc_html_e('No', 'gpt3-ai-content-generator'); ?>
                </option>
            </select>
        </div>
        <div class="aipkit_behavior_compact_cell aipkit_reasoning_effort_field">
            <label
                class="aipkit_popover_option_label"
                for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_reasoning_effort"

            >
                <span class="aipkit_reasoning_effort_label_text"><?php echo esc_html($reasoning_label_text); ?></span>
            </label>
            <select
                id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_reasoning_effort"
                name="reasoning_effort"
                class="aipkit_form-input aipkit_popover_option_select aipkit_reasoning_effort_value"
            >
                <?php foreach ($reasoning_options as $option_index => $option_value) : ?>
                    <option value="<?php echo esc_attr($option_value); ?>" <?php selected($reasoning_effort, $option_value); ?>>
                        <?php echo esc_html($reasoning_labels[$option_index]); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</div>
