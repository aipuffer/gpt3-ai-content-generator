import { bindChatbotSettingsAutosave, createChatbotStateHydration } from "./state.js";

/** Chatbot theme selection, presets, color editor, avatars, widget icons and interface controls. */

function bindPresentation(builder, nodes, reset, cleanup,
  getSelectedBotId = () => builder.querySelector(".aipkit_chatbot_settings_form")?.dataset.botId || "") {
  const controller = new AbortController();
  let generation = 0;
  const alive = () => !controller.signal.aborted && builder.isConnected && nodes.every(node => node.isConnected);
  const editable = () => alive() && !nodes.some(node => node.closest('[inert]'));
  const cancel = () => { generation++; reset(); };
  const dispose = () => {
    if (controller.signal.aborted) return;
    controller.abort(); observer.disconnect(); cancel(); cleanup();
  };
  const observer = new MutationObserver(() => { if (!alive()) dispose(); });
  builder.addEventListener('aipkit:bot-state-applied', cancel, { signal: controller.signal });
  observer.observe(builder.ownerDocument.documentElement, { childList: true, subtree: true });
  return {
    alive, dispose,
    editable,
    capture: () => {
      const botId = String(getSelectedBotId()), version = generation;
      return () => editable() && generation === version && String(getSelectedBotId()) === botId;
    },
    listen: (node, type, callback) => node.addEventListener(type, event => {
      if (node.isConnected && editable()) callback(event);
    }, { signal: controller.signal }),
  };
}

function aipkit_toggleCustomThemeSettingsVisibility(settingsArea) {
  if (!settingsArea) return;

  const themeSelect = settingsArea.querySelector('select[name="theme"]');

  // Update visibility of the "Customize Theme" button
  const customizeBtn = settingsArea.querySelector('.aipkit_theme_config_btn');
  const isCustom = themeSelect?.value === "custom";
  if (customizeBtn) customizeBtn.style.display = isCustom ? 'inline-flex' : 'none';
}

const themeDelegates = new WeakMap();
function bindThemeEvents(builder, modal = builder?.querySelector('#aipkit_custom_theme_modal')) {
  if (!builder?.isConnected || themeDelegates.get(builder)?.alive()) return;
  themeDelegates.get(builder)?.dispose();
  const roots = [builder, modal].filter(Boolean);
  const lifetime = bindPresentation(builder, roots, () => {}, () => {
    if (themeDelegates.get(builder) === lifetime) themeDelegates.delete(builder);
  });
  themeDelegates.set(builder, lifetime);
  const owns = target => target?.isConnected && roots.some(root => root.contains(target));
  const syncVisibility = target => aipkit_toggleCustomThemeSettingsVisibility(
    target.closest('.aipkit_model_settings_panel[data-aipkit-settings-panel="appearance"]') ||
    target.closest('.aipkit_chatbot-settings-area') || builder);
  lifetime.listen(document, 'change', event => {
    const target = event.target;
    if (!owns(target)) return;
    if (target.matches('select[name="theme"]')) {
      syncVisibility(target);
      applyPresetFromThemeSelect(target);
    } else if (target.matches('input[name^="custom_theme_settings["], select[name^="custom_theme_settings["]')) {
      clearPresetKeyForManualCustomThemeEdit(target);
    }
  });
  lifetime.listen(document, 'click', event => {
    if (owns(event.target) && event.target.closest('.aipkit_reset_custom_theme_btn') &&
      typeof window.aipkit_handleResetCustomTheme === 'function') window.aipkit_handleResetCustomTheme(event);
  });
  builder.querySelectorAll('select[name="theme"]').forEach(syncVisibility);
}

function aipkit_initChatThemeSettingsToggle() {
  const module = document.querySelector('.aipkit_chatbot_module_container') ||
    document.getElementById('aipkit_chatbot_main_tab_content_container');
  module?.querySelectorAll('.aipkit_chatbot_builder').forEach(builder => bindThemeEvents(builder));
}

// Expose globally
window.aipkit_initChatThemeSettingsToggle =
  aipkit_initChatThemeSettingsToggle;

function aipkit_handleResetCustomTheme(event) {
  const resetButton = event.target.closest('.aipkit_reset_custom_theme_btn');
  if (!resetButton || resetButton.disabled || !resetButton.isConnected || resetButton.closest('[inert]')) return;

  const botId = resetButton.dataset.botId;
  const themeSettingsContainer = resetButton.closest('.aipkit_custom_theme_settings_container') ||
    findSettingsContainerByBotId(botId);
  const statusSpan = themeSettingsContainer?.querySelector('.aipkit_custom_theme_reset_status');

  if (!themeSettingsContainer || !statusSpan) {
    console.error("AIPKit Theme Reset: Could not find theme settings container or status span for bot", botId);
    return;
  }

  const defaultsJson = themeSettingsContainer.dataset.defaults;
  if (!defaultsJson) {
    console.error("AIPKit Theme Reset: No default values found in data-defaults attribute.");
    if (typeof window.aipkit_showTemporaryMessage === 'function') {
      window.aipkit_showTemporaryMessage(statusSpan, 'error', 'Error: Defaults not found.', 2000);
    }
    return;
  }

  try {
    const defaults = JSON.parse(defaultsJson);
    const inputs = themeSettingsContainer.querySelectorAll('input[name^="custom_theme_settings["], select[name^="custom_theme_settings["]');
    const completed = applyThemeUpdate(themeSettingsContainer, resetButton.closest('.aipkit_chatbot_settings_form'),
      'aipkit:custom-theme-reset', botId, isCurrent => {
        for (const input of inputs) {
          if (!isCurrent()) break;
          const nameAttr = input.getAttribute('name');
          const keyMatch = nameAttr.match(/custom_theme_settings\[(.*?)\]/);
          if (keyMatch && keyMatch[1]) {
            const settingKey = keyMatch[1];
            const defaultKey = Object.prototype.hasOwnProperty.call(defaults, settingKey)
              ? settingKey
              : settingKey + '_placeholder';
            if (!Object.prototype.hasOwnProperty.call(defaults, defaultKey)) continue;
            const value = defaults[defaultKey];
            if (input.type === 'checkbox') {
              input.checked = String(value) === '1';
            } else {
              input.value = value !== null ? String(value) : '';
            }
            input.dispatchEvent(new Event('input', { bubbles: true }));
            if (isCurrent()) input.dispatchEvent(new Event('change', { bubbles: true }));
          }
        }
    });
    if (!completed) return;

    if (typeof window.aipkit_showTemporaryMessage === 'function') {
      window.aipkit_showTemporaryMessage(
        statusSpan,
        'success',
        resetButton.dataset.successMessage || 'Defaults restored.',
        2000
      );
    }

  } catch (e) {
    console.error("AIPKit Theme Reset: Error parsing default values JSON or applying defaults.", e);
    if (typeof window.aipkit_showTemporaryMessage === 'function') {
      window.aipkit_showTemporaryMessage(
        statusSpan,
        'error',
        resetButton.dataset.errorMessage || 'Could not restore defaults.',
        2000
      );
    }
  }
}

// Expose globally
window.aipkit_handleResetCustomTheme = aipkit_handleResetCustomTheme;

const THEME_PRESET_APPLIED_EVENT = "aipkit:theme-preset-applied";

function dispatchChange(input, isCurrent) {
  const inputEvent = new Event("input", { bubbles: true, cancelable: true });
  const changeEvent = new Event("change", { bubbles: true, cancelable: true });
  input.dispatchEvent(inputEvent);
  if (isCurrent()) input.dispatchEvent(changeEvent);
}

// A color update belongs to its rendered editor, never to the whole document.
const themeUpdates = new WeakMap();
const isThemeUpdating = target => themeUpdates.has(target?.closest('.aipkit_custom_theme_settings_container')) ||
  themeUpdates.has(target?.closest('.aipkit_chatbot_settings_form'));

function applyThemeUpdate(container, form, type, botId, update) {
  const roots = [container, form].filter(Boolean), token = {};
  const containerId = container.id, formId = form?.dataset.botId;
  const isCurrent = () => roots.every(root => root.isConnected && themeUpdates.get(root) === token && !root.closest('[inert]')) &&
    container.id === containerId && form?.dataset.botId === formId;
  roots.forEach(root => themeUpdates.set(root, token));
  let completed = false;
  try {
    if (isCurrent()) update(isCurrent);
    completed = isCurrent();
  } finally {
    roots.forEach(root => { if (themeUpdates.get(root) === token) themeUpdates.delete(root); });
  }
  if (completed) document.dispatchEvent(new CustomEvent(type, {
    detail: { botId: String(botId || '').trim(), settingsContainer: container },
  }));
  return completed;
}

