<?php


namespace WPAICG\Vector;

use WPAICG\AIPKit_Role_Manager;
// Use new processor classes
use WPAICG\Vector\PostProcessor\OpenAI\OpenAIPostProcessor;
use WPAICG\Vector\PostProcessor\Pinecone\PineconePostProcessor;
use WPAICG\Vector\PostProcessor\Qdrant\QdrantPostProcessor;
use WPAICG\Vector\PostProcessor\Chroma\ChromaPostProcessor;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * AIPKit_Vector_Post_Processor_Ajax_Handler
 *
 * Handles AJAX requests for indexing WordPress post content into vector stores.
 * Orchestrates calls to provider-specific post processor classes.
 */
class AIPKit_Vector_Post_Processor_Ajax_Handler
{
    private $openai_processor;
    private $pinecone_processor;
    private $qdrant_processor;
    private $chroma_processor;
    private $local_processor;

    public function __construct()
    {
        // Ensure classes are loaded by DependencyLoader, then instantiate
        if (class_exists(OpenAIPostProcessor::class)) {
            $this->openai_processor = new OpenAIPostProcessor();
        }

        if (class_exists(PineconePostProcessor::class)) {
            $this->pinecone_processor = new PineconePostProcessor();
        }

        if (class_exists(QdrantPostProcessor::class)) {
            $this->qdrant_processor = new QdrantPostProcessor();
        }

        if (class_exists(ChromaPostProcessor::class)) {
            $this->chroma_processor = new ChromaPostProcessor();
        }

        if (!class_exists(\WPAICG\Vector\PostProcessor\Local\LocalPostProcessor::class)) {
            require_once WPAICG_PLUGIN_DIR . 'classes/knowledge-base/indexing-local.php';
        }
        $this->local_processor = new \WPAICG\Vector\PostProcessor\Local\LocalPostProcessor();
    }

