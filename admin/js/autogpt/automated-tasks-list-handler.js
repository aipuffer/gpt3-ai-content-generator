/**
 * AIPKit Automated Tasks - List Handler Orchestrator
 * Initializes and coordinates list-related UI and actions.
 * Stores task list UI state.
 */
(function () {
  "use strict";

  // State variables for the list handler
  let currentTasks = [];
  let currentPage = 1;
  let currentPerPage = 10;

  // Make state accessible if needed by individual action handlers
  window.aipkit_automated_tasks_list_state = {
    get tasks() {
      return [...currentTasks];
    }, // Return a copy
    set tasks(newTasks) {
      currentTasks = Array.isArray(newTasks) ? newTasks : [];
    },
    get page() {
      return currentPage;
    },
    set page(newPage) {
      currentPage = newPage;
    },
    get perPage() {
      return currentPerPage;
    },
    set perPage(newPerPage) {
      const normalized = parseInt(newPerPage, 10);
      currentPerPage = [5, 10, 20, 50].includes(normalized)
        ? normalized
        : 10;
    },
  };

  /**
   * Main initialization for the list aspects of Automated Tasks.
   * This will be called by `aipkit_initAutogpt` in `autogpt-main.js`.
   */
  function initializeListHandler() {
    if (typeof window.aipkit_list_initAutomatedTaskList === "function") {
      window.aipkit_list_initAutomatedTaskList();
    } else {
      console.error(
        "Automated Tasks List Handler: aipkit_list_initAutomatedTaskList function not found."
      );
    }
  }

  // Expose the main initializer for the list handler part
  window.aipkit_initAutomatedTaskList = initializeListHandler;
})();
