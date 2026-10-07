/** Shared Chatbot settings shells and disclosure state; inert until setup. */

export function createChatbotPanels({
  builder,
  sheetOverlay,
  isCustomThemeOpen,
}) {
  const sheetTitle = sheetOverlay
    ? sheetOverlay.querySelector(".aipkit_builder_sheet_title")
    : null;
  const sheetDescription = sheetOverlay
    ? sheetOverlay.querySelector(".aipkit_builder_sheet_description")
    : null;
  const sheetSections = sheetOverlay
    ? sheetOverlay.querySelectorAll(".aipkit_builder_sheet_section")
    : [];
  const sheetCloseBtn = sheetOverlay
    ? sheetOverlay.querySelector(".aipkit_builder_sheet_close")
    : null;
  const updateBuilderSheetScrollLock = () => {
    const shouldLock = Boolean(
      (sheetOverlay && sheetOverlay.classList.contains("aipkit-active")) ||
        isCustomThemeOpen()
    );
    document.documentElement.classList.toggle(
      "aipkit_builder_sheet_scroll_lock",
      shouldLock
    );
    document.body.classList.toggle(
      "aipkit_builder_sheet_scroll_lock",
      shouldLock
    );
  };

  const setPanelActive = (panel, active) => {
    panel.classList[active ? "add" : "remove"]("aipkit-active");
    panel.setAttribute("aria-hidden", active ? "false" : "true");
  };

  return {
    updateBuilderSheetScrollLock,
    bindSheet({
      updateWebGroundingVisibility,
      updateAudioVisibility,
      updateConversationVisibility,
      updatePopupSettingsVisibility,
    }) {
      if (sheetOverlay && !builder.dataset.sheetBound) {
        let sheetCloseResetTimer = null;

        const openSheet = (trigger) => {
          if (sheetCloseResetTimer) {
            window.clearTimeout(sheetCloseResetTimer);
            sheetCloseResetTimer = null;
          }

          const title = trigger.dataset.sheetTitle || "Sheet";
          const description =
            trigger.dataset.sheetDescription || "Settings will appear here.";
          const contentKey = trigger.dataset.sheetContent || "placeholder";
          sheetOverlay.dataset.activeSheet = contentKey;
          // Its content starts over (What it knows opens on its list), then takes this trigger's title.
          sheetOverlay.dispatchEvent(new CustomEvent("aipkit:builder-sheet-open", { detail: { content: contentKey } }));

          if (sheetTitle) {
            sheetTitle.textContent = title;
          }
          if (sheetDescription) {
            sheetDescription.textContent = description;
          }
          sheetSections.forEach((section) => {
            section.hidden = section.dataset.sheet !== contentKey;
          });

          setPanelActive(sheetOverlay, true);
          updateBuilderSheetScrollLock();
          if (sheetCloseBtn) {
            sheetCloseBtn.focus();
          }

          if (
            contentKey === "sources" &&
            typeof window.aipkit_refreshSourcesSheet === "function"
          ) {
            window.aipkit_refreshSourcesSheet();
          }
          if (typeof window.aipkit_attachRangeValueHandlers === "function") {
            window.aipkit_attachRangeValueHandlers("#aipkit_builder_sheet");
          }
          updateWebGroundingVisibility();
          updateAudioVisibility();
          updateConversationVisibility();
          updatePopupSettingsVisibility();
        };

        const closeSheet = () => {
          setPanelActive(sheetOverlay, false);
          updateBuilderSheetScrollLock();
          if (sheetCloseResetTimer) {
            window.clearTimeout(sheetCloseResetTimer);
          }
          sheetCloseResetTimer = window.setTimeout(() => {
            if (!sheetOverlay.classList.contains("aipkit-active")) {
              delete sheetOverlay.dataset.activeSheet;
            }
            sheetCloseResetTimer = null;
          }, 220);
        };

        builder.addEventListener("click", (event) => {
          const trigger = event.target.closest(".aipkit_builder_sheet_trigger");
          if (!trigger) {
            return;
          }
          event.preventDefault();
          openSheet(trigger);
        });

        if (sheetCloseBtn) {
          sheetCloseBtn.addEventListener("click", closeSheet);
        }

        // The dimmed page and the sheet's own buttons (Done, Add source) close it.
        sheetOverlay.addEventListener("click", (event) => {
          if (event.target === sheetOverlay || event.target.closest("[data-aipkit-sheet-close]")) {
            closeSheet();
          }
        });

        // Escape closes the window on top first: a preview, editor or confirmation opened from the sheet.
        // Whether one was open is read before any of them closes on the same key.
        let windowAboveSheet = false;
        document.addEventListener("keydown", (event) => {
          windowAboveSheet = event.key === "Escape" && Boolean(document.querySelector(".aipkit-modal-overlay.aipkit-active"));
        }, true);
        document.addEventListener("keydown", (event) => {
          if (event.key === "Escape" && sheetOverlay.classList.contains("aipkit-active") && !windowAboveSheet) {
            closeSheet();
          }
        });

        builder.dataset.sheetBound = "1";
      }
    },
  };
}

