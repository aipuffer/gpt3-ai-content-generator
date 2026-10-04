<?php
/**
 * Shared dependency composition and module loading.
 */

namespace WPAICG\Includes;

use WPAICG\Includes\DependencyLoaders\Admin_Asset_Handlers_Loader;
use WPAICG\Includes\DependencyLoaders\Provider_Dependencies_Loader;
use WPAICG\Includes\DependencyLoaders\Core_Services_Loader;
use WPAICG\Includes\DependencyLoaders\Dashboard_Base_Classes_Loader;
use WPAICG\Includes\DependencyLoaders\Base_Ajax_Handlers_Loader;
use WPAICG\Includes\DependencyLoaders\Chat_Dependencies_Loader;
use WPAICG\Includes\DependencyLoaders\Speech_Dependencies_Loader;
use WPAICG\Includes\DependencyLoaders\Stt_Dependencies_Loader;
use WPAICG\Includes\DependencyLoaders\Rest_Dependencies_Loader;
use WPAICG\Includes\DependencyLoaders\Image_Dependencies_Loader;
use WPAICG\Includes\DependencyLoaders\Vector_Store_Dependencies_Loader;
use WPAICG\Includes\DependencyLoaders\Vector_Store_Ajax_Handlers_Loader;
use WPAICG\Includes\DependencyLoaders\Vector_Post_Processor_Classes_Loader;
use WPAICG\Includes\DependencyLoaders\Content_Writer_Dependencies_Loader;
use WPAICG\Includes\DependencyLoaders\Security_Dependencies_Loader;
use WPAICG\Includes\DependencyLoaders\Post_Enhancer_Core_Loader;
use WPAICG\Includes\DependencyLoaders\Woocommerce_Writer_Loader;
use WPAICG\Includes\DependencyLoaders\Automated_Task_Dependencies_Loader;
use WPAICG\Includes\DependencyLoaders\Automated_Task_Ajax_Handlers_Loader;
use WPAICG\Includes\DependencyLoaders\Automated_Task_Cron_Helpers_Loader;
use WPAICG\Includes\DependencyLoaders\Hook_Registrars_Loader;
use WPAICG\Includes\DependencyLoaders\AI_Forms_Dependencies_Loader;
use WPAICG\Includes\DependencyLoaders\Core_Moderation_Dependencies_Loader;
use WPAICG\Includes\DependencyLoaders\WP_AI_Client_Dependencies_Loader;


// Ensure this file is only loaded by WordPress
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * AIPKit_Dependency_Loader
 * Handles loading all necessary plugin dependencies.
 * Loads core plugin files and then delegates to specialized loader classes.
 */
