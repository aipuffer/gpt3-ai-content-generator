<?php


namespace WPAICG\Vector\PostProcessor\Base;

use WPAICG\Core\AIPKit_Event_Webhooks;
use WPAICG\Vector\AIPKit_Vector_Embedding_Batch_Policy;
use WPAICG\Vector\AIPKit_Vector_Text_Chunker;
use WPAICG\Vector\AIPKit_Vector_Store_Manager;
use WPAICG\Vector\Extraction\AIPKit_Knowledge_Content_Extractor;
use WP_Error;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

require_once dirname(__DIR__) . '/ai/http.php';

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This file intentionally uses core WordPress hook names.

/**
 * Base class for Vector Post Processor provider-specific classes.
 * Contains common utility methods for fetching content and logging.
 */
abstract class AIPKit_Vector_Post_Processor_Base
{
    protected $data_source_table_name;

    /** @var array<int,string> */
    private $last_extraction_fingerprints = [];

    public function __construct()
    {
        global $wpdb;
        $this->data_source_table_name = $wpdb->prefix . 'aipkit_vector_data_source';
    }

    /** Preserve public request metadata while logging an indexing failure. */
    protected function indexing_error($error, array $log, string $message = ''): array
    {
        $message = $message !== '' ? $message : (is_wp_error($error) ? $error->get_error_message() : (string) $error);
        $this->log_event(array_merge($log, ['status' => 'failed', 'message' => $message]));
        return [
            'status' => 'error',
            'message' => $message,
            'error_data' => is_wp_error($error) ? \WPAICG\Core\AIPKit_HTTP_Request::public_error_data($error, 400) : [],
        ];
    }

    protected function create_vector_store_manager(): ?AIPKit_Vector_Store_Manager
    {
        return $this->ensure_plugin_class(
            AIPKit_Vector_Store_Manager::class,
            'classes/knowledge-base/manager.php'
        )
            ? new AIPKit_Vector_Store_Manager()
            : null;
    }

    protected function ensure_plugin_class(string $class_name, string $relative_path): bool
    {
        if (!class_exists($class_name)) {
            $class_path = WPAICG_PLUGIN_DIR . ltrim($relative_path, '/');
            if (file_exists($class_path)) {
                require_once $class_path;
            }
        }

        return class_exists($class_name);
    }

    /**
     * @return mixed[]|\WP_Error
     */
    protected function prepare_embedding_chunks(
        string $content_string,
        string $embedding_model,
        string $embedding_provider_key,
        string $target_id,
        string $vector_store_type,
        string $provider_label
    ) {
        if (!$this->ensure_plugin_class(AIPKit_Vector_Text_Chunker::class, 'classes/knowledge-base/chunker.php')) {
            return new WP_Error(
                'aipkit_vector_chunker_unavailable',
                /* translators: %s: Vector database provider label. */
                sprintf(__('Vector text chunker is not available for %s indexing.', 'gpt3-ai-content-generator'), $provider_label)
            );
        }

        $chunks = AIPKit_Vector_Text_Chunker::chunk_for_embeddings(
            $content_string,
            $embedding_model,
            $embedding_provider_key,
            $target_id,
            $vector_store_type
        );

        if (empty($chunks)) {
            return new WP_Error(
                'aipkit_vector_chunks_empty',
                /* translators: %s: Vector database provider label. */
                sprintf(__('Could not prepare %s chunks for this post.', 'gpt3-ai-content-generator'), $provider_label)
            );
        }

        return $chunks;
    }

    protected function resolve_embedding_batch_size(
        string $embedding_provider,
        string $vector_provider,
        string $embedding_model,
        string $target_id,
        int $post_id,
        int $fallback
    ): int {
        if (!$this->ensure_plugin_class(AIPKit_Vector_Embedding_Batch_Policy::class, 'classes/knowledge-base/embedding-batches.php')) {
            return $fallback;
        }

        return AIPKit_Vector_Embedding_Batch_Policy::resolve_batch_size(
            $embedding_provider,
            [
                'source' => 'post_processor',
                'vector_provider' => $vector_provider,
                'embedding_model' => $embedding_model,
                'target_id' => $target_id,
                'post_id' => $post_id,
            ],
            $fallback
        );
    }

