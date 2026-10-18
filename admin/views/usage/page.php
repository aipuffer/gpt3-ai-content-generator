<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

$stats_default_days = 30;
$user_credits_nonce = wp_create_nonce('aipkit_user_credits_nonce');
$visitor_billing_enabled = \WPAICG\Stats\AIPKit_Stats::visitor_billing_enabled();
$cloud_allowed = class_exists('\\WPAICG\\Cloud\\Connection') && \WPAICG\Cloud\Connection::allowed();
$cloud_connected = $cloud_allowed && \WPAICG\Cloud\Connection::display()['connected'];
$is_pro = class_exists('\\WPAICG\\aipkit_dashboard')
    ? \WPAICG\aipkit_dashboard::is_pro_plan()
    : false;
// Log retention is paid: its controls ship in lib; the free plan sees a promotion instead.
$retention_paid_view = defined('WPAICG_LIB_DIR') ? WPAICG_LIB_DIR . 'views/usage/log-retention.php' : '';
$retention_is_paid = $is_pro && $retention_paid_view !== '' && file_exists($retention_paid_view);

$module_labels = [
    'chatbot' => __('Chatbot', 'gpt3-ai-content-generator'),
    'content_writer' => __('Content Writer', 'gpt3-ai-content-generator'),
    'content_writer_automation' => __('Automation', 'gpt3-ai-content-generator'),
    'image_generator' => __('Image generator', 'gpt3-ai-content-generator'),
    'ai_forms' => __('AI Forms', 'gpt3-ai-content-generator'),
    'autogpt' => __('Automations', 'gpt3-ai-content-generator'),
    'ai_post_enhancer' => __('Content Assistant', 'gpt3-ai-content-generator'),
    'wp_ai_client' => __('WP AI Client', 'gpt3-ai-content-generator'),
    'unknown' => __('Unknown', 'gpt3-ai-content-generator'),
];
// Each period: its menu label, and the short form the cards show beside their titles.
$period_options = [
    1 => [__('Today', 'gpt3-ai-content-generator'), __('Today', 'gpt3-ai-content-generator')],
    7 => [__('Last 7 days', 'gpt3-ai-content-generator'), __('7 days', 'gpt3-ai-content-generator')],
    30 => [__('Last 30 days', 'gpt3-ai-content-generator'), __('30 days', 'gpt3-ai-content-generator')],
    90 => [__('Last 90 days', 'gpt3-ai-content-generator'), __('90 days', 'gpt3-ai-content-generator')],
];
// Website activity: what ChatGPT and Claude read and changed here (website management, AI Puffer Cloud).
$website_activity = $cloud_allowed && class_exists('\\WPAICG\\Mcp\\Activity');
$website_connected = $cloud_allowed && \WPAICG\Mcp\Identity::active();
$activity_views = [
    'everything' => __('Everything', 'gpt3-ai-content-generator'),
    'conversations' => __('Conversations', 'gpt3-ai-content-generator'),
    'tasks' => __('Content', 'gpt3-ai-content-generator'),
    'website' => __('Website', 'gpt3-ai-content-generator'),
    'requests' => __('AI requests', 'gpt3-ai-content-generator'),
    'errors' => __('Errors', 'gpt3-ai-content-generator'),
];
?>

<div
    class="aipkit_container aipkit_stats_container aipkit_admin_ui"
    id="aipkit_stats_container"
    data-default-days="<?php echo esc_attr($stats_default_days); ?>"
    data-is-pro="<?php echo esc_attr($is_pro ? '1' : '0'); ?>"
    data-module-labels="<?php echo esc_attr(wp_json_encode($module_labels)); ?>"
    data-user-credits-nonce="<?php echo esc_attr($user_credits_nonce); ?>"
    data-cloud-connected="<?php echo esc_attr($cloud_connected ? '1' : '0'); ?>"
