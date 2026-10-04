/**
 * AIPKit AutoGPT - Set Comment Reply Defaults
 * Populates the comment reply task form with default values when creating a new task.
 */
(function () {
  "use strict";

  function aipkit_form_setCommentReplyDefaults() {
    const form = document.getElementById("aipkit_automated_task_form");
    const config = window.aipkit_automated_tasks_config || {};
    const defaultPrompts = config.default_cc_prompts || {};

    if (!form) return;

    // Set default prompt
    const promptTextarea = document.getElementById(
      "aipkit_task_cc_custom_content_prompt"
    );
    if (promptTextarea) {
      promptTextarea.value = defaultPrompts.reply || promptTextarea.value || "";
    }

    window.aipkit_syncAutogptInlinePrompts?.();

    if (
      typeof window.aipkit_applyAutogptDefaultAiState === "function" &&
      typeof window.aipkit_task_cc_populateModels === "function"
    ) {
      window.aipkit_applyAutogptDefaultAiState({
        form,
        providerSelector: "#aipkit_task_cc_ai_provider",
        modelSelector: "#aipkit_task_cc_ai_model",
        combinedSelector: "#aipkit_task_cc_ai_selection",
        populateModels: window.aipkit_task_cc_populateModels,
      });
    }

    if (typeof window.aipkit_setAutogptFieldValue === "function") {
      window.aipkit_setAutogptFieldValue(
        "#aipkit_task_cc_content_max_tokens",
        "4000",
        {
          form,
          dispatchChange: true,
        }
      );
    }
  }

  window.aipkit_form_setCommentReplyDefaults =
    aipkit_form_setCommentReplyDefaults;
})();
