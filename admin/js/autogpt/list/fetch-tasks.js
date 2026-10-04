/**
 * AIPKit Automated Tasks - Fetch Tasks
 * Fetches the task list from the backend.
 */
(function () {
  "use strict";

  async function aipkit_list_fetchTasks(page = 1) {
    const tableBody = document.getElementById("aipkit_automated_tasks_tbody");
    const listState = window.aipkit_automated_tasks_list_state;
    // Access config and texts dynamically if needed here or assume they are globally available
    // from the main form handler's localization
    const config = window.aipkit_automated_tasks_config || {};
    const texts = config.text || {};

    if (!tableBody) {
      console.warn("Fetch Tasks: Table body not found.");
      return;
    }
    tableBody.innerHTML = `<tr><td colspan="6" class="aipkit_text-center"><span class="aipkit_spinner" style="display:inline-block;"></span> ${
      texts.loading_tasks || "Loading tasks..."
    }</td></tr>`;

    // Store current page in state
    if (listState) {
      listState.page = page;
    }

    try {
      if (typeof window.aipkit_apiRequest !== "function")
        throw new Error("API request function missing.");

      const requestData = {
        page: page,
        per_page: listState ? listState.perPage : 10,
        _ajax_nonce: config.nonce_manage_tasks,
      };

      const response = await window.aipkit_apiRequest(
        "aipkit_get_automated_tasks",
        requestData
      );

      if (listState && response.pagination?.current_page) {
        listState.page = Number(response.pagination.current_page) || 1;
      }

      if (window.aipkit_automated_tasks_list_state) {
        // Check if state object exists
        window.aipkit_automated_tasks_list_state.tasks = response.tasks || [];
      } else {
        console.warn(
          "Fetch Tasks: List state object not available to store tasks."
        );
      }

      if (typeof window.aipkit_list_renderTasksTable === "function") {
        window.aipkit_list_renderTasksTable(response.tasks || []);
      } else {
        console.error("Fetch Tasks: renderTasksTable function not found.");
      }

      if (typeof window.aipkit_list_renderListPagination === "function") {
        window.aipkit_list_renderListPagination(response.pagination || {});
      } else {
        console.error("Fetch Tasks: renderListPagination function not found.");
      }

      const totalMetric = document.getElementById(
        "aipkit_autogpt_metric_total_tasks"
      );
      if (totalMetric) {
        const totalItems = Number(response.pagination?.total_items) || 0;
        totalMetric.textContent = totalItems.toLocaleString();
        totalMetric.setAttribute("aria-busy", "false");
      }

      const workspaceController =
        window.aipkit_autogpt_empty_workspace_controller;
      if (workspaceController?.contains?.(tableBody)) {
        const paginationCount = Number(response.pagination?.total_items);
        workspaceController.reportTasks(
          Number.isFinite(paginationCount)
            ? paginationCount
            : (response.tasks || []).length
        );
      }
    } catch (error) {
      console.error("Fetch Tasks Error:", error);
      tableBody.innerHTML = `<tr><td colspan="6" class="aipkit_text-center aipkit_text-danger">${
        texts.error_loading_tasks || "Error loading tasks:"
      } ${error.message || "Unknown error"}</td></tr>`;
      const workspaceController =
        window.aipkit_autogpt_empty_workspace_controller;
      if (workspaceController?.contains?.(tableBody)) {
        workspaceController.reportLoadError();
      }
    }
  }

  window.aipkit_list_fetchTasks = aipkit_list_fetchTasks;
})();
