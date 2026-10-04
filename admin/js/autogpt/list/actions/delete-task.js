/**
 * AIPKit Automated Tasks - Delete Task Action Handler
 * Handles the click event for the 'Delete' button in the task list.
 */
(function () {
  "use strict";

  /**
   * Confirms and executes the deletion of a task.
   * @param {HTMLElement} button - The delete button element that was clicked.
   * @param {string} taskId - The ID of the task to delete.
   */
  async function aipkit_handleDeleteTaskAction(button, taskId) {
    const config = window.aipkit_automated_tasks_config || {};
    const texts = config.text || {};

    const executeDelete = async () => {
      if (typeof window.aipkit_setLoadingStateOpenAI === "function") {
        window.aipkit_setLoadingStateOpenAI(
          button,
          true,
          texts.delete_button || "Delete",
          texts.deleting_task || "Deleting..."
        );
      }

      try {
        if (typeof window.aipkit_apiRequest !== "function") {
          throw new Error("API request function is missing.");
        }
        await window.aipkit_apiRequest("aipkit_delete_automated_task", {
          task_id: taskId,
          _ajax_nonce: config.nonce_manage_tasks,
        });

        if (typeof window.aipkit_list_showAutomatedTaskStatus === "function") {
          window.aipkit_list_showAutomatedTaskStatus(
            texts.task_deleted_success || "Task deleted successfully.",
            "success"
          );
        }

        if (typeof window.aipkit_list_fetchTasks === "function") {
          window.aipkit_list_fetchTasks(); // Refresh the task list
        }
        // Refresh the queue to show any items that are now orphaned (though they'll be processed or error out)
        if (
          typeof window.aipkit_queue_fetchAndRenderQueueItems === "function"
        ) {
          window.aipkit_queue_fetchAndRenderQueueItems(1);
        }
      } catch (error) {
        if (
          typeof window.aipkit_list_showAutomatedTaskStatus === "function"
        ) {
          window.aipkit_list_showAutomatedTaskStatus(
            `${texts.error_deleting_task || "Error deleting task:"} ${
              error.message || "Unknown error"
            }`,
            "error"
          );
        }
      } finally {
        if (typeof window.aipkit_setLoadingStateOpenAI === "function") {
          window.aipkit_setLoadingStateOpenAI(
            button,
            false,
            texts.delete_button || "Delete"
          );
        }
      }
    };

    const taskName =
      button.closest("tr")?.cells?.[0]?.textContent?.trim() ||
      "this automation";
    const confirmMessage = (
      texts.confirm_delete_task ||
      "This permanently deletes “%s” and stops future runs. This cannot be undone."
    ).replace("%s", taskName);

    if (typeof window.aipkit_showConfirmModal === "function") {
      window.aipkit_showConfirmModal(confirmMessage, {
        title: texts.confirm_delete_task_title || "Delete automation",
        confirmText: texts.delete_button || "Delete",
        cancelText: texts.cancel_button || "Cancel",
        variant: "danger",
        onConfirm: () => {
          void executeDelete();
        },
      });
      return;
    }

    if (!confirm(confirmMessage)) {
      return;
    }

    await executeDelete();
  }

  // Expose the handler function globally
  window.aipkit_handleDeleteTaskAction = aipkit_handleDeleteTaskAction;
})();
