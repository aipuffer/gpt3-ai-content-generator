<?php

namespace WPAICG\AutoGPT\Cron;

use WP_Error;
use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stores and authenticates the optional external server-cron integration.
 */
class AIPKit_Automation_Server_Cron
{
    public const OPTION_NAME = 'aipkit_automation_server_cron_v1';
    public const HEADER_NAME = 'X-AIPKit-Cron-Token';
    private const HEALTH_WINDOW = 10 * MINUTE_IN_SECONDS;

    /**
     * Enables server cron and returns the one-time secret and copyable command.
     *
     * @return array<string, mixed>|WP_Error
     */
    public static function enable()
    {
        if (self::is_enabled()) {
            return new WP_Error(
                'aipkit_server_cron_already_enabled',
                __('Server cron is already enabled. Rotate the secret if you need a new command.', 'gpt3-ai-content-generator')
            );
        }

        return self::store_new_secret();
    }

    /**
     * Rotates the secret and invalidates the previous command immediately.
     *
     * @return array<string, mixed>|WP_Error
     */
    public static function rotate_secret()
    {
        if (!self::is_enabled()) {
            return new WP_Error(
                'aipkit_server_cron_not_enabled',
                __('Enable server cron before rotating its secret.', 'gpt3-ai-content-generator')
            );
        }

        return self::store_new_secret();
    }

    /**
     * Disables the endpoint and removes the stored secret hash.
     */
    public static function disable(): array
    {
        $settings = self::get_settings();
        $settings['enabled'] = false;
        $settings['secret_hash'] = '';
        $settings['secret_hint'] = '';
        $settings['updated_at'] = time();
        update_option(self::OPTION_NAME, $settings, false);

        return self::get_status();
    }

    /**
     * Returns whether the dedicated server endpoint is explicitly enabled.
     */
    public static function is_enabled(): bool
    {
        $settings = self::get_settings();
        return !empty($settings['enabled']) && !empty($settings['secret_hash']);
    }

    /**
     * Authenticates a server-cron REST request.
     *
     * @return true|WP_Error
     */
    public static function check_request(WP_REST_Request $request)
    {
        if (!self::is_enabled()) {
            return new WP_Error(
                'aipkit_server_cron_disabled',
                __('AI Puffer server cron is disabled.', 'gpt3-ai-content-generator'),
                ['status' => 403]
            );
        }

        if (class_exists('\WPAICG\aipkit_dashboard')) {
            $module_settings = \WPAICG\aipkit_dashboard::get_module_settings();
            if (empty($module_settings['autogpt'])) {
                return new WP_Error(
                    'aipkit_automations_disabled',
                    __('The Automations module is disabled.', 'gpt3-ai-content-generator'),
                    ['status' => 403]
                );
            }
        }

        $submitted_secret = trim((string) $request->get_header(self::HEADER_NAME));
        if ($submitted_secret === '') {
            return new WP_Error(
                'aipkit_server_cron_secret_missing',
                __('The server cron secret is missing.', 'gpt3-ai-content-generator'),
                ['status' => 401]
            );
        }

        $settings = self::get_settings();
        $submitted_hash = self::hash_secret($submitted_secret);
        if (!hash_equals((string) $settings['secret_hash'], $submitted_hash)) {
            return new WP_Error(
                'aipkit_server_cron_secret_invalid',
                __('The server cron secret is invalid.', 'gpt3-ai-content-generator'),
                ['status' => 401]
            );
        }

        return true;
    }