    /**
     * Logs an entry for vector store related events to the `wp_aipkit_vector_data_source` table.
     *
     * @param array $log_data Data for the log entry.
     */
    protected function log_event(array $log_data): int
    {
        global $wpdb;
        $defaults = [
            'user_id'             => get_current_user_id(),
            'timestamp'           => current_time('mysql', 1),
            'provider'            => 'UnknownProvider',
            'vector_store_id'     => 'unknown_store',
            'vector_store_name'   => null,
            'post_id'             => null,
            'post_title'          => null,
            'status'              => 'info',
            'message'             => '',
            'indexed_content'     => null,
            'file_id'             => null,
            'batch_id'            => null,
            'embedding_provider'  => null,
            'embedding_model'     => null,
            'source_type_for_log' => null, // Internal helper for conditional truncation
        ];
        $data_to_insert = wp_parse_args($log_data, $defaults);

        $post_id = isset($data_to_insert['post_id']) ? absint($data_to_insert['post_id']) : 0;
        $extraction_fingerprint = isset($data_to_insert['extraction_fingerprint'])
            ? self::sanitize_extraction_fingerprint($data_to_insert['extraction_fingerprint'])
            : $this->get_last_extraction_fingerprint($post_id);
        if ($extraction_fingerprint === '' && $post_id > 0) {
            $post = get_post($post_id);
            if ($post instanceof \WP_Post) {
                $extraction_fingerprint = self::sanitize_extraction_fingerprint(
                    apply_filters('aipkit_knowledge_base_extraction_fingerprint', '', $post)
                );
            }
        }

        $source_type = $data_to_insert['source_type_for_log'] ?? ($data_to_insert['post_id'] ? 'wordpress_post' : 'unknown');
        $should_truncate = true;
        if (in_array($source_type, ['text_entry_global_form', 'file_upload_global_form', 'text_entry_pinecone_direct', 'file_upload_pinecone_direct', 'text_entry_qdrant_direct', 'file_upload_qdrant_direct', 'chatbot_training_text', 'chatbot_training_qa'], true)) {
            $should_truncate = false;
        }

        if ($should_truncate && is_string($data_to_insert['indexed_content']) && mb_strlen($data_to_insert['indexed_content']) > 1000) {
            $data_to_insert['indexed_content'] = mb_substr($data_to_insert['indexed_content'], 0, 997) . '...';
        }
        unset($data_to_insert['source_type_for_log'], $data_to_insert['extraction_fingerprint']);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reason: Direct insert to a custom table for logging. Cache is invalidated.
        $result = $wpdb->insert($this->data_source_table_name, $data_to_insert);
        $log_id = (int) $wpdb->insert_id;

        if ($result !== false && $log_id > 0) {
            /**
             * Fires after a vector source log row is created.
             *
             * @param int    $log_id                 Source log row ID.
             * @param array  $data_to_insert         Stored source data without raw extraction-only fields.
             * @param string $extraction_fingerprint Optional extension-provided extraction fingerprint.
             */
            do_action(
                'aipkit_vector_source_log_changed',
                $log_id,
                $data_to_insert,
                $extraction_fingerprint
            );
        }

        if ($result !== false) {
            $this->emit_source_indexed_event($log_id, $data_to_insert);
        }
        return $result !== false ? $log_id : 0;
    }

