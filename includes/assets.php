<?php


namespace WPAICG\Includes;

// Ensure this file is only loaded by WordPress
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * AIPKit_Shared_Assets_Manager
 * Registers assets shared across admin and public contexts.
 */
class AIPKit_Shared_Assets_Manager
{
    /**
     * Build a versioned plugin asset URL for lazy-loaded frontend bundles.
     *
     * @param string $relative_path
     * @return string
     */
    private static function get_versioned_asset_url(string $relative_path): string
    {
        $version = defined('WPAICG_VERSION') ? (string) WPAICG_VERSION : '1.0.0';

        return add_query_arg(
            'ver',
            rawurlencode($version),
            WPAICG_PLUGIN_URL . ltrim($relative_path, '/')
        );
    }

    /**
     * Return public asset URLs used by lazy frontend loaders.
     *
     * @return array<string, string>
     */
    public static function get_public_asset_urls(): array
    {
        $markdownit_url = self::get_versioned_asset_url('dist/vendor/js/markdown-it.min.js');
        $asset_urls = [
            'markdownIt' => $markdownit_url,
            'markdownit' => $markdownit_url,
            'chatSidebar' => self::get_versioned_asset_url('dist/js/public-chat-sidebar.bundle.js'),
            'chatSidebarCss' => self::get_versioned_asset_url('dist/css/public-chat-sidebar.bundle.css'),
            'chatStt' => self::get_versioned_asset_url('dist/js/public-chat-stt.bundle.js'),
            'chatUploads' => self::get_versioned_asset_url('dist/js/public-chat-uploads.bundle.js'),
            'chatTts' => self::get_versioned_asset_url('dist/js/public-chat-tts.bundle.js'),
            'chatStarters' => self::get_versioned_asset_url('dist/js/public-chat-starters.bundle.js'),
            'chatImageCommand' => self::get_versioned_asset_url('dist/js/public-chat-image-command.bundle.js'),
        ];

        // These files are stripped from Free. Do not advertise missing asset URLs.
        foreach ([
            'chatRealtime' => 'dist/js/public-chat-realtime.bundle.js',
            'chatRealtimeCss' => 'dist/css/public-chat-realtime.bundle.css',
            'chatPdf' => 'dist/js/public-chat-pdf.bundle.js',
            'chatFileUploads' => 'dist/js/public-chat-file-uploads.bundle.js',
            'chatConsent' => 'dist/js/public-chat-consent.bundle.js',
            'chatForms' => 'dist/js/public-chat-forms.bundle.js',
            'chatFileUploadsCss' => 'dist/css/public-chat-file-uploads.bundle.css',
            'chatConsentCss' => 'dist/css/public-chat-consent.bundle.css',
            'chatFormsCss' => 'dist/css/public-chat-forms.bundle.css',
        ] as $feature => $path) {
            if (file_exists(WPAICG_PLUGIN_DIR . $path)) {
                $asset_urls[$feature] = self::get_versioned_asset_url($path);
            }
        }

        return $asset_urls;
    }

