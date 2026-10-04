<?php

/**
 * Chatbot provider and model selection.
 */
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

if (!isset($providers) || !is_array($providers) || empty($providers)) {
    $providers = isset($allowed_main_providers) && is_array($allowed_main_providers)
        ? $allowed_main_providers
        : ['OpenAI', 'Google', 'Claude', 'OpenRouter', 'Azure', 'DeepSeek', 'xAI'];
}

$show_chatbot_selector = empty($is_next_layout) || !$is_next_layout;
$provider_select_options = class_exists('\\WPAICG\\AIPKit_Provider_Model_List_Builder')
    ? \WPAICG\AIPKit_Provider_Model_List_Builder::get_provider_options($providers, (bool) ($is_pro ?? false))
    : [];
$model_sync_timestamps = \WPAICG\AIPKit_Providers::get_model_sync_timestamps();
if (!is_array($model_sync_timestamps)) {
    $model_sync_timestamps = [];
}

$render_model_options = static function (string $provider, string $current_model): void {
    $payload = \WPAICG\AIPKit_Provider_Model_List_Builder::get_model_options($provider, $current_model);
    foreach ((array) ($payload['groups'] ?? []) as $group) {
        if (!is_array($group) || empty($group['options'])) {
            continue;
        }
        $group_label = (string) ($group['label'] ?? '');
        echo '<optgroup label="' . esc_attr($group_label) . '" data-family-key="' . esc_attr((string) ($group['key'] ?? 'other')) . '">';
        foreach ((array) $group['options'] as $option) {
            if (!is_array($option) || empty($option['value'])) {
                continue;
            }
            $value = (string) $option['value'];
            echo '<option value="' . esc_attr($value) . '"'
                . ' data-recommended="' . (!empty($option['recommended']) ? 'true' : 'false') . '"'
                . ' data-family-key="' . esc_attr((string) ($option['family_key'] ?? $group['key'] ?? 'other')) . '"'
                . ' data-family-label="' . esc_attr((string) ($option['family_label'] ?? $group_label)) . '"'
                . ' data-family-order="' . esc_attr((string) ($option['family_order'] ?? $group['order'] ?? 999)) . '"'
                . ' data-family-collapsed="' . (!empty($option['family_collapsed']) ? 'true' : 'false') . '" '
                . selected(!empty($option['selected']), true, false) . '>'
                . esc_html((string) ($option['label'] ?? $value)) . '</option>';
        }
        echo '</optgroup>';
    }

    $manual_option = is_array($payload['manual_option'] ?? null) ? $payload['manual_option'] : null;
    if ($manual_option && !empty($manual_option['value'])) {
        $manual_value = (string) $manual_option['value'];
        echo '<option value="' . esc_attr($manual_value) . '" data-family-key="other" data-family-label="'
            . esc_attr((string) ($manual_option['family_label'] ?? __('Other', 'gpt3-ai-content-generator')))
            . '" data-family-order="999" data-family-collapsed="true" selected>'
            . esc_html((string) ($manual_option['label'] ?? $manual_value)) . '</option>';
    }
    if (empty($payload['has_selectable_options']) && !$manual_option) {
        echo '<option value="">' . esc_html((string) ($payload['empty_option_label'] ?? __('(Sync models in main AI Settings)', 'gpt3-ai-content-generator'))) . '</option>';
    }
};

