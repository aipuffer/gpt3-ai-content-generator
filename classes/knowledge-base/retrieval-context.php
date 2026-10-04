<?php

/**
 * Shared knowledge retrieval and provider-native file search for AI requests.
 */
namespace WPAICG\Core\Stream\Vector;

use WPAICG\Core\AIPKit_AI_Caller;
use WPAICG\Vector\AIPKit_Vector_Store_Manager;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Builds the vector search results context string.
 *
 * @param \WPAICG\Core\AIPKit_AI_Caller $ai_caller Instance of AI Caller.
 * @param \WPAICG\Vector\AIPKit_Vector_Store_Manager $vector_store_manager Instance of Vector Store Manager.
 * @param string $user_message The user's current message.
 * @param array  $bot_settings The settings of the current bot.
 * @param string $main_provider The main AI provider being used for the chat.
 * @param string|null $frontend_active_openai_vs_id Optional active OpenAI Vector Store ID from frontend.
 * @param string|null $frontend_active_pinecone_index_name Optional active Pinecone index name from frontend.
 * @param string|null $frontend_active_pinecone_namespace Optional active Pinecone namespace from frontend.
 * @param string|null $frontend_active_qdrant_collection_name Optional active Qdrant collection name.
 * @param string|null $frontend_active_qdrant_file_upload_context_id Optional active Qdrant file context ID.
 * @param string|null $frontend_active_chroma_collection_name Optional active Chroma collection name.
 * @param string|null $frontend_active_chroma_file_upload_context_id Optional active Chroma file context ID.
 * @param array|null &$vector_search_scores_output Optional reference to capture vector search scores for logging.
 * @return string|\WP_Error Context text, an empty result, or the retrieval error.
 */
function build_vector_search_context_logic(
    AIPKit_AI_Caller $ai_caller,
    AIPKit_Vector_Store_Manager $vector_store_manager,
    string $user_message,
    array $bot_settings,
    string $main_provider,
    ?string $frontend_active_openai_vs_id = null,
    ?string $frontend_active_pinecone_index_name = null,
    ?string $frontend_active_pinecone_namespace = null,
    ?string $frontend_active_qdrant_collection_name = null,
    ?string $frontend_active_qdrant_file_upload_context_id = null,
    ?string $frontend_active_chroma_collection_name = null,
    ?string $frontend_active_chroma_file_upload_context_id = null,
    ?array &$vector_search_scores_output = null
) {
    global $wpdb;
    $data_source_table_name = $wpdb->prefix . 'aipkit_vector_data_source';

    $vector_store_enabled = ($bot_settings['enable_vector_store'] ?? '0') === '1';

    if (!BuildContext\check_prerequisites_logic($vector_store_enabled, $user_message, $ai_caller, $vector_store_manager)) {
        return "";
    }

    $all_formatted_results = "";
    $vector_provider_from_bot = $bot_settings['vector_store_provider'] ?? '';
    $vector_top_k = absint($bot_settings['vector_store_top_k'] ?? 3);
    $vector_top_k = max(1, min($vector_top_k, 20));

    // Initialize scores output array if reference provided
    if ($vector_search_scores_output !== null) {
        $vector_search_scores_output = [];
    }

    if ($vector_provider_from_bot === 'openai') {
        $openai_results = BuildContext\resolve_openai_context_logic(
            $vector_store_manager,
            $user_message,
            $bot_settings,
            $main_provider,
            $frontend_active_openai_vs_id,
            $vector_top_k,
            $vector_search_scores_output
        );
        $all_formatted_results = $openai_results;
    } elseif ($vector_provider_from_bot === 'pinecone') {
        $pinecone_results = BuildContext\resolve_pinecone_context_logic(
            $ai_caller,
            $vector_store_manager,
            $user_message,
            $bot_settings,
            $frontend_active_pinecone_index_name,
            $frontend_active_pinecone_namespace,
            $vector_top_k,
            $wpdb,
            $data_source_table_name,
            $vector_search_scores_output
        );
        $all_formatted_results = $pinecone_results;
    } elseif ($vector_provider_from_bot === 'qdrant') {
        $qdrant_results = BuildContext\resolve_qdrant_context_logic(
            $ai_caller,
            $vector_store_manager,
            $user_message,
            $bot_settings,
            $frontend_active_qdrant_collection_name,
            $frontend_active_qdrant_file_upload_context_id,
            $vector_top_k,
            $wpdb,
            $data_source_table_name,
            $vector_search_scores_output
        );
        $all_formatted_results = $qdrant_results;
    } elseif ($vector_provider_from_bot === 'local') {
        $all_formatted_results = BuildContext\resolve_local_context_logic(
            $ai_caller,
            $vector_store_manager,
            $user_message,
            $bot_settings,
            $bot_settings['active_local_file_upload_context_id'] ?? null,
            $vector_top_k,
            $vector_search_scores_output
        );
    } elseif ($vector_provider_from_bot === 'chroma') {
        $chroma_results = BuildContext\resolve_chroma_context_logic(
            $ai_caller,
            $vector_store_manager,
            $user_message,
            $bot_settings,
            $frontend_active_chroma_collection_name,
            $frontend_active_chroma_file_upload_context_id,
            $vector_top_k,
            $wpdb,
            $data_source_table_name,
            $vector_search_scores_output
        );
        $all_formatted_results = $chroma_results;
    }

    return is_wp_error($all_formatted_results) ? $all_formatted_results : trim($all_formatted_results);
}

/**
 * Prepare vector context for a standard AI call.
 * - Optionally builds vector context text (OpenAI cross-provider queries, Pinecone, Qdrant and Chroma) and prefixes system instruction.
 * - Collects vector_search_scores for logging and returns them both in ai_params (for payload parity) and instruction_context (for top-level log surfacing).
 * - Configures provider-native file search for OpenAI and Google.
 *
 * @param object|null $ai_caller              The AI caller instance.
 * @param object|null $vector_store_manager   The vector store manager instance.
 * @param string      $user_message           The user prompt content for the call.
 * @param array       $form_data              The POST/form data containing vector settings.
 * @param string      $provider               Provider canonical name (e.g., 'OpenAI', 'Google').
 * @param string      $base_system_instruction The base system instruction to augment.
 * @param array       $existing_ai_params     Existing ai_params to extend.
 * @return array|\WP_Error { system_instruction: string, ai_params: array, instruction_context: array }
 */
