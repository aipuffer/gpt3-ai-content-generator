<?php
/** Explicit owner actions; never part of ordinary settings autosave. */
namespace WPAICG\Mcp;
use WPAICG\Cloud\Connection;
if (!defined('ABSPATH')) { exit; }
final class Settings
{
    public static function register(): void { add_action('wp_ajax_aipkit_mcp_settings', [self::class, 'handle']); }
    public static function render(): void { include dirname(__DIR__, 2) . '/admin/views/settings/manage-website.php'; }
    /** A bounded, local view for setup. Pending is never treated as connected. No site keys or grant secrets are returned. */
    public static function onboarding_connections(): array
    {
        if (!Connection::allowed() || !Identity::active() || !Schema::ready()) { return []; }
        global $wpdb;
        $table = Schema::table('grants');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Current authorization evidence; allowlisted table and prepared identity/user values.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT grant_id, client_hint, access_level, state, created_at FROM $table WHERE instance_id = %s AND wp_user_id = %d AND (state IN ('active','paused') OR (state = 'pending' AND created_at > %d)) ORDER BY created_at DESC LIMIT 20", Identity::registration()['instance_id'] ?? '', get_current_user_id(), time() - 600), ARRAY_A);
        return array_map(static function (array $row): array {
            $row['expires_at'] = $row['state'] === 'pending' ? (int) $row['created_at'] + 600 : 0;
            unset($row['created_at']);
            $row['connect_url'] = $row['state'] === 'pending' ? (string) get_transient('aipkit_mcp_connect_' . $row['grant_id']) : '';
            return $row;
        }, (array) $rows);
    }