    /** Emits completion only after a source is confirmed indexed. */
    protected function emit_source_indexed_event(int $log_id, array $data_to_insert): void
    {
        if (
            class_exists(AIPKit_Event_Webhooks::class)
            && (string) ($data_to_insert['status'] ?? '') === 'indexed'
        ) {
            $event_name = 'kb.source_indexed';
            $post_id = isset($data_to_insert['post_id']) ? (int) $data_to_insert['post_id'] : 0;

            AIPKit_Event_Webhooks::emit(
                $event_name,
                [
                    'source' => [
                        'log_id' => $log_id,
                        'post_id' => $post_id,
                        'post_title' => (string) ($data_to_insert['post_title'] ?? ''),
                        'post_url' => $post_id > 0 ? get_permalink($post_id) : '',
                    ],
                    'provider' => (string) ($data_to_insert['provider'] ?? ''),
                    'store' => [
                        'id' => (string) ($data_to_insert['vector_store_id'] ?? ''),
                        'name' => (string) ($data_to_insert['vector_store_name'] ?? ''),
                    ],
                    'status' => (string) ($data_to_insert['status'] ?? ''),
                    'message' => (string) ($data_to_insert['message'] ?? ''),
                    'file_id' => (string) ($data_to_insert['file_id'] ?? ''),
                    'batch_id' => (string) ($data_to_insert['batch_id'] ?? ''),
                    'embedding' => [
                        'provider' => (string) ($data_to_insert['embedding_provider'] ?? ''),
                        'model' => (string) ($data_to_insert['embedding_model'] ?? ''),
                    ],
                ],
                [
                    'module' => 'knowledge_base',
                    'origin' => 'vector_post_processor_log',
                    'resource' => [
                        'type' => 'vector_source',
                        'id' => $log_id > 0 ? $log_id : ((string) ($data_to_insert['vector_store_id'] ?? '')),
                        'label' => (string) ($data_to_insert['post_title'] ?? __('KB source', 'gpt3-ai-content-generator')),
                    ],
                    'meta' => [
                        'provider' => (string) ($data_to_insert['provider'] ?? ''),
                        'vector_store_id' => (string) ($data_to_insert['vector_store_id'] ?? ''),
                        'post_id' => $post_id,
                        'log_status' => (string) ($data_to_insert['status'] ?? ''),
                    ],
                    'idempotency_key' => sha1(implode('|', [
                        $event_name,
                        (string) $log_id,
                        (string) ($data_to_insert['provider'] ?? ''),
                        (string) ($data_to_insert['vector_store_id'] ?? ''),
                        (string) $post_id,
                        (string) ($data_to_insert['status'] ?? ''),
                        (string) ($data_to_insert['message'] ?? ''),
                    ])),
                ]
            );
        }
    }

    /**
     * Ignore numeric traffic counters only in automatic field selection.
     * Explicit Content Rules remain authoritative, including custom counters.
     *
     * @param mixed $value Stored field value.
     */
    private static function is_automatic_metadata_noise(string $key, $value): bool
    {
        return is_numeric($value) && (bool) preg_match(
            '/(?:^|[_-])(?:views?|page[_-]?views?|visits?|hits?)(?:[_-](?:count|counter|total))?$/i',
            $key
        );
    }

    /**
     * Gets the post content as a formatted string.
     * @param int $post_id The ID of the post.
     * @return string|WP_Error The formatted content string or WP_Error on failure.
     */
    public function get_post_content_as_string(int $post_id)
    {
        
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('post_not_found', __('Post not found.', 'gpt3-ai-content-generator'));
        }
        
        $metadata_header = "Source URL: " . get_permalink($post) . "\n";
        $metadata_header .= "Title: " . $post->post_title . "\n";

        $indexing_settings = get_option('aipkit_indexing_field_settings', []);
        $post_type = $post->post_type;
        $has_custom_settings = !empty($indexing_settings[$post_type]);
        
        // Get custom basic labels if available
        $source_url_label = 'Source URL';
        $title_label = 'Title';
        $excerpt_label = 'Excerpt';
        $content_label = 'Content';
        
