/**
 * AIPKit Content Writer - Check Form Modifications
 * Compares the current form state to the initially loaded template config to detect changes.
 */
(function () {
  "use strict";

  /**
   * Checks if the form content has been modified from the initial template config.
   */
  function aipkit_checkFormForModifications() {
    if (
      typeof window.aipkit_getContentWriterFormConfig !== "function" ||
      typeof window.aipkit_deepEqual !== "function" ||
      !window.aipkit_cw_template_state
    ) {
      console.error(
        "checkFormForModifications: Missing required dependencies (getFormConfig, deepEqual, or state object)."
      );
      return;
    }

    const form = document.getElementById("aipkit_content_writer_form");
    if (!form) return;

    const currentFormConfig = window.aipkit_getContentWriterFormConfig(form);
    const initialConfig =
      window.aipkit_cw_template_state.initialConfigForSelectedTemplate;

    window.aipkit_cw_template_state.currentTemplateModified =
      !window.aipkit_deepEqual(currentFormConfig, initialConfig);
  }

  window.aipkit_checkFormForModifications = aipkit_checkFormForModifications;
})();
