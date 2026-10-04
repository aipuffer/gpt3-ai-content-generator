<?php

// Handles AI Puffer role-based access permissions.

namespace WPAICG;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * AIPKit_Role_Manager
 *
 * Handles getting roles, modules, saving/getting role-based permissions, checking access,
 * and updating permissions on activation.
 */
class AIPKit_Role_Manager
{
    public const OPTION_NAME = 'aipkit_role_permissions';
    public const CAP_MANAGE_ROLE_MANAGER = 'aipkit_manage_role_manager';
    public const CAP_MANAGE_SETTINGS = 'aipkit_manage_settings';
    public const CAP_MANAGE_OTHERS_CONTENT = 'aipkit_manage_others_content';
    private const SCHEMA_OPTION_NAME = 'aipkit_role_permissions_schema_version';
    private const SCHEMA_VERSION = 2;
    private const LEGACY_SETTINGS_CAPABILITY = 'wpaicg_settings';
    private const ASSISTANT_UTILITY_MODULES = [
        'bulk_assistant',
        'row_assistant',
        'classic_editor_assistant',
        'block_editor_assistant',
    ];
    private static $permission_cache = [];

    /**
     * Register AJAX actions for saving role permissions.
     */
    public static function init()
    {
        add_action('wp_ajax_aipkit_save_role_permissions', [__CLASS__, 'ajax_save_role_permissions']);
    }

    /**
     * Get grouped permission metadata for Role Manager.
     */
    public static function get_permission_groups(): array
    {
        return [
            'core' => [
                'label' => __('Core Modules', 'gpt3-ai-content-generator'),
                'modules' => [
                    'chatbot' => [
                        'label' => __('Chatbots', 'gpt3-ai-content-generator'),
                    ],
                    'content-writer' => [
                        'label' => __('Content Writer', 'gpt3-ai-content-generator'),
                    ],
                    'autogpt' => [
                        'label' => __('Automations', 'gpt3-ai-content-generator'),
                    ],
                    'ai-forms' => [
                        'label' => __('AI Forms', 'gpt3-ai-content-generator'),
                    ],
                    'image-generator' => [
                        'label' => __('Images', 'gpt3-ai-content-generator'),
                    ],
                    'sources' => [
                        'label' => __('Knowledge Base', 'gpt3-ai-content-generator'),
                    ],
                    'stats' => [
                        'label' => __('Usage', 'gpt3-ai-content-generator'),
                    ],
                ],
            ],
            'utilities' => [
                'label' => __('WordPress Utilities', 'gpt3-ai-content-generator'),
                'modules' => [
                    'bulk_assistant' => [
                        'label' => __('Content Assistant', 'gpt3-ai-content-generator'),
                    ],
                    'row_assistant' => [
                        'label' => __('Row Assistant Menu', 'gpt3-ai-content-generator'),
                    ],
                    'vector_content_indexer' => [
                        'label' => __('Content Indexing', 'gpt3-ai-content-generator'),
                    ],
                    'classic_editor_assistant' => [
                        'label' => __('Classic Editor Assistant', 'gpt3-ai-content-generator'),
                    ],
                    'block_editor_assistant' => [
                        'label' => __('Block Editor Assistant', 'gpt3-ai-content-generator'),
                    ],
                ],
            ],
            'administration' => [
                'label' => __('Administration', 'gpt3-ai-content-generator'),
                'modules' => [
                    'settings' => [
                        'label' => __('Settings', 'gpt3-ai-content-generator'),
                    ],
                ],
            ],
        ];
    }

    /**
     * Get the list of modules that require permission management.
     * @return array ['module_slug' => 'Module Name', ...]
     */
    public static function get_manageable_modules(): array
    {
        $modules = [];
        foreach (self::get_permission_groups() as $group) {
            foreach ($group['modules'] as $module_slug => $module) {
                $modules[$module_slug] = $module['label'];
            }
        }
        return $modules;
    }

    public static function get_assistant_utility_modules(): array
    {
        return self::ASSISTANT_UTILITY_MODULES;
    }

    public static function get_dashboard_access_modules(): array
    {
        return array_keys(self::get_manageable_modules());
    }

    public static function get_plugin_admin_capabilities(): array
    {
        return [
            self::CAP_MANAGE_ROLE_MANAGER,
            self::CAP_MANAGE_SETTINGS,
            self::CAP_MANAGE_OTHERS_CONTENT,
        ];
    }

    public static function ensure_administrator_capabilities(): void
    {
        $administrator_role = get_role('administrator');
        if (!$administrator_role) {
            return;
        }

        foreach (self::get_plugin_admin_capabilities() as $capability) {
            if (!$administrator_role->has_cap($capability)) {
                $administrator_role->add_cap($capability);
            }
        }
    }

