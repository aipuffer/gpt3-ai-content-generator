<?php

namespace WPAICG\Core;

use WP_Error;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Builds request signatures for Universal Event Webhooks.
 */
class AIPKit_Event_Signature
{
    /**
     * Returns an HMAC signature header value or an empty string when no secret is configured.
     *
     * @param string $timestamp
     * @param string $body
     * @param string $secret
     * @return string
     */
    public static function build(string $timestamp, string $body, string $secret): string
    {
        $normalized_secret = trim($secret);
        if ($normalized_secret === '') {
            return '';
        }

        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $normalized_secret);
        return 'sha256=' . $signature;
    }
}

/**
 * Settings access layer for the Universal Event Webhooks foundation.
 */
class AIPKit_Event_Webhooks_Settings
{
    private const EVENT_FIELD_KEY_SEPARATOR = '__dot__';
    private const LEGACY_EVENT_NAME_MAP = [
        'chatbot.feedback_submitted' => 'chatbot.fb_submitted',
    ];

    /**
     * Returns the default settings structure.
     *
     * @return array<string, mixed>
     */
    public static function get_defaults(): array
    {
        return [
            'enabled' => '0',
            'signing_secret' => '',
            'endpoints' => [],
        ];
    }

    /**
     * Ensures the event webhook settings exist in aipkit_options.
     *
     * @return void
     */
    public static function init(): void
    {
        $options = get_option('aipkit_options', []);
        if (!is_array($options)) {
            $options = [];
        }

        $defaults = self::get_defaults();
        $existing = isset($options['event_webhooks']) && is_array($options['event_webhooks'])
            ? $options['event_webhooks']
            : [];

        $merged = self::merge_settings($defaults, $existing);
        if ($merged['enabled'] === '1' && trim((string) $merged['signing_secret']) === '') {
            $merged['signing_secret'] = self::generate_signing_secret();
        }
        if ($merged !== $existing) {
            $options['event_webhooks'] = $merged;
            update_option('aipkit_options', $options, 'no');
        }
    }

    /**
     * Returns normalized event webhook settings.
     *
     * @return array<string, mixed>
     */
    public static function get_settings(): array
    {
        self::init();

        $options = get_option('aipkit_options', []);
        if (!is_array($options)) {
            return self::get_defaults();
        }

        $settings = isset($options['event_webhooks']) && is_array($options['event_webhooks'])
            ? $options['event_webhooks']
            : [];

        return self::merge_settings(self::get_defaults(), $settings);
    }

    /**
     * Returns active endpoints subscribed to the given event.
     *
     * @param string $event_name
     * @return array<int, array<string, mixed>>
     */
    public static function get_active_endpoints_for_event(string $event_name): array
    {
        $settings = self::get_settings();
        if (($settings['enabled'] ?? '0') !== '1') {
            return [];
        }

        $endpoints = isset($settings['endpoints']) && is_array($settings['endpoints'])
            ? $settings['endpoints']
            : [];

        $matched = [];
        foreach ($endpoints as $endpoint) {
            if (!is_array($endpoint)) {
                continue;
            }

            $is_enabled = isset($endpoint['enabled']) && (string) $endpoint['enabled'] === '1';
            $url = isset($endpoint['url']) ? esc_url_raw((string) $endpoint['url']) : '';
            $events = isset($endpoint['events']) && is_array($endpoint['events']) ? $endpoint['events'] : [];

            if (!$is_enabled || $url === '') {
                continue;
            }

            $normalized_events = array_values(array_filter(array_map(
                static function ($value): string {
                    return self::normalize_event_name((string) $value);
                },
                $events
            )));

            if (in_array($event_name, $normalized_events, true)) {
                $matched[] = [
                    'id' => sanitize_key((string) ($endpoint['id'] ?? '')),
                    'name' => sanitize_text_field((string) ($endpoint['name'] ?? '')),
                    'url' => $url,
                    'events' => $normalized_events,
                ];
            }
        }

        return $matched;
    }

    /**
     * Returns the HTML-safe event field key for a canonical event name.
     *
     * @param string $event_name
     * @return string
     */
    public static function encode_event_field_key(string $event_name): string
    {
        $normalized_event_name = strtolower(trim($event_name));
        return sanitize_key(str_replace('.', self::EVENT_FIELD_KEY_SEPARATOR, $normalized_event_name));
    }

