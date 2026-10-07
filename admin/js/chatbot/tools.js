import { resolveChatbotProviderConfigured } from "./providers.js";
import { bindChatbotSettingsAutosave } from "./state.js";

// Keep listener order and fallback selectors aligned with the backing settings fields.
const WEB_TOGGLE_SELECTORS = {
  openai: ".aipkit_openai_web_search_enable_toggle",
  google: ".aipkit_google_search_grounding_enable_toggle",
  claude: ".aipkit_claude_web_search_enable_toggle",
  openrouter: ".aipkit_openrouter_web_search_enable_toggle",
  xai: ".aipkit_xai_web_search_enable_toggle",
};
const TOOL_FIELDS = [
  ["file_upload", ".aipkit_file_upload_toggle_select"],
  ["web_search"],
  ["image_analysis", ".aipkit_image_analysis_checkbox"],
  ["image_generation", ".aipkit_tools_image_generation_toggle"],
  ["speech_to_text", ".aipkit_voice_input_toggle_switch"],
  ["text_to_speech", ".aipkit_tts_toggle_switch"],
  ["realtime_voice", ".aipkit_enable_realtime_voice_toggle"],
];

// Shared control synchronization; feature execution and entitlement remain separate.
export function bindChatbotTools({ builder, toolsEnabledOptions, getCurrentChatbotProvider }) {
  const getToolsToggleFieldState = (field) => {
    if (!field) {
      return false;
    }
    if (field.tagName === "SELECT") {
      return field.value === "1";
    }
    return Boolean(field.checked);
  };

  const setToolsToggleFieldState = (field, nextState) => {
    if (!field || field.disabled) {
      return;
    }
    const normalizedState = Boolean(nextState);
    const currentState = getToolsToggleFieldState(field);
    if (currentState === normalizedState) {
      return;
    }
    if (field.tagName === "SELECT") {
      field.value = normalizedState ? "1" : "0";
    } else {
      field.checked = normalizedState;
    }
    field.dispatchEvent(new Event("change", { bubbles: true }));
  };

  const getToolsToggleFieldByKey = (toolKey) => {
    const entry = TOOL_FIELDS.find(([key]) => key === toolKey);
    if (!entry) {
      return null;
    }
    const selector = toolKey === "web_search"
      ? WEB_TOGGLE_SELECTORS[String(getCurrentChatbotProvider() || "").toLowerCase()]
      : entry[1];
    return selector ? builder.querySelector(selector) : null;
  };

  const getToolsToggleFieldForOption = (option) => {
    const row = option
      ? option.closest("[data-aipkit-tool-key]")
      : null;
    if (row) {
      const rowField = row.querySelector(".aipkit_tools_state_field");
      if (rowField) {
        return rowField;
      }
    }
    const toolKey = option ? option.dataset.toolKey || option.value : "";
    return getToolsToggleFieldByKey(toolKey);
  };

  const updateToolsFeatureRowsVisibility = () => {
    builder
      .querySelectorAll("[data-aipkit-tool-key]")
      .forEach((row) => {
        const toolKey = row.dataset.aipkitToolKey || "";
        if (!toolKey) {
          return;
        }
        const option = row.querySelector(".aipkit_tools_enabled_option");
        const field =
          (option && getToolsToggleFieldForOption(option)) ||
          row.querySelector(".aipkit_tools_state_field") ||
          getToolsToggleFieldByKey(toolKey);
        const isEnabled = option
          ? Boolean(option.checked)
          : getToolsToggleFieldState(field);
        const isDisabled =
          (option && option.disabled) || (field && field.disabled);
        const imageOptionsButton = row.querySelector(
          ".aipkit_image_generation_config_btn"
        );
        if (imageOptionsButton) {
          const canConfigure = isEnabled && !isDisabled;
          imageOptionsButton.style.display = "";
          imageOptionsButton.disabled = !canConfigure;
          imageOptionsButton.setAttribute(
            "aria-disabled",
            canConfigure ? "false" : "true"
          );
        }
        row.classList.remove("aipkit_tools_feature_row--is-hidden");
        row.classList.toggle("aipkit_tools_feature_row--is-enabled", isEnabled);
        row.classList.toggle("aipkit_tools_feature_row--is-disabled", Boolean(isDisabled));
      });
  };

  const syncToolsEnabledOptionsFromFields = () => {
    if (!toolsEnabledOptions.length) {
      return;
    }
    toolsEnabledOptions.forEach((option) => {
      if (!option) {
        return;
      }
      const toolField = getToolsToggleFieldForOption(option);
      if (toolField) {
        option.checked = getToolsToggleFieldState(toolField);
      }
      const isStaticDisabled = option.dataset.staticDisabled === "1";
      const isDisabled = isStaticDisabled || !toolField || toolField.disabled;
      option.disabled = isDisabled;
      const optionItem = option.closest(".aipkit_tools_enable_label");
      if (optionItem) {
        optionItem.classList.toggle("is-disabled", isDisabled);
      }
    });
    updateToolsFeatureRowsVisibility();
  };

  toolsEnabledOptions.forEach((option) => {
    if (!option || option.dataset.aipkitToolsOptionBound === "1") {
      return;
    }
    option.addEventListener("change", () => {
      if (!option.disabled) {
        const toolField = getToolsToggleFieldForOption(option);
        setToolsToggleFieldState(toolField, option.checked);
      }
      updateToolsFeatureRowsVisibility();
    });
    option.dataset.aipkitToolsOptionBound = "1";
  });

  if (!builder.dataset.aipkitToolsEnabledFieldsBound) {
    TOOL_FIELDS.flatMap(([key, selector]) =>
      key === "web_search" ? Object.values(WEB_TOGGLE_SELECTORS) : [selector]
    ).forEach((selector) => {
      builder.querySelectorAll(selector).forEach((field) => {
        field.addEventListener("change", syncToolsEnabledOptionsFromFields);
      });
    });

    const providerSelectForTools = builder.querySelector(
      ".aipkit_chatbot_provider_select"
    );
    if (providerSelectForTools) {
      providerSelectForTools.addEventListener("change", () => {
        window.requestAnimationFrame(syncToolsEnabledOptionsFromFields);
      });
    }
    builder.dataset.aipkitToolsEnabledFieldsBound = "1";
  }
  syncToolsEnabledOptionsFromFields();
  return { syncToolsEnabledOptionsFromFields, updateToolsFeatureRowsVisibility };
}

