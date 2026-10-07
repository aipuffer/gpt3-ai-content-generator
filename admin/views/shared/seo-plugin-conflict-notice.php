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
    echo '<span hidden data-aipkit-notice-resolved="smart-seo-multiple-seo-plugins-v2"></span>';
    return;
}

$aipkit_seo_active_label = isset($aipkit_seo_notice_data['active_label'])
    ? (string) $aipkit_seo_notice_data['active_label']
    : __('AIPKit SEO', 'gpt3-ai-content-generator');
$aipkit_seo_detected_labels = isset($aipkit_seo_notice_data['detected_labels']) && is_array($aipkit_seo_notice_data['detected_labels'])
    ? array_map('strval', $aipkit_seo_notice_data['detected_labels'])
    : [];
$aipkit_seo_notice_key = 'smart-seo-multiple-seo-plugins-v2';
?>
<div class="aipkit_notification_bar aipkit_notification_bar--info" data-aipkit-dismissible-notice="<?php echo esc_attr($aipkit_seo_notice_key); ?>" data-aipkit-notice-state="<?php echo esc_attr($aipkit_seo_active_label . ':' . implode(',', $aipkit_seo_detected_labels)); ?>">
    <span class="aipkit_notification_bar__icon" aria-hidden="true"><span class="dashicons dashicons-search"></span></span>
    <div class="aipkit_notification_bar__content">
        <p>
            <strong><?php
            printf(
                /* translators: %s: comma-separated detected SEO plugin labels. */
                esc_html__('More than one SEO plugin is active: %s.', 'gpt3-ai-content-generator'),
                esc_html(implode(', ', $aipkit_seo_detected_labels))
            );
            ?></strong>
            <?php
            printf(
                /* translators: %s: the SEO plugin AI Puffer scores against. */
                esc_html__('Scores use %s; one SEO plugin gives steadier scores.', 'gpt3-ai-content-generator'),
                esc_html($aipkit_seo_active_label)
            );
            ?>
        </p>
    </div>
    <button type="button" class="aipkit_notification_bar__close" data-aipkit-dismiss-notice aria-label="<?php esc_attr_e('Dismiss notice', 'gpt3-ai-content-generator'); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
</div>
