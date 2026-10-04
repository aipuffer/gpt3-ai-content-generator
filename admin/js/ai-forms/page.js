/** AI Forms page startup and container, token and accordion lifecycle helpers. */
import "./events.js";
import "./field-settings.js";
import "./designer.js";
import "./settings.js";
import "./model-config.js";
import "./context-config.js";
import "./templates.js";
import "./editor.js";
import "./editor-dialogs.js";
import "./generator.js";
import "./preview.js";
import "./list.js";
import "./save.js";
import "./data-actions.js";

(function () {
  "use strict";

  let dismissTimer = null;

  function aipkitForms_displayMessage(formsContainerElement, type, message) {
    if (!formsContainerElement) {
      console.error(
        "AI Forms displayMessage: formsContainerElement not provided."
      );
      return;
    }

    if (type !== "error" || !message) {
      return;
    }

    const statusElement = formsContainerElement.querySelector(
      "#aipkit_ai_forms_settings_status"
    );
    if (!statusElement) {
      console.warn(
        "AI Forms displayMessage: Header status element not found."
      );
      return;
    }

    if (dismissTimer) {
      window.clearTimeout(dismissTimer);
    }
    statusElement.textContent = message;
    statusElement.classList.remove(
      "is-success",
      "is-warning",
      "is-loading"
    );
    statusElement.classList.add("is-visible", "is-error");

    dismissTimer = window.setTimeout(() => {
      statusElement.textContent = "";
      statusElement.classList.remove("is-visible", "is-error");
      dismissTimer = null;
    }, 8000);
  }

  window.aipkitForms_displayMessage = aipkitForms_displayMessage;

  /**
   * Finds and validates the main AI Forms container.
   * @returns {HTMLElement|null} The container element or null if not found.
   */
  function aipkitForms_validateContainer() {
    const formsContainer = document.getElementById("aipkit_ai_forms_container");
    if (!formsContainer) {
      console.error(
        "[AI Forms Init] CRITICAL: Main container (#aipkit_ai_forms_container) not found. Initialization aborted."
      );
      return null;
    }
    return formsContainer;
  }

  // Expose globally
  window.aipkitForms_validateContainer = aipkitForms_validateContainer;
})();

(function () {
  "use strict";

  /**
   * Initializes the token management UI on the settings form.
   * @param {HTMLElement} formsContainerElement The main container element.
   */
  function aipkitForms_initializeTokenUI(formsContainerElement) {
    const settingsForm = formsContainerElement.querySelector(
      "#aipkit_ai_forms_settings_form"
    );
    if (
      settingsForm &&
      typeof window.aipkit_initAIFormsTokenManagementUI === "function"
    ) {
      window.aipkit_initAIFormsTokenManagementUI(settingsForm);
    } else {
      console.error(
        "AI Forms Init: Could not initialize token management UI for settings form."
      );
    }
  }

  // Expose globally
  window.aipkitForms_initializeTokenUI = aipkitForms_initializeTokenUI;
})();

(function() {
    'use strict';

    /**
     * Initializes accordion functionality within the AI Forms module.
     * @param {HTMLElement} formsContainerElement The main container for the AI Forms module.
     */
    function aipkitForms_initializeAccordions(formsContainerElement) {
        if (!formsContainerElement) return;

        const listenerAttr = 'data-accordion-listener-attached';
        if (formsContainerElement.getAttribute(listenerAttr) === 'true') return;

        formsContainerElement.addEventListener('click', function(event) {
            const header = event.target.closest('.aipkit_accordion-header');
            if (!header) return;

            // Ensure the click is within the AI Forms container context
            if (!header.closest('.aipkit_ai_forms_container')) {
                return;
            }

            event.preventDefault();

            const group = header.closest('.aipkit_accordion-group');
            const isContextAccordion = header.dataset.aipkitContextAccordion === '1';
            // If part of a group, implement "one open at a time" logic
            if (group) {
                // If the clicked header is not already active, close the others.
                if (!header.classList.contains('aipkit_active')) {
                    const currentlyActiveHeader = group.querySelector('.aipkit_accordion-header.aipkit_active');
                    if (currentlyActiveHeader) {
                        currentlyActiveHeader.classList.remove('aipkit_active');
                        const activeContent = currentlyActiveHeader.nextElementSibling;
                        if (activeContent) activeContent.classList.remove('aipkit_active');
                    }
                }
            }

            // Toggle the clicked accordion
            const content = header.nextElementSibling;
            if (content) {
                header.classList.toggle('aipkit_active');
                content.classList.toggle('aipkit_active');
                if (
                    isContextAccordion &&
                    content.classList.contains('aipkit_active') &&
                    typeof window.aipkitForms_refreshOpenaiStores === 'function'
                ) {
                    window.aipkitForms_refreshOpenaiStores(content);
                }
            }
        });

        formsContainerElement.setAttribute(listenerAttr, 'true');
    }

    // Expose globally
    window.aipkitForms_initializeAccordions = aipkitForms_initializeAccordions;

})();