class AIPKit_Dependency_Loader
{
    /**
     * Load all required dependencies for the plugin.
     */
    public static function load()
    {
        $admin_like_request = self::is_admin_like_request();
        $automation_request = self::is_automation_request();
        // Core Plugin Files (Loaded directly before specialized loaders)
        require_once WPAICG_PLUGIN_DIR . 'public/assets.php';
        require_once WPAICG_PLUGIN_DIR . 'classes/integrations/blocks.php';
        require_once WPAICG_PLUGIN_DIR . 'includes/hooks.php';
        require_once WPAICG_PLUGIN_DIR . 'includes/database-schema.php';
        require_once WPAICG_PLUGIN_DIR . 'classes/seo/manager.php';
        require_once WPAICG_PLUGIN_DIR . 'classes/security/uploads.php';
        $toc_generator_path = WPAICG_PLUGIN_DIR . 'classes/content-writer/toc.php';
        if (file_exists($toc_generator_path)) {
            require_once $toc_generator_path;
        }
        $prompt_sanitizer_path = WPAICG_PLUGIN_DIR . 'classes/ai/prompt-sanitizer.php';
        if (file_exists($prompt_sanitizer_path)) {
            require_once $prompt_sanitizer_path;
        }
        $header_buttons_util_path = WPAICG_PLUGIN_DIR . 'classes/admin/header-actions.php';
        if (file_exists($header_buttons_util_path)) {
            require_once $header_buttons_util_path;
        }
        $cors_manager_path = WPAICG_PLUGIN_DIR . 'classes/security/origins.php';
        if (file_exists($cors_manager_path)) {
            require_once $cors_manager_path;
            // Initialize the CORS manager
            \WPAICG\Utils\AIPKit_CORS_Manager::init();
        }


        // Call specialized loaders
        if ($admin_like_request) {
            Admin_Asset_Handlers_Loader::load();
        }
        Provider_Dependencies_Loader::load();
        Core_Services_Loader::load();
        Dashboard_Base_Classes_Loader::load();
        Base_Ajax_Handlers_Loader::load();
        Chat_Dependencies_Loader::load();
        Speech_Dependencies_Loader::load();
        Stt_Dependencies_Loader::load();
        // REST route registration happens during plugin bootstrap, before REST_REQUEST
        // is reliably defined for the current request.
        Rest_Dependencies_Loader::load();
        Image_Dependencies_Loader::load();
        Vector_Store_Dependencies_Loader::load();
        if ($admin_like_request) {
            Vector_Store_Ajax_Handlers_Loader::load();
            Vector_Post_Processor_Classes_Loader::load();
        }
        if ($admin_like_request || $automation_request) {
            Content_Writer_Dependencies_Loader::load();
        }
        Security_Dependencies_Loader::load();
        require_once WPAICG_PLUGIN_DIR . 'classes/cloud/connection-diagnostics.php';
        require_once WPAICG_PLUGIN_DIR . 'classes/cloud/connection.php';
        require_once WPAICG_PLUGIN_DIR . 'classes/cloud/credit-notice.php';
        if ($admin_like_request) {
            require_once WPAICG_PLUGIN_DIR . 'classes/cloud/announcement.php';
            require_once WPAICG_PLUGIN_DIR . 'classes/admin/onboarding.php';
            Post_Enhancer_Core_Loader::load();
        }
        Woocommerce_Writer_Loader::load();
        if ($admin_like_request || $automation_request) {
            Automated_Task_Dependencies_Loader::load();
            Automated_Task_Cron_Helpers_Loader::load();
        }
        if ($admin_like_request) {
            Automated_Task_Ajax_Handlers_Loader::load();
        }
        Hook_Registrars_Loader::load();
        AI_Forms_Dependencies_Loader::load();
        Core_Moderation_Dependencies_Loader::load();
        WP_AI_Client_Dependencies_Loader::load();
    }

    private static function is_admin_like_request(): bool
    {
        return is_admin() || wp_doing_ajax();
    }

    private static function is_automation_request(): bool
    {
        if (wp_doing_cron()) {
            return true;
        }

        return defined('WP_CLI') && WP_CLI;
    }

}

namespace WPAICG\Includes\DependencyLoaders;

class Admin_Asset_Handlers_Loader
{
    public static function load()
    {
        require_once WPAICG_PLUGIN_DIR . 'classes/admin/assets.php';
    }
}

class Provider_Dependencies_Loader
{
    public static function load()
    {
        require_once WPAICG_PLUGIN_DIR . 'classes/ai/providers/contracts.php';
        require_once WPAICG_PLUGIN_DIR . 'classes/ai/providers/chat-completions.php';

        require_once WPAICG_PLUGIN_DIR . 'classes/ai/providers/openai.php';

        // Load other providers
        require_once WPAICG_PLUGIN_DIR . 'classes/ai/providers/openrouter.php';

        require_once WPAICG_PLUGIN_DIR . 'classes/ai/providers/google.php';

        require_once WPAICG_PLUGIN_DIR . 'classes/ai/providers/azure.php';
        require_once WPAICG_PLUGIN_DIR . 'classes/ai/providers/claude.php';
        require_once WPAICG_PLUGIN_DIR . 'classes/ai/providers/deepseek.php';
        require_once WPAICG_PLUGIN_DIR . 'classes/ai/providers/xai.php';
        require_once WPAICG_PLUGIN_DIR . 'classes/ai/providers/ollama.php';

    }
}

