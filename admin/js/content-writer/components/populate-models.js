/**
 * AIPKit Content Writer - Combined AI Model Populator
 * Builds one grouped model list and keeps the hidden provider/model fields in sync.
 */
(function () {
  "use strict";

  const escaper =
    window.aipkit_escapeHtml ||
    function (str) {
      return str;
    };
  const translate =
    window.wp?.i18n?.__ ||
    function (str) {
      return str;
    };

  function normalizeProviderKey(value) {
    return String(value || "").trim().toLowerCase();
  }

  function buildSelectionValue(providerKey, modelId) {
    return `${encodeURIComponent(providerKey)}::${encodeURIComponent(modelId)}`;
  }

  function resolveCombinedModelSelect(target) {
    if (target && typeof target === "object" && target.nodeType === 1) {
      return target;
    }

    if (typeof target === "string" && target) {
      return document.getElementById(target);
    }

    return document.getElementById("aipkit_content_writer_ai_selection");
  }

  function getProviderEntries(providerSelect) {
    if (!providerSelect?.options) {
      return [];
    }

    return Array.from(providerSelect.options)
      .filter((option) => option.value && !option.disabled && !option.hidden)
      .map((option) => ({
        key: normalizeProviderKey(option.value),
        label: option.textContent.trim(),
      }))
      .filter((entry) => entry.key);
  }

  function getProviderModelItems(providerKey, providerModelsData, recommendedList) {
    const items = [];
    const seen = new Set();

    const recommendedIds = new Set(
      (Array.isArray(recommendedList) ? recommendedList : [])
        .map((model) => String(model?.id || ""))
        .filter(Boolean)
    );

    const appendModel = (model, parentFamilyLabel = "", parentFamilyOrder = 999) => {
      if (!model || typeof model !== "object" || !model.id) {
        return;
      }

      const modelId = String(model.id);
      if (seen.has(modelId)) {
        return;
      }

      seen.add(modelId);
      items.push({
        provider: providerKey,
        id: modelId,
        label: String(model.name || model.id),
        isRecommended: recommendedIds.has(modelId) || Boolean(model.recommended),
        familyKey: String(model.family_key || "other"),
        familyLabel: String(model.family_label || parentFamilyLabel || ""),
        familyOrder: Number(model.family_order ?? parentFamilyOrder),
        familyCollapsed: Boolean(model.family_collapsed),
      });
    };

    if (
      providerModelsData &&
      typeof providerModelsData === "object" &&
      !Array.isArray(providerModelsData)
    ) {
      Object.entries(providerModelsData).forEach(([groupName, groupItems], index) => {
        if (Array.isArray(groupItems)) {
          groupItems.forEach((model) => appendModel(model, groupName, index));
        }
      });
    } else if (Array.isArray(providerModelsData)) {
      providerModelsData.forEach(appendModel);
    }

    if (Array.isArray(recommendedList)) {
      recommendedList.forEach(appendModel);
    }

    return items;
  }

  function findOptionByProviderAndModel(select, providerKey, modelId) {
    if (!select || !select.options) {
      return null;
    }

    return (
      Array.from(select.options).find(
        (option) =>
          normalizeProviderKey(option.dataset.provider) ===
            normalizeProviderKey(providerKey) &&
          String(option.dataset.model || "") === String(modelId || "")
      ) || null
    );
  }

  function findFirstOptionForProvider(select, providerKey) {
    if (!select || !select.options) {
      return null;
    }

    return (
      Array.from(select.options).find(
        (option) =>
          option.value &&
          normalizeProviderKey(option.dataset.provider) ===
            normalizeProviderKey(providerKey)
      ) || null
    );
  }

  function syncHiddenAiFields(combinedSelect, providerField, modelField) {
    if (!combinedSelect || !providerField || !modelField) {
      return { providerChanged: false, modelChanged: false };
    }

    const selectedOption =
      combinedSelect.selectedOptions && combinedSelect.selectedOptions.length
        ? combinedSelect.selectedOptions[0]
        : null;

    const nextProvider = normalizeProviderKey(
      selectedOption?.dataset?.provider || ""
    );
    const nextModel = String(selectedOption?.dataset?.model || "");

    const providerChanged =
      normalizeProviderKey(providerField.value) !== nextProvider;
    const modelChanged = String(modelField.value || "") !== nextModel;

    providerField.value = nextProvider;
    modelField.value = nextModel;

    return { providerChanged, modelChanged };
  }

  function populateCombinedAiModels(providerField, modelField, combinedTarget) {
    const combinedSelect = resolveCombinedModelSelect(combinedTarget);
    const allModelsData = window.aipkit_dashboard?.models;

    if (!combinedSelect || !providerField || !modelField) {
      console.error(
        "Content Writer Model Populator: Missing combined select or hidden AI fields."
      );
      return;
    }

    const previousSelection = combinedSelect.selectedOptions?.[0];
    combinedSelect.innerHTML = "";

    const rawProviderEntries = getProviderEntries(providerField);
    const setup = window.aipkit_autogpt_provider_setup;
    const providerEntries =
      providerField.dataset.aipkitPreferConfigured === "1" &&
      typeof setup?.sortProviderEntries === "function"
        ? setup.sortProviderEntries(rawProviderEntries)
        : rawProviderEntries;
    const currentProvider = normalizeProviderKey(providerField.value || previousSelection?.dataset.provider);
    const currentModel = String(modelField.value || "");
    const isContentWriterSelect =
      combinedSelect.id === "aipkit_content_writer_ai_selection";
    const pendingModel = isContentWriterSelect
      ? String(window.aipkit_cw_template_state?.pendingModelSelection || "")
      : "";

    let firstOverallOption = null;

    providerEntries.forEach((providerEntry) => {
      const providerModelsData = allModelsData?.[providerEntry.key];
      const recommendedList =
        window.aipkit_dashboard?.recommendedModels?.[providerEntry.key] || [];
      const modelItems = getProviderModelItems(
        providerEntry.key,
        providerModelsData,
        recommendedList
      );

      if (!modelItems.length) {
        return;
      }

      const optgroup = document.createElement("optgroup");
      optgroup.label = escaper(providerEntry.label);

      modelItems.forEach((modelItem) => {
        const option = new Option(
          escaper(modelItem.label),
          buildSelectionValue(modelItem.provider, modelItem.id)
        );
        option.dataset.provider = modelItem.provider;
        option.dataset.model = modelItem.id;
        option.dataset.providerLabel = providerEntry.label;
        if (providerEntry.setupState === "missing") {
          option.dataset.setupRequired = "true";
        }
        option.dataset.recommended = modelItem.isRecommended ? "true" : "false";
        option.dataset.familyKey = modelItem.familyKey;
        option.dataset.familyLabel = modelItem.familyLabel;
        option.dataset.familyOrder = String(modelItem.familyOrder);
        option.dataset.familyCollapsed = modelItem.familyCollapsed
          ? "true"
          : "false";
        if (!isContentWriterSelect) {
          option.dataset.pickerLabel = `${providerEntry.label} · ${modelItem.label}`;
        }
        optgroup.appendChild(option);
        if (!firstOverallOption) {
          firstOverallOption = option;
        }
      });

      combinedSelect.appendChild(optgroup);
    });

    const requestedModel = pendingModel || currentModel;
    let preferredOption = requestedModel
      ? findOptionByProviderAndModel(combinedSelect, currentProvider, requestedModel)
      : findFirstOptionForProvider(combinedSelect, currentProvider);

    if (requestedModel && !preferredOption) {
      preferredOption = new Option(
        `${requestedModel} — ${translate("Unavailable", "gpt3-ai-content-generator")}`,
        buildSelectionValue(currentProvider, requestedModel)
      );
      preferredOption.dataset.provider = currentProvider;
      preferredOption.dataset.model = requestedModel;
      preferredOption.disabled = true;
      combinedSelect.appendChild(preferredOption);
    } else if (!preferredOption) {
      preferredOption = !currentProvider ? firstOverallOption : null;
      if (!preferredOption) {
        preferredOption = new Option(
          translate("Select model", "gpt3-ai-content-generator"), ""
        );
        preferredOption.dataset.provider = currentProvider;
        combinedSelect.appendChild(preferredOption);
      }
    }

    combinedSelect.disabled = false;
    combinedSelect.value = preferredOption.value;
    // A disconnected provider may have been removed from the hidden select.
    if (currentProvider && !Array.from(providerField.options).some((option) => option.value === currentProvider)) {
      const savedProvider = new Option(currentProvider, currentProvider);
      savedProvider.hidden = true;
      providerField.appendChild(savedProvider);
    }

    syncHiddenAiFields(combinedSelect, providerField, modelField);
    combinedSelect._aipkitUnifiedModelSync?.();

    if (isContentWriterSelect && window.aipkit_cw_template_state) {
      window.aipkit_cw_template_state.pendingModelSelection = null;
    }

    if (typeof window.aipkit_checkFormForModifications === "function") {
      window.aipkit_checkFormForModifications();
    }

    if (
      isContentWriterSelect &&
      typeof window.aipkit_cw_toggleReasoningEffort === "function"
    ) {
      const mainContainer = document.getElementById(
        "aipkit_content_writer_container"
      );
      if (mainContainer) {
        window.aipkit_cw_toggleReasoningEffort(mainContainer);
      }
    }
  }

  function populateModelsForContentWriter(providerField, modelField) {
    populateCombinedAiModels(providerField, modelField);
  }

  window.aipkit_populateModelsForContentWriter = populateModelsForContentWriter;
  window.aipkit_syncContentWriterAiSelectionFields = syncHiddenAiFields;
  window.aipkit_populateCombinedAiModels = populateCombinedAiModels;
})();
