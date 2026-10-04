<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

$has_woocommerce = class_exists('WooCommerce') && post_type_exists('product');
$create_modes = [
    [
        'mode' => 'task',
        'icon' => 'dashicons-edit-page',
        'title' => __('Manual entry', 'gpt3-ai-content-generator'),
        'active' => true,
    ],
    [
        'mode' => 'csv',
        'icon' => 'dashicons-media-spreadsheet',
        'title' => __('CSV file', 'gpt3-ai-content-generator'),
        'active' => false,
    ],
    [
        'mode' => 'rss',
        'icon' => 'dashicons-rss',
        'title' => __('RSS feed', 'gpt3-ai-content-generator'),
        'active' => false,
    ],
    [
        'mode' => 'url',
        'icon' => 'dashicons-admin-links',
        'title' => __('Web page', 'gpt3-ai-content-generator'),
        'active' => false,
    ],
    [
        'mode' => 'gsheets',
        'icon' => 'dashicons-table-col-before',
        'title' => __('Google Sheets', 'gpt3-ai-content-generator'),
        'active' => false,
    ],
];

$optimize_modes = [
    [
        'mode' => 'existing-content',
        'icon' => 'dashicons-edit-large',
        'title' => __('Rewrite content', 'gpt3-ai-content-generator'),
        'active' => false,
    ],
    [
        'mode' => 'existing-images',
        'icon' => 'dashicons-format-image',
        'title' => __('Image alt text', 'gpt3-ai-content-generator'),
        'active' => false,
    ],
];

if ($has_woocommerce) {
    $optimize_modes[] = [
        'mode' => 'existing-products',
        'icon' => 'dashicons-cart',
        'title' => __('WooCommerce', 'gpt3-ai-content-generator'),
        'active' => false,
    ];
}
?>
<div class="aipkit_cw_source_selector_wrapper" data-template-ready="0">
    <div class="aipkit_cw_mode_section" aria-labelledby="aipkit_cw_mode_section_create">
        <div class="aipkit_cw_mode_section_heading" id="aipkit_cw_mode_section_create">
            <?php esc_html_e('Create from', 'gpt3-ai-content-generator'); ?>
        </div>
        <div class="aipkit_cw_mode_group_list aipkit_cw_mode_group_list--workflow" role="list" aria-labelledby="aipkit_cw_mode_section_create">
            <?php foreach ($create_modes as $item) : ?>
                <button
                    type="button"
                    class="aipkit_cw_mode_card<?php echo !empty($item['active']) ? ' is-active' : ''; ?>"
                    data-mode="<?php echo esc_attr($item['mode']); ?>"
                    aria-pressed="<?php echo !empty($item['active']) ? 'true' : 'false'; ?>"
                    title="<?php echo esc_attr($item['title']); ?>"
                >
                    <span class="aipkit_cw_mode_icon dashicons <?php echo esc_attr($item['icon']); ?>" aria-hidden="true"></span>
                    <span class="aipkit_cw_mode_text">
                        <span class="aipkit_cw_mode_title"><?php echo esc_html($item['title']); ?></span>
                    </span>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="aipkit_cw_mode_section" aria-labelledby="aipkit_cw_mode_section_optimize">
        <div class="aipkit_cw_mode_section_heading" id="aipkit_cw_mode_section_optimize">
            <?php esc_html_e('Optimize', 'gpt3-ai-content-generator'); ?>
        </div>
        <div class="aipkit_cw_mode_group_list aipkit_cw_mode_group_list--workflow" role="list" aria-labelledby="aipkit_cw_mode_section_optimize">
            <?php foreach ($optimize_modes as $item) : ?>
                <button
                    type="button"
                    class="aipkit_cw_mode_card<?php echo !empty($item['active']) ? ' is-active' : ''; ?>"
                    data-mode="<?php echo esc_attr($item['mode']); ?>"
                    aria-pressed="<?php echo !empty($item['active']) ? 'true' : 'false'; ?>"
                    title="<?php echo esc_attr($item['title']); ?>"
                >
                    <span class="aipkit_cw_mode_icon dashicons <?php echo esc_attr($item['icon']); ?>" aria-hidden="true"></span>
                    <span class="aipkit_cw_mode_text">
                        <span class="aipkit_cw_mode_title"><?php echo esc_html($item['title']); ?></span>
                    </span>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <label class="screen-reader-text" for="aipkit_cw_mode_select"><?php esc_html_e('Source', 'gpt3-ai-content-generator'); ?></label>
    <select id="aipkit_cw_mode_select" name="cw_generation_mode" class="aipkit_form-input aipkit_autosave_trigger screen-reader-text">
        <option value="task"><?php esc_html_e('Manual entry', 'gpt3-ai-content-generator'); ?></option>
        <option value="csv"><?php esc_html_e('CSV file', 'gpt3-ai-content-generator'); ?></option>
        <option value="rss"><?php esc_html_e('RSS feed', 'gpt3-ai-content-generator'); ?></option>
        <option value="url"><?php esc_html_e('Web page', 'gpt3-ai-content-generator'); ?></option>
        <option value="gsheets"><?php esc_html_e('Google Sheets', 'gpt3-ai-content-generator'); ?></option>
        <option value="existing-content"><?php esc_html_e('Rewrite content', 'gpt3-ai-content-generator'); ?></option>
        <option value="existing-images"><?php esc_html_e('Image alt text', 'gpt3-ai-content-generator'); ?></option>
        <?php if ($has_woocommerce): ?>
            <option value="existing-products"><?php esc_html_e('WooCommerce', 'gpt3-ai-content-generator'); ?></option>
        <?php endif; ?>
        <option value="existing"><?php esc_html_e('Update Existing (Legacy)', 'gpt3-ai-content-generator'); ?></option>
    </select>
</div>
