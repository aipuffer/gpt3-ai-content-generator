import { isMissingProviderCredentialMessage, resolveChatbotProviderConfigured } from "./providers.js";

import { createSourceActionMenu } from "../knowledge-base/source-actions.js";

import { createChatbotSourceRecords, getSourceUpdatedMeta } from "../knowledge-base/source-records.js";
import { createChatbotSourcePage } from "./source-page.js";

import { createChatbotKnowledgeRun, bindChatbotSourceInventory, createChatbotTrainingStatus, createChatbotWebsiteSources, bindChatbotSourcePicker, getKnowledgeProviderCompatibilityMessage, resolveChatbotSourceSetup } from "./knowledge.js";
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
    _n,
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
  const sourcesList = sourcesSheetSection ? sourcesSheetSection.querySelector("#aipkit_known_list") : null;
  const sourcesSearchInput = sourcesSheetSection ? sourcesSheetSection.querySelector(".aipkit_known_search_input") : null;
  const sourcesFilterSelect = sourcesSheetSection ? sourcesSheetSection.querySelector(".aipkit_sources_filter_select") : null;
  const sourcesTypeFilter = sourcesSheetSection ? sourcesSheetSection.querySelector(".aipkit_sources_type_filter") : null;
  const sourcesCounts = sourcesSheetSection ? sourcesSheetSection.querySelector("[data-aipkit-known-counts]") : null;
  const sourcesMore = sourcesSheetSection ? sourcesSheetSection.querySelector("[data-aipkit-known-more]") : null;
  const sourcesAddButton = sheetOverlay ? sheetOverlay.querySelector("[data-aipkit-known-add]") : null;
  const sourcesStatus = sourcesSheetSection ? sourcesSheetSection.querySelector("#aipkit_sources_status") : null;
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
                window.aipkit_closeTrainingSourcePopover({ saved: true });
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
                window.aipkit_closeTrainingSourcePopover({ saved: true });
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
                window.aipkit_closeTrainingSourcePopover({ saved: true });
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
            } else {
              const rawErrorMessage = error?.message || "";
              const missingProviderConfig = isMissingProviderCredentialMessage(rawErrorMessage, {
                includeUrl: true
              }) || /api key|qdrant url|chroma url|connection url|base url|endpoint/i.test(rawErrorMessage);
              const knowledgeSettingsSaveFailed = error?.code === "aipkit_knowledge_settings_save_failed";
              setTrainingStatus(missingProviderConfig ? "Connect an API key to add knowledge." : knowledgeSettingsSaveFailed ? rawErrorMessage || __("Could not save knowledge settings. Please try again.", "gpt3-ai-content-generator") : `Error: ${rawErrorMessage || "Adding knowledge failed."}`, "error");
            }
            // Failed or stopped indexing can still write a source log. Refresh even
            // when the successful-source count and queue counters did not change.
            refreshTrainingSourcesAfterMutation(getContextSettings());
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
        const SOURCES_PAGE_SIZE = 20;
        const sourcesState = {
          search: "",
          type: "",
          status: "",
          loaded: 0,
          cursor: null,
          hasMore: false,
          summary: null
        };
        let searchTimeout = null;
        let openSourcesActionMenu = null;
        let sourcesFetchGeneration = 0;
        const normalizeSourceProviderKey = value => String(value || "").trim().toLowerCase();
        const {
          getStatusMeta: getSourceStatusMeta,
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
        let sourcePage = null;
        const sourcesMoreShown = sourcesMore ? sourcesMore.querySelector("[data-aipkit-known-shown]") : null;
        const sourcesMoreButton = sourcesMore ? sourcesMore.querySelector("[data-aipkit-known-more-button]") : null;
        const STATUS_KEYS = {
          indexed: "ready",
          processing: "adding",
          failed: "failed"
        };
        const countLabels = {
          /* translators: %s: number of sources the chatbot answers from. */
          ready: count => _n("%s ready", "%s ready", count, "gpt3-ai-content-generator"),
          /* translators: %s: number of sources still being added. */
          adding: count => _n("%s adding", "%s adding", count, "gpt3-ai-content-generator"),
          /* translators: %s: number of sources that failed to add. */
          failed: count => _n("%s couldn't be added", "%s couldn't be added", count, "gpt3-ai-content-generator")
        };
        // Status counts for the kind on show (a pressed one stays so it can be turned off), kind sizes for the
        // status on show, and how many of them the list shows.
        const renderSourcesSummary = () => {
          const summary = sourcesState.summary;
          const kind = sourcesState.type || "all";
          const statusKey = STATUS_KEYS[sourcesState.status] || "all";
          let anyCount = false;
          sourcesCounts?.querySelectorAll("[data-aipkit-known-status]").forEach(button => {
            const status = button.dataset.aipkitKnownStatus;
            const key = STATUS_KEYS[status];
            const count = Number(summary?.[kind]?.[key] || 0);
            const pressed = sourcesState.status === status;
            button.hidden = !count && !pressed;
            button.setAttribute("aria-pressed", pressed ? "true" : "false");
            const label = button.querySelector("[data-aipkit-known-count-label]");
            if (label) {
              label.textContent = sprintf(countLabels[key](count), count.toLocaleString());
            }
            anyCount = anyCount || !button.hidden;
          });
          if (sourcesCounts) {
            sourcesCounts.hidden = !anyCount;
          }
          sourcesSheetSection.querySelectorAll("[data-aipkit-known-kind-count]").forEach(node => {
            const count = summary?.[node.dataset.aipkitKnownKindCount]?.[statusKey];
            node.textContent = Number.isFinite(count) ? count.toLocaleString() : "";
          });
          if (sourcesMore) {
            const total = summary?.[kind]?.[statusKey];
            sourcesMore.hidden = !sourcesState.hasMore;
            if (sourcesMoreShown) {
              sourcesMoreShown.textContent = Number.isFinite(total) && total > sourcesState.loaded
                /* translators: 1: sources shown, 2: all sources that match. */
                ? sprintf(__("Showing %1$s of %2$s", "gpt3-ai-content-generator"), sourcesState.loaded.toLocaleString(), total.toLocaleString())
                : "";
            }
          }
        };
        const renderSourcesEmpty = message => {
          if (!sourcesList) {
            return;
          }
          closeSourcesActionMenu();
          sourcesList.innerHTML = `<p class="aipkit_known_empty">${escaper(message)}</p>`;
          sourcesState.loaded = 0;
          sourcesState.hasMore = false;
          renderSourcesSummary();
        };
        // A website page shows where it lives; the page's address is the first line of what was read.
        const getSourcePath = log => {
          const match = String(log.indexed_content || "").match(/^Source URL:\s*(\S+)/m);
          if (!match) {
            return "";
          }
          try {
            const url = new URL(match[1]);
            return decodeURI(`${url.pathname}${url.search}`) || "/";
          } catch (error) {
            return "";
          }
        };
        // Each row's source, kept so its page can open without another request.
        const sourcesById = new Map();
        const renderSourceRow = (log, providerLabel) => {
          const providerKey = normalizeSourceProviderKey(log.provider || providerLabel || "");
          const actionProvider = providerLabels[providerKey] || log.provider || providerLabel || "";
          const contentType = getSourceContentTypeMeta(log, providerKey);
          const statusMeta = getSourceStatusMeta(log.status);
          const isProcessing = log.status === "processing" || log.status === "queued";
          const tone = log.status === "failed" ? "failed" : isProcessing ? "adding" : "ready";
          // Website pages are learned; the other sources are added.
          const statusLabel = contentType.key === "site" && tone !== "ready"
            ? tone === "failed" ? __("Couldn't learn", "gpt3-ai-content-generator") : __("Learning...", "gpt3-ai-content-generator")
            : statusMeta.label;
          const updatedMeta = getSourceUpdatedMeta(log);
          const sourceDisplay = String(getSourceDisplay(log) || "—");
          // An uploaded file is one source; the parts it was split into are counted, not listed.
          /* translators: %s: number of parts an uploaded file was split into. */
          const chunkMeta = log.file_chunks?.length > 1 ? {label: sprintf(_n("%s part", "%s parts", log.file_chunks.length, "gpt3-ai-content-generator"), log.file_chunks.length.toLocaleString())} : getSourceChunkMeta(log);
          const id = String(log.id || "");
          sourcesById.set(id, {
            log,
            kind: contentType.key,
            kindLabel: contentType.label,
            tone,
            statusLabel,
            updated: updatedMeta,
            display: sourceDisplay,
            target: {
              provider: actionProvider,
              storeId: String(log.vector_store_id || ""),
              vectorId: String(log.file_id || ""),
              logId: id
            }
          });
          // Ready sources say where they come from; the others say what is happening or what went wrong.
          const detail = tone === "failed"
            ? String(log.message || statusLabel)
            : [ (contentType.key === "site" && getSourcePath(log)) || `${contentType.label} · ${updatedMeta.label}`, chunkMeta?.label ].filter(Boolean).join(" · ");
          const actions = [ renderSourceAction(`data-log-id="${escaper(id)}"`, "aipkit_sources_action_open", contentType.key === "text" ? "dashicons-edit" : "dashicons-visibility", contentType.key === "text" ? __("Edit", "gpt3-ai-content-generator") : __("View what it read", "gpt3-ai-content-generator"), false) ];
          if (contentType.key === "site" && log.post_id) {
            actions.push(renderSourceAction(`${renderSourceTargetAttributes(log, actionProvider)}
                      data-post-id="${escaper(log.post_id || "")}"
                      data-embedding-provider="${escaper(log.embedding_provider || "")}"
                      data-embedding-model="${escaper(log.embedding_model || "")}"`, "aipkit_sources_action_retrain", "dashicons-update", isProcessing ? __("Learning...", "gpt3-ai-content-generator") : __("Learn again", "gpt3-ai-content-generator"), isProcessing));
          }
          if (log.file_id) {
            actions.push(renderSourceAction(renderSourceTargetAttributes(log, actionProvider), "aipkit_sources_action_menu_item--danger aipkit_sources_action_menu_item--separated aipkit_sources_action_delete", "dashicons-trash", __("Remove", "gpt3-ai-content-generator"), isProcessing));
          }
          return `<div class="aipkit_known_row is-${tone}" role="listitem" data-log-id="${escaper(id)}">
              <button type="button" class="aipkit_known_open" data-aipkit-known-open="${escaper(id)}">
                <span class="aipkit_known_icon dashicons ${escaper(getSourceTypeIcon(contentType))}" role="img" aria-label="${escaper(contentType.label)}"></span>
                <span class="aipkit_known_copy">
                  <span class="aipkit_known_title">${escaper(sourceDisplay)}</span>
                  <span class="aipkit_known_detail" title="${escaper(updatedMeta.title)}">${escaper(detail)}</span>
                </span>
                ${tone === "ready" ? "" : `<span class="aipkit_known_state">${escaper(statusLabel)}</span>`}
              </button>
              ${renderSourceActionMenu(actions)}
            </div>`;
        };
        const renderSourcesRows = (logs, providerLabel, append) => {
          if (!sourcesList) {
            return;
          }
          closeSourcesActionMenu();
          if (!append) {
            sourcesById.clear();
          }
          if (!append && !logs.length) {
            const message = sourcesState.search
              /* translators: %s: what someone searched for. */
              ? sprintf(__("Nothing matches “%s”.", "gpt3-ai-content-generator"), sourcesState.search)
              : sourcesState.type || sourcesState.status
                ? __("Nothing matches these filters.", "gpt3-ai-content-generator")
                : __("Nothing here yet. Add your website, questions and answers, or files.", "gpt3-ai-content-generator");
            renderSourcesEmpty(message);
            return;
          }
          const rows = logs.map(log => renderSourceRow(log, providerLabel)).join("");
          if (append) {
            sourcesList.insertAdjacentHTML("beforeend", rows);
          } else {
            sourcesList.innerHTML = rows;
          }
        };
        let sourcesRefreshTimer;
        // The first sources (as many as were showing, when kept), or the next ones for Show more.
        const fetchSources = async ({append = false, keep = false, silent = false} = {}) => {
          window.clearTimeout(sourcesRefreshTimer);
          if (!sourcesList || typeof window.aipkit_apiRequest !== "function") {
            return;
          }
          const botId = getSelectedBuilderBotId();
          if (!botId) {
            renderSourcesEmpty(__("Select a chatbot to see what it knows.", "gpt3-ai-content-generator"));
            return;
          }
          const {contextSettings, providerKey, providerLabel, storeIds} = getSourcesContext();
          const requestGeneration = ++sourcesFetchGeneration;
          const signatureOf = (id, context) => JSON.stringify({
            botId: String(id || ""),
            providerKey: context.providerKey,
            storeIds: context.storeIds,
            search: sourcesState.search,
            type: sourcesState.type,
            status: sourcesState.status
          });
          const requestSignature = signatureOf(botId, getSourcesContext());
          const isCurrentRequest = () => requestGeneration === sourcesFetchGeneration && requestSignature === signatureOf(getSelectedBuilderBotId(), getSourcesContext());
          if (!providerKey || !storeIds.length) {
            sourcesState.summary = null;
            renderSourcesEmpty(__("Nothing here yet. Add your website, questions and answers, or files.", "gpt3-ai-content-generator"));
            return;
          }
          const cursor = append ? sourcesState.cursor : null;
          if (append && !cursor) {
            return;
          }
          if (!silent && !append) {
            sourcesList.innerHTML = `<p class="aipkit_known_empty"><span class="aipkit_spinner" aria-hidden="true"></span> ${escaper(__("Loading…", "gpt3-ai-content-generator"))}</p>`;
            if (sourcesMore) {
              sourcesMore.hidden = true;
            }
          }
          if (sourcesMoreButton) {
            sourcesMoreButton.disabled = append;
          }
          try {
            const response = await window.aipkit_apiRequest("aipkit_get_chatbot_training_sources", {
              bot_id: botId,
              page: 1,
              per_page: append ? SOURCES_PAGE_SIZE : Math.min(50, Math.max(SOURCES_PAGE_SIZE, keep ? sourcesState.loaded : 0)),
              include_total: "0",
              include_summary: append ? "0" : "1",
              cursor_mode: "1",
              cursor_timestamp: cursor?.timestamp || "",
              cursor_id: cursor?.id || 0,
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
            });
            if (!isCurrentRequest()) {
              return;
            }
            const logs = response.logs || [];
            if (!append) {
              sourcesState.summary = response.summary || null;
            }
            const nextCursor = response.pagination?.next_cursor || null;
            sourcesState.cursor = nextCursor?.timestamp && Number(nextCursor?.id || 0) > 0 ? nextCursor : null;
            sourcesState.hasMore = Boolean(response.pagination?.has_more && sourcesState.cursor);
            renderSourcesRows(logs, providerLabel, append);
            const opened = sourcePage && sourcesById.get(sourcePage.currentId());
            if (opened) sourcePage.refresh(opened);
            if (append || logs.length) {
              sourcesState.loaded = (append ? sourcesState.loaded : 0) + logs.length;
              renderSourcesSummary();
            }
          } catch (error) {
            if (!isCurrentRequest()) {
              return;
            }
            if (!silent && !append) {
              /* translators: %s: error message. */
              renderSourcesEmpty(sprintf(__("Couldn't load what it knows: %s", "gpt3-ai-content-generator"), error.message || __("Unknown error", "gpt3-ai-content-generator")));
            }
          } finally {
            if (sourcesMoreButton && isCurrentRequest()) {
              sourcesMoreButton.disabled = false;
            }
            // While something is being added, the list checks again every ten seconds.
            if (isCurrentRequest() && sourcesList.querySelector(".aipkit_known_row.is-adding")) {
              const refreshWhenVisible = () => {
                if (!isCurrentRequest() || !builder.isConnected || !sheetOverlay?.classList.contains('aipkit-active') || sourcesSheetSection.hidden) return;
                if (document.visibilityState === 'hidden') {
                  sourcesRefreshTimer = window.setTimeout(refreshWhenVisible, 1e4);
                  return;
                }
                fetchSources({keep: true, silent: true});
              };
              sourcesRefreshTimer = window.setTimeout(refreshWhenVisible, 1e4);
            }
          }
        };
        // "keep" reloads as many sources as are showing (after an update or removal); anything else starts at the top.
        const refreshSourcesSheet = mode => {
          if (!sheetOverlay || !sheetOverlay.classList.contains("aipkit-active") || sourcesSheetSection.hidden) {
            return;
          }
          fetchSources({keep: mode === "keep"});
        };
        window.aipkit_refreshSourcesSheet = refreshSourcesSheet;
        // The row's buttons (and the page's Remove) wait while a source is removed or updated.
        const findSourceRow = logId => {
          const safeId = typeof CSS !== "undefined" && CSS.escape ? CSS.escape(String(logId)) : String(logId).replace(/[^a-zA-Z0-9_-]/g, "\\$&");
          return sourcesList ? sourcesList.querySelector(`.aipkit_known_row[data-log-id="${safeId}"]`) : null;
        };
        const holdSourceButtons = (logId, button) => {
          const row = findSourceRow(logId);
          const buttons = [ button, ...(row ? Array.from(row.querySelectorAll("button")) : []) ].filter(Boolean);
          const set = busy => buttons.forEach(node => {
            node.disabled = busy;
            if (busy) {
              node.setAttribute("aria-disabled", "true");
            } else {
              node.removeAttribute("aria-disabled");
            }
          });
          set(true);
          return () => set(false);
        };
        const deleteSourceFromSheet = async details => {
          const {providerLabel = "", storeId = "", vectorId = "", logId = "", button = null} = details;
          if (!providerLabel || !storeId || !vectorId || !logId) {
            setSourcesStatus("Missing content details.", "error");
            clearSourcesStatusSoon();
            return;
          }
          const release = holdSourceButtons(logId, button);
          setSourcesStatus(__("Removing...", "gpt3-ai-content-generator"), "");
          try {
            await window.aipkit_apiRequest("aipkit_delete_vector_data_source_entry", {
              provider: providerLabel,
              store_id: storeId,
              vector_id: vectorId,
              log_id: logId,
              remove_file: sourcesById.get(String(logId))?.log.file_chunks?.length ? "1" : "0"
            });
            sourcePage.close();
            setSourcesStatus(__("Removed", "gpt3-ai-content-generator"), "success");
            clearSourcesStatusSoon();
            refreshSourcesSheet("keep");
            refreshTrainingSourcesAfterMutation(getContextSettings());
          } catch (error) {
            setSourcesStatus(`Error: ${error.message || "Failed to delete."}`, "error");
            release();
          }
        };
        const confirmSourceDelete = details => {
          const message = __("The chatbot stops answering from it. This can't be undone.", "gpt3-ai-content-generator");
          const runDelete = () => deleteSourceFromSheet(details);
          if (typeof window.aipkit_showConfirmModal === "function") {
            window.aipkit_showConfirmModal(message, {
              title: __("Remove this source?", "gpt3-ai-content-generator"),
              confirmText: __("Remove", "gpt3-ai-content-generator"),
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
        const waitForReplacement = async readStatus => {
          for (let attempt = 0; attempt < 40; attempt++) {
            const status = String(await readStatus()).toLowerCase();
            if (status === "indexed") return;
            if (["failed", "cancelled", "expired"].includes(status)) {
              const error = new Error(__("The replacement could not be indexed. The original source was kept. You can try saving again.", "gpt3-ai-content-generator"));
              error.indexingFailed = true;
              throw error;
            }
            if (attempt < 39) await new Promise(resolve => window.setTimeout(resolve, 3000));
          }
          throw new Error(__("The replacement is still processing. The original source was kept. Try Save again to check its status.", "gpt3-ai-content-generator"));
        };
        const upsertEditedText = async (providerLabel, storeId, text, embeddingProvider, embeddingModel, sourceKind = "text", replacement = {}) => {
          const providerKey = providerLabel.toLowerCase();
          const sourceType = sourceKind === "qa" ? "chatbot_training_qa" : "chatbot_training_text";
          if (providerKey === "openai") {
            const openaiNonce = getNonceValue("aipkit_vector_store_nonce_openai");
            if (!openaiNonce) {
              throw new Error("OpenAI nonce missing.");
            }
            replacement.response ||= await window.aipkit_apiRequest("aipkit_add_text_to_vector_store_openai", {
              _ajax_nonce: openaiNonce,
              target_store_id: storeId,
              text_content: text,
              source_type: sourceType
            });
            const batchId = replacement.response?.batch?.id;
            if (!batchId) throw new Error(__("The replacement could not be verified. The original source was kept.", "gpt3-ai-content-generator"));
            await waitForReplacement(async () => {
              const result = await window.aipkit_apiRequest("aipkit_get_openai_file_batch_status", {
                _ajax_nonce: openaiNonce, store_id: storeId, batch_id: batchId
              });
              const batch = result.batch || {};
              if (Number(batch.file_counts?.failed || 0) || Number(batch.file_counts?.cancelled || 0)) return "failed";
              return result.status === "completed" ? "indexed" : result.status;
            });
            return;
          }
          if (providerKey === "google" || providerKey === "google file search") {
            const googleNonce = getNonceValue("aipkit_google_file_search_nonce");
            if (!googleNonce) {
              throw new Error("Google File Search nonce missing.");
            }
            const googleResponse = replacement.response ||= await window.aipkit_apiRequest("aipkit_add_text_to_google_file_search", {
              _ajax_nonce: googleNonce,
              target_store_id: storeId,
              text_content: text,
              source_type: sourceType
            });
            if (googleResponse?.job_id && String(googleResponse?.status || "").toLowerCase() !== "indexed") {
              await waitForReplacement(async () => {
                const result = await window.aipkit_apiRequest("aipkit_get_google_file_search_job_status", {
                  _ajax_nonce: googleNonce, job_id: googleResponse.job_id
                });
                return result.status;
              });
            } else if (String(googleResponse?.status || "").toLowerCase() !== "indexed") {
              throw new Error(__("The replacement could not be verified. The original source was kept.", "gpt3-ai-content-generator"));
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
        // Keep acknowledged replacements across retries, including pending native indexing jobs.
        const replacements = new Map();
        const saveSourceText = async ({entry, kind, text}) => {
          const {provider, storeId, vectorId, logId} = entry.target;
          if (!provider || !storeId || !vectorId || !logId) {
            setSourcesStatus("Missing content details.", "error");
            throw new Error("Missing content details.");
          }
          setSourcesStatus(__("Saving...", "gpt3-ai-content-generator"), "");
          const key = JSON.stringify([provider, storeId, vectorId, logId]);
          let replacement = replacements.get(key);
          if (replacement && replacement.text !== text) {
            const message = __("A previous replacement is pending. Restore the text from that attempt and save again, or review the sources before starting another edit.", "gpt3-ai-content-generator");
            setSourcesStatus(message, "error");
            throw new Error(message);
          }
          replacement ||= {text, ready: false};
          replacements.set(key, replacement);
          try {
            if (!replacement.ready) {
              await upsertEditedText(provider, storeId, text, entry.log.embedding_provider || "", entry.log.embedding_model || "", kind, replacement);
              replacement.ready = true;
            }
            try {
              await window.aipkit_apiRequest("aipkit_delete_vector_data_source_entry", {
                provider, store_id: storeId, vector_id: vectorId, log_id: logId
              });
            } catch (error) {
              throw new Error(__("The new version is ready, but the original could not be removed. Both were kept. Try Save again to finish replacing it.", "gpt3-ai-content-generator"));
            }
            replacements.delete(key);
          } catch (error) {
            if (error.indexingFailed || (!replacement.response && !replacement.ready)) replacements.delete(key);
            /* translators: %s: error message. */
            setSourcesStatus(sprintf(__("Couldn't save it: %s", "gpt3-ai-content-generator"), error.message || __("Unknown error", "gpt3-ai-content-generator")), "error");
            throw error;
          }
          setSourcesStatus(__("Saved", "gpt3-ai-content-generator"), "success");
          clearSourcesStatusSoon();
          refreshSourcesSheet("keep");
          refreshTrainingSourcesAfterMutation(getContextSettings());
        };
        sourcePage = createChatbotSourcePage({
          sheet: sheetOverlay,
          section: sourcesSheetSection,
          __,
          _n,
          sprintf,
          escaper,
          parsePreview: (log, text) => typeof window.aipkit_parseSourcePreview === "function" ? window.aipkit_parseSourcePreview(log, text) : null,
          parseEditorContent: text => typeof window.aipkit_parseSourceEditorContent === "function"
            ? window.aipkit_parseSourceEditorContent(text)
            : {type: "text", text, question: "", answer: ""},
          onSave: saveSourceText
        });
        // The sheet opens on the list; another chatbot's sources start there too.
        sheetOverlay.addEventListener("aipkit:builder-sheet-open", () => sourcePage.close());
        builder.addEventListener("aipkit:bot-state-applied", () => sourcePage.close());
        if (sourcesSearchInput) {
          sourcesSearchInput.addEventListener("input", () => {
            if (searchTimeout) {
              window.clearTimeout(searchTimeout);
            }
            searchTimeout = window.setTimeout(() => {
              sourcesState.search = sourcesSearchInput.value.trim();
              refreshSourcesSheet();
            }, 300);
          });
        }
        if (sourcesFilterSelect) {
          sourcesFilterSelect.addEventListener("change", () => {
            sourcesState.status = sourcesFilterSelect.value;
            renderSourcesSummary();
            refreshSourcesSheet();
          });
        }
        if (sourcesTypeFilter) {
          sourcesTypeFilter.addEventListener("change", () => {
            sourcesState.type = sourcesTypeFilter.value;
            renderSourcesSummary();
            refreshSourcesSheet();
          });
        }
        // A status count filters the list to it; pressing it again shows every status.
        sourcesCounts?.addEventListener("click", event => {
          const button = event.target.closest("[data-aipkit-known-status]");
          if (!button || !sourcesFilterSelect) {
            return;
          }
          const status = button.dataset.aipkitKnownStatus;
          sourcesFilterSelect.value = sourcesFilterSelect.value === status ? "" : status;
          sourcesFilterSelect.dispatchEvent(new Event("change", {
            bubbles: true
          }));
        });
        sourcesMoreButton?.addEventListener("click", () => {
          fetchSources({append: true});
        });
        // The sheet closes on this click; the picker opens next, beside the Knowledge card's own Add source.
        sourcesAddButton?.addEventListener("click", () => {
          window.setTimeout(() => {
            trainingAddSourceButton?.scrollIntoView({block: "nearest"});
            trainingAddSourceButton?.click();
          }, 0);
        });
        sheetOverlay.addEventListener("click", async event => {
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
          const opener = event.target.closest("[data-aipkit-known-open], .aipkit_sources_action_open");
          if (opener) {
            sourcePage.open(sourcesById.get(String(opener.dataset.aipkitKnownOpen || opener.dataset.logId || "")));
            return;
          }
          if (event.target.closest("[data-aipkit-known-back]")) {
            const logId = sourcePage.currentId();
            sourcePage.close();
            // Back on the list, the source just left keeps focus.
            findSourceRow(logId)?.querySelector("[data-aipkit-known-open]")?.focus();
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
              logId,
              button: deleteButton
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
            const label = retrainButton.innerHTML;
            const release = holdSourceButtons(logId, retrainButton);
            retrainButton.textContent = __("Learning...", "gpt3-ai-content-generator");
            setSourcesStatus(__("Learning...", "gpt3-ai-content-generator"), "");
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
              sourcePage.close();
              setSourcesStatus(response.processing ? __("Learning it in the background.", "gpt3-ai-content-generator") : __("Learned again.", "gpt3-ai-content-generator"), response.processing ? "info" : "success");
              clearSourcesStatusSoon();
              refreshSourcesSheet("keep");
              refreshTrainingSourcesAfterMutation(getContextSettings());
            } catch (error) {
              setSourcesStatus(error.message || __("Couldn't learn this page.", "gpt3-ai-content-generator"), "error");
              retrainButton.innerHTML = label;
              release();
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
