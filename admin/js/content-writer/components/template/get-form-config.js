/**
 * AIPKit Content Writer - Get Form Configuration
 * Collects current values from the content writer form to build a config object.
 */
(function () {
  "use strict";

  function getBinaryFieldValue(field, defaultValue = "0") {
    if (!field) {
      return defaultValue;
    }

    if (field.type === "checkbox") {
      return field.checked ? "1" : "0";
    }

    return String(field.value) === "1" ? "1" : "0";
  }

  function aipkit_getContentWriterFormConfig(form) {
    const config = {};
    if (!form) return config;

    if (typeof window.aipkit_syncImageProviderOptions === "function") {
      window.aipkit_syncImageProviderOptions(form);
    }

    const fieldsToSave = [
      "ai_provider",
      "ai_model",
      "content_title",
      "content_keywords",
      "ai_temperature",
      "content_length",
      "reasoning_effort",
      "post_type",
      "post_author",
      "post_status",
      "post_content_format",
      "post_schedule_date",
      "post_schedule_time",
      "schedule_mode",
      "smart_schedule_start_datetime",
      "smart_schedule_interval_value",
      "smart_schedule_interval_unit",
      "post_categories",
      "prompt_mode",
      "custom_title_prompt",
      "custom_content_prompt",
      "custom_meta_prompt",
      "custom_keyword_prompt",
      "custom_excerpt_prompt",
      "custom_tags_prompt",
      "custom_title_prompt_update",
      "custom_content_prompt_update",
      "custom_meta_prompt_update",
      "custom_keyword_prompt_update",
      "custom_excerpt_prompt_update",
      "custom_tags_prompt_update",
      "rss_feeds",
      "gsheets_sheet_id",
      "gsheets_credentials",
      "url_list",
      "seo_score_target",
      "seo_score_max_passes",
      "seo_score_profile",
      "seo_score_disabled_rules",
      // --- ADDED: All new image setting field names ---
      "image_provider",
      "image_model",
      "image_provider_options",
      "image_prompt",
      "image_prompt_update",
      "image_count",
      "image_placement",
      "image_placement_param_x",
      "image_alignment",
      "image_size",
      "generate_image_title",
      "generate_image_alt_text",
      "generate_image_caption",
      "generate_image_description",
      "image_title_prompt",
      "image_alt_text_prompt",
      "image_caption_prompt",
      "image_description_prompt",
      "image_title_prompt_update",
      "image_alt_text_prompt_update",
      "image_caption_prompt_update",
      "image_description_prompt_update",
      "featured_image_prompt",
      "featured_image_prompt_update",
      "generate_featured_image",
      "pexels_orientation",
      "pexels_size",
      "pexels_color",
      "pixabay_orientation",
      "pixabay_image_type",
      "pixabay_category",
      // --- END ADDED ---
      // --- ADDED: All new vector setting field names ---
      "vector_store_provider",
      "local_store_id",
      "pinecone_index_name",
      "qdrant_collection_name",
      "chroma_collection_name",
      "vector_embedding_provider",
      "vector_embedding_model",
      "vector_store_top_k",
  "vector_store_confidence_threshold",
      // --- ADDED: New RSS keyword fields ---
      "rss_include_keywords",
      "rss_exclude_keywords",
      "rss_item_limit",
    ];

    fieldsToSave.forEach((key) => {
      if (form.elements[key]) {
        config[key] = form.elements[key].value;
      }
    });

    const generateMetaCheckbox = form.elements["generate_meta_description"];
    if (generateMetaCheckbox) {
      config["generate_meta_description"] = generateMetaCheckbox.checked
        ? "1"
        : "0";
    } else {
      config["generate_meta_description"] = "0";
    }

    const generateKeywordCheckbox = form.elements["generate_focus_keyword"];
    if (generateKeywordCheckbox) {
      config["generate_focus_keyword"] = generateKeywordCheckbox.checked
        ? "1"
        : "0";
    } else {
      config["generate_focus_keyword"] = "0";
    }

    const generateExcerptCheckbox = form.elements["generate_excerpt"];
    if (generateExcerptCheckbox) {
      config["generate_excerpt"] = generateExcerptCheckbox.checked ? "1" : "0";
    } else {
      config["generate_excerpt"] = "0";
    }

    const generateTagsCheckbox = form.elements["generate_tags"];
    if (generateTagsCheckbox) {
      config["generate_tags"] = generateTagsCheckbox.checked ? "1" : "0";
    } else {
      config["generate_tags"] = "0";
    }

    const generateTitleCheckbox = form.elements["generate_title"];
    if (generateTitleCheckbox) {
      config["generate_title"] = generateTitleCheckbox.checked ? "1" : "0";
    } else {
      config["generate_title"] = "1";
    }

    const generateContentCheckbox = form.elements["generate_content"];
    if (generateContentCheckbox) {
      config["generate_content"] = generateContentCheckbox.checked ? "1" : "0";
    } else {
      config["generate_content"] = "1";
    }

    config["generate_toc"] = getBinaryFieldValue(
      form.elements["generate_toc"],
      "0"
    );

    config["generate_seo_slug"] = getBinaryFieldValue(
      form.elements["generate_seo_slug"],
      "0"
    );
    config["seo_score_improvement_enabled"] = getBinaryFieldValue(
      form.elements["seo_score_improvement_enabled"],
      "0"
    );
    config["seo_score_continue_until_target"] = getBinaryFieldValue(
      form.elements["seo_score_continue_until_target"],
      "1"
    );
    if (!config["seo_score_target"]) {
      config["seo_score_target"] = "100";
    }
    if (!config["seo_score_max_passes"]) {
      config["seo_score_max_passes"] = "3";
    }
    if (!config["seo_score_profile"]) {
      config["seo_score_profile"] = "auto";
    }
    if (!config["seo_score_disabled_rules"]) {
      config["seo_score_disabled_rules"] =
        typeof window.aipkit_getDefaultSmartSeoDisabledRules === "function"
          ? window.aipkit_getDefaultSmartSeoDisabledRules()
          : "[]";
    }

    // --- ADDED: Logic for image checkboxes ---
    const generateImagesCheckbox = form.elements["generate_images_enabled"];
    if (generateImagesCheckbox) {
      config["generate_images_enabled"] = generateImagesCheckbox.checked
        ? "1"
        : "0";
    } else {
      config["generate_images_enabled"] = "0";
    }

    const generateFeaturedCheckbox = form.elements["generate_featured_image"];
    if (generateFeaturedCheckbox) {
      config["generate_featured_image"] = generateFeaturedCheckbox.checked
        ? "1"
        : "0";
    } else {
      config["generate_featured_image"] = "0";
    }

    ["generate_image_title", "generate_image_alt_text", "generate_image_caption", "generate_image_description"].forEach(
      (field) => {
        const checkbox = form.elements[field];
        if (checkbox) {
          config[field] = checkbox.checked ? "1" : "0";
        } else {
          config[field] = "1";
        }
      }
    );

    // --- ADDED: Logic for vector checkbox ---
    const enableVectorCheckbox = form.elements["enable_vector_store"];
    if (enableVectorCheckbox) {
      config["enable_vector_store"] = enableVectorCheckbox.checked ? "1" : "0";
    } else {
      config["enable_vector_store"] = "0";
    }

    // --- ADDED: Logic for OpenAI vector stores multi-select ---
    const openaiVsSelect = form.elements["openai_vector_store_ids[]"];
    if (openaiVsSelect) {
      config["openai_vector_store_ids"] = Array.from(
        openaiVsSelect.selectedOptions
      ).map((opt) => opt.value);
    } else {
      config["openai_vector_store_ids"] = [];
    }
    const googleStoresSelect = form.elements["google_file_search_store_names[]"];
    config["google_file_search_store_names"] = googleStoresSelect
      ? Array.from(googleStoresSelect.selectedOptions).map((option) => option.value)
      : [];

    const promptModeInput = form.querySelector(
      "#aipkit_cw_prompt_mode_hidden_input"
    );
    if (promptModeInput) {
      config.prompt_mode = promptModeInput.value;
    } else {
      config.prompt_mode = "custom";
    }

    const modeSelect = form.querySelector("#aipkit_cw_mode_select");
    const selectedMode = modeSelect ? modeSelect.value : "task";
    config.cw_generation_mode = selectedMode === "single" ? "task" : selectedMode;

    const categoriesSelect = form.elements["post_categories[]"];
    if (categoriesSelect) {
      config.post_categories = Array.from(categoriesSelect.selectedOptions).map(
        (opt) => opt.value
      );
    } else {
      config.post_categories = [];
    }

    const promptKeys = [
      "custom_title_prompt",
      "custom_content_prompt",
      "custom_meta_prompt",
      "custom_keyword_prompt",
      "custom_excerpt_prompt",
      "custom_tags_prompt",
      "image_prompt",
      "featured_image_prompt",
    ];
    const promptSets =
      typeof window.aipkit_getContentWriterPromptSets === "function"
        ? window.aipkit_getContentWriterPromptSets()
        : null;
    if (promptSets && promptSets.create) {
      promptKeys.forEach((key) => {
        if (Object.prototype.hasOwnProperty.call(promptSets.create, key)) {
          config[key] = promptSets.create[key];
        }
      });
    }
    if (promptSets && promptSets.update) {
      promptKeys.forEach((key) => {
        const updateKey = `${key}_update`;
        if (Object.prototype.hasOwnProperty.call(promptSets.update, key)) {
          config[updateKey] = promptSets.update[key];
        }
      });
    }

    return typeof window.aipkit_normalizeContentWriterSeoConfig === "function"
      ? window.aipkit_normalizeContentWriterSeoConfig(config)
      : config;
  }

  window.aipkit_getContentWriterFormConfig = aipkit_getContentWriterFormConfig;
})();
