<?php

namespace WPAICG\Core\TokenManager\Ledger;

use WP_Error;
use WPAICG\Core\TokenManager\Constants\MetaKeysConstants;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- This repository only queries plugin-owned ledger tables and scalar values are normalized before each prepared call.

/** Stores usage and credit records, including duplicate-entry lookups. */
class AIPKit_Ledger_Repository
{
    private $table_name;
    private static $transaction_active = false;

    public function __construct(?string $table_name = null)
    {
        global $wpdb;
        $this->table_name = $table_name ?: $wpdb->prefix . 'aipkit_token_ledger';
    }

    /**
     * Keep a visitor's balance/quota and receipt in one local transaction.
     * Locks are scoped to the actor, not the whole site. Never commit a caller's transaction.
     * @return mixed|WP_Error
     */
    public function atomic_usage(?int $user_id, array $guest_identifiers, callable $record)
    {
        global $wpdb;
        if (self::$transaction_active) { return new WP_Error('aipkit_usage_busy', 'Local usage is already being recorded.'); }
        $guest_table = $wpdb->prefix . 'aipkit_guest_token_usage';
        $tables = [$this->table_name, $user_id ? $wpdb->usermeta : $guest_table];
        $actors = $user_id ? ['user:' . $user_id] : array_map(static function ($id) { return 'guest:' . $id; }, $guest_identifiers);
        if (!$actors) { return new WP_Error('aipkit_usage_actor_missing', 'Local usage requires an actor.'); }
        sort($actors, SORT_STRING);
        $locks = [];
        $started = false;
        self::$transaction_active = true;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded local accounting transaction; locks and engine checks cannot be cached across requests.
        try {
            $engines = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s) AND ENGINE = 'InnoDB'", $tables[0], $tables[1]));
            if ((int) $engines !== 2) { throw new \RuntimeException('transactional_storage_required'); }
            foreach (array_unique($actors) as $actor) {
                $name = 'aipkit_usage_' . substr(hash('sha256', DB_NAME . ':' . $wpdb->usermeta . ':' . $actor), 0, 48);
                if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $name)) !== '1') { throw new \RuntimeException('usage_lock_unavailable'); }
                $locks[] = $name;
            }
            // This statement fails inside an existing transaction, unlike START TRANSACTION
            // which would silently commit it. READ COMMITTED also avoids stale retry snapshots.
            if ($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') === false
                || $wpdb->query('START TRANSACTION') === false) { throw new \RuntimeException('usage_transaction_unavailable'); }
            $started = true;
            if ($user_id) {
                wp_cache_delete($user_id, 'user_meta');
                get_user_meta($user_id);
                if ($wpdb->last_error !== '') { throw new \RuntimeException('usage_balance_read_failed'); }
            }
            $result = $record();
            if (is_wp_error($result)) { throw new \RuntimeException('usage_record_failed'); }
            if ($wpdb->query('COMMIT') === false) { throw new \RuntimeException('usage_commit_failed'); }
            $started = false;
            return $result;
        } catch (\Throwable $error) {
            if ($started) { $wpdb->query('ROLLBACK'); }
            return new WP_Error('aipkit_usage_not_recorded', __('Local usage could not be saved. No balance change was confirmed.', 'gpt3-ai-content-generator'));
        } finally {
            // Metadata APIs may have populated an object cache with uncommitted values.
            if ($user_id) { wp_cache_delete($user_id, 'user_meta'); }
            foreach (array_reverse($locks) as $name) { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name)); }
            self::$transaction_active = false;
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /** Record provider usage without touching visitor balances, quotas, or Cloud receipts. */
    public static function record_provider_request(string $provider, string $model, ?array $usage, string $module, string $operation): void
    {
        if ($provider === '' || $model === '' || strtolower($provider) === 'aipuffercloud' || $usage === null) { return; }
        $input = max(0, (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? ($operation === 'embed' ? ($usage['total_tokens'] ?? 0) : 0)));
        $output = max(0, (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0));
        (new self())->insert_entry([
            'entry_type' => 'request', 'user_id' => get_current_user_id() ?: null,
            'module' => $module, 'operation' => $operation, 'provider' => $provider, 'model' => $model,
            'usage_input_units' => $input, 'usage_output_units' => $output,
            'usage_total_units' => max($input + $output, (int) ($usage['total_tokens'] ?? 0)),
            'meta' => ['usage_unit' => 'token'],
        ]);
    }

    /**
     * @param array<string, mixed> $entry
     * @return int|WP_Error
     */
    public function insert_entry(array $entry)
    {
        global $wpdb;

        if (!$this->table_exists()) {
            return new WP_Error('aipkit_token_ledger_missing', __('Token ledger table is not available.', 'gpt3-ai-content-generator'));
        }

        $idempotency_key = sanitize_text_field((string) ($entry['idempotency_key'] ?? ''));
        if ($idempotency_key !== '') {
            $existing = $this->find_by_idempotency_key($idempotency_key);
            if (is_array($existing) && !empty($existing['id'])) {
                // A caller inside accounting must decide duplicates before changing a
                // balance. A late duplicate must roll back, never commit a second debit.
                if (self::$transaction_active) { return new WP_Error('aipkit_usage_duplicate', 'Local usage was already recorded.'); }
                return (int) $existing['id'];
            }
            if ($wpdb->last_error !== '') { return new WP_Error('aipkit_usage_lookup_failed', 'Local usage could not be checked.'); }
        }

        $user_id = isset($entry['user_id']) && is_numeric($entry['user_id']) ? absint($entry['user_id']) : null;
        $session_id = sanitize_text_field((string) ($entry['session_id'] ?? ''));
        $meta = $entry['meta'] ?? null;
        if (is_array($meta) || is_object($meta)) {
            $meta = wp_json_encode($meta);
        } elseif ($meta !== null) {
            $meta = (string) $meta;
        }

        $data = [
            'user_id' => $user_id,
            'session_id' => $session_id !== '' ? $session_id : null,
            'actor_type' => $user_id ? 'user' : 'guest',
            'module' => sanitize_key((string) ($entry['module'] ?? 'chat')),
            'context_type' => $this->sanitize_nullable_text($entry['context_type'] ?? null),
            'context_id' => isset($entry['context_id']) && $entry['context_id'] !== '' ? absint($entry['context_id']) : null,
            'provider' => $this->sanitize_nullable_text($entry['provider'] ?? null),
            'model' => $this->sanitize_nullable_text($entry['model'] ?? null),
            'operation' => sanitize_text_field((string) ($entry['operation'] ?? 'usage')),
            'usage_input_units' => max(0, (int) ($entry['usage_input_units'] ?? 0)),
            'usage_output_units' => max(0, (int) ($entry['usage_output_units'] ?? 0)),
            'usage_total_units' => max(0, (int) ($entry['usage_total_units'] ?? 0)),
            'credits_delta' => (int) ($entry['credits_delta'] ?? 0),
            'entry_type' => sanitize_key((string) ($entry['entry_type'] ?? 'usage')),
            'reference_type' => $this->sanitize_nullable_text($entry['reference_type'] ?? null),
            'reference_id' => $this->sanitize_nullable_text($entry['reference_id'] ?? null),
            'idempotency_key' => $idempotency_key !== '' ? $idempotency_key : null,
            'meta' => $meta,
            'created_at' => sanitize_text_field((string) ($entry['created_at'] ?? current_time('mysql', 1))),
        ];

        $formats = [
            '%d',
            '%s',
            '%s',
            '%s',
            '%s',
            '%d',
            '%s',
            '%s',
            '%s',
            '%d',
            '%d',
            '%d',
            '%d',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
        ];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Insert into a plugin-owned ledger table.
        $inserted = $wpdb->insert($this->table_name, $data, $formats);
        if ($inserted === false) {
            return new WP_Error('aipkit_token_ledger_insert_failed', __('Failed to write token ledger entry.', 'gpt3-ai-content-generator'));
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find_by_idempotency_key(string $idempotency_key): ?array
    {
        global $wpdb;

        if ($idempotency_key === '' || !$this->table_exists()) {
            return null;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe custom table name on a prepared ledger lookup.
        $lookup_query = "SELECT * FROM {$this->table_name} WHERE idempotency_key = %s LIMIT 1";
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $prepared_lookup_query = $wpdb->prepare(
            $lookup_query,
            $idempotency_key
        );
        // phpcs:enable
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared lookup against a plugin-owned ledger table.
        $row = $wpdb->get_row($prepared_lookup_query, ARRAY_A);
        if (self::$transaction_active && $wpdb->last_error !== '') { throw new \RuntimeException('Local receipt lookup failed.'); }

        return is_array($row) ? $row : null;
    }

    /**
     * @param mixed $value
     */
    private function sanitize_nullable_text($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return sanitize_text_field((string) $value);
    }

    private function table_exists(): bool
    {
        global $wpdb;

        static $cache = [];
        if (isset($cache[$this->table_name])) {
            return $cache[$this->table_name];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time table existence check per request.
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->table_name)) === $this->table_name;
        $cache[$this->table_name] = $exists;

        return $exists;
    }
}

// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

/** Reads and updates the user's available credit balance. */
class AIPKit_Balance_Service
{
    public function get_current_balance(?int $user_id): int
    {
        if (!$user_id) {
            return 0;
        }

        $balance = get_user_meta($user_id, MetaKeysConstants::TOKEN_BALANCE_META_KEY, true);

        return is_numeric($balance) ? max(0, (int) $balance) : 0;
    }

    public function set_current_balance(int $user_id, int $balance): int
    {
        $balance = max(0, $balance);
        if ($this->get_current_balance($user_id) !== $balance
            && update_user_meta($user_id, MetaKeysConstants::TOKEN_BALANCE_META_KEY, $balance) === false) {
            throw new \RuntimeException('Local balance could not be saved.');
        }

        return $balance;
    }

    /**
     * @return array<string, int>
     */
    public function deduct_available_balance(int $user_id, int $requested_units): array
    {
        $requested_units = max(0, $requested_units);
        $balance_before = $this->get_current_balance($user_id);
        $deducted = min($balance_before, $requested_units);
        $balance_after = $balance_before - $deducted;

        $this->set_current_balance($user_id, $balance_after);

        return [
            'balance_before' => $balance_before,
            'deducted' => $deducted,
            'balance_after' => $balance_after,
            'remaining' => max(0, $requested_units - $deducted),
        ];
    }
}
