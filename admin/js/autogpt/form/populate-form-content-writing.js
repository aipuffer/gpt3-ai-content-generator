/**
 * AIPKit Automated Tasks - Form Content Writing Population
 * Populates fields specific to the 'content_writing' task type.
 */
(function () {
  "use strict";
  const escaper =
    window.aipkit_escapeHtml ||
    function (str) {
      return str;
    };

  function parseImageProviderOptions(rawOptions) {
    if (!rawOptions) {
      return {};
    }
    if (typeof rawOptions === "object") {
      return rawOptions;
    }
    try {
      const parsed = JSON.parse(String(rawOptions));
      return parsed && typeof parsed === "object" ? parsed : {};
    } catch (error) {
      return {};
    }
  }

  function applyTaskImageProviderOptions(form, rawOptions) {
    if (!form) {
      return;
    }

    if (typeof window.aipkit_applyImageProviderOptions === "function") {
      window.aipkit_applyImageProviderOptions(form, rawOptions);
      return;
    }

    const providerOptions = parseImageProviderOptions(rawOptions);
    form
      .querySelectorAll("[data-aipkit-image-provider-options]")
      .forEach((block) => {
        const providerKey = String(
          block.dataset.aipkitImageProviderOptions || ""
        )
          .trim()
          .toLowerCase();
        const optionsForProvider =
          providerOptions &&
          providerOptions[providerKey] &&
          typeof providerOptions[providerKey] === "object"
            ? providerOptions[providerKey]
            : null;
        if (!optionsForProvider) {
          return;
        }

        block
          .querySelectorAll("[data-aipkit-image-provider-option]")
          .forEach((control) => {
            const optionKey = String(
              control.dataset.aipkitImageProviderOption || ""
            ).trim();
            if (
              !optionKey ||
              !Object.prototype.hasOwnProperty.call(
                optionsForProvider,
                optionKey
              )
            ) {
              return;
            }
            const value = optionsForProvider[optionKey];
            if (control.type === "checkbox") {
              control.checked =
                value === true || value === "1" || value === "true";
            } else {
              control.value =
                value === null || typeof value === "undefined"
                  ? ""
                  : String(value);
            }
          });
      });
  }

  /**
   * Populates the form fields for a 'content_writing' task.
   * @param {Object} taskConfig - The configuration object for the task.
   */
  function aipkit_form_populateContentWritingFields(taskConfig) {
    const form = document.getElementById("aipkit_automated_task_form");
    if (!form) return;

    // --- Populate fields based on generation mode ---
    const mode = taskConfig.cw_generation_mode || "bulk";

    if (mode === "bulk" || mode === "single" || mode === "task") {
      const bulkInput = form.elements["content_title_bulk"];
      if (bulkInput) {
        bulkInput.value = taskConfig.content_title || "";
        if (typeof window.aipkit_refreshAutogptManualEntryUi === "function") {
          window.aipkit_refreshAutogptManualEntryUi();
        } else {
          bulkInput.dispatchEvent(new Event("input", { bubbles: true }));
        }
      }
    } else if (mode === "csv") {
      const csvInput = form.elements["content_title"];
      if (csvInput) {
        csvInput.value = taskConfig.content_title || "";
        if (
          csvInput.value &&
          typeof window.aipkit_restoreCsvUIFromParsedData === "function"
        ) {
          const csvContainer = csvInput.closest(".aipkit_csv_import_container");
          window.aipkit_restoreCsvUIFromParsedData(
            csvContainer,
            csvInput.value,
            {
              isAutogpt: true,
              fileName: "Saved CSV data",
            }
          );
        }
      }
    } else if (mode === "rss") {
      if (form.elements["rss_item_limit"]) {
        form.elements["rss_item_limit"].value = taskConfig.rss_item_limit ?? "";
      }
      if (form.elements["rss_feeds"])
        form.elements["rss_feeds"].value = taskConfig.rss_feeds || "";
      if (form.elements["rss_include_keywords"])
        form.elements["rss_include_keywords"].value =
          taskConfig.rss_include_keywords || "";
      if (form.elements["rss_exclude_keywords"])
        form.elements["rss_exclude_keywords"].value =
          taskConfig.rss_exclude_keywords || "";
      const rssTextarea = form.elements["rss_feeds"];
      if (rssTextarea) {
        if (typeof window.aipkit_initTaskRssInputHandler === "function") {
          window.aipkit_initTaskRssInputHandler();
        }
        rssTextarea.dispatchEvent(new Event("input", { bubbles: true }));
        window.aipkit_refreshRssCounter?.({ resetQuantity: true });
      }
    } else if (mode === "url") {
      if (form.elements["url_list"])
        form.elements["url_list"].value = taskConfig.url_list || "";
    } else if (mode === "gsheets") {
      const gsheetsSheetId = taskConfig.gsheets_sheet_id || "";
      if (form.elements["gsheets_sheet_id"]) {
        form.elements["gsheets_sheet_id"].value = gsheetsSheetId;
      }

      const gsheetsTextarea = form.elements["gsheets_credentials"];
      if (gsheetsTextarea) {
        const creds = taskConfig.gsheets_credentials;
        const gsheetsContainer = gsheetsTextarea.closest(
          ".aipkit_gsheets_section_container"
        );
        if (typeof creds === "object" && creds !== null) {
          gsheetsTextarea.value = JSON.stringify(creds, null, 2); // Pretty print for readability
        } else {
          gsheetsTextarea.value = creds || "";
        }
        if (gsheetsTextarea.value) {
          gsheetsTextarea.dataset.filename = "credentials.json";
          if (
            gsheetsContainer &&
            typeof window.aipkit_setGsheetsIndicator === "function"
          ) {
            delete gsheetsContainer.dataset.gsheetsManualUnlocked;
            gsheetsContainer.dataset.gsheetsRestorePending = "true";
            window.aipkit_setGsheetsIndicator(
              gsheetsContainer,
              "verifying",
              "",
              gsheetsSheetId,
              "credentials.json"
            );
          }
          if (
            gsheetsContainer &&
            typeof window.aipkit_verifyGSheetsCredentials === "function"
          ) {
            setTimeout(
              () => window.aipkit_verifyGSheetsCredentials(gsheetsContainer),
              100
            );
          }
        } else {
          delete gsheetsTextarea.dataset.filename;
          if (
            gsheetsContainer &&
            typeof window.aipkit_setGsheetsIndicator === "function"
          ) {
            delete gsheetsContainer.dataset.gsheetsRestorePending;
            delete gsheetsContainer.dataset.gsheetsManualUnlocked;
            window.aipkit_setGsheetsIndicator(gsheetsContainer, "clear");
          }
        }
      }
    }

    // --- Prompts ---
    if (form.elements["custom_title_prompt"])
      form.elements["custom_title_prompt"].value =
        taskConfig.custom_title_prompt || "";
    if (form.elements["custom_content_prompt"])
      form.elements["custom_content_prompt"].value =
        taskConfig.custom_content_prompt || "";

    // --- AI Settings ---
    if (
      typeof window.aipkit_applyAutogptSavedAiState === "function" &&
      typeof window.aipkit_task_cw_populateModels === "function"
    ) {
      window.aipkit_applyAutogptSavedAiState({
        form,
        taskConfig,
        providerSelector: "#aipkit_task_cw_ai_provider",
        modelSelector: "#aipkit_task_cw_ai_model",
        combinedSelector: "#aipkit_task_cw_ai_selection",
        temperatureSelector: "#aipkit_task_cw_ai_temperature",
        temperatureOutputSelector: "#aipkit_task_cw_ai_temperature_value",
        defaultTemperature: "1.0",
        reasoningSelector: "#aipkit_task_cw_reasoning_effort",
        populateModels: window.aipkit_task_cw_populateModels,
        reasoningToggle:
          typeof window.aipkit_autogpt_cw_toggleReasoningEffort === "function"
            ? window.aipkit_autogpt_cw_toggleReasoningEffort
            : null,
      });
    }

    if (typeof window.aipkit_setAutogptFieldValue === "function") {
      window.aipkit_setAutogptFieldValue(
        "#aipkit_task_cw_content_length",
        taskConfig.content_length || "medium",
        {
          form,
          dispatchChange: true,
        }
      );
    }


    // --- Populate SEO Settings ---
    if (form.elements["generate_meta_description"]) {
      form.elements["generate_meta_description"].checked =
        taskConfig.generate_meta_description === "1";
    }
    if (form.elements["custom_meta_prompt"]) {
      form.elements["custom_meta_prompt"].value =
        taskConfig.custom_meta_prompt || "";
    }
    if (form.elements["generate_focus_keyword"]) {
      form.elements["generate_focus_keyword"].checked =
        taskConfig.generate_focus_keyword === "1";
    }
    if (form.elements["custom_keyword_prompt"]) {
      form.elements["custom_keyword_prompt"].value =
        taskConfig.custom_keyword_prompt || "";
    }
    if (form.elements["generate_excerpt"]) {
      form.elements["generate_excerpt"].checked =
        taskConfig.generate_excerpt === "1";
    }
    if (form.elements["custom_excerpt_prompt"]) {
      form.elements["custom_excerpt_prompt"].value =
        taskConfig.custom_excerpt_prompt || "";
    }
    if (form.elements["generate_tags"]) {
      form.elements["generate_tags"].checked = taskConfig.generate_tags === "1";
    }
    if (form.elements["custom_tags_prompt"]) {
      form.elements["custom_tags_prompt"].value =
        taskConfig.custom_tags_prompt || "";
    }
    if (form.elements["generate_toc"]) {
      form.elements["generate_toc"].value =
        taskConfig.generate_toc === "1" ? "1" : "0";
    }
    if (form.elements["generate_seo_slug"]) {
      form.elements["generate_seo_slug"].value =
        taskConfig.generate_seo_slug === "1" ? "1" : "0";
    }
    const smartSeoToggle = form.querySelector(
      "[data-aipkit-task-smart-seo-main-toggle]"
    );
    if (smartSeoToggle) {
      const canUseSmartSeo = Boolean(
        window.aipkit_dashboard && window.aipkit_dashboard.isProPlan
      );
      const smartSeoEnabled =
        canUseSmartSeo && taskConfig.seo_score_improvement_enabled === "1";
      if (smartSeoToggle.type === "checkbox") {
        smartSeoToggle.checked = smartSeoEnabled;
      } else {
        smartSeoToggle.value = smartSeoEnabled ? "1" : "0";
      }
      smartSeoToggle.dispatchEvent(new Event("change", { bubbles: true }));
    }
    if (form.elements["seo_score_continue_until_target"]) {
      form.elements["seo_score_continue_until_target"].value = "1";
    }
    if (form.elements["seo_score_target"]) {
      form.elements["seo_score_target"].value = "100";
    }
    if (form.elements["seo_score_max_passes"]) {
      form.elements["seo_score_max_passes"].value = "3";
    }
    if (form.elements["seo_score_profile"]) {
      form.elements["seo_score_profile"].value = "auto";
    }
    if (form.elements["seo_score_disabled_rules"]) {
      const defaultSmartSeoDisabledRules =
        typeof window.aipkit_getDefaultSmartSeoDisabledRules === "function"
          ? window.aipkit_getDefaultSmartSeoDisabledRules()
          : "[]";
      form.elements["seo_score_disabled_rules"].value =
        taskConfig.seo_score_disabled_rules || defaultSmartSeoDisabledRules;
      form.elements["seo_score_disabled_rules"].dispatchEvent(
        new Event("change", { bubbles: true })
      );
    }
    // --- End Populate SEO Settings ---

    // --- Post Settings ---
    if (form.elements.post_content_format) {
      form.elements.post_content_format.value =
        taskConfig.post_content_format === "gutenberg" ? "gutenberg" : "html";
    }
    if (form.elements["post_type"])
      form.elements["post_type"].value = taskConfig.post_type || "post";
    const authorSelect = form.elements["post_author"];
    if (authorSelect)
      authorSelect.value =
        taskConfig.post_author || window.aipkit_dashboard?.currentUserId || "1";

    const cwPostStatusSelect = document.getElementById(
      "aipkit_task_cw_post_status"
    );
    if (cwPostStatusSelect) {
      cwPostStatusSelect.value = taskConfig.post_status || "draft";
    }

    const scheduleMode = taskConfig.schedule_mode || "immediate";
    const scheduleModeRadio = form.querySelector(
      `input[name="schedule_mode"][value="${scheduleMode}"]`
    );
    if (scheduleModeRadio) {
      scheduleModeRadio.checked = true;
    }

    if (form.elements["smart_schedule_start_datetime"]) {
      form.elements["smart_schedule_start_datetime"].value =
        taskConfig.smart_schedule_start_datetime || "";
    }
    if (form.elements["smart_schedule_interval_value"]) {
      form.elements["smart_schedule_interval_value"].value =
        taskConfig.smart_schedule_interval_value || "1";
    }
    if (form.elements["smart_schedule_interval_unit"]) {
      form.elements["smart_schedule_interval_unit"].value =
        taskConfig.smart_schedule_interval_unit || "hours";
    }

    // Trigger the UI update for scheduling fields
    if (typeof window.aipkit_toggleTaskCwScheduleFields === "function") {
      window.aipkit_toggleTaskCwScheduleFields({ target: cwPostStatusSelect });
    }

    const cwCategoriesSelect = document.getElementById(
      "aipkit_task_cw_post_categories"
    );
    if (cwCategoriesSelect && cwCategoriesSelect.options) {
      Array.from(cwCategoriesSelect.options).forEach(
        (opt) => (opt.selected = false)
      );
      const catIds = Array.isArray(taskConfig.post_categories)
        ? taskConfig.post_categories.map(String)
        : [];
      catIds.forEach((catId) => {
        const option = cwCategoriesSelect.querySelector(
          `option[value="${escaper(catId)}"]`
        );
        if (option) option.selected = true;
      });
      cwCategoriesSelect.dispatchEvent(new Event("change", { bubbles: true }));
    }

    // --- Image Settings ---
    if (form.elements["generate_images_enabled"]) {
      form.elements["generate_images_enabled"].checked =
        taskConfig.generate_images_enabled === "1";
    }
    if (form.elements["image_provider"]) {
      form.elements["image_provider"].value = (
        taskConfig.image_provider || "openai"
      ).toLowerCase();
    }
    if (
      form.elements["image_model"] &&
      window.aipkit_automated_tasks_form_state
    ) {
      window.aipkit_automated_tasks_form_state.pendingImageModelSelection =
        taskConfig.image_model;
    }
    if (form.elements["image_provider_options"]) {
      form.elements["image_provider_options"].value =
        typeof taskConfig.image_provider_options === "object" &&
        taskConfig.image_provider_options !== null
          ? JSON.stringify(taskConfig.image_provider_options)
          : taskConfig.image_provider_options || "{}";
      applyTaskImageProviderOptions(
        form,
        form.elements["image_provider_options"].value
      );
    }
    const stockImageDefaults = {
      pexels_orientation: "none",
      pexels_size: "none",
      pexels_color: "",
      pixabay_orientation: "all",
      pixabay_image_type: "all",
      pixabay_category: "",
    };
    Object.entries(stockImageDefaults).forEach(([fieldName, defaultValue]) => {
      if (!form.elements[fieldName]) return;
      form.elements[fieldName].value =
        taskConfig[fieldName] ?? defaultValue;
    });
    if (form.elements["image_prompt"]) {
      form.elements["image_prompt"].value = taskConfig.image_prompt || "";
    }
    if (form.elements["image_count"]) {
      form.elements["image_count"].value = taskConfig.image_count || "1";
    }
    if (form.elements["image_placement"]) {
      form.elements["image_placement"].value =
        taskConfig.image_placement || "after_first_h2";
    }
    if (form.elements["image_placement_param_x"]) {
      form.elements["image_placement_param_x"].value =
        taskConfig.image_placement_param_x || "2";
    }
    if (form.elements["image_size"]) {
      form.elements["image_size"].value = taskConfig.image_size || "large";
    }
    if (form.elements["image_alignment"]) {
      form.elements["image_alignment"].value =
        taskConfig.image_alignment || "none";
    }
    if (form.elements["generate_featured_image"]) {
      form.elements["generate_featured_image"].checked =
        taskConfig.generate_featured_image === "1";
    }
    if (form.elements["featured_image_prompt"]) {
      form.elements["featured_image_prompt"].value =
        taskConfig.featured_image_prompt || "";
    }
    // Manually trigger the UI update to show/hide correct sections
    if (typeof window.aipkit_updateTaskImageSettingsUI === "function") {
      window.aipkit_updateTaskImageSettingsUI();
    }

    // --- Populate Knowledge Base Settings ---
    const enableVectorCheckbox = form.querySelector(
      'input[name="enable_vector_store"]'
    );
    if (enableVectorCheckbox) {
      enableVectorCheckbox.checked = taskConfig.enable_vector_store === "1";
      enableVectorCheckbox.dispatchEvent(new Event("change", { bubbles: true }));
    }

    const vectorProviderSelect = form.querySelector(
      "#aipkit_task_cw_vector_store_provider"
    );
    if (vectorProviderSelect) {
      vectorProviderSelect.value = taskConfig.vector_store_provider || "openai";
      vectorProviderSelect.dispatchEvent(new Event("change", { bubbles: true }));
    }

    const topKInput = form.querySelector("#aipkit_task_cw_vector_store_top_k");
    if (topKInput) {
      topKInput.value = taskConfig.vector_store_top_k || "3";
    }

    const confidenceThresholdInput = form.querySelector(
      "#aipkit_task_cw_vector_store_confidence_threshold"
    );
    if (confidenceThresholdInput) {
      confidenceThresholdInput.value =
        taskConfig.vector_store_confidence_threshold || "20";
      confidenceThresholdInput._aipkitConfidenceSync?.();
      confidenceThresholdInput.dispatchEvent(
        new Event("input", { bubbles: true })
      );
    }

    // Populate provider-specific selects
    const openaiVsSelect = document.getElementById(
      "aipkit_task_cw_openai_vector_store_ids"
    );
    if (openaiVsSelect && openaiVsSelect.options) {
      const vsIds = Array.isArray(taskConfig.openai_vector_store_ids)
        ? taskConfig.openai_vector_store_ids.map(String)
        : [];
      Array.from(openaiVsSelect.options).forEach((opt) => {
        opt.selected = vsIds.includes(opt.value);
      });
      openaiVsSelect.dispatchEvent(new Event("change", { bubbles: true }));
    }
    const googleStoresSelect = document.getElementById(
      "aipkit_task_cw_google_file_search_store_names"
    );
    if (googleStoresSelect && googleStoresSelect.options) {
      const storeNames = Array.isArray(taskConfig.google_file_search_store_names)
        ? taskConfig.google_file_search_store_names.map(String)
        : [];
      Array.from(googleStoresSelect.options).forEach((option) => {
        option.selected = storeNames.includes(option.value);
      });
      googleStoresSelect.dispatchEvent(new Event("change", { bubbles: true }));
    }

    const pineconeSelect = document.getElementById(
      "aipkit_task_cw_pinecone_index_name"
    );
    if (pineconeSelect) {
      pineconeSelect.value = taskConfig.pinecone_index_name || "";
    }

    const qdrantSelect = document.getElementById(
      "aipkit_task_cw_qdrant_collection_name"
    );
    if (qdrantSelect) {
      qdrantSelect.value = taskConfig.qdrant_collection_name || "";
    }

    const chromaSelect = document.getElementById(
      "aipkit_task_cw_chroma_collection_name"
    );
    if (chromaSelect) {
      chromaSelect.value = taskConfig.chroma_collection_name || "";
    }

    const localStoreSelect = document.getElementById(
      "aipkit_task_cw_local_store_id"
    );
    if (localStoreSelect) {
      localStoreSelect.value = taskConfig.local_store_id || "";
    }

    // Populate embedding config
    if (
      taskConfig.vector_store_provider === "local" ||
      taskConfig.vector_store_provider === "pinecone" ||
      taskConfig.vector_store_provider === "qdrant" ||
      taskConfig.vector_store_provider === "chroma"
    ) {
      if (
        taskConfig.vector_embedding_model &&
        window.aipkit_automated_tasks_form_state
      ) {
        window.aipkit_automated_tasks_form_state.pendingEmbeddingModelSelection =
          taskConfig.vector_embedding_model;
      }

      const embeddingProviderSelect = document.getElementById(
        "aipkit_task_cw_vector_embedding_provider"
      );
      if (embeddingProviderSelect) {
        embeddingProviderSelect.value =
          taskConfig.vector_embedding_provider || "openai";
        embeddingProviderSelect.dispatchEvent(
          new Event("change", { bubbles: true })
        );
      }
    }

    // Manually trigger the UI update to show/hide correct sections
    if (typeof window.aipkit_updateCwKnowledgeBaseUI === "function") {
      window.aipkit_updateCwKnowledgeBaseUI();
    }
    // --- END Knowledge Base Settings ---

    // --- Scheduling ---
    if (form.elements["task_frequency"]) {
      form.elements["task_frequency"].value =
        taskConfig.task_frequency || "daily";
    }
    if (typeof window.aipkit_syncAutogptInlinePrompts === "function") {
      window.aipkit_syncAutogptInlinePrompts();
    }
  }

  window.aipkit_form_populateContentWritingFields =
    aipkit_form_populateContentWritingFields;
})();
