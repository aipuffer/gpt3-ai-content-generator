<?php

namespace WPAICG\Admin\Assets;

use WPAICG\aipkit_dashboard;
use WPAICG\AIPKit_Providers;
use WPAICG\AIPKit_Role_Manager;
use WPAICG\AIPKIT_AI_Settings;
use WPAICG\AutoGPT\Helpers\AIPKit_AutoGPT_Prompt_Definitions;
use WPAICG\Chat\Frontend\Assets as ChatFrontendAssetsOrchestrator;
use WPAICG\ContentWriter\AIPKit_Content_Writer_Prompts;
use WPAICG\Includes\AIPKit_Shared_Assets_Manager;
use WPAICG\PostEnhancer\Ajax\AIPKit_Enhancer_Actions_Ajax_Handler;
use WPAICG\Utils\AIPKit_Admin_Header_Action_Buttons;

if (! defined('ABSPATH')) {
    exit;
}

abstract class AIPKit_Admin_Asset_Base
{
    protected $version;

    public function __construct()
    {
        $this->version = self::plugin_version();
    }

    protected static function plugin_version(): string
    {
        return defined('WPAICG_VERSION') ? (string) WPAICG_VERSION : '1.9.15';
    }

    protected static function asset_version(string $relative_path, ?string $fallback = null): string
    {
        $fallback = $fallback ?: self::plugin_version();
        $full_path = WPAICG_PLUGIN_DIR . ltrim($relative_path, '/');

        if (file_exists($full_path)) {
            $mtime = filemtime($full_path);
            if (is_int($mtime) && $mtime > 0) {
                return (string) $mtime;
            }
        }

        return $fallback;
    }

    protected static function script_deps(): array
    {
        return ['wp-i18n', 'aipkit_markdown-it'];
    }

    protected function is_aipkit_page($screen = null): bool
    {
        $screen = $screen ?: get_current_screen();

        return $screen && strpos($screen->id, 'page_wpaicg') !== false;
    }

    protected function ensure_shared_vendor_assets(): void
    {
        if (class_exists(AIPKit_Shared_Assets_Manager::class)) {
            AIPKit_Shared_Assets_Manager::register($this->version);
        }
    }

    protected function register_style_bundle(string $handle, string $file, array $deps = ['dashicons'], ?string $version = null): void
    {
        if (! wp_style_is($handle, 'registered')) {
            wp_register_style(
                $handle,
                WPAICG_PLUGIN_URL . 'dist/css/' . ltrim($file, '/'),
                $deps,
                $version ?: $this->version
            );
        }
    }

    protected function enqueue_style_handle(string $handle): void
    {
        if (! wp_style_is($handle, 'enqueued')) {
            wp_enqueue_style($handle);
        }
    }

    protected function register_script_bundle(string $handle, string $file, array $deps = [], ?string $version = null): void
    {
        $this->ensure_shared_vendor_assets();

        if (! wp_script_is($handle, 'registered')) {
            wp_register_script(
                $handle,
                WPAICG_PLUGIN_URL . 'dist/js/' . ltrim($file, '/'),
                ! empty($deps) ? $deps : self::script_deps(),
                $version ?: $this->version,
                true
            );
        }
    }

    protected function enqueue_script_handle(string $handle, bool $translations = true): void
    {
        if (! wp_script_is($handle, 'enqueued')) {
            wp_enqueue_script($handle);

            if ($translations) {
                wp_set_script_translations($handle, 'gpt3-ai-content-generator', WPAICG_PLUGIN_DIR . 'languages');
            }
        }
    }

    protected function register_admin_main_css(?string $version = null): void
    {
        $version = $version ?: self::asset_version(
            'dist/css/admin-main.bundle.css',
            $this->version
        );
        $this->register_style_bundle('aipkit-admin-main-css', 'admin-main.bundle.css', ['dashicons', 'aipkit-shared-model-selector'], $version);
    }

    protected function enqueue_admin_main_css(?string $version = null): void
    {
        $this->register_admin_main_css($version);
        $this->enqueue_style_handle('aipkit-admin-main-css');
    }

    protected function register_admin_main_script(?string $version = null): void
    {
        $version = $version ?: self::asset_version(
            'dist/js/admin-main.bundle.js',
            $this->version
        );
        $this->register_script_bundle('aipkit-admin-main', 'admin-main.bundle.js', array_merge(self::script_deps(), ['aipkit-shared-model-selector']), $version);
    }

    protected function enqueue_admin_main_script(?string $version = null): void
    {
        $this->register_admin_main_script($version);
        $this->enqueue_script_handle('aipkit-admin-main');
    }

    protected function register_public_main_script(?string $version = null): void
    {
        $this->register_script_bundle('aipkit-public-main', 'public-main.bundle.js', self::script_deps(), $version);
    }

    protected function enqueue_public_main_script(?string $version = null): void
    {
        $this->register_public_main_script($version);
        $this->enqueue_script_handle('aipkit-public-main');
        if (class_exists(AIPKit_Shared_Assets_Manager::class)) {
            AIPKit_Shared_Assets_Manager::attach_public_asset_urls('aipkit-public-main');
        }
    }

    protected function ensure_dashboard_core_data(): void
    {
        if (class_exists(DashboardAssets::class) && method_exists(DashboardAssets::class, 'localize_core_data')) {
            DashboardAssets::localize_core_data($this->version);
        }
    }

    protected static function is_script_localized(string $handle, string $object_name): bool
    {
        $data = wp_scripts()->get_data($handle, 'data');

        return is_string($data) && strpos($data, "var {$object_name} =") !== false;
    }

    protected static function dashboard_texts(): array
    {
        return [
            // General / Loader
            /* translators: %s is the name of the module being loaded */
            'loading'                   => __('Loading %s...', 'gpt3-ai-content-generator'),
            'errorLoadingModuleTitle'   => __('Error Loading Module', 'gpt3-ai-content-generator'),
            /* translators: %s is the name of the module that failed to load */
            'errorLoadingModuleMsg'     => __('An error occurred while loading the \'%s\' module. Please try again later or check the browser console for details.', 'gpt3-ai-content-generator'),
            'errorDetails'              => __('Details:', 'gpt3-ai-content-generator'),
            'noKnowledgeBasesFound' => __('No knowledge bases found. Click "Add Content" to create one.', 'gpt3-ai-content-generator'),
            // Chat Preview Placeholders
            'previewPlaceholderSelect'  => __('Select a bot to see the preview.', 'gpt3-ai-content-generator'),
            'previewPlaceholderCreate'  => __('Configure the new bot and save it to see the preview.', 'gpt3-ai-content-generator'),
            'previewPlaceholderSettings' => __('Preview is not applicable for Chat Settings.', 'gpt3-ai-content-generator'),
            'previewPlaceholderLogs'    => __('Preview is not applicable for Chat Logs.', 'gpt3-ai-content-generator'),
            'noBotsPlaceholder'         => __('No chatbots found. Create one to get started!', 'gpt3-ai-content-generator'),
            // Logs Viewer
            'logViewTitle'              => __('Chat Log Details', 'gpt3-ai-content-generator'),
            'logLoading'                => __('Loading log details...', 'gpt3-ai-content-generator'),
            'logErrorLoading'           => __('Error loading log details.', 'gpt3-ai-content-generator'),
            'logBotLabel'               => __('Bot', 'gpt3-ai-content-generator'),
            'logUserLabel'              => __('User', 'gpt3-ai-content-generator'),
            'logSessionLabel'           => __('Session ID', 'gpt3-ai-content-generator'),
            'logStartLabel'             => __('Started', 'gpt3-ai-content-generator'),
            'logLastActivityLabel'      => __('Last Activity', 'gpt3-ai-content-generator'),
            'logProviderLabel'          => __('Provider', 'gpt3-ai-content-generator'),
            'logModelLabel'             => __('Model', 'gpt3-ai-content-generator'),
            'logIpLabel'                => __('IP Address', 'gpt3-ai-content-generator'),
            'noLogsFound'               => __('No logs found matching your criteria.', 'gpt3-ai-content-generator'),
            'logExportFilteredConfirmMsg' => __('Export all messages matching current filters?', 'gpt3-ai-content-generator'),
            'logExportAllConfirmMsg'    => __('Export all messages? This might take a while for large datasets.', 'gpt3-ai-content-generator'),
            'logExportStarting'         => __('Starting...', 'gpt3-ai-content-generator'),
            /* translators: %1$d is the current conversation number, %2$d is the total number of conversations */
            'logExportProgress' 		=> __('Exporting conversation %1$d / %2$d...', 'gpt3-ai-content-generator'),
            'logExportComplete'         => __('Done!', 'gpt3-ai-content-generator'),
            'logExportError'            => __('Export failed:', 'gpt3-ai-content-generator'),
            'logExportNoLogs'           => __('No messages found to export.', 'gpt3-ai-content-generator'),
            'logDeleteFilteredConfirmMsg' => __('Delete all conversations matching current filters? This action cannot be undone.', 'gpt3-ai-content-generator'),
            'logDeleteAllConfirmMsg'    => __('Delete ALL conversations? This will remove ALL chat history and cannot be undone.', 'gpt3-ai-content-generator'),
            'logDeleteStarting'         => __('Starting...', 'gpt3-ai-content-generator'),
            /* translators: %1$d is the current conversation number, %2$d is the total number of conversations */
            'logDeleteProgress' 		=> __('Deleting conversation %1$d / %2$d...', 'gpt3-ai-content-generator'),
            'logDeleteComplete'         => __('Done!', 'gpt3-ai-content-generator'),
            'logDeleteError'            => __('Deletion failed:', 'gpt3-ai-content-generator'),
            'logDeleteNoLogs'           => __('No conversations found to delete.', 'gpt3-ai-content-generator'),
            'confirm'                   => __('Confirm', 'gpt3-ai-content-generator'),
            'confirmDeleteFiltered'     => __('Confirm Delete Filtered', 'gpt3-ai-content-generator'),
            'confirmDeleteAll'          => __('Confirm Delete ALL', 'gpt3-ai-content-generator'),
            'cancel'                    => __('Cancel', 'gpt3-ai-content-generator'),
            // Frontend Chat UI (subset needed for preview)
            'sendMessage'               => __('Send Message', 'gpt3-ai-content-generator'),
            'sending'                   => __('Sending...', 'gpt3-ai-content-generator'),
            'typeMessage'               => __('Type your message...', 'gpt3-ai-content-generator'),
            'thinking'                  => __('Thinking', 'gpt3-ai-content-generator'),
            'streaming'                 => __('Streaming...', 'gpt3-ai-content-generator'),
            'errorPrefix'               => __('Error:', 'gpt3-ai-content-generator'),
            'userPrefix'                => __('User', 'gpt3-ai-content-generator'),
            'clearChat'                 => __('Clear Chat', 'gpt3-ai-content-generator'),
            'fullscreen'                => __('Fullscreen', 'gpt3-ai-content-generator'),
            'exitFullscreen'            => __('Exit Fullscreen', 'gpt3-ai-content-generator'),
            'fullscreenError'           => __('Error: Fullscreen functionality is unavailable.', 'gpt3-ai-content-generator'),
            'download'                  => __('Download Transcript', 'gpt3-ai-content-generator'),
            'downloadTxt'               => __('Download TXT', 'gpt3-ai-content-generator'),
            'downloadPdf'               => __('Download PDF', 'gpt3-ai-content-generator'),
            'downloadEmpty'             => __('Nothing to download.', 'gpt3-ai-content-generator'),
            'pdfError'                  => __('Could not open the print window. Please allow popups and try again.', 'gpt3-ai-content-generator'),
            'streamError'               => __('Stream error. Please try again.', 'gpt3-ai-content-generator'),
            'connError'                 => __('Connection error. Please try again.', 'gpt3-ai-content-generator'),
            'copyActionLabel'           => __('Copy response', 'gpt3-ai-content-generator'),
            'copySuccess'               => __('Copied!', 'gpt3-ai-content-generator'),
            'copyFail'                  => __('Failed to copy', 'gpt3-ai-content-generator'),
            'feedbackLikeLabel'         => __('Like response', 'gpt3-ai-content-generator'),
            'feedbackDislikeLabel'      => __('Dislike response', 'gpt3-ai-content-generator'),
            'feedbackSubmitted'         => __('Feedback submitted', 'gpt3-ai-content-generator'),
            'feedbackError'             => __('Error saving feedback', 'gpt3-ai-content-generator'),
            'sidebarToggle'             => __('Toggle Conversation Sidebar', 'gpt3-ai-content-generator'),
            'newChat'                   => __('New Chat', 'gpt3-ai-content-generator'),
            'conversations'             => __('Conversations', 'gpt3-ai-content-generator'),
            'searchConversations'       => __('Search conversations', 'gpt3-ai-content-generator'),
            'configure'                 => __('Configure', 'gpt3-ai-content-generator'),
            'enableToConfigure'         => __('Enable to configure', 'gpt3-ai-content-generator'),
            'historyGuests'             => __('History unavailable for guests.', 'gpt3-ai-content-generator'),
            'historyEmpty'              => __('No past conversations.', 'gpt3-ai-content-generator'),
            'historySidebarEmpty'       => __('No conversations yet — start one to see it here.', 'gpt3-ai-content-generator'),
            'historySearchEmpty'        => __('No conversations match your search.', 'gpt3-ai-content-generator'),
            'historyLoadingOlder'       => __('Loading older conversations...', 'gpt3-ai-content-generator'),
            'playActionLabel'           => __('Play audio', 'gpt3-ai-content-generator'),
            'pauseActionLabel'          => __('Pause audio', 'gpt3-ai-content-generator'),
            // The chatbot's Answer style calls reasoning "Thinking" for every provider.
            'reasoningLabel'            => __('Thinking', 'gpt3-ai-content-generator'),
            'thinkingLabel'             => __('Thinking', 'gpt3-ai-content-generator'),
            'offLabel'                  => __('off', 'gpt3-ai-content-generator'),
            'onLabel'                   => __('on', 'gpt3-ai-content-generator'),
            'reasoningHelpGeneric'      => '',
            'reasoningHelpOpenAI'       => '',
            'reasoningHelpOpenRouter'   => __('OpenRouter maps this level to the selected model\'s supported reasoning effort.', 'gpt3-ai-content-generator'),
            'reasoningHelpCloud'        => __('Thinking tokens are billed as output. Higher levels can improve answers but cost more and take longer.', 'gpt3-ai-content-generator'),
            'reasoningHelpOllama'       => __('Maps to Ollama think mode. GPT-OSS uses levels, while most other thinking-capable Ollama models use on or off.', 'gpt3-ai-content-generator'),
            'openaiOnly'                => __('Available for OpenAI only', 'gpt3-ai-content-generator'),
            'openaiReasoningOnly'       => __('Available for OpenAI reasoning models only', 'gpt3-ai-content-generator'),
            'openaiOllamaReasoningOnly' => __('Available for reasoning-capable OpenAI/OpenRouter models and Ollama thinking-capable models', 'gpt3-ai-content-generator'),
            'openaiClaudeOnly'          => __('Available for OpenAI, Anthropic, OpenRouter, and xAI only', 'gpt3-ai-content-generator'),
            // User Credits
            'userCreditsLoading'        => __('Loading user credits...', 'gpt3-ai-content-generator'),
            'userCreditsError'          => __('Error loading user credits.', 'gpt3-ai-content-generator'),
            'userCreditsNoUsers'        => __('No user token data found.', 'gpt3-ai-content-generator'),
            'userCreditsEdit'           => __('Edit Credits', 'gpt3-ai-content-generator'),
            'userCreditsReset'          => __('Reset Period', 'gpt3-ai-content-generator'),
            // Vector Store Specific
            'vectorSearching'           => __('Searching vector store...', 'gpt3-ai-content-generator'),
        ];
    }
}

