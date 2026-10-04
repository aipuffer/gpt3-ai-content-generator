<?php
/**
 * Partial: Automated Task List
 * Displays the table of existing automated tasks.
 * REDESIGNED: Simplified 5-column layout following philosophy principles
 * - Reduced choice overload by consolidating timing columns
 * - Task type is represented by an icon in the Name column
 * - Better chunking with cleaner visual hierarchy
 */

if (!defined('ABSPATH')) {
    exit;
}

$aipkit_cron_state = !empty($aipkit_autogpt_cron_summary['state']) ? (string) $aipkit_autogpt_cron_summary['state'] : 'enabled';
$aipkit_cron_card_status = !empty($aipkit_autogpt_cron_summary['status_label'])
    ? (string) $aipkit_autogpt_cron_summary['status_label']
    : __('Enabled', 'gpt3-ai-content-generator');
?>
<div id="aipkit_automated_task_list_wrapper">
    <header class="aipkit_autogpt_overview_intro">
        <div class="aipkit_autogpt_overview_intro_copy">
            <h1><?php esc_html_e('Automations', 'gpt3-ai-content-generator'); ?></h1>
            <p><?php esc_html_e('Manage scheduled tasks and monitor the run queue.', 'gpt3-ai-content-generator'); ?></p>
        </div>
        <div class="aipkit_autogpt_overview_header_actions">
            <button id="aipkit_add_new_task_btn" class="aipkit_btn aipkit_btn-primary">
                <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                <span><?php esc_html_e('New automation', 'gpt3-ai-content-generator'); ?></span>
            </button>
        </div>
    </header>

    <div class="aipkit_autogpt_overview_metrics" aria-label="<?php esc_attr_e('Automation summary', 'gpt3-ai-content-generator'); ?>">
        <div class="aipkit_autogpt_metric_card">
            <span class="aipkit_autogpt_metric_label"><?php esc_html_e('Total tasks', 'gpt3-ai-content-generator'); ?></span>
            <strong id="aipkit_autogpt_metric_total_tasks" class="aipkit_autogpt_metric_value" aria-live="polite" aria-busy="true">&mdash;</strong>
        </div>
        <div class="aipkit_autogpt_metric_card">
            <span class="aipkit_autogpt_metric_label"><?php esc_html_e('Pending', 'gpt3-ai-content-generator'); ?></span>
            <strong id="aipkit_autogpt_metric_pending" class="aipkit_autogpt_metric_value" aria-live="polite" aria-busy="true">&mdash;</strong>
        </div>
        <div class="aipkit_autogpt_metric_card">
            <span class="aipkit_autogpt_metric_label"><?php esc_html_e('Running', 'gpt3-ai-content-generator'); ?></span>
            <strong id="aipkit_autogpt_metric_running" class="aipkit_autogpt_metric_value" aria-live="polite" aria-busy="true">&mdash;</strong>
        </div>
        <div id="aipkit_autogpt_metric_failed_card" class="aipkit_autogpt_metric_card aipkit_autogpt_metric_card--failed" data-has-failures="false">
            <span class="aipkit_autogpt_metric_label"><?php esc_html_e('Failed', 'gpt3-ai-content-generator'); ?></span>
            <strong id="aipkit_autogpt_metric_failed" class="aipkit_autogpt_metric_value" aria-live="polite" aria-busy="true">&mdash;</strong>
        </div>
        <div class="aipkit_autogpt_metric_card aipkit_autogpt_metric_card--cron">
            <details
                class="aipkit_autogpt_cron_menu aipkit_autogpt_cron_metric"
                id="aipkit_autogpt_cron_info"
                data-cron-state="<?php echo esc_attr($aipkit_cron_state); ?>"
            >
                <summary
                    class="aipkit_autogpt_cron_metric_trigger"
                    id="aipkit_autogpt_cron_info_trigger"
                    aria-controls="aipkit_autogpt_cron_status"
                    aria-label="<?php esc_attr_e('Cron status', 'gpt3-ai-content-generator'); ?>"
                    aria-expanded="false"
                    title="<?php esc_attr_e('View cron status', 'gpt3-ai-content-generator'); ?>"
                >
                    <span class="aipkit_autogpt_metric_label"><?php esc_html_e('Cron', 'gpt3-ai-content-generator'); ?></span>
                    <strong class="aipkit_autogpt_metric_value aipkit_autogpt_cron_metric_status"><?php echo esc_html($aipkit_cron_card_status); ?></strong>
                </summary>
                <div id="aipkit_autogpt_cron_status" class="aipkit_autogpt_cron_status">
                    <?php include __DIR__ . '/scheduler-settings.php'; ?>
                </div>
            </details>
        </div>
    </div>

    <section class="aipkit_autogpt_overview_section" aria-labelledby="aipkit_autogpt_tasks_heading">
        <div class="aipkit_autogpt_section_header">
            <h2 id="aipkit_autogpt_tasks_heading"><?php esc_html_e('Tasks', 'gpt3-ai-content-generator'); ?></h2>
        </div>
        <div class="aipkit_autogpt_table_frame aipkit_data-table-frame">
            <div class="aipkit_data-table aipkit_autogpt_tasks_table">
                <table>
                <colgroup class="aipkit_autogpt_tasks_columns">
                    <col class="aipkit_autogpt_tasks_col aipkit_autogpt_tasks_col--name">
                    <col class="aipkit_autogpt_tasks_col aipkit_autogpt_tasks_col--frequency">
                    <col class="aipkit_autogpt_tasks_col aipkit_autogpt_tasks_col--status">
                    <col class="aipkit_autogpt_tasks_col aipkit_autogpt_tasks_col--schedule">
                    <col class="aipkit_autogpt_tasks_col aipkit_autogpt_tasks_col--actions">
                </colgroup>
                <thead>
                    <tr>
                        <th><?php esc_html_e('Name', 'gpt3-ai-content-generator'); ?></th>
                        <th><?php esc_html_e('Frequency', 'gpt3-ai-content-generator'); ?></th>
                        <th><?php esc_html_e('Status', 'gpt3-ai-content-generator'); ?></th>
                        <th><?php esc_html_e('Schedule', 'gpt3-ai-content-generator'); ?></th>
                        <th class="aipkit_actions_cell_header"><?php esc_html_e('Actions', 'gpt3-ai-content-generator'); ?></th>
                    </tr>
                </thead>
                <tbody id="aipkit_automated_tasks_tbody">
                    <tr><td colspan="5" class="aipkit_text-center"><?php esc_html_e('Loading...', 'gpt3-ai-content-generator'); ?></td></tr>
                </tbody>
                </table>
            </div>
            <div id="aipkit_automated_task_list_pagination" class="aipkit_pagination aipkit_data-table-footer"></div>
        </div>
    </section>
</div>
