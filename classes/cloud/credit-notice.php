<?php
namespace WPAICG\Cloud;

use WPAICG\AIPKit_Role_Manager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tells site managers when Cloud credits are low or gone and how to resolve it.
 * Reads the stored balance; displaying or dismissing notices never calls Cloud.
 */
final class CreditNotice
{
    private const DISMISSED = 'aipkit_cloud_notice_dismissed';
    private const ACTION = 'aipkit_dismiss_cloud_credit_notice';

    public static function register(): void
    {
        add_action('admin_notices', [self::class, 'render'], 10, 0);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('wp_ajax_' . self::ACTION, [self::class, 'dismiss']);
    }

    /** @return array{key: string, type: string, message: string}|null */
    public static function alert(): ?array
    {
        if (!Connection::generation_ready()) { return null; }
        $state = Connection::credit_state();
        $date = Connection::refresh_date();
        if (($state['problem'] ?? '') === 'site_limit') {
            return ['key' => 'site_limit', 'type' => 'error',
                'message' => __('This site has reached its AI Puffer Cloud spending limit. Cloud requests cannot complete until the limit resets or you raise it in your Cloud account.', 'gpt3-ai-content-generator')];
        }
        if (!empty($state['credits']['restricted']) || ($state['problem'] ?? '') === 'credit_deficit') {
            return ['key' => 'credit_deficit', 'type' => 'error',
                'message' => __('A refunded or disputed Cloud credit purchase left this account with a credit deficit. Cloud requests are restricted until you add credits or contact AI Puffer support.', 'gpt3-ai-content-generator')];
        }
        if (Connection::credits_exhausted()) {
            return ['key' => 'empty', 'type' => 'error', 'message' => $date !== ''
                /* translators: %s: date the free monthly credits refresh. */
                ? sprintf(__('Your AI Puffer Cloud credits have run out. Cloud requests need credits to continue. Free credits refresh on %s.', 'gpt3-ai-content-generator'), $date)
                : __('Your AI Puffer Cloud credits have run out. Cloud requests need credits to continue.', 'gpt3-ai-content-generator')];
        }
        if (Connection::credits_low()) {
            $available_units = (int) ($state['credits']['available'] ?? 0);
            return ['key' => 'low', 'type' => 'warning', 'message' => sprintf(
                /* translators: %s: number of credits left. */
                _n('Only %s AI Puffer Cloud credit left.', 'Only %s AI Puffer Cloud credits left.', $available_units === 1000 ? 1 : 2, 'gpt3-ai-content-generator'),
                Connection::format_credits($available_units)
            ) . ($date !== ''
                /* translators: %s: date the free monthly credits refresh. */
                ? ' ' . sprintf(__('Free credits refresh on %s.', 'gpt3-ai-content-generator'), $date) : '')];
        }
        return null;
    }

