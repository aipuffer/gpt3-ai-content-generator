/**
 * AIPKit UI Utilities - Logs Pagination Renderer
 * Renders pagination controls for log tables.
 */
(function () {
  "use strict";

  function aipkit_renderLogsPagination(paginationData, containerElement, pageChangeCallback, options = {}) {
    if (!paginationData || !containerElement || typeof pageChangeCallback !== "function") {
      if (containerElement) containerElement.innerHTML = "";
      return;
    }

    containerElement.innerHTML = "";
    const { total_logs, total_pages, current_page } = paginationData;
    const totalLogs = parseInt(total_logs, 10) || 0;
    const totalPages = Math.max(1, parseInt(total_pages, 10) || 1);
    const currentPage = Math.max(1, parseInt(current_page, 10) || 1);
    const cursorMode = Boolean(paginationData.cursor_mode);
    const hasPrevious = Boolean(paginationData.has_previous) || currentPage > 1;
    const hasMore = Boolean(paginationData.has_more);
    const itemCount = parseInt(paginationData.item_count, 10) || 0;

    if ((!cursorMode && !totalLogs) || (cursorMode && !itemCount && !hasPrevious && !hasMore)) {
      return;
    }

    if (!cursorMode && totalPages <= 1 && !options.showSinglePage && !Array.isArray(options.perPageOptions)) {
      return;
    }
    if (cursorMode && !hasPrevious && !hasMore && !options.showSinglePage && !Array.isArray(options.perPageOptions)) {
      return;
    }

    const __ = wp.i18n && wp.i18n.__ ? wp.i18n.__ : (text) => text;
    if (!cursorMode) {
      const countSpan = document.createElement("span");
      countSpan.className = "aipkit_pagination-count";
      const singularLabel = options.itemLabelSingular || __("item", "gpt3-ai-content-generator");
      const pluralLabel = options.itemLabelPlural || __("items", "gpt3-ai-content-generator");
      countSpan.textContent = `${totalLogs.toLocaleString()} ${totalLogs === 1 ? singularLabel : pluralLabel}`;
      containerElement.appendChild(countSpan);
    }

    const linksSpan = document.createElement("span");
    linksSpan.className = "aipkit_pagination-links";

    const perPageOptions = Array.isArray(options.perPageOptions)
      ? options.perPageOptions.map((value) => parseInt(value, 10)).filter(Boolean)
      : [];
    const activePerPage = parseInt(options.perPage, 10) || parseInt(paginationData.per_page, 10) || 0;

    if (perPageOptions.length && typeof options.onPerPageChange === "function") {
      const perPageLabel = document.createElement("label");
      perPageLabel.className = "aipkit_pagination-per-page";

      const labelText = document.createElement("span");
      labelText.textContent = __("Rows per page", "gpt3-ai-content-generator");
      perPageLabel.appendChild(labelText);

      const select = document.createElement("select");
      select.className = "aipkit_pagination-per-page-select";
      select.setAttribute("aria-label", __("Rows per page", "gpt3-ai-content-generator"));
      perPageOptions.forEach((value) => {
        const option = document.createElement("option");
        option.value = String(value);
        option.textContent = String(value);
        option.selected = value === activePerPage;
        select.appendChild(option);
      });
      select.addEventListener("change", () => {
        options.onPerPageChange(parseInt(select.value, 10));
      });
      perPageLabel.appendChild(select);
      linksSpan.appendChild(perPageLabel);
    }

    if ((cursorMode && (hasPrevious || hasMore)) || (!cursorMode && totalPages > 1)) {
      const prevButton = document.createElement("button");
      prevButton.className = "aipkit_btn aipkit_btn-secondary aipkit_btn-small aipkit_pagination-button";
      prevButton.textContent = options.compactControls
        ? __("Prev", "gpt3-ai-content-generator")
        : __("Previous", "gpt3-ai-content-generator");
      prevButton.disabled = cursorMode ? !hasPrevious : currentPage <= 1;
      if (!prevButton.disabled) {
        prevButton.addEventListener("click", (e) => {
          e.preventDefault();
          pageChangeCallback(currentPage - 1);
        });
      }
      linksSpan.appendChild(prevButton);

      const currentSpan = document.createElement("span");
      currentSpan.className = "aipkit_pagination-current";
      currentSpan.textContent = cursorMode
        ? `${__("Page", "gpt3-ai-content-generator")} ${currentPage}`
        : options.compactControls
          ? `${currentPage}/${totalPages}`
          : `${__("Page", "gpt3-ai-content-generator")} ${currentPage} ${__("of", "gpt3-ai-content-generator")} ${totalPages}`;
      linksSpan.appendChild(currentSpan);

      const nextButton = document.createElement("button");
      nextButton.className = "aipkit_btn aipkit_btn-secondary aipkit_btn-small aipkit_pagination-button";
      nextButton.textContent = __("Next", "gpt3-ai-content-generator");
      nextButton.disabled = cursorMode ? !hasMore : currentPage >= totalPages;
      if (!nextButton.disabled) {
        nextButton.addEventListener("click", (e) => {
          e.preventDefault();
          pageChangeCallback(currentPage + 1);
        });
      }
      linksSpan.appendChild(nextButton);
    }

    if (linksSpan.childElementCount) {
      containerElement.appendChild(linksSpan);
    }
  }

  window.aipkit_renderLogsPagination = aipkit_renderLogsPagination;
})();
