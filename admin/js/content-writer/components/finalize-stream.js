/**
 * AIPKit Content Writer - EventSource Finalize Stream
 * Helper function to finalize the EventSource stream and reset UI elements.
 * Keeps completed or interrupted output recoverable without reloading the form.
 */
(function () {
  "use strict";

  /**
   * Finalizes the SSE stream: closes EventSource and resets buttons.
   * Relies on `aipkit_resetContentWriterButtons` being available globally.
   */
  function aipkit_finalizeStream(eventSourceInstanceRef) {
    // Pass the EventSource instance directly
    if (eventSourceInstanceRef.current) {
      eventSourceInstanceRef.current.close();
      eventSourceInstanceRef.current = null;
    }

    // Check content area for output (updated selector for new chunked UI)
    const contentArea = document.getElementById(
      "aipkit_cw_generated_content_area"
    );
    const outputActions = document.querySelector(
      ".aipkit_content_writer_output_actions"
    );
    const copyBtn = document.getElementById("aipkit_content_writer_copy_btn");
    const clearBtn = document.getElementById("aipkit_content_writer_clear_btn");

    // Check if we have actual content (not just placeholder)
    const hasContent =
      typeof window.aipkit_hasContentWriterCanvasContent === "function"
        ? window.aipkit_hasContentWriterCanvasContent()
        : Boolean(
            contentArea &&
              (contentArea.textContent.trim().length > 0 ||
                !!contentArea.querySelector(
                  "img, h1, h2, h3, h4, h5, h6, p, ul, ol, li, blockquote, pre, table, figure"
                ))
          );
    const currentCanvasState =
      typeof window.aipkit_getContentWriterCanvasState === "function"
        ? window.aipkit_getContentWriterCanvasState()
        : "empty";
    const shouldKeepPreviewVisible =
      hasContent || currentCanvasState === "stopped" || currentCanvasState === "error";

    if (outputActions) {
      outputActions.style.display = shouldKeepPreviewVisible ? "flex" : "none";
    }
    if (copyBtn) copyBtn.disabled = !hasContent;
    if (clearBtn) clearBtn.disabled = !shouldKeepPreviewVisible;
    if (typeof window.aipkit_resetContentWriterOutputSkeletons === "function") {
      window.aipkit_resetContentWriterOutputSkeletons();
    }

    if (typeof window.aipkit_resetContentWriterButtons === "function") {
      window.aipkit_resetContentWriterButtons();
    } else {
      console.error(
        "Content Writer Finalize Stream: resetButtons function not found."
      );
    }

    if (typeof window.aipkit_setContentWriterSingleRunState === "function") {
      window.aipkit_setContentWriterSingleRunState(false);
    }
    if (
      typeof window.aipkit_setContentWriterSinglePreviewState === "function"
    ) {
      window.aipkit_setContentWriterSinglePreviewState(shouldKeepPreviewVisible);
    }

    if (hasContent) {
      if (typeof window.aipkit_setContentWriterCanvasState === "function") {
        if (currentCanvasState === "stopped") {
          window.aipkit_setContentWriterCanvasState("stopped", {
            hasContent: true,
          });
        } else if (currentCanvasState === "error") {
          window.aipkit_setContentWriterCanvasState("error", {
            hasContent: true,
          });
        } else {
          window.aipkit_setContentWriterCanvasState("ready", {
            hasContent: true,
          });
        }
      }
      // Update meta chunk visibility
      if (typeof window.aipkit_updateMetaChunkVisibility === "function") {
        window.aipkit_updateMetaChunkVisibility();
      }

      // Enable Save as Post button
      if (typeof window.aipkit_enableSaveAsPostButton === "function") {
        window.aipkit_enableSaveAsPostButton();
      }
    } else {
      if (typeof window.aipkit_setContentWriterCanvasState === "function") {
        if (currentCanvasState === "stopped") {
          window.aipkit_setContentWriterCanvasState("stopped", {
            hasContent: false,
          });
        } else if (currentCanvasState === "error") {
          window.aipkit_setContentWriterCanvasState("error", {
            hasContent: false,
          });
        } else {
          window.aipkit_setContentWriterCanvasState("empty", {
            hasContent: false,
          });
        }
      }
      if (typeof window.aipkit_resetSaveAsPostStatus === "function") {
        window.aipkit_resetSaveAsPostStatus();
      }

      if (typeof window.aipkit_disableSaveAsPostButton === "function") {
        window.aipkit_disableSaveAsPostButton();
      }
    }
    if (typeof window.aipkit_syncContentWriterSessionCardState === "function") {
      window.aipkit_syncContentWriterSessionCardState();
    }
  }

  window.aipkit_finalizeStream = aipkit_finalizeStream;
})();
