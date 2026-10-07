import { bindChatbotSettingsAutosave } from "./state.js";

/** Deployment configuration; external embedding execution remains in lib. */

export function bindChatbotDeployment({
  builder,
  saveStatus,
  popupOnlyControls,
  syncSettingsSectionsUiState,
  syncInterfaceControlsUiState,
  updateAudioVisibility,
  persistence,
}) {
  if (!saveStatus || !builder.isConnected || builder.dataset.deployWizardBound) return null;
  const topModeSelect = builder.querySelector("[data-aipkit-top-mode-select]");
  const popupToggle = builder.querySelector("[data-aipkit-popup-toggle]");
  const popupEnabledInput = builder.querySelector(
    "[data-aipkit-popup-enabled-input]"
  );
  const siteWideToggle = builder.querySelector(
    "[data-aipkit-site-wide-toggle]"
  );
  const siteWideEnabledInput = builder.querySelector(
    "[data-aipkit-site-wide-enabled-input]"
  );
  const embedAllowedDomainsField = builder.querySelector(
    'textarea[name="embed_allowed_domains"]'
  );
  // Allowed websites: a warning while any website may use the code, a short all-clear once one is listed.
  const embedDomainsNotes = Array.from(builder.querySelectorAll("[data-aipkit-embed-domains-note]"));
  const syncEmbedDomainsNote = () => {
    const listed = Boolean(embedAllowedDomainsField?.value.trim());
    embedDomainsNotes.forEach((note) => {
      note.hidden = note.dataset.aipkitEmbedDomainsNote !== (listed ? "listed" : "open");
    });
  };
  const popupScopeSelect = builder.querySelector(
    'select[name="aipkit_deploy_popup_scope"]'
  );
  const popupScopeRows = Array.from(
    builder.querySelectorAll(
      ".aipkit_interface_popup_scope_row, .aipkit_builder_popup_scope_row"
    )
  );

  if (!topModeSelect && !popupToggle && !siteWideToggle && !popupScopeSelect) return null;

  const normalizeTopDeployMode = (value) => {
    if (value === "popup" || value === "external") {
      return value;
    }
    return "inline";
  };

  const setTopDeployModeValue = (value) => {
    if (!topModeSelect) {
      return;
    }
    topModeSelect.value = normalizeTopDeployMode(value);
  };

  const setExternalPopupEnabledPreference = (value) => {
    if (!topModeSelect) {
      return;
    }
    topModeSelect.dataset.aipkitExternalPopupEnabled =
      value === "1" ? "1" : "0";
  };

  if (topModeSelect) {
    setTopDeployModeValue(topModeSelect.value);
    setExternalPopupEnabledPreference(
      topModeSelect.dataset.aipkitExternalPopupEnabled === "1" ? "1" : "0"
    );
  }

  const getDeployMode = () => {
    if (topModeSelect) {
      return normalizeTopDeployMode(topModeSelect.value);
    }
    // Without the mode field, the saved chat-button setting decides.
    const popupEnabled = popupToggle ? popupToggle.checked : popupEnabledInput?.value === "1";
    return popupEnabled ? "popup" : "inline";
  };

  const getPopupScope = () => {
    if (popupScopeSelect) {
      return popupScopeSelect.value === "sitewide" ? "sitewide" : "page";
    }
    if (siteWideToggle) {
      return siteWideToggle.checked ? "sitewide" : "page";
    }
    if (siteWideEnabledInput) {
      return siteWideEnabledInput.value === "1" ? "sitewide" : "page";
    }
    return "page";
  };

  const setPopupEnabledValue = (isEnabled) => {
    const enabled = Boolean(isEnabled);
    if (popupToggle) {
      popupToggle.checked = enabled;
    }
    if (popupEnabledInput) {
      popupEnabledInput.value = enabled ? "1" : "0";
    }
  };

  const setSiteWideEnabledValue = (isEnabled) => {
    const enabled = Boolean(isEnabled);
    if (siteWideToggle) {
      siteWideToggle.checked = enabled;
    }
    if (siteWideEnabledInput) {
      siteWideEnabledInput.value = enabled ? "1" : "0";
    }
    if (popupScopeSelect) {
      popupScopeSelect.value = enabled ? "sitewide" : "page";
    }
  };

  const setSidebarToggleState = (isPopupEnabled) => {
    const sidebarGroup = builder.querySelector(
      ".aipkit_sidebar_toggle_group"
    );
    if (!sidebarGroup) {
      return;
    }
    const sidebarCheckbox = sidebarGroup.querySelector(
      ".aipkit_sidebar_toggle_switch"
    );
    const sidebarLabel = sidebarGroup.querySelector(
      ".aipkit_interface_toggle_label"
    );
    if (!sidebarCheckbox) {
      return;
    }

    if (isPopupEnabled) {
      if (sidebarCheckbox.getAttribute("data-original-state") === null) {
        const originalState =
          sidebarCheckbox.tagName === "SELECT"
            ? sidebarCheckbox.value === "1"
              ? "checked"
              : "unchecked"
            : sidebarCheckbox.checked
              ? "checked"
              : "unchecked";
        sidebarCheckbox.setAttribute("data-original-state", originalState);
      }
      if (sidebarCheckbox.tagName === "SELECT") {
        sidebarCheckbox.value = "0";
      } else {
        sidebarCheckbox.checked = false;
      }
      sidebarCheckbox.disabled = true;
      if (sidebarLabel) {
        sidebarLabel.style.opacity = "0.6";
      }
    } else {
      sidebarCheckbox.disabled = false;
      if (sidebarLabel) {
        sidebarLabel.style.opacity = "1";
      }
      const originalState = sidebarCheckbox.getAttribute(
        "data-original-state"
      );
      if (originalState === "checked") {
        if (sidebarCheckbox.tagName === "SELECT") {
          sidebarCheckbox.value = "1";
        } else {
          sidebarCheckbox.checked = true;
        }
      }
      sidebarCheckbox.removeAttribute("data-original-state");
    }
  };

  const updateDeployUI = (syncSections = true) => {
    syncEmbedDomainsNote();
    const deployMode = getDeployMode();
    const isExternalMode = deployMode === "external";
    const isPopupEnabled = deployMode === "popup" || isExternalMode;
    const isLocalPopupMode = deployMode === "popup";

    setPopupEnabledValue(isPopupEnabled);
    if (!isLocalPopupMode) {
      setSiteWideEnabledValue(false);
    } else if (siteWideEnabledInput) {
      siteWideEnabledInput.value = getPopupScope() === "sitewide" ? "1" : "0";
    }

    popupScopeRows.forEach((row) => {
      row.hidden = row.classList.contains("aipkit_builder_popup_scope_row")
        ? false
        : !isLocalPopupMode;
    });
    popupOnlyControls.forEach((control) => {
      control.hidden = !isPopupEnabled;
    });
    // Settings only for a chat inside a page (its width in Size and font) show only then.
    builder.querySelectorAll("[data-aipkit-inline-only-control]").forEach((control) => {
      control.hidden = isPopupEnabled;
    });

    if (syncSections && typeof syncSettingsSectionsUiState === "function") {
      syncSettingsSectionsUiState();
    }

    if (popupScopeSelect) {
      popupScopeSelect.disabled = !isLocalPopupMode;
    }

    if (siteWideToggle) {
      siteWideToggle.disabled = !isLocalPopupMode;
    }

    setSidebarToggleState(isPopupEnabled);
    if (typeof syncInterfaceControlsUiState === "function") {
      syncInterfaceControlsUiState();
    }
    updateAudioVisibility();
  };

  const getDeploySettings = () => {
    const deployMode = getDeployMode();
    const popupEnabled = deployMode === "inline" ? "0" : "1";
    const siteWideEnabled =
      deployMode === "popup" && getPopupScope() === "sitewide" ? "1" : "0";
    const settings = {
      deploy_mode: deployMode,
      popup_enabled: popupEnabled,
      site_wide_enabled: siteWideEnabled,
    };

    if (embedAllowedDomainsField) {
      settings.embed_allowed_domains = embedAllowedDomainsField.value || "";
    }

    return settings;
  };

  let domainsSaveTimeout;
  const cancelDomainsSave = () => {
    clearTimeout(domainsSaveTimeout);
    domainsSaveTimeout = null;
  };
  const restoreDeploySettings = settings => {
    setTopDeployModeValue(settings.deploy_mode);
    setExternalPopupEnabledPreference(settings.popup_enabled);
    setPopupEnabledValue(settings.popup_enabled === "1");
    setSiteWideEnabledValue(settings.site_wide_enabled === "1");
    if (embedAllowedDomainsField) embedAllowedDomainsField.value = settings.embed_allowed_domains || "";
  };
  const syncDeployUiState = () => {
    cancelDomainsSave();
    // Builder calls this after applying another record's sidebar value.
    builder.querySelector(".aipkit_sidebar_toggle_switch")?.removeAttribute("data-original-state");
    updateDeployUI();
  };
  updateDeployUI();
  bindChatbotSettingsAutosave({
    builder, panel: builder, boundKey: "deployWizardBound", persistence,
    action: "aipkit_update_chatbot_deploy_settings", sharedLane: true,
    readSettings: getDeploySettings,
    isUnchanged: (a, b) => JSON.stringify(a) === JSON.stringify(b),
    restoreSettings: restoreDeploySettings,
    afterHydrate: event => { cancelDomainsSave(); updateDeployUI(event?.source !== "related-save"); },
    responsePatches: response => (response?.updated_bots || [])
      .filter(bot => bot?.bot_id && bot.settings?.site_wide_enabled !== undefined)
      .map(bot => ({ botId: bot.bot_id, settings: { site_wide_enabled: String(bot.settings.site_wide_enabled) } })),
    interactionNodes: () => {
      cancelDomainsSave();
      return [topModeSelect, popupToggle, siteWideToggle, popupScopeSelect, embedAllowedDomainsField];
    },
    bindEvents: ({ signal, isEditable, draft, save }) => {
      const change = (node, update) => node?.addEventListener("change", () => {
        if (!isEditable() || node.disabled) return;
        cancelDomainsSave();
        update();
        updateDeployUI();
        save();
      }, { signal });
      change(topModeSelect, () => {
        setTopDeployModeValue(topModeSelect.value);
        setExternalPopupEnabledPreference(topModeSelect.value === "inline" ? "0" : "1");
      });
      change(popupToggle, () => {
        if (popupToggle.checked) {
          if (getDeployMode() !== "external") setTopDeployModeValue("popup");
        } else setTopDeployModeValue("inline");
        setExternalPopupEnabledPreference(popupToggle.checked ? "1" : "0");
      });
      change(popupScopeSelect, () => {
        popupScopeSelect.value = popupScopeSelect.value === "sitewide" ? "sitewide" : "page";
      });
      change(siteWideToggle, () => setSiteWideEnabledValue(getDeployMode() === "popup" && siteWideToggle.checked));
      if (embedAllowedDomainsField) {
        const flush = () => { cancelDomainsSave(); if (!embedAllowedDomainsField.disabled) save(); };
        embedAllowedDomainsField.addEventListener("input", () => {
          if (!isEditable() || embedAllowedDomainsField.disabled) return;
          syncEmbedDomainsNote();
          draft();
          cancelDomainsSave();
          domainsSaveTimeout = setTimeout(flush, 350);
        }, { signal });
        embedAllowedDomainsField.addEventListener("change", flush, { signal });
        embedAllowedDomainsField.addEventListener("blur", flush, { signal });
      }
      signal.addEventListener("abort", cancelDomainsSave, { once: true });
    },
  });
  return syncDeployUiState;
}