function prepare_vector_standard_call(
    $ai_caller,
    $vector_store_manager,
    string $user_message,
    array $form_data,
    string $provider,
    string $base_system_instruction,
    array $existing_ai_params = []
) {
    $system_instruction = $base_system_instruction;
    $ai_params = $existing_ai_params;
    $instruction_context = [];

    $is_vector_enabled = ($form_data['enable_vector_store'] ?? '0') === '1';
    if (!$is_vector_enabled || !$ai_caller || !$vector_store_manager) {
        return [
            'system_instruction' => $system_instruction,
            'ai_params' => $ai_params,
            'instruction_context' => $instruction_context,
        ];
    }

    $collected_vector_search_scores = [];
    $vector_context = build_vector_search_context_logic(
        $ai_caller,
        $vector_store_manager,
        $user_message,
        $form_data,
        $provider,
        null, // active OpenAI VS id (none from CW UI)
        $form_data['pinecone_index_name'] ?? null,
        null, // pinecone namespace (optional)
        $form_data['qdrant_collection_name'] ?? null,
        null, // qdrant file upload context id (optional)
        $form_data['chroma_collection_name'] ?? null,
        null, // chroma file upload context id (optional)
        $collected_vector_search_scores
    );

    if (is_wp_error($vector_context)) { return $vector_context; }
    if (!empty($vector_context)) {
        $system_instruction = $vector_context . "\n\n---\n\n" . $system_instruction;
    }
    if (!empty($collected_vector_search_scores)) {
        // Attach for payload parity and top-level surfacing via instruction_context
        $ai_params['vector_search_scores'] = $collected_vector_search_scores;
        $instruction_context['vector_search_scores'] = $collected_vector_search_scores;
    }

    // Configure OpenAI file_search tool when applicable
    $vector_provider = $form_data['vector_store_provider'] ?? 'openai';
    if ($provider === 'OpenAI' && $vector_provider === 'openai') {
        $openai_vs_ids = $form_data['openai_vector_store_ids'] ?? [];
        if (!empty($openai_vs_ids) && is_array($openai_vs_ids)) {
            $vector_top_k = isset($form_data['vector_store_top_k']) ? absint($form_data['vector_store_top_k']) : 3;
            $vector_top_k = max(1, min($vector_top_k, 20));
            $confidence_threshold_percent = (int)($form_data['vector_store_confidence_threshold'] ?? 20);
            if ($confidence_threshold_percent <= 0) {
                $openai_score_threshold = 0.0;
            } elseif ($confidence_threshold_percent >= 100) {
                $openai_score_threshold = 1.0;
            } else {
                $openai_score_threshold = round($confidence_threshold_percent / 100, 6);
            }
            $ai_params['vector_store_tool_config'] = [
                'type' => 'file_search',
                'vector_store_ids' => $openai_vs_ids,
                'max_num_results' => $vector_top_k,
                'ranking_options' => [
                    'score_threshold' => $openai_score_threshold,
                ],
            ];
        }
    } elseif ($provider === 'Google' && $vector_provider === 'google') {
        $google_store_names = $form_data['google_file_search_store_names'] ?? [];
        if (!empty($google_store_names) && is_array($google_store_names)) {
            $vector_top_k = isset($form_data['vector_store_top_k']) ? absint($form_data['vector_store_top_k']) : 3;
            $ai_params['google_file_search_tool_config'] = [
                'file_search_store_names' => array_values(array_unique(array_filter(array_map('sanitize_text_field', $google_store_names)))),
                'top_k' => max(1, min($vector_top_k, 20)),
            ];
        }
    }

    return [
        'system_instruction' => $system_instruction,
        'ai_params' => $ai_params,
        'instruction_context' => $instruction_context,
    ];
}

namespace WPAICG\Core\Stream\Vector\BuildContext;

use WPAICG\AIPKit_Providers;
use WPAICG\Core\AIPKit_AI_Caller;
use WP_Error;
use WPAICG\Vector\AIPKit_Vector_Store_Manager;
use WPAICG\Vector\AIPKit_Vector_Store_Registry;

/**
 * Checks the prerequisites for building vector search context.
 *
 * @param bool $vector_store_enabled
 * @param string $user_message
 * @param \WPAICG\Core\AIPKit_AI_Caller|null $ai_caller
 * @param \WPAICG\Vector\AIPKit_Vector_Store_Manager|null $vector_store_manager
 * @return bool True if prerequisites are met, false otherwise.
 */
function check_prerequisites_logic(
    bool $vector_store_enabled,
    string $user_message,
    ?\WPAICG\Core\AIPKit_AI_Caller $ai_caller,
    ?\WPAICG\Vector\AIPKit_Vector_Store_Manager $vector_store_manager
): bool {
    if (!$vector_store_enabled || empty($user_message)) {
        return false;
    }

    if (!$ai_caller || !$vector_store_manager) {
        return false;
    }
    return true;
}

/**
 * Normalizes the embedding provider key to a standard name.
 *
 * @param string $embedding_provider_key The key from settings (e.g., 'openai', 'google').
 * @return string The normalized provider name (e.g., 'OpenAI', 'Google').
 */
function normalize_embedding_provider_logic(string $embedding_provider_key): string
{
    $provider_lookup = sanitize_key((string) strtolower($embedding_provider_key));

    return AIPKit_Providers::normalize_embedding_provider_name(
        $provider_lookup,
        'stream_vector_build_context'
    );
}

/**
 * Generates an embedding vector for the given user message using the specified provider and model.
 *
 * @param \WPAICG\Core\AIPKit_AI_Caller $ai_caller
 * @param string $user_message
 * @param string $embedding_provider_normalized
 * @param string $embedding_model
 * @return array|WP_Error The embedding vector values or WP_Error on failure.
 */
function resolve_embedding_vector_logic(
    AIPKit_AI_Caller $ai_caller,
    string $user_message,
    string $embedding_provider_normalized,
    string $embedding_model,
    string $store_provider,
    array $store_targets
) {
    $config = $store_provider === 'Local' ? [] : AIPKit_Providers::get_provider_data($store_provider);
    $embedding_options = (new AIPKit_Vector_Store_Manager())->embedding_options($embedding_provider_normalized, $embedding_model, $store_provider, $store_targets, $config);
    if (is_wp_error($embedding_options)) { return $embedding_options; }
    $embedding_result = $ai_caller->generate_embeddings($embedding_provider_normalized, $user_message, $embedding_options);

    if (is_wp_error($embedding_result)) { return $embedding_result; }
    if (empty($embedding_result['embeddings'][0])) {
        return new WP_Error('embedding_failed_for_query', __('No embedding was returned for the knowledge search.', 'gpt3-ai-content-generator'));
    }

    return $embedding_result['embeddings'][0];
}

/**
 * Adds optional vector metadata to a score item before it is stored in logs.
 *
 * @param array<string,mixed> $score_item
 * @param array<string,mixed> $metadata
 * @return array<string,mixed>
 */
