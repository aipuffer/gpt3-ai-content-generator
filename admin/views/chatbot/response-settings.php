<?php
/**
 * Chatbot answer style, in two groups: how it answers and what it remembers.
 *
 * Each row offers plain presets beside its name (under it when they don't fit). Number settings add
 * Custom, which reveals the saved field in its row. Thinking lists the levels the chosen model accepts,
 * and the provider's session setting is a switch; both rows show only when the model or provider uses them.
 */
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

use WPAICG\Chat\Storage\BotSettingsManager;
use WPAICG\Core\AIPKit_OpenAI_Reasoning;

$bot_id = $initial_active_bot_id;
$bot_settings = $active_bot_settings;
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

$aipkit_answer_settings = $active_bot_settings;
$aipkit_answer_values = [
    'temperature' => max(0.0, min(isset($aipkit_answer_settings['temperature']) ? floatval($aipkit_answer_settings['temperature']) : BotSettingsManager::DEFAULT_TEMPERATURE, 2.0)),
    'max_completion_tokens' => max(1, min(isset($aipkit_answer_settings['max_completion_tokens']) ? absint($aipkit_answer_settings['max_completion_tokens']) : BotSettingsManager::DEFAULT_MAX_COMPLETION_TOKENS, 128000)),
    'max_messages' => max(1, min(isset($aipkit_answer_settings['max_messages']) ? absint($aipkit_answer_settings['max_messages']) : BotSettingsManager::DEFAULT_MAX_MESSAGES, 1024)),
];

$aipkit_answer_style_rows = [
    'temperature' => [
        'title' => __('Creativity', 'gpt3-ai-content-generator'),
        'hint' => __('Precise sticks closest to your knowledge. Best for support.', 'gpt3-ai-content-generator'),
        'field_label' => __('Temperature', 'gpt3-ai-content-generator'),
        'field_hint' => __('Temperature, 0 to 2', 'gpt3-ai-content-generator'),
        'attributes' => ['min' => '0', 'max' => '2', 'step' => '0.1'],
        'options' => [
            '0.3' => __('Precise', 'gpt3-ai-content-generator'),
            '1' => __('Balanced', 'gpt3-ai-content-generator'),
            '1.3' => __('Creative', 'gpt3-ai-content-generator'),
        ],
    ],
    'max_completion_tokens' => [
        'title' => __('Answer length', 'gpt3-ai-content-generator'),
        'hint' => __('Short keeps replies quick to read.', 'gpt3-ai-content-generator'),
        'field_label' => __('Max tokens', 'gpt3-ai-content-generator'),
        'field_hint' => __('Max tokens per answer', 'gpt3-ai-content-generator'),
        'attributes' => ['min' => '1', 'max' => '128000', 'step' => '1'],
        'options' => [
            '500' => __('Short', 'gpt3-ai-content-generator'),
            '1500' => __('Medium', 'gpt3-ai-content-generator'),
            '4000' => __('Long', 'gpt3-ai-content-generator'),
        ],
    ],
    'max_messages' => [
        'title' => __('Recent messages', 'gpt3-ai-content-generator'),
        'hint' => __('How much of this chat it reads back before answering.', 'gpt3-ai-content-generator'),
        'field_label' => __('Messages remembered', 'gpt3-ai-content-generator'),
        'field_hint' => __('Messages remembered', 'gpt3-ai-content-generator'),
        'attributes' => ['min' => '1', 'max' => '1024', 'step' => '1'],
        'options' => [
            /* translators: %d: number of chat messages the chatbot remembers. */
            '5' => sprintf(__('Last %d', 'gpt3-ai-content-generator'), 5),
            /* translators: %d: number of chat messages the chatbot remembers. */
            '15' => sprintf(__('Last %d', 'gpt3-ai-content-generator'), 15),
            /* translators: %d: number of chat messages the chatbot remembers. */
            '30' => sprintf(__('Last %d', 'gpt3-ai-content-generator'), 30),
        ],
    ],
];

