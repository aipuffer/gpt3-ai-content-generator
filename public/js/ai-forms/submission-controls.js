/** AI Forms submit and stop button lifecycle. */
(function () {
  "use strict";

  /**
   * Creates and returns UI management functions for a specific form submission.
   * @param {HTMLElement} submitButton The submit button element.
   * @param {string} originalButtonText The original text of the button.
   * @param {function} onStop Cancels this form submission.
   * @returns {{ restoreGenerateButton: function, setupStopButton: function }} An object with UI control functions.
   */
  function aipkitForms_createSseUiManager(submitButton, originalButtonText, onStop) {
    const formWrapper = submitButton
      ? submitButton.closest(".aipkit-ai-form-wrapper")
      : null;
    const buttonTextSpan = submitButton
      ? submitButton.querySelector(".aipkit_btn-text")
      : null;
    const spinner = submitButton
      ? submitButton.querySelector(".aipkit_spinner")
      : null;
    const __ =
      window.wp && window.wp.i18n && window.wp.i18n.__
        ? window.wp.i18n.__
        : (text) => text;

    let stopStreamHandlerInstance = null; // To keep track of the specific handler instance

    const restoreGenerateButton = () => {
      if (submitButton) {
        submitButton.disabled = false;
        submitButton.classList.remove("aipkit_btn-danger");
        submitButton.classList.add("aipkit_btn-primary");
        if (buttonTextSpan) buttonTextSpan.textContent = originalButtonText;
        if (stopStreamHandlerInstance) {
          submitButton.removeEventListener("click", stopStreamHandlerInstance);
          stopStreamHandlerInstance = null; // Clear the handler
        }
      }
      if (spinner) spinner.style.display = "none";
    };

    const setupStopButton = () => {
      if (!submitButton) return;
      submitButton.disabled = false;
      submitButton.classList.remove("aipkit_btn-primary");
      submitButton.classList.add("aipkit_btn-danger");
      if (buttonTextSpan)
        buttonTextSpan.textContent =
          formWrapper.dataset.labelStopButton ||
          __("Stop", "gpt3-ai-content-generator");

      stopStreamHandlerInstance = (e) => {
        e.preventDefault();
        e.stopPropagation();
        onStop();
      };

      submitButton.addEventListener("click", stopStreamHandlerInstance, {
        once: true,
      });
    };

    return { restoreGenerateButton, setupStopButton };
  }

  // Expose the factory function globally
  window.aipkitForms_createSseUiManager = aipkitForms_createSseUiManager;
})();