function build_vector_search_score_item_logic(array $score_item, array $metadata = []): array
{
    $chunk_number = null;
    $chunk_index = null;

    if (isset($metadata['chunk_number']) && is_numeric($metadata['chunk_number'])) {
        $chunk_number = max(1, (int) $metadata['chunk_number']);
        $chunk_index = $chunk_number - 1;
    } elseif (isset($metadata['chunk_index']) && is_numeric($metadata['chunk_index'])) {
        $chunk_index = max(0, (int) $metadata['chunk_index']);
        $chunk_number = $chunk_index + 1;
    }

    $total_chunks = isset($metadata['total_chunks']) && is_numeric($metadata['total_chunks'])
        ? (int) $metadata['total_chunks']
        : null;

    if ($chunk_number !== null && $total_chunks !== null && $total_chunks > 0) {
        $score_item['chunk_index'] = $chunk_index;
        $score_item['chunk_number'] = $chunk_number;
        $score_item['total_chunks'] = $total_chunks;
    }

    foreach (['original_filename', 'filename'] as $file_key) {
        if (!empty($metadata[$file_key]) && is_scalar($metadata[$file_key])) {
            $score_item['file_name'] = sanitize_text_field((string) $metadata[$file_key]);
            break;
        }
    }

    if (isset($metadata['char_start']) && is_numeric($metadata['char_start'])) {
        $score_item['char_start'] = (int) $metadata['char_start'];
    }
    if (isset($metadata['char_end']) && is_numeric($metadata['char_end'])) {
        $score_item['char_end'] = (int) $metadata['char_end'];
    }

    return $score_item;
}

/**
 * Resolves OpenAI vector search context.
 *
 * @param AIPKit_Vector_Store_Manager $vector_store_manager Instance of Vector Store Manager.
 * @param string $user_message The user's current message.
 * @param array $bot_settings The settings of the current bot.
 * @param string $main_provider The main AI provider being used for the chat.
 * @param string|null $frontend_active_openai_vs_id Optional active OpenAI Vector Store ID from frontend.
 * @param int $vector_top_k Number of results to fetch.
 * @param array|null &$vector_search_scores_output Optional reference to capture scores for logging.
 * @return string|WP_Error Formatted OpenAI context results.
 */
