/**
 * AIPKit Settings - Autosave Data Collector
 * Gathers the current relevant form data from the settings container.
 */
(function () {
  "use strict";

  function getSettingsContainer() {
    return document.getElementById("aipkit_settings_container");
  }

  function getActiveSettingsPage(settingsContainer) {
    if (!settingsContainer) {
      return null;
    }

    return (
      settingsContainer.querySelector("[data-aipkit-settings-page]:not([hidden])") ||
      null
    );
  }

  function getNamedInputs(root) {
    if (!root) {
      return [];
    }

    return root.querySelectorAll(
      "input[name]:not(:disabled), select[name]:not(:disabled), textarea[name]:not(:disabled)"
    );
  }

  function shouldIncludeInput(input, context) {
    const { root, scope } = context;
    if (!input?.name || !root || !root.contains(input)) {
      return false;
    }

    if (input.closest("[data-aipkit-settings-autosave-exclude='true']")) {
      return false;
    }

    if (scope === "all") {
      return true;
    }

    return true;
  }

  function assignInputValue(data, input) {
    if (input.type === "checkbox") {
      data[input.name] = input.checked ? "1" : "0";
      return;
    }

    if (input.type === "radio") {
      if (input.checked) {
        data[input.name] = input.value;
      }
      return;
    }

    data[input.name] = input.value;
  }

  /**
   * Gathers settings form data.
   * Defaults to the active settings page.
   *
   * @param {Object} options
   * @param {"active"|"all"} [options.scope]
   * @returns {Object} The current form data.
   */
  function aipkit_getCurrentFormData(options = {}) {
    const data = {};
    const settingsContainer = getSettingsContainer();
    if (!settingsContainer) {
      // Not on the main AI Settings screen; silently skip collecting data
      // (previously logged a warning which was noisy on other admin pages)
      return data;
    }

    const normalizedOptions =
      options && typeof options === "object" ? options : {};
    const scope = normalizedOptions.scope === "all" ? "all" : "active";
    const root =
      scope === "all"
        ? settingsContainer
        : getActiveSettingsPage(settingsContainer) || settingsContainer;
    const context = {
      root,
      scope,
    };

    getNamedInputs(root).forEach((input) => {
      if (shouldIncludeInput(input, context)) {
        assignInputValue(data, input);
      }
    });

    return data;
  }

  window.aipkit_getCurrentFormData = aipkit_getCurrentFormData;
})();
