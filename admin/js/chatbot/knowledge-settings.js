import { bindKnowledgePicker, splitStoreLabel } from "../shared/knowledge-picker.js";
import { createChatbotProviderConfig } from "./providers.js";
import { deriveChatbotCapabilityState } from "./knowledge.js";
import { createChatbotUploadAvailability } from "./tools.js";
import { bindChatbotSettingsAutosave, createChatbotStateHydration } from "./state.js";
import { getConfiguredVectorStoreValue, getConfiguredVectorStoreValues } from "../utils/vector-store-selection-state.js";
import { isKnowledgeProviderCompatible, getKnowledgeProviderCompatibilityMessage } from "./knowledge.js";

/** Chatbot knowledge-base visibility and embedding configuration. */
(function () {
  "use strict";
  const providerFieldNames = [
    "openai", "google", "pinecone", "pinecone_key", "qdrant",
    "qdrant_url", "qdrant_key", "chroma", "local",
  ];

  const getEnableToggle = (settingsArea) =>
    settingsArea.querySelector(".aipkit_vector_store_toggle_switch") ||
    settingsArea.querySelector(".aipkit_vector_store_enable_select");

  const isKnowledgeEnabled = (toggle) =>
    toggle.tagName === "SELECT" ? toggle.value === "1" : toggle.checked;

  const setVisible = (field, visible) => {
    if (field) field.style.display = visible ? "block" : "none";
  };

  function updateRetrievalFields(advancedField, topKField, confidenceField, enabled, hideConfidence = false) {
    setVisible(advancedField, enabled);
    if (advancedField) {
      if (topKField) topKField.style.display = "";
      if (confidenceField) confidenceField.style.display = hideConfidence ? "none" : "";
    } else {
      setVisible(topKField, enabled);
      setVisible(confidenceField, enabled && !hideConfidence);
    }
  }

  function updateContextProvider(contextGrid, contextLayout, provider) {
    [contextGrid, contextLayout].forEach((element) => {
      if (element) element.setAttribute("data-vector-provider", provider);
    });
  }

  function aipkit_toggleVectorStoreSettingsVisibility(settingsArea) {
    if (!settingsArea) return;
    const enableToggle = getEnableToggle(settingsArea);
    const conditionalRow = settingsArea.querySelector(
      ".aipkit_vector_store_settings_conditional_row"
    );
    const contextGrid = settingsArea.querySelector(".aipkit_context_grid");
    const contextLayout = settingsArea.querySelector(".aipkit_context_layout");
    const advancedRetrievalField = settingsArea.querySelector(
      ".aipkit_vector_store_advanced_field"
    );
    const topKField = settingsArea.querySelector(
      ".aipkit_vector_store_top_k_field"
    );
    const confidenceField = settingsArea.querySelector(
      ".aipkit_vector_store_confidence_field"
    );
    if (!enableToggle || !conditionalRow) return;
    const isEnabled = isKnowledgeEnabled(enableToggle);
    setVisible(conditionalRow, isEnabled);
    updateRetrievalFields(advancedRetrievalField, topKField, confidenceField, isEnabled);
    if (isEnabled) {
        if(typeof window.aipkit_toggleVectorStoreProviderFields === 'function') window.aipkit_toggleVectorStoreProviderFields(settingsArea);
    } else {
      settingsArea.querySelectorAll('.aipkit_vector_store_openai_field, .aipkit_vector_store_pinecone_field, .aipkit_vector_store_pinecone_key_field, .aipkit_vector_store_qdrant_field, .aipkit_vector_store_qdrant_url_field, .aipkit_vector_store_qdrant_key_field, .aipkit_vector_store_chroma_field, .aipkit_vector_store_local_field, .aipkit_vector_store_embedding_config_row')
        .forEach(el => el.style.display = 'none');
      updateContextProvider(contextGrid, contextLayout, "");
    }
  }
  window.aipkit_toggleVectorStoreSettingsVisibility = aipkit_toggleVectorStoreSettingsVisibility;

  function aipkit_toggleVectorStoreProviderFields(settingsArea) {
    if (!settingsArea) return;
    const providerSelect = settingsArea.querySelector('.aipkit_vector_store_provider_select');
    const enableToggle = getEnableToggle(settingsArea);
    if (!providerSelect || !enableToggle) return;
    const builder = settingsArea.closest('.aipkit_chatbot_builder') || document.querySelector('.aipkit_chatbot_builder');
    const chatbotProviderSelect =
      settingsArea.querySelector('.aipkit_chatbot_provider_select') ||
      (builder ? builder.querySelector('.aipkit_chatbot_provider_select') : null);
    const chatbotProvider = chatbotProviderSelect ? String(chatbotProviderSelect.value || '').trim() : '';
    ["claude_files", "google"].forEach((provider) => {
      const option = Array.from(providerSelect.options || []).find(
        (option) => option && option.value === provider
      );
      if (!option) return;
      const previousDisabledState = Boolean(option.disabled);
      option.disabled = !isKnowledgeProviderCompatible(chatbotProvider, provider);
      if (
        previousDisabledState !== option.disabled &&
        typeof window.aipkit_refreshChatSelectPickers === "function"
      ) {
        window.aipkit_refreshChatSelectPickers();
      }
    });

    const selectedProvider = providerSelect.value;
    const isVectorStoreEnabled = isKnowledgeEnabled(enableToggle);
    const contextGrid = settingsArea.querySelector('.aipkit_context_grid');
    const contextLayout = settingsArea.querySelector('.aipkit_context_layout');
    const providerFields = providerFieldNames.map((name) =>
      settingsArea.querySelector(`.aipkit_vector_store_${name}_field`)
    );
    const embeddingConfigRow = settingsArea.querySelector('.aipkit_vector_store_embedding_config_row');
    const advancedRetrievalField = settingsArea.querySelector('.aipkit_vector_store_advanced_field');
    const topKField = settingsArea.querySelector('.aipkit_vector_store_top_k_field');
    const confidenceField = settingsArea.querySelector('.aipkit_vector_store_confidence_field');
    const hasRetrievalControls = ['openai', 'google', 'pinecone', 'qdrant', 'chroma', 'local'].includes(selectedProvider);

    const needsEmbedding = ['pinecone', 'qdrant', 'chroma', 'local'].includes(selectedProvider);
    providerFieldNames.forEach((name, index) => {
      setVisible(providerFields[index], isVectorStoreEnabled && selectedProvider === name.split("_")[0]);
    });
    setVisible(embeddingConfigRow, isVectorStoreEnabled && needsEmbedding);
    updateRetrievalFields(
      advancedRetrievalField, topKField, confidenceField,
      isVectorStoreEnabled && hasRetrievalControls, selectedProvider === "google"
    );
    updateContextProvider(contextGrid, contextLayout, isVectorStoreEnabled ? selectedProvider : "");

    if (isVectorStoreEnabled && selectedProvider === 'openai') {
        if (typeof window.aipkit_populateOpenAIVectorStoresMultiSelect === 'function') {
            window.aipkit_populateOpenAIVectorStoresMultiSelect(settingsArea);
        }
    } else if (isVectorStoreEnabled && selectedProvider === 'local') {
        window.aipkit_populateVectorEmbeddingProviderSelect?.(settingsArea);
        window.aipkit_populateVectorEmbeddingModelSelect?.(settingsArea);
        if (typeof window.aipkit_populateLocalStoresMultiSelect === 'function') {
            window.aipkit_populateLocalStoresMultiSelect(settingsArea);
        }
    } else if (isVectorStoreEnabled && selectedProvider === 'google') {
        if (typeof window.aipkit_populateGoogleFileSearchStoresMultiSelect === 'function') {
            window.aipkit_populateGoogleFileSearchStoresMultiSelect(settingsArea);
        }
    } else if (isVectorStoreEnabled && needsEmbedding) {
        if (selectedProvider === 'pinecone' && typeof window.aipkit_populatePineconeIndexSelect === 'function') {
            window.aipkit_populatePineconeIndexSelect(settingsArea);
        }
        if (selectedProvider === 'qdrant' && typeof window.aipkit_populateQdrantCollectionsMultiSelect === 'function') {
            window.aipkit_populateQdrantCollectionsMultiSelect(settingsArea);
        }
        if (selectedProvider === 'chroma' && typeof window.aipkit_populateChromaCollectionsMultiSelect === 'function') {
            window.aipkit_populateChromaCollectionsMultiSelect(settingsArea);
        }
        if (typeof window.aipkit_populateVectorEmbeddingProviderSelect === 'function') {
            window.aipkit_populateVectorEmbeddingProviderSelect(settingsArea);
        }
        if (typeof window.aipkit_populateVectorEmbeddingModelSelect === 'function') {
            window.aipkit_populateVectorEmbeddingModelSelect(settingsArea);
        }
    }
    if (
        isVectorStoreEnabled &&
        hasRetrievalControls &&
        typeof window.aipkit_refreshVectorStoreProviderList === 'function'
    ) {
        window.aipkit_refreshVectorStoreProviderList(selectedProvider);
    }
  }
  window.aipkit_toggleVectorStoreProviderFields = aipkit_toggleVectorStoreProviderFields;

  function aipkit_populateVectorEmbeddingProviderSelect(settingsArea) {
      const selectElement = settingsArea.querySelector('select[name="vector_embedding_provider"]');
      if (!selectElement) return;
      selectElement.dispatchEvent(new Event('change'));
  }
  window.aipkit_populateVectorEmbeddingProviderSelect = aipkit_populateVectorEmbeddingProviderSelect;

  function aipkit_populateVectorEmbeddingModelSelect(settingsArea) {
      const providerSelect = settingsArea.querySelector('select[name="vector_embedding_provider"]');
      const modelSelect = settingsArea.querySelector('select[name="vector_embedding_model"]');
      const escaper = window.aipkit_escapeHtml || function(str) { return str; };

      if (!providerSelect || !modelSelect) return;
      const selectedProvider = providerSelect.value;
      const currentSavedModel = modelSelect.value;
      const config = window.aipkit_chat_config || {};
      const embeddingUtils = window.aipkit_embedding_utils || {};
      const populateModelSelect =
          typeof embeddingUtils.populateModelSelect === 'function'
              ? embeddingUtils.populateModelSelect
              : null;

      if (typeof populateModelSelect !== 'function') {
          modelSelect.innerHTML = '<option value="" disabled>-- Select Provider or Sync Models --</option>';
          modelSelect.disabled = true;
          return;
      }

      populateModelSelect(modelSelect, {
          grouped: false,
          providerKey: selectedProvider,
          modelsByProvider: config.embedding_models_by_provider,
          includePlaceholder: true,
          placeholderText: '-- Select Model --',
          emptyText: '-- Select Provider or Sync Models --',
          selectedValue: currentSavedModel,
          preserveUnknownSelected: true,
          autoSelectFirst: false,
          escaper,
          valueFormatter: (_, model) => model.id,
      });
  }
  window.aipkit_populateVectorEmbeddingModelSelect = aipkit_populateVectorEmbeddingModelSelect;
})();

