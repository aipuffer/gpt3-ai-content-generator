<?php
/** An operation ID is never executed twice, including after a lost response. */
namespace WPAICG\Mcp;

if (!defined('ABSPATH')) { exit; }

final class Journal
{
    /**
     * A finished row is kept this long after it finished (updated_at: completion, approval, expiry or the owner's
     * review), and after confirmed bridge settlement for changes (reported_at). Unresolved, pending and
     * unacknowledged changes remain held. Reads can expire without a settlement acknowledgement.
     */
    public const RETENTION_DAYS = 90;
    private const FINISHED = ['succeeded', 'failed', 'conflict', 'declined', 'expired', 'fenced'];

    public static function get(string $id): ?array
    {
        global $wpdb;
        $table = Schema::table('operations');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uncached atomic journal/nonce state; table names are allowlisted by Schema and every external value is prepared.
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE operation_id = %s", $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function nonce(string $nonce): bool
    {
        global $wpdb;
        $table = Schema::table('nonces');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uncached atomic journal/nonce state; table names are allowlisted by Schema and every external value is prepared.
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE expires_at < %d LIMIT 100", time()));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uncached atomic journal/nonce state; table names are allowlisted by Schema and every external value is prepared.
        return $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $table (nonce, expires_at) VALUES (%s, %d)", $nonce, time() + 600)) === 1;
    }

    public static function fence(string $id, object $body, array $grant): bool
    {
        global $wpdb;
        $table = Schema::table('operations');
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uncached atomic journal/nonce state; table names are allowlisted by Schema and every external value is prepared.
        return $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $table
            (operation_id, account_id, site_id, instance_id, connection_id, acting_user, grant_id, tool, intent_hash, args, state, response, created_at, updated_at)
            VALUES (%s, %s, %s, %s, %s, %d, %s, '', '', '{}', 'fenced', %s, %d, %d)",
            $id, $body->account_id, $body->site_id, $grant['instance_id'], $body->connection_id,
            $body->acting_user, $grant['grant_id'], wp_json_encode(['state' => 'fenced', 'actions_used' => 0]), time(), time())) === 1;
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public static function expire(array $row): array
    {
        if ($row['state'] === 'pending_approval' && (int) $row['expires_at'] <= time() && self::lock()) {
            try {
                if (self::claim($row['operation_id'], 'pending_approval')) {
                    self::finish($row['operation_id'], ['state' => 'expired', 'actions_used' => 0]);
                }
                $row = self::get($row['operation_id']) ?? $row;
            } finally { self::unlock(); }
        }
        return $row;
    }

    public static function same(array $row, object $body, array $grant): bool
    {
        foreach (['account_id', 'site_id', 'connection_id', 'tool', 'intent_hash'] as $key) {
            if (!hash_equals((string) $row[$key], (string) $body->$key)) { return false; }
        }
        return (int) $row['acting_user'] === $body->acting_user && $row['grant_id'] === $grant['grant_id'];
    }

