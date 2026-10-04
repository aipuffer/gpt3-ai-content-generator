/**
 * AIPKit Content Writer - Generate Action Menu
 * Handles the Content Writer split-button actions.
 */
(function () {
  "use strict";

  const getActiveMode = () => {
    const modeSelect = document.getElementById("aipkit_cw_mode_select");
    const rawMode = modeSelect ? modeSelect.value : "task";
    return rawMode === "single" ? "task" : rawMode;
  };

  const shouldSkipIntervalsForMode = () => {
    const mode = getActiveMode();
    return mode === "task" || mode === "csv";
  };

  const ensureOneTimeFrequency = (refs) => {
    if (!refs?.frequencySelect) return;
    const hasOneTime = refs.frequencySelect.querySelector(
      'option[value="one-time"]'
    );
    if (!hasOneTime) return;
    if (refs.frequencySelect.value !== "one-time") {
      refs.frequencySelect.value = "one-time";
      refs.frequencySelect.dispatchEvent(new Event("change", { bubbles: true }));
    }
  };

  const getRefs = () => {
    const container = document.getElementById("aipkit_content_writer_container");
    if (!container) return null;
    const actionShell = container.querySelector(".aipkit_cw_action_shell");
    if (!actionShell) return null;
    const generateBtn = actionShell.querySelector(
      "#aipkit_content_writer_generate_btn"
    );
    const toggleBtn = actionShell.querySelector(".aipkit_cw_action_disclosure");
    const menu = actionShell.querySelector(".aipkit_cw_action_menu");
    const menuActions = menu
      ? menu.querySelector('[data-menu-panel="actions"]')
      : null;
    const menuIntervals = menu
      ? menu.querySelector('[data-menu-panel="intervals"]')
      : null;
    const actionItems = menuActions
      ? Array.from(
          menuActions.querySelectorAll(".aipkit_cw_action_menu_option[data-action]")
        )
      : [];
    const intervalItems = menuIntervals
      ? Array.from(
          menuIntervals.querySelectorAll(".aipkit_cw_action_menu_option[data-interval]")
        )
      : [];
    const backBtn = menuIntervals
      ? menuIntervals.querySelector(".aipkit_cw_action_menu_back[data-menu-back]")
      : null;
    const frequencySelect = container.querySelector("#aipkit_cw_task_frequency");
    return {
      container,
      actionShell,
      generateBtn,
      toggleBtn,
      menu,
      menuActions,
      menuIntervals,
      actionItems,
      intervalItems,
      backBtn,
      frequencySelect,
    };
  };

  const closeMenu = (refs) => {
    if (!refs?.menu || !refs?.toggleBtn) return;
    refs.menu.hidden = true;
    refs.toggleBtn.setAttribute("aria-expanded", "false");
  };

  const positionMenu = (refs) => {
    if (!refs?.menu || !refs?.actionShell) return;
    const menu = refs.menu;
    const shellRect = refs.actionShell.getBoundingClientRect();
    const viewportWidth = window.innerWidth;
    const viewportHeight = window.innerHeight;
    const gutter = 12;
    const offset = 8;

    menu.style.position = "fixed";
    menu.style.right = "auto";
    menu.style.bottom = "auto";
    menu.style.zIndex = "100000";
    menu.style.visibility = "hidden";
    menu.style.maxHeight = "";
    menu.style.overflowY = "";

    const menuRect = menu.getBoundingClientRect();
    let left = shellRect.right - menuRect.width;
    left = Math.max(gutter, Math.min(left, viewportWidth - menuRect.width - gutter));
    const availableBelow = viewportHeight - shellRect.bottom - gutter - offset;
    const availableAbove = shellRect.top - gutter - offset;
    const openAbove =
      menuRect.height > availableBelow && availableAbove > availableBelow;
    const availableHeight = Math.max(
      120,
      openAbove ? availableAbove : availableBelow
    );
    const top = openAbove
      ? Math.max(gutter, shellRect.top - Math.min(menuRect.height, availableHeight) - offset)
      : Math.min(
          shellRect.bottom + offset,
          viewportHeight - Math.min(menuRect.height, availableHeight) - gutter
        );

    if (menuRect.height > availableHeight) {
      menu.style.maxHeight = `${Math.floor(availableHeight)}px`;
      menu.style.overflowY = "auto";
    }

    menu.style.left = `${Math.round(left)}px`;
    menu.style.top = `${Math.round(top)}px`;
    menu.dataset.placement = openAbove ? "top" : "bottom";
    menu.style.visibility = "visible";
  };

  const openMenu = (refs) => {
    if (!refs?.menu || !refs?.toggleBtn) return;
    refs.menu.hidden = false;
    positionMenu(refs);
    refs.toggleBtn.setAttribute("aria-expanded", "true");
  };

  const showMenuPanel = (refs, panel) => {
    if (!refs?.menuActions || !refs?.menuIntervals) return;
    const showIntervals = panel === "intervals";
    refs.menuActions.hidden = showIntervals;
    refs.menuIntervals.hidden = !showIntervals;
  };

  const executeAction = (refs, action) => {
    if (!refs?.generateBtn) return;
    showMenuPanel(refs, "actions");
    closeMenu(refs);
    refs.generateBtn.dataset.aipkitRequestedAction = action || "generate";
    refs.generateBtn.click();
  };

  const applyInterval = (refs, value) => {
    if (!refs?.frequencySelect) return;
    refs.frequencySelect.value = value;
    refs.frequencySelect.dispatchEvent(new Event("change", { bubbles: true }));
    executeAction(refs, "create_task");
  };

  const applyAction = (action) => {
    const refs = getRefs();
    if (!refs) return;
    const nextAction = action || "generate";
    const skipIntervals =
      nextAction === "create_task" && shouldSkipIntervalsForMode();
    if (skipIntervals) {
      ensureOneTimeFrequency(refs);
    }
    if (nextAction === "create_task" && refs.menuIntervals && !skipIntervals) {
      showMenuPanel(refs, "intervals");
      openMenu(refs);
      return;
    }
    executeAction(refs, nextAction);
  };

  const initMenu = () => {
    const refs = getRefs();
    if (!refs) return;
    if (refs.actionShell.dataset.listenerAttached === "true") return;

    if (refs.toggleBtn) {
      refs.toggleBtn.addEventListener("click", (event) => {
        event.preventDefault();
        if (refs.toggleBtn.disabled) return;
        showMenuPanel(refs, "actions");
        if (refs.menu && refs.menu.hidden) {
          openMenu(refs);
        } else {
          closeMenu(refs);
        }
      });
    }

    refs.actionItems.forEach((item) => {
      item.addEventListener("click", (event) => {
        event.preventDefault();
        if (item.disabled) return;
        applyAction(item.dataset.action || "generate");
      });
    });

    refs.intervalItems.forEach((item) => {
      item.addEventListener("click", (event) => {
        event.preventDefault();
        if (item.disabled) return;
        applyInterval(refs, item.dataset.interval || "");
      });
    });

    if (refs.backBtn) {
      refs.backBtn.addEventListener("click", (event) => {
        event.preventDefault();
        showMenuPanel(refs, "actions");
        openMenu(refs);
      });
    }

    document.addEventListener("click", (event) => {
      const target = event.target;
      if (!refs.menu || refs.menu.hidden) return;
      if (refs.actionShell.contains(target)) return;
      closeMenu(refs);
    });

    document.addEventListener("keydown", (event) => {
      if (event.key !== "Escape") return;
      if (!refs.menu || refs.menu.hidden) return;
      closeMenu(refs);
    });

    window.addEventListener(
      "scroll",
      () => {
        if (!refs.menu || refs.menu.hidden) return;
        closeMenu(refs);
      },
      true
    );

    window.addEventListener("resize", () => {
      if (!refs.menu || refs.menu.hidden) return;
      positionMenu(refs);
    });

    refs.actionShell.dataset.listenerAttached = "true";
  };

  window.aipkit_initContentWriterGenerateMenu = initMenu;
})();
