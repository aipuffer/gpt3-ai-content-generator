/**
 * AIPKit Content Writer - Template Handler Initializer
 * Initializes all event listeners related to template management.
 */
(function () {
  "use strict";

  /**
   * Main initializer for the template handling system.
   */
  function aipkit_initContentWriterTemplateHandler() {
    const dependencies = [
      "aipkit_initTemplateSelect",
      "aipkit_initTemplatePicker",
      "aipkit_bindFormChangeListeners",
    ];

    // Check for the existence of all required functions before executing any
    for (const funcName of dependencies) {
      if (typeof window[funcName] !== "function") {
        console.error(
          `Content Writer Template Handler Init: Missing required function: ${funcName}`
        );
        // Optionally, display a UI error and stop initialization
        return;
      }
    }

    // Call all initialization and binding functions
    window.aipkit_initTemplateSelect();
    window.aipkit_initTemplatePicker();
    window.aipkit_bindFormChangeListeners();
  }

  window.aipkit_initContentWriterTemplateHandler =
    aipkit_initContentWriterTemplateHandler;
})();
