import { bindChatbotSettingsAutosave } from "./state.js";
import {
  getChatbotProviderConfigured,
  updateChatbotProviderNotice,
  refreshChatbotProviderNotice,
} from "./providers.js";
/** Chatbot model, search and reasoning controls, plus model-sync requests. */
(function () {
  "use strict";

  const activeBotSelector =
    "#aipkit_chatbot_main_tab_content_container .aipkit_tab-content.aipkit_active ";

  const getControl = (settingsArea, selector, activeSelector = selector) =>
    settingsArea.querySelector(selector) ||
    document.querySelector(activeBotSelector + activeSelector);

  const getWebSearchToggle = (settingsArea) =>
    getControl(settingsArea, ".aipkit_openai_web_search_enable_toggle");

  const isWebSearchEnabled = (toggle) =>
    toggle
      ? toggle.tagName === "SELECT"
        ? toggle.value === "1"
        : toggle.checked
      : false;

  function aipkit_toggleChatbotModelFields(settingsArea) {
    if (!settingsArea) {
      console.warn(
        "AIPKit Chat Fields: No settings area provided to toggle model fields."
      );
      return;
    }
    const providerSelect = settingsArea.querySelector(
      ".aipkit_chatbot_provider_select"
    );
    if (!providerSelect) {
      console.warn(
        "AIPKit Chat Fields: Provider select not found in the given settings area."
      );
      return;
    }
    const selectedProvider = providerSelect.value;
    const modelFields = settingsArea.querySelectorAll(
      ".aipkit_chatbot_model_field"
    );

    modelFields.forEach((field) => {
      field.style.display = "none";
    });

    const visibleModelField = settingsArea.querySelector(
      `.aipkit_chatbot_model_field[data-provider="${selectedProvider}"]`
    );
    if (visibleModelField) {
      visibleModelField.style.display = "block";

      if (typeof window.aipkit_toggleReasoningEffort === "function") {
        window.aipkit_toggleReasoningEffort(settingsArea);
      }
    }
  }
  window.aipkit_toggleChatbotModelFields = aipkit_toggleChatbotModelFields;

  function aipkit_toggleOpenAISpecificSettingsVisibility(settingsArea) {
    if (!settingsArea) return;

    if (typeof window.aipkit_toggleOpenAIWebSearchSubSettings === "function") {
      window.aipkit_toggleOpenAIWebSearchSubSettings(settingsArea);
    }
  }
  window.aipkit_toggleOpenAISpecificSettingsVisibility =
    aipkit_toggleOpenAISpecificSettingsVisibility;

  function aipkit_toggleOpenAIWebSearchSubSettings(settingsArea) {
    if (!settingsArea) return;

    const isProviderOpenAI = getProviderSelect(settingsArea)?.value === "OpenAI";
    const webSearchEnableToggle = getWebSearchToggle(settingsArea);
    const conditionalContainer = settingsArea.querySelector(
      ".aipkit_openai_web_search_conditional_settings"
    );

    if (!webSearchEnableToggle || !conditionalContainer) {
      return;
    }
    const isWebSearchActuallyEnabled = isWebSearchEnabled(webSearchEnableToggle);

    // Show sub-settings only if provider is OpenAI AND the feature is enabled
    conditionalContainer.style.display =
      isProviderOpenAI && isWebSearchActuallyEnabled ? "block" : "none";

    if (isProviderOpenAI && isWebSearchActuallyEnabled) {
      if (
        typeof window.aipkit_toggleOpenAIWebSearchLocationDetails === "function"
      ) {
        window.aipkit_toggleOpenAIWebSearchLocationDetails(settingsArea);
      }
    } else {
      const locationDetailsContainer = settingsArea.querySelector(
        ".aipkit_openai_web_search_location_details"
      );
      if (locationDetailsContainer) {
        locationDetailsContainer.style.display = "none";
      }
    }
  }
  window.aipkit_toggleOpenAIWebSearchSubSettings =
    aipkit_toggleOpenAIWebSearchSubSettings;

  function aipkit_toggleOpenAIWebSearchLocationDetails(settingsArea) {
    if (!settingsArea) return;

    const webSearchEnableToggle = getWebSearchToggle(settingsArea);

    const locTypeSelect = settingsArea.querySelector(
      ".aipkit_openai_web_search_loc_type_select"
    );
    const locDetailsContainer = settingsArea.querySelector(
      ".aipkit_openai_web_search_location_details"
    );

    if (!locTypeSelect || !locDetailsContainer) {
      if (locDetailsContainer) locDetailsContainer.style.display = "none";
      return;
    }

    const isApproximateLocation = (locTypeSelect.value || "") === "approximate";

    // The location details should only be visible if web search is enabled AND the location type is 'approximate'.
    locDetailsContainer.style.display =
      isWebSearchEnabled(webSearchEnableToggle) && isApproximateLocation ? "block" : "none";
  }
  window.aipkit_toggleOpenAIWebSearchLocationDetails =
    aipkit_toggleOpenAIWebSearchLocationDetails;

  function getDashboardText(key, fallback) {
    const text = window.aipkit_dashboard && window.aipkit_dashboard.text
      ? window.aipkit_dashboard.text
      : {};
    return typeof text[key] === "string" && text[key].trim() !== ""
      ? text[key].trim()
      : fallback;
  }

  function getReasoningUtils() {
    return (
      window.aipkit_reasoning_effort_utils ||
      window.aipkit_autogpt_reasoning_utils ||
      null
    );
  }

  function getProviderSelect(settingsArea) {
    return getControl(
      settingsArea,
      ".aipkit_chatbot_provider_select",
      ".aipkit_chatbot_settings_form .aipkit_chatbot_provider_select"
    );
  }

  function getModelSelect(settingsArea, provider) {
    if (!provider) return null;
    const selector = `.aipkit_chatbot_model_field[data-provider="${provider}"] select`;
    return getControl(settingsArea, selector, ".aipkit_chatbot_settings_form " + selector);
  }

  function normalizeReasoningValue(currentValue, values, defaultValue) {
    if (!Array.isArray(values) || values.length === 0) {
      return "";
    }

    let normalized = currentValue || "";
    if (normalized === "minimal") {
      normalized = "low";
    }
    if (normalized === "max" && !values.includes("max")) {
      normalized = defaultValue;
    }
    if (normalized === "xhigh" && !values.includes("xhigh") && values.includes("high")) {
      normalized = "high";
    }
    if (!values.includes(normalized) && normalized && normalized !== "none" && values.includes("medium")) {
      normalized = "medium";
    }
    if (!values.includes(normalized)) {
      normalized = defaultValue;
    }
    if (!values.includes(normalized)) {
      normalized = values[0] || "";
    }

    return normalized;
  }

  function populateReasoningSelect(selectEl, values, labels, defaultValue, savedValue) {
    if (!selectEl) {
      return;
    }

    const currentValue = normalizeReasoningValue(
      savedValue === undefined ? selectEl.value || "" : String(savedValue || ""),
      values,
      defaultValue
    );

    selectEl.textContent = "";
    values.forEach((value, index) => {
      const option = document.createElement("option");
      option.value = value;
      option.textContent = labels[index] || value;
      if (value === currentValue) {
        option.selected = true;
      }
      selectEl.appendChild(option);
    });
    selectEl.value = currentValue;
  }

  function getReasoningUiState(settingsArea) {
    const providerSelect = getProviderSelect(settingsArea);
    const provider = providerSelect ? providerSelect.value : "";
    const labelText = getDashboardText("reasoningLabel", "Reasoning");
    const defaultState = {
      provider,
      model: "",
      supported: false,
      labelText,
      helpText: getDashboardText(
        "reasoningHelpGeneric",
        ""
      ),
      disabledTitle: getDashboardText(
        "openaiOllamaReasoningOnly",
        "Available for OpenAI reasoning models and Ollama thinking-capable models"
      ),
      allowed: [],
      labels: [],
      defaultValue: "none",
    };

    if (!providerSelect) {
      return defaultState;
    }

    const modelSelect = getModelSelect(settingsArea, provider);
    const model = modelSelect ? modelSelect.value || "" : "";
    const modelLower = model.toLowerCase();
    const reasoningUtils = getReasoningUtils();

    if (provider === "OpenAI") {
      const supportsOpenAIReasoning =
        reasoningUtils &&
        typeof reasoningUtils.isOpenAIReasoningModel === "function" &&
        typeof reasoningUtils.getReasoningRule === "function" &&
        reasoningUtils.isOpenAIReasoningModel(modelLower);

      if (!modelLower || !supportsOpenAIReasoning) {
        return {
          ...defaultState,
          provider,
          model,
          helpText: getDashboardText(
            "reasoningHelpOpenAI",
            ""
          ),
        };
      }

      const rule = reasoningUtils.getReasoningRule(modelLower);
      return {
        provider,
        model,
        supported: true,
        labelText,
        helpText: getDashboardText(
          "reasoningHelpOpenAI",
          ""
        ),
        disabledTitle: "",
        allowed: rule.allowed,
        labels: rule.allowed.map((value) => (value === "medium" ? "med" : value)),
        defaultValue: rule.defaultValue,
      };
    }

    if (
      provider === "OpenRouter" &&
      reasoningUtils &&
      typeof reasoningUtils.getOpenRouterReasoningRule === "function"
    ) {
      const rule = reasoningUtils.getOpenRouterReasoningRule(model);
      if (!rule.supported) {
        return {
          ...defaultState,
          provider,
          model,
          helpText: getDashboardText("reasoningHelpOpenRouter", ""),
        };
      }

      return {
        provider,
        model,
        supported: true,
        labelText,
        helpText: getDashboardText("reasoningHelpOpenRouter", ""),
        disabledTitle: "",
        allowed: rule.allowed,
        labels: rule.allowed.map((value) =>
          value === "none"
            ? getDashboardText("offLabel", "off")
            : value === "medium"
              ? "med"
              : value
        ),
        defaultValue: rule.defaultValue,
      };
    }

    if (
      provider === "AIPufferCloud" &&
      reasoningUtils &&
      typeof reasoningUtils.getCloudReasoningRule === "function"
    ) {
      const rule = reasoningUtils.getCloudReasoningRule(model);
      if (!rule.supported) {
        return { ...defaultState, provider, model };
      }
      return {
        provider,
        model,
        supported: true,
        labelText,
        helpText: getDashboardText("reasoningHelpCloud", ""),
        disabledTitle: "",
        allowed: rule.allowed,
        labels: rule.allowed.map((value) =>
          value === "none"
            ? getDashboardText("offLabel", "off")
            : value === "medium"
              ? "med"
              : value
        ),
        defaultValue: rule.defaultValue,
      };
    }

    if (provider === "Ollama" && modelLower) {
      const isGptOss = modelLower.includes("gpt-oss");
      const allowed = isGptOss
        ? ["none", "low", "medium", "high"]
        : ["none", "medium"];
      const labels = isGptOss
        ? [
            getDashboardText("offLabel", "off"),
            "low",
            "med",
            "high",
          ]
        : [
            getDashboardText("offLabel", "off"),
            getDashboardText("onLabel", "on"),
          ];

      return {
        provider,
        model,
        supported: true,
        labelText: getDashboardText("thinkingLabel", "Thinking"),
        helpText: getDashboardText(
          "reasoningHelpOllama",
          "Maps to Ollama think mode. GPT-OSS uses levels, while most other thinking-capable Ollama models use on or off."
        ),
        disabledTitle: "",
        allowed,
        labels,
        defaultValue: "medium",
      };
    }

    return defaultState;
  }

  function getChatbotReasoningValue(settingsArea) {
    const uiState = getReasoningUiState(settingsArea);
    const select = settingsArea.querySelector('select[name="reasoning_effort"]');
    return uiState.supported && select
      ? normalizeReasoningValue(select.value, uiState.allowed, uiState.defaultValue)
      : "";
  }

  function aipkit_toggleReasoningEffort(settingsArea, savedValue) {
    if (!settingsArea) return;

    const providerSelect = getProviderSelect(settingsArea);
    const reasoningField = settingsArea.querySelector(
      ".aipkit_reasoning_effort_field"
    );
    if (!reasoningField) return;

    const selectFallback = reasoningField.querySelector('select[name="reasoning_effort"]');
    const uiState = getReasoningUiState(settingsArea);
    reasoningField
      .querySelectorAll(".aipkit_reasoning_effort_label_text")
      .forEach((node) => {
        node.textContent = uiState.labelText;
      });

    const supported = Boolean(providerSelect && uiState.supported);
    reasoningField.classList[supported ? "remove" : "add"]("aipkit_hidden");
    if (selectFallback) selectFallback.disabled = !supported;
    reasoningField.style.opacity = "";
    reasoningField.removeAttribute("title");
    if (!providerSelect) return;

    if (uiState.supported) {
      populateReasoningSelect(
        selectFallback,
        uiState.allowed,
        uiState.labels,
        uiState.defaultValue,
        savedValue
      );
      reasoningField.setAttribute("title", uiState.helpText);
    } else {
      if (savedValue !== undefined) {
        // Hydration must replace the previous bot's value even while hidden.
        const savedEffort = savedValue === "minimal" ? "low" : String(savedValue || "");
        populateReasoningSelect(
          selectFallback,
          savedEffort ? [savedEffort] : [],
          [],
          savedEffort,
          savedEffort
        );
      }
      reasoningField.setAttribute("title", uiState.disabledTitle || "");
    }
  }

  window.aipkit_toggleReasoningEffort = aipkit_toggleReasoningEffort;
  window.aipkit_getChatbotReasoningUiState = getReasoningUiState;
  window.aipkit_getChatbotReasoningValue = getChatbotReasoningValue;

  const providerStatusMap = {
    AIPufferCloud: "aipuffercloud",
    OpenAI: "openai",
    OpenRouter: "openrouter",
    Google: "google",
    Azure: "azure",
    Claude: "claude",
    DeepSeek: "deepseek",
    xAI: "xai",
    Ollama: "ollama",
  };

  function isProviderReady(provider) {
    const statusKey = providerStatusMap[provider];
    if (!statusKey) {
      return true;
    }
    const status =
      window.aipkit_dashboard?.providerStatus?.[statusKey];
    if (typeof status === "undefined") {
      return true;
    }
    return Boolean(status);
  }

  function aipkit_requestChatbotModelSync(provider, options = {}) {
    if (!provider) {
      return null;
    }
    if (typeof window.aipkit_queueProviderSync !== "function") {
      console.warn(
        "AIPKit Chatbot Sync: aipkit_queueProviderSync is not available."
      );
      return null;
    }
    const shouldForce = Boolean(options && options.force === true);
    if (!shouldForce && !isProviderReady(provider)) {
      return null;
    }
    return window.aipkit_queueProviderSync(provider, options);
  }

  window.aipkit_requestChatbotModelSync = aipkit_requestChatbotModelSync;
})();

