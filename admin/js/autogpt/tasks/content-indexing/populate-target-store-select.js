/**
 * AIPKit Automated Tasks - Populate Target Store Select
 * Populates the target store/index select based on the selected provider.
 */
(function () {
  "use strict";

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

  function aipkit_populateTargetStoreSelectForContentIndexing(provider) {
    const config = window.aipkit_automated_tasks_config || {};
    const texts = config.text || {};
    const escaper =
      window.aipkit_escapeHtml ||
      function (str) {
        return str;
      };

    const select = document.getElementById(
      "aipkit_task_content_indexing_target_store_id"
    );
    if (!select) return Promise.resolve();

    const previousProvider =
      select.dataset.aipkitTargetStoreProvider || "";
    const previousValue =
      previousProvider === provider ? String(select.value || "") : "";
    const formState =
      window.aipkit_automated_tasks_form_state ||
      (window.aipkit_automated_tasks_form_state = {});
    const pendingSelection = formState.pendingTargetStoreSelection;
    const pendingValue =
      pendingSelection && pendingSelection.provider === provider
        ? String(pendingSelection.value || "")
        : "";
    const preferredValue = pendingValue || previousValue;

    select.dataset.aipkitTargetStoreProvider = provider;

    select.innerHTML = `<option value="">${escaper(
      texts.loading_stores || "Loading..."
    )}</option>`;
    select.disabled = true;

    let stores = [];
    let defaultOptionText =
      texts.select_target_store || "-- Select Target Store --";

    if (provider === "local") {
      stores = Array.isArray(config.local_stores) ? config.local_stores : [];
      defaultOptionText = texts.select_target_knowledge_base || "-- Select knowledge base --";
    } else if (provider === "openai") {
      const sharedOpenaiStores = getSharedOpenaiStores();
      if (sharedOpenaiStores !== null) {
        stores = sharedOpenaiStores;
        config.openai_vector_stores = sharedOpenaiStores;
        window.aipkit_automated_tasks_config = config;
      } else if (config.openai_vector_stores) {
        stores = config.openai_vector_stores;
      }
    } else if (provider === "google" && config.google_file_search_stores) {
      stores = config.google_file_search_stores;
    } else if (provider === "pinecone" && config.pinecone_indexes) {
      stores = config.pinecone_indexes;
      defaultOptionText =
        texts.select_target_index || "-- Select Target Index --";
    } else if (provider === "qdrant" && config.qdrant_collections) {
      stores = config.qdrant_collections;
      defaultOptionText =
        texts.select_target_collection || "-- Select Target Collection --";
    } else if (provider === "chroma" && config.chroma_collections) {
      stores = config.chroma_collections;
      defaultOptionText =
        texts.select_target_collection || "-- Select Target Collection --";
    }

    select.innerHTML = `<option value="">${escaper(
      defaultOptionText
    )}</option>`;
    if (stores && Array.isArray(stores) && stores.length > 0) {
      stores.forEach((store) => {
        const storeName =
          store.display_name || store.name || store.collection_name || store.id;
        const storeId =
          provider === "openai" || provider === "local"
            ? store.id || storeName
            : provider === "google"
              ? store.resource_name || store.id || ""
              : storeName || store.id;
        if (!storeId) {
          return;
        }
        let optionText = escaper(storeName);
        if (provider === "openai" && store.file_counts?.total !== undefined) {
          optionText += ` (Files: ${store.file_counts.total})`;
        } else if (provider === "pinecone" && store.dimension !== undefined) {
          optionText += ` (Dim: ${store.dimension})`;
        } else if (provider === "chroma" && store.dimension !== undefined) {
          optionText += ` (Dim: ${store.dimension})`;
        } else if (provider === "local" && store.chunk_count !== undefined) {
          const chunks = Number(store.chunk_count);
          optionText += ` (${chunks.toLocaleString()} ${chunks === 1 ? "chunk" : "chunks"})`;
        }
        select.appendChild(new Option(optionText, storeId));
      });
      select.disabled = false;

      if (
        preferredValue &&
        Array.from(select.options).some(
          (option) => option.value === preferredValue
        )
      ) {
        select.value = preferredValue;
        if (pendingValue) {
          formState.pendingTargetStoreSelection = null;
        }
      }
    } else {
      select.appendChild(
        new Option(
          escaper(
            texts.no_targets_found_configure ||
              `No ${provider} targets found. Configure in AI Training.`
          ),
          ""
        )
      );
      select.disabled = true;
    }
    return Promise.resolve();
  }

  // Expose the function globally.
  window.aipkit_populateTargetStoreSelectForContentIndexing =
    aipkit_populateTargetStoreSelectForContentIndexing;
})();
