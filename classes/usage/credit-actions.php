<?php


namespace WPAICG\Chat\Admin\Ajax;

use WPAICG\Core\TokenManager\AIPKit_Token_Manager;
use WPAICG\Core\TokenManager\Constants\MetaKeysConstants;
use WPAICG\Chat\Admin\AdminSetup; // Needed for Post Type constant
use WP_User_Query;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Handles AJAX requests related to retrieving user credits/token usage data for the admin dashboard.
 * UPDATED: Fetches and includes Image Generator token usage.
 * UPDATED: Corrected constant names for chat token prefixes.
 * MODIFIED: Updated Token Manager namespace and constants usage.
 * ADDED: New method `ajax_admin_update_token_balance` to manually edit user token balances.
 */
class UserCreditsAjaxHandler extends BaseAjaxHandler
{
    private const CACHE_VERSION_OPTION = 'aipkit_user_credits_cache_version';

    private $token_manager;

    public function __construct()
    {
        if (!class_exists(\WPAICG\Core\TokenManager\AIPKit_Token_Manager::class)) {
            return;
        }
        $this->token_manager = new AIPKit_Token_Manager();
    }

    /**
     * AJAX: Retrieves paginated and searchable user token usage data.
     */
    public function ajax_get_user_credits_data()
    {
        $permission_check = $this->check_module_access_permissions('stats'); // Permissions now live under the Usage/Stats module
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions method.
        $current_page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions method.
        $search_term = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        $users_per_page = 20; // Or make this configurable

        $users_data = $this->get_users_with_token_usage($current_page, $users_per_page, $search_term);

        wp_send_json_success($users_data);
    }

