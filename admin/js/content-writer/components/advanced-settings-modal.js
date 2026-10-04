/**
 * Content Writer advanced model settings modal.
 */
(function () {
  "use strict";

  function getModal(container) {
    return (
      container?.querySelector("[data-aipkit-advanced-options-modal]") || null
    );
  }

  function getFocusableElements(modal) {
    return Array.from(
      modal?.querySelectorAll(
        'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
      ) || []
    ).filter(
      (element) =>
        !element.hidden &&
        element.getAttribute("aria-hidden") !== "true" &&
        element.offsetParent !== null
    );
  }

  function initContentWriterAdvancedSettingsModal(container) {
    if (!container || container.dataset.advancedSettingsModalAttached === "true") {
      return;
    }

    const modal = getModal(container);
    const trigger = container.querySelector(
      "[data-aipkit-advanced-options-trigger]"
    );
    if (!modal || !trigger) {
      return;
    }

    let returnFocus = null;

    const closeModal = (options = {}) => {
      if (!modal.classList.contains("aipkit-active")) {
        return;
      }

      modal.classList.remove("aipkit-active");
      modal.setAttribute("aria-hidden", "true");
      trigger.setAttribute("aria-expanded", "false");

      if (options.returnFocus !== false && returnFocus?.isConnected) {
        returnFocus.focus();
      }
      returnFocus = null;
    };

    const openModal = () => {
      if (modal.classList.contains("aipkit-active")) {
        return;
      }

      returnFocus = document.activeElement;
      modal.classList.add("aipkit-active");
      modal.setAttribute("aria-hidden", "false");
      trigger.setAttribute("aria-expanded", "true");

      window.setTimeout(() => {
        getFocusableElements(modal)[0]?.focus();
      }, 0);
    };

    container.addEventListener("click", (event) => {
      const openButton = event.target.closest(
        "[data-aipkit-advanced-options-trigger]"
      );
      if (openButton && container.contains(openButton)) {
        event.preventDefault();
        openModal();
        return;
      }

      const closeButton = event.target.closest(
        "[data-aipkit-advanced-options-close]"
      );
      if (closeButton && container.contains(closeButton)) {
        event.preventDefault();
        closeModal();
        return;
      }

      if (event.target === modal) {
        closeModal();
      }
    });

    container.addEventListener(
      "invalid",
      (event) => {
        if (event.target.closest("[data-aipkit-advanced-options-modal]")) {
          openModal();
        }
      },
      true
    );

    document.addEventListener("keydown", (event) => {
      if (!modal.classList.contains("aipkit-active")) {
        return;
      }

      if (event.key === "Escape") {
        event.preventDefault();
        closeModal();
        return;
      }

      if (event.key !== "Tab") {
        return;
      }

      const focusableElements = getFocusableElements(modal);
      if (!focusableElements.length) {
        event.preventDefault();
        return;
      }

      const firstElement = focusableElements[0];
      const lastElement = focusableElements[focusableElements.length - 1];
      if (event.shiftKey && document.activeElement === firstElement) {
        event.preventDefault();
        lastElement.focus();
      } else if (!event.shiftKey && document.activeElement === lastElement) {
        event.preventDefault();
        firstElement.focus();
      }
    });

    container.dataset.advancedSettingsModalAttached = "true";
  }

  window.aipkit_initContentWriterAdvancedSettingsModal =
    initContentWriterAdvancedSettingsModal;
})();
