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
$aipkit_cloud_to_credits = static function ($units): float { return ((float) $units) / 1000; };
$aipkit_cloud_format = static fn($credits): string => Connection::format_credits((string) round($credits * 1000));
$aipkit_cloud_date = static function ($iso): string { return wp_date(_x('M j', 'short renewal date', 'gpt3-ai-content-generator'), strtotime((string) $iso)); };
$aipkit_cloud_checked_at = (int) (Connection::credit_state()['checkedAt'] ?? 0);
$aipkit_cloud_checked_label = '';
if ($aipkit_cloud_checked_at > 0) {
    /* translators: %s: date and time the credit balance was checked. */
    $aipkit_cloud_checked_label = sprintf(__('Checked %s.', 'gpt3-ai-content-generator'), wp_date(get_option('date_format') . ' ' . get_option('time_format'), $aipkit_cloud_checked_at));
}
$aipkit_cloud_has_included = $aipkit_cloud_credits !== null && ((int) ($aipkit_cloud_credits['included'] ?? 0) !== 0 || (int) ($aipkit_cloud_credits['includedAllowance']['total'] ?? 0) > 0);
$aipkit_cloud_purchased = $aipkit_cloud_credits !== null ? $aipkit_cloud_to_credits((int) ($aipkit_cloud_credits['purchased'] ?? 0) + (int) ($aipkit_cloud_credits['adjustments'] ?? 0)) : 0;
$aipkit_cloud_available = $aipkit_cloud_credits !== null ? $aipkit_cloud_to_credits($aipkit_cloud_credits['available'] ?? 0) : null;
$aipkit_cloud_out = $aipkit_cloud_credits !== null && (int) ($aipkit_cloud_credits['available'] ?? 0) <= 0;
$aipkit_cloud_low = !$aipkit_cloud_out && Connection::credits_low();
// Where the balance comes from, largest first: included with a plan, purchased, and the free monthly credits.
$aipkit_cloud_sources = [];
if ($aipkit_cloud_credits !== null) {
    if ($aipkit_cloud_has_included) {
        $aipkit_cloud_sources[] = [__('Included', 'gpt3-ai-content-generator'), $aipkit_cloud_to_credits($aipkit_cloud_credits['included'] ?? 0)];
    }
    $aipkit_cloud_sources[] = [__('Purchased', 'gpt3-ai-content-generator'), $aipkit_cloud_purchased];
    $aipkit_cloud_sources[] = [__('Free', 'gpt3-ai-content-generator'), $aipkit_cloud_to_credits($aipkit_cloud_credits['free'] ?? 0)];
    usort($aipkit_cloud_sources, static fn($x, $y) => $y[1] <=> $x[1]);
    // An empty source says nothing, except Free: "Free 0" tells you this month's free credits are used.
    $aipkit_cloud_sources = array_values(array_filter($aipkit_cloud_sources, static fn($source) => $source[1] > 0 || $source[0] === __('Free', 'gpt3-ai-content-generator')));
}
$aipkit_cloud_revoked = !empty($aipkit_cloud_display['revoked']);
$aipkit_cloud_sources_total = array_sum(array_map(static fn($source) => max(0, $source[1]), $aipkit_cloud_sources));
// The header shows the next renewal, as on the website card: the plan's credits when there are any, else the free ones.
$aipkit_cloud_renewal = $aipkit_cloud_credits !== null ? ($aipkit_cloud_credits['includedAllowance']['nextRefresh'] ?? $aipkit_cloud_credits['allowance']['nextRefresh'] ?? null) : null;
// With Reconnect needed the pill takes the header's place, so the date stays in the line instead.
$aipkit_cloud_header_date = $aipkit_cloud_renewal && empty($aipkit_cloud_display['revoked']) ? $aipkit_cloud_date($aipkit_cloud_renewal) : '';
// The monthly allowances, in one line under the number. A date shows there only when it differs from the header's.
$aipkit_cloud_new_date = static fn($iso): bool => !empty($iso) && $aipkit_cloud_date($iso) !== $aipkit_cloud_header_date;
$aipkit_cloud_allowances = [];
if ($aipkit_cloud_credits !== null && ($aipkit_cloud_credits['allowance']['total'] ?? null) !== null) {
    $aipkit_cloud_allowances[] = $aipkit_cloud_new_date($aipkit_cloud_credits['allowance']['nextRefresh'] ?? null)
        /* translators: 1: monthly free credits, 2: renewal date. */
        ? sprintf(__('Free: %1$s a month, renews %2$s', 'gpt3-ai-content-generator'), number_format_i18n($aipkit_cloud_to_credits($aipkit_cloud_credits['allowance']['total'])), $aipkit_cloud_date($aipkit_cloud_credits['allowance']['nextRefresh']))
        /* translators: %s: monthly free credits. */
        : sprintf(__('Free: %s a month', 'gpt3-ai-content-generator'), number_format_i18n($aipkit_cloud_to_credits($aipkit_cloud_credits['allowance']['total'])));
}
if ($aipkit_cloud_has_included && (int) ($aipkit_cloud_credits['includedAllowance']['total'] ?? 0) > 0) {
    $aipkit_cloud_allowances[] = $aipkit_cloud_new_date($aipkit_cloud_credits['includedAllowance']['nextRefresh'] ?? null)
        /* translators: 1: credits included with the plan each month, 2: renewal date. */
        ? sprintf(__('Included: %1$s a month, renews %2$s', 'gpt3-ai-content-generator'), number_format_i18n($aipkit_cloud_to_credits($aipkit_cloud_credits['includedAllowance']['total'])), $aipkit_cloud_date($aipkit_cloud_credits['includedAllowance']['nextRefresh']))
        /* translators: %s: credits included with the plan each month. */
        : sprintf(__('Included: %s a month', 'gpt3-ai-content-generator'), number_format_i18n($aipkit_cloud_to_credits($aipkit_cloud_credits['includedAllowance']['total'])));
}
?>
<?php // The website card's build: the action in the header, the balance, then where it comes from as one stacked bar. ?>
<section class="aipkit_cloud_connection aipkit_usage_half" id="aipkit_usage_cloud_credits" aria-labelledby="aipkit_usage_cloud_title">
    <?php // The header's refresh button submits this form, so a balance refresh keeps using the shared Cloud action. ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="aipkit_usage_card_refresh" id="aipkit_usage_cloud_refresh" hidden>
        <?php wp_nonce_field('aipkit_cloud_connection', '_wpnonce', false); ?>
        <input type="hidden" name="action" value="aipkit_cloud_connection">
    </form>
    <?php // The header Buy credits button submits this form. ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="aipkit_cloud_purchase" id="aipkit_usage_cloud_checkout" hidden>
        <?php wp_nonce_field('aipkit_cloud_connection', '_wpnonce', false); ?>
        <input type="hidden" name="action" value="aipkit_cloud_connection">
    </form>
    <?php // Two parts, like the website card: the balance on top, where it comes from below the line. Side by side,
    // both cards share these two rows, so the lines meet. ?>
    <div class="aipkit_usage_card_top">
        <div class="aipkit_usage_card_head">
            <h2 class="aipkit_usage_card_title" id="aipkit_usage_cloud_title"><?php esc_html_e('AI credits', 'gpt3-ai-content-generator'); ?></h2>
            <?php if ($aipkit_cloud_revoked) : ?>
                <?php // Reconnecting comes first: the pill takes the place of the renewal hint and Buy credits. ?>
                <span class="aipkit_usage_pill is-warning"><?php esc_html_e('Reconnect needed', 'gpt3-ai-content-generator'); ?></span>
            <?php endif; ?>
            <?php if ($aipkit_cloud_header_date !== '' && !$aipkit_cloud_revoked) : ?>
                <?php // The renewal date opens from the ⓘ beside the title: on hover, on focus, or with a click or tap. ?>
                <?php /* translators: %s: monthly credit renewal date. */ $aipkit_cloud_renews = sprintf(__('Renews %s', 'gpt3-ai-content-generator'), $aipkit_cloud_header_date); ?>
                <button type="button" class="aipkit_usage_hint" data-aipkit-usage-hint aria-expanded="false" aria-label="<?php echo esc_attr($aipkit_cloud_renews); ?>"><span aria-hidden="true">i</span><span class="aipkit_usage_hint_tip" aria-hidden="true"><?php echo esc_html($aipkit_cloud_renews); ?></span></button>
            <?php endif; ?>
            <?php // Buy credits stays in the header in every state, so the card keeps one shape; only reconnecting replaces it. ?>
            <?php if (!$aipkit_cloud_revoked) : ?>
                <button type="submit" form="aipkit_usage_cloud_checkout" class="aipkit_usage_card_action is-primary" name="cloud_action" value="checkout"><svg class="aipkit_usage_card_action_icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M1.5 2h2l1.7 8h7.3l1.5-5.5H4.3" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><circle cx="6.5" cy="13.2" r="1" fill="currentColor"/><circle cx="11.5" cy="13.2" r="1" fill="currentColor"/></svg><?php esc_html_e('Buy credits', 'gpt3-ai-content-generator'); ?><span class="aipkit_spinner" aria-hidden="true"></span></button>
            <?php endif; ?>
        </div>
        <p class="aipkit_model_sync_status aipkit_cloud_feedback <?php echo $aipkit_cloud_is_error ? 'error' : 'success'; ?>" data-aipkit-cloud-feedback role="status" aria-live="polite"><?php echo esc_html($aipkit_cloud_notices[$aipkit_cloud_notice] ?? Connection::verification_message($aipkit_cloud_notice)); ?></p>
        <?php // Reconnecting and email confirmation live under the Dashboard header. ?>

        <?php if ($aipkit_cloud_credits === null) : ?>
            <p class="aipkit_usage_card_value aipkit_usage_card_value--empty">—</p>
            <p class="aipkit_usage_card_meta" data-cloud-checked-label role="status"><?php esc_html_e('Balance unavailable right now. Use Refresh to try again.', 'gpt3-ai-content-generator'); ?></p>
        <?php else : ?>
            <p class="aipkit_usage_card_value<?php echo $aipkit_cloud_out ? ' aipkit_usage_card_value--out' : ($aipkit_cloud_low ? ' aipkit_usage_card_value--low' : ''); ?>" title="<?php echo esc_attr($aipkit_cloud_checked_label); ?>"><?php echo esc_html($aipkit_cloud_format($aipkit_cloud_available)); ?><span class="aipkit_usage_card_unit"><?php esc_html_e('credits', 'gpt3-ai-content-generator'); ?></span></p>
            <?php if ((int) ($aipkit_cloud_credits['held'] ?? 0) > 0) : ?>
                <?php /* translators: %s: credits held by requests that are still running. */ ?>
                <p class="aipkit_usage_card_meta"><?php echo esc_html(sprintf(__('%s in use by running requests', 'gpt3-ai-content-generator'), Connection::format_credits($aipkit_cloud_credits['held']))); ?></p>
            <?php endif; ?>
            <?php if ($aipkit_cloud_checked_at > 0) : ?>
                <p class="aipkit_usage_card_meta aipkit_usage_card_meta--quiet" data-cloud-checked-label role="status" hidden><?php echo esc_html($aipkit_cloud_checked_label); ?></p>
            <?php endif; ?>
            <?php // One line under the number: out or low says so; otherwise the monthly allowances, like the website card. ?>
            <?php if ($aipkit_cloud_out) : ?>
                <p class="aipkit_usage_card_meta aipkit_usage_card_meta--bad"><?php esc_html_e('Out of Cloud credits. Tools using Cloud cannot answer.', 'gpt3-ai-content-generator'); ?></p>
            <?php elseif ($aipkit_cloud_low) : ?>
                <p class="aipkit_usage_card_meta aipkit_usage_card_meta--warn"><?php esc_html_e('Running low.', 'gpt3-ai-content-generator'); ?> <?php esc_html_e('Buy credits to keep Cloud-powered tools running.', 'gpt3-ai-content-generator'); ?></p>
            <?php elseif ($aipkit_cloud_allowances) : ?>
                <p class="aipkit_usage_card_meta"><?php echo esc_html(implode(' · ', $aipkit_cloud_allowances)); ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php if ($aipkit_cloud_credits !== null) : ?>
        <div class="aipkit_usage_card_bottom">
            <?php // Where the credits come from, in every state; at zero the bar is empty and the legend says so. ?>
            <h3 class="aipkit_usage_section_title"><?php esc_html_e('Where they come from', 'gpt3-ai-content-generator'); ?></h3>
            <div class="aipkit_usage_stack" aria-hidden="true">
                <?php foreach ($aipkit_cloud_sources as $aipkit_cloud_index => $aipkit_cloud_source) : ?>
                    <?php if ($aipkit_cloud_source[1] > 0) : ?>
                        <span class="aipkit_usage_stack_part" data-tone="<?php echo esc_attr((string) min($aipkit_cloud_index, 4)); ?>" style="width:<?php echo esc_attr((string) round($aipkit_cloud_source[1] / $aipkit_cloud_sources_total * 100, 2)); ?>%"></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <ul class="aipkit_usage_stack_legend">
                <?php foreach ($aipkit_cloud_sources as $aipkit_cloud_index => $aipkit_cloud_source) : ?>
                    <li><span class="aipkit_usage_stack_swatch" data-tone="<?php echo esc_attr((string) min($aipkit_cloud_index, 4)); ?>" aria-hidden="true"></span><?php echo esc_html($aipkit_cloud_source[0]); ?> <b><?php echo esc_html($aipkit_cloud_format($aipkit_cloud_source[1])); ?></b></li>
                <?php endforeach; ?>
            </ul>
            <?php if (($aipkit_cloud_out || $aipkit_cloud_low) && $aipkit_cloud_allowances) : ?>
                <?php
                $aipkit_cloud_bottom_allowances = $aipkit_cloud_allowances;
                $aipkit_cloud_free_total = $aipkit_cloud_to_credits($aipkit_cloud_credits['allowance']['total'] ?? 0);
                $aipkit_cloud_free_back = $aipkit_cloud_credits['allowance']['nextRefresh'] ?? null;
                $aipkit_cloud_free_active = empty($aipkit_cloud_credits['restricted']) && in_array($aipkit_cloud_credits['allowance']['state'] ?? '', ['issued', 'awaiting_activity'], true);
                if ($aipkit_cloud_out && $aipkit_cloud_free_active && $aipkit_cloud_free_total > 0 && $aipkit_cloud_free_back && strtotime($aipkit_cloud_free_back) > time()) {
                    /* translators: 1: monthly free credits, 2: renewal date. */
                    $aipkit_cloud_bottom_allowances[0] = sprintf(__('%1$s free credits renew %2$s.', 'gpt3-ai-content-generator'), Connection::format_credits($aipkit_cloud_credits['allowance']['total']), $aipkit_cloud_date($aipkit_cloud_free_back));
                }
                $aipkit_cloud_bottom_line = implode(' · ', $aipkit_cloud_bottom_allowances);
                ?>
                <p class="aipkit_usage_card_meta aipkit_usage_credit_back"><?php echo esc_html($aipkit_cloud_bottom_line); ?></p>
            <?php endif; ?>
            <?php $aipkit_cloud_allowance_note = Connection::allowance_message($aipkit_cloud_credits); ?>
            <?php if ($aipkit_cloud_allowance_note !== '') : ?>
                <div class="aipkit_cloud_notice aipkit_cloud_notice--warning"><p><?php echo esc_html($aipkit_cloud_allowance_note); ?></p></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
