<?php

namespace WPAICG\Dashboard\Ajax;

use WPAICG\Core\AIPKit_Event_Queue_Store;
use WPAICG\Core\AIPKit_Event_Queue_Worker;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

class AIPKit_Event_Webhook_Delivery_Issues_Ajax_Handler extends BaseDashboardAjaxHandler
{
    public function ajax_retry_event_webhook_delivery_issue(): void
    {
        $permission_check = $this->check_module_access_permissions('settings');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in check_module_access_permissions().
        $job_uuid = sanitize_text_field((string) wp_unslash($_POST['job_uuid'] ?? ''));
        if ($job_uuid === '') {
            $this->send_wp_error(new WP_Error(
                'aipkit_missing_webhook_issue_id',
                __('Webhook delivery issue ID is required.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            ));
            return;
        }

        $job = AIPKit_Event_Queue_Store::get_job_by_uuid($job_uuid);
        if (!is_array($job) || !$this->is_retryable_webhook_issue($job)) {
            $this->send_wp_error(new WP_Error(
                'aipkit_webhook_issue_not_found',
                __('The selected webhook delivery issue is not available for retry.', 'gpt3-ai-content-generator'),
                ['status' => 404]
            ));
            return;
        }

        $targets = isset($job['targets']) && is_array($job['targets']) ? $job['targets'] : [];
        $context = isset($job['context']) && is_array($job['context']) ? $job['context'] : [];
        $event_name = sanitize_text_field((string) ($job['event_name'] ?? ''));
        $envelope = isset($job['envelope']) && is_array($job['envelope']) ? $job['envelope'] : [];

        $updated = AIPKit_Event_Queue_Store::update_job_state($job_uuid, [
            'status' => 'pending',
            'attempt_count' => 0,
            'locked_at' => null,
            'processed_at' => null,
            'available_at' => gmdate('Y-m-d H:i:s'),
            'last_error_message' => '',
            'target_count' => count($targets),
            'context_json' => $context,
        ]);

        if (!$updated) {
            $this->send_wp_error(new WP_Error(
                'aipkit_webhook_issue_retry_failed',
                __('Failed to prepare the webhook delivery issue for retry.', 'gpt3-ai-content-generator'),
                ['status' => 500]
            ));
            return;
        }

        $claimed_job = AIPKit_Event_Queue_Store::claim_job_by_uuid($job_uuid);
        if (!is_array($claimed_job)) {
            $this->send_wp_error(new WP_Error(
                'aipkit_webhook_issue_retry_claim_failed',
                __('Failed to claim the webhook delivery issue for retry.', 'gpt3-ai-content-generator'),
                ['status' => 500]
            ));
            return;
        }

        AIPKit_Event_Queue_Worker::process_job($claimed_job);

        $refreshed_job = AIPKit_Event_Queue_Store::get_job_by_uuid($job_uuid);
        if (!is_array($refreshed_job)) {
            wp_send_json_success([
                'status' => 'resolved',
                'message' => __('Webhook delivery retry succeeded.', 'gpt3-ai-content-generator'),
            ]);
            return;
        }

        if ($this->is_retryable_webhook_issue($refreshed_job)) {
            wp_send_json_success([
                'status' => 'failed',
                'message' => __('Webhook delivery retry failed again.', 'gpt3-ai-content-generator'),
                'replacement_html' => $this->render_issue_html($refreshed_job),
            ]);
            return;
        }

        wp_send_json_success([
            'status' => 'queued',
            'message' => __('Webhook delivery retry was queued.', 'gpt3-ai-content-generator'),
        ]);
    }

    public function ajax_clear_event_webhook_delivery_issue(): void
    {
        $permission_check = $this->check_module_access_permissions('settings');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in check_module_access_permissions().
        $job_uuid = sanitize_text_field((string) wp_unslash($_POST['job_uuid'] ?? ''));
        if ($job_uuid === '') {
            $this->send_wp_error(new WP_Error(
                'aipkit_missing_webhook_issue_id',
                __('Webhook delivery issue ID is required.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            ));
            return;
        }

        $job = AIPKit_Event_Queue_Store::get_job_by_uuid($job_uuid);
        if (!is_array($job) || !$this->is_retryable_webhook_issue($job)) {
            $this->send_wp_error(new WP_Error(
                'aipkit_webhook_issue_not_found',
                __('The selected webhook delivery issue is not available to clear.', 'gpt3-ai-content-generator'),
                ['status' => 404]
            ));
            return;
        }

        if (!AIPKit_Event_Queue_Store::clear_failed_webhook_job($job_uuid)) {
            $this->send_wp_error(new WP_Error(
                'aipkit_webhook_issue_clear_failed',
                __('Failed to clear the selected webhook delivery issue.', 'gpt3-ai-content-generator'),
                ['status' => 500]
            ));
            return;
        }

        wp_send_json_success([
            'status' => 'cleared',
            'message' => __('Webhook delivery issue cleared.', 'gpt3-ai-content-generator'),
        ]);
    }

    /**
     * @param array<string, mixed> $job
     */
    private function is_retryable_webhook_issue(array $job): bool
    {
        return sanitize_key((string) ($job['status'] ?? '')) === 'failed'
            && max(0, (int) ($job['target_count'] ?? 0)) > 0;
    }

    /**
     * @param array<string, mixed> $issue
     */
    private function render_issue_html(array $issue): string
    {
        $job_uuid = sanitize_text_field((string) ($issue['job_uuid'] ?? ''));
        $event_name = sanitize_text_field((string) ($issue['event_name'] ?? ''));
        $target_summary = sanitize_text_field((string) ($issue['target_summary'] ?? __('Webhook endpoint', 'gpt3-ai-content-generator')));
        $error_message = sanitize_text_field((string) (($issue['error_message'] ?? '') ?: __('Webhook delivery failed.', 'gpt3-ai-content-generator')));
        $displayed_at = sanitize_text_field((string) ($issue['displayed_at'] ?? ''));

        ob_start();
        ?>
        <article class="aipkit_settings_app_delivery_issue" data-aipkit-event-webhook-delivery-issue data-job-uuid="<?php echo esc_attr($job_uuid); ?>">
            <div class="aipkit_settings_app_delivery_issue_header">
                <div class="aipkit_settings_app_delivery_issue_heading">
                    <strong><?php echo esc_html($target_summary); ?></strong>
                    <span class="aipkit_settings_app_delivery_issue_meta">
                        <?php echo esc_html($event_name); ?>
                    </span>
                </div>
                <span class="aipkit_settings_app_delivery_issue_status aipkit_settings_app_delivery_issue_status--failed">
                    <?php esc_html_e('Failed', 'gpt3-ai-content-generator'); ?>
                </span>
            </div>
            <p class="aipkit_settings_app_delivery_issue_message"><?php echo esc_html($error_message); ?></p>
            <div class="aipkit_settings_app_delivery_issue_footer">
                <span class="aipkit_settings_app_delivery_issue_time"><?php echo esc_html($displayed_at); ?></span>
                <div class="aipkit_settings_app_delivery_issue_actions">
                    <button type="button" class="button button-secondary aipkit_btn aipkit_btn-danger" data-aipkit-clear-event-webhook-delivery-issue data-job-uuid="<?php echo esc_attr($job_uuid); ?>">
                        <span class="aipkit_btn-text"><?php esc_html_e('Clear', 'gpt3-ai-content-generator'); ?></span>
                        <span class="aipkit_spinner"></span>
                    </button>
                    <button type="button" class="button button-secondary aipkit_btn" data-aipkit-retry-event-webhook-delivery-issue data-job-uuid="<?php echo esc_attr($job_uuid); ?>">
                        <span class="aipkit_btn-text"><?php esc_html_e('Retry', 'gpt3-ai-content-generator'); ?></span>
                        <span class="aipkit_spinner"></span>
                    </button>
                </div>
            </div>
        </article>
        <?php

        return (string) ob_get_clean();
    }
}
