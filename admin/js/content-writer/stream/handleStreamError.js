/**
 * AIPKit Content Writer Stream - Error Event Handler
 * Handles the custom 'error' event from the stream.
 */
(function () {
  "use strict";

  /**
   * Processes the 'error' event from the SSE stream.
   * @param {Event} event - The 'error' event.
   * @param {object} state - An object containing state: { isDone: boolean }.
   * @param {HTMLElement} statusDiv - The status message element.
   * @param {HTMLElement} outputContainer - The container element to apply error styles to.
   * @param {object} esInstanceRef - The reference to the EventSource instance.
   */
  function aipkit_stream_handleError(
    event,
    state,
    statusDiv,
    outputContainer,
    esInstanceRef
  ) {
    if (state.isDone) {
      return;
    }

    // Check if the stream was stopped by the user via the Stop button.
    if (esInstanceRef.current && esInstanceRef.current.userStopped === true) {
      // The stop request was intentional. Finalize the stream and let the
      // shared reset path restore the UI.
      if (typeof window.aipkit_finalizeStream === "function") {
        window.aipkit_finalizeStream(esInstanceRef);
      }
      return; // Important: Exit here to prevent generic error handling.
    }

    let errorMsg = "Error during streaming.";
    if (event.data) {
      try {
        const errData = JSON.parse(event.data);
        errorMsg = errData.error || errorMsg;
      } catch (e) {
        // Data might not be JSON, ignore parse error.
      }
    }
    console.error("Stream Error Handler: Error event received:", event);

    // Clear any previous status messages to avoid duplicate error displays
    if (statusDiv) {
      statusDiv.textContent = "";
      statusDiv.className = "aipkit_cw_status_badge";
    }

    const hasContent =
      typeof window.aipkit_hasContentWriterCanvasContent === "function"
        ? window.aipkit_hasContentWriterCanvasContent()
        : false;
    if (typeof window.aipkit_setContentWriterCanvasState === "function") {
      window.aipkit_setContentWriterCanvasState("error", {
        title: hasContent ? "Generation interrupted" : "Draft generation failed",
        description: errorMsg,
        hasContent,
      });
    }
    if (typeof window.aipkit_showGenerationError === "function") {
      window.aipkit_showGenerationError(
        hasContent
          ? "Generation stopped before finishing."
          : "Could not generate the draft."
      );
    }

    // Display error only in the step indicator (primary error display)
    if (typeof window.aipkit_updateCwGenerationStatus === "function") {
      window.aipkit_updateCwGenerationStatus(
        "content",
        "error",
        hasContent ? "Interrupted" : "Failed"
      );
    }

    if (typeof window.aipkit_disableSaveAsPostButton === "function") {
      window.aipkit_disableSaveAsPostButton();
    }

    // Finalize the stream (closes connection, resets buttons)
    if (typeof window.aipkit_finalizeStream === "function") {
      window.aipkit_finalizeStream(esInstanceRef);
    }
  }

  window.aipkit_stream_handleError = aipkit_stream_handleError;
})();
