<?php
/** A stale network response must never restore access after disconnect or removal. */
namespace WPAICG\Mcp;

use RuntimeException;
use WPAICG\Cloud\Connection;

if (!defined('ABSPATH')) { exit; }

final class Identity
{
    public const OPTION = 'aipkit_mcp_registration';

    public static function registration(): array
    {
        $value = get_option(self::OPTION, []);
        return is_array($value) ? $value : [];
    }

    private static function change(callable $merge): array
    {
        global $wpdb;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic registration fencing requires live database state; table names are WordPress-owned and values are prepared.
            $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION));
            $before = $raw === null ? [] : maybe_unserialize($raw);
            if (!is_array($before)) { throw new RuntimeException('mcp_storage_unreadable'); }
            $after = $merge($before);
            if ($raw === null) {
                $changed = add_option(self::OPTION, $after, '', false) ? 1 : 0;
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic registration fencing requires live database state; table names are WordPress-owned and values are prepared.
                $changed = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", maybe_serialize($after), self::OPTION, $raw));
            }
            if ($changed === false) { throw new RuntimeException('mcp_storage_unavailable'); }
            if ($changed === 1) {
                wp_cache_delete(self::OPTION, 'options');
                wp_cache_delete('notoptions', 'options');
                return $after;
            }
        }
        throw new RuntimeException('mcp_storage_busy');
    }

    public static function reserve(): array
    {
        return self::change(static function (array $before): array {
            if (empty($before['enabled']) || ($before['consent_version'] ?? '') !== Store::CONSENT_VERSION) {
                throw new RuntimeException('mcp_consent_required');
            }
            $before['epoch'] = (int) ($before['epoch'] ?? 0) + 1;
            return $before;
        });
    }

    /** Called only after authenticated owner consent; no inference-provider prerequisite. */
    public static function enable(): void
    {
        if (!Connection::allowed() || Store::site_token() === '') { throw new RuntimeException('permission_denied'); }
        if (!function_exists('sodium_crypto_sign_detached')) { throw new RuntimeException('mcp_crypto_unavailable'); }
        Schema::install();
        if (!Schema::ready()) { throw new RuntimeException('mcp_schema_unavailable'); }
        self::change(static function (array $before): array {
            return ['instance_id' => $before['instance_id'] ?? wp_generate_uuid4(),
                'epoch' => (int) ($before['epoch'] ?? 0) + 1, 'enabled' => true,
                'consent_version' => Store::CONSENT_VERSION];
        });
    }

    public static function active(): bool
    {
        try {
            $registration = self::registration();
            $config = Store::config();
            $token = Store::site_token();
            return !empty($registration['enabled']) && ($registration['consent_version'] ?? '') === Store::CONSENT_VERSION
                && ($config['instance_id'] ?? '') === ($registration['instance_id'] ?? null)
                && $token !== '' && hash_equals((string) ($config['registered_token_hash'] ?? ''), hash('sha256', $token));
        } catch (\Throwable $error) { return false; }
    }

    public static function end(string $reason = 'disconnected'): void
    {
        global $wpdb;
        if (self::registration() === []) { return; }
        self::change(static function (array $before): array {
            $before['enabled'] = false;
            $before['epoch'] = (int) ($before['epoch'] ?? 0) + 1;
            return $before;
        });
        // Nothing left to report or keep in step once website management is off.
        update_option(Registry::REPORTED_OPTION, '', true); // Disconnected: nothing to report.
        delete_option(Levels::OPTION);
        delete_option(Levels::ATTEMPTS_OPTION);
        // Stop locally before any best-effort network notification. Evidence tables stay.
        if (Schema::ready()) {
            $grants_table = Schema::table('grants');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Atomic registration fencing requires live database state; table names are WordPress-owned and values are prepared.
            if ($wpdb->query("UPDATE $grants_table SET state = 'revoked' WHERE state != 'revoked'") === false) {
                throw new RuntimeException('mcp_storage_unavailable');
            }
        }
        // Settings disable retains encrypted callback custody only while notifying
        // the bridge. Disabled registration and revoked grants remain authoritative.
        if ($reason !== 'settings_disabled') { try {
            Connection::update(static function (array $state): array { unset($state['mcp']); return $state; });
        } catch (\Throwable $error) {
            // Disabled registration remains the local authority fence even without readable custody.
        }
        }
        if ($reason === 'uninstalled') { delete_option(self::OPTION); }
    }

    public static function credential_ending(string $reason): void
    {
        try { self::end($reason); }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Production cleanup failure must remain observable; fixed message contains no credentials or user content.
        catch (\Throwable $error) { error_log('AI Puffer MCP: local access cleanup failed.'); }
    }
}