        if ($has_custom_settings && !empty($indexing_settings[$post_type]['basic_labels'])) {
            $basic_labels = $indexing_settings[$post_type]['basic_labels'];
            $source_url_label = !empty($basic_labels['source_url']) ? $basic_labels['source_url'] : $source_url_label;
            $title_label = !empty($basic_labels['title']) ? $basic_labels['title'] : $title_label;
            $excerpt_label = !empty($basic_labels['excerpt']) ? $basic_labels['excerpt'] : $excerpt_label;
            $content_label = !empty($basic_labels['content']) ? $basic_labels['content'] : $content_label;
        }

        $metadata_header = $source_url_label . ": " . get_permalink($post) . "\n";
        $metadata_header .= $title_label . ": " . $post->post_title . "\n";

        if ($has_custom_settings) {
            // Use specific settings from AI Training -> Settings tab
            $cpt_settings = $indexing_settings[$post_type];
            if (!empty($cpt_settings['fields'])) {
                $custom_fields_string = "";
                foreach ($cpt_settings['fields'] as $meta_key => $field_config) {
                    // Check if field is enabled (should be boolean true after our fix)
                    if (!empty($field_config['enabled'])) {
                        $value = get_post_meta($post->ID, $meta_key, true);
                        
                        if (empty($value) || is_serialized($value) || is_array($value) || is_object($value) || mb_strlen($value) > 1000) {
                            continue;
                        }
                        $label = !empty($field_config['label']) ? $field_config['label'] : ucwords(str_replace(['_', '-'], ' ', $meta_key));
                        $custom_fields_string .= esc_html($label) . ": " . esc_html(trim($value)) . "\n";
                    }
                }
                if (!empty(trim($custom_fields_string))) $metadata_header .= "\n" . trim($custom_fields_string) . "\n";
            }
            if ($post_type === 'product' && class_exists('WooCommerce') && !empty($cpt_settings['woo_attributes'])) {
                $product = wc_get_product($post_id);
                if ($product) {
                    $woo_data_string = "";
                    foreach ($cpt_settings['woo_attributes'] as $attr_key => $attr_config) {
                        if (!empty($attr_config['enabled'])) {
                            $label = !empty($attr_config['label']) ? $attr_config['label'] : ucwords(str_replace('_', ' ', $attr_key));
                            $value = '';
                            switch ($attr_key) {
                                case 'sku': $value = $product->get_sku(); break;
                                case 'price': $value = html_entity_decode(wp_strip_all_tags($product->get_price_html())); break;
                                case 'stock': $value = $product->get_stock_status(); break;
                                case 'dimensions':
                                    $dimension_parts = [];
                                    if ($product->has_weight()) {
                                        $dimension_parts[] = $product->get_weight() . get_option('woocommerce_weight_unit');
                                    }
                                    if ($product->has_dimensions()) {
                                        $length = $product->get_length();
                                        $width = $product->get_width();
                                        $height = $product->get_height();
                                        $unit = get_option('woocommerce_dimension_unit');
                                        
                                        $dimensions = array_filter([$length, $width, $height]);
                                        if (!empty($dimensions)) {
                                            $dimension_parts[] = implode(' × ', $dimensions) . ' ' . $unit;
                                        }
                                    }
                                    $value = implode(', ', $dimension_parts);
                                    break;
                                case 'attributes':
                                    $attributes = $product->get_attributes();
                                    if (!empty($attributes)) {
                                        $attr_strings = [];
                                        foreach ($attributes as $attribute) {
                                            $attr_name = wc_attribute_label($attribute->get_name());
                                            $attr_values = $product->get_attribute($attribute->get_name());
                                            if (!empty($attr_values)) { $attr_strings[] = $attr_name . ': ' . $attr_values; }
                                        }
                                        $value = implode('; ', $attr_strings);
                                    }
                                    break;
                            }
                            if (!empty(trim($value))) $woo_data_string .= esc_html($label) . ": " . esc_html(trim($value)) . "\n";
                        }
                    }
                    if (!empty(trim($woo_data_string))) $metadata_header .= "\n" . trim($woo_data_string) . "\n";
                }
            }
            if (!empty($cpt_settings['taxonomies'])) {
                $all_term_names_by_label = [];
                $enabled_tax_count = 0;
                foreach ($cpt_settings['taxonomies'] as $tax_slug => $tax_config) {
                    if (!empty($tax_config['enabled'])) {
                        $enabled_tax_count++;
                        $terms = get_the_terms($post->ID, $tax_slug);
                        if (!empty($terms) && !is_wp_error($terms)) {
                            $term_names = wp_list_pluck($terms, 'name');
                            $taxonomy_obj = get_taxonomy($tax_slug);
                            $label = !empty($tax_config['label']) && $taxonomy_obj ? $tax_config['label'] : ($taxonomy_obj ? $taxonomy_obj->label : $tax_slug);
                            if (!isset($all_term_names_by_label[$label])) $all_term_names_by_label[$label] = [];
                            $all_term_names_by_label[$label] = array_merge($all_term_names_by_label[$label], $term_names);
                        }
                    }
                }
                $taxonomy_string = "";
                foreach ($all_term_names_by_label as $label => $terms) {
                    $taxonomy_string .= esc_html($label) . ": " . implode(', ', array_unique($terms)) . "\n";
                }
                if (!empty(trim($taxonomy_string))) $metadata_header .= "\n" . trim($taxonomy_string) . "\n";
            }
        } else {
            // --- FALLBACK: Original logic to index all public fields ---
            $all_meta = get_post_meta($post->ID);
            if (!empty($all_meta)) {
                $custom_fields_string = "";
                foreach ($all_meta as $meta_key => $meta_values) {
                    if (substr($meta_key, 0, 1) === '_' || empty($meta_values[0]) || self::is_automatic_metadata_noise($meta_key, $meta_values[0])) continue;
                    $value = $meta_values[0];
                    if (is_serialized($value) || is_array($value) || is_object($value) || mb_strlen($value) > 1000) continue;
                    $label = ucwords(str_replace(['_', '-'], ' ', $meta_key));
                    $custom_fields_string .= esc_html($label) . ": " . esc_html(trim($value)) . "\n";
                }
                if (!empty(trim($custom_fields_string))) $metadata_header .= "\n" . trim($custom_fields_string) . "\n";
            }
            if ($post->post_type === 'product' && class_exists('WooCommerce')) {
                $product = wc_get_product($post_id);
                if ($product) {
                    if ($product->get_sku()) $metadata_header .= "SKU: " . $product->get_sku() . "\n";
                    $price_html = $product->get_price_html(); if ($price_html) $metadata_header .= "Price: " . html_entity_decode(wp_strip_all_tags($price_html)) . "\n";
                    $metadata_header .= "Stock Status: " . ucfirst($product->get_stock_status()) . "\n";
                    if ($product->has_weight()) $metadata_header .= "Weight: " . $product->get_weight() . ' ' . get_option('woocommerce_weight_unit') . "\n";
                    if ($product->has_dimensions()) $metadata_header .= "Dimensions (LxWxH): " . $product->get_length() . 'x' . $product->get_width() . 'x' . $product->get_height() . ' ' . get_option('woocommerce_dimension_unit') . "\n";
                    $attributes = $product->get_attributes();
                    if (!empty($attributes)) {
                        $attr_strings = [];
                        foreach ($attributes as $attribute) {
                            $attr_name = wc_attribute_label($attribute->get_name());
                            $attr_values = $product->get_attribute($attribute->get_name());
                            if (!empty($attr_values)) { $attr_strings[] = $attr_name . ': ' . $attr_values; }
                        }
                        if (!empty($attr_strings)) $metadata_header .= "Attributes: " . implode('; ', $attr_strings) . "\n";
                    }
                }
            }
            $taxonomy_slugs = get_object_taxonomies($post, 'names');
            $all_term_names = [];
            if (!empty($taxonomy_slugs)) {
                foreach ($taxonomy_slugs as $taxonomy_slug) {
                    $taxonomy_obj = get_taxonomy($taxonomy_slug);
                    if (!$taxonomy_obj || !$taxonomy_obj->public) continue;
                    $terms = get_the_terms($post->ID, $taxonomy_slug);
                    if (!empty($terms) && !is_wp_error($terms)) {
                        $term_names = wp_list_pluck($terms, 'name');
                        $all_term_names = array_merge($all_term_names, $term_names);
                    }
                }
            }
            if (!empty($all_term_names)) {
                $metadata_header .= "Categories/Tags: " . implode(', ', array_unique($all_term_names)) . "\n";
            }
            // --- END FALLBACK ---
        }

