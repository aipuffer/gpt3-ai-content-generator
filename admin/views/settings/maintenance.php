<?php
/**
 * Partial: Other settings page
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="aipkit_form-group aipkit_settings_simple_row" id="aipkit_settings_backup_row">
    <div class="aipkit_form-label">
        <?php esc_html_e('Settings backup', 'gpt3-ai-content-generator'); ?>
        <span class="aipkit_form-label-helper"><?php esc_html_e('Back up global settings and API keys as JSON. Chatbots, knowledge base data, licenses and Cloud connections are not included. Keep backups private.', 'gpt3-ai-content-generator'); ?></span>
    </div>
    <div class="aipkit_settings_action_buttons">
        <button
            type="button"
            id="aipkit_settings_export_button"
            class="aipkit_btn aipkit_btn-secondary"
            data-aipkit-settings-action="export-backup"
        >
            <?php esc_html_e('Export JSON', 'gpt3-ai-content-generator'); ?>
        </button>
        <button
            type="button"
            id="aipkit_settings_import_trigger"
            class="aipkit_btn aipkit_btn-secondary"
            data-aipkit-settings-action="import-trigger"
        >
            <?php esc_html_e('Import JSON', 'gpt3-ai-content-generator'); ?>
        </button>
        <input
            type="file"
            id="aipkit_settings_import_file"
            class="aipkit_settings_hidden_file_input"
            accept=".json,application/json"
            hidden
        />
    </div>
</div>

<div class="aipkit_form-group aipkit_settings_simple_row" id="aipkit_settings_restore_point_row">
    <div class="aipkit_form-label">
        <?php esc_html_e('Restore point', 'gpt3-ai-content-generator'); ?>
        <span class="aipkit_form-label-helper"><?php esc_html_e('Save or roll back to a saved state.', 'gpt3-ai-content-generator'); ?></span>
    </div>
    <div class="aipkit_settings_action_buttons">
        <button
            type="button"
            id="aipkit_settings_create_restore_point"
            class="aipkit_btn aipkit_btn-secondary"
            data-aipkit-settings-action="create-restore-point"
        >
            <?php esc_html_e('Create restore point', 'gpt3-ai-content-generator'); ?>
        </button>
        <button
            type="button"
            id="aipkit_settings_restore_restore_point"
            class="aipkit_btn aipkit_btn-secondary"
            data-aipkit-settings-action="restore-restore-point"
        >
            <?php esc_html_e('Restore last point', 'gpt3-ai-content-generator'); ?>
        </button>
    </div>
</div>

<div class="aipkit_form-group aipkit_settings_simple_row" id="aipkit_settings_sync_all_row">
    <div class="aipkit_form-label">
        <?php esc_html_e('Sync models', 'gpt3-ai-content-generator'); ?>
        <span class="aipkit_form-label-helper"><?php esc_html_e('Refresh model catalogs for every connected provider.', 'gpt3-ai-content-generator'); ?></span>
    </div>
    <div class="aipkit_settings_action_buttons">
        <button
            type="button"
            id="aipkit_settings_sync_all_models"
            class="aipkit_btn aipkit_btn-secondary"
            data-aipkit-settings-action="sync-all-models"
        >
            <?php esc_html_e('Sync all models', 'gpt3-ai-content-generator'); ?>
        </button>
    </div>
</div>

<?php
$aipkit_pufferworks_products = [
    [
        'name'        => 'PufferSights',
        'description' => __('AI crawler traffic and llms.txt coverage.', 'gpt3-ai-content-generator'),
        'icon'        => 'puffersights.svg',
        'url'         => 'https://wordpress.org/plugins/puffersights-ai-crawler-insights/',
    ],
    [
        'name'        => 'PufferDesk',
        'description' => __('A desktop-like WordPress admin.', 'gpt3-ai-content-generator'),
        'icon'        => 'pufferdesk.svg',
        'url'         => 'https://wordpress.org/plugins/pufferdesk/',
    ],
    [
        'name'        => 'Pufferbay',
        'description' => __('Roadmaps, feedback, and changelogs.', 'gpt3-ai-content-generator'),
        'icon'        => 'pufferbay.svg',
        'url'         => 'https://wordpress.org/plugins/pufferbay/',
    ],
    [
        'name'        => 'PufferPDF',
        'description' => __('Turn PDFs into editable WordPress content.', 'gpt3-ai-content-generator'),
        'icon'        => 'pufferpdf.svg',
        'url'         => 'https://wordpress.org/plugins/pufferpdf/',
    ],
];
?>

<section class="aipkit_settings_product_promotions" aria-labelledby="aipkit_settings_product_promotions_title">
    <h3 class="aipkit_settings_product_promotions_title" id="aipkit_settings_product_promotions_title">
        <?php esc_html_e('More from PufferWorks', 'gpt3-ai-content-generator'); ?>
    </h3>

    <div class="aipkit_settings_product_list">
        <?php foreach ($aipkit_pufferworks_products as $aipkit_product) : ?>
            <article class="aipkit_settings_product">
                <img
                    class="aipkit_settings_product_logo"
                    src="<?php echo esc_url(WPAICG_PLUGIN_URL . 'admin/images/plugins/' . $aipkit_product['icon']); ?>"
                    alt=""
                    width="32"
                    height="32"
                />
                <p class="aipkit_settings_product_summary">
                    <strong class="aipkit_settings_product_name"><?php echo esc_html($aipkit_product['name']); ?></strong>
                    <span class="aipkit_settings_product_description">&mdash; <?php echo esc_html($aipkit_product['description']); ?></span>
                </p>
                <a
                    class="aipkit_settings_product_link"
                    href="<?php echo esc_url($aipkit_product['url']); ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label="<?php echo esc_attr(sprintf(
                        /* translators: %s: product name. */
                        __('Learn more about %s (opens in a new tab)', 'gpt3-ai-content-generator'),
                        $aipkit_product['name']
                    )); ?>"
                >
                    <span class="aipkit_settings_product_link_label"><?php esc_html_e('Learn more', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_settings_product_link_arrow" aria-hidden="true">↗</span>
                </a>
            </article>
        <?php endforeach; ?>
    </div>
</section>
