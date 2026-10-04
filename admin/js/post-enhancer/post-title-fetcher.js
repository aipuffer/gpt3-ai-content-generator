import { createSuggestionFetcher } from "./suggestion-fetcher.js";

window.aipkit_fetchTitleSuggestions = createSuggestionFetcher({
  nonceKey: "nonce_generate_title",
  action: "aipkit_generate_title_suggestions",
  noSuggestionsTextKey: "no_suggestions_title",
  defaultNoSuggestions: "No title suggestions generated.",
  missingApiMessage: "Post Title Fetcher: Missing required API function.",
  missingNonceMessage:
    "AIPKit Post Enhancer Fetcher (Title): Missing nonce.",
  errorLogPrefix: "AIPKit Post Enhancer Fetcher (Title):",
});