$render_answer_style_row = static function (string $field, array $row) use ($bot_id, $aipkit_answer_values): void {
    $field_id = 'aipkit_bot_' . $bot_id . '_' . $field;
    $label_id = 'aipkit_answer_style_' . $field;
    ?>
    <div class="aipkit_answer_style_row aipkit_answer_style_row--inline">
        <div class="aipkit_answer_style_copy">
            <span class="aipkit_answer_style_title" id="<?php echo esc_attr($label_id); ?>"><?php echo esc_html($row['title']); ?></span>
            <span class="aipkit_answer_style_hint"><?php echo esc_html($row['hint']); ?></span>
        </div>
        <div class="aipkit_answer_style_control">
            <div
                class="aipkit_segmented"
                role="group"
                aria-labelledby="<?php echo esc_attr($label_id); ?>"
                data-aipkit-segmented-for="<?php echo esc_attr($field_id); ?>"
            >
                <?php foreach ($row['options'] as $option_value => $option_label) : ?>
                    <button type="button" class="aipkit_segmented_option" data-value="<?php echo esc_attr((string) $option_value); ?>" aria-pressed="false"><?php echo esc_html($option_label); ?></button>
                <?php endforeach; ?>
                <button type="button" class="aipkit_segmented_option" data-custom aria-pressed="false"><?php esc_html_e('Custom', 'gpt3-ai-content-generator'); ?></button>
            </div>
            <?php // The saved field itself; it shows when Custom is chosen or the value matches no preset. ?>
            <label class="aipkit_answer_style_custom" data-aipkit-segmented-custom-field hidden>
                <span class="screen-reader-text"><?php echo esc_html($row['field_label']); ?></span>
                <input
                    type="number"
                    id="<?php echo esc_attr($field_id); ?>"
                    name="<?php echo esc_attr($field); ?>"
                    class="aipkit_form-input"
                    <?php foreach ($row['attributes'] as $attribute => $attribute_value) : ?>
                        <?php echo esc_attr($attribute); ?>="<?php echo esc_attr($attribute_value); ?>"
                    <?php endforeach; ?>
                    value="<?php echo esc_attr((string) $aipkit_answer_values[$field]); ?>"
                />
                <span class="aipkit_answer_style_custom_hint" aria-hidden="true"><?php echo esc_html($row['field_hint']); ?></span>
            </label>
        </div>
    </div>
    <?php
};

// Thinking presets are drawn from the select's options, which the model scripts set per model.
// Their texts are short codes (low, med, xhigh; off and on for on/off models), named here.
$aipkit_thinking_labels = [];
foreach ([
    __('Off', 'gpt3-ai-content-generator') => ['none', __('none', 'gpt3-ai-content-generator'), 'off', __('off', 'gpt3-ai-content-generator')],
    __('Minimal', 'gpt3-ai-content-generator') => ['minimal'],
    __('Low', 'gpt3-ai-content-generator') => ['low', __('low', 'gpt3-ai-content-generator')],
    __('Medium', 'gpt3-ai-content-generator') => ['medium', 'med', __('med', 'gpt3-ai-content-generator')],
    __('High', 'gpt3-ai-content-generator') => ['high', __('high', 'gpt3-ai-content-generator')],
    __('Extra high', 'gpt3-ai-content-generator') => ['xhigh', __('xhigh', 'gpt3-ai-content-generator')],
    __('Max', 'gpt3-ai-content-generator') => ['max', __('max', 'gpt3-ai-content-generator')],
    __('On', 'gpt3-ai-content-generator') => ['on', __('on', 'gpt3-ai-content-generator')],
] as $aipkit_thinking_label => $aipkit_thinking_codes) {
    foreach ($aipkit_thinking_codes as $aipkit_thinking_code) {
        $aipkit_thinking_labels[strtolower((string) $aipkit_thinking_code)] = $aipkit_thinking_label;
    }
}