    /**
     * AJAX: Updates a user's persistent token balance.
     * @since 2.2
     */
    public function ajax_admin_update_token_balance()
    {
        $permission_check = $this->check_module_access_permissions('stats', 'aipkit_user_credits_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions method.
        $post_data = wp_unslash($_POST);

        $user_id = isset($post_data['user_id']) ? absint($post_data['user_id']) : 0;
        $new_balance_raw = isset($post_data['balance']) ? trim((string) $post_data['balance']) : null;

        if (empty($user_id) || !get_userdata($user_id)) {
            wp_send_json_error(['message' => __('Invalid user ID.', 'gpt3-ai-content-generator')], 400);
            return;
        }
        if ($new_balance_raw === '') {
            $new_balance_raw = '0';
        }

        if ($new_balance_raw === null || !is_numeric($new_balance_raw)) {
            wp_send_json_error(['message' => __('Invalid balance amount. Please provide a number.', 'gpt3-ai-content-generator')], 400);
            return;
        }

        $new_balance = max(0, intval($new_balance_raw));
        $balance_service = $this->token_manager ? $this->token_manager->get_balance_service() : null;
        $ledger_repository = $this->token_manager ? $this->token_manager->get_ledger_repository() : null;
        $current_balance = $balance_service
            ? $balance_service->get_current_balance($user_id)
            : (int) get_user_meta($user_id, MetaKeysConstants::TOKEN_BALANCE_META_KEY, true);

        if ($balance_service) {
            $balance_service->set_current_balance($user_id, $new_balance);
        } else {
            update_user_meta($user_id, MetaKeysConstants::TOKEN_BALANCE_META_KEY, $new_balance);
        }

        $delta = $new_balance - $current_balance;
        if ($delta !== 0 && $ledger_repository && method_exists($ledger_repository, 'insert_entry')) {
            $ledger_repository->insert_entry([
                'user_id' => $user_id,
                'module' => 'credits',
                'context_type' => 'manual',
                'operation' => 'manual_adjustment',
                'credits_delta' => $delta,
                'entry_type' => 'admin_adjustment',
                'reference_type' => 'manual',
                'reference_id' => 'admin:' . get_current_user_id(),
                'meta' => [
                    'edited_by_user_id' => get_current_user_id(),
                    'balance_before' => $current_balance,
                    'balance_after' => $new_balance,
                ],
            ]);
        }

        $this->bust_user_credits_cache();

        wp_send_json_success(['message' => __('Credit balance updated successfully.', 'gpt3-ai-content-generator'), 'new_balance' => $new_balance]);
    }

    /**
     * AJAX: Resets a single user's periodic usage for one scope.
     *
     * Supported scopes:
     * - chatbot (requires scope_id)
     * - image_generator
     * - ai_forms
     * - all
     *
     * @since 2.2
     */
    public function ajax_admin_reset_usage_scope()
    {
        $permission_check = $this->check_module_access_permissions('stats', 'aipkit_user_credits_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in check_module_access_permissions method.
        $post_data = wp_unslash($_POST);
        $user_id = isset($post_data['user_id']) ? absint($post_data['user_id']) : 0;
        $scope_type = isset($post_data['scope_type']) ? sanitize_key($post_data['scope_type']) : '';
        $scope_id = isset($post_data['scope_id']) ? absint($post_data['scope_id']) : 0;

        if (empty($user_id) || !get_userdata($user_id)) {
            wp_send_json_error(['message' => __('Invalid user ID.', 'gpt3-ai-content-generator')], 400);
            return;
        }

        $reset_timestamp = time();

        switch ($scope_type) {
            case 'chatbot':
                if ($scope_id <= 0) {
                    wp_send_json_error(['message' => __('Invalid chatbot scope.', 'gpt3-ai-content-generator')], 400);
                    return;
                }
                delete_user_meta($user_id, MetaKeysConstants::CHAT_USAGE_META_KEY_PREFIX . $scope_id);
                update_user_meta($user_id, MetaKeysConstants::CHAT_RESET_META_KEY_PREFIX . $scope_id, $reset_timestamp);
                break;

            case 'image_generator':
                delete_user_meta($user_id, MetaKeysConstants::IMG_USAGE_META_KEY);
                update_user_meta($user_id, MetaKeysConstants::IMG_RESET_META_KEY, $reset_timestamp);
                break;

            case 'ai_forms':
                delete_user_meta($user_id, MetaKeysConstants::AIFORMS_USAGE_META_KEY);
                update_user_meta($user_id, MetaKeysConstants::AIFORMS_RESET_META_KEY, $reset_timestamp);
                break;

            case 'all':
                $user_meta = get_user_meta($user_id);
                $chatbot_ids = [];

                if (is_array($user_meta)) {
                    foreach (array_keys($user_meta) as $meta_key) {
                        if (strpos($meta_key, MetaKeysConstants::CHAT_USAGE_META_KEY_PREFIX) === 0) {
                            $bot_id = (int) str_replace(MetaKeysConstants::CHAT_USAGE_META_KEY_PREFIX, '', $meta_key);
                            if ($bot_id > 0) {
                                $chatbot_ids[$bot_id] = true;
                            }
                        } elseif (strpos($meta_key, MetaKeysConstants::CHAT_RESET_META_KEY_PREFIX) === 0) {
                            $bot_id = (int) str_replace(MetaKeysConstants::CHAT_RESET_META_KEY_PREFIX, '', $meta_key);
                            if ($bot_id > 0) {
                                $chatbot_ids[$bot_id] = true;
                            }
                        }
                    }
                }

                foreach (array_keys($chatbot_ids) as $bot_id) {
                    delete_user_meta($user_id, MetaKeysConstants::CHAT_USAGE_META_KEY_PREFIX . $bot_id);
                    update_user_meta($user_id, MetaKeysConstants::CHAT_RESET_META_KEY_PREFIX . $bot_id, $reset_timestamp);
                }

                delete_user_meta($user_id, MetaKeysConstants::IMG_USAGE_META_KEY);
                update_user_meta($user_id, MetaKeysConstants::IMG_RESET_META_KEY, $reset_timestamp);

                delete_user_meta($user_id, MetaKeysConstants::AIFORMS_USAGE_META_KEY);
                update_user_meta($user_id, MetaKeysConstants::AIFORMS_RESET_META_KEY, $reset_timestamp);
                break;

            default:
                wp_send_json_error(['message' => __('Invalid usage scope.', 'gpt3-ai-content-generator')], 400);
                return;
        }

        $this->bust_user_credits_cache();

        wp_send_json_success([
            'message' => __('Usage reset successfully.', 'gpt3-ai-content-generator'),
            'user_id' => $user_id,
            'scope_type' => $scope_type,
            'scope_id' => $scope_id,
            'reset_at' => $reset_timestamp,
        ]);
    }

    /**
     * Fetches users and aggregates their token usage across all bots AND the image generator.
     * Includes bot titles for detailed display.
     * ADDED: Fetches and includes the persistent token balance.
     *
     * @param int    $page Page number.
     * @param int    $number Users per page.
     * @param string $search Search term for username/email.
     * @return array {
     *     'users_data' => array List of user data with token info.
     *     'pagination' => array Pagination details.
     *     'bot_titles' => array Map of [bot_id => bot_title].
     * }
     */
    private function get_users_with_token_usage(int $page = 1, int $number = 20, string $search = ''): array
    {
        global $wpdb;
        $offset = ($page - 1) * $number;
        $cache_version = $this->get_user_credits_cache_version();

        // --- Base User Query Args ---
        $args = [
            'number' => $number,
            'offset' => $offset,
            'orderby' => 'display_name',
            'order' => 'ASC',
            'count_total' => false,
        ];

        $total_users_for_pagination = 0;
        $all_relevant_bot_ids = [];

        // --- Define Meta Key Prefixes using new constants ---
        $chat_usage_prefix = MetaKeysConstants::CHAT_USAGE_META_KEY_PREFIX;
        $chat_reset_prefix = MetaKeysConstants::CHAT_RESET_META_KEY_PREFIX;
        $img_usage_key = MetaKeysConstants::IMG_USAGE_META_KEY;
        $img_reset_key = MetaKeysConstants::IMG_RESET_META_KEY;
        $aiforms_usage_key = MetaKeysConstants::AIFORMS_USAGE_META_KEY;
        $aiforms_reset_key = MetaKeysConstants::AIFORMS_RESET_META_KEY;
        $balance_key = MetaKeysConstants::TOKEN_BALANCE_META_KEY;

        // --- Fetching Logic ---
        if (!empty($search)) {
            // Searching logic (remains the same, fetches users first)
            $args['search'] = '*' . esc_sql($search) . '*';
            $args['search_columns'] = ['user_login', 'user_email', 'user_nicename', 'display_name'];
            $count_args = $args;
            $count_args['number'] = -1;
            $count_args['offset'] = 0;
            $count_args['fields'] = 'ID';
            $count_query = new WP_User_Query($count_args);
            $total_users_for_pagination = $count_query->get_total();
        } else {
            // Not Searching: Find user IDs with chat, image, AI Forms usage, or balance meta
            $chat_usage_like = $wpdb->esc_like($chat_usage_prefix) . '%';
            $cache_key_user_ids = 'aipkit_credits_all_user_ids_' . $cache_version;
            $cache_group_user_ids = 'aipkit_user_credits';
            $user_ids_with_any_usage = wp_cache_get($cache_key_user_ids, $cache_group_user_ids);

            if (false === $user_ids_with_any_usage) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $user_ids_with_any_usage = $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s OR meta_key = %s OR meta_key = %s OR meta_key = %s OR meta_key = %s OR meta_key = %s ORDER BY user_id",
                    $chat_usage_like,
                    $wpdb->esc_like($chat_reset_prefix) . '%',
                    $img_usage_key,
                    $img_reset_key,
                    $aiforms_usage_key,
                    $aiforms_reset_key,
                    $balance_key
                ));
                wp_cache_set($cache_key_user_ids, $user_ids_with_any_usage, $cache_group_user_ids, MINUTE_IN_SECONDS);
            }

            if (empty($user_ids_with_any_usage)) {
                return ['users_data' => [], 'pagination' => ['total_users' => 0, 'total_pages' => 0, 'current_page' => 1, 'per_page' => $number], 'bot_titles' => []];
            }
            $total_users_for_pagination = count($user_ids_with_any_usage);
            $user_ids_to_query = array_slice($user_ids_with_any_usage, $offset, $number);
            if (empty($user_ids_to_query)) {
                return ['users_data' => [], 'pagination' => ['total_users' => $total_users_for_pagination, 'total_pages' => ceil($total_users_for_pagination / $number), 'current_page' => $page, 'per_page' => $number], 'bot_titles' => []];
            }
            $args['include'] = $user_ids_to_query;
        }
        // --- End Fetching Logic ---