class DashboardAssets extends AIPKit_Admin_Asset_Base
{
    private static $is_core_data_localized = false;

    public function register_hooks()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_core_dashboard_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_pricing_assets']);
    }

    /** Style the SDK app while preserving its live plans, prices and checkout. */
    public function enqueue_pricing_assets($hook_suffix): void
    {
        $screen = get_current_screen();
        if (!$screen || !preg_match('/(?:^|_)page_wpaicg-pricing(?:-network)?$/D', $screen->id) || fs_request_get_bool('checkout')) {
            return;
        }

        if (function_exists('wpaicg_gacg_fs')) {
            // Show plan cards with a site selector, even when only Pro is published.
            wpaicg_gacg_fs()->add_filter('pricing/disable_single_package', '__return_true');
        }

        $this->register_style_bundle('aipkit-admin-pricing', 'admin-pricing.bundle.css', [], self::asset_version('dist/css/admin-pricing.bundle.css'));
        $this->enqueue_style_handle('aipkit-admin-pricing');
        $this->register_script_bundle('aipkit-admin-pricing', 'admin-pricing.bundle.js', ['wp-i18n', 'freemius-pricing'], self::asset_version('dist/js/admin-pricing.bundle.js'));
        $this->enqueue_script_handle('aipkit-admin-pricing');
    }

    public function enqueue_core_dashboard_assets($hook_suffix)
    {
        $screen = get_current_screen();
        $is_dashboard_screen = $screen && (
            strpos($screen->id, 'page_wpaicg') !== false ||
            $screen->id === 'toplevel_page_wpaicg' ||
            strpos($screen->id, 'aipkit-role-manager') !== false
        );

        if (! $is_dashboard_screen) {
            return;
        }

        $this->enqueue_admin_main_css(self::asset_version('dist/css/admin-main.bundle.css', $this->version));
        $this->enqueue_admin_main_script(self::asset_version('dist/js/admin-main.bundle.js', $this->version));
        self::localize_core_data($this->version);
    }

    public static function localize_core_data(string $plugin_version)
    {
        if (self::$is_core_data_localized) {
            return;
        }

        if (class_exists(AIPKit_Shared_Assets_Manager::class)) {
            AIPKit_Shared_Assets_Manager::register($plugin_version);
        }

        if (! wp_script_is('aipkit-admin-main', 'registered')) {
            wp_register_script(
                'aipkit-admin-main',
                WPAICG_PLUGIN_URL . 'dist/js/admin-main.bundle.js',
                array_merge(self::script_deps(), ['aipkit-shared-model-selector']),
                self::asset_version('dist/js/admin-main.bundle.js', $plugin_version),
                true
            );
        }

        if (self::is_script_localized('aipkit-admin-main', 'aipkit_dashboard')) {
            self::$is_core_data_localized = true;
            return;
        }

        $openai_models = [];
        $openrouter_models = [];
        $google_models = [];
        $azure_deployments = [];
        $claude_models = [];
        $deepseek_models = [];
        $xai_models = [];
        $ollama_models = [];
        $google_image_models = [];
        $openrouter_image_models = [];
        $xai_image_models = [];
        $recommended_models = [];
        $provider_status = [];
        $new_ai_selection = [];
        $openai_api_mode = 'responses';
        $model_catalog_config = class_exists(\WPAICG\Core\Models\AIPKit_Model_Catalog::class)
            ? \WPAICG\Core\Models\AIPKit_Model_Catalog::get_client_config()
            : [
                'defaults' => [],
                'seeds' => [],
                'providerCatalogs' => [],
                'providerPriority' => [],
                'providerAccess' => [],
            ];
        $current_provider = 'openai';

        if (class_exists(AIPKit_Providers::class)) {
            $openai_models = AIPKit_Providers::get_openai_models();
            $openrouter_models = AIPKit_Providers::get_openrouter_models();
            $google_models = AIPKit_Providers::get_google_models();
            $azure_deployments = AIPKit_Providers::get_azure_deployments();
            $claude_models = AIPKit_Providers::get_claude_models();
            $deepseek_models = AIPKit_Providers::get_deepseek_models();
            $xai_models = AIPKit_Providers::get_xai_models();
            $ollama_models = AIPKit_Providers::get_ollama_models();
            $google_image_models = AIPKit_Providers::get_google_image_models();
            $openrouter_image_models = AIPKit_Providers::get_openrouter_image_models();
            $xai_image_models = AIPKit_Providers::get_xai_image_models();
            $recommended_models = [
                'openai' => AIPKit_Providers::get_recommended_models('OpenAI'),
                'google' => AIPKit_Providers::get_recommended_models('Google'),
                'claude' => AIPKit_Providers::get_recommended_models('Claude'),
                'openrouter' => AIPKit_Providers::get_recommended_models('OpenRouter'),
                'deepseek' => AIPKit_Providers::get_recommended_models('DeepSeek'),
                'xai' => AIPKit_Providers::get_recommended_models('xAI'),
            ];

            $current_provider = strtolower(AIPKit_Providers::get_current_provider());
            $openai_provider_data = AIPKit_Providers::get_provider_data('OpenAI');
            $openai_api_mode = AIPKit_Providers::normalize_openai_api_mode($openai_provider_data['api_mode'] ?? null);
            $new_ai_selection = AIPKit_Providers::get_new_text_generation_selection();
            $provider_status = AIPKit_Providers::get_provider_status_map();
            $provider_connection_states = AIPKit_Providers::get_provider_connection_states();
            $model_sync_timestamps = AIPKit_Providers::get_model_sync_timestamps();
        }

        $embedding_provider_map = class_exists(AIPKit_Providers::class)
            ? AIPKit_Providers::get_embedding_provider_map('dashboard_ui')
            : [];
        $embedding_models = class_exists(AIPKit_Providers::class)
            ? AIPKit_Providers::get_embedding_models_by_provider('dashboard_ui')
            : [];
        $is_pro_plan = class_exists(aipkit_dashboard::class) ? aipkit_dashboard::is_pro_plan() : false;

        $aipkit_module_scripts = [
            'sources' => WPAICG_PLUGIN_URL . 'dist/js/admin-sources.bundle.js?ver=' . self::asset_version('dist/js/admin-sources.bundle.js', $plugin_version),
        ];
        if ($is_pro_plan && file_exists(WPAICG_PLUGIN_DIR . 'dist/js/lib-settings.bundle.js')) {
            $aipkit_module_scripts['settings'] = WPAICG_PLUGIN_URL . 'dist/js/lib-settings.bundle.js?ver=' . self::asset_version('dist/js/lib-settings.bundle.js', $plugin_version);
        }
        $aipkit_module_styles = [];
        if ($is_pro_plan && file_exists(WPAICG_PLUGIN_DIR . 'dist/css/lib-settings.bundle.css')) {
            $aipkit_module_styles['settings'] = WPAICG_PLUGIN_URL . 'dist/css/lib-settings.bundle.css?ver=' . self::asset_version('dist/css/lib-settings.bundle.css', $plugin_version);
        }
        if ($is_pro_plan && file_exists(WPAICG_PLUGIN_DIR . 'dist/css/lib-automations.bundle.css')) {
            $aipkit_module_styles['autogpt'] = WPAICG_PLUGIN_URL . 'dist/css/lib-automations.bundle.css?ver=' . self::asset_version('dist/css/lib-automations.bundle.css', $plugin_version);
        }

        wp_localize_script('aipkit-admin-main', 'aipkit_dashboard', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('aipkit_nonce'),
            'isProPlan' => $is_pro_plan,
            'isAdmin' => AIPKit_Role_Manager::user_can_manage_others_content(),
            'moduleScripts' => $aipkit_module_scripts,
            'moduleStyles' => $aipkit_module_styles,
            'upgradeUrl' => admin_url('admin.php?page=wpaicg-pricing'),
            'adminUrl' => admin_url(),
            'main_provider' => $current_provider,
            'openaiApiMode' => $openai_api_mode,
            'models' => [
                'aipuffercloud' => class_exists('\\WPAICG\\Cloud\\Connection') ? \WPAICG\Cloud\Connection::models() : [],
                'openai' => $openai_models,
                'google' => $google_models,
                'claude' => $claude_models,
                'openrouter' => $openrouter_models,
                'azure' => $azure_deployments,
                'ollama' => $ollama_models,
                'deepseek' => $deepseek_models,
                'xai' => $xai_models,
            ],
            'newAiSelection' => $new_ai_selection,
            'newFeatureDefaults' => AIPKit_Providers::get_new_feature_defaults(),
            'chatbotSourceDefaults' => AIPKit_Providers::get_chatbot_source_defaults(),
            'recommendedModels' => $recommended_models,
            'modelCatalog' => $model_catalog_config,
            'embeddingProviderMap' => $embedding_provider_map,
            'embeddingModels' => $embedding_models,
            'imageGeneratorModels' => [
                'openai' => class_exists(AIPKit_Providers::class) ? AIPKit_Providers::get_openai_image_models() : [],
                'google' => $google_image_models,
                'openrouter' => $openrouter_image_models,
                'aipuffercloud' => class_exists('\\WPAICG\\Cloud\\Connection') ? \WPAICG\Cloud\Connection::media_models('image_generate') : [],
                'xai' => $xai_image_models,
                'azure' => class_exists(AIPKit_Providers::class) ? AIPKit_Providers::get_azure_image_models() : [],
                'replicate' => class_exists(AIPKit_Providers::class) ? AIPKit_Providers::get_replicate_models() : [],
            ],
            'imageGeneratorVideoModels' => [
                'google' => class_exists(AIPKit_Providers::class) ? AIPKit_Providers::get_google_video_models() : [],
            ],
            'providerStatus' => $provider_status,
            'cloudConnected' => class_exists('\\WPAICG\\Cloud\\Connection') && \WPAICG\Cloud\Connection::display()['connected'],
            'modelRegistry' => [
                'schemaVersion' => \WPAICG\Core\Models\AIPKit_Model_Registry::SCHEMA_VERSION,
                'providerStates' => $provider_connection_states ?? [],
                'syncTimestamps' => $model_sync_timestamps ?? [],
            ],
            'text' => self::dashboard_texts(),
            'currentUserId' => get_current_user_id(),
        ]);

        // Keep provider setup presence in a small, stable object of its own.
        // Several admin modules extend the larger dashboard config at runtime;
        // setup validation must not depend on those unrelated mutations.
        wp_localize_script('aipkit-admin-main', 'aipkit_provider_status', $provider_status);

        self::$is_core_data_localized = true;
    }
}

