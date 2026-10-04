// Updated for chunked output UI design - restores enhanced placeholder
/**
 * AIPKit Content Writer - Start Over Button Handler
 * Manages the click event for the "Start Over" button in the output area.
 */
(function () {
  "use strict";

  /**
   * Clears the output area, title, meta description, and resets related UI elements.
   */
  function aipkit_clearContentWriterOutput() {
    if (
      typeof window.aipkit_resetContentWriterOutputSkeletons === "function"
    ) {
      window.aipkit_resetContentWriterOutputSkeletons();
    }
    const singleOutputWrapper = document.getElementById(
      "aipkit_cw_single_output_wrapper"
    );
    const outputContainer = document.getElementById(
      "aipkit_content_writer_output_display"
    );
    const contentArea = document.getElementById(
      "aipkit_cw_generated_content_area"
    );
    const outputActions = document.querySelector(
      ".aipkit_content_writer_output_actions"
    );
    const statusDisplayContainer = document.getElementById(
      "aipkit_cw_status_display_container"
    );
    const titleDisplay = document.getElementById(
      "aipkit_cw_generated_title_display"
    );
    const metaChunk = document.getElementById("aipkit_cw_meta_chunk");
    const metaDisplay = document.getElementById(
      "aipkit_cw_meta_desc_output_wrapper"
    );
    const metaTextarea = document.getElementById(
      "aipkit_cw_generated_meta_desc"
    );
    const focusKeywordDisplay = document.getElementById(
      "aipkit_cw_focus_keyword_output_wrapper"
    );
    const focusKeywordTextarea = document.getElementById(
      "aipkit_cw_generated_focus_keyword"
    );
    const excerptDisplay = document.getElementById(
      "aipkit_cw_excerpt_output_wrapper"
    );
    const excerptTextarea = document.getElementById(
      "aipkit_cw_generated_excerpt"
    );
    const tagsDisplay = document.getElementById(
      "aipkit_cw_tags_output_wrapper"
    );
    const tagsTextarea = document.getElementById("aipkit_cw_generated_tags");
    const imageDataHolder = document.getElementById(
      "aipkit_cw_image_data_holder"
    );
    const copyBtn = document.getElementById("aipkit_content_writer_copy_btn");
    const clearBtn = document.getElementById("aipkit_content_writer_clear_btn");

    if (singleOutputWrapper) {
      singleOutputWrapper.style.display = "none";
    }
    if (typeof window.aipkit_setContentWriterSinglePreviewState === "function") {
      window.aipkit_setContentWriterSinglePreviewState(false);
    }

    if (outputContainer && contentArea) {
      // Remove streaming and overflow classes
      contentArea.classList.remove('is-streaming');
      if (
        typeof window.aipkit_resetContentWriterStreamFollow === "function"
      ) {
        window.aipkit_resetContentWriterStreamFollow();
      }
      const primaryChunk = contentArea.closest('.aipkit_cw_output_chunk--primary');
      if (primaryChunk) {
        primaryChunk.classList.remove('has-overflow');
      }

      contentArea.innerHTML = "";
      outputContainer.scrollTop = 0;

      if (outputActions) outputActions.style.display = "none";
      if (typeof window.aipkit_resetSaveAsPostStatus === "function") {
        window.aipkit_resetSaveAsPostStatus();
      }
      if (copyBtn) copyBtn.disabled = true;
      if (clearBtn) clearBtn.disabled = true;
      if (typeof window.aipkit_resetContentWriterRawHtml === "function") {
        window.aipkit_resetContentWriterRawHtml();
      }
      if (typeof window.aipkit_disableSaveAsPostButton === "function") {
        window.aipkit_disableSaveAsPostButton();
      }

      const statusDiv = document.getElementById(
        "aipkit_content_writer_form_status"
      );
      if (statusDiv) {
        statusDiv.textContent = "";
      }

      // Instead of hiding the status container, reset the indicators within it
      if (
        statusDisplayContainer &&
        typeof window.aipkit_resetGenerationStatusIndicators === "function"
      ) {
        window.aipkit_resetGenerationStatusIndicators();
      }

      if (titleDisplay) {
        titleDisplay.style.display = "none";
        titleDisplay.textContent = "";
      }

      // Hide the meta chunk (chunked UI)
      if (metaChunk) {
        metaChunk.style.display = "none";
      }

      if (metaDisplay) {
        metaDisplay.style.display = "none";
      }
      if (metaTextarea) {
        metaTextarea.value = "";
      }
      if (focusKeywordDisplay) {
        focusKeywordDisplay.style.display = "none";
      }
      if (focusKeywordTextarea) {
        focusKeywordTextarea.value = "";
      }
      if (excerptDisplay) {
        excerptDisplay.style.display = "none";
      }
      if (excerptTextarea) {
        excerptTextarea.value = "";
      }
      if (tagsDisplay) {
        tagsDisplay.style.display = "none";
      }
      if (tagsTextarea) {
        tagsTextarea.value = "";
        if (typeof window.aipkit_syncContentWriterTagsEditor === "function") {
          window.aipkit_syncContentWriterTagsEditor(true);
        }
      }
      if (imageDataHolder) {
        imageDataHolder.value = "";
      }
      delete window.aipkit_regenerateContentWriterImage;

      // Update meta count badge
      if (typeof window.aipkit_updateMetaCountBadge === "function") {
        window.aipkit_updateMetaCountBadge();
      }

      if (typeof window.aipkit_refreshContentWriterMetaTextareas === "function") {
        window.aipkit_refreshContentWriterMetaTextareas();
      }

      if (typeof window.aipkit_clearContentWriterImagePreview === "function") {
        window.aipkit_clearContentWriterImagePreview();
      }

      if (typeof window.aipkit_resetContentWriterSeoAuditState === "function") {
        window.aipkit_resetContentWriterSeoAuditState();
      } else if (typeof window.aipkit_resetContentWriterSeoAuditPanel === "function") {
        window.aipkit_resetContentWriterSeoAuditPanel();
      }

      if (
        typeof window.aipkit_resetContentWriterPreviewCounter === "function"
      ) {
        window.aipkit_resetContentWriterPreviewCounter();
      }
      if (
        typeof window.aipkit_resetContentWriterStreamFollow === "function"
      ) {
        window.aipkit_resetContentWriterStreamFollow();
      }
      if (
        typeof window.aipkit_resetContentWriterCompletionTime === "function"
      ) {
        window.aipkit_resetContentWriterCompletionTime();
      }
      if (typeof window.aipkit_resetContentWriterCanvasState === "function") {
        window.aipkit_resetContentWriterCanvasState();
      }
    }
  }

  /**
   * Initializes the start-over button event listener.
   * @param {HTMLButtonElement} clearBtn The start-over button element.
   * @param {HTMLButtonElement} copyBtn The copy button element (not directly used by handler, but good to know it's related).
   */
  function aipkit_handleClearButton(clearBtn, copyBtn) {
    if (!clearBtn) {
      console.warn("Start Over Button Handler: Button element not provided.");
      return;
    }

    if (!clearBtn.dataset.listenerAttached) {
      clearBtn.addEventListener("click", () => {
        const clearOutput = () => {
          if (typeof window.aipkit_clearContentWriterOutput === "function") {
            window.aipkit_clearContentWriterOutput();
          }
        };
        if (typeof window.aipkit_hasContentWriterCanvasContent === "function"
            && !window.aipkit_hasContentWriterCanvasContent()) {
          clearOutput();
          return;
        }
        const message =
          "This clears the generated article and its unsaved metadata. This cannot be undone.";

        if (typeof window.aipkit_showConfirmModal === "function") {
          window.aipkit_showConfirmModal(message, {
            title: "Start over?",
            confirmText: "Start over",
            cancelText: "Cancel",
            variant: "danger",
            onConfirm: clearOutput,
          });
          return;
        }

        if (window.confirm(message)) {
          clearOutput();
        }
      });
      clearBtn.dataset.listenerAttached = "true";
    }
  }

  window.aipkit_handleClearButton = aipkit_handleClearButton;
  window.aipkit_clearContentWriterOutput = aipkit_clearContentWriterOutput; // Expose globally
})();
