<?php

namespace WPAICG\Core\Stream\Cache;

use WP_Error;
use DateTime;
use DateTimeZone;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Check if the external object cache is being used.
 *
 * @param \WPAICG\Core\Stream\Cache\AIPKit_SSE_Message_Cache $cacheInstance The instance of the cache class.
 * @return bool True if using object cache, false otherwise.
 */
function is_using_object_cache_logic(\WPAICG\Core\Stream\Cache\AIPKit_SSE_Message_Cache $cacheInstance): bool {
    // The property use_object_cache is private, need a getter or make it public
    return $cacheInstance->get_use_object_cache_status();
}

/**
 * Generates a unique cache key.
 *
 * @return string The generated cache key.
 */
function generate_key_logic(): string {
    return 'aipkit_sse_' . wp_generate_password(32, false, false);
}

/**
 * Stores a message in the cache.
 * MODIFIED: Always write to the database as a reliable fallback, then attempt to write to object cache for performance.
 *
 * @param \WPAICG\Core\Stream\Cache\AIPKit_SSE_Message_Cache $cacheInstance The instance of the cache class.
 * @param string $message The user message content.
 * @return string|WP_Error The cache key on success, WP_Error on failure.
 */
function set_logic(\WPAICG\Core\Stream\Cache\AIPKit_SSE_Message_Cache $cacheInstance, string $message) {
    if (empty($message)) {
        return new WP_Error('sse_cache_empty_message', __('Cannot cache an empty message.', 'gpt3-ai-content-generator'));
    }

    $key = $cacheInstance->generate_key_public_wrapper();

    // Always write to the database as a reliable fallback.
    global $wpdb;
    $expires_at = new DateTime('now', new DateTimeZone('UTC'));
    $expires_at->modify('+' . $cacheInstance::EXPIRY_SECONDS . ' seconds');
    $expires_at_str = $expires_at->format('Y-m-d H:i:s');

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Reason: Direct insertion into a custom table is necessary. Cache is set below.
    $inserted = $wpdb->insert(
        $cacheInstance->get_db_table_name(),
        [
            'cache_key' => $key,
            'message_content' => $message,
            'expires_at' => $expires_at_str,
        ],
        ['%s', '%s', '%s']
    );

    if ($inserted === false) {
        return new WP_Error('sse_cache_db_insert_failed', __('Failed to store message in database cache.', 'gpt3-ai-content-generator'));
    }

    // Keep the database expiry with the cached content so a cache hit cannot extend its lifetime.
    if ($cacheInstance->is_using_object_cache()) {
        wp_cache_set($key, [
            'message_content' => $message,
            'expires_at' => $expires_at_str,
        ], $cacheInstance::CACHE_GROUP, $cacheInstance::EXPIRY_SECONDS);
    }

    return $key; // Return the key since the DB write succeeded.
}

/**
 * Retrieves a message from the cache using its key.
 * Implemented WP Object Cache to resolve direct database query warnings.
 *
 * @param \WPAICG\Core\Stream\Cache\AIPKit_SSE_Message_Cache $cacheInstance The instance of the cache class.
 * @param string $key The cache key.
 * @return string|WP_Error The message content on success, WP_Error if not found or expired.
 */
function get_logic(\WPAICG\Core\Stream\Cache\AIPKit_SSE_Message_Cache $cacheInstance, string $key)
{
    if (empty($key)) {
        return new WP_Error('sse_cache_empty_key', __('Cache key cannot be empty.', 'gpt3-ai-content-generator'));
    }

    $now = new DateTime('now', new DateTimeZone('UTC'));
    $now_utc = $now->format('Y-m-d H:i:s');
    $cached_result = wp_cache_get($key, $cacheInstance::CACHE_GROUP);
    if (is_array($cached_result)
        && isset($cached_result['message_content'], $cached_result['expires_at'])
        && is_string($cached_result['message_content'])
        && is_string($cached_result['expires_at'])
        && $cached_result['expires_at'] > $now_utc
    ) {
        return $cached_result['message_content'];
    }

    // Legacy raw values and expired entries must be checked against the database.
    global $wpdb;
    $table_name = $wpdb->prefix . $cacheInstance::DB_TABLE_SUFFIX;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reason: Custom table name is fixed (wpdb prefix + plugin suffix).
    $row = $wpdb->get_row(
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reason: Direct query to a custom table. Caches will be invalidated.
        $wpdb->prepare(
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reason: Direct query to a custom table. Caches will be invalidated.
            "SELECT message_content, expires_at FROM {$table_name} WHERE cache_key = %s AND expires_at > %s LIMIT 1",
            $key,
            $now_utc
        ),
        ARRAY_A
    );

    if ($row && isset($row['message_content'])) {
        $expires_at = new DateTime($row['expires_at'], new DateTimeZone('UTC'));
        $remaining_seconds = max(1, $expires_at->getTimestamp() - $now->getTimestamp());
        wp_cache_set($key, $row, $cacheInstance::CACHE_GROUP, $remaining_seconds);
        return $row['message_content'];
    }

    wp_cache_delete($key, $cacheInstance::CACHE_GROUP);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reason: Custom table name is fixed (wpdb prefix + plugin suffix).
    $exists = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$table_name} WHERE cache_key = %s LIMIT 1", $key));
    return $exists
        ? new WP_Error('sse_cache_expired', __('Cached message has expired.', 'gpt3-ai-content-generator'))
        : new WP_Error('sse_cache_not_found', __('Message not found in cache.', 'gpt3-ai-content-generator'));
}

