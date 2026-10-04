/**
 * AIPKit Content Writer - Mode Selector UI Handler
 * Handles UI changes when switching between the different input modes.
 */
(function () {
  "use strict";

  const normalizeMode = (mode) =>
    mode && mode !== "single" ? mode : "task";

  const isExistingMode = (mode) =>
    typeof mode === "string" && mode.startsWith("existing");

  const normalizeExistingMode = (mode) =>
    mode === "existing" ? "existing-content" : mode;

  const PROMPT_LABEL_OVERRIDES = {
    "existing-products": {
      title: {
        label: "Product Title",
      },
      content: {
        label: "Product Description",
      },
      meta: {
        label: "Meta description",
      },
      keyword: {
        label: "Focus keyword",
      },
      excerpt: {
        label: "Short Description",
      },
      tags: {
        label: "Tags",
      },
    },
  };

  const INSPECTOR_SECTION_VISIBILITY_BY_MODE = Object.freeze({
    "existing-content": Object.freeze({
      seo: false,
      publishing: false,
      images: false,
    }),
    "existing-images": Object.freeze({
      prompts: false,
      seo: false,
      publishing: false,
    }),
    "existing-products": Object.freeze({
      seo: false,
      publishing: false,
      images: false,
    }),
  });

  const syncInspectorSectionVisibility = (form, mode) => {
    if (!form) {
      return;
    }

    const visibility = INSPECTOR_SECTION_VISIBILITY_BY_MODE[mode] || {};
    let promptEditorWasHidden = false;

    form
      .querySelectorAll("[data-aipkit-cw-mode-section]")
      .forEach((section) => {
        const sectionKey = section.dataset.aipkitCwModeSection || "";
        const shouldShow = visibility[sectionKey] !== false;
        section.hidden = !shouldShow;
        section.setAttribute("aria-hidden", shouldShow ? "false" : "true");

        if (shouldShow) {
          return;
        }

        const toggle = section.querySelector(
          "[data-aipkit-cw-inspector-disclosure-toggle]"
        );
        const panel = section.querySelector(
          "[data-aipkit-cw-inspector-disclosure-panel]"
        );
        toggle?.setAttribute("aria-expanded", "false");
        if (panel) {
          panel.hidden = true;
        }
        section.classList.remove("is-open");

        if (sectionKey === "prompts" || sectionKey === "images") {
          promptEditorWasHidden = true;
        }
      });

    const syncFlatFieldsSection = (
      sectionKey,
      headingSelector,
      shouldShowFlat
    ) => {
      const section = form.querySelector(
        `[data-aipkit-cw-mode-section="${sectionKey}"]`
      );
      const toggle = section?.querySelector(
        "[data-aipkit-cw-inspector-disclosure-toggle]"
      );
      const panel = section?.querySelector(
        "[data-aipkit-cw-inspector-disclosure-panel]"
      );
      const heading = section?.querySelector(headingSelector);
      const wasFlat = Boolean(section?.classList.contains("is-flat-fields"));

      if (!section || !toggle || !panel || !heading) {
        return;
      }

      section.classList.toggle("is-flat-fields", shouldShowFlat);
      toggle.hidden = shouldShowFlat;
      heading.hidden = !shouldShowFlat;

      if (shouldShowFlat) {
        toggle.setAttribute("aria-expanded", "true");
        panel.hidden = false;
        section.classList.add("is-open");
      } else if (wasFlat) {
        toggle.setAttribute("aria-expanded", "false");
        panel.hidden = true;
        section.classList.remove("is-open");
      }
    };

    syncFlatFieldsSection(
      "prompts",
      "[data-aipkit-cw-existing-fields-heading]",
      mode === "existing-content" || mode === "existing-products"
    );
    syncFlatFieldsSection(
      "images",
      "[data-aipkit-cw-existing-image-fields-heading]",
      mode === "existing-images"
    );

    if (
      promptEditorWasHidden &&
      typeof window.aipkit_closeContentWriterInlinePromptEditor === "function"
    ) {
      window.aipkit_closeContentWriterInlinePromptEditor();
    }
  };

  const updatePromptCardLabels = (mode) => {
    const container = document.getElementById("aipkit_content_writer_container");
    if (!container) {
      return;
    }
    const cards = container.querySelectorAll(
      ".aipkit_cw_prompt_field[data-aipkit-prompt-key]"
    );
    if (!cards.length) {
      return;
    }
    const overrides = PROMPT_LABEL_OVERRIDES[mode] || null;
    cards.forEach((card) => {
      const key = card.dataset.aipkitPromptKey;
      const labelEl = card.querySelector(":scope > .aipkit_cw_prompt_field_row .aipkit_cw_prompt_field_label");
      if (!labelEl) {
        return;
      }
      if (!card.dataset.defaultLabel) {
        card.dataset.defaultLabel = labelEl.textContent || "";
      }
      const override = overrides && key ? overrides[key] : null;
      if (override) {
        labelEl.textContent = override.label;
      } else {
        labelEl.textContent = card.dataset.defaultLabel;
      }
    });
  };

  /**
   * Main logic to handle UI changes based on the selected mode.
   * @param {string} selectedMode The selected mode value.
   */
  function handleTabChange(selectedMode) {
    const form = document.getElementById("aipkit_content_writer_form");
    if (!form) return;

    const normalizedMode = normalizeMode(selectedMode);
    const displayMode = normalizeExistingMode(normalizedMode);

    // --- Hide the output area if the mode is not 'task' ---
    // The wrapper is shown programmatically when single-row generation starts in bulk mode.
    const singleOutputWrapper = document.getElementById(
      "aipkit_cw_single_output_wrapper"
    );
    if (singleOutputWrapper && displayMode !== "task") {
      singleOutputWrapper.style.display = "none";
    }
    const wrapperVisible =
      singleOutputWrapper && singleOutputWrapper.style.display !== "none";
    if (
      typeof window.aipkit_setContentWriterSinglePreviewState === "function"
    ) {
      window.aipkit_setContentWriterSinglePreviewState(
        Boolean(displayMode === "task" && wrapperVisible)
      );
    }
    // --- End ---

    if (typeof window.aipkit_setContentWriterPromptMode === "function") {
      window.aipkit_setContentWriterPromptMode(displayMode);
    }

    if (typeof window.aipkit_updateContentWriterActionState === "function") {
      window.aipkit_updateContentWriterActionState(displayMode);
    }


    const batchQueue = document.getElementById("aipkit_cw_batch_queue");
    if (batchQueue) {
      const isBatchMonitorVisible =
        typeof window.aipkit_cw_isBatchMonitorVisible === "function" &&
        window.aipkit_cw_isBatchMonitorVisible();
      batchQueue.hidden = !isBatchMonitorVisible;
    }

    if (typeof window.aipkit_refreshContentWriterPublishingUI === "function") {
      window.aipkit_refreshContentWriterPublishingUI();
    }

    if (
      displayMode.startsWith("existing") &&
      typeof window.aipkit_fetchContentWriterExistingPosts === "function"
    ) {
      window.aipkit_fetchContentWriterExistingPosts(1);
    }

  }

  function aipkit_updateContentWriterActionState(selectedMode) {
    const form = document.getElementById("aipkit_content_writer_form");
    if (!form) return;
    const container = document.getElementById("aipkit_content_writer_container");

    const generateButton = document.getElementById(
      "aipkit_content_writer_generate_btn"
    );
    const generateButtonText = generateButton
      ? generateButton.querySelector(".aipkit_btn-text")
      : null;
    const generateButtonIcon = generateButton
      ? generateButton.querySelector(".aipkit_cw_action_icon")
      : null;
    const actionShell = document.querySelector(".aipkit_cw_action_shell");
    const actionDock = container
      ? container.querySelector(".aipkit_cw_action_dock")
      : null;
    const existingActionMount = form.querySelector(
      "[data-aipkit-existing-action-mount]"
    );
    const actionDockHome = form.querySelector(
      "[data-aipkit-action-dock-home]"
    );
    const toggleBtn = actionShell
      ? actionShell.querySelector(".aipkit_cw_action_disclosure")
      : null;
    const menu = actionShell
      ? actionShell.querySelector(".aipkit_cw_action_menu")
      : null;
    const frequencySelect = form.querySelector("#aipkit_cw_task_frequency");
    const menuItems = menu
      ? Array.from(
          menu.querySelectorAll(".aipkit_cw_action_menu_option[data-action]")
        )
      : [];

    const isPro = window.aipkit_dashboard && window.aipkit_dashboard.isProPlan;
    const taskModes = new Set(["task", "csv", "rss", "gsheets", "url"]);
    const proModes = new Set(["rss", "gsheets", "url"]);
    let activeMode = normalizeMode(
      selectedMode ||
        document.getElementById("aipkit_cw_mode_select")?.value ||
        "task"
    );
    activeMode = normalizeExistingMode(activeMode);
    const isExistingModeActive = isExistingMode(activeMode);
    const isExistingImages = activeMode === "existing-images";
    const isExistingProducts = activeMode === "existing-products";
    const isExistingContent = activeMode === "existing-content";
    const isTaskMode = taskModes.has(activeMode);
    const isProMode = proModes.has(activeMode);
    const shouldHideLockedModeActionDock = isProMode && !isPro;
    const canUseTaskActions = isTaskMode && (!isProMode || isPro);
    const isOneTimeOnlyMode = activeMode === "task" || activeMode === "csv";

    if (actionDock) {
      if (isExistingModeActive && existingActionMount) {
        if (actionDock.parentElement !== existingActionMount) {
          existingActionMount.appendChild(actionDock);
        }
      } else if (actionDockHome?.parentElement) {
        const homeParent = actionDockHome.parentElement;
        if (actionDock.parentElement !== homeParent) {
          homeParent.insertBefore(actionDock, actionDockHome.nextSibling);
        }
      }
    }

    form.classList.toggle("aipkit_cw_mode_existing", isExistingModeActive);
    form.classList.toggle("aipkit_cw_mode_existing_images", isExistingImages);
    form.classList.toggle("aipkit_cw_mode_existing_products", isExistingProducts);
    form.classList.toggle("aipkit_cw_mode_existing_content", isExistingContent);
    syncInspectorSectionVisibility(form, activeMode);

    // Close any open popovers when switching modes to avoid orphaned panels.
    form
      .querySelectorAll(".aipkit_model_settings_popover.aipkit-active")
      .forEach((popover) => {
        popover.classList.remove("aipkit-active");
        popover.setAttribute("aria-hidden", "true");
      });
    form
      .querySelectorAll(".aipkit_cw_popover_trigger[aria-expanded='true']")
      .forEach((trigger) => trigger.setAttribute("aria-expanded", "false"));

    updatePromptCardLabels(activeMode);
    if (typeof window.aipkit_updateImageSettingsUI === "function") {
      window.aipkit_updateImageSettingsUI();
    }

    const syncTemplateGenerationToggles = () => {
      const templateState = window.aipkit_cw_template_state;
      if (!templateState) {
        return;
      }
      const currentTemplate = Array.isArray(templateState.currentTemplates)
        ? templateState.currentTemplates.find(
            (template) =>
              String(template.id) === String(templateState.currentTemplateId || "")
          )
        : null;
      const config =
        currentTemplate?.config || templateState.initialConfigForSelectedTemplate;
      if (!config || typeof config !== "object") {
        return;
      }
      ["generate_title", "generate_content"].forEach((key) => {
        if (!Object.prototype.hasOwnProperty.call(config, key)) {
          return;
        }
        const checkbox = form.elements[key];
        if (!checkbox) {
          return;
        }
        const rawValue = config[key];
        checkbox.checked =
          rawValue === "1" || rawValue === 1 || rawValue === true;
      });
    };

    syncTemplateGenerationToggles();
    if (typeof window.aipkit_syncContentWriterInlinePrompts === "function") {
      window.aipkit_syncContentWriterInlinePrompts();
    }

    if (frequencySelect && isOneTimeOnlyMode) {
      const hasOneTime = Array.from(frequencySelect.options).some(
        (option) => option.value === "one-time"
      );
      if (hasOneTime && frequencySelect.value !== "one-time") {
        frequencySelect.value = "one-time";
      }
    }

    // --- RSS special handling: check if feeds are verified ---
    let isRssVerified = false;
    if (activeMode === "rss") {
      const rssItemsCount = document.getElementById("aipkit_cw_rss_items_count");
      isRssVerified = rssItemsCount && parseInt(rssItemsCount.value, 10) > 0;
    }

    let action = "generate";
    if (isExistingModeActive) {
      action = !isPro && isExistingProducts ? "upgrade" : "update";
    } else if (!canUseTaskActions && isProMode) {
      action = "upgrade";
    } else if (activeMode === "rss" && !isRssVerified) {
      action = "fetch_feeds";
    }

    if (actionShell) {
      actionShell.dataset.aipkitCwPrimaryAction = action;
    }

    menuItems.forEach((item) => {
      const itemAction = item.dataset.action || "generate";
      const shouldHideCreate =
        !isTaskMode || (isProMode && !isPro) || isExistingModeActive;
      if (itemAction === "create_task") {
        item.hidden = shouldHideCreate;
      }
    });

    if (actionDock) {
      actionDock.hidden = shouldHideLockedModeActionDock;
      actionDock.setAttribute(
        "aria-hidden",
        shouldHideLockedModeActionDock ? "true" : "false"
      );
    }

    if (toggleBtn) {
      // Hide toggle for RSS when not verified (single action only)
      const showToggle =
        canUseTaskActions && !isExistingModeActive && !(activeMode === "rss" && !isRssVerified);
      toggleBtn.style.display = showToggle ? "" : "none";
      toggleBtn.disabled = !showToggle;
    }
    if (menu) {
      menu.hidden = true;
      if (toggleBtn) {
        toggleBtn.setAttribute("aria-expanded", "false");
      }
    }
    if (actionShell) {
      actionShell.classList.toggle(
        "is-primary-only",
        !canUseTaskActions || (activeMode === "rss" && !isRssVerified)
      );
    }

    const labelMap = {
      generate: "Generate",
      upgrade: "Upgrade",
      fetch_feeds: "Fetch feeds",
      update: "Update",
    };
    const label = labelMap[action] || "Generate";
    if (generateButtonText) {
      generateButtonText.textContent = label;
    } else if (generateButton) {
      if (typeof window.aipkit_setButtonText === "function") {
        window.aipkit_setButtonText(generateButton, label);
      } else {
        generateButton.textContent = label;
      }
    }
    if (generateButton) {
      generateButton.dataset.action = action;
      generateButton.classList.toggle(
        "aipkit_pro_upgrade_button",
        action === "upgrade"
      );
      if (!isExistingModeActive) {
        generateButton.removeAttribute("aria-disabled");
        if (generateButton.dataset.aipkitExistingDisabled) {
          generateButton.disabled = false;
          delete generateButton.dataset.aipkitExistingDisabled;
        }
      }
    }
    if (
      typeof window.aipkit_refreshContentWriterGenerateLabel === "function"
    ) {
      window.aipkit_refreshContentWriterGenerateLabel();
    }
    if (generateButtonIcon) {
      generateButtonIcon.hidden = action !== "fetch_feeds";
    }

    const isBatchActive =
      typeof window.aipkit_cw_isBatchActive === "function" &&
      window.aipkit_cw_isBatchActive();
    if (isBatchActive) {
      if (typeof window.aipkit_setContentWriterStopMode === "function") {
        window.aipkit_setContentWriterStopMode(true);
      }
      return;
    }
    if (
      generateButton?.dataset?.aipkitStopMode === "true" &&
      typeof window.aipkit_setContentWriterStopMode === "function"
    ) {
      window.aipkit_setContentWriterStopMode(false);
    }

    if (typeof window.aipkit_cw_resetBatchQueue === "function") {
      const shouldHideQueue = action !== "generate" || !isTaskMode;
      if (shouldHideQueue) {
        window.aipkit_cw_resetBatchQueue();
      }
    }
  }

  /**
   * Initializes the mode selector functionality for the Content Writer.
   * @param {HTMLElement} scopeElement The container element for the content writer module to scope the query selectors.
   */
  function aipkit_initContentWriterTabs(scopeElement) {
    if (!scopeElement) {
      console.error(
        "Content Writer Mode: Scope element not provided for initialization."
      );
      return;
    }
    const modeSelect = scopeElement.querySelector("#aipkit_cw_mode_select");
    const modeCards = Array.from(
      scopeElement.querySelectorAll(".aipkit_cw_mode_card")
    );
    const bulkSourcePanels = Array.from(
      scopeElement.querySelectorAll("[data-aipkit-bulk-source-panel]")
    );
    const contentContainer = scopeElement.querySelector(
      ".aipkit_cw_tab_content_container"
    );

    if (!modeSelect || !contentContainer) {
      console.warn(
        "Content Writer Mode: Mode selector or content container not found within the provided scope."
      );
      return;
    }

    const updateModeCards = (selectedMode) => {
      if (!modeCards.length) return;
      modeCards.forEach((card) => {
        const isActive = card.dataset.mode === selectedMode;
        card.classList.toggle("is-active", isActive);
        card.setAttribute("aria-pressed", isActive ? "true" : "false");
      });
    };

    const updateBulkSourceUI = (source) => {
      if (!bulkSourcePanels.length) return;
      bulkSourcePanels.forEach((panel) => {
        const isActive = panel.dataset.aipkitBulkSourcePanel === source;
        panel.classList.toggle("is-hidden", !isActive);
        panel.hidden = !isActive;
        panel.setAttribute("aria-hidden", isActive ? "false" : "true");
      });
    };

    const setActiveMode = (selectedMode) => {
      const normalizedMode = normalizeMode(selectedMode);
      const displayMode = normalizeExistingMode(normalizedMode);
      // CSV is now its own pane (not nested inside task)
      const paneMode = displayMode.startsWith("existing")
        ? "existing"
        : displayMode;

      if (modeSelect.value !== normalizedMode) {
        modeSelect.value = normalizedMode;
      }
      contentContainer
        .querySelectorAll(".aipkit_cw_tab_content")
        .forEach((pane) => {
          pane.classList.toggle(
            "aipkit_active",
            pane.dataset.pane === paneMode
          );
        });
      updateModeCards(displayMode);
      updateBulkSourceUI(normalizedMode === "csv" ? "csv" : "task");
      handleTabChange(displayMode);
    };

    modeSelect.addEventListener("change", function (event) {
      setActiveMode(event.target.value);
    });

    modeCards.forEach((card) => {
      if (card.dataset.listenerAttached) return;
      card.addEventListener("click", () => {
        const selectedMode = card.dataset.mode || "task";
        if (modeSelect.value !== selectedMode) {
          modeSelect.value = selectedMode;
          modeSelect.dispatchEvent(new Event("change", { bubbles: true }));
        }
      });
      card.dataset.listenerAttached = "true";
    });

    // Initial call to set the UI correctly
    const initialMode = modeSelect.value || "task";
    setActiveMode(initialMode === "single" ? "task" : initialMode);
  }

  window.aipkit_initContentWriterTabs = aipkit_initContentWriterTabs;
  window.aipkit_updateContentWriterActionState = aipkit_updateContentWriterActionState;
})();
