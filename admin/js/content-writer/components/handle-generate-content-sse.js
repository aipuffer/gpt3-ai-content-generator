/**
 * AIPKit Content Writer - Handle SSE Content Generation
 * Manages the AJAX call to initialize the stream and starts the EventSource orchestrator.
 * UPDATED: Now calls the global orchestrator function `aipkit_handleContentWriterEventSource` and no longer contains its logic.
 */
import {
  applyContentWriterSeoFormFields,
  applyHostedKnowledgeStoreFields,
} from "../utils/form-data-fields.js";

(function () {
  "use strict";

  /**
   * Main function to initiate the SSE process.
   * This function gets the cache key and then calls the main event source handler.
   * @param {object} eventSourceInstanceRefFromMain - The shared reference object for the EventSource instance.
   */
  async function aipkit_handleGenerateContentSSE(
    eventSourceInstanceRefFromMain
  ) {
    const form = document.getElementById("aipkit_content_writer_form");
    const generateBtn = document.getElementById(
      "aipkit_content_writer_generate_btn"
    );
    const statusDiv = document.getElementById(
      "aipkit_content_writer_form_status"
    );
    const contentArea = document.getElementById(
      "aipkit_cw_generated_content_area"
    );
    const copyBtn = document.getElementById("aipkit_content_writer_copy_btn");
    const clearBtn = document.getElementById("aipkit_content_writer_clear_btn");
    const outputActions = document.querySelector(
      ".aipkit_content_writer_output_actions"
    );
    const formatApiError = function (error) {
      if (!error) {
        return "Error";
      }
      const message = error.message || "Error";
      const details = error.details || error.data?.details;
      const provider = details?.provider;
      const model = details?.model;
      if (provider || model) {
        return `${message} (${[provider, model].filter(Boolean).join(" \u00b7 ")})`;
      }
      return message;
    };
    const isAbortError =
      typeof window.aipkit_isContentWriterAbortError === "function"
        ? window.aipkit_isContentWriterAbortError
        : function (error) {
            return Boolean(
              error && (error.name === "AbortError" || error.code === "aborted")
            );
          };
    const applyResolvedKeywordResponse = function (payload) {
      if (!payload || !payload.resolved_focus_keyword) {
        return;
      }

      const resolvedKeywords = String(payload.resolved_keywords || "").trim();
      if (!resolvedKeywords) {
        return;
      }

      if (payload.resolved_keyword_source === "inline") {
        const titleInput = document.getElementById(
          "aipkit_content_writer_title"
        );
        if (titleInput && payload.resolved_content_title) {
          titleInput.value = payload.resolved_content_title;
        }
        return;
      }

      const keywordField = form.elements["content_keywords"];
      if (keywordField) {
        keywordField.value = resolvedKeywords;
      }
    };

    if (
      !form ||
      !generateBtn ||
      !statusDiv ||
      !contentArea ||
      !copyBtn ||
      !clearBtn ||
      !outputActions
    ) {
      console.error("Handle SSE: Missing UI elements.");
      return;
    }

    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    data.schedule_mode = "immediate";
    data.smart_schedule_start_datetime = "";
    data.smart_schedule_interval_value = "";
    data.smart_schedule_interval_unit = "hours";
    applyContentWriterSeoFormFields(data, form);
    applyHostedKnowledgeStoreFields(data, form);
    // Reuse an existing conversation UUID if we already have one (e.g., after Title)
    if (!data.conversation_uuid) {
      const existingConv =
        window.aipkit_current_conversation_uuid ||
        (function () {
          try {
            return sessionStorage.getItem("aipkit_current_conversation_uuid");
          } catch (e) {
            return null;
          }
        })();
      if (existingConv) data.conversation_uuid = existingConv;
    }

    if (!data.content_title) {
      // Clear general status to avoid duplicate error messages
      statusDiv.textContent = "";
      statusDiv.className = "aipkit_cw_status_badge";
      // Note: No step indicator update needed here as this is a validation error before generation starts
      return;
    }

    const mainNonceInput = document.getElementById(
      "aipkit_content_writer_nonce"
    );
    if (mainNonceInput && mainNonceInput.value)
      data._ajax_nonce = mainNonceInput.value;
    else {
      // Clear general status to avoid duplicate error messages
      statusDiv.textContent = "";
      statusDiv.className = "aipkit_cw_status_badge";
      // Note: No step indicator update needed here as this is a validation error before generation starts
      return;
    }

    if (typeof window.aipkit_setContentWriterStopMode === "function") {
      window.aipkit_setContentWriterStopMode(true);
    }
    outputActions.style.display = "none";
    if (typeof window.aipkit_resetSaveAsPostStatus === "function") {
      window.aipkit_resetSaveAsPostStatus();
    }
    if (typeof window.aipkit_refreshContentWriterSmartSeoLockedCard === "function") {
      window.aipkit_refreshContentWriterSmartSeoLockedCard({ forceHide: true });
    }

    if (contentArea) {
      contentArea.innerHTML = "";
    }
    if (typeof window.aipkit_setContentWriterCanvasState === "function") {
      window.aipkit_setContentWriterCanvasState("loading", {
        title: "Preparing draft",
        description:
          "Opening the manuscript canvas and connecting the live writing stream.",
        hasContent: false,
      });
    }

    copyBtn.disabled = true;
    clearBtn.disabled = true;
    if (typeof window.aipkit_disableSaveAsPostButton === "function") {
      // Disable Save as Post initially
      window.aipkit_disableSaveAsPostButton();
    }
    statusDiv.textContent = "";
    statusDiv.className = "aipkit_cw_status_badge";

    try {
      data.content_title = document.getElementById(
        "aipkit_content_writer_title"
      ).value;

      const initController =
        typeof window.aipkit_beginContentWriterAbortableRequest === "function"
          ? window.aipkit_beginContentWriterAbortableRequest("content")
          : new AbortController();
      let response;
      try {
        response = await window.aipkit_apiRequest(
          "aipkit_content_writer_init_stream",
          data,
          {
            signal: initController.signal,
            abortMessage: "Generation stopped",
          }
        );
      } finally {
        if (
          typeof window.aipkit_finishContentWriterAbortableRequest ===
          "function"
        ) {
          window.aipkit_finishContentWriterAbortableRequest(initController);
        } else if (
          window.aipkit_cw_singleRequestController === initController
        ) {
          window.aipkit_cw_singleRequestController = null;
        }
      }
	      if (!response.cache_key)
	        throw new Error(response.message || "Failed to initialize stream.");
	      applyResolvedKeywordResponse(response);

	      document.getElementById("aipkit_content_writer_stream_cache_key").value =
        response.cache_key;
      // Save conversation UUID for logging of follow-up steps
      if (response.conversation_uuid) {
        window.aipkit_current_conversation_uuid = response.conversation_uuid;
        try { sessionStorage.setItem("aipkit_current_conversation_uuid", response.conversation_uuid); } catch (e) {}
      }

      // Call the global orchestrator from content-writer-eventsource-handler.js
      if (typeof window.aipkit_handleContentWriterEventSource === "function") {
        window.aipkit_handleContentWriterEventSource(
          response.cache_key,
          eventSourceInstanceRefFromMain
        );
      } else {
        throw new Error(
          "Stream orchestrator function (aipkit_handleContentWriterEventSource) not found."
        );
      }
    } catch (error) {
      if (
        isAbortError(error) &&
        typeof window.aipkit_isContentWriterStopRequested === "function" &&
        window.aipkit_isContentWriterStopRequested()
      ) {
        if (typeof window.aipkit_finalizeStream === "function") {
          window.aipkit_finalizeStream(eventSourceInstanceRefFromMain);
        }
        return;
      }
      console.error("Content Writer Init Stream Error:", error);
      const formattedError = formatApiError(error);
      if (contentArea) {
        contentArea.innerHTML = "";
      }
      if (typeof window.aipkit_setContentWriterCanvasState === "function") {
        window.aipkit_setContentWriterCanvasState("error", {
          title: "Could not start the draft",
          description: formattedError,
          hasContent: false,
        });
      }
      if (typeof window.aipkit_showGenerationError === "function") {
        window.aipkit_showGenerationError();
      }
      // Keep the detailed provider error in the run surface; the scoped toast
      // only reassures the user that their input was retained.
      if (typeof window.aipkit_updateCwGenerationStatus === "function")
        window.aipkit_updateCwGenerationStatus(
          "content",
          "error",
          formattedError
        );
      if (typeof window.aipkit_finalizeStream === "function") {
        window.aipkit_finalizeStream(eventSourceInstanceRefFromMain);
      }
    }
  }

  // Expose the initiator function
  window.aipkit_handleGenerateContentSSE = aipkit_handleGenerateContentSSE;
})();
