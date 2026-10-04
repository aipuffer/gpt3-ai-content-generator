<?php
/**
 * WordPress hook and shortcode composition. Feature execution stays in module owners.
 */

namespace WPAICG\Includes;

if (!defined('ABSPATH')) {
    exit;
}

use WPAICG\PublicFrontend\WP_AI_Content_Generator_Public;
use WPAICG\Includes\AIPKit_Blocks_Manager;
use WPAICG\Shortcodes\AIPKit_Shortcodes_Manager;
use WPAICG\PostEnhancer\Core as PostEnhancerCore;
use WPAICG\Speech\AIPKit_Speech_Manager;
use WPAICG\STT\AIPKit_STT_Manager;
use WPAICG\Images\AIPKit_Image_Manager;
use WPAICG\Images\AIPKit_Image_Settings_Ajax_Handler;
use WPAICG\Vector\AIPKit_Vector_Post_Processor_Ajax_Handler;
use WPAICG\Dashboard\Ajax\AIPKit_OpenAI_Vector_Stores_Ajax_Handler;
use WPAICG\Dashboard\Ajax\AIPKit_OpenAI_Vector_Store_Files_Ajax_Handler;
use WPAICG\Dashboard\Ajax\AIPKit_OpenAI_WP_Content_Indexing_Ajax_Handler;
use WPAICG\Dashboard\Ajax\AIPKit_Google_File_Search_Ajax_Handler;
use WPAICG\Dashboard\Ajax\AIPKit_Vector_Store_Pinecone_Ajax_Handler;
use WPAICG\Dashboard\Ajax\AIPKit_Vector_Store_Qdrant_Ajax_Handler;
use WPAICG\Dashboard\Ajax\AIPKit_Vector_Store_Chroma_Ajax_Handler;
use WPAICG\KnowledgeBase\AIPKit_Source_Ajax_Handler;
use WPAICG\Usage\AIPKit_Log_Ajax_Handler;
use WPAICG\Usage\AIPKit_Pricing_Ajax_Handler;
use WPAICG\AutoGPT\AIPKit_Automated_Task_Manager;
use WPAICG\ContentWriter\Ajax\Actions\AIPKit_Content_Writer_Init_Stream_Action;
use WPAICG\ContentWriter\Ajax\Actions\AIPKit_Content_Writer_Standard_Generation_Action;
use WPAICG\ContentWriter\Ajax\Actions\AIPKit_Content_Writer_Generate_Title_Action;
use WPAICG\ContentWriter\Ajax\Actions\AIPKit_Content_Writer_Save_Post_Action;
use WPAICG\ContentWriter\Ajax\Actions\AIPKit_Content_Writer_Create_Task_Action;
use WPAICG\ContentWriter\Ajax\AIPKit_Content_Writer_Template_Ajax_Handler;
use WPAICG\ContentWriter\Ajax\AIPKit_Content_Writer_Prompt_Library_Ajax_Handler;
use WPAICG\AIForms\Admin\AIPKit_AI_Form_Ajax_Handler;
use WPAICG\AIForms\Admin\AIPKit_AI_Form_Settings_Ajax_Handler;
use WPAICG\Chat\Frontend\Ajax\ChatFormSubmissionAjaxHandler;
use WPAICG\Lib\Chat\Frontend\Ajax\ChatFileUploadAjaxDispatcher as LibChatFileUploadAjaxDispatcher;
use WPAICG\Dashboard\Ajax\SettingsAjaxHandler;
use WPAICG\Dashboard\Ajax\AIPKit_Event_Webhook_Delivery_Issues_Ajax_Handler;
use WPAICG\Dashboard\Ajax\ModelsAjaxHandler;
use WPAICG\Core\Ajax\AIPKit_Semantic_Search_Ajax_Handler;
use WPAICG\Lib\Chat\Frontend\Ajax\Handlers\AIPKit_Realtime_Session_Ajax_Handler;
use WPAICG\REST\AIPKit_REST_Controller;
use WPAICG\Includes\HookRegistrars\Core_Hooks_Registrar;
use WPAICG\Includes\HookRegistrars\Admin_Asset_Hooks_Registrar;
use WPAICG\Includes\HookRegistrars\Ajax_Hooks_Registrar;
use WPAICG\Includes\HookRegistrars\Rest_Api_Hooks_Registrar;
use WPAICG\Includes\HookRegistrars\Module_Initializer_Hooks_Registrar;

/**
 * AIPKit_Hook_Manager
 * Centralizes the registration of WordPress hooks for the plugin by orchestrating sub-registrars.
 */
