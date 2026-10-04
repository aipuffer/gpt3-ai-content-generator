<?php
/** Token usage, quota enforcement and reset lifecycle. */
namespace WPAICG\Core\TokenManager;

if (!defined('ABSPATH')) {
    exit;
}

require_once WPAICG_PLUGIN_DIR . 'classes/usage/pricing.php';
require_once WPAICG_PLUGIN_DIR . 'classes/usage/ledger.php';
require_once WPAICG_PLUGIN_DIR . 'classes/usage/quotas.php';

namespace WPAICG\Core\TokenManager\Init;

use WPAICG\Chat\Storage\BotStorage;
use WPAICG\Images\AIPKit_Image_Settings_Ajax_Handler;
use WPAICG\Core\TokenManager\Constants\GuestTableConstants;
use WPAICG\Core\TokenManager\AIPKit_Token_Manager;
use WPAICG\Core\TokenManager\Pricing\AIPKit_Price_Resolver;
use WPAICG\Core\TokenManager\Pricing\AIPKit_Usage_Normalizer;
use WPAICG\Core\TokenManager\Pricing\AIPKit_Charge_Calculator;
use WPAICG\Core\TokenManager\Ledger\AIPKit_Ledger_Repository;
use WPAICG\Core\TokenManager\Ledger\AIPKit_Balance_Service;
use WPAICG\Core\TokenManager\Limits\AIPKit_Quota_Service;

/**
 * Logic for the AIPKit_Token_Manager constructor.
 * Initializes properties and ensures dependencies are loaded.
 *
 * @param AIPKit_Token_Manager $managerInstance The instance of AIPKit_Token_Manager.
 */
function ConstructorLogic(AIPKit_Token_Manager $managerInstance): void {
    global $wpdb;
    $managerInstance->set_guest_table_name($wpdb->prefix . GuestTableConstants::GUEST_TABLE_NAME_SUFFIX);

    // Initialize BotStorage dependency
    if (!class_exists(BotStorage::class) && !defined('AIPKIT_TESTING_ENV')) {
        $bot_storage_path = WPAICG_PLUGIN_DIR . 'classes/chatbot/bots.php';
        if (file_exists($bot_storage_path)) {
            require_once $bot_storage_path;
        } else {
            $managerInstance->set_bot_storage(null);
        }
    }
    if (class_exists(BotStorage::class)) {
        $managerInstance->set_bot_storage(new BotStorage());
    } else {
        $managerInstance->set_bot_storage(null);
    }


    // Ensure AIPKit_Image_Settings_Ajax_Handler is loaded as it's used by PerformTokenResetLogic
    // This is more of a check; actual loading should be handled by the main plugin dependency loader.
    if (!class_exists(AIPKit_Image_Settings_Ajax_Handler::class) && !defined('AIPKIT_TESTING_ENV')) {
         $image_settings_handler_path = WPAICG_PLUGIN_DIR . 'classes/image-generator/settings-actions.php';
         if (file_exists($image_settings_handler_path)) {
             require_once $image_settings_handler_path;
         }
    }

    $price_resolver = new AIPKit_Price_Resolver();
    $usage_normalizer = new AIPKit_Usage_Normalizer();
    $charge_calculator = new AIPKit_Charge_Calculator();
    $ledger_repository = new AIPKit_Ledger_Repository();
    $balance_service = new AIPKit_Balance_Service();
    $quota_service = new AIPKit_Quota_Service();

    $managerInstance->set_price_resolver($price_resolver);
    $managerInstance->set_usage_normalizer($usage_normalizer);
    $managerInstance->set_charge_calculator($charge_calculator);
    $managerInstance->set_ledger_repository($ledger_repository);
    $managerInstance->set_balance_service($balance_service);
    $managerInstance->set_quota_service($quota_service);
}

namespace WPAICG\Core\TokenManager\Cron;

/**
 * Logic for scheduling the daily token reset event.
 *
 * @param string $cronHook The cron hook name (e.g., from CronHookConstant::CRON_HOOK).
 */
function ScheduleTokenResetEventLogic(string $cronHook): void {
    if (!wp_next_scheduled($cronHook)) {
        wp_schedule_event(time(), 'daily', $cronHook);
    }

}

namespace WPAICG\Core\TokenManager\Cron;

/**
 * Logic for unscheduling the token reset event.
 *
 * @param string $cronHook The cron hook name (e.g., from CronHookConstant::CRON_HOOK).
 */
function UnscheduleTokenResetEventLogic(string $cronHook): void {
    $timestamp = wp_next_scheduled($cronHook);
    if ($timestamp) {
        wp_unschedule_event($timestamp, $cronHook);
    }

}

namespace WPAICG\Core\TokenManager\Reset;

use WPAICG\Core\TokenManager\AIPKit_Token_Manager;
use WPAICG\Core\TokenManager\Constants\MetaKeysConstants;
use WPAICG\Core\TokenManager\Constants\GuestTableConstants;
use WPAICG\Images\AIPKit_Image_Settings_Ajax_Handler; // For Image Generator settings

/**
 * Logic for performing the token reset for chatbots AND the image generator module.
 * This function is called by the perform_token_reset method in AIPKit_Token_Manager.
 *
 * @param AIPKit_Token_Manager $managerInstance The instance of AIPKit_Token_Manager.
 */
function PerformTokenResetLogic(AIPKit_Token_Manager $managerInstance): void
{
    global $wpdb;
    $current_time = time();
    $current_day_of_week = wp_date('w', $current_time); // 0 (for Sunday) through 6 (for Saturday)
    $current_day_of_month = wp_date('j', $current_time);

    // --- 1. Chatbot Token Reset ---
    $bot_storage = $managerInstance->get_bot_storage();
    if ($bot_storage) {
        $all_chatbots = $bot_storage->get_chatbots(); // Assumes get_chatbots() returns array of WP_Post

        if (!empty($all_chatbots)) {
            foreach ($all_chatbots as $bot_post) {
                $bot_id = $bot_post->ID;
                $settings = $bot_storage->get_chatbot_settings($bot_id);
                $reset_period = $settings['token_reset_period'] ?? 'never';

                if ($reset_period === 'never') {
                    continue;
                }

                $reset_needed_for_cron = false;
                if ($reset_period === 'daily') {
                    $reset_needed_for_cron = true;
                } elseif ($reset_period === 'weekly' && $current_day_of_week == get_option('start_of_week', 1)) {
                    $reset_needed_for_cron = true;
                } elseif ($reset_period === 'monthly' && $current_day_of_month == 1) {
                    $reset_needed_for_cron = true;
                }


                if ($reset_needed_for_cron) {
                    $meta_key_usage = MetaKeysConstants::CHAT_USAGE_META_KEY_PREFIX . $bot_id;
                    $meta_key_reset = MetaKeysConstants::CHAT_RESET_META_KEY_PREFIX . $bot_id;
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Reason: Bulk deletion for a cron job. More efficient than individual API calls. Caching is not applicable here.
                    $wpdb->delete($wpdb->usermeta, ['meta_key' => $meta_key_usage], ['%s']);
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Reason: Bulk deletion for a cron job. More efficient than individual API calls. Caching is not applicable here.
                    $wpdb->delete($wpdb->usermeta, ['meta_key' => $meta_key_reset], ['%s']);


                    $guest_table_name = $managerInstance->get_guest_table_name();
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Bulk deletion on a custom table for a cron job. Caching is not applicable.
                    $wpdb->delete($guest_table_name, ['bot_id' => $bot_id], ['%d']);
                }
            }
        }
    }

    // --- 2. Image Generator Token Reset ---
    if (class_exists(AIPKit_Image_Settings_Ajax_Handler::class)) {
        $img_settings_all = AIPKit_Image_Settings_Ajax_Handler::get_settings();
        $img_token_settings = $img_settings_all['token_management'] ?? [];
        $img_reset_period = $img_token_settings['token_reset_period'] ?? 'never';

        if ($img_reset_period !== 'never') {
            $img_reset_needed_for_cron = false;
            if ($img_reset_period === 'daily') {
                $img_reset_needed_for_cron = true;
            } elseif ($img_reset_period === 'weekly' && $current_day_of_week == get_option('start_of_week', 1)) {
                $img_reset_needed_for_cron = true;
            } elseif ($img_reset_period === 'monthly' && $current_day_of_month == 1) {
                $img_reset_needed_for_cron = true;
            }

            if ($img_reset_needed_for_cron) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Reason: Bulk deletion for a cron job. More efficient than individual API calls. Caching is not applicable here.
                $wpdb->delete($wpdb->usermeta, ['meta_key' => MetaKeysConstants::IMG_USAGE_META_KEY], ['%s']);
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Reason: Bulk deletion for a cron job. More efficient than individual API calls. Caching is not applicable here.
                $wpdb->delete($wpdb->usermeta, ['meta_key' => MetaKeysConstants::IMG_RESET_META_KEY], ['%s']);

                $guest_table_name = $managerInstance->get_guest_table_name();
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Bulk deletion on a custom table for a cron job. Caching is not applicable.
                $wpdb->delete($guest_table_name, ['bot_id' => GuestTableConstants::IMG_GEN_GUEST_CONTEXT_ID], ['%d']);
            }
        }
    }
}

