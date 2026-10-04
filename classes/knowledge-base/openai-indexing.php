<?php


namespace WPAICG\Dashboard\Ajax;

use WPAICG\Vector\AIPKit_Vector_Store_Manager;
use WPAICG\Vector\AIPKit_Vector_Store_Registry;

// Shared OpenAI utility functions are loaded by Vector_Store_Ajax_Handlers_Loader.

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Handles AJAX requests for fetching and indexing WordPress content into OpenAI Vector Stores.
 */
class AIPKit_OpenAI_WP_Content_Indexing_Ajax_Handler extends BaseDashboardAjaxHandler
{
    private $vector_store_manager;
    private $vector_store_registry;
    private $openai_post_processor;


    public function __construct()
    {
        $this->vector_store_manager = $this->create_vector_store_manager();
        $this->vector_store_registry = $this->create_vector_store_registry();

        if (!class_exists(\WPAICG\Vector\PostProcessor\OpenAI\OpenAIPostProcessor::class)) {
            $processor_path = WPAICG_PLUGIN_DIR . 'classes/knowledge-base/indexing-openai.php';
            if (file_exists($processor_path)) {
                require_once $processor_path;
            }
        }
        if (class_exists(\WPAICG\Vector\PostProcessor\OpenAI\OpenAIPostProcessor::class)) {
            $this->openai_post_processor = new \WPAICG\Vector\PostProcessor\OpenAI\OpenAIPostProcessor();
        }
    }

    public function get_openai_post_processor(): ?\WPAICG\Vector\PostProcessor\OpenAI\OpenAIPostProcessor
    {
        return $this->openai_post_processor;
    }
    public function get_vector_store_manager(): ?AIPKit_Vector_Store_Manager
    {
        return $this->vector_store_manager;
    }
    public function get_vector_store_registry(): ?AIPKit_Vector_Store_Registry
    {
        return $this->vector_store_registry;
    }


    public function ajax_fetch_wp_content_for_indexing()
    {
        $permission_check = $this->check_any_module_access_permissions(['sources', 'chatbot'], 'aipkit_fetch_wp_content_for_indexing');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        \WPAICG\Dashboard\Ajax\OpenAI\HandlerIndexing\do_ajax_fetch_wp_content_for_indexing_logic($this);
    }

    public function ajax_index_selected_wp_content()
    {
        $permission_check = $this->check_any_module_access_permissions(['sources', 'chatbot'], 'aipkit_index_wp_content_nonce');
        if (is_wp_error($permission_check)) {
            $this->send_wp_error($permission_check);
            return;
        }
        \WPAICG\Dashboard\Ajax\OpenAI\HandlerIndexing\do_ajax_index_selected_wp_content_logic($this);
    }
}

namespace WPAICG\Dashboard\Ajax\OpenAI\HandlerIndexing;

use WPAICG\Dashboard\Ajax\AIPKit_OpenAI_WP_Content_Indexing_Ajax_Handler;
use WP_Error;
use WP_Query;
use WPAICG\AIPKit_Providers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles the logic for fetching WordPress content for indexing.
 * Called by AIPKit_OpenAI_WP_Content_Indexing_Ajax_Handler::ajax_fetch_wp_content_for_indexing().
 *
 * @param AIPKit_OpenAI_WP_Content_Indexing_Ajax_Handler $handler_instance
 * @return void
 */
