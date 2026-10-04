<?php
/**
 * Token usage dashboard, quota display, activity details and purchase history.
 */

namespace WPAICG\Shortcodes;

use WPAICG\Shortcodes\TokenUsage\Render as TokenUsageRenderer;
use WPAICG\Shortcodes\TokenUsage\Helpers as TokenUsageHelpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AIPKit_Token_Usage_Shortcode (Facade)
 *
 * Handles the rendering of the [aipkit_token_usage] shortcode by delegating
 * logic to namespaced functions.
 */
class AIPKit_Token_Usage_Shortcode
{
    /**
     * Registers the AJAX hook for fetching usage details.
     */
    public function init_hooks()
    {
        add_action('wp_ajax_aipkit_get_token_usage_details', [$this, 'ajax_get_token_usage_details']);
    }

    /**
     * AJAX handler to fetch detailed token usage for a specific module.
     */
    public function ajax_get_token_usage_details()
    {
        check_ajax_referer('aipkit_token_usage_details_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in to view details.', 'gpt3-ai-content-generator')], 403);
            return;
        }

        $user_id = get_current_user_id();
        $module = isset($_POST['module']) ? sanitize_key($_POST['module']) : '';
        $context_id = isset($_POST['context_id']) ? absint($_POST['context_id']) : 0;
        $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $per_page = 10;

        if (empty($module)) {
            wp_send_json_error(['message' => __('Module not specified.', 'gpt3-ai-content-generator')], 400);
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'aipkit_chat_logs';
        $where_clauses = ['user_id = %d', 'module = %s'];
        $params = [$user_id, $module];

        if ($module === 'chat') {
            if (empty($context_id)) {
                wp_send_json_error(['message' => __('Chatbot ID is required.', 'gpt3-ai-content-generator')], 400);
                return;
            }
            $where_clauses[] = 'bot_id = %d';
            $params[] = $context_id;
        } else {
            $where_clauses[] = 'bot_id IS NULL';
        }

        $where_sql = implode(' AND ', $where_clauses);

        // --- Caching for token usage details query ---
        $cache_key = 'aipkit_token_usage_details_' . md5(serialize($params));
        $cache_group = 'aipkit_token_usage';
        $conversations = wp_cache_get($cache_key, $cache_group);

        if (false === $conversations) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reason: Necessary for fetching and processing usage data from the custom logs table. Caching is implemented.
            $conversations = $wpdb->get_results($wpdb->prepare("SELECT messages FROM {$table_name} WHERE {$where_sql}", $params), ARRAY_A);
            wp_cache_set($cache_key, $conversations, $cache_group, MINUTE_IN_SECONDS); // Cache for 1 minute
        }
        // --- End Caching ---

        $usage_details = [];
        if (!empty($conversations)) {
            foreach ($conversations as $conv) {
                $messages_data = json_decode($conv['messages'], true);
                $messages_array = $messages_data['messages'] ?? ($messages_data ?? []);
                if (is_array($messages_array)) {
                    foreach ($messages_array as $msg) {
                        if (isset($msg['role']) && ($msg['role'] === 'bot' || $msg['role'] === 'assistant') && isset($msg['usage']['total_tokens']) && $msg['usage']['total_tokens'] > 0) {
                            $usage_details[] = [
                                'timestamp' => $msg['timestamp'] ?? 0,
                                'tokens'    => (int) $msg['usage']['total_tokens']
                            ];
                        }
                    }
                }
            }
        }

        // Sort by most recent
        usort($usage_details, fn ($a, $b) => $b['timestamp'] <=> $a['timestamp']);

        $total_items = count($usage_details);
        $total_pages = ceil($total_items / $per_page);
        $offset = ($page - 1) * $per_page;
        $paginated_items = array_slice($usage_details, $offset, $per_page);

        wp_send_json_success([
            'items' => $paginated_items,
            'pagination' => [
                'currentPage' => $page,
                'totalPages'  => $total_pages,
                'totalItems'  => $total_items
            ]
        ]);
    }

    /**
     * Render the shortcode output.
     * Delegates logic to \WPAICG\Shortcodes\TokenUsage\Render\render_shortcode_logic().
     *
     * @param array $atts Shortcode attributes. Supported: chatbot, aiforms, imagegenerator (all 'true' or 'false').
     * @return string HTML output.
     */
    public function render_shortcode($atts = [])
    {
        return TokenUsageRenderer\render_shortcode_logic($this, $atts);
    }

    /**
     * Helper to determine the token limit for a logged-in user for a specific module.
     * Delegates logic to \WPAICG\Shortcodes\TokenUsage\Helpers\get_user_limit_for_module_logic().
     * This method is public so it can be called by the modularized data fetching logic.
     *
     * @param int $user_id The user ID.
     * @param array $module_token_settings The token management settings for the module.
     * @return int|null The token limit (int > 0), 0 if disabled, or null if unlimited.
     */
    public function get_user_limit_for_module(int $user_id, array $module_token_settings): ?int
    {
        return TokenUsageHelpers\get_user_limit_for_module_logic($user_id, $module_token_settings);
    }

}

