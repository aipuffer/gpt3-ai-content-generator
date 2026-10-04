<?php
/**
 * Chatbot asset requirements, registration and conditional enqueueing.
 * Paid requirements are supplied by lib/chatbot/frontend.php.
 */

namespace WPAICG\Chat\Frontend\Assets;

use WPAICG\aipkit_dashboard;
use WPAICG\Lib\Chat\AssetRequirements;
use WPAICG\Chat\Frontend\Assets as AssetsOrchestrator;
use WPAICG\Chat\Frontend\Shortcode\FeatureManager;
use WPAICG\Chat\Storage\BotSettingsManager;
use WPAICG\Chat\Storage\BotStorage;
use WPAICG\Chat\Storage\SiteWideBotManager;
use WPAICG\Includes\AIPKit_Shared_Assets_Manager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages the registration of WordPress action hooks for chat assets.
 */
class AssetsHooks {
    private $orchestrator;

    public function __construct(AssetsOrchestrator $orchestrator) {
        $this->orchestrator = $orchestrator;
    }

    /**
     * Registers the necessary WordPress hooks.
     */
    public function register(): void {
        // Note: The orchestrator's methods are now public wrappers.
        add_action('wp_enqueue_scripts', [$this->orchestrator, 'register_and_enqueue_frontend_assets_public_wrapper'], 99);
        add_action('template_redirect', [$this->orchestrator, 'check_for_site_wide_bot_public_wrapper']);
    }
}

/**
 * Checks for a site-wide bot and sets relevant static flags in the AssetsOrchestrator.
 */
class AssetsSiteWideChecker {

    public function __construct() {
    }

    /**
     * Checks for a site-wide bot and updates static flags in AssetsOrchestrator.
     */
    public function check(): void {
        if (is_admin() || wp_doing_ajax()) return;

        // Ensure required classes are available
        if (!class_exists(SiteWideBotManager::class) ||
            !class_exists(aipkit_dashboard::class) ||
            !class_exists(BotStorage::class) ||
            !class_exists(BotSettingsManager::class)) {
            return;
        }

        $manager = new SiteWideBotManager();
        $bot_id = $manager->get_site_wide_bot_id();

        if ($bot_id) {
            AssetsOrchestrator::$site_wide_injection_needed = true;

            $bot_storage = new BotStorage();
            $settings = $bot_storage->get_chatbot_settings($bot_id);
            if (!class_exists(FeatureManager::class)) {
                $feature_manager_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/shortcode.php';
                if (file_exists($feature_manager_path)) {
                    require_once $feature_manager_path;
                }
            }

            $feature_flags = class_exists(FeatureManager::class)
                ? FeatureManager::determine_flags($settings)
                : [];

            AssetsOrchestrator::$consent_needed = true;

            if (!empty($feature_flags['enable_copy_button'])) AssetsOrchestrator::$copy_button_needed = true;
            if (!empty($feature_flags['feedback_ui_enabled'])) AssetsOrchestrator::$feedback_needed = true;
            if (!empty($feature_flags['starters_ui_enabled'])) AssetsOrchestrator::$starters_needed = true;
            if (!empty($feature_flags['sidebar_ui_enabled'])) AssetsOrchestrator::$sidebar_needed = true;
            if (!empty($feature_flags['tts_ui_enabled'])) AssetsOrchestrator::$tts_needed = true;
            if (!empty($feature_flags['enable_voice_input_ui'])) AssetsOrchestrator::$stt_needed = true;

            AssetsOrchestrator::$image_gen_needed = true;

            if (!empty($feature_flags['image_upload_ui_enabled'])) {
                AssetsOrchestrator::$chat_image_upload_needed = true;
            }
            if (class_exists(AssetRequirements::class)) {
                AssetRequirements::set_flags(
                    !empty($feature_flags['file_upload_ui_enabled']),
                    !empty($feature_flags['enable_realtime_voice_ui'])
                );
            }
        }
    }
}

/**
 * Handles setting the static flags in AssetsOrchestrator when assets are required.
 */
class AssetsRequireFlags {

