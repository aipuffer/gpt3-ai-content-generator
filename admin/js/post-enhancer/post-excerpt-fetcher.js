import { createSuggestionFetcher } from "./suggestion-fetcher.js";

window.aipkit_fetchExcerptSuggestions = createSuggestionFetcher({
  nonceKey: "nonce_generate_excerpt",
  action: "aipkit_generate_excerpt_suggestions",
  noSuggestionsTextKey: "no_suggestions_excerpt",
  defaultNoSuggestions: "No excerpt suggestions generated.",
  missingApiMessage: "Post Excerpt Fetcher: Missing required API function.",
  missingNonceMessage:
    "AIPKit Post Enhancer Fetcher (Excerpt): Missing nonce.",
  errorLogPrefix: "AIPKit Post Enhancer Fetcher (Excerpt):",
});
