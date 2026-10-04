<?php

/** Automations module data, runner notices and workspace entry. */

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.

use WPAICG\AIPKit_Providers; // For AI models
use WPAICG\AIPKIT_AI_Settings; // For AI parameters
use WPAICG\aipkit_dashboard;
use WPAICG\AutoGPT\Cron\AIPKit_Automated_Task_Scheduler;
use WPAICG\AutoGPT\Cron\AIPKit_Automation_Server_Cron;

// --- Variable Definitions for Partials ---
$post_types_args = ['public' => true];
$all_post_types = get_post_types($post_types_args, 'objects');
$all_selectable_post_types = array_filter($all_post_types, function ($pt_obj) {
    return $pt_obj->name !== 'attachment';
});

$vector_store_localization = [
    'openai_vector_stores' => [],
    'pinecone_indexes' => [],
    'qdrant_collections' => [],
    'chroma_collections' => [],
    'google_file_search_stores' => [],
];
if (class_exists(AIPKit_Providers::class)) {
    $vector_store_localization = AIPKit_Providers::get_vector_store_localization_payload('autogpt_ui');
}
$openai_vector_stores = isset($vector_store_localization['openai_vector_stores']) && is_array($vector_store_localization['openai_vector_stores'])
    ? $vector_store_localization['openai_vector_stores']
    : [];
$pinecone_indexes = isset($vector_store_localization['pinecone_indexes']) && is_array($vector_store_localization['pinecone_indexes'])
    ? $vector_store_localization['pinecone_indexes']
    : [];
$qdrant_collections = isset($vector_store_localization['qdrant_collections']) && is_array($vector_store_localization['qdrant_collections'])
    ? $vector_store_localization['qdrant_collections']
    : [];
$chroma_collections = isset($vector_store_localization['chroma_collections']) && is_array($vector_store_localization['chroma_collections'])
    ? $vector_store_localization['chroma_collections']
    : [];
$google_file_search_stores = isset($vector_store_localization['google_file_search_stores']) && is_array($vector_store_localization['google_file_search_stores'])
    ? $vector_store_localization['google_file_search_stores']
    : [];


$task_categories = [
    '' => __('-- Select Category --', 'gpt3-ai-content-generator'),
    'content_creation' => __('Create New Content', 'gpt3-ai-content-generator'),
    'content_enhancement' => __('Rewrite Content', 'gpt3-ai-content-generator'),
    'knowledge_base' => __('Content Indexing', 'gpt3-ai-content-generator'),
    'community_engagement' => __('Engagement', 'gpt3-ai-content-generator'),
];
$frequencies = [
    'one-time' => __('One-time', 'gpt3-ai-content-generator'),
    'aipkit_five_minutes' => __('Every 5 Minutes', 'gpt3-ai-content-generator'),
    'aipkit_fifteen_minutes' => __('Every 15 Minutes', 'gpt3-ai-content-generator'),
    'aipkit_thirty_minutes' => __('Every 30 Minutes', 'gpt3-ai-content-generator'),
    'hourly' => __('Hourly', 'gpt3-ai-content-generator'),
    'twicedaily' => __('Twice Daily', 'gpt3-ai-content-generator'),
    'daily' => __('Daily', 'gpt3-ai-content-generator'),
    'weekly' => __('Weekly', 'gpt3-ai-content-generator'),
];

$is_pro = aipkit_dashboard::is_pro_plan(); // Define is_pro for partials

// For Content Writing Task Type
$cw_providers_for_select = \WPAICG\AIPKit_Providers::get_text_generation_providers(true, true, true);
$cw_ai_parameters = AIPKIT_AI_Settings::get_ai_parameters();
$cw_default_temperature = $cw_ai_parameters['temperature'] ?? 1.0;
$cw_available_post_types = $all_post_types;
unset($cw_available_post_types['attachment']);
$cw_current_user_id = get_current_user_id();
// Minimal safeguard: avoid loading thousands of users into selects.
// Load up to a small, reasonable cap and ensure current user is always present.
$__aipkit_user_list_cap = 200;
$cw_users_for_author = get_users([
    'orderby' => 'display_name',
    'order'   => 'ASC',
    'fields'  => ['ID', 'display_name', 'user_login'],
    'number'  => $__aipkit_user_list_cap,
]);
if ($cw_current_user_id) {
    $has_current_user = false;
    foreach ($cw_users_for_author as $u) {
        if ((int) $u->ID === (int) $cw_current_user_id) { $has_current_user = true; break; }
    }
    if (!$has_current_user) {
        $u = get_user_by('id', $cw_current_user_id);
        if ($u && isset($u->ID)) {
            $cw_users_for_author[] = (object) [
                'ID' => (int) $u->ID,
                'display_name' => (string) $u->display_name,
                'user_login' => (string) $u->user_login,
            ];
        }
    }
}
$cw_post_statuses = [
    'draft' => __('Draft', 'gpt3-ai-content-generator'),
    'publish' => __('Publish', 'gpt3-ai-content-generator'),
    'pending' => __('Pending Review', 'gpt3-ai-content-generator'),
    'private' => __('Private', 'gpt3-ai-content-generator'),
];
$cw_wp_categories = get_categories(['hide_empty' => false]);

