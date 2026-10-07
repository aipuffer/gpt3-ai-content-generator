<?php


namespace WPAICG;

use WPAICG\Core\Models\AIPKit_Model_Registry;
use WPAICG\Core\Models\AIPKit_Model_Catalog;
use WPAICG\Core\Providers\Google\Interactions\GoogleModelCapabilityClassifier;
use WPAICG\Core\Providers\OpenAI\OpenAIApiMode;
use WPAICG\Vector\AIPKit_Vector_Store_Registry;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists(OpenAIApiMode::class)) {
    require_once WPAICG_PLUGIN_DIR . 'classes/ai/providers/openai.php';
}

/**
 * AIPKit_Providers
 */
class AIPKit_Providers
{
    private static $provider_defaults = [
        'AIPufferCloud' => ['model' => ''],
        'OpenAI' => [
            'api_key' => '', 'model' => '', 'embedding_model' => '',
            // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Configurable provider endpoint.
            'base_url' => 'https://api.openai.com', 'api_version' => 'v1',
            'api_mode' => OpenAIApiMode::RESPONSES,
            'store_conversation' => '0',
            'expiration_policy' => 7, // NEW: Default expiration policy in days
        ],
        'OpenRouter' => [
            'api_key' => '', 'model' => '',
            // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Configurable provider endpoint.
            'base_url' => 'https://openrouter.ai/api', 'api_version' => 'v1',
            'allow_fallbacks' => '1',
            'require_parameters' => '0',
            'data_collection' => 'allow',
            'zdr' => '0',
            'fallback_model_1' => '',
            'fallback_model_2' => '',
            'fallback_model_3' => '',
        ],
        'Google' => [
            'api_key' => '', 'model' => '', 'embedding_model' => '',
            // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Configurable provider endpoint.
            'base_url' => 'https://generativelanguage.googleapis.com', 'api_version' => 'v1beta',
            'store_conversation' => '0',
        ],
        'Azure' => [
            'api_key' => '', 'model' => '', 'endpoint' => '', 'embeddings' => '',
            'api_version_authoring' => '2023-03-15-preview', 'api_version_inference' => '2025-01-01-preview',
            'api_version_images' => '2025-04-01-preview'
        ],
        'Claude' => [
            'api_key' => '', 'model' => '',
            // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Configurable provider endpoint.
            'base_url' => 'https://api.anthropic.com', 'api_version' => '2023-06-01',
        ],
        'DeepSeek' => [
            'api_key' => '', 'model' => '',
            // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Configurable provider endpoint.
            'base_url' => 'https://api.deepseek.com', 'api_version' => 'v1',
        ],
        'xAI' => [
            'api_key' => '', 'model' => '',
            // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Configurable provider endpoint.
            'base_url' => 'https://api.x.ai', 'api_version' => 'v1',
        ],
        'Ollama' => [
            'model' => '',
            'base_url' => 'http://localhost:11434',
        ],
        'ElevenLabs' => [
            'api_key' => '', 'voice_id' => '', 'model_id' => '',
            'base_url' => 'https://api.elevenlabs.io', 'api_version' => 'v1',
        ],
        'Pexels' => [
            'api_key' => '',
        ],
        'Pixabay' => [
            'api_key' => '',
        ],
        'Pinecone' => [
            'api_key' => '',
        ],
        'Qdrant' => [ // Ensure API key is part of defaults as it's now mandatory for cloud
            'api_key' => '', 'url' => '', 'default_collection' => '',
        ],
        'Chroma' => [
            'api_key' => '',
            'url' => '',
            'tenant' => 'default_tenant',
            'database' => 'default_database',
            'default_collection' => '',
        ],
        'Replicate' => [
            'api_key' => '',
        ],
    ];

    private static $provider_capabilities = [
        'Google' => [
            'text_generation' => true,
            'streaming' => true,
            'image_input' => true,
            'web_search' => true,
            'embeddings' => true,
            'vector_stores' => false,
            'hosted_knowledge' => true,
            'file_search' => true,
            'image_generation' => true,
            'image_editing' => true,
            'video_generation' => true,
            'tts' => true,
            'stt' => true,
            'realtime' => false,
            'legacy_completions' => false,
            'chat_completions' => false,
        ],
        'xAI' => [
            'text_generation' => true,
            'streaming' => true,
            'image_input' => true,
            'web_search' => true,
            'embeddings' => false,
            'vector_stores' => false,
            'image_generation' => true,
            'image_editing' => true,
            'video_generation' => false,
            'tts' => false,
            'stt' => false,
            'realtime' => false,
            'legacy_completions' => false,
            'chat_completions' => false,
        ],
    ];

    public static function normalize_provider_label(string $provider): string
    {
        $provider = sanitize_text_field(trim($provider));
        if ($provider === '') {
            return '';
        }

        foreach (array_keys(self::$provider_defaults) as $known_provider) {
            if (strtolower($known_provider) === strtolower($provider)) {
                return $known_provider;
            }
        }

        return $provider;
    }

    public static function normalize_openai_api_mode($mode): string
    {
        return OpenAIApiMode::normalize($mode);
    }

    public static function get_provider_display_name(string $provider): string
    {
        $provider = sanitize_text_field(trim($provider));
        if ($provider === '') {
            return '';
        }

        if ($provider === 'AIPufferCloud') { return __('AI Puffer Cloud', 'gpt3-ai-content-generator'); }
        $provider_lower = strtolower($provider);
        if ($provider_lower === 'claude') {
            return __('Anthropic', 'gpt3-ai-content-generator');
        }
        if ($provider_lower === 'claude_files') {
            return __('Anthropic Files', 'gpt3-ai-content-generator');
        }
        if ($provider_lower === 'google_file_search') {
            return __('Google', 'gpt3-ai-content-generator');
        }

        return self::normalize_provider_label($provider);
    }

    /** The selected model determines whether image input is available. */
    public static function model_supports_image_input(string $provider, string $model): bool
    {
        $provider = self::normalize_provider_label($provider);
        if ($model === '') { return false; }
        if ($provider === 'AIPufferCloud') {
            if (!class_exists('\\WPAICG\\Cloud\\Connection')) { return false; }
            foreach (\WPAICG\Cloud\Connection::models() as $entry) {
                if ($entry['id'] === $model) { return !empty($entry['capabilities']['image_input']); }
            }
            return false;
        }
        if ($provider === 'xAI') { return self::xai_model_supports_image_input($model); }
        if (class_exists('\\WPAICG\\Core\\Models\\AIPKit_Model_Registry')) {
            foreach (\WPAICG\Core\Models\AIPKit_Model_Registry::get_catalog_records($provider) as $entry) {
                if (($entry['id'] ?? '') === $model || ($entry['raw_id'] ?? '') === $model) {
                    foreach (['image_input', 'vision'] as $key) {
                        if (array_key_exists($key, $entry['capabilities'] ?? [])) { return (bool) $entry['capabilities'][$key]; }
                    }
                }
            }
        }
        // These providers already expose image input; explicit model metadata above takes precedence.
        return in_array($provider, ['OpenAI', 'Google', 'Claude'], true);
    }

    public static function get_provider_capabilities(string $provider): array
    {
        $normalized_provider = self::normalize_provider_label($provider);
        $default_capabilities = self::$provider_capabilities[$normalized_provider] ?? [];
        if ($normalized_provider === 'AIPufferCloud') {
            $default_capabilities = [
                'text_generation' => true, 'streaming' => true, 'chat_completions' => true,
                'embeddings' => class_exists('\\WPAICG\\Cloud\\Connection') && (bool) \WPAICG\Cloud\Connection::embedding_models(), 'vector_stores' => false, 'hosted_knowledge' => false,
                'file_search' => false, 'image_input' => class_exists('\\WPAICG\\Cloud\\Connection') && (bool) array_filter(\WPAICG\Cloud\Connection::models(), static function ($m) { return !empty($m['capabilities']['image_input']); }), 'web_search' => false,
                'image_generation' => class_exists('\\WPAICG\\Cloud\\Connection') && (bool) \WPAICG\Cloud\Connection::media_models('image_generate'),
                'image_editing' => class_exists('\\WPAICG\\Cloud\\Connection') && (bool) \WPAICG\Cloud\Connection::media_models('image_edit'),
                'video_generation' => false,
                'tts' => class_exists('\\WPAICG\\Cloud\\Connection') && (bool) \WPAICG\Cloud\Connection::media_models('speech_generate'),
                'stt' => class_exists('\\WPAICG\\Cloud\\Connection') && (bool) \WPAICG\Cloud\Connection::media_models('transcribe'),
                'realtime' => false, 'legacy_completions' => false,
            ];
        }
        $filtered_capabilities = apply_filters(
            'aipkit_provider_capabilities',
            $default_capabilities,
            $normalized_provider
        );

        if (!is_array($filtered_capabilities)) {
            $filtered_capabilities = $default_capabilities;
        } else {
            $filtered_capabilities = array_merge($default_capabilities, $filtered_capabilities);
        }

        $normalized_capabilities = [];
        foreach ($filtered_capabilities as $capability => $supported) {
            if (!is_string($capability)) {
                continue;
            }
            $capability_key = sanitize_key($capability);
            if ($capability_key === '') {
                continue;
            }
            $normalized_capabilities[$capability_key] = (bool) $supported;
        }

        return $normalized_capabilities;
    }

