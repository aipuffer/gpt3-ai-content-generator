/** AI Forms submission orchestration. */
(function () {
  "use strict";

  /**
   * Handles SSE form submission for AI Forms.
   * @param {Event} event The form submission event.
   */
  async function aipkit_handleFormSubmitSSE(event) {
    event.preventDefault();
    event.stopPropagation();

    const state = window.aipkitAIFormsPublicState;

    // 1. Get Elements and Config
    const form = event.target;
    const formWrapper = form.closest(".aipkit-ai-form-wrapper");
    if (!formWrapper) return;

    const formId = formWrapper.dataset.formId;
    const sseNonce = aipkit_ai_forms_public_config.ajaxNonce;
    const resultsDiv = formWrapper.querySelector(".aipkit-ai-form-results");
    const submitButton = form.querySelector('button[type="submit"]');
    const spinner = submitButton
      ? submitButton.querySelector(".aipkit_spinner")
      : null;
    const originalButtonText =
      formWrapper.dataset.labelGenerateButton || "Generate";

    const __ =
      window.wp && window.wp.i18n && window.wp.i18n.__
        ? window.wp.i18n.__
        : (text) => text;

    if (!formId || !sseNonce || !resultsDiv || !submitButton) {
      console.error(
        "AI Form SSE: Missing essential elements (formId, nonce, resultsDiv, submitButton)."
      );
      if (resultsDiv) {
        resultsDiv.style.display = "block";
        resultsDiv.textContent = __("Configuration error.", "gpt3-ai-content-generator");
      }
      return;
    }

    // 2. Setup UI Manager
    let request;
    const { restoreGenerateButton, setupStopButton } =
      window.aipkitForms_createSseUiManager(submitButton, originalButtonText, () => request.cancel());
    request = window.aipkitForms_beginSubmission(form, restoreGenerateButton);

    // 4. Reset UI
    resultsDiv.innerHTML = "";
    delete resultsDiv.dataset.aipkitMarkdownUpgradePending;
    resultsDiv.classList.remove("aipkit-ai-form-results--quota");
    resultsDiv.style.display = "none";
    if (submitButton) submitButton.disabled = true;
    if (spinner) spinner.style.display = "inline-block";

    // 5. Validate Inputs
    const validationResult = window.aipkitForms_validateSseInputs(form);
    if (!validationResult.isValid) {
      if (validationResult.firstInvalidField) {
        if (validationResult.firstInvalidField.type !== "checkbox") {
          validationResult.firstInvalidField.style.borderColor = "red";
        } else {
          const label = validationResult.firstInvalidField
            .closest(".aipkit_form-group")
            ?.querySelector("label");
          if (label) label.style.color = "red";
        }
      }
      resultsDiv.style.display = "block";
      resultsDiv.innerHTML = `<p style="color:orange;">${__(
        "Please fill in all required fields.",
        "gpt3-ai-content-generator"
      )}</p>`;
      request.finish();
      return;
    }

    // 6. Setup "Stop" button and prepare results area
    setupStopButton();

    if (
      window.aipkitFormsMarkdown &&
      typeof window.aipkitFormsMarkdown.ensureRenderer === "function"
    ) {
      void window
        .aipkitFormsMarkdown.ensureRenderer()
        .then((renderer) => {
          state.mdInstanceAIForms = renderer;
        });
    }

    // 7. Start the SSE process
    try {
      await window.aipkitForms_setupAndStartEventSource(
        formId,
        validationResult.userInputs,
        validationResult.imageInputs || [],
        sseNonce,
        resultsDiv,
        request
      );
    } catch (error) {
      if (!request.isActive()) return;
      resultsDiv.style.display = "block";
      const notice = document.createElement("p");
      notice.className = "aipkit-ai-form-error";
      notice.setAttribute("role", "alert");
      notice.textContent = error.message || aipkit_ai_forms_public_config.text.error ||
        __("An error occurred.", "gpt3-ai-content-generator");
      resultsDiv.replaceChildren(notice);
      request.finish();
    }
  }

  // Expose the main handler globally
  window.aipkit_handleFormSubmitSSE = aipkit_handleFormSubmitSSE;
})();
