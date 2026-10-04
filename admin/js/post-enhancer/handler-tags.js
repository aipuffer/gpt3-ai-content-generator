/**
 * AIPKit Content Enhancer - Tags Suggestion Result Handler
 */
(function () {
  "use strict";
  /**
   * Callback function to handle the result from fetchTagsSuggestions.
   * @param {Error|null} error Error object or null on success.
   * @param {string[]|null} suggestions Array of suggestions or null on error.
   * @param {HTMLElement} triggerItem The menu item element that initiated the action.
   */
  function aipkit_handleTagsSuggestionsResult(
    error,
    suggestions,
    triggerItem,
    currentValue
  ) {
    const config = window.aipkit_post_enhancer || {};
    const texts = config.text || {};

    if (error) {
      window.aipkit_updateModalWithErrorTags(
        error,
        texts
      );
    } else if (suggestions) {
      window.aipkit_updateModalWithTagsSuggestions(
        suggestions,
        texts,
        currentValue
      );
    } else {
      window.aipkit_updateModalWithErrorTags(
        texts.error_loading_tags || "Unknown error occurred.",
        texts
      );
    }
  }

  // Expose globally
  window.aipkit_handleTagsSuggestionsResult = aipkit_handleTagsSuggestionsResult;
})();
