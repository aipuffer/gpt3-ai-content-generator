/**
 * AIPKit - Vector Post Processor - Google File Search logic.
 *
 * Google ingestion is asynchronous. Successful requests are shown as
 * submitted; the durable Sources record tracks the final indexing result.
 */
import { startVectorPostIndexing } from "./index-posts-runner.js";

(function () {
  "use strict";

  const escaper = window.aipkit_escapeHtml || ((value) => value);

  function applyGoogleStores(stores, preferredStoreName = "") {
    const config = window.aipkit_vpp_config || {};
    config.google_file_search_stores = Array.isArray(stores) ? stores : [];
    window.aipkit_vpp_config = config;
    populateGoogleStores();

    if (!preferredStoreName) return;
    const select = document.getElementById(
      "aipkit_vpp_google_file_search_store_name"
    );
    if (
      select &&
      Array.from(select.options).some(
        (option) => option.value === preferredStoreName
      )
    ) {
      select.value = preferredStoreName;
      select.dispatchEvent(new Event("change", { bubbles: true }));
    }
  }

  function populateGoogleStores() {
    const select = document.getElementById(
      "aipkit_vpp_google_file_search_store_name"
    );
    if (!select) return;

    const config = window.aipkit_vpp_config || {};
    const texts = config.text || {};
    const stores = Array.isArray(config.google_file_search_stores)
      ? config.google_file_search_stores
      : [];

    select.innerHTML = "";
    if (stores.length > 0) {
      select.add(new Option(escaper(texts.select_store || "Select a store"), ""));
      stores.forEach((store) => {
        const resourceName =
          store?.resource_name || store?.id || store?.name || "";
        const displayName = store?.display_name || store?.name || resourceName;
        if (resourceName) {
          select.add(new Option(escaper(displayName), resourceName));
        }
      });
    } else {
      select.add(
        new Option(
          escaper(
            texts.no_google_file_search_stores_found ||
              "No Google stores found. Create one in Knowledge Base."
          ),
          ""
        )
      );
    }
    select.disabled = stores.length === 0;
  }

  async function refreshGoogleStores(preferredStoreName = "") {
    const config = window.aipkit_vpp_config || {};
    if (
      !config.nonce_google_file_search ||
      typeof window.aipkit_apiRequest !== "function"
    ) {
      populateGoogleStores();
      return false;
    }

    try {
      const response = await window.aipkit_apiRequest(
        "aipkit_list_google_file_search_stores",
        { _ajax_nonce: config.nonce_google_file_search }
      );
      if (!response || !Array.isArray(response.stores)) return false;
      applyGoogleStores(response.stores, preferredStoreName);
      return true;
    } catch (error) {
      console.warn(
        "VPP Google Provider: Failed to refresh File Search stores.",
        error
      );
      populateGoogleStores();
      return false;
    }
  }

  function startIndexingGoogle(postIds, storeName, postType) {
    const config = window.aipkit_vpp_config || {};
    return startVectorPostIndexing({
      postIds,
      postType,
      asynchronous: true,
      provider: "google",
      providerLabel: "Google",
      failureMessage: "Failed to submit content to Google File Search.",
      actionName: "aipkit_index_wp_content_google_file_search",
      nonce: config.nonce_google_file_search || "",
      buildRequestData: () => ({ target_store_id: storeName }),
    });
  }

  window.aipkit_vpp_populateGoogleStoresSelect = populateGoogleStores;
  window.aipkit_vpp_refreshGoogleStoresFromApi = refreshGoogleStores;
  window.aipkit_vpp_startIndexingGoogle = startIndexingGoogle;

  window.addEventListener("aipkit:vector-store-list-updated", (event) => {
    const provider = String(event?.detail?.provider || "").toLowerCase();
    if (provider !== "google" && provider !== "google_file_search") return;
    applyGoogleStores(event.detail?.stores || []);
  });
})();
