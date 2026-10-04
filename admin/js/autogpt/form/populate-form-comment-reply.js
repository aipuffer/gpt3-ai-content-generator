/**
 * AIPKit AutoGPT - Form Comment Reply Population
 * Populates fields specific to the 'community_reply_comments' task type.
 */
(function () {
  "use strict";

  function aipkit_form_populateCommentReplyFields(taskConfig) {
    const form = document.getElementById("aipkit_automated_task_form");
    if (!form) return;

    // Populate AI and Prompt fields
    if (
      typeof window.aipkit_applyAutogptSavedAiState === "function" &&
      typeof window.aipkit_task_cc_populateModels === "function"
    ) {
      window.aipkit_applyAutogptSavedAiState({
        form,
        taskConfig,
        providerSelector: "#aipkit_task_cc_ai_provider",
        modelSelector: "#aipkit_task_cc_ai_model",
        combinedSelector: "#aipkit_task_cc_ai_selection",
        temperatureSelector: "#aipkit_task_cc_ai_temperature",
        defaultTemperature: "1.0",
        reasoningSelector: "#aipkit_task_cc_reasoning_effort",
        populateModels: window.aipkit_task_cc_populateModels,
        reasoningToggle:
          typeof window.aipkit_autogpt_cc_toggleReasoningEffort === "function"
            ? window.aipkit_autogpt_cc_toggleReasoningEffort
            : null,
      });
    }

    if (typeof window.aipkit_setAutogptFieldValue === "function") {
      window.aipkit_setAutogptFieldValue(
        "#aipkit_task_cc_content_max_tokens",
        taskConfig.content_max_tokens || 4000,
        {
          form,
          dispatchChange: true,
        }
      );
    }


    const promptTextarea = document.getElementById(
      "aipkit_task_cc_custom_content_prompt"
    );
    if (promptTextarea) {
      promptTextarea.value = taskConfig.custom_content_prompt || "";
    }

    // Populate Filters
    const includeKeywords = document.getElementById(
      "aipkit_task_comment_reply_include_keywords"
    );
    if (includeKeywords)
      includeKeywords.value = taskConfig.include_keywords || "";

    const excludeKeywords = document.getElementById(
      "aipkit_task_comment_reply_exclude_keywords"
    );
    if (excludeKeywords)
      excludeKeywords.value = taskConfig.exclude_keywords || "";

    const postTypesSelect = form.elements["post_types_for_comments[]"];
    if (postTypesSelect) {
      Array.from(postTypesSelect.options).forEach(
        (opt) => (opt.selected = false)
      );
      (taskConfig.post_types_for_comments || []).forEach((pt) => {
        const option = postTypesSelect.querySelector(`option[value="${pt}"]`);
        if (option) option.selected = true;
      });
    }

    const replyActionSelect = form.elements["reply_action"];
    if (replyActionSelect) {
      replyActionSelect.value = taskConfig.reply_action || "hold";
      replyActionSelect._aipkitSegmentedSync?.();
    }

    const noReplyCheckbox = form.elements["no_reply_to_replies"];
    if (noReplyCheckbox)
      noReplyCheckbox.checked = taskConfig.no_reply_to_replies === "1";

    // Populate task frequency from main setup step
    if (form.elements["task_frequency"]) {
      form.elements["task_frequency"].value =
        taskConfig.task_frequency || "hourly";
    }

    window.aipkit_syncAutogptInlinePrompts?.();
  }
  window.aipkit_form_populateCommentReplyFields =
    aipkit_form_populateCommentReplyFields;
})();
