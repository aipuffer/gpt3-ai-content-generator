<?php
/** Shared Automations model renderer. */
if (!defined('ABSPATH')) {
    exit;
}

function aipkit_render_automation_model_settings(array $aipkit_autogpt_setup_config, array $aipkit_view_data = []): void
{
    $cw_providers_for_select = $aipkit_view_data['cw_providers_for_select'] ?? [];
    $cw_default_temperature = $aipkit_view_data['cw_default_temperature'] ?? '1';
    $aipkit_autogpt_setup_scope = isset($aipkit_autogpt_setup_config['scope'])
        ? (string) $aipkit_autogpt_setup_config['scope']
        : 'cw';
    $aipkit_autogpt_setup_name_prefix = isset($aipkit_autogpt_setup_config['name_prefix'])
        ? (string) $aipkit_autogpt_setup_config['name_prefix']
        : '';
    $aipkit_autogpt_setup_model_question = isset($aipkit_autogpt_setup_config['model_question'])
        ? (string) $aipkit_autogpt_setup_config['model_question']
        : __('Model', 'gpt3-ai-content-generator');
    $aipkit_autogpt_setup_length_question = isset($aipkit_autogpt_setup_config['length_question'])
        ? (string) $aipkit_autogpt_setup_config['length_question']
        : __('Content length', 'gpt3-ai-content-generator');
    $aipkit_autogpt_setup_temperature_question = isset($aipkit_autogpt_setup_config['temperature_question'])
        ? (string) $aipkit_autogpt_setup_config['temperature_question']
        : __('Writing style', 'gpt3-ai-content-generator');
    $aipkit_autogpt_setup_temperature_helper = isset($aipkit_autogpt_setup_config['temperature_helper'])
        ? (string) $aipkit_autogpt_setup_config['temperature_helper']
        : __('Balance factual accuracy and creative flair.', 'gpt3-ai-content-generator');
    $aipkit_autogpt_setup_max_tokens_question = isset($aipkit_autogpt_setup_config['max_tokens_question'])
        ? (string) $aipkit_autogpt_setup_config['max_tokens_question']
        : __('Maximum output length', 'gpt3-ai-content-generator');
    $aipkit_autogpt_setup_reasoning_question = isset($aipkit_autogpt_setup_config['reasoning_question'])
        ? (string) $aipkit_autogpt_setup_config['reasoning_question']
        : __('Reasoning level', 'gpt3-ai-content-generator');
    $aipkit_autogpt_setup_reasoning_helper = isset($aipkit_autogpt_setup_config['reasoning_helper'])
        ? (string) $aipkit_autogpt_setup_config['reasoning_helper']
        : __('Higher improves quality, but costs more and takes longer.', 'gpt3-ai-content-generator');
    $aipkit_autogpt_setup_has_length = !empty($aipkit_autogpt_setup_config['has_length']);
    $aipkit_autogpt_setup_has_max_tokens = !empty($aipkit_autogpt_setup_config['has_max_tokens']);
    $aipkit_autogpt_setup_default_max_tokens = isset($aipkit_autogpt_setup_config['default_max_tokens'])
        ? $aipkit_autogpt_setup_config['default_max_tokens']
        : '4000';
    $aipkit_autogpt_setup_content_length_options = isset($aipkit_autogpt_setup_config['content_length_options']) && is_array($aipkit_autogpt_setup_config['content_length_options'])
        ? $aipkit_autogpt_setup_config['content_length_options']
        : [
            'short' => __('Short', 'gpt3-ai-content-generator'),
            'medium' => __('Medium', 'gpt3-ai-content-generator'),
            'long' => __('Long', 'gpt3-ai-content-generator'),
        ];
    $aipkit_autogpt_setup_default_length = isset($aipkit_autogpt_setup_config['default_length'])
        ? (string) $aipkit_autogpt_setup_config['default_length']
        : 'medium';
    
    $aipkit_autogpt_setup_providers = isset($cw_providers_for_select) && is_array($cw_providers_for_select)
        ? $cw_providers_for_select
        : [];
    $aipkit_autogpt_setup_default_provider = class_exists('\WPAICG\AIPKit_Providers')
        ? strtolower(\WPAICG\AIPKit_Providers::get_current_provider())
        : 'openai';
    $aipkit_autogpt_setup_is_pro = class_exists('\WPAICG\aipkit_dashboard') && \WPAICG\aipkit_dashboard::is_pro_plan();
    $aipkit_autogpt_setup_default_temperature = isset($cw_default_temperature) ? $cw_default_temperature : '1';
    $aipkit_autogpt_setup_reasoning_options = [
        'none' => __('Off', 'gpt3-ai-content-generator'),
        'low' => __('Low', 'gpt3-ai-content-generator'),
        'medium' => __('Medium', 'gpt3-ai-content-generator'),
        'high' => __('High', 'gpt3-ai-content-generator'),
        'xhigh' => __('Extra high', 'gpt3-ai-content-generator'),
        'max' => __('Max', 'gpt3-ai-content-generator'),
    ];
    
    $aipkit_autogpt_setup_base = 'aipkit_task_' . $aipkit_autogpt_setup_scope;
    $aipkit_autogpt_setup_provider_id = $aipkit_autogpt_setup_base . '_ai_provider';
    $aipkit_autogpt_setup_model_id = $aipkit_autogpt_setup_base . '_ai_model';
    $aipkit_autogpt_setup_selection_id = $aipkit_autogpt_setup_base . '_ai_selection';
    $aipkit_autogpt_setup_temperature_id = $aipkit_autogpt_setup_base . '_ai_temperature';
    $aipkit_autogpt_setup_temperature_value_id = $aipkit_autogpt_setup_temperature_id . '_value';
    $aipkit_autogpt_setup_reasoning_row_class = $aipkit_autogpt_setup_base . '_reasoning_effort_field';
    $aipkit_autogpt_setup_reasoning_id = $aipkit_autogpt_setup_base . '_reasoning_effort';
    $aipkit_autogpt_setup_reasoning_slider_id = $aipkit_autogpt_setup_reasoning_id . '_slider';
    $aipkit_autogpt_setup_reasoning_value_id = $aipkit_autogpt_setup_reasoning_id . '_value';
    $aipkit_autogpt_setup_content_length_id = $aipkit_autogpt_setup_base . '_content_length';
    $aipkit_autogpt_setup_content_max_tokens_id = $aipkit_autogpt_setup_base . '_content_max_tokens';
    
    ?>
    <div class="aipkit_content_writer_inputs aipkit_autogpt_setup_fields">
        <select
            id="<?php echo esc_attr($aipkit_autogpt_setup_provider_id); ?>"
            name="<?php echo esc_attr($aipkit_autogpt_setup_name_prefix . 'ai_provider'); ?>"
            class="aipkit_autosave_trigger"
            data-aipkit-prefer-configured="1"
            data-aipkit-provider-notice-target="aipkit_provider_notice_autogpt"
            data-aipkit-provider-notice-defer="1"
            hidden
            aria-hidden="true"
            tabindex="-1"
        >
            <?php foreach ($aipkit_autogpt_setup_providers as $aipkit_autogpt_setup_provider_name) : ?>
                <?php
                $aipkit_autogpt_setup_provider_value = strtolower((string) $aipkit_autogpt_setup_provider_name);
                $aipkit_autogpt_setup_provider_disabled = ($aipkit_autogpt_setup_provider_name === 'Ollama' && !$aipkit_autogpt_setup_is_pro);
                $aipkit_autogpt_setup_provider_display_name = class_exists('\\WPAICG\\AIPKit_Providers')
                    ? \WPAICG\AIPKit_Providers::get_provider_display_name((string) $aipkit_autogpt_setup_provider_name)
                    : ((string) $aipkit_autogpt_setup_provider_name === 'Claude' ? __('Anthropic', 'gpt3-ai-content-generator') : (string) $aipkit_autogpt_setup_provider_name);
                $aipkit_autogpt_setup_provider_label = $aipkit_autogpt_setup_provider_disabled
                    ? __('Ollama (Pro)', 'gpt3-ai-content-generator')
                    : $aipkit_autogpt_setup_provider_display_name;
                ?>
                <option
                    value="<?php echo esc_attr($aipkit_autogpt_setup_provider_value); ?>"
                    <?php selected($aipkit_autogpt_setup_default_provider, $aipkit_autogpt_setup_provider_value); ?>
                    <?php echo $aipkit_autogpt_setup_provider_disabled ? 'disabled' : ''; ?>
                >
                    <?php echo esc_html($aipkit_autogpt_setup_provider_label); ?>
                </option>
            <?php endforeach; ?>
        </select>
    
        <select
            id="<?php echo esc_attr($aipkit_autogpt_setup_model_id); ?>"
            name="<?php echo esc_attr($aipkit_autogpt_setup_name_prefix . 'ai_model'); ?>"
            class="aipkit_autosave_trigger screen-reader-text"
            hidden
            aria-hidden="true"
            tabindex="-1"
        ></select>
    
        <div class="aipkit_cw_ai_row aipkit_autogpt_question_row">
            <div class="aipkit_cw_panel_label_wrap">
                <label class="aipkit_cw_panel_label aipkit_autogpt_question" for="<?php echo esc_attr($aipkit_autogpt_setup_selection_id . '_trigger'); ?>">
                    <?php echo esc_html($aipkit_autogpt_setup_model_question); ?>
                </label>
            </div>
            <div class="aipkit_cw_ai_control aipkit_cw_ai_control--model">
                <select
                    id="<?php echo esc_attr($aipkit_autogpt_setup_selection_id); ?>"
                    data-aipkit-unified-model-source
                    hidden
                    aria-hidden="true"
                    tabindex="-1"
                >
                    <option value=""><?php esc_html_e('Loading models...', 'gpt3-ai-content-generator'); ?></option>
                </select>
                <?php
                $aipkit_unified_model_selector_config = [
                    'trigger_id' => $aipkit_autogpt_setup_selection_id . '_trigger',
                    'source_id' => $aipkit_autogpt_setup_selection_id,
                    'initial_label' => __('Loading models...', 'gpt3-ai-content-generator'),
                    'class_name' => 'aipkit_autogpt_unified_model_selector',
                    'capability' => 'text_generation',
                ];
                include WPAICG_PLUGIN_DIR . 'admin/views/shared/unified-model-selector.php';
                ?>
            </div>
        </div>
        <?php if ($aipkit_autogpt_setup_has_length) : ?>
            <div class="aipkit_cw_ai_row aipkit_autogpt_question_row">
                <div class="aipkit_cw_panel_label_wrap">
                    <label class="aipkit_cw_panel_label aipkit_autogpt_question" for="<?php echo esc_attr($aipkit_autogpt_setup_content_length_id); ?>">
                        <?php echo esc_html($aipkit_autogpt_setup_length_question); ?>
                    </label>
                </div>
                <div class="aipkit_cw_ai_control aipkit_cw_ai_control--compact aipkit_autogpt_length_control">
                    <select
                        id="<?php echo esc_attr($aipkit_autogpt_setup_content_length_id); ?>"
                        name="<?php echo esc_attr($aipkit_autogpt_setup_name_prefix . 'content_length'); ?>"
                        class="aipkit_autosave_trigger aipkit_form-input aipkit_cw_blended_chevron_select"
                        data-aipkit-segmented-select
                    >
                        <?php foreach ($aipkit_autogpt_setup_content_length_options as $aipkit_autogpt_setup_length_value => $aipkit_autogpt_setup_length_label) : ?>
                            <option value="<?php echo esc_attr($aipkit_autogpt_setup_length_value); ?>" <?php selected($aipkit_autogpt_setup_length_value, $aipkit_autogpt_setup_default_length); ?>>
                                <?php echo esc_html($aipkit_autogpt_setup_length_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        <?php endif; ?>
    
        <div class="aipkit_cw_ai_row aipkit_autogpt_question_row aipkit_autogpt_temperature_row">
            <div class="aipkit_cw_panel_label_wrap">
                <label class="aipkit_cw_panel_label aipkit_autogpt_question" for="<?php echo esc_attr($aipkit_autogpt_setup_temperature_id); ?>">
                    <?php echo esc_html($aipkit_autogpt_setup_temperature_question); ?>
                </label>
                <span class="aipkit_autogpt_question_helper" title="<?php echo esc_attr($aipkit_autogpt_setup_temperature_helper); ?>"><?php echo esc_html($aipkit_autogpt_setup_temperature_helper); ?></span>
            </div>
            <div class="aipkit_cw_ai_control aipkit_ai_temperature_control aipkit_autogpt_temperature_control">
                <div class="aipkit_autogpt_temperature_header" aria-hidden="true">
                    <span><?php esc_html_e('Focused', 'gpt3-ai-content-generator'); ?></span>
                    <output
                        id="<?php echo esc_attr($aipkit_autogpt_setup_temperature_value_id); ?>"
                        class="aipkit_ai_behavior_value aipkit_autogpt_temperature_value"
                        for="<?php echo esc_attr($aipkit_autogpt_setup_temperature_id); ?>"
                        data-aipkit-temperature-value
                    ><?php esc_html_e('Balanced', 'gpt3-ai-content-generator'); ?></output>
                    <span><?php esc_html_e('Creative', 'gpt3-ai-content-generator'); ?></span>
                </div>
                <input
                    type="range"
                    id="<?php echo esc_attr($aipkit_autogpt_setup_temperature_id); ?>"
                    name="<?php echo esc_attr($aipkit_autogpt_setup_name_prefix . 'ai_temperature'); ?>"
                    class="aipkit_form-input aipkit_autosave_trigger aipkit_autogpt_temperature_slider"
                    min="0"
                    max="2"
                    step="0.1"
                    value="<?php echo esc_attr($aipkit_autogpt_setup_default_temperature); ?>"
                    data-aipkit-temperature-slider
                    data-label-focused="<?php esc_attr_e('Focused', 'gpt3-ai-content-generator'); ?>"
                    data-label-balanced="<?php esc_attr_e('Balanced', 'gpt3-ai-content-generator'); ?>"
                    data-label-creative="<?php esc_attr_e('Creative', 'gpt3-ai-content-generator'); ?>"
                />
            </div>
        </div>
    
        <?php if ($aipkit_autogpt_setup_has_max_tokens) : ?>
            <div class="aipkit_cw_ai_row aipkit_autogpt_question_row">
                <div class="aipkit_cw_panel_label_wrap">
                    <label class="aipkit_cw_panel_label aipkit_autogpt_question" for="<?php echo esc_attr($aipkit_autogpt_setup_content_max_tokens_id); ?>">
                        <?php echo esc_html($aipkit_autogpt_setup_max_tokens_question); ?>
                    </label>
                </div>
                <div class="aipkit_cw_ai_control aipkit_cw_ai_control--compact aipkit_autogpt_number_with_suffix">
                    <input
                        type="number"
                        id="<?php echo esc_attr($aipkit_autogpt_setup_content_max_tokens_id); ?>"
                        name="<?php echo esc_attr($aipkit_autogpt_setup_name_prefix . 'content_max_tokens'); ?>"
                        class="aipkit_form-input aipkit_autosave_trigger"
                        min="1"
                        step="1"
                        value="<?php echo esc_attr($aipkit_autogpt_setup_default_max_tokens); ?>"
                        inputmode="numeric"
                    />
                    <span><?php esc_html_e('tokens', 'gpt3-ai-content-generator'); ?></span>
                </div>
            </div>
        <?php endif; ?>
    
        <div class="aipkit_cw_ai_row aipkit_autogpt_question_row aipkit_autogpt_reasoning_row <?php echo esc_attr($aipkit_autogpt_setup_reasoning_row_class); ?>" hidden>
            <div class="aipkit_cw_panel_label_wrap">
                <label class="aipkit_cw_panel_label aipkit_autogpt_question" for="<?php echo esc_attr($aipkit_autogpt_setup_reasoning_slider_id); ?>">
                    <?php echo esc_html($aipkit_autogpt_setup_reasoning_question); ?>
                </label>
                <span class="aipkit_autogpt_question_helper" title="<?php echo esc_attr($aipkit_autogpt_setup_reasoning_helper); ?>"><?php echo esc_html($aipkit_autogpt_setup_reasoning_helper); ?></span>
            </div>
            <div class="aipkit_cw_ai_control aipkit_autogpt_reasoning_control">
                <select
                    id="<?php echo esc_attr($aipkit_autogpt_setup_reasoning_id); ?>"
                    name="<?php echo esc_attr($aipkit_autogpt_setup_name_prefix . 'reasoning_effort'); ?>"
                    class="aipkit_autosave_trigger aipkit_cw_blended_chevron_select"
                    data-aipkit-reasoning-confirm-select
                >
                    <?php foreach ($aipkit_autogpt_setup_reasoning_options as $aipkit_autogpt_setup_reasoning_value => $aipkit_autogpt_setup_reasoning_label) : ?>
                        <option value="<?php echo esc_attr($aipkit_autogpt_setup_reasoning_value); ?>" <?php selected($aipkit_autogpt_setup_reasoning_value, 'none'); ?>>
                            <?php echo esc_html($aipkit_autogpt_setup_reasoning_label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="aipkit_autogpt_reasoning_header" aria-hidden="true">
                    <span data-aipkit-reasoning-start><?php esc_html_e('Off', 'gpt3-ai-content-generator'); ?></span>
                    <output
                        id="<?php echo esc_attr($aipkit_autogpt_setup_reasoning_value_id); ?>"
                        class="aipkit_ai_behavior_value aipkit_autogpt_reasoning_value"
                        for="<?php echo esc_attr($aipkit_autogpt_setup_reasoning_slider_id); ?>"
                        data-aipkit-reasoning-value
                    ><?php esc_html_e('Off', 'gpt3-ai-content-generator'); ?></output>
                    <span data-aipkit-reasoning-end><?php esc_html_e('Extra high', 'gpt3-ai-content-generator'); ?></span>
                </div>
                <input
                    type="range"
                    id="<?php echo esc_attr($aipkit_autogpt_setup_reasoning_slider_id); ?>"
                    class="aipkit_form-input aipkit_autogpt_reasoning_slider"
                    min="0"
                    max="4"
                    step="1"
                    value="0"
                    data-aipkit-reasoning-slider
                />
            </div>
        </div>
    
    </div>

    <?php
}
