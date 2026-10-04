/**
 * AIPKit - Vector Post Processor - Pinecone Specific Logic
 *
 * Handles AJAX calls for Pinecone: fetching indexes and indexing content.
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
   * Populates the Pinecone indexes dropdown in the modal from pre-loaded data.
   */
  function aipkit_vpp_populatePineconeIndexesSelect() {
    const selectElement = document.getElementById(
      "aipkit_vpp_pinecone_target_index_select"
    );
    const statusDiv = document.getElementById(
      "aipkit_vpp_pinecone_index_status"
    );
    const config = window.aipkit_vpp_config || {};
    const texts = config.text || {};
    const indexes = config.pinecone_indexes || [];

    if (!selectElement) {
      console.error("VPP Pinecone Provider: Index select not found.");
      return;
    }

    selectElement.innerHTML = "";
    if (statusDiv) {
      statusDiv.textContent = "";
    }

    if (indexes.length > 0) {
      selectElement.appendChild(
        new Option(
          escaper(texts.select_pinecone_index || "Select an index"),
          ""
        )
      );
      indexes.forEach((index) => {
        const indexName = index.name || index.id;
        if (indexName) {
          const optionText = `${escaper(indexName)} (${
            index.dimension || "?"
          })`;
          selectElement.appendChild(new Option(optionText, indexName));
        }
      });
    } else {
      selectElement.appendChild(
        new Option(
          escaper(
            texts.no_pinecone_indexes_found ||
              "No Pinecone indexes found. Create one in AI Training or via Pinecone console."
          ),
          ""
        )
      );
    }
    selectElement.disabled = indexes.length === 0;
  }

  /**
   * Starts the indexing process for selected posts to Pinecone.
   * @param {string[]} postIds Array of post IDs.
   * @param {string} targetIndexId ID of the target Pinecone index.
   * @param {string} embeddingProvider The provider for generating embeddings.
   * @param {string} embeddingModel The model for generating embeddings.
   * @param {string} postType Current post type.
   */
  async function aipkit_vpp_startIndexingPinecone(
    postIds,
    targetIndexId,
    embeddingProvider,
    embeddingModel,
    postType
  ) {
    return startVectorPostIndexing({
      postIds,
      postType,
      provider: "pinecone",
      providerLabel: "Pinecone",
      failureMessage: "Failed to index content to Pinecone.",
      buildRequestData: () => ({
        target_index_id: targetIndexId,
        embedding_provider: embeddingProvider,
        embedding_model: embeddingModel,
      }),
    });
  }

  // Expose functions globally
  window.aipkit_vpp_populatePineconeIndexesSelect =
    aipkit_vpp_populatePineconeIndexesSelect;
  window.aipkit_vpp_startIndexingPinecone = aipkit_vpp_startIndexingPinecone;

  window.addEventListener("aipkit:vector-store-list-updated", (event) => {
    const provider = String(event?.detail?.provider || "").toLowerCase();
    if (provider !== "pinecone") {
      return;
    }
    const config = window.aipkit_vpp_config || {};
    config.pinecone_indexes = Array.isArray(event.detail?.stores)
      ? event.detail.stores
      : [];
    window.aipkit_vpp_config = config;
    if (document.getElementById("aipkit_vpp_pinecone_target_index_select")) {
      aipkit_vpp_populatePineconeIndexesSelect();
    }
  });
})();
