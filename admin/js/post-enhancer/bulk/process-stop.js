/**
 * AIPKit Content Enhancer - Bulk Process Stop Logic
 */
(function () {
  "use strict";

  /**
   * Stops the bulk processing loop.
   * @param {string} [reason='user'] - The reason for stopping ('user' or 'complete').
   */
  function aipkit_enhancer_stopBulkProcess(reason = "user") {
    if (!window.aipkit_enhancer_bulkState.isRunning) return;
    window.aipkit_enhancer_bulkState.isRunning = false;

    const modal = document.querySelector(".aipkit-enhancer-bulk-modal-overlay");
    if (!modal) return;

    if (reason === "user") {
      if (typeof window.aipkit_enhancer_logToModal === "function") {
        window.aipkit_enhancer_logToModal(
          "Processing stopped by user.",
          "info"
        );
      }
    }

    const startButtonText = modal.querySelector(
      "#aipkit_bulk_enhancer_start_btn .aipkit_btn-text"
    );
    if (startButtonText) {
      startButtonText.textContent = "Start";
    }
    const startButton = modal.querySelector("#aipkit_bulk_enhancer_start_btn");
    if (startButton) {
      startButton.disabled = false;
      startButton.hidden = true;
    }
    modal.querySelector("#aipkit_bulk_enhancer_stop_btn").hidden = true;
    const cancelButton = modal.querySelector(
      "#aipkit_bulk_enhancer_cancel_btn"
    );
    if (cancelButton) {
      cancelButton.hidden = false;
    }
    
    // Show "Back to Settings" button when process is stopped or completed
    modal.querySelector("#aipkit_bulk_enhancer_back_to_settings_btn").hidden =
      false;
  }

  /**
   * Returns the modal to the settings view, clearing any progress/log data.
   */
  function aipkit_enhancer_returnToSettings() {
    const modal = document.querySelector(".aipkit-enhancer-bulk-modal-overlay");
    if (!modal) return;

    // Stop any running process first
    if (window.aipkit_enhancer_bulkState.isRunning) {
      aipkit_enhancer_stopBulkProcess("user");
    }

    // Reset the UI to initial state
    modal.querySelector("#aipkit-enhancer-bulk-config").hidden = false;
    modal.querySelector("#aipkit-enhancer-bulk-progress").hidden = true;
    
    // Reset button states
    const startButton = modal.querySelector("#aipkit_bulk_enhancer_start_btn");
    if (startButton) {
      startButton.disabled = false;
      startButton.hidden = false;
    }
    const startButtonLabel = modal.querySelector(
      "#aipkit_bulk_enhancer_start_btn .aipkit_btn-text"
    );
    if (startButtonLabel) {
      startButtonLabel.textContent = "Start";
    }
    modal.querySelector("#aipkit_bulk_enhancer_stop_btn").hidden = true;
    const cancelButton = modal.querySelector(
      "#aipkit_bulk_enhancer_cancel_btn"
    );
    if (cancelButton) {
      cancelButton.hidden = false;
    }
    modal.querySelector("#aipkit_bulk_enhancer_back_to_settings_btn").hidden =
      true;

    // Clear the progress log
    const logDiv = modal.querySelector("#aipkit-enhancer-bulk-status-log");
    if (logDiv) {
      logDiv.innerHTML = "";
    }

    // Reset progress bar
    const progressBar = modal.querySelector("#aipkit-enhancer-bulk-progress-bar");
    if (progressBar) {
      progressBar.style.width = "0%";
    }

    const postCount = parseInt(
      modal
        .querySelector("#aipkit_enhancer_bulk_modal")
        ?.dataset?.postCount || "0",
      10
    );

    // Reset progress text and stats
    const progressText = modal.querySelector("#aipkit-enhancer-progress-text");
    if (progressText) {
      progressText.textContent = `Processing 1 of ${postCount}…`;
    }
    
    const progressStats = modal.querySelector("#aipkit-enhancer-progress-stats");
    if (progressStats) {
      progressStats.textContent = "0%";
    }

    const footerStatus = modal.querySelector(
      "#aipkit_enhancer_footer_status"
    );
    if (footerStatus) {
      footerStatus.textContent = `${postCount} item${
        postCount === 1 ? "" : "s"
      } selected`;
    }

    // Reset bulk state
    window.aipkit_enhancer_bulkState = {
      queue: [],
      isRunning: false,
      completed: 0,
      failed: 0,
      total: 0,
      enhancementsConfig: {},
    };
  }

  window.aipkit_enhancer_stopBulkProcess = aipkit_enhancer_stopBulkProcess;
  window.aipkit_enhancer_returnToSettings = aipkit_enhancer_returnToSettings;
})();
