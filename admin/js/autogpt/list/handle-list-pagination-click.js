/**
 * AIPKit Automated Tasks - Handle List Pagination Click
 * Manages click events on task list pagination buttons.
 */
(function () {
  "use strict";

  function aipkit_list_handleListPaginationClick(event) {
    const button = event.target.closest("button[data-page]");
    if (button && !button.disabled) {
      const page = parseInt(button.dataset.page, 10);
      if (typeof window.aipkit_list_fetchTasks === "function") {
        window.aipkit_list_fetchTasks(page);
      } else {
        console.error(
          "List Pagination Click: aipkit_list_fetchTasks function not found."
        );
      }
    }
  }

  window.aipkit_list_handleListPaginationClick =
    aipkit_list_handleListPaginationClick;
})();
