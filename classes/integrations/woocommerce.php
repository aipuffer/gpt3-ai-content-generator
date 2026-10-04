<?php


namespace WPAICG\WooCommerce;

use WPAICG\Core\TokenManager\AIPKit_Token_Manager;
use WPAICG\Core\TokenManager\Constants\MetaKeysConstants;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * AIPKit_WooCommerce_Integration
 *
 * Handles all integration points with WooCommerce for selling token packages.
 * This version uses a simple meta box instead of a custom product type.
 */
class AIPKit_WooCommerce_Integration
{
    private const ORDER_TOKENS_GRANTED_META_KEY = '_aipkit_tokens_granted';
    private const PREVIEW_TEXT_INPUT_UNITS = 1000;
    private const PREVIEW_TEXT_OUTPUT_UNITS = 1000;

    private static $instance = null;

    /**
     * Ensures only one instance of the class is loaded.
     * @return AIPKit_WooCommerce_Integration
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor to register hooks.
     */
    private function __construct()
    {
        // Add meta box to product page for token settings
        add_action('add_meta_boxes_product', [$this, 'add_token_package_meta_box']);

        // Save meta box data when a product is saved
        add_action('woocommerce_process_product_meta', [$this, 'save_token_package_meta_box_data']);

        // Hook into order completion to grant tokens to the user
        add_action('woocommerce_order_status_completed', [$this, 'grant_tokens_on_order_completion'], 10, 1);
    }

    /** Adds the visitor credit package controls to the product editor. */
    public function add_token_package_meta_box($post)
    {
        add_meta_box(
            'aipkit_token_package_meta_box',                // Meta box ID
            __('AI Puffer: Visitor Credits', 'gpt3-ai-content-generator'), // Title
            [$this, 'render_token_package_meta_box'],       // Callback function
            'product',                                      // Post type
            'side',                                         // Context (side, normal, advanced)
            'default'                                       // Priority
        );
    }

