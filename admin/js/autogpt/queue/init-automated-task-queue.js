/**
 * AIPKit Automated Tasks - Queue Initialization
 * Initializes the task queue viewer and its general event listeners.
 * Initializes listeners for search, filtering, selection, and item actions.
 */
(function () {
  "use strict";

  function aipkit_queue_initAutomatedTaskQueue() {
    const queueTableBody = document.getElementById(
      "aipkit_automated_task_queue_tbody"
    );
    const refreshQueueButton = document.getElementById(
      "aipkit_refresh_task_queue_btn"
    );
    const queuePaginationContainer = document.getElementById(
      "aipkit_automated_task_queue_pagination"
    );
    const searchInput = document.getElementById(
      "aipkit_task_queue_search_input"
    );
    const statusFilter = document.getElementById(
      "aipkit_task_queue_status_filter"
    );
    const queueFilters = document.getElementById(
      "aipkit_autogpt_queue_filters"
    );
    const queueSelection = document.getElementById(
      "aipkit_autogpt_queue_selection"
    );
    const queueSelectionCount = document.getElementById(
      "aipkit_autogpt_queue_selection_count"
    );
    const selectAllCheckbox = document.getElementById(
      "aipkit_autogpt_queue_select_all"
    );
    const deleteSelectedButton = document.getElementById(
      "aipkit_delete_selected_queue_items_btn"
    );
    const clearSelectionButton = document.getElementById(
      "aipkit_clear_queue_selection_btn"
    );
    const floatingMenus = [
      {
        menu: document.getElementById("aipkit_autogpt_cron_info"),
        panelSelector: "#aipkit_autogpt_cron_status",
      },
    ];

    const getQueueState = () =>
      window.aipkit_automated_tasks_queue_state || null;

    const getVisibleRowCheckboxes = () =>
      queueTableBody
        ? Array.from(
            queueTableBody.querySelectorAll(
              ".aipkit_autogpt_queue_row_checkbox"
            )
          )
        : [];

    const syncSelectionUi = () => {
      const queueState = getQueueState();
      const selectedIds = queueState?.selectedIds;
      if (!selectedIds) {
        return;
      }

      const texts = window.aipkit_automated_tasks_config?.text || {};
      const selectedCount = selectedIds.size;
      const hasSelection = selectedCount > 0;

      if (queueFilters) {
        queueFilters.hidden = hasSelection;
      }
      if (queueSelection) {
        queueSelection.hidden = !hasSelection;
      }
      if (queueSelectionCount) {
        const countTemplate =
          selectedCount === 1
            ? texts.queue_item_selected || "%d item selected"
            : texts.queue_items_selected || "%d items selected";
        queueSelectionCount.textContent = countTemplate.replace(
          "%d",
          String(selectedCount)
        );
      }

      const visibleCheckboxes = getVisibleRowCheckboxes();
      let visibleSelectedCount = 0;
      visibleCheckboxes.forEach((checkbox) => {
        const itemId = Number(checkbox.value);
        const isSelected = itemId > 0 && selectedIds.has(itemId);
        checkbox.checked = isSelected;
        checkbox.closest("tr")?.classList.toggle(
          "aipkit_autogpt_queue_row--selected",
          isSelected
        );
        if (isSelected) {
          visibleSelectedCount += 1;
        }
      });

      if (selectAllCheckbox) {
        selectAllCheckbox.checked =
          visibleCheckboxes.length > 0 &&
          visibleSelectedCount === visibleCheckboxes.length;
        selectAllCheckbox.indeterminate =
          visibleSelectedCount > 0 &&
          visibleSelectedCount < visibleCheckboxes.length;
        selectAllCheckbox.disabled = visibleCheckboxes.length === 0;
      }
    };

    window.aipkit_queue_syncSelectionUi = syncSelectionUi;

    const positionFloatingMenuPanel = (menuConfig) => {
      if (!menuConfig || !menuConfig.menu || !menuConfig.menu.open) {
        return;
      }

      const trigger = menuConfig.menu.querySelector("summary");
      const panel = menuConfig.menu.querySelector(menuConfig.panelSelector);
      if (!trigger || !panel) {
        return;
      }

      const viewportPadding = 12;
      const gap = 7;
      const triggerRect = trigger.getBoundingClientRect();
      const panelRect = panel.getBoundingClientRect();
      const panelWidth = panelRect.width || panel.offsetWidth || 280;
      const panelHeight = panelRect.height || panel.offsetHeight || 0;

      let left = triggerRect.right - panelWidth;
      left = Math.max(
        viewportPadding,
        Math.min(left, window.innerWidth - panelWidth - viewportPadding)
      );

      let top = triggerRect.bottom + gap;
      const canOpenAbove =
        triggerRect.top - gap - panelHeight >= viewportPadding;
      if (
        panelHeight > 0 &&
        top + panelHeight > window.innerHeight - viewportPadding &&
        canOpenAbove
      ) {
        top = triggerRect.top - panelHeight - gap;
      } else if (
        panelHeight > 0 &&
        top + panelHeight > window.innerHeight - viewportPadding
      ) {
        top = window.innerHeight - panelHeight - viewportPadding;
      }

      panel.style.left = `${left}px`;
      panel.style.top = `${Math.max(viewportPadding, top)}px`;
      menuConfig.menu.dataset.aipkitFloatingMenuPositioned = "1";
    };

    const positionOpenFloatingMenus = () => {
      floatingMenus.forEach(positionFloatingMenuPanel);
    };

    const closeFloatingMenus = (activeMenu = null) => {
      const menuConfigs = window.aipkit_autogpt_queueFloatingMenus || [];
      menuConfigs.forEach((menuConfig) => {
        if (
          menuConfig &&
          menuConfig.menu &&
          menuConfig.menu !== activeMenu &&
          menuConfig.menu.open
        ) {
          delete menuConfig.menu.dataset.aipkitFloatingMenuPositioned;
          menuConfig.menu.open = false;
        }
      });
    };

    window.aipkit_autogpt_queueFloatingMenus = floatingMenus;
    window.aipkit_autogpt_closeQueueFloatingMenus = closeFloatingMenus;

    floatingMenus.forEach((menuConfig) => {
      if (!menuConfig.menu) {
        return;
      }

      const trigger = menuConfig.menu.querySelector("summary");
      if (trigger) {
        trigger.setAttribute(
          "aria-expanded",
          menuConfig.menu.open ? "true" : "false"
        );
      }

      if (menuConfig.menu.dataset.aipkitFloatingMenuBound === "1") {
        return;
      }

      menuConfig.menu.addEventListener("toggle", () => {
        if (trigger) {
          trigger.setAttribute(
            "aria-expanded",
            menuConfig.menu.open ? "true" : "false"
          );
        }
        if (menuConfig.menu.open) {
          delete menuConfig.menu.dataset.aipkitFloatingMenuPositioned;
          closeFloatingMenus(menuConfig.menu);
          positionFloatingMenuPanel(menuConfig);
        } else {
          delete menuConfig.menu.dataset.aipkitFloatingMenuPositioned;
        }
      });

      menuConfig.menu.dataset.aipkitFloatingMenuBound = "1";
    });

    if (!window.aipkit_autogpt_queueFloatingMenusBound) {
      window.addEventListener("resize", positionOpenFloatingMenus);
      window.addEventListener("scroll", positionOpenFloatingMenus, true);
      window.aipkit_autogpt_queueFloatingMenusBound = true;
    }

    if (!window.aipkit_autogpt_queueFloatingMenusDismissBound) {
      document.addEventListener("pointerdown", (event) => {
        const menuConfigs = window.aipkit_autogpt_queueFloatingMenus || [];
        const clickedInsideMenu = menuConfigs.some(
          (menuConfig) =>
            menuConfig &&
            menuConfig.menu &&
            menuConfig.menu.contains(event.target)
        );

        if (
          !clickedInsideMenu &&
          typeof window.aipkit_autogpt_closeQueueFloatingMenus === "function"
        ) {
          window.aipkit_autogpt_closeQueueFloatingMenus();
        }
      });

      document.addEventListener("keydown", (event) => {
        if (
          event.key === "Escape" &&
          typeof window.aipkit_autogpt_closeQueueFloatingMenus === "function"
        ) {
          const openMenuConfig = (
            window.aipkit_autogpt_queueFloatingMenus || []
          ).find(
            (menuConfig) => menuConfig?.menu?.open
          );
          window.aipkit_autogpt_closeQueueFloatingMenus();
          openMenuConfig?.menu?.querySelector("summary")?.focus();
        }
      });

      window.aipkit_autogpt_queueFloatingMenusDismissBound = true;
    }

    if (
      refreshQueueButton &&
      typeof window.aipkit_queue_fetchAndRenderQueueItems === "function"
    ) {
      refreshQueueButton.addEventListener("click", () =>
        window.aipkit_queue_fetchAndRenderQueueItems(1)
      );
    }

    if (searchInput) {
      let searchTimeout;
      searchInput.addEventListener("input", () => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
          const queueState = window.aipkit_automated_tasks_queue_state;
          if (queueState) {
            queueState.search = searchInput.value.trim();
          }
          if (
            typeof window.aipkit_queue_fetchAndRenderQueueItems === "function"
          ) {
            window.aipkit_queue_fetchAndRenderQueueItems(1);
          }
        }, 500); // Debounce
      });
    }

    if (statusFilter) {
      statusFilter.addEventListener("change", () => {
        const queueState = window.aipkit_automated_tasks_queue_state;
        if (queueState) {
          queueState.status = statusFilter.value;
        }
        if (
          typeof window.aipkit_queue_fetchAndRenderQueueItems === "function"
        ) {
          window.aipkit_queue_fetchAndRenderQueueItems(1);
        }
      });
    }

    if (
      queueTableBody &&
      typeof window.aipkit_queue_handleQueueItemActionClick === "function"
    ) {
      queueTableBody.addEventListener(
        "click",
        window.aipkit_queue_handleQueueItemActionClick
      );
    }

    if (queueTableBody) {
      queueTableBody.addEventListener("change", (event) => {
        const checkbox = event.target.closest(
          ".aipkit_autogpt_queue_row_checkbox"
        );
        if (!checkbox) {
          return;
        }

        const itemId = Number(checkbox.value);
        const selectedIds = getQueueState()?.selectedIds;
        if (!selectedIds || itemId <= 0) {
          return;
        }

        if (checkbox.checked) {
          selectedIds.add(itemId);
        } else {
          selectedIds.delete(itemId);
        }
        syncSelectionUi();
      });
    }

    if (selectAllCheckbox) {
      selectAllCheckbox.addEventListener("change", () => {
        const selectedIds = getQueueState()?.selectedIds;
        if (!selectedIds) {
          return;
        }

        getVisibleRowCheckboxes().forEach((checkbox) => {
          const itemId = Number(checkbox.value);
          if (itemId <= 0) {
            return;
          }
          if (selectAllCheckbox.checked) {
            selectedIds.add(itemId);
          } else {
            selectedIds.delete(itemId);
          }
        });
        syncSelectionUi();
      });
    }

    if (clearSelectionButton) {
      clearSelectionButton.addEventListener("click", () => {
        getQueueState()?.selectedIds?.clear();
        syncSelectionUi();
      });
    }

    if (
      deleteSelectedButton &&
      typeof window.aipkit_handleDeleteSelectedQueueItems === "function"
    ) {
      deleteSelectedButton.addEventListener(
        "click",
        window.aipkit_handleDeleteSelectedQueueItems
      );
    }

    if (
      queuePaginationContainer &&
      typeof window.aipkit_queue_handleQueuePaginationClick === "function"
    ) {
      queuePaginationContainer.addEventListener(
        "click",
        window.aipkit_queue_handleQueuePaginationClick
      );
    }

    // Initial fetch of queue items
    if (typeof window.aipkit_queue_fetchAndRenderQueueItems === "function") {
      window.aipkit_queue_fetchAndRenderQueueItems();
    } else {
      console.error(
        "Automated Task Queue Init: fetchAndRenderQueueItems function not found for initial fetch."
      );
    }
  }

  window.aipkit_queue_initAutomatedTaskQueue =
    aipkit_queue_initAutomatedTaskQueue;
})();