class Core_Services_Loader {
    public static function load() {
        require_once WPAICG_PLUGIN_DIR . 'classes/ai/models.php';
        $token_manager_path = WPAICG_PLUGIN_DIR . 'classes/usage/tokens.php';
        if (file_exists($token_manager_path)) {
            require_once $token_manager_path;
        }
        require_once WPAICG_PLUGIN_DIR . 'classes/ai/http.php';
        require_once WPAICG_PLUGIN_DIR . 'classes/ai/caller.php';
        require_once WPAICG_PLUGIN_DIR . 'classes/ai/reasoning.php';
        require_once WPAICG_PLUGIN_DIR . 'classes/ai/instructions.php';
        require_once WPAICG_PLUGIN_DIR . 'classes/security/moderation.php';
        require_once WPAICG_PLUGIN_DIR . 'classes/ai/payload-sanitizer.php';
        require_once WPAICG_PLUGIN_DIR . 'classes/integrations/events.php';
        require_once WPAICG_PLUGIN_DIR . 'classes/integrations/webhooks.php';
        require_once WPAICG_PLUGIN_DIR . 'classes/integrations/event-delivery.php';

        \WPAICG\Core\AIPKit_Event_Webhooks_Settings::init();
        \WPAICG\Core\AIPKit_Event_Delivery_Policy::register_hooks();
        \WPAICG\Core\AIPKit_Event_Queue_Worker::register_hooks();

        $sse_classes_to_load = [
            WPAICG_PLUGIN_DIR . 'classes/streaming/formatter.php',
            WPAICG_PLUGIN_DIR . 'classes/streaming/cache.php',
            WPAICG_PLUGIN_DIR . 'classes/knowledge-base/retrieval-context.php',
            WPAICG_PLUGIN_DIR . 'classes/chatbot/stream.php',
            WPAICG_PLUGIN_DIR . 'classes/content-writer/stream.php',
            WPAICG_PLUGIN_DIR . 'classes/ai-forms/stream.php',
            WPAICG_PLUGIN_DIR . 'classes/streaming/request.php',
            WPAICG_PLUGIN_DIR . 'classes/streaming/processor.php',
            WPAICG_PLUGIN_DIR . 'classes/streaming/handler.php',
        ];
        foreach ($sse_classes_to_load as $sse_class_file) {
            if (file_exists($sse_class_file)) {
                require_once $sse_class_file;
            }
        }
    }
}

class Dashboard_Base_Classes_Loader {
    public static function load() {
        require_once WPAICG_PLUGIN_DIR . 'classes/ai/settings.php';
        require_once WPAICG_PLUGIN_DIR . 'classes/ai/model-list.php';
        require_once WPAICG_PLUGIN_DIR . 'classes/settings/ai.php';
        require_once WPAICG_PLUGIN_DIR . 'classes/admin/dashboard.php';
        require_once WPAICG_PLUGIN_DIR . 'classes/security/roles.php';
        require_once WPAICG_PLUGIN_DIR . 'classes/usage/statistics.php';
    }
}

