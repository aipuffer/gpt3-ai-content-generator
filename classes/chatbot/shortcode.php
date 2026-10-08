<?php
/**
 * Chatbot shortcode configuration, rendering and site-wide placement.
 * Paid controls are supplied by the licensed lib/chatbot/frontend.php companion.
 */

namespace WPAICG\Chat\Frontend\Shortcode\ConfiguratorMethods;

use WPAICG\Chat\Storage\BotSettingsManager;
use WPAICG\AIPKit_Providers;
use WPAICG\Lib\Chat\ShortcodeFeatures;
use WPAICG\aipkit_dashboard; // For addon/plan status checks
use WPAICG\Core\Models\AIPKit_Model_Catalog;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Prepares the conversation starters array.
 *
 * @param array $settings Bot settings.
 * @param bool $starters_ui_enabled Flag indicating if starters UI is enabled.
 * @return array The array of conversation starter strings.
 */
function get_conversation_starters_logic(array $settings, bool $starters_ui_enabled): array {
    $starters_array = [];
    if ($starters_ui_enabled) {
        $starters_raw = $settings['conversation_starters'] ?? [];
        if (!empty($starters_raw) && is_array($starters_raw)) { // Check if it's already an array
            $starters_array = $starters_raw;
        } elseif (!empty($starters_raw) && is_string($starters_raw)) { // Handle JSON string if somehow passed
            $decoded_starters = json_decode($starters_raw, true);
            if (is_array($decoded_starters)) {
                $starters_array = $decoded_starters;
            }
        }

        if (empty($starters_array)) {
            // Fallback to default starters if the setting is empty or invalid
            $starters_array = method_exists(BotSettingsManager::class, 'get_default_conversation_starters')
                ? BotSettingsManager::get_default_conversation_starters()
                : [
                    __('What can you do?', 'gpt3-ai-content-generator'),
                    __('Tell me a fun fact', 'gpt3-ai-content-generator'),
                ];
        }
    }
    return $starters_array;
}

/**
 * Prepares the consent-related text fields.
 *
 * @param array $settings Bot settings.
 * @return array An array containing consent_title, consent_message, and consent_button texts.
 */
function get_consent_settings_logic(array $settings): array {
    $default_title = __('Consent Required', 'gpt3-ai-content-generator');
    $default_message = __('Before starting the conversation, please agree to our Terms of Service and Privacy Policy.', 'gpt3-ai-content-generator');
    $default_button = __('I Agree', 'gpt3-ai-content-generator');

    $consent_title = $settings['consent_title'] ?? '';
    $consent_message = $settings['consent_message'] ?? '';
    $consent_button = $settings['consent_button'] ?? '';

    return [
        'consent_title' => $consent_title !== '' ? $consent_title : $default_title,
        'consent_message' => $consent_message !== '' ? $consent_message : $default_message,
        'consent_button' => $consent_button !== '' ? $consent_button : $default_button,
    ];
}

/**
 * Prepares TTS (Text-to-Speech) related settings.
 *
 * @param array $settings Bot settings.
 * @return array An array containing tts_provider and tts_voice_id.
 */
function get_tts_settings_logic(array $settings): array {
    if (!class_exists(BotSettingsManager::class)) {
        return [
            'tts_provider' => 'Google',
            'tts_voice_id' => '',
            'tts_auto_play' => false,
            'tts_google_model_id' => AIPKit_Model_Catalog::get_default_id('GoogleTTS'),
            'tts_openai_model_id' => AIPKit_Model_Catalog::get_default_id('OpenAITTS'),
            'tts_elevenlabs_model_id' => AIPKit_Model_Catalog::get_default_id('ElevenLabsModels'),
        ];
    }
    $tts_provider = $settings['tts_provider'] ?? BotSettingsManager::DEFAULT_TTS_PROVIDER;
    $tts_voice_id = $settings['tts_voice_id'] ?? ''; // This should be the combined one after bot settings are fetched
    $tts_auto_play = ($settings['tts_auto_play'] ?? BotSettingsManager::DEFAULT_TTS_AUTO_PLAY) === '1';
    $tts_google_model_id = $settings['tts_google_model_id'] ?? BotSettingsManager::get_default_model_id('GoogleTTS');
    $tts_openai_model_id = $settings['tts_openai_model_id'] ?? BotSettingsManager::get_default_model_id('OpenAITTS');
    $tts_elevenlabs_model_id = $settings['tts_elevenlabs_model_id'] ?? BotSettingsManager::get_default_model_id('ElevenLabsModels');

    return [
        'tts_provider' => $tts_provider,
        'tts_voice_id' => $tts_voice_id,
        'tts_auto_play' => $tts_auto_play,
        'tts_google_model_id' => $tts_google_model_id,
        'tts_openai_model_id' => $tts_openai_model_id,
        'tts_elevenlabs_model_id' => $tts_elevenlabs_model_id,
    ];
}

/**
 * Prepares the `text` array for localization in JavaScript.
 *
 * @param array $settings Bot settings.
 * @param array $consent_texts Prepared consent texts.
 * @return array The array of text labels.
 */
function get_text_labels_logic(array $settings, array $consent_texts): array {
    $custom_sources_label = isset($settings['sources_label'])
        ? sanitize_text_field((string) $settings['sources_label'])
        : '';
    $custom_searching_web_text = isset($settings['searching_web_text'])
        ? sanitize_text_field((string) $settings['searching_web_text'])
        : '';
    $custom_retrieving_context_text = isset($settings['retrieving_context_text'])
        ? sanitize_text_field((string) $settings['retrieving_context_text'])
        : '';

    return [
        'sendMessage' => __('Send Message', 'gpt3-ai-content-generator'),
        'sending' => __('Sending...', 'gpt3-ai-content-generator'),
        'stopResponse' => __('Stop Response', 'gpt3-ai-content-generator'),
        'typeMessage' => $settings['input_placeholder'] ?? __('Type your message...', 'gpt3-ai-content-generator'),
        'thinking' => __('Thinking', 'gpt3-ai-content-generator'),
        'streaming' => __('Streaming...', 'gpt3-ai-content-generator'),
        'statusThinking' => __('Thinking...', 'gpt3-ai-content-generator'),
        'statusProcessing' => __('Processing...', 'gpt3-ai-content-generator'),
        'statusSearchingWeb' => $custom_searching_web_text !== '' ? $custom_searching_web_text : __('Searching web...', 'gpt3-ai-content-generator'),
        'statusRetrievingContext' => $custom_retrieving_context_text,
        'statusCallingTool' => __('Calling tool...', 'gpt3-ai-content-generator'),
        'errorPrefix' => __('Error:', 'gpt3-ai-content-generator'),
        // Shown as-is, without the error label (see WPAICG\Cloud\Connection::billing_message()).
        'userPrefix' => __('User', 'gpt3-ai-content-generator'),
        'sources' => $custom_sources_label !== '' ? $custom_sources_label : __('Sources', 'gpt3-ai-content-generator'),
        'source' => $custom_sources_label !== '' ? $custom_sources_label : __('Source', 'gpt3-ai-content-generator'),
        'clearChat' => __('Clear Chat', 'gpt3-ai-content-generator'),
        'fullscreen' => __('Fullscreen', 'gpt3-ai-content-generator'),
        'exitFullscreen' => __('Exit Fullscreen', 'gpt3-ai-content-generator'),
        'download' => __('Download Transcript', 'gpt3-ai-content-generator'),
        'downloadTxt' => __('Download TXT', 'gpt3-ai-content-generator'),
        'downloadPdf' => __('Download PDF', 'gpt3-ai-content-generator'),
        'downloadEmpty' => __('Nothing to download.', 'gpt3-ai-content-generator'),
        'pdfError' => __('Could not open the print window. Please allow popups and try again.', 'gpt3-ai-content-generator'),
        'streamError' => __('Stream error. Please try again.', 'gpt3-ai-content-generator'),
        'connError' => __('Connection error. Please try again.', 'gpt3-ai-content-generator'),
        'initialGreeting' => $settings['greeting'] ?? __('Hello there!', 'gpt3-ai-content-generator'),
        'initialSubgreeting' => $settings['subgreeting'] ?? __('How can I help you today?', 'gpt3-ai-content-generator'),
        'sidebarToggle' => __('Toggle Conversation Sidebar', 'gpt3-ai-content-generator'),
        'newChat' => __('New Chat', 'gpt3-ai-content-generator'),
        'conversations' => __('Conversations', 'gpt3-ai-content-generator'),
        'searchConversations' => __('Search conversations', 'gpt3-ai-content-generator'),
        'historyGuests' => __('History unavailable for guests.', 'gpt3-ai-content-generator'),
        'historyEmpty' => __('No past conversations.', 'gpt3-ai-content-generator'),
        'historySidebarEmpty' => __('No conversations yet — start one to see it here.', 'gpt3-ai-content-generator'),
        'historySearchEmpty' => __('No conversations match your search.', 'gpt3-ai-content-generator'),
        'historyLoadingOlder' => __('Loading older conversations...', 'gpt3-ai-content-generator'),
        /* translators: %s: Uploaded file name. */
        'fileUploading' => __('Uploading %s', 'gpt3-ai-content-generator'),
        /* translators: %s: Uploaded file name. */
        'fileIndexing' => __('Indexing %s', 'gpt3-ai-content-generator'),
        'detachFile' => __('Detach file', 'gpt3-ai-content-generator'),
        'feedbackLikeLabel' => __('Like response', 'gpt3-ai-content-generator'),
        'feedbackDislikeLabel' => __('Dislike response', 'gpt3-ai-content-generator'),
        'feedbackSubmitted' => __('Feedback submitted', 'gpt3-ai-content-generator'),
        'copyActionLabel' => __('Copy response', 'gpt3-ai-content-generator'),
        'copySuccess' => __('Copied!', 'gpt3-ai-content-generator'),
        'copyCodeLabel' => __('Copy code', 'gpt3-ai-content-generator'),
        'consentTitle' => $consent_texts['consent_title'],
        'consentMessage' => $consent_texts['consent_message'],
        'consentButton' => $consent_texts['consent_button'],
        'playActionLabel' => __('Play audio', 'gpt3-ai-content-generator'),
        'voiceInput' => __('Voice input', 'gpt3-ai-content-generator'),
        'voiceStarting' => __('Starting microphone...', 'gpt3-ai-content-generator'),
        'voiceRecording' => __('Listening...', 'gpt3-ai-content-generator'),
        /* translators: %s: maximum recording duration in seconds. */
        'voiceRecordingLimit' => __('Listening… Up to %s seconds.', 'gpt3-ai-content-generator'),
        'voiceClipTooLarge' => __('This recording is too large. Record a shorter clip.', 'gpt3-ai-content-generator'),
        'audioOutcomeUnknown' => __('The audio response was interrupted. Check its status before starting another request.', 'gpt3-ai-content-generator'),
        'audioMissing' => __('The original request cannot be checked. Starting again may use additional credits.', 'gpt3-ai-content-generator'),
        'audioAllowNew' => __('Allow a new request', 'gpt3-ai-content-generator'),
        'audioNewAllowed' => __('You can try again. This starts a new request.', 'gpt3-ai-content-generator'),
        'audioCheckStatus' => __('Check status', 'gpt3-ai-content-generator'),
        'audioSettled' => __('This request used credits, but its audio or transcript could not be recovered. Starting again uses credits for a new request.', 'gpt3-ai-content-generator'),
        'audioReleased' => __('This request did not use credits. You can try again.', 'gpt3-ai-content-generator'),
        'audioPending' => __('This request is still processing. Check again later before starting another request.', 'gpt3-ai-content-generator'),
        'voiceCancelRecording' => __('Cancel recording', 'gpt3-ai-content-generator'),
        'voiceFinishRecording' => __('Finish recording', 'gpt3-ai-content-generator'),
        'voiceTranscribing' => __('Transcribing audio...', 'gpt3-ai-content-generator'),
        'voiceAlreadyActive' => __('Voice recording is already active in another chatbot.', 'gpt3-ai-content-generator'),
        'voiceUnavailableScriptError' => __('Voice input is currently unavailable.', 'gpt3-ai-content-generator'),
        'voiceRequiresHttps' => __('Voice recording requires a secure HTTPS connection.', 'gpt3-ai-content-generator'),
        'voiceBrowserUnsupported' => __('Voice recording is not supported by this browser.', 'gpt3-ai-content-generator'),
        'voiceRecordingFailedPrefix' => __('Recording failed', 'gpt3-ai-content-generator'),
        'voiceTranscriptionFailedPrefix' => __('Transcription failed', 'gpt3-ai-content-generator'),
        'voiceProcessingError' => __('Error processing recorded audio.', 'gpt3-ai-content-generator'),
        'voiceConfigError' => __('Failed to process audio: configuration error.', 'gpt3-ai-content-generator'),
        'imageCommandEmptyPrompt' => __('Please provide a description after the image command (e.g., /image a cat playing with a ball).', 'gpt3-ai-content-generator'),
        'pauseActionLabel' => __('Pause audio', 'gpt3-ai-content-generator'),
        'webSearchToggle' => __('Toggle Web Search', 'gpt3-ai-content-generator'),
        'webSearchActive' => __('Web Search Active', 'gpt3-ai-content-generator'),
        'webSearchInactive' => __('Web Search Inactive', 'gpt3-ai-content-generator'),
        'googleSearchGroundingToggle' => __('Toggle Google Search Grounding', 'gpt3-ai-content-generator'),
        'googleSearchGroundingActive' => __('Google Search Grounding Active', 'gpt3-ai-content-generator'),
        'googleSearchGroundingInactive' => __('Google Search Grounding Inactive', 'gpt3-ai-content-generator'),
        // Popup hint related
        'dismissHint' => __('Dismiss', 'gpt3-ai-content-generator'),
    ];
}

