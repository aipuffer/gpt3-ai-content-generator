import { createSuggestionFetcher } from "./suggestion-fetcher.js";

window.aipkit_fetchTagsSuggestions = createSuggestionFetcher({
  nonceKey: "nonce_generate_tags",
  action: "aipkit_generate_tags_suggestions",
  noSuggestionsTextKey: "no_suggestions_tags",
  defaultNoSuggestions: "No tag suggestions generated.",
  missingApiMessage: "Tags Fetcher: Missing required API function.",
  missingNonceMessage:
    "AIPKit Post Enhancer Fetcher (Tags): Missing nonce.",
  errorLogPrefix: "AIPKit Post Enhancer Fetcher (Tags):",
});