class Base_Ajax_Handlers_Loader
{
    public static function load()
    {
        $chat_ajax_support_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/ajax-support.php';
        if (file_exists($chat_ajax_support_path)) {
            require_once $chat_ajax_support_path;
        }

        // Continue loading other base handlers if any
        $base_dashboard_ajax_handler_path = WPAICG_PLUGIN_DIR . 'classes/admin/ajax.php';
        if (file_exists($base_dashboard_ajax_handler_path)) {
            require_once $base_dashboard_ajax_handler_path;
        }

        if (!self::should_load_concrete_handlers()) {
            return;
        }

        $settings_ajax_handler_path = WPAICG_PLUGIN_DIR . 'classes/settings/actions.php';
        if (file_exists($settings_ajax_handler_path)) {
            require_once $settings_ajax_handler_path;
        }
        $event_webhook_delivery_issues_handler_path = WPAICG_PLUGIN_DIR . 'classes/integrations/delivery-actions.php';
        if (file_exists($event_webhook_delivery_issues_handler_path)) {
            require_once $event_webhook_delivery_issues_handler_path;
        }
        $models_ajax_handler_path = WPAICG_PLUGIN_DIR . 'classes/ai/model-actions.php';
        if (file_exists($models_ajax_handler_path)) {
            require_once $models_ajax_handler_path;
        }


        foreach ([
            'classes/knowledge-base/source-actions.php',
            'classes/usage/log-actions.php',
            'classes/usage/pricing-actions.php',
        ] as $handler_file) {
            $handler_path = WPAICG_PLUGIN_DIR . $handler_file;
            if (file_exists($handler_path)) {
                require_once $handler_path;
            }
        }

        $semantic_search_ajax_handler_path = WPAICG_PLUGIN_DIR . 'classes/knowledge-base/search-actions.php';
        if (file_exists($semantic_search_ajax_handler_path)) {
            require_once $semantic_search_ajax_handler_path;
        }
    }

    private static function should_load_concrete_handlers(): bool
    {
        return is_admin() || wp_doing_ajax();
    }
}

class Chat_Dependencies_Loader
{
    public static function load()
    {
        $paths = [
            'classes/chatbot/ai-service.php' => \WPAICG\Chat\Core\AIService::class,
            'classes/chatbot/content-context.php' => \WPAICG\Chat\Core\AIPKit_Content_Aware::class,
            'classes/chatbot/upload-provider.php' => \WPAICG\Chat\Core\AIPKit_Chat_File_Upload_Provider_Resolver::class,
            'classes/chatbot/form-actions.php' => \WPAICG\Chat\Frontend\Ajax\ChatFormSubmissionAjaxHandler::class,
            'classes/chatbot/formatting.php' => \WPAICG\Chat\Utils\Utils::class,
            'classes/chatbot/icons.php' => \WPAICG\Chat\Utils\AIPKit_SVG_Icons::class,
            'classes/chatbot/admin.php' => \WPAICG\Chat\Admin\AdminSetup::class,
            'classes/chatbot/logs.php' => \WPAICG\Chat\Storage\LogStorage::class,
            'classes/chatbot/bots.php' => \WPAICG\Chat\Storage\BotStorage::class,
            'classes/chatbot/assets.php' => \WPAICG\Chat\Frontend\Assets::class,
            'classes/chatbot/shortcode.php' => \WPAICG\Chat\Frontend\Shortcode::class,
            'classes/chatbot/bootstrap.php' => \WPAICG\Chat\Initializer::class,
        ];

        if (self::should_load_ajax_handlers()) {
            $paths += [
                'classes/chatbot/bot-actions.php' => \WPAICG\Chat\Admin\Ajax\ChatbotAjaxHandler::class,
                'classes/chatbot/conversation-actions.php' => \WPAICG\Chat\Admin\Ajax\ConversationAjaxHandler::class,
                'classes/usage/credit-actions.php' => \WPAICG\Chat\Admin\Ajax\UserCreditsAjaxHandler::class,
                'classes/chatbot/image-actions.php' => \WPAICG\Chat\Admin\Ajax\ChatbotImageAjaxHandler::class,
            ];
        }

        foreach ($paths as $file => $class) {
            $full_path = WPAICG_PLUGIN_DIR . $file;
            if (!class_exists($class) && file_exists($full_path)) {
                require_once $full_path;
            }
        }
    }

    private static function should_load_ajax_handlers(): bool
    {
        return is_admin() || wp_doing_ajax();
    }
}

class Speech_Dependencies_Loader
{
    public static function load()
    {
        require_once WPAICG_PLUGIN_DIR . 'classes/speech/text-to-speech.php';
    }
}

class Stt_Dependencies_Loader
{
    public static function load()
    {
        require_once WPAICG_PLUGIN_DIR . 'classes/speech/speech-to-text.php';
    }
}

