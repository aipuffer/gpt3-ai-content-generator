<?php

namespace WPAICG\Core\Moderation;

use WPAICG\AIPKit_Providers;
use WPAICG\Core\Providers\ProviderStrategyFactory;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stores global security settings shared across modules.
 */
class AIPKit_Global_Security_Settings
{
    private const OPTIONS_KEY = 'aipkit_options';
    private const SETTINGS_KEY = 'security';

    /**
     * Returns the default global security settings.
     *
     * @return array<string, mixed>
     */
    public static function get_defaults(): array
    {
        return [
            'enable_ip_anonymization' => '0',
            'openai_moderation_enabled' => '0',
            'openai_moderation_message' => self::get_default_openai_moderation_message(),
            'blocklists' => [
                'banned_words' => '',
                'banned_words_message' => '',
                'banned_ips' => '',
                'banned_ips_message' => '',
            ],
        ];
    }

    /**
     * Returns normalized global security settings.
     *
     * @return array<string, mixed>
     */
    public static function get_settings(): array
    {
        $options = get_option(self::OPTIONS_KEY, []);
        if (!is_array($options)) {
            $options = [];
        }

        $raw_settings = isset($options[self::SETTINGS_KEY]) && is_array($options[self::SETTINGS_KEY])
            ? $options[self::SETTINGS_KEY]
            : [];

        return self::sanitize_settings_input($raw_settings);
    }

    /**
     * Returns whether IP anonymization is enabled globally.
     */
    public static function is_ip_anonymization_enabled(): bool
    {
        $settings = self::get_settings();

        return isset($settings['enable_ip_anonymization']) && (string) $settings['enable_ip_anonymization'] === '1';
    }

    /**
     * Returns the normalized global OpenAI moderation settings.
     *
     * @return array{enabled: string, message: string}
     */
    public static function get_openai_moderation_settings(): array
    {
        $settings = self::get_settings();

        return [
            'enabled' => isset($settings['openai_moderation_enabled']) && (string) $settings['openai_moderation_enabled'] === '1'
                ? '1'
                : '0',
            'message' => isset($settings['openai_moderation_message'])
                ? (string) $settings['openai_moderation_message']
                : self::get_default_openai_moderation_message(),
        ];
    }

    /**
     * Resolves a guest session identifier without persisting raw IP addresses when anonymization is enabled.
     */
    public static function resolve_guest_session_id(?string $session_id, ?string $client_ip): ?string
    {
        $normalized_session_id = is_string($session_id) ? sanitize_text_field($session_id) : '';
        if ($normalized_session_id !== '') {
            return $normalized_session_id;
        }

        $normalized_ip = is_string($client_ip) ? sanitize_text_field($client_ip) : '';
        if ($normalized_ip === '') {
            return null;
        }

        if (!self::is_ip_anonymization_enabled()) {
            return $normalized_ip;
        }

        return 'anon-' . substr(hash_hmac('sha256', $normalized_ip, wp_salt('auth')), 0, 32);
    }

    /**
     * Saves normalized global security settings into aipkit_options.
     *
     * @param mixed $raw_settings
     * @return array<string, mixed>
     */
    public static function save_settings($raw_settings): array
    {
        $sanitized_settings = self::sanitize_settings_input($raw_settings);

        $options = get_option(self::OPTIONS_KEY, []);
        if (!is_array($options)) {
            $options = [];
        }

        $options[self::SETTINGS_KEY] = $sanitized_settings;
        update_option(self::OPTIONS_KEY, $options, 'no');

        return $sanitized_settings;
    }

    /**
     * Returns global banned IP and banned word settings.
     *
     * @param string $module Ignored. Global blocklists apply to every supported module.
     * @return array<string, array<string, string>>
     */
    public static function get_blocklists_for_module(string $module): array
    {
        $settings = self::get_settings();
        $blocklists = isset($settings['blocklists']) && is_array($settings['blocklists'])
            ? $settings['blocklists']
            : [];

        return [
            'banned_ips_settings' => [
                'ips' => (string) ($blocklists['banned_ips'] ?? ''),
                'message' => (string) ($blocklists['banned_ips_message'] ?? ''),
            ],
            'banned_words_settings' => [
                'words' => (string) ($blocklists['banned_words'] ?? ''),
                'message' => (string) ($blocklists['banned_words_message'] ?? ''),
            ],
        ];
    }

