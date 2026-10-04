<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

$stats_default_days = 7;
$log_settings = get_option('aipkit_log_settings', [
    'enable_pruning' => false,
    'retention_period_days' => 90,
]);
$log_settings_enabled = !empty($log_settings['enable_pruning']);
$log_settings_retention = isset($log_settings['retention_period_days'])
    ? (int) $log_settings['retention_period_days']
    : 90;
$user_credits_nonce = wp_create_nonce('aipkit_user_credits_nonce');
$visitor_billing_enabled = \WPAICG\Stats\AIPKit_Stats::visitor_billing_enabled();
$cloud_connected = class_exists('\\WPAICG\\Cloud\\Connection')
    && \WPAICG\Cloud\Connection::allowed()
    && \WPAICG\Cloud\Connection::display()['connected'];
$retention_options = class_exists('\\WPAICG\\Chat\\Utils\\LogConfig')
    ? \WPAICG\Chat\Utils\LogConfig::get_retention_periods()
    : [
        7 => __('7 days', 'gpt3-ai-content-generator'),
        15 => __('15 days', 'gpt3-ai-content-generator'),
        30 => __('30 days', 'gpt3-ai-content-generator'),
        60 => __('60 days', 'gpt3-ai-content-generator'),
        90 => __('90 days', 'gpt3-ai-content-generator'),
    ];
$is_pro = class_exists('\\WPAICG\\aipkit_dashboard')
    ? \WPAICG\aipkit_dashboard::is_pro_plan()
    : false;
$upgrade_url = admin_url('admin.php?page=wpaicg-pricing');
$cron_hook = class_exists('\\WPAICG\\Chat\\Storage\\LogCronManager')
    ? \WPAICG\Chat\Storage\LogCronManager::HOOK_NAME
    : 'aipkit_prune_logs_cron';
$next_scheduled = wp_next_scheduled($cron_hook);
$is_cron_active = $next_scheduled !== false;
$last_run_option = get_option('aipkit_log_pruning_last_run', '');
$last_run_text = $last_run_option
    ? sprintf(
        /* translators: %s: last log-pruning run time. */
        __('Last run %s', 'gpt3-ai-content-generator'),
        wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_run_option))
    )
    : __('No cleanup has run yet', 'gpt3-ai-content-generator');
$cron_state = 'disabled';
$cron_text = __('Disabled', 'gpt3-ai-content-generator');
if ($log_settings_enabled) {
    if ($is_cron_active) {
        $cron_state = 'scheduled';
        $cron_text = (int) $next_scheduled <= time()
            ? __('Next run is due', 'gpt3-ai-content-generator')
            : sprintf(
                /* translators: %s: time until the next log-pruning run. */
                __('Next run in %s', 'gpt3-ai-content-generator'),
                human_time_diff(time(), (int) $next_scheduled)
            );
    } else {
        $cron_state = 'pending';
        $cron_text = __('Scheduling next run…', 'gpt3-ai-content-generator');
    }
}

$chatbot_posts = [];
if (class_exists('\\WPAICG\\Chat\\Admin\\AdminSetup')) {
    $chatbot_posts = get_posts([
        'post_type' => \WPAICG\Chat\Admin\AdminSetup::POST_TYPE,
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    ]);
}

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

$module_options = [];
if (class_exists('\\WPAICG\\Stats\\AIPKit_Stats')) {
    $stats_calculator = new \WPAICG\Stats\AIPKit_Stats();
    $quick_stats = $stats_calculator->get_quick_interaction_stats($stats_default_days);
    if (!is_wp_error($quick_stats) && !empty($quick_stats['module_counts'])) {
        $module_options = array_keys($quick_stats['module_counts']);
    }
}
if (!in_array('chatbot', $module_options, true)) {
    $module_options[] = 'chatbot';
}
$module_options = array_values(array_unique(array_filter($module_options)));
sort($module_options);

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

