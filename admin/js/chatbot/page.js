import { applyChatbotDeploymentState, bindChatbotShortcodeCopy, bindChatbotDeployment, bindChatbotPlacement } from "./deployment.js";
import { isToggleFieldOn, updatePopoverToggleAvailability, bindChatbotImagePanel, createChatbotImageWarning, bindChatbotTools, bindChatbotImageSettings, bindChatbotFileUploadSettings } from "./tools.js";
import { startChatbotEditorPreview, bindChatbotPreviewDevice } from "./preview.js";
import { bindChatbotSettingsRows, createChatbotInlinePanels, createChatbotSaveFeedback, createChatbotPanels, bindChatbotDetailPanels, bindChatbotTabs, bindChatbotFeatureDrawers } from "./panels.js";
import { bindChatbotSegmented } from "./segmented.js";
import { createChatbotSession, createChatbotRecordActions } from "./state.js";
import { createChatbotCatalog, bindChatbotName, bindChatbotBotSwitcher } from "./actions.js";
import { createChatbotKnowledgeSettings, bindChatbotSearchSettings } from "./knowledge-settings.js";
import { createChatbotTraining } from "./knowledge-training.js";
import { createChatbotAudio } from "./audio.js";
import { createChatbotProviderNotices, isMissingProviderCredentialMessage } from "./providers.js";
import { createChatbotWebSearch } from "./web-search.js";
import { createChatbotModelSettings, createChatbotModelCapabilities, createChatbotModelPicker } from "./model-config.js";
import { createChatbotConversation } from "./conversation.js";
import { createChatbotPopup } from "./popup.js";
import { createChatbotAppearance } from "./appearance.js";
import { bindChatbotNavigation } from "./navigation.js";
import { bindChatbotInstructions } from "./instructions.js";
import { bindChatbotLimits, syncChatbotLimitVisibility } from "./limits.js";

