/**
 * AIPKit Automated Tasks - Save Task: Finalize Form Success
 * Handles UI actions after a task is successfully saved.
 */
(function () {
  "use strict";

  /**
   * Hides the form and refreshes the task list.
   */
  function aipkit_save_task_finalizeSuccess() {
    if (typeof window.aipkit_clearActiveAutogptPromptDraft === "function") {
      window.aipkit_clearActiveAutogptPromptDraft();
    }

    window.aipkit_autogpt_empty_workspace_controller?.handleTaskSaved?.();

    if (typeof window.aipkit_list_fetchTasks === "function") {
      window.aipkit_list_fetchTasks();
    }

    if (typeof window.aipkit_form_hideAutomatedTaskForm === "function") {
      window.aipkit_form_hideAutomatedTaskForm(
        document.getElementById("aipkit_automated_task_form_wrapper"),
        document.getElementById("aipkit_automated_task_list_wrapper"),
        document.getElementById("aipkit_add_new_task_btn")
      );
    }
  }

  window.aipkit_save_task_finalizeSuccess = aipkit_save_task_finalizeSuccess;
})();