class AIPKit_Hook_Manager
{
    /**
     * Define the core hooks (actions and filters) by calling specialized registrars.
     *
     * @param string $plugin_version The current plugin version.
     */
    public static function register_hooks(string $plugin_version)
    {
        $admin_like_request = is_admin() || wp_doing_ajax();
        if (is_admin()) {
            // Paid users need plan changes even while the SDK caches the old single-plan catalog.
            wpaicg_gacg_fs()->add_filter('is_pricing_page_visible', '__return_true');
        }
        if (class_exists(\WPAICG\Cloud\Connection::class)) {
            wpaicg_gacg_fs()->add_action('after_account_connection', [\WPAICG\Cloud\Connection::class, 'account_changed']);
            wpaicg_gacg_fs()->add_action('after_account_user_sync', [\WPAICG\Cloud\Connection::class, 'sync_verified_account']);
            wpaicg_gacg_fs()->add_action('after_account_delete', [\WPAICG\Cloud\Connection::class, 'forget_connection']);
            wpaicg_gacg_fs()->add_action('after_uninstall', [\WPAICG\Cloud\Connection::class, 'uninstall']);
            add_action('wp_ajax_aipkit_cloud_connection', [\WPAICG\Cloud\Connection::class, 'handle']);
            if (is_admin()) {
                add_action('admin_init', [\WPAICG\Cloud\Connection::class, 'remove_scheduled_sync']);
                \WPAICG\Cloud\CreditNotice::register();
                \WPAICG\Cloud\Announcement::register();
            }
        }
        if ($admin_like_request && class_exists(\WPAICG\Admin\Onboarding::class)) {
            \WPAICG\Admin\Onboarding::register();
        }

        // --- Instantiate ALL services/handlers needed by ANY registrar ---
        $public_handler  = new WP_AI_Content_Generator_Public();
        $blocks_manager  = new AIPKit_Blocks_Manager($plugin_version);
        $shortcodes      = new AIPKit_Shortcodes_Manager($plugin_version);
        $post_enhancer   = ($admin_like_request && class_exists(PostEnhancerCore::class)) ? new PostEnhancerCore() : null;
        $speech_manager  = class_exists(AIPKit_Speech_Manager::class) ? new AIPKit_Speech_Manager() : null;
        $stt_manager     = class_exists(AIPKit_STT_Manager::class) ? new AIPKit_STT_Manager() : null;
        $image_manager   = class_exists(AIPKit_Image_Manager::class) ? new AIPKit_Image_Manager() : null;
        $rest_controller = class_exists(AIPKit_REST_Controller::class) ? new AIPKit_REST_Controller() : null;

        $image_settings_ajax_handler = null;
        $vector_post_processor_ajax_handler = null;
        $openai_vs_stores_ajax_handler = null;
        $openai_vs_files_ajax_handler = null;
        $openai_wp_content_indexing_ajax_handler = null;
        $google_file_search_ajax_handler = null;
        $pinecone_vector_store_ajax_handler = null;
        $qdrant_vector_store_ajax_handler = null;
        $chroma_vector_store_ajax_handler = null;
        $source_ajax_handler = null;
        $log_ajax_handler = null;
        $pricing_ajax_handler = null;
        $automated_task_manager = null;
        $content_writer_init_stream_action = null;
        $content_writer_standard_gen_action = null;
        $content_writer_generate_title_action = null;
        $content_writer_save_post_action = null;
        $content_writer_create_task_action = null;
        $content_writer_template_ajax_handler = null;
        $content_writer_prompt_library_ajax_handler = null;
        $ai_form_ajax_handler = null;
        $ai_form_settings_ajax_handler = null;
        $settings_ajax_handler = null;
        $event_webhook_delivery_issues_ajax_handler = null;
        $models_ajax_handler = null;
        $semantic_search_ajax_handler = null;
        $chat_form_submission_ajax_handler = null;
        $chat_file_upload_ajax_dispatcher = null;
        $realtime_session_ajax_handler = null;

        if ($admin_like_request) {
            $image_settings_ajax_handler = class_exists(AIPKit_Image_Settings_Ajax_Handler::class) ? new AIPKit_Image_Settings_Ajax_Handler() : null;
            $vector_post_processor_ajax_handler = class_exists(AIPKit_Vector_Post_Processor_Ajax_Handler::class) ? new AIPKit_Vector_Post_Processor_Ajax_Handler() : null;
            $openai_vs_stores_ajax_handler = class_exists(AIPKit_OpenAI_Vector_Stores_Ajax_Handler::class) ? new AIPKit_OpenAI_Vector_Stores_Ajax_Handler() : null;
            $openai_vs_files_ajax_handler = class_exists(AIPKit_OpenAI_Vector_Store_Files_Ajax_Handler::class) ? new AIPKit_OpenAI_Vector_Store_Files_Ajax_Handler() : null;
            $openai_wp_content_indexing_ajax_handler = class_exists(AIPKit_OpenAI_WP_Content_Indexing_Ajax_Handler::class) ? new AIPKit_OpenAI_WP_Content_Indexing_Ajax_Handler() : null;
            $google_file_search_ajax_handler = class_exists(AIPKit_Google_File_Search_Ajax_Handler::class) ? new AIPKit_Google_File_Search_Ajax_Handler() : null;
            $pinecone_vector_store_ajax_handler = class_exists(AIPKit_Vector_Store_Pinecone_Ajax_Handler::class) ? new AIPKit_Vector_Store_Pinecone_Ajax_Handler() : null;
            $qdrant_vector_store_ajax_handler = class_exists(AIPKit_Vector_Store_Qdrant_Ajax_Handler::class) ? new AIPKit_Vector_Store_Qdrant_Ajax_Handler() : null;
            $chroma_vector_store_ajax_handler = class_exists(AIPKit_Vector_Store_Chroma_Ajax_Handler::class) ? new AIPKit_Vector_Store_Chroma_Ajax_Handler() : null;
            $source_ajax_handler = class_exists(AIPKit_Source_Ajax_Handler::class) ? new AIPKit_Source_Ajax_Handler() : null;
            $log_ajax_handler = class_exists(AIPKit_Log_Ajax_Handler::class) ? new AIPKit_Log_Ajax_Handler() : null;
            $pricing_ajax_handler = class_exists(AIPKit_Pricing_Ajax_Handler::class) ? new AIPKit_Pricing_Ajax_Handler() : null;
            $automated_task_manager = class_exists(AIPKit_Automated_Task_Manager::class) ? new AIPKit_Automated_Task_Manager() : null;
            $content_writer_init_stream_action = class_exists(AIPKit_Content_Writer_Init_Stream_Action::class) ? new AIPKit_Content_Writer_Init_Stream_Action() : null;
            $content_writer_standard_gen_action = class_exists(AIPKit_Content_Writer_Standard_Generation_Action::class) ? new AIPKit_Content_Writer_Standard_Generation_Action() : null;
            $content_writer_generate_title_action = class_exists(AIPKit_Content_Writer_Generate_Title_Action::class) ? new AIPKit_Content_Writer_Generate_Title_Action() : null;
            $content_writer_save_post_action = class_exists(AIPKit_Content_Writer_Save_Post_Action::class) ? new AIPKit_Content_Writer_Save_Post_Action() : null;
            $content_writer_create_task_action = class_exists(AIPKit_Content_Writer_Create_Task_Action::class) ? new AIPKit_Content_Writer_Create_Task_Action() : null;
            $content_writer_template_ajax_handler = class_exists(AIPKit_Content_Writer_Template_Ajax_Handler::class) ? new AIPKit_Content_Writer_Template_Ajax_Handler() : null;
            $content_writer_prompt_library_ajax_handler = class_exists(AIPKit_Content_Writer_Prompt_Library_Ajax_Handler::class) ? new AIPKit_Content_Writer_Prompt_Library_Ajax_Handler() : null;
            $ai_form_ajax_handler = class_exists(AIPKit_AI_Form_Ajax_Handler::class) ? new AIPKit_AI_Form_Ajax_Handler() : null;
            $ai_form_settings_ajax_handler = class_exists(AIPKit_AI_Form_Settings_Ajax_Handler::class) ? new AIPKit_AI_Form_Settings_Ajax_Handler() : null;
            $settings_ajax_handler = class_exists(SettingsAjaxHandler::class) ? new SettingsAjaxHandler() : null;
            $event_webhook_delivery_issues_ajax_handler = class_exists(AIPKit_Event_Webhook_Delivery_Issues_Ajax_Handler::class) ? new AIPKit_Event_Webhook_Delivery_Issues_Ajax_Handler() : null;
            $models_ajax_handler = class_exists(ModelsAjaxHandler::class) ? new ModelsAjaxHandler() : null;
            $semantic_search_ajax_handler = class_exists(AIPKit_Semantic_Search_Ajax_Handler::class) ? new AIPKit_Semantic_Search_Ajax_Handler() : null;

            if (class_exists(ChatFormSubmissionAjaxHandler::class)) {
                $chat_form_submission_ajax_handler = new ChatFormSubmissionAjaxHandler();
            }

            if (
                class_exists('\WPAICG\aipkit_dashboard') &&
                method_exists('\WPAICG\aipkit_dashboard', 'is_pro_plan') &&
                \WPAICG\aipkit_dashboard::is_pro_plan() &&
                class_exists(LibChatFileUploadAjaxDispatcher::class)
            ) {
                $chat_file_upload_ajax_dispatcher = new LibChatFileUploadAjaxDispatcher();
            }

            if (class_exists('\WPAICG\aipkit_dashboard') && \WPAICG\aipkit_dashboard::is_pro_plan()) {
                if (class_exists(AIPKit_Realtime_Session_Ajax_Handler::class)) {
                    $realtime_session_ajax_handler = new AIPKit_Realtime_Session_Ajax_Handler();
                }
            }
        }

        // --- End Instantiations ---

        // --- Call Specialized Registrars ---
        if (class_exists(Core_Hooks_Registrar::class)) {
            Core_Hooks_Registrar::register(
                $public_handler,
                $blocks_manager,
                $shortcodes,
                $post_enhancer,
                $speech_manager,
                $stt_manager,
                $image_manager
            );
        }

        if ($admin_like_request && class_exists(Admin_Asset_Hooks_Registrar::class)) {
            Admin_Asset_Hooks_Registrar::register();
        }

        if ($admin_like_request && class_exists(Ajax_Hooks_Registrar::class) &&
            $image_settings_ajax_handler && $vector_post_processor_ajax_handler &&
            $openai_vs_stores_ajax_handler && $openai_vs_files_ajax_handler &&
            $openai_wp_content_indexing_ajax_handler && $google_file_search_ajax_handler && $pinecone_vector_store_ajax_handler &&
            $qdrant_vector_store_ajax_handler && $chroma_vector_store_ajax_handler && $source_ajax_handler && $log_ajax_handler && $pricing_ajax_handler &&
            $automated_task_manager && $content_writer_init_stream_action &&
            $content_writer_standard_gen_action && $content_writer_generate_title_action &&
            $content_writer_save_post_action && $content_writer_create_task_action &&
            $content_writer_template_ajax_handler && $ai_form_ajax_handler &&
            $ai_form_settings_ajax_handler && // NEW CHECK
            $chat_form_submission_ajax_handler && $settings_ajax_handler && $models_ajax_handler &&
            $semantic_search_ajax_handler // ADDED
        ) {
            Ajax_Hooks_Registrar::register(
                $image_settings_ajax_handler,
                $vector_post_processor_ajax_handler,
                $openai_vs_stores_ajax_handler,
                $openai_vs_files_ajax_handler,
                $openai_wp_content_indexing_ajax_handler,
                $google_file_search_ajax_handler,
                $pinecone_vector_store_ajax_handler,
                $qdrant_vector_store_ajax_handler,
                $chroma_vector_store_ajax_handler,
                $source_ajax_handler,
                $log_ajax_handler,
                $pricing_ajax_handler,
                $automated_task_manager,
                $content_writer_init_stream_action,
                $content_writer_standard_gen_action,
                $content_writer_generate_title_action,
                $content_writer_save_post_action,
                $content_writer_create_task_action,
                $content_writer_template_ajax_handler,
                $ai_form_ajax_handler,
                $ai_form_settings_ajax_handler, // NEW: Pass handler
                $chat_form_submission_ajax_handler,
                $chat_file_upload_ajax_dispatcher,
                $settings_ajax_handler,
                $event_webhook_delivery_issues_ajax_handler,
                $models_ajax_handler,
                $realtime_session_ajax_handler,
                $semantic_search_ajax_handler, // ADDED
                $content_writer_prompt_library_ajax_handler
            );
        }


        if ($rest_controller && class_exists(Rest_Api_Hooks_Registrar::class)) {
            Rest_Api_Hooks_Registrar::register($rest_controller);
        }

        if (class_exists(Module_Initializer_Hooks_Registrar::class)) {
            Module_Initializer_Hooks_Registrar::register();
        }
    }
}

