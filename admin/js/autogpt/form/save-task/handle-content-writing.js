/**
 * AIPKit Automated Tasks - Save Task: Handle Content Writing Data
 * Prepares the data object for a 'content_writing' task.
 */
(function () {
  "use strict";

  /**
   * Adds content-writing specific fields to the data object and removes irrelevant ones.
   * @param {Object} data - The data object to modify.
   * @param {FormData} formData - The original FormData object to get multi-select values.
   * @returns {Object} The modified data object.
   */
  function aipkit_save_task_handleContentWriting(data, formData) {
    if (data.task_type.startsWith("content_writing")) {
      const sourceFieldsByTaskType = {
        content_writing_bulk: ["content_title_bulk"],
        content_writing_csv: ["content_title"],
        content_writing_rss: [
          "rss_feeds",
          "rss_include_keywords",
          "rss_exclude_keywords",
          "rss_item_limit",
        ],
        content_writing_url: ["url_list"],
        content_writing_gsheets: [
          "gsheets_sheet_id",
          "gsheets_credentials",
        ],
      };
      const sourceFields = Object.values(sourceFieldsByTaskType).flat();
      const activeSourceFields = new Set(
        sourceFieldsByTaskType[data.task_type] || ["content_title_bulk"]
      );

      sourceFields.forEach((key) => {
        if (!activeSourceFields.has(key)) {
          delete data[key];
        }
      });
      delete data.csv_file_input;

      data["post_categories"] = formData.getAll("post_categories[]");
      data["openai_vector_store_ids"] = formData.getAll(
        "openai_vector_store_ids[]"
      );
      data["google_file_search_store_names"] = formData.getAll(
        "google_file_search_store_names[]"
      );
      delete data["post_categories[]"];
      delete data["openai_vector_store_ids[]"];
      delete data["google_file_search_store_names[]"];

      const otherTaskKeysToDelete = [
        // Content Indexing
        "post_types",
        "post_types[]",
        "index_existing_now_flag",
        "only_new_updated_flag",
        "target_store_provider",
        "target_store_id",
        "embedding_provider",
        "embedding_model",
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
        "ce_local_store_id",
        "ce_vector_embedding_provider",
        "ce_vector_embedding_model",
        "ce_vector_store_top_k",
        "ce_enhance_existing_now_flag",
      ];
      otherTaskKeysToDelete.forEach((key) => delete data[key]);
    } else {
      // Clear content_writing fields if another task type is selected
      delete data.content_title;
      delete data.content_title_bulk;
      delete data.content_keywords;
      delete data.content_length;
      delete data.ai_temperature;
      delete data.post_type;
      delete data.post_author;
      delete data.post_status;
      delete data.post_content_format;
      delete data["post_categories[]"];
      delete data.post_categories;
      delete data.prompt_mode;
      delete data.custom_title_prompt;
      delete data.custom_content_prompt;
      delete data.generate_meta_description;
      delete data.custom_meta_prompt;
      delete data.generate_focus_keyword;
      delete data.custom_keyword_prompt;
      delete data.cw_generation_mode;
      delete data.rss_feeds;
      delete data.gsheets_sheet_id;
      delete data.gsheets_credentials;
      delete data.url_list;
      delete data.generate_toc;
      delete data.seo_score_improvement_enabled;
      delete data.seo_score_continue_until_target;
      delete data.seo_score_target;
      delete data.seo_score_max_passes;
      delete data.seo_score_profile;
      delete data.seo_score_disabled_rules;
      delete data.generate_images_enabled;
      delete data.image_provider;
      delete data.image_model;
      delete data.image_provider_options;
      delete data.image_prompt;
      delete data.openai_canvas_size;
      delete data.openai_quality;
      delete data.openai_output_format;
      delete data.openai_output_compression;
      delete data.openai_background;
      delete data.openai_moderation;
      delete data.azure_canvas_size;
      delete data.azure_quality;
      delete data.azure_output_format;
      delete data.azure_output_compression;
      delete data.azure_background;
      delete data.google_aspect_ratio;
      delete data.google_image_size;
      delete data.openrouter_aspect_ratio;
      delete data.openrouter_image_size;
      delete data.openrouter_quality;
      delete data.openrouter_output_format;
      delete data.openrouter_output_compression;
      delete data.openrouter_background;
      delete data.xai_aspect_ratio;
      delete data.xai_resolution;
      delete data.replicate_aspect_ratio;
      delete data.replicate_width;
      delete data.replicate_height;
      delete data.replicate_negative_prompt;
      delete data.replicate_guidance;
      delete data.replicate_num_inference_steps;
      delete data.replicate_seed;
      delete data.replicate_output_format;
      delete data.replicate_output_quality;
      delete data.image_count;
      delete data.image_placement;
      delete data.image_placement_param_x;
      delete data.image_alignment;
      delete data.image_size;
      delete data.generate_featured_image;
      delete data.featured_image_prompt;
      delete data.pexels_orientation;
      delete data.pexels_size;
      delete data.pexels_color;
      delete data.pixabay_orientation;
      delete data.pixabay_image_type;
      delete data.pixabay_category;
      delete data.enable_vector_store;
      delete data.vector_store_provider;
      delete data["openai_vector_store_ids[]"];
      delete data.openai_vector_store_ids;
      delete data["google_file_search_store_names[]"];
      delete data.google_file_search_store_names;
      delete data.pinecone_index_name;
      delete data.qdrant_collection_name;
      delete data.chroma_collection_name;
      delete data.local_store_id;
      delete data.vector_embedding_provider;
      delete data.vector_embedding_model;
      delete data.vector_store_top_k;
      delete data.rss_include_keywords;
      delete data.rss_exclude_keywords;
      delete data.rss_item_limit;
    }
    return data;
  }

  window.aipkit_save_task_handleContentWriting =
    aipkit_save_task_handleContentWriting;
})();
