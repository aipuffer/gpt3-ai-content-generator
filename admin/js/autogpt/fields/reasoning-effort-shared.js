/**
 * AIPKit - Shared reasoning effort utilities
 */
(function () {
  "use strict";

  const existingUtils =
    window.aipkit_reasoning_effort_utils ||
    window.aipkit_autogpt_reasoning_utils;

  if (existingUtils) {
    window.aipkit_reasoning_effort_utils = existingUtils;
    window.aipkit_autogpt_reasoning_utils = existingUtils;
    return;
  }

  function normalizeReasoningValue(value) {
    return value === "minimal" ? "low" : value;
  }

  function isChatVariantModel(modelLower) {
    return /(?:^|[-_])chat(?:[-_]|$)/.test(modelLower);
  }

  function isGpt6Astra(modelLower) {
    return /^gpt-6-astra(?:-\d{4}-\d{2}-\d{2})?$/.test(modelLower);
  }

  function isGpt6SolOrLuna(modelLower) {
    return /^gpt-6-(?:sol|luna)(?:-\d{4}-\d{2}-\d{2})?$/.test(modelLower);
  }

  function isOpenAIReasoningModel(modelLower) {
    if (isChatVariantModel(modelLower)) {
      return false;
    }

    const isOseries =
      modelLower.startsWith("o1") ||
      modelLower.startsWith("o3") ||
      modelLower.startsWith("o4");
    const isGpt5 = modelLower.includes("gpt-5");

    return isOseries || isGpt5 || isGpt6Astra(modelLower) || isGpt6SolOrLuna(modelLower);
  }

  function isPostGpt51(modelLower) {
    const minorMatch = modelLower.match(/gpt-5\.(\d+)/);
    if (minorMatch) {
      return Number.parseInt(minorMatch[1], 10) >= 2;
    }

    const majorMatch = modelLower.match(/gpt-(\d+)/);
    if (majorMatch) {
      return Number.parseInt(majorMatch[1], 10) >= 6;
    }

    return false;
  }

  function isGpt5Pro(modelLower) {
    return /gpt-5(\.\d+)?-pro/.test(modelLower);
  }

  function isModernGpt5Pro(modelLower) {
    const minorMatch = modelLower.match(/gpt-5\.(\d+)-pro/);
    if (minorMatch) {
      return Number.parseInt(minorMatch[1], 10) >= 2;
    }

    const majorMatch = modelLower.match(/gpt-(\d+)-pro/);
    if (majorMatch) {
      return Number.parseInt(majorMatch[1], 10) >= 6;
    }

    return false;
  }

  function isCodexModel(modelLower) {
    return modelLower.includes("codex");
  }

  function isPostGpt51Codex(modelLower) {
    return isCodexModel(modelLower) && isPostGpt51(modelLower);
  }

  function getReasoningRule(modelLower) {
    if (isGpt6Astra(modelLower)) {
      return {
        allowed: ["low", "medium", "high", "xhigh", "max"],
        defaultValue: "low",
      };
    }

    if (isGpt6SolOrLuna(modelLower)) {
      return {
        allowed: ["none", "low", "medium", "high", "xhigh", "max"],
        defaultValue: "medium",
      };
    }

    if (isGpt5Pro(modelLower)) {
      return isModernGpt5Pro(modelLower)
        ? { allowed: ["medium", "high", "xhigh"], defaultValue: "medium" }
        : { allowed: ["high"], defaultValue: "high" };
    }

    if (isCodexModel(modelLower)) {
      return isPostGpt51Codex(modelLower)
        ? { allowed: ["low", "medium", "high", "xhigh"], defaultValue: "medium" }
        : { allowed: ["low", "medium", "high"], defaultValue: "medium" };
    }

    if (modelLower.includes("gpt-5.1")) {
      return {
        allowed: ["none", "low", "medium", "high"],
        defaultValue: "none",
      };
    }

    if (isPostGpt51(modelLower)) {
      return {
        allowed: ["none", "low", "medium", "high", "xhigh"],
        defaultValue: "none",
      };
    }

    return { allowed: ["low", "medium", "high"], defaultValue: "medium" };
  }

  function getOpenRouterModels() {
    const models = window.aipkit_dashboard?.models?.openrouter;
    return Array.isArray(models) ? models : [];
  }

  function getOpenRouterReasoningRule(model) {
    const unsupported = {
      supported: false,
      allowed: [],
      defaultValue: "",
      mandatory: false,
    };
    const modelId = String(model || "");
    const modelLower = modelId.toLowerCase();
    if (
      !modelLower ||
      modelLower === "openrouter/auto" ||
      modelLower === "auto" ||
      modelLower.includes("auto-router")
    ) {
      return unsupported;
    }

    const metadata = getOpenRouterModels().find(
      (candidate) =>
        candidate && String(candidate.id || "").toLowerCase() === modelLower
    );
    if (!metadata) {
      return unsupported;
    }

    const hasReasoningMetadata = Object.prototype.hasOwnProperty.call(
      metadata,
      "reasoning"
    );
    const reasoning =
      hasReasoningMetadata && metadata.reasoning && typeof metadata.reasoning === "object"
        ? metadata.reasoning
        : {};
    const mandatory = reasoning.mandatory === true;
    const apiToUi = (effort) => {
      if (effort === "minimal") return "low";
      if (effort === "max") return "xhigh";
      return effort;
    };
    let effortValues = [];

    if (hasReasoningMetadata) {
      if (!Object.prototype.hasOwnProperty.call(reasoning, "supported_efforts")) {
        return unsupported;
      }
      const supportedEfforts = reasoning.supported_efforts;
      const apiEfforts = supportedEfforts === null
        ? ["low", "medium", "high", "xhigh"]
        : Array.isArray(supportedEfforts)
          ? supportedEfforts
          : [];
      effortValues = apiEfforts
        .map((effort) => apiToUi(String(effort || "").toLowerCase()))
        .filter((effort) => ["low", "medium", "high", "xhigh"].includes(effort));
    } else {
      const supportedParameters = Array.isArray(metadata.supported_parameters)
        ? metadata.supported_parameters.map((parameter) =>
            String(parameter || "").toLowerCase()
          )
        : [];
      if (
        !supportedParameters.includes("reasoning") &&
        !supportedParameters.includes("reasoning_effort")
      ) {
        return unsupported;
      }
      effortValues = ["low", "medium", "high", "xhigh"];
    }

    effortValues = Array.from(new Set(effortValues));
    if (effortValues.length === 0) {
      return unsupported;
    }

    const allowed = mandatory ? effortValues : ["none", ...effortValues];
    let defaultValue = apiToUi(
      String(reasoning.default_effort || "").toLowerCase()
    );
    if (!allowed.includes(defaultValue)) {
      defaultValue = !mandatory && reasoning.default_enabled !== true
        ? "none"
        : allowed.includes("medium")
          ? "medium"
          : effortValues[0];
    }

    return {
      supported: true,
      allowed,
      defaultValue,
      mandatory,
    };
  }

  /**
   * AI Puffer Cloud publishes each model's reasoning levels with the synced catalog. The lowest level
   * is the default (Cloud applies the same default when none is sent) to keep cost predictable.
   */
  function getCloudReasoningRule(model) {
    const unsupported = { supported: false, allowed: [], defaultValue: "", mandatory: false };
    const models = window.aipkit_dashboard?.models?.aipuffercloud;
    const entry = Array.isArray(models)
      ? models.find((candidate) => candidate && candidate.id === String(model || ""))
      : null;
    const levels = Array.isArray(entry?.reasoning?.levels) ? entry.reasoning.levels : [];
    const toUi = (level) => (level === "minimal" ? "low" : level === "max" ? "xhigh" : level);
    const allowed = Array.from(new Set(levels.map((level) => toUi(String(level)))))
      .filter((level) => ["none", "low", "medium", "high", "xhigh"].includes(level));
    if (!allowed.length || (allowed.length === 1 && allowed[0] === "none")) {
      return unsupported;
    }
    return { supported: true, allowed, defaultValue: allowed[0], mandatory: !allowed.includes("none") };
  }

  function getProviderReasoningRule(provider, model) {
    const providerLower = String(provider || "").toLowerCase();
    const modelLower = String(model || "").toLowerCase();
    if (providerLower === "openai" && isOpenAIReasoningModel(modelLower)) {
      return { supported: true, ...getReasoningRule(modelLower) };
    }
    if (providerLower === "openrouter") {
      return getOpenRouterReasoningRule(model);
    }
    if (providerLower === "aipuffercloud") {
      return getCloudReasoningRule(model);
    }
    return {
      supported: false,
      allowed: [],
      defaultValue: "",
      mandatory: false,
    };
  }

  function syncReasoningOptions(reasoningSelect, allowedValues, defaultValue) {
    if (!reasoningSelect) {
      return;
    }

    const allowed = new Set(allowedValues);

    Array.from(reasoningSelect.options).forEach((option) => {
      const normalizedValue = normalizeReasoningValue(option.value);
      const isAllowed = allowed.has(normalizedValue);
      option.disabled = !isAllowed;
      option.hidden = !isAllowed;
    });

    const currentValue = normalizeReasoningValue(reasoningSelect.value || "");
    const nextValue = allowed.has(currentValue)
      ? currentValue
      : allowed.has(defaultValue)
        ? defaultValue
        : allowedValues[0] || "";

    if (nextValue && reasoningSelect.value !== nextValue) {
      reasoningSelect.value = nextValue;
      reasoningSelect.dispatchEvent(new Event("change", { bubbles: true }));
    }

    reasoningSelect._aipkitSegmentedSync?.();
    reasoningSelect._aipkitReasoningSliderSync?.();
  }

  function updateLastVisibleAiPopoverRow(formContainer, optionsListSelector) {
    if (!formContainer || !optionsListSelector) {
      return;
    }

    const optionsList = formContainer.querySelector(optionsListSelector);
    if (!optionsList) {
      return;
    }

    const rows = optionsList.querySelectorAll(".aipkit_popover_option_row");
    let lastVisibleRow = null;

    rows.forEach((row) => {
      row.classList.remove("aipkit_last_visible_row");
      if (!row.hidden && row.style.display !== "none") {
        lastVisibleRow = row;
      }
    });

    if (lastVisibleRow) {
      lastVisibleRow.classList.add("aipkit_last_visible_row");
    }
  }

  function updateLastVisibleSetupRow(reasoningField) {
    const setupFields = reasoningField?.closest(
      ".aipkit_autogpt_setup_fields"
    );
    if (!setupFields) {
      return;
    }

    const rows = Array.from(setupFields.children).filter((row) =>
      row.matches?.(".aipkit_autogpt_question_row")
    );
    let lastVisibleRow = null;

    rows.forEach((row) => {
      row.classList.remove("aipkit_autogpt_last_visible_question_row");
      if (!row.hidden && row.style.display !== "none") {
        lastVisibleRow = row;
      }
    });

    lastVisibleRow?.classList.add(
      "aipkit_autogpt_last_visible_question_row"
    );
  }

  function toggleAutogptReasoningEffort(config = {}) {
    const formContainer =
      config.formContainer ||
      document.getElementById("aipkit_automated_task_form");
    if (!formContainer) {
      return;
    }

    const providerSelect = formContainer.querySelector(config.providerSelector || "");
    const modelSelect = formContainer.querySelector(config.modelSelector || "");
    const reasoningField = formContainer.querySelector(config.fieldSelector || "");
    const reasoningSelect = formContainer.querySelector(config.selectSelector || "");
    const optionsListSelector = config.optionsListSelector || "";

    if (!providerSelect || !modelSelect || !reasoningField || !reasoningSelect) {
      if (reasoningField) {
        reasoningField.hidden = true;
      }
      updateLastVisibleSetupRow(reasoningField);
      updateLastVisibleAiPopoverRow(formContainer, optionsListSelector);
      return;
    }

    const provider = String(providerSelect.value || "").toLowerCase();
    const model = String(modelSelect.value || "");
    const rule = getProviderReasoningRule(provider, model);
    const shouldShow = rule.supported;

    reasoningField.hidden = !shouldShow;

    if (shouldShow) {
      syncReasoningOptions(reasoningSelect, rule.allowed, rule.defaultValue);
    }

    updateLastVisibleSetupRow(reasoningField);
    updateLastVisibleAiPopoverRow(formContainer, optionsListSelector);
  }

  const utils = {
    normalizeReasoningValue,
    isOpenAIReasoningModel,
    getReasoningRule,
    getOpenRouterReasoningRule,
    getCloudReasoningRule,
    getProviderReasoningRule,
    syncReasoningOptions,
    updateLastVisibleAiPopoverRow,
    updateLastVisibleSetupRow,
    toggleAutogptReasoningEffort,
  };

  window.aipkit_reasoning_effort_utils = utils;
  window.aipkit_autogpt_reasoning_utils = utils;
})();