class SettingsAssets extends AIPKit_Admin_Asset_Base
{
    public function register_hooks()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_settings_assets']);
    }

    public function enqueue_settings_assets($hook_suffix)
    {
        $screen = get_current_screen();

        if (! $this->is_aipkit_page($screen)) {
            return;
        }

        $this->enqueue_admin_main_script();
        $this->ensure_dashboard_core_data();

        if (current_user_can('install_plugins')) {
            add_thickbox();
            wp_enqueue_script('plugin-install');
            wp_enqueue_script('updates');
        }
    }
}

class ChatAdminAssets extends AIPKit_Admin_Asset_Base
{
    public function register_hooks()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_chat_admin_assets']);
    }

    public function enqueue_chat_admin_assets($hook_suffix)
    {
        $screen = get_current_screen();

        if (! $this->is_aipkit_page($screen)) {
            return;
        }

        if (class_exists(ChatFrontendAssetsOrchestrator::class) && method_exists(ChatFrontendAssetsOrchestrator::class, 'register_public_chat_dependencies')) {
            ChatFrontendAssetsOrchestrator::register_public_chat_dependencies();
        }

        wp_enqueue_media();

        $this->enqueue_styles();
        $this->enqueue_scripts();
        $this->ensure_dashboard_core_data();
        $this->localize_chat_data();
    }

    private function enqueue_styles(): void
    {
        $public_css_version = self::asset_version('dist/css/public-main.bundle.css', $this->version);
        $chat_css_version = self::asset_version('dist/css/admin-chat.bundle.css', $this->version);

        $this->register_admin_main_css(self::asset_version('dist/css/admin-main.bundle.css', $this->version));
        $this->register_style_bundle('aipkit-public-main-css', 'public-main.bundle.css', ['dashicons'], $public_css_version);
        $this->register_style_bundle(
            'aipkit-admin-chat-css',
            'admin-chat.bundle.css',
            ['aipkit-admin-main-css', 'aipkit-public-main-css'],
            $chat_css_version
        );

        $this->enqueue_style_handle('aipkit-public-main-css');
        $this->enqueue_style_handle('aipkit-admin-chat-css');
    }

    private function enqueue_scripts(): void
    {
        $admin_js_version = self::asset_version('dist/js/admin-main.bundle.js', $this->version);
        $public_js_version = self::asset_version('dist/js/public-main.bundle.js', $this->version);

        $this->enqueue_admin_main_script($admin_js_version);
        $this->enqueue_public_main_script($public_js_version);
    }

    private function localize_chat_data(): void
    {
        $public_js_version = self::asset_version('dist/js/public-main.bundle.js', $this->version);

        $this->enqueue_public_main_script($public_js_version);

        if (self::is_script_localized('aipkit-public-main', 'aipkit_chat_config')) {
            return;
        }

        $vector_store_localization = AIPKit_Providers::get_vector_store_localization_payload('chat_admin_ui');
        $embedding_localization = AIPKit_Providers::get_embedding_localization_payload('chat_admin_ui', false);
        $dashboard_texts = self::dashboard_texts();
        $is_pro_plan = class_exists(aipkit_dashboard::class) ? aipkit_dashboard::is_pro_plan() : false;

        wp_localize_script('aipkit-public-main', 'aipkit_chat_config', [
            'nonce' => wp_create_nonce('aipkit_frontend_chat_nonce'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'requireConsentCompliance' => false,
            'openaiVectorStores' => $vector_store_localization['openaiVectorStores'],
            'googleFileSearchStores' => $vector_store_localization['googleFileSearchStores'],
            'pineconeIndexes' => $vector_store_localization['pineconeIndexes'],
            'qdrantCollections' => $vector_store_localization['qdrantCollections'],
            'chromaCollections' => $vector_store_localization['chromaCollections'],
            'embedding_provider_map' => $embedding_localization['embedding_provider_map'],
            'embedding_models_by_provider' => $embedding_localization['embedding_models_by_provider'],
            'isProPlan' => $is_pro_plan,
            'automationsNonce' => wp_create_nonce('aipkit_automated_tasks_manage_nonce'),
            'text' => array_merge($dashboard_texts, [
                'fullscreenError' => $dashboard_texts['fullscreenError'] ?? __('Error: Fullscreen functionality is unavailable.', 'gpt3-ai-content-generator'),
                'copySuccess' => $dashboard_texts['copySuccess'] ?? __('Copied!', 'gpt3-ai-content-generator'),
                'copyFail' => $dashboard_texts['copyFail'] ?? __('Failed to copy', 'gpt3-ai-content-generator'),
                'selectVectorStoreOpenAI' => __('Select OpenAI Store(s)', 'gpt3-ai-content-generator'),
                'selectVectorStorePinecone' => __('Select Pinecone Index', 'gpt3-ai-content-generator'),
                'selectVectorStoreQdrant' => __('Select Qdrant Collection', 'gpt3-ai-content-generator'),
                'selectVectorStoreChroma' => __('Select Chroma Collection', 'gpt3-ai-content-generator'),
                'selectEmbeddingProvider' => __('Select Embedding Provider', 'gpt3-ai-content-generator'),
                'selectEmbeddingModel' => __('Select Embedding Model', 'gpt3-ai-content-generator'),
                'noStoresFoundOpenAI' => __('No OpenAI Stores Found (Sync in AI Training)', 'gpt3-ai-content-generator'),
                'noIndexesFoundPinecone' => __('No Pinecone Indexes Found (Sync in AI Settings)', 'gpt3-ai-content-generator'),
                'noCollectionsFoundQdrant' => __('No Qdrant Collections Found (Sync in AI Settings)', 'gpt3-ai-content-generator'),
                'noCollectionsFoundChroma' => __('No Chroma Collections Found (Sync in AI Settings)', 'gpt3-ai-content-generator'),
                'noEmbeddingModelsFound' => __('No Models (Select Provider or Sync)', 'gpt3-ai-content-generator'),
            ]),
        ]);

        if (wp_script_is('aipkit-admin-main', 'enqueued')) {
            wp_add_inline_script(
                'aipkit-admin-main',
                'window.aipkit_index_content_nonce = "' . esc_js(wp_create_nonce('aipkit_chatbot_index_content_nonce')) . '";',
                'before'
            );
        }
    }
}

class RoleManagerAssets extends AIPKit_Admin_Asset_Base
{
    public function register_hooks()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_role_manager_assets']);
    }

    public function enqueue_role_manager_assets($hook_suffix)
    {
        $screen = get_current_screen();
        $is_role_manager = $screen && strpos($screen->id, 'page_aipkit-role-manager') !== false;

        if (! $is_role_manager) {
            return;
        }

        $this->enqueue_admin_main_css();
        $this->enqueue_admin_main_script();

        if (! self::is_script_localized('aipkit-admin-main', 'aipkit_role_manager_config')) {
            wp_localize_script('aipkit-admin-main', 'aipkit_role_manager_config', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('aipkit_role_manager_nonce'),
                'text' => [
                    'success' => __('Permissions saved.', 'gpt3-ai-content-generator'),
                    'fail' => __('Failed to save permissions.', 'gpt3-ai-content-generator'),
                    /* translators: 1: enabled permission count, 2: total permission count. */
                    'enabledCount' => __('%1$d of %2$d', 'gpt3-ai-content-generator'),
                    /* translators: 1: visible role count, 2: total role count. */
                    'comparisonCount' => __('%1$d of %2$d roles shown', 'gpt3-ai-content-generator'),
                ],
            ]);
        }
    }
}