    public static function provider_supports_capability(
        string $provider,
        string $capability,
        bool $default_for_unlisted = true
    ): bool {
        $capability_key = sanitize_key($capability);
        if ($capability_key === '') {
            return false;
        }

        $capabilities = self::get_provider_capabilities($provider);
        if (array_key_exists($capability_key, $capabilities)) {
            return (bool) $capabilities[$capability_key];
        }

        if (self::normalize_provider_label($provider) === 'AIPufferCloud') {
            return false;
        }
        return $default_for_unlisted;
    }

    /**
     * Resolve main provider allowlist for provider-selection flows.
     *
     * Defaults intentionally exclude addon providers. Addons can extend this
     * list using `aipkit_main_provider_allowlist`.
     *
     * @return array<int, string>
     */
    /**
     * Text-generation providers offered in module selectors (Content Writer, Automations, AI Forms,
     * Content Assistant, REST). Admin pickers may include disconnected Cloud for setup.
     *
     * @param bool $include_ollama Whether the (Pro) local Ollama provider belongs in this list.
     * @return array<int, string>
     */
    public static function get_text_generation_providers(bool $include_ollama = true, bool $include_cloud = true, bool $include_disconnected_cloud = false): array
    {
        $providers = ['OpenAI', 'Google', 'Claude', 'OpenRouter', 'Azure', 'Ollama', 'DeepSeek', 'xAI'];
        if (!$include_ollama) {
            $providers = array_values(array_diff($providers, ['Ollama']));
        }
        if ($include_cloud && ($include_disconnected_cloud || (class_exists('\\WPAICG\\Cloud\\Connection') && \WPAICG\Cloud\Connection::generation_ready()))) {
            array_unshift($providers, 'AIPufferCloud');
        }
        return $providers;
    }

    public static function get_main_provider_allowlist(bool $include_disconnected_cloud = false): array
    {
        $default_allowlist = ['OpenAI', 'Google', 'Claude', 'OpenRouter', 'Azure', 'DeepSeek', 'xAI'];
        if ($include_disconnected_cloud || (class_exists('\\WPAICG\\Cloud\\Connection') && \WPAICG\Cloud\Connection::generation_ready())) { array_unshift($default_allowlist, 'AIPufferCloud'); }
        $filtered_allowlist = apply_filters('aipkit_main_provider_allowlist', $default_allowlist);
        if (!is_array($filtered_allowlist)) {
            $filtered_allowlist = $default_allowlist;
        }

        $valid_provider_keys = array_keys(self::$provider_defaults);
        $blocked_provider_keys = ['ElevenLabs', 'Pexels', 'Pixabay', 'Pinecone', 'Qdrant', 'Chroma', 'Replicate'];
        $normalized = [];

        foreach ($filtered_allowlist as $provider) {
            $provider = sanitize_text_field((string) $provider);
            if (
                $provider === ''
                || !in_array($provider, $valid_provider_keys, true)
                || in_array($provider, $blocked_provider_keys, true)
            ) {
                continue;
            }

            $normalized[$provider] = true;
        }

        if (empty($normalized)) {
            foreach ($default_allowlist as $provider) {
                $normalized[$provider] = true;
            }
        }

        return array_keys($normalized);
    }

    public static function get_default_model_id(string $provider_key): string
    {
        return AIPKit_Model_Registry::get_default_model_id($provider_key);
    }

    private static function get_hydrated_provider_defaults_all(): array
    {
        $defaults = self::$provider_defaults;
        $provider_model_fields = [
            'OpenAI' => ['model' => 'OpenAI', 'embedding_model' => 'OpenAIEmbedding'],
            'OpenRouter' => ['model' => 'OpenRouter'],
            'Google' => ['model' => 'Google', 'embedding_model' => 'GoogleEmbedding'],
            'Azure' => ['model' => 'Azure', 'embeddings' => 'AzureEmbedding'],
            'Claude' => ['model' => 'Claude'],
            'DeepSeek' => ['model' => 'DeepSeek'],
            'xAI' => ['model' => 'xAI'],
            'Ollama' => ['model' => 'Ollama'],
            'ElevenLabs' => ['model_id' => 'ElevenLabsModels'],
        ];

        foreach ($provider_model_fields as $provider_name => $field_map) {
            if (!isset($defaults[$provider_name]) || !is_array($defaults[$provider_name])) {
                continue;
            }

            foreach ($field_map as $field_name => $catalog_key) {
                $defaults[$provider_name][$field_name] = self::get_default_model_id($catalog_key);
            }
        }

        return $defaults;
    }

    /**
     * Normalize a provider to a valid main-provider value.
     *
     * @param string $provider Raw provider value.
     * @param string $fallback Fallback provider when input is invalid.
     * @return string
     */
    public static function normalize_main_provider(string $provider, string $fallback = 'OpenAI'): string
    {
        $provider = sanitize_text_field(trim($provider));
        // A disconnected Cloud account must keep its saved provider choice. Replacing it with
        // OpenAI here would silently route later requests through an unrelated API key.
        if ($provider === 'AIPufferCloud') {
            return $provider;
        }
        $allowed_providers = self::get_main_provider_allowlist();

        if (in_array($provider, $allowed_providers, true)) {
            return $provider;
        }

        if (!in_array($fallback, $allowed_providers, true)) {
            $fallback = $allowed_providers[0] ?? 'OpenAI';
        }

        return $fallback;
    }

    /**
     * Returns the default embedding provider map used across vector flows.
     *
     * @return array<string, string>
     */
    public static function get_default_embedding_provider_map(): array
    {
        $map = [
            'openai' => 'OpenAI',
            'google' => 'Google',
            'azure' => 'Azure',
            'openrouter' => 'OpenRouter',
        ];
        if (class_exists('\\WPAICG\\Cloud\\Connection') && \WPAICG\Cloud\Connection::embedding_models()) {
            return ['aipuffercloud' => 'AIPufferCloud'] + $map;
        }
        return $map;
    }

    /**
     * Returns normalized embedding provider map, including filter extensions.
     *
     * @param string $context
     * @return array<string, string>
     */
    public static function get_embedding_provider_map(string $context = ''): array
    {
        $default_map = self::get_default_embedding_provider_map();
        $provider_map = apply_filters('aipkit_embedding_provider_map', $default_map, $context);
        if (!is_array($provider_map)) {
            $provider_map = $default_map;
        }

        $normalized_map = [];
        foreach ($provider_map as $provider_key => $provider_label) {
            if (!is_string($provider_key) || !is_string($provider_label)) {
                continue;
            }
            $provider_key = sanitize_key($provider_key);
            if ($provider_key === '') {
                continue;
            }
            $provider_label = sanitize_text_field($provider_label);
            if (!self::provider_supports_capability($provider_label, 'embeddings')) {
                continue;
            }
            $normalized_map[$provider_key] = $provider_label;
        }

        return empty($normalized_map) ? $default_map : $normalized_map;
    }

    /**
     * Returns normalized embedding provider keys for a given context.
     *
     * @param string $context
     * @return array<int, string>
     */
    public static function get_embedding_provider_keys(string $context = ''): array
    {
        $provider_map = self::get_embedding_provider_map($context);
        $provider_keys = array_values(array_unique(array_map('sanitize_key', array_keys($provider_map))));
        if (empty($provider_keys)) {
            $provider_keys = array_keys(self::get_default_embedding_provider_map());
        }
        return $provider_keys;
    }

    /**
     * Resolves provider key (e.g. "openai") to provider name (e.g. "OpenAI").
     *
     * Returns null when key is not present in the normalized embedding provider map.
     *
     * @param string $provider_key
     * @param string $context
     * @return string|null
     */
    public static function resolve_embedding_provider_name(string $provider_key, string $context = ''): ?string
    {
        $provider_lookup = sanitize_key((string) strtolower($provider_key));
        if ($provider_lookup === '') {
            return null;
        }

        $provider_map = self::get_embedding_provider_map($context);
        if (!isset($provider_map[$provider_lookup]) || !is_string($provider_map[$provider_lookup])) {
            return null;
        }

        $provider_name = sanitize_text_field($provider_map[$provider_lookup]);
        return $provider_name !== '' ? $provider_name : null;
    }

    /**
     * Normalizes provider key (e.g. "openai") to provider name (e.g. "OpenAI").
     *
     * @param string $provider_key
     * @param string $context
     * @return string
     */
    public static function normalize_embedding_provider_name(string $provider_key, string $context = ''): string
    {
        $provider_lookup = sanitize_key((string) strtolower($provider_key));
        $resolved_name = self::resolve_embedding_provider_name($provider_lookup, $context);
        return $resolved_name !== null ? $resolved_name : ucfirst($provider_lookup);
    }