// --- AutoGPT Cron Summary (Global Card) ---
global $wpdb;

if (class_exists(AIPKit_Automated_Task_Scheduler::class)) {
    AIPKit_Automated_Task_Scheduler::prune_orphaned_task_events();
}

$aipkit_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
$aipkit_cron_alternate = defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON;
$aipkit_cron_next_timestamp = null;
$aipkit_cron_task_count = 0;
$aipkit_tasks_table_name = $wpdb->prefix . 'aipkit_automated_tasks';
$aipkit_server_cron_status = class_exists(AIPKit_Automation_Server_Cron::class)
    ? AIPKit_Automation_Server_Cron::get_status()
    : [
        'enabled' => false,
        'healthy' => false,
        'awaiting_first_run' => false,
        'delayed' => false,
        'secret_hint' => '',
        'last_run_at' => 0,
        'last_result' => [],
        'endpoint' => '',
    ];

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct query to a custom table for scheduler status.
$aipkit_tasks_table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $aipkit_tasks_table_name));
if ($aipkit_tasks_table_exists === $aipkit_tasks_table_name) {
    // next_run_time is authoritative; WP-Cron and server cron only wake the shared runner.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed summary over the plugin-owned task table.
    $aipkit_cron_task_summary = $wpdb->get_row(
        $wpdb->prepare(
            'SELECT COUNT(*) AS task_count, MIN(next_run_time) AS next_run_time FROM ' . esc_sql($aipkit_tasks_table_name) . ' WHERE status = %s',
            'active'
        ),
        ARRAY_A
    );
    $aipkit_cron_task_count = absint($aipkit_cron_task_summary['task_count'] ?? 0);
    if (!empty($aipkit_cron_task_summary['next_run_time'])) {
        $aipkit_cron_next_timestamp = strtotime((string) $aipkit_cron_task_summary['next_run_time'] . ' UTC') ?: null;
    }
}

$aipkit_cron_state = 'enabled';
$aipkit_cron_status_label = __('Enabled', 'gpt3-ai-content-generator');
if ($aipkit_cron_disabled) {
    if (!empty($aipkit_server_cron_status['healthy'])) {
        $aipkit_cron_state = 'server';
        $aipkit_cron_status_label = __('Server cron', 'gpt3-ai-content-generator');
    } elseif (!empty($aipkit_server_cron_status['awaiting_first_run'])) {
        $aipkit_cron_state = 'server_pending';
        $aipkit_cron_status_label = __('Setup incomplete', 'gpt3-ai-content-generator');
    } elseif (!empty($aipkit_server_cron_status['delayed'])) {
        $aipkit_cron_state = 'server_delayed';
        $aipkit_cron_status_label = __('Server delayed', 'gpt3-ai-content-generator');
    } else {
        $aipkit_cron_state = 'disabled';
        $aipkit_cron_status_label = __('Disabled', 'gpt3-ai-content-generator');
    }
} elseif ($aipkit_cron_alternate) {
    $aipkit_cron_status_label = __('Enabled (Alternate)', 'gpt3-ai-content-generator');
}

$aipkit_cron_overdue = false;
if ($aipkit_cron_next_timestamp) {
    $aipkit_cron_overdue = $aipkit_cron_next_timestamp < (time() - (15 * MINUTE_IN_SECONDS));
    if ($aipkit_cron_overdue && !$aipkit_cron_disabled) {
        $aipkit_cron_state = 'overdue';
        $aipkit_cron_status_label = __('Delayed', 'gpt3-ai-content-generator');
    }
}

$aipkit_cron_next_label = $aipkit_cron_next_timestamp
    ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $aipkit_cron_next_timestamp)
    : __('Not scheduled', 'gpt3-ai-content-generator');

$aipkit_autogpt_cron_summary = [
    'state' => $aipkit_cron_state,
    'status_label' => $aipkit_cron_status_label,
    'next_label' => $aipkit_cron_next_label,
    'next_timestamp' => $aipkit_cron_next_timestamp ? (int) $aipkit_cron_next_timestamp : 0,
    'task_count' => $aipkit_cron_task_count,
    'wp_cron_disabled' => $aipkit_cron_disabled,
    'server_cron' => $aipkit_server_cron_status,
];
// --- End Variable Definitions ---

