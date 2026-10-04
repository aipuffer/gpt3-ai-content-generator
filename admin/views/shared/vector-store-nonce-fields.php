<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<input type="hidden" id="aipkit_vector_store_nonce_openai" value="<?php echo esc_attr(wp_create_nonce('aipkit_vector_store_nonce_openai')); ?>">
<input type="hidden" id="aipkit_google_file_search_nonce" value="<?php echo esc_attr(wp_create_nonce('aipkit_google_file_search_nonce')); ?>">
<input type="hidden" id="aipkit_vector_store_pinecone_nonce_management" value="<?php echo esc_attr(wp_create_nonce('aipkit_vector_store_pinecone_nonce')); ?>">
<input type="hidden" id="aipkit_vector_store_qdrant_nonce_management" value="<?php echo esc_attr(wp_create_nonce('aipkit_vector_store_qdrant_nonce')); ?>">
<input type="hidden" id="aipkit_vector_store_local_nonce_management" value="<?php echo esc_attr(wp_create_nonce('aipkit_vector_store_local_nonce')); ?>">
<input type="hidden" id="aipkit_vector_store_chroma_nonce_management" value="<?php echo esc_attr(wp_create_nonce('aipkit_vector_store_chroma_nonce')); ?>">
<input type="hidden" id="aipkit_wp_content_fetch_nonce" value="<?php echo esc_attr(wp_create_nonce('aipkit_fetch_wp_content_for_indexing')); ?>">
<input type="hidden" id="aipkit_wp_content_index_nonce" value="<?php echo esc_attr(wp_create_nonce('aipkit_index_wp_content_nonce')); ?>">
<input type="hidden" id="aipkit_index_posts_nonce" value="<?php echo esc_attr(wp_create_nonce('aipkit_index_posts_to_vector_store_nonce')); ?>">
