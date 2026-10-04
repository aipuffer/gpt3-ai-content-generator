/**
 * AIPKit Settings - Section Navigation
 * Handles tab navigation between settings sections.
 */
(function () {
  "use strict";

  function aipkit_initSettingsPageNav() {
    const settingsContainer = document.getElementById("aipkit_settings_container");
    if (!settingsContainer) {
      return;
    }

    const navLinks = settingsContainer.querySelectorAll(
      "[data-aipkit-settings-page-link]"
    );
    const sections = settingsContainer.querySelectorAll("[data-aipkit-settings-page]");
    if (!navLinks.length || !sections.length) {
      return;
    }

    const availablePages = new Set(
      Array.from(sections).map((section) => section.dataset.aipkitSettingsPage || "")
    );
    const defaultPage = availablePages.has("ai")
      ? "ai"
      : Array.from(availablePages)[0] || "";
    const requestedPage = (() => {
      if (window.__aipkitRequestedSettingsPage) {
        return String(window.__aipkitRequestedSettingsPage || "").trim();
      }

      try {
        const searchParams = new URLSearchParams(window.location.search || "");
        return String(searchParams.get("aipkit_settings_page") || "").trim();
      } catch (error) {
        return "";
      }
    })();

    const setActivePage = (page) => {
      const pageKey = availablePages.has(page) ? page : defaultPage;
      if (!pageKey) {
        return;
      }

      navLinks.forEach((link) => {
        const isActive = link.dataset.aipkitSettingsPageLink === pageKey;
        link.classList.toggle("is-active", isActive);
        link.classList.toggle("aipkit_active", isActive);
        link.setAttribute("aria-selected", isActive ? "true" : "false");
        link.setAttribute("tabindex", isActive ? "0" : "-1");
      });

      sections.forEach((section) => {
        const isActive = section.dataset.aipkitSettingsPage === pageKey;
        section.hidden = !isActive;
      });

      if (
        (pageKey === "ai" ||
          pageKey === "integrations" ||
          pageKey === "apps") &&
        typeof window.aipkit_refreshSettingsSelectPickers === "function"
      ) {
        window.aipkit_refreshSettingsSelectPickers();
      }

      if (typeof window.aipkit_updateLastSavedData === "function") {
        window.aipkit_updateLastSavedData(true);
      }
    };

    navLinks.forEach((link) => {
      if (link.dataset.aipkitSettingsPageNavBound === "true") {
        return;
      }

      link.addEventListener("click", (event) => {
        event.preventDefault();
        setActivePage(link.dataset.aipkitSettingsPageLink || defaultPage);
      });

      link.addEventListener("keydown", (event) => {
        const keys = [
          "ArrowRight",
          "ArrowDown",
          "ArrowLeft",
          "ArrowUp",
          "Home",
          "End",
        ];
        if (!keys.includes(event.key)) {
          return;
        }

        event.preventDefault();

        const tabs = Array.from(navLinks);
        const currentIndex = tabs.indexOf(link);
        let nextIndex = currentIndex;

        if (event.key === "Home") {
          nextIndex = 0;
        } else if (event.key === "End") {
          nextIndex = tabs.length - 1;
        } else if (event.key === "ArrowLeft" || event.key === "ArrowUp") {
          nextIndex = currentIndex <= 0 ? tabs.length - 1 : currentIndex - 1;
        } else {
          nextIndex = currentIndex >= tabs.length - 1 ? 0 : currentIndex + 1;
        }

        const nextTab = tabs[nextIndex];
        if (!nextTab) {
          return;
        }

        setActivePage(nextTab.dataset.aipkitSettingsPageLink || defaultPage);
        nextTab.focus();
      });

      link.dataset.aipkitSettingsPageNavBound = "true";
    });

    setActivePage(requestedPage || defaultPage);
    window.__aipkitRequestedSettingsPage = "";

    const hash = String(window.location.hash || "").trim();
    if (hash) {
      const hashTarget = document.querySelector(hash);
      if (hashTarget) {
        window.requestAnimationFrame(() => {
          hashTarget.scrollIntoView({ block: "start" });
        });
      }
    }
  }

  window.aipkit_initSettingsPageNav = aipkit_initSettingsPageNav;
})();