export function bindChatbotDetailPanels() {
  const advancedDetailPanelClosers = new Map();
  const registerAdvancedDetailPanelCloser = (panel, closeFn) => {
    if (!panel || typeof closeFn !== "function") {
      return;
    }
    advancedDetailPanelClosers.set(panel, closeFn);
    return () => {
      if (advancedDetailPanelClosers.get(panel) === closeFn) advancedDetailPanelClosers.delete(panel);
    };
  };
  const closeOtherAdvancedDetailPanels = (panelToKeep = null) => {
    advancedDetailPanelClosers.forEach((closeFn, panel) => {
      if (!panel || panel === panelToKeep || !panel.isConnected) {
        return;
      }
      if (
        panelToKeep &&
        panelToKeep.isConnected &&
        (panel.contains(panelToKeep) || panelToKeep.contains(panel))
      ) {
        return;
      }
      closeFn();
    });
  };

  return {
    registerAdvancedDetailPanelCloser,
    closeOtherAdvancedDetailPanels,
  };
}

/** Route save feedback to the active editor overlay. */
export function createChatbotSaveFeedback({builder, isBotCurrentlyActive}) {
  const saveStatus = document.getElementById("aipkit_chatbot_global_save_status_container");
  const mainPanelOverlay = builder.querySelector("#aipkit_chatbot_main_overlay");
  const mainPanel = builder.querySelector(".aipkit_chatbot_core_panel");
  // The core panel holds every workspace tab, so one overlay covers whichever tab is saving.
  const setSettingsAccordionBusy = isBusy => {
    const nextState = Boolean(isBusy);
    if (mainPanelOverlay) {
      mainPanelOverlay.hidden = !nextState;
      mainPanelOverlay.setAttribute("aria-hidden", nextState ? "false" : "true");
    }
    if (mainPanel) {
      mainPanel.classList.toggle("aipkit-is-busy", nextState);
    }
  };
  window.aipkit_setChatbotAccordionBusy = isBusy => {
    setSettingsAccordionBusy(Boolean(isBusy));
  };
  const setSaveStatus = (text, type) => {
    const statusText = typeof text === "string" ? text : "";
    const statusType = typeof type === "string" ? type.trim() : "";
    const isLoadingState = statusType === "loading" || !statusType && /saving|syncing|creating|deleting|duplicating|resetting|loading|refreshing/i.test(statusText);
    setSettingsAccordionBusy(isLoadingState);
    if (!saveStatus) {
      return;
    }
    // Status text UI is intentionally disabled in favor of settings overlay.
        const siblingStatus = saveStatus.closest(".aipkit_model_status_slot")?.querySelector(".aipkit_model_sync_status");
    if (siblingStatus) {
      siblingStatus.textContent = "";
      siblingStatus.classList.remove("success", "error", "loading");
    }
    saveStatus.textContent = "";
    saveStatus.className = "aipkit_save_status_container";
  };
  const setSavedStatus = (response, fallback = "Saved") => {
    setSaveStatus(response.message || fallback, "success");
  };
  const setSaveErrorStatus = (error, fallback = "Failed to save.") => {
    setSaveStatus(`Error: ${error?.message || fallback}`, "error");
  };
  const handleActiveSaveError = (botId, error, fallback) => {
    if (!isBotCurrentlyActive(botId)) {
      return false;
    }
    setSaveErrorStatus(error, fallback);
    return true;
  };
  return {
    saveStatus,
    setSaveStatus,
    setSavedStatus,
    setSaveErrorStatus,
    handleActiveSaveError
  };
}

