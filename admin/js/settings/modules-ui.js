/**
 * AIPKit Settings - Module Visibility
 */
(function () {
  "use strict";

  var MESSAGE_CONTAINER_ID = "aipkit_settings_global_messages";

  function getTopNavModuleLinks(moduleSlug) {
    if (!moduleSlug) {
      return [];
    }

    return document.querySelectorAll(
      '.aipkit_module-link[data-option-key][data-module="' +
        moduleSlug +
        '"]'
    );
  }

  function isTopNavModuleVisible(navLink) {
    return (
      navLink &&
      !navLink.hidden &&
      !navLink.classList.contains("aipkit_module-tab--is-hidden") &&
      navLink.getAttribute("aria-hidden") !== "true"
    );
  }

  function getLinkLabel(link, fallbackLabel) {
    var title = link ? String(link.getAttribute("title") || "").trim() : "";
    if (title) {
      return title;
    }

    var text = link ? String(link.textContent || "").trim() : "";
    return text || fallbackLabel;
  }

  function syncTopNavBrandTarget(topNav, targetLink) {
    var brandTargets = topNav.querySelectorAll(".aipkit_module-brand_home");
    if (!brandTargets.length) {
      return;
    }

    if (!targetLink) {
      Array.prototype.forEach.call(brandTargets, function (brandTarget) {
        brandTarget.removeAttribute("data-module");
        brandTarget.removeAttribute("data-aipkit-open-module");
        brandTarget.removeAttribute("data-aipkit-settings-page");
        brandTarget.setAttribute("title", "AI Puffer");
        brandTarget.setAttribute("aria-label", "AI Puffer");
      });
      return;
    }

    var targetModule =
      targetLink.getAttribute("data-aipkit-open-module") ||
      targetLink.getAttribute("data-module") ||
      "";
    if (!targetModule) {
      return;
    }

    var label = getLinkLabel(
      targetLink,
      targetModule === "settings" ? "Settings" : "AI Puffer"
    );

    Array.prototype.forEach.call(brandTargets, function (brandTarget) {
      brandTarget.setAttribute("data-module", targetModule);
      brandTarget.setAttribute("data-aipkit-open-module", targetModule);

      if (targetModule === "settings") {
        brandTarget.setAttribute(
          "data-aipkit-settings-page",
          targetLink.getAttribute("data-aipkit-settings-page") || "modules"
        );
      } else {
        brandTarget.removeAttribute("data-aipkit-settings-page");
      }

      brandTarget.setAttribute("title", label);
      brandTarget.setAttribute("aria-label", label);
    });
  }

  function syncTopNavModulesState() {
    var topNav = document.querySelector(".aipkit_module-tabs");
    if (!topNav) {
      return;
    }

    var navList = topNav.querySelector(".aipkit_module-tabs_list");
    var moduleLinks = topNav.querySelectorAll(".aipkit_module-tab--module");
    var utilityLinks = topNav.querySelectorAll(".aipkit_module-tab--utility");
    var visibleModuleLink = Array.prototype.find.call(
      moduleLinks,
      isTopNavModuleVisible
    );
    var visibleUtilityLink = Array.prototype.find.call(
      utilityLinks,
      isTopNavModuleVisible
    );
    var hasVisibleModule = Array.prototype.some.call(
      moduleLinks,
      isTopNavModuleVisible
    );

    topNav.classList.toggle(
      "aipkit_module-tabs--modules-empty",
      !hasVisibleModule
    );

    if (navList) {
      if (hasVisibleModule) {
        navList.removeAttribute("aria-hidden");
      } else {
        navList.setAttribute("aria-hidden", "true");
      }
    }

    syncTopNavBrandTarget(
      topNav,
      visibleModuleLink ||
        visibleUtilityLink ||
        topNav.querySelector(
          '.aipkit_module-tab--settings.aipkit_module-link[data-module="settings"]'
        )
    );
  }

  function setTopNavModuleVisibility(moduleSlug, isVisible) {
    var navLinks = getTopNavModuleLinks(moduleSlug);
    if (!navLinks.length) {
      syncTopNavModulesState();
      return;
    }

    Array.prototype.forEach.call(navLinks, function (navLink) {
      if (isVisible) {
        navLink.hidden = false;
        navLink.removeAttribute("aria-hidden");
        navLink.removeAttribute("tabindex");
        navLink.classList.remove("aipkit_module-tab--is-hidden");
      } else {
        navLink.hidden = true;
        navLink.setAttribute("aria-hidden", "true");
        navLink.setAttribute("tabindex", "-1");
        navLink.classList.add("aipkit_module-tab--is-hidden");
        navLink.classList.remove("aipkit_active");
      }
    });

    syncTopNavModulesState();
  }

  function setAutosaveBusy(isBusy) {
    if (typeof window.aipkit_setSettingsAutosaveBusy === "function") {
      window.aipkit_setSettingsAutosaveBusy(isBusy, document.querySelector('.aipkit_settings_scope[data-aipkit-settings-page="modules"]'));
    }
  }

  function invalidateModuleCaches(moduleSlugs) {
    if (typeof window.aipkit_invalidateModuleCache !== "function") {
      return;
    }

    moduleSlugs
      .filter(function (moduleSlug, index, list) {
        return moduleSlug && list.indexOf(moduleSlug) === index;
      })
      .forEach(function (moduleSlug) {
        window.aipkit_invalidateModuleCache(moduleSlug);
      });
  }

  function clearMessages() {
    if (typeof window.aipkit_clearStatusMessages === "function") {
      window.aipkit_clearStatusMessages(MESSAGE_CONTAINER_ID);
    }
  }

  function showError(message) {
    if (typeof window.aipkit_showMessage === "function") {
      window.aipkit_showMessage(MESSAGE_CONTAINER_ID, "error", message);
      return;
    }

    console.error(message);
  }

  function saveIndexButtonSetting(container, input) {
    if (
      !container ||
      !input ||
      !container.dataset.indexingNonce ||
      typeof window.aipkit_apiRequest !== "function"
    ) {
      return;
    }

    var nextValue = input.checked ? "1" : "0";
    var savedValue = input.dataset.savedValue || "";
    if (nextValue === savedValue) {
      return;
    }

    clearMessages();
    input.disabled = true;
    setAutosaveBusy(true);

    window
      .aipkit_apiRequest("aipkit_save_cpt_indexing_options", {
        _ajax_nonce: container.dataset.indexingNonce,
        settings: JSON.stringify({
          show_index_button: nextValue === "1",
        }),
      })
      .then(function () {
        input.dataset.savedValue = nextValue;
      })
      .catch(function (error) {
        input.checked = savedValue === "1";
        showError(
          error && error.message
            ? error.message
            : "Unable to save the Add to knowledge base setting."
        );
        console.error("AIPKit content indexing setting save error:", error);
      })
      .finally(function () {
        setAutosaveBusy(false);
        input.disabled = false;
      });
  }

  function initSettingsModulesUI() {
    var container = document.getElementById("aipkit_settings_modules");
    syncTopNavModulesState();

    if (!container) {
      return;
    }

    container
      .querySelectorAll(".aipkit_settings_module_toggle_input")
      .forEach(function (input) {
        if (input.dataset.aipkitModuleToggleBound === "true") {
          return;
        }

        input.addEventListener("change", function () {
          var optionKey = input.getAttribute("data-option-key") || "";
          var moduleSlug = input.getAttribute("data-module") || "";
          var isEnabled = !!input.checked;
          var previousEnabled = !isEnabled;

          setTopNavModuleVisibility(moduleSlug, isEnabled);
          clearMessages();

          if (!optionKey || typeof window.aipkit_apiRequest !== "function") {
            input.checked = previousEnabled;
            setTopNavModuleVisibility(moduleSlug, previousEnabled);
            showError("Unable to save module setting.");
            return;
          }

          invalidateModuleCaches(["settings", moduleSlug]);

          input.disabled = true;
          var billingToggle =
            optionKey === "stats_viewer"
              ? container.querySelector("#aipkit_settings_visitor_billing_toggle")
              : null;
          if (billingToggle) {
            billingToggle.disabled = true;
          }
          setAutosaveBusy(true);

          window
            .aipkit_apiRequest("aipkit_update_module_setting", {
              moduleKey: optionKey,
              enabled: isEnabled ? "1" : "0",
            })
            .then(function () {
              if (billingToggle && !isEnabled) {
                billingToggle.checked = false;
              }
            })
            .catch(function () {
              input.checked = previousEnabled;
              setTopNavModuleVisibility(moduleSlug, previousEnabled);
              showError("Unable to save module setting.");

              invalidateModuleCaches(["settings", moduleSlug]);
            })
            .finally(function () {
              setAutosaveBusy(false);
              input.disabled = false;
              if (billingToggle) {
                billingToggle.disabled = false;
              }
            });
        });

        input.dataset.aipkitModuleToggleBound = "true";
      });

    container
      .querySelectorAll(".aipkit_settings_index_button_toggle")
      .forEach(function (input) {
        if (input.dataset.aipkitIndexButtonToggleBound === "true") {
          return;
        }

        input.addEventListener("change", function () {
          saveIndexButtonSetting(container, input);
        });

        input.dataset.aipkitIndexButtonToggleBound = "true";
      });

    var billingToggle = container.querySelector("#aipkit_settings_visitor_billing_toggle");
    if (billingToggle && !billingToggle.dataset.aipkitBound) {
      billingToggle.addEventListener("change", function () {
        var enabled = billingToggle.checked;
        var usageToggle = container.querySelector(
          '.aipkit_settings_module_toggle_input[data-option-key="stats_viewer"]'
        );
        clearMessages();
        billingToggle.disabled = true;
        if (usageToggle) {
          usageToggle.disabled = true;
        }
        setAutosaveBusy(true);
        window.aipkit_apiRequest("aipkit_set_visitor_billing", {
          nonce: container.dataset.visitorBillingNonce || "",
          enabled: enabled ? "1" : "0",
        })
          .then(function () {
            if (enabled) {
              if (usageToggle) {
                usageToggle.checked = true;
              }
              setTopNavModuleVisibility("stats", true);
            }
            invalidateModuleCaches(["settings", "stats"]);
          })
          .catch(function (error) {
            billingToggle.checked = !enabled;
            showError(error?.message || "Unable to save visitor billing visibility.");
          })
          .finally(function () {
            setAutosaveBusy(false);
            billingToggle.disabled = false;
            if (usageToggle) {
              usageToggle.disabled = false;
            }
          });
      });
      billingToggle.dataset.aipkitBound = "true";
    }
  }

  window.aipkit_initSettingsModulesUI = initSettingsModulesUI;
})();
