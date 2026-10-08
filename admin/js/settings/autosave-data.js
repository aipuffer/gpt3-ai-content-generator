/**
 * AIPKit Settings - Autosave Data Collector
 * Gathers the current relevant form data from the settings container.
 */
(function () {
  "use strict";

  // Each scope saves its own fields, as the old tabs did; the one last used is the one saved.
  const SCOPE_SELECTOR = ".aipkit_settings_scope[data-aipkit-settings-page]";
  let activeScope = null;

  function getSettingsContainer() {
    return document.getElementById("aipkit_settings_container");
  }

  function getActiveSettingsPage(settingsContainer) {
    if (!settingsContainer) {
      return null;
    }
    if (activeScope && activeScope.isConnected && settingsContainer.contains(activeScope)) {
      return activeScope;
    }
    return settingsContainer.querySelector(SCOPE_SELECTOR);
  }

  // Not pointerdown: a blur save from the previous field must still see its own scope.
  // Moving to another scope switches the saved-state reference, as switching tabs did.
  function trackScope(event) {
    const scope = event.target && event.target.closest ? event.target.closest(SCOPE_SELECTOR) : null;
    if (!scope || scope === activeScope) {
      return;
    }
    activeScope = scope;
    if (typeof window.aipkit_updateLastSavedData === "function") {
      window.aipkit_updateLastSavedData(true);
    }
  }
  ["focusin", "click", "keydown", "paste", "input", "change"].forEach(function (name) {
    document.addEventListener(name, trackScope, true);
  });

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

    // Independent forms must never override the AJAX routing or authentication fields.
    if (["action", "_wpnonce", "_ajax_nonce", "_wp_http_referer"].includes(input.name)) {
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
   * Defaults to the settings scope last used.
   *
   * @param {Object} options
   * @param {"active"|"all"} [options.scope]
   * @param {Element} [options.root] One settings scope to read instead of the active one.
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
    const requestedRoot =
      normalizedOptions.root && settingsContainer.contains(normalizedOptions.root)
        ? normalizedOptions.root
        : null;
    const root =
      scope === "all"
        ? settingsContainer
        : requestedRoot || getActiveSettingsPage(settingsContainer) || settingsContainer;
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
  window.aipkit_getSettingsDataScope = function () {
    return getActiveSettingsPage(getSettingsContainer());
  };
  window.aipkit_getSettingsDataScopes = function () {
    const settingsContainer = getSettingsContainer();
    return settingsContainer ? Array.from(settingsContainer.querySelectorAll(SCOPE_SELECTOR)) : [];
  };
})();