    /**
     * Returns whether an exact IP address is present in the global blocklist.
     */
    public static function is_ip_blocked(string $ip_address): bool
    {
        $ip_address = self::normalize_ip($ip_address);
        if ($ip_address === '') {
            return false;
        }

        $settings = self::get_settings();
        $blocked_ips = isset($settings['blocklists']['banned_ips'])
            ? array_map('trim', explode(',', (string) $settings['blocklists']['banned_ips']))
            : [];

        return in_array($ip_address, array_filter($blocked_ips), true);
    }

    /** Whether a log address is shortened, including ambiguous legacy records. */
    public static function is_logged_ip_anonymized(array $log_row): bool
    {
        if (isset($log_row['ip_anonymized'])) {
            return (bool) $log_row['ip_anonymized'];
        }
        // Older logs have no privacy marker. Never offer an exact-visitor block
        // for an address that could be a shortened network address.
        $ip = self::normalize_ip((string) ($log_row['ip_address'] ?? ''));
        if ($ip === '') {
            return false;
        }
        $bytes = inet_pton($ip);
        return strlen($bytes) === 4 ? substr($bytes, -1) === "\0" : substr($bytes, -2) === "\0\0";
    }

    /**
     * Adds or removes an exact IP address from the global blocklist.
     */
    public static function set_ip_blocked(string $ip_address, bool $blocked): bool
    {
        $ip_address = self::normalize_ip($ip_address);
        if ($ip_address === '') {
            return false;
        }

        $settings = self::get_settings();
        $blocked_ips = isset($settings['blocklists']['banned_ips'])
            ? array_values(array_filter(array_map('trim', explode(',', (string) $settings['blocklists']['banned_ips']))))
            : [];

        if ($blocked) {
            if (!in_array($ip_address, $blocked_ips, true)) {
                $blocked_ips[] = $ip_address;
            }
        } else {
            $blocked_ips = array_values(array_filter($blocked_ips, static function ($candidate) use ($ip_address): bool {
                return $candidate !== $ip_address;
            }));
        }

        $settings['blocklists']['banned_ips'] = implode(',', array_unique($blocked_ips));
        self::save_settings($settings);

        return self::is_ip_blocked($ip_address) === $blocked;
    }

    /**
     * Sanitizes raw global security settings.
     *
     * @param mixed $raw_settings
     * @return array<string, mixed>
     */
    public static function sanitize_settings_input($raw_settings): array
    {
        $defaults = self::get_defaults();
        $sanitized = $defaults;

        if (!is_array($raw_settings)) {
            return $sanitized;
        }

        $raw_blocklists = isset($raw_settings['blocklists']) && is_array($raw_settings['blocklists'])
            ? $raw_settings['blocklists']
            : $raw_settings;

        $sanitized['enable_ip_anonymization'] =
            isset($raw_settings['enable_ip_anonymization']) && (string) $raw_settings['enable_ip_anonymization'] === '1'
                ? '1'
                : '0';
        $sanitized['openai_moderation_enabled'] =
            isset($raw_settings['openai_moderation_enabled']) && (string) $raw_settings['openai_moderation_enabled'] === '1'
                ? '1'
                : '0';
        $sanitized['openai_moderation_message'] = isset($raw_settings['openai_moderation_message'])
            ? sanitize_text_field((string) $raw_settings['openai_moderation_message'])
            : self::get_default_openai_moderation_message();

        $sanitized['blocklists']['banned_words'] = self::sanitize_banned_words(
            isset($raw_blocklists['banned_words']) ? (string) $raw_blocklists['banned_words'] : ''
        );
        $sanitized['blocklists']['banned_words_message'] = isset($raw_blocklists['banned_words_message'])
            ? sanitize_text_field((string) $raw_blocklists['banned_words_message'])
            : '';
        $sanitized['blocklists']['banned_ips'] = self::sanitize_banned_ips(
            isset($raw_blocklists['banned_ips']) ? (string) $raw_blocklists['banned_ips'] : ''
        );
        $sanitized['blocklists']['banned_ips_message'] = isset($raw_blocklists['banned_ips_message'])
            ? sanitize_text_field((string) $raw_blocklists['banned_ips_message'])
            : '';

        return $sanitized;
    }

    /**
     * Sanitizes a comma-separated banned words string.
     */
    private static function sanitize_banned_words(string $raw_words): string
    {
        $banned_words = array_map(
            'trim',
            explode(',', strtolower(sanitize_textarea_field($raw_words)))
        );

        return implode(',', array_filter($banned_words, static function ($word): bool {
            return $word !== '';
        }));
    }

    /**
     * Sanitizes a comma-separated banned IP string.
     */
    private static function sanitize_banned_ips(string $raw_ips): string
    {
        $candidate_ips = array_map(
            'trim',
            explode(',', sanitize_textarea_field($raw_ips))
        );

        $valid_ips = array_filter(array_map([self::class, 'normalize_ip'], $candidate_ips));

        return implode(',', array_unique($valid_ips));
    }

