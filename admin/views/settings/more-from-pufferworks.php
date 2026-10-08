<?php
/**
 * Partial: PufferWorks recommendations, shown under Help.
 */
if (!defined('ABSPATH')) {
    exit;
}
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

<h4 class="aipkit_settings_group_title"><?php esc_html_e('More from PufferWorks', 'gpt3-ai-content-generator'); ?></h4>
<div class="aipkit_settings_list">
    <?php foreach ($aipkit_pufferworks_products as $aipkit_product) : ?>
        <a
            class="aipkit_settings_list_row"
            href="<?php echo esc_url($aipkit_product['url']); ?>"
            target="_blank"
            rel="noopener noreferrer"
            data-aipkit-pufferworks-product
        >
            <img
                class="aipkit_settings_product_logo"
                src="<?php echo esc_url(WPAICG_PLUGIN_URL . 'admin/images/plugins/' . $aipkit_product['icon']); ?>"
                alt=""
                width="32"
                height="32"
            />
            <span class="aipkit_settings_list_copy">
                <span class="aipkit_settings_list_title"><?php echo esc_html($aipkit_product['name']); ?></span>
                <span class="aipkit_settings_list_meta"><?php echo esc_html($aipkit_product['description']); ?></span>
            </span>
            <span class="aipkit_settings_list_chevron dashicons dashicons-external" aria-hidden="true"></span>
            <span class="screen-reader-text"><?php esc_html_e('(opens in a new tab)', 'gpt3-ai-content-generator'); ?></span>
        </a>
    <?php endforeach; ?>
</div>
