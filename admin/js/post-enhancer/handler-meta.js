/**
 * AIPKit Content Enhancer - Meta Description Suggestion Result Handler
 */
(function () {
  "use strict";
  /**
   * Callback function to handle the result from fetchMetaSuggestions.
   * @param {Error|null} error Error object or null on success.
   * @param {string[]|null} suggestions Array of suggestions or null on error.
   * @param {HTMLElement} triggerItem The menu item element that initiated the action.
   */
  function aipkit_handleMetaSuggestionsResult(
    error,
    suggestions,
    triggerItem,
    currentValue
  ) {
    const config = window.aipkit_post_enhancer || {};
    const texts = config.text || {};

    if (error) {
      window.aipkit_updateModalWithErrorMeta(
        error,
        texts
      );
    } else if (suggestions) {
      window.aipkit_updateModalWithMetaSuggestions(
        suggestions,
        texts,
        currentValue
      );
    } else {
      window.aipkit_updateModalWithErrorMeta(
        texts.error_loading_meta || "Unknown error occurred.",
        texts
      );
    }
  }

  // Expose globally
  window.aipkit_handleMetaSuggestionsResult =
    aipkit_handleMetaSuggestionsResult;
})();
