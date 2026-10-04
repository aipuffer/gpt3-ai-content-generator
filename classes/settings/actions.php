<?php


namespace WPAICG\Dashboard\Ajax;

use WPAICG\AIPKit_Providers;
use WPAICG\AIPKIT_AI_Settings;
use WPAICG\Lib\Settings\SettingsFeatures;
use WPAICG\aipkit_dashboard;
use WPAICG\Core\AIPKit_Event_Webhooks_Settings;
use WPAICG\Core\Models\AIPKit_Model_Registry;
use WPAICG\Core\Moderation\AIPKit_Global_Security_Settings;
use WPAICG\Images\AIPKit_Image_Settings_Ajax_Handler;
use WPAICG\Images\AIPKit_Stock_Photo_Connection_Tester;
use WPAICG\Utils\AIPKit_Prompt_Sanitizer;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles AJAX requests for saving core AI Settings and related options.
 * Refactored for better modularity and clarity in saving different settings groups.
 */
class SettingsAjaxHandler extends BaseDashboardAjaxHandler
{
    public static function ajax_set_visitor_billing(): void
    {
        if (!\WPAICG\AIPKit_Role_Manager::user_can_manage_settings()) {
            wp_send_json_error(['message' => __('You cannot change visitor billing settings.', 'gpt3-ai-content-generator')], 403);
        }
        check_ajax_referer('aipkit_visitor_billing', 'nonce');
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact 0/1 allowlist immediately below rejects all other values.
        $enabled = isset($_POST['enabled']) && is_string($_POST['enabled']) ? wp_unslash($_POST['enabled']) : '';
        if (!in_array($enabled, ['0', '1'], true)) {
            wp_send_json_error(['message' => __('Choose a valid visitor billing setting.', 'gpt3-ai-content-generator')], 400);
        }
        if ($enabled === '1') {
            $options = get_option('aipkit_options', []);
            $options = is_array($options) ? $options : [];
            $modules = isset($options['module_settings']) && is_array($options['module_settings'])
                ? $options['module_settings']
                : [];
            $modules['stats_viewer'] = true;
            $options['module_settings'] = $modules;
            update_option('aipkit_options', $options, 'no');
        }
        update_option('aipkit_visitor_billing_enabled', $enabled === '1' ? 'yes' : 'no', false);
        wp_send_json_success(['enabled' => $enabled === '1']);
    }

    private const SETTINGS_RESTORE_POINT_OPTION = 'aipkit_settings_restore_point';

    /**
     * Model-list options that can be exported/imported by Settings Backup.
     *
     * @var array<int, string>
     */
    private const BACKUP_MODEL_LIST_OPTIONS = [
        'aipkit_openai_model_list',
        'aipkit_openai_embedding_model_list',
        'aipkit_openai_tts_model_list',
        'aipkit_openai_stt_model_list',
        'aipkit_openrouter_model_list',
        'aipkit_openrouter_embedding_model_list',
        'aipkit_google_model_list',
        'aipkit_google_embedding_model_list',
        'aipkit_google_image_model_list',
        'aipkit_google_video_model_list',
        'aipkit_google_tts_model_list',
        'aipkit_azure_deployment_list',
        'aipkit_azure_embedding_model_list',
        'aipkit_azure_image_model_list',
        'aipkit_claude_model_list',
        'aipkit_deepseek_model_list',
        'aipkit_xai_model_list',
        'aipkit_xai_image_model_list',
        'aipkit_ollama_model_list',
        'aipkit_ollama_embedding_model_list',
        'aipkit_ollama_vision_model_list',
        'aipkit_ollama_model_capability_list',
        'aipkit_elevenlabs_voice_list',
        'aipkit_elevenlabs_model_list',
        'aipkit_pinecone_index_list',
        'aipkit_qdrant_collection_list',
        'aipkit_chroma_collection_list',
        'aipkit_replicate_model_list',
        'aipkit_model_sync_timestamps',
    ];

    /**
     * Settings-owned options stored outside the main aipkit_options array.
     *
     * @var array<string, string>
     */
    private const BACKUP_ADDITIONAL_OPTIONS = [
        'native_app_recipes' => 'aipkit_native_app_recipes',
        'image_generator_settings' => AIPKit_Image_Settings_Ajax_Handler::SETTINGS_OPTION_NAME,
        'enhancer_actions' => 'aipkit_enhancer_actions',
        'training_general_settings' => 'aipkit_training_general_settings',
        'visitor_billing' => 'aipkit_visitor_billing_enabled',
        'wp_ai_client_mode' => 'aipkit_wp_ai_client_gateway_mode',
    ];

    private const BACKUP_CHOICE_OPTIONS = [
        'visitor_billing' => ['no', 'yes'],
        'wp_ai_client_mode' => ['observe', 'managed'],
    ];

    public function ajax_save_settings()
    {
        $permission_check = $this->check_module_access_permissions('settings');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions().
        $post_data = wp_unslash($_POST);

        // Store initial states to detect if any actual change occurred
        $initial_core_opts_json = wp_json_encode(get_option('aipkit_options', []));
        $initial_native_app_recipes_json = wp_json_encode(get_option('aipkit_native_app_recipes', []));
        $initial_image_generator_settings_json = wp_json_encode(get_option(AIPKit_Image_Settings_Ajax_Handler::SETTINGS_OPTION_NAME, []));
        // --- Perform Save Operations for Different Setting Groups ---
        $this->save_main_provider_selection($post_data);
        $this->save_all_provider_api_details($post_data);
        $this->save_replicate_integration_settings($post_data);
        $this->save_global_ai_parameters($post_data);
        $this->save_public_api_access_settings($post_data);
        $this->save_global_security_settings($post_data);
        $enhancer_settings_changed = $this->save_enhancer_settings($post_data);
        $this->save_semantic_search_settings($post_data);
        $this->save_event_webhooks_settings($post_data);
        if (class_exists(SettingsFeatures::class)) {
            SettingsFeatures::save_native_app_settings($post_data);
        }
        $updated_enhancer_actions = $this->save_enhancer_actions($post_data); // NEW

        // --- Check if any options actually changed ---
        $final_core_opts_json = wp_json_encode(get_option('aipkit_options', []));
        $final_native_app_recipes_json = wp_json_encode(get_option('aipkit_native_app_recipes', []));
        $final_image_generator_settings_json = wp_json_encode(get_option(AIPKit_Image_Settings_Ajax_Handler::SETTINGS_OPTION_NAME, []));

        $core_changed = ($initial_core_opts_json !== $final_core_opts_json);
        $native_app_recipes_changed = ($initial_native_app_recipes_json !== $final_native_app_recipes_json);
        $image_generator_settings_changed = ($initial_image_generator_settings_json !== $final_image_generator_settings_json);

        if ($core_changed || $native_app_recipes_changed || $image_generator_settings_changed || $enhancer_settings_changed || $updated_enhancer_actions !== null) {
            $response = [
                'message' => __('Settings saved successfully.', 'gpt3-ai-content-generator'),
                'providerStatus' => AIPKit_Providers::get_provider_status_map(),
                'newConfiguration' => AIPKit_Providers::get_new_configuration_payload(),
                'providerConnectionStates' => AIPKit_Providers::get_provider_connection_states(),
            ];
            if ($updated_enhancer_actions !== null) {
                $response['updated_enhancer_actions'] = $updated_enhancer_actions;
            }
            wp_send_json_success($response);
        } else {
            wp_send_json_success([
                'message' => __('No changes detected.', 'gpt3-ai-content-generator'),
                'providerStatus' => AIPKit_Providers::get_provider_status_map(),
                'newConfiguration' => AIPKit_Providers::get_new_configuration_payload(),
                'providerConnectionStates' => AIPKit_Providers::get_provider_connection_states(),
            ]);
        }
    }

