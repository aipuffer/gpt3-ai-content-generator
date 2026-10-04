<?php


namespace WPAICG;

use WPAICG\AIPKit_Role_Manager;
use WPAICG\Vector\AIPKit_Vector_Store_Registry;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('\\WPAICG\\aipkit_dashboard')) {
    class aipkit_dashboard
    {
        /**
         * Default module settings (true=enabled, false=disabled).
         * ADDED: ai_forms default.
         * MODIFIED: Changed visibility from private to public.
         */
        public static $default_module_settings = array( // MODIFIED: private to public
            'chat_bot'        => true,
            'content_writer'  => true,
            'autogpt'         => true,
            'ai_forms'        => true,
            'sources'         => true,
            'image_generator' => false,
            'training'        => true,
            'stats_viewer'    => true,
        );

        private static $module_settings = array();

        public static function is_pro_plan()
        {
            if (function_exists('wpaicg_gacg_fs')) {
                $fs = wpaicg_gacg_fs();
                if (!$fs->can_use_premium_code()) {
                    return false;
                }
                foreach (array('pro', 'proplus', 'promax') as $plan) {
                    if ($fs->is_plan($plan, true)) {
                        return true;
                    }
                }
            }
            return false;
        }

        public static function init()
        {
            if (is_admin()) {
                add_filter('admin_title', [__CLASS__, 'filter_admin_title'], 20, 1);
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is not applicable for page routing checks on this hook.
            $current_page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

            if (
                (is_admin() && !empty($current_page) && (strpos($current_page, 'wpaicg') !== false || $current_page === 'aipkit-role-manager')) ||
                wp_doing_ajax()
            ) {
                self::check_and_init_module_settings();
                self::register_ajax_handlers();
            }
        }

        /**
         * Adds plugin name to browser tab title on AIPKit admin pages.
         *
         * @param string $admin_title Full admin browser title.
         * @return string
         */
        public static function filter_admin_title($admin_title)
        {
            if (!self::is_aipkit_admin_page()) {
                return $admin_title;
            }

            $plugin_name = __('AI Puffer', 'gpt3-ai-content-generator');
            if ($plugin_name === '' || strpos($admin_title, $plugin_name) !== false) {
                return $admin_title;
            }

            return $plugin_name . ' - ' . $admin_title;
        }

        /**
         * Detects whether the current request is for an AIPKit admin screen.
         *
         * @return bool
         */
        private static function is_aipkit_admin_page()
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page context.
            $current_page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
            return !empty($current_page) && (strpos($current_page, 'wpaicg') !== false || $current_page === 'aipkit-role-manager');
        }

        public static function register_ajax_handlers()
        {
            add_action('wp_ajax_aipkit_dashboard_load_module', [__CLASS__, 'ajax_load_module']);
            add_action('wp_ajax_aipkit_update_module_setting', [__CLASS__, 'ajax_update_module_setting']);
            add_action('wp_ajax_aipkit_get_token_usage_chart_data', [__CLASS__, 'ajax_get_token_usage_chart_data']);
        }

        private static function check_and_init_module_settings()
        {
            // --- FIX: Safely retrieve options ---
            $opts = get_option('aipkit_options');
            if (!is_array($opts)) {
                $opts = [];
            }
            // --- END FIX ---

            if (!isset($opts['module_settings']) || !is_array($opts['module_settings'])) {
                $opts['module_settings'] = self::$default_module_settings;
                self::$module_settings = self::$default_module_settings;
                update_option('aipkit_options', $opts, 'no');
            } else {
                $merged = array_merge(self::$default_module_settings, $opts['module_settings']);
                $final_settings = array_intersect_key($merged, self::$default_module_settings);
                self::$module_settings = $final_settings;
                if ($final_settings !== $opts['module_settings']) {
                    $opts['module_settings'] = $final_settings;
                    update_option('aipkit_options', $opts, 'no');
                }
            }
        }

        public static function get_module_settings()
        {
            if (empty(self::$module_settings)) {
                self::check_and_init_module_settings();
            }
            return self::$module_settings;
        }

        public static function ajax_load_module()
        {
            if (!isset($_REQUEST['_ajax_nonce']) || !wp_verify_nonce(sanitize_key($_REQUEST['_ajax_nonce']), 'aipkit_nonce')) {
                wp_send_json_error(['message' => __('Security check failed.', 'gpt3-ai-content-generator')], 403);
                return;
            }

            $module = isset($_REQUEST['module']) ? sanitize_key($_REQUEST['module']) : '';
            if (empty($module)) {
                wp_send_json_error(['message' => 'No module specified.'], 400);
                return;
            }
            if (!preg_match('/^[a-z0-9-]+$/', $module)) {
                wp_send_json_error(['message' => 'Invalid module name.'], 400);
                return;
            }

            if (!AIPKit_Role_Manager::user_can_access_module($module)) {
                wp_send_json_error(['message' => __('You do not have permission to access this module.', 'gpt3-ai-content-generator')], 403);
                return;
            }

            $module_views = [
                'ai-forms' => 'ai-forms/page.php',
                'content-writer' => 'content-writer/page.php',
                'autogpt' => 'automations/page.php',
                'settings' => 'settings/page.php',
                'sources' => 'knowledge-base/page.php',
                'chatbot' => 'chatbot/page.php',
                'image-generator' => 'image-generator/page.php',
                'stats' => 'usage/page.php',
                'role-manager' => 'settings/roles.php',
            ];
            if (!isset($module_views[$module])) {
                wp_send_json_error(['message' => 'Invalid module path.'], 400);
                return;
            }
            $module_path = $module_views[$module];
            $module_file = WPAICG_PLUGIN_DIR . 'admin/views/' . $module_path;

            $modules_base_path = realpath(WPAICG_PLUGIN_DIR . 'admin/views/' . dirname($module_path));
            $real_module_file_path = realpath($module_file);

            if ($modules_base_path === false || $real_module_file_path === false || strpos($real_module_file_path, $modules_base_path . DIRECTORY_SEPARATOR) !== 0) {
                wp_send_json_error(['message' => 'Invalid module path. Attempted path: ' . esc_html($module_file)], 400);
                return;
            }

            if (!file_exists($module_file)) {
                wp_send_json_error(['message' => "Module file not found: {$module} at " . esc_html($module_file)], 404);
                return;
            }

            $content = '';
            $php_error = null;
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Used to gracefully catch fatal errors in included module view files.
            set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$php_error) {
                $php_error = new WP_Error('php_error_in_module_view', $errstr, [
                    'file' => basename($errfile),
                    'line' => $errline
                ]);
                return true;
            });

            ob_start();
            try {
                // Sanitize and define variables needed by specific modules here,
                // making them available to the included $module_file.
                // This avoids using $_REQUEST directly in view files and satisfies security scans.
                $force_active_bot_id = isset($_REQUEST['force_active_bot_id']) ? intval($_REQUEST['force_active_bot_id']) : 0;
                $force_active_tab = isset($_REQUEST['force_active_tab']) ? sanitize_key($_REQUEST['force_active_tab']) : '';

                include $module_file;
                $content = ob_get_clean();
            } catch (\Throwable $e) {
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                $php_error = new WP_Error('fatal_error_in_module_view', $e->getMessage(), [
                     'file' => basename($e->getFile()), 'line' => $e->getLine()
                ]);
            }
            restore_error_handler();

            if ($php_error !== null) {
                $error_details = $php_error->get_error_message();
                if (is_array($php_error->get_error_data())) {
                    $error_details .= " (File: " . ($php_error->get_error_data()['file'] ?? 'unknown') . ", Line: " . ($php_error->get_error_data()['line'] ?? 'unknown') . ")";
                }
                wp_send_json_error([
                    'message' => 'A server error occurred while loading the module content. Please check the PHP error log for details.',
                    'debug_error' => $php_error->get_error_code() . ': ' . $php_error->get_error_message()
                ], 500);
                return;
            }
            if (headers_sent($file, $line)) {
                die();
            }


            $response_data = ['html' => $content];
            if (class_exists(AIPKit_Providers::class)) {
                $response_data['providerStatus'] = AIPKit_Providers::get_provider_status_map();
            }
            if ($module === 'chatbot' && class_exists(AIPKit_Vector_Store_Registry::class)) {
                $openai_vector_stores = AIPKit_Vector_Store_Registry::get_registered_stores_by_provider('OpenAI');
                $response_data['openaiVectorStores'] = $openai_vector_stores;
            }

            wp_send_json_success($response_data);
        }

        public static function ajax_update_module_setting()
        {
            if (!AIPKit_Role_Manager::user_can_manage_settings()) {
                wp_send_json_error(['message' => 'Unauthorized access.'], 403);
                return;
            }
            if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce(sanitize_key($_POST['_ajax_nonce']), 'aipkit_nonce')) {
                wp_send_json_error(['message' => 'Security check failed.'], 403);
                return;
            }

            $moduleKey = isset($_POST['moduleKey']) ? sanitize_key($_POST['moduleKey']) : '';
            $enabled   = isset($_POST['enabled']) ? sanitize_text_field(wp_unslash($_POST['enabled'])) : '';

            self::check_and_init_module_settings();

            if (empty($moduleKey) || !array_key_exists($moduleKey, self::$default_module_settings)) {
                wp_send_json_error(['message' => 'Invalid module key.'], 400);
                return;
            }

            $isEnabled = ($enabled === '1');
            self::$module_settings[$moduleKey] = $isEnabled;

            // --- FIX: Safely retrieve options before updating ---
            $opts = get_option('aipkit_options');
            if (!is_array($opts)) {
                $opts = [];
            }
            // --- END FIX ---

            $opts['module_settings'] = self::$module_settings;
            update_option('aipkit_options', $opts, 'no');

            if ($moduleKey === 'stats_viewer' && !$isEnabled) {
                update_option('aipkit_visitor_billing_enabled', 'no', false);
            }

            wp_send_json_success(['message' => 'Module setting updated.']);
        }

        public static function ajax_get_token_usage_chart_data()
        {
            if (!AIPKit_Role_Manager::user_can_access_module('stats')) {
                wp_send_json_error(['message' => __('Unauthorized access.', 'gpt3-ai-content-generator')], 403);
                return;
            }
            if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce(sanitize_key($_POST['_ajax_nonce']), 'aipkit_nonce')) {
                wp_send_json_error(['message' => __('Security check failed.', 'gpt3-ai-content-generator')], 403);
                return;
            }

            // Optional period parameter (allowed: 3, 7, 14, 30, 90); default 3 to keep memory low
            $allowed_days = [3, 7, 14, 30, 90];
            $days = isset($_POST['days']) ? absint($_POST['days']) : 3;
            if (!in_array($days, $allowed_days, true)) {
                $days = 3;
            }
            $stats_class_name = '\\WPAICG\\Stats\\AIPKit_Stats';
            if (!class_exists($stats_class_name)) {
                wp_send_json_error(['message' => 'Statistics component unavailable.'], 500);
                return;
            }

            $stats_calculator = new $stats_class_name();
            $daily_data = $stats_calculator->get_daily_token_stats($days);

            if (is_wp_error($daily_data)) {
                if ($daily_data->get_error_code() === 'stats_volume_too_large') {
                    $data = $daily_data->get_error_data();
                    $rows = isset($data['rows']) ? (int) $data['rows'] : 0;
                    $bytes = isset($data['bytes']) ? (int) $data['bytes'] : 0;
                    $notice = sprintf(
                        /* translators: 1: rows, 2: size */
                        __('Usage data for the selected period is very large (rows: %1$s, size: %2$s). Showing no chart data. Consider reducing the period, pruning logs, or disabling conversation storage.', 'gpt3-ai-content-generator'),
                        number_format_i18n($rows),
                        size_format($bytes)
                    );
                    wp_send_json_success([
                        'daily_token_data' => new \stdClass(),
                        'notice' => $notice,
                        'volume' => ['rows' => $rows, 'bytes' => $bytes],
                        'days' => $days,
                        'manage_logs_url' => admin_url('admin.php?page=wpaicg#stats')
                    ]);
                } else {
                    wp_send_json_error(['message' => $daily_data->get_error_message()], 500);
                }
            } else {
                wp_send_json_success(['daily_token_data' => $daily_data]);
            }
        }
    }

    aipkit_dashboard::init();
}