function do_ajax_fetch_wp_content_for_indexing_logic(AIPKit_OpenAI_WP_Content_Indexing_Ajax_Handler $handler_instance): void {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $post_data = wp_unslash($_POST);
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $post_types    = isset($post_data['post_types']) && is_array($post_data['post_types']) ? array_map('sanitize_key', $post_data['post_types']) : ['post'];
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $post_status   = isset($post_data['post_status']) ? sanitize_key($post_data['post_status']) : 'publish';
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $paged         = isset($post_data['paged']) ? absint($post_data['paged']) : 1;
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $target_store_id = isset($post_data['target_store_id']) ? sanitize_text_field($post_data['target_store_id']) : null;
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $posts_per_page = 15;
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $get_content_for_ids = isset($post_data['get_content_for_ids']) && is_array($post_data['get_content_for_ids']) ? array_map('absint', $post_data['get_content_for_ids']) : null;

    $args = [
        'post_type'      => $post_types,
        'post_status'    => ($post_status === 'any') ? ['publish', 'draft', 'pending', 'private'] : $post_status,
        'posts_per_page' => $posts_per_page,
        'paged'          => $paged,
        'orderby'        => 'modified',
        'order'          => 'DESC',
    ];

    if (!empty($get_content_for_ids)) {
        $args['post__in'] = $get_content_for_ids;
        $args['posts_per_page'] = -1;
        $args['paged'] = 1;
        $args['orderby'] = 'post__in';
        if (count(array_diff(get_post_types(['public' => true]), $post_types)) > 0 || count(array_diff($post_types, get_post_types(['public' => true]))) > 0) {
             $args['post_type'] = get_post_types();
        }
        $args['post_status'] = 'any';
    }

    $query = new WP_Query($args);
    $posts_data = [];
    $posts_content_map = [];

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            $post_type_obj = get_post_type_object(get_post_type());
            $is_indexed = false;
            if ($target_store_id) {
                $is_indexed = (bool) get_post_meta($post_id, '_aipkit_indexed_to_vs_' . sanitize_key($target_store_id), true);
            }

            $post_item = [
                'id'         => $post_id,
                'title'      => get_the_title(),
                'type_label' => $post_type_obj ? $post_type_obj->labels->singular_name : get_post_type(),
                'edit_link'  => get_edit_post_link($post_id),
                'is_already_indexed' => $is_indexed,
            ];

            if (!empty($get_content_for_ids) && in_array($post_id, $get_content_for_ids)) {
                $openai_post_processor = $handler_instance->get_openai_post_processor();
                if ($openai_post_processor && method_exists($openai_post_processor, 'get_post_content_as_string')) {
                    $content_string_or_error = $openai_post_processor->get_post_content_as_string($post_id); // Use public method
                    if (!is_wp_error($content_string_or_error)) {
                        $posts_content_map[$post_id] = [
                            'content' => $content_string_or_error,
                            'title' => $post_item['title'],
                            'type_label' => $post_item['type_label'],
                            'edit_link' => $post_item['edit_link']
                        ];
                    } else {
                        $posts_content_map[$post_id] = ['content' => 'Error: Could not retrieve content.', 'title' => $post_item['title']];
                    }
                } else {
                    $posts_content_map[$post_id] = ['content' => 'Error: Content processing component missing.', 'title' => $post_item['title']];
                }
            }
            $posts_data[] = $post_item;
        }
        wp_reset_postdata();
    }

    $response_data = [
        'posts' => $posts_data,
        'pagination' => [
            'total_posts'  => (int) $query->found_posts,
            'total_pages'  => (int) $query->max_num_pages,
            'current_page' => (int) $paged,
        ]
    ];
    if (!empty($get_content_for_ids)) {
        $response_data['posts_content'] = $posts_content_map;
    }
    wp_send_json_success($response_data);
}

// Shared OpenAI utility functions are loaded by Vector_Store_Ajax_Handlers_Loader.



/**
 * Handles the logic for fetching and indexing WordPress content into an OpenAI Vector Store.
 * Called by AIPKit_OpenAI_WP_Content_Indexing_Ajax_Handler::ajax_index_selected_wp_content().
 */