    /**
     * Signals that assets are needed, and potentially specific features, updating static flags.
     *
     * @param bool $needs_pdf
     * @param bool $needs_copy
     * @param bool $needs_starters
     * @param bool $needs_sidebar
     * @param bool $needs_feedback
     * @param bool $needs_tts
     * @param bool $needs_stt
     * @param bool $needs_image_gen
     * @param bool $needs_chat_image_upload
     * @param bool $needs_chat_file_upload
     * @param bool $needs_realtime_voice
     */
    public static function set_flags(
        bool $needs_pdf = false, bool $needs_copy = false, bool $needs_starters = false,
        bool $needs_sidebar = false, bool $needs_feedback = false, bool $needs_tts = false,
        bool $needs_stt = false, bool $needs_image_gen = false, bool $needs_chat_image_upload = false,
        bool $needs_chat_file_upload = false, bool $needs_realtime_voice = false
    ): void {
        // Ensure aipkit_dashboard is available for addon checks
        if (!class_exists(aipkit_dashboard::class)) {
            // Attempt to load it if not found, to prevent fatal errors
            $dashboard_path = defined('WPAICG_PLUGIN_DIR') ? WPAICG_PLUGIN_DIR . 'classes/admin/dashboard.php' : null;
            if ($dashboard_path && file_exists($dashboard_path)) {
                require_once $dashboard_path;
            } else {
                 // Return early or set defaults if dashboard class is critical and missing
                 // For now, we'll proceed with a warning if class_exists check fails after attempt.
                 if (!class_exists(aipkit_dashboard::class)) {
                     return;
                 }
            }
        }


        AssetsOrchestrator::$shortcode_rendered = true;
        AssetsOrchestrator::$consent_needed = true;

        if ($needs_copy) AssetsOrchestrator::$copy_button_needed = true;
        if ($needs_feedback) AssetsOrchestrator::$feedback_needed = true;
        if ($needs_starters) AssetsOrchestrator::$starters_needed = true;
        if ($needs_sidebar) AssetsOrchestrator::$sidebar_needed = true;
        if ($needs_tts) AssetsOrchestrator::$tts_needed = true;
        if ($needs_stt) AssetsOrchestrator::$stt_needed = true;
        if ($needs_image_gen) AssetsOrchestrator::$image_gen_needed = true;
        if ($needs_chat_image_upload) AssetsOrchestrator::$chat_image_upload_needed = true;
        if (class_exists(AssetRequirements::class)) {
            AssetRequirements::set_flags($needs_chat_file_upload, $needs_realtime_voice);
        }
    }
}

/**
 * Handles the actual enqueuing of frontend assets based on set flags.
 * Enqueues public-main.bundle.js and public-main.bundle.css.
 * Optional chat features such as PDF download are lazy-loaded via asset URLs.
 */
class AssetsEnqueuer
{
    private $is_public_main_css_enqueued = false;

    public function __construct()
    {
    }