namespace WPAICG\Core\TokenManager\Reset;

/**
* Logic for checking if a token reset is due based on the last reset timestamp and period.
*
* @param int    $last_reset_timestamp Unix timestamp of the last reset.
* @param string $period 'daily', 'weekly', 'monthly', or 'never'.
* @return bool True if a reset is due, false otherwise.
*/
function IsResetDueLogic(int $last_reset_timestamp, string $period): bool
{
    if ($period === 'never' || $last_reset_timestamp <= 0) {
        return false;
    }

    $current_time = time();
    $site_timezone = wp_timezone();

    // Create a DateTime object from the last reset timestamp, in the site's timezone
    $last_reset_dt = new \DateTimeImmutable('@' . $last_reset_timestamp);
    $last_reset_dt = $last_reset_dt->setTimezone($site_timezone);

    // Calculate the start of the next reset period
    $next_reset_dt = null;

    switch ($period) {
        case 'daily':
            // The next day at midnight
            $next_reset_dt = $last_reset_dt->setTime(0, 0, 0)->modify('+1 day');
            break;
        case 'weekly':
            $start_of_week = (int) get_option('start_of_week', 1); // 0=Sun, 1=Mon...
            $day_names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $start_of_week_name = $day_names[$start_of_week];

            // Find the start of the week that contains the last reset date
            $start_of_last_period = clone $last_reset_dt;
            if ((int)$start_of_last_period->format('w') !== $start_of_week) {
                $start_of_last_period = $start_of_last_period->modify('last ' . $start_of_week_name);
            }
            // The next reset is one week after the start of the last reset period
            $next_reset_dt = $start_of_last_period->setTime(0, 0, 0)->modify('+1 week');
            break;
        case 'monthly':
            // The first day of the next month at midnight
            $next_reset_dt = $last_reset_dt->setTime(0, 0, 0)->modify('first day of next month');
            break;
        default:
            return false;
    }

    if ($next_reset_dt === null) {
        return false;
    }

    // Get the UTC timestamp of the next reset time and compare with current UTC time
    $next_reset_timestamp = $next_reset_dt->getTimestamp();

    return $current_time >= $next_reset_timestamp;
}

namespace WPAICG\Core\TokenManager\Check;

use WPAICG\Core\TokenManager\AIPKit_Token_Manager;
use WPAICG\Core\TokenManager\Constants\MetaKeysConstants;
use WPAICG\Core\TokenManager\Constants\GuestTableConstants;
use WPAICG\Images\AIPKit_Image_Settings_Ajax_Handler;
use WPAICG\Chat\Storage\BotSettingsManager; // For default constants
use WP_Error;
use function WPAICG\Core\TokenManager\Helpers\GetGuestQuotaIdentifiersLogic;

/**
 * Logic for checking token usage against limits for a given context (chat bot or module).
 * This function is called by the check_and_reset_tokens method in AIPKit_Token_Manager.
 *
 * @param AIPKit_Token_Manager $managerInstance The instance of AIPKit_Token_Manager.
 * @param int|null    $user_id               User ID, or null for guests.
 * @param string|null $session_id            Session ID for guests.
 * @param int|null    $context_id_or_bot_id  Bot ID for 'chat', or IMG_GEN_GUEST_CONTEXT_ID for 'image_generator'. Can be null for other modules.
 * @param string      $module_context        'chat', 'image_generator', or other module slug.
 * @return bool|WP_Error True if allowed, WP_Error if limit exceeded or other error.
 */