        // --- Execute User Query ---
        $user_query = new WP_User_Query($args);
        $users = $user_query->get_results();
        $total_pages = ceil($total_users_for_pagination / $number);
        // --- End User Query ---

        $users_output = [];

        if (!empty($users)) {
            $user_ids = wp_list_pluck($users, 'ID');
            $user_ids_placeholder = implode(',', array_fill(0, count($user_ids), '%d'));

            // --- Fetch ALL relevant meta keys (chat usage, chat reset, image usage, image reset, balance) ---
            $meta_keys_to_fetch_patterns = [
                $wpdb->esc_like($chat_usage_prefix) . '%',
                $wpdb->esc_like($chat_reset_prefix) . '%',
                    $img_usage_key,
                    $img_reset_key,
                    $aiforms_usage_key,
                    $aiforms_reset_key,
                    $balance_key // NEW
                ];

            $cache_key_meta = 'aipkit_credits_meta_' . $cache_version . '_' . md5(implode(',', $user_ids));
            $cache_group_meta = 'aipkit_user_credits';
            $all_meta = wp_cache_get($cache_key_meta, $cache_group_meta);

            if (false === $all_meta) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The dynamic IN clause is securely built with %d placeholders, and the number of arguments is correctly matched. This is a false positive from the linter.
                $all_meta = $wpdb->get_results($wpdb->prepare("SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id IN ($user_ids_placeholder) AND (meta_key LIKE %s OR meta_key LIKE %s OR meta_key = %s OR meta_key = %s OR meta_key = %s OR meta_key = %s OR meta_key = %s)", array_merge($user_ids, $meta_keys_to_fetch_patterns)), ARRAY_A);
                wp_cache_set($cache_key_meta, $all_meta, $cache_group_meta, MINUTE_IN_SECONDS);
            }
            // --- End Fetch Meta ---