(function () {
  "use strict";

  function aipkit_initAiForms() {
    const formsContainer = window.aipkitForms_validateContainer();
    if (!formsContainer) {
      return; // Stop if the main container isn't found
    }

    const isInitialized = formsContainer.dataset.aipkitInitialized === "true";

    if (typeof window.aipkit_initModuleSettingsTabs === "function") {
      window.aipkit_initModuleSettingsTabs(formsContainer);
    }
    if (typeof window.aipkit_initApiKeyToggles === "function") {
      window.aipkit_initApiKeyToggles("#aipkit_ai_forms_container");
    }
    if (typeof window.aipkitForms_initEditorPersistence === "function") {
      window.aipkitForms_initEditorPersistence(formsContainer);
    }
    if (typeof window.aipkitForms_initPromptModal === "function") {
      window.aipkitForms_initPromptModal(formsContainer);
    }
    if (typeof window.aipkitForms_initModelSettingsModal === "function") {
      window.aipkitForms_initModelSettingsModal(formsContainer);
    }
    if (
      typeof window.aipkitForms_initKnowledgeBaseSettingsModal === "function"
    ) {
      window.aipkitForms_initKnowledgeBaseSettingsModal(formsContainer);
    }
    if (
      typeof window.aipkitForms_initWebSearchSettingsModal === "function"
    ) {
      window.aipkitForms_initWebSearchSettingsModal(formsContainer);
    }
    if (typeof window.aipkitForms_initModelSync === "function") {
      window.aipkitForms_initModelSync(formsContainer);
    }
    if (typeof window.aipkitForms_initFormGeneratorModal === "function") {
      window.aipkitForms_initFormGeneratorModal(formsContainer);
    }
    if (typeof window.aipkitForms_initSettingsAutosave === "function") {
      window.aipkitForms_initSettingsAutosave(formsContainer);
    } else if (!isInitialized) {
      console.error(
        "AI Forms Init: aipkitForms_initSettingsAutosave function not found."
      );
    }
    if (typeof window.aipkitForms_initAllowedModelsSelector === "function") {
      window.aipkitForms_initAllowedModelsSelector(formsContainer);
    }
    if (typeof window.aipkit_refreshContentWriterSelectPickers === "function") {
      window.aipkit_refreshContentWriterSelectPickers();
    }
    if (typeof window.aipkitForms_initPreviewSheet === "function") {
      window.aipkitForms_initPreviewSheet(formsContainer);
    }
    // Returning to the module refreshes its controls without rebinding editor listeners.
    if (isInitialized) {
      window.aipkitForms_initializeDragAndDrop(formsContainer);
      window.aipkitForms_initListView();
      window.aipkitForms_initializeAccordions(formsContainer);
      window.aipkitForms_initializeTokenUI(formsContainer);
      return;
    }

    window.aipkitForms_attachClickEventListeners(formsContainer);
    window.aipkitForms_attachChangeEventListeners(formsContainer);
    window.aipkitForms_attachSettingsPanelListeners(formsContainer);
    window.aipkitForms_initializeAccordions(formsContainer);

    // Initialize prompt snippets
    if (typeof window.aipkitForms_initPromptSnippets === "function") {
      window.aipkitForms_initPromptSnippets(formsContainer);
    } else {
      console.error(
        "AI Forms Init: aipkitForms_initPromptSnippets function not found."
      );
    }

    // Initialize range sliders for the editor view
    if (typeof window.aipkit_attachRangeValueHandlers === "function") {
      window.aipkit_attachRangeValueHandlers("#aipkit_form_editor_container");
    } else {
      console.error(
        "AI Forms Init: aipkit_attachRangeValueHandlers function not found."
      );
    }

    window.aipkitForms_initializeDragAndDrop(formsContainer);
    window.aipkitForms_initListView();
    window.aipkitForms_initializeTokenUI(formsContainer);

    if (typeof window.aipkitForms_initAiConfig === "function") {
      window.aipkitForms_initAiConfig(formsContainer);
    } else {
      console.error(
        "AI Forms Init: aipkitForms_initAiConfig function not found."
      );
    }

    if (typeof window.aipkitForms_initVectorConfig === "function") {
      window.aipkitForms_initVectorConfig(formsContainer);
    } else {
      console.error(
        "AI Forms Init: aipkitForms_initVectorConfig function not found."
      );
    }

    formsContainer.dataset.aipkitInitialized = "true";
  }

  // Expose the main initializer to be called by the module loader
  window.aipkit_initAiForms = aipkit_initAiForms;
})();
