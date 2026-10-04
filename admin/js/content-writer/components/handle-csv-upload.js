/**
 * AIPKit CSV Upload Handler (Reusable)
 * Manages the client-side logic for uploading and parsing a CSV file.
 * Used by Content Writer and AutoGPT modules.
 * Redesigned with aesthetics, chunking, and choice overload reduction principles.
 */
(function () {
  "use strict";

  /**
   * Updates the UI state for the new CSV pane design.
   * @param {HTMLElement} container - The form-group container.
   * @param {string} state - 'idle' | 'loading' | 'success' | 'error'
   * @param {string} message - The status message to display.
   * @param {string} fileName - The uploaded file name (optional).
   */
  function updateCsvUI(container, state, message, fileName) {
    const uploadZone = container.querySelector("[data-csv-upload-zone]");
    const statusContainer = container.querySelector("[data-csv-status]");
    const statusCard = container.querySelector("[data-csv-status-card]");
    const statusIcon = container.querySelector("[data-csv-status-icon]");
    const fileNameEl = container.querySelector("[data-csv-file-name]");
    const messageEl = container.querySelector("[data-csv-message]");
    const helpContent = container.querySelector("[data-csv-help]");
    const isContentWriter = !!container.closest(
      "#aipkit_content_writer_container"
    );

    if (!statusContainer || !statusCard) {
      // Fallback for legacy UI
      return;
    }

    if (state === "idle") {
      if (uploadZone) uploadZone.classList.remove("is-hidden");
      statusContainer.hidden = true;
      if (helpContent) helpContent.hidden = false;
      if (isContentWriter) resetCsvPreview(container);
      return;
    }

    // Show status, hide upload zone
    if (uploadZone) uploadZone.classList.add("is-hidden");
    statusContainer.hidden = false;
    if (helpContent) {
      helpContent.hidden = state === "loading" || state === "success";
    }

    // Update status card appearance based on state
    statusCard.dataset.status = state;

    // Update icon
    if (statusIcon) {
      let iconClass = "dashicons-yes-alt";
      if (state === "loading") iconClass = "dashicons-update";
      if (state === "error") iconClass = "dashicons-warning";
      statusIcon.innerHTML = `<span class="dashicons ${iconClass}" aria-hidden="true"></span>`;
      
      // Add spin animation for loading state
      const icon = statusIcon.querySelector(".dashicons");
      if (icon && state === "loading") {
        icon.style.animation = "aipkit_csv_spin 1s linear infinite";
      }
    }

    // Update file name
    if (fileNameEl && fileName) {
      fileNameEl.textContent = fileName;
    } else if (fileNameEl) {
      fileNameEl.textContent = "";
    }

    // Update message
    if (messageEl) {
      messageEl.textContent = message || "";
    }

    if (isContentWriter && state !== "success") {
      resetCsvPreview(container);
    }
  }

  function countParsedTopics(value) {
    return String(value || "")
      .split(/\r?\n/)
      .map((line) => line.trim())
      .filter(Boolean).length;
  }

  function notifyDataHolder(dataHolder) {
    if (!dataHolder) return;
    dataHolder.dispatchEvent(new Event("input", { bubbles: true }));
    dataHolder.dispatchEvent(new Event("change", { bubbles: true }));
  }

  function clearParsedData(dataHolder) {
    if (!dataHolder) return;
    dataHolder.value = "";
    notifyDataHolder(dataHolder);
  }

  function resetCsvPreview(container) {
    const toggle = container.querySelector("[data-csv-preview-toggle]");
    const preview = container.querySelector("[data-csv-preview]");
    const body = container.querySelector("[data-csv-preview-body]");
    if (toggle) {
      toggle.hidden = true;
      toggle.setAttribute("aria-expanded", "false");
    }
    if (preview) preview.hidden = true;
    if (body) body.replaceChildren();
  }

  function renderCsvPreview(container, parsedData) {
    if (!container.closest("#aipkit_content_writer_container")) return;

    const toggle = container.querySelector("[data-csv-preview-toggle]");
    const label = container.querySelector("[data-csv-preview-label]");
    const preview = container.querySelector("[data-csv-preview]");
    const body = container.querySelector("[data-csv-preview-body]");
    if (!toggle || !preview || !body) return;

    const rows = String(parsedData || "")
      .split(/\r?\n/)
      .map((line) => line.trim())
      .filter(Boolean)
      .slice(0, 5)
      .map((line) => line.split("|").map((value) => value.trim()));

    resetCsvPreview(container);
    if (!rows.length) return;

    rows.forEach((values) => {
      const row = document.createElement("tr");
      for (let index = 0; index < 6; index += 1) {
        const cell = document.createElement("td");
        cell.textContent = values[index] || "—";
        row.appendChild(cell);
      }
      body.appendChild(row);
    });

    if (label) {
      label.textContent = `Preview first ${rows.length} ${
        rows.length === 1 ? "row" : "rows"
      }`;
    }
    toggle.hidden = false;
  }

  function restoreCsvUIFromParsedData(container, parsedData, options = {}) {
    if (!container) {
      return;
    }

    const topicCount = countParsedTopics(parsedData);
    if (!topicCount) {
      updateCsvUI(container, "idle", null, null);
      return;
    }

    const isAutogpt =
      options.isAutogpt === true || !!container.closest("#aipkit_autogpt_container");
    const fileName =
      options.fileName || (isAutogpt ? "Saved CSV data" : "Parsed CSV data");
    const message =
      options.message ||
      (isAutogpt
        ? `Found ${topicCount} ${topicCount === 1 ? "topic" : "topics"}. Ready to save the task.`
        : `${topicCount} ${topicCount === 1 ? "topic" : "topics"} found.`);

    updateCsvUI(container, "success", message, fileName);
    renderCsvPreview(container, parsedData);
    if (
      typeof window.aipkit_refreshContentWriterGenerateLabel === "function"
    ) {
      window.aipkit_refreshContentWriterGenerateLabel();
    }
  }

  /**
   * Core logic for handling the file change event.
   * @param {Event} event - The change event from the file input.
   */
  async function handleFileChange(event) {
    const fileInput = event.target;
    const container = fileInput.closest(".aipkit_csv_import_container") || fileInput.closest(".aipkit_form-group");
    if (!container) return;

    // New UI elements
    const hasNewUI = container.querySelector("[data-csv-status]") !== null;
    
    // Legacy UI elements
    const statusDiv = container.querySelector(".aipkit_csv_analysis_results");
    const dataHolder = container.querySelector(".aipkit_csv_data_holder");

    // Nonce can come from either Content Writer form or AutoGPT config object
    const cwNonceField = document.getElementById("aipkit_content_writer_nonce");
    const autogptNonce = (window.aipkit_automated_tasks_config || {})
      .nonce_manage_tasks;
    const nonce = cwNonceField ? cwNonceField.value : autogptNonce;

    // A newly selected file must earn readiness again. Clear any previously
    // parsed rows before validating or parsing the replacement file so stale
    // data cannot keep Content Writer's Generate action enabled.
    clearParsedData(dataHolder);

    if (!dataHolder || !nonce) {
      console.error(
        "CSV Handler: Missing data holder or nonce field."
      );
      if (hasNewUI) {
        updateCsvUI(container, "error", "Error: A required component is missing.", null);
      } else if (statusDiv) {
        statusDiv.textContent = "Error: A required UI component or security token is missing.";
        statusDiv.className = "aipkit_form-help aipkit_settings_message-error";
      }
      return;
    }

    const file = fileInput.files[0];
    if (!file) {
      if (hasNewUI) {
        updateCsvUI(container, "idle", null, null);
      } else if (statusDiv) {
        statusDiv.textContent = "Please select a CSV file first.";
        statusDiv.className = "aipkit_form-help aipkit_settings_message-error";
      }
      return;
    }

    if (!file.name.toLowerCase().endsWith(".csv")) {
      if (hasNewUI) {
        updateCsvUI(container, "error", "Invalid file type. Please upload a .csv file.", file.name);
      } else if (statusDiv) {
        statusDiv.textContent = "Invalid file type. Please upload a .csv file.";
        statusDiv.className = "aipkit_form-help aipkit_settings_message-error";
      }
      return;
    }

    // Show loading state
    if (hasNewUI) {
      updateCsvUI(container, "loading", "Parsing CSV...", file.name);
    } else if (statusDiv) {
      statusDiv.textContent = "Parsing CSV...";
      statusDiv.className = "aipkit_form-help aipkit_settings_message-info";
    }

    const formData = new FormData();
    formData.append("file", file);
    formData.append("_ajax_nonce", nonce);
    // Add action to the FormData object for admin-ajax.php
    formData.append("action", "aipkit_content_writer_parse_csv");

    try {
      // Use fetch directly as aipkit_apiRequest does not support FormData for file uploads
      const response = await fetch(window.aipkit_dashboard.ajaxurl, {
        method: "POST",
        body: formData,
        credentials: "same-origin",
      });

      if (!response.ok) {
        // Try to read JSON error if provided by wp_send_json_error
        let errorMsg = `Server returned status ${response.status}`;
        try {
          const maybeJson = await response.json();
          if (maybeJson && maybeJson.data && maybeJson.data.message) {
            errorMsg = maybeJson.data.message;
          }
        } catch (_) {
          // swallow, keep default message
        }
        throw new Error(errorMsg);
      }
      const json = await response.json();
      if (!json.success) {
        throw new Error(json.data?.message || "Unknown server error.");
      }
      const responseData = json.data;
      if (responseData.tasks_found > 0) {
        dataHolder.value = responseData.formatted_data;
        notifyDataHolder(dataHolder);
        const isAutogpt = fileInput.id === "aipkit_task_cw_csv_file_input"
          || !!fileInput.closest("#aipkit_autogpt_container");
        const successMsg = isAutogpt
          ? `Found ${responseData.tasks_found} ${responseData.tasks_found === 1 ? 'topic' : 'topics'}. Ready to save the task.`
          : `${responseData.tasks_found} ${responseData.tasks_found === 1 ? 'topic' : 'topics'} found.`;
        if (hasNewUI) {
          updateCsvUI(container, "success", successMsg, file.name);
          renderCsvPreview(container, responseData.formatted_data);
        } else if (statusDiv) {
          statusDiv.textContent = `Success! Found ${responseData.tasks_found} topics in the CSV file. Ready.`;
          statusDiv.className = "aipkit_form-help aipkit_settings_message-success";
        }
      } else {
        dataHolder.value = "";
        notifyDataHolder(dataHolder);
        const errorMsg = "No valid topics found in the CSV file.";
        if (hasNewUI) {
          updateCsvUI(container, "error", errorMsg, file.name);
        } else if (statusDiv) {
          statusDiv.textContent = errorMsg;
          statusDiv.className = "aipkit_form-help aipkit_settings_message-error";
        }
      }
    } catch (error) {
      dataHolder.value = "";
      notifyDataHolder(dataHolder);
      const errorMsg = error.message || "Failed to parse CSV file.";
      if (hasNewUI) {
        updateCsvUI(container, "error", errorMsg, file.name);
      } else if (statusDiv) {
        statusDiv.textContent = `Error: ${errorMsg}`;
        statusDiv.className = "aipkit_form-help aipkit_settings_message-error";
      }
      console.error("CSV Parse Error:", error);
    }
  }

  /**
   * Handles clearing the uploaded file.
   * @param {Event} event - The click event.
   */
  function handleClearFile(event) {
    const button = event.target.closest("[data-csv-clear]");
    if (!button) return;

    const container = button.closest(".aipkit_csv_import_container");
    if (!container) return;

    const fileInput = container.querySelector("input[type='file']");
    const dataHolder = container.querySelector(".aipkit_csv_data_holder");

    if (fileInput) {
      fileInput.value = "";
    }
    if (dataHolder) {
      dataHolder.value = "";
      notifyDataHolder(dataHolder);
    }

    updateCsvUI(container, "idle", null, null);
  }

  function handlePreviewToggle(event) {
    const toggle = event.target.closest("[data-csv-preview-toggle]");
    if (!toggle) return;

    const container = toggle.closest(".aipkit_csv_import_container");
    const preview = container?.querySelector("[data-csv-preview]");
    if (!preview) return;

    const shouldOpen = preview.hidden;
    preview.hidden = !shouldOpen;
    toggle.setAttribute("aria-expanded", shouldOpen ? "true" : "false");
  }

  /**
   * Sets up drag and drop handlers for the upload zone.
   * @param {HTMLElement} dropzone - The dropzone element.
   */
  function setupDragDrop(dropzone) {
    if (!dropzone || dropzone.dataset.dragDropAttached === "true") return;

    const preventDefaults = (e) => {
      e.preventDefault();
      e.stopPropagation();
    };

    const highlight = () => dropzone.classList.add("is-dragover");
    const unhighlight = () => dropzone.classList.remove("is-dragover");

    const handleDrop = (e) => {
      preventDefaults(e);
      unhighlight();

      const dt = e.dataTransfer;
      const files = dt.files;
      
      if (files.length > 0) {
        const fileInput = dropzone.querySelector("input[type='file']");
        if (fileInput) {
          // Create a new DataTransfer to set the files
          const dataTransfer = new DataTransfer();
          dataTransfer.items.add(files[0]);
          fileInput.files = dataTransfer.files;
          // Trigger change event
          fileInput.dispatchEvent(new Event("change", { bubbles: true }));
        }
      }
    };

    ["dragenter", "dragover", "dragleave", "drop"].forEach((eventName) => {
      dropzone.addEventListener(eventName, preventDefaults, false);
    });

    ["dragenter", "dragover"].forEach((eventName) => {
      dropzone.addEventListener(eventName, highlight, false);
    });

    ["dragleave", "drop"].forEach((eventName) => {
      dropzone.addEventListener(eventName, unhighlight, false);
    });

    dropzone.addEventListener("drop", handleDrop, false);
    dropzone.dataset.dragDropAttached = "true";
  }

  /**
   * Initializes the CSV file input listener for the Content Writer module.
   */
  function aipkit_initCsvUploadHandler() {
    const fileInput = document.getElementById("aipkit_cw_csv_file_input");
    if (!fileInput) return; // Not on the right tab
    if (fileInput.dataset.listenerAttached === "true") return;

    fileInput.addEventListener("change", handleFileChange);
    fileInput.dataset.listenerAttached = "true";

    // Setup new UI handlers
    const container = fileInput.closest(".aipkit_csv_import_container");
    if (container) {
      // Drag and drop
      const dropzone = container.querySelector(".aipkit_csv_dropzone");
      setupDragDrop(dropzone);

      // Clear button
      const clearBtn = container.querySelector("[data-csv-clear]");
      if (clearBtn && clearBtn.dataset.listenerAttached !== "true") {
        clearBtn.addEventListener("click", handleClearFile);
        clearBtn.dataset.listenerAttached = "true";
      }

      const previewToggle = container.querySelector(
        "[data-csv-preview-toggle]"
      );
      if (
        previewToggle &&
        previewToggle.dataset.listenerAttached !== "true"
      ) {
        previewToggle.addEventListener("click", handlePreviewToggle);
        previewToggle.dataset.listenerAttached = "true";
      }
    }

    if (
      typeof window.aipkit_refreshContentWriterGenerateLabel === "function"
    ) {
      window.aipkit_refreshContentWriterGenerateLabel();
    }
  }

  /**
   * Initializes the CSV file input listener for the AutoGPT module.
   */
  function aipkit_initTaskCsvUploadHandler() {
    const fileInput = document.getElementById("aipkit_task_cw_csv_file_input");
    if (!fileInput) return; // Not in the right section
    if (fileInput.dataset.listenerAttached === "true") return;

    fileInput.addEventListener("change", handleFileChange);
    fileInput.dataset.listenerAttached = "true";

    const container = fileInput.closest(".aipkit_csv_import_container");
    if (container) {
      const dropzone = container.querySelector(".aipkit_csv_dropzone");
      setupDragDrop(dropzone);

      const clearBtn = container.querySelector("[data-csv-clear]");
      if (clearBtn && clearBtn.dataset.listenerAttached !== "true") {
        clearBtn.addEventListener("click", handleClearFile);
        clearBtn.dataset.listenerAttached = "true";
      }
    }
  }

  // Expose the initializers globally
  window.aipkit_initCsvUploadHandler = aipkit_initCsvUploadHandler;
  window.aipkit_initTaskCsvUploadHandler = aipkit_initTaskCsvUploadHandler;
  window.aipkit_restoreCsvUIFromParsedData = restoreCsvUIFromParsedData;
})();