    /** Known output-size capabilities. Unknown models retain their provider defaults. */
    public static function embedding_dimension_policy(string $provider, string $model): ?array
    {
        $provider = strtolower($provider);
        $identity = $model;
        if ($provider === 'aipuffercloud') {
            foreach (\WPAICG\Cloud\Connection::embedding_models() as $row) {
                if ($row['id'] === $model) { return ['parameter' => 'dimensions', 'default' => $row['dimensions'], 'sizes' => $row['supportedDimensions'] ?? [$row['dimensions']]]; }
            }
            return null;
        }
        if ($provider === 'azure') {
            $identity = '';
            foreach (self::get_azure_embedding_models() as $row) {
                if (($row['id'] ?? '') === $model) { $identity = (string) ($row['model'] ?? ''); break; }
            }
        }
        if ($provider === 'openrouter') {
            if (strpos($model, 'openai/') !== 0) { return null; }
            $identity = substr($model, strlen('openai/'));
        }
        if (in_array($provider, ['openai', 'azure', 'openrouter'], true)) {
            if ($identity === 'text-embedding-3-small' || $identity === 'text-embedding-3-large') {
                $maximum = $identity === 'text-embedding-3-small' ? 1536 : 3072;
                return ['parameter' => 'dimensions', 'default' => $maximum, 'min' => 1, 'max' => $maximum];
            }
            if ($identity === 'text-embedding-ada-002') { return ['parameter' => null, 'default' => 1536, 'sizes' => [1536]]; }
        }
        if ($provider === 'google') {
            $identity = preg_replace('#^models/#', '', $model);
            if (in_array($identity, ['gemini-embedding-001', 'gemini-embedding-2', 'gemini-embedding-2-preview'], true)) {
                return ['parameter' => 'outputDimensionality', 'default' => 3072, 'min' => $identity === 'gemini-embedding-001' ? 1 : 128, 'max' => 3072];
            }
            if ($identity === 'text-embedding-004') { return ['parameter' => 'outputDimensionality', 'default' => 768, 'min' => 1, 'max' => 768]; }
            if ($identity === 'embedding-001') { return ['parameter' => null, 'default' => 768, 'sizes' => [768]]; }
        }
        return null;
    }

    /**
     * Returns default embedding model rows grouped by provider key.
     *
     * @return array<string, array<int, array{id:string,name:string}>>
     */
    public static function get_default_embedding_models_by_provider(): array
    {
        $cloud = class_exists('\\WPAICG\\Cloud\\Connection') ? \WPAICG\Cloud\Connection::embedding_models() : [];
        return ($cloud ? ['aipuffercloud' => self::normalize_embedding_model_rows(array_map(static function ($model) {
            return ['id' => $model['id'], 'dimensions' => (int) $model['dimensions'], 'supportedDimensions' => $model['supportedDimensions'] ?? [$model['dimensions']], 'name' => $model['name']];
        }, $cloud))] : []) + [
            'openai' => self::normalize_embedding_model_rows(self::get_openai_embedding_models()),
            'google' => self::normalize_embedding_model_rows(self::get_google_embedding_models()),
            'openrouter' => self::normalize_embedding_model_rows(self::get_openrouter_embedding_models()),
            'azure' => self::normalize_embedding_model_rows(self::get_azure_embedding_models()),
        ];
    }

    /**
     * Returns normalized embedding models by provider, including filter extensions.
     *
     * @param string $context
     * @return array<string, array<int, array{id:string,name:string}>>
     */
    public static function get_embedding_models_by_provider(string $context = ''): array
    {
        $provider_map = self::get_embedding_provider_map($context);
        $default_models_by_provider = self::get_default_embedding_models_by_provider();
        $models_by_provider = apply_filters(
            'aipkit_embedding_models_by_provider',
            $default_models_by_provider,
            $context
        );

        $normalized_models_by_provider = self::normalize_embedding_models_by_provider($models_by_provider);
        if (empty($normalized_models_by_provider)) {
            $normalized_models_by_provider = $default_models_by_provider;
        }

        foreach (array_keys($provider_map) as $provider_key) {
            if (!isset($normalized_models_by_provider[$provider_key])) {
                $normalized_models_by_provider[$provider_key] = $default_models_by_provider[$provider_key] ?? [];
            }
        }

        return $normalized_models_by_provider;
    }

    /**
     * Returns a lookup map of all known embedding model IDs for the given context.
     *
     * Useful for validating whether a saved model still exists in the current
     * provider model lists.
     *
     * @param string $context
     * @return array<string, bool>
     */
    public static function get_all_embedding_model_ids_map(string $context = ''): array
    {
        $models_by_provider = self::get_embedding_models_by_provider($context);
        $model_ids_map = [];

        foreach ($models_by_provider as $provider_models) {
            $normalized_rows = self::normalize_embedding_model_rows($provider_models);
            foreach ($normalized_rows as $model_row) {
                $model_id = isset($model_row['id']) ? sanitize_text_field((string) $model_row['id']) : '';
                if ($model_id !== '') {
                    $model_ids_map[$model_id] = true;
                }
            }
        }

        return $model_ids_map;
    }

    /**
     * Render `<optgroup>` options for embedding model select fields.
     *
     * Supports either plain model values (`model`) or combined provider/model
     * values (`provider_model`, formatted as `provider::model`).
     *
     * @param array<string, string> $provider_options
     * @param array<string, array<int, array{id:string,name:string}>> $models_by_provider
     * @param string $selected_provider
     * @param string $selected_model
     * @param array<string, mixed> $args
     * @return string
     */
    public static function render_embedding_optgroup_options(
        array $provider_options,
        array $models_by_provider,
        string $selected_provider = '',
        string $selected_model = '',
        array $args = []
    ): string {
        $args = wp_parse_args($args, [
            'value_mode' => 'model', // model | provider_model
            'include_manual_fallback' => true,
            'manual_group_label' => __('Manual', 'gpt3-ai-content-generator'),
        ]);

        $value_mode = isset($args['value_mode']) && $args['value_mode'] === 'provider_model'
            ? 'provider_model'
            : 'model';
        $include_manual_fallback = !empty($args['include_manual_fallback']);
        $manual_group_label = sanitize_text_field((string) ($args['manual_group_label'] ?? __('Manual', 'gpt3-ai-content-generator')));

        $normalized_provider_options = [];
        foreach ($provider_options as $provider_key => $provider_label) {
            if (!is_string($provider_key) || !is_string($provider_label)) {
                continue;
            }
            $provider_key = sanitize_key($provider_key);
            if ($provider_key === '') {
                continue;
            }
            $normalized_provider_options[$provider_key] = sanitize_text_field($provider_label);
        }
        if (empty($normalized_provider_options)) {
            $normalized_provider_options = self::get_default_embedding_provider_map();
        }

        $normalized_models_by_provider = self::normalize_embedding_models_by_provider($models_by_provider);
        foreach (array_keys($normalized_provider_options) as $provider_key) {
            if (!isset($normalized_models_by_provider[$provider_key])) {
                $normalized_models_by_provider[$provider_key] = [];
            }
        }

        $selected_provider = sanitize_key($selected_provider);
        $selected_model = sanitize_text_field($selected_model);
        $selected_value = '';
        if ($selected_model !== '') {
            $selected_value = $value_mode === 'provider_model'
                ? ($selected_provider !== '' ? $selected_provider . '::' . $selected_model : '')
                : $selected_model;
        }

        $model_provider_lookup = [];
        foreach ($normalized_models_by_provider as $provider_key => $provider_models) {
            foreach ($provider_models as $model_row) {
                $model_id = isset($model_row['id']) ? sanitize_text_field((string) $model_row['id']) : '';
                if ($model_id === '' || isset($model_provider_lookup[$model_id])) {
                    continue;
                }
                $model_provider_lookup[$model_id] = $provider_key;
            }
        }

        $manual_needed = (
            $include_manual_fallback
            && $selected_model !== ''
            && !isset($model_provider_lookup[$selected_model])
        );

        $html = '';
        foreach ($normalized_provider_options as $provider_key => $provider_label) {
            $provider_models = $normalized_models_by_provider[$provider_key] ?? [];
            $html .= '<optgroup label="' . esc_attr($provider_label) . '">';

            foreach ($provider_models as $model_row) {
                $model_id = isset($model_row['id']) ? sanitize_text_field((string) $model_row['id']) : '';
                if ($model_id === '') {
                    continue;
                }
                $model_name = isset($model_row['name'])
                    ? sanitize_text_field((string) $model_row['name'])
                    : $model_id;
                $option_value = $value_mode === 'provider_model'
                    ? $provider_key . '::' . $model_id
                    : $model_id;
                $is_selected = selected($selected_value, $option_value, false);
                $html .= '<option value="' . esc_attr($option_value) . '" data-provider="' . esc_attr($provider_key) . '" ' . $is_selected . '>' . esc_html($model_name) . '</option>';
            }

            if (
                $manual_needed
                && $value_mode === 'provider_model'
                && $selected_provider !== ''
                && $selected_provider === $provider_key
            ) {
                $manual_value = $selected_provider . '::' . $selected_model;
                $html .= '<option value="' . esc_attr($manual_value) . '" data-provider="' . esc_attr($selected_provider) . '" selected="selected">' . esc_html($selected_model) . '</option>';
                $manual_needed = false;
            }

            $html .= '</optgroup>';
        }

        if ($manual_needed && $selected_model !== '') {
            if ($value_mode === 'provider_model') {
                $manual_provider = $selected_provider !== '' ? $selected_provider : 'manual';
                $manual_value = $manual_provider . '::' . $selected_model;
                $html .= '<optgroup label="' . esc_attr($manual_group_label) . '">';
                $html .= '<option value="' . esc_attr($manual_value) . '" data-provider="' . esc_attr($manual_provider) . '" selected="selected">' . esc_html($selected_model) . '</option>';
                $html .= '</optgroup>';
            } else {
                $manual_provider = $selected_provider !== '' ? $selected_provider : 'manual';
                $html .= '<option value="' . esc_attr($selected_model) . '" data-provider="' . esc_attr($manual_provider) . '" selected="selected">' . esc_html($selected_model) . '</option>';
            }
        }

        return $html;
    }

