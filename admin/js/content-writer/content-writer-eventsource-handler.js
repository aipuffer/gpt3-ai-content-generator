/**
 * AIPKit Content Writer - EventSource Handler (Orchestrator)
 * Manages the EventSource connection for streaming content by calling modular components.
 * This file acts as the main entry point for SSE logic.
 */
(function () {
  "use strict";

  // Define the shared reference object. It will be passed to other functions.
  const eventSourceInstanceRef = { current: null };

  /**
   * Sets up and starts the EventSource connection for streaming content.
   * This is the main orchestrator function.
   * @param {string} cacheKey - The key to retrieve the cached prompt and settings.
   * @param {object} esInstanceRef - An object { current: EventSource | null } to store the EventSource instance.
   */
  function aipkit_handleContentWriterEventSource(cacheKey, esInstanceRef) {
    const outputContainer = document.getElementById(
      "aipkit_content_writer_output_display"
    );
    const contentArea = document.getElementById(
      "aipkit_cw_generated_content_area"
    );
    const statusDiv = document.getElementById(
      "aipkit_content_writer_form_status"
    );

    if (!outputContainer || !contentArea || !statusDiv) {
      console.error(
        "EventSource Orchestrator: Missing UI elements for streaming."
      );
      return;
    }

    const requiredFunctions = [
      "aipkit_getMarkdownRenderer",
      "aipkit_stream_setupEventSourceConnection",
      "aipkit_stream_handleMessage",
      "aipkit_stream_handleDone",
      "aipkit_stream_handleError",
      "aipkit_finalizeStream",
      "aipkit_updateCwGenerationStatus",
    ];

    for (const funcName of requiredFunctions) {
      if (typeof window[funcName] !== "function") {
        console.error(
          `EventSource Orchestrator: Missing required function: ${funcName}`
        );
        // Clear general status to avoid duplicate error messages
        if (statusDiv) {
          statusDiv.textContent = "";
          statusDiv.className = "aipkit_cw_status_badge";
        }
        if (typeof window.aipkit_setContentWriterCanvasState === "function") {
          window.aipkit_setContentWriterCanvasState("error", {
            title: "Draft stream is unavailable",
            description: `Missing required handler: ${funcName}`,
            hasContent: false,
          });
        }
        if (typeof window.aipkit_showGenerationError === "function") {
          window.aipkit_showGenerationError();
        }
        if (typeof window.aipkit_finalizeStream === "function") {
          window.aipkit_finalizeStream(esInstanceRef);
        } else if (typeof window.aipkit_resetContentWriterButtons === "function") {
          window.aipkit_resetContentWriterButtons();
        }
        return;
      }
    }

    const state = {
      fullContent: "", // To accumulate content for rendering
      isDone: false,
      pendingRenderHandle: null,
      lastRenderAt: 0,
      hasRenderedFirstDelta: false,
    };

    // UI Prep
    contentArea.innerHTML = "";
    if (typeof window.aipkit_setContentWriterCanvasState === "function") {
      window.aipkit_setContentWriterCanvasState("loading", {
        title: "Connecting live draft",
        description:
          "The stream is opening now. Your manuscript will begin rendering as soon as the first chunk arrives.",
        hasContent: false,
      });
    }
    if (statusDiv) {
      statusDiv.textContent = "";
      statusDiv.className = "aipkit_cw_status_badge";
    }
    window.aipkit_updateCwGenerationStatus("content", "generating");

    try {
      // 1. Setup Connection
      esInstanceRef.current = window.aipkit_stream_setupEventSourceConnection(
        cacheKey,
        esInstanceRef
      );

      // 2. Prepare Renderer
      const markdownRenderer = window.aipkit_getMarkdownRenderer();

      // 3. Attach Event Listeners
      esInstanceRef.current.onopen = function () {
        window.aipkit_updateCwGenerationStatus("content", "generating");
      };

      esInstanceRef.current.onmessage = function (event) {
        window.aipkit_stream_handleMessage(
          event,
          state,
          contentArea,
          markdownRenderer
        );
      };

      esInstanceRef.current.addEventListener("done", function (event) {
        window.aipkit_stream_handleDone(
          event,
          state,
          contentArea,
          markdownRenderer,
          statusDiv,
          esInstanceRef
        );
      });

      esInstanceRef.current.addEventListener("error", function (event) {
        window.aipkit_stream_handleError(
          event,
          state,
          statusDiv,
          outputContainer,
          esInstanceRef
        );
      });
    } catch (e) {
      console.error("EventSource Orchestrator: Error during setup:", e);
      // Clear the general status area to avoid duplicate error messages
      if (statusDiv) {
        statusDiv.textContent = "";
        statusDiv.className = "aipkit_cw_status_badge";
      }
      if (typeof window.aipkit_setContentWriterCanvasState === "function") {
        window.aipkit_setContentWriterCanvasState("error", {
          title: "Could not open the live draft",
          description: e.message || "Connection failed",
          hasContent: false,
        });
      }
      if (typeof window.aipkit_showGenerationError === "function") {
        window.aipkit_showGenerationError();
      }
      // Display error only in the step indicator (primary error display)
      window.aipkit_updateCwGenerationStatus(
        "content",
        "error",
        "Connection Failed"
      );
      window.aipkit_finalizeStream(esInstanceRef);
    }
  }

  // Expose main SSE handler and the shared eventSource instance ref
  window.aipkit_handleContentWriterEventSource =
    aipkit_handleContentWriterEventSource;
  window.aipkit_contentWriterEventSourceInstanceRef = eventSourceInstanceRef; // Expose the ref object
})();
