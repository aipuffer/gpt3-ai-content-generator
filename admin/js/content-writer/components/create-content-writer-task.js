/**
 * AIPKit Content Writer - Create Content Writer Task
 * Handles creating an Automated Task from the Content Writer UI.
 */
(function () {
  "use strict";

  const __ = window.wp?.i18n?.__ || function (str) { return str; };

  const getFieldValue = (form, name) =>
    (form?.elements?.[name]?.value || "").trim();

  const getLines = (value) =>
    String(value || "")
      .split(/\r?\n/)
      .map((line) => line.trim())
      .filter(Boolean);

  const getTopicsFromLines = (lines) =>
    lines
      .map((line) => line.split("|")[0].trim())
      .filter(Boolean);

  const isValidUrl = (value) => {
    try {
      const url = new URL(value);
      return url.protocol === "http:" || url.protocol === "https:";
    } catch (error) {
      return false;
    }
  };

  const hasVerifiedGsheets = (form) => {
    const statusContainer = form?.querySelector(
      ".aipkit_gsheets_status_container"
    );
    return statusContainer?.dataset.gsheetsStatus === "success";
  };

  const getCurrentTaskEntryView = () => {
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
  };

  const getTopicValidationElements = () => {
    const field = document.getElementById("aipkit_cw_single_compose_topic");
    const helper = document.getElementById(
      "aipkit_cw_single_compose_topic_help"
    );
    return {
      field,
      helper,
      wrapper: field?.closest(".aipkit_cw_single_compose_field--topic") || null,
    };
  };

  function aipkit_showContentWriterTopicValidation(message) {
    const { field, helper, wrapper } = getTopicValidationElements();
    if (!field || !helper) return false;

    helper.textContent =
      message ||
      __("Enter a topic to generate an article.", "gpt3-ai-content-generator");
    helper.classList.add("is-error");
    field.setAttribute("aria-invalid", "true");
    wrapper?.classList.add("is-invalid");
    field.focus({ preventScroll: true });
    field.scrollIntoView({ behavior: "smooth", block: "nearest" });
    return true;
  }

  function aipkit_clearContentWriterTopicValidation() {
    const { field, helper, wrapper } = getTopicValidationElements();
    if (!field || !helper) return;

    helper.textContent = helper.dataset.defaultMessage || "";
    helper.classList.remove("is-error");
    field.setAttribute("aria-invalid", "false");
    wrapper?.classList.remove("is-invalid");
  }

  function aipkit_clearContentWriterManualTopicValidation() {
    document
      .querySelectorAll(
        "#aipkit_content_writer_container [data-aipkit-bulk-topic-validation]"
      )
      .forEach((validation) => {
        validation.textContent = "";
        validation.hidden = true;
      });
    document
      .querySelectorAll(
        '#aipkit_content_writer_container [data-bulk-field="topic"][aria-invalid="true"]'
      )
      .forEach((field) => field.setAttribute("aria-invalid", "false"));

    const quickPasteValidation = document.querySelector(
      "#aipkit_content_writer_container [data-aipkit-quick-paste-validation]"
    );
    if (quickPasteValidation?.classList.contains("is-topic-error")) {
      quickPasteValidation.textContent = "";
      quickPasteValidation.hidden = true;
      quickPasteValidation.classList.remove("is-topic-error");
      document
        .querySelector("#aipkit_content_writer_container .aipkit_cw_paste_textarea")
        ?.setAttribute("aria-invalid", "false");
    }
  }

  function aipkit_showContentWriterManualTopicValidation(field, message) {
    const validationMessage =
      message ||
      __("Enter a topic to generate an article.", "gpt3-ai-content-generator");

    if (field === "batch-topic") {
      const topicInput = document.querySelector(
        '#aipkit_content_writer_container [data-aipkit-bulk-row] [data-bulk-field="topic"]'
      );
      const validation = topicInput
        ?.closest("[data-aipkit-bulk-row]")
        ?.querySelector("[data-aipkit-bulk-topic-validation]");
      if (!topicInput || !validation) return false;

      validation.textContent = validationMessage;
      validation.hidden = false;
      topicInput.setAttribute("aria-invalid", "true");
      topicInput.focus({ preventScroll: true });
      topicInput.scrollIntoView({ behavior: "smooth", block: "nearest" });
      return true;
    }

    if (field === "quick-paste") {
      const textarea = document.querySelector(
        "#aipkit_content_writer_container .aipkit_cw_paste_textarea"
      );
      const validation = document.querySelector(
        "#aipkit_content_writer_container [data-aipkit-quick-paste-validation]"
      );
      if (!textarea || !validation) return false;

      validation.replaceChildren(document.createTextNode(validationMessage));
      validation.classList.add("is-topic-error");
      validation.hidden = false;
      textarea.setAttribute("aria-invalid", "true");
      textarea.focus({ preventScroll: true });
      textarea.scrollIntoView({ behavior: "smooth", block: "nearest" });
      return true;
    }

    return false;
  }

  function aipkit_showContentWriterValidationError(
    statusDiv,
    message,
    field = ""
  ) {
    if (
      field === "topic" &&
      aipkit_showContentWriterTopicValidation(message)
    ) {
      aipkit_clearContentWriterValidationMessage(statusDiv);
      return;
    }

    if (
      (field === "batch-topic" || field === "quick-paste") &&
      aipkit_showContentWriterManualTopicValidation(field, message)
    ) {
      aipkit_clearContentWriterValidationMessage(statusDiv);
      return;
    }

    if (!statusDiv || statusDiv.id !== "aipkit_cw_action_validation") {
      console.warn(
        "Content Writer validation surface is unavailable for a non-field error."
      );
      return;
    }
    statusDiv.textContent =
      message || __("Please review your input.", "gpt3-ai-content-generator");
    statusDiv.className = "aipkit_cw_action_validation is-error";
  }

  function aipkit_clearContentWriterValidationMessage(statusDiv) {
    if (!statusDiv) return;
    statusDiv.textContent = "";
    if (statusDiv.id === "aipkit_cw_action_validation") {
      statusDiv.className = "aipkit_cw_action_validation";
    } else {
      statusDiv.className = "aipkit_cw_status_badge";
    }
  }

  function aipkit_validateContentWriterInputs(form, activeMode) {
    const modelSelection = form.querySelector("#aipkit_content_writer_ai_selection");
    if (modelSelection?.selectedOptions?.[0]?.disabled) {
      return {
        isValid: false,
        message: __("The selected model is unavailable. Choose another model before generating.", "gpt3-ai-content-generator"),
      };
    }

    let mode =
      activeMode ||
      document.getElementById("aipkit_cw_mode_select")?.value ||
      "task";

    if (mode === "single") {
      mode = "task";
    }

    if (mode === "task") {
      const taskEntryView = getCurrentTaskEntryView();
      const manualEntryValidation =
        form
          .closest("#aipkit_content_writer_container")
          ?.aipkitValidateManualEntries?.() || { isValid: true };
      if (!manualEntryValidation.isValid) {
        return manualEntryValidation;
      }

      const topics = getTopicsFromLines(
        getLines(getFieldValue(form, "content_title_bulk"))
      );
      if (!topics.length) {
        return {
          isValid: false,
          field:
            taskEntryView === "single"
              ? "topic"
              : taskEntryView === "batch"
              ? "batch-topic"
              : "quick-paste",
          message: __(
            "Enter a topic to generate an article.",
            "gpt3-ai-content-generator"
          ),
        };
      }
      return { isValid: true };
    }

    if (mode === "csv") {
      const csvDataHolder = document.getElementById(
        "aipkit_cw_csv_data_holder"
      );
      const csvData = csvDataHolder ? csvDataHolder.value.trim() : "";
      if (!csvData) {
        return {
          isValid: false,
          message: __(
            "Please upload and parse a CSV file first.",
            "gpt3-ai-content-generator"
          ),
        };
      }
      return { isValid: true };
    }

    if (mode === "rss") {
      const feeds = getLines(getFieldValue(form, "rss_feeds"));
      const validFeeds = feeds.filter(isValidUrl);
      if (!validFeeds.length) {
        return {
          isValid: false,
          message: __(
            "Please enter at least one valid RSS feed URL.",
            "gpt3-ai-content-generator"
          ),
        };
      }
      return { isValid: true };
    }

    if (mode === "url") {
      const urls = getLines(getFieldValue(form, "url_list"));
      const validUrls = urls.filter(isValidUrl);
      if (!validUrls.length) {
        return {
          isValid: false,
          message: __(
            "Please enter at least one valid website URL.",
            "gpt3-ai-content-generator"
          ),
        };
      }
      return { isValid: true };
    }

    if (mode === "gsheets") {
      const sheetId = getFieldValue(form, "gsheets_sheet_id");
      const credentials = getFieldValue(form, "gsheets_credentials");
      if (!sheetId || !credentials) {
        return {
          isValid: false,
          message: __(
            "Please provide a Google Sheet ID and credentials.",
            "gpt3-ai-content-generator"
          ),
        };
      }
      if (!hasVerifiedGsheets(form)) {
        return {
          isValid: false,
          message: __(
            "Verify access before generating.",
            "gpt3-ai-content-generator"
          ),
        };
      }
      return { isValid: true };
    }

    return { isValid: true };
  }

  /**
   * Handles creating an Automated Task from the Content Writer UI.
   * @param {HTMLFormElement} form The main content writer form element.
   * @param {HTMLButtonElement} createButton The button that triggered the action.
   * @param {HTMLElement} statusDiv The element to display status messages.
   */
  async function aipkit_createContentWriterTask(form, createButton, statusDiv) {
    if (typeof window.aipkit_applyContentWriterSeoFeatureGate === "function") {
      window.aipkit_applyContentWriterSeoFeatureGate(form);
    }

    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    if (typeof window.aipkit_normalizeContentWriterSeoConfig === "function") {
      Object.assign(data, window.aipkit_normalizeContentWriterSeoConfig(data));
    }
    const openaiVsSelect = form.elements["openai_vector_store_ids[]"];
    data.openai_vector_store_ids = openaiVsSelect
      ? Array.from(openaiVsSelect.selectedOptions).map((opt) => opt.value)
      : [];
    delete data["openai_vector_store_ids[]"];
    const googleStoresSelect = form.elements["google_file_search_store_names[]"];
    data.google_file_search_store_names = googleStoresSelect
      ? Array.from(googleStoresSelect.selectedOptions).map((option) => option.value)
      : [];
    delete data["google_file_search_store_names[]"];
    const modeSelect = document.getElementById("aipkit_cw_mode_select");
    let activeMode = modeSelect ? modeSelect.value : "task";
    if (activeMode === "single") {
      activeMode = "task";
    }
    data.cw_generation_mode = activeMode;

    if (activeMode === "task" && getCurrentTaskEntryView() === "single") {
      data.schedule_mode = "immediate";
      data.smart_schedule_start_datetime = "";
      data.smart_schedule_interval_value = "";
      data.smart_schedule_interval_unit = "hours";
    }

    const validationStatus = document.getElementById(
      "aipkit_cw_action_validation"
    );
    const validation = aipkit_validateContentWriterInputs(form, activeMode);
    if (!validation.isValid) {
      aipkit_clearContentWriterValidationMessage(statusDiv);
      aipkit_clearContentWriterValidationMessage(validationStatus);
      aipkit_clearContentWriterTopicValidation();
      aipkit_clearContentWriterManualTopicValidation();
      aipkit_showContentWriterValidationError(
        validationStatus,
        validation.message,
        validation.field
      );
      return;
    }
    aipkit_clearContentWriterValidationMessage(statusDiv);
    aipkit_clearContentWriterValidationMessage(validationStatus);
    aipkit_clearContentWriterTopicValidation();
    aipkit_clearContentWriterManualTopicValidation();

    // Get content title from the correct source based on active tab
    if (activeMode === "csv") {
      const csvDataHolder = document.getElementById(
        "aipkit_cw_csv_data_holder"
      );
      data.content_title = csvDataHolder ? csvDataHolder.value : "";
    } else if (activeMode === "url") {
      data.url_list = form.elements["url_list"]?.value || "";
      data.content_title = data.url_list; // For validation purposes
    } else if (activeMode === "task") {
      // This is bulk mode
      data.content_title = form.elements["content_title_bulk"]
        ? form.elements["content_title_bulk"].value
        : "";
    }

    // --- FIX: Clean up other source-specific title fields to prevent conflicts on the backend. ---
    delete data.content_title_bulk;
    delete data.content_title_csv;
    // We keep `url_list` as it is a distinct field in the config. `content_title` is just for validation in URL mode.
    // --- END FIX ---

    const templateSelect = document.getElementById("aipkit_cw_template_select");
    let templateName = "";
    if (templateSelect && templateSelect.selectedIndex > 0) {
      const selectedOption =
        templateSelect.options[templateSelect.selectedIndex];
      if (selectedOption) templateName = selectedOption.text;
    }

    if (templateName && templateName.trim() !== "") {
      data.task_name = templateName.trim();
    } else {
      const firstTitleLine = (
        data.content_title ||
        data.rss_feeds ||
        data.gsheets_sheet_id ||
        data.url_list ||
        ""
      )
        .split("\n")[0]
        .trim();
      data.task_name =
        firstTitleLine ||
        __("Automated Content Writing Task", "gpt3-ai-content-generator");
    }

    if (typeof window.aipkit_setLoadingStateOpenAI === "function") {
      window.aipkit_setLoadingStateOpenAI(
        createButton,
        true,
        __("Generate", "gpt3-ai-content-generator"),
        __("Creating task…", "gpt3-ai-content-generator")
      );
    } else {
      createButton.disabled = true;
      createButton.textContent = __("Creating task…", "gpt3-ai-content-generator");
    }
    try {
      const nonceInput = document.getElementById("aipkit_content_writer_nonce");
      data._ajax_nonce = nonceInput ? nonceInput.value : "";
      if (!data._ajax_nonce)
        throw new Error(
          __("Security token missing for task creation.", "gpt3-ai-content-generator")
        );

      await window.aipkit_apiRequest(
        "aipkit_content_writer_create_task",
        data
      );
      if (statusDiv) {
        statusDiv.textContent = "";
        statusDiv.className = "aipkit_cw_status_badge";
      }

      if (typeof window.aipkit_invalidateModuleCache === "function") {
        window.aipkit_invalidateModuleCache("autogpt");
      }
      if (typeof window.aipkit_loadModule === "function") {
        window.aipkit_loadModule("autogpt", {
          aipkit_force_refresh: true,
        });
      }
    } catch (error) {
      console.error("Content Writer Create Task Error:", error);
      const manualEntryIssues =
        error?.details?.issues || error?.data?.details?.issues || [];
      const issuesShown =
        form
          .closest("#aipkit_content_writer_container")
          ?.aipkitShowManualEntryIssues?.(
            manualEntryIssues,
            error?.message
          ) || false;
      if (!issuesShown) {
        if (typeof window.aipkit_showGenerationError === "function") {
          window.aipkit_showGenerationError(
            __("Could not create the task.", "gpt3-ai-content-generator"),
            __("Your setup is still here.", "gpt3-ai-content-generator")
          );
        } else if (statusDiv) {
          statusDiv.textContent = __(
            "Could not create the task. Your setup is still here.",
            "gpt3-ai-content-generator"
          );
          statusDiv.className =
            "aipkit_cw_status_badge aipkit_settings_message-error";
        }
      }
    } finally {
      if (typeof window.aipkit_setLoadingStateOpenAI === "function") {
        window.aipkit_setLoadingStateOpenAI(
          createButton,
          false,
          __("Generate", "gpt3-ai-content-generator")
        );
      } else {
        createButton.disabled = false;
        createButton.textContent = __("Generate", "gpt3-ai-content-generator");
      }
    }
  }
  window.aipkit_createContentWriterTask = aipkit_createContentWriterTask;
  window.aipkit_validateContentWriterInputs = aipkit_validateContentWriterInputs;
  window.aipkit_showContentWriterValidationError =
    aipkit_showContentWriterValidationError;
  window.aipkit_clearContentWriterValidationMessage =
    aipkit_clearContentWriterValidationMessage;
  window.aipkit_clearContentWriterTopicValidation =
    aipkit_clearContentWriterTopicValidation;
  window.aipkit_clearContentWriterManualTopicValidation =
    aipkit_clearContentWriterManualTopicValidation;
})();