function setFieldValue(container, fieldName, value, isCurrent) {
  if (!isCurrent()) return;
  const field = container.querySelector(
    `input[name="custom_theme_settings[${fieldName}]"], select[name="custom_theme_settings[${fieldName}]"]`
  );
  if (!field) {
    return;
  }

  if (field.type === "checkbox") {
    const shouldCheck = value === "1";
    if (field.checked !== shouldCheck) {
      field.checked = shouldCheck;
      dispatchChange(field, isCurrent);
    }
    return;
  }

  if (field.value !== value) {
    field.value = value;
    dispatchChange(field, isCurrent);
  }
}

function setThemePresetKey(settingsForm, presetKey) {
  if (!settingsForm) {
    return;
  }
  const presetKeyField = settingsForm.querySelector(
    'input[name="theme_preset_key"]'
  );
  if (presetKeyField && !presetKeyField.disabled) {
    presetKeyField.value = presetKey || "";
  }
}

function findSettingsContainerByBotId(botId) {
  if (!botId) {
    return null;
  }
  return document.getElementById(
    `aipkit_bot_${botId}_custom_theme_settings_container`
  );
}

function findSettingsContainerFromThemeSelect(themeSelect) {
  const settingsForm = themeSelect.closest(".aipkit_chatbot_settings_form");
  if (!settingsForm) {
    return null;
  }
  const inlineContainer = settingsForm.querySelector(
    ".aipkit_custom_theme_settings_container"
  );
  if (inlineContainer) {
    return inlineContainer;
  }
  const botId =
    settingsForm.dataset.botId ||
    settingsForm.closest("[data-bot-id]")?.dataset.botId;
  return settingsForm.closest('.aipkit_chatbot_builder')?.querySelector('.aipkit_custom_theme_settings_container') ||
    findSettingsContainerByBotId(botId);
}

function applyPresetFromThemeSelect(themeSelect) {
  if (!themeSelect || themeSelect.disabled || !themeSelect.isConnected || themeSelect.closest('[inert]')) {
    return;
  }
  const selectedOption =
    themeSelect.selectedOptions && themeSelect.selectedOptions.length
      ? themeSelect.selectedOptions[0]
      : null;
  if (!selectedOption || !selectedOption.dataset) {
    return;
  }
  const presetKey = selectedOption.dataset.presetKey || "";
  const primary = selectedOption.dataset.primary || "";
  const secondary = selectedOption.dataset.secondary || "";
  if (!presetKey || !primary || !secondary) {
    const settingsForm = themeSelect.closest(".aipkit_chatbot_settings_form");
    setThemePresetKey(settingsForm, "");
    return;
  }
  const settingsContainer = findSettingsContainerFromThemeSelect(themeSelect);
  if (!settingsContainer) {
    return;
  }
  const settingsForm = themeSelect.closest(".aipkit_chatbot_settings_form");
  applyThemeUpdate(settingsContainer, settingsForm, THEME_PRESET_APPLIED_EVENT, settingsForm.dataset.botId, isCurrent => {
    setThemePresetKey(settingsForm, presetKey);
    setFieldValue(settingsContainer, "primary_color", primary, isCurrent);
    setFieldValue(settingsContainer, "secondary_color", secondary, isCurrent);
  });
}

function clearPresetKeyForManualCustomThemeEdit(target) {
  if (!target || !target.closest) {
    return;
  }
  if (isThemeUpdating(target)) {
    return;
  }
  const settingsForm = target.closest(".aipkit_chatbot_settings_form");
  if (!settingsForm) {
    return;
  }
  setThemePresetKey(settingsForm, "");
}

function aipkit_initCustomThemePresets() {
  aipkit_initChatThemeSettingsToggle();
}

window.aipkit_initCustomThemePresets = aipkit_initCustomThemePresets;

