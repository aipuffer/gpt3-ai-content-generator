<?php


/**
 * Database Schema Definitions for AIPKit.
 * Contains functions to create and update plugin-specific database tables.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Creates or updates the chat logs database table.
 * Uses dbDelta for safe table creation/updates.
 * **REVISED** Schema: One row per conversation thread, messages stored in JSON.
 * **ADDED**: `module` column to track the source.
 */
function aipkit_create_logs_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'aipkit_chat_logs';
    $charset_collate = $wpdb->get_charset_collate();

    // Use LONGTEXT for messages JSON to store potentially long history
    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        bot_id bigint(20) unsigned DEFAULT NULL,
        user_id bigint(20) unsigned DEFAULT NULL,
        session_id varchar(64) DEFAULT NULL,
        conversation_uuid varchar(36) NOT NULL,
        is_guest tinyint(1) NOT NULL DEFAULT 0,
        module varchar(50) NULL DEFAULT NULL,
        messages longtext NOT NULL,
        form_state longtext DEFAULT NULL,
        message_count int unsigned NOT NULL DEFAULT 0,
        first_message_ts bigint(20) unsigned DEFAULT NULL,
        last_message_ts bigint(20) unsigned DEFAULT NULL,
        ip_address varchar(100) DEFAULT NULL,
        user_wp_role varchar(100) DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY unique_conversation (bot_id, user_id, session_id, conversation_uuid, module),
        KEY bot_id (bot_id),
        KEY user_id (user_id),
        KEY session_id (session_id),
        KEY conversation_uuid (conversation_uuid),
        KEY is_guest (is_guest),
        KEY module (module),
        KEY last_message_ts (last_message_ts),
        KEY created_at (created_at),
        KEY updated_at (updated_at)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Creates or updates the guest token usage database table.
 * Uses dbDelta for safe table creation/updates.
 */
function aipkit_create_guest_token_usage_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'aipkit_guest_token_usage';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        session_id varchar(64) NOT NULL,
        bot_id bigint(20) unsigned NOT NULL,
        tokens_used bigint(20) unsigned NOT NULL DEFAULT 0,
        last_reset_timestamp bigint(20) unsigned NOT NULL DEFAULT 0,
        last_updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY unique_guest_bot (session_id, bot_id),
        KEY session_id (session_id),
        KEY bot_id (bot_id),
        KEY last_reset_timestamp (last_reset_timestamp)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Creates or updates the SSE message cache database table.
 * Uses dbDelta for safe table creation/updates.
 * Only used if WP Object Cache is not available.
 */
function aipkit_create_sse_message_cache_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'aipkit_sse_message_cache';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        cache_key varchar(191) NOT NULL,
        message_content longtext NOT NULL,
        expires_at datetime NOT NULL,
        PRIMARY KEY  (cache_key),
        KEY expires_at (expires_at)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Creates or updates the vector data source table.
 */
function aipkit_create_vector_data_source_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'aipkit_vector_data_source';
    $charset_collate = $wpdb->get_charset_collate();

    // REMOVED ALL INLINE SQL COMMENTS FOR dbDelta COMPATIBILITY
    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned DEFAULT NULL,
        timestamp datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        provider varchar(50) NOT NULL,
        vector_store_id varchar(100) NOT NULL,
        vector_store_name varchar(255) DEFAULT NULL,
        post_id bigint(20) unsigned DEFAULT NULL,
        post_title text DEFAULT NULL,
        status varchar(50) NOT NULL,
        message text DEFAULT NULL,
        indexed_content longtext DEFAULT NULL,
        file_id varchar(512) DEFAULT NULL,
        batch_id varchar(512) DEFAULT NULL,
        embedding_provider varchar(50) DEFAULT NULL,
        embedding_model varchar(100) DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY user_id (user_id),
        KEY timestamp (timestamp),
        KEY provider_store_id (provider, vector_store_id),
        KEY provider_store_time (provider, vector_store_id, timestamp),
        KEY post_id (post_id),
        KEY file_id (file_id(191)),
        KEY status (status),
        KEY embedding_provider (embedding_provider),
        KEY embedding_model (embedding_model)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}


/**
 * RENAMED: Creates or updates the automated tasks table.
 */
/**
 * Built-in knowledge base ("This site"): stores and their chunks with packed float32 vectors.
 * Searched in PHP (classes/knowledge-base/providers/local.php); no database vector type is needed.
 */