/** Mount feature panels and synchronize inline disclosure and overflow. */
export function createChatbotInlinePanels({builder, settingsPanel, startersPanel, consentPanel}) {
  const syncInlineSettingsPanelState = (panel, isOpen) => {
    if (!panel) {
      return;
    }
    const host = panel.closest("[data-aipkit-inline-settings-host]");
    if (!host) {
      return;
    }
    const shouldOpen = Boolean(isOpen);
    const row = host.closest("[data-aipkit-inline-settings-row]");
    if (shouldOpen) {
      host.hidden = false;
    }
    host.classList.toggle("is-open", shouldOpen);
    if (row) {
      row.classList.toggle("is-open", shouldOpen);
    }
    if (!shouldOpen) {
      host.hidden = true;
    }
  };
  const mountInlineSettingsPanel = panel => {
    if (!panel || !panel.id) {
      return;
    }
    const host = builder.querySelector(`[data-aipkit-inline-settings-host="${panel.id}"]`);
    if (!host || host.dataset.aipkitInlinePanelMounted === "1") {
      return;
    }
    host.appendChild(panel);
    panel.classList.add("aipkit_inline_settings_content");
    panel.classList.remove("is-open");
    panel.setAttribute("aria-hidden", "true");
    host.hidden = true;
    host.dataset.aipkitInlinePanelMounted = "1";
  };
  mountInlineSettingsPanel(startersPanel);
  mountInlineSettingsPanel(consentPanel);
  const mountInlineSettingsPanelForTrigger = (panel, trigger) => {
    if (!panel || !panel.id || !trigger) {
      return null;
    }
    const row = trigger.closest("[data-aipkit-inline-settings-row]");
    if (!row) {
      return null;
    }
    let host = row.querySelector(`[data-aipkit-inline-settings-host="${panel.id}"]`);
    if (!host) {
      host = document.createElement("div");
      host.className = "aipkit_interface_feature_inline_panel aipkit_tools_inline_panel";
      host.dataset.aipkitInlineSettingsHost = panel.id;
      host.hidden = true;
      row.appendChild(host);
    }
    const previousHost = panel.closest("[data-aipkit-inline-settings-host]");
    if (previousHost && previousHost !== host) {
      previousHost.classList.remove("is-open");
      previousHost.hidden = true;
      const previousRow = previousHost.closest("[data-aipkit-inline-settings-row]");
      if (previousRow) {
        previousRow.classList.remove("is-open");
      }
    }
    if (panel.parentElement !== host) {
      host.appendChild(panel);
    }
    panel.classList.add("aipkit_inline_settings_content");
    host.hidden = false;
    return host;
  };
  function syncSettingsPanelOverflowState() {
    if (!settingsPanel) {
      return;
    }
    const openDropdownSelector = [ ".aipkit_popover_multiselect.is-open", ".aipkit_settings_model_dropdown.is-open", ".aipkit_ai_provider_dropdown.is-open", ".aipkit_vector_provider_dropdown.is-open" ].join(", ");
    const hasOpenDropdown = Boolean(settingsPanel.querySelector(openDropdownSelector));
    settingsPanel.classList.toggle("aipkit_chatbot_settings_panel--allow-overflow", hasOpenDropdown);
  }
  return {
    syncInlineSettingsPanelState,
    mountInlineSettingsPanelForTrigger,
    syncSettingsPanelOverflowState
  };
}

