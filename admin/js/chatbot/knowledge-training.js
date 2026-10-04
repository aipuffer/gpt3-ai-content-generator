import { isMissingProviderCredentialMessage, resolveChatbotProviderConfigured } from "./providers.js";

import { createSourceActionMenu } from "../knowledge-base/source-actions.js";

import { createChatbotSourceRecords, getSourceUpdatedMeta } from "../knowledge-base/source-records.js";

import { createChatbotKnowledgeRun, bindChatbotSourceInventory, createChatbotTrainingStatus, createChatbotWebsiteSources, createChatbotSourceEditor, bindChatbotSourcePicker, getKnowledgeProviderCompatibilityMessage, resolveChatbotSourceSetup } from "./knowledge.js";
import { getConfiguredVectorStoreValues } from "../utils/vector-store-selection-state.js";

import { bindChatbotContextSettings } from "./knowledge-settings.js";

import { createGoogleFileSearchJobPoller } from "../utils/google-file-search-job-poller.js";

/** Owns Knowledge training sessions, cancellation, source operations and their UI. */
export function createChatbotTraining({builder, __, _n, sprintf, getSelectedBuilderBotId, botSelect, sheetOverlay}) {
  const createQdrantPointId = () => {
    if (typeof window.aipkit_generateUUIDv4 === "function") {
      return window.aipkit_generateUUIDv4();
    }
    if (window.crypto && typeof window.crypto.randomUUID === "function") {
      return window.crypto.randomUUID();
    }
    return Date.now();
  };
  const createChromaRecordId = () => typeof window.aipkit_generateUUIDv4 === "function" ? window.aipkit_generateUUIDv4() : `text_${Date.now()}`;
  let activeTrainingSource = "";
  const trainingWebsitePanel = builder.querySelector("[data-aipkit-training-website-panel]");
  const trainingTextInput = builder.querySelector(".aipkit_training_text_input");
  const trainingQaQuestion = builder.querySelector("#aipkit_training_qa_question");
  const trainingQaAnswer = builder.querySelector("#aipkit_training_qa_answer");
  const trainingCommonQuestionsToggle = builder.querySelector("[data-aipkit-common-questions-toggle]");
  const trainingCommonQuestionsPanel = builder.querySelector("[data-aipkit-common-questions-panel]");
  const trainingCommonQuestionButtons = Array.from(builder.querySelectorAll("[data-aipkit-common-question]"));
  const trainingFilesInput = builder.querySelector("#aipkit_training_files_input");
  const trainingFilesButton = builder.querySelector(".aipkit_training_files_button");
  const trainingFilesDropzone = builder.querySelector(".aipkit_training_dropzone");
  const trainingActionButtons = builder.querySelectorAll(".aipkit_training_action_btn[data-training-action]");
  const trainingOtherSourceSelect = builder.querySelector("[data-aipkit-training-source-select]");
  const trainingOtherSourceOptions = Array.from(builder.querySelectorAll("[data-aipkit-training-source-option]"));
  const trainingSourcePicker = builder.querySelector("[data-aipkit-training-source-picker]");
  const trainingSourceForm = builder.querySelector("[data-aipkit-training-source-form]");
  const trainingSourcePopover = builder.querySelector("[data-aipkit-training-source-popover]");
  const trainingDiscardPrompt = trainingSourcePopover ? trainingSourcePopover.querySelector("[data-aipkit-training-discard-prompt]") : null;
  const trainingDiscardTitle = trainingDiscardPrompt ? trainingDiscardPrompt.querySelector(".aipkit_training_discard_title") : null;
  const trainingDiscardMessage = trainingDiscardPrompt ? trainingDiscardPrompt.querySelector(".aipkit_training_discard_message") : null;
  const trainingDiscardKeepButton = trainingDiscardPrompt ? trainingDiscardPrompt.querySelector(".aipkit_training_discard_keep") : null;
  const trainingDiscardConfirmButton = trainingDiscardPrompt ? trainingDiscardPrompt.querySelector(".aipkit_training_discard_confirm") : null;
  const trainingOtherFooter = builder.querySelector("[data-aipkit-training-other-footer]");
  const trainingOtherPanelsContainer = builder.querySelector("[data-aipkit-training-other-panels]");
  const trainingOtherPanels = Array.from(builder.querySelectorAll(".aipkit_training_other_panels .aipkit_builder_tab_panel[data-aipkit-panel]"));
  const trainingAddButtons = Array.from(builder.querySelectorAll('.aipkit_training_action_btn[data-training-action="add"]'));
  const trainingMainAddButton = builder.querySelector("[data-aipkit-training-main-action]");
  const trainingSheetAddButton = builder.querySelector("[data-aipkit-training-sheet-action]");
  const trainingStatusElements = Array.from(builder.querySelectorAll("[data-aipkit-training-status]"));
  const trainingStatus = builder.querySelector("#aipkit_training_status") || trainingStatusElements[0] || null;
  const trainingPresentation = createChatbotTrainingStatus({
    builder,
    __,
    trainingMainAddButton,
    trainingSheetAddButton,
    getActiveTrainingTabKey: () => activeTrainingSource,
    isOtherTrainingTab: tabKey => isOtherTrainingTab(tabKey),
    getIsTraining: () => isTraining,
    clearWebsiteTask: () => {
      currentWebsiteTrainingTaskId = "";
    }
  });
  const {
    trainingState,
    trainingStateMenu,
    trainingStopButton,
    closeTrainingStateMenu,
    setTrainingStateMenuOpen,
    setTrainingStatusSnapshot,
    setDurableTrainingStatus,
    getTrainingActionButton,
    getTrainingActionLabels,
    setTrainingActionButtonText,
    updateTrainingActionLabel,
    updateTrainingActionProgress
  } = trainingPresentation;
  const trainingSourcesViewButton = builder.querySelector("[data-aipkit-view-training-sources]");
  const trainingFileList = builder.querySelector("#aipkit_training_file_list");
  const trainingFileQueue = builder.querySelector("[data-aipkit-training-file-queue]");
  const trainingFileCount = builder.querySelector("[data-aipkit-training-file-count]");
  const trainingFileRows = new Map;
  const trainingSelectedFiles = new Map;
  let isTraining = false;
  let closeTrainingSourceWhenStopped = false;
  let trainingSourceAbortController = null;
  let activeFileTrainingRun = null;
  let currentWebsiteTrainingTaskId = "";
  let isStoppingTraining = false;
  let updateFileUploadActionAvailability = () => {};
  const trainingSourceApiRequest = async (action, data) => {
    const run = activeFileTrainingRun;
    const response = await window.aipkit_apiRequest(action, data, {
      signal: trainingSourceAbortController?.signal,
      abortMessage: __("Adding stopped.", "gpt3-ai-content-generator")
    });
    run?.assertCurrent();
    return response;
  };
  const abortActiveTrainingSourceRequest = () => {
    window.aipkit_isIndexingStopped = true;
    activeFileTrainingRun?.abort();
    if (trainingSourceAbortController && !trainingSourceAbortController.signal.aborted) {
      trainingSourceAbortController.abort();
    }
  };
  const isTrainingSourceStoppedError = error => error?.code === "aipkit_vector_file_job_stopped" || error?.name === "AbortError" || error?.code === "aborted" || window.aipkit_isIndexingStopped === true;
  const trainingSourcesButton = builder.querySelector(".aipkit_training_sources_btn");
  const trainingAddSourceButton = builder.querySelector(".aipkit_training_add_source");
  const trainingManagedSources = builder.querySelector("[data-aipkit-training-managed-sources]");
  const trainingSourceEmpty = builder.querySelector("[data-aipkit-training-source-empty]");
  const trainingSourceEmptyTitle = builder.querySelector("[data-aipkit-training-source-empty-title]");
  const trainingSourceEmptyDescription = builder.querySelector("[data-aipkit-training-source-empty-description]");
  const trainingCard = builder.querySelector("#aipkit_sources_training_card");
  const trainingWpTargetSelect = builder.querySelector("#aipkit_vs_global_target_select");
  const trainingWpStatusSelect = builder.querySelector("#aipkit_vs_wp_content_status");
  const trainingWpBulkPanel = builder.querySelector("#aipkit_wp_content_bulk_panel");
  const getActiveTrainingTabKey = () => activeTrainingSource;
  const isOtherTrainingTab = tabKey => tabKey === "text" || tabKey === "qa" || tabKey === "files";
  const getTrainingTabKeyForAction = (button = null) => {
    if (button && button.matches("[data-aipkit-training-main-action]")) {
      return "website";
    }
    if (button && button.matches("[data-aipkit-training-sheet-action]")) {
      const selectedSource = trainingOtherSourceSelect ? trainingOtherSourceSelect.value || "" : "";
      if (isOtherTrainingTab(selectedSource)) {
        return selectedSource;
      }
      const activeSource = getActiveTrainingTabKey();
      return isOtherTrainingTab(activeSource) ? activeSource : "text";
    }
    return getActiveTrainingTabKey();
  };
  const buildTrainingStatusPayload = (contextSettings = null) => {
    const botId = getSelectedBuilderBotId();
    if (!botId) {
      return null;
    }
    const payload = {
      bot_id: botId
    };
    if (contextSettings) {
      payload.enable_vector_store = contextSettings.enable_vector_store;
      payload.vector_store_provider = contextSettings.vector_store_provider;
      payload.openai_vector_store_ids = contextSettings.openai_vector_store_ids || [];
      payload.google_file_search_store_names = contextSettings.google_file_search_store_names || [];
      payload.pinecone_index_name = contextSettings.pinecone_index_name;
      payload.qdrant_collection_names = contextSettings.qdrant_collection_names || [];
      payload.chroma_collection_names = contextSettings.chroma_collection_names || [];
      payload.local_store_ids = contextSettings.local_store_ids || [];
    }
    return payload;
  };
  const updateTrainingCardVisibility = () => {
    if (!trainingCard) {
      return;
    }
    trainingCard.hidden = false;
  };
  updateTrainingCardVisibility();
  const sourcesSheetSection = sheetOverlay ? sheetOverlay.querySelector('.aipkit_builder_sheet_section[data-sheet="sources"]') : null;
  const sourcesTableBody = sourcesSheetSection ? sourcesSheetSection.querySelector("#aipkit_sources_table_body") : null;
  const sourcesSearchInput = sourcesSheetSection ? sourcesSheetSection.querySelector(".aipkit_sources_search_input") : null;
  const sourcesFilterSelect = sourcesSheetSection ? sourcesSheetSection.querySelector(".aipkit_sources_filter_select") : null;
  const sourcesTypeFilter = sourcesSheetSection ? sourcesSheetSection.querySelector(".aipkit_sources_type_filter") : null;
  const sourcesRefreshButton = sourcesSheetSection ? sourcesSheetSection.querySelector(".aipkit_sources_refresh_btn") : null;
  const sourcesPagination = sourcesSheetSection ? sourcesSheetSection.querySelector("#aipkit_sources_pagination") : null;
  const sourcesStatus = sourcesSheetSection ? sourcesSheetSection.querySelector("#aipkit_sources_status") : null;
  const sourceEditor = createChatbotSourceEditor({
    sourcesEditor: document.getElementById("aipkit_sources_editor_modal"),
    __
  });
  let finalizeTrainingSourceDiscard;
  const bindSourcePicker = () => {
    finalizeTrainingSourceDiscard = bindChatbotSourcePicker({
      builder,
      __,
      trainingCommonQuestionsToggle,
      trainingCommonQuestionsPanel,
      trainingCommonQuestionButtons,
      trainingQaQuestion,
      trainingQaAnswer,
      trainingTextInput,
      trainingSourcePopover,
      trainingAddSourceButton,
      trainingSourcePicker,
      trainingSourceForm,
      trainingWebsitePanel,
      trainingOtherPanelsContainer,
      trainingOtherPanels,
      trainingOtherFooter,
      trainingOtherSourceOptions,
      trainingOtherSourceSelect,
      trainingDiscardPrompt,
      trainingDiscardTitle,
      trainingDiscardMessage,
      trainingDiscardKeepButton,
      trainingDiscardConfirmButton,
      trainingSelectedFiles,
      trainingFileRows,
      trainingFileList,
      trainingFilesInput,
      trainingFilesDropzone,
      trainingFileQueue,
      trainingFileCount,
      isOtherTrainingTab,
      updateTrainingActionLabel,
      updateTrainingActionProgress,
      getIsTraining: () => isTraining,
      getActiveTrainingSource: () => activeTrainingSource,
      setActiveTrainingSourceKey: key => {
        activeTrainingSource = key;
      },
      updateFileUploadActionAvailability: () => updateFileUploadActionAvailability(),
      onDiscard: () => activeFileTrainingRun?.invalidate(),
      requestTrainingCancel: () => {
        closeTrainingSourceWhenStopped = true;
        abortActiveTrainingSourceRequest();
      }
    });
  };
  const bindSettings = ({
    modelPopoverPanel,
    saveStatus,
    builderPersistence,
    getContextSettingsContainer,
    getVectorProviderState,
    getVectorStoreElements,
    refreshVectorProviderNotice,
    showVectorProviderSyncErrorNotice,
    getChatbotCapabilityState,
    setToggleFieldValue,
    updateContextPopoverControls,
    setVectorProviderNoticeMessage,
    setSaveErrorStatus,
    updateVectorStoreVisibility,
    updateFileUploadAvailability,
    setGoogleSearchEnabledWithoutChangeEvent,
    saveWebSettingsAfterKnowledgeChange,
    pineconeApiKeySet,
    qdrantUrlSet,
    qdrantApiKeySet,
    chromaUrlSet,
    openaiApiKeySet,
    googleApiKeySet,
    azureApiKeySet
  }) => {
    let syncTrainingUiState = null;
    let saveContextSettingsAfterCapabilityChange = () => {};
    if (modelPopoverPanel && saveStatus && !builder.dataset.contextSettingsAutosaveBound) {
      const {
        getSettings: getContextSettings,
        saveBeforeKnowledgeUpdate: saveContextSettingsBeforeKnowledgeUpdate
      } = bindChatbotContextSettings({
        persistence: builderPersistence,
        builder,
        modelPopoverPanel,
        getContextSettingsContainer,
        getVectorProviderState,
        getVectorStoreElements,
        refreshVectorProviderNotice,
        showVectorProviderSyncErrorNotice,
        getChatbotCapabilityState,
        setToggleFieldValue,
        updateContextPopoverControls,
        setVectorProviderNoticeMessage,
        setSaveErrorStatus,
        updateVectorStoreVisibility,
        updateFileUploadAvailability,
        setGoogleSearchEnabledWithoutChangeEvent,
        __,
        // Resolve callbacks installed later in this initialization when they are used.
        syncEmbeddingInputsFromSelect: select => syncEmbeddingInputsFromSelect(select),
        syncEmbeddingSelectFromInputs: container => syncEmbeddingSelectFromInputs(container),
        saveWebSettingsAfterKnowledgeChange: () => saveWebSettingsAfterKnowledgeChange(),
        updateTrainingSourcesCount: settings => updateTrainingSourcesCount(settings),
        syncWebsiteTargetSelect: settings => syncWebsiteTargetSelect(settings),
        setCapabilitySave: save => {
          saveContextSettingsAfterCapabilityChange = save;
        }
      });
      const setTrainingStatus = (text, type) => {
        const statusTargets = trainingStatusElements.length ? trainingStatusElements : [ trainingStatus ].filter(Boolean);
        if (!statusTargets.length) {
          return;
        }
        const normalizedText = String(text || "").replace(/\((\d+)\s*\/\s*(\d+)\)/g, "$1 of $2").replace(/\b(\d+)\s*\/\s*(\d+)\b/g, "$1 of $2").replace(/(?:\.{3}|…)+\s*$/, "").trim();
        statusTargets.forEach(statusTarget => {
          statusTarget.textContent = normalizedText;
          statusTarget.classList.remove("is-visible", "is-success", "is-error", "is-warning", "is-loading");
          if (normalizedText) {
            statusTarget.classList.add("is-visible");
          }
          if (type) {
            statusTarget.classList.add(`is-${type}`);
          }
          if (type === "loading") {
            statusTarget.setAttribute("aria-busy", "true");
          } else {
            statusTarget.removeAttribute("aria-busy");
          }
        });
      };
      const clearTrainingStatusSoon = (delay = 2500) => {
        const statusTargets = trainingStatusElements.length ? trainingStatusElements : [ trainingStatus ].filter(Boolean);
        if (!statusTargets.length) {
          return;
        }
        setTimeout(() => {
          statusTargets.forEach(statusTarget => {
            statusTarget.textContent = "";
            statusTarget.classList.remove("is-visible", "is-success", "is-error", "is-warning", "is-loading");
            statusTarget.removeAttribute("aria-busy");
          });
        }, delay);
      };
      const websiteSources = createChatbotWebsiteSources({
        builder,
        trainingWpTargetSelect,
        trainingWpStatusSelect,
        trainingWpBulkPanel,
        getContextSettings
      });
      const {
        syncTarget: syncWebsiteTargetSelect,
        getPostTypes: getWebsitePostTypes,
        getStatus: getWebsiteStatusValue,
        syncTypes: syncTrainingTypeDropdownsFromHiddenSelects
      } = websiteSources;
      const fetchBulkSummary = async (postTypes, status, targetStoreId) => {
        const nonce = getNonceValue("aipkit_wp_content_fetch_nonce");
        if (!nonce || !postTypes.length) {
          return {
            total: 0
          };
        }
        const response = await window.aipkit_apiRequest("aipkit_fetch_wp_content_for_indexing", {
          _ajax_nonce: nonce,
          post_types: postTypes,
          post_status: status,
          target_store_id: targetStoreId || "",
          paged: 1
        });
        const total = parseInt(response?.pagination?.total_posts || 0, 10);
        return {
          total: Number.isNaN(total) ? 0 : total,
          totalPages: parseInt(response?.pagination?.total_pages || 1, 10)
        };
      };
      const createBackgroundIndexingTask = async ({botId, postTypes, provider, targetStoreId, embeddingProvider, embeddingModel}) => {
        const nonce = window.aipkit_chat_config?.automationsNonce || "";
        if (!nonce) {
          throw new Error("Automations nonce missing.");
        }
        const taskName = `Chatbot ${botId} website indexing`;
        const payload = {
          _ajax_nonce: nonce,
          task_id: 0,
          task_name: taskName,
          task_type: "content_indexing",
          task_status: "active",
          task_frequency: "one-time",
          index_existing_now_flag: "1",
          only_new_updated_flag: "0",
          post_types: postTypes,
          target_store_provider: provider,
          target_store_id: targetStoreId,
          source_context: "chatbot_training",
          chatbot_id: botId
        };
        if (provider === "local" || provider === "pinecone" || provider === "qdrant" || provider === "chroma") {
          payload.embedding_provider = embeddingProvider || "";
          payload.embedding_model = embeddingModel || "";
        }
        // Let task creation return its ID even when the user requests a stop.
        // The caller can then stop the created task deterministically instead
        // of losing the ID by aborting this request mid-flight.
                return window.aipkit_apiRequest("aipkit_save_automated_task", payload);
      };
      websiteSources.bind();
      syncTrainingUiState = () => {
        const contextSettings = getContextSettings();
        syncWebsiteTargetSelect(contextSettings);
        const settingsContainer = getContextSettingsContainer();
        if (settingsContainer) {
          syncEmbeddingSelectFromInputs(settingsContainer);
        }
        syncTrainingTypeDropdownsFromHiddenSelects();
        updateTrainingSourcesCount(contextSettings);
      };
      if (builder.dataset.knowledgeStoreSyncBound !== "1") {
        window.addEventListener("aipkit:vector-store-list-updated", event => {
          const updatedProvider = String(event?.detail?.provider || "").toLowerCase();
          if (!updatedProvider) {
            return;
          }
          Promise.resolve().then(() => {
            const capabilityState = getChatbotCapabilityState();
            if (updatedProvider === capabilityState.knowledgeProvider) {
              syncTrainingUiState();
            }
          });
        });
        builder.dataset.knowledgeStoreSyncBound = "1";
      }
      const {updateTrainingSourcesCount, setTrainingSourcesCount, refreshTrainingSourcesAfterMutation} = bindChatbotSourceInventory({
        trainingManagedSources,
        trainingSourceEmpty,
        trainingSourceEmptyTitle,
        trainingSourceEmptyDescription,
        trainingAddSourceButton,
        trainingSourcesButton,
        trainingCard,
        trainingPresentation,
        getChatbotCapabilityState,
        getSelectedBuilderBotId,
        buildTrainingStatusPayload,
        __,
        _n,
        sprintf
      });
      const stopChatbotTraining = async () => {
        if (isStoppingTraining || !trainingStopButton || trainingPresentation.getStatusKey() !== "training" || typeof window.aipkit_apiRequest !== "function") {
          return;
        }
        const payload = buildTrainingStatusPayload(getContextSettings());
        if (!payload) {
          return;
        }
        if (currentWebsiteTrainingTaskId) {
          payload.task_id = currentWebsiteTrainingTaskId;
        }
        isStoppingTraining = true;
        trainingStopButton.disabled = true;
        trainingStopButton.textContent = __("Stopping...", "gpt3-ai-content-generator");
        try {
          const response = await window.aipkit_apiRequest("aipkit_stop_chatbot_training", payload);
          currentWebsiteTrainingTaskId = "";
          const count = Number(response.count || 0);
          setTrainingSourcesCount(Number.isNaN(count) ? 0 : count, Boolean(response.count_is_capped));
          setTrainingStatusSnapshot(response);
          if (response.training_status) {
            setDurableTrainingStatus(response.training_status);
          }
          closeTrainingStateMenu();
          if (typeof window.aipkit_refreshSourcesSheet === "function") {
            window.aipkit_refreshSourcesSheet();
          }
        } catch (error) {
          setTrainingStatus(`Error: ${error?.message || "Failed to stop training."}`, "error");
        } finally {
          isStoppingTraining = false;
          trainingStopButton.disabled = false;
          trainingStopButton.textContent = __("Stop training", "gpt3-ai-content-generator");
          updateTrainingSourcesCount(getContextSettings());
        }
      };
      if (trainingState && trainingStateMenu && !builder.dataset.trainingStateMenuBound) {
        trainingState.addEventListener("click", event => {
          event.preventDefault();
          setTrainingStateMenuOpen(trainingStateMenu.hidden);
        });
        if (trainingStopButton) {
          trainingStopButton.addEventListener("click", event => {
            event.preventDefault();
            stopChatbotTraining();
          });
        }
        if (trainingSourcesViewButton) {
          trainingSourcesViewButton.addEventListener("click", event => {
            event.preventDefault();
            closeTrainingStateMenu();
            if (trainingSourcesButton) {
              trainingSourcesButton.click();
            }
          });
        }
        document.addEventListener("click", event => {
          if (trainingStateMenu.hidden || trainingState.contains(event.target) || trainingStateMenu.contains(event.target)) {
            return;
          }
          closeTrainingStateMenu();
        });
        document.addEventListener("keydown", event => {
          if (event.key === "Escape") {
            closeTrainingStateMenu();
          }
        });
        builder.dataset.trainingStateMenuBound = "1";
      }
      window.aipkit_refreshTrainingSourcesCount = () => {
        refreshTrainingSourcesAfterMutation(getContextSettings());
      };
      const startTrainingAutoSync = () => {
        if (!trainingSourcesButton || builder.dataset.trainingAutoSync) {
          return;
        }
        const syncNow = () => {
          if (document.visibilityState !== "visible") {
            return;
          }
          updateTrainingSourcesCount(getContextSettings());
        };
        const intervalId = window.setInterval(syncNow, 15000);
        builder.dataset.trainingAutoSync = "1";
        builder.dataset.trainingAutoSyncInterval = String(intervalId);
        document.addEventListener("visibilitychange", syncNow);
      };
      const getNonceValue = id => {
        const field = document.getElementById(id);
        return field ? field.value : "";
      };
      const googleFileSearchJobPoller = createGoogleFileSearchJobPoller({
        request: (action, data, options) => window.aipkit_apiRequest(action, data, options),
        getNonce: () => getNonceValue("aipkit_google_file_search_nonce"),
        onSettled: () => {
          refreshTrainingSourcesAfterMutation(getContextSettings());
          if (typeof window.aipkit_refreshSourcesSheet === "function") {
            window.aipkit_refreshSourcesSheet();
          }
        }
      });
      const pollGoogleFileSearchJob = (jobId, options = {}) => googleFileSearchJobPoller.poll(jobId, options);
      const flattenModelList = models => {
        if (!models) {
          return [];
        }
        if (Array.isArray(models)) {
          return models.flatMap(entry => {
            if (Array.isArray(entry)) {
              return entry;
            }
            if (entry && typeof entry === "object") {
              if (Array.isArray(entry.models)) {
                return entry.models;
              }
              if (Array.isArray(entry.items)) {
                return entry.items;
              }
              if (entry.id) {
                return [ entry ];
              }
            }
            return [];
          });
        }
        if (typeof models === "object") {
          return Object.values(models).flatMap(entry => Array.isArray(entry) ? entry : []);
        }
        return [];
      };
      const getEmbeddingModels = providerKey => {
        const chatConfig = window.aipkit_chat_config || {};
        const normalizedProviderKey = String(providerKey || "").toLowerCase();
        const modelsByProviderRaw = chatConfig.embedding_models_by_provider;
        const modelsByProvider = modelsByProviderRaw && typeof modelsByProviderRaw === "object" ? modelsByProviderRaw : {};
        return flattenModelList(window.aipkit_dashboard?.embeddingModels?.[normalizedProviderKey] ?? modelsByProvider[normalizedProviderKey] ?? []);
      };
      const buildEmbeddingChoiceValue = (provider, model) => {
        if (!provider || !model) {
          return "";
        }
        return `${provider}::${model}`;
      };
      const parseEmbeddingChoiceValue = value => {
        const parts = (value || "").split("::");
        const provider = parts.shift() || "";
        const model = parts.join("::") || "";
        return {
          provider,
          model
        };
      };
      const syncEmbeddingSelectFromInputs = settingsContainer => {
        if (!settingsContainer) {
          return;
        }
        const embeddingSelect = settingsContainer.querySelector(".aipkit_vector_embedding_select");
        if (!embeddingSelect) {
          return;
        }
        const providerInput = settingsContainer.querySelector('[name="vector_embedding_provider"]');
        const modelInput = settingsContainer.querySelector('[name="vector_embedding_model"]');
        const provider = providerInput ? providerInput.value : "";
        const model = modelInput ? modelInput.value : "";
        const value = buildEmbeddingChoiceValue(provider, model);
        if (!value || embeddingSelect.value === value) {
          if (!value) embeddingSelect.value = '';
          embeddingSelect._aipkitUnifiedModelSync?.();
          return;
        }
        const escapedValue = typeof CSS !== "undefined" && CSS.escape ? CSS.escape(value) : value.replace(/"/g, '\\"');
        const option = embeddingSelect.querySelector(`option[value="${escapedValue}"]`);
        if (option) {
          embeddingSelect.value = value;
        }
        embeddingSelect._aipkitUnifiedModelSync?.();
      };
      const syncEmbeddingInputsFromSelect = selectEl => {
        const settingsContainer = getContextSettingsContainer();
        if (!settingsContainer || !selectEl) {
          return;
        }
        const providerSelect = settingsContainer.querySelector('[name="vector_embedding_provider"]');
        const modelSelect = settingsContainer.querySelector('[name="vector_embedding_model"]');
        const selectedOption = selectEl.selectedOptions[0];
        const parsedValue = parseEmbeddingChoiceValue(selectEl.value || "");
        const provider = parsedValue.provider || (selectedOption ? selectedOption.dataset.provider || "" : "");
        const model = parsedValue.model || "";
        if (providerSelect) {
          providerSelect.value = provider;
        }
        if (modelSelect) {
          modelSelect.value = model;
        }
        if (providerSelect && modelSelect && provider && typeof window.aipkit_populateVectorEmbeddingModelSelect === "function") {
          modelSelect.value = model;
          window.aipkit_populateVectorEmbeddingModelSelect(settingsContainer);
          if (model) {
            modelSelect.value = model;
          }
        }
      };
      const getEmbeddingModelDimension = (providerKey, modelId) => {
        if (!modelId) {
          return null;
        }
        const models = getEmbeddingModels(providerKey);
        const model = models.find(entry => entry && entry.id === modelId);
        const candidate = model?.dimensions || model?.dimension || model?.output_dimensionality || model?.outputDimensionality || model?.output_dimension;
        const numericCandidate = candidate ? Number(candidate) : NaN;
        if (Number.isFinite(numericCandidate) && numericCandidate > 0) {
          return numericCandidate;
        }
        if (model && typeof model.name === "string") {
          const match = model.name.match(/\((\d{3,5})\)/);
          if (match) {
            return Number(match[1]);
          }
        }
        if (providerKey === "google") {
          return 3072;
        }
        return 1536;
      };
      const getVectorProviderKeyState = () => {
        return {
          openai: resolveChatbotProviderConfigured("openai", openaiApiKeySet),
          google: resolveChatbotProviderConfigured("google", googleApiKeySet),
          pinecone: resolveChatbotProviderConfigured("pinecone", pineconeApiKeySet),
          qdrant: resolveChatbotProviderConfigured("qdrant", qdrantUrlSet && qdrantApiKeySet),
          chroma: resolveChatbotProviderConfigured("chroma", chromaUrlSet),
        };
      };
      const getChatbotProviderValue = () => builder.querySelector(".aipkit_chatbot_provider_select")?.value || "";
      const getChatbotSourceDefaults = () => window.aipkit_dashboard?.chatbotSourceDefaults?.[getChatbotProviderValue().toLowerCase()] || {};
      const ensureEmbeddingConfig = (useDefaults = false) => {
        const settingsContainer = getContextSettingsContainer();
        if (!settingsContainer) {
          return {
            provider: "",
            model: ""
          };
        }
        const providerSelect = settingsContainer.querySelector('[name="vector_embedding_provider"]');
        const modelSelect = settingsContainer.querySelector('[name="vector_embedding_model"]');
        const defaults = getChatbotSourceDefaults();
        let provider = useDefaults ? "" : providerSelect?.value || "";
        if (!provider) {
          provider = defaults.vector_embedding_provider || "";
        }
        const fallbackConfigured = { openai: openaiApiKeySet, google: googleApiKeySet, azure: azureApiKeySet };
        const locked = window.aipkit_dashboard?.modelRegistry?.providerStates?.[provider]?.locked;
        if (!provider || locked || !resolveChatbotProviderConfigured(provider, fallbackConfigured[provider])) {
          throw new Error(__("Connect a provider with an embedding model to add knowledge.", "gpt3-ai-content-generator"));
        }
        let model = useDefaults || providerSelect?.value !== provider ? "" : modelSelect?.value || "";
        if (!model && provider) {
          const models = getEmbeddingModels(provider);
          const preferredModel = defaults.vector_embedding_provider === provider ? defaults.vector_embedding_model : "";
          model = models.find(entry => entry?.id === preferredModel)?.id || models[0]?.id || "";
        }
        if (!model) {
          throw new Error(__("Sync an embedding model for this provider to add knowledge.", "gpt3-ai-content-generator"));
        }
        if (providerSelect) {
          providerSelect.value = provider;
          window.aipkit_populateVectorEmbeddingModelSelect?.(settingsContainer);
        }
        if (modelSelect) modelSelect.value = model;
        syncEmbeddingSelectFromInputs(settingsContainer);
        return {
          provider,
          model
        };
      };
      const getSelectedOptionValue = select => {
        if (!select) {
          return "";
        }
        const selected = select.selectedOptions ? Array.from(select.selectedOptions) : [];
        const option = selected.find(opt => opt && !opt.disabled && opt.value);
        if (!option && getConfiguredVectorStoreValues(select).length) {
          throw new Error(__("The selected knowledge store is unavailable. Choose another store in Chatbot settings.", "gpt3-ai-content-generator"));
        }
        return option ? option.value : "";
      };
      const selectSingleOptionValue = (select, value) => {
        if (!select || !value) {
          return;
        }
        Array.from(select.options).forEach(opt => {
          opt.selected = opt.value === value;
        });
      };
      const ensureOpenAIStore = async botId => {
        const {openaiStoresSelect} = getVectorStoreElements();
        let storeId = getSelectedOptionValue(openaiStoresSelect);
        if (!storeId) {
          const openaiNonce = getNonceValue("aipkit_vector_store_nonce_openai");
          if (!openaiNonce) {
            throw new Error("OpenAI vector store nonce missing.");
          }
          const storeName = `Chatbot ${botId} ${Date.now()}`;
          const response = await trainingSourceApiRequest("aipkit_create_vector_store_openai", {
            _ajax_nonce: openaiNonce,
            name: storeName,
            source_type: "chatbot_training_text"
          });
          const store = response.store || response;
          if (!store || !store.id) {
            throw new Error("OpenAI vector store creation failed.");
          }
          if (!window.aipkit_chat_config) {
            window.aipkit_chat_config = {};
          }
          if (!Array.isArray(window.aipkit_chat_config.openaiVectorStores)) {
            window.aipkit_chat_config.openaiVectorStores = [];
          }
          const hasStore = window.aipkit_chat_config.openaiVectorStores.some(entry => entry && entry.id === store.id);
          if (!hasStore) {
            window.aipkit_chat_config.openaiVectorStores.push(store);
          }
          if (typeof window.aipkit_populateOpenAIVectorStoresMultiSelect === "function") {
            const settingsContainer = getContextSettingsContainer();
            if (settingsContainer) {
              window.aipkit_populateOpenAIVectorStoresMultiSelect(settingsContainer);
            }
          }
          if (openaiStoresSelect) {
            selectSingleOptionValue(openaiStoresSelect, store.id);
          }
          storeId = store.id;
        }
        return storeId;
      };
      const ensureGoogleStore = async botId => {
        const {googleStoresSelect} = getVectorStoreElements();
        let storeId = getSelectedOptionValue(googleStoresSelect);
        if (storeId) return storeId;
        const nonce = getNonceValue("aipkit_google_file_search_nonce");
        if (!nonce) throw new Error("Google File Search nonce missing.");
        const response = await trainingSourceApiRequest("aipkit_create_google_file_search_store", {
          _ajax_nonce: nonce,
          name: `${botSelect?.selectedOptions?.[0]?.textContent?.trim() || `Chatbot ${botId}`} knowledge ${Date.now().toString(36)}`,
        });
        const store = response?.store;
        storeId = store?.name;
        if (!storeId) throw new Error("Google knowledge store creation failed.");
        window.aipkit_chat_config ||= {};
        window.aipkit_chat_config.googleFileSearchStores ||= [];
        window.aipkit_chat_config.googleFileSearchStores.push(store);
        if (googleStoresSelect) {
          googleStoresSelect.appendChild(new Option(store.displayName || storeId, storeId));
          selectSingleOptionValue(googleStoresSelect, storeId);
          window.aipkit_populateGoogleFileSearchStoresMultiSelect?.(getContextSettingsContainer());
        }
        return storeId;
      };
      const ensurePineconeIndex = async (botId, dimension) => {
        const {pineconeIndexSelect} = getVectorStoreElements();
        let indexName = getSelectedOptionValue(pineconeIndexSelect);
        if (!indexName) {
          const pineconeNonce = getNonceValue("aipkit_vector_store_pinecone_nonce_management");
          if (!pineconeNonce) {
            throw new Error("Pinecone nonce missing.");
          }
          const name = `chatbot-${botId}-${Date.now()}`;
          const response = await trainingSourceApiRequest("aipkit_create_index_pinecone", {
            _ajax_nonce: pineconeNonce,
            name,
            dimension,
            metric: "cosine"
          });
          const index = response.index || response;
          const resolvedName = index?.name || name;
          if (!window.aipkit_chat_config) {
            window.aipkit_chat_config = {};
          }
          if (!Array.isArray(window.aipkit_chat_config.pineconeIndexes)) {
            window.aipkit_chat_config.pineconeIndexes = [];
          }
          const hasIndex = window.aipkit_chat_config.pineconeIndexes.some(entry => entry && entry.name === resolvedName || entry === resolvedName);
          if (!hasIndex) {
            window.aipkit_chat_config.pineconeIndexes.push(index || resolvedName);
          }
          if (typeof window.aipkit_populatePineconeIndexSelect === "function") {
            const settingsContainer = getContextSettingsContainer();
            if (settingsContainer) {
              window.aipkit_populatePineconeIndexSelect(settingsContainer);
            }
          }
          if (pineconeIndexSelect) {
            pineconeIndexSelect.value = resolvedName;
          }
          indexName = resolvedName;
        }
        return indexName;
      };
      const ensureQdrantCollection = async (botId, dimension) => {
        const {qdrantCollectionsSelect} = getVectorStoreElements();
        let collectionName = getSelectedOptionValue(qdrantCollectionsSelect);
        if (!collectionName) {
          const qdrantNonce = getNonceValue("aipkit_vector_store_qdrant_nonce_management");
          if (!qdrantNonce) {
            throw new Error("Qdrant nonce missing.");
          }
          const name = `chatbot-${botId}-${Date.now()}`;
          await trainingSourceApiRequest("aipkit_create_collection_qdrant", {
            _ajax_nonce: qdrantNonce,
            name,
            dimension,
            metric: "Cosine"
          });
          if (!window.aipkit_chat_config) {
            window.aipkit_chat_config = {};
          }
          if (!Array.isArray(window.aipkit_chat_config.qdrantCollections)) {
            window.aipkit_chat_config.qdrantCollections = [];
          }
          const hasCollection = window.aipkit_chat_config.qdrantCollections.some(entry => entry && entry.name === name || entry === name || entry?.id === name);
          if (!hasCollection) {
            window.aipkit_chat_config.qdrantCollections.push({
              name
            });
          }
          if (qdrantCollectionsSelect) {
            const optionExists = Array.from(qdrantCollectionsSelect.options).some(opt => opt.value === name);
            if (!optionExists) {
              const option = document.createElement("option");
              option.value = name;
              option.textContent = name;
              qdrantCollectionsSelect.appendChild(option);
            }
            selectSingleOptionValue(qdrantCollectionsSelect, name);
          }
          if (typeof window.aipkit_populateQdrantCollectionsMultiSelect === "function") {
            const settingsContainer = getContextSettingsContainer();
            if (settingsContainer) {
              window.aipkit_populateQdrantCollectionsMultiSelect(settingsContainer);
            }
          }
          collectionName = name;
        }
        return collectionName;
      };
      const ensureLocalStore = async (botId, dimension) => {
        const run = activeFileTrainingRun;
        const {localStoresSelect} = getVectorStoreElements();
        let storeId = getSelectedOptionValue(localStoresSelect);
        if (storeId) {
          return storeId;
        }
        const localNonce = getNonceValue("aipkit_vector_store_local_nonce_management");
        if (!localNonce) {
          throw new Error("Knowledge base nonce missing.");
        }
        const botName = (botSelect?.selectedOptions?.[0]?.textContent || "").trim();
        const name = `${botName || `Chatbot ${botId}`} knowledge ${Date.now().toString(36)}`;
        const response = await trainingSourceApiRequest("aipkit_local_create_store", {
          _ajax_nonce: localNonce,
          name,
          dimension
        });
        const store = response?.store;
        if (!store?.id) {
          throw new Error("The knowledge base could not be created.");
        }
        if (typeof window.aipkit_refreshLocalStores === "function") {
          await window.aipkit_refreshLocalStores();
        }
        run?.assertCurrent();
        if (localStoresSelect) {
          if (!Array.from(localStoresSelect.options).some(opt => opt.value === store.id)) {
            localStoresSelect.appendChild(new Option(store.name, store.id));
          }
          selectSingleOptionValue(localStoresSelect, store.id);
          const settingsContainer = getContextSettingsContainer();
          if (settingsContainer && typeof window.aipkit_populateLocalStoresMultiSelect === "function") {
            window.aipkit_populateLocalStoresMultiSelect(settingsContainer);
          }
        }
        return store.id;
      };
      const ensureChromaCollection = async botId => {
        const {chromaCollectionsSelect} = getVectorStoreElements();
        let collectionName = getSelectedOptionValue(chromaCollectionsSelect);
        if (!collectionName) {
          const chromaNonce = getNonceValue("aipkit_vector_store_chroma_nonce_management");
          if (!chromaNonce) {
            throw new Error("Chroma nonce missing.");
          }
          const name = `chatbot-${botId}-${Date.now()}`;
          await trainingSourceApiRequest("aipkit_create_collection_chroma", {
            _ajax_nonce: chromaNonce,
            name
          });
          if (!window.aipkit_chat_config) {
            window.aipkit_chat_config = {};
          }
          if (!Array.isArray(window.aipkit_chat_config.chromaCollections)) {
            window.aipkit_chat_config.chromaCollections = [];
          }
          const hasCollection = window.aipkit_chat_config.chromaCollections.some(entry => entry && entry.name === name || entry === name || entry?.id === name);
          if (!hasCollection) {
            window.aipkit_chat_config.chromaCollections.push({
              name
            });
          }
          if (chromaCollectionsSelect) {
            const optionExists = Array.from(chromaCollectionsSelect.options).some(opt => opt.value === name);
            if (!optionExists) {
              const option = document.createElement("option");
              option.value = name;
              option.textContent = name;
              chromaCollectionsSelect.appendChild(option);
            }
            selectSingleOptionValue(chromaCollectionsSelect, name);
          }
          if (typeof window.aipkit_populateChromaCollectionsMultiSelect === "function") {
            const settingsContainer = getContextSettingsContainer();
            if (settingsContainer) {
              window.aipkit_populateChromaCollectionsMultiSelect(settingsContainer);
            }
          }
          collectionName = name;
        }
        return collectionName;
      };
      if (trainingActionButtons.length && !builder.dataset.trainingTextBound) {
        const isTrainingActionInterruptible = tabKey => tabKey !== "website";
        const setTrainingRunningState = (isRunning, tabKey = "") => {
          const activeTrainingTabKey = tabKey || getActiveTrainingTabKey();
          const activeAddButton = getTrainingActionButton(activeTrainingTabKey);
          if (!activeAddButton) {
            return;
          }
          const canStopTraining = isTrainingActionInterruptible(activeTrainingTabKey);
          if (trainingCard) {
            trainingCard.classList.toggle("is-training", isRunning);
          }
          if (isRunning) {
            trainingAddButtons.forEach(button => {
              const isActiveButton = button === activeAddButton;
              button.disabled = !isActiveButton || !canStopTraining;
              if (isActiveButton && canStopTraining) {
                button.removeAttribute("aria-disabled");
              } else {
                button.setAttribute("aria-disabled", "true");
              }
            });
            updateTrainingActionProgress(getTrainingActionLabels(activeTrainingTabKey).loading, {
              canStop: canStopTraining,
              tabKey: activeTrainingTabKey
            });
          } else {
            trainingAddButtons.forEach(button => {
              button.disabled = false;
              button.removeAttribute("aria-disabled");
            });
            updateTrainingActionLabel(activeTrainingTabKey, false);
            updateFileUploadActionAvailability();
          }
        };
        const canUseFileUpload = () => {
          const toggle = modelPopoverPanel ? modelPopoverPanel.querySelector(".aipkit_file_upload_toggle_select") : null;
          const isPro = toggle ? toggle.dataset.isProPlan === "true" : window.aipkit_chat_config?.isProPlan === true || window.aipkit_dashboard?.isProPlan === true;
          return isPro;
        };
        const trainingFileQueueController = typeof window.aipkit_createChatbotFileQueue === "function" ? window.aipkit_createChatbotFileQueue({
          trainingFileList,
          trainingFilesInput,
          trainingFilesButton,
          trainingFilesDropzone,
          trainingFileQueue,
          trainingFileCount,
          trainingFileRows,
          trainingSelectedFiles,
          getIsTraining: () => isTraining,
          getActiveTrainingTabKey,
          getTrainingActionButton,
          setTrainingActionButtonText,
          updateTrainingActionProgress,
          abortActiveTrainingSourceRequest,
          setTrainingStatus,
          canUseFileUpload,
          __,
          _n,
          sprintf
        }) : null;
        updateFileUploadActionAvailability = () => {
          if (trainingFileQueueController) {
            trainingFileQueueController.updateFileUploadActionAvailability();
            return;
          }
          const button = getTrainingActionButton("files");
          if (!button || isTraining) return;
          const isFilesTab = getActiveTrainingTabKey() === "files";
          if (isFilesTab) {
            setTrainingActionButtonText(button, __("Add files", "gpt3-ai-content-generator"));
            button.setAttribute("aria-disabled", "true");
          } else {
            button.removeAttribute("aria-disabled");
          }
          button.disabled = isFilesTab;
        };
        const updateTrainingDropzoneVisibility = () => {
          if (trainingFileQueueController) {
            trainingFileQueueController.updateTrainingDropzoneVisibility();
            return;
          }
          if (!trainingFilesDropzone) return;
          trainingFilesDropzone.style.display = "";
          trainingFilesDropzone.classList.remove("has-files");
          if (trainingFileQueue) trainingFileQueue.hidden = true;
          if (trainingFileCount) trainingFileCount.textContent = "";
          updateFileUploadActionAvailability();
        };
        trainingFileQueueController?.bindBrowse();
        const handleTrainingAction = async event => {
          const activeTabKey = getTrainingTabKeyForAction(event?.currentTarget);
          if (isTraining) {
            if (!isTrainingActionInterruptible(activeTabKey)) {
              return;
            }
            abortActiveTrainingSourceRequest();
            updateTrainingActionProgress(__("Stopping", "gpt3-ai-content-generator"), {
              tabKey: activeTabKey
            });
            return;
          }
          let textValue = "";
          let sourceType = "chatbot_training_text";
          let filesToUpload = [];
          let websiteSettings = null;
          if (activeTabKey === "text") {
            textValue = trainingTextInput ? trainingTextInput.value.trim() : "";
            if (!textValue) {
              setTrainingStatus("Add text first.", "warning");
              clearTrainingStatusSoon();
              return;
            }
          } else if (activeTabKey === "qa") {
            const question = trainingQaQuestion ? trainingQaQuestion.value.trim() : "";
            const answer = trainingQaAnswer ? trainingQaAnswer.value.trim() : "";
            if (!question || !answer) {
              setTrainingStatus("Add a question and answer first.", "warning");
              clearTrainingStatusSoon();
              return;
            }
            textValue = `Q: ${question}\nA: ${answer}`;
            sourceType = "chatbot_training_qa";
          } else if (activeTabKey === "files") {
            if (!canUseFileUpload()) {
              setTrainingStatus("Upgrade to use file uploads.", "warning");
              return;
            }
            if (!trainingFileQueueController || typeof window.aipkit_createChatbotFileUploader !== "function") {
              setTrainingStatus(__("File uploads are unavailable. Please reload and try again.", "gpt3-ai-content-generator"), "error");
              return;
            }
            filesToUpload = trainingFileQueueController.getSelectedTrainingFiles();
            if (!filesToUpload.length) {
              setTrainingStatus("Choose files first.", "warning");
              clearTrainingStatusSoon();
              updateFileUploadActionAvailability();
              return;
            }
            sourceType = "chatbot_training_file";
          } else if (activeTabKey === "website") {
            const statusValue = getWebsiteStatusValue();
            const postTypes = getWebsitePostTypes();
            if (!postTypes.length) {
              setTrainingStatus("Select at least one post type.", "warning");
              clearTrainingStatusSoon();
              return;
            }
            websiteSettings = {
              statusValue,
              postTypes
            };
          } else {
            setTrainingStatus("Select a training tab to continue.", "warning");
            clearTrainingStatusSoon();
            return;
          }
          const botId = getSelectedBuilderBotId();
          if (!botId) {
            setTrainingStatus("Select a chatbot before training.", "error");
            clearTrainingStatusSoon();
            return;
          }
          const vectorStoreElements = getVectorStoreElements();
          const providerSelect = vectorStoreElements ? vectorStoreElements.providerSelect : null;
          const selectedProvider = providerSelect ? providerSelect.value : "";
          const chatbotProvider = getChatbotProviderValue();
          const validProviders = [ "openai", "google", "pinecone", "qdrant", "chroma", "local" ];
          const knowledgeToggle = modelPopoverPanel && modelPopoverPanel.querySelector(".aipkit_vector_store_enable_select") || builder.querySelector(".aipkit_vector_store_enable_select");
          const knowledgeOn = knowledgeToggle ? (knowledgeToggle.tagName === "SELECT" ? knowledgeToggle.value === "1" : knowledgeToggle.checked) : false;
          const targetSelect = {
            openai: vectorStoreElements.openaiStoresSelect,
            google: vectorStoreElements.googleStoresSelect,
            pinecone: vectorStoreElements.pineconeIndexSelect,
            qdrant: vectorStoreElements.qdrantCollectionsSelect,
            chroma: vectorStoreElements.chromaCollectionsSelect,
            local: vectorStoreElements.localStoresSelect,
          }[selectedProvider];
          const sourceSetup = resolveChatbotSourceSetup({
            knowledgeEnabled: knowledgeOn,
            knowledgeProvider: selectedProvider,
            knowledgeHasTarget: getConfiguredVectorStoreValues(targetSelect).length > 0,
            defaults: getChatbotSourceDefaults(),
          });
          const preferredProvider = sourceSetup.provider;
          if (!validProviders.includes(preferredProvider)) {
            setTrainingStatus(selectedProvider ? "Choose a supported knowledge provider in Chatbot settings." : "Connect an API key to add knowledge.", "error");
            return;
          }
          const preferredCompatibilityMessage = getKnowledgeProviderCompatibilityMessage(chatbotProvider, preferredProvider);
          if (preferredCompatibilityMessage) {
            setTrainingStatus(preferredCompatibilityMessage, "warning");
            clearTrainingStatusSoon();
            return;
          }
          activeFileTrainingRun?.invalidate();
          const fileRun = createChatbotKnowledgeRun({
            builder,
            botSelect,
            getBotId: getSelectedBuilderBotId,
            onInvalidate: () => {
              if (activeFileTrainingRun !== fileRun) return;
              activeFileTrainingRun = null;
              trainingSourceAbortController = null;
              isTraining = false;
              closeTrainingSourceWhenStopped = false;
              setTrainingRunningState(false, activeTabKey);
              updateTrainingDropzoneVisibility();
            }
          });
          activeFileTrainingRun = fileRun;
          isTraining = true;
          window.aipkit_isIndexingStopped = false;
          trainingSourceAbortController = fileRun;
          setTrainingRunningState(true, activeTabKey);
          if (activeTabKey === "website") {
            setTrainingStatus("", "");
          } else {
            setTrainingStatus("Preparing", "loading");
          }
          const getRequiredTrainingEmbeddingConfig = (includeDimension = false) => {
            const embeddingConfig = ensureEmbeddingConfig(sourceSetup.useDefaults);
            const provider = embeddingConfig.provider;
            const model = embeddingConfig.model;
            if (!provider || !model) {
              throw new Error("Select an embedding provider and model.");
            }
            return {
              provider,
              model,
              dimension: includeDimension ? getEmbeddingModelDimension(provider, model) : null
            };
          };
          try {
            let embeddingProvider = "";
            let embeddingModel = "";
            let targetStoreId = "";
            let pineconeIndexName = "";
            let qdrantCollectionName = "";
            let chromaCollectionName = "";
            let localStoreId = "";
            if (preferredProvider === "local") {
              const embeddingConfig = getRequiredTrainingEmbeddingConfig(true);
              embeddingProvider = embeddingConfig.provider;
              embeddingModel = embeddingConfig.model;
              localStoreId = await ensureLocalStore(botId, embeddingConfig.dimension);
              targetStoreId = localStoreId;
            } else if (preferredProvider === "openai") {
              if (!getVectorProviderKeyState().openai) {
                throw new Error("OpenAI API key missing.");
              }
              targetStoreId = await ensureOpenAIStore(botId);
            } else if (preferredProvider === "google") {
              if (!getVectorProviderKeyState().google) {
                throw new Error("Google API key missing.");
              }
              targetStoreId = await ensureGoogleStore(botId);
            } else if (preferredProvider === "pinecone") {
              const keys = getVectorProviderKeyState();
              if (!keys.pinecone) {
                throw new Error("Pinecone API key missing.");
              }
              const embeddingConfig = getRequiredTrainingEmbeddingConfig(true);
              embeddingProvider = embeddingConfig.provider;
              embeddingModel = embeddingConfig.model;
              pineconeIndexName = await ensurePineconeIndex(botId, embeddingConfig.dimension);
            } else if (preferredProvider === "qdrant") {
              const keys = getVectorProviderKeyState();
              if (!keys.qdrant) {
                throw new Error("Qdrant URL and API key are required.");
              }
              const embeddingConfig = getRequiredTrainingEmbeddingConfig(true);
              embeddingProvider = embeddingConfig.provider;
              embeddingModel = embeddingConfig.model;
              qdrantCollectionName = await ensureQdrantCollection(botId, embeddingConfig.dimension);
              targetStoreId = qdrantCollectionName;
            } else if (preferredProvider === "chroma") {
              const keys = getVectorProviderKeyState();
              if (!keys.chroma) {
                throw new Error("Chroma URL is required.");
              }
              const embeddingConfig = getRequiredTrainingEmbeddingConfig();
              embeddingProvider = embeddingConfig.provider;
              embeddingModel = embeddingConfig.model;
              chromaCollectionName = await ensureChromaCollection(botId);
              targetStoreId = chromaCollectionName;
            }
            fileRun?.assertCurrent();
            if (knowledgeToggle) {
              if (knowledgeToggle.tagName === "SELECT") knowledgeToggle.value = "1";
              else knowledgeToggle.checked = true;
            }
            if (providerSelect) providerSelect.value = preferredProvider;
            const activationState = getChatbotCapabilityState({
              knowledgeEnabled: true,
              knowledgeProvider: preferredProvider
            });
            if (activationState.shouldDisableGoogleSearchForKnowledge) {
              setGoogleSearchEnabledWithoutChangeEvent(false);
              saveWebSettingsAfterKnowledgeChange();
            }
            updateContextPopoverControls();
            updateVectorStoreVisibility();
            const contextSettings = getContextSettings();
            await saveContextSettingsBeforeKnowledgeUpdate(contextSettings, () => fileRun?.assertCurrent());
            fileRun?.assertCurrent();
            syncWebsiteTargetSelect(contextSettings);
            if (activeTabKey === "files") {
              updateTrainingDropzoneVisibility();
              const uploadTrainingFile = window.aipkit_createChatbotFileUploader({
                getAbortSignal: () => fileRun.signal,
                isRunCurrent: fileRun.isOwner,
                retainRun: fileRun.retain,
                getContextSettings,
                getSelectedBuilderBotId,
                getNonceValue,
                setTrainingFileStatus: trainingFileQueueController.setTrainingFileStatus,
                setTrainingStatus,
                refreshTrainingSourcesAfterMutation,
                pollGoogleFileSearchJob
              });
              let failedFileCount = 0;
              let batchError = null;
              for (let index = 0; index < filesToUpload.length; index += 1) {
                fileRun.assertCurrent();
                const file = filesToUpload[index];
                setTrainingStatus(`Uploading ${index + 1} of ${filesToUpload.length}`, "loading");
                trainingFileQueueController.setTrainingFileStatus(file, "Uploading", "loading");
                try {
                  await uploadTrainingFile({
                    file,
                    preferredProvider,
                    targetStoreId,
                    sourceType,
                    pineconeIndexName,
                    qdrantCollectionName,
                    chromaCollectionName,
                    embeddingProvider,
                    embeddingModel
                  });
                  fileRun.assertCurrent();
                  trainingSelectedFiles.delete(trainingFileQueueController.getTrainingFileKey(file));
                } catch (error) {
                  if (!fileRun.isOwner()) throw error;
                  const isStopped = isTrainingSourceStoppedError(error);
                  trainingFileQueueController.setTrainingFileStatus(file, isStopped ? "Stopped" : error?.message || "Failed", isStopped ? "warning" : "error");
                  if (isStopped) {
                    throw error;
                  }
                  failedFileCount += 1;
                  if (error?.stop_batch) { batchError = error; break; }
                }
              }
              if (trainingFilesInput) {
                trainingFilesInput.value = "";
              }
              updateTrainingDropzoneVisibility();
              updateFileUploadActionAvailability();
              if (batchError) {
                setTrainingStatus(batchError.message, "error");
              } else if (failedFileCount > 0) {
                const trainedFileCount = filesToUpload.length - failedFileCount;
                const failureMessage = sprintf(_n("%d file failed.", "%d files failed.", failedFileCount, "gpt3-ai-content-generator"), failedFileCount);
                setTrainingStatus(trainedFileCount > 0 ? failureMessage : __("Files failed.", "gpt3-ai-content-generator"), trainedFileCount > 0 ? "warning" : "error");
              } else {
                setTrainingStatus("Saved", "success");
              }
              if (!batchError) clearTrainingStatusSoon();
              refreshTrainingSourcesAfterMutation();
              isTraining = false;
              setTrainingRunningState(false, activeTabKey);
              if (typeof window.aipkit_refreshSourcesSheet === "function") {
                window.aipkit_refreshSourcesSheet();
              }
              if (failedFileCount === 0 && typeof window.aipkit_closeTrainingSourcePopover === "function") {
                window.aipkit_closeTrainingSourcePopover();
              }
              return;
            } else if (activeTabKey === "website" && websiteSettings) {
              const {statusValue, postTypes} = websiteSettings;
              if (window.aipkit_isIndexingStopped === true) {
                throw new Error("Adding stopped.");
              }
              const summary = await fetchBulkSummary(postTypes, statusValue, targetStoreId);
              fileRun.assertCurrent();
              if (window.aipkit_isIndexingStopped === true) {
                throw new Error("Adding stopped.");
              }
              if (!summary.total) {
                setTrainingStatus("No posts found for selection.", "warning");
                clearTrainingStatusSoon();
                return;
              }
              updateTrainingActionProgress(getTrainingActionLabels("website").loading, {
                tabKey: activeTabKey
              });
              if (window.aipkit_isIndexingStopped === true) {
                throw new Error("Adding stopped.");
              }
              const taskResponse = await createBackgroundIndexingTask({
                botId,
                postTypes,
                provider: preferredProvider,
                targetStoreId: preferredProvider === "openai" || preferredProvider === "google" || preferredProvider === "local" ? targetStoreId : preferredProvider === "pinecone" ? pineconeIndexName : preferredProvider === "qdrant" ? qdrantCollectionName : chromaCollectionName,
                embeddingProvider,
                embeddingModel
              });
              const taskId = taskResponse?.task_id ? String(taskResponse.task_id) : "";
              if (fileRun.isOwner()) currentWebsiteTrainingTaskId = taskId;
              if (fileRun.isOwner() && window.aipkit_isIndexingStopped === true) {
                const stopPayload = buildTrainingStatusPayload(getContextSettings());
                if (stopPayload && currentWebsiteTrainingTaskId) {
                  stopPayload.task_id = currentWebsiteTrainingTaskId;
                  await window.aipkit_apiRequest("aipkit_stop_chatbot_training", stopPayload);
                  currentWebsiteTrainingTaskId = "";
                }
                const stoppedError = new Error("Adding stopped.");
                stoppedError.code = "aipkit_vector_file_job_stopped";
                throw stoppedError;
              }
              fileRun.assertCurrent();
              setDurableTrainingStatus({
                key: "training",
                label: __("Adding knowledge", "gpt3-ai-content-generator"),
                type: "loading"
              });
              setTrainingStatus("", "");
              refreshTrainingSourcesAfterMutation(getContextSettings());
              isTraining = false;
              setTrainingRunningState(false, activeTabKey);
              if (typeof window.aipkit_refreshSourcesSheet === "function") {
                window.aipkit_refreshSourcesSheet();
              }
              if (typeof window.aipkit_closeTrainingSourcePopover === "function") {
                window.aipkit_closeTrainingSourcePopover();
              }
            } else {
              setTrainingStatus(getTrainingActionLabels(activeTabKey).loading, "loading");
              if (preferredProvider === "openai") {
                const openaiNonce = getNonceValue("aipkit_vector_store_nonce_openai");
                if (!openaiNonce) {
                  throw new Error("OpenAI vector store nonce missing.");
                }
                await trainingSourceApiRequest("aipkit_add_text_to_vector_store_openai", {
                  _ajax_nonce: openaiNonce,
                  target_store_id: targetStoreId,
                  text_content: textValue,
                  source_type: sourceType
                });
              } else if (preferredProvider === "google") {
                const googleNonce = getNonceValue("aipkit_google_file_search_nonce");
                if (!googleNonce) {
                  throw new Error("Google File Search nonce missing.");
                }
                const googleResponse = await trainingSourceApiRequest("aipkit_add_text_to_google_file_search", {
                  _ajax_nonce: googleNonce,
                  target_store_id: targetStoreId,
                  text_content: textValue,
                  source_type: sourceType
                });
                if (googleResponse?.job_id && String(googleResponse?.status || "").toLowerCase() !== "indexed") {
                  pollGoogleFileSearchJob(googleResponse.job_id);
                }
              } else if (preferredProvider === "pinecone") {
                const pineconeNonce = getNonceValue("aipkit_vector_store_pinecone_nonce_management");
                if (!pineconeNonce) {
                  throw new Error("Pinecone nonce missing.");
                }
                const vectorId = `text_${Date.now()}`;
                const metadata = {
                  source: sourceType,
                  created_at: (new Date).toISOString(),
                  vector_id: vectorId
                };
                await trainingSourceApiRequest("aipkit_upsert_to_pinecone_index", {
                  _ajax_nonce: pineconeNonce,
                  text_content: textValue,
                  metadata: JSON.stringify(metadata),
                  embedding_provider: embeddingProvider,
                  embedding_model: embeddingModel,
                  original_text_content: textValue,
                  index_name: pineconeIndexName,
                  source_type: sourceType,
                  source_context: "chatbot_settings_training"
                });
              } else if (preferredProvider === "local") {
                const localNonce = getNonceValue("aipkit_vector_store_local_nonce_management");
                if (!localNonce) {
                  throw new Error("Knowledge base nonce missing.");
                }
                const vectorId = `text_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;
                await trainingSourceApiRequest("aipkit_local_add_text", {
                  _ajax_nonce: localNonce,
                  store_id: localStoreId,
                  embedding_provider: embeddingProvider,
                  embedding_model: embeddingModel,
                  text_content: textValue,
                  metadata: JSON.stringify({ source: sourceType, created_at: (new Date).toISOString(), vector_id: vectorId }),
                  vector_id: vectorId,
                  source_type: sourceType
                });
              } else if (preferredProvider === "qdrant") {
                const qdrantNonce = getNonceValue("aipkit_vector_store_qdrant_nonce_management");
                if (!qdrantNonce) {
                  throw new Error("Qdrant nonce missing.");
                }
                const vectorId = createQdrantPointId();
                const metadata = {
                  source: sourceType,
                  created_at: (new Date).toISOString(),
                  vector_id: vectorId
                };
                const payload = {
                  _ajax_nonce: qdrantNonce,
                  text_content: textValue,
                  metadata: JSON.stringify(metadata),
                  embedding_provider: embeddingProvider,
                  embedding_model: embeddingModel,
                  original_text_content: textValue,
                  collection_name: qdrantCollectionName,
                  source_type: sourceType,
                  source_context: "chatbot_settings_training"
                };
                await trainingSourceApiRequest("aipkit_upsert_to_qdrant_collection", payload);
              } else if (preferredProvider === "chroma") {
                const chromaNonce = getNonceValue("aipkit_vector_store_chroma_nonce_management");
                if (!chromaNonce) {
                  throw new Error("Chroma nonce missing.");
                }
                const vectorId = createChromaRecordId();
                const metadata = {
                  source: sourceType,
                  created_at: (new Date).toISOString(),
                  vector_id: vectorId
                };
                await trainingSourceApiRequest("aipkit_upsert_to_chroma_collection", {
                  _ajax_nonce: chromaNonce,
                  text_content: textValue,
                  metadata: JSON.stringify(metadata),
                  embedding_provider: embeddingProvider,
                  embedding_model: embeddingModel,
                  original_text_content: textValue,
                  collection_name: chromaCollectionName,
                  source_type: sourceType,
                  source_context: "chatbot_settings_training"
                });
              }
              fileRun.assertCurrent();
              if (window.aipkit_isIndexingStopped === true) {
                throw new Error("Adding stopped.");
              }
              if (activeTabKey === "text" && trainingTextInput) {
                trainingTextInput.value = "";
              }
              if (activeTabKey === "qa") {
                if (trainingQaQuestion) {
                  trainingQaQuestion.value = "";
                }
                if (trainingQaAnswer) {
                  trainingQaAnswer.value = "";
                }
              }
              setTrainingStatus("Saved", "success");
              clearTrainingStatusSoon();
              refreshTrainingSourcesAfterMutation();
              isTraining = false;
              setTrainingRunningState(false, activeTabKey);
              if (typeof window.aipkit_refreshSourcesSheet === "function") {
                window.aipkit_refreshSourcesSheet();
              }
              if (typeof window.aipkit_closeTrainingSourcePopover === "function") {
                window.aipkit_closeTrainingSourcePopover();
              }
            }
          } catch (error) {
            if (fileRun && !fileRun.isOwner()) return;
            if (isTrainingSourceStoppedError(error)) {
              if (activeTabKey === "website") {
                setTrainingStatus("", "");
              } else {
                setTrainingStatus("Stopped.", "warning");
                clearTrainingStatusSoon();
              }
              if (typeof window.aipkit_refreshSourcesSheet === "function") {
                window.aipkit_refreshSourcesSheet();
              }
              updateTrainingSourcesCount();
            } else {
              const rawErrorMessage = error?.message || "";
              const missingProviderConfig = isMissingProviderCredentialMessage(rawErrorMessage, {
                includeUrl: true
              }) || /api key|qdrant url|chroma url|connection url|base url|endpoint/i.test(rawErrorMessage);
              const knowledgeSettingsSaveFailed = error?.code === "aipkit_knowledge_settings_save_failed";
              setTrainingStatus(missingProviderConfig ? "Connect an API key to add knowledge." : knowledgeSettingsSaveFailed ? rawErrorMessage || __("Could not save knowledge settings. Please try again.", "gpt3-ai-content-generator") : `Error: ${rawErrorMessage || "Adding knowledge failed."}`, "error");
            }
          } finally {
            if (!fileRun || activeFileTrainingRun === fileRun) {
              const shouldCloseTrainingSource = closeTrainingSourceWhenStopped;
              closeTrainingSourceWhenStopped = false;
              isTraining = false;
              trainingSourceAbortController = null;
              setTrainingRunningState(false, activeTabKey);
              updateTrainingDropzoneVisibility();
              if (shouldCloseTrainingSource) {
                finalizeTrainingSourceDiscard();
              }
            }
            fileRun?.release();
          }
        };
        trainingFileQueueController?.bindSelection();
        trainingActionButtons.forEach(button => {
          button.addEventListener("click", handleTrainingAction);
        });
        updateFileUploadActionAvailability();
        updateTrainingSourcesCount();
        startTrainingAutoSync();
        builder.dataset.trainingTextBound = "1";
      }
      if (sourcesSheetSection && !builder.dataset.sourcesSheetBound) {
        const escaper = window.aipkit_escapeHtml || function(value) {
          return value;
        };
        const providerLabels = {
          openai: "OpenAI",
          pinecone: "Pinecone",
          qdrant: "Qdrant",
          chroma: "Chroma",
          local: "Local",
          claude_files: "Anthropic Files",
          google: "Google"
        };
        const sourcesState = {
          page: 1,
          search: "",
          type: "",
          status: "",
          pageCursors: [ null ]
        };
        let searchTimeout = null;
        let openSourcesActionMenu = null;
        let sourcesFetchGeneration = 0;
        const normalizeSourceProviderKey = value => String(value || "").trim().toLowerCase();
        const truncateSourceText = (text, maxLength) => {
          const safeText = String(text || "");
          return safeText.length > maxLength ? `${safeText.slice(0, maxLength)}...` : safeText;
        };
        const {
          getStatusMeta: getSourceStatusMeta,
          isQaTextSource: isQaSourceText,
          getContentTypeMeta: getSourceContentTypeMeta,
          getSourceDisplay,
          getSourceChunkMeta
        } = createChatbotSourceRecords({
          __,
          normalizeProviderKey: normalizeSourceProviderKey
        });
        const {renderSourceAction, renderSourceTargetAttributes, renderSourceActionMenu} = createSourceActionMenu({
          __,
          escaper
        });
        const getSourceTypeIcon = contentType => {
          if (contentType?.key === "site") {
            return "dashicons-admin-site-alt3";
          }
          if (contentType?.key === "file") {
            return "dashicons-media-document";
          }
          return "dashicons-format-chat";
        };
        const closeSourcesActionMenu = () => {
          if (!openSourcesActionMenu) {
            return;
          }
          const {menu, panel, trigger, originalParent, nextSibling} = openSourcesActionMenu;
          if (panel) {
            panel.hidden = true;
            panel.style.left = "";
            panel.style.top = "";
            if (originalParent && originalParent.isConnected && panel.parentNode !== originalParent) {
              originalParent.insertBefore(panel, nextSibling && nextSibling.parentNode === originalParent ? nextSibling : null);
            }
          }
          if (trigger) {
            trigger.setAttribute("aria-expanded", "false");
          }
          if (menu) {
            menu.classList.remove("is-open");
          }
          openSourcesActionMenu = null;
        };
        const positionSourcesActionMenu = (trigger, panel) => {
          if (!trigger || !panel) {
            return;
          }
          const triggerRect = trigger.getBoundingClientRect();
          const panelWidth = panel.offsetWidth || 160;
          const panelHeight = panel.offsetHeight || 120;
          const edgeGap = 12;
          const verticalGap = 6;
          const maxLeft = Math.max(edgeGap, window.innerWidth - panelWidth - edgeGap);
          const left = Math.min(maxLeft, Math.max(edgeGap, triggerRect.right - panelWidth));
          let top = triggerRect.bottom + verticalGap;
          if (top + panelHeight > window.innerHeight - edgeGap) {
            top = Math.max(edgeGap, triggerRect.top - panelHeight - verticalGap);
          }
          let panelLeft = left;
          let panelTop = top;
          const sheetPanel = panel.closest(".aipkit_builder_sheet_panel");
          if (sheetPanel) {
            const sheetRect = sheetPanel.getBoundingClientRect();
            panelLeft = left - sheetRect.left;
            panelTop = top - sheetRect.top;
          }
          panel.style.left = `${Math.round(panelLeft)}px`;
          panel.style.top = `${Math.round(panelTop)}px`;
        };
        const toggleSourcesActionMenu = trigger => {
          if (!trigger || trigger.disabled) {
            return;
          }
          if (openSourcesActionMenu && openSourcesActionMenu.trigger === trigger) {
            closeSourcesActionMenu();
            return;
          }
          const menu = trigger.closest(".aipkit_sources_actions");
          const panel = menu ? menu.querySelector(".aipkit_sources_action_menu_panel") : null;
          if (!menu || !panel) {
            return;
          }
          closeSourcesActionMenu();
          const originalParent = panel.parentNode;
          const nextSibling = panel.nextSibling;
          if (sourcesSheetSection && panel.parentNode !== sourcesSheetSection) {
            sourcesSheetSection.appendChild(panel);
          }
          menu.classList.add("is-open");
          panel.hidden = false;
          trigger.setAttribute("aria-expanded", "true");
          positionSourcesActionMenu(trigger, panel);
          openSourcesActionMenu = {
            menu,
            panel,
            trigger,
            originalParent,
            nextSibling
          };
        };
        const setSourcesStatus = (text, type) => {
          if (!sourcesStatus) {
            return;
          }
          sourcesStatus.textContent = text || "";
          sourcesStatus.classList.remove("aipkit_form-help-success", "aipkit_form-help-error");
          if (type === "success") {
            sourcesStatus.classList.add("aipkit_form-help-success");
          }
          if (type === "error") {
            sourcesStatus.classList.add("aipkit_form-help-error");
          }
        };
        const clearSourcesStatusSoon = () => {
          if (!sourcesStatus) {
            return;
          }
          setTimeout(() => {
            sourcesStatus.textContent = "";
            sourcesStatus.classList.remove("aipkit_form-help-success", "aipkit_form-help-error");
          }, 2500);
        };
        const getSourcesContext = () => {
          const contextSettings = getContextSettings();
          const providerKey = contextSettings.vector_store_provider || "";
          const providerLabel = providerLabels[providerKey] || "";
          let storeIds = [];
          if (providerKey === "openai") {
            storeIds = Array.isArray(contextSettings.openai_vector_store_ids) ? contextSettings.openai_vector_store_ids.filter(Boolean) : [];
          } else if (providerKey === "google") {
            storeIds = Array.isArray(contextSettings.google_file_search_store_names) ? contextSettings.google_file_search_store_names.filter(Boolean) : [];
          } else if (providerKey === "pinecone") {
            if (contextSettings.pinecone_index_name) {
              storeIds = [ contextSettings.pinecone_index_name ];
            }
          } else if (providerKey === "qdrant") {
            storeIds = Array.isArray(contextSettings.qdrant_collection_names) ? contextSettings.qdrant_collection_names.filter(Boolean) : [];
          } else if (providerKey === "chroma") {
            storeIds = Array.isArray(contextSettings.chroma_collection_names) ? contextSettings.chroma_collection_names.filter(Boolean) : [];
          } else if (providerKey === "local") {
            storeIds = Array.isArray(contextSettings.local_store_ids) ? contextSettings.local_store_ids.filter(Boolean) : [];
          }
          return {
            contextSettings,
            providerKey,
            providerLabel,
            storeIds
          };
        };
        const renderSourcesEmpty = message => {
          if (!sourcesTableBody) {
            return;
          }
          closeSourcesActionMenu();
          sourcesTableBody.innerHTML = `<tr><td colspan="4" class="aipkit_text-center">${escaper(message)}</td></tr>`;
          if (sourcesPagination) {
            sourcesPagination.innerHTML = "";
          }
        };
        const renderSourcesRows = (logs, providerLabel) => {
          if (!sourcesTableBody) {
            return;
          }
          if (!logs.length) {
            renderSourcesEmpty("No trained content found.");
            return;
          }
          closeSourcesActionMenu();
          const rows = logs.map(log => {
            const providerKey = normalizeSourceProviderKey(log.provider || providerLabel || "");
            const actionProvider = providerLabels[providerKey] || log.provider || providerLabel || "";
            const rowProviderLabel = providerLabels[providerKey] || log.provider || providerLabel || "—";
            const contentType = getSourceContentTypeMeta(log, providerKey);
            const statusMeta = getSourceStatusMeta(log.status);
            const isProcessing = log.status === "processing" || log.status === "queued";
            const updatedMeta = getSourceUpdatedMeta(log);
            const sourceDisplay = String(getSourceDisplay(log) || "—");
            const truncatedSource = truncateSourceText(sourceDisplay, 60);
            const chunkMeta = getSourceChunkMeta(log);
            const sourceTitle = chunkMeta ? `${sourceDisplay} · ${chunkMeta.label}` : sourceDisplay;
            const sourceTypeIcon = getSourceTypeIcon(contentType);
            const snippet = log.indexed_content || "";
            const sourceViewLog = {
              provider: log.provider || rowProviderLabel,
              post_id: log.post_id || "",
              post_title: log.post_title || "",
              message: log.message || "",
              file_id: log.file_id || "",
              vector_store_id: log.vector_store_id || "",
              vector_store_name: log.vector_store_name || ""
            };
            const actions = [];
            if (snippet) {
              actions.push(renderSourceAction(`data-file-id="${escaper(log.file_id || "")}"\n                      data-snippet="${escaper(snippet)}"\n                      data-store-id="${escaper(log.vector_store_id || "")}"\n                      data-store-name="${escaper(log.vector_store_name || "")}"\n                      data-source-log="${escaper(JSON.stringify(sourceViewLog))}"`, "aipkit_sources_action_view aipkit_view_snippet_icon", "dashicons-visibility", __("View", "gpt3-ai-content-generator"), isProcessing));
            }
            if (contentType.key === "site" && log.post_id) {
              actions.push(renderSourceAction(`${renderSourceTargetAttributes(log, actionProvider)}\n                      data-post-id="${escaper(log.post_id || "")}"\n                      data-embedding-provider="${escaper(log.embedding_provider || "")}"\n                      data-embedding-model="${escaper(log.embedding_model || "")}"`, "aipkit_sources_action_retrain", "dashicons-update", isProcessing ? __("Updating...", "gpt3-ai-content-generator") : __("Update", "gpt3-ai-content-generator"), isProcessing));
            }
            if (contentType.key === "text" && snippet) {
              actions.push(renderSourceAction(`${renderSourceTargetAttributes(log, actionProvider)}\n                      data-embedding-provider="${escaper(log.embedding_provider || "")}"\n                      data-embedding-model="${escaper(log.embedding_model || "")}"\n                      data-source-kind="${isQaSourceText(log) ? "qa" : "text"}"\n                      data-content="${escaper(encodeURIComponent(snippet))}"`, "aipkit_sources_action_edit", "dashicons-edit", isProcessing ? __("Updating...", "gpt3-ai-content-generator") : __("Edit", "gpt3-ai-content-generator"), isProcessing));
            }
            if (log.file_id) {
              const deleteDividerClass = actions.length ? " aipkit_sources_action_menu_item--separated" : "";
              actions.push(renderSourceAction(renderSourceTargetAttributes(log, actionProvider), `aipkit_sources_action_menu_item--danger${deleteDividerClass} aipkit_sources_action_delete`, "dashicons-trash", __("Delete", "gpt3-ai-content-generator"), isProcessing));
            }
            const actionMenu = renderSourceActionMenu(actions);
            return `<tr data-log-id="${escaper(log.id || "")}">\n                <td class="aipkit_sources_status_cell">\n                  <div class="aipkit_sources_status_wrap">\n                    <span class="aipkit_status-tag ${escaper(statusMeta.className)}">${escaper(statusMeta.label)}</span>\n                  </div>\n                </td>\n                <td class="aipkit_sources_source_cell" title="${escaper(sourceTitle)}">\n                  <div class="aipkit_sources_source_identity">\n                    <span class="aipkit_sources_source_icon dashicons ${escaper(sourceTypeIcon)}" role="img" aria-label="${escaper(contentType.label)}" title="${escaper(contentType.label)}"></span>\n                    <div class="aipkit_sources_source_stack">\n                      <span class="aipkit_sources_source_title_line">\n                        <span class="aipkit_sources_source_title">${escaper(truncatedSource)}</span>\n                        ${chunkMeta ? `<span class="aipkit_sources_chunk_badge">${escaper(chunkMeta.label)}</span>` : ""}\n                      </span>\n                    </div>\n                  </div>\n                </td>\n                <td class="aipkit_sources_time_cell" title="${escaper(updatedMeta.title)}">\n                  <span class="aipkit_sources_time_value">${escaper(updatedMeta.label)}</span>\n                </td>\n                <td class="aipkit_actions_cell aipkit_sources_actions_cell">\n                  ${actionMenu}\n                </td>\n              </tr>`;
          }).join("");
          sourcesTableBody.innerHTML = rows;
          if (typeof window.aipkit_attachSnippetModalListeners === "function") {
            window.aipkit_attachSnippetModalListeners(sourcesTableBody, providerLabel);
          }
        };
        let sourcesRefreshTimer;
        let hasProcessingSources = false;
        const fetchSourcesPage = async (page = 1, silent = false) => {
          window.clearTimeout(sourcesRefreshTimer);
          if (!sourcesTableBody || typeof window.aipkit_apiRequest !== "function") {
            return;
          }
          const botId = getSelectedBuilderBotId();
          if (!botId) {
            renderSourcesEmpty("Select a chatbot to view trained content.");
            return;
          }
          const {contextSettings, providerKey, providerLabel, storeIds} = getSourcesContext();
          const requestGeneration = ++sourcesFetchGeneration;
          const requestSignature = JSON.stringify({
            botId: String(botId),
            providerKey,
            storeIds,
            page,
            search: sourcesState.search,
            type: sourcesState.type,
            status: sourcesState.status
          });
          const isCurrentRequest = () => {
            const currentContext = getSourcesContext();
            return requestGeneration === sourcesFetchGeneration && requestSignature === JSON.stringify({
              botId: String(getSelectedBuilderBotId() || ""),
              providerKey: currentContext.providerKey,
              storeIds: currentContext.storeIds,
              page,
              search: sourcesState.search,
              type: sourcesState.type,
              status: sourcesState.status
            });
          };
          if (!providerKey || !storeIds.length) {
            hasProcessingSources = false;
            renderSourcesEmpty("Train content to view it here.");
            return;
          }
          if (page === 1) {
            sourcesState.pageCursors = [ null ];
          }
          const pageCursor = sourcesState.pageCursors[Math.max(0, page - 1)] || null;
          if (!silent) {
            sourcesTableBody.innerHTML = `<tr><td colspan="4" class="aipkit_text-center"><span class="aipkit_spinner" style="display:inline-block;"></span> Loading trained content...</td></tr>`;
            if (sourcesPagination) sourcesPagination.innerHTML = "";
          }
          try {
            const payload = {
              bot_id: botId,
              page,
              include_total: "0",
              cursor_mode: "1",
              cursor_timestamp: pageCursor?.timestamp || "",
              cursor_id: pageCursor?.id || 0,
              search: sourcesState.search,
              source_type: sourcesState.type,
              status: sourcesState.status,
              include_inactive: "1",
              enable_vector_store: contextSettings.enable_vector_store,
              vector_store_provider: contextSettings.vector_store_provider,
              openai_vector_store_ids: contextSettings.openai_vector_store_ids || [],
              google_file_search_store_names: contextSettings.google_file_search_store_names || [],
              pinecone_index_name: contextSettings.pinecone_index_name,
              qdrant_collection_names: contextSettings.qdrant_collection_names || [],
              chroma_collection_names: contextSettings.chroma_collection_names || [],
              local_store_ids: contextSettings.local_store_ids || []
            };
            const response = await window.aipkit_apiRequest("aipkit_get_chatbot_training_sources", payload);
            if (!isCurrentRequest()) {
              return;
            }
            sourcesState.page = page;
            const nextCursor = response.pagination?.next_cursor || null;
            sourcesState.pageCursors = sourcesState.pageCursors.slice(0, page);
            if (nextCursor?.timestamp && Number(nextCursor?.id || 0) > 0) {
              sourcesState.pageCursors[page] = nextCursor;
            }
            renderSourcesRows(response.logs || [], providerLabel);
            hasProcessingSources = (response.logs || []).some(log => [ 'processing', 'queued' ].includes(log.status));
            if (typeof window.aipkit_renderLogsPagination === "function" && response.pagination && sourcesPagination) {
              window.aipkit_renderLogsPagination(response.pagination, sourcesPagination, newPage => fetchSourcesPage(newPage));
            }
          } catch (error) {
            if (!isCurrentRequest()) {
              return;
            }
            if (!silent) {
              renderSourcesEmpty(`Error loading trained content: ${escaper(error.message || "Unknown error")}`);
            }
          } finally {
            if (hasProcessingSources && isCurrentRequest()) {
              const refreshWhenVisible = () => {
                if (!isCurrentRequest() || !builder.isConnected || !sheetOverlay?.classList.contains('aipkit-active') || sourcesSheetSection.hidden) return;
                if (document.visibilityState === 'hidden') {
                  sourcesRefreshTimer = window.setTimeout(refreshWhenVisible, 1e4);
                  return;
                }
                fetchSourcesPage(page, true);
              };
              sourcesRefreshTimer = window.setTimeout(refreshWhenVisible, 1e4);
            }
          }
        };
        const refreshSourcesSheet = (page = 1) => {
          if (!sheetOverlay || !sheetOverlay.classList.contains("aipkit-active") || sourcesSheetSection.hidden) {
            return;
          }
          fetchSourcesPage(page);
        };
        window.aipkit_refreshSourcesSheet = refreshSourcesSheet;
        const deleteSourceFromSheet = async details => {
          const providerLabel = details.providerLabel || "";
          const storeId = details.storeId || "";
          const vectorId = details.vectorId || "";
          const logId = details.logId || "";
          if (!providerLabel || !storeId || !vectorId || !logId) {
            setSourcesStatus("Missing content details.", "error");
            clearSourcesStatusSoon();
            return;
          }
          let row = null;
          let editButton = null;
          let retrainButton = null;
          let viewButton = null;
          let deleteButton = null;
          if (sourcesTableBody && logId) {
            const safeId = typeof CSS !== "undefined" && CSS.escape ? CSS.escape(String(logId)) : String(logId).replace(/[^a-zA-Z0-9_-]/g, "\\$&");
            row = sourcesTableBody.querySelector(`tr[data-log-id="${safeId}"]`);
            if (row) {
              editButton = row.querySelector(".aipkit_sources_action_edit");
              retrainButton = row.querySelector(".aipkit_sources_action_retrain");
              viewButton = row.querySelector(".aipkit_view_snippet_icon");
              deleteButton = row.querySelector(".aipkit_sources_action_delete");
              [ editButton, retrainButton, viewButton, deleteButton ].forEach(button => {
                if (!button) {
                  return;
                }
                button.disabled = true;
                button.setAttribute("aria-disabled", "true");
              });
              if (deleteButton) {
                deleteButton.textContent = "Deleting...";
              }
            }
          }
          setSourcesStatus("Deleting...", "");
          try {
            await window.aipkit_apiRequest("aipkit_delete_vector_data_source_entry", {
              provider: providerLabel,
              store_id: storeId,
              vector_id: vectorId,
              log_id: logId
            });
            setSourcesStatus("Deleted", "success");
            clearSourcesStatusSoon();
            refreshSourcesSheet(sourcesState.page || 1);
            refreshTrainingSourcesAfterMutation(getContextSettings());
          } catch (error) {
            setSourcesStatus(`Error: ${error.message || "Failed to delete."}`, "error");
            [ editButton, retrainButton, viewButton, deleteButton ].forEach(button => {
              if (!button) {
                return;
              }
              button.disabled = false;
              button.removeAttribute("aria-disabled");
            });
            if (deleteButton) {
              deleteButton.textContent = "Delete";
            }
          }
        };
        const confirmSourceDelete = details => {
          const message = __("This permanently deletes the selected source from your knowledge base. This cannot be undone.", "gpt3-ai-content-generator");
          const runDelete = () => deleteSourceFromSheet(details);
          if (typeof window.aipkit_showConfirmModal === "function") {
            window.aipkit_showConfirmModal(message, {
              title: __("Delete source", "gpt3-ai-content-generator"),
              confirmText: __("Delete", "gpt3-ai-content-generator"),
              cancelText: __("Cancel", "gpt3-ai-content-generator"),
              variant: "danger",
              onConfirm: runDelete
            });
            return;
          }
          if (window.confirm(message)) {
            runDelete();
          }
        };
        const upsertEditedText = async (providerLabel, storeId, text, embeddingProvider, embeddingModel, sourceKind = "text") => {
          const providerKey = providerLabel.toLowerCase();
          const sourceType = sourceKind === "qa" ? "chatbot_training_qa" : "chatbot_training_text";
          if (providerKey === "openai") {
            const openaiNonce = getNonceValue("aipkit_vector_store_nonce_openai");
            if (!openaiNonce) {
              throw new Error("OpenAI nonce missing.");
            }
            await window.aipkit_apiRequest("aipkit_add_text_to_vector_store_openai", {
              _ajax_nonce: openaiNonce,
              target_store_id: storeId,
              text_content: text,
              source_type: sourceType
            });
            return;
          }
          if (providerKey === "google" || providerKey === "google file search") {
            const googleNonce = getNonceValue("aipkit_google_file_search_nonce");
            if (!googleNonce) {
              throw new Error("Google File Search nonce missing.");
            }
            const googleResponse = await window.aipkit_apiRequest("aipkit_add_text_to_google_file_search", {
              _ajax_nonce: googleNonce,
              target_store_id: storeId,
              text_content: text,
              source_type: sourceType
            });
            if (googleResponse?.job_id && String(googleResponse?.status || "").toLowerCase() !== "indexed") {
              pollGoogleFileSearchJob(googleResponse.job_id);
            }
            return;
          }
          if (!embeddingProvider || !embeddingModel) {
            const contextSettings = getContextSettings();
            embeddingProvider = embeddingProvider || contextSettings.vector_embedding_provider;
            embeddingModel = embeddingModel || contextSettings.vector_embedding_model;
          }
          if (!embeddingProvider || !embeddingModel) {
            throw new Error("Select an embedding provider and model.");
          }
          const createTrainingTextPayload = (nonce, vectorId, targetKey) => ({
            _ajax_nonce: nonce,
            text_content: text,
            metadata: JSON.stringify({
              source: sourceType,
              created_at: (new Date).toISOString(),
              vector_id: vectorId
            }),
            embedding_provider: embeddingProvider,
            embedding_model: embeddingModel,
            original_text_content: text,
            [targetKey]: storeId,
            source_type: sourceType,
            source_context: "chatbot_settings_sources_sheet"
          });
          if (providerKey === "pinecone") {
            const pineconeNonce = getNonceValue("aipkit_vector_store_pinecone_nonce_management");
            if (!pineconeNonce) {
              throw new Error("Pinecone nonce missing.");
            }
            const vectorId = `text_${Date.now()}`;
            await window.aipkit_apiRequest("aipkit_upsert_to_pinecone_index", createTrainingTextPayload(pineconeNonce, vectorId, "index_name"));
            return;
          }
          if (providerKey === "qdrant") {
            const qdrantNonce = getNonceValue("aipkit_vector_store_qdrant_nonce_management");
            if (!qdrantNonce) {
              throw new Error("Qdrant nonce missing.");
            }
            const vectorId = createQdrantPointId();
            await window.aipkit_apiRequest("aipkit_upsert_to_qdrant_collection", createTrainingTextPayload(qdrantNonce, vectorId, "collection_name"));
            return;
          }
          if (providerKey === "local") {
            const localNonce = getNonceValue("aipkit_vector_store_local_nonce_management");
            if (!localNonce) {
              throw new Error("Knowledge base nonce missing.");
            }
            const vectorId = `text_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;
            await window.aipkit_apiRequest("aipkit_local_add_text", { ...createTrainingTextPayload(localNonce, vectorId, "store_id"), vector_id: vectorId });
            return;
          }
          if (providerKey === "chroma") {
            const chromaNonce = getNonceValue("aipkit_vector_store_chroma_nonce_management");
            if (!chromaNonce) {
              throw new Error("Chroma nonce missing.");
            }
            const vectorId = createChromaRecordId();
            await window.aipkit_apiRequest("aipkit_upsert_to_chroma_collection", createTrainingTextPayload(chromaNonce, vectorId, "collection_name"));
            return;
          }
          throw new Error("Unsupported provider for edit.");
        };
        if (sourcesSearchInput) {
          sourcesSearchInput.addEventListener("input", () => {
            if (searchTimeout) {
              window.clearTimeout(searchTimeout);
            }
            searchTimeout = window.setTimeout(() => {
              sourcesState.search = sourcesSearchInput.value.trim();
              refreshSourcesSheet(1);
            }, 300);
          });
        }
        if (sourcesFilterSelect) {
          sourcesFilterSelect.addEventListener("change", () => {
            sourcesState.status = sourcesFilterSelect.value;
            refreshSourcesSheet(1);
          });
        }
        if (sourcesTypeFilter) {
          sourcesTypeFilter.addEventListener("change", () => {
            sourcesState.type = sourcesTypeFilter.value;
            refreshSourcesSheet(1);
          });
        }
        if (sourcesRefreshButton) {
          sourcesRefreshButton.addEventListener("click", () => {
            refreshSourcesSheet(sourcesState.page || 1);
          });
        }
        sourceEditor.bind({
          sourcesTableBody,
          setSourcesStatus,
          clearSourcesStatusSoon,
          upsertEditedText,
          refreshSourcesSheet,
          sourcesState,
          refreshTrainingSourcesAfterMutation,
          getContextSettings
        });
        sourcesSheetSection.addEventListener("click", async event => {
          const menuTrigger = event.target.closest(".aipkit_sources_action_menu_trigger");
          if (menuTrigger) {
            event.preventDefault();
            event.stopPropagation();
            toggleSourcesActionMenu(menuTrigger);
            return;
          }
          if (event.target.closest(".aipkit_sources_action_menu_item")) {
            closeSourcesActionMenu();
          }
          const editButton = event.target.closest(".aipkit_sources_action_edit");
          if (editButton) {
            sourceEditor.open(editButton);
            return;
          }
          const deleteButton = event.target.closest(".aipkit_sources_action_delete");
          if (deleteButton) {
            const providerLabel = deleteButton.dataset.provider || "";
            const storeId = deleteButton.dataset.storeId || "";
            const vectorId = deleteButton.dataset.vectorId || "";
            const logId = deleteButton.dataset.logId || "";
            if (!providerLabel || !storeId || !vectorId || !logId) {
              setSourcesStatus("Missing content details.", "error");
              clearSourcesStatusSoon();
              return;
            }
            confirmSourceDelete({
              providerLabel,
              storeId,
              vectorId,
              logId
            });
            return;
          }
          const retrainButton = event.target.closest(".aipkit_sources_action_retrain");
          if (retrainButton) {
            const providerLabel = retrainButton.dataset.provider || "";
            const storeId = retrainButton.dataset.storeId || "";
            const vectorId = retrainButton.dataset.vectorId || "";
            const logId = retrainButton.dataset.logId || "";
            const postId = retrainButton.dataset.postId || "";
            const embeddingProvider = retrainButton.dataset.embeddingProvider || "";
            const embeddingModel = retrainButton.dataset.embeddingModel || "";
            if (!providerLabel || !storeId || !vectorId || !logId || !postId) {
              setSourcesStatus("Missing content details.", "error");
              clearSourcesStatusSoon();
              return;
            }
            retrainButton.disabled = true;
            retrainButton.setAttribute("aria-disabled", "true");
            retrainButton.textContent = "Updating...";
            const row = retrainButton.closest("tr");
            const viewButton = row ? row.querySelector(".aipkit_view_snippet_icon") : null;
            const deleteButton = row ? row.querySelector(".aipkit_sources_action_delete") : null;
            if (viewButton) {
              viewButton.disabled = true;
              viewButton.setAttribute("aria-disabled", "true");
            }
            if (deleteButton) {
              deleteButton.disabled = true;
              deleteButton.setAttribute("aria-disabled", "true");
            }
            setSourcesStatus("Updating...", "");
            try {
              const response = await window.aipkit_apiRequest("aipkit_reindex_vector_data_source_entry", {
                provider: providerLabel,
                store_id: storeId,
                vector_id: vectorId,
                log_id: logId,
                post_id: postId,
                embedding_provider: embeddingProvider,
                embedding_model: embeddingModel
              });
              setSourcesStatus(response.processing ? __("Submitted — processing in background", "gpt3-ai-content-generator") : __("Updated", "gpt3-ai-content-generator"), response.processing ? "info" : "success");
              clearSourcesStatusSoon();
              refreshSourcesSheet(sourcesState.page || 1);
              refreshTrainingSourcesAfterMutation(getContextSettings());
            } catch (error) {
              setSourcesStatus(`Error: ${error.message || "Failed to retrain."}`, "error");
              retrainButton.disabled = false;
              retrainButton.removeAttribute("aria-disabled");
              retrainButton.textContent = "Update";
              if (viewButton) {
                viewButton.disabled = false;
                viewButton.removeAttribute("aria-disabled");
              }
              if (deleteButton) {
                deleteButton.disabled = false;
                deleteButton.removeAttribute("aria-disabled");
              }
            }
          }
        });
        document.addEventListener("click", event => {
          if (!event.target.closest(".aipkit_sources_actions")) {
            closeSourcesActionMenu();
          }
        });
        document.addEventListener("keydown", event => {
          if (event.key === "Escape") {
            closeSourcesActionMenu();
          }
        });
        window.addEventListener("resize", closeSourcesActionMenu, {
          passive: true
        });
        document.addEventListener("scroll", closeSourcesActionMenu, {
          capture: true,
          passive: true
        });
        builder.dataset.sourcesSheetBound = "1";
      }
    }
    return {
      syncTrainingUiState,
      saveContextSettingsAfterCapabilityChange
    };
  };
  return {
    updateTrainingCardVisibility,
    bindSourcePicker,
    bindSettings
  };
}
