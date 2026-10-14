<?php
/**
 * Owner changes to an assistant's safety level, kept in step between this site and the bridge.
 *
 * Changes run one at a time per site (a database lock), each numbered per grant. The bridge applies only a newer
 * number, so a delayed request never overwrites a later choice. This site always holds the more restrictive of the
 * old and new level until the bridge confirms: lowering applies here first, raising only after confirmation. A change
 * is saved, and read back, before the bridge hears of it. One the bridge hasn't confirmed stays pending here and is
 * sent again by choosing the level again, by Check, and by a few background attempts that then stop.
 */
namespace WPAICG\Mcp;

if (!defined('ABSPATH')) { exit; }

final class Levels
{
    /** grant_id => [target, revision, synced]. One small entry per connection; removed when it is revoked. */
    public const OPTION = 'aipkit_mcp_level_sync';
    public const RETRY_HOOK = 'aipkit_mcp_level_retry';
    /** Background attempts made since the owner last acted; they stop after the last wait below. */
    public const ATTEMPTS_OPTION = 'aipkit_mcp_level_attempts';
    private const RETRY_BACKOFF = [5 * MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS, 2 * HOUR_IN_SECONDS];

    public static function register_hooks(): void
    {
        add_action(self::RETRY_HOOK, [self::class, 'retry_pending']);
    }

    /** The pending change for a grant, or null when everything is confirmed. */
    public static function pending(string $grant_id): ?array
    {
        $entry = self::entries()[$grant_id] ?? null;
        return is_array($entry) && empty($entry['synced']) ? $entry : null;
    }

    /**
     * The owner chose a level. Returns ['state' => 'synced'|'pending', 'level' => …]; throws for an invalid grant, a
     * revoked one, a busy lock or a storage failure.
     */
    public static function change(string $grant_id, string $level): array
    {
        if (!in_array($level, Registry::LEVELS, true)) { throw new \RuntimeException('invalid_input'); }
        return self::locked(static function () use ($grant_id, $level): array {
            $grant = self::grant($grant_id);
            $entries = self::entries();
            $entry = $entries[$grant_id] ?? null;
            // The same level again: finish an unconfirmed change, or there is nothing to do.
            if ($grant['access_level'] === $level && (!is_array($entry) || !empty($entry['synced']) || $entry['target'] === $level)) {
                return is_array($entry) && empty($entry['synced']) ? self::queue_retry(self::send($grant, $entry)) : ['state' => 'synced', 'level' => $level];
            }
            $entry = ['target' => $level, 'revision' => (int) ($entry['revision'] ?? 0) + 1, 'synced' => false];
            // Nothing reaches the bridge unless the change is safely recorded here, so Check can always finish it.
            if (!self::save($grant_id, $entry)) { throw new \RuntimeException('mcp_storage_unavailable'); }
            // Lowering takes effect here at once; raising waits for the bridge.
            if (!Registry::raises((string) $grant['access_level'], $level) && !Store::set_level($grant_id, $level)) {
                throw new \RuntimeException('mcp_storage_unavailable');
            }
            return self::queue_retry(self::send(self::grant($grant_id), $entry));
        });
    }

    /** The owner selected Check: sends a pending change again. Null when nothing is pending. */
    public static function reconcile(string $grant_id): ?array
    {
        $result = self::finish($grant_id);
        return $result === null ? null : self::queue_retry($result);
    }

    /**
     * A background attempt. While changes stay pending it books the next one after a longer wait, and after the last
     * wait it stops: an outage never turns into requests forever. The owner's next choice or Check starts again.
     */
    public static function retry_pending(): void
    {
        foreach (array_keys(self::entries()) as $grant_id) {
            try { self::finish((string) $grant_id); }
            catch (\RuntimeException $error) {
                if ($error->getMessage() === 'invalid_input') { self::forget((string) $grant_id); } // Revoked or gone.
            } catch (\Throwable $error) { /* Still pending; shown in Settings. */ }
        }
        $pending = array_filter(self::entries(), static function ($entry): bool { return is_array($entry) && empty($entry['synced']); });
        $attempts = (int) get_option(self::ATTEMPTS_OPTION, 0);
        if (!$pending) { update_option(self::ATTEMPTS_OPTION, 0, false); return; }
        if ($attempts < count(self::RETRY_BACKOFF) && !wp_next_scheduled(self::RETRY_HOOK)) {
            update_option(self::ATTEMPTS_OPTION, $attempts + 1, false);
            wp_schedule_single_event(time() + self::RETRY_BACKOFF[$attempts], self::RETRY_HOOK);
        }
    }