$render_simple_model_field = static function (array $config) use ($bot_id, $saved_model, $saved_provider, $render_model_options): void {
    $provider = (string) ($config['provider'] ?? '');
    $slug = (string) ($config['slug'] ?? '');
    if ($provider === '' || $slug === '') {
        return;
    }
    $field_name = (string) ($config['name'] ?? $slug . '_model');
    $field_id = 'aipkit_bot_' . $bot_id . '_' . $field_name;
    $field_label = (string) ($config['label'] ?? __('Model', 'gpt3-ai-content-generator'));
    ?>
        <div
            class="aipkit_chatbot_model_field"
            data-provider="<?php echo esc_attr($provider); ?>"
            style="display: <?php echo $saved_provider === $provider ? 'block' : 'none'; ?>;"
        >
             <div class="aipkit_input-with-button aipkit_input-with-button--labels">
                <label
                    class="aipkit_form-label aipkit_form-label--status"
                    for="<?php echo esc_attr($field_id); ?>"
                >
                    <span class="aipkit_model_label_text"><?php echo esc_html($field_label); ?></span>
                    <span class="aipkit_model_status_slot">
                        <span class="aipkit_model_sync_status" aria-live="polite"></span>
                    </span>
                </label>
                <select
                    id="<?php echo esc_attr($field_id); ?>"
                    name="<?php echo esc_attr($field_name); ?>"
                    class="aipkit_form-input"
                    data-aipkit-model-state-select="1"
                >
                    <?php $render_model_options($provider, $saved_provider === $provider ? $saved_model : ''); ?>
                </select>
            </div>
        </div>
    <?php
};

