/**
 * AIPKit Automated Tasks - Handle Task Action Click (Delegator)
 * Manages click events on task action buttons by delegating to specific action handlers.
 */
(function () {
  "use strict";

  /**
   * Event delegation handler for all actions in the task list.
   * @param {Event} event The click event.
   */
  async function aipkit_list_handleTaskActionClick(event) {
    const button = event.target.closest("button[data-action]");
    if (!button) return;

    const action = button.dataset.action;
    const taskId = button.dataset.taskId;

    if (!taskId) return;

    // Fetch task data from state
    const currentTasks = window.aipkit_automated_tasks_list_state
      ? window.aipkit_automated_tasks_list_state.tasks
      : [];
    const task = currentTasks.find(
      (t) => t.id.toString() === taskId.toString()
    );

    switch (action) {
      case "edit":
        if (typeof window.aipkit_handleEditTaskAction === "function") {
          window.aipkit_handleEditTaskAction(task);
        }
        break;

      case "delete":
        if (typeof window.aipkit_handleDeleteTaskAction === "function") {
          window.aipkit_handleDeleteTaskAction(button, taskId);
        }
        break;

      case "pause":
      case "resume":
        if (typeof window.aipkit_handleToggleTaskStatusAction === "function") {
          const newStatus = action === "pause" ? "paused" : "active";
          window.aipkit_handleToggleTaskStatusAction(button, taskId, newStatus);
        }
        break;

      case "run_now":
        if (typeof window.aipkit_handleRunNowTaskAction === "function") {
          window.aipkit_handleRunNowTaskAction(button, taskId);
        }
        break;

      default:
        console.warn(`Unknown task action: ${action}`);
        break;
    }
  }

  // Expose the main delegator function globally
  window.aipkit_list_handleTaskActionClick = aipkit_list_handleTaskActionClick;
})();
