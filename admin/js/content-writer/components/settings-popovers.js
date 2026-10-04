/**
 * AIPKit Content Writer - Settings Popovers
 * Handles row-based popovers in the left settings card.
 */
import { positionSettingsPopover } from "../../utils/ui/popover-position.js";

(function () {
  "use strict";

  function aipkit_initContentWriterPopovers(scopeElement) {
    if (!scopeElement) return;

    if (typeof window.__aipkitCleanupContentWriterPopovers === "function") {
      window.__aipkitCleanupContentWriterPopovers();
      window.__aipkitCleanupContentWriterPopovers = null;
    }

    const triggers = scopeElement.querySelectorAll(
      "[data-aipkit-popover-target]"
    );
    if (!triggers.length) return;

    let activePopover = null;
    let activeTrigger = null;
    let repositionScheduled = false;

    const getPopover = (id) =>
      id ? scopeElement.querySelector(`#${id}`) : null;

    const scheduleReposition = () => {
      if (!activeTrigger || !activePopover) return;
      if (repositionScheduled) return;
      repositionScheduled = true;
      window.requestAnimationFrame(() => {
        const panel = activePopover.querySelector(
          ".aipkit_model_settings_popover_panel"
        );
        positionSettingsPopover(activeTrigger, panel);
        repositionScheduled = false;
      });
    };

    const closePopover = () => {
      if (!activePopover) return;
      activePopover.classList.remove("aipkit-active");
      activePopover.setAttribute("aria-hidden", "true");
      if (activeTrigger) {
        activeTrigger.setAttribute("aria-expanded", "false");
      }
      activePopover = null;
      activeTrigger = null;
    };

    const handleEscapeKeydown = (event) => {
      if (event.key === "Escape" && activePopover) {
        closePopover();
      }
    };

    let resizeRafId = 0;

    const handleViewportResize = () => {
      if (!activePopover) return;
      if (resizeRafId) return;
      resizeRafId = requestAnimationFrame(() => {
        resizeRafId = 0;
        if (activePopover) {
          scheduleReposition();
        }
      });
    };

    const handleViewportScroll = () => {
      scheduleReposition();
    };

    const openPopover = (trigger) => {
      if (!trigger) return;

      const targetId = trigger.getAttribute("data-aipkit-popover-target");
      const popover = getPopover(targetId);
      if (!popover) return;

      if (activePopover && activePopover !== popover) {
        closePopover();
      }

      activePopover = popover;
      activeTrigger = trigger;
      popover.classList.add("aipkit-active");
      popover.setAttribute("aria-hidden", "false");
      trigger.setAttribute("aria-expanded", "true");

      if (typeof window.aipkit_attachRangeValueHandlers === "function") {
        window.aipkit_attachRangeValueHandlers(`#${targetId}`);
      }

      const panel = popover.querySelector(".aipkit_model_settings_popover_panel");
      window.requestAnimationFrame(() => {
        positionSettingsPopover(trigger, panel);
      });
    };

    triggers.forEach((button) => {
      if (button.dataset.popoverListenerAttached === "true") return;
      button.addEventListener("click", (event) => {
        event.preventDefault();
        const keepOpen = button.dataset.aipkitPopoverKeepOpen === "true";
        if (
          !keepOpen &&
          activePopover &&
          activeTrigger === button &&
          activePopover.classList.contains("aipkit-active")
        ) {
          closePopover();
          return;
        }
        openPopover(button);
      });
      button.dataset.popoverListenerAttached = "true";
    });

    scopeElement
      .querySelectorAll(".aipkit_model_settings_popover")
      .forEach((popover) => {
        if (popover.dataset.overlayListenerAttached !== "true") {
          popover.addEventListener("click", (event) => {
            if (event.target === popover) {
              closePopover();
            }
          });
          popover.dataset.overlayListenerAttached = "true";
        }

        const closeButton = popover.querySelector(
          ".aipkit_model_settings_popover_close"
        );
        if (closeButton && closeButton.dataset.popoverListenerAttached !== "true") {
          closeButton.addEventListener("click", (event) => {
            event.preventDefault();
            closePopover();
          });
          closeButton.dataset.popoverListenerAttached = "true";
        }

        popover.querySelectorAll("[data-aipkit-popover-close]").forEach((button) => {
          if (button.dataset.popoverListenerAttached === "true") return;
          button.addEventListener("click", (event) => {
            event.preventDefault();
            closePopover();
          });
          button.dataset.popoverListenerAttached = "true";
        });
      });

    document.addEventListener("keydown", handleEscapeKeydown);
    window.addEventListener("resize", handleViewportResize);
    window.addEventListener("scroll", handleViewportScroll, true);

    window.__aipkitCleanupContentWriterPopovers = () => {
      closePopover();
      if (resizeRafId) {
        cancelAnimationFrame(resizeRafId);
        resizeRafId = 0;
      }
      document.removeEventListener("keydown", handleEscapeKeydown);
      window.removeEventListener("resize", handleViewportResize);
      window.removeEventListener("scroll", handleViewportScroll, true);
    };
  }

  window.aipkit_initContentWriterPopovers =
    aipkit_initContentWriterPopovers;
})();
