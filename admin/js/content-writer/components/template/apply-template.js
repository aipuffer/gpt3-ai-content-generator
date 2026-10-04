
/**
 * AIPKit Content Writer - Apply Template
 * Handles applying a selected template's configuration to the form fields.
 */
(function () {
  "use strict";

  const imageMetadataPromptTargets = {
    image_title_prompt: "aipkit_cw_image_title_prompt",
    image_alt_text_prompt: "aipkit_cw_image_alt_text_prompt",
    image_caption_prompt: "aipkit_cw_image_caption_prompt",
    image_description_prompt: "aipkit_cw_image_description_prompt",
  };

  function getPromptDefaultValue(targetId) {
    if (!targetId) return "";
    const select = document.querySelector(
      `.aipkit_cw_prompt_library_select[data-aipkit-prompt-target="${CSS.escape(
        targetId
      )}"]`
    );
    if (!select) return "";
    const option = select.querySelector("option");
    return option ? option.value : "";
  }

  function getNormalizedTemperatureValue(rawValue, fallbackValue) {
    const parsedValue = parseFloat(rawValue);
    if (!Number.isFinite(parsedValue)) {
      return fallbackValue;
    }

    const normalizedValue = Math.max(
      0,
      Math.min(2, Math.round(parsedValue * 10) / 10)
    );

    return String(normalizedValue);
  }

  function normalizeVectorEmbeddingConfig(config) {
    if (!config || typeof config !== "object") {
      return config;
    }

    if (config.vector_embedding_provider === undefined) {
      config.vector_embedding_provider = "openai";
    }

    if (config.vector_embedding_model === undefined && config.vector_embedding_provider === "openai") {
      config.vector_embedding_model =
        window.aipkit_dashboard?.modelCatalog?.defaults?.OpenAIEmbedding || "";
    }

    return config;
  }

  function setBinaryFieldValue(field, rawValue, defaultValue = "0") {
    if (!field) {
      return;
    }

    const normalizedValue =
      rawValue === "1" || rawValue === 1 || rawValue === true
        ? "1"
        : rawValue === "0" || rawValue === 0 || rawValue === false
        ? "0"
        : defaultValue;

    if (field.type === "checkbox") {
      field.checked = normalizedValue === "1";
      return;
    }

    field.value = normalizedValue;
  }

  function hideOutputDisplays() {
    const singleOutputWrapper = document.getElementById(
      "aipkit_cw_single_output_wrapper"
    );
    const titleDisplay = document.getElementById(
      "aipkit_cw_generated_title_display"
    );
    const contentArea = document.getElementById(
      "aipkit_cw_generated_content_area"
    );
    const metaChunk = document.getElementById("aipkit_cw_meta_chunk");
    const metaDisplay = document.getElementById(
      "aipkit_cw_meta_desc_output_wrapper"
    );
    const outputActions = document.querySelector(
      ".aipkit_content_writer_output_actions"
    );

    if (singleOutputWrapper) {
      singleOutputWrapper.style.display = "none";
    }
    if (typeof window.aipkit_setContentWriterSinglePreviewState === "function") {
      window.aipkit_setContentWriterSinglePreviewState(false);
    }

    if (titleDisplay) {
      titleDisplay.style.display = "none";
      titleDisplay.textContent = "";
    }
    if (contentArea) {
      contentArea.innerHTML = "";
    }
    if (typeof window.aipkit_resetContentWriterCanvasState === "function") {
      window.aipkit_resetContentWriterCanvasState();
    }
    if (metaChunk) metaChunk.style.display = "none";
    if (metaDisplay) metaDisplay.style.display = "none";
    if (outputActions) outputActions.style.display = "none";
    const tagsTextarea = document.getElementById("aipkit_cw_generated_tags");
    if (tagsTextarea) {
      tagsTextarea.value = "";
      if (typeof window.aipkit_syncContentWriterTagsEditor === "function") {
        window.aipkit_syncContentWriterTagsEditor(true);
      }
    }
    if (typeof window.aipkit_resetSaveAsPostStatus === "function") {
      window.aipkit_resetSaveAsPostStatus();
    }
  }

  function markTemplateRenderReady() {
    const modeContainer = document.querySelector(
      "#aipkit_content_writer_container .aipkit_cw_mode_container"
    );
    const sourceSelector = document.querySelector(
      "#aipkit_content_writer_container .aipkit_cw_source_selector_wrapper"
    );
    if (modeContainer) {
      modeContainer.dataset.templateReady = "1";
    }
    if (sourceSelector) {
      sourceSelector.dataset.templateReady = "1";
    }
  }

  function aipkit_applyContentWriterTemplate(templateId, silent = false, options = {}) {
    const autosaveWasSuspended = window.aipkit_cw_autosave_suspended === true;
    window.aipkit_cw_autosave_suspended = true;

    try {
    const showError =
      typeof window.aipkit_showError === "function" ? window.aipkit_showError : null;
    window.aipkit_cw_template_state.currentTemplateId = templateId;

    if (templateId) {
        sessionStorage.setItem("aipkit_cw_last_template_id", templateId);
    } else {
        sessionStorage.removeItem("aipkit_cw_last_template_id");
        sessionStorage.removeItem("aipkit_cw_last_template_label");
    }
    const selectedTemplate =
      window.aipkit_cw_template_state.currentTemplates.find(
        (t) => String(t.id) === String(templateId)
      );

    if (selectedTemplate?.template_name) {
      sessionStorage.setItem(
        "aipkit_cw_last_template_label",
        selectedTemplate.template_name
      );
    } else if (!templateId) {
      sessionStorage.removeItem("aipkit_cw_last_template_label");
    }

    let configToApply;
    if (selectedTemplate) {
      configToApply = JSON.parse(JSON.stringify(selectedTemplate.config));
    } else {
      if (
        typeof window.aipkit_getDefaultContentWriterTemplateConfig !==
        "function"
      ) {
        console.error(
        "Apply Template: getDefaultContentWriterTemplateConfig function is missing."
      );
        if (showError && !silent) {
          showError("Couldn’t load the default template.");
        }
        return;
      }
      configToApply = window.aipkit_getDefaultContentWriterTemplateConfig();
    }

    normalizeVectorEmbeddingConfig(configToApply);

    // Switching writing presets preserves the current provider and publishing
    // choices. Reopening the writer also restores the user's saved settings.
    const isWritingPreset = selectedTemplate?.is_starter === true ||
      String(selectedTemplate?.is_starter) === "1" ||
      configToApply?.template_scope === "prompts_only";
    const isPromptsOnlyTemplate = isWritingPreset && !(
      options.restoreSavedSettings === true && selectedTemplate?.has_saved_settings === true
    );
    if (!isPromptsOnlyTemplate) {
      window.aipkit_cw_template_state.initialConfigForSelectedTemplate =
        JSON.parse(JSON.stringify(configToApply));
      window.aipkit_cw_template_state.currentTemplateModified = false;
    }

    hideOutputDisplays();

    const form = document.getElementById("aipkit_content_writer_form");
    if (!form) {
      console.error("Apply Template: Content writer form not found.");
      markTemplateRenderReady();
      return;
    }

    if (!isPromptsOnlyTemplate && configToApply.ai_model && form.elements["ai_model"]) {
      window.aipkit_cw_template_state.pendingModelSelection =
        configToApply.ai_model;
    } else {
      window.aipkit_cw_template_state.pendingModelSelection = null;
    }
    
    if (!isPromptsOnlyTemplate && configToApply.image_model && form.elements["image_model"]) {
      window.aipkit_cw_template_state.pendingImageModelSelection =
        configToApply.image_model;
    } else {
      window.aipkit_cw_template_state.pendingImageModelSelection = null;
    }

    if (isPromptsOnlyTemplate) {
      const promptModeInput = document.getElementById(
        "aipkit_cw_prompt_mode_hidden_input"
      );
      if (promptModeInput) {
        promptModeInput.value = configToApply.prompt_mode || "custom";
      }

      const promptFieldsToUpdate = [
        "custom_title_prompt",
        "custom_content_prompt",
        "custom_meta_prompt",
        "custom_keyword_prompt",
        "custom_excerpt_prompt",
        "custom_tags_prompt",
        "image_prompt",
        "featured_image_prompt",
        "image_title_prompt",
        "image_alt_text_prompt",
        "image_caption_prompt",
        "image_description_prompt",
      ];

      promptFieldsToUpdate.forEach((key) => {
        const hasValue = Object.prototype.hasOwnProperty.call(configToApply, key);
        const input = form.elements[key];
        if (!input) {
          return;
        }
        const valueToSet =
          configToApply[key] === null ||
          typeof configToApply[key] === "undefined"
            ? ""
            : configToApply[key];
        const isImageMetadataPrompt = Boolean(imageMetadataPromptTargets[key]);
        const isEmptyValue =
          typeof valueToSet === "string"
            ? valueToSet.trim() === ""
            : valueToSet === "";
        if ((!hasValue || isEmptyValue) && isImageMetadataPrompt) {
          input.value = getPromptDefaultValue(imageMetadataPromptTargets[key]);
          return;
        }
        if (hasValue) {
          input.value = valueToSet;
        }
      });

      const promptToggleKeys = [
        "generate_title",
        "generate_content",
        "generate_meta_description",
        "generate_focus_keyword",
        "generate_excerpt",
        "generate_tags",
      ];
      promptToggleKeys.forEach((key) => {
        if (!Object.prototype.hasOwnProperty.call(configToApply, key)) {
          return;
        }
        const checkbox = form.elements[key];
        if (!checkbox) {
          return;
        }
        const rawValue = configToApply[key];
        checkbox.checked = rawValue === "1" || rawValue === 1 || rawValue === true;
      });

      const contentLengthSelect = form.elements["content_length"];
      if (
        contentLengthSelect &&
        Object.prototype.hasOwnProperty.call(configToApply, "content_length")
      ) {
        contentLengthSelect.value = configToApply.content_length || "medium";
        contentLengthSelect.dispatchEvent(new Event("change", { bubbles: true }));
      }

      const temperatureInput = form.elements["ai_temperature"];
      if (temperatureInput && temperatureInput.type === "number") {
        const fallbackTemperature = temperatureInput.defaultValue || "1.0";
        const rawTemperature = Object.prototype.hasOwnProperty.call(
          configToApply,
          "ai_temperature"
        )
          ? configToApply.ai_temperature
          : fallbackTemperature;
        temperatureInput.value = getNormalizedTemperatureValue(
          rawTemperature,
          fallbackTemperature
        );
      }

      if (typeof window.aipkit_updateImageSettingsUI === "function") {
        window.aipkit_updateImageSettingsUI();
      }
    } else {
      const fieldsToUpdate = [
        "ai_provider",
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
        "custom_title_prompt",
        "custom_content_prompt",
        "custom_meta_prompt",
        "custom_keyword_prompt",
        "custom_excerpt_prompt",
        "custom_tags_prompt",
        "rss_feeds",
        "gsheets_sheet_id",
        "gsheets_credentials",
        "url_list",
        "image_provider",
        "image_provider_options",
        "image_prompt",
        "image_count",
        "image_placement",
        "image_placement_param_x",
        "image_alignment",
        "image_size",
        "featured_image_prompt",
        "image_title_prompt",
        "image_alt_text_prompt",
        "image_caption_prompt",
        "image_description_prompt",
        "generate_featured_image",
        "pexels_orientation",
        "pexels_size",
        "pexels_color",
        "pixabay_orientation",
        "pixabay_image_type",
        "pixabay_category",
        "vector_store_provider",
        "local_store_id",
        "pinecone_index_name",
        "qdrant_collection_name",
        "chroma_collection_name",
        "vector_embedding_provider",
        "vector_store_top_k",
        "vector_store_confidence_threshold",
        "seo_score_target",
        "seo_score_max_passes",
        "seo_score_profile",
        "seo_score_disabled_rules",
        "rss_include_keywords",
        "rss_exclude_keywords",
        "rss_item_limit",
      ];

    fieldsToUpdate.forEach((key) => {
      const input = form.elements[key];
      if (input) {
        const hasValue = Object.prototype.hasOwnProperty.call(configToApply, key);
        let valueToSet =
          configToApply[key] === null ||
          typeof configToApply[key] === "undefined"
            ? ""
            : configToApply[key];
        if (key === "post_content_format") {
          valueToSet = valueToSet === "gutenberg" ? "gutenberg" : "html";
        } else if (!hasValue && key === "smart_schedule_interval_value") {
          valueToSet = "1";
        } else if (!hasValue && key === "smart_schedule_interval_unit") {
          valueToSet = "hours";
        }
        const isImageMetadataPrompt = Boolean(imageMetadataPromptTargets[key]);
        const isEmptyValue =
          typeof valueToSet === "string"
            ? valueToSet.trim() === ""
            : valueToSet === "";
        if ((!hasValue || isEmptyValue) && isImageMetadataPrompt) {
          input.value = getPromptDefaultValue(imageMetadataPromptTargets[key]);
          return;
        }
        if (key === "schedule_mode" && typeof input.length === "number") {
          const scheduleMode = ["immediate", "smart", "from_input"].includes(
            valueToSet
          )
            ? valueToSet
            : "immediate";
          Array.from(input).forEach((radio) => {
            radio.checked = radio.value === scheduleMode;
          });
        } else if (
          key === "gsheets_credentials" &&
          typeof valueToSet === "object" &&
          valueToSet !== null
        ) {
          input.value = JSON.stringify(valueToSet, null, 2);
        } else if (key === "image_provider_options" && typeof valueToSet === "object") {
          input.value = JSON.stringify(valueToSet);
        } else if (key === "ai_temperature" && input.type === "number") {
          const fallbackTemperature = input.defaultValue || "1.0";
          const rawTemperature = hasValue ? valueToSet : fallbackTemperature;
          input.value = getNormalizedTemperatureValue(
            rawTemperature,
            fallbackTemperature
          );
        } else if (
          (key === "ai_temperature" ||
            key === "vector_store_top_k" ||
            key === "vector_store_confidence_threshold") &&
          input.type === "range"
        ) {
          input.value =
            valueToSet ||
            (key === "ai_temperature"
              ? "1.0"
              : key === "vector_store_top_k"
              ? "3"
              : key === "vector_store_confidence_threshold"
              ? "20"
              : "");
          const valueSpan = document.getElementById(input.id + "_value");
          if (valueSpan)
            valueSpan.textContent =
              key === "vector_store_confidence_threshold"
                ? input.value + "%"
                : input.value;
        } else if (
          (key === "vector_store_top_k" ||
            key === "vector_store_confidence_threshold") &&
          input.type === "number"
        ) {
          input.value =
            hasValue && !isEmptyValue
              ? valueToSet
              : key === "vector_store_top_k"
              ? "3"
              : "20";
        } else if (input.type === "checkbox") {
          input.checked = valueToSet === "1";
        } else {
          input.value = valueToSet;
        }
        if (key === "content_length") {
          input.dispatchEvent(new Event("change", { bubbles: true }));
        }
      }
    });

    const gsheetsTextarea = form.elements["gsheets_credentials"];
    if (gsheetsTextarea) {
      const creds = configToApply.gsheets_credentials;
      const gsheetsContainer = gsheetsTextarea.closest(
        ".aipkit_gsheets_section_container"
      );
      gsheetsTextarea.value =
        typeof creds === "object" && creds !== null
          ? JSON.stringify(creds, null, 2)
          : creds || "";
      if (gsheetsTextarea.value) {
        gsheetsTextarea.dataset.filename = "credentials.json";
        if (
          gsheetsContainer &&
          typeof window.aipkit_verifyGSheetsCredentials === "function"
        ) {
          gsheetsContainer.dataset.gsheetsRestorePending = "true";
          setTimeout(
            () => window.aipkit_verifyGSheetsCredentials(gsheetsContainer),
            100
          );
        }
      } else {
        if (gsheetsContainer) {
          delete gsheetsContainer.dataset.gsheetsRestorePending;
        }
        delete gsheetsTextarea.dataset.filename;
        if (
          gsheetsContainer &&
          typeof window.aipkit_setGsheetsIndicator === "function"
        ) {
          window.aipkit_setGsheetsIndicator(gsheetsContainer, "clear");
        }
      }
    }

    const checkboxes = {
      generate_meta_description: "1",
      generate_focus_keyword: "1",
      generate_excerpt: "0",
      generate_tags: "0",
      generate_toc: "0",
      generate_seo_slug: "0",
      seo_score_improvement_enabled: "0",
      seo_score_continue_until_target: "1",
      generate_images_enabled: "0",
      generate_featured_image: "0",
      generate_image_title: "1",
      generate_image_alt_text: "1",
      generate_image_caption: "1",
      generate_image_description: "1",
      enable_vector_store: "0",
    };

    for (const [key, defaultValue] of Object.entries(checkboxes)) {
      const field = form.elements[key];
      if (field) {
        const rawValue = Object.prototype.hasOwnProperty.call(
          configToApply,
          key
        )
          ? configToApply[key]
          : defaultValue;
        setBinaryFieldValue(field, rawValue, defaultValue);
      }
    }

    if (typeof window.aipkit_applyContentWriterSeoFeatureGate === "function") {
      window.aipkit_applyContentWriterSeoFeatureGate(form);
    }
    if (form.elements["seo_score_disabled_rules"]) {
      const defaultSmartSeoDisabledRules =
        typeof window.aipkit_getDefaultSmartSeoDisabledRules === "function"
          ? window.aipkit_getDefaultSmartSeoDisabledRules()
          : "[]";
      form.elements["seo_score_disabled_rules"].value =
        configToApply.seo_score_disabled_rules || defaultSmartSeoDisabledRules;
      form.elements["seo_score_disabled_rules"].dispatchEvent(
        new Event("change", { bubbles: true })
      );
    }

    const promptModeInput = document.getElementById(
      "aipkit_cw_prompt_mode_hidden_input"
    );
    if (promptModeInput) {
      promptModeInput.value = configToApply.prompt_mode || "custom";
    }

    const modeSelect = form.querySelector("#aipkit_cw_mode_select");
    const rawMode = configToApply.cw_generation_mode || "task";
    const generationModeToSet = rawMode === "single" ? "task" : rawMode;
    if (modeSelect) {
      modeSelect.value = generationModeToSet;
      modeSelect.dispatchEvent(new Event("change", { bubbles: true }));
    }

    if (typeof window.aipkit_refreshContentWriterPublishingUI === "function") {
      window.aipkit_refreshContentWriterPublishingUI();
    }

    const categoriesSelect = form.elements["post_categories[]"];
    if (categoriesSelect) {
      const categoryIds = Array.isArray(configToApply.post_categories)
        ? configToApply.post_categories.map((id) => String(id))
        : [];
      Array.from(categoriesSelect.options).forEach((option) => {
        option.selected = categoryIds.includes(option.value);
      });
      if (typeof window.aipkit_initCategoryDropdowns === "function") {
        window.aipkit_initCategoryDropdowns(form);
      }
    }

    const openaiVsSelect = form.elements["openai_vector_store_ids[]"];
    if (openaiVsSelect) {
      const vsIds = Array.isArray(configToApply.openai_vector_store_ids)
        ? configToApply.openai_vector_store_ids.map(String)
        : [];
      Array.from(openaiVsSelect.options).forEach((option) => {
        option.selected = vsIds.includes(option.value);
      });
      if (
        typeof window.aipkit_initContentWriterVectorStoreMultiSelect ===
        "function"
      ) {
        window.aipkit_initContentWriterVectorStoreMultiSelect();
      }
    }
    const googleStoresSelect = form.elements["google_file_search_store_names[]"];
    if (googleStoresSelect) {
      const googleStoreNames = Array.isArray(configToApply.google_file_search_store_names)
        ? configToApply.google_file_search_store_names.map(String)
        : [];
      Array.from(googleStoresSelect.options).forEach((option) => {
        option.selected = googleStoreNames.includes(option.value);
      });
      if (typeof window.aipkit_initContentWriterVectorStoreMultiSelect === "function") {
        window.aipkit_initContentWriterVectorStoreMultiSelect();
      }
    }

    const vectorEmbeddingModelSelect = form.elements["vector_embedding_model"];
    if (vectorEmbeddingModelSelect) {
      vectorEmbeddingModelSelect.dataset.aipkitRequestedValue =
        configToApply.vector_embedding_model || "";
    }

    if (typeof window.aipkit_updateImageSettingsUI === "function") {
      if (typeof window.aipkit_applyImageProviderOptions === "function") {
        window.aipkit_applyImageProviderOptions(
          form,
          configToApply.image_provider_options || "{}"
        );
      }
      window.aipkit_updateImageSettingsUI();
    }

    const enableVectorCheckbox = form.elements["enable_vector_store"];
    if (
      enableVectorCheckbox &&
      typeof window.aipkit_cw_toggleVectorSettingsContainer === "function"
    ) {
      window.aipkit_cw_toggleVectorSettingsContainer(
        enableVectorCheckbox.closest(".aipkit_cw_vector_section") ||
          document.getElementById("aipkit_content_writer_container")
      );
    }
    const vectorProviderSelect = form.elements["vector_store_provider"];
    if (vectorProviderSelect) {
      vectorProviderSelect.value = configToApply.vector_store_provider || "openai";
      vectorProviderSelect.dispatchEvent(new Event("change", { bubbles: true }));
    }

    const providerInput = form.elements["ai_provider"];
    if (providerInput) {
      const currentProviderValue = providerInput.value;
      const fallbackProvider = (
        window.aipkit_dashboard?.main_provider || "OpenAI"
      ).toLowerCase();
      const newProviderValue = (
        configToApply.ai_provider || fallbackProvider
      ).toLowerCase();

      if (!Array.from(providerInput.options).some((option) => option.value === newProviderValue)) {
        const savedProvider = new Option(newProviderValue, newProviderValue);
        savedProvider.hidden = true;
        providerInput.appendChild(savedProvider);
      }
      if (currentProviderValue !== newProviderValue) {
        providerInput.value = newProviderValue;
        providerInput.dispatchEvent(new Event("change", { bubbles: true }));
      } else {
        const modelSelectField = form.elements["ai_model"];
        if (
          modelSelectField &&
          typeof window.aipkit_populateModelsForContentWriter === "function"
        ) {
          window.aipkit_populateModelsForContentWriter(
            providerInput,
            modelSelectField
          );
        } else if (
          typeof window.aipkit_checkFormForModifications === "function"
        ) {
          window.aipkit_checkFormForModifications();
        }
      }

      if (providerInput.getAttribute("data-aipkit-provider-notice-defer") === "1") {
        if (templateId) {
          providerInput.dataset.aipkitProviderNoticeArmed = "true";
        } else {
          delete providerInput.dataset.aipkitProviderNoticeArmed;
        }
        if (typeof window.aipkit_initProviderKeyNotices === "function") {
          window.aipkit_initProviderKeyNotices(form);
        }
      }
    }

    }

    if (typeof window.aipkit_applyContentWriterPromptSets === "function") {
      window.aipkit_applyContentWriterPromptSets(configToApply);
    }

    if (typeof window.aipkit_syncContentWriterInlinePrompts === "function") {
      window.aipkit_syncContentWriterInlinePrompts();
    }

    if (isPromptsOnlyTemplate) {
      if (typeof window.aipkit_getContentWriterFormConfig === "function") {
        window.aipkit_cw_template_state.initialConfigForSelectedTemplate =
          window.aipkit_getContentWriterFormConfig(form);
      }
      window.aipkit_cw_template_state.currentTemplateModified = false;
    }

    if (typeof window.aipkit_updateTemplatePickerSelection === "function") {
      window.aipkit_updateTemplatePickerSelection(templateId);
    }

    if (typeof window.aipkit_cw_toggleReasoningEffort === "function") {
      const mainContainer = document.getElementById("aipkit_content_writer_container");
      if (mainContainer) {
        window.aipkit_cw_toggleReasoningEffort(mainContainer);
      }
    }

    if (typeof window.aipkit_refreshContentWriterSelectPickers === "function") {
      window.aipkit_refreshContentWriterSelectPickers();
    }

    // Refresh the URL counter after template values are applied
    if (typeof window.aipkit_refreshUrlCounter === "function") {
      window.aipkit_refreshUrlCounter();
    }

    // Refresh the RSS URL counter after template values are applied
    if (typeof window.aipkit_refreshRssCounter === "function") {
      window.aipkit_refreshRssCounter({ resetQuantity: !isPromptsOnlyTemplate });
    }

    markTemplateRenderReady();

    } finally {
      markTemplateRenderReady();
      if (!autosaveWasSuspended) {
        window.aipkit_cw_autosave_suspended = false;
      }
      if (
        typeof window.aipkit_updateContentWriterAutosaveBaseline === "function"
      ) {
        window.aipkit_updateContentWriterAutosaveBaseline();
      }
    }
  }

  window.aipkit_applyContentWriterTemplate = aipkit_applyContentWriterTemplate;
})();