    private static function plugin_screen(): bool
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        return $screen && preg_match('/(?:^|_)page_(?:wpaicg|aipkit)(?:-|$)/', (string) $screen->id) === 1;
    }

    private static function dashboard_screen(): bool
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        return $screen && $screen->id === 'toplevel_page_wpaicg';
    }

    private static function fingerprint(array $alert): string
    {
        $state = Connection::credit_state();
        $cycle = $alert['key'] === 'site_limit' ? gmdate('Y-m') : ($state['credits']['allowance']['nextRefresh'] ?? '');
        return hash('sha256', wp_json_encode([Connection::state()['identity'] ?? [], $alert['key'], $cycle]));
    }

    private static function visible_alert(): ?array
    {
        if (!AIPKit_Role_Manager::user_can_manage_settings()) { return null; }
        $alert = self::alert();
        $dismissed = get_user_meta(get_current_user_id(), self::DISMISSED, true);
        // A resolved condition clears dismissal; a later problem can be shown again.
        if (!$alert) {
            if ($dismissed) { delete_user_meta(get_current_user_id(), self::DISMISSED); }
            return null;
        }
        return is_array($dismissed) && ($dismissed['fingerprint'] ?? '') === self::fingerprint($alert) ? null : $alert;
    }

    public static function enqueue_assets(): void
    {
        $screen = get_current_screen();
        if (!self::plugin_screen() || self::dashboard_screen() || ($screen && strpos($screen->id, 'aipkit-setup') !== false)) { return; }
        if (!self::visible_alert()) { return; }
        foreach (['style' => 'dist/css/cloud-credit-notice.bundle.css', 'script' => 'dist/js/cloud-credit-notice.bundle.js'] as $kind => $path) {
            $version = (string) filemtime(WPAICG_PLUGIN_DIR . $path);
            if ($kind === 'style') {
                wp_enqueue_style('aipkit-cloud-credit-notice', WPAICG_PLUGIN_URL . $path, ['dashicons'], $version);
            } else {
                wp_enqueue_script('aipkit-cloud-credit-notice', WPAICG_PLUGIN_URL . $path, [], $version, true);
            }
        }
    }

    public static function render(bool $on_plugin_screen = false): void
    {
        if (!AIPKit_Role_Manager::user_can_manage_settings()) { return; }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && strpos((string) $screen->id, 'aipkit-setup') !== false) { return; }
        // The dashboard renders this beneath its header, rather than above the plugin shell.
        if (!$on_plugin_screen && self::dashboard_screen()) { return; }
        // Explicit rendering is used by the plugin shell and its AJAX fragments.
        if (!$on_plugin_screen && !self::plugin_screen()) { return; }
        $alert = self::visible_alert();
        if (!$alert) { return; }
        ?>
        <div class="<?php echo $on_plugin_screen ? '' : 'notice '; ?>aipkit_notification_bar aipkit_notification_bar--info aipkit_cloud_credit_notice" role="alert"
            data-cloud-credit-key="<?php echo esc_attr($alert['key']); ?>" data-cloud-credit-fingerprint="<?php echo esc_attr(self::fingerprint($alert)); ?>"
            data-cloud-credit-error="<?php esc_attr_e('Could not dismiss the notice. Please reload and try again.', 'gpt3-ai-content-generator'); ?>"
            data-cloud-credit-nonce="<?php echo esc_attr(wp_create_nonce(self::ACTION)); ?>" data-cloud-credit-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
            <img class="aipkit_notification_bar__icon" src="<?php echo esc_url(WPAICG_LOGO_URL); ?>" width="28" height="28" alt="" />
            <div class="aipkit_notification_bar__content">
                <p><?php echo esc_html($alert['message']); ?></p>
                <p data-cloud-credit-feedback role="status" hidden></p>
            </div>
            <a class="aipkit_btn aipkit_btn-primary" href="<?php echo esc_url(Connection::account_url()); ?>"><?php esc_html_e('View credits', 'gpt3-ai-content-generator'); ?></a>
            <button type="button" class="aipkit_notification_bar__close" data-cloud-credit-dismiss aria-label="<?php esc_attr_e('Dismiss credit notice', 'gpt3-ai-content-generator'); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
        </div>
        <?php
    }

    public static function dismiss(): void
    {
        if (!AIPKit_Role_Manager::user_can_manage_settings()) {
            wp_send_json_error(['message' => __('You do not have permission to manage AI Puffer settings.', 'gpt3-ai-content-generator')], 403);
            return;
        }
        check_ajax_referer(self::ACTION, '_ajax_nonce');
        $key = isset($_POST['key']) ? sanitize_key(wp_unslash($_POST['key'])) : '';
        $fingerprint = isset($_POST['fingerprint']) ? sanitize_text_field(wp_unslash($_POST['fingerprint'])) : '';
        $alert = self::alert();
        if ($alert && $alert['key'] === $key && hash_equals(self::fingerprint($alert), $fingerprint)) {
            $dismissed = ['fingerprint' => $fingerprint];
            update_user_meta(get_current_user_id(), self::DISMISSED, $dismissed);
            if (get_user_meta(get_current_user_id(), self::DISMISSED, true) !== $dismissed) {
                wp_send_json_error(['message' => __('Could not dismiss the notice. Please try again.', 'gpt3-ai-content-generator')], 500);
                return;
            }
        }
        wp_send_json_success();
    }
}
