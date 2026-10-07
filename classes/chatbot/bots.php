<?php

namespace WPAICG\Chat\Storage;

use WPAICG\Chat\Admin\AdminSetup;
use WP_Error;
use WPAICG\Core\Models\AIPKit_Model_Catalog;
use WPAICG\AIPKit_Providers;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Manages the site-wide chatbot setting, including uniqueness and caching.
 * Methods now delegate to namespaced functions.
 */
class SiteWideBotManager
{
    public const SITE_WIDE_BOT_CACHE_KEY = 'aipkit_site_wide_bot_id';

    /**
     * Gets the ID of the bot configured for site-wide injection.
     * Uses WP Object Cache and Transients for performance.
     *
     * @param bool $force_refresh Set to true to bypass cache and query DB directly.
     * @return int|null The ID of the site-wide bot, or null if none is set.
     */
    public function get_site_wide_bot_id($force_refresh = false): ?int
    {
        return SiteWide\get_site_wide_bot_id_logic($force_refresh);
    }

    /**
     * Ensures only one bot is set as site-wide enabled.
     * Called BEFORE updating the target bot's site-wide meta.
     *
     * @param int $target_bot_id The ID of the bot being potentially enabled.
     * @param bool $is_enabling Whether the target bot is being enabled for site-wide use.
     * @return bool True if the cache should be cleared after the operation.
     */
    public function ensure_site_wide_uniqueness(int $target_bot_id, bool $is_enabling): bool
    {
        return SiteWide\ensure_site_wide_uniqueness_logic($target_bot_id, $is_enabling, $this);
    }

    /**
     * Clears the site-wide bot ID cache.
     */
    public function clear_site_wide_cache(): void
    {
        SiteWide\clear_site_wide_cache_logic();
    }
}

/**
 * Handles creation and setup of the default chatbot.
 */
class DefaultBotSetup
{
    private static function get_default_bot_name(): string
    {
        return __('Customer Support', 'gpt3-ai-content-generator');
    }

    /**
     * Ensures we have a default chatbot in place.
     * Checks if one exists; if not, creates it.
     * Only sets initial settings when creating or if marker is missing.
     */
    public static function ensure_default_chatbot()
    {

        $existing = self::get_default_bot(); // This finds posts with the meta key _aipkit_default_bot = 1

        if (!$existing) {
            // No bot marked as default found, try to create one
            self::create_default_bot(); // This also calls set_initial_bot_settings on creation
        } else {
            // Default bot exists. Check if it's marked correctly.
            $is_marked = get_post_meta($existing->ID, '_aipkit_default_bot', true);
            if ($is_marked !== '1') {
                update_post_meta($existing->ID, '_aipkit_default_bot', '1');
            }
            if (trim((string) $existing->post_title) === 'Default') {
                wp_update_post([
                    'ID' => $existing->ID,
                    'post_title' => self::get_default_bot_name(),
                ]);
            }
            self::ensure_default_bot_hint_settings((int) $existing->ID);
            self::ensure_default_bot_header_avatar_settings((int) $existing->ID);
        }
    }

    /**
     * Ensures launcher hint defaults for existing default bots.
     */
    private static function ensure_default_bot_hint_settings(int $bot_id): void
    {
        if (!metadata_exists('post', $bot_id, '_aipkit_popup_label_enabled')) {
            update_post_meta($bot_id, '_aipkit_popup_label_enabled', BotSettingsManager::DEFAULT_POPUP_LABEL_ENABLED);
        }

        $stored_hint_text = get_post_meta($bot_id, '_aipkit_popup_label_text', true);
        if (!is_string($stored_hint_text) || trim($stored_hint_text) === '') {
            update_post_meta($bot_id, '_aipkit_popup_label_text', BotSettingsManager::DEFAULT_POPUP_LABEL_TEXT);
        }

        $allowed_hint_sizes = ['small', 'medium', 'large', 'xlarge'];
        $stored_hint_size = get_post_meta($bot_id, '_aipkit_popup_label_size', true);
        if (!is_string($stored_hint_size) || !in_array($stored_hint_size, $allowed_hint_sizes, true)) {
            update_post_meta($bot_id, '_aipkit_popup_label_size', BotSettingsManager::DEFAULT_POPUP_LABEL_SIZE);
        }
    }

    /**
     * Moves the built-in bot from the legacy sparkle default to the new linked
     * header-avatar mode once, without changing explicit choices on other bots.
     */
    private static function ensure_default_bot_header_avatar_settings(int $bot_id): void
    {
        $migration_key = '_aipkit_header_avatar_link_mode_migrated';
        if (get_post_meta($bot_id, $migration_key, true) === '1') {
            return;
        }

        $avatar_type = get_post_meta($bot_id, '_aipkit_header_avatar_type', true);
        $avatar_value = get_post_meta($bot_id, '_aipkit_header_avatar_value', true);
        $avatar_url = get_post_meta($bot_id, '_aipkit_header_avatar_url', true);
        $is_missing = !metadata_exists('post', $bot_id, '_aipkit_header_avatar_type');
        $is_legacy_factory_default = (
            $avatar_type === 'default'
            && $avatar_value === 'spark'
            && trim((string) $avatar_url) === ''
        );

        if ($is_missing || $is_legacy_factory_default) {
            update_post_meta($bot_id, '_aipkit_header_avatar_type', BotSettingsManager::DEFAULT_HEADER_AVATAR_TYPE);
            update_post_meta($bot_id, '_aipkit_header_avatar_value', BotSettingsManager::DEFAULT_HEADER_AVATAR_VALUE);
            update_post_meta($bot_id, '_aipkit_header_avatar_url', BotSettingsManager::DEFAULT_HEADER_AVATAR_URL);
        }

        update_post_meta($bot_id, $migration_key, '1');
    }

    /**
     * Checks if a default chatbot exists.
     */
    private static function get_default_bot(): ?\WP_Post
    {
        if (!class_exists('\\WPAICG\\Chat\\Admin\\AdminSetup')) {
            $admin_setup_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/admin.php';
            if (file_exists($admin_setup_path)) {
                require_once $admin_setup_path;
            } else {
                return null;
            }
        }

        $args = array(
            'post_type'      => AdminSetup::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Reason: The meta/tax query is essential for the feature's functionality. Its performance impact is considered acceptable as the query is highly specific, paginated, cached, or runs in a non-critical admin/cron context.
            'meta_query' => array(
                array(
                    'key'   => '_aipkit_default_bot',
                    'value' => '1',
                    'compare' => '=',
                ),
            ),
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        );
        $query = new \WP_Query($args);
        $posts = $query->get_posts();
        return !empty($posts) ? $posts[0] : null;
    }

    /**
     * Gets the ID of the default chatbot.
     */
    public static function get_default_bot_id(): ?int
    {
        $default_bot = self::get_default_bot();
        return $default_bot ? $default_bot->ID : null;
    }

    /**
     * Creates the default chatbot.
     * Calls static BotSettingsManager::set_initial_bot_settings.
     * @return int|\WP_Error
     */
    private static function create_default_bot()
    {
        if (!class_exists('\\WPAICG\\Chat\\Admin\\AdminSetup')) {
            $admin_setup_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/admin.php';
            if (file_exists($admin_setup_path)) {
                require_once $admin_setup_path;
            } else {
                return new WP_Error('dependency_missing', 'AdminSetup class not found for default bot creation.');
            }
        }

        $botName = self::get_default_bot_name();

        $post_data = array(
            'post_title'  => $botName,
            'post_type'   => AdminSetup::POST_TYPE,
            'post_status' => 'publish',
            'post_author' => get_current_user_id() ?: 1,
        );
        $post_id = wp_insert_post($post_data, true);
        if (is_wp_error($post_id) || $post_id === 0) {
            $error_message = is_wp_error($post_id) ? $post_id->get_error_message() : 'wp_insert_post returned 0';
            return new WP_Error('creation_failed', __('Error creating default chatbot post.', 'gpt3-ai-content-generator'));
        }

        update_post_meta($post_id, '_aipkit_default_bot', '1');
        BotSettingsManager::set_initial_bot_settings($post_id, $botName);
        return $post_id;
    }

    /**
     * Resets a given chatbot's settings to the initial defaults.
     * Calls static BotSettingsManager::set_initial_bot_settings.
     * @return bool|\WP_Error
     */
    public static function reset_bot_settings($bot_id)
    {
        if (!class_exists('\\WPAICG\\Chat\\Admin\\AdminSetup')) {
            $admin_setup_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/admin.php';
            if (file_exists($admin_setup_path)) {
                require_once $admin_setup_path;
            } else {
                return new WP_Error('dependency_missing', 'AdminSetup class not found for bot reset.');
            }
        }

        $bot_id = absint($bot_id);
        if (empty($bot_id) || get_post_type($bot_id) !== AdminSetup::POST_TYPE) {
            return new WP_Error('invalid_bot_id_reset', __('Invalid chatbot ID provided for reset.', 'gpt3-ai-content-generator'));
        }

        $bot_post = get_post($bot_id);
        if (!$bot_post) {
            return new WP_Error('bot_not_found_reset', __('Chatbot post not found for reset.', 'gpt3-ai-content-generator'));
        }

        $is_actually_default = (get_post_meta($bot_id, '_aipkit_default_bot', true) === '1');
        $was_site_wide = (get_post_meta($bot_id, '_aipkit_site_wide_enabled', true) === '1');

        BotSettingsManager::set_initial_bot_settings($bot_id, $bot_post->post_title);

        if (!$is_actually_default) {
            delete_post_meta($bot_id, '_aipkit_default_bot');
        } else {
            // Ensure default marker remains if it is the default bot
            update_post_meta($bot_id, '_aipkit_default_bot', '1');
        }

        if ($was_site_wide) {

            if (class_exists('\\WPAICG\\Chat\\Storage\\SiteWideBotManager')) {
                $site_wide_manager = new SiteWideBotManager();
                $site_wide_manager->clear_site_wide_cache();
            }
        }
        return true;
    }

}

/**
 * AIPKit Chatbot - Settings Manager (Refactored)
 * Handles getting/saving/defaulting chatbot settings stored as post meta.
 * Delegates actual logic to new helper classes.
 */

class BotSettingsManager
{
    public const DEFAULT_THEME = 'custom';
    public const DEFAULT_THEME_PRESET_KEY = 'ocean';
    public const DEFAULT_DEPLOY_MODE = 'popup';
    public const DEFAULT_POPUP_ENABLED = '1';
    public const DEFAULT_SITE_WIDE_ENABLED = '0';
    // --- Constants for Default Settings ---
    public const DEFAULT_TEMPERATURE = 1.0;
    public const DEFAULT_MAX_COMPLETION_TOKENS = 1500;
    public const DEFAULT_INSTRUCTIONS = "You are a helpful AI assistant for this website. Answer clearly and concisely, usually in a few sentences. Give longer explanations when the user asks for more detail. Today's date is [date].";
    public const DEFAULT_MAX_MESSAGES = 15;
    public const DEFAULT_ENABLE_FULLSCREEN = '1';
    public const DEFAULT_ENABLE_DOWNLOAD = '0';
    public const DEFAULT_ENABLE_COPY_BUTTON = '1';
    public const DEFAULT_ENABLE_FEEDBACK = '1';
    public const DEFAULT_ENABLE_CONSENT_COMPLIANCE = '0';
    public const DEFAULT_POPUP_DELAY = 0;
    public const DEFAULT_ENABLE_CONVERSATION_STARTERS = '1';
    public const DEFAULT_ENABLE_CONVERSATION_SIDEBAR = '0';
    public const DEFAULT_POPUP_ICON_TYPE = 'default';
    public const DEFAULT_POPUP_ICON_STYLE = 'circle';
    public const DEFAULT_POPUP_ICON_VALUE = 'chat-bubble';
    public const DEFAULT_POPUP_ICON_SIZE = 'medium'; // allowed: small|medium|large|xlarge
    // --- Popup Hint/Label Defaults ---
    public const DEFAULT_POPUP_LABEL_ENABLED = '1';
    public const DEFAULT_POPUP_LABEL_TEXT = 'Need help? Ask me!';
    public const DEFAULT_POPUP_LABEL_MODE = 'on_delay'; // allowed: always|on_delay|until_open|until_dismissed
    public const DEFAULT_POPUP_LABEL_DELAY_SECONDS = 1;
    public const DEFAULT_POPUP_LABEL_AUTO_HIDE_SECONDS = 0; // 0 = never auto-hide
    public const DEFAULT_POPUP_LABEL_DISMISSIBLE = '1';
    public const DEFAULT_POPUP_LABEL_FREQUENCY = 'once_per_visitor'; // allowed: always|once_per_session|once_per_visitor
    public const DEFAULT_POPUP_LABEL_SHOW_ON_MOBILE = '1';
    public const DEFAULT_POPUP_LABEL_SHOW_ON_DESKTOP = '1';
    public const DEFAULT_POPUP_LABEL_VERSION = '';
    public const DEFAULT_POPUP_LABEL_SIZE = 'large'; // allowed: small|medium|large|xlarge
    // --- Header Defaults ---
    public const DEFAULT_HEADER_AVATAR_URL = '';
    public const DEFAULT_HEADER_AVATAR_TYPE = 'inherit';
    public const DEFAULT_HEADER_AVATAR_VALUE = self::DEFAULT_POPUP_ICON_VALUE;
    public const DEFAULT_HEADER_ONLINE_TEXT = 'Online';
    public const DEFAULT_CONTENT_AWARE_ENABLED = '0';
    public const DEFAULT_TOKEN_GUEST_LIMIT = null;
    public const DEFAULT_TOKEN_USER_LIMIT = null;
    public const DEFAULT_TOKEN_RESET_PERIOD = 'never';
    public const DEFAULT_TOKEN_LIMIT_MESSAGE = 'You have reached your quota for this period.';
    public const DEFAULT_TOKEN_LIMIT_MODE = 'general';
    public const DEFAULT_TOKEN_LIMIT_PRIMARY_ACTION_TYPE = 'none';
    public const DEFAULT_TOKEN_LIMIT_PRIMARY_ACTION_LABEL = '';
    public const DEFAULT_TOKEN_LIMIT_PRIMARY_ACTION_URL = '';
    public const DEFAULT_TOKEN_LIMIT_SECONDARY_ACTION_TYPE = 'none';
    public const DEFAULT_TOKEN_LIMIT_SECONDARY_ACTION_LABEL = '';
    public const DEFAULT_TOKEN_LIMIT_SECONDARY_ACTION_URL = '';
    public const DEFAULT_TTS_ENABLED = '0';
    public const DEFAULT_TTS_PROVIDER = 'Google';
    public const DEFAULT_TTS_AUTO_PLAY = '0';
    public const DEFAULT_ENABLE_VOICE_INPUT = '0';
    public const DEFAULT_STT_PROVIDER = 'OpenAI';
    public const DEFAULT_STT_AZURE_MODEL_ID = '';
    public const DEFAULT_IMAGE_TRIGGERS = '/image, /generate';
    public const DEFAULT_ENABLE_IMAGE_GENERATION = '0';
    public const DEFAULT_ENABLE_FILE_UPLOAD = '0';
    public const DEFAULT_ENABLE_IMAGE_UPLOAD = '0';
    public const DEFAULT_OPENAI_CONVERSATION_STATE_ENABLED = '0';
    public const DEFAULT_GOOGLE_CONVERSATION_STATE_ENABLED = '0';
    public const DEFAULT_OPENROUTER_SESSION_STICKINESS = '0';
    // --- Typing Indicator Defaults ---
    public const DEFAULT_CUSTOM_TYPING_TEXT = '';
    // --- Vector Store Constants ---
    public const DEFAULT_ENABLE_VECTOR_STORE = '0';
    public const DEFAULT_VECTOR_STORE_PROVIDER = 'openai';
    public const DEFAULT_OPENAI_VECTOR_STORE_ID = ''; // Legacy, will be array now
    public const DEFAULT_VECTOR_STORE_TOP_K = 3;
    public const DEFAULT_VECTOR_STORE_CONFIDENCE_THRESHOLD = 20; // NEW
    // --- Pinecone & Embedding Specific Constants ---
    public const DEFAULT_PINECONE_INDEX_NAME = '';
    public const DEFAULT_VECTOR_EMBEDDING_PROVIDER = 'openai';
    // --- Qdrant Specific Constants ---
    public const DEFAULT_QDRANT_COLLECTION_NAME = '';
    // --- OpenAI Web Search Constants ---
    public const DEFAULT_OPENAI_WEB_SEARCH_ENABLED = '0'; // Master switch in bot settings
    public const DEFAULT_OPENAI_WEB_SEARCH_CONTEXT_SIZE = 'medium';
    public const DEFAULT_OPENAI_WEB_SEARCH_LOC_TYPE = 'none';
    // --- Claude Web Search Constants ---
    public const DEFAULT_CLAUDE_WEB_SEARCH_ENABLED = '0';
    public const DEFAULT_CLAUDE_WEB_SEARCH_MAX_USES = 5;
    public const DEFAULT_CLAUDE_WEB_SEARCH_LOC_TYPE = 'none';
    public const DEFAULT_CLAUDE_WEB_SEARCH_CACHE_TTL = 'none';
    // --- OpenRouter Web Search Constants ---
    public const DEFAULT_OPENROUTER_WEB_SEARCH_ENABLED = '0';
    public const DEFAULT_OPENROUTER_WEB_SEARCH_ENGINE = 'auto';
    public const DEFAULT_OPENROUTER_WEB_SEARCH_MAX_RESULTS = 5;
    public const DEFAULT_OPENROUTER_WEB_SEARCH_MAX_USES = 1;
    public const DEFAULT_OPENROUTER_WEB_SEARCH_MAX_TOTAL_RESULTS = 10;
    public const DEFAULT_OPENROUTER_WEB_SEARCH_CONTEXT_SIZE = 'auto';
    public const DEFAULT_OPENROUTER_WEB_SEARCH_ALLOWED_DOMAINS = '';
    public const DEFAULT_OPENROUTER_WEB_SEARCH_EXCLUDED_DOMAINS = '';
    // --- xAI Web Search Constants ---
    public const DEFAULT_XAI_WEB_SEARCH_ENABLED = '0';
    // --- Frontend Web Toggle Defaults ---
    public const DEFAULT_WEB_TOGGLE_DEFAULT_ON = '0';
    public const DEFAULT_SHOW_SOURCES = '1';
    public const DEFAULT_SOURCES_LABEL = '';
    public const DEFAULT_SEARCHING_WEB_TEXT = '';
    public const DEFAULT_RETRIEVING_CONTEXT_TEXT = '';
    public const DEFAULT_GOOGLE_SEARCH_GROUNDING_ENABLED = '0'; // Master switch for bot
    public const DEFAULT_ENABLE_REALTIME_VOICE = '0';
    public const DEFAULT_DIRECT_VOICE_MODE = '0';
    public const DEFAULT_TURN_DETECTION = 'server_vad';
    public const DEFAULT_SPEED = 1.0;
    public const DEFAULT_INPUT_AUDIO_FORMAT = 'pcm16';
    public const DEFAULT_OUTPUT_AUDIO_FORMAT = 'pcm16';
    public const DEFAULT_INPUT_AUDIO_NOISE_REDUCTION = '1';
    public const DEFAULT_REASONING_EFFORT = 'none';

    public const DEFAULT_CUSTOM_THEME_FONT_FAMILY = 'inherit';
    public const DEFAULT_CUSTOM_THEME_BUBBLE_BORDER_RADIUS = 16;
    public const DEFAULT_CTS_PRIMARY_COLOR = '#0B5FFF';
    public const DEFAULT_CTS_SECONDARY_COLOR = '#F1F5FF';
    public const DEFAULT_CTS_ACCENT_COLOR = '#111111';
    public const DEFAULT_CTS_CONTAINER_MAX_WIDTH = 896; // px
    public const DEFAULT_CTS_POPUP_WIDTH = 380;         // px
    public const DEFAULT_CTS_CONTAINER_HEIGHT = 620;    // px
    public const DEFAULT_CTS_CONTAINER_MAX_HEIGHT = 90; // vh (number only)
    public const DEFAULT_CTS_CONTAINER_MIN_HEIGHT = 320;  // px
    public const DEFAULT_CTS_POPUP_HEIGHT = 620;        // px (can inherit from container_height)
    public const DEFAULT_CTS_POPUP_MIN_HEIGHT = 320;    // px (can inherit)
    public const DEFAULT_CTS_POPUP_MAX_HEIGHT = 90;     // vh (can inherit, number only)

    public static function get_default_model_id(string $catalog_key): string
    {
        return AIPKit_Model_Catalog::get_default_id($catalog_key);
    }

    /**
     * Whether a knowledge provider can run with the selected chatbot provider.
     *
     * Google File Search and Anthropic Files are native generation tools. The
     * remaining knowledge providers are retrieved independently and can supply
     * context to any chatbot provider.
     */
    public static function is_vector_store_provider_compatible(
        string $chatbot_provider,
        string $vector_store_provider
    ): bool {
        $chatbot_provider = strtolower(trim($chatbot_provider));
        $vector_store_provider = strtolower(trim($vector_store_provider));

        if ($vector_store_provider === 'google') {
            return $chatbot_provider === 'google';
        }

        if ($vector_store_provider === 'claude_files') {
            return $chatbot_provider === 'claude';
        }

        return true;
    }

    /**
     * Returns the chatbot provider required by a native knowledge provider.
     */
    public static function get_required_chatbot_provider_for_vector_store(
        string $vector_store_provider
    ): string {
        $vector_store_provider = strtolower(trim($vector_store_provider));

        if ($vector_store_provider === 'google') {
            return 'Google';
        }

        if ($vector_store_provider === 'claude_files') {
            return 'Claude';
        }

        return '';
    }

    /**
     * Resolves stored and effective native Knowledge state without changing
     * the selected provider or its stores.
     *
     * @return array{
     *     requested: bool,
     *     compatible: bool,
     *     effective: bool,
     *     required_provider: string,
     *     google_search_conflict: bool
     * }
     */
    public static function get_knowledge_capability_state(
        string $chatbot_provider,
        string $vector_store_provider,
        string $enable_vector_store,
        string $google_search_enabled = '0'
    ): array {
        $vector_store_provider = sanitize_key($vector_store_provider);
        $requested = $enable_vector_store === '1';
        $compatible = self::is_vector_store_provider_compatible(
            $chatbot_provider,
            $vector_store_provider
        );
        $effective = $requested && $compatible;

        return [
            'requested' => $requested,
            'compatible' => $compatible,
            'effective' => $effective,
            'required_provider' => self::get_required_chatbot_provider_for_vector_store(
                $vector_store_provider
            ),
            'google_search_conflict' => $effective
                && $vector_store_provider === 'google'
                && $google_search_enabled === '1',
        ];
    }

    private $site_wide_manager;
    private $settings_saver;

    public function __construct()
    {
        // Instantiate the shared settings helpers.

        if (class_exists(SiteWideBotManager::class)) {
            $this->site_wide_manager = new SiteWideBotManager();
        }

        // Share the site-wide manager with the saver.

        if (class_exists(AIPKit_Bot_Settings_Saver::class) && $this->site_wide_manager) {
            $this->settings_saver = new AIPKit_Bot_Settings_Saver($this->site_wide_manager);
        }
    }

    public function get_chatbot_settings(int $bot_id): array
    {
        $settings = AIPKit_Bot_Settings_Getter::get($bot_id);
        return is_array($settings) ? $settings : [];
    }

    /**
     * @return bool|\WP_Error
     */
    public function save_bot_settings(int $botId, array $settings)
    {
        if (!$this->settings_saver) {
            return new \WP_Error('dependency_missing_manager_save', __('Settings saving component is missing.', 'gpt3-ai-content-generator'));
        }
        return $this->settings_saver->save($botId, $settings);
    }

    /**
     * Apply factory settings to a chatbot.
     *
     * @param int    $post_id     Chatbot post ID.
     * @param string $botName     Chatbot name.
     * @param string $deploy_mode Initial deployment mode: popup or inline.
     */
    public static function set_initial_bot_settings(int $post_id, string $botName, string $deploy_mode = self::DEFAULT_DEPLOY_MODE)
    {

        AIPKit_Bot_Settings_Initializer::initialize($post_id, $botName, $deploy_mode);
    }

    /**
     * Returns the default conversation starter prompts for newly initialized bots.
     */
    public static function get_default_conversation_starters(): array
    {
        return [
            __('What can you do?', 'gpt3-ai-content-generator'),
            __('Tell me a fun fact', 'gpt3-ai-content-generator'),
        ];
    }

    /**
     * Returns the default conversation starter prompts as JSON for storage.
     */
    public static function get_default_conversation_starters_json(): string
    {
        return self::encode_conversation_starters(self::get_default_conversation_starters());
    }

    /**
     * Normalize conversation starters submitted by the textarea or an API client.
     *
     * @param mixed $raw_starters Newline-delimited text or an array of starter strings.
     * @return string[]
     */
    public static function normalize_conversation_starters($raw_starters): array
    {
        if (is_array($raw_starters)) {
            $candidates = $raw_starters;
        } elseif (is_scalar($raw_starters)) {
            $candidates = preg_split('/\R/u', (string) $raw_starters);
            if (!is_array($candidates)) {
                $candidates = [];
            }
        } else {
            $candidates = [];
        }

        $starters = [];
        foreach ($candidates as $candidate) {
            if (!is_scalar($candidate)) {
                continue;
            }
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                $starters[] = $candidate;
            }
        }

