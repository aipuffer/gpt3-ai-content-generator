/**
 * AIPKit Content Writer - Combined AI Selection Handler
 * Keeps the visible combined model picker and hidden provider/model fields aligned.
 */
(function () {
  "use strict";

  function initializeUnifiedModelSelector(combinedSelect, providerField, modelField) {
    const selector = document.querySelector(
      "#aipkit_content_writer_container .aipkit_cw_unified_model_selector"
    );
    if (
      !selector ||
      typeof window.aipkit_createUnifiedModelSelector !== "function" ||
      typeof window.aipkit_createUnifiedModelSelectAdapter !== "function"
    ) {
      return null;
    }

    const adapter = window.aipkit_createUnifiedModelSelectAdapter(combinedSelect);
    adapter.onSyncComplete = () => {
      window.aipkit_populateModelsForContentWriter(providerField, modelField);
    };
    const controller = window.aipkit_createUnifiedModelSelector(
      selector,
      adapter
    );
    if (!controller) {
      return null;
    }

    combinedSelect._aipkitUnifiedModelSync = () => controller.sync();
    controller.sync();
    return controller;
  }

  function aipkit_handleProviderModelDropdowns(providerField, modelField) {
    const combinedSelect = document.getElementById(
      "aipkit_content_writer_ai_selection"
    );

    if (
      !providerField ||
      !modelField ||
      !combinedSelect ||
      typeof window.aipkit_populateModelsForContentWriter !== "function" ||
      typeof window.aipkit_syncContentWriterAiSelectionFields !== "function"
    ) {
      console.error(
        "Content Writer AI Selection Handler: Missing required fields or model population helpers."
      );
      return;
    }

    initializeUnifiedModelSelector(combinedSelect, providerField, modelField);

    if (combinedSelect.dataset.aipkitCombinedAiBound !== "true") {
      combinedSelect.addEventListener("change", () => {
        const syncResult = window.aipkit_syncContentWriterAiSelectionFields(
          combinedSelect,
          providerField,
          modelField
        );

        if (syncResult.providerChanged) {
          providerField.dataset.aipkitCombinedSync = "true";
          providerField.dispatchEvent(new Event("change", { bubbles: true }));
        }

        if (syncResult.modelChanged) {
          modelField.dispatchEvent(new Event("change", { bubbles: true }));
        }

        // The hidden AI model field is an input, not a select, so the
        // Content Writer autosave listener does not autosave on its change
        // event. When the provider stays the same and only the model changes,
        // trigger autosave explicitly once here.
        if (
          !syncResult.providerChanged &&
          syncResult.modelChanged &&
          typeof window.aipkit_handleContentWriterAutoSave === "function"
        ) {
          window.aipkit_handleContentWriterAutoSave(combinedSelect);
        }
      });
      combinedSelect.dataset.aipkitCombinedAiBound = "true";
    }

    if (providerField.dataset.aipkitCombinedAiBound !== "true") {
      providerField.addEventListener("change", () => {
        if (providerField.dataset.aipkitCombinedSync === "true") {
          delete providerField.dataset.aipkitCombinedSync;
          return;
        }

        window.aipkit_populateModelsForContentWriter(providerField, modelField);
      });
      providerField.dataset.aipkitCombinedAiBound = "true";
    }

    if (window.aipkit_dashboard && window.aipkit_dashboard.models) {
      window.aipkit_populateModelsForContentWriter(providerField, modelField);
      return;
    }

    combinedSelect.innerHTML = '<option value="">Models unavailable</option>';
    combinedSelect.disabled = true;
    combinedSelect._aipkitUnifiedModelSync?.();
  }

  window.aipkit_handleProviderModelDropdowns =
    aipkit_handleProviderModelDropdowns;
})();
