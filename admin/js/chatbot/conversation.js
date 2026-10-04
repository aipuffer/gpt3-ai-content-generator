/** Starter questions and the shared consent-settings panel/gate. */
export function createChatbotConversation({
  builder,
  modelPopoverPanel,
  startersPanel,
  consentPanel,
  syncInlineSettingsPanelState,
  registerAdvancedDetailPanelCloser,
  closeOtherAdvancedDetailPanels,
}) {
  const starters = {
    panel: startersPanel,
    id: "aipkit_starters_panel",
    triggerSelector: ".aipkit_starters_config_btn",
    activeTrigger: null,
  };
  const consent = {
    panel: consentPanel,
    id: "aipkit_consent_panel",
    triggerSelector: ".aipkit_consent_config_btn",
    activeTrigger: null,
  };
  const panels = [starters, consent];

  const closePanel = (state) => {
    if (!state.panel) {
      return;
    }
    state.panel.classList.remove("is-open");
    state.panel.setAttribute("aria-hidden", "true");
    syncInlineSettingsPanelState(state.panel, false);
    if (state.activeTrigger) {
      state.activeTrigger.setAttribute("aria-expanded", "false");
      state.activeTrigger = null;
    }
  };
  const closeStartersPanel = () => closePanel(starters);
  registerAdvancedDetailPanelCloser(startersPanel, closeStartersPanel);
  registerAdvancedDetailPanelCloser(consentPanel, () => closePanel(consent));

  const updateConsentControls = () => {
    const interfaceControlsRoot = modelPopoverPanel
      ? modelPopoverPanel.querySelector("[data-aipkit-interface-controls]")
      : builder.querySelector("[data-aipkit-interface-controls]");
    const controlsPanel =
      (interfaceControlsRoot &&
        interfaceControlsRoot.closest(".aipkit_settings_panel_body")) ||
      (modelPopoverPanel
        ? modelPopoverPanel.querySelector('[data-aipkit-settings-panel="appearance"]')
        : builder.querySelector('[data-aipkit-settings-panel="appearance"]'));
    if (!controlsPanel) {
      return;
    }
    const consentToggle = controlsPanel.querySelector(
      '[name="enable_consent_compliance"]'
    );
    const consentConfigBtn = controlsPanel.querySelector(consent.triggerSelector);
    const showConsentFields = consentToggle
      ? consentToggle.tagName === "SELECT"
        ? consentToggle.value === "1"
        : consentToggle.checked
      : false;
    if (consentConfigBtn) {
      consentConfigBtn.hidden = false;
      consentConfigBtn.disabled = !showConsentFields;
      consentConfigBtn.setAttribute(
        "aria-disabled",
        showConsentFields ? "false" : "true"
      );
      if (!showConsentFields) {
        consentConfigBtn.setAttribute("aria-expanded", "false");
        closePanel(consent);
      }
    }
  };

  const bindToggles = () => {
    panels.forEach((state) => {
      builder.addEventListener("click", (event) => {
        const trigger = event.target.closest(state.triggerSelector);
        if (!trigger) {
          return;
        }
        event.preventDefault();
        if (!state.panel) {
          return;
        }
        const isOpen = !state.panel.classList.contains("is-open");
        if (isOpen) {
          closeOtherAdvancedDetailPanels(state.panel);
        }
        state.panel.classList.toggle("is-open", isOpen);
        state.panel.setAttribute("aria-hidden", isOpen ? "false" : "true");
        syncInlineSettingsPanelState(state.panel, isOpen);
        if (state.activeTrigger && state.activeTrigger !== trigger) {
          state.activeTrigger.setAttribute("aria-expanded", "false");
        }
        trigger.setAttribute("aria-expanded", isOpen ? "true" : "false");
        state.activeTrigger = isOpen ? trigger : null;
      });
    });
  };

  const bindOutsideClicks = () => {
    panels.forEach((state) => {
      document.addEventListener("click", (event) => {
        if (!state.panel || !state.panel.classList.contains("is-open")) {
          return;
        }
        if (state.panel.classList.contains("aipkit_inline_settings_content")) {
          return;
        }
        if (
          event.target.closest(state.triggerSelector) ||
          event.target.closest(`[data-aipkit-inline-settings-target="${state.id}"]`) ||
          event.target.closest(`#${state.id}`)
        ) {
          return;
        }
        closePanel(state);
      });
    });
  };


  return {
    closeStartersPanel,
    updateConsentControls,
    bindToggles,
    bindOutsideClicks,
  };
}