    private function add_admin_error(string $message): void
    {
        if (class_exists('\WC_Admin_Meta_Boxes') && method_exists('\WC_Admin_Meta_Boxes', 'add_error')) {
            \WC_Admin_Meta_Boxes::add_error($message);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function get_credit_preview_items(): array
    {
        $token_manager = class_exists(AIPKit_Token_Manager::class) ? new AIPKit_Token_Manager() : null;
        $price_resolver = $token_manager ? $token_manager->get_price_resolver() : null;
        $usage_normalizer = $token_manager ? $token_manager->get_usage_normalizer() : null;
        $charge_calculator = $token_manager ? $token_manager->get_charge_calculator() : null;

        if (!$price_resolver || !$usage_normalizer || !$charge_calculator) {
            return [];
        }

        $rules = $price_resolver->list_rules([
            'scope_type' => 'module',
            'enabled' => 1,
        ]);

        if (empty($rules)) {
            return [];
        }

        $slots = [
            ['module' => 'chat', 'operation' => 'chat'],
            ['module' => 'chat', 'operation' => 'live_voice'],
            ['module' => 'ai_forms', 'operation' => 'form_submit'],
            ['module' => 'image_generator', 'operation' => 'generate'],
            ['module' => 'image_generator', 'operation' => 'video_generate'],
        ];

        $preview_items = [];
        foreach ($slots as $slot) {
            foreach ($rules as $rule) {
                if (
                    sanitize_key((string) ($rule['module'] ?? '')) !== $slot['module']
                    || sanitize_key((string) ($rule['operation'] ?? '')) !== $slot['operation']
                ) {
                    continue;
                }

                $preview_item = $this->build_credit_preview_item(
                    $rule,
                    $usage_normalizer,
                    $charge_calculator
                );
                if ($preview_item !== null) {
                    $preview_items[] = $preview_item;
                }
                break;
            }
        }

        return $preview_items;
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<string, mixed>|null
     */
    private function build_credit_preview_item(
        array $rule,
        $usage_normalizer,
        $charge_calculator
    ): ?array {
        $operation = sanitize_key((string) ($rule['operation'] ?? ''));
        $provider = sanitize_text_field((string) ($rule['provider'] ?? ''));
        $model = sanitize_text_field((string) ($rule['model'] ?? ''));
        if ($operation === '' || $provider === '' || $model === '') {
            return null;
        }

        $usage_context = [];
        $fallback_units = 0;
        $unit_singular = __('requests', 'gpt3-ai-content-generator');
        $unit_plural = __('requests', 'gpt3-ai-content-generator');
        $assumption = '';
        $label_suffix = '';

        switch ($operation) {
            case 'live_voice':
                $usage_context = ['usage_data' => ['duration_seconds' => 60]];
                $unit_singular = __('voice minute', 'gpt3-ai-content-generator');
                $unit_plural = __('voice minutes', 'gpt3-ai-content-generator');
                $label_suffix = __('voice', 'gpt3-ai-content-generator');
                $assumption = __('Voice time only; reasoning and tools are billed separately. Each session has a 15-second minimum and rounds up to whole credits.', 'gpt3-ai-content-generator');
                break;

            case 'chat':
                $fallback_units = self::PREVIEW_TEXT_INPUT_UNITS + self::PREVIEW_TEXT_OUTPUT_UNITS;
                $usage_context = [
                    'usage_data' => [
                        'input_tokens' => self::PREVIEW_TEXT_INPUT_UNITS,
                        'output_tokens' => self::PREVIEW_TEXT_OUTPUT_UNITS,
                        'total_tokens' => $fallback_units,
                    ],
                ];
                $label_suffix = __('chat', 'gpt3-ai-content-generator');
                $assumption = __('Assumes about 1K input + 1K output tokens per request.', 'gpt3-ai-content-generator');
                break;

            case 'form_submit':
                $fallback_units = self::PREVIEW_TEXT_INPUT_UNITS + self::PREVIEW_TEXT_OUTPUT_UNITS;
                $usage_context = [
                    'usage_data' => [
                        'input_tokens' => self::PREVIEW_TEXT_INPUT_UNITS,
                        'output_tokens' => self::PREVIEW_TEXT_OUTPUT_UNITS,
                        'total_tokens' => $fallback_units,
                    ],
                ];
                $label_suffix = __('AI Form submit', 'gpt3-ai-content-generator');
                $assumption = __('Assumes about 1K input + 1K output tokens per submit.', 'gpt3-ai-content-generator');
                break;

            case 'generate':
                $fallback_units = 1;
                $usage_context = [
                    'usage_data' => [
                        'unit_count' => 1,
                        'image_count' => 1,
                        'total_units' => 1,
                    ],
                ];
                $unit_singular = __('image', 'gpt3-ai-content-generator');
                $unit_plural = __('images', 'gpt3-ai-content-generator');
                $label_suffix = __('image generation', 'gpt3-ai-content-generator');
                break;

            case 'video_generate':
                $fallback_units = 1;
                $usage_context = [
                    'usage_data' => [
                        'unit_count' => 1,
                        'video_count' => 1,
                        'total_units' => 1,
                    ],
                ];
                $unit_singular = __('video', 'gpt3-ai-content-generator');
                $unit_plural = __('videos', 'gpt3-ai-content-generator');
                $label_suffix = __('video generation', 'gpt3-ai-content-generator');
                break;

            default:
                return null;
        }

        $normalized_usage = $usage_normalizer->normalize($usage_context, $fallback_units);
        $charge = $charge_calculator->calculate($rule, $normalized_usage, $fallback_units);
        $credits_per_unit = $operation === 'live_voice'
            ? max(0, (float) ($charge['raw_charge'] ?? 0))
            : max(0, (int) ($charge['required_units'] ?? 0));
        if ($credits_per_unit <= 0) {
            return null;
        }

        return [
            'label' => trim($provider . ' · ' . $model . ' ' . $label_suffix),
            'credits_per_unit' => $credits_per_unit,
            'unit_singular' => $unit_singular,
            'unit_plural' => $unit_plural,
            'assumption' => $assumption,
        ];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function format_credit_preview_count_text(int $credits_amount, array $item): string
    {
        $credits_per_unit = max(0, (float) ($item['credits_per_unit'] ?? 0));
        $unit_singular = (string) ($item['unit_singular'] ?? __('unit', 'gpt3-ai-content-generator'));
        $unit_plural = (string) ($item['unit_plural'] ?? __('units', 'gpt3-ai-content-generator'));

        if ($credits_amount <= 0) {
            return __('Enter a credit amount to preview package value.', 'gpt3-ai-content-generator');
        }

        if ($credits_per_unit <= 0) {
            return __('Preview unavailable for this pricing rule.', 'gpt3-ai-content-generator');
        }

        $count = (int) floor($credits_amount / $credits_per_unit);
        if ($count <= 0) {
            return sprintf(
                /* translators: %s: singular unit label such as "image". */
                __('Less than 1 %s at current pricing.', 'gpt3-ai-content-generator'),
                $unit_singular
            );
        }

        return sprintf(
            /* translators: 1: formatted count, 2: unit label such as "images". */
            __('About %1$s %2$s at current pricing.', 'gpt3-ai-content-generator'),
            number_format_i18n($count),
            $count === 1 ? $unit_singular : $unit_plural
        );
    }

    /**
     * Renders the HTML for the token package meta box.
     *
     * @param \WP_Post $post The post object.
     */
    public function render_token_package_meta_box($post)
    {
        wp_nonce_field('aipkit_save_token_package_meta', 'aipkit_token_package_nonce');

        $is_token_package = get_post_meta($post->ID, '_aipkit_is_token_package', true);
        $tokens_amount = get_post_meta($post->ID, '_aipkit_tokens_amount', true);
        $credits_amount = absint($tokens_amount);
        $preview_items = $this->get_credit_preview_items();
        $preview_assumptions = array_values(array_unique(array_filter(array_map(
            static function ($item) {
                return isset($item['assumption']) ? (string) $item['assumption'] : '';
            },
            $preview_items
        ))));

        echo '<p><label for="aipkit_is_token_package" style="display:block; margin-bottom: 6px; font-weight:600;">';
        echo '<input type="checkbox" id="aipkit_is_token_package" name="_aipkit_is_token_package" value="yes" ' . checked($is_token_package, 'yes', false) . ' />';
        echo ' ';
        esc_html_e('Sell this product as a visitor credit package', 'gpt3-ai-content-generator');
        echo '</label></p>';

        echo '<p style="margin:0 0 10px; color:#50575e;">';
        esc_html_e('Credits are added to the purchaser\'s balance on this WordPress site. They are separate from AI Puffer Cloud credits.', 'gpt3-ai-content-generator');
        echo '</p>';
        echo '<p style="margin:0 0 12px; padding:8px 10px; border:1px solid #f0d7a1; background:#fff8e5; border-radius:4px; color:#664d03;">';
        echo '<strong>' . esc_html__('Account required:', 'gpt3-ai-content-generator') . '</strong> ';
        esc_html_e('Orders must be linked to a WordPress user account. Guest checkout orders without a user account cannot receive credits automatically.', 'gpt3-ai-content-generator');
        echo '</p>';

        echo '<div id="aipkit_tokens_amount_wrapper" style="display:' . ($is_token_package === 'yes' ? 'block' : 'none') . ';">';
        echo '<p style="margin-bottom:8px;"><label for="aipkit_tokens_amount" style="display:block; font-weight:600; margin-bottom:4px;">' . esc_html__('Credits Granted Per Quantity:', 'gpt3-ai-content-generator') . '</label>';
        echo '<input type="number" id="aipkit_tokens_amount" name="_aipkit_tokens_amount" value="' . esc_attr($tokens_amount) . '" class="short" min="1" step="1" placeholder="e.g., 100000" style="width:100%;" ' . disabled($is_token_package !== 'yes', true, false) . ' />';
        echo '<span class="description" style="display:block; margin-top:6px;">' . esc_html__('Granted once per purchased quantity. Example: quantity 3 grants this amount three times.', 'gpt3-ai-content-generator') . '</span>';
        echo '</p>';
        echo '<p style="margin:0 0 12px; color:#1d2327;"><strong>' . esc_html__('Current package value:', 'gpt3-ai-content-generator') . '</strong> <span id="aipkit_credit_package_value">' . esc_html(number_format_i18n($credits_amount)) . ' ' . esc_html__('credits', 'gpt3-ai-content-generator') . '</span></p>';

        if (!empty($preview_items)) {
            echo '<div id="aipkit_credit_package_preview" style="margin:0 0 12px; padding:10px; border:1px solid #dcdcde; border-radius:4px; background:#fff;">';
            echo '<p style="margin:0 0 8px; font-weight:600;">' . esc_html__('What this package can roughly buy at current pricing', 'gpt3-ai-content-generator') . '</p>';
            echo '<ul style="margin:0; padding-left:18px; list-style:disc; list-style-position:outside;">';
            foreach ($preview_items as $index => $preview_item) {
                echo '<li style="margin:0 0 8px;">';
                echo '<strong>' . esc_html((string) $preview_item['label']) . '</strong><br />';
                echo '<span class="aipkit_credit_preview_value" data-preview-index="' . esc_attr((string) $index) . '">' . esc_html($this->format_credit_preview_count_text($credits_amount, $preview_item)) . '</span>';
                echo '</li>';
            }
            echo '</ul>';

            if (!empty($preview_assumptions)) {
                echo '<div style="margin-top:10px; color:#50575e;">';
                foreach ($preview_assumptions as $assumption) {
                    echo '<div class="description" style="margin-top:4px;">' . esc_html($assumption) . '</div>';
                }
                echo '</div>';
            }

            echo '<div class="description" style="margin-top:8px;">' . esc_html__('Preview uses your current module-level pricing rules. It is guidance for package sizing, not a billing guarantee.', 'gpt3-ai-content-generator') . '</div>';
            echo '<script type="application/json" id="aipkit_credit_package_preview_data">' . wp_json_encode($preview_items) . '</script>';
            echo '</div>';
        } else {
            echo '<p class="description" style="margin-top:0;">' . esc_html__('No pricing rules are configured yet. Define pricing in Usage to see package previews here.', 'gpt3-ai-content-generator') . '</p>';
        }
        echo '</div>';

        ?>
        <script>
        (function() {
            var checkbox = document.getElementById('aipkit_is_token_package');
            var amountField = document.getElementById('aipkit_tokens_amount');
            var wrapper = document.getElementById('aipkit_tokens_amount_wrapper');
            var packageValue = document.getElementById('aipkit_credit_package_value');
            var previewDataEl = document.getElementById('aipkit_credit_package_preview_data');
            var previewRows = document.querySelectorAll('.aipkit_credit_preview_value');
            if (!checkbox || !wrapper) {
                return;
            }

            var previewItems = [];
            if (previewDataEl) {
                try {
                    previewItems = JSON.parse(previewDataEl.textContent || '[]');
                } catch (error) {
                    previewItems = [];
                }
            }

            var formatNumber = function(value) {
                try {
                    return new Intl.NumberFormat().format(value);
                } catch (error) {
                    return String(value);
                }
            };

            var updateVisibility = function() {
                var checked = !!checkbox.checked;
                wrapper.style.display = checked ? 'block' : 'none';
                if (amountField) {
                    amountField.disabled = !checked;
                    amountField.required = checked;
                }
            };

            var updatePreview = function() {
                var creditsAmount = amountField ? Math.max(0, parseInt(amountField.value || '0', 10) || 0) : 0;
                if (packageValue) {
                    packageValue.textContent = formatNumber(creditsAmount) + ' <?php echo esc_js(__('credits', 'gpt3-ai-content-generator')); ?>';
                }
                if (!previewItems.length || !previewRows.length) {
                    return;
                }

                previewRows.forEach(function(row) {
                    var index = parseInt(row.getAttribute('data-preview-index') || '-1', 10);
                    var item = index >= 0 ? previewItems[index] : null;
                    if (!item) {
                        return;
                    }

                    var creditsPerUnit = Math.max(0, parseFloat(item.credits_per_unit || 0) || 0);
                    var singular = item.unit_singular || '<?php echo esc_js(__('unit', 'gpt3-ai-content-generator')); ?>';
                    var plural = item.unit_plural || '<?php echo esc_js(__('units', 'gpt3-ai-content-generator')); ?>';

                    if (creditsAmount <= 0) {
                        row.textContent = '<?php echo esc_js(__('Enter a credit amount to preview package value.', 'gpt3-ai-content-generator')); ?>';
                        return;
                    }

                    if (creditsPerUnit <= 0) {
                        row.textContent = '<?php echo esc_js(__('Preview unavailable for this pricing rule.', 'gpt3-ai-content-generator')); ?>';
                        return;
                    }

                    var count = Math.floor(creditsAmount / creditsPerUnit);
                    if (count <= 0) {
                        row.textContent = '<?php echo esc_js(__('Less than 1', 'gpt3-ai-content-generator')); ?> ' + singular + ' <?php echo esc_js(__('at current pricing.', 'gpt3-ai-content-generator')); ?>';
                        return;
                    }

                    row.textContent = '<?php echo esc_js(__('About', 'gpt3-ai-content-generator')); ?> ' + formatNumber(count) + ' ' + (count === 1 ? singular : plural) + ' <?php echo esc_js(__('at current pricing.', 'gpt3-ai-content-generator')); ?>';
                });
            };

            checkbox.addEventListener('change', updateVisibility);
            if (amountField) {
                amountField.addEventListener('input', updatePreview);
                amountField.addEventListener('change', updatePreview);
            }

            updateVisibility();
            updatePreview();
        }());
        </script>
        <?php
    }

    /**
     * Saves the token package meta box data.
     *
     * @param int $post_id The ID of the product being saved.
     */
    public function save_token_package_meta_box_data($post_id)
    {
        // --- FIX: Unslash and sanitize POST data before use ---
        $post_data = wp_unslash($_POST);
        if (!isset($post_data['aipkit_token_package_nonce']) || !wp_verify_nonce(sanitize_key($post_data['aipkit_token_package_nonce']), 'aipkit_save_token_package_meta')) {
            return;
        }
        // --- END FIX ---

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $is_token_package = isset($post_data['_aipkit_is_token_package']) ? 'yes' : 'no';
        update_post_meta($post_id, '_aipkit_is_token_package', $is_token_package);

        if ($is_token_package === 'yes' && isset($post_data['_aipkit_tokens_amount'])) {
            $tokens_amount = absint($post_data['_aipkit_tokens_amount']);
            if ($tokens_amount > 0) {
                update_post_meta($post_id, '_aipkit_tokens_amount', $tokens_amount);
            } else {
                delete_post_meta($post_id, '_aipkit_tokens_amount');
                $this->add_admin_error(__('Visitor credit packages must grant at least 1 credit.', 'gpt3-ai-content-generator'));
            }
        } else {
            // Delete the tokens amount if it's no longer a token package
            delete_post_meta($post_id, '_aipkit_tokens_amount');
        }
    }

    /**
     * Grant tokens to a user when their WooCommerce order is marked as "completed".
     *
     * @param int $order_id The ID of the completed order.
     */
    public function grant_tokens_on_order_completion(int $order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        if ((int) $order->get_meta(self::ORDER_TOKENS_GRANTED_META_KEY, true) > 0) {
            return;
        }

        $user_id = $order->get_user_id();
        if (!$user_id) {
            return;
        }

        $total_tokens_to_grant = 0;
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $is_token_package = get_post_meta($product_id, '_aipkit_is_token_package', true);

            if ($is_token_package === 'yes') {
                $tokens_granted = (int) get_post_meta($product_id, '_aipkit_tokens_amount', true);
                $quantity = $item->get_quantity();
                if ($tokens_granted > 0) {
                    $total_tokens_to_grant += ($tokens_granted * $quantity);
                }
            }
        }

        if ($total_tokens_to_grant > 0) {
            $token_manager = class_exists(AIPKit_Token_Manager::class) ? new AIPKit_Token_Manager() : null;
            $ledger_repository = $token_manager ? $token_manager->get_ledger_repository() : null;
            $balance_service = $token_manager ? $token_manager->get_balance_service() : null;
            $idempotency_key = 'woocommerce_order_' . $order_id . '_purchase';

            if ($ledger_repository && method_exists($ledger_repository, 'find_by_idempotency_key')) {
                $existing_entry = $ledger_repository->find_by_idempotency_key($idempotency_key);
                if (is_array($existing_entry) && !empty($existing_entry['id'])) {
                    $order->update_meta_data(self::ORDER_TOKENS_GRANTED_META_KEY, $total_tokens_to_grant);
                    $order->save();
                    return;
                }
            }

            $current_balance = $balance_service
                ? $balance_service->get_current_balance($user_id)
                : (int) get_user_meta($user_id, MetaKeysConstants::TOKEN_BALANCE_META_KEY, true);
            $new_balance = $current_balance + $total_tokens_to_grant;

            if ($balance_service) {
                $balance_service->set_current_balance($user_id, $new_balance);
            } else {
                update_user_meta($user_id, MetaKeysConstants::TOKEN_BALANCE_META_KEY, $new_balance);
            }

            $order->update_meta_data(self::ORDER_TOKENS_GRANTED_META_KEY, $total_tokens_to_grant);
            $order->save();

            if ($ledger_repository && method_exists($ledger_repository, 'insert_entry')) {
                $ledger_repository->insert_entry([
                    'user_id' => $user_id,
                    'module' => 'woocommerce',
                    'context_type' => 'order',
                    'context_id' => $order_id,
                    'operation' => 'purchase',
                    'usage_total_units' => $total_tokens_to_grant,
                    'credits_delta' => $total_tokens_to_grant,
                    'entry_type' => 'purchase',
                    'reference_type' => 'woocommerce_order',
                    'reference_id' => (string) $order_id,
                    'idempotency_key' => $idempotency_key,
                    'meta' => [
                        'tokens_granted' => $total_tokens_to_grant,
                        'balance_before' => $current_balance,
                        'balance_after' => $new_balance,
                    ],
                ]);
            }

            $order->add_order_note(
                /* translators: 1: The number of tokens granted, 2: The user's new token balance. */
                sprintf(__('AI Power: Granted %1$s tokens to user. New balance: %2$s', 'gpt3-ai-content-generator'),
                    number_format_i18n($total_tokens_to_grant),
                    number_format_i18n($new_balance)
                )
            );
        }
    }
}