class Rest_Dependencies_Loader
{
    public static function load()
    {
        $rest_controller_path = WPAICG_PLUGIN_DIR . 'classes/api/rest.php';
        if (file_exists($rest_controller_path)) {
            require_once $rest_controller_path;
        }
    }
}

class Image_Dependencies_Loader
{
    public static function load()
    {
        $images_base_path = WPAICG_PLUGIN_DIR . 'classes/image-generator/';
        $image_settings_ajax_handler_path = $images_base_path . 'settings-actions.php';
        if (file_exists($image_settings_ajax_handler_path)) {
            require_once $image_settings_ajax_handler_path;
        }
        $paths = [
            $images_base_path . 'provider-contracts.php',
            $images_base_path . 'manager.php',
            $images_base_path . 'storage.php',
            $images_base_path . 'stock-connections.php',
            $images_base_path . 'providers.php',
        ];
        foreach ($paths as $full_path) {
            if (file_exists($full_path)) {
                require_once $full_path;
            }
        }
    }
}

class Vector_Store_Dependencies_Loader
{
    public static function load()
    {
        $knowledge_base_path = WPAICG_PLUGIN_DIR . 'classes/knowledge-base/';
        $providers_path = $knowledge_base_path . 'providers/'; // Base path for provider strategies

        // Core vector store classes (interfaces, base classes, factories, main manager)
        $core_paths = [
            $knowledge_base_path . 'provider-contracts.php',
            $knowledge_base_path . 'chunker.php',
            $knowledge_base_path . 'embedding-batches.php',
            $knowledge_base_path . 'ingestion.php',
            $knowledge_base_path . 'google-file-search.php',
            $knowledge_base_path . 'manager.php',
            $knowledge_base_path . 'stores.php',
            $knowledge_base_path . 'indexing-openai.php',
        ];

        foreach ($core_paths as $path) {
            if (file_exists($path)) {
                require_once $path;
            }
        }
        if (class_exists(\WPAICG\Vector\GoogleFileSearch\GoogleFileSearchIngestionService::class)) {
            \WPAICG\Vector\GoogleFileSearch\GoogleFileSearchIngestionService::register_hooks();
        }
        if (class_exists(\WPAICG\Vector\PostProcessor\OpenAI\OpenAIPostProcessor::class)) {
            \WPAICG\Vector\PostProcessor\OpenAI\OpenAIPostProcessor::register_hooks();
        }

        // Provider-specific strategy bootstrap files
        $provider_bootstraps = [
            $providers_path . 'pinecone.php',
            $providers_path . 'qdrant.php',
            $providers_path . 'openai.php',
            $providers_path . 'chroma.php',
        ];

        foreach ($provider_bootstraps as $bootstrap_file) {
            if (file_exists($bootstrap_file)) {
                require_once $bootstrap_file;
            }
        }
    }
}

class Vector_Store_Ajax_Handlers_Loader
{
    public static function load()
    {
        $knowledge_base_path = WPAICG_PLUGIN_DIR . 'classes/knowledge-base/';

        // Load vector store AJAX owners and their shared OpenAI support.
        $module_files = [
            'openai-stores.php',
            'openai-files.php',
            'openai-indexing.php',
            'google-actions.php',
            'pinecone-actions.php',
            'qdrant-actions.php',
            'chroma-actions.php',
            'local-actions.php',
            'openai-support.php',
        ];
        foreach ($module_files as $module_file) {
            $full_path = $knowledge_base_path . $module_file;
            if (file_exists($full_path)) {
                require_once $full_path;
            }
        }
    }
}

