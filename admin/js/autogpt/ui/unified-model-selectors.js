/**
 * Connects Automated Tasks combined model selects to the shared model picker.
 */
(function () {
  "use strict";

  function bindSelector(selector) {
    const sourceId = selector.dataset.aipkitUnifiedModelSourceId || "";
    const source = sourceId ? document.getElementById(sourceId) : null;
    if (
      !source ||
      typeof window.aipkit_createUnifiedModelSelector !== "function" ||
      typeof window.aipkit_createUnifiedModelSelectAdapter !== "function"
    ) {
      return;
    }

    const adapter = window.aipkit_createUnifiedModelSelectAdapter(source);
    adapter.onSyncComplete = () => source._aipkitRefreshModelOptions?.();
    const controller = window.aipkit_createUnifiedModelSelector(
      selector,
      adapter
    );
    if (!controller) {
      return;
    }

    source._aipkitUnifiedModelSync = () => controller.sync();
    if (source.dataset.aipkitUnifiedModelEventsBound !== "1") {
      source.addEventListener("change", source._aipkitUnifiedModelSync);
      const observer = new MutationObserver(() => controller.sync());
      observer.observe(source, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: [
          "data-provider",
          "data-model",
          "data-provider-label",
          "data-group-label",
          "data-recommended",
          "data-family-key",
          "data-family-label",
          "data-family-order",
          "data-family-collapsed",
          "data-setup-required",
          "disabled",
        ],
      });
      source._aipkitUnifiedModelObserver = observer;
      source.dataset.aipkitUnifiedModelEventsBound = "1";
    }
    controller.sync();
  }

  function initAutogptUnifiedModelSelectors(root = document) {
    const scope = root?.querySelectorAll ? root : document;
    scope
      .querySelectorAll(
        "[data-aipkit-unified-model-selector][data-aipkit-unified-model-source-id]"
      )
      .forEach((selector) => bindSelector(selector));
  }

  window.aipkit_initAutogptUnifiedModelSelectors =
    initAutogptUnifiedModelSelectors;
})();
