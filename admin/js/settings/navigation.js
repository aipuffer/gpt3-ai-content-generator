/**
 * AIPKit Settings - Section Navigation
 * The side menu shows one section at a time.
 */
(function () {
  "use strict";

  // Older links name a settings tab; each now lives inside one of the sections.
  const PAGE_SECTIONS = {
    ai: "ai",
    modules: "tools",
    integrations: "connections",
    apps: "connections",
    "stock-photos": "connections",
    security: "safety",
    others: "safety",
    backups: "safety",
    api: "developers",
  };
  let hashChangeHandler = null;

  const getScrollOffset = () => {
    const adminBar = document.getElementById("wpadminbar");
    const fixedBar = adminBar && window.getComputedStyle(adminBar).position === "fixed";
    return (fixedBar ? adminBar.offsetHeight : 0) + 16;
  };

  const scrollToTop = (target, onlyIfAbove) => {
    const top = target.getBoundingClientRect().top + window.scrollY - getScrollOffset();
    if (!onlyIfAbove || window.scrollY > top) {
      window.scrollTo({ top: Math.max(0, top), behavior: "auto" });
    }
  };

  function aipkit_initSettingsPageNav() {
    const settingsContainer = document.getElementById("aipkit_settings_container");
    if (!settingsContainer) {
      return;
    }

    const links = Array.from(settingsContainer.querySelectorAll("[data-aipkit-settings-jump]"));
    const sections = Array.from(settingsContainer.querySelectorAll("[data-aipkit-settings-section]"));
    if (!links.length || !sections.length) {
      return;
    }

    const showSection = (key) => {
      const section = sections.find((candidate) => candidate.dataset.aipkitSettingsSection === key) || sections[0];
      const sectionKey = section.dataset.aipkitSettingsSection;
      sections.forEach((candidate) => {
        candidate.hidden = candidate !== section;
      });
      links.forEach((link) => {
        const isActive = link.dataset.aipkitSettingsJump === sectionKey;
        link.classList.toggle("is-active", isActive);
        if (isActive) {
          link.setAttribute("aria-current", "page");
        } else {
          link.removeAttribute("aria-current");
        }
      });
      if (typeof window.aipkit_refreshSettingsSelectPickers === "function") {
        window.aipkit_refreshSettingsSelectPickers();
      }
      return section;
    };

    links.forEach((link) => {
      if (link.dataset.aipkitSettingsJumpBound === "true") {
        return;
      }
      link.addEventListener("click", (event) => {
        event.preventDefault();
        showSection(link.dataset.aipkitSettingsJump || "");
        scrollToTop(settingsContainer, true);
      });
      link.dataset.aipkitSettingsJumpBound = "true";
    });

    const requestedPage = (() => {
      if (window.__aipkitRequestedSettingsPage) {
        return String(window.__aipkitRequestedSettingsPage || "").trim();
      }
      try {
        return String(new URLSearchParams(window.location.search || "").get("aipkit_settings_page") || "").trim();
      } catch (error) {
        return "";
      }
    })();
    window.__aipkitRequestedSettingsPage = "";

    const navigateToRequestedSection = () => {
      const hashTarget = (() => {
        const hash = String(window.location.hash || "").trim();
        try {
          const selector = hash === "#aipkit_settings_section_backups" ? "#aipkit_settings_backups" : hash;
          return selector ? settingsContainer.querySelector(selector) : null;
        } catch (error) {
          return null;
        }
      })();
      const requestedScope = requestedPage
        ? settingsContainer.querySelector(`.aipkit_settings_scope[data-aipkit-settings-page="${CSS.escape(requestedPage === "backups" ? "others" : requestedPage)}"]`)
        : null;
      const target = hashTarget || requestedScope;
      const section = showSection(
        target?.closest("[data-aipkit-settings-section]")?.dataset.aipkitSettingsSection
          || PAGE_SECTIONS[requestedPage]
          || requestedPage
          || "ai"
      );

      if (typeof window.aipkit_initSettingsSavedData === "function") {
        window.aipkit_initSettingsSavedData();
      }

      // Links to groups such as Apps and Backups land on the group within its section.
      if (target && target !== section && section.querySelector(".aipkit_settings_scope") !== target) {
        window.requestAnimationFrame(() => scrollToTop(target, false));
      }
    };
    navigateToRequestedSection();
    if (hashChangeHandler) {
      window.removeEventListener("hashchange", hashChangeHandler);
    }
    hashChangeHandler = navigateToRequestedSection;
    window.addEventListener("hashchange", hashChangeHandler);
  }

  window.aipkit_initSettingsPageNav = aipkit_initSettingsPageNav;
})();
