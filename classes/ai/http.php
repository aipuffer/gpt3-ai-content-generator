<?php

namespace WPAICG\Core;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

class AIPKit_HTTP_Request
{
    /** Classify refusals consistently across batch workers and HTTP responses. */
    public static function batch_error_data(WP_Error $error): array
    {
        $data = is_array($error->get_error_data()) ? $error->get_error_data() : [];
        $body = json_decode((string) ($data['response_body_for_debug'] ?? ''), true);
        $upstream_code = $body['error']['code'] ?? $body['error']['type'] ?? '';
        $code = (string) ($data['provider_error_code'] ?? (is_scalar($upstream_code) && $upstream_code !== '' ? $upstream_code : $error->get_error_code()));
        $status = (int) ($data['status_code'] ?? $data['status'] ?? 0);
        $account_error = in_array($code, ['insufficient_funds', 'insufficient_credits', 'insufficient_quota', 'credit_deficit', 'account_paused', 'credential_unavailable', 'unauthorized', 'invalid_api_key', 'billing_hard_limit_reached', 'missing_api_key', 'missing_azure_endpoint'], true);
        $stop = !empty($data['stop_batch']) || $account_error || in_array($status, [401, 402, 403, 429], true);
        $rate_limit = !$account_error && ($status === 429 || in_array($code, ['generation_rate_limit', 'generation_concurrency_limit', 'cloud_operation_rate_limit', 'rate_limit_exceeded'], true));
        return [
            'code' => $error->get_error_code(),
            'provider_error_code' => $code,
            'provider' => sanitize_text_field((string) ($data['provider'] ?? '')),
            'stop_batch' => $stop,
            'pause_task' => $stop && !$rate_limit,
        ];
    }

    /** Public error fields only; provider payloads and credentials never leave this boundary. */
    public static function public_error_data(WP_Error $error, int $default_status = 500): array
    {
        $source = is_array($error->get_error_data()) ? $error->get_error_data() : [];
        $status = $default_status;
        foreach (['status', 'status_code'] as $key) {
            if (isset($source[$key]) && is_numeric($source[$key]) && (int) $source[$key] >= 400 && (int) $source[$key] <= 599) {
                $status = (int) $source[$key];
                break;
            }
        }
        $data = array_merge(self::batch_error_data($error), ['status' => $status]);
        foreach (['cloud_operation_id', 'cloud_request_id', 'cloud_request_state'] as $key) {
            if (isset($source[$key]) && is_string($source[$key])) { $data[$key] = sanitize_text_field($source[$key]); }
        }
        if (isset($source['outcome_unknown'])) { $data['outcome_unknown'] = (bool) $source['outcome_unknown']; }
        if (isset($source['retry_after']) && is_numeric($source['retry_after'])) { $data['retry_after'] = max(0, (int) ceil((float) $source['retry_after'])); }
        return $data;
    }

    /** Whether the complete native transport used by our streams/uploads is available. */
    public static function has_curl(): bool
    {
        foreach (['curl_init', 'curl_setopt', 'curl_setopt_array', 'curl_exec', 'curl_getinfo', 'curl_errno', 'curl_error'] as $function) {
            if (!function_exists($function)) {
                return false;
            }
        }
        return true;
    }

    /** @return array|WP_Error */
    public static function multipart(string $url, array $fields, array $files, array $args = [], bool $bypass_wp_ai_connector_approval = false)
    {
        require_once __DIR__ . '/multipart.php';
        return AIPKit_HTTP_Multipart::request($url, $fields, $files, $args, $bypass_wp_ai_connector_approval);
    }

    /**
     * Runs an HTTP request for AI Puffer-owned provider calls.
     *
     * The WordPress AI connector approval guard matches outbound credentials in
     * the WP HTTP stack. AI Puffer stores and uses its own provider settings, so
     * a matching key in a separate connector plugin is a false attribution for
     * these internal provider requests.
     *
     * @param string $url Request URL.
     * @param array $args Request arguments.
     * @param bool $bypass_wp_ai_connector_approval Whether to bypass only the WP AI approval guard.
     * @return array|WP_Error
     */
    public static function request(string $url, array $args = [], bool $bypass_wp_ai_connector_approval = false)
    {
        if (!$bypass_wp_ai_connector_approval) {
            return wp_remote_request($url, $args);
        }

        return self::without_wp_ai_connector_approval_guard(
            static fn() => wp_remote_request($url, $args)
        );
    }

    /**
     * Temporarily removes only WordPress AI's connector approval HTTP guard.
     *
     * AI Puffer enforces WordPress AI Client gateway approvals before gateway
     * calls reach the provider layer. This wrapper avoids duplicate/false
     * approval blocks for AI Puffer's own provider requests.
     *
     * @param callable $callback Request callback.
     * @return mixed
     */
    private static function without_wp_ai_connector_approval_guard(callable $callback)
    {
        $removed = self::remove_wp_ai_connector_approval_filters();

        try {
            return $callback();
        } finally {
            self::restore_filters($removed);
        }
    }

    private static function remove_wp_ai_connector_approval_filters(): array
    {
        if (!class_exists('\WordPress\AI\Connector_Approval\Http_Guard', false)) {
            return [];
        }

        global $wp_filter;
        $hook = $wp_filter['pre_http_request'] ?? null;
        if (!is_object($hook) || empty($hook->callbacks) || !is_array($hook->callbacks)) {
            return [];
        }

        $removed = [];
        foreach ($hook->callbacks as $priority => $callbacks) {
            foreach ((array) $callbacks as $callback) {
                $function = $callback['function'] ?? null;
                if (!self::is_wp_ai_connector_approval_callback($function)) {
                    continue;
                }

                $accepted_args = isset($callback['accepted_args']) ? (int) $callback['accepted_args'] : 1;
                remove_filter('pre_http_request', $function, (int) $priority);
                $removed[] = [$function, (int) $priority, $accepted_args];
            }
        }

        return $removed;
    }

    private static function restore_filters(array $removed): void
    {
        foreach ($removed as $filter) {
            [$function, $priority, $accepted_args] = $filter;
            add_filter('pre_http_request', $function, $priority, $accepted_args);
        }
    }

    private static function is_wp_ai_connector_approval_callback($function): bool
    {
        return is_array($function)
            && is_object($function[0] ?? null)
            && $function[0] instanceof \WordPress\AI\Connector_Approval\Http_Guard;
    }
}
