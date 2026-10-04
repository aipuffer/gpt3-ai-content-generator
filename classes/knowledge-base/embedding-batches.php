<?php

namespace WPAICG\Vector;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves safe embedding batch sizes for vector ingestion.
 */
class AIPKit_Vector_Embedding_Batch_Policy
{
    private const OPTION_NAME = 'aipkit_training_general_settings';
    private const MIN_BATCH_SIZE = 1;

    /**
     * @return array<string,array{key:string,label:string,default:int,max:int}>
     */
    public static function provider_configs(): array
    {
        return [
            'google' => [
                'key' => 'embedding_batch_size_google',
                'label' => __('Google', 'gpt3-ai-content-generator'),
                'default' => 100,
                'max' => 100,
            ],
            'openai' => [
                'key' => 'embedding_batch_size_openai',
                'label' => __('OpenAI', 'gpt3-ai-content-generator'),
                'default' => 50,
                'max' => 100,
            ],
            'openrouter' => [
                'key' => 'embedding_batch_size_openrouter',
                'label' => __('OpenRouter', 'gpt3-ai-content-generator'),
                'default' => 50,
                'max' => 100,
            ],
            'azure' => [
                'key' => 'embedding_batch_size_azure',
                'label' => __('Azure', 'gpt3-ai-content-generator'),
                'default' => 50,
                'max' => 100,
            ],
            'ollama' => [
                'key' => 'embedding_batch_size_ollama',
                'label' => __('Ollama', 'gpt3-ai-content-generator'),
                'default' => 1,
                'max' => 100,
            ],
        ];
    }

    public static function option_name(): string
    {
        return self::OPTION_NAME;
    }

    public static function min_batch_size(): int
    {
        return self::MIN_BATCH_SIZE;
    }

    public static function supports_provider(string $provider): bool
    {
        $configs = self::provider_configs();
        return isset($configs[self::normalize_provider($provider)]);
    }

    public static function get_provider_default_batch_size(string $provider): int
    {
        $provider = self::normalize_provider($provider);
        $configs = self::provider_configs();
        return (int) ($configs[$provider]['default'] ?? self::MIN_BATCH_SIZE);
    }

    public static function get_provider_max_batch_size(string $provider): int
    {
        $provider = self::normalize_provider($provider);
        $configs = self::provider_configs();
        return (int) ($configs[$provider]['max'] ?? self::MIN_BATCH_SIZE);
    }

    /**
     * Returns the configured batch size for a Pro override, or the safe default.
     */
    public static function get_provider_batch_size(string $provider, bool $allow_custom = true): int
    {
        $provider = self::normalize_provider($provider);
        $configs = self::provider_configs();
        if (!isset($configs[$provider])) {
            return self::MIN_BATCH_SIZE;
        }

        $config = $configs[$provider];
        $value = (int) $config['default'];

        if ($allow_custom && self::load_custom_settings()) {
            $value = \WPAICG\Lib\VectorStores\FileUpload\AIPKit_Vector_File_Ingestion_Batch_Settings::saved_batch_size($config);
        }

        return self::clamp($value, (int) $config['max']);
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function resolve_batch_size(
        string $provider,
        array $context = [],
        ?int $fallback = null
    ): int {
        $provider = self::normalize_provider($provider);
        $allow_custom = array_key_exists('allow_custom', $context)
            ? (bool) $context['allow_custom']
            : self::can_use_custom_settings();
        $default = self::supports_provider($provider)
            ? self::get_provider_batch_size($provider, $allow_custom)
            : (int) ($fallback ?? self::MIN_BATCH_SIZE);
        $max = self::supports_provider($provider)
            ? self::get_provider_max_batch_size($provider)
            : max(self::MIN_BATCH_SIZE, (int) ($fallback ?? self::MIN_BATCH_SIZE));

        $batch_size = (int) apply_filters(
            'aipkit_vector_embedding_batch_size',
            $default,
            $provider,
            $context
        );

        return self::clamp($batch_size, $max);
    }

    /**
     * @param array<string,mixed> $settings
     */
    public static function has_payload_keys(array $settings): bool
    {
        foreach (self::provider_configs() as $config) {
            if (array_key_exists($config['key'], $settings)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    public static function remove_payload_keys(array $settings): array
    {
        foreach (self::provider_configs() as $config) {
            unset($settings[$config['key']]);
        }
        return $settings;
    }

    /**
     * @param array<string,mixed> $general_settings
     * @param array<string,mixed> $settings
     * @return bool|\WP_Error
     */
    public static function apply_payload_to_general_settings(array &$general_settings, array $settings, bool $is_pro)
    {
        if (!$is_pro || !self::has_payload_keys($settings) || !self::load_custom_settings()) {
            return false;
        }
        return \WPAICG\Lib\VectorStores\FileUpload\AIPKit_Vector_File_Ingestion_Batch_Settings::apply_payload_to_general_settings($general_settings, $settings, $is_pro);
    }

    private static function load_custom_settings(): bool
    {
        $settings_class = '\\WPAICG\\Lib\\VectorStores\\FileUpload\\AIPKit_Vector_File_Ingestion_Batch_Settings';
        if (!class_exists($settings_class)) {
            $settings_path = WPAICG_PLUGIN_DIR . 'lib/knowledge-base/ingestion-settings.php';
            if (file_exists($settings_path)) {
                require_once $settings_path;
            }
        }
        return class_exists($settings_class);
    }

    private static function normalize_provider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        $provider = str_replace([' ', '-', '_embedding'], ['', '', ''], $provider);
        switch ($provider) {
            case 'openai':
            case 'openaiembedding':
                return 'openai';
            case 'openrouter':
            case 'openrouterembedding':
                return 'openrouter';
            case 'google':
            case 'googleembedding':
                return 'google';
            case 'azure':
            case 'azureembedding':
                return 'azure';
            case 'ollama':
            case 'ollamaembedding':
                return 'ollama';
            default:
                return function_exists('sanitize_key') ? sanitize_key($provider) : preg_replace('/[^a-z0-9_\\-]/', '', $provider);
        }
    }

    private static function can_use_custom_settings(): bool
    {
        return class_exists('\\WPAICG\\aipkit_dashboard')
            && \WPAICG\aipkit_dashboard::is_pro_plan();
    }

    private static function clamp(int $value, int $max): int
    {
        return max(self::MIN_BATCH_SIZE, min(max(self::MIN_BATCH_SIZE, $max), $value));
    }
}
