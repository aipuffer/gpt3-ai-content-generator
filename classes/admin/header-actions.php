<?php
// Purpose: Shared utility to inject action buttons (e.g., Assistant, Index) next to the page title on list screens.

namespace WPAICG\Utils;

if (!defined('ABSPATH')) { exit; }

/**
 * Class AIPKit_Admin_Header_Action_Buttons
 * Provides a single injection point so multiple modules can register header buttons
 * without duplicating MutationObserver / placement logic.
 */
class AIPKit_Admin_Header_Action_Buttons {

    /** @var array<string, array{label:string, id:string, capability?:string, class?:string, post_types?:array<int,string>, access_callback?:callable|null, label_callback?:callable|null}> */
    private static $registered = [];
    private static $hook_added = false;

    /**
     * Register a button for header injection.
     */
    public static function register_button(string $id, string $label, array $args = []): void {
        if (isset(self::$registered[$id])) return;
        $defaults = [
            'id' => $id,
            'label' => $label,
            'capability' => null,
            'class' => 'page-title-action',
            'post_types' => [],
            'access_callback' => null,
            'label_callback' => null,
        ];
        self::$registered[$id] = array_merge($defaults, $args);
        if (!self::$hook_added) {
            add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
            self::$hook_added = true;
        }
    }

    /**
     * Enqueue assets + localize data for list screens.
     */
    public static function enqueue(): void {
        if (empty(self::$registered)) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->base !== 'edit') return; // Only list screens

        $current_post_type = isset($screen->post_type) ? (string) $screen->post_type : '';

        // Filter by capability and post type
        $buttons = array_filter(self::$registered, function($btn) use ($current_post_type) {
            if (!empty($btn['capability']) && !current_user_can($btn['capability'])) {
                return false;
            }
            if (!empty($btn['post_types']) && is_array($btn['post_types'])) {
                if (!in_array($current_post_type, $btn['post_types'], true)) {
                    return false;
                }
            }
            if (!empty($btn['access_callback']) && is_callable($btn['access_callback'])) {
                return (bool) call_user_func($btn['access_callback'], $current_post_type, $btn);
            }
            return true;
        });
        if (empty($buttons)) return;

        $export = [];
        foreach ($buttons as $btn) {
            $label = (string) $btn['label'];
            if (!empty($btn['label_callback']) && is_callable($btn['label_callback'])) {
                $callback_label = call_user_func($btn['label_callback']);
                if (is_string($callback_label) && $callback_label !== '') {
                    $label = $callback_label;
                }
            }

            $export[] = [
                'id' => $btn['id'],
                'label' => $label,
                'class' => $btn['class'] ?? 'page-title-action'
            ];
        }

        $version = self::file_ver('dist/css/admin-header-action-buttons.bundle.css', 'dist/js/admin-header-action-buttons.bundle.js');
        $handle = 'aipkit-header-buttons';

        // Enqueue built (dist) assets so raw source files need not be shipped.
        wp_enqueue_style(
            $handle,
            WPAICG_PLUGIN_URL . 'dist/css/admin-header-action-buttons.bundle.css',
            [],
            $version
        );

        wp_enqueue_script(
            $handle,
            WPAICG_PLUGIN_URL . 'dist/js/admin-header-action-buttons.bundle.js',
            [],
            $version,
            true
        );

        // Provide data
        wp_add_inline_script(
            $handle,
            'window.aipkitHeaderButtons = ' . wp_json_encode($export) . ';',
            'before'
        );
    }

    private static function file_ver(string $css_rel, string $js_rel): string {
        $base = defined('WPAICG_PLUGIN_DIR') ? WPAICG_PLUGIN_DIR : plugin_dir_path(__FILE__) . '../../';
        $css_ts = @filemtime($base . ltrim($css_rel, '/')) ?: 0;
        $js_ts  = @filemtime($base . ltrim($js_rel, '/')) ?: 0;
        $best = max($css_ts, $js_ts);
        if ($best > 0) return (string)$best;
        return defined('WPAICG_VERSION') ? WPAICG_VERSION : '1.0.0';
    }
}
