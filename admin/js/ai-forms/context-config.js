/**
 * AIPKit AI Forms - Editor Context Configuration
 *
 * Owns knowledge-base, embedding and web-search controls in the form editor.
 */
(function () {
  "use strict";

  const escaper =
    window.aipkit_escapeHtml ||
    function (str) {
      return str;
    };
  const VECTOR_PROVIDERS = ["local", "openai", "google", "pinecone", "qdrant", "chroma"];
  let openaiStoreUpdateListenerAttached = false;

  const setOpenaiStoresOnConfig = (stores) => {
    if (!window.aipkit_ai_forms_config) {
      window.aipkit_ai_forms_config = {};
    }
    if (!window.aipkit_ai_forms_config.vectorStores) {
      window.aipkit_ai_forms_config.vectorStores = {};
    }
    window.aipkit_ai_forms_config.vectorStores.openai = stores;
  };

  const formatOpenaiStoreLabel = (store, index) => {
    const storeId = store.id || store.vector_store_id || "";
    const storeName = store.name || store.vector_store_name || "";
    const fallbackName = storeName || `Untitled store ${index}`;
    const fileCountTotal = store.file_counts ? store.file_counts.total : null;
    let fileCountDisplay = " (Files: N/A)";
    if (typeof fileCountTotal === "number") {
      fileCountDisplay = ` (${fileCountTotal} ${fileCountTotal === 1 ? "File" : "Files"})`;
    }
    return {
      id: storeId,
      label: `${fallbackName}${fileCountDisplay}`,
    };
  };

  const renderOpenaiStoreOptions = (selectEl, stores) => {
    if (!selectEl) return;
    const selected = new Set(
      Array.from(selectEl.selectedOptions || []).map((opt) => opt.value)
    );

    selectEl.innerHTML = "";

    const normalized = Array.isArray(stores) ? stores : [];
    let storeIndex = 1;
    normalized.forEach((store) => {
      if (!store || typeof store !== "object") {
        return;
      }
      const { id, label } = formatOpenaiStoreLabel(store, storeIndex);
      if (!id) {
        return;
      }
      const option = new Option(escaper(label), id);
      if (selected.has(id)) {
        option.selected = true;
      }
      selectEl.appendChild(option);
      storeIndex += 1;
    });

    if (!selectEl.options.length) {
      const emptyOption = new Option(
        "No stores found.",
        ""
      );
      emptyOption.disabled = true;
      selectEl.appendChild(emptyOption);
    }
  };

  const refreshOpenaiStoreSelectsInForms = (stores) => {
    const formsContainer = document.getElementById("aipkit_ai_forms_container");
    if (!formsContainer) {
      return;
    }

    const selectElements = Array.from(
      formsContainer.querySelectorAll('select[name="openai_vector_store_ids[]"]')
    );
    selectElements.forEach((selectEl) => {
      renderOpenaiStoreOptions(selectEl, stores);
      const contextPanel =
        selectEl.closest('[data-aipkit-settings-panel="context"]') ||
        selectEl.closest(".aipkit_vector_store_openai_field") ||
        formsContainer;
      initVectorStoreMultiSelect(contextPanel);
    });
  };

  const attachOpenaiStoreUpdateListener = () => {
    if (openaiStoreUpdateListenerAttached) {
      return;
    }

    window.addEventListener("aipkit:vector-store-list-updated", (event) => {
      const provider = String(event?.detail?.provider || "").toLowerCase();
      if (provider !== "openai") {
        return;
      }

      const stores = Array.isArray(event?.detail?.stores)
        ? event.detail.stores
        : [];
      setOpenaiStoresOnConfig(stores);
      refreshOpenaiStoreSelectsInForms(stores);
    });

    openaiStoreUpdateListenerAttached = true;
  };

  const initVectorStoreMultiSelect = (contextPanel) => {
    if (
      contextPanel &&
      typeof window.aipkit_initContentWriterVectorStoreMultiSelect === "function"
    ) {
      window.aipkit_initContentWriterVectorStoreMultiSelect(contextPanel);
    }
  };

  const notifyEditorMutation = (editorContainer) => {
    const formsContainer = editorContainer?.closest("#aipkit_ai_forms_container");
    if (
      formsContainer &&
      typeof window.aipkitForms_notifyEditorMutation === "function"
    ) {
      window.aipkitForms_notifyEditorMutation(formsContainer);
    }
  };

  const syncVectorFacadeSelect = (contextPanel) => {
    if (!contextPanel) {
      return;
    }
    const formsContainer = contextPanel.closest("#aipkit_ai_forms_container");
    const facadeSelect = formsContainer?.querySelector(
      ".aipkit_ai_form_context_enable_select"
    );
    const enableToggle = contextPanel.querySelector(
      ".aipkit_vector_store_enable_select"
    );
    if (!facadeSelect || !enableToggle) {
      return;
    }
    if (facadeSelect.type === "checkbox") {
      facadeSelect.checked = Boolean(enableToggle.checked);
      return;
    }
    facadeSelect.value = enableToggle.checked ? "1" : "0";
  };

  const getWebSearchCheckboxForProvider = (editorContainer, provider) => {
    if (!editorContainer || !provider) {
      return null;
    }
    const selectors = {
      OpenAI: 'input[name="openai_web_search_enabled"]',
      Claude: 'input[name="claude_web_search_enabled"]',
      OpenRouter: 'input[name="openrouter_web_search_enabled"]',
      xAI: 'input[name="xai_web_search_enabled"]',
      Google: 'input[name="google_search_grounding_enabled"]',
    };
    return selectors[provider]
      ? editorContainer.querySelector(selectors[provider])
      : null;
  };

  const syncWebSearchFromFacade = (editorContainer, facadeSelect) => {
    if (!editorContainer || !facadeSelect) {
      return;
    }
    const providerSelect = editorContainer.querySelector(
      "#aipkit_ai_form_ai_provider"
    );
    const checkbox = getWebSearchCheckboxForProvider(
      editorContainer,
      providerSelect?.value || ""
    );
    if (!checkbox || checkbox.disabled || facadeSelect.disabled) {
      if (facadeSelect.type === "checkbox") {
        facadeSelect.checked = false;
      } else {
        facadeSelect.value = "0";
      }
      return;
    }
    checkbox.checked =
      facadeSelect.type === "checkbox"
        ? Boolean(facadeSelect.checked)
        : facadeSelect.value === "1";
    checkbox.dispatchEvent(new Event("change", { bubbles: true }));
    if (typeof window.aipkitForms_updateWebSearchVisibility === "function") {
      window.aipkitForms_updateWebSearchVisibility(
        providerSelect?.value || "",
        editorContainer
      );
    }
  };

  const updateVectorStoreVisibility = (contextPanel) => {
    if (!contextPanel) {
      return;
    }

    const enableToggle = contextPanel.querySelector(
      ".aipkit_vector_store_enable_select"
    );
    const conditionalRow = contextPanel.querySelector(
      ".aipkit_vector_store_settings_conditional_row"
    );
    const providerSelect = contextPanel.querySelector(
      ".aipkit_vector_store_provider_select"
    );
    const providerFields = VECTOR_PROVIDERS.map((provider) => ({
      provider,
      field: contextPanel.querySelector(`.aipkit_vector_store_${provider}_field`),
    }));
    const embeddingRow = contextPanel.querySelector(
      ".aipkit_vector_store_embedding_config_row"
    );

    if (!enableToggle || !conditionalRow || !providerSelect) {
      return;
    }

    const isEnabled =
      enableToggle.tagName === "SELECT"
        ? enableToggle.value === "1"
        : enableToggle.checked;

    const keepSettingsVisible = Boolean(
      contextPanel.closest("#aipkit_ai_form_knowledge_base_settings_modal")
    );

    conditionalRow.style.display = isEnabled || keepSettingsVisible ? "block" : "none";

    if (!isEnabled && !keepSettingsVisible) {
      [...providerFields.map(({ field }) => field), embeddingRow].forEach((field) => {
        if (field) {
          field.style.display = "none";
        }
      });
      syncVectorFacadeSelect(contextPanel);
      return;
    }

    const editorContainer = contextPanel.closest("#aipkit_form_editor_container");
    const aiProvider = editorContainer?.querySelector("#aipkit_ai_form_ai_provider")?.value || "";
    const googleOption = Array.from(providerSelect.options || []).find(
      (option) => option.value === "google"
    );
    if (googleOption) {
      googleOption.disabled = aiProvider !== "Google";
      if (googleOption.disabled && providerSelect.value === "google") {
        providerSelect.value = "openai";
      }
    }
    const activeProvider = providerSelect.value || "openai";
    if (
      VECTOR_PROVIDERS.includes(activeProvider) &&
      typeof window.aipkit_refreshVectorStoreProviderList === "function"
    ) {
      window.aipkit_refreshVectorStoreProviderList(activeProvider);
    }
    providerFields.forEach(({ provider, field }) => {
      if (field) {
        field.style.display = activeProvider === provider ? "block" : "none";
      }
    });
    if (embeddingRow) {
      embeddingRow.style.display =
        ["pinecone", "qdrant", "chroma", "local"].includes(activeProvider)
          ? "block"
          : "none";
    }

    const confidenceField = contextPanel.querySelector(".aipkit_vector_store_confidence_field");
    if (confidenceField) {
      confidenceField.style.display = activeProvider === "google" ? "none" : "";
    }
    if (activeProvider === "openai" || activeProvider === "google") {
      initVectorStoreMultiSelect(contextPanel);
    }
    syncVectorFacadeSelect(contextPanel);
  };

  const parseEmbeddingChoice = (value) => {
    const parts = (value || "").split("::");
    const provider = parts.shift() || "";
    const model = parts.join("::") || "";
    return { provider, model };
  };

  const buildEmbeddingChoice = (provider, model) => {
    if (!provider || !model) {
      return "";
    }
    return `${provider}::${model}`;
  };

  const ensureEmbeddingOption = (selectEl, provider, model) => {
    if (!selectEl || !provider || !model) {
      return;
    }
    const value = buildEmbeddingChoice(provider, model);
    const escapedValue =
      typeof CSS !== "undefined" && CSS.escape
        ? CSS.escape(value)
        : value.replace(/"/g, '\\"');
    if (selectEl.querySelector(`option[value="${escapedValue}"]`)) {
      return;
    }
    const option = document.createElement("option");
    option.value = value;
    option.dataset.provider = provider;
    option.textContent = `${model}`;
    selectEl.appendChild(option);
  };

  const syncEmbeddingSelectFromInputs = (contextPanel, selectEl) => {
    if (!contextPanel) {
      return;
    }
    const embeddingSelect =
      selectEl || contextPanel.querySelector(".aipkit_vector_embedding_select");
    if (!embeddingSelect) {
      return;
    }
    const providerSelect = contextPanel.querySelector(
      "select[name=\"vector_embedding_provider\"]"
    );
    const modelSelect = contextPanel.querySelector(
      "select[name=\"vector_embedding_model\"]"
    );
    const provider = providerSelect ? providerSelect.value : "";
    const model = modelSelect ? modelSelect.value : "";
    const combined = buildEmbeddingChoice(provider, model);
    if (combined) {
      ensureEmbeddingOption(embeddingSelect, provider, model);
      embeddingSelect.value = combined;
      embeddingSelect._aipkitUnifiedModelSync?.();
      return;
    }
    embeddingSelect.value = "";
    embeddingSelect._aipkitUnifiedModelSync?.();
  };

  const syncEmbeddingInputsFromSelect = (selectEl, contextPanel) => {
    if (!contextPanel || !selectEl) {
      return;
    }
    const providerSelect = contextPanel.querySelector(
      "select[name=\"vector_embedding_provider\"]"
    );
    const modelSelect = contextPanel.querySelector(
      "select[name=\"vector_embedding_model\"]"
    );
    const { provider, model } = parseEmbeddingChoice(selectEl.value);
    if (providerSelect) {
      providerSelect.value = provider;
    }
    if (
      providerSelect &&
      modelSelect &&
      typeof window.aipkitForms_populateVectorEmbeddingModels === "function"
    ) {
      window.aipkitForms_populateVectorEmbeddingModels(contextPanel);
    }
    if (modelSelect) {
      modelSelect.value = model;
    }
  };

  /**
   * Populates the embedding model dropdown based on the selected embedding provider.
   * @param {HTMLElement} contextPanel - The context settings panel element.
   */
  function aipkitForms_populateVectorEmbeddingModels(contextPanel) {
    const providerSelect = contextPanel.querySelector(
      ".aipkit_vector_embedding_provider_select"
    );
    const modelSelect = contextPanel.querySelector(
      ".aipkit_vector_embedding_model_select"
    );
    const config = window.aipkit_ai_forms_config || {};
    const allModelsData = config.embeddingModels || {};

    if (!providerSelect || !modelSelect) return;

    const selectedModel = modelSelect.value;
    const selectedProvider = providerSelect.value;
    const providerKey = selectedProvider.toLowerCase();
    const modelsForProvider = allModelsData[providerKey] || [];

    modelSelect.innerHTML = "";
    modelSelect.appendChild(
      new Option(
        `-- Select ${
          providerSelect.options[providerSelect.selectedIndex]?.text || ""
        } Model --`,
        ""
      )
    );
    if (
      Array.isArray(modelsForProvider) &&
      modelsForProvider.length > 0
    ) {
      modelsForProvider.forEach((model) => {
        modelSelect.appendChild(new Option(escaper(model.name), model.id));
      });
      if (modelsForProvider.some((model) => model.id === selectedModel)) modelSelect.value = selectedModel;
      modelSelect.disabled = false;
    } else {
      modelSelect.appendChild(
        new Option("(No models found - Sync in Settings)", "")
      );
      modelSelect.disabled = true;
    }
  }

  const toggleWebSearchLocationDetails = (rootElement, provider, markLastRow = false) => {
    const scope = rootElement || document;
    const locTypeSelect = scope.querySelector(
      `.aipkit_ai_form_${provider}_web_search_loc_type_select`
    );
    const details = scope.querySelector(
      `.aipkit_ai_form_${provider}_web_search_location_details`
    );
    const locationTypeRow = markLastRow
      ? scope.querySelector(`.aipkit_ai_form_${provider}_web_search_location_type_row`)
      : null;
    if (!locTypeSelect || !details) return;

    const approximate = locTypeSelect.value === "approximate";
    details.style.display = approximate ? "block" : "none";
    if (locationTypeRow) {
      locationTypeRow.classList.toggle(
        "aipkit_ai_form_web_search_settings_last_visible_row",
        !approximate
      );
    }
  };

  function aipkitForms_toggleOpenAIWebSearchLocationDetails(rootElement) {
    toggleWebSearchLocationDetails(rootElement, "openai", true);
  }

  function aipkitForms_toggleClaudeWebSearchLocationDetails(rootElement) {
    toggleWebSearchLocationDetails(rootElement, "claude");
  }

  /**
   * Initializes event listeners for the Vector/Context and Tools panels.
   * @param {HTMLElement} formsContainerElement - The main container element for the AI Forms module.
   */
  function aipkitForms_initVectorConfig(formsContainerElement) {
    const editorContainer = formsContainerElement.querySelector(
      "#aipkit_form_editor_container"
    );
    if (!editorContainer) return;

    const settingsRoot =
      editorContainer.querySelector("#aipkit_ai_form_settings_panel") ||
      editorContainer;

    const contextPanel = editorContainer.querySelector(
      '[data-aipkit-settings-panel="context"]'
    );
    const toolsPanel = editorContainer.querySelector(
      '[data-aipkit-settings-panel="tools"]'
    );
    if (!contextPanel) return;

    const mainProviderSelect = editorContainer.querySelector(
      "#aipkit_ai_form_ai_provider"
    );

    attachOpenaiStoreUpdateListener();

    const contextListenerAttr = "data-vector-config-listener-attached";
    if (contextPanel.getAttribute(contextListenerAttr) !== "true") {
      contextPanel.addEventListener("change", (event) => {
        const target = event.target;
        if (!target) {
          return;
        }

        if (
          target.matches(".aipkit_vector_store_enable_select, .aipkit_vector_store_provider_select")
        ) {
          updateVectorStoreVisibility(contextPanel);
          return;
        }

        if (target.matches(".aipkit_vector_embedding_select")) {
          syncEmbeddingInputsFromSelect(target, contextPanel);
          return;
        }

        if (
          target.matches(".aipkit_vector_embedding_provider_select, .aipkit_vector_embedding_model_select")
        ) {
          if (typeof window.aipkitForms_populateVectorEmbeddingModels === "function") {
            window.aipkitForms_populateVectorEmbeddingModels(contextPanel);
          }
          syncEmbeddingSelectFromInputs(contextPanel);
          return;
        }

        if (
          target.matches('select[name="openai_vector_store_ids[]"]') ||
          target.matches('select[name="google_file_search_store_names[]"]')
        ) {
          initVectorStoreMultiSelect(contextPanel);
        }
      });

      contextPanel.setAttribute(contextListenerAttr, "true");
    }

    const facadeListenerAttr = "data-context-facade-listener-attached";
    if (settingsRoot.getAttribute(facadeListenerAttr) !== "true") {
      settingsRoot.addEventListener("change", (event) => {
        const target = event.target;
        if (!target) {
          return;
        }
        if (target.matches(".aipkit_ai_form_context_enable_select")) {
          const enableToggle = contextPanel.querySelector(
            ".aipkit_vector_store_enable_select"
          );
          if (enableToggle) {
            enableToggle.checked =
              target.type === "checkbox"
                ? Boolean(target.checked)
                : target.value === "1";
            enableToggle.dispatchEvent(new Event("change", { bubbles: true }));
          }
          updateVectorStoreVisibility(contextPanel);
          notifyEditorMutation(editorContainer);
          return;
        }
        if (target.matches(".aipkit_ai_form_web_search_enable_select")) {
          syncWebSearchFromFacade(editorContainer, target);
          notifyEditorMutation(editorContainer);
          return;
        }
        if (target.matches("#aipkit_ai_form_ai_provider")) {
          updateVectorStoreVisibility(contextPanel);
        }
      });
      settingsRoot.setAttribute(facadeListenerAttr, "true");
    }

    if (toolsPanel) {
      const toolsListenerAttr = "data-tools-config-listener-attached";
      if (toolsPanel.getAttribute(toolsListenerAttr) !== "true") {
        toolsPanel.addEventListener("change", (event) => {
          const target = event.target;
          if (
            target.matches(".aipkit_ai_form_openai_web_search_toggle, .aipkit_ai_form_claude_web_search_toggle, .aipkit_ai_form_openrouter_web_search_toggle, .aipkit_ai_form_xai_web_search_toggle, .aipkit_ai_form_google_search_grounding_toggle")
          ) {
            if (
              typeof window.aipkitForms_updateWebSearchVisibility ===
              "function"
            ) {
              const providerValue = mainProviderSelect
                ? mainProviderSelect.value
                : "";
              window.aipkitForms_updateWebSearchVisibility(
                providerValue,
                editorContainer
              );
            }
          } else if (
            target.matches(".aipkit_ai_form_openai_web_search_loc_type_select")
          ) {
            aipkitForms_toggleOpenAIWebSearchLocationDetails(toolsPanel);
          } else if (
            target.matches(".aipkit_ai_form_claude_web_search_loc_type_select")
          ) {
            aipkitForms_toggleClaudeWebSearchLocationDetails(toolsPanel);
          }
        });

        toolsPanel.setAttribute(toolsListenerAttr, "true");
      }
    }

    if (
      typeof window.aipkitForms_updateWebSearchVisibility === "function" &&
      mainProviderSelect
    ) {
      window.aipkitForms_updateWebSearchVisibility(
        mainProviderSelect.value,
        editorContainer
      );
    }

    updateVectorStoreVisibility(contextPanel);
    aipkitForms_populateVectorEmbeddingModels(contextPanel);
    syncEmbeddingSelectFromInputs(contextPanel);
    initVectorStoreMultiSelect(contextPanel);
  }

  // Expose globally
  window.aipkitForms_initVectorConfig = aipkitForms_initVectorConfig;
  window.aipkitForms_populateVectorEmbeddingModels =
    aipkitForms_populateVectorEmbeddingModels;
  window.aipkitForms_toggleOpenAIWebSearchLocationDetails =
    aipkitForms_toggleOpenAIWebSearchLocationDetails;
  window.aipkitForms_toggleClaudeWebSearchLocationDetails =
    aipkitForms_toggleClaudeWebSearchLocationDetails;
  window.aipkitForms_syncEmbeddingSelectFromInputs = syncEmbeddingSelectFromInputs;
  window.aipkitForms_updateVectorStoreVisibility = updateVectorStoreVisibility;
})();