    /**
     * Returns the mapping used by endpoint event checkboxes.
     *
     * @return array<string, string>
     */
    public static function get_event_field_key_map(): array
    {
        $map = [];
        foreach (array_keys(AIPKit_Event_Registry::get_definitions()) as $event_name) {
            $map[self::encode_event_field_key($event_name)] = $event_name;
        }

        return $map;
    }

    /**
     * Sanitizes raw event webhook settings input.
     *
     * @param mixed $raw_settings
     * @return array<string, mixed>
     */
    public static function sanitize_settings_input($raw_settings): array
    {
        if (!is_array($raw_settings)) {
            return self::get_defaults();
        }

        $settings = self::get_defaults();
        $settings['enabled'] = isset($raw_settings['enabled']) && (string) $raw_settings['enabled'] === '1' ? '1' : '0';

        $event_field_key_map = self::get_event_field_key_map();
        $raw_endpoints = isset($raw_settings['endpoints']) && is_array($raw_settings['endpoints'])
            ? $raw_settings['endpoints']
            : [];

        foreach ($raw_endpoints as $endpoint) {
            $normalized_endpoint = self::sanitize_endpoint($endpoint, $event_field_key_map);
            if ($normalized_endpoint !== null) {
                $settings['endpoints'][] = $normalized_endpoint;
            }
        }

        return $settings;
    }

    /**
     * Saves sanitized event webhook settings into aipkit_options.
     *
     * @param mixed $raw_settings
     * @return array<string, mixed>
     */
    public static function save_settings($raw_settings): array
    {
        $sanitized = self::sanitize_settings_input($raw_settings);
        $existing = self::get_settings();

        // The signing secret is server-owned. Generic settings autosave may
        // update endpoint configuration and the enabled flag, but it must never
        // accept an arbitrary replacement secret from form data.
        $sanitized['signing_secret'] = sanitize_text_field((string) ($existing['signing_secret'] ?? ''));
        if ($sanitized['enabled'] === '1' && $sanitized['signing_secret'] === '') {
            $sanitized['signing_secret'] = self::generate_signing_secret();
        }

        self::persist_settings($sanitized);

        return $sanitized;
    }

    /**
     * Enables or disables outbound webhooks, generating the secret on first use.
     *
     * @return array<string, mixed>
     */
    public static function set_enabled(bool $enabled): array
    {
        $settings = self::get_settings();
        $settings['enabled'] = $enabled ? '1' : '0';
        if ($enabled && trim((string) ($settings['signing_secret'] ?? '')) === '') {
            $settings['signing_secret'] = self::generate_signing_secret();
        }

        self::persist_settings($settings);

        return $settings;
    }

    /**
     * Replaces the webhook signing secret with a cryptographically random value.
     *
     * @return array<string, mixed>
     */
    public static function regenerate_signing_secret(): array
    {
        $settings = self::get_settings();
        $settings['signing_secret'] = self::generate_signing_secret();
        self::persist_settings($settings);

        return $settings;
    }

    /**
     * Generates a server-owned webhook signing secret.
     */
    public static function generate_signing_secret(): string
    {
        try {
            return 'whsec_' . bin2hex(random_bytes(32));
        } catch (\Exception $exception) {
            return 'whsec_' . wp_generate_password(64, false, false);
        }
    }

    /**
     * Persists an already-normalized settings structure.
     *
     * @param array<string, mixed> $settings
     */
    private static function persist_settings(array $settings): void
    {
        $settings = self::merge_settings(self::get_defaults(), $settings);

        $options = get_option('aipkit_options', []);
        if (!is_array($options)) {
            $options = [];
        }

        $options['event_webhooks'] = $settings;
        update_option('aipkit_options', $options, 'no');
    }