namespace WPAICG\Shortcodes;

use WPAICG\Shortcodes\AIPKit_Token_Usage_Shortcode;
use WPAICG\Shortcodes\AIPKit_Image_Generator_Shortcode;
use WPAICG\Shortcodes\AIPKit_Semantic_Search_Shortcode;
use WPAICG\Includes\AIPKit_Shared_Assets_Manager;
use WPAICG\aipkit_dashboard;

/**
 * AIPKit_Shortcodes_Manager
 * Registers shortcodes and handles their asset enqueueing using bundled files.
 */
class AIPKit_Shortcodes_Manager
{
    private $version;
    private $token_usage_shortcode = null;
    private $image_generator_shortcode = null;
    private $semantic_search_shortcode = null; // NEW
    private $is_token_management_active = true;
    private $is_image_generator_active = false;
    private $is_semantic_search_active = true; // NEW
    private $is_token_usage_css_enqueued = false;
    private $is_image_generator_css_enqueued = false;
    private $is_semantic_search_css_enqueued = false; // NEW

    public function __construct($version)
    {
        $this->version = $version;
        if (!class_exists('\\WPAICG\\aipkit_dashboard')) {
            $dashboard_path = WPAICG_PLUGIN_DIR . 'classes/admin/dashboard.php';
            if (file_exists($dashboard_path)) {
                require_once $dashboard_path;
            }
        }
        if (class_exists('\\WPAICG\\aipkit_dashboard')) {
            $module_settings = aipkit_dashboard::get_module_settings();
            $this->is_image_generator_active = !empty($module_settings['image_generator']);
        }
    }

    public function init_hooks()
    {
        $this->load_dependencies();
        if ($this->is_token_management_active && $this->token_usage_shortcode) {
            add_shortcode('aipkit_token_usage', [$this->token_usage_shortcode, 'render_shortcode']);
            if (method_exists($this->token_usage_shortcode, 'init_hooks')) {
                $this->token_usage_shortcode->init_hooks();
            }
        }
        if ($this->is_image_generator_active && $this->image_generator_shortcode) {
            add_shortcode('aipkit_image_generator', [$this->image_generator_shortcode, 'render_shortcode']);
        }
        if ($this->is_semantic_search_active && $this->semantic_search_shortcode) {
            add_shortcode('aipkit_semantic_search', [$this->semantic_search_shortcode, 'render_shortcode']);
        }
        add_action('wp_enqueue_scripts', [$this, 'register_and_enqueue_assets']);
    }

    private function load_dependencies()
    {
        if ($this->is_token_management_active) {
            $token_usage_path = WPAICG_PLUGIN_DIR . 'classes/usage/shortcode.php';
            if (file_exists($token_usage_path)) {
                require_once $token_usage_path;
                if (class_exists('\\WPAICG\\Shortcodes\\AIPKit_Token_Usage_Shortcode')) {
                    $this->token_usage_shortcode = new AIPKit_Token_Usage_Shortcode();
                }
            }
        }
        if ($this->is_image_generator_active) {
            $image_gen_path = WPAICG_PLUGIN_DIR . 'classes/image-generator/shortcode.php';
            if (file_exists($image_gen_path)) {
                require_once $image_gen_path;
                if (class_exists('\\WPAICG\\Shortcodes\\AIPKit_Image_Generator_Shortcode')) {
                    $this->image_generator_shortcode = new AIPKit_Image_Generator_Shortcode();
                }
            }
        }
        if ($this->is_semantic_search_active) {
            $semantic_search_path = WPAICG_PLUGIN_DIR . 'classes/knowledge-base/shortcode.php';
            if (file_exists($semantic_search_path)) {
                require_once $semantic_search_path;
                if (class_exists('\\WPAICG\\Shortcodes\\AIPKit_Semantic_Search_Shortcode')) {
                    $this->semantic_search_shortcode = new AIPKit_Semantic_Search_Shortcode();
                }
            }
        }
    }

    public function register_and_enqueue_assets()
    {
        if (is_admin()) {
            return;
        }

        global $post;
        $content = is_a($post, 'WP_Post') ? $post->post_content : '';
        $dist_css_url = WPAICG_PLUGIN_URL . 'dist/css/';
        $dist_js_url = WPAICG_PLUGIN_URL . 'dist/js/';
        $public_image_generator_js_handle = 'aipkit-public-image-generator-js';
        $public_token_usage_js_handle = 'aipkit-public-token-usage-js';
        $public_semantic_search_js_handle = 'aipkit-public-semantic-search-js';

        if ($this->is_token_management_active && has_shortcode($content, 'aipkit_token_usage')) {
            $token_usage_css_handle = 'aipkit-public-token-usage';
            if (!wp_style_is($token_usage_css_handle, 'registered')) {
                wp_register_style($token_usage_css_handle, $dist_css_url . 'public-token-usage.bundle.css', [], $this->version);
            }
            if (!$this->is_token_usage_css_enqueued && !wp_style_is($token_usage_css_handle, 'enqueued')) {
                wp_enqueue_style($token_usage_css_handle);
                $this->is_token_usage_css_enqueued = true;
            }
            if (!wp_script_is($public_token_usage_js_handle, 'registered')) {
                wp_register_script($public_token_usage_js_handle, $dist_js_url . 'public-token-usage.bundle.js', [], $this->version, true);
            }
            if (!wp_script_is($public_token_usage_js_handle, 'enqueued')) {
                wp_enqueue_script($public_token_usage_js_handle);
            }
        }

        $image_generator_present = $this->is_image_generator_active && (
            has_shortcode($content, 'aipkit_image_generator') || has_block('aipkit/image-generator', $content)
        );
        $force_load_image_gen = apply_filters('aipkit_enqueue_public_image_generator_assets', false);

        if ($image_generator_present || $force_load_image_gen) {
            $public_img_gen_css_handle = 'aipkit-public-image-generator-css';
            if (!wp_style_is($public_img_gen_css_handle, 'registered')) {
                wp_register_style($public_img_gen_css_handle, $dist_css_url . 'public-image-generator.bundle.css', ['aipkit-shared-model-selector'], $this->version);
            }
            if (!$this->is_image_generator_css_enqueued && !wp_style_is($public_img_gen_css_handle, 'enqueued')) {
                wp_enqueue_style($public_img_gen_css_handle);
                $this->is_image_generator_css_enqueued = true;
            }
            if (!wp_script_is($public_image_generator_js_handle, 'registered')) {
                wp_register_script($public_image_generator_js_handle, $dist_js_url . 'public-image-generator.bundle.js', ['aipkit-shared-model-selector'], $this->version, true);
            }
            if (!wp_script_is($public_image_generator_js_handle, 'enqueued')) {
                wp_enqueue_script($public_image_generator_js_handle);
            }
        }

        if ($this->is_semantic_search_active && has_shortcode($content, 'aipkit_semantic_search')) {
            $semantic_search_css_handle = 'aipkit-public-semantic-search';
            if (!wp_style_is($semantic_search_css_handle, 'registered')) {
                wp_register_style($semantic_search_css_handle, $dist_css_url . 'public-semantic-search.bundle.css', [], $this->version);
            }
            if (!$this->is_semantic_search_css_enqueued && !wp_style_is($semantic_search_css_handle, 'enqueued')) {
                wp_enqueue_style($semantic_search_css_handle);
                $this->is_semantic_search_css_enqueued = true;
            }
            if (!wp_script_is($public_semantic_search_js_handle, 'registered')) {
                wp_register_script($public_semantic_search_js_handle, $dist_js_url . 'public-semantic-search.bundle.js', [], $this->version, true);
            }
            if (!wp_script_is($public_semantic_search_js_handle, 'enqueued')) {
                wp_enqueue_script($public_semantic_search_js_handle);
            }
        }

        // --- START FIX: Localize data for Image Generator shortcode ---
        if (($image_generator_present || $force_load_image_gen) && wp_script_is($public_image_generator_js_handle, 'enqueued')) {
            static $image_gen_localized = false;
            if (!$image_gen_localized) {
                wp_localize_script(
                    $public_image_generator_js_handle,
                    'aipkit_image_generator_config_public',
                    AIPKit_Shared_Assets_Manager::get_public_image_generator_config()
                );
                $image_gen_localized = true;
            }
        }
        // --- END FIX ---

        // Localize data for each shortcode if present and script is enqueued
        if ($this->is_token_management_active && has_shortcode($content, 'aipkit_token_usage') && wp_script_is($public_token_usage_js_handle, 'enqueued')) {
            static $token_usage_localized = false;
            if (!$token_usage_localized) {
                wp_localize_script($public_token_usage_js_handle, 'aipkit_token_usage_config', [
                    'ajaxUrl' => admin_url('admin-ajax.php'), 'nonce'   => wp_create_nonce('aipkit_token_usage_details_nonce'),
                    /* translators: %s is the name of the token, e.g. "OpenAI" */
                    'text' => ['loadingDetails' => __('Loading activity...', 'gpt3-ai-content-generator'), 'errorLoading' => __('Error loading activity.', 'gpt3-ai-content-generator'), 'close' => __('Close', 'gpt3-ai-content-generator'), 'usageDetailsTitle' => __('Usage Activity for %s', 'gpt3-ai-content-generator'), 'pageLabel' => __('Page', 'gpt3-ai-content-generator'), 'ofLabel' => __('of', 'gpt3-ai-content-generator'), 'previous' => __('Previous', 'gpt3-ai-content-generator'), 'next' => __('Next', 'gpt3-ai-content-generator'),]
                ]);
                $token_usage_localized = true;
            }
        }
        if ($this->is_semantic_search_active && has_shortcode($content, 'aipkit_semantic_search') && wp_script_is($public_semantic_search_js_handle, 'enqueued')) {
            static $semantic_search_localized = false;
            if (!$semantic_search_localized) {
                $opts = get_option('aipkit_options', []);
                $semantic_search_settings = $opts['semantic_search'] ?? [];
                wp_localize_script($public_semantic_search_js_handle, 'aipkit_semantic_search_config', [
                    'ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('aipkit_semantic_search_nonce'),
                    'settings' => $semantic_search_settings,
                    'text' => ['searching' => __('Searching...', 'gpt3-ai-content-generator'), 'error' => __('An error occurred while searching.', 'gpt3-ai-content-generator'),]
                ]);
                $semantic_search_localized = true;
            }
        }
    }
}