    /**
     * Returns embedding localization payload for admin UI scripts.
     *
     * Includes both new grouped keys and legacy per-provider keys
     * to keep existing JS modules backward-compatible.
     *
     * @param string $context
     * @param bool $include_legacy Include deprecated per-provider arrays for backward compatibility.
     * @return array<string, mixed>
     */
    public static function get_embedding_localization_payload(string $context = '', bool $include_legacy = true): array
    {
        $provider_map = self::get_embedding_provider_map($context);
        $models_by_provider = self::get_embedding_models_by_provider($context);

        $payload = [
            'embeddingProviderMap' => $provider_map,
            'embeddingModelsByProvider' => $models_by_provider,
            'embeddingModels' => $models_by_provider,
            'embedding_provider_map' => $provider_map,
            'embedding_models_by_provider' => $models_by_provider,
        ];

        if (!$include_legacy) {
            return $payload;
        }

        $openai_models = isset($models_by_provider['openai']) && is_array($models_by_provider['openai'])
            ? $models_by_provider['openai']
            : [];
        $google_models = isset($models_by_provider['google']) && is_array($models_by_provider['google'])
            ? $models_by_provider['google']
            : [];
        $openrouter_models = isset($models_by_provider['openrouter']) && is_array($models_by_provider['openrouter'])
            ? $models_by_provider['openrouter']
            : [];
        $azure_models = isset($models_by_provider['azure']) && is_array($models_by_provider['azure'])
            ? $models_by_provider['azure']
            : [];
        $ollama_models = isset($models_by_provider['ollama']) && is_array($models_by_provider['ollama'])
            ? $models_by_provider['ollama']
            : [];

        return array_merge($payload, [
            'openaiEmbeddingModels' => $openai_models,
            'googleEmbeddingModels' => $google_models,
            'openrouterEmbeddingModels' => $openrouter_models,
            'azureEmbeddingModels' => $azure_models,
            'ollamaEmbeddingModels' => $ollama_models,
            'openai_embedding_models' => $openai_models,
            'google_embedding_models' => $google_models,
            'openrouter_embedding_models' => $openrouter_models,
            'azure_embedding_models' => $azure_models,
            'ollama_embedding_models' => $ollama_models,
        ]);
    }

    /**
     * Returns vector-store localization payload for admin UI scripts.
     *
     * Uses registry data when available and falls back to saved model-list
     * options for Pinecone/Qdrant/Chroma in partial-sync states.
     *
     * @param string $context
     * @return array<string, mixed>
     */
    public static function get_vector_store_localization_payload(string $context = ''): array
    {
        $openai_vector_stores = [];
        $pinecone_indexes = self::get_pinecone_indexes();
        $qdrant_collections = self::get_qdrant_collections();
        $chroma_collections = self::get_chroma_collections();
        $google_file_search_stores = self::get_google_file_search_stores();

        if (class_exists(AIPKit_Vector_Store_Registry::class)) {
            $openai_vector_stores = AIPKit_Vector_Store_Registry::get_registered_stores_by_provider('OpenAI');

            $registry_google_file_search_stores = AIPKit_Vector_Store_Registry::get_registered_stores_by_provider('Google');
            if (is_array($registry_google_file_search_stores) && !empty($registry_google_file_search_stores)) {
                $google_file_search_stores = $registry_google_file_search_stores;
            }

            $registry_pinecone_indexes = AIPKit_Vector_Store_Registry::get_registered_stores_by_provider('Pinecone');
            if (is_array($registry_pinecone_indexes) && !empty($registry_pinecone_indexes)) {
                $pinecone_indexes = $registry_pinecone_indexes;
            }

            $registry_qdrant_collections = AIPKit_Vector_Store_Registry::get_registered_stores_by_provider('Qdrant');
            if (is_array($registry_qdrant_collections) && !empty($registry_qdrant_collections)) {
                $qdrant_collections = $registry_qdrant_collections;
            }

            $registry_chroma_collections = AIPKit_Vector_Store_Registry::get_registered_stores_by_provider('Chroma');
            if (is_array($registry_chroma_collections) && !empty($registry_chroma_collections)) {
                $chroma_collections = $registry_chroma_collections;
            }
        }

        // AI Puffer knowledge bases live in this site's database, so the list is always current.
        if (!class_exists(\WPAICG\Vector\Providers\AIPKit_Vector_Local_Strategy::class) && defined('WPAICG_PLUGIN_DIR') && file_exists(WPAICG_PLUGIN_DIR . 'classes/knowledge-base/providers/local.php')) {
            require_once WPAICG_PLUGIN_DIR . 'classes/knowledge-base/providers/local.php';
        }
        $local_stores = class_exists(\WPAICG\Vector\Providers\AIPKit_Vector_Local_Strategy::class) ? \WPAICG\Vector\Providers\AIPKit_Vector_Local_Strategy::stores() : [];

        $payload = [
            'vectorStores' => [
                'local' => $local_stores,
                'openai' => $openai_vector_stores,
                'pinecone' => $pinecone_indexes,
                'qdrant' => $qdrant_collections,
                'chroma' => $chroma_collections,
                'google_file_search' => $google_file_search_stores,
            ],
            'openaiVectorStores' => $openai_vector_stores,
            'pineconeIndexes' => $pinecone_indexes,
            'qdrantCollections' => $qdrant_collections,
            'chromaCollections' => $chroma_collections,
            'googleFileSearchStores' => $google_file_search_stores,
            'openai_vector_stores' => $openai_vector_stores,
            'pinecone_indexes' => $pinecone_indexes,
            'qdrant_collections' => $qdrant_collections,
            'chroma_collections' => $chroma_collections,
            'google_file_search_stores' => $google_file_search_stores,
            'localVectorStores' => $local_stores,
            'local_stores' => $local_stores,
        ];

        $filtered_payload = apply_filters('aipkit_vector_store_localization_payload', $payload, $context);
        return is_array($filtered_payload) ? array_merge($payload, $filtered_payload) : $payload;
    }

    /**
     * Normalize models-by-provider map into `{provider_key => [id,name]}` entries.
     *
     * @param mixed $models_by_provider
     * @return array<string, array<int, array{id:string,name:string}>>
     */
    public static function normalize_embedding_models_by_provider($models_by_provider): array
    {
        $normalized_map = [];
        if (!is_array($models_by_provider)) {
            return $normalized_map;
        }

        foreach ($models_by_provider as $provider_key => $models) {
            if (!is_string($provider_key)) {
                continue;
            }
            $provider_key = sanitize_key($provider_key);
            if ($provider_key === '') {
                continue;
            }
            $normalized_map[$provider_key] = self::normalize_embedding_model_rows($models);
        }

        return $normalized_map;
    }

    /**
     * Normalize mixed model rows to `[id, name]` entries.
     *
     * @param mixed $models
     * @return array<int, array{id:string,name:string}>
     */
    public static function normalize_embedding_model_rows($models): array
    {
        $normalized_models = [];
        if (!is_array($models)) {
            return $normalized_models;
        }

        foreach ($models as $model_row) {
            if (is_string($model_row)) {
                $model_id = sanitize_text_field($model_row);
                if ($model_id !== '') {
                    $normalized_models[] = [
                        'id' => $model_id,
                        'name' => $model_id,
                    ];
                }
                continue;
            }

            if (!is_array($model_row)) {
                continue;
            }

            $model_id = isset($model_row['id']) ? sanitize_text_field((string) $model_row['id']) : '';
            if ($model_id === '' && isset($model_row['name'])) {
                $model_id = sanitize_text_field((string) $model_row['name']);
            }
            if ($model_id === '') {
                continue;
            }

            $model_name = isset($model_row['name'])
                ? sanitize_text_field((string) $model_row['name'])
                : $model_id;

            $normalized_row = $model_row;
            $normalized_row['id'] = $model_id;
            $normalized_row['name'] = $model_name;
            $normalized_models[] = $normalized_row;
        }

        return $normalized_models;
    }

    private static function merge_model_rows_by_id(array ...$model_lists): array
    {
        $merged_models = [];
        $indexes_by_id = [];

        foreach ($model_lists as $model_list) {
            foreach (self::normalize_embedding_model_rows($model_list) as $model_row) {
                $model_id = isset($model_row['id']) ? sanitize_text_field((string) $model_row['id']) : '';
                if ($model_id === '') {
                    continue;
                }

                if (isset($indexes_by_id[$model_id])) {
                    $existing_index = $indexes_by_id[$model_id];
                    $merged_models[$existing_index] = array_merge($merged_models[$existing_index], $model_row);
                    continue;
                }

                $indexes_by_id[$model_id] = count($merged_models);
                $merged_models[] = $model_row;
            }
        }

        return $merged_models;
    }

    public static function merge_model_rows(array ...$model_lists): array
    {
        return self::merge_model_rows_by_id(...$model_lists);
    }


    public static function get_current_provider()
    {
        $opts = get_option('aipkit_options');
        if (!is_array($opts)) {
            $opts = [];
        }
        $stored_provider = isset($opts['provider']) ? sanitize_text_field((string) $opts['provider']) : 'OpenAI';
        $normalized_provider = self::normalize_main_provider($stored_provider, 'OpenAI');

        if ($normalized_provider !== $stored_provider) {
            $opts['provider'] = $normalized_provider;
            update_option('aipkit_options', $opts, 'no');
        }

        return $normalized_provider;
    }

