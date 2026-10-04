<?php

namespace WPAICG;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reusable builder for provider and model select option lists.
 *
 * Model families, recommendations, and ordering come from the canonical model
 * registry so every module receives the same option structure.
 */
class AIPKit_Provider_Model_List_Builder
{
    /** Render Settings model options from the shared catalog. */
    public static function render_model_options(string $provider, string $current_model): void
    {
        $payload = self::get_model_options($provider, $current_model);

        foreach ((array) ($payload['groups'] ?? []) as $group) {
            if (!is_array($group) || empty($group['options'])) {
                continue;
            }
            $group_label = (string) ($group['label'] ?? '');
            if ($group_label !== '') {
                echo '<optgroup label="' . esc_attr($group_label) . '" data-family-key="' . esc_attr((string) ($group['key'] ?? 'other')) . '">';
            }
            foreach ((array) $group['options'] as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $value = (string) ($option['value'] ?? '');
                if ($value === '') {
                    continue;
                }
                echo '<option value="' . esc_attr($value) . '"'
                    . ' data-recommended="' . (!empty($option['recommended']) ? 'true' : 'false') . '"'
                    . ' data-family-key="' . esc_attr((string) ($option['family_key'] ?? $group['key'] ?? 'other')) . '"'
                    . ' data-family-label="' . esc_attr((string) ($option['family_label'] ?? $group_label)) . '"'
                    . ' data-family-order="' . esc_attr((string) ($option['family_order'] ?? $group['order'] ?? 999)) . '"'
                    . ' data-family-collapsed="' . (!empty($option['family_collapsed']) ? 'true' : 'false') . '" '
                    . selected(!empty($option['selected']), true, false) . '>'
                    . esc_html((string) ($option['label'] ?? $value)) . '</option>';
            }
            if ($group_label !== '') {
                echo '</optgroup>';
            }
        }

        $manual_option = is_array($payload['manual_option'] ?? null) ? $payload['manual_option'] : null;
        if ($manual_option && (string) ($manual_option['value'] ?? '') !== '') {
            $manual_value = (string) $manual_option['value'];
            echo '<option value="' . esc_attr($manual_value) . '" data-family-key="other" data-family-label="'
                . esc_attr((string) ($manual_option['family_label'] ?? __('Other', 'gpt3-ai-content-generator')))
                . '" data-family-order="999" data-family-collapsed="true" selected>'
                . esc_html((string) ($manual_option['label'] ?? $manual_value)) . '</option>';
        }

        if (empty($payload['has_selectable_options']) && !$manual_option) {
            echo '<option value="">' . esc_html((string) ($payload['empty_option_label'] ?? __('Sync to load models', 'gpt3-ai-content-generator'))) . '</option>';
        }
    }

    /**
     * @param array<int, string> $providers Ordered provider keys.
     * @return array<int, array<string, mixed>>
     */
    public static function get_provider_options(array $providers, bool $is_pro): array
    {
        $options = [];
        foreach ($providers as $provider_key) {
            $provider_key = (string) $provider_key;
            if ($provider_key === '') {
                continue;
            }
            $disabled = ($provider_key === 'Ollama' && !$is_pro);
            $options[] = [
                'value' => $provider_key,
                'label' => $disabled
                    ? __('Ollama (Pro)', 'gpt3-ai-content-generator')
                    : AIPKit_Providers::get_provider_display_name($provider_key),
                'disabled' => $disabled,
            ];
        }
        return $options;
    }

