<?php
/**
 * Partial: Apps Settings Page
 */
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

$is_pro_plan = class_exists('\WPAICG\\aipkit_dashboard') && \WPAICG\aipkit_dashboard::is_pro_plan();
$upgrade_url = admin_url('admin.php?page=wpaicg-pricing');
$apps_logo_base_url = defined('WPAICG_PLUGIN_URL')
    ? WPAICG_PLUGIN_URL . 'admin/images/apps/'
    : '';
$supported_apps_for_upsell = [
    [
        'name' => __('Slack', 'gpt3-ai-content-generator'),
        'summary' => __('Alerts and team notifications.', 'gpt3-ai-content-generator'),
        'logo_url' => $apps_logo_base_url . 'slack.svg',
    ],
    [
        'name' => __('HubSpot', 'gpt3-ai-content-generator'),
        'summary' => __('Contacts and CRM handoff.', 'gpt3-ai-content-generator'),
        'logo_url' => $apps_logo_base_url . 'hubspot.svg',
    ],
    [
        'name' => __('Notion', 'gpt3-ai-content-generator'),
        'summary' => __('Pages and database items.', 'gpt3-ai-content-generator'),
        'logo_url' => $apps_logo_base_url . 'notion.svg',
    ],
    [
        'name' => __('Pipedrive', 'gpt3-ai-content-generator'),
        'summary' => __('People and pipeline-ready leads.', 'gpt3-ai-content-generator'),
        'logo_url' => $apps_logo_base_url . 'pipedrive.svg',
    ],
    [
        'name' => __('Zapier', 'gpt3-ai-content-generator'),
        'summary' => __('Webhook-based automation flows.', 'gpt3-ai-content-generator'),
        'logo_url' => $apps_logo_base_url . 'zapier.svg',
    ],
    [
        'name' => __('Make', 'gpt3-ai-content-generator'),
        'summary' => __('Scenarios triggered by events.', 'gpt3-ai-content-generator'),
        'logo_url' => $apps_logo_base_url . 'make.svg',
    ],
    [
        'name' => __('n8n', 'gpt3-ai-content-generator'),
        'summary' => __('Webhook-based automation workflows.', 'gpt3-ai-content-generator'),
        'logo_url' => $apps_logo_base_url . 'n8n.svg',
    ],
];
$supported_modules_for_upsell = [
    __('AI Forms', 'gpt3-ai-content-generator'),
    __('Content Writer', 'gpt3-ai-content-generator'),
    __('Task Automation', 'gpt3-ai-content-generator'),
    __('Image Generator', 'gpt3-ai-content-generator'),
    __('Knowledge Base', 'gpt3-ai-content-generator'),
    __('Chatbot', 'gpt3-ai-content-generator'),
];

if (!$is_pro_plan) :
    ?>
    <section id="aipkit_settings_apps_upsell_section">
        <div class="aipkit_settings_apps_upsell_main">
            <div class="aipkit_settings_apps_upsell_promo">
                <div class="aipkit_settings_apps_upsell_intro">
                    <span class="aipkit_settings_apps_upsell_icon" aria-hidden="true">
                        <span class="dashicons dashicons-admin-plugins"></span>
                        <span class="aipkit_settings_apps_upsell_lock">
                            <span class="dashicons dashicons-lock"></span>
                        </span>
                    </span>
                    <div class="aipkit_settings_apps_upsell_intro_copy">
                        <strong><?php esc_html_e('Connect apps and automate real workflows.', 'gpt3-ai-content-generator'); ?></strong>
                        <p class="aipkit_settings_apps_upsell_text">
                            <?php esc_html_e('Turn AI Puffer events into connected app actions with reusable connections and recipe templates.', 'gpt3-ai-content-generator'); ?>
                        </p>
                    </div>
                </div>
                <div class="aipkit_settings_apps_upsell_app_grid" aria-label="<?php esc_attr_e('Supported apps', 'gpt3-ai-content-generator'); ?>">
                    <?php foreach ($supported_apps_for_upsell as $supported_app) : ?>
                        <article class="aipkit_settings_apps_upsell_app_card">
                            <div class="aipkit_settings_apps_upsell_app_logo_tile">
                                <img
                                    class="aipkit_settings_apps_upsell_app_logo"
                                    src="<?php echo esc_url($supported_app['logo_url']); ?>"
                                    alt="<?php echo esc_attr($supported_app['name']); ?>"
                                    loading="lazy"
                                    decoding="async"
                                />
                            </div>
                            <strong><?php echo esc_html($supported_app['name']); ?></strong>
                            <span><?php echo esc_html($supported_app['summary']); ?></span>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="aipkit_settings_apps_upsell_modules">
                    <strong><?php esc_html_e('Supported modules', 'gpt3-ai-content-generator'); ?></strong>
                    <p>
                        <?php echo esc_html(implode(', ', $supported_modules_for_upsell)); ?>.
                    </p>
                </div>
                <a
                    class="aipkit_pro_upgrade_button"
                    href="<?php echo esc_url($upgrade_url); ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    <?php esc_html_e('Upgrade', 'gpt3-ai-content-generator'); ?>
                </a>
            </div>
        </div>
    </section>
    <?php
    return;
endif;

?>
<input type="hidden" name="native_app_recipes[_ui_present]" value="1" />
<?php

foreach (['app-connections', 'app-recipes'] as $aipkit_apps_view_name) {
    $aipkit_apps_view = WPAICG_PLUGIN_DIR . 'lib/views/settings/' . $aipkit_apps_view_name . '.php';
    if (file_exists($aipkit_apps_view)) {
        include $aipkit_apps_view;
    }
}

$aipkit_delivery_issues_view = WPAICG_PLUGIN_DIR . 'lib/views/settings/app-delivery-issues.php';
if (file_exists($aipkit_delivery_issues_view)) {
    require_once $aipkit_delivery_issues_view;
    \WPAICG\Lib\Views\Settings\AIPKit_App_Delivery_Issues_View::render();
}
