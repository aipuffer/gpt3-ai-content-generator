/**
 * AIPKit AutoGPT - Comment Reply Task Form UI Handler
 * Orchestrates initialization of UI elements specific to the "Comment Reply" task type form.
 */
(function () {
  "use strict";

  /**
   * Initializes UI elements specific to the Comment Reply task type form.
   * Called when "Comment Reply" is selected in the Task Type dropdown.
   */
  function aipkit_initCommentReplyTaskFormUI() {
    const form = document.getElementById("aipkit_automated_task_form");
    if (!form) return;

    const providerSelect = form.querySelector("#aipkit_task_cc_ai_provider");
    const modelSelect = form.querySelector("#aipkit_task_cc_ai_model");

    if (
      providerSelect &&
      modelSelect &&
      typeof window.aipkit_bindAutogptAiSetup === "function" &&
      typeof window.aipkit_task_cc_populateModels === "function"
    ) {
      window.aipkit_bindAutogptAiSetup({
        form,
        scope: "cc",
        providerSelector: "#aipkit_task_cc_ai_provider",
        modelSelector: "#aipkit_task_cc_ai_model",
        combinedSelector: "#aipkit_task_cc_ai_selection",
        populateModels: window.aipkit_task_cc_populateModels,
        reasoningToggle:
          typeof window.aipkit_autogpt_cc_toggleReasoningEffort === "function"
            ? window.aipkit_autogpt_cc_toggleReasoningEffort
            : null,
      });
    }
  }

  window.aipkit_initCommentReplyTaskFormUI = aipkit_initCommentReplyTaskFormUI;
})();