const VECTOR_STORE_SYNC_TARGETS = new Map([
  ["pinecone", { sync: "PineconeIndexes", select: "pineconeIndexSelect" }],
  ["qdrant", { sync: "QdrantCollections", select: "qdrantCollectionsSelect" }],
  ["chroma", { sync: "ChromaCollections", select: "chromaCollectionsSelect" }],
]);

/** Builder Knowledge field collection, inventory sync and save-before-training barrier. */
export function bindChatbotContextSettings({
  persistence,
  builder,
  modelPopoverPanel,
  getContextSettingsContainer,
  getVectorProviderState,
  getVectorStoreElements,
  refreshVectorProviderNotice,
  showVectorProviderSyncErrorNotice,
  syncEmbeddingInputsFromSelect,
  getChatbotCapabilityState,
  setToggleFieldValue,
  updateContextPopoverControls,
  setVectorProviderNoticeMessage,
  setSaveErrorStatus,
  updateVectorStoreVisibility,
  updateFileUploadAvailability,
  syncEmbeddingSelectFromInputs,
  setGoogleSearchEnabledWithoutChangeEvent,
  saveWebSettingsAfterKnowledgeChange,
  updateTrainingSourcesCount,
  syncWebsiteTargetSelect,
  setCapabilitySave,
  __,
}) {
  let saveAndWait, saveCurrentSettings, editable, bindingSignal;
  let syncStatusToken = 0, clearStatusTimer = null, hydrated = false;
  const hydration = createChatbotStateHydration({ syncUnifiedModelSelector: () => {} });
  const findContextField = (selector) => {
    const container = getContextSettingsContainer();
    return container ? container.querySelector(selector) : null;
  };

  const getContextSettings = () => {
    const readValue = (selector) => {
      const field = findContextField(selector);
      return field ? field.value : "";
    };
    const readSelectValue = (selector) => {
      const field = findContextField(selector);
      if (!field) {
        return "";
      }
      return getConfiguredVectorStoreValue(field);
    };
    const readToggle = (selector) => {
      const field =
        (modelPopoverPanel && modelPopoverPanel.querySelector(selector)) ||
        (builder && builder.querySelector(selector));
      if (!field) {
        return "0";
      }
      if (field.tagName === "SELECT") {
        return field.value === "1" ? "1" : "0";
      }
      return field.checked ? "1" : "0";
    };
    const readMultiSelect = (selector) => {
      const field = findContextField(selector);
      if (!field) {
        return [];
      }
      return getConfiguredVectorStoreValues(field);
    };

    return {
      enable_vector_store: readToggle(".aipkit_vector_store_enable_select"),
      vector_store_provider: readValue('[name="vector_store_provider"]'),
      openai_vector_store_ids: readMultiSelect(
        'select[name="openai_vector_store_ids[]"]'
      ),
      google_file_search_store_names: readMultiSelect(
        'select[name="google_file_search_store_names[]"]'
      ),
      pinecone_index_name: readSelectValue('[name="pinecone_index_name"]'),
      qdrant_collection_names: readMultiSelect(
        'select[name="qdrant_collection_names[]"]'
      ),
      chroma_collection_names: readMultiSelect(
        'select[name="chroma_collection_names[]"]'
      ),
      local_store_ids: readMultiSelect('select[name="local_store_ids[]"]'),
      vector_embedding_provider: readValue(
        '[name="vector_embedding_provider"]'
      ),
      vector_embedding_model: readValue('[name="vector_embedding_model"]'),
      vector_store_top_k: readValue('[name="vector_store_top_k"]'),
      vector_store_confidence_threshold: readValue(
        '[name="vector_store_confidence_threshold"]'
      ),
      content_aware_enabled: readToggle(
        ".aipkit_content_aware_enable_select"
      ),
    };
  };

  const contextHeaderStatus = (() => {
    const headerStatus =
      (modelPopoverPanel &&
        modelPopoverPanel
          .closest(".aipkit_model_settings_popover")
          ?.querySelector(
            ".aipkit_model_settings_popover_header .aipkit_popover_status_inline"
          )) ||
      (modelPopoverPanel &&
        modelPopoverPanel.querySelector(".aipkit_popover_status_inline"));
    return headerStatus || null;
  })();
  const setContextHeaderStatus = (text, type) => {
    if (!contextHeaderStatus) {
      return;
    }
    contextHeaderStatus.textContent = text || "";
    contextHeaderStatus.classList.remove("success", "error", "loading");
    if (type) {
      contextHeaderStatus.classList.add(type);
    }
    contextHeaderStatus.style.display = text ? "inline-block" : "none";
  };
  const clearContextHeaderStatusSoon = () => {
    if (!contextHeaderStatus) {
      return;
    }
    clearTimeout(clearStatusTimer);
    const token = syncStatusToken;
    clearStatusTimer = setTimeout(() => {
      clearStatusTimer = null;
      if (!bindingSignal.aborted && syncStatusToken === token) setContextHeaderStatus("", "");
    }, 2500);
  };
  const getVectorProviderAvailability = () => {
    const vectorProviderState = getVectorProviderState();
    return {
      pinecone: vectorProviderState.pineconeConfigured,
      qdrant: vectorProviderState.qdrantConfigured,
      chroma: vectorProviderState.chromaConfigured,
    };
  };
  const invalidateSyncStatus = () => {
    syncStatusToken += 1;
    clearTimeout(clearStatusTimer);
    clearStatusTimer = null;
    setContextHeaderStatus("", "");
  };
  const queueVectorStoreSync = (providerKey, targetSelectId) => {
    if (typeof window.aipkit_queueProviderSync === "function") {
      return window.aipkit_queueProviderSync(providerKey, {
        silent: true,
        showErrors: false,
        targetSelectId,
        propagateError: true,
      });
    }
    if (typeof window.aipkit_syncModels === "function") {
      return window.aipkit_syncModels(null, providerKey, {
        silent: true,
        showErrors: false,
        targetSelectId,
        propagateError: true,
      });
    }
    return null;
  };
  const runVectorStoreSync = (providerKey, targetSelectId, noticeProviderKey) => {
    const token = ++syncStatusToken;
    setContextHeaderStatus("Syncing...", "loading");
    refreshVectorProviderNotice();
    const syncPromise = queueVectorStoreSync(providerKey, targetSelectId);
    if (syncPromise && typeof syncPromise.then === "function") {
      syncPromise
        .then(() => {
          if (bindingSignal.aborted || !builder.isConnected || syncStatusToken !== token) {
            return;
          }
          setContextHeaderStatus("Synced", "success");
          clearContextHeaderStatusSoon();
          refreshVectorProviderNotice();
        })
        .catch((error) => {
          if (bindingSignal.aborted || !builder.isConnected || syncStatusToken !== token) {
            return;
          }
          const errorMessage = error?.message || "Sync failed.";
          setContextHeaderStatus(
            `Error: ${errorMessage}`,
            "error"
          );
          showVectorProviderSyncErrorNotice(errorMessage, noticeProviderKey);
        });
      return;
    }
    refreshVectorProviderNotice();
    clearContextHeaderStatusSoon();
  };
  const syncVectorStoreLists = (provider) => {
    invalidateSyncStatus();
    const availability = getVectorProviderAvailability();
    const target = VECTOR_STORE_SYNC_TARGETS.get(provider);
    if (target && availability[provider]) {
      const select = getVectorStoreElements()[target.select];
      runVectorStoreSync(target.sync, select ? select.id : "", provider);
    } else {
      refreshVectorProviderNotice();
    }
  };

  const saveContextSettingsBeforeKnowledgeUpdate = async (settings, assertCurrent = () => {}) => {
    assertCurrent();
    try {
      return await saveAndWait(settings, assertCurrent);
    } catch (error) {
      assertCurrent();
      const saveError = new Error(
        error?.message || __("Could not save knowledge settings. Please try again.", "gpt3-ai-content-generator")
      );
      saveError.code = "aipkit_knowledge_settings_save_failed";
      throw saveError;
    }
  };

  const handleContextSettingsChange = (event) => {
    const target = event.target;
    if (!editable() || !target) {
      return;
    }

    if (
      target.matches(".aipkit_vector_store_enable_select") ||
      target.matches(".aipkit_vector_store_provider_select") ||
      target.matches('select[name="openai_vector_store_ids[]"]') ||
      target.matches('select[name="google_file_search_store_names[]"]') ||
      target.matches('select[name="qdrant_collection_names[]"]') ||
      target.matches('select[name="chroma_collection_names[]"]') ||
      target.matches('select[name="local_store_ids[]"]') ||
      target.matches('[name="pinecone_index_name"]') ||
      target.matches(".aipkit_vector_embedding_select") ||
      target.matches('[name="vector_embedding_provider"]') ||
      target.matches('[name="vector_embedding_model"]') ||
      target.matches('[name="vector_store_top_k"]') ||
      target.matches('[name="vector_store_confidence_threshold"]') ||
      target.matches('[name="content_aware_enabled"]')
    ) {
      if (target.matches(".aipkit_vector_embedding_select")) {
        syncEmbeddingInputsFromSelect(target);
      }
      if (target.matches(".aipkit_vector_store_enable_select")) {
        const capabilityState = getChatbotCapabilityState();
        const compatibilityMessage = capabilityState.knowledgeRequested
          ? getKnowledgeProviderCompatibilityMessage(
              capabilityState.chatbotProvider,
              capabilityState.knowledgeProvider
            )
          : "";
        if (compatibilityMessage) {
          setToggleFieldValue(target, false);
          updateContextPopoverControls();
          setVectorProviderNoticeMessage(
            compatibilityMessage,
            capabilityState.knowledgeProvider,
            false
          );
          setSaveErrorStatus({ message: compatibilityMessage });
          return;
        }
        updateContextPopoverControls();
        syncVectorStoreLists(getContextSettings().vector_store_provider);
      }
      if (target.matches(".aipkit_vector_store_provider_select")) {
        updateVectorStoreVisibility();
        syncVectorStoreLists(target.value || "");
        updateFileUploadAvailability();
      }
      if (target.matches('[name="vector_embedding_provider"]')) {
        const settingsContainer = getContextSettingsContainer();
        if (
          settingsContainer &&
          typeof window.aipkit_populateVectorEmbeddingModelSelect ===
            "function"
        ) {
          window.aipkit_populateVectorEmbeddingModelSelect(
            settingsContainer
          );
        }
        syncEmbeddingSelectFromInputs(settingsContainer);
      }
      if (target.matches('[name="vector_embedding_model"]')) {
        const settingsContainer = getContextSettingsContainer();
        syncEmbeddingSelectFromInputs(settingsContainer);
      }
      updateFileUploadAvailability();
      const capabilityState = getChatbotCapabilityState();
      if (capabilityState.shouldDisableGoogleSearchForKnowledge) {
        setGoogleSearchEnabledWithoutChangeEvent(false);
        saveWebSettingsAfterKnowledgeChange();
      }
      const contextSettings = getContextSettings();
      saveCurrentSettings();
      refreshVectorProviderNotice();
      updateTrainingSourcesCount(contextSettings);
      syncWebsiteTargetSelect(contextSettings);
      if (typeof window.aipkit_refreshSourcesSheet === "function") {
        window.aipkit_refreshSourcesSheet(1);
      }
    }
  };

  bindChatbotSettingsAutosave({
    builder, panel: modelPopoverPanel, boundKey: "contextSettingsAutosaveBound",
    action: "aipkit_update_chatbot_context_settings", lane: "model-context", persistence,
    readSettings: getContextSettings,
    isUnchanged: (a, b) => JSON.stringify(a) === JSON.stringify(b),
    savedSettings: (settings, response) => {
      const saved = response?.bot?.settings;
      return settings.enable_vector_store === "1" && settings.vector_store_provider === "google" &&
        saved?.provider === "Google" && saved.google_search_grounding_enabled === "0"
        ? { ...settings, google_search_grounding_enabled: "0" } : settings;
    },
    resetSettings: (settings, response) => Object.fromEntries(Object.entries(settings).map(([name, value]) =>
      [name, response.bot.settings[name] ?? (Array.isArray(value) ? [] : value)])),
    restoreSettings: settings => {
      for (const root of new Set([builder, getContextSettingsContainer()])) if (root) hydration.applySettings(root, settings);
    },
    afterHydrate: () => {
      invalidateSyncStatus();
      if (hydrated) {
        updateContextPopoverControls();
        updateVectorStoreVisibility();
        updateFileUploadAvailability();
        syncEmbeddingSelectFromInputs(getContextSettingsContainer());
        const settings = getContextSettings();
        updateTrainingSourcesCount(settings);
        syncWebsiteTargetSelect(settings);
      }
      hydrated = true;
      refreshVectorProviderNotice();
    },
    interactionNodes: () => [getContextSettingsContainer()],
    bindEvents: ({ signal, isEditable, save, draft, saveAndWait: saveSnapshot }) => {
      bindingSignal = signal; editable = isEditable; saveCurrentSettings = save; saveAndWait = saveSnapshot;
      setCapabilitySave(save);
      signal.addEventListener("abort", invalidateSyncStatus, { once: true });
      modelPopoverPanel.addEventListener("change", handleContextSettingsChange, { signal });
      modelPopoverPanel.addEventListener("input", event => {
        if (event.target?.matches('[name="vector_store_top_k"], [name="vector_store_confidence_threshold"]')) draft();
      }, { signal });
    },
  });
  return {
    getSettings: getContextSettings,
    saveBeforeKnowledgeUpdate: saveContextSettingsBeforeKnowledgeUpdate,
  };
}

