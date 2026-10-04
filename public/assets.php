<?php


namespace WPAICG\PublicFrontend;

use WPAICG\Chat\Frontend\Assets as ChatAssetsOrchestrator;
use WPAICG\Includes\AIPKit_Shared_Assets_Manager;
use WPAICG\aipkit_dashboard;

if (! defined('ABSPATH')) {
    exit;
}

/**
* The public-facing functionality of the plugin.
* Registers shared public bundles and enqueues chat-specific assets.
*/
class WP_AI_Content_Generator_Public
{
    private $version;
    private $is_public_main_js_enqueued = false;
    private $is_public_ai_forms_css_enqueued = false;


    /**
    * Register public-specific hooks.
    */
    public function init_hooks()
    {
        $this->version = defined('WPAICG_VERSION') ? WPAICG_VERSION : '1.9.15';

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']); // Combined for styles & scripts
        $this->initialize_chat_assets();
    }

    /**
    * Initialize Chat Frontend Assets handler if chat module is enabled.
    */
    private function initialize_chat_assets()
    {
        $dashboard_path = WPAICG_PLUGIN_DIR . 'classes/admin/dashboard.php';
        if (file_exists($dashboard_path)) {
            if (!class_exists('\\WPAICG\\aipkit_dashboard')) {
                require_once $dashboard_path;
            }
            if (class_exists('\\WPAICG\\aipkit_dashboard')) {
                $modules = aipkit_dashboard::get_module_settings();
                $is_chat_enabled = !empty($modules['chat_bot']);

                if ($is_chat_enabled) {
                    $chat_assets_orchestrator_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/assets.php';
                    if (file_exists($chat_assets_orchestrator_path)) {
                        if (!class_exists(ChatAssetsOrchestrator::class)) {
                            require_once $chat_assets_orchestrator_path;
                        }
                        if (class_exists(ChatAssetsOrchestrator::class)) {
                            $chat_assets = new ChatAssetsOrchestrator();
                            $chat_assets->register_hooks();
                        }
                    }
                }
            }
        }
    }

