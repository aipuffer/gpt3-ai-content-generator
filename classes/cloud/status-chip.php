<?php
namespace WPAICG\Cloud;

use WPAICG\AIPKit_Role_Manager;

if (!defined('ABSPATH')) {
    exit;
}

/** Dashboard navigation and cached balances. Rendering never contacts external services. */
final class StatusChip
{
    /** Markup for AJAX responses; account balances are restricted to settings managers. */
    public static function html(): string
    {
        ob_start();
        self::render();
        return (string) ob_get_clean();
    }

    public static function render(): void
    {
        echo '<div class="aipkit_nav-status" data-aipkit-nav-status>';
        if (!AIPKit_Role_Manager::user_can_access_module('stats')) { echo '</div>'; return; }
        $parts = [];
        $warning = false;
        if (AIPKit_Role_Manager::user_can_manage_settings()) {
            $display = Connection::display();
            if (!empty($display['revoked'])) {
                $parts[] = ['text' => __('Reconnect Cloud', 'gpt3-ai-content-generator'), 'warn' => true];
            } elseif (!empty($display['connected']) && ($display['email_verified'] ?? false) !== true) {
                $parts[] = ['text' => __('Verify email', 'gpt3-ai-content-generator'), 'warn' => true];
            } elseif (!empty($display['connected'])) {
                $credits = Connection::credit_state()['credits'] ?? null;
                $available = is_array($credits) && is_numeric($credits['available'] ?? null) ? max(0, (int) $credits['available']) : null;
                $parts[] = [
                    // translators: %s: remaining AI credits, not the monthly allowance.
                    'text' => $available === null ? __('Balance unavailable', 'gpt3-ai-content-generator') : sprintf(__('%s AI credits', 'gpt3-ai-content-generator'), Connection::format_credits($available)),
                    'warn' => $available === null || Connection::credits_exhausted() || Connection::credits_low(),
                    'balance' => 'credits',
                    'short' => $available === null ? '—' : ($available >= 10000000 ? self::short_number($available / 1000) : Connection::format_credits($available)),
                ];
                if (class_exists('\WPAICG\Mcp\Settings') && \WPAICG\Mcp\Identity::active()) {
                    $usage = \WPAICG\Mcp\Settings::usage_data();
                    $parts[] = [
                        // translators: %s: remaining website actions.
                        'text' => $usage === null ? __('Actions unavailable', 'gpt3-ai-content-generator') : sprintf(__('%s actions', 'gpt3-ai-content-generator'), number_format_i18n($usage['remaining'])),
                        'warn' => $usage === null || $usage['remaining'] === 0,
                        'balance' => 'actions',
                        'short' => $usage === null ? '—' : ($usage['remaining'] >= 10000 ? self::short_number($usage['remaining']) : number_format_i18n($usage['remaining'])),
                    ];
                }
            }
        }
        foreach ($parts as $part) { $warning = $warning || $part['warn']; }
        $label = __('Dashboard', 'gpt3-ai-content-generator');
        $full_label = implode(' · ', array_merge([$label], array_column($parts, 'text')));
        ?>
        <a class="aipkit_nav-status_chip" href="<?php echo esc_url(admin_url('admin.php?page=wpaicg&aipkit_module=stats')); ?>"
            data-module="stats" data-aipkit-open-module="stats" aria-label="<?php echo esc_attr($full_label); ?>" title="<?php echo esc_attr($full_label); ?>">
            <span><?php echo esc_html($label); ?></span>
            <?php // Balances as an icon and a short number; account steps as a word. The label above reads them in full. ?>
            <?php if ($parts) : ?><span class="aipkit_nav-status_sep" aria-hidden="true"></span><?php endif; ?>
            <?php foreach ($parts as $part) : ?>
                <?php if (!empty($part['balance'])) : ?>
                    <span class="aipkit_nav-status_balance aipkit_nav-status_balance--<?php echo esc_attr($part['balance']); ?><?php echo $part['warn'] ? ' aipkit_nav-status_balance--warning' : ''; ?>" aria-hidden="true"><?php echo self::icon($part['balance']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup. ?><?php echo esc_html($part['short']); ?></span>
                <?php else : ?>
                    <span class="aipkit_nav-status_detail<?php echo $part['warn'] ? ' aipkit_nav-status_detail--warning' : ''; ?>" aria-hidden="true"><?php echo esc_html($part['text']); ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($warning) : ?><span class="aipkit_nav-status_dot" aria-hidden="true"></span><?php endif; ?>
        </a>
        <?php
        echo '</div>';
    }

    /** 19,564 as "19.6K" and 2,500,000 as "2.5M", to one decimal. */
    private static function short_number(float $value): string
    {
        $formats = [
            /* translators: %s: a number in millions, for example 2.5 for 2,500,000. */
            1000000 => __('%sM', 'gpt3-ai-content-generator'),
            /* translators: %s: a number in thousands, for example 19.6 for 19,564. */
            1000 => __('%sK', 'gpt3-ai-content-generator'),
        ];
        foreach ($formats as $size => $format) {
            // 999,950 rounds to 1,000K, so it reads as 1M instead.
            if ($value >= $size || ($size === 1000000 && round($value / 1000, 1) >= 1000)) {
                $short = round($value / $size, 1);
                return sprintf($format, number_format_i18n($short, fmod($short, 1.0) === 0.0 ? 0 : 1));
            }
        }
        return number_format_i18n($value);
    }

    /** A sparkle for AI credits, a globe for website actions. */
    private static function icon(string $kind): string
    {
        return $kind === 'credits'
            ? '<svg class="aipkit_nav-status_icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M8 1.5l1.6 4.1 4.1 1.6-4.1 1.6L8 12.9 6.4 8.8 2.3 7.2l4.1-1.6L8 1.5z" fill="currentColor"/><circle cx="13" cy="12.5" r="1.4" fill="currentColor"/></svg>'
            : '<svg class="aipkit_nav-status_icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><circle cx="8" cy="8" r="6.2" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M1.8 8h12.4M8 1.8c1.9 2 1.9 10.4 0 12.4M8 1.8c-1.9 2-1.9 10.4 0 12.4" fill="none" stroke="currentColor" stroke-width="1.3"/></svg>';
    }

}