>
    <div class="aipkit_usage_header">
        <div class="aipkit_usage_header_copy">
            <div class="aipkit_stats_header_title_row">
                <h1 class="aipkit_container-title"><?php esc_html_e('Dashboard', 'gpt3-ai-content-generator'); ?></h1>
                <span id="aipkit_stats_status" class="aipkit_training_status aipkit_global_status_area" aria-live="polite"></span>
            </div>
            <p class="aipkit_stats_header_hint"><?php echo $cloud_connected
                ? esc_html__('Your balances and what happened on this site.', 'gpt3-ai-content-generator')
                : esc_html__('What happened on this site.', 'gpt3-ai-content-generator'); ?></p>
        </div>
        <div class="aipkit_usage_header_actions">
            <?php // The period as one button with a menu: it sets "Used on this site", the summary and Activity. ?>
            <div class="aipkit_usage_period" data-aipkit-usage-period>
                <button type="button" class="aipkit_usage_period_toggle" data-aipkit-usage-period-toggle aria-haspopup="menu" aria-expanded="false" aria-controls="aipkit_usage_period_menu">
                    <span class="screen-reader-text"><?php esc_html_e('Period:', 'gpt3-ai-content-generator'); ?> </span>
                    <span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
                    <span data-aipkit-usage-period-current><?php echo esc_html($period_options[$stats_default_days][0]); ?></span>
                    <span class="aipkit_usage_period_caret" aria-hidden="true"></span>
                </button>
                <div class="aipkit_usage_period_menu" id="aipkit_usage_period_menu" role="menu" aria-label="<?php esc_attr_e('Period', 'gpt3-ai-content-generator'); ?>" hidden>
                    <?php foreach ($period_options as $period_days => $period_label) : ?>
                        <button type="button" class="aipkit_usage_period_option" role="menuitemradio" tabindex="-1" data-aipkit-usage-days="<?php echo esc_attr($period_days); ?>" data-short-label="<?php echo esc_attr($period_label[1]); ?>" aria-checked="<?php echo $period_days === $stats_default_days ? 'true' : 'false'; ?>"><?php echo esc_html($period_label[0]); ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php // One refresh for every balance: it submits the AI credits card's refresh form (Cloud then refreshes website actions) and reloads the actions used. ?>
            <button type="submit" class="aipkit_usage_icon_btn aipkit_usage_refresh" form="aipkit_usage_cloud_refresh" name="cloud_action" value="refresh" data-aipkit-dashboard-refresh aria-label="<?php esc_attr_e('Refresh balances', 'gpt3-ai-content-generator'); ?>" title="<?php esc_attr_e('Refresh', 'gpt3-ai-content-generator'); ?>" <?php echo $cloud_connected ? '' : 'hidden'; ?>><span class="dashicons dashicons-update" aria-hidden="true"></span><span class="aipkit_spinner" aria-hidden="true"></span></button>
            <div class="aipkit_stats_table_menu_wrapper">
                <button
                    type="button"
                    class="aipkit_stats_table_menu_trigger"
                    id="aipkit_stats_table_menu_trigger"
                    aria-expanded="false"
                    aria-controls="aipkit_stats_table_menu"
                    aria-haspopup="menu"
                >
                    <span class="aipkit_stats_table_menu_trigger_dots" aria-hidden="true">
                        <span class="aipkit_stats_table_menu_trigger_dot"></span>
                        <span class="aipkit_stats_table_menu_trigger_dot"></span>
                        <span class="aipkit_stats_table_menu_trigger_dot"></span>
                    </span>
                    <span class="screen-reader-text"><?php esc_html_e('More actions', 'gpt3-ai-content-generator'); ?></span>
                </button>
                <div
                    class="aipkit_stats_table_menu"
                    id="aipkit_stats_table_menu"
                    role="menu"
                    aria-label="<?php esc_attr_e('More actions', 'gpt3-ai-content-generator'); ?>"
                    hidden
                >
                    <button type="button" class="aipkit_stats_table_menu_item" id="aipkit_stats_export_btn" role="menuitem">
                        <span class="aipkit_stats_table_menu_item_icon" aria-hidden="true">
                            <span class="dashicons dashicons-download"></span>
                        </span>
                        <span class="aipkit_stats_table_menu_item_label"><?php esc_html_e('Export logs', 'gpt3-ai-content-generator'); ?></span>
                    </button>
                    <button type="button" class="aipkit_stats_table_menu_item" id="aipkit_stats_retention_open" role="menuitem">
                        <span class="aipkit_stats_table_menu_item_icon" aria-hidden="true">
                            <span class="dashicons dashicons-backup"></span>
                        </span>
                        <span class="aipkit_stats_table_menu_item_label"><?php esc_html_e('Log retention…', 'gpt3-ai-content-generator'); ?></span>
                    </button>
                    <?php if ($visitor_billing_enabled) : ?>
                        <button type="button" class="aipkit_stats_table_menu_item" id="aipkit_stats_billing_open" role="menuitem" data-aipkit-usage-drawer-open="aipkit_usage_billing_drawer" aria-haspopup="dialog">
                            <span class="aipkit_stats_table_menu_item_icon" aria-hidden="true">
                                <span class="dashicons dashicons-groups"></span>
                            </span>
                            <span class="aipkit_stats_table_menu_item_label"><?php esc_html_e('Visitor billing', 'gpt3-ai-content-generator'); ?></span>
                        </button>
                    <?php endif; ?>
                    <button type="button" class="aipkit_stats_table_menu_item aipkit_stats_table_menu_item--danger" id="aipkit_stats_delete_all_btn" role="menuitem">
                        <span class="aipkit_stats_table_menu_item_icon" aria-hidden="true">
                            <span class="dashicons dashicons-trash"></span>
                        </span>
                        <span class="aipkit_stats_table_menu_item_label"><?php esc_html_e('Delete filtered logs', 'gpt3-ai-content-generator'); ?></span>
                    </button>
                </div>
            </div>
        </div>
        <?php // The account chip sits between the title and the period; email and reconnect notices span the row below. ?>
        <?php include __DIR__ . '/cloud-account-line.php'; ?>
    </div>

    <?php if (class_exists('\\WPAICG\\Mcp\\Screens')) { \WPAICG\Mcp\Screens::render_usage_attention(); } ?>
    <?php // Two cards: AI credits, and website actions read left to right (what is left, then what this site used). A half
    // without data hides, and a card with no visible half hides; with one card left, the period summary fills the
    // second slot. Own API key usage is in the AI requests view. ?>
    <div class="aipkit_usage_cards" data-aipkit-usage-cards>
        <section class="aipkit_usage_card aipkit_usage_card--stack" data-aipkit-usage-ai-card aria-label="<?php esc_attr_e('AI credits', 'gpt3-ai-content-generator'); ?>" <?php echo $cloud_connected ? '' : 'hidden'; ?>>
            <?php if ($cloud_connected) : ?>
                <?php include __DIR__ . '/cloud-account.php'; ?>
            <?php endif; ?>
        </section>
        <?php // One column: what is left this month (all sites), then what this site used in the period, and on what. ?>
        <section class="aipkit_usage_card aipkit_usage_card--stack" data-aipkit-usage-website-card aria-label="<?php esc_attr_e('Website actions', 'gpt3-ai-content-generator'); ?>" <?php echo $website_connected ? '' : 'hidden'; ?>>
            <?php \WPAICG\Mcp\Settings::render_usage(); ?>
            <?php if ($website_activity) : ?>
                <?php // Disconnected sites may have retained history. Wait for it before showing either
                // this card or its replacement summary, so neither flashes and disappears on entry. ?>
                <section class="aipkit_usage_half aipkit_usage_breakdown" data-website-breakdown data-website-breakdown-pending aria-labelledby="aipkit_usage_breakdown_title" <?php echo $website_connected ? '' : 'hidden'; ?>>
                    <div class="aipkit_usage_site_head">
                        <h3 class="aipkit_usage_site_title" id="aipkit_usage_breakdown_title"><?php esc_html_e('On this site', 'gpt3-ai-content-generator'); ?> · <span data-aipkit-usage-period-label="short" title="<?php esc_attr_e('Successful actions in the selected period, counted in UTC.', 'gpt3-ai-content-generator'); ?>"></span></h3>
                        <span class="aipkit_usage_site_total" data-website-breakdown-total>—</span>
                    </div>
                    <div data-website-breakdown-content role="status"><?php esc_html_e('Loading website actions…', 'gpt3-ai-content-generator'); ?></div>
                </section>
            <?php endif; ?>
        </section>
        <section class="aipkit_usage_card" data-aipkit-usage-summary-card aria-labelledby="aipkit_usage_summary_title" hidden>
            <div class="aipkit_usage_card_head">
                <h2 class="aipkit_usage_card_title" id="aipkit_usage_summary_title" data-aipkit-usage-period-label></h2>
            </div>
            <dl class="aipkit_usage_summary">
                <div>
                    <dd class="aipkit_usage_card_value" data-aipkit-usage-summary="conversations">—</dd>
                    <dt><?php esc_html_e('conversations', 'gpt3-ai-content-generator'); ?></dt>
                </div>
                <div>
                    <dd class="aipkit_usage_card_value" data-aipkit-usage-summary="tasks">—</dd>
                    <dt><?php esc_html_e('content', 'gpt3-ai-content-generator'); ?></dt>
                </div>
                <div>
                    <dd class="aipkit_usage_card_value" data-aipkit-usage-summary="requests">—</dd>
                    <dt><?php esc_html_e('AI requests', 'gpt3-ai-content-generator'); ?></dt>
                </div>
            </dl>
        </section>
    </div>

    <section class="aipkit_usage_activity" aria-labelledby="aipkit_usage_activity_title">
        <div class="aipkit_usage_activity_bar">
            <h2 class="aipkit_usage_activity_title" id="aipkit_usage_activity_title"><?php esc_html_e('Activity', 'gpt3-ai-content-generator'); ?></h2>
            <div class="aipkit_usage_chips" role="group" aria-label="<?php esc_attr_e('Activity views', 'gpt3-ai-content-generator'); ?>">
                <?php foreach ($activity_views as $view_key => $view_label) : ?>
                    <button
                        type="button"
                        class="aipkit_usage_chip"
                        data-aipkit-usage-view="<?php echo esc_attr($view_key); ?>"
                        aria-pressed="<?php echo $view_key === 'everything' ? 'true' : 'false'; ?>"
                        <?php echo $view_key === 'errors' || ($view_key === 'website' && !$website_activity) ? 'hidden' : ''; ?>
                    ><?php echo esc_html($view_label); ?><span class="aipkit_usage_chip_count" data-aipkit-usage-count="<?php echo esc_attr($view_key); ?>"></span></button>
                <?php endforeach; ?>
            </div>
            <label class="aipkit_usage_search" for="aipkit_stats_search_input">
                <span class="dashicons dashicons-search" aria-hidden="true"></span>
                <span class="screen-reader-text"><?php esc_html_e('Search activity', 'gpt3-ai-content-generator'); ?></span>
                <input type="search" id="aipkit_stats_search_input" placeholder="<?php esc_attr_e('Search…', 'gpt3-ai-content-generator'); ?>" autocomplete="off" />
            </label>
        </div>
        <div class="aipkit_usage_subchips" role="group" data-aipkit-usage-subchips hidden></div>
        <p class="screen-reader-text" aria-live="polite" data-aipkit-usage-announce></p>

        <div class="aipkit_usage_view" data-aipkit-usage-panel="feed">
            <ul class="aipkit_usage_feed" id="aipkit_usage_feed_list"></ul>
            <div class="aipkit_usage_view_footer" data-aipkit-usage-feed-footer hidden>
                <button type="button" class="aipkit_usage_link_btn" id="aipkit_usage_feed_more"><?php esc_html_e('Show more', 'gpt3-ai-content-generator'); ?></button>
            </div>
        </div>

        <?php if ($website_activity) : ?>
            <div class="aipkit_usage_view" data-aipkit-usage-panel="website" hidden>
                <ul class="aipkit_usage_feed" id="aipkit_usage_website_list"></ul>
                <div class="aipkit_usage_view_footer aipkit_usage_website_footer">
                    <span class="aipkit_usage_website_note"><?php echo esc_html(sprintf(
                        /* translators: %d: number of days website activity is kept. */
                        __('Website activity is kept for %d days on this site.', 'gpt3-ai-content-generator'),
                        \WPAICG\Mcp\Journal::RETENTION_DAYS
                    )); ?></span>
                    <button type="button" class="aipkit_usage_link_btn" id="aipkit_usage_website_more" hidden><?php esc_html_e('Show more', 'gpt3-ai-content-generator'); ?></button>
                </div>
            </div>
        <?php endif; ?>

        <div class="aipkit_usage_view" data-aipkit-usage-panel="logs" hidden>
            <div class="aipkit_data-table aipkit_stats_table aipkit_usage_table">
                <table class="aipkit_data-table__table">
                    <thead>
                        <tr id="aipkit_stats_logs_table_header"></tr>
                        <tr id="aipkit_stats_logs_selection_header" class="aipkit_stats_logs_selection_header" hidden>
                            <th colspan="6">
                                <div class="aipkit_stats_logs_selection_toolbar">
                                    <span class="aipkit_stats_logs_selection_count" id="aipkit_stats_logs_selection_count"></span>
                                    <span class="aipkit_stats_logs_selection_actions">
                                        <button type="button" class="aipkit_stats_logs_selection_action aipkit_stats_logs_selection_action--danger" id="aipkit_stats_logs_bulk_delete">
                                            <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                                            <?php esc_html_e('Delete', 'gpt3-ai-content-generator'); ?>
                                        </button>
                                        <button type="button" class="aipkit_stats_logs_selection_action" id="aipkit_stats_logs_selection_clear">
                                            <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                                            <?php esc_html_e('Clear', 'gpt3-ai-content-generator'); ?>
                                        </button>
                                    </span>
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody id="aipkit_stats_table_body"></tbody>
                </table>
            </div>
            <div id="aipkit_stats_pagination" class="aipkit_logs_pagination_container aipkit_data-table-footer"></div>
        </div>

        <div class="aipkit_usage_view" data-aipkit-usage-panel="requests" hidden>
            <div class="aipkit_data-table aipkit_stats_ledger_table aipkit_usage_table">
                <table class="aipkit_data-table__table">
                    <thead><tr id="aipkit_stats_requests_header">
                        <th class="aipkit_stats_log_select_cell"><input type="checkbox" class="aipkit_stats_log_checkbox" id="aipkit_stats_requests_select_all" aria-label="<?php esc_attr_e('Select all requests on this page', 'gpt3-ai-content-generator'); ?>" /></th>
                        <th><?php esc_html_e('When', 'gpt3-ai-content-generator'); ?></th>
                        <th><?php esc_html_e('Request type', 'gpt3-ai-content-generator'); ?></th>
                        <th><?php esc_html_e('Provider / model', 'gpt3-ai-content-generator'); ?></th>
                        <th><?php esc_html_e('Usage', 'gpt3-ai-content-generator'); ?></th>
                    </tr>
                    <tr id="aipkit_stats_requests_selection" class="aipkit_stats_logs_selection_header" hidden><th colspan="5">
                        <div class="aipkit_stats_logs_selection_toolbar">
                            <span class="aipkit_stats_logs_selection_count" id="aipkit_stats_requests_count"></span>
                            <span class="aipkit_stats_logs_selection_actions">
                                <button type="button" class="aipkit_stats_logs_selection_action aipkit_stats_logs_selection_action--danger" id="aipkit_stats_requests_delete"><span class="dashicons dashicons-trash" aria-hidden="true"></span><?php esc_html_e('Delete', 'gpt3-ai-content-generator'); ?></button>
                                <button type="button" class="aipkit_stats_logs_selection_action" id="aipkit_stats_requests_clear"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span><?php esc_html_e('Clear', 'gpt3-ai-content-generator'); ?></button>
                            </span>
                        </div>
                    </th></tr></thead>
                    <tbody id="aipkit_stats_requests_body"></tbody>
                </table>
            </div>
            <div id="aipkit_stats_requests_pagination" class="aipkit_logs_pagination_container aipkit_data-table-footer"></div>
        </div>
    </section>

    <?php if ($cloud_allowed) : ?>
        <?php // One Cloud invitation at a time: the banner while it shows; this quiet line once it is dismissed. ?>
        <?php $aipkit_banner_dismissed = \WPAICG\Cloud\Announcement::dismissed(); ?>
        <p class="aipkit_usage_invite" data-aipkit-usage-invite data-banner-dismissed="<?php echo $aipkit_banner_dismissed ? '1' : '0'; ?>" hidden>
            <span class="dashicons dashicons-cloud" aria-hidden="true"></span>
            <span><strong><?php esc_html_e('Free every month: 25 AI credits + 2,000 website actions.', 'gpt3-ai-content-generator'); ?></strong> <?php esc_html_e('No API key needed.', 'gpt3-ai-content-generator'); ?></span>
            <button type="button" class="aipkit_usage_invite_action" data-aipkit-cloud-connect><?php esc_html_e('Connect for free', 'gpt3-ai-content-generator'); ?></button>
        </p>
    <?php endif; ?>

    <div class="aipkit_usage_drawer_overlay" id="aipkit_usage_detail_drawer" data-aipkit-usage-drawer hidden>
        <div class="aipkit_usage_drawer aipkit_usage_drawer--wide" role="dialog" aria-modal="true" aria-labelledby="aipkit_usage_detail_title" tabindex="-1">
            <div class="aipkit_usage_drawer_head">
                <div class="aipkit_usage_drawer_title_row">
                    <h2 class="aipkit_usage_drawer_title" id="aipkit_usage_detail_title"></h2>
                    <span class="aipkit_usage_pill" id="aipkit_usage_detail_status" hidden></span>
                    <button type="button" class="aipkit_usage_drawer_close" data-aipkit-usage-drawer-close aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
                </div>
                <p class="aipkit_usage_drawer_sub" id="aipkit_usage_detail_sub"></p>
                <dl class="aipkit_usage_drawer_meta" id="aipkit_usage_detail_meta" hidden></dl>
            </div>
            <div class="aipkit_usage_drawer_body" id="aipkit_stats_detail_panel"></div>
            <div class="aipkit_usage_drawer_foot" id="aipkit_usage_detail_footer" hidden></div>
        </div>
    </div>

    <?php if ($visitor_billing_enabled) : ?>
        <div class="aipkit_usage_drawer_overlay" id="aipkit_usage_billing_drawer" data-aipkit-usage-drawer hidden>
            <div class="aipkit_usage_drawer aipkit_usage_drawer--full" role="dialog" aria-modal="true" aria-labelledby="aipkit_usage_billing_title" tabindex="-1">
                <div class="aipkit_usage_drawer_head">
                    <div class="aipkit_usage_drawer_title_row">
                        <h2 class="aipkit_usage_drawer_title" id="aipkit_usage_billing_title"><?php esc_html_e('Visitor billing', 'gpt3-ai-content-generator'); ?></h2>
                        <button type="button" class="aipkit_usage_drawer_close" data-aipkit-usage-drawer-close aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
                    </div>
                    <p class="aipkit_usage_drawer_sub"><?php esc_html_e('Charge visitors credits for AI features. Set pricing, review transactions and manage visitor balances.', 'gpt3-ai-content-generator'); ?></p>
                </div>
                <div class="aipkit_usage_drawer_body aipkit_stats_billing_panels" id="aipkit_stats_visitor_billing_panel">
                    <?php