export function createChatbotAppearance({
  builder,
  customThemeModal,
  widgetDesigner,
  popupSettingsPanel,
  __,
  syncSettingsPanelOverflowState,
  closeOtherAdvancedDetailPanels,
  updateBuilderSheetScrollLock,
  getSelectedBotId = () => builder.querySelector(".aipkit_chatbot_settings_form")?.dataset.botId || "",
}) {
  let modalLifetime = null, customThemeFocusFrame = null, interfaceBinding = null, themeLifetime = null;
  const cancelThemeFocus = () => {
    if (customThemeFocusFrame !== null) window.cancelAnimationFrame(customThemeFocusFrame);
    customThemeFocusFrame = null;
  };
  const quickAvatarUploadBtn = widgetDesigner
    ? widgetDesigner.querySelector("[data-aipkit-avatar-quick-upload]")
    : null;
  const quickAvatarPreview = widgetDesigner
    ? widgetDesigner.querySelector("[data-aipkit-avatar-quick-preview]")
    : null;
  const quickAvatarUseWidgetBtn = widgetDesigner
    ? widgetDesigner.querySelector("[data-aipkit-avatar-use-widget]")
    : null;
  const quickAvatarLinkStatus = widgetDesigner
    ? widgetDesigner.querySelector("[data-aipkit-avatar-link-status]")
    : null;
  const quickWidgetIconRadios = widgetDesigner
    ? Array.from(widgetDesigner.querySelectorAll('input[name="aipkit_widget_icon_quick"]'))
    : [];
  const quickWidgetIconUploadBtn = widgetDesigner
    ? widgetDesigner.querySelector("[data-aipkit-widget-icon-upload]")
    : null;
  const quickWidgetIconCustomVisual = widgetDesigner
    ? widgetDesigner.querySelector("[data-aipkit-widget-icon-custom-visual]")
    : null;

  const customThemeModalContent = customThemeModal
    ? customThemeModal.querySelector(".aipkit_custom_theme_modal_content")
    : null;
  const customThemeCloseBtn = customThemeModal
    ? customThemeModal.querySelector(".aipkit_custom_theme_modal_close")
    : null;
  const customThemeColorInput = customThemeModal
    ? customThemeModal.querySelector(
        'input[name="custom_theme_settings[primary_color]"]'
      )
    : null;
  const customThemeHexInput = customThemeModal
    ? customThemeModal.querySelector("[data-aipkit-custom-theme-hex]")
    : null;
  let activeCustomThemeTrigger = null;
  const themeDropdown = builder.querySelector("[data-aipkit-theme-dropdown]");
  const themeDropdownButton = themeDropdown
    ? themeDropdown.querySelector(".aipkit_popover_multiselect_btn")
    : null;
  const themeDropdownLabel = themeDropdown
    ? themeDropdown.querySelector(".aipkit_popover_multiselect_label")
    : null;
  const themeDropdownPanel = themeDropdown
    ? themeDropdown.querySelector(".aipkit_popover_multiselect_panel")
    : null;
  const themeOptionRadios = themeDropdown
    ? Array.from(themeDropdown.querySelectorAll(".aipkit_interface_theme_radio"))
    : [];
  const themeSelectField = builder.querySelector('select[name="theme"]');
  const themePresetKeyField = builder.querySelector(
    'input[name="theme_preset_key"]'
  );
  const themeConfigButton = builder.querySelector(".aipkit_theme_config_btn");
  const hydration = createChatbotStateHydration({ syncUnifiedModelSelector: () => {} });
  const applyThemeSettings = settings => {
    const containers = new Set([builder, customThemeModal].filter(Boolean).flatMap(root =>
      [...root.querySelectorAll('.aipkit_custom_theme_settings_container')]));
    // Hydration invalidates a re-entrant update, including switching away and back.
    containers.forEach(container => themeUpdates.delete(container));
    if (themeSelectField && Object.prototype.hasOwnProperty.call(settings, 'theme')) {
      const theme = String(settings.theme || ''), preset = theme === 'custom' ? String(settings.theme_preset_key || '') : '';
      const options = [...themeSelectField.options];
      const exact = options.findIndex(option => option.value === theme && (option.dataset.presetKey || '') === preset);
      const fallback = options.findIndex(option => option.value === theme && !option.dataset.presetKey);
      if (exact >= 0 || fallback >= 0) themeSelectField.selectedIndex = exact >= 0 ? exact : fallback;
    }
    if (settings.custom_theme_settings && typeof settings.custom_theme_settings === 'object') {
      for (const container of containers) {
        let defaults = {};
        try { defaults = JSON.parse(container.dataset.defaults || '{}') || {}; } catch {}
        container.querySelectorAll('input[name^="custom_theme_settings["], select[name^="custom_theme_settings["]').forEach(field => {
          const key = field.name.match(/^custom_theme_settings\[([^\]]+)\]$/)?.[1];
          if (key) hydration.applyField(container, field.name,
            settings.custom_theme_settings[key] ?? defaults[key] ?? defaults[`${key}_placeholder`] ?? '');
        });
      }
    }
  };

  const getSelectedThemeOption = () =>
    themeSelectField && themeSelectField.selectedOptions && themeSelectField.selectedOptions.length
      ? themeSelectField.selectedOptions[0]
      : null;
  const getPlainCustomRadio = () => themeOptionRadios.find((radio) => {
    const themeValue = radio.getAttribute("data-theme-value") || radio.value || "";
    const presetKey = radio.getAttribute("data-preset-key") || "";
    return themeValue === "custom" && presetKey === "";
  });

  const closeThemeDropdown = () => {
    if (!themeDropdownButton || !themeDropdownPanel || !themeDropdown) {
      return;
    }
    themeDropdownButton.setAttribute("aria-expanded", "false");
    themeDropdownPanel.hidden = true;
    themeDropdown.classList.remove("is-open");
    syncSettingsPanelOverflowState();
  };

  const updateThemeConfigButtonVisibility = () => {
    if (!themeConfigButton || !themeSelectField || !themeOptionRadios.length) {
      return;
    }
    const customRadio = getPlainCustomRadio();
    const isAvailable = Boolean(customRadio && !customRadio.disabled);
    themeConfigButton.hidden = !isAvailable;
    themeConfigButton.disabled = !isAvailable;
    themeConfigButton.setAttribute("aria-disabled", isAvailable ? "false" : "true");

    if (!isAvailable && activeCustomThemeTrigger === themeConfigButton) {
      closeCustomThemeModal({ restoreFocus: false });
    }
  };

  const updateThemeDropdownLabel = () => {
    if (!themeDropdownLabel || !themeSelectField) {
      return;
    }
    const selectedOption =
      getSelectedThemeOption();
    const labelText =
      selectedOption && selectedOption.textContent
        ? selectedOption.textContent.trim()
        : "";

    themeDropdownLabel.textContent =
      labelText ||
      themeDropdown.dataset.placeholder ||
      "Select theme";
    updateThemeConfigButtonVisibility();
  };

  const setThemePresetKeyValue = (presetKey) => {
    if (!themePresetKeyField || themePresetKeyField.disabled) {
      return;
    }
    themePresetKeyField.value = presetKey || "";
  };

  const syncThemePresetKeyField = (themeValue, presetKey) => {
    if ((themeValue || "") !== "custom") {
      setThemePresetKeyValue("");
      return;
    }
    setThemePresetKeyValue(presetKey || "");
  };

  const getReadableIconColorForBackground = (color) => {
    const rawColor = String(color || "").trim();
    let hex = rawColor.startsWith("#") ? rawColor.slice(1) : rawColor;
    if (hex.length === 3) {
      hex = hex
        .split("")
        .map((char) => `${char}${char}`)
        .join("");
    }
    if (!/^[0-9a-f]{6}$/i.test(hex)) {
      return "#ffffff";
    }
    const red = parseInt(hex.slice(0, 2), 16);
    const green = parseInt(hex.slice(2, 4), 16);
    const blue = parseInt(hex.slice(4, 6), 16);
    const brightness = (red * 299 + green * 587 + blue * 114) / 1000;
    return brightness > 190 ? "#111111" : "#ffffff";
  };

  const updateWidgetIconAccent = () => {
    if (!widgetDesigner) {
      return;
    }

    const selectedOption =
      getSelectedThemeOption();
    const checkedRadio = themeOptionRadios.find((radio) => radio.checked);
    const selectedTheme = themeSelectField ? themeSelectField.value || "" : "";
    const selectedPresetKey =
      themePresetKeyField && themePresetKeyField.value
        ? themePresetKeyField.value
        : selectedOption && selectedOption.dataset
          ? selectedOption.dataset.presetKey || ""
          : "";
    let accentColor =
      checkedRadio && checkedRadio.dataset
        ? checkedRadio.dataset.primary || ""
        : "";
    let iconColor =
      checkedRadio && checkedRadio.dataset
        ? checkedRadio.dataset.iconColor || ""
        : "";

    if (!accentColor && selectedOption && selectedOption.dataset) {
      accentColor = selectedOption.dataset.primary || "";
      iconColor = selectedOption.dataset.iconColor || "";
    }

    if (selectedTheme === "custom" && !selectedPresetKey) {
      const customPrimaryField = builder.querySelector(
        'input[name="custom_theme_settings[primary_color]"]'
      );
      if (customPrimaryField && customPrimaryField.value) {
        accentColor = customPrimaryField.value;
        iconColor = getReadableIconColorForBackground(accentColor);
      }
    }

    if (!accentColor) {
      widgetDesigner.style.removeProperty("--aipkit-widget-active-color");
      widgetDesigner.style.removeProperty("--aipkit-widget-active-icon-color");
      return;
    }

    widgetDesigner.style.setProperty("--aipkit-widget-active-color", accentColor);
    widgetDesigner.style.setProperty(
      "--aipkit-widget-active-icon-color",
      iconColor || getReadableIconColorForBackground(accentColor)
    );
  };

  const clearThemePresetSelection = () => {
    syncThemePresetKeyField("custom", "");

    if (themeSelectField && !themeSelectField.disabled && themeSelectField.value === "custom") {
      const plainCustomIndex = Array.from(themeSelectField.options || []).findIndex(
        (option) =>
          (option.value || "") === "custom" &&
          !(option.dataset && option.dataset.presetKey)
      );
      if (
        plainCustomIndex >= 0 &&
        themeSelectField.selectedIndex !== plainCustomIndex
      ) {
        themeSelectField.selectedIndex = plainCustomIndex;
      }
    }

    if (themeOptionRadios.length) {
      const plainCustomRadio = getPlainCustomRadio();
      if (plainCustomRadio) {
        plainCustomRadio.checked = true;
      }
    }

    updateThemeDropdownLabel();
    updateWidgetIconAccent();
  };

  const syncThemeRadiosFromSelect = () => {
    if (!themeSelectField || !themeOptionRadios.length) {
      updateThemeDropdownLabel();
      updateWidgetIconAccent();
      return;
    }

    const selectedOption =
      getSelectedThemeOption();
    const selectedThemeValue = themeSelectField.value || "";
    const selectedPresetKey =
      selectedOption && selectedOption.dataset && selectedOption.dataset.presetKey
        ? selectedOption.dataset.presetKey
        : "";

    let matched = false;
    themeOptionRadios.forEach((radio) => {
      const themeValue = radio.getAttribute("data-theme-value") || radio.value || "";
      const presetKey = radio.getAttribute("data-preset-key") || "";
      const isMatch =
        themeValue === selectedThemeValue && presetKey === selectedPresetKey;
      radio.checked = isMatch;
      if (isMatch) {
        matched = true;
      }
    });

    if (!matched && selectedThemeValue) {
      const fallbackRadio = themeOptionRadios.find((radio) => {
        const themeValue = radio.getAttribute("data-theme-value") || radio.value || "";
        return themeValue === selectedThemeValue;
      });
      if (fallbackRadio) {
        fallbackRadio.checked = true;
      }
    }

    const checkedRadio = themeOptionRadios.find((radio) => radio.checked);
    const resolvedThemeValue = checkedRadio
      ? checkedRadio.getAttribute("data-theme-value") || checkedRadio.value || ""
      : selectedThemeValue;
    const resolvedPresetKey = checkedRadio
      ? checkedRadio.getAttribute("data-preset-key") || ""
      : selectedPresetKey;

    syncThemePresetKeyField(resolvedThemeValue, resolvedPresetKey);
    updateThemeDropdownLabel();
    updateWidgetIconAccent();
  };

  const syncThemeSelectFromRadio = (radio) => {
    if (!radio || !themeSelectField) {
      return;
    }

    const themeValue = radio.getAttribute("data-theme-value") || radio.value || "";
    const presetKey = radio.getAttribute("data-preset-key") || "";
    if (!themeValue) {
      return;
    }

    const options = Array.from(themeSelectField.options || []);
    let matchedIndex = options.findIndex((option) =>
      (option.value || "") === themeValue &&
      (option.dataset && option.dataset.presetKey ? option.dataset.presetKey : "") === presetKey
    );

    if (matchedIndex === -1) {
      matchedIndex = options.findIndex((option) => (option.value || "") === themeValue);
    }
    if (matchedIndex < 0 || !options[matchedIndex]) {
      return;
    }

    const shouldDispatch =
      themeSelectField.selectedIndex !== matchedIndex;

    themeSelectField.selectedIndex = matchedIndex;

    if (shouldDispatch) {
      themeSelectField.dispatchEvent(new Event("change", { bubbles: true }));
    } else {
      syncThemeRadiosFromSelect();
    }
  };

  const normalizeCustomThemeHex = (value, allowShort = true) => {
    const rawValue = String(value || "")
      .trim()
      .replace(/^#/, "");
    if (allowShort && /^[0-9a-f]{3}$/i.test(rawValue)) {
      return `#${rawValue
        .split("")
        .map((character) => character + character)
        .join("")}`.toUpperCase();
    }
    return /^[0-9a-f]{6}$/i.test(rawValue)
      ? `#${rawValue.toUpperCase()}`
      : "";
  };

  const syncCustomThemeHexInput = () => {
    if (!customThemeColorInput || !customThemeHexInput) {
      return;
    }
    const normalizedColor = normalizeCustomThemeHex(
      customThemeColorInput.value,
      false
    );
    customThemeHexInput.value = normalizedColor || "#0B5FFF";
    customThemeHexInput.setAttribute("aria-invalid", "false");
  };

  const getCustomThemeModalFocusableElements = () => {
    if (!customThemeModalContent) {
      return [];
    }
    return Array.from(
      customThemeModalContent.querySelectorAll(
        'button:not([disabled]):not([hidden]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
      )
    ).filter((element) => element.offsetParent !== null);
  };

  const closeCustomThemeModal = ({ restoreFocus = true } = {}) => {
    cancelThemeFocus();
    if (!customThemeModal) {
      return;
    }
    customThemeModal.classList.remove("aipkit-active");
    customThemeModal.setAttribute("aria-hidden", "true");
    const triggerToRestore = activeCustomThemeTrigger;
    if (activeCustomThemeTrigger) {
      activeCustomThemeTrigger.setAttribute("aria-expanded", "false");
      activeCustomThemeTrigger = null;
    }
    updateBuilderSheetScrollLock();
    if (restoreFocus && builder.isConnected && (!modalLifetime || modalLifetime.alive())) {
      const fallbackTrigger = builder.querySelector(
        ".aipkit_widget_color_more_btn:not([disabled])"
      );
      const focusTarget =
        triggerToRestore &&
        triggerToRestore.isConnected &&
        triggerToRestore.offsetParent !== null
          ? triggerToRestore
          : fallbackTrigger;
      focusTarget?.focus({ preventScroll: true });
    }
  };
  const openCustomThemeModal = (trigger) => {
    if (!customThemeModal?.isConnected || !builder.isConnected || !trigger?.isConnected || trigger.disabled ||
      trigger.closest('[inert]') || (modalLifetime && !modalLifetime.editable())) return;
    cancelThemeFocus();
    closeOtherAdvancedDetailPanels(customThemeModal);
    if (activeCustomThemeTrigger && activeCustomThemeTrigger !== trigger) {
      activeCustomThemeTrigger.setAttribute("aria-expanded", "false");
    }
    activeCustomThemeTrigger = trigger;
    trigger.setAttribute("aria-expanded", "true");
    customThemeModal.classList.add("aipkit-active");
    customThemeModal.setAttribute("aria-hidden", "false");
    updateBuilderSheetScrollLock();
    syncCustomThemeHexInput();
    if (typeof window.aipkit_attachRangeValueHandlers === "function") {
      window.aipkit_attachRangeValueHandlers("#aipkit_custom_theme_modal");
    }
    const isCurrent = modalLifetime ? modalLifetime.capture() : () => builder.isConnected && customThemeModal.isConnected;
    const frame = window.requestAnimationFrame(() => {
      if (customThemeFocusFrame !== frame) return;
      customThemeFocusFrame = null;
      if (!isCurrent() || activeCustomThemeTrigger !== trigger || !customThemeModal.classList.contains('aipkit-active')) return;
      const focusableElements = getCustomThemeModalFocusableElements();
      (customThemeCloseBtn || focusableElements[0])?.focus({
        preventScroll: true,
      });
    });
    customThemeFocusFrame = frame;
  };

  const syncMediaVisibility = () => {
    if (!popupSettingsPanel) return;
    const ensureDefaultCustomUrl = (selector) => {
      const input = popupSettingsPanel.querySelector(selector);
      if (!input) {
        return;
      }
      const fallbackUrl = (input.getAttribute("data-default-url") || "").trim();
      if (!fallbackUrl) {
        return;
      }
      const currentValue = (input.value || "").trim();
      const normalized = currentValue.toLowerCase();
      const looksLikeIconKey =
        normalized === "spark" ||
        normalized === "chat-bubble" ||
        normalized === "openai" ||
        normalized === "plus" ||
        normalized === "question-mark";
      if (!currentValue || looksLikeIconKey) {
        input.value = fallbackUrl;
      }
    };

    const selectedPopupIconOption = popupSettingsPanel.querySelector(
      'input[name="popup_icon_default"]:checked'
    );
    const iconType =
      selectedPopupIconOption && selectedPopupIconOption.value === "__custom__"
        ? "custom"
        : "default";
    const iconCustomContainer = popupSettingsPanel.querySelector(
      ".aipkit_popup_icon_custom_input_container"
    );
    if (iconCustomContainer) {
      if (iconType === "custom") {
        iconCustomContainer.removeAttribute("hidden");
        ensureDefaultCustomUrl('[name="popup_icon_custom_url"]');
      } else {
        iconCustomContainer.setAttribute("hidden", "");
      }
    }

    const selectedHeaderAvatarOption = popupSettingsPanel.querySelector(
      'input[name="header_avatar_default"]:checked'
    );
    const headerAvatarType =
      selectedHeaderAvatarOption &&
      selectedHeaderAvatarOption.value === "__custom__"
        ? "custom"
        : selectedHeaderAvatarOption &&
            selectedHeaderAvatarOption.value === "__inherit__"
          ? "inherit"
          : "default";
    const headerCustomContainer = popupSettingsPanel.querySelector(
      ".aipkit_header_avatar_custom_input_container"
    );
    if (headerCustomContainer) {
      if (headerAvatarType === "custom") {
        headerCustomContainer.removeAttribute("hidden");
        ensureDefaultCustomUrl('[name="header_avatar_url"]');
      } else {
        headerCustomContainer.setAttribute("hidden", "");
      }
    }
  };

  const findPopupRadioByValue = (name, value) => {
    if (!popupSettingsPanel) {
      return null;
    }
    return Array.from(
      popupSettingsPanel.querySelectorAll(`input[name="${name}"]`)
    ).find((radio) => radio.value === value) || null;
  };

  const setQuickAvatarFallback = () => {
    if (!quickAvatarPreview) {
      return;
    }
    const initial = quickAvatarPreview.dataset.avatarInitial || "A";
    quickAvatarPreview.innerHTML = "";
    const fallback = document.createElement("span");
    fallback.className = "aipkit_widget_avatar_initial";
    fallback.setAttribute("aria-hidden", "true");
    fallback.textContent = initial;
    quickAvatarPreview.appendChild(fallback);
  };

  const renderShortcutImage = (preview, className, url) => {
    if (!preview || !url) return;
    preview.innerHTML = "";
    const image = document.createElement("img");
    image.className = className;
    image.src = url;
    image.alt = "";
    preview.appendChild(image);
  };

  const renderQuickAvatarIcon = (iconKey) => {
    if (!quickAvatarPreview || !popupSettingsPanel || !iconKey) {
      return false;
    }
    const iconRadio = findPopupRadioByValue("header_avatar_default", iconKey);
    const iconCard = iconRadio ? iconRadio.closest(".aipkit_option_card") : null;
    const svg = iconCard ? iconCard.querySelector("svg") : null;
    if (!svg) {
      return false;
    }
    quickAvatarPreview.innerHTML = "";
    const iconWrap = document.createElement("span");
    iconWrap.className = "aipkit_widget_avatar_icon";
    iconWrap.setAttribute("aria-hidden", "true");
    iconWrap.appendChild(svg.cloneNode(true));
    quickAvatarPreview.appendChild(iconWrap);
    return true;
  };

  const syncQuickAvatarShortcut = () => {
    if (!quickAvatarPreview || !popupSettingsPanel) {
      return;
    }
    const selectedAvatar = popupSettingsPanel.querySelector(
      'input[name="header_avatar_default"]:checked'
    );
    const avatarUrlInput = popupSettingsPanel.querySelector(
      '[name="header_avatar_url"]'
    );
    const selectedValue = selectedAvatar ? selectedAvatar.value : "";
    const avatarUrl = avatarUrlInput ? String(avatarUrlInput.value || "").trim() : "";
    const isInherited = selectedValue === "__inherit__";
    if (quickAvatarUseWidgetBtn) {
      quickAvatarUseWidgetBtn.hidden = isInherited;
    }
    if (quickAvatarLinkStatus) {
      quickAvatarLinkStatus.textContent = isInherited
        ? __("Matches widget icon", "gpt3-ai-content-generator")
        : selectedValue === "__custom__"
          ? __("Custom image", "gpt3-ai-content-generator")
          : __("Separate icon", "gpt3-ai-content-generator");
    }
    if (isInherited) {
      const selectedPopupIcon = popupSettingsPanel.querySelector(
        'input[name="popup_icon_default"]:checked'
      );
      const popupIconValue = selectedPopupIcon ? selectedPopupIcon.value : "";
      const popupIconUrlInput = getPopupIconCustomUrlInput();
      const popupIconUrl = popupIconUrlInput
        ? String(popupIconUrlInput.value || "").trim()
        : "";
      if (popupIconValue === "__custom__" && popupIconUrl) {
        renderShortcutImage(quickAvatarPreview, "aipkit_widget_avatar_img", popupIconUrl);
        return;
      }
      if (
        popupIconValue &&
        popupIconValue !== "__custom__" &&
        renderQuickAvatarIcon(popupIconValue)
      ) {
        return;
      }
      setQuickAvatarFallback();
      return;
    }
    if (selectedValue === "__custom__" && avatarUrl) {
      renderShortcutImage(quickAvatarPreview, "aipkit_widget_avatar_img", avatarUrl);
      return;
    }
    if (selectedValue && selectedValue !== "__custom__" && renderQuickAvatarIcon(selectedValue)) {
      return;
    }
    setQuickAvatarFallback();
  };

  const getPopupIconCustomUrlInput = () =>
    popupSettingsPanel
      ? popupSettingsPanel.querySelector('[name="popup_icon_custom_url"]')
      : null;

  const getPopupIconCustomUrlDisplay = () =>
    popupSettingsPanel
      ? popupSettingsPanel.querySelector("[data-aipkit-popup-icon-url-display]")
      : null;

  const setQuickWidgetIconCustomFallback = () => {
    if (!quickWidgetIconCustomVisual) {
      return;
    }
    quickWidgetIconCustomVisual.innerHTML = "";
    const icon = document.createElement("span");
    icon.className = "dashicons dashicons-plus-alt2";
    icon.setAttribute("aria-hidden", "true");
    quickWidgetIconCustomVisual.appendChild(icon);
  };


  const syncPopupIconCustomUrlDisplay = () => {
    const source = getPopupIconCustomUrlInput();
    const display = getPopupIconCustomUrlDisplay();
    if (!source || !display) {
      return;
    }
    const selectedPopupIcon = popupSettingsPanel.querySelector(
      'input[name="popup_icon_default"]:checked'
    );
    const selectedValue = selectedPopupIcon ? selectedPopupIcon.value : "";
    const displayValue = selectedValue === "__custom__" ? source.value || "" : "";
    if (display.value !== displayValue) {
      display.value = displayValue;
    }
  };

  const syncQuickWidgetIconShortcut = () => {
    if ((!quickWidgetIconRadios.length && !quickWidgetIconUploadBtn) || !popupSettingsPanel) {
      return;
    }
    const selectedPopupIcon = popupSettingsPanel.querySelector(
      'input[name="popup_icon_default"]:checked'
    );
    const selectedValue = selectedPopupIcon ? selectedPopupIcon.value : "";
    const customUrlInput = getPopupIconCustomUrlInput();
    const customUrl = customUrlInput ? String(customUrlInput.value || "").trim() : "";
    quickWidgetIconRadios.forEach((radio) => {
      radio.checked = selectedValue !== "__custom__" && radio.value === selectedValue;
    });
    if (quickWidgetIconUploadBtn) {
      const isCustomSelected = selectedValue === "__custom__";
      quickWidgetIconUploadBtn.classList.toggle("is-selected", isCustomSelected);
      quickWidgetIconUploadBtn.setAttribute("aria-pressed", isCustomSelected ? "true" : "false");
    }
    if (selectedValue === "__custom__" && customUrl) {
      renderShortcutImage(quickWidgetIconCustomVisual, "aipkit_widget_icon_custom_img", customUrl);
    } else {
      setQuickWidgetIconCustomFallback();
    }
    syncPopupIconCustomUrlDisplay();
  };

  const syncQuickDesignShortcuts = () => {
    syncQuickAvatarShortcut();
    syncQuickWidgetIconShortcut();
  };

  return {
    applyThemeSettings,
    bindPersistence({ settingsPanel, styleSection, interfaceControlsSection, startersPanel, consentPanel,
      persistence, updateConsentControls, updateConversationStartersControls, syncInterfaceControls }) {
      const roots = [...new Set([builder, customThemeModal, startersPanel, consentPanel].filter(Boolean))];
      const textNames = ['greeting', 'subgreeting', 'input_placeholder', 'footer_text', 'custom_typing_text', 'retrieving_context_text'];
      const toggleNames = ['enable_download', 'enable_fullscreen', 'enable_conversation_sidebar', 'enable_conversation_starters'];
      const themeFields = 'input[name^="custom_theme_settings["], select[name^="custom_theme_settings["]';
      const textFields = [...textNames.map(name => `input[name="${name}"]`), 'textarea[name="conversation_starters"]',
        'input[name="consent_title"]', 'textarea[name="consent_message"]', 'input[name="consent_button"]'].join(', ');
      const changeFields = [textFields, ...[...toggleNames, 'enable_consent_compliance'].flatMap(name =>
        [`input[name="${name}"]`, `select[name="${name}"]`]), 'select[name="theme"]', themeFields].join(', ');
      const byBotId = suffix => {
        const id = persistence.getSelectedBuilderBotId();
        if (!id) return null;
        for (const root of roots) {
          const field = root.querySelector(`[id="aipkit_bot_${id}_${suffix}"]`);
          if (field) return field;
        }
        return null;
      };
      const value = field => field.type === 'checkbox' ? (field.checked ? '1' : '0') : field.value || '';
      const readSettings = () => {
        const settings = {};
        const theme = styleSection.querySelector('select[name="theme"]');
        const activeTheme = theme && !theme.disabled ? theme : builder.querySelector('select[name="theme"]');
        if (activeTheme && !activeTheme.disabled) settings.theme = activeTheme.value || 'light';
        settings.theme_preset_key = settings.theme === 'custom'
          ? (!themePresetKeyField?.disabled && themePresetKeyField?.value) ||
            (theme && !theme.disabled && theme.selectedOptions?.[0]?.dataset.presetKey) || '' : '';
        for (const name of textNames) {
          const field = settingsPanel.querySelector(`input[name="${name}"]`) || styleSection.querySelector(`input[name="${name}"]`);
          if (field && !field.disabled) settings[name] = value(field);
        }
        for (const name of toggleNames) {
          const field = interfaceControlsSection.querySelector(`[name="${name}"]`) || styleSection.querySelector(`[name="${name}"]`);
          if (field && !field.disabled) settings[name] = field.tagName === 'SELECT' ? (field.value === '1' ? '1' : '0') : (field.checked ? '1' : '0');
        }
        for (const name of ['enable_consent_compliance', 'consent_title', 'consent_message', 'consent_button']) {
          const field = byBotId(name);
          if (field && !field.disabled) settings[name] = name === 'enable_consent_compliance'
            ? (field.tagName === 'SELECT' ? (field.value === '1' ? '1' : '0') : (field.checked ? '1' : '0')) : value(field);
        }
        // Custom-theme fields intentionally keep the endpoint's existing disabled-field policy.
        byBotId('custom_theme_settings_container')?.querySelectorAll(themeFields).forEach(field => {
          if (field.name && (field.type !== 'radio' || field.checked)) settings[field.name] = value(field);
        });
        const starters = byBotId('conversation_starters');
        if (starters && !starters.disabled) settings.conversation_starters = value(starters);
        return settings;
      };
      const ownedSettings = settings => {
        const result = {};
        for (const [name, value] of Object.entries(settings)) {
          const key = name.match(/^custom_theme_settings\[([^\]]+)\]$/)?.[1];
          if (key) (result.custom_theme_settings ||= {})[key] = value;
          else result[name] = value;
        }
        return result;
      };
      const isPreset = target => Boolean(target.selectedOptions?.[0]?.dataset.presetKey?.trim());
      bindChatbotSettingsAutosave({
        builder, panel: settingsPanel, boundKey: 'styleSettingsAutosaveBound',
        action: 'aipkit_update_chatbot_style_settings', persistence, readSettings, ownedSettings,
        isUnchanged: (a, b) => JSON.stringify(a) === JSON.stringify(b),
        savedSettings: (settings, response) => {
          const saved = ownedSettings(settings);
          for (const name of ['enable_copy_button', 'enable_feedback']) {
            if (response?.bot?.settings?.[name] === '1') saved[name] = '1';
          }
          return saved;
        },
        restoreSettings: settings => {
          roots.forEach(root => hydration.applySettings(root, settings));
          applyThemeSettings(settings);
        },
        resetSettings: (settings, response) => Object.fromEntries(Object.entries(settings).map(([name, value]) => {
          const key = name.match(/^custom_theme_settings\[([^\]]+)\]$/)?.[1];
          return [name, key ? response.bot.settings.custom_theme_settings?.[key] ?? '' :
            name === 'conversation_starters' ? response.bot.conversation_starters_text ?? value : response.bot.settings[name] ?? value];
        })),
        afterHydrate: () => {
          updateConsentControls(); updateConversationStartersControls(); syncInterfaceControls();
          syncThemeRadiosFromSelect(); syncCustomThemeHexInput(); updateWidgetIconAccent();
        },
        interactionNodes: () => [styleSection, interfaceControlsSection.querySelector('[data-aipkit-interface-controls]'),
          ...builder.querySelectorAll('[data-aipkit-theme-dropdown], .aipkit_starters_config_btn, .aipkit_consent_config_btn'),
          customThemeModal, startersPanel, consentPanel],
        bindEvents: ({ signal, isEditable, save, draft }) => {
          const owns = target => isEditable() && target?.isConnected && roots.some(root => root.contains(target));
          document.addEventListener('change', event => {
            const target = event.target;
            if (!owns(target) || !target.matches(changeFields)) return;
            if (target.matches('[name="enable_conversation_starters"]')) updateConversationStartersControls();
            if (target.matches('[name="enable_consent_compliance"]')) updateConsentControls();
            if (target.matches('select[name="theme"]') && styleSection.contains(target)) {
              if (target.value !== 'custom' || isPreset(target)) closeCustomThemeModal({ restoreFocus: false });
              else openCustomThemeModal(styleSection.querySelector('.aipkit_theme_config_btn:not([hidden])') ||
                styleSection.querySelector('[data-aipkit-theme-dropdown] .aipkit_popover_multiselect_btn') || target);
            }
            if (isThemeUpdating(event.target) || (target.matches('select[name="theme"]') && isPreset(target))) return;
            if (target.matches(themeFields)) {
              if (themeSelectField?.value === 'custom') clearThemePresetSelection();
              updateWidgetIconAccent();
            }
            save();
          }, { signal });
          document.addEventListener('input', event => {
            if (owns(event.target) && event.target.matches(`${textFields}, ${themeFields}`) && !isThemeUpdating(event.target)) draft();
          }, { signal });
          document.addEventListener('blur', event => {
            if (owns(event.target) && event.target.matches(textFields) && !isThemeUpdating(event.target)) save();
          }, { signal, capture: true });
          for (const type of [THEME_PRESET_APPLIED_EVENT, 'aipkit:custom-theme-reset']) {
            document.addEventListener(type, event => {
              const id = String(event.detail?.botId || '').trim();
              if (!owns(event.detail?.settingsContainer) || id !== String(persistence.getSelectedBuilderBotId()).trim()) return;
              if (type === 'aipkit:custom-theme-reset') { clearThemePresetSelection(); updateWidgetIconAccent(); }
              syncCustomThemeHexInput(); save();
            }, { signal });
          }
        },
      });
    },
    bindInterface({ interfaceControlsSection, closeStartersPanel }) {
      if (interfaceBinding?.section === interfaceControlsSection && interfaceBinding.lifetime.alive()) return interfaceBinding.api;
      interfaceBinding?.lifetime.dispose();
      let pendingFrame = null, lifetime = null;
      const markers = [];
      const alive = () => builder.isConnected && interfaceControlsSection.isConnected && (!lifetime || lifetime.alive());
      const cancelFrame = () => {
        if (pendingFrame !== null) window.cancelAnimationFrame(pendingFrame);
        pendingFrame = null;
      };
      const updateConversationStartersControls = () => {
        if (!alive()) return;
        const startersToggle = interfaceControlsSection.querySelector(
          '[name="enable_conversation_starters"]'
        );
        const startersConfigBtn = interfaceControlsSection.querySelector(
          ".aipkit_starters_config_btn"
        );
        if (!startersToggle || !startersConfigBtn) {
          return;
        }
        const isEnabled =
          !startersToggle.disabled &&
          (startersToggle.tagName === "SELECT"
            ? startersToggle.value === "1"
            : !!startersToggle.checked);
        startersConfigBtn.hidden = false;
        startersConfigBtn.disabled = !isEnabled;
        startersConfigBtn.setAttribute(
          "aria-disabled",
          isEnabled ? "false" : "true"
        );
        if (!isEnabled) {
          startersConfigBtn.setAttribute("aria-expanded", "false");
          closeStartersPanel();
        }
      };

      const interfaceControls = interfaceControlsSection.querySelector(
        "[data-aipkit-interface-controls]"
      );

      const interfaceControlOptions = interfaceControls
        ? Array.from(
            interfaceControls.querySelectorAll(
              ".aipkit_interface_control_option"
            )
          )
        : [];
      const interfaceControlFields = {};
      Array.from(
        interfaceControlsSection.querySelectorAll(".aipkit_interface_control_hidden_select")
      ).forEach((field) => {
        const fieldName = field.getAttribute("name");
        if (fieldName) {
          interfaceControlFields[fieldName] = field;
        }
      });
      const sidebarInterfaceOption = interfaceControlsSection.querySelector(
        ".aipkit_interface_control_option--sidebar"
      );
      const sidebarInterfaceOptionItem = sidebarInterfaceOption
        ? sidebarInterfaceOption.closest(".aipkit_interface_control_item--sidebar")
        : null;
      const sidebarInterfaceOptionLabel = sidebarInterfaceOption
        ? sidebarInterfaceOption.closest(".aipkit_interface_feature_label")
        : null;
      const topModeSelectForControls = builder.querySelector(
        "[data-aipkit-top-mode-select]"
      );
      const popupEnabledInputForControls = builder.querySelector(
        "[data-aipkit-popup-enabled-input]"
      );

      const getToggleFieldState = (field) => {
        if (!field) {
          return false;
        }
        if (field.tagName === "SELECT") {
          return field.value === "1";
        }
        return Boolean(field.checked);
      };

      const setToggleFieldState = (field, nextState) => {
        if (!field?.isConnected || field.disabled) {
          return;
        }
        const normalized = Boolean(nextState);
        const currentState = getToggleFieldState(field);
        if (currentState === normalized) {
          return;
        }
        if (field.tagName === "SELECT") {
          field.value = normalized ? "1" : "0";
        } else {
          field.checked = normalized;
        }
        field.dispatchEvent(new Event("change", { bubbles: true }));
      };

      const syncInterfaceControlsOptionsFromFields = () => {
        if (!alive()) return;
        interfaceControlOptions.forEach((option) => {
          if (!option?.isConnected) {
            return;
          }
          const optionField = interfaceControlFields[option.value];
          if (!optionField?.isConnected) {
            return;
          }
          option.checked = getToggleFieldState(optionField);
        });
      };

      const syncInterfaceControlFieldsFromOptions = () => {
        interfaceControlOptions.forEach((option) => {
          if (!option?.isConnected || option.disabled) {
            return;
          }
          const optionField = interfaceControlFields[option.value];
          if (!optionField) {
            return;
          }
          setToggleFieldState(optionField, option.checked);
        });
      };

      const updateInterfaceSidebarOptionState = () => {
        if (!alive()) return;
        if (!sidebarInterfaceOption || !sidebarInterfaceOptionItem) {
          return;
        }
        const sidebarField = interfaceControlFields.enable_conversation_sidebar;
        const currentDeployMode = topModeSelectForControls
          ? String(topModeSelectForControls.value || "").trim()
          : "";
        const isPopupMode = currentDeployMode
          ? currentDeployMode !== "inline"
          : popupEnabledInputForControls
            ? popupEnabledInputForControls.value === "1"
            : sidebarField
              ? sidebarField.disabled
              : false;
        sidebarInterfaceOption.disabled = isPopupMode;
        sidebarInterfaceOptionItem.hidden = isPopupMode;
        sidebarInterfaceOptionItem.classList.remove("is-disabled");
        if (sidebarInterfaceOptionLabel) {
          sidebarInterfaceOptionLabel.classList.remove("is-disabled");
        }
      };

      lifetime = bindPresentation(builder, [interfaceControlsSection, interfaceControls].filter(Boolean), cancelFrame, () => {
        markers.forEach(([node, key]) => { if (node.dataset[key] === '1') delete node.dataset[key]; });
      }, getSelectedBotId);
      const syncInterfaceControlsUiState = () => {
        cancelFrame();
        updateInterfaceSidebarOptionState();
        syncInterfaceControlsOptionsFromFields();
      };
      if (interfaceControls && !interfaceControls.dataset.bound) {
        interfaceControlOptions.forEach((option) => {
          lifetime.listen(option, "change", () => {
            syncInterfaceControlFieldsFromOptions();
          });
        });

        Object.values(interfaceControlFields).forEach((field) => {
          lifetime.listen(field, "change", () => {
            syncInterfaceControlsUiState();
          });
        });

        if (
          topModeSelectForControls &&
          topModeSelectForControls.dataset.interfaceControlsBound !== "1"
        ) {
          lifetime.listen(topModeSelectForControls, "change", () => {
            cancelFrame();
            const isCurrent = lifetime.capture();
            const frame = window.requestAnimationFrame(() => {
              if (pendingFrame !== frame) return;
              pendingFrame = null;
              if (isCurrent()) syncInterfaceControlsUiState();
            });
            pendingFrame = frame;
          });
          topModeSelectForControls.dataset.interfaceControlsBound = "1";
          markers.push([topModeSelectForControls, "interfaceControlsBound"]);
        }

        syncInterfaceControlsUiState();
        interfaceControls.dataset.bound = "1";
        markers.push([interfaceControls, "bound"]);
      }
      const api = { updateConversationStartersControls, syncInterfaceControlsUiState };
      interfaceBinding = { section: interfaceControlsSection, lifetime, api };
      return api;
    },
    syncMediaVisibility,
    syncQuickDesignShortcuts,
    bindMedia({ updatePopupSettingsVisibility, setSaveStatus }) {
      if (!widgetDesigner || !popupSettingsPanel || builder.dataset.widgetDesignerShortcutsBound || !builder.isConnected) return;
      const pickers = [];
      const lifetime = bindPresentation(builder, [widgetDesigner, popupSettingsPanel], () => {
        pickers.forEach(picker => picker.cancel());
      }, () => {
        pickers.forEach(picker => picker.remove());
        pickers.length = 0;
        delete builder.dataset.widgetDesignerShortcutsBound;
      }, getSelectedBotId);
      const bindMediaPicker = (button, getLabels, applyImage) => {
        if (!button) return;
        let frame = null, owner = null;
        const context = {};
        const cancel = () => { owner = null; frame?.close(); };
        pickers.push({ cancel, remove: () => {
          if (!frame) return;
          frame.off(null, null, context);
          (frame.modal || frame).remove();
          if (window.wp?.media?.frame === frame) delete window.wp.media.frame;
          frame = null;
        } });
        lifetime.listen(button, 'click', event => {
          event.preventDefault();
          if (button.disabled) return;
          if (!window.wp || !window.wp.media) {
            setSaveStatus(__("Media library is unavailable.", "gpt3-ai-content-generator"), "error");
            return;
          }
          if (!frame) {
            frame = window.wp.media({ ...getLabels(), library: { type: "image" }, multiple: false });
            frame.on('select', () => {
              if (!owner?.()) return;
              const attachment = frame.state().get('selection').first();
              if (!attachment) return;
              const data = attachment.toJSON ? attachment.toJSON() : {};
              const url = data?.sizes?.thumbnail?.url || data?.sizes?.medium?.url || data?.url || '';
              if (!url || !owner?.()) return;
              const isCurrent = owner;
              owner = null;
              applyImage(url, isCurrent);
            }, context);
            frame.on('close', () => {
              // WordPress's Select button closes the frame before emitting select.
              const closingOwner = owner;
              if (closingOwner) Promise.resolve().then(() => { if (owner === closingOwner) owner = null; });
            }, context);
            frame.on('escape', () => { owner = null; }, context);
          }
          owner = lifetime.capture();
          frame.open();
        });
      };

      bindMediaPicker(quickAvatarUploadBtn, () => ({
        title: __("Choose assistant photo", "gpt3-ai-content-generator"),
        button: { text: __("Use this photo", "gpt3-ai-content-generator") },
      }), (url, isCurrent) => {
        const customRadio = findPopupRadioByValue("header_avatar_default", "__custom__");
        const avatarUrlInput = popupSettingsPanel.querySelector(
          '[name="header_avatar_url"]'
        );
        if (!customRadio || !avatarUrlInput) {
          return;
        }
        customRadio.checked = true;
        updatePopupSettingsVisibility();
        if (!isCurrent()) return;
        avatarUrlInput.value = url;
        renderShortcutImage(quickAvatarPreview, "aipkit_widget_avatar_img", url);
        avatarUrlInput.dispatchEvent(new Event("change", { bubbles: true }));
      });
      if (quickAvatarUseWidgetBtn) {
        lifetime.listen(quickAvatarUseWidgetBtn, "click", (event) => {
          event.preventDefault();
          const inheritRadio = findPopupRadioByValue(
            "header_avatar_default",
            "__inherit__"
          );
          if (!inheritRadio) {
            return;
          }
          inheritRadio.checked = true;
          inheritRadio.dispatchEvent(new Event("change", { bubbles: true }));
        });
      }
      bindMediaPicker(quickWidgetIconUploadBtn, () => ({
        title: __("Choose widget icon", "gpt3-ai-content-generator"),
        button: { text: __("Use this icon", "gpt3-ai-content-generator") },
      }), (url, isCurrent) => {
        const customRadio = findPopupRadioByValue("popup_icon_default", "__custom__");
        const iconUrlInput = getPopupIconCustomUrlInput();
        const iconUrlDisplay = getPopupIconCustomUrlDisplay();
        if (!customRadio || !iconUrlInput) {
          return;
        }
        customRadio.checked = true;
        updatePopupSettingsVisibility();
        if (!isCurrent()) return;
        iconUrlInput.value = url;
        if (iconUrlDisplay) {
          iconUrlDisplay.value = url;
        }
        renderShortcutImage(quickWidgetIconCustomVisual, "aipkit_widget_icon_custom_img", url);
        syncQuickWidgetIconShortcut();
        iconUrlInput.dispatchEvent(new Event("change", { bubbles: true }));
      });
      quickWidgetIconRadios.forEach((radio) => {
        lifetime.listen(radio, "change", () => {
          if (!radio.checked) {
            return;
          }
          const targetRadio = findPopupRadioByValue("popup_icon_default", radio.value);
          if (!targetRadio) {
            return;
          }
          targetRadio.checked = true;
          targetRadio.dispatchEvent(new Event("change", { bubbles: true }));
        });
      });
      const iconUrlDisplay = getPopupIconCustomUrlDisplay();
      if (iconUrlDisplay) {
        lifetime.listen(iconUrlDisplay, "input", () => {
          const iconUrlInput = getPopupIconCustomUrlInput();
          const customRadio = findPopupRadioByValue("popup_icon_default", "__custom__");
          if (iconUrlInput) {
            iconUrlInput.value = iconUrlDisplay.value || "";
          }
          if (customRadio) {
            customRadio.checked = true;
          }
          updatePopupSettingsVisibility();
          syncQuickWidgetIconShortcut();
        });
        lifetime.listen(iconUrlDisplay, "change", () => {
          const iconUrlInput = getPopupIconCustomUrlInput();
          const customRadio = findPopupRadioByValue("popup_icon_default", "__custom__");
          if (!iconUrlInput) {
            return;
          }
          if (customRadio) {
            customRadio.checked = true;
          }
          iconUrlInput.value = iconUrlDisplay.value || "";
          updatePopupSettingsVisibility();
          iconUrlInput.dispatchEvent(new Event("change", { bubbles: true }));
        });
      }
      syncQuickDesignShortcuts();
      builder.dataset.widgetDesignerShortcutsBound = "1";
    },
    updateThemeConfigButtonVisibility,
    updateWidgetIconAccent,
    clearThemePresetSelection,
    syncThemeRadiosFromSelect,
    syncCustomThemeHexInput,
    closeCustomThemeModal,
    openCustomThemeModal,
    bindModal(registerCloser) {
      if (!customThemeModal || !builder.isConnected || modalLifetime?.alive()) return;
      const unregister = registerCloser?.(customThemeModal, closeCustomThemeModal);
      modalLifetime = bindPresentation(builder, [customThemeModal], () => closeCustomThemeModal({ restoreFocus: false }),
        () => unregister?.(), getSelectedBotId);
      if (customThemeCloseBtn) {
        modalLifetime.listen(customThemeCloseBtn, "click", (event) => {
          event.preventDefault();
          closeCustomThemeModal();
        });
      }

      if (customThemeModal) {
        modalLifetime.listen(customThemeModal, "click", (event) => {
          if (event.target === customThemeModal) {
            closeCustomThemeModal();
          }
        });
      }

      if (customThemeColorInput && customThemeHexInput) {
        modalLifetime.listen(customThemeColorInput, "input", syncCustomThemeHexInput);
        modalLifetime.listen(customThemeColorInput, "change", syncCustomThemeHexInput);

        const applyCustomThemeHexInput = ({ restoreInvalid = false } = {}) => {
          const normalizedColor = normalizeCustomThemeHex(
            customThemeHexInput.value
          );
          if (!normalizedColor) {
            customThemeHexInput.setAttribute("aria-invalid", "true");
            if (restoreInvalid) {
              syncCustomThemeHexInput();
            }
            return false;
          }
          customThemeHexInput.value = normalizedColor;
          customThemeHexInput.setAttribute("aria-invalid", "false");
          if (customThemeColorInput.value.toUpperCase() !== normalizedColor) {
            customThemeColorInput.value = normalizedColor;
            customThemeColorInput.dispatchEvent(
              new Event("input", { bubbles: true })
            );
            customThemeColorInput.dispatchEvent(
              new Event("change", { bubbles: true })
            );
          }
          return true;
        };

        modalLifetime.listen(customThemeHexInput, "input", () => {
          applyCustomThemeHexInput();
        });
        modalLifetime.listen(customThemeHexInput, "change", () => {
          applyCustomThemeHexInput({ restoreInvalid: true });
        });
        modalLifetime.listen(customThemeHexInput, "blur", () => {
          applyCustomThemeHexInput({ restoreInvalid: true });
        });
      }

      modalLifetime.listen(document, "keydown", (event) => {
        if (
          !customThemeModal ||
          !customThemeModal.classList.contains("aipkit-active")
        ) {
          return;
        }
        if (event.key === "Escape") {
          event.preventDefault();
          closeCustomThemeModal();
          return;
        }
        if (event.key !== "Tab") {
          return;
        }
        const focusableElements = getCustomThemeModalFocusableElements();
        if (!focusableElements.length) {
          event.preventDefault();
          return;
        }
        const firstFocusable = focusableElements[0];
        const lastFocusable = focusableElements[focusableElements.length - 1];
        if (event.shiftKey && document.activeElement === firstFocusable) {
          event.preventDefault();
          lastFocusable.focus();
        } else if (!event.shiftKey && document.activeElement === lastFocusable) {
          event.preventDefault();
          firstFocusable.focus();
        }
      });
    },
    bindTheme() {
      bindThemeEvents(builder, customThemeModal);
      if (!builder.isConnected || themeLifetime?.alive()) return;
      themeLifetime?.dispose();
      const markers = [];
      themeLifetime = bindPresentation(builder, [themeDropdown, themeSelectField].filter(Boolean), closeThemeDropdown, () => {
        markers.forEach(([node, key]) => { if (node.dataset[key] === '1') delete node.dataset[key]; });
      }, getSelectedBotId);
      if (
        themeDropdown &&
        themeDropdownButton &&
        themeDropdownPanel &&
        themeSelectField &&
        !themeDropdown.dataset.bound
      ) {
        themeLifetime.listen(themeDropdownButton, "click", (event) => {
          event.preventDefault();
          const isOpen =
            themeDropdownButton.getAttribute("aria-expanded") === "true";
          if (isOpen) {
            closeThemeDropdown();
            return;
          }
          themeDropdownButton.setAttribute("aria-expanded", "true");
          themeDropdownPanel.hidden = false;
          themeDropdown.classList.add("is-open");
          syncSettingsPanelOverflowState();
          syncThemeRadiosFromSelect();
        });

        themeOptionRadios.forEach((radio) => {
          themeLifetime.listen(radio, "change", () => {
            if (!radio.checked) {
              return;
            }
            syncThemeSelectFromRadio(radio);
            closeThemeDropdown();
          });
        });

        themeLifetime.listen(document, "click", (event) => {
          if (themeDropdown && !themeDropdown.contains(event.target)) {
            closeThemeDropdown();
          }
        });

        themeLifetime.listen(document, "keydown", (event) => {
          if (event.key === "Escape") {
            closeThemeDropdown();
          }
        });

        themeDropdown.dataset.bound = "1";
        markers.push([themeDropdown, "bound"]);
      }

      if (themeSelectField && themeSelectField.dataset.themeDropdownBound !== "1") {
        themeLifetime.listen(themeSelectField, "change", () => {
          syncThemeRadiosFromSelect();
        });
        themeSelectField.dataset.themeDropdownBound = "1";
        markers.push([themeSelectField, "themeDropdownBound"]);
      }

      if (!builder.dataset.themeConfigButtonsBound) {
        themeLifetime.listen(builder, "click", (event) => {
          const clickTarget = event.target;
          const clickedButton =
            clickTarget && clickTarget.closest
              ? clickTarget.closest(".aipkit_theme_config_btn")
              : null;
          if (!clickedButton || !builder.contains(clickedButton)) {
            return;
          }
          event.preventDefault();
          if (clickedButton.hidden || clickedButton.disabled) {
            return;
          }
          const customRadio = getPlainCustomRadio();
          if (customRadio && !customRadio.disabled) {
            if (!customRadio.checked) {
              customRadio.checked = true;
              syncThemeSelectFromRadio(customRadio);
            }
          }
          closeThemeDropdown();
          openCustomThemeModal(clickedButton);
        });
        builder.dataset.themeConfigButtonsBound = "1";
        markers.push([builder, "themeConfigButtonsBound"]);
      }
    },
  };
}
