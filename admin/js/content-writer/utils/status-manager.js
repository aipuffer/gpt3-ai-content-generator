/**
 * AIPKit Content Writer - Status Manager Utility
 * Provides unified status management to avoid duplicate error messages.
 * General rule: normal progress/success stays quiet; errors use the status badge.
 */
(function () {
  "use strict";

  const __ = window.wp?.i18n?.__ || function (str) { return str; };
  let clearTimer = null;

  /**
   * Updates the general form status area with appropriate messages
   * @param {string} type - Only 'error' renders; every other type clears
   * @param {string} message - The message to display
   * @param {number} [autoClearMs=0] - Optional auto-clear delay in ms
   */
  function updateGeneralStatus(type, message = "", autoClearMs = 0) {
    const statusDiv = document.getElementById("aipkit_content_writer_form_status");
    if (!statusDiv) return;

    if (clearTimer) {
      clearTimeout(clearTimer);
      clearTimer = null;
    }

    const baseClass = "aipkit_cw_status_badge";
    const setStatusClass = (extraClass = "") => {
      statusDiv.className = extraClass
        ? `${baseClass} ${extraClass}`
        : baseClass;
    };

    setStatusClass();

    if (type !== "error" || !message) {
      statusDiv.textContent = "";
      setStatusClass();
      return;
    }
    statusDiv.textContent = message;
    setStatusClass("aipkit_settings_message-error");

    if (autoClearMs) {
      clearTimer = setTimeout(() => {
        if (statusDiv.textContent === message) {
          clearGeneralStatus();
        }
      }, autoClearMs);
    }
  }

  /**
   * Clears the general status area
   */
  function clearGeneralStatus() {
    updateGeneralStatus("clear");
  }

  /**
   * Shows an error message in the general status area
   * @param {string} message - The error message
   */
  function showError(message) {
    updateGeneralStatus("error", message);
  }

  /**
   * Shows a generation failure while confirming the user's input was retained.
   * @param {string} message - Short failure summary
   * @param {string} retainedMessage - Context-specific retained-input message
   */
  function showGenerationError(
    message,
    retainedMessage = __(
      "Your topic is still here.",
      "gpt3-ai-content-generator"
    )
  ) {
    const summary =
      message ||
      __("Could not start the draft.", "gpt3-ai-content-generator");
    updateGeneralStatus("error", `${summary} ${retainedMessage}`);
  }

  // Expose functions globally
  window.aipkit_updateGeneralStatus = updateGeneralStatus;
  window.aipkit_clearGeneralStatus = clearGeneralStatus;
  window.aipkit_showError = showError;
  window.aipkit_showGenerationError = showGenerationError;
})();