namespace WPAICG\Shortcodes\TokenUsage\Helpers;

/**
 * Logic to determine the token limit for a logged-in user for a specific module.
 *
 * @param int $user_id The user ID.
 * @param array $module_token_settings The token management settings for the module.
 * @return int|null The token limit (int > 0), 0 if disabled, or null if unlimited.
 */
function get_user_limit_for_module_logic(int $user_id, array $module_token_settings): ?int
{
    $limit_mode = $module_token_settings['token_limit_mode'] ?? 'general';
    $limit = null;

    if ($limit_mode === 'general') {
        $limit = $module_token_settings['token_user_limit'] ?? null;
    } else { // role-based
        $user_data = get_userdata($user_id);
        $user_roles = $user_data ? (array) $user_data->roles : [];
        $role_limits_raw = $module_token_settings['token_role_limits'] ?? [];
        $role_limits = is_string($role_limits_raw) ? json_decode($role_limits_raw, true) : (is_array($role_limits_raw) ? $role_limits_raw : []);
        if (!is_array($role_limits)) {
            $role_limits = [];
        }

        if (empty($user_roles) || empty($role_limits)) {
            $limit = null;
        } else {
            $highest_limit = -1; // -1 indicates no specific limit found for user's roles
            foreach ($user_roles as $role) {
                if (isset($role_limits[$role])) {
                    $role_limit_value_raw = $role_limits[$role];
                    if ($role_limit_value_raw === null || $role_limit_value_raw === '') {
                        $highest_limit = null; // Explicitly unlimited for this role, overrides others
                        break;
                    }
                    if ($role_limit_value_raw === '0' || $role_limit_value_raw === 0) {
                        $highest_limit = max($highest_limit, 0);
                    } elseif (ctype_digit((string)$role_limit_value_raw)) {
                        $highest_limit = max($highest_limit, (int)$role_limit_value_raw);
                    }
                }
            }
            $limit = ($highest_limit === -1) ? null : $highest_limit;
        }
    }

    if ($limit === '') {
        $limit = null;
    } elseif (is_numeric($limit)) {
        $limit = (int) $limit;
    }

    return $limit;
}

namespace WPAICG\Shortcodes\TokenUsage\Data;

use WPAICG\Chat\Storage\BotStorage;
use WPAICG\Core\TokenManager\Constants\MetaKeysConstants;
use WPAICG\Images\AIPKit_Image_Settings_Ajax_Handler;
use WPAICG\AIForms\Admin\AIPKit_AI_Form_Settings_Ajax_Handler;
use WPAICG\Chat\Admin\AdminSetup;

/**
 * Logic to fetch and structure token usage data for the current user.
 *
 * @param \WPAICG\Shortcodes\AIPKit_Token_Usage_Shortcode $facade The facade instance.
 * @param int $user_id The ID of the current user.
 * @return array Structured usage data.
 */
