(function () {
  "use strict";

  function showAssistantWarning(message, title, texts) {
    if (typeof window.aipkit_showConfirmModal !== "function") {
      console.error(`AIPKit Post Enhancer: ${message}`);
      return;
    }

    window.aipkit_showConfirmModal(message, {
      title,
      confirmText: texts.ok || "OK",
      variant: "warning",
      hideCancel: true,
    });
  }

  function dismissAssistantPopover(menuItem) {
    const container = menuItem.closest(".aipkit_enhancer_action");
    if (!container) return;

    container.classList.remove("aipkit-open");
    container.classList.add("aipkit-dismissed");

    const focusedElement = document.activeElement;
    if (
      focusedElement instanceof HTMLElement &&
      container.contains(focusedElement)
    ) {
      focusedElement.blur();
    }
  }

  /**
   * The main click event handler for all post enhancer actions.
   * @param {Event} event The click event object.
   */
  function handleEnhancerClick(event) {
    const menuItem = event.target.closest(".aipkit_enhancer_item");
    const bulkButton = event.target.closest("#aipkit_bulk_enhance_btn");
    const config = window.aipkit_post_enhancer || {};
    const texts = config.text || {};

    if (menuItem) {
      if (menuItem.classList.contains("aipkit_enhancer_placeholder")) {
        if (event.target.tagName === "A") event.preventDefault();
        return;
      }

      event.preventDefault();
      dismissAssistantPopover(menuItem);

      const postId = menuItem.getAttribute("data-post-id");
      const actionType = menuItem.getAttribute("data-action-type");
      if (!postId || !actionType) return;

      switch (actionType) {
        case "title":
          window.aipkit_showLoadingModal(postId, texts, config);
          window.aipkit_fetchTitleSuggestions(
            postId,
            window.aipkit_handleTitleSuggestionsResult,
            menuItem
          );
          break;
        case "excerpt":
          window.aipkit_showLoadingExcerptModal(postId, texts, config);
          window.aipkit_fetchExcerptSuggestions(
            postId,
            window.aipkit_handleExcerptSuggestionsResult,
            menuItem
          );
          break;
        case "meta":
          window.aipkit_showLoadingMetaModal(postId, texts, config);
          window.aipkit_fetchMetaSuggestions(
            postId,
            window.aipkit_handleMetaSuggestionsResult,
            menuItem
          );
          break;
        case "tags":
          window.aipkit_showLoadingTagsModal(postId, texts, config);
          window.aipkit_fetchTagsSuggestions(
            postId,
            window.aipkit_handleTagsSuggestionsResult,
            menuItem
          );
          break;
        default:
          console.warn(
            `AIPKit Post Enhancer Events: Unhandled action type '${actionType}'.`
          );
          break;
      }
    } else if (bulkButton) {
      event.preventDefault();
      const selectedCheckboxes = document.querySelectorAll(
        'input[name="post[]"]:checked'
      );
      if (selectedCheckboxes.length === 0) {
        showAssistantWarning(
          texts.select_posts_message || "Please select at least one item.",
          texts.select_posts_title || "Select items",
          texts
        );
        return;
      }
      const postIds = Array.from(selectedCheckboxes).map((cb) => cb.value);
      const postType = window.typenow || "post";
      if (typeof window.aipkit_enhancer_createAndShowBulkModal === "function") {
        window.aipkit_enhancer_createAndShowBulkModal(postIds, postType);
      } else {
        showAssistantWarning(
          texts.bulk_unavailable_message ||
            "The Assistant interface is not available. Reload the page and try again.",
          texts.bulk_unavailable_title || "Unable to open",
          texts
        );
      }
    }
  }

  /**
   * Initializes the delegated event listener for the post list screen.
   */
  function aipkit_initEnhancerEventListeners() {
    const container = document; // Use document for robust event delegation
    if (!container) return;

    const listenerAttr = "data-post-enhancer-listener";
    // Check on body to prevent re-attaching if script runs multiple times
    if (document.body.getAttribute(listenerAttr)) return;

    container.addEventListener("click", handleEnhancerClick);
    document.body.setAttribute(listenerAttr, "true"); // Attach attribute to body to mark as initialized
  }

  // Expose initializer
  window.aipkit_initEnhancerEventListeners = aipkit_initEnhancerEventListeners;
})();
