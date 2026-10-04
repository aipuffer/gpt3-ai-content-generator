/**
 * AIPKit Automated Tasks - Form Handler Orchestrator
 * Initializes and coordinates form-related UI and actions.
 */
(function () {
  "use strict";

  // State variable for the form handler
  let isSubmittingTask = false;
  let pendingModelSelection = null; // For main AI model
  let pendingImageModelSelection = null;
  let pendingEmbeddingModelSelection = null; // NEW

  // Make state accessible to specific action handlers if needed,
  // though it's better if they are stateless and UI-driven.
  // For this refactor, we'll keep it simple and assume state is managed
  // by the functions that need it, or they update UI that this orchestrator might read.
  window.aipkit_automated_tasks_form_state = {
    get isSubmitting() {
      return isSubmittingTask;
    },
    set isSubmitting(value) {
      isSubmittingTask = value;
    },
    get pendingModelSelection() {
      return pendingModelSelection;
    },
    set pendingModelSelection(value) {
      pendingModelSelection = value;
    },
    get pendingImageModelSelection() {
      return pendingImageModelSelection;
    },
    set pendingImageModelSelection(value) {
      pendingImageModelSelection = value;
    },
    get pendingEmbeddingModelSelection() {
      return pendingEmbeddingModelSelection;
    },
    set pendingEmbeddingModelSelection(value) {
      pendingEmbeddingModelSelection = value;
    },
  };

  /**
   * Main initialization for the form aspects of Automated Tasks.
   * This will be called by `aipkit_initAutogpt` in `autogpt-main.js`.
   */
  function initializeFormHandler() {
    if (typeof window.aipkit_form_initAutomatedTaskForm === "function") {
      window.aipkit_form_initAutomatedTaskForm();
    } else {
      console.error(
        "Automated Tasks Form Handler: aipkit_form_initAutomatedTaskForm function not found."
      );
    }
  }

  // Expose the main initializer for the form handler part
  window.aipkit_initAutomatedTaskForm = initializeFormHandler;
})();
