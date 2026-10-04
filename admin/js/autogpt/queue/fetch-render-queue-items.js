/**
 * AIPKit Automated Tasks - Fetch and Render Queue Items
 * Fetches and renders items in the task queue.
 * Sends pagination, search, and filter parameters to the backend.
 */
(function () {
  "use strict";

  let queueSummaryRequest = null;

  async function fetchQueueSummary() {
    if (queueSummaryRequest) {
      return queueSummaryRequest;
    }
    const config = window.aipkit_automated_tasks_config || {};
    if (typeof window.aipkit_apiRequest !== "function") {
      return null;
    }
    queueSummaryRequest = window
      .aipkit_apiRequest("aipkit_get_automated_task_queue_items", {
        summary_only: "1",
        _ajax_nonce: config.nonce_manage_tasks,
      })
      .then((response) => {
        if (typeof window.aipkit_queue_renderOverviewMetrics === "function") {
          window.aipkit_queue_renderOverviewMetrics(response.summary || {});
        }
        return response.summary || {};
      })
      .catch((error) => {
        console.error("Fetch Queue Summary Error:", error);
        return null;
      })
      .finally(() => {
        queueSummaryRequest = null;
      });
    return queueSummaryRequest;
  }

  async function aipkit_queue_fetchAndRenderQueueItems(page = 1) {
    const queueState = window.aipkit_automated_tasks_queue_state;
    if (queueState) {
      queueState.page = page;
      queueState.selectedIds?.clear();
    } else {
      console.warn("Fetch/Render Queue: Queue state object not available.");
    }

    const queueTableBody = document.getElementById(
      "aipkit_automated_task_queue_tbody"
    );
    // Access config and texts dynamically
    const config = window.aipkit_automated_tasks_config || {};
    const texts = config.text || {};

    if (!queueTableBody) {
      console.warn("Fetch/Render Queue: Table body not found.");
      return;
    }
    queueTableBody.innerHTML = `<tr><td colspan="6" class="aipkit_text-center"><span class="aipkit_spinner" style="display:inline-block;"></span> ${
      texts.loading_queue || "Loading queue items..."
    }</td></tr>`;
    if (typeof window.aipkit_queue_syncSelectionUi === "function") {
      window.aipkit_queue_syncSelectionUi();
    }

    try {
      if (typeof window.aipkit_apiRequest !== "function")
        throw new Error("API request function missing.");

      const requestData = {
        page: page,
        cursor: queueState?.getCursor?.(page) || "",
        search: queueState ? queueState.search : "",
        status_filter: queueState ? queueState.status : "all",
        _ajax_nonce: config.nonce_manage_tasks,
      };

      const response = await window.aipkit_apiRequest(
        "aipkit_get_automated_task_queue_items",
        requestData
      );

      queueState?.recordPage?.(
        page,
        requestData.cursor,
        response.pagination?.next_cursor || ""
      );

      if (queueState && response.pagination?.current_page) {
        queueState.page = Number(response.pagination.current_page) || 1;
      }

      if (typeof window.aipkit_queue_renderQueueTable === "function") {
        window.aipkit_queue_renderQueueTable(response.items || []);
      } else {
        console.error(
          "Fetch/Render Queue: renderQueueTable function not found."
        );
      }

      if (typeof window.aipkit_queue_renderQueuePagination === "function") {
        window.aipkit_queue_renderQueuePagination(response.pagination || {});
      } else {
        console.error(
          "Fetch/Render Queue: renderQueuePagination function not found."
        );
      }

      const workspaceController =
        window.aipkit_autogpt_empty_workspace_controller;
      const isUnfiltered =
        (!queueState || !queueState.search) &&
        (!queueState || queueState.status === "all");
      if (isUnfiltered && workspaceController?.contains?.(queueTableBody)) {
        workspaceController.reportQueue((response.items || []).length);
      }

      if (isUnfiltered && page === 1) {
        fetchQueueSummary();
      }
    } catch (error) {
      console.error("Fetch/Render Queue Error:", error);
      queueTableBody.innerHTML = `<tr><td colspan="6" class="aipkit_text-center aipkit_text-danger">${
        texts.error_loading_queue || "Error loading queue:"
      } ${error.message || "Unknown error"}</td></tr>`;
      if (typeof window.aipkit_queue_syncSelectionUi === "function") {
        window.aipkit_queue_syncSelectionUi();
      }
      const workspaceController =
        window.aipkit_autogpt_empty_workspace_controller;
      if (workspaceController?.contains?.(queueTableBody)) {
        workspaceController.reportLoadError();
      }
    }
  }

  window.aipkit_queue_fetchAndRenderQueueItems =
    aipkit_queue_fetchAndRenderQueueItems;
})();