namespace WPAICG\Includes\HookRegistrars;

use WPAICG\Admin\Assets\DashboardAssets;
use WPAICG\Admin\Assets\SettingsAssets;
use WPAICG\Admin\Assets\ChatAdminAssets;
use WPAICG\Admin\Assets\RoleManagerAssets;
use WPAICG\Admin\Assets\PostEnhancerAssets;
use WPAICG\Admin\Assets\ImageGeneratorAssets;
use WPAICG\Admin\Assets\AIPKit_Vector_Post_Processor_Assets;
use WPAICG\Vector\PostProcessor\AIPKit_Vector_Post_Processor_List_Screen;
use WPAICG\Admin\Assets\AIPKit_Autogpt_Assets;
use WPAICG\Admin\Assets\AIPKit_Content_Writer_Assets;
use WPAICG\Admin\Assets\AIPKit_AI_Forms_Assets;
use WPAICG\Admin\Assets\AIPKit_Woocommerce_Writer_Assets;
use WPAICG\Images\AIPKit_Image_Settings_Ajax_Handler;
use WPAICG\Vector\AIPKit_Vector_Post_Processor_Ajax_Handler;
use WPAICG\Dashboard\Ajax\AIPKit_OpenAI_Vector_Stores_Ajax_Handler;
use WPAICG\Dashboard\Ajax\AIPKit_OpenAI_Vector_Store_Files_Ajax_Handler;
use WPAICG\Dashboard\Ajax\AIPKit_OpenAI_WP_Content_Indexing_Ajax_Handler;
use WPAICG\Dashboard\Ajax\AIPKit_Google_File_Search_Ajax_Handler;
use WPAICG\Dashboard\Ajax\AIPKit_Vector_Store_Pinecone_Ajax_Handler;
use WPAICG\Dashboard\Ajax\AIPKit_Vector_Store_Qdrant_Ajax_Handler;
use WPAICG\Dashboard\Ajax\AIPKit_Vector_Store_Chroma_Ajax_Handler;
use WPAICG\KnowledgeBase\AIPKit_Source_Ajax_Handler;
use WPAICG\Usage\AIPKit_Log_Ajax_Handler;
use WPAICG\Usage\AIPKit_Pricing_Ajax_Handler;
use WPAICG\AutoGPT\AIPKit_Automated_Task_Manager;
use WPAICG\ContentWriter\Ajax\Actions\AIPKit_Content_Writer_Init_Stream_Action;
use WPAICG\ContentWriter\Ajax\Actions\AIPKit_Content_Writer_Standard_Generation_Action;
use WPAICG\ContentWriter\Ajax\Actions\AIPKit_Content_Writer_Generate_Title_Action;
use WPAICG\ContentWriter\Ajax\Actions\AIPKit_Content_Writer_Generate_Excerpt_Action;
use WPAICG\ContentWriter\Ajax\Actions\AIPKit_Content_Writer_Generate_Tags_Action;
use WPAICG\ContentWriter\Ajax\Actions\AIPKit_Content_Writer_Save_Post_Action;
use WPAICG\ContentWriter\Ajax\Actions\AIPKit_Content_Writer_Create_Task_Action;
use WPAICG\ContentWriter\Ajax\Actions\AIPKit_Content_Writer_Prepare_Batch_Action;
use WPAICG\ContentWriter\Ajax\Actions\AIPKit_Content_Writer_Generate_Images_Action;
use WPAICG\ContentWriter\Ajax\Actions\AIPKit_Content_Writer_Generate_Meta_Action;
use WPAICG\ContentWriter\Ajax\Actions\AIPKit_Content_Writer_Generate_Keyword_Action;
use WPAICG\ContentWriter\Ajax\Actions\AIPKit_Content_Writer_Parse_Csv_Action;
use WPAICG\ContentWriter\Ajax\Actions\AIPKit_Content_Writer_Fetch_Posts_Action;
use WPAICG\ContentWriter\Ajax\Actions\AIPKit_Content_Writer_Prepare_Update_Run_Action;
use WPAICG\ContentWriter\Ajax\AIPKit_Content_Writer_Template_Ajax_Handler;
use WPAICG\ContentWriter\Ajax\AIPKit_Content_Writer_Prompt_Library_Ajax_Handler;
use WPAICG\AIForms\Admin\AIPKit_AI_Form_Ajax_Handler;
use WPAICG\AIForms\Admin\AIPKit_AI_Form_Settings_Ajax_Handler;
use WPAICG\Chat\Frontend\Ajax\ChatFormSubmissionAjaxHandler;
use WPAICG\Lib\Chat\Frontend\Ajax\ChatFileUploadAjaxDispatcher as LibChatFileUploadAjaxDispatcher;
use WPAICG\Dashboard\Ajax\SettingsAjaxHandler;
use WPAICG\Dashboard\Ajax\AIPKit_Event_Webhook_Delivery_Issues_Ajax_Handler;
use WPAICG\Dashboard\Ajax\ModelsAjaxHandler;
use WPAICG\PostEnhancer\Ajax\AIPKit_Enhancer_Actions_Ajax_Handler;
use WPAICG\Core\Ajax\AIPKit_Semantic_Search_Ajax_Handler;
use WPAICG\Lib\Chat\Frontend\Ajax\Handlers\AIPKit_Realtime_Session_Ajax_Handler;
use WPAICG\PublicFrontend\WP_AI_Content_Generator_Public;
use WPAICG\Includes\AIPKit_Blocks_Manager;
use WPAICG\Shortcodes\AIPKit_Shortcodes_Manager;
use WPAICG\PostEnhancer\Core as PostEnhancerCore;
use WPAICG\Speech\AIPKit_Speech_Manager;
use WPAICG\STT\AIPKit_STT_Manager;
use WPAICG\Images\AIPKit_Image_Manager;
use WPAICG\Chat\Initializer as ChatInitializer;
use WPAICG\AutoGPT\AIPKit_Automated_Task_Cron;
use WPAICG\AIForms\AIPKit_AI_Form_Initializer;
use WPAICG\REST\AIPKit_REST_Controller;

/**
 * Registers hooks for admin asset handlers.
 */