    /**
     * Build provider-family model options from the universal registry.
     *
     * @return array<string, mixed>
     */
    public static function get_model_options(string $provider_key, string $current_model = ''): array
    {
        $provider_key = trim($provider_key);
        $current_model = trim($current_model);
        $payload = [
            'groups' => [],
            'manual_option' => null,
            'has_selectable_options' => false,
            'empty_option_label' => __('(Sync to load models)', 'gpt3-ai-content-generator'),
        ];
        $catalog_key = self::get_primary_catalog_key($provider_key);
        if ($catalog_key === '') {
            return $payload;
        }

        $records = AIPKit_Providers::query_models([
            'catalog_keys' => [$catalog_key],
            'required_capabilities' => ['text_generation'],
        ]);
        $families = [];
        $found_current = false;
        foreach ($records as $record) {
            $option = self::normalize_record($provider_key, $record);
            if (!$option) {
                continue;
            }
            $option['selected'] = self::is_selected($provider_key, $current_model, $option['value']);
            $found_current = $found_current || $option['selected'];
            $family_key = (string) $option['family_key'];
            if (!isset($families[$family_key])) {
                $families[$family_key] = [
                    'key' => $family_key,
                    'label' => (string) $option['family_label'],
                    'order' => (int) $option['family_order'],
                    'collapsed' => !empty($option['family_collapsed']),
                    'options' => [],
                ];
            }
            $families[$family_key]['options'][] = $option;
        }

        uasort($families, static function (array $left, array $right): int {
            return ((int) $left['order'] <=> (int) $right['order'])
                ?: strcasecmp((string) $left['label'], (string) $right['label']);
        });
        foreach ($families as &$family) {
            usort($family['options'], static function (array $left, array $right): int {
                return ((int) !empty($right['recommended']) <=> (int) !empty($left['recommended']))
                    ?: strnatcasecmp((string) $left['label'], (string) $right['label']);
            });
        }
        unset($family);

        $payload['groups'] = array_values($families);
        $payload['has_selectable_options'] = !empty($families);
        if (!$found_current && $current_model !== '') {
            $payload['manual_option'] = [
                'value' => $current_model,
                'label' => $provider_key === 'Google'
                    ? self::normalize_google_model_id($current_model)
                    : $current_model,
                'recommended' => false,
                'family_key' => 'other',
                'family_label' => __('Other', 'gpt3-ai-content-generator'),
                'family_order' => 999,
                'family_collapsed' => true,
            ];
        }

        return $payload;
    }

    private static function get_primary_catalog_key(string $provider_key): string
    {
        $catalogs = [
            'AIPufferCloud' => 'AIPufferCloud',
            'OpenAI' => 'OpenAI',
            'OpenRouter' => 'OpenRouter',
            'Google' => 'Google',
            'Claude' => 'Claude',
            'Azure' => 'Azure',
            'DeepSeek' => 'DeepSeek',
            'xAI' => 'xAI',
            'Ollama' => 'Ollama',
        ];
        return $catalogs[$provider_key] ?? '';
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>|null
     */
    private static function normalize_record(string $provider_key, array $record): ?array
    {
        $value = (string) ($record['raw_id'] ?? $record['id'] ?? '');
        if ($provider_key === 'Google') {
            $value = self::normalize_google_model_id($value);
        }
        if ($value === '') {
            return null;
        }

        $name = (string) ($record['name'] ?? $value);
        $label = $name;
        if ($provider_key === 'Azure' && $name !== '' && $name !== $value) {
            $label = $value . ' (model: ' . $name . ')';
        }

        return [
            'value' => $value,
            'label' => $label,
            'recommended' => !empty($record['recommended']),
            'family_key' => sanitize_key((string) ($record['family_key'] ?? 'other')),
            'family_label' => sanitize_text_field((string) ($record['family_label'] ?? __('Other', 'gpt3-ai-content-generator'))),
            'family_order' => (int) ($record['family_order'] ?? 999),
            'family_collapsed' => !empty($record['family_collapsed']),
        ];
    }

    private static function is_selected(string $provider_key, string $current_model, string $value): bool
    {
        if ($current_model === '' || $value === '') {
            return false;
        }
        return $provider_key === 'Google'
            ? self::normalize_google_model_id($current_model) === self::normalize_google_model_id($value)
            : $current_model === $value;
    }

    private static function normalize_google_model_id(string $model_id): string
    {
        $model_id = trim($model_id);
        return strpos($model_id, 'models/') === 0
            ? (string) substr($model_id, 7)
            : $model_id;
    }
}
