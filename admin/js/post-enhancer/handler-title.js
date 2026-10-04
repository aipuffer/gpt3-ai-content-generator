/**
 * AIPKit Content Enhancer - Title Suggestion Result Handler
 */
(function () {
  "use strict";
  /**
   * Callback function to handle the result from fetchTitleSuggestions.
   * @param {Error|null} error Error object or null on success.
   * @param {string[]|null} suggestions Array of suggestions or null on error.
   * @param {HTMLElement} triggerItem The menu item element that initiated the action.
   */
  function aipkit_handleTitleSuggestionsResult(
    error,
    suggestions,
    triggerItem,
    currentValue
  ) {
    const config = window.aipkit_post_enhancer || {};
    const texts = config.text || {};

    if (error) {
      window.aipkit_updateModalWithError(
        error,
        texts
      );
    } else if (suggestions) {
      window.aipkit_updateModalWithSuggestions(
        suggestions,
        texts,
        currentValue
      );
    } else {
      window.aipkit_updateModalWithError(
        texts.error_loading_title || "Unknown error occurred.",
        texts
      );
    }
  }

  // Expose globally
  window.aipkit_handleTitleSuggestionsResult =
    aipkit_handleTitleSuggestionsResult;
})();
