<?php

namespace WPAICG\ContentWriter\SEO;

use WPAICG\aipkit_dashboard;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalizes and gates Content Writer SEO improvement settings.
 */
class AIPKit_Content_Writer_SEO_Config
{
    public const KEY_ENABLED = 'seo_score_improvement_enabled';
    public const KEY_CONTINUE = 'seo_score_continue_until_target';
    public const KEY_TARGET = 'seo_score_target';
    public const KEY_MAX_PASSES = 'seo_score_max_passes';
    public const KEY_PROFILE = 'seo_score_profile';
    public const KEY_DISABLED_RULES = 'seo_score_disabled_rules';

    public static function is_pro_plan(): bool
    {
        return class_exists(aipkit_dashboard::class) && aipkit_dashboard::is_pro_plan();
    }

    public static function normalize(array $config, bool $enforce_plan = true, bool $add_defaults = true): array
    {
        $has_seo_config = self::has_any_seo_config($config);
        if (!$add_defaults && !$has_seo_config) {
            return $config;
        }

        if ($add_defaults || array_key_exists(self::KEY_ENABLED, $config)) {
            $config[self::KEY_ENABLED] = self::normalize_binary($config[self::KEY_ENABLED] ?? '0', '0');
        }

        if ($add_defaults || $has_seo_config) {
            $config[self::KEY_CONTINUE] = '1';
            $config[self::KEY_TARGET] = '100';
            $config[self::KEY_MAX_PASSES] = '3';
            $config[self::KEY_PROFILE] = 'auto';
            $disabled_rules = array_key_exists(self::KEY_DISABLED_RULES, $config)
                ? $config[self::KEY_DISABLED_RULES]
                : self::default_disabled_rule_ids();
            $config[self::KEY_DISABLED_RULES] = self::sanitize_disabled_rules($disabled_rules);
        }

        if ($enforce_plan && !self::is_pro_plan() && ($add_defaults || array_key_exists(self::KEY_ENABLED, $config))) {
            $config[self::KEY_ENABLED] = '0';
        }

        return $config;
    }

    /**
     * @return bool|\WP_Error
     */
    public static function require_pro_for_improvement(array $config)
    {
        if (!self::is_enabled($config) || self::is_pro_plan()) {
            return true;
        }

        return new WP_Error(
            'pro_feature_required',
            __('Continuous SEO score improvement is a Pro feature. Please upgrade.', 'gpt3-ai-content-generator'),
            ['status' => 403]
        );
    }

    public static function is_enabled(array $config): bool
    {
        return self::normalize_binary($config[self::KEY_ENABLED] ?? '0', '0') === '1';
    }

    /**
     * @param mixed $value
     */
    public static function sanitize_disabled_rules($value): string
    {
        $disabled = self::sanitize_disabled_rule_ids($value);
        $encoded = wp_json_encode($disabled);

        return is_string($encoded) ? $encoded : '[]';
    }

    /**
     * @return string[]
     */
    public static function default_disabled_rule_ids(): array
    {
        if (self::ensure_rules_class_loaded()) {
            $rules_class = self::smart_seo_rules_class();
            return $rules_class::default_disabled_rule_ids();
        }

        return [];
    }

    public static function default_disabled_rules(): string
    {
        return self::sanitize_disabled_rules(self::default_disabled_rule_ids());
    }

    /**
     * @return string[]
     */
    public static function disabled_rule_ids(array $config_or_context): array
    {
        $raw = $config_or_context['disabled_rule_ids']
            ?? $config_or_context[self::KEY_DISABLED_RULES]
            ?? self::default_disabled_rule_ids();

        return self::sanitize_disabled_rule_ids($raw);
    }

    public static function is_rule_enabled(array $config_or_context, string $rule_id): bool
    {
        $rule_id = sanitize_key($rule_id);
        if ($rule_id === '') {
            return true;
        }

        return !in_array($rule_id, self::disabled_rule_ids($config_or_context), true);
    }

    private static function smart_seo_rules_class(): string
    {
        return '\\WPAICG\\ContentWriter\\SEO\\AIPKit_Content_Writer_Smart_SEO_Rules';
    }

    private static function ensure_rules_class_loaded(): bool
    {
        $rules_class = self::smart_seo_rules_class();
        if (class_exists($rules_class)) {
            return true;
        }

        if (defined('WPAICG_LIB_DIR')) {
            $rules_path = WPAICG_LIB_DIR . 'content-writer/seo-rules.php';
            if (file_exists($rules_path)) {
                require_once $rules_path;
            }
        }

        return class_exists($rules_class);
    }

    /**
     * @return string[]
     */
    private static function known_rule_ids(): array
    {
        if (self::ensure_rules_class_loaded()) {
            $rules_class = self::smart_seo_rules_class();
            if (method_exists($rules_class, 'allowed_rule_ids')) {
                return $rules_class::allowed_rule_ids();
            }
        }

        return [];
    }

    private static function has_any_seo_config(array $config): bool
    {
        foreach ([self::KEY_ENABLED, self::KEY_CONTINUE, self::KEY_TARGET, self::KEY_MAX_PASSES, self::KEY_PROFILE, self::KEY_DISABLED_RULES] as $key) {
            if (array_key_exists($key, $config)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     */
    private static function normalize_binary($value, string $default): string
    {
        if ($value === '1' || $value === 1 || $value === true) {
            return '1';
        }

        if ($value === '0' || $value === 0 || $value === false) {
            return '0';
        }

        return $default;
    }

    /**
     * @return string[]
     * @param mixed $value
     */
    private static function sanitize_disabled_rule_ids($value): array
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                $value = [];
            } else {
                $decoded = json_decode($trimmed, true);
                if (!is_array($decoded)) {
                    $decoded = json_decode(stripslashes($trimmed), true);
                }
                $value = is_array($decoded) ? $decoded : explode(',', $trimmed);
            }
        }

        if (!is_array($value)) {
            return [];
        }

        $allowed = array_fill_keys(self::known_rule_ids(), true);

        $disabled = [];
        foreach ($value as $key => $raw_rule_id) {
            $rule_id = is_string($key) && !is_numeric($key) && !empty($raw_rule_id)
                ? $key
                : $raw_rule_id;
            if (!is_scalar($rule_id)) {
                continue;
            }

            $rule_id = sanitize_key((string) $rule_id);
            if ($rule_id === '' || (!empty($allowed) && empty($allowed[$rule_id]))) {
                continue;
            }

            $disabled[$rule_id] = true;
        }

        return array_keys($disabled);
    }
}
