<?php
/**
 * Chatbot dependency loading and hook registration.
 */

namespace WPAICG\Chat\Initializer;

use WPAICG\Chat\Admin\AdminSetup;
use WPAICG\Chat\Frontend\Shortcode;
use WPAICG\Chat\Frontend\Assets;
use WPAICG\Chat\Admin\Ajax; // Namespace for AJAX Handlers
use WPAICG\Chat\Admin\Ajax\ConversationAjaxHandler;
use WPAICG\Chat\Admin\Ajax\ChatbotImageAjaxHandler;
use WPAICG\Core\Stream\Handler\SSEHandler;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Logic for loading Core Chat Service dependencies.
 * Called by WPAICG\Chat\Initializer::load_dependencies().
 */
function load_core_services_logic(): void {
    $base_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/';
    $core_service_paths = [
        'ai-service.php' => \WPAICG\Chat\Core\AIService::class,
        'content-context.php' => \WPAICG\Chat\Core\AIPKit_Content_Aware::class,
    ];

    foreach ($core_service_paths as $file => $class_name) {
        $full_path = $base_path . $file;
        if (file_exists($full_path) && !class_exists($class_name)) {
            require_once $full_path;
        }
    }
}

/**
 * Logic for loading Chat Admin Setup dependencies.
 * Called by WPAICG\Chat\Initializer::load_dependencies().
 */
function load_admin_setup_logic(): void {
    $base_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/';
    $admin_setup_path = $base_path . 'admin.php';

    if (file_exists($admin_setup_path) && !class_exists(\WPAICG\Chat\Admin\AdminSetup::class)) {
        require_once $admin_setup_path;
    }
}

/**
 * Logic for loading Chat AJAX Handler dependencies.
 * Called by WPAICG\Chat\Initializer::load_dependencies().
 */
function load_ajax_handlers_logic(): void {
    $base_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/';
    $ajax_handlers_paths = [
        'bot-actions.php' => Ajax\ChatbotAjaxHandler::class,
        'conversation-actions.php' => Ajax\ConversationAjaxHandler::class,
        'image-actions.php' => Ajax\ChatbotImageAjaxHandler::class,
    ];

    // BaseAjaxHandler is supplied by Base_Ajax_Handlers_Loader.
    foreach ($ajax_handlers_paths as $file => $class_name) {
        $full_path = $base_path . $file;
        if (file_exists($full_path) && !class_exists($class_name)) {
            require_once $full_path;
        }
    }
}

/**
 * Logic for loading Chat Frontend dependencies.
 * Called by WPAICG\Chat\Initializer::load_dependencies().
 */
function load_frontend_logic(): void {
    $base_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/';
    $frontend_paths = [
        'assets.php' => \WPAICG\Chat\Frontend\Assets::class,
        'shortcode.php' => \WPAICG\Chat\Frontend\Shortcode::class,
    ];

    foreach ($frontend_paths as $file => $class_name) {
        $full_path = $base_path . $file;
        if (file_exists($full_path) && !class_exists($class_name)) {
            require_once $full_path;
        }
    }
}

/**
 * Logic for loading Chat Utility dependencies.
 * Called by WPAICG\Chat\Initializer::load_dependencies().
 */
function load_utils_logic(): void {
    $svg_icons_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/icons.php';
    if (file_exists($svg_icons_path) && !class_exists(\WPAICG\Chat\Utils\AIPKit_SVG_Icons::class)) {
        require_once $svg_icons_path;
    }
}

/**
 * Logic for loading the Core SSE Handler dependency.
 * Called by WPAICG\Chat\Initializer::load_dependencies().
 */
function load_sse_handler_logic(): void {
    $sse_handler_path = WPAICG_PLUGIN_DIR . 'classes/streaming/handler.php';
    if (file_exists($sse_handler_path) && !class_exists(\WPAICG\Core\Stream\Handler\SSEHandler::class)) {
        require_once $sse_handler_path;
    }
}

/**
 * Logic for registering core Chat module hooks (CPT, shortcode, assets).
 * Called by WPAICG\Chat\Initializer::register_hooks().
 *
 * @param AdminSetup $admin_setup
 * @param Shortcode $shortcode
 * @param Assets $assets
 * @return void
 */
