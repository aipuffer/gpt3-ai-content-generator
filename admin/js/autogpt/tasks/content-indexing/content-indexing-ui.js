/**
 * AIPKit Automated Tasks - Content Indexing Task UI Handler (Orchestrator)
 * Initializes event listeners for the "Content Indexing" specific fields.
 */
(function () {
  "use strict";
  let openaiStoreUpdateListenerAttached = false;

  function getSharedOpenaiStores() {
    const sharedConfig = window.aipkit_chat_config;
    if (!sharedConfig || typeof sharedConfig !== "object") {
      return null;
    }
    if (!Object.prototype.hasOwnProperty.call(sharedConfig, "openaiVectorStores")) {
      return null;
    }
    return Array.isArray(sharedConfig.openaiVectorStores)
      ? sharedConfig.openaiVectorStores
      : [];
  }

  function syncAutogptOpenaiStoresConfig(storesOverride = null) {
    const sharedStores = Array.isArray(storesOverride)
      ? storesOverride
      : getSharedOpenaiStores();
    if (sharedStores === null) {
      return;
    }
    if (
      !window.aipkit_automated_tasks_config ||
      typeof window.aipkit_automated_tasks_config !== "object"
    ) {
      window.aipkit_automated_tasks_config = {};
    }
    window.aipkit_automated_tasks_config.openai_vector_stores = sharedStores;
  }

  function refreshAutogptOpenaiStoreSelects() {
    const autogptContainer = document.getElementById("aipkit_autogpt_container");
    if (!autogptContainer) {
      return;
    }

    if (typeof window.aipkit_autogptSyncOpenaiVectorStoreSelects === "function") {
      window.aipkit_autogptSyncOpenaiVectorStoreSelects(autogptContainer);
    }

    const targetStoreProviderSelect = autogptContainer.querySelector(
      "#aipkit_task_content_indexing_target_store_provider"
    );
    window.aipkit_autogpt_provider_setup?.annotateProviderOptions?.(
      targetStoreProviderSelect,
      { nativeLabels: true }
    );
    if (
      targetStoreProviderSelect &&
      targetStoreProviderSelect.value === "openai" &&
      typeof window.aipkit_populateTargetStoreSelectForContentIndexing === "function"
    ) {
      window.aipkit_populateTargetStoreSelectForContentIndexing("openai");
    }
  }

  /**
   * Initializes event listeners for the "Content Indexing" specific fields.
   * Called by autogpt-main.js.
   */
  function aipkit_initContentIndexingTaskFormUI() {
    const autogptContainer = document.getElementById("aipkit_autogpt_container");
    if (!autogptContainer) {
      return;
    }

    syncAutogptOpenaiStoresConfig();
    if (!openaiStoreUpdateListenerAttached) {
      window.addEventListener("aipkit:vector-store-list-updated", (event) => {
        const provider = String(event?.detail?.provider || "").toLowerCase();
        if (!["local", "openai", "google", "pinecone", "qdrant", "chroma"].includes(provider)) {
          return;
        }
        const stores = Array.isArray(event?.detail?.stores)
          ? event.detail.stores
          : [];
        if (provider === "openai") {
          syncAutogptOpenaiStoresConfig(stores);
          refreshAutogptOpenaiStoreSelects();
        } else if (window.aipkit_automated_tasks_config) {
          const configKeyMap = {
            local: "local_stores",
            google: "google_file_search_stores",
            pinecone: "pinecone_indexes",
            qdrant: "qdrant_collections",
            chroma: "chroma_collections",
          };
          window.aipkit_automated_tasks_config[configKeyMap[provider]] = stores;
        }
        const targetStoreProviderSelect = document.getElementById(
          "aipkit_task_content_indexing_target_store_provider"
        );
        if (
          targetStoreProviderSelect &&
          String(targetStoreProviderSelect.value || "").toLowerCase() === provider &&
          typeof window.aipkit_populateTargetStoreSelectForContentIndexing === "function"
        ) {
          window.aipkit_populateTargetStoreSelectForContentIndexing(provider);
        }
      });
      openaiStoreUpdateListenerAttached = true;
    }

    const targetStoreProviderSelect = autogptContainer.querySelector(
      "#aipkit_task_content_indexing_target_store_provider"
    );

    // Attach listener for the main provider dropdown.
    if (targetStoreProviderSelect) {
      if (targetStoreProviderSelect.dataset.listenerAttached === "true") {
        // A new form or task-type change can select a different default provider.
        window.aipkit_handleTargetStoreProviderChange?.({ target: targetStoreProviderSelect });
        return;
      }
      targetStoreProviderSelect.addEventListener("change", () => {
        window.aipkit_autogpt_provider_setup?.syncControlFallbackNote?.(
          targetStoreProviderSelect,
          null
        );
      });
      if (typeof window.aipkit_handleTargetStoreProviderChange === "function") {
        targetStoreProviderSelect.addEventListener(
          "change",
          window.aipkit_handleTargetStoreProviderChange
        );
        // --- FIX: Initial call to populate the dropdowns based on the default value ---
        window.aipkit_handleTargetStoreProviderChange({
          target: targetStoreProviderSelect,
        });
        targetStoreProviderSelect.dataset.listenerAttached = "true";
      } else {
        console.error(
          "Content Indexing UI Init: aipkit_handleTargetStoreProviderChange function not found."
        );
      }
    }

    refreshAutogptOpenaiStoreSelects();
  }

  // Expose the main initializer function globally
  window.aipkit_initContentIndexingTaskFormUI =
    aipkit_initContentIndexingTaskFormUI;
})();