    public function process_assets(): void
    {
        if (is_admin()) {
            return;
        }

        // Ensure dependencies are registered if they haven't been yet.
        if (!AssetsOrchestrator::$assets_registered) {
            AssetsDependencyRegistrar::register();
            AssetsOrchestrator::$assets_registered = true;
        }

        global $post;
        $content = is_a($post, 'WP_Post') ? $post->post_content : '';
        $found_in_content = has_shortcode($content, 'aipkit_chatbot') || has_block('aipkit/chatbot', $content);

        $should_enqueue_core_css = AssetsOrchestrator::$shortcode_rendered || AssetsOrchestrator::$site_wide_injection_needed || $found_in_content;
        $should_enqueue_core_js = $should_enqueue_core_css; // JS likely needed if CSS is

        // Main public CSS bundle (dist/css/public-main.bundle.css)
        $public_main_css_handle = 'aipkit-public-main-css';
        if (!wp_style_is($public_main_css_handle, 'registered')) {
            $public_main_css_file = WPAICG_PLUGIN_DIR . 'dist/css/public-main.bundle.css';
            $public_main_css_version = file_exists($public_main_css_file)
                ? (string) filemtime($public_main_css_file)
                : (defined('WPAICG_VERSION') ? WPAICG_VERSION : '1.0.0');
            wp_register_style(
                $public_main_css_handle,
                WPAICG_PLUGIN_URL . 'dist/css/public-main.bundle.css',
                [],
                $public_main_css_version
            );
        }
        if ($should_enqueue_core_css && !$this->is_public_main_css_enqueued && !wp_style_is($public_main_css_handle, 'enqueued')) {
            wp_enqueue_style($public_main_css_handle);
            $this->is_public_main_css_enqueued = true;
        }

        $sidebar_css_handle = 'aipkit-public-chat-sidebar-css';
        if ($should_enqueue_core_css && AssetsOrchestrator::$sidebar_needed) {
            if (!wp_style_is($sidebar_css_handle, 'registered')) {
                wp_register_style(
                    $sidebar_css_handle,
                    WPAICG_PLUGIN_URL . 'dist/css/public-chat-sidebar.bundle.css',
                    [$public_main_css_handle],
                    defined('WPAICG_VERSION') ? WPAICG_VERSION : '1.0.0'
                );
            }
            wp_enqueue_style($sidebar_css_handle);
        }


        // Main public JS bundle (dist/js/public-main.bundle.js)
        $public_main_js_handle = 'aipkit-public-main';
        // Registration is now handled by AssetsDependencyRegistrar and potentially modified by lib/wpaicg__premium_only.php
        // So, just check if it's registered before trying to enqueue.
        if (!wp_script_is($public_main_js_handle, 'registered')) {
            // Fallback registration if somehow missed.
            wp_register_script(
                $public_main_js_handle,
                WPAICG_PLUGIN_URL . 'dist/js/public-main.bundle.js',
                [],
                defined('WPAICG_VERSION') ? WPAICG_VERSION : '1.0.0',
                true
            );
        }

        if ($should_enqueue_core_js) {
            if (!wp_script_is($public_main_js_handle, 'enqueued')) {
                wp_enqueue_script($public_main_js_handle);
            }
            if (class_exists(AIPKit_Shared_Assets_Manager::class)) {
                AIPKit_Shared_Assets_Manager::attach_public_asset_urls($public_main_js_handle);
            }

            if (AssetsOrchestrator::$sidebar_needed && wp_script_is('aipkit-public-chat-sidebar', 'registered')) {
                if (!wp_script_is('aipkit-public-chat-sidebar', 'enqueued')) {
                    wp_enqueue_script('aipkit-public-chat-sidebar');
                }
            }


            // Global localization (if not already done by WP_AI_Content_Generator_Public or Shortcodes_Manager)
            // This is for general chat UI texts that are always needed if chat is active.
            static $global_chat_localized = false;
            if (!$global_chat_localized && wp_script_is($public_main_js_handle, 'enqueued')) {
                wp_localize_script($public_main_js_handle, 'aipkit_chat_config_global', [
                    'text' => [
                        'fullscreenError' => __('Error: Fullscreen functionality is unavailable.', 'gpt3-ai-content-generator'),
                        'copySuccess' => __('Copied!', 'gpt3-ai-content-generator'), 'copyFail' => __('Failed to copy', 'gpt3-ai-content-generator'),
                        'copyActionLabel' => __('Copy response', 'gpt3-ai-content-generator'),
                        'feedbackSubmitted' => __('Feedback submitted', 'gpt3-ai-content-generator'), 'feedbackError' => __('Error saving feedback', 'gpt3-ai-content-generator'),
                        'newChat' => __('New Chat', 'gpt3-ai-content-generator'), 'sidebarToggle' => __('Toggle Conversation Sidebar', 'gpt3-ai-content-generator'),
                        'historyGuests' => __('History unavailable for guests.', 'gpt3-ai-content-generator'), 'historyEmpty' => __('No past conversations.', 'gpt3-ai-content-generator'),
                        'playActionLabel' => __('Play audio', 'gpt3-ai-content-generator'),
                        'pauseActionLabel' => __('Pause audio', 'gpt3-ai-content-generator'),
                        'uploadFile' => __('Upload File (TXT, PDF)', 'gpt3-ai-content-generator'),
                        /* translators: %s: Uploaded file name. */
                        'fileUploading' => __('Uploading %s', 'gpt3-ai-content-generator'),
                        /* translators: %s: Uploaded file name. */
                        'fileIndexing' => __('Indexing %s', 'gpt3-ai-content-generator'),
                        'detachFile' => __('Detach file', 'gpt3-ai-content-generator'),
                    ]
                ]);
                // Provide a consistent action name for nonce refresh (used by JS auto-retry)
                // No security risk: this just exposes the action string, not the nonce value itself.
                wp_add_inline_script(
                    $public_main_js_handle,
                    'window.aipkit_getChatNonceAction = "aipkit_get_frontend_chat_nonce";',
                    'before'
                );
                $global_chat_localized = true;
            }

            // Inject a tiny runtime wrapper to auto-refresh nonce once on nonce-related failures
            static $nonce_wrapper_injected = false;
            if (!$nonce_wrapper_injected && wp_script_is($public_main_js_handle, 'enqueued')) {
                $wrapper_js = <<<'JS'
;(function(){
  try{
    if(typeof window.aipkit_frontendApiRequest === 'function'){
      var __aipkit_origFrontendApiRequest = window.aipkit_frontendApiRequest;
      function __aipkit_refreshNonce(cfg){
        return new Promise(function(resolve,reject){
          try{
            if(!cfg || !cfg.ajaxUrl){ return reject(new Error('No ajaxUrl for nonce refresh')); }
            var fd = new FormData();
            fd.append('action', (typeof window.aipkit_getChatNonceAction === 'string' && window.aipkit_getChatNonceAction) ? window.aipkit_getChatNonceAction : 'aipkit_get_frontend_chat_nonce');
            if(cfg.botId){ fd.append('bot_id', cfg.botId); }
            fetch(cfg.ajaxUrl, { method:'POST', body: fd, credentials:'same-origin' })
              .then(function(r){ return r.json(); })
              .then(function(j){ if(j && j.success && j.data && j.data.nonce){ cfg.nonce = j.data.nonce; resolve(j.data.nonce); } else { reject(new Error('Nonce refresh failed')); } })
              .catch(function(){ reject(new Error('Nonce refresh network error')); });
          }catch(e){ reject(e); }
        });
      }
      window.aipkit_frontendApiRequest = function(action, data, cfg){
        return __aipkit_origFrontendApiRequest(action, data, cfg).catch(function(err){
          var msg = (err && err.message ? String(err.message) : '').toLowerCase();
          if(msg.indexOf('security check failed') !== -1 || msg.indexOf('session has expired') !== -1 || msg.indexOf('nonce') !== -1){
            return __aipkit_refreshNonce(cfg).then(function(){ return __aipkit_origFrontendApiRequest(action, data, cfg); });
          }
          throw err;
        });
      };
    }
  }catch(e){ /* noop */ }
})();
;(function(){
  try{
    if(typeof window.aipkit_chatUI_cacheSseMessage === 'function'){
      var __aipkit_origCacheSseMessage = window.aipkit_chatUI_cacheSseMessage;
      function __aipkit_refreshNonce(cfg){
        return new Promise(function(resolve,reject){
          try{
            if(!cfg || !cfg.ajaxUrl){ return reject(new Error('No ajaxUrl for nonce refresh')); }
            var fd = new FormData();
            fd.append('action', (typeof window.aipkit_getChatNonceAction === 'string' && window.aipkit_getChatNonceAction) ? window.aipkit_getChatNonceAction : 'aipkit_get_frontend_chat_nonce');
            if(cfg.botId){ fd.append('bot_id', cfg.botId); }
            fetch(cfg.ajaxUrl, { method:'POST', body: fd, credentials:'same-origin' })
              .then(function(r){ return r.json(); })
              .then(function(j){ if(j && j.success && j.data && j.data.nonce){ cfg.nonce = j.data.nonce; resolve(j.data.nonce); } else { reject(new Error('Nonce refresh failed')); } })
              .catch(function(){ reject(new Error('Nonce refresh network error')); });
          }catch(e){ reject(e); }
        });
      }
      window.aipkit_chatUI_cacheSseMessage = function(userText, cfg, imageDataPayload, activeFileContext, clientUserMessageId, streamOptions){
        return __aipkit_origCacheSseMessage(userText, cfg, imageDataPayload, activeFileContext, clientUserMessageId, streamOptions).catch(function(err){
          var msg = (err && err.message ? String(err.message) : '').toLowerCase();
          if(msg.indexOf('security check failed') !== -1 || msg.indexOf('session has expired') !== -1 || msg.indexOf('nonce') !== -1){
            return __aipkit_refreshNonce(cfg).then(function(){
              return __aipkit_origCacheSseMessage(userText, cfg, imageDataPayload, activeFileContext, clientUserMessageId, streamOptions);
            });
          }
          throw err;
        });
      };
    }
  }catch(e){ /* noop */ }
})();
JS;
                wp_add_inline_script($public_main_js_handle, $wrapper_js, 'after');
                $nonce_wrapper_injected = true;
            }
        }
    }
}