// Each provider names its session setting differently; only the current provider's row shows.
$aipkit_session_rows = [
    'OpenAI' => [
        'name' => 'openai_conversation_state_enabled',
        'id' => 'aipkit_bot_' . $bot_id . '_openai_conversation_state_enabled_select',
        'class' => 'aipkit_openai_conversation_state_enable_toggle',
        'value' => $openai_conversation_state_enabled_val,
        'title' => __('Session memory', 'gpt3-ai-content-generator'),
        'hint' => __('OpenAI keeps the conversation between replies, so long chats send less each time.', 'gpt3-ai-content-generator'),
    ],
    'Google' => [
        'name' => 'google_conversation_state_enabled',
        'id' => 'aipkit_bot_' . $bot_id . '_google_conversation_state_enabled_select',
        'class' => 'aipkit_google_conversation_state_enable_toggle',
        'value' => $google_conversation_state_enabled_val,
        'title' => __('Session memory', 'gpt3-ai-content-generator'),
        'hint' => __('Google keeps the conversation between replies, so long chats send less each time.', 'gpt3-ai-content-generator'),
    ],
    'OpenRouter' => [
        'name' => 'openrouter_session_stickiness',
        'id' => 'aipkit_bot_' . $bot_id . '_openrouter_session_stickiness_select',
        'class' => 'aipkit_openrouter_session_stickiness_toggle',
        'value' => $openrouter_session_stickiness_val,
        'title' => __('Same provider each time', 'gpt3-ai-content-generator'),
        'hint' => __('OpenRouter keeps this chatbot on one provider for faster replies.', 'gpt3-ai-content-generator'),
    ],
];
?>
<div class="aipkit_answer_style">
    <section class="aipkit_answer_style_group" aria-labelledby="aipkit_answer_style_group_answers">
        <h4 class="aipkit_answer_style_group_title" id="aipkit_answer_style_group_answers"><?php esc_html_e('How it answers', 'gpt3-ai-content-generator'); ?></h4>
        <?php
        $render_answer_style_row('temperature', $aipkit_answer_style_rows['temperature']);
        $render_answer_style_row('max_completion_tokens', $aipkit_answer_style_rows['max_completion_tokens']);
        ?>
        <?php // Hidden by the model scripts when the chosen model does not think. ?>
        <div class="aipkit_answer_style_row aipkit_answer_style_row--inline aipkit_reasoning_effort_field">
            <div class="aipkit_answer_style_copy">
                <span class="aipkit_answer_style_title" id="aipkit_answer_style_reasoning_effort">
                    <span class="aipkit_reasoning_effort_label_text"><?php esc_html_e('Thinking', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_answer_style_tag"><?php esc_html_e('Uses more credits', 'gpt3-ai-content-generator'); ?></span>
                </span>
                <span class="aipkit_answer_style_hint"><?php esc_html_e('How long it thinks before answering. Deeper is slower.', 'gpt3-ai-content-generator'); ?></span>
            </div>
            <div class="aipkit_answer_style_control">
                <div
                    class="aipkit_segmented"
                    role="group"
                    aria-labelledby="aipkit_answer_style_reasoning_effort"
                    data-aipkit-segmented-for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_reasoning_effort"
                    data-aipkit-segmented-options
                    data-aipkit-segmented-labels="<?php echo esc_attr(wp_json_encode($aipkit_thinking_labels)); ?>"
                    data-aipkit-segmented-fixed="<?php /* translators: %s: the only thinking level a model accepts, such as High. */ esc_attr_e('%s · set by this model', 'gpt3-ai-content-generator'); ?>"
                ></div>
                <select
                    id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_reasoning_effort"
                    name="reasoning_effort"
                    class="aipkit_reasoning_effort_value"
                    aria-hidden="true"
                    tabindex="-1"
                    hidden
                >
                    <?php foreach ($reasoning_options as $option_index => $option_value) : ?>
                        <option value="<?php echo esc_attr($option_value); ?>" <?php selected($reasoning_effort, $option_value); ?>><?php echo esc_html($reasoning_labels[$option_index]); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </section>
    <section class="aipkit_answer_style_group" aria-labelledby="aipkit_answer_style_group_memory">
        <h4 class="aipkit_answer_style_group_title" id="aipkit_answer_style_group_memory"><?php esc_html_e('What it remembers', 'gpt3-ai-content-generator'); ?></h4>
        <?php $render_answer_style_row('max_messages', $aipkit_answer_style_rows['max_messages']); ?>
        <?php foreach ($aipkit_session_rows as $aipkit_session_provider => $aipkit_session_row) : ?>
            <div
                class="aipkit_answer_style_row aipkit_answer_style_row--switch aipkit_stateful_convo_group"
                data-provider="<?php echo esc_attr($aipkit_session_provider); ?>"
                style="<?php echo ($current_provider_for_this_bot === $aipkit_session_provider) ? '' : 'display:none;'; ?>"
            >
                <label class="aipkit_answer_style_switch_label" for="<?php echo esc_attr($aipkit_session_row['id']); ?>">
                    <span class="aipkit_answer_style_copy">
                        <span class="aipkit_answer_style_title"><?php echo esc_html($aipkit_session_row['title']); ?></span>
                        <span class="aipkit_answer_style_hint"><?php echo esc_html($aipkit_session_row['hint']); ?></span>
                    </span>
                    <span class="aipkit_switch">
                        <input
                            type="checkbox"
                            id="<?php echo esc_attr($aipkit_session_row['id']); ?>"
                            name="<?php echo esc_attr($aipkit_session_row['name']); ?>"
                            class="<?php echo esc_attr($aipkit_session_row['class']); ?> aipkit_stateful_convo_checkbox"
                            value="1"
                            <?php checked($aipkit_session_row['value'], '1'); ?>
                        />
                        <span class="aipkit_switch_slider" aria-hidden="true"></span>
                    </span>
                </label>
            </div>
        <?php endforeach; ?>
    </section>
</div>