/** Image generation and analysis settings; provider execution remains separate. */
export function bindChatbotImageSettings({ builder, modelPopoverPanel, sheetOverlay,
  persistence, updateImageProviderWarning, syncToolsEnabledOptionsFromFields }) {
  if (!sheetOverlay) return;
  const find = selector => modelPopoverPanel?.querySelector(selector) || sheetOverlay.querySelector(selector);
  const fields = () => ({
    chat_image_model_id: find('select[name="chat_image_model_id"]'),
    image_triggers: modelPopoverPanel?.querySelector('input[name="image_triggers"]') ||
      modelPopoverPanel?.querySelector('textarea[name="image_triggers"]') ||
      sheetOverlay.querySelector('input[name="image_triggers"]') || sheetOverlay.querySelector('textarea[name="image_triggers"]'),
    enable_image_generation: find('select[name="enable_image_generation"]'),
    enable_image_upload: find('.aipkit_image_analysis_select') || find('.aipkit_image_analysis_checkbox'),
  });
  if (!fields().chat_image_model_id || !fields().image_triggers) return;
  const sync = () => { updateImageProviderWarning(); syncToolsEnabledOptionsFromFields(); };
  bindChatbotSettingsAutosave({
    builder, panel: builder, boundKey: 'imageSettingsAutosaveBound', persistence,
    action: 'aipkit_update_chatbot_image_settings',
    readSettings: () => {
      const f = fields(), analysis = find('.aipkit_image_analysis_select'), toggle = find('.aipkit_image_analysis_checkbox');
      return {
        chat_image_model_id: f.chat_image_model_id?.value || '',
        image_triggers: f.image_triggers?.value || '',
        enable_image_generation: f.enable_image_generation?.value === '1' ? '1' : '0',
        enable_image_upload: analysis?.value || (toggle?.checked ? '1' : '0'),
      };
    },
    isUnchanged: (a, b) => JSON.stringify(a) === JSON.stringify(b),
    isRelevant: target => target.matches('select[name="chat_image_model_id"], input[name="image_triggers"], textarea[name="image_triggers"], select[name="enable_image_generation"], .aipkit_image_analysis_checkbox, .aipkit_image_analysis_select'),
    beforeRead: event => { if (event.target.matches('select[name="chat_image_model_id"]')) updateImageProviderWarning(); },
    afterHydrate: sync,
    restoreSettings: settings => {
      for (const [name, field] of Object.entries(fields())) {
        if (!field) continue;
        const value = settings[name];
        if (name === 'chat_image_model_id' && value && !Array.from(field.options).some(option => option.value === value)) {
          field.add(new Option(value, value));
        }
        if (field.type === 'checkbox') field.checked = value === '1';
        else field.value = value;
      }
      fields().chat_image_model_id?.parentElement?.querySelector('[data-aipkit-unified-model-selector]')?._aipkitUnifiedModelController?.sync();
    },
    interactionNodes: () => [...Object.values(fields()), ...builder.querySelectorAll(
      '[data-aipkit-tool-key="image_generation"], [data-aipkit-tool-key="image_analysis"], .aipkit_builder_image_generation_settings_modal'
    )],
  });
}