function CheckAndResetTokensLogic(
    AIPKit_Token_Manager $managerInstance,
    ?int $user_id,
    ?string $session_id,
    ?int $context_id_or_bot_id,
    string $module_context = 'chat',
    array $usage_context = []
) {
    global $wpdb;

    // If no balance, or a guest, proceed with the original periodic usage tracking logic.

    $fallback_units = isset($usage_context['fallback_units']) && is_numeric($usage_context['fallback_units'])
        ? max(0, (int) $usage_context['fallback_units'])
        : 0;
    $charge_estimate = $managerInstance->estimate_usage_charge($fallback_units, $module_context, $usage_context);
    $resolved_rule = is_array($charge_estimate['resolved_rule'] ?? null) ? $charge_estimate['resolved_rule'] : null;
    $use_pricing_estimate = !empty($resolved_rule);
    $required_units = max(0, (int) ($charge_estimate['required_units'] ?? $fallback_units));
    $balance_service = $managerInstance->get_balance_service();
    $available_balance = 0;

    if ($user_id) {
        $available_balance = ($balance_service && method_exists($balance_service, 'get_current_balance'))
            ? (int) $balance_service->get_current_balance($user_id)
            : max(0, (int) get_user_meta($user_id, MetaKeysConstants::TOKEN_BALANCE_META_KEY, true));

        if (!$use_pricing_estimate && $available_balance > 0) {
            return true;
        }
    }

    // Validation
    if ($module_context === 'chat' && empty($context_id_or_bot_id)) {
        return new WP_Error('token_check_no_bot_id_logic', __('Bot ID missing for chat token check.', 'gpt3-ai-content-generator'));
    }
    if ($module_context === 'image_generator' && $context_id_or_bot_id === null) { // image_generator uses 0 for guests
        return new WP_Error('token_check_no_img_context_logic', __('Image Generator context ID missing for token check.', 'gpt3-ai-content-generator'));
    }
    if ($module_context === 'ai_forms' && $context_id_or_bot_id === null && !$user_id) { // ai_forms uses 1 for guests, null for users
        // This is a valid state for logged-in users, so only error if guest AND null
        return new WP_Error('token_check_no_aiforms_context_logic', __('AI Forms context ID missing for guest token check.', 'gpt3-ai-content-generator'));
    }

    $is_guest = !$user_id;
    $guest_identifiers = $is_guest ? GetGuestQuotaIdentifiersLogic($session_id) : [];
    if ($is_guest && empty($guest_identifiers)) {
        return new WP_Error('token_check_no_identifier_logic', __('User/Session ID missing for token check.', 'gpt3-ai-content-generator'));
    }

    $settings = [];
    $usage_key = '';
    $reset_key = '';
    $guest_context_table_id = is_numeric($context_id_or_bot_id) ? $context_id_or_bot_id : null;
    $guest_usage_rows = [];

    // Fetch settings based on module context
    if ($module_context === 'chat') {
        $bot_storage = $managerInstance->get_bot_storage();
        if (!$bot_storage) {
            return new WP_Error('init_error_chat_storage_logic', __('Token manager (bot storage) not initialized.', 'gpt3-ai-content-generator'));
        }
        if ($guest_context_table_id === null) { // Ensure bot_id is numeric for chat
            return new WP_Error('internal_error_chat_context_id_logic', __('Chat context requires a valid Bot ID for token check.', 'gpt3-ai-content-generator'));
        }
        $settings = $bot_storage->get_chatbot_settings($guest_context_table_id);
        $usage_key = MetaKeysConstants::CHAT_USAGE_META_KEY_PREFIX . $guest_context_table_id;
        $reset_key = MetaKeysConstants::CHAT_RESET_META_KEY_PREFIX . $guest_context_table_id;
    } elseif ($module_context === 'image_generator') {
        if (!class_exists(AIPKit_Image_Settings_Ajax_Handler::class)) {
            return new WP_Error('init_error_img_settings_logic', __('Token manager (image settings) not initialized.', 'gpt3-ai-content-generator'));
        }
        $img_settings_all = AIPKit_Image_Settings_Ajax_Handler::get_settings();
        $settings = $img_settings_all['token_management'] ?? [];
        $usage_key = MetaKeysConstants::IMG_USAGE_META_KEY;
        $reset_key = MetaKeysConstants::IMG_RESET_META_KEY;
        // guest_context_table_id for image generator is a constant (e.g., 0)
        $guest_context_table_id = GuestTableConstants::IMG_GEN_GUEST_CONTEXT_ID;
    } elseif ($module_context === 'ai_forms') {
        if (!class_exists(\WPAICG\AIForms\Admin\AIPKit_AI_Form_Settings_Ajax_Handler::class)) {
            return new WP_Error('init_error_aiforms_settings_logic', __('Token manager (AI Forms settings) not initialized.', 'gpt3-ai-content-generator'));
        }
        $aiforms_settings_all = \WPAICG\AIForms\Admin\AIPKit_AI_Form_Settings_Ajax_Handler::get_settings();
        $settings = $aiforms_settings_all['token_management'] ?? [];
        $usage_key = MetaKeysConstants::AIFORMS_USAGE_META_KEY;
        $reset_key = MetaKeysConstants::AIFORMS_RESET_META_KEY;
        $guest_context_table_id = GuestTableConstants::AI_FORMS_GUEST_CONTEXT_ID;
    } else {
        if ($context_id_or_bot_id === null) {
            return true;
        } // No specific limits for this generic module context if no ID
        return new WP_Error('invalid_module_context_for_tokens_logic', __('Invalid module context or ID for token check.', 'gpt3-ai-content-generator'));
    }

    if ($guest_context_table_id === null && $is_guest) {
        return true; // Or error, based on policy. For now, allow if this unlikely scenario happens.
    }

    // Determine limit and reset period
    $limit = null;
    $reset_period = $settings['token_reset_period'] ?? 'never';
    $default_limit_message = class_exists(BotSettingsManager::class)
        ? BotSettingsManager::get_default_token_limit_message()
        : __('You have reached your quota for this period.', 'gpt3-ai-content-generator');
    $limit_message_template = $settings['token_limit_message'] ?? '';
    $limit_message = !empty($limit_message_template) ? $limit_message_template : $default_limit_message;

    $current_usage = 0;
    $last_reset_time = 0;
    $guest_table_name = $wpdb->prefix . GuestTableConstants::GUEST_TABLE_NAME_SUFFIX;

    if ($is_guest) {
        $limit = $settings['token_guest_limit'] ?? null;
        if ($limit === 0 || (is_string($limit) && $limit === '0')) { // Check for string '0' too
            return new WP_Error('token_limit_exceeded_guest_logic', __('Access disabled for guests.', 'gpt3-ai-content-generator'));
        }
        if ($limit === null || $limit === '') {
            return true;
        } // Unlimited for this context

        if ($guest_context_table_id !== null) {
            foreach ($guest_identifiers as $guest_identifier) {
                $cache_key = "aipkit_guest_usage_{$guest_identifier}_{$guest_context_table_id}";
                $guest_row = wp_cache_get($cache_key, 'aipkit_token_usage');
                if (false === $guest_row) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table query; unavoidable.
                    $guest_row = $wpdb->get_row($wpdb->prepare("SELECT tokens_used, last_reset_timestamp FROM {$guest_table_name} WHERE session_id = %s AND bot_id = %d",
                        $guest_identifier,
                        $guest_context_table_id
                    ), ARRAY_A);
                    wp_cache_set($cache_key, $guest_row, 'aipkit_token_usage', 300); // Cache for 5 minutes
                }

                $guest_usage_rows[$guest_identifier] = [
                    'current_usage' => $guest_row ? (int) $guest_row['tokens_used'] : 0,
                    'last_reset_time' => $guest_row ? (int) $guest_row['last_reset_timestamp'] : 0,
                ];

                $current_usage = max($current_usage, $guest_usage_rows[$guest_identifier]['current_usage']);
                $last_reset_time = max($last_reset_time, $guest_usage_rows[$guest_identifier]['last_reset_time']);
            }
        } else {
            return true; // Should have been caught earlier
        }
    } else { // Logged-in User
        $limit_mode = $settings['token_limit_mode'] ?? 'general';
        if ($limit_mode === 'general') {
            $limit = $settings['token_user_limit'] ?? null;
        } else { // Role-based
            $user_data = get_userdata($user_id);
            $user_roles = $user_data ? (array) $user_data->roles : [];
            $role_limits_raw = $settings['token_role_limits'] ?? [];
            $role_limits = is_string($role_limits_raw) ? json_decode($role_limits_raw, true) : (is_array($role_limits_raw) ? $role_limits_raw : []);
            if (!is_array($role_limits)) {
                $role_limits = [];
            }

            if (empty($user_roles) || empty($role_limits)) {
                $limit = null;
            } else {
                $highest_limit = -1;
                foreach ($user_roles as $role) {
                    if (isset($role_limits[$role])) {
                        $role_limit_value_raw = $role_limits[$role];
                        if ($role_limit_value_raw === null || $role_limit_value_raw === '') {
                            $highest_limit = null; // Explicitly unlimited for this role, overrides others
                            break;
                        }
                        if ($role_limit_value_raw === '0' || $role_limit_value_raw === 0) {
                            $highest_limit = max($highest_limit, 0); // Found a "disabled" (0) limit
                        } elseif (ctype_digit((string)$role_limit_value_raw)) {
                            $highest_limit = max($highest_limit, (int)$role_limit_value_raw);
                        }
                    }
                }
                $limit = ($highest_limit === -1) ? null : $highest_limit;
            }
        }
        if ($limit === 0 || (is_string($limit) && $limit === '0')) {
            return new WP_Error('token_limit_exceeded_user_logic', __('Access disabled for your account/role.', 'gpt3-ai-content-generator'));
        }
        if ($limit === null || $limit === '') {
            return true;
        } // Unlimited

        $current_usage = (int) get_user_meta($user_id, $usage_key, true);
        $last_reset_time = (int) get_user_meta($user_id, $reset_key, true);
    }

    $guest_reset_due = false;
    if ($is_guest && !empty($guest_usage_rows) && $reset_period !== 'never') {
        foreach ($guest_usage_rows as $guest_usage_row) {
            if (\WPAICG\Core\TokenManager\Reset\IsResetDueLogic((int) $guest_usage_row['last_reset_time'], $reset_period)) {
                $guest_reset_due = true;
                break;
            }
        }
    }

    // Fail-safe reset if due (not relying on cron exclusively for active users)
    if ($reset_period !== 'never' && ($limit !== null && $limit !== '')) { // Only reset if there's a limit
        if (($is_guest && $guest_reset_due) || (!$is_guest && \WPAICG\Core\TokenManager\Reset\IsResetDueLogic($last_reset_time, $reset_period))) {

            $current_usage = 0;
            $last_reset_time_new = time(); // Use a different var name for new reset time
            if ($is_guest && $guest_context_table_id !== null) {
                foreach ($guest_usage_rows as $guest_identifier => $guest_usage_row) {
                    if (!\WPAICG\Core\TokenManager\Reset\IsResetDueLogic((int) $guest_usage_row['last_reset_time'], $reset_period)) {
                        continue;
                    }

                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching is not applicable for a write operation (REPLACE). Cache is invalidated after.
                    $wpdb->replace($guest_table_name, ['session_id' => $guest_identifier, 'bot_id' => $guest_context_table_id, 'tokens_used' => 0, 'last_reset_timestamp' => $last_reset_time_new, 'last_updated_at' => current_time('mysql', 1)], ['%s', '%d', '%d', '%d', '%s']);
                    $guest_usage_rows[$guest_identifier]['current_usage'] = 0;
                    $guest_usage_rows[$guest_identifier]['last_reset_time'] = $last_reset_time_new;

                    // Invalidate cache after write
                    $cache_key = "aipkit_guest_usage_{$guest_identifier}_{$guest_context_table_id}";
                    wp_cache_delete($cache_key, 'aipkit_token_usage');
                }
            } elseif (!$is_guest) {
                update_user_meta($user_id, $usage_key, 0);
                update_user_meta($user_id, $reset_key, $last_reset_time_new);
            }
        }
    }

    if ($is_guest && !empty($guest_usage_rows)) {
        $current_usage = 0;
        $last_reset_time = 0;
        foreach ($guest_usage_rows as $guest_usage_row) {
            $current_usage = max($current_usage, (int) $guest_usage_row['current_usage']);
            $last_reset_time = max($last_reset_time, (int) $guest_usage_row['last_reset_time']);
        }
    }

    $required_quota_units = 0;
    if ($use_pricing_estimate) {
        $required_quota_units = $required_units;
        if (!$is_guest && $available_balance > 0) {
            $required_quota_units = max(0, $required_units - $available_balance);
        }

        if (!$is_guest && $required_quota_units <= 0) {
            return true;
        }
    }

    // Final Limit Check
    if ($use_pricing_estimate && ($limit !== null && $limit !== '')) {
        $quota_service = $managerInstance->get_quota_service();
        $can_consume = $quota_service && method_exists($quota_service, 'can_consume')
            ? $quota_service->can_consume((int) $limit, $current_usage, $required_quota_units)
            : (($current_usage + $required_quota_units) <= (int) $limit);

        if (!$can_consume) {
            return build_token_limit_exceeded_error_logic($settings, $limit_message, $module_context, $guest_context_table_id);
        }
    } elseif (($limit !== null && $limit !== '') && $current_usage >= (int)$limit) {
        return build_token_limit_exceeded_error_logic($settings, $limit_message, $module_context, $guest_context_table_id);
    }

    return true; // Allowed
}

