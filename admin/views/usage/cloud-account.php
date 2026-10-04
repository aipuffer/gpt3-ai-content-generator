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
?>
<section class="aipkit_cloud_connection aipkit_cloud" id="aipkit_cloud_connection">
    <p class="aipkit_model_sync_status aipkit_cloud_feedback <?php echo $aipkit_cloud_is_error ? 'error' : 'success'; ?>" data-aipkit-cloud-feedback role="status" aria-live="polite"><?php echo esc_html($aipkit_cloud_notices[$aipkit_cloud_notice] ?? Connection::verification_message($aipkit_cloud_notice)); ?></p>
    <?php if (!empty($aipkit_cloud_display['revoked'])) : ?>
        <div class="aipkit_cloud_notice aipkit_cloud_notice--warning" role="alert">
            <p><?php esc_html_e('AI Puffer Cloud no longer accepts this site’s connection, for example because it was connected again from a copy of this site. Click Disconnect, then connect again.', 'gpt3-ai-content-generator'); ?></p>
        </div>
    <?php endif; ?>
    <?php echo Connection::email_recovery_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared renderer escapes every value. ?>

    <div class="aipkit_cloud_balance">
        <div class="aipkit_cloud_balance_toolbar">
            <?php echo Connection::account_email_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared renderer escapes every value. ?>
            <div class="aipkit_cloud_balance_toolbar_actions">
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
                    <?php wp_nonce_field('aipkit_cloud_connection', '_wpnonce', false); ?>
                    <input type="hidden" name="action" value="aipkit_cloud_connection">
                    <button class="aipkit_btn aipkit_cloud_btn" name="cloud_action" value="refresh"><span class="dashicons dashicons-update" aria-hidden="true"></span><?php esc_html_e('Refresh', 'gpt3-ai-content-generator'); ?><span class="aipkit_spinner" aria-hidden="true"></span></button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="aipkit_cloud_purchase">
                    <?php wp_nonce_field('aipkit_cloud_connection', '_wpnonce', false); ?>
                    <input type="hidden" name="action" value="aipkit_cloud_connection">
                    <button type="submit" class="aipkit_cloud_purchase_link" name="cloud_action" value="checkout"><?php esc_html_e('Buy credits', 'gpt3-ai-content-generator'); ?><span class="dashicons dashicons-external" aria-hidden="true"></span><span class="aipkit_spinner" aria-hidden="true"></span></button>
                </form>
            </div>
        </div>
        <div class="aipkit_cloud_balance_summary">
            <div class="aipkit_cloud_balance_main">
                <p class="aipkit_cloud_balance_label"><?php esc_html_e('Available credits', 'gpt3-ai-content-generator'); ?></p>
                <?php if ($aipkit_cloud_credits === null) : ?>
                    <p class="aipkit_cloud_balance_value aipkit_cloud_balance_value--empty">—</p>
                    <p class="aipkit_cloud_muted" data-cloud-checked-label role="status"><?php esc_html_e('Balance unavailable right now. Use Refresh to try again.', 'gpt3-ai-content-generator'); ?></p>
                <?php else : ?>
                    <p class="aipkit_cloud_balance_value"><?php echo esc_html(number_format_i18n($aipkit_cloud_to_credits($aipkit_cloud_credits['available']))); ?><span class="aipkit_cloud_balance_unit"><?php esc_html_e('credits', 'gpt3-ai-content-generator'); ?></span></p>
                    <?php if ($aipkit_cloud_checked_at > 0) : ?>
                        <p class="aipkit_cloud_muted" data-cloud-checked-label role="status"><?php echo esc_html(sprintf(
                            /* translators: %s: time the credit balance was last checked. */
                            __('Checked %s.', 'gpt3-ai-content-generator'),
                            wp_date(get_option('date_format') . ' ' . get_option('time_format'), $aipkit_cloud_checked_at)
                        )); ?></p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php if ($aipkit_cloud_credits !== null) : ?>
                <dl class="aipkit_cloud_split">
                    <div class="aipkit_cloud_split_item">
                        <dt><?php esc_html_e('Free', 'gpt3-ai-content-generator'); ?></dt>
                        <dd><?php echo esc_html(number_format_i18n($aipkit_cloud_to_credits($aipkit_cloud_credits['free']))); ?></dd>
                        <?php if ($aipkit_cloud_credits['allowance']['total'] !== null) : ?>
                            <small><?php echo esc_html(sprintf(
                                /* translators: 1: monthly free credits, 2: renewal date. */
                                __('%1$s/month · renews %2$s', 'gpt3-ai-content-generator'),
                                number_format_i18n($aipkit_cloud_to_credits($aipkit_cloud_credits['allowance']['total'])),
                                $aipkit_cloud_credits['allowance']['nextRefresh'] ? $aipkit_cloud_date($aipkit_cloud_credits['allowance']['nextRefresh']) : '—'
                            )); ?></small>
                        <?php endif; ?>
                    </div>
                    <?php if ((int) ($aipkit_cloud_credits['included'] ?? 0) !== 0 || (int) ($aipkit_cloud_credits['includedAllowance']['total'] ?? 0) > 0) : ?>
                        <div class="aipkit_cloud_split_item">
                            <dt><?php esc_html_e('Included', 'gpt3-ai-content-generator'); ?></dt>
                            <dd><?php echo esc_html(number_format_i18n($aipkit_cloud_to_credits($aipkit_cloud_credits['included'] ?? '0'))); ?></dd>
                            <small><?php echo esc_html(sprintf(
                                /* translators: 1: included monthly credits, 2: credit renewal date. */
                                __('%1$s/month · renews %2$s', 'gpt3-ai-content-generator'),
                                number_format_i18n($aipkit_cloud_to_credits($aipkit_cloud_credits['includedAllowance']['total'] ?? '0')),
                                !empty($aipkit_cloud_credits['includedAllowance']['nextRefresh']) ? $aipkit_cloud_date($aipkit_cloud_credits['includedAllowance']['nextRefresh']) : '—'
                            )); ?></small>
                        </div>
                    <?php endif; ?>
                    <div class="aipkit_cloud_split_item">
                        <dt><?php esc_html_e('Purchased', 'gpt3-ai-content-generator'); ?></dt>
                        <dd><?php echo esc_html(number_format_i18n($aipkit_cloud_to_credits((int) $aipkit_cloud_credits['purchased'] + (int) $aipkit_cloud_credits['adjustments']))); ?></dd>
                        <small><?php esc_html_e('Never expire', 'gpt3-ai-content-generator'); ?></small>
                    </div>
                    <?php if ((int) $aipkit_cloud_credits['held'] > 0) : ?>
                        <div class="aipkit_cloud_split_item">
                            <dt><?php esc_html_e('In use', 'gpt3-ai-content-generator'); ?></dt>
                            <dd><?php echo esc_html(number_format_i18n($aipkit_cloud_to_credits($aipkit_cloud_credits['held']))); ?></dd>
                            <small><?php esc_html_e('Running requests', 'gpt3-ai-content-generator'); ?></small>
                        </div>
                    <?php endif; ?>
                </dl>
            <?php endif; ?>
        </div>
        <?php if ($aipkit_cloud_credits !== null) : ?>
            <?php
            $aipkit_cloud_allowance_note = Connection::allowance_message($aipkit_cloud_credits);
            ?>
            <?php if ($aipkit_cloud_allowance_note !== '') : ?>
                <div class="aipkit_cloud_notice aipkit_cloud_notice--warning"><p><?php echo esc_html($aipkit_cloud_allowance_note); ?></p></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
