<?php

namespace WPAICG;

if (!defined('ABSPATH')) {
    exit;
}

use WPAICG\Chat\Storage\DefaultBotSetup;
use WPAICG\Core\TokenManager\AIPKit_Token_Manager;
use WPAICG\Core\Stream\Cache\AIPKit_SSE_Message_Cache;
use WPAICG\AutoGPT\AIPKit_Automated_Task_Cron;
use WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager;
use WPAICG\Core\AIPKit_Event_Queue_Worker;
use WPAICG\AutoGPT\Cron\AIPKit_Automated_Task_Scheduler;
use WPAICG\AutoGPT\Cron\AIPKit_Automated_Task_Event_Processor;
use WPAICG\AutoGPT\Cron\AIPKit_Automation_Runner;
use WPAICG\Chat\Storage\LogCronManager;
use WPAICG\Lib\Integrations\Logs\AIPKit_Recipe_Delivery_Log_Maintenance;
use WPAICG\Lib\Integrations\Logs\AIPKit_Recipe_Delivery_Log_Store;
use WPAICG\Core\Models\AIPKit_Model_Registry;

/**
 * Update and schema checks for WP_AI_Content_Generator.
 * Uses its version property and schema constants to preserve existing callback APIs.
 */
trait AIPKit_Plugin_Updates
{
    /**
     * Check for plugin updates (e.g., version change) and run necessary routines.
     * Runs on the 'init' action in installation, admin, cron and CLI contexts.
     */
    public function check_for_updates()
    {
        if (!$this->should_run_update_check()) {
            return;
        }

        $current_version = $this->version;
        $saved_version = get_option(self::DB_VERSION_OPTION);
        $saved_token_manager_schema_version = get_option(self::TOKEN_MANAGER_SCHEMA_VERSION_OPTION);
        $saved_performance_schema_version = get_option(self::PERFORMANCE_SCHEMA_VERSION_OPTION);
        $saved_model_registry_schema_version = get_option(self::MODEL_REGISTRY_SCHEMA_VERSION_OPTION);
        $saved_conversation_state_schema_version = get_option(self::CONVERSATION_STATE_SCHEMA_VERSION_OPTION);

        $version_needs_update = version_compare((string) $saved_version, $current_version, '<');
        $token_manager_schema_needs_update = version_compare(
            (string) $saved_token_manager_schema_version,
            self::TOKEN_MANAGER_SCHEMA_VERSION,
            '<'
        );
        $performance_schema_needs_update = version_compare(
            (string) $saved_performance_schema_version,
            self::PERFORMANCE_SCHEMA_VERSION,
            '<'
        );
        $model_registry_schema_needs_update = version_compare(
            (string) $saved_model_registry_schema_version,
            self::MODEL_REGISTRY_SCHEMA_VERSION,
            '<'
        );
        $conversation_state_schema_needs_update = version_compare(
            (string) $saved_conversation_state_schema_version,
            self::CONVERSATION_STATE_SCHEMA_VERSION,
            '<'
        );

        $tables_are_missing = false;
        $performance_schema_missing = false;
        $conversation_state_schema_missing = false;

        if ($version_needs_update || $token_manager_schema_needs_update || $performance_schema_needs_update || $model_registry_schema_needs_update || $conversation_state_schema_needs_update || $this->should_run_install_integrity_check()) {
            $tables_are_missing = $this->are_plugin_tables_missing();
            $performance_schema_missing = self::is_performance_schema_missing();
            $conversation_state_schema_missing = self::is_conversation_state_schema_missing();
        }

        if (!$version_needs_update && !$tables_are_missing && !$performance_schema_missing && !$conversation_state_schema_missing && !$token_manager_schema_needs_update && !$performance_schema_needs_update && !$model_registry_schema_needs_update && !$conversation_state_schema_needs_update) {
            set_transient(self::INSTALL_INTEGRITY_TRANSIENT, '1', DAY_IN_SECONDS);
            return;
        }

        delete_transient(self::INSTALL_INTEGRITY_TRANSIENT);

        $this->clear_external_caches();

        // Run DB table setup on version change to apply any schema updates.
        WP_AI_Content_Generator_Activator::setup_tables_for_blog();
        $tables_are_missing = $this->are_plugin_tables_missing();
        $performance_schema_missing = self::is_performance_schema_missing();
        $conversation_state_schema_missing = self::is_conversation_state_schema_missing();
        $this->cleanup_legacy_chatbot_pricing_overrides();

        $model_registry_migrated = true;
        if ($model_registry_schema_needs_update) {
            $model_registry_migrated = class_exists(AIPKit_Model_Registry::class)
                && AIPKit_Model_Registry::migrate_legacy_options();
        }

        // Ensure Role Manager Permissions are Updated/Initialized
        if (class_exists('\\WPAICG\\AIPKit_Role_Manager')) {
            \WPAICG\AIPKit_Role_Manager::update_permissions_on_activation();
        }

        // Ensure Default Chatbot exists
        if (class_exists('\\WPAICG\\Chat\\Storage\\DefaultBotSetup')) {
            \WPAICG\Chat\Storage\DefaultBotSetup::ensure_default_chatbot();
        }

        // Ensure Default Content Writer Template exists
        if (class_exists('\\WPAICG\\ContentWriter\\AIPKit_Content_Writer_Template_Manager')) {
            \WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager::ensure_default_template_exists();
        }

        // Ensure Cron Jobs are scheduled
        if (class_exists('\\WPAICG\\Core\\TokenManager\\AIPKit_Token_Manager')) {
            \WPAICG\Core\TokenManager\AIPKit_Token_Manager::schedule_token_reset_event();
        }
        if (class_exists('\\WPAICG\\Core\\Stream\\Cache\\AIPKit_SSE_Message_Cache')) {
            \WPAICG\Core\Stream\Cache\AIPKit_SSE_Message_Cache::schedule_cleanup_event();
        }
        if (class_exists('\\WPAICG\\AutoGPT\\AIPKit_Automated_Task_Cron')) {
            \WPAICG\AutoGPT\AIPKit_Automated_Task_Cron::init();
        }

        // Update the stored version
        update_option(self::DB_VERSION_OPTION, $current_version, 'no'); // Use autoload 'no'
        update_option(self::TOKEN_MANAGER_SCHEMA_VERSION_OPTION, self::TOKEN_MANAGER_SCHEMA_VERSION, 'no');
        if (!$performance_schema_missing) {
            update_option(self::PERFORMANCE_SCHEMA_VERSION_OPTION, self::PERFORMANCE_SCHEMA_VERSION, 'no');
        }
        if ($model_registry_migrated) {
            update_option(self::MODEL_REGISTRY_SCHEMA_VERSION_OPTION, self::MODEL_REGISTRY_SCHEMA_VERSION, 'no');
        }
        if (!$conversation_state_schema_missing) {
            update_option(self::CONVERSATION_STATE_SCHEMA_VERSION_OPTION, self::CONVERSATION_STATE_SCHEMA_VERSION, 'no');
        }
        if (!$tables_are_missing && !$performance_schema_missing && !$conversation_state_schema_missing && $model_registry_migrated) {
            set_transient(self::INSTALL_INTEGRITY_TRANSIENT, '1', DAY_IN_SECONDS);
        }
    }