        return array_slice($starters, 0, 6);
    }

    /**
     * Encode normalized conversation starters as JSON.
     *
     * @param string[] $starters Normalized conversation starter strings.
     */
    public static function encode_conversation_starters(array $starters): string
    {
        $json = wp_json_encode(array_values($starters), JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : '[]';
    }

    /**
     * Encode conversation starters for update_post_meta(). WordPress unslashes
     * metadata values before storage, so JSON must be slashed at this boundary.
     *
     * @param string[] $starters Normalized conversation starter strings.
     */
    public static function get_conversation_starters_meta_value(array $starters): string
    {
        return wp_slash(self::encode_conversation_starters($starters));
    }

    /**
     * Returns the default quota reached message.
     */
    public static function get_default_token_limit_message(): string
    {
        return __('You have reached your quota for this period.', 'gpt3-ai-content-generator');
    }

    /**
     * Returns the supported recovery action types for quota notices.
     *
     * @return string[]
     */
    public static function get_token_limit_action_types(): array
    {
        return [
            'none',
            'dashboard_usage',
            'dashboard_credits',
            'dashboard_purchases',
            'buy_credits',
            'custom_url',
        ];
    }

    /**
     * Returns the UI labels for supported quota recovery action types.
     *
     * @return array<string, string>
     */
    public static function get_token_limit_action_options(): array
    {
        return [
            'none' => __('No button', 'gpt3-ai-content-generator'),
            'dashboard_usage' => __('Customer dashboard: Usage', 'gpt3-ai-content-generator'),
            'dashboard_credits' => __('Customer dashboard: Credits', 'gpt3-ai-content-generator'),
            'dashboard_purchases' => __('Customer dashboard: Purchases', 'gpt3-ai-content-generator'),
            'buy_credits' => __('Buy credits page', 'gpt3-ai-content-generator'),
            'custom_url' => __('Custom URL', 'gpt3-ai-content-generator'),
        ];
    }

    /**
     * Returns the default label for a quota recovery action type.
     */
    public static function get_token_limit_action_default_label(string $action_type): string
    {
        switch ($action_type) {
            case 'dashboard_usage':
                return __('View usage', 'gpt3-ai-content-generator');
            case 'dashboard_credits':
                return __('View credits', 'gpt3-ai-content-generator');
            case 'dashboard_purchases':
                return __('View purchases', 'gpt3-ai-content-generator');
            case 'buy_credits':
                return __('Buy credits', 'gpt3-ai-content-generator');
            case 'custom_url':
                return __('Open link', 'gpt3-ai-content-generator');
            case 'none':
            default:
                return '';
        }
    }

    /**
     * Returns default quota recovery action settings for newly initialized bots.
     *
     * @return array<string, string>
     */
    public static function get_default_token_limit_action_settings(): array
    {
        return [
            'primary_type' => self::DEFAULT_TOKEN_LIMIT_PRIMARY_ACTION_TYPE,
            'primary_label' => self::DEFAULT_TOKEN_LIMIT_PRIMARY_ACTION_LABEL,
            'primary_url' => self::DEFAULT_TOKEN_LIMIT_PRIMARY_ACTION_URL,
            'secondary_type' => self::DEFAULT_TOKEN_LIMIT_SECONDARY_ACTION_TYPE,
            'secondary_label' => self::DEFAULT_TOKEN_LIMIT_SECONDARY_ACTION_LABEL,
            'secondary_url' => self::DEFAULT_TOKEN_LIMIT_SECONDARY_ACTION_URL,
        ];
    }

    /** Explicit dimension choices, independent of the selected color palette. */
    public static function normalize_theme_dimension_overrides($value): string
    {
        $keys = is_string($value) ? explode(',', $value) : (is_array($value) ? $value : []);
        return implode(',', array_intersect([
            'container_max_width', 'popup_width', 'container_height',
            'container_min_height', 'container_max_height',
        ], array_filter($keys, 'is_string')));
    }

    /**
     * Returns an array of default values for custom theme settings.
     * @return array
     */
    public static function get_custom_theme_defaults(): array
    {
        return [
            'primary_color' => self::DEFAULT_CTS_PRIMARY_COLOR,
            'secondary_color' => self::DEFAULT_CTS_SECONDARY_COLOR,
            'accent_color' => self::DEFAULT_CTS_ACCENT_COLOR,
            'font_family' => self::DEFAULT_CUSTOM_THEME_FONT_FAMILY,
            'bubble_border_radius' => self::DEFAULT_CUSTOM_THEME_BUBBLE_BORDER_RADIUS,
            'container_max_width' => self::DEFAULT_CTS_CONTAINER_MAX_WIDTH,
            'popup_width' => self::DEFAULT_CTS_POPUP_WIDTH,
            'container_height' => self::DEFAULT_CTS_CONTAINER_HEIGHT,
            'container_max_height' => self::DEFAULT_CTS_CONTAINER_MAX_HEIGHT,
            'container_min_height' => self::DEFAULT_CTS_CONTAINER_MIN_HEIGHT,
            'popup_height' => self::DEFAULT_CTS_POPUP_HEIGHT,
            'popup_min_height' => self::DEFAULT_CTS_POPUP_MIN_HEIGHT,
            'popup_max_height' => self::DEFAULT_CTS_POPUP_MAX_HEIGHT,
        ];
    }

    /**
     * Removes deprecated custom theme overrides stored in post meta.
     */
    public static function cleanup_custom_theme_meta(int $bot_id): void
    {
        $allowed_keys = array_keys(self::get_custom_theme_defaults());
        $allowed_meta_keys = [];
        foreach ($allowed_keys as $key) {
            $allowed_meta_keys['_aipkit_cts_' . $key] = true;
        }

        $all_meta = get_post_meta($bot_id);
        foreach ($all_meta as $meta_key => $_) {
            if (strpos($meta_key, '_aipkit_cts_') === 0 && !isset($allowed_meta_keys[$meta_key])) {
                delete_post_meta($bot_id, $meta_key);
            }
        }
    }

    /**
     * Returns the preset palettes shown for the custom theme picker.
     * @return array<int, array<string, string>>
     */
    public static function get_custom_theme_presets(): array
    {
        return [
            [
                'key' => 'ocean',
                'label' => __('Ocean', 'gpt3-ai-content-generator'),
                'secondary' => '#F1F5FF',
                'primary' => '#0B5FFF',
            ],
            [
                'key' => 'lagoon',
                'label' => __('Lagoon', 'gpt3-ai-content-generator'),
                'secondary' => '#ECFEFF',
                'primary' => '#0F766E',
            ],
            [
                'key' => 'forest',
                'label' => __('Forest', 'gpt3-ai-content-generator'),
                'secondary' => '#F2FBF5',
                'primary' => '#1B5E20',
            ],
            [
                'key' => 'ember',
                'label' => __('Ember', 'gpt3-ai-content-generator'),
                'secondary' => '#FFF7ED',
                'primary' => '#C2410C',
            ],
            [
                'key' => 'rose',
                'label' => __('Rose', 'gpt3-ai-content-generator'),
                'secondary' => '#FFF1F2',
                'primary' => '#BE123C',
            ],
            [
                'key' => 'berry',
                'label' => __('Berry', 'gpt3-ai-content-generator'),
                'secondary' => '#F5F3FF',
                'primary' => '#7C3AED',
            ],
            [
                'key' => 'midnight',
                'label' => __('Midnight', 'gpt3-ai-content-generator'),
                'secondary' => '#0F172A',
                'primary' => '#60A5FA',
            ],
            [
                'key' => 'citrine',
                'label' => __('Citrine', 'gpt3-ai-content-generator'),
                'secondary' => '#FFFBEB',
                'primary' => '#CA8A04',
            ],
        ];
    }
}

class BotLifecycleManager
{
    private $default_setup;
    private $site_wide_manager;

    public function __construct()
    {
        // Instantiate dependencies if the classes exist.
        if (class_exists(DefaultBotSetup::class)) {
            $this->default_setup = new DefaultBotSetup();
        }
        if (class_exists(SiteWideBotManager::class)) {
            $this->site_wide_manager = new SiteWideBotManager();
        }
    }

    /**
     * Creates a new chatbot post and sets its initial settings.
     * REMOVED: Duplicate name check. Bots can now have the same name.
     *
     * @param string $botName The desired name for the new bot.
     * @return array|WP_Error ['bot_id' => int, 'bot_name' => string, 'bot_settings' => array] on success, WP_Error on failure.
     */
    public function create_bot(string $botName)
    {
        if (empty($botName)) {
            return new WP_Error('empty_name', __('Chatbot name cannot be empty.', 'gpt3-ai-content-generator'));
        }

        $post_data = array(
            'post_title'  => $botName,
            'post_type'   => AdminSetup::POST_TYPE,
            'post_status' => 'publish',
            'post_author' => get_current_user_id() ?: 1,
        );

        $post_id = wp_insert_post($post_data, true);
        if (is_wp_error($post_id) || $post_id === 0) {
            $error_message = is_wp_error($post_id) ? $post_id->get_error_message() : 'wp_insert_post returned 0';
            return new WP_Error('creation_failed', __('Error creating chatbot post.', 'gpt3-ai-content-generator'));
        }

        // Call the static method from BotSettingsManager to set defaults
        BotSettingsManager::set_initial_bot_settings($post_id, $botName);

        // Fetch the settings AFTER setting them
        // Need an instance to call the non-static get_chatbot_settings
        $settings_manager_instance = new BotSettingsManager();
        $saved_settings = $settings_manager_instance->get_chatbot_settings($post_id);
        return ['bot_id' => $post_id, 'bot_name' => $botName, 'bot_settings' => $saved_settings];
    }

    /**
     * Deletes (trashes) a chatbot post.
     *
     * @param int $botId The ID of the bot to delete.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function delete_bot(int $botId)
    {
        // Ensure AdminSetup class is loaded for POST_TYPE constant
        if (!class_exists(AdminSetup::class)) {
            return new WP_Error('dependency_missing', 'AdminSetup class not available.');
        }

        if (empty($botId) || get_post_type($botId) !== AdminSetup::POST_TYPE) {
            return new WP_Error('invalid_bot_id_delete', __('Invalid chatbot ID provided for deletion.', 'gpt3-ai-content-generator'));
        }
        if (!$this->site_wide_manager) {
            return new WP_Error('missing_dependency', __('SiteWideBotManager not initialized for deletion.', 'gpt3-ai-content-generator'));
        }

        // Check if it's the default bot using the static method
        $default_bot_id = DefaultBotSetup::get_default_bot_id();
        if ($botId === $default_bot_id) {
            return new WP_Error('cannot_delete_default', __('The default chatbot cannot be deleted.', 'gpt3-ai-content-generator'));
        }

        $was_site_wide = (get_post_meta($botId, '_aipkit_site_wide_enabled', true) === '1');
        $deleted = wp_delete_post($botId, false); // Move to trash

        if (!$deleted) {
            return new WP_Error('delete_failed', __('Failed to delete chatbot.', 'gpt3-ai-content-generator'));
        }

        if ($was_site_wide) {
            $this->site_wide_manager->clear_site_wide_cache();
        }

        return true;
    }
}

/**
 * Facade class for managing Chatbot posts and settings.
 * Delegates operations to BotLifecycleManager and BotSettingsManager.
 * Retains get_chatbots and helper methods for default/site-wide bots.
 * ADDED: New method get_chatbots_with_settings() for optimized data fetching.
 */
class BotStorage
{
    private $lifecycle_manager;
    private $settings_manager;
    private $default_setup; // Keep for ensure_default_chatbot()
    private $site_wide_manager; // Keep for get_site_wide_bot_id()

    public function __construct()
    {
        // Load the admin post-type dependency when used outside bootstrap.

        $admin_setup_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/admin.php'; // Need AdminSetup path
         // ADDED: Ensure getter is loaded

        // AdminSetup may also be loaded by the chat dependency loader.
        if (file_exists($admin_setup_path) && !class_exists(AdminSetup::class)) {
            require_once $admin_setup_path;
        }

        // Instantiate dependencies needed by this facade or its children
        if (class_exists(DefaultBotSetup::class)) {
            $this->default_setup = new DefaultBotSetup();
        }
        if (class_exists(SiteWideBotManager::class)) {
            $this->site_wide_manager = new SiteWideBotManager();
        }

        if (class_exists(BotLifecycleManager::class)) {
            $this->lifecycle_manager = new BotLifecycleManager();
        }
        if (class_exists(BotSettingsManager::class)) {
            $this->settings_manager = new BotSettingsManager();
        }
    }

    /**
     * Retrieve all published chatbots.
     * (Kept in Facade for now)
     *
     * @param bool $prefetch_meta Whether to prefetch post meta for all bots.
     * @return array Array of WP_Post objects.
     */
    public function get_chatbots(bool $prefetch_meta = true): array
    {
        if (!class_exists(AdminSetup::class)) {
            return [];
        }
        $args = array(
            'post_type'      => AdminSetup::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'update_post_meta_cache' => $prefetch_meta,
            'update_post_term_cache' => false,
        );
        $query = new \WP_Query($args);
        return $query->get_posts();
    }

    /**
     * NEW: Retrieve all published chatbots along with their settings, optimized.
     *
     * @return array Array of ['post' => WP_Post, 'settings' => array].
     */
    public function get_chatbots_with_settings(): array
    {
        $bot_posts = $this->get_chatbots(); // This WP_Query should cache meta
        if (empty($bot_posts)) {
            return [];
        }

        $bots_with_settings = [];
        foreach ($bot_posts as $bot_post) {
            // Fetch all meta for the current bot. This should hit the cache.
            $all_meta_for_bot = get_post_meta($bot_post->ID);
            $prefetched_meta = [];
            if (is_array($all_meta_for_bot)) {
                foreach ($all_meta_for_bot as $meta_key => $meta_values) {
                    // Store the single value, similar to get_post_meta($id, $key, true)
                    $prefetched_meta[$meta_key] = isset($meta_values[0]) ? $meta_values[0] : null;
                }
            }

            // Ensure AIPKit_Bot_Settings_Getter is loaded and class exists

            $settings = AIPKit_Bot_Settings_Getter::get($bot_post->ID, $prefetched_meta);
            if (!is_wp_error($settings)) {
                $bots_with_settings[] = [
                    'post' => $bot_post,
                    'settings' => $settings,
                ];
            }
        }
        return $bots_with_settings;
    }

    /**
     * Facade method to create a bot. Delegates to BotLifecycleManager.
     *
     * @param string $botName The desired name for the new bot.
     * @return array|WP_Error ['bot_id' => int, 'bot_name' => string, 'bot_settings' => array] on success, WP_Error on failure.
     */
    public function create_bot(string $botName)
    {
        if (!$this->lifecycle_manager) {
            return new WP_Error('init_error', 'Lifecycle Manager not available.');
        }
        return $this->lifecycle_manager->create_bot($botName);
    }

    /**
     * Facade method to save bot settings. Delegates to BotSettingsManager.
     *
     * @param int $botId The chatbot post ID.
     * @param array $settings The settings array from the form.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function save_bot_settings(int $botId, array $settings)
    {
        if (!$this->settings_manager) {
            return new WP_Error('init_error', 'Settings Manager not available.');
        }
        return $this->settings_manager->save_bot_settings($botId, $settings);
    }

    /**
     * Facade method to delete a bot. Delegates to BotLifecycleManager.
     *
     * @param int $botId The ID of the bot to delete.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function delete_bot(int $botId)
    {
        if (!$this->lifecycle_manager) {
            return new WP_Error('init_error', 'Lifecycle Manager not available.');
        }
        return $this->lifecycle_manager->delete_bot($botId);
    }

    /**
     * Facade method to get bot settings. Delegates to BotSettingsManager.
     *
     * @param int $bot_id The chatbot post ID.
     * @return array An associative array of settings.
     */
    public function get_chatbot_settings(int $bot_id): array
    {
        if (!$this->settings_manager) {
            return [];
        } // Return empty if manager failed
        $settings = $this->settings_manager->get_chatbot_settings($bot_id);

        if (is_wp_error($settings) || !is_array($settings)) {
            return [];
        }

        return $settings;
    }

    /**
     * Facade method to get the site-wide bot ID. Delegates to SiteWideBotManager.
     *
     * @param bool $force_refresh Set to true to bypass cache.
     * @return int|null The ID of the site-wide bot, or null if none is set.
     */
    public function get_site_wide_bot_id(bool $force_refresh = false): ?int
    {
        if (!$this->site_wide_manager) {
            return null;
        }
        return $this->site_wide_manager->get_site_wide_bot_id($force_refresh);
    }

    /**
     * Facade method to ensure the default chatbot exists. Delegates to DefaultBotSetup.
     */
    public function ensure_default_chatbot(): void
    {
        if (!$this->default_setup) {
            return;
        }
        // DefaultBotSetup::ensure_default_chatbot() is static now
        DefaultBotSetup::ensure_default_chatbot();
    }
}


class AIPKit_Bot_Settings_Getter
{
    /**
     * Retrieves and structures all settings for a given chatbot ID.
     * Delegates specific setting groups to individual logic functions.
     * MODIFIED: Accepts an optional array of prefetched meta to optimize database calls.
     *
     * @param int $bot_id The ID of the chatbot post.
     * @param array|null $prefetched_meta Optional. An array of already fetched meta for this bot.
     *                                     Format: [meta_key => single_meta_value, ...].
     * @return array|WP_Error An associative array of settings or WP_Error on failure.
     */
    public static function get(int $bot_id, ?array $prefetched_meta = null)
    {
        $validation_result = GetterMethods\validate_bot_post_logic($bot_id);
        if (is_wp_error($validation_result)) {
            return $validation_result;
        }
        $bot_post = $validation_result; // Validated WP_Post object

        $get_meta_fn = function ($key, $default = '') use ($bot_id, $prefetched_meta) {
            $value = '';

            if ($prefetched_meta !== null && array_key_exists($key, $prefetched_meta)) {
                $value = $prefetched_meta[$key];
            } else {
                $value = get_post_meta($bot_id, $key, true);
            }

            // Special handling for token limits where empty string means unlimited, not 0
            if (in_array($key, ['_aipkit_token_guest_limit', '_aipkit_token_user_limit'], true)) {
                return ($value === '') ? '' : $value; // Return empty string as is, otherwise actual value
            }
            if (in_array($key, ['_aipkit_tts_provider', '_aipkit_stt_provider', '_aipkit_chat_image_model_id', '_aipkit_vector_embedding_provider', '_aipkit_vector_embedding_model'], true)
                && (($prefetched_meta !== null && array_key_exists($key, $prefetched_meta)) || metadata_exists('post', $bot_id, $key))) {
                return $value;
            }
            // For other keys, if value is empty string (either from prefetched or get_post_meta) use default
            return ($value !== '') ? $value : $default;
        };
        // END MODIFICATION

        $bot_name = $bot_post->post_title ?: __('Chatbot', 'gpt3-ai-content-generator');

        $settings = [];
        $current_provider_from_main_settings = class_exists(AIPKit_Providers::class) ? AIPKit_Providers::get_current_provider() : 'OpenAI';

        $settings = array_merge($settings, GetterMethods\get_general_bot_settings_logic($bot_id, $bot_name, $get_meta_fn));
        $settings = array_merge($settings, GetterMethods\get_ai_configuration_logic($bot_id, $current_provider_from_main_settings, $get_meta_fn));
        $settings = array_merge($settings, GetterMethods\get_appearance_settings_logic($bot_id, $bot_name, $get_meta_fn));
        $settings = array_merge($settings, GetterMethods\get_conversation_starters_logic($bot_id, $get_meta_fn));
        $settings = array_merge($settings, GetterMethods\get_contextual_settings_logic($bot_id, $get_meta_fn));
        $settings = array_merge($settings, GetterMethods\get_vector_store_config_logic($bot_id, $get_meta_fn));
        $settings = array_merge($settings, GetterMethods\get_tts_config_logic($bot_id, $get_meta_fn));
        $settings = array_merge($settings, GetterMethods\get_stt_config_logic($bot_id, $get_meta_fn));
        $settings = array_merge($settings, GetterMethods\get_token_management_config_logic($bot_id, $get_meta_fn));
        $settings = array_merge($settings, GetterMethods\get_openai_specific_config_logic($bot_id, $get_meta_fn));
        $settings = array_merge($settings, GetterMethods\get_claude_specific_config_logic($bot_id, $get_meta_fn));
        $settings = array_merge($settings, GetterMethods\get_openrouter_specific_config_logic($bot_id, $get_meta_fn));
        $settings = array_merge($settings, GetterMethods\get_xai_specific_config_logic($bot_id, $get_meta_fn));
        $settings = array_merge($settings, GetterMethods\get_google_specific_config_logic($bot_id, $get_meta_fn));
        $settings['triggers_json'] = '[]';
        if (function_exists(__NAMESPACE__ . '\GetterMethods\get_trigger_config_logic')) {
            $settings = array_merge($settings, GetterMethods\get_trigger_config_logic($bot_id, $get_meta_fn));
        }
        if (function_exists(__NAMESPACE__ . '\GetterMethods\get_voice_agent_config_logic')) {
            $settings = array_merge($settings, GetterMethods\get_voice_agent_config_logic($bot_id, $get_meta_fn));
        } else {
            // Shared UI defaults keep Free functional without loading paid implementation.
            $settings = array_merge($settings, [
                'enable_realtime_voice' => BotSettingsManager::DEFAULT_ENABLE_REALTIME_VOICE,
                'direct_voice_mode' => BotSettingsManager::DEFAULT_DIRECT_VOICE_MODE,
                'realtime_model' => BotSettingsManager::get_default_model_id('OpenAIRealtime'),
                'realtime_voice' => BotSettingsManager::get_default_model_id('OpenAIRealtimeVoices'),
                'turn_detection' => BotSettingsManager::DEFAULT_TURN_DETECTION,
                'speed' => BotSettingsManager::DEFAULT_SPEED,
                'input_audio_format' => BotSettingsManager::DEFAULT_INPUT_AUDIO_FORMAT,
                'output_audio_format' => BotSettingsManager::DEFAULT_OUTPUT_AUDIO_FORMAT,
                'input_audio_noise_reduction' => BotSettingsManager::DEFAULT_INPUT_AUDIO_NOISE_REDUCTION,
            ]);
        }
        $settings = array_merge($settings, GetterMethods\get_embed_settings_logic($bot_id, $get_meta_fn)); // ADDED

        return $settings;
    }
}


/**
 * Handles SAVING chatbot settings.
 * This class now primarily delegates to the namespaced save_bot_settings_logic function.
 */
class AIPKit_Bot_Settings_Saver {

    private $site_wide_manager;

    public function __construct(SiteWideBotManager $site_wide_manager) {
        $this->site_wide_manager = $site_wide_manager;
    }

    /**
     * Saves the chatbot settings.
     * Delegates the core logic to the modularized save_bot_settings_logic function.
     *
     * @param int $botId The chatbot post ID.
     * @param array $raw_settings The raw settings array from the form (e.g., $_POST).
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function save(int $botId, array $raw_settings) {
        // Check if SiteWideBotManager was successfully initialized
        if (!$this->site_wide_manager) {
            return new WP_Error('dependency_missing_saver', __('Site-wide manager component is missing.', 'gpt3-ai-content-generator'));
        }

        // Call the externalized orchestrator function
        return SaverMethods\save_bot_settings_logic($botId, $raw_settings, $this->site_wide_manager);
    }
}

class AIPKit_Bot_Settings_Initializer
{
    /** Called only for a new bot or the untouched starter bot during onboarding. */
    public static function initialize_feature_defaults(int $post_id, string $provider, bool $only_untouched = false): void
    {
        if ($only_untouched) {
            $previous = get_post_meta($post_id, '_aipkit_initial_feature_defaults', true);
            if (!is_array($previous) || !$previous) { return; }
            foreach ($previous as $key => $value) {
                if (get_post_meta($post_id, '_aipkit_' . $key, true) !== $value) { return; }
            }
            foreach (['enable_vector_store', 'tts_enabled', 'enable_voice_input', 'enable_image_generation'] as $key) {
                if (get_post_meta($post_id, '_aipkit_' . $key, true) === '1') { return; }
            }
        }
        $defaults = AIPKit_Providers::get_new_feature_defaults('chatbot', $provider);
        $values = array_intersect_key($defaults, array_flip([
            'vector_store_provider', 'vector_embedding_provider', 'vector_embedding_model', 'tts_provider', 'stt_provider',
        ]));
        $values['chat_image_model_id'] = $defaults['image_model'];
        foreach (['tts', 'stt'] as $type) {
            $slug = $defaults[$type . '_provider'] === 'AIPufferCloud' ? 'cloud' : strtolower($defaults[$type . '_provider']);
            if ($slug === '') { continue; }
            $values[$type . '_' . $slug . '_model_id'] = $defaults[$type . '_model'];
            if ($type === 'tts') { $values['tts_' . $slug . '_voice_id'] = $defaults['tts_voice']; }
        }
        foreach ($values as $key => $value) { update_post_meta($post_id, '_aipkit_' . $key, $value); }
        update_post_meta($post_id, '_aipkit_initial_feature_defaults', $values);
    }