/** Wire row shortcuts and their existing feature panels. */
export function bindChatbotSettingsRows({builder, modelPopoverPanel, conversation, appearance, registerAdvancedDetailPanelCloser}) {
  if (modelPopoverPanel && !builder.dataset.modelPopoverBound) {
    builder.addEventListener("click", event => {
      const row = event.target.closest("[data-aipkit-inline-settings-row]");
      if (!row) {
        return;
      }
      // Rows in a Features side panel keep their settings open while the feature is on.
      if (row.closest(".aipkit_feature_drawer") || event.target.closest("[data-aipkit-inline-settings-host], .aipkit_interface_feature_inline_panel, .aipkit_inline_settings_content")) {
        return;
      }
      if (event.target.closest("button, a, input, select, textarea, .aipkit_switch, .aipkit_settings_big_checkbox_box")) {
        return;
      }
      const toggle = row.querySelector("[data-aipkit-inline-settings-toggle]");
      if (!toggle || toggle.hidden || toggle.disabled) {
        return;
      }
      event.preventDefault();
      toggle.click();
    });
    conversation.bindToggles();
    appearance.bindModal(registerAdvancedDetailPanelCloser);
    conversation.bindOutsideClicks();
    builder.dataset.modelPopoverBound = "1";
  }
}

/** Chatbot workspace tabs: one settings group visible at a time, remembered for the browser session. */

const STORAGE_KEY = "aipkit_chatbot_workspace_tab";

const readStoredTab = () => {
  try {
    return window.sessionStorage.getItem(STORAGE_KEY) || "";
  } catch (error) {
    return "";
  }
};

const storeTab = (key) => {
  try {
    window.sessionStorage.setItem(STORAGE_KEY, key);
  } catch (error) {
    // Storage can be unavailable (private windows); the tab still switches.
  }
};

export function bindChatbotTabs(builder, { onShow } = {}) {
  const tabList = builder.querySelector("[data-aipkit-chatbot-tabs]");
  if (!tabList || builder.dataset.chatbotTabsBound) {
    return null;
  }
  builder.dataset.chatbotTabsBound = "1";

  const tabs = Array.from(tabList.querySelectorAll("[data-aipkit-chatbot-tab]"));
  const panels = new Map(
    Array.from(builder.querySelectorAll("[data-aipkit-chatbot-tab-panel]")).map(
      (panel) => [panel.dataset.aipkitChatbotTabPanel, panel]
    )
  );
  if (!tabs.length || !panels.size) {
    return null;
  }

  const select = (key, { focus = false, remember = true } = {}) => {
    const target = panels.has(key) ? key : tabs[0].dataset.aipkitChatbotTab;
    tabs.forEach((tab) => {
      const active = tab.dataset.aipkitChatbotTab === target;
      tab.classList.toggle("is-active", active);
      tab.setAttribute("aria-selected", active ? "true" : "false");
      tab.tabIndex = active ? 0 : -1;
      if (active && focus) {
        tab.focus();
      }
    });
    panels.forEach((panel, panelKey) => {
      panel.hidden = panelKey !== target;
    });
    if (remember) {
      storeTab(target);
    }
    if (typeof onShow === "function") {
      onShow(target);
    }
  };

  tabList.addEventListener("click", (event) => {
    const tab = event.target.closest("[data-aipkit-chatbot-tab]");
    if (!tab) {
      return;
    }
    event.preventDefault();
    select(tab.dataset.aipkitChatbotTab);
  });

  // Arrow keys, Home and End move between tabs (WAI-ARIA tabs pattern).
  tabList.addEventListener("keydown", (event) => {
    const current = tabs.indexOf(event.target.closest("[data-aipkit-chatbot-tab]"));
    if (current < 0) {
      return;
    }
    const moves = {
      ArrowRight: current + 1,
      ArrowLeft: current - 1,
      Home: 0,
      End: tabs.length - 1,
    };
    if (!(event.key in moves)) {
      return;
    }
    event.preventDefault();
    const next = (moves[event.key] + tabs.length) % tabs.length;
    select(tabs[next].dataset.aipkitChatbotTab, { focus: true });
  });

  select(readStoredTab(), { remember: false });
  return { select };
}

