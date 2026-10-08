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
$supported_apps_for_upsell = ['slack', 'hubspot', 'notion', 'zapier'];

if (!$is_pro_plan) :
    // The same square marks the Pro app list shows, each on its own tile.
    $render_app_mark = require __DIR__ . '/app-mark.php';
    ?>
    <section id="aipkit_settings_apps_upsell_section" class="aipkit_settings_list">
        <div class="aipkit_settings_list_row">
            <span class="aipkit_settings_apps_upsell_logos" aria-hidden="true">
                <?php foreach ($supported_apps_for_upsell as $supported_app_slug) : ?>
                    <span class="aipkit_settings_apps_upsell_logo"><?php $render_app_mark($supported_app_slug); ?></span>
                <?php endforeach; ?>
            </span>
            <span class="aipkit_settings_list_copy">
                <span class="aipkit_settings_list_title">
                    <?php esc_html_e('Send to your apps', 'gpt3-ai-content-generator'); ?>
                    <span class="aipkit_pro_badge"><?php esc_html_e('Pro', 'gpt3-ai-content-generator'); ?></span>
                </span>
                <span class="aipkit_settings_list_meta"><?php esc_html_e('When a chat, form or post is ready, send it to Slack, HubSpot, Notion, Pipedrive, Zapier, Make or n8n.', 'gpt3-ai-content-generator'); ?></span>
            </span>
            <a
                class="aipkit_pro_upgrade_button"
                href="<?php echo esc_url($upgrade_url); ?>"
                target="_blank"
                rel="noopener noreferrer"
            ><?php esc_html_e('Upgrade', 'gpt3-ai-content-generator'); ?></a>
        </div>
    </section>
    <?php
    return;
endif;

?>
<input type="hidden" name="native_app_recipes[_ui_present]" value="1" />
<?php

$aipkit_apps_view = WPAICG_PLUGIN_DIR . 'lib/views/settings/app-list.php';
if (file_exists($aipkit_apps_view)) {
    include $aipkit_apps_view;
}
