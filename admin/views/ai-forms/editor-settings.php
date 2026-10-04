<?php

/**
 * Partial: AI Form Editor - Main Settings
 * Renders the right-hand editor column for model, prompt, connected apps,
 * and advanced generation settings.
 */
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

// Variables passed from parent (form-editor.php):
// $providers, $default_temp, $default_max_tokens, $default_top_p, $default_frequency_penalty, $default_presence_penalty
// Variables supplied by page.php and form-editor.php:
// $openai_vector_stores, $pinecone_indexes, $qdrant_collections, $chroma_collections, $openai_embedding_models, $google_embedding_models
$model_sync_timestamps = \WPAICG\AIPKit_Providers::get_model_sync_timestamps();
if (!is_array($model_sync_timestamps)) {
    $model_sync_timestamps = [];
}
?>
<div class="aipkit_ai_form_editor_sidebar">
    <select
        id="aipkit_ai_form_ai_provider"
        name="ai_provider"
        class="aipkit_form-input aipkit_ai_form_hidden_ai_field"
        data-aipkit-provider-notice-target="aipkit_provider_notice_ai_forms"
        data-aipkit-provider-notice-defer="1"
        hidden
        aria-hidden="true"
        tabindex="-1"
    >
        <?php foreach ($providers as $p_value) :
            $disabled = false;
            $label = class_exists('\\WPAICG\\AIPKit_Providers')
                ? \WPAICG\AIPKit_Providers::get_provider_display_name((string) $p_value)
                : ((string) $p_value === 'Claude' ? __('Anthropic', 'gpt3-ai-content-generator') : (string) $p_value);
            if ($p_value === 'Ollama' && (empty($is_pro) || !$is_pro)) {
                $disabled = true;
                $label = __('Ollama (Pro)', 'gpt3-ai-content-generator');
            }
            ?>
            <option value="<?php echo esc_attr($p_value); ?>" <?php echo $disabled ? 'disabled' : ''; ?>><?php echo esc_html($label); ?></option>
        <?php endforeach; ?>
    </select>
    <input
        type="hidden"
        id="aipkit_ai_form_ai_model"
        name="ai_model"
        class="aipkit_ai_form_hidden_ai_field"
        value=""
    >

    <section class="aipkit_ai_form_inspector_card aipkit_ai_form_inspector_card--model">
        <div class="aipkit_ai_form_inspector_card_header">
            <div class="aipkit_ai_form_inspector_card_header_copy">
                <h3 class="aipkit_ai_form_inspector_card_title"><?php esc_html_e('AI', 'gpt3-ai-content-generator'); ?></h3>
            </div>
        </div>
        <div class="aipkit_ai_form_inspector_card_body">
            <div class="aipkit_ai_form_model_field">
                <label class="aipkit_ai_form_model_label" for="aipkit_ai_form_unified_model_trigger">
                    <?php esc_html_e('Model', 'gpt3-ai-content-generator'); ?>
                </label>
                <div class="aipkit_ai_form_model_control">
                    <select
                        id="aipkit_ai_form_ai_selection"
                        class="aipkit_form-input aipkit_ai_form_hidden_ai_field"
                        hidden
                        aria-hidden="true"
                        tabindex="-1"
                    >
                        <option value=""><?php esc_html_e('Loading models...', 'gpt3-ai-content-generator'); ?></option>
                    </select>
                    <?php
                    $aipkit_unified_model_selector_config = [
                        'trigger_id' => 'aipkit_ai_form_unified_model_trigger',
                        'initial_label' => __('Select model', 'gpt3-ai-content-generator'),
                        'source_id' => 'aipkit_ai_form_ai_selection',
                        'class_name' => 'aipkit_ai_form_unified_model_selector',
                        'capability' => 'text_generation',
                    ];
                    include WPAICG_PLUGIN_DIR . 'admin/views/shared/unified-model-selector.php';
                    unset($aipkit_unified_model_selector_config);
                    ?>
                    <button
                        type="button"
                        class="aipkit_ai_form_model_action_btn aipkit_ai_form_model_sync_btn"
                        id="aipkit_ai_form_model_sync_btn"
                        data-aipkit-model-sync-times="<?php echo esc_attr(wp_json_encode($model_sync_timestamps)); ?>"
                        aria-label="<?php esc_attr_e('Sync models for the selected provider', 'gpt3-ai-content-generator'); ?>"
                        aria-busy="false"
                        title="<?php esc_attr_e('Sync models', 'gpt3-ai-content-generator'); ?>"
                    >
                        <span class="dashicons dashicons-update" aria-hidden="true"></span>
                    </button>
                    <button
                        type="button"
                        class="aipkit_ai_form_model_action_btn aipkit_ai_form_model_settings_trigger aipkit_ai_form_model_settings_icon"
                        id="aipkit_ai_form_model_settings_trigger"
                        aria-haspopup="dialog"
                        aria-controls="aipkit_ai_form_model_settings_modal"
                        aria-expanded="false"
                        aria-label="<?php esc_attr_e('Model settings', 'gpt3-ai-content-generator'); ?>"
                        title="<?php esc_attr_e('Model settings', 'gpt3-ai-content-generator'); ?>"
                    >
                        <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
                    </button>
                </div>
                <span
                    class="aipkit_ai_form_model_last_synced"
                    id="aipkit_ai_form_model_last_synced"
                    aria-live="polite"
                    hidden
                ></span>
            </div>

            <div class="aipkit_ai_form_prompt_field">
                <label class="aipkit_ai_form_model_label aipkit_ai_form_prompt_label" for="aipkit_ai_form_prompt_template">
                    <?php esc_html_e('Prompt', 'gpt3-ai-content-generator'); ?>
                </label>
                <div class="aipkit_builder_textarea_wrap aipkit_ai_form_prompt_wrap">
                    <textarea id="aipkit_ai_form_prompt_template" name="prompt_template" class="aipkit_builder_textarea aipkit_form-input" rows="12" placeholder="<?php esc_attr_e('e.g., Generate a meta description for: {your_field_name}', 'gpt3-ai-content-generator'); ?>"></textarea>
                    <button
                        type="button"
                        class="aipkit_builder_icon_btn aipkit_builder_textarea_expand aipkit_ai_form_prompt_expand"
                        aria-label="<?php esc_attr_e('Expand prompt editor', 'gpt3-ai-content-generator'); ?>"
                    >
                        <span class="dashicons dashicons-editor-expand"></span>
                    </button>
                </div>
                <div class="aipkit_prompt_snippets_container" id="aipkit_prompt_snippets_container">
                </div>
                <div class="aipkit_prompt_validation_area">
                    <button type="button" id="aipkit_validate_prompt_btn" class="aipkit_btn aipkit_btn-secondary aipkit_ai_form_prompt_validate_btn">
                        <span class="dashicons dashicons-editor-spellcheck"></span>
                        <span class="aipkit_btn-text"><?php esc_html_e('Validate', 'gpt3-ai-content-generator'); ?></span>
                    </button>
                    <div id="aipkit_prompt_validation_results" class="aipkit_form-help"></div>
                </div>
            </div>
        </div>
    </section>

    <details
        class="aipkit_ai_form_inspector_card aipkit_ai_form_inspector_card--collapsible aipkit_ai_form_inspector_card--advanced"
        id="aipkit_ai_form_settings_panel"
    >
        <summary class="aipkit_ai_form_inspector_card_header aipkit_ai_form_inspector_card_summary">
            <span class="aipkit_ai_form_inspector_card_header_copy">
                <span class="aipkit_ai_form_inspector_card_title" role="heading" aria-level="3"><?php esc_html_e('Context', 'gpt3-ai-content-generator'); ?></span>
            </span>
            <span class="dashicons dashicons-arrow-down-alt2 aipkit_ai_form_inspector_card_chevron" aria-hidden="true"></span>
        </summary>
        <div class="aipkit_ai_form_inspector_card_body aipkit_ai_form_context_body">
            <div class="aipkit_ai_form_model_field aipkit_ai_form_context_field">
                <div class="aipkit_ai_form_context_copy">
                    <label class="aipkit_ai_form_model_label" for="aipkit_ai_form_enable_vector_store_display">
                        <?php esc_html_e('Knowledge base', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <span class="aipkit_ai_form_context_helper" id="aipkit_ai_form_context_kb_help">
                        <?php esc_html_e('Use selected sources for answers.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
                <div class="aipkit_ai_form_model_control aipkit_ai_form_context_control">
                    <label class="aipkit_switch aipkit_ai_form_context_switch">
                        <input
                            type="checkbox"
                            id="aipkit_ai_form_enable_vector_store_display"
                            class="aipkit_ai_form_context_enable_select"
                            value="1"
                            aria-label="<?php esc_attr_e('Enable knowledge base', 'gpt3-ai-content-generator'); ?>"
                            aria-describedby="aipkit_ai_form_context_kb_help"
                        >
                        <span class="aipkit_switch_slider"></span>
                    </label>
                    <button
                        type="button"
                        class="aipkit_ai_form_model_action_btn aipkit_ai_form_model_settings_icon aipkit_ai_form_context_settings_icon"
                        id="aipkit_ai_form_context_settings_trigger"
                        aria-haspopup="dialog"
                        aria-controls="aipkit_ai_form_knowledge_base_settings_modal"
                        aria-expanded="false"
                        aria-label="<?php esc_attr_e('Configure knowledge base retrieval', 'gpt3-ai-content-generator'); ?>"
                        title="<?php esc_attr_e('Configure knowledge base retrieval', 'gpt3-ai-content-generator'); ?>"
                    >
                        <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
                    </button>
                </div>
            </div>

            <div class="aipkit_ai_form_model_field aipkit_ai_form_context_field">
                <div class="aipkit_ai_form_context_copy">
                    <label class="aipkit_ai_form_model_label" for="aipkit_ai_form_web_search_enabled_display">
                        <?php esc_html_e('Web Search', 'gpt3-ai-content-generator'); ?>
                    </label>
                    <span class="aipkit_ai_form_context_helper" id="aipkit_ai_form_context_web_help">
                        <?php esc_html_e('Use current information from the web.', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
                <div class="aipkit_ai_form_model_control aipkit_ai_form_context_control">
                    <label class="aipkit_switch aipkit_ai_form_context_switch">
                        <input
                            type="checkbox"
                            id="aipkit_ai_form_web_search_enabled_display"
                            class="aipkit_ai_form_web_search_enable_select"
                            value="1"
                            aria-label="<?php esc_attr_e('Enable web search', 'gpt3-ai-content-generator'); ?>"
                            aria-describedby="aipkit_ai_form_context_web_help"
                        >
                        <span class="aipkit_switch_slider"></span>
                    </label>
                    <button
                        type="button"
                        class="aipkit_ai_form_model_action_btn aipkit_ai_form_model_settings_icon aipkit_ai_form_context_settings_icon"
                        id="aipkit_ai_form_web_search_settings_trigger"
                        aria-haspopup="dialog"
                        aria-controls="aipkit_ai_form_web_search_settings_modal"
                        aria-expanded="false"
                        aria-label="<?php esc_attr_e('Configure web search', 'gpt3-ai-content-generator'); ?>"
                        title="<?php esc_attr_e('Configure web search', 'gpt3-ai-content-generator'); ?>"
                    >
                        <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
                    </button>
                </div>
            </div>
        </div>
    </details>

    <details
        class="aipkit_ai_form_inspector_card aipkit_ai_form_inspector_card--collapsible aipkit_ai_form_connected_apps_card"
        data-aipkit-ai-form-connected-apps
        data-manage-url="<?php echo esc_url($connected_apps_manage_url); ?>"
    >
        <summary class="aipkit_ai_form_inspector_card_header aipkit_ai_form_inspector_card_summary">
            <span class="aipkit_ai_form_inspector_card_header_copy">
                <span class="aipkit_ai_form_inspector_card_title_row">
                    <span class="aipkit_ai_form_inspector_card_title" role="heading" aria-level="3"><?php esc_html_e('Connected Apps', 'gpt3-ai-content-generator'); ?></span>
                    <?php if (!$is_pro): ?>
                        <span class="aipkit_pro_tag aipkit_pro_badge"><?php esc_html_e('Pro', 'gpt3-ai-content-generator'); ?></span>
                    <?php endif; ?>
                </span>
                <?php if ($is_pro): ?>
                    <?php aipkit_render_paid_ai_form_editor_panel('connected-apps-summary'); ?>
                <?php endif; ?>
            </span>
            <span class="dashicons dashicons-arrow-down-alt2 aipkit_ai_form_inspector_card_chevron" aria-hidden="true"></span>
        </summary>
        <div class="aipkit_ai_form_inspector_card_body aipkit_ai_form_connected_apps_body">
            <?php if ($is_pro): ?>
                <?php aipkit_render_paid_ai_form_editor_panel('connected-apps', $connected_apps_manage_url); ?>
            <?php else : ?>
                <div class="aipkit_chatbot_connected_apps_upsell aipkit_ai_form_connected_apps_upsell">
                    <p class="aipkit_chatbot_connected_apps_intro_text">
                        <?php esc_html_e('Send form submissions to Slack, HubSpot, Notion, Pipedrive, Zapier, Make, and n8n.', 'gpt3-ai-content-generator'); ?>
                    </p>
                    <div class="aipkit_chatbot_connected_apps_logo_grid" aria-label="<?php esc_attr_e('Supported app destinations', 'gpt3-ai-content-generator'); ?>">
                        <?php foreach ($connected_apps_supported_destinations as $connected_app_destination) : ?>
                            <span
                                class="aipkit_chatbot_connected_apps_logo_item"
                                title="<?php echo esc_attr((string) $connected_app_destination['name']); ?>"
                            >
                                <img
                                    class="aipkit_chatbot_connected_apps_logo"
                                    src="<?php echo esc_url((string) $connected_app_destination['logo_url']); ?>"
                                    alt="<?php echo esc_attr((string) $connected_app_destination['name']); ?>"
                                    loading="lazy"
                                    decoding="async"
                                />
                            </span>
                        <?php endforeach; ?>
                        <a
                            href="<?php echo esc_url($upgrade_url); ?>"
                            class="aipkit_btn aipkit_ai_form_connected_apps_upgrade aipkit_chatbot_connected_apps_grid_cta aipkit_pro_upgrade_button"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <span class="aipkit_btn-text"><?php esc_html_e('Upgrade', 'gpt3-ai-content-generator'); ?></span>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </details>
</div>
