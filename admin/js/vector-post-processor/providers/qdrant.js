/**
 * AIPKit - Vector Post Processor - Qdrant Specific Logic
 *
 * Handles AJAX calls for Qdrant: fetching collections and indexing content.
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

  /**
   * Populates the Qdrant collections dropdown in the modal from pre-loaded data.
   */
  function aipkit_vpp_populateQdrantCollectionsSelect() {
    const selectElement = document.getElementById(
      "aipkit_vpp_qdrant_target_collection_select"
    );
    const statusDiv = document.getElementById(
      "aipkit_vpp_qdrant_collection_status"
    );
    const config = window.aipkit_vpp_config || {};
    const texts = config.text || {};
    const collections = config.qdrant_collections || [];

    if (!selectElement) {
      console.error("VPP Qdrant Provider: Collection select not found.");
      return;
    }

    selectElement.innerHTML = "";
    if (statusDiv) {
      statusDiv.textContent = "";
    }

    if (collections.length > 0) {
      selectElement.appendChild(
        new Option(
          escaper(texts.select_qdrant_collection || "Select a collection"),
          ""
        )
      );
      collections.forEach((collection) => {
        const collectionName = collection.name || collection.id;
        if (collectionName) {
          selectElement.appendChild(
            new Option(escaper(collectionName), collectionName)
          );
        }
      });
    } else {
      selectElement.appendChild(
        new Option(
          escaper(
            texts.no_qdrant_collections_found ||
              "No Qdrant collections found. Create one in AI Training."
          ),
          ""
        )
      );
    }
    selectElement.disabled = collections.length === 0;
  }

  /**
   * Starts the indexing process for selected posts to Qdrant.
   * @param {string[]} postIds Array of post IDs.
   * @param {string} targetCollectionName Name of the target Qdrant collection.
   * @param {string} embeddingProvider The provider for generating embeddings.
   * @param {string} embeddingModel The model for generating embeddings.
   * @param {string} postType Current post type.
   */
  async function aipkit_vpp_startIndexingQdrant(
    postIds,
    targetCollectionName,
    embeddingProvider,
    embeddingModel,
    postType
  ) {
    return startVectorPostIndexing({
      postIds,
      postType,
      provider: "qdrant",
      providerLabel: "Qdrant",
      failureMessage: "Failed to index content to Qdrant.",
      buildRequestData: () => ({
        target_collection_name: targetCollectionName,
        embedding_provider: embeddingProvider,
        embedding_model: embeddingModel,
      }),
    });
  }

  // Expose functions globally
  window.aipkit_vpp_populateQdrantCollectionsSelect =
    aipkit_vpp_populateQdrantCollectionsSelect;
  window.aipkit_vpp_startIndexingQdrant = aipkit_vpp_startIndexingQdrant;

  window.addEventListener("aipkit:vector-store-list-updated", (event) => {
    const provider = String(event?.detail?.provider || "").toLowerCase();
    if (provider !== "qdrant") {
      return;
    }
    const config = window.aipkit_vpp_config || {};
    config.qdrant_collections = Array.isArray(event.detail?.stores)
      ? event.detail.stores
      : [];
    window.aipkit_vpp_config = config;
    if (document.getElementById("aipkit_vpp_qdrant_target_collection_select")) {
      aipkit_vpp_populateQdrantCollectionsSelect();
    }
  });
})();
