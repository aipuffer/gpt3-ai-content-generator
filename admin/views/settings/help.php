<?php
/**
 * Partial: where to get help, a little about AI Puffer, and more from PufferWorks.
 */
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-local variables.

// Freemius shows its Contact and Account pages only to some sites, so the rows follow it.
$aipkit_help_freemius = function_exists('wpaicg_gacg_fs') ? wpaicg_gacg_fs() : null;
$aipkit_help_page_url = static function (string $page) use ($aipkit_help_freemius): string {
    if (!is_object($aipkit_help_freemius) || !method_exists($aipkit_help_freemius, 'is_page_visible') || !$aipkit_help_freemius->is_page_visible($page)) {
        return '';
    }
    return $page === 'contact' ? (string) $aipkit_help_freemius->contact_url() : (string) $aipkit_help_freemius->get_account_url();
};
$aipkit_help_contact_url = $aipkit_help_page_url('contact');
$aipkit_help_account_url = $aipkit_help_page_url('account');
$aipkit_help_wporg_url = 'https://wordpress.org/support/plugin/gpt3-ai-content-generator/';

$aipkit_render_help_row = static function (string $icon, string $title, string $meta, string $url, bool $external = true): void {
    ?>
    <a
        class="aipkit_settings_list_row"
        href="<?php echo esc_url($url); ?>"
        <?php echo $external ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>
    >
        <span class="aipkit_settings_list_icon dashicons dashicons-<?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
        <span class="aipkit_settings_list_copy">
            <span class="aipkit_settings_list_title"><?php echo esc_html($title); ?></span>
            <span class="aipkit_settings_list_meta"><?php echo esc_html($meta); ?></span>
        </span>
        <span class="aipkit_settings_list_chevron dashicons dashicons-<?php echo $external ? 'external' : 'arrow-right-alt2'; ?>" aria-hidden="true"></span>
        <?php if ($external) : ?>
            <span class="screen-reader-text"><?php esc_html_e('(opens in a new tab)', 'gpt3-ai-content-generator'); ?></span>
        <?php endif; ?>
    </a>
    <?php
};
?>
<div class="aipkit_settings_part">
    <h4 class="aipkit_settings_group_title"><?php esc_html_e('Get help', 'gpt3-ai-content-generator'); ?></h4>
    <div class="aipkit_settings_list">
        <?php
        $aipkit_render_help_row('book-alt', __('Guides', 'gpt3-ai-content-generator'), __('Step-by-step help for every tool.', 'gpt3-ai-content-generator'), 'https://docs.aipower.org/');
        $aipkit_render_help_row('format-chat', __('Ask the community', 'gpt3-ai-content-generator'), __('Questions and answers on the WordPress.org forum.', 'gpt3-ai-content-generator'), $aipkit_help_wporg_url);
        if ($aipkit_help_contact_url !== '') {
            $aipkit_render_help_row('email-alt', __('Contact us', 'gpt3-ai-content-generator'), __('Write to the AI Puffer team.', 'gpt3-ai-content-generator'), $aipkit_help_contact_url, false);
        }
        ?>
    </div>
</div>

<div class="aipkit_settings_part">
    <h4 class="aipkit_settings_group_title"><?php esc_html_e('AI Puffer', 'gpt3-ai-content-generator'); ?></h4>
    <div class="aipkit_settings_list">
        <?php
        $aipkit_render_help_row(
            'megaphone',
            __('What’s new', 'gpt3-ai-content-generator'),
            /* translators: %s: installed AI Puffer version number. */
            sprintf(__('You have version %s. See what changed in each release.', 'gpt3-ai-content-generator'), defined('WPAICG_VERSION') ? WPAICG_VERSION : ''),
            'https://wordpress.org/plugins/gpt3-ai-content-generator/#developers'
        );
        if ($aipkit_help_account_url !== '') {
            $aipkit_render_help_row('admin-users', __('Your account', 'gpt3-ai-content-generator'), __('Plan, license and billing.', 'gpt3-ai-content-generator'), $aipkit_help_account_url, false);
        }
        $aipkit_render_help_row('star-filled', __('Leave a review', 'gpt3-ai-content-generator'), __('A short review helps others find AI Puffer.', 'gpt3-ai-content-generator'), $aipkit_help_wporg_url . 'reviews/#new-post');
        ?>
    </div>
</div>

<div class="aipkit_settings_part">
    <?php include __DIR__ . '/more-from-pufferworks.php'; ?>
</div>