function resolve_openai_context_logic(
    AIPKit_Vector_Store_Manager $vector_store_manager,
    string $user_message,
    array $bot_settings,
    string $main_provider,
    ?string $frontend_active_openai_vs_id,
    int $vector_top_k,
    ?array &$vector_search_scores_output = null
) {
    // For OpenAI main provider, use File Search tool instead of prompt injection.
    // For non-OpenAI providers, inject a concise context string from OpenAI vector stores.
    $openai_results = "";
    $should_inject_context = ($main_provider !== 'OpenAI');

    if (!$should_inject_context) {
        // OpenAI chat requests attach the file_search tool elsewhere; pre-searching here duplicates retrieval and adds latency.
        return "";
    }

    $openai_vector_store_ids_from_settings = $bot_settings['openai_vector_store_ids'] ?? [];
    $confidence_threshold_percent = (int)($bot_settings['vector_store_confidence_threshold'] ?? 20);
    $openai_score_threshold = round($confidence_threshold_percent / 100, 4); // Convert to 0.0-1.0 scale for OpenAI and round to avoid precision issues

    $final_openai_vector_store_ids = $openai_vector_store_ids_from_settings;
    if ($frontend_active_openai_vs_id && !in_array($frontend_active_openai_vs_id, $final_openai_vector_store_ids, true)) {
        $final_openai_vector_store_ids[] = $frontend_active_openai_vs_id;
    }
    $final_openai_vector_store_ids = array_unique(array_filter($final_openai_vector_store_ids));

    if (!empty($final_openai_vector_store_ids)) {
        if (!class_exists(AIPKit_Providers::class)) {
            $providers_path = WPAICG_PLUGIN_DIR . 'classes/ai/settings.php';
            if (file_exists($providers_path)) {
                require_once $providers_path;
            } else {
                return "";
            }
        }
        $openai_api_config = AIPKit_Providers::get_provider_data('OpenAI');
        if (empty($openai_api_config['api_key'])) {
            return new WP_Error('knowledge_provider_not_configured', __('Connect OpenAI to search the selected knowledge store.', 'gpt3-ai-content-generator'));
        }
        foreach ($final_openai_vector_store_ids as $current_vs_id) {
            if (empty($current_vs_id)) {
                continue;
            }

            // Add ranking_options to the search query for OpenAI server-side filtering
            $search_query_vector = [
                'query_text' => $user_message,
                'ranking_options' => [
                    'score_threshold' => $openai_score_threshold
                ]
            ];

            $search_results = $vector_store_manager->query_vectors('OpenAI', $current_vs_id, $search_query_vector, $vector_top_k, [], $openai_api_config);

            if (is_wp_error($search_results)) { return $search_results; }
            if (!empty($search_results)) {
                $current_store_results = "";
                foreach ($search_results as $item) {
                    if (isset($item['score']) && (float)$item['score'] < $openai_score_threshold) {
                        continue;
                    }

                    if (!empty($item['content'])) {
                        $textContent = is_array($item['content']) ? implode(" ", array_column(array_filter($item['content'], fn ($p) => $p['type'] === 'text'), 'text')) : $item['content'];
                        if (!empty(trim($textContent))) {
                            if ($should_inject_context) {
                                $current_store_results .= "- " . trim($textContent) . "\n";
                            }

                            // Capture score data if reference provided
                            if ($vector_search_scores_output !== null && isset($item['score'])) {
                                // Get store name from registry
                                $store_name = $current_vs_id; // fallback to ID
                                $openai_stores = AIPKit_Vector_Store_Registry::get_registered_stores_by_provider('OpenAI');
                                foreach ($openai_stores as $store) {
                                    if (isset($store['id']) && $store['id'] === $current_vs_id) {
                                        $store_name = $store['name'] ?? $current_vs_id;
                                        break;
                                    }
                                }

                                $vector_search_scores_output[] = [
                                    'provider' => 'OpenAI',
                                    'store_id' => $current_vs_id,
                                    'store_name' => $store_name,
                                    'result_id' => $item['id'] ?? null,
                                    'score' => $item['score'],
                                    'content_preview' => wp_trim_words(trim($textContent), 10, '...')
                                ];
                            }
                        }
                    }
                }
                if ($should_inject_context && !empty($current_store_results)) {
                    $store_label = sanitize_text_field($store_name ?? $current_vs_id);
                    $openai_results .= "Context from OpenAI Vector Store ({$store_label}):\n" . $current_store_results . "\n";
                }
            }
        }
    }
    return $openai_results;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Pinecone context resolution only reads plugin-owned vector log tables with prepared scalar values.

/**
 * Resolves Pinecone vector search context.
 *
 * @param AIPKit_AI_Caller $ai_caller Instance of AI Caller.
 * @param AIPKit_Vector_Store_Manager $vector_store_manager Instance of Vector Store Manager.
 * @param string $user_message The user's current message.
 * @param array $bot_settings The settings of the current bot.
 * @param string|null $frontend_active_pinecone_index_name Optional active Pinecone index name from frontend.
 * @param string|null $frontend_active_pinecone_namespace Optional active Pinecone namespace from frontend.
 * @param int $vector_top_k Number of results to fetch.
 * @param \wpdb $wpdb WordPress database instance.
 * @param string $data_source_table_name Vector data source table name.
 * @param array|null &$vector_search_scores_output Optional reference to capture scores for logging.
 * @return string|WP_Error Formatted Pinecone context results.
 */
function resolve_pinecone_context_logic(
    AIPKit_AI_Caller $ai_caller,
    AIPKit_Vector_Store_Manager $vector_store_manager,
    string $user_message,
    array $bot_settings,
    ?string $frontend_active_pinecone_index_name,
    ?string $frontend_active_pinecone_namespace,
    int $vector_top_k,
    \wpdb $wpdb,
    string $data_source_table_name,
    ?array &$vector_search_scores_output = null
) {
    $pinecone_results = "";
    $pinecone_index_name_from_settings = $bot_settings['pinecone_index_name'] ?? '';
    $vector_embedding_provider = $bot_settings['vector_embedding_provider'] ?? '';
    $vector_embedding_model = $bot_settings['vector_embedding_model'] ?? '';
    $confidence_threshold_percent = (int)($bot_settings['vector_store_confidence_threshold'] ?? 20);
    $pinecone_score_threshold = round($confidence_threshold_percent / 100, 4); // Normalize 0-100 to 0-1 scale and round to avoid precision issues

    if (empty($pinecone_index_name_from_settings) || empty($vector_embedding_provider) || empty($vector_embedding_model)) {
        return "";
    }

    if (!class_exists(AIPKit_Providers::class)) {
        $providers_path = WPAICG_PLUGIN_DIR . 'classes/ai/settings.php';
        if (file_exists($providers_path)) {
            require_once $providers_path;
        } else {
            return "";
        }
    }
    $pinecone_api_config = AIPKit_Providers::get_provider_data('Pinecone');
    if (empty($pinecone_api_config['api_key'])) {
        return new WP_Error('knowledge_provider_not_configured', __('Connect Pinecone to search the selected knowledge store.', 'gpt3-ai-content-generator'));
    }

    $embedding_provider_normalized = normalize_embedding_provider_logic($vector_embedding_provider);
    $query_vector_values_or_error = resolve_embedding_vector_logic($ai_caller, $user_message, $embedding_provider_normalized, $vector_embedding_model, 'Pinecone', [$pinecone_index_name_from_settings]);

    if (is_wp_error($query_vector_values_or_error)) {
        return $query_vector_values_or_error;
    }
    $query_vector_values = $query_vector_values_or_error;

    $index_to_query = $pinecone_index_name_from_settings;
    $pinecone_results_this_pass = "";

    // 1. Search with file-specific namespace if provided
    if (!empty($frontend_active_pinecone_namespace)) {
        $query_vector_for_file_context = ['vector' => $query_vector_values, 'namespace' => $frontend_active_pinecone_namespace];
        $file_search_results = $vector_store_manager->query_vectors('Pinecone', $index_to_query, $query_vector_for_file_context, $vector_top_k, [], $pinecone_api_config);
        if (is_wp_error($file_search_results)) { return $file_search_results; }
        if (!empty($file_search_results)) {
            $formatted_file_results = "";
            foreach ($file_search_results as $item) {
                if (isset($item['score']) && (float)$item['score'] < $pinecone_score_threshold) {
                    continue;
                }

                $metadata = isset($item['metadata']) && is_array($item['metadata']) ? $item['metadata'] : [];
                $content_snippet = $metadata['original_content'] ?? ($metadata['text_content'] ?? null);
                if (empty($content_snippet) && isset($item['id'])) {
                    $cache_key = 'aipkit_vds_content_' . md5('pinecone_file_' . $index_to_query . $frontend_active_pinecone_namespace . $item['id']);
                    $cache_group = 'aipkit_vector_source_content';
                    $log_entry = wp_cache_get($cache_key, $cache_group);

                    if (false === $log_entry) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reason: Direct query to a custom table. Caches will be invalidated.
                        $log_entry = $wpdb->get_row($wpdb->prepare("SELECT indexed_content FROM {$data_source_table_name} WHERE provider = %s AND vector_store_id = %s AND batch_id = %s AND file_id = %s ORDER BY timestamp DESC LIMIT 1", 'Pinecone', $index_to_query, $frontend_active_pinecone_namespace, $item['id']), ARRAY_A);
                        wp_cache_set($cache_key, $log_entry, $cache_group, HOUR_IN_SECONDS);
                    }

                    if ($log_entry && !empty($log_entry['indexed_content'])) {
                        $content_snippet = $log_entry['indexed_content'];
                    }
                }
                if (!empty($content_snippet)) {
                    $formatted_file_results .= "- " . trim($content_snippet) . "\n";

                    // Capture score data if reference provided
                    if ($vector_search_scores_output !== null && isset($item['score'])) {
                        $vector_search_scores_output[] = build_vector_search_score_item_logic(
                            [
                                'provider' => 'Pinecone',
                                'index_name' => $index_to_query,
                                'namespace' => $frontend_active_pinecone_namespace,
                                'result_id' => $item['id'] ?? null,
                                'score' => $item['score'],
                                'content_preview' => wp_trim_words(trim($content_snippet), 10, '...')
                            ],
                            $metadata
                        );
                    }
                }
            }
            if (!empty($formatted_file_results)) {
                $pinecone_results_this_pass .= "Context from Uploaded File (Index {$index_to_query}, Namespace: {$frontend_active_pinecone_namespace}):\n" . $formatted_file_results . "\n";
            }
        }
    }

    // 2. Search general bot knowledge (default/empty namespace)
    $query_vector_for_general_context = ['vector' => $query_vector_values]; // No namespace implies default
    $general_search_results = $vector_store_manager->query_vectors('Pinecone', $index_to_query, $query_vector_for_general_context, $vector_top_k, [], $pinecone_api_config);
    if (is_wp_error($general_search_results)) { return $general_search_results; }
    if (!empty($general_search_results)) {
        $formatted_general_results = "";
        foreach ($general_search_results as $item) {
            if (isset($item['score']) && (float)$item['score'] < $pinecone_score_threshold) {
                continue;
            }

            // Skip if this result was already part of the file-specific context (if a namespace was used)
            $metadata = isset($item['metadata']) && is_array($item['metadata']) ? $item['metadata'] : [];
            if (!empty($frontend_active_pinecone_namespace) && ($metadata['namespace'] ?? null) === $frontend_active_pinecone_namespace) {
                continue;
            }
            $content_snippet = $metadata['original_content'] ?? ($metadata['text_content'] ?? null);
            if (empty($content_snippet) && isset($item['id'])) {
                $cache_key = 'aipkit_vds_content_' . md5('pinecone_general_' . $index_to_query . $item['id']);
                $cache_group = 'aipkit_vector_source_content';
                $log_entry = wp_cache_get($cache_key, $cache_group);

                if (false === $log_entry) {
                    // Preferred query for legacy general records (no batch)
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query to custom table
                    $log_entry = $wpdb->get_row($wpdb->prepare(
                        "SELECT indexed_content FROM {$data_source_table_name} WHERE provider = 'Pinecone' AND vector_store_id = %s AND (batch_id IS NULL OR batch_id = '') AND file_id = %s ORDER BY timestamp DESC LIMIT 1",
                        $index_to_query,
                        $item['id']
                    ), ARRAY_A);

                    // Fallback: allow any batch_id (covers file uploads where batch_id is set)
                    if (!$log_entry) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query to custom table; results are cached.
                        $log_entry = $wpdb->get_row($wpdb->prepare(
                            "SELECT indexed_content FROM {$data_source_table_name} WHERE provider = 'Pinecone' AND vector_store_id = %s AND file_id = %s ORDER BY timestamp DESC LIMIT 1",
                            $index_to_query,
                            $item['id']
                        ), ARRAY_A);
                    }

                    // Fallback 2: if metadata contains batch_id, query by it explicitly for precision
                    if (!$log_entry && !empty($metadata['batch_id'])) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Direct query to custom table; results are cached.
                        $log_entry = $wpdb->get_row($wpdb->prepare(
                            "SELECT indexed_content FROM {$data_source_table_name} WHERE provider = 'Pinecone' AND vector_store_id = %s AND batch_id = %s AND file_id = %s ORDER BY timestamp DESC LIMIT 1",
                            $index_to_query,
                            $metadata['batch_id'],
                            $item['id']
                        ), ARRAY_A);
                    }

                    wp_cache_set($cache_key, $log_entry, $cache_group, HOUR_IN_SECONDS);
                }

                if ($log_entry && !empty($log_entry['indexed_content'])) {
                    $content_snippet = $log_entry['indexed_content'];
                }
            }
            if (!empty($content_snippet)) {
                $formatted_general_results .= "- " . trim($content_snippet) . "\n";

                // Capture score data if reference provided
                if ($vector_search_scores_output !== null && isset($item['score'])) {
                    $vector_search_scores_output[] = build_vector_search_score_item_logic(
                        [
                            'provider' => 'Pinecone',
                            'index_name' => $index_to_query,
                            'namespace' => null, // General context has no specific namespace
                            'result_id' => $item['id'] ?? null,
                            'score' => $item['score'],
                            'content_preview' => wp_trim_words(trim($content_snippet), 10, '...')
                        ],
                        $metadata
                    );
                }
            }
        }
        if (!empty($formatted_general_results)) {
            $pinecone_results_this_pass .= "General Knowledge from Bot (Index {$index_to_query}):\n" . $formatted_general_results . "\n";
        }
    }

    if (!empty($pinecone_results_this_pass)) {
        $pinecone_results = $pinecone_results_this_pass;
    }

    return $pinecone_results;
}

