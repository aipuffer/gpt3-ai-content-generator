/**
 * AIPKit AutoGPT - Comment Replies model-populator wrapper
 */
(function () {
  "use strict";

  function aipkit_task_cc_populateModels(providerSelect, modelSelect, combinedTarget = null) {
    if (typeof window.aipkit_autogpt_populateModels !== "function") {
      return;
    }

    window.aipkit_autogpt_populateModels(providerSelect, modelSelect, {
      combinedTarget:
        combinedTarget || document.getElementById("aipkit_task_cc_ai_selection"),
      reasoningToggle:
        typeof window.aipkit_autogpt_cc_toggleReasoningEffort === "function"
          ? window.aipkit_autogpt_cc_toggleReasoningEffort
          : null,
      includeRecommended: true,
    });
  }

  window.aipkit_task_cc_populateModels = aipkit_task_cc_populateModels;
})();