    /** A revoked connection has nothing left to keep in step. */
    public static function forget(string $grant_id): void
    {
        $entries = self::entries();
        if (!isset($entries[$grant_id])) { return; }
        unset($entries[$grant_id]);
        update_option(self::OPTION, $entries, false);
    }

    /** Sends a pending change again, without booking background attempts. */
    private static function finish(string $grant_id): ?array
    {
        if (self::pending($grant_id) === null) { return null; }
        return self::locked(static function () use ($grant_id): ?array {
            $entry = self::pending($grant_id);
            return $entry === null ? null : self::send(self::grant($grant_id), $entry);
        });
    }

    /** After the owner acted: a change still pending gets a fresh, bounded set of background attempts. */
    private static function queue_retry(array $result): array
    {
        if ($result['state'] === 'pending') {
            update_option(self::ATTEMPTS_OPTION, 1, false);
            if (!wp_next_scheduled(self::RETRY_HOOK)) { wp_schedule_single_event(time() + self::RETRY_BACKOFF[0], self::RETRY_HOOK); }
        }
        return $result;
    }

    /**
     * Sends one numbered change. Confirmed: raising now applies here too. Stale: the bridge already holds a newer
     * change; if it is this level the change is done, otherwise this site renumbers after it once (its own count was
     * lost) and sends again. Unreachable, or this site can't record the outcome: the change stays pending.
     */
    private static function send(array $grant, array $entry, bool $renumbered = false): array
    {
        $reply = BridgeClient::set_level($grant['grant_id'], $entry['target'], (int) $entry['revision']);
        if (is_array($reply) && ($reply['stale'] ?? false)) {
            if (($reply['access_level'] ?? null) !== $entry['target'] && !$renumbered) {
                $renumbered_entry = $entry;
                $renumbered_entry['revision'] = max((int) $entry['revision'], (int) ($reply['level_revision'] ?? 0)) + 1;
                if (!self::save($grant['grant_id'], $renumbered_entry)) { return self::left_pending($entry); }
                return self::send($grant, $renumbered_entry, true);
            }
            if (($reply['access_level'] ?? null) !== $entry['target']) { return self::left_pending($entry); }
        } elseif (is_wp_error($reply)) {
            if ($reply->get_error_code() === 'revoked') {
                self::forget($grant['grant_id']);
                throw new \RuntimeException('invalid_input');
            }
            return self::left_pending($entry);
        }
        if ($grant['access_level'] !== $entry['target'] && !Store::set_level($grant['grant_id'], $entry['target'])) {
            return self::left_pending($entry);
        }
        // Both sides now hold the target; until that is recorded here the change shows as pending and Check repeats it.
        if (!self::save($grant['grant_id'], array_merge($entry, ['synced' => true]))) { return self::left_pending($entry); }
        return ['state' => 'synced', 'level' => $entry['target']];
    }

    private static function left_pending(array $entry): array
    {
        return ['state' => 'pending', 'level' => $entry['target']];
    }

    private static function grant(string $grant_id): array
    {
        $grant = Store::grant($grant_id);
        if (!$grant || $grant['instance_id'] !== (Identity::registration()['instance_id'] ?? null) || $grant['state'] === 'revoked') {
            throw new \RuntimeException('invalid_input');
        }
        return $grant;
    }

    private static function entries(): array
    {
        $entries = get_option(self::OPTION, []);
        return is_array($entries) ? $entries : [];
    }

    /**
     * Records one grant's entry and reads it back from the database. update_option() also returns false when the value
     * is unchanged, so only the read-back says whether the entry is really stored.
     */
    private static function save(string $grant_id, array $entry): bool
    {
        $entries = self::entries();
        $entries[$grant_id] = $entry;
        update_option(self::OPTION, $entries, false);
        wp_cache_delete(self::OPTION, 'options');
        return (self::entries()[$grant_id] ?? null) === $entry;
    }

    /** The database lock that serializes level changes on this site. */
    public static function lock_name(): string
    {
        global $wpdb;
        return 'aipkit_mcp_level_' . substr(hash('sha256', DB_NAME . ':' . $wpdb->prefix), 0, 40);
    }

    /** One level change at a time on this site, across requests; it waits up to 10 seconds for another to finish. */
    private static function locked(callable $work)
    {
        global $wpdb;
        $name = self::lock_name();
        $wait = (int) apply_filters('aipkit_mcp_level_lock_wait', 10);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Database advisory lock serializes safety-level changes; lock name/wait are prepared; a cached result cannot acquire or release a lock.
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, max(0, min(10, $wait)))) !== '1') { throw new \RuntimeException('mcp_storage_busy'); }
        try {
            wp_cache_delete(self::OPTION, 'options');
            return $work();
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Database advisory lock serializes safety-level changes; lock name/wait are prepared; a cached result cannot acquire or release a lock.
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }
}
