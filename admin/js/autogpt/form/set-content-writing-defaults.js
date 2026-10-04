/**
 * AIPKit AutoGPT - Set Content Writing Defaults
 * Populates the content writing task form with default values when creating a new task.
 */
(function () {
  "use strict";

  function aipkit_form_setContentWritingDefaults() {
    const form = document.getElementById("aipkit_automated_task_form");
    const config = window.aipkit_automated_tasks_config || {};
    const defaultPrompts = config.default_cw_prompts || {};

    if (!form) return;

    const setBuiltInPrompt = (name, value) => {
      const field = form.elements[name];
      if (!field) return;
      const prompt = value || field.defaultValue || "";
      field.defaultValue = prompt;
      field.value = prompt;
    };

    // Set prompt values
    setBuiltInPrompt("custom_title_prompt", defaultPrompts.title);
    setBuiltInPrompt("custom_content_prompt", defaultPrompts.content);
    setBuiltInPrompt("custom_meta_prompt", defaultPrompts.meta);
    setBuiltInPrompt("custom_keyword_prompt", defaultPrompts.keyword);
    setBuiltInPrompt("custom_excerpt_prompt", defaultPrompts.excerpt);
    setBuiltInPrompt("custom_tags_prompt", defaultPrompts.tags);
    setBuiltInPrompt("image_prompt", defaultPrompts.image);
    setBuiltInPrompt("featured_image_prompt", defaultPrompts.featured_image);

    [
      "generate_meta_description",
      "generate_focus_keyword",
      "generate_excerpt",
      "generate_tags",
    ].forEach((name) => {
      if (form.elements[name]) form.elements[name].checked = true;
    });

    if (
      typeof window.aipkit_applyAutogptDefaultAiState === "function" &&
      typeof window.aipkit_task_cw_populateModels === "function"
    ) {
      window.aipkit_applyAutogptDefaultAiState({
        form,
        providerSelector: "#aipkit_task_cw_ai_provider",
        modelSelector: "#aipkit_task_cw_ai_model",
        combinedSelector: "#aipkit_task_cw_ai_selection",
        populateModels: window.aipkit_task_cw_populateModels,
      });
    }

    if (typeof window.aipkit_setAutogptFieldValue === "function") {
      window.aipkit_setAutogptFieldValue(
        "#aipkit_task_cw_content_length",
        "medium",
        {
          form,
          dispatchChange: true,
        }
      );
    }
    const smartSeoToggle = form.querySelector(
      "[data-aipkit-task-smart-seo-main-toggle]"
    );
    if (smartSeoToggle) {
      const isPro = Boolean(
        window.aipkit_dashboard && window.aipkit_dashboard.isProPlan
      );
      if (smartSeoToggle.type === "checkbox") {
        smartSeoToggle.checked = isPro;
        smartSeoToggle.defaultChecked = isPro;
      } else {
        smartSeoToggle.value = isPro ? "1" : "0";
        Array.from(smartSeoToggle.options || []).forEach((option) => {
          option.defaultSelected = option.value === smartSeoToggle.value;
        });
      }
      smartSeoToggle.dispatchEvent(new Event("change", { bubbles: true }));
    }
    if (form.elements["seo_score_continue_until_target"]) {
      form.elements["seo_score_continue_until_target"].value = "1";
    }
    if (form.elements["seo_score_target"]) {
      form.elements["seo_score_target"].value = "100";
    }
    if (form.elements["seo_score_max_passes"]) {
      form.elements["seo_score_max_passes"].value = "3";
    }
    if (form.elements["seo_score_profile"]) {
      form.elements["seo_score_profile"].value = "auto";
    }
    if (form.elements["seo_score_disabled_rules"]) {
      form.elements["seo_score_disabled_rules"].value =
        typeof window.aipkit_getDefaultSmartSeoDisabledRules === "function"
          ? window.aipkit_getDefaultSmartSeoDisabledRules()
          : "[]";
      form.elements["seo_score_disabled_rules"].dispatchEvent(
        new Event("change", { bubbles: true })
      );
    }
    if (form.elements.post_content_format) {
      form.elements.post_content_format.value = "gutenberg";
    }
    if (form.elements["post_status"]) {
      form.elements["post_status"].value = "publish";
      form.elements["post_status"].dispatchEvent(
        new Event("change", { bubbles: true })
      );
    }
    const immediateSchedule = form.querySelector(
      'input[name="schedule_mode"][value="immediate"]'
    );
    if (immediateSchedule) {
      immediateSchedule.checked = true;
    }
    if (form.elements["generate_images_enabled"]) {
      form.elements["generate_images_enabled"].checked = false;
    }
    const featureDefaults = window.aipkit_applyNewFeatureDefaults?.(form) || {};
    if (window.aipkit_automated_tasks_form_state) {
      window.aipkit_automated_tasks_form_state.pendingImageModelSelection = featureDefaults.image_model || '';
      window.aipkit_automated_tasks_form_state.pendingEmbeddingModelSelection = featureDefaults.vector_embedding_model || '';
    }
    if (form.elements["image_provider_options"]) {
      form.elements["image_provider_options"].value = "{}";
    }
    if (typeof window.aipkit_updateTaskImageSettingsUI === "function") {
      window.aipkit_updateTaskImageSettingsUI();
    }
    if (form.elements["image_count"]) {
      form.elements["image_count"].value = "1";
    }
    if (form.elements["image_placement"]) {
      form.elements["image_placement"].value = "after_first_h2";
    }
    if (form.elements["generate_featured_image"]) {
      form.elements["generate_featured_image"].checked = false;
    }
    if (typeof window.aipkit_updateTaskImageSettingsUI === "function") {
      window.aipkit_updateTaskImageSettingsUI();
    }
    if (typeof window.aipkit_syncAutogptInlinePrompts === "function") {
      window.aipkit_syncAutogptInlinePrompts();
    }
  }

  window.aipkit_form_setContentWritingDefaults =
    aipkit_form_setContentWritingDefaults;
})();
