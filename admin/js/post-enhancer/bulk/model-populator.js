/**
 * AIPKit Content Enhancer - Bulk Modal AI Model Populator
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
   * Toggles the visibility of the reasoning effort dropdown.
   * @param {HTMLElement} modal The root modal element.
   */
  function aipkit_enhancer_toggleReasoningEffort(modal) {
    const providerSelect = modal.querySelector("#aipkit_bulk_ai_provider");
    const modelSelect = modal.querySelector("#aipkit_bulk_ai_model");
    const reasoningField = modal.querySelector(
      ".aipkit_enhancer_reasoning_effort_field"
    );
    const reasoningSelect = modal.querySelector("#aipkit_bulk_reasoning_effort");

    if (!providerSelect || !modelSelect || !reasoningField || !reasoningSelect) {
      if (reasoningField) reasoningField.hidden = true;
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

    if (!rule.supported) {
      reasoningField.hidden = true;
      return;
    }

    reasoningField.hidden = false;
    reasoningUtils.syncReasoningOptions(
      reasoningSelect,
      rule.allowed,
      rule.defaultValue
    );
  }

  /**
   * Populates the model select dropdown based on the selected provider.
   * @param {HTMLSelectElement} providerSelect The provider select dropdown element.
   * @param {HTMLSelectElement} modelSelect The model select dropdown element to populate.
   * @param {string} preferredModel The model to preserve when the provider changes.
   */
  function aipkit_enhancer_populateBulkModels(
    providerSelect,
    modelSelect,
    preferredModel = "",
    preserveMissingModel = false
  ) {
    const selectedProviderKey = providerSelect.value.toLowerCase();
    const previousModel = preferredModel || modelSelect.value || "";
    const allModelsData = window.aipkit_dashboard?.models || {};
    const providerModelsData = allModelsData[selectedProviderKey] || [];
    const escaper = window.aipkit_escapeHtml || ((str) => str);
    const recommendedModels =
      window.aipkit_dashboard?.recommendedModels?.[selectedProviderKey] || [];
    const recommendedList = Array.isArray(recommendedModels)
      ? recommendedModels
      : [];
    const recommendedIds = new Set(
      recommendedList.map((model) => model?.id).filter(Boolean)
    );

    modelSelect.innerHTML = ""; // Clear current options

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
    }

    recommendedList.forEach((model) => appendModel(model));

    if (preserveMissingModel && previousModel && !seenModelIds.has(previousModel)) {
      const unavailable = new Option(
        `${previousModel} — ${window.wp?.i18n?.__("Unavailable", "gpt3-ai-content-generator") || "Unavailable"}`,
        previousModel
      );
      unavailable.disabled = true;
      modelSelect.appendChild(unavailable);
    }

    if (modelSelect.options.length === 0) {
      modelSelect.appendChild(
        new Option(escaper(`No models for ${providerSelect.value}`), "")
      );
      modelSelect.disabled = true;
    } else {
      modelSelect.disabled = false;

      const defaultModel =
        window.aipkit_resolveNewAiSelection?.({
          allowedProviders: [providerSelect.value],
          preferredProvider: providerSelect.value,
        })?.modelId || "";

      if (
        previousModel &&
        modelSelect.querySelector(
          `option[value="${CSS.escape(previousModel)}"]`
        )
      ) {
        modelSelect.value = previousModel;
      } else if (
        defaultModel &&
        modelSelect.querySelector(`option[value="${CSS.escape(defaultModel)}"]`)
      ) {
        modelSelect.value = defaultModel;
      } else {
        modelSelect.selectedIndex = 0;
      }
    }
    const modal = providerSelect.closest(".aipkit-enhancer-bulk-modal-overlay");
    if (modal) {
      aipkit_enhancer_toggleReasoningEffort(modal);
    }
  }

  function syncCombinedModelOptions(
    providerSelect,
    modelSelect,
    combinedSelect
  ) {
    if (
      !providerSelect ||
      !modelSelect ||
      !combinedSelect ||
      typeof window.aipkit_populateCombinedAiModels !== "function"
    ) {
      return;
    }

    window.aipkit_populateCombinedAiModels(
      providerSelect,
      modelSelect,
      combinedSelect
    );
    combinedSelect._aipkitUnifiedModelSync?.();
  }

  function applyCombinedModelSelection(
    providerSelect,
    modelSelect,
    combinedSelect
  ) {
    const selectedOption = combinedSelect.selectedOptions?.[0] || null;
    const nextProvider = String(
      selectedOption?.dataset?.provider || ""
    ).trim().toLowerCase();
    const nextModel = String(selectedOption?.dataset?.model || "");

    if (!nextProvider || !nextModel) {
      return;
    }

    if (
      String(providerSelect.value || "").trim().toLowerCase() !== nextProvider
    ) {
      providerSelect.dataset.pendingModelSelection = nextModel;
      providerSelect.value = nextProvider;
      providerSelect.dispatchEvent(new Event("change", { bubbles: true }));
      return;
    }

    if (String(modelSelect.value || "") !== nextModel) {
      modelSelect.value = nextModel;
      modelSelect.dispatchEvent(new Event("change", { bubbles: true }));
    }
  }

  /**
   * Binds the shared combined model selector while retaining the separate
   * provider/model fields used by templates and processing.
   * @param {HTMLElement} modal The root modal element.
   */
  function aipkit_enhancer_initUnifiedModelSelector(modal) {
    if (!modal || modal.dataset.aipkitUnifiedModelInit === "true") {
      return;
    }

    const providerSelect = modal.querySelector("#aipkit_bulk_ai_provider");
    const modelSelect = modal.querySelector("#aipkit_bulk_ai_model");
    const combinedSelect = modal.querySelector("#aipkit_bulk_ai_selection");
    const selector = modal.querySelector(
      ".aipkit_enhancer_unified_model_selector"
    );

    if (
      !providerSelect ||
      !modelSelect ||
      !combinedSelect ||
      !selector ||
      typeof window.aipkit_createUnifiedModelSelector !== "function" ||
      typeof window.aipkit_createUnifiedModelSelectAdapter !== "function"
    ) {
      return;
    }

    const syncFromHiddenFields = () => {
      aipkit_enhancer_toggleReasoningEffort(modal);
      syncCombinedModelOptions(providerSelect, modelSelect, combinedSelect);
    };

    providerSelect.addEventListener("change", () => {
      const pendingModel =
        providerSelect.dataset.pendingModelSelection || "";
      delete providerSelect.dataset.pendingModelSelection;
      aipkit_enhancer_populateBulkModels(
        providerSelect,
        modelSelect,
        pendingModel
      );
      syncFromHiddenFields();
    });

    modelSelect.addEventListener("change", syncFromHiddenFields);
    combinedSelect.addEventListener("change", () => {
      applyCombinedModelSelection(providerSelect, modelSelect, combinedSelect);
    });

    aipkit_enhancer_populateBulkModels(providerSelect, modelSelect);
    syncCombinedModelOptions(providerSelect, modelSelect, combinedSelect);

    const adapter =
      window.aipkit_createUnifiedModelSelectAdapter(combinedSelect);
    adapter.onSyncComplete = () => {
      aipkit_enhancer_populateBulkModels(providerSelect, modelSelect, "", true);
      syncFromHiddenFields();
    };
    const controller = window.aipkit_createUnifiedModelSelector(
      selector,
      adapter
    );
    if (controller) {
      combinedSelect._aipkitUnifiedModelSync = () => controller.sync();
      controller.sync();
    }

    aipkit_enhancer_toggleReasoningEffort(modal);
    modal.dataset.aipkitUnifiedModelInit = "true";
  }

  window.aipkit_enhancer_populateBulkModels =
    aipkit_enhancer_populateBulkModels;
  window.aipkit_enhancer_initUnifiedModelSelector =
    aipkit_enhancer_initUnifiedModelSelector;
  window.aipkit_enhancer_toggleReasoningEffort =
    aipkit_enhancer_toggleReasoningEffort;
})();
