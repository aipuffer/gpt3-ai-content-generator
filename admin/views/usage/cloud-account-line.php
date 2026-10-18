<?php
/**
 * The AI Puffer Cloud account in the Dashboard header: a chip with the email that opens a small menu (Change email or
 * Manage email, and Disconnect). Not connected, it shows nothing: the Cloud banner, or the Dashboard's quiet line once
 * the banner is dismissed, is the one invitation. Email confirmation and
 * reconnecting show as a full-width line under the header, because they need attention. Its forms are handled by the
 * shared Cloud script, and every Cloud response replaces it together with the AI credits card.
 */
use WPAICG\Cloud\Connection;

if (!defined('ABSPATH')) { exit; }
if (!Connection::allowed()) { return; }
$aipkit_cloud_line = Connection::display();
$aipkit_cloud_line_verified = $aipkit_cloud_line['connected'] && $aipkit_cloud_line['email_verified'] === true;
$aipkit_cloud_line_state = !$aipkit_cloud_line['connected'] ? 'signup' : (!empty($aipkit_cloud_line['revoked']) ? 'revoked' : ($aipkit_cloud_line_verified ? 'connected' : 'verify'));
$aipkit_cloud_line_email = (string) $aipkit_cloud_line['email'];
$aipkit_cloud_line_disconnect = static function (string $label, string $class): void {
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="aipkit_usage_account_disconnect">
        <?php wp_nonce_field('aipkit_cloud_connection', '_wpnonce', false); ?>
        <input type="hidden" name="action" value="aipkit_cloud_connection">
        <button type="submit" class="<?php echo esc_attr($class); ?>" name="cloud_action" value="disconnect"
            data-confirm-title="<?php esc_attr_e('Disconnect AI Puffer Cloud?', 'gpt3-ai-content-generator'); ?>"
            data-confirm-text="<?php esc_attr_e('Tools that use AI Puffer stop answering and assistants lose access to this site. Your balance is kept. Connect Cloud again, then reconnect your assistants to restore access.', 'gpt3-ai-content-generator'); ?>"
            data-confirm-button="<?php esc_attr_e('Disconnect', 'gpt3-ai-content-generator'); ?>"
            data-cancel-button="<?php esc_attr_e('Cancel', 'gpt3-ai-content-generator'); ?>"><?php echo esc_html($label); ?><span class="aipkit_spinner" aria-hidden="true"></span></button>
    </form>
    <?php
};
?>
<section class="aipkit_cloud_connection aipkit_usage_account" id="aipkit_usage_cloud_account" data-cloud-line="<?php echo esc_attr($aipkit_cloud_line_state); ?>" aria-label="<?php esc_attr_e('AI Puffer Cloud account', 'gpt3-ai-content-generator'); ?>">
    <?php if ($aipkit_cloud_line_state !== 'signup') : ?>
        <div class="aipkit_usage_account_menu" data-account-menu>
            <button type="button" class="aipkit_usage_account_chip" data-account-chip aria-expanded="false" aria-controls="aipkit_usage_account_popover">
                <span class="aipkit_usage_account_avatar" aria-hidden="true"><?php echo esc_html(function_exists('mb_strtoupper') ? mb_strtoupper(mb_substr($aipkit_cloud_line_email, 0, 1)) : strtoupper(substr($aipkit_cloud_line_email, 0, 1))); ?></span>
                <span class="aipkit_usage_account_email"><?php echo esc_html($aipkit_cloud_line_email); ?></span>
                <?php if ($aipkit_cloud_line_verified) : ?><span class="aipkit_usage_account_tick" aria-label="<?php esc_attr_e('Email verified', 'gpt3-ai-content-generator'); ?>">✓</span><?php endif; ?>
                <span class="aipkit_usage_account_caret" aria-hidden="true"></span>
            </button>
            <div class="aipkit_usage_account_popover" id="aipkit_usage_account_popover" data-account-popover hidden>
                <?php // The account email with Change email (or Manage email) and the one email editor on the Dashboard. ?>
                <?php echo Connection::account_email_html(false, true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared renderer escapes every value. ?>
                <?php $aipkit_cloud_line_disconnect(__('Disconnect AI Puffer Cloud', 'gpt3-ai-content-generator'), 'aipkit_usage_account_item is-danger'); ?>
            </div>
        </div>
        <?php if ($aipkit_cloud_line_state === 'revoked') : ?>
            <div class="aipkit_usage_account_notice" role="alert">
                <p><strong><?php esc_html_e('AI Puffer Cloud no longer accepts this site’s connection', 'gpt3-ai-content-generator'); ?></strong> <?php esc_html_e('This usually happens when the site was connected again from a copy. Disconnect here, then connect again. Your balance is kept.', 'gpt3-ai-content-generator'); ?></p>
                <?php $aipkit_cloud_line_disconnect(__('Disconnect and start again', 'gpt3-ai-content-generator'), 'aipkit_usage_btn aipkit_usage_btn--primary'); ?>
            </div>
        <?php elseif ($aipkit_cloud_line_state === 'verify' && ($aipkit_cloud_line_recovery = Connection::email_recovery_html()) !== '') : ?>
            <?php // Waiting for the email confirmation: the shared resend and "I've verified" controls. ?>
            <div class="aipkit_usage_account_notice"><?php echo $aipkit_cloud_line_recovery; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared renderer escapes every value. ?></div>
        <?php endif; ?>
    <?php endif; ?>
    <p class="aipkit_model_sync_status aipkit_cloud_feedback" data-aipkit-cloud-feedback role="status" aria-live="polite"></p>
</section>
