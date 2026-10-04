/**
 * AIPKit shared module settings tabs.
 */
(function () {
  "use strict";

  function initModuleSettingsTabs(rootElement) {
    const root = rootElement || document;
    const tabLists = Array.from(
      root.querySelectorAll("[data-aipkit-settings-module-tabs]")
    );

    tabLists.forEach((tabList) => {
      if (tabList.dataset.aipkitSettingsTabsBound === "true") {
        return;
      }

      const scope =
        tabList.closest("[data-aipkit-settings-module-tab-scope]") ||
        tabList.parentElement;
      if (!scope) {
        return;
      }

      const buttons = Array.from(
        tabList.querySelectorAll("[data-aipkit-settings-module-tab]")
      );
      const panels = Array.from(
        scope.querySelectorAll("[data-aipkit-settings-module-tab-panel]")
      );
      if (!buttons.length || !panels.length) {
        return;
      }

      const validTabs = buttons
        .map((button) => button.dataset.aipkitSettingsModuleTab || "")
        .filter(Boolean);

      const activateTab = (tabKey) => {
        const fallbackTab =
          buttons.find((button) => button.classList.contains("aipkit_active"))
            ?.dataset.aipkitSettingsModuleTab ||
          validTabs[0] ||
          "";
        const nextTab = validTabs.includes(tabKey) ? tabKey : fallbackTab;
        if (!nextTab) {
          return;
        }

        buttons.forEach((button) => {
          const isActive = button.dataset.aipkitSettingsModuleTab === nextTab;
          button.classList.toggle("aipkit_active", isActive);
          button.setAttribute("aria-selected", isActive ? "true" : "false");
          button.tabIndex = isActive ? 0 : -1;
        });

        panels.forEach((panel) => {
          panel.hidden = panel.dataset.aipkitSettingsModuleTabPanel !== nextTab;
        });

        tabList.dispatchEvent(
          new CustomEvent("aipkit:module-settings-tab-change", {
            bubbles: true,
            detail: { tab: nextTab },
          })
        );
      };

      tabList.addEventListener("click", (event) => {
        const button = event.target.closest("[data-aipkit-settings-module-tab]");
        if (!button || !tabList.contains(button)) {
          return;
        }
        event.preventDefault();
        activateTab(button.dataset.aipkitSettingsModuleTab || "");
      });

      buttons.forEach((button) => {
        button.addEventListener("keydown", (event) => {
          const currentIndex = buttons.indexOf(button);
          if (currentIndex === -1) {
            return;
          }

          let nextIndex = null;
          if (event.key === "ArrowRight") {
            nextIndex = (currentIndex + 1) % buttons.length;
          } else if (event.key === "ArrowLeft") {
            nextIndex = (currentIndex - 1 + buttons.length) % buttons.length;
          } else if (event.key === "Home") {
            nextIndex = 0;
          } else if (event.key === "End") {
            nextIndex = buttons.length - 1;
          }

          if (nextIndex === null) {
            return;
          }

          event.preventDefault();
          const nextButton = buttons[nextIndex];
          nextButton.focus();
          activateTab(nextButton.dataset.aipkitSettingsModuleTab || "");
        });
      });

      const initiallyActive =
        buttons.find((button) => button.classList.contains("aipkit_active")) ||
        buttons[0];
      activateTab(initiallyActive.dataset.aipkitSettingsModuleTab || "");
      tabList.dataset.aipkitSettingsTabsBound = "true";
    });
  }

  window.aipkit_initModuleSettingsTabs = initModuleSettingsTabs;
})();
