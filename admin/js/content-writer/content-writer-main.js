/**
AIPKit Content Writer - Main Initializer
Handles UI initialization, form submission, and EventSource management.
Calls refactored component functions.
*/
(function () {
  "use strict";

  const previousBeforeModuleChange =
    typeof window.aipkit_beforeModuleChange === "function"
      ? window.aipkit_beforeModuleChange
      : null;
  let inspectorDisclosureResizeObserver = null;
  let inspectorDisclosureWindowResizeHandler = null;
  let lastRunBlockedNoticeAt = 0;

  function cleanupInspectorDisclosureResponsiveWatcher() {
    if (inspectorDisclosureResizeObserver) {
      inspectorDisclosureResizeObserver.disconnect();
      inspectorDisclosureResizeObserver = null;
    }

    if (inspectorDisclosureWindowResizeHandler) {
      window.removeEventListener(
        "resize",
        inspectorDisclosureWindowResizeHandler
      );
      inspectorDisclosureWindowResizeHandler = null;
    }
  }

  function getActiveContentWriterWorkflow() {
    const container = document.getElementById("aipkit_content_writer_container");
    if (!container) {
      return null;
    }

    const isSingleRunActive =
      typeof window.aipkit_cw_isSingleRunActive === "function" &&
      window.aipkit_cw_isSingleRunActive();
    const isBatchActive =
      typeof window.aipkit_cw_isBatchActive === "function" &&
      window.aipkit_cw_isBatchActive();
    const isExistingUpdateActive =
      typeof window.aipkit_cw_isExistingUpdateActive === "function" &&
      window.aipkit_cw_isExistingUpdateActive();

    if (isBatchActive) {
      return "batch";
    }

    if (isExistingUpdateActive) {
      return "existing";
    }

    if (isSingleRunActive) {
      return "single";
    }

    return null;
  }

  function showContentWriterLeaveBlockedNotice() {
    const now = Date.now();
    if (now - lastRunBlockedNoticeAt < 400) {
      return;
    }
    lastRunBlockedNoticeAt = now;

    const message =
      "Stop the current run before switching modules or reloading Content Writer.";

    if (typeof window.aipkit_showConfirmModal === "function") {
      window.aipkit_showConfirmModal(message, {
        title: "Work in progress",
        confirmText: "OK",
        hideCancel: true,
        variant: "warning",
      });
      return;
    }

    window.alert(message);
  }

  function initContentWriterActiveRunGuard(container) {
    if (!container || container.dataset.aipkitRunGuardAttached === "true") {
      return;
    }

    const visualControlSelector = [
      "[data-aipkit-cw-inspector-disclosure-toggle]",
      "[data-aipkit-cw-prompt-toggle]",
      "[data-aipkit-cw-image-prompts-toggle]",
      "[data-aipkit-cw-prompt-expand]",
      "[data-aipkit-prompt-variable]",
      ".aipkit-modal-close-btn",
      "[data-aipkit-cw-prompt-editor-close]",
      "[data-aipkit-advanced-options-close]",
      "[data-aipkit-image-options-close]",
      "[data-aipkit-context-options-close]",
    ].join(",");
    const settingsRegionSelector = [
      ".aipkit_cw_inspector_stack",
      "#aipkit_cw_prompt_editor_modal",
      "#aipkit_cw_advanced_options_modal",
      "[data-aipkit-image-options-modal]",
      "[data-aipkit-context-options-modal]",
      ".aipkit_cw_settings_popover",
    ].join(",");
    const interactiveSelector = [
      "button",
      "input:not([type='hidden'])",
      "select",
      "textarea",
      "[contenteditable='true']",
      "[role='option']",
      "[role='switch']",
    ].join(",");
    const searchOnlySelector = [
      ".aipkit_unified_model_search_input",
      ".aipkit_searchable_multiselect_search_input",
    ].join(",");

    const isExistingRunActive = () =>
      getActiveContentWriterWorkflow() === "existing";
    const asElement = (target) =>
      target instanceof Element ? target : target?.parentElement || null;
    const isModeSwitch = (target) => {
      const card = target?.closest(".aipkit_cw_mode_card[data-mode]");
      if (!card || !container.contains(card)) {
        return false;
      }
      const currentMode = container.querySelector("#aipkit_cw_mode_select")?.value;
      return Boolean(card.dataset.mode && card.dataset.mode !== currentMode);
    };
    const getSettingsControl = (target) => {
      if (!target || !target.closest(settingsRegionSelector)) {
        return null;
      }
      if (
        target.closest(visualControlSelector) ||
        target.closest(searchOnlySelector)
      ) {
        return null;
      }

      const directControl = target.closest(interactiveSelector);
      if (directControl) {
        return directControl;
      }

      const label = target.closest("label");
      return label?.querySelector(interactiveSelector) ? label : null;
    };
    const getNativeControl = (control) => {
      if (!control) {
        return null;
      }
      if (control.matches("input, select, textarea, [contenteditable='true']")) {
        return control;
      }
      return control.querySelector(
        "input:not([type='hidden']), select, textarea, [contenteditable='true']"
      );
    };
    const isTextEntry = (control) => {
      const nativeControl = getNativeControl(control);
      if (!nativeControl) {
        return false;
      }
      if (
        nativeControl.matches("textarea, [contenteditable='true']")
      ) {
        return true;
      }
      if (!nativeControl.matches("input")) {
        return false;
      }
      return ["text", "search", "email", "url", "tel", "password"].includes(
        String(nativeControl.type || "text").toLowerCase()
      );
    };
    const blockAttempt = (event) => {
      event.preventDefault();
      event.stopImmediatePropagation();
      showContentWriterLeaveBlockedNotice();
    };

    const handlePointerOrClick = (event) => {
      if (!event.isTrusted || !isExistingRunActive()) {
        return;
      }
      const target = asElement(event.target);
      if (isModeSwitch(target)) {
        blockAttempt(event);
        return;
      }
      const control = getSettingsControl(target);
      if (control && !isTextEntry(control)) {
        blockAttempt(event);
      }
    };

    container.addEventListener("pointerdown", handlePointerOrClick, true);
    container.addEventListener("click", handlePointerOrClick, true);
    container.addEventListener(
      "keydown",
      (event) => {
        if (!event.isTrusted || !isExistingRunActive()) {
          return;
        }
        const target = asElement(event.target);
        if (isModeSwitch(target)) {
          if (["Enter", " "].includes(event.key)) {
            blockAttempt(event);
          }
          return;
        }
        const control = getSettingsControl(target);
        if (!control) {
          return;
        }
        if (isTextEntry(control)) {
          const navigationKeys = [
            "Tab",
            "Escape",
            "ArrowLeft",
            "ArrowRight",
            "ArrowUp",
            "ArrowDown",
            "Home",
            "End",
            "PageUp",
            "PageDown",
          ];
          const isReadOnlyShortcut =
            (event.metaKey || event.ctrlKey) &&
            ["a", "c"].includes(String(event.key).toLowerCase());
          if (navigationKeys.includes(event.key) || isReadOnlyShortcut) {
            return;
          }
          blockAttempt(event);
          return;
        }
        if (!["Tab", "Escape"].includes(event.key)) {
          blockAttempt(event);
        }
      },
      true
    );
    ["beforeinput", "paste", "cut", "drop"].forEach((eventName) => {
      container.addEventListener(
        eventName,
        (event) => {
          if (!event.isTrusted || !isExistingRunActive()) {
            return;
          }
          const control = getSettingsControl(asElement(event.target));
          if (control && isTextEntry(control)) {
            blockAttempt(event);
          }
        },
        true
      );
    });

    container.dataset.aipkitRunGuardAttached = "true";
  }

  function initInspectorDisclosures(container) {
    cleanupInspectorDisclosureResponsiveWatcher();

    const disclosures = Array.from(
      container?.querySelectorAll("[data-aipkit-cw-inspector-disclosure]") || []
    );
    if (!disclosures.length) {
      return;
    }

    const setOpen = (disclosure, open) => {
      const toggle = disclosure.querySelector(
        "[data-aipkit-cw-inspector-disclosure-toggle]"
      );
      const panel = disclosure.querySelector(
        "[data-aipkit-cw-inspector-disclosure-panel]"
      );
      if (!toggle || !panel) {
        return;
      }

      panel.hidden = !open;
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
      disclosure.classList.toggle("is-open", open);
    };

    const mobileCoreDisclosure = disclosures.find((disclosure) =>
      disclosure.hasAttribute("data-aipkit-cw-mobile-core-disclosure")
    );
    const moduleShell = container.closest(".aipkit_wrap");
    const getAvailableWidth = () =>
      moduleShell?.getBoundingClientRect().width ||
      container.getBoundingClientRect().width ||
      window.innerWidth;

    disclosures.forEach((disclosure) => {
      const toggle = disclosure.querySelector(
        "[data-aipkit-cw-inspector-disclosure-toggle]"
      );
      const panel = disclosure.querySelector(
        "[data-aipkit-cw-inspector-disclosure-panel]"
      );
      if (!toggle || !panel) {
        return;
      }

      setOpen(
        disclosure,
        disclosure === mobileCoreDisclosure && getAvailableWidth() >= 820
      );
      if (toggle.dataset.aipkitDisclosureAttached === "true") {
        return;
      }

      toggle.addEventListener("click", () => {
        const shouldOpen = toggle.getAttribute("aria-expanded") !== "true";
        const keepDesktopCoreOpen =
          mobileCoreDisclosure && getAvailableWidth() >= 820;

        disclosures.forEach((candidate) => {
          if (candidate.classList.contains("is-flat-fields")) {
            setOpen(candidate, true);
            return;
          }

          if (
            keepDesktopCoreOpen &&
            candidate === mobileCoreDisclosure
          ) {
            setOpen(candidate, true);
            return;
          }

          setOpen(candidate, candidate === disclosure && shouldOpen);
        });
      });
      toggle.dataset.aipkitDisclosureAttached = "true";
    });

    if (mobileCoreDisclosure) {
      let wasMobile = getAvailableWidth() < 820;
      const syncMobileCoreDisclosure = () => {
        const isMobile = getAvailableWidth() < 820;
        if (isMobile === wasMobile) {
          return;
        }
        wasMobile = isMobile;
        setOpen(mobileCoreDisclosure, !isMobile);
      };

      const responsiveTarget = moduleShell || container;
      if (typeof window.ResizeObserver === "function") {
        inspectorDisclosureResizeObserver = new ResizeObserver(
          syncMobileCoreDisclosure
        );
        inspectorDisclosureResizeObserver.observe(responsiveTarget);
      } else {
        inspectorDisclosureWindowResizeHandler = syncMobileCoreDisclosure;
        window.addEventListener(
          "resize",
          inspectorDisclosureWindowResizeHandler,
          { passive: true }
        );
      }
    }
  }

  /**
Main initializer for the Content Writer module.
Called by the main dashboard loader when the 'content-writer' module is loaded.
*/
  function aipkit_initContentWriter() {
    const mainContainer = document.getElementById(
      "aipkit_content_writer_container"
    ); // Get the main container for scoping
    if (!mainContainer) {
      console.error(
        "Content Writer Main: Could not find the main container (#aipkit_content_writer_container). Aborting initialization."
      );
      return;
    }

    const isBatchActive =
      typeof window.aipkit_cw_isBatchActive === "function" &&
      window.aipkit_cw_isBatchActive();
    if (
      !isBatchActive &&
      typeof window.aipkit_cw_resetBatchQueue === "function"
    ) {
      window.aipkit_cw_resetBatchQueue();
    }

    const form = document.getElementById("aipkit_content_writer_form");
    const generateBtn = document.getElementById(
      "aipkit_content_writer_generate_btn"
    );
    const copyBtn = document.getElementById("aipkit_content_writer_copy_btn");
    const clearBtn = document.getElementById("aipkit_content_writer_clear_btn");
    const providerSelect = document.getElementById(
      "aipkit_content_writer_provider"
    );
    const modelSelect = document.getElementById("aipkit_content_writer_model");
    const temperatureInput = document.getElementById(
      "aipkit_content_writer_temperature"
    );
    const saveAsPostBtns = Array.from(
      mainContainer.querySelectorAll("[data-aipkit-cw-save-post-btn]")
    );
    const indicatorsContainer = document.querySelector(
      ".aipkit_cw_generation_status_indicators"
    );
    if (
      !form ||
      !generateBtn ||
      !copyBtn ||
      !clearBtn ||
      !providerSelect ||
      !modelSelect ||
      !temperatureInput ||
      !saveAsPostBtns.length
    ) {
      console.error(
        "Content Writer Main: Missing one or more required UI elements."
      );
      return;
    }

    initInspectorDisclosures(mainContainer);
    initContentWriterActiveRunGuard(mainContainer);

    // Initialize settings popovers
    if (typeof window.aipkit_initContentWriterPopovers === "function") {
      window.aipkit_initContentWriterPopovers(mainContainer);
    } else {
      console.error(
        "Content Writer Main: aipkit_initContentWriterPopovers function not found."
      );
    }

    // Initialize inline prompt editors
    if (typeof window.aipkit_initContentWriterInlinePrompts === "function") {
      window.aipkit_initContentWriterInlinePrompts(mainContainer);
    } else {
      console.error(
        "Content Writer Main: aipkit_initContentWriterInlinePrompts function not found."
      );
    }

    if (typeof window.aipkit_initContentWriterPromptModeManager === "function") {
      window.aipkit_initContentWriterPromptModeManager(mainContainer);
    } else {
      console.error(
        "Content Writer Main: aipkit_initContentWriterPromptModeManager function not found."
      );
    }

    if (typeof window.aipkit_initContentWriterBulkEditor === "function") {
      window.aipkit_initContentWriterBulkEditor(mainContainer);
    } else {
      console.error(
        "Content Writer Main: aipkit_initContentWriterBulkEditor function not found."
      );
    }

    if (typeof window.aipkit_initContentWriterGenerateMenu === "function") {
      window.aipkit_initContentWriterGenerateMenu();
    } else {
      console.error(
        "Content Writer Main: aipkit_initContentWriterGenerateMenu function not found."
      );
    }

    // Initialize range sliders' value displays within this module scope
    if (typeof window.aipkit_attachRangeValueHandlers === "function") {
      window.aipkit_attachRangeValueHandlers("#aipkit_content_writer_container");
    }

    if (typeof window.aipkit_initContentWriterTabs === "function") {
      window.aipkit_initContentWriterTabs(mainContainer); // Pass scope element
    } else {
      console.error(
        "Content Writer Main: aipkit_initContentWriterTabs function not found."
      );
    }
    if (typeof window.aipkit_initContentWriterExistingContent === "function") {
      window.aipkit_initContentWriterExistingContent(mainContainer);
    } else {
      console.error(
        "Content Writer Main: aipkit_initContentWriterExistingContent function not found."
      );
    }

    // Initialize reasoning effort toggle
    if (typeof window.aipkit_cw_toggleReasoningEffort === "function") {
      providerSelect.addEventListener("change", function () {
        window.aipkit_cw_toggleReasoningEffort(mainContainer);
      });
      modelSelect.addEventListener("change", function () {
        window.aipkit_cw_toggleReasoningEffort(mainContainer);
      });
      window.aipkit_cw_toggleReasoningEffort(mainContainer);
    } else {
      console.error(
        "Content Writer Main: aipkit_cw_toggleReasoningEffort function not found."
      );
    }

    if (temperatureInput.dataset.aipkitValidationAttached !== "true") {
      const normalizeTemperatureValue = () => {
        const parsedValue = parseFloat(temperatureInput.value);
        if (!Number.isFinite(parsedValue)) {
          return;
        }

        const normalizedValue = Math.max(
          0,
          Math.min(2, Math.round(parsedValue * 10) / 10)
        );
        temperatureInput.value = String(normalizedValue);
      };

      temperatureInput.addEventListener("change", normalizeTemperatureValue);
      temperatureInput.dataset.aipkitValidationAttached = "true";
    }

    // --- ADDED: Initialize AI Image settings handler ---
    if (typeof window.aipkit_initImageSettingsHandler === "function") {
      window.aipkit_initImageSettingsHandler();
    } else {
      console.error(
        "Content Writer Main: aipkit_initImageSettingsHandler function not found."
      );
    }
    // --- END ADDED ---

    if (typeof window.aipkit_initApiKeyToggles === "function") {
      window.aipkit_initApiKeyToggles("#aipkit_content_writer_container");
    } else {
      console.error(
        "Content Writer Main: aipkit_initApiKeyToggles function not found."
      );
    }

    if (
      typeof window.aipkit_initContentWriterAdvancedSettingsModal ===
      "function"
    ) {
      window.aipkit_initContentWriterAdvancedSettingsModal(mainContainer);
    } else {
      console.error(
        "Content Writer Main: aipkit_initContentWriterAdvancedSettingsModal function not found."
      );
    }

    // --- ADDED: Initialize Vector settings handler ---
    if (typeof window.aipkit_initContentWriterVectorSettings === "function") {
      window.aipkit_initContentWriterVectorSettings();
    } else {
      console.error(
        "Content Writer Main: aipkit_initContentWriterVectorSettings function not found."
      );
    }
    // --- END ADDED ---

    // --- ADDED: Initialize OpenAI vector stores multiselect ---
    if (
      typeof window.aipkit_initContentWriterVectorStoreMultiSelect === "function"
    ) {
      window.aipkit_initContentWriterVectorStoreMultiSelect(mainContainer);
    } else {
      console.error(
        "Content Writer Main: aipkit_initContentWriterVectorStoreMultiSelect function not found."
      );
    }
    // --- END ADDED ---

    if (typeof window.aipkit_initContentWriterTagsEditor === "function") {
      window.aipkit_initContentWriterTagsEditor();
    }

    if (typeof window.aipkit_initCategoryDropdowns === "function") {
      window.aipkit_initCategoryDropdowns(mainContainer);
    } else {
      console.error(
        "Content Writer Main: aipkit_initCategoryDropdowns function not found."
      );
    }

    if (typeof window.aipkit_resetGenerationStatusIndicators === "function") {
      window.aipkit_resetGenerationStatusIndicators();
    } else {
      console.error(
        "Content Writer Main: resetGenerationStatusIndicators function not found."
      );
    }

    if (typeof window.aipkit_initContentWriterUI === "function") {
      window.aipkit_initContentWriterUI(
        providerSelect,
        modelSelect,
        generateBtn,
        copyBtn,
        clearBtn
      );
    } else {
      console.error(
        "Content Writer Main: aipkit_initContentWriterUI function not found."
      );
    }

    if (typeof window.aipkit_initContentWriterTemplateHandler === "function") {
      window.aipkit_initContentWriterTemplateHandler();
    } else {
      console.error(
        "Content Writer Main: aipkit_initContentWriterTemplateHandler function not found."
      );
    }

    if (typeof window.aipkit_initContentWriterAutosave === "function") {
      window.aipkit_initContentWriterAutosave();
    } else {
      console.error(
        "Content Writer Main: aipkit_initContentWriterAutosave function not found."
      );
    }

    if (typeof window.aipkit_initSaveAsPostButton === "function") {
      window.aipkit_initSaveAsPostButton();
    } else {
      console.error(
        "Content Writer Main: aipkit_initSaveAsPostButton function not found."
      );
    }

    // Initialize CSV upload handler
    if (typeof window.aipkit_initCsvUploadHandler === "function") {
      window.aipkit_initCsvUploadHandler();
    } else {
      console.error(
        "Content Writer Main: aipkit_initCsvUploadHandler function not found."
      );
    }

    const isPro = window.aipkit_dashboard && window.aipkit_dashboard.isProPlan;

    if (typeof window.aipkit_applyContentWriterSeoFeatureGate === "function") {
      window.aipkit_applyContentWriterSeoFeatureGate(form);
    }

    // Initialize RSS input handler
    if (isPro && typeof window.aipkit_initRssInputHandler === "function") {
      window.aipkit_initRssInputHandler();
    }

    // Initialize Google Sheets verification handler
    if (isPro && typeof window.aipkit_initGsheetsVerification === "function") {
      window.aipkit_initGsheetsVerification();
    } else if (isPro) {
      // Only log error if pro and function is missing
      console.error(
        "Content Writer Main: aipkit_initGsheetsVerification function not found."
      );
    }

    // Initialize URL Scrape handler
    if (isPro && typeof window.aipkit_initUrlScrapeHandler === "function") {
      window.aipkit_initUrlScrapeHandler();
    } else if (isPro) {
      // Only log error if pro and function is missing
      console.error(
        "Content Writer Main: aipkit_initUrlScrapeHandler function not found."
      );
    }

    // Attach listeners using modular functions
    if (typeof window.aipkit_attachGenerateButtonListener === "function") {
      window.aipkit_attachGenerateButtonListener(
        generateBtn,
        form,
        indicatorsContainer,
        window.aipkit_contentWriterEventSourceInstanceRef
      );
    } else {
      console.error(
        "Content Writer Main: aipkit_attachGenerateButtonListener function not found."
      );
    }

    window.aipkit_enableSaveAsPostButton = function () {
      saveAsPostBtns.forEach((button) => {
        button.disabled = false;
      });
    };
    window.aipkit_disableSaveAsPostButton = function () {
      saveAsPostBtns.forEach((button) => {
        button.disabled = true;
      });
    };
    window.aipkit_disableSaveAsPostButton();
  }

  window.aipkit_beforeModuleChange = async function (targetModuleName) {
    if (previousBeforeModuleChange) {
      const previousResult = await previousBeforeModuleChange(targetModuleName);
      if (previousResult === false) {
        return false;
      }
    }

    if (!getActiveContentWriterWorkflow()) {
      cleanupInspectorDisclosureResponsiveWatcher();
      return true;
    }

    showContentWriterLeaveBlockedNotice();
    return false;
  };

  window.aipkit_initContentWriter = aipkit_initContentWriter;
})();