$woo_active = class_exists('WooCommerce') && post_type_exists('product') && function_exists('wc_get_product');
$woo_create_product_url = admin_url('post-new.php?post_type=product');
$woo_products_url = admin_url('edit.php?post_type=product');
$can_manage_woo_products = current_user_can('edit_products') || current_user_can('manage_woocommerce');
$customer_dashboard_page_url = (string) get_option('aipkit_token_dashboard_page_url', '');
$customer_dashboard_buycredits_saved_url = (string) get_option('aipkit_token_shop_page_url', '');
$customer_dashboard_buycredits_default_url = $customer_dashboard_buycredits_saved_url;
if ($customer_dashboard_buycredits_default_url === '' && function_exists('wc_get_page_id')) {
    $customer_dashboard_buycredits_default_url = (string) get_permalink(wc_get_page_id('shop'));
}
$woo_credit_products = [];

if ($woo_active) {
    $woo_credit_query = new \WP_Query([
        'post_type' => 'product',
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'posts_per_page' => 8,
        'orderby' => 'modified',
        'order' => 'DESC',
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Small admin-only WooCommerce lookup for token packages.
        'meta_key' => '_aipkit_is_token_package',
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Small admin-only WooCommerce lookup for token packages.
        'meta_value' => 'yes',
        'no_found_rows' => true,
    ]);

    if ($woo_credit_query->have_posts()) {
        foreach ($woo_credit_query->posts as $woo_product_post) {
            $woo_product = wc_get_product($woo_product_post->ID);
            $woo_price = $woo_product ? $woo_product->get_price() : '';
            $woo_status = get_post_status($woo_product_post);
            $woo_status_object = $woo_status ? get_post_status_object($woo_status) : null;
            $woo_edit_url = $can_manage_woo_products ? get_edit_post_link($woo_product_post->ID, '') : '';

            $woo_credit_products[] = [
                'id' => $woo_product_post->ID,
                'title' => get_the_title($woo_product_post),
                'credits' => absint(get_post_meta($woo_product_post->ID, '_aipkit_tokens_amount', true)),
                'price' => ($woo_price !== '' && $woo_price !== null) ? wc_price((float) $woo_price) : '&mdash;',
                'status' => $woo_status_object ? $woo_status_object->label : __('Unknown', 'gpt3-ai-content-generator'),
                'status_key' => $woo_status ? sanitize_key($woo_status) : 'unknown',
                'edit_url' => is_string($woo_edit_url) ? $woo_edit_url : '',
            ];
        }
    }

    wp_reset_postdata();
}
?>
<div class="aipkit_stats_tabs aipkit_stats_billing_tabs" role="tablist" aria-label="<?php esc_attr_e('Visitor billing sections', 'gpt3-ai-content-generator'); ?>">
    <button type="button" class="aipkit_stats_tab aipkit_active" id="aipkit_stats_tab_pricing" role="tab" aria-selected="true" aria-controls="aipkit_stats_billing_pricing_panel" data-aipkit-stats-tab="pricing">
        <?php esc_html_e('Pricing', 'gpt3-ai-content-generator'); ?>
    </button>
    <button type="button" class="aipkit_stats_tab" id="aipkit_stats_tab_activity" role="tab" aria-selected="false" aria-controls="aipkit_stats_billing_activity_panel" data-aipkit-stats-tab="activity" tabindex="-1">
        <?php esc_html_e('Transactions', 'gpt3-ai-content-generator'); ?>
    </button>
    <button type="button" class="aipkit_stats_tab" id="aipkit_stats_tab_balances" role="tab" aria-selected="false" aria-controls="aipkit_stats_billing_balances_panel" data-aipkit-stats-tab="balances" tabindex="-1">
        <?php esc_html_e('User balances', 'gpt3-ai-content-generator'); ?>
    </button>
    <button type="button" class="aipkit_stats_tab" id="aipkit_stats_tab_woocommerce" role="tab" aria-selected="false" aria-controls="aipkit_stats_billing_shortcode_panel" data-aipkit-stats-tab="woocommerce" tabindex="-1">
        <?php esc_html_e('Storefront', 'gpt3-ai-content-generator'); ?>
    </button>