    /**
     * Verifies a Pexels or Pixabay key with a real search before saving it.
     */
    public function ajax_connect_stock_photo_provider(): void
    {
        $permission_check = $this->check_module_access_permissions('settings');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked above.
        $provider_slug = isset($_POST['provider']) ? sanitize_key(wp_unslash($_POST['provider'])) : '';
        $provider_name = AIPKit_Stock_Photo_Connection_Tester::get_provider_name($provider_slug);
        if ($provider_name === '') {
            $this->send_wp_error(new WP_Error(
                'unsupported_stock_photo_provider',
                __('Unsupported stock photo provider.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            ));
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked above.
        $api_key = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';
        $api_key = trim($api_key);

        $tester = new AIPKit_Stock_Photo_Connection_Tester();
        $result = $tester->test($provider_slug, $api_key);
        if (is_wp_error($result)) {
            $this->send_wp_error($result);
            return;
        }

        AIPKit_Providers::save_provider_data($provider_name, ['api_key' => $api_key]);

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %s: Stock photo provider name. */
                __('%s connection verified.', 'gpt3-ai-content-generator'),
                $provider_name
            ),
            'provider' => $provider_slug,
            'providerConnectionStates' => AIPKit_Providers::get_provider_connection_states(),
        ]);
    }

    /**
     * Saves only Semantic Search settings.
     *
     * These options apply site-wide, so Knowledge Base access alone cannot
     * authorize changes, even when the form appears in that module.
     */
    public function ajax_save_semantic_search_settings()
    {
        $permission_check = $this->check_module_access_permissions('settings');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions().
        $post_data = wp_unslash($_POST);
        $changed = $this->save_semantic_search_settings($post_data);

        wp_send_json_success([
            'message' => $changed
                ? __('Settings saved successfully.', 'gpt3-ai-content-generator')
                : __('No changes detected.', 'gpt3-ai-content-generator'),
        ]);
    }

    /**
     * Updates server-owned developer credentials and their explicit access state.
     */
    public function ajax_update_developer_credential(): void
    {
        $permission_check = $this->check_module_access_permissions('settings');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked above.
        $credential_type = isset($_POST['credential']) ? sanitize_key(wp_unslash($_POST['credential'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked above.
        $operation = isset($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : '';
        if (!in_array($credential_type, ['rest_api', 'webhook'], true)
            || !in_array($operation, ['enable', 'disable', 'regenerate'], true)) {
            wp_send_json_error([
                'message' => __('Invalid developer credential action.', 'gpt3-ai-content-generator'),
            ], 400);
        }

        if ($credential_type === 'rest_api') {
            $options = get_option('aipkit_options', []);
            $options = is_array($options) ? $options : [];
            $api_keys = isset($options['api_keys']) && is_array($options['api_keys'])
                ? array_merge(AIPKIT_AI_Settings::$default_api_keys, $options['api_keys'])
                : AIPKIT_AI_Settings::$default_api_keys;

            if ($operation === 'enable') {
                $api_keys['public_api_enabled'] = '1';
                if (trim((string) ($api_keys['public_api_key'] ?? '')) === '') {
                    $api_keys['public_api_key'] = AIPKIT_AI_Settings::generate_public_api_key();
                }
            } elseif ($operation === 'disable') {
                $api_keys['public_api_enabled'] = '0';
            } else {
                $api_keys['public_api_key'] = AIPKIT_AI_Settings::generate_public_api_key();
            }

            $options['api_keys'] = $api_keys;
            update_option('aipkit_options', $options, 'no');
            wp_send_json_success([
                'credential' => (string) $api_keys['public_api_key'],
                'enabled' => (string) $api_keys['public_api_enabled'] === '1',
            ]);
        }

        if ($operation === 'enable' || $operation === 'disable') {
            $settings = AIPKit_Event_Webhooks_Settings::set_enabled($operation === 'enable');
        } else {
            $settings = AIPKit_Event_Webhooks_Settings::regenerate_signing_secret();
        }

        wp_send_json_success([
            'credential' => (string) ($settings['signing_secret'] ?? ''),
            'enabled' => (string) ($settings['enabled'] ?? '0') === '1',
        ]);
    }

    /**
     * Reveals a stored credential only after an explicit, permission-checked request.
     *
     * Initial Settings markup contains masks only. This endpoint keeps provider,
     * integration, app-connection, and developer secrets out of the page source.
     */
    public function ajax_reveal_settings_credential(): void
    {
        $permission_check = $this->check_module_access_permissions('settings');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked above.
        $scope = isset($_POST['scope']) ? sanitize_key(wp_unslash($_POST['scope'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked above.
        $identifier = isset($_POST['identifier']) ? sanitize_text_field(wp_unslash($_POST['identifier'])) : '';
        $credential = '';

        if ($scope === 'provider') {
            $provider_map = [
                'openai' => 'OpenAI',
                'claude' => 'Claude',
                'google' => 'Google',
                'openrouter' => 'OpenRouter',
                'azure' => 'Azure',
                'deepseek' => 'DeepSeek',
                'xai' => 'xAI',
            ];
            $provider = $provider_map[sanitize_key($identifier)] ?? '';
            if ($provider !== '') {
                $provider_data = AIPKit_Providers::get_provider_data($provider);
                $credential = (string) ($provider_data['api_key'] ?? '');
            }
        } elseif ($scope === 'integration') {
            $integration_map = [
                'elevenlabs' => 'ElevenLabs',
                'replicate' => 'Replicate',
                'pinecone' => 'Pinecone',
                'qdrant' => 'Qdrant',
                'chroma' => 'Chroma',
                'pexels' => 'Pexels',
                'pixabay' => 'Pixabay',
            ];
            $provider = $integration_map[sanitize_key($identifier)] ?? '';
            if ($provider !== '') {
                $provider_data = AIPKit_Providers::get_provider_data($provider);
                $credential = (string) ($provider_data['api_key'] ?? '');
            }
        } elseif ($scope === 'app_connection') {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked above.
            $field_path = isset($_POST['field']) ? sanitize_text_field(wp_unslash($_POST['field'])) : '';
            $credential = class_exists(SettingsFeatures::class)
                ? SettingsFeatures::get_app_connection_secret($identifier, $field_path)
                : '';
        } elseif ($scope === 'developer') {
            if ($identifier === 'rest_api') {
                $api_keys = AIPKIT_AI_Settings::get_api_keys();
                $credential = (string) ($api_keys['public_api_key'] ?? '');
            } elseif ($identifier === 'webhook') {
                $webhook_settings = AIPKit_Event_Webhooks_Settings::get_settings();
                $credential = (string) ($webhook_settings['signing_secret'] ?? '');
            }
        }

        if ($credential === '') {
            wp_send_json_error([
                'message' => __('The stored credential could not be revealed.', 'gpt3-ai-content-generator'),
            ], 404);
        }

        wp_send_json_success([
            'credential' => $credential,
        ]);
    }

    /**
     * Saves the main AI provider selection.
     * Calls AIPKit_Providers::save_current_provider which handles its own update_option.
     */
    private function save_main_provider_selection(array $post_data): void
    {
        $current_main_provider = isset($post_data['provider']) ? sanitize_text_field($post_data['provider']) : null;
        if ($current_main_provider) {
            AIPKit_Providers::save_current_provider($current_main_provider);
        }
    }

    /**
     * Saves API details for ALL providers if their data is present in POST.
     * Calls AIPKit_Providers::save_provider_data for each, which handles its own update_option.
     */
    private function save_all_provider_api_details(array $post_data): void
    {
        $all_provider_defaults = AIPKit_Providers::get_provider_defaults_all();

        foreach (array_keys($all_provider_defaults) as $provider_name) {
            $provider_key_prefix = strtolower($provider_name);
            $provider_data_from_post = [];
            $provider_has_data_in_post = false;
            $existing_provider_data = AIPKit_Providers::get_provider_data($provider_name);

            // Collect data for this provider from $post_data
            foreach (array_keys($all_provider_defaults[$provider_name]) as $key) {
                // Default form field name construction
                $form_field_name = $provider_key_prefix . '_' . $key;

                // Handle special form field names that don't match the $provider_key_prefix . '_' . $key pattern
                if ($provider_name === 'Azure' && $key === 'model') {
                    $form_field_name = 'azure_deployment';
                }


                if (array_key_exists($form_field_name, $post_data)) {
                    $value_from_post = $post_data[$form_field_name];
                    // Sanitize based on key
                    if (in_array($key, ['base_url', 'endpoint', 'url'], true)) {
                        $sanitized_value = esc_url_raw($value_from_post);
                    } elseif ($provider_name === 'OpenAI' && $key === 'api_mode') {
                        $sanitized_value = AIPKit_Providers::normalize_openai_api_mode($value_from_post);
                    } elseif (
                        $provider_name === 'OpenRouter'
                        && function_exists('WPAICG\\Core\\Providers\\OpenRouter\\Methods\\sanitize_routing_settings_logic')
                        && in_array($key, ['allow_fallbacks', 'require_parameters', 'data_collection', 'zdr', 'fallback_model_1', 'fallback_model_2', 'fallback_model_3'], true)
                    ) {
                        $routing_settings = \WPAICG\Core\Providers\OpenRouter\Methods\sanitize_routing_settings_logic([
                            $key => $value_from_post,
                        ]);
                        $sanitized_value = $routing_settings[$key];
                    } elseif ($key === 'store_conversation') {
                        $sanitized_value = ($value_from_post === '1' ? '1' : '0');
                    } elseif ($key === 'expiration_policy') {
                        $sanitized_value = absint($value_from_post);
                    } else {
                        $sanitized_value = sanitize_text_field($value_from_post);
                    }

                    $provider_data_from_post[$key] = $sanitized_value;
                    $provider_has_data_in_post = true;
                }
            }

            if ($provider_has_data_in_post) {
                $should_clear_vector_cache = $this->did_vector_provider_connection_change($provider_name, $provider_data_from_post, $existing_provider_data);
                AIPKit_Providers::save_provider_data($provider_name, $provider_data_from_post);
                if ($should_clear_vector_cache) {
                    $this->clear_vector_provider_cache($provider_name);
                }
            }
        }
    }

    /**
     * Detects connection-affecting changes for vector store provider lists.
     */
    private function did_vector_provider_connection_change(string $provider_name, array $new_data, array $existing_data): bool
    {
        $connection_keys_by_provider = [
            'OpenAI' => ['api_key', 'base_url', 'api_version'],
            'Pinecone' => ['api_key'],
            'Qdrant' => ['url', 'api_key'],
            'Chroma' => ['url', 'api_key', 'tenant', 'database'],
        ];
        if (empty($connection_keys_by_provider[$provider_name])) {
            return false;
        }

        foreach ($connection_keys_by_provider[$provider_name] as $key) {
            if (!array_key_exists($key, $new_data)) {
                continue;
            }
            $old_value = isset($existing_data[$key]) && is_scalar($existing_data[$key])
                ? (string) $existing_data[$key]
                : '';
            $new_value = is_scalar($new_data[$key])
                ? (string) $new_data[$key]
                : '';
            if ($old_value !== $new_value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clears stale vector targets when connection settings change.
     */
    private function clear_vector_provider_cache(string $provider_name): void
    {
        if (!in_array($provider_name, ['OpenAI', 'Pinecone', 'Qdrant', 'Chroma'], true)) {
            return;
        }

        if (!class_exists('\\WPAICG\\Vector\\AIPKit_Vector_Store_Registry')) {
            $registry_path = WPAICG_PLUGIN_DIR . 'classes/knowledge-base/stores.php';
            if (file_exists($registry_path)) {
                require_once $registry_path;
            }
        }

        if (class_exists('\\WPAICG\\Vector\\AIPKit_Vector_Store_Registry')) {
            \WPAICG\Vector\AIPKit_Vector_Store_Registry::clear_registered_stores($provider_name);
        }
    }

    /**
     * Saves Replicate image integration settings that are managed from Settings > Integrations.
     */
    private function save_replicate_integration_settings(array $post_data): void
    {
        if (!array_key_exists('replicate_disable_safety_checker', $post_data)) {
            return;
        }

        if (!class_exists(AIPKit_Image_Settings_Ajax_Handler::class) || !method_exists(AIPKit_Image_Settings_Ajax_Handler::class, 'save_replicate_settings')) {
            return;
        }

        AIPKit_Image_Settings_Ajax_Handler::save_replicate_settings([
            'disable_safety_checker' => $post_data['replicate_disable_safety_checker'],
        ]);
    }

    /**
     * Saves global AI parameters (temperature, max_tokens, etc.) to 'aipkit_options'.
     */
    private function save_global_ai_parameters(array $post_data): void
    {
        $opts = get_option('aipkit_options');
        if (!is_array($opts)) {
            $opts = [];
        }

        $default_ai_params = AIPKIT_AI_Settings::$default_ai_params;
        $existing_params = $opts['ai_parameters'] ?? $default_ai_params;
        $new_params = $existing_params;
        $changed = false;

        foreach ($default_ai_params as $key => $default_value) {
            if (isset($post_data[$key])) {
                $value_from_post = $post_data[$key];
                $value_to_set = null;
                switch ($key) {
                    case 'temperature':
                    case 'top_p':
                    case 'frequency_penalty':
                    case 'presence_penalty':
                        $val = floatval($value_from_post);
                        if ($key === 'temperature' || $key === 'frequency_penalty' || $key === 'presence_penalty') {
                            $val = max(0.0, min($val, 2.0));
                        }
                        if ($key === 'top_p') {
                            $val = max(0.0, min($val, 1.0));
                        }
                        $value_to_set = $val;
                        break;
                    default: $value_to_set = sanitize_text_field($value_from_post);
                        break;
                }
                if (!isset($new_params[$key]) || $new_params[$key] !== $value_to_set) {
                    $new_params[$key] = $value_to_set;
                    $changed = true;
                }
            }
        }
        if ($changed) {
            $opts['ai_parameters'] = $new_params;
            update_option('aipkit_options', $opts, 'no');
        }
    }

    /**
     * Saves the explicit REST API access state without accepting a user-entered key.
     */
    private function save_public_api_access_settings(array $post_data): void
    {
        if (!array_key_exists('public_api_enabled', $post_data)) {
            return;
        }

        $opts = get_option('aipkit_options');
        if (!is_array($opts)) {
            $opts = [];
        }

        $api_keys = isset($opts['api_keys']) && is_array($opts['api_keys'])
            ? array_merge(AIPKIT_AI_Settings::$default_api_keys, $opts['api_keys'])
            : AIPKIT_AI_Settings::$default_api_keys;
        $enabled = (string) $post_data['public_api_enabled'] === '1' ? '1' : '0';
        $api_keys['public_api_enabled'] = $enabled;
        if ($enabled === '1' && trim((string) ($api_keys['public_api_key'] ?? '')) === '') {
            $api_keys['public_api_key'] = AIPKIT_AI_Settings::generate_public_api_key();
        }

        $opts['api_keys'] = $api_keys;
        update_option('aipkit_options', $opts, 'no');
    }

    /**
     * Saves global security settings to aipkit_options.
     */
    private function save_global_security_settings(array $post_data): void
    {
        $raw_settings = $post_data['security'] ?? null;
        if (!is_array($raw_settings) || !class_exists(AIPKit_Global_Security_Settings::class)) {
            return;
        }

        $current_settings = AIPKit_Global_Security_Settings::get_settings();
        AIPKit_Global_Security_Settings::save_settings(array_replace_recursive($current_settings, $raw_settings));
    }

    /**
     * Saves Universal Event Webhooks settings to aipkit_options.
     *
     * @param array<string, mixed> $post_data
     * @return void
     */
    private function save_event_webhooks_settings(array $post_data): void
    {
        $raw_settings = $post_data['event_webhooks'] ?? null;
        if (!is_array($raw_settings)) {
            return;
        }

        AIPKit_Event_Webhooks_Settings::save_settings($raw_settings);
    }

    /**
     * Saves Content Enhancer settings.
     * @param array $post_data
     * @return bool True if settings were changed, false otherwise.
     */
    private function save_enhancer_settings(array $post_data): bool
    {
        $opts = get_option('aipkit_options');
        if (!is_array($opts)) {
            $opts = [];
        }

        $current_enhancer_settings = $opts['enhancer_settings'] ?? [];
        $new_enhancer_settings = $current_enhancer_settings;
        $changed = false;

        if (array_key_exists('enhancer_editor_integration', $post_data)) {
            $new_value = ($post_data['enhancer_editor_integration'] === '1') ? '1' : '0';
            if (($new_enhancer_settings['editor_integration'] ?? '1') !== $new_value) {
                $new_enhancer_settings['editor_integration'] = $new_value;
                $changed = true;
            }
        }

        if (array_key_exists('enhancer_list_button', $post_data)) {
            $new_value = ($post_data['enhancer_list_button'] === '1') ? '1' : '0';
            if (($new_enhancer_settings['show_list_button'] ?? '1') !== $new_value) {
                $new_enhancer_settings['show_list_button'] = $new_value;
                $changed = true;
            }
        }

        if ($changed) {
            $opts['enhancer_settings'] = $new_enhancer_settings;
            update_option('aipkit_options', $opts, 'no');
        }
        return $changed;
    }

    /**
     * Saves Content Enhancer custom actions.
     * @param array $post_data
     * @return array|null The updated list of actions if changes were made, otherwise null.
     */
    private function save_enhancer_actions(array $post_data): ?array
    {
        $submitted_actions = $post_data['enhancer_actions'] ?? null;
        if (!is_array($submitted_actions)) {
            return null;
        }
        $actions_option_name = 'aipkit_enhancer_actions';
        $current_actions = get_option($actions_option_name, []);
        $actions_map = [];
        foreach ($current_actions as $action) {
            if (isset($action['id'])) {
                $actions_map[$action['id']] = $action;
            }
        }
        $changed = false;
        foreach ($submitted_actions as $id => $data) {
            $label = sanitize_text_field($data['label'] ?? '');
            $prompt = AIPKit_Prompt_Sanitizer::sanitize($data['prompt'] ?? '');
            if (empty($label) || empty($prompt)) {
                continue;
            }
            if (strpos($id, 'new-') === 0) {
                // Create new action
                $new_id = 'custom-' . wp_generate_uuid4();
                $actions_map[$new_id] = ['id' => $new_id, 'label' => $label, 'prompt' => $prompt, 'is_default' => false];
                $changed = true;
            } elseif (isset($actions_map[$id]) && !$actions_map[$id]['is_default']) {
                // Update existing custom action
                if ($actions_map[$id]['label'] !== $label || $actions_map[$id]['prompt'] !== $prompt) {
                    $actions_map[$id]['label'] = $label;
                    $actions_map[$id]['prompt'] = $prompt;
                    $changed = true;
                }
            }
        }
        if ($changed) {
            $new_actions_array = array_values($actions_map);
            update_option($actions_option_name, $new_actions_array, 'no');
            return $new_actions_array;
        }
        return null;
    }

    /**
     * NEW: Saves Semantic Search settings to 'aipkit_options'.
     *
     * @param array $post_data The $_POST data array.
     */
    private function save_semantic_search_settings(array $post_data): bool
    {
        // Check if any semantic search data was submitted
        $semantic_keys_exist = array_filter(array_keys($post_data), function ($key) {
            return strpos($key, 'semantic_search_') === 0;
        });

        if (empty($semantic_keys_exist)) {
            return false; // No settings to save.
        }

        $opts = get_option('aipkit_options');
        if (!is_array($opts)) {
            $opts = [];
        }

        $current_settings = $opts['semantic_search'] ?? [];
        $new_settings = [];
        $allowed_vector_providers = ['local', 'pinecone', 'qdrant', 'chroma'];

        // Sanitize and collect new settings
        $semantic_vector_provider = isset($post_data['semantic_search_vector_provider'])
            ? $post_data['semantic_search_vector_provider']
            : ($current_settings['vector_provider'] ?? 'pinecone');
        $new_settings['vector_provider'] = sanitize_key((string) $semantic_vector_provider);
        if (!in_array($new_settings['vector_provider'], $allowed_vector_providers, true)) {
            $new_settings['vector_provider'] = 'pinecone';
        }

        $new_settings['target_id'] = isset($post_data['semantic_search_target_id'])
            ? sanitize_text_field($post_data['semantic_search_target_id'])
            : ($current_settings['target_id'] ?? '');

        $new_settings['embedding_provider'] = isset($post_data['semantic_search_embedding_provider'])
            ? sanitize_key($post_data['semantic_search_embedding_provider'])
            : ($current_settings['embedding_provider'] ?? 'openai');

        $new_settings['embedding_model'] = isset($post_data['semantic_search_embedding_model'])
            ? sanitize_text_field($post_data['semantic_search_embedding_model'])
            : ($current_settings['embedding_model'] ?? '');

        $new_settings['num_results'] = isset($post_data['semantic_search_num_results'])
            ? absint($post_data['semantic_search_num_results'])
            : ($current_settings['num_results'] ?? 5);

        $new_settings['no_results_text'] = isset($post_data['semantic_search_no_results_text'])
            ? sanitize_text_field($post_data['semantic_search_no_results_text'])
            : ($current_settings['no_results_text'] ?? __('No results found.', 'gpt3-ai-content-generator'));

        // Compare and update if changed
        if (wp_json_encode($current_settings) !== wp_json_encode($new_settings)) {
            $opts['semantic_search'] = $new_settings;
            update_option('aipkit_options', $opts, 'no');
            return true;
        }

        return false;
    }

    /**
     * Exports settings backup payload for download.
     */
    public function ajax_export_settings_backup()
    {
        $permission_check = $this->check_module_access_permissions('settings');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        $export_payload = $this->build_settings_backup_payload();
        $timestamp = gmdate('Y-m-d-His');

        wp_send_json_success([
            'message' => __('Settings backup is ready.', 'gpt3-ai-content-generator'),
            'filename' => 'aipkit-settings-backup-' . $timestamp . '.json',
            'export_data' => $export_payload,
        ]);
    }

    /**
     * Imports settings backup payload from uploaded JSON.
     */
    public function ajax_import_settings_backup()
    {
        $permission_check = $this->check_module_access_permissions('settings');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is verified above and the uploaded file array is validated structurally before use.
        $uploaded_file = (isset($_FILES['settings_backup_file']) && is_array($_FILES['settings_backup_file']))
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Returning the validated upload array for further structural checks below.
            ? $_FILES['settings_backup_file']
            : null;

        if (!is_array($uploaded_file)) {
            $this->send_wp_error(new WP_Error(
                'missing_import_file',
                __('No backup file was uploaded.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            ));
            return;
        }

        if (!empty($uploaded_file['error'])) {
            $this->send_wp_error(new WP_Error(
                'upload_error',
                __('Failed to read uploaded backup file.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            ));
            return;
        }

        $uploaded_size = isset($uploaded_file['size']) ? absint($uploaded_file['size']) : 0;
        if ($uploaded_size > 5 * MB_IN_BYTES) {
            $this->send_wp_error(new WP_Error(
                'import_file_too_large',
                __('Backup files must be 5 MB or smaller.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            ));
            return;
        }

        $tmp_path = isset($uploaded_file['tmp_name']) ? (string) $uploaded_file['tmp_name'] : '';
        if ($tmp_path === '' || !is_readable($tmp_path)) {
            $this->send_wp_error(new WP_Error(
                'invalid_upload_path',
                __('Uploaded backup file is not readable.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            ));
            return;
        }

        $raw_content = file_get_contents($tmp_path);
        if ($raw_content === false || trim((string) $raw_content) === '') {
            $this->send_wp_error(new WP_Error(
                'empty_import_file',
                __('Backup file is empty.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            ));
            return;
        }

        $decoded_payload = json_decode($raw_content, true);
        if (!is_array($decoded_payload)) {
            $this->send_wp_error(new WP_Error(
                'invalid_import_json',
                __('Backup file must be valid JSON.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            ));
            return;
        }

        $apply_result = $this->apply_imported_settings_payload($decoded_payload);
        if (is_wp_error($apply_result)) {
            $this->send_wp_error($apply_result);
            return;
        }

        wp_send_json_success([
            'message' => __('Global settings imported successfully. Chatbots and knowledge base data were not changed.', 'gpt3-ai-content-generator'),
        ]);
    }

    /**
     * Creates a server-side restore point from current settings.
     */
    public function ajax_create_settings_restore_point()
    {
        $permission_check = $this->check_module_access_permissions('settings');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        update_option(self::SETTINGS_RESTORE_POINT_OPTION, $this->build_settings_backup_payload(), 'no');

        wp_send_json_success([
            'message' => __('Restore point created.', 'gpt3-ai-content-generator'),
        ]);
    }

    /**
     * Restores settings from the latest server-side restore point.
     */
    public function ajax_restore_settings_restore_point()
    {
        $permission_check = $this->check_module_access_permissions('settings');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        $restore_payload = get_option(self::SETTINGS_RESTORE_POINT_OPTION, []);
        if (!is_array($restore_payload) || empty($restore_payload)) {
            $this->send_wp_error(new WP_Error(
                'restore_point_missing',
                __('No restore point found.', 'gpt3-ai-content-generator'),
                ['status' => 404]
            ));
            return;
        }

        $apply_result = $this->apply_imported_settings_payload($restore_payload);
        if (is_wp_error($apply_result)) {
            $this->send_wp_error($apply_result);
            return;
        }

        wp_send_json_success([
            'message' => __('Restore point applied successfully.', 'gpt3-ai-content-generator'),
        ]);
    }

    /**
     * Builds a normalized backup payload.
     */
    private function build_settings_backup_payload(): array
    {
        $options = get_option('aipkit_options', []);
        $options = is_array($options) ? $options : [];

        return [
            'format' => 'aipkit_settings_backup_v3',
            'exported_at' => gmdate('c'),
            'plugin_version' => defined('WPAICG_VERSION') ? WPAICG_VERSION : '',
            'site_url' => home_url('/'),
            'aipkit_options' => $this->sanitize_imported_ai_settings($options),
            'additional_options' => $this->collect_additional_options_for_backup(),
            'model_lists' => $this->collect_model_list_options_for_backup(),
            'model_registry' => AIPKit_Model_Registry::export_state(),
        ];
    }

    /**
     * Collects Settings data stored outside the main options array.
     */
    private function collect_additional_options_for_backup(): array
    {
        $additional_options = [];
        foreach (self::BACKUP_ADDITIONAL_OPTIONS as $payload_key => $option_name) {
            if (isset(self::BACKUP_CHOICE_OPTIONS[$payload_key])) {
                $choices = self::BACKUP_CHOICE_OPTIONS[$payload_key];
                $default = $choices[0];
                if ($payload_key === 'visitor_billing' && class_exists('\\WPAICG\\Stats\\AIPKit_Stats')) {
                    $default = \WPAICG\Stats\AIPKit_Stats::visitor_billing_enabled() ? 'yes' : 'no';
                }
                $value = get_option($option_name, $default);
                $additional_options[$payload_key] = in_array($value, $choices, true) ? $value : $default;
                continue;
            }
            $value = get_option($option_name, []);
            $additional_options[$payload_key] = is_array($value)
                ? $this->sanitize_recursive_value($value)
                : [];
        }

        return $additional_options;
    }

    /**
     * Collects model-list options for backup.
     */
    private function collect_model_list_options_for_backup(): array
    {
        $model_lists = [];
        foreach (self::BACKUP_MODEL_LIST_OPTIONS as $option_name) {
            $value = get_option($option_name, []);
            if (is_array($value) && !empty($value)) {
                $model_lists[$option_name] = $this->sanitize_recursive_value($value);
            }
        }

        return $model_lists;
    }

    /**
     * Applies a backup payload to options/model lists.
     *
     * @param array $payload Decoded JSON backup payload.
     * @return true|WP_Error
     */
    private function apply_imported_settings_payload(array $payload)
    {
        $imported_options = null;
        if (isset($payload['aipkit_options']) && is_array($payload['aipkit_options'])) {
            $imported_options = $payload['aipkit_options'];
        } elseif (isset($payload['providers']) && is_array($payload['providers'])) {
            // Support importing raw aipkit_options-shaped JSON.
            $imported_options = $payload;
        }

        if (!is_array($imported_options)) {
            return new WP_Error(
                'invalid_import_format',
                __('Backup payload is missing the settings block.', 'gpt3-ai-content-generator'),
                ['status' => 400]
            );
        }

        // Normalize every block before mutating any option so malformed payloads
        // cannot leave Settings half-restored.
        $sanitized_options = $this->sanitize_imported_ai_settings($imported_options);
        $normalized_model_lists = [];
        if (isset($payload['model_lists'])) {
            if (!is_array($payload['model_lists'])) {
                return new WP_Error(
                    'invalid_model_lists',
                    __('Backup model-list data is invalid.', 'gpt3-ai-content-generator'),
                    ['status' => 400]
                );
            }
            foreach ($payload['model_lists'] as $option_name => $model_list_value) {
                if (!in_array($option_name, self::BACKUP_MODEL_LIST_OPTIONS, true)) {
                    continue;
                }
                if (!is_array($model_list_value)) {
                    return new WP_Error(
                        'invalid_model_list',
                        __('A model-list entry in the backup is invalid.', 'gpt3-ai-content-generator'),
                        ['status' => 400]
                    );
                }
                $normalized_model_lists[$option_name] = $this->sanitize_recursive_value($model_list_value);
            }
        }

        $normalized_additional_options = [];
        if (isset($payload['additional_options'])) {
            if (!is_array($payload['additional_options'])) {
                return new WP_Error(
                    'invalid_additional_options',
                    __('Backup auxiliary settings data is invalid.', 'gpt3-ai-content-generator'),
                    ['status' => 400]
                );
            }
            foreach (self::BACKUP_ADDITIONAL_OPTIONS as $payload_key => $option_name) {
                if (!array_key_exists($payload_key, $payload['additional_options'])) {
                    continue;
                }
                $value = $payload['additional_options'][$payload_key];
                if (isset(self::BACKUP_CHOICE_OPTIONS[$payload_key])) {
                    if (!in_array($value, self::BACKUP_CHOICE_OPTIONS[$payload_key], true)) {
                        return new WP_Error('invalid_additional_option', __('An auxiliary settings value in the backup is invalid.', 'gpt3-ai-content-generator'), ['status' => 400]);
                    }
                    $normalized_additional_options[$option_name] = $value;
                    continue;
                }
                if (!is_array($value)) {
                    return new WP_Error(
                        'invalid_additional_option',
                        __('An auxiliary settings block in the backup is invalid.', 'gpt3-ai-content-generator'),
                        ['status' => 400]
                    );
                }
                $normalized_additional_options[$option_name] = $this->sanitize_recursive_value($value);
            }
        }

        $model_registry_state = null;
        if (isset($payload['model_registry'])) {
            if (!is_array($payload['model_registry'])) {
                return new WP_Error(
                    'invalid_model_registry',
                    __('Backup model registry data is invalid.', 'gpt3-ai-content-generator'),
                    ['status' => 400]
                );
            }
            if (isset($payload['model_registry']['snapshots']) && !is_array($payload['model_registry']['snapshots'])) {
                return new WP_Error(
                    'invalid_model_registry_snapshots',
                    __('Backup model registry snapshots are invalid.', 'gpt3-ai-content-generator'),
                    ['status' => 400]
                );
            }
            $model_registry_state = $payload['model_registry'];
            $registry_validation = AIPKit_Model_Registry::validate_import_state($model_registry_state);
            if (is_wp_error($registry_validation)) {
                return $registry_validation;
            }
        }

        if (($normalized_additional_options['aipkit_visitor_billing_enabled'] ?? '') === 'yes') {
            $sanitized_options['module_settings']['stats_viewer'] = true;
        }
        update_option('aipkit_options', $sanitized_options, 'no');
        foreach ($normalized_additional_options as $option_name => $value) {
            update_option($option_name, $value, 'no');
        }
        foreach ($normalized_model_lists as $option_name => $model_list_value) {
            update_option($option_name, $model_list_value, 'no');
        }

        if (is_array($model_registry_state)) {
            $registry_import_result = AIPKit_Model_Registry::import_state($model_registry_state);
            if (is_wp_error($registry_import_result)) {
                return $registry_import_result;
            }
        } elseif (!empty($normalized_model_lists)) {
            AIPKit_Model_Registry::migrate_legacy_options(true);
        }

        if (class_exists(AIPKit_Providers::class) && method_exists(AIPKit_Providers::class, 'clear_model_caches')) {
            AIPKit_Providers::clear_model_caches();
        }

        return true;
    }

    /**
     * Sanitizes imported options to safe, known structures.
     */
    private function sanitize_imported_ai_settings(array $imported_options): array
    {
        $existing_options = get_option('aipkit_options', []);
        $existing_options = is_array($existing_options) ? $existing_options : [];

        $sanitized = $existing_options;
        $provider_defaults = AIPKit_Providers::get_provider_defaults_all();
        $allowed_top_level_providers = AIPKit_Providers::get_main_provider_allowlist();

        $provider_value = isset($imported_options['provider']) ? sanitize_text_field((string) $imported_options['provider']) : 'OpenAI';
        $fallback_provider = $allowed_top_level_providers[0] ?? 'OpenAI';
        $sanitized['provider'] = in_array($provider_value, $allowed_top_level_providers, true)
            ? $provider_value
            : $fallback_provider;

        $imported_providers = isset($imported_options['providers']) && is_array($imported_options['providers'])
            ? $imported_options['providers']
            : [];
        $sanitized['providers'] = [];

        foreach ($provider_defaults as $provider_name => $defaults) {
            $incoming_provider_data = isset($imported_providers[$provider_name]) && is_array($imported_providers[$provider_name])
                ? $imported_providers[$provider_name]
                : [];
            $provider_data = [];

            foreach ($defaults as $provider_key => $default_value) {
                $raw_value = array_key_exists($provider_key, $incoming_provider_data)
                    ? $incoming_provider_data[$provider_key]
                    : $default_value;
                $provider_data[$provider_key] = $this->sanitize_provider_value_by_key($provider_key, $raw_value, $default_value);
            }

            if (
                $provider_name === 'OpenRouter'
                && function_exists('WPAICG\\Core\\Providers\\OpenRouter\\Methods\\sanitize_routing_settings_logic')
            ) {
                $provider_data = array_merge(
                    $provider_data,
                    \WPAICG\Core\Providers\OpenRouter\Methods\sanitize_routing_settings_logic($provider_data)
                );
            }

            $sanitized['providers'][$provider_name] = $provider_data;
        }

        $imported_params = isset($imported_options['ai_parameters']) && is_array($imported_options['ai_parameters'])
            ? $imported_options['ai_parameters']
            : [];
        $sanitized['ai_parameters'] = $this->normalize_ai_parameters($imported_params);

        $imported_api_keys = isset($imported_options['api_keys']) && is_array($imported_options['api_keys'])
            ? $imported_options['api_keys']
            : [];
        $sanitized['api_keys'] = [
            'public_api_key' => sanitize_text_field((string) ($imported_api_keys['public_api_key'] ?? '')),
            'public_api_enabled' => array_key_exists('public_api_enabled', $imported_api_keys)
                ? ((string) $imported_api_keys['public_api_enabled'] === '1' ? '1' : '0')
                : (trim((string) ($imported_api_keys['public_api_key'] ?? '')) !== '' ? '1' : '0'),
        ];

        $imported_security = isset($imported_options['security']) && is_array($imported_options['security'])
            ? $imported_options['security']
            : [];
        if (class_exists(AIPKit_Global_Security_Settings::class)) {
            $sanitized['security'] = AIPKit_Global_Security_Settings::sanitize_settings_input($imported_security);
        }

        $imported_semantic = isset($imported_options['semantic_search']) && is_array($imported_options['semantic_search'])
            ? $imported_options['semantic_search']
            : [];
        $imported_semantic_vector_provider = sanitize_key((string) ($imported_semantic['vector_provider'] ?? 'pinecone'));
        if (!in_array($imported_semantic_vector_provider, ['local', 'pinecone', 'qdrant', 'chroma'], true)) {
            $imported_semantic_vector_provider = 'pinecone';
        }
        $sanitized['semantic_search'] = [
            'vector_provider' => $imported_semantic_vector_provider,
            'target_id' => sanitize_text_field((string) ($imported_semantic['target_id'] ?? '')),
            'embedding_provider' => sanitize_key((string) ($imported_semantic['embedding_provider'] ?? 'openai')),
            'embedding_model' => sanitize_text_field((string) ($imported_semantic['embedding_model'] ?? '')),
            'num_results' => max(1, min(50, absint($imported_semantic['num_results'] ?? 5))),
            'no_results_text' => sanitize_text_field((string) ($imported_semantic['no_results_text'] ?? __('No results found.', 'gpt3-ai-content-generator'))),
        ];

        $imported_enhancer = isset($imported_options['enhancer_settings']) && is_array($imported_options['enhancer_settings'])
            ? $imported_options['enhancer_settings']
            : [];
        $sanitized['enhancer_settings'] = [
            'editor_integration' => ((string) ($imported_enhancer['editor_integration'] ?? '1') === '1') ? '1' : '0',
            'show_list_button' => ((string) ($imported_enhancer['show_list_button'] ?? '1') === '1') ? '1' : '0',
        ];

        $module_defaults = class_exists(aipkit_dashboard::class)
            ? aipkit_dashboard::$default_module_settings
            : [];
        $imported_modules = isset($imported_options['module_settings']) && is_array($imported_options['module_settings'])
            ? $imported_options['module_settings']
            : [];
        if (!empty($module_defaults) && array_key_exists('module_settings', $imported_options)) {
            $sanitized['module_settings'] = [];
            foreach ($module_defaults as $module_key => $default_enabled) {
                $sanitized['module_settings'][$module_key] = array_key_exists($module_key, $imported_modules)
                    ? (bool) $imported_modules[$module_key]
                    : (bool) $default_enabled;
            }
        }

        $imported_webhooks = isset($imported_options['event_webhooks']) && is_array($imported_options['event_webhooks'])
            ? $imported_options['event_webhooks']
            : [];
        if (class_exists(AIPKit_Event_Webhooks_Settings::class) && array_key_exists('event_webhooks', $imported_options)) {
            $webhooks_for_sanitization = $imported_webhooks;
            $event_field_key_map = AIPKit_Event_Webhooks_Settings::get_event_field_key_map();
            $event_name_to_field_key = array_flip($event_field_key_map);
            foreach ((array) ($webhooks_for_sanitization['endpoints'] ?? []) as $endpoint_index => $endpoint) {
                if (!is_array($endpoint) || !isset($endpoint['events']) || !is_array($endpoint['events'])) {
                    continue;
                }
                $event_flags = [];
                foreach ($endpoint['events'] as $event_key => $event_value) {
                    if (is_int($event_key) && is_string($event_value) && isset($event_name_to_field_key[$event_value])) {
                        $event_flags[$event_name_to_field_key[$event_value]] = '1';
                    } elseif (is_string($event_key) && (string) $event_value === '1') {
                        $event_flags[$event_key] = '1';
                    }
                }
                $webhooks_for_sanitization['endpoints'][$endpoint_index]['events'] = $event_flags;
            }

            $sanitized_webhooks = AIPKit_Event_Webhooks_Settings::sanitize_settings_input($webhooks_for_sanitization);
            $sanitized_webhooks['signing_secret'] = sanitize_text_field((string) ($imported_webhooks['signing_secret'] ?? ''));
            if ($sanitized_webhooks['enabled'] === '1' && $sanitized_webhooks['signing_secret'] === '') {
                $sanitized_webhooks['signing_secret'] = AIPKit_Event_Webhooks_Settings::generate_signing_secret();
            }
            $sanitized['event_webhooks'] = $sanitized_webhooks;
        }

        return $sanitized;
    }

    /**
     * Sanitizes provider-specific values based on field type.
     * @param mixed $raw_value
     * @param mixed $default_value
     * @return mixed
     */
    private function sanitize_provider_value_by_key(string $provider_key, $raw_value, $default_value)
    {
        if ($provider_key === 'api_mode') {
            return AIPKit_Providers::normalize_openai_api_mode($raw_value);
        }

        if ($provider_key === 'store_conversation') {
            return ((string) $raw_value === '1') ? '1' : '0';
        }

        if (
            function_exists('WPAICG\\Core\\Providers\\OpenRouter\\Methods\\sanitize_routing_settings_logic')
            && in_array($provider_key, ['allow_fallbacks', 'require_parameters', 'data_collection', 'zdr', 'fallback_model_1', 'fallback_model_2', 'fallback_model_3'], true)
        ) {
            $routing_settings = \WPAICG\Core\Providers\OpenRouter\Methods\sanitize_routing_settings_logic([
                $provider_key => $raw_value,
            ]);
            return $routing_settings[$provider_key];
        }

        if ($provider_key === 'expiration_policy') {
            $expiration = absint($raw_value);
            if ($expiration < 1) {
                $expiration = absint($default_value);
            }
            return max(1, min(365, $expiration));
        }

        if (in_array($provider_key, ['base_url', 'endpoint', 'url'], true)) {
            return esc_url_raw((string) $raw_value);
        }

        return sanitize_text_field((string) $raw_value);
    }

    /**
     * Sanitizes AI parameter values.
     */
    private function normalize_ai_parameters(array $ai_parameters): array
    {
        $defaults = AIPKIT_AI_Settings::$default_ai_params;
        $normalized = [];

        foreach ($defaults as $key => $default_value) {
            $raw_value = $ai_parameters[$key] ?? $default_value;
            switch ($key) {
                case 'temperature':
                case 'frequency_penalty':
                case 'presence_penalty':
                    $normalized[$key] = max(0.0, min(2.0, floatval($raw_value)));
                    break;
                case 'top_p':
                    $normalized[$key] = max(0.0, min(1.0, floatval($raw_value)));
                    break;
                default:
                    $normalized[$key] = sanitize_text_field((string) $raw_value);
                    break;
            }
        }

        return $normalized;
    }

    /**
     * Recursively sanitizes exported/imported array values.
     * @param mixed $value
     * @return mixed
     */
    private function sanitize_recursive_value($value)
    {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                $clean_key = is_string($key) ? sanitize_text_field($key) : $key;
                $sanitized[$clean_key] = $this->sanitize_recursive_value($item);
            }
            return $sanitized;
        }

        if (is_string($value)) {
            return sanitize_textarea_field($value);
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        return sanitize_text_field((string) $value);
    }
}
