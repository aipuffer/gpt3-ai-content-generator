<?php

namespace WPAICG\Chat\Admin\Ajax;

use WPAICG\Chat\Storage\BotStorage;
use WPAICG\Chat\Storage\DefaultBotSetup;
use WPAICG\Chat\Storage\BotSettingsManager;
use WPAICG\Core\AIPKit_OpenAI_Reasoning;
use WPAICG\Chat\Storage\SiteWideBotManager;
use WPAICG\Chat\Frontend\Shortcode;
use WPAICG\Chat\Admin\AdminSetup; // Needed for POST_TYPE constant
use WPAICG\AIPKit_Providers; // Added for updating global provider settings
use WPAICG\Utils\AIPKit_Prompt_Sanitizer;
use WPAICG\Core\Models\AIPKit_Model_Catalog;
use function WPAICG\Chat\Storage\SaverMethods\sanitize_settings_logic;
use WP_Error;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

require_once dirname(__DIR__) . '/runtime-diagnostics.php';

/**
 * Handles AJAX requests for Chatbot CRUD operations and settings.
 * Uses the BotStorage facade.
 */
class ChatbotAjaxHandler extends BaseAjaxHandler
{
    private $bot_storage;

    public function __construct()
    {
        // Ensure BotStorage exists and instantiate
        if (!class_exists(\WPAICG\Chat\Storage\BotStorage::class)) {
            return;
        }
        $this->bot_storage = new BotStorage();

        // Ensure AIPKit_Providers is available for updating global settings
        if (!class_exists(\WPAICG\AIPKit_Providers::class)) {
            $providers_path = WPAICG_PLUGIN_DIR . 'classes/ai/settings.php';
            if (file_exists($providers_path)) {
                require_once $providers_path;
            }
        }
    }

    /**
     * Validate access and resolve an existing chatbot ID from the current AJAX request.
     *
     * @return int Valid chatbot ID, or 0 after sending an error response.
     */
    private function get_validated_chatbot_id_from_request(): int
    {
        $permission_check = $this->check_module_access_permissions('chatbot');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return 0;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled in check_module_access_permissions.
        $bot_id = isset($_POST['bot_id']) ? absint($_POST['bot_id']) : 0;

        if (empty($bot_id)) {
            wp_send_json_error(['message' => __('Invalid Chatbot ID.', 'gpt3-ai-content-generator')], 400);
            return 0;
        }

        if (!class_exists(AdminSetup::class)) {
            wp_send_json_error(['message' => __('Internal server error.', 'gpt3-ai-content-generator')], 500);
            return 0;
        }

        if (get_post_type($bot_id) !== AdminSetup::POST_TYPE || !in_array(get_post_status($bot_id), ['publish', 'draft'], true)) {
            wp_send_json_error(['message' => __('Invalid Chatbot ID.', 'gpt3-ai-content-generator')], 400);
            return 0;
        }

        return $bot_id;
    }

    private function is_pro_plan_active(): bool
    {
        return class_exists('\WPAICG\aipkit_dashboard') && \WPAICG\aipkit_dashboard::is_pro_plan();
    }

    private function get_validated_table_identifier(string $table_name): string
    {
        $table_name = trim($table_name);
        if ($table_name === '' || !preg_match('/^[A-Za-z0-9_]+$/', $table_name)) {
            return '';
        }

        return '`' . $table_name . '`';
    }

    public function ajax_create_chatbot()
    {
        $permission_check = $this->check_module_access_permissions('chatbot');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $botName = isset($_POST['bot_name']) ? sanitize_text_field(wp_unslash($_POST['bot_name'])) : '';
        if (empty($botName)) {
            wp_send_json_error(['message' => __('Chatbot name cannot be empty.', 'gpt3-ai-content-generator')], 400);
            return;
        }

        // Uses facade method
        $result = $this->bot_storage->create_bot($botName);
        if (is_wp_error($result)) {
            $this->send_wp_error($result);
        } else {
            $bot_id = isset($result['bot_id']) ? absint($result['bot_id']) : 0;
            $this->send_saved_bot_state_success(
                $bot_id,
                __('Chatbot created successfully!', 'gpt3-ai-content-generator')
            );
        }
    }

    /**
     * Return chatbot builder state for one bot or all bots.
     * Used by the admin chatbot builder for state-driven bot switching.
     *
     * @return void
     */
    public function ajax_get_chatbot_switch_state()
    {
        $permission_check = $this->check_module_access_permissions('chatbot');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled in check_module_access_permissions.
        $requested_bot_id = isset($_POST['bot_id']) ? absint($_POST['bot_id']) : 0;

        if (!class_exists(DefaultBotSetup::class)) {
            wp_send_json_error(['message' => __('Unable to load chatbot state.', 'gpt3-ai-content-generator')], 500);
            return;
        }

        $default_bot_id = (int) DefaultBotSetup::get_default_bot_id();

        if ($requested_bot_id > 0) {
            if (!class_exists(AdminSetup::class)) {
                wp_send_json_error(['message' => __('Unable to load chatbot state.', 'gpt3-ai-content-generator')], 500);
                return;
            }

            if (
                get_post_type($requested_bot_id) !== AdminSetup::POST_TYPE
                || !in_array(get_post_status($requested_bot_id), ['publish', 'draft'], true)
            ) {
                wp_send_json_error(['message' => __('Invalid Chatbot ID.', 'gpt3-ai-content-generator')], 400);
                return;
            }

            $post = get_post($requested_bot_id);
            if (!$post instanceof \WP_Post) {
                wp_send_json_error(['message' => __('Chatbot not found.', 'gpt3-ai-content-generator')], 404);
                return;
            }

            $settings = $this->bot_storage->get_chatbot_settings($requested_bot_id);
            $state = $this->build_bot_switch_state_payload(
                $requested_bot_id,
                (string) $post->post_title,
                is_array($settings) ? $settings : [],
                $default_bot_id
            );

            wp_send_json_success([
                'bot' => $state,
                'default_bot_id' => $default_bot_id,
            ]);
            return;
        }

        $bots_with_settings = $this->bot_storage->get_chatbots_with_settings();
        $states = [];
        $order = [];

        foreach ($bots_with_settings as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $post = $entry['post'] ?? null;
            $settings = $entry['settings'] ?? [];
            if (!$post instanceof \WP_Post) {
                continue;
            }
            $bot_id = (int) $post->ID;
            if ($bot_id <= 0) {
                continue;
            }
            $order[] = $bot_id;
            $states[(string) $bot_id] = $this->build_bot_switch_state_payload(
                $bot_id,
                (string) $post->post_title,
                is_array($settings) ? $settings : [],
                $default_bot_id
            );
        }

        wp_send_json_success([
            'bots' => $states,
            'order' => $order,
            'default_bot_id' => $default_bot_id,
        ]);
    }

    /**
     * Resolve the persisted deploy mode for a bot.
     *
     * Falls back to legacy popup/inline behavior for bots saved before deploy mode
     * was stored separately.
     *
     * @param string|null $stored_mode Raw stored deploy mode.
     * @param array       $settings    Bot settings used for fallback inference.
     * @return string
     */
    private function resolve_deploy_mode(?string $stored_mode, array $settings = []): string
    {
        $normalized_mode = is_string($stored_mode) ? sanitize_key($stored_mode) : '';
        if (in_array($normalized_mode, ['inline', 'popup', 'external'], true)) {
            return $normalized_mode;
        }

        if (array_key_exists('popup_enabled', $settings)) {
            return ((string) $settings['popup_enabled'] === '1')
                ? BotSettingsManager::DEFAULT_DEPLOY_MODE
                : 'inline';
        }

        return BotSettingsManager::DEFAULT_DEPLOY_MODE;
    }

    /**
     * Normalize triggers payload to a JSON string.
     *
     * @param mixed $value Raw triggers value.
     * @return string
     */
    private function normalize_triggers_json($value): string
    {
        if (is_array($value)) {
            return wp_json_encode($value) ?: '[]';
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed !== '' ? $trimmed : '[]';
        }
        return '[]';
    }