?>
<div class="aipkit_form-row aipkit_form-row-align-bottom aipkit_builder_inline_row aipkit_chatbot_model_row">
    <?php if ($show_chatbot_selector) : ?>
        <div class="aipkit_form-group aipkit_form-col aipkit_chatbot_model_col aipkit_chatbot_model_col--bot">
            <label
                class="aipkit_form-label"
                for="aipkit_chatbot_builder_bot_select"
            >
                <span><?php esc_html_e('Chatbot', 'gpt3-ai-content-generator'); ?></span>
            </label>
            <div class="aipkit_input-with-button">
                <select
                    id="aipkit_chatbot_builder_bot_select"
                    name="aipkit_chatbot_builder_bot_select"
                    class="aipkit_form-input aipkit_builder_bot_select_input"
                    <?php echo empty($all_bots_ordered_entries) ? 'disabled' : ''; ?>
                >
                    <?php if (empty($all_bots_ordered_entries)) : ?>
                        <option value="">
                            <?php esc_html_e('No chatbots yet', 'gpt3-ai-content-generator'); ?>
                        </option>
                    <?php else : ?>
                        <?php foreach ($all_bots_ordered_entries as $bot_entry) : ?>
                            <?php $bot_post = $bot_entry['post']; ?>
                            <option
                                value="<?php echo esc_attr($bot_post->ID); ?>"
                                <?php selected($bot_id, $bot_post->ID); ?>
                            >
                                <?php echo esc_html($bot_post->post_title); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
        </div>
    <?php endif; ?>

    <div class="aipkit_form-group aipkit_form-col aipkit_chatbot_model_col aipkit_chatbot_model_col--unified">
        <div class="aipkit_chatbot_model_label_row">
            <label
                class="aipkit_form-label"
                for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_unified_model_trigger"
            >
                <?php esc_html_e('Model', 'gpt3-ai-content-generator'); ?>
            </label>
            <?php if (!empty($is_next_layout)) : ?>
            <button
                type="button"
                class="aipkit_chatbot_model_sync_btn"
                data-aipkit-chatbot-sync-models
                data-aipkit-syncing-label="<?php esc_attr_e('Syncing…', 'gpt3-ai-content-generator'); ?>"
                data-aipkit-model-sync-times="<?php echo esc_attr(wp_json_encode($model_sync_timestamps)); ?>"
                aria-label="<?php esc_attr_e('Sync models for the selected provider', 'gpt3-ai-content-generator'); ?>"
                aria-busy="false"
            >
                <span class="dashicons dashicons-update" aria-hidden="true"></span>
                <span class="aipkit_btn-text"><?php esc_html_e('Sync models', 'gpt3-ai-content-generator'); ?></span>
            </button>
            <?php endif; ?>
        </div>
        <?php
        $aipkit_unified_model_selector_config = [
            'trigger_id' => 'aipkit_bot_' . $bot_id . '_unified_model_trigger',
            'initial_label' => $saved_model ?: __('Select model', 'gpt3-ai-content-generator'),
            'capability' => 'text_generation',
        ];
        include WPAICG_PLUGIN_DIR . 'admin/views/shared/unified-model-selector.php';
        ?>
        <?php if (!empty($is_next_layout)) : ?>
        <span
            class="aipkit_chatbot_model_last_synced"
            data-aipkit-model-last-synced
            aria-live="polite"
            hidden
        ></span>
        <?php endif; ?>
    </div>

    <div class="aipkit_model_state_controls" aria-hidden="true">
    <div class="aipkit_form-group aipkit_form-col aipkit_chatbot_model_col aipkit_chatbot_model_col--provider">
        <label
            class="aipkit_form-label"
            for="aipkit_bot_<?php echo esc_attr($bot_id); ?>_provider"
        >
            <?php esc_html_e('Engine', 'gpt3-ai-content-generator'); ?>
        </label>
                <select
            id="aipkit_bot_<?php echo esc_attr($bot_id); ?>_provider"
            name="provider"
            class="aipkit_form-input aipkit_chatbot_provider_select" <?php // JS targets this class?>
            data-aipkit-provider-notice-target="aipkit_provider_notice_chatbot"
            data-aipkit-model-state-select="1"
        >
            <?php if (empty($provider_select_options)) :
                foreach ($providers as $p_value) {
                    $p_value = (string) $p_value;
                    if ($p_value === '') {
                        continue;
                    }
                    $disabled = ($p_value === 'Ollama' && empty($is_pro));
                    $label = class_exists('\\WPAICG\\AIPKit_Providers')
                        ? \WPAICG\AIPKit_Providers::get_provider_display_name($p_value)
                        : ($p_value === 'Claude' ? __('Anthropic', 'gpt3-ai-content-generator') : $p_value);
                    $provider_select_options[] = [
                        'value' => $p_value,
                        'label' => $disabled ? __('Ollama (Pro)', 'gpt3-ai-content-generator') : $label,
                        'disabled' => $disabled,
                    ];
                }
            endif; ?>
            <?php foreach ($provider_select_options as $provider_option) :
                if (!is_array($provider_option)) {
                    continue;
                }
                $p_value = (string) ($provider_option['value'] ?? '');
                if ($p_value === '') {
                    continue;
                }
                $disabled = !empty($provider_option['disabled']);
                $label = (string) ($provider_option['label'] ?? $p_value);
            ?>
                <option
                    value="<?php echo esc_attr($p_value); ?>"
                    <?php selected($saved_provider, $p_value); ?> <?php echo $disabled ? 'disabled' : ''; ?>
                >
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
            </div>

    <div class="aipkit_form-group aipkit_form-col aipkit_chatbot_model_col aipkit_chatbot_model_col--model">
        <?php
        $model_fields = [
            ['provider' => 'OpenAI', 'slug' => 'openai'],
            ['provider' => 'OpenRouter', 'slug' => 'openrouter'],
            ['provider' => 'Google', 'slug' => 'google'],
            ['provider' => 'Claude', 'slug' => 'claude'],
            ['provider' => 'Azure', 'slug' => 'azure', 'name' => 'azure_deployment', 'label' => __('Deployment', 'gpt3-ai-content-generator')],
            ['provider' => 'DeepSeek', 'slug' => 'deepseek'],
            ['provider' => 'xAI', 'slug' => 'xai'],
        ];
        $paid_model_options = WPAICG_PLUGIN_DIR . 'lib/views/chatbot/model-options.php';
        $model_fields[] = ['provider' => 'AIPufferCloud', 'slug' => 'aipuffercloud'];
        if (!empty($is_pro) && is_file($paid_model_options)) {
            $model_fields = array_merge($model_fields, include $paid_model_options);
        }
        foreach ($model_fields as $model_field) {
            $render_simple_model_field($model_field);
        }
        ?>

    </div>
    </div>
</div>