/**
 * Deletes a message from the cache.
 * MODIFIED: Now deletes from both the database and the object cache to ensure a clean state.
 *
 * @param \WPAICG\Core\Stream\Cache\AIPKit_SSE_Message_Cache $cacheInstance The instance of the cache class.
 * @param string $key The cache key.
 * @return bool True on success, false on failure or if key didn't exist.
 */
function delete_logic(\WPAICG\Core\Stream\Cache\AIPKit_SSE_Message_Cache $cacheInstance, string $key): bool
{
    if (empty($key)) {
        return false;
    }

    // Always try to delete from the database.
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Reason: Direct deletion from custom table is necessary. Cache is invalidated below.
    $deleted_db = $wpdb->delete(
        $cacheInstance->get_db_table_name(),
        ['cache_key' => $key],
        ['%s']
    );
    // Reads also use WordPress's in-request cache when no external cache is installed.
    $deleted_from_object_cache = wp_cache_delete($key, $cacheInstance::CACHE_GROUP);

    // Return true if it was deleted from at least one source (or didn't exist in either).
    return $deleted_db !== false || $deleted_from_object_cache;
}

/**
 * Schedules the hourly cache cleanup event if not already scheduled.
 *
 * @param string $cron_hook_const The cron hook constant.
 * @return void
 */
function schedule_cleanup_event_logic(string $cron_hook_const): void {
    if (!wp_next_scheduled($cron_hook_const)) {
        wp_schedule_event(time(), 'hourly', $cron_hook_const);
    }
    if (!has_action($cron_hook_const, ['WPAICG\Core\Stream\Cache\AIPKit_SSE_Message_Cache', 'run_db_cleanup_static_wrapper'])) {
        add_action($cron_hook_const, ['WPAICG\Core\Stream\Cache\AIPKit_SSE_Message_Cache', 'run_db_cleanup_static_wrapper']);
    }
}

/**
 * Unschedules the cache cleanup event.
 *
 * @param string $cron_hook_const The cron hook constant.
 * @return void
 */
function unschedule_cleanup_event_logic(string $cron_hook_const): void {
    $timestamp = wp_next_scheduled($cron_hook_const);
    if ($timestamp) {
        wp_unschedule_event($timestamp, $cron_hook_const);
    }
    remove_action($cron_hook_const, ['WPAICG\Core\Stream\Cache\AIPKit_SSE_Message_Cache', 'run_db_cleanup_static_wrapper']);
}

/**
 * Cron callback function to delete expired cache entries from the DB table.
 * Every write creates a database row, including when external object caching is enabled.
 *
 * @return void
 */
function run_db_cleanup_logic(): void {

    global $wpdb;
    $table = $wpdb->prefix . AIPKit_SSE_Message_Cache::DB_TABLE_SUFFIX;
    $now_utc = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reason: Custom table name is fixed (wpdb prefix + plugin suffix); cron cleanup query.
    $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE expires_at <= %s", $now_utc));

}

/**
 * AIPKit_SSE_Message_Cache
 *
 * Handles caching large user messages temporarily for SSE requests
 * to avoid "414 Request-URI Too Large" errors.
 * Persists each message in the database and uses object caching without extending its expiry.
 */
class AIPKit_SSE_Message_Cache
{
    public const CACHE_GROUP = 'default'; // Use default group for better compatibility with external object caches
    public const DB_TABLE_SUFFIX = 'aipkit_sse_message_cache';
    public const EXPIRY_SECONDS = 60; // Messages expire after 60 seconds
    public const CLEANUP_CRON_HOOK = 'aipkit_cleanup_sse_cache';

    private $use_object_cache;
    private $db_table_name;

    public function __construct()
    {
        global $wpdb;
        $this->use_object_cache = wp_using_ext_object_cache();
        $this->db_table_name = $wpdb->prefix . self::DB_TABLE_SUFFIX;
    }

    public function is_using_object_cache(): bool
    {
        return is_using_object_cache_logic($this);
    }

    // Public wrapper for the private generate_key logic
    public function generate_key_public_wrapper(): string
    {
        return generate_key_logic();
    }

    /**
     * @return string|\WP_Error
     */
    public function set(string $message)
    {
        return set_logic($this, $message);
    }

    /**
     * @return string|\WP_Error
     */
    public function get(string $key)
    {
        return get_logic($this, $key);
    }

    public function delete(string $key): bool
    {
        return delete_logic($this, $key);
    }

    public static function schedule_cleanup_event()
    {
        schedule_cleanup_event_logic(self::CLEANUP_CRON_HOOK);
    }

    public static function unschedule_cleanup_event()
    {
        unschedule_cleanup_event_logic(self::CLEANUP_CRON_HOOK);
    }

    // Static wrapper for non-static logic to be used in cron
    public static function run_db_cleanup_static_wrapper()
    {
        run_db_cleanup_logic();
    }

    // Getters for private properties
    public function get_use_object_cache_status(): bool
    {
        return (bool) $this->use_object_cache;
    }
    public function get_db_table_name(): string
    {
        return $this->db_table_name;
    }
}

// Add action for the cron callback immediately after class definition
add_action(AIPKit_SSE_Message_Cache::CLEANUP_CRON_HOOK, ['WPAICG\Core\Stream\Cache\AIPKit_SSE_Message_Cache', 'run_db_cleanup_static_wrapper']);
