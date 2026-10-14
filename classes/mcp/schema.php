<?php
/** The three website-management tables: operations (journal and activity), grants and request nonces. */
namespace WPAICG\Mcp;

if (!defined('ABSPATH')) { exit; }

final class Schema
{
    public static function table(string $name): string
    {
        global $wpdb;
        if (!in_array($name, ['operations', 'grants', 'nonces'], true)) { throw new \InvalidArgumentException('invalid_table'); }
        return $wpdb->prefix . 'aipkit_mcp_' . $name;
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collation = $wpdb->get_charset_collate();
        $operations = self::table('operations');
        $grants = self::table('grants');
        $nonces = self::table('nonces');
        dbDelta("CREATE TABLE $operations (
            operation_id varchar(36) NOT NULL,
            account_id varchar(80) NOT NULL,
            site_id varchar(80) NOT NULL,
            instance_id varchar(36) NOT NULL,
            connection_id varchar(80) NOT NULL,
            acting_user bigint unsigned NOT NULL,
            grant_id varchar(40) NOT NULL,
            tool varchar(40) NOT NULL,
            intent_hash char(64) NOT NULL,
            args longtext NOT NULL,
            state varchar(24) NOT NULL,
            response longtext DEFAULT NULL,
            expires_at bigint unsigned NOT NULL DEFAULT 0,
            created_at bigint unsigned NOT NULL,
            updated_at bigint unsigned NOT NULL,
            reported_at bigint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (operation_id),
            KEY state_updated (state, updated_at),
            KEY grant_created (grant_id, created_at),
            KEY created (created_at)
        ) ENGINE=InnoDB $collation;");
        dbDelta("CREATE TABLE $grants (
            grant_id varchar(40) NOT NULL,
            instance_id varchar(36) NOT NULL,
            wp_user_id bigint unsigned NOT NULL,
            wp_user_login varchar(60) NOT NULL,
            access_level varchar(24) NOT NULL,
            profile varchar(24) NOT NULL,
            scopes text NOT NULL,
            state varchar(16) NOT NULL,
            connection_id varchar(80) NOT NULL DEFAULT '',
            client_name varchar(191) NOT NULL DEFAULT '',
            client_hint varchar(32) NOT NULL DEFAULT '',
            secret_enc text NOT NULL,
            created_at bigint unsigned NOT NULL,
            last_used_at bigint unsigned DEFAULT NULL,
            PRIMARY KEY  (grant_id),
            KEY user_state (wp_user_id, state),
            KEY instance_state (instance_id, state)
        ) ENGINE=InnoDB $collation;");
        dbDelta("CREATE TABLE $nonces (
            nonce varchar(22) NOT NULL,
            expires_at bigint unsigned NOT NULL,
            PRIMARY KEY  (nonce),
            KEY expires_at (expires_at)
        ) ENGINE=InnoDB $collation;");
    }

    public static function ready(): bool
    {
        global $wpdb;
        $required = [
            'operations' => ['operation_id', 'account_id', 'site_id', 'instance_id', 'connection_id', 'acting_user', 'grant_id', 'tool', 'intent_hash', 'args', 'state', 'response', 'expires_at', 'created_at', 'updated_at', 'reported_at'],
            'grants' => ['grant_id', 'instance_id', 'wp_user_id', 'wp_user_login', 'access_level', 'profile', 'scopes', 'state', 'connection_id', 'client_name', 'client_hint', 'secret_enc', 'created_at', 'last_used_at'],
            'nonces' => ['nonce', 'expires_at'],
        ];
        foreach ($required as $name => $columns) {
            $table = self::table($name);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live InnoDB/schema inspection; names come only from Schema allowlist or WordPress tables; cached schema checks could miss changes.
            $row = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', self::table($name)), ARRAY_A);
            if (!$row || strcasecmp((string) $row['Engine'], 'InnoDB') !== 0) { return false; }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live InnoDB/schema inspection; names come only from Schema allowlist or WordPress tables; cached schema checks could miss changes.
            $found = $wpdb->get_col("SHOW COLUMNS FROM $table");
            if (!is_array($found) || array_diff($columns, $found)) { return false; }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Live InnoDB/schema inspection; names come only from Schema allowlist or WordPress tables; cached schema checks could miss changes.
            $indexes = $wpdb->get_results("SHOW INDEX FROM $table", ARRAY_A);
            $primary = array_values(array_filter((array) $indexes, static function ($index) { return $index['Key_name'] === 'PRIMARY'; }));
            if (count($primary) !== 1 || $primary[0]['Column_name'] !== $columns[0]
                || (int) $primary[0]['Non_unique'] !== 0 || $primary[0]['Sub_part'] !== null) { return false; }
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live InnoDB/schema inspection; names come only from Schema allowlist or WordPress tables; cached schema checks could miss changes.
        $posts = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', $wpdb->posts), ARRAY_A);
        if (!$posts || strcasecmp((string) $posts['Engine'], 'InnoDB') !== 0) { return false; }
        return true;
    }
}
