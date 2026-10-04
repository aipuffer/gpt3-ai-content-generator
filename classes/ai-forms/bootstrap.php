<?php


namespace WPAICG\AIForms;

use WPAICG\AIForms\Admin\AIPKit_AI_Form_Admin_Setup;
use WPAICG\AIForms\Frontend\AIPKit_AI_Form_Shortcode;
use WPAICG\AIForms\Core\AIPKit_AI_Form_Processor;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Initializes the AIPKit AI Forms module by loading dependencies and registering hooks.
 */
class AIPKit_AI_Form_Initializer
{
    /**
     * Registers WordPress hooks for the AI Forms module.
     * Called by the AIPKit_Hook_Manager via Module_Initializer_Hooks_Registrar.
     */
    public static function register_hooks()
    {
        // Dependencies are loaded by the central AIPKit_Dependency_Loader.
        // This method should only register hooks.

        // Instantiate classes needed for hooks
        if (class_exists(AIPKit_AI_Form_Admin_Setup::class)) {
            $admin_setup = new AIPKit_AI_Form_Admin_Setup();
            add_action('init', [$admin_setup, 'register_cpt']);
        }

        if (class_exists(AIPKit_AI_Form_Shortcode::class)) {
            $shortcode_handler = new AIPKit_AI_Form_Shortcode();
            add_shortcode('aipkit_ai_form', [$shortcode_handler, 'render_shortcode']);
        }

        if (!wp_doing_ajax()) {
            return;
        }

        // AJAX action hooks for form submissions (frontend)
        if (class_exists(AIPKit_AI_Form_Processor::class)) {
            $form_processor = new AIPKit_AI_Form_Processor();
            // Note: The aipkit_process_ai_form hook is removed as it's unused.
            add_action('wp_ajax_aipkit_ai_form_upload_and_parse_file', [$form_processor, 'ajax_upload_and_parse_file']);
            add_action('wp_ajax_nopriv_aipkit_ai_form_upload_and_parse_file', [$form_processor, 'ajax_upload_and_parse_file']);
            add_action('wp_ajax_aipkit_ai_form_save_as_post', [$form_processor, 'ajax_save_as_post']);
        }
    }
}
