<?php
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

$is_pro = class_exists('\\WPAICG\\aipkit_dashboard') && \WPAICG\aipkit_dashboard::is_pro_plan();
?>

<select
    id="aipkit_content_writer_provider"
    name="ai_provider"
    class="aipkit_autosave_trigger"
    data-aipkit-provider-notice-target="aipkit_provider_notice_content_writer"
    data-aipkit-provider-notice-defer="1"
    hidden
    aria-hidden="true"
    tabindex="-1"
>
    <?php
    if (!empty($providers_for_select) && is_array($providers_for_select)) {
        foreach ($providers_for_select as $provider_name) {
            $provider_value = strtolower($provider_name);
            $provider_disabled = ($provider_name === 'Ollama' && !$is_pro);
            $provider_display_name = class_exists('\\WPAICG\\AIPKit_Providers')
                ? \WPAICG\AIPKit_Providers::get_provider_display_name((string) $provider_name)
                : ((string) $provider_name === 'Claude' ? __('Anthropic', 'gpt3-ai-content-generator') : (string) $provider_name);
            $provider_label = $provider_disabled
                ? __('Ollama (Pro)', 'gpt3-ai-content-generator')
                : $provider_display_name;
            ?>
            <option
                value="<?php echo esc_attr($provider_value); ?>"
                <?php selected($default_provider, $provider_value); ?>
                <?php echo $provider_disabled ? 'disabled' : ''; ?>
            >
                <?php echo esc_html($provider_label); ?>
            </option>
            <?php
        }
    }
    ?>
</select>

<input
    type="hidden"
    id="aipkit_content_writer_model"
    name="ai_model"
    class="aipkit_autosave_trigger"
    value="<?php echo esc_attr($default_model); ?>"
>

<div class="aipkit_cw_ai_row">
    <div class="aipkit_cw_panel_label_wrap">
        <label class="aipkit_cw_panel_label" for="aipkit_content_writer_unified_model_trigger">
            <?php esc_html_e('Model', 'gpt3-ai-content-generator'); ?>
        </label>
    </div>
    <div class="aipkit_cw_ai_control aipkit_cw_ai_control--model">
        <select
            id="aipkit_content_writer_ai_selection"
            hidden
            aria-hidden="true"
            tabindex="-1"
        >
            <option value=""><?php esc_html_e('Loading models...', 'gpt3-ai-content-generator'); ?></option>
        </select>
        <?php
        $aipkit_unified_model_selector_config = [
            'trigger_id'   => 'aipkit_content_writer_unified_model_trigger',
            'initial_label' => __('Select model', 'gpt3-ai-content-generator'),
            'source_id'    => 'aipkit_content_writer_ai_selection',
            'class_name'   => 'aipkit_cw_unified_model_selector',
            'show_trigger_logo' => false,
            'capability' => 'text_generation',
        ];
        include WPAICG_PLUGIN_DIR . 'admin/views/shared/unified-model-selector.php';
        unset($aipkit_unified_model_selector_config);
        ?>
        <button
            type="button"
            class="aipkit_cw_advanced_options_trigger"
            id="aipkit_cw_advanced_options_trigger"
            data-aipkit-advanced-options-trigger
            aria-controls="aipkit_cw_advanced_options_modal"
            aria-expanded="false"
            aria-haspopup="dialog"
            aria-label="<?php esc_attr_e('Advanced settings', 'gpt3-ai-content-generator'); ?>"
            title="<?php esc_attr_e('Advanced settings', 'gpt3-ai-content-generator'); ?>"
        >
            <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
        </button>
    </div>
</div>

<?php include __DIR__ . '/advanced-options-modal.php'; ?>
