/**
 * AIPKit AutoGPT - Content Writing Task: Form UI Handler
 * Orchestrates initialization of UI elements specific to the "Content Writing" task type form.
 */
(function () {
  "use strict";

  const translate =
    window.wp?.i18n?.__ ||
    function (text) {
      return text;
    };

  /**
   * Initializes UI elements specific to the Content Writing task type form.
   * Called when "Content Writing" is selected in the Task Type dropdown.
   */
  function aipkit_initContentWritingTaskFormUI() {

    const form = document.getElementById("aipkit_automated_task_form");
    if (!form) {
      console.warn(
        "Content Writing UI Init: Could not find main form element."
      );
      return;
    }

    if (typeof window.aipkit_initAutogptManualEntryUi === "function") {
      window.aipkit_initAutogptManualEntryUi();
    }

    // 1. AI Provider and Model Select
    const providerSelect = form.querySelector("#aipkit_task_cw_ai_provider");
    const modelSelect = form.querySelector("#aipkit_task_cw_ai_model");
    if (
      providerSelect &&
      modelSelect &&
      typeof window.aipkit_bindAutogptAiSetup === "function" &&
      typeof window.aipkit_task_cw_populateModels === "function"
    ) {
      window.aipkit_bindAutogptAiSetup({
        form,
        scope: "cw",
        providerSelector: "#aipkit_task_cw_ai_provider",
        modelSelector: "#aipkit_task_cw_ai_model",
        combinedSelector: "#aipkit_task_cw_ai_selection",
        populateModels: window.aipkit_task_cw_populateModels,
        reasoningToggle:
          typeof window.aipkit_autogpt_cw_toggleReasoningEffort === "function"
            ? window.aipkit_autogpt_cw_toggleReasoningEffort
            : null,
      });
    }

    // 2. Temperature Slider
    const tempSlider = form.querySelector("#aipkit_task_cw_ai_temperature");
    if (
      tempSlider &&
      typeof window.aipkit_task_cw_handleTemperatureSlider === "function"
    ) {
      window.aipkit_task_cw_handleTemperatureSlider(tempSlider);
    }

    // 3. Post Status and Schedule Fields Toggle
    const postStatusSelect = form.querySelector("#aipkit_task_cw_post_status");
    if (
      postStatusSelect &&
      typeof window.aipkit_toggleTaskCwScheduleFields === "function"
    ) {
      if (!postStatusSelect.dataset.cwStatusListenerAttached) {
        postStatusSelect.addEventListener(
          "change",
          window.aipkit_toggleTaskCwScheduleFields
        );
        postStatusSelect.dataset.cwStatusListenerAttached = "true";
      }
      window.aipkit_toggleTaskCwScheduleFields({ target: postStatusSelect }); // Initial call
    }

    if (typeof window.aipkit_initCategoryDropdowns === "function") {
      window.aipkit_initCategoryDropdowns(form);
    }

    // --- ADDED: CSV Upload handler ---
    if (typeof window.aipkit_initTaskCsvUploadHandler === "function") {
      window.aipkit_initTaskCsvUploadHandler();
    }
    // --- END ADDED ---

    const isPro = window.aipkit_dashboard && window.aipkit_dashboard.isProPlan;
    if (isPro) {
      if (typeof window.aipkit_initTaskRssInputHandler === "function") {
        window.aipkit_initTaskRssInputHandler();
      } else if (typeof window.aipkit_initRssInputHandler === "function") {
        window.aipkit_initRssInputHandler();
      }

      if (typeof window.aipkit_initGsheetsVerification === "function") {
        window.aipkit_initGsheetsVerification();
      } else {
        console.warn("AutoGPT CW UI: GSheets verification handler not found.");
      }

      if (typeof window.aipkit_initUrlScrapeHandler === "function") {
        window.aipkit_initUrlScrapeHandler();
      } else {
        console.warn("AutoGPT CW UI: URL Scrape handler not found.");
      }
    }
    // --- ADDED: Image Settings UI handlers ---
    const imageEnableToggle = form.querySelector(
      ".aipkit_task_cw_image_enable_toggle"
    );
    const featuredImageToggle = form.querySelector(
      "#aipkit_task_cw_generate_featured_image"
    );
    const imageChildToggles = Array.from(
      form.querySelectorAll("[data-aipkit-image-child-toggle]")
    );
    const imageModeControl = form.querySelector(
      "#aipkit_task_cw_image_mode_control"
    );
    if (imageModeControl) {
      if (!imageModeControl.dataset.cwImgModeListenerAttached) {
        imageModeControl.addEventListener("change", function () {
          syncTaskImageModeFields(form, imageModeControl.value, true);
          if (typeof window.aipkit_updateTaskImageSettingsUI === "function") {
            window.aipkit_updateTaskImageSettingsUI();
          }
        });
        imageModeControl.dataset.cwImgModeListenerAttached = "true";
      }
    }
    if (imageEnableToggle) {
      if (!imageEnableToggle.dataset.cwImgListenerAttached) {
        imageEnableToggle.addEventListener("change", function () {
          if (typeof window.aipkit_updateTaskImageSettingsUI === "function") {
            window.aipkit_updateTaskImageSettingsUI();
          }
        });
        imageEnableToggle.dataset.cwImgListenerAttached = "true";
      }
    }
    if (featuredImageToggle) {
      if (!featuredImageToggle.dataset.cwImgListenerAttached) {
        featuredImageToggle.addEventListener("change", function () {
          if (typeof window.aipkit_updateTaskImageSettingsUI === "function") {
            window.aipkit_updateTaskImageSettingsUI();
          }
        });
        featuredImageToggle.dataset.cwImgListenerAttached = "true";
      }
    }
    imageChildToggles.forEach((toggle) => {
      if (toggle.dataset.cwImgChildListenerAttached) return;
      toggle.addEventListener("change", function () {
        const backendToggle = toggle.dataset.aipkitImageChildToggle === "image"
          ? imageEnableToggle
          : featuredImageToggle;
        updateTaskImageCheckboxState(backendToggle, toggle.checked, true);
        if (typeof window.aipkit_updateTaskImageSettingsUI === "function") {
          window.aipkit_updateTaskImageSettingsUI();
        }
      });
      toggle.dataset.cwImgChildListenerAttached = "true";
    });

    form.querySelectorAll("[data-aipkit-image-count-step]").forEach((button) => {
      if (button.dataset.cwImgCountStepListenerAttached) return;
      button.addEventListener("click", function () {
        const input = form.querySelector("#aipkit_task_cw_image_count");
        if (!input) return;
        const direction = Number(button.dataset.aipkitImageCountStep || 0);
        const current = Number(input.value || input.min || 1);
        const min = Number(input.min || 1);
        const max = Number(input.max || 10);
        input.value = String(Math.min(max, Math.max(min, current + direction)));
        input.dispatchEvent(new Event("input", { bubbles: true }));
        input.dispatchEvent(new Event("change", { bubbles: true }));
      });
      button.dataset.cwImgCountStepListenerAttached = "true";
    });

    form
      .querySelectorAll("[data-aipkit-image-placement-step]")
      .forEach((button) => {
        if (button.dataset.cwImgPlacementStepListenerAttached) return;
        button.addEventListener("click", function () {
          const input = form.querySelector(
            "#aipkit_task_cw_image_placement_param_x"
          );
          if (!input) return;
          const direction = Number(
            button.dataset.aipkitImagePlacementStep || 0
          );
          const current = Number(input.value || input.min || 1);
          const min = Number(input.min || 1);
          const max = input.max ? Number(input.max) : Number.POSITIVE_INFINITY;
          input.value = String(
            Math.min(max, Math.max(min, current + direction))
          );
          input.dispatchEvent(new Event("input", { bubbles: true }));
          input.dispatchEvent(new Event("change", { bubbles: true }));
        });
        button.dataset.cwImgPlacementStepListenerAttached = "true";
      });

    const imageProviderSelect = form.querySelector(
      "#aipkit_task_cw_image_provider"
    );
    const imageSelectionSelect = form.querySelector(
      "#aipkit_task_cw_image_selection"
    );
    if (imageProviderSelect) {
      if (!imageProviderSelect.dataset.cwImgListenerAttached) {
        imageProviderSelect.addEventListener("change", function () {
          if (typeof window.aipkit_updateTaskImageSettingsUI === "function") {
            window.aipkit_updateTaskImageSettingsUI();
          }
        });
        imageProviderSelect.dataset.cwImgListenerAttached = "true";
      }
    }
    if (imageSelectionSelect) {
      imageSelectionSelect._aipkitRefreshModelOptions = () =>
        refreshTaskImageSourceSelection(form, true);
      if (!imageSelectionSelect.dataset.cwImgSelectionListenerAttached) {
        imageSelectionSelect.addEventListener("change", function () {
          const providerField = form.querySelector("#aipkit_task_cw_image_provider");
          const modelField = form.querySelector("#aipkit_task_cw_image_model");
          if (!providerField || !modelField) {
            return;
          }

          const selectedImageOption = imageSelectionSelect.selectedOptions?.[0];
          const selectedImageProvider = normalizeTaskImageProviderKey(
            selectedImageOption?.dataset?.provider || ""
          );
          const selectedImageModel = String(
            selectedImageOption?.dataset?.model || ""
          );
          const isChangingProvider =
            normalizeTaskImageProviderKey(providerField.value) !==
            selectedImageProvider;
          if (
            isChangingProvider &&
            window.aipkit_automated_tasks_form_state
          ) {
            window.aipkit_automated_tasks_form_state.pendingImageModelSelection =
              selectedImageModel;
          }

          const { providerChanged, modelChanged } = syncTaskHiddenImageSelectionFields(
            imageSelectionSelect,
            providerField,
            modelField
          );

          if (providerChanged) {
            providerField.dispatchEvent(new Event("change", { bubbles: true }));
          }
          if (modelChanged) {
            modelField.dispatchEvent(new Event("change", { bubbles: true }));
          }

          if (typeof window.aipkit_updateTaskImageSettingsUI === "function") {
            window.aipkit_updateTaskImageSettingsUI();
          }
        });
        imageSelectionSelect.dataset.cwImgSelectionListenerAttached = "true";
      }
    }

    if (featuredImageToggle) {
      if (!featuredImageToggle.dataset.cwImgListenerAttached) {
        featuredImageToggle.addEventListener("change", function () {
          if (typeof window.aipkit_updateTaskImageSettingsUI === "function") {
            window.aipkit_updateTaskImageSettingsUI();
          }
        });
        featuredImageToggle.dataset.cwImgListenerAttached = "true";
      }
    }

    const imagePlacementSelect = form.querySelector(
      ".aipkit_task_cw_image_placement_select"
    );
    if (imagePlacementSelect) {
      if (!imagePlacementSelect.dataset.cwImgListenerAttached) {
        imagePlacementSelect.addEventListener("change", function () {
          if (typeof window.aipkit_updateTaskImageSettingsUI === "function") {
            window.aipkit_updateTaskImageSettingsUI();
          }
        });
        imagePlacementSelect.dataset.cwImgListenerAttached = "true";
      }
    }

    const imageAdvancedToggle = form.querySelector(
      "[data-aipkit-image-advanced-toggle]"
    );
    const imageAdvancedPanel = form.querySelector(
      "[data-aipkit-image-advanced-panel]"
    );
    if (
      imageAdvancedToggle &&
      !imageAdvancedToggle.dataset.cwImgAdvancedListenerAttached
    ) {
      imageAdvancedToggle.addEventListener("click", function () {
        const isExpanded =
          imageAdvancedToggle.getAttribute("aria-expanded") === "true";
        setTaskImageAdvancedExpanded(form, !isExpanded);
      });
      imageAdvancedToggle.dataset.cwImgAdvancedListenerAttached = "true";
    }
    if (
      imageAdvancedPanel &&
      !imageAdvancedPanel.dataset.cwImgAdvancedSummaryListenerAttached
    ) {
      const updateAdvancedSummary = () =>
        updateTaskImageAdvancedSummary(form);
      imageAdvancedPanel.addEventListener("change", updateAdvancedSummary);
      imageAdvancedPanel.addEventListener("input", updateAdvancedSummary);
      imageAdvancedPanel.addEventListener(
        "invalid",
        () => setTaskImageAdvancedExpanded(form, true),
        true
      );
      imageAdvancedPanel.dataset.cwImgAdvancedSummaryListenerAttached = "true";
    }

    const imageShapeControl = form.querySelector(
      "[data-aipkit-image-shape-control]"
    );
    if (
      imageShapeControl &&
      !imageShapeControl.dataset.cwImgShapeListenerAttached
    ) {
      imageShapeControl.addEventListener("change", function () {
        const sourceId = imageShapeControl.dataset.aipkitImageShapeSourceId;
        const source = sourceId ? document.getElementById(sourceId) : null;
        if (!source) return;
        source.value = imageShapeControl.value;
        source.dispatchEvent(new Event("change", { bubbles: true }));
        const provider = form.querySelector(
          "#aipkit_task_cw_image_provider"
        )?.value;
        renderTaskImageShapeChoices(form, imageShapeControl, provider || "");
      });
      imageShapeControl.dataset.cwImgShapeListenerAttached = "true";
    }

    if (!form.dataset.cwImgProviderOptionsListenerAttached) {
      form.addEventListener("change", function (event) {
        if (
          event.target &&
          event.target.matches("[data-aipkit-image-provider-option]")
        ) {
          syncTaskImageProviderOptions(form);
          if (typeof window.aipkit_updateTaskImageSettingsUI === "function") {
            window.aipkit_updateTaskImageSettingsUI();
          }
        }
      });
      form.dataset.cwImgProviderOptionsListenerAttached = "true";
    }
    
    // ADDED: Initialize UI state immediately and again after a brief delay to handle any async loading
    if (typeof window.aipkit_updateTaskImageSettingsUI === "function") {
      window.aipkit_updateTaskImageSettingsUI(); // Initial call
      
      // Second call after brief delay to handle any dynamic content loading
      setTimeout(() => {
        window.aipkit_updateTaskImageSettingsUI();
      }, 100);
    }
    // --- END ADDED ---

    // --- ADDED: Knowledge Base UI handler ---
    if (typeof window.aipkit_initCwKnowledgeBaseUI === "function") {
      window.aipkit_initCwKnowledgeBaseUI();
    }
    // --- END ADDED ---

  }

  function getTaskImageModeFromFields(form) {
    const contentToggle = form.querySelector(
      "#aipkit_task_cw_generate_images_enabled"
    );
    const featuredToggle = form.querySelector(
      "#aipkit_task_cw_generate_featured_image"
    );

    const contentEnabled = Boolean(contentToggle && contentToggle.checked);
    const featuredEnabled = Boolean(featuredToggle && featuredToggle.checked);

    if (contentEnabled && featuredEnabled) {
      return "both";
    }
    if (contentEnabled) {
      return "content";
    }
    if (featuredEnabled) {
      return "featured";
    }
    return "off";
  }

  function updateTaskImageCheckboxState(checkbox, checked, dispatchChange) {
    if (!checkbox || checkbox.checked === checked) {
      return;
    }
    checkbox.checked = checked;
    if (dispatchChange) {
      checkbox.dispatchEvent(new Event("change", { bubbles: true }));
    }
  }

  function syncTaskImageModeFields(form, mode, dispatchChange) {
    const contentToggle = form.querySelector(
      "#aipkit_task_cw_generate_images_enabled"
    );
    const featuredToggle = form.querySelector(
      "#aipkit_task_cw_generate_featured_image"
    );

    updateTaskImageCheckboxState(
      contentToggle,
      mode === "content" || mode === "both",
      dispatchChange
    );
    updateTaskImageCheckboxState(
      featuredToggle,
      mode === "featured" || mode === "both",
      dispatchChange
    );
  }

  function syncTaskImageModeControl(form) {
    const modeSelect = form.querySelector("#aipkit_task_cw_image_mode_control");
    if (!modeSelect) {
      return "off";
    }
    const mode = getTaskImageModeFromFields(form);
    if (modeSelect.value !== mode) {
      modeSelect.value = mode;
    }
    return mode;
  }

  function normalizeTaskImageProviderKey(value) {
    return String(value || "").trim().toLowerCase();
  }

  function getTaskImageSourceFamily(providerKey) {
    return ["pexels", "pixabay"].includes(
      normalizeTaskImageProviderKey(providerKey)
    )
      ? "stock"
      : "ai";
  }

  function getTaskProviderOptionBlocks(form) {
    return Array.from(
      form?.querySelectorAll("[data-aipkit-image-provider-options]") || []
    );
  }

  function getTaskProviderOptionBlock(form, providerKey) {
    const normalizedProvider = normalizeTaskImageProviderKey(providerKey);
    return (
      getTaskProviderOptionBlocks(form).find(
        (block) =>
          normalizeTaskImageProviderKey(
            block.dataset.aipkitImageProviderOptions
          ) === normalizedProvider
      ) || null
    );
  }

  function getTaskImageShapeSource(form, providerKey) {
    const provider = normalizeTaskImageProviderKey(providerKey);
    if (provider === "pexels") {
      return form.querySelector("#aipkit_task_cw_pexels_orientation");
    }
    if (provider === "pixabay") {
      return form.querySelector("#aipkit_task_cw_pixabay_orientation");
    }
    const optionKey = ["openai", "azure"].includes(provider)
      ? "canvas_size"
      : "aspect_ratio";
    const providerBlock = getTaskProviderOptionBlocks(form).find(
      (block) =>
        normalizeTaskImageProviderKey(
          block.dataset.aipkitImageProviderOptions
        ) === provider &&
        block.querySelector(
          `[data-aipkit-image-provider-option="${optionKey}"]`
        )
    );
    return (
      providerBlock?.querySelector(
        `[data-aipkit-image-provider-option="${optionKey}"]`
      ) || null
    );
  }

  function getTaskImageShapeSources(form) {
    return Array.from(
      form.querySelectorAll(
        '[data-aipkit-image-provider-option="canvas_size"], [data-aipkit-image-provider-option="aspect_ratio"], #aipkit_task_cw_pexels_orientation, #aipkit_task_cw_pixabay_orientation'
      )
    );
  }

  function restoreTaskImageShapeRows(form) {
    getTaskImageShapeSources(form).forEach((control) => {
      const row = control.closest(".aipkit_popover_option_row");
      if (row?.dataset.aipkitPrimaryShapeHidden === "1") {
        row.hidden = false;
        delete row.dataset.aipkitPrimaryShapeHidden;
      }
    });
  }

  function getTaskImageShapeChoiceLabel(option, sourceFamily) {
    const fallbackLabel = String(
      option?.textContent || option?.value || ""
    ).trim();
    if (sourceFamily === "stock") {
      const stockLabels = {
        all: "Any",
        none: "Any",
        horizontal: "Landscape",
        vertical: "Portrait",
      };
      return (
        stockLabels[String(option?.value || "").toLowerCase()] || fallbackLabel
      );
    }

    const value = String(option?.value || "").trim().toLowerCase();
    if (/^\d+:\d+$/.test(value)) {
      return value;
    }

    const dimensions = value.match(/^(\d+)x(\d+)$/);
    if (!dimensions) {
      return fallbackLabel;
    }

    const width = Number.parseInt(dimensions[1], 10);
    const height = Number.parseInt(dimensions[2], 10);
    let first = width;
    let second = height;
    while (second !== 0) {
      const remainder = first % second;
      first = second;
      second = remainder;
    }
    const divisor = first || 1;
    return `${width / divisor}:${height / divisor}`;
  }

  function renderTaskImageShapeChoices(form, shapeControl, providerKey) {
    const choices = form.querySelector("[data-aipkit-image-shape-choices]");
    const label = form.querySelector("[data-aipkit-image-shape-label]");
    const helper = form.querySelector("[data-aipkit-image-shape-helper]");
    const sourceFamily = getTaskImageSourceFamily(providerKey);
    const isStock = sourceFamily === "stock";
    const contentEnabled = Boolean(
      form.querySelector("#aipkit_task_cw_generate_images_enabled")?.checked
    );
    const featuredEnabled = Boolean(
      form.querySelector("#aipkit_task_cw_generate_featured_image")?.checked
    );
    const enabledMode =
      contentEnabled && featuredEnabled
        ? "Both"
        : featuredEnabled
          ? "Featured"
          : "Content";

    if (label) {
      label.textContent = isStock
        ? label.dataset.stockLabel || "Orientation"
        : label.dataset.aiLabel || "Aspect ratio";
    }
    if (helper) {
      const contextualHelperKey = `${isStock ? "stock" : "ai"}${enabledMode}Helper`;
      helper.hidden = isStock;
      helper.textContent = isStock
        ? ""
        : helper.dataset[contextualHelperKey] ||
          helper.dataset.aiHelper ||
          "Shape of each generated image.";
    }
    if (!choices) {
      return;
    }
    choices.dataset.aipkitImageShapeFamily = sourceFamily;

    const buttons = Array.from(shapeControl?.options || [])
      .filter((option) => !option.hidden)
      .map((option) => {
        const button = document.createElement("button");
        const isSelected = option.value === shapeControl.value;
        button.type = "button";
        button.className = `aipkit_cw_mode_card${isSelected ? " is-active" : ""}`;
        button.textContent = getTaskImageShapeChoiceLabel(
          option,
          sourceFamily
        );
        button.dataset.aipkitImageShapeValue = option.value;
        button.setAttribute("role", "radio");
        button.setAttribute("aria-checked", isSelected ? "true" : "false");
        button.disabled = option.disabled;
        button.addEventListener("click", () => {
          if (shapeControl.value === option.value) {
            return;
          }
          shapeControl.value = option.value;
          shapeControl.dispatchEvent(new Event("change", { bubbles: true }));
        });
        return button;
      });

    choices.replaceChildren(...buttons);
  }

  function syncTaskImageShapeControl(form, providerKey, shouldShowContainer) {
    const shapeControl = form.querySelector("[data-aipkit-image-shape-control]");
    const shapeRow = form.querySelector("[data-aipkit-image-shape-row]");
    const source = getTaskImageShapeSource(form, providerKey);
    const sourceRow = source?.closest(".aipkit_popover_option_row") || null;
    const hasAvailableOption = Boolean(
      source &&
        Array.from(source.options || []).some(
          (option) => !option.hidden && !option.disabled
        )
    );
    const sourceAvailable = Boolean(
      shouldShowContainer &&
        source &&
        sourceRow &&
        !source.disabled &&
        !sourceRow.hidden &&
        hasAvailableOption
    );

    if (shapeControl && sourceAvailable) {
      shapeControl.replaceChildren(
        ...Array.from(source.options || []).map((option) => {
          const clone = new Option(option.textContent || option.value, option.value);
          clone.disabled = option.disabled;
          clone.hidden = option.hidden;
          return clone;
        })
      );
      shapeControl.value = source.value;
      shapeControl.dataset.aipkitImageShapeSourceId = source.id;
      renderTaskImageShapeChoices(form, shapeControl, providerKey);
    } else if (shapeControl) {
      shapeControl.dataset.aipkitImageShapeSourceId = "";
      renderTaskImageShapeChoices(form, shapeControl, providerKey);
    }
    setTaskHidden(shapeRow, !sourceAvailable);

    getTaskImageShapeSources(form).forEach((control) => {
      const row = control.closest(".aipkit_popover_option_row");
      if (!row) return;
      row.hidden = true;
      row.dataset.aipkitPrimaryShapeHidden = "1";
    });
  }

  function syncTaskImageProviderOptions(form) {
    const sharedSync = window.aipkit_syncImageProviderOptions;
    if (typeof sharedSync === "function") {
      return sharedSync(form, 'input[name="image_provider_options"]');
    }

    const hiddenField =
      form?.querySelector('input[name="image_provider_options"]') ||
      form?.elements?.image_provider_options;
    const providerOptions = {};

    getTaskProviderOptionBlocks(form).forEach((block) => {
      const providerKey = normalizeTaskImageProviderKey(
        block.dataset.aipkitImageProviderOptions
      );
      if (!providerKey) {
        return;
      }

      block
        .querySelectorAll("[data-aipkit-image-provider-option]")
        .forEach((control) => {
          const optionKey = String(
            control.dataset.aipkitImageProviderOption || ""
          ).trim();
          if (!optionKey) {
            return;
          }
          if (!providerOptions[providerKey]) {
            providerOptions[providerKey] = {};
          }
          providerOptions[providerKey][optionKey] =
            control.type === "checkbox"
              ? control.checked
                ? "1"
                : "0"
              : String(control.value || "");
        });
    });

    const serialized = JSON.stringify(providerOptions);
    if (hiddenField) {
      hiddenField.value = serialized;
    }
    return serialized;
  }

  function updateTaskOpenAICompressionRows(form) {
    getTaskProviderOptionBlocks(form).forEach((block) => {
      if (
        normalizeTaskImageProviderKey(
          block.dataset.aipkitImageProviderOptions
        ) !== "openai"
      ) {
        return;
      }
      const formatControl = block.querySelector(
        '[data-aipkit-image-provider-option="output_format"]'
      );
      const outputFormat = normalizeTaskImageProviderKey(
        formatControl?.value || ""
      );
      const showCompression =
        outputFormat === "jpeg" || outputFormat === "webp";
      block
        .querySelectorAll("[data-aipkit-openai-compression-row]")
        .forEach((row) => setTaskHidden(row, !showCompression));
    });
  }

  function taskOpenAIModelSupportsTransparentBackground(modelId) {
    const model = String(modelId || "").trim().toLowerCase();
    const unsupportedModel = String(
      window.aipkit_dashboard?.modelCatalog?.defaults?.OpenAIImage || ""
    ).toLowerCase();
    return (
      model.startsWith("gpt-image") &&
      (!unsupportedModel || !model.startsWith(unsupportedModel))
    );
  }

  function updateTaskOpenAIBackgroundOptions(form, modelId) {
    getTaskProviderOptionBlocks(form).forEach((block) => {
      if (
        normalizeTaskImageProviderKey(
          block.dataset.aipkitImageProviderOptions
        ) !== "openai"
      ) {
        return;
      }
      const backgroundControl = block.querySelector(
        '[data-aipkit-image-provider-option="background"]'
      );
      const outputFormat = normalizeTaskImageProviderKey(
        block.querySelector(
          '[data-aipkit-image-provider-option="output_format"]'
        )?.value || ""
      );
      const supportsTransparent =
        taskOpenAIModelSupportsTransparentBackground(modelId) &&
        outputFormat !== "jpeg";
      const transparentOption = backgroundControl?.querySelector(
        '[data-aipkit-openai-transparent-background-option]'
      );
      if (!transparentOption) {
        return;
      }
      transparentOption.hidden = !supportsTransparent;
      transparentOption.disabled = !supportsTransparent;
      if (!supportsTransparent && backgroundControl.value === "transparent") {
        backgroundControl.value = "";
      }
    });
  }

  function updateTaskAzureCompressionRows(form) {
    getTaskProviderOptionBlocks(form).forEach((block) => {
      if (
        normalizeTaskImageProviderKey(
          block.dataset.aipkitImageProviderOptions
        ) !== "azure"
      ) {
        return;
      }
      const formatControl = block.querySelector(
        '[data-aipkit-image-provider-option="output_format"]'
      );
      const outputFormat = normalizeTaskImageProviderKey(
        formatControl?.value || ""
      );
      block
        .querySelectorAll("[data-aipkit-azure-compression-row]")
        .forEach((row) => setTaskHidden(row, outputFormat !== "jpeg"));
    });
  }

  function isTaskGoogleGeminiResponseFormatModel(modelId) {
    const model = String(modelId || "").toLowerCase();
    return model.includes("gemini") && model.includes("image");
  }

  function getTaskGoogleAspectRatios(modelId) {
    const model = String(modelId || "").toLowerCase();
    const ratios = [
      "",
      "1:1",
      "2:3",
      "3:2",
      "3:4",
      "4:3",
      "4:5",
      "5:4",
      "9:16",
      "16:9",
      "21:9",
    ];
    if (model.includes("gemini-3.1-flash-image")) {
      return ratios.concat(["1:4", "4:1", "1:8", "8:1"]);
    }
    return isTaskGoogleGeminiResponseFormatModel(model) ? ratios : [""];
  }

  function getTaskGoogleImageSizes(modelId) {
    const model = String(modelId || "").toLowerCase();
    if (model.includes("gemini-3.1-flash-lite-image")) {
      return ["", "1k"];
    }
    if (model.includes("gemini-3.1-flash-image")) {
      return ["", "512", "1k", "2k", "4k"];
    }
    if (model.includes("gemini-3-pro-image")) {
      return ["", "1k", "2k", "4k"];
    }
    return [""];
  }

  function getTaskOpenRouterModelMetadata(modelId) {
    const models = Array.isArray(
      window.aipkit_dashboard?.imageGeneratorModels?.openrouter
    )
      ? window.aipkit_dashboard.imageGeneratorModels.openrouter
      : [];
    const target = String(modelId || "").trim().toLowerCase();
    if (!target) {
      return null;
    }
    return (
      models.find((model) => {
        const id = String(model?.id || "").trim().toLowerCase();
        return id === target;
      }) || null
    );
  }

  function taskOpenRouterModelSupportsImageConfig(modelId) {
    const model = String(modelId || "").trim().toLowerCase();
    if (!model) {
      return false;
    }

    const metadata = getTaskOpenRouterModelMetadata(modelId);
    const supportedParameters = Array.isArray(metadata?.supported_parameters)
      ? metadata.supported_parameters.map((value) =>
          String(value || "").trim().toLowerCase()
        )
      : [];
    if (
      supportedParameters.includes("image_config") ||
      supportedParameters.includes("image_config.aspect_ratio") ||
      supportedParameters.includes("image_config.image_size")
    ) {
      return true;
    }

    return (
      (model.startsWith("google/gemini-") && model.includes("image")) ||
      model.startsWith("black-forest-labs/flux") ||
      model.startsWith("recraft/") ||
      model.startsWith("sourceful/riverflow")
    );
  }

  function getTaskOpenRouterAspectRatios(modelId) {
    const ratios = [
      "",
      "1:1",
      "2:3",
      "3:2",
      "3:4",
      "4:3",
      "4:5",
      "5:4",
      "9:16",
      "16:9",
      "21:9",
    ];
    if (
      String(modelId || "")
        .toLowerCase()
        .includes("google/gemini-3.1-flash-image")
    ) {
      return ratios.concat(["1:4", "4:1", "1:8", "8:1"]);
    }
    return ratios;
  }

  function getTaskOpenRouterImageSizes(modelId) {
    const sizes = ["", "1k", "2k", "4k"];
    if (
      String(modelId || "")
        .toLowerCase()
        .includes("google/gemini-3.1-flash-image")
    ) {
      sizes.push("0.5k");
    }
    return sizes;
  }

  const taskReplicateProviderOptionFields = [
    "aspect_ratio",
    "width",
    "height",
    "negative_prompt",
    "guidance",
    "num_inference_steps",
    "seed",
    "output_format",
    "output_quality",
  ];

  const taskReplicateAspectRatioFallbacks = [
    "",
    "1:1",
    "16:9",
    "21:9",
    "3:2",
    "2:3",
    "4:3",
    "3:4",
    "4:5",
    "5:4",
    "9:16",
    "1:2",
    "2:1",
    "3:1",
    "1:3",
  ];

  const taskReplicateOutputFormatFallbacks = ["", "webp", "png", "jpg", "jpeg"];

  function getTaskReplicateModelMetadata(modelId) {
    const models = Array.isArray(
      window.aipkit_dashboard?.imageGeneratorModels?.replicate
    )
      ? window.aipkit_dashboard.imageGeneratorModels.replicate
      : [];
    const target = String(modelId || "").trim().toLowerCase();
    if (!target) {
      return null;
    }
    return (
      models.find((model) => {
        const id = String(model?.id || "").trim().toLowerCase();
        return id === target;
      }) || null
    );
  }

  function getTaskReplicateInputSchema(modelId) {
    const metadata = getTaskReplicateModelMetadata(modelId);
    const schema = metadata?.input_schema || metadata?.replicate_input_schema;
    return schema && typeof schema === "object" ? schema : {};
  }

  function getTaskReplicateInputFields(modelId) {
    const schema = getTaskReplicateInputSchema(modelId);
    const fields = schema.fields || schema;
    return fields && typeof fields === "object" ? fields : {};
  }

  function getTaskReplicateInputField(modelId, fieldName) {
    const fields = getTaskReplicateInputFields(modelId);
    const field = fields[fieldName];
    return field && typeof field === "object" ? field : null;
  }

  function taskReplicateModelSupportsInputField(modelId, fieldName) {
    return Boolean(getTaskReplicateInputField(modelId, fieldName));
  }

  function taskReplicateModelSupportsProviderOptions(modelId) {
    return taskReplicateProviderOptionFields.some((fieldName) =>
      taskReplicateModelSupportsInputField(modelId, fieldName)
    );
  }

  function getTaskReplicateSchemaEnumValues(modelId, fieldName, fallbackValues) {
    const field = getTaskReplicateInputField(modelId, fieldName);
    const enumValues = Array.isArray(field?.enum)
      ? field.enum
          .map((value) => String(value || "").trim())
          .filter((value) => value !== "")
      : [];
    if (enumValues.length > 0) {
      return ["", ...enumValues];
    }
    return fallbackValues;
  }

  function setTaskNumberInputSchemaRange(input, field, fallbackMin, fallbackMax) {
    if (!input) {
      return;
    }
    const minValue = Number.isFinite(Number(field?.minimum))
      ? Number(field.minimum)
      : fallbackMin;
    const maxValue = Number.isFinite(Number(field?.maximum))
      ? Number(field.maximum)
      : fallbackMax;
    input.min = String(minValue);
    input.max = String(maxValue);
    if (input.value !== "") {
      const numericValue = Number(input.value);
      if (
        !Number.isFinite(numericValue) ||
        numericValue < minValue ||
        numericValue > maxValue
      ) {
        input.value = "";
      }
    }
  }

  function setTaskSelectAllowedValues(select, allowedValues) {
    if (!select?.options) {
      return;
    }
    const allowed = new Set(allowedValues);
    Array.from(select.options).forEach((option) => {
      const isAllowed = allowed.has(option.value);
      option.hidden = !isAllowed;
      option.disabled = !isAllowed;
    });
    if (!allowed.has(select.value)) {
      select.value = "";
    }
  }

  function isTaskImageProviderOptionsSupported(providerKey, modelId) {
    if (typeof window.aipkit_isImageProviderOptionsSupported === "function") {
      return window.aipkit_isImageProviderOptionsSupported(providerKey, modelId);
    }

    const provider = normalizeTaskImageProviderKey(providerKey);
    if (provider === "openrouter") {
      return taskOpenRouterModelSupportsImageConfig(modelId);
    }
    if (provider === "replicate") {
      return taskReplicateModelSupportsProviderOptions(modelId);
    }
    if (provider !== "google") {
      return true;
    }
    return isTaskGoogleGeminiResponseFormatModel(modelId);
  }

  function updateTaskImageProviderOptionRows(form, providerKey, modelId) {
    if (typeof window.aipkit_updateImageProviderOptionRows === "function") {
      window.aipkit_updateImageProviderOptionRows(form, providerKey, modelId);
      return;
    }

    updateTaskOpenAICompressionRows(form);
    updateTaskOpenAIBackgroundOptions(form, modelId);
    updateTaskAzureCompressionRows(form);

    if (normalizeTaskImageProviderKey(providerKey) === "openrouter") {
      getTaskProviderOptionBlocks(form).forEach((block) => {
        if (
          normalizeTaskImageProviderKey(
            block.dataset.aipkitImageProviderOptions
          ) !== "openrouter"
        ) {
          return;
        }

        const supportsImageConfig =
          taskOpenRouterModelSupportsImageConfig(modelId);
        const aspectControl = block.querySelector(
          '[data-aipkit-image-provider-option="aspect_ratio"]'
        );
        const imageSizeControl = block.querySelector(
          '[data-aipkit-image-provider-option="image_size"]'
        );

        setTaskSelectAllowedValues(
          aspectControl,
          getTaskOpenRouterAspectRatios(modelId)
        );
        setTaskSelectAllowedValues(
          imageSizeControl,
          getTaskOpenRouterImageSizes(modelId)
        );

        block
          .querySelectorAll("[data-aipkit-openrouter-aspect-ratio-row]")
          .forEach((row) => setTaskHidden(row, !supportsImageConfig));
        block
          .querySelectorAll("[data-aipkit-openrouter-image-size-row]")
          .forEach((row) => setTaskHidden(row, !supportsImageConfig));
      });
      return;
    }

    if (normalizeTaskImageProviderKey(providerKey) === "replicate") {
      getTaskProviderOptionBlocks(form).forEach((block) => {
        if (
          normalizeTaskImageProviderKey(
            block.dataset.aipkitImageProviderOptions
          ) !== "replicate"
        ) {
          return;
        }

        taskReplicateProviderOptionFields.forEach((fieldName) => {
          const field = getTaskReplicateInputField(modelId, fieldName);
          block
            .querySelectorAll(
              `[data-aipkit-replicate-option-row="${fieldName}"]`
            )
            .forEach((row) => setTaskHidden(row, !field));
        });

        setTaskSelectAllowedValues(
          block.querySelector(
            '[data-aipkit-image-provider-option="aspect_ratio"]'
          ),
          getTaskReplicateSchemaEnumValues(
            modelId,
            "aspect_ratio",
            taskReplicateAspectRatioFallbacks
          )
        );
        setTaskSelectAllowedValues(
          block.querySelector(
            '[data-aipkit-image-provider-option="output_format"]'
          ),
          getTaskReplicateSchemaEnumValues(
            modelId,
            "output_format",
            taskReplicateOutputFormatFallbacks
          )
        );
        setTaskNumberInputSchemaRange(
          block.querySelector('[data-aipkit-image-provider-option="width"]'),
          getTaskReplicateInputField(modelId, "width"),
          64,
          4096
        );
        setTaskNumberInputSchemaRange(
          block.querySelector('[data-aipkit-image-provider-option="height"]'),
          getTaskReplicateInputField(modelId, "height"),
          64,
          4096
        );
        setTaskNumberInputSchemaRange(
          block.querySelector('[data-aipkit-image-provider-option="guidance"]'),
          getTaskReplicateInputField(modelId, "guidance"),
          0,
          30
        );
        setTaskNumberInputSchemaRange(
          block.querySelector(
            '[data-aipkit-image-provider-option="num_inference_steps"]'
          ),
          getTaskReplicateInputField(modelId, "num_inference_steps"),
          1,
          100
        );
        setTaskNumberInputSchemaRange(
          block.querySelector('[data-aipkit-image-provider-option="seed"]'),
          getTaskReplicateInputField(modelId, "seed"),
          0,
          2147483647
        );
        setTaskNumberInputSchemaRange(
          block.querySelector(
            '[data-aipkit-image-provider-option="output_quality"]'
          ),
          getTaskReplicateInputField(modelId, "output_quality"),
          0,
          100
        );
      });
      return;
    }

    if (normalizeTaskImageProviderKey(providerKey) !== "google") {
      return;
    }

    getTaskProviderOptionBlocks(form).forEach((block) => {
      if (
        normalizeTaskImageProviderKey(
          block.dataset.aipkitImageProviderOptions
        ) !== "google"
      ) {
        return;
      }

      const supportsGeminiConfig =
        isTaskGoogleGeminiResponseFormatModel(modelId);
      const aspectRatios = getTaskGoogleAspectRatios(modelId);
      const imageSizes = getTaskGoogleImageSizes(modelId);
      const aspectControl = block.querySelector(
        '[data-aipkit-image-provider-option="aspect_ratio"]'
      );
      const imageSizeControl = block.querySelector(
        '[data-aipkit-image-provider-option="image_size"]'
      );

      setTaskSelectAllowedValues(aspectControl, aspectRatios);
      setTaskSelectAllowedValues(imageSizeControl, imageSizes);

      block
        .querySelectorAll("[data-aipkit-google-aspect-ratio-row]")
        .forEach((row) => setTaskHidden(row, !supportsGeminiConfig));
      block
        .querySelectorAll("[data-aipkit-google-image-size-row]")
        .forEach((row) => setTaskHidden(row, imageSizes.length <= 1));
    });
  }

  function isTaskImageProviderConfigured(providerKey) {
    const normalizedKey = normalizeTaskImageProviderKey(providerKey);
    if (!normalizedKey) {
      return true;
    }
    const statusMap =
      window.aipkit_provider_status &&
      typeof window.aipkit_provider_status === "object"
        ? window.aipkit_provider_status
        : window.aipkit_dashboard &&
            window.aipkit_dashboard.providerStatus &&
            typeof window.aipkit_dashboard.providerStatus === "object"
          ? window.aipkit_dashboard.providerStatus
        : null;
    if (
      !statusMap ||
      !Object.prototype.hasOwnProperty.call(statusMap, normalizedKey)
    ) {
      return true;
    }
    return Boolean(statusMap[normalizedKey]);
  }

  function getTaskImageProviderNoticeMessage(notice, providerKey) {
    if (!notice) {
      return "";
    }
    if (getTaskImageSourceFamily(providerKey) === "stock") {
      return translate(
        "Connect the selected stock photo provider to use it in this automation.",
        "gpt3-ai-content-generator"
      );
    }
    return notice.dataset?.messageDefault || "";
  }

  function setTaskHidden(element, shouldHide) {
    if (!element) {
      return;
    }
    element.hidden = Boolean(shouldHide);
  }

  function setTaskImageAdvancedExpanded(form, shouldExpand) {
    const toggle = form?.querySelector("[data-aipkit-image-advanced-toggle]");
    const panel = form?.querySelector("[data-aipkit-image-advanced-panel]");
    if (!toggle || !panel) {
      return;
    }

    const isExpanded = Boolean(shouldExpand);
    toggle.setAttribute("aria-expanded", isExpanded ? "true" : "false");
    panel.hidden = !isExpanded;
    updateTaskImageLastVisibleOptionRow(form);
  }

  function isTaskImageAdvancedControlApplicable(control, panel) {
    if (!control || !panel || control.disabled || !control.name) {
      return false;
    }

    let current = control.parentElement;
    while (current && current !== panel) {
      if (current.hidden || current.style?.display === "none") {
        return false;
      }
      current = current.parentElement;
    }
    return current === panel;
  }

  function isTaskImageAdvancedControlCustomized(control) {
    if (!control) {
      return false;
    }

    if (control.type === "checkbox" || control.type === "radio") {
      return control.checked !== control.defaultChecked;
    }

    if (control.tagName === "SELECT") {
      const selectedValues = Array.from(control.selectedOptions || []).map(
        (option) => option.value
      );
      let defaultOptions = Array.from(control.options || []).filter(
        (option) => option.defaultSelected
      );
      if (defaultOptions.length === 0 && control.options?.length) {
        defaultOptions = [control.options[0]];
      }
      const defaultValues = defaultOptions.map((option) => option.value);
      return (
        selectedValues.length !== defaultValues.length ||
        selectedValues.some((value, index) => value !== defaultValues[index])
      );
    }

    return String(control.value || "") !== String(control.defaultValue || "");
  }

  function updateTaskImageAdvancedSummary(form) {
    const root = form?.querySelector("[data-aipkit-image-advanced]");
    const panel = form?.querySelector("[data-aipkit-image-advanced-panel]");
    const summary = form?.querySelector("[data-aipkit-image-advanced-summary]");
    if (!root || !panel || !summary) {
      return;
    }

    const controls = Array.from(
      panel.querySelectorAll("select[name], input[name], textarea[name]")
    ).filter((control) =>
      isTaskImageAdvancedControlApplicable(control, panel)
    );
    const customCount = controls.filter((control) =>
      isTaskImageAdvancedControlCustomized(control)
    ).length;

    root.hidden = controls.length === 0;
    summary.textContent =
      customCount === 0
        ? summary.dataset.defaultLabel || "Using recommended defaults"
        : customCount === 1
          ? summary.dataset.customOneLabel || "1 customized"
          : (summary.dataset.customManyLabel || "%d customized").replace(
              "%d",
              customCount
            );
    root.classList.toggle("has-custom-settings", customCount > 0);
  }

  function updateTaskImageLastVisibleOptionRow(form) {
    if (!form) {
      return;
    }

    const isVisibleRow = (row) => {
      let current = row;
      while (current && current !== form) {
        if (current.hidden) {
          return false;
        }
        if (current.style && current.style.display === "none") {
          return false;
        }
        current = current.parentElement;
      }

      return true;
    };

    const rows = Array.from(
      form.querySelectorAll(
        "[data-aipkit-image-inline-settings] .aipkit_popover_option_row"
      )
    );

    rows.forEach((row) => row.classList.remove("aipkit_last_visible_row"));

    const visibleRows = rows.filter((row) => isVisibleRow(row));
    const lastVisibleRow = visibleRows.length
      ? visibleRows[visibleRows.length - 1]
      : null;

    if (lastVisibleRow) {
      lastVisibleRow.classList.add("aipkit_last_visible_row");
    }
  }

  function getTaskImageProviderEntries(providerField) {
    const resolver = window.aipkit_getImageProviderEntries;
    if (typeof resolver === "function") {
      return resolver(providerField);
    }

    if (!providerField?.options) {
      return [];
    }

    const entries = [];
    Array.from(providerField.children).forEach((child) => {
      if (child.tagName === "OPTGROUP") {
        Array.from(child.children).forEach((option) => {
          if (!option.value || option.disabled) {
            return;
          }
          entries.push({
            key: normalizeTaskImageProviderKey(option.value),
            label: option.textContent.trim(),
          });
        });
        return;
      }

      if (child.tagName === "OPTION" && child.value && !child.disabled) {
        entries.push({
          key: normalizeTaskImageProviderKey(child.value),
          label: child.textContent.trim(),
        });
      }
    });

    return entries;
  }

  function buildTaskImageSelectionValue(providerKey, modelId) {
    const builder = window.aipkit_buildImageSelectionValue;
    if (typeof builder === "function") {
      return builder(providerKey, modelId);
    }

    return `${encodeURIComponent(providerKey)}::${encodeURIComponent(
      modelId || ""
    )}`;
  }

  function findTaskImageSelectionOption(select, providerKey, modelId) {
    const finder = window.aipkit_findImageSelectionOption;
    if (typeof finder === "function") {
      return finder(select, providerKey, modelId);
    }

    if (!select?.options) {
      return null;
    }

    return (
      Array.from(select.options).find(
        (option) =>
          normalizeTaskImageProviderKey(option.dataset.provider) ===
            normalizeTaskImageProviderKey(providerKey) &&
          String(option.dataset.model || "") === String(modelId || "")
      ) || null
    );
  }

  function findFirstTaskImageSelectionOptionForProvider(select, providerKey) {
    const finder = window.aipkit_findFirstImageSelectionOptionForProvider;
    if (typeof finder === "function") {
      return finder(select, providerKey);
    }

    if (!select?.options) {
      return null;
    }

    return (
      Array.from(select.options).find(
        (option) =>
          normalizeTaskImageProviderKey(option.dataset.provider) ===
          normalizeTaskImageProviderKey(providerKey)
      ) || null
    );
  }

  function syncTaskHiddenImageSelectionFields(
    selectionSelect,
    providerField,
    modelField
  ) {
    const sharedSync = window.aipkit_syncHiddenImageSelectionFields;
    if (typeof sharedSync === "function") {
      return sharedSync(selectionSelect, providerField, modelField);
    }

    if (!selectionSelect || !providerField || !modelField) {
      return { providerChanged: false, modelChanged: false };
    }

    const selectedOption =
      selectionSelect.selectedOptions && selectionSelect.selectedOptions.length
        ? selectionSelect.selectedOptions[0]
        : null;
    const nextProvider = normalizeTaskImageProviderKey(
      selectedOption?.dataset?.provider || ""
    );
    const nextModel = String(selectedOption?.dataset?.model || "");
    const providerChanged =
      normalizeTaskImageProviderKey(providerField.value) !== nextProvider;
    const modelChanged = String(modelField.value || "") !== nextModel;

    providerField.value = nextProvider;
    modelField.value = nextModel;

    return { providerChanged, modelChanged };
  }

  function populateTaskImageModels(providerField, modelField, preserveMissingModel = false) {
    if (!providerField || !modelField) {
      return;
    }

    const allModels = window.aipkit_dashboard?.imageGeneratorModels || {};
    const modelsForProvider =
      allModels[normalizeTaskImageProviderKey(providerField.value)] || [];
    const providerKey = normalizeTaskImageProviderKey(providerField.value);
    const pendingModel =
      window.aipkit_automated_tasks_form_state?.pendingImageModelSelection || "";
    const currentModelValue = modelField.value;
    const valueToSet = pendingModel || currentModelValue;

    modelField.innerHTML = "";

    if (modelsForProvider.length > 0) {
      modelsForProvider.forEach((model) => {
        modelField.appendChild(new Option(model.name, model.id));
      });

      if (
        (providerKey === "aipuffercloud" || preserveMissingModel) &&
        valueToSet &&
        !Array.from(modelField.options).some((option) => option.value === valueToSet)
      ) {
        modelField.appendChild(
          new Option(
            `${valueToSet} — ${translate("Unavailable", "gpt3-ai-content-generator")}`,
            valueToSet
          )
        );
      }

      if (
        valueToSet &&
        modelField.querySelector(`option[value="${CSS.escape(valueToSet)}"]`)
      ) {
        modelField.value = valueToSet;
      } else if (modelField.options.length > 0) {
        modelField.selectedIndex = 0;
      }
    } else {
      const savedModel = providerKey === "aipuffercloud" || preserveMissingModel ? valueToSet : "";
      modelField.appendChild(
        new Option(
          savedModel
            ? `${savedModel} — ${translate("Unavailable", "gpt3-ai-content-generator")}`
            : "",
          savedModel
        )
      );
      modelField.value = savedModel;
    }

    if (window.aipkit_automated_tasks_form_state) {
      window.aipkit_automated_tasks_form_state.pendingImageModelSelection = null;
    }
  }

  function populateTaskCombinedImageSelection(form, preserveMissingModel = false) {
    if (!form) {
      return;
    }

    const selectionSelect = form.querySelector("#aipkit_task_cw_image_selection");
    const providerField = form.querySelector("#aipkit_task_cw_image_provider");
    const modelField = form.querySelector("#aipkit_task_cw_image_model");

    if (!selectionSelect || !providerField || !modelField) {
      return;
    }

    const rawProviderEntries = getTaskImageProviderEntries(providerField);
    const setup = window.aipkit_autogpt_provider_setup;
    const providerEntries = rawProviderEntries;
    const allModels = window.aipkit_dashboard?.imageGeneratorModels || {};
    const currentProvider = normalizeTaskImageProviderKey(providerField.value);
    const currentModel = String(modelField.value || "");
    const pendingModel =
      window.aipkit_automated_tasks_form_state?.pendingImageModelSelection || "";

    selectionSelect.innerHTML = "";

    let firstOption = null;

    providerEntries.forEach((providerEntry) => {
      const optgroup = document.createElement("optgroup");
      const category = getTaskImageSourceFamily(providerEntry.key);
      optgroup.label =
        category === "stock"
          ? translate("Stock Photos", "gpt3-ai-content-generator")
          : providerEntry.label;

      const modelItems = Array.isArray(allModels[providerEntry.key])
        ? allModels[providerEntry.key]
        : [];
      const savedModel =
        (providerEntry.key === "aipuffercloud" || preserveMissingModel) &&
        currentProvider === providerEntry.key
          ? pendingModel || currentModel
          : "";
      if (providerEntry.key === "aipuffercloud" && !modelItems.length && !savedModel) {
        return;
      }
      const optionItems = modelItems.length
        ? modelItems.map((model) => ({
            id: String(model?.id || ""),
            label: String(model?.name || model?.id || providerEntry.label),
          }))
        : [
            {
              id: "",
              label: providerEntry.label,
            },
          ];

      if (
        savedModel &&
        !optionItems.some((item) => item.id === savedModel)
      ) {
        optionItems.push({
          id: savedModel,
          label: `${savedModel} — ${translate("Unavailable", "gpt3-ai-content-generator")}`,
        });
      }

      optionItems.forEach((item) => {
        const option = new Option(
          item.label,
          buildTaskImageSelectionValue(providerEntry.key, item.id)
        );
        option.dataset.provider = providerEntry.key;
        option.dataset.model = item.id;
        option.dataset.providerLabel = providerEntry.label;
        option.dataset.category = category;
        if (category === "stock") {
          option.dataset.aipkitPickerProvider = "stock_photos";
          option.dataset.aipkitPickerProviderLabel = translate(
            "Stock Photos",
            "gpt3-ai-content-generator"
          );
          option.dataset.aipkitModelPickerValue = `stock:${providerEntry.key}`;
          option.dataset.groupLabel = translate(
            "Stock Photos",
            "gpt3-ai-content-generator"
          );
        }
        if (setup?.getSetupState?.(providerEntry.key) === "missing") {
          option.dataset.setupRequired = "true";
        }
        optgroup.appendChild(option);
        if (!firstOption) {
          firstOption = option;
        }
      });

      selectionSelect.appendChild(optgroup);
    });

    if (!selectionSelect.options.length) {
      selectionSelect.appendChild(new Option("No image sources available", ""));
      selectionSelect.disabled = true;
      providerField.value = "";
      modelField.value = "";
      return;
    }

    selectionSelect.disabled = false;

    const preferredOption =
      (pendingModel &&
        findTaskImageSelectionOption(
          selectionSelect,
          currentProvider,
          pendingModel
        )) ||
      (currentModel &&
        findTaskImageSelectionOption(
          selectionSelect,
          currentProvider,
          currentModel
        )) ||
      findFirstTaskImageSelectionOptionForProvider(
        selectionSelect,
        currentProvider
      ) ||
      firstOption;

    if (preferredOption) {
      selectionSelect.value = preferredOption.value;
    }

    syncTaskHiddenImageSelectionFields(selectionSelect, providerField, modelField);
    selectionSelect._aipkitUnifiedModelSync?.();

    if (typeof window.aipkit_refreshContentWriterSelectPickers === "function") {
      window.aipkit_refreshContentWriterSelectPickers();
    }
  }

  function refreshTaskImageSourceSelection(form, force = false) {
    if (!form) {
      return;
    }

    const providerField = form.querySelector("#aipkit_task_cw_image_provider");
    const modelField = form.querySelector("#aipkit_task_cw_image_model");
    const selectionSelect = form.querySelector("#aipkit_task_cw_image_selection");

    if (!providerField || !modelField || !selectionSelect) {
      return;
    }

    populateTaskImageModels(providerField, modelField, force);

    // form.reset() resets the selects, but not cached state or the picker label.
    // Only skip rebuilding when the actual selection matches the restored fields.
    const selectedOption = selectionSelect.selectedOptions?.[0];
    if (
      !force && selectedOption &&
      normalizeTaskImageProviderKey(selectedOption.dataset.provider) ===
        normalizeTaskImageProviderKey(providerField.value) &&
      String(selectedOption.dataset.model || "") === String(modelField.value || "")
    ) {
      selectionSelect._aipkitUnifiedModelSync?.();
      return;
    }

    populateTaskCombinedImageSelection(form, force);
  }

  function aipkit_updateTaskImageSettingsUI() {
    const form = document.getElementById("aipkit_automated_task_form");
    if (!form) return;

    if (
      typeof window.aipkit_normalizeStockImageOptionControls === "function"
    ) {
      window.aipkit_normalizeStockImageOptionControls(form);
    }

    const enableCheckbox = form.querySelector(
      ".aipkit_task_cw_image_enable_toggle"
    );
    const featuredImageCheckbox = form.querySelector(
      "#aipkit_task_cw_generate_featured_image"
    );
    const settingsContainer = form.querySelector(
      ".aipkit_task_cw_image_settings_container"
    );
    const imageProviderSelect = form.querySelector(
      "#aipkit_task_cw_image_provider"
    );
    const imageSelectionSelect = form.querySelector(
      "#aipkit_task_cw_image_selection"
    );
    const imageModelSelect = form.querySelector("#aipkit_task_cw_image_model");

    if (
      !enableCheckbox ||
      !featuredImageCheckbox ||
      !settingsContainer ||
      !imageProviderSelect ||
      !imageSelectionSelect ||
      !imageModelSelect
    )
      return;

    const inContentEnabled = enableCheckbox.checked;
    const featuredEnabled = featuredImageCheckbox.checked;
    const contentUiToggle = form.querySelector(
      '[data-aipkit-image-child-toggle="image"]'
    );
    const featuredUiToggle = form.querySelector(
      '[data-aipkit-image-child-toggle="featured_image"]'
    );
    if (contentUiToggle) contentUiToggle.checked = inContentEnabled;
    if (featuredUiToggle) featuredUiToggle.checked = featuredEnabled;
    syncTaskImageModeControl(form);
    const shouldShowContainer = inContentEnabled || featuredEnabled;
    if (shouldShowContainer) {
      refreshTaskImageSourceSelection(form);
    }
    const selectedProvider = normalizeTaskImageProviderKey(
      imageProviderSelect.value
    );
    const selectedImageModel = String(imageModelSelect.value || "");
    const selectedProviderOptionsBlock = getTaskProviderOptionBlock(
      form,
      selectedProvider
    );
    const selectedProviderOptionsSupported =
      isTaskImageProviderOptionsSupported(selectedProvider, selectedImageModel);
    const providerNotice = form.querySelector(
      "#aipkit_task_cw_image_provider_notice"
    );
    const providerOptionsBlock = form.querySelector(
      "#aipkit_task_cw_image_provider_options_block"
    );
    const pexelsOptions = form.querySelector("#aipkit_task_cw_pexels_options");
    const pixabayOptions = form.querySelector("#aipkit_task_cw_pixabay_options");
    const imageCountField = form.querySelector(
      "#aipkit_task_cw_image_display_count_field"
    );
    const imageSizeField = form.querySelector(
      "#aipkit_task_cw_image_display_size_field"
    );
    const imageAlignmentField = form.querySelector(
      "#aipkit_task_cw_image_display_alignment_field"
    );
    const imagePlacementField = form.querySelector(
      "#aipkit_task_cw_image_display_placement_field"
    );
    const placementSelect = form.querySelector("#aipkit_task_cw_image_placement");
    const placementXField = form.querySelector(
      "#aipkit_task_cw_image_display_param_x_field"
    );
    const contentOptionsPanel = form.querySelector(
      "[data-aipkit-image-content-panel]"
    );
    const imageInstructionSettings = form.querySelector(
      "[data-aipkit-image-instructions]"
    );
    const showProviderNotice =
      shouldShowContainer &&
      !isTaskImageProviderConfigured(selectedProvider);
    const showDisplaySettings = shouldShowContainer && inContentEnabled;
    const showAiProviderSettings =
      shouldShowContainer &&
      Boolean(selectedProviderOptionsBlock) &&
      selectedProviderOptionsSupported;
    const showStockProviderSettings =
      shouldShowContainer &&
      (selectedProvider === "pexels" || selectedProvider === "pixabay");
    const showProviderSettings =
      showAiProviderSettings || showStockProviderSettings;
    setTaskHidden(settingsContainer, !shouldShowContainer);
    setTaskHidden(imageInstructionSettings, false);

    if (providerNotice) {
      const message = showProviderNotice
        ? getTaskImageProviderNoticeMessage(providerNotice, selectedProvider)
        : "";
      if (typeof window.aipkit_updateProviderNotice === "function") {
        window.aipkit_updateProviderNotice(providerNotice, {
          providerKey: selectedProvider,
          message,
          visible: showProviderNotice,
        });
      } else {
        const messageEl = providerNotice.querySelector(
          ".aipkit_provider_notice_message"
        );
        if (message && messageEl) {
          messageEl.textContent = message;
        }
        providerNotice.hidden = !showProviderNotice;
        providerNotice.classList.toggle(
          "aipkit_provider_notice--hidden",
          !showProviderNotice
        );
      }
    }

    setTaskHidden(providerOptionsBlock, !showProviderSettings);
    setTaskHidden(contentOptionsPanel, !showDisplaySettings);
    setTaskHidden(imageCountField, !showDisplaySettings);
    setTaskHidden(imageSizeField, !showDisplaySettings);
    setTaskHidden(imageAlignmentField, !showDisplaySettings);
    setTaskHidden(imagePlacementField, !showDisplaySettings);
    getTaskProviderOptionBlocks(form).forEach((block) => {
      setTaskHidden(
        block,
        !(
          showAiProviderSettings &&
          normalizeTaskImageProviderKey(
            block.dataset.aipkitImageProviderOptions
          ) === selectedProvider
        )
      );
    });
    setTaskHidden(
      pexelsOptions,
      !(showStockProviderSettings && selectedProvider === "pexels")
    );
    setTaskHidden(
      pixabayOptions,
      !(showStockProviderSettings && selectedProvider === "pixabay")
    );
    restoreTaskImageShapeRows(form);
    updateTaskImageProviderOptionRows(
      form,
      selectedProvider,
      selectedImageModel
    );
    syncTaskImageShapeControl(form, selectedProvider, shouldShowContainer);
    syncTaskImageProviderOptions(form);

    const placementRequiresX =
      placementSelect &&
      ["after_every_x_h2", "after_every_x_h3", "after_every_x_p"].includes(
        placementSelect.value
      );
    setTaskHidden(
      placementXField,
      !(showDisplaySettings && placementRequiresX)
    );

    updateTaskImageLastVisibleOptionRow(form);
    updateTaskImageAdvancedSummary(form);
    if (typeof window.aipkit_syncAutogptInlinePrompts === "function") {
      window.aipkit_syncAutogptInlinePrompts();
    }
  }

  window.aipkit_initContentWritingTaskFormUI =
    aipkit_initContentWritingTaskFormUI;
  window.aipkit_updateTaskImageSettingsUI = aipkit_updateTaskImageSettingsUI;
})();
