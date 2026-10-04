/**
 * AIPKit Content Enhancer - Excerpt Suggestion Result Handler
 */
(function () {
  "use strict";
  /**
   * Callback function to handle the result from fetchExcerptSuggestions.
   * @param {Error|null} error Error object or null on success.
   * @param {string[]|null} suggestions Array of suggestions or null on error.
   * @param {HTMLElement} triggerItem The menu item element that initiated the action.
   */
  function aipkit_handleExcerptSuggestionsResult(
    error,
    suggestions,
    triggerItem,
    currentValue
  ) {
    const config = window.aipkit_post_enhancer || {};
    const texts = config.text || {};

    if (error) {
      window.aipkit_updateModalWithErrorExcerpt(
        error,
        texts
      );
    } else if (suggestions) {
      window.aipkit_updateModalWithExcerptSuggestions(
        suggestions,
        texts,
        currentValue
      );
    } else {
      window.aipkit_updateModalWithErrorExcerpt(
        texts.error_loading_excerpt || "Unknown error occurred.",
        texts
      );
    }
  }

  // Expose globally
  window.aipkit_handleExcerptSuggestionsResult =
    aipkit_handleExcerptSuggestionsResult;
})();
