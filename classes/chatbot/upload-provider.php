<?php

namespace WPAICG\Chat\Core;

use WPAICG\Chat\Storage\BotSettingsManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves the transport used for a chatbot's temporary document uploads.
 *
 * Persistent Knowledge configuration is preserved when it is explicitly
 * enabled. Otherwise, native AI-provider file APIs are preferred and no
 * Knowledge setting is mutated.
 */
class AIPKit_Chat_File_Upload_Provider_Resolver
{
    /**
     * Vector providers that can supply uploaded-file context to any text provider.
     *
     * @var string[]
     */
    private const VECTOR_CONTEXT_PROVIDERS = ['openai', 'pinecone', 'qdrant', 'chroma', 'local'];

    /**
     * Resolve the upload transport from existing chatbot settings.
     *
     * @param array<string, mixed> $settings Chatbot settings.
     * @return string Provider slug, or an empty string when no safe route exists.
     */
    public static function resolve(array $settings): string
    {
        $main_provider = strtolower(trim((string) ($settings['provider'] ?? 'OpenAI')));
        $knowledge_enabled = ($settings['enable_vector_store'] ?? '0') === '1';
        $knowledge_provider = sanitize_key((string) ($settings['vector_store_provider'] ?? ''));

        $knowledge_state = BotSettingsManager::get_knowledge_capability_state(
            $main_provider,
            $knowledge_provider,
            $knowledge_enabled ? '1' : '0'
        );

        if ($knowledge_state['effective'] && self::has_knowledge_upload_route($knowledge_provider, $settings)) {
            return $knowledge_provider;
        }

        return self::resolve_native_provider($main_provider);
    }

    /**
     * @param array<string, mixed> $settings Chatbot settings.
     */
    private static function has_knowledge_upload_route(string $provider, array $settings): bool
    {
        if (in_array($provider, self::VECTOR_CONTEXT_PROVIDERS, true)) {
            return $provider === 'openai' || self::has_vector_target($provider, $settings);
        }

        return in_array($provider, ['google', 'claude_files'], true);
    }

    /**
     * @param array<string, mixed> $settings Chatbot settings.
     */
    private static function has_vector_target(string $provider, array $settings): bool
    {
        $embedding_provider = trim((string) ($settings['vector_embedding_provider'] ?? ''));
        $embedding_model = trim((string) ($settings['vector_embedding_model'] ?? ''));
        if ($embedding_provider === '' || $embedding_model === '') {
            return false;
        }

        return self::vector_target($provider, $settings) !== '';
    }

    /** Use the first selected store for temporary uploads; retrieval can still search all selected stores. */
    public static function vector_target(string $provider, array $settings): string
    {
        if ($provider === 'pinecone') { return trim((string) ($settings['pinecone_index_name'] ?? '')); }

        $singular_key = $provider === 'local' ? 'local_store_id' : $provider . '_collection_name';
        if (trim((string) ($settings[$singular_key] ?? '')) !== '') {
            return trim((string) $settings[$singular_key]);
        }

        $plural_key = $provider === 'local' ? 'local_store_ids' : $provider . '_collection_names';
        $targets = isset($settings[$plural_key]) && is_array($settings[$plural_key])
            ? $settings[$plural_key]
            : [];

        $targets = array_values(array_filter(array_map('strval', $targets)));
        return $targets[0] ?? '';
    }

    private static function resolve_native_provider(string $main_provider): string
    {
        $native_providers = [
            'openai' => 'openai',
            'google' => 'google',
            'claude' => 'claude_files',
        ];

        return $native_providers[$main_provider] ?? '';
    }
}
