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
        // Uploads run directly from the verified body, never from a later approval. Keep the file identity,
        // not its short-lived bearer download URL, in history. The signed intent hash still binds the full body.
        $stored_args = $body->args;
        if ($body->tool === 'upload_image_file' && is_object($body->args->file ?? null)) {
            $stored_args = clone $body->args;
            $stored_args->file = clone $body->args->file;
            unset($stored_args->file->download_url);
        }
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uncached atomic journal/nonce state; table names are allowlisted by Schema and every external value is prepared.
        return $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $table
            (operation_id, account_id, site_id, instance_id, connection_id, acting_user, grant_id, tool, intent_hash, args, state, created_at, updated_at)
            VALUES (%s, %s, %s, %s, %s, %d, %s, %s, %s, %s, 'received', %d, %d)",
            $body->operation_id, $body->account_id, $body->site_id, Identity::registration()['instance_id'],
            $body->connection_id, $body->acting_user, $grant['grant_id'], $body->tool, $body->intent_hash,
            wp_json_encode($stored_args), time(), time())) === 1 && self::prune();
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
        if (!in_array($row['state'], self::FINISHED, true) || $response['state'] !== $row['state'] || ($receipt->state ?? null) !== $row['state']
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

    public static function write_gate(string $except): ?array
    {
        global $wpdb;
        $table = Schema::table('operations');
        $writes = Registry::write_names();
        // Only generated placeholders are interpolated; every tool name is a prepared value.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Uncached atomic journal/nonce state; table names are allowlisted by Schema and every external value is prepared.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT operation_id FROM $table WHERE operation_id != %s AND tool IN (" . implode(',', array_fill(0, count($writes), '%s')) . ") AND state IN ('received', 'executing', 'unknown') ORDER BY created_at, operation_id LIMIT 1", array_merge([$except], $writes)), ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($rows)) {
            return Operations::error('write_check_unavailable', 'WordPress could not check its change history. Nothing ran and no action was charged. Reads still work. Ask the website owner to check database availability before trying a new change.');
        }
        if (!$rows) { return null; }
        $id = $rows[0]['operation_id'];
        $response = Operations::error('previous_write_needs_review', 'An earlier website change needs the owner’s review. This request did not run and no action was charged. Reads still work. Do not retry changes until the owner checks the original operation in WordPress.');
        $response['error']['blocking_operation_id'] = $id;
        $response['error']['review_url'] = Screens::review_url();
        $response['operation_diagnostics'] = ['blocking_operation_id' => $id];
        return $response;
    }

    /** Durable itemized progress while the parent stays executing; replay never resumes a half-finished set. */
    public static function checkpoint(string $id, array $items): bool
    {
        global $wpdb;
        $table = Schema::table('operations');
        $response = wp_json_encode(['state' => 'unknown', 'actions_used' => 0, 'result' => ['data_json' => wp_json_encode(['items' => $items, 'complete' => false])]]);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Existing allowlisted journal table, prepared values; durable progress must bypass caches.
        $changed = $wpdb->query($wpdb->prepare("UPDATE $table SET response = %s, updated_at = %d WHERE operation_id = %s AND state = 'executing' AND acting_user = %d", $response, time(), $id, get_current_user_id()));
        $row = self::get($id);
        return $changed !== false && $row && $row['state'] === 'executing' && (int) $row['acting_user'] === get_current_user_id() && $row['response'] === $response;
    }

    public static function finish(string $id, array $response): bool
    {
        global $wpdb;
        $table = Schema::table('operations');
        if (!in_array($response['state'] ?? '', ['succeeded', 'failed', 'conflict', 'unknown', 'pending_approval', 'declined', 'expired'], true)) { return false; }
        $encoded = wp_json_encode($response);
        if (!is_string($encoded)) { return false; }
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uncached atomic journal/nonce state; table names are allowlisted by Schema and every external value is prepared.
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $changed = $wpdb->query($wpdb->prepare("UPDATE $table SET state = %s, response = %s, expires_at = %d, updated_at = %d WHERE operation_id = %s AND state = 'executing'",
                $response['state'], $encoded, $response['state'] === 'pending_approval' ? time() + 900 : 0, time(), $id));
            if ($changed === 1) { return true; }
            // A lost acknowledgement is not a lost result. Retry only this save, never the handler,
            // and never overwrite another terminal result or owner review.
            $row = self::get($id);
            if (!$row) { return false; }
            if ($row['state'] === $response['state'] && $row['response'] === $encoded) { return true; }
            if ($row['state'] !== 'executing') { return false; }
        }
        return false;
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public static function response(array $row): array
    {
        $response = json_decode((string) ($row['response'] ?? ''), true);
        if (!is_array($response) || !in_array($response['state'] ?? '', ['succeeded','failed','conflict','unknown','pending_approval','declined','expired','fenced'], true)
            || !is_int($response['actions_used'] ?? null) || $response['actions_used'] < 0
            || ($response['state'] !== $row['state'] && !($row['state'] === 'executing' && $response['state'] === 'unknown'))) {
            $response = self::uncertain(in_array($row['state'], ['received', 'executing'], true) ? 'operation_in_progress' : 'operation_record_invalid');
        } elseif ($response['state'] === 'unknown' && empty($response['error']) && in_array($row['state'], ['received', 'executing'], true)) {
            $response['error'] = self::uncertain('operation_in_progress')['error'];
        }
        unset($response['local_error']); // Detailed ability errors stay in the local journal, including on replay/recovery.
        return ['operation_id' => $row['operation_id']] + $response;
    }

    /** Fixed diagnostic codes only: never export database errors, arguments or exception messages. */
    public static function uncertain(string $code): array
    {
        $messages = [
            'operation_result_not_saved' => 'WordPress completed the handler but could not confirm saving its result.',
            'operation_storage_unavailable' => 'WordPress could not confirm the operation record before execution.',
            'operation_in_progress' => 'The original operation record is still unfinished. It may be running or may have been interrupted.',
            'operation_record_invalid' => 'WordPress found the operation record but its saved result is missing or invalid.',
            'operation_interrupted' => 'WordPress execution was interrupted before a confirmed result was returned.',
            'idempotency_conflict' => 'This operation ID belongs to a different request.',
        ];
        return ['state' => 'unknown', 'actions_used' => 0, 'error' => ['code' => $code,
            'message' => ($messages[$code] ?? 'The original operation has no confirmed result.') . ' Do not repeat a website change; the website owner can inspect its local operation record.']];
    }

    /** A local owner decision is recorded separately from an automatic outcome. */
    public static function resolve(array $row, array $response): bool
    {
        global $wpdb;
        $table = Schema::table('operations');
        $progress = Content::recorded_progress($row);
        if ($progress !== null) {
            if (($response['state'] ?? '') === 'failed' && Content::has_completed_items($row)) { return false; }
            $progress['owner_reviewed'] = true;
            $progress['owner_decision'] = $response['state'];
            $response['result']['data_json'] = wp_json_encode($progress);
        }
        $response['owner_review'] = ['user_id' => get_current_user_id(), 'at' => time()];
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uncached atomic journal/nonce state; table names are allowlisted by Schema and every external value is prepared.
        return $wpdb->query($wpdb->prepare("UPDATE $table SET state = %s, response = %s, updated_at = %d
            WHERE operation_id = %s AND state = %s AND state IN ('received', 'executing', 'unknown')", $response['state'], wp_json_encode($response), time(), $row['operation_id'], $row['state'])) === 1;
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public static function lock(int $wait_seconds = 0): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uncached atomic journal/nonce state; table names are allowlisted by Schema and every external value is prepared.
        return (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::lock_name(), max(0, min(5, $wait_seconds)))) === '1';
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
