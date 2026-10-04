/**
 * AIPKit AutoGPT - Content Enhancement promo-compatible UI helpers
 *
 * These live in the free admin bundle so the Rewrite Content promo path can
 * render the same shared Setup/Advanced structure without depending on /lib/.
 * This is UI-only wiring. Premium runtime logic remains in /lib/.
 */
(function () {
  "use strict";

  function aipkit_autogpt_ce_toggleReasoningEffort() {
    const utils = window.aipkit_autogpt_reasoning_utils;
    if (!utils || typeof utils.toggleAutogptReasoningEffort !== "function") {
      return;
    }

    utils.toggleAutogptReasoningEffort({
      providerSelector: "#aipkit_task_ce_ai_provider",
      modelSelector: "#aipkit_task_ce_ai_model",
      fieldSelector: ".aipkit_task_ce_reasoning_effort_field",
      selectSelector: "#aipkit_task_ce_reasoning_effort",
    });
  }

  function aipkit_task_ce_populateModels(
    providerSelect,
    modelSelect,
    combinedTarget = null
  ) {
    if (typeof window.aipkit_autogpt_populateModels !== "function") {
      return;
    }

    window.aipkit_autogpt_populateModels(providerSelect, modelSelect, {
      combinedTarget:
        combinedTarget ||
        document.getElementById("aipkit_task_ce_ai_selection"),
      reasoningToggle:
        typeof window.aipkit_autogpt_ce_toggleReasoningEffort === "function"
          ? window.aipkit_autogpt_ce_toggleReasoningEffort
          : null,
      includeRecommended: true,
    });
  }

  function aipkit_form_setContentEnhancementDefaults() {
    const form = document.getElementById("aipkit_automated_task_form");
    const config = window.aipkit_automated_tasks_config || {};
    const defaultPrompts = config.default_ce_prompts || {};

    if (!form) {
      return;
    }

    const titlePrompt = document.getElementById("aipkit_task_ce_title_prompt");
    if (titlePrompt) {
      titlePrompt.value = defaultPrompts.title || titlePrompt.value || "";
    }

    const excerptPrompt = document.getElementById(
      "aipkit_task_ce_excerpt_prompt"
    );
    if (excerptPrompt) {
      excerptPrompt.value = defaultPrompts.excerpt || excerptPrompt.value || "";
    }

    const contentPrompt = document.getElementById(
      "aipkit_task_ce_content_prompt"
    );
    if (contentPrompt) {
      contentPrompt.value = defaultPrompts.content || contentPrompt.value || "";
    }

    const metaPrompt = document.getElementById("aipkit_task_ce_meta_prompt");
    if (metaPrompt) {
      metaPrompt.value = defaultPrompts.meta || metaPrompt.value || "";
    }

    if (
      typeof window.aipkit_applyAutogptDefaultAiState === "function" &&
      typeof window.aipkit_task_ce_populateModels === "function"
    ) {
      window.aipkit_applyAutogptDefaultAiState({
        form,
        providerSelector: "#aipkit_task_ce_ai_provider",
        modelSelector: "#aipkit_task_ce_ai_model",
        combinedSelector: "#aipkit_task_ce_ai_selection",
        reasoningSelector: "#aipkit_task_ce_reasoning_effort",
        defaultReasoningValue: "none",
        populateModels: window.aipkit_task_ce_populateModels,
      });
    }

    if (typeof window.aipkit_setAutogptFieldValue === "function") {
      window.aipkit_setAutogptFieldValue(
        "#aipkit_task_ce_content_max_tokens",
        "4000",
        {
          form,
          dispatchChange: true,
        }
      );
    }
  }

  function aipkit_initContentEnhancementTaskFormUI() {
    const form = document.getElementById("aipkit_automated_task_form");
    if (!form) {
      return;
    }

    const providerSelect = form.querySelector("#aipkit_task_ce_ai_provider");
    const modelSelect = form.querySelector("#aipkit_task_ce_ai_model");

    if (
      providerSelect &&
      modelSelect &&
      typeof window.aipkit_bindAutogptAiSetup === "function" &&
      typeof window.aipkit_task_ce_populateModels === "function"
    ) {
      window.aipkit_bindAutogptAiSetup({
        form,
        scope: "ce",
        providerSelector: "#aipkit_task_ce_ai_provider",
        modelSelector: "#aipkit_task_ce_ai_model",
        combinedSelector: "#aipkit_task_ce_ai_selection",
        populateModels: window.aipkit_task_ce_populateModels,
        reasoningToggle:
          typeof window.aipkit_autogpt_ce_toggleReasoningEffort === "function"
            ? window.aipkit_autogpt_ce_toggleReasoningEffort
            : null,
      });
    }

    const promptToggleContainer = document.getElementById(
      "aipkit_task_config_enhancement_ai_and_prompts_main"
    );
    if (
      promptToggleContainer &&
      promptToggleContainer.dataset.cePromptListenerAttached !== "true"
    ) {
      promptToggleContainer.addEventListener("click", (event) => {
        const toggleButton = event.target.closest(".aipkit_textarea_toggle");
        if (!toggleButton) {
          return;
        }

        const targetId = toggleButton.dataset.target;
        const targetElement = document.getElementById(targetId);
        const icon = toggleButton.querySelector(".dashicons");
        if (!targetElement || !icon) {
          return;
        }

        const isCollapsed = targetElement.classList.toggle("aipkit_collapsed");
        if (isCollapsed) {
          icon.classList.remove("dashicons-minus");
          icon.classList.add("dashicons-plus-alt2");
          toggleButton.title = "Expand";
        } else {
          icon.classList.remove("dashicons-plus-alt2");
          icon.classList.add("dashicons-minus");
          toggleButton.title = "Collapse";
        }
      });
      promptToggleContainer.dataset.cePromptListenerAttached = "true";
    }

    if (typeof window.aipkit_initCeKnowledgeBaseUI === "function") {
      window.aipkit_initCeKnowledgeBaseUI();
    }
  }

  window.aipkit_autogpt_ce_toggleReasoningEffort =
    aipkit_autogpt_ce_toggleReasoningEffort;
  window.aipkit_task_ce_populateModels = aipkit_task_ce_populateModels;
  window.aipkit_form_setContentEnhancementDefaults =
    aipkit_form_setContentEnhancementDefaults;
  window.aipkit_initContentEnhancementTaskFormUI =
    aipkit_initContentEnhancementTaskFormUI;
})();