function get_user_token_usage_data_logic(\WPAICG\Shortcodes\AIPKit_Token_Usage_Shortcode $facade, $user_id): array
{
    global $wpdb;
    $data = [
        'token_balance' => (int) get_user_meta($user_id, MetaKeysConstants::TOKEN_BALANCE_META_KEY, true),
        'chat' => [],
        'image_generator' => [],
        'ai_forms' => [],
    ];

    // Ensure dependencies exist
    if (!class_exists('\\WPAICG\\Chat\\Storage\\BotStorage')) {
        return $data;
    }

    $usage_meta_prefix = MetaKeysConstants::CHAT_USAGE_META_KEY_PREFIX;

    // --- Caching for meta_keys query ---
    $cache_key = 'aipkit_token_usage_meta_keys_' . $user_id;
    $cache_group = 'aipkit_token_usage';
    $meta_keys = wp_cache_get($cache_key, $cache_group);

    if (false === $meta_keys) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Necessary for fetching meta keys with a LIKE condition, which standard WP functions do not support. Caching is implemented.
        $meta_keys = $wpdb->get_col($wpdb->prepare(
            "SELECT meta_key FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
            $user_id,
            $wpdb->esc_like($usage_meta_prefix) . '%'
        ));
        wp_cache_set($cache_key, $meta_keys, $cache_group, MINUTE_IN_SECONDS); // Cache for 1 minute
    }
    // --- End Caching ---


    if (!empty($meta_keys)) {
        $bot_storage = new BotStorage();

        foreach ($meta_keys as $meta_key) {
            $bot_id = (int) str_replace($usage_meta_prefix, '', $meta_key);
            if ($bot_id > 0) {
                $bot_post = get_post($bot_id);
                if ($bot_post && $bot_post->post_type === AdminSetup::POST_TYPE) {
                    $used_tokens = (int) get_user_meta($user_id, $meta_key, true);
                    $settings = $bot_storage->get_chatbot_settings($bot_id);
                    $limit = $facade->get_user_limit_for_module($user_id, $settings);

                    if ($limit !== 0) {
                        $data['chat'][] = [
                           'module' => 'chat', // NEW
                           'context_id' => $bot_id, // NEW
                           /* translators: %d: Bot ID */
                           'title' => $bot_post->post_title ?: sprintf(__('Bot #%d', 'gpt3-ai-content-generator'), $bot_id),
                           'used' => $used_tokens,
                           'limit' => $limit,
                        ];
                    }
                }
            }
        }
    }

    if (class_exists('\\WPAICG\\Images\\AIPKit_Image_Settings_Ajax_Handler')) {
        $img_settings_all = AIPKit_Image_Settings_Ajax_Handler::get_settings();
        $img_token_settings = $img_settings_all['token_management'] ?? [];
        $limit = $facade->get_user_limit_for_module($user_id, $img_token_settings);

        if ($limit !== 0) {
            $used_tokens = (int) get_user_meta($user_id, MetaKeysConstants::IMG_USAGE_META_KEY, true);
            if ($used_tokens > 0 || $limit !== null) {
                $data['image_generator'][] = [
                   'module' => 'image_generator', // NEW
                   'context_id' => 0, // NEW (0 for generic module context)
                   'title' => __('Image Generator', 'gpt3-ai-content-generator'),
                   'used' => $used_tokens,
                   'limit' => $limit,
                ];
            }
        }
    }

    if (class_exists('\\WPAICG\\AIForms\\Admin\\AIPKit_AI_Form_Settings_Ajax_Handler')) {
        $aiform_settings_all = AIPKit_AI_Form_Settings_Ajax_Handler::get_settings();
        $aiform_token_settings = $aiform_settings_all['token_management'] ?? [];
        $limit = $facade->get_user_limit_for_module($user_id, $aiform_token_settings);

        if ($limit !== 0) {
            $used_tokens = (int) get_user_meta($user_id, MetaKeysConstants::AIFORMS_USAGE_META_KEY, true);
            if ($used_tokens > 0 || $limit !== null) {
                $data['ai_forms'][] = [
                   'module' => 'ai_forms', // NEW
                   'context_id' => 1, // NEW (1 for generic module context)
                   'title' => __('AI Forms', 'gpt3-ai-content-generator'),
                   'used' => $used_tokens,
                   'limit' => $limit,
                ];
            }
        }
    }

    return $data;
}

/**
 * Fetch token purchase history for a specific user.
 * 
 * @param int $user_id The user ID.
 * @param int $limit Maximum number of purchases to return. Default: 10.
 * @return array Array of purchase details with order info, tokens granted, date, etc.
 */