    /**
     * Initialize a chatbot with its factory settings.
     *
     * @param int    $post_id     Chatbot post ID.
     * @param string $botName     Chatbot name.
     * @param string $deploy_mode Initial deployment mode: popup or inline.
     */
    public static function initialize(int $post_id, string $botName, string $deploy_mode = BotSettingsManager::DEFAULT_DEPLOY_MODE)
    {
        if (!class_exists('\WPAICG\AIPKit_Providers')) {
            $providers_path = WPAICG_PLUGIN_DIR . 'classes/ai/settings.php';
            if (file_exists($providers_path)) {
                require_once $providers_path;
            } else {
                return;
            }
        }

        $default_greeting = __('Hello there!', 'gpt3-ai-content-generator');
        $default_subgreeting = __('How can I help you today?', 'gpt3-ai-content-generator');
        update_post_meta($post_id, '_aipkit_greeting_message', $default_greeting);
        update_post_meta($post_id, '_aipkit_subgreeting_message', $default_subgreeting);
        update_post_meta($post_id, '_aipkit_header_avatar_url', BotSettingsManager::DEFAULT_HEADER_AVATAR_URL);
        update_post_meta($post_id, '_aipkit_header_avatar_type', BotSettingsManager::DEFAULT_HEADER_AVATAR_TYPE);
        update_post_meta($post_id, '_aipkit_header_avatar_value', BotSettingsManager::DEFAULT_HEADER_AVATAR_VALUE);
        update_post_meta($post_id, '_aipkit_header_online_text', __('Online', 'gpt3-ai-content-generator'));
        $new_ai_selection = AIPKit_Providers::get_new_text_generation_selection([], true);
        $global_provider = (string) ($new_ai_selection['provider'] ?? 'OpenAI');
        $global_model = (string) ($new_ai_selection['model'] ?? '');
        update_post_meta($post_id, '_aipkit_provider', $global_provider);
        if (!empty($global_model)) {
            update_post_meta($post_id, '_aipkit_model', $global_model);
        } else {
            delete_post_meta($post_id, '_aipkit_model');
        }
        delete_post_meta($post_id, '_aipkit_azure_deployment');
        delete_post_meta($post_id, '_aipkit_azure_endpoint');
        update_post_meta($post_id, '_aipkit_theme', BotSettingsManager::DEFAULT_THEME);
        update_post_meta($post_id, '_aipkit_theme_preset_key', BotSettingsManager::DEFAULT_THEME_PRESET_KEY);
        update_post_meta($post_id, '_aipkit_instructions', BotSettingsManager::DEFAULT_INSTRUCTIONS);
        $deploy_mode = ($deploy_mode === 'inline')
            ? 'inline'
            : BotSettingsManager::DEFAULT_DEPLOY_MODE;
        $popup_enabled = ($deploy_mode === BotSettingsManager::DEFAULT_DEPLOY_MODE)
            ? BotSettingsManager::DEFAULT_POPUP_ENABLED
            : '0';
        update_post_meta($post_id, '_aipkit_deploy_mode', $deploy_mode);
        update_post_meta($post_id, '_aipkit_popup_enabled', $popup_enabled);
        update_post_meta($post_id, '_aipkit_popup_position', 'bottom-right');
        update_post_meta($post_id, '_aipkit_popup_delay', BotSettingsManager::DEFAULT_POPUP_DELAY);
        update_post_meta($post_id, '_aipkit_site_wide_enabled', BotSettingsManager::DEFAULT_SITE_WIDE_ENABLED);
        update_post_meta($post_id, '_aipkit_popup_icon_type', BotSettingsManager::DEFAULT_POPUP_ICON_TYPE);
        update_post_meta($post_id, '_aipkit_popup_icon_style', BotSettingsManager::DEFAULT_POPUP_ICON_STYLE);
        update_post_meta($post_id, '_aipkit_popup_icon_value', BotSettingsManager::DEFAULT_POPUP_ICON_VALUE);
        update_post_meta($post_id, '_aipkit_popup_icon_size', BotSettingsManager::DEFAULT_POPUP_ICON_SIZE);
        // Initialize Popup Hint/Label defaults
        update_post_meta($post_id, '_aipkit_popup_label_enabled', BotSettingsManager::DEFAULT_POPUP_LABEL_ENABLED);
        update_post_meta($post_id, '_aipkit_popup_label_text', BotSettingsManager::DEFAULT_POPUP_LABEL_TEXT);
        update_post_meta($post_id, '_aipkit_popup_label_mode', BotSettingsManager::DEFAULT_POPUP_LABEL_MODE);
        update_post_meta($post_id, '_aipkit_popup_label_delay_seconds', BotSettingsManager::DEFAULT_POPUP_LABEL_DELAY_SECONDS);
        update_post_meta($post_id, '_aipkit_popup_label_auto_hide_seconds', BotSettingsManager::DEFAULT_POPUP_LABEL_AUTO_HIDE_SECONDS);
        update_post_meta($post_id, '_aipkit_popup_label_dismissible', BotSettingsManager::DEFAULT_POPUP_LABEL_DISMISSIBLE);
        update_post_meta($post_id, '_aipkit_popup_label_frequency', BotSettingsManager::DEFAULT_POPUP_LABEL_FREQUENCY);
        update_post_meta($post_id, '_aipkit_popup_label_show_on_mobile', BotSettingsManager::DEFAULT_POPUP_LABEL_SHOW_ON_MOBILE);
        update_post_meta($post_id, '_aipkit_popup_label_show_on_desktop', BotSettingsManager::DEFAULT_POPUP_LABEL_SHOW_ON_DESKTOP);
        update_post_meta($post_id, '_aipkit_popup_label_version', BotSettingsManager::DEFAULT_POPUP_LABEL_VERSION);
        update_post_meta($post_id, '_aipkit_popup_label_size', BotSettingsManager::DEFAULT_POPUP_LABEL_SIZE);
        update_post_meta($post_id, '_aipkit_footer_text', '');
        update_post_meta($post_id, '_aipkit_enable_fullscreen', BotSettingsManager::DEFAULT_ENABLE_FULLSCREEN);
        update_post_meta($post_id, '_aipkit_enable_download', BotSettingsManager::DEFAULT_ENABLE_DOWNLOAD);
        update_post_meta($post_id, '_aipkit_enable_copy_button', BotSettingsManager::DEFAULT_ENABLE_COPY_BUTTON);
        update_post_meta($post_id, '_aipkit_enable_feedback', BotSettingsManager::DEFAULT_ENABLE_FEEDBACK);
        update_post_meta($post_id, '_aipkit_enable_consent_compliance', BotSettingsManager::DEFAULT_ENABLE_CONSENT_COMPLIANCE);
        update_post_meta($post_id, '_aipkit_consent_title', __('Consent Required', 'gpt3-ai-content-generator'));
        update_post_meta($post_id, '_aipkit_consent_message', __('Before starting the conversation, please agree to our Terms of Service and Privacy Policy.', 'gpt3-ai-content-generator'));
        update_post_meta($post_id, '_aipkit_consent_button', __('I Agree', 'gpt3-ai-content-generator'));
        update_post_meta($post_id, '_aipkit_enable_conversation_sidebar', BotSettingsManager::DEFAULT_ENABLE_CONVERSATION_SIDEBAR);
        $default_placeholder = __('Type your message...', 'gpt3-ai-content-generator');
        update_post_meta($post_id, '_aipkit_input_placeholder', $default_placeholder);
        // Typing indicator customization defaults
        update_post_meta($post_id, '_aipkit_custom_typing_text', BotSettingsManager::DEFAULT_CUSTOM_TYPING_TEXT);
        update_post_meta($post_id, '_aipkit_retrieving_context_text', BotSettingsManager::DEFAULT_RETRIEVING_CONTEXT_TEXT);
        update_post_meta($post_id, '_aipkit_temperature', (string)BotSettingsManager::DEFAULT_TEMPERATURE);
        update_post_meta($post_id, '_aipkit_max_completion_tokens', BotSettingsManager::DEFAULT_MAX_COMPLETION_TOKENS);
        update_post_meta($post_id, '_aipkit_max_messages', BotSettingsManager::DEFAULT_MAX_MESSAGES);
        update_post_meta($post_id, '_aipkit_reasoning_effort', BotSettingsManager::DEFAULT_REASONING_EFFORT);
        update_post_meta($post_id, '_aipkit_enable_conversation_starters', BotSettingsManager::DEFAULT_ENABLE_CONVERSATION_STARTERS);
        update_post_meta(
            $post_id,
            '_aipkit_conversation_starters',
            BotSettingsManager::get_conversation_starters_meta_value(
                BotSettingsManager::get_default_conversation_starters()
            )
        );
        update_post_meta($post_id, '_aipkit_content_aware_enabled', BotSettingsManager::DEFAULT_CONTENT_AWARE_ENABLED);
        update_post_meta($post_id, '_aipkit_openai_conversation_state_enabled', BotSettingsManager::DEFAULT_OPENAI_CONVERSATION_STATE_ENABLED);
        update_post_meta($post_id, '_aipkit_google_conversation_state_enabled', BotSettingsManager::DEFAULT_GOOGLE_CONVERSATION_STATE_ENABLED);
        update_post_meta($post_id, '_aipkit_openrouter_session_stickiness', BotSettingsManager::DEFAULT_OPENROUTER_SESSION_STICKINESS);
        $default_guest_limit_value = (BotSettingsManager::DEFAULT_TOKEN_GUEST_LIMIT === null) ? '' : (string)BotSettingsManager::DEFAULT_TOKEN_GUEST_LIMIT;
        $default_user_limit_value = (BotSettingsManager::DEFAULT_TOKEN_USER_LIMIT === null) ? '' : (string)BotSettingsManager::DEFAULT_TOKEN_USER_LIMIT;
        update_post_meta($post_id, '_aipkit_token_guest_limit', $default_guest_limit_value);
        update_post_meta($post_id, '_aipkit_token_user_limit', $default_user_limit_value);
        update_post_meta($post_id, '_aipkit_token_reset_period', BotSettingsManager::DEFAULT_TOKEN_RESET_PERIOD);
        $default_token_limit_actions = BotSettingsManager::get_default_token_limit_action_settings();
        update_post_meta($post_id, '_aipkit_token_limit_message', BotSettingsManager::get_default_token_limit_message());
        update_post_meta($post_id, '_aipkit_token_limit_mode', BotSettingsManager::DEFAULT_TOKEN_LIMIT_MODE);
        update_post_meta($post_id, '_aipkit_token_role_limits', '[]');
        update_post_meta($post_id, '_aipkit_token_limit_primary_action_type', $default_token_limit_actions['primary_type']);
        update_post_meta($post_id, '_aipkit_token_limit_primary_action_label', $default_token_limit_actions['primary_label']);
        update_post_meta($post_id, '_aipkit_token_limit_primary_action_url', $default_token_limit_actions['primary_url']);
        update_post_meta($post_id, '_aipkit_token_limit_secondary_action_type', $default_token_limit_actions['secondary_type']);
        update_post_meta($post_id, '_aipkit_token_limit_secondary_action_label', $default_token_limit_actions['secondary_label']);
        update_post_meta($post_id, '_aipkit_token_limit_secondary_action_url', $default_token_limit_actions['secondary_url']);
        update_post_meta($post_id, '_aipkit_tts_enabled', BotSettingsManager::DEFAULT_TTS_ENABLED);
        update_post_meta($post_id, '_aipkit_tts_google_voice_id', AIPKit_Providers::normalize_google_tts_voice(''));
        update_post_meta($post_id, '_aipkit_tts_google_model_id', AIPKit_Providers::normalize_google_tts_model(''));
        update_post_meta($post_id, '_aipkit_tts_openai_voice_id', BotSettingsManager::get_default_model_id('OpenAIVoices'));
        update_post_meta($post_id, '_aipkit_tts_openai_model_id', BotSettingsManager::get_default_model_id('OpenAITTS'));
        update_post_meta($post_id, '_aipkit_tts_elevenlabs_voice_id', '');
        update_post_meta($post_id, '_aipkit_tts_elevenlabs_model_id', BotSettingsManager::get_default_model_id('ElevenLabsModels'));
        update_post_meta($post_id, '_aipkit_tts_auto_play', BotSettingsManager::DEFAULT_TTS_AUTO_PLAY);
        update_post_meta($post_id, '_aipkit_enable_voice_input', BotSettingsManager::DEFAULT_ENABLE_VOICE_INPUT);
        update_post_meta($post_id, '_aipkit_stt_openai_model_id', BotSettingsManager::get_default_model_id('OpenAISTT'));
        update_post_meta($post_id, '_aipkit_stt_google_model_id', AIPKit_Providers::normalize_google_stt_model(''));
        update_post_meta($post_id, '_aipkit_stt_azure_model_id', BotSettingsManager::DEFAULT_STT_AZURE_MODEL_ID);
        update_post_meta($post_id, '_aipkit_image_triggers', BotSettingsManager::DEFAULT_IMAGE_TRIGGERS);
        update_post_meta($post_id, '_aipkit_enable_image_generation', BotSettingsManager::DEFAULT_ENABLE_IMAGE_GENERATION);
        update_post_meta($post_id, '_aipkit_enable_file_upload', BotSettingsManager::DEFAULT_ENABLE_FILE_UPLOAD);
        update_post_meta($post_id, '_aipkit_enable_image_upload', BotSettingsManager::DEFAULT_ENABLE_IMAGE_UPLOAD);
        update_post_meta($post_id, '_aipkit_enable_vector_store', BotSettingsManager::DEFAULT_ENABLE_VECTOR_STORE);
        update_post_meta($post_id, '_aipkit_openai_vector_store_ids', '[]');
        update_post_meta($post_id, '_aipkit_google_file_search_store_names', '[]');
        delete_post_meta($post_id, '_aipkit_openai_vector_store_id');
        update_post_meta($post_id, '_aipkit_pinecone_index_name', BotSettingsManager::DEFAULT_PINECONE_INDEX_NAME);
        update_post_meta($post_id, '_aipkit_qdrant_collection_name', BotSettingsManager::DEFAULT_QDRANT_COLLECTION_NAME);
        update_post_meta($post_id, '_aipkit_qdrant_collection_names', '[]');
        update_post_meta($post_id, '_aipkit_chroma_collection_name', '');
        update_post_meta($post_id, '_aipkit_chroma_collection_names', '[]');
        update_post_meta($post_id, '_aipkit_local_store_ids', '[]');
        update_post_meta($post_id, '_aipkit_vector_store_top_k', BotSettingsManager::DEFAULT_VECTOR_STORE_TOP_K);
        update_post_meta($post_id, '_aipkit_vector_store_confidence_threshold', BotSettingsManager::DEFAULT_VECTOR_STORE_CONFIDENCE_THRESHOLD); // NEW
        update_post_meta($post_id, '_aipkit_openai_web_search_enabled', BotSettingsManager::DEFAULT_OPENAI_WEB_SEARCH_ENABLED);
        update_post_meta($post_id, '_aipkit_openai_web_search_context_size', BotSettingsManager::DEFAULT_OPENAI_WEB_SEARCH_CONTEXT_SIZE);
        update_post_meta($post_id, '_aipkit_openai_web_search_loc_type', BotSettingsManager::DEFAULT_OPENAI_WEB_SEARCH_LOC_TYPE);
        update_post_meta($post_id, '_aipkit_openai_web_search_loc_country', '');
        update_post_meta($post_id, '_aipkit_openai_web_search_loc_city', '');
        update_post_meta($post_id, '_aipkit_openai_web_search_loc_region', '');
        update_post_meta($post_id, '_aipkit_openai_web_search_loc_timezone', '');
        update_post_meta($post_id, '_aipkit_claude_web_search_enabled', BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_ENABLED);
        update_post_meta($post_id, '_aipkit_claude_web_search_max_uses', (string) BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_MAX_USES);
        update_post_meta($post_id, '_aipkit_claude_web_search_loc_type', BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_LOC_TYPE);
        update_post_meta($post_id, '_aipkit_claude_web_search_loc_country', '');
        update_post_meta($post_id, '_aipkit_claude_web_search_loc_city', '');
        update_post_meta($post_id, '_aipkit_claude_web_search_loc_region', '');
        update_post_meta($post_id, '_aipkit_claude_web_search_loc_timezone', '');
        update_post_meta($post_id, '_aipkit_claude_web_search_allowed_domains', '');
        update_post_meta($post_id, '_aipkit_claude_web_search_blocked_domains', '');
        update_post_meta($post_id, '_aipkit_claude_web_search_cache_ttl', BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_CACHE_TTL);
        update_post_meta($post_id, '_aipkit_openrouter_web_search_enabled', BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_ENABLED);
        update_post_meta($post_id, '_aipkit_openrouter_web_search_engine', BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_ENGINE);
        update_post_meta($post_id, '_aipkit_openrouter_web_search_max_results', (string) BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_MAX_RESULTS);
        update_post_meta($post_id, '_aipkit_openrouter_web_search_max_uses', (string) BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_MAX_USES);
        update_post_meta($post_id, '_aipkit_openrouter_web_search_max_total_results', (string) BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_MAX_TOTAL_RESULTS);
        update_post_meta($post_id, '_aipkit_openrouter_web_search_context_size', BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_CONTEXT_SIZE);
        update_post_meta($post_id, '_aipkit_openrouter_web_search_allowed_domains', BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_ALLOWED_DOMAINS);
        update_post_meta($post_id, '_aipkit_openrouter_web_search_excluded_domains', BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_EXCLUDED_DOMAINS);
        update_post_meta($post_id, '_aipkit_xai_web_search_enabled', BotSettingsManager::DEFAULT_XAI_WEB_SEARCH_ENABLED);
        update_post_meta($post_id, '_aipkit_web_toggle_default_on', BotSettingsManager::DEFAULT_WEB_TOGGLE_DEFAULT_ON);
        update_post_meta($post_id, '_aipkit_show_sources', BotSettingsManager::DEFAULT_SHOW_SOURCES);
        update_post_meta($post_id, '_aipkit_sources_label', BotSettingsManager::DEFAULT_SOURCES_LABEL);
        update_post_meta($post_id, '_aipkit_searching_web_text', BotSettingsManager::DEFAULT_SEARCHING_WEB_TEXT);
        update_post_meta($post_id, '_aipkit_google_search_grounding_enabled', BotSettingsManager::DEFAULT_GOOGLE_SEARCH_GROUNDING_ENABLED);

        $custom_theme_defaults = BotSettingsManager::get_custom_theme_defaults();
        foreach ($custom_theme_defaults as $key => $default_value) {
            if (strpos($key, '_placeholder') === false) {
                update_post_meta($post_id, '_aipkit_cts_' . $key, $default_value);
            }
        }

        self::initialize_feature_defaults($post_id, $global_provider);

        if (function_exists(__NAMESPACE__ . '\initialize_paid_bot_settings_logic')) {
            initialize_paid_bot_settings_logic($post_id);
        }

    }
}

namespace WPAICG\Chat\Storage\SiteWide;

use WPAICG\Chat\Admin\AdminSetup;
use WPAICG\Chat\Storage\SiteWideBotManager;


/**
 * Logic for the get_site_wide_bot_id method of SiteWideBotManager.
 *
 * @param bool $force_refresh Set to true to bypass cache and query DB directly.
 * @return int|null The ID of the site-wide bot, or null if none is set.
 */
function get_site_wide_bot_id_logic(bool $force_refresh = false): ?int {
    $cache_key = SiteWideBotManager::SITE_WIDE_BOT_CACHE_KEY;
    $bot_id = null;

    if (!$force_refresh) {
        $bot_id = wp_cache_get($cache_key, 'aipkit');
        if ($bot_id !== false) {
            return ($bot_id === 'none') ? null : (int) $bot_id;
        }

        $bot_id = get_transient($cache_key);
        if ($bot_id !== false) {
            wp_cache_set($cache_key, $bot_id, 'aipkit', MINUTE_IN_SECONDS);
            return ($bot_id === 'none') ? null : (int) $bot_id;
        }
    }

    // Query the Database
    // Ensure AdminSetup is available for POST_TYPE
    if (!class_exists(AdminSetup::class)) {
        $admin_setup_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/admin.php';
        if (file_exists($admin_setup_path)) {
            require_once $admin_setup_path;
        } else {
            return null; // Cannot proceed without post type
        }
    }

    $args = array(
        'post_type'      => AdminSetup::POST_TYPE,
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Reason: The meta/tax query is essential for the feature's functionality. Its performance impact is considered acceptable as the query is highly specific, paginated, cached, or runs in a non-critical admin/cron context.
        'meta_query'     => array(
            'relation' => 'AND',
            array('key' => '_aipkit_site_wide_enabled', 'value' => '1', 'compare' => '='),
            array('key' => '_aipkit_popup_enabled', 'value' => '1', 'compare' => '=') // Must be popup too
        ),
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    );
    $query = new \WP_Query($args);
    $found_ids = $query->get_posts();

    $bot_id = !empty($found_ids) ? (int) $found_ids[0] : null;
    $cache_value = ($bot_id === null) ? 'none' : $bot_id;

    wp_cache_set($cache_key, $cache_value, 'aipkit', MINUTE_IN_SECONDS);
    set_transient($cache_key, $cache_value, MINUTE_IN_SECONDS * 5);

    return $bot_id;
}

/**
 * Logic for the ensure_site_wide_uniqueness method of SiteWideBotManager.
 *
 * @param int $target_bot_id The ID of the bot being potentially enabled.
 * @param bool $is_enabling Whether the target bot is being enabled for site-wide use.
 * @param SiteWideBotManager $site_wide_manager_instance Instance of SiteWideBotManager to call its methods.
 * @return bool True if the cache should be cleared after the operation.
 */
function ensure_site_wide_uniqueness_logic(int $target_bot_id, bool $is_enabling, SiteWideBotManager $site_wide_manager_instance): bool {
    $clear_cache = false;

    if ($is_enabling) {
        if (!class_exists(AdminSetup::class)) {
            $admin_setup_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/admin.php';
            if (file_exists($admin_setup_path)) {
                require_once $admin_setup_path;
            }
        }

        if (class_exists(AdminSetup::class)) {
            // Disable every other popup bot marked as site-wide.
            $conflicting_query = new \WP_Query([
                'post_type'              => AdminSetup::POST_TYPE,
                'post_status'            => ['publish', 'draft'],
                'posts_per_page'         => -1,
                'fields'                 => 'ids',
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Targeted query over chatbot posts for uniqueness enforcement.
                'meta_query'             => [
                    'relation' => 'AND',
                    ['key' => '_aipkit_site_wide_enabled', 'value' => '1', 'compare' => '='],
                    ['key' => '_aipkit_popup_enabled', 'value' => '1', 'compare' => '='],
                ],
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ]);
            $conflicting_ids = $conflicting_query->get_posts();
            foreach ($conflicting_ids as $conflicting_id) {
                $conflicting_id = absint($conflicting_id);
                if ($conflicting_id > 0 && $conflicting_id !== $target_bot_id) {
                    update_post_meta($conflicting_id, '_aipkit_site_wide_enabled', '0');
                    $clear_cache = true;
                }
            }
        }

        // Enabling site-wide on target always requires cache refresh.
        $clear_cache = true;
    } else {
        // If target was site-wide, cache must be refreshed.
        $was_site_wide = get_post_meta($target_bot_id, '_aipkit_site_wide_enabled', true) === '1';
        if ($was_site_wide) {
            $clear_cache = true;
        }
    }
    return $clear_cache;
}

/**
 * Logic for the clear_site_wide_cache method of SiteWideBotManager.
 */
function clear_site_wide_cache_logic(): void {
    wp_cache_delete(SiteWideBotManager::SITE_WIDE_BOT_CACHE_KEY, 'aipkit');
    delete_transient(SiteWideBotManager::SITE_WIDE_BOT_CACHE_KEY);
}

namespace WPAICG\Chat\Storage\GetterMethods;

use WPAICG\Chat\Admin\AdminSetup;
use WP_Error;
use WP_Post;
use WPAICG\Chat\Storage\BotSettingsManager;
use WPAICG\AIPKit_Providers;
use WPAICG\AIPKIT_AI_Settings;
use WPAICG\Core\AIPKit_OpenAI_Reasoning;
use WPAICG\Core\Models\AIPKit_Model_Catalog;


// --- fn-validate-bot-post.php ---
/**
 * Validates the bot post ID, type, and status.
 *
 * @param int $bot_id The ID of the bot post.
 * @return WP_Post|WP_Error The WP_Post object on success, or WP_Error on failure.
 */
function validate_bot_post_logic(int $bot_id) {
    if (!class_exists(AdminSetup::class)) {
        $admin_setup_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/admin.php';
        if (file_exists($admin_setup_path)) {
            require_once $admin_setup_path;
        } else {
            return new WP_Error('dependency_missing_validator', __('AdminSetup class missing.', 'gpt3-ai-content-generator'));
        }
    }

    $post = get_post($bot_id);
    if (!$post) {
        return new WP_Error('post_not_found_validator', __('Chatbot post not found.', 'gpt3-ai-content-generator'));
    }

    if ($post->post_type !== AdminSetup::POST_TYPE) {
        return new WP_Error('invalid_post_type_validator', __('Invalid chatbot post type.', 'gpt3-ai-content-generator'));
    }

    if (!in_array($post->post_status, ['publish', 'draft'], true)) {
        return new WP_Error('invalid_post_status_validator', __('Chatbot post has an invalid status.', 'gpt3-ai-content-generator'));
    }
    return $post;
}

// --- fn-get-general-bot-settings.php ---
/**
 * Retrieves general bot settings like greeting and instructions.
 * MODIFIED: Explicitly adds 'bot_id' and 'name' to the settings array.
 *
 * @param int $bot_id The ID of the bot post.
 * @param string $bot_name The name of the bot.
 * @param callable $get_meta_fn A function to retrieve post meta.
 * @return array Associative array of general settings.
 */
function get_general_bot_settings_logic(int $bot_id, string $bot_name, callable $get_meta_fn): array
{
    $settings = [];

    $settings['bot_id'] = $bot_id;
    $settings['name'] = $bot_name;

    $default_greeting = __('Hello there!', 'gpt3-ai-content-generator');
    $default_subgreeting = __('How can I help you today?', 'gpt3-ai-content-generator');
    $settings['greeting'] = $get_meta_fn('_aipkit_greeting_message', $default_greeting);
    $settings['subgreeting'] = $get_meta_fn('_aipkit_subgreeting_message', $default_subgreeting);

    $settings['instructions'] = $get_meta_fn('_aipkit_instructions', BotSettingsManager::DEFAULT_INSTRUCTIONS);

    return $settings;
}

// --- fn-get-ai-configuration.php ---
/**
 * Retrieves AI configuration settings like provider, model, temperature, etc.
 *
 * @param int $bot_id The ID of the bot post.
 * @param string|null $current_provider_from_main_settings The current global provider.
 * @param callable $get_meta_fn A function to retrieve post meta.
 * @return array Associative array of AI configuration settings.
 */
function get_ai_configuration_logic(int $bot_id, ?string $current_provider_from_main_settings, callable $get_meta_fn): array
{
    $settings = [];

    // Ensure dependencies are loaded for defaults
    if (!class_exists(AIPKit_Providers::class)) {
        $path = WPAICG_PLUGIN_DIR . 'classes/ai/settings.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }
    if (!class_exists(AIPKIT_AI_Settings::class)) {
        $path = WPAICG_PLUGIN_DIR . 'classes/settings/ai.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }

    $default_provider = $current_provider_from_main_settings ?: (class_exists(AIPKit_Providers::class) ? AIPKit_Providers::get_current_provider() : 'OpenAI');
    $settings['provider'] = $get_meta_fn('_aipkit_provider', $default_provider);
    if (class_exists(AIPKit_Providers::class)) {
        $settings['provider'] = AIPKit_Providers::normalize_main_provider(
            (string) $settings['provider'],
            (string) $default_provider
        );
    }
    $settings['model'] = $get_meta_fn('_aipkit_model'); // No default model here, depends on provider sync

    $global_ai_params = class_exists(AIPKIT_AI_Settings::class) ? AIPKIT_AI_Settings::get_ai_parameters() : [];
    $default_temp = BotSettingsManager::DEFAULT_TEMPERATURE;
    $default_max_tokens = BotSettingsManager::DEFAULT_MAX_COMPLETION_TOKENS;
    $default_max_messages = BotSettingsManager::DEFAULT_MAX_MESSAGES;

    $temp_val = $get_meta_fn('_aipkit_temperature', 'not_set');
    $settings['temperature'] = ($temp_val === 'not_set')
        ? floatval($global_ai_params['temperature'] ?? $default_temp)
        : floatval($temp_val);
    $settings['temperature'] = max(0.0, min($settings['temperature'], 2.0));

    $max_tokens_val = $get_meta_fn('_aipkit_max_completion_tokens', 'not_set');
    $settings['max_completion_tokens'] = ($max_tokens_val === 'not_set')
        ? absint($global_ai_params['max_completion_tokens'] ?? $default_max_tokens)
        : absint($max_tokens_val);
    $settings['max_completion_tokens'] = max(1, min($settings['max_completion_tokens'], 128000));

    $max_msgs_val = $get_meta_fn('_aipkit_max_messages', 'not_set');
    $settings['max_messages'] = ($max_msgs_val === 'not_set')
        ? $default_max_messages
        : absint($max_msgs_val);
    $settings['max_messages'] = max(1, min($settings['max_messages'], 1024));

    $settings['web_toggle_default_on'] = in_array(
        $get_meta_fn('_aipkit_web_toggle_default_on', BotSettingsManager::DEFAULT_WEB_TOGGLE_DEFAULT_ON),
        ['0', '1'],
        true
    ) ? $get_meta_fn('_aipkit_web_toggle_default_on', BotSettingsManager::DEFAULT_WEB_TOGGLE_DEFAULT_ON)
      : BotSettingsManager::DEFAULT_WEB_TOGGLE_DEFAULT_ON;
    $settings['show_sources'] = in_array(
        $get_meta_fn('_aipkit_show_sources', BotSettingsManager::DEFAULT_SHOW_SOURCES),
        ['0', '1'],
        true
    ) ? $get_meta_fn('_aipkit_show_sources', BotSettingsManager::DEFAULT_SHOW_SOURCES)
      : BotSettingsManager::DEFAULT_SHOW_SOURCES;
    $settings['sources_label'] = sanitize_text_field(
        (string) $get_meta_fn('_aipkit_sources_label', BotSettingsManager::DEFAULT_SOURCES_LABEL)
    );
    $settings['searching_web_text'] = sanitize_text_field(
        (string) $get_meta_fn('_aipkit_searching_web_text', BotSettingsManager::DEFAULT_SEARCHING_WEB_TEXT)
    );

    return $settings;
}

// --- fn-get-appearance-settings.php ---
/**
 * Retrieves appearance-related settings.
 * UPDATED: Includes custom theme settings.
 * ADDED: Logging and a defensive fix for bubble_border_radius.
 *
 * @param int $bot_id The ID of the bot post.
 * @param string $bot_name The name of the bot (for default popup icon value).
 * @param callable $get_meta_fn A function to retrieve post meta.
 * @return array Associative array of appearance settings.
 */
function get_appearance_settings_logic(int $bot_id, string $bot_name, callable $get_meta_fn): array
{
    $settings = [];
    $custom_theme_defaults = BotSettingsManager::get_custom_theme_defaults();

    $valid_themes = ['light', 'dark', 'custom', 'chatgpt'];
    $stored_theme = sanitize_key((string) $get_meta_fn('_aipkit_theme', ''));
    $has_valid_stored_theme = in_array($stored_theme, $valid_themes, true);
    $settings['theme'] = $has_valid_stored_theme
        ? $stored_theme
        : BotSettingsManager::DEFAULT_THEME;
    $preset_fallback = $has_valid_stored_theme
        ? ''
        : BotSettingsManager::DEFAULT_THEME_PRESET_KEY;
    $raw_theme_preset_key = sanitize_key((string) $get_meta_fn('_aipkit_theme_preset_key', $preset_fallback));
    $valid_theme_preset_keys = [];
    if (class_exists(BotSettingsManager::class)) {
        $custom_theme_presets = BotSettingsManager::get_custom_theme_presets();
        foreach ($custom_theme_presets as $preset) {
            if (!is_array($preset) || !isset($preset['key'])) {
                continue;
            }
            $preset_key = sanitize_key((string) $preset['key']);
            if ($preset_key !== '') {
                $valid_theme_preset_keys[$preset_key] = true;
            }
        }
    }
    $settings['theme_preset_key'] = (
        $settings['theme'] === 'custom' &&
        $raw_theme_preset_key !== '' &&
        isset($valid_theme_preset_keys[$raw_theme_preset_key])
    )
        ? $raw_theme_preset_key
        : '';
    $settings['theme_dimension_overrides'] = BotSettingsManager::normalize_theme_dimension_overrides($get_meta_fn('_aipkit_theme_dimension_overrides', ''));
    $settings['footer_text'] = $get_meta_fn('_aipkit_footer_text');
    $settings['input_placeholder'] = $get_meta_fn('_aipkit_input_placeholder', __('Type your message...', 'gpt3-ai-content-generator'));
    $header_avatar_url = $get_meta_fn('_aipkit_header_avatar_url', BotSettingsManager::DEFAULT_HEADER_AVATAR_URL);
    $header_avatar_type = $get_meta_fn('_aipkit_header_avatar_type', BotSettingsManager::DEFAULT_HEADER_AVATAR_TYPE);
    $header_avatar_value = $get_meta_fn('_aipkit_header_avatar_value', BotSettingsManager::DEFAULT_HEADER_AVATAR_VALUE);
    if (!in_array($header_avatar_type, ['inherit', 'default', 'custom'], true)) {
        $header_avatar_type = $header_avatar_url !== '' ? 'custom' : BotSettingsManager::DEFAULT_HEADER_AVATAR_TYPE;
    }
    if ($header_avatar_type === 'custom') {
        if ($header_avatar_url === '' && !empty($header_avatar_value)) {
            $header_avatar_url = $header_avatar_value;
        }
        $header_avatar_value = $header_avatar_url;
    } elseif ($header_avatar_type === 'default') {
        $allowed_header_icons = ['chat-bubble', 'spark', 'openai', 'plus', 'question-mark'];
        if (!in_array($header_avatar_value, $allowed_header_icons, true)) {
            $header_avatar_value = BotSettingsManager::DEFAULT_HEADER_AVATAR_VALUE;
        }
        $header_avatar_url = '';
    } else {
        $header_avatar_value = BotSettingsManager::DEFAULT_HEADER_AVATAR_VALUE;
        $header_avatar_url = '';
    }
    $settings['header_avatar_type'] = $header_avatar_type;
    $settings['header_avatar_value'] = $header_avatar_value;
    $settings['header_avatar_url'] = ($header_avatar_type === 'custom') ? $header_avatar_url : '';
    $settings['header_online_text'] = $get_meta_fn('_aipkit_header_online_text', __('Online', 'gpt3-ai-content-generator'));
    $settings['enable_fullscreen'] = in_array($get_meta_fn('_aipkit_enable_fullscreen', BotSettingsManager::DEFAULT_ENABLE_FULLSCREEN), ['0','1'])
        ? $get_meta_fn('_aipkit_enable_fullscreen', BotSettingsManager::DEFAULT_ENABLE_FULLSCREEN)
        : BotSettingsManager::DEFAULT_ENABLE_FULLSCREEN;
    $settings['enable_download'] = in_array($get_meta_fn('_aipkit_enable_download', BotSettingsManager::DEFAULT_ENABLE_DOWNLOAD), ['0','1'])
        ? $get_meta_fn('_aipkit_enable_download', BotSettingsManager::DEFAULT_ENABLE_DOWNLOAD)
        : BotSettingsManager::DEFAULT_ENABLE_DOWNLOAD;
    $settings['enable_copy_button'] = '1';
    $settings['enable_feedback'] = '1';
    $settings['enable_consent_compliance'] = in_array($get_meta_fn('_aipkit_enable_consent_compliance', BotSettingsManager::DEFAULT_ENABLE_CONSENT_COMPLIANCE), ['0','1'])
        ? $get_meta_fn('_aipkit_enable_consent_compliance', BotSettingsManager::DEFAULT_ENABLE_CONSENT_COMPLIANCE)
        : BotSettingsManager::DEFAULT_ENABLE_CONSENT_COMPLIANCE;
    $settings['consent_title'] = $get_meta_fn(
        '_aipkit_consent_title',
        __('Consent Required', 'gpt3-ai-content-generator')
    );
    $settings['consent_message'] = $get_meta_fn(
        '_aipkit_consent_message',
        __('Before starting the conversation, please agree to our Terms of Service and Privacy Policy.', 'gpt3-ai-content-generator')
    );
    $settings['consent_button'] = $get_meta_fn(
        '_aipkit_consent_button',
        __('I Agree', 'gpt3-ai-content-generator')
    );
    $settings['enable_conversation_sidebar'] = in_array($get_meta_fn('_aipkit_enable_conversation_sidebar', BotSettingsManager::DEFAULT_ENABLE_CONVERSATION_SIDEBAR), ['0','1'])
        ? $get_meta_fn('_aipkit_enable_conversation_sidebar', BotSettingsManager::DEFAULT_ENABLE_CONVERSATION_SIDEBAR)
        : BotSettingsManager::DEFAULT_ENABLE_CONVERSATION_SIDEBAR;

    // Typing indicator customization
    $settings['custom_typing_text'] = $get_meta_fn('_aipkit_custom_typing_text', BotSettingsManager::DEFAULT_CUSTOM_TYPING_TEXT);
    $settings['retrieving_context_text'] = $get_meta_fn('_aipkit_retrieving_context_text', BotSettingsManager::DEFAULT_RETRIEVING_CONTEXT_TEXT);

    // Popup settings
    $settings['popup_enabled'] = in_array($get_meta_fn('_aipkit_popup_enabled', BotSettingsManager::DEFAULT_POPUP_ENABLED), ['0','1'])
        ? $get_meta_fn('_aipkit_popup_enabled', BotSettingsManager::DEFAULT_POPUP_ENABLED)
        : BotSettingsManager::DEFAULT_POPUP_ENABLED;
    $settings['popup_position'] = in_array($get_meta_fn('_aipkit_popup_position', 'bottom-right'), ['bottom-right','bottom-left','top-right','top-left'])
        ? $get_meta_fn('_aipkit_popup_position', 'bottom-right')
        : 'bottom-right';
    $settings['popup_delay'] = absint($get_meta_fn('_aipkit_popup_delay', BotSettingsManager::DEFAULT_POPUP_DELAY));
    $settings['site_wide_enabled'] = in_array($get_meta_fn('_aipkit_site_wide_enabled', BotSettingsManager::DEFAULT_SITE_WIDE_ENABLED), ['0','1'])
        ? $get_meta_fn('_aipkit_site_wide_enabled', BotSettingsManager::DEFAULT_SITE_WIDE_ENABLED)
        : BotSettingsManager::DEFAULT_SITE_WIDE_ENABLED;
    $allowed_icon_sizes = ['small','medium','large','xlarge'];
    $icon_size_meta = $get_meta_fn('_aipkit_popup_icon_size', BotSettingsManager::DEFAULT_POPUP_ICON_SIZE);
    $settings['popup_icon_size'] = in_array($icon_size_meta, $allowed_icon_sizes, true) ? $icon_size_meta : BotSettingsManager::DEFAULT_POPUP_ICON_SIZE;
    $settings['popup_icon_style'] = $get_meta_fn('_aipkit_popup_icon_style', BotSettingsManager::DEFAULT_POPUP_ICON_STYLE);
    if (!in_array($settings['popup_icon_style'], ['circle', 'square', 'none'])) {
        $settings['popup_icon_style'] = BotSettingsManager::DEFAULT_POPUP_ICON_STYLE;
    }
    $settings['popup_icon_type'] = $get_meta_fn('_aipkit_popup_icon_type', BotSettingsManager::DEFAULT_POPUP_ICON_TYPE);
    $settings['popup_icon_value'] = $get_meta_fn('_aipkit_popup_icon_value', BotSettingsManager::DEFAULT_POPUP_ICON_VALUE);
    if (!in_array($settings['popup_icon_type'], ['default', 'custom'])) {
        $settings['popup_icon_type'] = BotSettingsManager::DEFAULT_POPUP_ICON_TYPE;
    }
    if ($settings['popup_icon_type'] === 'default' && !in_array($settings['popup_icon_value'], ['chat-bubble', 'spark', 'openai', 'plus', 'question-mark'])) {
        $settings['popup_icon_value'] = BotSettingsManager::DEFAULT_POPUP_ICON_VALUE;
    }
    if ($settings['popup_icon_type'] === 'custom' && empty($settings['popup_icon_value'])) {
        $settings['popup_icon_value'] = '';
    }

    $settings['popup_label_enabled'] = in_array($get_meta_fn('_aipkit_popup_label_enabled', BotSettingsManager::DEFAULT_POPUP_LABEL_ENABLED), ['0','1'])
        ? $get_meta_fn('_aipkit_popup_label_enabled', BotSettingsManager::DEFAULT_POPUP_LABEL_ENABLED)
        : BotSettingsManager::DEFAULT_POPUP_LABEL_ENABLED;
    $popup_label_text = trim((string) $get_meta_fn('_aipkit_popup_label_text', BotSettingsManager::DEFAULT_POPUP_LABEL_TEXT));
    if ($popup_label_text === '') {
        $popup_label_text = BotSettingsManager::DEFAULT_POPUP_LABEL_TEXT;
    }
    $settings['popup_label_text'] = $popup_label_text;
    $allowed_modes = ['always','on_delay','until_open','until_dismissed'];
    $raw_label_mode = $get_meta_fn('_aipkit_popup_label_mode', BotSettingsManager::DEFAULT_POPUP_LABEL_MODE);
    $legacy_mode_map = [
        'delay_once' => 'on_delay',
        'delay_always' => 'on_delay',
        'immediate_once' => 'always',
        'immediate_always' => 'always',
        'manual' => 'until_dismissed',
    ];
    $legacy_frequency_map = [
        'delay_once' => 'once_per_visitor',
        'delay_always' => 'always',
        'immediate_once' => 'once_per_visitor',
        'immediate_always' => 'always',
    ];
    $label_mode = $legacy_mode_map[$raw_label_mode] ?? $raw_label_mode;
    $settings['popup_label_mode'] = in_array($label_mode, $allowed_modes, true) ? $label_mode : BotSettingsManager::DEFAULT_POPUP_LABEL_MODE;
    $settings['popup_label_delay_seconds'] = absint($get_meta_fn('_aipkit_popup_label_delay_seconds', BotSettingsManager::DEFAULT_POPUP_LABEL_DELAY_SECONDS));
    $settings['popup_label_auto_hide_seconds'] = absint($get_meta_fn('_aipkit_popup_label_auto_hide_seconds', BotSettingsManager::DEFAULT_POPUP_LABEL_AUTO_HIDE_SECONDS));
    $settings['popup_label_dismissible'] = in_array($get_meta_fn('_aipkit_popup_label_dismissible', BotSettingsManager::DEFAULT_POPUP_LABEL_DISMISSIBLE), ['0','1'])
        ? $get_meta_fn('_aipkit_popup_label_dismissible', BotSettingsManager::DEFAULT_POPUP_LABEL_DISMISSIBLE)
        : BotSettingsManager::DEFAULT_POPUP_LABEL_DISMISSIBLE;
    $allowed_freq = ['always','once_per_session','once_per_visitor'];
    $label_freq = $get_meta_fn('_aipkit_popup_label_frequency', BotSettingsManager::DEFAULT_POPUP_LABEL_FREQUENCY);
    if (!in_array($label_freq, $allowed_freq, true) && isset($legacy_frequency_map[$raw_label_mode])) {
        $label_freq = $legacy_frequency_map[$raw_label_mode];
    }
    $settings['popup_label_frequency'] = in_array($label_freq, $allowed_freq, true) ? $label_freq : BotSettingsManager::DEFAULT_POPUP_LABEL_FREQUENCY;
    $settings['popup_label_show_on_mobile'] = in_array($get_meta_fn('_aipkit_popup_label_show_on_mobile', BotSettingsManager::DEFAULT_POPUP_LABEL_SHOW_ON_MOBILE), ['0','1'])
        ? $get_meta_fn('_aipkit_popup_label_show_on_mobile', BotSettingsManager::DEFAULT_POPUP_LABEL_SHOW_ON_MOBILE)
        : BotSettingsManager::DEFAULT_POPUP_LABEL_SHOW_ON_MOBILE;
    $settings['popup_label_show_on_desktop'] = in_array($get_meta_fn('_aipkit_popup_label_show_on_desktop', BotSettingsManager::DEFAULT_POPUP_LABEL_SHOW_ON_DESKTOP), ['0','1'])
        ? $get_meta_fn('_aipkit_popup_label_show_on_desktop', BotSettingsManager::DEFAULT_POPUP_LABEL_SHOW_ON_DESKTOP)
        : BotSettingsManager::DEFAULT_POPUP_LABEL_SHOW_ON_DESKTOP;
    $settings['popup_label_version'] = $get_meta_fn('_aipkit_popup_label_version', BotSettingsManager::DEFAULT_POPUP_LABEL_VERSION);
    $allowed_sizes = ['small','medium','large','xlarge'];
    $label_size = $get_meta_fn('_aipkit_popup_label_size', BotSettingsManager::DEFAULT_POPUP_LABEL_SIZE);
    $settings['popup_label_size'] = in_array($label_size, $allowed_sizes, true) ? $label_size : BotSettingsManager::DEFAULT_POPUP_LABEL_SIZE;

    // --- Retrieve Custom Theme Settings ---
    $custom_theme_settings_retrieved = [];
    foreach (array_keys($custom_theme_defaults) as $key) {
        if (strpos($key, '_placeholder') !== false) {
            continue;
        }
        $meta_key_name = '_aipkit_cts_' . $key;
        $value_from_meta = $get_meta_fn($meta_key_name);

        if ($value_from_meta === '' || $value_from_meta === null) {
            $custom_theme_settings_retrieved[$key] = $custom_theme_defaults[$key];
        } else {
            // Specific handling for numeric dimension settings
            if (in_array($key, [
                'bubble_border_radius', 'container_max_width', 'popup_width',
                'container_height', 'container_min_height',
                'popup_height', 'popup_min_height'
            ], true)) {
                $custom_theme_settings_retrieved[$key] = is_numeric($value_from_meta) ? max(0, absint($value_from_meta)) : $custom_theme_defaults[$key];
            } elseif (in_array($key, ['container_max_height', 'popup_max_height'], true)) {
                $custom_theme_settings_retrieved[$key] = is_numeric($value_from_meta) ? max(1, min(absint($value_from_meta), 100)) : $custom_theme_defaults[$key];
            } else {
                $custom_theme_settings_retrieved[$key] = $value_from_meta;
            }
        }
    }
    $settings['custom_theme_settings'] = $custom_theme_settings_retrieved;
    // --- END Retrieve Custom Theme Settings ---

    return $settings;
}

// --- fn-get-conversation-starters.php ---
/**
 * Retrieves conversation starters settings.
 *
 * @param int $bot_id The ID of the bot post.
 * @param callable $get_meta_fn A function to retrieve post meta.
 * @return array Associative array of conversation starters settings.
 */
function get_conversation_starters_logic(int $bot_id, callable $get_meta_fn): array
{
    $settings = [];


    $default_enable_starters = BotSettingsManager::DEFAULT_ENABLE_CONVERSATION_STARTERS;
    $settings['enable_conversation_starters'] = in_array($get_meta_fn('_aipkit_enable_conversation_starters', $default_enable_starters), ['0','1'])
        ? $get_meta_fn('_aipkit_enable_conversation_starters', $default_enable_starters)
        : $default_enable_starters;

    $default_starters_json = method_exists(BotSettingsManager::class, 'get_default_conversation_starters_json')
        ? BotSettingsManager::get_default_conversation_starters_json()
        : '[]';
    $starters_json = $get_meta_fn('_aipkit_conversation_starters', $default_starters_json);
    $starters_array = json_decode($starters_json, true);
    $settings['conversation_starters'] = is_array($starters_array) ? $starters_array : [];

    return $settings;
}

// --- fn-get-contextual-settings.php ---
/**
 * Retrieves contextual settings like content_aware, file_upload, image_upload,
 * image_triggers, and chat_image_model_id.
 *
 * @param int $bot_id The ID of the bot post.
 * @param callable $get_meta_fn A function to retrieve post meta.
 * @return array Associative array of contextual settings.
 */
function get_contextual_settings_logic(int $bot_id, callable $get_meta_fn): array
{
    $settings = [];


    $settings['content_aware_enabled'] = in_array($get_meta_fn('_aipkit_content_aware_enabled', BotSettingsManager::DEFAULT_CONTENT_AWARE_ENABLED), ['0','1'])
        ? $get_meta_fn('_aipkit_content_aware_enabled', BotSettingsManager::DEFAULT_CONTENT_AWARE_ENABLED)
        : BotSettingsManager::DEFAULT_CONTENT_AWARE_ENABLED;

    $settings['enable_file_upload'] = in_array($get_meta_fn('_aipkit_enable_file_upload', BotSettingsManager::DEFAULT_ENABLE_FILE_UPLOAD), ['0','1'])
        ? $get_meta_fn('_aipkit_enable_file_upload', BotSettingsManager::DEFAULT_ENABLE_FILE_UPLOAD)
        : BotSettingsManager::DEFAULT_ENABLE_FILE_UPLOAD;

    $settings['enable_image_upload'] = in_array($get_meta_fn('_aipkit_enable_image_upload', BotSettingsManager::DEFAULT_ENABLE_IMAGE_UPLOAD), ['0','1'])
        ? $get_meta_fn('_aipkit_enable_image_upload', BotSettingsManager::DEFAULT_ENABLE_IMAGE_UPLOAD)
        : BotSettingsManager::DEFAULT_ENABLE_IMAGE_UPLOAD;

    $settings['image_triggers'] = $get_meta_fn('_aipkit_image_triggers', BotSettingsManager::DEFAULT_IMAGE_TRIGGERS);
    if (empty($settings['image_triggers'])) {
        $settings['image_triggers'] = BotSettingsManager::DEFAULT_IMAGE_TRIGGERS;
    }

    $settings['chat_image_model_id'] = $get_meta_fn('_aipkit_chat_image_model_id', BotSettingsManager::get_default_model_id('OpenAIImage'));
    $saved_image_model = strtolower((string) $settings['chat_image_model_id']);
    if (
        class_exists(AIPKit_Providers::class)
        && (
            strpos($saved_image_model, 'imagen-') === 0
            || (strpos($saved_image_model, 'gemini-') === 0 && strpos($saved_image_model, 'image') !== false)
        )
    ) {
        $settings['chat_image_model_id'] = AIPKit_Providers::normalize_google_image_model(
            (string) $settings['chat_image_model_id']
        );
    }
    $settings['enable_image_generation'] = in_array($get_meta_fn('_aipkit_enable_image_generation', BotSettingsManager::DEFAULT_ENABLE_IMAGE_GENERATION), ['0','1'], true)
        ? $get_meta_fn('_aipkit_enable_image_generation', BotSettingsManager::DEFAULT_ENABLE_IMAGE_GENERATION)
        : BotSettingsManager::DEFAULT_ENABLE_IMAGE_GENERATION;
    return $settings;
}

// --- fn-get-vector-store-config.php ---
/**
 * Retrieves vector store configuration settings.
 *
 * @param int $bot_id The ID of the bot post.
 * @param callable $get_meta_fn A function to retrieve post meta.
 * @return array Associative array of vector store settings.
 */
function get_vector_store_config_logic(int $bot_id, callable $get_meta_fn): array
{
    $settings = [];


    $settings['enable_vector_store'] = in_array($get_meta_fn('_aipkit_enable_vector_store', BotSettingsManager::DEFAULT_ENABLE_VECTOR_STORE), ['0','1'])
        ? $get_meta_fn('_aipkit_enable_vector_store', BotSettingsManager::DEFAULT_ENABLE_VECTOR_STORE)
        : BotSettingsManager::DEFAULT_ENABLE_VECTOR_STORE;

    $settings['vector_store_provider'] = $get_meta_fn('_aipkit_vector_store_provider', BotSettingsManager::DEFAULT_VECTOR_STORE_PROVIDER);
    if (!in_array($settings['vector_store_provider'], ['openai', 'pinecone', 'qdrant', 'chroma', 'local', 'claude_files', 'google'], true)) {
        $settings['vector_store_provider'] = BotSettingsManager::DEFAULT_VECTOR_STORE_PROVIDER;
    }
    $chat_provider = sanitize_text_field((string) $get_meta_fn('_aipkit_provider', 'OpenAI'));
    if (strtolower($chat_provider) !== 'claude' && $settings['vector_store_provider'] === 'claude_files') {
        $settings['vector_store_provider'] = BotSettingsManager::DEFAULT_VECTOR_STORE_PROVIDER;
    }

    $openai_vs_ids_json = $get_meta_fn('_aipkit_openai_vector_store_ids', '[]');
    $openai_vs_ids_array = json_decode($openai_vs_ids_json, true);
    if (!is_array($openai_vs_ids_array)) {
        $openai_vs_ids_array = [];
    }
    $settings['openai_vector_store_ids'] = $openai_vs_ids_array;

    $google_store_names_json = $get_meta_fn('_aipkit_google_file_search_store_names', '[]');
    $google_store_names = json_decode($google_store_names_json, true);
    $settings['google_file_search_store_names'] = is_array($google_store_names)
        ? array_values(array_filter(array_map('sanitize_text_field', $google_store_names)))
        : [];

    // Delete old singular OpenAI store ID meta if it exists
    if (get_post_meta($bot_id, '_aipkit_openai_vector_store_id', true) !== false) {
        delete_post_meta($bot_id, '_aipkit_openai_vector_store_id');
    }

    $settings['pinecone_index_name'] = $get_meta_fn('_aipkit_pinecone_index_name', BotSettingsManager::DEFAULT_PINECONE_INDEX_NAME);
    $settings['qdrant_collection_name'] = $get_meta_fn('_aipkit_qdrant_collection_name', BotSettingsManager::DEFAULT_QDRANT_COLLECTION_NAME);
    $qdrant_names_json = $get_meta_fn('_aipkit_qdrant_collection_names', '[]');
    $qdrant_names_array = json_decode($qdrant_names_json, true);
    if (!is_array($qdrant_names_array)) { $qdrant_names_array = []; }
    if (empty($qdrant_names_array) && !empty($settings['qdrant_collection_name'])) {
        $qdrant_names_array = [$settings['qdrant_collection_name']];
    }
    $settings['qdrant_collection_names'] = $qdrant_names_array;

    $settings['chroma_collection_name'] = $get_meta_fn('_aipkit_chroma_collection_name', '');
    // Local knowledge stores; embedding provider/model are configured separately.
    $local_store_ids = json_decode((string) $get_meta_fn('_aipkit_local_store_ids', '[]'), true);
    $settings['local_store_ids'] = is_array($local_store_ids) ? array_values(array_filter(array_map('sanitize_key', $local_store_ids))) : [];

    $chroma_names_json = $get_meta_fn('_aipkit_chroma_collection_names', '[]');
    $chroma_names_array = json_decode($chroma_names_json, true);
    if (!is_array($chroma_names_array)) { $chroma_names_array = []; }
    if (empty($chroma_names_array) && !empty($settings['chroma_collection_name'])) {
        $chroma_names_array = [$settings['chroma_collection_name']];
    }
    $settings['chroma_collection_names'] = $chroma_names_array;

    $allowed_embedding_provider_keys = AIPKit_Providers::get_embedding_provider_keys('chat_vector_store_getter');

    $settings['vector_embedding_provider'] = $get_meta_fn('_aipkit_vector_embedding_provider', BotSettingsManager::DEFAULT_VECTOR_EMBEDDING_PROVIDER);
    if ($settings['vector_embedding_provider'] !== '' && $settings['vector_embedding_provider'] !== 'aipuffercloud' && !in_array($settings['vector_embedding_provider'], $allowed_embedding_provider_keys, true)) {
        $settings['vector_embedding_provider'] = BotSettingsManager::DEFAULT_VECTOR_EMBEDDING_PROVIDER;
    }
    $settings['vector_embedding_model'] = $get_meta_fn('_aipkit_vector_embedding_model', BotSettingsManager::get_default_model_id('OpenAIEmbedding'));

    $top_k_val = $get_meta_fn('_aipkit_vector_store_top_k', BotSettingsManager::DEFAULT_VECTOR_STORE_TOP_K);
    $settings['vector_store_top_k'] = max(1, min(absint($top_k_val), 20));

    $threshold_val = $get_meta_fn('_aipkit_vector_store_confidence_threshold', BotSettingsManager::DEFAULT_VECTOR_STORE_CONFIDENCE_THRESHOLD);
    $settings['vector_store_confidence_threshold'] = max(0, min(absint($threshold_val), 100));
    // END NEW

    return $settings;
}

// --- fn-get-tts-config.php ---
/**
 * Retrieves Text-to-Speech (TTS) configuration settings.
 *
 * @param int $bot_id The ID of the bot post.
 * @param callable $get_meta_fn A function to retrieve post meta.
 * @return array Associative array of TTS settings.
 */
function get_tts_config_logic(int $bot_id, callable $get_meta_fn): array
{
    $settings = [];


    $settings['tts_enabled'] = in_array($get_meta_fn('_aipkit_tts_enabled', BotSettingsManager::DEFAULT_TTS_ENABLED), ['0','1'])
        ? $get_meta_fn('_aipkit_tts_enabled', BotSettingsManager::DEFAULT_TTS_ENABLED)
        : BotSettingsManager::DEFAULT_TTS_ENABLED;

    $settings['tts_provider'] = $get_meta_fn('_aipkit_tts_provider', BotSettingsManager::DEFAULT_TTS_PROVIDER);
    if (!in_array($settings['tts_provider'], ['', 'Google', 'OpenAI', 'ElevenLabs', 'AIPufferCloud'])) {
        $settings['tts_provider'] = BotSettingsManager::DEFAULT_TTS_PROVIDER;
    }

    $settings['tts_google_voice_id'] = AIPKit_Providers::normalize_google_tts_voice(
        $get_meta_fn('_aipkit_tts_google_voice_id', '')
    );
    $settings['tts_google_model_id'] = AIPKit_Providers::normalize_google_tts_model(
        $get_meta_fn('_aipkit_tts_google_model_id', '')
    );
    $settings['tts_openai_voice_id'] = $get_meta_fn('_aipkit_tts_openai_voice_id', BotSettingsManager::get_default_model_id('OpenAIVoices'));
    $settings['tts_openai_model_id'] = $get_meta_fn('_aipkit_tts_openai_model_id', BotSettingsManager::get_default_model_id('OpenAITTS'));
    $settings['tts_cloud_voice_id'] = $get_meta_fn('_aipkit_tts_cloud_voice_id', 'alloy');
    $settings['tts_cloud_model_id'] = $get_meta_fn('_aipkit_tts_cloud_model_id', \WPAICG\Cloud\Connection::media_models('speech_generate')[0]['id'] ?? '');
    $settings['tts_elevenlabs_voice_id'] = $get_meta_fn('_aipkit_tts_elevenlabs_voice_id', '');
    $settings['tts_elevenlabs_model_id'] = $get_meta_fn('_aipkit_tts_elevenlabs_model_id', BotSettingsManager::get_default_model_id('ElevenLabsModels'));

    $settings['tts_voice_id'] = ''; // Determine combined voice ID based on provider
    switch ($settings['tts_provider']) {
        case 'Google': $settings['tts_voice_id'] = $settings['tts_google_voice_id'];
            break;
        case 'OpenAI': $settings['tts_voice_id'] = $settings['tts_openai_voice_id'];
            break;
        case 'ElevenLabs': $settings['tts_voice_id'] = $settings['tts_elevenlabs_voice_id'];
            break;
        case 'AIPufferCloud': $settings['tts_voice_id'] = $settings['tts_cloud_voice_id'];
            break;
    }

    $settings['tts_auto_play'] = in_array($get_meta_fn('_aipkit_tts_auto_play', BotSettingsManager::DEFAULT_TTS_AUTO_PLAY), ['0','1'])
        ? $get_meta_fn('_aipkit_tts_auto_play', BotSettingsManager::DEFAULT_TTS_AUTO_PLAY)
        : BotSettingsManager::DEFAULT_TTS_AUTO_PLAY;

    return $settings;
}

// --- fn-get-stt-config.php ---
/**
 * Retrieves Speech-to-Text (STT) configuration settings.
 *
 * @param int $bot_id The ID of the bot post.
 * @param callable $get_meta_fn A function to retrieve post meta.
 * @return array Associative array of STT settings.
 */
function get_stt_config_logic(int $bot_id, callable $get_meta_fn): array
{
    $settings = [];


    $settings['enable_voice_input'] = in_array($get_meta_fn('_aipkit_enable_voice_input', BotSettingsManager::DEFAULT_ENABLE_VOICE_INPUT), ['0','1'])
        ? $get_meta_fn('_aipkit_enable_voice_input', BotSettingsManager::DEFAULT_ENABLE_VOICE_INPUT)
        : BotSettingsManager::DEFAULT_ENABLE_VOICE_INPUT;

    $settings['stt_provider'] = $get_meta_fn('_aipkit_stt_provider', BotSettingsManager::DEFAULT_STT_PROVIDER);
    if (!in_array($settings['stt_provider'], ['', 'OpenAI', 'Google', 'Azure', 'AIPufferCloud'], true)) {
        $settings['stt_provider'] = BotSettingsManager::DEFAULT_STT_PROVIDER;
    }

    $settings['stt_openai_model_id'] = AIPKit_Model_Catalog::sanitize_openai_file_transcription_model(
        (string) $get_meta_fn('_aipkit_stt_openai_model_id', BotSettingsManager::get_default_model_id('OpenAISTT'))
    );
    $settings['stt_google_model_id'] = \WPAICG\AIPKit_Providers::normalize_google_stt_model(
        (string) $get_meta_fn('_aipkit_stt_google_model_id', '')
    );
    $settings['stt_cloud_model_id'] = $get_meta_fn('_aipkit_stt_cloud_model_id', \WPAICG\Cloud\Connection::media_models('transcribe')[0]['id'] ?? '');
    $settings['stt_azure_model_id'] = $get_meta_fn('_aipkit_stt_azure_model_id', BotSettingsManager::DEFAULT_STT_AZURE_MODEL_ID);

    return $settings;
}

// --- fn-get-token-management-config.php ---
/**
 * Retrieves token management configuration settings.
 *
 * @param int $bot_id The ID of the bot post.
 * @param callable $get_meta_fn A function to retrieve post meta.
 * @return array Associative array of token management settings.
 */
function get_token_management_config_logic(int $bot_id, callable $get_meta_fn): array
{
    $settings = [];


    $settings['token_limit_mode'] = $get_meta_fn('_aipkit_token_limit_mode', BotSettingsManager::DEFAULT_TOKEN_LIMIT_MODE);
    if (!in_array($settings['token_limit_mode'], ['general', 'role_based'])) {
        $settings['token_limit_mode'] = BotSettingsManager::DEFAULT_TOKEN_LIMIT_MODE;
    }

    $guest_limit_raw = $get_meta_fn('_aipkit_token_guest_limit', BotSettingsManager::DEFAULT_TOKEN_GUEST_LIMIT);
    $settings['token_guest_limit'] = ($guest_limit_raw === '') ? null : (($guest_limit_raw === '0') ? 0 : absint($guest_limit_raw));

    $user_limit_raw = $get_meta_fn('_aipkit_token_user_limit', BotSettingsManager::DEFAULT_TOKEN_USER_LIMIT);
    $settings['token_user_limit'] = ($user_limit_raw === '') ? null : (($user_limit_raw === '0') ? 0 : absint($user_limit_raw));

    $role_limits_json = $get_meta_fn('_aipkit_token_role_limits', '[]');
    $decoded_roles = json_decode($role_limits_json, true);
    $settings['token_role_limits'] = is_array($decoded_roles) ? $decoded_roles : [];

    $settings['token_reset_period'] = $get_meta_fn('_aipkit_token_reset_period', BotSettingsManager::DEFAULT_TOKEN_RESET_PERIOD);
    if (!in_array($settings['token_reset_period'], ['never', 'daily', 'weekly', 'monthly'])) {
        $settings['token_reset_period'] = BotSettingsManager::DEFAULT_TOKEN_RESET_PERIOD;
    }

    $default_limit_message = class_exists(BotSettingsManager::class)
        ? BotSettingsManager::get_default_token_limit_message()
        : __('You have reached your quota for this period.', 'gpt3-ai-content-generator');
    $settings['token_limit_message'] = $get_meta_fn('_aipkit_token_limit_message', $default_limit_message);
    if (empty($settings['token_limit_message'])) {
        $settings['token_limit_message'] = $default_limit_message;
    }

    $valid_action_types = class_exists(BotSettingsManager::class)
        ? BotSettingsManager::get_token_limit_action_types()
        : ['none', 'dashboard_usage', 'dashboard_credits', 'dashboard_purchases', 'buy_credits', 'custom_url'];
    $default_action_settings = class_exists(BotSettingsManager::class)
        ? BotSettingsManager::get_default_token_limit_action_settings()
        : [
            'primary_type' => 'none',
            'primary_label' => '',
            'primary_url' => '',
            'secondary_type' => 'none',
            'secondary_label' => '',
            'secondary_url' => '',
        ];

    $settings['token_limit_primary_action_type'] = (string) $get_meta_fn(
        '_aipkit_token_limit_primary_action_type',
        $default_action_settings['primary_type']
    );
    if (!in_array($settings['token_limit_primary_action_type'], $valid_action_types, true)) {
        $settings['token_limit_primary_action_type'] = $default_action_settings['primary_type'];
    }
    $settings['token_limit_primary_action_label'] = trim((string) $get_meta_fn(
        '_aipkit_token_limit_primary_action_label',
        $default_action_settings['primary_label']
    ));
    if ($settings['token_limit_primary_action_type'] === 'none') {
        $settings['token_limit_primary_action_label'] = '';
    } elseif ($settings['token_limit_primary_action_label'] === '') {
        $settings['token_limit_primary_action_label'] = class_exists(BotSettingsManager::class)
            ? BotSettingsManager::get_token_limit_action_default_label($settings['token_limit_primary_action_type'])
            : $default_action_settings['primary_label'];
    }
    $settings['token_limit_primary_action_url'] = esc_url_raw((string) $get_meta_fn(
        '_aipkit_token_limit_primary_action_url',
        $default_action_settings['primary_url']
    ));

    $settings['token_limit_secondary_action_type'] = (string) $get_meta_fn(
        '_aipkit_token_limit_secondary_action_type',
        $default_action_settings['secondary_type']
    );
    if (!in_array($settings['token_limit_secondary_action_type'], $valid_action_types, true)) {
        $settings['token_limit_secondary_action_type'] = $default_action_settings['secondary_type'];
    }
    $settings['token_limit_secondary_action_label'] = trim((string) $get_meta_fn(
        '_aipkit_token_limit_secondary_action_label',
        $default_action_settings['secondary_label']
    ));
    if ($settings['token_limit_secondary_action_type'] === 'none') {
        $settings['token_limit_secondary_action_label'] = '';
    } elseif ($settings['token_limit_secondary_action_label'] === '') {
        $settings['token_limit_secondary_action_label'] = class_exists(BotSettingsManager::class)
            ? BotSettingsManager::get_token_limit_action_default_label($settings['token_limit_secondary_action_type'])
            : $default_action_settings['secondary_label'];
    }
    $settings['token_limit_secondary_action_url'] = esc_url_raw((string) $get_meta_fn(
        '_aipkit_token_limit_secondary_action_url',
        $default_action_settings['secondary_url']
    ));

    return $settings;
}

// --- fn-get-openai-specific-config.php ---
/**
 * Retrieves OpenAI-specific configuration settings.
 *
 * @param int $bot_id The ID of the bot post.
 * @param callable $get_meta_fn A function to retrieve post meta.
 * @return array Associative array of OpenAI-specific settings.
 */
function get_openai_specific_config_logic(int $bot_id, callable $get_meta_fn): array
{
    $settings = [];


    $settings['openai_conversation_state_enabled'] = in_array(
        $get_meta_fn('_aipkit_openai_conversation_state_enabled', BotSettingsManager::DEFAULT_OPENAI_CONVERSATION_STATE_ENABLED),
        ['0', '1']
    ) ? $get_meta_fn('_aipkit_openai_conversation_state_enabled', BotSettingsManager::DEFAULT_OPENAI_CONVERSATION_STATE_ENABLED)
      : BotSettingsManager::DEFAULT_OPENAI_CONVERSATION_STATE_ENABLED;

    // OpenAI Web Search Settings
    $settings['openai_web_search_enabled'] = in_array(
        $get_meta_fn('_aipkit_openai_web_search_enabled', BotSettingsManager::DEFAULT_OPENAI_WEB_SEARCH_ENABLED),
        ['0', '1']
    ) ? $get_meta_fn('_aipkit_openai_web_search_enabled', BotSettingsManager::DEFAULT_OPENAI_WEB_SEARCH_ENABLED)
      : BotSettingsManager::DEFAULT_OPENAI_WEB_SEARCH_ENABLED;

    $settings['openai_web_search_context_size'] = $get_meta_fn('_aipkit_openai_web_search_context_size', BotSettingsManager::DEFAULT_OPENAI_WEB_SEARCH_CONTEXT_SIZE);
    if (!in_array($settings['openai_web_search_context_size'], ['low', 'medium', 'high'])) {
        $settings['openai_web_search_context_size'] = BotSettingsManager::DEFAULT_OPENAI_WEB_SEARCH_CONTEXT_SIZE;
    }

    $settings['openai_web_search_loc_type'] = $get_meta_fn('_aipkit_openai_web_search_loc_type', BotSettingsManager::DEFAULT_OPENAI_WEB_SEARCH_LOC_TYPE);
    if (!in_array($settings['openai_web_search_loc_type'], ['none', 'approximate'])) {
        $settings['openai_web_search_loc_type'] = BotSettingsManager::DEFAULT_OPENAI_WEB_SEARCH_LOC_TYPE;
    }

    $settings['openai_web_search_loc_country'] = $get_meta_fn('_aipkit_openai_web_search_loc_country', '');
    $settings['openai_web_search_loc_city'] = $get_meta_fn('_aipkit_openai_web_search_loc_city', '');
    $settings['openai_web_search_loc_region'] = $get_meta_fn('_aipkit_openai_web_search_loc_region', '');
    $settings['openai_web_search_loc_timezone'] = $get_meta_fn('_aipkit_openai_web_search_loc_timezone', '');

    // Reasoning Effort Setting
    $default_reasoning_effort = defined('WPAICG\Chat\Storage\BotSettingsManager::DEFAULT_REASONING_EFFORT') ? BotSettingsManager::DEFAULT_REASONING_EFFORT : 'none';
    $settings['reasoning_effort'] = $get_meta_fn('_aipkit_reasoning_effort', $default_reasoning_effort);
    $reasoning_effort = AIPKit_OpenAI_Reasoning::sanitize_effort($settings['reasoning_effort']);
    $reasoning_model = (string) $get_meta_fn('_aipkit_model', '');
    $reasoning_provider = (string) $get_meta_fn('_aipkit_provider', 'OpenAI');
    if ($reasoning_provider === 'OpenAI' && AIPKit_OpenAI_Reasoning::is_gpt_6_astra($reasoning_model)) {
        $reasoning_effort = AIPKit_OpenAI_Reasoning::normalize_effort_for_model(
            $reasoning_model,
            $reasoning_effort !== '' ? $reasoning_effort : $default_reasoning_effort
        );
    } elseif ($reasoning_effort === 'max') {
        $reasoning_effort = $reasoning_provider === 'OpenAI'
            ? AIPKit_OpenAI_Reasoning::get_default_effort_for_model($reasoning_model)
            : '';
    }
    $settings['reasoning_effort'] = $reasoning_effort !== '' ? $reasoning_effort : $default_reasoning_effort;


    return $settings;
}

// --- fn-get-claude-specific-config.php ---
/**
 * Retrieves Claude-specific configuration settings (Web Search).
 *
 * @param int $bot_id The ID of the bot post.
 * @param callable $get_meta_fn A function to retrieve post meta.
 * @return array Associative array of Claude-specific settings.
 */
function get_claude_specific_config_logic(int $bot_id, callable $get_meta_fn): array
{
    $settings = [];


    $settings['claude_web_search_enabled'] = in_array(
        $get_meta_fn('_aipkit_claude_web_search_enabled', BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_ENABLED),
        ['0', '1'],
        true
    ) ? $get_meta_fn('_aipkit_claude_web_search_enabled', BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_ENABLED)
      : BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_ENABLED;

    $raw_max_uses = $get_meta_fn('_aipkit_claude_web_search_max_uses', (string) BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_MAX_USES);
    $max_uses = is_numeric($raw_max_uses) ? absint($raw_max_uses) : BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_MAX_USES;
    $settings['claude_web_search_max_uses'] = max(1, min($max_uses, 20));

    $settings['claude_web_search_loc_type'] = $get_meta_fn('_aipkit_claude_web_search_loc_type', BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_LOC_TYPE);
    if (!in_array($settings['claude_web_search_loc_type'], ['none', 'approximate'], true)) {
        $settings['claude_web_search_loc_type'] = BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_LOC_TYPE;
    }

    $settings['claude_web_search_loc_country'] = $get_meta_fn('_aipkit_claude_web_search_loc_country', '');
    $settings['claude_web_search_loc_city'] = $get_meta_fn('_aipkit_claude_web_search_loc_city', '');
    $settings['claude_web_search_loc_region'] = $get_meta_fn('_aipkit_claude_web_search_loc_region', '');
    $settings['claude_web_search_loc_timezone'] = $get_meta_fn('_aipkit_claude_web_search_loc_timezone', '');

    $settings['claude_web_search_allowed_domains'] = $get_meta_fn('_aipkit_claude_web_search_allowed_domains', '');
    $settings['claude_web_search_blocked_domains'] = $get_meta_fn('_aipkit_claude_web_search_blocked_domains', '');
    if (!empty($settings['claude_web_search_allowed_domains']) && !empty($settings['claude_web_search_blocked_domains'])) {
        $settings['claude_web_search_blocked_domains'] = '';
    }

    $settings['claude_web_search_cache_ttl'] = $get_meta_fn('_aipkit_claude_web_search_cache_ttl', BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_CACHE_TTL);
    if (!in_array($settings['claude_web_search_cache_ttl'], ['none', '5m', '1h'], true)) {
        $settings['claude_web_search_cache_ttl'] = BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_CACHE_TTL;
    }

    return $settings;
}

// --- fn-get-openrouter-specific-config.php ---
/**
 * Retrieves OpenRouter-specific chatbot configuration settings.
 *
 * @param int $bot_id The ID of the bot post.
 * @param callable $get_meta_fn A function to retrieve post meta.
 * @return array Associative array of OpenRouter-specific settings.
 */
function get_openrouter_specific_config_logic(int $bot_id, callable $get_meta_fn): array
{
    $settings = [];


    $settings['openrouter_session_stickiness'] = in_array(
        $get_meta_fn('_aipkit_openrouter_session_stickiness', BotSettingsManager::DEFAULT_OPENROUTER_SESSION_STICKINESS),
        ['0', '1'],
        true
    ) ? $get_meta_fn('_aipkit_openrouter_session_stickiness', BotSettingsManager::DEFAULT_OPENROUTER_SESSION_STICKINESS)
      : BotSettingsManager::DEFAULT_OPENROUTER_SESSION_STICKINESS;

    $settings['openrouter_web_search_enabled'] = in_array(
        $get_meta_fn('_aipkit_openrouter_web_search_enabled', BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_ENABLED),
        ['0', '1'],
        true
    ) ? $get_meta_fn('_aipkit_openrouter_web_search_enabled', BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_ENABLED)
      : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_ENABLED;

    $engine = $get_meta_fn('_aipkit_openrouter_web_search_engine', BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_ENGINE);
    $settings['openrouter_web_search_engine'] = in_array($engine, ['auto', 'native', 'exa', 'firecrawl', 'parallel', 'perplexity'], true)
        ? $engine
        : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_ENGINE;

    $raw_max_results = $get_meta_fn(
        '_aipkit_openrouter_web_search_max_results',
        (string) BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_MAX_RESULTS
    );
    $max_results = is_numeric($raw_max_results) ? absint($raw_max_results) : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_MAX_RESULTS;
    $settings['openrouter_web_search_max_results'] = max(1, min($max_results, 25));

    $raw_max_uses = $get_meta_fn('_aipkit_openrouter_web_search_max_uses', (string) BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_MAX_USES);
    $max_uses = is_numeric($raw_max_uses) ? absint($raw_max_uses) : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_MAX_USES;
    $settings['openrouter_web_search_max_uses'] = max(1, min($max_uses, 10));

    $raw_max_total_results = $get_meta_fn('_aipkit_openrouter_web_search_max_total_results', (string) BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_MAX_TOTAL_RESULTS);
    $max_total_results = is_numeric($raw_max_total_results) ? absint($raw_max_total_results) : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_MAX_TOTAL_RESULTS;
    $settings['openrouter_web_search_max_total_results'] = max(1, min($max_total_results, 100));

    $context_size = $get_meta_fn('_aipkit_openrouter_web_search_context_size', BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_CONTEXT_SIZE);
    $settings['openrouter_web_search_context_size'] = in_array($context_size, ['auto', 'low', 'medium', 'high'], true)
        ? $context_size
        : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_CONTEXT_SIZE;
    $settings['openrouter_web_search_allowed_domains'] = $get_meta_fn('_aipkit_openrouter_web_search_allowed_domains', BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_ALLOWED_DOMAINS);
    $settings['openrouter_web_search_excluded_domains'] = $get_meta_fn('_aipkit_openrouter_web_search_excluded_domains', BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_EXCLUDED_DOMAINS);
    if ($settings['openrouter_web_search_allowed_domains'] !== '') {
        $settings['openrouter_web_search_excluded_domains'] = '';
    }

    return $settings;
}

// --- fn-get-xai-specific-config.php ---
/**
 * Retrieves xAI-specific chatbot settings.
 *
 * @param int $bot_id The ID of the bot post.
 * @param callable $get_meta_fn A function to retrieve post meta.
 * @return array Associative array of xAI-specific settings.
 */
function get_xai_specific_config_logic(int $bot_id, callable $get_meta_fn): array
{

    $enabled = $get_meta_fn('_aipkit_xai_web_search_enabled', BotSettingsManager::DEFAULT_XAI_WEB_SEARCH_ENABLED);

    return [
        'xai_web_search_enabled' => in_array($enabled, ['0', '1'], true)
            ? $enabled
            : BotSettingsManager::DEFAULT_XAI_WEB_SEARCH_ENABLED,
    ];
}

// --- fn-get-google-specific-config.php ---
/**
 * Retrieves Google-specific configuration settings (Search Grounding).
 *
 * @param int $bot_id The ID of the bot post.
 * @param callable $get_meta_fn A function to retrieve post meta.
 * @return array Associative array of Google-specific settings.
 */
function get_google_specific_config_logic(int $bot_id, callable $get_meta_fn): array
{
    $settings = [];


    $settings['google_search_grounding_enabled'] = in_array(
        $get_meta_fn('_aipkit_google_search_grounding_enabled', BotSettingsManager::DEFAULT_GOOGLE_SEARCH_GROUNDING_ENABLED),
        ['0', '1']
    ) ? $get_meta_fn('_aipkit_google_search_grounding_enabled', BotSettingsManager::DEFAULT_GOOGLE_SEARCH_GROUNDING_ENABLED)
      : BotSettingsManager::DEFAULT_GOOGLE_SEARCH_GROUNDING_ENABLED;

    $settings['google_conversation_state_enabled'] = in_array(
        $get_meta_fn('_aipkit_google_conversation_state_enabled', BotSettingsManager::DEFAULT_GOOGLE_CONVERSATION_STATE_ENABLED),
        ['0', '1'],
        true
    ) ? $get_meta_fn('_aipkit_google_conversation_state_enabled', BotSettingsManager::DEFAULT_GOOGLE_CONVERSATION_STATE_ENABLED)
      : BotSettingsManager::DEFAULT_GOOGLE_CONVERSATION_STATE_ENABLED;

    return $settings;
}

// --- fn-get-trigger-config.php ---
// --- fn-get-embed-settings.php ---
/**
 * Retrieves Embed configuration settings.
 *
 * @param int $bot_id The ID of the bot post.
 * @param callable $get_meta_fn A function to retrieve post meta.
 * @return array Associative array of embed settings.
 */
function get_embed_settings_logic(int $bot_id, callable $get_meta_fn): array
{
    $settings = [];

    $deploy_mode = sanitize_key((string) $get_meta_fn('_aipkit_deploy_mode', ''));
    if (in_array($deploy_mode, ['inline', 'popup', 'external'], true)) {
        $settings['deploy_mode'] = $deploy_mode;
    } else {
        $legacy_popup_enabled = (string) $get_meta_fn(
            '_aipkit_popup_enabled',
            BotSettingsManager::DEFAULT_POPUP_ENABLED
        );
        $settings['deploy_mode'] = ($legacy_popup_enabled === '0')
            ? 'inline'
            : BotSettingsManager::DEFAULT_DEPLOY_MODE;
    }

    // Get the allowed domains, default to an empty string if not set.
    $settings['embed_allowed_domains'] = $get_meta_fn('_aipkit_embed_allowed_domains', '');

    return $settings;
}

namespace WPAICG\Chat\Storage\SaverMethods;

use WPAICG\Chat\Admin\AdminSetup;
use WP_Error;
use WPAICG\Core\AIPKit_OpenAI_Reasoning;
use WPAICG\Chat\Storage\BotSettingsManager;
use WPAICG\AIPKit_Providers;
use WPAICG\Utils\AIPKit_Prompt_Sanitizer;
use WPAICG\Chat\Storage\SiteWideBotManager;
use WPAICG\Core\Models\AIPKit_Model_Catalog;


// --- validate-bot-post-logic.php ---
/**
 * Validates the bot post ID, type, and status.
 *
 * @param int $botId The ID of the bot post.
 * @return \WP_Post|WP_Error The WP_Post object on success, or WP_Error on failure.
 */
function validate_bot_post_logic(int $botId) {
    if (!class_exists(AdminSetup::class)) {
        $admin_setup_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/admin.php';
        if (file_exists($admin_setup_path)) {
            require_once $admin_setup_path;
        } else {
            return new WP_Error('dependency_missing_validator', __('AdminSetup class missing.', 'gpt3-ai-content-generator'));
        }
    }

    $post = get_post($botId);
    if (!$post) {
        return new WP_Error('post_not_found_validator', __('Chatbot post not found.', 'gpt3-ai-content-generator'));
    }

    if ($post->post_type !== AdminSetup::POST_TYPE) {
        return new WP_Error('invalid_post_type_validator', __('Invalid chatbot post type.', 'gpt3-ai-content-generator'));
    }

    if (!in_array($post->post_status, ['publish', 'draft'], true)) {
        return new WP_Error('invalid_post_status_validator', __('Chatbot post has an invalid status.', 'gpt3-ai-content-generator'));
    }

    return $post;
}

/**
 * Coordinates native Knowledge with chatbot provider and Google Search while
 * preserving the selected Knowledge provider and its stores.
 *
 * @param array $raw_settings Raw settings submitted by the admin UI.
 * @param int   $bot_id       Chatbot post ID.
 * @return array|WP_Error Coordinated raw settings or a validation error.
 */
function coordinate_native_knowledge_settings_logic(array $raw_settings, int $bot_id) {
    $allowed_vector_store_providers = ['openai', 'google', 'pinecone', 'qdrant', 'chroma', 'local', 'claude_files'];
    $stored_chatbot_provider = (string) get_post_meta($bot_id, '_aipkit_provider', true);
    $stored_vector_store_provider = (string) get_post_meta($bot_id, '_aipkit_vector_store_provider', true);
    $stored_enable_vector_store = (string) get_post_meta($bot_id, '_aipkit_enable_vector_store', true);

    $chatbot_provider = isset($raw_settings['provider'])
        ? sanitize_text_field((string) $raw_settings['provider'])
        : ($stored_chatbot_provider !== '' ? $stored_chatbot_provider : 'OpenAI');
    $vector_store_provider = isset($raw_settings['vector_store_provider'])
        ? sanitize_key((string) $raw_settings['vector_store_provider'])
        : ($stored_vector_store_provider !== ''
            ? sanitize_key($stored_vector_store_provider)
            : BotSettingsManager::DEFAULT_VECTOR_STORE_PROVIDER);
    $enable_vector_store = isset($raw_settings['enable_vector_store'])
        ? (((string) $raw_settings['enable_vector_store'] === '1') ? '1' : '0')
        : (($stored_enable_vector_store === '1') ? '1' : '0');

    if (!in_array($vector_store_provider, $allowed_vector_store_providers, true)) {
        return new WP_Error(
            'invalid_knowledge_provider',
            __('Invalid knowledge provider.', 'gpt3-ai-content-generator'),
            ['status' => 400]
        );
    }

    $google_search_enabled = isset($raw_settings['google_search_grounding_enabled'])
        ? (((string) $raw_settings['google_search_grounding_enabled'] === '1') ? '1' : '0')
        : ((string) get_post_meta($bot_id, '_aipkit_google_search_grounding_enabled', true) === '1' ? '1' : '0');
    $knowledge_state = BotSettingsManager::get_knowledge_capability_state(
        $chatbot_provider,
        $vector_store_provider,
        $enable_vector_store,
        $google_search_enabled
    );
    if ($knowledge_state['requested'] && !$knowledge_state['compatible']) {
        $raw_settings['enable_vector_store'] = '0';
    }
    if ($knowledge_state['google_search_conflict']) {
        $raw_settings['google_search_grounding_enabled'] = '0';
    }

    return $raw_settings;
}

// --- sanitize-settings-logic.php ---
/**
 * Sanitizes the raw bot settings array.
 * UPDATED: Includes sanitization for new custom theme settings.
 * UPDATED: Validates new theme names.
 * FIXED: Ensures color fields default to valid hex codes from $custom_theme_defaults if input is invalid/empty.
 * ADDED: Handles 'triggers_json' from form.
 *
 * @param array $raw_settings The raw settings array from $_POST or similar.
 * @param int $bot_id The ID of the bot (for context, e.g., default values).
 * @return array The sanitized settings array.
 */
function sanitize_settings_logic(array $raw_settings, int $bot_id): array
{
    $sanitized = [];
    $custom_theme_defaults = BotSettingsManager::get_custom_theme_defaults();

    $sanitized['greeting'] = isset($raw_settings['greeting']) ? sanitize_textarea_field($raw_settings['greeting']) : '';
    $sanitized['subgreeting'] = isset($raw_settings['subgreeting']) ? sanitize_textarea_field($raw_settings['subgreeting']) : '';
    $sanitized['provider'] = isset($raw_settings['provider']) ? sanitize_text_field($raw_settings['provider']) : '';
    $valid_themes = ['light', 'dark', 'custom', 'chatgpt'];
    $has_valid_theme = isset($raw_settings['theme']) && in_array($raw_settings['theme'], $valid_themes, true);
    $sanitized['theme'] = $has_valid_theme
        ? sanitize_text_field($raw_settings['theme'])
        : BotSettingsManager::DEFAULT_THEME;
    $raw_theme_preset_key = isset($raw_settings['theme_preset_key'])
        ? sanitize_key((string) $raw_settings['theme_preset_key'])
        : ($has_valid_theme ? '' : BotSettingsManager::DEFAULT_THEME_PRESET_KEY);
    $valid_theme_preset_keys = [];
    if (class_exists(BotSettingsManager::class)) {
        $custom_theme_presets = BotSettingsManager::get_custom_theme_presets();
        foreach ($custom_theme_presets as $preset) {
            if (!is_array($preset) || !isset($preset['key'])) {
                continue;
            }
            $preset_key = sanitize_key((string) $preset['key']);
            if ($preset_key !== '') {
                $valid_theme_preset_keys[$preset_key] = true;
            }
        }
    }
    $sanitized['theme_preset_key'] = (
        $sanitized['theme'] === 'custom' &&
        $raw_theme_preset_key !== '' &&
        isset($valid_theme_preset_keys[$raw_theme_preset_key])
    )
        ? $raw_theme_preset_key
        : '';
    $sanitized['theme_dimension_overrides'] = BotSettingsManager::normalize_theme_dimension_overrides($raw_settings['theme_dimension_overrides'] ?? '');
    $sanitized['instructions'] = isset($raw_settings['instructions']) ? AIPKit_Prompt_Sanitizer::sanitize($raw_settings['instructions']) : '';
    $sanitized['popup_enabled'] = isset($raw_settings['popup_enabled'])
        ? (($raw_settings['popup_enabled'] === '1') ? '1' : '0')
        : BotSettingsManager::DEFAULT_POPUP_ENABLED;
    $sanitized['popup_position'] = isset($raw_settings['popup_position']) ? sanitize_key($raw_settings['popup_position']) : 'bottom-right';
    $sanitized['popup_delay'] = isset($raw_settings['popup_delay']) ? absint($raw_settings['popup_delay']) : BotSettingsManager::DEFAULT_POPUP_DELAY;
    $sanitized['site_wide_enabled'] = isset($raw_settings['site_wide_enabled'])
        ? (($raw_settings['site_wide_enabled'] === '1') ? '1' : '0')
        : BotSettingsManager::DEFAULT_SITE_WIDE_ENABLED;
    if ($sanitized['popup_enabled'] !== '1') {
        $sanitized['site_wide_enabled'] = '0';
    }
    $raw_deploy_mode = isset($raw_settings['deploy_mode']) ? sanitize_key((string) $raw_settings['deploy_mode']) : '';
    $sanitized['deploy_mode'] = in_array($raw_deploy_mode, ['inline', 'popup', 'external'], true)
        ? $raw_deploy_mode
        : (($sanitized['popup_enabled'] === '1') ? BotSettingsManager::DEFAULT_DEPLOY_MODE : 'inline');
    $sanitized['popup_icon_style'] = isset($raw_settings['popup_icon_style']) && in_array($raw_settings['popup_icon_style'], ['circle', 'square', 'none']) ? sanitize_key($raw_settings['popup_icon_style']) : BotSettingsManager::DEFAULT_POPUP_ICON_STYLE;
    $allowed_icon_sizes = ['small','medium','large','xlarge'];
    $sanitized['popup_icon_size'] = isset($raw_settings['popup_icon_size']) && in_array($raw_settings['popup_icon_size'], $allowed_icon_sizes, true)
        ? sanitize_key($raw_settings['popup_icon_size'])
        : (defined('WPAICG\\Chat\\Storage\\BotSettingsManager::DEFAULT_POPUP_ICON_SIZE') ? BotSettingsManager::DEFAULT_POPUP_ICON_SIZE : 'medium');
    $sanitized['popup_icon_type'] = isset($raw_settings['popup_icon_type']) && in_array($raw_settings['popup_icon_type'], ['default', 'custom']) ? $raw_settings['popup_icon_type'] : BotSettingsManager::DEFAULT_POPUP_ICON_TYPE;
    $sanitized['popup_icon_value'] = '';
    if ($sanitized['popup_icon_type'] === 'default') {
        $default_icon_key = isset($raw_settings['popup_icon_default']) && in_array($raw_settings['popup_icon_default'], ['chat-bubble', 'spark', 'openai', 'plus', 'question-mark']) ? $raw_settings['popup_icon_default'] : BotSettingsManager::DEFAULT_POPUP_ICON_VALUE;
        $sanitized['popup_icon_value'] = $default_icon_key;
    } elseif ($sanitized['popup_icon_type'] === 'custom') {
        $sanitized['popup_icon_value'] = isset($raw_settings['popup_icon_custom_url']) ? esc_url_raw(trim($raw_settings['popup_icon_custom_url'])) : '';
    }
    $sanitized['footer_text'] = isset($raw_settings['footer_text']) ? wp_kses_post($raw_settings['footer_text']) : '';
    $allowed_header_icons = ['chat-bubble', 'spark', 'openai', 'plus', 'question-mark'];
    $header_avatar_type = isset($raw_settings['header_avatar_type']) && in_array($raw_settings['header_avatar_type'], ['inherit', 'default', 'custom'], true)
        ? sanitize_key($raw_settings['header_avatar_type'])
        : BotSettingsManager::DEFAULT_HEADER_AVATAR_TYPE;
    if (!isset($raw_settings['header_avatar_type']) && !empty($raw_settings['header_avatar_url'])) {
        $header_avatar_type = 'custom';
    }
    $header_avatar_value = '';
    $header_avatar_url = '';
    if ($header_avatar_type === 'custom') {
        $header_avatar_url = isset($raw_settings['header_avatar_url'])
            ? esc_url_raw(trim((string)$raw_settings['header_avatar_url']))
            : BotSettingsManager::DEFAULT_HEADER_AVATAR_URL;
        $header_avatar_value = $header_avatar_url;
    } elseif ($header_avatar_type === 'default') {
        $default_avatar_key = isset($raw_settings['header_avatar_default']) && in_array($raw_settings['header_avatar_default'], $allowed_header_icons, true)
            ? sanitize_key($raw_settings['header_avatar_default'])
            : BotSettingsManager::DEFAULT_HEADER_AVATAR_VALUE;
        $header_avatar_value = $default_avatar_key;
    } else {
        $header_avatar_value = BotSettingsManager::DEFAULT_HEADER_AVATAR_VALUE;
    }
    $sanitized['header_avatar_type'] = $header_avatar_type;
    $sanitized['header_avatar_value'] = $header_avatar_value;
    $sanitized['header_avatar_url'] = $header_avatar_url;
    $sanitized['header_online_text'] = isset($raw_settings['header_online_text'])
        ? sanitize_text_field($raw_settings['header_online_text'])
        : __('Online', 'gpt3-ai-content-generator');
    $sanitized['enable_fullscreen'] = (isset($raw_settings['enable_fullscreen']) && $raw_settings['enable_fullscreen'] === '1') ? '1' : '0';
    $sanitized['enable_download'] = (isset($raw_settings['enable_download']) && $raw_settings['enable_download'] === '1') ? '1' : '0';
    $sanitized['enable_copy_button'] = '1';
    $sanitized['enable_feedback'] = '1';
    $sanitized['enable_consent_compliance'] = (isset($raw_settings['enable_consent_compliance']) && $raw_settings['enable_consent_compliance'] === '1') ? '1' : '0';
    $sanitized['consent_title'] = isset($raw_settings['consent_title']) ? sanitize_text_field($raw_settings['consent_title']) : '';
    $sanitized['consent_message'] = isset($raw_settings['consent_message']) ? wp_kses_post($raw_settings['consent_message']) : '';
    $sanitized['consent_button'] = isset($raw_settings['consent_button']) ? sanitize_text_field($raw_settings['consent_button']) : '';
    $sanitized['enable_conversation_sidebar'] = (isset($raw_settings['enable_conversation_sidebar']) && $raw_settings['enable_conversation_sidebar'] === '1') ? '1' : '0';
    // Typing indicator customization
    $sanitized['custom_typing_text'] = isset($raw_settings['custom_typing_text']) ? sanitize_text_field($raw_settings['custom_typing_text']) : '';
    $sanitized['retrieving_context_text'] = isset($raw_settings['retrieving_context_text'])
        ? sanitize_text_field((string) $raw_settings['retrieving_context_text'])
        : BotSettingsManager::DEFAULT_RETRIEVING_CONTEXT_TEXT;
    $sanitized['input_placeholder'] = isset($raw_settings['input_placeholder']) ? sanitize_text_field($raw_settings['input_placeholder']) : __('Type your message...', 'gpt3-ai-content-generator');
    $sanitized['temperature'] = isset($raw_settings['temperature']) ? floatval($raw_settings['temperature']) : BotSettingsManager::DEFAULT_TEMPERATURE;
    $sanitized['max_completion_tokens'] = isset($raw_settings['max_completion_tokens']) ? absint($raw_settings['max_completion_tokens']) : BotSettingsManager::DEFAULT_MAX_COMPLETION_TOKENS;
    $sanitized['max_messages'] = isset($raw_settings['max_messages']) ? absint($raw_settings['max_messages']) : BotSettingsManager::DEFAULT_MAX_MESSAGES;
    $reasoning_effort = AIPKit_OpenAI_Reasoning::sanitize_effort($raw_settings['reasoning_effort'] ?? '');
    $reasoning_model = sanitize_text_field((string) ($raw_settings['model'] ?? ''));
    if ($sanitized['provider'] === 'OpenAI' && AIPKit_OpenAI_Reasoning::is_gpt_6_astra($reasoning_model)) {
        $reasoning_effort = AIPKit_OpenAI_Reasoning::normalize_effort_for_model(
            $reasoning_model,
            $reasoning_effort !== '' ? $reasoning_effort : BotSettingsManager::DEFAULT_REASONING_EFFORT
        );
    } elseif ($reasoning_effort === 'max') {
        $reasoning_effort = $sanitized['provider'] === 'OpenAI'
            ? AIPKit_OpenAI_Reasoning::get_default_effort_for_model($reasoning_model)
            : '';
    }
    $sanitized['reasoning_effort'] = $reasoning_effort !== '' ? $reasoning_effort : BotSettingsManager::DEFAULT_REASONING_EFFORT;
    $sanitized['enable_conversation_starters'] = (isset($raw_settings['enable_conversation_starters']) && $raw_settings['enable_conversation_starters'] === '1') ? '1' : '0';
    $starters_raw = $raw_settings['conversation_starters'] ?? '';
    $sanitized['conversation_starters'] = BotSettingsManager::encode_conversation_starters(
        BotSettingsManager::normalize_conversation_starters($starters_raw)
    );
    $sanitized['content_aware_enabled'] = (isset($raw_settings['content_aware_enabled']) && $raw_settings['content_aware_enabled'] === '1') ? '1' : '0';
    $sanitized['openai_conversation_state_enabled'] = (isset($raw_settings['openai_conversation_state_enabled']) && $raw_settings['openai_conversation_state_enabled'] === '1') ? '1' : '0';
    $sanitized['google_conversation_state_enabled'] = (isset($raw_settings['google_conversation_state_enabled']) && $raw_settings['google_conversation_state_enabled'] === '1') ? '1' : '0';
    $sanitized['openrouter_session_stickiness'] = (isset($raw_settings['openrouter_session_stickiness']) && $raw_settings['openrouter_session_stickiness'] === '1') ? '1' : '0';
    $sanitized['token_limit_mode'] = isset($raw_settings['token_limit_mode']) && in_array($raw_settings['token_limit_mode'], ['general', 'role_based']) ? $raw_settings['token_limit_mode'] : BotSettingsManager::DEFAULT_TOKEN_LIMIT_MODE;
    $raw_guest_limit = isset($raw_settings['token_guest_limit']) ? trim($raw_settings['token_guest_limit']) : '';
    $sanitized['token_guest_limit'] = ($raw_guest_limit === '0' || (ctype_digit($raw_guest_limit) && $raw_guest_limit > 0)) ? (string)absint($raw_guest_limit) : ''; // Store as string or empty
    $raw_user_limit = isset($raw_settings['token_user_limit']) ? trim($raw_settings['token_user_limit']) : '';
    $sanitized['token_user_limit'] = ($raw_user_limit === '0' || (ctype_digit($raw_user_limit) && $raw_user_limit > 0)) ? (string)absint($raw_user_limit) : '';
    $role_limits_to_save = [];
    if (isset($raw_settings['token_role_limits']) && is_array($raw_settings['token_role_limits'])) {
        $editable_roles = get_editable_roles();
        foreach ($editable_roles as $role_slug => $role_info) {
            if (isset($raw_settings['token_role_limits'][$role_slug])) {
                $raw_limit = trim($raw_settings['token_role_limits'][$role_slug]);
                if ($raw_limit === '0' || (ctype_digit($raw_limit) && $raw_limit > 0)) {
                    $role_limits_to_save[$role_slug] = (string)absint($raw_limit);
                } else {
                    $role_limits_to_save[$role_slug] = '';
                }
            }
        }
    }
    $sanitized['token_role_limits'] = wp_json_encode($role_limits_to_save, JSON_UNESCAPED_UNICODE);
    $sanitized['token_reset_period'] = isset($raw_settings['token_reset_period']) && in_array($raw_settings['token_reset_period'], ['never', 'daily', 'weekly', 'monthly']) ? sanitize_key($raw_settings['token_reset_period']) : BotSettingsManager::DEFAULT_TOKEN_RESET_PERIOD;
    $sanitized['token_limit_message'] = isset($raw_settings['token_limit_message']) ? sanitize_text_field($raw_settings['token_limit_message']) : '';
    $allowed_token_limit_action_types = class_exists(BotSettingsManager::class)
        ? BotSettingsManager::get_token_limit_action_types()
        : ['none', 'dashboard_usage', 'dashboard_credits', 'dashboard_purchases', 'buy_credits', 'custom_url'];
    $default_token_limit_actions = class_exists(BotSettingsManager::class)
        ? BotSettingsManager::get_default_token_limit_action_settings()
        : [
            'primary_type' => 'none',
            'primary_label' => '',
            'primary_url' => '',
            'secondary_type' => 'none',
            'secondary_label' => '',
            'secondary_url' => '',
        ];
    $sanitized['token_limit_primary_action_type'] = isset($raw_settings['token_limit_primary_action_type']) && in_array($raw_settings['token_limit_primary_action_type'], $allowed_token_limit_action_types, true)
        ? sanitize_key($raw_settings['token_limit_primary_action_type'])
        : $default_token_limit_actions['primary_type'];
    $sanitized['token_limit_primary_action_label'] = isset($raw_settings['token_limit_primary_action_label'])
        ? sanitize_text_field((string) $raw_settings['token_limit_primary_action_label'])
        : $default_token_limit_actions['primary_label'];
    if ($sanitized['token_limit_primary_action_type'] === 'none') {
        $sanitized['token_limit_primary_action_label'] = '';
    } elseif ($sanitized['token_limit_primary_action_label'] === '') {
        $sanitized['token_limit_primary_action_label'] = class_exists(BotSettingsManager::class)
            ? BotSettingsManager::get_token_limit_action_default_label($sanitized['token_limit_primary_action_type'])
            : $default_token_limit_actions['primary_label'];
    }
    $sanitized['token_limit_primary_action_url'] = isset($raw_settings['token_limit_primary_action_url'])
        ? esc_url_raw(trim((string) $raw_settings['token_limit_primary_action_url']))
        : $default_token_limit_actions['primary_url'];
    $sanitized['token_limit_secondary_action_type'] = isset($raw_settings['token_limit_secondary_action_type']) && in_array($raw_settings['token_limit_secondary_action_type'], $allowed_token_limit_action_types, true)
        ? sanitize_key($raw_settings['token_limit_secondary_action_type'])
        : $default_token_limit_actions['secondary_type'];
    $sanitized['token_limit_secondary_action_label'] = isset($raw_settings['token_limit_secondary_action_label'])
        ? sanitize_text_field((string) $raw_settings['token_limit_secondary_action_label'])
        : $default_token_limit_actions['secondary_label'];
    if ($sanitized['token_limit_secondary_action_type'] === 'none') {
        $sanitized['token_limit_secondary_action_label'] = '';
    } elseif ($sanitized['token_limit_secondary_action_label'] === '') {
        $sanitized['token_limit_secondary_action_label'] = class_exists(BotSettingsManager::class)
            ? BotSettingsManager::get_token_limit_action_default_label($sanitized['token_limit_secondary_action_type'])
            : $default_token_limit_actions['secondary_label'];
    }
    $sanitized['token_limit_secondary_action_url'] = isset($raw_settings['token_limit_secondary_action_url'])
        ? esc_url_raw(trim((string) $raw_settings['token_limit_secondary_action_url']))
        : $default_token_limit_actions['secondary_url'];
    $sanitized['model'] = isset($raw_settings['model']) ? sanitize_text_field($raw_settings['model']) : '';
    $sanitized['tts_enabled'] = (isset($raw_settings['tts_enabled']) && $raw_settings['tts_enabled'] === '1') ? '1' : '0';
    $sanitized['tts_provider'] = isset($raw_settings['tts_provider']) ? sanitize_text_field($raw_settings['tts_provider']) : BotSettingsManager::DEFAULT_TTS_PROVIDER;
    if (!in_array($sanitized['tts_provider'], ['', 'Google', 'OpenAI', 'ElevenLabs', 'AIPufferCloud'])) {
        $sanitized['tts_provider'] = BotSettingsManager::DEFAULT_TTS_PROVIDER;
    }
    $sanitized['tts_google_voice_id'] = AIPKit_Providers::normalize_google_tts_voice(
        isset($raw_settings['tts_google_voice_id']) ? (string) $raw_settings['tts_google_voice_id'] : ''
    );
    $sanitized['tts_google_model_id'] = AIPKit_Providers::normalize_google_tts_model(
        isset($raw_settings['tts_google_model_id']) ? (string) $raw_settings['tts_google_model_id'] : ''
    );
    $sanitized['tts_openai_voice_id'] = isset($raw_settings['tts_openai_voice_id']) ? sanitize_text_field($raw_settings['tts_openai_voice_id']) : BotSettingsManager::get_default_model_id('OpenAIVoices');
    $sanitized['tts_openai_model_id'] = isset($raw_settings['tts_openai_model_id']) ? sanitize_text_field($raw_settings['tts_openai_model_id']) : BotSettingsManager::get_default_model_id('OpenAITTS');
    $sanitized['tts_cloud_voice_id'] = isset($raw_settings['tts_cloud_voice_id']) ? sanitize_text_field($raw_settings['tts_cloud_voice_id']) : 'alloy';
    $sanitized['tts_cloud_model_id'] = isset($raw_settings['tts_cloud_model_id'])
        ? sanitize_text_field($raw_settings['tts_cloud_model_id']) : (\WPAICG\Cloud\Connection::media_models('speech_generate')[0]['id'] ?? '');
    $sanitized['tts_elevenlabs_voice_id'] = isset($raw_settings['tts_elevenlabs_voice_id']) ? sanitize_text_field($raw_settings['tts_elevenlabs_voice_id']) : '';
    $sanitized['tts_elevenlabs_model_id'] = isset($raw_settings['tts_elevenlabs_model_id']) ? sanitize_text_field($raw_settings['tts_elevenlabs_model_id']) : '';
    $sanitized['tts_auto_play'] = (isset($raw_settings['tts_auto_play']) && $raw_settings['tts_auto_play'] === '1') ? '1' : '0';
    $sanitized['enable_voice_input'] = (isset($raw_settings['enable_voice_input']) && $raw_settings['enable_voice_input'] === '1') ? '1' : '0';
    $sanitized['stt_provider'] = isset($raw_settings['stt_provider']) ? sanitize_text_field($raw_settings['stt_provider']) : BotSettingsManager::DEFAULT_STT_PROVIDER;
    if (!in_array($sanitized['stt_provider'], ['', 'OpenAI', 'Google', 'Azure', 'AIPufferCloud'], true)) {
        $sanitized['stt_provider'] = BotSettingsManager::DEFAULT_STT_PROVIDER;
    }
    $sanitized['stt_openai_model_id'] = AIPKit_Model_Catalog::sanitize_openai_file_transcription_model(
        isset($raw_settings['stt_openai_model_id'])
            ? (string) $raw_settings['stt_openai_model_id']
            : BotSettingsManager::get_default_model_id('OpenAISTT')
    );
    $sanitized['stt_google_model_id'] = AIPKit_Providers::normalize_google_stt_model(
        isset($raw_settings['stt_google_model_id']) ? (string) $raw_settings['stt_google_model_id'] : ''
    );
    $sanitized['stt_cloud_model_id'] = isset($raw_settings['stt_cloud_model_id'])
        ? sanitize_text_field($raw_settings['stt_cloud_model_id']) : (\WPAICG\Cloud\Connection::media_models('transcribe')[0]['id'] ?? '');
    $sanitized['stt_azure_model_id'] = isset($raw_settings['stt_azure_model_id']) ? sanitize_text_field($raw_settings['stt_azure_model_id']) : BotSettingsManager::DEFAULT_STT_AZURE_MODEL_ID;
    $raw_image_triggers = isset($raw_settings['image_triggers']) ? sanitize_text_field($raw_settings['image_triggers']) : BotSettingsManager::DEFAULT_IMAGE_TRIGGERS;
    $triggers_array = array_map('trim', explode(',', $raw_image_triggers));
    $triggers_array = array_filter($triggers_array, function ($trigger) { return !empty($trigger) && preg_match('/^\/[a-zA-Z0-9_]+$/', $trigger); });
    $sanitized['image_triggers'] = !empty($triggers_array) ? implode(',', $triggers_array) : BotSettingsManager::DEFAULT_IMAGE_TRIGGERS;
    $sanitized['chat_image_model_id'] = isset($raw_settings['chat_image_model_id']) ? sanitize_text_field($raw_settings['chat_image_model_id']) : BotSettingsManager::get_default_model_id('OpenAIImage');
    $saved_image_model = strtolower((string) $sanitized['chat_image_model_id']);
    if (
        strpos($saved_image_model, 'imagen-') === 0
        || (strpos($saved_image_model, 'gemini-') === 0 && strpos($saved_image_model, 'image') !== false)
    ) {
        $sanitized['chat_image_model_id'] = AIPKit_Providers::normalize_google_image_model(
            (string) $sanitized['chat_image_model_id']
        );
    }
    $sanitized['enable_image_generation'] = (isset($raw_settings['enable_image_generation']) && $raw_settings['enable_image_generation'] === '1') ? '1' : '0';
    $sanitized['enable_file_upload'] = (isset($raw_settings['enable_file_upload']) && $raw_settings['enable_file_upload'] === '1') ? '1' : '0';
    $sanitized['enable_image_upload'] = (isset($raw_settings['enable_image_upload']) && $raw_settings['enable_image_upload'] === '1') ? '1' : '0';
    $sanitized['enable_vector_store'] = (isset($raw_settings['enable_vector_store']) && $raw_settings['enable_vector_store'] === '1') ? '1' : '0';
    $vector_store_provider_input = isset($raw_settings['vector_store_provider'])
        ? sanitize_key((string) $raw_settings['vector_store_provider'])
        : '';
    $allowed_vector_store_providers = ['openai', 'google', 'pinecone', 'qdrant', 'chroma', 'local', 'claude_files'];
    $sanitized['vector_store_provider'] = in_array($vector_store_provider_input, $allowed_vector_store_providers, true)
        ? $vector_store_provider_input
        : BotSettingsManager::DEFAULT_VECTOR_STORE_PROVIDER;
    $openai_vs_ids_raw = isset($raw_settings['openai_vector_store_ids']) && is_array($raw_settings['openai_vector_store_ids']) ? $raw_settings['openai_vector_store_ids'] : [];
    $openai_vs_ids_to_save = [];
    foreach ($openai_vs_ids_raw as $vs_id) {
        $sanitized_id = sanitize_text_field(trim($vs_id));
        if (!empty($sanitized_id) && strpos($sanitized_id, 'vs_') === 0) {
            $openai_vs_ids_to_save[] = $sanitized_id;
        }
    }
    $sanitized['openai_vector_store_ids'] = wp_json_encode(array_values(array_unique($openai_vs_ids_to_save)));
    $google_store_names_raw = isset($raw_settings['google_file_search_store_names'])
        && is_array($raw_settings['google_file_search_store_names'])
        ? $raw_settings['google_file_search_store_names']
        : [];
    $google_store_names = [];
    foreach ($google_store_names_raw as $store_name) {
        $store_name = sanitize_text_field(trim((string) $store_name));
        if (strpos($store_name, 'fileSearchStores/') === 0) {
            $google_store_names[] = $store_name;
        }
    }
    $sanitized['google_file_search_store_names'] = wp_json_encode(array_values(array_unique($google_store_names)));
    $sanitized['pinecone_index_name'] = ($sanitized['vector_store_provider'] === 'pinecone' && isset($raw_settings['pinecone_index_name'])) ? sanitize_text_field($raw_settings['pinecone_index_name']) : '';
    // Qdrant: accept multiple collections; also maintain legacy single for compatibility
    $qdrant_names_raw = [];
    if ($sanitized['vector_store_provider'] === 'qdrant') {
        if (isset($raw_settings['qdrant_collection_names'])) {
            $qdrant_names_raw = is_array($raw_settings['qdrant_collection_names']) ? $raw_settings['qdrant_collection_names'] : [];
        } elseif (isset($raw_settings['qdrant_collection_names']) && is_string($raw_settings['qdrant_collection_names'])) {
            // If sent as JSON string for any reason
            $decoded = json_decode($raw_settings['qdrant_collection_names'], true);
            if (is_array($decoded)) { $qdrant_names_raw = $decoded; }
        }
        // Fallback to single field
        if (empty($qdrant_names_raw) && isset($raw_settings['qdrant_collection_name'])) {
            $single = sanitize_text_field($raw_settings['qdrant_collection_name']);
            if (!empty($single)) { $qdrant_names_raw = [$single]; }
        }
    }
    $qdrant_names_clean = [];
    foreach ($qdrant_names_raw as $name) {
        $sn = sanitize_text_field(trim((string)$name));
        if ($sn !== '') { $qdrant_names_clean[] = $sn; }
    }
    $qdrant_names_clean = array_values(array_unique($qdrant_names_clean));
    $sanitized['qdrant_collection_names'] = wp_json_encode($qdrant_names_clean);
    $sanitized['qdrant_collection_name'] = $qdrant_names_clean[0] ?? '';
    $chroma_names_raw = [];
    if ($sanitized['vector_store_provider'] === 'chroma') {
        if (isset($raw_settings['chroma_collection_names']) && is_array($raw_settings['chroma_collection_names'])) {
            $chroma_names_raw = $raw_settings['chroma_collection_names'];
        } elseif (isset($raw_settings['chroma_collection_names']) && is_string($raw_settings['chroma_collection_names'])) {
            $decoded = json_decode($raw_settings['chroma_collection_names'], true);
            if (is_array($decoded)) { $chroma_names_raw = $decoded; }
        }
        if (empty($chroma_names_raw) && isset($raw_settings['chroma_collection_name'])) {
            $single = sanitize_text_field($raw_settings['chroma_collection_name']);
            if (!empty($single)) { $chroma_names_raw = [$single]; }
        }
    }
    $chroma_names_clean = [];
    foreach ($chroma_names_raw as $name) {
        $sn = sanitize_text_field(trim((string)$name));
        if ($sn !== '') { $chroma_names_clean[] = $sn; }
    }
    $chroma_names_clean = array_values(array_unique($chroma_names_clean));
    $sanitized['chroma_collection_names'] = wp_json_encode($chroma_names_clean);
    $sanitized['chroma_collection_name'] = $chroma_names_clean[0] ?? '';
    $local_ids_raw = [];
    if ($sanitized['vector_store_provider'] === 'local') {
        $local_ids_raw = $raw_settings['local_store_ids'] ?? [];
        if (is_string($local_ids_raw)) { $local_ids_raw = json_decode($local_ids_raw, true); }
        $local_ids_raw = is_array($local_ids_raw) ? $local_ids_raw : [];
    }
    $sanitized['local_store_ids'] = wp_json_encode(array_values(array_unique(array_filter(array_map(static function ($id) {
        return substr((string) preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $id)), 0, 64);
    }, $local_ids_raw)))));
    $allowed_embedding_providers = array_unique(array_merge(AIPKit_Providers::get_embedding_provider_keys('chat_settings_sanitize'), ['aipuffercloud']));
    $uses_custom_embedding_provider = in_array($sanitized['vector_store_provider'], ['pinecone', 'qdrant', 'chroma', 'local'], true);
    $sanitized['vector_embedding_provider'] = ($uses_custom_embedding_provider && isset($raw_settings['vector_embedding_provider']))
        ? sanitize_key($raw_settings['vector_embedding_provider'])
        : BotSettingsManager::DEFAULT_VECTOR_EMBEDDING_PROVIDER;
    if ($sanitized['vector_embedding_provider'] !== '' && $sanitized['vector_embedding_provider'] !== 'aipuffercloud' && !in_array($sanitized['vector_embedding_provider'], $allowed_embedding_providers, true)) {
        $sanitized['vector_embedding_provider'] = BotSettingsManager::DEFAULT_VECTOR_EMBEDDING_PROVIDER;
    }
    $sanitized['vector_embedding_model'] = ($uses_custom_embedding_provider && isset($raw_settings['vector_embedding_model']))
        ? sanitize_text_field($raw_settings['vector_embedding_model'])
        : '';
    // Backward-compatibility: accept combined "provider::model" values from older UI payloads.
    if (strpos($sanitized['vector_embedding_model'], '::') !== false) {
        [$model_provider, $model_id] = array_pad(explode('::', $sanitized['vector_embedding_model'], 2), 2, '');
        $model_provider = sanitize_key((string) $model_provider);
        $model_id = sanitize_text_field((string) $model_id);
        if ($model_id !== '' && in_array($model_provider, $allowed_embedding_providers, true)) {
            $sanitized['vector_embedding_provider'] = $model_provider;
            $sanitized['vector_embedding_model'] = $model_id;
        }
    }
    $raw_top_k = isset($raw_settings['vector_store_top_k']) ? absint($raw_settings['vector_store_top_k']) : BotSettingsManager::DEFAULT_VECTOR_STORE_TOP_K;
    $sanitized['vector_store_top_k'] = max(1, min($raw_top_k, 20));
    $raw_threshold = isset($raw_settings['vector_store_confidence_threshold']) ? absint($raw_settings['vector_store_confidence_threshold']) : BotSettingsManager::DEFAULT_VECTOR_STORE_CONFIDENCE_THRESHOLD;
    $sanitized['vector_store_confidence_threshold'] = max(0, min($raw_threshold, 100));
    // END NEW
    $sanitized['openai_web_search_enabled'] = (isset($raw_settings['openai_web_search_enabled']) && $raw_settings['openai_web_search_enabled'] === '1') ? '1' : '0';
    $sanitized['openai_web_search_context_size'] = isset($raw_settings['openai_web_search_context_size']) && in_array($raw_settings['openai_web_search_context_size'], ['low', 'medium', 'high']) ? $raw_settings['openai_web_search_context_size'] : BotSettingsManager::DEFAULT_OPENAI_WEB_SEARCH_CONTEXT_SIZE;
    $sanitized['openai_web_search_loc_type'] = isset($raw_settings['openai_web_search_loc_type']) && in_array($raw_settings['openai_web_search_loc_type'], ['none', 'approximate']) ? $raw_settings['openai_web_search_loc_type'] : BotSettingsManager::DEFAULT_OPENAI_WEB_SEARCH_LOC_TYPE;
    $sanitized['openai_web_search_loc_country'] = isset($raw_settings['openai_web_search_loc_country']) ? sanitize_text_field($raw_settings['openai_web_search_loc_country']) : '';
    $sanitized['openai_web_search_loc_city'] = isset($raw_settings['openai_web_search_loc_city']) ? sanitize_text_field($raw_settings['openai_web_search_loc_city']) : '';
    $sanitized['openai_web_search_loc_region'] = isset($raw_settings['openai_web_search_loc_region']) ? sanitize_text_field($raw_settings['openai_web_search_loc_region']) : '';
    $sanitized['openai_web_search_loc_timezone'] = isset($raw_settings['openai_web_search_loc_timezone']) ? sanitize_text_field($raw_settings['openai_web_search_loc_timezone']) : '';
    $sanitized['claude_web_search_enabled'] = (isset($raw_settings['claude_web_search_enabled']) && $raw_settings['claude_web_search_enabled'] === '1') ? '1' : '0';
    $raw_claude_max_uses = isset($raw_settings['claude_web_search_max_uses']) ? absint($raw_settings['claude_web_search_max_uses']) : BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_MAX_USES;
    $sanitized['claude_web_search_max_uses'] = max(1, min($raw_claude_max_uses, 20));
    $sanitized['claude_web_search_loc_type'] = isset($raw_settings['claude_web_search_loc_type']) && in_array($raw_settings['claude_web_search_loc_type'], ['none', 'approximate'], true)
        ? $raw_settings['claude_web_search_loc_type']
        : BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_LOC_TYPE;
    $sanitized['claude_web_search_loc_country'] = isset($raw_settings['claude_web_search_loc_country']) ? sanitize_text_field($raw_settings['claude_web_search_loc_country']) : '';
    $sanitized['claude_web_search_loc_city'] = isset($raw_settings['claude_web_search_loc_city']) ? sanitize_text_field($raw_settings['claude_web_search_loc_city']) : '';
    $sanitized['claude_web_search_loc_region'] = isset($raw_settings['claude_web_search_loc_region']) ? sanitize_text_field($raw_settings['claude_web_search_loc_region']) : '';
    $sanitized['claude_web_search_loc_timezone'] = isset($raw_settings['claude_web_search_loc_timezone']) ? sanitize_text_field($raw_settings['claude_web_search_loc_timezone']) : '';
    $normalize_domains = static function ($domains_raw): string {
        if (!is_string($domains_raw)) {
            return '';
        }
        $domains = preg_split('/[\r\n,]+/', $domains_raw);
        if (!is_array($domains)) {
            return '';
        }
        $clean = [];
        foreach ($domains as $domain) {
            $value = strtolower(trim((string) $domain));
            if ($value === '') {
                continue;
            }
            $value = preg_replace('/^https?:\/\//', '', $value);
            $value = trim((string) $value, " \t\n\r\0\x0B/");
            if ($value === '') {
                continue;
            }
            if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $value)) {
                continue;
            }
            $clean[] = $value;
        }
        return implode(',', array_values(array_unique($clean)));
    };
    $sanitized['claude_web_search_allowed_domains'] = $normalize_domains($raw_settings['claude_web_search_allowed_domains'] ?? '');
    $sanitized['claude_web_search_blocked_domains'] = $normalize_domains($raw_settings['claude_web_search_blocked_domains'] ?? '');
    if ($sanitized['claude_web_search_allowed_domains'] !== '') {
        $sanitized['claude_web_search_blocked_domains'] = '';
    }
    $sanitized['claude_web_search_cache_ttl'] = isset($raw_settings['claude_web_search_cache_ttl']) && in_array($raw_settings['claude_web_search_cache_ttl'], ['none', '5m', '1h'], true)
        ? $raw_settings['claude_web_search_cache_ttl']
        : BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_CACHE_TTL;
    $sanitized['openrouter_web_search_enabled'] = (isset($raw_settings['openrouter_web_search_enabled']) && $raw_settings['openrouter_web_search_enabled'] === '1') ? '1' : '0';
    $sanitized['openrouter_web_search_engine'] = isset($raw_settings['openrouter_web_search_engine']) && in_array($raw_settings['openrouter_web_search_engine'], ['auto', 'native', 'exa', 'firecrawl', 'parallel', 'perplexity'], true)
        ? $raw_settings['openrouter_web_search_engine']
        : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_ENGINE;
    $raw_openrouter_max_results = isset($raw_settings['openrouter_web_search_max_results'])
        ? absint($raw_settings['openrouter_web_search_max_results'])
        : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_MAX_RESULTS;
    $sanitized['openrouter_web_search_max_results'] = max(1, min($raw_openrouter_max_results, 25));
    $raw_openrouter_max_uses = isset($raw_settings['openrouter_web_search_max_uses'])
        ? absint($raw_settings['openrouter_web_search_max_uses'])
        : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_MAX_USES;
    $sanitized['openrouter_web_search_max_uses'] = max(1, min($raw_openrouter_max_uses, 10));
    $raw_openrouter_max_total_results = isset($raw_settings['openrouter_web_search_max_total_results'])
        ? absint($raw_settings['openrouter_web_search_max_total_results'])
        : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_MAX_TOTAL_RESULTS;
    $sanitized['openrouter_web_search_max_total_results'] = max(1, min($raw_openrouter_max_total_results, 100));
    $sanitized['openrouter_web_search_context_size'] = isset($raw_settings['openrouter_web_search_context_size']) && in_array($raw_settings['openrouter_web_search_context_size'], ['auto', 'low', 'medium', 'high'], true)
        ? $raw_settings['openrouter_web_search_context_size']
        : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_CONTEXT_SIZE;
    $sanitized['openrouter_web_search_allowed_domains'] = $normalize_domains($raw_settings['openrouter_web_search_allowed_domains'] ?? '');
    $sanitized['openrouter_web_search_excluded_domains'] = $normalize_domains($raw_settings['openrouter_web_search_excluded_domains'] ?? '');
    if ($sanitized['openrouter_web_search_allowed_domains'] !== '') {
        $sanitized['openrouter_web_search_excluded_domains'] = '';
    }
    $sanitized['xai_web_search_enabled'] = (isset($raw_settings['xai_web_search_enabled']) && $raw_settings['xai_web_search_enabled'] === '1') ? '1' : '0';
    $sanitized['google_search_grounding_enabled'] = (isset($raw_settings['google_search_grounding_enabled']) && $raw_settings['google_search_grounding_enabled'] === '1') ? '1' : '0';
    $sanitized['web_toggle_default_on'] = (isset($raw_settings['web_toggle_default_on']) && $raw_settings['web_toggle_default_on'] === '1') ? '1' : '0';
    $sanitized['show_sources'] = isset($raw_settings['show_sources'])
        ? (($raw_settings['show_sources'] === '1') ? '1' : '0')
        : BotSettingsManager::DEFAULT_SHOW_SOURCES;
    $sanitized['sources_label'] = isset($raw_settings['sources_label'])
        ? sanitize_text_field((string) $raw_settings['sources_label'])
        : BotSettingsManager::DEFAULT_SOURCES_LABEL;
    $sanitized['searching_web_text'] = isset($raw_settings['searching_web_text'])
        ? sanitize_text_field((string) $raw_settings['searching_web_text'])
        : BotSettingsManager::DEFAULT_SEARCHING_WEB_TEXT;

    if (function_exists(__NAMESPACE__ . '\sanitize_voice_agent_settings_logic')) {
        $sanitized = array_merge($sanitized, sanitize_voice_agent_settings_logic($raw_settings));
    }

    $sanitized['popup_label_enabled'] = (isset($raw_settings['popup_label_enabled']) && $raw_settings['popup_label_enabled'] === '1') ? '1' : '0';
    $raw_popup_label_mode = isset($raw_settings['popup_label_mode']) ? sanitize_key((string) $raw_settings['popup_label_mode']) : '';
    $legacy_mode_map = [
        'delay_once' => 'on_delay',
        'delay_always' => 'on_delay',
        'immediate_once' => 'always',
        'immediate_always' => 'always',
        'manual' => 'until_dismissed',
    ];
    $legacy_frequency_map = [
        'delay_once' => 'once_per_visitor',
        'delay_always' => 'always',
        'immediate_once' => 'once_per_visitor',
        'immediate_always' => 'always',
    ];
    $normalized_popup_label_mode = $legacy_mode_map[$raw_popup_label_mode] ?? $raw_popup_label_mode;
    $allowed_modes = ['always','on_delay','until_open','until_dismissed'];
    $sanitized['popup_label_mode'] = in_array($normalized_popup_label_mode, $allowed_modes, true)
        ? $normalized_popup_label_mode
        : 'on_delay';
    $sanitized['popup_label_text'] = isset($raw_settings['popup_label_text']) ? sanitize_text_field($raw_settings['popup_label_text']) : '';
    $sanitized['popup_label_delay_seconds'] = isset($raw_settings['popup_label_delay_seconds']) ? max(0, absint($raw_settings['popup_label_delay_seconds'])) : BotSettingsManager::DEFAULT_POPUP_LABEL_DELAY_SECONDS;
    $sanitized['popup_label_auto_hide_seconds'] = isset($raw_settings['popup_label_auto_hide_seconds']) ? max(0, absint($raw_settings['popup_label_auto_hide_seconds'])) : 0;
    $sanitized['popup_label_dismissible'] = (isset($raw_settings['popup_label_dismissible']) && $raw_settings['popup_label_dismissible'] === '1') ? '1' : '0';
    $allowed_freq = ['always','once_per_session','once_per_visitor'];
    $raw_popup_label_frequency = isset($raw_settings['popup_label_frequency']) ? sanitize_key((string) $raw_settings['popup_label_frequency']) : '';
    if ($raw_popup_label_frequency === '' && isset($legacy_frequency_map[$raw_popup_label_mode])) {
        $raw_popup_label_frequency = $legacy_frequency_map[$raw_popup_label_mode];
    }
    $sanitized['popup_label_frequency'] = in_array($raw_popup_label_frequency, $allowed_freq, true)
        ? $raw_popup_label_frequency
        : 'once_per_visitor';
    $sanitized['popup_label_show_on_mobile'] = (isset($raw_settings['popup_label_show_on_mobile']) && $raw_settings['popup_label_show_on_mobile'] === '1') ? '1' : '0';
    $sanitized['popup_label_show_on_desktop'] = (isset($raw_settings['popup_label_show_on_desktop']) && $raw_settings['popup_label_show_on_desktop'] === '1') ? '1' : '0';
    $sanitized['popup_label_version'] = isset($raw_settings['popup_label_version']) ? sanitize_text_field($raw_settings['popup_label_version']) : '';
    $allowed_sizes = ['small','medium','large','xlarge'];
    $sanitized['popup_label_size'] = isset($raw_settings['popup_label_size']) && in_array($raw_settings['popup_label_size'], $allowed_sizes, true)
        ? $raw_settings['popup_label_size']
        : BotSettingsManager::DEFAULT_POPUP_LABEL_SIZE;

    $raw_domains = isset($raw_settings['embed_allowed_domains']) ? trim($raw_settings['embed_allowed_domains']) : '';
    $domains_array = preg_split('/[\s,]+/', $raw_domains, -1, PREG_SPLIT_NO_EMPTY);
    $sanitized_domains = [];
    foreach ($domains_array as $domain) {
        $sanitized_url = esc_url_raw(trim($domain));
        if (!empty($sanitized_url)) {
            $sanitized_domains[] = rtrim($sanitized_url, '/');
        }
    }
    $sanitized['embed_allowed_domains'] = implode("\n", array_unique($sanitized_domains));

    // Sanitize Custom Theme Settings
    $custom_theme_settings_raw = $raw_settings['custom_theme_settings'] ?? [];
    $custom_theme_settings_sanitized = [];

    foreach (array_keys($custom_theme_defaults) as $key) {
        if (strpos($key, '_placeholder') !== false) {
            continue;
        }

        $value = $custom_theme_settings_raw[$key] ?? '';
        if (substr_compare($key, '_color', -strlen('_color')) === 0) {
            if (preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/', $value) || preg_match('/^rgba?\((\d{1,3}%?,\s*){2,3}\d{1,3}%?(,\s*(0|1|0?\.\d+))?\)$/', $value)) {
                $custom_theme_settings_sanitized[$key] = $value;
            } else {
                $custom_theme_settings_sanitized[$key] = $custom_theme_defaults[$key] ?? '#FFFFFF';
            }
        } elseif ($key === 'font_family') {
            $allowed_fonts = [
                '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif',
                'Arial, Helvetica, sans-serif', 'Verdana, Geneva, sans-serif', 'Tahoma, Geneva, sans-serif',
                '"Trebuchet MS", Helvetica, sans-serif', '"Times New Roman", Times, serif', 'Georgia, serif',
                'Garamond, serif', '"Courier New", Courier, monospace', '"Brush Script MT", cursive', 'inherit'
            ];
            $custom_theme_settings_sanitized[$key] = in_array($value, $allowed_fonts, true) ? $value : ($custom_theme_defaults['font_family'] ?? 'inherit');
        } elseif ($key === 'bubble_border_radius' ||
                   $key === 'container_max_width' ||
                   $key === 'popup_width' ||
                   $key === 'container_height' ||
                   $key === 'container_min_height' ||
                   $key === 'popup_height' ||
                   $key === 'popup_min_height'
        ) {
            $custom_theme_settings_sanitized[$key] = ($value === '' || !is_numeric($value)) ? '' : (string)max(0, absint($value));
        } elseif ($key === 'container_max_height' || $key === 'popup_max_height') {
            $custom_theme_settings_sanitized[$key] = ($value === '' || !is_numeric($value)) ? '' : (string)max(1, min(absint($value), 100));
        } else {
            $custom_theme_settings_sanitized[$key] = sanitize_text_field($value);
        }
    }
    $sanitized['custom_theme_settings'] = $custom_theme_settings_sanitized;

    if (function_exists(__NAMESPACE__ . '\save_trigger_settings_logic')) {
        $sanitized['triggers_json'] = isset($raw_settings['triggers_json']) ? trim(wp_unslash($raw_settings['triggers_json'])) : '[]';
    }

    return $sanitized;
}