/**
 * Builds the structured quota error payload returned to frontend clients.
 *
 * @param array<string, mixed> $settings
 */
function build_token_limit_exceeded_error_logic(
    array $settings,
    string $limit_message,
    string $module_context,
    ?int $context_id_or_bot_id
): WP_Error {
    $error_data = ['status' => 429];

    $quota_notice = build_quota_notice_logic($settings, $module_context, $context_id_or_bot_id, $limit_message);
    if (!empty($quota_notice)) {
        $error_data['quota_notice'] = $quota_notice;
    }

    return new WP_Error('token_limit_exceeded_final_logic', $limit_message, $error_data);
}

/**
 * Creates the quota recovery card payload for quota errors.
 *
 * @param array<string, mixed> $settings
 * @return array<string, mixed>
 */
function build_quota_notice_logic(array $settings, string $module_context, ?int $context_id, string $limit_message): array
{
    if (!in_array($module_context, ['chat', 'image_generator', 'ai_forms'], true)) {
        return [];
    }

    if ($module_context === 'chat' && ($context_id === null || $context_id <= 0)) {
        return [];
    }

    $actions = [];
    $action_slots = [
        ['slot' => 'primary', 'variant' => 'primary'],
        ['slot' => 'secondary', 'variant' => 'secondary'],
    ];

    foreach ($action_slots as $action_slot) {
        $slot = $action_slot['slot'];
        $type = sanitize_key((string) ($settings["token_limit_{$slot}_action_type"] ?? 'none'));
        if ($type === '' || $type === 'none') {
            continue;
        }

        $label = trim((string) ($settings["token_limit_{$slot}_action_label"] ?? ''));
        if ($label === '' && class_exists(BotSettingsManager::class)) {
            $label = BotSettingsManager::get_token_limit_action_default_label($type);
        }

        $custom_url = trim((string) ($settings["token_limit_{$slot}_action_url"] ?? ''));
        $url = resolve_quota_notice_action_url_logic($type, $module_context, $context_id, $custom_url);
        if ($label === '' || $url === '') {
            continue;
        }

        $actions[] = [
            'label' => $label,
            'url' => $url,
            'variant' => $action_slot['variant'],
        ];
    }

    return [
        'type' => 'quota_notice',
        'message' => $limit_message,
        'actions' => $actions,
    ];
}