class PostEnhancerAssets extends AIPKit_Admin_Asset_Base
{
    private const BULK_ASSISTANT_MODULE = 'bulk_assistant';
    private const ROW_ASSISTANT_MODULE = 'row_assistant';
    private const CLASSIC_EDITOR_ASSISTANT_MODULE = 'classic_editor_assistant';
    private const BLOCK_EDITOR_ASSISTANT_MODULE = 'block_editor_assistant';

    public function register_hooks()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_post_enhancer_assets']);
    }

    public function enqueue_post_enhancer_assets($hook_suffix)
    {
        $screen = get_current_screen();
        $is_aipkit_page = $this->is_aipkit_page($screen);
        $is_post_edit_screen = in_array($hook_suffix, ['post.php', 'post-new.php'], true);

        $ui_post_types = get_post_types(['show_ui' => true]);
        unset($ui_post_types['attachment']);

        $supported_post_types = apply_filters('aipkit_post_enhancer_post_types', array_keys($ui_post_types));
        $current_post_type = isset($screen->post_type) ? (string) $screen->post_type : '';
        $is_post_list_screen = $screen && $screen->base === 'edit' && in_array($current_post_type, $supported_post_types, true);
        $can_enqueue_for_aipkit_page = $is_aipkit_page && AIPKit_Role_Manager::user_can_access_any_module([
            'content-writer',
            'settings',
            self::BULK_ASSISTANT_MODULE,
            self::ROW_ASSISTANT_MODULE,
            self::CLASSIC_EDITOR_ASSISTANT_MODULE,
            self::BLOCK_EDITOR_ASSISTANT_MODULE,
        ]);
        $can_enqueue_for_post_edit = $is_post_edit_screen && AIPKit_Role_Manager::user_can_access_any_module([
            self::CLASSIC_EDITOR_ASSISTANT_MODULE,
            self::BLOCK_EDITOR_ASSISTANT_MODULE,
        ]);
        $can_enqueue_for_post_list = $is_post_list_screen && $this->can_access_list_screen_tools();

        if ($can_enqueue_for_post_list || $can_enqueue_for_post_edit || $can_enqueue_for_aipkit_page) {
            $this->enqueue_scripts();
        }

        if ($can_enqueue_for_post_list || $can_enqueue_for_post_edit) {
            $this->enqueue_styles();
        }
    }

    private function can_access_list_screen_tools(): bool
    {
        return AIPKit_Role_Manager::user_can_access_any_module([
            self::BULK_ASSISTANT_MODULE,
            self::ROW_ASSISTANT_MODULE,
        ]);
    }

    private function enqueue_styles(): void
    {
        $this->register_admin_main_css(
            self::asset_version('dist/css/admin-main.bundle.css', $this->version)
        );
        $this->register_style_bundle(
            'aipkit-admin-post-enhancer-css',
            'admin-post-enhancer.bundle.css',
            ['aipkit-admin-main-css'],
            self::asset_version('dist/css/admin-post-enhancer.bundle.css', $this->version)
        );
        $this->enqueue_style_handle('aipkit-admin-post-enhancer-css');
    }

    private function enqueue_scripts(): void
    {
        $this->enqueue_admin_main_script(
            self::asset_version('dist/js/admin-main.bundle.js', $this->version)
        );
        $this->ensure_dashboard_core_data();

        if (self::is_script_localized('aipkit-admin-main', 'aipkit_post_enhancer')) {
            return;
        }

        $default_ai_config = AIPKit_Providers::get_new_text_generation_selection();
        $default_ai_params = AIPKIT_AI_Settings::get_ai_parameters();
        $vector_store_localization = AIPKit_Providers::get_vector_store_localization_payload('post_enhancer_ui');
        $embedding_localization = AIPKit_Providers::get_embedding_localization_payload('post_enhancer_ui', false);
        $enhancer_actions = get_option('aipkit_enhancer_actions', []);
        $enhancer_prompt_items = class_exists(AIPKit_AutoGPT_Prompt_Definitions::class)
            ? AIPKit_AutoGPT_Prompt_Definitions::get_post_enhancer_prompt_items(true)
            : [];

        if (empty($enhancer_actions) && class_exists(AIPKit_Enhancer_Actions_Ajax_Handler::class)) {
            $enhancer_actions = (new AIPKit_Enhancer_Actions_Ajax_Handler())->get_default_actions_public();
        }
        if (class_exists(AIPKit_Enhancer_Actions_Ajax_Handler::class) && is_array($enhancer_actions)) {
            $enhancer_actions = (new AIPKit_Enhancer_Actions_Ajax_Handler())->normalize_actions_for_ui_public($enhancer_actions);
        }

        wp_localize_script('aipkit-admin-main', 'aipkit_post_enhancer', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce_generate_title' => wp_create_nonce('aipkit_generate_title_nonce'),
            'nonce_update_title' => wp_create_nonce('aipkit_update_title_nonce'),
            'nonce_generate_excerpt' => wp_create_nonce('aipkit_generate_excerpt_nonce'),
            'nonce_update_excerpt' => wp_create_nonce('aipkit_update_excerpt_nonce'),
            'nonce_generate_meta' => wp_create_nonce('aipkit_generate_meta_nonce'),
            'nonce_update_meta' => wp_create_nonce('aipkit_update_meta_nonce'),
            'nonce_generate_tags' => wp_create_nonce('aipkit_generate_tags_nonce'),
            'nonce_update_tags' => wp_create_nonce('aipkit_update_tags_nonce'),
            'nonce_process_text' => wp_create_nonce('aipkit_process_enhancer_text_nonce'),
            'nonce_manage_templates' => wp_create_nonce('aipkit_content_writer_template_nonce'),
            'nonce_manage_actions' => wp_create_nonce('aipkit_enhancer_actions_nonce'),
            'nonce_prompt_library' => wp_create_nonce('aipkit_nonce'),
            'default_ai_provider' => $default_ai_config['provider'] ?? 'N/A',
            'default_ai_model' => $default_ai_config['model'] ?? 'N/A',
            'default_ai_params' => $default_ai_params,
            'prompt_items' => $enhancer_prompt_items,
            'local_stores' => $vector_store_localization['local_stores'] ?? [],
            'openai_vector_stores' => $vector_store_localization['openai_vector_stores'],
            'google_file_search_stores' => $vector_store_localization['google_file_search_stores'],
            'pinecone_indexes' => $vector_store_localization['pinecone_indexes'],
            'qdrant_collections' => $vector_store_localization['qdrant_collections'],
            'chroma_collections' => $vector_store_localization['chroma_collections'],
            'embeddingProviderMap' => $embedding_localization['embeddingProviderMap'],
            'embeddingModelsByProvider' => $embedding_localization['embeddingModelsByProvider'],
            'actions' => $enhancer_actions,
            'can_manage_actions' => AIPKit_Role_Manager::user_can_access_module(AIPKit_Enhancer_Actions_Ajax_Handler::MODULE_SLUG),
            'max_actions' => AIPKit_Enhancer_Actions_Ajax_Handler::MAX_ACTIONS,
            'parse_html_formats' => (bool) apply_filters('aipkit_enhancer_enable_formatting', true),
            'text' => [
                'modal_title_title' => __('Title suggestions', 'gpt3-ai-content-generator'),
                'loading_title' => __('Generating title suggestions…', 'gpt3-ai-content-generator'),
                'updating_title' => __('Updating title…', 'gpt3-ai-content-generator'),
                'error_loading_title' => __('Error loading title suggestions.', 'gpt3-ai-content-generator'),
                'error_updating_title' => __('Error updating title.', 'gpt3-ai-content-generator'),
                'no_suggestions_title' => __('No title suggestions generated or AI Error.', 'gpt3-ai-content-generator'),
                'current_title' => __('Current title', 'gpt3-ai-content-generator'),
                'no_current_title' => __('No current title.', 'gpt3-ai-content-generator'),
                'apply_title' => __('Apply title', 'gpt3-ai-content-generator'),
                'modal_title_excerpt' => __('Excerpt suggestions', 'gpt3-ai-content-generator'),
                'loading_excerpt' => __('Generating excerpt suggestions…', 'gpt3-ai-content-generator'),
                'updating_excerpt' => __('Updating excerpt…', 'gpt3-ai-content-generator'),
                'error_loading_excerpt' => __('Error loading excerpt suggestions.', 'gpt3-ai-content-generator'),
                'error_updating_excerpt' => __('Error updating excerpt.', 'gpt3-ai-content-generator'),
                'no_suggestions_excerpt' => __('No excerpt suggestions generated or AI Error.', 'gpt3-ai-content-generator'),
                'current_excerpt' => __('Current excerpt', 'gpt3-ai-content-generator'),
                'no_current_excerpt' => __('No current excerpt.', 'gpt3-ai-content-generator'),
                'apply_excerpt' => __('Apply excerpt', 'gpt3-ai-content-generator'),
                'modal_title_meta' => __('Meta description suggestions', 'gpt3-ai-content-generator'),
                'loading_meta' => __('Generating meta description suggestions…', 'gpt3-ai-content-generator'),
                'updating_meta' => __('Updating meta description…', 'gpt3-ai-content-generator'),
                'error_loading_meta' => __('Error loading meta description suggestions.', 'gpt3-ai-content-generator'),
                'error_updating_meta' => __('Error updating meta description.', 'gpt3-ai-content-generator'),
                'no_suggestions_meta' => __('No meta description suggestions generated or AI Error.', 'gpt3-ai-content-generator'),
                'current_meta' => __('Current meta description', 'gpt3-ai-content-generator'),
                'no_current_meta' => __('No current meta description.', 'gpt3-ai-content-generator'),
                'apply_meta' => __('Apply meta description', 'gpt3-ai-content-generator'),
                'modal_title_tags' => __('Tag suggestions', 'gpt3-ai-content-generator'),
                'loading_tags' => __('Generating tag suggestions…', 'gpt3-ai-content-generator'),
                'updating_tags' => __('Updating tags…', 'gpt3-ai-content-generator'),
                'error_loading_tags' => __('Error loading tag suggestions.', 'gpt3-ai-content-generator'),
                'error_updating_tags' => __('Error updating tags.', 'gpt3-ai-content-generator'),
                'no_suggestions_tags' => __('No tag suggestions generated or AI Error.', 'gpt3-ai-content-generator'),
                'current_tags' => __('Current tags', 'gpt3-ai-content-generator'),
                'no_current_tags' => __('No current tags.', 'gpt3-ai-content-generator'),
                'apply_tags' => __('Apply tags', 'gpt3-ai-content-generator'),
                'regenerate' => __('Regenerate', 'gpt3-ai-content-generator'),
                'show_more' => __('Show more', 'gpt3-ai-content-generator'),
                'show_less' => __('Show less', 'gpt3-ai-content-generator'),
                'retry' => __('Retry', 'gpt3-ai-content-generator'),
                'check_status' => __('Check status', 'gpt3-ai-content-generator'),
                'generate_again' => __('Generate again', 'gpt3-ai-content-generator'),
                'request_settled' => __('The original request completed and its credit usage was recorded. Generating again starts a new request and uses credits.', 'gpt3-ai-content-generator'),
                'request_released' => __('The original request finished without using credits. You can generate again.', 'gpt3-ai-content-generator'),
                'request_pending' => __('The original request is still being resolved. Check its status again before generating.', 'gpt3-ai-content-generator'),
                'status_unavailable' => __('The request status could not be checked. Please check again.', 'gpt3-ai-content-generator'),
                'uncertain_request' => __('The response was interrupted. Credit usage may still be processing. Check your credits before starting another request.', 'gpt3-ai-content-generator'),
                'cancel' => __('Cancel', 'gpt3-ai-content-generator'),
                'select_posts_title' => __('Select items', 'gpt3-ai-content-generator'),
                'select_posts_message' => __('Please select at least one item.', 'gpt3-ai-content-generator'),
                'bulk_unavailable_title' => __('Unable to open', 'gpt3-ai-content-generator'),
                'bulk_unavailable_message' => __('The Assistant interface is not available. Reload the page and try again.', 'gpt3-ai-content-generator'),
                'close_generation_title' => __('Close while generating?', 'gpt3-ai-content-generator'),
                'close_generation_active' => __('Content generation is still in progress. Closing will stop the remaining work. The current request may still finish and save its changes.', 'gpt3-ai-content-generator'),
                'close_window' => __('Close window', 'gpt3-ai-content-generator'),
                'keep_open' => __('Keep open', 'gpt3-ai-content-generator'),
                'ok' => __('OK', 'gpt3-ai-content-generator'),
                'close' => __('Close', 'gpt3-ai-content-generator'),
                'customize_actions' => __('Customize menu', 'gpt3-ai-content-generator'),
                'assistant_menu_title' => __('Assistant menu', 'gpt3-ai-content-generator'),
                'assistant_menu_description' => __('Customize the actions shown in the editor menu.', 'gpt3-ai-content-generator'),
                'assistant_menu_items' => __('Actions', 'gpt3-ai-content-generator'),
                'add_action' => __('Add action', 'gpt3-ai-content-generator'),
                'new_action' => __('New action', 'gpt3-ai-content-generator'),
                'delete_action' => __('Delete action', 'gpt3-ai-content-generator'),
                'action_label' => __('Label', 'gpt3-ai-content-generator'),
                'action_prompt' => __('Prompt', 'gpt3-ai-content-generator'),
                'insert_position' => __('Position', 'gpt3-ai-content-generator'),
                'replace_selection' => __('Replace selection', 'gpt3-ai-content-generator'),
                'insert_after' => __('Insert after', 'gpt3-ai-content-generator'),
                'insert_before' => __('Insert before', 'gpt3-ai-content-generator'),
                'reset_actions' => __('Restore default actions', 'gpt3-ai-content-generator'),
                'confirm_reset_actions' => __('Reset all actions to the default set? This will replace current customizations.', 'gpt3-ai-content-generator'),
                'save_action' => __('Save', 'gpt3-ai-content-generator'),
                'saving_action' => __('Saving…', 'gpt3-ai-content-generator'),
                'deleting_action' => __('Deleting…', 'gpt3-ai-content-generator'),
                'loading_actions' => __('Loading actions…', 'gpt3-ai-content-generator'),
                'loading_failed' => __('Failed to load actions.', 'gpt3-ai-content-generator'),
                'label_required' => __('Label is required.', 'gpt3-ai-content-generator'),
                'prompt_required' => __('Prompt is required.', 'gpt3-ai-content-generator'),
                'max_actions_reached' => __('Maximum actions reached.', 'gpt3-ai-content-generator'),
                'saving_order' => __('Saving order…', 'gpt3-ai-content-generator'),
                'resetting_actions' => __('Resetting…', 'gpt3-ai-content-generator'),
                'error' => __('Error', 'gpt3-ai-content-generator'),
                'save' => __('Save', 'gpt3-ai-content-generator'),
                'selected_text_token' => __('{selected_text}', 'gpt3-ai-content-generator'),
                'search_actions' => __('Search actions', 'gpt3-ai-content-generator'),
                'accept' => __('Accept', 'gpt3-ai-content-generator'),
                'discard' => __('Discard', 'gpt3-ai-content-generator'),
                'try_again' => __('Try again', 'gpt3-ai-content-generator'),
                'rewriting_selection' => __('Rewriting selection…', 'gpt3-ai-content-generator'),
                'generation_failed' => __('Could not generate a result.', 'gpt3-ai-content-generator'),
                'select_text' => __('Select text to use the Assistant.', 'gpt3-ai-content-generator'),
                'assistant_unavailable' => __('The Assistant is unavailable. Reload the editor and try again.', 'gpt3-ai-content-generator'),
            ],
        ]);
    }
}