            // --- Process Meta Data ---
            $user_meta_map = [];
            foreach ($all_meta as $meta) {
                $uid = (int) $meta['user_id'];
                if (!isset($user_meta_map[$uid])) {
                    $user_meta_map[$uid] = [];
                }
                $user_meta_map[$uid][$meta['meta_key']] = $meta['meta_value'];

                // Collect bot IDs encountered from CHAT usage/reset keys
                if (strpos($meta['meta_key'], $chat_usage_prefix) === 0 || strpos($meta['meta_key'], $chat_reset_prefix) === 0) {
                    $bot_id_str = preg_replace('/^' . preg_quote($chat_usage_prefix, '/') . '|^' . preg_quote($chat_reset_prefix, '/') . '/', '', $meta['meta_key']);
                    if (ctype_digit($bot_id_str)) {
                        $all_relevant_bot_ids[] = (int) $bot_id_str;
                    }
                }
            }
            // --- End Process Meta ---

            // --- Fetch Bot Titles (remains the same) ---
            $bot_titles = [];
            $unique_bot_ids = array_unique(array_filter($all_relevant_bot_ids));
            if (!empty($unique_bot_ids)) {
                $bot_query_args = [
                    'post_type' => AdminSetup::POST_TYPE, 'post__in' => $unique_bot_ids, 'posts_per_page' => -1,
                    'post_status' => ['publish', 'draft'], 'no_found_rows' => true, 'update_post_meta_cache' => false, 'update_post_term_cache' => false,
                ];
                $bot_posts = get_posts($bot_query_args);
                foreach ($bot_posts as $bot_post) {
                    if (is_object($bot_post) && isset($bot_post->ID) && isset($bot_post->post_title)) {
                        $bot_titles[$bot_post->ID] = $bot_post->post_title;
                    }
                }
            }
            // --- End Fetch Bot Titles ---