    /**
     * Records an authenticated endpoint invocation and its bounded runner result.
     *
     * @param array<string, mixed> $result Runner result.
     */
    public static function record_run(array $result): void
    {
        $settings = self::get_settings();
        $settings['last_run_at'] = time();
        $settings['last_result'] = [
            'status' => sanitize_key((string) ($result['status'] ?? 'unknown')),
            'busy' => !empty($result['busy']),
            'triggered_tasks' => absint($result['triggered_tasks'] ?? 0),
            'failed_tasks' => absint($result['failed_tasks'] ?? 0),
            'processed_items' => absint($result['processed_items'] ?? 0),
            'failed_items' => absint($result['failed_items'] ?? 0),
            'recovered_items' => absint($result['recovered_items'] ?? 0),
            'has_remaining_items' => !empty($result['has_remaining_items']),
            'duration_ms' => absint($result['duration_ms'] ?? 0),
        ];
        $settings['updated_at'] = time();

        if (empty($result['busy']) && ($result['status'] ?? '') === 'success') {
            $settings['last_success_at'] = time();
        }

        update_option(self::OPTION_NAME, $settings, false);
    }

    /**
     * Returns sanitized status suitable for admin UI and AJAX responses.
     *
     * @return array<string, mixed>
     */
    public static function get_status(): array
    {
        $settings = self::get_settings();
        $enabled = self::is_enabled();
        $last_run_at = absint($settings['last_run_at'] ?? 0);
        $last_success_at = absint($settings['last_success_at'] ?? 0);
        $healthy = $enabled && $last_success_at > 0 && $last_success_at >= (time() - self::HEALTH_WINDOW);

        return [
            'enabled' => $enabled,
            'healthy' => $healthy,
            'awaiting_first_run' => $enabled && $last_success_at === 0,
            'delayed' => $enabled && $last_success_at > 0 && !$healthy,
            'secret_hint' => sanitize_text_field((string) ($settings['secret_hint'] ?? '')),
            'last_run_at' => $last_run_at,
            'last_success_at' => $last_success_at,
            'last_result' => is_array($settings['last_result'] ?? null) ? $settings['last_result'] : [],
            'endpoint' => esc_url_raw(rest_url('aipkit/v1/automations/run')),
        ];
    }

    /**
     * Generates and stores a new secret hash, returning the raw secret once.
     *
     * @return array<string, mixed>|WP_Error
     */
    private static function store_new_secret()
    {
        try {
            $secret = 'apcr_' . bin2hex(random_bytes(32));
        } catch (\Throwable $throwable) {
            return new WP_Error(
                'aipkit_server_cron_secret_generation_failed',
                __('AI Puffer could not generate a secure server cron secret.', 'gpt3-ai-content-generator')
            );
        }

        $settings = self::get_settings();
        $settings['enabled'] = true;
        $settings['secret_hash'] = self::hash_secret($secret);
        $settings['secret_hint'] = substr($secret, -6);
        $settings['created_at'] = absint($settings['created_at'] ?? 0) ?: time();
        $settings['updated_at'] = time();
        $settings['last_run_at'] = 0;
        $settings['last_success_at'] = 0;
        $settings['last_result'] = [];
        update_option(self::OPTION_NAME, $settings, false);

        return array_merge(
            self::get_status(),
            [
                'command' => self::build_command($secret),
                'crontab_command' => '* * * * * ' . self::build_command($secret),
                'secret_shown_once' => true,
            ]
        );
    }

    /**
     * Returns normalized settings without exposing the raw secret.
     *
     * @return array<string, mixed>
     */
    private static function get_settings(): array
    {
        $settings = get_option(self::OPTION_NAME, []);
        return is_array($settings) ? $settings : [];
    }

    /**
     * Hashes a high-entropy secret using the site's authentication salt.
     */
    private static function hash_secret(string $secret): string
    {
        return hash_hmac('sha256', $secret, wp_salt('auth'));
    }

    /**
     * Builds a broadly compatible cPanel/Plesk/Linux cron command.
     */
    private static function build_command(string $secret): string
    {
        $header = self::HEADER_NAME . ': ' . $secret;
        return sprintf(
            'curl -fsS --max-time 300 -X POST -H %s %s >/dev/null 2>&1',
            escapeshellarg($header),
            escapeshellarg(rest_url('aipkit/v1/automations/run'))
        );
    }
}