    /**
     * Merges settings arrays while preserving normalized endpoint lists.
     *
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    private static function merge_settings(array $defaults, array $existing): array
    {
        $merged = array_merge($defaults, $existing);
        $merged['enabled'] = isset($merged['enabled']) && (string) $merged['enabled'] === '1' ? '1' : '0';
        $merged['signing_secret'] = sanitize_text_field((string) ($merged['signing_secret'] ?? ''));
        $merged['endpoints'] = isset($merged['endpoints']) && is_array($merged['endpoints'])
            ? array_values(array_filter(array_map(
                static function ($endpoint): ?array {
                    if (!is_array($endpoint)) {
                        return null;
                    }

                    return [
                        'id' => sanitize_key((string) ($endpoint['id'] ?? '')),
                        'name' => sanitize_text_field((string) ($endpoint['name'] ?? '')),
                        'enabled' => isset($endpoint['enabled']) && (string) $endpoint['enabled'] === '1' ? '1' : '0',
                        'url' => esc_url_raw((string) ($endpoint['url'] ?? '')),
                        'events' => isset($endpoint['events']) && is_array($endpoint['events'])
                            ? array_values(array_unique(array_filter(array_map(
                                static function ($event_name): string {
                                    $normalized_event_name = self::normalize_event_name((string) $event_name);
                                    if ($normalized_event_name === '' || !AIPKit_Event_Registry::has_event($normalized_event_name)) {
                                        return '';
                                    }

                                    return $normalized_event_name;
                                },
                                $endpoint['events']
                            ))))
                            : [],
                    ];
                },
                $merged['endpoints']
            )))
            : [];

        return $merged;
    }

    /**
     * Sanitizes one endpoint config row.
     *
     * @param mixed $endpoint
     * @param array<string, string> $event_field_key_map
     * @return array<string, mixed>|null
     */
    private static function sanitize_endpoint($endpoint, array $event_field_key_map): ?array
    {
        if (!is_array($endpoint)) {
            return null;
        }

        $name = sanitize_text_field((string) ($endpoint['name'] ?? ''));
        $url = esc_url_raw((string) ($endpoint['url'] ?? ''));
        $enabled = isset($endpoint['enabled']) && (string) $endpoint['enabled'] === '1' ? '1' : '0';
        $events = [];

        $raw_events = isset($endpoint['events']) && is_array($endpoint['events']) ? $endpoint['events'] : [];
        foreach ($event_field_key_map as $field_key => $event_name) {
            if (isset($raw_events[$field_key]) && (string) $raw_events[$field_key] === '1' && AIPKit_Event_Registry::has_event($event_name)) {
                $events[] = $event_name;
            }
        }

        $has_meaningful_data = $name !== '' || $url !== '' || $enabled === '1' || !empty($events);
        if (!$has_meaningful_data) {
            return null;
        }

        $endpoint_id = sanitize_key((string) ($endpoint['id'] ?? ''));
        if ($endpoint_id === '') {
            $endpoint_id = 'endpoint_' . sanitize_key(wp_generate_uuid4());
        }

        return [
            'id' => $endpoint_id,
            'name' => $name,
            'enabled' => $enabled,
            'url' => $url,
            'events' => array_values(array_unique($events)),
        ];
    }

    /**
     * Normalizes canonical and legacy event names to the current supported key.
     *
     * @param string $event_name
     * @return string
     */
    private static function normalize_event_name(string $event_name): string
    {
        $normalized_event_name = sanitize_text_field(trim($event_name));
        if ($normalized_event_name === '') {
            return '';
        }

        return self::LEGACY_EVENT_NAME_MAP[$normalized_event_name] ?? $normalized_event_name;
    }
}

/**
 * Module-facing façade for the Universal Event Webhooks foundation.
 */
class AIPKit_Event_Webhooks
{
    /**
     * Emits a registered event.
     *
     * @param string $event_name
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     * @return array<string, mixed>|WP_Error
     */
    public static function emit(string $event_name, array $payload = [], array $context = [])
    {
        return AIPKit_Event_Dispatcher::emit($event_name, $payload, $context);
    }

    /**
     * Returns registered event definitions.
     *
     * @return array<string, array<string, string>>
     */
    public static function get_registered_events(): array
    {
        return AIPKit_Event_Registry::get_definitions();
    }

    /**
     * Returns normalized settings for the future delivery layer/UI.
     *
     * @return array<string, mixed>
     */
    public static function get_settings(): array
    {
        return AIPKit_Event_Webhooks_Settings::get_settings();
    }
}