class ImageGeneratorAssets extends AIPKit_Admin_Asset_Base
{
    public function register_hooks()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_image_generator_assets']);
    }

    public function enqueue_image_generator_assets($hook_suffix)
    {
        $screen = get_current_screen();

        if (! $this->is_aipkit_page($screen)) {
            return;
        }

        $this->enqueue_styles();
        $this->enqueue_scripts();
        $this->ensure_dashboard_core_data();
        $this->localize_data();
    }

    private function enqueue_styles(): void
    {
        $this->register_style_bundle(
            'aipkit-public-image-generator-css',
            'public-image-generator.bundle.css',
            ['aipkit-shared-model-selector'],
            self::asset_version('dist/css/public-image-generator.bundle.css', $this->version)
        );
        $this->enqueue_style_handle('aipkit-public-image-generator-css');
    }

    private function enqueue_scripts(): void
    {
        $this->enqueue_admin_main_script();
        $public_js_version = self::asset_version('dist/js/public-image-generator.bundle.js', $this->version);
        $this->register_script_bundle(
            'aipkit-public-image-generator-js',
            'public-image-generator.bundle.js',
            ['wp-i18n', 'aipkit-shared-model-selector'],
            $public_js_version
        );
        $this->enqueue_script_handle('aipkit-public-image-generator-js');
    }

    private function localize_data(): void
    {
        if (self::is_script_localized('aipkit-public-image-generator-js', 'aipkit_image_generator_config_public')) {
            return;
        }

        wp_localize_script(
            'aipkit-public-image-generator-js',
            'aipkit_image_generator_config_public',
            AIPKit_Shared_Assets_Manager::get_public_image_generator_config()
        );
    }
}

class AIPKit_Content_Writer_Assets extends AIPKit_Admin_Asset_Base
{
    public function register_hooks()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_content_writer_assets']);
    }

    public function enqueue_content_writer_assets($hook_suffix)
    {
        $screen = get_current_screen();

        if (! $this->is_aipkit_page($screen)) {
            return;
        }

        wp_enqueue_media();

        $this->enqueue_admin_main_css();
        $this->enqueue_admin_main_script();
        $this->ensure_dashboard_core_data();
        $this->localize_data();
    }

    private function localize_data(): void
    {
        if (self::is_script_localized('aipkit-admin-main', 'aipkit_content_writer_config')) {
            return;
        }

        wp_localize_script('aipkit-admin-main', 'aipkit_content_writer_config', [
            'default_prompts' => [
                'title' => AIPKit_Content_Writer_Prompts::get_default_title_prompt(),
                'content' => AIPKit_Content_Writer_Prompts::get_default_content_prompt(),
                'meta' => AIPKit_Content_Writer_Prompts::get_default_meta_prompt(),
                'keyword' => AIPKit_Content_Writer_Prompts::get_default_keyword_prompt(),
                'image' => AIPKit_Content_Writer_Prompts::get_default_image_prompt(),
                'featured_image' => AIPKit_Content_Writer_Prompts::get_default_featured_image_prompt(),
                'image_title' => AIPKit_Content_Writer_Prompts::get_default_image_title_prompt(),
                'image_alt_text' => AIPKit_Content_Writer_Prompts::get_default_image_alt_text_prompt(),
                'image_caption' => AIPKit_Content_Writer_Prompts::get_default_image_caption_prompt(),
                'image_description' => AIPKit_Content_Writer_Prompts::get_default_image_description_prompt(),
                'image_title_update' => AIPKit_Content_Writer_Prompts::get_default_image_title_prompt_update(),
                'image_alt_text_update' => AIPKit_Content_Writer_Prompts::get_default_image_alt_text_prompt_update(),
                'image_caption_update' => AIPKit_Content_Writer_Prompts::get_default_image_caption_prompt_update(),
                'image_description_update' => AIPKit_Content_Writer_Prompts::get_default_image_description_prompt_update(),
            ],
        ]);
    }
}

class AIPKit_Vector_Post_Processor_Assets extends AIPKit_Admin_Asset_Base
{
    public const MODULE_SLUG = 'vector_content_indexer';

    public function register_hooks()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_init', function () {
            if (! AIPKit_Role_Manager::user_can_access_module(self::MODULE_SLUG)) {
                return;
            }

            $general = get_option('aipkit_training_general_settings', []);
            $show = $general['show_index_button'] ?? true;