(function() {
  "use strict";
  const recordActions = createChatbotRecordActions();
  const LAST_CHATBOT_STORAGE_KEY = "aipkit_last_selected_chatbot";
  function aipkit_initChatbotBuilder() {
    const builder = document.querySelector(".aipkit_chatbot_builder");
    if (!builder || builder.dataset.aipkitContext === "sources") {
      return;
    }
    document.documentElement.classList.add("aipkit-chatbot-builder-page");
    const __ = window.wp && window.wp.i18n && window.wp.i18n.__ ? window.wp.i18n.__ : text => text;
    const _n = window.wp && window.wp.i18n && window.wp.i18n._n ? window.wp.i18n._n : (single, plural, number) => number === 1 ? single : plural;
    const sprintf = window.wp && window.wp.i18n && window.wp.i18n.sprintf ? window.wp.i18n.sprintf : (template, ...values) => {
      if (!template || !values.length) {
        return template;
      }
      let valueIndex = 0;
      return String(template).replace(/%\d*\$?[sd]/g, () => {
        const nextValue = valueIndex < values.length ? values[valueIndex] : "";
        valueIndex += 1;
        return String(nextValue);
      });
    };
    const layout = builder.getAttribute("data-aipkit-chatbot-layout") || "next";
    const openaiApiKeySet = builder.dataset.openaiApiKeySet === "true";
    const pineconeApiKeySet = builder.dataset.pineconeApiKeySet === "true";
    const qdrantApiKeySet = builder.dataset.qdrantApiKeySet === "true";
    const qdrantUrlSet = builder.dataset.qdrantUrlSet === "true";
    const chromaUrlSet = builder.dataset.chromaUrlSet === "true";
    const googleApiKeySet = builder.dataset.googleApiKeySet === "true";
    const azureApiKeySet = builder.dataset.azureApiKeySet === "true";
    const claudeApiKeySet = builder.dataset.claudeApiKeySet === "true";
    const botSelect = builder.querySelector("#aipkit_chatbot_builder_bot_select");
    const getSelectedBuilderBotId = () => botSelect && botSelect.value || builder.getAttribute("data-active-bot-id") || "";
    const activeBotId = getSelectedBuilderBotId();
    let triggerAutosave = null;
    let syncDeployUiState = null;
    let syncToolsEnabledUiState = null;
    let syncInterfaceControlsUiState = null;
    let syncThemeUiState = null;
    let syncImageModelUiState = null;
    let syncPopupSettingsUiState = null;
    let syncSettingsSectionsUiState = null;
    let syncConversationStartersUiState = null;
    let syncAudioUiState = null;
    let syncTrainingUiState = null;
    let saveContextSettingsAfterCapabilityChange = () => {};
    let saveWebSettingsAfterKnowledgeChange = () => {};
    const {
      consumeBotSwitchPreviewOptions,
      getSelectableBotIds,
      updateAvailableBotsDataset,
      getCurrentActiveBotId,
      isDefaultBotId,
      syncDeleteActionsState,
      isBotCurrentlyActive,
      getPreferredBotAfterDeleteUi,
      applyBotStateResponse,
      applyInsertedBotResponse,
      applyDeleteBotInPlace,
      botStateCache
    } = createChatbotCatalog({
      builder,
      botSelect,
      __,
      switchToBotState: (...args) => switchToBotState(...args),
      storageKey: LAST_CHATBOT_STORAGE_KEY
    });
    if (activeBotId) {
      try {
        localStorage.setItem(LAST_CHATBOT_STORAGE_KEY, activeBotId);
      } catch (error) {
        // Ignore storage failures silently.
      }
    }
    const newBotBtn = builder.querySelector(".aipkit_builder_new_bot_btn");
    const botActions = builder.querySelector("[data-aipkit-bot-actions]");
    const botActionsTrigger = builder.querySelector("[data-aipkit-bot-actions-toggle]");
    const botActionsMenu = builder.querySelector("[data-aipkit-bot-actions-menu]");
    const sheetOverlay = document.getElementById("aipkit_builder_sheet");
    const settingsPanel = document.getElementById("aipkit_chatbot_settings_panel");
    const modelPopoverPanel = settingsPanel;
    const chatbotPanels = createChatbotPanels({
      builder,
      sheetOverlay,
      // The theme dialog is declared later in this initializer.
      isCustomThemeOpen: () => Boolean(customThemeModal && customThemeModal.classList.contains("aipkit-active"))
    });
    const {updateBuilderSheetScrollLock} = chatbotPanels;
    const botNameField = builder.querySelector(".aipkit_bot_name_input");
    const instructionsField = builder.querySelector('textarea[name="instructions"]');
    const instructionsExpandBtn = builder.querySelector(".aipkit_builder_instructions_expand");
    const instructionsModal = document.getElementById("aipkit_builder_instructions_modal");
    const instructionsModalCloseBtn = instructionsModal ? instructionsModal.querySelector(".aipkit_builder_instructions_close") : null;
    const instructionsModalTextarea = instructionsModal ? instructionsModal.querySelector(".aipkit_builder_instructions_modal_textarea") : null;
    const instructionsModalCount = instructionsModal ? instructionsModal.querySelector(".aipkit_builder_instructions_count") : null;
    const {
      getCurrentChatbotProvider,
      getCurrentChatbotModel,
      isReasoningSupportedForCurrentSelection,
      modelSupportsOpenRouterCapability,
      modelSupportsXaiImageInput
    } = createChatbotModelCapabilities({
      builder
    });
    const {bindUnifiedModelSelector, syncUnifiedModelSelector} = createChatbotModelPicker({
      builder,
      __
    });
    window.aipkit_syncUnifiedChatModelSelector = () => syncUnifiedModelSelector(builder);
    const updateRulesSectionSummary = window.aipkit_bindChatbotRuleSummary?.({
      builder
    });
    const renderConnectedAppsList = window.aipkit_bindChatbotConnectedAppsSummary?.(builder);
    bindUnifiedModelSelector(builder);
    syncUnifiedModelSelector(builder);
    const {
      stateHydration,
      handleBotSaveSuccess,
      switchToBotState,
      bindBotStateAppliedReset,
      getBotSwitchFailure
    } = createChatbotSession({
      builder,
      botSelect,
      activeBotId,
      botStateCache,
      sheetOverlay,
      instructionsModal,
      botNameField,
      syncUnifiedModelSelector,
      syncDeleteActionsState,
      storageKey: LAST_CHATBOT_STORAGE_KEY,
      prepareTriggers: (...args) => triggerAutosave?.prepareHydration(...args),
      applyThemeSettings: settings => appearance.applyThemeSettings(settings),
      applyDeploymentSettings: (id, botState, settings) => applyChatbotDeploymentState(builder, id, botState, settings),
      syncFeatureState: (botState, syncTraining) => {
        updateWebGroundingVisibility();
        updateConversationVisibility();
        updateAudioVisibility();
        updatePopupSettingsVisibility();
        updateVectorStoreVisibility();
        updateTrainingCardVisibility();
        updateTokenLimitVisibility();
        updateToolsFeatureRowsVisibility();
        updateContextPopoverControls();
        syncDeployUiState?.();
        syncConversationStartersUiState?.();
        syncInterfaceControlsUiState?.();
        syncThemeUiState?.();
        syncImageModelUiState?.();
        syncPopupSettingsUiState?.();
        syncToolsEnabledUiState?.();
        syncAudioUiState?.();
        if (syncTraining) syncTrainingUiState?.();
        if (typeof window.aipkit_attachRangeValueHandlers === "function") {
          window.aipkit_attachRangeValueHandlers("#aipkit_chatbot_settings_panel");
        }

        updateRulesSectionSummary?.();
        renderConnectedAppsList?.(botState && typeof botState.connected_apps === "object" ? botState.connected_apps : null);
      }
    });
    const training = createChatbotTraining({
      builder,
      __,
      _n,
      sprintf,
      getSelectedBuilderBotId,
      botSelect,
      sheetOverlay
    });
    const {updateTrainingCardVisibility} = training;
    const toolsEnabledOptions = Array.from(builder.querySelectorAll(".aipkit_tools_enabled_option"));
    const {syncToolsEnabledOptionsFromFields, updateToolsFeatureRowsVisibility} = bindChatbotTools({
      builder,
      toolsEnabledOptions,
      getCurrentChatbotProvider
    });
    syncToolsEnabledUiState = syncToolsEnabledOptionsFromFields;
    const startersPanel = document.getElementById("aipkit_starters_panel");
    const consentPanel = document.getElementById("aipkit_consent_panel");
    const customThemeModal = document.getElementById("aipkit_custom_theme_modal");
    const webSettingsModal = document.getElementById("aipkit_builder_web_settings_modal");
    const contextSettingsPanel = modelPopoverPanel ? modelPopoverPanel.querySelector('[data-aipkit-settings-panel="context"]') : null;
    const limitsSettingsPanel = modelPopoverPanel ? modelPopoverPanel.querySelector('[data-aipkit-settings-panel="limits"]') : null;
    const audioSettingsModal = document.getElementById("aipkit_builder_audio_settings_modal");
    const imageSettingsModal = document.getElementById("aipkit_builder_image_generation_settings_modal");
    const popupSettingsPanel = builder.querySelector("#aipkit_builder_popup_settings_panel");
    const widgetDesigner = builder.querySelector("[data-aipkit-widget-designer]");
    const popupOnlyControls = Array.from(builder.querySelectorAll("[data-aipkit-popup-only-control]"));
    const {syncInlineSettingsPanelState, mountInlineSettingsPanelForTrigger, syncSettingsPanelOverflowState} = createChatbotInlinePanels({
      builder,
      settingsPanel,
      startersPanel,
      consentPanel
    });
    const appearance = createChatbotAppearance({
      builder,
      customThemeModal,
      widgetDesigner,
      popupSettingsPanel,
      getSelectedBotId: getSelectedBuilderBotId,
      __,
      syncSettingsPanelOverflowState,
      // Disclosure state is initialized later; resolve it when opening the dialog.
      closeOtherAdvancedDetailPanels: panel => closeOtherAdvancedDetailPanels(panel),
      updateBuilderSheetScrollLock
    });
    const imageGenerationRow = builder.querySelector(".aipkit_tools_feature_row--image-generation");
    const imageModelSelectField = imageGenerationRow ? imageGenerationRow.querySelector('select[name="chat_image_model_id"]') : null;
    const {
      showChatbotRequestErrorNotice,
      clearChatbotRequestErrorNotice,
      hideImageProviderWarning,
      showImageProviderWarning,
      setVectorProviderNoticeMessage,
      getVectorProviderNotConfiguredMessage
    } = createChatbotProviderNotices({
      builder,
      imageGenerationRow,
      getContextSettingsContainer: () => getContextSettingsContainer()
    });
    const {saveStatus, setSaveStatus, setSavedStatus, setSaveErrorStatus, handleActiveSaveError} = createChatbotSaveFeedback({
      builder,
      isBotCurrentlyActive
    });
    window.aipkit_showChatbotRequestErrorNotice = message => {
      showChatbotRequestErrorNotice(message);
    };
    window.aipkit_clearChatbotRequestErrorNotice = () => {
      clearChatbotRequestErrorNotice();
    };
    const {registerAdvancedDetailPanelCloser, closeOtherAdvancedDetailPanels} = bindChatbotDetailPanels();
    const webSearch = createChatbotWebSearch({
      builder,
      modelPopoverPanel,
      webSettingsModal,
      sheetOverlay,
      modelSupportsOpenRouterCapability,
      isToggleFieldOn,
      updatePopoverToggleAvailability,
      syncToolsEnabledOptionsFromFields,
      updateToolsFeatureRowsVisibility,
      syncInlineSettingsPanelState,
      mountInlineSettingsPanelForTrigger,
      registerAdvancedDetailPanelCloser,
      closeOtherAdvancedDetailPanels,
      __
    });
    const {updateVisibility: updateWebGroundingVisibility} = webSearch;
    const {
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
    } = createChatbotKnowledgeSettings({
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
      saveContextSettingsAfterCapabilityChange: (...args) => saveContextSettingsAfterCapabilityChange?.(...args),
      syncTrainingUiState: (...args) => syncTrainingUiState?.(...args)
    });
    const updateTokenLimitVisibility = () => syncChatbotLimitVisibility(limitsSettingsPanel);
    const audio = createChatbotAudio({
      builder,
      audioSettingsModal,
      isToggleFieldOn,
      syncToolsEnabledOptionsFromFields
    });
    const {updateAudioVisibility} = audio;
    const {syncThemeRadiosFromSelect} = appearance;
    const {updateImageProviderWarning} = createChatbotImageWarning({
      imageModelSelectField,
      hideImageProviderWarning,
      showImageProviderWarning
    });
    const popup = createChatbotPopup({
      builder,
      popupSettingsPanel,
      appearance
    });
    const {updatePopupSettingsVisibility} = popup;
    appearance.bindMedia({
      updatePopupSettingsVisibility,
      setSaveStatus
    });
    const conversation = createChatbotConversation({
      builder,
      modelPopoverPanel,
      startersPanel,
      consentPanel,
      syncInlineSettingsPanelState,
      registerAdvancedDetailPanelCloser,
      closeOtherAdvancedDetailPanels,
      __,
      sprintf
    });
    const {closeStartersPanel, updateConsentControls} = conversation;
    const syncStarterList = conversation.bindStarterList();
    const modelSettings = createChatbotModelSettings({
      builder,
      modelPopoverPanel,
      sheetOverlay,
      isReasoningSupportedForCurrentSelection,
      modelSupportsOpenRouterCapability,
      modelSupportsXaiImageInput,
      syncToolsEnabledOptionsFromFields,
      getCurrentChatbotProvider,
      getCurrentChatbotModel
    });
    const {updateVisibility: updateConversationVisibility} = modelSettings;
    bindChatbotShortcodeCopy(builder);
    // Controls in a newly shown tab need their visibility rules and range readouts refreshed.
    bindChatbotBotSwitcher(builder);
    bindChatbotSegmented(builder);
    bindChatbotPlacement(builder);
    bindChatbotPreviewDevice(builder);
    bindChatbotSearchSettings(builder, { __, _n, sprintf });
    let featureDrawers = null;
    bindChatbotTabs(builder, {
      onShow: () => {
        featureDrawers?.close({ restoreFocus: false });
        featureDrawers?.refresh();
        if (typeof window.aipkit_attachRangeValueHandlers === "function") {
          window.aipkit_attachRangeValueHandlers("#aipkit_chatbot_settings_panel");
        }
        updateWebGroundingVisibility();
        updateAudioVisibility();
        updateConversationVisibility();
        updatePopupSettingsVisibility();
        updateContextPopoverControls();
      }
    });
    // --- Chatbot Settings Panel ---
        if (settingsPanel && !builder.dataset.settingsPanelBound) {
      const syncSettingsSections = () => {
        closeOtherAdvancedDetailPanels();
      };
      syncSettingsSectionsUiState = syncSettingsSections;
      syncSettingsSections();
      // Initialise settings controls after the drawer content is available.
            if (typeof window.aipkit_attachRangeValueHandlers === "function") {
        window.aipkit_attachRangeValueHandlers("#aipkit_chatbot_settings_panel");
      }
      updateWebGroundingVisibility();
      updateConversationVisibility();
      updateContextPopoverControls();
      builder.dataset.settingsPanelBound = "1";
    }
    bindChatbotNavigation({
      builder,
      botSelect,
      newBotBtn,
      botActions,
      botActionsTrigger,
      botActionsMenu,
      layout,
      __,
      setSaveStatus,
      setSavedStatus,
      setSaveErrorStatus,
      consumeBotSwitchPreviewOptions,
      switchToBotState,
      primeBotSwitchStateCache: botStateCache.prime,
      applyInsertedBotResponse,
      getCurrentActiveBotId,
      syncDeleteActionsState,
      isDefaultBotId,
      applyBotStateResponse,
      getPreferredBotAfterDeleteUi,
      applyDeleteBotInPlace,
      withRecordAction: recordActions.run,
      // Read failure state after the asynchronous switch resolves.
      getBotSwitchFailure,
      storageKey: LAST_CHATBOT_STORAGE_KEY
    });
    chatbotPanels.bindSheet({
      updateWebGroundingVisibility,
      updateAudioVisibility,
      updateConversationVisibility,
      updatePopupSettingsVisibility
    });
    bindChatbotSettingsRows({
      builder,
      modelPopoverPanel,
      conversation,
      appearance,
      registerAdvancedDetailPanelCloser
    });
    const builderPersistence = {
      recordActions,
      getSelectedBuilderBotId,
      setSaveStatus,
      handleBotSaveSuccess,
      isBotCurrentlyActive,
      setSavedStatus,
      handleActiveSaveError,
      bindBotStateAppliedReset
    };
    bindChatbotInstructions({
      builder,
      instructionsField,
      instructionsExpandBtn,
      instructionsModal,
      instructionsModalCloseBtn,
      instructionsModalTextarea,
      instructionsModalCount,
      saveStatus,
      persistence: builderPersistence,
      __,
      _n,
      sprintf
    });
    training.bindSourcePicker();
    triggerAutosave = window.aipkit_bindTriggerAutosave?.({
      builder,
      saveStatus,
      persistence: builderPersistence,
      onSaved: (botId, triggersJson) => {
        const cached = botStateCache.get(botId);
        if (cached) botStateCache.store({
          ...cached,
          triggers_json: triggersJson
        });
      }
    });
    if (webSettingsModal && !webSettingsModal.dataset.bound) {
      webSearch.bindPanel();
    }
    bindChatbotImagePanel({
      builder,
      imageSettingsModal,
      imageGenerationRow,
      closeOtherAdvancedDetailPanels,
      mountInlineSettingsPanelForTrigger,
      syncInlineSettingsPanelState,
      updateImageProviderWarning,
      registerAdvancedDetailPanelCloser,
      isToggleFieldOn
    });
    audio.bindPanel();
    featureDrawers = bindChatbotFeatureDrawers(builder, { closeOtherAdvancedDetailPanels, __, _n, sprintf });
    bindChatbotName({
      builder,
      botNameField,
      botSelect,
      saveStatus,
      persistence: builderPersistence,
      getSavedName: botId => botStateCache.get(botId)?.bot_name,
      updateAvailableBotsDataset
    });
    if (saveStatus && !builder.dataset.modelAutosaveBound) {
      modelSettings.bindSelection({
        persistence: builderPersistence,
        coordinateKnowledgeForChatbotProvider,
        syncUnifiedModelSelector,
        restoreSelection: (provider, model) => {
          stateHydration.applyModel(builder, provider, model);
          window.aipkit_toggleChatbotModelFields?.(builder);
        },
        updateWebGroundingVisibility,
        updateConversationVisibility,
        updateContextPopoverControls,
        updateFileUploadAvailability,
        refreshVectorProviderNotice,
        isMissingProviderCredentialMessage,
        saveContextSettingsAfterCapabilityChange: () => saveContextSettingsAfterCapabilityChange(),
        syncTrainingUiState: () => {
          syncTrainingUiState?.();
        }
      });
    }
    syncDeployUiState = bindChatbotDeployment({
      builder,
      saveStatus,
      popupOnlyControls,
      // These callbacks are assigned by later controls and must stay late-bound.
      syncSettingsSectionsUiState: () => {
        syncSettingsSectionsUiState?.();
      },
      syncInterfaceControlsUiState: () => {
        syncInterfaceControlsUiState?.();
      },
      updateAudioVisibility,
      persistence: builderPersistence
    });
    if (modelPopoverPanel && saveStatus && !builder.dataset.aiParamsAutosaveBound) {
      modelSettings.bindResponse(builderPersistence);
    }
    if ((webSettingsModal || modelPopoverPanel) && saveStatus && !builder.dataset.webSettingsAutosaveBound) {
      saveWebSettingsAfterKnowledgeChange = webSearch.bindPersistence({
        ...builderPersistence,
        getChatbotCapabilityState,
        setKnowledgeEnabledWithoutChangeEvent,
        saveContextSettingsAfterCapabilityChange: () => saveContextSettingsAfterCapabilityChange(),
        syncTrainingUiState: () => {
          syncTrainingUiState?.();
        }
      });
    }
    ({syncTrainingUiState, saveContextSettingsAfterCapabilityChange} = training.bindSettings({
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
    }));
    bindChatbotLimits({
      builder,
      limitsSettingsContainer: limitsSettingsPanel,
      saveStatus,
      updateTokenLimitVisibility,
      persistence: builderPersistence
    });
    const appearancePanel = settingsPanel ? settingsPanel.querySelector('[data-aipkit-settings-panel="appearance"]') : null;
    if (appearancePanel && saveStatus && !builder.dataset.styleSettingsAutosaveBound) {
      const interfaceControlsRoot = settingsPanel.querySelector('[data-aipkit-interface-controls]');
      const interfaceControlsSection = interfaceControlsRoot?.closest('.aipkit_settings_panel_body') || appearancePanel;
      const {updateConversationStartersControls, syncInterfaceControlsUiState: syncInterfaceControls} = appearance.bindInterface({
        interfaceControlsSection,
        closeStartersPanel
      });
      appearance.bindTheme();
      // Loading, restoring or resetting a bot sets the starters field directly, so redraw its list too.
      const syncConversationStarters = () => {
        updateConversationStartersControls();
        syncStarterList();
      };
      syncConversationStartersUiState = syncConversationStarters;
      syncInterfaceControlsUiState = syncInterfaceControls;
      syncThemeUiState = syncThemeRadiosFromSelect;
      appearance.bindPersistence({
        settingsPanel,
        styleSection: appearancePanel,
        interfaceControlsSection,
        startersPanel,
        consentPanel,
        persistence: builderPersistence,
        updateConsentControls,
        updateConversationStartersControls: syncConversationStarters,
        syncInterfaceControls
      });
    }
    if (sheetOverlay && saveStatus && !builder.dataset.imageSettingsAutosaveBound) {
      bindChatbotImageSettings({
        builder,
        modelPopoverPanel,
        sheetOverlay,
        persistence: builderPersistence,
        updateImageProviderWarning,
        syncToolsEnabledOptionsFromFields
      });
      syncImageModelUiState = updateImageProviderWarning;
    }
    if (audioSettingsModal && saveStatus && !builder.dataset.audioSettingsAutosaveBound) {
      syncAudioUiState = audio.bindPersistence(builderPersistence);
    }
    if (popupSettingsPanel && saveStatus && !builder.dataset.popupSettingsAutosaveBound) {
      syncPopupSettingsUiState = popup.bindPersistence(builderPersistence);
    }
    if (modelPopoverPanel && saveStatus && !builder.dataset.conversationSettingsAutosaveBound) {
      modelSettings.bindPersistence(builderPersistence);
    }
    if (modelPopoverPanel && saveStatus && !builder.dataset.fileUploadAutosaveBound) {
      bindChatbotFileUploadSettings({
        builder,
        modelPopoverPanel,
        persistence: builderPersistence,
        syncToolsEnabledOptionsFromFields
      });
    }
    startChatbotEditorPreview({
      builder,
      getSelectableBotIds,
      getSelectedBuilderBotId
    });
  }
  window.aipkit_initChatbotBuilder = aipkit_initChatbotBuilder;
})();