// --- handle-site-wide-logic.php ---
/**
 * Handles site-wide bot uniqueness and reports whether cache invalidation is needed.
 *
 * @param SiteWideBotManager $site_wide_manager The SiteWideBotManager instance.
 * @param int $botId The ID of the bot being saved.
 * @param string $site_wide_enabled_flag '0' or '1' indicating if site-wide is enabled for this bot.
 * @return bool Whether the site-wide cache should be cleared after meta is saved.
 */
function handle_site_wide_logic(SiteWideBotManager $site_wide_manager, int $botId, string $site_wide_enabled_flag): bool {
    return $site_wide_manager->ensure_site_wide_uniqueness($botId, $site_wide_enabled_flag === '1');
}

// --- save-meta-fields-logic.php ---
/**
 * Saves the sanitized bot settings as post meta fields.
 * UPDATED: Saves new custom theme settings.
 * NEW: Saves triggers JSON using AIPKit_Trigger_Storage::META_KEY.
 *
 * @param int $botId The ID of the bot post.
 * @param array $sanitized_settings The array of sanitized settings.
 * @return bool|WP_Error Returns true on success, or WP_Error if JSON for triggers is invalid.
 */
function save_meta_fields_logic(int $botId, array $sanitized_settings)
{
    update_post_meta($botId, '_aipkit_greeting_message', $sanitized_settings['greeting']);
    update_post_meta($botId, '_aipkit_subgreeting_message', $sanitized_settings['subgreeting']);
    update_post_meta($botId, '_aipkit_provider', $sanitized_settings['provider']);
    update_post_meta($botId, '_aipkit_theme', $sanitized_settings['theme']);
    if (
        ($sanitized_settings['theme'] ?? '') === 'custom' &&
        !empty($sanitized_settings['theme_preset_key'])
    ) {
        update_post_meta($botId, '_aipkit_theme_preset_key', $sanitized_settings['theme_preset_key']);
    } else {
        delete_post_meta($botId, '_aipkit_theme_preset_key');
    }
    update_post_meta($botId, '_aipkit_theme_dimension_overrides', $sanitized_settings['theme_dimension_overrides'] ?? '');
    update_post_meta($botId, '_aipkit_instructions', $sanitized_settings['instructions']);
    update_post_meta($botId, '_aipkit_deploy_mode', $sanitized_settings['deploy_mode']);
    update_post_meta($botId, '_aipkit_popup_enabled', $sanitized_settings['popup_enabled']);
    update_post_meta($botId, '_aipkit_popup_position', $sanitized_settings['popup_position']);
    update_post_meta($botId, '_aipkit_popup_delay', $sanitized_settings['popup_delay']);
    update_post_meta($botId, '_aipkit_site_wide_enabled', $sanitized_settings['site_wide_enabled']);
    update_post_meta($botId, '_aipkit_popup_icon_size', $sanitized_settings['popup_icon_size']);
    update_post_meta($botId, '_aipkit_popup_icon_type', $sanitized_settings['popup_icon_type']);
    update_post_meta($botId, '_aipkit_popup_icon_style', $sanitized_settings['popup_icon_style']);
    update_post_meta($botId, '_aipkit_popup_icon_value', $sanitized_settings['popup_icon_value']);
    update_post_meta($botId, '_aipkit_footer_text', $sanitized_settings['footer_text']);
    update_post_meta($botId, '_aipkit_header_avatar_type', $sanitized_settings['header_avatar_type']);
    update_post_meta($botId, '_aipkit_header_avatar_value', $sanitized_settings['header_avatar_value']);
    update_post_meta($botId, '_aipkit_header_avatar_url', $sanitized_settings['header_avatar_url']);
    update_post_meta($botId, '_aipkit_header_online_text', $sanitized_settings['header_online_text']);
    update_post_meta($botId, '_aipkit_enable_fullscreen', $sanitized_settings['enable_fullscreen']);
    update_post_meta($botId, '_aipkit_enable_download', $sanitized_settings['enable_download']);
    update_post_meta($botId, '_aipkit_enable_copy_button', $sanitized_settings['enable_copy_button']);
    update_post_meta($botId, '_aipkit_enable_feedback', $sanitized_settings['enable_feedback']);
    update_post_meta($botId, '_aipkit_enable_consent_compliance', $sanitized_settings['enable_consent_compliance']);
    update_post_meta($botId, '_aipkit_consent_title', $sanitized_settings['consent_title']);
    update_post_meta($botId, '_aipkit_consent_message', $sanitized_settings['consent_message']);
    update_post_meta($botId, '_aipkit_consent_button', $sanitized_settings['consent_button']);
    update_post_meta($botId, '_aipkit_enable_conversation_sidebar', $sanitized_settings['enable_conversation_sidebar']);
    update_post_meta($botId, '_aipkit_custom_typing_text', $sanitized_settings['custom_typing_text']);
    update_post_meta($botId, '_aipkit_retrieving_context_text', $sanitized_settings['retrieving_context_text']);
    update_post_meta($botId, '_aipkit_input_placeholder', $sanitized_settings['input_placeholder']);
    update_post_meta($botId, '_aipkit_temperature', (string)$sanitized_settings['temperature']);
    update_post_meta($botId, '_aipkit_max_completion_tokens', $sanitized_settings['max_completion_tokens']);
    update_post_meta($botId, '_aipkit_max_messages', $sanitized_settings['max_messages']);
    update_post_meta($botId, '_aipkit_reasoning_effort', $sanitized_settings['reasoning_effort']);
    update_post_meta($botId, '_aipkit_enable_conversation_starters', $sanitized_settings['enable_conversation_starters']);
    update_post_meta(
        $botId,
        '_aipkit_conversation_starters',
        wp_slash($sanitized_settings['conversation_starters'])
    );
    update_post_meta($botId, '_aipkit_content_aware_enabled', $sanitized_settings['content_aware_enabled']);
    update_post_meta($botId, '_aipkit_openai_conversation_state_enabled', $sanitized_settings['openai_conversation_state_enabled']);
    update_post_meta($botId, '_aipkit_google_conversation_state_enabled', $sanitized_settings['google_conversation_state_enabled']);
    update_post_meta($botId, '_aipkit_openrouter_session_stickiness', $sanitized_settings['openrouter_session_stickiness']);
    update_post_meta($botId, '_aipkit_token_limit_mode', $sanitized_settings['token_limit_mode']);
    delete_post_meta($botId, '_aipkit_token_pricing_mode');
    if ($sanitized_settings['token_guest_limit'] === '') {
        delete_post_meta($botId, '_aipkit_token_guest_limit');
    } else {
        update_post_meta($botId, '_aipkit_token_guest_limit', $sanitized_settings['token_guest_limit']);
    }
    if ($sanitized_settings['token_user_limit'] === '') {
        delete_post_meta($botId, '_aipkit_token_user_limit');
    } else {
        update_post_meta($botId, '_aipkit_token_user_limit', $sanitized_settings['token_user_limit']);
    }
    if (empty(json_decode($sanitized_settings['token_role_limits'], true))) {
        delete_post_meta($botId, '_aipkit_token_role_limits');
    } else {
        update_post_meta($botId, '_aipkit_token_role_limits', $sanitized_settings['token_role_limits']);
    }
    update_post_meta($botId, '_aipkit_token_reset_period', $sanitized_settings['token_reset_period']);
    if (empty($sanitized_settings['token_limit_message'])) {
        delete_post_meta($botId, '_aipkit_token_limit_message');
    } else {
        update_post_meta($botId, '_aipkit_token_limit_message', $sanitized_settings['token_limit_message']);
    }
    update_post_meta($botId, '_aipkit_token_limit_primary_action_type', $sanitized_settings['token_limit_primary_action_type']);
    if (empty($sanitized_settings['token_limit_primary_action_label'])) {
        delete_post_meta($botId, '_aipkit_token_limit_primary_action_label');
    } else {
        update_post_meta($botId, '_aipkit_token_limit_primary_action_label', $sanitized_settings['token_limit_primary_action_label']);
    }
    if (empty($sanitized_settings['token_limit_primary_action_url'])) {
        delete_post_meta($botId, '_aipkit_token_limit_primary_action_url');
    } else {
        update_post_meta($botId, '_aipkit_token_limit_primary_action_url', $sanitized_settings['token_limit_primary_action_url']);
    }
    update_post_meta($botId, '_aipkit_token_limit_secondary_action_type', $sanitized_settings['token_limit_secondary_action_type']);
    if (empty($sanitized_settings['token_limit_secondary_action_label'])) {
        delete_post_meta($botId, '_aipkit_token_limit_secondary_action_label');
    } else {
        update_post_meta($botId, '_aipkit_token_limit_secondary_action_label', $sanitized_settings['token_limit_secondary_action_label']);
    }
    if (empty($sanitized_settings['token_limit_secondary_action_url'])) {
        delete_post_meta($botId, '_aipkit_token_limit_secondary_action_url');
    } else {
        update_post_meta($botId, '_aipkit_token_limit_secondary_action_url', $sanitized_settings['token_limit_secondary_action_url']);
    }
    if (!empty($sanitized_settings['model'])) {
        update_post_meta($botId, '_aipkit_model', $sanitized_settings['model']);
    } else {
        delete_post_meta($botId, '_aipkit_model');
    }
    delete_post_meta($botId, '_aipkit_azure_endpoint');
    delete_post_meta($botId, '_aipkit_azure_deployment');
    update_post_meta($botId, '_aipkit_tts_enabled', $sanitized_settings['tts_enabled']);
    update_post_meta($botId, '_aipkit_tts_provider', $sanitized_settings['tts_provider']);
    update_post_meta($botId, '_aipkit_tts_cloud_voice_id', $sanitized_settings['tts_cloud_voice_id']);
    update_post_meta($botId, '_aipkit_tts_cloud_model_id', $sanitized_settings['tts_cloud_model_id']);
    update_post_meta($botId, '_aipkit_tts_google_voice_id', $sanitized_settings['tts_google_voice_id']);
    update_post_meta($botId, '_aipkit_tts_google_model_id', $sanitized_settings['tts_google_model_id']);
    update_post_meta($botId, '_aipkit_tts_openai_voice_id', $sanitized_settings['tts_openai_voice_id']);
    update_post_meta($botId, '_aipkit_tts_openai_model_id', $sanitized_settings['tts_openai_model_id']);
    update_post_meta($botId, '_aipkit_tts_elevenlabs_voice_id', $sanitized_settings['tts_elevenlabs_voice_id']);
    update_post_meta($botId, '_aipkit_tts_elevenlabs_model_id', $sanitized_settings['tts_elevenlabs_model_id']);
    update_post_meta($botId, '_aipkit_tts_auto_play', $sanitized_settings['tts_auto_play']);
    update_post_meta($botId, '_aipkit_enable_voice_input', $sanitized_settings['enable_voice_input']);
    update_post_meta($botId, '_aipkit_stt_provider', $sanitized_settings['stt_provider']);
    update_post_meta($botId, '_aipkit_stt_openai_model_id', $sanitized_settings['stt_openai_model_id']);
    update_post_meta($botId, '_aipkit_stt_google_model_id', $sanitized_settings['stt_google_model_id']);
    update_post_meta($botId, '_aipkit_stt_cloud_model_id', $sanitized_settings['stt_cloud_model_id']);
    update_post_meta($botId, '_aipkit_stt_azure_model_id', $sanitized_settings['stt_azure_model_id']);
    update_post_meta($botId, '_aipkit_image_triggers', $sanitized_settings['image_triggers']);
    update_post_meta($botId, '_aipkit_chat_image_model_id', $sanitized_settings['chat_image_model_id']);
    update_post_meta($botId, '_aipkit_enable_image_generation', $sanitized_settings['enable_image_generation']);
    update_post_meta($botId, '_aipkit_enable_file_upload', $sanitized_settings['enable_file_upload']);
    update_post_meta($botId, '_aipkit_enable_image_upload', $sanitized_settings['enable_image_upload']);
    update_post_meta($botId, '_aipkit_enable_vector_store', $sanitized_settings['enable_vector_store']);
    update_post_meta($botId, '_aipkit_vector_store_provider', $sanitized_settings['vector_store_provider']);
    if ($sanitized_settings['vector_store_provider'] === 'openai') {
        update_post_meta($botId, '_aipkit_openai_vector_store_ids', $sanitized_settings['openai_vector_store_ids']);
        delete_post_meta($botId, '_aipkit_pinecone_index_name');
        delete_post_meta($botId, '_aipkit_qdrant_collection_name');
        delete_post_meta($botId, '_aipkit_qdrant_collection_names');
        delete_post_meta($botId, '_aipkit_chroma_collection_name');
        delete_post_meta($botId, '_aipkit_chroma_collection_names');
        delete_post_meta($botId, '_aipkit_vector_embedding_provider');
        delete_post_meta($botId, '_aipkit_vector_embedding_model');
        delete_post_meta($botId, '_aipkit_google_file_search_store_names');
    } elseif ($sanitized_settings['vector_store_provider'] === 'google') {
        update_post_meta($botId, '_aipkit_google_file_search_store_names', $sanitized_settings['google_file_search_store_names']);
        delete_post_meta($botId, '_aipkit_openai_vector_store_ids');
        delete_post_meta($botId, '_aipkit_pinecone_index_name');
        delete_post_meta($botId, '_aipkit_qdrant_collection_name');
        delete_post_meta($botId, '_aipkit_qdrant_collection_names');
        delete_post_meta($botId, '_aipkit_chroma_collection_name');
        delete_post_meta($botId, '_aipkit_chroma_collection_names');
        delete_post_meta($botId, '_aipkit_vector_embedding_provider');
        delete_post_meta($botId, '_aipkit_vector_embedding_model');
    } elseif ($sanitized_settings['vector_store_provider'] === 'pinecone') {
        update_post_meta($botId, '_aipkit_pinecone_index_name', $sanitized_settings['pinecone_index_name']);
        update_post_meta($botId, '_aipkit_vector_embedding_provider', $sanitized_settings['vector_embedding_provider']);
        update_post_meta($botId, '_aipkit_vector_embedding_model', $sanitized_settings['vector_embedding_model']);
        delete_post_meta($botId, '_aipkit_openai_vector_store_ids');
        delete_post_meta($botId, '_aipkit_qdrant_collection_name');
        delete_post_meta($botId, '_aipkit_qdrant_collection_names');
        delete_post_meta($botId, '_aipkit_chroma_collection_name');
        delete_post_meta($botId, '_aipkit_chroma_collection_names');
        delete_post_meta($botId, '_aipkit_google_file_search_store_names');
    } elseif ($sanitized_settings['vector_store_provider'] === 'qdrant') {
        update_post_meta($botId, '_aipkit_qdrant_collection_name', $sanitized_settings['qdrant_collection_name']);
        update_post_meta($botId, '_aipkit_qdrant_collection_names', $sanitized_settings['qdrant_collection_names']);
        update_post_meta($botId, '_aipkit_vector_embedding_provider', $sanitized_settings['vector_embedding_provider']);
        update_post_meta($botId, '_aipkit_vector_embedding_model', $sanitized_settings['vector_embedding_model']);
        delete_post_meta($botId, '_aipkit_openai_vector_store_ids');
        delete_post_meta($botId, '_aipkit_pinecone_index_name');
        delete_post_meta($botId, '_aipkit_chroma_collection_name');
        delete_post_meta($botId, '_aipkit_chroma_collection_names');
        delete_post_meta($botId, '_aipkit_google_file_search_store_names');
    } elseif ($sanitized_settings['vector_store_provider'] === 'local') {
        // Store selection and embedding configuration are independent.
        update_post_meta($botId, '_aipkit_local_store_ids', $sanitized_settings['local_store_ids']);
        update_post_meta($botId, '_aipkit_vector_embedding_provider', $sanitized_settings['vector_embedding_provider']);
        update_post_meta($botId, '_aipkit_vector_embedding_model', $sanitized_settings['vector_embedding_model']);
    } elseif ($sanitized_settings['vector_store_provider'] === 'chroma') {
        update_post_meta($botId, '_aipkit_chroma_collection_name', $sanitized_settings['chroma_collection_name']);
        update_post_meta($botId, '_aipkit_chroma_collection_names', $sanitized_settings['chroma_collection_names']);
        update_post_meta($botId, '_aipkit_vector_embedding_provider', $sanitized_settings['vector_embedding_provider']);
        update_post_meta($botId, '_aipkit_vector_embedding_model', $sanitized_settings['vector_embedding_model']);
        delete_post_meta($botId, '_aipkit_openai_vector_store_ids');
        delete_post_meta($botId, '_aipkit_pinecone_index_name');
        delete_post_meta($botId, '_aipkit_qdrant_collection_name');
        delete_post_meta($botId, '_aipkit_qdrant_collection_names');
        delete_post_meta($botId, '_aipkit_google_file_search_store_names');
    } else {
        delete_post_meta($botId, '_aipkit_openai_vector_store_ids');
        delete_post_meta($botId, '_aipkit_pinecone_index_name');
        delete_post_meta($botId, '_aipkit_qdrant_collection_name');
        delete_post_meta($botId, '_aipkit_qdrant_collection_names');
        delete_post_meta($botId, '_aipkit_chroma_collection_name');
        delete_post_meta($botId, '_aipkit_chroma_collection_names');
        delete_post_meta($botId, '_aipkit_vector_embedding_provider');
        delete_post_meta($botId, '_aipkit_vector_embedding_model');
        delete_post_meta($botId, '_aipkit_google_file_search_store_names');
    }
    update_post_meta($botId, '_aipkit_vector_store_top_k', $sanitized_settings['vector_store_top_k']);
    update_post_meta($botId, '_aipkit_vector_store_confidence_threshold', $sanitized_settings['vector_store_confidence_threshold']); // NEW
    update_post_meta($botId, '_aipkit_openai_web_search_enabled', $sanitized_settings['openai_web_search_enabled']);
    if ($sanitized_settings['openai_web_search_enabled'] === '1') {
        update_post_meta($botId, '_aipkit_openai_web_search_context_size', $sanitized_settings['openai_web_search_context_size']);
        update_post_meta($botId, '_aipkit_openai_web_search_loc_type', $sanitized_settings['openai_web_search_loc_type']);
        if ($sanitized_settings['openai_web_search_loc_type'] === 'approximate') {
            update_post_meta($botId, '_aipkit_openai_web_search_loc_country', $sanitized_settings['openai_web_search_loc_country']);
            update_post_meta($botId, '_aipkit_openai_web_search_loc_city', $sanitized_settings['openai_web_search_loc_city']);
            update_post_meta($botId, '_aipkit_openai_web_search_loc_region', $sanitized_settings['openai_web_search_loc_region']);
            update_post_meta($botId, '_aipkit_openai_web_search_loc_timezone', $sanitized_settings['openai_web_search_loc_timezone']);
        } else {
            delete_post_meta($botId, '_aipkit_openai_web_search_loc_country');
            delete_post_meta($botId, '_aipkit_openai_web_search_loc_city');
            delete_post_meta($botId, '_aipkit_openai_web_search_loc_region');
            delete_post_meta($botId, '_aipkit_openai_web_search_loc_timezone');
        }
    } else {
        delete_post_meta($botId, '_aipkit_openai_web_search_context_size');
        delete_post_meta($botId, '_aipkit_openai_web_search_loc_type');
        delete_post_meta($botId, '_aipkit_openai_web_search_loc_country');
        delete_post_meta($botId, '_aipkit_openai_web_search_loc_city');
        delete_post_meta($botId, '_aipkit_openai_web_search_loc_region');
        delete_post_meta($botId, '_aipkit_openai_web_search_loc_timezone');
    }
    update_post_meta($botId, '_aipkit_claude_web_search_enabled', $sanitized_settings['claude_web_search_enabled']);
    if ($sanitized_settings['claude_web_search_enabled'] === '1') {
        update_post_meta($botId, '_aipkit_claude_web_search_max_uses', (string) $sanitized_settings['claude_web_search_max_uses']);
        update_post_meta($botId, '_aipkit_claude_web_search_loc_type', $sanitized_settings['claude_web_search_loc_type']);
        if ($sanitized_settings['claude_web_search_loc_type'] === 'approximate') {
            update_post_meta($botId, '_aipkit_claude_web_search_loc_country', $sanitized_settings['claude_web_search_loc_country']);
            update_post_meta($botId, '_aipkit_claude_web_search_loc_city', $sanitized_settings['claude_web_search_loc_city']);
            update_post_meta($botId, '_aipkit_claude_web_search_loc_region', $sanitized_settings['claude_web_search_loc_region']);
            update_post_meta($botId, '_aipkit_claude_web_search_loc_timezone', $sanitized_settings['claude_web_search_loc_timezone']);
        } else {
            delete_post_meta($botId, '_aipkit_claude_web_search_loc_country');
            delete_post_meta($botId, '_aipkit_claude_web_search_loc_city');
            delete_post_meta($botId, '_aipkit_claude_web_search_loc_region');
            delete_post_meta($botId, '_aipkit_claude_web_search_loc_timezone');
        }
        if (!empty($sanitized_settings['claude_web_search_allowed_domains'])) {
            update_post_meta($botId, '_aipkit_claude_web_search_allowed_domains', $sanitized_settings['claude_web_search_allowed_domains']);
            delete_post_meta($botId, '_aipkit_claude_web_search_blocked_domains');
        } elseif (!empty($sanitized_settings['claude_web_search_blocked_domains'])) {
            update_post_meta($botId, '_aipkit_claude_web_search_blocked_domains', $sanitized_settings['claude_web_search_blocked_domains']);
            delete_post_meta($botId, '_aipkit_claude_web_search_allowed_domains');
        } else {
            delete_post_meta($botId, '_aipkit_claude_web_search_allowed_domains');
            delete_post_meta($botId, '_aipkit_claude_web_search_blocked_domains');
        }
        update_post_meta($botId, '_aipkit_claude_web_search_cache_ttl', $sanitized_settings['claude_web_search_cache_ttl']);
    } else {
        delete_post_meta($botId, '_aipkit_claude_web_search_max_uses');
        delete_post_meta($botId, '_aipkit_claude_web_search_loc_type');
        delete_post_meta($botId, '_aipkit_claude_web_search_loc_country');
        delete_post_meta($botId, '_aipkit_claude_web_search_loc_city');
        delete_post_meta($botId, '_aipkit_claude_web_search_loc_region');
        delete_post_meta($botId, '_aipkit_claude_web_search_loc_timezone');
        delete_post_meta($botId, '_aipkit_claude_web_search_allowed_domains');
        delete_post_meta($botId, '_aipkit_claude_web_search_blocked_domains');
        delete_post_meta($botId, '_aipkit_claude_web_search_cache_ttl');
    }
    update_post_meta($botId, '_aipkit_openrouter_web_search_enabled', $sanitized_settings['openrouter_web_search_enabled']);
    if ($sanitized_settings['openrouter_web_search_enabled'] === '1') {
        update_post_meta($botId, '_aipkit_openrouter_web_search_engine', $sanitized_settings['openrouter_web_search_engine']);
        update_post_meta($botId, '_aipkit_openrouter_web_search_max_results', (string) $sanitized_settings['openrouter_web_search_max_results']);
        update_post_meta($botId, '_aipkit_openrouter_web_search_max_uses', (string) $sanitized_settings['openrouter_web_search_max_uses']);
        update_post_meta($botId, '_aipkit_openrouter_web_search_max_total_results', (string) $sanitized_settings['openrouter_web_search_max_total_results']);
        update_post_meta($botId, '_aipkit_openrouter_web_search_context_size', $sanitized_settings['openrouter_web_search_context_size']);
        update_post_meta($botId, '_aipkit_openrouter_web_search_allowed_domains', $sanitized_settings['openrouter_web_search_allowed_domains']);
        update_post_meta($botId, '_aipkit_openrouter_web_search_excluded_domains', $sanitized_settings['openrouter_web_search_excluded_domains']);
        delete_post_meta($botId, '_aipkit_openrouter_web_search_search_prompt');
    } else {
        delete_post_meta($botId, '_aipkit_openrouter_web_search_engine');
        delete_post_meta($botId, '_aipkit_openrouter_web_search_max_results');
        delete_post_meta($botId, '_aipkit_openrouter_web_search_max_uses');
        delete_post_meta($botId, '_aipkit_openrouter_web_search_max_total_results');
        delete_post_meta($botId, '_aipkit_openrouter_web_search_context_size');
        delete_post_meta($botId, '_aipkit_openrouter_web_search_allowed_domains');
        delete_post_meta($botId, '_aipkit_openrouter_web_search_excluded_domains');
        delete_post_meta($botId, '_aipkit_openrouter_web_search_search_prompt');
    }
    update_post_meta($botId, '_aipkit_xai_web_search_enabled', $sanitized_settings['xai_web_search_enabled']);
    update_post_meta($botId, '_aipkit_google_search_grounding_enabled', $sanitized_settings['google_search_grounding_enabled']);
    update_post_meta($botId, '_aipkit_web_toggle_default_on', $sanitized_settings['web_toggle_default_on']);
    update_post_meta($botId, '_aipkit_show_sources', $sanitized_settings['show_sources']);
    update_post_meta($botId, '_aipkit_sources_label', $sanitized_settings['sources_label']);
    update_post_meta($botId, '_aipkit_searching_web_text', $sanitized_settings['searching_web_text']);

    if (isset($sanitized_settings['custom_theme_settings']) && is_array($sanitized_settings['custom_theme_settings'])) {
        foreach ($sanitized_settings['custom_theme_settings'] as $key => $value) {
            update_post_meta($botId, '_aipkit_cts_' . $key, $value);
        }
        if (class_exists(\WPAICG\Chat\Storage\BotSettingsManager::class)) {
            \WPAICG\Chat\Storage\BotSettingsManager::cleanup_custom_theme_meta($botId);
        }
    }

    if (function_exists(__NAMESPACE__ . '\save_voice_agent_settings_logic')) {
        save_voice_agent_settings_logic($botId, $sanitized_settings);
    }

    update_post_meta($botId, '_aipkit_popup_label_enabled', $sanitized_settings['popup_label_enabled']);
    update_post_meta($botId, '_aipkit_popup_label_text', $sanitized_settings['popup_label_text']);
    update_post_meta($botId, '_aipkit_popup_label_mode', $sanitized_settings['popup_label_mode']);
    update_post_meta($botId, '_aipkit_popup_label_delay_seconds', $sanitized_settings['popup_label_delay_seconds']);
    update_post_meta($botId, '_aipkit_popup_label_auto_hide_seconds', $sanitized_settings['popup_label_auto_hide_seconds']);
    update_post_meta($botId, '_aipkit_popup_label_dismissible', $sanitized_settings['popup_label_dismissible']);
    update_post_meta($botId, '_aipkit_popup_label_frequency', $sanitized_settings['popup_label_frequency']);
    update_post_meta($botId, '_aipkit_popup_label_show_on_mobile', $sanitized_settings['popup_label_show_on_mobile']);
    update_post_meta($botId, '_aipkit_popup_label_show_on_desktop', $sanitized_settings['popup_label_show_on_desktop']);
    update_post_meta($botId, '_aipkit_popup_label_version', $sanitized_settings['popup_label_version']);
    update_post_meta($botId, '_aipkit_popup_label_size', $sanitized_settings['popup_label_size']);

    update_post_meta($botId, '_aipkit_embed_allowed_domains', $sanitized_settings['embed_allowed_domains']);

    if (function_exists(__NAMESPACE__ . '\save_trigger_settings_logic')) {
        return save_trigger_settings_logic($botId, $sanitized_settings);
    }

    return true;
}

