import { bindChatbotSettingsAutosave } from "./state.js";

// Keep server key order and class-based toggle/location hooks shared by reads and edits.
const WEB_SETTINGS_FIELDS = [
  ["openai_web_search_enabled", ".aipkit_openai_web_search_enable_toggle", true],
  ["openai_web_search_context_size"],
  ["openai_web_search_loc_type", ".aipkit_openai_web_search_loc_type_select"],
  ["openai_web_search_loc_country"],
  ["openai_web_search_loc_city"],
  ["openai_web_search_loc_region"],
  ["openai_web_search_loc_timezone"],
  ["claude_web_search_enabled", ".aipkit_claude_web_search_enable_toggle", true],
  ["claude_web_search_max_uses"],
  ["claude_web_search_loc_type", ".aipkit_claude_web_search_loc_type_select"],
  ["claude_web_search_loc_country"],
  ["claude_web_search_loc_city"],
  ["claude_web_search_loc_region"],
  ["claude_web_search_loc_timezone"],
  ["claude_web_search_allowed_domains"],
  ["claude_web_search_blocked_domains"],
  ["claude_web_search_cache_ttl"],
  ["openrouter_web_search_enabled", ".aipkit_openrouter_web_search_enable_toggle", true],
  ["openrouter_web_search_engine"],
  ["openrouter_web_search_max_results"],
  ["openrouter_web_search_max_uses"],
  ["openrouter_web_search_max_total_results"],
  ["openrouter_web_search_context_size"],
  ["openrouter_web_search_allowed_domains"],
  ["openrouter_web_search_excluded_domains"],
  ["xai_web_search_enabled", ".aipkit_xai_web_search_enable_toggle", true],
  ["google_search_grounding_enabled", ".aipkit_google_search_grounding_enable_toggle", true],
  ["web_toggle_default_on", ".aipkit_web_toggle_default_on", true],
  ["show_sources", ".aipkit_show_sources_toggle", true],
  ["sources_label"],
  ["searching_web_text"],
].map(([name, eventSelector, toggle = false]) => {
  const selector = `[name="${name}"]`;
  return { name, toggle, selector: toggle ? eventSelector : selector,
    eventSelector: eventSelector || selector };
});