    private function should_run_update_check(): bool
    {
        if (function_exists('wp_installing') && wp_installing()) {
            return true;
        }

        if (is_admin() || wp_doing_cron()) {
            return true;
        }

        return defined('WP_CLI') && WP_CLI;
    }

    private function should_run_install_integrity_check(): bool
    {
        return false === get_transient(self::INSTALL_INTEGRITY_TRANSIENT);
    }

    private function cleanup_legacy_chatbot_pricing_overrides(): void
    {
        global $wpdb;

        $pricing_rules_table = $wpdb->prefix . 'aipkit_pricing_rules';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time plugin migration cleanup.
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $pricing_rules_table));
        if ($table_exists === $pricing_rules_table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time plugin migration cleanup against a plugin-owned table.
            $wpdb->query($wpdb->prepare("DELETE FROM {$pricing_rules_table} WHERE scope_type IN (%s, %s)", 'chatbot', 'bot'));
        }

        delete_post_meta_by_key('_aipkit_token_pricing_mode');
    }

    /**
     * NEW: Helper function to check if any of our custom tables are missing.
     * This adds robustness to the update process.
     * @return bool True if one or more tables are missing.
     */
    private function are_plugin_tables_missing(): bool
    {
        global $wpdb;
        $required_tables = [
            'aipkit_chat_logs',
            'aipkit_guest_token_usage',
            'aipkit_sse_message_cache',
            'aipkit_vector_data_source',
            'aipkit_vector_stores',
            'aipkit_vectors',
            'aipkit_automated_tasks',
            'aipkit_automated_task_queue',
            'aipkit_content_writer_templates',
            'aipkit_rss_history',
            'aipkit_event_delivery_queue',
            'aipkit_recipe_delivery_logs',
            'aipkit_pricing_rules',
            'aipkit_token_ledger',
        ];

        $recipe_log_store_path = WPAICG_PLUGIN_DIR . 'lib/integrations/delivery-log.php';
        foreach ($required_tables as $table_suffix) {
            if ($table_suffix === 'aipkit_recipe_delivery_logs' && !file_exists($recipe_log_store_path)) {
                continue;
            }
            $table_name = $wpdb->prefix . $table_suffix;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Necessary check for table existence during plugin initialization/update.
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) !== $table_name) {
                return true; // Found a missing table
            }
        }
        return false;
    }

    /**
     * Checks whether the large-dataset columns and indexes exist.
     * @return bool True if any performance schema change is missing.
     */
    public static function is_performance_schema_missing(): bool
    {
        global $wpdb;
        $vector_table_name = $wpdb->prefix . 'aipkit_vector_data_source';
        $queue_table_name = $wpdb->prefix . 'aipkit_automated_task_queue';

        // Missing tables are handled by are_plugin_tables_missing().
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Metadata checks for plugin-owned tables.
        $vector_table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $vector_table_name));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Metadata checks for plugin-owned tables.
        $queue_table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $queue_table_name));
        if ($vector_table_exists !== $vector_table_name || $queue_table_exists !== $queue_table_name) {
            return true;
        }

        $required_vector_indexes = ['provider_store_time'];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table identifier is derived from $wpdb->prefix and index name is prepared.
        $vector_index_rows = $wpdb->get_results($wpdb->prepare("SHOW INDEX FROM {$vector_table_name} WHERE Key_name = %s", ...$required_vector_indexes));
        $existing_vector_indexes = array_unique(array_map(static function ($row) {
            return isset($row->Key_name) ? (string) $row->Key_name : '';
        }, (array) $vector_index_rows));
        if (count(array_intersect($required_vector_indexes, $existing_vector_indexes)) !== count($required_vector_indexes)) {
            return true;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Metadata check on a plugin-owned queue table.
        $sort_priority_column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$queue_table_name} LIKE %s", 'sort_priority'));
        if ($sort_priority_column !== 'sort_priority') {
            return true;
        }

        $required_queue_indexes = ['queue_sort', 'task_status_type', 'status_task_id', 'task_target'];
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Metadata check on a plugin-owned table whose identifier is derived from $wpdb->prefix; all index names use placeholders.
        $queue_index_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SHOW INDEX FROM {$queue_table_name} WHERE Key_name IN (%s, %s, %s, %s)",
                $required_queue_indexes[0],
                $required_queue_indexes[1],
                $required_queue_indexes[2],
                $required_queue_indexes[3]
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $existing_queue_indexes = array_unique(array_map(static function ($row) {
            return isset($row->Key_name) ? (string) $row->Key_name : '';
        }, (array) $queue_index_rows));

        return count(array_intersect($required_queue_indexes, $existing_queue_indexes)) !== count($required_queue_indexes);
    }

    /**
     * Checks whether durable per-conversation state can be stored.
     *
     * @return bool True when the conversation state column is missing.
     */
    public static function is_conversation_state_schema_missing(): bool
    {
        global $wpdb;

        $chat_logs_table_name = $wpdb->prefix . 'aipkit_chat_logs';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Metadata check on a plugin-owned table.
        $chat_logs_table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $chat_logs_table_name));
        if ($chat_logs_table_exists !== $chat_logs_table_name) {
            return true;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table identifier is derived from $wpdb->prefix and the column name is prepared.
        $form_state_column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$chat_logs_table_name} LIKE %s", 'form_state'));

        return $form_state_column !== 'form_state';
    }

    /**
     * Clears caches after a plugin update.
     */
    private function clear_external_caches()
    {
        if (false === apply_filters('aipkit_auto_clear_caches_on_update', true)) {
            return;
        }

        // WP Rocket
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        wp_cache_flush();
    }
}