        $content_body = "";

        if ($post->post_type === 'product' && class_exists('WooCommerce')) {
            $product = wc_get_product($post_id);
            if ($product) {
                $short_description = $product->get_short_description();
                if (!empty($short_description)) {
                    $content_body .= "Short Description: " . wp_strip_all_tags($short_description) . "\n\n";
                }
            }
        } elseif (has_excerpt($post)) { // Fallback for other post types
            $content_body .= $excerpt_label . ": " . wp_strip_all_tags(get_the_excerpt($post)) . "\n\n";
        }

        if (!$this->ensure_plugin_class(
            AIPKit_Knowledge_Content_Extractor::class,
            'classes/knowledge-base/extraction.php'
        )) {
            return new WP_Error(
                'aipkit_knowledge_content_extractor_unavailable',
                __('Knowledge content extractor is not available.', 'gpt3-ai-content-generator')
            );
        }

        $extraction_result = AIPKit_Knowledge_Content_Extractor::extract($post);
        $this->last_extraction_fingerprints[$post_id] = self::sanitize_extraction_fingerprint(
            apply_filters('aipkit_knowledge_base_extraction_fingerprint', '', $post)
        );
        $main_content = (string) ($extraction_result['content'] ?? '');

        $content_body .= $content_label . ": " . trim($main_content);