function resolve_quota_notice_action_url_logic(
    string $action_type,
    string $module_context,
    ?int $context_id,
    string $custom_url = ''
): string
{
    $dashboard_url = trim((string) get_option('aipkit_token_dashboard_page_url', ''));
    $buy_credits_url = trim((string) get_option('aipkit_token_shop_page_url', ''));

    if ($buy_credits_url === '' && function_exists('wc_get_page_id')) {
        $shop_page_id = wc_get_page_id('shop');
        if ($shop_page_id && $shop_page_id > 0) {
            $buy_credits_url = (string) get_permalink($shop_page_id);
        }
    }

    switch ($action_type) {
        case 'dashboard_usage':
            if ($dashboard_url === '') {
                return '';
            }
            $dashboard_context_id = resolve_quota_notice_dashboard_context_id_logic($module_context, $context_id);
            $query_args = [
                'aipkit_section' => 'usage',
                'aipkit_module' => $module_context,
            ];
            if ($dashboard_context_id !== null) {
                $query_args['aipkit_context_id'] = $dashboard_context_id;
            }
            return build_dashboard_quota_notice_url_logic(
                $dashboard_url,
                $query_args,
                'aipkit_customer_dashboard_usage'
            );

        case 'dashboard_credits':
            if ($dashboard_url === '') {
                return '';
            }
            return build_dashboard_quota_notice_url_logic(
                $dashboard_url,
                ['aipkit_section' => 'credits'],
                'aipkit_customer_dashboard_credits'
            );

        case 'dashboard_purchases':
            if ($dashboard_url === '') {
                return '';
            }
            return build_dashboard_quota_notice_url_logic(
                $dashboard_url,
                ['aipkit_section' => 'purchases'],
                'aipkit_purchase_history_details'
            );

        case 'buy_credits':
            return $buy_credits_url !== '' ? esc_url_raw($buy_credits_url) : '';

        case 'custom_url':
            return $custom_url !== '' ? esc_url_raw($custom_url) : '';

        case 'none':
        default:
            return '';
    }
}

function resolve_quota_notice_dashboard_context_id_logic(string $module_context, ?int $context_id): ?int
{
    if ($module_context === 'chat') {
        return ($context_id !== null && $context_id > 0) ? $context_id : null;
    }

    if ($module_context === 'image_generator') {
        return GuestTableConstants::IMG_GEN_GUEST_CONTEXT_ID;
    }

    if ($module_context === 'ai_forms') {
        return GuestTableConstants::AI_FORMS_GUEST_CONTEXT_ID;
    }

    return $context_id;
}

/**
 * @param array<string, string|int> $query_args
 */
function build_dashboard_quota_notice_url_logic(string $base_url, array $query_args, string $fragment = ''): string
{
    if ($base_url === '') {
        return '';
    }

    $url = add_query_arg($query_args, $base_url);
    if ($fragment !== '') {
        $url .= '#' . ltrim($fragment, '#');
    }

    return esc_url_raw($url);
}

namespace WPAICG\Core\TokenManager\Record;

use WPAICG\Core\TokenManager\AIPKit_Token_Manager;
use WPAICG\Core\TokenManager\Constants\MetaKeysConstants;
use WPAICG\Core\TokenManager\Constants\GuestTableConstants;
use WPAICG\Images\AIPKit_Image_Settings_Ajax_Handler;
use WPAICG\AIForms\Admin\AIPKit_AI_Form_Settings_Ajax_Handler;
use function WPAICG\Core\TokenManager\Helpers\GetGuestQuotaIdentifiersLogic;

/**
 * Logic for recording token usage for a given context (chat bot or module).
 * This function is called by the record_token_usage method in AIPKit_Token_Manager.
 *
 * @param AIPKit_Token_Manager $managerInstance The instance of AIPKit_Token_Manager.
 * @param int|null    $user_id               User ID, or null for guests.
 * @param string|null $session_id            Session ID for guests.
 * @param int|null    $context_id_or_bot_id  Bot ID for 'chat', IMG_GEN_GUEST_CONTEXT_ID for 'image_generator' guest table. Can be null for others.
 * @param int         $tokens_used           Number of tokens to record.
 * @param string      $module_context        'chat', 'image_generator', or other module slug.
 */