/**
 * Fired during plugin activation. Also contains multisite setup logic.
 */
class WP_AI_Content_Generator_Activator
{
    /**
     * Main activation routine for single site or per-site activation.
     * Creates tables, records schema versions, ensures default content and schedules jobs.
     */
    public static function activate()
    {
        // Decided before this method writes anything: only a site that never had AI Puffer gets first-run setup.
        // (aipkit_options is not a signal: loading the plugin writes it before the activation hook runs.)
        global $wpdb;
        $is_fresh_install = get_option('aipkit_plugin_version', null) === null
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time check on activation.
            && !$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->posts} WHERE post_type = %s LIMIT 1", 'aipkit_chatbot'));

        // Create database tables if they don't exist.
        self::setup_tables_for_blog();

        // Load the main plugin class to get access to constants.
        if (!class_exists(WP_AI_Content_Generator::class)) {
            require_once WPAICG_PLUGIN_DIR . 'includes/plugin.php';
        }
        // Set the current version in the database. This is crucial for the `check_for_updates`
        // routine to correctly trigger on new installs and version changes.
        update_option(WP_AI_Content_Generator::DB_VERSION_OPTION, WPAICG_VERSION, 'no');
        update_option(WP_AI_Content_Generator::TOKEN_MANAGER_SCHEMA_VERSION_OPTION, WP_AI_Content_Generator::TOKEN_MANAGER_SCHEMA_VERSION, 'no');
        if (!WP_AI_Content_Generator::is_performance_schema_missing()) {
            update_option(WP_AI_Content_Generator::PERFORMANCE_SCHEMA_VERSION_OPTION, WP_AI_Content_Generator::PERFORMANCE_SCHEMA_VERSION, 'no');
        }
        if (!WP_AI_Content_Generator::is_conversation_state_schema_missing()) {
            update_option(WP_AI_Content_Generator::CONVERSATION_STATE_SCHEMA_VERSION_OPTION, WP_AI_Content_Generator::CONVERSATION_STATE_SCHEMA_VERSION, 'no');
        }