class Admin_Asset_Hooks_Registrar
{
    public static function register()
    {
        $dashboard_assets    = new DashboardAssets();
        $settings_assets     = new SettingsAssets();
        $chat_admin_assets   = new ChatAdminAssets();
        $role_manager_assets = new RoleManagerAssets();
        $post_enhancer_assets = new PostEnhancerAssets();
        $image_generator_assets = new ImageGeneratorAssets();
        $content_writer_assets = new AIPKit_Content_Writer_Assets();
        $vector_post_processor_assets = new AIPKit_Vector_Post_Processor_Assets();
        $vector_post_processor_list_screen = new AIPKit_Vector_Post_Processor_List_Screen();
        $autogpt_assets = class_exists(AIPKit_Autogpt_Assets::class) ? new AIPKit_Autogpt_Assets() : null;
        $ai_forms_assets = class_exists(AIPKit_AI_Forms_Assets::class) ? new AIPKit_AI_Forms_Assets() : null;
        $woocommerce_writer_assets = class_exists(AIPKit_Woocommerce_Writer_Assets::class)
            ? new AIPKit_Woocommerce_Writer_Assets()
            : null;


        $dashboard_assets->register_hooks();
        $settings_assets->register_hooks();
        $chat_admin_assets->register_hooks();
        $role_manager_assets->register_hooks();
        $post_enhancer_assets->register_hooks();
        $image_generator_assets->register_hooks();
        $content_writer_assets->register_hooks();
        $vector_post_processor_assets->register_hooks();
        $vector_post_processor_list_screen->register_hooks();
        if ($autogpt_assets) {
            $autogpt_assets->register_hooks();
        }
        if ($ai_forms_assets) {
            $ai_forms_assets->register_hooks();
        }
        if ($woocommerce_writer_assets) {
            $woocommerce_writer_assets->register_hooks();
        }
    }
}

/**
 * Registers AJAX action hooks.
 */