/**
 * Main orchestrator function to build the frontend configuration array.
 * This replaces the body of the original Configurator::prepare_config().
 * UPDATED: Include custom theme settings.
 * ADDED: Logging and a defensive fix for bubble_border_radius in custom theme settings.
 * ADDED: fileUploadEnabledUI flag.
 * MODIFIED: Added vectorStoreProvider to the returned config.
 *
 * @param int $bot_id
 * @param \WP_Post $bot_post
 * @param array $settings Bot settings.
 * @param array $feature_flags Determined feature flags.
 * @return array Frontend configuration data.
 */
function build_config_array_logic(int $bot_id, \WP_Post $bot_post, array $settings, array $feature_flags): array
{
    // Ensure dependencies are loaded
    if (!class_exists(BotSettingsManager::class)) {
        $bsm_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/bots.php';
        if (file_exists($bsm_path)) {
            require_once $bsm_path;
        }
    }
    if (!class_exists(AIPKit_Providers::class)) {
        $providers_path = WPAICG_PLUGIN_DIR . 'classes/ai/settings.php';
        if (file_exists($providers_path)) {
            require_once $providers_path;
        }
    }
    if (!class_exists(aipkit_dashboard::class)) {
        $dashboard_path = WPAICG_PLUGIN_DIR . 'classes/admin/dashboard.php';
        if (file_exists($dashboard_path)) {
            require_once $dashboard_path;
        }
    }

    $starters_array = get_conversation_starters_logic($settings, $feature_flags['starters_ui_enabled']);
    $consent_texts = get_consent_settings_logic($settings);
    $tts_settings = get_tts_settings_logic($settings);

    $nonce = wp_create_nonce('aipkit_frontend_chat_nonce');

    $requires_consent = class_exists(ShortcodeFeatures::class) && ShortcodeFeatures::requires_consent($settings);

    $current_post_id = 0;
    if (is_singular()) {
        $current_post_id = get_the_ID();
    }

    $image_triggers = $settings['image_triggers'] ?? (class_exists(BotSettingsManager::class) ? BotSettingsManager::DEFAULT_IMAGE_TRIGGERS : '/image');
    if (empty($image_triggers)) {
        $image_triggers = (class_exists(BotSettingsManager::class) ? BotSettingsManager::DEFAULT_IMAGE_TRIGGERS : '/image');
    }

    $enable_openai_conv_state = ($settings['openai_conversation_state_enabled'] ?? (class_exists(BotSettingsManager::class) ? BotSettingsManager::DEFAULT_OPENAI_CONVERSATION_STATE_ENABLED : '0')) === '1';
    $enable_google_conv_state = ($settings['google_conversation_state_enabled'] ?? (class_exists(BotSettingsManager::class) ? BotSettingsManager::DEFAULT_GOOGLE_CONVERSATION_STATE_ENABLED : '0')) === '1';
    $allow_openai_web_search_tool = $feature_flags['allowWebSearchTool'] ?? false;

    $text_labels = get_text_labels_logic($settings, $consent_texts);

    // --- Add custom theme settings to frontend config ---
    $custom_theme_settings_for_js = [];
    $custom_theme_preset_key = isset($settings['theme_preset_key'])
        ? sanitize_key((string) $settings['theme_preset_key'])
        : '';
    if (($settings['theme'] ?? 'light') === 'custom') {
        $raw_custom_theme_settings = isset($settings['custom_theme_settings']) && is_array($settings['custom_theme_settings'])
            ? $settings['custom_theme_settings']
            : [];
        $preset_map = [];
        if (class_exists(BotSettingsManager::class)) {
            $custom_theme_presets = BotSettingsManager::get_custom_theme_presets();
            foreach ($custom_theme_presets as $preset) {
                if (!is_array($preset)) {
                    continue;
                }
                $preset_key = isset($preset['key']) ? sanitize_key((string) $preset['key']) : '';
                if ($preset_key === '') {
                    continue;
                }
                $preset_map[$preset_key] = [
                    'primary' => isset($preset['primary']) ? strtolower(trim((string) $preset['primary'])) : '',
                    'secondary' => isset($preset['secondary']) ? strtolower(trim((string) $preset['secondary'])) : '',
                ];
            }
        }

        // Backward compatibility: old bots may not have an explicit preset key saved.
        if (
            $custom_theme_preset_key === '' &&
            !empty($preset_map)
        ) {
            $saved_custom_primary = isset($raw_custom_theme_settings['primary_color'])
                ? strtolower(trim((string) $raw_custom_theme_settings['primary_color']))
                : '';
            $saved_custom_secondary = isset($raw_custom_theme_settings['secondary_color'])
                ? strtolower(trim((string) $raw_custom_theme_settings['secondary_color']))
                : '';
            if ($saved_custom_primary !== '' && $saved_custom_secondary !== '') {
                foreach ($preset_map as $preset_key => $preset_colors) {
                    if (
                        $preset_colors['primary'] !== '' &&
                        $preset_colors['secondary'] !== '' &&
                        $saved_custom_primary === $preset_colors['primary'] &&
                        $saved_custom_secondary === $preset_colors['secondary']
                    ) {
                        $custom_theme_preset_key = $preset_key;
                        break;
                    }
                }
            }
        }

        if ($custom_theme_preset_key !== '' && !isset($preset_map[$custom_theme_preset_key])) {
            $custom_theme_preset_key = '';
        }

        $custom_theme_settings_for_js = $settings['custom_theme_settings'] ?? [];
        $custom_theme_defaults = class_exists(BotSettingsManager::class)
            ? BotSettingsManager::get_custom_theme_defaults()
            : [];
        if (!empty($custom_theme_defaults)) {
            $custom_theme_settings_for_js = array_intersect_key(
                $custom_theme_settings_for_js,
                $custom_theme_defaults
            );
        }
        $custom_theme_settings_for_js = array_filter($custom_theme_settings_for_js, static function ($value) {
            return $value !== '' && $value !== null;
        });
    }
    // --- END ---

    $direct_voice_mode_flag = class_exists(ShortcodeFeatures::class) && ShortcodeFeatures::direct_voice_enabled($settings, $feature_flags);

    return [
        'botId' => $bot_id,
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => $nonce,
        'postId' => $current_post_id,
        'theme' => $settings['theme'] ?? 'light',
        'popupEnabled' => $feature_flags['popup_enabled'],
        'popupPosition' => $settings['popup_position'] ?? 'bottom-right',
        'popupDelay' => absint($settings['popup_delay'] ?? (class_exists(BotSettingsManager::class) ? BotSettingsManager::DEFAULT_POPUP_DELAY : 1)),
        'popupIconType' => $settings['popup_icon_type'] ?? (class_exists(BotSettingsManager::class) ? BotSettingsManager::DEFAULT_POPUP_ICON_TYPE : 'default'),
        'popupIconStyle' => $settings['popup_icon_style'] ?? (class_exists(BotSettingsManager::class) ? BotSettingsManager::DEFAULT_POPUP_ICON_STYLE : 'circle'),
        'popupIconValue' => $settings['popup_icon_value'] ?? (class_exists(BotSettingsManager::class) ? BotSettingsManager::DEFAULT_POPUP_ICON_VALUE : 'chat-bubble'),
        'popupIconSize' => (function() use ($settings) {
            $val = $settings['popup_icon_size'] ?? 'medium';
            return in_array($val, ['small','medium','large','xlarge'], true) ? $val : 'medium';
        })(),
        'customThemePresetKey' => $custom_theme_preset_key,
        'themeDimensionOverrides' => explode(',', $settings['theme_dimension_overrides'] ?? ''),
        'popupLabelEnabled' => ($settings['popup_label_enabled'] ?? '0') === '1',
        'popupLabelText' => (function() use ($settings) {
            $fallback = class_exists(BotSettingsManager::class)
                ? BotSettingsManager::DEFAULT_POPUP_LABEL_TEXT
                : 'Need help? Ask me!';
            $text = isset($settings['popup_label_text'])
                ? wp_strip_all_tags((string) $settings['popup_label_text'])
                : '';
            $text = trim($text);
            return $text !== '' ? $text : $fallback;
        })(),
        // Modes: 'always', 'on_delay', 'until_open', 'until_dismissed'
        'popupLabelMode' => in_array(($settings['popup_label_mode'] ?? 'on_delay'), ['always','on_delay','until_open','until_dismissed'], true) ? $settings['popup_label_mode'] : 'on_delay',
        'popupLabelDelaySeconds' => max(0, absint($settings['popup_label_delay_seconds'] ?? BotSettingsManager::DEFAULT_POPUP_LABEL_DELAY_SECONDS)),
        // 0 = never auto-hide
        'popupLabelAutoHideSeconds' => max(0, absint($settings['popup_label_auto_hide_seconds'] ?? 0)),
        'popupLabelDismissible' => ($settings['popup_label_dismissible'] ?? '1') === '1',
        // Frequency: 'always', 'once_per_session', 'once_per_visitor'
        'popupLabelFrequency' => in_array(($settings['popup_label_frequency'] ?? 'once_per_visitor'), ['always','once_per_session','once_per_visitor'], true) ? $settings['popup_label_frequency'] : 'once_per_visitor',
        'popupLabelShowOnMobile' => ($settings['popup_label_show_on_mobile'] ?? '1') === '1',
        'popupLabelShowOnDesktop' => ($settings['popup_label_show_on_desktop'] ?? '1') === '1',
        // Bump this (any string) to re-show hints for everyone
        'popupLabelVersion' => isset($settings['popup_label_version']) ? (string)$settings['popup_label_version'] : '',
        'popupLabelSize' => (function() use ($settings) {
            $fallback = class_exists(BotSettingsManager::class)
                ? BotSettingsManager::DEFAULT_POPUP_LABEL_SIZE
                : 'large';
            $value = $settings['popup_label_size'] ?? $fallback;
            return in_array($value, ['small','medium','large','xlarge'], true) ? $value : $fallback;
        })(),
        'footerText' => $settings['footer_text'] ?? '',
        'headerAvatarType' => $settings['header_avatar_type'] ?? (class_exists(BotSettingsManager::class) ? BotSettingsManager::DEFAULT_HEADER_AVATAR_TYPE : 'inherit'),
        'headerAvatarValue' => $settings['header_avatar_value'] ?? (class_exists(BotSettingsManager::class) ? BotSettingsManager::DEFAULT_HEADER_AVATAR_VALUE : 'chat-bubble'),
        'headerAvatarUrl' => (($settings['header_avatar_type'] ?? '') === 'custom')
            ? ($settings['header_avatar_value'] ?? ($settings['header_avatar_url'] ?? ''))
            : '',
        'headerOnlineText' => $settings['header_online_text'] ?? '',
        'enableFullscreen' => $feature_flags['enable_fullscreen'],
        'enableDownload' => $feature_flags['enable_download'],
        'enableCopyButton' => $feature_flags['enable_copy_button'],
        'enableFeedback' => $feature_flags['feedback_ui_enabled'],
        'enableSidebar' => $feature_flags['sidebar_ui_enabled'],
        'formGateEnabled' => (bool) apply_filters('aipkit_chat_form_gate_enabled', false, $bot_id),
        'pdfDownloadActive' => $feature_flags['pdf_ui_enabled'],
        'headerName' => $bot_post->post_title ?: '',
        'enableStarters' => $feature_flags['starters_ui_enabled'],
        'starters' => $starters_array,
        'requireConsentCompliance' => $requires_consent,
        'ttsEnabled' => $feature_flags['tts_ui_enabled'],
        'ttsAutoPlay' => $tts_settings['tts_auto_play'],
        'ttsProvider' => $tts_settings['tts_provider'],
        'ttsVoiceId' => $tts_settings['tts_voice_id'],
        'ttsGoogleModelId' => $tts_settings['tts_google_model_id'],
        'ttsOpenAIModelId' => $tts_settings['tts_openai_model_id'],
        'ttsElevenLabsModelId' => $tts_settings['tts_elevenlabs_model_id'],
        'enableVoiceInputUI' => $feature_flags['enable_voice_input_ui'] ?? false,
        'enableRealtimeVoiceUI' => $feature_flags['enable_realtime_voice_ui'] ?? false,
        'directVoiceMode' => $direct_voice_mode_flag,
        'voiceEngine' => !empty($feature_flags['enable_realtime_voice_ui']) && ($settings['voice_engine'] ?? 'realtime') === 'live' ? 'live' : 'realtime',
        'realtimeModel' => $settings['realtime_model'] ?? AIPKit_Model_Catalog::get_default_id('OpenAIRealtime'),
        'sttProvider' => $settings['stt_provider'] ?? (class_exists(BotSettingsManager::class) ? BotSettingsManager::DEFAULT_STT_PROVIDER : 'OpenAI'),
        'sttLimits' => (function () use ($settings) {
            $limits = ($settings['stt_provider'] ?? '') === 'AIPufferCloud'
                ? \WPAICG\Cloud\Connection::media_capabilities('transcribe', (string) ($settings['stt_cloud_model_id'] ?? '')) : [];
            $site_max = (int) apply_filters('aipkit_stt_max_audio_bytes', 4 * 1024 * 1024);
            $limits['maxInputBytes'] = min($site_max, (int) ($limits['maxInputBytes'] ?? $site_max));
            return $limits;
        })(),
        'imageTriggers' => $image_triggers,
        'fileUploadEnabledUI' => $feature_flags['file_upload_ui_enabled'] ?? false,
        'imageUploadEnabledUI' => $feature_flags['image_upload_ui_enabled'] ?? false,
        'inputActionButtonEnabled' => $feature_flags['input_action_button_enabled'] ?? false,
        'provider' => $settings['provider'] ?? 'OpenAI', // This is the Main AI provider for the bot
        'fileUploadProvider' => $feature_flags['file_upload_provider'] ?? '',
        // Knowledge remains independently available to the public chat configuration.
        'vectorStoreProvider' => $settings['vector_store_provider'] ?? (class_exists(BotSettingsManager::class) ? BotSettingsManager::DEFAULT_VECTOR_STORE_PROVIDER : 'openai'),
        'enableOpenAIConversationState' => $enable_openai_conv_state,
        'enableGoogleConversationState' => $enable_google_conv_state,
        'allowWebSearchTool' => $allow_openai_web_search_tool,
        'webToggleDefaultOn' => ($settings['web_toggle_default_on'] ?? (class_exists(BotSettingsManager::class) ? BotSettingsManager::DEFAULT_WEB_TOGGLE_DEFAULT_ON : '0')) === '1',
        'showSources' => ($settings['provider'] ?? 'OpenAI') === 'Google'
            || ($settings['show_sources'] ?? (class_exists(BotSettingsManager::class) ? BotSettingsManager::DEFAULT_SHOW_SOURCES : '1')) === '1',
        'allowGoogleSearchGrounding' => $feature_flags['allowGoogleSearchGrounding'] ?? false,
        'customThemeSettings' => $custom_theme_settings_for_js,
        'text' => $text_labels,
        'customTypingText' => (function() use ($settings, $text_labels) {
            $txt = isset($settings['custom_typing_text']) ? trim((string)$settings['custom_typing_text']) : '';
            // If empty, frontend shows dots; no auto-fallback
            return $txt;
        })(),
    ];
}