    public static function get_all_providers()
    {
        $provider_defaults = self::get_hydrated_provider_defaults_all();

        $opts = get_option('aipkit_options');
        if (!is_array($opts)) {
            $opts = [];
        }

        // Check if the providers data is missing or corrupted (not an array).
        if (!isset($opts['providers']) || !is_array($opts['providers'])) {
            // Data is corrupt or missing. Return a default structure for this request only.
            // Crucially, DO NOT save this back to the database. This prevents a temporary read error
            // from causing a permanent wipe of all saved API keys. The next successful save
            // from the settings page will restore the correct structure.
            $temporary_providers = [];
            foreach ($provider_defaults as $provider_name => $defaults) {
                $temporary_providers[$provider_name] = $defaults;
            }
            return $temporary_providers;
        }

        // Data from DB is a valid array. Proceed with normal initialization/pruning.
        $providers_from_db = $opts['providers'];
        $final_providers = [];
        $changed = false;

        // Loop through the master list of defaults to ensure structure is always correct.
        foreach ($provider_defaults as $provider_name => $defaults) {
            $current_settings = $providers_from_db[$provider_name] ?? [];
            if (!is_array($current_settings)) {
                $current_settings = []; // Treat a corrupted entry for a single provider as empty
            }
            // Merge defaults with current settings (current values take precedence).
            $merged = array_merge($defaults, $current_settings);
            // Prune any obsolete settings that are not in the defaults.
            $final_providers[$provider_name] = array_intersect_key($merged, $defaults);
        }

        if (wp_json_encode($providers_from_db) !== wp_json_encode($final_providers)) {
            $opts['providers'] = $final_providers;
            update_option('aipkit_options', $opts, 'no');
        }
        return $final_providers;
    }

    /**
     * Return the connection state used by provider-dependent admin modules.
     *
     * Keeping this map here gives page localization and AJAX saves one
     * authoritative definition of what "configured" means for every provider.
     *
     * @return array<string, bool>
     */
    public static function get_provider_status_map(): array
    {
        $providers = self::get_all_providers();
        $has_value = static function (string $provider, string $key) use ($providers): bool {
            $value = $providers[$provider][$key] ?? '';
            return is_scalar($value) && trim((string) $value) !== '';
        };

        $ollama_state = AIPKit_Model_Registry::get_provider_states($providers)['ollama'] ?? [];
        $ollama_connected = $has_value('Ollama', 'base_url')
            && class_exists(aipkit_dashboard::class)
            && aipkit_dashboard::is_pro_plan()
            && !empty($ollama_state['last_success'])
            && empty($ollama_state['connection_changed'])
            && in_array($ollama_state['status'] ?? '', ['ready', 'stale'], true);

        return [
            'aipuffercloud' => class_exists('\\WPAICG\\Cloud\\Connection') && \WPAICG\Cloud\Connection::generation_ready(),
            'openai' => $has_value('OpenAI', 'api_key'),
            'google' => $has_value('Google', 'api_key'),
            'claude' => $has_value('Claude', 'api_key'),
            'openrouter' => $has_value('OpenRouter', 'api_key'),
            'azure' => $has_value('Azure', 'api_key') && $has_value('Azure', 'endpoint'),
            'ollama' => $ollama_connected,
            'deepseek' => $has_value('DeepSeek', 'api_key'),
            'xai' => $has_value('xAI', 'api_key'),
            'replicate' => $has_value('Replicate', 'api_key'),
            'elevenlabs' => $has_value('ElevenLabs', 'api_key'),
            'pexels' => $has_value('Pexels', 'api_key'),
            'pixabay' => $has_value('Pixabay', 'api_key'),
            'pinecone' => $has_value('Pinecone', 'api_key'),
            'qdrant' => $has_value('Qdrant', 'api_key') && $has_value('Qdrant', 'url'),
            'chroma' => $has_value('Chroma', 'url'),
        ];
    }

    /**
     * Return the canonical setup and synchronization state for every provider.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function get_provider_connection_states(): array
    {
        $states = AIPKit_Model_Registry::get_provider_states(self::get_all_providers());
        if (
            isset($states['ollama'])
            && class_exists(aipkit_dashboard::class)
            && !aipkit_dashboard::is_pro_plan()
        ) {
            $states['ollama']['locked'] = true;
            $states['ollama']['status'] = 'locked';
            if (($states['ollama']['resource_status'] ?? 'not_applicable') !== 'not_applicable') {
                $states['ollama']['resource_status'] = 'locked';
            }
        }
        return $states;
    }

    /**
     * Return compatibility-shaped timestamps sourced from registry snapshots.
     *
     * @return array<string, int>
     */
    public static function get_model_sync_timestamps(): array
    {
        return AIPKit_Model_Registry::get_sync_timestamps();
    }

    /**
     * Query the universal model registry.
     *
     * @param array<string, mixed> $args
     * @return array<int, array<string, mixed>>
     */
    public static function query_models(array $args = []): array
    {
        return AIPKit_Model_Registry::query_models($args);
    }

    /**
     * Query voices, vector targets and other provider resources.
     *
     * @param array<string, mixed> $args
     * @return array<int, array<string, mixed>>
     */
    public static function query_provider_resources(array $args = []): array
    {
        return AIPKit_Model_Registry::query_resources($args);
    }

    /**
     * Resolve a provider/model pair against the universal registry.
     *
     * @return array<string, mixed>
     */
    public static function resolve_model_selection(
        string $provider,
        string $model_id,
        string $required_capability = '',
        bool $allow_manual = true
    ): array {
        return AIPKit_Model_Registry::resolve_selection(
            $provider,
            $model_id,
            $required_capability,
            $allow_manual
        );
    }

    public static function get_provider_data($provider)
    {
        if ($provider === 'AIPufferCloud') {
            $ready = class_exists('\\WPAICG\\Cloud\\Connection') && \WPAICG\Cloud\Connection::generation_ready();
            $all = self::get_all_providers();
            return ['api_key' => $ready ? 'cloud-site-connection' : '', 'model' => (string) ($all['AIPufferCloud']['model'] ?? '')];
        }
        $all = self::get_all_providers();
        $provider_defaults = self::get_hydrated_provider_defaults_all();
        $defaults = $provider_defaults[$provider] ?? [];
        $provider_data = isset($all[$provider]) ? array_merge($defaults, $all[$provider]) : $defaults;

        // Backward compatibility: older installs may have an empty stored model.
        if (
            isset($provider_data['model'])
            && trim((string) $provider_data['model']) === ''
            && !empty($defaults['model'])
        ) {
            $provider_data['model'] = $defaults['model'];
        }
        if (
            $provider === 'DeepSeek'
            && isset($provider_data['model'])
            && AIPKit_Model_Catalog::is_deprecated_id('DeepSeek', (string) $provider_data['model'])
        ) {
            $provider_data['model'] = $defaults['model'] ?? self::get_default_model_id('DeepSeek');
        }
        if ($provider === 'Google' && isset($provider_data['model'])) {
            $provider_data['model'] = self::normalize_google_text_model((string) $provider_data['model']);
        }
        if ($provider === 'OpenAI') {
            $provider_data['api_mode'] = self::normalize_openai_api_mode($provider_data['api_mode'] ?? null);
        }

        return $provider_data;
    }

    public static function get_default_provider_config()
    {
        $currentProvider = self::get_current_provider();
        $provData = self::get_provider_data($currentProvider);
        $all_possible_keys = [];
        foreach (self::get_hydrated_provider_defaults_all() as $def_val) {
            $all_possible_keys = array_merge($all_possible_keys, array_keys($def_val));
        }
        $all_possible_keys = array_unique($all_possible_keys);
        $result = array_fill_keys($all_possible_keys, '');
        $result['provider'] = $currentProvider;
        foreach ($result as $key => $value) {
            if (isset($provData[$key])) {
                $result[$key] = $provData[$key];
            }
        }
        return $result;
    }

    /**
     * Resolve the provider/model used only for a newly created text-generation
     * configuration. A valid model saved in Settings takes precedence over the
     * catalog recommendation. Existing items bypass this initialization path.
     *
     * @param array<int, string> $allowed_providers
     * @param bool $include_cloud Whether Cloud may be used when the site owner selected it as the main provider.
     * @return array<string, mixed>
     */
    public static function get_new_text_generation_selection(array $allowed_providers = [], bool $include_cloud = true): array
    {
        $current_provider = self::get_current_provider();
        if ($include_cloud && $current_provider === 'AIPufferCloud'
            && (!class_exists('\\WPAICG\\Cloud\\Connection') || !\WPAICG\Cloud\Connection::generation_ready())) {
            return ['provider' => 'AIPufferCloud', 'provider_key' => 'aipuffercloud', 'model' => ''];
        }
        if (empty($allowed_providers)) {
            $allowed_providers = self::get_main_provider_allowlist();
        }
        // A connection alone never chooses a billable provider for a new item.
        if (!$include_cloud || $current_provider !== 'AIPufferCloud') {
            $allowed_providers = array_values(array_diff($allowed_providers, ['AIPufferCloud']));
        }
        return AIPKit_Model_Registry::resolve_new_model_selection('text_generation', [
            'allowed_providers' => $allowed_providers,
            'preferred_provider' => $current_provider,
            'provider_configs' => self::get_all_providers(),
        ]);
    }

