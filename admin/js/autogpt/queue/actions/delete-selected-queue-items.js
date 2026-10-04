/**
 * AIPKit Automated Tasks - Delete Selected Queue Items
 * Deletes only the queue rows the user explicitly selected.
 */
(function () {
  "use strict";

  async function aipkit_handleDeleteSelectedQueueItems() {
    const button = document.getElementById(
      "aipkit_delete_selected_queue_items_btn"
    );
    const queueState = window.aipkit_automated_tasks_queue_state;
    const selectedIds = Array.from(queueState?.selectedIds || []).filter(
      (itemId) => Number(itemId) > 0
    );
    if (!button || selectedIds.length === 0) {
      return;
    }

    const config = window.aipkit_automated_tasks_config || {};
    const texts = config.text || {};
    const currentQueuePage = queueState?.page || 1;
    const visibleItemCount = document.querySelectorAll(
      "#aipkit_automated_task_queue_tbody .aipkit_autogpt_queue_row_checkbox"
    ).length;
    const pageAfterDelete =
      currentQueuePage > 1 && selectedIds.length === visibleItemCount
        ? currentQueuePage - 1
        : currentQueuePage;
    const confirmMessage = (
      texts.confirm_delete_selected_queue_items ||
      "This permanently deletes %d selected queue items. This cannot be undone."
    ).replace("%d", String(selectedIds.length));

    const showStatus = (message, tone) => {
      if (
        typeof window.aipkit_form_showAutomatedTaskFormStatus === "function"
      ) {
        window.aipkit_form_showAutomatedTaskFormStatus(message, tone);
        return;
      }
      if (typeof window.aipkit_list_showAutomatedTaskStatus === "function") {
        window.aipkit_list_showAutomatedTaskStatus(message, tone);
      }
    };

    const executeDelete = async () => {
      button.disabled = true;
      button.classList.add("is-busy");

      try {
        if (typeof window.aipkit_apiRequest !== "function") {
          throw new Error("API request function is missing.");
        }

        const response = await window.aipkit_apiRequest(
          "aipkit_delete_automated_task_queue_items",
          {
            item_ids: JSON.stringify(selectedIds),
            _ajax_nonce: config.nonce_manage_tasks,
          }
        );

        showStatus(
          response.message ||
            texts.queue_items_deleted ||
            "Selected queue items deleted.",
          "success"
        );
        queueState?.selectedIds?.clear();
        if (typeof window.aipkit_queue_syncSelectionUi === "function") {
          window.aipkit_queue_syncSelectionUi();
        }
        if (
          typeof window.aipkit_queue_fetchAndRenderQueueItems === "function"
        ) {
          await window.aipkit_queue_fetchAndRenderQueueItems(pageAfterDelete);
        }
      } catch (error) {
        showStatus(
          `${
            texts.error_deleting_queue_items || "Error deleting queue items:"
          } ${error.message || "Unknown error"}`,
          "error"
        );
      } finally {
        button.disabled = false;
        button.classList.remove("is-busy");
      }
    };

    if (typeof window.aipkit_showConfirmModal === "function") {
      window.aipkit_showConfirmModal(confirmMessage, {
        title:
          texts.confirm_delete_selected_queue_items_title ||
          "Delete queue items",
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

  window.aipkit_handleDeleteSelectedQueueItems =
    aipkit_handleDeleteSelectedQueueItems;
})();