/**
 * Handles the registration of all public chat JavaScript dependencies.
 */
class AssetsDependencyRegistrar
{
    public static function register(): void
    {
        $version = defined('WPAICG_VERSION') ? WPAICG_VERSION : '1.0.0';
        $plugin_base_url = defined('WPAICG_PLUGIN_URL') ? WPAICG_PLUGIN_URL : plugin_dir_url(dirname(__DIR__, 2) . '/gpt3-ai-content-generator.php');
        $dist_js_url = $plugin_base_url . 'dist/js/';

        // Main public bundle (contains chat frontend JS only).
        // wp-i18n is no longer needed here because the chat runtime does not call wp.i18n.
        $public_main_js_handle = 'aipkit-public-main';
        if (!wp_script_is($public_main_js_handle, 'registered')) {
            wp_register_script(
                $public_main_js_handle,
                $dist_js_url . 'public-main.bundle.js',
                [],
                $version,
                true
            );
        }

        $public_chat_sidebar_js_handle = 'aipkit-public-chat-sidebar';
        if (!wp_script_is($public_chat_sidebar_js_handle, 'registered')) {
            wp_register_script(
                $public_chat_sidebar_js_handle,
                $dist_js_url . 'public-chat-sidebar.bundle.js',
                [$public_main_js_handle],
                $version,
                true
            );
        }
    }
}