function do_ajax_index_selected_wp_content_logic(AIPKit_OpenAI_WP_Content_Indexing_Ajax_Handler $handler_instance): void
{
    // Permission check already done by the handler calling this

    $openai_post_processor = $handler_instance->get_openai_post_processor();
    $vector_store_manager = $handler_instance->get_vector_store_manager();
    $vector_store_registry = $handler_instance->get_vector_store_registry();

    if (!$openai_post_processor || !$vector_store_manager || !$vector_store_registry) {
        $handler_instance->send_wp_error(new WP_Error('processor_missing', __('Vector processing components are missing.', 'gpt3-ai-content-generator'), ['status' => 500]));
        return;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $post_data = wp_unslash($_POST);
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $post_ids_raw = isset($post_data['post_ids']) && is_array($post_data['post_ids']) ? $post_data['post_ids'] : [];
    $post_ids = array_map('absint', $post_ids_raw);
    $post_ids = array_filter($post_ids, function ($id) {
        return $id > 0;
    });
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $target_store_id = isset($post_data['target_store_id']) ? sanitize_text_field($post_data['target_store_id']) : '';
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $new_store_name = isset($post_data['new_store_name_openai']) ? sanitize_text_field($post_data['new_store_name_openai']) : '';
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked in the calling handler method.
    $provider = isset($post_data['provider']) ? sanitize_key($post_data['provider']) : 'openai';

    if (empty($post_ids)) {
        wp_send_json_error(['message' => __('No posts selected for indexing.', 'gpt3-ai-content-generator')], 400);
        return;
    }
    if (empty($target_store_id) && (empty($new_store_name) || $provider !== 'openai')) {
        wp_send_json_error(['message' => __('Please select an existing vector store or provide a name for a new OpenAI store.', 'gpt3-ai-content-generator')], 400);
        return;
    }
    if ($provider !== 'openai') {
        wp_send_json_error(['message' => __('Currently, only OpenAI vector stores are supported for WordPress content indexing from this UI.', 'gpt3-ai-content-generator')], 400);
        return;
    }

    $openai_config = AIPKit_Providers::get_provider_data('OpenAI');
    if (empty($openai_config['api_key'])) {
        $handler_instance->send_wp_error(new WP_Error('missing_openai_key', __('OpenAI API Key is not configured in global settings.', 'gpt3-ai-content-generator')));
        return;
    }

    $actual_store_id = $target_store_id;
    $actual_store_name = '';
    $is_new_store_created = false;

    if (!empty($new_store_name) && $provider === 'openai') {
        $index_config = ['metadata' => ['source_type' => 'wp_content_ai_training']];
        $create_result = $vector_store_manager->create_index_if_not_exists('OpenAI', $new_store_name, $index_config, $openai_config);
        if (is_wp_error($create_result)) {
            wp_send_json_error(['message' => 'Failed to create new vector store: ' . $create_result->get_error_message()], 500);
            return;
        }
        if (is_array($create_result) && isset($create_result['id'])) {
            $actual_store_id = $create_result['id'];
            $actual_store_name = $create_result['name'] ?? $new_store_name;
            $is_new_store_created = true;
            if ($vector_store_registry) {
                $vector_store_registry->add_registered_store('OpenAI', $create_result);
            }
        } else {
            wp_send_json_error(['message' => 'Failed to create or identify vector store ID after creation attempt.'], 500);
            return;
        }
    } elseif (!empty($actual_store_id)) {
        $existing_store_details = $vector_store_manager->describe_single_index('OpenAI', $actual_store_id, $openai_config);
        if (!is_wp_error($existing_store_details) && isset($existing_store_details['name'])) {
            $actual_store_name = $existing_store_details['name'];
        }
    }
    if (empty($actual_store_id)) {
        wp_send_json_error(['message' => __('Could not determine target vector store ID.', 'gpt3-ai-content-generator')], 500);
        return;
    }

    $processed_count = 0;
    $failed_posts_log = [];
    $jobs = [];

    foreach ($post_ids as $post_id) {
        $result = $openai_post_processor->index_single_post_to_store($post_id, $actual_store_id, $actual_store_name);
        if ($result['status'] === 'success') {
            if (!empty($result['job_id'])) {
                $jobs[] = ['job_id' => (int) $result['job_id'], 'post_id' => $post_id, 'status' => !empty($result['processing']) ? 'processing' : 'indexed'];
            }
            $processed_count++;
        } else {
            $failed_posts_log[$post_id] = $result['message'];
        }
    }

    if ($vector_store_registry) {
        $updated_store_data = $vector_store_manager->describe_single_index('OpenAI', $actual_store_id, $openai_config);
        if (!is_wp_error($updated_store_data) && is_array($updated_store_data) && isset($updated_store_data['id'])) {
            $vector_store_registry->add_registered_store('OpenAI', $updated_store_data);
        }
    }

    if (!$processed_count && $failed_posts_log) {
        wp_send_json_error(['message' => reset($failed_posts_log), 'failed_posts_summary' => array_keys($failed_posts_log)], 400);
        return;
    }

    /* translators: %1$d: The number of posts processed, %2$s: The name of the vector store. */
    $response_message = sprintf(_n('%1$d post processed and submitted to vector store "%2$s".', '%1$d posts processed and submitted to vector store "%2$s".', $processed_count, 'gpt3-ai-content-generator'), $processed_count, esc_html($actual_store_name ?: $actual_store_id));

    if (!empty($failed_posts_log)) {
        /* translators: %d: Number of failed posts */
        $response_message .= ' ' . sprintf(__('Some posts failed: %d. Check data source logs for details.', 'gpt3-ai-content-generator'), count($failed_posts_log));
    }

    wp_send_json_success([
        'message' => $response_message,
        'processed_count' => $processed_count,
        'jobs' => $jobs,
        'total_count' => count($post_ids),
        'new_store_id' => ($is_new_store_created ? $actual_store_id : null),
        'failed_posts_summary' => array_keys($failed_posts_log)
    ]);
}
