<?php
namespace WPAICG\Cloud;

use WPAICG\AIPKit_Providers;
use WPAICG\AIPKit_Role_Manager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Top-bar AI status: Cloud credits, or a prompt to connect when no AI is connected. Sites on their own
 * provider key see nothing here; their provider is in Settings. Reads stored state only; rendering never
 * calls Cloud or a provider.
 */
final class StatusChip
{
    /** Text-generation providers that make the plugin usable without Cloud. */
    private const TEXT_PROVIDERS = ['openai', 'claude', 'google', 'openrouter', 'azure', 'deepseek', 'xai', 'ollama'];

    /** Markup for AJAX responses that can change the connection; empty for users who cannot manage settings. */
    public static function html(): string
    {
        ob_start();
        self::render();
        return (string) ob_get_clean();
    }

    public static function render(): void
    {
        echo '<div class="aipkit_nav-status" data-aipkit-nav-status>';
        if (AIPKit_Role_Manager::user_can_manage_settings()) {
            $display = Connection::display();
            if (!empty($display['connected'])) {
                self::render_cloud();
            } elseif (!self::has_own_key()) {
                self::render_connect();
            }
        }
        echo '</div>';
    }

    private static function render_cloud(): void
    {
        $credits = Connection::credit_state()['credits'] ?? null;
        $available = is_array($credits) && is_string($credits['available'] ?? null) ? max(0, (int) $credits['available']) : null;
        $total = is_array($credits) && is_string($credits['includedAllowance']['total'] ?? null) ? (int) $credits['includedAllowance']['total'] : 0;
        $state = Connection::credits_exhausted() ? 'empty' : (Connection::credits_low() ? 'low' : 'ok');

        if ($available === null) {
            $label = __('AI Puffer Cloud', 'gpt3-ai-content-generator');
            $aria = $label;
        } elseif ($state === 'empty') {
            $label = __('Out of credits', 'gpt3-ai-content-generator');
            $aria = __('AI Puffer Cloud: out of credits', 'gpt3-ai-content-generator');
        } else {
            // Whole credits keep the bar compact; fractions matter only below one credit.
            $amount = $available >= 1000 ? number_format_i18n(intdiv($available, 1000)) : Connection::format_credits($available);
            $label = $state === 'low'
                /* translators: %s: number of AI Puffer Cloud credits left. */
                ? sprintf(__('%s credits left', 'gpt3-ai-content-generator'), $amount)
                /* translators: %s: number of AI Puffer Cloud credits left. */
                : sprintf(__('%s credits', 'gpt3-ai-content-generator'), $amount);
            /* translators: %s: number of AI Puffer Cloud credits left. */
            $aria = sprintf(__('AI Puffer Cloud: %s credits left', 'gpt3-ai-content-generator'), $amount);
        }
        // The meter compares the balance with the monthly free allowance when Cloud reports one.
        $percent = ($available !== null && $total > 0) ? (int) min(100, round($available / $total * 100)) : null;
        ?>
        <a class="aipkit_nav-status_chip aipkit_nav-status_chip--<?php echo esc_attr($state); ?>"
            href="<?php echo esc_url(Connection::account_url()); ?>"
            data-module="stats" data-aipkit-open-module="stats"
            aria-label="<?php echo esc_attr($aria); ?>" title="<?php echo esc_attr($aria); ?>">
            <?php self::icon($state === 'ok' ? 'cloud' : 'alert'); ?>
            <span class="aipkit_nav-status_text">
                <span class="aipkit_nav-status_label"><?php echo esc_html($label); ?></span>
                <?php if ($percent !== null && $state === 'ok') : ?>
                    <span class="aipkit_nav-status_meter" aria-hidden="true"><span style="width: <?php echo esc_attr((string) $percent); ?>%"></span></span>
                <?php endif; ?>
            </span>
        </a>
        <?php
    }

    private static function render_connect(): void
    {
        ?>
        <button type="button" class="aipkit_nav-status_chip aipkit_nav-status_chip--connect" data-aipkit-nav-connect
            aria-label="<?php esc_attr_e('Connect AI', 'gpt3-ai-content-generator'); ?>" title="<?php esc_attr_e('Connect AI', 'gpt3-ai-content-generator'); ?>">
            <?php self::icon('cloud'); ?>
            <span class="aipkit_nav-status_text"><span class="aipkit_nav-status_label"><?php esc_html_e('Connect AI', 'gpt3-ai-content-generator'); ?></span></span>
        </button>
        <?php
    }

    /** Whether a text provider is connected with the site's own key. */
    private static function has_own_key(): bool
    {
        if (!class_exists(AIPKit_Providers::class)) {
            return false;
        }
        $status = AIPKit_Providers::get_provider_status_map();
        foreach (self::TEXT_PROVIDERS as $provider) {
            if (!empty($status[$provider])) {
                return true;
            }
        }
        return false;
    }

    private static function icon(string $name): void
    {
        $paths = [
            'cloud' => '<path d="M7 18a4 4 0 0 1-.6-7.96A6 6 0 0 1 18 9.5a4.25 4.25 0 0 1-.75 8.5z"></path>',
            'alert' => '<path d="M12 3 2 20h20z"></path><path d="M12 10v4M12 17h.01"></path>',
        ];
        echo '<svg class="aipkit_nav-status_icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . wp_kses($paths[$name], ['path' => ['d' => true]])
            . '</svg>';
    }
}