namespace WPAICG\Chat\Frontend\Shortcode\FeatureManagerMethods;

use WPAICG\AIPKit_Providers;
use WPAICG\Lib\Chat\ShortcodeFeatures;
use WPAICG\aipkit_dashboard;
use WPAICG\Chat\Core\AIPKit_Chat_File_Upload_Provider_Resolver;
use WPAICG\Chat\Storage\BotSettingsManager;
use function WPAICG\Core\Providers\OpenRouter\Methods\resolve_model_capabilities_logic;

/**
 * Retrieves core feature flag values directly from bot settings.
 * These are intermediate values used by other flag determination logic.
 *
 * @param array $settings Bot settings array.
 * @return array An array of core flag values.
 */
function get_core_flag_values_logic(array $settings): array {
    if (!class_exists(BotSettingsManager::class)) {
        // This is a critical dependency for defaults. If it's not loaded,
        // the behavior might be unexpected. Consider logging an error.
        // Provide hardcoded fallbacks if class is missing, though this indicates a deeper issue.
        $defaults = [
            'DEFAULT_ENABLE_FULLSCREEN' => '1',
            'DEFAULT_ENABLE_DOWNLOAD' => '0',
            'DEFAULT_ENABLE_CONVERSATION_STARTERS' => '1',
            'DEFAULT_ENABLE_CONVERSATION_SIDEBAR' => '0',
            'DEFAULT_TTS_ENABLED' => '0',
            'DEFAULT_ENABLE_VOICE_INPUT' => '0',
            'DEFAULT_ENABLE_FILE_UPLOAD' => '0',
            'DEFAULT_ENABLE_IMAGE_UPLOAD' => '0',
            'DEFAULT_OPENAI_WEB_SEARCH_ENABLED' => '0',
            'DEFAULT_CLAUDE_WEB_SEARCH_ENABLED' => '0',
            'DEFAULT_OPENROUTER_WEB_SEARCH_ENABLED' => '0',
            'DEFAULT_XAI_WEB_SEARCH_ENABLED' => '0',
            'DEFAULT_GOOGLE_SEARCH_GROUNDING_ENABLED' => '0',
        ];
    } else {
        $defaults = [
            'DEFAULT_ENABLE_FULLSCREEN' => BotSettingsManager::DEFAULT_ENABLE_FULLSCREEN,
            'DEFAULT_ENABLE_DOWNLOAD' => BotSettingsManager::DEFAULT_ENABLE_DOWNLOAD,
            'DEFAULT_ENABLE_CONVERSATION_STARTERS' => BotSettingsManager::DEFAULT_ENABLE_CONVERSATION_STARTERS,
            'DEFAULT_ENABLE_CONVERSATION_SIDEBAR' => BotSettingsManager::DEFAULT_ENABLE_CONVERSATION_SIDEBAR,
            'DEFAULT_TTS_ENABLED' => BotSettingsManager::DEFAULT_TTS_ENABLED,
            'DEFAULT_ENABLE_VOICE_INPUT' => BotSettingsManager::DEFAULT_ENABLE_VOICE_INPUT,
            'DEFAULT_ENABLE_FILE_UPLOAD' => BotSettingsManager::DEFAULT_ENABLE_FILE_UPLOAD,
            'DEFAULT_ENABLE_IMAGE_UPLOAD' => BotSettingsManager::DEFAULT_ENABLE_IMAGE_UPLOAD,
            'DEFAULT_OPENAI_WEB_SEARCH_ENABLED' => BotSettingsManager::DEFAULT_OPENAI_WEB_SEARCH_ENABLED,
            'DEFAULT_CLAUDE_WEB_SEARCH_ENABLED' => BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_ENABLED,
            'DEFAULT_OPENROUTER_WEB_SEARCH_ENABLED' => BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_ENABLED,
            'DEFAULT_XAI_WEB_SEARCH_ENABLED' => BotSettingsManager::DEFAULT_XAI_WEB_SEARCH_ENABLED,
            'DEFAULT_GOOGLE_SEARCH_GROUNDING_ENABLED' => BotSettingsManager::DEFAULT_GOOGLE_SEARCH_GROUNDING_ENABLED,
        ];
    }

    return [
        'provider' => isset($settings['provider']) ? sanitize_text_field((string) $settings['provider']) : 'OpenAI',
        'model' => isset($settings['model']) ? sanitize_text_field((string) $settings['model']) : '',
        'vector_store_provider' => isset($settings['vector_store_provider']) ? sanitize_key((string) $settings['vector_store_provider']) : 'openai',
        'file_upload_provider' => class_exists(AIPKit_Chat_File_Upload_Provider_Resolver::class)
            ? AIPKit_Chat_File_Upload_Provider_Resolver::resolve($settings)
            : '',
        // Directly derived flags (boolean)
        'popup_enabled'      => ($settings['popup_enabled'] ?? '0') === '1',
        'enable_fullscreen'  => ($settings['enable_fullscreen'] ?? $defaults['DEFAULT_ENABLE_FULLSCREEN']) === '1',
        'enable_download'    => ($settings['enable_download'] ?? $defaults['DEFAULT_ENABLE_DOWNLOAD']) === '1',
        'enable_copy_button' => true,
        'enable_feedback'    => true,
        'enable_voice_input_ui' => ($settings['enable_voice_input'] ?? $defaults['DEFAULT_ENABLE_VOICE_INPUT']) === '1', // Direct UI flag

        // Intermediate setting values (to be combined with addon status)
        'enable_starters_setting' => ($settings['enable_conversation_starters'] ?? $defaults['DEFAULT_ENABLE_CONVERSATION_STARTERS']) === '1',
        'enable_sidebar_setting'  => ($settings['enable_conversation_sidebar'] ?? $defaults['DEFAULT_ENABLE_CONVERSATION_SIDEBAR']) === '1',
        'enable_tts_setting'      => ($settings['tts_enabled'] ?? $defaults['DEFAULT_TTS_ENABLED']) === '1',
        'enable_file_upload_setting'  => ($settings['enable_file_upload'] ?? $defaults['DEFAULT_ENABLE_FILE_UPLOAD']) === '1',
        'enable_image_upload_setting' => ($settings['enable_image_upload'] ?? $defaults['DEFAULT_ENABLE_IMAGE_UPLOAD']) === '1',
        'enable_realtime_voice_setting' => ($settings['enable_realtime_voice'] ?? '0') === '1',
        'allow_openai_web_search_tool_setting'  => ($settings['openai_web_search_enabled'] ?? $defaults['DEFAULT_OPENAI_WEB_SEARCH_ENABLED']) === '1',
        'allow_claude_web_search_tool_setting'  => ($settings['claude_web_search_enabled'] ?? $defaults['DEFAULT_CLAUDE_WEB_SEARCH_ENABLED']) === '1',
        'allow_openrouter_web_search_tool_setting'  => ($settings['openrouter_web_search_enabled'] ?? $defaults['DEFAULT_OPENROUTER_WEB_SEARCH_ENABLED']) === '1',
        'allow_xai_web_search_tool_setting'  => ($settings['xai_web_search_enabled'] ?? $defaults['DEFAULT_XAI_WEB_SEARCH_ENABLED']) === '1',
        'allow_google_search_grounding_setting' => ($settings['google_search_grounding_enabled'] ?? $defaults['DEFAULT_GOOGLE_SEARCH_GROUNDING_ENABLED']) === '1',
    ];
}

/**
 * Determines UI-related feature flags based on intermediate core settings and plan status.
 *
 * @param array $core_flags An array of intermediate flags from get_core_flag_values_logic.
 *                          Expected keys: 'enable_starters_setting', 'enable_sidebar_setting',
 *                                         'popup_enabled', 'enable_download', 'enable_tts_setting'.
 * @param bool $is_pro_plan Whether the current user is on a Pro plan.
 * @return array An array of UI feature flags:
 *               'starters_ui_enabled', 'sidebar_ui_enabled', 'pdf_ui_enabled', 'tts_ui_enabled'.
 */
function get_ui_flags_logic(array $core_flags, bool $is_pro_plan): array {
    $ui_flags = [];

    $ui_flags['starters_ui_enabled'] = ($core_flags['enable_starters_setting'] ?? false);

    $ui_flags['sidebar_ui_enabled']  = ($core_flags['enable_sidebar_setting'] ?? false) &&
                                      !($core_flags['popup_enabled'] ?? false); // Sidebar disabled in popup mode

    $ui_flags['pdf_ui_enabled'] = class_exists(ShortcodeFeatures::class) && ShortcodeFeatures::pdf_enabled($core_flags, $is_pro_plan);

    $ui_flags['tts_ui_enabled']      = ($core_flags['enable_tts_setting'] ?? false);

    // Note: 'feedback_ui_enabled' and 'enable_voice_input_ui' are taken directly
    // from $core_flags in the main orchestrator.

    return $ui_flags;
}

/**
 * Determines file/image upload related feature flags.
 *
 * @param array $core_flags An array of intermediate flags from get_core_flag_values_logic.
 *                          Expected keys: 'provider', 'enable_file_upload_setting', 'enable_image_upload_setting'.
 * @return array An array of upload feature flags:
 *               'file_upload_ui_enabled', 'image_upload_ui_enabled', 'input_action_button_enabled'.
 */
function get_upload_flags_logic(array $core_flags): array {
    $upload_flags = [];
    $is_pro = false;
    // Ensure aipkit_dashboard class is loaded before calling its static methods
    if (!class_exists(aipkit_dashboard::class)) {
        $dashboard_path = WPAICG_PLUGIN_DIR . 'classes/admin/dashboard.php';
        if (file_exists($dashboard_path)) {
            require_once $dashboard_path;
        }
    }

    if (class_exists(aipkit_dashboard::class)) {
        $is_pro = aipkit_dashboard::is_pro_plan();
    }

    $provider = isset($core_flags['provider']) ? sanitize_text_field((string) $core_flags['provider']) : 'OpenAI';
    $model = isset($core_flags['model']) ? sanitize_text_field((string) $core_flags['model']) : '';
    $file_upload_provider = isset($core_flags['file_upload_provider'])
        ? sanitize_key((string) $core_flags['file_upload_provider'])
        : '';
    $default_image_upload_supported_providers = ['OpenAI', 'Google', 'Claude', 'OpenRouter', 'xAI', 'AIPufferCloud'];
    $image_upload_supported_providers = apply_filters(
        'aipkit_chat_image_upload_supported_providers',
        $default_image_upload_supported_providers,
        $core_flags,
        $is_pro
    );
    if (!is_array($image_upload_supported_providers)) {
        $image_upload_supported_providers = $default_image_upload_supported_providers;
    } else {
        $image_upload_supported_providers = array_values(array_filter(array_map(
            static fn($item) => is_string($item) ? sanitize_text_field($item) : '',
            $image_upload_supported_providers
        )));
        if (empty($image_upload_supported_providers)) {
            $image_upload_supported_providers = $default_image_upload_supported_providers;
        }
    }
    $is_image_upload_supported_provider = in_array($provider, $image_upload_supported_providers, true);
    if ($provider === 'AIPufferCloud') { $is_image_upload_supported_provider = AIPKit_Providers::model_supports_image_input($provider, $model); }
    if ($provider === 'OpenRouter' && $is_image_upload_supported_provider && $model !== '') {
        $resolver_fn = 'WPAICG\\Core\\Providers\\OpenRouter\\Methods\\resolve_model_capabilities_logic';
        if (!function_exists($resolver_fn)) {
            $capability_file = WPAICG_PLUGIN_DIR . 'classes/ai/providers/openrouter.php';
            if (file_exists($capability_file)) {
                require_once $capability_file;
            }
        }
        if (function_exists($resolver_fn)) {
            $capabilities = resolve_model_capabilities_logic($model);
            $is_image_upload_supported_provider = !empty($capabilities['image_input']);
        }
    }
    if ($provider === 'xAI' && $is_image_upload_supported_provider && $model !== '') {
        if (!class_exists(AIPKit_Providers::class)) {
            $providers_path = WPAICG_PLUGIN_DIR . 'classes/ai/settings.php';
            if (file_exists($providers_path)) {
                require_once $providers_path;
            }
        }
        if (class_exists(AIPKit_Providers::class)) {
            $is_image_upload_supported_provider = AIPKit_Providers::xai_model_supports_image_input($model);
        }
    }

    /**
     * Final gate for image-upload availability by provider/model on frontend.
     *
     * Allows paid integrations to enable/disable support without adding
     * provider-specific logic in core frontend classes.
     *
     * @param bool  $is_image_upload_supported_provider Current support decision.
     * @param array $context Gate context (provider, model, core_flags, is_pro_plan).
     */
    $is_image_upload_supported_provider = (bool) apply_filters(
        'aipkit_chat_image_upload_model_supported',
        $is_image_upload_supported_provider,
        [
            'provider' => $provider,
            'model' => $model,
            'core_flags' => $core_flags,
            'is_pro_plan' => $is_pro,
            'vector_store_provider' => $core_flags['vector_store_provider'] ?? 'openai',
        ]
    );

    $upload_flags['file_upload_provider'] = $file_upload_provider;
    // File upload UI requires a server-resolved route; the Knowledge provider is not used as an implicit client instruction.
    $upload_flags['file_upload_ui_enabled'] = class_exists(ShortcodeFeatures::class)
        && ShortcodeFeatures::file_upload_enabled($core_flags, $is_pro, $file_upload_provider);
    // Image upload UI is enabled only for providers with image-analysis support.
    $upload_flags['image_upload_ui_enabled'] = ($core_flags['enable_image_upload_setting'] ?? false) && $is_image_upload_supported_provider;

    $upload_flags['input_action_button_enabled'] = $upload_flags['file_upload_ui_enabled'] ||
                                                 $upload_flags['image_upload_ui_enabled'];

    return $upload_flags;
}

