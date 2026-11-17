<?php
namespace WPAICG\AutoGPT\Cron;

if (!defined('ABSPATH')) { exit; }

/** Queue attempts own completion; a reclaimed item must not accept an older worker's effects. */
final class AIPKit_Queue_Attempt
{
    public static function owns(array $item): bool
    {
        global $wpdb;
        if (empty($item['id']) || empty($item['attempt_token'])) { return false; }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fresh ownership read on the plugin's queue; values are prepared.
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->prefix}aipkit_automated_task_queue WHERE id = %d AND status = 'processing' AND attempt_token = %s", $item['id'], $item['attempt_token']));
    }

    /** Commit a post and its queue receipt together, without holding a transaction over generation. */
    public static function save_post(array $item, callable $insert)
    {
        global $wpdb;
        if (empty($item['id']) || empty($item['attempt_token'])) { return new \WP_Error('aipkit_attempt_expired', __('This queue attempt no longer owns the item.', 'gpt3-ai-content-generator')); }
        $table = $wpdb->prefix . 'aipkit_automated_task_queue';
        $started = false;
        $post_id = 0;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Transactional queue/post commit. Identifiers come from wpdb, values are prepared; never commit an existing caller transaction.
        try {
            $engines = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s) AND ENGINE = 'InnoDB'", $table, $wpdb->posts));
            if ((int) $engines !== 2 || $wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') === false || $wpdb->query('START TRANSACTION') === false) {
                throw new \RuntimeException('aipkit_queue_storage_unavailable');
            }
            $started = true;
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifier uses only wpdb's prefix and a fixed queue suffix; the item ID is prepared.
            $row = $wpdb->get_row($wpdb->prepare("SELECT status, attempt_token, result_post_id FROM $table WHERE id = %d FOR UPDATE", $item['id']), ARRAY_A);
            if (!$row || $row['status'] !== 'processing' || !hash_equals((string) $row['attempt_token'], (string) $item['attempt_token'])) {
                throw new \RuntimeException('aipkit_attempt_expired');
            }
            // A worker can die after the post commit but before queue completion. Reuse that result.
            $post_id = (int) $row['result_post_id'];
            if (!$post_id) {
                $result = $insert();
                if (is_wp_error($result)) { throw new \RuntimeException('aipkit_queue_post_failed'); }
                $post_id = (int) $result;
                if (!$post_id || $wpdb->update($table, ['result_post_id' => $post_id, 'last_attempt_time' => current_time('mysql', true)], ['id' => (int) $item['id'], 'attempt_token' => $item['attempt_token']], ['%d', '%s'], ['%d', '%s']) !== 1) {
                    throw new \RuntimeException('aipkit_queue_receipt_failed');
                }
            }
            if ($wpdb->query('COMMIT') === false) { throw new \RuntimeException('aipkit_queue_commit_failed'); }
            $started = false;
            return $post_id;
        } catch (\Throwable $error) {
            if ($started) { $wpdb->query('ROLLBACK'); }
            if ($post_id) { clean_post_cache($post_id); }
            $code = in_array($error->getMessage(), ['aipkit_attempt_expired', 'aipkit_queue_storage_unavailable', 'aipkit_queue_receipt_failed', 'aipkit_queue_commit_failed'], true) ? $error->getMessage() : 'aipkit_queue_post_failed';
            return new \WP_Error($code, __('Could not save this queue attempt. Check its result before retrying.', 'gpt3-ai-content-generator'));
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
}
