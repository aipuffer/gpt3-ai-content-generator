<?php
/** Diagnostics for an explicit, consented connection attempt. Never collects request bodies or credentials. */
namespace WPAICG\Cloud;

if (!defined('ABSPATH')) { exit; }

final class ConnectionDiagnostics
{
    public const OPTION = 'aipkit_cloud_connection_diagnostics';
    public const CONSENT = Connection::CONSENT_VERSION;
    private static $report = null;
    private static $started = 0.0;
    private const CODES = ['api_blocked', 'sdk_unavailable', 'installation_missing', 'http_request_failed', 'http_request_not_executed', 'http_request_rejected', 'connect_timeout', 'invalid_json', 'installation_inactive', 'site_mismatch', 'account_unavailable', 'identity_verification_unavailable', 'connection_unavailable', 'consent_required', 'cloud_storage_unavailable', 'cloud_storage_unreadable'];

    /** The observer exists only while the administrator's Connect action runs. */
    public static function run(callable $work, string $source): string
    {
        self::$started = microtime(true);
        self::$report = [
            'reference' => wp_generate_uuid4(), 'consentVersion' => self::CONSENT,
            'source' => $source === 'onboarding' ? 'onboarding' : 'settings',
            'stage' => 'registration', 'code' => 'connection_unavailable', 'outcome' => 'failed',
            'durationMs' => 0, 'pluginVersion' => WPAICG_VERSION,
            'wpVersion' => (string) get_bloginfo('version'), 'phpVersion' => PHP_VERSION,
            'observations' => [],
        ];
        add_action('http_api_debug', [self::class, 'observe'], 10, 5);
        $status = 'unavailable';
        try { $status = $work(); }
        catch (\Throwable $error) { self::error($error->getMessage()); }
        finally {
            remove_action('http_api_debug', [self::class, 'observe'], 10);
            self::$report['durationMs'] = min(180000, max(0, (int) round((microtime(true) - self::$started) * 1000)));
            self::$report['outcome'] = $status === 'connected' ? 'connected' : (in_array($status, ['confirm_email', 'registration_wait', 'invalid_email'], true) ? 'pending' : 'failed');
            if (self::$report['code'] === 'connection_unavailable' || self::$report['outcome'] !== 'failed') { self::$report['code'] = $status; }
            // A small, non-autoloaded local history remains available if reporting is blocked.
            // Recording diagnostics must never change the connection outcome.
            try {
                $saved = get_option(self::OPTION, []);
                $saved = is_array($saved) ? array_filter($saved, static function ($row) { return is_array($row) && ($row['recordedAt'] ?? 0) > time() - 30 * DAY_IN_SECONDS; }) : [];
                $saved[] = self::$report + ['recordedAt' => time()];
                update_option(self::OPTION, array_slice(array_values($saved), -10), false);
            } catch (\Throwable $error) { /* The connection result has priority over its diagnostic record. */ }
        }
        return $status;
    }

    public static function stage(string $stage): void
    {
        if (self::$report !== null) { self::$report['stage'] = $stage; }
    }

    public static function error(string $code): void
    {
        if ($code === 'cloud_unavailable' && self::$report !== null) {
            if (in_array(self::$report['code'], ['http_request_failed', 'http_request_not_executed', 'http_request_rejected', 'connect_timeout'], true)) { return; }
            $code = 'connection_unavailable';
        }
        if (self::$report !== null) { self::$report['code'] = in_array($code, self::CODES, true) ? $code : 'unexpected_error'; }
    }

    /** Submitted installation context is unverified and used only for troubleshooting a consented attempt. */
    public static function context(string $site, string $install): void
    {
        $parts = wp_parse_url($site);
        if (self::$report === null || !preg_match('/^[1-9][0-9]{0,18}$/D', $install) || strlen($site) > 2048 || !is_array($parts)
            || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) { return; }
        self::$report['context'] = ['siteUrl' => $site, 'installId' => $install];
    }

    public static function sdk_result($result): void
    {
        if (is_wp_error($result)) { self::error((string) $result->get_error_code()); }
        elseif (is_object($result) && is_object($result->error ?? null)) {
            self::error(is_string($result->error->code ?? null) ? $result->error->code : 'unexpected_error');
        }
    }

    /** WordPress provides the already completed response; no additional HTTP request is made. */
    public static function observe($response, $context, $transport, $args, $url): void
    {
        if (self::$report === null || $context !== 'response') { return; }
        $host = wp_parse_url($url, PHP_URL_HOST);
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $service = in_array($host, ['wp.freemius.com', 'api.freemius.com'], true) ? 'account_service' : ($host === 'puffercloud.dev' && $path === '/api/connect' ? 'cloud' : '');
        if ($service === '') { return; }
        $row = ['stage' => self::$report['stage'], 'service' => $service];
        if (is_wp_error($response)) {
            $code = (string) $response->get_error_code();
            $row['errorCode'] = in_array($code, self::CODES, true) ? $code : 'transport_error';
            // Extract only a numeric transport code or a fixed classification. Never retain the raw message.
            $message = $response->get_error_message();
            if (preg_match('/cURL error ([1-9][0-9]?):/i', $message, $match)) {
                $row['curlCode'] = (int) $match[1];
                if ($row['curlCode'] === 28) { $row['transportCause'] = 'timeout'; }
                elseif (in_array($row['curlCode'], [5, 6], true)) { $row['transportCause'] = 'dns'; }
                elseif (in_array($row['curlCode'], [35, 51, 58, 59, 60, 77, 80, 83, 90, 91], true)) { $row['transportCause'] = 'tls'; }
                elseif ($row['curlCode'] === 7) { $row['transportCause'] = 'connection'; }
            } elseif (preg_match('/timed out|timeout/i', $message)) { $row['transportCause'] = 'timeout'; }
            elseif (preg_match('/could not resolve|name resolution|name or service not known/i', $message)) { $row['transportCause'] = 'dns'; }
            elseif (preg_match('/SSL|TLS|certificate/i', $message)) { $row['transportCause'] = 'tls'; }
            elseif (preg_match('/failed to connect|connection refused|network unreachable/i', $message)) { $row['transportCause'] = 'connection'; }
            self::error($code);
        } else {
            $row['httpStatus'] = (int) wp_remote_retrieve_response_code($response);
            if ($service === 'account_service') {
                $row['markerPresent'] = wp_remote_retrieve_header($response, 'x-api-server') !== '';
                $row['playgroundProxy'] = wp_remote_retrieve_header($response, 'x-playground-cors-proxy') !== '';
            } else {
                $body = wp_remote_retrieve_body($response);
                $decoded = strlen($body) <= 4096 ? json_decode($body, true) : null;
                if (is_array($decoded) && is_string($decoded['code'] ?? null)) { self::error($decoded['code']); }
            }
        }
        self::$report['observations'][] = $row;
        self::$report['observations'] = array_slice(self::$report['observations'], -6);
    }

    public static function report(): ?array { return self::$report; }

    public static function playground_failure(): bool
    {
        foreach (self::$report['observations'] ?? [] as $row) {
            if (!empty($row['playgroundProxy']) && isset($row['markerPresent']) && !$row['markerPresent']) { return true; }
        }
        return false;
    }

    public static function with_reference(string $message): string
    {
        if (self::$report === null || self::$report['outcome'] !== 'failed') { return $message; }
        /* translators: %s: support reference identifying this connection attempt. */
        return $message . ' ' . sprintf(__('Support reference: %s', 'gpt3-ai-content-generator'), self::$report['reference']);
    }
}