            if ($show) {
                $post_types = ['post', 'page'];
                if (post_type_exists('product')) {
                    $post_types[] = 'product';
                }
                $post_types = apply_filters('aipkit_vector_post_processor_supported_post_types', $post_types);
                AIPKit_Admin_Header_Action_Buttons::register_button(
                    'aipkit_add_to_vector_store_btn',
                    __('Add to knowledge base', 'gpt3-ai-content-generator'),
                    [
                        'post_types' => array_values(array_unique(array_map('sanitize_key', (array) $post_types))),
                        'access_callback' => [__CLASS__, 'current_user_can_access_index_button'],
                    ]
                );
            }
        });
    }

    public static function current_user_can_access_index_button(string $post_type): bool
    {
        if (! AIPKit_Role_Manager::user_can_access_module(self::MODULE_SLUG)) {
            return false;
        }

        return self::current_user_can_edit_post_type($post_type);
    }

    private static function current_user_can_edit_post_type(string $post_type): bool
    {
        $post_type_object = get_post_type_object($post_type);
        $capability = 'edit_posts';

        if ($post_type_object && isset($post_type_object->cap->edit_posts)) {
            $capability = (string) $post_type_object->cap->edit_posts;
        }

        return current_user_can($capability);
    }

    public function enqueue_assets($hook_suffix)
    {
        $screen = get_current_screen();
        $is_post_list_screen = $screen && $screen->base === 'edit';

        if (! $is_post_list_screen || ! AIPKit_Role_Manager::user_can_access_module(self::MODULE_SLUG)) {
            return;
        }

        $this->enqueue_styles();
        $this->enqueue_admin_main_script();
        $this->ensure_dashboard_core_data();
        $this->localize_vpp_data((string) $screen->post_type);
    }

    private function enqueue_styles(): void
    {
        $this->register_admin_main_css();
        $this->register_style_bundle(
            'aipkit-admin-vector-post-processor-css',
            'admin-vector-post-processor.bundle.css',
            ['aipkit-admin-main-css']
        );
        $this->enqueue_style_handle('aipkit-admin-vector-post-processor-css');
    }

    private function localize_vpp_data(string $post_type): void
    {
        if (self::is_script_localized('aipkit-admin-main', 'aipkit_vpp_config')) {
            return;
        }

        $vector_store_localization = AIPKit_Providers::get_vector_store_localization_payload('vector_post_processor_ui');
        $embedding_localization = AIPKit_Providers::get_embedding_localization_payload('vector_post_processor_ui', false);

        wp_localize_script('aipkit-admin-main', 'aipkit_vpp_config', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce_index_posts' => wp_create_nonce('aipkit_index_posts_to_vector_store_nonce'),
            'nonce_openai_store_list' => wp_create_nonce('aipkit_vector_store_nonce_openai'),
            'nonce_google_file_search' => wp_create_nonce('aipkit_google_file_search_nonce'),
            'post_type' => $post_type,
            'local_stores' => $vector_store_localization['local_stores'] ?? [],
            'openai_vector_stores' => $vector_store_localization['openai_vector_stores'],
            'google_file_search_stores' => $vector_store_localization['google_file_search_stores'],
            'pinecone_indexes' => $vector_store_localization['pinecone_indexes'],
            'qdrant_collections' => $vector_store_localization['qdrant_collections'],
            'chroma_collections' => $vector_store_localization['chroma_collections'],
            'embeddingProviderMap' => $embedding_localization['embeddingProviderMap'],
            'embeddingModelsByProvider' => $embedding_localization['embeddingModelsByProvider'],
            'text' => [
                'modal_title' => __('Add to knowledge base', 'gpt3-ai-content-generator'),
                'provider_label' => __('Provider', 'gpt3-ai-content-generator'),
                'select_store' => __('Select a store', 'gpt3-ai-content-generator'),
                'no_stores_found' => __('No OpenAI stores found. Create one in AI Training > Knowledge Base.', 'gpt3-ai-content-generator'),
                'no_google_file_search_stores_found' => __('No Google stores found. Create one in Knowledge Base.', 'gpt3-ai-content-generator'),
                'loading_stores' => __('Loading stores...', 'gpt3-ai-content-generator'),
                'start_indexing' => __('Start indexing', 'gpt3-ai-content-generator'),
                'processingButton' => __('Processing...', 'gpt3-ai-content-generator'),
                'close' => __('Close', 'gpt3-ai-content-generator'),
                'stop' => __('Stop', 'gpt3-ai-content-generator'),
                'close_indexing_title' => __('Close while indexing?', 'gpt3-ai-content-generator'),
                'close_indexing_active' => __('Indexing is still in progress. Closing will stop submitting the remaining items. Requests already sent may still finish in the background.', 'gpt3-ai-content-generator'),
                'close_indexing_background' => __('Some sources are still processing. Closing this window will not cancel them. You can check their progress in Sources.', 'gpt3-ai-content-generator'),
                'close_window' => __('Close window', 'gpt3-ai-content-generator'),
                'keep_open' => __('Keep open', 'gpt3-ai-content-generator'),
                'stopping' => __('Stopping...', 'gpt3-ai-content-generator'),
                /* translators: 1: processed item count, 2: total item count. */
                'indexing_progress' => __('Indexing %1$d of %2$d...', 'gpt3-ai-content-generator'),
                'indexing_complete' => __('Indexing complete', 'gpt3-ai-content-generator'),
                'error_fetching_stores' => __('Error fetching vector stores.', 'gpt3-ai-content-generator'),
                'error_no_store_selected_vpp' => __('Please select an existing OpenAI store.', 'gpt3-ai-content-generator'),
                'error_no_google_file_search_store_selected' => __('Please select a Google store.', 'gpt3-ai-content-generator'),
                'error_no_posts_selected_title' => __('Select items', 'gpt3-ai-content-generator'),
                'error_no_posts_selected' => __('Please select at least one item to index.', 'gpt3-ai-content-generator'),
                'error_ui_unavailable_title' => __('Unable to open', 'gpt3-ai-content-generator'),
                'error_ui_unavailable' => __('The indexing interface is not available. Reload the page and try again.', 'gpt3-ai-content-generator'),
                'ok' => __('OK', 'gpt3-ai-content-generator'),
                'status_preparing' => __('Preparing content...', 'gpt3-ai-content-generator'),
                /* translators: 1: current file number, 2: total file count. */
                'status_uploading' => __('Uploading file %1$s of %2$s...', 'gpt3-ai-content-generator'),
                'status_adding_files' => __('Adding files to vector store...', 'gpt3-ai-content-generator'),
                'status_error' => __('An error occurred.', 'gpt3-ai-content-generator'),
                /* translators: %d: number of selected items. */
                'items_selected_singular' => __('%d item selected', 'gpt3-ai-content-generator'),
                /* translators: %d: number of selected items. */
                'items_selected_plural' => __('%d items selected', 'gpt3-ai-content-generator'),
                /* translators: 1: successfully indexed item count, 2: total item count. */
                'items_indexed_progress' => __('%1$d of %2$d items indexed', 'gpt3-ai-content-generator'),
                /* translators: 1: successfully submitted item count, 2: total item count. */
                'items_submitted_progress' => __('%1$d of %2$d items submitted', 'gpt3-ai-content-generator'),
                'submission_complete' => __('Submitted — processing in background', 'gpt3-ai-content-generator'),
                'submitted_items_ready' => __('Submitted items are ready', 'gpt3-ai-content-generator'),
                'indexing_has_errors' => __('Indexing finished with errors', 'gpt3-ai-content-generator'),
                'status_check_retry' => __('Could not check progress. Retrying…', 'gpt3-ai-content-generator'),
                'background_processing' => __('Indexing continues in the background. Check Sources for progress.', 'gpt3-ai-content-generator'),
                /* translators: 1: ready item count, 2: total item count. */
                'items_ready_progress' => __('%1$d of %2$d items ready', 'gpt3-ai-content-generator'),
                'select_pinecone_index' => __('Select an index', 'gpt3-ai-content-generator'),
                'loading_indexes' => __('Loading indexes...', 'gpt3-ai-content-generator'),
                'error_fetching_indexes' => __('Error fetching indexes.', 'gpt3-ai-content-generator'),
                'no_pinecone_indexes_found' => __('No Pinecone indexes found. Create one in AI Training or via Pinecone console.', 'gpt3-ai-content-generator'),
                'error_no_pinecone_index_selected' => __('Please select a Pinecone index.', 'gpt3-ai-content-generator'),
                'select_qdrant_collection' => __('Select a collection', 'gpt3-ai-content-generator'),
                'no_qdrant_collections_found' => __('No Qdrant collections found. Create one in AI Training.', 'gpt3-ai-content-generator'),
                'error_no_qdrant_collection_selected' => __('Please select a Qdrant collection.', 'gpt3-ai-content-generator'),
                'select_chroma_collection' => __('Select a collection', 'gpt3-ai-content-generator'),
                'no_chroma_collections_found' => __('No Chroma collections found. Create one in Knowledge Base.', 'gpt3-ai-content-generator'),
                'error_no_chroma_collection_selected' => __('Please select a Chroma collection.', 'gpt3-ai-content-generator'),
                'target_label' => __('Index', 'gpt3-ai-content-generator'),
                'embedding_label' => __('Embedding model', 'gpt3-ai-content-generator'),
                'embedding_provider_label' => __('Embedding provider', 'gpt3-ai-content-generator'),
                'embedding_model_label' => __('Embedding model', 'gpt3-ai-content-generator'),
                'select_model' => __('Select a model', 'gpt3-ai-content-generator'),
                'error_no_embedding_config' => __('Embedding provider and model are required.', 'gpt3-ai-content-generator'),
                'ensure_api_key_for_embedding' => __('Ensure API key is set for the selected embedding provider in AI Settings.', 'gpt3-ai-content-generator'),
                'status_pending' => __('Waiting', 'gpt3-ai-content-generator'),
                'status_processing' => __('Processing', 'gpt3-ai-content-generator'),
                'status_completed' => __('Ready', 'gpt3-ai-content-generator'),
                'status_submitted' => __('Submitted', 'gpt3-ai-content-generator'),
                'status_failed' => __('Failed', 'gpt3-ai-content-generator'),
                'status_stopped' => __('Stopped', 'gpt3-ai-content-generator'),
            ],
        ]);
    }
}