/** Copy the shortcode currently displayed by the selected chatbot. */
export function bindChatbotShortcodeCopy(builder) {
  if (!builder.dataset.shortcodeCopyBound) {
    builder.addEventListener("click", event => {
      // Pills in Publish, plus Copy shortcode in the chatbot menu.
      const pill = event.target.closest(".aipkit_shortcode_pill, [data-aipkit-shortcode-copy]");
      if (!pill) {
        return;
      }
      const shortcode = pill.dataset.shortcode || pill.querySelector(".aipkit_shortcode_text")?.textContent || "";
      if (shortcode && typeof window.aipkit_copyShortcode === "function") {
        window.aipkit_copyShortcode(shortcode, pill);
      }
    });
    builder.dataset.shortcodeCopyBound = "1";
  }
}

/** Restore deployment controls and displayed shortcode/embed values on bot selection. */
export function applyChatbotDeploymentState(builder, normalizedBotId, botState, settings) {
  const modeSelect = builder.querySelector("[data-aipkit-top-mode-select]");
  const popupScopeSelectField = builder.querySelector('select[name="aipkit_deploy_popup_scope"]');
  const popupToggleField = builder.querySelector("[data-aipkit-popup-toggle]");
  const popupEnabledField = builder.querySelector("[data-aipkit-popup-enabled-input]");
  const siteWideToggleField = builder.querySelector("[data-aipkit-site-wide-toggle]");
  const siteWideEnabledField = builder.querySelector("[data-aipkit-site-wide-enabled-input]");
  if (modeSelect && botState.deploy_mode) {
    const isExternalMode = String(botState.deploy_mode) === "external";
    const isPopupEnabled = isExternalMode || String(botState.deploy_mode) === "popup" || String(settings.popup_enabled || "0") === "1";
    modeSelect.value = isExternalMode ? "external" : isPopupEnabled ? "popup" : "inline";
    modeSelect.dataset.aipkitExternalPopupEnabled = isPopupEnabled ? "1" : "0";
  }
  if (popupToggleField) {
    popupToggleField.checked = String(settings.popup_enabled || "0") === "1" || String(botState.deploy_mode) === "popup" || String(botState.deploy_mode) === "external";
  }
  if (popupEnabledField) {
    popupEnabledField.value = popupToggleField && popupToggleField.checked ? "1" : "0";
  }
  if (popupScopeSelectField) {
    popupScopeSelectField.value = String(settings.site_wide_enabled || "0") === "1" ? "sitewide" : "page";
  }
  if (siteWideToggleField) {
    siteWideToggleField.checked = String(settings.site_wide_enabled || "0") === "1" && String(botState.deploy_mode) !== "external";
  }
  if (siteWideEnabledField) {
    siteWideEnabledField.value = siteWideToggleField && siteWideToggleField.checked ? "1" : "0";
  }
  if (botState.shortcode) {
    const shortcodePills = builder.querySelectorAll(".aipkit_builder_shortcode_pill, [data-aipkit-shortcode-copy]");
    shortcodePills.forEach(pill => {
      pill.setAttribute("data-shortcode", botState.shortcode);
      const textNode = pill.querySelector(".aipkit_shortcode_text");
      if (textNode) {
        textNode.textContent = botState.shortcode;
      } else {
        pill.textContent = botState.shortcode;
      }
    });
  }
  const embedCodeField = document.getElementById(`aipkit_embed_code_${normalizedBotId}`);
  if (embedCodeField && typeof botState.embed_code === "string") {
    embedCodeField.value = botState.embed_code;
  }
}

