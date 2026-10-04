<?php

namespace WPAICG\Usage;

use WPAICG\Stats\AIPKit_Stats;
use WPAICG\Core\TokenManager\AIPKit_Token_Manager;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/log-actions.php';

/** Manages Usage pricing rules and ledger summaries. */
class AIPKit_Pricing_Ajax_Handler extends AIPKit_Usage_Ajax_Handler
{
    public function ajax_get_stats_pricing_management()
    {
        $post_data = $this->get_stats_post_data();
        if ($post_data === null) {
            return;
        }
        $days = $this->resolve_stats_days($post_data['days'] ?? 30);

        $token_manager = class_exists(AIPKit_Token_Manager::class) ? new AIPKit_Token_Manager() : null;
        $price_resolver = $token_manager ? $token_manager->get_price_resolver() : null;
        if (!$price_resolver) {
            $this->send_wp_error(new WP_Error('missing_price_resolver', __('Pricing service is unavailable.', 'gpt3-ai-content-generator')));
            return;
        }

        $rules = $price_resolver->list_rules(['scope_type' => 'module']);
        $ledger_summary = [];
        $recent_activity = [];

        if (class_exists(AIPKit_Stats::class)) {
            $stats = new AIPKit_Stats();
            $ledger_summary = $stats->get_ledger_summary($days);
            $recent_activity = $stats->get_recent_ledger_activity($days, 12);
            if (is_wp_error($ledger_summary)) {
                $this->send_wp_error($ledger_summary);
                return;
            }
            if (is_wp_error($recent_activity)) {
                $this->send_wp_error($recent_activity);
                return;
            }
        }

        wp_send_json_success([
            'pricing_rules' => $rules,
            'ledger_summary' => $ledger_summary,
            'recent_activity' => $recent_activity,
        ]);
    }

    public function ajax_save_stats_pricing_rule()
    {
        $post_data = $this->get_stats_post_data();
        if ($post_data === null) {
            return;
        }
        $rule_id = isset($post_data['id']) ? absint($post_data['id']) : 0;
        $module = isset($post_data['module']) ? sanitize_key($post_data['module']) : '';
        $provider = isset($post_data['provider']) ? sanitize_text_field($post_data['provider']) : '';
        $model = isset($post_data['model']) ? sanitize_text_field($post_data['model']) : '';
        $operation = isset($post_data['operation']) ? sanitize_key($post_data['operation']) : '';
        $billing_method = isset($post_data['billing_method']) ? sanitize_key($post_data['billing_method']) : '';
        $enabled = isset($post_data['enabled']) ? ($post_data['enabled'] === '1' || $post_data['enabled'] === 1) : true;

        if ($operation === 'live_voice' && (strcasecmp($provider, 'OpenAI') !== 0 || $model !== 'gpt-live-1'
            || !isset($post_data['unit_rate']) || !is_numeric($post_data['unit_rate'])
            || !is_finite((float) $post_data['unit_rate']) || (float) $post_data['unit_rate'] < 0)) {
            $this->send_wp_error(new WP_Error('invalid_live_pricing', __('Choose GPT Live and enter a non-negative credit rate per minute.', 'gpt3-ai-content-generator')));
            return;
        }

        $allowed_modules = $this->get_allowed_pricing_modules();
        if (!in_array($module, $allowed_modules, true)) {
            $this->send_wp_error(new WP_Error('invalid_pricing_module', __('Invalid pricing module.', 'gpt3-ai-content-generator')));
            return;
        }

        $allowed_operations = $this->get_allowed_pricing_operations($module);
        if (!in_array($operation, $allowed_operations, true)) {
            $this->send_wp_error(new WP_Error('invalid_pricing_operation', __('Invalid pricing operation.', 'gpt3-ai-content-generator')));
            return;
        }

        $allowed_billing_methods = $this->get_allowed_billing_methods($operation);
        if (!in_array($billing_method, $allowed_billing_methods, true)) {
            $this->send_wp_error(new WP_Error('invalid_billing_method', __('Invalid billing method.', 'gpt3-ai-content-generator')));
            return;
        }

        $token_manager = class_exists(AIPKit_Token_Manager::class) ? new AIPKit_Token_Manager() : null;
        $price_resolver = $token_manager ? $token_manager->get_price_resolver() : null;
        if (!$price_resolver) {
            $this->send_wp_error(new WP_Error('missing_price_resolver', __('Pricing service is unavailable.', 'gpt3-ai-content-generator')));
            return;
        }

        $result = $price_resolver->save_rule([
            'id' => $rule_id,
            'scope_type' => 'module',
            'scope_id' => null,
            'module' => $module,
            'provider' => $provider,
            'model' => $model,
            'operation' => $operation,
            'billing_method' => $billing_method,
            'input_rate' => $post_data['input_rate'] ?? null,
            'output_rate' => $post_data['output_rate'] ?? null,
            'unit_rate' => $post_data['unit_rate'] ?? null,
            'enabled' => $enabled ? 1 : 0,
        ]);

        if (is_wp_error($result)) {
            $this->send_wp_error($result);
            return;
        }

        wp_send_json_success([
            'message' => $rule_id > 0
                ? __('Pricing rule updated.', 'gpt3-ai-content-generator')
                : __('Pricing rule created.', 'gpt3-ai-content-generator'),
            'rule_id' => (int) $result,
        ]);
    }

    public function ajax_delete_stats_pricing_rule()
    {
        $post_data = $this->get_stats_post_data();
        if ($post_data === null) {
            return;
        }
        $rule_id = isset($post_data['id']) ? absint($post_data['id']) : 0;
        if ($rule_id <= 0) {
            $this->send_wp_error(new WP_Error('missing_pricing_rule_id', __('Pricing rule ID is required.', 'gpt3-ai-content-generator')));
            return;
        }

        $token_manager = class_exists(AIPKit_Token_Manager::class) ? new AIPKit_Token_Manager() : null;
        $price_resolver = $token_manager ? $token_manager->get_price_resolver() : null;
        if (!$price_resolver) {
            $this->send_wp_error(new WP_Error('missing_price_resolver', __('Pricing service is unavailable.', 'gpt3-ai-content-generator')));
            return;
        }

        $deleted = $price_resolver->delete_rule($rule_id);
        if (!$deleted) {
            $this->send_wp_error(new WP_Error('delete_pricing_rule_failed', __('Failed to delete pricing rule.', 'gpt3-ai-content-generator')));
            return;
        }

        wp_send_json_success([
            'message' => __('Pricing rule deleted.', 'gpt3-ai-content-generator'),
            'rule_id' => $rule_id,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function get_allowed_pricing_modules(): array
    {
        return ['chat', 'ai_forms', 'image_generator'];
    }

    /**
     * @return array<int, string>
     */
    private function get_allowed_pricing_operations(string $module): array
    {
        switch ($module) {
            case 'chat':
                return ['chat', 'live_voice'];
            case 'ai_forms':
                return ['form_submit'];
            case 'image_generator':
                return ['generate', 'edit', 'video_generate'];
            default:
                return [];
        }
    }

    /**
     * @return array<int, string>
     */
    private function get_allowed_billing_methods(string $operation): array
    {
        switch ($operation) {
            case 'live_voice':
                return ['per_minute'];
            case 'chat':
            case 'form_submit':
                return ['per_1k_tokens', 'flat'];
            case 'generate':
            case 'edit':
                return ['per_image', 'flat'];
            case 'video_generate':
                return ['per_video', 'flat'];
            default:
                return ['flat'];
        }
    }
}
