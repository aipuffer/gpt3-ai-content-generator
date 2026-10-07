<?php

namespace WPAICG\Vector;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Splits source text into embedding-safe chunks for external vector stores.
 */
class AIPKit_Vector_Text_Chunker
{
    private const DEFAULT_AVG_CHARS_PER_TOKEN = 4;
    private const DEFAULT_MAX_TOKENS_PER_CHUNK = 3000;
    private const DEFAULT_OVERLAP_TOKENS = 150;
    private const MAX_AVG_CHARS_PER_TOKEN = 4;
    private const OPENAI_SAFE_MAX_CHARS_PER_CHUNK = 6000;
    private const CHROMA_SAFE_MAX_CHARS_PER_CHUNK = 5000;
    private const OLLAMA_SAFE_MAX_CHARS_PER_CHUNK = 2000;
    private const MXBAI_SAFE_MAX_CHARS_PER_CHUNK = 384;
    // AI Puffer Cloud accepts 8,000 bytes per text; UTF-8 is at most 4 bytes per character.
    private const CLOUD_MAX_CHARS_PER_CHUNK = 2000;
    private const MAX_OVERLAP_RATIO = 0.25;
    private const DEFAULT_MAX_CHUNKS = 2000;

    /**
     * @return array<int, array{text:string,start:int,end:int,index:int}>
     */
    public static function chunk_for_embeddings(
        string $text,
        string $embedding_model,
        string $embedding_provider_key,
        string $target_id,
        string $vector_store_type
    ): array {
        $default_chunk_cfg = [
            'avg_chars_per_token' => self::DEFAULT_AVG_CHARS_PER_TOKEN,
            'max_tokens_per_chunk' => self::DEFAULT_MAX_TOKENS_PER_CHUNK,
            'overlap_tokens' => self::DEFAULT_OVERLAP_TOKENS,
        ];
        if (self::can_use_custom_settings()) {
            $settings_path = WPAICG_PLUGIN_DIR . 'lib/knowledge-base/ingestion-settings.php';
            if (file_exists($settings_path)) {
                require_once $settings_path;
                $default_chunk_cfg = \WPAICG\Lib\VectorStores\FileUpload\AIPKit_Vector_File_Ingestion_Batch_Settings::apply_chunking_defaults($default_chunk_cfg);
            }
        }

        $chunk_cfg = apply_filters(
            'aipkit_vector_chunking_config',
            $default_chunk_cfg,
            $embedding_model,
            $embedding_provider_key,
            $target_id,
            $vector_store_type
        );
        $chunk_cfg = is_array($chunk_cfg) ? $chunk_cfg : $default_chunk_cfg;

        $avg_chars_per_token = isset($chunk_cfg['avg_chars_per_token']) ? max(1, (int) $chunk_cfg['avg_chars_per_token']) : $default_chunk_cfg['avg_chars_per_token'];
        $max_tokens_per_chunk = isset($chunk_cfg['max_tokens_per_chunk']) ? max(1, (int) $chunk_cfg['max_tokens_per_chunk']) : $default_chunk_cfg['max_tokens_per_chunk'];
        $overlap_tokens = isset($chunk_cfg['overlap_tokens']) ? max(0, (int) $chunk_cfg['overlap_tokens']) : $default_chunk_cfg['overlap_tokens'];

        $limits = self::get_embedding_chunk_limits(
            $embedding_provider_key,
            $embedding_model,
            $target_id,
            $vector_store_type
        );
        $max_avg_chars_per_token = max(1, (int) ($limits['max_avg_chars_per_token'] ?? self::MAX_AVG_CHARS_PER_TOKEN));
        $max_tokens_limit = max(1, (int) ($limits['max_tokens_per_chunk'] ?? self::DEFAULT_MAX_TOKENS_PER_CHUNK));
        $max_chars_limit = isset($limits['max_chars_per_chunk']) ? max(1, (int) $limits['max_chars_per_chunk']) : null;
        $max_chunks = isset($limits['max_chunks']) ? max(1, (int) $limits['max_chunks']) : self::DEFAULT_MAX_CHUNKS;

        $avg_chars_per_token = min($avg_chars_per_token, $max_avg_chars_per_token);
        $max_tokens_per_chunk = min($max_tokens_per_chunk, $max_tokens_limit);
        $overlap_tokens = min($overlap_tokens, max(0, $max_tokens_per_chunk - 1));

        $chunk_chars = max(1, $max_tokens_per_chunk * $avg_chars_per_token);
        if ($max_chars_limit !== null) {
            $chunk_chars = min($chunk_chars, $max_chars_limit);
        }

        $overlap_chars = max(0, $overlap_tokens * $avg_chars_per_token);
        $max_overlap_chars = max(0, (int) floor($chunk_chars * self::MAX_OVERLAP_RATIO));
        $overlap_chars = min($overlap_chars, $max_overlap_chars);
        $step = max(1, $chunk_chars - $overlap_chars);
        $content_len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);

        if ($content_len <= 0) {
            return [];
        }

        if ($content_len <= $chunk_chars) {
            return [[
                'text' => $text,
                'start' => 0,
                'end' => $content_len,
                'index' => 0,
            ]];
        }

