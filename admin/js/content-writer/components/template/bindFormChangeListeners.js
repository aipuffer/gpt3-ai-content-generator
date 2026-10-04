/**
 * AIPKit Content Writer - Bind Form Change Listeners
 * Attaches delegated listeners to the form to detect changes for template modification state.
 */
(function () {
  "use strict";

  function aipkit_bindFormChangeListeners() {
    const formInputsWrapper = document.querySelector(
      ".aipkit_content_writer_inputs"
    );
    if (!formInputsWrapper) {
      console.warn(
        "bindFormChangeListeners: Form inputs wrapper not found for change detection."
      );
      return;
    }

    if (typeof window.aipkit_checkFormForModifications === "function") {
      // Use event delegation on the container
      formInputsWrapper.addEventListener(
        "input",
        window.aipkit_checkFormForModifications
      );
      formInputsWrapper.addEventListener(
        "change",
        window.aipkit_checkFormForModifications
      );
    } else {
      console.error(
        "bindFormChangeListeners: aipkit_checkFormForModifications function is missing."
      );
    }
  }

  window.aipkit_bindFormChangeListeners = aipkit_bindFormChangeListeners;
})();
