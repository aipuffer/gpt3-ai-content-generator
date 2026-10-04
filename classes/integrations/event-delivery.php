<?php

namespace WPAICG\Core;

use WP_Error;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Handles outbound webhook delivery and retry policy.
 */
class AIPKit_Event_Delivery_Manager
{
    /**
     * Delivers an event envelope to all matched endpoints.
     *
     * @param string $event_name
     * @param array<string, mixed> $envelope
     * @param array<int, array<string, mixed>> $targets
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    public static function deliver(string $event_name, array $envelope, array $targets, array $options = []): array
    {
        $settings = AIPKit_Event_Webhooks_Settings::get_settings();
        $results = [];
        $normalized_options = self::normalize_delivery_options($options);

        foreach ($targets as $target) {
            if (!is_array($target)) {
                continue;
            }

            $results[] = self::deliver_to_target($event_name, $envelope, $target, $settings, $normalized_options);
        }

        return $results;
    }

    /**
     * Returns the configured retry delays in seconds.
     *
     * @return array<int, float>
     */
    public static function get_retry_delays(): array
    {
        $retry_delays = apply_filters('aipkit_event_webhooks_retry_delays', [0.0, 0.25, 1.0]);
        if (!is_array($retry_delays) || empty($retry_delays)) {
            return [0.0];
        }

        return array_values(array_map(static function ($delay): float {
            return max(0.0, (float) $delay);
        }, $retry_delays));
    }

    /**
     * Performs delivery to one endpoint with retry handling.
     *
     * @param string $event_name
     * @param array<string, mixed> $envelope
     * @param array<string, mixed> $target
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function deliver_to_target(string $event_name, array $envelope, array $target, array $settings, array $options = []): array
    {
        $delivery_uuid = wp_generate_uuid4();
        $body = wp_json_encode($envelope);

        if (!is_string($body) || $body === '') {
            return [
                'delivery_id' => $delivery_uuid,
                'endpoint_id' => sanitize_key((string) ($target['id'] ?? '')),
                'status' => 'failed',
                'attempt_count' => 0,
                'http_status' => null,
                'error_message' => __('Failed to encode webhook payload.', 'gpt3-ai-content-generator'),
                'should_retry' => false,
                'retry_delay' => 0.0,
            ];
        }

        $secret = sanitize_text_field((string) ($settings['signing_secret'] ?? ''));
        $retry_delays = isset($options['retry_delays']) && is_array($options['retry_delays'])
            ? $options['retry_delays']
            : self::get_retry_delays();
        $attempt_offset = max(0, (int) ($options['attempt_offset'] ?? 0));
        $max_attempts_per_call = max(1, (int) ($options['max_attempts_per_call'] ?? count($retry_delays)));
        $sleep_between_attempts = !empty($options['sleep_between_attempts']);
        $attempt_count = 0;
        $last_http_status = null;
        $last_error_message = '';
        $should_retry_after_failure = false;
        $next_retry_delay = 0.0;

        foreach ($retry_delays as $attempt_index => $retry_delay) {
            if (($attempt_index + 1) <= $attempt_offset) {
                continue;
            }

            if ($attempt_count >= $max_attempts_per_call) {
                break;
            }

            $attempt_count = $attempt_index + 1;
            $timestamp = (string) time();
            $request_headers = self::build_request_headers($event_name, $envelope, $delivery_uuid, $timestamp, $body, $secret);
            $request_args = self::build_request_args($request_headers, $body);
            $response = wp_remote_post(esc_url_raw((string) ($target['url'] ?? '')), $request_args);

            if (!is_wp_error($response)) {
                $last_http_status = (int) wp_remote_retrieve_response_code($response);
                if ($last_http_status >= 200 && $last_http_status < 300) {
                    $result = [
                        'delivery_id' => $delivery_uuid,
                        'endpoint_id' => sanitize_key((string) ($target['id'] ?? '')),
                        'status' => 'delivered',
                        'attempt_count' => $attempt_count,
                        'http_status' => $last_http_status,
                        'error_message' => '',
                        'should_retry' => false,
                        'retry_delay' => 0.0,
                    ];

                    do_action('aipkit_event_webhooks_delivery_completed', $result, $target, $envelope);
                    return $result;
                }

                $last_error_message = sprintf(
                    /* translators: %d: HTTP response code */
                    __('Webhook returned HTTP %d.', 'gpt3-ai-content-generator'),
                    $last_http_status
                );
            } else {
                $last_error_message = $response->get_error_message();
                $last_http_status = null;
            }

            $should_retry = self::should_retry($response, $last_http_status, $attempt_count, count($retry_delays));
            $should_retry_after_failure = $should_retry;
            $next_retry_delay = $should_retry ? max(0.0, (float) $retry_delay) : 0.0;
            if (!$should_retry) {
                break;
            }

            if ($sleep_between_attempts && $retry_delay > 0) {
                usleep((int) round($retry_delay * 1000000));
            }
        }

        $result = [
            'delivery_id' => $delivery_uuid,
            'endpoint_id' => sanitize_key((string) ($target['id'] ?? '')),
            'status' => 'failed',
            'attempt_count' => $attempt_count,
            'http_status' => $last_http_status,
            'error_message' => $last_error_message,
            'should_retry' => $should_retry_after_failure,
            'retry_delay' => $next_retry_delay,
        ];

        do_action('aipkit_event_webhooks_delivery_failed', $result, $target, $envelope);
        return $result;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function normalize_delivery_options(array $options): array
    {
        $retry_delays = isset($options['retry_delays']) && is_array($options['retry_delays'])
            ? $options['retry_delays']
            : self::get_retry_delays();
        if (empty($retry_delays)) {
            $retry_delays = [0.0];
        }

        return [
            'retry_delays' => array_values(array_map(static function ($delay): float {
                return max(0.0, (float) $delay);
            }, $retry_delays)),
            'attempt_offset' => max(0, (int) ($options['attempt_offset'] ?? 0)),
            'max_attempts_per_call' => max(1, (int) ($options['max_attempts_per_call'] ?? count($retry_delays))),
            'sleep_between_attempts' => array_key_exists('sleep_between_attempts', $options)
                ? !empty($options['sleep_between_attempts'])
                : true,
        ];
    }

    /**
     * Builds request headers for one delivery attempt.
     *
     * @param string $event_name
     * @param array<string, mixed> $envelope
     * @param string $delivery_uuid
     * @param string $timestamp
     * @param string $body
     * @param string $secret
     * @return array<string, string>
     */
    private static function build_request_headers(string $event_name, array $envelope, string $delivery_uuid, string $timestamp, string $body, string $secret): array
    {
        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'Accept' => 'application/json',
            'User-Agent' => sprintf(
                'AIPKit/%s (%s)',
                defined('WPAICG_VERSION') ? WPAICG_VERSION : '1.0.0',
                site_url()
            ),
            'X-AIPKit-Event' => $event_name,
            'X-AIPKit-Event-Id' => sanitize_text_field((string) ($envelope['id'] ?? '')),
            'X-AIPKit-Delivery-Id' => $delivery_uuid,
            'X-AIPKit-Idempotency-Key' => sanitize_text_field((string) ($envelope['idempotency_key'] ?? '')),
            'X-AIPKit-Schema-Version' => sanitize_text_field((string) ($envelope['schema_version'] ?? '')),
            'X-AIPKit-Timestamp' => $timestamp,
        ];

        $signature = AIPKit_Event_Signature::build($timestamp, $body, $secret);
        if ($signature !== '') {
            $headers['X-AIPKit-Signature'] = $signature;
            $headers['X-AIPKit-Signature-Alg'] = 'sha256';
        }

        /**
         * Filters outbound webhook headers for Universal Event Webhooks.
         *
         * @param array<string, string> $headers
         * @param array<string, mixed>  $envelope
         * @param string                $delivery_uuid
         */
        $headers = apply_filters('aipkit_event_webhooks_request_headers', $headers, $envelope, $delivery_uuid);

        return is_array($headers) ? $headers : [];
    }

    /**
     * Builds wp_remote_post arguments.
     *
     * @param array<string, string> $headers
     * @param string $body
     * @return array<string, mixed>
     */
    private static function build_request_args(array $headers, string $body): array
    {
        $args = [
            'timeout' => 5,
            'redirection' => 2,
            'httpversion' => '1.1',
            'blocking' => true,
            'headers' => $headers,
            'body' => $body,
            'data_format' => 'body',
        ];

        $filtered_args = apply_filters('aipkit_event_webhooks_request_args', $args, $headers, $body);
        return is_array($filtered_args) ? $filtered_args : $args;
    }

    /**
     * Determines whether a failed attempt should be retried.
     *
     * @param mixed $response
     * @param int|null $http_status
     * @param int $attempt_count
     * @param int $max_attempts
     * @return bool
     */
    private static function should_retry($response, ?int $http_status, int $attempt_count, int $max_attempts): bool
    {
        if ($attempt_count >= $max_attempts) {
            return false;
        }

        $is_retryable = is_wp_error($response)
            || $http_status === 408
            || $http_status === 409
            || $http_status === 425
            || $http_status === 429
            || ($http_status !== null && $http_status >= 500);

        $filtered = apply_filters('aipkit_event_webhooks_should_retry', $is_retryable, $response, $http_status, $attempt_count, $max_attempts);
        return (bool) $filtered;
    }
}