class Ajax_Hooks_Registrar
{
    public static function register(
        AIPKit_Image_Settings_Ajax_Handler $image_settings_ajax_handler,
        AIPKit_Vector_Post_Processor_Ajax_Handler $vector_post_processor_ajax_handler,
        AIPKit_OpenAI_Vector_Stores_Ajax_Handler $openai_vs_stores_ajax_handler,
        AIPKit_OpenAI_Vector_Store_Files_Ajax_Handler $openai_vs_files_ajax_handler,
        AIPKit_OpenAI_WP_Content_Indexing_Ajax_Handler $openai_wp_content_indexing_ajax_handler,
        AIPKit_Google_File_Search_Ajax_Handler $google_file_search_ajax_handler,
        AIPKit_Vector_Store_Pinecone_Ajax_Handler $pinecone_vector_store_ajax_handler,
        AIPKit_Vector_Store_Qdrant_Ajax_Handler $qdrant_vector_store_ajax_handler,
        AIPKit_Vector_Store_Chroma_Ajax_Handler $chroma_vector_store_ajax_handler,
        AIPKit_Source_Ajax_Handler $source_ajax_handler,
        AIPKit_Log_Ajax_Handler $log_ajax_handler,
        AIPKit_Pricing_Ajax_Handler $pricing_ajax_handler,
        AIPKit_Automated_Task_Manager $automated_task_manager,
        AIPKit_Content_Writer_Init_Stream_Action $content_writer_init_stream_action,
        AIPKit_Content_Writer_Standard_Generation_Action $content_writer_standard_gen_action,
        AIPKit_Content_Writer_Generate_Title_Action $content_writer_generate_title_action,
        AIPKit_Content_Writer_Save_Post_Action $content_writer_save_post_action,
        AIPKit_Content_Writer_Create_Task_Action $content_writer_create_task_action,
        ?AIPKit_Content_Writer_Template_Ajax_Handler $content_writer_template_ajax_handler = null,
        ?AIPKit_AI_Form_Ajax_Handler $ai_form_ajax_handler = null,
        ?AIPKit_AI_Form_Settings_Ajax_Handler $ai_form_settings_ajax_handler = null, // NEW
        ?ChatFormSubmissionAjaxHandler $chat_form_submission_ajax_handler = null,
        ?LibChatFileUploadAjaxDispatcher $chat_file_upload_ajax_dispatcher = null,
        ?SettingsAjaxHandler $settings_ajax_handler = null,
        ?AIPKit_Event_Webhook_Delivery_Issues_Ajax_Handler $event_webhook_delivery_issues_ajax_handler = null,
        ?ModelsAjaxHandler $models_ajax_handler = null,
        ?AIPKit_Realtime_Session_Ajax_Handler $realtime_session_ajax_handler = null,
        ?AIPKit_Semantic_Search_Ajax_Handler $semantic_search_ajax_handler = null, // ADDED
        ?AIPKit_Content_Writer_Prompt_Library_Ajax_Handler $content_writer_prompt_library_ajax_handler = null
    ) {
        $content_writer_generate_meta_action = class_exists(AIPKit_Content_Writer_Generate_Meta_Action::class) ? new AIPKit_Content_Writer_Generate_Meta_Action() : null;
        $content_writer_generate_keyword_action = class_exists(AIPKit_Content_Writer_Generate_Keyword_Action::class) ? new AIPKit_Content_Writer_Generate_Keyword_Action() : null;
        $content_writer_generate_excerpt_action = class_exists(AIPKit_Content_Writer_Generate_Excerpt_Action::class) ? new AIPKit_Content_Writer_Generate_Excerpt_Action() : null;
        $content_writer_generate_tags_action = class_exists(AIPKit_Content_Writer_Generate_Tags_Action::class) ? new AIPKit_Content_Writer_Generate_Tags_Action() : null;
        $content_writer_generate_images_action = class_exists(AIPKit_Content_Writer_Generate_Images_Action::class) ? new AIPKit_Content_Writer_Generate_Images_Action() : null;
        $content_writer_parse_csv_action = class_exists(AIPKit_Content_Writer_Parse_Csv_Action::class) ? new AIPKit_Content_Writer_Parse_Csv_Action() : null;
        $content_writer_fetch_posts_action = class_exists(AIPKit_Content_Writer_Fetch_Posts_Action::class) ? new AIPKit_Content_Writer_Fetch_Posts_Action() : null;
        $content_writer_prepare_update_run_action = class_exists(AIPKit_Content_Writer_Prepare_Update_Run_Action::class) ? new AIPKit_Content_Writer_Prepare_Update_Run_Action() : null;
        $content_writer_prepare_batch_action = class_exists(AIPKit_Content_Writer_Prepare_Batch_Action::class) ? new AIPKit_Content_Writer_Prepare_Batch_Action() : null;
        $enhancer_actions_ajax_handler = class_exists(AIPKit_Enhancer_Actions_Ajax_Handler::class) ? new AIPKit_Enhancer_Actions_Ajax_Handler() : null;

        if ($settings_ajax_handler) {
            if (method_exists($settings_ajax_handler, 'ajax_save_settings')) {
                add_action('wp_ajax_aipkit_save_ai_settings', [$settings_ajax_handler, 'ajax_save_settings']);
            }
            if (method_exists($settings_ajax_handler, 'ajax_save_semantic_search_settings')) {
                add_action('wp_ajax_aipkit_save_semantic_search_settings', [$settings_ajax_handler, 'ajax_save_semantic_search_settings']);
            }
            if (method_exists($settings_ajax_handler, 'ajax_update_developer_credential')) {
                add_action('wp_ajax_aipkit_update_developer_credential', [$settings_ajax_handler, 'ajax_update_developer_credential']);
            }
            if (method_exists($settings_ajax_handler, 'ajax_reveal_settings_credential')) {
                add_action('wp_ajax_aipkit_reveal_settings_credential', [$settings_ajax_handler, 'ajax_reveal_settings_credential']);
            }
            if (method_exists($settings_ajax_handler, 'ajax_connect_stock_photo_provider')) {
                add_action('wp_ajax_aipkit_connect_stock_photo_provider', [$settings_ajax_handler, 'ajax_connect_stock_photo_provider']);
            }
            if (method_exists($settings_ajax_handler, 'ajax_export_settings_backup')) {
                add_action('wp_ajax_aipkit_export_settings_backup', [$settings_ajax_handler, 'ajax_export_settings_backup']);
            }
            if (method_exists($settings_ajax_handler, 'ajax_import_settings_backup')) {
                add_action('wp_ajax_aipkit_import_settings_backup', [$settings_ajax_handler, 'ajax_import_settings_backup']);
            }
            if (method_exists($settings_ajax_handler, 'ajax_create_settings_restore_point')) {
                add_action('wp_ajax_aipkit_create_settings_restore_point', [$settings_ajax_handler, 'ajax_create_settings_restore_point']);
            }
            if (method_exists($settings_ajax_handler, 'ajax_restore_settings_restore_point')) {
                add_action('wp_ajax_aipkit_restore_settings_restore_point', [$settings_ajax_handler, 'ajax_restore_settings_restore_point']);
            }
        }

        if ($event_webhook_delivery_issues_ajax_handler) {
            if (method_exists($event_webhook_delivery_issues_ajax_handler, 'ajax_retry_event_webhook_delivery_issue')) {
                add_action('wp_ajax_aipkit_retry_event_webhook_delivery_issue', [$event_webhook_delivery_issues_ajax_handler, 'ajax_retry_event_webhook_delivery_issue']);
            }
            if (method_exists($event_webhook_delivery_issues_ajax_handler, 'ajax_clear_event_webhook_delivery_issue')) {
                add_action('wp_ajax_aipkit_clear_event_webhook_delivery_issue', [$event_webhook_delivery_issues_ajax_handler, 'ajax_clear_event_webhook_delivery_issue']);
            }
        }

        if ($models_ajax_handler) {
            if (method_exists($models_ajax_handler, 'ajax_get_model_catalog')) {
                add_action('wp_ajax_aipkit_get_model_catalog', [$models_ajax_handler, 'ajax_get_model_catalog']);
            }
            if (method_exists($models_ajax_handler, 'ajax_get_model_sync_targets')) {
                add_action('wp_ajax_aipkit_get_model_sync_targets', [$models_ajax_handler, 'ajax_get_model_sync_targets']);
            }
            add_action('wp_ajax_aipkit_provider_connection', [$models_ajax_handler, 'ajax_provider_connection']);
            if (method_exists($models_ajax_handler, 'ajax_sync_models')) {
                add_action('wp_ajax_aipkit_sync_models', [$models_ajax_handler, 'ajax_sync_models']);
            }
        }

        if (method_exists($image_settings_ajax_handler, 'ajax_save_image_settings')) {
            add_action('wp_ajax_aipkit_save_image_settings', [$image_settings_ajax_handler, 'ajax_save_image_settings']);
        }

        if ($ai_form_settings_ajax_handler && method_exists($ai_form_settings_ajax_handler, 'ajax_save_ai_forms_settings')) {
            add_action('wp_ajax_aipkit_save_ai_forms_settings', [$ai_form_settings_ajax_handler, 'ajax_save_ai_forms_settings']);
        }

        if (method_exists($vector_post_processor_ajax_handler, 'ajax_index_posts_to_vector_store')) {
            add_action('wp_ajax_aipkit_index_posts_to_vector_store', [$vector_post_processor_ajax_handler, 'ajax_index_posts_to_vector_store']);
            add_action('wp_ajax_aipkit_get_post_indexing_status', [$vector_post_processor_ajax_handler, 'ajax_get_post_indexing_status']);
        }

        add_action('wp_ajax_aipkit_list_vector_stores_openai', [$openai_vs_stores_ajax_handler, 'ajax_list_vector_stores_openai']);
        add_action('wp_ajax_aipkit_create_vector_store_openai', [$openai_vs_stores_ajax_handler, 'ajax_create_vector_store_openai']);
        add_action('wp_ajax_aipkit_delete_vector_store_openai', [$openai_vs_stores_ajax_handler, 'ajax_delete_vector_store_openai']);
        add_action('wp_ajax_aipkit_add_text_to_vector_store_openai', [$openai_vs_files_ajax_handler, 'ajax_add_text_to_vector_store_openai']);
        add_action('wp_ajax_aipkit_upload_and_add_file_to_store_direct_openai', [$openai_vs_files_ajax_handler, 'ajax_upload_and_add_file_to_store_direct_openai']);
        add_action('wp_ajax_aipkit_get_openai_file_batch_status', [$openai_vs_files_ajax_handler, 'ajax_get_openai_file_batch_status']);

        add_action('wp_ajax_aipkit_fetch_wp_content_for_indexing', [$openai_wp_content_indexing_ajax_handler, 'ajax_fetch_wp_content_for_indexing']);
        add_action('wp_ajax_aipkit_index_selected_wp_content', [$openai_wp_content_indexing_ajax_handler, 'ajax_index_selected_wp_content']);

        add_action('wp_ajax_aipkit_list_google_file_search_stores', [$google_file_search_ajax_handler, 'ajax_list_stores']);
        add_action('wp_ajax_aipkit_create_google_file_search_store', [$google_file_search_ajax_handler, 'ajax_create_store']);
        add_action('wp_ajax_aipkit_delete_google_file_search_store', [$google_file_search_ajax_handler, 'ajax_delete_store']);
        add_action('wp_ajax_aipkit_add_text_to_google_file_search', [$google_file_search_ajax_handler, 'ajax_add_text']);
        add_action('wp_ajax_aipkit_index_wp_content_google_file_search', [$google_file_search_ajax_handler, 'ajax_index_wp_content']);
        add_action('wp_ajax_aipkit_get_google_file_search_job_status', [$google_file_search_ajax_handler, 'ajax_get_job_status']);

        add_action('wp_ajax_aipkit_list_indexes_pinecone', [$pinecone_vector_store_ajax_handler, 'ajax_list_indexes_pinecone']);
        add_action('wp_ajax_aipkit_create_index_pinecone', [$pinecone_vector_store_ajax_handler, 'ajax_create_index_pinecone']);
        add_action('wp_ajax_aipkit_upsert_to_pinecone_index', [$pinecone_vector_store_ajax_handler, 'ajax_upsert_to_pinecone_index']);
        add_action('wp_ajax_aipkit_upload_file_and_upsert_to_pinecone', [$pinecone_vector_store_ajax_handler, 'ajax_upload_file_and_upsert_to_pinecone']);
        add_action('wp_ajax_aipkit_delete_index_pinecone', [$pinecone_vector_store_ajax_handler, 'ajax_delete_index_pinecone']);

        if (class_exists(\WPAICG\Dashboard\Ajax\AIPKit_Vector_Store_Local_Ajax_Handler::class)) {
            $local_vector_store_ajax_handler = new \WPAICG\Dashboard\Ajax\AIPKit_Vector_Store_Local_Ajax_Handler();
            add_action('wp_ajax_aipkit_local_list_stores', [$local_vector_store_ajax_handler, 'ajax_list_stores']);
            add_action('wp_ajax_aipkit_local_create_store', [$local_vector_store_ajax_handler, 'ajax_create_store']);
            add_action('wp_ajax_aipkit_local_delete_store', [$local_vector_store_ajax_handler, 'ajax_delete_store']);
            add_action('wp_ajax_aipkit_local_add_text', [$local_vector_store_ajax_handler, 'ajax_add_text']);
            add_action('wp_ajax_aipkit_local_delete_source', [$local_vector_store_ajax_handler, 'ajax_delete_source']);
            add_action('wp_ajax_aipkit_local_upload_file', [$local_vector_store_ajax_handler, 'ajax_upload_file']);
        }
        add_action('wp_ajax_aipkit_list_collections_qdrant', [$qdrant_vector_store_ajax_handler, 'ajax_list_collections_qdrant']);
        add_action('wp_ajax_aipkit_create_collection_qdrant', [$qdrant_vector_store_ajax_handler, 'ajax_create_collection_qdrant']);
        add_action('wp_ajax_aipkit_delete_collection_qdrant', [$qdrant_vector_store_ajax_handler, 'ajax_delete_collection_qdrant']);
        add_action('wp_ajax_aipkit_upsert_to_qdrant_collection', [$qdrant_vector_store_ajax_handler, 'ajax_upsert_to_qdrant_collection']);
        add_action('wp_ajax_aipkit_upload_file_and_upsert_to_qdrant', [$qdrant_vector_store_ajax_handler, 'ajax_upload_file_and_upsert_to_qdrant']);

        add_action('wp_ajax_aipkit_list_collections_chroma', [$chroma_vector_store_ajax_handler, 'ajax_list_collections_chroma']);
        add_action('wp_ajax_aipkit_create_collection_chroma', [$chroma_vector_store_ajax_handler, 'ajax_create_collection_chroma']);
        add_action('wp_ajax_aipkit_delete_collection_chroma', [$chroma_vector_store_ajax_handler, 'ajax_delete_collection_chroma']);
        add_action('wp_ajax_aipkit_upsert_to_chroma_collection', [$chroma_vector_store_ajax_handler, 'ajax_upsert_to_chroma_collection']);
        add_action('wp_ajax_aipkit_upload_file_and_upsert_to_chroma', [$chroma_vector_store_ajax_handler, 'ajax_upload_file_and_upsert_to_chroma']);

        foreach ([
            [$source_ajax_handler, [
                'aipkit_get_global_vector_sources' => 'ajax_get_global_vector_sources',
                'aipkit_delete_vector_data_source_entry' => 'ajax_delete_vector_data_source_entry',
                'aipkit_reindex_vector_data_source_entry' => 'ajax_reindex_vector_data_source_entry',
                'aipkit_get_cpt_indexing_options' => 'ajax_get_cpt_indexing_options',
                'aipkit_save_cpt_indexing_options' => 'ajax_save_cpt_indexing_options',
            ]],
            [$log_ajax_handler, [
                'aipkit_stats_get_logs' => 'ajax_get_stats_logs',
                'aipkit_stats_get_requests' => 'ajax_get_stats_requests',
                'aipkit_stats_delete_requests' => 'ajax_delete_stats_requests',
                'aipkit_stats_get_log_detail' => 'ajax_get_stats_log_detail',
                'aipkit_stats_set_ip_block' => 'ajax_set_stats_ip_block',
                'aipkit_stats_export_logs' => 'ajax_export_stats_logs',
                'aipkit_stats_delete_log' => 'ajax_delete_stats_log',
                'aipkit_stats_delete_logs' => 'ajax_delete_stats_logs',
                'aipkit_stats_save_settings' => 'ajax_save_stats_settings',
                'aipkit_stats_get_log_cron_status' => 'ajax_get_stats_log_cron_status',
            ]],
            [$pricing_ajax_handler, [
                'aipkit_stats_get_pricing_management' => 'ajax_get_stats_pricing_management',
                'aipkit_stats_save_pricing_rule' => 'ajax_save_stats_pricing_rule',
                'aipkit_stats_delete_pricing_rule' => 'ajax_delete_stats_pricing_rule',
            ]],
        ] as [$handler, $actions]) {
            foreach ($actions as $action => $method) {
                add_action('wp_ajax_' . $action, [$handler, $method]);
            }
        }

        if (method_exists($automated_task_manager, 'init_ajax_hooks')) {
            $automated_task_manager->init_ajax_hooks();
        }

        if (method_exists($content_writer_init_stream_action, 'handle')) {
            add_action('wp_ajax_aipkit_content_writer_init_stream', [$content_writer_init_stream_action, 'handle']);
        }
        if (method_exists($content_writer_standard_gen_action, 'handle')) {
            add_action('wp_ajax_aipkit_content_writer_generate_standard', [$content_writer_standard_gen_action, 'handle']);
        }
        if (method_exists($content_writer_generate_title_action, 'handle')) {
            add_action('wp_ajax_aipkit_content_writer_generate_title', [$content_writer_generate_title_action, 'handle']);
        }
        add_action('wp_ajax_aipkit_save_cw_template', [$content_writer_template_ajax_handler, 'ajax_save_template']);
        add_action('wp_ajax_aipkit_rename_cw_template', [$content_writer_template_ajax_handler, 'ajax_rename_template']);
        add_action('wp_ajax_aipkit_delete_cw_template', [$content_writer_template_ajax_handler, 'ajax_delete_template']);
        add_action('wp_ajax_aipkit_list_cw_templates', [$content_writer_template_ajax_handler, 'ajax_list_templates']);
        add_action('wp_ajax_aipkit_reset_cw_starter_templates', [$content_writer_template_ajax_handler, 'ajax_reset_starter_templates']);
        if ($content_writer_prompt_library_ajax_handler && method_exists($content_writer_prompt_library_ajax_handler, 'ajax_list_prompt_library')) {
            add_action('wp_ajax_aipkit_list_prompt_library', [$content_writer_prompt_library_ajax_handler, 'ajax_list_prompt_library']);
        }
        if ($content_writer_prompt_library_ajax_handler && method_exists($content_writer_prompt_library_ajax_handler, 'ajax_create_prompt_library_item')) {
            add_action('wp_ajax_aipkit_create_prompt_library_item', [$content_writer_prompt_library_ajax_handler, 'ajax_create_prompt_library_item']);
        }
        if ($content_writer_prompt_library_ajax_handler && method_exists($content_writer_prompt_library_ajax_handler, 'ajax_update_prompt_library_item')) {
            add_action('wp_ajax_aipkit_update_prompt_library_item', [$content_writer_prompt_library_ajax_handler, 'ajax_update_prompt_library_item']);
        }
        if ($content_writer_prompt_library_ajax_handler && method_exists($content_writer_prompt_library_ajax_handler, 'ajax_delete_prompt_library_item')) {
            add_action('wp_ajax_aipkit_delete_prompt_library_item', [$content_writer_prompt_library_ajax_handler, 'ajax_delete_prompt_library_item']);
        }

        if (method_exists($content_writer_save_post_action, 'handle')) {
            add_action('wp_ajax_aipkit_content_writer_save_post', [$content_writer_save_post_action, 'handle']);
        }
        if (method_exists($content_writer_create_task_action, 'handle')) {
            add_action('wp_ajax_aipkit_content_writer_create_task', [$content_writer_create_task_action, 'handle']);
        }
        if ($content_writer_prepare_batch_action && method_exists($content_writer_prepare_batch_action, 'handle')) {
            add_action('wp_ajax_aipkit_content_writer_prepare_batch', [$content_writer_prepare_batch_action, 'handle']);
        }
        if ($content_writer_prepare_batch_action && method_exists($content_writer_prepare_batch_action, 'handle_cancel')) {
            add_action('wp_ajax_aipkit_content_writer_cancel_batch', [$content_writer_prepare_batch_action, 'handle_cancel']);
        }
        if ($content_writer_generate_meta_action && method_exists($content_writer_generate_meta_action, 'handle')) {
            add_action('wp_ajax_aipkit_content_writer_generate_meta_desc', [$content_writer_generate_meta_action, 'handle']);
        }
        if ($content_writer_generate_keyword_action && method_exists($content_writer_generate_keyword_action, 'handle')) {
            add_action('wp_ajax_aipkit_content_writer_generate_focus_keyword', [$content_writer_generate_keyword_action, 'handle']);
        }
        if ($content_writer_generate_excerpt_action && method_exists($content_writer_generate_excerpt_action, 'handle')) {
            add_action('wp_ajax_aipkit_content_writer_generate_excerpt', [$content_writer_generate_excerpt_action, 'handle']);
        }
        if ($content_writer_generate_tags_action && method_exists($content_writer_generate_tags_action, 'handle')) {
            add_action('wp_ajax_aipkit_content_writer_generate_tags', [$content_writer_generate_tags_action, 'handle']);
        }
        if ($content_writer_generate_images_action && method_exists($content_writer_generate_images_action, 'handle')) {
            add_action('wp_ajax_aipkit_content_writer_generate_images', [$content_writer_generate_images_action, 'handle']);
        }
        if ($content_writer_parse_csv_action && method_exists($content_writer_parse_csv_action, 'handle')) {
            add_action('wp_ajax_aipkit_content_writer_parse_csv', [$content_writer_parse_csv_action, 'handle']);
        }
        if ($content_writer_fetch_posts_action && method_exists($content_writer_fetch_posts_action, 'handle')) {
            add_action('wp_ajax_aipkit_content_writer_fetch_existing_posts', [$content_writer_fetch_posts_action, 'handle']);
        }
        if ($content_writer_prepare_update_run_action && method_exists($content_writer_prepare_update_run_action, 'handle')) {
            add_action('wp_ajax_aipkit_content_writer_prepare_existing_update', [$content_writer_prepare_update_run_action, 'handle']);
        }
        if ($content_writer_prepare_update_run_action && method_exists($content_writer_prepare_update_run_action, 'handle_cancel')) {
            add_action('wp_ajax_aipkit_content_writer_cancel_existing_update', [$content_writer_prepare_update_run_action, 'handle_cancel']);
        }

        if ($ai_form_ajax_handler && method_exists($ai_form_ajax_handler, 'register_ajax_hooks')) {
            $ai_form_ajax_handler->register_ajax_hooks();
        }

        if ($chat_form_submission_ajax_handler && method_exists($chat_form_submission_ajax_handler, 'ajax_handle_form_submission')) {
            add_action('wp_ajax_aipkit_handle_form_submission', [$chat_form_submission_ajax_handler, 'ajax_handle_form_submission']);
            add_action('wp_ajax_nopriv_aipkit_handle_form_submission', [$chat_form_submission_ajax_handler, 'ajax_handle_form_submission']);
        }
        if ($chat_file_upload_ajax_dispatcher && method_exists($chat_file_upload_ajax_dispatcher, 'ajax_handle_frontend_file_upload')) {
            add_action('wp_ajax_aipkit_frontend_chat_upload_file', [$chat_file_upload_ajax_dispatcher, 'ajax_handle_frontend_file_upload']);
            add_action('wp_ajax_nopriv_aipkit_frontend_chat_upload_file', [$chat_file_upload_ajax_dispatcher, 'ajax_handle_frontend_file_upload']);
        }

        if ($realtime_session_ajax_handler && method_exists($realtime_session_ajax_handler, 'ajax_create_session')) {
            add_action('wp_ajax_aipkit_create_realtime_session', [$realtime_session_ajax_handler, 'ajax_create_session']);
            add_action('wp_ajax_nopriv_aipkit_create_realtime_session', [$realtime_session_ajax_handler, 'ajax_create_session']);
            add_action('wp_ajax_aipkit_sync_live_session', [$realtime_session_ajax_handler, 'ajax_sync_live_session']);
            add_action('wp_ajax_nopriv_aipkit_sync_live_session', [$realtime_session_ajax_handler, 'ajax_sync_live_session']);
        }

        if ($realtime_session_ajax_handler && method_exists($realtime_session_ajax_handler, 'ajax_log_session_turn')) {
            add_action('wp_ajax_aipkit_log_realtime_session_turn', [$realtime_session_ajax_handler, 'ajax_log_session_turn']);
            add_action('wp_ajax_nopriv_aipkit_log_realtime_session_turn', [$realtime_session_ajax_handler, 'ajax_log_session_turn']);
        }

        if ($enhancer_actions_ajax_handler) {
            if (method_exists($enhancer_actions_ajax_handler, 'ajax_get_actions')) {
                add_action('wp_ajax_aipkit_get_enhancer_actions', [$enhancer_actions_ajax_handler, 'ajax_get_actions']);
            }
            if (method_exists($enhancer_actions_ajax_handler, 'ajax_save_action')) {
                add_action('wp_ajax_aipkit_save_enhancer_action', [$enhancer_actions_ajax_handler, 'ajax_save_action']);
            }
            if (method_exists($enhancer_actions_ajax_handler, 'ajax_delete_action')) {
                add_action('wp_ajax_aipkit_delete_enhancer_action', [$enhancer_actions_ajax_handler, 'ajax_delete_action']);
            }
            if (method_exists($enhancer_actions_ajax_handler, 'ajax_reset_actions')) {
                add_action('wp_ajax_aipkit_reset_enhancer_actions', [$enhancer_actions_ajax_handler, 'ajax_reset_actions']);
            }
            if (method_exists($enhancer_actions_ajax_handler, 'ajax_reorder_actions')) {
                add_action('wp_ajax_aipkit_reorder_enhancer_actions', [$enhancer_actions_ajax_handler, 'ajax_reorder_actions']);
            }
        }

        if ($semantic_search_ajax_handler && method_exists($semantic_search_ajax_handler, 'ajax_perform_semantic_search')) {
            add_action('wp_ajax_aipkit_perform_semantic_search', [$semantic_search_ajax_handler, 'ajax_perform_semantic_search']);
            add_action('wp_ajax_nopriv_aipkit_perform_semantic_search', [$semantic_search_ajax_handler, 'ajax_perform_semantic_search']);
        }
    }
}