?>
<?php
$aipkit_notice_id = 'aipkit_provider_notice_autogpt';
$aipkit_notice_context = __('run this automation', 'gpt3-ai-content-generator');
include WPAICG_PLUGIN_DIR . 'admin/views/shared/provider-key-notice.php';
include WPAICG_PLUGIN_DIR . 'admin/views/shared/seo-plugin-conflict-notice.php';
?>
<?php if ($aipkit_cron_disabled) : ?>
<?php
$aipkit_runner_notice_state = $aipkit_cron_state;
$aipkit_runner_notice_message = __('WP-Cron is disabled. Automated tasks won’t run.', 'gpt3-ai-content-generator');
$aipkit_runner_notice_action = __('Use server cron instead', 'gpt3-ai-content-generator');
$aipkit_runner_notice_show_wp_link = true;
if ($aipkit_runner_notice_state === 'server_pending') {
    $aipkit_runner_notice_message = __('Server cron hasn’t checked in yet. Automated tasks won’t run until setup is complete.', 'gpt3-ai-content-generator');
    $aipkit_runner_notice_action = __('Finish server cron setup', 'gpt3-ai-content-generator');
    $aipkit_runner_notice_show_wp_link = false;
} elseif ($aipkit_runner_notice_state === 'server_delayed') {
    $aipkit_runner_notice_message = __('Server cron hasn’t checked in recently. Automated tasks may be delayed.', 'gpt3-ai-content-generator');
    $aipkit_runner_notice_action = __('Check server cron setup', 'gpt3-ai-content-generator');
    $aipkit_runner_notice_show_wp_link = false;
}
?>
<div
    class="aipkit_notification_bar aipkit_notification_bar--warning aipkit_autogpt_cron_notice"
    data-aipkit-dismissible-notice="autogpt-cron-runner-unavailable-v2"
    data-aipkit-cron-runner-notice
    data-aipkit-message-disabled="<?php echo esc_attr__('WP-Cron is disabled. Automated tasks won’t run.', 'gpt3-ai-content-generator'); ?>"
    data-aipkit-message-pending="<?php echo esc_attr__('Server cron hasn’t checked in yet. Automated tasks won’t run until setup is complete.', 'gpt3-ai-content-generator'); ?>"
    data-aipkit-message-delayed="<?php echo esc_attr__('Server cron hasn’t checked in recently. Automated tasks may be delayed.', 'gpt3-ai-content-generator'); ?>"
    data-aipkit-action-disabled="<?php echo esc_attr__('Use server cron instead', 'gpt3-ai-content-generator'); ?>"
    data-aipkit-action-pending="<?php echo esc_attr__('Finish server cron setup', 'gpt3-ai-content-generator'); ?>"
    data-aipkit-action-delayed="<?php echo esc_attr__('Check server cron setup', 'gpt3-ai-content-generator'); ?>"
    <?php if ($aipkit_runner_notice_state === 'server') : ?>hidden<?php endif; ?>
>
    <div class="aipkit_notification_bar__icon" aria-hidden="true">
        <span class="dashicons dashicons-clock"></span>
    </div>
    <div class="aipkit_notification_bar__content">
        <p>
            <span data-aipkit-cron-notice-message><?php echo esc_html($aipkit_runner_notice_message); ?></span>
            <a
                href="<?php echo esc_url('https://www.siteground.com/kb/enable-wordpress-cron/'); ?>"
                target="_blank"
                rel="noopener noreferrer"
                data-aipkit-cron-notice-wp-link
                <?php if (!$aipkit_runner_notice_show_wp_link) : ?>hidden<?php endif; ?>
            ><?php esc_html_e('Learn how to enable WP-Cron', 'gpt3-ai-content-generator'); ?></a>
        </p>
    </div>
    <div class="aipkit_notification_bar__actions">
        <button type="button" class="aipkit_autogpt_cron_notice_action" data-aipkit-open-server-cron data-aipkit-cron-notice-action>
            <?php echo esc_html($aipkit_runner_notice_action); ?>
        </button>
    </div>
    <button type="button" class="aipkit_notification_bar__close" data-aipkit-dismiss-notice aria-label="<?php esc_attr_e('Dismiss notice', 'gpt3-ai-content-generator'); ?>">
        &times;
    </button>
</div>
<?php elseif ($aipkit_cron_state === 'overdue') : ?>
<div
    class="aipkit_notification_bar aipkit_notification_bar--warning"
    data-aipkit-dismissible-notice="autogpt-wp-cron-overdue-v1"
>
    <div class="aipkit_notification_bar__icon" aria-hidden="true">
        <span class="dashicons dashicons-clock"></span>
    </div>
    <div class="aipkit_notification_bar__content">
        <p><?php esc_html_e('WP-Cron appears delayed. Automated tasks run on page loads, so low traffic can delay runs.', 'gpt3-ai-content-generator'); ?></p>
    </div>
    <button type="button" class="aipkit_notification_bar__close" data-aipkit-dismiss-notice aria-label="<?php esc_attr_e('Dismiss notice', 'gpt3-ai-content-generator'); ?>">
        &times;
    </button>
</div>
<?php endif; ?>
<div class="aipkit_module_autogpt aipkit_admin_ui" id="aipkit_autogpt_container" data-workspace-state="checking" aria-busy="true">
    <div
        id="aipkit_automated_task_form_status"
        class="aipkit_training_status aipkit_global_status_area"
        aria-live="polite"
    ></div>
    <?php include __DIR__ . '/workspace.php'; ?>
</div>
