<?php

namespace WPAICG\Images;

use WPAICG\Dashboard\Ajax\BaseDashboardAjaxHandler;
use WPAICG\Chat\Storage\BotSettingsManager;
use WPAICG\Core\Models\AIPKit_Model_Catalog;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/usage/quotas.php';

/**
 * Handles AJAX requests for saving Image Generator settings.
 */
class AIPKit_Image_Settings_Ajax_Handler extends BaseDashboardAjaxHandler
{
    use \WPAICG\Core\TokenManager\Limits\AIPKit_Token_Limit_Settings_Trait;

    public const SETTINGS_OPTION_NAME = 'aipkit_image_generator_settings';

    /**
     * Normalize checkbox-like values to booleans.
     */
    private static function normalize_checkbox_value($value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /**
     * Get default UI text values for the frontend image generator.
     */
    public static function get_default_ui_text_settings(): array
    {
        return [
            'generate_label' => __('Generate', 'gpt3-ai-content-generator'),
            'edit_label' => __('Edit Image', 'gpt3-ai-content-generator'),
            'generate_placeholder' => __('Describe your image…', 'gpt3-ai-content-generator'),
            'edit_placeholder' => __('Describe the edit…', 'gpt3-ai-content-generator'),
            'history_title' => __('Your Images', 'gpt3-ai-content-generator'),
        ];
    }

    /**
     * Get the default settings structure.
     */
    public static function get_default_settings(): array
    {
        $default_limit_message = BotSettingsManager::get_default_token_limit_message();
        $default_limit_mode = BotSettingsManager::DEFAULT_TOKEN_LIMIT_MODE;
        $default_reset_period = BotSettingsManager::DEFAULT_TOKEN_RESET_PERIOD;
        $default_action_settings = BotSettingsManager::get_default_token_limit_action_settings();

        return [
            'common' => [
                'custom_css' => '',
            ],
            'token_management' => [
                'token_limit_mode' => $default_limit_mode,
                'token_guest_limit' => null,
                'token_user_limit' => null,
                'token_role_limits' => [],
                'token_reset_period' => $default_reset_period,
                'token_limit_message' => $default_limit_message,
                'token_limit_primary_action_type' => $default_action_settings['primary_type'],
                'token_limit_primary_action_label' => $default_action_settings['primary_label'],
                'token_limit_primary_action_url' => $default_action_settings['primary_url'],
                'token_limit_secondary_action_type' => $default_action_settings['secondary_type'],
                'token_limit_secondary_action_label' => $default_action_settings['secondary_label'],
                'token_limit_secondary_action_url' => $default_action_settings['secondary_url'],
            ],
            'frontend_display' => [
                'allowed_providers' => '',
                'allowed_models' => '',
            ],
            'ui_text' => self::get_default_ui_text_settings(),
            'replicate' => [
                'disable_safety_checker' => true, // Default to disabled safety check to avoid false positives
            ]
        ];
    }

    /**
     * Retrieves the saved image generator settings, merging with defaults.
     */
    public static function get_settings(): array
    {
        $defaults = self::get_default_settings();
        $saved = get_option(self::SETTINGS_OPTION_NAME, []);

        if (!isset($saved['common']) || !is_array($saved['common'])) {
            $saved['common'] = [];
        }
        $saved['common'] = array_merge($defaults['common'], $saved['common']);
        $saved['common'] = array_intersect_key($saved['common'], $defaults['common']);

        if (!isset($saved['token_management']) || !is_array($saved['token_management'])) {
            $saved['token_management'] = $defaults['token_management'];
        } else {
            $saved['token_management'] = array_merge($defaults['token_management'], $saved['token_management']);
        }
        $saved['token_management'] = array_intersect_key($saved['token_management'], $defaults['token_management']);
        if (isset($saved['token_management']['token_role_limits']) && is_string($saved['token_management']['token_role_limits'])) {
            $decoded_roles = json_decode($saved['token_management']['token_role_limits'], true);
            $saved['token_management']['token_role_limits'] = is_array($decoded_roles) ? $decoded_roles : [];
        } elseif (!isset($saved['token_management']['token_role_limits']) || !is_array($saved['token_management']['token_role_limits'])) {
            $saved['token_management']['token_role_limits'] = [];
        }
        $saved['token_management'] = self::normalize_token_management_settings($saved['token_management']);
        if (!isset($saved['frontend_display']) || !is_array($saved['frontend_display'])) {
            $saved['frontend_display'] = $defaults['frontend_display'];
        } else {
            $saved['frontend_display'] = array_merge($defaults['frontend_display'], $saved['frontend_display']);
        }
        $saved['frontend_display'] = array_intersect_key($saved['frontend_display'], $defaults['frontend_display']);

        if (!isset($saved['ui_text']) || !is_array($saved['ui_text'])) {
            $saved['ui_text'] = $defaults['ui_text'];
        } else {
            $saved['ui_text'] = array_merge($defaults['ui_text'], $saved['ui_text']);
        }
        $saved['ui_text'] = array_intersect_key($saved['ui_text'], $defaults['ui_text']);
        $legacy_placeholder_values = [
            'generate_placeholder' => 'Describe the image you want to generate...',
            'edit_placeholder' => 'Describe how you want to edit the uploaded image...',
        ];
        foreach ($legacy_placeholder_values as $placeholder_key => $legacy_value) {
            if ((string) ($saved['ui_text'][$placeholder_key] ?? '') === $legacy_value) {
                $saved['ui_text'][$placeholder_key] = $defaults['ui_text'][$placeholder_key];
            }
        }
        // Handle Replicate settings
        if (!isset($saved['replicate']) || !is_array($saved['replicate'])) {
            $saved['replicate'] = $defaults['replicate'];
        } else {
            $saved['replicate'] = array_merge($defaults['replicate'], $saved['replicate']);
        }
        $saved['replicate'] = array_intersect_key($saved['replicate'], $defaults['replicate']);
        
        return $saved;
    }

    /**
     * Save Replicate-specific image settings outside the Image Generator module UI.
     */
    public static function save_replicate_settings(array $raw_settings): bool
    {
        $defaults = self::get_default_settings();
        $current_settings = self::get_settings();
        $current_replicate = isset($current_settings['replicate']) && is_array($current_settings['replicate'])
            ? $current_settings['replicate']
            : $defaults['replicate'];

        $next_replicate = array_merge($defaults['replicate'], $current_replicate);
        $changed = false;

        if (array_key_exists('disable_safety_checker', $raw_settings)) {
            $next_value = self::normalize_checkbox_value($raw_settings['disable_safety_checker']);
            if (!array_key_exists('disable_safety_checker', $current_replicate) || (bool) $current_replicate['disable_safety_checker'] !== $next_value) {
                $next_replicate['disable_safety_checker'] = $next_value;
                $changed = true;
            }
        }

        if (!$changed) {
            return false;
        }

        $current_settings['replicate'] = $next_replicate;
        update_option(self::SETTINGS_OPTION_NAME, $current_settings, 'no');

        return true;
    }

    /**
     * AJAX handler to save Image Generator settings.
     */
    public function ajax_save_image_settings()
    {
        $permission_check = $this->check_module_access_permissions('image_generator', 'aipkit_image_generator_settings_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked by check_module_access_permissions() above.
        $post_data = wp_unslash($_POST);
        $current_settings = self::get_settings();
        $new_settings = $current_settings; // Start with current settings as a base
        $defaults = self::get_default_settings();

        if (isset($post_data['custom_css'])) {
            $new_settings['common']['custom_css'] = wp_strip_all_tags($post_data['custom_css']);
        }

        $token_defaults = $defaults['token_management'];
        $new_token_settings = $new_settings['token_management'] ?? $token_defaults;
        if (isset($post_data['image_token_limit_mode']) && in_array($post_data['image_token_limit_mode'], ['general', 'role_based'])) {
            $new_token_settings['token_limit_mode'] = $post_data['image_token_limit_mode'];
        }
        if (isset($post_data['image_token_guest_limit'])) {
            $guest_limit_raw = trim($post_data['image_token_guest_limit']);
            if ($guest_limit_raw === '0') {
                $new_token_settings['token_guest_limit'] = 0;
            } elseif (ctype_digit($guest_limit_raw) && $guest_limit_raw > 0) {
                $new_token_settings['token_guest_limit'] = absint($guest_limit_raw);
            } else {
                $new_token_settings['token_guest_limit'] = null;
            }
        }
        if (isset($post_data['image_token_user_limit'])) {
            $user_limit_raw = trim($post_data['image_token_user_limit']);
            if ($user_limit_raw === '0') {
                $new_token_settings['token_user_limit'] = 0;
            } elseif (ctype_digit($user_limit_raw) && $user_limit_raw > 0) {
                $new_token_settings['token_user_limit'] = absint($user_limit_raw);
            } else {
                $new_token_settings['token_user_limit'] = null;
            }
        }
        if (isset($post_data['image_token_role_limits']) && is_array($post_data['image_token_role_limits'])) {
            $editable_roles = get_editable_roles();
            $sanitized_role_limits = [];
            foreach ($editable_roles as $role_slug => $role_info) {
                if (isset($post_data['image_token_role_limits'][$role_slug])) {
                    $raw_limit = trim($post_data['image_token_role_limits'][$role_slug]);
                    if ($raw_limit === '0') {
                        $sanitized_role_limits[$role_slug] = 0;
                    } elseif (ctype_digit($raw_limit) && $raw_limit > 0) {
                        $sanitized_role_limits[$role_slug] = absint($raw_limit);
                    } else {
                        $sanitized_role_limits[$role_slug] = null;
                    }
                }
            }
            $new_token_settings['token_role_limits'] = wp_json_encode($sanitized_role_limits);
        } else {
            $new_token_settings['token_role_limits'] = '[]';
        }
        if (isset($post_data['image_token_reset_period']) && in_array($post_data['image_token_reset_period'], ['never', 'daily', 'weekly', 'monthly'])) {
            $new_token_settings['token_reset_period'] = $post_data['image_token_reset_period'];
        }
        if (isset($post_data['image_token_limit_message'])) {
            $new_token_settings['token_limit_message'] = sanitize_text_field($post_data['image_token_limit_message']);
        }
        if (isset($post_data['image_token_limit_primary_action_type'])) {
            $new_token_settings['token_limit_primary_action_type'] = sanitize_key((string) $post_data['image_token_limit_primary_action_type']);
        }
        if (isset($post_data['image_token_limit_primary_action_label'])) {
            $new_token_settings['token_limit_primary_action_label'] = sanitize_text_field((string) $post_data['image_token_limit_primary_action_label']);
        }
        if (isset($post_data['image_token_limit_primary_action_url'])) {
            $new_token_settings['token_limit_primary_action_url'] = esc_url_raw(trim((string) $post_data['image_token_limit_primary_action_url']));
        }
        if (isset($post_data['image_token_limit_secondary_action_type'])) {
            $new_token_settings['token_limit_secondary_action_type'] = sanitize_key((string) $post_data['image_token_limit_secondary_action_type']);
        }
        if (isset($post_data['image_token_limit_secondary_action_label'])) {
            $new_token_settings['token_limit_secondary_action_label'] = sanitize_text_field((string) $post_data['image_token_limit_secondary_action_label']);
        }
        if (isset($post_data['image_token_limit_secondary_action_url'])) {
            $new_token_settings['token_limit_secondary_action_url'] = esc_url_raw(trim((string) $post_data['image_token_limit_secondary_action_url']));
        }
        $new_token_settings = self::normalize_token_management_settings($new_token_settings);
        $new_settings['token_management'] = $new_token_settings;

        $frontend_defaults = $defaults['frontend_display'];
        $new_frontend_settings = $new_settings['frontend_display'] ?? $frontend_defaults;
        // Providers now inferred from selected models. If no models selected = allow all (store empty string for both fields)
        if (isset($post_data['frontend_models'])) {
            $models_raw = sanitize_textarea_field(wp_unslash($post_data['frontend_models']));
            $models_arr = array_filter(array_map('trim', explode(',', $models_raw)));
            if (empty($models_arr)) {
                // All providers & models allowed
                $new_frontend_settings['allowed_models'] = '';
                $new_frontend_settings['allowed_providers'] = '';
            } else {
                // Build lookup tables from known provider model lists for accurate detection.
                $openai_ids = class_exists('\\WPAICG\\AIPKit_Providers')
                    ? \WPAICG\AIPKit_Providers::get_openai_image_model_ids()
                    : AIPKit_Model_Catalog::get_seed_ids('OpenAIImage');
                // Get Google image and video models from synced lists
                $google_ids = [];
                if (class_exists('\\WPAICG\\AIPKit_Providers')) {
                    $google_image_models = \WPAICG\AIPKit_Providers::get_google_image_models();
                    $google_video_models = \WPAICG\AIPKit_Providers::get_google_video_models();
                    foreach ([$google_image_models, $google_video_models] as $list) {
                        if (is_array($list) && !empty($list)) {
                            foreach ($list as $mdl) {
                                if (is_array($mdl) && isset($mdl['id'])) { $google_ids[] = strtolower($mdl['id']); }
                                elseif (is_string($mdl)) { $google_ids[] = strtolower($mdl); }
                            }
                        }
                    }
                }
                $azure_ids = [];
                $openrouter_ids = [];
                $xai_ids = [];
                if (class_exists('\\WPAICG\\AIPKit_Providers')) {
                    $azure_models_list = \WPAICG\AIPKit_Providers::get_azure_image_models();
                    if (is_array($azure_models_list)) {
                        foreach ($azure_models_list as $mdl) {
                            if (is_array($mdl) && isset($mdl['id'])) { $azure_ids[] = strtolower($mdl['id']); }
                            elseif (is_string($mdl)) { $azure_ids[] = strtolower($mdl); }
                        }
                    }
                    $openrouter_models_list = \WPAICG\AIPKit_Providers::get_openrouter_image_models();
                    if (is_array($openrouter_models_list)) {
                        foreach ($openrouter_models_list as $mdl) {
                            if (is_array($mdl) && isset($mdl['id'])) {
                                $openrouter_ids[] = strtolower((string) $mdl['id']);
                            } elseif (is_string($mdl)) {
                                $openrouter_ids[] = strtolower($mdl);
                            }
                        }
                    }
                    $xai_models_list = \WPAICG\AIPKit_Providers::get_xai_image_models();
                    if (is_array($xai_models_list)) {
                        foreach ($xai_models_list as $mdl) {
                            if (is_array($mdl) && isset($mdl['id'])) {
                                $xai_ids[] = strtolower((string) $mdl['id']);
                            } elseif (is_string($mdl)) {
                                $xai_ids[] = strtolower($mdl);
                            }
                        }
                    }
                    $replicate_models_list = \WPAICG\AIPKit_Providers::get_replicate_models();
                } else {
                    $replicate_models_list = [];
                }
                $replicate_ids = [];
                if (is_array($replicate_models_list)) {
                    foreach ($replicate_models_list as $mdl) {
                        if (is_array($mdl) && isset($mdl['id'])) { $replicate_ids[] = strtolower($mdl['id']); }
                        elseif (is_string($mdl)) { $replicate_ids[] = strtolower($mdl); }
                    }
                }
                $openai_lu = array_flip(array_map('strtolower',$openai_ids));
                $google_lu = array_flip(array_map('strtolower',$google_ids));
                $openrouter_lu = array_flip(array_map('strtolower', $openrouter_ids));
                $xai_lu = array_flip(array_map('strtolower', $xai_ids));
                $azure_lu = array_flip($azure_ids);
                $replicate_lu = array_flip($replicate_ids);
                $providers_detected = [];
                foreach ($models_arr as $m) {
                    $ml = strtolower($m);
                    if (strpos($ml, 'aipuffer/image_generate/') === 0 || strpos($ml, 'aipuffer/image_edit/') === 0) { $providers_detected['AIPufferCloud'] = true; continue; }
                    if (isset($openai_lu[$ml])) { $providers_detected['OpenAI'] = true; continue; }
                    if (isset($google_lu[$ml])) { $providers_detected['Google'] = true; continue; }
                    if (isset($openrouter_lu[$ml])) { $providers_detected['OpenRouter'] = true; continue; }
                    if (isset($xai_lu[$ml])) { $providers_detected['xAI'] = true; continue; }
                    if (isset($azure_lu[$ml])) { $providers_detected['Azure'] = true; continue; }
                    if (isset($replicate_lu[$ml])) { $providers_detected['Replicate'] = true; continue; }
                    if (strpos($ml, '/') !== false) {
                        if (strpos($ml, ':') !== false) {
                            $providers_detected['Replicate'] = true;
                        } else {
                            $providers_detected['OpenRouter'] = true;
                        }
                    }
                }
                $new_frontend_settings['allowed_models'] = implode(', ', $models_arr);
                $new_frontend_settings['allowed_providers'] = implode(', ', array_keys($providers_detected));
            }
        }
        $new_settings['frontend_display'] = $new_frontend_settings;

        $ui_text_defaults = $defaults['ui_text'];
        $new_ui_text_settings = $new_settings['ui_text'] ?? $ui_text_defaults;
        $ui_text_field_map = [
            'ui_text_generate_label' => 'generate_label',
            'ui_text_edit_label' => 'edit_label',
            'ui_text_generate_placeholder' => 'generate_placeholder',
            'ui_text_edit_placeholder' => 'edit_placeholder',
            'ui_text_history_title' => 'history_title',
        ];
        foreach ($ui_text_field_map as $post_key => $setting_key) {
            if (!isset($post_data[$post_key])) {
                continue;
            }
            $sanitized_value = sanitize_text_field($post_data[$post_key]);
            $sanitized_value = trim($sanitized_value);
            $new_ui_text_settings[$setting_key] = $sanitized_value !== ''
                ? $sanitized_value
                : ($ui_text_defaults[$setting_key] ?? '');
        }
        $new_settings['ui_text'] = $new_ui_text_settings;

        // Handle Replicate settings only when this field is part of the request.
        if (array_key_exists('replicate_disable_safety_checker', $post_data)) {
            $replicate_defaults = $defaults['replicate'];
            $new_replicate_settings = $new_settings['replicate'] ?? $replicate_defaults;
            $new_replicate_settings['disable_safety_checker'] = self::normalize_checkbox_value($post_data['replicate_disable_safety_checker']);
            $new_settings['replicate'] = $new_replicate_settings;
        }

        $current_json = wp_json_encode($current_settings);
        $new_json     = wp_json_encode($new_settings);
        if ($current_json !== $new_json) {
            $updated = update_option(self::SETTINGS_OPTION_NAME, $new_settings, 'no');
            if ($updated) {
                wp_send_json_success(['message' => __('Image Generator settings saved.', 'gpt3-ai-content-generator')]);
            } else {
                // Re-fetch and compare again; if value already matches desired state, treat as success
                $after = get_option(self::SETTINGS_OPTION_NAME, []);
                if (wp_json_encode($after) === $new_json) {
                    wp_send_json_success(['message' => __('Image Generator settings saved.', 'gpt3-ai-content-generator')]);
                } else {
                    $this->send_wp_error(new WP_Error('save_failed', __('Failed to save settings.', 'gpt3-ai-content-generator'), ['status' => 500]));
                }
            }
        } else {
            wp_send_json_success(['message' => __('No changes detected.', 'gpt3-ai-content-generator')]);
        }
    }

}
