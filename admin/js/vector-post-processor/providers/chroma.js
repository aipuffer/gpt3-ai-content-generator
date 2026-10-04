/**
 * AIPKit - Vector Post Processor - Chroma Specific Logic
 *
 * Handles Chroma collection selection and indexing content.
 */
import { startVectorPostIndexing } from "./index-posts-runner.js";

(function () {
  "use strict";

  const escaper =
    window.aipkit_escapeHtml ||
    function (str) {
      return str;
    };

  function aipkit_vpp_populateChromaCollectionsSelect() {
    const selectElement = document.getElementById(
      "aipkit_vpp_chroma_target_collection_select"
    );
    const config = window.aipkit_vpp_config || {};
    const texts = config.text || {};
    const collections = config.chroma_collections || [];

    if (!selectElement) {
      console.error("VPP Chroma Provider: Collection select not found.");
      return;
    }

    selectElement.innerHTML = "";

    if (collections.length > 0) {
      selectElement.appendChild(
        new Option(
          escaper(texts.select_chroma_collection || "Select a collection"),
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
            texts.no_chroma_collections_found ||
              "No Chroma collections found. Create one in Knowledge Base."
          ),
          ""
        )
      );
    }
    selectElement.disabled = collections.length === 0;
  }

  async function aipkit_vpp_startIndexingChroma(
    postIds,
    targetCollectionName,
    embeddingProvider,
    embeddingModel,
    postType
  ) {
    return startVectorPostIndexing({
      postIds,
      postType,
      provider: "chroma",
      providerLabel: "Chroma",
      failureMessage: "Failed to index content to Chroma.",
      buildRequestData: () => ({
        target_collection_name: targetCollectionName,
        embedding_provider: embeddingProvider,
        embedding_model: embeddingModel,
      }),
    });
  }

  window.aipkit_vpp_populateChromaCollectionsSelect =
    aipkit_vpp_populateChromaCollectionsSelect;
  window.aipkit_vpp_startIndexingChroma = aipkit_vpp_startIndexingChroma;

  window.addEventListener("aipkit:vector-store-list-updated", (event) => {
    const provider = String(event?.detail?.provider || "").toLowerCase();
    if (provider !== "chroma") {
      return;
    }
    const config = window.aipkit_vpp_config || {};
    config.chroma_collections = Array.isArray(event.detail?.stores)
      ? event.detail.stores
      : [];
    window.aipkit_vpp_config = config;
    if (document.getElementById("aipkit_vpp_chroma_target_collection_select")) {
      aipkit_vpp_populateChromaCollectionsSelect();
    }
  });
})();