function aipkit_create_local_vector_tables()
{
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $stores = $wpdb->prefix . 'aipkit_vector_stores';
    $vectors = $wpdb->prefix . 'aipkit_vectors';
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta("CREATE TABLE $stores (
        id varchar(64) NOT NULL,
        name varchar(191) NOT NULL,
        embedding_provider varchar(50) DEFAULT NULL,
        embedding_model varchar(100) DEFAULT NULL,
        dimensions smallint(5) unsigned NOT NULL DEFAULT 0,
        chunk_count int(10) unsigned NOT NULL DEFAULT 0,
        bytes bigint(20) unsigned NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;");
    dbDelta("CREATE TABLE $vectors (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        store_id varchar(64) NOT NULL,
        vector_id varchar(64) NOT NULL,
        parent_id varchar(191) DEFAULT NULL,
        post_id bigint(20) unsigned DEFAULT NULL,
        chunk_index int(10) unsigned NOT NULL DEFAULT 0,
        content mediumtext NOT NULL,
        metadata longtext DEFAULT NULL,
        embedding mediumblob NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY store_vector (store_id,vector_id),
        KEY store_parent (store_id,parent_id),
        KEY store_post (store_id,post_id)
    ) $charset_collate;");
}

function aipkit_create_automated_tasks_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'aipkit_automated_tasks';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        task_name varchar(255) NOT NULL,
        task_type varchar(50) NOT NULL,
        task_config longtext NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'paused',
        last_run_time datetime DEFAULT NULL,
        next_run_time datetime DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY task_type (task_type),
        KEY status (status),
        KEY next_run_time (next_run_time)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Adds the queue performance column and indexes in one ALTER TABLE operation.
 *
 * Combining the clauses is important for very large queues because MySQL can
 * build every missing index during one table-alter pass instead of repeating
 * that work for each index.
 */
function aipkit_upgrade_automated_task_queue_performance_schema($table_name)
{
    global $wpdb;

    if (!is_string($table_name) || preg_match('/^[A-Za-z0-9_]+$/', $table_name) !== 1) {
        return false;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Metadata lookup on a validated plugin-owned table identifier.
    $sort_priority_exists = $wpdb->get_var("SHOW COLUMNS FROM `{$table_name}` LIKE 'sort_priority'");
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Metadata lookup on a validated plugin-owned table identifier.
    $index_rows = $wpdb->get_results("SHOW INDEX FROM `{$table_name}`");
    $existing_indexes = array_unique(array_filter(array_map(static function ($row) {
        return isset($row->Key_name) ? (string) $row->Key_name : '';
    }, (array) $index_rows)));

    $alter_clauses = [];
    if ($sort_priority_exists !== 'sort_priority') {
        $alter_clauses[] = 'ADD COLUMN sort_priority tinyint unsigned NOT NULL DEFAULT 1';
    }

    $required_indexes = [
        'queue_sort' => 'ADD KEY queue_sort (sort_priority, added_at, id)',
        'task_status_type' => 'ADD KEY task_status_type (task_id, status, task_type)',
        'status_task_id' => 'ADD KEY status_task_id (status, task_id, id)',
        'task_target' => 'ADD KEY task_target (task_id, target_identifier(100))',
    ];
    foreach ($required_indexes as $index_name => $clause) {
        if (!in_array($index_name, $existing_indexes, true)) {
            $alter_clauses[] = $clause;
        }
    }

    if (empty($alter_clauses)) {
        return true;
    }

    $alter_sql = "ALTER TABLE `{$table_name}` " . implode(', ', $alter_clauses);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time schema migration using a validated table identifier and fixed clauses.
    return $wpdb->query($alter_sql) !== false;
}

/**
 * RENAMED: Creates or updates the automated task queue table.
 */
function aipkit_create_automated_task_queue_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'aipkit_automated_task_queue';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        task_id bigint(20) unsigned NOT NULL,
        target_identifier varchar(255) NOT NULL,
        task_type varchar(50) NOT NULL,
        item_config longtext DEFAULT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        attempts tinyint unsigned NOT NULL DEFAULT 0,
        last_attempt_time datetime DEFAULT NULL,
        error_message text DEFAULT NULL,
        added_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        sort_priority tinyint unsigned NOT NULL DEFAULT 1,
        PRIMARY KEY  (id),
        KEY task_id (task_id),
        KEY target_identifier (target_identifier(191)),
        KEY task_type (task_type),
        KEY status_added_at (status, added_at),
        KEY queue_sort (sort_priority, added_at, id),
        KEY task_status_type (task_id, status, task_type),
        KEY status_task_id (status, task_id, id),
        KEY task_target (task_id, target_identifier(100))
    ) $charset_collate;";

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table existence check for a plugin-owned table.
    $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) === $table_name;
    if ($table_exists) {
        if (!aipkit_upgrade_automated_task_queue_performance_schema($table_name)) {
            return false;
        }
    } else {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        if (!aipkit_upgrade_automated_task_queue_performance_schema($table_name)) {
            return false;
        }
    }

    // Existing processing rows predate sort_priority. This indexed, narrowly
    // filtered update preserves their previous top-of-queue presentation.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time schema normalization on a plugin-owned table.
    $wpdb->query("UPDATE {$table_name} SET sort_priority = 2 WHERE status = 'processing' AND sort_priority <> 2");

    return true;
}

