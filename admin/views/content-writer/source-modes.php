<?php
/** Shared source-mode dispatch and upgrade panels for Content Writer and Automations. */
if (!defined('ABSPATH')) {
    exit;
}

// Parent inputs: $is_pro, $aipkit_cw_source_mode and optional automation/RSS flags.
if (!in_array($aipkit_cw_source_mode ?? '', ['rss', 'url', 'gsheets'], true)) {
    return;
}

$aipkit_paid_source_view = WPAICG_PLUGIN_DIR . 'lib/views/content-writer/source-modes.php';
if (!empty($is_pro) && class_exists('\\WPAICG\\aipkit_dashboard') && \WPAICG\aipkit_dashboard::is_pro_plan() && file_exists($aipkit_paid_source_view)) {
    include $aipkit_paid_source_view;
    return;
}

switch ($aipkit_cw_source_mode) {
    case 'rss':
        $aipkit_feature_promo_class = 'aipkit_feature_promo--rss';
        $aipkit_feature_promo_dashicon = 'dashicons-rss';
        $aipkit_feature_promo_title = __('RSS feed content generation', 'gpt3-ai-content-generator');
        $aipkit_feature_promo_subtitle = __('Turn RSS feeds into unique, AI-written posts — hands-free.', 'gpt3-ai-content-generator');
        $aipkit_feature_promo_steps = [
            __('Add feed URLs', 'gpt3-ai-content-generator'),
            __('AI rewrites each item', 'gpt3-ai-content-generator'),
            __('Publish automatically', 'gpt3-ai-content-generator'),
        ];
        $aipkit_feature_promo_cards = [
            ['dashicon' => 'dashicons-screenoptions', 'label' => __('Multiple feeds', 'gpt3-ai-content-generator')],
            ['dashicon' => 'dashicons-admin-generic', 'label' => __('Smart parsing', 'gpt3-ai-content-generator')],
            ['dashicon' => 'dashicons-clock', 'label' => __('Auto-schedule', 'gpt3-ai-content-generator')],
        ];
        break;
    case 'url':
        $aipkit_feature_promo_class = 'aipkit_feature_promo--url';
        $aipkit_feature_promo_dashicon = 'dashicons-admin-links';
        $aipkit_feature_promo_title = __('URL content extraction', 'gpt3-ai-content-generator');
        $aipkit_feature_promo_subtitle = __('Turn web pages into fresh, AI-written content.', 'gpt3-ai-content-generator');
        $aipkit_feature_promo_steps = [
            __('Add page URLs', 'gpt3-ai-content-generator'),
            __('AI extracts the content', 'gpt3-ai-content-generator'),
            __('Create unique posts', 'gpt3-ai-content-generator'),
        ];
        $aipkit_feature_promo_cards = [
            ['dashicon' => 'dashicons-admin-site-alt3', 'label' => __('Any website', 'gpt3-ai-content-generator')],
            ['dashicon' => 'dashicons-filter', 'label' => __('Smart extraction', 'gpt3-ai-content-generator')],
            ['dashicon' => 'dashicons-update', 'label' => __('Bulk processing', 'gpt3-ai-content-generator')],
        ];
        break;
    case 'gsheets':
        $aipkit_feature_promo_class = 'aipkit_feature_promo--gsheets';
        $aipkit_feature_promo_dashicon = 'dashicons-media-spreadsheet';
        $aipkit_feature_promo_title = __('Google Sheets content import', 'gpt3-ai-content-generator');
        $aipkit_feature_promo_subtitle = __('Turn spreadsheet rows into ready-to-publish WordPress posts.', 'gpt3-ai-content-generator');
        $aipkit_feature_promo_steps = [
            __('Connect a sheet', 'gpt3-ai-content-generator'),
            __('Map your columns', 'gpt3-ai-content-generator'),
            __('Generate in bulk', 'gpt3-ai-content-generator'),
        ];
        $aipkit_feature_promo_cards = [
            ['dashicon' => 'dashicons-update', 'label' => __('Live sync', 'gpt3-ai-content-generator')],
            ['dashicon' => 'dashicons-editor-table', 'label' => __('Column mapping', 'gpt3-ai-content-generator')],
            ['dashicon' => 'dashicons-controls-repeat', 'label' => __('Bulk generation', 'gpt3-ai-content-generator')],
        ];
        break;
}
$aipkit_feature_promo_show_pro_badge = true;
$aipkit_feature_promo_upgrade_label = __('Upgrade', 'gpt3-ai-content-generator');
$aipkit_feature_promo_upgrade_url = admin_url('admin.php?page=wpaicg-pricing');
include WPAICG_PLUGIN_DIR . 'admin/views/shared/feature-promo.php';
