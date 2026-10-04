/**
 * AIPKit Role Manager JS
 *
 * Coordinates role-first and comparison views while keeping one synchronized
 * permission state and autosaving it through the existing endpoint.
 *
 * @since NEXT_VERSION
 */
(function () {
  "use strict";

  const VIEW_STORAGE_KEY = "aipkit_role_manager_view";
  const COMPARISON_ROLES_STORAGE_KEY =
    "aipkit_role_manager_comparison_roles";

  let isSavingRoles = false;
  let savePending = false;
  let currentSavePromise = null;

  function createStatusMessage(type, message) {
    const messageEl = document.createElement("div");
    messageEl.className = `aipkit_settings_message aipkit_settings_message-${type}`;

    const icon = document.createElement("span");
    icon.className =
      type === "success"
        ? "dashicons dashicons-yes-alt"
        : "dashicons dashicons-warning";
    messageEl.appendChild(icon);
    messageEl.appendChild(document.createTextNode(` ${message}`));

    return messageEl;
  }

  function showRoleManagerMessage(messagesDiv, type, message, timeout = 4000) {
    if (!messagesDiv) {
      return;
    }

    messagesDiv.replaceChildren(createStatusMessage(type, message));

    if (timeout > 0) {
      const displayedMessage = messagesDiv.firstElementChild;
      window.setTimeout(() => {
        if (messagesDiv.firstElementChild === displayedMessage) {
          displayedMessage.remove();
        }
      }, timeout);
    }
  }

  function clearRoleManagerMessages(messagesDiv) {
    if (messagesDiv) {
      messagesDiv.replaceChildren();
    }
  }

  function setRoleManagerBusy(isBusy, container, busyScope) {
    if (container) {
      container.setAttribute("aria-busy", isBusy ? "true" : "false");
    }

    if (typeof window.aipkit_setSettingsAutosaveBusy === "function") {
      window.aipkit_setSettingsAutosaveBusy(isBusy, busyScope);
    }
  }

  async function parseRoleManagerResponse(response) {
    let payload = null;

    try {
      payload = await response.json();
    } catch (error) {
      payload = null;
    }

    if (!response.ok) {
      const responseMessage =
        payload && payload.data && payload.data.message
          ? payload.data.message
          : `HTTP error! status: ${response.status}`;
      throw new Error(responseMessage);
    }

    if (!payload || !payload.success) {
      const payloadMessage =
        payload && payload.data && payload.data.message
          ? payload.data.message
          : "An unknown error occurred.";
      throw new Error(payloadMessage);
    }

    return payload.data || {};
  }

  async function postRolePermissions(form, config) {
    const formData = new FormData(form);
    formData.append("action", "aipkit_save_role_permissions");

    if (!formData.has("_ajax_nonce") && config.nonce) {
      formData.append("_ajax_nonce", config.nonce);
    }

    const response = await fetch(config.ajaxurl, {
      method: "POST",
      body: formData,
      credentials: "same-origin",
    });

    return parseRoleManagerResponse(response);
  }

  function saveRolePermissions({
    form,
    config,
    texts,
    container,
    busyScope,
    messagesDiv,
  }) {
    if (isSavingRoles) {
      savePending = true;
      return currentSavePromise;
    }

    currentSavePromise = (async () => {
      isSavingRoles = true;
      savePending = false;
      clearRoleManagerMessages(messagesDiv);
      setRoleManagerBusy(true, container, busyScope);

      try {
        let savedMessage = texts.success || "Permissions saved.";
        let shouldSaveAgain = true;

        while (shouldSaveAgain) {
          shouldSaveAgain = false;
          savePending = false;

          const data = await postRolePermissions(form, config);
          savedMessage = data.message || savedMessage;

          if (savePending) {
            shouldSaveAgain = true;
          }
        }

        showRoleManagerMessage(messagesDiv, "success", savedMessage, 3000);
      } catch (error) {
        console.error("AIPKit Role Manager Save Error:", error);
        showRoleManagerMessage(
          messagesDiv,
          "error",
          error.message || texts.fail || "Failed to save permissions.",
          6000
        );
      } finally {
        setRoleManagerBusy(false, container, busyScope);
        isSavingRoles = false;
        currentSavePromise = null;
      }
    })();

    return currentSavePromise;
  }

  function readStoredValue(key) {
    try {
      return window.localStorage.getItem(key);
    } catch (error) {
      return null;
    }
  }

  function writeStoredValue(key, value) {
    try {
      window.localStorage.setItem(key, value);
    } catch (error) {
      // Storage is optional; the current session still works without it.
    }
  }

  function formatCount(template, first, second) {
    return String(template || "%1$d of %2$d")
      .replace("%1$d", String(first))
      .replace("%2$d", String(second));
  }

  function getPermissionInputs(form, role = "", module = "") {
    return Array.from(
      form.querySelectorAll(".aipkit_role_permission_input")
    ).filter((input) => {
      const roleMatches = !role || input.dataset.role === role;
      const moduleMatches = !module || input.dataset.module === module;
      return roleMatches && moduleMatches;
    });
  }

  function synchronizePermissionInputs(form, changedInput) {
    const role = changedInput.dataset.role || "";
    const module = changedInput.dataset.module || "";
    if (!role || !module) {
      return;
    }

    getPermissionInputs(form, role, module).forEach((input) => {
      if (input !== changedInput) {
        input.checked = changedInput.checked;
      }
    });
  }

  function updateRoleCounts(container, form, texts) {
    const moduleCount = Number.parseInt(container.dataset.moduleCount || "0", 10);
    container.querySelectorAll("[data-role-enabled-count]").forEach((countEl) => {
      const role = countEl.dataset.roleEnabledCount || "";
      const enabledModules = new Set(
        getPermissionInputs(form, role)
          .filter((input) => input.checked)
          .map((input) => input.dataset.module)
          .filter(Boolean)
      );
      countEl.textContent = formatCount(
        texts.enabledCount || "%1$d of %2$d",
        enabledModules.size,
        moduleCount
      );
    });
  }

  function activateRole(container, role, focusTab = false) {
    const roleTabs = Array.from(
      container.querySelectorAll("[data-role-selector]")
    );
    const rolePanels = Array.from(container.querySelectorAll("[data-role-panel]"));
    const selectedTab = roleTabs.find(
      (tab) => tab.dataset.roleSelector === role
    );
    const selectedPanel = rolePanels.find(
      (panel) => panel.dataset.rolePanel === role
    );

    if (!selectedTab || !selectedPanel) {
      return;
    }

    roleTabs.forEach((tab) => {
      const selected = tab === selectedTab;
      tab.classList.toggle("is-active", selected);
      tab.setAttribute("aria-selected", selected ? "true" : "false");
      tab.tabIndex = selected ? 0 : -1;
    });
    rolePanels.forEach((panel) => {
      panel.hidden = panel !== selectedPanel;
    });

    if (focusTab) {
      selectedTab.focus();
    }
  }

  function initializeRoleNavigation(container) {
    const roleTabs = Array.from(
      container.querySelectorAll("[data-role-selector]")
    );
    const searchInput = container.querySelector("[data-role-search]");
    const emptyState = container.querySelector("[data-role-search-empty]");

    roleTabs.forEach((tab) => {
      tab.addEventListener("click", () => {
        activateRole(container, tab.dataset.roleSelector || "");
      });

      tab.addEventListener("keydown", (event) => {
        if (!["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) {
          return;
        }

        const visibleTabs = roleTabs.filter((item) => !item.hidden);
        if (!visibleTabs.length) {
          return;
        }

        event.preventDefault();
        const currentIndex = visibleTabs.indexOf(tab);
        let nextIndex = currentIndex;
        if (event.key === "ArrowDown") {
          nextIndex = (currentIndex + 1) % visibleTabs.length;
        } else if (event.key === "ArrowUp") {
          nextIndex =
            (currentIndex - 1 + visibleTabs.length) % visibleTabs.length;
        } else if (event.key === "Home") {
          nextIndex = 0;
        } else if (event.key === "End") {
          nextIndex = visibleTabs.length - 1;
        }

        activateRole(
          container,
          visibleTabs[nextIndex].dataset.roleSelector || "",
          true
        );
      });
    });

    if (searchInput) {
      searchInput.addEventListener("input", () => {
        const query = searchInput.value.trim().toLocaleLowerCase();
        let visibleCount = 0;

        roleTabs.forEach((tab) => {
          const name = (tab.dataset.roleName || "").toLocaleLowerCase();
          const visible = !query || name.includes(query);
          tab.hidden = !visible;
          if (visible) {
            visibleCount++;
          }
        });

        if (emptyState) {
          emptyState.hidden = visibleCount !== 0;
        }
      });
    }
  }

  function updateComparisonOverflow(scrollContainer) {
    if (!scrollContainer) {
      return;
    }

    const hasOverflow =
      scrollContainer.scrollWidth > scrollContainer.clientWidth + 2;
    const atEnd =
      !hasOverflow ||
      scrollContainer.scrollLeft + scrollContainer.clientWidth >=
        scrollContainer.scrollWidth - 2;

    scrollContainer.classList.toggle("has-overflow", hasOverflow);
    scrollContainer.classList.toggle("is-at-end", atEnd);
    scrollContainer
      .closest(".aipkit_role_manager_comparison_group")
      ?.classList.toggle("show-overflow-fade", hasOverflow && !atEnd);
  }

  function getComparisonScrollContainers(container) {
    return Array.from(
      container.querySelectorAll("[data-comparison-scroll]")
    );
  }

  function getComparisonFilterInputs(container) {
    return Array.from(
      container.querySelectorAll("[data-comparison-role-filter]")
    );
  }

  function applyComparisonRoleVisibility(container, texts, persist = true) {
    const filterInputs = getComparisonFilterInputs(container);
    const selectedRoles = new Set(
      filterInputs
        .filter((input) => input.checked)
        .map((input) => input.dataset.comparisonRoleFilter)
        .filter(Boolean)
    );

    container.querySelectorAll("[data-comparison-role]").forEach((element) => {
      element.hidden = !selectedRoles.has(element.dataset.comparisonRole);
    });

    const countEl = container.querySelector("[data-comparison-count]");
    if (countEl) {
      countEl.textContent = formatCount(
        texts.comparisonCount || "%1$d of %2$d roles shown",
        selectedRoles.size,
        filterInputs.length
      );
    }

    if (persist) {
      writeStoredValue(
        COMPARISON_ROLES_STORAGE_KEY,
        JSON.stringify(Array.from(selectedRoles))
      );
    }

    window.requestAnimationFrame(() => {
      getComparisonScrollContainers(container).forEach(
        updateComparisonOverflow
      );
    });
  }

  function restoreComparisonRoleSelection(container) {
    const filterInputs = getComparisonFilterInputs(container);
    const validRoles = new Set(
      filterInputs
        .map((input) => input.dataset.comparisonRoleFilter)
        .filter(Boolean)
    );
    const storedValue = readStoredValue(COMPARISON_ROLES_STORAGE_KEY);
    let storedRoles = null;

    if (storedValue) {
      try {
        const parsed = JSON.parse(storedValue);
        if (Array.isArray(parsed)) {
          storedRoles = new Set(parsed.filter((role) => validRoles.has(role)));
        }
      } catch (error) {
        storedRoles = null;
      }
    }

    filterInputs.forEach((input) => {
      input.checked = storedRoles
        ? storedRoles.has(input.dataset.comparisonRoleFilter)
        : input.dataset.defaultVisible === "true";
    });
  }

  function initializeComparisonFilter(container, texts) {
    const filterDetails = container.querySelector("[data-role-filter]");
    const filterInputs = getComparisonFilterInputs(container);
    const searchInput = container.querySelector(
      "[data-comparison-role-search]"
    );
    const filterRows = Array.from(
      container.querySelectorAll("[data-comparison-filter-row]")
    );
    const emptyState = container.querySelector(
      "[data-comparison-filter-empty]"
    );
    const scrollContainers = getComparisonScrollContainers(container);

    restoreComparisonRoleSelection(container);
    applyComparisonRoleVisibility(container, texts, false);

    filterInputs.forEach((input) => {
      input.addEventListener("change", () => {
        applyComparisonRoleVisibility(container, texts);
      });
    });

    container
      .querySelectorAll("[data-comparison-filter-action]")
      .forEach((button) => {
        button.addEventListener("click", () => {
          const action = button.dataset.comparisonFilterAction;
          filterInputs.forEach((input) => {
            if (action === "all") {
              input.checked = true;
            } else if (action === "reset") {
              input.checked = input.dataset.defaultVisible === "true";
            }
          });
          applyComparisonRoleVisibility(container, texts);
        });
      });

    if (searchInput) {
      searchInput.addEventListener("input", () => {
        const query = searchInput.value.trim().toLocaleLowerCase();
        let visibleCount = 0;
        filterRows.forEach((row) => {
          const name = (row.dataset.roleName || "").toLocaleLowerCase();
          const visible = !query || name.includes(query);
          row.hidden = !visible;
          if (visible) {
            visibleCount++;
          }
        });
        if (emptyState) {
          emptyState.hidden = visibleCount !== 0;
        }
      });
    }

    if (filterDetails) {
      document.addEventListener("click", (event) => {
        if (
          filterDetails.open &&
          event.target instanceof Node &&
          !filterDetails.contains(event.target)
        ) {
          filterDetails.open = false;
        }
      });

      filterDetails.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && filterDetails.open) {
          filterDetails.open = false;
          filterDetails.querySelector("summary")?.focus();
        }
      });
    }

    if (scrollContainers.length) {
      let isSyncingScroll = false;

      scrollContainers.forEach((scrollContainer) => {
        scrollContainer.addEventListener("scroll", () => {
          if (isSyncingScroll) {
            return;
          }

          isSyncingScroll = true;
          scrollContainers.forEach((otherContainer) => {
            if (otherContainer !== scrollContainer) {
              otherContainer.scrollLeft = scrollContainer.scrollLeft;
            }
            updateComparisonOverflow(otherContainer);
          });
          window.requestAnimationFrame(() => {
            isSyncingScroll = false;
          });
        });
      });

      window.addEventListener("resize", () => {
        scrollContainers.forEach(updateComparisonOverflow);
      });
      if (typeof ResizeObserver === "function") {
        const resizeObserver = new ResizeObserver(() => {
          scrollContainers.forEach(updateComparisonOverflow);
        });
        scrollContainers.forEach((scrollContainer) => {
          resizeObserver.observe(scrollContainer);
        });
      }
    }
  }

  function setRoleManagerView(container, view, persist = true) {
    const validView = view === "comparison" ? "comparison" : "roles";
    container.dataset.activeView = validView;

    container.querySelectorAll("[data-role-manager-view]").forEach((button) => {
      const active = button.dataset.roleManagerView === validView;
      button.classList.toggle("is-active", active);
      button.setAttribute("aria-pressed", active ? "true" : "false");
    });
    container.querySelectorAll("[data-role-manager-panel]").forEach((panel) => {
      panel.hidden = panel.dataset.roleManagerPanel !== validView;
    });

    if (persist) {
      writeStoredValue(VIEW_STORAGE_KEY, validView);
    }

    if (validView === "comparison") {
      window.requestAnimationFrame(() => {
        getComparisonScrollContainers(container).forEach(
          updateComparisonOverflow
        );
      });
    }
  }

  function initializeViewSwitch(container) {
    container.querySelectorAll("[data-role-manager-view]").forEach((button) => {
      button.addEventListener("click", () => {
        setRoleManagerView(container, button.dataset.roleManagerView || "roles");
      });
    });

    const storedView = readStoredValue(VIEW_STORAGE_KEY);
    setRoleManagerView(
      container,
      storedView === "comparison" ? "comparison" : "roles",
      false
    );
  }

  function initializeBulkActions(
    container,
    form,
    config,
    texts,
    busyScope,
    messagesDiv
  ) {
    container.querySelectorAll("[data-role-bulk]").forEach((button) => {
      button.addEventListener("click", () => {
        const role = button.dataset.role || "";
        const shouldEnable = button.dataset.roleBulk === "enable";
        const roleInputs = getPermissionInputs(form, role);

        if (!role || !roleInputs.length) {
          return;
        }

        roleInputs.forEach((input) => {
          input.checked = shouldEnable;
        });
        updateRoleCounts(container, form, texts);
        saveRolePermissions({
          form,
          config,
          texts,
          container,
          busyScope,
          messagesDiv,
        });
      });
    });
  }

  /**
   * Initializes the Role Manager UI and autosave behavior.
   */
  function aipkit_initRoleManager() {
    const roleManagerContainer = document.getElementById(
      "aipkit_role_manager_container"
    );

    if (!roleManagerContainer) {
      return;
    }

    const form = roleManagerContainer.querySelector(
      "#aipkit_role_manager_form"
    );
    const messagesDiv = roleManagerContainer.querySelector(
      "#aipkit_role_manager_messages"
    );
    const busyScope =
      roleManagerContainer.querySelector(".aipkit_role_manager_workspace") ||
      form;

    if (typeof window.aipkit_role_manager_config === "undefined") {
      console.error(
        "AIPKit Role Manager Error: Configuration object (aipkit_role_manager_config) not found."
      );
      showRoleManagerMessage(
        messagesDiv,
        "error",
        "Error: Script configuration missing.",
        0
      );
      return;
    }

    if (!form) {
      console.warn("AIPKit Role Manager: Form element not found.");
      return;
    }

    const listenerAttr = "data-role-manager-initialized";
    if (form.getAttribute(listenerAttr)) {
      return;
    }

    const config = window.aipkit_role_manager_config;
    const texts = config.text || {};

    initializeViewSwitch(roleManagerContainer);
    initializeRoleNavigation(roleManagerContainer);
    initializeComparisonFilter(roleManagerContainer, texts);
    initializeBulkActions(
      roleManagerContainer,
      form,
      config,
      texts,
      busyScope,
      messagesDiv
    );
    updateRoleCounts(roleManagerContainer, form, texts);

    form.addEventListener("change", (event) => {
      if (!(event.target instanceof HTMLInputElement)) {
        return;
      }

      const permissionInput = event.target.closest(
        ".aipkit_role_permission_input"
      );
      if (!permissionInput) {
        return;
      }

      synchronizePermissionInputs(form, permissionInput);
      updateRoleCounts(roleManagerContainer, form, texts);
      saveRolePermissions({
        form,
        config,
        texts,
        container: roleManagerContainer,
        busyScope,
        messagesDiv,
      });
    });

    form.setAttribute(listenerAttr, "true");
  }

  document.addEventListener("DOMContentLoaded", function () {
    const urlParams = new URLSearchParams(window.location.search);
    const currentPage = urlParams.get("page");

    if (currentPage === "aipkit-role-manager") {
      aipkit_initRoleManager();
    }
  });

  window.aipkit_initRoleManager = aipkit_initRoleManager;
})();