function get_user_purchase_history_logic(int $user_id, int $limit = 10): array
{
    // Return empty array if WooCommerce is not active
    if (!function_exists('wc_get_orders')) {
        return [];
    }

    try {
        // Get completed orders for this user
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'status' => 'completed',
            'limit' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $purchase_history = [];

        foreach ($orders as $order) {
            $order_data = [
                'order_id' => $order->get_id(),
                'date' => $order->get_date_completed(),
                'total_amount' => $order->get_total(),
                'currency' => $order->get_currency(),
                'tokens_granted' => 0,
                'products' => [],
            ];

            $has_token_products = false;

            // Check each item in the order for token packages
            foreach ($order->get_items() as $item) {
                $product_id = $item->get_product_id();
                $is_token_package = get_post_meta($product_id, '_aipkit_is_token_package', true);

                if ($is_token_package === 'yes') {
                    $has_token_products = true;
                    $tokens_per_product = (int) get_post_meta($product_id, '_aipkit_tokens_amount', true);
                    $quantity = $item->get_quantity();
                    $product_tokens = $tokens_per_product * $quantity;
                    
                    $order_data['tokens_granted'] += $product_tokens;
                    $order_data['products'][] = [
                        'name' => $item->get_name(),
                        'quantity' => $quantity,
                        'tokens_per_item' => $tokens_per_product,
                        'total_tokens' => $product_tokens,
                        'line_total' => $item->get_total(),
                    ];
                }
            }

            // Only include orders that contained token packages
            if ($has_token_products) {
                $purchase_history[] = $order_data;
            }
        }

        return $purchase_history;

    } catch (\Exception $e) {
        return [];
    }
}

namespace WPAICG\Shortcodes\TokenUsage\Render;

/**
 * Logic for the render_shortcode method.
 *
 * @param \WPAICG\Shortcodes\AIPKit_Token_Usage_Shortcode $facade The facade instance.
 * @param array $atts Shortcode attributes.
 * @return string HTML output.
 */
function render_shortcode_logic(\WPAICG\Shortcodes\AIPKit_Token_Usage_Shortcode $facade, $atts = []): string
{
    // Check if user is logged in
    if (!is_user_logged_in()) {
        return '<p class="aipkit-login-prompt">' . esc_html__('Please log in to view your credits and usage.', 'gpt3-ai-content-generator') . '</p>';
    }

    $default_atts = [
        'chatbot'        => 'true',
        'aiforms'        => 'true',
        'imagegenerator' => 'true',
        'title'          => __('Credits & Usage', 'gpt3-ai-content-generator'),
        'intro'          => __('View your credits, purchases, and quotas.', 'gpt3-ai-content-generator'),
        'buycredits'     => 'true',
        'buycreditslabel'=> __('Buy credits', 'gpt3-ai-content-generator'),
        'buycreditsurl'  => '',
        'purchasehistory'=> 'true',
    ];
    $atts = shortcode_atts($default_atts, $atts, 'aipkit_token_usage');

    $show_chatbot = filter_var($atts['chatbot'], FILTER_VALIDATE_BOOLEAN);
    $show_aiforms = filter_var($atts['aiforms'], FILTER_VALIDATE_BOOLEAN);
    $show_imagegenerator = filter_var($atts['imagegenerator'], FILTER_VALIDATE_BOOLEAN);
    $show_buy_credits = filter_var($atts['buycredits'], FILTER_VALIDATE_BOOLEAN);
    $show_purchase_history = filter_var($atts['purchasehistory'], FILTER_VALIDATE_BOOLEAN);
    $dashboard_title = sanitize_text_field((string) ($atts['title'] ?? ''));
    $dashboard_intro = sanitize_text_field((string) ($atts['intro'] ?? ''));
    $buy_credits_label = sanitize_text_field((string) ($atts['buycreditslabel'] ?? ''));
    $buy_credits_url = esc_url_raw((string) ($atts['buycreditsurl'] ?? ''));

    if ($dashboard_title === '') {
        $dashboard_title = __('Credits & Usage', 'gpt3-ai-content-generator');
    }
    if ($dashboard_intro === '') {
        $dashboard_intro = __('View your credits, purchases, and quotas.', 'gpt3-ai-content-generator');
    }
    if ($buy_credits_label === '') {
        $buy_credits_label = __('Buy credits', 'gpt3-ai-content-generator');
    }

    $user_id = get_current_user_id();

    $usage_data = \WPAICG\Shortcodes\TokenUsage\Data\get_user_token_usage_data_logic($facade, $user_id);
    $usage_data = apply_filters('aipkit_token_usage_data', $usage_data, $user_id);

    return \WPAICG\Shortcodes\TokenUsage\Render\render_dashboard_logic(
        $facade,
        $usage_data,
        $show_chatbot,
        $show_aiforms,
        $show_imagegenerator,
        $dashboard_title,
        $dashboard_intro,
        $show_buy_credits,
        $buy_credits_label,
        $buy_credits_url,
        $show_purchase_history
    );
}


