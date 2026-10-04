/**
 * AIPKit Content Writer - Handle Stop Generation Action
 * Orchestrates stopping the SSE stream and updating the UI immediately.
 */
(function () {
  "use strict";

  const __ = window.wp?.i18n?.__ || function (str) { return str; };

  function setSingleRunStopRequested(isRequested) {
    if (isRequested) {
      window.aipkit_cw_singleStopRequested = true;
      return;
    }

    delete window.aipkit_cw_singleStopRequested;
  }

  function isSingleRunStopRequested() {
    return window.aipkit_cw_singleStopRequested === true;
  }

  function beginAbortableSingleRequest(step = "content") {
    const controller = new AbortController();
    window.aipkit_cw_singleRequestController = controller;
    window.aipkit_cw_singleRequestStep = step;
    return controller;
  }

  function finishAbortableSingleRequest(controller) {
    if (window.aipkit_cw_singleRequestController === controller) {
      window.aipkit_cw_singleRequestController = null;
      delete window.aipkit_cw_singleRequestStep;
    }
  }

  function isAbortError(error) {
    return Boolean(
      error && (error.name === "AbortError" || error.code === "aborted")
    );
  }

  function updateActiveStepStopped() {
    if (typeof window.aipkit_updateCwGenerationStatus !== "function") {
      return;
    }

    const activeStep = String(window.aipkit_cw_singleRequestStep || "content");
    if (activeStep === "title") {
      window.aipkit_updateCwGenerationStatus("title", "skipped", "Stopped");
      window.aipkit_updateCwGenerationStatus("content", "skipped", "Stopped");
      return;
    }

    window.aipkit_updateCwGenerationStatus(activeStep, "skipped", "Stopped");
  }

  function aipkit_handleStopGeneration(eventSourceInstanceRef) {
    const generateBtn = document.getElementById("aipkit_content_writer_generate_btn");
    const setStoppingLabel = () => {
      if (!generateBtn) return;
      if (typeof window.aipkit_setButtonText === "function") {
        window.aipkit_setButtonText(generateBtn, __("Stopping...", "gpt3-ai-content-generator"));
      } else {
        const label = generateBtn.querySelector(".aipkit_btn-text");
        if (label) {
          label.textContent = __("Stopping...", "gpt3-ai-content-generator");
        } else {
          generateBtn.textContent = __("Stopping...", "gpt3-ai-content-generator");
        }
      }
      generateBtn.disabled = true;
      generateBtn.setAttribute("aria-disabled", "true");
    };

    if (
      typeof window.aipkit_cw_isBatchActive === "function" &&
      window.aipkit_cw_isBatchActive()
    ) {
      setStoppingLabel();
      if (typeof window.aipkit_cw_requestBatchStop === "function") {
        window.aipkit_cw_requestBatchStop();
      }
      return;
    }

    if (
      typeof window.aipkit_cw_isExistingUpdateActive === "function" &&
      window.aipkit_cw_isExistingUpdateActive()
    ) {
      setStoppingLabel();
      if (typeof window.aipkit_cw_requestExistingUpdateStop === "function") {
        window.aipkit_cw_requestExistingUpdateStop();
      }
      return;
    }

    setSingleRunStopRequested(true);

    if (
      window.aipkit_cw_singleRequestController &&
      typeof window.aipkit_cw_singleRequestController.abort === "function"
    ) {
      setStoppingLabel();
      window.aipkit_cw_singleRequestController.abort();
      updateActiveStepStopped();
      if (typeof window.aipkit_setContentWriterCanvasState === "function") {
        const hasContent =
          typeof window.aipkit_hasContentWriterCanvasContent === "function"
            ? window.aipkit_hasContentWriterCanvasContent()
            : false;
        window.aipkit_setContentWriterCanvasState("stopped", {
          title: "Generation stopped",
          description: hasContent
            ? "The partial draft has been kept below."
            : "The run was stopped before the manuscript started streaming.",
          hasContent,
        });
      }
      if (
        typeof window.aipkit_hideContentWriterFeaturedImageLoading ===
        "function"
      ) {
        window.aipkit_hideContentWriterFeaturedImageLoading();
      }
      return;
    }

    if (!eventSourceInstanceRef || !eventSourceInstanceRef.current) {
      setSingleRunStopRequested(false);
      if (typeof window.aipkit_resetContentWriterButtons === "function") {
        window.aipkit_resetContentWriterButtons();
      }
      return;
    }

    setStoppingLabel();

    // 1. Set the flag so the onerror handler knows this was intentional.
    eventSourceInstanceRef.current.userStopped = true;

    // 3. Update the active step state.
    updateActiveStepStopped();
    if (typeof window.aipkit_setContentWriterCanvasState === "function") {
      const hasContent =
        typeof window.aipkit_hasContentWriterCanvasContent === "function"
          ? window.aipkit_hasContentWriterCanvasContent()
          : false;
      window.aipkit_setContentWriterCanvasState("stopped", {
        title: "Generation stopped",
        description: hasContent
          ? "The partial draft has been kept below."
          : "The run stopped before the manuscript was produced.",
        hasContent,
      });
    }
    if (
      typeof window.aipkit_hideContentWriterFeaturedImageLoading === "function"
    ) {
      window.aipkit_hideContentWriterFeaturedImageLoading();
    }

    // 4. Close the connection (this will trigger onerror, which will finalize the stream ref).
    if (typeof window.aipkit_stopContentWriterEventSource === "function") {
      window.aipkit_stopContentWriterEventSource(eventSourceInstanceRef);
    } else {
      console.error(
        "Handle Stop Generation: stopContentWriterEventSource function not found."
      );
    }

    window.setTimeout(() => {
      if (
        !isSingleRunStopRequested() ||
        !generateBtn ||
        generateBtn.dataset.aipkitStopMode !== "true" ||
        window.aipkit_cw_singleRequestController
      ) {
        return;
      }

      if (typeof window.aipkit_finalizeStream === "function") {
        window.aipkit_finalizeStream(eventSourceInstanceRef);
      }
    }, 180);
  }

  window.aipkit_setContentWriterStopRequested = setSingleRunStopRequested;
  window.aipkit_isContentWriterStopRequested = isSingleRunStopRequested;
  window.aipkit_beginContentWriterAbortableRequest =
    beginAbortableSingleRequest;
  window.aipkit_finishContentWriterAbortableRequest =
    finishAbortableSingleRequest;
  window.aipkit_isContentWriterAbortError = isAbortError;
  window.aipkit_handleStopGeneration = aipkit_handleStopGeneration;
})();