/** Binds Knowledge capability/configuration controls and their provider handoffs. */
export function createChatbotKnowledgeSettings({
  builder,
  contextSettingsPanel,
  modelPopoverPanel,
  isToggleFieldOn,
  __,
  openaiApiKeySet,
  pineconeApiKeySet,
  qdrantApiKeySet,
  qdrantUrlSet,
  chromaUrlSet,
  googleApiKeySet,
  claudeApiKeySet,
  syncToolsEnabledOptionsFromFields,
  setVectorProviderNoticeMessage,
  getVectorProviderNotConfiguredMessage,
  updateTrainingCardVisibility,
  updateWebGroundingVisibility,
  saveContextSettingsAfterCapabilityChange,
  syncTrainingUiState
}) {
  const getVectorStoreToggleValue = () => {
    const field = modelPopoverPanel && modelPopoverPanel.querySelector(".aipkit_vector_store_enable_select") || builder.querySelector(".aipkit_vector_store_enable_select");
    if (!field) {
      return "0";
    }
    if (field.tagName === "SELECT") {
      return field.value === "1" ? "1" : "0";
    }
    return field.checked ? "1" : "0";
  };
  const getFileUploadToggleValue = () => {
    if (!modelPopoverPanel) {
      return "0";
    }
    const field = modelPopoverPanel.querySelector(".aipkit_file_upload_toggle_select");
    if (!field || field.disabled) {
      return "0";
    }
    if (field.tagName === "SELECT") {
      return field.value === "1" ? "1" : "0";
    }
    return field.checked ? "1" : "0";
  };
  const getContextSettingsContainer = () => contextSettingsPanel;
  const getVectorStoreElements = () => {
    const settingsContainer = getContextSettingsContainer();
    if (!settingsContainer) {
      return {};
    }
    return {
      providerSelect: settingsContainer.querySelector('[name="vector_store_provider"]'),
      openaiStoresSelect: settingsContainer.querySelector('select[name="openai_vector_store_ids[]"]'),
      googleStoresSelect: settingsContainer.querySelector('select[name="google_file_search_store_names[]"]'),
      pineconeIndexSelect: settingsContainer.querySelector('[name="pinecone_index_name"]'),
      qdrantCollectionsSelect: settingsContainer.querySelector('select[name="qdrant_collection_names[]"]'),
      chromaCollectionsSelect: settingsContainer.querySelector('select[name="chroma_collection_names[]"]'),
      localStoresSelect: settingsContainer.querySelector('select[name="local_store_ids[]"]')
    };
  };
  const hasSelectedValue = select => getConfiguredVectorStoreValues(select).length > 0;
  const getChatbotCapabilityState = (overrides = {}) => {
    const settingsContainer = getContextSettingsContainer();
    const chatbotProviderField = builder.querySelector(".aipkit_chatbot_provider_select");
    const fileUploadField = modelPopoverPanel ? modelPopoverPanel.querySelector(".aipkit_file_upload_toggle_select") : null;
    const googleSearchField = modelPopoverPanel ? modelPopoverPanel.querySelector(".aipkit_google_search_grounding_enable_toggle") : null;
    const {
      providerSelect,
      openaiStoresSelect,
      googleStoresSelect,
      pineconeIndexSelect,
      qdrantCollectionsSelect,
      chromaCollectionsSelect,
      localStoresSelect
    } = getVectorStoreElements();
    const knowledgeProvider = overrides.knowledgeProvider ?? providerSelect?.value ?? "";
    let knowledgeHasTarget = false;
    if (knowledgeProvider === "openai") {
      knowledgeHasTarget = hasSelectedValue(openaiStoresSelect);
    } else if (knowledgeProvider === "google") {
      knowledgeHasTarget = hasSelectedValue(googleStoresSelect);
    } else if (knowledgeProvider === "pinecone") {
      knowledgeHasTarget = hasSelectedValue(pineconeIndexSelect);
    } else if (knowledgeProvider === "qdrant") {
      knowledgeHasTarget = hasSelectedValue(qdrantCollectionsSelect);
    } else if (knowledgeProvider === "chroma") {
      knowledgeHasTarget = hasSelectedValue(chromaCollectionsSelect);
    } else if (knowledgeProvider === "local") {
      knowledgeHasTarget = hasSelectedValue(localStoresSelect);
    } else if (knowledgeProvider === "claude_files") {
      knowledgeHasTarget = true;
    }
    const embeddingProviderField = settingsContainer ? settingsContainer.querySelector('[name="vector_embedding_provider"]') : null;
    const embeddingModelField = settingsContainer ? settingsContainer.querySelector('[name="vector_embedding_model"]') : null;
    return deriveChatbotCapabilityState({
      chatbotProvider: overrides.chatbotProvider ?? chatbotProviderField?.value ?? "",
      knowledgeEnabled: overrides.knowledgeEnabled ?? getVectorStoreToggleValue(),
      knowledgeProvider,
      knowledgeHasTarget: overrides.knowledgeHasTarget ?? knowledgeHasTarget,
      knowledgeHasEmbedding: overrides.knowledgeHasEmbedding ?? Boolean(embeddingProviderField?.value && embeddingModelField?.value),
      fileUploadEnabled: overrides.fileUploadEnabled ?? getFileUploadToggleValue(),
      isProPlan: overrides.isProPlan ?? fileUploadField?.dataset.isProPlan === "true",
      googleSearchEnabled: overrides.googleSearchEnabled ?? isToggleFieldOn(googleSearchField)
    });
  };
  const setToggleFieldValue = (field, enabled) => {
    if (!field) {
      return;
    }
    if (field.tagName === "SELECT") {
      field.value = enabled ? "1" : "0";
      return;
    }
    field.checked = Boolean(enabled);
  };
  const setKnowledgeEnabledWithoutChangeEvent = enabled => {
    const field = modelPopoverPanel && modelPopoverPanel.querySelector(".aipkit_vector_store_enable_select") || builder.querySelector(".aipkit_vector_store_enable_select");
    setToggleFieldValue(field, enabled);
    updateContextPopoverControls();
  };
  const setGoogleSearchEnabledWithoutChangeEvent = enabled => {
    const field = modelPopoverPanel ? modelPopoverPanel.querySelector(".aipkit_google_search_grounding_enable_toggle") : null;
    setToggleFieldValue(field, enabled);
    updateWebGroundingVisibility();
  };
  const coordinateKnowledgeForChatbotProvider = chatbotProvider => {
    const capabilityState = getChatbotCapabilityState({
      chatbotProvider
    });
    if (!capabilityState.shouldDeactivateKnowledgeForProviderSwitch) {
      return false;
    }
    setKnowledgeEnabledWithoutChangeEvent(false);
    saveContextSettingsAfterCapabilityChange();
    if (typeof syncTrainingUiState === "function") {
      syncTrainingUiState();
    }
    return true;
  };
  const {
    getVectorProviderState,
    getVectorProviderSelectionState,
    getVectorProviderConfigIssue,
    getVectorProviderReasonFromError,
    isVectorProviderMissingConfigError
  } = createChatbotProviderConfig({
    builder,
    getContextSettingsContainer,
    getVectorStoreToggleValue,
    openaiApiKeySet,
    pineconeApiKeySet,
    qdrantApiKeySet,
    qdrantUrlSet,
    chromaUrlSet,
    googleApiKeySet,
    claudeApiKeySet
  });
  const refreshVectorProviderNotice = () => {
    const configIssue = getVectorProviderConfigIssue();
    if (!configIssue) {
      setVectorProviderNoticeMessage("");
      return;
    }
    if (configIssue.reason === "google-provider" || configIssue.reason === "claude_files-provider") {
      const {vectorProvider, chatbotProvider} = getVectorProviderSelectionState();
      setVectorProviderNoticeMessage(getKnowledgeProviderCompatibilityMessage(chatbotProvider, vectorProvider), vectorProvider, false);
      return;
    }
    setVectorProviderNoticeMessage(getVectorProviderNotConfiguredMessage(), configIssue.reason || configIssue.providerKey);
  };
  const showVectorProviderSyncErrorNotice = (rawMessage, providerKey = "") => {
    let message = String(rawMessage || "").trim() || "Sync failed.";
    const configIssue = getVectorProviderConfigIssue();
    const normalizedProvider = String(providerKey || "").trim().toLowerCase() || configIssue?.providerKey || "";
    let noticeProviderKey = normalizedProvider;
    let missingConfiguration = false;
    if (normalizedProvider && isVectorProviderMissingConfigError(normalizedProvider, message)) {
      missingConfiguration = true;
      let reason = getVectorProviderReasonFromError(normalizedProvider, message) || "";
      if (configIssue && configIssue.providerKey === normalizedProvider) {
        reason = configIssue.reason || reason;
      }
      noticeProviderKey = reason || normalizedProvider;
      message = getVectorProviderNotConfiguredMessage();
    }
    setVectorProviderNoticeMessage(message, noticeProviderKey, missingConfiguration);
  };
  const {updateFileUploadAvailability} = createChatbotUploadAvailability({
    modelPopoverPanel,
    getChatbotCapabilityState,
    syncToolsEnabledOptionsFromFields,
    __
  });
  const updateVectorStoreVisibility = () => {
    const settingsContainer = getContextSettingsContainer();
    if (!settingsContainer) {
      return;
    }
    if (typeof window.aipkit_toggleVectorStoreSettingsVisibility === "function") {
      window.aipkit_toggleVectorStoreSettingsVisibility(settingsContainer);
    }
    if (typeof window.aipkit_toggleVectorStoreProviderFields === "function") {
      window.aipkit_toggleVectorStoreProviderFields(settingsContainer);
    }
    refreshVectorProviderNotice();
  };
  const updateContextPopoverControls = () => {
    if (!modelPopoverPanel) {
      return;
    }
    updateVectorStoreVisibility();
    updateFileUploadAvailability();
    refreshVectorProviderNotice();
    updateTrainingCardVisibility();
  };
  return {
    getContextSettingsContainer,
    getVectorStoreElements,
    getVectorProviderState,
    refreshVectorProviderNotice,
    showVectorProviderSyncErrorNotice,
    getChatbotCapabilityState,
    setToggleFieldValue,
    updateContextPopoverControls,
    setKnowledgeEnabledWithoutChangeEvent,
    setGoogleSearchEnabledWithoutChangeEvent,
    coordinateKnowledgeForChatbotProvider,
    updateVectorStoreVisibility,
    updateFileUploadAvailability
  };
}

