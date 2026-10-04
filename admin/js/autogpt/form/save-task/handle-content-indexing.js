(function () {
  "use strict";

  /**
   * Adds content-indexing specific fields to the data object and removes irrelevant ones.
   * @param {Object} data - The data object to modify.
   * @param {FormData} formData - The original FormData object to get multi-select values.
   * @returns {Object} The modified data object.
   */
  function aipkit_save_task_handleContentIndexing(data, formData) {
    if (data.task_type === "content_indexing") {
      data.post_types = formData.getAll("post_types[]");
      data.indexing_categories = formData.getAll("indexing_categories[]");
      data.target_store_provider =
        formData.get("target_store_provider") || "openai";
      data.target_store_id = formData.get("target_store_id") || "";

      if (
        data.target_store_provider === "local" ||
        data.target_store_provider === "pinecone" ||
        data.target_store_provider === "qdrant" ||
        data.target_store_provider === "chroma"
      ) {
        // Parse combined embedding provider/model value (provider::model).
        const combinedEmbedding = formData.get("embedding_model") || "";
        if (combinedEmbedding && combinedEmbedding.includes("::")) {
          const [provider, model] = combinedEmbedding.split("::", 2);
          data.embedding_provider = provider;
          data.embedding_model = model;
        } else {
          // If malformed or empty, set to empty to fail validation gracefully
          data.embedding_provider = "";
          data.embedding_model = combinedEmbedding;
        }
      } else {
        delete data.embedding_provider;
        delete data.embedding_model;
      }

      const otherTaskKeysToDelete = [
        // Content Writing
        "ai_provider",
        "ai_model",
        "content_title_bulk",
        "content_keywords",
        "ai_temperature",
        "content_max_tokens",
        "post_type",
        "post_author",
        "post_status",
        "post_content_format",
        "post_schedule_date",
        "post_schedule_time",
        "post_categories",
        "post_categories[]",
        "prompt_mode",
        "custom_title_prompt",
        "custom_content_prompt",
        "generate_meta_description",
        "custom_meta_prompt",
        "generate_focus_keyword",
        "custom_keyword_prompt",
        "cw_generation_mode",
        "rss_feeds",
        "rss_include_keywords",
        "rss_exclude_keywords",
        "rss_item_limit",
        "gsheets_sheet_id",
        "gsheets_credentials",
        "url_list",
        "content_title",
        "generate_toc",
        "generate_images_enabled",
        "image_provider",
        "image_model",
        "image_provider_options",
        "image_prompt",
        "openai_canvas_size",
        "openai_quality",
        "openai_output_format",
        "openai_output_compression",
        "openai_background",
        "openai_moderation",
        "azure_canvas_size",
        "azure_quality",
        "azure_output_format",
        "azure_output_compression",
        "azure_background",
        "google_aspect_ratio",
        "google_image_size",
        "openrouter_aspect_ratio",
        "openrouter_image_size",
        "openrouter_quality",
        "openrouter_output_format",
        "openrouter_output_compression",
        "openrouter_background",
        "xai_aspect_ratio",
        "xai_resolution",
        "replicate_aspect_ratio",
        "replicate_width",
        "replicate_height",
        "replicate_negative_prompt",
        "replicate_guidance",
        "replicate_num_inference_steps",
        "replicate_seed",
        "replicate_output_format",
        "replicate_output_quality",
        "image_count",
        "image_placement",
        "image_placement_param_x",
        "image_alignment",
        "image_size",
        "generate_featured_image",
        "featured_image_prompt",
        "pexels_orientation",
        "pexels_size",
        "pexels_color",
        "pixabay_orientation",
        "pixabay_image_type",
        "pixabay_category",
        "enable_vector_store",
        "vector_store_provider",
        "openai_vector_store_ids",
        "openai_vector_store_ids[]",
        "google_file_search_store_names",
        "google_file_search_store_names[]",
        "pinecone_index_name",
        "qdrant_collection_name",
        "chroma_collection_name",
        "vector_store_top_k",
        "csv_file_input",
        // Content Enhancement (ce_ prefixed)
        "ce_post_types",
        "ce_post_types[]",
        "ce_post_categories",
        "ce_post_categories[]",
        "ce_post_authors",
        "ce_post_authors[]",
        "ce_post_statuses",
        "ce_post_statuses[]",
        "ce_update_title",
        "ce_update_excerpt",
        "ce_update_content",
        "ce_update_meta",
        "ce_title_prompt",
        "ce_excerpt_prompt",
        "ce_content_prompt",
        "ce_meta_prompt",
        "ce_ai_provider",
        "ce_ai_model",
        "ce_ai_temperature",
        "ce_content_max_tokens",
        "ce_enable_vector_store",
        "ce_vector_store_provider",
        "ce_openai_vector_store_ids",
        "ce_openai_vector_store_ids[]",
        "ce_google_file_search_store_names",
        "ce_google_file_search_store_names[]",
        "ce_pinecone_index_name",
        "ce_qdrant_collection_name",
        "ce_chroma_collection_name",
        "ce_vector_embedding_provider",
        "ce_vector_embedding_model",
        "ce_vector_store_top_k",
        "ce_enhance_existing_now_flag",
      ];
      otherTaskKeysToDelete.forEach((key) => delete data[key]);
    } else {
      // Clear content_indexing specific fields if another task type
      delete data.indexing_categories;
      delete data["indexing_categories[]"];
      delete data.post_types;
      delete data["post_types[]"];
      delete data.target_store_provider;
      delete data.target_store_id;
      delete data.index_existing_now_flag;
      delete data.only_new_updated_flag;
      delete data.embedding_model;
      delete data.embedding_provider;
    }
    return data;
  }

  window.aipkit_save_task_handleContentIndexing =
    aipkit_save_task_handleContentIndexing;
})();
