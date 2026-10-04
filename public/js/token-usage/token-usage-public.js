/**
 * AIPKit Public - Token Usage Shortcode JS
 *
 * Handles showing an inline, paginated view of token usage details.
 */
(function () {
  "use strict";

  /**
   * Fetches and renders detailed usage data into a details row.
   * @param {string} module The module slug (e.g., 'chat').
   * @param {number} contextId The context ID (e.g., bot_id for chat).
   * @param {number} page The page number to fetch.
   * @param {HTMLElement} contentContainer The container div inside the new row to render into.
   */
  function fetchAndRenderDetails(module, contextId, page, contentContainer) {
    const config = window.aipkit_token_usage_config || {};
    const texts = config.text || {};

    const tableContainer = document.createElement("div");
    tableContainer.className = "aipkit-usage-details-table-container";
    const paginationContainer = document.createElement("div");
    paginationContainer.className = "aipkit-usage-details-pagination-container";

    contentContainer.appendChild(tableContainer);
    contentContainer.appendChild(paginationContainer);

    tableContainer.innerHTML = `<div class="aipkit-usage-details-loading"><span class="aipkit_spinner"></span><span>${
      texts.loadingDetails || "Loading activity..."
    }</span></div>`;

    const data = {
      action: "aipkit_get_token_usage_details",
      nonce: config.nonce,
      module: module,
      context_id: contextId,
      page: page,
    };

    const formData = new FormData();
    for (const key in data) {
      formData.append(key, data[key]);
    }

    fetch(config.ajaxUrl, {
      method: "POST",
      body: formData,
      credentials: "same-origin",
    })
      .then((response) => response.json())
      .then((json) => {
        if (!json.success) {
          throw new Error(json.data?.message || "Failed to load activity.");
        }
        renderDetailsTable(json.data.items, tableContainer);
        renderPagination(
          json.data.pagination,
          paginationContainer,
          module,
          contextId,
          contentContainer
        );
      })
      .catch((error) => {
        console.error("Error fetching token usage details:", error);
        tableContainer.innerHTML = `<div class="aipkit-usage-details-error">${
          texts.errorLoading || "Error loading activity."
        } (${error.message})</div>`;
      });
  }

  /**
   * Renders the table of usage details.
   * @param {Array} items Array of usage items [{timestamp, tokens}].
   * @param {HTMLElement} tableContainer The container for the table.
   */
  function renderDetailsTable(items, tableContainer) {
    if (!items || items.length === 0) {
      tableContainer.innerHTML =
        '<div class="aipkit-usage-details-empty">No detailed usage activity found for this period.</div>';
      return;
    }

    let tableHTML =
      '<table class="aipkit_usage_details_table"><thead><tr><th>Date</th><th>Usage</th></tr></thead><tbody>';
    items.forEach((item) => {
      const date = new Date(item.timestamp * 1000);
      const formattedDate = date.toLocaleString(undefined, {
        dateStyle: "medium",
        timeStyle: "short",
      });
      tableHTML += `<tr><td>${formattedDate}</td><td>${item.tokens.toLocaleString()}</td></tr>`;
    });
    tableHTML += "</tbody></table>";
    tableContainer.innerHTML = tableHTML;
  }

  /**
   * Renders the pagination controls for the details view.
   * @param {Object} paginationData Pagination data from the server.
   * @param {HTMLElement} paginationContainer The container for pagination controls.
   * @param {string} module The module slug.
   * @param {number} contextId The context ID.
   * @param {HTMLElement} contentContainer The main content container of the details row.
   */
  function renderPagination(
    paginationData,
    paginationContainer,
    module,
    contextId,
    contentContainer
  ) {
    const { currentPage, totalPages } = paginationData;
    const texts = window.aipkit_token_usage_config?.text || {};
    paginationContainer.innerHTML = ""; // Clear previous

    if (totalPages <= 1) return;

    // Previous Button
    const prevButton = document.createElement("button");
    prevButton.className = "aipkit_btn aipkit_btn-secondary aipkit_btn-small";
    prevButton.textContent = texts.previous || "Previous";
    prevButton.disabled = currentPage <= 1;
    prevButton.addEventListener("click", () =>
      fetchAndRenderDetails(
        module,
        contextId,
        currentPage - 1,
        contentContainer
      )
    );
    paginationContainer.appendChild(prevButton);

    // Page Indicator
    const pageInfo = document.createElement("span");
    pageInfo.className = "aipkit_pagination-current";
    pageInfo.textContent = `${texts.pageLabel || "Page"} ${currentPage} ${
      texts.ofLabel || "of"
    } ${totalPages}`;
    paginationContainer.appendChild(pageInfo);

    // Next Button
    const nextButton = document.createElement("button");
    nextButton.className = "aipkit_btn aipkit_btn-secondary aipkit_btn-small";
    nextButton.textContent = texts.next || "Next";
    nextButton.disabled = currentPage >= totalPages;
    nextButton.addEventListener("click", () =>
      fetchAndRenderDetails(
        module,
        contextId,
        currentPage + 1,
        contentContainer
      )
    );
    paginationContainer.appendChild(nextButton);
  }

  // Use event delegation to attach listener to the main dashboard container
  document.addEventListener("click", function (event) {
    const detailsButton = event.target.closest(".aipkit-usage-details-btn");
    if (!detailsButton || detailsButton.disabled) return;

    const parentRow = detailsButton.closest("tr.aipkit-usage-main-row");
    if (!parentRow) return;

    const nextRow = parentRow.nextElementSibling;
    const isDetailsRowOpen =
      nextRow && nextRow.classList.contains("aipkit-usage-details-row");

    // Close any other open details rows
    document.querySelectorAll(".aipkit-usage-details-row").forEach((row) => {
      if (row !== nextRow) {
        const otherButton = row.previousElementSibling.querySelector(
          ".aipkit-usage-details-btn"
        );
        if (otherButton) otherButton.classList.remove("aipkit-active");
        row.remove();
      }
    });

    if (isDetailsRowOpen) {
      // It's open, so close it
      nextRow.remove();
      detailsButton.classList.remove("aipkit-active");
    } else {
      // It's closed, so open it
      detailsButton.classList.add("aipkit-active");

      const newDetailsRow = document.createElement("tr");
      newDetailsRow.className = "aipkit-usage-details-row";

      const newDetailsCell = document.createElement("td");
      newDetailsCell.colSpan = parentRow.cells.length;
      newDetailsCell.className = "aipkit-usage-details-cell";

      const detailsContentContainer = document.createElement("div");
      detailsContentContainer.className = "aipkit-usage-details-content";
      newDetailsCell.appendChild(detailsContentContainer);

      newDetailsRow.appendChild(newDetailsCell);
      // Insert after the parent row
      parentRow.parentNode.insertBefore(newDetailsRow, parentRow.nextSibling);

      const module = detailsButton.dataset.module;
      const contextId = detailsButton.dataset.contextId;

      fetchAndRenderDetails(module, contextId, 1, detailsContentContainer);
    }
  });

  // Handle purchase history toggle
  document.addEventListener("click", function (event) {
    const toggleButton = event.target.closest(".aipkit_toggle_purchase_history");
    if (!toggleButton) return;

    event.preventDefault();
    
    const detailsContainer = document.getElementById("aipkit_purchase_history_details");
    if (!detailsContainer) return;

    const isExpanded = toggleButton.getAttribute("aria-expanded") === "true";
    
    if (isExpanded) {
      // Hide the details
      detailsContainer.style.display = "none";
      toggleButton.setAttribute("aria-expanded", "false");
    } else {
      // Show the details
      detailsContainer.style.display = "block";
      toggleButton.setAttribute("aria-expanded", "true");
    }
  });

  function scrollDashboardTarget(element, block = "start") {
    if (!element || typeof element.scrollIntoView !== "function") {
      return;
    }

    window.setTimeout(() => {
      element.scrollIntoView({
        behavior: "smooth",
        block,
      });
    }, 60);
  }

  function handleCustomerDashboardDeepLinks() {
    if (!document.querySelector(".aipkit_token_usage_dashboard")) {
      return;
    }

    const params = new URLSearchParams(window.location.search);
    const section = String(params.get("aipkit_section") || "")
      .trim()
      .toLowerCase();

    if (!section) {
      return;
    }

    if (section === "credits") {
      scrollDashboardTarget(
        document.getElementById("aipkit_customer_dashboard_credits")
      );
      return;
    }

    if (section === "purchases") {
      const toggleButton = document.querySelector(".aipkit_toggle_purchase_history");
      const detailsContainer = document.getElementById("aipkit_purchase_history_details");
      if (
        toggleButton &&
        detailsContainer &&
        toggleButton.getAttribute("aria-expanded") !== "true"
      ) {
        toggleButton.click();
      }
      scrollDashboardTarget(
        detailsContainer ||
          document.getElementById("aipkit_customer_dashboard_credits")
      );
      return;
    }

    if (section !== "usage") {
      return;
    }

    const usageSection = document.getElementById("aipkit_customer_dashboard_usage");
    scrollDashboardTarget(usageSection);

    const module = String(params.get("aipkit_module") || "").trim();
    const contextId = String(params.get("aipkit_context_id") || "").trim();
    if (!module || !contextId) {
      return;
    }

    const matchingButton = Array.from(
      document.querySelectorAll(".aipkit-usage-details-btn")
    ).find((button) => {
      return button.dataset.module === module && button.dataset.contextId === contextId;
    });

    if (!matchingButton) {
      return;
    }

    if (!matchingButton.classList.contains("aipkit-active")) {
      matchingButton.click();
    }

    scrollDashboardTarget(
      matchingButton.closest("tr") || matchingButton,
      "center"
    );
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", handleCustomerDashboardDeepLinks, {
      once: true,
    });
  } else {
    handleCustomerDashboardDeepLinks();
  }

})();
