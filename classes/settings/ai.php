<?php

namespace WPAICG;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class to handle AI Settings definitions, initialization, and retrieval.
 * AJAX saving and model sync logic has been moved to dedicated handler classes.
 */
if (!class_exists('\\WPAICG\\AIPKIT_AI_Settings')) {
    class AIPKIT_AI_Settings {

        // Default advanced parameters for AI generation.
        public static $default_ai_params = array(
            'temperature' => 1.0,
            'top_p' => 1.0,
            'frequency_penalty' => 0.0,
            'presence_penalty' => 0.0,
        );

        // Default API Keys structure.
        // Moved to public static.
        public static $default_api_keys = array(
            'public_api_key' => '',
            'public_api_enabled' => '0',
        );

        /**
         * Initializes settings checks.
         * AJAX hooks are now registered in DashboardInitializer.
         */
        public static function init() {
            // Initialize core settings
            self::check_and_init_ai_parameters();
            self::check_and_init_api_keys();
        }

        /** Ensure ai_parameters exist in the options array. */
        private static function check_and_init_ai_parameters() {
            $opts = get_option('aipkit_options', array());
            if (!isset($opts['ai_parameters']) || !is_array($opts['ai_parameters'])) {
                $opts['ai_parameters'] = self::$default_ai_params;
                update_option('aipkit_options', $opts, 'no');
            } else {
                // Ensure all default keys exist and remove obsolete ones
                $final_params = [];
                foreach (self::$default_ai_params as $key => $default_value) {
                    $final_params[$key] = $opts['ai_parameters'][$key] ?? $default_value;
                }
                if ($final_params !== $opts['ai_parameters']) {
                    $opts['ai_parameters'] = $final_params;
                    update_option('aipkit_options', $opts, 'no');
                }
            }
        }

        /** Ensure api_keys exist in the options array. */
        private static function check_and_init_api_keys() {
             $opts = get_option('aipkit_options', array());
             if (!isset($opts['api_keys']) || !is_array($opts['api_keys'])) {
                 $opts['api_keys'] = self::$default_api_keys;
                 update_option('aipkit_options', $opts, 'no');
             } else {
                 $had_explicit_enabled_state = array_key_exists('public_api_enabled', $opts['api_keys']);
                 $merged = array_merge(self::$default_api_keys, $opts['api_keys']);
                 if (!$had_explicit_enabled_state) {
                     // Preserve REST access for existing installations that already
                     // configured a key before the explicit access toggle existed.
                     $merged['public_api_enabled'] = trim((string) ($merged['public_api_key'] ?? '')) !== '' ? '1' : '0';
                 } else {
                     $merged['public_api_enabled'] = (string) $merged['public_api_enabled'] === '1' ? '1' : '0';
                 }
                 if ($merged['public_api_enabled'] === '1' && trim((string) ($merged['public_api_key'] ?? '')) === '') {
                     $merged['public_api_key'] = self::generate_public_api_key();
                 }
                 if ($merged !== $opts['api_keys']) {
                     $opts['api_keys'] = $merged;
                     update_option('aipkit_options', $opts, 'no');
                 }
             }
        }

        /**
         * Generates a server-owned REST API credential.
         */
        public static function generate_public_api_key(): string {
            try {
                return 'aipk_live_' . bin2hex(random_bytes(24));
            } catch (\Exception $exception) {
                return 'aipk_live_' . wp_generate_password(48, false, false);
            }
        }

        /** Retrieve advanced AI parameters. */
        public static function get_ai_parameters(): array {
            self::check_and_init_ai_parameters(); // Ensure initialized
            $opts = get_option('aipkit_options', array()); // Re-fetch
            return $opts['ai_parameters'] ?? self::$default_ai_params;
        }

        /** Retrieve API Keys. */
        public static function get_api_keys(): array {
             self::check_and_init_api_keys(); // Ensure initialized
             $opts = get_option('aipkit_options', array()); // Re-fetch
             return $opts['api_keys'] ?? self::$default_api_keys;
        }

    } // End class

    AIPKIT_AI_Settings::init();
}
