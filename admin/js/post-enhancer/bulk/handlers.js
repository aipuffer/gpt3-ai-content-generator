/**
 * AIPKit Content Enhancer - Bulk Modal Event Handlers
 */
import { createActiveWorkCloseGuard } from "../../utils/ui/active-work-close-guard.js";

(function () {
  "use strict";

  const escaper = window.aipkit_escapeHtml || ((str) => str || "");
  const bulkPromptDraftStorageKey = "aipkit_bulk_enhancer_prompt_draft_v1";
  const bulkPromptDraftMaxAge = 24 * 60 * 60 * 1000;
  const bulkPromptFieldKeys = [
    "title",
    "excerpt",
    "content",
    "meta",
    "keyword",
    "tags",
  ];

  function collectBulkPromptDraft(modalOverlay) {
    if (!modalOverlay) {
      return null;
    }
    const prompts = {};
    const enabled = {};
    let hasContent = false;

    bulkPromptFieldKeys.forEach((key) => {
      const textarea = modalOverlay.querySelector(`#aipkit_bulk_prompt_${key}`);
      const checkbox = modalOverlay.querySelector(`#aipkit_bulk_enhance_${key}`);
      const value = textarea ? textarea.value : "";
      const defaultValue = textarea ? String(textarea.defaultValue || "") : "";
      prompts[key] = value;
      enabled[key] = checkbox ? checkbox.checked : false;
      if (!hasContent && ((textarea && value !== defaultValue) || enabled[key])) {
        hasContent = true;
      }
    });

    if (!hasContent) {
      return null;
    }

    return {
      updatedAt: Date.now(),
      templateId:
        modalOverlay.querySelector("#aipkit_enhancer_template_select")?.value ||
        "",
      prompts,
      enabled,
    };
  }

  function saveBulkPromptDraft(modalOverlay) {
    try {
      const draft = collectBulkPromptDraft(modalOverlay);
      if (!draft) {
        localStorage.removeItem(bulkPromptDraftStorageKey);
        return;
      }
      localStorage.setItem(bulkPromptDraftStorageKey, JSON.stringify(draft));
    } catch (error) {
      console.warn("Bulk Enhancer: failed to save prompt draft.", error);
    }
  }

  function loadBulkPromptDraft() {
    try {
      const raw = localStorage.getItem(bulkPromptDraftStorageKey);
      if (!raw) {
        return null;
      }
      const parsed = JSON.parse(raw);
      if (
        !parsed ||
        typeof parsed !== "object" ||
        typeof parsed.updatedAt !== "number"
      ) {
        localStorage.removeItem(bulkPromptDraftStorageKey);
        return null;
      }
      if (Date.now() - parsed.updatedAt > bulkPromptDraftMaxAge) {
        localStorage.removeItem(bulkPromptDraftStorageKey);
        return null;
      }
      return parsed;
    } catch (error) {
      console.warn("Bulk Enhancer: failed to load prompt draft.", error);
      return null;
    }
  }

  function restoreBulkPromptDraft(modalOverlay) {
    const draft = loadBulkPromptDraft();
    if (!modalOverlay || !draft) {
      return false;
    }

    const templateSelect = modalOverlay.querySelector("#aipkit_enhancer_template_select");
    const activeTemplateId = templateSelect ? String(templateSelect.value || "") : "";
    const draftTemplateId = String(draft.templateId || "");

    if (draftTemplateId && activeTemplateId && draftTemplateId !== activeTemplateId) {
      return false;
    }

    bulkPromptFieldKeys.forEach((key) => {
      const textarea = modalOverlay.querySelector(`#aipkit_bulk_prompt_${key}`);
      const checkbox = modalOverlay.querySelector(`#aipkit_bulk_enhance_${key}`);

      if (
        textarea &&
        draft.prompts &&
        Object.prototype.hasOwnProperty.call(draft.prompts, key)
      ) {
        textarea.value = String(draft.prompts[key] || "");
        textarea.dispatchEvent(new Event("input", { bubbles: true }));
      }

      if (
        checkbox &&
        draft.enabled &&
        Object.prototype.hasOwnProperty.call(draft.enabled, key)
      ) {
        checkbox.checked = !!draft.enabled[key];
        checkbox.dispatchEvent(new Event("change", { bubbles: true }));
      }
    });

    return true;
  }

  // Populate vector target dropdowns.
  function aipkit_enhancer_populateVectorTargets(provider, modal) {
    const data = window.aipkit_post_enhancer || {};
    let selectEl, options, idKey, nameKey, emptyText;

    if (provider === "local") {
      selectEl = modal.querySelector("#aipkit_bulk_local_store_id");
      options = data.local_stores || [];
      idKey = "id";
      nameKey = "name";
      emptyText = "No site knowledge bases yet.";
    } else if (provider === "openai") {
      selectEl = modal.querySelector("#aipkit_bulk_openai_vector_store_ids");
      options = data.openai_vector_stores || [];
      idKey = "id";
      nameKey = "name";
      emptyText = "No OpenAI stores found.";
    } else if (provider === "google") {
      selectEl = modal.querySelector(
        "#aipkit_bulk_google_file_search_store_names"
      );
      options = data.google_file_search_stores || [];
      idKey = "resource_name";
      nameKey = "display_name";
      emptyText = "No Google stores found.";
    } else if (provider === "pinecone") {
      selectEl = modal.querySelector("#aipkit_bulk_pinecone_index_name");
      options = data.pinecone_indexes || [];
      idKey = "name";
      nameKey = "name";
      emptyText = "No Pinecone indexes found.";
    } else if (provider === "qdrant") {
      selectEl = modal.querySelector("#aipkit_bulk_qdrant_collection_name");
      options = data.qdrant_collections || [];
      idKey = "name";
      nameKey = "name";
      emptyText = "No Qdrant collections found.";
    } else if (provider === "chroma") {
      selectEl = modal.querySelector("#aipkit_bulk_chroma_collection_name");
      options = data.chroma_collections || [];
      idKey = "name";
      nameKey = "name";
      emptyText = "No Chroma collections found.";
    }

    if (!selectEl) return;
    selectEl.innerHTML = ""; // Clear existing
    selectEl.disabled = false;

    if (options.length > 0) {
      if (selectEl.multiple) {
        // For hosted multi-select providers.
        options.forEach((opt) => {
          const optionValue = opt[idKey] || opt.id || opt.name || "";
          const optionLabel = opt[nameKey] || opt.name || optionValue;
          if (optionValue) {
            selectEl.add(new Option(escaper(optionLabel), optionValue));
          }
        });
      } else {
        // For Pinecone/Qdrant/Chroma single-select
        selectEl.add(new Option("-- Select --", ""));
        options.forEach((opt) => {
          selectEl.add(new Option(escaper(opt[nameKey]), opt[idKey]));
        });
      }
    } else {
      selectEl.add(new Option(emptyText, ""));
      selectEl.disabled = true;
    }

    // Initialize multiselect UI for hosted knowledge providers.
    if (provider === "openai" || provider === "google") {
      window.aipkit_initContentWriterVectorStoreMultiSelect?.(modal);
    }
  }

  function aipkit_enhancer_syncEmbeddingInputs(modal) {
    const embeddingSelect = modal.querySelector(
      "#aipkit_bulk_vector_embedding_selection"
    );
    const providerInput = modal.querySelector(
      "#aipkit_bulk_vector_embedding_provider"
    );
    const modelInput = modal.querySelector(
      "#aipkit_bulk_vector_embedding_model"
    );
    if (!embeddingSelect || !providerInput || !modelInput) return;

    const selectedOption =
      embeddingSelect.options[embeddingSelect.selectedIndex] || null;
    const rawValue = String(embeddingSelect.value || "");
    if (!rawValue) {
      modelInput.value = "";
      return;
    }
    const separatorIndex = rawValue.indexOf("::");
    const valueProvider =
      separatorIndex >= 0 ? rawValue.slice(0, separatorIndex) : "";
    const valueModel =
      separatorIndex >= 0 ? rawValue.slice(separatorIndex + 2) : "";

    providerInput.value =
      selectedOption?.dataset.provider || valueProvider || "openai";
    modelInput.value = selectedOption?.dataset.model || valueModel || "";
  }

  // Populate one grouped provider-and-model embedding dropdown.
  function aipkit_enhancer_populateEmbeddingModels(modal) {
    const embeddingSelect = modal.querySelector(
      "#aipkit_bulk_vector_embedding_selection"
    );
    const providerInput = modal.querySelector(
      "#aipkit_bulk_vector_embedding_provider"
    );
    const modelInput = modal.querySelector(
      "#aipkit_bulk_vector_embedding_model"
    );
    if (!embeddingSelect || !providerInput || !modelInput) return;

    const data = window.aipkit_post_enhancer || {};
    const embeddingUtils = window.aipkit_embedding_utils || {};
    const populateModelSelect =
      typeof embeddingUtils.populateModelSelect === "function"
        ? embeddingUtils.populateModelSelect
        : null;
    const resolveProviderEntries =
      typeof embeddingUtils.resolveProviderEntries === "function"
        ? embeddingUtils.resolveProviderEntries
        : null;
    const modelsByProvider =
      data.embeddingModelsByProvider ||
      data.embedding_models_by_provider ||
      {};
    const providerEntries = resolveProviderEntries
      ? resolveProviderEntries(
          data.embeddingProviderMap || data.embedding_provider_map || {},
          modelsByProvider,
          embeddingUtils.defaultProviderMap || undefined
        )
      : Object.entries(
          embeddingUtils.defaultProviderMap || {
            openai: "OpenAI",
            google: "Google",
            azure: "Azure",
            openrouter: "OpenRouter",
          }
        );

    if (typeof populateModelSelect !== "function") {
      embeddingSelect.innerHTML = "";
      embeddingSelect.add(new Option("No embedding models found", ""));
      embeddingSelect.disabled = true;
      return;
    }

    const selectedProvider = String(providerInput.value || "openai");
    const selectedModel = String(modelInput.value || "");
    const selectedValue = selectedModel
      ? `${selectedProvider}::${selectedModel}`
      : "";

    populateModelSelect(embeddingSelect, {
      grouped: true,
      providerEntries,
      modelsByProvider,
      includePlaceholder: true,
      placeholderText: "Select embedding model",
      emptyText: "No embedding models found",
      selectedValue,
      preserveUnknownSelected: true,
      unknownOptionLabel: selectedModel || selectedValue,
      unknownProviderKey: selectedProvider,
      autoSelectFirst: false,
      escaper,
      valueFormatter: (providerKey, model) =>
        `${providerKey}::${model.id}`,
    });

    aipkit_enhancer_syncEmbeddingInputs(modal);
  }

  function aipkit_enhancer_setEmbeddingSelection(modal, provider, model) {
    if (!modal) return;
    const providerInput = modal.querySelector(
      "#aipkit_bulk_vector_embedding_provider"
    );
    const modelInput = modal.querySelector(
      "#aipkit_bulk_vector_embedding_model"
    );
    if (!providerInput || !modelInput) return;

    providerInput.value = provider || "openai";
    modelInput.value = model || "";
    aipkit_enhancer_populateEmbeddingModels(modal);
  }

  window.aipkit_enhancer_setEmbeddingSelection =
    aipkit_enhancer_setEmbeddingSelection;

  // Handle provider switching in vector settings.
  function aipkit_enhancer_handleProviderChange(modal) {
    const providerSelect = modal.querySelector(
      "#aipkit_bulk_vector_store_provider"
    );
    const provider = providerSelect ? providerSelect.value : "off";

    const openaiFields = modal.querySelector(".aipkit_cw_vector_openai_field");
    const googleFields = modal.querySelector(".aipkit_cw_vector_google_field");
    const pineconeFields = modal.querySelector(
      ".aipkit_cw_vector_pinecone_field"
    );
    const qdrantFields = modal.querySelector(".aipkit_cw_vector_qdrant_field");
    const chromaFields = modal.querySelector(".aipkit_cw_vector_chroma_field");
    const localFields = modal.querySelector(".aipkit_cw_vector_local_field");
    const embeddingConfig = modal.querySelector(
      ".aipkit_cw_vector_embedding_config_row"
    );

    // Hide all
    if (openaiFields) openaiFields.hidden = true;
    if (googleFields) googleFields.hidden = true;
    if (pineconeFields) pineconeFields.hidden = true;
    if (qdrantFields) qdrantFields.hidden = true;
    if (chromaFields) chromaFields.hidden = true;
    if (localFields) localFields.hidden = true;
    if (embeddingConfig) embeddingConfig.hidden = true;

    if (!["local", "openai", "google", "pinecone", "qdrant", "chroma"].includes(provider)) {
      return;
    }

    if (typeof window.aipkit_refreshVectorStoreProviderList === "function") {
      window.aipkit_refreshVectorStoreProviderList(provider);
    }

    // Show relevant ones
    if (provider === "local") {
      if (embeddingConfig) embeddingConfig.hidden = false;
      if (localFields) localFields.hidden = false;
      aipkit_enhancer_populateVectorTargets("local", modal);
      aipkit_enhancer_populateEmbeddingModels(modal);
    } else if (provider === "openai") {
      if (openaiFields) openaiFields.hidden = false;
      aipkit_enhancer_populateVectorTargets("openai", modal);
    } else if (provider === "google") {
      if (googleFields) googleFields.hidden = false;
      aipkit_enhancer_populateVectorTargets("google", modal);
    } else if (provider === "pinecone") {
      if (pineconeFields) pineconeFields.hidden = false;
      if (embeddingConfig) embeddingConfig.hidden = false;
      aipkit_enhancer_populateVectorTargets("pinecone", modal);
      aipkit_enhancer_populateEmbeddingModels(modal);
    } else if (provider === "qdrant") {
      if (qdrantFields) qdrantFields.hidden = false;
      if (embeddingConfig) embeddingConfig.hidden = false;
      aipkit_enhancer_populateVectorTargets("qdrant", modal);
      aipkit_enhancer_populateEmbeddingModels(modal);
    } else if (provider === "chroma") {
      if (chromaFields) chromaFields.hidden = false;
      if (embeddingConfig) embeddingConfig.hidden = false;
      aipkit_enhancer_populateVectorTargets("chroma", modal);
      aipkit_enhancer_populateEmbeddingModels(modal);
    }
  }

  window.addEventListener("aipkit:vector-store-list-updated", (event) => {
    const provider = String(event?.detail?.provider || "").toLowerCase();
    if (!["local", "openai", "google", "pinecone", "qdrant", "chroma"].includes(provider)) {
      return;
    }
    const configKeyMap = {
      local: "local_stores",
      openai: "openai_vector_stores",
      google: "google_file_search_stores",
      pinecone: "pinecone_indexes",
      qdrant: "qdrant_collections",
      chroma: "chroma_collections",
    };
    const data = window.aipkit_post_enhancer || {};
    data[configKeyMap[provider]] = Array.isArray(event.detail?.stores)
      ? event.detail.stores
      : [];
    window.aipkit_post_enhancer = data;

    const modal = document.getElementById("aipkit_enhancer_bulk_modal");
    const providerSelect = modal
      ? modal.querySelector("#aipkit_bulk_vector_store_provider")
      : null;
    if (
      modal &&
      providerSelect &&
      String(providerSelect.value || "").toLowerCase() === provider
    ) {
      aipkit_enhancer_populateVectorTargets(provider, modal);
    }
  });

  // Initialize vector controls.
  function aipkit_enhancer_initVectorControls(modal) {
    const enableCheckbox = modal.querySelector(
      'input[name="enable_vector_store"]'
    );
    const settingsContainer = modal.querySelector(
      ".aipkit_cw_vector_store_settings_container"
    );
    const providerSelect = modal.querySelector(
      "#aipkit_bulk_vector_store_provider"
    );
    const providerControl = modal.querySelector(
      ".aipkit_enhancer_knowledge_provider_control"
    );
    const embeddingSelect = modal.querySelector(
      "#aipkit_bulk_vector_embedding_selection"
    );
    const topKInput = modal.querySelector("#aipkit_bulk_vector_store_top_k");

    if (!enableCheckbox || !settingsContainer || !providerSelect) return;

    const activeProviders = new Set([
      "local",
      "openai",
      "google",
      "pinecone",
      "qdrant",
      "chroma",
    ]);
    const aiProviderSelect = modal.querySelector("#aipkit_bulk_ai_provider");
    const googleOption = providerSelect.querySelector('option[value="google"]');
    const featureDefaults = window.aipkit_getNewFeatureDefaults?.() || {};
    let lastKnowledgeProvider = featureDefaults.vector_store_provider || "local";
    aipkit_enhancer_setEmbeddingSelection(
      modal,
      featureDefaults.vector_embedding_provider,
      featureDefaults.vector_embedding_model
    );
    const getKnowledgeProvider = () => activeProviders.has(lastKnowledgeProvider)
      && !(lastKnowledgeProvider === "google" && googleOption?.disabled)
      ? lastKnowledgeProvider : "local";
    const syncGoogleAvailability = () => {
      const googleIsActive =
        String(aiProviderSelect?.value || "").toLowerCase() === "google";
      if (googleOption) {
        googleOption.disabled = !googleIsActive;
        googleOption.title = googleIsActive
          ? ""
          : "Google File Search requires Google as the AI provider.";
      }
      if (!googleIsActive && providerSelect.value === "google") {
        providerSelect.value = "off";
      }
    };
    const syncVectorMode = (source = "provider") => {
      syncGoogleAvailability();
      if (source === "checkbox") {
        if (!enableCheckbox.checked) {
          if (activeProviders.has(providerSelect.value)) lastKnowledgeProvider = providerSelect.value;
          providerSelect.value = "off";
        } else if (!activeProviders.has(providerSelect.value)) {
          providerSelect.value = getKnowledgeProvider();
        }
      }

      const isEnabled = activeProviders.has(providerSelect.value);
      if (isEnabled) lastKnowledgeProvider = providerSelect.value;
      enableCheckbox.checked = isEnabled;
      if (providerControl) {
        providerControl.hidden = !isEnabled;
      }
      settingsContainer.hidden = !isEnabled;
      aipkit_enhancer_handleProviderChange(modal);
    };

    enableCheckbox.addEventListener("change", () => syncVectorMode("checkbox"));

    // Handle provider switching
    providerSelect.addEventListener("change", () => syncVectorMode("provider"));
    aiProviderSelect?.addEventListener("change", () => syncVectorMode("provider"));

    if (embeddingSelect) {
      embeddingSelect.addEventListener("change", () =>
        aipkit_enhancer_syncEmbeddingInputs(modal)
      );
    }

    if (topKInput) {
      const clampTopK = () => {
        const min = parseInt(topKInput.min || "1", 10);
        const max = parseInt(topKInput.max || "20", 10);
        const value = parseInt(topKInput.value || "", 10);
        if (!Number.isFinite(value)) {
          topKInput.value = String(min);
          return;
        }
        if (value < min) {
          topKInput.value = String(min);
        } else if (value > max) {
          topKInput.value = String(max);
        }
      };
      topKInput.addEventListener("input", clampTopK);
      topKInput.addEventListener("change", clampTopK);
      clampTopK();
    }

    // Initial setup
    if (enableCheckbox.checked && !activeProviders.has(providerSelect.value)) {
      providerSelect.value = getKnowledgeProvider();
    } else if (!enableCheckbox.checked) {
      providerSelect.value = "off";
    }
    syncVectorMode("provider");
  }

  /**
   * Attaches all necessary event listeners to a newly created bulk modal.
   * @param {HTMLElement} modalOverlay The root overlay element of the modal.
   * @param {string[]} postIds Array of post IDs to process.
   */
  function aipkit_enhancer_attachBulkModalListeners(modalOverlay, postIds) {
    if (!modalOverlay) return;
    let promptDraftTimer = null;

    const configPanel = modalOverlay.querySelector(
      "#aipkit-enhancer-bulk-config"
    );
    const progressPanel = modalOverlay.querySelector(
      "#aipkit-enhancer-bulk-progress"
    );
    const startButton = modalOverlay.querySelector(
      "#aipkit_bulk_enhancer_start_btn"
    );
    const stopButton = modalOverlay.querySelector(
      "#aipkit_bulk_enhancer_stop_btn"
    );
    const backButton = modalOverlay.querySelector(
      "#aipkit_bulk_enhancer_back_to_settings_btn"
    );

    if (configPanel) configPanel.hidden = false;
    if (progressPanel) progressPanel.hidden = true;
    if (startButton) {
      startButton.disabled = false;
      const startLabel = startButton.querySelector(".aipkit_btn-text");
      if (startLabel) startLabel.textContent = "Start";
    }
    if (stopButton) stopButton.hidden = true;
    if (backButton) backButton.hidden = true;

    const texts = window.aipkit_post_enhancer?.text || {};
    const closeModal = createActiveWorkCloseGuard({
      modal: modalOverlay,
      isBusy: () => {
        const state = window.aipkit_enhancer_bulkState;
        return state?.modal === modalOverlay && (state.isRunning || state.isProcessing);
      },
      getMessage: () => texts.close_generation_active || "Content generation is still in progress. Closing will stop the remaining work. The current request may still finish and save its changes.",
      title: texts.close_generation_title || "Close while generating?",
      confirmText: texts.close_window || "Close window",
      cancelText: texts.keep_open || "Keep open",
      onClose: () => {
        if (typeof window.aipkit_enhancer_stopBulkProcess === "function") {
          window.aipkit_enhancer_stopBulkProcess();
        }
        clearTimeout(promptDraftTimer);
        saveBulkPromptDraft(modalOverlay);
        modalOverlay.classList.remove("aipkit-active");
        modalOverlay.addEventListener(
          "transitionend",
          () => modalOverlay.remove(),
          { once: true }
        );
        setTimeout(() => modalOverlay?.remove(), 500);
      },
    });

    // Main Modal Action Listeners
    modalOverlay
      .querySelector(".aipkit-modal-close-btn")
      .addEventListener("click", closeModal);

    if (
      typeof window.aipkit_enhancer_initUnifiedModelSelector === "function"
    ) {
      window.aipkit_enhancer_initUnifiedModelSelector(modalOverlay);
    }

    if (typeof window.aipkit_enhancer_initTemplateControls === "function") {
      window.aipkit_enhancer_initTemplateControls(modalOverlay);
    }

    if (typeof window.aipkit_enhancer_initBulkInlineControls === "function") {
      window.aipkit_enhancer_initBulkInlineControls(modalOverlay);
    }

    const draftFieldSelectors = [
      ...bulkPromptFieldKeys.map((key) => `#aipkit_bulk_prompt_${key}`),
      ...bulkPromptFieldKeys.map((key) => `#aipkit_bulk_enhance_${key}`),
    ];
    const shouldTrackPromptDraft = (target) => {
      if (!target || !target.matches) {
        return false;
      }
      return draftFieldSelectors.some((selector) => target.matches(selector));
    };

    const schedulePromptDraftSave = () => {
      if (promptDraftTimer) {
        clearTimeout(promptDraftTimer);
      }
      promptDraftTimer = setTimeout(() => {
        promptDraftTimer = null;
        saveBulkPromptDraft(modalOverlay);
      }, 250);
    };

    modalOverlay.addEventListener("input", (event) => {
      if (!shouldTrackPromptDraft(event.target)) {
        return;
      }
      schedulePromptDraftSave();
    });
    modalOverlay.addEventListener("change", (event) => {
      if (!shouldTrackPromptDraft(event.target)) {
        return;
      }
      schedulePromptDraftSave();
    });

    setTimeout(() => {
      restoreBulkPromptDraft(modalOverlay);
    }, 450);

    // Start button listener
    modalOverlay
      .querySelector("#aipkit_bulk_enhancer_start_btn")
      .addEventListener("click", () => {
        if (typeof window.aipkit_enhancer_startBulkProcess === "function") {
          window.aipkit_enhancer_startBulkProcess(postIds);
        }
      });

    // Stop button listener
    modalOverlay
      .querySelector("#aipkit_bulk_enhancer_stop_btn")
      .addEventListener("click", () => {
        if (typeof window.aipkit_enhancer_stopBulkProcess === "function") {
          window.aipkit_enhancer_stopBulkProcess();
        }
      });

    // Back to Settings button listener
    modalOverlay
      .querySelector("#aipkit_bulk_enhancer_back_to_settings_btn")
      .addEventListener("click", () => {
        if (typeof window.aipkit_enhancer_returnToSettings === "function") {
          window.aipkit_enhancer_returnToSettings();
        }
      });

    // --- Initialize Vector Controls ---
    aipkit_enhancer_initVectorControls(modalOverlay);
  }

  window.aipkit_enhancer_attachBulkModalListeners =
    aipkit_enhancer_attachBulkModalListeners;
})();