/**
 * AIPKit Admin Chat - Main Initialization
 *
 * Orchestrates various sub-initializers for the Chatbot module.
 */ (function() {
  "use strict";
  /**
   * Initializes the AIPKit Chat Admin UI by calling sub-initializer functions.
   * Called by the main dashboard loader when the 'chatbot' module is loaded.
   */  function aipkit_initChatbot() {
    // Required shared controls must load before the editor.
    if (typeof window.aipkit_setButtonText !== "function" || typeof window.aipkit_copyShortcode !== "function") {
      console.error("AIPKit Chat Admin Main: Essential utility functions not found (e.g., aipkit_setButtonText, aipkit_copyShortcode). Check utils/");
      const mainContainer = document.querySelector(".aipkit_chatbot_module_container");
      if (mainContainer) mainContainer.innerHTML = '<p style="color:red; padding:20px;">Critical Error: UI utility scripts failed to load correctly.</p>';
      return;
      // Halt initialization
        }
    if (typeof window.aipkit_escapeHtml !== "function") {
      console.error("AIPKit Chat Admin Main: Essential log utility functions (like aipkit_escapeHtml) not found. Check the shared HTML utility.");
      // Allow to continue, but some parts of logs might not render correctly if this is missing.
        }
    // --- Initialize Components ---
    // Call each initializer, checking if the function exists first for robustness.
    // Provider fields
        if (typeof window.aipkit_initProviderSelectToggles === "function") {
      window.aipkit_initProviderSelectToggles();
    } else {
      console.error("AIPKit Chat Admin Main: aipkit_initProviderSelectToggles function not found.");
    }
    if (typeof window.aipkit_initChatSelectPickers === "function") {
      window.aipkit_initChatSelectPickers();
    } else {
      console.error("AIPKit Chat Admin Main: aipkit_initChatSelectPickers function not found.");
    }
    if (typeof window.aipkit_initChatEmbeddingPickers === "function") {
      window.aipkit_initChatEmbeddingPickers();
    } else {
      console.error("AIPKit Chat Admin Main: aipkit_initChatEmbeddingPickers function not found.");
    }
    if (typeof window.aipkit_initSyncButtons === "function") {
      window.aipkit_initSyncButtons();
    } else {
      console.error("AIPKit Chat Admin Main: aipkit_initSyncButtons function not found.");
    }
    if (typeof window.aipkit_initApiKeyToggles === "function") {
      window.aipkit_initApiKeyToggles(".aipkit_chatbot_module_container");
    } else {
      console.error("AIPKit Chat Admin Main: aipkit_initApiKeyToggles function not found.");
    }
    if (typeof window.aipkit_initChatThemeSettingsToggle === "function") {
      window.aipkit_initChatThemeSettingsToggle();
    } else {
      console.error("AIPKit Chat Admin Main: aipkit_initChatThemeSettingsToggle function not found.");
    }
    if (typeof window.aipkit_initCustomThemePresets === "function") {
      window.aipkit_initCustomThemePresets();
    } else {
      console.error("AIPKit Chat Admin Main: aipkit_initCustomThemePresets function not found.");
    }
    // 7) Range Sliders
        if (typeof window.aipkit_attachRangeValueHandlers === "function") {
      window.aipkit_attachRangeValueHandlers("#aipkit_chatbot_main_tab_content_container");
    } else {
      console.error("AIPKit Chat Admin Main: aipkit_attachRangeValueHandlers function not found.");
    }
    // 13) Load initial preview after the tabs are set
        if (typeof window.aipkit_loadInitialChatPreview === "function") {
      window.aipkit_loadInitialChatPreview();
    } else {
      console.error("AIPKit Chat Admin Main: aipkit_loadInitialChatPreview function not found.");
    }
    if (typeof window.aipkit_initChatbotBuilder === "function") {
      window.aipkit_initChatbotBuilder();
    }
    if (typeof window.aipkit_initEmbedCodeCopy === 'function') {
      window.aipkit_initEmbedCodeCopy();
    }
  }
  // Expose to global so the main dashboard loader can call it
    window.aipkit_initChatbot = aipkit_initChatbot;
})();