    public static function receive(object $body, array $grant): bool
    {
        global $wpdb;
        $table = Schema::table('operations');
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uncached atomic journal/nonce state; table names are allowlisted by Schema and every external value is prepared.
        return $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $table
            (operation_id, account_id, site_id, instance_id, connection_id, acting_user, grant_id, tool, intent_hash, args, state, created_at, updated_at)
            VALUES (%s, %s, %s, %s, %s, %d, %s, %s, %s, %s, 'received', %d, %d)",
            $body->operation_id, $body->account_id, $body->site_id, Identity::registration()['instance_id'],
            $body->connection_id, $body->acting_user, $grant['grant_id'], $body->tool, $body->intent_hash,
            wp_json_encode($body->args), time(), time())) === 1 && self::prune();
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Removes finished rows past retention, a bounded batch each time a new operation arrives (no scheduled job): 90
     * days after completion and confirmation. Unacknowledged changes, fences and unresolved rows are not removed.
     * Always true: pruning never refuses work.
     */
    public static function prune(int $limit = 200): bool
    {
        global $wpdb;
        $table = Schema::table('operations');
        $kept = time() - self::RETENTION_DAYS * DAY_IN_SECONDS;
        $reads = array_keys(array_filter(Registry::operations(), static function (array $operation): bool { return $operation['risk'] === 'read'; }));
        // Only generated placeholders are interpolated; every state is a prepared value.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Uncached atomic journal/nonce state; table names are allowlisted by Schema and every external value is prepared.
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE state IN (" . implode(',', array_fill(0, count(self::FINISHED), '%s')) . ")
            AND updated_at < %d AND reported_at < %d AND (reported_at > 0 OR tool IN (" . implode(',', array_fill(0, count($reads), '%s')) . ")) LIMIT %d",
            array_merge(self::FINISHED, [$kept, $kept], $reads, [$limit])));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        return true;
    }

    /** A read's local response timestamp. Sending a write response is not proof of bridge settlement. */
    public static function reported(string $id): void
    {
        global $wpdb;
        $table = Schema::table('operations');
        $reads = array_keys(array_filter(Registry::operations(), static function (array $operation): bool { return $operation['risk'] === 'read'; }));
        // Only generated placeholders are interpolated; every state is a prepared value.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Uncached atomic journal/nonce state; table names are allowlisted by Schema and every external value is prepared.
        $wpdb->query($wpdb->prepare("UPDATE $table SET reported_at = %d WHERE operation_id = %s AND tool IN (" . implode(',', array_fill(0, count($reads), '%s')) . ") AND state IN (" . implode(',', array_fill(0, count(self::FINISHED), '%s')) . ')',
            array_merge([time(), $id], $reads, self::FINISHED)));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
    }

    /** Signed bridge confirmation is bound to the exact terminal outcome and action count. */
    public static function acknowledge(array $row, object $receipt): bool
    {
        $response = self::response($row);
        if (!in_array($row['state'], self::FINISHED, true) || ($receipt->state ?? null) !== $row['state']
            || !is_int($receipt->actions_used ?? null) || $receipt->actions_used !== ($response['actions_used'] ?? 0)) { return false; }
        global $wpdb;
        $table = Schema::table('operations');
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uncached atomic journal/nonce state; table names are allowlisted by Schema and every external value is prepared.
        $changed = $wpdb->query($wpdb->prepare("UPDATE $table SET reported_at = %d WHERE operation_id = %s AND state = %s AND updated_at = %d AND reported_at = 0",
            time(), $row['operation_id'], $row['state'], $row['updated_at']));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($changed === false) { return false; }
        $current = self::get($row['operation_id']);
        return $current && $current['state'] === $row['state'] && (int) $current['reported_at'] > 0;
    }

    public static function claim(string $id, string $from): bool
    {
        global $wpdb;
        $table = Schema::table('operations');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uncached atomic journal/nonce state; table names are allowlisted by Schema and every external value is prepared.
        return $wpdb->query($wpdb->prepare("UPDATE $table SET state = 'executing', updated_at = %d WHERE operation_id = %s AND state = %s", time(), $id, $from)) === 1;
    }

    public static function unresolved_write(string $except): bool
    {
        global $wpdb;
        $table = Schema::table('operations');
        $writes = Registry::write_names();
        // Only generated placeholders are interpolated; every tool name is a prepared value.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Uncached atomic journal/nonce state; table names are allowlisted by Schema and every external value is prepared.
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE operation_id != %s AND tool IN (" . implode(',', array_fill(0, count($writes), '%s')) . ") AND state IN ('received', 'executing', 'unknown')", array_merge([$except], $writes)));
        return $count === null || (int) $count > 0;
    }

    public static function finish(string $id, array $response): bool
    {
        global $wpdb;
        $table = Schema::table('operations');
        if (!in_array($response['state'] ?? '', ['succeeded', 'failed', 'conflict', 'unknown', 'pending_approval', 'declined', 'expired'], true)) { return false; }
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uncached atomic journal/nonce state; table names are allowlisted by Schema and every external value is prepared.
        return $wpdb->query($wpdb->prepare("UPDATE $table SET state = %s, response = %s, expires_at = %d, updated_at = %d WHERE operation_id = %s AND state = 'executing'",
            $response['state'], wp_json_encode($response), $response['state'] === 'pending_approval' ? time() + 900 : 0, time(), $id)) === 1;
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public static function response(array $row): array
    {
        $response = json_decode((string) ($row['response'] ?? ''), true);
        if (!is_array($response)) {
            $response = ['state' => in_array($row['state'], ['received', 'executing'], true) ? 'unknown' : $row['state'], 'actions_used' => 0];
        }
        return ['operation_id' => $row['operation_id']] + $response;
    }

    /** A local owner decision is recorded separately from an automatic outcome. */
    public static function resolve(array $row, array $response): bool
    {
        global $wpdb;
        $table = Schema::table('operations');
        $response['owner_review'] = ['user_id' => get_current_user_id(), 'at' => time()];
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uncached atomic journal/nonce state; table names are allowlisted by Schema and every external value is prepared.
        return $wpdb->query($wpdb->prepare("UPDATE $table SET state = %s, response = %s, updated_at = %d
            WHERE operation_id = %s AND state = %s AND state IN ('received', 'executing', 'unknown')", $response['state'], wp_json_encode($response), time(), $row['operation_id'], $row['state'])) === 1;
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public static function lock(): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uncached atomic journal/nonce state; table names are allowlisted by Schema and every external value is prepared.
        return (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::lock_name())) === '1';
    }

    public static function unlock(): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uncached atomic journal/nonce state; table names are allowlisted by Schema and every external value is prepared.
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name()));
    }

    private static function lock_name(): string
    {
        global $wpdb;
        return 'aipkit_mcp_' . substr(hash('sha256', DB_NAME . ':' . $wpdb->prefix), 0, 40);
    }
}