/**
 * Logic for rendering the HTML for the token usage dashboard.
 *
 * @param \WPAICG\Shortcodes\AIPKit_Token_Usage_Shortcode $facade The facade instance.
 * @param array $usage_data Structured usage data grouped by module.
 * @param bool $show_chatbot
 * @param bool $show_aiforms
 * @param bool $show_imagegenerator
 * @param string $dashboard_title
 * @param string $dashboard_intro
 * @param bool $show_buy_credits
 * @param string $buy_credits_label
 * @param string $buy_credits_url
 * @param bool $show_purchase_history
 * @return string HTML output.
 */
function render_dashboard_logic(
    \WPAICG\Shortcodes\AIPKit_Token_Usage_Shortcode $facade,
    array $usage_data,
    bool $show_chatbot = true,
    bool $show_aiforms = true,
    bool $show_imagegenerator = true,
    string $dashboard_title = '',
    string $dashboard_intro = '',
    bool $show_buy_credits = true,
    string $buy_credits_label = '',
    string $buy_credits_url = '',
    bool $show_purchase_history = true
): string {
    ob_start();
    $shop_page_url = trim($buy_credits_url);
    if ($shop_page_url === '') {
        $shop_page_url = get_option('aipkit_token_shop_page_url', '');
    }
    if ($shop_page_url === '' && function_exists('wc_get_page_id')) {
        $shop_page_url = get_permalink(wc_get_page_id('shop'));
    }
    $dashboard_title = trim($dashboard_title) !== ''
        ? $dashboard_title
        : __('Credits & Usage', 'gpt3-ai-content-generator');
    $dashboard_intro = trim($dashboard_intro) !== ''
        ? $dashboard_intro
        : __('View your credits, purchases, and quotas.', 'gpt3-ai-content-generator');
    $buy_credits_label = trim($buy_credits_label) !== ''
        ? $buy_credits_label
        : __('Buy credits', 'gpt3-ai-content-generator');
    ?>
    <div class="aipkit_token_usage_dashboard">
        <div class="aipkit_token_usage_header">
            <div class="aipkit_token_usage_header_copy">
                <h2 class="aipkit_token_usage_title"><?php echo esc_html($dashboard_title); ?></h2>
                <p class="aipkit_token_usage_intro"><?php echo esc_html($dashboard_intro); ?></p>
            </div>
        </div>
        <div class="aipkit_token_usage_content">

            <?php
            $purchase_history = \WPAICG\Shortcodes\TokenUsage\Data\get_user_purchase_history_logic(get_current_user_id(), 10);
            echo wp_kses_post(\WPAICG\Shortcodes\TokenUsage\Render\render_purchase_details_logic(
                $purchase_history,
                $usage_data['token_balance'],
                $show_buy_credits ? (string) $shop_page_url : '',
                $buy_credits_label,
                $show_purchase_history
            ));
            ?>

            <?php
            $has_periodic_usage = ($show_chatbot && !empty($usage_data['chat'])) ||
                                  ($show_imagegenerator && !empty($usage_data['image_generator'])) ||
                                  ($show_aiforms && !empty($usage_data['ai_forms'])) ||
                                  has_action('aipkit_after_token_usage_dashboard');
            ?>
            <section class="aipkit_customer_shell aipkit_customer_shell--usage" id="aipkit_customer_dashboard_usage">
                <div class="aipkit_customer_shell_header">
                    <div class="aipkit_customer_shell_intro">
                        <h3 class="aipkit_customer_shell_title"><?php esc_html_e('Quota Usage', 'gpt3-ai-content-generator'); ?></h3>
                        <p class="aipkit_customer_shell_hint"><?php esc_html_e('Track usage across your enabled modules.', 'gpt3-ai-content-generator'); ?></p>
                    </div>
                </div>
                <div class="aipkit_customer_shell_body aipkit_customer_shell_body--usage">
                    <?php if ($has_periodic_usage) : ?>
                        <div class="aipkit_customer_usage_stack">
                            <?php if ($show_chatbot && !empty($usage_data['chat'])) : ?>
                                <div class="aipkit_customer_usage_group">
                                    <h4 class="aipkit_customer_usage_group_title"><?php esc_html_e('Chatbot', 'gpt3-ai-content-generator'); ?></h4>
                                    <div class="aipkit_usage_table_shell">
                                        <table class="aipkit_usage_table">
                                            <?php echo wp_kses_post(render_module_table_header_logic(__('Chatbot', 'gpt3-ai-content-generator'))); ?>
                                            <tbody>
                                                <?php
                                                foreach ($usage_data['chat'] as $bot_usage) {
                                                    echo wp_kses_post(\WPAICG\Shortcodes\TokenUsage\Render\render_usage_row_logic($facade, $bot_usage, __('Chatbot', 'gpt3-ai-content-generator')));
                                                }
                                                ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($show_imagegenerator && !empty($usage_data['image_generator'])) : ?>
                                <div class="aipkit_customer_usage_group">
                                    <h4 class="aipkit_customer_usage_group_title"><?php esc_html_e('Image Generator', 'gpt3-ai-content-generator'); ?></h4>
                                    <div class="aipkit_usage_table_shell">
                                        <table class="aipkit_usage_table">
                                            <?php echo wp_kses_post(render_module_table_header_logic(__('Module', 'gpt3-ai-content-generator'))); ?>
                                            <tbody>
                                                <?php
                                                foreach ($usage_data['image_generator'] as $item) {
                                                    echo wp_kses_post(\WPAICG\Shortcodes\TokenUsage\Render\render_usage_row_logic($facade, $item, __('Module', 'gpt3-ai-content-generator')));
                                                }
                                                ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($show_aiforms && !empty($usage_data['ai_forms'])) : ?>
                                <div class="aipkit_customer_usage_group">
                                    <h4 class="aipkit_customer_usage_group_title"><?php esc_html_e('AI Forms', 'gpt3-ai-content-generator'); ?></h4>
                                    <div class="aipkit_usage_table_shell">
                                        <table class="aipkit_usage_table">
                                            <?php echo wp_kses_post(render_module_table_header_logic(__('Module', 'gpt3-ai-content-generator'))); ?>
                                            <tbody>
                                                <?php
                                                foreach ($usage_data['ai_forms'] as $item) {
                                                    echo wp_kses_post(\WPAICG\Shortcodes\TokenUsage\Render\render_usage_row_logic($facade, $item, __('Module', 'gpt3-ai-content-generator')));
                                                }
                                                ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php do_action('aipkit_after_token_usage_dashboard', $usage_data); ?>
                        </div>
                    <?php else : ?>
                        <div class="aipkit_usage_empty_state">
                            <p class="aipkit_customer_shell_hint"><?php esc_html_e('No quota usage has been recorded yet.', 'gpt3-ai-content-generator'); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Logic to render a single row in a usage table.
 *
 * @param \WPAICG\Shortcodes\AIPKit_Token_Usage_Shortcode $facade The facade instance.
 * @param array $item The usage data item.
 * @param string $first_column_label The label for the first column ('Bot Name' or 'Module').
 * @return string HTML for the table row.
 */
function render_usage_row_logic(\WPAICG\Shortcodes\AIPKit_Token_Usage_Shortcode $facade, array $item, string $first_column_label): string
{
    $used = (int) ($item['used'] ?? 0);
    $limit = $item['limit'] ?? null;
    $module = $item['module'] ?? '';
    $context_id = $item['context_id'] ?? 0;

    $remaining_display = '∞';
    $progress_percent = 0;
    $progress_display = '';
    $limit_display = esc_html__('Unlimited', 'gpt3-ai-content-generator');

    if (is_numeric($limit) && $limit > 0) {
        $limit_display = number_format_i18n($limit);
        $remaining = max(0, $limit - $used);
        $remaining_display = number_format_i18n($remaining);
        $progress_percent = round(($used / $limit) * 100);
        $progress_percent = min(100, $progress_percent);
        $progress_display = \WPAICG\Shortcodes\TokenUsage\Render\render_progress_bar_logic($progress_percent);
    } else {
        $remaining_display = '&mdash;';
        $progress_display = '<span class="aipkit_usage_progress_na">' . esc_html__('Not capped', 'gpt3-ai-content-generator') . '</span>';
    }

    ob_start();
    ?>
    <tr class="aipkit-usage-main-row">
        <td data-label="<?php echo esc_attr($first_column_label); ?>"><?php echo esc_html($item['title']); ?></td>
        <td data-label="<?php esc_attr_e('Used', 'gpt3-ai-content-generator'); ?>">
             <button
                type="button"
                class="aipkit-usage-details-btn aipkit-btn-as-link"
                title="<?php esc_attr_e('Click to view recent usage activity', 'gpt3-ai-content-generator'); ?>"
                data-module="<?php echo esc_attr($module); ?>"
                data-context-id="<?php echo esc_attr($context_id); ?>"
            >
                <?php echo esc_html(number_format_i18n($used)); ?>
            </button>
        </td>
        <td data-label="<?php esc_attr_e('Quota', 'gpt3-ai-content-generator'); ?>"><?php echo wp_kses_post($limit_display); ?></td>
        <td data-label="<?php esc_attr_e('Remaining', 'gpt3-ai-content-generator'); ?>"><?php echo wp_kses_post($remaining_display); ?></td>
        <td data-label="<?php esc_attr_e('Progress', 'gpt3-ai-content-generator'); ?>"><?php echo wp_kses_post($progress_display); ?></td>
    </tr>
    <?php
    return ob_get_clean();
}

/**
 * Logic to render the progress bar HTML.
 *
 * @param int $percentage The percentage to display.
 * @return string HTML for the progress bar.
 */
function render_progress_bar_logic($percentage): string
{
    $percentage = max(0, min(100, (int)$percentage));
    $color = '#4a6fa5';
    if ($percentage > 90) {
        $color = '#c45144';
    }
    elseif ($percentage > 70) {
        $color = '#d18b28';
    }

    return sprintf(
        '<div class="aipkit_progress_bar_container" title="%1$d%%">' .
            '<div class="aipkit_progress_bar_filled" style="width: %1$d%%; background-color: %2$s;"></div>' .
            '<span class="aipkit_progress_bar_text">%1$d%%</span>' .
        '</div>',
        esc_attr($percentage),
        esc_attr($color)
    );
}

/**
 * Logic for rendering the HTML for a module usage table header.
 *
 * @param string $first_column_label The label for the first column (e.g., 'Chatbot', 'Module').
 * @return string HTML output for the table header.
 */
function render_module_table_header_logic(string $first_column_label): string
{
    ob_start();
    ?>
    <thead>
        <tr>
            <th><?php echo esc_html($first_column_label); ?></th>
            <th><?php esc_html_e('Used', 'gpt3-ai-content-generator'); ?></th>
            <th><?php esc_html_e('Quota', 'gpt3-ai-content-generator'); ?></th>
            <th><?php esc_html_e('Remaining', 'gpt3-ai-content-generator'); ?></th>
            <th><?php esc_html_e('Progress', 'gpt3-ai-content-generator'); ?></th>
        </tr>
    </thead>
    <?php
    return ob_get_clean();
}

/**
 * Render the purchase details section with expandable purchase history.
 *
 * @param array $purchase_history Array of purchase data from get_user_purchase_history_logic
 * @param int $current_balance Current credit balance
 * @param string $shop_page_url Optional shop URL for buying more credits.
 * @param string $buy_credits_label Optional CTA label.
 * @param bool $show_purchase_history Whether to show purchase history UI.
 * @return string HTML output for purchase details section
 */
function render_purchase_details_logic(
    array $purchase_history,
    int $current_balance,
    string $shop_page_url = '',
    string $buy_credits_label = '',
    bool $show_purchase_history = true
): string
{
    $buy_credits_label = trim($buy_credits_label) !== ''
        ? $buy_credits_label
        : __('Buy credits', 'gpt3-ai-content-generator');
    ob_start();
    ?>

    <section class="aipkit_customer_shell aipkit_customer_shell--balance" id="aipkit_customer_dashboard_credits">
        <div class="aipkit_customer_shell_header">
            <div class="aipkit_customer_shell_intro">
                <h3 class="aipkit_customer_shell_title"><?php esc_html_e('Credits', 'gpt3-ai-content-generator'); ?></h3>
                <p class="aipkit_customer_shell_hint"><?php esc_html_e('Available balance and recent purchases.', 'gpt3-ai-content-generator'); ?></p>
            </div>
        </div>
        <div class="aipkit_customer_shell_body aipkit_customer_shell_body--balance">
            <div class="aipkit_token_balance_wrapper">
                <div class="aipkit_token_balance_info">
                    <span class="aipkit_token_balance_label"><?php esc_html_e('Available credits', 'gpt3-ai-content-generator'); ?></span>
                    <span class="aipkit_token_balance_value"><?php echo esc_html(number_format_i18n($current_balance)); ?></span>
                </div>

                <div class="aipkit_purchase_actions">
                    <?php if (!empty($shop_page_url)): ?>
                        <a
                            class="aipkit_btn aipkit_btn-primary"
                            href="<?php echo esc_url($shop_page_url); ?>"
                        >
                            <?php echo esc_html($buy_credits_label); ?>
                        </a>
                    <?php endif; ?>

                    <?php if ($show_purchase_history && !empty($purchase_history)): ?>
                    <button type="button"
                            class="aipkit_toggle_purchase_history"
                            aria-expanded="false"
                            aria-controls="aipkit_purchase_history_details">
                        <span class="aipkit_toggle_text"><?php esc_html_e('Recent purchases', 'gpt3-ai-content-generator'); ?></span>
                        <span class="aipkit_toggle_arrow">▼</span>
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($show_purchase_history && empty($purchase_history)): ?>
                <p class="aipkit_token_balance_empty"><?php esc_html_e('No credit purchases yet.', 'gpt3-ai-content-generator'); ?></p>
            <?php endif; ?>

            <?php if ($show_purchase_history && !empty($purchase_history)): ?>
                <div id="aipkit_purchase_history_details" class="aipkit_purchase_history_details" data-aipkit-customer-purchases="1" style="display: none;">
                    <h4 class="aipkit_purchase_history_title"><?php esc_html_e('Recent credit purchases', 'gpt3-ai-content-generator'); ?></h4>

                    <div class="aipkit_purchase_history_list">
                        <?php foreach ($purchase_history as $purchase): ?>
                            <div class="aipkit_purchase_item">
                                <div class="aipkit_purchase_header">
                                    <div class="aipkit_purchase_date">
                                        <strong><?php echo esc_html(wp_date(get_option('date_format'), $purchase['date']->getTimestamp())); ?></strong>
                                    </div>
                                    <div class="aipkit_purchase_summary_info">
                                        <span class="aipkit_purchase_tokens">+<?php echo esc_html(number_format_i18n($purchase['tokens_granted'])); ?> <?php esc_html_e('credits', 'gpt3-ai-content-generator'); ?></span>
                                        <span class="aipkit_purchase_amount"><?php echo wp_kses_post(wc_price($purchase['total_amount'])); ?></span>
                                    </div>
                                </div>

                                <div class="aipkit_purchase_details">
                                    <div class="aipkit_purchase_order_info">
                                        <span class="aipkit_purchase_order_id">
                                            <?php esc_html_e('Order #', 'gpt3-ai-content-generator'); ?><?php echo esc_html($purchase['order_id']); ?>
                                        </span>
                                    </div>

                                    <?php if (!empty($purchase['products'])): ?>
                                        <div class="aipkit_purchase_products">
                                            <?php foreach ($purchase['products'] as $product): ?>
                                                <div class="aipkit_purchase_product">
                                                    <span class="aipkit_product_name"><?php echo esc_html($product['name']); ?></span>
                                                    <span class="aipkit_product_details">
                                                        <?php if ($product['quantity'] > 1): ?>
                                                        <?php echo esc_html($product['quantity']); ?>x
                                                        <?php endif; ?>
                                                        <?php echo esc_html(number_format_i18n($product['tokens_per_item'])); ?> <?php esc_html_e('credits', 'gpt3-ai-content-generator'); ?>
                                                        = <?php echo esc_html(number_format_i18n($product['total_tokens'])); ?> <?php esc_html_e('credits', 'gpt3-ai-content-generator'); ?>
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="aipkit_purchase_history_footer">
                        <p class="aipkit_purchase_note">
                            <?php esc_html_e('Showing your most recent credit purchases. Orders must be completed to appear here.', 'gpt3-ai-content-generator'); ?>
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php
    return ob_get_clean();
}
