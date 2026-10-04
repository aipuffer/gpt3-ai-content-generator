<?php

namespace WPAICG\Core\TokenManager\Limits;

use WPAICG\Chat\Storage\BotSettingsManager;

if (!defined('ABSPATH')) {
    exit;
}

/** Shared token-limit notice and action settings for module settings adapters. */
trait AIPKit_Token_Limit_Settings_Trait
{
    private static function normalize_token_management_settings(array $settings): array
    {
        $default_limit_message = BotSettingsManager::get_default_token_limit_message();
        $default_action_settings = BotSettingsManager::get_default_token_limit_action_settings();
        $valid_action_types = BotSettingsManager::get_token_limit_action_types();

        $settings['token_limit_message'] = isset($settings['token_limit_message'])
            ? sanitize_text_field((string) $settings['token_limit_message'])
            : $default_limit_message;
        if ($settings['token_limit_message'] === '') {
            $settings['token_limit_message'] = $default_limit_message;
        }

        foreach (['primary', 'secondary'] as $slot) {
            $type_key = "token_limit_{$slot}_action_type";
            $label_key = "token_limit_{$slot}_action_label";
            $url_key = "token_limit_{$slot}_action_url";
            $default_type = (string) ($default_action_settings["{$slot}_type"] ?? 'none');
            $default_label = (string) ($default_action_settings["{$slot}_label"] ?? '');
            $default_url = (string) ($default_action_settings["{$slot}_url"] ?? '');

            $action_type = isset($settings[$type_key]) ? sanitize_key((string) $settings[$type_key]) : $default_type;
            if (!in_array($action_type, $valid_action_types, true)) {
                $action_type = $default_type;
            }

            $action_label = isset($settings[$label_key]) ? sanitize_text_field((string) $settings[$label_key]) : $default_label;
            if ($action_type === 'none') {
                $action_label = '';
            } elseif ($action_label === '') {
                $action_label = BotSettingsManager::get_token_limit_action_default_label($action_type);
            }

            $action_url = isset($settings[$url_key]) ? esc_url_raw(trim((string) $settings[$url_key])) : $default_url;

            $settings[$type_key] = $action_type;
            $settings[$label_key] = $action_label;
            $settings[$url_key] = $action_url;
        }

        return $settings;
    }
}

class AIPKit_Quota_Service
{
    /**
     * @return array<string, int|bool|null>
     */
    public function evaluate(?int $limit, int $current_usage, int $required_units = 0): array
    {
        $current_usage = max(0, $current_usage);
        $required_units = max(0, $required_units);

        if ($limit === null) {
            return [
                'has_limit' => false,
                'allowed' => true,
                'limit' => null,
                'current_usage' => $current_usage,
                'required_units' => $required_units,
                'projected_usage' => $current_usage + $required_units,
                'remaining' => null,
            ];
        }

        $limit = max(0, $limit);
        $projected_usage = $current_usage + $required_units;

        return [
            'has_limit' => true,
            'allowed' => $projected_usage <= $limit,
            'limit' => $limit,
            'current_usage' => $current_usage,
            'required_units' => $required_units,
            'projected_usage' => $projected_usage,
            'remaining' => max(0, $limit - $current_usage),
        ];
    }

    public function can_consume(?int $limit, int $current_usage, int $required_units = 0): bool
    {
        $evaluation = $this->evaluate($limit, $current_usage, $required_units);

        return !empty($evaluation['allowed']);
    }
}
