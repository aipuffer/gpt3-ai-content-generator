<?php

namespace WPAICG;

if (!defined('ABSPATH')) {
    exit;
}

use WPAICG\Includes\AIPKit_Dependency_Loader;
use WPAICG\Includes\AIPKit_Hook_Manager;
use WPAICG\Includes\AIPKit_Module_Initializer;
use WPAICG\Includes\AIPKit_Shared_Assets_Manager;

require_once WPAICG_PLUGIN_DIR . 'includes/dependencies.php';
require_once WPAICG_PLUGIN_DIR . 'includes/hooks.php';
require_once WPAICG_PLUGIN_DIR . 'includes/assets.php';
require_once WPAICG_PLUGIN_DIR . 'includes/lifecycle.php';
require_once WPAICG_PLUGIN_DIR . 'classes/security/roles.php';

/**
 * Plugin startup and registration. Lifecycle callbacks are supplied by the update trait.
 */
class WP_AI_Content_Generator
{
    use AIPKit_Plugin_Updates;

    private static $instance = null;
    private $version;
    private $plugin_name;
    public const DB_VERSION_OPTION = 'aipkit_plugin_version'; // Option to store current DB version
    public const TOKEN_MANAGER_SCHEMA_VERSION_OPTION = 'aipkit_token_manager_schema_version';
    public const TOKEN_MANAGER_SCHEMA_VERSION = '4';
    public const PERFORMANCE_SCHEMA_VERSION_OPTION = 'aipkit_performance_schema_version';
    public const PERFORMANCE_SCHEMA_VERSION = '3';
    public const MODEL_REGISTRY_SCHEMA_VERSION_OPTION = 'aipkit_model_registry_schema_version';
    public const MODEL_REGISTRY_SCHEMA_VERSION = '1';
    public const CONVERSATION_STATE_SCHEMA_VERSION_OPTION = 'aipkit_conversation_state_schema_version';
    public const CONVERSATION_STATE_SCHEMA_VERSION = '1';
    private const INSTALL_INTEGRITY_TRANSIENT = 'aipkit_install_integrity_checked';

    public static function get_instance(): WP_AI_Content_Generator
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->version = defined('WPAICG_VERSION') ? WPAICG_VERSION : '1.9.15';
        $this->plugin_name = 'gpt3-ai-content-generator';
    }

    /**
     * Run the plugin setup.
     * Load dependencies, define hooks, initialize modules, and ensure DB tables exist.
     */
    public function run()
    {
        // Load all dependencies using the new loader class
        AIPKit_Dependency_Loader::load();

        // Register shared assets (moved to a separate manager, called on init)
        add_action('init', [$this, 'register_shared_assets'], 0);

        // Check for plugin updates (version change)
        add_action('init', [$this, 'check_for_updates'], 10);

        // Define hooks using the new hook manager
        AIPKit_Hook_Manager::register_hooks($this->version);

        // Initialize modules using the new module initializer
        AIPKit_Module_Initializer::init($this->version);
    }

    /**
     * Register shared assets via the SharedAssetsManager.
     * Hooked to 'init' with priority 0.
     */
    public function register_shared_assets()
    {
        AIPKit_Shared_Assets_Manager::register($this->version);
    }

    public function get_plugin_name(): string
    {
        return $this->plugin_name;
    }
    public function get_version(): string
    {
        return $this->version;
    }

}

namespace WPAICG\Includes;

use WPAICG\Dashboard\Initializer as DashboardInitializer;

/**
 * AIPKit_Module_Initializer
 * Handles initializing core AIPKit modules like the Dashboard.
 */
class AIPKit_Module_Initializer {

    /**
     * Initialize core AIPKit modules.
     *
     * @param string $plugin_version The current plugin version.
     */
    public static function init(string $plugin_version) {
        if (!self::should_initialize_dashboard()) {
            return;
        }

        // Dashboard Initializer
        $dashboard_initializer_path = WPAICG_PLUGIN_DIR . 'classes/admin/bootstrap.php';
        if (file_exists($dashboard_initializer_path)) {
            if (!class_exists(DashboardInitializer::class)) {
                require_once $dashboard_initializer_path;
            }
            if (class_exists(DashboardInitializer::class)) {
                DashboardInitializer::init($plugin_version);
            }
        }
    }

    private static function should_initialize_dashboard(): bool
    {
        if (is_admin() || wp_doing_ajax()) {
            return true;
        }

        return defined('WP_CLI') && WP_CLI;
    }
}