/** Shared enabled/disabled control only; paid file processing stays in lib. */
export function bindChatbotFileUploadSettings({ builder, modelPopoverPanel, persistence, syncToolsEnabledOptionsFromFields }) {
  if (!modelPopoverPanel) return;
  const field = () => modelPopoverPanel.querySelector('[name="enable_file_upload"]');
  bindChatbotSettingsAutosave({
    builder, panel: modelPopoverPanel, boundKey: 'fileUploadAutosaveBound', persistence,
    action: 'aipkit_update_chatbot_file_upload_settings',
    readSettings: () => {
      const input = field();
      return { enable_file_upload: !input || input.disabled ? '' :
        input.tagName === 'SELECT' ? (input.value === '1' ? '1' : '0') : (input.checked ? '1' : '0') };
    },
    canSave: settings => settings.enable_file_upload !== '',
    isUnchanged: (a, b) => a.enable_file_upload === b.enable_file_upload,
    isRelevant: target => target.matches('[name="enable_file_upload"]'),
    restoreSettings: settings => {
      const input = field();
      if (input) {
        if (input.type === 'checkbox') input.checked = settings.enable_file_upload === '1';
        else input.value = settings.enable_file_upload;
      }
      syncToolsEnabledOptionsFromFields();
    },
    interactionNodes: () => [field(), ...builder.querySelectorAll('[data-aipkit-tool-key="file_upload"]')],
  });
}

/** Resolve the selected image provider and update its configuration notice. */
export function createChatbotImageWarning({imageModelSelectField, hideImageProviderWarning, showImageProviderWarning}) {
  const getSelectedImageModelProviderGroup = () => {
    if (imageModelSelectField) {
      const selectedOption = imageModelSelectField.selectedOptions && imageModelSelectField.selectedOptions.length ? imageModelSelectField.selectedOptions[0] : null;
      const optionProvider = selectedOption && selectedOption.dataset && selectedOption.dataset.providerGroup ? selectedOption.dataset.providerGroup : "";
      if (optionProvider) {
        return optionProvider;
      }
    }
    return "";
  };
  const updateImageProviderWarning = () => {
    const providerGroup = String(getSelectedImageModelProviderGroup() || "").trim().toLowerCase();
    if ((providerGroup === "replicate" || providerGroup === "xai") && !resolveChatbotProviderConfigured(providerGroup, true)) {
      showImageProviderWarning(providerGroup);
      return;
    }
    hideImageProviderWarning();
  };
  return {
    updateImageProviderWarning
  };
}