    /** Resolve a feature from cached catalogs, keeping Cloud billing an explicit choice. */
    private static function resolve_new_feature_selection(string $capability, string $provider, array $configs): array
    {
        $allowed = AIPKit_Model_Catalog::get_provider_priority($capability);
        if ($provider !== 'AIPufferCloud') {
            $allowed = array_values(array_diff($allowed, ['AIPufferCloud']));
        } elseif (!class_exists('\\WPAICG\\Cloud\\Connection') || !\WPAICG\Cloud\Connection::generation_ready()) {
            return ['provider' => 'AIPufferCloud', 'provider_key' => 'aipuffercloud', 'model' => ''];
        }
        $allowed = array_values(array_filter($allowed, static function ($candidate) use ($capability): bool {
            return self::provider_supports_capability($candidate, $capability);
        }));
        return $allowed ? AIPKit_Model_Registry::resolve_new_model_selection($capability, [
            'allowed_providers' => $allowed, 'preferred_provider' => $provider,
            'provider_configs' => $configs, 'configured_only' => true,
        ]) : ['provider' => '', 'provider_key' => '', 'model' => ''];
    }

    /**
     * Add source defaults for persistent knowledge, indexed by the chatbot's text provider.
     * OpenAI and Google use native stores; other providers use persistent Local knowledge.
     */
    public static function get_chatbot_source_defaults(): array
    {
        $configs = self::get_all_providers();
        $defaults = [];
        foreach (AIPKit_Model_Catalog::get_provider_priority('text_generation') as $provider) {
            $selection = self::resolve_new_feature_selection('embeddings', $provider, $configs);
            $defaults[strtolower($provider)] = [
                'vector_store_provider' => ['OpenAI' => 'openai', 'Google' => 'google'][$provider] ?? 'local',
                'vector_embedding_provider' => $selection['provider_key'],
                'vector_embedding_model' => $selection['model'],
            ];
        }
        return $defaults;
    }

    /** Defaults for optional features on NEW configurations only. Never binds a store or enables a feature. */
    public static function get_new_feature_defaults(string $context = 'content', string $provider = ''): array
    {
        $provider = $provider !== '' ? self::normalize_provider_label($provider)
            : (string) (self::get_new_text_generation_selection()['provider'] ?? self::get_current_provider());
        $configs = self::get_all_providers();
        $selections = [];
        foreach (['embeddings', 'image_generation', 'tts', 'stt'] as $capability) {
            $selections[$capability] = self::resolve_new_feature_selection($capability, $provider, $configs);
        }
        // Google transcribes with audio-capable Gemini text models, not a separate STT catalog.
        $google_state = AIPKit_Model_Registry::get_provider_states($configs)['google'] ?? [];
        if (($provider === 'Google' || $selections['stt']['provider'] === '')
            && !empty($google_state['configured']) && empty($google_state['locked'])) {
            $ids = array_column(self::get_google_stt_models(), 'id');
            $preferred = (string) ($configs['Google']['model'] ?? '');
            $model = in_array($preferred, $ids, true) ? $preferred : self::normalize_google_stt_model('');
            if (!in_array($model, $ids, true)) { $model = (string) ($ids[0] ?? ''); }
            if ($model !== '') { $selections['stt'] = ['provider' => 'Google', 'provider_key' => 'google', 'model' => $model]; }
        }
        $knowledge = ['OpenAI' => 'openai', 'Google' => 'google'];
        if ($context === 'chatbot') { $knowledge['Claude'] = 'claude_files'; }
        $tts = $selections['tts'];
        $voice = '';
        if ($tts['model'] !== '') {
            $voice_catalogs = ['OpenAI' => 'OpenAIVoices', 'Google' => 'GoogleTTSVoices', 'ElevenLabs' => 'ElevenLabs'];
            if ($tts['provider'] === 'AIPufferCloud') {
                $voices = \WPAICG\Cloud\Connection::media_capabilities('speech_generate', $tts['model'])['voices'] ?? [];
                $voice = (string) ($voices[0] ?? '');
            } elseif (isset($voice_catalogs[$tts['provider']])) {
                $voice_catalog = $voice_catalogs[$tts['provider']];
                $voices = AIPKit_Model_Registry::get_catalog_records($voice_catalog);
                $ids = array_column($voices, 'id');
                $preferred_voice = $tts['provider'] === 'ElevenLabs' ? ($configs['ElevenLabs']['voice_id'] ?? '')
                    : AIPKit_Model_Catalog::get_default_id($voice_catalog);
                $voice = in_array($preferred_voice, $ids, true) ? $preferred_voice : (string) ($ids[0] ?? '');
            }
        }
        return [
            'vector_store_provider' => $knowledge[$provider] ?? 'local',
            'vector_embedding_provider' => $selections['embeddings']['provider_key'],
            'vector_embedding_model' => $selections['embeddings']['model'],
            'image_provider' => $selections['image_generation']['provider_key'],
            'image_model' => $selections['image_generation']['model'],
            'tts_provider' => $tts['provider'], 'tts_model' => $tts['model'], 'tts_voice' => $voice,
            'stt_provider' => $selections['stt']['provider'], 'stt_model' => $selections['stt']['model'],
        ];
    }

    /** Safe initialization data for admin screens; contains no credentials and makes no HTTP requests. */
    public static function get_new_configuration_payload(): array
    {
        return [
            'main_provider' => strtolower(self::get_current_provider()),
            'newAiSelection' => self::get_new_text_generation_selection(),
            'newFeatureDefaults' => self::get_new_feature_defaults(),
            'chatbotSourceDefaults' => self::get_chatbot_source_defaults(),
        ];
    }

    public static function get_provider_defaults($provider)
    {
        $provider_defaults = self::get_hydrated_provider_defaults_all();
        return $provider_defaults[$provider] ?? [];
    }
    public static function get_provider_defaults_all(): array
    {
        return self::get_hydrated_provider_defaults_all();
    }

    public static function get_recommended_models(string $provider_key): array
    {
        return AIPKit_Model_Registry::get_recommended_models($provider_key);
    }

    public static function save_provider_data($provider, $data)
    {
        $provider_defaults = self::get_hydrated_provider_defaults_all();

        $opts = get_option('aipkit_options');
        if (!is_array($opts)) {
            $opts = [];
        }

        if (!isset($opts['providers']) || !is_array($opts['providers'])) {
            $opts['providers'] = array();
        }
        if (!isset($opts['providers'][$provider]) || !is_array($opts['providers'][$provider])) {
            $opts['providers'][$provider] = $provider_defaults[$provider] ?? [];
        }

        $current_provider_settings_ref = & $opts['providers'][$provider];
        $defaults_for_provider = $provider_defaults[$provider] ?? [];
        $changed_for_this_provider = false;

        foreach ($defaults_for_provider as $key => $default_value) {
            if (array_key_exists($key, $data)) { // Only process keys that were sent in $data
                $new_value = $data[$key]; // Use the value from $data
                // Sanitize based on key
                if (in_array($key, ['base_url', 'endpoint', 'url'], true)) {
                    $new_value = esc_url_raw($new_value);
                } elseif ($provider === 'OpenAI' && $key === 'api_mode') {
                    $new_value = self::normalize_openai_api_mode($new_value);
                } elseif (
                    $provider === 'OpenRouter'
                    && function_exists('WPAICG\\Core\\Providers\\OpenRouter\\Methods\\sanitize_routing_settings_logic')
                    && in_array($key, ['allow_fallbacks', 'require_parameters', 'data_collection', 'zdr', 'fallback_model_1', 'fallback_model_2', 'fallback_model_3'], true)
                ) {
                    $routing_settings = \WPAICG\Core\Providers\OpenRouter\Methods\sanitize_routing_settings_logic([
                        $key => $new_value,
                    ]);
                    $new_value = $routing_settings[$key];
                } elseif ($key === 'store_conversation') {
                    $new_value = ($new_value === '1' ? '1' : '0');
                } elseif ($key === 'expiration_policy') {
                    $new_value = absint($new_value);
                } // Sanitize new field
                else {
                    $new_value = sanitize_text_field($new_value);
                }

                if (!isset($current_provider_settings_ref[$key]) || $current_provider_settings_ref[$key] !== $new_value) {
                    $current_provider_settings_ref[$key] = $new_value;
                    $changed_for_this_provider = true;
                }
            }
        }
        if (
            $provider === 'OpenRouter'
            && function_exists('WPAICG\\Core\\Providers\\OpenRouter\\Methods\\sanitize_routing_settings_logic')
        ) {
            $normalized_routing_settings = \WPAICG\Core\Providers\OpenRouter\Methods\sanitize_routing_settings_logic(
                array_merge($current_provider_settings_ref, $data)
            );
            foreach ($normalized_routing_settings as $routing_key => $routing_value) {
                if (
                    !isset($current_provider_settings_ref[$routing_key])
                    || $current_provider_settings_ref[$routing_key] !== $routing_value
                ) {
                    $current_provider_settings_ref[$routing_key] = $routing_value;
                    $changed_for_this_provider = true;
                }
            }
        }
        if ($changed_for_this_provider) {
            update_option('aipkit_options', $opts, 'no');
        }
    }

    public static function save_current_provider($provider)
    {
        $provider_defaults = self::get_hydrated_provider_defaults_all();

        $opts = get_option('aipkit_options');
        if (!is_array($opts)) {
            $opts = [];
        }

        $provider = self::normalize_main_provider((string) $provider, 'OpenAI');
        if (!isset($opts['provider']) || $opts['provider'] !== $provider) {
            $opts['provider'] = $provider;
            if (!isset($opts['providers']) || !is_array($opts['providers'])) {
                $opts['providers'] = array();
            }
            if (!isset($opts['providers'][$provider]) || !is_array($opts['providers'][$provider])) {
                $opts['providers'][$provider] = $provider_defaults[$provider] ?? [];
            }
            update_option('aipkit_options', $opts, 'no');
        }
    }

