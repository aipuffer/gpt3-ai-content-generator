/**
 * AIPKit Automated Tasks - Handle Target Store Provider Change
 * Handles the change event of the target vector store provider select.
 */
(function () {
  "use strict";
  function aipkit_handleTargetStoreProviderChange(event) {
    const selectedProvider = event.target.value;
    const embeddingModelGroup = document.getElementById(
      "aipkit_task_content_indexing_embedding_model_group"
    );

    if (
      typeof window.aipkit_populateTargetStoreSelectForContentIndexing ===
      "function"
    ) {
      window.aipkit_populateTargetStoreSelectForContentIndexing(
        selectedProvider
      );
      if (
        ["local", "openai", "pinecone", "qdrant", "chroma"].includes(selectedProvider) &&
        typeof window.aipkit_refreshVectorStoreProviderList === "function"
      ) {
        window.aipkit_refreshVectorStoreProviderList(selectedProvider);
      }
    } else {
      console.error(
        "Target Store Provider Change Handler: populateTargetStoreSelectForContentIndexing function not found."
      );
    }

    if (embeddingModelGroup) {
      const showEmbeddingConfig =
        selectedProvider === "local" || selectedProvider === "pinecone" ||
        selectedProvider === "qdrant" ||
        selectedProvider === "chroma";
      embeddingModelGroup.hidden = !showEmbeddingConfig;

      if (
        showEmbeddingConfig &&
        typeof window.aipkit_populateEmbeddingModelSelectForContentIndexing ===
          "function"
      ) {
        window.aipkit_populateEmbeddingModelSelectForContentIndexing();
      }
    }
  }
  // Expose the handler function globally
  window.aipkit_handleTargetStoreProviderChange =
    aipkit_handleTargetStoreProviderChange;
})();
