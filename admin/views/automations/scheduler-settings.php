<?php
/**
 * Partial: AutoGPT Settings Popover
 * Current: Cron status summary (future options will be added here).
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<?php if (!empty($aipkit_autogpt_cron_summary)) : ?>
    <?php
    $aipkit_cron_state = !empty($aipkit_autogpt_cron_summary['state']) ? (string) $aipkit_autogpt_cron_summary['state'] : 'enabled';
    $aipkit_cron_health_copy = __('Automation scheduling is healthy.', 'gpt3-ai-content-generator');
    $aipkit_cron_health_icon = 'dashicons-yes-alt';
    $aipkit_cron_health_has_wp_link = false;
    if ($aipkit_cron_state === 'disabled') {
        $aipkit_cron_health_copy = __('Automated tasks won’t run until a cron method is enabled.', 'gpt3-ai-content-generator');
        $aipkit_cron_health_icon = 'dashicons-warning';
        $aipkit_cron_health_has_wp_link = true;
    } elseif ($aipkit_cron_state === 'server') {
        $aipkit_cron_health_copy = __('The authenticated server scheduler is running.', 'gpt3-ai-content-generator');
    } elseif ($aipkit_cron_state === 'server_pending') {
        $aipkit_cron_health_copy = __('Server cron is enabled but hasn’t checked in yet. Copy the command below into your hosting scheduler.', 'gpt3-ai-content-generator');
        $aipkit_cron_health_icon = 'dashicons-warning';
    } elseif ($aipkit_cron_state === 'server_delayed') {
        $aipkit_cron_health_copy = __('The server scheduler hasn’t checked in recently. Check the scheduler command or enable WP-Cron.', 'gpt3-ai-content-generator');
        $aipkit_cron_health_icon = 'dashicons-warning';
    } elseif ($aipkit_cron_state === 'overdue') {
        $aipkit_cron_health_copy = __('The next scheduled run is delayed. WP-Cron runs on page loads.', 'gpt3-ai-content-generator');
        $aipkit_cron_health_icon = 'dashicons-warning';
    }
    $aipkit_server_cron = isset($aipkit_autogpt_cron_summary['server_cron']) && is_array($aipkit_autogpt_cron_summary['server_cron'])
        ? $aipkit_autogpt_cron_summary['server_cron']
        : [];
    $aipkit_server_cron_enabled = !empty($aipkit_server_cron['enabled']);
    $aipkit_server_cron_last_run = absint($aipkit_server_cron['last_run_at'] ?? 0);
    $aipkit_server_cron_last_success = absint($aipkit_server_cron['last_success_at'] ?? 0);
    $aipkit_server_cron_state_label = __('Disabled', 'gpt3-ai-content-generator');
    $aipkit_server_cron_state = 'disabled';
    if (!empty($aipkit_server_cron['healthy'])) {
        $aipkit_server_cron_state_label = __('Active', 'gpt3-ai-content-generator');
        $aipkit_server_cron_state = 'active';
    } elseif (!empty($aipkit_server_cron['awaiting_first_run'])) {
        $aipkit_server_cron_state_label = __('Waiting for first run', 'gpt3-ai-content-generator');
        $aipkit_server_cron_state = 'pending';
    } elseif (!empty($aipkit_server_cron['delayed'])) {
        $aipkit_server_cron_state_label = __('Delayed', 'gpt3-ai-content-generator');
        $aipkit_server_cron_state = 'delayed';
    }
    $aipkit_server_cron_last_result = isset($aipkit_server_cron['last_result']) && is_array($aipkit_server_cron['last_result'])
        ? $aipkit_server_cron['last_result']
        : [];
    $aipkit_server_cron_result_label = '';
    if (!empty($aipkit_server_cron_last_result)) {
        $aipkit_server_cron_result_label = sprintf(
            /* translators: 1: tasks triggered, 2: queue items processed, 3: failures. */
            __('%1$d tasks triggered, %2$d items processed, %3$d failed', 'gpt3-ai-content-generator'),
            absint($aipkit_server_cron_last_result['triggered_tasks'] ?? 0),
            absint($aipkit_server_cron_last_result['processed_items'] ?? 0),
            absint($aipkit_server_cron_last_result['failed_tasks'] ?? 0) + absint($aipkit_server_cron_last_result['failed_items'] ?? 0)
        );
    }
    ?>
    <div class="aipkit_autogpt_settings_section">
        <h3 class="aipkit_autogpt_settings_title"><?php esc_html_e('Cron status', 'gpt3-ai-content-generator'); ?></h3>
        <div class="aipkit_autogpt_cron_health">
            <span class="aipkit_autogpt_cron_health_icon" aria-hidden="true">
                <span class="dashicons <?php echo esc_attr($aipkit_cron_health_icon); ?>"></span>
            </span>
            <span class="aipkit_autogpt_cron_health_copy">
                <strong><?php echo esc_html($aipkit_autogpt_cron_summary['status_label']); ?></strong>
                <span data-aipkit-cron-health-message><?php echo esc_html($aipkit_cron_health_copy); ?></span>
                <a
                    class="aipkit_autogpt_cron_health_link"
                    href="<?php echo esc_url('https://www.siteground.com/kb/enable-wordpress-cron/'); ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                    data-aipkit-cron-health-wp-link
                    <?php if (!$aipkit_cron_health_has_wp_link) : ?>hidden<?php endif; ?>
                ><?php esc_html_e('Learn how to enable WP-Cron', 'gpt3-ai-content-generator'); ?></a>
            </span>
        </div>
        <div class="aipkit_autogpt_settings_list">
            <div class="aipkit_autogpt_settings_item">
                <span class="aipkit_autogpt_settings_key"><?php esc_html_e('Next run', 'gpt3-ai-content-generator'); ?></span>
                <?php $aipkit_cron_next_ts = !empty($aipkit_autogpt_cron_summary['next_timestamp']) ? (int) $aipkit_autogpt_cron_summary['next_timestamp'] : 0; ?>
                <span
                    class="aipkit_autogpt_settings_value"
                    <?php if ($aipkit_cron_next_ts > 0) : ?>
                        data-aipkit-cron-timestamp="<?php echo esc_attr($aipkit_cron_next_ts); ?>"
                    <?php endif; ?>
                >
                    <?php echo esc_html($aipkit_autogpt_cron_summary['next_label']); ?>
                </span>
            </div>
            <div class="aipkit_autogpt_settings_item">
                <span class="aipkit_autogpt_settings_key"><?php esc_html_e('Scheduled tasks', 'gpt3-ai-content-generator'); ?></span>
                <span class="aipkit_autogpt_settings_value"><?php echo esc_html(number_format_i18n((int) $aipkit_autogpt_cron_summary['task_count'])); ?></span>
            </div>
        </div>
        <div
            class="aipkit_autogpt_server_cron"
            data-aipkit-server-cron-settings
            data-aipkit-server-cron-state="<?php echo esc_attr($aipkit_server_cron_state); ?>"
            data-aipkit-server-cron-last-success="<?php echo esc_attr($aipkit_server_cron_last_success); ?>"
            data-aipkit-server-cron-nonce="<?php echo esc_attr(wp_create_nonce('aipkit_automated_tasks_manage_nonce')); ?>"
            data-aipkit-server-cron-wp-disabled="<?php echo !empty($aipkit_autogpt_cron_summary['wp_cron_disabled']) ? '1' : '0'; ?>"
            data-aipkit-copied-label="<?php echo esc_attr__('Cron command copied', 'gpt3-ai-content-generator'); ?>"
            data-aipkit-checking-label="<?php echo esc_attr__('Waiting for the next server request...', 'gpt3-ai-content-generator'); ?>"
            data-aipkit-check-timeout-label="<?php echo esc_attr__('No new server request was detected. Check the scheduler command and try again.', 'gpt3-ai-content-generator'); ?>"
            data-aipkit-rotate-confirm="<?php echo esc_attr__('Rotate the server cron secret? The existing command will stop working immediately.', 'gpt3-ai-content-generator'); ?>"
            data-aipkit-disable-confirm="<?php echo esc_attr__('WP-Cron is also disabled. Automated tasks will stop running until another cron method is enabled.', 'gpt3-ai-content-generator'); ?>"
            data-aipkit-disable-confirm-title="<?php echo esc_attr__('Disable server cron?', 'gpt3-ai-content-generator'); ?>"
            data-aipkit-label-disabled="<?php echo esc_attr__('Disabled', 'gpt3-ai-content-generator'); ?>"
            data-aipkit-label-active="<?php echo esc_attr__('Active', 'gpt3-ai-content-generator'); ?>"
            data-aipkit-label-waiting="<?php echo esc_attr__('Waiting for first run', 'gpt3-ai-content-generator'); ?>"
            data-aipkit-label-delayed="<?php echo esc_attr__('Delayed', 'gpt3-ai-content-generator'); ?>"
            data-aipkit-label-never="<?php echo esc_attr__('Never', 'gpt3-ai-content-generator'); ?>"
            data-aipkit-label-no-result="<?php echo esc_attr__('No run recorded', 'gpt3-ai-content-generator'); ?>"
	            <?php /* translators: %s: Final characters of the server cron secret. */ ?>
	            data-aipkit-label-secret-template="<?php echo esc_attr__('Ending in %s', 'gpt3-ai-content-generator'); ?>"
	            <?php /* translators: 1: Number of tasks triggered, 2: Number of queue items processed, 3: Number of failed queue items. */ ?>
	            data-aipkit-label-result-template="<?php echo esc_attr__('%1$d tasks triggered, %2$d items processed, %3$d failed', 'gpt3-ai-content-generator'); ?>"
            data-aipkit-label-enabled-feedback="<?php echo esc_attr__('Server cron enabled. Copy the command and add it to your hosting scheduler.', 'gpt3-ai-content-generator'); ?>"
            data-aipkit-label-rotated-feedback="<?php echo esc_attr__('Secret rotated. Replace the previous scheduler command now.', 'gpt3-ai-content-generator'); ?>"
            data-aipkit-label-disabled-feedback="<?php echo esc_attr__('Server cron disabled.', 'gpt3-ai-content-generator'); ?>"
            data-aipkit-label-connected-feedback="<?php echo esc_attr__('Server cron connection detected.', 'gpt3-ai-content-generator'); ?>"
            data-aipkit-label-error="<?php echo esc_attr__('Server cron request failed.', 'gpt3-ai-content-generator'); ?>"
            data-aipkit-label-card-server="<?php echo esc_attr__('Server cron', 'gpt3-ai-content-generator'); ?>"
            data-aipkit-label-card-pending="<?php echo esc_attr__('Setup incomplete', 'gpt3-ai-content-generator'); ?>"
            data-aipkit-label-card-delayed="<?php echo esc_attr__('Server delayed', 'gpt3-ai-content-generator'); ?>"
            data-aipkit-label-health-server="<?php echo esc_attr__('The authenticated server scheduler is running.', 'gpt3-ai-content-generator'); ?>"
            data-aipkit-label-health-pending="<?php echo esc_attr__('Server cron is enabled but hasn’t checked in yet. Copy the command below into your hosting scheduler.', 'gpt3-ai-content-generator'); ?>"
            data-aipkit-label-health-delayed="<?php echo esc_attr__('The server scheduler hasn’t checked in recently. Check the scheduler command or enable WP-Cron.', 'gpt3-ai-content-generator'); ?>"
            data-aipkit-label-health-disabled="<?php echo esc_attr__('Automated tasks won’t run until a cron method is enabled.', 'gpt3-ai-content-generator'); ?>"
            data-aipkit-label-confirm-title="<?php echo esc_attr__('Server cron', 'gpt3-ai-content-generator'); ?>"
            data-aipkit-label-continue="<?php echo esc_attr__('Continue', 'gpt3-ai-content-generator'); ?>"
            data-aipkit-label-disable="<?php echo esc_attr__('Disable server cron', 'gpt3-ai-content-generator'); ?>"
            data-aipkit-label-cancel="<?php echo esc_attr__('Cancel', 'gpt3-ai-content-generator'); ?>"
        >
            <div class="aipkit_autogpt_server_cron_header">
                <span>
                    <strong><?php esc_html_e('Server cron', 'gpt3-ai-content-generator'); ?></strong>
                    <small><?php esc_html_e('Optional alternative', 'gpt3-ai-content-generator'); ?></small>
                </span>
                <span class="aipkit_autogpt_server_cron_badge" data-aipkit-server-cron-badge><?php echo esc_html($aipkit_server_cron_state_label); ?></span>
            </div>

            <p class="aipkit_autogpt_server_cron_intro" data-aipkit-server-cron-intro <?php echo $aipkit_server_cron_enabled ? 'hidden' : ''; ?>>
                <?php esc_html_e('Use a hosting scheduler such as cPanel or Plesk to call AI Puffer directly. No WP-CLI or wp-cron.php is required.', 'gpt3-ai-content-generator'); ?>
            </p>

            <div class="aipkit_autogpt_server_cron_details" data-aipkit-server-cron-details <?php echo $aipkit_server_cron_enabled ? '' : 'hidden'; ?>>
                <div class="aipkit_autogpt_settings_item">
                    <span class="aipkit_autogpt_settings_key"><?php esc_html_e('Secret', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_autogpt_settings_value" data-aipkit-server-cron-secret-hint>
                        <?php
                        if (!empty($aipkit_server_cron['secret_hint'])) {
                            printf(
                                /* translators: %s: Last six characters of the stored secret. */
                                esc_html__('Ending in %s', 'gpt3-ai-content-generator'),
                                esc_html((string) $aipkit_server_cron['secret_hint'])
                            );
                        }
                        ?>
                    </span>
                </div>
                <div class="aipkit_autogpt_settings_item">
                    <span class="aipkit_autogpt_settings_key"><?php esc_html_e('Last server run', 'gpt3-ai-content-generator'); ?></span>
                    <span
                        class="aipkit_autogpt_settings_value"
                        data-aipkit-server-cron-last-run-label
                        <?php if ($aipkit_server_cron_last_run > 0) : ?>data-aipkit-cron-timestamp="<?php echo esc_attr($aipkit_server_cron_last_run); ?>"<?php endif; ?>
                    >
                        <?php echo $aipkit_server_cron_last_run > 0 ? esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $aipkit_server_cron_last_run)) : esc_html__('Never', 'gpt3-ai-content-generator'); ?>
                    </span>
                </div>
                <div class="aipkit_autogpt_settings_item">
                    <span class="aipkit_autogpt_settings_key"><?php esc_html_e('Last result', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_autogpt_settings_value" data-aipkit-server-cron-result><?php echo esc_html($aipkit_server_cron_result_label ?: __('No run recorded', 'gpt3-ai-content-generator')); ?></span>
                </div>
            </div>

            <div class="aipkit_autogpt_server_cron_command" data-aipkit-server-cron-command hidden>
                <div class="aipkit_autogpt_server_cron_command_notice">
                    <strong><?php esc_html_e('Copy this command now', 'gpt3-ai-content-generator'); ?></strong>
                    <span><?php esc_html_e('For security, the generated secret will not be shown again. Configure the scheduler to run every minute.', 'gpt3-ai-content-generator'); ?></span>
                </div>
                <span class="aipkit_autogpt_server_cron_command_label"><?php esc_html_e('cPanel or Plesk command', 'gpt3-ai-content-generator'); ?></span>
                <div class="aipkit_autogpt_server_cron_command_field">
                    <textarea readonly rows="4" data-aipkit-server-cron-command-value aria-label="<?php esc_attr_e('Server cron command', 'gpt3-ai-content-generator'); ?>"></textarea>
                    <button type="button" class="aipkit_autogpt_server_cron_copy" data-aipkit-copy-server-cron data-aipkit-copy-target="[data-aipkit-server-cron-command-value]" aria-label="<?php esc_attr_e('Copy server cron command', 'gpt3-ai-content-generator'); ?>">
                        <span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
                    </button>
                </div>
                <span class="aipkit_autogpt_server_cron_command_label"><?php esc_html_e('Linux crontab line', 'gpt3-ai-content-generator'); ?></span>
                <div class="aipkit_autogpt_server_cron_command_field">
                    <textarea readonly rows="4" data-aipkit-server-cron-crontab-value aria-label="<?php esc_attr_e('Linux crontab line', 'gpt3-ai-content-generator'); ?>"></textarea>
                    <button type="button" class="aipkit_autogpt_server_cron_copy" data-aipkit-copy-server-cron data-aipkit-copy-target="[data-aipkit-server-cron-crontab-value]" aria-label="<?php esc_attr_e('Copy Linux crontab line', 'gpt3-ai-content-generator'); ?>">
                        <span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
                    </button>
                </div>
            </div>

            <div class="aipkit_autogpt_server_cron_actions">
                <button type="button" class="aipkit_autogpt_server_cron_action" data-aipkit-server-cron-enable <?php echo $aipkit_server_cron_enabled ? 'hidden' : ''; ?>>
                    <?php esc_html_e('Enable server cron', 'gpt3-ai-content-generator'); ?>
                </button>
                <button type="button" class="aipkit_autogpt_server_cron_action" data-aipkit-server-cron-check <?php echo $aipkit_server_cron_enabled ? '' : 'hidden'; ?>>
                    <?php esc_html_e('Check connection', 'gpt3-ai-content-generator'); ?>
                </button>
                <button type="button" class="aipkit_autogpt_server_cron_text_action" data-aipkit-server-cron-rotate <?php echo $aipkit_server_cron_enabled ? '' : 'hidden'; ?>>
                    <?php esc_html_e('Rotate secret', 'gpt3-ai-content-generator'); ?>
                </button>
                <button type="button" class="aipkit_autogpt_server_cron_text_action aipkit_autogpt_server_cron_disable" data-aipkit-server-cron-disable <?php echo $aipkit_server_cron_enabled ? '' : 'hidden'; ?>>
                    <?php esc_html_e('Disable', 'gpt3-ai-content-generator'); ?>
                </button>
            </div>
            <p class="aipkit_autogpt_server_cron_feedback" data-aipkit-server-cron-feedback aria-live="polite"></p>
        </div>
    </div>
<?php endif; ?>