/**
 * Features and Look tabs: one row per feature, and its settings in a side panel beside the preview.
 * A panel shows the feature's switch; while it is on, the panel opens the feature's existing settings below it.
 */
export function bindChatbotFeatureDrawers(builder, options) {
  const bound = Array.from(builder.querySelectorAll("[data-aipkit-feature-panels]"))
    .map((root) => bindFeaturePanels(builder, root, options))
    .filter(Boolean);
  if (!bound.length) {
    return null;
  }
  return {
    close: (closeOptions) => bound.forEach((panels) => panels.close(closeOptions)),
    refresh: () => bound.forEach((panels) => panels.refresh()),
  };
}

function bindFeaturePanels(builder, root, { closeOtherAdvancedDetailPanels, __, _n, sprintf }) {
  if (root.dataset.featureDrawersBound) {
    return null;
  }
  root.dataset.featureDrawersBound = "1";
  const controller = new AbortController();
  const listen = (target, type, handler, options = {}) =>
    target.addEventListener(type, handler, { ...options, signal: controller.signal });
  const drawers = new Map(
    Array.from(root.querySelectorAll("[data-aipkit-feature-drawer]")).map((drawer) => [drawer.dataset.aipkitFeatureDrawer, drawer])
  );
  let active = null;
  let opener = null;
  let frame = null;

  const isShown = (node) => Boolean(node) && !node.hidden && node.style.display !== "none";
  // Switches in a panel whose row the current provider and model leave visible; a panel's own switch always counts.
  const visibleOptions = (drawer) => Array.from(
    drawer?.querySelectorAll(".aipkit_tools_enabled_option, .aipkit_interface_control_option, [data-aipkit-feature-switch]") || []
  ).filter((option) => option.matches("[data-aipkit-feature-switch]") ||
    isShown(option.closest(".aipkit_tools_feature_row, .aipkit_interface_feature_row, .aipkit_voice_option_head")));

  const featureState = (key) => {
    // A panel whose state is a choice (Limits by role) marks the field and the value that means On.
    const onField = drawers.get(key)?.querySelector("[data-aipkit-feature-on-value]");
    if (onField) {
      return onField.value === onField.dataset.aipkitFeatureOnValue ? "on" : "off";
    }
    const options = visibleOptions(drawers.get(key));
    if (key === "web" && !options.some((option) => !option.disabled)) {
      return "unavailable";
    }
    return options.some((option) => option.checked) ? "on" : "off";
  };

  // The line under the instructions: preset rows the current model uses (Thinking hides for models that do not
  // think), short enough for one line. Preset names differ per row (Balanced, Medium), so they stand alone; switches stay out.
  const answerSummary = () => Array.from(drawers.get("answer")?.querySelectorAll(".aipkit_answer_style_row") || [])
    .filter((row) => row.querySelector("[data-aipkit-segmented-for]") && isShown(row) && !row.classList.contains("aipkit_hidden"))
    .map((row) => {
      const pressed = row.querySelector('.aipkit_segmented_option[aria-pressed="true"]');
      const fixed = row.querySelector(".aipkit_segmented_fixed");
      const field = row.querySelector("[name]");
      const custom = !fixed && (!pressed || pressed.hasAttribute("data-custom"));
      const label = fixed ? fixed.dataset.label : pressed?.textContent.trim() || "";
      const number = Number(field?.value) || 0;
      switch (field?.name) {
        case "temperature":
          /* translators: %s: a custom creativity (temperature), such as 0.7. */
          return custom ? sprintf(__("Creativity %s", "gpt3-ai-content-generator"), field.value) : label;
        case "max_completion_tokens":
          return custom ? __("Custom length", "gpt3-ai-content-generator") : label;
        case "reasoning_effort":
          /* translators: %s: how long it thinks, such as Off or High. */
          return label ? sprintf(__("Thinking: %s", "gpt3-ai-content-generator"), label) : "";
        case "max_messages":
          /* translators: %s: number of chat messages it reads back before answering. */
          return number ? sprintf(_n("%s message", "%s messages", number, "gpt3-ai-content-generator"), number.toLocaleString()) : "";
        default:
          return label;
      }
    })
    .filter(Boolean)
    .join(" · ");

  const startersHint = () => {
    const field = builder.querySelector('textarea[name="conversation_starters"]');
    const count = field ? field.value.split("\n").filter((line) => line.trim()).length : 0;
    /* translators: %d: number of suggested questions. */
    return count ? sprintf(_n("%d question visitors can tap", "%d questions visitors can tap", count, "gpt3-ai-content-generator"), count) : "";
  };

  // Welcome bubble: its message, then when and how often it shows.
  const bubbleHint = () => {
    const drawer = drawers.get("bubble");
    const text = drawer?.querySelector('input[name="popup_label_text"]')?.value.trim() || "";
    const timing = ["popup_label_mode", "popup_label_frequency"]
      .map((name) => drawer?.querySelector(`select[name="${name}"]`)?.selectedOptions[0]?.textContent.trim() || "");
    /* translators: %s: the welcome bubble's message. */
    return [text ? sprintf(__("“%s”", "gpt3-ai-content-generator"), text) : "", ...timing].filter(Boolean).join(" · ");
  };

  // Size and font: the custom theme's font, corners and width; built-in colors keep their own.
  const sizeHint = () => {
    const panel = root.querySelector("#aipkit_custom_theme_modal");
    const theme = builder.querySelector('select[name="theme"]');
    if (!panel || !theme) {
      return "";
    }
    if (theme.value !== "custom") {
      /* translators: %s: a built-in color theme, such as Dark. */
      return sprintf(__("%s keeps its own font and size", "gpt3-ai-content-generator"), theme.selectedOptions[0]?.textContent.trim() || "");
    }
    const font = panel.querySelector('select[name="custom_theme_settings[font_family]"]')?.selectedOptions[0]?.textContent.trim() || "";
    const corners = panel.querySelector('input[name="custom_theme_settings[bubble_border_radius]"]')?.value;
    // Floating chats show the popup width and chats inside a page the page width; the other row is hidden.
    const width = Array.from(panel.querySelectorAll('input[name$="_width]"]'))
      .find((input) => !input.closest(".aipkit_answer_style_row")?.hidden)?.value;
    return [
      font,
      /* translators: %s: corner radius in pixels. */
      corners !== undefined ? sprintf(__("%s px corners", "gpt3-ai-content-generator"), corners) : "",
      /* translators: %s: chat window width in pixels. */
      width ? sprintf(__("%s px wide", "gpt3-ai-content-generator"), width) : "",
    ].filter(Boolean).join(" · ");
  };

  // When someone reaches a usage limit: the message, and the button or buttons it offers.
  const limitHint = () => {
    const drawer = drawers.get("limit");
    if (!drawer) {
      return "";
    }
    const rows = Array.from(drawer.querySelectorAll("[data-aipkit-limit-action-row]"))
      .filter((row) => row.querySelector('select[name$="_action_type"]')?.value !== "none");
    if (rows.length > 1) {
      return __("A message and two buttons", "gpt3-ai-content-generator");
    }
    if (rows.length === 1) {
      const input = rows[0].querySelector('input[name$="_action_label"]');
      /* translators: %s: the button's label, such as Buy credits. */
      return sprintf(__("A message and a button: “%s”", "gpt3-ai-content-generator"), input?.value.trim() || input?.placeholder || "");
    }
    return __("A message only", "gpt3-ai-content-generator");
  };

  const STATE_LABELS = {
    on: __("On", "gpt3-ai-content-generator"),
    off: __("Off", "gpt3-ai-content-generator"),
    unavailable: __("Not available", "gpt3-ai-content-generator"),
  };

  const render = () => {
    root.querySelectorAll("[data-aipkit-feature-row]").forEach((row) => {
      const key = row.dataset.aipkitFeatureRow;
      const hint = row.querySelector("[data-aipkit-feature-hint]");
      if (hint && hint.dataset.defaultHint === undefined) {
        hint.dataset.defaultHint = hint.textContent.trim();
      }
      if (key === "answer" && hint) {
        hint.textContent = answerSummary() || hint.dataset.defaultHint;
      }
      if (key === "size" && hint) {
        hint.textContent = sizeHint() || hint.dataset.defaultHint;
      }
      if (key === "limit" && hint) {
        hint.textContent = limitHint() || hint.dataset.defaultHint;
      }
      const stateNode = row.querySelector("[data-aipkit-feature-state]");
      if (!stateNode) {
        return;
      }
      const state = featureState(key);
      stateNode.dataset.state = state;
      stateNode.textContent = STATE_LABELS[state];
      if (key === "bubble" && hint) {
        hint.textContent = state === "on"
          ? bubbleHint() || hint.dataset.defaultHint
          : __("Off. Visitors only see the chat button.", "gpt3-ai-content-generator");
      }
      if (key === "starters" && hint) {
        hint.textContent = (state === "on" && startersHint()) || hint.dataset.defaultHint;
      }
      if (key === "web" && hint) {
        // A provider without web search has no switch to show, so the row stays closed.
        const hasSwitch = visibleOptions(drawers.get("web")).length > 0;
        const button = row.querySelector("[data-aipkit-feature-open]");
        if (button) {
          button.disabled = !hasSwitch;
        }
        hint.textContent = hasSwitch
          ? hint.dataset.defaultHint
          : __("Not available with this AI provider.", "gpt3-ai-content-generator");
      }
    });
  };

  // While the feature is on, open its settings under the switch; when it turns off, close them.
  const syncSettings = (drawer) => {
    if (!drawer || drawer.hidden) {
      return;
    }
    const triggers = Array.from(drawer.querySelectorAll("[data-aipkit-inline-settings-toggle]")).filter((trigger) =>
      isShown(trigger.closest("[data-aipkit-inline-settings-row]")) && trigger.style.display !== "none"
    );
    const ready = triggers.find((trigger) => !trigger.disabled);
    if (ready) {
      if (ready.getAttribute("aria-expanded") !== "true") {
        ready.click();
      }
    } else if (triggers.some((trigger) => trigger.getAttribute("aria-expanded") === "true")) {
      closeOtherAdvancedDetailPanels();
    }
  };

  const refresh = () => {
    if (frame !== null) {
      return;
    }
    frame = window.requestAnimationFrame(() => {
      frame = null;
      if (!root.isConnected) {
        return;
      }
      render();
      syncSettings(active);
    });
  };

  // Picker ownership stays with its trigger even when the dialog is portaled to body.
  const dismissPickers = (drawer, focus) => {
    let dismissed = false;
    drawer?.querySelectorAll('[data-aipkit-unified-model-bound], [data-aipkit-knowledge-picker]').forEach(root => {
      const picker = root._aipkitUnifiedModelController || root._aipkitKnowledgePicker;
      if (!picker?.popover || picker.popover.hidden) return;
      picker.close({ focus });
      if (focus) root.querySelector('[aria-haspopup="dialog"]')?.focus();
      dismissed = true;
    });
    return dismissed;
  };

  const close = ({ restoreFocus = true } = {}) => {
    if (!active) {
      return;
    }
    const drawer = active;
    dismissPickers(drawer, false);
    active = null;
    closeOtherAdvancedDetailPanels();
    drawer.hidden = true;
    if (opener) {
      opener.setAttribute("aria-expanded", "false");
      if (restoreFocus && opener.isConnected) {
        opener.focus({ preventScroll: true });
      }
    }
    opener = null;
    refresh();
  };

  const open = (key, trigger) => {
    const drawer = drawers.get(key);
    if (!drawer) {
      return;
    }
    if (active) {
      close({ restoreFocus: false });
    }
    closeOtherAdvancedDetailPanels();
    drawer.hidden = false;
    active = drawer;
    opener = trigger || null;
    opener?.setAttribute("aria-expanded", "true");
    if (typeof window.aipkit_attachRangeValueHandlers === "function") {
      window.aipkit_attachRangeValueHandlers("#aipkit_chatbot_settings_panel");
    }
    syncSettings(drawer);
    // Panels that draw their contents on demand (Rules, from the paid add-on) start here.
    drawer.dispatchEvent(new CustomEvent("aipkit:feature-drawer-open", { bubbles: true, detail: { key } }));
    drawer.focus({ preventScroll: true });
  };

  listen(root, "click", (event) => {
    const trigger = event.target.closest("[data-aipkit-feature-open]");
    if (trigger && !trigger.disabled) {
      event.preventDefault();
      open(trigger.dataset.aipkitFeatureOpen, trigger);
      return;
    }
    if (event.target.closest("[data-aipkit-feature-close]")) {
      event.preventDefault();
      close();
    }
  });
  // Portaled model pickers, media dialogs and confirmations (with their backdrops) remain usable above the drawer.
  const inPortal = target => !builder.contains(target) &&
    Boolean(target.closest?.('[role="dialog"]') || target.querySelector?.(':scope > [role="dialog"][aria-modal="true"]'));
  const focusable = () => Array.from(active?.querySelectorAll('button, input, select, textarea, a[href], [tabindex]') || [])
    .filter(node => node.tabIndex >= 0 && !node.matches(':disabled') && !node.closest('[hidden], [inert]') && node.getClientRects().length);
  // The dimmed page around an open panel only closes it; the click does not reach what is underneath.
  // Capture runs before any handler can re-render the clicked node out of the panel.
  listen(document, "click", (event) => {
    if (!root.isConnected || !active || active.contains(event.target) || inPortal(event.target)) {
      return;
    }
    event.preventDefault();
    event.stopPropagation();
    close();
  }, { capture: true });
  // Escape from inside the panel closes it before page-wide handlers (the preview widget focuses its launcher).
  // Popovers opened from the panel live outside it and handle their own Escape.
  listen(document, "keydown", (event) => {
    const fromPanel = root.isConnected && active && (active.contains(event.target) || event.target === document.body);
    if (fromPanel && event.key === "Escape" && !event.defaultPrevented) {
      event.preventDefault();
      event.stopPropagation();
      if (!dismissPickers(active, true)) {
        close();
      }
    } else if (fromPanel && event.key === "Tab") {
      const controls = focusable();
      const first = controls[0], last = controls[controls.length - 1];
      if (!first || (event.shiftKey && (event.target === first || event.target === active)) ||
          (!event.shiftKey && event.target === last)) {
        event.preventDefault();
        (event.shiftKey ? last : first)?.focus();
      }
    }
  }, { capture: true });
  // Tabbing past the panel's last control returns to the panel rather than the dimmed page.
  listen(document, "focusin", (event) => {
    if (root.isConnected && active && !active.contains(event.target) && !inPortal(event.target)) {
      active.focus({ preventScroll: true });
    }
  });
  listen(builder, "change", refresh);
  listen(builder, "input", refresh);
  // Another chatbot's values arrive without change events.
  listen(builder, "aipkit:bot-state-applied", () => {
    close({ restoreFocus: false });
    refresh();
  });

  const dispose = () => {
    controller.abort();
    removal.disconnect();
    if (frame !== null) {
      window.cancelAnimationFrame(frame);
      frame = null;
    }
    delete root.dataset.featureDrawersBound;
  };
  const removal = new MutationObserver(() => {
    if (!root.isConnected) dispose();
  });
  removal.observe(root.ownerDocument.documentElement, { childList: true, subtree: true });

  render();
  return { close, refresh };
}