function RecordTokenUsageLogic(
    AIPKit_Token_Manager $managerInstance,
    ?int $user_id,
    ?string $session_id,
    ?int $context_id_or_bot_id,
    int $tokens_used,
    string $module_context = 'chat',
    array $usage_context = []
): void {
    global $wpdb;

    if ($tokens_used <= 0 && empty($usage_context['usage_data']['duration_seconds'])) {
        return;
    }

    $ledger_repository = $managerInstance->get_ledger_repository();
    $idempotency_key = sanitize_text_field((string) ($usage_context['idempotency_key'] ?? ''));
    if ($idempotency_key !== '' && $ledger_repository && $ledger_repository->find_by_idempotency_key($idempotency_key)) {
        return;
    }

    $charge_estimate = $managerInstance->estimate_usage_charge($tokens_used, $module_context, $usage_context);
    $resolved_rule = is_array($charge_estimate['resolved_rule'] ?? null) ? $charge_estimate['resolved_rule'] : null;
    $billed_units = max(0, (int) ($charge_estimate['billed_credits'] ?? $tokens_used));
    $normalized_usage = is_array($charge_estimate['normalized_usage'] ?? null) ? $charge_estimate['normalized_usage'] : [];
    $provider = sanitize_text_field((string) ($usage_context['provider'] ?? ''));
    $model = sanitize_text_field((string) ($usage_context['model'] ?? ''));
    $operation = sanitize_text_field((string) ($usage_context['operation'] ?? ''));
    $pricing_module = sanitize_key((string) ($charge_estimate['pricing_module'] ?? ($usage_context['pricing_module'] ?? $module_context)));
    if ($operation === '') {
        if ($module_context === 'chat') {
            $operation = 'chat';
        } elseif ($module_context === 'ai_forms') {
            $operation = 'form_submit';
        } elseif ($module_context === 'image_generator') {
            $operation = 'generate';
        } else {
            $operation = 'usage';
        }
    }

    $tokens_left_to_deduct = $billed_units;
    $deducted_from_balance = 0;
    $balance_before = 0;
    $balance_after = 0;
    $balance_service = $managerInstance->get_balance_service();

    if ($user_id) {
        if ($balance_service && method_exists($balance_service, 'deduct_available_balance')) {
            $balance_result = $balance_service->deduct_available_balance($user_id, $tokens_left_to_deduct);
            $balance_before = (int) ($balance_result['balance_before'] ?? 0);
            $deducted_from_balance = (int) ($balance_result['deducted'] ?? 0);
            $balance_after = (int) ($balance_result['balance_after'] ?? 0);
            $tokens_left_to_deduct = (int) ($balance_result['remaining'] ?? $tokens_left_to_deduct);
        } else {
            $token_balance_raw = get_user_meta($user_id, MetaKeysConstants::TOKEN_BALANCE_META_KEY, true);
            if (is_numeric($token_balance_raw) && (int)$token_balance_raw > 0) {
                $balance_before = (int) $token_balance_raw;
                $deducted_from_balance = min($balance_before, $tokens_left_to_deduct);
                $balance_after = $balance_before - $deducted_from_balance;
                update_user_meta($user_id, MetaKeysConstants::TOKEN_BALANCE_META_KEY, $balance_after);
                $tokens_left_to_deduct -= $deducted_from_balance;
            } else {
                $balance_before = is_numeric($token_balance_raw) ? (int) $token_balance_raw : 0;
                $balance_after = $balance_before;
            }
        }
    }

    // Validation
    if ($module_context === 'chat' && empty($context_id_or_bot_id)) {
        return;
    }
    if ($module_context === 'image_generator' && $context_id_or_bot_id === null) {
        return;
    }
    if ($module_context === 'ai_forms' && $context_id_or_bot_id === null && !$user_id) {
        return;
    }

    $is_guest = !$user_id;
    $guest_identifiers = $is_guest ? GetGuestQuotaIdentifiersLogic($session_id) : [];
    if ($is_guest && empty($guest_identifiers)) {
        return;
    }

    $settings = [];
    $usage_key = '';
    $reset_key = '';
    $guest_context_table_id = is_numeric($context_id_or_bot_id) ? $context_id_or_bot_id : null;

    // Fetch settings based on module context
    if ($module_context === 'chat') {
        $bot_storage = $managerInstance->get_bot_storage();
        if (!$bot_storage) {
            return;
        }
        if ($guest_context_table_id === null) {
            return;
        }
        $settings = $bot_storage->get_chatbot_settings($guest_context_table_id);
        $usage_key = MetaKeysConstants::CHAT_USAGE_META_KEY_PREFIX . $guest_context_table_id;
        $reset_key = MetaKeysConstants::CHAT_RESET_META_KEY_PREFIX . $guest_context_table_id;
    } elseif ($module_context === 'image_generator') {
        if (!class_exists(AIPKit_Image_Settings_Ajax_Handler::class)) {
            return;
        }
        $img_settings_all = AIPKit_Image_Settings_Ajax_Handler::get_settings();
        $settings = $img_settings_all['token_management'] ?? [];
        $usage_key = MetaKeysConstants::IMG_USAGE_META_KEY;
        $reset_key = MetaKeysConstants::IMG_RESET_META_KEY;
        $guest_context_table_id = GuestTableConstants::IMG_GEN_GUEST_CONTEXT_ID;
    } elseif ($module_context === 'ai_forms') {
        if (!class_exists(AIPKit_AI_Form_Settings_Ajax_Handler::class)) {
            return;
        }
        $aiforms_settings_all = AIPKit_AI_Form_Settings_Ajax_Handler::get_settings();
        $settings = $aiforms_settings_all['token_management'] ?? [];
        $usage_key = MetaKeysConstants::AIFORMS_USAGE_META_KEY;
        $reset_key = MetaKeysConstants::AIFORMS_RESET_META_KEY;
        $guest_context_table_id = GuestTableConstants::AI_FORMS_GUEST_CONTEXT_ID;
    } elseif ($module_context === 'wp_ai_client') {
        $settings = [];
        $guest_context_table_id = null;
    } else {
        return;
    }

    if ($guest_context_table_id === null && $is_guest && $module_context !== 'wp_ai_client') {
        return;
    }

    $reset_period = $settings['token_reset_period'] ?? 'never';
    $should_record = false;

    // Determine if tokens should be recorded based on limits
    if ($module_context === 'wp_ai_client') {
        $should_record = false;
    } elseif ($is_guest) {
        $limit = $settings['token_guest_limit'] ?? null;
        if ($limit === null || $limit === '' || (ctype_digit((string)$limit) && (int)$limit > 0)) {
            $should_record = true;
        } // Record if unlimited or limit > 0
    } else { // Logged-in User
        $limit_mode = $settings['token_limit_mode'] ?? 'general';
        $limit_value_source = ($limit_mode === 'general') ? ($settings['token_user_limit'] ?? null) : 'role_based';
        if ($limit_value_source === null || $limit_value_source === '' || $limit_value_source === 'role_based') {
            $should_record = true; // Record if unlimited or role-based (as roles might have limits)
        } elseif (ctype_digit((string)$limit_value_source) && (int)$limit_value_source > 0) {
            $should_record = true; // Record if general limit > 0
        }
    }

    if ($should_record && $tokens_left_to_deduct > 0) {
        $guest_table_name = $wpdb->prefix . GuestTableConstants::GUEST_TABLE_NAME_SUFFIX;
        if ($is_guest && $guest_context_table_id !== null) {
            foreach ($guest_identifiers as $guest_identifier) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reason: Direct query to a custom table. Cache is invalidated after each write.
                $guest_row = $wpdb->get_row($wpdb->prepare("SELECT tokens_used, last_reset_timestamp FROM {$guest_table_name} WHERE session_id = %s AND bot_id = %d", $guest_identifier, $guest_context_table_id), ARRAY_A);
                $current_usage = $guest_row ? (int) $guest_row['tokens_used'] : 0;
                $last_reset = $guest_row ? (int) $guest_row['last_reset_timestamp'] : 0;
                $new_usage = $current_usage + $tokens_left_to_deduct; // Use remaining tokens
                if ($last_reset === 0 && $reset_period !== 'never') {
                    $last_reset = time();
                } // Set initial reset time if not set

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->replace($guest_table_name, ['session_id' => $guest_identifier, 'bot_id' => $guest_context_table_id, 'tokens_used' => $new_usage, 'last_reset_timestamp' => $last_reset, 'last_updated_at' => current_time('mysql', 1)], ['%s', '%d', '%d', '%d', '%s']);
                wp_cache_delete("aipkit_guest_usage_{$guest_identifier}_{$guest_context_table_id}", 'aipkit_token_usage');
            }
        } elseif (!$is_guest) {
            $current_usage = (int) get_user_meta($user_id, $usage_key, true);
            $new_usage = $current_usage + $tokens_left_to_deduct; // Use remaining tokens
            update_user_meta($user_id, $usage_key, $new_usage);
            if ($reset_period !== 'never') {
                if (!get_user_meta($user_id, $reset_key, true)) {
                    update_user_meta($user_id, $reset_key, time());
                } // Set initial reset time
            }
        }
    }

    if ($ledger_repository && method_exists($ledger_repository, 'insert_entry')) {
        $context_type = $module_context === 'chat' ? 'chatbot' : 'module';
        $context_id = null;
        $reference_type = null;
        $reference_id = null;

        if ($module_context === 'chat' && is_numeric($context_id_or_bot_id)) {
            $context_id = absint($context_id_or_bot_id);
        } elseif ($module_context === 'image_generator') {
            $context_id = is_numeric($context_id_or_bot_id)
                ? absint($context_id_or_bot_id)
                : GuestTableConstants::IMG_GEN_GUEST_CONTEXT_ID;
        } elseif ($module_context === 'ai_forms') {
            $form_id = 0;
            if (!empty($usage_context['form_id']) && is_numeric($usage_context['form_id'])) {
                $form_id = absint($usage_context['form_id']);
            } elseif (
                ($usage_context['pricing_scope_type'] ?? '') === 'ai_form' &&
                !empty($usage_context['pricing_scope_id']) &&
                is_numeric($usage_context['pricing_scope_id'])
            ) {
                $form_id = absint($usage_context['pricing_scope_id']);
            }

            if ($form_id > 0) {
                $context_type = 'ai_form';
                $context_id = $form_id;
                $reference_type = 'ai_form';
                $reference_id = (string) $form_id;
            } else {
                $context_id = GuestTableConstants::AI_FORMS_GUEST_CONTEXT_ID;
            }
        }

        $ledger_repository->insert_entry([
            'user_id' => $user_id,
            'session_id' => $user_id ? null : $session_id,
            'module' => $module_context,
            'context_type' => $context_type,
            'context_id' => $context_id,
            'provider' => $provider !== '' ? $provider : null,
            'model' => $model !== '' ? $model : null,
            'operation' => $operation,
            'usage_input_units' => max(0, (int) ($normalized_usage['input_units'] ?? 0)),
            'usage_output_units' => max(0, (int) ($normalized_usage['output_units'] ?? 0)),
            'usage_total_units' => max(0, (int) ($normalized_usage['total_units'] ?? $tokens_used)),
            'credits_delta' => 0 - $deducted_from_balance,
            'entry_type' => 'usage',
            'reference_type' => $reference_type,
            'reference_id' => $reference_id,
            'idempotency_key' => $idempotency_key !== '' ? $idempotency_key : null,
            'meta' => [
                'legacy_total_units' => $tokens_used,
                'billed_credits' => $billed_units,
                'pricing_module' => $pricing_module !== '' ? $pricing_module : $module_context,
                'billing_method' => sanitize_key((string) ($charge_estimate['billing_method'] ?? 'legacy_fallback')),
                'raw_charge' => isset($charge_estimate['raw_charge']) ? (float) $charge_estimate['raw_charge'] : (float) $billed_units,
                'used_legacy_fallback' => !empty($charge_estimate['used_legacy_fallback']),
                'resolved_rule_id' => is_array($resolved_rule) && isset($resolved_rule['id']) ? absint($resolved_rule['id']) : null,
                'balance_before' => $balance_before,
                'balance_after' => $balance_after,
                'balance_units' => $deducted_from_balance,
                'quota_units' => $tokens_left_to_deduct,
                'quota_recorded' => $should_record && $tokens_left_to_deduct > 0,
                'tool_units' => max(0, (int) ($normalized_usage['tool_units'] ?? 0)),
                'tool_usage' => isset($normalized_usage['tool_usage']) && is_array($normalized_usage['tool_usage'])
                    ? $normalized_usage['tool_usage']
                    : [],
                'raw_usage_data' => $normalized_usage['raw_usage_data'] ?? [],
            ],
        ]);
    }
}