class Vector_Post_Processor_Classes_Loader
{
    public static function load()
    {
        $knowledge_base_path = WPAICG_PLUGIN_DIR . 'classes/knowledge-base/';

        // Load the shared primary-content extractor before post processors.
        $content_extractor_path = WPAICG_PLUGIN_DIR . 'classes/knowledge-base/extraction.php';
        if (file_exists($content_extractor_path) && !class_exists(\WPAICG\Vector\Extraction\AIPKit_Knowledge_Content_Extractor::class)) {
            require_once $content_extractor_path;
        }

        // Load the Base Class first
        $base_class_path = WPAICG_PLUGIN_DIR . 'classes/knowledge-base/indexing.php';
        if (file_exists($base_class_path) && !class_exists(\WPAICG\Vector\PostProcessor\Base\AIPKit_Vector_Post_Processor_Base::class)) {
            require_once $base_class_path;
        }

        // Load each provider's owner in dependency order.
        $provider_modules = [
            'indexing-openai.php',
            'indexing-google.php',
            'indexing-pinecone.php',
            'indexing-qdrant.php',
            'indexing-chroma.php',
        ];
        foreach ($provider_modules as $provider_module) {
            $module_path = $knowledge_base_path . $provider_module;
            if (file_exists($module_path)) {
                require_once $module_path;
            }
        }

        // Load the main AJAX Handler (which uses the above processors)
        $ajax_handler_path = WPAICG_PLUGIN_DIR . 'classes/knowledge-base/indexing-actions.php';
        if (file_exists($ajax_handler_path) && !class_exists(\WPAICG\Vector\AIPKit_Vector_Post_Processor_Ajax_Handler::class)) {
            require_once $ajax_handler_path;
        }

        // Load the List Screen class (handles post list screen features)
        $list_screen_path = WPAICG_PLUGIN_DIR . 'classes/knowledge-base/post-list.php';
        if (file_exists($list_screen_path) && !class_exists(\WPAICG\Vector\PostProcessor\AIPKit_Vector_Post_Processor_List_Screen::class)) {
            require_once $list_screen_path;
        }
    }
}

class Content_Writer_Dependencies_Loader
{
    public static function load()
    {
        $content_writer_base_path = WPAICG_PLUGIN_DIR . 'classes/content-writer/';
        $files_to_load = [
            $content_writer_base_path . 'actions.php',
            $content_writer_base_path . 'seo.php',
            $content_writer_base_path . 'prompts.php',
            $content_writer_base_path . 'image-options.php',
            $content_writer_base_path . 'output.php',
            $content_writer_base_path . 'blocks.php',
            $content_writer_base_path . 'prompt-library.php',
            $content_writer_base_path . 'templates.php',
            $content_writer_base_path . 'template-actions.php',
            $content_writer_base_path . 'prompt-actions.php',
            $content_writer_base_path . 'images.php',
            $content_writer_base_path . 'image-placement.php',
        ];
        foreach ($files_to_load as $full_path) {
            if (file_exists($full_path)) {
                require_once $full_path;
            }
        }
    }
}

class Security_Dependencies_Loader
{
    public static function load()
    {
        require_once WPAICG_PLUGIN_DIR . 'classes/security/ip-anonymization.php';
    }
}

class Post_Enhancer_Core_Loader
{
    public static function load()
    {
        $post_enhancer_core_path = WPAICG_PLUGIN_DIR . 'classes/post-enhancer/bootstrap.php';
        $post_enhancer_ajax_path = WPAICG_PLUGIN_DIR . 'classes/post-enhancer/actions.php';

        if (file_exists($post_enhancer_core_path)) {
            require_once $post_enhancer_core_path;
        }

        if (file_exists($post_enhancer_ajax_path)) {
            require_once $post_enhancer_ajax_path;
        }

    }
}

class Woocommerce_Writer_Loader
{
    /**
     * Registers an action to initialize integrations after all plugins are loaded.
     */
    public static function load()
    {
        add_action('plugins_loaded', [__CLASS__, 'init_integrations']);
    }

    /**
     * Initializes WooCommerce-dependent features after ensuring WooCommerce is active.
     */
    public static function init_integrations()
    {
        // Only load any of this if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            return;
        }

        $woo_integration_path = WPAICG_PLUGIN_DIR . 'classes/integrations/woocommerce.php';
        if (file_exists($woo_integration_path)) {
            require_once $woo_integration_path;

            // Get the singleton instance to register hooks
            \WPAICG\WooCommerce\AIPKit_WooCommerce_Integration::get_instance();
        }
    }
}

