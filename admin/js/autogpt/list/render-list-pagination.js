/**
 * AIPKit Automated Tasks - Render List Pagination
 * Renders pagination controls for the tasks list table.
 */
(function () {
  "use strict";

  function aipkit_list_renderListPagination(paginationData) {
    const paginationContainer = document.getElementById(
      "aipkit_automated_task_list_pagination"
    );
    const config = window.aipkit_automated_tasks_config || {};
    const texts = config.text || {};

    if (!paginationContainer) {
      console.warn("Render List Pagination: Pagination container not found.");
      return;
    }
    paginationContainer.innerHTML = "";

    const { total_items, total_pages, current_page, per_page } =
      paginationData || {};
    const totalItems = Number(total_items) || 0;
    const totalPages = Math.max(1, Number(total_pages) || 1);
    const currentPage = Number(current_page) || 1;
    const perPage = Number(per_page) || 10;

    if (!totalItems) {
      return;
    }

    const countSpan = document.createElement("span");
    countSpan.className = "aipkit_pagination-count";
    countSpan.textContent = `${totalItems.toLocaleString()} ${
      totalItems === 1
        ? texts.task_singular || "task"
        : texts.task_plural || "tasks"
    }`;
    paginationContainer.appendChild(countSpan);

    const controls = document.createElement("span");
    controls.className = "aipkit_autogpt_pagination_controls";

    const perPageGroup = document.createElement("label");
    perPageGroup.className = "aipkit_autogpt_rows_per_page";
    const perPageLabel = document.createElement("span");
    perPageLabel.textContent = texts.rows_per_page || "Rows per page";
    const perPageSelect = document.createElement("select");
    perPageSelect.setAttribute("data-aipkit-autogpt-tasks-per-page", "");
    perPageSelect.setAttribute(
      "aria-label",
      texts.rows_per_page || "Rows per page"
    );
    [5, 10, 20, 50].forEach((value) => {
      const option = document.createElement("option");
      option.value = String(value);
      option.textContent = String(value);
      option.selected = value === perPage;
      perPageSelect.appendChild(option);
    });
    perPageGroup.append(perPageLabel, perPageSelect);
    controls.appendChild(perPageGroup);

    const linksSpan = document.createElement("span");
    linksSpan.className = "aipkit_pagination-links";

    const prevButton = document.createElement("button");
    prevButton.className = "aipkit_btn aipkit_btn-secondary aipkit_btn-small";
    prevButton.textContent = texts.previous_button || "Previous";
    prevButton.disabled = currentPage <= 1;
    if (!prevButton.disabled) prevButton.dataset.page = currentPage - 1;
    linksSpan.appendChild(prevButton);

    const currentSpan = document.createElement("span");
    currentSpan.className = "aipkit_pagination-current";
    currentSpan.textContent = `${currentPage}/${totalPages}`;
    linksSpan.appendChild(currentSpan);

    const nextButton = document.createElement("button");
    nextButton.className = "aipkit_btn aipkit_btn-secondary aipkit_btn-small";
    nextButton.textContent = texts.next_button || "Next";
    nextButton.disabled = currentPage >= totalPages;
    if (!nextButton.disabled) nextButton.dataset.page = currentPage + 1;
    linksSpan.appendChild(nextButton);
    controls.appendChild(linksSpan);
    paginationContainer.appendChild(controls);
  }

  window.aipkit_list_renderListPagination = aipkit_list_renderListPagination;
})();
