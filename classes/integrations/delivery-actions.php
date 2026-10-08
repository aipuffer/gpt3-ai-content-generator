<?php

namespace WPAICG\Dashboard\Ajax;

use WPAICG\Core\AIPKit_Event_Delivery_Manager;
use WPAICG\Core\AIPKit_Event_Payload_Builder;
use WPAICG\Core\AIPKit_Event_Queue_Store;
use WPAICG\Core\AIPKit_Event_Queue_Worker;
use WPAICG\Core\AIPKit_Event_Webhook_Status;
use WPAICG\Core\AIPKit_Event_Webhooks_Settings;
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
            // The endpoint's panel shows the new answer in place of the old one, in the same plain words.
            wp_send_json_success([
                'status' => 'failed',
                'message' => __('Webhook delivery retry failed again.', 'gpt3-ai-content-generator'),
                'issue' => $this->describe_issue($refreshed_job),
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
     * The failure in plain words, named after the endpoint the panel belongs to.
     *
     * @param array<string, mixed> $job
     * @return array<string, string>
     */
    private function describe_issue(array $job): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in check_module_access_permissions().
        $endpoint_id = sanitize_key((string) wp_unslash($_POST['endpoint_id'] ?? ''));
        $host = '';
        foreach ((array) ($job['targets'] ?? []) as $target) {
            if (!is_array($target)) {
                continue;
            }
            $target_host = (string) wp_parse_url((string) ($target['url'] ?? ''), PHP_URL_HOST);
            if ($host === '' || sanitize_key((string) ($target['id'] ?? '')) === $endpoint_id) {
                $host = $target_host;
            }
        }
        if (!class_exists(AIPKit_Event_Webhook_Status::class)) {
            require_once __DIR__ . '/webhook-status.php';
        }

        return AIPKit_Event_Webhook_Status::describe($job, $host);
    }

    /**
     * Sends one sample event to a saved endpoint, now, and says what it answered.
     */
    public function ajax_send_event_webhook_test(): void
    {
        $permission_check = $this->check_module_access_permissions('settings');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in check_module_access_permissions().
        $endpoint_id = sanitize_key((string) wp_unslash($_POST['endpoint_id'] ?? ''));
        $endpoint = null;
        foreach ((array) (AIPKit_Event_Webhooks_Settings::get_settings()['endpoints'] ?? []) as $candidate) {
            if (is_array($candidate) && $endpoint_id !== '' && sanitize_key((string) ($candidate['id'] ?? '')) === $endpoint_id) {
                $endpoint = $candidate;
                break;
            }
        }
        $url = is_array($endpoint) ? esc_url_raw((string) ($endpoint['url'] ?? '')) : '';
        if ($url === '') {
            $this->send_wp_error(new WP_Error(
                'aipkit_webhook_test_no_url',
                __('Add the endpoint’s URL first.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            ));
            return;
        }

        $events = array_values(array_filter(array_map('strval', (array) ($endpoint['events'] ?? []))));
        $event_name = sanitize_text_field($events[0] ?? 'chatbot.response_generated');
        $envelope = AIPKit_Event_Payload_Builder::build_envelope(
            $event_name,
            ['test' => true, 'message' => __('A test event from AI Puffer. You can ignore it.', 'gpt3-ai-content-generator')],
            ['origin' => 'test', 'meta' => ['test' => true]]
        );
        $results = AIPKit_Event_Delivery_Manager::deliver(
            $event_name,
            $envelope,
            [['id' => $endpoint_id, 'name' => sanitize_text_field((string) ($endpoint['name'] ?? '')), 'url' => $url]],
            ['retry_delays' => [0.0]]
        );
        $result = is_array($results[0] ?? null) ? $results[0] : [];
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        if (!class_exists(AIPKit_Event_Webhook_Status::class)) {
            require_once __DIR__ . '/webhook-status.php';
        }
        $event_label = (string) (AIPKit_Event_Webhook_Status::event_labels()[$event_name] ?? $event_name);

        if (($result['status'] ?? '') === 'delivered') {
            wp_send_json_success([
                'status' => 'delivered',
                /* translators: 1: the event, e.g. "The chatbot replies", 2: the endpoint's host, 3: HTTP status code. */
                'message' => sprintf(__('Sent “%1$s”. %2$s answered %3$d.', 'gpt3-ai-content-generator'), $event_label, $host, (int) ($result['http_status'] ?? 200)),
            ]);
            return;
        }

        wp_send_json_success([
            'status' => 'failed',
            'issue' => AIPKit_Event_Webhook_Status::describe([
                'error_message' => (string) ($result['error_message'] ?? ''),
                'event_name' => $event_name,
            ], $host),
        ]);
    }
}
