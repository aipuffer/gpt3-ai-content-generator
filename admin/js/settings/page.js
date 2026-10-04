/**
 * AIPKit Settings - Initialization
 * Orchestrates the initialization of various UI components and functionalities
 * within the AI Settings module.
 */
(function () {
  "use strict";

  function aipkit_disableSettingsLabelClickForwarding() {
    const settingsContainer = document.getElementById("aipkit_settings_container");
    if (!settingsContainer || settingsContainer.dataset.aipkitLabelClickGuard === "true") {
      return;
    }

    settingsContainer.addEventListener("click", function (event) {
      const label = event.target.closest(
        ".aipkit_settings_simple_row > .aipkit_form-label"
      );
      if (!label || !settingsContainer.contains(label)) {
        return;
      }

      // Prevent label clicks from focusing fields or activating button-linked labels.
      event.preventDefault();
    });

    settingsContainer.dataset.aipkitLabelClickGuard = "true";
  }

  /**
   * Main initializer function for the AI Settings module.
   * Called by the module loader (`shared/module-loader.js`).
   */
  function aipkit_initSettings() {
    aipkit_disableSettingsLabelClickForwarding();
    window.aipkit_initCloudConnection?.();

    if (typeof window.aipkit_attachRangeValueHandlers === "function") {
      window.aipkit_attachRangeValueHandlers("#aipkit_settings_container");
    } else {
      console.error(
        "Settings Init: aipkit_attachRangeValueHandlers not found."
      );
    }
    if (typeof window.aipkit_initApiKeyToggles === "function") {
      window.aipkit_initApiKeyToggles("#aipkit_settings_container");
    } else {
      console.error("Settings Init: aipkit_initApiKeyToggles not found.");
    }
    if (typeof window.aipkit_initRestoreDefaultIcons === "function") {
      window.aipkit_initRestoreDefaultIcons("#aipkit_settings_container");
    } else {
      console.error("Settings Init: aipkit_initRestoreDefaultIcons not found.");
    }

    if (typeof window.aipkit_initAiProviderCards === "function") {
      window.aipkit_initAiProviderCards();
    } else {
      console.error(
        "Settings Init: aipkit_initAiProviderCards not found."
      );
    }

    if (typeof window.aipkit_initIntegrationCards === "function") {
      window.aipkit_initIntegrationCards();
    } else {
      console.error(
        "Settings Init: aipkit_initIntegrationCards not found."
      );
    }

    if (typeof window.aipkit_initEventWebhookSettingsUI === "function") {
      window.aipkit_initEventWebhookSettingsUI();
    } else {
      console.error("Settings Init: aipkit_initEventWebhookSettingsUI not found.");
    }

    if (typeof window.aipkit_initDeveloperSettings === "function") {
      window.aipkit_initDeveloperSettings();
    } else {
      console.error("Settings Init: aipkit_initDeveloperSettings not found.");
    }

    if (typeof window.aipkit_initAppConnectionsUI === "function") {
      window.aipkit_initAppConnectionsUI();
    }

    if (typeof window.aipkit_initRecipesUI === "function") {
      window.aipkit_initRecipesUI();
    }

    if (typeof window.aipkit_initAppDeliveryIssuesUI === "function") {
      window.aipkit_initAppDeliveryIssuesUI();
    }

    if (typeof window.aipkit_initEventWebhookDeliveryIssuesUI === "function") {
      window.aipkit_initEventWebhookDeliveryIssuesUI();
    } else {
      console.error("Settings Init: aipkit_initEventWebhookDeliveryIssuesUI not found.");
    }

    if (typeof window.aipkit_initSettingsSelectPickers === "function") {
      window.aipkit_initSettingsSelectPickers();
    } else {
      console.error(
        "Settings Init: aipkit_initSettingsSelectPickers not found."
      );
    }

    if (typeof window.aipkit_initSettingsPageNav === "function") {
      window.aipkit_initSettingsPageNav();
    } else {
      console.error("Settings Init: aipkit_initSettingsPageNav not found.");
    }

    if (typeof window.aipkit_initSettingsOthersActions === "function") {
      window.aipkit_initSettingsOthersActions();
    } else {
      console.error("Settings Init: aipkit_initSettingsOthersActions not found.");
    }

    if (typeof window.aipkit_initWpAiClientControl === "function") {
      window.aipkit_initWpAiClientControl();
    } else {
      console.error("Settings Init: aipkit_initWpAiClientControl not found.");
    }

    if (typeof window.aipkit_initSettingsModulesUI === "function") {
      window.aipkit_initSettingsModulesUI();
    } else {
      console.error("Settings Init: aipkit_initSettingsModulesUI not found.");
    }

    if (typeof window.aipkit_initSecuritySettingsUI === "function") {
      window.aipkit_initSecuritySettingsUI();
    } else {
      console.error("Settings Init: aipkit_initSecuritySettingsUI not found.");
    }

    const accordionHeaders = document.querySelectorAll(
      "#aipkit_settings_container .aipkit_accordion-header"
    );
    accordionHeaders.forEach((header) => {
      if (header.dataset.accordionListenerAttached === "true") return;
      header.addEventListener("click", function () {
        const content = this.nextElementSibling;
        if (content) {
          this.classList.toggle("aipkit_active");
          content.classList.toggle("aipkit_active");
        }
      });
      header.dataset.accordionListenerAttached = "true";
    });

    if (typeof window.aipkit_initSyncButtons === "function") {
      window.aipkit_initSyncButtons();
    } else {
      console.error("Settings Init: aipkit_initSyncButtons not found.");
    }

    if (typeof window.aipkit_attachAutoSaveListeners === "function") {
      window.aipkit_attachAutoSaveListeners();
    } else {
      console.error("Settings Init: aipkit_attachAutoSaveListeners not found.");
    }

  }

  window.aipkit_initSettings = aipkit_initSettings;
})();
