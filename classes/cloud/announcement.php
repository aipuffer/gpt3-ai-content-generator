<?php
namespace WPAICG\Cloud;

if (!defined('ABSPATH')) {
    exit;
}

/** Optional Cloud invitation inside the plugin dashboard. Never calls Cloud. */
final class Announcement
{
    private const DISMISSED = 'aipkit_cloud_announcement_dismissed';
    // One dismissal hides the website-management tip in Content Writer, Automations and Content Assistant.
    private const TIP_DISMISSED = 'aipkit_website_tip_dismissed';

    public static function register(): void
    {
        add_action('wp_ajax_aipkit_dismiss_cloud_announcement', [self::class, 'dismiss']);
        add_action('wp_ajax_aipkit_dismiss_website_tip', [self::class, 'dismiss_website_tip']);
        add_action('wp_ajax_aipkit_get_website_tip', [self::class, 'get_website_tip']);
    }

    /**
     * The website-management tip shows to people who can set it up, while it isn't set up or was disconnected (the
     * Dashboard invitation's rule), until they dismiss it. Cloud credits and own AI keys don't matter; a paused or
     * outage-retained setup counts as set up.
     */
    public static function website_tip_eligible(): bool
    {
        return class_exists(\WPAICG\Mcp\Settings::class) && \WPAICG\Mcp\Settings::offers_setup()
            && !get_user_meta(get_current_user_id(), self::TIP_DISMISSED, true);
    }

    /**
     * Content Writer and Automations share one tip in the shell's notice stack, with a line for each; its script shows the
     * open screen's line and keeps it hidden while the Cloud banner (the same invitation) shows.
     */
    public static function render_website_tip(): void
    {
        if (!self::website_tip_eligible()) {
            return;
        }
        $settings_url = add_query_arg([
            'page' => 'wpaicg',
            'aipkit_module' => 'settings',
            'aipkit_settings_page' => 'connections',
        ], admin_url('admin.php'));
        $lines = [
            'content-writer' => __('You can write, edit, and publish posts directly from ChatGPT or Claude, without opening WordPress.', 'gpt3-ai-content-generator'),
            'autogpt' => __('You can handle repetitive tasks, update products, and fix SEO issues using ChatGPT or Claude, without opening WordPress.', 'gpt3-ai-content-generator'),
        ];
        ?>
        <div class="aipkit_notification_bar aipkit_notification_bar--promo" data-aipkit-website-tip hidden>
            <span class="aipkit_notification_bar__icon" aria-hidden="true"><span class="dashicons dashicons-admin-site-alt3"></span></span>
            <div class="aipkit_notification_bar__content">
                <?php foreach ($lines as $aipkit_tip_module => $aipkit_tip_line) : ?>
                    <p data-aipkit-website-tip-for="<?php echo esc_attr($aipkit_tip_module); ?>" hidden><strong><?php esc_html_e('Did you know?', 'gpt3-ai-content-generator'); ?></strong> <?php echo esc_html($aipkit_tip_line); ?></p>
                <?php endforeach; ?>
                <p data-website-tip-feedback role="status" hidden></p>
            </div>
            <?php // Opens the website sheet over the open screen, so unfinished work stays; the link is the fallback. ?>
            <a href="<?php echo esc_url($settings_url); ?>" class="aipkit_notification_bar__action is-primary" data-aipkit-cloud-open="website"><?php esc_html_e('Try it free', 'gpt3-ai-content-generator'); ?></a>
            <button type="button" class="aipkit_notification_bar__close" data-aipkit-website-tip-dismiss aria-label="<?php esc_attr_e('Dismiss tip', 'gpt3-ai-content-generator'); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
        </div>
        <?php
    }

    /**
     * The Content Assistant modal's line per list screen (posts, pages, products), or null when the tip doesn't apply.
     * Try it free opens the Dashboard in a new tab, since the website sheet isn't available on WordPress list screens.
     */
    public static function website_tip_payload(): ?array
    {
        if (!self::website_tip_eligible()) {
            return null;
        }
        return [
            // The same words as the Content Writer tip, naming what the list screen holds.
            'lead' => __('Did you know?', 'gpt3-ai-content-generator'),
            'text' => [
                'post' => __('You can write, edit, and publish posts directly from ChatGPT or Claude, without opening WordPress.', 'gpt3-ai-content-generator'),
                'page' => __('You can write, edit, and publish pages directly from ChatGPT or Claude, without opening WordPress.', 'gpt3-ai-content-generator'),
                'product' => __('You can create, edit, and publish products directly from ChatGPT or Claude, without opening WordPress.', 'gpt3-ai-content-generator'),
            ],
            'url' => admin_url('admin.php?page=wpaicg&aipkit_module=stats'),
            'label' => __('Try it free', 'gpt3-ai-content-generator'),
            'newTab' => __('(opens in a new tab)', 'gpt3-ai-content-generator'),
            'dismiss' => __('Dismiss tip', 'gpt3-ai-content-generator'),
            'failed' => __('Could not dismiss the tip. Please try again.', 'gpt3-ai-content-generator'),
        ];
    }

    public static function render(): void
    {
        if (!Connection::allowed() || get_user_meta(get_current_user_id(), self::DISMISSED, true)) {
            return;
        }
        $settings_url = add_query_arg([
            'page' => 'wpaicg',
            'aipkit_module' => 'settings',
            'aipkit_settings_page' => 'connections',
        ], admin_url('admin.php'));
        // Starts hidden; ui-cloud-announcement.js shows it once the first module's own notices are known.
        // It leads with website management: Connect for free opens the website sheet, which starts with the free account.
        ?>
        <div class="aipkit_notification_bar aipkit_notification_bar--promo" data-aipkit-cloud-announcement hidden>
            <span class="aipkit_notification_bar__icon" aria-hidden="true"><span class="dashicons dashicons-admin-site-alt3"></span></span>
            <div class="aipkit_notification_bar__content">
                <p><strong><?php esc_html_e('Manage this site from ChatGPT or Claude.', 'gpt3-ai-content-generator'); ?></strong> <?php esc_html_e('Free every month: 2,000 website actions and 25 AI credits.', 'gpt3-ai-content-generator'); ?></p>
                <p data-cloud-announcement-feedback role="status" hidden></p>
            </div>
            <a href="<?php echo esc_url($settings_url); ?>" class="aipkit_notification_bar__action is-primary" data-aipkit-cloud-open="website"><?php esc_html_e('Connect for free', 'gpt3-ai-content-generator'); ?></a>
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

    /** Revalidate a returning admin tab against current local connection state and this user's dismissal. */
    public static function get_website_tip(): void
    {
        if (!Connection::allowed()) {
            wp_send_json_error(['message' => __('You do not have permission to manage AI Puffer settings.', 'gpt3-ai-content-generator')], 403);
            return;
        }
        check_ajax_referer('aipkit_nonce', '_ajax_nonce');
        wp_send_json_success(['tip' => self::website_tip_payload()]);
    }

    public static function dismiss_website_tip(): void
    {
        if (!Connection::allowed()) {
            wp_send_json_error(['message' => __('You do not have permission to manage AI Puffer settings.', 'gpt3-ai-content-generator')], 403);
            return;
        }
        check_ajax_referer('aipkit_nonce', '_ajax_nonce');
        update_user_meta(get_current_user_id(), self::TIP_DISMISSED, true);
        if (!get_user_meta(get_current_user_id(), self::TIP_DISMISSED, true)) {
            wp_send_json_error(['message' => __('Could not dismiss the tip. Please try again.', 'gpt3-ai-content-generator')], 500);
            return;
        }
        wp_send_json_success();
    }
}
