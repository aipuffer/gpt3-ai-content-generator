/**
 * AIPKit UI Utils - Placeholder Copy Handler
 *
 * Handles clicking on placeholder `<code>` tags to copy them to the clipboard.
 */
(function () {
  "use strict";

  /**
   * Shows visual feedback on the clicked element.
   * @param {HTMLElement} element - The element that was clicked.
   */
  function showCopyFeedback(element) {
    const originalText = element.textContent;
    element.textContent = "Copied!";
    element.classList.add("aipkit-placeholder--copied");

    setTimeout(() => {
      // Check if the element still exists and has the 'copied' class before reverting
      if (element && element.classList.contains("aipkit-placeholder--copied")) {
        element.textContent = originalText;
        element.classList.remove("aipkit-placeholder--copied");
      }
    }, 1500);
  }

  function copyPlaceholder(event) {
    const placeholder = event.target;

    if (!placeholder.matches(".aipkit-placeholder")) {
      return;
    }

    event.preventDefault();
    event.stopPropagation();

    const textToCopy = placeholder.textContent;

    if (!navigator.clipboard) {
      console.warn("AIPKit Placeholder Copy: Clipboard API not available.");
      return;
    }

    navigator.clipboard
      .writeText(textToCopy)
      .then(() => {
        showCopyFeedback(placeholder);
      })
      .catch((err) => {
        console.error(
          "AIPKit Placeholder Copy: Failed to copy placeholder. Error:",
          err
        );
      });
  }

  /**
   * Handles pointer activation of placeholder chips.
   * @param {MouseEvent} event
   */
  function handlePlaceholderClick(event) {
    copyPlaceholder(event);
  }

  /**
   * Gives placeholder chips native button-like keyboard behavior.
   * @param {KeyboardEvent} event
   */
  function handlePlaceholderKeydown(event) {
    if (event.key !== "Enter" && event.key !== " ") {
      return;
    }
    copyPlaceholder(event);
  }

  /**
   * Attaches a single delegated event listener to the document body.
   */
  function initPlaceholderCopy() {
    const container = document.body;
    if (container) {
      const listenerAttr = "data-placeholder-copy-listener-attached";
      if (!container.getAttribute(listenerAttr)) {
        container.addEventListener("click", handlePlaceholderClick);
        container.addEventListener("keydown", handlePlaceholderKeydown);
        container.setAttribute(listenerAttr, "true");
      }
    } else {
      console.warn(
        "AIPKit Placeholder Copy: Could not find document.body to attach delegated listener."
      );
    }
  }

  // Run the initializer on DOM load
  document.addEventListener("DOMContentLoaded", initPlaceholderCopy);
})();
