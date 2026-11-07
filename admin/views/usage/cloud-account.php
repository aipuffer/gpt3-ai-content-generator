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
$aipkit_cloud_is_error = in_array($aipkit_cloud_notice, ['models_unavailable', 'balance_unavailable', 'sync_failed', 'unavailable', 'identity_check_unavailable', 'disconnect_failed', 'busy', 'forbidden'], true);
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
$aipkit_cloud_revoked = !empty($aipkit_cloud_display['revoked']);
// Where the balance comes from, in the order of the bar: the free monthly credits, credits included with a plan, then
// purchased. Each monthly part shows what is left of its allowance; the bar's empty end is what this month has used.
$aipkit_cloud_free_total = $aipkit_cloud_credits !== null && ($aipkit_cloud_credits['allowance']['total'] ?? null) !== null ? $aipkit_cloud_to_credits($aipkit_cloud_credits['allowance']['total']) : null;
$aipkit_cloud_included_total = $aipkit_cloud_has_included ? $aipkit_cloud_to_credits($aipkit_cloud_credits['includedAllowance']['total'] ?? 0) : 0;
$aipkit_cloud_sources = [];
if ($aipkit_cloud_credits !== null) {
    $aipkit_cloud_sources[] = ['tone' => 0, 'label' => __('Free', 'gpt3-ai-content-generator'), 'left' => $aipkit_cloud_to_credits($aipkit_cloud_credits['free'] ?? 0), 'total' => $aipkit_cloud_free_total];
    if ($aipkit_cloud_has_included) {
        $aipkit_cloud_sources[] = ['tone' => 1, 'label' => __('Included', 'gpt3-ai-content-generator'), 'left' => $aipkit_cloud_to_credits($aipkit_cloud_credits['included'] ?? 0), 'total' => $aipkit_cloud_included_total ?: null];
    }
    $aipkit_cloud_sources[] = ['tone' => 2, 'label' => __('Purchased', 'gpt3-ai-content-generator'), 'left' => $aipkit_cloud_purchased, 'total' => null];
}
$aipkit_cloud_scale = max(
    array_sum(array_map(static fn($source) => max(0, $source['left']), $aipkit_cloud_sources)),
    (float) $aipkit_cloud_free_total + $aipkit_cloud_included_total + max(0, $aipkit_cloud_purchased)
);
// The next renewal: the plan's credits when there are any, else the free ones.
$aipkit_cloud_renewal = $aipkit_cloud_credits !== null ? ($aipkit_cloud_credits['includedAllowance']['nextRefresh'] ?? $aipkit_cloud_credits['allowance']['nextRefresh'] ?? null) : null;
$aipkit_cloud_header_date = $aipkit_cloud_renewal && !$aipkit_cloud_revoked ? $aipkit_cloud_date($aipkit_cloud_renewal) : '';
// The monthly allowances. A date shows beside one only when it differs from the next renewal, which ends the line.
$aipkit_cloud_new_date = static fn($iso): bool => !empty($iso) && $aipkit_cloud_date($iso) !== $aipkit_cloud_header_date;
$aipkit_cloud_allowances = [];
if ($aipkit_cloud_free_total !== null) {
    $aipkit_cloud_allowances[] = $aipkit_cloud_new_date($aipkit_cloud_credits['allowance']['nextRefresh'] ?? null)
        /* translators: 1: monthly free credits, 2: renewal date. */
        ? sprintf(__('Free: %1$s a month, renews %2$s', 'gpt3-ai-content-generator'), number_format_i18n($aipkit_cloud_free_total), $aipkit_cloud_date($aipkit_cloud_credits['allowance']['nextRefresh']))
        /* translators: %s: monthly free credits. */
        : sprintf(__('Free: %s a month', 'gpt3-ai-content-generator'), number_format_i18n($aipkit_cloud_free_total));
}
if ($aipkit_cloud_included_total > 0) {
    $aipkit_cloud_allowances[] = $aipkit_cloud_new_date($aipkit_cloud_credits['includedAllowance']['nextRefresh'] ?? null)
        /* translators: 1: credits included with the plan each month, 2: renewal date. */
        ? sprintf(__('Included: %1$s a month, renews %2$s', 'gpt3-ai-content-generator'), number_format_i18n($aipkit_cloud_included_total), $aipkit_cloud_date($aipkit_cloud_credits['includedAllowance']['nextRefresh']))
        /* translators: %s: credits included with the plan each month. */
        : sprintf(__('Included: %s a month', 'gpt3-ai-content-generator'), number_format_i18n($aipkit_cloud_included_total));
}
if ($aipkit_cloud_header_date !== '') {
    /* translators: %s: monthly credit renewal date. */
    $aipkit_cloud_allowances[] = sprintf(__('Renews %s', 'gpt3-ai-content-generator'), $aipkit_cloud_header_date);
}
$aipkit_cloud_allowance_line = implode(' · ', $aipkit_cloud_allowances);
$aipkit_cloud_allowance_note = $aipkit_cloud_credits !== null ? Connection::allowance_message($aipkit_cloud_credits) : '';
// The card's one line: what is wrong and what to do, else the allowances. What it can't hold goes behind ⓘ.
$aipkit_cloud_line = ['text' => $aipkit_cloud_allowance_line, 'tone' => ''];
$aipkit_cloud_notes = [];
if ($aipkit_cloud_credits === null) {
    $aipkit_cloud_line = ['text' => __('Balance unavailable right now. Use Refresh to try again.', 'gpt3-ai-content-generator'), 'tone' => ''];
} elseif ($aipkit_cloud_out) {
    $aipkit_cloud_line = ['text' => __('Out of Cloud credits. Tools using Cloud cannot answer.', 'gpt3-ai-content-generator'), 'tone' => 'bad'];
    $aipkit_cloud_free_back = $aipkit_cloud_credits['allowance']['nextRefresh'] ?? null;
    $aipkit_cloud_free_active = empty($aipkit_cloud_credits['restricted']) && in_array($aipkit_cloud_credits['allowance']['state'] ?? '', ['issued', 'awaiting_activity'], true);
    if ($aipkit_cloud_free_active && $aipkit_cloud_free_total > 0 && $aipkit_cloud_free_back && strtotime($aipkit_cloud_free_back) > time()) {
        /* translators: 1: monthly free credits, 2: renewal date. */
        $aipkit_cloud_line['text'] .= ' ' . sprintf(__('%1$s free credits renew %2$s.', 'gpt3-ai-content-generator'), Connection::format_credits($aipkit_cloud_credits['allowance']['total']), $aipkit_cloud_date($aipkit_cloud_free_back));
    }
} elseif ($aipkit_cloud_low) {
    $aipkit_cloud_line = ['text' => __('Running low.', 'gpt3-ai-content-generator') . ' ' . __('Top up to keep Cloud-powered tools running.', 'gpt3-ai-content-generator'), 'tone' => 'warn'];
} elseif ($aipkit_cloud_allowance_note !== '') {
    $aipkit_cloud_line = ['text' => $aipkit_cloud_allowance_note, 'tone' => 'warn'];
} elseif ($aipkit_cloud_free_total > 0 && $aipkit_cloud_sources[0]['left'] <= 0 && !empty($aipkit_cloud_credits['allowance']['nextRefresh'])) {
    // Empty free credits do not establish whether included or purchased credits fund the next request.
    /* translators: %s: renewal date of the free monthly credits. */
    $aipkit_cloud_line = ['text' => sprintf(__('Free credits renew %s', 'gpt3-ai-content-generator'), $aipkit_cloud_date($aipkit_cloud_credits['allowance']['nextRefresh'])), 'tone' => ''];
}
if ($aipkit_cloud_credits !== null) {
    if ($aipkit_cloud_line['text'] !== $aipkit_cloud_allowance_line && $aipkit_cloud_allowance_line !== '') { $aipkit_cloud_notes[] = $aipkit_cloud_allowance_line; }
    if ($aipkit_cloud_allowance_note !== '' && $aipkit_cloud_line['text'] !== $aipkit_cloud_allowance_note) { $aipkit_cloud_notes[] = $aipkit_cloud_allowance_note; }
    if ((int) ($aipkit_cloud_credits['held'] ?? 0) > 0) {
        /* translators: %s: credits held by requests that are still running. */
        $aipkit_cloud_notes[] = sprintf(__('%s in use by running requests', 'gpt3-ai-content-generator'), Connection::format_credits($aipkit_cloud_credits['held']));
    }
    if ($aipkit_cloud_checked_label !== '') { $aipkit_cloud_notes[] = $aipkit_cloud_checked_label; }
}
// One pill, only when something needs you.
$aipkit_cloud_pill = $aipkit_cloud_revoked ? [__('Reconnect needed', 'gpt3-ai-content-generator'), 'is-warning']
    : ($aipkit_cloud_credits === null ? [__('Can’t load', 'gpt3-ai-content-generator'), '']
    : ($aipkit_cloud_out ? [__('Out', 'gpt3-ai-content-generator'), 'is-error']
    : ($aipkit_cloud_low ? [__('Running low', 'gpt3-ai-content-generator'), 'is-warning'] : null)));
