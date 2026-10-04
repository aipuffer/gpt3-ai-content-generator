/**
 * AIPKit AutoGPT - Shared AI form state helpers
 * Reused by task populate/default flows for provider/model/temperature/reasoning state.
 */
(function () {
  "use strict";

  if (
    typeof window.aipkit_setAutogptFieldValue === "function" &&
    typeof window.aipkit_applyAutogptSavedAiState === "function" &&
    typeof window.aipkit_applyAutogptDefaultAiState === "function"
  ) {
    return;
  }

  function resolveField(target, form) {
    if (!target) {
      return null;
    }

    if (typeof target === "string") {
      return (form || document).querySelector(target);
    }

    return target;
  }

  function normalizeReasoningValue(value) {
    const utils = window.aipkit_autogpt_reasoning_utils;
    if (utils && typeof utils.normalizeReasoningValue === "function") {
      return utils.normalizeReasoningValue(value);
    }

    return value === "minimal" ? "low" : value;
  }

  function setPendingModelSelection(modelId) {
    if (!window.aipkit_automated_tasks_form_state) {
      return;
    }

    window.aipkit_automated_tasks_form_state.pendingModelSelection =
      modelId || null;
  }

  function aipkit_setAutogptFieldValue(target, value, options = {}) {
    const form = options.form || document;
    const field = resolveField(target, form);
    if (!field) {
      return null;
    }

    field.value = value ?? "";

    if (options.textTargetSelector) {
      const textTarget = resolveField(options.textTargetSelector, form);
      if (textTarget) {
        textTarget.textContent = String(field.value);
      }
    }

    if (options.dispatchInput) {
      field.dispatchEvent(new Event("input", { bubbles: true }));
    }

    if (options.dispatchChange) {
      field.dispatchEvent(new Event("change", { bubbles: true }));
    }

    field._aipkitTemperatureSync?.();
    field._aipkitSegmentedSync?.();

    return field;
  }

  function aipkit_applyAutogptSavedAiState(config = {}) {
    const form =
      config.form || document.getElementById("aipkit_automated_task_form");
    const taskConfig = config.taskConfig || {};
    const providerSelect = resolveField(config.providerSelector, form);
    const modelSelect = resolveField(config.modelSelector, form);
    const combinedSelect = resolveField(config.combinedSelector, form);
    const reasoningSelect = resolveField(config.reasoningSelector, form);
    const populateModels = config.populateModels;
    const reasoningToggle =
      typeof config.reasoningToggle === "function" ? config.reasoningToggle : null;

    if (providerSelect && modelSelect && typeof populateModels === "function") {
      const savedProvider = String(taskConfig.ai_provider || "openai").toLowerCase();
      if (!Array.from(providerSelect.options).some((option) => option.value === savedProvider)) {
        const unavailableProvider = new Option(savedProvider, savedProvider);
        unavailableProvider.hidden = true;
        providerSelect.appendChild(unavailableProvider);
      }
      providerSelect.value = savedProvider;

      if (taskConfig.ai_model) {
        setPendingModelSelection(taskConfig.ai_model);
      }

      populateModels(providerSelect, modelSelect, combinedSelect);
      window.aipkit_autogpt_provider_setup?.syncFallbackNote?.(
        providerSelect,
        null
      );
    }

    if (
      config.temperatureSelector &&
      (Object.prototype.hasOwnProperty.call(taskConfig, "ai_temperature") ||
        Object.prototype.hasOwnProperty.call(config, "defaultTemperature"))
    ) {
      aipkit_setAutogptFieldValue(
        config.temperatureSelector,
        taskConfig.ai_temperature || config.defaultTemperature || "1.0",
        {
          form,
          textTargetSelector: config.temperatureOutputSelector,
        }
      );
    }

    if (reasoningSelect) {
      const rawEffort = taskConfig.reasoning_effort || "";
      if (rawEffort) {
        reasoningSelect.value = normalizeReasoningValue(rawEffort);
        reasoningSelect.dispatchEvent(new Event("change", { bubbles: true }));
      }
    }

    if (modelSelect && reasoningToggle) {
      setTimeout(() => {
        modelSelect.dispatchEvent(new Event("change", { bubbles: true }));
      }, config.reasoningDispatchDelay || 100);
    }
  }

  function aipkit_applyAutogptDefaultAiState(config = {}) {
    const form =
      config.form || document.getElementById("aipkit_automated_task_form");
    const dashboardConfig = window.aipkit_dashboard || {};
    const providerSelect = resolveField(config.providerSelector, form);
    const modelSelect = resolveField(config.modelSelector, form);
    const combinedSelect = resolveField(config.combinedSelector, form);
    const reasoningSelect = resolveField(config.reasoningSelector, form);
    const populateModels = config.populateModels;

    if (providerSelect && modelSelect && typeof populateModels === "function") {
      const allowedProviders = Array.from(providerSelect.options || [])
        .filter((option) => option.value && !option.disabled)
        .map((option) => option.value);
      const setup = window.aipkit_autogpt_provider_setup;
      const resolution = window.aipkit_resolveNewAiSelection?.({
        allowedProviders,
        preferredProvider:
          config.defaultProvider || dashboardConfig.main_provider,
      }) || {
        provider:
          dashboardConfig.newAiSelection?.provider_key || "openai",
        providerValue:
          dashboardConfig.newAiSelection?.provider_key || "openai",
        modelId: dashboardConfig.newAiSelection?.model || "",
        preferredProvider: dashboardConfig.main_provider || "openai",
        fellBack: false,
        needsSetup: false,
      };
      const provider = resolution.providerValue || resolution.provider;
      const defaultModel =
        config.defaultModel || resolution.modelId || "";

      providerSelect.value = provider;

      if (defaultModel) {
        setPendingModelSelection(defaultModel);
      }

      populateModels(providerSelect, modelSelect, combinedSelect);
      setup?.syncFallbackNote?.(providerSelect, resolution);
    }

    if (reasoningSelect && config.defaultReasoningValue && !reasoningSelect.value) {
      reasoningSelect.value = normalizeReasoningValue(config.defaultReasoningValue);
    }
  }

  window.aipkit_setAutogptFieldValue = aipkit_setAutogptFieldValue;
  window.aipkit_applyAutogptSavedAiState = aipkit_applyAutogptSavedAiState;
  window.aipkit_applyAutogptDefaultAiState = aipkit_applyAutogptDefaultAiState;
})();