/**
 * Search settings, a side panel from the Knowledge card: the knowledge picker, and one line on the
 * card's row saying which knowledge answers read and how much. Adding the first source sets up the
 * storage, so the panel only shows what a storage uses.
 */

const STORE_SELECTS = {
  local: 'select[name="local_store_ids[]"]',
  openai: 'select[name="openai_vector_store_ids[]"]',
  google: 'select[name="google_file_search_store_names[]"]',
  pinecone: 'select[name="pinecone_index_name"]',
  qdrant: 'select[name="qdrant_collection_names[]"]',
  chroma: 'select[name="chroma_collection_names[]"]',
};

// Builder flags that say a provider's key or URL is saved.
const CONFIGURED_FLAGS = {
  openai: "openaiApiKeySet",
  google: "googleApiKeySet",
  pinecone: "pineconeApiKeySet",
  qdrant: "qdrantUrlSet",
  chroma: "chromaUrlSet",
  claude_files: "claudeApiKeySet",
};

export function bindChatbotSearchSettings(builder, { __ = (text) => text, _n = (single, plural, count) => (count === 1 ? single : plural), sprintf = (text) => text } = {}) {
  const settingsArea = builder.querySelector('[data-aipkit-feature-drawer="search"] [data-aipkit-settings-panel="context"]');
  if (!settingsArea || settingsArea.dataset.searchSettingsBound) {
    return;
  }
  settingsArea.dataset.searchSettingsBound = "1";
  const controller = new AbortController();
  const listen = (target, type, handler) => target?.addEventListener(type, handler, { signal: controller.signal });
  const field = (name) => settingsArea.querySelector(`[name="${name}"]`);

  const providerNames = {
    local: __("built-in", "gpt3-ai-content-generator"),
    openai: "OpenAI",
    google: "Google",
    pinecone: "Pinecone",
    qdrant: "Qdrant",
    chroma: "Chroma",
    claude_files: "Anthropic Files",
  };

  const picker = bindKnowledgePicker(settingsArea.querySelector("[data-aipkit-knowledge-picker]"), {
    settingsArea,
    stateTarget: builder,
    __,
    isConfigured: (key) => !CONFIGURED_FLAGS[key] || builder.dataset[CONFIGURED_FLAGS[key]] === "true",
    lockedReason: (key) => ({
      google: __("Needs a Gemini model", "gpt3-ai-content-generator"),
      claude_files: __("Needs a Claude model", "gpt3-ai-content-generator"),
    }[key] || ""),
  });

  // The knowledge in use, as [words, whether answers search it].
  const describeKnowledge = () => {
    const enabled = Boolean(settingsArea.querySelector(".aipkit_vector_store_enable_select")?.checked);
    const key = field("vector_store_provider")?.value || "";
    const select = STORE_SELECTS[key] ? settingsArea.querySelector(STORE_SELECTS[key]) : null;
    const names = select
      ? Array.from(select.selectedOptions).filter((option) => option.value && !option.disabled).map((option) => splitStoreLabel(option.textContent).name)
      : [];
    if (!enabled) {
      return [names.length
        ? __("Off. Answers don't use your knowledge.", "gpt3-ai-content-generator")
        : __("Set up when you add your first source.", "gpt3-ai-content-generator"), false];
    }
    if (select && !names.length) {
      return [__("Set up when you add your first source.", "gpt3-ai-content-generator"), false];
    }
    /* translators: 1: knowledge base names, 2: where they are stored, such as built-in or Pinecone. */
    const where = select ? sprintf(__("%1$s (%2$s)", "gpt3-ai-content-generator"), names.join(", "), providerNames[key] || key) : providerNames[key] || key;
    // Storages that search on their own side (Anthropic Files) have no number to show.
    const topKRow = settingsArea.querySelector(".aipkit_vector_store_top_k_field");
    const topK = Number(field("vector_store_top_k")?.value);
    if (!topKRow || topKRow.closest('[style*="none"]') || !Number.isFinite(topK) || topK < 1) {
      return [where, true];
    }
    /* translators: 1: where answers search, 2: number of matches read. */
    return [sprintf(_n("%1$s · reads the closest match", "%1$s · reads the %2$s closest matches", topK, "gpt3-ai-content-generator"), where, String(topK)), true];
  };
  // Knowledge first, then the page the visitor is on when answers use it.
  const describe = () => {
    const [knowledge, searched] = describeKnowledge();
    if (!settingsArea.querySelector(".aipkit_content_aware_enable_select")?.checked) {
      return knowledge;
    }
    /* translators: %s: the knowledge answers search, such as Support (built-in) · reads the 3 closest matches. */
    return searched ? sprintf(__("%s · plus the page they're on", "gpt3-ai-content-generator"), knowledge) : __("Uses the page they're on", "gpt3-ai-content-generator");
  };

  let frame = null;
  const render = () => {
    frame = null;
    const hint = builder.querySelector('[data-aipkit-feature-row="search"] [data-aipkit-feature-hint]');
    if (hint) {
      hint.textContent = describe();
    }
  };
  const refresh = () => {
    if (frame === null) {
      frame = window.requestAnimationFrame(render);
    }
  };

  listen(builder, "change", refresh);
  listen(builder, "input", refresh);
  listen(builder, "aipkit:bot-state-applied", refresh);
  // Store lists fill in late, and switching providers or turning knowledge off (sometimes without a
  // change event) shows and hides the rows.
  const changes = new MutationObserver(refresh);
  changes.observe(settingsArea, { childList: true, subtree: true, attributes: true, attributeFilter: ["style", "data-vector-provider"] });

  const removal = new MutationObserver(() => {
    if (settingsArea.isConnected) return;
    controller.abort();
    changes.disconnect();
    if (frame !== null) {
      window.cancelAnimationFrame(frame);
      frame = null;
    }
    picker?.dispose();
    removal.disconnect();
    delete settingsArea.dataset.searchSettingsBound;
  });
  removal.observe(settingsArea.ownerDocument.documentElement, { childList: true, subtree: true });
  render();
}
