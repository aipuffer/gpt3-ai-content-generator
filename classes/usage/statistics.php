<?php

namespace WPAICG\Stats; // Use a dedicated Stats namespace

use wpdb;
use WP_Error;
use DateTime;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- This stats service only reads plugin-owned tables plus wp_users with prepared scalar values.

/**
 * Calculates statistics related to overall AI usage across different modules.
 * Includes filtering for top N modules in daily stats.
 */
class AIPKit_Stats
{ // Renamed from AIPKit_Chat_Stats to reflect broader scope

    public static function visitor_billing_enabled(): bool
    {
        $saved = get_option('aipkit_visitor_billing_enabled');
        if ($saved === 'yes' || $saved === 'no') {
            return $saved === 'yes';
        }

        if (get_option('aipkit_token_dashboard_page_url') || get_option('aipkit_token_shop_page_url')) {
            return true;
        }

        $products = get_posts([
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-row check for an existing visitor credit package.
            'meta_key' => '_aipkit_is_token_package',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One-row check for an existing visitor credit package.
            'meta_value' => 'yes',
        ]);
        if ($products) {
            return true;
        }

        $users = get_users([
            'number' => 1,
            'fields' => 'ID',
            'count_total' => false,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-row check to keep saved visitor balances visible.
            'meta_key' => '_aipkit_token_balance',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One-row check to keep saved visitor balances visible.
            'meta_value' => 0,
            'meta_compare' => '>',
            'meta_type' => 'NUMERIC',
        ]);
        if ($users) {
            return true;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'aipkit_pricing_rules';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only check of a plugin-owned table while rendering Usage.
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The table name is built only from the WordPress prefix and the plugin's fixed suffix.
        return (bool) $wpdb->get_var("SELECT 1 FROM {$table} LIMIT 1");
    }

    private $wpdb;
    private $log_table_name;
    private $ledger_table_name;
    // Hard caps to prevent memory exhaustion on large sites; can be filtered.
    private const DEFAULT_MAX_BYTES_FOR_STATS = 64000000; // ~64 MB
    private const DEFAULT_MAX_ROWS_FOR_STATS  = 5000;       // cap rows to scan
    public const TOP_N_MODULES_FOR_CHART = 4; // Number of top modules to display individually in the chart

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->log_table_name = $wpdb->prefix . 'aipkit_chat_logs'; // Table name remains the same
        $this->ledger_table_name = $wpdb->prefix . 'aipkit_token_ledger';
    }

    /**
     * @return array{start_utc:string,end_utc:string}
     */
    private function get_ledger_date_range(int $days): array
    {
        $wp_timezone = wp_timezone();
        $end_datetime = new DateTime('now', $wp_timezone);
        $start_datetime = new DateTime("-{$days} days", $wp_timezone);
        $start_datetime->setTime(0, 0, 0);

        return [
            'start_utc' => gmdate('Y-m-d H:i:s', $start_datetime->getTimestamp()),
            'end_utc' => gmdate('Y-m-d H:i:s', $end_datetime->getTimestamp()),
        ];
    }

    private function ledger_table_exists(): bool
    {
        static $exists = null;

        if ($exists !== null) {
            return $exists;
        }

        $table_exists_query = $this->wpdb->prepare('SHOW TABLES LIKE %s', $this->ledger_table_name);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One-time table existence check using the prepared query above.
        $exists = $this->wpdb->get_var($table_exists_query) === $this->ledger_table_name;

        return $exists;
    }

