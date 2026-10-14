<?php
/** Explicit owner actions; never part of ordinary settings autosave or onboarding. */
namespace WPAICG\Mcp;
use WPAICG\Cloud\Connection;
if (!defined('ABSPATH')) { exit; }
final class Settings
{
    public static function register(): void { add_action('wp_ajax_aipkit_mcp_settings', [self::class, 'handle']); }
    public static function render(): void { include dirname(__DIR__, 2) . '/admin/views/settings/manage-website.php'; }
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
        if (!array_key_exists('period_ends_at', $usage)) { return null; }
        if ($usage['period_ends_at'] !== null && (!is_int($usage['period_ends_at']) || $usage['period_ends_at'] <= $usage['as_of'])) { return null; }
        // Keep one identity-bound last-known balance for instant rendering, not authorization.
        update_option('aipkit_mcp_usage_snapshot', ['key' => $key, 'usage' => $usage], false);
        return $usage;
    }

    /** Shared free/premium renderer. No network requests while rendering a page. */
    public static function render_usage(string $surface = 'usage', ?array $usage = null): void
    {
        $enabled = Connection::allowed() && Identity::active();
        $usage = $usage ?? ($enabled ? self::usage_data() : null);
        $panel = $surface === 'panel';
        $checked_label = '';
        if ($usage) {
            /* translators: %s: date and time the balance was checked. */
            $checked_label = sprintf(__('Checked %s.', 'gpt3-ai-content-generator'), wp_date(get_option('date_format') . ' ' . get_option('time_format'), $usage['as_of']));
        }
        ?>
        <section data-aipkit-website-usage="<?php echo esc_attr($surface); ?>" data-mcp-enabled="<?php echo $enabled ? '1' : '0'; ?>" class="<?php echo $panel ? 'aipkit_cloud_panel_group' : 'aipkit_usage_card'; ?>" <?php echo $enabled ? '' : 'hidden'; ?>>
            <input type="hidden" data-website-usage-nonce value="<?php echo esc_attr(wp_create_nonce('aipkit_mcp_settings')); ?>">
            <?php if ($panel) : ?>
                <?php // The same layout as AI credits: the balance with its action, then where it stands. ?>
                <h3 class="aipkit_settings_provider_panel_section_title"><?php esc_html_e('Website actions', 'gpt3-ai-content-generator'); ?></h3>
                <div class="aipkit_cloud_balance">
                    <p class="aipkit_cloud_balance_value" <?php if ($usage) : ?>title="<?php echo esc_attr($checked_label); ?>"<?php endif; ?>><strong data-website-remaining><?php echo $usage ? esc_html(number_format_i18n($usage['remaining'])) : '—'; ?></strong> <span><?php esc_html_e('actions remaining', 'gpt3-ai-content-generator'); ?></span></p>
                    <div class="aipkit_cloud_balance_actions">
                        <button type="button" class="aipkit_btn" data-website-usage-refresh aria-label="<?php esc_attr_e('Refresh website actions', 'gpt3-ai-content-generator'); ?>"><?php esc_html_e('Refresh', 'gpt3-ai-content-generator'); ?></button>
                    </div>
                </div>
                <?php if ($usage) : ?>
                    <dl class="aipkit_cloud_breakdown">
                        <div><dt><?php esc_html_e('Used this period', 'gpt3-ai-content-generator'); ?></dt><dd><?php echo esc_html(number_format_i18n($usage['used'])); ?></dd></div>
                        <div><dt><?php esc_html_e('Monthly allowance', 'gpt3-ai-content-generator'); ?></dt><dd><?php echo esc_html(number_format_i18n($usage['monthly_allowance'])); ?></dd></div>
                        <?php if ($usage['in_use'] > 0) : ?>
                            <div><dt><?php esc_html_e('In use by running requests', 'gpt3-ai-content-generator'); ?></dt><dd><?php echo esc_html(number_format_i18n($usage['in_use'])); ?></dd></div>
                        <?php endif; ?>
                        <div><dt><?php esc_html_e('Renews', 'gpt3-ai-content-generator'); ?></dt><dd><?php echo $usage['period_ends_at'] ? esc_html(wp_date(get_option('date_format'), $usage['period_ends_at'])) : esc_html__('After your first assistant connection', 'gpt3-ai-content-generator'); ?></dd></div>
                    </dl>
                <?php else : ?>
                    <p class="aipkit_settings_provider_option_help"><?php esc_html_e('Balance unavailable. Refresh to check again.', 'gpt3-ai-content-generator'); ?></p>
                <?php endif; ?>
                <p class="aipkit_settings_provider_option_help"><?php echo esc_html(trim(__('Shared across your connected websites. Separate from AI credits.', 'gpt3-ai-content-generator') . ' ' . $checked_label)); ?></p>
                <p role="status" aria-live="polite" data-website-usage-status></p>
            <?php else : ?>
                <div class="aipkit_usage_card_head"><h2 class="aipkit_usage_card_title"><?php esc_html_e('Website actions', 'gpt3-ai-content-generator'); ?></h2></div>
                <p class="aipkit_usage_card_value" <?php if ($usage) : ?>title="<?php echo esc_attr($checked_label); ?>"<?php endif; ?>><?php echo $usage ? esc_html(number_format_i18n($usage['remaining'])) : '—'; ?><span class="aipkit_usage_card_unit"> <?php esc_html_e('remaining', 'gpt3-ai-content-generator'); ?></span></p>
                <p class="aipkit_usage_card_meta"><?php if ($usage) {
                    /* translators: 1: actions used, 2: monthly allowance. */
                    echo esc_html(sprintf(__('%1$s used · %2$s / month', 'gpt3-ai-content-generator'), number_format_i18n($usage['used']), number_format_i18n($usage['monthly_allowance'])));
                } else { esc_html_e('Balance unavailable. Refresh to check again.', 'gpt3-ai-content-generator'); } ?></p>
                <p class="aipkit_usage_card_meta aipkit_usage_card_meta--quiet"><?php
                    if ($usage && $usage['period_ends_at']) { /* translators: %s: the website-action renewal date. */ echo esc_html(sprintf(__('Renews %s', 'gpt3-ai-content-generator'), wp_date(_x('M j', 'short renewal date', 'gpt3-ai-content-generator'), $usage['period_ends_at']))); }
                    elseif ($usage) { esc_html_e('Renewal starts after your first assistant connection.', 'gpt3-ai-content-generator'); }
                ?></p>
                <p role="status" aria-live="polite" data-website-usage-status></p>
                <div class="aipkit_usage_card_actions">
                    <button type="button" class="aipkit_usage_btn" data-aipkit-cloud-open="website" aria-haspopup="dialog"><?php esc_html_e('Manage website', 'gpt3-ai-content-generator'); ?></button>
                    <button type="button" class="aipkit_usage_icon_btn" data-website-usage-refresh aria-label="<?php esc_attr_e('Refresh website actions', 'gpt3-ai-content-generator'); ?>" title="<?php esc_attr_e('Refresh', 'gpt3-ai-content-generator'); ?>"><span class="dashicons dashicons-update" aria-hidden="true"></span></button>
                </div>
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

    public static function handle(): void
    {
        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        if ($method !== 'POST' || !Connection::allowed()
            || !check_ajax_referer('aipkit_mcp_settings', '_wpnonce', false)) {
            wp_send_json_error(['message' => __('You cannot manage website access. Reload Settings and try again.', 'gpt3-ai-content-generator')], 403);
        }
        $action = isset($_POST['mcp_action']) && is_string($_POST['mcp_action']) ? sanitize_key(wp_unslash($_POST['mcp_action'])) : '';
        try {
            foreach (['consent', 'client', 'access_level', 'grant_id'] as $field) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reject any normalization of exact enums, consent or grant IDs; sanitization must not retarget access changes.
                if (isset($_POST[$field]) && (!is_string($_POST[$field]) || wp_unslash($_POST[$field]) !== sanitize_text_field(wp_unslash($_POST[$field])))) { throw new \RuntimeException('invalid_input'); }
            }
            if ($action === 'usage') {
                $usage = self::usage_data(true);
                if (Identity::active() && $usage === null) { throw new \RuntimeException('usage_unavailable'); }
                ob_start(); self::render_usage('usage', $usage); $card = ob_get_clean();
                ob_start(); self::render_usage('panel', $usage); $panel = ob_get_clean();
                wp_send_json_success(['enabled' => Identity::active(), 'card' => $card, 'panel' => $panel]);
            }
            $message = '';
            $pair = null;
            if ($action === 'connect') {
                if (!isset($_POST['consent']) || !is_string($_POST['consent']) || sanitize_text_field(wp_unslash($_POST['consent'])) !== 'yes') { throw new \RuntimeException('consent_required'); }
                // One click uses the default safety level; the level can be chosen here or changed later per assistant.
                $level = isset($_POST['access_level']) && is_string($_POST['access_level']) && $_POST['access_level'] !== '' ? sanitize_text_field(wp_unslash($_POST['access_level'])) : Registry::DEFAULT_LEVEL;
                $client = isset($_POST['client']) && is_string($_POST['client']) ? sanitize_text_field(wp_unslash($_POST['client'])) : '';
                if (!in_array($client, ['chatgpt', 'claude', 'other'], true) || !in_array($level, Registry::LEVELS, true)) { throw new \RuntimeException('invalid_input'); }
                if (isset($_POST['act_as']) && !is_string($_POST['act_as']) && !is_int($_POST['act_as'])) { throw new \RuntimeException('invalid_input'); }
                $acting_user = self::acting_user(isset($_POST['act_as']) ? absint(wp_unslash($_POST['act_as'])) : 0);
                global $wpdb;
                $grants_table = Schema::table('grants');
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read current assistant state for authorization; Schema allowlists tables and IDs are prepared; cached grants could retain revoked access.
                if (Schema::ready() && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $grants_table WHERE instance_id = %s AND state = 'pending' AND created_at > %d", Identity::registration()['instance_id'] ?? '', time() - 600)) >= 5) { throw new \RuntimeException('pending_limit'); }
                if (Store::site_token() === '') { throw new \RuntimeException('cloud_required'); }
                if (Connection::display()['email_verified'] !== true) { throw new \RuntimeException('verification_required'); }
                Identity::enable(); Store::record_consent(get_current_user_id());
                $config = Store::config();
                $expect = isset($config['account_id'], $config['site_id']) ? ['account_id' => $config['account_id'], 'site_id' => $config['site_id']] : null;
                $result = BridgeClient::register($expect);
                if (is_wp_error($result)) { throw new \RuntimeException('connection_unavailable'); }
                $pair = BridgeClient::pair($level, $client, $acting_user);
                if (is_wp_error($pair)) { throw new \RuntimeException('connection_unavailable'); }
                $message = __('Continue in the connection link below, then return here and check the connection.', 'gpt3-ai-content-generator');
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
                if (!Identity::active()) { throw new \RuntimeException('connection_required'); }
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
            ob_start(); self::render(); $html = ob_get_clean();
            wp_send_json_success(['html' => $html, 'message' => $message, 'pair' => $pair]);
        } catch (\Throwable $error) {
            $messages = [
                'mcp_crypto_unavailable' => __('Your hosting needs the PHP Sodium extension to connect website management. Ask your host to enable it.', 'gpt3-ai-content-generator'),
                'mcp_schema_unavailable' => __('Website management needs InnoDB tables with the required indexes. Ask your host to check the database configuration.', 'gpt3-ai-content-generator'),
                'mcp_storage_unavailable' => __('Website access could not be updated. Check the connection status before continuing.', 'gpt3-ai-content-generator'),
                'mcp_storage_busy' => __('Another change to website access is still running. Try again in a moment.', 'gpt3-ai-content-generator'),
                'pending_limit' => __('Finish or disconnect an existing pending connection before adding another.', 'gpt3-ai-content-generator'),
                'consent_required' => __('Agree to website management before connecting.', 'gpt3-ai-content-generator'),
                'cloud_required' => __('Connect your Cloud account first.', 'gpt3-ai-content-generator'),
                'verification_required' => __('Verify your email in your Cloud account, then check again.', 'gpt3-ai-content-generator'),
                'invalid_input' => __('Choose an assistant, a safety level and a WordPress user who can write posts.', 'gpt3-ai-content-generator'),
                'usage_unavailable' => __('Could not update website actions. Try Refresh.', 'gpt3-ai-content-generator'),
                'connection_required' => __('Connect website management first.', 'gpt3-ai-content-generator'),
            ];
            wp_send_json_error(['message' => $messages[$error->getMessage()] ?? __('The connection could not be completed. Check your Cloud account and try again.', 'gpt3-ai-content-generator')], 409);
        }
    }
}