const readModelSetting = (panel, name) => {
  const field = panel.querySelector(`[name="${name}"]`);
  return field ? field.value : "";
};

export function readChatbotModelValue(builder, provider) {
  if (!provider) {
    return "";
  }
  const field = builder.querySelector(
    `.aipkit_chatbot_model_field[data-provider="${provider}"]`
  );
  if (!field) {
    return "";
  }
  const select = field.querySelector("select");
  return select ? select.value : "";
}

/** Builder provider selection, response, memory and reasoning controls. */
export function createChatbotModelSettings({
  builder,
  modelPopoverPanel,
  sheetOverlay,
  isReasoningSupportedForCurrentSelection,
  modelSupportsOpenRouterCapability,
  modelSupportsXaiImageInput,
  syncToolsEnabledOptionsFromFields,
  getCurrentChatbotProvider,
  getCurrentChatbotModel,
}) {
  const updateVisibility = () => {
    if (!modelPopoverPanel) {
      return;
    }

    const providerSelect = builder.querySelector(
      ".aipkit_chatbot_provider_select"
    );
    const provider = providerSelect ? providerSelect.value : "";
    const isReasoningModel = isReasoningSupportedForCurrentSelection();
    const openrouterSupportsImageInput = modelSupportsOpenRouterCapability(
      "image_input"
    );
    const xaiSupportsImageInput = modelSupportsXaiImageInput();
    const supportsImageAnalysis =
      (provider === "AIPufferCloud" && (window.aipkit_dashboard?.models?.aipuffercloud || []).some(model => model.id === getCurrentChatbotModel(provider) && model.capabilities?.image_input === true)) ||
      provider === "OpenAI" ||
      provider === "Google" ||
      provider === "Claude" ||
      (provider === "xAI" && xaiSupportsImageInput) ||
      (provider === "OpenRouter" && openrouterSupportsImageInput);
    modelPopoverPanel
      .querySelectorAll(".aipkit_stateful_convo_group")
      .forEach((statefulGroup) => {
        const isActiveProvider = statefulGroup.dataset.provider === provider;
        statefulGroup.style.display = isActiveProvider ? "" : "none";
        statefulGroup
          .querySelectorAll(".aipkit_stateful_convo_checkbox")
          .forEach((field) => {
            field.disabled = !isActiveProvider;
          });
        statefulGroup.removeAttribute("title");
      });

    const reasoningField = modelPopoverPanel.querySelector(
      ".aipkit_reasoning_effort_field"
    );
    if (reasoningField) {
      reasoningField.style.display = isReasoningModel ? "" : "none";
    }
    const imageAnalysisRow =
      modelPopoverPanel.querySelector(".aipkit_image_analysis_popover_row");
    if (imageAnalysisRow) {
      imageAnalysisRow.style.display = supportsImageAnalysis ? "" : "none";
    }
    if (
      (provider === "OpenRouter" && !openrouterSupportsImageInput) ||
      (provider === "xAI" && !xaiSupportsImageInput)
    ) {
      const analysisSelect =
        modelPopoverPanel.querySelector(".aipkit_image_analysis_select") ||
        sheetOverlay.querySelector(".aipkit_image_analysis_select");
      const analysisToggle =
        modelPopoverPanel.querySelector(".aipkit_image_analysis_checkbox") ||
        sheetOverlay.querySelector(".aipkit_image_analysis_checkbox");
      if (analysisSelect && analysisSelect.value !== "0") {
        analysisSelect.value = "0";
        analysisSelect.dispatchEvent(new Event("change", { bubbles: true }));
      } else if (analysisToggle && analysisToggle.checked) {
        analysisToggle.checked = false;
        analysisToggle.dispatchEvent(new Event("change", { bubbles: true }));
      }
    }

    syncToolsEnabledOptionsFromFields();
    if (typeof window.aipkit_toggleReasoningEffort === "function") {
      window.aipkit_toggleReasoningEffort(modelPopoverPanel);
    }

    // Update last visible row styling for proper border handling
    const activePanel = modelPopoverPanel.querySelector(
      ".aipkit_model_settings_panel:not([hidden])"
    );
    const optionsList = activePanel
      ? activePanel.querySelector(".aipkit_popover_options_list")
      : modelPopoverPanel.querySelector(".aipkit_popover_options_list");
    if (optionsList) {
      const allRows = optionsList.querySelectorAll(".aipkit_popover_option_row");
      allRows.forEach((row) => row.classList.remove("aipkit_last_visible_row"));

      // Find the last visible row
      let lastVisibleRow = null;
      allRows.forEach((row) => {
        if (row.style.display !== "none") {
          lastVisibleRow = row;
        }
      });
      if (lastVisibleRow) {
        lastVisibleRow.classList.add("aipkit_last_visible_row");
      }
    }
  };

  return {
    updateVisibility,
    bindSelection({
      persistence,
      coordinateKnowledgeForChatbotProvider,
      syncUnifiedModelSelector,
      restoreSelection,
      updateWebGroundingVisibility,
      updateConversationVisibility,
      updateContextPopoverControls,
      updateFileUploadAvailability,
      refreshVectorProviderNotice,
      isMissingProviderCredentialMessage,
      saveContextSettingsAfterCapabilityChange,
      syncTrainingUiState,
    }) {
      const providerSelect = builder.querySelector(".aipkit_chatbot_provider_select");
      if (!providerSelect) return;
      const providerNoticeKeyMap = {
        AIPufferCloud: "aipuffercloud",
        OpenAI: "openai",
        OpenRouter: "openrouter",
        Google: "google",
        Azure: "azure",
        Claude: "claude",
        DeepSeek: "deepseek",
        xAI: "xai",
        Ollama: "ollama",
        Replicate: "replicate",
      };
      const getProviderNoticeElement = () => {
        const targetId = String(
          providerSelect.getAttribute("data-aipkit-provider-notice-target") || ""
        ).trim();
        if (!targetId) {
          return null;
        }
        return document.getElementById(targetId);
      };
      const isMissingProviderConfigError = (providerName, rawMessage) => {
        const configured = getChatbotProviderConfigured(
          providerNoticeKeyMap[providerName] || "", null
        );
        if (configured === false) {
          return true;
        }
        return isMissingProviderCredentialMessage(rawMessage);
      };
      const showProviderSyncErrorNotice = (rawMessage, providerName = "") => {
        const noticeEl = getProviderNoticeElement();
        if (!noticeEl) {
          return;
        }
        let message = String(rawMessage || "").trim() || "Sync failed.";
        const missingConfiguration =
          providerName &&
          isMissingProviderConfigError(providerName, message);
        if (missingConfiguration) {
          message = String(
            noticeEl.getAttribute("data-message-default") ||
            "Connect an AI provider to use this chatbot."
          ).trim();
        }
        const providerKey = providerNoticeKeyMap[providerName] || "";
        updateChatbotProviderNotice(noticeEl, {
          providerKey,
          message,
          visible: true,
          actionVisible: Boolean(missingConfiguration),
        });
      };
      const refreshProviderNotice = () =>
        refreshChatbotProviderNotice(builder, getProviderNoticeElement);

      const syncControls = (providerChanged) => {
        updateWebGroundingVisibility();
        updateConversationVisibility();
        if (providerChanged) {
          updateContextPopoverControls();
          updateFileUploadAvailability();
          refreshVectorProviderNotice();
        }
      };
      bindChatbotSettingsAutosave({
        builder, panel: builder, boundKey: "modelAutosaveBound",
        action: "aipkit_update_chatbot_model_settings", lane: "model-context",
        interactionNodes: () => [providerSelect, ...builder.querySelectorAll(".aipkit_chatbot_model_field"),
          builder.querySelector('[data-aipkit-unified-model-selector][data-aipkit-model-capability="text_generation"]')],
        persistence,
        isRelevant: target => target.matches(".aipkit_chatbot_provider_select") ||
          Boolean(target.closest(".aipkit_chatbot_model_field select")),
        readSettings: (event) => {
          const target = event?.target;
          const providerChanged = target?.matches(".aipkit_chatbot_provider_select");
          const provider = providerChanged ? target.value || "" : providerSelect.value || "";
          // Model changes capture the chosen value before Knowledge coordination;
          // provider changes read that provider's field after coordination.
          let model = target && !providerChanged
            ? target.closest(".aipkit_chatbot_model_field select").value || "" : null;
          const knowledgeDisabled = target ? Boolean(coordinateKnowledgeForChatbotProvider(provider)) : false;
          if (model === null) model = readChatbotModelValue(builder, provider);
          if (target) syncUnifiedModelSelector(builder);
          return { provider, model,
            reasoning_effort: target ? window.aipkit_getChatbotReasoningValue?.(builder) || "" : "",
            knowledgeDisabled,
          };
        },
        isUnchanged: (a, b) => a.provider === b.provider && a.model === b.model,
        // Keep a pending provider change's Knowledge effect when a later model
        // choice coalesces that request before it reaches WordPress.
        mergePending: (previous, next) => ({ ...next,
          knowledgeDisabled: previous.knowledgeDisabled || next.knowledgeDisabled,
        }),
        makePayload: ({ provider, model, reasoning_effort }) => ({ provider, model, reasoning_effort }),
        savedSettings: (settings, response) => {
          const patch = { provider: settings.provider, model: settings.model };
          const saved = response?.bot?.settings;
          if (saved && Object.prototype.hasOwnProperty.call(saved, "reasoning_effort")) patch.reasoning_effort = saved.reasoning_effort;
          if (settings.knowledgeDisabled && saved && Object.prototype.hasOwnProperty.call(saved, "enable_vector_store")) {
            patch.enable_vector_store = saved.enable_vector_store;
          }
          return patch;
        },
        restoreSettings: ({ provider, model }) => {
          restoreSelection(provider, model);
          coordinateKnowledgeForChatbotProvider(provider);
          syncControls(true);
        },
        afterChange: event => syncControls(event.target.matches(".aipkit_chatbot_provider_select")),
        onSaved: ({ provider, model }, response, isCurrent) => {
          saveContextSettingsAfterCapabilityChange();
          syncTrainingUiState?.();
          persistence.setSavedStatus(response);
          const selected = () => isCurrent() && providerSelect.value === provider &&
            readChatbotModelValue(builder, provider) === model;
          if (!selected() || typeof window.aipkit_requestChatbotModelSync !== "function") return;
          const showError = error => {
            if (selected()) showProviderSyncErrorNotice(error?.message || "Sync failed.", provider);
          };
          // Catalog refresh is independent of the acknowledged settings write.
          // Neither a slow refresh nor its failure holds the save lane open.
          try {
            const sync = window.aipkit_requestChatbotModelSync(provider, {
              silent: true, force: true, showErrors: false,
              showLocalStatus: false, propagateError: true,
            });
            if (sync && typeof sync.then === "function") {
              Promise.resolve(sync).then(() => {
                if (selected()) refreshProviderNotice();
              }).catch(showError);
            }
          } catch (error) { showError(error); }
        },
      });
    },
    bindResponse(persistence) {
      bindChatbotSettingsAutosave({
        builder, panel: modelPopoverPanel, boundKey: "aiParamsAutosaveBound",
        action: "aipkit_update_chatbot_ai_parameters",
        readSettings: () => ({
          temperature: readModelSetting(modelPopoverPanel, "temperature"),
          max_completion_tokens: readModelSetting(modelPopoverPanel, "max_completion_tokens"),
          max_messages: readModelSetting(modelPopoverPanel, "max_messages"),
        }),
        isUnchanged: (a, b) =>
          a.temperature === b.temperature &&
          a.max_completion_tokens === b.max_completion_tokens &&
          a.max_messages === b.max_messages,
        canSave: (settings) =>
          settings.temperature !== "" &&
          settings.max_completion_tokens !== "" &&
          settings.max_messages !== "",
        isRelevant: (target) => target.matches('[name="temperature"]') ||
          target.matches('[name="max_completion_tokens"]') || target.matches('[name="max_messages"]'),
        persistence,
      });
    },
    bindPersistence(persistence) {
      const collectSettings = () => {
        const readToggle = (selector) => {
          const field = modelPopoverPanel.querySelector(selector);
          if (!field) return "0";
          // Support both checkbox and select elements
          if (field.tagName === "SELECT") {
            return field.value === "1" ? "1" : "0";
          }
          return field.checked ? "1" : "0";
        };

        return {
          provider:
            builder.querySelector(".aipkit_chatbot_provider_select")?.value ||
            "",
          model: getCurrentChatbotModel(getCurrentChatbotProvider()),
          openai_conversation_state_enabled: readToggle(
            '[name="openai_conversation_state_enabled"]'
          ),
          google_conversation_state_enabled: readToggle(
            '[name="google_conversation_state_enabled"]'
          ),
          openrouter_session_stickiness: readToggle(
            '[name="openrouter_session_stickiness"]'
          ),
          reasoning_effort: readModelSetting(modelPopoverPanel, "reasoning_effort"),
        };
      };

      bindChatbotSettingsAutosave({
        builder, panel: modelPopoverPanel, boundKey: "conversationSettingsAutosaveBound",
        action: "aipkit_update_chatbot_conversation_settings", lane: "model-context",
        readSettings: collectSettings,
        // Provider/model are reasoning context, not fields owned by this endpoint.
        ownedSettings: settings => Object.fromEntries(Object.entries(settings).filter(
          ([name]) => name !== "provider" && name !== "model"
        )),
        isUnchanged: (a, b) => JSON.stringify(a) === JSON.stringify(b),
        isRelevant: (target) => target.matches(".aipkit_stateful_convo_checkbox") ||
          target.matches('[name="reasoning_effort"]'),
        beforeRead: updateVisibility,
        persistence,
      });
    },
  };
}

