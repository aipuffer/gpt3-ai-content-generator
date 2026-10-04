/**
 * AIPKit Content Writer - Reasoning Effort Toggle UI Logic
 *
 * Shows/hides the reasoning effort field based on provider and model.
 */
(function () {
  "use strict";

  function getReasoningUtils() {
    return (
      window.aipkit_reasoning_effort_utils ||
      window.aipkit_autogpt_reasoning_utils ||
      null
    );
  }

  /**
   * Toggles the visibility of the Reasoning Effort dropdown.
   * @param {HTMLElement} formContainer The parent form container.
   */
  function aipkit_cw_toggleReasoningEffort(formContainer) {
    if (!formContainer) return;

    const providerSelect = formContainer.querySelector(
      "#aipkit_content_writer_provider"
    );
    const modelSelect = formContainer.querySelector(
      "#aipkit_content_writer_model"
    );
    const reasoningField = formContainer.querySelector(
      ".aipkit_cw_reasoning_effort_field"
    );
    const reasoningSelect = formContainer.querySelector(
      "#aipkit_content_writer_reasoning_effort"
    );

    if (!providerSelect || !modelSelect || !reasoningField || !reasoningSelect) {
      if (reasoningField) {
        reasoningField.hidden = true;
      }
      return;
    }

    const provider = (providerSelect.value || "").toLowerCase();
    const model = modelSelect.value || "";
    const reasoningUtils = getReasoningUtils();
    const rule =
      reasoningUtils &&
      typeof reasoningUtils.getProviderReasoningRule === "function"
        ? reasoningUtils.getProviderReasoningRule(provider, model)
        : { supported: false };
    const isVisible =
      rule.supported &&
      typeof reasoningUtils.syncReasoningOptions === "function";

    reasoningField.hidden = !isVisible;
    if (isVisible) {
      reasoningUtils.syncReasoningOptions(reasoningSelect, rule.allowed, rule.defaultValue);
    }
  }

  window.aipkit_cw_toggleReasoningEffort = aipkit_cw_toggleReasoningEffort;
})();
