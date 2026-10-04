<?php

namespace WPAICG\ContentWriter;

if (!defined('ABSPATH')) {
    exit;
}

class AIPKit_Content_Writer_Image_Provider_Options
{
    private const OPTION_DEFINITIONS = [
        'openai' => [
            'canvas_size' => [
                'aliases' => ['openai_canvas_size'],
                'default' => '1024x1024',
                'allowed' => ['1024x1024', '1536x1024', '1024x1536', 'auto'],
            ],
            'quality' => [
                'aliases' => ['openai_quality'],
                'default' => '',
                'allowed' => ['', 'auto', 'low', 'medium', 'high'],
            ],
            'output_format' => [
                'aliases' => ['openai_output_format'],
                'default' => '',
                'allowed' => ['', 'png', 'jpeg', 'webp'],
            ],
            'output_compression' => [
                'aliases' => ['openai_output_compression'],
                'default' => '',
                'type' => 'int_optional',
                'min' => 0,
                'max' => 100,
            ],
            'background' => [
                'aliases' => ['openai_background'],
                'default' => '',
                'allowed' => ['', 'auto', 'opaque', 'transparent'],
            ],
            'moderation' => [
                'aliases' => ['openai_moderation'],
                'default' => '',
                'allowed' => ['', 'auto', 'low'],
            ],
        ],
        'azure' => [
            'canvas_size' => [
                'aliases' => ['azure_canvas_size'],
                'default' => '',
                'allowed' => ['', '1024x1024', '1536x1024', '1024x1536', 'auto'],
            ],
            'quality' => [
                'aliases' => ['azure_quality'],
                'default' => '',
                'allowed' => ['', 'low', 'medium', 'high'],
            ],
            'output_format' => [
                'aliases' => ['azure_output_format'],
                'default' => '',
                'allowed' => ['', 'png', 'jpeg'],
            ],
            'output_compression' => [
                'aliases' => ['azure_output_compression'],
                'default' => '',
                'type' => 'int_optional',
                'min' => 0,
                'max' => 100,
            ],
            'background' => [
                'aliases' => ['azure_background'],
                'default' => '',
                'allowed' => ['', 'auto', 'transparent'],
            ],
        ],
        'google' => [
            'aspect_ratio' => [
                'aliases' => ['google_aspect_ratio'],
                'default' => '',
                'type' => 'text_choice',
                'allowed' => ['', '1:1', '1:4', '1:8', '2:3', '3:2', '3:4', '4:1', '4:3', '4:5', '5:4', '8:1', '9:16', '16:9', '21:9'],
            ],
            'image_size' => [
                'aliases' => ['google_image_size'],
                'default' => '',
                'allowed' => ['', '512', '1k', '2k', '4k'],
            ],
        ],
        'openrouter' => [
            'aspect_ratio' => [
                'aliases' => ['openrouter_aspect_ratio'],
                'default' => '',
                'type' => 'text_choice',
                'allowed' => ['', 'auto', '1:1', '1:2', '2:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '9:19.5', '19.5:9', '9:20', '20:9', '9:21', '21:9', '1:4', '4:1', '1:8', '8:1'],
            ],
            'image_size' => [
                'aliases' => ['openrouter_image_size'],
                'default' => '',
                'type' => 'text_choice',
                'allowed' => ['', '512', '1k', '2k', '4k', '0.5k'],
            ],
            'quality' => [
                'aliases' => ['openrouter_quality'],
                'default' => '',
                'allowed' => ['', 'auto', 'low', 'medium', 'high'],
            ],
            'output_format' => [
                'aliases' => ['openrouter_output_format'],
                'default' => '',
                'allowed' => ['', 'png', 'jpeg', 'webp'],
            ],
            'output_compression' => [
                'aliases' => ['openrouter_output_compression'],
                'default' => '',
                'type' => 'int_optional',
                'min' => 0,
                'max' => 100,
            ],
            'background' => [
                'aliases' => ['openrouter_background'],
                'default' => '',
                'allowed' => ['', 'auto', 'transparent', 'opaque'],
            ],
        ],
        'xai' => [
            'aspect_ratio' => [
                'aliases' => ['xai_aspect_ratio'],
                'default' => '',
                'type' => 'text_choice',
                'allowed' => ['', 'auto', '1:1', '16:9', '9:16', '4:3', '3:4', '3:2', '2:3', '2:1', '1:2', '19.5:9', '9:19.5', '20:9', '9:20'],
            ],
            'resolution' => [
                'aliases' => ['xai_resolution'],
                'default' => '',
                'allowed' => ['', '1k', '2k'],
            ],
        ],
        'replicate' => [
            'aspect_ratio' => [
                'aliases' => ['replicate_aspect_ratio'],
                'default' => '',
                'type' => 'text_optional',
                'max_length' => 50,
            ],
            'width' => [
                'aliases' => ['replicate_width'],
                'default' => '',
                'type' => 'int_optional',
                'min' => 64,
                'max' => 4096,
            ],
            'height' => [
                'aliases' => ['replicate_height'],
                'default' => '',
                'type' => 'int_optional',
                'min' => 64,
                'max' => 4096,
            ],
            'negative_prompt' => [
                'aliases' => ['replicate_negative_prompt'],
                'default' => '',
                'type' => 'text_optional',
                'max_length' => 1000,
            ],
            'guidance' => [
                'aliases' => ['replicate_guidance'],
                'default' => '',
                'type' => 'float_optional',
                'min' => 0,
                'max' => 30,
                'precision' => 2,
            ],
            'num_inference_steps' => [
                'aliases' => ['replicate_num_inference_steps'],
                'default' => '',
                'type' => 'int_optional',
                'min' => 1,
                'max' => 100,
            ],
            'seed' => [
                'aliases' => ['replicate_seed'],
                'default' => '',
                'type' => 'int_optional',
                'min' => 0,
                'max' => 2147483647,
            ],
            'output_format' => [
                'aliases' => ['replicate_output_format'],
                'default' => '',
                'type' => 'text_optional',
                'max_length' => 20,
            ],
            'output_quality' => [
                'aliases' => ['replicate_output_quality'],
                'default' => '',
                'type' => 'int_optional',
                'min' => 0,
                'max' => 100,
            ],
        ],
    ];

    private const ALLOWED_PROVIDER_KEYS = [
        'openai',
        'openrouter',
        'google',
        'azure',
        'xai',
        'replicate',
    ];

    public static function normalize_provider_key(string $provider): string
    {
        $provider = sanitize_key($provider);
        $aliases = [
            'open_ai' => 'openai',
            'open-router' => 'openrouter',
            'open_router' => 'openrouter',
            'x_ai' => 'xai',
        ];
        $provider = $aliases[$provider] ?? $provider;

        return in_array($provider, self::ALLOWED_PROVIDER_KEYS, true) ? $provider : 'openai';
    }

    public static function normalize(array $settings): array
    {
        $decoded_options = self::decode_options($settings['image_provider_options'] ?? []);
        $normalized = [];

        foreach (self::OPTION_DEFINITIONS as $provider => $fields) {
            $provider_options = [];
            if (isset($decoded_options[$provider]) && is_array($decoded_options[$provider])) {
                $provider_options = $decoded_options[$provider];
            }

            foreach ($fields as $field => $definition) {
                $value = null;
                foreach ($definition['aliases'] as $alias) {
                    if (array_key_exists($alias, $settings)) {
                        $value = $settings[$alias];
                        break;
                    }
                }
                if ($value === null && array_key_exists($field, $provider_options)) {
                    $value = $provider_options[$field];
                }
                if ($value === null) {
                    $value = $definition['default'];
                }

                $normalized[$provider][$field] = self::sanitize_option_value($value, $definition);
            }
        }

        return $normalized;
    }

    public static function sanitize_options_json($raw_options, array $settings = []): string
    {
        $settings['image_provider_options'] = $raw_options;
        $normalized = self::normalize($settings);
        $encoded = wp_json_encode($normalized);

        return is_string($encoded) ? $encoded : '{}';
    }

    public static function get_provider_options(array $settings, string $provider): array
    {
        $provider = self::normalize_provider_key($provider);
        $normalized = self::normalize($settings);

        return isset($normalized[$provider]) && is_array($normalized[$provider])
            ? $normalized[$provider]
            : [];
    }

    public static function get_hash_value(array $settings): string
    {
        return self::sanitize_options_json($settings['image_provider_options'] ?? '', $settings);
    }

    private static function decode_options($raw_options): array
    {
        if (is_array($raw_options)) {
            return $raw_options;
        }

        if (!is_string($raw_options)) {
            return [];
        }

        $raw_options = trim(wp_unslash($raw_options));
        if ($raw_options === '') {
            return [];
        }

        $decoded = json_decode($raw_options, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function sanitize_option_value($value, array $definition): string
    {
        $type = $definition['type'] ?? '';

        if ($type === 'int_optional') {
            if ($value === null || $value === '') {
                return (string) ($definition['default'] ?? '');
            }
            $value = is_scalar($value) ? (string) wp_unslash($value) : '';
            if ($value === '') {
                return (string) ($definition['default'] ?? '');
            }
            $number = absint($value);
            $min = isset($definition['min']) ? (int) $definition['min'] : 0;
            $max = isset($definition['max']) ? (int) $definition['max'] : PHP_INT_MAX;

            return (string) max($min, min($number, $max));
        }

        if ($type === 'float_optional') {
            if ($value === null || $value === '') {
                return (string) ($definition['default'] ?? '');
            }
            $value = is_scalar($value) ? (string) wp_unslash($value) : '';
            if ($value === '' || !is_numeric($value)) {
                return (string) ($definition['default'] ?? '');
            }

            $number = (float) $value;
            $min = isset($definition['min']) ? (float) $definition['min'] : 0.0;
            $max = isset($definition['max']) ? (float) $definition['max'] : PHP_FLOAT_MAX;
            $precision = isset($definition['precision']) ? max(0, (int) $definition['precision']) : 4;
            $number = max($min, min($number, $max));
            $formatted = rtrim(rtrim(sprintf('%.' . $precision . 'F', $number), '0'), '.');

            return $formatted === '' ? '0' : $formatted;
        }

        if ($type === 'text_optional') {
            $value = is_scalar($value) ? sanitize_text_field((string) wp_unslash($value)) : '';
            if ($value === '') {
                return (string) ($definition['default'] ?? '');
            }
            $max_length = isset($definition['max_length']) ? max(1, (int) $definition['max_length']) : 1000;

            return function_exists('mb_substr') ? mb_substr($value, 0, $max_length) : (string) substr($value, 0, $max_length);
        }

        $value = is_scalar($value) ? (string) wp_unslash($value) : '';
        $value = $type === 'text_choice'
            ? sanitize_text_field($value)
            : sanitize_key($value);
        $allowed = $definition['allowed'] ?? [];

        if (in_array($value, $allowed, true)) {
            return $value;
        }

        return (string) ($definition['default'] ?? '');
    }
}
