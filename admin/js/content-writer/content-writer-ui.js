
// --- Import the new component handlers ---
import "./ui/handleProviderModelDropdowns.js";
import "./ui/handleCopyButton.js";
import "./ui/handleClearButton.js";
// --- End Import ---

/**
 * AIPKit Content Writer - UI Initializer
 * Initializes UI components like provider/model dropdowns and button event listeners.
 * Calls the individual component handler functions.
 */
(function () {
  "use strict";

  /**
   * Initializes the Content Writer UI components.
   * This function is called by aipkit_initContentWriter in content-writer-main.js.
   * @param {HTMLSelectElement} providerSelect
   * @param {HTMLSelectElement} modelSelect
   * @param {HTMLButtonElement} generateBtn // Unused in this function but passed for context
   * @param {HTMLButtonElement} copyBtn
   * @param {HTMLButtonElement} clearBtn
   */
  function aipkit_initContentWriterUI(
    providerSelect,
    modelSelect,
    generateBtn,
    copyBtn,
    clearBtn
  ) {

    if (typeof window.aipkit_handleProviderModelDropdowns === "function") {
      window.aipkit_handleProviderModelDropdowns(providerSelect, modelSelect);
    } else {
      console.error(
        "Content Writer UI: handleProviderModelDropdowns function not found."
      );
    }

    if (typeof window.aipkit_handleCopyButton === "function") {
      window.aipkit_handleCopyButton(copyBtn);
    } else {
      console.error("Content Writer UI: handleCopyButton function not found.");
    }

    if (typeof window.aipkit_handleClearButton === "function") {
      // Clear button needs reference to copy button to disable it
      window.aipkit_handleClearButton(clearBtn, copyBtn);
    } else {
      console.error("Content Writer UI: handleClearButton function not found.");
    }

  }

  window.aipkit_initContentWriterUI = aipkit_initContentWriterUI;
})();