function register_hooks_core_logic(
    AdminSetup $admin_setup,
    Shortcode $shortcode,
    Assets $assets
): void {
    add_action('init', [$admin_setup, 'register_chatbot_post_type']);
    add_shortcode('aipkit_chatbot', [$shortcode, 'render_chatbot_shortcode']);
    $assets->register_hooks(); // This internally adds 'wp_enqueue_scripts' and 'template_redirect'
}

/**
 * Logic for registering admin-specific AJAX hooks for the Chat module.
 * Called by WPAICG\Chat\Initializer::register_hooks().
 *
 * @param Ajax\ChatbotAjaxHandler $chatbot_ajax_handler
 * @param Ajax\ConversationAjaxHandler $conversation_ajax_handler
 * @return void
 */
function register_hooks_admin_ajax_logic(
    Ajax\ChatbotAjaxHandler $chatbot_ajax_handler,
    Ajax\ConversationAjaxHandler $conversation_ajax_handler
): void {
    $actions = [
        'create_chatbot',
        'save_chatbot_settings',
        'delete_chatbot',
        'duplicate_chatbot',
        'get_chatbot_shortcode',
        'reset_chatbot_settings',
        'rename_chatbot',
        'update_chatbot_instructions',
        'update_chatbot_model_settings',
        'update_chatbot_ai_parameters',
        'update_chatbot_conversation_settings',
        'update_chatbot_style_settings',
        'update_chatbot_web_settings',
        'update_chatbot_context_settings',
        'update_chatbot_token_limits',
        'update_chatbot_image_settings',
        'update_chatbot_file_upload_settings',
        'update_chatbot_audio_settings',
        'update_chatbot_popup_settings',
        'update_chatbot_deploy_settings',
        'update_chatbot_triggers',
        'get_chatbot_training_source_count',
        'get_chatbot_training_status',
        'stop_chatbot_training',
        'get_chatbot_training_sources',
        'get_chatbot_switch_state',
    ];
    foreach ($actions as $action) {
        add_action('wp_ajax_aipkit_' . $action, [$chatbot_ajax_handler, 'ajax_' . $action]);
    }
}

/**
 * Logic for registering general AJAX hooks for the Chat module (frontend and admin).
 * Called by WPAICG\Chat\Initializer::register_hooks().
 *
 * @param ConversationAjaxHandler $conversation_ajax_handler
 * @param ChatbotImageAjaxHandler|null $chatbot_image_ajax_handler
 * @return void
 */
function register_hooks_general_ajax_logic(
    ConversationAjaxHandler $conversation_ajax_handler,
    ?ChatbotImageAjaxHandler $chatbot_image_ajax_handler
): void {
    // Ensure the nonce refresh function is available
    $nonce_refresh_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/nonce-actions.php';
    if (file_exists($nonce_refresh_path) && !function_exists('WPAICG\\Chat\\Frontend\\Ajax\\ajax_get_frontend_chat_nonce_logic')) {
        require_once $nonce_refresh_path;
    }
    // Conversation actions are available to both signed-in and guest requests.
    $actions = [
        'get_conversations_list',
        'get_conversation_history',
        'store_feedback',
        'generate_speech',
        'speech_request_status',
        'delete_single_conversation',
    ];
    foreach ($actions as $action) {
        $callback = [$conversation_ajax_handler, 'ajax_' . $action];
        add_action('wp_ajax_aipkit_' . $action, $callback);
        add_action('wp_ajax_nopriv_aipkit_' . $action, $callback);
    }

    // Hooks for image generation within chat
    if ($chatbot_image_ajax_handler) {
         add_action('wp_ajax_aipkit_chat_generate_image', [$chatbot_image_ajax_handler, 'ajax_chat_generate_image']);
         add_action('wp_ajax_nopriv_aipkit_chat_generate_image', [$chatbot_image_ajax_handler, 'ajax_chat_generate_image']);
    }

    // Frontend utility: Get fresh nonce for chat actions (anonymous allowed)
    if (function_exists('WPAICG\\Chat\\Frontend\\Ajax\\ajax_get_frontend_chat_nonce_logic')) {
        add_action('wp_ajax_aipkit_get_frontend_chat_nonce', 'WPAICG\\Chat\\Frontend\\Ajax\\ajax_get_frontend_chat_nonce_logic');
        add_action('wp_ajax_nopriv_aipkit_get_frontend_chat_nonce', 'WPAICG\\Chat\\Frontend\\Ajax\\ajax_get_frontend_chat_nonce_logic');
    }
}

