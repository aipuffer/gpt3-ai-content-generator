<?php

namespace WPAICG\Vector\PostProcessor\Local;

use WPAICG\Vector\PostProcessor\Base\AIPKit_Vector_Post_Processor_Base;
use WPAICG\Vector\AIPKit_Vector_Text_Ingestion_Service;
use WPAICG\Vector\Providers\AIPKit_Vector_Local_Strategy;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists(AIPKit_Vector_Post_Processor_Base::class)) {
    require_once WPAICG_PLUGIN_DIR . 'classes/knowledge-base/indexing.php';
}

/**
 * Indexes WordPress posts into Local using the requested embedding model.
 */
class LocalPostProcessor extends AIPKit_Vector_Post_Processor_Base
{
    private $vector_store_manager;

    public function __construct()
    {
        parent::__construct();
        $this->vector_store_manager = $this->create_vector_store_manager();
        if (!class_exists(AIPKit_Vector_Text_Ingestion_Service::class)) {
            require_once WPAICG_PLUGIN_DIR . 'classes/knowledge-base/ingestion.php';
        }
        if (!class_exists(AIPKit_Vector_Local_Strategy::class)) {
            require_once WPAICG_PLUGIN_DIR . 'classes/knowledge-base/providers/local.php';
        }
    }

    /** @return array{status:string,message:string} */
    public function index_single_post_to_store(int $post_id, string $store_id, string $embedding_provider_key = '', string $embedding_model = ''): array
    {
        $post = get_post($post_id);
        $title = $post ? $post->post_title : 'N/A';
        $strategy = new AIPKit_Vector_Local_Strategy();
        $store = $strategy->get_store(AIPKit_Vector_Local_Strategy::store_id($store_id));
        $log = [
            'provider' => 'Local', 'vector_store_id' => $store_id, 'vector_store_name' => $store['name'] ?? $store_id,
            'post_id' => $post_id, 'post_title' => $title, 'file_id' => 'wp_post_' . $post_id, 'source_type_for_log' => 'wordpress_post',
        ];
        $fail = function ($error) use ($log): array {
            return $this->indexing_error($error, $log);
        };
        if (!$store) { return $fail(__('Knowledge base not found.', 'gpt3-ai-content-generator')); }
        if ($embedding_provider_key === '' || $embedding_model === '') { return $fail(__('Choose an embedding model for this knowledge base.', 'gpt3-ai-content-generator')); }
        $log['embedding_provider'] = $embedding_provider_key;
        $log['embedding_model'] = $embedding_model;

        $content = $this->get_post_content_as_string($post_id);
        if (is_wp_error($content)) { return $fail($content); }
        if (trim($content) === '') { return $fail(__('This post has no content to index.', 'gpt3-ai-content-generator')); }

        $service = new AIPKit_Vector_Text_Ingestion_Service($this->vector_store_manager, new \WPAICG\Core\AIPKit_AI_Caller());
        $result = $service->ingest_text('Local', $store['id'], $content, $embedding_provider_key, $embedding_model, [
            'vector_id' => 'wp_post_' . $post_id,
            'source' => 'wordpress_post',
            'post_id' => (string) $post_id,
            'title' => $title,
            'type' => get_post_type($post_id),
            'url' => get_permalink($post_id),
        ], []);
        if (is_wp_error($result)) { return $fail($result); }

        $log['indexed_content'] = $content;
        $this->log_event($log + ['status' => 'indexed', 'message' => sprintf('WordPress post content indexed. Chunks: %d.', (int) ($result['total_chunks'] ?? 0))]);
        update_post_meta($post_id, '_aipkit_indexed_to_vs_' . sanitize_key($store['id']), '1');
        update_post_meta($post_id, '_aipkit_vector_id_for_vs_' . sanitize_key($store['id']), 'wp_post_' . $post_id);
        return ['status' => 'success', 'message' => __('Post content added to the knowledge base on this site.', 'gpt3-ai-content-generator')];
    }
}