class Automated_Task_Dependencies_Loader
{
    public static function load()
    {
        $automations_path = WPAICG_PLUGIN_DIR . 'classes/automations/';
        $main_manager_path = $automations_path . 'tasks.php';
        $main_cron_path = $automations_path . 'cron.php';
        $prompt_definitions_path = $automations_path . 'prompts.php';

        if (file_exists($main_manager_path)) {
            require_once $main_manager_path;
        }

        if (file_exists($main_cron_path)) {
            require_once $main_cron_path;
        }

        if (file_exists($prompt_definitions_path)) {
            require_once $prompt_definitions_path;
        }
    }
}

class Automated_Task_Ajax_Handlers_Loader
{
    public static function load()
    {
        require_once WPAICG_PLUGIN_DIR . 'classes/automations/actions.php';
    }
}

class Automated_Task_Cron_Helpers_Loader
{
    public static function load()
    {
        $cron_base_path = WPAICG_PLUGIN_DIR . 'classes/automations/';
        $cron_helpers = [
            'lock.php',
            'scheduler.php',
            'queue.php',
            'events.php',
            'runner.php',
            'server-cron.php',
        ];
        foreach ($cron_helpers as $file) {
            $full_path = $cron_base_path . $file;
            if (file_exists($full_path)) {
                require_once $full_path;
            }
        }
    }
}

/**
 * AIPKit_Hook_Registrars_Loader
 * Handles loading all the hook registrar classes.
 */
class Hook_Registrars_Loader {

    public static function load() {
        $hooks_path = WPAICG_PLUGIN_DIR . 'includes/hooks.php';
        if (file_exists($hooks_path)) {
            require_once $hooks_path;
        }
    }
}

/**
 * AIPKit_AI_Forms_Dependencies_Loader
 * Handles loading all necessary PHP class dependencies for the AI Forms module.
 */
class AI_Forms_Dependencies_Loader
{
    public static function load()
    {
        $ai_forms_base_path = WPAICG_PLUGIN_DIR . 'classes/ai-forms/';

        // Define paths to AI Forms classes
        $paths = [
            'admin.php',
            'settings-actions.php',
            'shortcode.php',
            'processor.php',
            'storage.php',
            'bootstrap.php',
        ];

        if (self::should_load_admin_ajax_handlers()) {
            $paths[] = 'actions.php';
        }

        foreach ($paths as $file) {
            $full_path = $ai_forms_base_path . $file;
            if (file_exists($full_path)) {
                require_once $full_path;
            }
        }
    }

    private static function should_load_admin_ajax_handlers(): bool
    {
        return is_admin() || wp_doing_ajax();
    }
}

/**
 * AIPKit_Core_Moderation_Dependencies_Loader
 * Handles loading core moderation classes.
 */
class Core_Moderation_Dependencies_Loader {

    public static function load() {
        require_once WPAICG_PLUGIN_DIR . 'classes/security/moderation.php';
    }
}

class WP_AI_Client_Dependencies_Loader
{
    public static function load(): void
    {
        // Early PHP 7.4 builds have a covariant return compile bug that the WP AI Client DTOs trigger.
        if (PHP_VERSION_ID < 70412) {
            return;
        }

        $base_path = WPAICG_PLUGIN_DIR . 'classes/integrations/';

        $settings_path = $base_path . 'wordpress-ai-settings.php';
        if (file_exists($settings_path)) {
            require_once $settings_path;
        }

        if (!class_exists(\WPAICG\WP_AI_Client\AIPKit_WP_AI_Client_Settings::class)
            || !\WPAICG\WP_AI_Client\AIPKit_WP_AI_Client_Settings::is_supported()
        ) {
            return;
        }

        $classes_path = $base_path . 'wordpress-ai-client.php';
        if (file_exists($classes_path)) {
            require_once $classes_path;
        }

        if (class_exists(\WPAICG\WP_AI_Client\AIPKit_WP_AI_Client_Integration::class)) {
            \WPAICG\WP_AI_Client\AIPKit_WP_AI_Client_Integration::register_hooks();
        }
    }
}
