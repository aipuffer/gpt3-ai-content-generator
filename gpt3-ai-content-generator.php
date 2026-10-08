<?php

/**
 * The plugin bootstrap file
 *
 * @link              https://aipower.org
 * @since             1.0.0
 * @package           Wp_Ai_Content_Generator
 *
 * @wordpress-plugin
 * Plugin Name:       AI Puffer – AI Chatbot, AI Writer & Automation
 * Description:       AI chatbot that answers from your content, an AI writer that drafts and schedules posts, AI forms and images. Use AI Puffer Cloud or your own provider keys. Formerly AI Power.
 * Version:           2.4.97
 * Author:            Senol Sahin
 * Author URI:        https://aipower.org
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       gpt3-ai-content-generator
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This file only uses local helper/template variables and does not define public globals.
if ( !defined( 'WPINC' ) ) {
    die;
}
// Freemius SDK Integration
if ( function_exists( 'wpaicg_gacg_fs' ) ) {
    wpaicg_gacg_fs()->set_basename( false, __FILE__ );
} else {
    // Only the first edition loaded in this request owns the plugin runtime.
    define( 'WPAICG_VERSION', '2.4.97' );
    define( 'WPAICG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
    define( 'WPAICG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
    define( 'WPAICG_LIB_DIR', WPAICG_PLUGIN_DIR . 'lib/' );
    // Canonical brand asset; retain its public path for saved chatbot image URLs.
    define( 'WPAICG_LOGO_URL', WPAICG_PLUGIN_URL . 'public/images/icon.svg?ver=' . WPAICG_VERSION );
    // DO NOT REMOVE THIS IF — it ensures `function_exists()` is reliable
    if ( !function_exists( 'wpaicg_gacg_fs' ) ) {
        // Create a helper function for easy SDK access.
        function wpaicg_gacg_fs() {
            global $wpaicg_gacg_fs;
            if ( !isset( $wpaicg_gacg_fs ) ) {
                // Activate multisite network integration.
                if ( !defined( 'WP_FS__PRODUCT_11606_MULTISITE' ) ) {
                    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Freemius requires this product-specific multisite constant name.
                    define( 'WP_FS__PRODUCT_11606_MULTISITE', true );
                }
                // Include Freemius SDK.
                require_once dirname( __FILE__ ) . '/vendor/freemius/start.php';
                $wpaicg_gacg_fs = fs_dynamic_init( array(
                    'id'               => '11606',
                    'slug'             => 'gpt3-ai-content-generator',
                    'type'             => 'plugin',
                    'public_key'       => 'pk_374fe2f12f24f09286bc6f89cd0c6',
                    'is_premium'       => false,
                    'premium_suffix'   => 'Pro',
                    'has_addons'       => false,
                    'has_paid_plans'   => true,
                    'anonymous_mode'   => true,
                    'menu'             => array(
                        'slug'       => 'wpaicg',
                        'first-path' => 'admin.php?page=wpaicg',
                        'support'    => false,
                    ),
                    'is_live'          => true,
                    'is_org_compliant' => true,
                ) );
            }
            return $wpaicg_gacg_fs;
        }

        // Init Freemius.
        wpaicg_gacg_fs();
        // Signal that SDK was initiated.
        do_action( 'wpaicg_gacg_fs_loaded' );
    }
    // --- Load Core Dashboard Class (needed by Pro loader) ---
    $aipkit_dashboard_class_path = WPAICG_PLUGIN_DIR . 'classes/admin/dashboard.php';
    if ( file_exists( $aipkit_dashboard_class_path ) ) {
        require_once $aipkit_dashboard_class_path;
    }
    // --- End Load Core Dashboard Class ---
    // --- Load Pro Features ---
    // We always load the Pro library loader. The logic within that file handles the Freemius checks.
    $pro_loader_path = WPAICG_LIB_DIR . 'wpaicg__premium_only.php';
    if ( file_exists( $pro_loader_path ) ) {
        require_once $pro_loader_path;
    }
    // --- Core Plugin Includes ---
    require_once WPAICG_PLUGIN_DIR . 'includes/plugin.php';
    // --- Activation / Deactivation Hooks ---
    register_activation_hook( __FILE__, ['WPAICG\\WP_AI_Content_Generator_Activator', 'activate'] );
    register_deactivation_hook( __FILE__, ['WPAICG\\WP_AI_Content_Generator_Deactivator', 'deactivate'] );
    // --- Multisite Setup ---
    if ( function_exists( 'wp_initialize_site' ) ) {
        add_action(
            'wp_initialize_site',
            ['WPAICG\\WP_AI_Content_Generator_Activator', 'setup_new_blog'],
            10,
            2
        );
    } else {
        add_action(
            'wpmu_new_blog',
            ['WPAICG\\WP_AI_Content_Generator_Activator', 'setup_new_blog'],
            10,
            2
        );
    }
    // --- Run Plugin ---
    \WPAICG\WP_AI_Content_Generator::get_instance()->run();
}