<?php


namespace WPAICG\Dashboard;

use WPAICG\aipkit_dashboard;
use WPAICG\AIPKit_Role_Manager;
use WPAICG\Chat\Admin\Ajax\UserCreditsAjaxHandler;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Initializes the AIPKit Dashboard.
 * - Registers the admin menu page.
 * - Includes necessary dashboard component files for backend logic.
 * - Registers hooks for dashboard-specific AJAX actions and cron jobs.
 */
class Initializer
{
    public static function init($version)
    {
        static $instance = null;
        if ($instance === null) {
            $instance = new self($version);
        }
        return $instance;
    }

    private function __construct($version)
    {
        // --- Load Core Provider Strategies FIRST (needed by Settings AJAX) ---
        // Note: These are loaded by the main plugin loader.

        // --- Load Dashboard Component Logic ---
        require_once __DIR__ . '/dashboard.php';
        require_once WPAICG_PLUGIN_DIR . 'classes/settings/ai.php';
        require_once WPAICG_PLUGIN_DIR . 'classes/ai/settings.php';
        require_once WPAICG_PLUGIN_DIR . 'classes/usage/statistics.php';
        require_once WPAICG_PLUGIN_DIR . 'classes/security/roles.php';

        // --- Load AJAX Handlers for Dashboard Actions ---
        // BaseDashboardAjaxHandler is loaded by Base_Ajax_Handlers_Loader
        // SettingsAjaxHandler and ModelsAjaxHandler are now loaded by Base_Ajax_Handlers_Loader

        $user_credits_handler_path = WPAICG_PLUGIN_DIR . 'classes/usage/credit-actions.php';
        if (file_exists($user_credits_handler_path)) {
            require_once $user_credits_handler_path;
        }

        $this->register_hooks();
    }

