
/**
 * AIPKit Content Writer - Reset Generation Status Indicators
 * Clears transient generation statuses and the header status.
 */
(function () {
  "use strict";

  /** Clears all transient generation status rows and the header status. */
  function resetGenerationStatusIndicators() {
    if (typeof window.aipkit_clearCwGenerationStatusIndicators === "function") {
      window.aipkit_clearCwGenerationStatusIndicators();
      return;
    }

    const statusEl = document.getElementById("aipkit_content_writer_form_status");
    if (statusEl) {
      statusEl.textContent = "";
      statusEl.className = "aipkit_cw_status_badge";
    }
  }

  // Expose globally
  window.aipkit_resetGenerationStatusIndicators =
    resetGenerationStatusIndicators;
})();