class AIPKit_Autogpt_Assets extends AIPKit_Admin_Asset_Base
{
    public function register_hooks()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_autogpt_assets']);
    }

    public function enqueue_autogpt_assets($hook_suffix)
    {
        $screen = get_current_screen();

        if (! $this->is_aipkit_page($screen)) {
            return;
        }

        $this->enqueue_styles();
        $this->enqueue_admin_main_script();
        $this->ensure_dashboard_core_data();
        $this->localize_data();
    }

    private function enqueue_styles(): void
    {
        $this->register_admin_main_css();
        $this->register_style_bundle(
            'aipkit-admin-autogpt-css',
            'admin-autogpt.bundle.css',
            ['aipkit-admin-main-css'],
            self::asset_version('dist/css/admin-autogpt.bundle.css', $this->version)
        );
        $this->enqueue_style_handle('aipkit-admin-autogpt-css');
    }

    private function localize_data(): void
    {
        if (self::is_script_localized('aipkit-admin-main', 'aipkit_automated_tasks_config')) {
            return;
        }

        $vector_store_localization = AIPKit_Providers::get_vector_store_localization_payload('autogpt_ui');
        $embedding_localization = AIPKit_Providers::get_embedding_localization_payload('autogpt_ui', false);
        $default_cw_prompts = [];
        $default_ce_prompts = class_exists(AIPKit_AutoGPT_Prompt_Definitions::class)
            ? AIPKit_AutoGPT_Prompt_Definitions::get_content_enhancement_defaults()
            : [];
        $default_cc_prompts = class_exists(AIPKit_AutoGPT_Prompt_Definitions::class)
            ? AIPKit_AutoGPT_Prompt_Definitions::get_comment_reply_defaults()
            : [];

        if (class_exists(AIPKit_Content_Writer_Prompts::class)) {
            $default_cw_prompts = [
                'title' => AIPKit_Content_Writer_Prompts::get_default_title_prompt(),
                'content' => AIPKit_Content_Writer_Prompts::get_default_content_prompt(),
                'meta' => AIPKit_Content_Writer_Prompts::get_default_meta_prompt(),
                'keyword' => AIPKit_Content_Writer_Prompts::get_default_keyword_prompt(),
                'image' => AIPKit_Content_Writer_Prompts::get_default_image_prompt(),
                'featured_image' => AIPKit_Content_Writer_Prompts::get_default_featured_image_prompt(),
            ];
        }

        $frequencies = [];
        foreach (wp_get_schedules() as $slug => $details) {
            $frequencies[$slug] = $details['display'];
        }

        wp_localize_script('aipkit-admin-main', 'aipkit_automated_tasks_config', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce_manage_tasks' => wp_create_nonce('aipkit_automated_tasks_manage_nonce'),
            'local_stores' => $vector_store_localization['local_stores'] ?? [],
            'openai_vector_stores' => $vector_store_localization['openai_vector_stores'],
            'google_file_search_stores' => $vector_store_localization['google_file_search_stores'],
            'pinecone_indexes' => $vector_store_localization['pinecone_indexes'],
            'qdrant_collections' => $vector_store_localization['qdrant_collections'],
            'chroma_collections' => $vector_store_localization['chroma_collections'],
            'embedding_provider_map' => $embedding_localization['embedding_provider_map'],
            'embedding_models_by_provider' => $embedding_localization['embedding_models_by_provider'],
            'task_types' => [
                'content_indexing' => [
                    'label' => __('Index WordPress Content', 'gpt3-ai-content-generator'),
                    'icon' => 'dashicons-database',
                    'category' => 'knowledge_base',
                    'description' => __('Index WordPress posts, pages, or products into a vector store for RAG.', 'gpt3-ai-content-generator'),
                    'ui' => [
                        'source_title' => __('Choose content and destination', 'gpt3-ai-content-generator'),
                        'source_description' => __('Select WordPress content and a vector store to keep in sync.', 'gpt3-ai-content-generator'),
                        'schedule_title' => __('How should knowledge updates be handled?', 'gpt3-ai-content-generator'),
                        'primary_action' => __('Create indexing task', 'gpt3-ai-content-generator'),
                        'frequency_policy' => 'conditional',
                        'supports_writing' => false,
                        'supports_context' => false,
                        'supports_images' => false,
                        'risk' => 'normal',
                    ],
                ],
                'content_writing_bulk' => [
                    'label' => __('List', 'gpt3-ai-content-generator'),
                    'icon' => 'dashicons-list-view',
                    'category' => 'content_creation',
                    'description' => __('Generate full articles from a list of titles and optional keywords.', 'gpt3-ai-content-generator'),
                    'ui' => [
                        'source_title' => __('Add topics', 'gpt3-ai-content-generator'),
                        'source_description' => __('Add the topics you want to turn into posts.', 'gpt3-ai-content-generator'),
                        'writing_title' => __('AI', 'gpt3-ai-content-generator'),
                        'context_title' => __('Should we ground the writing in your own knowledge?', 'gpt3-ai-content-generator'),
                        'schedule_title' => __('How should new posts be handled?', 'gpt3-ai-content-generator'),
                        'primary_action' => __('Create and run once', 'gpt3-ai-content-generator'),
                        'frequency_policy' => 'one_time',
                        'supports_writing' => true,
                        'supports_context' => true,
                        'supports_images' => true,
                        'risk' => 'normal',
                    ],
                ],
                'content_writing_csv' => [
                    'label' => __('CSV', 'gpt3-ai-content-generator'),
                    'icon' => 'dashicons-media-spreadsheet',
                    'category' => 'content_creation',
                    'description' => __('Generate articles by importing topics and metadata from a CSV file.', 'gpt3-ai-content-generator'),
                    'ui' => [
                        'source_title' => __('Upload a CSV', 'gpt3-ai-content-generator'),
                        'source_description' => __('Import topics and optional post details.', 'gpt3-ai-content-generator'),
                        'writing_title' => __('AI', 'gpt3-ai-content-generator'),
                        'context_title' => __('Should we ground the writing in your own knowledge?', 'gpt3-ai-content-generator'),
                        'schedule_title' => __('How should new posts be handled?', 'gpt3-ai-content-generator'),
                        'primary_action' => __('Create and run once', 'gpt3-ai-content-generator'),
                        'frequency_policy' => 'one_time',
                        'supports_writing' => true,
                        'supports_context' => true,
                        'supports_images' => true,
                        'risk' => 'normal',
                    ],
                ],
                'content_writing_rss' => [
                    'label' => __('RSS', 'gpt3-ai-content-generator'),
                    'icon' => 'dashicons-rss',
                    'category' => 'content_creation',
                    'description' => __('Automatically generate articles from new items in one or more RSS feeds.', 'gpt3-ai-content-generator'),
                    'pro' => true,
                    'ui' => [
                        'source_title' => __('Connect RSS feeds', 'gpt3-ai-content-generator'),
                        'source_description' => __('Add the feeds you want to monitor.', 'gpt3-ai-content-generator'),
                        'writing_title' => __('AI', 'gpt3-ai-content-generator'),
                        'context_title' => __('Should we ground the writing in your own knowledge?', 'gpt3-ai-content-generator'),
                        'schedule_title' => __('How should new posts be handled?', 'gpt3-ai-content-generator'),
                        'primary_action' => __('Start RSS automation', 'gpt3-ai-content-generator'),
                        'frequency_policy' => 'recurring',
                        'supports_writing' => true,
                        'supports_context' => true,
                        'supports_images' => true,
                        'risk' => 'normal',
                    ],
                ],
                'content_writing_url' => [
                    'label' => __('URL', 'gpt3-ai-content-generator'),
                    'icon' => 'dashicons-admin-links',
                    'category' => 'content_creation',
                    'description' => __('Generate articles by scraping content from a list of URLs to use as context.', 'gpt3-ai-content-generator'),
                    'pro' => true,
                    'ui' => [
                        'source_title' => __('Add web pages', 'gpt3-ai-content-generator'),
                        'source_description' => __('Add pages to use as source material.', 'gpt3-ai-content-generator'),
                        'writing_title' => __('AI', 'gpt3-ai-content-generator'),
                        'context_title' => __('Should we ground the writing in your own knowledge?', 'gpt3-ai-content-generator'),
                        'schedule_title' => __('How should new posts be handled?', 'gpt3-ai-content-generator'),
                        'primary_action' => __('Create and run once', 'gpt3-ai-content-generator'),
                        'frequency_policy' => 'one_time_preferred',
                        'supports_writing' => true,
                        'supports_context' => true,
                        'supports_images' => true,
                        'risk' => 'normal',
                    ],
                ],
                'content_writing_gsheets' => [
                    'label' => __('Google Sheets', 'gpt3-ai-content-generator'),
                    'icon' => 'dashicons-table-col-before',
                    'category' => 'content_creation',
                    'description' => __('Generate articles from a list of topics in a Google Sheets spreadsheet.', 'gpt3-ai-content-generator'),
                    'pro' => true,
                    'ui' => [
                        'source_title' => __('Connect Google Sheets', 'gpt3-ai-content-generator'),
                        'source_description' => __('Connect a sheet and use new rows as content topics.', 'gpt3-ai-content-generator'),
                        'writing_title' => __('AI', 'gpt3-ai-content-generator'),
                        'context_title' => __('Should we ground the writing in your own knowledge?', 'gpt3-ai-content-generator'),
                        'schedule_title' => __('How should new posts be handled?', 'gpt3-ai-content-generator'),
                        'primary_action' => __('Start Sheets automation', 'gpt3-ai-content-generator'),
                        'frequency_policy' => 'recurring',
                        'supports_writing' => true,
                        'supports_context' => true,
                        'supports_images' => true,
                        'risk' => 'normal',
                    ],
                ],
                'enhance_existing_content' => [
                    'label' => __('Update Existing Content', 'gpt3-ai-content-generator'),
                    'icon' => 'dashicons-update',
                    'category' => 'content_enhancement',
                    'description' => __('Automatically update titles, excerpts, or meta descriptions for existing posts based on your custom prompts.', 'gpt3-ai-content-generator'),
                    'pro' => true,
                    'disabled' => false,
                    'ui' => [
                        'source_title' => __('Choose content to rewrite', 'gpt3-ai-content-generator'),
                        'source_description' => __('Select the content and fields AI may change.', 'gpt3-ai-content-generator'),
                        'writing_title' => __('AI', 'gpt3-ai-content-generator'),
                        'context_title' => __('Should we ground the rewrite in your own knowledge?', 'gpt3-ai-content-generator'),
                        'schedule_title' => __('How should rewrites be handled?', 'gpt3-ai-content-generator'),
                        'primary_action' => __('Save rewrite automation', 'gpt3-ai-content-generator'),
                        'frequency_policy' => 'recurring',
                        'supports_writing' => true,
                        'supports_context' => true,
                        'supports_images' => false,
                        'risk' => 'high',
                    ],
                ],
                'community_reply_comments' => [
                    'label' => __('Auto-Reply to Comments', 'gpt3-ai-content-generator'),
                    'icon' => 'dashicons-admin-comments',
                    'category' => 'community_engagement',
                    'description' => __('Automatically generate and post replies to new comments.', 'gpt3-ai-content-generator'),
                    'disabled' => false,
                    'ui' => [
                        'source_title' => __('Choose comments to monitor', 'gpt3-ai-content-generator'),
                        'source_description' => __('Choose where replies may be created and how they are moderated.', 'gpt3-ai-content-generator'),
                        'writing_title' => __('AI', 'gpt3-ai-content-generator'),
                        'schedule_title' => __('How should comment replies be handled?', 'gpt3-ai-content-generator'),
                        'primary_action' => __('Start comment automation', 'gpt3-ai-content-generator'),
                        'frequency_policy' => 'recurring',
                        'supports_writing' => true,
                        'supports_context' => false,
                        'supports_images' => false,
                        'risk' => 'moderate',
                    ],
                ],
            ],
            'default_cw_prompts' => $default_cw_prompts,
            'default_ce_prompts' => $default_ce_prompts,
            'default_cc_prompts' => $default_cc_prompts,
            'frequencies' => $frequencies,
            'text' => [
                'confirm_delete_task_title' => __('Delete automation', 'gpt3-ai-content-generator'),
                /* translators: %s: automation name. */
                'confirm_delete_task' => __('This permanently deletes “%s” and stops future runs. This cannot be undone.', 'gpt3-ai-content-generator'),
                'task_name_required' => __('Task name is required.', 'gpt3-ai-content-generator'),
                'task_type_required' => __('Task type is required.', 'gpt3-ai-content-generator'),
                'target_store_required' => __('Please select a target vector store/index.', 'gpt3-ai-content-generator'),
                'content_type_required' => __('Please select at least one content type.', 'gpt3-ai-content-generator'),
                'indexing_behavior_required' => __('Choose whether to index existing content, keep future content in sync, or both.', 'gpt3-ai-content-generator'),
                'embedding_provider_required' => __('Embedding provider is required for this vector database.', 'gpt3-ai-content-generator'),
                'embedding_model_required' => __('Embedding model is required for this vector database.', 'gpt3-ai-content-generator'),
                'context_source_required' => __('Please select a knowledge source before enabling context.', 'gpt3-ai-content-generator'),
                'content_title_required_cw_task' => __('Please add a topic.', 'gpt3-ai-content-generator'),
                'csv_required_cw_task' => __('Please upload a CSV file.', 'gpt3-ai-content-generator'),
                'rss_required_cw_task' => __('Please add at least one RSS feed.', 'gpt3-ai-content-generator'),
                'url_required_cw_task' => __('Please add at least one URL.', 'gpt3-ai-content-generator'),
                'gsheets_id_required_cw_task' => __('Please add a Google Sheet ID.', 'gpt3-ai-content-generator'),
                'gsheets_credentials_required_cw_task' => __('Please add Google Sheets credentials.', 'gpt3-ai-content-generator'),
                'ai_config_required_cw_task' => __('AI Provider and Model are required for Content Writing task.', 'gpt3-ai-content-generator'),
                'comment_post_type_required' => __('Please select at least one content type to monitor.', 'gpt3-ai-content-generator'),
                'comment_ai_config_required' => __('AI Provider and Model are required for Comment Replies.', 'gpt3-ai-content-generator'),
                'comment_prompt_required' => __('The reply prompt cannot be empty.', 'gpt3-ai-content-generator'),
                'saving_task' => __('Saving Task...', 'gpt3-ai-content-generator'),
                'deleting_task' => __('Deleting Task...', 'gpt3-ai-content-generator'),
                'running_task' => __('Initiating Run...', 'gpt3-ai-content-generator'),
                'pausing_task' => __('Pausing Task...', 'gpt3-ai-content-generator'),
                'resuming_task' => __('Resuming Task...', 'gpt3-ai-content-generator'),
                'save_task_button' => __('Save changes', 'gpt3-ai-content-generator'),
                'create_task_button' => __('Create automation', 'gpt3-ai-content-generator'),
                'edit_task_title' => __('Edit automation', 'gpt3-ai-content-generator'),
                'create_task_title' => __('Create automation', 'gpt3-ai-content-generator'),
                'loading_stores' => __('Loading stores...', 'gpt3-ai-content-generator'),
                'loading_indexes' => __('Loading indexes...', 'gpt3-ai-content-generator'),
                'select_target_store' => __('-- Select Target Store --', 'gpt3-ai-content-generator'),
                'select_target_index' => __('-- Select Target Index --', 'gpt3-ai-content-generator'),
                'select_target_collection' => __('-- Select Target Collection --', 'gpt3-ai-content-generator'),
                'no_targets_found_configure' => __('No targets found. Configure in AI Training.', 'gpt3-ai-content-generator'),
                'loading_models' => __('Loading models...', 'gpt3-ai-content-generator'),
                'select_embedding_model' => __('-- Select Model --', 'gpt3-ai-content-generator'),
                'no_embedding_models_sync' => __('No models - Sync in AI Settings.', 'gpt3-ai-content-generator'),
                'loading_tasks' => __('Loading tasks...', 'gpt3-ai-content-generator'),
                'error_loading_tasks' => __('Error loading tasks:', 'gpt3-ai-content-generator'),
                'no_tasks_configured' => __('No automated tasks configured yet.', 'gpt3-ai-content-generator'),
                'edit_button' => __('Edit', 'gpt3-ai-content-generator'),
                'pause_button' => __('Pause', 'gpt3-ai-content-generator'),
                'resume_button' => __('Resume', 'gpt3-ai-content-generator'),
                'run_now_button' => __('Run Now', 'gpt3-ai-content-generator'),
                'task_not_active_run_title' => __('Task must be active to run', 'gpt3-ai-content-generator'),
                'delete_button' => __('Delete', 'gpt3-ai-content-generator'),
                'never_run' => __('Never', 'gpt3-ai-content-generator'),
                'not_scheduled' => __('Not Scheduled', 'gpt3-ai-content-generator'),
                'task_deleted_success' => __('Task deleted successfully.', 'gpt3-ai-content-generator'),
                'error_deleting_task' => __('Error deleting task:', 'gpt3-ai-content-generator'),
                'task_status_updated' => __('Task status updated to', 'gpt3-ai-content-generator'),
                'error_updating_status' => __('Error updating task status:', 'gpt3-ai-content-generator'),
                'task_run_initiated' => __('Task run initiated. Check queue below for progress.', 'gpt3-ai-content-generator'),
                'error_initiating_run' => __('Error initiating task run:', 'gpt3-ai-content-generator'),
                'loading_queue' => __('Loading queue items...', 'gpt3-ai-content-generator'),
                'error_loading_queue' => __('Error loading queue:', 'gpt3-ai-content-generator'),
                'queue_empty' => __('Task queue is currently empty.', 'gpt3-ai-content-generator'),
                'target_id_prefix' => __('Target ID:', 'gpt3-ai-content-generator'),
                'task_id_prefix' => __('Task ID:', 'gpt3-ai-content-generator'),
                'not_applicable' => __('N/A', 'gpt3-ai-content-generator'),
                'added_at_label' => __('Added', 'gpt3-ai-content-generator'),
                'scheduled_for_label' => __('Scheduled', 'gpt3-ai-content-generator'),
                'item_singular' => __('item', 'gpt3-ai-content-generator'),
                'item_plural' => __('items', 'gpt3-ai-content-generator'),
                'page_label' => __('Page', 'gpt3-ai-content-generator'),
                'of_label' => __('of', 'gpt3-ai-content-generator'),
                'previous_button' => __('Prev', 'gpt3-ai-content-generator'),
                'next_button' => __('Next', 'gpt3-ai-content-generator'),
                'confirm_delete_queue_item_title' => __('Remove item from queue', 'gpt3-ai-content-generator'),
                /* translators: %s: queue item name. */
                'confirm_delete_queue_item' => __('This removes “%s” from the queue. This cannot be undone.', 'gpt3-ai-content-generator'),
                'select_queue_item' => __('Select queue item', 'gpt3-ai-content-generator'),
                /* translators: %d: Number of selected queue items. */
                'queue_item_selected' => __('%d item selected', 'gpt3-ai-content-generator'),
                /* translators: %d: Number of selected queue items. */
                'queue_items_selected' => __('%d items selected', 'gpt3-ai-content-generator'),
                'confirm_delete_selected_queue_items_title' => __('Delete queue items', 'gpt3-ai-content-generator'),
                /* translators: %d: Number of selected queue items. */
                'confirm_delete_selected_queue_items' => __('This permanently deletes %d selected queue items. This cannot be undone.', 'gpt3-ai-content-generator'),
                'queue_item_deleted' => __('Queue item deleted.', 'gpt3-ai-content-generator'),
                'queue_items_deleted' => __('Selected queue items deleted.', 'gpt3-ai-content-generator'),
                'error_deleting_queue_item' => __('Error deleting item:', 'gpt3-ai-content-generator'),
                'error_deleting_queue_items' => __('Error deleting queue items:', 'gpt3-ai-content-generator'),
                'retry_button' => __('Retry', 'gpt3-ai-content-generator'),
                'item_marked_retry' => __('Item marked for retry. Queue processing will pick it up.', 'gpt3-ai-content-generator'),
                'error_retrying_item' => __('Error retrying item:', 'gpt3-ai-content-generator'),
                'task_singular' => __('task', 'gpt3-ai-content-generator'),
                'task_plural' => __('tasks', 'gpt3-ai-content-generator'),
                'rows_per_page' => __('Rows per page', 'gpt3-ai-content-generator'),
            ],
        ]);
    }
}