/**
 * Determines the 'allowWebSearchTool' feature flag.
 *
 * @param array $settings Bot settings array (needs 'provider').
 * @param bool $allow_openai_web_search_tool_setting Intermediate OpenAI flag value from core flags.
 * @param bool $allow_claude_web_search_tool_setting Intermediate Claude flag value from core flags.
 * @param bool $allow_openrouter_web_search_tool_setting Intermediate OpenRouter flag value from core flags.
 * @param bool $allow_xai_web_search_tool_setting Intermediate xAI flag value from core flags.
 * @return array An array containing the 'allowWebSearchTool' flag.
 */
function get_web_search_flag_logic(
    array $settings,
    bool $allow_openai_web_search_tool_setting,
    bool $allow_claude_web_search_tool_setting,
    bool $allow_openrouter_web_search_tool_setting,
    bool $allow_xai_web_search_tool_setting
): array {
    $provider = $settings['provider'] ?? 'OpenAI';
    $allow_web_search_tool = false;
    if ($provider === 'OpenAI') {
        $allow_web_search_tool = $allow_openai_web_search_tool_setting;
    } elseif ($provider === 'Claude') {
        $allow_web_search_tool = $allow_claude_web_search_tool_setting;
    } elseif ($provider === 'OpenRouter') {
        $allow_web_search_tool = $allow_openrouter_web_search_tool_setting;
        $model = isset($settings['model']) ? sanitize_text_field((string) $settings['model']) : '';
        if ($allow_web_search_tool && $model !== '') {
            $resolver_fn = 'WPAICG\\Core\\Providers\\OpenRouter\\Methods\\resolve_model_capabilities_logic';
            if (!function_exists($resolver_fn)) {
                $capability_file = WPAICG_PLUGIN_DIR . 'classes/ai/providers/openrouter.php';
                if (file_exists($capability_file)) {
                    require_once $capability_file;
                }
            }
            if (function_exists($resolver_fn)) {
                $capabilities = resolve_model_capabilities_logic($model);
                $allow_web_search_tool = !empty($capabilities['web_search_tool']);
            }
        }
    } elseif ($provider === 'xAI') {
        $allow_web_search_tool = $allow_xai_web_search_tool_setting;
    }

    return [
        'allowWebSearchTool' => $allow_web_search_tool,
    ];
}

/**
 * Determines Google Search Grounding related feature flags.
 *
 * @param array $settings Bot settings array.
 * @param bool $allow_google_search_grounding_setting Intermediate flag value from core flags.
 * @return array An array containing the Google Search Grounding capability flag.
 */
function get_google_grounding_flags_logic(array $settings, bool $allow_google_search_grounding_setting): array {
    return [
        'allowGoogleSearchGrounding' => ($settings['provider'] ?? 'OpenAI') === 'Google'
            && $allow_google_search_grounding_setting,
    ];
}

/**
 * Determines the 'enable_realtime_voice_ui' feature flag.
 *
 * @param array $core_flags An array of intermediate flags from get_core_flag_values_logic.
 * @return array An array containing the 'enable_realtime_voice_ui' flag.
 */
function get_realtime_voice_flag_logic(array $core_flags): array
{
    return [
        'enable_realtime_voice_ui' => class_exists(ShortcodeFeatures::class) && ShortcodeFeatures::realtime_enabled($core_flags),
    ];
}

/**
 * Computes derived feature flags based on already determined flags.
 *
 * @param array $current_flags An array of already computed flags.
 *                             Expected keys: 'popup_enabled', 'enable_fullscreen',
 *                                            'enable_download', 'sidebar_ui_enabled'.
 * @return array An array containing derived flags, e.g., 'show_header'.
 */
function compute_derived_flags_logic(array $current_flags): array {
    $derived_flags = [];

    $derived_flags['show_header'] = ($current_flags['popup_enabled'] ?? false) ||
                                  ($current_flags['enable_fullscreen'] ?? false) ||
                                  ($current_flags['enable_download'] ?? false) ||
                                  ($current_flags['sidebar_ui_enabled'] ?? false);
    return $derived_flags;
}

namespace WPAICG\Chat\Frontend\Shortcode\RendererMethods;

use WPAICG\Chat\Storage\BotSettingsManager;
use WPAICG\Lib\Chat\ShortcodeFeatures;
use WPAICG\Chat\Utils\AIPKit_SVG_Icons;

/**
 * Builds static custom-accent variables so light accents remain readable before
 * the frontend runtime initializes (including external embed contexts).
 */
function get_custom_accent_style(array $settings): string
{
    $primary = !empty($settings['primary_color'])
        ? sanitize_hex_color((string) $settings['primary_color'])
        : '';
    $legacy_accent = !empty($settings['accent_color'])
        ? sanitize_hex_color((string) $settings['accent_color'])
        : '';
    $use_legacy_accent = $legacy_accent
        && strtolower($legacy_accent) !== '#111111'
        && (!$primary || strtolower($primary) === '#0f766e');
    $accent = $use_legacy_accent ? $legacy_accent : ($primary ?: $legacy_accent);
    if (!$accent) {
        return '';
    }

    if (strlen($accent) === 4) {
        $accent = sprintf(
            '#%1$s%1$s%2$s%2$s%3$s%3$s',
            $accent[1],
            $accent[2],
            $accent[3]
        );
    }

    $red = hexdec(substr($accent, 1, 2));
    $green = hexdec(substr($accent, 3, 2));
    $blue = hexdec(substr($accent, 5, 2));
    $linearize = static function (int $channel): float {
        $value = $channel / 255;
        return $value <= 0.03928
            ? $value / 12.92
            : (($value + 0.055) / 1.055) ** 2.4;
    };
    $luminance = (0.2126 * $linearize($red))
        + (0.7152 * $linearize($green))
        + (0.0722 * $linearize($blue));
    $on_accent_rgb = $luminance > 0.55 ? [26, 29, 35] : [255, 255, 255];
    $on_accent = sprintf(
        '#%02X%02X%02X',
        $on_accent_rgb[0],
        $on_accent_rgb[1],
        $on_accent_rgb[2]
    );
    $mix = static function (array $from, array $to, float $to_weight): array {
        return [
            (int) round(($from[0] * (1 - $to_weight)) + ($to[0] * $to_weight)),
            (int) round(($from[1] * (1 - $to_weight)) + ($to[1] * $to_weight)),
            (int) round(($from[2] * (1 - $to_weight)) + ($to[2] * $to_weight)),
        ];
    };
    $accent_rgb = [$red, $green, $blue];
    $selection_rgb = $luminance > 0.55 ? [26, 29, 35] : $accent_rgb;
    $hover_rgb = $mix(
        $accent_rgb,
        $luminance > 0.55 ? [26, 29, 35] : [255, 255, 255],
        $luminance > 0.55 ? 0.08 : 0.12
    );
    $to_hex = static function (array $rgb): string {
        return sprintf('#%02X%02X%02X', $rgb[0], $rgb[1], $rgb[2]);
    };

    return implode('', [
        '--aipkit-chat-accent-color:' . strtoupper($accent) . ';',
        '--aipkit-chat-accent-rgb:' . implode(',', $accent_rgb) . ';',
        '--aipkit-chat-selection-rgb:' . implode(',', $selection_rgb) . ';',
        '--aipkit-chat-on-accent-color:' . $on_accent . ';',
        '--aipkit-chat-on-accent-rgb:' . implode(',', $on_accent_rgb) . ';',
        '--aipkit-chat-accent-hover-color:' . $to_hex($hover_rgb) . ';',
        '--aipkit-chat-accent-shadow-color:rgba(' . $red . ',' . $green . ',' . $blue . ',0.3);',
        '--aipkit-chat-header-avatar-bg-color:rgba(' . implode(',', $on_accent_rgb) . ',0.18);',
        '--aipkit-chat-header-status-text-color:rgba(' . implode(',', $on_accent_rgb) . ',0.85);',
    ]);
}

/**
 * Logic for rendering the main chatbot HTML structure.
 *
 * @param \WPAICG\Chat\Frontend\Shortcode\Renderer $rendererInstance The instance of the Renderer class.
 * @param int $bot_id
 * @param array $settings Bot Settings.
 * @param array $feature_flags Determined feature flags.
 * @param array $frontend_config Prepared frontend config data.
 * @return string Rendered HTML.
 */
