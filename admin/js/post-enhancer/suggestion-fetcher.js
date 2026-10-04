export function createSuggestionFetcher(options) {
  return function fetchSuggestions(postId, callback, triggerLink) {
    if (typeof window.aipkit_apiRequest !== "function") {
      console.error(options.missingApiMessage);
      if (typeof callback === "function") {
        callback(new Error("API function missing."), null, triggerLink);
      }
      return;
    }

    const config = window.aipkit_post_enhancer;
    const nonce = config?.[options.nonceKey];
    const texts = config?.text || {};

    if (!nonce) {
      console.error(options.missingNonceMessage);
      if (typeof callback === "function") {
        callback(new Error("Configuration missing (nonce)."), null, triggerLink);
      }
      return;
    }

    window
      .aipkit_apiRequest(options.action, {
        _ajax_nonce: nonce,
        post_id: postId,
      })
      .then((data) => {
        if (!data?.suggestions || data.suggestions.length === 0) {
          throw new Error(
            texts[options.noSuggestionsTextKey] || options.defaultNoSuggestions
          );
        }
        if (typeof callback === "function") {
          callback(
            null,
            data.suggestions,
            triggerLink,
            data.current_value || ""
          );
        }
      })
      .catch((error) => {
        console.error(options.errorLogPrefix, error);
        if (typeof callback === "function") {
          callback(error, null, triggerLink);
        }
      });
  };
}