        // Combine metadata header with a clear separator
        $final_content = trim($metadata_header . "\n---\n\n" . $content_body);
        
        return $final_content;
    }

    protected function get_last_extraction_fingerprint(int $post_id): string
    {
        return $post_id > 0 && isset($this->last_extraction_fingerprints[$post_id])
            ? $this->last_extraction_fingerprints[$post_id]
            : '';
    }

    private static function sanitize_extraction_fingerprint($fingerprint): string
    {
        if (!is_scalar($fingerprint)) {
            return '';
        }

        $fingerprint = strtolower(trim((string) $fingerprint));

        return preg_match('/^[a-f0-9]{64}$/', $fingerprint) ? $fingerprint : '';
    }


    /**
     * Creates a temporary file from a string of content.
     *
     * @param string $content_string The content to write to the file.
     * @param string $filename_prefix Prefix for the temporary filename.
     * @return string|WP_Error The path to the temporary file or WP_Error on failure.
     */
    protected function create_temp_file_from_string(string $content_string, string $filename_prefix = 'aipkit-content')
    {
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . '/wp-admin/includes/file.php';
            WP_Filesystem();
        }

        $temp_file_path = wp_tempnam($filename_prefix);
        if ($temp_file_path === false) {
            return new WP_Error('temp_file_creation_failed', __('Could not create temporary file for content.', 'gpt3-ai-content-generator'));
        }

        $final_temp_file_path = dirname($temp_file_path) . '/' . basename($temp_file_path, '.tmp') . '.txt';
        if ($wp_filesystem->move($temp_file_path, $final_temp_file_path, true)) { // true to overwrite
            $temp_file_path = $final_temp_file_path;
        }

        $bytes_written = $wp_filesystem->put_contents($temp_file_path, $content_string);
        if ($bytes_written === false) {
            wp_delete_file($temp_file_path);
            return new WP_Error('temp_file_write_failed', __('Could not write content to temporary file.', 'gpt3-ai-content-generator'));
        }
        return $temp_file_path;
    }
}