function render_chatbot_html_logic(\WPAICG\Chat\Frontend\Shortcode\Renderer $rendererInstance, int $bot_id, array $settings, array $feature_flags, array $frontend_config): string {
    ob_start();

    $json_encoded_data = wp_json_encode($frontend_config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    $theme = $frontend_config['theme'];
    $voice_input_enabled_ui = $feature_flags['enable_voice_input_ui'] ?? false;
    $allow_openai_web_search_tool = $feature_flags['allowWebSearchTool'] ?? false;
    $allow_google_search_grounding = $feature_flags['allowGoogleSearchGrounding'] ?? false;

    if ($feature_flags['popup_enabled']) {
        // Call the render_popup_mode_html logic via the instance
        $rendererInstance->render_popup_mode_html_internal($bot_id, $theme, $json_encoded_data, $feature_flags, $frontend_config, $voice_input_enabled_ui, $allow_openai_web_search_tool, $allow_google_search_grounding);
    } else {
        // Call the render_inline_mode_html logic via the instance
        $rendererInstance->render_inline_mode_html_internal($bot_id, $theme, $json_encoded_data, $feature_flags, $frontend_config, $voice_input_enabled_ui, $allow_openai_web_search_tool, $allow_google_search_grounding);
    }

    return ob_get_clean();
}

/**
 * Logic for rendering the Popup mode HTML.
 * UPDATED: Add data-custom-theme attribute and aipkit-theme-custom class.
 *
 * @param \WPAICG\Chat\Frontend\Shortcode\Renderer $rendererInstance The instance of the Renderer class.
 * @param int $bot_id
 * @param string $theme
 * @param string $json_encoded_data
 * @param array $feature_flags
 * @param array $frontend_config
 * @param bool $voice_input_enabled_ui
 * @param bool $allow_openai_web_search_tool
 * @param bool $allow_google_search_grounding
 * @return void Echos HTML.
 */
function render_popup_mode_html_logic(
    \WPAICG\Chat\Frontend\Shortcode\Renderer $rendererInstance,
    int $bot_id,
    string $theme, // This is the selected theme ('light', 'dark', 'custom')
    string $json_encoded_data,
    array $feature_flags,
    array $frontend_config,
    bool $voice_input_enabled_ui,
    bool $allow_openai_web_search_tool,
    bool $allow_google_search_grounding
) {
    $popup_position = $frontend_config['popupPosition'];
    $popup_icon_type = $frontend_config['popupIconType'] ?? BotSettingsManager::DEFAULT_POPUP_ICON_TYPE;
    $popup_icon_type = in_array($popup_icon_type, ['default', 'custom'], true) ? $popup_icon_type : BotSettingsManager::DEFAULT_POPUP_ICON_TYPE;
    $popup_icon_style = $frontend_config['popupIconStyle'] ?? 'circle';
    $popup_icon_style = in_array($popup_icon_style, ['circle', 'square', 'none'], true) ? $popup_icon_style : BotSettingsManager::DEFAULT_POPUP_ICON_STYLE;
    $popup_icon_value = $frontend_config['popupIconValue'] ?? BotSettingsManager::DEFAULT_POPUP_ICON_VALUE;
    $popup_icon_size  = (isset($frontend_config['popupIconSize']) && in_array($frontend_config['popupIconSize'], ['small','medium','large','xlarge'], true))
        ? $frontend_config['popupIconSize']
        : BotSettingsManager::DEFAULT_POPUP_ICON_SIZE;
    $direct_voice_popup = class_exists(ShortcodeFeatures::class) ? ShortcodeFeatures::direct_voice_popup($frontend_config) : [];
    $is_direct_voice_mode = !empty($direct_voice_popup);
    $icon_html = '';
    if ($is_direct_voice_mode) {
        $icon_html = $direct_voice_popup['icon'];
    } elseif ($popup_icon_type === 'custom' && !empty($popup_icon_value)) {
        // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage -- Reason: The image source is correctly retrieved using a WordPress function (e.g., `wp_get_attachment_image_url`). The `<img>` tag is constructed manually to build a custom HTML structure with specific wrappers, classes, or attributes that are not achievable with the standard `wp_get_attachment_image()` function.
        $icon_html = '<img src="' . esc_url($popup_icon_value) . '" alt="' . esc_attr__('Open Chat', 'gpt3-ai-content-generator') . '" class="aipkit_popup_custom_icon" />';
    } else {
        switch ($popup_icon_value) {
            case 'spark':
                $icon_html = AIPKit_SVG_Icons::get_spark_svg();
                break;
            case 'openai':
                $icon_html = AIPKit_SVG_Icons::get_openai_svg();
                break;
            case 'plus': $icon_html = AIPKit_SVG_Icons::get_plus_svg();
                break;
            case 'question-mark': $icon_html = AIPKit_SVG_Icons::get_question_mark_svg();
                break;
            case 'chat-bubble': default: $icon_html = AIPKit_SVG_Icons::get_chat_bubble_svg();
                break;
        }
    }
    $voice_input_class = $voice_input_enabled_ui ? 'aipkit-voice-input-enabled' : '';
    $web_search_class = $allow_openai_web_search_tool ? 'aipkit-web-search-tool-allowed' : '';
    $google_grounding_class = $allow_google_search_grounding ? 'aipkit-google-search-grounding-allowed' : '';

    $custom_theme_class = '';
    $custom_theme_data_attr = '';
    $custom_accent_style = '';
    $custom_theme_preset_key = isset($frontend_config['customThemePresetKey'])
        ? sanitize_key((string) $frontend_config['customThemePresetKey'])
        : '';
    $is_custom_theme_preset = ($theme === 'custom' && $custom_theme_preset_key !== '');
    if ($theme === 'custom' && !empty($frontend_config['customThemeSettings'])) {
        if (!$is_custom_theme_preset) {
            $custom_theme_class = 'aipkit-theme-custom';
        }
        $custom_theme_data_attr = 'data-custom-theme=\'' . esc_attr(wp_json_encode($frontend_config['customThemeSettings'])) . '\'';

        $custom_accent_style = get_custom_accent_style($frontend_config['customThemeSettings']);
    } elseif ($theme === 'custom' && !$is_custom_theme_preset) {
        $custom_theme_class = 'aipkit-theme-custom';
    }

    $popup_theme_marker_class = 'aipkit-popup-theme-' . sanitize_html_class(!empty($theme) ? $theme : 'light');
    $popup_wrapper_classes = 'aipkit_popup_wrapper ' . $popup_theme_marker_class;
    if (!$is_direct_voice_mode) {
        $popup_wrapper_classes .= ' aipkit-popup-standard';
    }
    if (!empty($custom_theme_class)) {
        $popup_wrapper_classes .= ' ' . $custom_theme_class;
    }

    // Build wrapper style attribute
    $wrapper_style_attr = !empty($custom_accent_style) ? 'style="' . esc_attr($custom_accent_style) . '"' : '';
    $container_style_attr = $wrapper_style_attr;
    $trigger_open_label = $direct_voice_popup['open_label'] ?? __('Open Chat', 'gpt3-ai-content-generator');
    $trigger_close_label = $direct_voice_popup['close_label'] ?? __('Close Chat', 'gpt3-ai-content-generator');
    $rendered_icon_type = $direct_voice_popup['icon_type'] ?? $popup_icon_type;
    ?>
    <div class="<?php echo esc_attr($popup_wrapper_classes); ?>" id="aipkit_popup_wrapper_<?php echo esc_attr($bot_id); ?>" data-config='<?php echo esc_attr($json_encoded_data); ?>' data-bot-id="<?php echo esc_attr($bot_id); ?>" data-icon-size="<?php echo esc_attr($popup_icon_size); ?>" <?php echo $wrapper_style_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
        <button type="button" class="aipkit_popup_trigger aipkit_popup_position-<?php echo esc_attr($popup_position); ?> aipkit_popup_trigger--size-<?php echo esc_attr($popup_icon_size); ?>" id="aipkit_popup_trigger_<?php echo esc_attr($bot_id); ?>" aria-label="<?php echo esc_attr($trigger_open_label); ?>" title="<?php echo esc_attr($trigger_open_label); ?>" aria-haspopup="dialog" aria-controls="aipkit_chat_container_<?php echo esc_attr($bot_id); ?>" aria-expanded="false" <?php echo $direct_voice_popup['trigger_attributes'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed attributes from the paid renderer. ?> data-icon-style="<?php echo esc_attr($popup_icon_style); ?>" data-icon-type="<?php echo esc_attr($rendered_icon_type); ?>" data-label-open="<?php echo esc_attr($trigger_open_label); ?>" data-label-close="<?php echo esc_attr($trigger_close_label); ?>">
            <span class="aipkit_popup_icon aipkit_popup_icon--open">
                <?php echo $icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped?>
            </span>
        </button>
        <?php
        $hint_enabled = !empty($frontend_config['popupLabelEnabled']) && !empty($frontend_config['popupLabelText']);
        if ($hint_enabled) {
            $dismissible = !empty($frontend_config['popupLabelDismissible']);
            // Plain text only
            $hint_text = wp_strip_all_tags((string)$frontend_config['popupLabelText']);
            $hint_size = isset($frontend_config['popupLabelSize']) && in_array($frontend_config['popupLabelSize'], ['small','medium','large','xlarge'], true)
                ? $frontend_config['popupLabelSize']
                : BotSettingsManager::DEFAULT_POPUP_LABEL_SIZE;
            ?>
            <div
                class="aipkit_popup_hint aipkit_popup_position-<?php echo esc_attr($popup_position); ?> aipkit_popup_hint--size-<?php echo esc_attr($hint_size); ?>"
                id="aipkit_popup_hint_<?php echo esc_attr($bot_id); ?>"
                role="status"
                aria-live="polite"
                aria-atomic="true"
                aria-hidden="true"
                inert
                hidden
                data-bot-id="<?php echo esc_attr($bot_id); ?>"
            >
                <span class="aipkit_popup_hint_text"><?php echo esc_html($hint_text); ?></span>
                <?php if ($dismissible): ?>
                    <button type="button" class="aipkit_popup_hint_close" aria-label="<?php echo esc_attr($frontend_config['text']['dismissHint'] ?? 'Dismiss'); ?>" aria-controls="aipkit_popup_hint_<?php echo esc_attr($bot_id); ?>" title="<?php echo esc_attr($frontend_config['text']['dismissHint'] ?? 'Dismiss'); ?>">&times;</button>
                <?php endif; ?>
            </div>
        <?php } // end hint_enabled ?>
        <div class="aipkit_chat_container aipkit_popup_content aipkit-theme-<?php echo esc_attr($theme); ?> <?php echo esc_attr($custom_theme_class); ?> aipkit_popup_position-<?php echo esc_attr($popup_position); ?> aipkit-sidebar-state-closed <?php echo esc_attr($voice_input_class); ?> <?php echo esc_attr($web_search_class); ?> <?php echo esc_attr($google_grounding_class); ?>" id="aipkit_chat_container_<?php echo esc_attr($bot_id); ?>" aria-hidden="true" inert <?php echo $custom_theme_data_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped?> <?php echo $container_style_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> >
            <div class="aipkit_chat_body">
                <div class="aipkit_chat_main">
                    <?php if ($feature_flags['show_header']): ?>
                        <?php $rendererInstance->render_header_html_internal($feature_flags, $frontend_config, true); ?>
                    <?php endif; ?>
                    <div class="aipkit_chat_messages"></div>
                    <?php if ($feature_flags['starters_ui_enabled']): ?>
                        <div class="aipkit_conversation_starters"></div>
                    <?php endif; ?>
                    <?php $rendererInstance->render_input_area_html_internal($frontend_config, $feature_flags, $allow_openai_web_search_tool, $allow_google_search_grounding); ?>
                </div>
            </div>
            <?php $rendererInstance->render_footer_html_internal($frontend_config['footerText']); ?>
        </div>
    </div>
    <?php
}

/**
 * Logic for rendering the Inline mode HTML.
 * UPDATED: Add data-custom-theme attribute and aipkit-theme-custom class.
 *
 * @param \WPAICG\Chat\Frontend\Shortcode\Renderer $rendererInstance The instance of the Renderer class.
 * @param int $bot_id
 * @param string $theme
 * @param string $json_encoded_data
 * @param array $feature_flags
 * @param array $frontend_config
 * @param bool $voice_input_enabled_ui
 * @param bool $allow_openai_web_search_tool
 * @param bool $allow_google_search_grounding
 * @return void Echos HTML.
 */
function render_inline_mode_html_logic(
    \WPAICG\Chat\Frontend\Shortcode\Renderer $rendererInstance,
    int $bot_id,
    string $theme, // This is the selected theme ('light', 'dark', 'custom')
    string $json_encoded_data,
    array $feature_flags,
    array $frontend_config,
    bool $voice_input_enabled_ui,
    bool $allow_openai_web_search_tool,
    bool $allow_google_search_grounding
) {
    $voice_input_class = $voice_input_enabled_ui ? 'aipkit-voice-input-enabled' : '';
    $web_search_class = $allow_openai_web_search_tool ? 'aipkit-web-search-tool-allowed' : '';
    $google_grounding_class = $allow_google_search_grounding ? 'aipkit-google-search-grounding-allowed' : '';

    $custom_theme_class = '';
    $custom_theme_data_attr = '';
    $custom_accent_style = '';
    $custom_theme_preset_key = isset($frontend_config['customThemePresetKey'])
        ? sanitize_key((string) $frontend_config['customThemePresetKey'])
        : '';
    $is_custom_theme_preset = ($theme === 'custom' && $custom_theme_preset_key !== '');
    if ($theme === 'custom' && !empty($frontend_config['customThemeSettings'])) {
        if (!$is_custom_theme_preset) {
            $custom_theme_class = 'aipkit-theme-custom';
        }
        $custom_theme_data_attr = 'data-custom-theme=\'' . esc_attr(wp_json_encode($frontend_config['customThemeSettings'])) . '\'';
        $custom_accent_style = get_custom_accent_style($frontend_config['customThemeSettings']);
    } elseif ($theme === 'custom' && !$is_custom_theme_preset) { // Custom theme selected, but no settings (will fallback to light/base)
        $custom_theme_class = 'aipkit-theme-custom';
    }

    ?>
    <div class="aipkit_chat_container aipkit-theme-<?php echo esc_attr($theme); ?> <?php echo esc_attr($custom_theme_class); ?> aipkit-sidebar-state-closed <?php echo esc_attr($voice_input_class); ?> <?php echo esc_attr($web_search_class); ?> <?php echo esc_attr($google_grounding_class); ?>" id="aipkit_chat_container_<?php echo esc_attr($bot_id); ?>" data-bot-id="<?php echo esc_attr($bot_id); ?>" data-config='<?php echo esc_attr($json_encoded_data); ?>' <?php echo $custom_theme_data_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $custom_theme_data_attr is properly escaped ?> <?php echo !empty($custom_accent_style) ? 'style="' . esc_attr($custom_accent_style) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> >
        <div class="aipkit_chat_body">
            <?php if ($feature_flags['sidebar_ui_enabled']): ?>
                <?php $rendererInstance->render_sidebar_html_internal($frontend_config); ?>
            <?php endif; ?>
            <div class="aipkit_chat_main">
                <?php if ($feature_flags['show_header']): ?>
                    <?php $rendererInstance->render_header_html_internal($feature_flags, $frontend_config, false); ?>
                <?php endif; ?>
                <div class="aipkit_chat_messages"></div>
                <?php if ($feature_flags['starters_ui_enabled']): ?>
                    <div class="aipkit_conversation_starters"></div>
                <?php endif; ?>
                <?php $rendererInstance->render_input_area_html_internal($frontend_config, $feature_flags, $allow_openai_web_search_tool, $allow_google_search_grounding); ?>
            </div>
        </div>
        <?php $rendererInstance->render_footer_html_internal($frontend_config['footerText']); ?>
    </div>
    <?php
}

/**
 * Logic for rendering the chat header HTML.
 *
 * @param array $feature_flags
 * @param array $frontend_config
 * @param bool $is_popup
 * @return void Echos HTML.
 */
function render_header_html_logic(array $feature_flags, array $frontend_config, bool $is_popup) {
    // SVG definitions
    $sidebar_toggle_svg = '<svg  xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="currentColor"  stroke-width="2"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-menu-2"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 6l16 0" /><path d="M4 12l16 0" /><path d="M4 18l16 0" /></svg>';
    $fullscreen_svg = '<svg  xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="currentColor"  stroke-width="2"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-arrows-maximize"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M16 4l4 0l0 4" /><path d="M14 10l6 -6" /><path d="M8 20l-4 0l0 -4" /><path d="M4 20l6 -6" /><path d="M16 20l4 0l0 -4" /><path d="M14 14l6 6" /><path d="M8 4l-4 0l0 4" /><path d="M4 4l6 6" /></svg>';
    $download_svg = '<svg  xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="currentColor"  stroke-width="2"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-download"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" /><path d="M7 11l5 5l5 -5" /><path d="M12 4l0 12" /></svg>';
    $close_svg = '<svg  xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="currentColor"  stroke-width="2"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-x"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg>';
    $header_avatar_type = isset($frontend_config['headerAvatarType']) ? (string) $frontend_config['headerAvatarType'] : '';
    $header_avatar_value = isset($frontend_config['headerAvatarValue']) ? (string) $frontend_config['headerAvatarValue'] : '';
    $header_avatar_url = '';
    $header_avatar_svg = '';
    $inherited_icon_url = '';
    $inherited_icon_svg = '';
    $resolve_icon_svg = function (string $icon_key): string {
        switch ($icon_key) {
            case 'spark':
                return AIPKit_SVG_Icons::get_spark_svg();
            case 'openai':
                return AIPKit_SVG_Icons::get_openai_svg();
            case 'plus':
                return AIPKit_SVG_Icons::get_plus_svg();
            case 'question-mark':
                return AIPKit_SVG_Icons::get_question_mark_svg();
            case 'chat-bubble':
            default:
                return AIPKit_SVG_Icons::get_chat_bubble_svg();
        }
    };
    $allowed_header_icons = ['chat-bubble', 'spark', 'openai', 'plus', 'question-mark'];
    $show_identity = $is_popup;
    if ($show_identity) {
        $use_widget_icon = ($header_avatar_type === 'inherit');
        if ($header_avatar_type === 'custom') {
            if ($header_avatar_value !== '') {
                $header_avatar_url = $header_avatar_value;
            } else {
                $legacy_header_url = isset($frontend_config['headerAvatarUrl']) ? trim((string) $frontend_config['headerAvatarUrl']) : '';
                $header_avatar_url = $legacy_header_url;
            }
        } elseif ($header_avatar_type === 'default') {
            $icon_key = in_array($header_avatar_value, $allowed_header_icons, true) ? $header_avatar_value : 'chat-bubble';
            $header_avatar_svg = $resolve_icon_svg($icon_key);
        } else {
            $use_widget_icon = true;
        }

        if ($use_widget_icon || ($header_avatar_url === '' && $header_avatar_svg === '')) {
            $popup_icon_type = isset($frontend_config['popupIconType']) ? (string) $frontend_config['popupIconType'] : '';
            $popup_icon_value = isset($frontend_config['popupIconValue']) ? (string) $frontend_config['popupIconValue'] : '';
            if ($popup_icon_type === 'custom' && $popup_icon_value !== '') {
                $inherited_icon_url = $popup_icon_value;
            } else {
                $icon_key = in_array($popup_icon_value, $allowed_header_icons, true)
                    ? $popup_icon_value
                    : BotSettingsManager::DEFAULT_POPUP_ICON_VALUE;
                $inherited_icon_svg = $resolve_icon_svg($icon_key);
            }
        }
    }
    $header_name = isset($frontend_config['headerName']) ? trim((string) $frontend_config['headerName']) : '';
    if ($header_name === '') {
        $header_name = __('Chatbot', 'gpt3-ai-content-generator');
    }
    $header_online_text = isset($frontend_config['headerOnlineText']) ? trim((string) $frontend_config['headerOnlineText']) : '';
    if ($header_online_text === '') {
        $header_online_text = __('Online', 'gpt3-ai-content-generator');
    }
    $download_menu_id = function_exists('wp_unique_id')
        ? wp_unique_id('aipkit_download_menu_')
        : uniqid('aipkit_download_menu_', false);
    $fallback_avatar_svg = class_exists(AIPKit_SVG_Icons::class)
        ? AIPKit_SVG_Icons::get_chat_bubble_svg()
        : '';
    ?>
    <div class="aipkit_chat_header">
        <div class="aipkit_header_info">
            <?php if (!$is_popup && $feature_flags['sidebar_ui_enabled']): ?>
                <button type="button" class="aipkit_header_btn aipkit_sidebar_toggle_btn aipkit_sidebar_toggle_btn--main" title="<?php echo esc_attr($frontend_config['text']['sidebarToggle']); ?>" aria-label="<?php echo esc_attr($frontend_config['text']['sidebarToggle']); ?>" aria-expanded="false">
                    <?php echo $sidebar_toggle_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </button>
            <?php endif; ?>
            <?php if ($show_identity): ?>
                <div class="aipkit_header_identity">
                <div class="aipkit_header_avatar aipkit_header_icon">
                    <?php if (!empty($header_avatar_url)) : ?>
                        <img src="<?php echo esc_url($header_avatar_url); ?>" alt="<?php echo esc_attr($header_name); ?>" class="aipkit_header_avatar_img" />
                    <?php elseif (!empty($header_avatar_svg)) : ?>
                        <span class="aipkit_header_avatar_icon" aria-hidden="true">
                            <?php echo $header_avatar_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </span>
                    <?php elseif (!empty($inherited_icon_url)) : ?>
                        <img src="<?php echo esc_url($inherited_icon_url); ?>" alt="<?php echo esc_attr($header_name); ?>" class="aipkit_header_avatar_img" />
                    <?php elseif (!empty($inherited_icon_svg)) : ?>
                        <span class="aipkit_header_avatar_icon" aria-hidden="true">
                            <?php echo $inherited_icon_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </span>
                    <?php elseif (!empty($fallback_avatar_svg)) : ?>
                        <span class="aipkit_header_avatar_icon" aria-hidden="true">
                            <?php echo $fallback_avatar_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </span>
                    <?php endif; ?>
                </div>
                    <div class="aipkit_header_meta">
                        <div class="aipkit_header_name"><?php echo esc_html($header_name); ?></div>
                        <?php if (!empty($header_online_text)) : ?>
                            <div class="aipkit_header_status">
                                <span class="aipkit_header_status_dot" aria-hidden="true"></span>
                                <span class="aipkit_header_status_text"><?php echo esc_html($header_online_text); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($is_popup): ?>
            <div class="aipkit_header_drag_zone" aria-hidden="true"></div>
        <?php endif; ?>
        <div class="aipkit_header_actions">
            <?php if ($feature_flags['enable_fullscreen']): ?>
                <button type="button" class="aipkit_header_btn aipkit_fullscreen_btn" title="<?php echo esc_attr($frontend_config['text']['fullscreen']); ?>" aria-label="<?php echo esc_attr($frontend_config['text']['fullscreen']); ?>" aria-expanded="false">
                    <?php echo $fullscreen_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </button>
            <?php endif; ?>
            <?php if ($feature_flags['enable_download']): ?>
                <div class="aipkit_download_wrapper">
                    <button type="button" class="aipkit_header_btn aipkit_download_btn" title="<?php echo esc_attr($frontend_config['text']['download']); ?>" aria-label="<?php echo esc_attr($frontend_config['text']['download']); ?>" aria-haspopup="menu" aria-expanded="false" aria-controls="<?php echo esc_attr($download_menu_id); ?>">
                        <?php echo $download_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </button>
                    <div class="aipkit_download_menu" id="<?php echo esc_attr($download_menu_id); ?>" role="menu" aria-hidden="true">
                        <button type="button" class="aipkit_download_menu_item" role="menuitem" data-format="txt"><?php echo esc_html($frontend_config['text']['downloadTxt']); ?></button>
                        <?php if ($feature_flags['pdf_ui_enabled'] && class_exists(ShortcodeFeatures::class)) { ShortcodeFeatures::render_pdf_option($frontend_config); } ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($is_popup): ?>
                <button type="button" class="aipkit_header_btn aipkit_popup_close_btn" title="<?php echo esc_attr__('Close chat', 'gpt3-ai-content-generator'); ?>" aria-label="<?php echo esc_attr__('Close chat', 'gpt3-ai-content-generator'); ?>">
                    <?php echo $close_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </button>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * Logic for rendering the chat input area HTML.
 *
 * @param array $frontend_config
 * @param array $feature_flags Determined feature flags.
 * @param bool $allow_openai_web_search_tool Whether the OpenAI web search tool is allowed for this bot.
 * @param bool $allow_google_search_grounding Whether Google Search Grounding is allowed for this bot.
 * @return void Echos HTML.
 */
function render_input_area_html_logic(array $frontend_config, array $feature_flags = [], bool $allow_openai_web_search_tool = false, bool $allow_google_search_grounding = false) {
    $input_action_button_enabled = $feature_flags['input_action_button_enabled'] ?? false;
    $file_upload_ui_enabled = $feature_flags['file_upload_ui_enabled'] ?? false;
    $image_upload_ui_enabled = $feature_flags['image_upload_ui_enabled'] ?? false;
    $voice_input_enabled_ui = $feature_flags['enable_voice_input_ui'] ?? false;
    $realtime_voice_enabled_ui = $feature_flags['enable_realtime_voice_ui'] ?? false;
    $bot_id = $frontend_config['botId'] ?? 'default';

    // SVG definitions
    $attachment_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-paperclip"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M15 7l-6.5 6.5a1.5 1.5 0 0 0 3 3l6.5 -6.5a3 3 0 0 0 -6 -6l-6.5 6.5a4.5 4.5 0 0 0 9 9l6.5 -6.5" /></svg>';
    $image_upload_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-photo-up"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M15 8h.01" /><path d="M12.5 21h-6.5a3 3 0 0 1 -3 -3v-12a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v6.5" /><path d="M3 16l5 -5c.928 -.893 2.072 -.893 3 0l3.5 3.5" /><path d="M14 14l1 -1c.679 -.653 1.473 -.829 2.214 -.526" /><path d="M19 22v-6" /><path d="M22 19l-3 -3l-3 3" /></svg>';
    $send_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-arrow-up aipkit_send_icon"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 5l0 14" /><path d="M18 11l-6 -6" /><path d="M6 11l6 -6" /></svg>';
    $clear_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-eraser aipkit_clear_icon" hidden><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M19 20h-10.5l-4.21 -4.3a1 1 0 0 1 0 -1.41l10 -10a1 1 0 0 1 1.41 0l5 5a1 1 0 0 1 0 1.41l-9.2 9.3" /><path d="M18 13.3l-6.3 -6.3" /></svg>';
    $microphone_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-microphone"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 2m0 3a3 3 0 0 1 3 -3h0a3 3 0 0 1 3 3v5a3 3 0 0 1 -3 3h0a3 3 0 0 1 -3 -3z" /><path d="M5 10a7 7 0 0 0 14 0" /><path d="M8 21l8 0" /><path d="M12 17l0 4" /></svg>';
    $voice_cancel_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg>';
    $voice_confirm_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5l10 -10" /></svg>';
    $world_www_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-world-www"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M19.5 7a9 9 0 0 0 -7.5 -4a8.991 8.991 0 0 0 -7.484 4" /><path d="M11.5 3a16.989 16.989 0 0 0 -1.826 4" /><path d="M12.5 3a16.989 16.989 0 0 1 1.828 4" /><path d="M19.5 17a9 9 0 0 1 -7.5 4a8.991 8.991 0 0 1 -7.484 -4" /><path d="M11.5 21a16.989 16.989 0 0 1 -1.826 -4" /><path d="M12.5 21a16.989 16.989 0 0 0 1.828 -4" /><path d="M2 10l1 4l1.5 -4l1.5 4l1 -4" /><path d="M17 10l1 4l1.5 -4l1.5 4l1 -4" /><path d="M9.5 10l1 4l1.5 -4l1.5 4l1 -4" /></svg>';

    $initial_icon_html = $attachment_svg;
    $initial_aria_label = __('Attach files or use tools', 'gpt3-ai-content-generator');
    $initial_has_popup = 'true';

    if ($file_upload_ui_enabled && !$image_upload_ui_enabled) {
        $file_trigger = class_exists(ShortcodeFeatures::class) ? ShortcodeFeatures::file_upload_trigger() : [];
        $initial_icon_html = $file_trigger['icon'] ?? '';
        $initial_aria_label = $file_trigger['label'] ?? '';
        $initial_has_popup = 'false';
    } elseif (!$file_upload_ui_enabled && $image_upload_ui_enabled) {
        $initial_icon_html = $image_upload_svg;
        $initial_aria_label = __('Upload Image', 'gpt3-ai-content-generator');
        $initial_has_popup = 'false';
    }
    $input_action_menu_id = ($input_action_button_enabled && $file_upload_ui_enabled && $image_upload_ui_enabled)
        ? 'aipkit_input_action_menu_' . uniqid('', false)
        : '';

    ?>
    <div class="aipkit_chat_input">
        <div class="aipkit_chat_input_wrapper">
            <textarea
                id="aipkit_chat_input_field_<?php echo esc_attr($bot_id); ?>"
                name="aipkit_chat_message_<?php echo esc_attr($bot_id); ?>"
                class="aipkit_chat_input_field"
                placeholder="<?php echo esc_attr($frontend_config['text']['typeMessage']); ?>"
                aria-label="<?php esc_attr_e('Chat message input', 'gpt3-ai-content-generator'); ?>"
                rows="1"
            ></textarea>
            <div
                class="aipkit_voice_recording_panel"
                role="group"
                aria-label="<?php esc_attr_e('Voice recording', 'gpt3-ai-content-generator'); ?>"
                hidden
            >
                <div class="aipkit_voice_waveform_stage" aria-hidden="true">
                    <canvas class="aipkit_voice_waveform_canvas" width="320" height="40"></canvas>
                    <div class="aipkit_voice_transcribing_indicator" hidden>
                        <span class="aipkit_spinner"></span>
                        <span><?php esc_html_e('Transcribing audio...', 'gpt3-ai-content-generator'); ?></span>
                    </div>
                </div>
                <span class="aipkit_voice_recording_status" aria-live="polite"><?php esc_html_e('Listening...', 'gpt3-ai-content-generator'); ?></span>
                <button
                    type="button"
                    class="aipkit_voice_recording_control aipkit_voice_cancel_btn"
                    aria-label="<?php esc_attr_e('Cancel recording', 'gpt3-ai-content-generator'); ?>"
                    title="<?php esc_attr_e('Cancel recording', 'gpt3-ai-content-generator'); ?>"
                >
                    <?php echo $voice_cancel_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </button>
                <button
                    type="button"
                    class="aipkit_voice_recording_control aipkit_voice_confirm_btn"
                    aria-label="<?php esc_attr_e('Finish recording', 'gpt3-ai-content-generator'); ?>"
                    title="<?php esc_attr_e('Finish recording', 'gpt3-ai-content-generator'); ?>"
                >
                    <?php echo $voice_confirm_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </button>
            </div>
             <div class="aipkit_chat_input_actions_bar">
                <div class="aipkit_chat_input_actions_left">
                    <button
                        type="button"
                        class="aipkit_input_action_btn aipkit_input_action_toggle"
                        aria-label="<?php echo esc_attr($initial_aria_label); ?>"
                        role="button"
                        <?php if ($initial_has_popup === 'true'): ?>
                            aria-haspopup="true"
                            aria-controls="<?php echo esc_attr($input_action_menu_id); ?>"
                            aria-expanded="false"
                        <?php endif; ?>
                        <?php if (!$input_action_button_enabled): ?>hidden<?php endif; ?>
                    >
                        <?php echo $initial_icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </button>
                     <?php if ($allow_openai_web_search_tool): ?>
                     <button
                        type="button"
                        class="aipkit_input_action_btn aipkit_web_search_toggle"
                        aria-label="<?php echo esc_attr($frontend_config['text']['webSearchToggle'] ?? __('Toggle Web Search', 'gpt3-ai-content-generator')); ?>"
                        title="<?php echo esc_attr($frontend_config['text']['webSearchInactive'] ?? __('Web Search Inactive', 'gpt3-ai-content-generator')); ?>"
                        role="button"
                        aria-pressed="false"
                    >
                        <?php echo $world_www_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </button>
                    <?php endif; ?>
                    <?php if ($allow_google_search_grounding): ?>
                     <button
                        type="button"
                        class="aipkit_input_action_btn aipkit_google_search_grounding_toggle"
                        aria-label="<?php echo esc_attr($frontend_config['text']['googleSearchGroundingToggle'] ?? __('Toggle Google Search Grounding', 'gpt3-ai-content-generator')); ?>"
                        title="<?php echo esc_attr($frontend_config['text']['googleSearchGroundingInactive'] ?? __('Google Search Grounding Inactive', 'gpt3-ai-content-generator')); ?>"
                        role="button"
                        aria-pressed="false"
                    >
                        <?php echo $world_www_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </button>
                    <?php endif; ?>
                </div>
                <div class="aipkit_chat_input_actions_right">
                    <?php if (class_exists(ShortcodeFeatures::class)) { ShortcodeFeatures::render_realtime_button($realtime_voice_enabled_ui); } ?>
                    <button
                        class="aipkit_input_action_btn aipkit_voice_input_btn"
                        aria-label="<?php esc_attr_e('Voice input', 'gpt3-ai-content-generator'); ?>"
                        title="<?php esc_attr_e('Voice input', 'gpt3-ai-content-generator'); ?>"
                        aria-pressed="false"
                        type="button"
                        <?php if (!$voice_input_enabled_ui): ?>hidden<?php endif; ?>
                    >
                        <?php echo $microphone_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </button>
                    <span class="aipkit_chat_action_timer" aria-hidden="true" hidden></span>
                    <button
                        class="aipkit_input_action_btn aipkit_chat_action_btn aipkit_send_btn"
                        aria-label="<?php echo esc_attr($frontend_config['text']['sendMessage']); ?>"
                        title="<?php echo esc_attr($frontend_config['text']['sendMessage']); ?>"
                        type="button"
                    >
                        <?php echo $send_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php echo $clear_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <span class="aipkit_spinner" hidden></span>
                    </button>
                </div>
            </div>
        </div>
        <?php if ($input_action_button_enabled && ($file_upload_ui_enabled && $image_upload_ui_enabled) ): ?>
            <?php if (class_exists(ShortcodeFeatures::class)) { ShortcodeFeatures::render_upload_menu($input_action_menu_id, $image_upload_svg); } ?>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Logic for rendering the chat footer HTML.
 *
 * @param string $footer_text
 * @return void Echos HTML.
 */
function render_footer_html_logic(string $footer_text) {
    if (!empty($footer_text)) {
        ?>
        <div class="aipkit_chat_footer"><?php echo wp_kses_post($footer_text); ?></div>
        <?php
    }
}

/**
 * Logic for rendering the conversation sidebar HTML.
 *
 * @param array $frontend_config
 * @return void Echos HTML.
 */
function render_sidebar_html_logic(array $frontend_config)
{
    $sidebar_toggle_svg = '<svg  xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="currentColor"  stroke-width="2"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-menu-2"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 6l16 0" /><path d="M4 12l16 0" /><path d="M4 18l16 0" /></svg>';
    $new_chat_svg = '<svg  xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="currentColor"  stroke-width="2"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-plus"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 5l0 14" /><path d="M5 12l14 0" /></svg>';
    $search_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-search"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0"/><path d="M21 21l-6 -6"/></svg>';
    ?>
    <div class="aipkit_chat_sidebar" aria-hidden="true">
         <div class="aipkit_sidebar_header">
            <button type="button" class="aipkit_header_btn aipkit_sidebar_toggle_btn aipkit_sidebar_toggle_btn--sidebar" title="<?php echo esc_attr($frontend_config['text']['sidebarToggle']); ?>" aria-label="<?php echo esc_attr($frontend_config['text']['sidebarToggle']); ?>" aria-expanded="false">
                <?php echo $sidebar_toggle_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </button>
            <h4 class="aipkit_sidebar_title"><?php echo esc_html($frontend_config['text']['conversations']); ?></h4>
            <button type="button" class="aipkit_btn aipkit_btn-secondary aipkit_btn-small aipkit_sidebar_new_chat_btn" aria-label="<?php echo esc_attr($frontend_config['text']['newChat']); ?>">
                 <?php echo $new_chat_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html($frontend_config['text']['newChat']); ?>
            </button>
         </div>
         <div class="aipkit_sidebar_search" hidden>
            <label class="aipkit_sidebar_search_control">
                <span class="aipkit_sidebar_search_icon" aria-hidden="true">
                    <?php echo $search_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </span>
                <input type="search" class="aipkit_sidebar_search_input" placeholder="<?php echo esc_attr($frontend_config['text']['searchConversations']); ?>" aria-label="<?php echo esc_attr($frontend_config['text']['searchConversations']); ?>" autocomplete="off" spellcheck="false" />
            </label>
         </div>
         <div class="aipkit_sidebar_content" aria-live="polite">
         </div>
    </div>
    <?php
}

/**
 * Logic for creating the HTML for message action buttons.
 *
 * @param array $config
 * @return string HTML for the actions container.
 */
function createActionsContainerHTML_logic(array $config): string {
    // SVG definitions
    $play_svg = '<svg  xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="currentColor"  stroke-width="2"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-player-play"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 4v16l13 -8z" /></svg>';
    $copy_svg = '<svg  xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="currentColor"  stroke-width="2"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-copy"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 7m0 2.667a2.667 2.667 0 0 1 2.667 -2.667h8.666a2.667 2.667 0 0 1 2.667 2.667v8.666a2.667 2.667 0 0 1 -2.667 2.667h-8.666a2.667 2.667 0 0 1 -2.667 -2.667z" /><path d="M4.012 16.737a2.005 2.005 0 0 1 -1.012 -1.737v-10c0 -1.1 .9 -2 2 -2h10c.75 0 1.158 .385 1.5 1" /></svg>';
    $thumb_up_svg = '<svg  xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="currentColor"  stroke-width="2"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-thumb-up"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 11v8a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1v-7a1 1 0 0 1 1 -1h3a4 4 0 0 0 4 -4v-1a2 2 0 0 1 4 0v5h3a2 2 0 0 1 2 2l-1 5a2 3 0 0 1 -2 2h-7a3 3 0 0 1 -3 -3" /></svg>';
    $thumb_down_svg = '<svg  xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="currentColor"  stroke-width="2"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-thumb-down"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 13v-8a1 1 0 0 0 -1 -1h-2a1 1 0 0 0 -1 1v7a1 1 0 0 0 1 1h3a4 4 0 0 1 4 4v1a2 2 0 0 0 4 0v-5h3a2 2 0 0 0 2 -2l-1 -5a2 3 0 0 0 -2 -2h-7a3 3 0 0 0 -3 3" /></svg>';

    $utility_actions_html = '';
    $feedback_actions_html = '';
    $texts = $config['text'] ?? [];
    $saved_feedback = isset($config['feedback']) && in_array($config['feedback'], ['up', 'down'], true)
        ? $config['feedback']
        : '';
    if ($config['ttsEnabled'] ?? false) {
        $playTitle = $texts['playActionLabel'] ?? 'Play audio';
        $pauseTitle = $texts['pauseActionLabel'] ?? 'Pause audio';
        $utility_actions_html .= sprintf(
             '<button type="button" class="aipkit_action_btn aipkit_play_btn" title="%1$s" aria-label="%1$s" aria-pressed="false" data-play-label="%1$s" data-pause-label="%2$s">' .
             '%3$s' .
             '</button>',
             esc_attr($playTitle),
             esc_attr($pauseTitle),
             $play_svg
         );
    }
    if ($config['enableCopyButton'] ?? false) {
        $copyTitle = $texts['copyActionLabel'] ?? 'Copy response';
        $copySuccessTitle = $texts['copySuccess'] ?? 'Copied!';
        $utility_actions_html .= sprintf(
            '<button type="button" class="aipkit_action_btn aipkit_copy_btn" title="%1$s" aria-label="%1$s" data-success-label="%2$s">%3$s</button>',
            esc_attr($copyTitle),
            esc_attr($copySuccessTitle),
            $copy_svg
        );
    }
    if ($config['enableFeedback'] ?? false) {
        $likeTitle = $texts['feedbackLikeLabel'] ?? 'Like response';
        $dislikeTitle = $texts['feedbackDislikeLabel'] ?? 'Dislike response';
        $up_selected = $saved_feedback === 'up';
        $down_selected = $saved_feedback === 'down';
        $feedback_actions_html .= sprintf(
            '<button type="button" class="aipkit_action_btn aipkit_feedback_btn aipkit_thumb_up_btn%1$s" title="%2$s" aria-label="%2$s" aria-pressed="%3$s" data-feedback="up">%4$s</button>',
            $up_selected ? ' aipkit_feedback_selected' : '',
            esc_attr($likeTitle),
            $up_selected ? 'true' : 'false',
            $thumb_up_svg
        );
         $feedback_actions_html .= sprintf(
            '<button type="button" class="aipkit_action_btn aipkit_feedback_btn aipkit_thumb_down_btn%1$s" title="%2$s" aria-label="%2$s" aria-pressed="%3$s" data-feedback="down">%4$s</button>',
            $down_selected ? ' aipkit_feedback_selected' : '',
            esc_attr($dislikeTitle),
            $down_selected ? 'true' : 'false',
            $thumb_down_svg
        );
    }

    if ($utility_actions_html || $feedback_actions_html) {
        $utility_group = $utility_actions_html
            ? '<span class="aipkit_message_action_group aipkit_message_action_group--utility">' . $utility_actions_html . '</span>'
            : '';
        $feedback_group = $feedback_actions_html
            ? '<span class="aipkit_message_action_group aipkit_message_action_group--feedback">' . $feedback_actions_html . '</span>'
            : '';
        return '<div class="aipkit_message_actions" data-current-feedback="' . esc_attr($saved_feedback) . '">' . $utility_group . $feedback_group . '</div>';
    }
    return '';
}

namespace WPAICG\Chat\Frontend\Shortcode;

use WP_Post;
use WPAICG\Chat\Storage\BotStorage;
use WP_Error;
use WPAICG\aipkit_dashboard;
use WPAICG\Chat\Utils\AIPKit_SVG_Icons;
use WPAICG\Chat\Frontend\Assets\AssetsRequireFlags;
use WPAICG\Chat\Storage\SiteWideBotManager;
use WPAICG\Chat\Frontend\Shortcode;
use WPAICG\Chat\Admin\AdminSetup;





/**
 * Prepares the frontend JavaScript configuration object for the Chatbot Shortcode.
 * This class now acts as a dispatcher to the modularized logic.
 */
class Configurator {

    /**
     * Prepares the configuration array needed for the frontend JavaScript.
     * Delegates the main work to the build_config_array_logic function.
     *
     * @param int $bot_id
     * @param WP_Post $bot_post
     * @param array $settings Bot settings.
     * @param array $feature_flags Determined flags from FeatureManager.
     * @return array Frontend configuration data.
     */
    public static function prepare_config(int $bot_id, WP_Post $bot_post, array $settings, array $feature_flags): array {
        // Call the main orchestrator function from the new structure
        return ConfiguratorMethods\build_config_array_logic($bot_id, $bot_post, $settings, $feature_flags);
    }
}







/**
 * Handles data fetching logic for the Chatbot Shortcode.
 * Uses the BotStorage facade.
 */
class DataProvider {

    /**
     * Fetches the WP_Post object and settings for a given bot ID.
     *
     * @param int $bot_id The chatbot post ID.
     * @return array|WP_Error Array containing 'post' and 'settings' on success, WP_Error on failure.
     */
    public static function get_bot_data(int $bot_id) {
        $bot_post = get_post($bot_id);
        if (!$bot_post) {
            return new WP_Error('fetch_error', sprintf('[AIPKit Chatbot Error: Could not fetch post data for ID: %d]', $bot_id));
        }

        // Ensure BotStorage facade is available
        if (!class_exists(\WPAICG\Chat\Storage\BotStorage::class)) {
            return new \WP_Error('internal_error', 'Cannot load bot data.');
        }
        // Instantiate BotStorage facade locally
        $bot_storage = new BotStorage();
        $bot_settings = $bot_storage->get_chatbot_settings($bot_id);

        return ['post' => $bot_post, 'settings' => $bot_settings];
    }
}




/**
 * Determines feature flags for the Chatbot Shortcode based on settings and global configs.
 * Now orchestrates calls to modularized logic functions.
 */
class FeatureManager {

    /**
     * Determines feature flags based on bot settings and global configurations (addons, pro plan).
     *
     * @param array $settings Bot settings array.
     * @return array Associative array of boolean feature flags.
     */
    public static function determine_flags(array $settings): array {
        $flags = [];

        // 1. Get core flag values directly from settings
        $core_flags = FeatureManagerMethods\get_core_flag_values_logic($settings);
        $flags = array_merge($flags, [
            'popup_enabled'      => $core_flags['popup_enabled'],
            'enable_fullscreen'  => $core_flags['enable_fullscreen'],
            'enable_download'    => $core_flags['enable_download'],
            'enable_copy_button' => $core_flags['enable_copy_button'],
            'enable_feedback'    => $core_flags['enable_feedback'], // This becomes feedback_ui_enabled
            'enable_voice_input_ui' => $core_flags['enable_voice_input_ui'], // Direct UI flag
        ]);
        // Rename for consistency if needed, or use directly
        $flags['feedback_ui_enabled'] = $flags['enable_feedback'];

        // 2. Determine plan status
        $is_pro_plan = class_exists(aipkit_dashboard::class) ? aipkit_dashboard::is_pro_plan() : false;

        // 3. Determine UI flags based on core flags and addon statuses
        $ui_flags = FeatureManagerMethods\get_ui_flags_logic($core_flags, $is_pro_plan);
        $flags = array_merge($flags, $ui_flags);

        // 4. Determine upload related flags
        $upload_flags = FeatureManagerMethods\get_upload_flags_logic($core_flags);
        $flags = array_merge($flags, $upload_flags);

        // 5. Determine Web Search flag
        $web_search_flag = FeatureManagerMethods\get_web_search_flag_logic(
            $settings,
            $core_flags['allow_openai_web_search_tool_setting'],
            $core_flags['allow_claude_web_search_tool_setting'],
            $core_flags['allow_openrouter_web_search_tool_setting'],
            $core_flags['allow_xai_web_search_tool_setting']
        );
        $flags = array_merge($flags, $web_search_flag);

        // 6. Determine Google Search Grounding flags
        $google_grounding_flags = FeatureManagerMethods\get_google_grounding_flags_logic($settings, $core_flags['allow_google_search_grounding_setting']);
        $flags = array_merge($flags, $google_grounding_flags);

        // 7. Determine Realtime Voice flag
        $realtime_voice_flag = FeatureManagerMethods\get_realtime_voice_flag_logic($core_flags);
        $flags = array_merge($flags, $realtime_voice_flag);

        // 8. Compute derived flags
        $derived_flags = FeatureManagerMethods\compute_derived_flags_logic($flags);
        $flags = array_merge($flags, $derived_flags);

        return $flags;
    }
}




/**
 * Handles the HTML rendering for the Chatbot Shortcode.
 * Delegates logic to namespaced functions.
 */
class Renderer {

    public function __construct() {
        // Load SVG Icons utility if not already loaded
        if (!class_exists(AIPKit_SVG_Icons::class)) {
            $svg_util_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/icons.php';
            if (file_exists($svg_util_path)) {
                require_once $svg_util_path;
            }
        }
    }

    /**
     * Generates the final HTML output for the chatbot.
     *
     * @param int $bot_id
     * @param array $settings Bot Settings.
     * @param array $feature_flags Determined feature flags.
     * @param array $frontend_config Prepared frontend config data.
     * @return string Rendered HTML.
     */
    public function render_chatbot_html(int $bot_id, array $settings, array $feature_flags, array $frontend_config): string {
        // Ensure AssetsRequireFlags class is loaded before calling its static method
        if (!class_exists(AssetsRequireFlags::class)) {
            $flags_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/assets.php';
            if (file_exists($flags_path)) {
                require_once $flags_path;
            }
        }
        if (class_exists(AssetsRequireFlags::class)) {
            AssetsRequireFlags::set_flags(
                $feature_flags['pdf_ui_enabled'],
                $feature_flags['enable_copy_button'],
                $feature_flags['starters_ui_enabled'],
                $feature_flags['sidebar_ui_enabled'],
                $feature_flags['feedback_ui_enabled'],
                $feature_flags['tts_ui_enabled'],
                $feature_flags['enable_voice_input_ui'],
                true, // Assume image generation command is always potentially available
                $feature_flags['image_upload_ui_enabled'],
                $feature_flags['file_upload_ui_enabled'],
                $feature_flags['enable_realtime_voice_ui']
            );
        }

        return RendererMethods\render_chatbot_html_logic($this, $bot_id, $settings, $feature_flags, $frontend_config);
    }

    /**
     * Renders the HTML structure for the Popup mode.
     * Internal method to be called by namespaced logic.
     */
    public function render_popup_mode_html_internal(int $bot_id, string $theme, string $json_encoded_data, array $feature_flags, array $frontend_config, bool $voice_input_enabled_ui, bool $allow_openai_web_search_tool, bool $allow_google_search_grounding) {
        RendererMethods\render_popup_mode_html_logic($this, $bot_id, $theme, $json_encoded_data, $feature_flags, $frontend_config, $voice_input_enabled_ui, $allow_openai_web_search_tool, $allow_google_search_grounding);
    }

    /**
     * Renders the HTML structure for the Inline mode.
     * Internal method to be called by namespaced logic.
     */
    public function render_inline_mode_html_internal(int $bot_id, string $theme, string $json_encoded_data, array $feature_flags, array $frontend_config, bool $voice_input_enabled_ui, bool $allow_openai_web_search_tool, bool $allow_google_search_grounding) {
        RendererMethods\render_inline_mode_html_logic($this, $bot_id, $theme, $json_encoded_data, $feature_flags, $frontend_config, $voice_input_enabled_ui, $allow_openai_web_search_tool, $allow_google_search_grounding);
    }

    /**
     * Renders the chat header HTML.
     * Internal method to be called by namespaced logic.
     */
    public function render_header_html_internal(array $feature_flags, array $frontend_config, bool $is_popup) {
        RendererMethods\render_header_html_logic($feature_flags, $frontend_config, $is_popup);
    }

    /**
     * Renders the chat input area HTML.
     * Internal method to be called by namespaced logic.
     */
    public function render_input_area_html_internal(array $frontend_config, array $feature_flags = [], bool $allow_openai_web_search_tool = false, bool $allow_google_search_grounding = false) {
        RendererMethods\render_input_area_html_logic($frontend_config, $feature_flags, $allow_openai_web_search_tool, $allow_google_search_grounding);
    }

    /**
     * Renders the optional chat footer HTML.
     * Internal method to be called by namespaced logic.
     */
    public function render_footer_html_internal(string $footer_text) {
        RendererMethods\render_footer_html_logic($footer_text);
    }

    /**
     * Renders the conversation sidebar HTML.
     * Internal method to be called by namespaced logic.
     */
    public function render_sidebar_html_internal(array $frontend_config) {
        RendererMethods\render_sidebar_html_logic($frontend_config);
    }

    /**
     * Creates the HTML for message action buttons.
     * This method can remain public if directly used by other classes, or be made internal.
     */
    public function createActionsContainerHTML(array $config): string {
        return RendererMethods\createActionsContainerHTML_logic($config);
    }
}

/**
 * Handles site-wide injection logic for the Chatbot Shortcode.
 */
class SiteWideHandler {

    private $site_wide_manager;
    private $shortcode_instance; // Reference to the main Shortcode class instance
    private static $site_wide_bot_id_cache = null; // Cache for site-wide bot ID

    public function __construct(Shortcode $shortcode_instance) {
        $this->site_wide_manager = new SiteWideBotManager();
        $this->shortcode_instance = $shortcode_instance; // Store reference
    }

    /**
     * Inject the site-wide chatbot if configured.
     * Hooked into `wp_footer`.
     */
    public function inject_site_wide_chatbot() {
        if (is_admin() || wp_doing_ajax()) return;

        // Use the manager to get the site-wide bot ID, utilize its caching
        if (self::$site_wide_bot_id_cache === null) { // Check static cache first
            self::$site_wide_bot_id_cache = $this->site_wide_manager->get_site_wide_bot_id();
        }
        $bot_id_to_inject = self::$site_wide_bot_id_cache;

        // Check if the shortcode instance exists and the bot ID is valid
        if ($this->shortcode_instance && $bot_id_to_inject) {
            // Check if this bot has *already* been rendered by the main shortcode class
            // Access the static property via the instance reference
            if (!isset($this->shortcode_instance::$rendered_bot_ids[$bot_id_to_inject])) {
                // Render the shortcode using the main public method from the Shortcode instance
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode output is sanitized internally and safe to render
                echo $this->shortcode_instance->render_chatbot_shortcode(['id' => $bot_id_to_inject]);
            }
        }
    }
}

/**
 * Handles validation logic for the Chatbot Shortcode.
 */
class Validator {

    /**
     * Validates shortcode attributes, checks for duplicates, and verifies the bot ID.
     *
     * @param array $atts Raw shortcode attributes.
     * @param array $rendered_bot_ids Reference to the array tracking rendered bot IDs.
     * @return int|WP_Error Valid bot ID on success, WP_Error on failure.
     */
    public static function validate_atts(array $atts, array &$rendered_bot_ids) {
        $atts = shortcode_atts(['id' => 0], $atts, 'aipkit_chatbot');
        $bot_id = absint($atts['id']);

        if (empty($bot_id)) {
            return new WP_Error('invalid_id', sprintf('[AIPKit Chatbot Error: Invalid Chatbot ID: %s]', esc_html($atts['id'])));
        }

        $bot_post = get_post($bot_id);
        if (!$bot_post || $bot_post->post_type !== AdminSetup::POST_TYPE || !in_array($bot_post->post_status, ['publish', 'draft'])) {
            return new WP_Error('not_found', sprintf('[AIPKit Chatbot Error: Invalid or non-existent Chatbot ID: %d]', $bot_id));
        }

        return $bot_id;
    }
}

namespace WPAICG\Chat\Frontend;

// Use statements for the new helper classes
use WPAICG\Chat\Frontend\Shortcode\Validator;
use WPAICG\Chat\Frontend\Shortcode\DataProvider;
use WPAICG\Chat\Frontend\Shortcode\FeatureManager;
use WPAICG\Chat\Frontend\Shortcode\Configurator;
use WPAICG\Chat\Frontend\Shortcode\Renderer;
use WPAICG\Chat\Frontend\Shortcode\SiteWideHandler;
use WPAICG\Chat\Frontend\Assets\AssetsEnqueuer; // Import the enqueuer class
use WP_Error;

/**
 * Orchestrates rendering the [aipkit_chatbot] shortcode.
 * Uses helper classes for validation, data fetching, config, features, and rendering.
 * Also handles site-wide injection via a dedicated handler.
 */
class Shortcode {

    /**
     * Tracks IDs rendered on the current page to prevent duplicates.
     * Made public static so SiteWideHandler can access it via the instance.
     * @var array
     */
    public static $rendered_bot_ids = [];

    private $renderer; // Instance of the renderer class
    private $site_wide_handler; // Instance of the site-wide handler

    public function __construct() {
        // Instantiate classes that need state or complex dependencies
        $this->renderer = new Renderer();
        // Pass the current instance ($this) to the SiteWideHandler
        $this->site_wide_handler = new SiteWideHandler($this);
        $this->register_hooks();
    }

    /**
     * Registers WordPress hooks.
     */
    private function register_hooks() {
        // Use the instance method from the handler for the footer hook
        add_action('wp_footer', [$this->site_wide_handler, 'inject_site_wide_chatbot'], 10);
    }

    /**
     * Main entry point for rendering the chatbot shortcode.
     * Orchestrates validation, data fetching, configuration, and HTML generation.
     *
     * @param array $atts Shortcode attributes.
     * @return string The rendered HTML or an error message/empty string.
     */
    public function render_chatbot_shortcode($atts) {
        // 1. Validate Attributes
        $validation_result = Validator::validate_atts($atts, self::$rendered_bot_ids);
        if (is_wp_error($validation_result)) {
            return $this->handle_render_error($validation_result);
        }
        return $this->render_bot($validation_result);
    }

    /**
     * The chat in its own space, even for a bot that runs as the site's popup. First-run setup
     * shows the real chatbot this way; the bot's saved settings are unchanged.
     *
     * @return string|WP_Error
     */
    public function render_inline(int $bot_id) {
        $validation_result = Validator::validate_atts(['id' => $bot_id], self::$rendered_bot_ids);
        if (is_wp_error($validation_result)) {
            return $validation_result;
        }
        return $this->render_bot($validation_result, true);
    }

    private function render_bot(int $bot_id, bool $inline = false) {
        // 2. Get Bot Data
        $bot_data = DataProvider::get_bot_data($bot_id);
        if (is_wp_error($bot_data)) {
            return $this->handle_render_error($bot_data, $bot_id);
        }
        $bot_post = $bot_data['post'];
        $bot_settings = $bot_data['settings'];
        if ($inline) {
            $bot_settings['popup_enabled'] = '0';
        }

        // 3. Determine Feature Flags
        $feature_flags = FeatureManager::determine_flags($bot_settings);

        // 4. Prepare Frontend Config
        $frontend_config = Configurator::prepare_config($bot_id, $bot_post, $bot_settings, $feature_flags);

        // 5. Signal Assets Needed AND Force Enqueue
        Assets::require_assets(
            $feature_flags['pdf_ui_enabled'],
            $feature_flags['enable_copy_button'],
            $feature_flags['starters_ui_enabled'],
            $feature_flags['sidebar_ui_enabled'],
            $feature_flags['feedback_ui_enabled'],
            $feature_flags['tts_ui_enabled'],
            $feature_flags['enable_voice_input_ui'],
            true,
            $feature_flags['image_upload_ui_enabled'],
            $feature_flags['file_upload_ui_enabled'],
            $feature_flags['enable_realtime_voice_ui']
        );

        // --- THE FIX: Manually trigger the enqueuer logic ---
        // This ensures assets are loaded even if the `wp_enqueue_scripts` hook has already run.
        if (class_exists(AssetsEnqueuer::class)) {
            (new AssetsEnqueuer())->process_assets();
        }
        // --- END FIX ---

        // 6. Mark as rendered *before* generating HTML
        self::$rendered_bot_ids[$bot_id] = true;

        // 7. Generate the HTML using the Renderer instance
        return $this->renderer->render_chatbot_html($bot_id, $bot_settings, $feature_flags, $frontend_config);
    }

    /**
     * Handles rendering errors, showing messages to admins only.
     * Kept in the main class as it relates to the overall shortcode result.
     *
     * @param WP_Error $error The error object.
     * @param int|null $bot_id Optional bot ID for context.
     * @return string HTML error message or empty string.
     */
    private function handle_render_error(WP_Error $error, $bot_id = null) {
        if (\WPAICG\AIPKit_Role_Manager::user_can_view_admin_notices()) {
            $message = $error->get_error_message();
            $code = $error->get_error_code();
            if ($code === 'already_rendered') {
                 return '<p style="color: orange; font-style: italic; margin: 1em 0;">' . esc_html($message) . '</p>';
            } else {
                 return '<p style="color: red; font-style: italic; margin: 1em 0;">' . esc_html($message) . '</p>';
            }
        }
        // For non-admins, mark as rendered if it was an 'already_rendered' error
        if ($bot_id && $error->get_error_code() === 'already_rendered') {
            self::$rendered_bot_ids[$bot_id] = true;
        }
        return ''; // Silently fail for regular users on other errors
    }
}
