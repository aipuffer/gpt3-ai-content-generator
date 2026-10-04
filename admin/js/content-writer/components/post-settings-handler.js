/**
 * AIPKit Content Writer - Publishing Settings UI Handler
 *
 * Handles mode-aware publishing schedule visibility in the Content Writer.
 */
(function () {
  "use strict";

  const translate =
    window.wp?.i18n?.__ ||
    function (str) {
      return str;
    };

  const PUBLISHING_VISIBLE_MODES = new Set([
    "task",
    "csv",
    "rss",
    "url",
    "gsheets",
  ]);
  const FROM_INPUT_ALLOWED_MODES = new Set(["task", "csv", "gsheets"]);
  const FROM_INPUT_LABEL_BY_MODE = {
    task: translate("Use dates from input", "gpt3-ai-content-generator"),
    csv: translate("Use dates from CSV", "gpt3-ai-content-generator"),
    gsheets: translate("Use dates from Sheets", "gpt3-ai-content-generator"),
  };
  const FROM_INPUT_HELP_BY_MODE = {
    csv: translate(
      "Use the Schedule column in the CSV.",
      "gpt3-ai-content-generator"
    ),
    gsheets: translate(
      "Use the Schedule column in Google Sheets.",
      "gpt3-ai-content-generator"
    ),
  };
  const FIT_SELECTED_SELECT_SELECTOR = "select[data-aipkit-cw-fit-selected]";
  let selectMeasurementCanvas = null;

  function getCurrentTaskEntryView() {
    const taskEntryRoot = document.querySelector(
      '#aipkit_content_writer_container [data-aipkit-bulk-source-panel="task"]'
    );
    const taskEntryShell = document.querySelector(
      "#aipkit_content_writer_container .aipkit_cw_task_entry_shell"
    );

    return (
      taskEntryRoot?.dataset.taskEntryView ||
      taskEntryShell?.dataset.taskEntryView ||
      "single"
    );
  }

  function isFromInputAllowedForMode(generationMode) {
    if (generationMode !== "task") {
      return FROM_INPUT_ALLOWED_MODES.has(generationMode);
    }

    const taskEntryView = getCurrentTaskEntryView();
    return taskEntryView === "batch" || taskEntryView === "paste";
  }

  function getFromInputHelpText(generationMode) {
    if (generationMode !== "task") {
      return FROM_INPUT_HELP_BY_MODE[generationMode] || "";
    }

    const taskEntryView = getCurrentTaskEntryView();
    if (taskEntryView === "batch") {
      return translate(
        "Use the row Schedule field in Batch Editor.",
        "gpt3-ai-content-generator"
      );
    }
    if (taskEntryView === "paste") {
      return translate(
        "Append | YYYY-MM-DD HH:MM to each line.",
        "gpt3-ai-content-generator"
      );
    }

    return "";
  }

  function isSingleTaskEntryMode(generationMode) {
    return generationMode === "task" && getCurrentTaskEntryView() === "single";
  }

  function getScheduleContainer(scopeElement) {
    return getContentWriterRoot(scopeElement)?.querySelector(
      "[data-aipkit-cw-schedule]"
    );
  }

  function setScheduleExpanded(scheduleContainer, shouldExpand) {
    if (!scheduleContainer) {
      return;
    }

    const toggle = scheduleContainer.querySelector(
      "[data-aipkit-cw-schedule-toggle]"
    );
    const panel = scheduleContainer.querySelector(
      "[data-aipkit-cw-schedule-panel]"
    );
    if (toggle) {
      toggle.setAttribute("aria-expanded", shouldExpand ? "true" : "false");
    }
    if (panel) {
      panel.hidden = !shouldExpand;
    }
    scheduleContainer.classList.toggle("is-open", shouldExpand);
  }

  function clearScheduleValidation(scheduleContainer) {
    const validation = scheduleContainer?.querySelector(
      "[data-aipkit-cw-schedule-validation]"
    );
    if (!validation) {
      return;
    }
    validation.hidden = true;
    validation.textContent = "";
  }

  function showScheduleValidation(scheduleContainer, message, field) {
    const validation = scheduleContainer?.querySelector(
      "[data-aipkit-cw-schedule-validation]"
    );
    if (validation) {
      validation.textContent = message;
      validation.hidden = false;
    }
    setScheduleExpanded(scheduleContainer, true);
    scheduleContainer?.scrollIntoView({ behavior: "smooth", block: "center" });
    window.setTimeout(() => field?.focus?.({ preventScroll: true }), 180);
  }

  function normalizeGenerationMode(mode) {
    const rawMode = String(mode || "").trim();
    if (!rawMode || rawMode === "single") {
      return "task";
    }
    if (rawMode === "existing") {
      return "existing-content";
    }
    return rawMode;
  }

  function getCurrentGenerationMode() {
    return normalizeGenerationMode(
      document.getElementById("aipkit_cw_mode_select")?.value || "task"
    );
  }

  function getPublishingContainers(scopeElement) {
    if (!scopeElement) {
      return Array.from(
        document.querySelectorAll(
          ".aipkit_cw_publishing_panel.aipkit_post_settings_redesigned"
        )
      );
    }

    if (
      scopeElement.classList?.contains("aipkit_cw_publishing_panel") &&
      scopeElement.classList?.contains("aipkit_post_settings_redesigned")
    ) {
      return [scopeElement];
    }

    return Array.from(
      scopeElement.querySelectorAll(
        ".aipkit_cw_publishing_panel.aipkit_post_settings_redesigned"
      )
    );
  }

  function getContentWriterRoot(element) {
    if (element?.matches?.("#aipkit_content_writer_container")) {
      return element;
    }

    return (
      element?.closest?.("#aipkit_content_writer_container") ||
      document.getElementById("aipkit_content_writer_container")
    );
  }

  function syncDynamicSelectTitle(selectElement) {
    if (!(selectElement instanceof HTMLSelectElement)) {
      return;
    }

    const selectedLabel =
      selectElement.selectedOptions[0]?.textContent?.trim() || "";
    if (selectedLabel) {
      selectElement.title = selectedLabel;
    } else {
      selectElement.removeAttribute("title");
    }
  }

  function parsePixelValue(value) {
    const parsedValue = Number.parseFloat(value);
    return Number.isFinite(parsedValue) ? parsedValue : 0;
  }

  function syncSelectedOptionWidth(selectElement) {
    if (
      !(selectElement instanceof HTMLSelectElement) ||
      !selectElement.matches(FIT_SELECTED_SELECT_SELECTOR)
    ) {
      return;
    }

    syncDynamicSelectTitle(selectElement);

    const selectedLabel =
      selectElement.selectedOptions[0]?.textContent?.trim() || "";
    if (!selectedLabel) {
      selectElement.style.removeProperty("--aipkit-cw-selected-width");
      return;
    }

    const computedStyle = window.getComputedStyle(selectElement);
    selectMeasurementCanvas =
      selectMeasurementCanvas || document.createElement("canvas");
    const context = selectMeasurementCanvas.getContext("2d");
    if (!context) {
      return;
    }

    context.font =
      computedStyle.font ||
      `${computedStyle.fontWeight} ${computedStyle.fontSize} ${computedStyle.fontFamily}`;

    const horizontalChrome =
      parsePixelValue(computedStyle.paddingLeft) +
      parsePixelValue(computedStyle.paddingRight) +
      parsePixelValue(computedStyle.borderLeftWidth) +
      parsePixelValue(computedStyle.borderRightWidth);
    const selectedWidth = Math.ceil(
      context.measureText(selectedLabel).width + horizontalChrome + 1
    );
    const maximumWidth =
      parsePixelValue(selectElement.dataset.aipkitCwFitSelectedMax) || 168;
    const minimumWidth =
      parsePixelValue(selectElement.dataset.aipkitCwFitSelectedMin) || 36;
    const clampedWidth = Math.min(
      maximumWidth,
      Math.max(minimumWidth, selectedWidth)
    );

    selectElement.style.setProperty(
      "--aipkit-cw-selected-width",
      `${clampedWidth}px`
    );
  }

  function refreshSelectedOptionWidths(scopeElement) {
    const root = getContentWriterRoot(scopeElement);
    root
      ?.querySelectorAll(FIT_SELECTED_SELECT_SELECTOR)
      .forEach(syncSelectedOptionWidth);
  }

  function refreshDynamicValueTitles(scopeElement) {
    refreshSelectedOptionWidths(scopeElement);
  }

  function togglePublishingCard(container) {
    if (!container) return false;

    const generationMode = getCurrentGenerationMode();
    const shouldShowPublishing = PUBLISHING_VISIBLE_MODES.has(generationMode);
    const contentWriterRoot = getContentWriterRoot(container);
    const statusRow = contentWriterRoot?.querySelector(
      "[data-aipkit-cw-core-status-row]"
    );

    container.hidden = !shouldShowPublishing;
    container.setAttribute(
      "aria-hidden",
      shouldShowPublishing ? "false" : "true"
    );
    if (statusRow) {
      statusRow.hidden = !shouldShowPublishing;
      statusRow.setAttribute(
        "aria-hidden",
        shouldShowPublishing ? "false" : "true"
      );
    }

    if (!shouldShowPublishing) {
      const disclosureToggle = container.querySelector(
        "[data-aipkit-cw-inspector-disclosure-toggle]"
      );
      const disclosurePanel = container.querySelector(
        "[data-aipkit-cw-inspector-disclosure-panel]"
      );
      if (disclosureToggle) {
        disclosureToggle.setAttribute("aria-expanded", "false");
      }
      if (disclosurePanel) {
        disclosurePanel.hidden = true;
      }
      container.classList.remove("is-open");

    }

    return shouldShowPublishing;
  }

  /**
   * Toggles the schedule date/time fields based on post status selection.
   * @param {HTMLElement} container The parent container element.
   */
  function toggleScheduleFields(scopeElement) {
    const contentWriterRoot = getContentWriterRoot(scopeElement);
    const scheduleContainer = getScheduleContainer(contentWriterRoot);
    if (!contentWriterRoot || !scheduleContainer) return;
    const generationMode = getCurrentGenerationMode();
    const statusSelect = contentWriterRoot?.querySelector(
      "#aipkit_content_writer_post_status"
    );
    if (!statusSelect) return;

    const shouldShow =
      PUBLISHING_VISIBLE_MODES.has(generationMode) &&
      statusSelect.value === "publish" &&
      !isSingleTaskEntryMode(generationMode);

    if (!shouldShow) {
      scheduleContainer.hidden = true;
      scheduleContainer.setAttribute("aria-hidden", "true");
      setScheduleExpanded(scheduleContainer, false);
      clearScheduleValidation(scheduleContainer);
      return;
    }

    scheduleContainer.hidden = false;
    scheduleContainer.setAttribute("aria-hidden", "false");
    toggleSmartScheduleFields(scheduleContainer);
  }

  /**
   * Toggles smart schedule fields based on the selected schedule mode.
   * @param {HTMLElement} container The parent container element.
   */
  function toggleSmartScheduleFields(container) {
    if (!container) return;

    const generationMode = getCurrentGenerationMode();
    if (isSingleTaskEntryMode(generationMode)) {
      const smartScheduleFields = container.querySelector(
        ".aipkit_post_smart_schedule_fields"
      );
      const fromInputHelp = container.querySelector(
        ".aipkit_schedule_from_input_help"
      );
      if (smartScheduleFields) {
        smartScheduleFields.hidden = true;
      }
      if (fromInputHelp) {
        fromInputHelp.hidden = true;
      }
      return;
    }

    const isFromInputAllowed = isFromInputAllowedForMode(generationMode);
    const smartScheduleFields = container.querySelector(
      ".aipkit_post_smart_schedule_fields"
    );
    const fromInputOption = container.querySelector(
      ".aipkit_schedule_from_input_option"
    );
    const fromInputText = fromInputOption?.querySelector(
      ".aipkit_post_schedule_radio_text"
    );
    const fromInputHelp = container.querySelector(".aipkit_schedule_from_input_help");

    if (fromInputOption) {
      fromInputOption.hidden = !isFromInputAllowed;
      fromInputOption.setAttribute(
        "aria-hidden",
        isFromInputAllowed ? "false" : "true"
      );
      if (fromInputText) {
        fromInputText.textContent =
          FROM_INPUT_LABEL_BY_MODE[generationMode] ||
          translate("Use dates from input", "gpt3-ai-content-generator");
      }
      const fromInputRadio = fromInputOption.querySelector(
        'input[name="schedule_mode"]'
      );
      if (!isFromInputAllowed && fromInputRadio && fromInputRadio.checked) {
        const immediateRadio = container.querySelector(
          'input[name="schedule_mode"][value="immediate"]'
        );
        if (immediateRadio) {
          immediateRadio.checked = true;
        }
      }
    }

    if (fromInputHelp) {
      fromInputHelp.textContent = isFromInputAllowed
        ? getFromInputHelpText(generationMode)
        : "";
    }

    if (!smartScheduleFields && !fromInputHelp) return;

    const effectiveSelectedMode = container.querySelector(
      'input[name="schedule_mode"]:checked'
    );
    const isSmart = effectiveSelectedMode && effectiveSelectedMode.value === "smart";
    const isFromInput =
      effectiveSelectedMode &&
      effectiveSelectedMode.value === "from_input" &&
      isFromInputAllowed;

    if (smartScheduleFields) {
      smartScheduleFields.hidden = !isSmart;
    }

    if (fromInputHelp) {
      fromInputHelp.hidden =
        !isFromInput || !fromInputHelp.textContent;
    }

    const summary = container.querySelector("[data-aipkit-cw-schedule-value]");
    if (summary) {
      if (isSmart) {
        summary.textContent = translate(
          "Smart schedule",
          "gpt3-ai-content-generator"
        );
      } else if (isFromInput) {
        summary.textContent =
          FROM_INPUT_LABEL_BY_MODE[generationMode] ||
          translate("Use dates from input", "gpt3-ai-content-generator");
      } else {
        summary.textContent = translate(
          "Publish immediately",
          "gpt3-ai-content-generator"
        );
      }
    }

    clearScheduleValidation(container);
  }

  function refreshPublishingUI(scopeElement) {
    const root = getContentWriterRoot(scopeElement);
    getPublishingContainers(root).forEach(togglePublishingCard);
    toggleScheduleFields(root);
    refreshDynamicValueTitles(scopeElement);
  }

  function validateContentWriterSchedule(form, requestedMode, reveal = false) {
    const generationMode = normalizeGenerationMode(
      requestedMode || form?.elements?.cw_generation_mode?.value || "task"
    );
    const scheduleContainer = getScheduleContainer(form);
    const validResult = { valid: true, message: "", field: null };

    if (
      !form ||
      form.elements.post_status?.value !== "publish" ||
      isSingleTaskEntryMode(generationMode)
    ) {
      return validResult;
    }

    const selectedMode = form.querySelector(
      'input[name="schedule_mode"]:checked'
    )?.value || "immediate";
    let result = validResult;

    if (selectedMode === "smart") {
      const startField = form.elements.smart_schedule_start_datetime;
      const intervalField = form.elements.smart_schedule_interval_value;
      const unitField = form.elements.smart_schedule_interval_unit;
      const startDate = new Date(startField?.value || "");
      if (!startField?.value || Number.isNaN(startDate.getTime())) {
        result = {
          valid: false,
          message: translate(
            "Choose a valid start date and time before generating.",
            "gpt3-ai-content-generator"
          ),
          field: startField,
        };
      } else if (startDate.getTime() <= Date.now()) {
        result = {
          valid: false,
          message: translate(
            "The schedule must start in the future.",
            "gpt3-ai-content-generator"
          ),
          field: startField,
        };
      } else if (
        !Number.isInteger(Number(intervalField?.value)) ||
        Number(intervalField?.value) < 1
      ) {
        result = {
          valid: false,
          message: translate(
            "Enter an interval of 1 or more before generating.",
            "gpt3-ai-content-generator"
          ),
          field: intervalField,
        };
      } else if (!new Set(["hours", "days"]).has(unitField?.value || "")) {
        result = {
          valid: false,
          message: translate(
            "Choose hours or days for the publishing interval.",
            "gpt3-ai-content-generator"
          ),
          field: unitField,
        };
      }
    } else if (
      selectedMode === "from_input" &&
      !isFromInputAllowedForMode(generationMode)
    ) {
      result = {
        valid: false,
        message: translate(
          "This source cannot use dates from input. Choose another schedule.",
          "gpt3-ai-content-generator"
        ),
        field: form.querySelector('input[name="schedule_mode"][value="immediate"]'),
      };
    }

    if (!result.valid && reveal) {
      showScheduleValidation(scheduleContainer, result.message, result.field);
    }
    return result;
  }

  function initDocumentListeners() {
    document.addEventListener("change", (e) => {
      const target = e.target;
      if (!(target instanceof HTMLElement)) {
        return;
      }

      const contentWriterContainer = target.closest(
        "#aipkit_content_writer_container"
      );
      if (!contentWriterContainer) {
        return;
      }

      if (target.matches(FIT_SELECTED_SELECT_SELECTOR)) {
        syncSelectedOptionWidth(target);
      }

      if (target.matches("#aipkit_content_writer_post_status")) {
        toggleScheduleFields(contentWriterContainer);
        return;
      }

      if (target.matches('input[name="schedule_mode"]')) {
        const container = target.closest("[data-aipkit-cw-schedule]");
        if (container) {
          toggleSmartScheduleFields(container);
        }
        return;
      }

      if (
        target.matches(
          '[name="smart_schedule_start_datetime"], [name="smart_schedule_interval_value"], [name="smart_schedule_interval_unit"]'
        )
      ) {
        clearScheduleValidation(getScheduleContainer(contentWriterContainer));
      }
    });

    document.addEventListener("click", (event) => {
      const toggle = event.target.closest("[data-aipkit-cw-schedule-toggle]");
      if (!toggle) {
        return;
      }
      const scheduleContainer = toggle.closest("[data-aipkit-cw-schedule]");
      if (!scheduleContainer) {
        return;
      }
      event.preventDefault();
      setScheduleExpanded(
        scheduleContainer,
        toggle.getAttribute("aria-expanded") !== "true"
      );
    });
  }

  /**
   * Main initialization on DOM ready.
   */
  function init() {
    initDocumentListeners();
    refreshPublishingUI();
  }

  // Initialize when DOM is ready
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }

  window.aipkit_refreshContentWriterPublishingUI = refreshPublishingUI;
  window.aipkit_refreshContentWriterSelectWidths =
    refreshSelectedOptionWidths;
  window.aipkit_validateContentWriterSchedule = validateContentWriterSchedule;
})();
