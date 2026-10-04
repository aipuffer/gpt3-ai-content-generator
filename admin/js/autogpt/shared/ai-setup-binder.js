/**
 * AIPKit AutoGPT - Shared AI setup binder
 * Attaches provider/model/combined picker sync for task setup panels.
 */
(function () {
  "use strict";

  if (typeof window.aipkit_bindAutogptAiSetup === "function") {
    return;
  }

  function getDatasetKey(scope, suffix) {
    return `aipkit${String(scope || "").toUpperCase()}${suffix}`;
  }

  function aipkit_bindAutogptAiSetup(config = {}) {
    const form =
      config.form || document.getElementById("aipkit_automated_task_form");
    if (!form) {
      return;
    }

    const providerSelect = form.querySelector(config.providerSelector || "");
    const modelSelect = form.querySelector(config.modelSelector || "");
    const combinedModelSelect = form.querySelector(config.combinedSelector || "");
    const populateModels = config.populateModels;
    const reasoningToggle = config.reasoningToggle;
    const scope = String(config.scope || "cw").toLowerCase();

    if (!providerSelect || !modelSelect || typeof populateModels !== "function") {
      return;
    }

    const providerListenerKey = getDatasetKey(scope, "ProviderListenerAttached");
    const modelListenerKey = getDatasetKey(scope, "ModelListenerAttached");
    const combinedListenerKey = getDatasetKey(scope, "CombinedListenerAttached");

    if (combinedModelSelect) {
      combinedModelSelect._aipkitRefreshModelOptions = () =>
        populateModels(providerSelect, modelSelect, combinedModelSelect);
    }

    if (providerSelect.dataset[providerListenerKey] !== "true") {
      providerSelect.addEventListener("change", () => {
        window.aipkit_autogpt_provider_setup?.syncFallbackNote?.(
          providerSelect,
          null
        );
        populateModels(providerSelect, modelSelect, combinedModelSelect);
      });
      providerSelect.dataset[providerListenerKey] = "true";
    }

    if (modelSelect.dataset[modelListenerKey] !== "true") {
      modelSelect.addEventListener("change", () => {
        if (typeof reasoningToggle === "function") {
          reasoningToggle();
        }

        if (
          combinedModelSelect &&
          typeof window.aipkit_populateCombinedAiModels === "function"
        ) {
          window.aipkit_populateCombinedAiModels(
            providerSelect,
            modelSelect,
            combinedModelSelect
          );
        }
      });
      modelSelect.dataset[modelListenerKey] = "true";
    }

    if (
      combinedModelSelect &&
      combinedModelSelect.dataset[combinedListenerKey] !== "true"
    ) {
      combinedModelSelect.addEventListener("change", () => {
        window.aipkit_autogpt_provider_setup?.syncFallbackNote?.(
          providerSelect,
          null
        );
        const selectedOption =
          combinedModelSelect.selectedOptions &&
          combinedModelSelect.selectedOptions.length
            ? combinedModelSelect.selectedOptions[0]
            : null;
        const nextProvider = String(
          selectedOption?.dataset?.provider || ""
        ).trim().toLowerCase();
        const nextModel = String(selectedOption?.dataset?.model || "");

        if (!nextProvider || !nextModel) {
          return;
        }

        const providerChanged =
          String(providerSelect.value || "").trim().toLowerCase() !==
          nextProvider;
        const modelChanged = String(modelSelect.value || "") !== nextModel;

        if (providerChanged) {
          if (window.aipkit_automated_tasks_form_state) {
            window.aipkit_automated_tasks_form_state.pendingModelSelection =
              nextModel;
          }
          providerSelect.value = nextProvider;
          providerSelect.dispatchEvent(new Event("change", { bubbles: true }));
          return;
        }

        if (modelChanged) {
          modelSelect.value = nextModel;
          modelSelect.dispatchEvent(new Event("change", { bubbles: true }));
        }
      });
      combinedModelSelect.dataset[combinedListenerKey] = "true";
    }

    if (window.aipkit_dashboard?.models) {
      populateModels(providerSelect, modelSelect, combinedModelSelect);
    } else if (
      combinedModelSelect &&
      typeof window.aipkit_populateCombinedAiModels === "function"
    ) {
      window.aipkit_populateCombinedAiModels(
        providerSelect,
        modelSelect,
        combinedModelSelect
      );
    }

    if (typeof reasoningToggle === "function") {
      reasoningToggle();
    }
  }

  window.aipkit_bindAutogptAiSetup = aipkit_bindAutogptAiSetup;
})();