    public static function get_public_image_generator_config(): array
    {
        if (!class_exists('\\WPAICG\\AIPKit_Providers')) {
            $providers_path = WPAICG_PLUGIN_DIR . 'classes/ai/settings.php';
            if (file_exists($providers_path)) {
                require_once $providers_path;
            }
        }
        if (!class_exists('\\WPAICG\\Images\\AIPKit_Image_Settings_Ajax_Handler')) {
            $settings_handler_path = WPAICG_PLUGIN_DIR . 'classes/image-generator/settings-actions.php';
            if (file_exists($settings_handler_path)) {
                require_once $settings_handler_path;
            }
        }

        $ui_text_settings = [];
        if (class_exists('\\WPAICG\\Images\\AIPKit_Image_Settings_Ajax_Handler')) {
            $all_image_settings = \WPAICG\Images\AIPKit_Image_Settings_Ajax_Handler::get_settings();
            $ui_text_settings = $all_image_settings['ui_text'] ?? [];
        }
        $get_ui_text = static function (string $key, string $default) use ($ui_text_settings): string {
            if (!isset($ui_text_settings[$key])) {
                return $default;
            }
            $value = sanitize_text_field((string) $ui_text_settings[$key]);
            return $value !== '' ? $value : $default;
        };
        $providers_available = class_exists('\\WPAICG\\AIPKit_Providers');
        $model_catalog_config = class_exists('\\WPAICG\\Core\\Models\\AIPKit_Model_Catalog')
            ? \WPAICG\Core\Models\AIPKit_Model_Catalog::get_client_config()
            : ['defaults' => [], 'seeds' => []];

        return [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'text' => [
                'generating' => __('Generating...', 'gpt3-ai-content-generator'),
                'generationStarted' => __('Image generation started.', 'gpt3-ai-content-generator'),
                'editing' => __('Editing...', 'gpt3-ai-content-generator'),
                'error' => __('Error generating image.', 'gpt3-ai-content-generator'),
                'guestAccessDisabled' => __('Image generation is not available for guests.', 'gpt3-ai-content-generator'),
                'generateButton' => $get_ui_text('generate_label', __('Generate', 'gpt3-ai-content-generator')),
                'noPrompt' => __('Please enter a prompt.', 'gpt3-ai-content-generator'),
                'imageReady' => __('Your image is ready.', 'gpt3-ai-content-generator'),
                'videoReady' => __('Your video is ready.', 'gpt3-ai-content-generator'),
                'tryAgain' => __('Try again', 'gpt3-ai-content-generator'),
                'downloadResult' => __('Download result', 'gpt3-ai-content-generator'),
                'addFavorite' => __('Add to favorites', 'gpt3-ai-content-generator'),
                'removeFavorite' => __('Remove from favorites', 'gpt3-ai-content-generator'),
                'favoriteAdded' => __('Added to favorites.', 'gpt3-ai-content-generator'),
                'favoriteRemoved' => __('Removed from favorites.', 'gpt3-ai-content-generator'),
                'favoriteFailed' => __('Could not update favorite.', 'gpt3-ai-content-generator'),
                'historyLoadFailed' => __('Could not load image history.', 'gpt3-ai-content-generator'),
                'useAsEditSource' => __('Use as edit source', 'gpt3-ai-content-generator'),
                'expandResult' => __('Expand to fullscreen', 'gpt3-ai-content-generator'),
                'deleteResult' => __('Delete result', 'gpt3-ai-content-generator'),
                'deleteFailed' => __('Could not delete this result.', 'gpt3-ai-content-generator'),
                'showResult' => __('Show result', 'gpt3-ai-content-generator'),
                'copyPrompt' => __('Copy prompt', 'gpt3-ai-content-generator'),
                'copied' => __('Copied', 'gpt3-ai-content-generator'),
                'promptCopied' => __('Prompt copied.', 'gpt3-ai-content-generator'),
                'copyFailed' => __('Could not copy prompt.', 'gpt3-ai-content-generator'),
                'mediaLoadFailed' => __('The generated media could not be displayed.', 'gpt3-ai-content-generator'),
                'noMediaReturned' => __('No media was returned. Try again.', 'gpt3-ai-content-generator'),
                'viewFullImage' => __('Click to view full image', 'gpt3-ai-content-generator'),
                'viewFullVideo' => __('Click to view full video', 'gpt3-ai-content-generator'),
                'openrouterModelUnsupported' => __('Selected OpenRouter model does not support image generation.', 'gpt3-ai-content-generator'),
                'editUploadRequired' => __('Please upload an image to edit.', 'gpt3-ai-content-generator'),
                'editProviderUnsupported' => __('The selected provider does not support image editing.', 'gpt3-ai-content-generator'),
                'editModelUnsupported' => __('Selected model does not support image editing.', 'gpt3-ai-content-generator'),
                /* translators: %s: supported image formats. */
                'editUploadTypes' => __('Invalid image type. Allowed types: %s.', 'gpt3-ai-content-generator'),
                /* translators: %s: upload size limit. */
                'editUploadSize' => __('Source image is too large. Maximum allowed size is %s.', 'gpt3-ai-content-generator'),
                'checkStatus' => __('Check status', 'gpt3-ai-content-generator'),
                'generateNewImage' => __('Generate new image', 'gpt3-ai-content-generator'),
                'cloudImageUnknown' => __('The image response was interrupted. This request may have used credits. Check its status before generating again.', 'gpt3-ai-content-generator'),
                'cloudImageSettled' => __('This request completed and used credits, but its image was not received. Check your image history. Generating again starts a new request.', 'gpt3-ai-content-generator'),
                'cloudImageFinished' => __('This request has finished. You can start a new image request.', 'gpt3-ai-content-generator'),
                'cloudImagePending' => __('This request is still processing or needs review. Check again before generating another image.', 'gpt3-ai-content-generator'),
                'cloudStatusUnavailable' => __('The request status could not be checked. Check again before starting another request.', 'gpt3-ai-content-generator'),
                'editDropUsePicker' => __('Could not attach dropped file automatically. Click to choose file.', 'gpt3-ai-content-generator'),
                'editHistoryLoadFailed' => __('Could not load the selected image for editing.', 'gpt3-ai-content-generator'),
                'editHistoryUnavailable' => __('Image editing is not available in the current setup.', 'gpt3-ai-content-generator'),
                'editHistoryLoaded' => __('Source image loaded. Describe your edits and click Edit Image.', 'gpt3-ai-content-generator'),
                'noEditCapableModels' => __('(No edit-capable models available)', 'gpt3-ai-content-generator'),
                'noOpenRouterImageModels' => __('(No image-capable OpenRouter models found)', 'gpt3-ai-content-generator'),
                'noXAIImageModels' => __('(No xAI image models found)', 'gpt3-ai-content-generator'),
                'noModelsAvailable' => __('(No models available)', 'gpt3-ai-content-generator'),
                'imageModelsGroup' => __('Image Models', 'gpt3-ai-content-generator'),
                'videoModelsGroup' => __('Video Models', 'gpt3-ai-content-generator'),
                'configurationMissing' => __('Error: Configuration missing.', 'gpt3-ai-content-generator'),
                'coreUiMissing' => __('Error: Core UI elements missing.', 'gpt3-ai-content-generator'),
                'missingRequiredSettings' => __('Error: Missing required image generation settings.', 'gpt3-ai-content-generator'),
                'noVideoDataFound' => __('Error: No video data found.', 'gpt3-ai-content-generator'),
                'noImageDataFound' => __('Error: No image data found.', 'gpt3-ai-content-generator'),
                'deleteConfigMissing' => __('Error: Cannot delete image. Configuration missing.', 'gpt3-ai-content-generator'),
                'deleteImageErrorPrefix' => __('Error deleting image:', 'gpt3-ai-content-generator'),
                'revisedPromptPrefix' => __('Revised:', 'gpt3-ai-content-generator'),
                'generatingVideo' => __('Generating Video...', 'gpt3-ai-content-generator'),
                'videoGenerationInProgress' => __('Video generation in progress...', 'gpt3-ai-content-generator'),
                'generatingVideoProgress' => __('Generating video...', 'gpt3-ai-content-generator'),
                'videoGenerationTimedOut' => __('The video is taking longer than expected. Check its status before starting another video.', 'gpt3-ai-content-generator'),
                'videoGenerationFailed' => __('Video generation failed:', 'gpt3-ai-content-generator'),
                'videoGenerationFailedShort' => __("We couldn't generate that video. Try again.", 'gpt3-ai-content-generator'),
            ],
            'model_catalog' => $model_catalog_config,
            'openai_models' => $providers_available ? \WPAICG\AIPKit_Providers::get_openai_image_models() : [],
            'azure_models' => $providers_available ? \WPAICG\AIPKit_Providers::get_azure_image_models() : [],
            'google_models' => [
                'image' => $providers_available ? \WPAICG\AIPKit_Providers::get_google_image_models() : [],
                'video' => $providers_available ? \WPAICG\AIPKit_Providers::get_google_video_models() : [],
            ],
            'openrouter_image_models' => $providers_available ? \WPAICG\AIPKit_Providers::get_openrouter_image_models() : [],
            'cloud_image_models' => class_exists('\\WPAICG\\Cloud\\Connection') ? array_values(array_merge(
                \WPAICG\Cloud\Connection::media_models('image_generate'), \WPAICG\Cloud\Connection::media_models('image_edit'))) : [],
            'xai_image_models' => $providers_available ? \WPAICG\AIPKit_Providers::get_xai_image_models() : [],
            'replicate_models' => $providers_available ? \WPAICG\AIPKit_Providers::get_replicate_models() : [],
        ];
    }