/**
 * Logic for registering SSE-specific AJAX hooks for the Chat module.
 * Called by WPAICG\Chat\Initializer::register_hooks().
 *
 * @param SSEHandler|null $sse_handler
 * @return void
 */
function register_hooks_sse_ajax_logic(?SSEHandler $sse_handler): void {
    if ($sse_handler) {
        foreach (['cache_sse_message', 'frontend_chat_stream'] as $action) {
            $callback = [$sse_handler, 'ajax_' . $action];
            add_action('wp_ajax_aipkit_' . $action, $callback);
            add_action('wp_ajax_nopriv_aipkit_' . $action, $callback);
        }
    }
}

namespace WPAICG\Chat;

// Core classes instantiated in register_hooks
use WPAICG\Chat\Admin\AdminSetup;
use WPAICG\Chat\Frontend;
use WPAICG\Chat\Storage;
use WPAICG\Chat\Admin\Ajax;
use WPAICG\Core\Stream\Handler\SSEHandler;

/**
 * Initializes the AIPKit Chat functionality by loading dependencies and registering hooks.
 * Dependency loading and hook registration helpers share this owner file.
 */
class Initializer
{
    /**
     * Ensure dependencies specific to Chat module hooks are loaded.
     * Note: This is largely redundant if Chat_Dependencies_Loader has already run.
     */
    public static function load_dependencies()
    {
        Initializer\load_core_services_logic();
        Initializer\load_admin_setup_logic();
        Initializer\load_ajax_handlers_logic();
        Initializer\load_frontend_logic(); // This loads Frontend\Assets orchestrator
        Initializer\load_utils_logic();
        Initializer\load_sse_handler_logic();
    }

    /**
     * Register WordPress hooks conditionally.
     * Called by the main plugin class via Module_Initializer_Hooks_Registrar.
     */
    public static function register_hooks()
    {
        // Instantiate handlers needed for hook registration
        $admin_setup     = new AdminSetup();
        $shortcode       = new Frontend\Shortcode();
        $assets          = new Frontend\Assets();

        if (class_exists(Storage\LogCronManager::class)) {
            add_action(Storage\LogCronManager::HOOK_NAME, ['WPAICG\Chat\Storage\LogCronManager', 'run_pruning']);
        }

        // Core hooks (CPT, Shortcode, Assets) are needed on every request.
        Initializer\register_hooks_core_logic($admin_setup, $shortcode, $assets);

        if (!(is_admin() || wp_doing_ajax())) {
            return;
        }

        $sse_handler     = class_exists(SSEHandler::class) ? new SSEHandler() : null;

        // Instantiate specific Admin AJAX Handlers
        if (!class_exists('\\WPAICG\\Chat\\Admin\\Ajax\\BaseAjaxHandler')) {
            return;
        }
        $chatbot_ajax_handler = new Ajax\ChatbotAjaxHandler();
        $conversation_ajax_handler = new Ajax\ConversationAjaxHandler();
        $chatbot_image_ajax_handler = null;
        if (class_exists(\WPAICG\Chat\Admin\Ajax\ChatbotImageAjaxHandler::class)) {
            $chatbot_image_ajax_handler = new Ajax\ChatbotImageAjaxHandler();
        }

        Initializer\register_hooks_admin_ajax_logic(
            $chatbot_ajax_handler,
            $conversation_ajax_handler
        );
        Initializer\register_hooks_general_ajax_logic(
            $conversation_ajax_handler,
            $chatbot_image_ajax_handler
        );
        Initializer\register_hooks_sse_ajax_logic($sse_handler);
    }
}