    private function register_hooks()
    {
        add_action('wp_ajax_aipkit_set_visitor_billing', ['\\WPAICG\\Dashboard\\Ajax\\SettingsAjaxHandler', 'ajax_set_visitor_billing']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_menu', [$this, 'position_content_writer_shortcut_menu'], 999);
        add_filter('screen_options_show_screen', [$this, 'hide_screen_options_on_aipkit_screens'], PHP_INT_MAX, 2);

        if (class_exists('\\WPAICG\\Core\\TokenManager\\AIPKit_Token_Manager')) {
            $token_manager = new \WPAICG\Core\TokenManager\AIPKit_Token_Manager();
            if (!has_action(\WPAICG\Core\TokenManager\Constants\CronHookConstant::CRON_HOOK, [$token_manager, 'perform_token_reset'])) {
                add_action(\WPAICG\Core\TokenManager\Constants\CronHookConstant::CRON_HOOK, [$token_manager, 'perform_token_reset']);
            }
        }

        if (class_exists('\\WPAICG\\AIPKit_Role_Manager') && method_exists('\\WPAICG\\AIPKit_Role_Manager', 'init')) {
            \WPAICG\AIPKit_Role_Manager::init();
        }
        if (class_exists('\\WPAICG\\Chat\\Admin\\Ajax\\UserCreditsAjaxHandler')) {
            $user_credits_handler = new UserCreditsAjaxHandler();
            if (!has_action('wp_ajax_aipkit_get_user_credits_data', [$user_credits_handler, 'ajax_get_user_credits_data'])) {
                add_action('wp_ajax_aipkit_get_user_credits_data', [$user_credits_handler, 'ajax_get_user_credits_data']);
            }
            if (method_exists($user_credits_handler, 'ajax_admin_update_token_balance') && !has_action('wp_ajax_aipkit_admin_update_token_balance', [$user_credits_handler, 'ajax_admin_update_token_balance'])) {
                add_action('wp_ajax_aipkit_admin_update_token_balance', [$user_credits_handler, 'ajax_admin_update_token_balance']);
            }
            if (method_exists($user_credits_handler, 'ajax_admin_reset_usage_scope') && !has_action('wp_ajax_aipkit_admin_reset_usage_scope', [$user_credits_handler, 'ajax_admin_reset_usage_scope'])) {
                add_action('wp_ajax_aipkit_admin_reset_usage_scope', [$user_credits_handler, 'ajax_admin_reset_usage_scope']);
            }
        }

        if (class_exists('\\WPAICG\\aipkit_dashboard') && method_exists('\\WPAICG\\aipkit_dashboard', 'init')) {
            \WPAICG\aipkit_dashboard::init();
        }
        if (class_exists('\\WPAICG\\AIPKIT_AI_Settings') && method_exists('\\WPAICG\\AIPKIT_AI_Settings', 'init')) {
            \WPAICG\AIPKIT_AI_Settings::init();
        }
    }

    public function hide_screen_options_on_aipkit_screens($show_screen, $screen)
    {
        $screen_id = is_object($screen) && isset($screen->id) ? (string) $screen->id : '';

        $is_aipkit_screen = $screen_id === 'toplevel_page_wpaicg'
            || strpos($screen_id, 'page_wpaicg') !== false
            || strpos($screen_id, 'aipkit-role-manager') !== false;

        return $is_aipkit_screen ? false : $show_screen;
    }

    private function get_base_menu_capability(): string
    {
        $base_capability = 'edit_posts';
        return apply_filters('aipkit_base_menu_capability', $base_capability);
    }

    private function get_content_writer_shortcut_url(): string
    {
        return admin_url('admin.php?page=wpaicg&aipkit_module=content-writer');
    }

    private function can_user_access_content_writer_shortcut(): bool
    {
        if (!current_user_can('edit_posts')) {
            return false;
        }

        if (!class_exists(aipkit_dashboard::class) || !class_exists(AIPKit_Role_Manager::class)) {
            return false;
        }

        if (!AIPKit_Role_Manager::user_can_access_module('content-writer')) {
            return false;
        }

        $module_settings = aipkit_dashboard::get_module_settings();
        return !isset($module_settings['content_writer']) || !empty($module_settings['content_writer']);
    }

    public function register_admin_menu()
    {
        if (!$this->can_user_access_dashboard() && !AIPKit_Role_Manager::user_can_manage_role_manager()) {
            return;
        }

        $base_menu_capability = $this->get_base_menu_capability();
        $menu_capability = current_user_can($base_menu_capability) ? $base_menu_capability : 'read';

        add_menu_page(__('AI Puffer', 'gpt3-ai-content-generator'), __('AI Puffer', 'gpt3-ai-content-generator'), $menu_capability, 'wpaicg', [$this, 'render_dashboard_page'], WPAICG_LOGO_URL, 6);
        wp_add_inline_style('admin-menu', '#toplevel_page_wpaicg .wp-menu-image img{width:20px;height:auto;box-sizing:content-box;}');
        add_submenu_page('wpaicg', __('Dashboard', 'gpt3-ai-content-generator'), __('Dashboard', 'gpt3-ai-content-generator'), $menu_capability, 'wpaicg', [$this, 'render_dashboard_page']);
        add_submenu_page('wpaicg', __('Role Manager', 'gpt3-ai-content-generator'), __('Role Manager', 'gpt3-ai-content-generator'), AIPKit_Role_Manager::CAP_MANAGE_ROLE_MANAGER, 'aipkit-role-manager', [$this, 'render_role_manager_page']);

        if ($this->can_user_access_content_writer_shortcut()) {
            $shortcut_hook = add_submenu_page(
                'edit.php',
                __('Generate New Post', 'gpt3-ai-content-generator'),
                __('Generate New Post', 'gpt3-ai-content-generator'),
                'edit_posts',
                'aipkit-generate-new',
                [$this, 'render_content_writer_shortcut_page']
            );

            if ($shortcut_hook) {
                add_action("load-{$shortcut_hook}", [$this, 'redirect_content_writer_shortcut_page']);
            }
        }

    }
    private function can_user_access_dashboard(): bool
    {
        if (!class_exists('\\WPAICG\\AIPKit_Role_Manager')) {
            return false;
        }
        return AIPKit_Role_Manager::user_can_access_dashboard_shell();
    }
    public function render_dashboard_page()
    {
        if (!$this->can_user_access_dashboard()) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'gpt3-ai-content-generator'), 403);
        }
        $dashboard_path = WPAICG_PLUGIN_DIR . 'admin/views/shell/page.php';
        if (file_exists($dashboard_path)) {
            include $dashboard_path;
        } else {
            echo '<div class="wrap"><h2>Error</h2><p>AI Puffer admin view file not found: ' . esc_html($dashboard_path) . '</p></div>';
        }
    }
    public function render_role_manager_page()
    {
        if (!AIPKit_Role_Manager::user_can_manage_role_manager()) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'gpt3-ai-content-generator'));
        }
        $role_manager_path = WPAICG_PLUGIN_DIR . 'admin/views/settings/roles.php';
        if (file_exists($role_manager_path)) {
            echo '<div class="wrap aipkit_wrap">';
            include $role_manager_path;
            echo '</div>';
        } else {
            echo '<div class="wrap"><h2>Error</h2><p>Role Manager view file not found: ' . esc_html($role_manager_path) . '</p></div>';
        }
    }

    public function redirect_content_writer_shortcut_page()
    {
        if (!$this->can_user_access_content_writer_shortcut()) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'gpt3-ai-content-generator'), 403);
        }

        wp_safe_redirect($this->get_content_writer_shortcut_url());
        exit;
    }

    public function render_content_writer_shortcut_page()
    {
        if (!$this->can_user_access_content_writer_shortcut()) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'gpt3-ai-content-generator'), 403);
        }

        $target_url = $this->get_content_writer_shortcut_url();

        echo '<div class="wrap">';
        echo '<p><a href="' . esc_url($target_url) . '">' . esc_html__('Open Content Writer', 'gpt3-ai-content-generator') . '</a></p>';
        echo '</div>';
    }

    public function position_content_writer_shortcut_menu()
    {
        if (!$this->can_user_access_content_writer_shortcut()) {
            return;
        }

        global $submenu;

        if (!isset($submenu['edit.php']) || !is_array($submenu['edit.php'])) {
            return;
        }

        $posts_submenu = array_values($submenu['edit.php']);
        $shortcut_slug = 'aipkit-generate-new';
        $add_new_slug = 'post-new.php';
        $shortcut_index = null;

        foreach ($posts_submenu as $index => $item) {
            if (($item[2] ?? '') === $shortcut_slug) {
                $shortcut_index = $index;
                break;
            }
        }

        if ($shortcut_index === null) {
            return;
        }

        $shortcut_item = $posts_submenu[$shortcut_index];
        array_splice($posts_submenu, $shortcut_index, 1);

        $add_new_index = null;
        foreach ($posts_submenu as $index => $item) {
            if (($item[2] ?? '') === $add_new_slug) {
                $add_new_index = $index;
                break;
            }
        }

        if ($add_new_index === null) {
            $posts_submenu[] = $shortcut_item;
            $submenu['edit.php'] = $posts_submenu;
            return;
        }

        array_splice($posts_submenu, $add_new_index + 1, 0, [$shortcut_item]);
        $submenu['edit.php'] = $posts_submenu;
    }

}