namespace WPAICG\Core\TokenManager\Helpers;

/**
 * Builds the guest quota identifiers used for server-side rate limiting.
 *
 * The browser-provided session ID remains the default identifier so existing
 * guests keep isolated quota buckets. A server-derived network fingerprint is
 * only used as a fallback when no client session ID is available.
 *
 * @param string|null $session_id Guest session ID supplied by the client.
 * @return array<int, string>
 */
function GetGuestQuotaIdentifiersLogic(?string $session_id): array {
    $normalized_session_id = is_string($session_id) ? sanitize_text_field($session_id) : '';
    $normalized_client_ip = isset($_SERVER['REMOTE_ADDR'])
        ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
        : '';
    $normalized_user_agent = isset($_SERVER['HTTP_USER_AGENT'])
        ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
        : '';

    $identifiers = [];

    if ($normalized_session_id !== '') {
        $identifiers[] = $normalized_session_id;
    }

    $network_fingerprint = null;
    if ($normalized_session_id === '' && $normalized_client_ip !== '') {
        $fingerprint_source = $normalized_client_ip . '|' . strtolower(substr($normalized_user_agent, 0, 190));
        $network_fingerprint = 'net-' . substr(hash_hmac('sha256', $fingerprint_source, wp_salt('auth')), 0, 48);
        $identifiers[] = $network_fingerprint;
    }

    $identifiers = array_values(array_unique(array_filter(array_map(static function ($identifier): string {
        if (!is_string($identifier)) {
            return '';
        }

        return substr(sanitize_text_field($identifier), 0, 64);
    }, $identifiers))));

    /**
     * Filters the guest quota identifiers used for unauthenticated rate limiting.
     *
     * @param array<int, string> $identifiers
     * @param array<string, string|null> $context
     */
    $identifiers = apply_filters('aipkit_guest_quota_identifiers', $identifiers, [
        'session_id' => $normalized_session_id !== '' ? $normalized_session_id : null,
        'client_ip' => $normalized_client_ip !== '' ? $normalized_client_ip : null,
        'user_agent' => $normalized_user_agent !== '' ? $normalized_user_agent : null,
        'network_fingerprint' => $network_fingerprint,
    ]);

    if (!is_array($identifiers)) {
        return [];
    }

    return array_values(array_unique(array_filter(array_map(static function ($identifier): string {
        if (!is_string($identifier)) {
            return '';
        }

        return substr(sanitize_text_field($identifier), 0, 64);
    }, $identifiers))));
}

namespace WPAICG\Core\TokenManager\Constants;

class CronHookConstant {
    const CRON_HOOK = 'aipkit_token_reset';
}

namespace WPAICG\Core\TokenManager\Constants;

class MetaKeysConstants
{
    // User's persistent token balance (from purchases)
    public const TOKEN_BALANCE_META_KEY = '_aipkit_token_balance';

    // Chat specific prefixes
    public const CHAT_USAGE_META_KEY_PREFIX = '_aipkit_token_usage_';
    public const CHAT_RESET_META_KEY_PREFIX = '_aipkit_last_token_reset_';

    // Image Generator specific prefixes (user meta)
    public const IMG_USAGE_META_KEY = '_aipkit_img_tokens_used';
    public const IMG_RESET_META_KEY = '_aipkit_img_tokens_reset';

    // AI Forms specific prefixes (user meta)
    public const AIFORMS_USAGE_META_KEY = '_aipkit_aiforms_tokens_used';
    public const AIFORMS_RESET_META_KEY = '_aipkit_aiforms_tokens_reset';
}

namespace WPAICG\Core\TokenManager\Constants;

class GuestTableConstants {
    const GUEST_TABLE_NAME_SUFFIX = 'aipkit_guest_token_usage';
    const IMG_GEN_GUEST_CONTEXT_ID = 0; // Special context ID for image generator guest usage
    const AI_FORMS_GUEST_CONTEXT_ID = 1; // Special context ID for AI Forms guest usage
    const CONTENT_WRITER_GUEST_CONTEXT_ID = 2; // Special context ID for Content Writer guest usage
}

namespace WPAICG\Core\TokenManager;

use WPAICG\Core\TokenManager\Constants\CronHookConstant; // For CRON_HOOK

/**
 * Token usage, quota checks and reset lifecycle.
 * Handles token usage tracking, limits, and resets for different modules.
 * Delegates logic to namespaced functions.
 */
class AIPKit_Token_Manager {