/**
 * Resolves context from Qdrant vector store.
 *
 * @param \WPAICG\Core\AIPKit_AI_Caller $ai_caller
 * @param \WPAICG\Vector\AIPKit_Vector_Store_Manager $vector_store_manager
 * @param string $user_message
 * @param array $bot_settings
 * @param string|null $frontend_active_qdrant_collection_name
 * @param string|null $frontend_active_qdrant_file_upload_context_id
 * @param int $vector_top_k
 * @param \wpdb $wpdb
 * @param string $data_source_table_name
 * @param array|null &$vector_search_scores_output Optional reference to capture scores for logging.
 * @return string|WP_Error Formatted Qdrant context results.
 */
function resolve_qdrant_context_logic(
    AIPKit_AI_Caller $ai_caller,
    AIPKit_Vector_Store_Manager $vector_store_manager,
    string $user_message,
    array $bot_settings,
    ?string $frontend_active_qdrant_collection_name, // Note: Qdrant doesn't use frontend-defined collection name for bot-level context, bot setting is primary
    ?string $frontend_active_qdrant_file_upload_context_id,
    int $vector_top_k,
    \wpdb $wpdb,
    string $data_source_table_name,
    ?array &$vector_search_scores_output = null
) {
    $qdrant_results = "";
    $qdrant_collection_name_from_settings = $bot_settings['qdrant_collection_name'] ?? '';
    $qdrant_collection_names_multi = [];
    if (!empty($bot_settings['qdrant_collection_names']) && is_array($bot_settings['qdrant_collection_names'])) {
        $qdrant_collection_names_multi = array_values(array_unique(array_filter(array_map('strval', $bot_settings['qdrant_collection_names']))));
    } elseif (!empty($qdrant_collection_name_from_settings)) {
        $qdrant_collection_names_multi = [$qdrant_collection_name_from_settings];
    }
    $vector_embedding_provider = $bot_settings['vector_embedding_provider'] ?? '';
    $vector_embedding_model = $bot_settings['vector_embedding_model'] ?? ''; // This is the correctly defined variable
    $confidence_threshold_percent = (int)($bot_settings['vector_store_confidence_threshold'] ?? 20);
    $qdrant_score_threshold = round($confidence_threshold_percent / 100, 4); // Normalize 0-100 to 0-1 scale and round to avoid precision issues

    if (empty($qdrant_collection_names_multi) || empty($vector_embedding_provider) || empty($vector_embedding_model)) {
        // If $vector_embedding_model is empty, this condition is met, and it returns early.
        // This prevents "Undefined variable" if the model is essential and missing.
        return "";
    }

    if (!class_exists(AIPKit_Providers::class)) {
        $providers_path = WPAICG_PLUGIN_DIR . 'classes/ai/settings.php';
        if (file_exists($providers_path)) {
            require_once $providers_path;
        } else {
            return "";
        }
    }
    $qdrant_api_config = AIPKit_Providers::get_provider_data('Qdrant');
    if (empty($qdrant_api_config['url']) || empty($qdrant_api_config['api_key'])) {
        return new WP_Error('knowledge_provider_not_configured', __('Connect Qdrant to search the selected knowledge store.', 'gpt3-ai-content-generator'));
    }

    $embedding_provider_normalized = normalize_embedding_provider_logic($vector_embedding_provider);

    $query_vector_values_or_error = resolve_embedding_vector_logic(
        $ai_caller,
        $user_message,
        $embedding_provider_normalized,
        $vector_embedding_model, 'Qdrant', $qdrant_collection_names_multi
    );

    if (is_wp_error($query_vector_values_or_error)) {
        return $query_vector_values_or_error;
    }
    $query_vector_values = $query_vector_values_or_error;

    // We'll search across all selected collections and aggregate
    $collections_to_query = $qdrant_collection_names_multi;
    $qdrant_results_aggregate = "";

    // 1. Search with file-specific context ID if provided
    if (!empty($frontend_active_qdrant_file_upload_context_id)) {
        foreach ($collections_to_query as $collection_to_query) {
            $file_specific_filter = [
                'must' => [
                    ['key' => 'file_upload_context_id', 'match' => ['value' => $frontend_active_qdrant_file_upload_context_id]]
                ]
            ];
            $query_vector_for_file_context = ['vector' => $query_vector_values];
            $file_search_results = $vector_store_manager->query_vectors('Qdrant', $collection_to_query, $query_vector_for_file_context, $vector_top_k, $file_specific_filter, $qdrant_api_config);

            if (is_wp_error($file_search_results)) { return $file_search_results; }
            if (!empty($file_search_results)) {
                $formatted_file_results = "";
                foreach ($file_search_results as $item) {
                    if (isset($item['score']) && (float)$item['score'] < $qdrant_score_threshold) {
                        continue;
                    }
                    $metadata = isset($item['metadata']) && is_array($item['metadata']) ? $item['metadata'] : [];
                    $content_snippet = $metadata['original_content'] ?? ($metadata['text_content'] ?? null);
                    if (!empty($content_snippet)) {
                        $formatted_file_results .= "- " . trim($content_snippet) . "\n";

                        if ($vector_search_scores_output !== null && isset($item['score'])) {
                            $vector_search_scores_output[] = build_vector_search_score_item_logic(
                                [
                                    'provider' => 'Qdrant',
                                    'collection_name' => $collection_to_query,
                                    'file_context_id' => $frontend_active_qdrant_file_upload_context_id,
                                    'result_id' => $item['id'] ?? null,
                                    'score' => $item['score'],
                                    'content_preview' => wp_trim_words(trim($content_snippet), 10, '...')
                                ],
                                $metadata
                            );
                        }
                    }
                }
                if (!empty($formatted_file_results)) {
                    $qdrant_results_aggregate .= "Context from Uploaded File (Collection {$collection_to_query}, File Context ID: {$frontend_active_qdrant_file_upload_context_id}):\n" . $formatted_file_results . "\n";
                }
            }
        }
    }

    // 2. Search general bot knowledge
    $general_knowledge_filter = ['must_not' => [['key' => 'source', 'match' => ['value' => 'chat_file_upload']]]];

    foreach ($collections_to_query as $collection_to_query) {
        $query_vector_for_general_context = ['vector' => $query_vector_values];
        $general_search_results = $vector_store_manager->query_vectors('Qdrant', $collection_to_query, $query_vector_for_general_context, $vector_top_k, $general_knowledge_filter, $qdrant_api_config);

        if (is_wp_error($general_search_results)) { return $general_search_results; }
        if (!empty($general_search_results)) {
            $formatted_general_results = "";
            foreach ($general_search_results as $item) {
                if (isset($item['score']) && (float)$item['score'] < $qdrant_score_threshold) {
                    continue;
                }
                $metadata = isset($item['metadata']) && is_array($item['metadata']) ? $item['metadata'] : [];
                if (($metadata['source'] ?? '') === 'chat_file_upload' || !empty($metadata['file_upload_context_id'])) { continue; }
                $content_snippet = $metadata['original_content'] ?? ($metadata['text_content'] ?? null);
                if (empty($content_snippet) && isset($item['id'])) {
                    $cache_key = 'aipkit_vds_content_' . md5('qdrant_general_' . $collection_to_query . $item['id']);
                    $cache_group = 'aipkit_vector_source_content';
                    $log_entry = wp_cache_get($cache_key, $cache_group);

                    if (false === $log_entry) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reason: Direct query to a custom table. Caches will be invalidated.
                        $log_entry = $wpdb->get_row($wpdb->prepare("SELECT indexed_content FROM {$data_source_table_name} WHERE provider = 'Qdrant' AND vector_store_id = %s AND file_id = %s AND (batch_id IS NULL OR batch_id = '' OR batch_id NOT LIKE %s) ORDER BY timestamp DESC LIMIT 1", $collection_to_query, $item['id'], 'qdrant_chat_file_%'), ARRAY_A);
                        wp_cache_set($cache_key, $log_entry, $cache_group, HOUR_IN_SECONDS);
                    }

                    if ($log_entry && !empty($log_entry['indexed_content'])) {
                        $content_snippet = $log_entry['indexed_content'];
                    }
                }
                if (!empty($content_snippet)) {
                    $formatted_general_results .= "- " . trim($content_snippet) . "\n";

                    if ($vector_search_scores_output !== null && isset($item['score'])) {
                        $vector_search_scores_output[] = build_vector_search_score_item_logic(
                            [
                                'provider' => 'Qdrant',
                                'collection_name' => $collection_to_query,
                                'file_context_id' => null,
                                'result_id' => $item['id'] ?? null,
                                'score' => $item['score'],
                                'content_preview' => wp_trim_words(trim($content_snippet), 10, '...')
                            ],
                            $metadata
                        );
                    }
                }
            }
            if (!empty($formatted_general_results)) {
                $qdrant_results_aggregate .= "General Knowledge from Bot (Collection {$collection_to_query}):\n" . $formatted_general_results . "\n";
            }
        }
    }

    if (!empty($qdrant_results_aggregate)) {
        $qdrant_results = $qdrant_results_aggregate;
    }

    return $qdrant_results;
}