export function createChatbotWebSearch({
  builder,
  modelPopoverPanel,
  webSettingsModal,
  sheetOverlay,
  modelSupportsOpenRouterCapability,
  isToggleFieldOn,
  updatePopoverToggleAvailability,
  syncToolsEnabledOptionsFromFields,
  updateToolsFeatureRowsVisibility,
  syncInlineSettingsPanelState,
  mountInlineSettingsPanelForTrigger,
  registerAdvancedDetailPanelCloser,
  closeOtherAdvancedDetailPanels,
  __,
}) {
  let activeWebSettingsTrigger = null;

  const updateWebSearchPopoverControls = () => {
    if (!modelPopoverPanel) {
      return;
    }

    const providerSelect = builder.querySelector(
      ".aipkit_chatbot_provider_select"
    );
    const provider = providerSelect ? providerSelect.value : "";
    const openrouterSupportsWebSearch = modelSupportsOpenRouterCapability(
      "web_search_tool"
    );
    const openaiSupportsWebSearch =
      String(window.aipkit_dashboard?.openaiApiMode || "responses") !==
      "chat_completions";

    const webProviderControls = Object.fromEntries([
      ["OpenAI", "openai_web_search"],
      ["Google", "google_search_grounding"],
      ["Claude", "claude_web_search"],
      ["OpenRouter", "openrouter_web_search"],
      ["xAI", "xai_web_search"],
    ].map(([name, prefix]) => {
      const slug = name.toLowerCase();
      return [name, {
        row: modelPopoverPanel.querySelector(`.aipkit_web_search_toggle_${slug}`),
        toggle: modelPopoverPanel.querySelector(`.aipkit_${prefix}_enable_toggle`),
        button: modelPopoverPanel.querySelector(
          `.aipkit_web_search_config_btn[data-web-provider="${slug}"]`
        ),
      }];
    }));

    Object.entries(webProviderControls).forEach(([providerName, controls]) => {
      if (controls.row) {
        controls.row.style.display = provider === providerName ? "" : "none";
      }
      if (controls.button) {
        const isOpenRouter = providerName === "OpenRouter";
        const isOpenAI = providerName === "OpenAI";
        const canShowForProvider = isOpenRouter
          ? openrouterSupportsWebSearch
          : isOpenAI
            ? openaiSupportsWebSearch
            : true;
        const canConfigure =
          canShowForProvider && isToggleFieldOn(controls.toggle);
        controls.button.style.display = canShowForProvider ? "" : "none";
        controls.button.disabled = !canConfigure;
        controls.button.setAttribute(
          "aria-disabled",
          canConfigure ? "false" : "true"
        );
      }
    });

    if (
      webSettingsModal &&
      activeWebSettingsTrigger &&
      webSettingsModal.classList.contains("is-open")
    ) {
      const isTriggerVisible =
        activeWebSettingsTrigger.offsetParent !== null &&
        activeWebSettingsTrigger.style.display !== "none" &&
        !activeWebSettingsTrigger.hidden;
      if (!isTriggerVisible) {
        webSettingsModal.classList.remove("is-open");
        webSettingsModal.setAttribute("aria-hidden", "true");
        syncInlineSettingsPanelState(webSettingsModal, false);
        activeWebSettingsTrigger.setAttribute("aria-expanded", "false");
        activeWebSettingsTrigger = null;
      }
    }

    const openrouterControls = webProviderControls.OpenRouter;
    if (openrouterControls.row && openrouterControls.toggle) {
      const shouldDisableOpenRouterWebSearch =
        provider === "OpenRouter" && !openrouterSupportsWebSearch;
      if (
        shouldDisableOpenRouterWebSearch &&
        isToggleFieldOn(openrouterControls.toggle)
      ) {
        if (openrouterControls.toggle.tagName === "SELECT") {
          openrouterControls.toggle.value = "0";
        } else if (openrouterControls.toggle.type === "checkbox") {
          openrouterControls.toggle.checked = false;
        } else {
          openrouterControls.toggle.value = "0";
        }
        openrouterControls.toggle.dispatchEvent(
          new Event("change", { bubbles: true })
        );
      }
      updatePopoverToggleAvailability(
        openrouterControls.row,
        openrouterControls.toggle,
        shouldDisableOpenRouterWebSearch
      );
    }

    const openaiControls = webProviderControls.OpenAI;
    if (openaiControls.row && openaiControls.toggle) {
      const shouldDisableOpenAIWebSearch = !openaiSupportsWebSearch;
      updatePopoverToggleAvailability(
        openaiControls.row,
        openaiControls.toggle,
        shouldDisableOpenAIWebSearch
      );

      const hint = openaiControls.row.querySelector(
        ".aipkit_tools_feature_hint"
      );
      if (hint) {
        if (!hint.dataset.aipkitDefaultHint) {
          hint.dataset.aipkitDefaultHint = hint.textContent || "";
        }
        hint.textContent = shouldDisableOpenAIWebSearch
          ? __(
              "Unavailable while OpenAI uses Chat Completions compatibility mode.",
              "gpt3-ai-content-generator"
            )
          : hint.dataset.aipkitDefaultHint;
      }
    }

    syncToolsEnabledOptionsFromFields();
    if (openaiControls?.row && !openaiSupportsWebSearch) {
      const visibleOption = openaiControls.row.querySelector(
        ".aipkit_tools_enabled_option"
      );
      if (visibleOption) {
        visibleOption.checked = false;
      }
      updateToolsFeatureRowsVisibility();
    }
  };

  const updateWebGroundingVisibility = () => {
    if (!builder.isConnected || webSettingsModal?.isConnected === false) return;
    const updateClaudeWebSearchSettings = (settingsArea) => {
      if (!settingsArea) {
        return;
      }
      const toggle =
        (modelPopoverPanel &&
          modelPopoverPanel.querySelector(
            ".aipkit_claude_web_search_enable_toggle"
          )) ||
        settingsArea.querySelector(".aipkit_claude_web_search_enable_toggle");
      const enabled = isToggleFieldOn(toggle);
      const conditional = settingsArea.querySelector(
        ".aipkit_claude_web_search_conditional_settings"
      );
      if (conditional) {
        conditional.style.display = enabled ? "" : "none";
      }
      const locType = settingsArea.querySelector(
        ".aipkit_claude_web_search_loc_type_select"
      );
      const locDetails = settingsArea.querySelector(
        ".aipkit_claude_web_search_location_details"
      );
      if (locDetails) {
        locDetails.style.display =
          enabled && locType && locType.value === "approximate" ? "" : "none";
      }
    };
    const updateOpenRouterWebSearchSettings = (
      settingsArea,
      supportsWebSearch
    ) => {
      if (!settingsArea) {
        return;
      }
      const toggle =
        (modelPopoverPanel &&
          modelPopoverPanel.querySelector(
            ".aipkit_openrouter_web_search_enable_toggle"
          )) ||
        settingsArea.querySelector(
          ".aipkit_openrouter_web_search_enable_toggle"
        );
      const enabled = supportsWebSearch && isToggleFieldOn(toggle);
      if (!supportsWebSearch && toggle && isToggleFieldOn(toggle)) {
        if (toggle.tagName === "SELECT") {
          toggle.value = "0";
        } else if (toggle.type === "checkbox") {
          toggle.checked = false;
        } else {
          toggle.value = "0";
        }
        toggle.dispatchEvent(new Event("change", { bubbles: true }));
      }
      const conditional = settingsArea.querySelector(
        ".aipkit_openrouter_web_search_conditional_settings"
      );
      if (conditional) {
        conditional.style.display = enabled ? "" : "none";
      }
    };

    const providerSelect = builder.querySelector(
      ".aipkit_chatbot_provider_select"
    );
    const provider = providerSelect ? providerSelect.value : "";
    const openrouterSupportsWebSearch = modelSupportsOpenRouterCapability(
      "web_search_tool"
    );
    const settingsArea = webSettingsModal || sheetOverlay;
    if (settingsArea) {
      const webToggleDefaultRow = settingsArea.querySelector(
        ".aipkit_web_toggle_default_row"
      );
      const showSourcesRow = settingsArea.querySelector(
        ".aipkit_show_sources_row"
      );

      for (const name of ["OpenAI", "Claude", "OpenRouter"]) {
        const section = settingsArea.querySelector(`.aipkit_web_modal_section_${name.toLowerCase()}`);
        if (section) section.style.display = provider === name &&
          (name !== "OpenRouter" || openrouterSupportsWebSearch) ? "" : "none";
      }
      if (webToggleDefaultRow) {
        webToggleDefaultRow.style.display = [
          "OpenAI",
          "Google",
          "Claude",
          "OpenRouter",
          "xAI",
        ].includes(provider)
          ? ""
          : "none";
      }
      if (showSourcesRow) {
        showSourcesRow.style.display = provider === "Google" ? "none" : "";
      }

      if (
        typeof window.aipkit_toggleOpenAIWebSearchSubSettings === "function"
      ) {
        window.aipkit_toggleOpenAIWebSearchSubSettings(settingsArea);
      }
      if (
        typeof window.aipkit_toggleOpenAIWebSearchLocationDetails ===
        "function"
      ) {
        window.aipkit_toggleOpenAIWebSearchLocationDetails(settingsArea);
      }
      updateClaudeWebSearchSettings(settingsArea);
      updateOpenRouterWebSearchSettings(
        settingsArea,
        openrouterSupportsWebSearch
      );
    }

    updateWebSearchPopoverControls();
  };

  return {
    updateVisibility: updateWebGroundingVisibility,
    bindPanel() {
      if (!webSettingsModal || !builder.isConnected || webSettingsModal.dataset.bound) return;
      const controller = new AbortController();
      const listenerOptions = { signal: controller.signal };
      const observer = new MutationObserver(() => {
        if (builder.isConnected && webSettingsModal.isConnected) return;
        controller.abort();
        observer.disconnect();
        closeWebSettingsFlyout();
        unregister();
        delete webSettingsModal.dataset.bound;
      });
      const openWebSettingsFlyout = (trigger) => {
        if (!webSettingsModal || !trigger) {
          return;
        }
        closeOtherAdvancedDetailPanels(webSettingsModal);
        mountInlineSettingsPanelForTrigger(webSettingsModal, trigger);
        const providerKey =
          String(trigger.dataset.webProvider || "").trim().toLowerCase();
        webSettingsModal.dataset.activeWebProvider = providerKey;
        webSettingsModal.classList.add("is-open");
        webSettingsModal.setAttribute("aria-hidden", "false");
        syncInlineSettingsPanelState(webSettingsModal, true);
        if (activeWebSettingsTrigger && activeWebSettingsTrigger !== trigger) {
          activeWebSettingsTrigger.setAttribute("aria-expanded", "false");
        }
        trigger.setAttribute("aria-expanded", "true");
        activeWebSettingsTrigger = trigger;

        if (typeof window.aipkit_attachRangeValueHandlers === "function") {
          window.aipkit_attachRangeValueHandlers(
            "#aipkit_builder_web_settings_modal"
          );
        }

        updateWebGroundingVisibility();
      };

      const closeWebSettingsFlyout = () => {
        webSettingsModal.classList.remove("is-open");
        webSettingsModal.setAttribute("aria-hidden", "true");
        syncInlineSettingsPanelState(webSettingsModal, false);
        delete webSettingsModal.dataset.activeWebProvider;
        if (activeWebSettingsTrigger) {
          activeWebSettingsTrigger.setAttribute("aria-expanded", "false");
          activeWebSettingsTrigger = null;
        }
      };
      const unregister = registerAdvancedDetailPanelCloser(webSettingsModal, closeWebSettingsFlyout);

      builder.addEventListener("click", (event) => {
        const configBtn = event.target.closest(".aipkit_web_search_config_btn");
        if (!configBtn) {
          return;
        }
        event.preventDefault();
        if (
          webSettingsModal.classList.contains("is-open") &&
          activeWebSettingsTrigger === configBtn
        ) {
          closeWebSettingsFlyout();
          return;
        }
        openWebSettingsFlyout(configBtn);
      }, listenerOptions);

      document.addEventListener("click", (event) => {
        if (!webSettingsModal.classList.contains("is-open")) {
          return;
        }
        if (webSettingsModal.classList.contains("aipkit_inline_settings_content")) {
          return;
        }
        if (
          event.target.closest(".aipkit_web_search_config_btn") ||
          event.target.closest('[data-aipkit-inline-settings-target="aipkit_builder_web_settings_modal"]') ||
          event.target.closest("#aipkit_builder_web_settings_modal")
        ) {
          return;
        }
        closeWebSettingsFlyout();
      }, listenerOptions);

      document.addEventListener("keydown", (event) => {
        if (
          event.key === "Escape" &&
          webSettingsModal.classList.contains("is-open")
        ) {
          closeWebSettingsFlyout();
        }
      }, listenerOptions);
      observer.observe(builder.ownerDocument.documentElement, { childList: true, subtree: true });

      webSettingsModal.dataset.bound = "1";
    },
    bindPersistence(persistence) {
      const {
        getChatbotCapabilityState,
        setKnowledgeEnabledWithoutChangeEvent,
        saveContextSettingsAfterCapabilityChange,
        syncTrainingUiState,
      } = persistence;
      const getWebSettings = () => {
        const readValue = (selector) => {
          const field = webSettingsModal
            ? webSettingsModal.querySelector(selector)
            : null;
          return field ? field.value : "";
        };
        const readToggle = (selector) => {
          const field =
            (modelPopoverPanel && modelPopoverPanel.querySelector(selector)) ||
            (builder && builder.querySelector(selector)) ||
            (webSettingsModal && webSettingsModal.querySelector(selector));
          if (!field) {
            return "0";
          }
          if (field.tagName === "SELECT") {
            return field.value === "1" ? "1" : "0";
          }
          return field.checked ? "1" : "0";
        };

        const settings = {};
        for (const field of WEB_SETTINGS_FIELDS) {
          settings[field.name] = field.toggle
            ? readToggle(field.selector)
            : readValue(field.selector);
        }
        return settings;
      };

      let saveWebSettingsAfterKnowledgeChange;
      bindChatbotSettingsAutosave({
        builder, panel: webSettingsModal || modelPopoverPanel,
        boundKey: "webSettingsAutosaveBound",
        action: "aipkit_update_chatbot_web_settings", persistence,
        lane: "model-context",
        readSettings: getWebSettings,
        isUnchanged: (a, b) => JSON.stringify(a) === JSON.stringify(b),
        savedSettings: (settings, response) => {
          const saved = response?.bot?.settings;
          // The web endpoint can also disable conflicting native Google Knowledge.
          return settings.google_search_grounding_enabled === "1" &&
            saved?.provider === "Google" && saved.vector_store_provider === "google" &&
            saved.enable_vector_store === "0"
            ? { ...settings, enable_vector_store: "0" } : settings;
        },
        restoreSettings: settings => {
          for (const { name, selector, toggle } of WEB_SETTINGS_FIELDS) {
            for (const root of new Set([modelPopoverPanel, builder, webSettingsModal])) {
              root?.querySelectorAll(toggle ? selector : `[name="${name}"]`).forEach(field => {
                if (field.type === "checkbox") field.checked = settings[name] === "1";
                else field.value = settings[name];
              });
            }
          }
        },
        afterHydrate: updateWebGroundingVisibility,
        interactionNodes: () => [webSettingsModal, ...builder.querySelectorAll('[data-aipkit-tool-key="web_search"]')],
        bindEvents: ({ signal, isEditable, draft, save }) => {
          // Knowledge changes this toggle without a change event. Persist the
          // corrected snapshot behind sent web writes instead of dropping drafts.
          saveWebSettingsAfterKnowledgeChange = save;
          const handleChange = event => {
            const target = event.target;
            if (!isEditable() || !target ||
                !WEB_SETTINGS_FIELDS.some(({ eventSelector }) => target.matches(eventSelector))) return;
            if (target.matches(".aipkit_google_search_grounding_enable_toggle") &&
                getChatbotCapabilityState().shouldDeactivateKnowledgeForGoogleSearch) {
              setKnowledgeEnabledWithoutChangeEvent(false);
              saveContextSettingsAfterCapabilityChange();
              syncTrainingUiState?.();
            }
            updateWebGroundingVisibility();
            save();
          };
          const handleInput = event => {
            if (event.target && WEB_SETTINGS_FIELDS.some(({ eventSelector }) => event.target.matches(eventSelector))) draft();
          };
          const roots = [modelPopoverPanel];
          if (webSettingsModal && !modelPopoverPanel?.contains(webSettingsModal)) roots.push(webSettingsModal);
          for (const root of roots) {
            root?.addEventListener("change", handleChange, { signal });
            root?.addEventListener("input", handleInput, { signal });
          }
        },
      });
      return saveWebSettingsAfterKnowledgeChange;
    },
  };
}