/**
 * NEW: Creates or updates the content writer templates table.
 * UPDATED: Added new fields for post settings.
 * UPDATED: Removed post_tags column.
 */
function aipkit_create_content_writer_templates_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'aipkit_content_writer_templates';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        template_name varchar(255) NOT NULL,
        template_type varchar(50) NOT NULL DEFAULT 'content_writer',
        config longtext NOT NULL,
        is_default tinyint(1) NOT NULL DEFAULT 0,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        post_type varchar(20) DEFAULT 'post',
        post_author bigint(20) unsigned DEFAULT NULL,
        post_status varchar(20) DEFAULT 'draft',
        post_schedule datetime DEFAULT NULL,
        post_categories text DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY user_template_name_type (user_id, template_name(191), template_type),
        KEY user_id (user_id),
        KEY template_type (template_type),
        KEY is_default (is_default),
        KEY post_type (post_type)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Creates the RSS history table to prevent re-processing feed items.
 */
function aipkit_create_rss_history_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'aipkit_rss_history';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        task_id bigint(20) unsigned NOT NULL,
        item_guid varchar(255) NOT NULL,
        processed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY unique_task_guid (task_id, item_guid(191)),
        KEY task_id (task_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Creates or updates the event delivery queue table.
 * This is the durable foundation for async webhook and app delivery.
 */
function aipkit_create_event_delivery_queue_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'aipkit_event_delivery_queue';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        job_uuid varchar(36) NOT NULL,
        event_id varchar(36) NOT NULL,
        event_name varchar(191) NOT NULL,
        event_idempotency_key varchar(191) DEFAULT NULL,
        source_module varchar(50) DEFAULT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        attempt_count int unsigned NOT NULL DEFAULT 0,
        target_count int unsigned NOT NULL DEFAULT 0,
        available_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        locked_at datetime DEFAULT NULL,
        processed_at datetime DEFAULT NULL,
        last_error_message text DEFAULT NULL,
        envelope_json longtext NOT NULL,
        context_json longtext DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY job_uuid (job_uuid),
        UNIQUE KEY event_id (event_id),
        KEY status_available_at (status, available_at),
        KEY event_name_created_at (event_name, created_at),
        KEY source_module_status (source_module, status),
        KEY processed_at (processed_at)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Creates or updates the pricing rules table used for model-aware billing.
 */
function aipkit_create_pricing_rules_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'aipkit_pricing_rules';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        scope_type varchar(32) NOT NULL,
        scope_id bigint(20) unsigned NOT NULL DEFAULT 0,
        module varchar(50) NOT NULL,
        provider varchar(50) NOT NULL,
        model varchar(191) NOT NULL,
        operation varchar(50) NOT NULL,
        billing_method varchar(32) NOT NULL,
        input_rate decimal(20,6) DEFAULT NULL,
        output_rate decimal(20,6) DEFAULT NULL,
        unit_rate decimal(20,6) DEFAULT NULL,
        enabled tinyint(1) NOT NULL DEFAULT 1,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY scope_rule_lookup (scope_type, scope_id, module(32), provider(32), model(100), operation(32)),
        KEY module_model_operation (module(32), provider(32), model(100), operation(32)),
        KEY scope_lookup (scope_type, scope_id),
        KEY enabled (enabled)
    ) {$charset_collate};";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Creates or updates the ledger table used for purchases, usage, and adjustments.
 */
function aipkit_create_token_ledger_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'aipkit_token_ledger';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned DEFAULT NULL,
        session_id varchar(64) DEFAULT NULL,
        actor_type varchar(16) NOT NULL,
        module varchar(50) NOT NULL,
        context_type varchar(32) DEFAULT NULL,
        context_id bigint(20) unsigned DEFAULT NULL,
        provider varchar(50) DEFAULT NULL,
        model varchar(191) DEFAULT NULL,
        operation varchar(50) NOT NULL,
        usage_input_units bigint(20) unsigned NOT NULL DEFAULT 0,
        usage_output_units bigint(20) unsigned NOT NULL DEFAULT 0,
        usage_total_units bigint(20) unsigned NOT NULL DEFAULT 0,
        credits_delta bigint(20) NOT NULL DEFAULT 0,
        entry_type varchar(32) NOT NULL,
        reference_type varchar(32) DEFAULT NULL,
        reference_id varchar(191) DEFAULT NULL,
        idempotency_key varchar(191) DEFAULT NULL,
        meta longtext DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY idempotency_key (idempotency_key),
        KEY user_created_at (user_id, created_at),
        KEY session_created_at (session_id, created_at),
        KEY module_context_created_at (module, context_type, context_id, created_at),
        KEY provider_model_operation (provider(32), model(100), operation(32)),
        KEY entry_type_created_at (entry_type, created_at)
    ) {$charset_collate};";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