/**
 * Local knowledge retrieval uses the selected embedding model and each store
 * dimension. A question is embedded once per model and dimension. Settings: `local_store_ids` (array) or, from modules
 * that pick one knowledge base, `local_store_id` (string).
 */
function resolve_local_context_logic(
    AIPKit_AI_Caller $ai_caller,
    AIPKit_Vector_Store_Manager $vector_store_manager,
    string $user_message,
    array $bot_settings,
    ?string $file_upload_context_id,
    int $vector_top_k,
    ?array &$vector_search_scores_output = null
) {
    $store_ids = is_array($bot_settings['local_store_ids'] ?? null) ? $bot_settings['local_store_ids'] : [];
    if (!empty($bot_settings['local_store_id']) && is_scalar($bot_settings['local_store_id'])) { $store_ids[] = (string) $bot_settings['local_store_id']; }
    $store_ids = array_values(array_unique(array_filter(array_map('strval', $store_ids))));
    if (!$store_ids) { return ''; }
    if (!class_exists(\WPAICG\Vector\Providers\AIPKit_Vector_Local_Strategy::class)) {
        require_once WPAICG_PLUGIN_DIR . 'classes/knowledge-base/providers/local.php';
    }
    $strategy = new \WPAICG\Vector\Providers\AIPKit_Vector_Local_Strategy();
    $threshold = round(((int) ($bot_settings['vector_store_confidence_threshold'] ?? 20)) / 100, 4);
    $query_vectors = []; // "provider|model|dimensions" => vector
    $output = '';
    foreach (array_slice($store_ids, 0, 10) as $store_id) {
        $store = $strategy->get_store(\WPAICG\Vector\Providers\AIPKit_Vector_Local_Strategy::store_id($store_id));
        if (!$store) { return new \WP_Error('knowledge_store_missing', __('The selected knowledge store is unavailable. Select an existing store.', 'gpt3-ai-content-generator')); }
        if (empty($bot_settings['vector_embedding_provider']) || empty($bot_settings['vector_embedding_model'])) {
            return new \WP_Error('knowledge_embedding_missing', __('Select an embedding provider and model for the knowledge search.', 'gpt3-ai-content-generator'));
        }
        if ((int) $store['chunk_count'] === 0) { continue; }
        $key = $bot_settings['vector_embedding_provider'] . '|' . $bot_settings['vector_embedding_model'] . '|' . $store['dimensions'];
        if (!isset($query_vectors[$key])) {
            $vector = resolve_embedding_vector_logic($ai_caller, $user_message, normalize_embedding_provider_logic((string) $bot_settings['vector_embedding_provider']), (string) $bot_settings['vector_embedding_model'], 'Local', [$store_id]);
            if (is_wp_error($vector)) { return $vector; }
            $query_vectors[$key] = $vector;
        }
        $searches = [];
        if (!empty($file_upload_context_id) && ($bot_settings['active_local_store_id'] ?? '') === $store['id']) {
            $searches[] = [__('Context from the uploaded file', 'gpt3-ai-content-generator'), ['must' => [['key' => 'file_upload_context_id', 'match' => ['value' => $file_upload_context_id]]]], true];
        }
        $searches[] = [sprintf('Knowledge base "%s"', $store['name']), ['must_not' => [['key' => 'source', 'match' => ['value' => 'chat_file_upload']]]], false];
        foreach ($searches as [$label, $filter, $private_file]) {
            $hits = $vector_store_manager->query_vectors('Local', $store['id'], ['vector' => $query_vectors[$key], 'score_threshold' => $threshold], $vector_top_k, $filter, []);
            if (is_wp_error($hits)) { return $hits; }
            if (!$hits) { continue; }
            $lines = '';
            foreach ($hits as $item) {
                if (!$private_file && (($item['metadata']['source'] ?? '') === 'chat_file_upload' || !empty($item['metadata']['file_upload_context_id']))) { continue; }
                $snippet = trim((string) ($item['metadata']['original_content'] ?? ''));
                if ($snippet === '') { continue; }
                $lines .= '- ' . $snippet . "\n";
                if ($vector_search_scores_output !== null) {
                    $vector_search_scores_output[] = build_vector_search_score_item_logic([
                        'provider' => 'Local', 'collection_name' => $store['id'], 'file_context_id' => $file_upload_context_id ?: null,
                        'result_id' => $item['id'] ?? null, 'score' => $item['score'], 'content_preview' => wp_trim_words($snippet, 10, '...'),
                    ], (array) $item['metadata']);
                }
            }
            if ($lines !== '') { $output .= $label . ":\n" . $lines . "\n"; }
        }
    }
    return $output;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Chroma context resolution only reads plugin-owned vector log tables with prepared scalar values.

/**
 * Resolves context from Chroma collections.
 *
 * @param AIPKit_AI_Caller $ai_caller
 * @param AIPKit_Vector_Store_Manager $vector_store_manager
 * @param string $user_message
 * @param array<string,mixed> $bot_settings
 * @param string|null $frontend_active_chroma_collection_name
 * @param string|null $frontend_active_chroma_file_upload_context_id
 * @param int $vector_top_k
 * @param \wpdb $wpdb
 * @param string $data_source_table_name
 * @param array|null &$vector_search_scores_output Optional reference to capture scores for logging.
 * @return string|WP_Error Formatted Chroma context results.
 */
function resolve_chroma_context_logic(
    AIPKit_AI_Caller $ai_caller,
    AIPKit_Vector_Store_Manager $vector_store_manager,
    string $user_message,
    array $bot_settings,
    ?string $frontend_active_chroma_collection_name,
    ?string $frontend_active_chroma_file_upload_context_id,
    int $vector_top_k,
    \wpdb $wpdb,
    string $data_source_table_name,
    ?array &$vector_search_scores_output = null
) {
    $chroma_collection_name_from_settings = $bot_settings['chroma_collection_name'] ?? '';
    $chroma_collection_names_multi = [];
    if (!empty($bot_settings['chroma_collection_names']) && is_array($bot_settings['chroma_collection_names'])) {
        $chroma_collection_names_multi = array_values(array_unique(array_filter(array_map('strval', $bot_settings['chroma_collection_names']))));
    } elseif (!empty($chroma_collection_name_from_settings)) {
        $chroma_collection_names_multi = [$chroma_collection_name_from_settings];
    }

    $active_chroma_collection_name = $frontend_active_chroma_collection_name !== null
        ? trim(sanitize_text_field($frontend_active_chroma_collection_name))
        : (isset($bot_settings['active_chroma_collection_name']) ? trim((string) $bot_settings['active_chroma_collection_name']) : '');
    if ($active_chroma_collection_name !== '' && !in_array($active_chroma_collection_name, $chroma_collection_names_multi, true)) {
        $chroma_collection_names_multi[] = $active_chroma_collection_name;
    }

    $vector_embedding_provider = $bot_settings['vector_embedding_provider'] ?? '';
    $vector_embedding_model = $bot_settings['vector_embedding_model'] ?? '';
    $confidence_threshold_percent = (int) ($bot_settings['vector_store_confidence_threshold'] ?? 20);
    $chroma_score_threshold = round($confidence_threshold_percent / 100, 4);

    if (empty($chroma_collection_names_multi) || empty($vector_embedding_provider) || empty($vector_embedding_model)) {
        return '';
    }

    if (!class_exists(AIPKit_Providers::class)) {
        $providers_path = WPAICG_PLUGIN_DIR . 'classes/ai/settings.php';
        if (file_exists($providers_path)) {
            require_once $providers_path;
        } else {
            return '';
        }
    }

    $chroma_api_config = AIPKit_Providers::get_provider_data('Chroma');
    if (empty($chroma_api_config['url'])) {
        return new WP_Error('knowledge_provider_not_configured', __('Connect Chroma to search the selected knowledge store.', 'gpt3-ai-content-generator'));
    }

    $embedding_provider_normalized = normalize_embedding_provider_logic($vector_embedding_provider);
    $query_vector_values_or_error = resolve_embedding_vector_logic(
        $ai_caller,
        $user_message,
        $embedding_provider_normalized,
        $vector_embedding_model, 'Chroma', $chroma_collection_names_multi
    );

    if (is_wp_error($query_vector_values_or_error)) {
        return $query_vector_values_or_error;
    }

    $query_vector_values = $query_vector_values_or_error;
    $collections_to_query = $chroma_collection_names_multi;
    $active_file_context_id = $frontend_active_chroma_file_upload_context_id !== null
        ? sanitize_text_field($frontend_active_chroma_file_upload_context_id)
        : '';
    foreach (['active_chroma_file_upload_context_id', 'chroma_file_upload_context_id'] as $context_key) {
        if ($active_file_context_id !== '') {
            break;
        }
        if (!empty($bot_settings[$context_key])) {
            $active_file_context_id = sanitize_text_field((string) $bot_settings[$context_key]);
            break;
        }
    }

    $chroma_results_aggregate = '';

    if ($active_file_context_id !== '') {
        foreach ($collections_to_query as $collection_to_query) {
            $file_specific_filter = [
                'where' => [
                    'file_upload_context_id' => $active_file_context_id,
                ],
            ];
            $file_search_results = $vector_store_manager->query_vectors(
                'Chroma',
                $collection_to_query,
                ['vector' => $query_vector_values],
                $vector_top_k,
                $file_specific_filter,
                $chroma_api_config
            );

            if (is_wp_error($file_search_results)) { return $file_search_results; }
            if (!empty($file_search_results)) {
                $formatted_file_results = '';
                foreach ($file_search_results as $item) {
                    if (isset($item['score']) && (float) $item['score'] < $chroma_score_threshold) {
                        continue;
                    }
                    $metadata = isset($item['metadata']) && is_array($item['metadata']) ? $item['metadata'] : [];
                    $content_snippet = resolve_chroma_content_snippet_logic($item, $metadata, $collection_to_query, $wpdb, $data_source_table_name);
                    if ($content_snippet === '') {
                        continue;
                    }
                    $formatted_file_results .= '- ' . trim($content_snippet) . "\n";

                    if ($vector_search_scores_output !== null && isset($item['score'])) {
                        $vector_search_scores_output[] = build_vector_search_score_item_logic(
                            [
                                'provider' => 'Chroma',
                                'collection_name' => $collection_to_query,
                                'file_context_id' => $active_file_context_id,
                                'result_id' => $item['id'] ?? null,
                                'score' => $item['score'],
                                'content_preview' => wp_trim_words(trim($content_snippet), 10, '...'),
                            ],
                            $metadata
                        );
                    }
                }
                if ($formatted_file_results !== '') {
                    $chroma_results_aggregate .= "Context from Uploaded File (Collection {$collection_to_query}, File Context ID: {$active_file_context_id}):\n" . $formatted_file_results . "\n";
                }
            }
        }
    }

    foreach ($collections_to_query as $collection_to_query) {
        $general_search_results = $vector_store_manager->query_vectors(
            'Chroma',
            $collection_to_query,
            ['vector' => $query_vector_values],
            $vector_top_k,
            ['where' => ['source' => ['$ne' => 'chat_file_upload']]],
            $chroma_api_config
        );

        if (is_wp_error($general_search_results)) { return $general_search_results; }
        if (empty($general_search_results)) {
            continue;
        }

        $formatted_general_results = '';
        foreach ($general_search_results as $item) {
            if (isset($item['score']) && (float) $item['score'] < $chroma_score_threshold) {
                continue;
            }

            $metadata = isset($item['metadata']) && is_array($item['metadata']) ? $item['metadata'] : [];
            if (($metadata['source'] ?? '') === 'chat_file_upload' || !empty($metadata['file_upload_context_id'])) {
                continue;
            }

            $content_snippet = resolve_chroma_content_snippet_logic($item, $metadata, $collection_to_query, $wpdb, $data_source_table_name);
            if ($content_snippet === '') {
                continue;
            }

            $formatted_general_results .= '- ' . trim($content_snippet) . "\n";

            if ($vector_search_scores_output !== null && isset($item['score'])) {
                $vector_search_scores_output[] = build_vector_search_score_item_logic(
                    [
                        'provider' => 'Chroma',
                        'collection_name' => $collection_to_query,
                        'file_context_id' => null,
                        'result_id' => $item['id'] ?? null,
                        'score' => $item['score'],
                        'content_preview' => wp_trim_words(trim($content_snippet), 10, '...'),
                    ],
                    $metadata
                );
            }
        }

        if ($formatted_general_results !== '') {
            $chroma_results_aggregate .= "General Knowledge from Bot (Collection {$collection_to_query}):\n" . $formatted_general_results . "\n";
        }
    }

    return $chroma_results_aggregate;
}

/**
 * @param array<string,mixed> $item
 * @param array<string,mixed> $metadata
 */
function resolve_chroma_content_snippet_logic(array $item, array $metadata, string $collection_name, \wpdb $wpdb, string $data_source_table_name): string
{
    $content_snippet = $metadata['original_content'] ?? ($metadata['text_content'] ?? null);
    if (!empty($content_snippet)) {
        return trim((string) $content_snippet);
    }

    $result_id = isset($item['id']) ? (string) $item['id'] : '';
    if ($result_id === '') {
        return '';
    }

    $cache_key = 'aipkit_vds_content_' . md5('chroma_general_' . $collection_name . $result_id);
    $cache_group = 'aipkit_vector_source_content';
    $log_entry = wp_cache_get($cache_key, $cache_group);

    if (false === $log_entry) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $log_entry = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT indexed_content FROM {$data_source_table_name} WHERE provider = 'Chroma' AND vector_store_id = %s AND file_id = %s ORDER BY timestamp DESC LIMIT 1",
                $collection_name,
                $result_id
            ),
            ARRAY_A
        );
        wp_cache_set($cache_key, $log_entry, $cache_group, HOUR_IN_SECONDS);
    }

    if ($log_entry && !empty($log_entry['indexed_content'])) {
        return trim((string) $log_entry['indexed_content']);
    }

    return '';
}
