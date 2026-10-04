/**
 * AIPKit - Vector Post Processor - site knowledge bases
 *
 * Lists site knowledge bases and indexes posts into one. The knowledge base keeps
 * the embedding model selected for this indexing request.
 */
import { startVectorPostIndexing } from "./index-posts-runner.js";

(function () {
  "use strict";

  const escaper =
    window.aipkit_escapeHtml ||
    function (str) {
      return str;
    };

  function aipkit_vpp_populateLocalStoresSelect() {
    const selectElement = document.getElementById("aipkit_vpp_local_store_select");
    const config = window.aipkit_vpp_config || {};
    const texts = config.text || {};
    const stores = config.local_stores || [];

    if (!selectElement) {
      return;
    }

    selectElement.innerHTML = "";

    if (stores.length > 0) {
      selectElement.appendChild(
        new Option(escaper(texts.select_local_store || "Select a knowledge base"), "")
      );
      stores.forEach((store) => {
        if (store.id) {
          selectElement.appendChild(new Option(escaper(store.name || store.id), store.id));
        }
      });
    } else {
      selectElement.appendChild(
        new Option(
          escaper(
            texts.no_local_stores_found ||
              "No site knowledge bases yet. Create one in Knowledge Base."
          ),
          ""
        )
      );
    }
    selectElement.disabled = stores.length === 0;
  }

  async function aipkit_vpp_startIndexingLocal(postIds, storeId, postType, embedding) {
    return startVectorPostIndexing({
      postIds,
      postType,
      provider: "local",
      providerLabel: "Local",
      failureMessage: "Failed to add content to the site knowledge base.",
      buildRequestData: () => ({
        target_store_id: storeId,
        embedding_provider: embedding.embedding_provider,
        embedding_model: embedding.embedding_model,
      }),
    });
  }

  window.aipkit_vpp_populateLocalStoresSelect = aipkit_vpp_populateLocalStoresSelect;
  window.aipkit_vpp_startIndexingLocal = aipkit_vpp_startIndexingLocal;

  window.addEventListener("aipkit:vector-store-list-updated", (event) => {
    const provider = String(event?.detail?.provider || "").toLowerCase();
    if (provider !== "local") {
      return;
    }
    const config = window.aipkit_vpp_config || {};
    config.local_stores = Array.isArray(event.detail?.stores) ? event.detail.stores : [];
    window.aipkit_vpp_config = config;
    if (document.getElementById("aipkit_vpp_local_store_select")) {
      aipkit_vpp_populateLocalStoresSelect();
    }
  });
})();