    public static function user_can_manage_role_manager(): bool
    {
        $can_manage = current_user_can(self::CAP_MANAGE_ROLE_MANAGER);
        return (bool) apply_filters('aipkit_user_can_manage_role_manager', $can_manage, get_current_user_id());
    }

    public static function user_can_manage_settings(): bool
    {
        $can_manage = current_user_can(self::CAP_MANAGE_SETTINGS) || current_user_can(self::LEGACY_SETTINGS_CAPABILITY);
        return (bool) apply_filters('aipkit_user_can_manage_settings', $can_manage, get_current_user_id());
    }

    public static function user_can_manage_others_content(): bool
    {
        $can_manage = current_user_can(self::CAP_MANAGE_OTHERS_CONTENT);
        return (bool) apply_filters('aipkit_user_can_manage_others_content', $can_manage, get_current_user_id());
    }

    public static function user_can_view_admin_notices(): bool
    {
        return self::user_can_manage_settings();
    }

    /**
     * Get all editable WordPress roles.
     * @return array ['role_slug' => ['name' => 'Role Name', ...], ...]
     */
    public static function get_editable_roles(): array
    {
        global $wp_roles;
        if (!isset($wp_roles)) {
            $wp_roles = new \WP_Roles();
        }
        return $wp_roles->get_names();
    }

    /**
     * Get the default permissions (admin only for all modules).
     * @return array ['module_slug' => ['administrator']]
     */
    private static function get_default_permissions(): array
    {
        $defaults = [];
        $modules = self::get_manageable_modules();
        foreach (array_keys($modules) as $module_slug) {
            $defaults[$module_slug] = ['administrator'];
        }
        return $defaults;
    }

    /**
     * Get the currently saved role permissions.
     * @return array ['module_slug' => ['role1', 'role2'], ...]
     */
    public static function get_role_permissions(): array
    {
        $permissions = get_option(self::OPTION_NAME);
        $normalized = self::normalize_permissions($permissions);
        if ($normalized['changed']) {
            update_option(self::OPTION_NAME, $normalized['permissions'], 'no');
            update_option(self::SCHEMA_OPTION_NAME, self::SCHEMA_VERSION, 'no');
            self::$permission_cache = [];
        }
        return $normalized['permissions'];
    }

    /**
     * Called on plugin activation or update.
     * Ensures all current manageable modules exist in the permissions option, adding defaults if missing.
     * Migrates old wpaicg_ capabilities to the new module-based permission system.
     */
    public static function update_permissions_on_activation()
    {
        self::ensure_administrator_capabilities();

        $current_permissions = get_option(self::OPTION_NAME);
        $normalized = self::normalize_permissions($current_permissions);
        if ($normalized['changed']) {
            update_option(self::OPTION_NAME, $normalized['permissions'], 'no');
            update_option(self::SCHEMA_OPTION_NAME, self::SCHEMA_VERSION, 'no');
            self::$permission_cache = []; // Clear cache
        }
    }

    private static function normalize_permissions($permissions): array
    {
        $modules = self::get_manageable_modules();
        $defaults = self::get_default_permissions();
        $updated_permissions = is_array($permissions) ? $permissions : [];
        $changed = !is_array($permissions);
        $schema_version = (int) get_option(self::SCHEMA_OPTION_NAME, 0);
        $needs_schema_migration = $schema_version < self::SCHEMA_VERSION;

        if ($needs_schema_migration) {
            if (isset($updated_permissions['logs']) && !isset($updated_permissions['stats'])) {
                $updated_permissions['stats'] = $updated_permissions['logs'];
            }

            if (isset($updated_permissions['ai_post_enhancer']) && is_array($updated_permissions['ai_post_enhancer'])) {
                foreach (self::ASSISTANT_UTILITY_MODULES as $utility_module_slug) {
                    if (!isset($updated_permissions[$utility_module_slug])) {
                        $updated_permissions[$utility_module_slug] = $updated_permissions['ai_post_enhancer'];
                    }
                }
            }

            if (isset($updated_permissions['settings'])) {
                $updated_permissions['settings'] = ['administrator'];
            }

            $changed = true;
        }

        foreach (array_keys($modules) as $module_slug) {
            if (!isset($updated_permissions[$module_slug]) || !is_array($updated_permissions[$module_slug])) {
                $updated_permissions[$module_slug] = $defaults[$module_slug];
                $changed = true;
            }

            if (!in_array('administrator', $updated_permissions[$module_slug], true)) {
                $updated_permissions[$module_slug][] = 'administrator';
                $changed = true;
            }

            $updated_permissions[$module_slug] = array_values(array_unique(array_filter(array_map('sanitize_key', $updated_permissions[$module_slug]))));
        }

        foreach (array_keys($updated_permissions) as $saved_module_slug) {
            if (!isset($modules[$saved_module_slug])) {
                unset($updated_permissions[$saved_module_slug]);
                $changed = true;
            }
        }

        return [
            'permissions' => $updated_permissions,
            'changed' => $changed,
        ];
    }


