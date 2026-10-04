/**
 * AIPKit Automated Tasks - Form Content Indexing Population
 * Populates fields specific to the 'content_indexing' task type.
 */
(function () {
  "use strict";
  const escaper =
    window.aipkit_escapeHtml ||
    function (str) {
      return str;
    };

  /**
   * Populates the form fields for a 'content_indexing' task.
   * @param {Object} taskConfig - The configuration object for the task.
   */
  function aipkit_form_populateContentIndexingFields(taskConfig) {
    const form = document.getElementById("aipkit_automated_task_form");
    if (!form) return;

    const targetStoreProvider =
      taskConfig.target_store_provider || "openai";
    const targetStoreId = taskConfig.target_store_id || "";

    if (window.aipkit_automated_tasks_form_state && targetStoreId) {
      window.aipkit_automated_tasks_form_state.pendingTargetStoreSelection = {
        provider: targetStoreProvider,
        value: targetStoreId,
      };
    }

    const categoriesSelect = form.elements["indexing_categories[]"];
    if (categoriesSelect) {
      const selected = (taskConfig.indexing_categories || []).map(String);
      Array.from(categoriesSelect.options).forEach((option) => {
        option.selected = selected.includes(option.value);
      });
      categoriesSelect._aipkitChecklistSync?.();
    }

    // Populate multi-select for Post Types
    const postTypesSelect = form.elements["post_types[]"];
    if (postTypesSelect) {
      Array.from(postTypesSelect.options).forEach(
        (opt) => (opt.selected = false)
      );
      (taskConfig.post_types || []).forEach((pt) => {
        const option = postTypesSelect.querySelector(
          `option[value="${escaper(pt)}"]`
        );
        if (option) option.selected = true;
      });
    }

    // Populate Target Store Provider and trigger sub-elements
    const targetStoreProviderSelect = form.elements["target_store_provider"];
    if (targetStoreProviderSelect) {
      targetStoreProviderSelect.value = targetStoreProvider;
      // --- FIX: Dispatch change event to ensure dependent UI updates ---
      targetStoreProviderSelect.dispatchEvent(
        new Event("change", { bubbles: true })
      );
      // --- END FIX ---
    }

    // Populate Target Store ID (async population of dropdown)
    if (
      typeof window.aipkit_populateTargetStoreSelectForContentIndexing ===
      "function"
    ) {
      window
        .aipkit_populateTargetStoreSelectForContentIndexing(
          targetStoreProvider
        )
        .then(() => {
          const targetStoreIdSelect = form.elements["target_store_id"];
          if (targetStoreIdSelect) {
            targetStoreIdSelect.value = targetStoreId;
          }
        });
    }

    // Populate checkboxes
    if (form.elements["index_existing_now_flag"]) {
      form.elements["index_existing_now_flag"].checked =
        taskConfig.index_existing_now_flag === "1";
    }
    if (form.elements["only_new_updated_flag"]) {
      form.elements["only_new_updated_flag"].checked =
        taskConfig.only_new_updated_flag === "1";
    }

    // Set pending value for combined embedding model dropdown
    if (
      taskConfig.target_store_provider === "local" ||
      taskConfig.target_store_provider === "pinecone" ||
      taskConfig.target_store_provider === "qdrant" ||
      taskConfig.target_store_provider === "chroma"
    ) {
      if (
        window.aipkit_automated_tasks_form_state &&
        taskConfig.embedding_provider &&
        taskConfig.embedding_model
      ) {
        window.aipkit_automated_tasks_form_state.pendingEmbeddingModelSelection = `${taskConfig.embedding_provider}::${taskConfig.embedding_model}`;
      }
      // Trigger the handler to populate the new dropdown, which will then use the pending value
      if (
        typeof window.aipkit_populateEmbeddingModelSelectForContentIndexing ===
        "function"
      ) {
        window.aipkit_populateEmbeddingModelSelectForContentIndexing();
      }
    }

    // Populate task frequency
    if (form.elements["task_frequency"]) {
      form.elements["task_frequency"].value =
        taskConfig.indexing_frequency || "daily";
    }
  }

  window.aipkit_form_populateContentIndexingFields =
    aipkit_form_populateContentIndexingFields;
})();
