import { updateVectorStoreSelects } from "./sync-vector-store-selects.js";

(function () {
  "use strict";

  function aipkit_updateGoogleFileSearchStores(
    stores,
    selectElementId = "aipkit_google_file_search_default_store"
  ) {
    updateVectorStoreSelects(stores, {
      provider: "google",
      targetSelectId: selectElementId,
      defaultSelectId: "aipkit_google_file_search_default_store",
      selectors: 'select[name="google_file_search_store_names[]"]',
      defaultLabel: "-- Select Google Store --",
      emptyLabel: "(No Google stores found - Refresh again)",
      chatConfigKey: "googleFileSearchStores",
      vppConfigKey: "google_file_search_stores",
      automatedTasksConfigKey: "google_file_search_stores",
      postEnhancerConfigKey: "google_file_search_stores",
      refreshCallbackName: "aipkit_populateGoogleFileSearchStoresMultiSelect",
      refreshAreaSelectors: [
        ".aipkit_settings_panel_body",
        ".aipkit_popover_options_list",
        ".aipkit_popover_option_main",
        ".aipkit_chatbot_builder",
      ],
      emptyRefreshAreaSelectors: [
        ".aipkit_settings_panel_body",
        ".aipkit_popover_options_list",
        ".aipkit_popover_option_main",
        ".aipkit_chatbot_builder",
      ],
      sortInPlace: false,
      getSortLabel: (store) =>
        store?.display_name || store?.name || store?.resource_name || store?.id || "",
      getOptionLabel: (store) =>
        store?.display_name || store?.name || store?.resource_name || store?.id || "",
      getOptionValue: (store) => store?.resource_name || store?.id || "",
    });
  }

  window.aipkit_updateGoogleFileSearchStores =
    aipkit_updateGoogleFileSearchStores;
})();
