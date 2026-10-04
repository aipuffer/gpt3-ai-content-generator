/**
 * AIPKit Automated Tasks - Populate Embedding Model Select
 * Populates the combined embedding model select for vector DB tasks.
 */
(function () {
  "use strict";

  function aipkit_populateEmbeddingModelSelectForContentIndexing() {
    const config = window.aipkit_automated_tasks_config || {};
    const texts = config.text || {};
    const escaper =
      window.aipkit_escapeHtml ||
      function (str) {
        return str;
      };
    const select = document.getElementById(
      "aipkit_task_content_indexing_embedding_model"
    );
    const formState = window.aipkit_automated_tasks_form_state || {};
    const pendingValue = formState.pendingEmbeddingModelSelection;

    if (!select) return Promise.resolve();

    const embeddingUtils = window.aipkit_embedding_utils || {};
    const resolveProviderEntries =
      typeof embeddingUtils.resolveProviderEntries === "function"
        ? embeddingUtils.resolveProviderEntries
        : () => [];
    const populateModelSelect =
      typeof embeddingUtils.populateModelSelect === "function"
        ? embeddingUtils.populateModelSelect
        : null;

    const rawProviderEntries = resolveProviderEntries(
      config.embedding_provider_map,
      config.embedding_models_by_provider,
      embeddingUtils.defaultProviderMap || undefined
    );
    const providerEntries =
      window.aipkit_autogpt_provider_setup?.sortProviderEntries?.(
        rawProviderEntries
      ) || rawProviderEntries;

    if (typeof populateModelSelect === "function") {
      const result = populateModelSelect(select, {
        grouped: true,
        providerEntries,
        modelsByProvider: config.embedding_models_by_provider,
        includePlaceholder: true,
        placeholderText: texts.select_embedding_model || "-- Select Model --",
        emptyText:
          texts.no_embedding_models_sync ||
          "No models found - Sync in AI Settings",
        selectedValue: pendingValue ?? select.value ?? "",
        preserveUnknownSelected: false,
        autoSelectFirst: false,
        escaper,
        valueFormatter: (providerKey, model) => `${providerKey}::${model.id}`,
      });

      if (pendingValue && result.matchedSelected) {
        formState.pendingEmbeddingModelSelection = null;
      }
      window.aipkit_autogpt_provider_setup?.annotateProviderOptions?.(select);
      return;
    }

    // Fallback if shared utility is unavailable.
    select.innerHTML = `<option value="">${escaper(
      texts.no_embedding_models_sync || "No models found - Sync in AI Settings"
    )}</option>`;
    select.disabled = true;
  }

  // Expose the function globally.
  window.aipkit_populateEmbeddingModelSelectForContentIndexing =
    aipkit_populateEmbeddingModelSelectForContentIndexing;
})();
