/**
 * AIPKit AutoGPT - Shared AI model populator
 * Reused by AutoGPT task forms that keep hidden provider/model fields plus a combined visible picker.
 */
(function () {
  "use strict";

  if (typeof window.aipkit_autogpt_populateModels === "function") {
    return;
  }

  const escaper =
    window.aipkit_escapeHtml ||
    function (str) {
      return str;
    };
  function normalizeProviderKey(value) {
    return String(value || "").trim().toLowerCase();
  }

  function getRecommendedModels(providerKey, includeRecommended) {
    if (!includeRecommended) {
      return [];
    }

    const recommendedModels =
      window.aipkit_dashboard?.recommendedModels?.[providerKey] || [];

    return Array.isArray(recommendedModels) ? recommendedModels : [];
  }

  function aipkit_autogpt_populateModels(providerSelect, modelSelect, options = {}) {
    if (!providerSelect || !modelSelect) {
      return;
    }

    const selectedProviderLabel = providerSelect.value;
    const selectedProviderKey = normalizeProviderKey(selectedProviderLabel);
    const allModelsData = window.aipkit_dashboard?.models;
    const formState = window.aipkit_automated_tasks_form_state || {};
    const combinedTarget = options.combinedTarget || null;
    const reasoningToggle =
      typeof options.reasoningToggle === "function"
        ? options.reasoningToggle
        : null;
    const includeRecommended = options.includeRecommended !== false;

    if (!allModelsData) {
      modelSelect.innerHTML = `<option value="">${escaper(
        "Error: Model data object not found"
      )}</option>`;
      modelSelect.disabled = true;
      return;
    }

    let providerModelsData = allModelsData[selectedProviderKey];
    if (typeof providerModelsData === "undefined" || providerModelsData === null) {
      console.warn(
        `AutoGPT Model Populator: No model data for provider '${selectedProviderKey}'.`
      );
      providerModelsData = selectedProviderKey === "openai" ? {} : [];
    }

    const oldValue = formState.pendingModelSelection || modelSelect.value;
    modelSelect.innerHTML = "";

    const recommendedList = getRecommendedModels(
      selectedProviderKey,
      includeRecommended
    );
    const recommendedIds = new Set(
      recommendedList.map((model) => model?.id).filter(Boolean)
    );
    const seenModelIds = new Set();
    const appendModel = (model, familyLabel = "", familyOrder = 999) => {
      if (!model || !model.id || seenModelIds.has(String(model.id))) {
        return;
      }

      const modelId = String(model.id);
      const option = new Option(escaper(model.name || modelId), modelId);
      option.dataset.recommended =
        recommendedIds.has(modelId) || model.recommended ? "true" : "false";
      option.dataset.familyKey = String(model.family_key || "other");
      option.dataset.familyLabel = String(model.family_label || familyLabel || "");
      option.dataset.familyOrder = String(model.family_order ?? familyOrder);
      option.dataset.familyCollapsed = model.family_collapsed ? "true" : "false";
      modelSelect.appendChild(option);
      seenModelIds.add(modelId);
    };

    if (Array.isArray(providerModelsData)) {
      providerModelsData.forEach((model) => appendModel(model));
    } else if (providerModelsData && typeof providerModelsData === "object") {
      Object.entries(providerModelsData).forEach(([familyLabel, models], index) => {
        if (Array.isArray(models)) {
          models.forEach((model) => appendModel(model, familyLabel, index));
        }
      });
    } else {
      console.warn(
        `AutoGPT Model Populator: Unexpected format for provider '${selectedProviderKey}'.`
      );
      modelSelect.appendChild(
        new Option(
          escaper(`No models for ${selectedProviderLabel} (Format Error)`),
          ""
        )
      );
    }

    recommendedList.forEach((model) => appendModel(model));

    if (oldValue && !seenModelIds.has(String(oldValue))) {
      const unavailable = new Option(
        `${oldValue} — ${window.wp?.i18n?.__("Unavailable", "gpt3-ai-content-generator") || "Unavailable"}`,
        oldValue
      );
      unavailable.disabled = true;
      modelSelect.appendChild(unavailable);
    }
    if (oldValue) {
      modelSelect.value = oldValue;
      modelSelect.disabled = false;
    } else if (modelSelect.options.length === 0) {
      modelSelect.appendChild(
        new Option(escaper(`No models for ${selectedProviderLabel}`), "")
      );
      modelSelect.disabled = true;
    } else {
      modelSelect.disabled = false;
    }

    formState.pendingModelSelection = null;

    if (
      combinedTarget &&
      typeof window.aipkit_populateCombinedAiModels === "function"
    ) {
      window.aipkit_populateCombinedAiModels(
        providerSelect,
        modelSelect,
        combinedTarget
      );
    }

    if (reasoningToggle) {
      reasoningToggle();
    }

    modelSelect.dispatchEvent(new Event("change", { bubbles: true }));
  }

  window.aipkit_autogpt_populateModels = aipkit_autogpt_populateModels;
})();
