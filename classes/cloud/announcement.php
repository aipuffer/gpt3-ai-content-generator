<?php
namespace WPAICG\Cloud;

if (!defined('ABSPATH')) {
    exit;
}

/** Optional Cloud invitation inside the plugin dashboard. Never calls Cloud. */
final class Announcement
{
    private const DISMISSED = 'aipkit_cloud_announcement_dismissed';

    public static function register(): void
    {
        add_action('wp_ajax_aipkit_dismiss_cloud_announcement', [self::class, 'dismiss']);
    }

    public static function render(): void
    {
        if (!Connection::allowed() || get_user_meta(get_current_user_id(), self::DISMISSED, true)) {
            return;
        }
        $connected = Connection::display()['connected'];
        $settings_url = add_query_arg([
            'page' => 'wpaicg',
            'aipkit_module' => 'settings',
            'aipkit_settings_page' => 'ai',
            'aipkit_provider' => 'AIPufferCloud',
        ], admin_url('admin.php'));
        ?>
        <div class="aipkit_notification_bar aipkit_notification_bar--info" data-aipkit-cloud-announcement<?php if ($connected) : ?> hidden<?php endif; ?>>
            <img class="aipkit_notification_bar__icon" src="<?php echo esc_url(WPAICG_LOGO_URL); ?>" width="28" height="28" alt="" />
            <div class="aipkit_notification_bar__content">
                <p><?php esc_html_e('Try AI Puffer Cloud — 25 free credits every month. No API key needed.', 'gpt3-ai-content-generator'); ?></p>
                <p data-cloud-announcement-feedback role="status" hidden></p>
            </div>
            <a href="<?php echo esc_url($settings_url); ?>" class="aipkit_btn aipkit_btn-primary" data-cloud-announcement-connect><?php esc_html_e('Claim free credits', 'gpt3-ai-content-generator'); ?></a>
            <button type="button" class="aipkit_notification_bar__close" data-cloud-announcement-dismiss aria-label="<?php esc_attr_e('Dismiss notice', 'gpt3-ai-content-generator'); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
        </div>
        <?php
    }

    public static function dismiss(): void
    {
        if (!Connection::allowed()) {
            wp_send_json_error(['message' => __('You do not have permission to manage AI Puffer settings.', 'gpt3-ai-content-generator')], 403);
            return;
        }
        check_ajax_referer('aipkit_nonce', '_ajax_nonce');
        update_user_meta(get_current_user_id(), self::DISMISSED, true);
        if (!get_user_meta(get_current_user_id(), self::DISMISSED, true)) {
            wp_send_json_error(['message' => __('Could not dismiss the notice. Please try again.', 'gpt3-ai-content-generator')], 500);
            return;
        }
        wp_send_json_success();
    }
}