    /**
     * AJAX handler to save role permissions.
     */
    public static function ajax_save_role_permissions()
    {
        if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce(sanitize_key($_POST['_ajax_nonce']), 'aipkit_role_manager_nonce')) {
            wp_send_json_error(['message' => __('Security check failed.', 'gpt3-ai-content-generator')], 403);
            return;
        }
        if (!self::user_can_manage_role_manager()) {
            wp_send_json_error(['message' => __('You do not have permission to manage roles.', 'gpt3-ai-content-generator')], 403);
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The array is sanitized inside the loop.
        $permissions_input = isset($_POST['permissions']) ? wp_unslash($_POST['permissions']) : [];
        $sanitized_permissions = [];
        $valid_modules = array_keys(self::get_manageable_modules());
        $valid_roles = array_keys(self::get_editable_roles());

        if (!is_array($permissions_input)) {
            wp_send_json_error(['message' => __('Invalid input format.', 'gpt3-ai-content-generator')], 400);
            return;
        }

        foreach ($permissions_input as $module_slug => $allowed_roles) {
            $module_slug = sanitize_key($module_slug);
            if (!in_array($module_slug, $valid_modules)) {
                continue;
            }

            $sanitized_roles = [];
            if (is_array($allowed_roles)) {
                foreach ($allowed_roles as $role_slug => $is_allowed) {
                    $role_slug = sanitize_key($role_slug);
                    if (in_array($role_slug, $valid_roles) && $is_allowed) {
                        $sanitized_roles[] = $role_slug;
                    }
                }
            }
            if (!in_array('administrator', $sanitized_roles)) {
                $sanitized_roles[] = 'administrator';
            }
            $sanitized_permissions[$module_slug] = array_unique($sanitized_roles);
        }

        foreach ($valid_modules as $module_slug) {
            if (!isset($sanitized_permissions[$module_slug])) {
                $sanitized_permissions[$module_slug] = ['administrator'];
            }
        }

        $updated = update_option(self::OPTION_NAME, $sanitized_permissions, 'no');
        update_option(self::SCHEMA_OPTION_NAME, self::SCHEMA_VERSION, 'no');
        self::$permission_cache = [];

        if ($updated) {
            wp_send_json_success(['message' => __('Role permissions saved successfully.', 'gpt3-ai-content-generator')]);
        } else {
            wp_send_json_success(['message' => __('Permissions are up to date.', 'gpt3-ai-content-generator')]);
        }
    }

    /**
     * Checks if the current user has permission to access a specific module.
     * @param string $module_slug The slug of the module.
     * @return bool True if the user has access, false otherwise.
     */
    public static function user_can_access_module(string $module_slug): bool
    {
        if (!function_exists('wp_get_current_user') || !function_exists('current_user_can')) {
            return false;
        }

        // Normalize module slug to match saved permissions keys.
        // Some callers use underscores (e.g., 'image_generator') while
        // the Role Manager stores slugs with hyphens (e.g., 'image-generator').
        $all_permissions = self::get_role_permissions();
        $normalized_slug = $module_slug;
        if (!isset($all_permissions[$normalized_slug])) {
            $alt_hyphen = str_replace('_', '-', $module_slug);
            if (isset($all_permissions[$alt_hyphen])) {
                $normalized_slug = $alt_hyphen;
            } else {
                $alt_underscore = str_replace('-', '_', $module_slug);
                if (isset($all_permissions[$alt_underscore])) {
                    $normalized_slug = $alt_underscore;
                }
            }
        }

        $user_id = get_current_user_id();
        $cache_key = $user_id . '_' . $normalized_slug;
        if (isset(self::$permission_cache[$cache_key])) {
            return self::$permission_cache[$cache_key];
        }

        $user = wp_get_current_user();
        if (!$user || !$user->ID) {
            self::$permission_cache[$cache_key] = false;
            return false;
        }
        $user_roles = (array) $user->roles;

        // Use normalized slug to fetch allowed roles
        $allowed_roles = isset($all_permissions[$normalized_slug]) && is_array($all_permissions[$normalized_slug])
                         ? $all_permissions[$normalized_slug]
                         : ['administrator'];

        $has_access = count(array_intersect($user_roles, $allowed_roles)) > 0;
        self::$permission_cache[$cache_key] = $has_access;

        return $has_access;
    }

    public static function user_can_access_any_module(array $module_slugs): bool
    {
        foreach ($module_slugs as $module_slug) {
            if (self::user_can_access_module((string) $module_slug)) {
                return true;
            }
        }
        return false;
    }

    public static function user_can_access_dashboard_shell(): bool
    {
        if (!function_exists('wp_get_current_user') || !function_exists('current_user_can')) {
            return false;
        }

        return self::user_can_access_any_module(self::get_dashboard_access_modules());
    }

}
