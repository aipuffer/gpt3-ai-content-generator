<?php
/** Directory-origin setup. Browser identity stays at Bridge; WordPress owns account and site approval. */
namespace WPAICG\Mcp;
use WPAICG\Cloud\Connection;
if (!defined('ABSPATH')) { exit; }
final class DirectoryConnect
{
    public const PAGE = 'wpaicg-directory-connect';
    private const META = 'aipkit_directory_request';
    private static $context = null;
    private static $error = '';
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'add_page'], 20);
        add_action('admin_init', [self::class, 'prepare']);
        add_action('admin_notices', [self::class, 'resume_notice']);
    }
    public static function add_page(): void
    {
        add_submenu_page(null, __('Connect to Claude', 'gpt3-ai-content-generator'), __('Connect to Claude', 'gpt3-ai-content-generator'), 'read', self::PAGE, [self::class, 'render']);
    }
    public static function url(string $id): string { return add_query_arg(['page' => self::PAGE, 'request' => $id], admin_url('admin.php')); }
    private static function valid_id($id): bool { return is_string($id) && (bool) preg_match('/^[A-Za-z0-9_-]{32}$/D', $id); }
    private static function base(string $url): string
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) { return ''; }
        return strtolower($parts['scheme'] . '://' . $parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '') . untrailingslashit($parts['path'] ?? '');
    }
    /** Fixed Bridge destination only; no caller-supplied host and no redirect following. */
    public static function request_info(string $id)
    {
        if (!self::valid_id($id)) { return new \WP_Error('invalid_request', __('Start connecting again from Claude.', 'gpt3-ai-content-generator')); }
        $response = wp_remote_get(Store::bridge_url() . '/directory/request?request=' . rawurlencode($id), ['timeout' => 10, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 8192, 'headers' => ['Accept' => 'application/json']]);
        if (is_wp_error($response)) { return new \WP_Error('request_unavailable', __('The connection request could not be checked. Reload this page to try again.', 'gpt3-ai-content-generator')); }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) !== 200 || !is_array($data) || ($data['request'] ?? '') !== $id || ($data['client'] ?? '') !== 'claude'
            || !is_int($data['expires_at'] ?? null) || $data['expires_at'] <= time() || $data['expires_at'] > time() + 1810) {
            return new \WP_Error('invalid_request', __('This request expired or was already used. Start connecting again from Claude.', 'gpt3-ai-content-generator'));
        }
        if (!is_string($data['site_url'] ?? null) || !in_array(self::base($data['site_url']), [self::base(home_url()), self::base(site_url())], true)) {
            return new \WP_Error('site_mismatch', __('This request is for a different website. Start again from Claude with this website’s address.', 'gpt3-ai-content-generator'));
        }
        return $data;
    }
    public static function ready(): bool
    {
        $cloud = Connection::display();
        return Connection::allowed() && !empty($cloud['connected']) && ($cloud['email_verified'] ?? null) === true && ($cloud['status'] ?? '') !== 'unavailable' && Store::site_token() !== '';
    }
    /** Testable authorization boundary. Reuses the exact normal Settings pairing checks. */
    public static function complete(string $id, string $level, string $nonce)
    {
        if (!Connection::allowed() || !wp_verify_nonce($nonce, 'aipkit_directory_' . $id)) { return new \WP_Error('forbidden', __('You cannot approve this connection. Reload the page and try again.', 'gpt3-ai-content-generator')); }
        $pending = get_user_meta(get_current_user_id(), self::META, true);
        if (!is_array($pending) || ($pending['id'] ?? '') !== $id) { return new \WP_Error('invalid_request', __('Start connecting again from Claude.', 'gpt3-ai-content-generator')); }
        $info = self::request_info($id);
        if (is_wp_error($info)) { return $info; }
        if (!self::ready()) { return new \WP_Error('verification_required', __('Connect AI Puffer Cloud and finish Freemius email verification before continuing.', 'gpt3-ai-content-generator')); }
        if (!in_array($level, Registry::LEVELS, true)) { return new \WP_Error('invalid_level', __('Choose a valid safety level.', 'gpt3-ai-content-generator')); }
        try {
            // Retries reuse a pending pairing after the same permission and account checks; no extra grants.
            $grant = isset($pending['grant_id']) ? Store::grant($pending['grant_id']) : null;
            if ($grant && ($grant['state'] ?? '') === 'pending' && (int) $grant['wp_user_id'] === get_current_user_id() && ($grant['access_level'] ?? '') === $level && ($grant['instance_id'] ?? '') === (Identity::registration()['instance_id'] ?? '') && (int) ($grant['created_at'] ?? 0) > time() - 600 && Identity::active()) {
                $connect = get_transient('aipkit_mcp_connect_' . $grant['grant_id']);
            } else { $connect = ''; }
            if (!$connect) {
                $pair = Settings::create_pair($level, 'claude', wp_get_current_user());
                $connect = $pair['connect_url'];
                $pending['grant_id'] = $pair['grant_id'];
                update_user_meta(get_current_user_id(), self::META, $pending);
            }
            $parts = wp_parse_url($connect); $query = []; parse_str((string) ($parts['query'] ?? ''), $query);
            if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== wp_parse_url(Store::bridge_url(), PHP_URL_HOST) || ($parts['path'] ?? '') !== '/connect' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment']) || !is_string($query['ticket'] ?? null) || !preg_match('/^[A-Za-z0-9_.-]{32,150}$/D', $query['ticket'])) { throw new \RuntimeException('invalid_pairing'); }
            $pending['submitted'] = true;
            update_user_meta(get_current_user_id(), self::META, $pending);
            return Store::bridge_url() . '/directory/return?request=' . rawurlencode($id) . '&ticket=' . rawurlencode($query['ticket']);
        } catch (\Throwable $error) {
            return new \WP_Error('connection_unavailable', __('The website connection could not be prepared. Try again; your existing connections are unchanged.', 'gpt3-ai-content-generator'));
        }
    }
    public static function prepare(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route; approval POST checks a user-bound nonce in complete().
        $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== self::PAGE) { return; }
        nocache_headers(); header('Referrer-Policy: no-referrer'); header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'", false);
        if (!is_user_logged_in()) { auth_redirect(); return; }
        if (!Connection::allowed()) { wp_die(esc_html__('You cannot manage website access.', 'gpt3-ai-content-generator'), '', ['response' => 403]); }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only opaque token; request_info validates the exact ID without normalization.
        $id = isset($_GET['request']) && is_string($_GET['request']) ? wp_unslash($_GET['request']) : '';
        $info = self::request_info($id);
        if (is_wp_error($info)) { self::$error = $info->get_error_message(); return; }
        self::$context = $info;
        $old = get_user_meta(get_current_user_id(), self::META, true);
        if (!is_array($old) || ($old['id'] ?? '') !== $id) { update_user_meta(get_current_user_id(), self::META, ['id' => $id, 'expires_at' => $info['expires_at']]); }
        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        if ($method !== 'POST') { return; }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- complete verifies the user-bound nonce and rejects any value outside the exact safety enum.
        $level = isset($_POST['access_level']) && is_string($_POST['access_level']) ? wp_unslash($_POST['access_level']) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- complete verifies this nonce before any pairing or remote request.
        $nonce = isset($_POST['_wpnonce']) && is_string($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
        $next = self::complete($id, $level, $nonce);
        if (is_wp_error($next)) { self::$error = $next->get_error_message(); return; }
        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- complete constructs only the fixed HTTPS Bridge origin and fixed path.
        wp_redirect($next, 303); exit;
    }
    public static function resume_notice(): void
    {
        if (!Connection::allowed()) { return; }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only routing hint.
        $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page === self::PAGE || ($page !== 'aipkit-setup' && strpos($page, 'wpaicg') !== 0)) { return; }
        $pending = get_user_meta(get_current_user_id(), self::META, true);
        if (!is_array($pending) || !self::valid_id($pending['id'] ?? null) || ($pending['expires_at'] ?? 0) <= time()) { if ($pending) { delete_user_meta(get_current_user_id(), self::META); } return; }
        if (!empty($pending['submitted'])) { return; }
        ?><div class="notice notice-info"><p><a href="<?php echo esc_url(self::url($pending['id'])); ?>"><?php esc_html_e('Continue connecting your website to Claude', 'gpt3-ai-content-generator'); ?></a></p></div><?php
    }
    public static function render(): void
    {
        if (!Connection::allowed()) { return; }
        $ready = self::ready();
        ?><div class="wrap"><div id="aipkit_module-container"><section class="card aipkit_directory_connect" id="aipkit-directory-connect" data-account-ready="<?php echo $ready ? 'true' : 'false'; ?>">
        <h1><?php esc_html_e('Connect this website to Claude', 'gpt3-ai-content-generator'); ?></h1>
        <?php if (self::$error !== '') : ?><p role="alert"><?php echo esc_html(self::$error); ?></p><?php endif; ?>
        <?php if (self::$context) : ?>
            <p><?php echo esc_html(home_url()); ?></p>
            <?php if (!$ready) : ?>
                <p><?php esc_html_e('Connect your AI Puffer account and finish email verification, then continue here.', 'gpt3-ai-content-generator'); ?></p>
                <div><button type="button" class="button button-primary" data-aipkit-cloud-open="credits"><?php esc_html_e('Set up my account', 'gpt3-ai-content-generator'); ?></button><p data-directory-feedback role="alert" hidden></p></div>
                <p><a class="button" href="<?php echo esc_url(self::url(self::$context['request'])); ?>"><?php esc_html_e('Check account', 'gpt3-ai-content-generator'); ?></a></p>
            <?php else : ?>
                <p><?php esc_html_e('Account ready', 'gpt3-ai-content-generator'); ?> · <?php echo esc_html(Connection::display()['email']); ?></p>
                <form method="post" action="<?php echo esc_url(self::url(self::$context['request'])); ?>">
                    <?php wp_nonce_field('aipkit_directory_' . self::$context['request']); ?>
                    <p><?php esc_html_e('Acts as', 'gpt3-ai-content-generator'); ?>: <strong><?php echo esc_html(wp_get_current_user()->user_login); ?></strong></p>
                    <p><label for="directory-access"><?php esc_html_e('Access', 'gpt3-ai-content-generator'); ?></label> <select name="access_level" id="directory-access"><?php foreach (Registry::LEVELS as $level) : ?><option value="<?php echo esc_attr($level); ?>" <?php selected($level, Registry::DEFAULT_LEVEL); ?>><?php echo esc_html(Registry::level_label($level)); ?></option><?php endforeach; ?></select></p>
                    <p><?php esc_html_e('Claude can use this website within your WordPress permissions and the safety level you choose.', 'gpt3-ai-content-generator'); ?></p>
                    <?php Settings::render_connection_terms(); ?>
                    <p><button class="button button-primary"><?php esc_html_e('Continue to Claude', 'gpt3-ai-content-generator'); ?></button></p>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        </section></div></div><?php
    }
}