    /** Returns a canonical address, or an empty string for invalid input. */
    public static function normalize_ip(string $ip): string
    {
        $ip = trim($ip);
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? inet_ntop(inet_pton($ip)) : '';
    }

    /**
     * Returns the default message shown when OpenAI moderation blocks a request.
     */
    private static function get_default_openai_moderation_message(): string
    {
        return __('Your message was flagged by the moderation system and could not be sent.', 'gpt3-ai-content-generator');
    }
}

/**
 * AIPKit_BannedIP_Checker
 *
 * Checks if a client's IP address is in the list of banned IPs.
 */
class AIPKit_BannedIP_Checker {

    /**
     * Checks if the client's IP is banned.
     *
     * @param string|null $client_ip The IP address of the user.
     * @param array $banned_ips_settings Associative array with 'ips' (string) and 'message' (string).
     * @return WP_Error|null WP_Error if banned, null otherwise.
     */
    public static function check(?string $client_ip, array $banned_ips_settings): ?WP_Error {
        $client_ip = AIPKit_Global_Security_Settings::normalize_ip($client_ip ?? '');
        if ($client_ip !== '' && !empty($banned_ips_settings['ips'])) {
            $banned_ips_list = array_map([AIPKit_Global_Security_Settings::class, 'normalize_ip'], explode(',', $banned_ips_settings['ips']));
            if (in_array($client_ip, $banned_ips_list, true)) {
                $banned_ip_message = $banned_ips_settings['message'] ?: __('Access from your IP address has been blocked.', 'gpt3-ai-content-generator');
                return new WP_Error('ip_banned', $banned_ip_message, ['status' => 403]); // Forbidden
            }
        }
        return null;
    }
}

/**
 * AIPKit_BannedWords_Checker
 *
 * Checks if the provided text contains any banned words.
 */
class AIPKit_BannedWords_Checker {

    /**
     * Checks text for banned words.
     *
     * @param string $text The text content to check.
     * @param array $banned_words_settings Associative array with 'words' (string) and 'message' (string).
     * @return WP_Error|null WP_Error if a banned word is found, null otherwise.
     */
    public static function check(string $text, array $banned_words_settings): ?WP_Error {
        if (!empty($banned_words_settings['words'])) {
            $banned_words_list = array_map('trim', explode(',', strtolower($banned_words_settings['words'])));
            // We no longer need to lowercase the text here, as the regex will be case-insensitive.

            foreach ($banned_words_list as $banned_word) {
                if (empty($banned_word)) {
                    continue;
                }
                if (preg_match('/(*UCP)\b' . preg_quote($banned_word, '/') . '\b/iu', $text)) {
                    $banned_word_message = $banned_words_settings['message'] ?: __('Sorry, your message could not be sent as it contains prohibited words.', 'gpt3-ai-content-generator');
                    return new WP_Error('word_banned', $banned_word_message, ['status' => 400]); // Bad Request
                }
            }
        }
        return null;
    }
}

/**
 * AIPKit_OpenAI_Moderation_Checker
 *
 * Checks if OpenAI Moderation should be performed and, if so, calls the OpenAI provider.
 */
class AIPKit_OpenAI_Moderation_Checker {
    /**
     * Checks if text should be moderated by OpenAI and performs the check if applicable.
     *
     * @param string $text The text content to check.
     * @param array $bot_settings Current request settings/context (used for provider selection and moderation execution).
     * @return WP_Error|null WP_Error if flagged by OpenAI, null otherwise or if not applicable.
     */
    public static function check(string $text, array $bot_settings): ?WP_Error {
        $global_moderation_settings = class_exists(AIPKit_Global_Security_Settings::class)
            ? AIPKit_Global_Security_Settings::get_openai_moderation_settings()
            : [
                'enabled' => '0',
                'message' => self::get_default_flagged_message(),
            ];
        $moderation_settings = $bot_settings;
        $moderation_settings['openai_moderation_enabled'] = $global_moderation_settings['enabled'] ?? '0';
        $moderation_settings['openai_moderation_message'] = $global_moderation_settings['message']
            ?? self::get_default_flagged_message();

        $moderation_enabled = $moderation_settings['openai_moderation_enabled'] ?? '0';
        if (!($moderation_enabled === '1' || $moderation_enabled === 1 || $moderation_enabled === true)) {
            return null;
        }

        $provider_from_bot = $moderation_settings['provider'] ?? null;
        $global_default_provider = null;
        if (class_exists(AIPKit_Providers::class)) {
            $global_default_provider = AIPKit_Providers::get_current_provider();
        }
        $current_provider = $provider_from_bot ?: $global_default_provider;
        if ($current_provider !== 'OpenAI') {
            return null;
        }

        if (!class_exists(ProviderStrategyFactory::class) || !class_exists(AIPKit_Providers::class)) {
            return null;
        }

        $strategy = ProviderStrategyFactory::get_strategy('OpenAI');
        if (is_wp_error($strategy) || !method_exists($strategy, 'moderate_text')) {
            return null;
        }

        $api_params = AIPKit_Providers::get_provider_data('OpenAI');
        if (empty($api_params['api_key'])) {
            return null;
        }
        if (AIPKit_Providers::normalize_openai_api_mode($api_params['api_mode'] ?? null) === 'chat_completions') {
            return null;
        }

        $moderation_result = $strategy->moderate_text($text, $api_params);
        if (is_wp_error($moderation_result) || $moderation_result !== true) {
            return null;
        }

        $message = trim((string) ($moderation_settings['openai_moderation_message'] ?? ''));
        if ($message === '') {
            $message = self::get_default_flagged_message();
        }

        return new WP_Error('content_flagged_by_openai', $message, ['status' => 400]);
    }