/** Bind image settings disclosure, dismissal and model-picker closure. */
export function bindChatbotImagePanel({
  builder,
  imageSettingsModal,
  imageGenerationRow,
  closeOtherAdvancedDetailPanels,
  mountInlineSettingsPanelForTrigger,
  syncInlineSettingsPanelState,
  updateImageProviderWarning,
  registerAdvancedDetailPanelCloser,
  isToggleFieldOn
}) {
  let activeImageSettingsTrigger = null;
  if (imageSettingsModal && !imageSettingsModal.dataset.bound) {
    const openImageSettingsFlyout = trigger => {
      if (!imageSettingsModal || !trigger) {
        return;
      }
      closeOtherAdvancedDetailPanels(imageSettingsModal);
      mountInlineSettingsPanelForTrigger(imageSettingsModal, trigger);
      imageSettingsModal.classList.add("is-open");
      imageSettingsModal.setAttribute("aria-hidden", "false");
      syncInlineSettingsPanelState(imageSettingsModal, true);
      if (activeImageSettingsTrigger && activeImageSettingsTrigger !== trigger) {
        activeImageSettingsTrigger.setAttribute("aria-expanded", "false");
      }
      trigger.setAttribute("aria-expanded", "true");
      activeImageSettingsTrigger = trigger;
      updateImageProviderWarning();
    };
    const closeImageSettingsFlyout = () => {
      imageSettingsModal.classList.remove("is-open");
      imageSettingsModal.setAttribute("aria-hidden", "true");
      syncInlineSettingsPanelState(imageSettingsModal, false);
      imageGenerationRow?.querySelector("[data-aipkit-unified-model-selector]")?._aipkitUnifiedModelController?.close();
      if (activeImageSettingsTrigger) {
        activeImageSettingsTrigger.setAttribute("aria-expanded", "false");
        activeImageSettingsTrigger = null;
      }
    };
    registerAdvancedDetailPanelCloser(imageSettingsModal, closeImageSettingsFlyout);
    builder.addEventListener("click", event => {
      const configBtn = event.target.closest(".aipkit_image_generation_config_btn");
      if (!configBtn) {
        return;
      }
      event.preventDefault();
      if (imageSettingsModal.classList.contains("is-open") && activeImageSettingsTrigger === configBtn) {
        closeImageSettingsFlyout();
        return;
      }
      openImageSettingsFlyout(configBtn);
    });
    builder.addEventListener("change", event => {
      const target = event.target;
      if (target && target.matches(".aipkit_tools_image_generation_toggle") && !isToggleFieldOn(target)) {
        closeImageSettingsFlyout();
      }
    });
    document.addEventListener("click", event => {
      if (!imageSettingsModal.classList.contains("is-open")) {
        return;
      }
      if (imageSettingsModal.classList.contains("aipkit_inline_settings_content")) {
        return;
      }
      if (event.target.closest(".aipkit_image_generation_config_btn") || event.target.closest('[data-aipkit-inline-settings-target="aipkit_builder_image_generation_settings_modal"]') || event.target.closest("#aipkit_builder_image_generation_settings_modal")) {
        return;
      }
      closeImageSettingsFlyout();
    });
    document.addEventListener("keydown", event => {
      if (event.key === "Escape" && imageSettingsModal.classList.contains("is-open")) {
        closeImageSettingsFlyout();
      }
    });
    imageSettingsModal.dataset.bound = "1";
  }
}

/** Keeps the upload setting interactive only for supported paid capabilities. */
export function createChatbotUploadAvailability({modelPopoverPanel, getChatbotCapabilityState, syncToolsEnabledOptionsFromFields, __}) {
  // The same plain line wherever the documents are kept; only a chatbot that can't take them yet says why.
  const getFileUploadHint = uploadProvider => uploadProvider
    ? __("Visitors can share documents in the chat.", "gpt3-ai-content-generator")
    : __("Turn on Knowledge with a knowledge base to use this with your AI model.", "gpt3-ai-content-generator");
  const updateFileUploadAvailability = () => {
    if (!modelPopoverPanel) {
      return;
    }
    const fileUploadToggle = modelPopoverPanel.querySelector(".aipkit_file_upload_toggle_select");
    if (!fileUploadToggle) {
      return;
    }
    const row = fileUploadToggle.closest(".aipkit_popover_option_row");
    const isProPlan = fileUploadToggle.dataset.isProPlan === "true";
    const capabilityState = getChatbotCapabilityState({
      isProPlan
    });
    const uploadProvider = capabilityState.fileUploadProvider;
    const canEnable = capabilityState.fileUploadAvailable;
    const isEnabled = fileUploadToggle.tagName === "SELECT" ? fileUploadToggle.value === "1" : fileUploadToggle.checked;
    const keepEnabledControlInteractive = isProPlan && isEnabled;
    if (row) {
      row.classList.toggle("aipkit_popover_option_row--disabled", !canEnable && !keepEnabledControlInteractive);
      const hint = row.querySelector(".aipkit_tools_feature_hint");
      if (hint) {
        hint.textContent = getFileUploadHint(uploadProvider);
      }
    }
    fileUploadToggle.disabled = !canEnable && !keepEnabledControlInteractive;
    syncToolsEnabledOptionsFromFields();
  };
  return {
    updateFileUploadAvailability
  };
}

export const updatePopoverToggleAvailability = (row, toggleField, isDisabled) => {
  if (!row || !toggleField) {
    return;
  }
  row.classList.toggle("aipkit_popover_option_row--disabled", isDisabled);
  row.setAttribute("aria-disabled", isDisabled ? "true" : "false");
  toggleField.disabled = Boolean(isDisabled);
};

export const isToggleFieldOn = field => {
  if (!field) {
    return false;
  }
  if (field.tagName === "SELECT") {
    return field.value === "1";
  }
  if (field.type === "checkbox") {
    return field.checked;
  }
  return field.value === "1";
};