    public static function get_model_list(string $provider_key): array
    {
        return AIPKit_Model_Registry::get_legacy_model_list($provider_key);
    }

    /**
     * Clears all model list caches (static and transient).
     * Called after a model sync operation.
     */
    public static function clear_model_caches(): void
    {
        AIPKit_Model_Registry::clear_caches();
    }


    public static function get_openai_models(): array
    {
        return self::get_model_list('OpenAI');
    }
    public static function get_openai_image_models(): array
    {
        return self::get_model_list('OpenAIImage');
    }
    public static function get_default_openai_image_model(): string
    {
        return self::get_default_model_id('OpenAIImage');
    }
    public static function get_xai_image_models(): array
    {
        return self::get_model_list('xAIImage');
    }
    public static function get_default_xai_image_model(): string
    {
        return self::get_default_model_id('xAIImage');
    }
    public static function get_xai_image_model_ids(): array
    {
        return wp_list_pluck(self::get_xai_image_models(), 'id');
    }
    public static function is_supported_xai_image_model(string $model): bool
    {
        return in_array(trim($model), self::get_xai_image_model_ids(), true);
    }
    public static function normalize_xai_image_model(?string $model): string
    {
        $normalized_model = is_string($model) ? trim($model) : '';
        if ($normalized_model !== '' && self::is_supported_xai_image_model($normalized_model)) {
            return $normalized_model;
        }

        return self::get_default_xai_image_model();
    }
    public static function get_openai_image_model_ids(): array
    {
        return wp_list_pluck(self::get_openai_image_models(), 'id');
    }
    public static function is_openai_gpt_image_model(string $model): bool
    {
        $normalized_model = strtolower(trim($model));

        return $normalized_model !== '' && strpos($normalized_model, 'gpt-image') === 0;
    }
    public static function openai_image_model_supports_transparent_background(string $model): bool
    {
        $normalized_model = strtolower(trim($model));
        $unsupported_model = strtolower(self::get_default_openai_image_model());

        return self::is_openai_gpt_image_model($normalized_model)
            && ($unsupported_model === '' || strncmp($normalized_model, $unsupported_model, strlen($unsupported_model)) !== 0);
    }
    public static function is_supported_openai_image_model(string $model): bool
    {
        return in_array(trim($model), self::get_openai_image_model_ids(), true);
    }
    public static function normalize_openai_image_model(?string $model): string
    {
        $normalized_model = is_string($model) ? trim($model) : '';

        if ($normalized_model !== '' && self::is_supported_openai_image_model($normalized_model)) {
            return $normalized_model;
        }

        return self::get_default_openai_image_model();
    }
    public static function get_openai_embedding_models(): array
    {
        return self::get_model_list('OpenAIEmbedding');
    }
    public static function get_openrouter_models(): array
    {
        return self::filter_openrouter_text_models(self::get_model_list('OpenRouter'));
    }
    public static function get_openrouter_image_models(): array
    {
        $models = self::get_model_list('OpenRouterImage');
        if (empty($models)) {
            // Backward compatibility until the first dedicated Image Models sync.
            $models = self::get_model_list('OpenRouter');
        }
        if (!is_array($models) || empty($models)) {
            return [];
        }

        $resolver_fn = '\WPAICG\Core\Providers\OpenRouter\Methods\resolve_model_capabilities_from_metadata_logic';
        if (!function_exists($resolver_fn)) {
            $capability_file = WPAICG_PLUGIN_DIR . 'classes/ai/providers/openrouter.php';
            if (file_exists($capability_file)) {
                require_once $capability_file;
            }
        }

        $image_models = [];
        foreach ($models as $model) {
            if (!is_array($model) || empty($model['id'])) {
                continue;
            }

            $model_id = sanitize_text_field((string) $model['id']);
            $model_name = isset($model['name']) ? sanitize_text_field((string) $model['name']) : $model_id;
            if ($model_id === '') {
                continue;
            }

            $capabilities = isset($model['capabilities']) && is_array($model['capabilities'])
                ? $model['capabilities']
                : (function_exists($resolver_fn) ? (array) call_user_func($resolver_fn, $model) : []);
            $supports_image = !empty($capabilities['image_output']) || !empty($capabilities['image_generation']);
            if (!$supports_image) {
                continue;
            }

            $item = [
                'id' => $model_id,
                'name' => $model_name,
            ];
            if (isset($model['output_modalities']) && is_array($model['output_modalities'])) {
                $normalized_output_modalities = array_values(array_unique(array_map(
                    static fn($modality): string => strtolower(trim((string) $modality)),
                    $model['output_modalities']
                )));
                $normalized_output_modalities = array_values(array_filter($normalized_output_modalities, static fn($modality): bool => $modality !== ''));
                if (!empty($normalized_output_modalities)) {
                    $item['output_modalities'] = $normalized_output_modalities;
                }
            }
            if (isset($model['input_modalities']) && is_array($model['input_modalities'])) {
                $normalized_input_modalities = array_values(array_unique(array_map(
                    static fn($modality): string => strtolower(trim((string) $modality)),
                    $model['input_modalities']
                )));
                $normalized_input_modalities = array_values(array_filter($normalized_input_modalities, static fn($modality): bool => $modality !== ''));
                if (!empty($normalized_input_modalities)) {
                    $item['input_modalities'] = $normalized_input_modalities;
                }
            }
            if (isset($model['supported_parameters']) && is_array($model['supported_parameters'])) {
                $normalized_supported_parameters = array_values(array_unique(array_map(
                    static fn($parameter): string => strtolower(trim((string) $parameter)),
                    $model['supported_parameters']
                )));
                $normalized_supported_parameters = array_values(array_filter($normalized_supported_parameters, static fn($parameter): bool => $parameter !== ''));
                if (!empty($normalized_supported_parameters)) {
                    $item['supported_parameters'] = $normalized_supported_parameters;
                }
            }
            if (isset($model['supported_parameter_schema']) && is_array($model['supported_parameter_schema'])) {
                $item['supported_parameter_schema'] = $model['supported_parameter_schema'];
            }
            if (array_key_exists('supports_streaming', $model)) {
                $item['supports_streaming'] = (bool) $model['supports_streaming'];
            }
            if (!empty($capabilities)) {
                $item['capabilities'] = $capabilities;
            }
            $image_models[] = $item;
        }

        usort(
            $image_models,
            static fn(array $a, array $b): int => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''))
        );

        return $image_models;
    }

    private static function filter_openrouter_text_models(array $models): array
    {
        $text_models = [];
        foreach ($models as $model) {
            if (!is_array($model)) {
                $text_models[] = $model;
                continue;
            }

            if (isset($model['capabilities']) && is_array($model['capabilities'])) {
                if (!empty($model['capabilities']['text_generation'])) {
                    $text_models[] = $model;
                }
                continue;
            }

            $output_modalities = isset($model['output_modalities']) && is_array($model['output_modalities'])
                ? array_values(array_unique(array_map(
                    static fn($modality): string => strtolower(trim((string) $modality)),
                    $model['output_modalities']
                )))
                : [];
            $output_modalities = array_values(array_filter($output_modalities, static fn($modality): bool => $modality !== ''));

            if (!empty($output_modalities) && in_array('image', $output_modalities, true) && !in_array('text', $output_modalities, true)) {
                continue;
            }

            $text_models[] = $model;
        }

        return $text_models;
    }
    public static function get_openrouter_embedding_models(): array
    {
        return self::get_model_list('OpenRouterEmbedding');
    }
    public static function get_google_models(): array
    {
        return self::get_model_list('Google');
    }
    public static function normalize_google_text_model(?string $model): string
    {
        $normalized_model = is_string($model) ? trim($model) : '';
        if (strpos($normalized_model, 'models/') === 0) {
            $normalized_model = (string) substr($normalized_model, 7);
        }

        $default_model = self::get_default_model_id('Google');
        if ($normalized_model === '') {
            return $default_model;
        }

        $normalized_lower = strtolower($normalized_model);
        if (
            AIPKit_Model_Catalog::is_deprecated_id('Google', $normalized_lower)
            || preg_match('/^gemini-(?:1(?:\.|-|$)|2\.0(?:-|$)|pro(?:-|$))/', $normalized_lower)
        ) {
            return $default_model;
        }

        $classification = GoogleModelCapabilityClassifier::classify($normalized_model);
        $capabilities = isset($classification['capabilities']) && is_array($classification['capabilities'])
            ? $classification['capabilities']
            : [];
        if (!empty($classification['supports_interactions']) && in_array('text_generation', $capabilities, true)) {
            return $normalized_model;
        }

        // Preserve unknown IDs for custom Gemini-compatible endpoints, but do
        // not route a known image, audio, embedding, video, or agent model as chat.
        return ($classification['family'] ?? 'unknown') === 'unknown'
            ? $normalized_model
            : $default_model;
    }
    public static function get_google_stt_models(): array
    {
        $models = [];
        foreach (self::get_google_models() as $model) {
            if (!GoogleModelCapabilityClassifier::supports_audio_input($model)) {
                continue;
            }
            $row = is_array($model) ? $model : ['id' => (string) $model, 'name' => (string) $model];
            $model_id = trim((string) ($row['id'] ?? ''));
            if (strpos($model_id, 'models/') === 0) {
                $model_id = (string) substr($model_id, 7);
            }
            if ($model_id === '') {
                continue;
            }
            $row['id'] = $model_id;
            $row['name'] = isset($row['name']) && trim((string) $row['name']) !== ''
                ? (string) $row['name']
                : $model_id;
            $models[$model_id] = $row;
        }
        return array_values($models);
    }
    public static function normalize_google_stt_model(?string $model): string
    {
        $normalized_model = is_string($model) ? trim($model) : '';
        if (strpos($normalized_model, 'models/') === 0) {
            $normalized_model = (string) substr($normalized_model, 7);
        }

        foreach (self::get_google_stt_models() as $model_row) {
            $model_id = is_array($model_row)
                ? trim((string) ($model_row['id'] ?? ''))
                : trim((string) $model_row);
            if (strpos($model_id, 'models/') === 0) {
                $model_id = (string) substr($model_id, 7);
            }
            if ($model_id !== '' && $model_id === $normalized_model) {
                return $model_id;
            }
        }

        $default_model = self::get_default_model_id('Google');
        return GoogleModelCapabilityClassifier::supports_audio_input($default_model)
            ? $default_model
            : 'gemini-3.7-flash';
    }
    public static function get_google_embedding_models(): array
    {
        return self::get_model_list('GoogleEmbedding');
    }
    public static function get_claude_models(): array
    {
        return self::get_model_list('Claude');
    }
    public static function get_google_image_models(): array
    {
        return self::get_model_list('GoogleImage');
    }
    public static function get_google_image_model_ids(): array
    {
        return wp_list_pluck(self::get_google_image_models(), 'id');
    }
    public static function is_supported_google_image_model(string $model): bool
    {
        return in_array(trim($model), self::get_google_image_model_ids(), true);
    }
    public static function normalize_google_image_model(?string $model): string
    {
        $normalized_model = is_string($model) ? trim($model) : '';
        if ($normalized_model !== '' && self::is_supported_google_image_model($normalized_model)) {
            return $normalized_model;
        }

        return self::get_default_google_image_model();
    }
    public static function get_default_google_image_model(): string
    {
        return self::get_default_model_id('GoogleImage');
    }
    public static function get_google_video_models(): array
    {
        return self::get_model_list('GoogleVideo');
    }
    public static function get_azure_deployments(): array
    {
        return self::get_model_list('Azure');
    }
    
    /**
     * Get all Azure models grouped by type for dashboard display
     * @return array Grouped array with chat, embedding, and image models
     */
    public static function get_azure_all_models_grouped(): array
    {
        $grouped = [];
        
        // Get chat/language models
        $chat_models = self::get_model_list('Azure');
        if (!empty($chat_models)) {
            $grouped['Chat Models'] = $chat_models;
        }
        
        // Get embedding models
        $embedding_models = self::get_model_list('AzureEmbedding');
        if (!empty($embedding_models)) {
            $grouped['Embedding Models'] = $embedding_models;
        }
        
        // Get image models
        $image_models = self::get_model_list('AzureImage');
        if (!empty($image_models)) {
            $grouped['Image Models'] = $image_models;
        }
        
        return $grouped;
    }
    
    public static function get_azure_image_models(): array
    {
        return self::get_model_list('AzureImage');
    }
    public static function get_azure_embedding_models(): array { return self::get_model_list('AzureEmbedding'); }
    public static function get_deepseek_models(): array
    {
        return self::get_model_list('DeepSeek');
    }

    /**
     * @param mixed $modalities
     * @return array<int, string>
     */
    private static function normalize_xai_modality_list($modalities): array
    {
        if (!is_array($modalities)) {
            return [];
        }

        $normalized = [];
        foreach ($modalities as $modality) {
            if (!is_string($modality)) {
                continue;
            }
            $modality = strtolower(trim($modality));
            if ($modality !== '') {
                $normalized[] = $modality;
            }
        }

        return array_values(array_unique($normalized));
    }

    private static function xai_model_row_matches_id(string $target_model_id, $model): bool
    {
        $target_model_id = strtolower(trim($target_model_id));
        if ($target_model_id === '') {
            return false;
        }

        if (is_string($model)) {
            return strtolower(trim($model)) === $target_model_id;
        }

        if (!is_array($model)) {
            return false;
        }

        $candidates = [];
        foreach (['id', 'model', 'name'] as $key) {
            if (isset($model[$key]) && is_string($model[$key])) {
                $candidates[] = $model[$key];
            }
        }
        if (isset($model['aliases']) && is_array($model['aliases'])) {
            foreach ($model['aliases'] as $alias) {
                if (is_string($alias)) {
                    $candidates[] = $alias;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (strtolower(trim((string) $candidate)) === $target_model_id) {
                return true;
            }
        }

        return false;
    }

    public static function xai_model_supports_image_input(string $model_id): bool
    {
        $model_id = sanitize_text_field(trim($model_id));
        if ($model_id === '') {
            return false;
        }

        $models = self::get_xai_models();
        foreach ($models as $model) {
            if (!self::xai_model_row_matches_id($model_id, $model)) {
                continue;
            }

            if (!is_array($model)) {
                return true;
            }

            $input_modalities = self::normalize_xai_modality_list(
                $model['input_modalities'] ?? ($model['modalities']['input'] ?? [])
            );
            if (!empty($input_modalities)) {
                return in_array('image', $input_modalities, true)
                    || in_array('input_image', $input_modalities, true)
                    || in_array('image_url', $input_modalities, true);
            }

            if (isset($model['capabilities']) && is_array($model['capabilities'])) {
                foreach (['image_input', 'vision'] as $capability_key) {
                    if (array_key_exists($capability_key, $model['capabilities'])) {
                        return (bool) $model['capabilities'][$capability_key];
                    }
                }
            }

            return true;
        }

        return true;
    }

    public static function get_xai_models(): array
    {
        return self::get_model_list('xAI');
    }
    public static function get_ollama_models(): array
    {
        return self::get_model_list('Ollama');
    }
    public static function get_elevenlabs_voices(): array
    {
        return self::get_model_list('ElevenLabs');
    }
    public static function get_elevenlabs_models(): array
    {
        return self::get_model_list('ElevenLabsModels');
    }
    public static function get_openai_tts_models(): array
    {
        return self::get_model_list('OpenAITTS');
    }
    public static function get_openai_stt_models(): array
    {
        $models = self::get_model_list('OpenAISTT');
        $preferred_ids = AIPKit_Model_Catalog::get_seed_ids('OpenAISTT');
        $models_by_id = [];

        foreach ($models as $model) {
            $model_id = is_array($model)
                ? sanitize_text_field((string) ($model['id'] ?? ''))
                : sanitize_text_field((string) $model);
            if ($model_id !== '') {
                $models_by_id[$model_id] = is_array($model)
                    ? $model
                    : ['id' => $model_id, 'name' => $model_id];
            }
        }

        $preferred_models = [];
        foreach ($preferred_ids as $model_id) {
            if (isset($models_by_id[$model_id])) {
                $preferred_models[] = $models_by_id[$model_id];
            }
        }

        return $preferred_models;
    }
    public static function get_openai_realtime_models(): array
    {
        return self::get_model_list('OpenAIRealtime');
    }
    public static function get_openai_tts_voices(): array
    {
        return self::get_model_list('OpenAIVoices');
    }
    public static function get_openai_realtime_voices(): array
    {
        return self::get_model_list('OpenAIRealtimeVoices');
    }
    public static function get_google_tts_voices(): array
    {
        return self::get_model_list('GoogleTTSVoices');
    }
    public static function get_google_tts_models(): array
    {
        return self::get_model_list('GoogleTTS');
    }
    public static function normalize_google_tts_model(?string $model): string
    {
        $normalized_model = is_string($model) ? trim($model) : '';
        $valid_ids = wp_list_pluck(self::get_google_tts_models(), 'id');
        if ($normalized_model !== '' && in_array($normalized_model, $valid_ids, true)) {
            return $normalized_model;
        }

        return self::get_default_model_id('GoogleTTS');
    }
    public static function normalize_google_tts_voice(?string $voice): string
    {
        $normalized_voice = is_string($voice) ? trim($voice) : '';
        foreach (self::get_google_tts_voices() as $voice_row) {
            $voice_id = is_array($voice_row) ? (string) ($voice_row['id'] ?? '') : (string) $voice_row;
            if ($voice_id !== '' && strcasecmp($normalized_voice, $voice_id) === 0) {
                return $voice_id;
            }
        }

        return self::get_default_model_id('GoogleTTSVoices');
    }
    public static function get_ollama_embedding_models(): array
    {
        return self::get_model_list('OllamaEmbedding');
    }
    public static function get_ollama_vision_models(): array
    {
        return self::get_model_list('OllamaVision');
    }
    public static function get_ollama_capability_models(): array
    {
        return self::get_model_list('OllamaCapabilities');
    }
    public static function get_pinecone_indexes(): array
    {
        return self::get_model_list('PineconeIndexes');
    }
    public static function get_qdrant_collections(): array
    {
        return self::get_model_list('QdrantCollections');
    }
    public static function get_chroma_collections(): array
    {
        return self::get_model_list('ChromaCollections');
    }
    public static function get_google_file_search_stores(): array
    {
        return self::get_model_list('GoogleFileSearchStores');
    }
    public static function get_replicate_models(): array
    {
        return self::get_model_list('Replicate');
    }
}
