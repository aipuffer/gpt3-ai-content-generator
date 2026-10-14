<?php
/** Connected AI Puffer Cloud account in Usage. */
use WPAICG\Cloud\Connection;

if (!defined('ABSPATH')) { exit; }
$aipkit_cloud_display = Connection::display();
if (!$aipkit_cloud_display['connected']) { return; }
$aipkit_cloud_credits = $aipkit_cloud_display['credits'];
$aipkit_cloud_notice = $aipkit_cloud_notice ?? '';
$aipkit_cloud_notices = [
    'models_unavailable' => __('Credits updated, but models could not be synced. Your saved models are still available.', 'gpt3-ai-content-generator'),
    'balance_unavailable' => __('Models updated, but the credit balance is unavailable. Try again shortly.', 'gpt3-ai-content-generator'),
    'synced' => __('Cloud models synced successfully.', 'gpt3-ai-content-generator'),
    'sync_failed' => __('Cloud models could not be synced. Your previous model list has been preserved. Please try again.', 'gpt3-ai-content-generator'),
    'unavailable' => __('The connection could not be updated. Please try again. Your saved connection has been preserved.', 'gpt3-ai-content-generator'),
    'busy' => __('Another connection action is in progress. Please wait before trying again.', 'gpt3-ai-content-generator'),
    'forbidden' => __('You cannot manage this Cloud connection.', 'gpt3-ai-content-generator'),
];
$aipkit_cloud_is_error = in_array($aipkit_cloud_notice, ['models_unavailable', 'balance_unavailable', 'sync_failed', 'unavailable', 'busy', 'forbidden'], true);
$aipkit_cloud_to_credits = static function ($units): int { return (int) floor(((float) $units) / 1000); };
$aipkit_cloud_date = static function ($iso): string { return wp_date(_x('M j', 'short renewal date', 'gpt3-ai-content-generator'), strtotime((string) $iso)); };
$aipkit_cloud_checked_at = (int) (Connection::credit_state()['checkedAt'] ?? 0);
$aipkit_cloud_checked_label = '';
if ($aipkit_cloud_checked_at > 0) {
    /* translators: %s: date and time the credit balance was checked. */
    $aipkit_cloud_checked_label = sprintf(__('Checked %s.', 'gpt3-ai-content-generator'), wp_date(get_option('date_format') . ' ' . get_option('time_format'), $aipkit_cloud_checked_at));
}
?>
<section class="aipkit_cloud_connection aipkit_cloud aipkit_usage_card aipkit_usage_card--cloud" id="aipkit_usage_cloud_credits" aria-labelledby="aipkit_usage_cloud_title">
    <div class="aipkit_usage_card_head">
        <h2 class="aipkit_usage_card_title" id="aipkit_usage_cloud_title"><?php esc_html_e('AI credits', 'gpt3-ai-content-generator'); ?></h2>
        <?php if (!empty($aipkit_cloud_display['revoked'])) : ?>
            <span class="aipkit_usage_pill is-warning"><?php esc_html_e('Reconnect needed', 'gpt3-ai-content-generator'); ?></span>
        <?php endif; ?>
    </div>
    <p class="aipkit_model_sync_status aipkit_cloud_feedback <?php echo $aipkit_cloud_is_error ? 'error' : 'success'; ?>" data-aipkit-cloud-feedback role="status" aria-live="polite"><?php echo esc_html($aipkit_cloud_notices[$aipkit_cloud_notice] ?? Connection::verification_message($aipkit_cloud_notice)); ?></p>
    <?php if (!empty($aipkit_cloud_display['revoked'])) : ?>
        <div class="aipkit_cloud_notice aipkit_cloud_notice--warning" role="alert">
            <p><?php esc_html_e('AI Puffer Cloud no longer accepts this site’s connection, for example because it was connected again from a copy of this site. Open Credit details, then disconnect and connect again.', 'gpt3-ai-content-generator'); ?></p>
        </div>
    <?php endif; ?>
    <?php echo Connection::email_recovery_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared renderer escapes every value. ?>

    <?php if ($aipkit_cloud_credits === null) : ?>
        <p class="aipkit_usage_card_value aipkit_usage_card_value--empty">—</p>
        <p class="aipkit_usage_card_meta" data-cloud-checked-label role="status"><?php esc_html_e('Balance unavailable right now. Use Refresh to try again.', 'gpt3-ai-content-generator'); ?></p>
    <?php else : ?>
        <p class="aipkit_usage_card_value" title="<?php echo esc_attr($aipkit_cloud_checked_label); ?>"><?php echo esc_html(number_format_i18n($aipkit_cloud_to_credits($aipkit_cloud_credits['available']))); ?><span class="aipkit_usage_card_unit"><?php esc_html_e('credits', 'gpt3-ai-content-generator'); ?></span></p>
        <p class="aipkit_usage_card_meta">
            <?php
            $aipkit_cloud_renewal = $aipkit_cloud_credits['includedAllowance']['nextRefresh'] ?? $aipkit_cloud_credits['allowance']['nextRefresh'] ?? null;
            if ($aipkit_cloud_renewal) {
                /* translators: %s: monthly credit renewal date. */
                echo esc_html(sprintf(__('Renews %s', 'gpt3-ai-content-generator'), $aipkit_cloud_date($aipkit_cloud_renewal)));
            }
            ?>
        </p>
        <?php if ($aipkit_cloud_checked_at > 0) : ?>
            <p class="aipkit_usage_card_meta aipkit_usage_card_meta--quiet" data-cloud-checked-label role="status" hidden><?php echo esc_html($aipkit_cloud_checked_label); ?></p>
        <?php endif; ?>
        <?php $aipkit_cloud_allowance_note = Connection::allowance_message($aipkit_cloud_credits); ?>
        <?php if ($aipkit_cloud_allowance_note !== '') : ?>
            <div class="aipkit_cloud_notice aipkit_cloud_notice--warning"><p><?php echo esc_html($aipkit_cloud_allowance_note); ?></p></div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="aipkit_usage_card_actions">
        <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="aipkit_cloud_purchase">
            <?php wp_nonce_field('aipkit_cloud_connection', '_wpnonce', false); ?>
            <input type="hidden" name="action" value="aipkit_cloud_connection">
            <button type="submit" class="aipkit_usage_btn aipkit_usage_btn--primary" name="cloud_action" value="checkout"><?php esc_html_e('Buy credits', 'gpt3-ai-content-generator'); ?><span class="aipkit_spinner" aria-hidden="true"></span></button>
        </form>
        <?php // Open the shared Cloud sheet directly over Usage. ?>
        <button type="button" class="aipkit_usage_btn" data-aipkit-cloud-open="credits" aria-haspopup="dialog"><?php esc_html_e('Credit details', 'gpt3-ai-content-generator'); ?></button>
        <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="aipkit_usage_card_refresh">
            <?php wp_nonce_field('aipkit_cloud_connection', '_wpnonce', false); ?>
            <input type="hidden" name="action" value="aipkit_cloud_connection">
            <button class="aipkit_usage_icon_btn" name="cloud_action" value="refresh" aria-label="<?php esc_attr_e('Refresh credit balance', 'gpt3-ai-content-generator'); ?>" title="<?php esc_attr_e('Refresh', 'gpt3-ai-content-generator'); ?>"><span class="dashicons dashicons-update" aria-hidden="true"></span><span class="aipkit_spinner" aria-hidden="true"></span></button>
        </form>
    </div>
</section>
