/**
 * AIPKit Automated Tasks - Run Now Task Action Handler
 * Handles the click event for the 'Run Now' button.
 */
(function () {
  "use strict";

  /**
   * Triggers an immediate run of a specified task.
   * @param {HTMLElement} button - The 'Run Now' button element.
   * @param {string} taskId - The ID of the task to run.
   */
  async function aipkit_handleRunNowTaskAction(button, taskId) {
    const config = window.aipkit_automated_tasks_config || {};
    const texts = config.text || {};

    if (typeof window.aipkit_setAutogptActionButtonLoading === "function") {
      window.aipkit_setAutogptActionButtonLoading(button, true);
    } else {
      button.classList.add("aipkit_loading");
      button.disabled = true;
    }

    try {
      if (typeof window.aipkit_apiRequest !== "function") {
        throw new Error("API request function is missing.");
      }
      await window.aipkit_apiRequest("aipkit_run_automated_task_now", {
        task_id: taskId,
        _ajax_nonce: config.nonce_manage_tasks,
      });

      if (typeof window.aipkit_list_showAutomatedTaskStatus === "function") {
        window.aipkit_list_showAutomatedTaskStatus(
          texts.task_run_initiated ||
            "Task run initiated. Check queue below for progress.",
          "success"
        );
      }

      // Refresh the queue to show the newly added items
      if (typeof window.aipkit_queue_fetchAndRenderQueueItems === "function") {
        window.aipkit_queue_fetchAndRenderQueueItems(1);
      }
    } catch (error) {
      if (typeof window.aipkit_list_showAutomatedTaskStatus === "function") {
        window.aipkit_list_showAutomatedTaskStatus(
          `${texts.error_initiating_run || "Error initiating task run:"} ${
            error.message || "Unknown error"
          }`,
          "error"
        );
      }
    } finally {
      if (typeof window.aipkit_setAutogptActionButtonLoading === "function") {
        window.aipkit_setAutogptActionButtonLoading(button, false);
      } else {
        button.classList.remove("aipkit_loading");
        button.disabled = false;
      }
    }
  }

  // Expose the handler function globally
  window.aipkit_handleRunNowTaskAction = aipkit_handleRunNowTaskAction;
})();