            // --- Aggregate User Data ---
            if (!function_exists('WPAICG\Shortcodes\TokenUsage\Data\get_user_purchase_history_logic')) {
                $history_logic_path = WPAICG_PLUGIN_DIR . 'classes/usage/shortcode.php';
                if (file_exists($history_logic_path)) {
                    require_once $history_logic_path;
                }
            }

            foreach ($users as $user) {
                $user_id = $user->ID;
                $user_specific_meta = $user_meta_map[$user_id] ?? [];
                $tokens_used_per_bot = [];
                $last_reset_per_bot = [];
                $total_used_all_bots = 0;
                $token_balance = isset($user_specific_meta[$balance_key]) ? (int)$user_specific_meta[$balance_key] : 0; // NEW

                // Image Generator Specific Data
                $image_usage = ['used' => 0, 'last_reset' => 0];
                $ai_forms_usage = ['used' => 0, 'last_reset' => 0];

                foreach ($user_specific_meta as $meta_key => $meta_value) {
                    if (strpos($meta_key, $chat_usage_prefix) === 0) {
                        $bot_id = (int) str_replace($chat_usage_prefix, '', $meta_key);
                        if ($bot_id > 0) {
                            $usage = absint($meta_value);
                            $tokens_used_per_bot[$bot_id] = $usage;
                            $total_used_all_bots += $usage;
                        }
                    } elseif (strpos($meta_key, $chat_reset_prefix) === 0) {
                        $bot_id = (int) str_replace($chat_reset_prefix, '', $meta_key);
                        if ($bot_id > 0) {
                            $last_reset_per_bot[$bot_id] = absint($meta_value);
                        }
                    } elseif ($meta_key === MetaKeysConstants::IMG_USAGE_META_KEY) {
                        $usage = absint($meta_value);
                        $image_usage['used'] = $usage;
                        $total_used_all_bots += $usage;
                    } elseif ($meta_key === MetaKeysConstants::IMG_RESET_META_KEY) {
                        $image_usage['last_reset'] = absint($meta_value);
                    } elseif ($meta_key === MetaKeysConstants::AIFORMS_USAGE_META_KEY) {
                        $usage = absint($meta_value);
                        $ai_forms_usage['used'] = $usage;
                        $total_used_all_bots += $usage;
                    } elseif ($meta_key === MetaKeysConstants::AIFORMS_RESET_META_KEY) {
                        $ai_forms_usage['last_reset'] = absint($meta_value);
                    }
                }

                $purchase_history = [];
                if (function_exists('WPAICG\Shortcodes\TokenUsage\Data\get_user_purchase_history_logic') && class_exists('WooCommerce')) {
                    $purchase_history = \WPAICG\Shortcodes\TokenUsage\Data\get_user_purchase_history_logic($user_id);
                }


                $users_output[] = [
                    'id' => $user_id,
                    'display_name' => $user->display_name,
                    'email' => $user->user_email,
                    'token_balance' => $token_balance, // NEW
                    'total_used_all_bots' => $total_used_all_bots,
                    'tokens_used' => $tokens_used_per_bot,
                    'last_reset' => $last_reset_per_bot,
                    'image_usage' => $image_usage,
                    'ai_forms_usage' => $ai_forms_usage,
                    'purchase_history' => $purchase_history,
                ];
            }
            // --- End Aggregate ---
        }

        return [
            'users_data' => $users_output,
            'pagination' => ['total_users' => $total_users_for_pagination, 'total_pages' => $total_pages, 'current_page' => $page, 'per_page' => $number],
            'bot_titles' => $bot_titles,
        ];
    }

    /**
     * Returns the current cache version for Balances data.
     */
    private function get_user_credits_cache_version(): string
    {
        $version = get_option(self::CACHE_VERSION_OPTION, '1');

        return is_scalar($version) ? (string) $version : '1';
    }

    /**
     * Invalidates Balances data caches.
     */
    private function bust_user_credits_cache(): void
    {
        update_option(self::CACHE_VERSION_OPTION, (string) microtime(true), false);
    }
}