// --- handle-openai-specific-settings-logic.php ---
/**
 * Handles OpenAI-specific settings, such as ensuring global 'store_conversation' is true
 * if the bot's conversation state is enabled.
 *
 * @param int $botId The ID of the bot.
 * @param array $sanitized_settings The array of sanitized settings for the bot.
 * @return void
 */
function handle_provider_conversation_state_settings_logic(array $sanitized_settings): void
{
    $provider = $sanitized_settings['provider'] ?? '';
    $state_setting = $provider === 'OpenAI'
        ? 'openai_conversation_state_enabled'
        : ($provider === 'Google' ? 'google_conversation_state_enabled' : '');
    if ($state_setting === '' || ($sanitized_settings[$state_setting] ?? '0') !== '1') {
        return;
    }
    if (class_exists(\WPAICG\AIPKit_Providers::class)) {
        $provider_settings = AIPKit_Providers::get_provider_data($provider);
        if (($provider_settings['store_conversation'] ?? '0') !== '1') {
            $provider_settings['store_conversation'] = '1';
            AIPKit_Providers::save_provider_data($provider, $provider_settings);
        }
    }
}

// --- save-bot-settings-logic.php ---
// Ensure the new method logic files are loaded

/**
 * Orchestrates the saving of bot settings.
 * MODIFIED: Now handles WP_Error from save_meta_fields_logic and clears site-wide cache
 * only after the updated popup/site-wide meta has been persisted.
 *
 * @param int $botId The chatbot post ID.
 * @param array $raw_settings The raw settings array from the form (e.g., $_POST).
 * @param SiteWideBotManager $site_wide_manager The SiteWideBotManager instance.
 * @return bool|WP_Error True on success, WP_Error on failure.
 */
