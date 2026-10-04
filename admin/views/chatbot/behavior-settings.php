<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

if (!defined('ABSPATH')) {
    exit;
}

$bot_id = $initial_active_bot_id;
$behavior_sections = [
    'chat-options' => [
        'heading' => __('Conversation', 'gpt3-ai-content-generator'),
        'title' => __('Chat options', 'gpt3-ai-content-generator'),
        'hint' => __('Choose what visitors can do in chat.', 'gpt3-ai-content-generator'),
        'toggle_label' => __('Toggle Chat options settings', 'gpt3-ai-content-generator'),
        'icon' => 'admin-comments',
    ],
    'knowledge' => [
        'title' => __('Knowledge', 'gpt3-ai-content-generator'),
        'hint' => __('Choose how this chatbot uses your content.', 'gpt3-ai-content-generator'),
        'toggle_label' => __('Toggle Knowledge settings', 'gpt3-ai-content-generator'),
        'icon' => 'search',
    ],
    'capabilities' => [
        'title' => __('Capabilities', 'gpt3-ai-content-generator'),
        'hint' => __('Enable file, web, image, and voice features.', 'gpt3-ai-content-generator'),
        'toggle_label' => __('Toggle Capabilities settings', 'gpt3-ai-content-generator'),
        'icon' => 'admin-tools',
    ],
    'model' => [
        'heading' => __('AI behavior', 'gpt3-ai-content-generator'),
        'title' => __('Model', 'gpt3-ai-content-generator'),
        'hint' => __('Adjust response style, memory, and reasoning.', 'gpt3-ai-content-generator'),
        'toggle_label' => __('Toggle Model settings', 'gpt3-ai-content-generator'),
        'icon' => 'admin-generic',
    ],
    'limits' => [
        'title' => __('Limits', 'gpt3-ai-content-generator'),
        'hint' => $limits_summary_text ?? __('Set visitor message limits.', 'gpt3-ai-content-generator'),
        'summary_default' => $limits_summary_fallback ?? __('Set visitor message limits.', 'gpt3-ai-content-generator'),
        'toggle_label' => __('Toggle Limits settings', 'gpt3-ai-content-generator'),
        'icon' => 'chart-bar',
    ],
    'apps' => [
        'heading' => __('Automations', 'gpt3-ai-content-generator'),
        'title' => __('Apps', 'gpt3-ai-content-generator'),
        'hint' => $connected_apps_summary_text ?: __('Send chat activity to your tools.', 'gpt3-ai-content-generator'),
        'summary_default' => __('Send chat activity to your tools.', 'gpt3-ai-content-generator'),
        'toggle_label' => __('Toggle Apps settings', 'gpt3-ai-content-generator'),
        'icon' => 'share',
    ],
    'rules' => [
        'title' => __('Rules', 'gpt3-ai-content-generator'),
        'hint' => $rules_summary_text ?: __('Automate replies and actions.', 'gpt3-ai-content-generator'),
        'summary_default' => __('Automate replies and actions.', 'gpt3-ai-content-generator'),
        'toggle_label' => __('Toggle Rules settings', 'gpt3-ai-content-generator'),
        'icon' => 'controls-repeat',
    ],
];
?>
<div class="aipkit_popover_options_list aipkit_behavior_compact_options aipkit_behavior_compact_options--general">
    <?php foreach ($behavior_sections as $behavior_section_key => $behavior_section) :
        $behavior_panel_id = 'aipkit_general_' . str_replace('-', '_', $behavior_section_key) . '_panel';
        $behavior_panel_class = in_array($behavior_section_key, ['model', 'limits', 'apps', 'rules'], true)
            ? ' aipkit_general_' . $behavior_section_key . '_section_panel'
            : '';
        ?>
        <?php if (isset($behavior_section['heading'])) : ?>
            <div class="aipkit_chatbot_settings_section_heading">
                <?php echo esc_html($behavior_section['heading']); ?>
            </div>
        <?php endif; ?>
        <div
            class="aipkit_interface_feature_row aipkit_interface_feature_row--expandable aipkit_display_settings_row aipkit_general_settings_section_row aipkit_general_settings_section_row--<?php echo esc_attr($behavior_section_key); ?>"
            data-aipkit-inline-settings-row
            data-aipkit-static-inline-settings-row
        >
            <div class="aipkit_interface_feature_label">
                <span class="aipkit_display_settings_icon" aria-hidden="true">
                    <span class="dashicons dashicons-<?php echo esc_attr($behavior_section['icon']); ?>"></span>
                </span>
                <span class="aipkit_interface_feature_text">
                    <span class="aipkit_interface_feature_title aipkit_popover_option_label">
                        <?php echo esc_html($behavior_section['title']); ?>
                        <?php if (!$is_pro_plan && in_array($behavior_section_key, ['apps', 'rules'], true)) : ?>
                            <span class="aipkit_general_settings_section_badge aipkit_paid_feature_badge aipkit_pro_badge"><?php esc_html_e('Pro', 'gpt3-ai-content-generator'); ?></span>
                        <?php endif; ?>
                    </span>
                    <span
                        class="aipkit_interface_feature_hint"
                        <?php if ($behavior_section_key === 'limits') : ?>data-aipkit-limits-section-summary<?php endif; ?>
                        <?php if ($behavior_section_key === 'apps') : ?>data-aipkit-connected-apps-section-summary<?php endif; ?>
                        <?php if ($behavior_section_key === 'rules') : ?>data-aipkit-rules-section-summary<?php endif; ?>
                        <?php if (isset($behavior_section['summary_default'])) : ?>data-default-summary="<?php echo esc_attr($behavior_section['summary_default']); ?>"<?php endif; ?>
                    >
                        <?php echo esc_html($behavior_section['hint']); ?>
                    </span>
                </span>
            </div>
            <div class="aipkit_interface_feature_action">
                <button
                    type="button"
                    class="aipkit_popover_option_btn aipkit_display_settings_toggle aipkit_interface_feature_expand_btn"
                    data-aipkit-inline-settings-toggle
                    data-aipkit-static-inline-settings-toggle
                    aria-expanded="false"
                    aria-controls="<?php echo esc_attr($behavior_panel_id); ?>"
                    aria-label="<?php echo esc_attr($behavior_section['toggle_label']); ?>"
                >
                    <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                </button>
            </div>
            <div
                id="<?php echo esc_attr($behavior_panel_id); ?>"
                class="aipkit_interface_feature_inline_panel aipkit_display_inline_panel aipkit_general_settings_section_panel<?php echo esc_attr($behavior_panel_class); ?>"
                hidden
            >
                <?php if ($behavior_section_key === 'chat-options') : ?>
                    <?php include __DIR__ . '/interface-feature-settings.php'; ?>
                <?php elseif ($behavior_section_key === 'knowledge') : ?>
                    <div class="aipkit_general_knowledge_section aipkit_settings_panel_body" data-aipkit-settings-panel="context">
                        <?php include __DIR__ . '/context.php'; ?>
                    </div>
                <?php elseif ($behavior_section_key === 'capabilities') : ?>
                    <div class="aipkit_general_capabilities_section aipkit_settings_panel_body" data-aipkit-settings-panel="tools">
                        <?php include __DIR__ . '/tools-settings.php'; ?>
                    </div>
                <?php elseif ($behavior_section_key === 'model') : ?>
                    <div class="aipkit_builder_field aipkit_chatbot_response_settings">
                        <?php include __DIR__ . '/response-settings.php'; ?>
                    </div>
                <?php elseif ($behavior_section_key === 'limits') : ?>
                    <div class="aipkit_general_limits_section aipkit_settings_panel_body" data-aipkit-settings-panel="limits">
                        <?php include __DIR__ . '/limits-settings.php'; ?>
                    </div>
                <?php elseif ($behavior_section_key === 'apps') : ?>
                    <?php include __DIR__ . '/connected-apps-settings.php'; ?>
                <?php elseif ($behavior_section_key === 'rules') : ?>
                    <button
                        type="button"
                        class="aipkit_chatbot_settings_action_btn aipkit_general_settings_manage_btn aipkit_builder_sheet_trigger"
                        data-sheet-title="<?php esc_attr_e('Rules', 'gpt3-ai-content-generator'); ?>"
                        data-sheet-description="<?php esc_attr_e('Create and manage rule-based automations for this chatbot.', 'gpt3-ai-content-generator'); ?>"
                        data-sheet-content="triggers"
                    >
                        <?php esc_html_e('Manage rules', 'gpt3-ai-content-generator'); ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