        // Fresh installs and reactivations run the same idempotent setup as updates.

        // Update Role Manager permissions, migrating old caps if necessary.
        $role_manager_path = WPAICG_PLUGIN_DIR . 'classes/security/roles.php';
        if (file_exists($role_manager_path)) {
            require_once $role_manager_path;
            if (class_exists('\\WPAICG\\AIPKit_Role_Manager')) {
                \WPAICG\AIPKit_Role_Manager::update_permissions_on_activation();
            }
        }

        // Ensure Default Chatbot exists.
        $default_bot_setup_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/bots.php';
        if (file_exists($default_bot_setup_path)) {
            require_once $default_bot_setup_path;
            if (class_exists('\\WPAICG\\Chat\\Storage\\DefaultBotSetup')) {
                \WPAICG\Chat\Storage\DefaultBotSetup::ensure_default_chatbot();
            }
        }

        // Ensure Default Content Writer Template exists.
        $cw_template_manager_path = WPAICG_PLUGIN_DIR . 'classes/content-writer/templates.php';
        if (file_exists($cw_template_manager_path)) {
            require_once $cw_template_manager_path;
            if (class_exists('\\WPAICG\\ContentWriter\\AIPKit_Content_Writer_Template_Manager')) {
                \WPAICG\ContentWriter\AIPKit_Content_Writer_Template_Manager::ensure_default_template_exists();
            }
        }

