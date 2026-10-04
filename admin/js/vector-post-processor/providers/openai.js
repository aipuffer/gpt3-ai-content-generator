/**
 * AIPKit - Vector Post Processor - OpenAI Specific Logic
 *
 * Handles AJAX calls for OpenAI: fetching vector stores and indexing content.
 * REVISED: Implemented iterative, one-by-one post indexing for robustness.
 */
import { startVectorPostIndexing } from "./index-posts-runner.js";

(function () {
  "use strict";

  const escaper =
    window.aipkit_escapeHtml ||
    function (str) {
      return str;
    };

  function formatOpenAIStoreOptionLabel(store) {
    let fileCountText = "(Files: N/A)";
    if (store.file_counts && typeof store.file_counts.total === "number") {
      fileCountText = `(${store.file_counts.total} ${
        store.file_counts.total === 1 ? "File" : "Files"
      })`;
    } else if (store.file_counts) {
      fileCountText = `(Files: ?)`;
    }
    return `${escaper(store.name || store.id)} ${fileCountText}`;
  }

  function aipkit_vpp_applyOpenAIStoreList(stores, preferredStoreId = "") {
    const config = window.aipkit_vpp_config || {};
    config.openai_vector_stores = Array.isArray(stores) ? stores : [];
    window.aipkit_vpp_config = config;

    aipkit_vpp_populateOpenAIStoresSelect();

    if (!preferredStoreId) {
      return;
    }

    const selectElement = document.getElementById(
      "aipkit_vpp_openai_vector_store_id"
    );
    if (!selectElement) {
      return;
    }

    const hasPreferredOption = Array.from(selectElement.options).some(
      (option) => option.value === preferredStoreId
    );
    if (!hasPreferredOption) {
      return;
    }

    selectElement.value = preferredStoreId;
    selectElement.dispatchEvent(new Event("change", { bubbles: true }));
  }

  async function aipkit_vpp_refreshOpenAIStoresFromApi(preferredStoreId = "") {
    const config = window.aipkit_vpp_config || {};
    const nonce = config.nonce_openai_store_list || "";
    if (!nonce || typeof window.aipkit_apiRequest !== "function") {
      return false;
    }

    try {
      const response = await window.aipkit_apiRequest(
        "aipkit_list_vector_stores_openai",
        {
          _ajax_nonce: nonce,
          limit: 100,
          order: "desc",
        }
      );
      if (!response || !Array.isArray(response.stores)) {
        return false;
      }

      aipkit_vpp_applyOpenAIStoreList(response.stores, preferredStoreId);
      return true;
    } catch (error) {
      console.warn(
        "VPP OpenAI Provider: Failed to refresh vector stores after indexing.",
        error
      );
      return false;
    }
  }

  function aipkit_vpp_incrementOpenAIStoreFileCount(targetStoreId, incrementBy) {
    const increment = Number(incrementBy);
    if (!targetStoreId || !Number.isFinite(increment) || increment <= 0) {
      return false;
    }

    const config = window.aipkit_vpp_config || {};
    const stores = Array.isArray(config.openai_vector_stores)
      ? config.openai_vector_stores
      : [];

    let matched = false;
    const updatedStores = stores.map((store) => {
      if (!store || store.id !== targetStoreId) {
        return store;
      }

      matched = true;
      const updatedStore = { ...store };
      const fileCounts =
        store.file_counts && typeof store.file_counts === "object"
          ? { ...store.file_counts }
          : {};
      const currentTotal = Number(fileCounts.total);

      fileCounts.total =
        Number.isFinite(currentTotal) && currentTotal >= 0
          ? currentTotal + increment
          : increment;
      updatedStore.file_counts = fileCounts;

      return updatedStore;
    });

    if (!matched) {
      return false;
    }

    aipkit_vpp_applyOpenAIStoreList(updatedStores, targetStoreId);
    return true;
  }

  /**
   * Populates the OpenAI vector stores dropdown in the modal from pre-loaded data.
   */
  function aipkit_vpp_populateOpenAIStoresSelect() {
    const selectElement = document.getElementById(
      "aipkit_vpp_openai_vector_store_id"
    );
    const statusDiv = document.getElementById("aipkit_vpp_openai_store_status");
    const config = window.aipkit_vpp_config || {};
    const texts = config.text || {};
    const stores = config.openai_vector_stores || [];

    if (!selectElement) {
      console.error("VPP OpenAI Provider: Store select not found.");
      return;
    }

    selectElement.innerHTML = "";
    if (statusDiv) {
      statusDiv.textContent = "";
    }

    if (stores.length > 0) {
      selectElement.appendChild(
        new Option(
          escaper(texts.select_store || "Select a store"),
          ""
        )
      );
      stores.forEach((store) => {
        const optionText = formatOpenAIStoreOptionLabel(store);
        const option = new Option(optionText, store.id);
        selectElement.appendChild(option);
      });
    } else {
      selectElement.appendChild(
        new Option(
          escaper(
            texts.no_stores_found ||
              "No stores found. Create one in AI Training > Knowledge Base."
          ),
          ""
        )
      );
    }
    selectElement.disabled = stores.length === 0;
  }

  /**
   * Starts the indexing process for selected posts to OpenAI, one by one.
   * @param {string[]} postIds Array of post IDs.
   * @param {string} targetStoreId ID of existing store.
   * @param {string|null} newStoreName Name for new store (not used in VPP modal flow currently).
   * @param {string} postType Current post type.
   */
  async function aipkit_vpp_startIndexingOpenAI(
    postIds,
    targetStoreId,
    newStoreName,
    postType
  ) {
    return startVectorPostIndexing({
      postIds,
      postType,
      provider: "openai",
      providerLabel: "OpenAI",
      asynchronous: true,
      failureMessage: "Failed to index content to OpenAI.",
      outerErrorLabel: "Indexing setup error",
      buildRequestData: () => ({
        target_store_id: targetStoreId,
        new_store_name_openai: newStoreName || "",
      }),
      onComplete: async ({ successCount }) => {
        if (successCount > 0 && targetStoreId) {
          const refreshed = await aipkit_vpp_refreshOpenAIStoresFromApi(
            targetStoreId
          );
          if (!refreshed) {
            aipkit_vpp_incrementOpenAIStoreFileCount(
              targetStoreId,
              successCount
            );
          }
        }
      },
    });
  }

  // Expose functions globally
  window.aipkit_vpp_populateOpenAIStoresSelect =
    aipkit_vpp_populateOpenAIStoresSelect;
  window.aipkit_vpp_startIndexingOpenAI = aipkit_vpp_startIndexingOpenAI;

  window.addEventListener("aipkit:vector-store-list-updated", (event) => {
    const provider = String(event?.detail?.provider || "").toLowerCase();
    if (provider !== "openai") {
      return;
    }
    const config = window.aipkit_vpp_config || {};
    config.openai_vector_stores = Array.isArray(event.detail?.stores)
      ? event.detail.stores
      : [];
    window.aipkit_vpp_config = config;
    if (document.getElementById("aipkit_vpp_openai_vector_store_id")) {
      aipkit_vpp_populateOpenAIStoresSelect();
    }
  });
})();