/** Shared model-picker catalog and selection adapter for the Chatbot builder. */
export function createChatbotModelPicker({ builder, __ }) {
  const getSelectedOptionLabel = (selectElement) => {
    if (!selectElement) {
      return "";
    }
    const selectedOption =
      selectElement.selectedOptions && selectElement.selectedOptions.length
        ? selectElement.selectedOptions[0]
        : selectElement.options &&
          selectElement.selectedIndex >= 0 &&
          selectElement.selectedIndex < selectElement.options.length
          ? selectElement.options[selectElement.selectedIndex]
          : null;
    return selectedOption && selectedOption.textContent
      ? selectedOption.textContent.trim()
      : "";
  };
  const escapeUnifiedSelectorValue = (value) => {
    if (window.CSS && typeof window.CSS.escape === "function") {
      return window.CSS.escape(String(value || ""));
    }
    return String(value || "").replace(/["\\]/g, "\\$&");
  };
  const getUnifiedModelStateSelect = (scope, provider) => {
    if (!scope || !provider) {
      return null;
    }
    const field = scope.querySelector(
      `.aipkit_chatbot_model_field[data-provider="${escapeUnifiedSelectorValue(
        provider
      )}"]`
    );
    return field ? field.querySelector("select") : null;
  };
  const getUnifiedProviderLabel = (providerSelect, provider) => {
    if (!providerSelect || !provider) {
      return provider || "";
    }
    const option = Array.from(providerSelect.options || []).find(
      (item) => item.value === provider
    );
    const label = option && option.textContent ? option.textContent.trim() : "";
    return label || provider;
  };
  const isUnifiedPlaceholderModel = (option) => {
    const value = String(option?.value || "").trim();
    const label = String(option?.textContent || "").trim();
    return !value || /^\(/.test(label);
  };
  const getUnifiedModelGroups = (scope) => {
    const providerSelect = scope
      ? scope.querySelector(".aipkit_chatbot_provider_select")
      : null;
    const recommended = [];
    const groups = new Map();
    if (!providerSelect) {
      return { recommended, groups };
    }
    Array.from(providerSelect.options || []).forEach((providerOption) => {
      const provider = providerOption.value || "";
      if (!provider || providerOption.disabled) {
        return;
      }
      const modelSelect = getUnifiedModelStateSelect(scope, provider);
      if (!modelSelect) {
        return;
      }
      const providerLabel = getUnifiedProviderLabel(providerSelect, provider);
      const seen = new Set();
      Array.from(modelSelect.options || []).forEach((option) => {
        if (option.disabled || isUnifiedPlaceholderModel(option)) {
          return;
        }
        const modelValue = option.value || "";
        const modelLabel =
          option.textContent && option.textContent.trim()
            ? option.textContent.trim()
            : modelValue;
        const optgroup =
          option.parentElement &&
          option.parentElement.tagName === "OPTGROUP"
            ? option.parentElement
            : null;
        const optgroupLabel = optgroup
          ? String(optgroup.getAttribute("label") || "").trim()
          : "";
        const isRecommended =
          option.dataset.recommended === "true" ||
          optgroupLabel.toLowerCase() === "recommended";
        const familyLabel = String(
          option.dataset.familyLabel ||
            (optgroupLabel &&
            !["recommended", "all models"].includes(
              optgroupLabel.toLowerCase()
            )
              ? optgroupLabel
              : providerLabel)
        );
        const familyKey = String(
          option.dataset.familyKey ||
            familyLabel.toLowerCase().replace(/[^a-z0-9]+/g, "-") ||
            "other"
        );
        const key = `${provider}::${modelValue}`;
        if (seen.has(key)) {
          return;
        }
        seen.add(key);
        const entry = {
          provider,
          providerLabel,
          modelValue,
          modelLabel,
          familyKey,
          familyLabel,
          familyOrder: Number(option.dataset.familyOrder || 999),
          familyCollapsed: option.dataset.familyCollapsed === "true",
          searchText: `${providerLabel} ${familyLabel} ${modelLabel} ${modelValue}`.toLowerCase(),
        };
        if (isRecommended) {
          recommended.push(entry);
          return;
        }
        if (!groups.has(providerLabel)) {
          groups.set(providerLabel, []);
        }
        groups.get(providerLabel).push(entry);
      });
    });
    return { recommended, groups };
  };
  const createChatbotUnifiedModelAdapter = (scope) => ({
    getGroups: () => getUnifiedModelGroups(scope),
    getProviders: () => {
      const providerSelect = scope.querySelector(
        ".aipkit_chatbot_provider_select"
      );
      return Array.from(providerSelect?.options || [])
        .filter((option) => option.value)
        .map((option) => ({
          provider: option.value,
          providerLabel: option.textContent?.trim() || option.value,
          disabled: option.disabled,
        }));
    },
    getSelection: () => {
      const providerSelect = scope.querySelector(
        ".aipkit_chatbot_provider_select"
      );
      const provider = providerSelect ? providerSelect.value || "" : "";
      const modelSelect = getUnifiedModelStateSelect(scope, provider);
      const modelValue = modelSelect ? modelSelect.value || "" : "";
      return {
        provider,
        modelValue,
        modelLabel:
          getSelectedOptionLabel(modelSelect) ||
          modelValue ||
          __("Select model", "gpt3-ai-content-generator"),
      };
    },
    onSelect: (provider, modelValue) => {
      const providerSelect = scope.querySelector(
        ".aipkit_chatbot_provider_select"
      );
      const modelSelect = getUnifiedModelStateSelect(scope, provider);
      if (!providerSelect || !modelSelect) {
        return;
      }
      providerSelect.value = provider;
      modelSelect.value = modelValue;
      if (typeof window.aipkit_toggleChatbotModelFields === "function") {
        const settingsArea = scope.querySelector(
          ".aipkit_chatbot-settings-area"
        );
        window.aipkit_toggleChatbotModelFields(settingsArea || scope);
      }
      if (typeof window.aipkit_toggleReasoningEffort === "function") {
        const settingsArea = scope.querySelector(
          ".aipkit_chatbot-settings-area"
        );
        window.aipkit_toggleReasoningEffort(settingsArea || scope);
      }
      providerSelect.dispatchEvent(new Event("change", { bubbles: true }));
    },
  });
  const syncUnifiedModelSelector = (scope = builder) => {
    if (!scope) {
      return;
    }
    const selector = scope.querySelector("[data-aipkit-unified-model-selector]");
    if (!selector) {
      return;
    }
    if (selector._aipkitUnifiedModelController) {
      selector._aipkitUnifiedModelController.sync();
      return;
    }
    bindUnifiedModelSelector(scope);
  };
  const bindUnifiedModelSelector = (scope = builder) => {
    const selector = scope
      ? scope.querySelector("[data-aipkit-unified-model-selector]")
      : null;
    if (!selector) {
      return;
    }
    if (typeof window.aipkit_createUnifiedModelSelector !== "function") {
      return;
    }
    window.aipkit_createUnifiedModelSelector(
      selector,
      createChatbotUnifiedModelAdapter(scope)
    );
  };
  return { bindUnifiedModelSelector, syncUnifiedModelSelector };
}

/** Model catalog sync UI; the shared provider queue retains transport ownership. */
export function bindChatbotModelSync({
  builder,
  __,
  syncUnifiedModelSelector,
  showChatbotRequestErrorNotice,
  clearChatbotRequestErrorNotice,
}) {
  const chatbotModelSyncButton = builder.querySelector(
    "[data-aipkit-chatbot-sync-models]"
  );
  const chatbotModelLastSynced = builder.querySelector(
    "[data-aipkit-model-last-synced]"
  );
  const chatbotModelProviderSelect = builder.querySelector(
    ".aipkit_chatbot_provider_select"
  );
  const chatbotModelSyncTimes = (() => {
    if (!chatbotModelSyncButton) {
      return {};
    }
    try {
      const parsed = JSON.parse(
        chatbotModelSyncButton.dataset.aipkitModelSyncTimes || "{}"
      );
      return parsed && typeof parsed === "object" ? parsed : {};
    } catch (error) {
      return {};
    }
  })();
  const refreshChatbotModelLastSynced = () => {
    if (!chatbotModelLastSynced) {
      return;
    }
    const provider = chatbotModelProviderSelect?.value || "";
    const timestamp = chatbotModelSyncTimes[provider];
    const text = typeof window.aipkit_formatModelLastSynced === "function"
      ? window.aipkit_formatModelLastSynced(timestamp)
      : "";
    chatbotModelLastSynced.textContent = text;
    chatbotModelLastSynced.hidden = text === "";
  };
  const showSyncUnavailable = () => showChatbotRequestErrorNotice(
    __("Model sync is not available. Please try again.", "gpt3-ai-content-generator")
  );
  const shouldBindChatbotModelSyncUi =
    chatbotModelSyncButton &&
    chatbotModelSyncButton.dataset.aipkitModelSyncBound !== "1";
  if (shouldBindChatbotModelSyncUi) {
    chatbotModelSyncButton.addEventListener("click", () => {
      if (chatbotModelSyncButton.disabled) {
        return;
      }
      const provider = chatbotModelProviderSelect?.value || "";
      if (
        !provider ||
        typeof window.aipkit_requestChatbotModelSync !== "function"
      ) {
        showSyncUnavailable();
        return;
      }
      clearChatbotRequestErrorNotice();
      const syncPromise = window.aipkit_requestChatbotModelSync(provider, {
        button: chatbotModelSyncButton,
        silent: true,
        force: true,
        showErrors: false,
        showSuccess: false,
        showLocalStatus: false,
        propagateError: true,
      });
      const syncLabel = chatbotModelSyncButton.querySelector(
        ".aipkit_btn-text"
      );
      const originalSyncLabel =
        syncLabel?.textContent ||
        __("Sync models", "gpt3-ai-content-generator");
      const managesQueuedVisualState =
        !chatbotModelSyncButton.classList.contains("aipkit_loading");
      const restoreQueuedVisualState = () => {
        if (!managesQueuedVisualState) {
          return;
        }
        chatbotModelSyncButton.classList.remove("aipkit_loading");
        chatbotModelSyncButton.disabled = false;
        chatbotModelSyncButton.setAttribute("aria-busy", "false");
        if (syncLabel) {
          syncLabel.textContent = originalSyncLabel;
        }
      };
      if (managesQueuedVisualState) {
        chatbotModelSyncButton.classList.add("aipkit_loading");
        chatbotModelSyncButton.disabled = true;
        chatbotModelSyncButton.setAttribute("aria-busy", "true");
        if (syncLabel) {
          syncLabel.textContent =
            chatbotModelSyncButton.dataset.aipkitSyncingLabel ||
            __("Syncing…", "gpt3-ai-content-generator");
        }
      }
      if (!syncPromise || typeof syncPromise.then !== "function") {
        restoreQueuedVisualState();
        showSyncUnavailable();
        return;
      }
      syncPromise
        .then(() => {
          if (document.documentElement.contains(builder)) {
            syncUnifiedModelSelector(builder);
          }
        })
        .catch((error) => {
          if (!document.documentElement.contains(builder)) {
            return;
          }
          showChatbotRequestErrorNotice(
            error?.message ||
              __(
                "Could not sync models. Please try again.",
                "gpt3-ai-content-generator"
              )
          );
        })
        .finally(restoreQueuedVisualState);
    });
  }
  if (shouldBindChatbotModelSyncUi && chatbotModelProviderSelect) {
    chatbotModelProviderSelect.addEventListener(
      "change",
      refreshChatbotModelLastSynced
    );
  }
  const handleModelSyncComplete = (event) => {
    if (!document.documentElement.contains(builder)) {
      window.removeEventListener("aipkit:model-sync-complete", handleModelSyncComplete);
      return;
    }
    const provider = String(event?.detail?.provider || "");
    const syncedAt = Number(event?.detail?.syncedAt);
    if (!provider || !Number.isFinite(syncedAt) || syncedAt <= 0) {
      return;
    }
    chatbotModelSyncTimes[provider] = syncedAt;
    if ((chatbotModelProviderSelect?.value || "") === provider) {
      refreshChatbotModelLastSynced();
      syncUnifiedModelSelector(builder);
    }
  };
  if (shouldBindChatbotModelSyncUi) {
    window.addEventListener("aipkit:model-sync-complete", handleModelSyncComplete);
  }
  refreshChatbotModelLastSynced();
  if (shouldBindChatbotModelSyncUi && chatbotModelLastSynced) {
    const lastSyncedRefreshTimer = window.setInterval(() => {
      if (!document.documentElement.contains(builder)) {
        window.clearInterval(lastSyncedRefreshTimer);
        window.removeEventListener("aipkit:model-sync-complete", handleModelSyncComplete);
        return;
      }
      refreshChatbotModelLastSynced();
    }, 60000);
  }
  if (shouldBindChatbotModelSyncUi) {
    chatbotModelSyncButton.dataset.aipkitModelSyncBound = "1";
  }
}

/** Live provider/model state and catalog capability policy for Chatbot controls. */
export function createChatbotModelCapabilities({ builder }) {
  const OPENROUTER_DEFAULT_CAPABILITIES = {
    chat: true,
    stream: true,
    tools: false,
    web_search_tool: true,
    image_input: false,
    image_output: false,
    image_generation: false,
    embeddings: false,
  };
  const getModelCatalog = (provider) => {
    const models = window.aipkit_dashboard?.models?.[provider];
    return Array.isArray(models) ? models : [];
  };

  const getCurrentChatbotProvider = () => {
    const providerSelect = builder.querySelector(
      ".aipkit_chatbot_provider_select"
    );
    return providerSelect ? providerSelect.value : "";
  };
  const getCurrentChatbotModel = (provider) =>
    readChatbotModelValue(builder, provider) || "";
  const supportsOpenAIReasoningModel = (modelName) => {
    const normalizedModel = String(modelName || "").toLowerCase();
    return Boolean(
      window.aipkit_reasoning_effort_utils?.isOpenAIReasoningModel(
        normalizedModel
      )
    );
  };
  const isReasoningSupportedForCurrentSelection = () => {
    if (typeof window.aipkit_getChatbotReasoningUiState === "function") {
      const uiState = window.aipkit_getChatbotReasoningUiState(builder);
      return !!(uiState && uiState.supported);
    }
    const provider = getCurrentChatbotProvider();
    if (provider !== "OpenAI") {
      return false;
    }
    return supportsOpenAIReasoningModel(getCurrentChatbotModel(provider));
  };
  const getOpenRouterModelCapabilities = (modelId) => {
    if (!modelId) {
      return OPENROUTER_DEFAULT_CAPABILITIES;
    }
    const openrouterModels = getModelCatalog("openrouter");
    const normalizedTarget = String(modelId).trim().toLowerCase();
    if (!normalizedTarget) {
      return OPENROUTER_DEFAULT_CAPABILITIES;
    }
    const matchedModel = openrouterModels.find((model) => {
      const modelIdValue = model && model.id ? String(model.id) : "";
      return modelIdValue.trim().toLowerCase() === normalizedTarget;
    });
    if (
      !matchedModel ||
      !matchedModel.capabilities ||
      typeof matchedModel.capabilities !== "object"
    ) {
      return OPENROUTER_DEFAULT_CAPABILITIES;
    }
    return {
      ...OPENROUTER_DEFAULT_CAPABILITIES,
      ...matchedModel.capabilities,
    };
  };
  const modelSupportsOpenRouterCapability = (capabilityKey) => {
    if (!capabilityKey) {
      return true;
    }
    const provider = getCurrentChatbotProvider();
    if (provider !== "OpenRouter") {
      return true;
    }
    const modelId = getCurrentChatbotModel("OpenRouter");
    const capabilities = getOpenRouterModelCapabilities(modelId);
    return Boolean(capabilities[capabilityKey]);
  };
  const normalizeCapabilityList = (value) =>
    Array.isArray(value)
      ? value
          .filter((entry) => typeof entry === "string")
          .map((entry) => entry.trim().toLowerCase())
          .filter(Boolean)
      : [];
  const modelHasXaiImageInput = (modelId) => {
    if (!modelId) {
      return true;
    }
    const xaiModels = getModelCatalog("xai");
    const normalizedTarget = String(modelId).trim().toLowerCase();
    if (!normalizedTarget) {
      return true;
    }
    const matchedModel = xaiModels.find((model) => {
      if (!model || typeof model !== "object") {
        return false;
      }
      const candidates = [];
      ["id", "model", "name"].forEach((key) => {
        if (typeof model[key] === "string") {
          candidates.push(model[key]);
        }
      });
      if (Array.isArray(model.aliases)) {
        model.aliases.forEach((alias) => {
          if (typeof alias === "string") {
            candidates.push(alias);
          }
        });
      }
      return candidates.some(
        (candidate) => candidate.trim().toLowerCase() === normalizedTarget
      );
    });
    if (!matchedModel || typeof matchedModel !== "object") {
      return true;
    }

    let imageInput = true;
    const inputModalities = normalizeCapabilityList(
      matchedModel.input_modalities ||
        (matchedModel.modalities && matchedModel.modalities.input)
    );
    if (inputModalities.length) {
      imageInput =
        inputModalities.includes("image") ||
        inputModalities.includes("input_image") ||
        inputModalities.includes("image_url");
    }
    if (
      matchedModel.capabilities &&
      typeof matchedModel.capabilities === "object"
    ) {
      if (Object.prototype.hasOwnProperty.call(matchedModel.capabilities, "image_input")) {
        imageInput = Boolean(matchedModel.capabilities.image_input);
      } else if (Object.prototype.hasOwnProperty.call(matchedModel.capabilities, "vision")) {
        imageInput = Boolean(matchedModel.capabilities.vision);
      }
    }

    return imageInput;
  };
  const modelSupportsXaiImageInput = () => {
    const provider = getCurrentChatbotProvider();
    if (provider !== "xAI") {
      return true;
    }
    const modelId = getCurrentChatbotModel("xAI");
    return modelHasXaiImageInput(modelId);
  };
  return {
    getCurrentChatbotProvider,
    getCurrentChatbotModel,
    isReasoningSupportedForCurrentSelection,
    modelSupportsOpenRouterCapability,
    modelSupportsXaiImageInput,
  };
}
