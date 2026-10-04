
/**
 * AIPKit Content Writer - Get Default Template Configuration
 * Provides a default configuration object for the content writer form.
 */
(function () {
  "use strict";

  function aipkit_getDefaultContentWriterTemplateConfig() {
    const featureDefaults = window.aipkit_getNewFeatureDefaults?.() || {};
    const providerSelect = document.getElementById(
      "aipkit_content_writer_provider"
    );
    const tempSlider = document.getElementById(
      "aipkit_content_writer_temperature"
    );
    const authorSelect = document.getElementById(
      "aipkit_content_writer_post_author"
    );
    const currentUserId = authorSelect
      ? authorSelect.value
      : window.aipkit_dashboard?.currentUserId || "1";

    const allowedProviders = Array.from(providerSelect?.options || [])
      .filter((option) => option.value && !option.disabled)
      .map((option) => option.value);
    const selection = window.aipkit_resolveNewAiSelection?.({
      allowedProviders,
    }) || {
      providerKey:
        window.aipkit_dashboard?.newAiSelection?.provider_key || "openai",
      modelId: window.aipkit_dashboard?.newAiSelection?.model || "",
    };
    const providerFromSelect = selection.providerKey || "openai";
    const defaultModelForProvider = selection.modelId || "";

    const defaultTemperature = tempSlider
      ? tempSlider.defaultValue || "1.0"
      : "1.0";

    const defaultPrompts =
      window.aipkit_content_writer_config?.default_prompts || {};
    const defaultSmartSeoDisabledRules =
      typeof window.aipkit_getDefaultSmartSeoDisabledRules === "function"
        ? window.aipkit_getDefaultSmartSeoDisabledRules()
        : "[]";

    const config = {
      ai_provider: providerFromSelect,
      ai_model: defaultModelForProvider,
      content_title: "",
      content_title_bulk: "",
      content_keywords: "",
      ai_temperature: defaultTemperature,
      reasoning_effort: "none",
      post_type: "post",
      post_author: currentUserId,
      post_status: "draft",
      post_content_format: "gutenberg",
      post_schedule_date: "",
      post_schedule_time: "",
      schedule_mode: "immediate",
      smart_schedule_start_datetime: "",
      smart_schedule_interval_value: "1",
      smart_schedule_interval_unit: "hours",
      post_categories: [],
      prompt_mode: "custom",
      custom_title_prompt: defaultPrompts.title,
      custom_content_prompt: defaultPrompts.content,
      generate_title: "1",
      generate_content: "1",
      generate_meta_description: "1",
      custom_meta_prompt: defaultPrompts.meta,
      generate_focus_keyword: "1",
      custom_keyword_prompt: defaultPrompts.keyword,
      generate_excerpt: "1",
      custom_excerpt_prompt: defaultPrompts.excerpt,
      generate_tags: "1",
      custom_tags_prompt: defaultPrompts.tags,
      custom_title_prompt_update: "",
      custom_content_prompt_update: "",
      custom_meta_prompt_update: "",
      custom_keyword_prompt_update: "",
      custom_excerpt_prompt_update: "",
      custom_tags_prompt_update: "",
      cw_generation_mode: "task",
      rss_feeds: "",
      gsheets_sheet_id: "",
      gsheets_credentials: "",
      url_list: "",
      generate_toc: "0",
      generate_seo_slug: "0",
      seo_score_improvement_enabled: "0",
      seo_score_continue_until_target: "1",
      seo_score_target: "100",
      seo_score_max_passes: "3",
      seo_score_profile: "auto",
      seo_score_disabled_rules: defaultSmartSeoDisabledRules,
      // Image Settings
      generate_images_enabled: "0",
      image_provider: featureDefaults.image_provider ?? "",
      image_model: featureDefaults.image_model || "",
      image_provider_options: "{}",
      image_prompt:
        defaultPrompts.image ||
        "A high-quality, relevant image for an article about: {topic}",
      image_prompt_update: "",
      image_count: "1",
      image_placement: "after_first_h2",
      image_placement_param_x: "2",
      image_alignment: "none",
      image_size: "large",
      generate_image_title: "1",
      generate_image_alt_text: "1",
      generate_image_caption: "1",
      generate_image_description: "1",
      image_title_prompt:
        defaultPrompts.image_title ||
        "Write a concise image title based on: {topic}",
      image_alt_text_prompt:
        defaultPrompts.image_alt_text ||
        "Write clear alt text for the image based on: {topic}",
      image_caption_prompt:
        defaultPrompts.image_caption ||
        "Write a short image caption based on: {topic}",
      image_description_prompt:
        defaultPrompts.image_description ||
        "Write a brief image description based on: {topic}",
      image_title_prompt_update:
        defaultPrompts.image_title_update ||
        defaultPrompts.image_title ||
        "Write a concise image title based on: {topic}",
      image_alt_text_prompt_update:
        defaultPrompts.image_alt_text_update ||
        defaultPrompts.image_alt_text ||
        "Write clear alt text for the image based on: {topic}",
      image_caption_prompt_update:
        defaultPrompts.image_caption_update ||
        defaultPrompts.image_caption ||
        "Write a short image caption based on: {topic}",
      image_description_prompt_update:
        defaultPrompts.image_description_update ||
        defaultPrompts.image_description ||
        "Write a brief image description based on: {topic}",
      generate_featured_image: "0",
      featured_image_prompt:
        defaultPrompts.featured_image ||
        "An eye-catching, high-quality featured image for a blog post about: {topic}. Keywords: {keywords}.",
      featured_image_prompt_update: "",
      pexels_orientation: "none",
      pexels_size: "none",
      pexels_color: "",
      pixabay_orientation: "all",
      pixabay_image_type: "all",
      pixabay_category: "",
      // Vector Store Settings
      enable_vector_store: "0",
      vector_store_provider: featureDefaults.vector_store_provider || "local",
      local_store_id: "",
      openai_vector_store_ids: [],
      google_file_search_store_names: [],
      pinecone_index_name: "",
      qdrant_collection_name: "",
      chroma_collection_name: "",
      vector_embedding_provider: featureDefaults.vector_embedding_provider ?? "",
      vector_embedding_model: featureDefaults.vector_embedding_model || "",
      vector_store_top_k: "3",
  vector_store_confidence_threshold: "20",
      // RSS keywords
      rss_include_keywords: "",
      rss_exclude_keywords: "",
      rss_item_limit: "0",
    };

    return typeof window.aipkit_normalizeContentWriterSeoConfig === "function"
      ? window.aipkit_normalizeContentWriterSeoConfig(config)
      : config;
  }

  window.aipkit_getDefaultContentWriterTemplateConfig =
    aipkit_getDefaultContentWriterTemplateConfig;
})();