namespace WPAICG\Chat\Frontend;

use WPAICG\Chat\Frontend\Assets\AssetsHooks;
use WPAICG\Chat\Frontend\Assets\AssetsSiteWideChecker;
use WPAICG\Chat\Frontend\Assets\AssetsRequireFlags;
use WPAICG\Chat\Frontend\Assets\AssetsEnqueuer;
use WPAICG\Chat\Frontend\Assets\AssetsDependencyRegistrar;

/**
 * Orchestrates frontend asset (CSS/JS) management for the Chatbot.
 * Delegates specific tasks to sub-component classes.
 * Holds static flags for asset requirements.
 */
class Assets {

    // --- Static Flags to track asset requirements ---
    public static $assets_registered = false;
    public static $shortcode_rendered = false;
    public static $site_wide_injection_needed = false;
    public static $copy_button_needed = false;
    public static $feedback_needed = false;
    public static $starters_needed = false;
    public static $sidebar_needed = false;
    public static $tts_needed = false;
    public static $consent_needed = false;
    public static $stt_needed = false;
    public static $image_gen_needed = false;
    public static $chat_image_upload_needed = false;
    public static $chat_file_upload_needed = false;
    public static $realtime_voice_needed = false;
    public static $is_embed = false; // Flag for embed mode
    // --- End Static Flags ---

    private $hooks_handler;
    private $sitewide_checker_handler;
    private $enqueuer_handler;
    // DependencyRegistrar and RequireFlags only have static methods, no instantiation needed for them here.

    public function __construct() {
        // Instantiate handlers that are called via instance methods
        $this->hooks_handler = new AssetsHooks($this);
        $this->sitewide_checker_handler = new AssetsSiteWideChecker();
        $this->enqueuer_handler = new AssetsEnqueuer();
    }

    /**
     * Registers hooks. Delegates to AssetsHooks.
     */
    public function register_hooks() {
        $this->hooks_handler->register();
    }

    /**
     * Checks for site-wide bot and sets relevant flags. Delegates to AssetsSiteWideChecker.
     * This method is public to be callable from the AssetsHooks context (template_redirect hook).
     */
    public function check_for_site_wide_bot_public_wrapper() {
        $this->sitewide_checker_handler->check();
    }

    /**
     * Signals that assets are needed, potentially with specific feature flags.
     * Delegates to AssetsRequireFlags.
     *
     * @param bool $needs_pdf
     * @param bool $needs_copy
     * @param bool $needs_starters
     * @param bool $needs_sidebar
     * @param bool $needs_feedback
     * @param bool $needs_tts
     * @param bool $needs_stt
     * @param bool $needs_image_gen
     * @param bool $needs_chat_image_upload
     * @param bool $needs_chat_file_upload
     * @param bool $needs_realtime_voice
     */
    public static function require_assets(
        bool $needs_pdf = false, bool $needs_copy = false, bool $needs_starters = false,
        bool $needs_sidebar = false, bool $needs_feedback = false, bool $needs_tts = false,
        bool $needs_stt = false, bool $needs_image_gen = false, bool $needs_chat_image_upload = false,
        bool $needs_chat_file_upload = false, bool $needs_realtime_voice = false
    ) {
        AssetsRequireFlags::set_flags(
            $needs_pdf, $needs_copy, $needs_starters, $needs_sidebar,
            $needs_feedback, $needs_tts, $needs_stt, $needs_image_gen, $needs_chat_image_upload,
            $needs_chat_file_upload, $needs_realtime_voice
        );
    }

    /**
     * Registers and conditionally enqueues frontend assets. Delegates to AssetsEnqueuer.
     * This method is public to be callable from the AssetsHooks context (wp_enqueue_scripts hook).
     */
    public function register_and_enqueue_frontend_assets_public_wrapper() {
        $this->enqueuer_handler->process_assets();
    }

    /**
     * Registers all public chat JavaScript dependencies.
     * Delegates to AssetsDependencyRegistrar.
     */
    public static function register_public_chat_dependencies() {
        AssetsDependencyRegistrar::register();
    }
}