    /**
    * Register and enqueue stylesheets and scripts for the public-facing side.
    */
    public function enqueue_assets()
    {
        // --- Register Bundled CSS ---
        $dist_css_url = WPAICG_PLUGIN_URL . 'dist/css/';
        $public_ai_forms_css_handle = 'aipkit-public-ai-forms'; // Keep existing handle for AI Forms

        // AI Forms CSS
        if (!wp_style_is($public_ai_forms_css_handle, 'registered')) {
            wp_register_style(
                $public_ai_forms_css_handle,
                $dist_css_url . 'public-ai-forms.bundle.css',
                [], // AI Forms CSS is self-contained or depends on theme's base styles
                $this->version
            );
        }


        // --- Register Bundled JS ---
        $dist_js_url = WPAICG_PLUGIN_URL . 'dist/js/';
        $public_main_js_handle = 'aipkit-public-main';
        $public_ai_forms_js_handle = 'aipkit-public-ai-forms-js';

        if (!wp_script_is($public_main_js_handle, 'registered')) {
            wp_register_script(
                $public_main_js_handle,
                $dist_js_url . 'public-main.bundle.js',
                [],
                $this->version,
                true
            );
        }
        if (!wp_script_is($public_ai_forms_js_handle, 'registered')) {
            wp_register_script(
                $public_ai_forms_js_handle,
                $dist_js_url . 'public-ai-forms.bundle.js',
                ['wp-i18n'],
                $this->version,
                true
            );
        }

        // --- Conditional Enqueueing based on shortcode presence ---
        global $post;
        $content = is_a($post, 'WP_Post') ? $post->post_content : '';

        // AI Forms
        $ai_forms_present = has_shortcode($content, 'aipkit_ai_form') || has_block('aipkit/ai-form', $content);
        $force_load_ai_forms = apply_filters('aipkit_enqueue_public_ai_forms_assets', false);

        if ($ai_forms_present || $force_load_ai_forms) {
            if (!$this->is_public_ai_forms_css_enqueued && !wp_style_is($public_ai_forms_css_handle, 'enqueued')) {
                wp_enqueue_style($public_ai_forms_css_handle);
                $this->is_public_ai_forms_css_enqueued = true;
            }
            if (!wp_script_is($public_ai_forms_js_handle, 'enqueued')) {
                wp_enqueue_script($public_ai_forms_js_handle);
                wp_set_script_translations($public_ai_forms_js_handle, 'gpt3-ai-content-generator', WPAICG_PLUGIN_DIR . 'languages');
            }
            if (class_exists(AIPKit_Shared_Assets_Manager::class)) {
                AIPKit_Shared_Assets_Manager::attach_public_asset_urls($public_ai_forms_js_handle);
            }
            // Localize for AI Forms (attached to the dedicated AI Forms bundle)
            static $ai_forms_localized = false;
            if (!$ai_forms_localized) {
                $frontend_display_settings = [];
                if (class_exists('\\WPAICG\\AIForms\\Admin\\AIPKit_AI_Form_Settings_Ajax_Handler')) {
                    $all_settings = \WPAICG\AIForms\Admin\AIPKit_AI_Form_Settings_Ajax_Handler::get_settings();
                    $frontend_display_settings = $all_settings['frontend_display'] ?? [];
                }

                wp_localize_script($public_ai_forms_js_handle, 'aipkit_ai_forms_public_config', [
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'ajaxNonce' => wp_create_nonce('aipkit_frontend_chat_nonce'), // Re-using chat nonce, consider specific AI Forms nonce
                    'is_user_logged_in' => is_user_logged_in(),
                    'is_pro_plan' => class_exists('\\WPAICG\\aipkit_dashboard') && \WPAICG\aipkit_dashboard::is_pro_plan(),
                    'allowed_models' => $frontend_display_settings['allowed_models'] ?? '', // NEW
                    'text' => [
                        'processing' => __('Processing...', 'gpt3-ai-content-generator'),
                        'error' => __('An error occurred.', 'gpt3-ai-content-generator'),
                        'saveAsPost' => __('Save', 'gpt3-ai-content-generator'),
                    ]
                ]);

                if (class_exists('\\WPAICG\\AIPKit_Providers')) {
                    $all_models = [
                        'aipuffercloud' => \WPAICG\AIPKit_Providers::get_model_list('AIPufferCloud'),
                        'openai'     => \WPAICG\AIPKit_Providers::get_openai_models(),
                        'google'     => \WPAICG\AIPKit_Providers::get_google_models(),
                        'claude'     => \WPAICG\AIPKit_Providers::get_claude_models(),
                        'openrouter' => \WPAICG\AIPKit_Providers::get_openrouter_models(),
                        'azure'      => \WPAICG\AIPKit_Providers::get_azure_deployments(),
                    ];
                    if (
                        class_exists('\\WPAICG\\aipkit_dashboard') &&
                        \WPAICG\aipkit_dashboard::is_pro_plan()
                    ) {
                        $all_models['ollama'] = \WPAICG\AIPKit_Providers::get_ollama_models();
                    }
                    $all_models['deepseek'] = \WPAICG\AIPKit_Providers::get_deepseek_models();
                    $all_models['xai'] = \WPAICG\AIPKit_Providers::get_xai_models();
                    wp_localize_script($public_ai_forms_js_handle, 'aipkit_ai_forms_models', $all_models);
                }
                $ai_forms_localized = true;
            }
        }

        // Image Generator (Shortcode handled by AIPKit_Shortcodes_Manager)
        // This class only handles assets for its *own* shortcodes or general public assets.
        // The Shortcodes_Manager will handle enqueuing for aipkit_image_generator.

        // Enqueue public-main.bundle.js if any relevant shortcode is present OR if ChatAssetsOrchestrator signals need
        $chat_assets_needed = class_exists(ChatAssetsOrchestrator::class) && (ChatAssetsOrchestrator::$shortcode_rendered || ChatAssetsOrchestrator::$site_wide_injection_needed);

        if ($chat_assets_needed && !$this->is_public_main_js_enqueued) {
            if (!wp_script_is($public_main_js_handle, 'enqueued')) {
                wp_enqueue_script($public_main_js_handle);
            }
            if (class_exists(AIPKit_Shared_Assets_Manager::class)) {
                AIPKit_Shared_Assets_Manager::attach_public_asset_urls($public_main_js_handle);
            }
            $this->is_public_main_js_enqueued = true;
        }
    }
}