    /**
     * Estimate number of rows and total bytes for messages in range to avoid OOM.
     *
     * @param int $start_ts
     * @param int $end_ts
     * @return array{rows:int, bytes:int}|WP_Error
     */
    private function estimate_volume(int $start_ts, int $end_ts)
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Using prepare with timestamps
        $sql = $this->wpdb->prepare(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(CHAR_LENGTH(messages)), 0) AS total_bytes FROM {$this->log_table_name} WHERE last_message_ts >= %d AND last_message_ts <= %d",
            $start_ts,
            $end_ts
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row = $this->wpdb->get_row($sql, ARRAY_A);
        if ($this->wpdb->last_error) {
            return new WP_Error('db_query_error', __('Database error estimating log volume for stats.', 'gpt3-ai-content-generator'));
        }
        $rows  = isset($row['cnt']) ? (int) $row['cnt'] : 0;
        $bytes = isset($row['total_bytes']) ? (int) $row['total_bytes'] : 0;
        return ['rows' => $rows, 'bytes' => $bytes];
    }

    

    /**
     * Calculates daily token usage grouped by the top N modules and 'Other' for the last X days.
     *
     * @param int $days Number of past days to include.
     * @return array|WP_Error An array ['YYYY-MM-DD' => ['top_module1' => tokens, 'top_moduleN' => tokens, 'Other' => tokens]] or WP_Error.
     */
    public function get_daily_token_stats(int $days = 30)
    {
        if ($days <= 0) {
            return new WP_Error('invalid_days', __('Number of days must be positive.', 'gpt3-ai-content-generator'));
        }

        $wp_timezone = wp_timezone();
        $end_datetime = new DateTime('now', $wp_timezone);
        $start_datetime = new DateTime("-$days days", $wp_timezone);
        $start_datetime->setTime(0, 0, 0);

        $timestamp_threshold_start = $start_datetime->getTimestamp();
        $timestamp_threshold_end = $end_datetime->getTimestamp();

        // Guardrail: estimate volume and bail out if too heavy
        $vol = $this->estimate_volume($timestamp_threshold_start, $timestamp_threshold_end);
        if (is_wp_error($vol)) {
            return $vol;
        }
        $max_bytes = (int) apply_filters('aipkit_stats_max_bytes', self::DEFAULT_MAX_BYTES_FOR_STATS);
        $max_rows  = (int) apply_filters('aipkit_stats_max_rows', self::DEFAULT_MAX_ROWS_FOR_STATS);
        
        if ($vol['rows'] > $max_rows || $vol['bytes'] > $max_bytes) {
            return new WP_Error(
                'stats_volume_too_large',
                sprintf(
                    /* translators: 1: number of rows, 2: number of bytes */
                    __('Usage data volume is too large to compute daily chart (rows: %1$s, bytes: %2$s). Try reducing retention or disabling conversation storage.', 'gpt3-ai-content-generator'),
                    number_format_i18n($vol['rows']),
                    size_format($vol['bytes'])
                ),
                [ 'rows' => (int) $vol['rows'], 'bytes' => (int) $vol['bytes'] ]
            );
        }

        // Fetch logs within the range, including 'module' and 'messages'

        // Fetch logs within the range, including 'module' and 'messages'
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reason: Using prepare to safely insert timestamps
        $query = $this->wpdb->prepare("SELECT messages, module FROM {$this->log_table_name} WHERE last_message_ts >= %d AND last_message_ts <= %d", $timestamp_threshold_start, $timestamp_threshold_end);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reason: Using prepare to safely insert timestamps
        $results = $this->wpdb->get_results($query, ARRAY_A);

        if ($this->wpdb->last_error) {
            return new WP_Error('db_query_error', __('Database error fetching logs for daily stats.', 'gpt3-ai-content-generator'));
        }

        // --- Phase 1: Aggregate ALL daily tokens per module and total tokens per module ---
        $daily_tokens_all = [];
        $total_module_tokens = []; // Stores total tokens for each module across the period

        // Initialize all days in the period
        $current_date = clone $start_datetime;
        while ($current_date <= $end_datetime) {
            $daily_tokens_all[$current_date->format('Y-m-d')] = [];
            $current_date->modify('+1 day');
        }

        if (!empty($results)) {
            foreach ($results as $row) {
                $module_name = !empty($row['module']) ? $row['module'] : 'unknown';
                $messages_json = $row['messages'] ?? '[]';
                $conversation_data = json_decode($messages_json, true);
                $messages_array = null;
                if (is_array($conversation_data) && isset($conversation_data['messages']) && is_array($conversation_data['messages'])) {
                    $messages_array = $conversation_data['messages'];
                } elseif (is_array($conversation_data)) {
                    $messages_array = $conversation_data;
                }

                if (is_array($messages_array)) {
                    foreach ($messages_array as $msg) {
                        // Use timestamp of the individual message for daily grouping
                        if (isset($msg['timestamp']) && is_numeric($msg['timestamp'])) {
                            $message_timestamp = (int)$msg['timestamp'];
                            // Ensure message is within the overall date range (redundant check, but safe)
                            if ($message_timestamp < $timestamp_threshold_start || $message_timestamp > $timestamp_threshold_end) {
                                continue;
                            }

                            $message_date = new DateTime('@' . $message_timestamp);
                            $message_date->setTimezone($wp_timezone);
                            $date_key = $message_date->format('Y-m-d');

                            // Sum tokens for this message
                            $tokens_in_message = 0;
                            if (isset($msg['usage']['total_tokens']) && is_numeric($msg['usage']['total_tokens'])) {
                                $tokens_in_message = (int) $msg['usage']['total_tokens'];
                            } elseif (isset($msg['usage']['totalTokenCount']) && is_numeric($msg['usage']['totalTokenCount'])) {
                                $tokens_in_message = (int) $msg['usage']['totalTokenCount'];
                            }

                            if ($tokens_in_message > 0) {
                                // Add to daily count FOR THE MODULE
                                if (isset($daily_tokens_all[$date_key])) {
                                    $daily_tokens_all[$date_key][$module_name] = ($daily_tokens_all[$date_key][$module_name] ?? 0) + $tokens_in_message;
                                }
                                // Add to overall module total
                                $total_module_tokens[$module_name] = ($total_module_tokens[$module_name] ?? 0) + $tokens_in_message;
                            }
                        } // End timestamp check
                    } // End foreach message
                }
            } // End foreach result row
        } // End if results

        // --- Phase 2: Identify Top N modules ---
        arsort($total_module_tokens); // Sort modules by total usage descending
        $top_n_modules = array_slice(array_keys($total_module_tokens), 0, self::TOP_N_MODULES_FOR_CHART);
        $top_n_modules_set = array_flip($top_n_modules); // Use as a set for quick lookups

        // --- Phase 3: Create Filtered Daily Data with 'Other' category ---
        $filtered_daily_tokens = [];
        // Re-initialize all days
        $current_date = clone $start_datetime;
        while ($current_date <= $end_datetime) {
            $filtered_daily_tokens[$current_date->format('Y-m-d')] = [];
            $current_date->modify('+1 day');
        }

        foreach ($daily_tokens_all as $date => $modules_on_day) {
            $other_tokens_for_day = 0;
            foreach ($modules_on_day as $module => $tokens) {
                if (isset($top_n_modules_set[$module])) {
                    $filtered_daily_tokens[$date][$module] = $tokens;
                } else {
                    $other_tokens_for_day += $tokens;
                }
            }
            if ($other_tokens_for_day > 0) {
                $filtered_daily_tokens[$date]['Other'] = $other_tokens_for_day;
            }
        }

        ksort($filtered_daily_tokens); // Ensure outer array is sorted by date key

        return $filtered_daily_tokens;
    }


    /**
     * Fast path: Returns only interactions count and per-module counts for last X days.
     * Avoids loading `messages` longtext to keep memory low.
     *
     * @param int $days
     * @return array|WP_Error ['total_interactions' => int, 'module_counts' => array, 'days_period' => int]
     */
    public function get_quick_interaction_stats(int $days = 3)
    {
        if ($days <= 0) {
            return new WP_Error('invalid_days', __('Number of days must be positive.', 'gpt3-ai-content-generator'));
        }

        $wp_timezone = wp_timezone();
        $end_datetime = new DateTime('now', $wp_timezone);
        $start_datetime = new DateTime("-$days days", $wp_timezone);
        $start_datetime->setTime(0, 0, 0);

        $timestamp_threshold_start = $start_datetime->getTimestamp();
        $timestamp_threshold_end = $end_datetime->getTimestamp();

        // Count total interactions
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Using prepare with timestamps
        $count_sql = $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->log_table_name} WHERE last_message_ts >= %d AND last_message_ts <= %d",
            $timestamp_threshold_start,
            $timestamp_threshold_end
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total_interactions = (int) $this->wpdb->get_var($count_sql);
        if ($this->wpdb->last_error) {
            return new WP_Error('db_query_error', __('Database error fetching counts for quick stats.', 'gpt3-ai-content-generator'));
        }

        // Per-module counts
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Using prepare with timestamps
        $group_sql = $this->wpdb->prepare(
            "SELECT COALESCE(module,'unknown') as module, COUNT(*) as cnt FROM {$this->log_table_name} WHERE last_message_ts >= %d AND last_message_ts <= %d GROUP BY module",
            $timestamp_threshold_start,
            $timestamp_threshold_end
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $this->wpdb->get_results($group_sql, ARRAY_A) ?: [];
        if ($this->wpdb->last_error) {
            return new WP_Error('db_query_error', __('Database error fetching module counts for quick stats.', 'gpt3-ai-content-generator'));
        }
        $module_counts = [];
        foreach ($rows as $r) {
            $module = $r['module'] !== null && $r['module'] !== '' ? $r['module'] : 'unknown';
            $module_counts[$module] = (int) $r['cnt'];
        }
        arsort($module_counts);

        return [
            'total_interactions' => $total_interactions,
            'module_counts' => $module_counts,
            'days_period' => $days,
        ];
    }

    /**
     * Returns ledger-backed summary values for the selected period.
     *
     * @return array<string, int>|WP_Error
     */
    public function get_ledger_summary(int $days = 30)
    {
        if ($days <= 0) {
            return new WP_Error('invalid_days', __('Number of days must be positive.', 'gpt3-ai-content-generator'));
        }

        if (!$this->ledger_table_exists()) {
            return [
                'credits_added' => 0,
                'credits_spent' => 0,
                'quota_only_usage_count' => 0,
                'usage_entry_count' => 0,
                'total_entries' => 0,
            ];
        }

        $range = $this->get_ledger_date_range($days);
        $query = $this->wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN credits_delta > 0 THEN credits_delta ELSE 0 END), 0) AS credits_added,
                COALESCE(ABS(SUM(CASE WHEN credits_delta < 0 THEN credits_delta ELSE 0 END)), 0) AS credits_spent,
                COALESCE(SUM(CASE WHEN entry_type = 'usage' AND credits_delta = 0 AND usage_total_units > 0
                    AND (operation <> 'live_voice' OR meta REGEXP '\"quota_recorded\"[[:space:]]*:[[:space:]]*true')
                    THEN 1 ELSE 0 END), 0) AS quota_only_usage_count,
                COALESCE(SUM(CASE WHEN entry_type = 'usage' THEN 1 ELSE 0 END), 0) AS usage_entry_count,
                COUNT(*) AS total_entries
             FROM {$this->ledger_table_name}
             WHERE entry_type <> 'request' AND created_at >= %s AND created_at <= %s",
            $range['start_utc'],
            $range['end_utc']
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
        $row = $this->wpdb->get_row($query, ARRAY_A);
        if ($this->wpdb->last_error) {
            return new WP_Error('db_query_error', __('Database error fetching ledger summary.', 'gpt3-ai-content-generator'));
        }

        return [
            'credits_added' => isset($row['credits_added']) ? (int) $row['credits_added'] : 0,
            'credits_spent' => isset($row['credits_spent']) ? (int) $row['credits_spent'] : 0,
            'quota_only_usage_count' => isset($row['quota_only_usage_count']) ? (int) $row['quota_only_usage_count'] : 0,
            'usage_entry_count' => isset($row['usage_entry_count']) ? (int) $row['usage_entry_count'] : 0,
            'total_entries' => isset($row['total_entries']) ? (int) $row['total_entries'] : 0,
        ];
    }

    /**
     * Returns recent ledger activity for the selected period.
     *
     * @return array<int, array<string, mixed>>|WP_Error
     */
    public function get_recent_ledger_activity(int $days = 30, int $limit = 12)
    {
        if ($days <= 0) {
            return new WP_Error('invalid_days', __('Number of days must be positive.', 'gpt3-ai-content-generator'));
        }

        if (!$this->ledger_table_exists()) {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $range = $this->get_ledger_date_range($days);
        $users_table = $this->wpdb->users;

        $query = $this->wpdb->prepare(
            "SELECT l.*, u.display_name, u.user_email
             FROM {$this->ledger_table_name} l
             LEFT JOIN {$users_table} u ON u.ID = l.user_id
             WHERE l.entry_type <> 'request' AND l.created_at >= %s AND l.created_at <= %s
             ORDER BY l.created_at DESC, l.id DESC
             LIMIT %d",
            $range['start_utc'],
            $range['end_utc'],
            $limit
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
        $rows = $this->wpdb->get_results($query, ARRAY_A);
        if ($this->wpdb->last_error) {
            return new WP_Error('db_query_error', __('Database error fetching recent ledger activity.', 'gpt3-ai-content-generator'));
        }

        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $activity = [];
        foreach ($rows as $row) {
            $display_name = isset($row['display_name']) ? (string) $row['display_name'] : '';
            $user_email = isset($row['user_email']) ? (string) $row['user_email'] : '';
            $session_id = isset($row['session_id']) ? (string) $row['session_id'] : '';
            $actor_label = __('System', 'gpt3-ai-content-generator');

            if (!empty($row['user_id'])) {
                $actor_label = $display_name !== '' ? $display_name : $user_email;
            } elseif ($session_id !== '') {
                /* translators: %s: guest session fragment */
                $actor_label = sprintf(__('Guest %s', 'gpt3-ai-content-generator'), substr($session_id, 0, 8));
            }

            $created_at = isset($row['created_at']) ? (string) $row['created_at'] : '';
            $meta = json_decode((string) ($row['meta'] ?? ''), true);
            $activity[] = [
                'id' => isset($row['id']) ? (int) $row['id'] : 0,
                'actor_label' => $actor_label,
                'module' => isset($row['module']) ? (string) $row['module'] : '',
                'entry_type' => isset($row['entry_type']) ? (string) $row['entry_type'] : '',
                'operation' => isset($row['operation']) ? (string) $row['operation'] : '',
                'provider' => isset($row['provider']) ? (string) $row['provider'] : '',
                'model' => isset($row['model']) ? (string) $row['model'] : '',
                'credits_delta' => isset($row['credits_delta']) ? (int) $row['credits_delta'] : 0,
                'usage_total_units' => isset($row['usage_total_units']) ? (int) $row['usage_total_units'] : 0,
                'billing_method' => is_array($meta) ? (string) ($meta['billing_method'] ?? '') : '',
                'reference_type' => isset($row['reference_type']) ? (string) $row['reference_type'] : '',
                'reference_id' => isset($row['reference_id']) ? (string) $row['reference_id'] : '',
                'created_at' => $created_at,
                'created_at_ts' => $created_at !== '' ? (int) strtotime($created_at . ' UTC') : 0,
            ];
        }

        return $activity;
    }

    /** Delete standalone request receipts; preserve shared visitor-accounting entries. */
    public function delete_provider_requests(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
        if (!$ids || count($ids) > 20) {
            return new WP_Error('invalid_request_ids', __('Select up to 20 requests.', 'gpt3-ai-content-generator'));
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The dynamic IN list contains only integer placeholders, with every ID bound below.
        $deleted = $this->wpdb->query($this->wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One %d per validated ID; wpdb prepares the array of IDs.
            "DELETE FROM {$this->ledger_table_name} WHERE entry_type = 'request' AND id IN ({$placeholders})", $ids
        ));
        if ($deleted === false) { return new WP_Error('request_delete_failed', __('Could not delete request history.', 'gpt3-ai-content-generator')); }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The dynamic IN list contains only integer placeholders, with every ID bound below.
        $hidden = $this->wpdb->query($this->wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One %d per validated ID; the update preserves visitor credit accounting.
            "UPDATE {$this->ledger_table_name} SET meta = JSON_SET(CASE WHEN JSON_VALID(meta) THEN meta ELSE '{}' END, '$.request_history_deleted', true) WHERE entry_type = 'usage' AND id IN ({$placeholders})", $ids
        ));
        if ($hidden === false) { return new WP_Error('request_delete_failed', __('Could not delete request history.', 'gpt3-ai-content-generator')); }
        return $deleted + $hidden;
    }

    /** Locally recorded provider usage and completed Cloud receipts. */
    public function get_recent_provider_requests(int $days, int $limit, int $offset = 0)
    {
        if (!$this->ledger_table_exists()) {
            return [];
        }

        $range = $this->get_ledger_date_range($days);
        if ($days === 0) { $range['start_utc'] = '1970-01-01 00:00:00'; }
        $query = $this->wpdb->prepare(
            "SELECT id, created_at, module, provider, model, operation, usage_input_units, usage_output_units, usage_total_units, meta
             FROM {$this->ledger_table_name}
             WHERE (entry_type = 'request' OR (entry_type = 'usage'
               AND LOWER(COALESCE(provider, '')) NOT IN ('aipuffercloud', 'ai puffer cloud')))
               AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(meta) THEN meta ELSE '{}' END, '$.request_history_deleted')), 'false') NOT IN ('true', '1')
               AND created_at >= %s AND created_at <= %s
             ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
            $range['start_utc'],
            $range['end_utc'],
            max(1, min(201, $limit)),
            max(0, $offset)
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared query of the plugin-owned ledger.
        $rows = $this->wpdb->get_results($query, ARRAY_A);
        if ($this->wpdb->last_error) {
            return new WP_Error('db_query_error', __('Database error fetching recent requests.', 'gpt3-ai-content-generator'));
        }

        return array_map(static function ($row) {
            $meta = json_decode($row['meta'] ?? '', true);
            $cloud = strtolower($row['provider'] ?? '') === 'aipuffercloud';
            return [
                'id' => (int) $row['id'],
                'source' => $cloud ? 'cloud' : 'provider',
                'createdAt' => gmdate('c', strtotime($row['created_at'] . ' UTC')),
                'feature' => (string) $row['module'],
                'operation' => (string) $row['operation'],
                'provider' => $cloud ? 'AI Puffer Cloud' : (string) $row['provider'],
                'model' => (string) $row['model'],
                'providerLabel' => \WPAICG\AIPKit_Providers::get_provider_display_name((string) $row['provider']),
                'modelLabel' => \WPAICG\Core\Models\AIPKit_Model_Registry::get_model_display_name((string) $row['provider'], (string) $row['model']),
                'inputUnits' => (int) $row['usage_input_units'],
                'outputUnits' => (int) $row['usage_output_units'],
                'totalUnits' => (int) $row['usage_total_units'],
                'unit' => $meta['usage_unit'] ?? ($row['operation'] === 'live_voice' ? 'second' : 'token'),
                'cachedUnits' => (int) ($meta['cached_units'] ?? 0),
                'chargedUnits' => $cloud ? ($meta['cloud_charged_units'] ?? null) : null,
            ];
        }, $rows ?: []);
    }
}