/**
 * Delivery policy defaults for the event queue runtime.
 *
 * This keeps latency-sensitive chatbot and AI Form events off the originating
 * request when there is actual downstream delivery work to do.
 */
class AIPKit_Event_Delivery_Policy
{
    /**
     * Registers default delivery policy filters.
     */
    public static function register_hooks(): void
    {
        add_filter('aipkit_event_delivery_queue_async_enabled', [__CLASS__, 'enable_async_for_latency_sensitive_events'], 10, 5);
    }

    /**
     * @param array<string, mixed> $envelope
     * @param array<string, mixed> $event_context
     * @param array<int, array<string, mixed>> $targets
     */
    public static function enable_async_for_latency_sensitive_events(bool $enabled, string $event_name, array $envelope = [], array $event_context = [], array $targets = []): bool
    {
        if ($enabled) {
            return true;
        }

        if (!self::is_latency_sensitive_event($event_name)) {
            return false;
        }

        if (self::is_manual_or_admin_origin($event_context)) {
            return false;
        }

        return self::has_delivery_subscribers($event_name, $targets);
    }

    private static function is_latency_sensitive_event(string $event_name): bool
    {
        $normalized_event_name = sanitize_text_field($event_name);

        if (strpos($normalized_event_name, 'chatbot.') === 0) {
            return true;
        }

        return in_array($normalized_event_name, ['form.submitted', 'content.generated', 'image.generated'], true);
    }

    /**
     * @param array<string, mixed> $event_context
     */
    private static function is_manual_or_admin_origin(array $event_context = []): bool
    {
        $origin = sanitize_key((string) ($event_context['origin'] ?? ''));
        if ($origin === '') {
            return false;
        }

        return strpos($origin, 'admin_') === 0 || strpos($origin, 'manual_') === 0;
    }

    /**
     * @param array<int, array<string, mixed>> $targets
     */
    private static function has_delivery_subscribers(string $event_name, array $targets = []): bool
    {
        if (!empty($targets)) {
            return true;
        }

        return (bool) apply_filters('aipkit_event_delivery_has_subscribers', false, $event_name);
    }
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This file intentionally uses the core https_local_ssl_verify hook.

/**
 * Loopback worker, cron fallback, and queue health support for async delivery.
 */
class AIPKit_Event_Queue_Worker
{
    public const AJAX_ACTION = 'aipkit_process_event_delivery_queue';
    public const CRON_HOOK = 'aipkit_process_event_delivery_queue_cron';
    private const DEFAULT_BATCH_SIZE = 5;
    private const CLEANUP_LAST_RUN_OPTION = 'aipkit_event_delivery_queue_cleanup_last_run_gmt';
    private const QUEUE_CONTEXT_KEY = 'aipkit_queue';
    private const QUEUE_META_EMITTED_KEY = 'emitted_hooks_fired';
    private const QUEUE_META_FAILURE_MESSAGES_KEY = 'failed_messages';
    private static bool $shutdown_processing_registered = false;
    private static bool $shutdown_processing_needed = false;

    public static function register_hooks(): void
    {
        add_action('init', [__CLASS__, 'bootstrap_cron']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [__CLASS__, 'ajax_process_queue']);
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, [__CLASS__, 'ajax_process_queue']);
        add_action(self::CRON_HOOK, [__CLASS__, 'process_cron_queue']);
        add_action('aipkit_event_delivery_queue_process_job', [__CLASS__, 'process_job']);
    }

