<?php


namespace WPAICG\Core\Ajax;

use WPAICG\Dashboard\Ajax\BaseDashboardAjaxHandler;
use WPAICG\Core\AIPKit_AI_Caller;
use WPAICG\Vector\AIPKit_Vector_Store_Manager;
use WPAICG\AIPKit_Providers;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles AJAX requests for the public-facing Semantic Search shortcode.
 */
class AIPKit_Semantic_Search_Ajax_Handler extends BaseDashboardAjaxHandler
{
    private $ai_caller;
    private $vector_store_manager;

    public function __construct()
    {
        if (class_exists(\WPAICG\Core\AIPKit_AI_Caller::class)) {
            $this->ai_caller = new AIPKit_AI_Caller();
        }
        if (class_exists(\WPAICG\Vector\AIPKit_Vector_Store_Manager::class)) {
            $this->vector_store_manager = new AIPKit_Vector_Store_Manager();
        }
    }

    /**
     * AJAX handler for performing the semantic search.
     */
    public function ajax_perform_semantic_search()
    {
        if (!check_ajax_referer('aipkit_semantic_search_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed.', 'gpt3-ai-content-generator')], 403);
            return;
        }

        if (!$this->ai_caller || !$this->vector_store_manager) {
            $this->send_wp_error(new WP_Error('dependency_missing', __('Search service components are not available.', 'gpt3-ai-content-generator'), ['status' => 500]));
            return;
        }

        $query = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';
        if (empty($query)) {
            wp_send_json_error(['message' => __('Search query cannot be empty.', 'gpt3-ai-content-generator')], 400);
            return;
        }

        $rate_check = \WPAICG\Core\TokenManager\AIPKit_Token_Manager::check_public_request_rate();
        if (is_wp_error($rate_check)) { $this->send_wp_error($rate_check); return; }

        $opts = get_option('aipkit_options', []);
        $settings = $opts['semantic_search'] ?? [];

        $vector_provider = sanitize_key((string) ($settings['vector_provider'] ?? ''));
        $target_id = $settings['target_id'] ?? '';
        $embedding_provider_key = $settings['embedding_provider'] ?? '';
        $embedding_model = $settings['embedding_model'] ?? '';
        $num_results = $settings['num_results'] ?? 5;
        $no_results_text = $settings['no_results_text'] ?? __('No results found.', 'gpt3-ai-content-generator');

        if (empty($vector_provider) || empty($target_id) || empty($embedding_provider_key) || empty($embedding_model)) {
            $this->send_wp_error(new WP_Error('config_missing', __('Semantic Search is not configured correctly in AI Settings.', 'gpt3-ai-content-generator'), ['status' => 500]));
            return;
        }

        $vector_provider_map = [
            'local' => 'Local',
            'pinecone' => 'Pinecone',
            'qdrant'  => 'Qdrant',
            'chroma'  => 'Chroma',
        ];
        if (!isset($vector_provider_map[$vector_provider])) {
            $this->send_wp_error(new WP_Error('invalid_semantic_vector_provider', __('Invalid vector database configured for Semantic Search.', 'gpt3-ai-content-generator'), ['status' => 500]));
            return;
        }
        $vector_provider_normalized = $vector_provider_map[$vector_provider];

        // Generate embedding for the user's query
        $embedding_provider_lookup = sanitize_key((string) strtolower($embedding_provider_key));
        $embedding_provider_norm = AIPKit_Providers::resolve_embedding_provider_name(
            $embedding_provider_lookup,
            'semantic_search_ajax'
        );
        if (!is_string($embedding_provider_norm) || $embedding_provider_norm === '') {
            $this->send_wp_error(new WP_Error('invalid_embedding_provider_semantic_search', __('Invalid embedding provider configured for Semantic Search.', 'gpt3-ai-content-generator'), ['status' => 500]));
            return;
        }

        $vector_store_config = $vector_provider_normalized === 'Local' ? [] : AIPKit_Providers::get_provider_data($vector_provider_normalized);
        $embedding_options = $this->vector_store_manager->embedding_options($embedding_provider_norm, $embedding_model, $vector_provider_normalized, [$target_id], $vector_store_config);
        if (is_wp_error($embedding_options)) { $this->send_wp_error($embedding_options); return; }
        $embedding_result = $this->ai_caller->generate_embeddings($embedding_provider_norm, $query, $embedding_options);

        if (is_wp_error($embedding_result)) {
            $this->send_wp_error($embedding_result);
            return;
        }
        if (empty($embedding_result['embeddings'][0])) {
            $this->send_wp_error(new WP_Error('embedding_failed', __('Failed to generate vector for the search query.', 'gpt3-ai-content-generator'), ['status' => 500]));
            return;
        }
        $query_vector = $embedding_result['embeddings'][0];

        $query_vector_param = ['vector' => $query_vector];
        $search_results = $this->vector_store_manager->query_vectors($vector_provider_normalized, $target_id, $query_vector_param, (int)$num_results, [], $vector_store_config);

        if (is_wp_error($search_results)) {
            $this->send_wp_error($search_results);
            return;
        }

        // Format results into HTML
        $html = '';
        if (empty($search_results)) {
            $html = '<p class="aipkit-search-no-results">' . esc_html($no_results_text) . '</p>';
        } else {
            foreach ($search_results as $result) {
                $metadata = $result['metadata'] ?? [];
                $title = $metadata['title'] ?? __('Untitled Result', 'gpt3-ai-content-generator');
                $url = $metadata['url'] ?? '#';
                $snippet = $metadata['original_content'] ?? ($metadata['text_content'] ?? __('No snippet available.', 'gpt3-ai-content-generator'));

                $html .= '<div class="aipkit_semantic_search_item">';
                $html .= '  <h3 class="aipkit_search_result_title"><a href="' . esc_url($url) . '" target="_blank">' . esc_html($title) . '</a></h3>';
                $html .= '  <p class="aipkit_search_result_snippet">' . esc_html(wp_trim_words($snippet, 55, '...')) . '</p>';
                $html .= '  <a href="' . esc_url($url) . '" class="aipkit_search_result_link" target="_blank">' . esc_html($url) . '</a>';
                $html .= '</div>';
            }
        }

        wp_send_json_success(['html' => $html]);
    }
}