function save_bot_settings_logic(int $botId, array $raw_settings, SiteWideBotManager $site_wide_manager) {
    // 1. Validate Bot Post
    $validation_result = validate_bot_post_logic($botId);
    if (is_wp_error($validation_result)) {
        return $validation_result;
    }

    $coordinated_settings = coordinate_native_knowledge_settings_logic(
        $raw_settings,
        $botId
    );
    if (is_wp_error($coordinated_settings)) {
        return $coordinated_settings;
    }
    $raw_settings = $coordinated_settings;

    // 2. Sanitize Settings
    $sanitized_settings = sanitize_settings_logic($raw_settings, $botId);

    // 3. Handle Site-Wide Logic (before saving meta, so uniqueness can be enforced first)
    $should_clear_site_wide_cache = handle_site_wide_logic(
        $site_wide_manager,
        $botId,
        $sanitized_settings['site_wide_enabled']
    );

    // 4. Save Meta Fields
    // This function can now return WP_Error
    $meta_save_result = save_meta_fields_logic($botId, $sanitized_settings);
    if (is_wp_error($meta_save_result)) {
        return $meta_save_result; // Propagate WP_Error if JSON validation failed for triggers
    }

    delete_post_meta($botId, '_aipkit_initial_feature_defaults');

    // 5. Session memory requires provider-side storage for both OpenAI and Google.
    handle_provider_conversation_state_settings_logic($sanitized_settings);

    if ($should_clear_site_wide_cache) {
        $site_wide_manager->clear_site_wide_cache();
    }

    // Action hook after settings are saved
    do_action('aipkit_after_bot_settings_saved', $botId, $sanitized_settings);

    return true;
}
