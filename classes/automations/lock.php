<?php

namespace WPAICG\AutoGPT\Cron;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Atomic, token-owned locks stored in the current site's options table.
 */
final class AIPKit_Option_Lock
{
    /**
     * Acquires a lock or atomically takes over an expired lock.
     */
    public static function acquire(string $option_name, int $ttl): string
    {
        if ($option_name === '') {
            return '';
        }

        $ttl = max(1, $ttl);
        $token = wp_generate_uuid4();
        $new_lock = [
            'token' => $token,
            'expires_at' => time() + $ttl,
        ];

        if (add_option($option_name, $new_lock, '', false)) {
            return $token;
        }

        $existing_lock = get_option($option_name, null);
        if (!self::is_expired($existing_lock, $ttl)) {
            return '';
        }

        global $wpdb;
        $serialized_existing_lock = maybe_serialize($existing_lock);
        $serialized_new_lock = maybe_serialize($new_lock);

        // The old serialized value is part of the predicate, making stale-lock
        // takeover a compare-and-swap instead of a delete-then-add race.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Atomic lock operation on the current site's WordPress options table.
        $swapped = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s, autoload = %s WHERE option_name = %s AND option_value = %s",
                $serialized_new_lock,
                'no',
                $option_name,
                $serialized_existing_lock
            )
        );

        if ($swapped !== 1) {
            return '';
        }

        self::clear_option_cache($option_name);
        return $token;
    }

    /**
     * Releases the lock only while the stored value still belongs to its owner.
     */
    public static function release(string $option_name, string $token): void
    {
        if ($option_name === '' || $token === '') {
            return;
        }

        $existing_lock = get_option($option_name, null);
        if (!is_array($existing_lock)) {
            return;
        }

        $stored_token = (string) ($existing_lock['token'] ?? '');
        if ($stored_token === '' || !hash_equals($stored_token, $token)) {
            return;
        }

        global $wpdb;
        $serialized_existing_lock = maybe_serialize($existing_lock);

        // The exact value is part of the delete predicate, so an expired owner
        // cannot remove a lock that another invocation took over meanwhile.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Atomic lock operation on the current site's WordPress options table.
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                $option_name,
                $serialized_existing_lock
            )
        );

        if ($deleted === 1) {
            self::clear_option_cache($option_name);
        }
    }

    /**
     * Supports both token locks and the timestamp-only producer locks from 2.4.61.
     *
     * @param mixed $lock Stored option value.
     */
    private static function is_expired($lock, int $ttl): bool
    {
        if (is_array($lock)) {
            $expires_at = absint($lock['expires_at'] ?? 0);
        } elseif (is_numeric($lock)) {
            $expires_at = absint($lock) + $ttl;
        } else {
            return false;
        }

        return $expires_at <= 0 || $expires_at <= time();
    }

    /** Clears both the individual option cache and the negative-option cache. */
    private static function clear_option_cache(string $option_name): void
    {
        wp_cache_delete($option_name, 'options');
        wp_cache_delete('notoptions', 'options');
    }
}
