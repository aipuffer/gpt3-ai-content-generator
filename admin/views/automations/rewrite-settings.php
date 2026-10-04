<?php
/** Shared rewrite promotion and paid-panel dispatch for Automations. */
if (!defined('ABSPATH')) {
    exit;
}

function aipkit_render_automation_rewrite_panel(string $aipkit_panel, array $aipkit_view_data = []): void
{
    $aipkit_paid_view = defined('WPAICG_LIB_DIR') ? WPAICG_LIB_DIR . 'views/automations/rewrite.php' : '';
    if (class_exists('\\WPAICG\\aipkit_dashboard') && \WPAICG\aipkit_dashboard::is_pro_plan() && $aipkit_paid_view !== '' && file_exists($aipkit_paid_view)) {
        require_once $aipkit_paid_view;
        aipkit_render_paid_automation_rewrite_panel($aipkit_panel, $aipkit_view_data);
        return;
    }
    if ($aipkit_panel !== 'source') {
        return;
    }
    $aipkit_feature_promo_class = 'aipkit_feature_promo--content-enhance';
    $aipkit_feature_promo_dashicon = 'dashicons-update';
    $aipkit_feature_promo_title = __('Rewrite existing content', 'gpt3-ai-content-generator');
    $aipkit_feature_promo_subtitle = __('Refresh and improve existing WordPress content automatically.', 'gpt3-ai-content-generator');
    $aipkit_feature_promo_steps = [
        __('Choose existing posts', 'gpt3-ai-content-generator'),
        __('AI rewrites the content', 'gpt3-ai-content-generator'),
        __('Update automatically', 'gpt3-ai-content-generator'),
    ];
    $aipkit_feature_promo_cards = [
        ['dashicon' => 'dashicons-edit', 'label' => __('Rewrite and polish', 'gpt3-ai-content-generator')],
        ['dashicon' => 'dashicons-admin-generic', 'label' => __('Custom instructions', 'gpt3-ai-content-generator')],
        ['dashicon' => 'dashicons-update', 'label' => __('Bulk processing', 'gpt3-ai-content-generator')],
    ];
    $aipkit_feature_promo_upgrade_url = admin_url('admin.php?page=wpaicg-pricing');
    $aipkit_feature_promo_show_pro_badge = true;
    $aipkit_feature_promo_upgrade_label = __('Upgrade', 'gpt3-ai-content-generator');
    include WPAICG_PLUGIN_DIR . 'admin/views/shared/feature-promo.php';
}