    /**
     * Read submitted WordPress source jobs without repeating provider work.
     */
    public function ajax_get_post_indexing_status(): void
    {
        if (!AIPKit_Role_Manager::user_can_access_module('vector_content_indexer')) {
            wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'gpt3-ai-content-generator')], 403);
            return;
        }
        if (!check_ajax_referer('aipkit_index_posts_to_vector_store_nonce', '_ajax_nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed (nonce).', 'gpt3-ai-content-generator')], 403);
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified immediately above.
        $ids = isset($_POST['job_ids']) && is_array($_POST['job_ids']) ? array_map('absint', wp_unslash($_POST['job_ids'])) : [];
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids || count($ids) > 20) {
            wp_send_json_error(['message' => __('Select up to 20 indexing jobs.', 'gpt3-ai-content-generator')], 400);
            return;
        }
        global $wpdb;
        $jobs = [];
        foreach ($ids as $id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded exact-ID lookup; each source requires edit access to its WordPress post.
            $job = $wpdb->get_row($wpdb->prepare(
                'SELECT post_id, status, message FROM ' . esc_sql($wpdb->prefix . 'aipkit_vector_data_source') . ' WHERE id = %d AND post_id > 0', $id
            ), ARRAY_A);
            if (!$job || !current_user_can('edit_post', (int) $job['post_id'])) {
                wp_send_json_error(['message' => __('The indexing job is unavailable or you do not have permission to view it.', 'gpt3-ai-content-generator')], 403);
                return;
            }
            $jobs[] = ['job_id' => $id, 'post_id' => (int) $job['post_id'], 'status' => $job['status'], 'message' => $job['message']];
        }
        wp_send_json_success(['jobs' => $jobs]);
    }

    /**
     * AJAX handler for indexing selected posts to the chosen vector store.
     */
    public function ajax_index_posts_to_vector_store()
    {        
        if (!AIPKit_Role_Manager::user_can_access_module('vector_content_indexer')) {
            wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'gpt3-ai-content-generator')], 403);
            return;
        }
        if (!check_ajax_referer('aipkit_index_posts_to_vector_store_nonce', '_ajax_nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed (nonce).', 'gpt3-ai-content-generator')], 403);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked above.
        $post_data = wp_unslash($_POST);

        $post_ids_raw = isset($post_data['post_ids']) && is_array($post_data['post_ids']) ? $post_data['post_ids'] : [];
        $post_ids = array_map('absint', $post_ids_raw);
        $post_ids = array_filter($post_ids, function ($id) { return $id > 0; });
        $provider = isset($post_data['provider']) ? sanitize_key($post_data['provider']) : '';
        
        if (empty($post_ids)) {
            wp_send_json_error(['message' => __('No posts selected for indexing.', 'gpt3-ai-content-generator')], 400);
            return;
        }
        foreach ($post_ids as $post_id) {
            if (!current_user_can('edit_post', $post_id)) {
                wp_send_json_error(['message' => __('You do not have permission to index one or more selected items.', 'gpt3-ai-content-generator')], 403);
                return;
            }
        }
        if (empty($provider)) {
            wp_send_json_error(['message' => __('Vector store provider is required.', 'gpt3-ai-content-generator')], 400);
            return;
        }

        $first_error_data = [];
        $stop_error = null;
        $processed_count = 0;
        $successful_posts = [];
        $failed_posts_log = [];
        $jobs = [];
        $store_identifier_for_msg = '';
        $new_store_id = null; // This is specific to OpenAI when creating a new store, not used by vector DB providers here

        if ($provider === 'openai' && $this->openai_processor) {
            $target_store_id = isset($post_data['target_store_id']) ? sanitize_text_field($post_data['target_store_id']) : '';
            if (empty($target_store_id)) {
                wp_send_json_error(['message' => __('Please select an existing OpenAI vector store.', 'gpt3-ai-content-generator')], 400);
                return;
            }
            $store_identifier_for_msg = $target_store_id;
            
            foreach ($post_ids as $post_id) {
                $result = $this->openai_processor->index_single_post_to_store($post_id, $target_store_id);
                if ($result['status'] === 'success') {
                    if (!empty($result['job_id'])) {
                        $jobs[] = ['job_id' => (int) $result['job_id'], 'post_id' => $post_id, 'status' => !empty($result['processing']) ? 'processing' : 'indexed'];
                    }
                    $successful_posts[] = $post_id;
                    $processed_count++;
                } else {
                    $failed_posts_log[$post_id] = $result['message'];
                    $error_data = $result['error_data'] ?? [];
                    if (count($failed_posts_log) === 1) { $first_error_data = $error_data; }
                    if (!empty($error_data['stop_batch'])) {
                        $stop_error = $error_data + ['message' => $result['message']];
                        break;
                    }
                }
            }
        } elseif ($provider === 'pinecone' && $this->pinecone_processor) {
            $target_index_id = isset($post_data['target_index_id']) ? sanitize_text_field($post_data['target_index_id']) : '';
            $embedding_provider_key = isset($post_data['embedding_provider']) ? sanitize_key($post_data['embedding_provider']) : '';
            $embedding_model = isset($post_data['embedding_model']) ? sanitize_text_field($post_data['embedding_model']) : '';

            if (empty($target_index_id)) {
                wp_send_json_error(['message' => __('Please select a Pinecone index.', 'gpt3-ai-content-generator')], 400);
                return;
            }
            if (empty($embedding_provider_key) || empty($embedding_model)) {
                wp_send_json_error(['message' => __('Embedding provider and model are required for Pinecone.', 'gpt3-ai-content-generator')], 400);
                return;
            }
            $store_identifier_for_msg = $target_index_id;
            
            foreach ($post_ids as $post_id) {
                $result = $this->pinecone_processor->index_single_post_to_index($post_id, $target_index_id, $embedding_provider_key, $embedding_model);
                if ($result['status'] === 'success') {
                    $successful_posts[] = $post_id;
                    $processed_count++;
                } else {
                    $failed_posts_log[$post_id] = $result['message'];
                    $error_data = $result['error_data'] ?? [];
                    if (count($failed_posts_log) === 1) { $first_error_data = $error_data; }
                    if (!empty($error_data['stop_batch'])) {
                        $stop_error = $error_data + ['message' => $result['message']];
                        break;
                    }
                }
            }
        } elseif ($provider === 'qdrant' && $this->qdrant_processor) { // ADDED Qdrant case
            $target_collection_name = isset($post_data['target_collection_name']) ? sanitize_text_field($post_data['target_collection_name']) : '';
            $embedding_provider_key = isset($post_data['embedding_provider']) ? sanitize_key($post_data['embedding_provider']) : '';
            $embedding_model = isset($post_data['embedding_model']) ? sanitize_text_field($post_data['embedding_model']) : '';

            if (empty($target_collection_name)) {
                wp_send_json_error(['message' => __('Please select a Qdrant collection.', 'gpt3-ai-content-generator')], 400);
                return;
            }
            if (empty($embedding_provider_key) || empty($embedding_model)) {
                wp_send_json_error(['message' => __('Embedding provider and model are required for Qdrant.', 'gpt3-ai-content-generator')], 400);
                return;
            }
            $store_identifier_for_msg = $target_collection_name;
            
            foreach ($post_ids as $post_id) {
                $result = $this->qdrant_processor->index_single_post_to_collection($post_id, $target_collection_name, $embedding_provider_key, $embedding_model);
                if ($result['status'] === 'success') {
                    $successful_posts[] = $post_id;
                    $processed_count++;
                } else {
                    $failed_posts_log[$post_id] = $result['message'];
                    $error_data = $result['error_data'] ?? [];
                    if (count($failed_posts_log) === 1) { $first_error_data = $error_data; }
                    if (!empty($error_data['stop_batch'])) {
                        $stop_error = $error_data + ['message' => $result['message']];
                        break;
                    }
                }
            }
        } elseif ($provider === 'chroma' && $this->chroma_processor) {
            $target_collection_name = isset($post_data['target_collection_name']) ? sanitize_text_field($post_data['target_collection_name']) : '';
            $embedding_provider_key = isset($post_data['embedding_provider']) ? sanitize_key($post_data['embedding_provider']) : '';
            $embedding_model = isset($post_data['embedding_model']) ? sanitize_text_field($post_data['embedding_model']) : '';

            if (empty($target_collection_name)) {
                wp_send_json_error(['message' => __('Please select a Chroma collection.', 'gpt3-ai-content-generator')], 400);
                return;
            }
            if (empty($embedding_provider_key) || empty($embedding_model)) {
                wp_send_json_error(['message' => __('Embedding provider and model are required for Chroma.', 'gpt3-ai-content-generator')], 400);
                return;
            }
            $store_identifier_for_msg = $target_collection_name;

            foreach ($post_ids as $post_id) {
                $result = $this->chroma_processor->index_single_post_to_collection($post_id, $target_collection_name, $embedding_provider_key, $embedding_model);
                if ($result['status'] === 'success') {
                    $successful_posts[] = $post_id;
                    $processed_count++;
                } else {
                    $failed_posts_log[$post_id] = $result['message'];
                    $error_data = $result['error_data'] ?? [];
                    if (count($failed_posts_log) === 1) { $first_error_data = $error_data; }
                    if (!empty($error_data['stop_batch'])) {
                        $stop_error = $error_data + ['message' => $result['message']];
                        break;
                    }
                }
            }
        } elseif ($provider === 'local' && $this->local_processor) {
            $target_store = isset($post_data['target_store_id']) ? sanitize_text_field($post_data['target_store_id']) : (isset($post_data['target_collection_name']) ? sanitize_text_field($post_data['target_collection_name']) : '');
            $embedding_provider_key = isset($post_data['embedding_provider']) ? sanitize_key($post_data['embedding_provider']) : '';
            $embedding_model = isset($post_data['embedding_model']) ? sanitize_text_field($post_data['embedding_model']) : '';
            if ($target_store === '') {
                wp_send_json_error(['message' => __('Please select a knowledge base.', 'gpt3-ai-content-generator')], 400);
                return;
            }
            $store_identifier_for_msg = $target_store;
            foreach ($post_ids as $post_id) {
                $result = $this->local_processor->index_single_post_to_store($post_id, $target_store, $embedding_provider_key, $embedding_model);
                if ($result['status'] === 'success') {
                    $successful_posts[] = $post_id;
                    $processed_count++;
                } else {
                    $failed_posts_log[$post_id] = $result['message'];
                    $error_data = $result['error_data'] ?? [];
                    if (count($failed_posts_log) === 1) { $first_error_data = $error_data; }
                    if (!empty($error_data['stop_batch'])) {
                        $stop_error = $error_data + ['message' => $result['message']];
                        break;
                    }
                }
            }
        } else {
            wp_send_json_error(['message' => __('Unsupported provider or processor missing.', 'gpt3-ai-content-generator')], 400);
            return;
        }

        if ($stop_error || (empty($successful_posts) && !empty($failed_posts_log))) {
            $error = $stop_error ?? ($first_error_data + ['message' => reset($failed_posts_log)]);
            $error['failed_posts_summary'] = array_keys($failed_posts_log);
            $error['successful_posts'] = $successful_posts;
            $error['unprocessed_posts'] = array_values(array_diff($post_ids, $successful_posts, array_keys($failed_posts_log)));
            wp_send_json_error($error, $error['status'] ?? 400);
            return;
        }
        /* translators: %1$d is the number of posts processed, %2$s is the vector store identifier */
        $response_message = sprintf(_n('%1$d post processed and submitted to vector store "%2$s".', '%1$d posts processed and submitted to vector store "%2$s".', $processed_count, 'gpt3-ai-content-generator'), $processed_count, esc_html($store_identifier_for_msg));
        if (!empty($failed_posts_log)) {
            /* translators: %d is the number of posts that failed to index */
            $response_message .= ' ' . sprintf(__('Some posts failed: %d. Check data source logs for details.', 'gpt3-ai-content-generator'), count($failed_posts_log));
        }

        wp_send_json_success([
            'message' => $response_message,
            'processed_count' => $processed_count,
            'total_count' => count($post_ids),
            'jobs' => $jobs,
            'new_store_id' => $new_store_id, // This will be null unless OpenAI created a new store (not applicable in this flow)
            'failed_posts_summary' => array_keys($failed_posts_log)
        ]);
    }
}