    /**
     * Get IDs of popup bots (excluding target) currently marked as site-wide.
     *
     * @param int $exclude_bot_id Bot ID to exclude from the query.
     * @return int[]
     */
    private function get_other_site_wide_popup_bot_ids(int $exclude_bot_id): array
    {
        if (!class_exists(AdminSetup::class)) {
            return [];
        }

        $query = new \WP_Query([
            'post_type'              => AdminSetup::POST_TYPE,
            'post_status'            => ['publish', 'draft'],
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ]);

        $ids = [];
        foreach ((array) $query->get_posts() as $id) {
            $id = absint($id);
            if (
                $id > 0
                && $id !== $exclude_bot_id
                && get_post_meta($id, '_aipkit_site_wide_enabled', true) === '1'
                && get_post_meta($id, '_aipkit_popup_enabled', true) === '1'
            ) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Build normalized switch state payload for a bot.
     *
     * @param int    $bot_id Bot ID.
     * @param string $bot_name Bot name.
     * @param array  $settings Bot settings.
     * @param int    $default_bot_id Default bot ID.
     * @return array<string, mixed>
     */
    private function build_bot_switch_state_payload(
        int $bot_id,
        string $bot_name,
        array $settings,
        int $default_bot_id
    ): array {
        $is_pro_plan = $this->is_pro_plan_active();
        $deploy_mode = $this->resolve_deploy_mode($settings['deploy_mode'] ?? null, $settings);
        if (!$is_pro_plan && $deploy_mode === 'external') {
            $popup_enabled = isset($settings['popup_enabled']) && (string) $settings['popup_enabled'] === '1';
            $deploy_mode = $popup_enabled ? 'popup' : 'inline';
        }

        $conversation_starters = $settings['conversation_starters'] ?? [];
        if (!is_array($conversation_starters)) {
            $conversation_starters = is_scalar($conversation_starters) && (string) $conversation_starters !== ''
                ? [(string) $conversation_starters]
                : [];
        }
        $conversation_starters_text = implode("\n", array_map('strval', $conversation_starters));

        $settings['triggers_json'] = $this->normalize_triggers_json($settings['triggers_json'] ?? '[]');
        $settings['deploy_mode'] = $deploy_mode;
        if (!$is_pro_plan) {
            unset($settings['embed_allowed_domains']);
        }

        return [
            'bot_id' => $bot_id,
            'bot_name' => $bot_name,
            'is_default' => ($bot_id === $default_bot_id),
            'settings' => $settings,
            'deploy_mode' => $deploy_mode,
            'shortcode' => sprintf('[aipkit_chatbot id=%d]', $bot_id),
            'embed_code' => class_exists('\WPAICG\Lib\Chat\ShortcodeFeatures')
                ? \WPAICG\Lib\Chat\ShortcodeFeatures::embed_code($bot_id)
                : '',
            'embed_allowed_domains' => $is_pro_plan && isset($settings['embed_allowed_domains']) ? (string) $settings['embed_allowed_domains'] : '',
            'conversation_starters_text' => $conversation_starters_text,
            'triggers_json' => $settings['triggers_json'],
            'connected_apps' => class_exists('\WPAICG\Lib\Integrations\Recipes\AIPKit_Stored_Recipes')
                && method_exists('\WPAICG\Lib\Integrations\Recipes\AIPKit_Stored_Recipes', 'get_chatbot_connected_apps_payload')
                ? \WPAICG\Lib\Integrations\Recipes\AIPKit_Stored_Recipes::get_chatbot_connected_apps_payload($bot_id)
                : [
                    'count' => 0,
                    'summary' => '',
                    'recipes' => [],
                ],
        ];
    }

    /**
     * Build a standard successful save/autosave response for a chatbot.
     *
     * @param int                  $bot_id Chatbot ID.
     * @param string               $message Success message.
     * @param array<string, mixed> $extra Additional response fields.
     * @return array<string, mixed>
     */
    private function build_saved_bot_state_success_payload(
        int $bot_id,
        string $message,
        array $extra = []
    ): array {
        $default_bot_id = class_exists(DefaultBotSetup::class)
            ? (int) DefaultBotSetup::get_default_bot_id()
            : 0;
        $bot_post = get_post($bot_id);
        $bot_name = ($bot_post instanceof \WP_Post) ? (string) $bot_post->post_title : '';
        $bot_settings = $this->bot_storage->get_chatbot_settings($bot_id);
        if (!is_array($bot_settings)) {
            $bot_settings = [];
        }

        $response = [
            'message' => $message,
            'bot_id' => $bot_id,
            'bot_name' => $bot_name,
            'bot' => $this->build_bot_switch_state_payload(
                $bot_id,
                $bot_name,
                $bot_settings,
                $default_bot_id
            ),
            'default_bot_id' => $default_bot_id,
        ];

        foreach ($extra as $key => $value) {
            $response[$key] = $value;
        }

        return $response;
    }

    /**
     * Send the standard successful save/autosave response for a chatbot.
     *
     * @param int                  $bot_id Chatbot ID.
     * @param string               $message Success message.
     * @param array<string, mixed> $extra Additional response fields.
     * @return void
     */
    private function send_saved_bot_state_success(
        int $bot_id,
        string $message,
        array $extra = []
    ): void {
        wp_send_json_success(
            $this->build_saved_bot_state_success_payload($bot_id, $message, $extra)
        );
    }

    public function ajax_save_chatbot_settings()
    {
        $permission_check = $this->check_module_access_permissions('chatbot');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $botId = isset($_POST['bot_id']) ? absint($_POST['bot_id']) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $settings = isset($_POST) ? wp_unslash($_POST) : array(); // Use unslashed $_POST

        if (empty($botId)) {
            wp_send_json_error(['message' => __('Invalid Chatbot ID.', 'gpt3-ai-content-generator')], 400);
            return;
        }

        // Uses facade method
        $result = $this->bot_storage->save_bot_settings($botId, $settings);

        if (is_wp_error($result)) {
            $this->send_wp_error($result);
        } else {
            $this->send_saved_bot_state_success(
                $botId,
                __('Chatbot settings saved successfully.', 'gpt3-ai-content-generator')
            );
        }
    }

    public function ajax_delete_chatbot()
    {
        $permission_check = $this->check_module_access_permissions('chatbot');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $botId = isset($_POST['bot_id']) ? absint($_POST['bot_id']) : 0;
        if (empty($botId)) {
            wp_send_json_error(['message' => __('Invalid Chatbot ID.', 'gpt3-ai-content-generator')], 400);
            return;
        }

        // Check if it's the default bot (uses static method, no facade needed here)
        if (!class_exists(DefaultBotSetup::class)) {
            $this->send_wp_error(new WP_Error('dependency_missing', 'DefaultBotSetup class not found for deletion check.', ['status' => 500]));
            return;
        }
        $default_bot_id = DefaultBotSetup::get_default_bot_id();
        if ($botId === $default_bot_id) {
            $this->send_wp_error(new WP_Error('cannot_delete_default', __('The default chatbot cannot be deleted.', 'gpt3-ai-content-generator'), ['status' => 400]));
            return;
        }

        // Uses facade method
        $result = $this->bot_storage->delete_bot($botId);
        if (is_wp_error($result)) {
            $this->send_wp_error($result);
        } else {
            wp_send_json_success([
                'message' => __('Chatbot deleted successfully.', 'gpt3-ai-content-generator'),
                'bot_id' => $botId,
            ]);
        }
    }

    public function ajax_duplicate_chatbot()
    {
        $bot_id = $this->get_validated_chatbot_id_from_request();
        if ($bot_id <= 0) {
            return;
        }

        $source_post = get_post($bot_id);
        if (!$source_post instanceof \WP_Post) {
            wp_send_json_error(['message' => __('Chatbot not found.', 'gpt3-ai-content-generator')], 404);
            return;
        }

        $source_title = sanitize_text_field((string) $source_post->post_title);
        if ($source_title === '') {
            $source_title = __('Chatbot', 'gpt3-ai-content-generator');
        }
        /* translators: %s is the chatbot name being duplicated. */
        $new_title = sprintf(__('%s Copy', 'gpt3-ai-content-generator'), $source_title);

        $new_bot_id = wp_insert_post([
            'post_type' => AdminSetup::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => $new_title,
        ], true);

        if (is_wp_error($new_bot_id) || empty($new_bot_id)) {
            wp_send_json_error(['message' => __('Failed to duplicate chatbot.', 'gpt3-ai-content-generator')], 500);
            return;
        }

        $all_meta = get_post_meta($bot_id);
        if (is_array($all_meta)) {
            foreach ($all_meta as $meta_key => $meta_values) {
                if (!is_string($meta_key) || $meta_key === '' || in_array($meta_key, ['_edit_lock', '_edit_last', '_aipkit_default_bot'], true)) {
                    continue;
                }
                if (!is_array($meta_values)) {
                    continue;
                }
                foreach ($meta_values as $meta_value) {
                    add_post_meta($new_bot_id, $meta_key, maybe_unserialize($meta_value));
                }
            }
        }

        // Ensure the duplicated bot is never marked as default.
        delete_post_meta($new_bot_id, '_aipkit_default_bot');
        // Duplicated bots must always start as non-site-wide to avoid replacing live popup behavior.
        update_post_meta($new_bot_id, '_aipkit_site_wide_enabled', '0');
        update_post_meta($new_bot_id, '_aipkit_popup_delay', BotSettingsManager::DEFAULT_POPUP_DELAY);
        update_post_meta($new_bot_id, '_aipkit_enable_conversation_starters', BotSettingsManager::DEFAULT_ENABLE_CONVERSATION_STARTERS);
        update_post_meta(
            $new_bot_id,
            '_aipkit_conversation_starters',
            BotSettingsManager::get_conversation_starters_meta_value(
                BotSettingsManager::get_default_conversation_starters()
            )
        );

        // Normalize default markers so only one chatbot remains default.
        $default_marker_candidates = get_posts([
            'post_type' => AdminSetup::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'date',
            'order' => 'ASC',
            'no_found_rows' => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ]);
        $default_marker_ids = [];
        foreach ((array) $default_marker_candidates as $default_marker_candidate_id) {
            $default_marker_candidate_id = absint($default_marker_candidate_id);
            if ($default_marker_candidate_id > 0 && get_post_meta($default_marker_candidate_id, '_aipkit_default_bot', true) === '1') {
                $default_marker_ids[] = $default_marker_candidate_id;
            }
        }

        if (is_array($default_marker_ids) && !empty($default_marker_ids)) {
            $normalized_marker_ids = array_values(array_filter(array_map('absint', $default_marker_ids)));
            if (!empty($normalized_marker_ids)) {
                $canonical_default_id = class_exists(DefaultBotSetup::class)
                    ? absint(DefaultBotSetup::get_default_bot_id())
                    : 0;
                if ($canonical_default_id <= 0 || !in_array($canonical_default_id, $normalized_marker_ids, true)) {
                    $canonical_default_id = $normalized_marker_ids[0];
                }

                if ($canonical_default_id > 0) {
                    update_post_meta($canonical_default_id, '_aipkit_default_bot', '1');
                    foreach ($normalized_marker_ids as $default_marker_id) {
                        if ($default_marker_id > 0 && $default_marker_id !== $canonical_default_id) {
                            delete_post_meta($default_marker_id, '_aipkit_default_bot');
                        }
                    }
                }
            }
        }

        $this->send_saved_bot_state_success(
            (int) $new_bot_id,
            __('Chatbot duplicated successfully.', 'gpt3-ai-content-generator')
        );
    }

    public function ajax_get_chatbot_shortcode()
    {
        $permission_check = $this->check_module_access_permissions('chatbot');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $bot_id = isset($_REQUEST['bot_id']) ? absint($_REQUEST['bot_id']) : 0;

        // Ensure AdminSetup class is available
        if (!class_exists(AdminSetup::class)) {
            wp_send_json_error(['message' => __('Internal server error.', 'gpt3-ai-content-generator')], 500);
            return;
        }
        if (empty($bot_id) || get_post_type($bot_id) !== AdminSetup::POST_TYPE) {
            wp_send_json_error(['message' => __('Invalid Chatbot ID provided.', 'gpt3-ai-content-generator')], 400);
            return;
        }

        try {
            // Ensure Shortcode class is available
            if (!class_exists(Shortcode::class)) {
                wp_send_json_error(['message' => __('Internal server error.', 'gpt3-ai-content-generator')], 500);
                return;
            }
            $shortcode_renderer = new Shortcode();
            $shortcode_html = $shortcode_renderer->render_chatbot_shortcode(['id' => $bot_id]);

            if (is_wp_error($shortcode_html)) {
                wp_send_json_error(['message' => $shortcode_html->get_error_message()], 500);
                return;
            }
            if (!is_string($shortcode_html)) {
                wp_send_json_error(['message' => __('Error generating shortcode HTML (non-string result).', 'gpt3-ai-content-generator')], 500);
                return;
            }
            // Shortcode HTML is UTF-8. WordPress supplies cleanup without requiring mbstring.
            if (preg_match('//u', $shortcode_html) !== 1) {
                $shortcode_html = wp_check_invalid_utf8($shortcode_html, true);
                if ($shortcode_html === '' || preg_match('//u', $shortcode_html) !== 1) {
                    wp_send_json_error(['message' => __('Error generating shortcode HTML (encoding issue).', 'gpt3-ai-content-generator')], 500);
                    return;
                }
            }
            wp_send_json_success(['html' => $shortcode_html]);
        } catch (\Throwable $e) {
            $reference = \WPAICG\RuntimeDiagnostics::report($e, 'chatbot_preview');
            wp_send_json_error([
                /* translators: %s: Reference matching the PHP server error log. */
                'message' => sprintf(__('Unable to generate the preview. Please check the server error log. Reference: %s', 'gpt3-ai-content-generator'), $reference),
            ], 500);
        }
    }

    public function ajax_reset_chatbot_settings()
    {
        $permission_check = $this->check_module_access_permissions('chatbot');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $botId = isset($_POST['bot_id']) ? absint($_POST['bot_id']) : 0;
        if (empty($botId)) {
            wp_send_json_error(['message' => __('Invalid Chatbot ID.', 'gpt3-ai-content-generator')], 400);
            return;
        }

        // Uses static method, no facade needed here
        if (!class_exists(DefaultBotSetup::class)) {
            $this->send_wp_error(new WP_Error('dependency_missing', 'DefaultBotSetup class not found for reset.', ['status' => 500]));
            return;
        }
        $result = DefaultBotSetup::reset_bot_settings($botId);
        if (is_wp_error($result)) {
            $this->send_wp_error($result);
        } else {
            $this->send_saved_bot_state_success(
                $botId,
                __('Chatbot settings reset to defaults.', 'gpt3-ai-content-generator')
            );
        }
    }

    /**
     * AJAX: Renames a chatbot.
     * @since NEXT_VERSION
     */
    public function ajax_rename_chatbot()
    {
        $bot_id = $this->get_validated_chatbot_id_from_request();
        if ($bot_id <= 0) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $new_name = isset($_POST['new_name']) ? sanitize_text_field(wp_unslash($_POST['new_name'])) : '';

        if (empty($new_name)) {
            wp_send_json_error(['message' => __('Chatbot name cannot be empty.', 'gpt3-ai-content-generator')], 400);
            return;
        }

        // Update the post title
        $update_args = [
            'ID' => $bot_id,
            'post_title' => $new_name,
        ];
        $updated_post_id = wp_update_post($update_args, true); // Pass true to get WP_Error on failure

        if (is_wp_error($updated_post_id)) {
            wp_send_json_error(['message' => __('Failed to update chatbot name.', 'gpt3-ai-content-generator')], 500);
        } else {
            $this->send_saved_bot_state_success(
                $bot_id,
                __('Success!', 'gpt3-ai-content-generator'),
                [
                    'new_name' => $new_name,
                ]
            );
        }
    }

    /**
     * AJAX: Updates chatbot instructions only (autosave).
     * @since NEXT_VERSION
     */
    public function ajax_update_chatbot_instructions()
    {
        $bot_id = $this->get_validated_chatbot_id_from_request();
        if ($bot_id <= 0) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is checked in check_module_access_permissions(); AIPKit_Prompt_Sanitizer preserves literal HTML while sanitizing prompt text.
        $instructions = isset($_POST['instructions']) ? AIPKit_Prompt_Sanitizer::sanitize(wp_unslash($_POST['instructions'])) : '';

        update_post_meta($bot_id, '_aipkit_instructions', $instructions);

        $this->send_saved_bot_state_success(
            $bot_id,
            __('Saved', 'gpt3-ai-content-generator')
        );
    }

    /**
     * AJAX: Updates chatbot provider/model only (autosave).
     * @since NEXT_VERSION
     */
    public function ajax_update_chatbot_model_settings()
    {
        $bot_id = $this->get_validated_chatbot_id_from_request();
        if ($bot_id <= 0) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $provider = isset($_POST['provider']) ? sanitize_text_field(wp_unslash($_POST['provider'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $model = isset($_POST['model']) ? sanitize_text_field(wp_unslash($_POST['model'])) : '';

        $default_allowed_providers = ['OpenAI', 'Google', 'Claude', 'OpenRouter', 'Azure', 'DeepSeek', 'xAI'];
        $allowed_providers = class_exists(AIPKit_Providers::class)
            ? AIPKit_Providers::get_main_provider_allowlist()
            : $default_allowed_providers;
        if (empty($allowed_providers) || !is_array($allowed_providers)) {
            $allowed_providers = $default_allowed_providers;
        }
        if (!in_array($provider, $allowed_providers, true)) {
            wp_send_json_error(['message' => __('Invalid provider.', 'gpt3-ai-content-generator')], 400);
            return;
        }

        $vector_store_provider = (string) get_post_meta(
            $bot_id,
            '_aipkit_vector_store_provider',
            true
        );
        if ($vector_store_provider === '') {
            $vector_store_provider = BotSettingsManager::DEFAULT_VECTOR_STORE_PROVIDER;
        }
        $enable_vector_store = (string) get_post_meta(
            $bot_id,
            '_aipkit_enable_vector_store',
            true
        );
        $knowledge_state = BotSettingsManager::get_knowledge_capability_state(
            $provider,
            $vector_store_provider,
            $enable_vector_store
        );
        if ($knowledge_state['requested'] && !$knowledge_state['compatible']) {
            update_post_meta($bot_id, '_aipkit_enable_vector_store', '0');
        }

        update_post_meta($bot_id, '_aipkit_provider', $provider);
        if ($model !== '') {
            update_post_meta($bot_id, '_aipkit_model', $model);
        } else {
            delete_post_meta($bot_id, '_aipkit_model');
        }

        $saved_reasoning_effort = AIPKit_OpenAI_Reasoning::sanitize_effort(
            get_post_meta($bot_id, '_aipkit_reasoning_effort', true)
        );
        if ($provider === 'OpenAI' && AIPKit_OpenAI_Reasoning::is_gpt_6_astra($model)) {
            $next_reasoning_effort = AIPKit_OpenAI_Reasoning::normalize_effort_for_model(
                $model,
                $saved_reasoning_effort !== '' ? $saved_reasoning_effort : BotSettingsManager::DEFAULT_REASONING_EFFORT
            );
            update_post_meta($bot_id, '_aipkit_reasoning_effort', $next_reasoning_effort);
        } elseif ($saved_reasoning_effort === 'max') {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified by get_validated_chatbot_id_from_request().
            $next_reasoning_effort = AIPKit_OpenAI_Reasoning::sanitize_effort(isset($_POST['reasoning_effort']) ? sanitize_text_field(wp_unslash($_POST['reasoning_effort'])) : '');
            if ($next_reasoning_effort === '' || $next_reasoning_effort === 'max') {
                $next_reasoning_effort = $provider === 'OpenAI'
                    ? AIPKit_OpenAI_Reasoning::get_default_effort_for_model($model)
                    : '';
            }
            update_post_meta(
                $bot_id,
                '_aipkit_reasoning_effort',
                $next_reasoning_effort !== '' ? $next_reasoning_effort : BotSettingsManager::DEFAULT_REASONING_EFFORT
            );
        }

        $this->send_saved_bot_state_success(
            $bot_id,
            __('Saved', 'gpt3-ai-content-generator')
        );
    }

    /**
     * AJAX: Updates chatbot AI parameters only (autosave).
     * @since NEXT_VERSION
     */
    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every handler in this autosave/training block verifies access via check_module_access_permissions(); remaining superglobal reads are immediately unslashed and normalized per field.
    public function ajax_update_chatbot_ai_parameters()
    {
        $bot_id = $this->get_validated_chatbot_id_from_request();
        if ($bot_id <= 0) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $temperature_raw = isset($_POST['temperature']) ? wp_unslash($_POST['temperature']) : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $max_tokens_raw = isset($_POST['max_completion_tokens']) ? wp_unslash($_POST['max_completion_tokens']) : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $max_messages_raw = isset($_POST['max_messages']) ? wp_unslash($_POST['max_messages']) : null;

        if ($temperature_raw === null || $max_tokens_raw === null) {
            wp_send_json_error(['message' => __('Invalid parameters.', 'gpt3-ai-content-generator')], 400);
            return;
        }

        $temperature = floatval($temperature_raw);
        $temperature = max(0.0, min($temperature, 2.0));
        $max_tokens = absint($max_tokens_raw);
        $max_tokens = max(1, min($max_tokens, 128000));

        update_post_meta($bot_id, '_aipkit_temperature', (string) $temperature);
        update_post_meta($bot_id, '_aipkit_max_completion_tokens', $max_tokens);
        if ($max_messages_raw !== null) {
            $max_messages = absint($max_messages_raw);
            $max_messages = max(1, min($max_messages, 1024));
            update_post_meta($bot_id, '_aipkit_max_messages', $max_messages);
        }

        $this->send_saved_bot_state_success(
            $bot_id,
            __('Saved', 'gpt3-ai-content-generator')
        );
    }

    /**
     * AJAX: Updates chatbot conversation settings only (autosave).
     * @since NEXT_VERSION
     */
    public function ajax_update_chatbot_conversation_settings()
    {
        $bot_id = $this->get_validated_chatbot_id_from_request();
        if ($bot_id <= 0) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $openai_conversation_state_enabled = (isset($_POST['openai_conversation_state_enabled']) && wp_unslash($_POST['openai_conversation_state_enabled']) === '1') ? '1' : '0';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $google_conversation_state_enabled = (isset($_POST['google_conversation_state_enabled']) && wp_unslash($_POST['google_conversation_state_enabled']) === '1') ? '1' : '0';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $openrouter_session_stickiness = (isset($_POST['openrouter_session_stickiness']) && wp_unslash($_POST['openrouter_session_stickiness']) === '1') ? '1' : '0';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $provider = isset($_POST['provider']) ? sanitize_text_field(wp_unslash($_POST['provider'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $reasoning_effort = AIPKit_OpenAI_Reasoning::sanitize_effort(
            isset($_POST['reasoning_effort']) ? wp_unslash($_POST['reasoning_effort']) : ''
        );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified by get_validated_chatbot_id_from_request().
        $reasoning_model = isset($_POST['model'])
            ? sanitize_text_field(wp_unslash($_POST['model']))
            : (string) get_post_meta($bot_id, '_aipkit_model', true);
        if ($provider === 'OpenAI' && AIPKit_OpenAI_Reasoning::is_gpt_6_astra($reasoning_model)) {
            $reasoning_effort = AIPKit_OpenAI_Reasoning::normalize_effort_for_model(
                $reasoning_model,
                $reasoning_effort !== '' ? $reasoning_effort : BotSettingsManager::DEFAULT_REASONING_EFFORT
            );
        } elseif ($reasoning_effort === 'max') {
            $reasoning_effort = $provider === 'OpenAI'
                ? AIPKit_OpenAI_Reasoning::get_default_effort_for_model($reasoning_model)
                : '';
        }
        if ($reasoning_effort === '') {
            $reasoning_effort = BotSettingsManager::DEFAULT_REASONING_EFFORT;
        }

        update_post_meta($bot_id, '_aipkit_openai_conversation_state_enabled', $openai_conversation_state_enabled);
        update_post_meta($bot_id, '_aipkit_google_conversation_state_enabled', $google_conversation_state_enabled);
        update_post_meta($bot_id, '_aipkit_openrouter_session_stickiness', $openrouter_session_stickiness);
        update_post_meta($bot_id, '_aipkit_reasoning_effort', $reasoning_effort);

        $state_enabled = ($provider === 'OpenAI' && $openai_conversation_state_enabled === '1')
            || ($provider === 'Google' && $google_conversation_state_enabled === '1');
        if ($state_enabled && class_exists(AIPKit_Providers::class)) {
            $provider_settings = AIPKit_Providers::get_provider_data($provider);
            if (($provider_settings['store_conversation'] ?? '0') !== '1') {
                $provider_settings['store_conversation'] = '1';
                AIPKit_Providers::save_provider_data($provider, $provider_settings);
            }
        }

        $this->send_saved_bot_state_success(
            $bot_id,
            __('Saved', 'gpt3-ai-content-generator')
        );
    }

    /**
     * AJAX: Updates chatbot style settings only (autosave).
     * @since NEXT_VERSION
     */
    public function ajax_update_chatbot_style_settings()
    {
        $bot_id = $this->get_validated_chatbot_id_from_request();
        if ($bot_id <= 0) {
            return;
        }

        if (isset($_POST['greeting'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $greeting = sanitize_textarea_field(wp_unslash($_POST['greeting']));
            update_post_meta($bot_id, '_aipkit_greeting_message', $greeting);
        }

        if (isset($_POST['subgreeting'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $subgreeting = sanitize_textarea_field(wp_unslash($_POST['subgreeting']));
            update_post_meta($bot_id, '_aipkit_subgreeting_message', $subgreeting);
        }

        if (isset($_POST['input_placeholder'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $input_placeholder = sanitize_text_field(wp_unslash($_POST['input_placeholder']));
            update_post_meta($bot_id, '_aipkit_input_placeholder', $input_placeholder);
        }

        if (isset($_POST['footer_text'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $footer_text = wp_kses_post(wp_unslash($_POST['footer_text']));
            update_post_meta($bot_id, '_aipkit_footer_text', $footer_text);
        }

        if (
            isset($_POST['header_avatar_type']) ||
            isset($_POST['header_avatar_default']) ||
            isset($_POST['header_avatar_url'])
        ) {
            $allowed_header_icons = ['chat-bubble', 'spark', 'openai', 'plus', 'question-mark'];
            $header_avatar_type = get_post_meta($bot_id, '_aipkit_header_avatar_type', BotSettingsManager::DEFAULT_HEADER_AVATAR_TYPE);
            if (isset($_POST['header_avatar_type'])) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
                $header_avatar_type = sanitize_key(wp_unslash($_POST['header_avatar_type']));
            }
            if (!in_array($header_avatar_type, ['inherit', 'default', 'custom'], true)) {
                $header_avatar_type = BotSettingsManager::DEFAULT_HEADER_AVATAR_TYPE;
            }
            if (!isset($_POST['header_avatar_type']) && isset($_POST['header_avatar_url']) && !empty($_POST['header_avatar_url'])) {
                $header_avatar_type = 'custom';
            }

            if ($header_avatar_type === 'custom') {
                $header_avatar_url = isset($_POST['header_avatar_url'])
                    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
                    ? esc_url_raw(trim((string)wp_unslash($_POST['header_avatar_url'])))
                    : get_post_meta($bot_id, '_aipkit_header_avatar_url', BotSettingsManager::DEFAULT_HEADER_AVATAR_URL);
                $header_avatar_value = $header_avatar_url;
                update_post_meta($bot_id, '_aipkit_header_avatar_url', $header_avatar_url);
            } elseif ($header_avatar_type === 'default') {
                $header_avatar_default = isset($_POST['header_avatar_default'])
                    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
                    ? sanitize_key(wp_unslash($_POST['header_avatar_default']))
                    : get_post_meta($bot_id, '_aipkit_header_avatar_value', BotSettingsManager::DEFAULT_HEADER_AVATAR_VALUE);
                if (!in_array($header_avatar_default, $allowed_header_icons, true)) {
                    $header_avatar_default = BotSettingsManager::DEFAULT_HEADER_AVATAR_VALUE;
                }
                $header_avatar_value = $header_avatar_default;
                update_post_meta($bot_id, '_aipkit_header_avatar_url', '');
            } else {
                $header_avatar_value = BotSettingsManager::DEFAULT_HEADER_AVATAR_VALUE;
                update_post_meta($bot_id, '_aipkit_header_avatar_url', '');
            }

            update_post_meta($bot_id, '_aipkit_header_avatar_type', $header_avatar_type);
            update_post_meta($bot_id, '_aipkit_header_avatar_value', $header_avatar_value);
        }

        if (isset($_POST['custom_typing_text'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $typing_text = sanitize_text_field(wp_unslash($_POST['custom_typing_text']));
            update_post_meta($bot_id, '_aipkit_custom_typing_text', $typing_text);
        }

        if (isset($_POST['retrieving_context_text'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $retrieving_context_text = sanitize_text_field(wp_unslash($_POST['retrieving_context_text']));
            update_post_meta($bot_id, '_aipkit_retrieving_context_text', $retrieving_context_text);
        }

        $theme_for_preset = get_post_meta($bot_id, '_aipkit_theme', true);
        if (!in_array($theme_for_preset, ['light', 'dark', 'custom', 'chatgpt'], true)) {
            $theme_for_preset = BotSettingsManager::DEFAULT_THEME;
        }

        if (isset($_POST['theme'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $theme = sanitize_key(wp_unslash($_POST['theme']));
            $allowed_themes = ['light', 'dark', 'custom', 'chatgpt'];
            if (!in_array($theme, $allowed_themes, true)) {
                $theme = BotSettingsManager::DEFAULT_THEME;
            }
            update_post_meta($bot_id, '_aipkit_theme', $theme);
            $theme_for_preset = $theme;
        }

        if ($theme_for_preset !== 'custom') {
            delete_post_meta($bot_id, '_aipkit_theme_preset_key');
        } elseif (isset($_POST['theme_preset_key'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $theme_preset_key = sanitize_key(wp_unslash($_POST['theme_preset_key']));
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

            if ($theme_preset_key !== '' && isset($valid_theme_preset_keys[$theme_preset_key])) {
                update_post_meta($bot_id, '_aipkit_theme_preset_key', $theme_preset_key);
            } else {
                delete_post_meta($bot_id, '_aipkit_theme_preset_key');
            }
        }

        if (isset($_POST['enable_download'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $enable_download = (wp_unslash($_POST['enable_download']) === '1') ? '1' : '0';
            update_post_meta($bot_id, '_aipkit_enable_download', $enable_download);
        }

        update_post_meta($bot_id, '_aipkit_enable_copy_button', '1');

        if (isset($_POST['enable_fullscreen'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $enable_fullscreen = (wp_unslash($_POST['enable_fullscreen']) === '1') ? '1' : '0';
            update_post_meta($bot_id, '_aipkit_enable_fullscreen', $enable_fullscreen);
        }

        update_post_meta($bot_id, '_aipkit_enable_feedback', '1');

        if (isset($_POST['enable_consent_compliance'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $enable_consent = (wp_unslash($_POST['enable_consent_compliance']) === '1') ? '1' : '0';
            update_post_meta($bot_id, '_aipkit_enable_consent_compliance', $enable_consent);
        }

        if (isset($_POST['consent_title'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $consent_title = sanitize_text_field(wp_unslash($_POST['consent_title']));
            update_post_meta($bot_id, '_aipkit_consent_title', $consent_title);
        }

        if (isset($_POST['consent_message'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $consent_message = wp_kses_post(wp_unslash($_POST['consent_message']));
            update_post_meta($bot_id, '_aipkit_consent_message', $consent_message);
        }

        if (isset($_POST['consent_button'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $consent_button = sanitize_text_field(wp_unslash($_POST['consent_button']));
            update_post_meta($bot_id, '_aipkit_consent_button', $consent_button);
        }

        if (isset($_POST['enable_conversation_sidebar'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $enable_sidebar = (wp_unslash($_POST['enable_conversation_sidebar']) === '1') ? '1' : '0';
            update_post_meta($bot_id, '_aipkit_enable_conversation_sidebar', $enable_sidebar);
        }

        if (isset($_POST['enable_conversation_starters'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $enable_starters = (wp_unslash($_POST['enable_conversation_starters']) === '1') ? '1' : '0';
            update_post_meta($bot_id, '_aipkit_enable_conversation_starters', $enable_starters);
        }

        if (isset($_POST['conversation_starters'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $starters_raw = wp_unslash($_POST['conversation_starters']);
            $starters_array = BotSettingsManager::normalize_conversation_starters($starters_raw);
            update_post_meta(
                $bot_id,
                '_aipkit_conversation_starters',
                BotSettingsManager::get_conversation_starters_meta_value($starters_array)
            );
        }

        if (isset($_POST['theme_dimension_overrides'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_module_access_permissions.
            $dimension_overrides = BotSettingsManager::normalize_theme_dimension_overrides(wp_unslash($_POST['theme_dimension_overrides']));
            update_post_meta($bot_id, '_aipkit_theme_dimension_overrides', $dimension_overrides);
        }

        if (isset($_POST['custom_theme_settings']) && is_array($_POST['custom_theme_settings'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $custom_theme_raw = wp_unslash($_POST['custom_theme_settings']);
            if (is_array($custom_theme_raw)) {
                if (!function_exists('\\WPAICG\\Chat\\Storage\\SaverMethods\\sanitize_settings_logic')) {
                    $sanitize_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/bots.php';
                    if (file_exists($sanitize_path)) {
                        require_once $sanitize_path;
                    }
                }
                $sanitized = sanitize_settings_logic([
                    'custom_theme_settings' => $custom_theme_raw,
                ], $bot_id);
                if (!empty($sanitized['custom_theme_settings']) && is_array($sanitized['custom_theme_settings'])) {
                    foreach ($sanitized['custom_theme_settings'] as $key => $value) {
                        update_post_meta($bot_id, '_aipkit_cts_' . $key, $value);
                    }
                    if (class_exists(BotSettingsManager::class)) {
                        BotSettingsManager::cleanup_custom_theme_meta($bot_id);
                    }
                }
            }
        }

        $this->send_saved_bot_state_success(
            $bot_id,
            __('Saved', 'gpt3-ai-content-generator')
        );
    }

    /**
     * AJAX: Updates chatbot web search/grounding settings only (autosave).
     * @since NEXT_VERSION
     */
    public function ajax_update_chatbot_web_settings()
    {
        $bot_id = $this->get_validated_chatbot_id_from_request();
        if ($bot_id <= 0) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $openai_web_search_enabled = (isset($_POST['openai_web_search_enabled']) && wp_unslash($_POST['openai_web_search_enabled']) === '1') ? '1' : '0';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $openai_web_search_context_size = isset($_POST['openai_web_search_context_size']) ? sanitize_text_field(wp_unslash($_POST['openai_web_search_context_size'])) : BotSettingsManager::DEFAULT_OPENAI_WEB_SEARCH_CONTEXT_SIZE;
        $allowed_context_sizes = ['low', 'medium', 'high'];
        if (!in_array($openai_web_search_context_size, $allowed_context_sizes, true)) {
            $openai_web_search_context_size = BotSettingsManager::DEFAULT_OPENAI_WEB_SEARCH_CONTEXT_SIZE;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $openai_web_search_loc_type = isset($_POST['openai_web_search_loc_type']) ? sanitize_text_field(wp_unslash($_POST['openai_web_search_loc_type'])) : BotSettingsManager::DEFAULT_OPENAI_WEB_SEARCH_LOC_TYPE;
        $allowed_loc_types = ['none', 'approximate'];
        if (!in_array($openai_web_search_loc_type, $allowed_loc_types, true)) {
            $openai_web_search_loc_type = BotSettingsManager::DEFAULT_OPENAI_WEB_SEARCH_LOC_TYPE;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $openai_web_search_loc_country = isset($_POST['openai_web_search_loc_country']) ? sanitize_text_field(wp_unslash($_POST['openai_web_search_loc_country'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $openai_web_search_loc_city = isset($_POST['openai_web_search_loc_city']) ? sanitize_text_field(wp_unslash($_POST['openai_web_search_loc_city'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $openai_web_search_loc_region = isset($_POST['openai_web_search_loc_region']) ? sanitize_text_field(wp_unslash($_POST['openai_web_search_loc_region'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $openai_web_search_loc_timezone = isset($_POST['openai_web_search_loc_timezone']) ? sanitize_text_field(wp_unslash($_POST['openai_web_search_loc_timezone'])) : '';

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $claude_web_search_enabled = (isset($_POST['claude_web_search_enabled']) && wp_unslash($_POST['claude_web_search_enabled']) === '1') ? '1' : '0';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $claude_web_search_max_uses = isset($_POST['claude_web_search_max_uses'])
            ? absint(wp_unslash($_POST['claude_web_search_max_uses']))
            : BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_MAX_USES;
        $claude_web_search_max_uses = max(1, min($claude_web_search_max_uses, 20));
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $claude_web_search_loc_type = isset($_POST['claude_web_search_loc_type']) ? sanitize_text_field(wp_unslash($_POST['claude_web_search_loc_type'])) : BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_LOC_TYPE;
        $allowed_claude_loc_types = ['none', 'approximate'];
        if (!in_array($claude_web_search_loc_type, $allowed_claude_loc_types, true)) {
            $claude_web_search_loc_type = BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_LOC_TYPE;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $claude_web_search_loc_country = isset($_POST['claude_web_search_loc_country']) ? sanitize_text_field(wp_unslash($_POST['claude_web_search_loc_country'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $claude_web_search_loc_city = isset($_POST['claude_web_search_loc_city']) ? sanitize_text_field(wp_unslash($_POST['claude_web_search_loc_city'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $claude_web_search_loc_region = isset($_POST['claude_web_search_loc_region']) ? sanitize_text_field(wp_unslash($_POST['claude_web_search_loc_region'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $claude_web_search_loc_timezone = isset($_POST['claude_web_search_loc_timezone']) ? sanitize_text_field(wp_unslash($_POST['claude_web_search_loc_timezone'])) : '';
        $normalize_domains = static function ($value): string {
            if (!is_string($value)) {
                return '';
            }
            $parts = preg_split('/[\r\n,]+/', $value);
            if (!is_array($parts)) {
                return '';
            }
            $clean = [];
            foreach ($parts as $part) {
                $domain = strtolower(trim((string) $part));
                if ($domain === '') {
                    continue;
                }
                $domain = preg_replace('/^https?:\/\//', '', $domain);
                $domain = trim((string) $domain, " \t\n\r\0\x0B/");
                if ($domain === '') {
                    continue;
                }
                if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)) {
                    continue;
                }
                $clean[] = $domain;
            }
            return implode(',', array_values(array_unique($clean)));
        };
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $claude_web_search_allowed_domains = isset($_POST['claude_web_search_allowed_domains']) ? $normalize_domains((string) wp_unslash($_POST['claude_web_search_allowed_domains'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $claude_web_search_blocked_domains = isset($_POST['claude_web_search_blocked_domains']) ? $normalize_domains((string) wp_unslash($_POST['claude_web_search_blocked_domains'])) : '';
        if ($claude_web_search_allowed_domains !== '') {
            $claude_web_search_blocked_domains = '';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $claude_web_search_cache_ttl = isset($_POST['claude_web_search_cache_ttl']) ? sanitize_text_field(wp_unslash($_POST['claude_web_search_cache_ttl'])) : BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_CACHE_TTL;
        if (!in_array($claude_web_search_cache_ttl, ['none', '5m', '1h'], true)) {
            $claude_web_search_cache_ttl = BotSettingsManager::DEFAULT_CLAUDE_WEB_SEARCH_CACHE_TTL;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $openrouter_web_search_enabled = (isset($_POST['openrouter_web_search_enabled']) && wp_unslash($_POST['openrouter_web_search_enabled']) === '1') ? '1' : '0';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $openrouter_web_search_engine = isset($_POST['openrouter_web_search_engine']) ? sanitize_key(wp_unslash($_POST['openrouter_web_search_engine'])) : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_ENGINE;
        if (!in_array($openrouter_web_search_engine, ['auto', 'native', 'exa', 'firecrawl', 'parallel', 'perplexity'], true)) {
            $openrouter_web_search_engine = BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_ENGINE;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $openrouter_web_search_max_results = isset($_POST['openrouter_web_search_max_results'])
            ? absint(wp_unslash($_POST['openrouter_web_search_max_results']))
            : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_MAX_RESULTS;
        $openrouter_web_search_max_results = max(1, min($openrouter_web_search_max_results, 25));
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $openrouter_web_search_max_uses = isset($_POST['openrouter_web_search_max_uses'])
            ? absint(wp_unslash($_POST['openrouter_web_search_max_uses']))
            : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_MAX_USES;
        $openrouter_web_search_max_uses = max(1, min($openrouter_web_search_max_uses, 10));
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $openrouter_web_search_max_total_results = isset($_POST['openrouter_web_search_max_total_results'])
            ? absint(wp_unslash($_POST['openrouter_web_search_max_total_results']))
            : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_MAX_TOTAL_RESULTS;
        $openrouter_web_search_max_total_results = max(1, min($openrouter_web_search_max_total_results, 100));
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $openrouter_web_search_context_size = isset($_POST['openrouter_web_search_context_size'])
            ? sanitize_key(wp_unslash($_POST['openrouter_web_search_context_size']))
            : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_CONTEXT_SIZE;
        if (!in_array($openrouter_web_search_context_size, ['auto', 'low', 'medium', 'high'], true)) {
            $openrouter_web_search_context_size = BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_CONTEXT_SIZE;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $openrouter_web_search_allowed_domains = isset($_POST['openrouter_web_search_allowed_domains'])
            ? $normalize_domains((string) wp_unslash($_POST['openrouter_web_search_allowed_domains']))
            : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_ALLOWED_DOMAINS;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $openrouter_web_search_excluded_domains = isset($_POST['openrouter_web_search_excluded_domains'])
            ? $normalize_domains((string) wp_unslash($_POST['openrouter_web_search_excluded_domains']))
            : BotSettingsManager::DEFAULT_OPENROUTER_WEB_SEARCH_EXCLUDED_DOMAINS;
        if ($openrouter_web_search_allowed_domains !== '') {
            $openrouter_web_search_excluded_domains = '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $xai_web_search_enabled = (isset($_POST['xai_web_search_enabled']) && wp_unslash($_POST['xai_web_search_enabled']) === '1') ? '1' : '0';

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $google_search_grounding_enabled = (isset($_POST['google_search_grounding_enabled']) && wp_unslash($_POST['google_search_grounding_enabled']) === '1') ? '1' : '0';
        if ($google_search_grounding_enabled === '1') {
            $knowledge_state = BotSettingsManager::get_knowledge_capability_state(
                (string) get_post_meta($bot_id, '_aipkit_provider', true),
                (string) get_post_meta($bot_id, '_aipkit_vector_store_provider', true),
                (string) get_post_meta($bot_id, '_aipkit_enable_vector_store', true),
                $google_search_grounding_enabled
            );
            if ($knowledge_state['google_search_conflict']) {
                update_post_meta($bot_id, '_aipkit_enable_vector_store', '0');
            }
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $web_toggle_default_on = (isset($_POST['web_toggle_default_on']) && wp_unslash($_POST['web_toggle_default_on']) === '1') ? '1' : '0';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $show_sources = isset($_POST['show_sources'])
            ? ((wp_unslash($_POST['show_sources']) === '1') ? '1' : '0')
            : BotSettingsManager::DEFAULT_SHOW_SOURCES;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $sources_label = isset($_POST['sources_label'])
            ? sanitize_text_field(wp_unslash($_POST['sources_label']))
            : BotSettingsManager::DEFAULT_SOURCES_LABEL;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $searching_web_text = isset($_POST['searching_web_text'])
            ? sanitize_text_field(wp_unslash($_POST['searching_web_text']))
            : BotSettingsManager::DEFAULT_SEARCHING_WEB_TEXT;

        update_post_meta($bot_id, '_aipkit_openai_web_search_enabled', $openai_web_search_enabled);
        update_post_meta($bot_id, '_aipkit_openai_web_search_context_size', $openai_web_search_context_size);
        update_post_meta($bot_id, '_aipkit_openai_web_search_loc_type', $openai_web_search_loc_type);
        update_post_meta($bot_id, '_aipkit_openai_web_search_loc_country', $openai_web_search_loc_country);
        update_post_meta($bot_id, '_aipkit_openai_web_search_loc_city', $openai_web_search_loc_city);
        update_post_meta($bot_id, '_aipkit_openai_web_search_loc_region', $openai_web_search_loc_region);
        update_post_meta($bot_id, '_aipkit_openai_web_search_loc_timezone', $openai_web_search_loc_timezone);
        update_post_meta($bot_id, '_aipkit_claude_web_search_enabled', $claude_web_search_enabled);
        update_post_meta($bot_id, '_aipkit_claude_web_search_max_uses', (string) $claude_web_search_max_uses);
        update_post_meta($bot_id, '_aipkit_claude_web_search_loc_type', $claude_web_search_loc_type);
        update_post_meta($bot_id, '_aipkit_claude_web_search_loc_country', $claude_web_search_loc_country);
        update_post_meta($bot_id, '_aipkit_claude_web_search_loc_city', $claude_web_search_loc_city);
        update_post_meta($bot_id, '_aipkit_claude_web_search_loc_region', $claude_web_search_loc_region);
        update_post_meta($bot_id, '_aipkit_claude_web_search_loc_timezone', $claude_web_search_loc_timezone);
        update_post_meta($bot_id, '_aipkit_claude_web_search_allowed_domains', $claude_web_search_allowed_domains);
        update_post_meta($bot_id, '_aipkit_claude_web_search_blocked_domains', $claude_web_search_blocked_domains);
        update_post_meta($bot_id, '_aipkit_claude_web_search_cache_ttl', $claude_web_search_cache_ttl);
        update_post_meta($bot_id, '_aipkit_openrouter_web_search_enabled', $openrouter_web_search_enabled);
        update_post_meta($bot_id, '_aipkit_openrouter_web_search_engine', $openrouter_web_search_engine);
        update_post_meta($bot_id, '_aipkit_openrouter_web_search_max_results', (string) $openrouter_web_search_max_results);
        update_post_meta($bot_id, '_aipkit_openrouter_web_search_max_uses', (string) $openrouter_web_search_max_uses);
        update_post_meta($bot_id, '_aipkit_openrouter_web_search_max_total_results', (string) $openrouter_web_search_max_total_results);
        update_post_meta($bot_id, '_aipkit_openrouter_web_search_context_size', $openrouter_web_search_context_size);
        update_post_meta($bot_id, '_aipkit_openrouter_web_search_allowed_domains', $openrouter_web_search_allowed_domains);
        update_post_meta($bot_id, '_aipkit_openrouter_web_search_excluded_domains', $openrouter_web_search_excluded_domains);
        delete_post_meta($bot_id, '_aipkit_openrouter_web_search_search_prompt');
        update_post_meta($bot_id, '_aipkit_xai_web_search_enabled', $xai_web_search_enabled);
        update_post_meta($bot_id, '_aipkit_google_search_grounding_enabled', $google_search_grounding_enabled);
        update_post_meta($bot_id, '_aipkit_web_toggle_default_on', $web_toggle_default_on);
        update_post_meta($bot_id, '_aipkit_show_sources', $show_sources);
        update_post_meta($bot_id, '_aipkit_sources_label', $sources_label);
        update_post_meta($bot_id, '_aipkit_searching_web_text', $searching_web_text);

        $this->send_saved_bot_state_success(
            $bot_id,
            __('Saved', 'gpt3-ai-content-generator')
        );
    }

    /**
     * AJAX: Updates chatbot context settings only (autosave).
     * @since NEXT_VERSION
     */
    public function ajax_update_chatbot_context_settings()
    {
        $bot_id = $this->get_validated_chatbot_id_from_request();
        if ($bot_id <= 0) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $content_aware_enabled = (isset($_POST['content_aware_enabled']) && wp_unslash($_POST['content_aware_enabled']) === '1') ? '1' : '0';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $enable_vector_store = (isset($_POST['enable_vector_store']) && wp_unslash($_POST['enable_vector_store']) === '1') ? '1' : '0';

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $vector_store_provider = isset($_POST['vector_store_provider'])
            ? sanitize_text_field(wp_unslash($_POST['vector_store_provider']))
            : BotSettingsManager::DEFAULT_VECTOR_STORE_PROVIDER;
        $main_provider = (string) get_post_meta($bot_id, '_aipkit_provider', true);
        $allowed_providers = ['openai', 'google', 'pinecone', 'qdrant', 'chroma', 'local', 'claude_files'];
        if (!in_array($vector_store_provider, $allowed_providers, true)) {
            wp_send_json_error(
                ['message' => __('Invalid knowledge provider.', 'gpt3-ai-content-generator')],
                400
            );
            return;
        }

        $knowledge_state = BotSettingsManager::get_knowledge_capability_state(
            $main_provider,
            $vector_store_provider,
            $enable_vector_store,
            (string) get_post_meta($bot_id, '_aipkit_google_search_grounding_enabled', true)
        );
        if ($knowledge_state['requested'] && !$knowledge_state['compatible']) {
            $enable_vector_store = '0';
        }
        if ($knowledge_state['google_search_conflict']) {
            update_post_meta($bot_id, '_aipkit_google_search_grounding_enabled', '0');
        }

        $openai_vector_store_ids = [];
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        if (isset($_POST['openai_vector_store_ids'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $raw_ids = wp_unslash($_POST['openai_vector_store_ids']);
            if (is_array($raw_ids)) {
                foreach ($raw_ids as $vs_id) {
                    $sanitized_id = sanitize_text_field(trim((string) $vs_id));
                    if (!empty($sanitized_id) && strpos($sanitized_id, 'vs_') === 0) {
                        $openai_vector_store_ids[] = $sanitized_id;
                    }
                }
            }
        }
        $openai_vector_store_ids = array_values(array_unique($openai_vector_store_ids));

        $google_file_search_store_names = [];
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        if (isset($_POST['google_file_search_store_names'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $raw_store_names = wp_unslash($_POST['google_file_search_store_names']);
            if (is_array($raw_store_names)) {
                foreach ($raw_store_names as $store_name) {
                    $store_name = sanitize_text_field(trim((string) $store_name));
                    if (strpos($store_name, 'fileSearchStores/') === 0) {
                        $google_file_search_store_names[] = $store_name;
                    }
                }
            }
        }
        $google_file_search_store_names = array_values(array_unique($google_file_search_store_names));

        $pinecone_index_name = '';
        if ($vector_store_provider === 'pinecone') {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $pinecone_index_name = isset($_POST['pinecone_index_name'])
                ? sanitize_text_field(wp_unslash($_POST['pinecone_index_name']))
                : '';
        }

        $qdrant_collection_names = [];
        if ($vector_store_provider === 'qdrant') {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            if (isset($_POST['qdrant_collection_names'])) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
                $raw_names = wp_unslash($_POST['qdrant_collection_names']);
                if (is_array($raw_names)) {
                    foreach ($raw_names as $name) {
                        $sanitized_name = sanitize_text_field(trim((string) $name));
                        if ($sanitized_name !== '') {
                            $qdrant_collection_names[] = $sanitized_name;
                        }
                    }
                }
            }
        }
        $qdrant_collection_names = array_values(array_unique($qdrant_collection_names));
        $qdrant_collection_name = $qdrant_collection_names[0] ?? '';

        $chroma_collection_names = [];
        if ($vector_store_provider === 'chroma') {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            if (isset($_POST['chroma_collection_names'])) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
                $raw_names = wp_unslash($_POST['chroma_collection_names']);
                if (is_array($raw_names)) {
                    foreach ($raw_names as $name) {
                        $sanitized_name = sanitize_text_field(trim((string) $name));
                        if ($sanitized_name !== '') {
                            $chroma_collection_names[] = $sanitized_name;
                        }
                    }
                }
            }
        }
        $chroma_collection_names = array_values(array_unique($chroma_collection_names));
        $chroma_collection_name = $chroma_collection_names[0] ?? '';

        // Local store selection.
        $local_store_ids = [];
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        if ($vector_store_provider === 'local' && isset($_POST['local_store_ids']) && is_array($_POST['local_store_ids'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked above; each id is reduced to [a-z0-9_-] below.
            foreach (wp_unslash($_POST['local_store_ids']) as $local_id) {
                $local_id = substr((string) preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $local_id)), 0, 64);
                if ($local_id !== '') { $local_store_ids[] = $local_id; }
            }
        }
        $local_store_ids = array_values(array_unique($local_store_ids));

        $vector_embedding_provider = BotSettingsManager::DEFAULT_VECTOR_EMBEDDING_PROVIDER;
        $vector_embedding_model = '';
        if (in_array($vector_store_provider, ['pinecone', 'qdrant', 'chroma', 'local'], true)) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $vector_embedding_provider = isset($_POST['vector_embedding_provider'])
                ? sanitize_key(wp_unslash($_POST['vector_embedding_provider']))
                : BotSettingsManager::DEFAULT_VECTOR_EMBEDDING_PROVIDER;
            $allowed_embedding_providers = array_unique(array_merge(AIPKit_Providers::get_embedding_provider_keys('chatbot_admin_save'), ['aipuffercloud']));
            // Saving settings must preserve Cloud identity while disconnected; dispatch checks availability.
            if (!in_array($vector_embedding_provider, $allowed_embedding_providers, true)) {
                $vector_embedding_provider = BotSettingsManager::DEFAULT_VECTOR_EMBEDDING_PROVIDER;
            }
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $vector_embedding_model = isset($_POST['vector_embedding_model'])
                ? sanitize_text_field(wp_unslash($_POST['vector_embedding_model']))
                : '';
            // Backward-compatibility: accept combined "provider::model" values from older UI payloads.
            if (strpos($vector_embedding_model, '::') !== false) {
                [$model_provider, $model_id] = array_pad(explode('::', $vector_embedding_model, 2), 2, '');
                $model_provider = sanitize_key((string) $model_provider);
                $model_id = sanitize_text_field((string) $model_id);
                if ($model_id !== '' && in_array($model_provider, $allowed_embedding_providers, true)) {
                    $vector_embedding_provider = $model_provider;
                    $vector_embedding_model = $model_id;
                }
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $vector_store_top_k = isset($_POST['vector_store_top_k'])
            ? absint(wp_unslash($_POST['vector_store_top_k']))
            : BotSettingsManager::DEFAULT_VECTOR_STORE_TOP_K;
        $vector_store_top_k = max(1, min($vector_store_top_k, 20));

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $vector_store_confidence_threshold = isset($_POST['vector_store_confidence_threshold'])
            ? absint(wp_unslash($_POST['vector_store_confidence_threshold']))
            : BotSettingsManager::DEFAULT_VECTOR_STORE_CONFIDENCE_THRESHOLD;
        $vector_store_confidence_threshold = max(0, min($vector_store_confidence_threshold, 100));

        update_post_meta($bot_id, '_aipkit_content_aware_enabled', $content_aware_enabled);
        update_post_meta($bot_id, '_aipkit_enable_vector_store', $enable_vector_store);
        update_post_meta($bot_id, '_aipkit_vector_store_provider', $vector_store_provider);

        if ($vector_store_provider === 'openai') {
            update_post_meta($bot_id, '_aipkit_openai_vector_store_ids', wp_json_encode($openai_vector_store_ids));
            delete_post_meta($bot_id, '_aipkit_pinecone_index_name');
            delete_post_meta($bot_id, '_aipkit_qdrant_collection_name');
            delete_post_meta($bot_id, '_aipkit_qdrant_collection_names');
            delete_post_meta($bot_id, '_aipkit_chroma_collection_name');
            delete_post_meta($bot_id, '_aipkit_chroma_collection_names');
            delete_post_meta($bot_id, '_aipkit_vector_embedding_provider');
            delete_post_meta($bot_id, '_aipkit_vector_embedding_model');
            delete_post_meta($bot_id, '_aipkit_google_file_search_store_names');
        } elseif ($vector_store_provider === 'google') {
            update_post_meta($bot_id, '_aipkit_google_file_search_store_names', wp_json_encode($google_file_search_store_names));
            delete_post_meta($bot_id, '_aipkit_openai_vector_store_ids');
            delete_post_meta($bot_id, '_aipkit_pinecone_index_name');
            delete_post_meta($bot_id, '_aipkit_qdrant_collection_name');
            delete_post_meta($bot_id, '_aipkit_qdrant_collection_names');
            delete_post_meta($bot_id, '_aipkit_chroma_collection_name');
            delete_post_meta($bot_id, '_aipkit_chroma_collection_names');
            delete_post_meta($bot_id, '_aipkit_vector_embedding_provider');
            delete_post_meta($bot_id, '_aipkit_vector_embedding_model');
        } elseif ($vector_store_provider === 'pinecone') {
            update_post_meta($bot_id, '_aipkit_pinecone_index_name', $pinecone_index_name);
            update_post_meta($bot_id, '_aipkit_vector_embedding_provider', $vector_embedding_provider);
            update_post_meta($bot_id, '_aipkit_vector_embedding_model', $vector_embedding_model);
            delete_post_meta($bot_id, '_aipkit_openai_vector_store_ids');
            delete_post_meta($bot_id, '_aipkit_qdrant_collection_name');
            delete_post_meta($bot_id, '_aipkit_qdrant_collection_names');
            delete_post_meta($bot_id, '_aipkit_chroma_collection_name');
            delete_post_meta($bot_id, '_aipkit_chroma_collection_names');
            delete_post_meta($bot_id, '_aipkit_google_file_search_store_names');
        } elseif ($vector_store_provider === 'qdrant') {
            update_post_meta($bot_id, '_aipkit_qdrant_collection_name', $qdrant_collection_name);
            update_post_meta($bot_id, '_aipkit_qdrant_collection_names', wp_json_encode($qdrant_collection_names));
            update_post_meta($bot_id, '_aipkit_vector_embedding_provider', $vector_embedding_provider);
            update_post_meta($bot_id, '_aipkit_vector_embedding_model', $vector_embedding_model);
            delete_post_meta($bot_id, '_aipkit_openai_vector_store_ids');
            delete_post_meta($bot_id, '_aipkit_pinecone_index_name');
            delete_post_meta($bot_id, '_aipkit_chroma_collection_name');
            delete_post_meta($bot_id, '_aipkit_chroma_collection_names');
            delete_post_meta($bot_id, '_aipkit_google_file_search_store_names');
        } elseif ($vector_store_provider === 'local') {
            update_post_meta($bot_id, '_aipkit_local_store_ids', wp_json_encode($local_store_ids));
            update_post_meta($bot_id, '_aipkit_vector_embedding_provider', $vector_embedding_provider);
            update_post_meta($bot_id, '_aipkit_vector_embedding_model', $vector_embedding_model);
        } elseif ($vector_store_provider === 'chroma') {
            update_post_meta($bot_id, '_aipkit_chroma_collection_name', $chroma_collection_name);
            update_post_meta($bot_id, '_aipkit_chroma_collection_names', wp_json_encode($chroma_collection_names));
            update_post_meta($bot_id, '_aipkit_vector_embedding_provider', $vector_embedding_provider);
            update_post_meta($bot_id, '_aipkit_vector_embedding_model', $vector_embedding_model);
            delete_post_meta($bot_id, '_aipkit_openai_vector_store_ids');
            delete_post_meta($bot_id, '_aipkit_pinecone_index_name');
            delete_post_meta($bot_id, '_aipkit_qdrant_collection_name');
            delete_post_meta($bot_id, '_aipkit_qdrant_collection_names');
            delete_post_meta($bot_id, '_aipkit_google_file_search_store_names');
        } else {
            delete_post_meta($bot_id, '_aipkit_openai_vector_store_ids');
            delete_post_meta($bot_id, '_aipkit_pinecone_index_name');
            delete_post_meta($bot_id, '_aipkit_qdrant_collection_name');
            delete_post_meta($bot_id, '_aipkit_qdrant_collection_names');
            delete_post_meta($bot_id, '_aipkit_chroma_collection_name');
            delete_post_meta($bot_id, '_aipkit_chroma_collection_names');
            delete_post_meta($bot_id, '_aipkit_vector_embedding_provider');
            delete_post_meta($bot_id, '_aipkit_vector_embedding_model');
            delete_post_meta($bot_id, '_aipkit_google_file_search_store_names');
        }

        update_post_meta($bot_id, '_aipkit_vector_store_top_k', $vector_store_top_k);
        update_post_meta($bot_id, '_aipkit_vector_store_confidence_threshold', $vector_store_confidence_threshold);

        $this->send_saved_bot_state_success(
            $bot_id,
            __('Saved', 'gpt3-ai-content-generator')
        );
    }

    /**
     * AJAX: Updates chatbot token limit settings only (autosave).
     * @since NEXT_VERSION
     */
    public function ajax_update_chatbot_token_limits()
    {
        $bot_id = $this->get_validated_chatbot_id_from_request();
        if ($bot_id <= 0) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $token_limit_mode = isset($_POST['token_limit_mode']) ? sanitize_key(wp_unslash($_POST['token_limit_mode'])) : BotSettingsManager::DEFAULT_TOKEN_LIMIT_MODE;
        if (!in_array($token_limit_mode, ['general', 'role_based'], true)) {
            $token_limit_mode = BotSettingsManager::DEFAULT_TOKEN_LIMIT_MODE;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $guest_limit_raw = isset($_POST['token_guest_limit']) ? trim(wp_unslash($_POST['token_guest_limit'])) : '';
        $token_guest_limit = ($guest_limit_raw === '0' || (ctype_digit($guest_limit_raw) && $guest_limit_raw > 0))
            ? (string) absint($guest_limit_raw)
            : '';

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $user_limit_raw = isset($_POST['token_user_limit']) ? trim(wp_unslash($_POST['token_user_limit'])) : '';
        $token_user_limit = ($user_limit_raw === '0' || (ctype_digit($user_limit_raw) && $user_limit_raw > 0))
            ? (string) absint($user_limit_raw)
            : '';

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $token_reset_period = isset($_POST['token_reset_period']) ? sanitize_key(wp_unslash($_POST['token_reset_period'])) : BotSettingsManager::DEFAULT_TOKEN_RESET_PERIOD;
        if (!in_array($token_reset_period, ['never', 'daily', 'weekly', 'monthly'], true)) {
            $token_reset_period = BotSettingsManager::DEFAULT_TOKEN_RESET_PERIOD;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $token_limit_message = isset($_POST['token_limit_message']) ? sanitize_text_field(wp_unslash($_POST['token_limit_message'])) : '';
        $default_token_limit_actions = BotSettingsManager::get_default_token_limit_action_settings();
        $allowed_token_limit_action_types = BotSettingsManager::get_token_limit_action_types();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $token_limit_primary_action_type = isset($_POST['token_limit_primary_action_type'])
            ? sanitize_key(wp_unslash($_POST['token_limit_primary_action_type']))
            : $default_token_limit_actions['primary_type'];
        if (!in_array($token_limit_primary_action_type, $allowed_token_limit_action_types, true)) {
            $token_limit_primary_action_type = $default_token_limit_actions['primary_type'];
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $token_limit_primary_action_label = isset($_POST['token_limit_primary_action_label'])
            ? sanitize_text_field(wp_unslash($_POST['token_limit_primary_action_label']))
            : $default_token_limit_actions['primary_label'];
        if ($token_limit_primary_action_type === 'none') {
            $token_limit_primary_action_label = '';
        } elseif ($token_limit_primary_action_label === '') {
            $token_limit_primary_action_label = BotSettingsManager::get_token_limit_action_default_label($token_limit_primary_action_type);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $token_limit_primary_action_url = isset($_POST['token_limit_primary_action_url'])
            ? esc_url_raw(trim((string) wp_unslash($_POST['token_limit_primary_action_url'])))
            : $default_token_limit_actions['primary_url'];

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $token_limit_secondary_action_type = isset($_POST['token_limit_secondary_action_type'])
            ? sanitize_key(wp_unslash($_POST['token_limit_secondary_action_type']))
            : $default_token_limit_actions['secondary_type'];
        if (!in_array($token_limit_secondary_action_type, $allowed_token_limit_action_types, true)) {
            $token_limit_secondary_action_type = $default_token_limit_actions['secondary_type'];
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $token_limit_secondary_action_label = isset($_POST['token_limit_secondary_action_label'])
            ? sanitize_text_field(wp_unslash($_POST['token_limit_secondary_action_label']))
            : $default_token_limit_actions['secondary_label'];
        if ($token_limit_secondary_action_type === 'none') {
            $token_limit_secondary_action_label = '';
        } elseif ($token_limit_secondary_action_label === '') {
            $token_limit_secondary_action_label = BotSettingsManager::get_token_limit_action_default_label($token_limit_secondary_action_type);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $token_limit_secondary_action_url = isset($_POST['token_limit_secondary_action_url'])
            ? esc_url_raw(trim((string) wp_unslash($_POST['token_limit_secondary_action_url'])))
            : $default_token_limit_actions['secondary_url'];

        $role_limits_to_save = [];
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        if (isset($_POST['token_role_limits']) && is_array($_POST['token_role_limits'])) {
            $editable_roles = get_editable_roles();
            $posted_role_limits = wp_unslash($_POST['token_role_limits']);
            foreach ($editable_roles as $role_slug => $role_info) {
                if (!isset($posted_role_limits[$role_slug])) {
                    continue;
                }
                $raw_limit = trim((string) $posted_role_limits[$role_slug]);
                if ($raw_limit === '0' || (ctype_digit($raw_limit) && $raw_limit > 0)) {
                    $role_limits_to_save[$role_slug] = (string) absint($raw_limit);
                } else {
                    $role_limits_to_save[$role_slug] = '';
                }
            }
        }

        update_post_meta($bot_id, '_aipkit_token_limit_mode', $token_limit_mode);
        delete_post_meta($bot_id, '_aipkit_token_pricing_mode');

        if ($token_guest_limit === '') {
            delete_post_meta($bot_id, '_aipkit_token_guest_limit');
        } else {
            update_post_meta($bot_id, '_aipkit_token_guest_limit', $token_guest_limit);
        }

        if ($token_user_limit === '') {
            delete_post_meta($bot_id, '_aipkit_token_user_limit');
        } else {
            update_post_meta($bot_id, '_aipkit_token_user_limit', $token_user_limit);
        }

        update_post_meta($bot_id, '_aipkit_token_reset_period', $token_reset_period);

        if ($token_limit_message === '') {
            delete_post_meta($bot_id, '_aipkit_token_limit_message');
        } else {
            update_post_meta($bot_id, '_aipkit_token_limit_message', $token_limit_message);
        }
        update_post_meta($bot_id, '_aipkit_token_limit_primary_action_type', $token_limit_primary_action_type);
        if ($token_limit_primary_action_label === '') {
            delete_post_meta($bot_id, '_aipkit_token_limit_primary_action_label');
        } else {
            update_post_meta($bot_id, '_aipkit_token_limit_primary_action_label', $token_limit_primary_action_label);
        }
        if ($token_limit_primary_action_url === '') {
            delete_post_meta($bot_id, '_aipkit_token_limit_primary_action_url');
        } else {
            update_post_meta($bot_id, '_aipkit_token_limit_primary_action_url', $token_limit_primary_action_url);
        }
        update_post_meta($bot_id, '_aipkit_token_limit_secondary_action_type', $token_limit_secondary_action_type);
        if ($token_limit_secondary_action_label === '') {
            delete_post_meta($bot_id, '_aipkit_token_limit_secondary_action_label');
        } else {
            update_post_meta($bot_id, '_aipkit_token_limit_secondary_action_label', $token_limit_secondary_action_label);
        }
        if ($token_limit_secondary_action_url === '') {
            delete_post_meta($bot_id, '_aipkit_token_limit_secondary_action_url');
        } else {
            update_post_meta($bot_id, '_aipkit_token_limit_secondary_action_url', $token_limit_secondary_action_url);
        }

        $role_limits_json = wp_json_encode($role_limits_to_save, JSON_UNESCAPED_UNICODE);
        if (empty(json_decode($role_limits_json, true))) {
            delete_post_meta($bot_id, '_aipkit_token_role_limits');
        } else {
            update_post_meta($bot_id, '_aipkit_token_role_limits', $role_limits_json);
        }

        $this->send_saved_bot_state_success(
            $bot_id,
            __('Saved', 'gpt3-ai-content-generator')
        );
    }

    /**
     * AJAX: Updates chatbot image settings only (autosave).
     * @since NEXT_VERSION
     */
    public function ajax_update_chatbot_image_settings()
    {
        $bot_id = $this->get_validated_chatbot_id_from_request();
        if ($bot_id <= 0) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $chat_image_model_id = isset($_POST['chat_image_model_id'])
            ? sanitize_text_field(wp_unslash($_POST['chat_image_model_id']))
            : BotSettingsManager::get_default_model_id('OpenAIImage');
        if ($chat_image_model_id === '') {
            $chat_image_model_id = BotSettingsManager::get_default_model_id('OpenAIImage');
        }
        $saved_image_model = strtolower($chat_image_model_id);
        if (
            strpos($saved_image_model, 'imagen-') === 0
            || (strpos($saved_image_model, 'gemini-') === 0 && strpos($saved_image_model, 'image') !== false)
        ) {
            $chat_image_model_id = AIPKit_Providers::normalize_google_image_model($chat_image_model_id);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $raw_image_triggers = isset($_POST['image_triggers'])
            ? sanitize_text_field(wp_unslash($_POST['image_triggers']))
            : BotSettingsManager::DEFAULT_IMAGE_TRIGGERS;
        $triggers_array = array_map('trim', explode(',', $raw_image_triggers));
        $image_triggers = !empty($triggers_array)
            ? implode(',', $triggers_array)
            : BotSettingsManager::DEFAULT_IMAGE_TRIGGERS;

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $enable_image_generation = (isset($_POST['enable_image_generation']) && wp_unslash($_POST['enable_image_generation']) === '1') ? '1' : '0';

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $enable_image_upload = (isset($_POST['enable_image_upload']) && wp_unslash($_POST['enable_image_upload']) === '1') ? '1' : '0';

        update_post_meta($bot_id, '_aipkit_chat_image_model_id', $chat_image_model_id);
        update_post_meta($bot_id, '_aipkit_image_triggers', $image_triggers);
        update_post_meta($bot_id, '_aipkit_enable_image_generation', $enable_image_generation);
        update_post_meta($bot_id, '_aipkit_enable_image_upload', $enable_image_upload);

        $this->send_saved_bot_state_success(
            $bot_id,
            __('Saved', 'gpt3-ai-content-generator')
        );
    }

    /**
     * AJAX: Updates chatbot file upload setting only (autosave).
     * @since NEXT_VERSION
     */
    public function ajax_update_chatbot_file_upload_settings()
    {
        $bot_id = $this->get_validated_chatbot_id_from_request();
        if ($bot_id <= 0) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $enable_file_upload = (isset($_POST['enable_file_upload']) && wp_unslash($_POST['enable_file_upload']) === '1') ? '1' : '0';
        update_post_meta($bot_id, '_aipkit_enable_file_upload', $enable_file_upload);

        $this->send_saved_bot_state_success(
            $bot_id,
            __('Saved', 'gpt3-ai-content-generator')
        );
    }

    /**
     * AJAX: Updates chatbot audio settings only (autosave).
     * @since NEXT_VERSION
     */
    public function ajax_update_chatbot_audio_settings()
    {
        $bot_id = $this->get_validated_chatbot_id_from_request();
        if ($bot_id <= 0) {
            return;
        }

        if (isset($_POST['enable_voice_input'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $enable_voice_input = (wp_unslash($_POST['enable_voice_input']) === '1') ? '1' : '0';
            update_post_meta($bot_id, '_aipkit_enable_voice_input', $enable_voice_input);
        }

        if (isset($_POST['stt_provider'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $stt_provider = sanitize_text_field(wp_unslash($_POST['stt_provider']));
            $allowed_stt_providers = ['OpenAI', 'Google', 'Azure', 'AIPufferCloud'];
            if (!in_array($stt_provider, $allowed_stt_providers, true)) {
                $stt_provider = BotSettingsManager::DEFAULT_STT_PROVIDER;
            }
            update_post_meta($bot_id, '_aipkit_stt_provider', $stt_provider);
        }

        if (isset($_POST['stt_openai_model_id'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $stt_openai_model_id = AIPKit_Model_Catalog::sanitize_openai_file_transcription_model(
                sanitize_text_field(wp_unslash($_POST['stt_openai_model_id']))
            );
            update_post_meta($bot_id, '_aipkit_stt_openai_model_id', $stt_openai_model_id);
        }

        if (isset($_POST['stt_google_model_id'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $stt_google_model_id = AIPKit_Providers::normalize_google_stt_model(
                sanitize_text_field(wp_unslash($_POST['stt_google_model_id']))
            );
            update_post_meta($bot_id, '_aipkit_stt_google_model_id', $stt_google_model_id);
        }
        if (isset($_POST['stt_cloud_model_id'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $cloud_model = sanitize_text_field(wp_unslash($_POST['stt_cloud_model_id']));
            if (in_array($cloud_model, wp_list_pluck(\WPAICG\Cloud\Connection::media_models('transcribe'), 'id'), true)) {
                update_post_meta($bot_id, '_aipkit_stt_cloud_model_id', $cloud_model);
            }
        }

        if (isset($_POST['tts_enabled'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $tts_enabled = (wp_unslash($_POST['tts_enabled']) === '1') ? '1' : '0';
            update_post_meta($bot_id, '_aipkit_tts_enabled', $tts_enabled);
        }

        if (isset($_POST['tts_auto_play'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $tts_auto_play = (wp_unslash($_POST['tts_auto_play']) === '1') ? '1' : '0';
            update_post_meta($bot_id, '_aipkit_tts_auto_play', $tts_auto_play);
        }

        if (isset($_POST['tts_provider'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $tts_provider = sanitize_text_field(wp_unslash($_POST['tts_provider']));
            $allowed_tts_providers = ['Google', 'OpenAI', 'ElevenLabs', 'AIPufferCloud'];
            if (!in_array($tts_provider, $allowed_tts_providers, true)) {
                $tts_provider = BotSettingsManager::DEFAULT_TTS_PROVIDER;
            }
            update_post_meta($bot_id, '_aipkit_tts_provider', $tts_provider);
        }

        if (isset($_POST['tts_google_voice_id'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $tts_google_voice_id = AIPKit_Providers::normalize_google_tts_voice(
                sanitize_text_field(wp_unslash($_POST['tts_google_voice_id']))
            );
            update_post_meta($bot_id, '_aipkit_tts_google_voice_id', $tts_google_voice_id);
        }

        if (isset($_POST['tts_google_model_id'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $tts_google_model_id = AIPKit_Providers::normalize_google_tts_model(
                sanitize_text_field(wp_unslash($_POST['tts_google_model_id']))
            );
            update_post_meta($bot_id, '_aipkit_tts_google_model_id', $tts_google_model_id);
        }

        if (isset($_POST['tts_openai_voice_id'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $tts_openai_voice_id = sanitize_text_field(wp_unslash($_POST['tts_openai_voice_id']));
            if ($tts_openai_voice_id === '') {
                $tts_openai_voice_id = BotSettingsManager::get_default_model_id('OpenAIVoices');
            }
            update_post_meta($bot_id, '_aipkit_tts_openai_voice_id', $tts_openai_voice_id);
        }
        if (isset($_POST['tts_cloud_voice_id'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_module_access_permissions.
            $voice = sanitize_text_field(wp_unslash($_POST['tts_cloud_voice_id']));
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_module_access_permissions.
            $model = sanitize_text_field(wp_unslash($_POST['tts_cloud_model_id'] ?? get_post_meta($bot_id, '_aipkit_tts_cloud_model_id', true)));
            $voices = \WPAICG\Cloud\Connection::media_capabilities('speech_generate', $model)['voices'] ?? [];
            if ($voice === '' || in_array($voice, $voices, true)) {
                update_post_meta($bot_id, '_aipkit_tts_cloud_voice_id', $voice);
            }
        }
        if (isset($_POST['tts_cloud_model_id'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $cloud_model = sanitize_text_field(wp_unslash($_POST['tts_cloud_model_id']));
            if (in_array($cloud_model, wp_list_pluck(\WPAICG\Cloud\Connection::media_models('speech_generate'), 'id'), true)) {
                update_post_meta($bot_id, '_aipkit_tts_cloud_model_id', $cloud_model);
            }
        }

        if (isset($_POST['tts_openai_model_id'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $tts_openai_model_id = sanitize_text_field(wp_unslash($_POST['tts_openai_model_id']));
            if ($tts_openai_model_id === '') {
                $tts_openai_model_id = BotSettingsManager::get_default_model_id('OpenAITTS');
            }
            update_post_meta($bot_id, '_aipkit_tts_openai_model_id', $tts_openai_model_id);
        }

        if (isset($_POST['tts_elevenlabs_voice_id'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $tts_elevenlabs_voice_id = sanitize_text_field(wp_unslash($_POST['tts_elevenlabs_voice_id']));
            update_post_meta($bot_id, '_aipkit_tts_elevenlabs_voice_id', $tts_elevenlabs_voice_id);
        }

        if (isset($_POST['tts_elevenlabs_model_id'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $tts_elevenlabs_model_id = sanitize_text_field(wp_unslash($_POST['tts_elevenlabs_model_id']));
            update_post_meta($bot_id, '_aipkit_tts_elevenlabs_model_id', $tts_elevenlabs_model_id);
        }

        if (isset($_POST['enable_realtime_voice'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $enable_realtime_voice = (wp_unslash($_POST['enable_realtime_voice']) === '1') ? '1' : '0';
            update_post_meta($bot_id, '_aipkit_enable_realtime_voice', $enable_realtime_voice);
        }

        if (isset($_POST['direct_voice_mode'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $direct_voice_mode = (wp_unslash($_POST['direct_voice_mode']) === '1') ? '1' : '0';
            update_post_meta($bot_id, '_aipkit_direct_voice_mode', $direct_voice_mode);
        }

        if (isset($_POST['input_audio_noise_reduction'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $input_audio_noise_reduction = (wp_unslash($_POST['input_audio_noise_reduction']) === '1') ? '1' : '0';
            update_post_meta($bot_id, '_aipkit_input_audio_noise_reduction', $input_audio_noise_reduction);
        }

        if (isset($_POST['realtime_model'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $realtime_model = sanitize_text_field(wp_unslash($_POST['realtime_model']));
            $allowed_realtime_models = class_exists('\WPAICG\AIPKit_Providers')
                ? array_column(\WPAICG\AIPKit_Providers::get_openai_realtime_models(), 'id')
                : [BotSettingsManager::get_default_model_id('OpenAIRealtime')];
            if (!in_array($realtime_model, $allowed_realtime_models, true)) {
                $realtime_model = BotSettingsManager::get_default_model_id('OpenAIRealtime');
            }
            update_post_meta($bot_id, '_aipkit_realtime_model', $realtime_model);
        }

        if (isset($_POST['realtime_voice'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $realtime_voice = sanitize_text_field(wp_unslash($_POST['realtime_voice']));
            $allowed_realtime_voices = class_exists('\WPAICG\AIPKit_Providers')
                ? array_column(\WPAICG\AIPKit_Providers::get_openai_realtime_voices(), 'id')
                : [BotSettingsManager::get_default_model_id('OpenAIRealtimeVoices')];
            if (!in_array($realtime_voice, $allowed_realtime_voices, true)) {
                $realtime_voice = BotSettingsManager::get_default_model_id('OpenAIRealtimeVoices');
            }
            update_post_meta($bot_id, '_aipkit_realtime_voice', $realtime_voice);
        }

        if (isset($_POST['turn_detection'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $turn_detection = sanitize_text_field(wp_unslash($_POST['turn_detection']));
            $allowed_turn_detection = ['server_vad', 'semantic_vad'];
            if (!in_array($turn_detection, $allowed_turn_detection, true)) {
                $turn_detection = BotSettingsManager::DEFAULT_TURN_DETECTION;
            }
            update_post_meta($bot_id, '_aipkit_turn_detection', $turn_detection);
        }

        if (isset($_POST['speed'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $speed = floatval(wp_unslash($_POST['speed']));
            $speed = max(0.25, min($speed, 1.5));
            update_post_meta($bot_id, '_aipkit_speed', (string) $speed);
        }

        if (isset($_POST['input_audio_format'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $input_audio_format = sanitize_text_field(wp_unslash($_POST['input_audio_format']));
            $valid_formats = ['pcm16', 'g711_ulaw', 'g711_alaw'];
            if (!in_array($input_audio_format, $valid_formats, true)) {
                $input_audio_format = BotSettingsManager::DEFAULT_INPUT_AUDIO_FORMAT;
            }
            update_post_meta($bot_id, '_aipkit_input_audio_format', $input_audio_format);
        }

        if (isset($_POST['output_audio_format'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $output_audio_format = sanitize_text_field(wp_unslash($_POST['output_audio_format']));
            $valid_formats = ['pcm16', 'g711_ulaw', 'g711_alaw'];
            if (!in_array($output_audio_format, $valid_formats, true)) {
                $output_audio_format = BotSettingsManager::DEFAULT_OUTPUT_AUDIO_FORMAT;
            }
            update_post_meta($bot_id, '_aipkit_output_audio_format', $output_audio_format);
        }

        if ($this->is_pro_plan_active() && class_exists('\\WPAICG\\Lib\\Chat\\LiveSettings')) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Module access and nonce checked above.
            \WPAICG\Lib\Chat\LiveSettings::save($bot_id, wp_unslash($_POST));
        }

        $this->send_saved_bot_state_success(
            $bot_id,
            __('Saved', 'gpt3-ai-content-generator')
        );
    }

    /**
     * AJAX: Updates chatbot popup settings only (autosave).
     * @since NEXT_VERSION
     */
    public function ajax_update_chatbot_popup_settings()
    {
        $bot_id = $this->get_validated_chatbot_id_from_request();
        if ($bot_id <= 0) {
            return;
        }

        if (isset($_POST['popup_position'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $popup_position = sanitize_key(wp_unslash($_POST['popup_position']));
            $allowed_positions = ['bottom-right', 'bottom-left', 'top-right', 'top-left'];
            if (!in_array($popup_position, $allowed_positions, true)) {
                $popup_position = 'bottom-right';
            }
            update_post_meta($bot_id, '_aipkit_popup_position', $popup_position);
        }

        if (isset($_POST['popup_delay'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $popup_delay = absint(wp_unslash($_POST['popup_delay']));
            update_post_meta($bot_id, '_aipkit_popup_delay', $popup_delay);
        }

        $icon_type = null;
        if (isset($_POST['popup_icon_type'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $icon_type = sanitize_key(wp_unslash($_POST['popup_icon_type']));
            if (!in_array($icon_type, ['default', 'custom'], true)) {
                $icon_type = BotSettingsManager::DEFAULT_POPUP_ICON_TYPE;
            }
            update_post_meta($bot_id, '_aipkit_popup_icon_type', $icon_type);
        }

        if (isset($_POST['popup_icon_style'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $icon_style = sanitize_key(wp_unslash($_POST['popup_icon_style']));
            if (!in_array($icon_style, ['circle', 'square', 'none'], true)) {
                $icon_style = BotSettingsManager::DEFAULT_POPUP_ICON_STYLE;
            }
            update_post_meta($bot_id, '_aipkit_popup_icon_style', $icon_style);
        }

        if (isset($_POST['popup_icon_size'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $icon_size = sanitize_key(wp_unslash($_POST['popup_icon_size']));
            $allowed_sizes = ['small', 'medium', 'large', 'xlarge'];
            if (!in_array($icon_size, $allowed_sizes, true)) {
                $icon_size = BotSettingsManager::DEFAULT_POPUP_ICON_SIZE;
            }
            update_post_meta($bot_id, '_aipkit_popup_icon_size', $icon_size);
        }

        if (
            isset($_POST['popup_icon_type']) ||
            isset($_POST['popup_icon_default']) ||
            isset($_POST['popup_icon_custom_url'])
        ) {
            $current_icon_value = get_post_meta($bot_id, '_aipkit_popup_icon_value', true);
            if ($icon_type === null) {
                $icon_type = get_post_meta($bot_id, '_aipkit_popup_icon_type', BotSettingsManager::DEFAULT_POPUP_ICON_TYPE);
            }

            if ($icon_type === 'custom') {
                if (isset($_POST['popup_icon_custom_url'])) {
                    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
                    $icon_value = esc_url_raw(trim(wp_unslash($_POST['popup_icon_custom_url'])));
                } else {
                    $icon_value = filter_var($current_icon_value, FILTER_VALIDATE_URL) ? $current_icon_value : '';
                }
            } else {
                $allowed_defaults = ['chat-bubble', 'spark', 'openai', 'plus', 'question-mark'];
                if (isset($_POST['popup_icon_default'])) {
                    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
                    $icon_value = sanitize_key(wp_unslash($_POST['popup_icon_default']));
                } else {
                    $icon_value = $current_icon_value;
                }
                if (!in_array($icon_value, $allowed_defaults, true)) {
                    $icon_value = BotSettingsManager::DEFAULT_POPUP_ICON_VALUE;
                }
            }

            update_post_meta($bot_id, '_aipkit_popup_icon_value', $icon_value);
        }

        if (
            isset($_POST['header_avatar_type']) ||
            isset($_POST['header_avatar_default']) ||
            isset($_POST['header_avatar_url'])
        ) {
            $allowed_header_icons = ['chat-bubble', 'spark', 'openai', 'plus', 'question-mark'];
            $header_avatar_type = isset($_POST['header_avatar_type'])
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
                ? sanitize_key(wp_unslash($_POST['header_avatar_type']))
                : get_post_meta($bot_id, '_aipkit_header_avatar_type', BotSettingsManager::DEFAULT_HEADER_AVATAR_TYPE);
            if (!in_array($header_avatar_type, ['inherit', 'default', 'custom'], true)) {
                $header_avatar_type = BotSettingsManager::DEFAULT_HEADER_AVATAR_TYPE;
            }
            if (!isset($_POST['header_avatar_type']) && isset($_POST['header_avatar_url']) && !empty($_POST['header_avatar_url'])) {
                $header_avatar_type = 'custom';
            }

            if ($header_avatar_type === 'custom') {
                $header_avatar_url = isset($_POST['header_avatar_url'])
                    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
                    ? esc_url_raw(trim((string)wp_unslash($_POST['header_avatar_url'])))
                    : get_post_meta($bot_id, '_aipkit_header_avatar_url', BotSettingsManager::DEFAULT_HEADER_AVATAR_URL);
                $header_avatar_value = $header_avatar_url;
                update_post_meta($bot_id, '_aipkit_header_avatar_url', $header_avatar_url);
            } elseif ($header_avatar_type === 'default') {
                $header_avatar_default = isset($_POST['header_avatar_default'])
                    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
                    ? sanitize_key(wp_unslash($_POST['header_avatar_default']))
                    : get_post_meta($bot_id, '_aipkit_header_avatar_value', BotSettingsManager::DEFAULT_HEADER_AVATAR_VALUE);
                if (!in_array($header_avatar_default, $allowed_header_icons, true)) {
                    $header_avatar_default = BotSettingsManager::DEFAULT_HEADER_AVATAR_VALUE;
                }
                $header_avatar_value = $header_avatar_default;
                update_post_meta($bot_id, '_aipkit_header_avatar_url', '');
            } else {
                $header_avatar_value = BotSettingsManager::DEFAULT_HEADER_AVATAR_VALUE;
                update_post_meta($bot_id, '_aipkit_header_avatar_url', '');
            }

            update_post_meta($bot_id, '_aipkit_header_avatar_type', $header_avatar_type);
            update_post_meta($bot_id, '_aipkit_header_avatar_value', $header_avatar_value);
        }

        if (isset($_POST['header_online_text'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $header_online_text = sanitize_text_field(wp_unslash($_POST['header_online_text']));
            update_post_meta($bot_id, '_aipkit_header_online_text', $header_online_text);
        }

        if (isset($_POST['popup_label_enabled'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $label_enabled = (wp_unslash($_POST['popup_label_enabled']) === '1') ? '1' : '0';
            update_post_meta($bot_id, '_aipkit_popup_label_enabled', $label_enabled);
        }

        if (isset($_POST['popup_label_text'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $label_text = sanitize_text_field(wp_unslash($_POST['popup_label_text']));
            update_post_meta($bot_id, '_aipkit_popup_label_text', $label_text);
        }

        if (isset($_POST['popup_label_mode'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $label_mode = sanitize_key(wp_unslash($_POST['popup_label_mode']));
            $allowed_modes = ['always', 'on_delay', 'until_open', 'until_dismissed'];
            if (!in_array($label_mode, $allowed_modes, true)) {
                $label_mode = BotSettingsManager::DEFAULT_POPUP_LABEL_MODE;
            }
            update_post_meta($bot_id, '_aipkit_popup_label_mode', $label_mode);
        }

        if (isset($_POST['popup_label_size'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $label_size = sanitize_key(wp_unslash($_POST['popup_label_size']));
            $allowed_sizes = ['small', 'medium', 'large', 'xlarge'];
            if (!in_array($label_size, $allowed_sizes, true)) {
                $label_size = BotSettingsManager::DEFAULT_POPUP_LABEL_SIZE;
            }
            update_post_meta($bot_id, '_aipkit_popup_label_size', $label_size);
        }

        if (isset($_POST['popup_label_delay_seconds'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $label_delay = max(0, absint(wp_unslash($_POST['popup_label_delay_seconds'])));
            update_post_meta($bot_id, '_aipkit_popup_label_delay_seconds', $label_delay);
        }

        if (isset($_POST['popup_label_auto_hide_seconds'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $label_auto_hide = max(0, absint(wp_unslash($_POST['popup_label_auto_hide_seconds'])));
            update_post_meta($bot_id, '_aipkit_popup_label_auto_hide_seconds', $label_auto_hide);
        }

        if (isset($_POST['popup_label_dismissible'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $label_dismissible = (wp_unslash($_POST['popup_label_dismissible']) === '1') ? '1' : '0';
            update_post_meta($bot_id, '_aipkit_popup_label_dismissible', $label_dismissible);
        }

        if (isset($_POST['popup_label_show_on_desktop'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $label_show_desktop = (wp_unslash($_POST['popup_label_show_on_desktop']) === '1') ? '1' : '0';
            update_post_meta($bot_id, '_aipkit_popup_label_show_on_desktop', $label_show_desktop);
        }

        if (isset($_POST['popup_label_show_on_mobile'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $label_show_mobile = (wp_unslash($_POST['popup_label_show_on_mobile']) === '1') ? '1' : '0';
            update_post_meta($bot_id, '_aipkit_popup_label_show_on_mobile', $label_show_mobile);
        }

        if (isset($_POST['popup_label_frequency'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $label_frequency = sanitize_key(wp_unslash($_POST['popup_label_frequency']));
            $allowed_frequency = ['once_per_visitor', 'once_per_session', 'always'];
            if (!in_array($label_frequency, $allowed_frequency, true)) {
                $label_frequency = BotSettingsManager::DEFAULT_POPUP_LABEL_FREQUENCY;
            }
            update_post_meta($bot_id, '_aipkit_popup_label_frequency', $label_frequency);
        }

        if (isset($_POST['popup_label_version'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $label_version = sanitize_text_field(wp_unslash($_POST['popup_label_version']));
            update_post_meta($bot_id, '_aipkit_popup_label_version', $label_version);
        }

        $this->send_saved_bot_state_success(
            $bot_id,
            __('Saved', 'gpt3-ai-content-generator')
        );
    }

    /**
     * AJAX: Updates chatbot deploy settings (popup/site-wide/embed) only (autosave).
     * @since NEXT_VERSION
     */
    public function ajax_update_chatbot_deploy_settings()
    {
        $bot_id = $this->get_validated_chatbot_id_from_request();
        if ($bot_id <= 0) {
            return;
        }

        $updated_any = false;
        $success_message = __('Saved', 'gpt3-ai-content-generator');
        $response_extra = [];
        $site_wide_manager = null;
        $is_pro_plan = $this->is_pro_plan_active();
        $popup_enabled = (get_post_meta($bot_id, '_aipkit_popup_enabled', true) === '1') ? '1' : '0';
        $deploy_mode = $this->resolve_deploy_mode(
            (string) get_post_meta($bot_id, '_aipkit_deploy_mode', true),
            ['popup_enabled' => $popup_enabled]
        );
        if (!$is_pro_plan && $deploy_mode === 'external') {
            $deploy_mode = ($popup_enabled === '1') ? 'popup' : 'inline';
        }
        $deploy_mode_explicit = false;

        if (isset($_POST['deploy_mode'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $deploy_mode = $this->resolve_deploy_mode(
                sanitize_key((string) wp_unslash($_POST['deploy_mode'])),
                ['popup_enabled' => $popup_enabled]
            );
            if (!$is_pro_plan && $deploy_mode === 'external') {
                $deploy_mode = ($popup_enabled === '1') ? 'popup' : 'inline';
            }
            $deploy_mode_explicit = true;
            $updated_any = true;
        }

        if (isset($_POST['popup_enabled'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $popup_enabled = (wp_unslash($_POST['popup_enabled']) === '1') ? '1' : '0';
            update_post_meta($bot_id, '_aipkit_popup_enabled', $popup_enabled);
            $updated_any = true;

            if (!$deploy_mode_explicit) {
                $deploy_mode = ($popup_enabled === '1') ? 'popup' : 'inline';
            }
        }

        if (isset($_POST['site_wide_enabled'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $requested_site_wide_enabled = (wp_unslash($_POST['site_wide_enabled']) === '1') ? '1' : '0';
            $site_wide_enabled = ($requested_site_wide_enabled === '1' && $popup_enabled === '1') ? '1' : '0';
            $updated_any = true;
            $disabled_site_wide_ids = [];

            if (!class_exists(SiteWideBotManager::class)) {
                $site_wide_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/bots.php';
                if (file_exists($site_wide_path)) {
                    require_once $site_wide_path;
                }
            }
            if (class_exists(SiteWideBotManager::class)) {
                $site_wide_manager = new SiteWideBotManager();
                if ($site_wide_enabled === '1') {
                    $disabled_site_wide_ids = $this->get_other_site_wide_popup_bot_ids($bot_id);
                }
                $clear_cache = $site_wide_manager->ensure_site_wide_uniqueness(
                    $bot_id,
                    $site_wide_enabled === '1'
                );
                update_post_meta($bot_id, '_aipkit_site_wide_enabled', $site_wide_enabled);
                if ($clear_cache) {
                    $site_wide_manager->clear_site_wide_cache();
                }
            } else {
                update_post_meta($bot_id, '_aipkit_site_wide_enabled', $site_wide_enabled);
            }

            if ($requested_site_wide_enabled === '1' && $site_wide_enabled !== '1') {
                $success_message = __('Saved. Site-wide is available only in Popup mode.', 'gpt3-ai-content-generator');
            }

            if ($site_wide_enabled === '1' && !empty($disabled_site_wide_ids)) {
                $default_bot_id = class_exists(DefaultBotSetup::class)
                    ? (int) DefaultBotSetup::get_default_bot_id()
                    : 0;
                $updated_bots = [];
                foreach ($disabled_site_wide_ids as $disabled_bot_id) {
                    $disabled_bot_id = absint($disabled_bot_id);
                    if ($disabled_bot_id <= 0) {
                        continue;
                    }
                    $disabled_post = get_post($disabled_bot_id);
                    if (!$disabled_post instanceof \WP_Post) {
                        continue;
                    }
                    $disabled_settings = $this->bot_storage->get_chatbot_settings($disabled_bot_id);
                    if (!is_array($disabled_settings)) {
                        $disabled_settings = [];
                    }
                    $updated_bots[] = $this->build_bot_switch_state_payload(
                        $disabled_bot_id,
                        (string) $disabled_post->post_title,
                        $disabled_settings,
                        $default_bot_id
                    );
                }
                if (!empty($updated_bots)) {
                    $response_extra['updated_bots'] = $updated_bots;
                    $success_message = (count($updated_bots) === 1)
                        ? __('Saved.', 'gpt3-ai-content-generator')
                        : __('Saved.', 'gpt3-ai-content-generator');
                }
            }
        }

        if ($is_pro_plan && isset($_POST['embed_allowed_domains'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
            $raw_domains = trim(wp_unslash($_POST['embed_allowed_domains']));
            if ($raw_domains === '') {
                $sanitized_domains = '';
            } else {
                $domains_array = preg_split('/[\s,]+/', $raw_domains, -1, PREG_SPLIT_NO_EMPTY);
                $sanitized_list = [];
                foreach ($domains_array as $domain) {
                    $sanitized_url = esc_url_raw(trim($domain));
                    if (!empty($sanitized_url)) {
                        $sanitized_list[] = rtrim($sanitized_url, '/');
                    }
                }
                $sanitized_domains = implode("\n", array_unique($sanitized_list));
            }
            update_post_meta($bot_id, '_aipkit_embed_allowed_domains', $sanitized_domains);
            $updated_any = true;
        }

        update_post_meta($bot_id, '_aipkit_deploy_mode', $deploy_mode);

        if ($updated_any && $site_wide_manager instanceof SiteWideBotManager) {
            $site_wide_manager->clear_site_wide_cache();
        }

        if (!$updated_any) {
            wp_send_json_error(['message' => __('No changes to save.', 'gpt3-ai-content-generator')], 400);
            return;
        }

        $this->send_saved_bot_state_success($bot_id, $success_message, $response_extra);
    }

    /**
     * AJAX: Updates chatbot triggers JSON only (autosave).
     * @since NEXT_VERSION
     */
    public function ajax_update_chatbot_triggers()
    {
        $bot_id = $this->get_validated_chatbot_id_from_request();
        if ($bot_id <= 0) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $triggers_json = isset($_POST['triggers_json']) ? trim(wp_unslash($_POST['triggers_json'])) : '';

        if ($triggers_json === '') {
            $triggers_json = '[]';
        }

        $decoded_triggers = json_decode($triggers_json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => __('Invalid JSON format for triggers.', 'gpt3-ai-content-generator')], 400);
            return;
        }

        if (!is_array($decoded_triggers)) {
            $triggers_json = '[]';
        } else {
            // Normalize stored JSON to ensure nested strings (e.g. body_template) are properly escaped.
            $triggers_json = wp_json_encode(
                $decoded_triggers,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        $trigger_validator_class = '\\WPAICG\\Lib\\Chat\\Triggers\\Validation\\AIPKit_Trigger_Validator';
        if (!class_exists($trigger_validator_class) && defined('WPAICG_LIB_DIR')) {
            $validator_path = WPAICG_LIB_DIR . 'chatbot/trigger-validation.php';
            if (file_exists($validator_path)) {
                require_once $validator_path;
            }
        }
        if (class_exists($trigger_validator_class)) {
            $validation_result = $trigger_validator_class::validate_triggers_array($decoded_triggers);
            if (is_wp_error($validation_result)) {
                wp_send_json_error(['message' => $validation_result->get_error_message()], 400);
                return;
            }
        }

        $trigger_meta_key = '_aipkit_chatbot_triggers';
        $trigger_storage_class_name = '\WPAICG\Lib\Chat\Triggers\AIPKit_Trigger_Storage';
        if (class_exists($trigger_storage_class_name)) {
            $trigger_meta_key = $trigger_storage_class_name::META_KEY;
        }

        update_post_meta($bot_id, $trigger_meta_key, $triggers_json);

        $this->send_saved_bot_state_success(
            $bot_id,
            __('Saved', 'gpt3-ai-content-generator')
        );
    }

    /**
     * AJAX: Returns the training source count for a chatbot.
     * @since NEXT_VERSION
     */
    /** Built-in knowledge base stores for this request: the posted override, else the bot's saved list. */
    private function local_store_ids_for_request(array $settings): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled by the calling action.
        $raw = isset($_POST['local_store_ids']) && is_array($_POST['local_store_ids']) ? wp_unslash($_POST['local_store_ids']) : ($settings['local_store_ids'] ?? []);
        return array_values(array_unique(array_filter(array_map(static function ($id) {
            return substr((string) preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $id)), 0, 64);
        }, (array) $raw))));
    }

    public function ajax_get_chatbot_training_source_count()
    {
        $bot_id = $this->get_validated_chatbot_id_from_request();
        if ($bot_id <= 0) {
            return;
        }

        if (!class_exists(BotSettingsManager::class)) {
            $manager_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/bots.php';
            if (file_exists($manager_path)) {
                require_once $manager_path;
            }
        }

        $settings_manager = class_exists(BotSettingsManager::class) ? new BotSettingsManager() : null;
        $settings = $settings_manager ? $settings_manager->get_chatbot_settings($bot_id) : [];

        // Optional overrides from UI (for instant reflection before autosave completes).
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $override_enable_vector_store = isset($_POST['enable_vector_store'])
            ? sanitize_text_field(wp_unslash($_POST['enable_vector_store']))
            : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $override_provider = isset($_POST['vector_store_provider'])
            ? sanitize_key(wp_unslash($_POST['vector_store_provider']))
            : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $has_openai_override = isset($_POST['openai_vector_store_ids']);
        $override_openai_ids = $has_openai_override
            ? (array) wp_unslash($_POST['openai_vector_store_ids'])
            : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $has_google_override = isset($_POST['google_file_search_store_names']);
        $override_google_store_names = $has_google_override
            ? (array) wp_unslash($_POST['google_file_search_store_names'])
            : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $has_pinecone_override = isset($_POST['pinecone_index_name']);
        $override_pinecone_index = $has_pinecone_override
            ? sanitize_text_field(wp_unslash($_POST['pinecone_index_name']))
            : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $has_qdrant_override = isset($_POST['qdrant_collection_names']);
        $override_qdrant_names = $has_qdrant_override
            ? (array) wp_unslash($_POST['qdrant_collection_names'])
            : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $has_chroma_override = isset($_POST['chroma_collection_names']);
        $override_chroma_names = $has_chroma_override
            ? (array) wp_unslash($_POST['chroma_collection_names'])
            : null;
        $vector_store_enabled = $settings['enable_vector_store'] ?? BotSettingsManager::DEFAULT_ENABLE_VECTOR_STORE;
        if ($override_enable_vector_store !== null) {
            $vector_store_enabled = in_array($override_enable_vector_store, ['0', '1'], true)
                ? $override_enable_vector_store
                : $vector_store_enabled;
        }
        if ($vector_store_enabled !== '1') {
            wp_send_json_success([
                'count' => 0,
            ]);
            return;
        }

        $provider_key = $settings['vector_store_provider'] ?? BotSettingsManager::DEFAULT_VECTOR_STORE_PROVIDER;
        if ($override_provider && in_array($override_provider, ['openai', 'pinecone', 'qdrant', 'chroma', 'local', 'claude_files', 'google'], true)) {
            $provider_key = $override_provider;
        }
        $chatbot_provider = (string) ($settings['provider'] ?? get_post_meta($bot_id, '_aipkit_provider', true));
        if (!BotSettingsManager::is_vector_store_provider_compatible($chatbot_provider, $provider_key)) {
            wp_send_json_success([
                'count' => 0,
            ]);
            return;
        }
        $provider_map = [
            'openai' => 'OpenAI',
            'pinecone' => 'Pinecone',
            'qdrant' => 'Qdrant',
            'chroma' => 'Chroma',
            'local' => 'Local',
            'google' => 'Google',
        ];
        $provider_name = $provider_map[$provider_key] ?? '';
        if ($provider_name === '') {
            wp_send_json_success([
                'count' => 0,
            ]);
            return;
        }

        $store_ids = [];
        if ($provider_key === 'openai') {
            if ($has_openai_override && is_array($override_openai_ids)) {
                $store_ids = array_filter(array_map('sanitize_text_field', $override_openai_ids));
            } else {
                $store_ids = $settings['openai_vector_store_ids'] ?? [];
            }
            if (!is_array($store_ids)) {
                $store_ids = json_decode((string) $store_ids, true);
            }
        } elseif ($provider_key === 'google') {
            $store_ids = $has_google_override && is_array($override_google_store_names)
                ? $override_google_store_names
                : ($settings['google_file_search_store_names'] ?? []);
        } elseif ($provider_key === 'pinecone') {
            if ($has_pinecone_override) {
                $store_ids = !empty($override_pinecone_index) ? [$override_pinecone_index] : [];
            } else {
                $store_ids = !empty($settings['pinecone_index_name'])
                    ? [$settings['pinecone_index_name']]
                    : [];
            }
        } elseif ($provider_key === 'qdrant') {
            if ($has_qdrant_override && is_array($override_qdrant_names)) {
                $store_ids = array_filter(array_map('sanitize_text_field', $override_qdrant_names));
            } else {
                $store_ids = $settings['qdrant_collection_names'] ?? [];
            }
            if (!is_array($store_ids)) {
                $store_ids = json_decode((string) $store_ids, true);
            }
            if (empty($store_ids) && !empty($settings['qdrant_collection_name']) && !$has_qdrant_override) {
                $store_ids = [$settings['qdrant_collection_name']];
            }
        } elseif ($provider_key === 'local') {
            $store_ids = $this->local_store_ids_for_request($settings);
        } elseif ($provider_key === 'chroma') {
            if ($has_chroma_override && is_array($override_chroma_names)) {
                $store_ids = array_filter(array_map('sanitize_text_field', $override_chroma_names));
            } else {
                $store_ids = $settings['chroma_collection_names'] ?? [];
            }
            if (!is_array($store_ids)) {
                $store_ids = json_decode((string) $store_ids, true);
            }
            if (empty($store_ids) && !empty($settings['chroma_collection_name']) && !$has_chroma_override) {
                $store_ids = [$settings['chroma_collection_name']];
            }
        }

        $store_ids = array_filter(array_map('sanitize_text_field', (array) $store_ids));
        $store_ids = array_values(array_unique($store_ids));

        if (empty($store_ids)) {
            wp_send_json_success([
                'count' => 0,
            ]);
            return;
        }

        $source_stats = $this->get_chatbot_training_source_stats_for_context($provider_name, $store_ids);

        wp_send_json_success([
            'count' => $source_stats['count'],
            'count_is_capped' => $source_stats['count_is_capped'],
        ]);
    }

    /**
     * AJAX: Returns durable training status for the active chatbot knowledge base.
     * @since NEXT_VERSION
     */
    public function ajax_get_chatbot_training_status()
    {
        $bot_id = $this->get_validated_chatbot_id_from_request();
        if ($bot_id <= 0) {
            return;
        }

        $context = $this->get_chatbot_training_vector_context_from_request($bot_id);
        if (empty($context['enabled']) || empty($context['provider_label']) || empty($context['store_ids'])) {
            wp_send_json_success([
                'count' => 0,
                'queue' => [
                    'pending' => 0,
                    'processing' => 0,
                    'failed' => 0,
                    'active' => 0,
                ],
                'training_status' => $this->build_chatbot_training_status(0, []),
            ]);
            return;
        }

        $source_stats = $this->get_chatbot_training_source_stats_for_context(
            (string) $context['provider_label'],
            (array) $context['store_ids'],
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Validated chatbot access and nonce above; refresh the bounded local count after a source mutation.
            isset($_POST['refresh_source_stats']) && sanitize_text_field(wp_unslash($_POST['refresh_source_stats'])) === '1'
        );
        $count = $source_stats['count'];
        $queue_summary = $this->get_chatbot_training_queue_summary_for_context(
            (string) $context['provider_key'],
            (array) $context['store_ids'],
            $bot_id
        );

        wp_send_json_success([
            'count' => $count,
            'count_is_capped' => $source_stats['count_is_capped'],
            'queue' => $queue_summary,
            'training_status' => $this->build_chatbot_training_status($count, $queue_summary),
        ]);
    }

    /**
     * AJAX: Stops pending background training work for the active chatbot.
     * @since NEXT_VERSION
     */
    public function ajax_stop_chatbot_training()
    {
        $bot_id = $this->get_validated_chatbot_id_from_request();
        if ($bot_id <= 0) {
            return;
        }

        $context = $this->get_chatbot_training_vector_context_from_request($bot_id);
        if (empty($context['provider_label']) || empty($context['store_ids'])) {
            wp_send_json_success([
                'stopped' => false,
                'stopped_items' => 0,
                'count' => 0,
                'queue' => [
                    'pending' => 0,
                    'processing' => 0,
                    'failed' => 0,
                    'active' => 0,
                ],
                'training_status' => $this->build_chatbot_training_status(0, []),
            ]);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $requested_task_id = isset($_POST['task_id']) ? absint($_POST['task_id']) : 0;
        $task_ids = $this->get_chatbot_training_task_ids_for_context(
            $bot_id,
            (string) $context['provider_key'],
            (array) $context['store_ids'],
            $requested_task_id
        );

        $stopped_items = 0;
        if (!empty($task_ids)) {
            $this->clear_chatbot_training_task_events($task_ids);

            global $wpdb;
            $queue_table_name = $wpdb->prefix . 'aipkit_automated_task_queue';
            $tasks_table_name = $wpdb->prefix . 'aipkit_automated_tasks';
            $queue_table_identifier = $this->get_validated_table_identifier($queue_table_name);
            $tasks_table_identifier = $this->get_validated_table_identifier($tasks_table_name);
            if ($queue_table_identifier === '' || $tasks_table_identifier === '') {
                wp_send_json_error(['message' => __('Unable to stop chatbot training.', 'gpt3-ai-content-generator')], 500);
                return;
            }

            $task_placeholders = implode(',', array_fill(0, count($task_ids), '%d'));
            $status_placeholders = implode(',', array_fill(0, 2, '%s'));
            $delete_params = array_merge($task_ids, ['content_indexing', 'pending', 'processing']);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Admin stop action over plugin-owned queue table with prepared task IDs/statuses.
            $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$queue_table_identifier} WHERE task_id IN ($task_placeholders) AND task_type = %s AND status IN ($status_placeholders)", ...$delete_params));
            $stopped_items = $deleted === false ? 0 : (int) $deleted;

            $update_params = array_merge(['paused', current_time('mysql', 1)], $task_ids);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Admin stop action over plugin-owned task table with prepared task IDs.
            $wpdb->query($wpdb->prepare("UPDATE {$tasks_table_identifier} SET status = %s, next_run_time = NULL, updated_at = %s WHERE id IN ($task_placeholders)", ...$update_params));

            foreach ($task_ids as $task_id) {
                delete_transient('aipkit_initial_queue_page_' . absint($task_id));
                set_transient('aipkit_initial_queue_done_' . absint($task_id), 'yes', MONTH_IN_SECONDS);
                delete_option('aipkit_initial_queue_cursor_v2_' . absint($task_id));
                delete_option('aipkit_initial_queue_force_v2_' . absint($task_id));
                update_option('aipkit_initial_queue_done_v2_' . absint($task_id), 'yes', false);
            }
        }

        $source_stats = $this->get_chatbot_training_source_stats_for_context(
            (string) $context['provider_label'],
            (array) $context['store_ids']
        );
        $count = $source_stats['count'];
        $queue_summary = $this->get_chatbot_training_queue_summary_for_context(
            (string) $context['provider_key'],
            (array) $context['store_ids'],
            $bot_id
        );

        wp_send_json_success([
            'stopped' => !empty($task_ids),
            'stopped_items' => $stopped_items,
            'count' => $count,
            'count_is_capped' => $source_stats['count_is_capped'],
            'queue' => $queue_summary,
            'training_status' => $this->build_chatbot_training_status($count, $queue_summary),
        ]);
    }

    private function get_chatbot_training_vector_context_from_request(int $bot_id): array
    {
        if (!class_exists(BotSettingsManager::class)) {
            $manager_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/bots.php';
            if (file_exists($manager_path)) {
                require_once $manager_path;
            }
        }

        $settings_manager = class_exists(BotSettingsManager::class) ? new BotSettingsManager() : null;
        $settings = $settings_manager ? $settings_manager->get_chatbot_settings($bot_id) : [];

        // Optional overrides from UI (for instant reflection before autosave completes).
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $override_enable_vector_store = isset($_POST['enable_vector_store'])
            ? sanitize_text_field(wp_unslash($_POST['enable_vector_store']))
            : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $override_provider = isset($_POST['vector_store_provider'])
            ? sanitize_key(wp_unslash($_POST['vector_store_provider']))
            : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $has_openai_override = isset($_POST['openai_vector_store_ids']);
        $override_openai_ids = $has_openai_override
            ? (array) wp_unslash($_POST['openai_vector_store_ids'])
            : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $has_google_override = isset($_POST['google_file_search_store_names']);
        $override_google_store_names = $has_google_override
            ? (array) wp_unslash($_POST['google_file_search_store_names'])
            : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $has_pinecone_override = isset($_POST['pinecone_index_name']);
        $override_pinecone_index = $has_pinecone_override
            ? sanitize_text_field(wp_unslash($_POST['pinecone_index_name']))
            : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $has_qdrant_override = isset($_POST['qdrant_collection_names']);
        $override_qdrant_names = $has_qdrant_override
            ? (array) wp_unslash($_POST['qdrant_collection_names'])
            : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $has_chroma_override = isset($_POST['chroma_collection_names']);
        $override_chroma_names = $has_chroma_override
            ? (array) wp_unslash($_POST['chroma_collection_names'])
            : null;

        $vector_store_enabled = $settings['enable_vector_store'] ?? BotSettingsManager::DEFAULT_ENABLE_VECTOR_STORE;
        if ($override_enable_vector_store !== null) {
            $vector_store_enabled = in_array($override_enable_vector_store, ['0', '1'], true)
                ? $override_enable_vector_store
                : $vector_store_enabled;
        }
        if ($vector_store_enabled !== '1') {
            return [
                'enabled' => false,
                'provider_key' => '',
                'provider_label' => '',
                'store_ids' => [],
            ];
        }

        $provider_key = $settings['vector_store_provider'] ?? BotSettingsManager::DEFAULT_VECTOR_STORE_PROVIDER;
        if ($override_provider && in_array($override_provider, ['openai', 'pinecone', 'qdrant', 'chroma', 'local', 'claude_files', 'google'], true)) {
            $provider_key = $override_provider;
        }
        $chatbot_provider = (string) ($settings['provider'] ?? get_post_meta($bot_id, '_aipkit_provider', true));
        if (!BotSettingsManager::is_vector_store_provider_compatible($chatbot_provider, $provider_key)) {
            return [
                'enabled' => false,
                'provider_key' => '',
                'provider_label' => '',
                'store_ids' => [],
            ];
        }
        $provider_map = [
            'openai' => 'OpenAI',
            'pinecone' => 'Pinecone',
            'qdrant' => 'Qdrant',
            'chroma' => 'Chroma',
            'local' => 'Local',
            'google' => 'Google',
        ];
        $provider_label = $provider_map[$provider_key] ?? '';
        if ($provider_label === '') {
            return [
                'enabled' => true,
                'provider_key' => $provider_key,
                'provider_label' => '',
                'store_ids' => [],
            ];
        }

        $store_ids = [];
        if ($provider_key === 'openai') {
            if ($has_openai_override && is_array($override_openai_ids)) {
                $store_ids = array_filter(array_map('sanitize_text_field', $override_openai_ids));
            } else {
                $store_ids = $settings['openai_vector_store_ids'] ?? [];
            }
            if (!is_array($store_ids)) {
                $store_ids = json_decode((string) $store_ids, true);
            }
        } elseif ($provider_key === 'google') {
            $store_ids = $has_google_override && is_array($override_google_store_names)
                ? $override_google_store_names
                : ($settings['google_file_search_store_names'] ?? []);
        } elseif ($provider_key === 'pinecone') {
            if ($has_pinecone_override) {
                $store_ids = !empty($override_pinecone_index) ? [$override_pinecone_index] : [];
            } else {
                $store_ids = !empty($settings['pinecone_index_name'])
                    ? [$settings['pinecone_index_name']]
                    : [];
            }
        } elseif ($provider_key === 'qdrant') {
            if ($has_qdrant_override && is_array($override_qdrant_names)) {
                $store_ids = array_filter(array_map('sanitize_text_field', $override_qdrant_names));
            } else {
                $store_ids = $settings['qdrant_collection_names'] ?? [];
            }
            if (!is_array($store_ids)) {
                $store_ids = json_decode((string) $store_ids, true);
            }
            if (empty($store_ids) && !empty($settings['qdrant_collection_name']) && !$has_qdrant_override) {
                $store_ids = [$settings['qdrant_collection_name']];
            }
        } elseif ($provider_key === 'local') {
            $store_ids = $this->local_store_ids_for_request($settings);
        } elseif ($provider_key === 'chroma') {
            if ($has_chroma_override && is_array($override_chroma_names)) {
                $store_ids = array_filter(array_map('sanitize_text_field', $override_chroma_names));
            } else {
                $store_ids = $settings['chroma_collection_names'] ?? [];
            }
            if (!is_array($store_ids)) {
                $store_ids = json_decode((string) $store_ids, true);
            }
            if (empty($store_ids) && !empty($settings['chroma_collection_name']) && !$has_chroma_override) {
                $store_ids = [$settings['chroma_collection_name']];
            }
        }

        $store_ids = array_filter(array_map('sanitize_text_field', (array) $store_ids));
        $store_ids = array_values(array_unique($store_ids));

        return [
            'enabled' => true,
            'provider_key' => $provider_key,
            'provider_label' => $provider_label,
            'store_ids' => $store_ids,
        ];
    }

    /** @return array{count:int,count_is_capped:bool} */
    private function get_chatbot_training_source_stats_for_context(string $provider_label, array $store_ids, bool $refresh = false): array
    {
        $store_ids = array_values(array_unique(array_filter(array_map('sanitize_text_field', $store_ids))));
        if ($provider_label === '' || empty($store_ids)) {
            return ['count' => 0, 'count_is_capped' => false];
        }

        sort($store_ids, SORT_STRING);
        $cache_key = 'aipkit_chatbot_training_stats_' . md5($provider_label . '|' . wp_json_encode($store_ids));
        $cached_stats = get_transient($cache_key);
        if (!$refresh && is_array($cached_stats)) {
            return [
                'count' => max(0, (int) ($cached_stats['count'] ?? 0)),
                'count_is_capped' => !empty($cached_stats['count_is_capped']),
            ];
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'aipkit_vector_data_source';
        $placeholders = implode(',', array_fill(0, count($store_ids), '%s'));
        $limit = 1001;
        $params = array_merge([$provider_label], $store_ids, [$limit]);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dynamic IN placeholders match the sanitized store ID parameters; this is a bounded preview over indexed columns in a plugin-owned table.
        $source_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$table_name} WHERE provider = %s AND vector_store_id IN ($placeholders) AND (post_id IS NOT NULL OR file_id IS NOT NULL OR indexed_content IS NOT NULL) LIMIT %d", ...$params));
        $count_is_capped = count((array) $source_ids) >= $limit;
        $stats = [
            'count' => $count_is_capped ? $limit - 1 : count((array) $source_ids),
            'count_is_capped' => $count_is_capped,
        ];
        set_transient($cache_key, $stats, 30);
        return $stats;
    }

    private function get_chatbot_training_task_ids_for_context(int $bot_id, string $provider_key, array $store_ids, int $requested_task_id = 0): array
    {
        $store_ids = array_values(array_filter(array_map('sanitize_text_field', $store_ids)));
        if ($bot_id <= 0 || $provider_key === '' || empty($store_ids)) {
            return [];
        }

        global $wpdb;
        $tasks_table_name = $wpdb->prefix . 'aipkit_automated_tasks';
        $tasks_table_identifier = $this->get_validated_table_identifier($tasks_table_name);
        if ($tasks_table_identifier === '') {
            return [];
        }

        if ($requested_task_id > 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Admin stop action over a validated plugin-owned task table.
            $rows = $wpdb->get_results($wpdb->prepare("SELECT id, task_name, task_type, task_config FROM {$tasks_table_identifier} WHERE id = %d LIMIT 1", $requested_task_id), ARRAY_A);
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Admin stop action over a validated plugin-owned task table.
            $rows = $wpdb->get_results($wpdb->prepare("SELECT id, task_name, task_type, task_config FROM {$tasks_table_identifier} WHERE task_type = %s ORDER BY id DESC LIMIT 500", 'content_indexing'), ARRAY_A);
        }
        if (empty($rows)) {
            return [];
        }

        $task_ids = [];
        foreach ($rows as $row) {
            $task_config = json_decode((string) ($row['task_config'] ?? ''), true);
            if (!is_array($task_config)) {
                $task_config = [];
            }
            if (!$this->is_chatbot_training_task_for_context($row, $task_config, $bot_id, $provider_key, $store_ids)) {
                continue;
            }
            $task_id = isset($row['id']) ? absint($row['id']) : 0;
            if ($task_id > 0) {
                $task_ids[] = $task_id;
            }
        }

        return array_values(array_unique($task_ids));
    }

    private function is_chatbot_training_task_for_context(array $row, array $task_config, int $bot_id, string $provider_key, array $store_ids): bool
    {
        if ((string) ($row['task_type'] ?? 'content_indexing') !== 'content_indexing') {
            return false;
        }
        $task_provider = sanitize_key((string) ($task_config['target_store_provider'] ?? ''));
        $task_store_id = sanitize_text_field((string) ($task_config['target_store_id'] ?? ''));
        if ($task_provider !== $provider_key || !in_array($task_store_id, $store_ids, true)) {
            return false;
        }

        return $this->is_chatbot_training_task_for_bot($row, $task_config, $bot_id);
    }

    private function is_chatbot_training_task_for_bot(array $row, array $task_config, int $bot_id): bool
    {
        $source_context = sanitize_key((string) ($task_config['source_context'] ?? ''));
        $task_bot_id = isset($task_config['chatbot_id']) ? absint($task_config['chatbot_id']) : 0;
        if ($source_context !== '' || $task_bot_id > 0) {
            return $source_context === 'chatbot_training' && $task_bot_id === $bot_id;
        }

        $task_name = sanitize_text_field((string) ($row['task_name'] ?? ''));
        return $task_name === sprintf('Chatbot %d website indexing', $bot_id);
    }

    private function get_chatbot_training_queue_summary_for_context(string $provider_key, array $store_ids, int $bot_id = 0): array
    {
        $summary = [
            'pending' => 0,
            'processing' => 0,
            'failed' => 0,
            'active' => 0,
            'pending_is_capped' => false,
            'processing_is_capped' => false,
            'failed_is_capped' => false,
        ];
        $store_ids = array_values(array_filter(array_map('sanitize_text_field', $store_ids)));
        if ($provider_key === '' || empty($store_ids) || $bot_id <= 0) {
            return $summary;
        }

        $task_ids = $this->get_chatbot_training_task_ids_for_context($bot_id, $provider_key, $store_ids);
        if (empty($task_ids)) {
            return $summary;
        }

        global $wpdb;
        $queue_table_name = $wpdb->prefix . 'aipkit_automated_task_queue';
        $statuses = ['pending', 'processing', 'failed'];
        $task_placeholders = implode(',', array_fill(0, count($task_ids), '%d'));
        $limit = 1001;
        foreach ($statuses as $status) {
            $params = array_merge($task_ids, ['content_indexing', $status, $limit]);
            $query = 'SELECT id FROM ' . esc_sql($queue_table_name) . " WHERE task_id IN ($task_placeholders) AND task_type = %s AND status = %s LIMIT %d";
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bounded status preview over known chatbot task IDs in a plugin-owned table.
            $queue_ids = $wpdb->get_col($wpdb->prepare($query, ...$params));
            $is_capped = count((array) $queue_ids) >= $limit;
            $summary[$status] = $is_capped ? $limit - 1 : count((array) $queue_ids);
            $summary[$status . '_is_capped'] = $is_capped;
        }

        $summary['active'] = $summary['pending'] + $summary['processing'];
        return $summary;
    }

    private function clear_chatbot_training_task_events(array $task_ids): void
    {
        $scheduler_class = '\WPAICG\AutoGPT\Cron\AIPKit_Automated_Task_Scheduler';
        if (!class_exists($scheduler_class)) {
            $scheduler_path = WPAICG_PLUGIN_DIR . 'classes/automations/scheduler.php';
            if (file_exists($scheduler_path)) {
                require_once $scheduler_path;
            }
        }
        if (!class_exists($scheduler_class)) {
            return;
        }

        foreach ($task_ids as $task_id) {
            $scheduler_class::clear_task_event(absint($task_id));
        }
    }

    private function build_chatbot_training_status(int $count, array $queue_summary): array
    {
        $pending_count = isset($queue_summary['pending']) ? (int) $queue_summary['pending'] : 0;
        $processing_count = isset($queue_summary['processing']) ? (int) $queue_summary['processing'] : 0;
        $failed_count = isset($queue_summary['failed']) ? (int) $queue_summary['failed'] : 0;

        if ($processing_count > 0) {
            return [
                'key' => 'training',
                'label' => __('Adding knowledge...', 'gpt3-ai-content-generator'),
                'type' => 'loading',
            ];
        }

        if ($count > 0) {
            return [
                'key' => 'trained',
                'label' => __('Knowledge ready', 'gpt3-ai-content-generator'),
                'type' => 'success',
            ];
        }

        if ($pending_count > 0) {
            return [
                'key' => 'training',
                'label' => __('Adding knowledge...', 'gpt3-ai-content-generator'),
                'type' => 'loading',
            ];
        }

        if ($failed_count > 0) {
            return [
                'key' => 'failed',
                'label' => __('Failed', 'gpt3-ai-content-generator'),
                'type' => 'error',
            ];
        }

        return [
            'key' => 'not_trained',
            'label' => __('No knowledge yet', 'gpt3-ai-content-generator'),
            'type' => 'neutral',
        ];
    }

    /**
     * AJAX: Returns training source records for the active knowledge base.
     * @since NEXT_VERSION
     */
    public function ajax_get_chatbot_training_sources()
    {
        $bot_id = $this->get_validated_chatbot_id_from_request();
        if ($bot_id <= 0) {
            return;
        }

        if (!class_exists(BotSettingsManager::class)) {
            $manager_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/bots.php';
            if (file_exists($manager_path)) {
                require_once $manager_path;
            }
        }

        $settings_manager = class_exists(BotSettingsManager::class) ? new BotSettingsManager() : null;
        $settings = $settings_manager ? $settings_manager->get_chatbot_settings($bot_id) : [];

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $override_enable_vector_store = isset($_POST['enable_vector_store'])
            ? sanitize_text_field(wp_unslash($_POST['enable_vector_store']))
            : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $override_provider = isset($_POST['vector_store_provider'])
            ? sanitize_key(wp_unslash($_POST['vector_store_provider']))
            : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $has_openai_override = isset($_POST['openai_vector_store_ids']);
        $override_openai_ids = $has_openai_override
            ? (array) wp_unslash($_POST['openai_vector_store_ids'])
            : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $has_google_override = isset($_POST['google_file_search_store_names']);
        $override_google_store_names = $has_google_override
            ? (array) wp_unslash($_POST['google_file_search_store_names'])
            : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $has_pinecone_override = isset($_POST['pinecone_index_name']);
        $override_pinecone_index = $has_pinecone_override
            ? sanitize_text_field(wp_unslash($_POST['pinecone_index_name']))
            : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $has_qdrant_override = isset($_POST['qdrant_collection_names']);
        $override_qdrant_names = $has_qdrant_override
            ? (array) wp_unslash($_POST['qdrant_collection_names'])
            : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $has_chroma_override = isset($_POST['chroma_collection_names']);
        $override_chroma_names = $has_chroma_override
            ? (array) wp_unslash($_POST['chroma_collection_names'])
            : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $include_inactive = isset($_POST['include_inactive'])
            && sanitize_text_field(wp_unslash($_POST['include_inactive'])) === '1';

        $vector_store_enabled = $settings['enable_vector_store'] ?? BotSettingsManager::DEFAULT_ENABLE_VECTOR_STORE;
        if ($override_enable_vector_store !== null) {
            $vector_store_enabled = in_array($override_enable_vector_store, ['0', '1'], true)
                ? $override_enable_vector_store
                : $vector_store_enabled;
        }
        if (!$include_inactive && $vector_store_enabled !== '1') {
            wp_send_json_success([
                'logs' => [],
                'pagination' => [
                    'total_logs' => 0,
                    'total_pages' => 0,
                    'current_page' => 1,
                ],
            ]);
            return;
        }

        $provider_key = $settings['vector_store_provider'] ?? BotSettingsManager::DEFAULT_VECTOR_STORE_PROVIDER;
        if ($override_provider && in_array($override_provider, ['openai', 'pinecone', 'qdrant', 'chroma', 'local', 'claude_files', 'google'], true)) {
            $provider_key = $override_provider;
        }
        $chatbot_provider = (string) ($settings['provider'] ?? get_post_meta($bot_id, '_aipkit_provider', true));
        if (
            !$include_inactive
            && !BotSettingsManager::is_vector_store_provider_compatible($chatbot_provider, $provider_key)
        ) {
            wp_send_json_success([
                'logs' => [],
                'pagination' => [
                    'total_logs' => 0,
                    'total_pages' => 0,
                    'current_page' => 1,
                ],
            ]);
            return;
        }
        $provider_map = [
            'openai' => 'OpenAI',
            'pinecone' => 'Pinecone',
            'qdrant' => 'Qdrant',
            'chroma' => 'Chroma',
            'local' => 'Local',
            'google' => 'Google',
        ];
        $provider_label = $provider_map[$provider_key] ?? '';
        if (!$provider_label) {
            wp_send_json_success([
                'logs' => [],
                'pagination' => [
                    'total_logs' => 0,
                    'total_pages' => 0,
                    'current_page' => 1,
                ],
            ]);
            return;
        }

        $store_ids = [];
        if ($provider_key === 'openai') {
            $store_ids = is_array($override_openai_ids)
                ? array_filter(array_map('sanitize_text_field', $override_openai_ids))
                : array_filter((array) ($settings['openai_vector_store_ids'] ?? []));
        } elseif ($provider_key === 'google') {
            $store_ids = is_array($override_google_store_names)
                ? array_filter(array_map('sanitize_text_field', $override_google_store_names))
                : array_filter((array) ($settings['google_file_search_store_names'] ?? []));
        } elseif ($provider_key === 'pinecone') {
            $store_id = $override_pinecone_index !== null
                ? $override_pinecone_index
                : ($settings['pinecone_index_name'] ?? '');
            if ($store_id) {
                $store_ids = [sanitize_text_field($store_id)];
            }
        } elseif ($provider_key === 'qdrant') {
            $store_ids = is_array($override_qdrant_names)
                ? array_filter(array_map('sanitize_text_field', $override_qdrant_names))
                : array_filter((array) ($settings['qdrant_collection_names'] ?? []));
        } elseif ($provider_key === 'local') {
            $store_ids = $this->local_store_ids_for_request($settings);
        } elseif ($provider_key === 'chroma') {
            $store_ids = is_array($override_chroma_names)
                ? array_filter(array_map('sanitize_text_field', $override_chroma_names))
                : array_filter((array) ($settings['chroma_collection_names'] ?? []));
        }

        if (empty($store_ids)) {
            wp_send_json_success([
                'logs' => [],
                'pagination' => [
                    'total_logs' => 0,
                    'total_pages' => 0,
                    'current_page' => 1,
                ],
            ]);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $page = isset($_POST['page']) ? max(1, absint($_POST['page'])) : 1;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $per_page = isset($_POST['per_page']) ? absint($_POST['per_page']) : 10;
        $per_page = min(50, max(1, $per_page));
        $offset = ($page - 1) * $per_page;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled in check_module_access_permissions method.
        $include_total = !isset($_POST['include_total']) || sanitize_text_field(wp_unslash($_POST['include_total'])) !== '0';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled in check_module_access_permissions method.
        $cursor_mode = isset($_POST['cursor_mode']) && sanitize_text_field(wp_unslash($_POST['cursor_mode'])) === '1';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled in check_module_access_permissions method.
        $cursor_timestamp = isset($_POST['cursor_timestamp']) ? sanitize_text_field(wp_unslash($_POST['cursor_timestamp'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled in check_module_access_permissions method.
        $cursor_id = isset($_POST['cursor_id']) ? absint($_POST['cursor_id']) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled in check_module_access_permissions method.
        $include_summary = isset($_POST['include_summary']) && sanitize_text_field(wp_unslash($_POST['include_summary'])) === '1';

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $status_filter = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : '';
        $allowed_statuses = ['indexed', 'failed', 'processing', 'queued', 'skipped_already_indexed'];
        if ($status_filter && !in_array($status_filter, $allowed_statuses, true)) {
            $status_filter = '';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce verification is handled in check_module_access_permissions method.
        $source_type_filter = isset($_POST['source_type']) ? sanitize_key(wp_unslash($_POST['source_type'])) : '';
        if ($source_type_filter && !in_array($source_type_filter, ['site', 'text', 'file'], true)) {
            $source_type_filter = '';
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'aipkit_vector_data_source';
        require_once WPAICG_PLUGIN_DIR . 'classes/knowledge-base/file-source-groups.php';
        $raw_table = $table_name;
        $file_group_key = \WPAICG\KnowledgeBase\FileSourceGroups::key_sql();
        // Group before filtering and pagination so a file never spans multiple source pages.
        $table_name = "(SELECT source.* FROM {$raw_table} source INNER JOIN (SELECT MAX(id) AS source_id FROM {$raw_table} GROUP BY {$file_group_key}) file_groups ON source.id = file_groups.source_id) sources";

        // Each status and kind is one condition, shared by the list filters and the summary counts.
        $status_clause = static function (string $status): array {
            if ($status === 'processing') {
                return ['status IN (%s, %s)', ['processing', 'queued']];
            }
            if ($status === 'indexed') {
                return ['status IN (%s, %s, %s)', ['indexed', 'skipped_already_indexed', 'success']];
            }
            return ['status = %s', [$status]];
        };
        $kind_clause = static function (string $kind): array {
            if ($kind === 'site') {
                return ['(' . implode(' OR ', [
                    '(post_id IS NOT NULL AND post_id > 0)',
                    '(message LIKE %s)',
                    '(file_id LIKE %s)',
                    '(provider = %s AND message LIKE %s AND message LIKE %s)',
                ]) . ')', [
                    '%wordpress post content submitted for indexing%',
                    'wp_post_%',
                    'Qdrant',
                    '%points upserted to qdrant%',
                    '%post id:%',
                ]];
            }
            if ($kind === 'text') {
                return ['(' . implode(' OR ', [
                    '(message LIKE %s)',
                    '(file_id LIKE %s)',
                    '(provider = %s AND message LIKE %s AND (post_id IS NULL OR post_id = 0) AND message NOT LIKE %s)',
                    '(provider = %s AND message LIKE %s AND (post_id IS NULL OR post_id = 0))',
                ]) . ')', [
                    '%text content submitted for indexing%',
                    'text_%',
                    'Qdrant',
                    '%points upserted to qdrant%',
                    '%post id:%',
                    'Chroma',
                    '%chroma records upserted%',
                ]];
            }
            return ['(' . implode(' OR ', [
                '(message LIKE %s)',
                '(message LIKE %s)',
                '(message LIKE %s)',
                '(message LIKE %s)',
                '(file_id LIKE %s)',
                '(message LIKE %s)',
                '(file_id LIKE %s)',
            ]) . ')', [
                '%file content submitted for indexing%',
                '%file content embedded and upserted%',
                '%original filename:%',
                '%file uploaded%',
                'pinecone_file_%',
                '%file chunk embedded%',
                'chroma_file_%',
            ]];
        };

        $where_clauses = ['provider = %s'];
        $params = [$provider_label];

        $store_placeholders = implode(',', array_fill(0, count($store_ids), '%s'));
        $where_clauses[] = "vector_store_id IN ($store_placeholders)";
        $params = array_merge($params, $store_ids);

        if ($search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where_clauses[] = '(message LIKE %s OR post_title LIKE %s OR file_id LIKE %s OR vector_store_name LIKE %s OR indexed_content LIKE %s)';
            $where_clauses[count($where_clauses) - 1] = '(' . $where_clauses[count($where_clauses) - 1] . " OR {$file_group_key} IN (SELECT {$file_group_key} FROM {$raw_table} WHERE indexed_content LIKE %s))";
            $params = array_merge($params, array_fill(0, 6, $like));
        }

        // What it knows: counts by kind and status for this search, whatever the list shows.
        $summary = null;
        if ($include_summary) {
            $summary_kinds = ['all', 'site', 'text', 'file'];
            $summary_statuses = ['all' => '', 'ready' => 'indexed', 'adding' => 'processing', 'failed' => 'failed'];
            $columns = [];
            $column_params = [];
            foreach ($summary_kinds as $kind) {
                [$kind_sql, $kind_params] = $kind === 'all' ? ['1 = 1', []] : $kind_clause($kind);
                foreach ($summary_statuses as $status) {
                    [$status_sql, $status_params] = $status === '' ? ['1 = 1', []] : $status_clause($status);
                    // Each count gets its own name: unnamed expressions can share a truncated name and overwrite each other.
                    $columns[] = "SUM(CASE WHEN {$kind_sql} AND {$status_sql} THEN 1 ELSE 0 END) AS count_" . count($columns);
                    $column_params = array_merge($column_params, $kind_params, $status_params);
                }
            }
            $summary_where_sql = implode(' AND ', $where_clauses);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name, columns and WHERE clause are internal and scalar values are prepared below.
            $counts = (array) $wpdb->get_row($wpdb->prepare('SELECT ' . implode(', ', $columns) . " FROM {$table_name} WHERE {$summary_where_sql}", ...array_merge($column_params, $params)), ARRAY_A);
            $summary = [];
            $index = 0;
            foreach ($summary_kinds as $kind) {
                foreach (array_keys($summary_statuses) as $status_key) {
                    $summary[$kind][$status_key] = (int) ($counts['count_' . $index++] ?? 0);
                }
            }
        }

        if ($status_filter) {
            [$status_sql, $status_params] = $status_clause($status_filter);
            $where_clauses[] = $status_sql;
            $params = array_merge($params, $status_params);
        }
        if ($source_type_filter) {
            [$kind_sql, $kind_params] = $kind_clause($source_type_filter);
            $where_clauses[] = $kind_sql;
            $params = array_merge($params, $kind_params);
        }

        if ($cursor_mode && $cursor_timestamp !== '' && $cursor_id > 0) {
            $where_clauses[] = '(timestamp < %s OR (timestamp = %s AND id < %d))';
            $params[] = $cursor_timestamp;
            $params[] = $cursor_timestamp;
            $params[] = $cursor_id;
        }

        $where_sql = implode(' AND ', $where_clauses);

        $total_logs = null;
        if ($include_total && !$cursor_mode) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name and assembled WHERE clause are internal and scalar values are prepared below.
            $total_logs = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE {$where_sql}", ...$params));
        }

        $query_limit = ($include_total && !$cursor_mode) ? $per_page : $per_page + 1;
        $query_offset = $cursor_mode ? 0 : $offset;
        $logs_params = array_merge($params, [$query_limit, $query_offset]);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name and assembled WHERE clause are internal and scalar values are prepared below.
        $logs = $wpdb->get_results($wpdb->prepare("SELECT id, timestamp, provider, status, message, indexed_content, post_id, post_title, file_id, batch_id, embedding_provider, embedding_model, vector_store_id, vector_store_name FROM {$table_name} WHERE {$where_sql} ORDER BY timestamp DESC, id DESC LIMIT %d OFFSET %d", ...$logs_params), ARRAY_A);
        $has_more = (!$include_total || $cursor_mode) && count((array) $logs) > $per_page;
        if ($has_more) {
            array_pop($logs);
        }

        $total_pages = ($include_total && !$cursor_mode && $per_page > 0) ? (int) ceil((int) $total_logs / $per_page) : 0;
        $next_cursor = null;
        if ($cursor_mode && $has_more && !empty($logs)) {
            $last_log = end($logs);
            $next_cursor = [
                'timestamp' => (string) ($last_log['timestamp'] ?? ''),
                'id' => absint($last_log['id'] ?? 0),
            ];
        }

        foreach ($logs as &$log) {
            $chunks = \WPAICG\KnowledgeBase\FileSourceGroups::chunks($log);
            if ($chunks) {
                $log['file_chunks'] = $chunks;
            }
        }
        unset($log);

        wp_send_json_success([
            'logs' => $logs ?: [],
            'pagination' => [
                'total_logs' => $total_logs,
                'total_pages' => $total_pages,
                'current_page' => $page,
                'cursor_mode' => $cursor_mode,
                'has_previous' => $cursor_mode && $page > 1,
                'has_more' => $has_more,
                'next_cursor' => $next_cursor,
                'item_count' => count((array) $logs),
            ],
            'provider' => $provider_label,
            'summary' => $summary,
        ]);
    }
    // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
}
