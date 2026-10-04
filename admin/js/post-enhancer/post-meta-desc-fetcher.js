import { createSuggestionFetcher } from "./suggestion-fetcher.js";

window.aipkit_fetchMetaSuggestions = createSuggestionFetcher({
  nonceKey: "nonce_generate_meta",
  action: "aipkit_generate_meta_suggestions",
  noSuggestionsTextKey: "no_suggestions_meta",
  defaultNoSuggestions: "No meta description suggestions generated.",
  missingApiMessage: "Post Meta Fetcher: Missing required API function.",
  missingNonceMessage:
    "AIPKit Post Enhancer Fetcher (Meta): Missing nonce.",
  errorLogPrefix: "AIPKit Post Enhancer Fetcher (Meta):",
});