        $chunks = [];
        for ($start = 0, $idx = 0; $start < $content_len && $idx < $max_chunks; $start += $step, $idx++) {
            $chunk = function_exists('mb_substr')
                ? mb_substr($text, $start, $chunk_chars)
                : (string) substr($text, $start, $chunk_chars);

            if ($chunk === '' || $chunk === false) {
                break;
            }

            $chunks[] = [
                'text' => $chunk,
                'start' => $start,
                'end' => min($start + $chunk_chars, $content_len),
                'index' => $idx,
            ];
            if ($start + $chunk_chars >= $content_len) {
                break; // The remaining overlap is already covered by this final chunk.
            }
        }

        return $chunks;
    }

    /**
     * @return array{max_tokens_per_chunk:int,max_avg_chars_per_token:int,max_chars_per_chunk?:int,max_chunks?:int}
     */
    private static function get_embedding_chunk_limits(
        string $embedding_provider_key,
        string $embedding_model,
        string $target_id,
        string $vector_store_type
    ): array {
        $provider = strtolower(trim($embedding_provider_key));
        $model = strtolower(trim($embedding_model));
        $max_tokens = self::DEFAULT_MAX_TOKENS_PER_CHUNK;
        $max_chars = null;

        if (in_array($provider, ['openai', 'azure'], true)) {
            $max_tokens = 6000;
            $max_chars = self::OPENAI_SAFE_MAX_CHARS_PER_CHUNK;
        } elseif ($provider === 'google') {
            $max_tokens = 1500;
        } elseif ($provider === 'openrouter') {
            if (strpos($model, 'gemini') !== false || strpos($model, 'google') !== false) {
                $max_tokens = 1500;
            } elseif (strpos($model, 'text-embedding') !== false || strpos($model, 'openai') !== false) {
                $max_tokens = 6000;
                $max_chars = self::OPENAI_SAFE_MAX_CHARS_PER_CHUNK;
            }
        } elseif ($provider === 'ollama') {
            $max_tokens = 2000;
            $max_chars = self::OLLAMA_SAFE_MAX_CHARS_PER_CHUNK;
        } elseif ($provider === 'aipuffercloud') {
            $max_tokens = 500;
            $max_chars = self::CLOUD_MAX_CHARS_PER_CHUNK;
        }

        if (strpos($model, 'mxbai-embed-large') !== false) {
            $max_tokens = min($max_tokens, 512);
            $max_chars = $max_chars !== null
                ? min($max_chars, self::MXBAI_SAFE_MAX_CHARS_PER_CHUNK)
                : self::MXBAI_SAFE_MAX_CHARS_PER_CHUNK;
        }

        // Built-in store: small chunks give precise matches and keep the context added to each reply short.
        if (strtolower(trim($vector_store_type)) === 'local') {
            $max_tokens = min($max_tokens, 400);
            $max_chars = $max_chars !== null ? min($max_chars, 1600) : 1600;
        }

        if (strtolower(trim($vector_store_type)) === 'chroma') {
            $max_chars = $max_chars !== null
                ? min($max_chars, self::CHROMA_SAFE_MAX_CHARS_PER_CHUNK)
                : self::CHROMA_SAFE_MAX_CHARS_PER_CHUNK;
        }

        $limits = [
            'max_tokens_per_chunk' => $max_tokens,
            'max_avg_chars_per_token' => self::MAX_AVG_CHARS_PER_TOKEN,
            'max_chunks' => self::DEFAULT_MAX_CHUNKS,
        ];
        if ($max_chars !== null) {
            $limits['max_chars_per_chunk'] = $max_chars;
        }

        $filtered = apply_filters(
            'aipkit_vector_embedding_chunk_limits',
            $limits,
            $embedding_provider_key,
            $embedding_model,
            $target_id,
            $vector_store_type
        );

        if (!is_array($filtered)) {
            return $limits;
        }

        $normalized = [
            'max_tokens_per_chunk' => isset($filtered['max_tokens_per_chunk']) ? max(1, (int) $filtered['max_tokens_per_chunk']) : $limits['max_tokens_per_chunk'],
            'max_avg_chars_per_token' => isset($filtered['max_avg_chars_per_token']) ? max(1, (int) $filtered['max_avg_chars_per_token']) : $limits['max_avg_chars_per_token'],
            'max_chunks' => isset($filtered['max_chunks']) ? max(1, (int) $filtered['max_chunks']) : $limits['max_chunks'],
        ];
        if (isset($filtered['max_chars_per_chunk'])) {
            $normalized['max_chars_per_chunk'] = max(1, (int) $filtered['max_chars_per_chunk']);
        } elseif (isset($limits['max_chars_per_chunk'])) {
            $normalized['max_chars_per_chunk'] = $limits['max_chars_per_chunk'];
        }
        // A filter may not raise Cloud chunks past what Cloud accepts.
        if ($provider === 'aipuffercloud') {
            $normalized['max_chars_per_chunk'] = min($normalized['max_chars_per_chunk'] ?? self::CLOUD_MAX_CHARS_PER_CHUNK, self::CLOUD_MAX_CHARS_PER_CHUNK);
        }

        return $normalized;
    }

    private static function can_use_custom_settings(): bool
    {
        return class_exists('\\WPAICG\\aipkit_dashboard')
            && \WPAICG\aipkit_dashboard::is_pro_plan();
    }
}
