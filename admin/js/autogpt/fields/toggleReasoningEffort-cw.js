/**
 * AIPKit AutoGPT Content Writing - Reasoning Effort Toggle UI Logic
 */
(function () {
  "use strict";

  function aipkit_autogpt_cw_toggleReasoningEffort() {
    const utils = window.aipkit_autogpt_reasoning_utils;
    if (!utils || typeof utils.toggleAutogptReasoningEffort !== "function") {
      return;
    }

    utils.toggleAutogptReasoningEffort({
      providerSelector: "#aipkit_task_cw_ai_provider",
      modelSelector: "#aipkit_task_cw_ai_model",
      fieldSelector: ".aipkit_task_cw_reasoning_effort_field",
      selectSelector: "#aipkit_task_cw_reasoning_effort",
    });
  }

  window.aipkit_autogpt_cw_toggleReasoningEffort = aipkit_autogpt_cw_toggleReasoningEffort;
})();