<div
    class="aipkit_container aipkit_stats_container aipkit_admin_ui"
    id="aipkit_stats_container"
    data-default-days="<?php echo esc_attr($stats_default_days); ?>"
    data-is-pro="<?php echo esc_attr($is_pro ? '1' : '0'); ?>"
    data-module-labels="<?php echo esc_attr(wp_json_encode($module_labels)); ?>"
    data-user-credits-nonce="<?php echo esc_attr($user_credits_nonce); ?>"
>
    <div class="aipkit_stats_page_header">
        <div class="aipkit_container-header">
            <div class="aipkit_container-header-left">
                <div class="aipkit_stats_header_copy">
                    <div class="aipkit_stats_header_title_row">
                        <div class="aipkit_container-title"><?php esc_html_e('Usage', 'gpt3-ai-content-generator'); ?></div>
                        <span id="aipkit_stats_status" class="aipkit_training_status aipkit_global_status_area" aria-live="polite"></span>
                    </div>
                    <p class="aipkit_stats_header_hint"><?php echo $cloud_connected
                        ? esc_html__('Manage your AI Puffer credits and review activity on this site.', 'gpt3-ai-content-generator')
                        : esc_html__('Review conversations and recorded AI requests on this site.', 'gpt3-ai-content-generator'); ?></p>
                </div>
            </div>
        </div>
        <div class="aipkit_stats_tabs" role="tablist" aria-label="<?php esc_attr_e('Usage sections', 'gpt3-ai-content-generator'); ?>">
            <button
                type="button"
                class="aipkit_stats_tab aipkit_active"
                id="aipkit_stats_tab_logs"
                role="tab"
                aria-selected="true"
                aria-controls="aipkit_stats_logs_panel"
                data-aipkit-stats-section="logs"
            >
                <span class="dashicons dashicons-chart-area" aria-hidden="true"></span>
                <?php esc_html_e('Overview', 'gpt3-ai-content-generator'); ?>
            </button>
            <button
                type="button"
                class="aipkit_stats_tab"
                id="aipkit_stats_tab_requests"
                role="tab"
                aria-selected="false"
                aria-controls="aipkit_stats_requests_panel"
                data-aipkit-stats-section="requests"
                tabindex="-1"
            >
                <span class="dashicons dashicons-list-view" aria-hidden="true"></span>
                <?php esc_html_e('Requests', 'gpt3-ai-content-generator'); ?>
            </button>
            <?php if ($visitor_billing_enabled) : ?>
            <button
                type="button"
                class="aipkit_stats_tab"
                id="aipkit_stats_tab_visitor_billing"
                role="tab"
                aria-selected="false"
                aria-controls="aipkit_stats_visitor_billing_panel"
                data-aipkit-stats-section="billing"
                tabindex="-1"
            >
                <span class="dashicons dashicons-groups" aria-hidden="true"></span>
                <?php esc_html_e('Visitor billing', 'gpt3-ai-content-generator'); ?>
            </button>
            <?php endif; ?>
        </div>
    </div>
    <div class="aipkit_container-body">
        <div class="aipkit_stats_workspace">
            <section
                class="aipkit_stats_tab_panel"
                id="aipkit_stats_logs_panel"
                role="tabpanel"
                aria-labelledby="aipkit_stats_tab_logs"
                data-aipkit-stats-section-panel="logs"
            >
                <?php if ($cloud_connected) : ?>
                    <?php include __DIR__ . '/cloud-account.php'; ?>
                <?php endif; ?>
                <div class="aipkit_stats_layout">
                    <div class="aipkit_stats_logs_shell">
                        <div class="aipkit_stats_panel_header aipkit_stats_logs_header">
                            <div class="aipkit_stats_panel_intro">
                                <div class="aipkit_sub_container_title"><?php esc_html_e('Conversation logs', 'gpt3-ai-content-generator'); ?></div>
                            </div>
                            <div class="aipkit_stats_logs_header_actions">
                                <button
                                    type="button"
                                    class="aipkit_stats_filters_toggle"
                                    id="aipkit_stats_filters_toggle"
                                    aria-expanded="false"
                                    aria-controls="aipkit_stats_logs_toolbar"
                                >
                                    <span class="dashicons dashicons-filter" aria-hidden="true"></span>
                                    <?php esc_html_e('Filters', 'gpt3-ai-content-generator'); ?>
                                </button>
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
                                        <span class="screen-reader-text"><?php esc_html_e('Log actions', 'gpt3-ai-content-generator'); ?></span>
                                    </button>
                                    <div
                                        class="aipkit_stats_table_menu"
                                        id="aipkit_stats_table_menu"
                                        role="menu"
                                        aria-label="<?php esc_attr_e('Log actions', 'gpt3-ai-content-generator'); ?>"
                                        hidden
                                    >
                                        <button type="button" class="aipkit_stats_table_menu_item" id="aipkit_stats_retention_open" role="menuitem">
                                            <span class="aipkit_stats_table_menu_item_icon" aria-hidden="true">
                                                <span class="dashicons dashicons-backup"></span>
                                            </span>
                                            <span class="aipkit_stats_table_menu_item_label"><?php esc_html_e('Retention settings', 'gpt3-ai-content-generator'); ?></span>
                                        </button>
                                        <button type="button" class="aipkit_stats_table_menu_item" id="aipkit_stats_export_btn" role="menuitem">
                                            <span class="aipkit_stats_table_menu_item_icon" aria-hidden="true">
                                                <span class="dashicons dashicons-download"></span>
                                            </span>
                                            <span class="aipkit_stats_table_menu_item_label"><?php esc_html_e('Export logs', 'gpt3-ai-content-generator'); ?></span>
                                        </button>
                                        <button type="button" class="aipkit_stats_table_menu_item aipkit_stats_table_menu_item--danger" id="aipkit_stats_delete_all_btn" role="menuitem">
                                            <span class="aipkit_stats_table_menu_item_icon" aria-hidden="true">
                                                <span class="dashicons dashicons-trash"></span>
                                            </span>
                                            <span class="aipkit_stats_table_menu_item_label"><?php esc_html_e('Delete filtered logs', 'gpt3-ai-content-generator'); ?></span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="aipkit_stats_logs_toolbar" id="aipkit_stats_logs_toolbar" role="group" aria-label="<?php esc_attr_e('Log filters', 'gpt3-ai-content-generator'); ?>" hidden>
                            <label class="aipkit_stats_toolbar_search" for="aipkit_stats_search_input">
                                <span class="dashicons dashicons-search" aria-hidden="true"></span>
                                <span class="screen-reader-text"><?php esc_html_e('Search logs', 'gpt3-ai-content-generator'); ?></span>
                                <input
                                    type="search"
                                    id="aipkit_stats_search_input"
                                    class="aipkit_stats_search_input"
                                    placeholder="<?php esc_attr_e('Search conversations', 'gpt3-ai-content-generator'); ?>"
                                    autocomplete="off"
                                />
                            </label>
                            <label class="aipkit_stats_toolbar_field" for="aipkit_stats_bot_filter">
                                <span class="screen-reader-text"><?php esc_html_e('Chatbot', 'gpt3-ai-content-generator'); ?></span>
                                <select id="aipkit_stats_bot_filter" class="aipkit_popover_select" aria-label="<?php esc_attr_e('Filter by chatbot', 'gpt3-ai-content-generator'); ?>">
                                    <option value=""><?php esc_html_e('All bots', 'gpt3-ai-content-generator'); ?></option>
                                    <option value="0"><?php esc_html_e('No bot', 'gpt3-ai-content-generator'); ?></option>
                                    <?php foreach ($chatbot_posts as $chatbot_post): ?>
                                        <option value="<?php echo esc_attr($chatbot_post->ID); ?>">
                                            <?php echo esc_html($chatbot_post->post_title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="aipkit_stats_toolbar_field" for="aipkit_stats_module_filter">
                                <span class="screen-reader-text"><?php esc_html_e('Module', 'gpt3-ai-content-generator'); ?></span>
                                <select id="aipkit_stats_module_filter" class="aipkit_popover_select" aria-label="<?php esc_attr_e('Filter by module', 'gpt3-ai-content-generator'); ?>">
                                    <option value=""><?php esc_html_e('All modules', 'gpt3-ai-content-generator'); ?></option>
                                    <?php foreach ($module_options as $module_key): ?>
                                        <option value="<?php echo esc_attr($module_key); ?>">
                                            <?php echo esc_html($module_labels[$module_key] ?? ucfirst(str_replace('_', ' ', $module_key))); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="aipkit_stats_toolbar_field aipkit_stats_toolbar_field--days" for="aipkit_stats_logs_days_filter">
                                <span class="screen-reader-text"><?php esc_html_e('Date range', 'gpt3-ai-content-generator'); ?></span>
                                <select id="aipkit_stats_logs_days_filter" class="aipkit_popover_select" data-aipkit-stats-days-filter aria-label="<?php esc_attr_e('Filter by date range', 'gpt3-ai-content-generator'); ?>">
                                    <option value="7" <?php selected($stats_default_days, 7); ?>><?php esc_html_e('Last 7 days', 'gpt3-ai-content-generator'); ?></option>
                                    <option value="30" <?php selected($stats_default_days, 30); ?>><?php esc_html_e('Last 30 days', 'gpt3-ai-content-generator'); ?></option>
                                    <option value="90" <?php selected($stats_default_days, 90); ?>><?php esc_html_e('Last 90 days', 'gpt3-ai-content-generator'); ?></option>
                                </select>
                            </label>
                            <button type="button" class="aipkit_stats_filters_reset" id="aipkit_stats_filters_reset_btn">
                                <span class="dashicons dashicons-image-rotate" aria-hidden="true"></span>
                                <?php esc_html_e('Reset', 'gpt3-ai-content-generator'); ?>
                            </button>
                        </div>
                        <div class="aipkit_data-table aipkit_stats_table">
                            <table class="aipkit_data-table__table">
                                <colgroup>
                                    <col class="aipkit_stats_log_col_select" />
                                    <col class="aipkit_stats_log_col_time" />
                                    <col class="aipkit_stats_log_col_item" />
                                    <col class="aipkit_stats_log_col_tokens" />
                                </colgroup>
                                <thead>
                                    <tr id="aipkit_stats_logs_table_header">
                                        <th class="aipkit_stats_log_select_cell">
                                            <input
                                                type="checkbox"
                                                id="aipkit_stats_logs_select_all"
                                                aria-label="<?php esc_attr_e('Select all conversations on this page', 'gpt3-ai-content-generator'); ?>"
                                            />
                                        </th>
                                        <th><?php esc_html_e('Time', 'gpt3-ai-content-generator'); ?></th>
                                        <th><?php esc_html_e('Item', 'gpt3-ai-content-generator'); ?></th>
                                        <th class="aipkit_stats_log_tokens_cell"><?php esc_html_e('Tokens', 'gpt3-ai-content-generator'); ?></th>
                                    </tr>
                                    <tr id="aipkit_stats_logs_selection_header" class="aipkit_stats_logs_selection_header" hidden>
                                        <th colspan="4">
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
                                <tbody id="aipkit_stats_table_body">
                                    <tr>
                                        <td colspan="4" class="aipkit_stats_table_placeholder">
                                            <?php esc_html_e('Loading logs...', 'gpt3-ai-content-generator'); ?>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div id="aipkit_stats_pagination" class="aipkit_logs_pagination_container aipkit_data-table-footer"></div>
                    </div>

                    <div class="aipkit_stats_detail_shell">
                        <div class="aipkit_stats_panel_header aipkit_stats_detail_shell_header">
                            <div class="aipkit_stats_panel_intro">
                                <div class="aipkit_sub_container_title"><?php esc_html_e('Conversation details', 'gpt3-ai-content-generator'); ?></div>
                                <div id="aipkit_stats_detail_identity" class="aipkit_stats_detail_identity" hidden></div>
                            </div>
                        </div>
                        <div class="aipkit_stats_detail_panel" id="aipkit_stats_detail_panel">
                            <div class="aipkit_stats_detail_empty">
                                <?php esc_html_e('Select a conversation to view details.', 'gpt3-ai-content-generator'); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section
                class="aipkit_stats_tab_panel"
                id="aipkit_stats_requests_panel"
                role="tabpanel"
                aria-labelledby="aipkit_stats_tab_requests"
                data-aipkit-stats-section-panel="requests"
                hidden
            >
                <div class="aipkit_sub_container aipkit_stats_management_card aipkit_stats_shell">
                    <div class="aipkit_sub_container_header aipkit_stats_panel_header">
                        <div class="aipkit_stats_panel_intro">
                            <div class="aipkit_sub_container_title"><?php esc_html_e('Recent requests', 'gpt3-ai-content-generator'); ?></div>
                            <p class="aipkit_stats_management_hint"><?php esc_html_e('Review recent AI requests across providers.', 'gpt3-ai-content-generator'); ?></p>
                        </div>
                        <div class="aipkit_stats_section_actions">
                            <label class="screen-reader-text" for="aipkit_stats_requests_days"><?php esc_html_e('Request date range', 'gpt3-ai-content-generator'); ?></label>
                            <select id="aipkit_stats_requests_days" class="aipkit_popover_select">
                                <option value="7" <?php selected($stats_default_days, 7); ?>><?php esc_html_e('Last 7 days', 'gpt3-ai-content-generator'); ?></option>
                                <option value="30" <?php selected($stats_default_days, 30); ?>><?php esc_html_e('Last 30 days', 'gpt3-ai-content-generator'); ?></option>
                                <option value="90" <?php selected($stats_default_days, 90); ?>><?php esc_html_e('Last 90 days', 'gpt3-ai-content-generator'); ?></option>
                                <option value="0"><?php esc_html_e('All time', 'gpt3-ai-content-generator'); ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="aipkit_stats_management_body aipkit_stats_shell_body">
                        <div class="aipkit_data-table aipkit_stats_ledger_table">
                            <table class="aipkit_data-table__table">
                                <thead><tr id="aipkit_stats_requests_header">
                                    <th class="aipkit_stats_log_select_cell"><input type="checkbox" class="aipkit_stats_log_checkbox" id="aipkit_stats_requests_select_all" aria-label="<?php esc_attr_e('Select all requests on this page', 'gpt3-ai-content-generator'); ?>" /></th>
                                    <th><?php esc_html_e('Time', 'gpt3-ai-content-generator'); ?></th>
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
                                <tbody id="aipkit_stats_requests_body"><tr><td colspan="5" class="aipkit_stats_table_placeholder"><?php esc_html_e('Loading requests...', 'gpt3-ai-content-generator'); ?></td></tr></tbody>
                            </table>
                        </div>
                        <div id="aipkit_stats_requests_pagination" class="aipkit_logs_pagination_container aipkit_data-table-footer"></div>
                    </div>
                </div>
            </section>

            <div
                id="aipkit_stats_visitor_billing_panel"
                class="aipkit_stats_billing_panels"
                role="tabpanel"
                aria-labelledby="aipkit_stats_tab_visitor_billing"
                data-aipkit-stats-section-panel="billing"
                hidden
            >
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
                                <select id="aipkit_stats_ledger_days_filter" class="aipkit_popover_select" data-aipkit-stats-days-filter>
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
    <div
        class="aipkit-modal-overlay aipkit_stats_retention_modal"
        id="aipkit_stats_retention_modal"
        aria-hidden="true"
    >
        <div class="aipkit-modal-content aipkit-modal-shell aipkit-modal-shell--compact aipkit_stats_retention_modal_content" role="dialog" aria-modal="true" aria-labelledby="aipkit_stats_retention_modal_title" aria-describedby="aipkit_stats_retention_modal_description">
            <div class="aipkit-modal-header aipkit-modal-shell-header">
                <div class="aipkit-modal-shell-intro">
                    <h2 class="aipkit-modal-shell-title" id="aipkit_stats_retention_modal_title"><?php esc_html_e('Log retention', 'gpt3-ai-content-generator'); ?></h2>
                    <p class="aipkit-modal-shell-copy" id="aipkit_stats_retention_modal_description"><?php esc_html_e('Control how long saved conversation logs stay in your database.', 'gpt3-ai-content-generator'); ?></p>
                </div>
                <button type="button" class="aipkit-modal-close-btn aipkit-modal-shell-close" id="aipkit_stats_retention_modal_close" aria-label="<?php esc_attr_e('Close', 'gpt3-ai-content-generator'); ?>">
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </div>
            <div class="aipkit-modal-body aipkit-modal-shell-body aipkit_stats_retention_modal_body">
                <div class="aipkit_stats_retention_setting">
                    <div class="aipkit_stats_retention_setting_copy">
                        <span class="aipkit_stats_retention_setting_label"><?php esc_html_e('Auto-delete logs', 'gpt3-ai-content-generator'); ?></span>
                        <p class="aipkit_stats_retention_setting_hint"><?php esc_html_e('Run pruning automatically in the background.', 'gpt3-ai-content-generator'); ?></p>
                    </div>
                    <div class="aipkit_stats_retention_setting_control aipkit_stats_retention_setting_control--toggle">
                        <?php if ($is_pro): ?>
                            <label class="aipkit_switch aipkit_stats_retention_switch" for="aipkit_stats_auto_delete_toggle">
                                <input
                                    type="checkbox"
                                    class="aipkit_toggle_switch"
                                    id="aipkit_stats_auto_delete_toggle"
                                    <?php checked($log_settings_enabled); ?>
                                />
                                <span class="aipkit_switch_slider" aria-hidden="true"></span>
                            </label>
                        <?php endif; ?>
                        <?php if (!$is_pro): ?>
                            <a class="aipkit_btn aipkit_btn-primary aipkit_pro_upgrade_button" href="<?php echo esc_url($upgrade_url); ?>">
                                <?php esc_html_e('Upgrade', 'gpt3-ai-content-generator'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div
                    class="aipkit_stats_retention_setting aipkit_stats_retention_row"
                    data-aipkit-stats-retention-row
                    <?php echo $log_settings_enabled ? '' : 'hidden'; ?>
                >
                    <div class="aipkit_stats_retention_setting_copy">
                        <label class="aipkit_stats_retention_setting_label" for="aipkit_stats_retention_days">
                            <?php esc_html_e('Delete logs older than', 'gpt3-ai-content-generator'); ?>
                        </label>
                        <p class="aipkit_stats_retention_setting_hint"><?php esc_html_e('Older logs are removed after this period.', 'gpt3-ai-content-generator'); ?></p>
                    </div>
                    <div class="aipkit_stats_retention_setting_control">
                        <select
                            id="aipkit_stats_retention_days"
                            class="aipkit_popover_select aipkit_stats_retention_select"
                            <?php disabled(!$is_pro || !$log_settings_enabled); ?>
                        >
                            <?php foreach ($retention_options as $retention_value => $retention_label): ?>
                                <option value="<?php echo esc_attr($retention_value); ?>" <?php selected($log_settings_retention, $retention_value); ?>>
                                    <?php echo esc_html($retention_label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div
                    class="aipkit_stats_retention_info aipkit_stats_cron_row"
                    data-aipkit-stats-cron-row
                    <?php echo $log_settings_enabled ? '' : 'hidden'; ?>
                >
                    <div class="aipkit_stats_retention_info_copy">
                        <span class="aipkit_stats_retention_info_label"><?php esc_html_e('Pruning schedule', 'gpt3-ai-content-generator'); ?></span>
                        <p class="aipkit_stats_retention_info_hint"><?php esc_html_e('Cleanup runs daily while auto-delete is on.', 'gpt3-ai-content-generator'); ?></p>
                    </div>
                    <div class="aipkit_stats_retention_info_meta">
                        <div
                            class="aipkit_stats_cron_status"
                            id="aipkit_stats_cron_status"
                            data-state="<?php echo esc_attr($cron_state); ?>"
                        >
                            <span class="aipkit_stats_cron_dot" aria-hidden="true"></span>
                            <span class="aipkit_stats_cron_text" id="aipkit_stats_cron_text"><?php echo esc_html($cron_text); ?></span>
                        </div>
                        <span class="aipkit_stats_cron_last_run" id="aipkit_stats_cron_last_run">
                            <?php echo esc_html($last_run_text); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