/**
 * Registers core functionality hooks (public, shortcodes, and non-AJAX module inits).
 */
class Core_Hooks_Registrar {

    public static function register(
        WP_AI_Content_Generator_Public $public,
        AIPKit_Blocks_Manager $blocks_manager,
        AIPKit_Shortcodes_Manager $shortcodes,
        ?PostEnhancerCore $post_enhancer,
        ?AIPKit_Speech_Manager $speech_manager, // Nullable if class might not exist
        ?AIPKit_STT_Manager $stt_manager,       // Nullable
        ?AIPKit_Image_Manager $image_manager     // Nullable
    ) {
        $public->init_hooks();
        $blocks_manager->init_hooks();
        $shortcodes->init_hooks();
        if ($post_enhancer && method_exists($post_enhancer, 'init_hooks')) {
            $post_enhancer->init_hooks();
        }

        if ($speech_manager && method_exists($speech_manager, 'init_hooks')) {
            $speech_manager->init_hooks();
        }
        if ($stt_manager && method_exists($stt_manager, 'init_hooks')) {
            $stt_manager->init_hooks();
        }
        if ($image_manager && method_exists($image_manager, 'init_hooks')) {
            $image_manager->init_hooks();
        }

        add_filter('cron_schedules', [__CLASS__, 'add_custom_cron_schedules']);
    }

