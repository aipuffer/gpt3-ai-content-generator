/**
 * AIPKit Automated Tasks - Edit Task Action Handler
 * Handles the click event for the 'Edit' button in the task list.
 */
(function () {
  "use strict";

  /**
   * Shows the task form pre-filled with the data for the selected task.
   * @param {object} task - The task data object from the state.
   */
  function aipkit_handleEditTaskAction(task) {
    if (!task) {
      console.error("Edit Task Action: Task data is missing.");
      return;
    }
    if (typeof window.aipkit_form_showAutomatedTaskForm === "function") {
      window.aipkit_form_showAutomatedTaskForm(
        document.getElementById("aipkit_automated_task_form_wrapper"),
        document.getElementById("aipkit_automated_task_list_wrapper"),
        document.getElementById("aipkit_add_new_task_btn"),
        task
      );
    } else {
      console.error(
        "Edit Task Action: showAutomatedTaskForm function is not available."
      );
      alert("Error: Cannot open the edit form.");
    }
  }

  // Expose the handler function globally
  window.aipkit_handleEditTaskAction = aipkit_handleEditTaskAction;
})();
