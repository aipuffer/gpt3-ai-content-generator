/**
 * AIPKit AI Forms - Editor AI Config UI
 *
 * Owns model choices, synchronization, reasoning and provider-specific web-search UI.
 */
(function () {
  "use strict";

  const __ = window.wp?.i18n?.__ || function (str) { return str; };
  const WEB_SEARCH_PROVIDERS = [
    { provider: "OpenAI", key: "openai_web_search", locationHook: "aipkitForms_toggleOpenAIWebSearchLocationDetails" },
    { provider: "Claude", key: "claude_web_search", locationHook: "aipkitForms_toggleClaudeWebSearchLocationDetails" },
    { provider: "OpenRouter", key: "openrouter_web_search" },
    { provider: "xAI", key: "xai_web_search" },
    { provider: "Google", key: "google_search_grounding" },
  ];

  function supportsOpenRouterWebSearch(modelId) {
    if (!modelId) return true;
    const models = window.aipkit_dashboard?.models?.openrouter;
    const target = String(modelId).trim().toLowerCase();
    const matched = (Array.isArray(models) ? models : []).find(
      (model) => (model?.id ? String(model.id).trim().toLowerCase() : "") === target
    );
    if (!matched || typeof matched.capabilities !== "object") return true;
    // An explicitly supplied false/undefined value overrides the default.
    return Boolean({ web_search_tool: true, ...matched.capabilities }.web_search_tool);
  }

  function aipkitForms_getWebSearchCheckboxForProvider(scope, provider) {
    const entry = WEB_SEARCH_PROVIDERS.find((item) => item.provider === provider);
    return entry ? scope.querySelector(`input[name="${entry.key}_enabled"]`) : null;
  }

  function aipkitForms_syncWebSearchFacade(scope, provider, isSupported) {
    const facadeSelect = scope.querySelector(".aipkit_ai_form_web_search_enable_select");
    const settingsButton = scope.querySelector("#aipkit_ai_form_web_search_settings_trigger");
    if (!facadeSelect) {
      return;
    }
    const checkbox = aipkitForms_getWebSearchCheckboxForProvider(scope, provider);
    const isAvailable = Boolean(checkbox) && isSupported !== false;
    facadeSelect.disabled = !isAvailable;
    if (facadeSelect.type === "checkbox") {
      facadeSelect.checked = isAvailable && checkbox.checked;
    } else {
      facadeSelect.value = isAvailable && checkbox.checked ? "1" : "0";
    }
    if (settingsButton) {
      settingsButton.hidden = false;
      settingsButton.disabled = !isAvailable;
      settingsButton.setAttribute("aria-disabled", isAvailable ? "false" : "true");
    }
  }

  function aipkitForms_normalizeProviderKey(value) {
    return String(value || "").trim().toLowerCase();
  }

  function aipkitForms_buildAiSelectionValue(providerValue, modelId) {
    return `${encodeURIComponent(providerValue)}::${encodeURIComponent(modelId)}`;
  }

  function aipkitForms_getProviderOption(providerSelect, providerName) {
    if (!providerSelect || !providerSelect.options) {
      return null;
    }
    const requestedKey = aipkitForms_normalizeProviderKey(providerName);
    return (
      Array.from(providerSelect.options).find(
        (option) =>
          aipkitForms_normalizeProviderKey(option.value) === requestedKey
      ) || null
    );
  }

  function aipkitForms_getProviderEntries(providerSelect) {
    if (!providerSelect || !providerSelect.options) {
      return [];
    }
    return Array.from(providerSelect.options)
      .filter((option) => option.value && !option.disabled && !option.dataset.unavailable)
      .map((option) => ({
        value: option.value,
        key: aipkitForms_normalizeProviderKey(option.value),
        label: option.textContent.trim(),
      }))
      .filter((entry) => entry.key);
  }

  function aipkitForms_getProviderModelItems(
    providerKey,
    providerModelsData,
    recommendedList
  ) {
    const items = [];
    const seen = new Set();

    const appendModel = (model, isRecommended = false) => {
      if (!model || typeof model !== "object" || !model.id) {
        return;
      }
      const modelId = String(model.id);
      if (seen.has(modelId)) {
        return;
      }
      seen.add(modelId);
      items.push({
        id: modelId,
        label: String(model.name || model.id),
        isRecommended,
        groupLabel:
          providerKey === "openrouter" && modelId.includes("/")
            ? modelId.split("/")[0]
            : "",
      });
    };

    if (Array.isArray(recommendedList)) {
      recommendedList.forEach((model) => appendModel(model, true));
    }

    if (
      providerKey === "openai" &&
      providerModelsData &&
      typeof providerModelsData === "object" &&
      !Array.isArray(providerModelsData)
    ) {
      Object.keys(providerModelsData).forEach((groupName) => {
        const groupItems = providerModelsData[groupName];
        if (Array.isArray(groupItems)) {
          groupItems.forEach((model) => appendModel(model));
        }
      });
      return items;
    }

    if (Array.isArray(providerModelsData)) {
      providerModelsData.forEach((model) => appendModel(model));
    }

    if (providerKey === "openrouter") {
      const recommendedItems = items.filter((item) => item.isRecommended);
      const otherItems = items
        .filter((item) => !item.isRecommended)
        .sort((first, second) => {
          if (first.groupLabel !== second.groupLabel) {
            return first.groupLabel < second.groupLabel ? -1 : 1;
          }
          if (first.label === second.label) {
            return 0;
          }
          return first.label < second.label ? -1 : 1;
        });
      return [...recommendedItems, ...otherItems];
    }

    return items;
  }

  function aipkitForms_findAiOption(combinedSelect, providerValue, modelId) {
    if (!combinedSelect || !combinedSelect.options) {
      return null;
    }
    const providerKey = aipkitForms_normalizeProviderKey(providerValue);
    return (
      Array.from(combinedSelect.options).find(
        (option) =>
          aipkitForms_normalizeProviderKey(option.dataset.provider || "") ===
            providerKey &&
          String(option.dataset.model || "") === String(modelId || "")
      ) || null
    );
  }

  function aipkitForms_findFirstAiOptionForProvider(
    combinedSelect,
    providerValue
  ) {
    if (!combinedSelect || !combinedSelect.options) {
      return null;
    }
    const providerKey = aipkitForms_normalizeProviderKey(providerValue);
    return (
      Array.from(combinedSelect.options).find(
        (option) =>
          option.value &&
          aipkitForms_normalizeProviderKey(option.dataset.provider || "") ===
            providerKey
      ) || null
    );
  }

  function aipkitForms_syncHiddenAiFields(
    combinedSelect,
    providerSelect,
    modelField
  ) {
    if (!combinedSelect || !providerSelect || !modelField) {
      return { providerChanged: false, modelChanged: false };
    }

    const selectedOption =
      combinedSelect.selectedOptions && combinedSelect.selectedOptions.length
        ? combinedSelect.selectedOptions[0]
        : null;
    const nextProvider = selectedOption?.dataset?.provider || "";
    const nextModel = selectedOption?.dataset?.model || "";
    const providerChanged = providerSelect.value !== nextProvider;
    const modelChanged = String(modelField.value || "") !== String(nextModel);

    providerSelect.value = nextProvider;
    modelField.value = nextModel;

    return { providerChanged, modelChanged };
  }

  function aipkitForms_notifyEditorMutation(editorContainer) {
    const formsContainer = editorContainer?.closest("#aipkit_ai_forms_container");
    if (
      formsContainer &&
      typeof window.aipkitForms_notifyEditorMutation === "function"
    ) {
      window.aipkitForms_notifyEditorMutation(formsContainer);
    }
  }

  /**
   * Populates the combined AI model selector and keeps the hidden provider/model
   * fields compatible with existing save, edit, and generator flows.
   * @param {string} providerName - The selected provider (e.g., 'OpenAI').
   * @param {string} [selectedValue] - The model value to pre-select.
   */
  function aipkitForms_populateAiModels(providerName, selectedValue = "") {
    const providerSelect = document.getElementById("aipkit_ai_form_ai_provider");
    const modelField = document.getElementById("aipkit_ai_form_ai_model");
    const combinedSelect = document.getElementById("aipkit_ai_form_ai_selection");
    const allModelsData = window.aipkit_dashboard?.models || {};

    if (!providerSelect || !modelField || !combinedSelect) {
      return;
    }

    if (providerName && !aipkitForms_getProviderOption(providerSelect, providerName)) {
      const savedProvider = new Option(providerName, providerName);
      savedProvider.dataset.unavailable = "true";
      providerSelect.appendChild(savedProvider);
    }
    const requestedProviderOption =
      aipkitForms_getProviderOption(providerSelect, providerName) ||
      aipkitForms_getProviderOption(providerSelect, providerSelect.value) ||
      Array.from(providerSelect.options).find((option) => !option.disabled);

    const requestedProvider = requestedProviderOption
      ? requestedProviderOption.value
      : providerSelect.value || "";
    const requestedModel = selectedValue || modelField.value || "";

    if (requestedProviderOption && allModelsData[aipkitForms_normalizeProviderKey(requestedProvider)]?.length) {
      delete requestedProviderOption.dataset.unavailable;
    }
    combinedSelect.innerHTML = "";
    combinedSelect.disabled = false;

    const providerEntries = aipkitForms_getProviderEntries(providerSelect);
    let firstOverallOption = null;
    let requestedProviderGroup = null;
    const recommendedProviderKeys = new Set([
      "openai",
      "openrouter",
      "google",
      "claude",
    ]);

    providerEntries.forEach((providerEntry) => {
      const providerModelsData = allModelsData[providerEntry.key];
      const recommendedList = recommendedProviderKeys.has(providerEntry.key)
        ? window.aipkit_dashboard?.recommendedModels?.[providerEntry.key] || []
        : [];
      const modelItems = aipkitForms_getProviderModelItems(
        providerEntry.key,
        providerModelsData,
        recommendedList
      );

      if (!modelItems.length) {
        return;
      }

      const optgroup = document.createElement("optgroup");
      optgroup.label = providerEntry.label;
      if (
        aipkitForms_normalizeProviderKey(providerEntry.value) ===
        aipkitForms_normalizeProviderKey(requestedProvider)
      ) {
        requestedProviderGroup = optgroup;
      }

      modelItems.forEach((modelItem) => {
        const option = new Option(
          modelItem.label,
          aipkitForms_buildAiSelectionValue(providerEntry.value, modelItem.id)
        );
        option.dataset.provider = providerEntry.value;
        option.dataset.providerKey = providerEntry.key;
        option.dataset.providerLabel = providerEntry.label;
        option.dataset.model = modelItem.id;
        option.dataset.recommended = modelItem.isRecommended ? "true" : "false";
        if (modelItem.groupLabel) {
          option.dataset.groupLabel = modelItem.groupLabel;
        }
        optgroup.appendChild(option);
        if (!firstOverallOption) {
          firstOverallOption = option;
        }
      });

      combinedSelect.appendChild(optgroup);
    });

    let preferredOption = aipkitForms_findAiOption(
      combinedSelect,
      requestedProvider,
      requestedModel
    );

    if (!preferredOption && requestedProvider && requestedModel) {
      if (!requestedProviderGroup) {
        const providerLabel =
          requestedProviderOption?.textContent?.trim() || requestedProvider;
        requestedProviderGroup = document.createElement("optgroup");
        requestedProviderGroup.label = providerLabel;
        combinedSelect.appendChild(requestedProviderGroup);
      }
      preferredOption = new Option(
        requestedProviderOption?.dataset.unavailable
          ? `${requestedModel} (${__("Unavailable", "gpt3-ai-content-generator")})`
          : requestedModel,
        aipkitForms_buildAiSelectionValue(requestedProvider, requestedModel),
        false,
        true
      );
      preferredOption.dataset.provider = requestedProvider;
      preferredOption.dataset.providerKey =
        aipkitForms_normalizeProviderKey(requestedProvider);
      preferredOption.dataset.model = requestedModel;
      requestedProviderGroup.insertBefore(
        preferredOption,
        requestedProviderGroup.firstChild
      );
    }

    preferredOption =
      preferredOption ||
      aipkitForms_findFirstAiOptionForProvider(combinedSelect, requestedProvider) ||
      firstOverallOption;

    if (preferredOption) {
      combinedSelect.value = preferredOption.value;
    } else {
      combinedSelect.appendChild(
        new Option(
          __("No synced models available", "gpt3-ai-content-generator"),
          ""
        )
      );
      combinedSelect.disabled = true;
      providerSelect.value = requestedProvider;
      modelField.value = "";
    }

    if (!combinedSelect.disabled) {
      aipkitForms_syncHiddenAiFields(
        combinedSelect,
        providerSelect,
        modelField
      );
    }

    combinedSelect._aipkitUnifiedModelSync?.();
  }

  function aipkitForms_initUnifiedModelSelector(editorContainer, combinedSelect) {
    const selector = editorContainer?.querySelector(
      ".aipkit_ai_form_unified_model_selector"
    );
    if (
      !selector ||
      !combinedSelect ||
      typeof window.aipkit_createUnifiedModelSelector !== "function" ||
      typeof window.aipkit_createUnifiedModelSelectAdapter !== "function"
    ) {
      return;
    }

    const adapter = window.aipkit_createUnifiedModelSelectAdapter(combinedSelect);
    const controller = window.aipkit_createUnifiedModelSelector(
      selector,
      adapter
    );
    if (!controller) {
      return;
    }

    combinedSelect._aipkitUnifiedModelSync = () => controller.sync();
    controller.sync();
  }

  /**
   * Updates the visibility of web search options based on the selected provider.
   * @param {string} provider - The selected AI provider.
   */
  function aipkitForms_updateWebSearchVisibility(provider, editorContainer) {
    const scope = editorContainer || document;
    const model = scope.querySelector("#aipkit_ai_form_ai_model")?.value || "";
    const supported = provider !== "OpenRouter" || supportsOpenRouterWebSearch(model);
    // Resolve controls before callbacks run, retaining the original provider order.
    const controls = WEB_SEARCH_PROVIDERS.map((entry) => ({
      ...entry,
      checkbox: scope.querySelector(`input[name="${entry.key}_enabled"]`),
      settings: scope.querySelector(`.aipkit_ai_form_${entry.key}_settings`),
      location: entry.locationHook
        ? scope.querySelector(`.aipkit_ai_form_${entry.key}_location_details`)
        : null,
    }));
    const emptyState = scope.querySelector(".aipkit_ai_form_web_search_empty_state");
    const keepSettingsVisible = Boolean(
      scope.querySelector("#aipkit_ai_form_web_search_settings_modal")
        ?.classList.contains("aipkit-active")
    );

    controls.forEach((entry) => {
      const active = provider === entry.provider;
      const available = active && (entry.provider !== "OpenRouter" || supported);
      if (!available && entry.checkbox) entry.checkbox.checked = false;
      const visible = available && entry.checkbox &&
        (entry.checkbox.checked || keepSettingsVisible);
      if (entry.settings) entry.settings.style.display = visible ? "block" : "none";
      if (!visible && entry.location) {
        entry.location.style.display = "none";
      } else if (visible && entry.locationHook && typeof window[entry.locationHook] === "function") {
        window[entry.locationHook](scope);
      }
    });

    const available = supported && WEB_SEARCH_PROVIDERS.some((entry) => entry.provider === provider);
    if (emptyState) emptyState.style.display = available ? "none" : "block";
    aipkitForms_syncWebSearchFacade(scope, provider, available);
  }

  /**
   * Toggles the visibility of the Reasoning Effort dropdown based on provider and model.
   * @param {HTMLElement} editorContainer The main container for the form editor.
   */
  function aipkitForms_toggleReasoningEffort(editorContainer) {
    const providerSelect = editorContainer.querySelector(
      "#aipkit_ai_form_ai_provider"
    );
    const modelSelect = editorContainer.querySelector(
      "#aipkit_ai_form_ai_model"
    );
    const reasoningField = editorContainer.querySelector(
      ".aipkit_ai_form_reasoning_effort_field"
    );
    const reasoningSelect = editorContainer.querySelector(
      "#aipkit_ai_form_reasoning_effort"
    );

    if (!providerSelect || !modelSelect || !reasoningField || !reasoningSelect) {
      if (reasoningField) reasoningField.hidden = true;
      return;
    }

    const provider = providerSelect.value;
    const model = modelSelect.value || "";
    const reasoningUtils =
      window.aipkit_reasoning_effort_utils ||
      window.aipkit_autogpt_reasoning_utils;
    const rule =
      reasoningUtils &&
      typeof reasoningUtils.getProviderReasoningRule === "function"
        ? reasoningUtils.getProviderReasoningRule(provider, model)
        : { supported: false };

    if (!rule.supported) {
      reasoningField.hidden = true;
      return;
    }

    reasoningField.hidden = false;
    reasoningUtils.syncReasoningOptions(
      reasoningSelect,
      rule.allowed,
      rule.defaultValue
    );
  }

  /**
   * Initializes the AI configuration section of the form editor.
   * @param {HTMLElement} editorContainer - The main container for the form editor.
   */
  function aipkitForms_initAiConfig(editorContainer) {
    const providerSelect = editorContainer.querySelector(
      "#aipkit_ai_form_ai_provider"
    );
    const modelField = editorContainer.querySelector(
        "#aipkit_ai_form_ai_model"
    );
    const combinedSelect = editorContainer.querySelector(
      "#aipkit_ai_form_ai_selection"
    );

    if (!providerSelect || !modelField) {
      return;
    }

    aipkitForms_initUnifiedModelSelector(editorContainer, combinedSelect);

    // Attach listener if not already attached
    if (providerSelect.dataset.modelListenerAttached !== "true") {
      providerSelect.addEventListener("change", function () {
        aipkitForms_populateAiModels(this.value, modelField.value || "");
        aipkitForms_updateWebSearchVisibility(this.value, editorContainer);
        aipkitForms_toggleReasoningEffort(editorContainer);
      });
      providerSelect.dataset.modelListenerAttached = "true";
    }

    if (
      combinedSelect &&
      combinedSelect.dataset.modelListenerAttached !== "true"
    ) {
      combinedSelect.addEventListener("change", function () {
        const changes = aipkitForms_syncHiddenAiFields(
          combinedSelect,
          providerSelect,
          modelField
        );
        if (changes.providerChanged) {
          providerSelect.dispatchEvent(new Event("change", { bubbles: true }));
          aipkitForms_notifyEditorMutation(editorContainer);
          return;
        }
        if (changes.modelChanged) {
          modelField.dispatchEvent(new Event("change", { bubbles: true }));
        }
        aipkitForms_toggleReasoningEffort(editorContainer);
        aipkitForms_updateWebSearchVisibility(
          providerSelect.value,
          editorContainer
        );
        if (changes.modelChanged) {
          aipkitForms_notifyEditorMutation(editorContainer);
        }
      });
      combinedSelect.dataset.modelListenerAttached = "true";
    }

    if (modelField.dataset.reasoningListenerAttached !== "true") {
        modelField.addEventListener("change", function () {
            aipkitForms_toggleReasoningEffort(editorContainer);
            aipkitForms_updateWebSearchVisibility(
              providerSelect.value,
              editorContainer
            );
        });
        modelField.dataset.reasoningListenerAttached = "true";
    }

    // Initial population on load
    aipkitForms_populateAiModels(providerSelect.value, modelField.value || "");
    aipkitForms_updateWebSearchVisibility(providerSelect.value, editorContainer);
    aipkitForms_toggleReasoningEffort(editorContainer);
  }

  // Expose functions globally
  window.aipkitForms_initAiConfig = aipkitForms_initAiConfig;
  window.aipkitForms_populateAiModels = aipkitForms_populateAiModels;
  window.aipkitForms_updateWebSearchVisibility = aipkitForms_updateWebSearchVisibility;
  window.aipkitForms_toggleReasoningEffort = aipkitForms_toggleReasoningEffort;

  const getI18n = () =>
    typeof wp !== "undefined" && wp.i18n && wp.i18n.__
      ? wp.i18n.__
      : (text) => text;

  const readSyncTimes = (button) => {
    try {
      const parsed = JSON.parse(button.dataset.aipkitModelSyncTimes || "{}");
      return parsed && typeof parsed === "object" ? parsed : {};
    } catch (error) {
      return {};
    }
  };

  const setSyncError = (formsContainerElement, syncButton, message) => {
    if (
      !syncButton.isConnected ||
      !message ||
      typeof window.aipkitForms_setHeaderStatus !== "function"
    ) {
      return;
    }

    window.aipkitForms_setHeaderStatus(formsContainerElement, message, "error");
    window.setTimeout(() => {
      if (!syncButton.isConnected) return;
      const status = formsContainerElement?.querySelector(
        "#aipkit_ai_forms_status"
      );
      if (
        status?.classList.contains("is-error") &&
        status.textContent?.trim() === message
      ) {
        window.aipkitForms_setHeaderStatus(formsContainerElement, "", "");
      }
    }, 5000);
  };

  function aipkitForms_initModelSync(formsContainerElement) {
    const editorContainer = formsContainerElement?.querySelector(
      "#aipkit_form_editor_container"
    );
    const syncButton = editorContainer?.querySelector(
      "#aipkit_ai_form_model_sync_btn"
    );
    const lastSynced = editorContainer?.querySelector(
      "#aipkit_ai_form_model_last_synced"
    );
    const providerSelect = editorContainer?.querySelector(
      "#aipkit_ai_form_ai_provider"
    );
    const modelField = editorContainer?.querySelector(
      "#aipkit_ai_form_ai_model"
    );

    if (
      !syncButton ||
      !lastSynced ||
      !providerSelect ||
      !modelField ||
      syncButton.dataset.aipkitModelSyncBound === "1"
    ) {
      return;
    }

    const __ = getI18n();
    const syncTimes = readSyncTimes(syncButton);

    const refreshLastSynced = () => {
      const formatter = window.aipkit_formatModelLastSynced;
      const provider = providerSelect.value || "";
      const text =
        typeof formatter === "function"
          ? formatter(syncTimes[provider])
          : "";
      lastSynced.textContent = text;
      lastSynced.hidden = text === "";
    };

    const refreshModelSelector = (provider) => {
      if (
        !syncButton.isConnected ||
        provider !== (providerSelect.value || "") ||
        typeof window.aipkitForms_populateAiModels !== "function"
      ) {
        return;
      }
      window.aipkitForms_populateAiModels(provider, modelField.value || "");
    };

    const resetQueuedVisualState = () => {
      syncButton.classList.remove("aipkit_loading");
      syncButton.disabled = false;
      syncButton.setAttribute("aria-busy", "false");
    };

    const reportUnavailable = () => setSyncError(
      formsContainerElement,
      syncButton,
      __(
        "Model sync is not available. Please try again.",
        "gpt3-ai-content-generator"
      )
    );

    syncButton.addEventListener("click", () => {
      if (syncButton.disabled) {
        return;
      }

      const provider = providerSelect.value || "";
      if (!provider || typeof window.aipkit_queueProviderSync !== "function") {
        reportUnavailable();
        return;
      }

      syncButton.classList.add("aipkit_loading");
      syncButton.disabled = true;
      syncButton.setAttribute("aria-busy", "true");

      const syncPromise = window.aipkit_queueProviderSync(provider, {
        button: syncButton,
        silent: true,
        force: true,
        showErrors: false,
        showSuccess: false,
        showLocalStatus: false,
        propagateError: true,
      });

      if (!syncPromise || typeof syncPromise.then !== "function") {
        resetQueuedVisualState();
        reportUnavailable();
        return;
      }

      syncPromise
        .then(() => {
          refreshModelSelector(provider);
        })
        .catch((error) => {
          setSyncError(
            formsContainerElement,
            syncButton,
            error?.message ||
              __(
                "Could not sync models. Please try again.",
                "gpt3-ai-content-generator"
              )
          );
        })
        .finally(resetQueuedVisualState);
    });

    providerSelect.addEventListener("change", refreshLastSynced);
    const handleSyncComplete = (event) => {
      if (!syncButton.isConnected) {
        return;
      }
      const provider = String(event?.detail?.provider || "");
      const syncedAt = Number(event?.detail?.syncedAt);
      if (!provider || !Number.isFinite(syncedAt) || syncedAt <= 0) {
        return;
      }

      syncTimes[provider] = syncedAt;
      if ((providerSelect.value || "") === provider) {
        refreshLastSynced();
        refreshModelSelector(provider);
      }
    };
    window.addEventListener("aipkit:model-sync-complete", handleSyncComplete);

    refreshLastSynced();
    const refreshTimer = window.setInterval(() => {
      if (!syncButton.isConnected) {
        window.clearInterval(refreshTimer);
        window.removeEventListener("aipkit:model-sync-complete", handleSyncComplete);
        return;
      }
      refreshLastSynced();
    }, 60000);

    syncButton.dataset.aipkitModelSyncBound = "1";
  }

  window.aipkitForms_initModelSync = aipkitForms_initModelSync;
})();