/** Publish placement: one choice that sets the Popup and Every page fields deployment saves. */

export const placementFor = (popupOn, siteWideOn) => (!popupOn ? "inline" : siteWideOn ? "everywhere" : "chosen");

export function bindChatbotPlacement(builder) {
  const root = builder.querySelector("[data-aipkit-placement]");
  const popup = builder.querySelector("[data-aipkit-popup-toggle]");
  const siteWide = builder.querySelector("[data-aipkit-site-wide-toggle]");
  if (!root || !popup || !siteWide || root.dataset.placementBound) {
    return null;
  }
  root.dataset.placementBound = "1";
  const radios = Array.from(root.querySelectorAll(".aipkit_placement_radio"));
  const notes = Array.from(root.querySelectorAll("[data-aipkit-placement-note]"));

  const sync = () => {
    const value = placementFor(popup.checked, siteWide.checked);
    radios.forEach((radio) => {
      radio.checked = radio.value === value;
    });
    notes.forEach((note) => {
      note.hidden = !note.dataset.aipkitPlacementNote.split(" ").includes(value);
    });
  };

  // Deployment saves on these fields' change events, exactly as when the switches were clicked.
  const set = (field, checked, force = false) => {
    if (field.checked === checked && !force) {
      return;
    }
    field.checked = checked;
    field.dispatchEvent(new Event("change", { bubbles: true }));
  };

  root.addEventListener("change", (event) => {
    const radio = event.target.closest(".aipkit_placement_radio");
    if (!radio) {
      return;
    }
    if (radio.value === "inline") {
      set(popup, false);
    } else {
      // An external-embed bot keeps its popup but cannot be site-wide; bring it back to a site popup first.
      const mode = builder.querySelector("[data-aipkit-top-mode-select]");
      const fromExternal = mode?.value === "external";
      if (fromExternal) {
        mode.value = "popup";
      }
      set(popup, true, fromExternal);
      set(siteWide, radio.value === "everywhere");
    }
    sync();
  });

  popup.addEventListener("change", sync);
  siteWide.addEventListener("change", sync);
  builder.addEventListener("aipkit:bot-state-applied", sync);
  sync();
  return { sync };
}
