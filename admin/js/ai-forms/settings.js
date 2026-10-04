/** AI Forms settings: save queue, allowed models and token controls. */
import { syncLimitActionRow as syncSharedLimitActionRow } from "../utils/ui/limit-action-fields.js";
(function () {
  "use strict";

  function getI18n() {
    return typeof wp !== "undefined" && wp.i18n && wp.i18n.__
      ? wp.i18n.__ : (text) => text;
  }

  const MESSAGE_CONTAINER_ID = "aipkit_ai_forms_status";
  const SETTINGS_MESSAGE_CONTAINER_ID = "aipkit_ai_forms_settings_status";
  let isSaving = false;
  let pendingData = null;
  let lastSavedSignature = null;

  function getStatusElement() {
    const editorContainer = document.getElementById(
      "aipkit_form_editor_container"
    );
    const editorIsVisible =
      editorContainer &&
      editorContainer.style.display !== "none" &&
      editorContainer.offsetParent !== null;

    if (editorIsVisible) {
      return document.getElementById(MESSAGE_CONTAINER_ID);
    }

    return (
      document.getElementById(SETTINGS_MESSAGE_CONTAINER_ID) ||
      document.getElementById(MESSAGE_CONTAINER_ID)
    );
  }

  function setStatusMessage(message) {
    const statusEl = getStatusElement();
    if (!statusEl) {
      return;
    }
    const usesTrainingStyles = statusEl.classList.contains(
      "aipkit_training_status"
    );
    statusEl.textContent = message || "";
    if (usesTrainingStyles) {
      statusEl.classList.remove(
        "is-visible",
        "is-success",
        "is-error",
        "is-warning",
        "is-loading"
      );
      if (message) {
        statusEl.classList.add("is-visible", "is-error");
      }
      return;
    }
    statusEl.classList.remove("aipkit_form-help-success", "aipkit_form-help-error");
    if (message) {
      statusEl.classList.add("aipkit_form-help-error");
    }
  }

  function getSettingsForm() {
    return document.getElementById("aipkit_ai_forms_settings_form");
  }

  function getAutosaveScope() {
    return (
      document.getElementById("aipkit_ai_forms_settings_content") ||
      getSettingsForm() ||
      document.getElementById("aipkit_ai_forms_container")
    );
  }

  function setAutosaveBusy(isBusy, scope) {
    if (typeof window.aipkit_setSettingsAutosaveBusy !== "function") {
      return;
    }
    window.aipkit_setSettingsAutosaveBusy(isBusy, scope);
  }

  function getSettingsData() {
    const settingsForm = getSettingsForm();
    if (!settingsForm) {
      console.error("AI Forms Save Settings: Settings form not found.");
      return null;
    }

    const formData = new FormData(settingsForm);
    const dataToSend = {};
    formData.forEach((value, key) => {
      dataToSend[key] = value;
    });
    return dataToSend;
  }

  function buildSignature(data) {
    const ordered = {};
    Object.keys(data)
      .sort()
      .forEach((key) => {
        ordered[key] = data[key];
      });
    return JSON.stringify(ordered);
  }

  function updateBaseline(data) {
    lastSavedSignature = buildSignature(data);
  }

  function hasChanges(data) {
    return buildSignature(data) !== lastSavedSignature;
  }

  /**
   * Gathers data from the AI Forms settings form and saves it via AJAX.
   * @param {HTMLElement} saveButton - The button element that was clicked.
   */
  async function saveSettings(dataToSend, saveButton) {
    const __ = getI18n();

    if (typeof window.aipkit_apiRequest !== "function") {
      console.error("AI Forms Save Settings: aipkit_apiRequest not found.");
      return;
    }

    setStatusMessage("");
    const busyScope = getAutosaveScope();
    setAutosaveBusy(true, busyScope);

    let originalButtonText = "";
    let spinner = null;
    if (saveButton) {
      const buttonText = saveButton.querySelector(".aipkit_btn-text");
      originalButtonText =
        buttonText?.textContent ||
        __("Save AI Forms Settings", "gpt3-ai-content-generator");
      spinner = saveButton.querySelector(".aipkit_spinner");
      saveButton.disabled = true;
      if (spinner) spinner.style.display = "inline-block";
      if (buttonText) {
        buttonText.textContent = __(
          "Saving...",
          "gpt3-ai-content-generator"
        );
      }
    }

    try {
      const response = await window.aipkit_apiRequest(
        "aipkit_save_ai_forms_settings",
        dataToSend
      );
      setStatusMessage("");
      updateBaseline(dataToSend);
      return response;
    } catch (error) {
      const errorMessage =
        error.message || __("Failed to save settings.", "gpt3-ai-content-generator");
      setStatusMessage(
        __("Save failed:", "gpt3-ai-content-generator") + " " + errorMessage
      );
      console.error("AI Forms Save Settings Error:", error);
      throw error;
    } finally {
      setAutosaveBusy(false, busyScope);
      if (saveButton) {
        saveButton.disabled = false;
        if (spinner) spinner.style.display = "none";
        const buttonText = saveButton.querySelector(".aipkit_btn-text");
        if (buttonText) buttonText.textContent = originalButtonText;
      }
    }
  }

  function runSave(dataToSend, saveButton) {
    if (!dataToSend) {
      return;
    }

    if (isSaving) {
      pendingData = dataToSend;
      return;
    }

    isSaving = true;
    saveSettings(dataToSend, saveButton)
      .catch(() => {})
      .finally(() => {
        isSaving = false;
        if (pendingData) {
          const nextData = pendingData;
          pendingData = null;
          if (hasChanges(nextData)) {
            runSave(nextData, null);
          }
        }
      });
  }

  function aipkitForms_saveAiFormsSettings(saveButton) {
    const dataToSend = getSettingsData();
    runSave(dataToSend, saveButton);
  }

  function aipkitForms_handleSettingsAutosave() {
    const dataToSend = getSettingsData();
    // While saving, even a return to the old baseline must replace queued data.
    if (!dataToSend || (!isSaving && !hasChanges(dataToSend))) {
      return;
    }
    runSave(dataToSend, null);
  }

  function aipkitForms_updateSettingsAutosaveBaseline() {
    const dataToSend = getSettingsData();
    if (!dataToSend) {
      return;
    }
    updateBaseline(dataToSend);
  }

  function aipkitForms_initSettingsAutosave(formsContainerElement) {
    const settingsForm = (formsContainerElement || document).querySelector(
      "#aipkit_ai_forms_settings_form"
    );
    if (!settingsForm) {
      return;
    }

    const listenerAttr = "data-aipkit-aiforms-autosave-attached";
    if (settingsForm.getAttribute(listenerAttr) === "true") {
      return;
    }

    const handleAutosaveEvent = (event) => {
      const target = event.target;
      if (!target || !target.matches(".aipkit_autosave_trigger")) return;

      const tagName = target.tagName.toLowerCase();
      const isImmediate = tagName === "select" || target.type === "checkbox" || target.type === "range";
      const shouldSave = event.type === "change" ? isImmediate
        : (tagName === "input" || tagName === "textarea") && target.type !== "range" && target.type !== "checkbox";
      if (shouldSave && typeof window.aipkitForms_handleSettingsAutosave === "function") {
        window.aipkitForms_handleSettingsAutosave();
      }
    };

    settingsForm.addEventListener("change", handleAutosaveEvent);
    settingsForm.addEventListener("blur", handleAutosaveEvent, true);

    if (typeof window.aipkitForms_updateSettingsAutosaveBaseline === "function") {
      window.aipkitForms_updateSettingsAutosaveBaseline();
    }

    settingsForm.setAttribute(listenerAttr, "true");
  }

  const PROVIDERS = [
    { key: "aipuffercloud", label: "AI Puffer Cloud" },
    { key: "openai", label: "OpenAI" },
    { key: "google", label: "Google" },
    { key: "claude", label: "Anthropic" },
    { key: "openrouter", label: "OpenRouter" },
    {
      key: "azure",
      label: "Azure",
      keepWhenEmpty: true,
    },
    { key: "ollama", label: "Ollama", requiresPro: true },
    { key: "deepseek", label: "DeepSeek" },
    { key: "xai", label: "xAI" },
  ];

  const getSprintfHelper = () =>
    typeof wp !== "undefined" && wp.i18n && wp.i18n.sprintf
      ? wp.i18n.sprintf
      : (format, value) => format.replace("%d", value);

  const normalizeModel = (model) => {
    if (!model) {
      return null;
    }
    if (typeof model === "string") {
      return { id: model, name: model };
    }
    if (typeof model === "object") {
      const id = model.id || model.name || "";
      if (!id) {
        return null;
      }
      return { id, name: model.name || model.id || id };
    }
    return null;
  };

  const normalizeProviderModels = (rawModels) => {
    if (!rawModels) {
      return null;
    }
    if (Array.isArray(rawModels)) {
      return rawModels.map(normalizeModel).filter(Boolean);
    }
    if (typeof rawModels === "object") {
      const grouped = {};
      Object.keys(rawModels).forEach((groupKey) => {
        const groupModels = Array.isArray(rawModels[groupKey])
          ? rawModels[groupKey]
          : [];
        const normalized = groupModels.map(normalizeModel).filter(Boolean);
        if (normalized.length) {
          grouped[groupKey] = normalized;
        }
      });
      return grouped;
    }
    return null;
  };

  const providerHasModels = (models) => {
    if (!models) {
      return false;
    }
    if (Array.isArray(models)) {
      return models.length > 0;
    }
    return Object.keys(models).length > 0;
  };

  function aipkitForms_initAllowedModelsSelector(formsContainerElement) {
    const scope = formsContainerElement || document;
    const selectorWrapper = scope.querySelector(
      "#aipkit_ai_forms_models_selector"
    );
    if (!selectorWrapper || selectorWrapper.dataset.enhanced === "true") {
      return;
    }

    const hiddenModels = scope.querySelector("#aipkit_aiforms_frontend_models");
    const hiddenProviders = scope.querySelector(
      "#aipkit_aiforms_frontend_providers"
    );
    if (!hiddenModels || !hiddenProviders) {
      return;
    }

    const __ = getI18n();
    const sprintf = getSprintfHelper();
    const initialValueRaw =
      selectorWrapper.dataset.initialValue || hiddenModels.value || "";
    const initialValues = initialValueRaw
      .split(",")
      .map((value) => value.trim())
      .filter(Boolean);
    const initialSet = new Set(initialValues);

    const config = window.aipkit_dashboard || {};
    const modelConfig = config.models || {};
    const isProPlan = Boolean(config.isProPlan);

    const providerConfigs = PROVIDERS.filter(
      (provider) => !provider.requiresPro || isProPlan
    )
      .map((provider) => ({
        ...provider,
        models: normalizeProviderModels(modelConfig[provider.key]),
      }))
      .filter((provider) => provider.keepWhenEmpty || providerHasModels(provider.models));

    selectorWrapper.innerHTML = "";
    selectorWrapper.classList.add("aipkit_models_selector--ready");

    const searchInput = document.createElement("input");
    searchInput.type = "search";
    searchInput.placeholder = __("Filter models...", "gpt3-ai-content-generator");
    searchInput.className = "aipkit_models_selector-search";
    selectorWrapper.appendChild(searchInput);

    const summaryBar = document.createElement("div");
    summaryBar.className = "aipkit_models_selector-summary";
    selectorWrapper.appendChild(summaryBar);

    const providersContainer = document.createElement("div");
    providersContainer.className = "aipkit_models_selector-providers";
    selectorWrapper.appendChild(providersContainer);

    const syncAllowedFields = (shouldAutosave) => {
      const selectedCheckboxes = Array.from(
        selectorWrapper.querySelectorAll(
          '.aipkit_models_selector-item input[type="checkbox"]:checked'
        )
      );
      const selectedModels = selectedCheckboxes
        .map((checkbox) => checkbox.value.trim())
        .filter(Boolean);

      const providersSet = new Set();
      selectedCheckboxes.forEach((checkbox) => {
        if (checkbox.dataset.providerName) {
          providersSet.add(checkbox.dataset.providerName);
        }
      });

      const providersValue = selectedModels.length
        ? providerConfigs
            .map((provider) => provider.label)
            .filter((label) => providersSet.has(label))
            .join(", ")
        : "";

      hiddenModels.value = selectedModels.join(", ");
      hiddenProviders.value = providersValue;

      if (selectedModels.length === 0) {
        summaryBar.textContent = __(
          "Showing all models (none selected)",
          "gpt3-ai-content-generator"
        );
      } else if (selectedModels.length === 1) {
        summaryBar.textContent = __(
          "1 model selected",
          "gpt3-ai-content-generator"
        );
      } else {
        summaryBar.textContent = sprintf(
          __("%d models selected", "gpt3-ai-content-generator"),
          selectedModels.length
        );
      }

      if (
        shouldAutosave &&
        typeof window.aipkitForms_handleSettingsAutosave === "function"
      ) {
        window.aipkitForms_handleSettingsAutosave();
      }
    };

    providerConfigs.forEach((provider) => {
      const card = document.createElement("div");
      card.className = "aipkit_models_selector-provider";
      card.dataset.provider = provider.key;

      const header = document.createElement("div");
      header.className = "aipkit_models_selector-provider-header";
      const heading = document.createElement("strong");
      heading.textContent = provider.label;
      header.appendChild(heading);

      const actions = document.createElement("div");
      actions.className = "aipkit_models_selector-provider-actions";

      const selectAllBtn = document.createElement("button");
      selectAllBtn.type = "button";
      selectAllBtn.className = "aipkit_btn aipkit_btn-xs";
      selectAllBtn.textContent = __("All", "gpt3-ai-content-generator");

      const clearBtn = document.createElement("button");
      clearBtn.type = "button";
      clearBtn.className = "aipkit_btn aipkit_btn-xs aipkit_btn-secondary";
      clearBtn.textContent = __("None", "gpt3-ai-content-generator");

      actions.appendChild(selectAllBtn);
      actions.appendChild(clearBtn);
      header.appendChild(actions);
      card.appendChild(header);

      const list = document.createElement("div");
      list.className = "aipkit_models_selector-models";

      const appendModelCheckbox = (model) => {
        if (!model || !model.id) {
          return;
        }
        const wrap = document.createElement("label");
        wrap.className = "aipkit_models_selector-item";
        wrap.title = model.id;

        const checkbox = document.createElement("input");
        checkbox.type = "checkbox";
        checkbox.value = model.id;
        checkbox.checked = initialSet.has(model.id);
        checkbox.dataset.providerName = provider.label;
        checkbox.addEventListener("change", () => syncAllowedFields(true));

        const labelText = document.createElement("span");
        labelText.textContent = model.name || model.id;

        wrap.appendChild(checkbox);
        wrap.appendChild(labelText);
        list.appendChild(wrap);
      };

      if (Array.isArray(provider.models)) {
        provider.models.forEach((model) => appendModelCheckbox(model));
      } else if (provider.models && typeof provider.models === "object") {
        Object.keys(provider.models).forEach((groupKey) => {
          const groupModels = provider.models[groupKey] || [];
          if (!groupModels.length) {
            return;
          }
          const groupHeader = document.createElement("div");
          groupHeader.className = "aipkit_models_selector-subgroup";
          groupHeader.textContent =
            groupKey.charAt(0).toUpperCase() + groupKey.slice(1);
          list.appendChild(groupHeader);
          groupModels.forEach((model) => appendModelCheckbox(model));
        });
      }

      if (!providerHasModels(provider.models)) {
        const emptyNotice = document.createElement("div");
        emptyNotice.className = "aipkit_models_selector-empty";
        // Azure is the only provider kept when its model list is empty.
        emptyNotice.textContent = __(
          "No Azure deployments found. Sync Azure models in main settings first.",
          "gpt3-ai-content-generator"
        );
        list.appendChild(emptyNotice);
      }

      selectAllBtn.addEventListener("click", () => {
        list
          .querySelectorAll('input[type="checkbox"]')
          .forEach((checkbox) => {
            checkbox.checked = true;
          });
        syncAllowedFields(true);
      });

      clearBtn.addEventListener("click", () => {
        list
          .querySelectorAll('input[type="checkbox"]')
          .forEach((checkbox) => {
            checkbox.checked = false;
          });
        syncAllowedFields(true);
      });

      card.appendChild(list);
      providersContainer.appendChild(card);
    });

    searchInput.addEventListener("input", () => {
      const term = searchInput.value.toLowerCase();
      providersContainer
        .querySelectorAll(".aipkit_models_selector-provider")
        .forEach((card) => {
          let hasVisible = false;
          card.querySelectorAll(".aipkit_models_selector-item").forEach((item) => {
            const text = item.textContent.toLowerCase();
            const show = text.includes(term);
            item.style.display = show ? "" : "none";
            if (show) {
              hasVisible = true;
            }
          });
          card.style.display = hasVisible || term === "" ? "" : "none";
        });
    });

    syncAllowedFields(false);

    if (typeof window.aipkitForms_updateSettingsAutosaveBaseline === "function") {
      window.aipkitForms_updateSettingsAutosaveBaseline();
    }

    selectorWrapper.dataset.enhanced = "true";
  }

  window.aipkitForms_saveAiFormsSettings = aipkitForms_saveAiFormsSettings;
  window.aipkitForms_handleSettingsAutosave = aipkitForms_handleSettingsAutosave;
  window.aipkitForms_updateSettingsAutosaveBaseline = aipkitForms_updateSettingsAutosaveBaseline;
  window.aipkitForms_initSettingsAutosave = aipkitForms_initSettingsAutosave;
  window.aipkitForms_initAllowedModelsSelector = aipkitForms_initAllowedModelsSelector;

  const syncLimitActionRow = (row) =>
    syncSharedLimitActionRow(row, { dependentFieldsInParent: true });

  function aipkit_aiforms_toggleLimitFields(settingsContainer) {
    if (!settingsContainer) {
      return;
    }

    const modeSelect = settingsContainer.querySelector(
      ".aipkit_token_limit_mode_select"
    );
    const generalUserLimitField = settingsContainer.querySelector(
      ".aipkit_token_general_user_limit_field"
    );
    const roleLimitsContainer = settingsContainer.querySelector(
      ".aipkit_token_role_limits_container"
    );

    if (modeSelect && generalUserLimitField && roleLimitsContainer) {
      const selectedMode = modeSelect.value;
      generalUserLimitField.hidden = selectedMode !== "general";
      roleLimitsContainer.hidden = selectedMode !== "role_based";
    }

    settingsContainer
      .querySelectorAll("[data-aipkit-limit-action-row]")
      .forEach(syncLimitActionRow);
  }

  function aipkit_initAIFormsTokenManagementUI(settingsContainer) {
    if (!settingsContainer) {
      console.error(
        "AI Forms Token UI: Initialization failed, settings container not provided."
      );
      return;
    }

    const listenerAttr = "data-token-listener-attached";
    if (settingsContainer.getAttribute(listenerAttr) === "true") {
      return; // Avoid attaching multiple listeners
    }

    // Use event delegation on the container for the limit mode and action type selects
    settingsContainer.addEventListener("change", function (event) {
      const modeSelect = event.target.closest(".aipkit_token_limit_mode_select");
      const actionTypeSelect = event.target.closest('select[name$="_action_type"]');
      if (!modeSelect && !actionTypeSelect) return;

      aipkit_aiforms_toggleLimitFields(settingsContainer);
    });

    // Initial toggle on load
    aipkit_aiforms_toggleLimitFields(settingsContainer);

    settingsContainer.setAttribute(listenerAttr, "true");
  }

  window.aipkit_initAIFormsTokenManagementUI =
    aipkit_initAIFormsTokenManagementUI;
})();