        // Schedule cron jobs (methods are idempotent, so it's safe to run).
        if (class_exists(AIPKit_Token_Manager::class)) {
            AIPKit_Token_Manager::schedule_token_reset_event();
        }
        if (class_exists(AIPKit_SSE_Message_Cache::class)) {
            AIPKit_SSE_Message_Cache::schedule_cleanup_event();
        }
        if (class_exists(AIPKit_Automated_Task_Cron::class)) {
            AIPKit_Automated_Task_Cron::init();
        }

        require_once WPAICG_PLUGIN_DIR . 'classes/admin/onboarding.php';
        \WPAICG\Admin\Onboarding::mark_fresh_install($is_fresh_install);
    }

    public static function setup_tables_for_blog($blog_id = null)
    {
        $switched = false;
        if (is_multisite() && $blog_id !== null && get_current_blog_id() !== $blog_id) {
            switch_to_blog($blog_id);
            $switched = true;
        }
        $db_schema_path = WPAICG_PLUGIN_DIR . 'includes/database-schema.php';
        if (!file_exists($db_schema_path)) {
            if ($switched) {
                restore_current_blog();
            }
            return;
        }
        require_once $db_schema_path;

        if (function_exists('aipkit_create_logs_table')) {
            aipkit_create_logs_table();
        }
        if (function_exists('aipkit_create_guest_token_usage_table')) {
            aipkit_create_guest_token_usage_table();
        }
        if (function_exists('aipkit_create_sse_message_cache_table')) {
            aipkit_create_sse_message_cache_table();
        }
        if (function_exists('aipkit_create_vector_data_source_table')) {
            aipkit_create_vector_data_source_table();
        }
        if (function_exists('aipkit_create_local_vector_tables')) {
            aipkit_create_local_vector_tables();
        }
        if (function_exists('aipkit_create_automated_tasks_table')) {
            aipkit_create_automated_tasks_table();
        }
        if (function_exists('aipkit_create_automated_task_queue_table')) {
            aipkit_create_automated_task_queue_table();
        }
        if (function_exists('aipkit_create_content_writer_templates_table')) {
            aipkit_create_content_writer_templates_table();
        }
        if (function_exists('aipkit_create_rss_history_table')) {
            aipkit_create_rss_history_table();
        }
        if (function_exists('aipkit_create_event_delivery_queue_table')) {
            aipkit_create_event_delivery_queue_table();
        }
        $recipe_log_store_path = WPAICG_PLUGIN_DIR . 'lib/integrations/delivery-log.php';
        if (file_exists($recipe_log_store_path)) {
            require_once $recipe_log_store_path;
            AIPKit_Recipe_Delivery_Log_Store::setup_table();
        }
        if (function_exists('aipkit_create_pricing_rules_table')) {
            aipkit_create_pricing_rules_table();
        }
        if (function_exists('aipkit_create_token_ledger_table')) {
            aipkit_create_token_ledger_table();
        }
        if ($switched) {
            restore_current_blog();
        }
    }

    public static function setup_new_blog($blog, $user_id)
    {
        $blog_id = is_object($blog) ? $blog->blog_id : (is_array($blog) ? $blog['blog_id'] : 0);
        if ($blog_id > 0) {
            self::setup_tables_for_blog($blog_id);
            switch_to_blog($blog_id);
            if (class_exists('\\WPAICG\\Chat\\Storage\\DefaultBotSetup')) {
                DefaultBotSetup::ensure_default_chatbot();
            }
            if (class_exists('\\WPAICG\\ContentWriter\\AIPKit_Content_Writer_Template_Manager')) {
                AIPKit_Content_Writer_Template_Manager::ensure_default_template_exists();
            }
            restore_current_blog();
        }
    }

}

