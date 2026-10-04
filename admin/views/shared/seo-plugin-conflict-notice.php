<?php
/**
 * Shared Partial: Multiple SEO Plugin Notice
 */

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

if (
    !class_exists('\\WPAICG\\SEO\\AIPKit_SEO_Helper')
    || !method_exists('\\WPAICG\\SEO\\AIPKit_SEO_Helper', 'get_multiple_active_plugins_notice_data')
) {
    return;
}

$aipkit_seo_notice_data = \WPAICG\SEO\AIPKit_SEO_Helper::get_multiple_active_plugins_notice_data();
if (empty($aipkit_seo_notice_data)) {
    return;
}

$aipkit_seo_active_label = isset($aipkit_seo_notice_data['active_label'])
    ? (string) $aipkit_seo_notice_data['active_label']
    : __('AIPKit SEO', 'gpt3-ai-content-generator');
$aipkit_seo_detected_labels = isset($aipkit_seo_notice_data['detected_labels']) && is_array($aipkit_seo_notice_data['detected_labels'])
    ? array_map('strval', $aipkit_seo_notice_data['detected_labels'])
    : [];
$aipkit_seo_notice_key = 'smart-seo-multiple-seo-plugins-v1-' . sanitize_key((string) ($aipkit_seo_notice_data['active_plugin'] ?? 'auto'));
?>
<div class="aipkit_notification_bar aipkit_notification_bar--warning" data-aipkit-dismissible-notice="<?php echo esc_attr($aipkit_seo_notice_key); ?>">
    <div class="aipkit_notification_bar__icon" aria-hidden="true">
        <span class="dashicons dashicons-warning"></span>
    </div>
    <div class="aipkit_notification_bar__content">
        <p>
            <?php
            printf(
                /* translators: 1: active SEO plugin label, 2: comma-separated detected SEO plugin labels. */
                esc_html__(
                    'Multiple SEO plugins detected (%2$s). AI Puffer is using %1$s as the active profile. For more predictable scores, consider using only one SEO plugin at a time.',
                    'gpt3-ai-content-generator'
                ),
                esc_html($aipkit_seo_active_label),
                esc_html(implode(', ', $aipkit_seo_detected_labels))
            );
            ?>
        </p>
    </div>
    <button type="button" class="aipkit_notification_bar__close" data-aipkit-dismiss-notice aria-label="<?php esc_attr_e('Dismiss notice', 'gpt3-ai-content-generator'); ?>">
        &times;
    </button>
</div>