    private static function get_default_flagged_message(): string {
        return __('Your message was flagged by the moderation system and could not be sent.', 'gpt3-ai-content-generator');
    }
}

namespace WPAICG\Core;

use WPAICG\Core\Moderation\AIPKit_BannedIP_Checker;
use WPAICG\Core\Moderation\AIPKit_BannedWords_Checker;
use WPAICG\Core\Moderation\AIPKit_Global_Security_Settings;
use WPAICG\Core\Moderation\AIPKit_OpenAI_Moderation_Checker;
use WP_Error;

/**
 * AIPKit_Content_Moderator (Facade)
 *
 * Centralized class for handling content moderation checks.
 * Delegates specific checks to specialized checker classes.
 */
class AIPKit_Content_Moderator {
    /**
     * Checks the provided text and context against configured moderation rules.
     *
     * @param string $text The text content to check (e.g., user message).
     * @param array $context Associative array containing context information.
     *                      Expected keys:
     *                      - 'client_ip': (string) The IP address of the user making the request.
     *                      - 'module': (string) The current module slug (e.g. chat, image_generator, ai_forms).
     *                      - 'bot_settings': (array) Current request settings/context (provider and module-specific options).
     * @return WP_Error|null Returns a WP_Error if the content is flagged (with user-facing message),
     *                       or null if the content passes all checks or moderation is not applicable/failed internally.
     */
    public static function check_content(string $text, array $context = []): ?WP_Error {
        $client_ip = $context['client_ip'] ?? null;
        $module = isset($context['module']) ? sanitize_key((string) $context['module']) : 'chat';
        $bot_settings = $context['bot_settings'] ?? [];
        $global_blocklists = class_exists(AIPKit_Global_Security_Settings::class)
            ? AIPKit_Global_Security_Settings::get_blocklists_for_module($module)
            : [
                'banned_ips_settings' => ['ips' => '', 'message' => ''],
                'banned_words_settings' => ['words' => '', 'message' => ''],
            ];

        $banned_ips_settings = isset($global_blocklists['banned_ips_settings']) && is_array($global_blocklists['banned_ips_settings'])
            ? $global_blocklists['banned_ips_settings']
            : ['ips' => '', 'message' => ''];
        $ip_check_result = AIPKit_BannedIP_Checker::check($client_ip, $banned_ips_settings);
        if (is_wp_error($ip_check_result)) {
            return $ip_check_result;
        }

        // IP blocking also applies to requests that contain only an image or
        // another non-text input. Skip the text-specific checks after the IP
        // check so empty input never triggers an external moderation request.
        if (trim($text) === '') {
            return null;
        }

        $banned_words_settings = isset($global_blocklists['banned_words_settings']) && is_array($global_blocklists['banned_words_settings'])
            ? $global_blocklists['banned_words_settings']
            : ['words' => '', 'message' => ''];
        $words_check_result = AIPKit_BannedWords_Checker::check($text, $banned_words_settings);
        if (is_wp_error($words_check_result)) {
            return $words_check_result;
        }

        // OpenAI Moderation API check.
        $openai_mod_check_result = AIPKit_OpenAI_Moderation_Checker::check($text, $bot_settings);
        if (is_wp_error($openai_mod_check_result)) {
            return $openai_mod_check_result;
        }

        // All checks passed
        return null;
    }
}