class AIPKit_AI_Forms_Assets extends AIPKit_Admin_Asset_Base
{
    public function register_hooks()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_ai_forms_assets']);
    }

    public function enqueue_ai_forms_assets($hook_suffix)
    {
        $screen = get_current_screen();

        if (! $this->is_aipkit_page($screen)) {
            return;
        }

        $this->enqueue_admin_main_script();
        $this->ensure_dashboard_core_data();
        $this->localize_data();
    }

    private function localize_data(): void
    {
        if (self::is_script_localized('aipkit-admin-main', 'aipkit_ai_forms_config')) {
            return;
        }

        $vector_store_localization = AIPKit_Providers::get_vector_store_localization_payload('ai_forms_ui');
        $embedding_localization = AIPKit_Providers::get_embedding_localization_payload('ai_forms_ui', false);

        wp_localize_script('aipkit-admin-main', 'aipkit_ai_forms_config', [
            'nonce_manage_forms' => wp_create_nonce('aipkit_manage_ai_forms_nonce'),
            'current_user_id' => get_current_user_id(),
            'vectorStores' => $vector_store_localization['vectorStores'],
            'embeddingProviderMap' => $embedding_localization['embeddingProviderMap'],
            'embeddingModels' => $embedding_localization['embeddingModels'],
            'text' => [
                'savingForm' => __('Saving form...', 'gpt3-ai-content-generator'),
                'formSaved' => __('Form saved successfully!', 'gpt3-ai-content-generator'),
                'errorSavingForm' => __('Error saving form.', 'gpt3-ai-content-generator'),
                'generatingForm' => __('Generating form draft...', 'gpt3-ai-content-generator'),
                'formGenerated' => __('Form draft generated. Review and save it when ready.', 'gpt3-ai-content-generator'),
                'errorGeneratingForm' => __('Error generating form draft.', 'gpt3-ai-content-generator'),
                'loadingForms' => __('Loading forms...', 'gpt3-ai-content-generator'),
                'deletingForm' => __('Deleting form...', 'gpt3-ai-content-generator'),
                'duplicatingForm' => __('Duplicating...', 'gpt3-ai-content-generator'),
                'errorDuplicatingForm' => __('Error duplicating form.', 'gpt3-ai-content-generator'),
                'confirmReplaceGeneratedDraft' => __('Generating a new draft will replace the current title, prompt, and fields in the editor. Continue?', 'gpt3-ai-content-generator'),
                'formTitleRequired' => __('Form title is required.', 'gpt3-ai-content-generator'),
                'promptTemplateRequired' => __('Prompt template is required.', 'gpt3-ai-content-generator'),
                'generatorPromptRequired' => __('Describe the AI task before generating a form draft.', 'gpt3-ai-content-generator'),
                'generatorModelRequired' => __('Select an engine and model before generating a form draft.', 'gpt3-ai-content-generator'),
                'confirmSaveEmptyForm' => __('This form currently has no fields. Saving now will remove previously configured fields. Do you want to continue?', 'gpt3-ai-content-generator'),
                'editFormTitle' => __('Edit AI Form', 'gpt3-ai-content-generator'),
                'createNewFormTitle' => __('Create New AI Form', 'gpt3-ai-content-generator'),
                'noOptionsConfigured' => __('No options configured', 'gpt3-ai-content-generator'),
                'settingsLabel' => __('Label Text', 'gpt3-ai-content-generator'),
                'settingsFieldId' => __('Field Variable Name (for prompt)', 'gpt3-ai-content-generator'),
                'settingsFieldIdHelp' => __('Use as {your_variable_name} in the Prompt Template. Must be unique and contain only letters, numbers, underscores.', 'gpt3-ai-content-generator'),
                'settingsPlaceholder' => __('Placeholder Text', 'gpt3-ai-content-generator'),
                'settingsRequired' => __('Required Field', 'gpt3-ai-content-generator'),
                'settingsSelectOptions' => __('Options (Value|Text)', 'gpt3-ai-content-generator'),
                'settingsSelectOptionValue' => __('Value', 'gpt3-ai-content-generator'),
                'settingsSelectOptionText' => __('Display Text', 'gpt3-ai-content-generator'),
                'settingsAddOption' => __('Add Option', 'gpt3-ai-content-generator'),
                'settingsRemoveOption' => __('Remove Option', 'gpt3-ai-content-generator'),
                'settingsDoneButton' => __('Done', 'gpt3-ai-content-generator'),
                'errorUniqueFieldId' => __('Field Variable Name must be unique and valid (letters, numbers, underscores).', 'gpt3-ai-content-generator'),
            ],
        ]);
    }
}

class AIPKit_Woocommerce_Writer_Assets extends AIPKit_Admin_Asset_Base
{
    public function register_hooks()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets($hook_suffix)
    {
        $screen = get_current_screen();

        if (! $this->is_aipkit_page($screen) || ! class_exists(aipkit_dashboard::class)) {
            return;
        }

        $modules = aipkit_dashboard::get_module_settings();
        if (empty($modules['content_writer']) || ! class_exists('WooCommerce')) {
            return;
        }

        $this->enqueue_admin_main_css();
        $this->enqueue_admin_main_script();
        $this->ensure_dashboard_core_data();
    }
}
