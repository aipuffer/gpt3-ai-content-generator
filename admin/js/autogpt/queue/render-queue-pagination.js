/**
 * AIPKit Automated Tasks - Render Queue Pagination
 * Renders pagination controls for the queue table.
 */
(function () {
  "use strict";

  function aipkit_queue_renderOverviewMetrics(summaryData) {
    const pendingCount = Number(summaryData.pending) || 0;
    const runningCount = Number(summaryData.processing) || 0;
    const failedCount = Number(summaryData.failed) || 0;
    const pendingMetric = document.getElementById(
      "aipkit_autogpt_metric_pending"
    );
    const runningMetric = document.getElementById(
      "aipkit_autogpt_metric_running"
    );
    const failedMetric = document.getElementById(
      "aipkit_autogpt_metric_failed"
    );
    const failedMetricCard = document.getElementById(
      "aipkit_autogpt_metric_failed_card"
    );
    const paginationContainer = document.getElementById(
      "aipkit_automated_task_queue_pagination"
    );
    const totalItems = Number(summaryData.total) || 0;
    const config = window.aipkit_automated_tasks_config || {};
    const texts = config.text || {};

    if (pendingMetric) {
      pendingMetric.textContent = pendingCount.toLocaleString();
      pendingMetric.setAttribute("aria-busy", "false");
    }
    if (runningMetric) {
      runningMetric.textContent = runningCount.toLocaleString();
      runningMetric.setAttribute("aria-busy", "false");
    }
    if (failedMetric) {
      failedMetric.textContent = failedCount.toLocaleString();
      failedMetric.setAttribute("aria-busy", "false");
    }

    if (paginationContainer) {
      let countSpan = paginationContainer.querySelector(
        ".aipkit_pagination-count--queue"
      );
      if (totalItems > 0) {
        if (!countSpan) {
          countSpan = document.createElement("span");
          countSpan.className =
            "aipkit_pagination-count aipkit_pagination-count--queue";
          paginationContainer.prepend(countSpan);
        }
        countSpan.textContent = `${totalItems.toLocaleString()} ${
          totalItems === 1
            ? texts.item_singular || "item"
            : texts.item_plural || "items"
        }`;
      } else if (countSpan) {
        countSpan.remove();
      }
    }
    if (failedMetricCard) {
      failedMetricCard.dataset.hasFailures = failedCount > 0 ? "true" : "false";
    }

  }

  function aipkit_queue_renderQueuePagination(paginationData) {
    const paginationContainer = document.getElementById(
      "aipkit_automated_task_queue_pagination"
    );
    // Access config and texts dynamically
    const config = window.aipkit_automated_tasks_config || {};
    const texts = config.text || {};

    if (!paginationContainer) {
      console.warn("Render Queue Pagination: Pagination container not found.");
      return;
    }
    Array.from(paginationContainer.children).forEach((child) => {
      if (!child.classList.contains("aipkit_pagination-count--queue")) {
        child.remove();
      }
    });

    const { current_page, has_previous, has_more } = paginationData || {};
    const currentPage = Number(current_page) || 1;
    const hasPrevious = Boolean(has_previous) || currentPage > 1;
    const hasMore = Boolean(has_more);

    if (hasPrevious || hasMore) {
      const linksSpan = document.createElement("span");
      linksSpan.className = "aipkit_pagination-links";

      const prevButton = document.createElement("button");
      prevButton.className = "aipkit_btn aipkit_btn-secondary aipkit_btn-small";
      prevButton.textContent = texts.previous_button || "Previous";
      prevButton.disabled = !hasPrevious;
      if (!prevButton.disabled) prevButton.dataset.page = currentPage - 1;
      linksSpan.appendChild(prevButton);

      const currentSpan = document.createElement("span");
      currentSpan.className = "aipkit_pagination-current";
      currentSpan.textContent = `${texts.page_label || "Page"} ${currentPage}`;
      linksSpan.appendChild(currentSpan);

      const nextButton = document.createElement("button");
      nextButton.className = "aipkit_btn aipkit_btn-secondary aipkit_btn-small";
      nextButton.textContent = texts.next_button || "Next";
      nextButton.disabled = !hasMore;
      if (!nextButton.disabled) nextButton.dataset.page = currentPage + 1;
      linksSpan.appendChild(nextButton);
      paginationContainer.appendChild(linksSpan);
    }
  }

  window.aipkit_queue_renderQueuePagination =
    aipkit_queue_renderQueuePagination;
  window.aipkit_queue_renderOverviewMetrics =
    aipkit_queue_renderOverviewMetrics;
})();
