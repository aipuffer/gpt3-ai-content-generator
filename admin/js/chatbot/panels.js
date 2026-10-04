/** Shared Chatbot settings shells and disclosure state; inert until setup. */

export function createChatbotPanels({
  builder,
  sheetOverlay,
  advancedDrawer,
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
  const sheetPlaceholder = sheetOverlay
    ? sheetOverlay.querySelector('.aipkit_builder_sheet_section[data-sheet="placeholder"]')
    : null;
  const sheetCloseBtn = sheetOverlay
    ? sheetOverlay.querySelector(".aipkit_builder_sheet_close")
    : null;
  const advancedDrawerOpenBtn = builder.querySelector(
    "[data-aipkit-advanced-drawer-open]"
  );
  const advancedDrawerPanel = advancedDrawer
    ? advancedDrawer.querySelector(".aipkit_chatbot_advanced_panel")
    : null;
  const advancedDrawerCloseBtn = advancedDrawer
    ? advancedDrawer.querySelector("[data-aipkit-advanced-drawer-close]")
    : null;
  const updateBuilderSheetScrollLock = () => {
    const shouldLock = Boolean(
      (sheetOverlay && sheetOverlay.classList.contains("aipkit-active")) ||
        (advancedDrawer && advancedDrawer.classList.contains("aipkit-active")) ||
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
    bindDrawer({
      updateWebGroundingVisibility,
      updateAudioVisibility,
      updateConversationVisibility,
      updatePopupSettingsVisibility,
      updateContextPopoverControls,
    }) {
      if (
        advancedDrawer &&
        advancedDrawerPanel &&
        advancedDrawerOpenBtn &&
        !builder.dataset.advancedDrawerBound
      ) {
        let advancedDrawerReturnFocus = null;
        const focusableSelector = [
          'a[href]',
          'button:not([disabled])',
          'textarea:not([disabled])',
          'input:not([disabled]):not([type="hidden"])',
          'select:not([disabled])',
          '[tabindex]:not([tabindex="-1"])',
        ].join(", ");

        const isAdvancedDrawerOpen = () =>
          advancedDrawer.classList.contains("aipkit-active");

        const getAdvancedDrawerFocusable = () =>
          Array.from(advancedDrawerPanel.querySelectorAll(focusableSelector)).filter(
            (element) =>
              element instanceof HTMLElement &&
              !element.hidden &&
              element.getAttribute("aria-hidden") !== "true" &&
              Boolean(element.offsetParent)
          );

        const openAdvancedDrawer = () => {
          advancedDrawerReturnFocus = document.activeElement;
          setPanelActive(advancedDrawer, true);
          advancedDrawerOpenBtn.setAttribute("aria-expanded", "true");
          updateBuilderSheetScrollLock();

          if (typeof window.aipkit_attachRangeValueHandlers === "function") {
            window.aipkit_attachRangeValueHandlers("#aipkit_chatbot_settings_panel");
          }
          updateWebGroundingVisibility();
          updateAudioVisibility();
          updateConversationVisibility();
          updatePopupSettingsVisibility();
          updateContextPopoverControls();

          window.requestAnimationFrame(() => {
            const focusTarget =
              advancedDrawerCloseBtn || getAdvancedDrawerFocusable()[0];
            if (focusTarget && typeof focusTarget.focus === "function") {
              focusTarget.focus();
            }
          });
        };

        const closeAdvancedDrawer = () => {
          if (!isAdvancedDrawerOpen()) {
            return;
          }
          advancedDrawer
            .querySelectorAll(".aipkit_unified_model_selector.is-open")
            .forEach((selector) =>
              selector._aipkitUnifiedModelController?.close()
            );
          setPanelActive(advancedDrawer, false);
          advancedDrawerOpenBtn.setAttribute("aria-expanded", "false");
          updateBuilderSheetScrollLock();
          if (
            advancedDrawerReturnFocus &&
            typeof advancedDrawerReturnFocus.focus === "function" &&
            document.contains(advancedDrawerReturnFocus)
          ) {
            advancedDrawerReturnFocus.focus();
          }
          advancedDrawerReturnFocus = null;
        };

        advancedDrawerOpenBtn.addEventListener("click", (event) => {
          event.preventDefault();
          openAdvancedDrawer();
        });

        if (advancedDrawerCloseBtn) {
          advancedDrawerCloseBtn.addEventListener("click", closeAdvancedDrawer);
        }

        advancedDrawer.addEventListener("click", (event) => {
          if (event.target === advancedDrawer) {
            closeAdvancedDrawer();
          }
        });

        document.addEventListener("keydown", (event) => {
          if (!isAdvancedDrawerOpen()) {
            return;
          }
          if (sheetOverlay && sheetOverlay.classList.contains("aipkit-active")) {
            return;
          }
          if (event.key === "Escape") {
            closeAdvancedDrawer();
            return;
          }
          if (event.key !== "Tab") {
            return;
          }
          const focusable = getAdvancedDrawerFocusable();
          if (!focusable.length) {
            event.preventDefault();
            return;
          }
          const first = focusable[0];
          const last = focusable[focusable.length - 1];
          if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
          } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
          }
        });

        builder.dataset.advancedDrawerBound = "1";
      }
    },
    bindSheet({
      getTriggersStatus,
      getSelectedBuilderBotId,
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

          if (sheetTitle) {
            sheetTitle.textContent = title;
          }
          if (sheetDescription) {
            sheetDescription.textContent = description;
          }
          if (sheetSections.length) {
            let hasMatch = false;
            sheetSections.forEach((section) => {
              const match = section.dataset.sheet === contentKey;
              section.hidden = !match;
              if (match) {
                hasMatch = true;
              }
            });
            if (!hasMatch && sheetPlaceholder) {
              sheetPlaceholder.hidden = false;
            }
          }

          const triggersStatus = getTriggersStatus();
          if (triggersStatus) {
            triggersStatus.textContent = "";
            triggersStatus.classList.remove("success", "error", "loading");
            triggersStatus.style.display = "none";
          }

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
          if (
            contentKey === "triggers" &&
            typeof window.aipkit_initTriggerBuilderUI === "function"
          ) {
            const botId =
              getSelectedBuilderBotId();
            if (botId && botId !== "__new__") {
              window.aipkit_initTriggerBuilderUI(botId);
            }
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

        sheetOverlay.addEventListener("click", (event) => {
          if (event.target === sheetOverlay) {
            closeSheet();
          }
        });

        document.addEventListener("keydown", (event) => {
          if (event.key === "Escape" && sheetOverlay.classList.contains("aipkit-active")) {
            closeSheet();
          }
        });

        builder.dataset.sheetBound = "1";
      }
    },
  };
}

export function bindChatbotDetailPanels(builder) {
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

  const syncStaticInlineSettingsRow = (row, isOpen) => {
    if (!row) {
      return;
    }
    const panel = row.querySelector(".aipkit_interface_feature_inline_panel");
    const toggle = row.querySelector("[data-aipkit-static-inline-settings-toggle]");
    if (!panel) {
      return;
    }
    const shouldOpen = Boolean(isOpen);
    if (shouldOpen) {
      panel.hidden = false;
    }
    panel.classList.toggle("is-open", shouldOpen);
    row.classList.toggle("is-open", shouldOpen);
    if (toggle) {
      toggle.setAttribute("aria-expanded", shouldOpen ? "true" : "false");
    }
    if (!shouldOpen) {
      panel.hidden = true;
    }
  };

  const closeStaticInlineSettingsRow = (row) => {
    syncStaticInlineSettingsRow(row, false);
  };

  builder
    .querySelectorAll("[data-aipkit-static-inline-settings-row]")
    .forEach((row) => {
      registerAdvancedDetailPanelCloser(row, () =>
        closeStaticInlineSettingsRow(row)
      );
    });

  builder.addEventListener("click", (event) => {
    const toggle = event.target.closest(
      "[data-aipkit-static-inline-settings-toggle]"
    );
    if (!toggle || toggle.disabled || toggle.hidden) {
      return;
    }
    const row = toggle.closest("[data-aipkit-static-inline-settings-row]");
    if (!row) {
      return;
    }
    event.preventDefault();
    const isOpen = !row.classList.contains("is-open");
    if (isOpen) {
      closeOtherAdvancedDetailPanels(row);
    }
    syncStaticInlineSettingsRow(row, isOpen);
  });

  return {
    registerAdvancedDetailPanelCloser,
    closeOtherAdvancedDetailPanels,
    closeStaticInlineSettingsRow,
  };
}

/** Route save feedback to the active editor overlay. */
export function createChatbotSaveFeedback({builder, settingsPanel, advancedDrawer, isBotCurrentlyActive}) {
  const saveStatus = document.getElementById("aipkit_chatbot_global_save_status_container");
  const mainPanelOverlay = builder.querySelector("#aipkit_chatbot_main_overlay");
  const mainPanel = builder.querySelector(".aipkit_chatbot_core_panel");
  const settingsPanelOverlay = builder.querySelector("#aipkit_chatbot_settings_overlay");
  const setSettingsAccordionBusy = isBusy => {
    const nextState = Boolean(isBusy);
    const shouldUseSettingsOverlay = Boolean(nextState && advancedDrawer && advancedDrawer.classList.contains("aipkit-active"));
    const setOverlayState = (overlay, active) => {
      if (!overlay) {
        return;
      }
      overlay.hidden = !active;
      overlay.setAttribute("aria-hidden", active ? "false" : "true");
    };
    setOverlayState(settingsPanelOverlay, nextState && shouldUseSettingsOverlay);
    setOverlayState(mainPanelOverlay, nextState && !shouldUseSettingsOverlay);
    if (mainPanel) {
      mainPanel.classList.toggle("aipkit-is-busy", nextState && !shouldUseSettingsOverlay);
    }
    if (settingsPanel) {
      settingsPanel.classList.toggle("aipkit-is-busy", nextState && shouldUseSettingsOverlay);
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
      if (event.target.closest("[data-aipkit-inline-settings-host], .aipkit_interface_feature_inline_panel, .aipkit_inline_settings_content")) {
        return;
      }
      if (event.target.closest("button, a, input, select, textarea, .aipkit_settings_big_checkbox_box")) {
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
