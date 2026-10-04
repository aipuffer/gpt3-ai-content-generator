/**
 * AIPKit Automated Tasks - Queue Handler Orchestrator
 * Initializes and coordinates queue-related UI and actions.
 * Stores queue search, filtering, pagination, and selection state.
 */
(function () {
  "use strict";

  // State variables for the queue handler
  let currentQueuePage = 1;
  let currentQueueSearch = "";
  let currentQueueStatus = "all";
  let queuePageCursors = [""];
  let nextQueueCursor = "";
  const selectedQueueItemIds = new Set();

  const resetQueuePagination = () => {
    currentQueuePage = 1;
    queuePageCursors = [""];
    nextQueueCursor = "";
  };

  window.aipkit_automated_tasks_queue_state = {
    get page() {
      return currentQueuePage;
    },
    set page(newPage) {
      currentQueuePage = newPage;
    },
    get search() {
      return currentQueueSearch;
    },
    set search(newSearch) {
      if (currentQueueSearch !== newSearch) {
        currentQueueSearch = newSearch;
        resetQueuePagination();
      }
    },
    get status() {
      return currentQueueStatus;
    },
    set status(newStatus) {
      if (currentQueueStatus !== newStatus) {
        currentQueueStatus = newStatus;
        resetQueuePagination();
      }
    },
    get selectedIds() {
      return selectedQueueItemIds;
    },
    getCursor(page) {
      return queuePageCursors[Math.max(0, Number(page || 1) - 1)] || "";
    },
    recordPage(page, cursor, nextCursor) {
      const pageIndex = Math.max(0, Number(page || 1) - 1);
      queuePageCursors[pageIndex] = cursor || "";
      queuePageCursors = queuePageCursors.slice(0, pageIndex + 1);
      nextQueueCursor = nextCursor || "";
      if (nextQueueCursor) {
        queuePageCursors[pageIndex + 1] = nextQueueCursor;
      }
    },
    resetPagination: resetQueuePagination,
  };

  /**
   * Main initialization for the queue aspects of Automated Tasks.
   * This will be called by `aipkit_initAutogpt` in `autogpt-main.js`.
   */
  function initializeQueueHandler() {
    if (typeof window.aipkit_queue_initAutomatedTaskQueue === "function") {
      window.aipkit_queue_initAutomatedTaskQueue();
    } else {
      console.error(
        "Automated Tasks Queue Handler: aipkit_queue_initAutomatedTaskQueue function not found."
      );
    }
  }

  // Expose the main initializer for the queue handler part
  window.aipkit_initAutomatedTaskQueue = initializeQueueHandler;
})();