    public static function bootstrap_cron(): void
    {
        if (!self::should_bootstrap_cron()) {
            return;
        }

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            $recurrence = (string) apply_filters('aipkit_event_delivery_queue_cron_recurrence', 'aipkit_five_minutes');
            if ($recurrence === '') {
                $recurrence = 'aipkit_five_minutes';
            }

            wp_schedule_event(time() + MINUTE_IN_SECONDS, $recurrence, self::CRON_HOOK);
        }
    }

    public static function unschedule_cron(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }

        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * @param array<string, mixed> $queue_job
     * @param array<string, mixed> $dispatch_result
     * @param array<string, mixed> $event_context
     */
    public static function maybe_trigger_async_worker(array $queue_job, array $dispatch_result = [], array $event_context = []): void
    {
        $job_uuid = sanitize_text_field((string) ($queue_job['job_uuid'] ?? ''));
        $job_status = sanitize_key((string) ($queue_job['status'] ?? ''));
        if ($job_uuid === '' || $job_status !== 'pending') {
            return;
        }

        $sync_delivery_enabled = !empty($dispatch_result['sync_delivery_enabled']);
        if ($sync_delivery_enabled) {
            return;
        }

        $event_name = sanitize_text_field((string) ($dispatch_result['event_name'] ?? ''));
        $envelope = isset($dispatch_result['envelope']) && is_array($dispatch_result['envelope'])
            ? $dispatch_result['envelope']
            : [];
        $targets = isset($dispatch_result['targets']) && is_array($dispatch_result['targets'])
            ? $dispatch_result['targets']
            : self::get_targets_from_context($event_context);
        $async_delivery_enabled = array_key_exists('async_delivery_enabled', $dispatch_result)
            ? !empty($dispatch_result['async_delivery_enabled'])
            : self::is_async_mode_enabled($event_name, $envelope, $event_context, $targets);

        if (!$async_delivery_enabled) {
            return;
        }

        self::schedule_immediate_cron_fallback();

        $request_url = admin_url('admin-ajax.php');
        $request_args = [
            'blocking' => false,
            'timeout' => max(0.1, (float) apply_filters('aipkit_event_delivery_queue_worker_timeout', 1.0)),
            'sslverify' => apply_filters('https_local_ssl_verify', true),
            'body' => [
                'action' => self::AJAX_ACTION,
                'worker_token' => self::get_worker_token(),
                'batch_size' => self::DEFAULT_BATCH_SIZE,
            ],
        ];

        do_action('aipkit_event_delivery_queue_worker_before_trigger', $queue_job, $dispatch_result, $event_context, $request_args);
        $triggered = self::trigger_worker_via_socket($request_url, $request_args);

        if (!$triggered) {
            wp_remote_post($request_url, $request_args);
        }

        if (function_exists('spawn_cron')) {
            spawn_cron();
        }

        self::schedule_shutdown_processing();
    }

    public static function ajax_process_queue(): void
    {
        if (!self::is_worker_request_authorized()) {
            wp_send_json_error([
                'message' => __('Unauthorized event queue worker request.', 'gpt3-ai-content-generator'),
            ], 403);
        }

        if (!self::is_processing_enabled()) {
            wp_send_json_success([
                'processed_count' => 0,
                'message' => __('Event queue processing is not enabled yet.', 'gpt3-ai-content-generator'),
            ]);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Internal worker requests are authorized with a signed worker token instead of a nonce.
        $batch_size = isset($_REQUEST['batch_size']) ? max(1, min(20, (int) $_REQUEST['batch_size'])) : self::DEFAULT_BATCH_SIZE;
        $processed_count = self::process_queue_batch($batch_size);

        wp_send_json_success([
            'processed_count' => $processed_count,
        ]);
    }

    public static function process_queue_batch(int $batch_size = self::DEFAULT_BATCH_SIZE): int
    {
        if (!self::is_processing_enabled()) {
            return 0;
        }

        if (!has_action('aipkit_event_delivery_queue_process_job')) {
            return 0;
        }

        self::maybe_cleanup_expired_jobs();

        $recovery_summary = AIPKit_Event_Queue_Store::recover_stale_jobs();
        if (!empty($recovery_summary['recovered_count']) || !empty($recovery_summary['failed_count'])) {
            do_action('aipkit_event_delivery_queue_stale_jobs_recovered', $recovery_summary);
        }

        $claimed_jobs = AIPKit_Event_Queue_Store::claim_due_jobs($batch_size);
        if (empty($claimed_jobs)) {
            return 0;
        }

        foreach ($claimed_jobs as $job) {
            do_action('aipkit_event_delivery_queue_process_job', $job);
        }

        return count($claimed_jobs);
    }

    public static function process_cron_queue(): void
    {
        $batch_size = (int) apply_filters('aipkit_event_delivery_queue_cron_batch_size', self::DEFAULT_BATCH_SIZE);
        self::process_queue_batch(max(1, min(20, $batch_size)));
    }

    /**
     * Returns queue health information including worker scheduling state.
     *
     * @return array<string, mixed>
     */
    public static function get_health_snapshot(): array
    {
        $queue_snapshot = AIPKit_Event_Queue_Store::get_health_snapshot();
        $next_cron_timestamp = wp_next_scheduled(self::CRON_HOOK);

        return [
            'processing_enabled' => self::is_processing_enabled(),
            'cron_hook' => self::CRON_HOOK,
            'next_cron_timestamp' => $next_cron_timestamp ? (int) $next_cron_timestamp : 0,
            'next_cron_gmt' => $next_cron_timestamp ? gmdate('Y-m-d H:i:s', (int) $next_cron_timestamp) : '',
            'queue' => $queue_snapshot,
        ];
    }

    /**
     * @param array<string, mixed> $job
     */
    public static function process_job(array $job): void
    {
        $job_uuid = sanitize_text_field((string) ($job['job_uuid'] ?? ''));
        if ($job_uuid === '') {
            return;
        }

        try {
            $envelope = self::decode_json_array((string) ($job['envelope_json'] ?? ''));
            $context = self::decode_json_array((string) ($job['context_json'] ?? ''));
            $event_name = sanitize_text_field((string) ($job['event_name'] ?? ($envelope['event'] ?? '')));

            if ($event_name === '' || empty($envelope)) {
                AIPKit_Event_Queue_Store::mark_job_failed($job_uuid, __('Queued event payload is incomplete.', 'gpt3-ai-content-generator'));
                return;
            }

            $queue_meta = self::get_queue_meta($context);
            $targets = self::resolve_targets($event_name, $envelope, $context);
            $retry_schedule = AIPKit_Event_Delivery_Manager::get_retry_delays();
            $current_attempt = max(1, (int) ($job['attempt_count'] ?? 1));
            $deliveries = !empty($targets)
                ? AIPKit_Event_Delivery_Manager::deliver($event_name, $envelope, $targets, [
                    'retry_delays' => $retry_schedule,
                    'attempt_offset' => max(0, $current_attempt - 1),
                    'max_attempts_per_call' => 1,
                    'sleep_between_attempts' => false,
                ])
                : [];

            $dispatch_result = [
                'event_name' => $event_name,
                'envelope' => $envelope,
                'targets' => $targets,
                'target_count' => count($targets),
                'queue_job' => [
                    'job_uuid' => $job_uuid,
                    'event_id' => sanitize_text_field((string) ($job['event_id'] ?? '')),
                    'event_name' => $event_name,
                    'status' => 'processing',
                ],
                'queue_error' => null,
                'queued_count' => 1,
                'deliveries' => $deliveries,
                'delivered_count' => count(array_filter($deliveries, static function ($delivery): bool {
                    return is_array($delivery) && (($delivery['status'] ?? '') === 'delivered');
                })),
                'failed_count' => count(array_filter($deliveries, static function ($delivery): bool {
                    return is_array($delivery) && (($delivery['status'] ?? '') === 'failed');
                })),
                'async_delivery_requested' => true,
                'async_delivery_enabled' => true,
                'sync_delivery_enabled' => false,
                'processed_by_worker' => true,
            ];

            if (empty($queue_meta[self::QUEUE_META_EMITTED_KEY])) {
                do_action('aipkit_event_webhooks_emitted', $dispatch_result, $context);
                do_action('aipkit_event_webhooks_emitted_' . self::get_hook_suffix($event_name), $dispatch_result, $context);
                $queue_meta[self::QUEUE_META_EMITTED_KEY] = true;
            }

            $queue_meta = self::merge_failure_messages($queue_meta, $deliveries);
            $retry_state = self::build_retry_state($targets, $deliveries);
            if (!empty($retry_state['targets'])) {
                $context = self::set_queue_meta($context, array_merge($queue_meta, [
                    'targets' => $retry_state['targets'],
                ]));

                $rescheduled = AIPKit_Event_Queue_Store::update_job_state($job_uuid, [
                    'status' => 'pending',
                    'locked_at' => null,
                    'processed_at' => null,
                    'available_at' => gmdate('Y-m-d H:i:s', time() + (int) ceil((float) ($retry_state['delay'] ?? 0.0))),
                    'last_error_message' => sanitize_text_field((string) ($retry_state['message'] ?? '')),
                    'target_count' => count($retry_state['targets']),
                    'context_json' => $context,
                ]);

                if ($rescheduled) {
                    $pending_job = [
                        'job_uuid' => $job_uuid,
                        'status' => 'pending',
                    ];

                    if ((float) ($retry_state['delay'] ?? 0.0) <= 0.0) {
                        self::maybe_trigger_async_worker($pending_job, $dispatch_result, $context);
                    }

                    do_action('aipkit_event_delivery_queue_job_rescheduled', $job, $dispatch_result, $context, $retry_state);
                    return;
                }
            }

            $has_failed_deliveries = !empty(array_filter($deliveries, static function ($delivery): bool {
                return is_array($delivery) && (($delivery['status'] ?? '') === 'failed');
            }));
            $persisted_failure_messages = isset($queue_meta[self::QUEUE_META_FAILURE_MESSAGES_KEY]) && is_array($queue_meta[self::QUEUE_META_FAILURE_MESSAGES_KEY])
                ? $queue_meta[self::QUEUE_META_FAILURE_MESSAGES_KEY]
                : [];

            if ($has_failed_deliveries || !empty($persisted_failure_messages)) {
                $error_message = self::build_final_failure_message($deliveries, $persisted_failure_messages);
                $failed_targets = self::extract_failed_targets($targets, $deliveries);
                if (!empty($failed_targets)) {
                    $context = self::set_queue_meta($context, array_merge($queue_meta, [
                        'targets' => $failed_targets,
                    ]));
                }

                AIPKit_Event_Queue_Store::mark_job_failed($job_uuid, $error_message, [
                    'context_json' => $context,
                    'target_count' => !empty($failed_targets) ? count($failed_targets) : count($targets),
                ]);
                do_action('aipkit_event_delivery_queue_job_failed', $job, $error_message, $dispatch_result, $context);
                return;
            }

            AIPKit_Event_Queue_Store::mark_job_completed($job_uuid);
            do_action('aipkit_event_delivery_queue_job_completed', $job, $dispatch_result, $context);
        } catch (\Throwable $throwable) {
            $error_message = sanitize_text_field($throwable->getMessage());
            if ($error_message === '') {
                $error_message = __('Event delivery worker processing failed.', 'gpt3-ai-content-generator');
            }

            AIPKit_Event_Queue_Store::mark_job_failed($job_uuid, $error_message);
            do_action('aipkit_event_delivery_queue_job_failed', $job, $error_message);
        }
    }

    /**
     * @param array<string, mixed> $event_context
     * @param array<string, mixed> $envelope
     * @param array<int, array<string, mixed>> $targets
     */
    private static function is_async_mode_enabled(string $event_name, array $envelope = [], array $event_context = [], array $targets = []): bool
    {
        return (bool) apply_filters(
            'aipkit_event_delivery_queue_async_enabled',
            false,
            $event_name,
            $envelope,
            $event_context,
            $targets
        );
    }

    /**
     * @param array<string, mixed> $event_context
     * @return array<int, array<string, mixed>>
     */
    private static function get_targets_from_context(array $event_context = []): array
    {
        $queue_context = isset($event_context[self::QUEUE_CONTEXT_KEY]) && is_array($event_context[self::QUEUE_CONTEXT_KEY])
            ? $event_context[self::QUEUE_CONTEXT_KEY]
            : [];

        $targets = isset($queue_context['targets']) && is_array($queue_context['targets'])
            ? $queue_context['targets']
            : [];

        return array_values(array_filter($targets, static function ($target): bool {
            return is_array($target);
        }));
    }

    private static function is_processing_enabled(): bool
    {
        return (bool) apply_filters('aipkit_event_delivery_queue_processing_enabled', true);
    }

    private static function is_worker_request_authorized(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Internal loopback requests use a deterministic worker token.
        $submitted_token = sanitize_text_field((string) wp_unslash($_REQUEST['worker_token'] ?? ''));
        if ($submitted_token === '') {
            return false;
        }

        return hash_equals(self::get_worker_token(), $submitted_token);
    }

    private static function get_worker_token(): string
    {
        return hash_hmac('sha256', self::AJAX_ACTION, wp_salt('auth'));
    }

    private static function maybe_cleanup_expired_jobs(): void
    {
        $cleanup_interval_seconds = max(
            HOUR_IN_SECONDS,
            (int) apply_filters('aipkit_event_delivery_queue_cleanup_interval_seconds', DAY_IN_SECONDS)
        );

        $last_run_gmt = sanitize_text_field((string) get_option(self::CLEANUP_LAST_RUN_OPTION, ''));
        if ($last_run_gmt !== '') {
            $last_run_timestamp = strtotime($last_run_gmt . ' GMT');
            if (is_int($last_run_timestamp) && $last_run_timestamp > 0 && (time() - $last_run_timestamp) < $cleanup_interval_seconds) {
                return;
            }
        }

        update_option(self::CLEANUP_LAST_RUN_OPTION, gmdate('Y-m-d H:i:s'), false);
        $cleanup_summary = AIPKit_Event_Queue_Store::cleanup_expired_jobs();

        if (!empty($cleanup_summary['deleted_completed']) || !empty($cleanup_summary['deleted_failed'])) {
            do_action('aipkit_event_delivery_queue_cleanup_completed', $cleanup_summary);
        }
    }

    private static function schedule_immediate_cron_fallback(): void
    {
        if (!function_exists('wp_schedule_single_event')) {
            return;
        }

        wp_schedule_single_event(time(), self::CRON_HOOK);
    }

    private static function schedule_shutdown_processing(): void
    {
        self::$shutdown_processing_needed = true;

        if (self::$shutdown_processing_registered) {
            return;
        }

        self::$shutdown_processing_registered = true;
        add_action('shutdown', [__CLASS__, 'process_shutdown_queue'], 9999);
    }

    public static function process_shutdown_queue(): void
    {
        if (!self::$shutdown_processing_needed || !self::is_processing_enabled()) {
            return;
        }

        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Internal shutdown guard only inspects the current AJAX action for routing.
            $action = sanitize_key((string) ($_REQUEST['action'] ?? ''));
            if ($action === self::AJAX_ACTION) {
                return;
            }
        }

        self::$shutdown_processing_needed = false;
        self::maybe_finish_response();

        $batch_size = (int) apply_filters('aipkit_event_delivery_queue_shutdown_batch_size', 3);
        self::process_queue_batch(max(1, min(5, $batch_size)));
    }

    private static function maybe_finish_response(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            return;
        }

        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
    }

    /**
     * Sends a fire-and-forget POST request to the worker endpoint using a raw
     * socket so local environments do not depend entirely on WP HTTP loopbacks.
     *
     * @param array<string, mixed> $request_args
     */
    private static function trigger_worker_via_socket(string $request_url, array $request_args = []): bool
    {
        if (!function_exists('wp_parse_url')) {
            return false;
        }

        $url_parts = wp_parse_url($request_url);
        if (!is_array($url_parts) || empty($url_parts['host'])) {
            return false;
        }

        $scheme = strtolower((string) ($url_parts['scheme'] ?? 'http'));
        $host = (string) $url_parts['host'];
        $port = isset($url_parts['port'])
            ? (int) $url_parts['port']
            : ($scheme === 'https' ? 443 : 80);
        $path = (string) ($url_parts['path'] ?? '/');
        $query = (string) ($url_parts['query'] ?? '');
        if ($query !== '') {
            $path .= '?' . $query;
        }

        $body = isset($request_args['body']) && is_array($request_args['body'])
            ? http_build_query($request_args['body'], '', '&')
            : '';
        if ($body === '') {
            return false;
        }

        $transport_host = $scheme === 'https' ? 'ssl://' . $host : $host;
        $timeout = max(0.1, (float) ($request_args['timeout'] ?? 1.0));
        $errno = 0;
        $errstr = '';

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen -- Raw socket dispatch preserves the non-loopback async worker trigger path.
        $socket = @fsockopen($transport_host, $port, $errno, $errstr, $timeout);
        if (!is_resource($socket)) {
            return false;
        }

        stream_set_blocking($socket, false);

        $host_header = $host;
        $is_default_http_port = $scheme === 'http' && $port === 80;
        $is_default_https_port = $scheme === 'https' && $port === 443;
        if (!$is_default_http_port && !$is_default_https_port) {
            $host_header .= ':' . $port;
        }

        $request = "POST {$path} HTTP/1.1\r\n";
        $request .= "Host: {$host_header}\r\n";
        $request .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $request .= "Content-Length: " . strlen($body) . "\r\n";
        $request .= "Connection: Close\r\n\r\n";
        $request .= $body;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Raw socket dispatch preserves the non-loopback async worker trigger path.
        $written = @fwrite($socket, $request);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Raw socket dispatch preserves the non-loopback async worker trigger path.
        fclose($socket);

        return $written !== false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode_json_array(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $event_context
     * @param array<string, mixed> $envelope
     * @return array<int, array<string, mixed>>
     */
    private static function resolve_targets(string $event_name, array $envelope, array $event_context = []): array
    {
        $queue_meta = self::get_queue_meta($event_context);
        $targets = isset($queue_meta['targets']) && is_array($queue_meta['targets'])
            ? $queue_meta['targets']
            : AIPKit_Event_Webhooks_Settings::get_active_endpoints_for_event($event_name);

        $targets = apply_filters('aipkit_event_webhooks_targets', $targets, $event_name, $envelope, $event_context);

        return is_array($targets) ? $targets : [];
    }

    /**
     * @param array<string, mixed> $event_context
     * @return array<string, mixed>
     */
    private static function get_queue_meta(array $event_context = []): array
    {
        $queue_meta = isset($event_context[self::QUEUE_CONTEXT_KEY]) && is_array($event_context[self::QUEUE_CONTEXT_KEY])
            ? $event_context[self::QUEUE_CONTEXT_KEY]
            : [];

        return is_array($queue_meta) ? $queue_meta : [];
    }

    /**
     * @param array<string, mixed> $event_context
     * @param array<string, mixed> $queue_meta
     * @return array<string, mixed>
     */
    private static function set_queue_meta(array $event_context, array $queue_meta): array
    {
        $event_context[self::QUEUE_CONTEXT_KEY] = $queue_meta;

        return $event_context;
    }

    /**
     * @param array<int, array<string, mixed>> $targets
     * @param array<int, array<string, mixed>> $deliveries
     * @return array<string, mixed>
     */
    private static function build_retry_state(array $targets, array $deliveries): array
    {
        $retry_targets = [];
        $retry_delay = 0.0;
        $messages = [];

        foreach ($deliveries as $index => $delivery) {
            if (!is_array($delivery) || empty($delivery['should_retry'])) {
                continue;
            }

            if (isset($targets[$index]) && is_array($targets[$index])) {
                $retry_targets[] = $targets[$index];
            }

            $retry_delay = max($retry_delay, (float) ($delivery['retry_delay'] ?? 0.0));
            $message = sanitize_text_field((string) ($delivery['error_message'] ?? ''));
            if ($message !== '') {
                $messages[] = $message;
            }
        }

        return [
            'targets' => $retry_targets,
            'delay' => $retry_delay,
            'message' => !empty($messages) ? implode(' | ', array_unique($messages)) : __('Retrying failed webhook delivery.', 'gpt3-ai-content-generator'),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $targets
     * @param array<int, array<string, mixed>> $deliveries
     * @return array<int, array<string, mixed>>
     */
    private static function extract_failed_targets(array $targets, array $deliveries): array
    {
        $failed_targets = [];

        foreach ($deliveries as $index => $delivery) {
            if (!is_array($delivery) || (($delivery['status'] ?? '') !== 'failed')) {
                continue;
            }

            if (isset($targets[$index]) && is_array($targets[$index])) {
                $failed_targets[] = $targets[$index];
            }
        }

        return $failed_targets;
    }

    /**
     * @param array<int, array<string, mixed>> $deliveries
     * @param array<int, string> $persisted_messages
     */
    private static function build_final_failure_message(array $deliveries, array $persisted_messages = []): string
    {
        $messages = [];
        foreach ($deliveries as $delivery) {
            if (!is_array($delivery) || (($delivery['status'] ?? '') !== 'failed')) {
                continue;
            }

            $message = sanitize_text_field((string) ($delivery['error_message'] ?? ''));
            if ($message !== '') {
                $messages[] = $message;
            }
        }

        foreach ($persisted_messages as $message) {
            $message = sanitize_text_field((string) $message);
            if ($message !== '') {
                $messages[] = $message;
            }
        }

        return !empty($messages)
            ? implode(' | ', array_unique($messages))
            : __('Event delivery failed after retry attempts were exhausted.', 'gpt3-ai-content-generator');
    }

    /**
     * @param array<string, mixed> $queue_meta
     * @param array<int, array<string, mixed>> $deliveries
     * @return array<string, mixed>
     */
    private static function merge_failure_messages(array $queue_meta, array $deliveries): array
    {
        $messages = isset($queue_meta[self::QUEUE_META_FAILURE_MESSAGES_KEY]) && is_array($queue_meta[self::QUEUE_META_FAILURE_MESSAGES_KEY])
            ? $queue_meta[self::QUEUE_META_FAILURE_MESSAGES_KEY]
            : [];

        foreach ($deliveries as $delivery) {
            if (!is_array($delivery) || (($delivery['status'] ?? '') !== 'failed') || !empty($delivery['should_retry'])) {
                continue;
            }

            $message = sanitize_text_field((string) ($delivery['error_message'] ?? ''));
            if ($message !== '') {
                $messages[] = $message;
            }
        }

        $queue_meta[self::QUEUE_META_FAILURE_MESSAGES_KEY] = array_values(array_unique(array_filter(array_map(
            static function ($message): string {
                return sanitize_text_field((string) $message);
            },
            $messages
        ))));

        return $queue_meta;
    }

    private static function get_hook_suffix(string $event_name): string
    {
        return str_replace(['.', '-'], '_', sanitize_key($event_name));
    }

    private static function should_bootstrap_cron(): bool
    {
        if (is_admin() || wp_doing_cron()) {
            return true;
        }

        return defined('WP_CLI') && WP_CLI;
    }
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- This store only queries plugin-owned queue tables and scalar values are normalized before each prepared call.

/**
 * Durable queue storage for emitted events before async delivery.
 */
class AIPKit_Event_Queue_Store
{
    private const TABLE_SUFFIX = 'aipkit_event_delivery_queue';
    private const ALLOWED_STATUSES = ['captured', 'pending', 'processing', 'completed', 'failed'];

    private static bool $table_ensured = false;

    public static function get_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public static function ensure_table(): void
    {
        if (self::$table_ensured) {
            return;
        }

        $table_name = self::get_table_name();
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required to check custom queue table existence.
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
        if ($table_exists !== $table_name && function_exists('aipkit_create_event_delivery_queue_table')) {
            aipkit_create_event_delivery_queue_table();
        }

        self::$table_ensured = true;
    }

    /**
     * @param array<string, mixed> $envelope
     * @param array<string, mixed> $context
     * @param array<string, mixed> $meta
     * @return array<string, mixed>|WP_Error
     */
    public static function enqueue_event(string $event_name, array $envelope, array $context = [], array $meta = [])
    {
        global $wpdb;

        self::ensure_table();

        $normalized_event_name = sanitize_text_field($event_name);
        $event_id = sanitize_text_field((string) ($envelope['id'] ?? ''));
        if ($normalized_event_name === '' || $event_id === '') {
            return new WP_Error(
                'aipkit_event_queue_invalid_payload',
                __('Event queue payload is missing required identifiers.', 'gpt3-ai-content-generator')
            );
        }

        $envelope_json = self::encode_json($envelope);
        if ($envelope_json === '') {
            return new WP_Error(
                'aipkit_event_queue_encode_failed',
                __('Failed to encode the event envelope for queue storage.', 'gpt3-ai-content-generator')
            );
        }

        $context_json = self::encode_json(self::normalize_context_for_storage($context));
        $source = isset($envelope['source']) && is_array($envelope['source']) ? $envelope['source'] : [];
        $source_module = sanitize_key((string) ($source['module'] ?? ''));
        $table_name = self::get_table_name();
        $job_uuid = wp_generate_uuid4();
        $available_at = gmdate('Y-m-d H:i:s');
        $initial_status = self::normalize_status((string) ($meta['initial_status'] ?? 'captured'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Insert into a plugin-owned queue table.
        $inserted = $wpdb->insert(
            $table_name,
            [
                'job_uuid' => $job_uuid,
                'event_id' => $event_id,
                'event_name' => $normalized_event_name,
                'event_idempotency_key' => sanitize_text_field((string) ($envelope['idempotency_key'] ?? '')),
                'source_module' => $source_module,
                'status' => $initial_status,
                'attempt_count' => 0,
                'target_count' => max(0, (int) ($meta['target_count'] ?? 0)),
                'available_at' => $available_at,
                'envelope_json' => $envelope_json,
                'context_json' => $context_json !== '' ? $context_json : null,
            ],
            [
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
                '%d',
                '%s',
                '%s',
                '%s',
            ]
        );

        if ($inserted === false) {
            return new WP_Error(
                'aipkit_event_queue_insert_failed',
                __('Failed to queue the emitted event.', 'gpt3-ai-content-generator'),
                [
                    'db_error' => sanitize_text_field((string) $wpdb->last_error),
                    'event_id' => $event_id,
                    'event_name' => $normalized_event_name,
                ]
            );
        }

        return [
            'job_uuid' => $job_uuid,
            'event_id' => $event_id,
            'event_name' => $normalized_event_name,
            'status' => $initial_status,
            'attempt_count' => 0,
            'target_count' => max(0, (int) ($meta['target_count'] ?? 0)),
            'available_at' => $available_at,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function claim_due_jobs(int $limit = 5): array
    {
        global $wpdb;

        self::ensure_table();

        $safe_limit = max(1, min(20, $limit));
        $table_name = self::get_table_name();
        $now_gmt = gmdate('Y-m-d H:i:s');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom queue reads.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE status = %s AND available_at <= %s ORDER BY created_at ASC LIMIT %d",
                'pending',
                $now_gmt,
                $safe_limit
            ),
            ARRAY_A
        );

        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $claimed = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $job_uuid = sanitize_text_field((string) ($row['job_uuid'] ?? ''));
            if ($job_uuid === '') {
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for queue claim updates.
            $updated = $wpdb->update(
                $table_name,
                [
                    'status' => 'processing',
                    'locked_at' => $now_gmt,
                    'attempt_count' => max(1, (int) ($row['attempt_count'] ?? 0) + 1),
                ],
                [
                    'job_uuid' => $job_uuid,
                    'status' => 'pending',
                ],
                ['%s', '%s', '%d'],
                ['%s', '%s']
            );

            if ($updated !== 1) {
                continue;
            }

            $row['status'] = 'processing';
            $row['locked_at'] = $now_gmt;
            $row['attempt_count'] = max(1, (int) ($row['attempt_count'] ?? 0) + 1);
            $claimed[] = self::normalize_row($row);
        }

        return $claimed;
    }

    public static function mark_job_completed(string $job_uuid): bool
    {
        return self::delete_job($job_uuid);
    }

    /**
     * @param array<string, mixed> $updates
     */
    public static function mark_job_failed(string $job_uuid, string $error_message = '', array $updates = []): bool
    {
        return self::update_job_state($job_uuid, array_merge([
            'status' => 'failed',
            'locked_at' => null,
            'processed_at' => gmdate('Y-m-d H:i:s'),
            'last_error_message' => sanitize_text_field($error_message),
        ], $updates));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get_job_by_uuid(string $job_uuid): ?array
    {
        global $wpdb;

        self::ensure_table();

        $normalized_uuid = sanitize_text_field($job_uuid);
        if ($normalized_uuid === '') {
            return null;
        }

        $table_name = self::get_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for direct queue row reads.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE job_uuid = %s LIMIT 1",
                $normalized_uuid
            ),
            ARRAY_A
        );

        if (!is_array($row)) {
            return null;
        }

        return self::normalize_job_with_payload($row);
    }

    /**
     * Returns recent failed webhook queue jobs for the Event Webhooks admin surface.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_recent_failed_webhook_jobs(int $limit = 8): array
    {
        global $wpdb;

        self::ensure_table();

        $safe_limit = max(1, min(20, $limit));
        $table_name = self::get_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for direct queue diagnostics reads.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE status = %s AND target_count > 0 ORDER BY COALESCE(processed_at, created_at) DESC LIMIT %d",
                'failed',
                $safe_limit
            ),
            ARRAY_A
        );

        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter(array_map([__CLASS__, 'normalize_job_with_payload'], $rows), 'is_array'));
    }

    /**
     * Claims a specific pending job for immediate processing.
     *
     * @return array<string, mixed>|null
     */
    public static function claim_job_by_uuid(string $job_uuid): ?array
    {
        global $wpdb;

        self::ensure_table();

        $normalized_uuid = sanitize_text_field($job_uuid);
        if ($normalized_uuid === '') {
            return null;
        }

        $table_name = self::get_table_name();
        $now_gmt = gmdate('Y-m-d H:i:s');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for direct queue row reads.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE job_uuid = %s AND status = %s LIMIT 1",
                $normalized_uuid,
                'pending'
            ),
            ARRAY_A
        );

        if (!is_array($row)) {
            return null;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for queue claim updates.
        $updated = $wpdb->update(
            $table_name,
            [
                'status' => 'processing',
                'locked_at' => $now_gmt,
                'attempt_count' => max(1, (int) ($row['attempt_count'] ?? 0) + 1),
            ],
            [
                'job_uuid' => $normalized_uuid,
                'status' => 'pending',
            ],
            ['%s', '%s', '%d'],
            ['%s', '%s']
        );

        if ($updated !== 1) {
            return null;
        }

        $row['status'] = 'processing';
        $row['locked_at'] = $now_gmt;
        $row['attempt_count'] = max(1, (int) ($row['attempt_count'] ?? 0) + 1);

        return self::normalize_job_with_payload($row);
    }

    public static function clear_failed_webhook_job(string $job_uuid): bool
    {
        $job = self::get_job_by_uuid($job_uuid);
        if (!is_array($job) || !self::is_failed_webhook_job($job)) {
            return false;
        }

        return self::delete_job($job_uuid);
    }

    /**
     * Deletes completed queue jobs immediately and prunes failed queue jobs
     * after a short retention window.
     *
     * @return array<string, int>
     */
    public static function cleanup_expired_jobs(): array
    {
        global $wpdb;

        self::ensure_table();

        $table_name = self::get_table_name();
        $failed_retention_days = max(1, (int) apply_filters('aipkit_event_delivery_queue_failed_retention_days', 7));
        $failed_cutoff = gmdate('Y-m-d H:i:s', time() - ($failed_retention_days * DAY_IN_SECONDS));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required cleanup on custom queue table.
        $deleted_completed = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} WHERE status = %s",
                'completed'
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required cleanup on custom queue table.
        $deleted_failed = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} WHERE status = %s AND ((processed_at IS NOT NULL AND processed_at < %s) OR (processed_at IS NULL AND created_at < %s))",
                'failed',
                $failed_cutoff,
                $failed_cutoff
            )
        );

        return [
            'deleted_completed' => is_int($deleted_completed) ? $deleted_completed : 0,
            'deleted_failed' => is_int($deleted_failed) ? $deleted_failed : 0,
            'failed_retention_days' => $failed_retention_days,
        ];
    }

    /**
     * Recovers stale processing jobs that were abandoned by a crashed worker.
     *
     * Jobs that exceed the allowed processing attempts are marked failed. The
     * rest are returned to `pending` so a later loopback/cron pass can reclaim
     * them.
     *
     * @return array<string, mixed>
     */
    public static function recover_stale_jobs(): array
    {
        global $wpdb;

        self::ensure_table();

        $stale_after_seconds = max(60, (int) apply_filters('aipkit_event_delivery_queue_stale_after_seconds', 15 * MINUTE_IN_SECONDS));
        $max_processing_attempts = max(1, (int) apply_filters('aipkit_event_delivery_queue_max_processing_attempts', 6));
        $recovery_limit = max(1, min(100, (int) apply_filters('aipkit_event_delivery_queue_recovery_limit', 25)));

        $table_name = self::get_table_name();
        $cutoff_gmt = gmdate('Y-m-d H:i:s', time() - $stale_after_seconds);
        $now_gmt = gmdate('Y-m-d H:i:s');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom queue recovery reads.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE status = %s AND locked_at IS NOT NULL AND locked_at <= %s ORDER BY locked_at ASC LIMIT %d",
                'processing',
                $cutoff_gmt,
                $recovery_limit
            ),
            ARRAY_A
        );

        $summary = [
            'checked_count' => is_array($rows) ? count($rows) : 0,
            'recovered_count' => 0,
            'failed_count' => 0,
            'stale_after_seconds' => $stale_after_seconds,
            'max_processing_attempts' => $max_processing_attempts,
            'oldest_locked_at' => '',
            'recovered_job_uuids' => [],
            'failed_job_uuids' => [],
        ];

        if (!is_array($rows) || empty($rows)) {
            return $summary;
        }

        $oldest_locked_at = sanitize_text_field((string) ($rows[0]['locked_at'] ?? ''));
        if ($oldest_locked_at !== '') {
            $summary['oldest_locked_at'] = $oldest_locked_at;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $job_uuid = sanitize_text_field((string) ($row['job_uuid'] ?? ''));
            if ($job_uuid === '') {
                continue;
            }

            $attempt_count = max(0, (int) ($row['attempt_count'] ?? 0));
            if ($attempt_count >= $max_processing_attempts) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom queue recovery writes.
                $updated = $wpdb->update(
                    $table_name,
                    [
                        'status' => 'failed',
                        'locked_at' => null,
                        'processed_at' => $now_gmt,
                        'last_error_message' => sanitize_text_field(__('Queue job exceeded stale-processing recovery attempts.', 'gpt3-ai-content-generator')),
                    ],
                    [
                        'job_uuid' => $job_uuid,
                        'status' => 'processing',
                    ],
                    ['%s', '%s', '%s', '%s'],
                    ['%s', '%s']
                );

                if ($updated === 1) {
                    $summary['failed_count']++;
                    $summary['failed_job_uuids'][] = $job_uuid;
                }

                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom queue recovery writes.
            $updated = $wpdb->update(
                $table_name,
                [
                    'status' => 'pending',
                    'locked_at' => null,
                    'processed_at' => null,
                    'available_at' => $now_gmt,
                    'last_error_message' => sanitize_text_field(__('Recovered stale queue job after worker timeout.', 'gpt3-ai-content-generator')),
                ],
                [
                    'job_uuid' => $job_uuid,
                    'status' => 'processing',
                ],
                ['%s', '%s', '%s', '%s', '%s'],
                ['%s', '%s']
            );

            if ($updated === 1) {
                $summary['recovered_count']++;
                $summary['recovered_job_uuids'][] = $job_uuid;
            }
        }

        return $summary;
    }

    /**
     * Returns a queue health summary for diagnostics.
     *
     * @return array<string, mixed>
     */
    public static function get_health_snapshot(): array
    {
        global $wpdb;

        self::ensure_table();

        $table_name = self::get_table_name();
        $stale_after_seconds = max(60, (int) apply_filters('aipkit_event_delivery_queue_stale_after_seconds', 15 * MINUTE_IN_SECONDS));
        $cutoff_gmt = gmdate('Y-m-d H:i:s', time() - $stale_after_seconds);

        $status_counts = [
            'captured' => 0,
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
        ];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom queue diagnostics reads.
        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) AS total FROM {$table_name} GROUP BY status",
            ARRAY_A
        );

        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $status = self::normalize_status((string) ($row['status'] ?? 'captured'));
                $status_counts[$status] = max(0, (int) ($row['total'] ?? 0));
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom queue diagnostics reads.
        $stale_processing_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE status = %s AND locked_at IS NOT NULL AND locked_at <= %s",
                'processing',
                $cutoff_gmt
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom queue diagnostics reads.
        $oldest_pending_available_at = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT available_at FROM {$table_name} WHERE status = %s ORDER BY available_at ASC LIMIT 1",
                'pending'
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom queue diagnostics reads.
        $oldest_processing_locked_at = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT locked_at FROM {$table_name} WHERE status = %s AND locked_at IS NOT NULL ORDER BY locked_at ASC LIMIT 1",
                'processing'
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom queue diagnostics reads.
        $last_failed_at = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT processed_at FROM {$table_name} WHERE status = %s AND processed_at IS NOT NULL ORDER BY processed_at DESC LIMIT 1",
                'failed'
            )
        );

        $snapshot = [
            'total_jobs' => array_sum($status_counts),
            'status_counts' => $status_counts,
            'stale_processing_count' => max(0, $stale_processing_count),
            'stale_after_seconds' => $stale_after_seconds,
            'oldest_pending_available_at' => sanitize_text_field($oldest_pending_available_at),
            'oldest_processing_locked_at' => sanitize_text_field($oldest_processing_locked_at),
            'last_failed_at' => sanitize_text_field($last_failed_at),
        ];

        return apply_filters('aipkit_event_delivery_queue_health_snapshot', $snapshot, $table_name);
    }

    /**
     * @param array<string, mixed> $updates
     */
    public static function update_job_state(string $job_uuid, array $updates): bool
    {
        global $wpdb;

        self::ensure_table();

        $normalized_uuid = sanitize_text_field($job_uuid);
        if ($normalized_uuid === '') {
            return false;
        }

        $table_name = self::get_table_name();
        $update_data = [];
        $update_format = [];

        if (isset($updates['status'])) {
            $update_data['status'] = self::normalize_status((string) $updates['status']);
            $update_format[] = '%s';
        }

        if (array_key_exists('locked_at', $updates)) {
            $update_data['locked_at'] = $updates['locked_at'] !== null ? sanitize_text_field((string) $updates['locked_at']) : null;
            $update_format[] = '%s';
        }

        if (array_key_exists('processed_at', $updates)) {
            $update_data['processed_at'] = $updates['processed_at'] !== null ? sanitize_text_field((string) $updates['processed_at']) : null;
            $update_format[] = '%s';
        }

        if (array_key_exists('available_at', $updates)) {
            $update_data['available_at'] = sanitize_text_field((string) $updates['available_at']);
            $update_format[] = '%s';
        }

        if (array_key_exists('last_error_message', $updates)) {
            $update_data['last_error_message'] = sanitize_text_field((string) $updates['last_error_message']);
            $update_format[] = '%s';
        }

        if (array_key_exists('target_count', $updates)) {
            $update_data['target_count'] = max(0, (int) $updates['target_count']);
            $update_format[] = '%d';
        }

        if (array_key_exists('attempt_count', $updates)) {
            $update_data['attempt_count'] = max(0, (int) $updates['attempt_count']);
            $update_format[] = '%d';
        }

        if (array_key_exists('context_json', $updates)) {
            $context_json = '';
            if (is_string($updates['context_json'])) {
                $context_json = $updates['context_json'];
            } elseif (is_array($updates['context_json'])) {
                $context_json = self::encode_json(self::normalize_context_for_storage($updates['context_json']));
            }

            $update_data['context_json'] = $context_json !== '' ? $context_json : null;
            $update_format[] = '%s';
        }

        if (empty($update_data)) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom queue writes.
        $updated = $wpdb->update(
            $table_name,
            $update_data,
            ['job_uuid' => $normalized_uuid],
            $update_format,
            ['%s']
        );

        return $updated !== false;
    }

    private static function delete_job(string $job_uuid): bool
    {
        global $wpdb;

        self::ensure_table();

        $normalized_uuid = sanitize_text_field($job_uuid);
        if ($normalized_uuid === '') {
            return false;
        }

        $table_name = self::get_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required delete on custom queue table.
        $deleted = $wpdb->delete(
            $table_name,
            ['job_uuid' => $normalized_uuid],
            ['%s']
        );

        return $deleted === 1;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function normalize_context_for_storage($value)
    {
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return sanitize_text_field($value);
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $child_value) {
                $normalized_key = is_int($key) ? $key : sanitize_key((string) $key);
                if ($normalized_key === '') {
                    continue;
                }

                $normalized_child = self::normalize_context_for_storage($child_value);
                if ($normalized_child === null) {
                    continue;
                }

                $normalized[$normalized_key] = $normalized_child;
            }

            return $normalized;
        }

        if (is_scalar($value)) {
            return sanitize_text_field((string) $value);
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    private static function encode_json($value): string
    {
        $encoded = wp_json_encode($value);

        return is_string($encoded) ? $encoded : '';
    }

    private static function normalize_status(string $status): string
    {
        $normalized_status = sanitize_key($status);

        if (!in_array($normalized_status, self::ALLOWED_STATUSES, true)) {
            return 'captured';
        }

        return $normalized_status;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize_row(array $row): array
    {
        return [
            'job_uuid' => sanitize_text_field((string) ($row['job_uuid'] ?? '')),
            'event_id' => sanitize_text_field((string) ($row['event_id'] ?? '')),
            'event_name' => sanitize_text_field((string) ($row['event_name'] ?? '')),
            'event_idempotency_key' => sanitize_text_field((string) ($row['event_idempotency_key'] ?? '')),
            'status' => self::normalize_status((string) ($row['status'] ?? 'captured')),
            'attempt_count' => max(0, (int) ($row['attempt_count'] ?? 0)),
            'target_count' => max(0, (int) ($row['target_count'] ?? 0)),
            'available_at' => sanitize_text_field((string) ($row['available_at'] ?? '')),
            'locked_at' => sanitize_text_field((string) ($row['locked_at'] ?? '')),
            'processed_at' => sanitize_text_field((string) ($row['processed_at'] ?? '')),
            'last_error_message' => sanitize_text_field((string) ($row['last_error_message'] ?? '')),
            'envelope_json' => (string) ($row['envelope_json'] ?? ''),
            'context_json' => (string) ($row['context_json'] ?? ''),
            'source_module' => sanitize_key((string) ($row['source_module'] ?? '')),
            'created_at' => sanitize_text_field((string) ($row['created_at'] ?? '')),
            'updated_at' => sanitize_text_field((string) ($row['updated_at'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize_job_with_payload(array $row): array
    {
        $normalized = self::normalize_row($row);
        $envelope = self::decode_json_array((string) ($normalized['envelope_json'] ?? ''));
        $context = self::decode_json_array((string) ($normalized['context_json'] ?? ''));
        $targets = self::extract_webhook_targets_from_context($context);

        $normalized['envelope'] = $envelope;
        $normalized['context'] = $context;
        $normalized['targets'] = $targets;
        $normalized['target_labels'] = self::extract_target_labels($targets);
        $normalized['target_summary'] = self::build_target_summary($targets);
        $normalized['error_message'] = sanitize_text_field((string) ($normalized['last_error_message'] ?? ''));
        $normalized['resource_label'] = sanitize_text_field((string) (($envelope['resource']['label'] ?? '') ?: ($envelope['type'] ?? '')));
        $normalized['displayed_at'] = sanitize_text_field((string) (($normalized['processed_at'] ?? '') ?: ($normalized['created_at'] ?? '')));

        return $normalized;
    }

    /**
     * @param array<string, mixed> $job
     */
    private static function is_failed_webhook_job(array $job): bool
    {
        return self::normalize_status((string) ($job['status'] ?? '')) === 'failed'
            && max(0, (int) ($job['target_count'] ?? 0)) > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode_json_array(string $value): array
    {
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    private static function extract_webhook_targets_from_context(array $context): array
    {
        $queue_meta = isset($context['aipkit_queue']) && is_array($context['aipkit_queue'])
            ? $context['aipkit_queue']
            : [];
        $targets = isset($queue_meta['targets']) && is_array($queue_meta['targets'])
            ? $queue_meta['targets']
            : [];

        $normalized_targets = [];
        foreach ($targets as $target) {
            if (!is_array($target)) {
                continue;
            }

            $url = esc_url_raw((string) ($target['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $normalized_targets[] = [
                'id' => sanitize_key((string) ($target['id'] ?? '')),
                'name' => sanitize_text_field((string) ($target['name'] ?? '')),
                'url' => $url,
            ];
        }

        return $normalized_targets;
    }

    /**
     * @param array<int, array<string, mixed>> $targets
     * @return array<int, string>
     */
    private static function extract_target_labels(array $targets): array
    {
        $labels = [];
        foreach ($targets as $target) {
            if (!is_array($target)) {
                continue;
            }

            $label = sanitize_text_field((string) ($target['name'] ?? ''));
            if ($label === '') {
                $url = esc_url_raw((string) ($target['url'] ?? ''));
                $host = $url !== '' ? wp_parse_url($url, PHP_URL_HOST) : '';
                $label = sanitize_text_field((string) ($host ?: $url));
            }

            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * @param array<int, array<string, mixed>> $targets
     */
    private static function build_target_summary(array $targets): string
    {
        $labels = self::extract_target_labels($targets);
        if (empty($labels)) {
            return __('Webhook endpoint', 'gpt3-ai-content-generator');
        }

        if (count($labels) === 1) {
            return $labels[0];
        }

        $visible_labels = array_slice($labels, 0, 2);
        $remaining_count = count($labels) - count($visible_labels);
        $summary = implode(', ', $visible_labels);

        if ($remaining_count > 0) {
            $summary .= sprintf(
                /* translators: %d: remaining endpoint count */
                __(' +%d more', 'gpt3-ai-content-generator'),
                $remaining_count
            );
        }

        return $summary;
    }
}
