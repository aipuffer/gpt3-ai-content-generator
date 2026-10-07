/**
 * AIPKit Main JS
 *
 * Entry point for the new AIPKit Dashboard. Initializes navigation components
 * and loads the default module.
 */
document.addEventListener("DOMContentLoaded", function () {
  const closeCompactNavigation = () => {
    document
      .querySelectorAll(".aipkit_module-menu[open]")
      .forEach((menu) => menu.removeAttribute("open"));
  };

  const bindModuleOpenTriggers = () => {
    document.addEventListener("click", function (event) {
      const trigger = event.target.closest(
        "[data-aipkit-open-module], .aipkit_module-link[data-module]"
      );
      if (!trigger) {
        return;
      }

      const moduleName =
        trigger.getAttribute("data-aipkit-open-module") ||
        trigger.getAttribute("data-module") ||
        "";

      if (!moduleName || typeof window.aipkit_loadModule !== "function") {
        return;
      }

      if (
        trigger.hasAttribute("disabled") ||
        trigger.getAttribute("aria-disabled") === "true"
      ) {
        event.preventDefault();
        return;
      }

      event.preventDefault();
      if (moduleName === "settings") {
        const settingsPage = String(
          trigger.getAttribute("data-aipkit-settings-page") || ""
        ).trim();
        if (settingsPage) {
          window.__aipkitRequestedSettingsPage = settingsPage;
        }
      }
      closeCompactNavigation();
      window.aipkit_loadModule(moduleName);
    });

    document.addEventListener("click", function (event) {
      if (!event.target.closest(".aipkit_module-menu")) {
        closeCompactNavigation();
      }
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape") {
        closeCompactNavigation();
      }
    });
  };

  bindModuleOpenTriggers();

  const urlParamsCheck = new URLSearchParams(window.location.search);
  const currentPageCheck = urlParamsCheck.get("page");
  const isRoleManagerPageCheck = currentPageCheck === "aipkit-role-manager";

  if (!isRoleManagerPageCheck && typeof window.aipkit_loadModule === "function") {
    const requestedModule = (() => {
      try {
        const params = new URLSearchParams(window.location.search);
        return String(params.get("aipkit_module") || "").trim();
      } catch (error) {
        return "";
      }
    })();

    const modulePriority = [
      "chatbot",
      "content-writer",
      "autogpt",
      "ai-forms",
      "image-generator",
      "sources",
      "stats",
      "settings",
    ];
    const allLinks = Array.from(
      document.querySelectorAll(".aipkit_module-link")
    );
    const isAvailableLink = (link) =>
      !link.hidden &&
      link.getAttribute("aria-hidden") !== "true" &&
      !link.classList.contains("aipkit_module-tab--is-hidden");
    let chosenModule = null;

    if (requestedModule) {
      const requestedLink = allLinks.find(
        (l) =>
          l.getAttribute("data-module") === requestedModule &&
          isAvailableLink(l)
      );
      if (requestedLink) {
        chosenModule = requestedModule;
      }
    }

    if (!chosenModule) {
      for (let i = 0; i < modulePriority.length; i++) {
        const modName = modulePriority[i];
        const link = allLinks.find(
          (l) =>
            l.getAttribute("data-module") === modName && isAvailableLink(l)
        );
        if (link) {
          chosenModule = modName;
          break;
        }
      }
    }

    if (!chosenModule) {
      const visibleLink = allLinks.find(isAvailableLink);
      if (visibleLink) {
        chosenModule = visibleLink.getAttribute("data-module");
      }
    }

    if (chosenModule) {
      if (
        chosenModule === "settings" &&
        !requestedModule &&
        !window.__aipkitRequestedSettingsPage
      ) {
        window.__aipkitRequestedSettingsPage = "modules";
      }
      window.aipkit_loadModule(chosenModule);
    } else {
      const moduleContainer = document.getElementById(
        "aipkit_module-container"
      );
      if (
        moduleContainer &&
        typeof window.aipkit_renderModuleError === "function"
      ) {
        window.aipkit_renderModuleError(
          moduleContainer,
          "initial",
          "No modules enabled or accessible. Please review module access and settings."
        );
      } else if (moduleContainer) {
        moduleContainer.innerHTML =
          '<p style="text-align:center; padding: 30px;">No modules are currently available.</p>';
      }
    }
  } else if (!isRoleManagerPageCheck) {
    console.error(
      "AIPKit Main: Module loader function (aipkit_loadModule) not found."
    );
    const moduleContainer = document.getElementById("aipkit_module-container");
    if (moduleContainer) {
      moduleContainer.innerHTML =
        '<p style="color:red; text-align:center; padding: 30px;">Critical Error: Failed to load dashboard components.</p>';
    }
  }
});

/**
 * Top-bar AI status chip.
 *
 * The server renders the chip (Cloud credits, a connected provider, or Connect AI)
 * and returns fresh markup as `navStatusHtml` from every response that can change
 * the connection; api.js hands that markup to aipkit_applyNavStatus.
 */
function applyNavStatus(html) {
  if (typeof html !== "string") {
    return;
  }
  const current = document.querySelector("[data-aipkit-nav-status]");
  if (!current) {
    return;
  }
  const template = document.createElement("template");
  template.innerHTML = html.trim();
  const next = template.content.querySelector("[data-aipkit-nav-status]");
  if (next) {
    current.replaceWith(next);
  }
}

window.aipkit_applyNavStatus = applyNavStatus;

document.addEventListener("click", (event) => {
  const trigger = event.target.closest("[data-aipkit-nav-connect]");
  if (!trigger || typeof window.aipkit_openProviderConnection !== "function") {
    return;
  }
  event.preventDefault();
  window.aipkit_openProviderConnection(trigger);
});
