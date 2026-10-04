/**
 * AIPKit Automated Tasks - List Initialization
 * Initializes the task list UI and general event listeners for the list.
 * Initializes task list interactions.
 */
(function () {
  "use strict";

  function aipkit_list_initAutomatedTaskList() {
    const listWrapper = document.getElementById(
      "aipkit_automated_task_list_wrapper"
    );
    const tasksTableBody = document.getElementById(
      "aipkit_automated_tasks_tbody"
    );
    const addTaskButton = document.getElementById("aipkit_add_new_task_btn");
    const formWrapper = document.getElementById(
      "aipkit_automated_task_form_wrapper"
    );
    const paginationContainer = document.getElementById(
      "aipkit_automated_task_list_pagination"
    );
    const listState = window.aipkit_automated_tasks_list_state;
    if (
      addTaskButton &&
      formWrapper &&
      listWrapper &&
      typeof window.aipkit_form_showAutomatedTaskForm === "function"
    ) {
      addTaskButton.addEventListener("click", () => {
        window.aipkit_form_showAutomatedTaskForm(
          formWrapper,
          listWrapper,
          addTaskButton
        );
      });
    }

    if (
      tasksTableBody &&
      typeof window.aipkit_list_handleTaskActionClick === "function"
    ) {
      tasksTableBody.addEventListener(
        "click",
        window.aipkit_list_handleTaskActionClick
      );
    }

    if (
      paginationContainer &&
      typeof window.aipkit_list_handleListPaginationClick === "function"
    ) {
      paginationContainer.addEventListener(
        "click",
        window.aipkit_list_handleListPaginationClick
      );

      paginationContainer.addEventListener("change", (event) => {
        const perPageSelect = event.target.closest(
          "[data-aipkit-autogpt-tasks-per-page]"
        );
        if (!perPageSelect || !listState) {
          return;
        }

        listState.perPage = perPageSelect.value;
        window.aipkit_list_fetchTasks?.(1);
      });
    }

    // Initial fetch of tasks
    if (typeof window.aipkit_list_fetchTasks === "function") {
      window.aipkit_list_fetchTasks();
    } else {
      console.error("Automated Task List Init: fetchTasks function not found.");
    }
  }

  window.aipkit_list_initAutomatedTaskList = aipkit_list_initAutomatedTaskList;
})();