    /**
     * Admit public AI requests independently of caller-controlled guest sessions.
     * Site administrators skip throttling; the global IP blocklist still applies.
     *
     * @return true|\WP_Error
     */
    public static function check_public_request_rate() {
        require_once WPAICG_PLUGIN_DIR . 'classes/security/moderation.php';
        $blocklists = \WPAICG\Core\Moderation\AIPKit_Global_Security_Settings::get_blocklists_for_module('');
        $ip = isset($_SERVER['REMOTE_ADDR']) ? filter_var(wp_unslash($_SERVER['REMOTE_ADDR']), FILTER_VALIDATE_IP) : false;
        $blocked = \WPAICG\Core\Moderation\AIPKit_BannedIP_Checker::check($ip ?: null, $blocklists['banned_ips_settings']);
        if (is_wp_error($blocked)) {
            return $blocked;
        }

        if (get_current_user_id() > 0 && \WPAICG\AIPKit_Role_Manager::user_can_manage_settings()) {
            return true;
        }

        if (!$ip) {
            return new \WP_Error('public_request_unavailable', __('Unable to verify this request. Please try again.', 'gpt3-ai-content-generator'), ['status' => 503]);
        }
        $ip = inet_ntop(inet_pton($ip));
        $limit = max(1, min(1000, (int) apply_filters('aipkit_public_ai_requests_per_minute', 60)));
        $now = time();
        $expires = (intdiv($now, 60) + 1) * 60;
        $name = '_aipkit_public_rate_' . hash_hmac('sha256', $ip, wp_salt('auth'));
        global $wpdb;

        // Store only a salted fingerprint, expiry and counter; never autoload these rows.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic admission must bypass the options/object cache.
        $inserted = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $expires . '|0'));
        if ($inserted === false) {
            return new \WP_Error('public_request_unavailable', __('Unable to verify this request. Please try again.', 'gpt3-ai-content-generator'), ['status' => 503]);
        }
        // A conditional UPDATE admits at most $limit callers, including simultaneous requests.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic conditional counter update; the options table name is supplied by WordPress.
        $admitted = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = CASE WHEN CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) <= %d THEN %s ELSE CONCAT(SUBSTRING_INDEX(option_value, '|', 1), '|', CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) + 1) END WHERE option_name = %s AND (CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) <= %d OR CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) < %d)",
            $now, $expires . '|1', $name, $now, $limit
        ));
        if ($inserted === 1) {
            // New visitors remove a bounded batch of idle counters without a background job.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Expired admission counters have no cached readers.
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) < %d LIMIT 100", $wpdb->esc_like('_aipkit_public_rate_') . '%', $now - 600));
        }
        if ($admitted === false) {
            return new \WP_Error('public_request_unavailable', __('Unable to verify this request. Please try again.', 'gpt3-ai-content-generator'), ['status' => 503]);
        }
        if ($admitted !== 1) {
            return new \WP_Error('public_request_rate_limit', __('Too many requests. Please wait a minute and try again.', 'gpt3-ai-content-generator'), ['status' => 429, 'retry_after' => $expires - $now]);
        }
        return true;
    }

    // --- Properties for dependencies (injected by ConstructorLogic) ---
    private $guest_table_name;
    private $bot_storage;
    private $price_resolver;
    private $usage_normalizer;
    private $charge_calculator;
    private $ledger_repository;
    private $balance_service;
    private $quota_service;
    // --- End Properties ---

    public function __construct() {
        // Call the constructor logic from the init sub-namespace
        Init\ConstructorLogic($this);
    }

    // --- Public static methods for cron scheduling ---
    public static function schedule_token_reset_event() {
        Cron\ScheduleTokenResetEventLogic(CronHookConstant::CRON_HOOK);
    }

    public static function unschedule_token_reset_event() {
        Cron\UnscheduleTokenResetEventLogic(CronHookConstant::CRON_HOOK);
    }
    // --- End cron scheduling ---

    // --- Public method for performing token reset ---
    public function perform_token_reset() {
        Reset\PerformTokenResetLogic($this);
    }
    // --- End perform token reset ---

    // --- Public static method for checking reset due ---
    public static function is_reset_due(int $last_reset_timestamp, string $period): bool {
        return Reset\IsResetDueLogic($last_reset_timestamp, $period);
    }
    // --- End checking reset due ---
    // --- Public methods for token checking and recording ---
    /**
     * @return bool|\WP_Error
     */
    public function check_and_reset_tokens(?int $user_id, ?string $session_id, ?int $context_id_or_bot_id, string $module_context = 'chat', array $usage_context = []) {
        return Check\CheckAndResetTokensLogic($this, $user_id, $session_id, $context_id_or_bot_id, $module_context, $usage_context);
    }

    public function record_token_usage(?int $user_id, ?string $session_id, ?int $context_id_or_bot_id, int $tokens_used, string $module_context = 'chat', array $usage_context = []) {
        Record\RecordTokenUsageLogic($this, $user_id, $session_id, $context_id_or_bot_id, $tokens_used, $module_context, $usage_context);
    }
    // --- End token checking and recording ---

    /**
     * Estimate the billable credits for a usage context without changing live behavior.
     *
     * @param int $fallback_units
     * @param string $module_context
     * @param array<string, mixed> $usage_context
     * @return array<string, mixed>
     */
    public function estimate_usage_charge(int $fallback_units, string $module_context = 'chat', array $usage_context = []): array {
        $normalized_usage = $this->usage_normalizer
            ? $this->usage_normalizer->normalize($usage_context, $fallback_units)
            : [
                'input_units' => 0,
                'output_units' => 0,
                'total_units' => max(0, $fallback_units),
                'unit_count' => max(0, $fallback_units),
                'fallback_units' => max(0, $fallback_units),
                'raw_usage_data' => [],
            ];

        $pricing_module = sanitize_key((string) ($usage_context['pricing_module'] ?? $module_context));
        if ($pricing_module === '') {
            $pricing_module = sanitize_key($module_context);
        }

        // Internal callers may freeze a rule for a metered session, including an unpriced session.
        $resolved_rule = array_key_exists('pricing_rule_snapshot', $usage_context)
            ? $usage_context['pricing_rule_snapshot']
            : ($this->price_resolver ? $this->price_resolver->resolve_rule($pricing_module, $usage_context) : null);

        if (!$this->charge_calculator) {
            return [
                'resolved_rule' => $resolved_rule,
                'billing_method' => is_array($resolved_rule) ? ($resolved_rule['billing_method'] ?? 'legacy_fallback') : 'legacy_fallback',
                'required_units' => max(0, $fallback_units),
                'billed_credits' => max(0, $fallback_units),
                'raw_charge' => (float) max(0, $fallback_units),
                'used_legacy_fallback' => true,
                'pricing_module' => $pricing_module,
                'normalized_usage' => $normalized_usage,
            ];
        }

        $charge = $this->charge_calculator->calculate($resolved_rule, $normalized_usage, $fallback_units);
        $charge['pricing_module'] = $pricing_module;

        return $charge;
    }


    // --- Getters for dependencies needed by logic functions (called via $this passed to them) ---
    public function get_guest_table_name(): string {
        return $this->guest_table_name;
    }

    public function get_bot_storage() { // Type hint can be added if BotStorage class is defined in a way it can be type-hinted here
        return $this->bot_storage;
    }

    public function get_price_resolver() {
        return $this->price_resolver;
    }

    public function get_usage_normalizer() {
        return $this->usage_normalizer;
    }

    public function get_charge_calculator() {
        return $this->charge_calculator;
    }

    public function get_ledger_repository() {
        return $this->ledger_repository;
    }

    public function get_balance_service() {
        return $this->balance_service;
    }

    public function get_quota_service() {
        return $this->quota_service;
    }
    // --- End Getters ---

    // --- Setters for dependencies (used by ConstructorLogic) ---
    public function set_guest_table_name(string $name): void {
        $this->guest_table_name = $name;
    }

    public function set_bot_storage($storage_instance): void { // Type hint can be added
        $this->bot_storage = $storage_instance;
    }

    public function set_price_resolver($resolver_instance): void {
        $this->price_resolver = $resolver_instance;
    }

    public function set_usage_normalizer($normalizer_instance): void {
        $this->usage_normalizer = $normalizer_instance;
    }

    public function set_charge_calculator($calculator_instance): void {
        $this->charge_calculator = $calculator_instance;
    }

    public function set_ledger_repository($repository_instance): void {
        $this->ledger_repository = $repository_instance;
    }

    public function set_balance_service($service_instance): void {
        $this->balance_service = $service_instance;
    }

    public function set_quota_service($service_instance): void {
        $this->quota_service = $service_instance;
    }
    // --- End Setters ---
}