    /** Local preflight only: no DNS lookups or requests while rendering setup/Settings. */
    public static function connection_issue(?string $url = null): string
    {
        $addresses = $url === null ? [home_url(), rest_url(), admin_url('admin-ajax.php')] : [$url];
        foreach ($addresses as $address) {
            $parts = wp_parse_url($address);
            $host = is_array($parts) ? strtolower(rtrim(trim((string) ($parts['host'] ?? ''), '[]'), '.')) : '';
            $local = $host === '' || strpos($host, '.') === false || (bool) preg_match('/^(?:[0-9.]+|0x[0-9a-f]+)$/i', $host);
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                $ip = $host;
                $packed = inet_pton($host);
                if (strlen($packed) === 16 && substr($packed, 0, 10) === str_repeat("\0", 10) && substr($packed, 10, 2) === "\xff\xff") {
                    $ip = inet_ntop(substr($packed, 12));
                }
                $local = !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
                if (strpos($ip, ':') === false) {
                    $octets = array_map('intval', explode('.', $ip));
                    $local = $local || $octets[0] >= 224 || ($octets[0] === 100 && $octets[1] >= 64 && $octets[1] <= 127);
                } else { $local = $local || ord($packed[0]) === 255; }
            } else {
                foreach (['localhost', 'local', 'localdomain', 'test', 'internal', 'lan', 'home', 'home.arpa', 'invalid', 'example'] as $suffix) {
                    if ($host === $suffix || substr($host, -strlen('.' . $suffix)) === '.' . $suffix) { $local = true; break; }
                }
            }
            if ($local) {
                /* translators: %s: the configured website hostname. */
                return sprintf(__('This website uses %s, a local or private address that ChatGPT and Claude cannot reach from the internet.', 'gpt3-ai-content-generator'), $host ?: __('an invalid address', 'gpt3-ai-content-generator'));
            }
            if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
                return __('Website management needs a public HTTPS address. This website is not configured with a supported public HTTPS address.', 'gpt3-ai-content-generator');
            }
        }
        return '';
    }

    /** Compact address guidance for the Manage website sheet. */
    public static function render_connection_notice(): void
    {
        $issue = self::connection_issue();
        if ($issue === '') { return; }
        ?><div class="aipkit_cloud_notice aipkit_cloud_notice--warning" role="status">
            <p><strong><?php esc_html_e('A public HTTPS address is required', 'gpt3-ai-content-generator'); ?></strong></p>
            <?php // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Documentation hyperlink only; no scripts or assets are fetched from this address. ?>
            <p><?php esc_html_e('Use a public HTTPS site or a tunnel to connect ChatGPT and Claude.', 'gpt3-ai-content-generator'); ?> <a href="https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Setup guide', 'gpt3-ai-content-generator'); ?></a></p>
        </div><?php
    }

    /** Cached evidence only; ordinary page loads never run connectivity requests. */
    private static function check_fingerprint(): string
    {
        try { $token = Store::site_token(); } catch (\Throwable $error) { $token = ''; }
        return hash('sha256', wp_json_encode([home_url(), rest_url(), admin_url('admin-ajax.php'), hash('sha256', $token)]));
    }

    private static function saved_check(): array
    {
        $report = get_option('aipkit_mcp_readiness_' . get_current_user_id(), []);
        return is_array($report) && ($report['fingerprint'] ?? '') === self::check_fingerprint() ? $report : [];
    }

    public static function connection_blocked(): bool
    {
        return self::connection_issue() !== '' || in_array(self::saved_check()['status'] ?? '', ['failed', 'incomplete'], true);
    }

    /** Only a fresh owner-created challenge can read this content-free executor response. */
    public static function readiness_response($challenge, callable $header): array
    {
        $failure = ['status' => 403, 'signature' => '', 'body' => ['error' => ['code' => 'invalid_probe']]];
        if (!is_string($challenge) || !preg_match('/^[a-f0-9]{32}$/D', $challenge)) { return $failure; }
        $secret = get_transient('aipkit_mcp_probe_' . $challenge);
        if (!is_string($secret) || strlen($secret) !== 64) { return $failure; }
        $expected = Store::b64url_encode(hash_hmac('sha256', 'request:' . $challenge, $secret, true));
        $signature = $header('X-AIPKit-Agent-Signature');
        if (!is_string($signature) || !hash_equals($expected, $signature)) { return $failure; }
        delete_transient('aipkit_mcp_probe_' . $challenge);
        return ['status' => 200, 'signature' => '', 'body' => ['proof' => Store::b64url_encode(hash_hmac('sha256', 'response:' . $challenge, $secret, true))]];
    }

    private static function run_connection_check(): array
    {
        $fingerprint = self::check_fingerprint();
        $rows = [];
        $add = static function ($name, $status, $detail) use (&$rows) { $rows[] = compact('name', 'status', 'detail'); };
        $issue = self::connection_issue();
        $add(__('Website address', 'gpt3-ai-content-generator'), $issue === '' ? 'passed' : 'failed', $issue === '' ? __('Public HTTPS address configured; reachability is checked separately.', 'gpt3-ai-content-generator') : $issue);
        $crypto = function_exists('sodium_crypto_sign_detached') && function_exists('sodium_crypto_sign_verify_detached');
        $add(__('Request signing', 'gpt3-ai-content-generator'), $crypto ? 'passed' : 'failed', $crypto ? __('PHP Sodium is available.', 'gpt3-ai-content-generator') : __('Ask your host to enable PHP Sodium.', 'gpt3-ai-content-generator'));
        $db = Schema::ready();
        if (!$db) {
            try { Schema::install(); $db = Schema::ready(); } catch (\Throwable $error) { $db = false; }
        }
        $add(__('Database', 'gpt3-ai-content-generator'), $db ? 'passed' : 'failed', $db ? __('Required tables and indexes are ready.', 'gpt3-ai-content-generator') : __('Required database tables or indexes could not be prepared. Check your database setup and try again.', 'gpt3-ai-content-generator'));
        $can_edit = current_user_can('edit_posts');
        $add(__('WordPress account', 'gpt3-ai-content-generator'), $can_edit ? 'passed' : 'failed', $can_edit ? __('This account can edit posts; individual actions still use its permissions.', 'gpt3-ai-content-generator') : __('Use a WordPress account that can edit posts.', 'gpt3-ai-content-generator'));
        $cloud = Connection::display();
        $cloud_ready = !empty($cloud['connected']) && $cloud['email_verified'] === true;
        $cloud_unavailable = !empty($cloud['connected']) && ($cloud['status'] ?? '') === 'unavailable';
        $add(__('Cloud account', 'gpt3-ai-content-generator'), $cloud_ready ? 'passed' : 'not_checked', $cloud_ready ? __('Connected and email verified.', 'gpt3-ai-content-generator') : ($cloud_unavailable ? Connection::verification_message('identity_check_unavailable') : __('Connect Cloud and verify your email, then check again.', 'gpt3-ai-content-generator')));
        if ($issue === '' && $crypto && $db && $can_edit && $cloud_ready) {
            $challenge = bin2hex(random_bytes(16));
            $secret = bin2hex(random_bytes(32));
            $transient = 'aipkit_mcp_probe_' . $challenge;
            set_transient($transient, $secret, 180);
            try {
                if (get_transient($transient) !== $secret) { throw new \RuntimeException('probe_not_saved'); }
                $reply = BridgeClient::readiness(['site_url' => home_url(), 'challenge' => $challenge, 'secret' => $secret,
                    'endpoints' => [rest_url('aipkit/v1/mcp/execute'), home_url('/') . '?rest_route=/aipkit/v1/mcp/execute', admin_url('admin-ajax.php?action=aipkit_mcp_execute')]]);
                if (is_wp_error($reply)) {
                    $add(__('Bridge connection', 'gpt3-ai-content-generator'), 'not_checked', __('The connection check could not finish. The server may be busy or temporarily unreachable. Try again.', 'gpt3-ai-content-generator'));
                } elseif (!is_array($reply) || !is_bool($reply['ok'] ?? null) || !is_array($reply['results'] ?? null) || !$reply['results'] || count($reply['results']) > 3) {
                    $add(__('Bridge connection', 'gpt3-ai-content-generator'), 'failed', __('The bridge did not return a usable connection report.', 'gpt3-ai-content-generator'));
                } else {
                    $add(__('Bridge connection', 'gpt3-ai-content-generator'), 'passed', __('The authenticated bridge request succeeded.', 'gpt3-ai-content-generator'));
                    $passed = false;
                    $temporary = true;
                    foreach ($reply['results'] as $result) {
                        $route = is_array($result) && in_array($result['route'] ?? '', ['REST', 'REST alternative', 'AJAX'], true) ? $result['route'] : __('Unknown route', 'gpt3-ai-content-generator');
                        $ok = in_array($result['route'] ?? '', ['REST', 'REST alternative', 'AJAX'], true) && ($result['status'] ?? '') === 'passed' && ($result['http'] ?? 0) === 200;
                        $passed = $passed || $ok;
                        $http = is_int($result['http'] ?? null) ? $result['http'] : 0;
                        $temporary = $temporary && (!$http || $http === 429 || $http >= 500);
                        $detail = $ok ? __('Signed challenge verified; no content was read or changed.', 'gpt3-ai-content-generator') : (!$http || $http === 429 || $http >= 500 ? __('No verified response. The server may be busy or temporarily unreachable. Try again.', 'gpt3-ai-content-generator') : __('The route did not return the signed response. Check the HTTP result, redirects and access restrictions.', 'gpt3-ai-content-generator'));
                        if (isset($result['http']) && is_int($result['http'])) { $detail .= ' HTTP ' . $result['http'] . '.'; }
                        $add($route, $ok ? 'passed' : 'unavailable', $detail);
                    }
                    $add(__('Remote WordPress access', 'gpt3-ai-content-generator'), $reply['ok'] && $passed ? 'passed' : ($temporary ? 'not_checked' : 'failed'), $reply['ok'] && $passed ? __('At least one executor route accepts the signed connection challenge.', 'gpt3-ai-content-generator') : ($temporary ? __('The connection check could not finish. The server may be busy or temporarily unreachable. Try again.', 'gpt3-ai-content-generator') : __('The website did not return a verified callback. Review the route results and check again.', 'gpt3-ai-content-generator')));
                }
            } catch (\Throwable $error) {
                $add(__('Bridge connection', 'gpt3-ai-content-generator'), 'not_checked', __('The connection check could not finish. Try again.', 'gpt3-ai-content-generator'));
            } finally { delete_transient($transient); }
        } else {
            $add(__('Remote WordPress access', 'gpt3-ai-content-generator'), 'not_checked', __('Finish the checks above to test access from the bridge.', 'gpt3-ai-content-generator'));
        }
        if ($fingerprint !== self::check_fingerprint()) {
            $add(__('Cloud account', 'gpt3-ai-content-generator'), 'failed', __('The account or website address changed during this check. Check again.', 'gpt3-ai-content-generator'));
        }
        $statuses = array_column($rows, 'status');
        $report = ['fingerprint' => self::check_fingerprint(), 'checked' => time(), 'status' => in_array('failed', $statuses, true) ? 'failed' : (in_array('not_checked', $statuses, true) ? 'incomplete' : 'passed'), 'rows' => $rows];
        update_option('aipkit_mcp_readiness_' . get_current_user_id(), $report, false);
        return $report;
    }

    public static function render_connection_check(bool $onboarding = false): void
    {
        $report = self::saved_check();
        if ($onboarding) { self::render_setup_check($report); return; }
        $labels = ['passed' => __('Passed', 'gpt3-ai-content-generator'), 'failed' => __('Needs attention', 'gpt3-ai-content-generator'), 'not_checked' => __('Not checked', 'gpt3-ai-content-generator'), 'unavailable' => __('Unavailable', 'gpt3-ai-content-generator')];
        ?><div data-connection-report data-status="<?php echo esc_attr($report['status'] ?? 'not_checked'); ?>">
            <?php if ($report) : ?><p role="status"><strong><?php echo esc_html($report['status'] === 'passed' ? __('Connection check passed', 'gpt3-ai-content-generator') : ($report['status'] === 'failed' ? __('Connection needs attention', 'gpt3-ai-content-generator') : __('Connection check incomplete', 'gpt3-ai-content-generator'))); ?></strong> · <?php echo esc_html(gmdate('Y-m-d H:i', $report['checked']) . ' UTC'); ?></p><?php endif; ?>
            <button type="button" class="<?php echo $onboarding ? 'aipkit-setup__link' : 'button-link'; ?>" data-action="check-connection" data-mcp-action="diagnostics"><?php echo esc_html(self::connection_blocked() ? __('Check again', 'gpt3-ai-content-generator') : __('Check connection', 'gpt3-ai-content-generator')); ?></button>
            <?php // A check that needs attention shows its details right away; a passed one keeps them folded. ?>
            <?php if ($report) : ?><details <?php echo $report['status'] === 'passed' ? '' : 'open'; ?>><summary><?php esc_html_e('Technical details', 'gpt3-ai-content-generator'); ?></summary><ul><?php foreach ($report['rows'] as $row) : ?><li data-status="<?php echo esc_attr(isset($labels[$row['status'] ?? '']) ? $row['status'] : 'not_checked'); ?>"><strong><?php echo esc_html($row['name'] . ': ' . ($labels[$row['status']] ?? $labels['not_checked'])); ?></strong><br><?php echo esc_html($row['detail']); ?></li><?php endforeach; ?></ul></details><?php endif; ?>
        </div><?php
    }

    /**
     * Setup's version: one card with the problem, its fix, Check again and the guide; every check folds into one-line
     * rows under Technical details. Keeps data-connection-report and the check-connection action for onboarding.js.
     */
    private static function render_setup_check(array $report): void
    {
        $issue = self::connection_issue();
        $rows = is_array($report['rows'] ?? null) ? $report['rows'] : [];
        $failed = array_values(array_filter($rows, static function ($row) { return ($row['status'] ?? '') === 'failed'; }));
        $labels = ['passed' => __('Passed', 'gpt3-ai-content-generator'), 'failed' => __('Needs attention', 'gpt3-ai-content-generator'), 'not_checked' => __('Not checked', 'gpt3-ai-content-generator'), 'unavailable' => __('Unavailable', 'gpt3-ai-content-generator')];
        $icons = ['passed' => '✓', 'failed' => '!'];
        $check = static function (string $label): void {
            ?><button type="button" class="aipkit-setup__btn aipkit-setup__btn--ghost" data-action="check-connection" data-mcp-action="diagnostics"><?php echo esc_html($label); ?></button><?php
        };
        // Keep the details focused on the individual checks, with recheck after the list.
        $details = static function (bool $include_recheck = false) use ($rows, $labels, $icons): void {
            if (!$rows) { return; }
            ?>
                    <details class="aipkit-setup__details">
                        <summary><?php esc_html_e('Technical details', 'gpt3-ai-content-generator'); ?></summary>
                        <ul class="aipkit-setup__sentences">
                            <?php foreach ($rows as $row) : $status = isset($labels[$row['status'] ?? '']) ? $row['status'] : 'not_checked'; ?>
                                <li data-status="<?php echo esc_attr($status); ?>"><span class="aipkit-setup__checks-icon" aria-hidden="true"><?php echo esc_html($icons[$status] ?? '–'); ?></span><span><strong><?php echo esc_html((string) $row['name']); ?>:</strong> <?php echo esc_html((string) $row['detail']); ?><span class="screen-reader-text"> (<?php echo esc_html($labels[$status]); ?>)</span></span></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if ($include_recheck) : ?>
                            <button type="button" class="aipkit-setup__link aipkit-setup__details-recheck" data-action="check-connection" data-mcp-action="diagnostics"><?php esc_html_e('Check again', 'gpt3-ai-content-generator'); ?></button>
                        <?php endif; ?>
                    </details>
            <?php
        };
        $passed = $issue === '' && !$failed && ($report['status'] ?? '') === 'passed';
        ?><div data-connection-report data-check-state="<?php echo $passed ? 'passed' : 'attention'; ?>"><?php
        if ($issue !== '' || $failed) : ?>
            <div class="aipkit-setup__problem" role="status">
                <p class="aipkit-setup__problem-title"><span class="aipkit-setup__problem-icon" aria-hidden="true">!</span><?php esc_html_e('This site can’t be connected yet', 'gpt3-ai-content-generator'); ?></p>
                <p class="aipkit-setup__muted"><?php echo esc_html($issue !== '' ? $issue . ' ' . __('Use a public https:// address, for example a tunnel or a staging copy.', 'gpt3-ai-content-generator') : (string) $failed[0]['detail']); ?></p>
                <div class="aipkit-setup__row">
                    <?php $check(__('Check again', 'gpt3-ai-content-generator')); ?>
                    <?php // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Documentation hyperlink only; no scripts or assets are fetched from this address. ?>
                    <a class="aipkit-setup__link" href="https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Setup guide', 'gpt3-ai-content-generator'); ?></a>
                </div>
                <?php $details(); ?>
            </div>
        <?php elseif (($report['status'] ?? '') === 'passed') : ?>
            <?php // Keep successful diagnostics folded away while preserving an explicit recheck. ?>
            <?php $details(true); ?>
        <?php else : ?>
            <?php if ($report) : ?><p class="aipkit-setup__muted" role="status"><?php esc_html_e('Connection check incomplete. The server may be busy or temporarily unreachable. Try again.', 'gpt3-ai-content-generator'); ?></p><?php endif; ?>
            <?php $check($report ? __('Check again', 'gpt3-ai-content-generator') : __('Check connection', 'gpt3-ai-content-generator')); ?>
            <?php $details(); ?>
        <?php endif; ?>
        </div><?php
    }

    /** Shared disclosure beside the explicit Connect action on Settings and setup. */
    public static function render_connection_terms(string $class = 'aipkit_settings_provider_option_help'): void
    {
        ?><p class="<?php echo esc_attr($class); ?>"><?php printf(
            /* translators: 1: AI Puffer terms link. 2: AI Puffer privacy policy link. */
            esc_html__('By connecting, you agree to our %1$s and %2$s.', 'gpt3-ai-content-generator'),
            '<a href="' . esc_url('https://aipower.org/terms-and-conditions/') . '" target="_blank" rel="noopener noreferrer">' . esc_html__('terms', 'gpt3-ai-content-generator') . '</a>',
            '<a href="' . esc_url('https://aipower.org/privacy-policy/') . '" target="_blank" rel="noopener noreferrer">' . esc_html__('privacy policy', 'gpt3-ai-content-generator') . '</a>'
        ); ?></p><?php
    }

    /**
     * Setup steps for one assistant, shared by the website sheet and first-run setup. Each value to paste sits in the
     * step that uses it, step 1 holds the Open button, and edge cases fold into Having trouble?. Claude leads with its
     * directory; manual details remain available without bypassing Bridge authorization. Settings passes the waiting
     * setup ($handoff: url, expires) for the Open links and the time left; setup sets its links in onboarding.js.
     */
    public static function render_assistant_instructions(string $class = 'aipkit_settings_provider_option_help', string $client = 'chatgpt', bool $onboarding = false, array $handoff = []): void
    {
        $url = Store::config()['mcp_url'] ?? 'https://mcp.aipuffer.org/mcp';
        $link = !empty($handoff['url']) ? add_query_arg('launch', '1', (string) $handoff['url']) : '';
        $expires = (int) ($handoff['expires'] ?? 0);
        $expired = !$onboarding && (!$link || $expires <= time());
        $minutes = max(0, (int) ceil(($expires - time()) / 60));
        // A UI name in the assistant, in bold: "+ Add", "Connect".
        $say = static function (string $format, string ...$names): void {
            echo vsprintf(esc_html($format), array_map(static function (string $name): string { return '<b>' . esc_html($name) . '</b>'; }, $names)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Format and names are escaped above.
        };
        // One value to paste: its label, the value, and Copy. ChatGPT's server address is the one Connect copies in setup.
        $field = static function (string $label, string $value, bool $plain = false, bool $address = false) use ($onboarding): void { ?>
            <div class="aipkit_mcp_field">
                <?php if ($label !== '') : ?><span><?php echo esc_html($label); ?></span><?php endif; ?>
                <code<?php echo $plain ? ' class="is-plain"' : ''; ?><?php echo $address && $onboarding ? ' data-assistant-address' : ''; ?>><?php echo esc_html($value); ?></code>
                <button type="button" class="aipkit_mcp_copy" data-action="copy-assistant-setup" data-mcp-copy="<?php echo esc_attr($value); ?>"<?php echo $address && $onboarding ? ' data-mcp-address' : ''; ?>><?php esc_html_e('Copy', 'gpt3-ai-content-generator'); ?></button>
            </div>
        <?php };
        $name_and_address = static function (bool $copied_by_connect = false) use ($field, $url): void { ?>
            <div class="aipkit_mcp_fields"><?php $field(__('Name', 'gpt3-ai-content-generator'), 'AI Puffer', true); $field(__('Server URL', 'gpt3-ai-content-generator'), $url, false, $copied_by_connect); ?></div>
        <?php };
        $open = static function (string $label, string $class = 'aipkit_mcp_open') use ($link, $onboarding): void { ?>
            <a class="<?php echo esc_attr($class . ($class === 'aipkit_mcp_open' ? ($onboarding ? ' aipkit-setup__btn' : ' aipkit_btn aipkit_btn-primary') : '')); ?>" data-mcp-connect-link data-assistant-link<?php echo $link ? ' href="' . esc_url($link) . '"' : ''; ?> target="_blank" rel="noopener noreferrer"><?php echo esc_html($label); ?> <span aria-hidden="true">↗</span></a>
        <?php };
        // Restart uses the original setup's permissions, never a new form's default safety level.
        $restart = static function (string $hint) use ($handoff, $expired): void { ?>
            <div class="aipkit_mcp_expired" data-mcp-expired <?php echo $expired ? '' : 'hidden'; ?>>
                <p><?php esc_html_e('This setup link expired. Links last 10 minutes.', 'gpt3-ai-content-generator'); ?></p>
                <?php if (!empty($handoff['can_restart'])) : ?>
                    <button type="submit" class="aipkit_btn aipkit_btn-primary" form="aipkit_mcp_connect_form" name="client" value="<?php echo esc_attr($hint); ?>" data-mcp-restart data-mcp-access="<?php echo esc_attr($handoff['access_level']); ?>" data-mcp-user="<?php echo esc_attr((string) $handoff['wp_user_id']); ?>"><?php esc_html_e('Start again', 'gpt3-ai-content-generator'); ?></button>
                <?php else : ?>
                    <p><?php esc_html_e('Resolve the connection issue above, then start again.', 'gpt3-ai-content-generator'); ?></p>
                <?php endif; ?>
            </div>
        <?php };
        // Step 1 keeps its Open button beside the action to take in the assistant.
        $first = static function (string $hint, string $label, string $what, string ...$names) use ($open, $say, $onboarding, $restart, $expired): void { ?>
            <li class="aipkit_mcp_step_open">
                <div data-mcp-live <?php echo $expired ? 'hidden' : ''; ?>><?php $open($label); ?><p><?php $say($what, ...$names); ?></p></div>
                <?php if (!$onboarding) { $restart($hint); } ?>
            </li>
        <?php };
        // Settings ends with a quiet Try it; first-run setup has its own step for the first request.
        $try = static function () use ($onboarding, $field): void {
            if ($onboarding) { return; }
            $prompt = sprintf(
                /* translators: %s: WordPress website address. */
                __('Use AI Puffer to show the website name and address for %s. Do not change anything.', 'gpt3-ai-content-generator'), untrailingslashit(home_url())); ?>
            <li class="is-quiet"><p><b><?php esc_html_e('Try it.', 'gpt3-ai-content-generator'); ?></b> <?php esc_html_e('Open a new chat, select AI Puffer and send:', 'gpt3-ai-content-generator'); ?></p>
                <div class="aipkit_mcp_fields aipkit_mcp_prompt"><?php $field('', $prompt, true); ?></div></li>
        <?php };
        $trouble = static function (array $items): void { ?>
            <details class="aipkit_mcp_trouble">
                <summary><?php esc_html_e('Having trouble?', 'gpt3-ai-content-generator'); ?></summary>
                <ul><?php foreach ($items as [$question, $answer]) : ?><li><strong><?php echo esc_html($question); ?></strong> <?php echo esc_html($answer); ?></li><?php endforeach; ?></ul>
            </details>
        <?php };
        $expired_help = [__('Link expired?', 'gpt3-ai-content-generator'), __('Setup links last 10 minutes. Start again to get a new one.', 'gpt3-ai-content-generator')];
        $head = static function (string $hint, string $title, string $sub) use ($onboarding, $minutes, $expired): void {
            if ($onboarding) { return; } ?>
            <div class="aipkit_mcp_setup_head">
                <span class="aipkit_mcp_tile aipkit_mcp_tile--<?php echo esc_attr($hint); ?>" aria-hidden="true"></span>
                <span class="aipkit_mcp_setup_title"><strong><?php echo esc_html($title); ?></strong><span><?php echo esc_html($sub); ?></span></span>
                <?php /* translators: %s: minutes left before the setup link expires. */ ?>
                <span class="aipkit_mcp_setup_status" data-mcp-countdown role="status"><?php echo esc_html($expired ? __('Link expired', 'gpt3-ai-content-generator') : sprintf(__('Waiting · %s min left', 'gpt3-ai-content-generator'), number_format_i18n($minutes))); ?></span>
            </div>
        <?php };
        ?><div class="<?php echo esc_attr($class . ' aipkit_mcp_setup'); ?>" data-mcp-setup>
            <div class="aipkit_mcp_setup_card" data-mcp-instructions="chatgpt" <?php echo $client === 'chatgpt' ? '' : 'hidden'; ?>>
                <?php $head('chatgpt', __('Set up ChatGPT', 'gpt3-ai-content-generator'), __('3 steps in ChatGPT', 'gpt3-ai-content-generator')); ?>
                <ol class="aipkit_mcp_steps">
                    <?php $first('chatgpt', __('Open ChatGPT plugins', 'gpt3-ai-content-generator'), /* translators: 1: Add button label, 2: Add custom MCP server menu label. */ __('There, select %1$s, then %2$s.', 'gpt3-ai-content-generator'), __('+ Add', 'gpt3-ai-content-generator'), __('Add custom MCP server', 'gpt3-ai-content-generator')); ?>
                    <li><p><?php $say(/* translators: %s: OAuth authentication method. */ __('Enter these, and leave Authentication on %s.', 'gpt3-ai-content-generator'), 'OAuth'); ?></p><?php $name_and_address(true); ?></li>
                    <li><p><?php $say(/* translators: 1: consent checkbox label, 2: Create button label, 3: Allow button label. */ __('Select %1$s, then %2$s. Choose %3$s when AI Puffer asks.', 'gpt3-ai-content-generator'), __('I understand and want to continue', 'gpt3-ai-content-generator'), __('Create', 'gpt3-ai-content-generator'), __('Allow', 'gpt3-ai-content-generator')); ?></p></li>
                    <?php $try(); ?>
                </ol>
                <div class="aipkit_mcp_setup_foot">
                    <?php $trouble([
                        [__('Already added AI Puffer?', 'gpt3-ai-content-generator'), __('In ChatGPT, open Plugins → Manage, then choose Connect or Reconnect. For a second account, choose Connect another account.', 'gpt3-ai-content-generator')],
                        [__('No “Add custom MCP server”?', 'gpt3-ai-content-generator'), __('Check your ChatGPT plan, developer mode and workspace permissions. You can also use Claude.', 'gpt3-ai-content-generator')],
                        $expired_help,
                    ]); ?>
                </div>
            </div>
            <div class="aipkit_mcp_setup_card" data-mcp-instructions="claude" <?php echo $client === 'claude' ? '' : 'hidden'; ?>>
                <?php $head('claude', __('Set up Claude', 'gpt3-ai-content-generator'), __('2 steps in Claude', 'gpt3-ai-content-generator')); ?>
                <ol class="aipkit_mcp_steps">
                    <?php $first('claude', __('Open AI Puffer in Claude', 'gpt3-ai-content-generator'), /* translators: %s: Connect button label. */ __('There, select %s.', 'gpt3-ai-content-generator'), __('Connect', 'gpt3-ai-content-generator')); ?>
                    <li><p><?php $say(/* translators: %s: Allow button label. */ __('Choose %s when AI Puffer asks for access to this website.', 'gpt3-ai-content-generator'), __('Allow', 'gpt3-ai-content-generator')); ?></p></li>
                    <?php $try(); ?>
                </ol>
                <div class="aipkit_mcp_setup_foot">
                    <details class="aipkit_mcp_manual">
                        <summary><?php esc_html_e('Not in the directory? Add it manually', 'gpt3-ai-content-generator'); ?></summary>
                        <ol class="aipkit_mcp_steps is-sub">
                            <li><p><?php $say(/* translators: %s: Customize and Connectors menu path. */ __('In Claude, open %s and add a custom connector.', 'gpt3-ai-content-generator'), __('Customize → Connectors', 'gpt3-ai-content-generator')); ?></p></li>
                            <li><p><?php $say(/* translators: 1: Add button label, 2: Connect button label. */ __('Enter these, keep OAuth, then %1$s and %2$s.', 'gpt3-ai-content-generator'), __('Add', 'gpt3-ai-content-generator'), __('Connect', 'gpt3-ai-content-generator')); ?></p><?php $name_and_address(); ?></li>
                            <li><p><?php $say(/* translators: %s: Allow button label. */ __('Choose %s when AI Puffer asks.', 'gpt3-ai-content-generator'), __('Allow', 'gpt3-ai-content-generator')); ?></p></li>
                        </ol>
                    </details>
                    <?php $trouble([
                        [__('Already added AI Puffer?', 'gpt3-ai-content-generator'), __('In Claude, open Customize → Connectors, then choose Connect or Reconnect.', 'gpt3-ai-content-generator')],
                        $expired_help,
                    ]); ?>
                </div>
            </div>
            <?php
            // Other apps: one tab per app, so only the steps for the app in hand show. Radios keep it working without script.
            $apps = ['cursor' => 'Cursor', 'claude-code' => 'Claude Code', 'any' => __('Any MCP app', 'gpt3-ai-content-generator')];
            $group = wp_unique_id($onboarding ? 'aipkit_mcp_app_setup_' : 'aipkit_mcp_app_settings_');
            ?>
            <div class="aipkit_mcp_setup_card" data-mcp-instructions="other" <?php echo !in_array($client, ['chatgpt', 'claude'], true) ? '' : 'hidden'; ?>>
                <?php $head('other', __('Set up another MCP app', 'gpt3-ai-content-generator'), __('Works with any app that supports remote MCP and OAuth', 'gpt3-ai-content-generator')); ?>
                <div class="aipkit_mcp_apps" role="radiogroup" aria-label="<?php esc_attr_e('Your app', 'gpt3-ai-content-generator'); ?>">
                    <?php foreach ($apps as $app => $app_name) : ?>
                        <label><input type="radio" name="<?php echo esc_attr($group); ?>" value="<?php echo esc_attr($app); ?>" <?php checked($app, 'cursor'); ?>><span><?php echo esc_html($app_name); ?></span></label>
                    <?php endforeach; ?>
                </div>
                <ol class="aipkit_mcp_steps" data-mcp-app="cursor">
                    <li><p><?php $say(/* translators: %s: Customize and MCPs menu path. */ __('In Cursor, open %s and add a custom remote server.', 'gpt3-ai-content-generator'), __('Customize → MCPs', 'gpt3-ai-content-generator')); ?></p><div class="aipkit_mcp_fields"><?php $field(__('Server URL', 'gpt3-ai-content-generator'), $url); ?></div></li>
                    <li><p><?php $say(/* translators: 1: Authenticate button label, 2: Allow button label. */ __('Select %1$s, then %2$s in the browser.', 'gpt3-ai-content-generator'), __('Authenticate', 'gpt3-ai-content-generator'), __('Allow', 'gpt3-ai-content-generator')); ?></p></li>
                    <?php $try(); ?>
                </ol>
                <ol class="aipkit_mcp_steps" data-mcp-app="claude-code">
                    <li><p><?php esc_html_e('Run in your terminal:', 'gpt3-ai-content-generator'); ?></p><div class="aipkit_mcp_fields"><?php $field('', 'claude mcp add --scope user --transport http ai-puffer ' . $url); $field('', 'claude mcp login ai-puffer'); ?></div></li>
                    <li><p><?php $say(/* translators: %s: Allow button label. */ __('Choose %s in the browser.', 'gpt3-ai-content-generator'), __('Allow', 'gpt3-ai-content-generator')); ?></p></li>
                    <?php $try(); ?>
                </ol>
                <ol class="aipkit_mcp_steps" data-mcp-app="any">
                    <li><p><?php esc_html_e('Add a remote MCP server with OAuth sign-in.', 'gpt3-ai-content-generator'); ?></p><?php $name_and_address(); ?></li>
                    <li><p><?php $say(/* translators: %s: Allow button label. */ __('Choose %s when AI Puffer asks.', 'gpt3-ai-content-generator'), __('Allow', 'gpt3-ai-content-generator')); ?></p></li>
                    <?php $try(); ?>
                </ol>
                <div class="aipkit_mcp_setup_foot">
                    <div data-mcp-live <?php echo $expired ? 'hidden' : ''; ?>><?php $open(__('Open assistant setup', 'gpt3-ai-content-generator'), 'aipkit_mcp_foot_link'); ?></div>
                    <?php if (!$onboarding) { $restart('other'); } ?>
                    <?php $trouble([
                        [__('No OAuth option?', 'gpt3-ai-content-generator'), __('AI Puffer needs an app that supports remote MCP servers with OAuth sign-in.', 'gpt3-ai-content-generator')],
                        $expired_help,
                    ]); ?>
                </div>
            </div>
        </div><?php
    }

    /** Account-wide action balance; reading this view never charges an action. */
    private static function usage_key(): string
    {
        return 'aipkit_mcp_usage_' . substr(hash('sha256', serialize([Identity::registration(), Store::config()['site_id'] ?? '', Store::site_token()])), 0, 32);
    }

    public static function usage_data(bool $refresh = false): ?array
    {
        if (!Connection::allowed() || !Identity::active()) { return null; }
        $key = self::usage_key();
        $snapshot = get_option('aipkit_mcp_usage_snapshot', []);
        $cached = is_array($snapshot) && ($snapshot['key'] ?? '') === $key ? ($snapshot['usage'] ?? null) : null;
        if (!$refresh) { return is_array($cached) ? $cached : null; }
        $reply = BridgeClient::site_status();
        if (!Identity::active() || self::usage_key() !== $key) { return null; }
        $usage = is_wp_error($reply) ? null : ($reply['usage'] ?? null);
        if (!is_array($usage)) { return null; }
        foreach (['used', 'remaining', 'monthly_allowance', 'in_use', 'as_of'] as $field) {
            if (!isset($usage[$field]) || !is_int($usage[$field]) || $usage[$field] < 0) { return null; }
        }
        foreach (['purchased_remaining', 'monthly_remaining'] as $field) {
            if (isset($usage[$field]) && (!is_int($usage[$field]) || $usage[$field] < 0)) { return null; }
        }
        if (!array_key_exists('period_ends_at', $usage)) { return null; }
        if ($usage['period_ends_at'] !== null && (!is_int($usage['period_ends_at']) || $usage['period_ends_at'] <= $usage['as_of'])) { return null; }
        // Keep one identity-bound last-known balance for instant rendering, not authorization.
        update_option('aipkit_mcp_usage_snapshot', ['key' => $key, 'usage' => $usage], false);
        return $usage;
    }

    /**
     * Whether to invite a settings manager to set up website management: it is off here, and no Cloud outage is
     * hiding a setup that already exists. Reads cached state only.
     */
    public static function offers_setup(): bool
    {
        if (!Connection::allowed() || Identity::active()) { return false; }
        $cloud = Connection::display();
        return empty($cloud['connected']) || ($cloud['status'] ?? '') !== 'unavailable';
    }

    /**
     * The Dashboard's website actions: one half of its balance panel, built like AI credits (cloud-account.php): title
     * row (ⓘ details, a pill when something needs you, then the panel's one Top up and Manage), the number, one bar, one
     * line. Paused, Resume sits at the bottom. Not set up, the same shape invites setup. Available actions include purchased balances. No network requests while rendering.
     */
    public static function render_usage(?array $usage = null): void
    {
        $enabled = Connection::allowed() && Identity::active();
        $invite = !$enabled && self::offers_setup();
        $paused = $enabled && !empty(get_option('aipkit_mcp_settings', [])['paused']);
        // Top up submits the AI credits card's checkout form, so it shows only while that card is here to own it.
        $cloud = $enabled ? Connection::display() : [];
        $top_up = !empty($cloud['connected']) && empty($cloud['revoked']);
        $usage = $usage ?? ($enabled ? self::usage_data() : null);
        $gear = '<svg class="aipkit_usage_card_action_icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><circle cx="8" cy="8" r="2.2" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M8 1.5v2M8 12.5v2M1.5 8h2M12.5 8h2M3.4 3.4l1.4 1.4M11.2 11.2l1.4 1.4M3.4 12.6l1.4-1.4M11.2 4.8l1.4-1.4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';
        $cart = '<svg class="aipkit_usage_card_action_icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M1.5 2h2l1.7 8h7.3l1.5-5.5H4.3" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><circle cx="6.5" cy="13.2" r="1" fill="currentColor"/><circle cx="11.5" cy="13.2" r="1" fill="currentColor"/></svg>';
        $date = static fn(int $at): string => wp_date(_x('M j', 'short renewal date', 'gpt3-ai-content-generator'), $at);
        $line = ['text' => '', 'tone' => ''];
        $notes = [];
        $pill = null;
        $sources = [];
        $scale = 0;
        $out = $low = false;
        if ($usage) {
            $purchased = (int) ($usage['purchased_remaining'] ?? 0);
            $monthly_left = (int) ($usage['monthly_remaining'] ?? max(0, $usage['remaining'] - $purchased));
            // In the bar's order: the monthly allowance (free and plan actions), then purchased. The empty end is what this month used.
            $sources = [
                ['tone' => 0, 'label' => __('Monthly allowance', 'gpt3-ai-content-generator'), 'left' => $monthly_left, 'total' => $usage['monthly_allowance']],
                ['tone' => 2, 'label' => __('Purchased', 'gpt3-ai-content-generator'), 'left' => $purchased, 'total' => null],
            ];
            $scale = max($monthly_left + $purchased, $usage['monthly_allowance'] + $purchased);
            $out = $usage['remaining'] <= 0;
            // Low: a tenth of the monthly allowance or less is left, counting purchased actions.
            $low = !$out && $usage['monthly_allowance'] > 0 && $usage['remaining'] <= $usage['monthly_allowance'] / 10;
            /* translators: %s: the monthly website-action allowance, for example 2,000. */
            $allowance = sprintf(__('Monthly allowance: %s', 'gpt3-ai-content-generator'), number_format_i18n($usage['monthly_allowance']));
            $allowance_line = $usage['period_ends_at']
                /* translators: %s: the website-action renewal date. */
                ? $allowance . ' · ' . sprintf(__('Renews %s', 'gpt3-ai-content-generator'), $date($usage['period_ends_at']))
                : $allowance . ' · ' . __('Renewal starts after your first assistant connection.', 'gpt3-ai-content-generator');
            if ($out && $usage['in_use'] > 0) {
                $line = ['text' => __('No website actions available right now. Some are reserved for running requests; check again when those finish.', 'gpt3-ai-content-generator'), 'tone' => 'bad'];
            } elseif ($out) {
                $line = ['text' => __('No website actions left.', 'gpt3-ai-content-generator'), 'tone' => 'bad'];
            } elseif ($paused) {
                $line = ['text' => __('Website changes are paused. Assistants can still read, not change.', 'gpt3-ai-content-generator'), 'tone' => ''];
            } elseif ($low) {
                $line = ['text' => __('Running low.', 'gpt3-ai-content-generator') . ' ' . __('Top up to keep assistants working.', 'gpt3-ai-content-generator'), 'tone' => 'warn'];
            } elseif ($monthly_left <= 0 && $purchased > 0 && $usage['period_ends_at']) {
                // Monthly actions include both the free allowance and paid-plan entitlements.
                /* translators: %s: the website-action renewal date. */
                $line = ['text' => sprintf(__('Using purchased · monthly actions renew %s', 'gpt3-ai-content-generator'), $date($usage['period_ends_at'])), 'tone' => ''];
            } else {
                $line = ['text' => $allowance_line, 'tone' => ''];
            }
            if ($line['text'] !== $allowance_line) { $notes[] = $allowance_line; }
            /* translators: %s: actions used from the current monthly allowance, excluding purchased actions. */
            $notes[] = sprintf(__('%s used from your monthly allowance across all your sites.', 'gpt3-ai-content-generator'), number_format_i18n($usage['used']));
            if ($usage['in_use'] > 0) {
                /* translators: %s: website actions reserved by running requests. */
                $notes[] = sprintf(__('%s in use by running requests', 'gpt3-ai-content-generator'), number_format_i18n($usage['in_use']));
            }
            /* translators: %s: date and time the balance was checked. */
            $notes[] = sprintf(__('Checked %s.', 'gpt3-ai-content-generator'), wp_date(get_option('date_format') . ' ' . get_option('time_format'), $usage['as_of']));
            $pill = $out ? [__('Out', 'gpt3-ai-content-generator'), 'is-error']
                : ($paused ? [__('Paused', 'gpt3-ai-content-generator'), 'is-warning']
                : ($low ? [__('Running low', 'gpt3-ai-content-generator'), 'is-warning'] : null));
        } elseif ($enabled) {
            $line = ['text' => __('Balance unavailable. Refresh to check again.', 'gpt3-ai-content-generator'), 'tone' => ''];
            $pill = $paused ? [__('Paused', 'gpt3-ai-content-generator'), 'is-warning'] : [__('Can’t load', 'gpt3-ai-content-generator'), ''];
        }
        ?>
        <section data-aipkit-website-usage="usage" data-mcp-enabled="<?php echo $enabled ? '1' : '0'; ?>" class="aipkit_usage_half aipkit_usage_twin" aria-labelledby="aipkit_usage_website_title" <?php echo $invite ? 'data-website-invite' : ''; ?> <?php echo $enabled || $invite ? '' : 'hidden'; ?>>
            <input type="hidden" data-website-usage-nonce value="<?php echo esc_attr(wp_create_nonce('aipkit_mcp_settings')); ?>">
            <?php if ($invite || $enabled) : ?>
                <div class="aipkit_usage_card_head">
                    <h2 class="aipkit_usage_card_title" id="aipkit_usage_website_title"><?php esc_html_e('Website actions', 'gpt3-ai-content-generator'); ?></h2>
                    <?php if ($usage) : ?>
                        <?php // Where the actions come from, and the fine print, behind ⓘ: on hover, on focus, or with a click or tap. ?>
                        <button type="button" class="aipkit_usage_hint" data-aipkit-usage-hint aria-expanded="false" aria-label="<?php esc_attr_e('Website actions details', 'gpt3-ai-content-generator'); ?>" aria-describedby="aipkit_usage_website_details"><span aria-hidden="true">i</span><span class="aipkit_usage_hint_tip" id="aipkit_usage_website_details">
                            <?php foreach ($sources as $source) : ?>
                                <span class="aipkit_usage_detail_row"><span class="aipkit_usage_stack_swatch" data-tone="<?php echo esc_attr((string) $source['tone']); ?>" aria-hidden="true"></span><span class="aipkit_usage_detail_label"><?php echo esc_html($source['label']); ?></span><b><?php
                                    echo esc_html($source['total'] !== null
                                        /* translators: 1: actions left, 2: monthly allowance. */
                                        ? sprintf(__('%1$s of %2$s', 'gpt3-ai-content-generator'), number_format_i18n($source['left']), number_format_i18n($source['total']))
                                        : number_format_i18n($source['left']));
                                ?></b></span>
                            <?php endforeach; ?>
                            <?php foreach ($notes as $note) : ?>
                                <span class="aipkit_usage_detail_note"><?php echo esc_html($note); ?></span>
                            <?php endforeach; ?>
                        </span></button>
                    <?php endif; ?>
                    <?php if ($pill) : ?>
                        <span class="aipkit_usage_pill aipkit_usage_pill--inline <?php echo esc_attr($pill[1]); ?>"><?php echo esc_html($pill[0]); ?></span>
                    <?php endif; ?>
                    <?php if ($top_up) : ?>
                        <?php // The panel's one Top up: one checkout adds AI credits and website actions. ?>
                        <button type="submit" form="aipkit_usage_cloud_checkout" class="aipkit_usage_card_action is-primary" name="cloud_action" value="checkout" data-aipkit-website-topup><?php echo $cart; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static icon markup. ?><?php esc_html_e('Buy credits', 'gpt3-ai-content-generator'); ?><span class="aipkit_spinner" aria-hidden="true"></span></button>
                    <?php endif; ?>
                    <?php if ($enabled) : ?>
                        <button type="button" class="aipkit_usage_card_action" data-aipkit-cloud-open="website" aria-haspopup="dialog" aria-label="<?php esc_attr_e('Manage website', 'gpt3-ai-content-generator'); ?>"><?php echo $gear; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static icon markup. ?><span class="aipkit_usage_card_action_label"><?php esc_html_e('Manage', 'gpt3-ai-content-generator'); ?></span></button>
                    <?php endif; ?>
                </div>
                <?php if ($top_up) : ?>
                    <p class="aipkit_model_sync_status aipkit_cloud_feedback" data-aipkit-cloud-feedback role="status" aria-live="polite"></p>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($invite) : ?>
                <?php // Not set up: the free allowance as the number, an empty bar, one line, and the setup buttons where Top
                // up goes. Each button opens the website sheet and starts that assistant's Set up there, or shows the step
                // that comes first (Cloud, verify email). ?>
                <p class="aipkit_usage_card_value aipkit_usage_card_value--offer"><?php echo esc_html(number_format_i18n(2000)); ?><span class="aipkit_usage_card_unit"><?php esc_html_e('free a month', 'gpt3-ai-content-generator'); ?></span></p>
                <div class="aipkit_usage_stack is-empty" aria-hidden="true"></div>
                <p class="aipkit_usage_card_meta"><?php esc_html_e('Ask ChatGPT or Claude to write drafts, update products or design pages.', 'gpt3-ai-content-generator'); ?></p>
                <p role="status" aria-live="polite" data-website-usage-status></p>
                <div class="aipkit_usage_card_foot">
                    <button type="button" class="aipkit_usage_card_action is-primary" data-aipkit-cloud-open="website" data-aipkit-website-start="chatgpt" aria-haspopup="dialog"><?php esc_html_e('Connect ChatGPT', 'gpt3-ai-content-generator'); ?></button>
                    <button type="button" class="aipkit_usage_card_action is-primary" data-aipkit-cloud-open="website" data-aipkit-website-start="claude" aria-haspopup="dialog"><?php esc_html_e('Connect Claude', 'gpt3-ai-content-generator'); ?></button>
                </div>
            <?php elseif (!$enabled) : ?>
                <p role="status" aria-live="polite" data-website-usage-status></p>
            <?php else : ?>
                <p class="aipkit_usage_card_value<?php echo !$usage ? ' aipkit_usage_card_value--empty' : ($out ? ' aipkit_usage_card_value--out' : ($low ? ' aipkit_usage_card_value--low' : '')); ?>"><?php if (!$usage) : ?>—<?php else : ?><?php echo esc_html(number_format_i18n($usage['remaining'])); ?><span class="aipkit_usage_card_unit"><?php
                    if (!empty($usage['purchased_remaining'])) {
                        esc_html_e('left', 'gpt3-ai-content-generator');
                    } else {
                        /* translators: %s: the monthly website-action allowance, for example 2,000. */
                        echo esc_html(sprintf(__('left of %s', 'gpt3-ai-content-generator'), number_format_i18n($usage['monthly_allowance'])));
                    }
                    ?></span><?php endif; ?></p>
                <div class="aipkit_usage_stack<?php echo !$usage ? ' is-empty' : ($low ? ' is-low' : ''); ?>" aria-hidden="true">
                    <?php foreach ($sources as $source) : ?>
                        <?php if ($source['left'] > 0 && $scale > 0) : ?>
                            <span class="aipkit_usage_stack_part" data-tone="<?php echo esc_attr((string) $source['tone']); ?>" style="width:<?php echo esc_attr((string) round($source['left'] / $scale * 100, 2)); ?>%"></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <p class="aipkit_usage_card_meta<?php echo $line['tone'] !== '' ? ' aipkit_usage_card_meta--' . esc_attr($line['tone']) : ''; ?>"><?php echo esc_html($line['text']); ?></p>
                <p role="status" aria-live="polite" data-website-usage-status></p>
                <?php // Resume, only while changes are paused, at the bottom like the setup buttons. It runs the website
                // sheet's own Resume, so the sheet shows the result. ?>
                <?php if ($paused) : ?>
                    <div class="aipkit_usage_card_foot">
                        <button type="button" class="aipkit_usage_card_action" data-aipkit-cloud-open="website" data-aipkit-website-start="resume" aria-haspopup="dialog"><?php esc_html_e('Resume', 'gpt3-ai-content-generator'); ?></button>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php
    }

    /**
     * The WordPress user a new assistant acts as: the current user, or another account an administrator chose (for
     * example a dedicated editor). The chosen account must exist and be able to write posts.
     */
    private static function acting_user(int $id): \WP_User
    {
        if ($id === 0 || $id === get_current_user_id()) { return wp_get_current_user(); }
        $user = get_userdata($id);
        if (!current_user_can('manage_options') || !$user || !user_can($user, 'edit_posts')) { throw new \RuntimeException('invalid_input'); }
        return $user;
    }

    /** Shared by Settings and directory entry; callers must validate their own WordPress nonce. */
    public static function create_pair(string $level, string $client, \WP_User $acting_user): array
    {
        if (!Connection::allowed() || !in_array($level, Registry::LEVELS, true) || !in_array($client, ['chatgpt', 'claude', 'other'], true)) { throw new \RuntimeException('invalid_input'); }
        if (self::connection_issue() !== '') { throw new \RuntimeException('connection_unavailable'); }
        global $wpdb;
        $grants_table = Schema::table('grants');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read current assistant state for authorization; Schema allowlists tables and IDs are prepared; cached grants could retain revoked access.
        if (Schema::ready() && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $grants_table WHERE instance_id = %s AND state = 'pending' AND created_at > %d", Identity::registration()['instance_id'] ?? '', time() - 600)) >= 5) { throw new \RuntimeException('pending_limit'); }
        $cloud = Connection::display();
        if (!empty($cloud['connected']) && ($cloud['status'] ?? '') === 'unavailable') { throw new \RuntimeException('identity_check_unavailable'); }
        if (Store::site_token() === '') { throw new \RuntimeException('cloud_required'); }
        if (Connection::display()['email_verified'] !== true) { throw new \RuntimeException('verification_required'); }
        Identity::enable(); Store::record_consent(get_current_user_id());
        $config = Store::config();
        $expect = isset($config['account_id'], $config['site_id']) ? ['account_id' => $config['account_id'], 'site_id' => $config['site_id']] : null;
        $result = BridgeClient::register($expect);
        if (is_wp_error($result)) { throw new \RuntimeException('connection_unavailable'); }
        $pair = BridgeClient::pair($level, $client, $acting_user);
        if (is_wp_error($pair)) { throw new \RuntimeException('connection_unavailable'); }
        return $pair;
    }

    /** Status-only reconciliation for an existing pairing; never changes safety levels or starts access. */
    public static function pending_status(string $id)
    {
        if (!Connection::allowed() || !Identity::active()) { return new \WP_Error('invalid_input'); }
        $grant = Store::grant($id);
        if (!$grant
            || $grant['instance_id'] !== (Identity::registration()['instance_id'] ?? null)) {
            return new \WP_Error('invalid_input');
        }
        if ($grant['state'] !== 'pending') { return ['state' => $grant['state']]; }
        if ((int) $grant['created_at'] <= time() - 600) { return ['state' => 'expired']; }
        return BridgeClient::status($id);
    }

    public static function handle(): void
    {
        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        if ($method !== 'POST' || !Connection::allowed()
            || !check_ajax_referer('aipkit_mcp_settings', '_wpnonce', false)) {
            wp_send_json_error(['message' => __('You cannot manage website access. Reload Settings and try again.', 'gpt3-ai-content-generator')], 403);
        }
        $action = isset($_POST['mcp_action']) && is_string($_POST['mcp_action']) ? sanitize_key(wp_unslash($_POST['mcp_action'])) : '';
        $surface = isset($_POST['surface']) && is_string($_POST['surface']) ? sanitize_key(wp_unslash($_POST['surface'])) : '';
        if ($action === 'diagnostics') {
            $report = self::run_connection_check();
            if ($surface === 'onboarding') {
                ob_start(); self::render_connection_check(true); $html = ob_get_clean();
                wp_send_json_success(['websiteBlocked' => self::connection_blocked(), 'addressBlocked' => self::connection_issue() !== '', 'diagnosticHtml' => $html, 'ready' => $report['status'] === 'passed', 'rows' => $report['rows']]);
            }
            ob_start(); self::render(); $html = ob_get_clean();
            wp_send_json_success(['html' => $html, 'message' => '', 'websiteBlocked' => self::connection_blocked(), 'addressBlocked' => self::connection_issue() !== '']);
        }
        if ($action === 'connect') {
            $address_issue = self::connection_issue();
            if ($address_issue !== '') { wp_send_json_error(['message' => $address_issue], 409); }
        }
        try {
            foreach (['client', 'access_level', 'grant_id'] as $field) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reject any normalization of exact enums or grant IDs; sanitization must not retarget access changes.
                if (isset($_POST[$field]) && (!is_string($_POST[$field]) || wp_unslash($_POST[$field]) !== sanitize_text_field(wp_unslash($_POST[$field])))) { throw new \RuntimeException('invalid_input'); }
            }
            if ($action === 'assistant_status') {
                $id = isset($_POST['grant_id']) && is_string($_POST['grant_id']) ? sanitize_text_field(wp_unslash($_POST['grant_id'])) : '';
                $status = self::pending_status($id);
                if (is_wp_error($status)) { throw new \RuntimeException($status->get_error_code()); }
                wp_send_json_success($status);
            }
            if ($action === 'usage') {
                $usage = self::usage_data(true);
                if (Identity::active() && $usage === null) { throw new \RuntimeException('usage_unavailable'); }
                ob_start(); self::render_usage($usage); $card = ob_get_clean();
                wp_send_json_success(['enabled' => Identity::active(), 'card' => $card] + ($surface === 'onboarding' ? ['allowanceOffer' => \WPAICG\Admin\Onboarding::allowance_offer()] : []));
            }
            $message = '';
            $pair = null;
            if ($action === 'connect') {
                // One click uses the default safety level; the level can be chosen here or changed later per assistant.
                $level = isset($_POST['access_level']) && is_string($_POST['access_level']) && $_POST['access_level'] !== '' ? sanitize_text_field(wp_unslash($_POST['access_level'])) : Registry::DEFAULT_LEVEL;
                $client = isset($_POST['client']) && is_string($_POST['client']) ? sanitize_text_field(wp_unslash($_POST['client'])) : '';
                if (!in_array($client, ['chatgpt', 'claude', 'other'], true) || !in_array($level, Registry::LEVELS, true)) { throw new \RuntimeException('invalid_input'); }
                if (isset($_POST['act_as']) && !is_string($_POST['act_as']) && !is_int($_POST['act_as'])) { throw new \RuntimeException('invalid_input'); }
                $acting_user = self::acting_user(isset($_POST['act_as']) ? absint(wp_unslash($_POST['act_as'])) : 0);
                $pair = self::create_pair($level, $client, $acting_user);
                $message = __('Finish connecting in your assistant, then return here. Your connection updates automatically; select Check if needed.', 'gpt3-ai-content-generator');
            } elseif ($action === 'level') {
                $id = isset($_POST['grant_id']) && is_string($_POST['grant_id']) ? sanitize_text_field(wp_unslash($_POST['grant_id'])) : '';
                $level = isset($_POST['access_level']) && is_string($_POST['access_level']) ? sanitize_text_field(wp_unslash($_POST['access_level'])) : '';
                $changed = Levels::change($id, $level);
                $message = $changed['state'] === 'synced'
                    /* translators: 1: assistant name, such as "Claude". 2: safety level, such as "Trusted". */
                    ? sprintf(__('%1$s now has the %2$s safety level.', 'gpt3-ai-content-generator'), ucfirst(Screens::client_label(Store::grant($id))), Registry::level_label($level))
                    /* translators: %s: safety level, such as "Trusted". */
                    : sprintf(__('The bridge hasn’t confirmed %s yet. This website already uses the safer of the two levels. Choose it again or select Check to finish.', 'gpt3-ai-content-generator'), Registry::level_label($level));
            } elseif ($action === 'disconnect') {
                global $wpdb;
                $grants_table = Schema::table('grants');
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read current assistant state for authorization; Schema allowlists tables and IDs are prepared; cached grants could retain revoked access.
                $ids = $wpdb->get_col($wpdb->prepare("SELECT grant_id FROM $grants_table WHERE instance_id = %s AND state != 'revoked' ORDER BY created_at DESC", Identity::registration()['instance_id'] ?? ''));
                $retry = Store::update_revocations(static function (array $pending) use ($ids): array {
                    foreach ($ids as $id) { $pending[$id] = true; }
                    return $pending;
                });
                Identity::end('settings_disabled'); // Fence locally before waiting on any network.
                $deadline = microtime(true) + 12;
                // Bound network work, not retained evidence: pending IDs are never silently discarded.
                foreach (array_slice(array_keys($retry), 0, 20) as $id) {
                    $remaining = $deadline - microtime(true);
                    if ($remaining < 0.1) { break; }
                    if (!is_wp_error(BridgeClient::revoke($id, min(3, $remaining)))) {
                        Store::update_revocations(static function (array $pending) use ($id): array { unset($pending[$id]); return $pending; });
                    }
                }
                // Disabled registration is the authority fence. Do not clear Cloud fields after I/O:
                // another request may have already connected a newer registration.
                $message = __('Website access is off. Existing assistants cannot make new requests. Your Cloud account remains connected.', 'gpt3-ai-content-generator');
            } elseif (in_array($action, ['pause', 'resume'], true)) {
                global $wpdb;
                // Pausing only restricts local access; resuming still requires a verified active identity.
                if (!Identity::active() && !($action === 'pause' && !empty(Identity::registration()['enabled']))) { throw new \RuntimeException('connection_required'); }
                update_option('aipkit_mcp_settings', ['paused' => $action === 'pause'], false);
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read current assistant state for authorization; Schema allowlists tables and IDs are prepared; cached grants could retain revoked access.
                $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'aipkit_mcp_settings'));
                if ((maybe_unserialize($raw)['paused'] ?? null) !== ($action === 'pause')) { throw new \RuntimeException('mcp_storage_unavailable'); }
                $message = $action === 'pause' ? __('Website changes paused. Reading remains available.', 'gpt3-ai-content-generator') : __('Website changes resumed.', 'gpt3-ai-content-generator');
            } elseif (in_array($action, ['recheck', 'revoke'], true)) {
                $id = isset($_POST['grant_id']) && is_string($_POST['grant_id']) ? sanitize_text_field(wp_unslash($_POST['grant_id'])) : '';
                $grant = Store::grant($id);
                if (!$grant || $grant['instance_id'] !== (Identity::registration()['instance_id'] ?? null)) { throw new \RuntimeException('invalid_input'); }
                if ($action === 'revoke') { Store::update_revocations(static function (array $pending) use ($id): array { $pending[$id] = true; return $pending; }); }
                if ($action === 'revoke') { Levels::forget($id); }
                // Check also finishes a safety-level change the bridge hasn't confirmed.
                $level_sync = $action === 'recheck' ? Levels::reconcile($id) : null;
                $result = $action === 'revoke' ? BridgeClient::revoke($id) : BridgeClient::status($id);
                if (is_wp_error($result)) {
                    if ($result->get_error_code() === 'mcp_storage_unavailable') { throw new \RuntimeException('mcp_storage_unavailable'); }
                    if ($action !== 'revoke') { throw new \RuntimeException('connection_unavailable'); }
                    $message = __('Access revoked on this website. The bridge could not be reached; retry Disconnect for that assistant.', 'gpt3-ai-content-generator');
                } else { if ($action === 'revoke') { Store::update_revocations(static function (array $pending) use ($id): array { unset($pending[$id]); return $pending; }); } $message = __('Connection updated.', 'gpt3-ai-content-generator');
                    // After Check, say plainly whether the assistant finished connecting.
                    $after = $action === 'recheck' ? Store::grant($id) : null;
                    if ($after && $after['state'] === 'active' && $grant['state'] === 'pending') {
                        /* translators: %s: assistant name, such as "Claude". */
                        $message = sprintf(__('%s is connected. Go back to it and try “List my five latest drafts”.', 'gpt3-ai-content-generator'), ucfirst(Screens::client_label($after)));
                    } elseif ($after && $after['state'] === 'pending') {
                        /* translators: %s: assistant name, such as "Claude". */
                        $message = sprintf(__('Still waiting for you to choose Allow in %s.', 'gpt3-ai-content-generator'), Screens::client_label($after));
                    }
                    if (is_array($level_sync)) {
                        $message .= ' ' . ($level_sync['state'] === 'synced'
                            /* translators: %s: safety level, such as "Read only". */
                            ? sprintf(__('The %s safety level is now confirmed.', 'gpt3-ai-content-generator'), Registry::level_label($level_sync['level']))
                            /* translators: %s: safety level, such as "Read only". */
                            : sprintf(__('The bridge still hasn’t confirmed the %s safety level; this website keeps the safer level.', 'gpt3-ai-content-generator'), Registry::level_label($level_sync['level'])));
                    } }
            } elseif ($action !== 'view') { throw new \RuntimeException('invalid_input'); }
            if ($surface === 'onboarding') {
                if (in_array($action, ['connect', 'recheck'], true) && Identity::active()) { self::usage_data(true); }
                wp_send_json_success(['assistants' => self::onboarding_connections(), 'message' => $message, 'pair' => $pair, 'allowanceOffer' => \WPAICG\Admin\Onboarding::allowance_offer()]);
            }
            ob_start(); self::render(); $html = ob_get_clean();
            wp_send_json_success(['html' => $html, 'message' => $message, 'pair' => $pair]);
        } catch (\Throwable $error) {
            $messages = [
                'mcp_crypto_unavailable' => __('Your hosting needs the PHP Sodium extension to connect website management. Ask your host to enable it.', 'gpt3-ai-content-generator'),
                'mcp_schema_unavailable' => __('Website management needs InnoDB tables with the required indexes. Ask your host to check the database configuration.', 'gpt3-ai-content-generator'),
                'mcp_storage_unavailable' => __('Website access could not be updated. Check the connection status before continuing.', 'gpt3-ai-content-generator'),
                'mcp_storage_busy' => __('Another change to website access is still running. Try again in a moment.', 'gpt3-ai-content-generator'),
                'pending_limit' => __('Finish or disconnect an existing pending connection before adding another.', 'gpt3-ai-content-generator'),
                'cloud_required' => __('Connect your Cloud account first.', 'gpt3-ai-content-generator'),
                'identity_check_unavailable' => Connection::verification_message('identity_check_unavailable'),
                'verification_required' => __('Verify your email in your Cloud account, then check again.', 'gpt3-ai-content-generator'),
                'invalid_input' => __('Choose an assistant, a safety level and a WordPress user who can write posts.', 'gpt3-ai-content-generator'),
                'usage_unavailable' => __('Could not update website actions. Try Refresh.', 'gpt3-ai-content-generator'),
                'connection_required' => __('Connect website management first.', 'gpt3-ai-content-generator'),
            ];
            wp_send_json_error(['message' => $messages[$error->getMessage()] ?? __('The connection could not be completed. Check your Cloud account and try again.', 'gpt3-ai-content-generator')], 409);
        }
    }
}
