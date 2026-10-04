/**
 * AIPKit Automated Tasks - Toggle Task Status Action Handler
 * Handles the click event for the 'Pause' and 'Resume' buttons.
 */
(function () {
  "use strict";

  /**
   * Toggles the status of a task between 'active' and 'paused'.
   * @param {HTMLElement} button - The button element that was clicked.
   * @param {string} taskId - The ID of the task to update.
   * @param {string} newStatus - The new status to set ('active' or 'paused').
   */
  async function aipkit_handleToggleTaskStatusAction(
    button,
    taskId,
    newStatus
  ) {
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
      await window.aipkit_apiRequest("aipkit_update_automated_task_status", {
        task_id: taskId,
        status: newStatus,
        _ajax_nonce: config.nonce_manage_tasks,
      });

      if (typeof window.aipkit_list_showAutomatedTaskStatus === "function") {
        window.aipkit_list_showAutomatedTaskStatus(
          `${
            texts.task_status_updated || "Task status updated to"
          } ${newStatus}.`,
          "success"
        );
      }
      if (typeof window.aipkit_list_fetchTasks === "function") {
        window.aipkit_list_fetchTasks(); // Refresh the list
      }
    } catch (error) {
      if (typeof window.aipkit_list_showAutomatedTaskStatus === "function") {
        window.aipkit_list_showAutomatedTaskStatus(
          `${texts.error_updating_status || "Error updating task status:"} ${
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
  window.aipkit_handleToggleTaskStatusAction =
    aipkit_handleToggleTaskStatusAction;
})();