</div>
<section
    class="aipkit_stats_tab_panel"
    id="aipkit_stats_billing_pricing_panel"
    role="tabpanel"
    aria-labelledby="aipkit_stats_tab_pricing"
    data-aipkit-stats-panel="pricing"
>
    <div class="aipkit_sub_container aipkit_stats_management_card aipkit_stats_shell">
        <div class="aipkit_sub_container_header aipkit_stats_panel_header">
            <div class="aipkit_stats_panel_intro">
                <div class="aipkit_sub_container_title"><?php esc_html_e('Pricing rules', 'gpt3-ai-content-generator'); ?></div>
                <p class="aipkit_stats_management_hint">
                    <?php esc_html_e('Set the credits visitors pay to use AI features on this site.', 'gpt3-ai-content-generator'); ?>
                </p>
            </div>
            <div class="aipkit_stats_section_actions">
                <button type="button" class="aipkit_btn aipkit_btn-primary" id="aipkit_stats_pricing_open">
                    <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                    <?php esc_html_e('New pricing', 'gpt3-ai-content-generator'); ?>
                </button>
            </div>
        </div>
        <div class="aipkit_stats_management_body aipkit_stats_shell_body">
            <div class="aipkit_data-table aipkit_stats_pricing_table">
                <table class="aipkit_data-table__table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Module', 'gpt3-ai-content-generator'); ?></th>
                            <th><?php esc_html_e('Provider / model', 'gpt3-ai-content-generator'); ?></th>
                            <th><?php esc_html_e('Pricing', 'gpt3-ai-content-generator'); ?></th>
                            <th><?php esc_html_e('Status', 'gpt3-ai-content-generator'); ?></th>
                            <th><?php esc_html_e('Actions', 'gpt3-ai-content-generator'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="aipkit_stats_pricing_rules_body">
                        <tr>
                            <td colspan="5" class="aipkit_stats_table_placeholder">
                                <?php esc_html_e('Loading pricing rules...', 'gpt3-ai-content-generator'); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<div class="aipkit-modal-overlay aipkit_stats_pricing_modal" id="aipkit_stats_pricing_modal" aria-hidden="true">
    <div class="aipkit-modal-content aipkit-modal-shell aipkit-modal-shell--compact aipkit-modal-shell--overflow-visible aipkit_stats_pricing_modal_content" role="dialog" aria-modal="true" aria-labelledby="aipkit_stats_pricing_modal_title" aria-describedby="aipkit_stats_pricing_modal_description">
        <div class="aipkit-modal-header aipkit-modal-shell-header">
            <div class="aipkit-modal-shell-intro">
                <h2 class="aipkit-modal-shell-title" id="aipkit_stats_pricing_modal_title"><?php esc_html_e('New rule', 'gpt3-ai-content-generator'); ?></h2>
                <p class="aipkit-modal-shell-copy" id="aipkit_stats_pricing_modal_description">
                    <?php esc_html_e('Set pricing for a usage type, model, and billing method.', 'gpt3-ai-content-generator'); ?>
                </p>
            </div>
            <button class="aipkit-modal-close-btn aipkit-modal-shell-close" type="button" id="aipkit_stats_pricing_modal_close" aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
        </div>
        <div class="aipkit-modal-body aipkit-modal-shell-body aipkit_stats_modal_body">
            <form id="aipkit_stats_pricing_form" class="aipkit_stats_pricing_form">
                <input type="hidden" id="aipkit_stats_pricing_rule_id" value="" />
                <input type="hidden" id="aipkit_stats_pricing_enabled" value="1" />
                <div class="aipkit_stats_pricing_form_grid">
                    <label class="aipkit_form-field">
                        <span class="aipkit_form-label"><?php esc_html_e('Usage type', 'gpt3-ai-content-generator'); ?></span>
                        <select id="aipkit_stats_pricing_usage_type" class="aipkit_popover_select"></select>
                        <select id="aipkit_stats_pricing_module" class="aipkit_popover_select" hidden tabindex="-1" aria-hidden="true">
                            <option value="chat"><?php esc_html_e('Chatbot', 'gpt3-ai-content-generator'); ?></option>
                            <option value="ai_forms"><?php esc_html_e('AI Forms', 'gpt3-ai-content-generator'); ?></option>
                            <option value="image_generator"><?php esc_html_e('Image generator', 'gpt3-ai-content-generator'); ?></option>
                        </select>
                    </label>
                    <label class="aipkit_form-field">
                        <span class="aipkit_form-label"><?php esc_html_e('Provider and model', 'gpt3-ai-content-generator'); ?></span>
                        <input type="hidden" id="aipkit_stats_pricing_provider" value="" />
                        <input type="hidden" id="aipkit_stats_pricing_model" value="" />
                        <select
                            id="aipkit_stats_pricing_ai_selection"
                            class="aipkit_form-input"
                            data-aipkit-picker-title="<?php esc_attr_e('Provider and model', 'gpt3-ai-content-generator'); ?>"
                        >
                            <option value=""><?php esc_html_e('Loading models...', 'gpt3-ai-content-generator'); ?></option>
                        </select>
                    </label>
                    <label class="aipkit_form-field">
                        <span class="aipkit_form-label"><?php esc_html_e('Billing method', 'gpt3-ai-content-generator'); ?></span>
                        <select id="aipkit_stats_pricing_billing_method" class="aipkit_popover_select"></select>
                        <select id="aipkit_stats_pricing_operation" class="aipkit_popover_select" hidden tabindex="-1" aria-hidden="true"></select>
                    </label>
                </div>
                <div class="aipkit_stats_pricing_rate_grid">
                    <label class="aipkit_form-field" data-rate-field="input_rate">
                        <span class="aipkit_form-label"><?php esc_html_e('Input rate', 'gpt3-ai-content-generator'); ?></span>
                        <input type="number" id="aipkit_stats_pricing_input_rate" class="aipkit_form-input" min="0" step="0.000001" placeholder="0.002" />
                    </label>
                    <label class="aipkit_form-field" data-rate-field="output_rate">
                        <span class="aipkit_form-label"><?php esc_html_e('Output rate', 'gpt3-ai-content-generator'); ?></span>
                        <input type="number" id="aipkit_stats_pricing_output_rate" class="aipkit_form-input" min="0" step="0.000001" placeholder="0.006" />
                    </label>
                    <label class="aipkit_form-field" data-rate-field="unit_rate">
                        <span class="aipkit_form-label" id="aipkit_stats_pricing_unit_rate_label"><?php esc_html_e('Rate per request', 'gpt3-ai-content-generator'); ?></span>
                        <input type="number" id="aipkit_stats_pricing_unit_rate" class="aipkit_form-input" min="0" step="0.000001" placeholder="1.00" aria-labelledby="aipkit_stats_pricing_unit_rate_label" />
                    </label>
                </div>
                <p class="aipkit_stats_pricing_rate_help" id="aipkit_stats_pricing_rate_help"></p>
                <div class="aipkit_stats_pricing_actions">
                    <button type="button" class="aipkit_btn aipkit_btn-secondary" id="aipkit_stats_pricing_reset">
                        <?php esc_html_e('Cancel', 'gpt3-ai-content-generator'); ?>
                    </button>
                    <button type="submit" class="aipkit_btn aipkit_btn-primary" id="aipkit_stats_pricing_save">
                        <?php esc_html_e('Save rule', 'gpt3-ai-content-generator'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<section
    class="aipkit_stats_tab_panel"
    id="aipkit_stats_billing_activity_panel"
    role="tabpanel"
    aria-labelledby="aipkit_stats_tab_activity"
    data-aipkit-stats-panel="activity"
    hidden
>
    <div class="aipkit_sub_container aipkit_stats_management_card aipkit_stats_shell">
        <div class="aipkit_sub_container_header aipkit_stats_panel_header">
            <div class="aipkit_stats_panel_intro">
                <div class="aipkit_sub_container_title"><?php esc_html_e('Visitor credit transactions', 'gpt3-ai-content-generator'); ?></div>
                <p class="aipkit_stats_management_hint">
                    <?php esc_html_e('Review credits added, debited balance, and recent ledger entries.', 'gpt3-ai-content-generator'); ?>
                </p>
            </div>
            <div class="aipkit_stats_section_actions">
                <label class="screen-reader-text" for="aipkit_stats_ledger_days_filter"><?php esc_html_e('Ledger date range', 'gpt3-ai-content-generator'); ?></label>
                <select id="aipkit_stats_ledger_days_filter" class="aipkit_popover_select">
                    <option value="7" <?php selected($stats_default_days, 7); ?>><?php esc_html_e('Last 7 days', 'gpt3-ai-content-generator'); ?></option>
                    <option value="30" <?php selected($stats_default_days, 30); ?>><?php esc_html_e('Last 30 days', 'gpt3-ai-content-generator'); ?></option>
                    <option value="90" <?php selected($stats_default_days, 90); ?>><?php esc_html_e('Last 90 days', 'gpt3-ai-content-generator'); ?></option>
                </select>
            </div>
        </div>
        <div class="aipkit_stats_management_body aipkit_stats_shell_body">
            <div class="aipkit_stats_ledger_summary_grid">
                <div class="aipkit_stats_ledger_metric">
                    <span class="aipkit_stats_ledger_metric_label"><?php esc_html_e('Credits added', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_stats_ledger_metric_value" id="aipkit_stats_ledger_added">-</span>
                </div>
                <div class="aipkit_stats_ledger_metric">
                    <span class="aipkit_stats_ledger_metric_label"><?php esc_html_e('Balance debited', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_stats_ledger_metric_value" id="aipkit_stats_ledger_spent">-</span>
                </div>
                <div class="aipkit_stats_ledger_metric">
                    <span class="aipkit_stats_ledger_metric_label"><?php esc_html_e('Quota-only usage', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_stats_ledger_metric_value" id="aipkit_stats_ledger_quota_only">-</span>
                </div>
                <div class="aipkit_stats_ledger_metric">
                    <span class="aipkit_stats_ledger_metric_label"><?php esc_html_e('Ledger entries', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_stats_ledger_metric_value" id="aipkit_stats_ledger_entries">-</span>
                </div>
            </div>

            <div class="aipkit_data-table aipkit_stats_ledger_table">
                <table class="aipkit_data-table__table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Time', 'gpt3-ai-content-generator'); ?></th>
                            <th><?php esc_html_e('Actor', 'gpt3-ai-content-generator'); ?></th>
                            <th><?php esc_html_e('Type', 'gpt3-ai-content-generator'); ?></th>
                            <th><?php esc_html_e('Module / model', 'gpt3-ai-content-generator'); ?></th>
                            <th><?php esc_html_e('Credits', 'gpt3-ai-content-generator'); ?></th>
                            <th><?php esc_html_e('Units', 'gpt3-ai-content-generator'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="aipkit_stats_ledger_body">
                        <tr>
                            <td colspan="6" class="aipkit_stats_table_placeholder">
                                <?php esc_html_e('Loading ledger activity...', 'gpt3-ai-content-generator'); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<section
    class="aipkit_stats_tab_panel"
    id="aipkit_stats_billing_balances_panel"
    role="tabpanel"
    aria-labelledby="aipkit_stats_tab_balances"
    data-aipkit-stats-panel="balances"
    hidden
>
    <div class="aipkit_sub_container aipkit_stats_management_card aipkit_stats_shell">
        <div class="aipkit_sub_container_header aipkit_stats_panel_header">
            <div class="aipkit_stats_panel_intro">
                <div class="aipkit_sub_container_title"><?php esc_html_e('Visitor credit balances', 'gpt3-ai-content-generator'); ?></div>
                <p class="aipkit_stats_management_hint">
                    <?php esc_html_e('Manage user credit balances, periodic usage, and purchase history.', 'gpt3-ai-content-generator'); ?>
                </p>
            </div>
        </div>
        <div class="aipkit_stats_management_body aipkit_stats_shell_body aipkit_stats_credits_panel_body">
            <div class="aipkit_stats_user_sheet_section">
                <div class="aipkit_stats_user_toolbar">
                    <div class="aipkit_stats_user_search_row">
                        <label class="screen-reader-text" for="aipkit_stats_user_search"><?php esc_html_e('Search users', 'gpt3-ai-content-generator'); ?></label>
                        <input
                            type="search"
                            id="aipkit_stats_user_search"
                            class="aipkit_form-input aipkit_stats_user_search_input"
                            placeholder="<?php esc_attr_e('Search by username or email', 'gpt3-ai-content-generator'); ?>"
                        />
                    </div>
                </div>

                <div class="aipkit_data-table aipkit_stats_user_table">
                    <table id="aipkit_stats_user_table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('User', 'gpt3-ai-content-generator'); ?></th>
                                <th><?php esc_html_e('Credit balance', 'gpt3-ai-content-generator'); ?></th>
                                <th><?php esc_html_e('Usage', 'gpt3-ai-content-generator'); ?></th>
                                <th><?php esc_html_e('Latest reset', 'gpt3-ai-content-generator'); ?></th>
                                <th><?php esc_html_e('Actions', 'gpt3-ai-content-generator'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="aipkit_stats_user_table_body">
                            <tr>
                                <td colspan="5" class="aipkit_stats_table_placeholder">
                                    <?php esc_html_e('Loading user credits...', 'gpt3-ai-content-generator'); ?>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot hidden>
                            <tr>
                                <th colspan="5">
                                    <div class="aipkit_pagination" id="aipkit_stats_user_pagination"></div>
                                </th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div id="aipkit_stats_user_no_results" class="aipkit_stats_user_empty" hidden>
                    <?php esc_html_e('No user credit data found.', 'gpt3-ai-content-generator'); ?>
                </div>
            </div>
        </div>
    </div>
</section>

<section
    class="aipkit_stats_tab_panel"
    id="aipkit_stats_billing_shortcode_panel"
    role="tabpanel"
    aria-labelledby="aipkit_stats_tab_woocommerce"
    data-aipkit-stats-panel="woocommerce"
    hidden
>
    <div class="aipkit_sub_container aipkit_stats_management_card aipkit_stats_shell">
        <div class="aipkit_sub_container_header aipkit_stats_panel_header">
            <div class="aipkit_stats_panel_intro">
                <div class="aipkit_sub_container_title"><?php esc_html_e('Customer access', 'gpt3-ai-content-generator'); ?></div>
                <p class="aipkit_stats_management_hint">
                    <?php esc_html_e('Configure the customer credit dashboard and choose which usage sections it shows.', 'gpt3-ai-content-generator'); ?>
                </p>
            </div>
        </div>
        <div class="aipkit_stats_management_body aipkit_stats_shell_body">
            <div class="aipkit_stats_shortcode_card">
                    <div class="aipkit_stats_shortcode_controls">
                        <button
                            type="button"
                            id="aipkit_stats_shortcode_snippet"
                            class="aipkit_stats_shortcode_snippet"
                            data-shortcode="[aipkit_token_usage]"
                            title="<?php echo esc_attr('[aipkit_token_usage]'); ?>"
                            aria-label="<?php esc_attr_e('Click to copy shortcode', 'gpt3-ai-content-generator'); ?>"
                        >
                            <span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
                            <span class="aipkit_stats_shortcode_text">[aipkit_token_usage]</span>
                        </button>
                    </div>
                    <div class="aipkit_stats_shortcode_customization">
                        <div class="aipkit_stats_shortcode_toggle_card">
                            <div class="aipkit_stats_shortcode_toggles">
                                <label class="aipkit_stats_shortcode_toggle_row">
                                    <span class="aipkit_stats_shortcode_toggle_label"><?php esc_html_e('Show buy credits button', 'gpt3-ai-content-generator'); ?></span>
                                    <span class="aipkit_switch aipkit_stats_shortcode_switch">
                                        <input type="checkbox" name="cfg_show_buycredits" class="aipkit_toggle_switch aipkit_stats_shortcode_option" value="1" checked>
                                        <span class="aipkit_switch_slider" aria-hidden="true"></span>
                                    </span>
                                </label>
                                <label class="aipkit_stats_shortcode_toggle_row">
                                    <span class="aipkit_stats_shortcode_toggle_label"><?php esc_html_e('Show purchase history', 'gpt3-ai-content-generator'); ?></span>
                                    <span class="aipkit_switch aipkit_stats_shortcode_switch">
                                        <input type="checkbox" name="cfg_show_purchasehistory" class="aipkit_toggle_switch aipkit_stats_shortcode_option" value="1" checked>
                                        <span class="aipkit_switch_slider" aria-hidden="true"></span>
                                    </span>
                                </label>
                                <label class="aipkit_stats_shortcode_toggle_row">
                                    <span class="aipkit_stats_shortcode_toggle_label"><?php esc_html_e('Show chatbot usage', 'gpt3-ai-content-generator'); ?></span>
                                    <span class="aipkit_switch aipkit_stats_shortcode_switch">
                                        <input type="checkbox" name="cfg_show_chatbot" class="aipkit_toggle_switch aipkit_stats_shortcode_option" value="1" checked>
                                        <span class="aipkit_switch_slider" aria-hidden="true"></span>
                                    </span>
                                </label>
                                <label class="aipkit_stats_shortcode_toggle_row">
                                    <span class="aipkit_stats_shortcode_toggle_label"><?php esc_html_e('Show AI Forms usage', 'gpt3-ai-content-generator'); ?></span>
                                    <span class="aipkit_switch aipkit_stats_shortcode_switch">
                                        <input type="checkbox" name="cfg_show_aiforms" class="aipkit_toggle_switch aipkit_stats_shortcode_option" value="1" checked>
                                        <span class="aipkit_switch_slider" aria-hidden="true"></span>
                                    </span>
                                </label>
                                <label class="aipkit_stats_shortcode_toggle_row">
                                    <span class="aipkit_stats_shortcode_toggle_label"><?php esc_html_e('Show image generator usage', 'gpt3-ai-content-generator'); ?></span>
                                    <span class="aipkit_switch aipkit_stats_shortcode_switch">
                                        <input type="checkbox" name="cfg_show_imagegenerator" class="aipkit_toggle_switch aipkit_stats_shortcode_option" value="1" checked>
                                        <span class="aipkit_switch_slider" aria-hidden="true"></span>
                                    </span>
                                </label>
                            </div>
                        </div>
                        <div class="aipkit_stats_shortcode_fields_card">
                            <div class="aipkit_stats_shortcode_fields">
                                <label class="aipkit_stats_shortcode_field aipkit_stats_shortcode_field--full">
                                    <span class="aipkit_stats_shortcode_field_label"><?php esc_html_e('Intro text', 'gpt3-ai-content-generator'); ?></span>
                                    <input
                                        type="text"
                                        class="aipkit_form-input aipkit_stats_shortcode_text_option"
                                        name="cfg_dashboard_intro"
                                        value="<?php echo esc_attr__('View your credits, purchases, and quotas.', 'gpt3-ai-content-generator'); ?>"
                                        data-default-value="<?php echo esc_attr__('View your credits, purchases, and quotas.', 'gpt3-ai-content-generator'); ?>"
                                    >
                                </label>
                                <label class="aipkit_stats_shortcode_field">
                                    <span class="aipkit_stats_shortcode_field_label"><?php esc_html_e('Customer dashboard URL', 'gpt3-ai-content-generator'); ?></span>
                                    <input
                                        type="url"
                                        class="aipkit_form-input aipkit_stats_shortcode_text_option"
                                        name="cfg_dashboard_url"
                                        value="<?php echo esc_attr($customer_dashboard_page_url); ?>"
                                        data-default-value="<?php echo esc_attr($customer_dashboard_page_url); ?>"
                                        placeholder="<?php esc_attr_e('https://example.com/customer-dashboard', 'gpt3-ai-content-generator'); ?>"
                                    >
                                </label>
                                <label class="aipkit_stats_shortcode_field">
                                    <span class="aipkit_stats_shortcode_field_label"><?php esc_html_e('Default buy credits URL', 'gpt3-ai-content-generator'); ?></span>
                                    <input
                                        type="url"
                                        class="aipkit_form-input aipkit_stats_shortcode_text_option"
                                        name="cfg_buycredits_url"
                                        value="<?php echo esc_attr($customer_dashboard_buycredits_saved_url); ?>"
                                        data-default-value="<?php echo esc_attr($customer_dashboard_buycredits_saved_url); ?>"
                                        placeholder="<?php echo esc_attr($customer_dashboard_buycredits_default_url); ?>"
                                    >
                                </label>
                                <label class="aipkit_stats_shortcode_field">
                                    <span class="aipkit_stats_shortcode_field_label"><?php esc_html_e('Dashboard title', 'gpt3-ai-content-generator'); ?></span>
                                    <input
                                        type="text"
                                        class="aipkit_form-input aipkit_stats_shortcode_text_option"
                                        name="cfg_dashboard_title"
                                        value="<?php echo esc_attr__('Credits & usage', 'gpt3-ai-content-generator'); ?>"
                                        data-default-value="<?php echo esc_attr__('Credits & usage', 'gpt3-ai-content-generator'); ?>"
                                    >
                                </label>
                                <label class="aipkit_stats_shortcode_field">
                                    <span class="aipkit_stats_shortcode_field_label"><?php esc_html_e('Buy credits button label', 'gpt3-ai-content-generator'); ?></span>
                                    <input
                                        type="text"
                                        class="aipkit_form-input aipkit_stats_shortcode_text_option"
                                        name="cfg_buycredits_label"
                                        value="<?php echo esc_attr__('Buy credits', 'gpt3-ai-content-generator'); ?>"
                                        data-default-value="<?php echo esc_attr__('Buy credits', 'gpt3-ai-content-generator'); ?>"
                                    >
                                </label>
                            </div>
                        </div>
                    </div>
            </div>
        </div>
    </div>

    <div class="aipkit_sub_container aipkit_stats_management_card aipkit_stats_shell aipkit_stats_customer_woo_shell">
        <div class="aipkit_sub_container_header aipkit_stats_panel_header">
            <div class="aipkit_stats_panel_intro">
                <div class="aipkit_sub_container_title"><?php esc_html_e('Sell visitor credits with WooCommerce', 'gpt3-ai-content-generator'); ?></div>
                <p class="aipkit_stats_management_hint">
                    <?php esc_html_e('Create credit products in WooCommerce and use the AI Puffer box on the product editor to define how many credits each package grants.', 'gpt3-ai-content-generator'); ?>
                </p>
            </div>
        </div>
        <div class="aipkit_stats_management_body aipkit_stats_shell_body">

                    <div class="aipkit_stats_customer_woo_status">
                        <span class="aipkit_stats_customer_woo_badge <?php echo $woo_active ? 'is-active' : 'is-inactive'; ?>">
                            <?php echo $woo_active ? esc_html__('WooCommerce active', 'gpt3-ai-content-generator') : esc_html__('WooCommerce not active', 'gpt3-ai-content-generator'); ?>
                        </span>
                        <span class="aipkit_stats_customer_woo_status_text">
                            <?php
                            if (!$woo_active) {
                                esc_html_e('Activate WooCommerce to start selling visitor credit packages.', 'gpt3-ai-content-generator');
                            } elseif ($can_manage_woo_products) {
                                esc_html_e('Open any WooCommerce product to find the visitor credits box.', 'gpt3-ai-content-generator');
                            } else {
                                esc_html_e('Your role can review credit package summaries, but cannot edit WooCommerce products.', 'gpt3-ai-content-generator');
                            }
                            ?>
                        </span>
                    </div>

                    <div class="aipkit_stats_customer_woo_actions">
                        <?php if ($woo_active && $can_manage_woo_products): ?>
                            <a class="aipkit_btn aipkit_btn-primary" href="<?php echo esc_url($woo_create_product_url); ?>">
                                <?php esc_html_e('Create credit product', 'gpt3-ai-content-generator'); ?>
                            </a>
                            <a class="aipkit_btn aipkit_btn-secondary" href="<?php echo esc_url($woo_products_url); ?>">
                                <?php esc_html_e('View products', 'gpt3-ai-content-generator'); ?>
                            </a>
                        <?php elseif (!$woo_active): ?>
                            <a class="aipkit_btn aipkit_btn-secondary" href="<?php echo esc_url(admin_url('plugins.php')); ?>">
                                <?php esc_html_e('Manage plugins', 'gpt3-ai-content-generator'); ?>
                            </a>
                        <?php else: ?>
                            <span class="aipkit_stats_pricing_rule_meta"><?php esc_html_e('No WooCommerce product access', 'gpt3-ai-content-generator'); ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($woo_active): ?>
                        <div class="aipkit_stats_customer_woo_info">
                            <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                            <span><?php esc_html_e('Credit packages add balance to the user account. Module quotas still apply separately.', 'gpt3-ai-content-generator'); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($woo_active && !empty($woo_credit_products)): ?>
                        <div class="aipkit_data-table aipkit_stats_customer_woo_table">
                            <table class="aipkit_data-table__table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Product', 'gpt3-ai-content-generator'); ?></th>
                                        <th><?php esc_html_e('Credits', 'gpt3-ai-content-generator'); ?></th>
                                        <th><?php esc_html_e('Price', 'gpt3-ai-content-generator'); ?></th>
                                        <th><?php esc_html_e('Status', 'gpt3-ai-content-generator'); ?></th>
                                        <th><?php esc_html_e('Actions', 'gpt3-ai-content-generator'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($woo_credit_products as $woo_credit_product): ?>
                                        <?php
                                        $woo_product_status_key = sanitize_key((string) ($woo_credit_product['status_key'] ?? 'unknown'));
                                        $woo_product_status_class = 'publish' === $woo_product_status_key
                                            ? 'is-enabled'
                                            : ('pending' === $woo_product_status_key ? 'is-pending' : 'is-disabled');
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="aipkit_stats_pricing_rule_title"><?php echo esc_html($woo_credit_product['title']); ?></div>
                                                <div class="aipkit_stats_pricing_rule_meta">#<?php echo esc_html((string) $woo_credit_product['id']); ?></div>
                                            </td>
                                            <td><?php echo esc_html(number_format_i18n((int) $woo_credit_product['credits'])); ?></td>
                                            <td><?php echo wp_kses_post($woo_credit_product['price']); ?></td>
                                            <td>
                                                <span class="aipkit_stats_status_pill <?php echo esc_attr($woo_product_status_class); ?>">
                                                    <?php echo esc_html($woo_credit_product['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="aipkit_stats_pricing_actions_inline">
                                                    <?php if (!empty($woo_credit_product['edit_url'])): ?>
                                                        <a
                                                            class="aipkit_stats_icon_action"
                                                            href="<?php echo esc_url($woo_credit_product['edit_url']); ?>"
                                                            aria-label="<?php esc_attr_e('Edit credit product', 'gpt3-ai-content-generator'); ?>"
                                                            title="<?php esc_attr_e('Edit', 'gpt3-ai-content-generator'); ?>"
                                                        >
                                                            <span class="dashicons dashicons-edit" aria-hidden="true"></span>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="aipkit_stats_pricing_rule_meta"><?php esc_html_e('No edit access', 'gpt3-ai-content-generator'); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="aipkit_stats_customer_woo_count">
                            <?php
                            $woo_credit_product_count = count($woo_credit_products);
                            printf(
                                esc_html(
                                    /* translators: %s: number of WooCommerce credit products */
                                    _n(
                                        '%s credit product found',
                                        '%s credit products found',
                                        $woo_credit_product_count,
                                        'gpt3-ai-content-generator'
                                    )
                                ),
                                esc_html(number_format_i18n($woo_credit_product_count))
                            );
                            ?>
                        </div>
                    <?php elseif ($woo_active): ?>
                        <div class="aipkit_stats_customer_woo_empty">
                            <?php esc_html_e('No visitor credit products yet. Create a WooCommerce product and enable its visitor credits box.', 'gpt3-ai-content-generator'); ?>
                        </div>
                    <?php endif; ?>
        </div>
    </div>
</section>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <div class="aipkit-modal-overlay aipkit_stats_retention_modal" id="aipkit_stats_retention_modal" aria-hidden="true">
        <div class="aipkit-modal-content aipkit-modal-shell aipkit-modal-shell--compact aipkit_usage_retention" role="dialog" aria-modal="true" aria-labelledby="aipkit_stats_retention_modal_title" aria-describedby="aipkit_stats_retention_modal_description">
            <div class="aipkit-modal-header aipkit-modal-shell-header">
                <div class="aipkit-modal-shell-intro">
                    <h2 class="aipkit-modal-shell-title" id="aipkit_stats_retention_modal_title"><?php esc_html_e('Log retention', 'gpt3-ai-content-generator'); ?></h2>
                    <p class="aipkit-modal-shell-copy" id="aipkit_stats_retention_modal_description"><?php echo $retention_is_paid
                        ? esc_html__('Choose how long conversation and task logs stay in your database.', 'gpt3-ai-content-generator')
                        : esc_html__('Conversation and task logs stay in your database until you delete them.', 'gpt3-ai-content-generator'); ?></p>
                </div>
                <button type="button" class="aipkit-modal-close-btn aipkit-modal-shell-close" id="aipkit_stats_retention_modal_close" aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>">
                    <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                </button>
            </div>
            <?php if ($retention_is_paid) :
                include $retention_paid_view;
            else :
$aipkit_retention_upgrade_url = admin_url('admin.php?page=wpaicg-pricing');
?>
<div class="aipkit-modal-body aipkit-modal-shell-body aipkit_usage_retention_body">
    <div class="aipkit_usage_retention_storage">
        <span class="dashicons dashicons-database" aria-hidden="true"></span>
        <div>
            <strong data-aipkit-log-storage="summary"><?php esc_html_e('Checking saved logs…', 'gpt3-ai-content-generator'); ?></strong>
            <span data-aipkit-log-storage="detail" hidden></span>
        </div>
    </div>

    <div class="aipkit_usage_retention_promo">
        <div class="aipkit_usage_retention_promo_head">
            <span class="dashicons dashicons-backup" aria-hidden="true"></span>
            <strong><?php esc_html_e('Delete old logs automatically', 'gpt3-ai-content-generator'); ?></strong>
            <span class="aipkit_usage_retention_badge"><?php esc_html_e('Pro', 'gpt3-ai-content-generator'); ?></span>
        </div>
        <p><?php esc_html_e('Keep your database small and keep visitor data only as long as you need it.', 'gpt3-ai-content-generator'); ?></p>
        <ul>
            <li><?php esc_html_e('Keep logs for 1 day up to 1 year', 'gpt3-ai-content-generator'); ?></li>
            <li><?php esc_html_e('Cleanup runs daily in the background', 'gpt3-ai-content-generator'); ?></li>
            <li><?php esc_html_e('See the next and last cleanup here', 'gpt3-ai-content-generator'); ?></li>
        </ul>
        <a class="aipkit_usage_btn aipkit_usage_btn--primary" href="<?php echo esc_url($aipkit_retention_upgrade_url); ?>" data-aipkit-retention-focus><?php esc_html_e('Upgrade to Pro', 'gpt3-ai-content-generator'); ?></a>
    </div>

    <p class="aipkit_usage_retention_note">
        <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
        <?php esc_html_e('You can still delete logs yourself: ⋯ menu → Delete filtered logs.', 'gpt3-ai-content-generator'); ?>
    </p>
</div>
<div class="aipkit_usage_retention_foot">
    <button type="button" class="aipkit_usage_btn" data-aipkit-retention-close><?php esc_html_e('Close', 'gpt3-ai-content-generator'); ?></button>
</div>
            <?php endif; ?>
        </div>
    </div>
<?php if ($cloud_allowed) : ?>
    <div id="aipkit_settings_container" data-aipkit-cloud-only="true">
        <?php include WPAICG_PLUGIN_DIR . 'admin/views/settings/cloud-panel.php'; ?>
    </div>
<?php endif; ?>
</div>
