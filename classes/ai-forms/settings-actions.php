<?php


namespace WPAICG\AIForms\Admin;

use WPAICG\Dashboard\Ajax\BaseDashboardAjaxHandler;
use WPAICG\Chat\Storage\BotSettingsManager; // For default constants
use WP_Error;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

require_once dirname(__DIR__) . '/usage/quotas.php';

/**
 * Handles AJAX requests for saving AI Forms module-specific settings,
 * including token management.
 */
class AIPKit_AI_Form_Settings_Ajax_Handler extends BaseDashboardAjaxHandler
{
    use \WPAICG\Core\TokenManager\Limits\AIPKit_Token_Limit_Settings_Trait;

    public const SETTINGS_OPTION_NAME = 'aipkit_ai_forms_settings';

    /**
     * Get the default settings structure for the AI Forms module.
     * @return array
     */
    public static function get_default_settings(): array
    {
        $default_limit_message = BotSettingsManager::get_default_token_limit_message();
        $default_limit_mode = BotSettingsManager::DEFAULT_TOKEN_LIMIT_MODE;
        $default_reset_period = BotSettingsManager::DEFAULT_TOKEN_RESET_PERIOD;
        $default_action_settings = BotSettingsManager::get_default_token_limit_action_settings();

        return [
            'token_management' => [
                'token_limit_mode' => $default_limit_mode,
                'token_guest_limit' => null, // null for unlimited
                'token_user_limit' => null,  // null for unlimited
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
            'custom_theme' => [
                'custom_css' => '',
            ],
            'frontend_display' => [
                'allowed_providers' => '',
                'allowed_models' => '',
            ]
        ];
    }

    /**
     * Retrieves the saved AI Forms settings, merged with defaults.
     * @return array
     */
    public static function get_settings(): array
    {
        $defaults = self::get_default_settings();
        $saved = get_option(self::SETTINGS_OPTION_NAME, []);

        if (!isset($saved['token_management']) || !is_array($saved['token_management'])) {
            $saved['token_management'] = $defaults['token_management'];
        } else {
            $saved['token_management'] = array_merge($defaults['token_management'], $saved['token_management']);
        }
        $saved['token_management'] = array_intersect_key($saved['token_management'], $defaults['token_management']);

        // Decode role limits JSON if it's a string
        if (isset($saved['token_management']['token_role_limits']) && is_string($saved['token_management']['token_role_limits'])) {
            $decoded_roles = json_decode($saved['token_management']['token_role_limits'], true);
            $saved['token_management']['token_role_limits'] = is_array($decoded_roles) ? $decoded_roles : [];
        } elseif (!isset($saved['token_management']['token_role_limits']) || !is_array($saved['token_management']['token_role_limits'])) {
            $saved['token_management']['token_role_limits'] = [];
        }
        $saved['token_management'] = self::normalize_token_management_settings($saved['token_management']);

        if (!isset($saved['custom_theme']) || !is_array($saved['custom_theme'])) {
            $saved['custom_theme'] = $defaults['custom_theme'];
        } else {
            $saved['custom_theme'] = array_merge($defaults['custom_theme'], $saved['custom_theme']);
        }
        $saved['custom_theme'] = array_intersect_key($saved['custom_theme'], $defaults['custom_theme']);

        if (!isset($saved['frontend_display']) || !is_array($saved['frontend_display'])) {
            $saved['frontend_display'] = $defaults['frontend_display'];
        } else {
            $saved['frontend_display'] = array_merge($defaults['frontend_display'], $saved['frontend_display']);
        }
        $saved['frontend_display'] = array_intersect_key($saved['frontend_display'], $defaults['frontend_display']);

        return $saved;
    }

    /**
     * AJAX handler to save AI Forms settings.
     */
    public function ajax_save_ai_forms_settings()
    {
        $permission_check = $this->check_module_access_permissions('ai-forms', 'aipkit_ai_forms_settings_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reason: Nonce is verified in check_module_access_permissions() above.
        $post_data = wp_unslash($_POST);
        $current_settings = self::get_settings();
        $new_settings = $current_settings; // Start with current settings as a base
        $defaults = self::get_default_settings();

        // --- Handle Token Management Settings ---
        $token_defaults = $defaults['token_management'];
        $new_token_settings = $new_settings['token_management'] ?? $token_defaults;
        // Use 'aiforms_' prefixed names from the form partial
        if (isset($post_data['aiforms_token_limit_mode']) && in_array($post_data['aiforms_token_limit_mode'], ['general', 'role_based'])) {
            $new_token_settings['token_limit_mode'] = $post_data['aiforms_token_limit_mode'];
        }
        if (isset($post_data['aiforms_token_guest_limit'])) {
            $guest_limit_raw = trim($post_data['aiforms_token_guest_limit']);
            if ($guest_limit_raw === '0') {
                $new_token_settings['token_guest_limit'] = 0;
            } elseif (ctype_digit($guest_limit_raw) && $guest_limit_raw > 0) {
                $new_token_settings['token_guest_limit'] = absint($guest_limit_raw);
            } else {
                $new_token_settings['token_guest_limit'] = null;
            }
        }
        if (isset($post_data['aiforms_token_user_limit'])) {
            $user_limit_raw = trim($post_data['aiforms_token_user_limit']);
            if ($user_limit_raw === '0') {
                $new_token_settings['token_user_limit'] = 0;
            } elseif (ctype_digit($user_limit_raw) && $user_limit_raw > 0) {
                $new_token_settings['token_user_limit'] = absint($user_limit_raw);
            } else {
                $new_token_settings['token_user_limit'] = null;
            }
        }
        if (isset($post_data['aiforms_token_role_limits']) && is_array($post_data['aiforms_token_role_limits'])) {
            $editable_roles = get_editable_roles();
            $sanitized_role_limits = [];
            foreach ($editable_roles as $role_slug => $role_info) {
                if (isset($post_data['aiforms_token_role_limits'][$role_slug])) {
                    $raw_limit = trim($post_data['aiforms_token_role_limits'][$role_slug]);
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
        if (isset($post_data['aiforms_token_reset_period']) && in_array($post_data['aiforms_token_reset_period'], ['never', 'daily', 'weekly', 'monthly'])) {
            $new_token_settings['token_reset_period'] = $post_data['aiforms_token_reset_period'];
        }
        if (isset($post_data['aiforms_token_limit_message'])) {
            $new_token_settings['token_limit_message'] = sanitize_text_field($post_data['aiforms_token_limit_message']);
        }
        if (isset($post_data['aiforms_token_limit_primary_action_type'])) {
            $new_token_settings['token_limit_primary_action_type'] = sanitize_key((string) $post_data['aiforms_token_limit_primary_action_type']);
        }
        if (isset($post_data['aiforms_token_limit_primary_action_label'])) {
            $new_token_settings['token_limit_primary_action_label'] = sanitize_text_field((string) $post_data['aiforms_token_limit_primary_action_label']);
        }
        if (isset($post_data['aiforms_token_limit_primary_action_url'])) {
            $new_token_settings['token_limit_primary_action_url'] = esc_url_raw(trim((string) $post_data['aiforms_token_limit_primary_action_url']));
        }
        if (isset($post_data['aiforms_token_limit_secondary_action_type'])) {
            $new_token_settings['token_limit_secondary_action_type'] = sanitize_key((string) $post_data['aiforms_token_limit_secondary_action_type']);
        }
        if (isset($post_data['aiforms_token_limit_secondary_action_label'])) {
            $new_token_settings['token_limit_secondary_action_label'] = sanitize_text_field((string) $post_data['aiforms_token_limit_secondary_action_label']);
        }
        if (isset($post_data['aiforms_token_limit_secondary_action_url'])) {
            $new_token_settings['token_limit_secondary_action_url'] = esc_url_raw(trim((string) $post_data['aiforms_token_limit_secondary_action_url']));
        }
        $new_token_settings = self::normalize_token_management_settings($new_token_settings);
        $new_settings['token_management'] = $new_token_settings;

        $theme_defaults = $defaults['custom_theme'];
        $new_theme_settings = $new_settings['custom_theme'] ?? $theme_defaults;
        if (isset($post_data['custom_css'])) {
            $new_theme_settings['custom_css'] = wp_strip_all_tags(wp_unslash($post_data['custom_css']));
        }
        $new_settings['custom_theme'] = $new_theme_settings;

        $frontend_defaults = $defaults['frontend_display'];
        $new_frontend_settings = $new_settings['frontend_display'] ?? $frontend_defaults;
        if (isset($post_data['frontend_providers'])) {
            $providers_raw = sanitize_textarea_field(wp_unslash($post_data['frontend_providers']));
            $providers_arr = array_map('trim', explode(',', $providers_raw));
            $new_frontend_settings['allowed_providers'] = implode(', ', array_filter($providers_arr));
        }
        if (isset($post_data['frontend_models'])) {
            $models_raw = sanitize_textarea_field(wp_unslash($post_data['frontend_models']));
            $models_arr = array_map('trim', explode(',', $models_raw));
            $new_frontend_settings['allowed_models'] = implode(', ', array_filter($models_arr));
        }
        $new_settings['frontend_display'] = $new_frontend_settings;

        if (wp_json_encode($current_settings) !== wp_json_encode($new_settings)) {
            $updated = update_option(self::SETTINGS_OPTION_NAME, $new_settings, 'no');
            if ($updated) {
                wp_send_json_success(['message' => __('AI Forms settings saved.', 'gpt3-ai-content-generator')]);
            } else {
                $this->send_wp_error(new WP_Error('save_failed_ai_forms', __('Failed to save AI Forms settings.', 'gpt3-ai-content-generator'), ['status' => 500]));
            }
        } else {
            wp_send_json_success(['message' => __('No changes detected.', 'gpt3-ai-content-generator')]);
        }
    }
}