/**
 * Fired during plugin deactivation.
 */
class WP_AI_Content_Generator_Deactivator
{
    public static function deactivate()
    {
        if (class_exists('\\WPAICG\\Core\\TokenManager\\AIPKit_Token_Manager')) {
            AIPKit_Token_Manager::unschedule_token_reset_event();
        }

        if (class_exists('\\WPAICG\\Core\\Stream\\Cache\\AIPKit_SSE_Message_Cache')) {
            AIPKit_SSE_Message_Cache::unschedule_cleanup_event();
        }

        if (class_exists('\\WPAICG\\Chat\\Storage\\LogCronManager')) {
            LogCronManager::unschedule_event();
        }

        $recipe_log_maintenance_path = WPAICG_PLUGIN_DIR . 'lib/integrations/delivery-maintenance.php';
        if (file_exists($recipe_log_maintenance_path) && !class_exists(\WPAICG\Lib\Integrations\Logs\AIPKit_Recipe_Delivery_Log_Maintenance::class)) {
            require_once $recipe_log_maintenance_path;
        }
        if (class_exists('\\WPAICG\\Lib\\Integrations\\Logs\\AIPKit_Recipe_Delivery_Log_Maintenance')) {
            AIPKit_Recipe_Delivery_Log_Maintenance::unschedule_cleanup();
        }

        $event_queue_worker_path = WPAICG_PLUGIN_DIR . 'classes/integrations/event-delivery.php';
        if (file_exists($event_queue_worker_path) && !class_exists(\WPAICG\Core\AIPKit_Event_Queue_Worker::class)) {
            require_once $event_queue_worker_path;
        }
        if (class_exists('\\WPAICG\\Core\\AIPKit_Event_Queue_Worker')) {
            AIPKit_Event_Queue_Worker::unschedule_cron();
        }

        $automated_task_scheduler_path = WPAICG_PLUGIN_DIR . 'classes/automations/scheduler.php';
        $automated_task_event_processor_path = WPAICG_PLUGIN_DIR . 'classes/automations/events.php';
        $automation_runner_path = WPAICG_PLUGIN_DIR . 'classes/automations/runner.php';

        if (file_exists($automated_task_scheduler_path) && !class_exists(\WPAICG\AutoGPT\Cron\AIPKit_Automated_Task_Scheduler::class)) {
            require_once $automated_task_scheduler_path;
        }
        if (file_exists($automated_task_event_processor_path) && !class_exists(\WPAICG\AutoGPT\Cron\AIPKit_Automated_Task_Event_Processor::class)) {
            require_once $automated_task_event_processor_path;
        }
        if (file_exists($automation_runner_path) && !class_exists(\WPAICG\AutoGPT\Cron\AIPKit_Automation_Runner::class)) {
            require_once $automation_runner_path;
        }

        if (class_exists('\\WPAICG\\AutoGPT\\Cron\\AIPKit_Automated_Task_Scheduler') && class_exists('\\WPAICG\\AutoGPT\\Cron\\AIPKit_Automated_Task_Event_Processor')) {
            AIPKit_Automated_Task_Scheduler::clear_all_task_events();
            wp_clear_scheduled_hook(AIPKit_Automated_Task_Event_Processor::MAIN_CRON_HOOK);
        }
        if (class_exists('\WPAICG\AutoGPT\Cron\AIPKit_Automation_Runner')) {
            AIPKit_Automation_Runner::clear_lock();
        }

        do_action('aipkit_deactivate_background_workers');
    }
}