    /**
     * Register scripts and styles shared across admin and public contexts.
     *
     * @param string $plugin_version The current plugin version.
     */
    public static function register(string $plugin_version)
    {
        if (!wp_style_is('aipkit-shared-model-selector', 'registered')) {
            $selector_path = 'dist/css/shared-model-selector.bundle.css';
            $selector_mtime = file_exists(WPAICG_PLUGIN_DIR . $selector_path)
                ? filemtime(WPAICG_PLUGIN_DIR . $selector_path)
                : false;
            wp_register_style(
                'aipkit-shared-model-selector',
                WPAICG_PLUGIN_URL . $selector_path,
                [],
                $selector_mtime ? (string) $selector_mtime : $plugin_version
            );
        }

        if (!wp_script_is('aipkit-shared-model-selector', 'registered')) {
            $selector_script_path = 'dist/js/shared-model-selector.bundle.js';
            $selector_script_mtime = file_exists(WPAICG_PLUGIN_DIR . $selector_script_path)
                ? filemtime(WPAICG_PLUGIN_DIR . $selector_script_path)
                : false;
            wp_register_script(
                'aipkit-shared-model-selector',
                WPAICG_PLUGIN_URL . $selector_script_path,
                ['wp-i18n'],
                $selector_script_mtime ? (string) $selector_script_mtime : $plugin_version,
                true
            );
            wp_set_script_translations('aipkit-shared-model-selector', 'gpt3-ai-content-generator', WPAICG_PLUGIN_DIR . 'languages');
        }

        if (!wp_script_is('aipkit_markdown-it', 'registered')) {
            wp_register_script('aipkit_markdown-it', WPAICG_PLUGIN_URL . 'dist/vendor/js/markdown-it.min.js', [], '14.3.2', true);
        }
    }

    /**
     * Expose shared public asset URLs to frontend bundles that lazy-load vendor scripts.
     *
     * @param string $handle The registered script handle that should receive the config.
     */
    public static function attach_public_asset_urls(string $handle): void
    {
        if (!wp_script_is($handle, 'registered')) {
            return;
        }

        static $attached_handles = [];
        if (isset($attached_handles[$handle])) {
            return;
        }

        $asset_urls = self::get_public_asset_urls();

        wp_add_inline_script(
            $handle,
            'window.aipkitPublicAssetUrls = Object.assign({}, window.aipkitPublicAssetUrls || {}, ' . wp_json_encode($asset_urls) . ');',
            'before'
        );

        $attached_handles[$handle] = true;
    }
}
