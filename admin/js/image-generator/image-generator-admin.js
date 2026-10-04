/**
 * AIPKit Admin - Image Generator Module JS
 * Handles initialization for the generator preview and module settings.
 * REVISED: Shortcode configurator moved.
 * REVISED: Updated element selectors to match new DOM structure.
 */
import { syncLimitActionRow as syncSharedLimitActionRow } from "../utils/ui/limit-action-fields.js";

(function () {
  "use strict";

  let isSavingSettings = false;
  let pendingSettingsPayload = null;
  let lastSettingsSignature = null;
  let statusTimer = null;
  const IMAGE_SETTINGS_STATUS_ID = "aipkit_image_generator_status";
  const IMAGE_SHORTCODE_DEFAULTS = Object.freeze({
    allowModelSelection: true,
    mode: "both",
    showHistory: false,
    theme: "light",
    font: "system",
  });
  let shortcodeConfiguratorController = null;
  let shortcodeCopyFeedbackTimer = null;
  let shortcodeCopyFeedbackButton = null;
  let shortcodeAnnouncementTimer = null;
  let shortcodePreviewFlashTimer = null;

  function aipkit_initImageGenerator() {
    const imageGeneratorContainer = document.getElementById(
      "aipkit_image_generator_container"
    );
    if (!imageGeneratorContainer) {
      console.error(
        "AIPKit Image Generator: Main container #aipkit_image_generator_container not found."
      );
      return;
    }

    // Preview container is within the image generator container.
    const previewContainer = imageGeneratorContainer.querySelector(
      "#aipkit_public_image_generator"
    );
    if (previewContainer) {
      if (typeof window.aipkit_initPublicImageGenerator === "function") {
        try {
          window.aipkit_initPublicImageGenerator();
        } catch (error) {
          console.error(
            "AIPKit Image Generator: Error calling public UI initializer:",
            error
          );
          const resultsContainer = document.getElementById(
            "aipkit_public_image_results"
          );
          if (resultsContainer)
            resultsContainer.innerHTML =
              '<p style="color:red">Error initializing preview scripts.</p>';
        }
      } else {
        console.error(
          "AIPKit Image Generator: Public UI initializer function not found!"
        );
        const resultsContainer = document.getElementById(
          "aipkit_public_image_results"
        );
        if (resultsContainer)
          resultsContainer.innerHTML =
            '<p style="color:red">Error: Preview script not loaded.</p>';
      }
    }

    // Initialize controls now searched from the main module container
    initShortcodeConfigurator(imageGeneratorContainer);
    initSettingsPanel(imageGeneratorContainer);
    if (typeof window.aipkit_initModuleSettingsTabs === "function") {
      window.aipkit_initModuleSettingsTabs(imageGeneratorContainer);
    }
    initWorkspaceTabs(imageGeneratorContainer);

    if (typeof window.aipkit_initApiKeyToggles === "function") {
      window.aipkit_initApiKeyToggles("#aipkit_image_generator_container");
    } else {
      console.error(
        "AIPKit Image Generator: aipkit_initApiKeyToggles function not found."
      );
    }
  }

  function getStatusElement() {
    return document.getElementById(IMAGE_SETTINGS_STATUS_ID);
  }

  function setStatusMessage(message, tone) {
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
        statusEl.classList.add("is-visible");
      }
      if (tone) {
        statusEl.classList.add(`is-${tone}`);
      }
      return;
    }
    statusEl.classList.remove("aipkit_form-help-success", "aipkit_form-help-error");
    if (tone === "success") {
      statusEl.classList.add("aipkit_form-help-success");
    } else if (tone === "error") {
      statusEl.classList.add("aipkit_form-help-error");
    }
  }

  function clearStatusSoon(delay = 2500) {
    if (statusTimer) {
      window.clearTimeout(statusTimer);
    }
    statusTimer = window.setTimeout(() => {
      setStatusMessage("", "");
    }, delay);
  }

  function getSettingsForm() {
    return document.getElementById("aipkit_image_generator_settings_form");
  }

  function getAutosaveScope() {
    return (
      document.querySelector("#aipkit_image_generator_settings_panel:not([hidden])") ||
      document.getElementById("aipkit_image_generator_settings_panel") ||
      getSettingsForm() ||
      document.getElementById("aipkit_image_generator_container")
    );
  }

  function setAutosaveBusy(isBusy) {
    if (typeof window.aipkit_setSettingsAutosaveBusy !== "function") {
      return;
    }
    window.aipkit_setSettingsAutosaveBusy(isBusy, getAutosaveScope());
  }

  function getSettingsData() {
    const settingsForm = getSettingsForm();
    if (!settingsForm) {
      console.error("AIPKit Image Settings: Settings form not found.");
      return null;
    }

    const formData = new FormData(settingsForm);
    if (!formData.has("frontend_models")) {
      const hiddenModels = settingsForm.querySelector(
        "#aipkit_image_gen_frontend_models"
      );
      if (hiddenModels) {
        formData.append("frontend_models", hiddenModels.value || "");
      }
    } else {
      const hiddenModels = settingsForm.querySelector(
        "#aipkit_image_gen_frontend_models"
      );
      if (hiddenModels && hiddenModels.value === "") {
        formData.set("frontend_models", "");
      }
    }

    const dataToSend = {};
    formData.forEach((value, key) => {
      dataToSend[key] = value;
    });
    return dataToSend;
  }

  function buildSignature(data) {
    if (!data) {
      return "";
    }
    const ordered = {};
    Object.keys(data)
      .sort()
      .forEach((key) => {
        ordered[key] = data[key];
      });
    return JSON.stringify(ordered);
  }

  function updateSettingsBaseline(data) {
    lastSettingsSignature = buildSignature(data);
  }

  function hasSettingsChanges(data) {
    return buildSignature(data) !== lastSettingsSignature;
  }

  async function saveImageSettings(dataToSend) {
    const __ =
      typeof wp !== "undefined" && wp.i18n && wp.i18n.__
        ? wp.i18n.__
        : (text) => text;

    if (typeof window.aipkit_apiRequest !== "function") {
      console.error("AIPKit Image Settings: aipkit_apiRequest not found.");
      return;
    }

    setStatusMessage("", "");
    setAutosaveBusy(true);

    try {
      const response = await window.aipkit_apiRequest(
        "aipkit_save_image_settings",
        dataToSend
      );
      setStatusMessage("", "");
      updateSettingsBaseline(dataToSend);
      return response;
    } catch (error) {
      setStatusMessage(
        error.message || __("Failed to save settings.", "gpt3-ai-content-generator"),
        "error"
      );
      clearStatusSoon(4000);
      console.error("AIPKit Image Settings Save Error:", error);
      throw error;
    } finally {
      setAutosaveBusy(false);
    }
  }

  function runSettingsSave(dataToSend) {
    if (!dataToSend) {
      return;
    }

    if (isSavingSettings) {
      pendingSettingsPayload = dataToSend;
      return;
    }

    isSavingSettings = true;
    saveImageSettings(dataToSend)
      .catch(() => {})
      .finally(() => {
        isSavingSettings = false;
        if (pendingSettingsPayload) {
          const nextData = pendingSettingsPayload;
          pendingSettingsPayload = null;
          if (hasSettingsChanges(nextData)) {
            runSettingsSave(nextData);
          }
        }
      });
  }

  function handleSettingsAutosave() {
    const dataToSend = getSettingsData();
    if (!dataToSend || !hasSettingsChanges(dataToSend)) {
      return;
    }
    runSettingsSave(dataToSend);
  }

  function updateSettingsAutosaveBaseline() {
    const dataToSend = getSettingsData();
    if (!dataToSend) {
      return;
    }
    updateSettingsBaseline(dataToSend);
  }

  function initSettingsAutosave(settingsForm) {
    if (!settingsForm) return;
    const listenerAttr = "data-aipkit-image-autosave-attached";
    if (settingsForm.hasAttribute(listenerAttr)) {
      return;
    }

    const triggerAutosave = () => {
      handleSettingsAutosave();
    };

    settingsForm.addEventListener("change", (event) => {
      const target = event.target;
      if (!target || !target.matches(".aipkit_autosave_trigger")) {
        return;
      }
      triggerAutosave();
    });

    settingsForm.addEventListener(
      "blur",
      (event) => {
        const target = event.target;
        if (!target || !target.matches(".aipkit_autosave_trigger")) {
          return;
        }
        triggerAutosave();
      },
      true
    );

    settingsForm.setAttribute(listenerAttr, "true");
    updateSettingsAutosaveBaseline();
  }

  function initSettingsPanel(container) {
    const settingsForm = container.querySelector(
      "#aipkit_image_generator_settings_form"
    );
    if (!settingsForm) return;

    if (typeof window.aipkit_initContentWriterPopovers === "function") {
      window.aipkit_initContentWriterPopovers(container);
    } else {
      console.error(
        "AIPKit Image Generator: aipkit_initContentWriterPopovers function not found."
      );
    }

    const tokenListenerAttr = "data-token-listener-attached";
    if (!settingsForm.hasAttribute(tokenListenerAttr)) {
      settingsForm.addEventListener("change", function (event) {
        const target = event.target;
        if (
          target &&
          (
            target.closest(".aipkit_token_limit_mode_select") ||
            target.closest('[data-aipkit-limit-action-row] select[name$="_action_type"]') ||
            target.matches('[name="image_token_guest_limit"]') ||
            target.matches('[name="image_token_user_limit"]') ||
            target.matches('input[name^="image_token_role_limits["]')
          )
        ) {
          toggleTokenLimitFields(settingsForm);
        }
      });
      settingsForm.setAttribute(tokenListenerAttr, "true");
    }
    toggleTokenLimitFields(settingsForm);

    try {
      initAllowedModelsSelector(settingsForm);
    } catch (e) {
      console.error("AIPKit Image Settings: Failed to init model selector", e);
    }

    initSettingsAutosave(settingsForm);
  }

  function initWorkspaceTabs(container) {
    const workspace = container.querySelector("#aipkit_image_generator_workspace");
    if (!workspace || workspace.dataset.workspaceTabsBound === "true") {
      return;
    }

    workspace.addEventListener("click", (event) => {
      const tab = event.target.closest(".aipkit_image_generator_workspace_tab");
      if (!tab) {
        return;
      }

      event.preventDefault();
      const nextTab = tab.dataset.aipkitImageGeneratorTab || "generator";
      const panelId =
        nextTab === "settings"
          ? "aipkit_image_generator_settings_panel"
          : "aipkit_image_generator_preview_panel";

      workspace
        .querySelectorAll(".aipkit_image_generator_workspace_tab")
        .forEach((workspaceTab) => {
          const isActive =
            (workspaceTab.dataset.aipkitImageGeneratorTab || "generator") ===
            nextTab;
          workspaceTab.classList.toggle("is-active", isActive);
          workspaceTab.setAttribute("aria-selected", isActive ? "true" : "false");
        });

      workspace
        .querySelectorAll(".aipkit_image_generator_workspace_panel")
        .forEach((panel) => {
          const isActive = panel.id === panelId;
          panel.classList.toggle("is-active", isActive);
          panel.hidden = !isActive;
        });

      workspace
        .querySelectorAll(".aipkit_image_generator_workspace_tools")
        .forEach((tools) => {
          const isActive =
            (tools.dataset.aipkitImageGeneratorTools || "generator") === nextTab;
          tools.classList.toggle("is-active", isActive);
          tools.hidden = !isActive;
        });

      workspace.classList.toggle(
        "aipkit_image_generator_workspace--settings",
        nextTab === "settings"
      );

      if (nextTab === "settings") {
        const settingsForm = getSettingsForm();
        if (settingsForm) {
          toggleTokenLimitFields(settingsForm);
          try {
            initAllowedModelsSelector(settingsForm);
          } catch (e) {
            console.error("AIPKit Image Settings: Failed to init model selector", e);
          }
          updateSettingsAutosaveBaseline();
        }
      }
    });

    workspace.dataset.workspaceTabsBound = "true";
  }

  function toggleTokenLimitFields(settingsTabContainer) {
    if (!settingsTabContainer) return;
    const modeSelect = settingsTabContainer.querySelector(
      ".aipkit_token_limit_mode_select"
    );
    const generalUserLimitField = settingsTabContainer.querySelector(
      ".aipkit_token_general_user_limit_field"
    );
    const roleLimitsContainer = settingsTabContainer.querySelector(
      ".aipkit_token_role_limits_container"
    );
    const syncLimitActionRow = (row) =>
      syncSharedLimitActionRow(row, { dependentFieldsInParent: true });

    if (!modeSelect || !generalUserLimitField || !roleLimitsContainer) {
      settingsTabContainer
        .querySelectorAll("[data-aipkit-limit-action-row]")
        .forEach(syncLimitActionRow);
      return;
    }
    const selectedMode = modeSelect.value;
    if (generalUserLimitField) {
      generalUserLimitField.hidden = selectedMode !== "general";
    }
    if (roleLimitsContainer) {
      roleLimitsContainer.hidden = selectedMode !== "role_based";
    }
    settingsTabContainer
      .querySelectorAll("[data-aipkit-limit-action-row]")
      .forEach(syncLimitActionRow);
  }

  /**
   * Build an accessible, searchable multi-select UI for choosing allowed models.
   * Keeps data stored in hidden original textarea as comma-separated IDs for backward compatibility.
   */
  function initAllowedModelsSelector(scope, refresh = false) {
    const selectorWrapper = scope.querySelector("#aipkit_image_gen_models_selector");
    if (!selectorWrapper || (!refresh && selectorWrapper.getAttribute("data-enhanced") === "true")) return;

    const hiddenTextarea = scope.querySelector("#aipkit_image_gen_frontend_models");
    const searchTerm = selectorWrapper.querySelector(".aipkit_models_selector-search")?.value || "";
    const initialValueRaw = hiddenTextarea?.value ?? selectorWrapper.dataset.initialValue ?? "";
    const initialSet = new Set(initialValueRaw.split(",").map(value => value.trim()).filter(Boolean));

    // Attempt to pull model lists from already localized public config (since public script is enqueued on this admin page too)
    const cfg = window.aipkit_image_generator_config_public || {};
    const openrouterSupportsImageGeneration = (model) => {
      if (!model || typeof model !== "object") {
        return false;
      }
      const defaults = { image_output: true, image_generation: true };
      if (!model.capabilities || typeof model.capabilities !== "object") {
        return true;
      }
      const capabilities = { ...defaults, ...model.capabilities };
      return Boolean(capabilities.image_output || capabilities.image_generation);
    };
    // Fallback: some pages may not localize azure_models into public config. Try window.aipkit_dashboard_providers if present.
    let azureModels = cfg.azure_models || [];
    if ((!azureModels || azureModels.length === 0) && window.aipkit_dashboard_providers) {
      // Attempt to discover Azure Image models (they are stored under option 'AzureImage')
      try {
        if (Array.isArray(window.aipkit_dashboard_providers.AzureImage)) {
          azureModels = window.aipkit_dashboard_providers.AzureImage.map(m => {
            if (typeof m === 'string') return { id: m, name: m };
            return m;
          });
        }
      } catch (e) { /* ignore */ }
    }
    const providers = [
      { key: "openai", label: "OpenAI", type: "flat", models: (cfg.openai_models || []).map(m=>({id:m.id,name:m.name})) },
      { key: "aipuffercloud", label: "AI Puffer Cloud", type: "flat", models: (cfg.cloud_image_models || []).map(m=>({id:m.id,name:m.name})) },
      {
        key: "openrouter",
        label: "OpenRouter",
        type: "flat",
        models: (cfg.openrouter_image_models || [])
          .filter((m) => openrouterSupportsImageGeneration(m))
          .map((m)=>({id:m.id || m.name || m, name:m.name || m.id || m}))
      },
      {
        key: "xai",
        label: "xAI",
        type: "flat",
        models: (cfg.xai_image_models || []).map((m) =>
          m && m.id ? m : { id: m?.id || m?.name || m, name: m?.name || m?.id || m }
        ),
      },
      { key: "azure", label: "Azure", type: "flat", models: azureModels.map(m=> (m.id? m : {id:m.id||m.name||m, name: m.name||m.id||m})) },
      { key: "google", label: "Google", type: "grouped", models: cfg.google_models || {} },
      { key: "replicate", label: "Replicate", type: "flat", models: (cfg.replicate_models || []).map(m=> m.id? m : {id:m.id||m.name||m, name:m.name||m.id||m}) }
  ].filter(p => {
      // Keep Azure/OpenRouter even if empty so users know why models are missing
      if (p.key === 'azure' || p.key === 'openrouter' || p.key === 'xai') return true;
      return p.models && (Array.isArray(p.models) ? p.models.length : Object.keys(p.models).length);
    });

    // Clear loading state
    selectorWrapper.innerHTML = "";
    selectorWrapper.classList.add("aipkit_models_selector--ready");

    // Search box
    const searchInput = document.createElement("input");
    searchInput.type = "search";
    searchInput.placeholder = "Filter models...";
    searchInput.className = "aipkit_models_selector-search";
    searchInput.value = searchTerm;
    selectorWrapper.appendChild(searchInput);

    // Selected summary
    const summaryBar = document.createElement("div");
    summaryBar.className = "aipkit_models_selector-summary";
    selectorWrapper.appendChild(summaryBar);

    // Providers container
    const providersContainer = document.createElement("div");
    providersContainer.className = "aipkit_models_selector-providers";
    selectorWrapper.appendChild(providersContainer);

  providers.forEach(provider => {
      const card = document.createElement("div");
      card.className = "aipkit_models_selector-provider";
      card.dataset.provider = provider.key;

      const header = document.createElement("div");
      header.className = "aipkit_models_selector-provider-header";
      header.innerHTML = `<strong>${provider.label}</strong>`;

      const actions = document.createElement("div");
      actions.className = "aipkit_models_selector-provider-actions";
      const selectAllBtn = document.createElement("button");
      selectAllBtn.type = "button";
      selectAllBtn.className = "aipkit_btn aipkit_btn-xs";
      selectAllBtn.textContent = "All";
      const clearBtn = document.createElement("button");
      clearBtn.type = "button";
      clearBtn.className = "aipkit_btn aipkit_btn-xs aipkit_btn-secondary";
      clearBtn.textContent = "None";
      actions.appendChild(selectAllBtn);
      actions.appendChild(clearBtn);
      header.appendChild(actions);
      card.appendChild(header);

      const list = document.createElement("div");
      list.className = "aipkit_models_selector-models";

      const appendModelCheckbox = (model) => {
        if (!model || !model.id) return;
        const id = model.id;
        const wrap = document.createElement("label");
        wrap.className = "aipkit_models_selector-item";
        wrap.title = id;
        const cb = document.createElement("input");
        cb.type = "checkbox";
        cb.value = id;
  // Only pre-check if explicitly saved. Leaving all unchecked for a provider hides that provider.
  cb.checked = initialSet.has(id);
        cb.dataset.modelId = id;
        cb.addEventListener("change", () => {
          syncAllowedModelsHiddenField(true);
        });
        const span = document.createElement("span");
        span.textContent = model.name || id;
        wrap.appendChild(cb);
        wrap.appendChild(span);
        list.appendChild(wrap);
      };

      if (provider.type === "grouped") {
        Object.keys(provider.models).forEach(groupKey => {
          const groupModels = provider.models[groupKey];
          if (!Array.isArray(groupModels)) return;
            const groupHeader = document.createElement("div");
            groupHeader.className = "aipkit_models_selector-subgroup";
            groupHeader.textContent = groupKey.charAt(0).toUpperCase() + groupKey.slice(1);
            list.appendChild(groupHeader);
            groupModels.forEach(m => appendModelCheckbox(m));
        });
      } else {
        provider.models.forEach(m => appendModelCheckbox(m));
      }

      if (provider.key === 'azure' && provider.models.length === 0) {
        const notice = document.createElement('div');
        notice.className = 'aipkit_models_selector-empty';
        notice.textContent = 'No Azure image models found. Sync Azure Image models in the main Models settings first.';
        list.appendChild(notice);
      }
      if (provider.key === 'openrouter' && provider.models.length === 0) {
        const notice = document.createElement('div');
        notice.className = 'aipkit_models_selector-empty';
        notice.textContent = 'No image-capable OpenRouter models found. Sync OpenRouter models in the main Models settings first.';
        list.appendChild(notice);
      }
      if (provider.key === 'xai' && provider.models.length === 0) {
        const notice = document.createElement('div');
        notice.className = 'aipkit_models_selector-empty';
        notice.textContent = 'No xAI image models found. Sync xAI models in the main Models settings first.';
        list.appendChild(notice);
      }

      // Select all / none logic limited to those visible & in this provider
      selectAllBtn.addEventListener("click", () => {
        list.querySelectorAll('input[type="checkbox"]').forEach(cb => { cb.checked = true; });
        syncAllowedModelsHiddenField(true);
      });
      clearBtn.addEventListener("click", () => {
        list.querySelectorAll('input[type="checkbox"]').forEach(cb => { cb.checked = false; });
        syncAllowedModelsHiddenField(true);
      });

      card.appendChild(list);
      providersContainer.appendChild(card);
    });

    // Live filtering
    const filterModels = () => {
      const term = searchInput.value.toLowerCase();
      providersContainer.querySelectorAll(".aipkit_models_selector-item").forEach(item => {
        const text = item.textContent.toLowerCase();
        item.style.display = text.includes(term) ? "" : "none";
      });
    };
    searchInput.addEventListener("input", filterModels);
    filterModels();

    selectorWrapper.setAttribute("data-enhanced", "true");
    updateModelsSummary();

    function updateModelsSummary() {
      const selected = Array.from(selectorWrapper.querySelectorAll('.aipkit_models_selector-item input:checked')).map(cb => cb.value);
      if (selected.length === 0) {
        summaryBar.textContent = "Showing all models (none selected)";
      } else {
        summaryBar.textContent = `${selected.length} model${selected.length>1?"s":""} selected`;
      }
    }

    function syncAllowedModelsHiddenField(shouldAutosave = false) {
      const selected = Array.from(selectorWrapper.querySelectorAll('.aipkit_models_selector-item input:checked')).map(cb => cb.value.trim()).filter(Boolean);
      if (hiddenTextarea) {
        hiddenTextarea.value = selected.join(', ');
      }
      updateModelsSummary();
      if (shouldAutosave) {
        handleSettingsAutosave();
      }
    }
  }

  /**
   * Initializes the shortcode configurator.
   * @param {HTMLElement} scopeElement The parent element to search within.
   */
  function initShortcodeConfigurator(scopeElement) {
    const defaultCopyButton = scopeElement.querySelector(
      "#aipkit_image_generator_default_shortcode_copy"
    );
    const configuratorDiv = scopeElement.querySelector(
      "#aipkit_image_generator_shortcode_configurator"
    );
    const configInputs = scopeElement.querySelectorAll(
      "#aipkit_image_generator_shortcode_configurator .aipkit_image_generator_shortcode_input"
    );
    const copyButton = configuratorDiv?.querySelector(
      ".aipkit_image_generator_shortcode_variant_copy"
    );
    const resetButton = configuratorDiv?.querySelector(
      ".aipkit_image_generator_shortcode_reset"
    );

    if (
      !defaultCopyButton ||
      !configuratorDiv ||
      configInputs.length === 0 ||
      !copyButton ||
      !resetButton
    ) {
      console.warn(
        "AIPKit Image Gen Admin: Shortcode configurator elements not found in scope:",
        scopeElement
      );
      return;
    }
    if (configuratorDiv.hasAttribute("data-listener-attached")) return;

    shortcodeConfiguratorController?.abort();
    shortcodeConfiguratorController = new AbortController();
    const { signal } = shortcodeConfiguratorController;

    configInputs.forEach((input) =>
      input.addEventListener(
        "change",
        () => {
          updateImageGeneratorShortcodePreview(configuratorDiv);
        },
        { signal }
      )
    );
    [copyButton, defaultCopyButton].forEach((button) => {
      button.addEventListener(
        "click",
        async (event) => {
          event.preventDefault();
          event.stopPropagation();
          try {
            await copyImageGeneratorShortcode(button.dataset.shortcode || "");
            showImageGeneratorShortcodeCopyFeedback(button);
          } catch (error) {
            setStatusMessage(
              error.message || "Unable to copy shortcode.",
              "error"
            );
            clearStatusSoon(4000);
          }
        },
        { signal }
      );
    });
    resetButton.addEventListener(
      "click",
      (event) => {
        event.preventDefault();
        resetImageGeneratorShortcodeConfigurator(configuratorDiv);
      },
      { signal }
    );
    resetImageGeneratorShortcodeConfigurator(configuratorDiv, {
      announce: false,
      flash: false,
    });
    configuratorDiv.setAttribute("data-listener-attached", "true");
  }

  async function copyImageGeneratorShortcode(shortcode) {
    if (!shortcode) {
      throw new Error("Unable to copy an empty shortcode.");
    }
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(shortcode);
      return;
    }

    const textarea = document.createElement("textarea");
    textarea.value = shortcode;
    textarea.setAttribute("readonly", "");
    textarea.style.position = "fixed";
    textarea.style.opacity = "0";
    document.body.appendChild(textarea);
    textarea.select();
    const copied = document.execCommand("copy");
    textarea.remove();
    if (!copied) {
      throw new Error("Unable to copy shortcode.");
    }
  }

  function showImageGeneratorShortcodeCopyFeedback(copyButton) {
    if (shortcodeCopyFeedbackTimer) {
      window.clearTimeout(shortcodeCopyFeedbackTimer);
    }
    if (
      shortcodeCopyFeedbackButton &&
      shortcodeCopyFeedbackButton !== copyButton
    ) {
      resetImageGeneratorShortcodeCopyFeedback(shortcodeCopyFeedbackButton);
    }
    shortcodeCopyFeedbackButton = copyButton;
    copyButton.classList.add("is-copied");
    const copiedLabel = copyButton.dataset.copiedLabel || "";
    if (copiedLabel) {
      if (!copyButton.dataset.copyLabel) {
        copyButton.dataset.copyLabel = copyButton.getAttribute("aria-label") || "";
      }
      copyButton.setAttribute("aria-label", copiedLabel);
      const liveRegion = copyButton.querySelector(
        "[data-aipkit-shortcode-copy-live]"
      );
      if (liveRegion) liveRegion.textContent = copiedLabel;
    }
    shortcodeCopyFeedbackTimer = window.setTimeout(() => {
      resetImageGeneratorShortcodeCopyFeedback(copyButton);
      if (shortcodeCopyFeedbackButton === copyButton) {
        shortcodeCopyFeedbackButton = null;
      }
    }, 2500);
  }

  function resetImageGeneratorShortcodeCopyFeedback(copyButton) {
    if (!copyButton) return;
    copyButton.classList.remove("is-copied");
    if (copyButton.dataset.copyLabel) {
      copyButton.setAttribute("aria-label", copyButton.dataset.copyLabel);
    }
    const liveRegion = copyButton.querySelector(
      "[data-aipkit-shortcode-copy-live]"
    );
    if (liveRegion) liveRegion.textContent = "";
  }

  function buildImageGeneratorShortcode(configurator) {
    if (!configurator) return "";
    const allowModelSelection = configurator.querySelector(
      '[data-aipkit-shortcode-option="allow-model-selection"]'
    )?.checked ?? true;
    const showHistory = configurator.querySelector(
      '[data-aipkit-shortcode-option="show-history"]'
    )?.checked ?? false;
    const modeSelect = configurator.querySelector(
      '[data-aipkit-shortcode-option="mode"]'
    );
    const themeSelect = configurator.querySelector(
      '[data-aipkit-shortcode-option="theme"]'
    );
    const fontSelect = configurator.querySelector(
      '[data-aipkit-shortcode-option="font"]'
    );
    const theme = themeSelect ? themeSelect.value : "light";
    const mode = modeSelect ? modeSelect.value : "generate";
    const font = fontSelect ? fontSelect.value : "system";

    let shortcode = `[aipkit_image_generator mode="${mode}"`;
    if (showHistory) shortcode += ' history="true"';
    if (theme !== "light") shortcode += ` theme="${theme}"`;
    if (font !== "system") shortcode += ` font="${font}"`;
    if (!allowModelSelection) {
      shortcode += ' show_provider="false" show_model="false"';
    }
    shortcode += "]";
    return shortcode;
  }

  function flashImageGeneratorShortcodePreview(configurator) {
    const previewBlock = configurator.querySelector(
      "[data-aipkit-shortcode-preview-block]"
    );
    if (!previewBlock) return;
    if (shortcodePreviewFlashTimer) {
      window.clearTimeout(shortcodePreviewFlashTimer);
    }
    previewBlock.classList.remove("is-updated");
    void previewBlock.offsetWidth;
    previewBlock.classList.add("is-updated");
    shortcodePreviewFlashTimer = window.setTimeout(() => {
      previewBlock.classList.remove("is-updated");
    }, 140);
  }

  function announceImageGeneratorShortcode(configurator, shortcode) {
    const announcer = configurator.querySelector(
      "[data-aipkit-shortcode-announcer]"
    );
    if (!announcer) return;
    if (shortcodeAnnouncementTimer) {
      window.clearTimeout(shortcodeAnnouncementTimer);
    }
    announcer.textContent = "";
    shortcodeAnnouncementTimer = window.setTimeout(() => {
      announcer.textContent = shortcode;
    }, 250);
  }

  function updateImageGeneratorShortcodePreview(
    configurator,
    { announce = true, flash = true } = {}
  ) {
    if (!configurator) return "";
    const shortcode = buildImageGeneratorShortcode(configurator);
    const preview = configurator.querySelector(
      ".aipkit_image_generator_shortcode_preview"
    );
    const copyButton = configurator.querySelector(
      ".aipkit_image_generator_shortcode_variant_copy"
    );
    if (preview) {
      preview.textContent = shortcode;
      preview.dataset.shortcode = shortcode;
    }
    if (copyButton) {
      copyButton.dataset.shortcode = shortcode;
      resetImageGeneratorShortcodeCopyFeedback(copyButton);
    }
    if (flash) flashImageGeneratorShortcodePreview(configurator);
    if (announce) announceImageGeneratorShortcode(configurator, shortcode);
    return shortcode;
  }

  function resetImageGeneratorShortcodeConfigurator(
    configurator,
    updateOptions
  ) {
    if (!configurator) return "";
    const setChecked = (option, checked) => {
      const input = configurator.querySelector(
        `[data-aipkit-shortcode-option="${option}"]`
      );
      if (input) input.checked = checked;
    };
    const setValue = (option, value) => {
      const input = configurator.querySelector(
        `[data-aipkit-shortcode-option="${option}"]`
      );
      if (input) input.value = value;
    };

    setChecked(
      "allow-model-selection",
      IMAGE_SHORTCODE_DEFAULTS.allowModelSelection
    );
    setChecked("show-history", IMAGE_SHORTCODE_DEFAULTS.showHistory);
    setValue("mode", IMAGE_SHORTCODE_DEFAULTS.mode);
    setValue("theme", IMAGE_SHORTCODE_DEFAULTS.theme);
    setValue("font", IMAGE_SHORTCODE_DEFAULTS.font);
    return updateImageGeneratorShortcodePreview(configurator, updateOptions);
  }

  window.addEventListener("aipkit:model-sync-complete", () => {
    const container = document.getElementById("aipkit_image_generator_container");
    if (container) initAllowedModelsSelector(container, true);
  });

  window.aipkit_initImageGenerator = aipkit_initImageGenerator;
})();