?>
<?php // One half of the Dashboard's balance panel (Settings::render_usage is the other), built the same: title row
// (ⓘ details, a pill when something needs you, buttons at the right), the number, one bar, one line. ?>
<section class="aipkit_cloud_connection aipkit_usage_half aipkit_usage_twin" id="aipkit_usage_cloud_credits" aria-labelledby="aipkit_usage_cloud_title">
    <?php // The panel's Top up submits this form: one checkout adds AI credits and website actions. ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="aipkit_cloud_purchase" id="aipkit_usage_cloud_checkout" hidden>
        <?php wp_nonce_field('aipkit_cloud_connection', '_wpnonce', false); ?>
        <input type="hidden" name="action" value="aipkit_cloud_connection">
    </form>
    <div class="aipkit_usage_card_head">
        <h2 class="aipkit_usage_card_title" id="aipkit_usage_cloud_title"><?php esc_html_e('AI credits', 'gpt3-ai-content-generator'); ?></h2>
        <?php if ($aipkit_cloud_credits !== null) : ?>
            <?php // Where the credits come from, and the fine print, behind ⓘ: on hover, on focus, or with a click or tap. ?>
            <button type="button" class="aipkit_usage_hint" data-aipkit-usage-hint aria-expanded="false" aria-label="<?php esc_attr_e('AI credits details', 'gpt3-ai-content-generator'); ?>" aria-describedby="aipkit_usage_cloud_details"><span aria-hidden="true">i</span><span class="aipkit_usage_hint_tip" id="aipkit_usage_cloud_details">
                <?php foreach ($aipkit_cloud_sources as $aipkit_cloud_source) : ?>
                    <span class="aipkit_usage_detail_row"><span class="aipkit_usage_stack_swatch" data-tone="<?php echo esc_attr((string) $aipkit_cloud_source['tone']); ?>" aria-hidden="true"></span><span class="aipkit_usage_detail_label"><?php echo esc_html($aipkit_cloud_source['label']); ?></span><b><?php
                        echo esc_html($aipkit_cloud_source['total'] !== null
                            /* translators: 1: credits left, 2: monthly allowance. */
                            ? sprintf(__('%1$s of %2$s', 'gpt3-ai-content-generator'), $aipkit_cloud_format($aipkit_cloud_source['left']), number_format_i18n($aipkit_cloud_source['total']))
                            : $aipkit_cloud_format($aipkit_cloud_source['left']));
                    ?></b></span>
                <?php endforeach; ?>
                <?php foreach ($aipkit_cloud_notes as $aipkit_cloud_note) : ?>
                    <span class="aipkit_usage_detail_note"><?php echo esc_html($aipkit_cloud_note); ?></span>
                <?php endforeach; ?>
            </span></button>
        <?php endif; ?>
        <?php if ($aipkit_cloud_pill) : ?>
            <span class="aipkit_usage_pill aipkit_usage_pill--inline <?php echo esc_attr($aipkit_cloud_pill[1]); ?>"><?php echo esc_html($aipkit_cloud_pill[0]); ?></span>
        <?php endif; ?>
        <?php // The panel's one Top up stands before Manage on Website actions. This copy shows only when that card has
        // none (website off or hidden), so AI credits can still be topped up; base.css hides it otherwise. ?>
        <?php if (!$aipkit_cloud_revoked) : ?>
            <button type="submit" form="aipkit_usage_cloud_checkout" class="aipkit_usage_card_action is-primary" name="cloud_action" value="checkout" data-aipkit-ai-topup><svg class="aipkit_usage_card_action_icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M1.5 2h2l1.7 8h7.3l1.5-5.5H4.3" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><circle cx="6.5" cy="13.2" r="1" fill="currentColor"/><circle cx="11.5" cy="13.2" r="1" fill="currentColor"/></svg><?php esc_html_e('Buy credits', 'gpt3-ai-content-generator'); ?><span class="aipkit_spinner" aria-hidden="true"></span></button>
        <?php endif; ?>
    </div>
    <p class="aipkit_model_sync_status aipkit_cloud_feedback <?php echo $aipkit_cloud_is_error ? 'error' : 'success'; ?>" data-aipkit-cloud-feedback role="status" aria-live="polite"><?php echo esc_html($aipkit_cloud_notices[$aipkit_cloud_notice] ?? Connection::verification_message($aipkit_cloud_notice)); ?></p>
    <?php if ($aipkit_cloud_credits === null) : ?>
        <p class="aipkit_usage_card_value aipkit_usage_card_value--empty">—</p>
        <div class="aipkit_usage_stack is-empty" aria-hidden="true"></div>
        <p class="aipkit_usage_card_meta" data-cloud-checked-label role="status"><?php echo esc_html($aipkit_cloud_line['text']); ?></p>
    <?php else : ?>
        <p class="aipkit_usage_card_value<?php echo $aipkit_cloud_out ? ' aipkit_usage_card_value--out' : ($aipkit_cloud_low ? ' aipkit_usage_card_value--low' : ''); ?>" title="<?php echo esc_attr($aipkit_cloud_checked_label); ?>"><?php echo esc_html($aipkit_cloud_format($aipkit_cloud_available)); ?><span class="aipkit_usage_card_unit"><?php
            // Worded like the website card: "left of" the monthly allowances, or just "left" once purchased credits count too.
            $aipkit_cloud_monthly_total = (float) $aipkit_cloud_free_total + $aipkit_cloud_included_total;
            if ($aipkit_cloud_purchased > 0 || $aipkit_cloud_monthly_total <= 0) {
                esc_html_e('left', 'gpt3-ai-content-generator');
            } else {
                /* translators: %s: the monthly credit allowance, for example 25. */
                echo esc_html(sprintf(__('left of %s', 'gpt3-ai-content-generator'), $aipkit_cloud_format($aipkit_cloud_monthly_total)));
            }
            ?></span></p>
        <div class="aipkit_usage_stack<?php echo $aipkit_cloud_low ? ' is-low' : ''; ?>" aria-hidden="true">
            <?php foreach ($aipkit_cloud_sources as $aipkit_cloud_source) : ?>
                <?php if ($aipkit_cloud_source['left'] > 0 && $aipkit_cloud_scale > 0) : ?>
                    <span class="aipkit_usage_stack_part" data-tone="<?php echo esc_attr((string) $aipkit_cloud_source['tone']); ?>" style="width:<?php echo esc_attr((string) round($aipkit_cloud_source['left'] / $aipkit_cloud_scale * 100, 2)); ?>%"></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <p class="aipkit_usage_card_meta<?php echo $aipkit_cloud_line['tone'] !== '' ? ' aipkit_usage_card_meta--' . esc_attr($aipkit_cloud_line['tone']) : ''; ?>"><?php echo esc_html($aipkit_cloud_line['text']); ?></p>
        <?php if ($aipkit_cloud_checked_at > 0) : ?>
            <p class="aipkit_usage_card_meta aipkit_usage_card_meta--quiet" data-cloud-checked-label role="status" hidden><?php echo esc_html($aipkit_cloud_checked_label); ?></p>
        <?php endif; ?>
    <?php endif; ?>
</section>