    /**
     * Adds custom cron schedules for more frequent task automation.
     * WP Cron's actual execution depends on site traffic. These define intervals, not exact run times.
     * @param array $schedules The existing cron schedules.
     * @return array The modified schedules.
     */
    public static function add_custom_cron_schedules($schedules) {
        $schedules['aipkit_five_minutes'] = [
            'interval' => 300, // 5 * 60 seconds
            'display'  => self::get_schedule_display_label('aipkit_five_minutes')
        ];
        $schedules['aipkit_fifteen_minutes'] = [
            'interval' => 900, // 15 * 60 seconds
            'display'  => self::get_schedule_display_label('aipkit_fifteen_minutes')
        ];
        $schedules['aipkit_thirty_minutes'] = [
            'interval' => 1800, // 30 * 60 seconds
            'display'  => self::get_schedule_display_label('aipkit_thirty_minutes')
        ];
        // Ensure weekly is present as some plugins/themes might remove it.
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = [
                'interval' => 604800,
                'display'  => self::get_schedule_display_label('weekly')
            ];
        }
        return $schedules;
    }

    /**
     * Cron schedules can be requested before init while WordPress is preparing events.
     * Avoid triggering just-in-time textdomain loading notices in that early path.
     */
    private static function get_schedule_display_label(string $schedule): string
    {
        $can_translate = did_action('init');

        switch ($schedule) {
            case 'aipkit_five_minutes':
                return $can_translate ? __('Every 5 Minutes', 'gpt3-ai-content-generator') : 'Every 5 Minutes';
            case 'aipkit_fifteen_minutes':
                return $can_translate ? __('Every 15 Minutes', 'gpt3-ai-content-generator') : 'Every 15 Minutes';
            case 'aipkit_thirty_minutes':
                return $can_translate ? __('Every 30 Minutes', 'gpt3-ai-content-generator') : 'Every 30 Minutes';
            case 'weekly':
                return $can_translate ? __('Once Weekly', 'gpt3-ai-content-generator') : 'Once Weekly';
        }

        return '';
    }
}

/**
 * Registers hooks for modules that have their own initializers or self-registering cron jobs.
 */
class Module_Initializer_Hooks_Registrar {

    public static function register() {
        // Chat Initializer
        if (class_exists(ChatInitializer::class) && method_exists(ChatInitializer::class, 'register_hooks')) { // Added method_exists check for safety
            ChatInitializer::register_hooks();
        }

        // Automated Task Cron - Called Statically
        if (self::should_boot_automated_tasks() &&
            class_exists(AIPKit_Automated_Task_Cron::class) &&
            method_exists(AIPKit_Automated_Task_Cron::class, 'init')) {
            AIPKit_Automated_Task_Cron::init(); // Call statically
        }

        // AI Forms Initializer
        if (class_exists(AIPKit_AI_Form_Initializer::class) && method_exists(AIPKit_AI_Form_Initializer::class, 'register_hooks')) {
            AIPKit_AI_Form_Initializer::register_hooks();
        }
    }

    private static function should_boot_automated_tasks(): bool
    {
        if (is_admin() || wp_doing_cron()) {
            return true;
        }

        return defined('WP_CLI') && WP_CLI;
    }
}

/**
 * Registers REST API hooks.
 */
class Rest_Api_Hooks_Registrar {

    public static function register(?AIPKit_REST_Controller $rest_controller) { // Nullable
        if ($rest_controller && method_exists($rest_controller, 'register_routes')) {
            add_action('rest_api_init', [$rest_controller, 'register_routes']);
        }
    }
}
