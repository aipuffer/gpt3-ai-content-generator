import { createRowAssistantModalController } from "./row-assistant-modal.js";

const excerptModal = createRowAssistantModalController({
  variant: "long-form",
  titleTextKey: "modal_title_excerpt",
  defaultTitle: "Excerpt suggestions",
  loadingTextKey: "loading_excerpt",
  defaultLoading: "Generating excerpt suggestions…",
  errorTextKey: "error_loading_excerpt",
  defaultError: "Error loading excerpt suggestions.",
  fieldName: "excerpt",
  currentLabelTextKey: "current_excerpt",
  defaultCurrentLabel: "Current excerpt",
  emptyCurrentTextKey: "no_current_excerpt",
  defaultEmptyCurrent: "No current excerpt.",
  suggestionsLabelTextKey: "modal_title_excerpt",
  applyTextKey: "apply_excerpt",
  defaultApply: "Apply excerpt",
  dataAttribute: "data-new-excerpt",
  applyFunctionName: "aipkit_applyExcerptSuggestion",
  fetchFunctionName: "aipkit_fetchExcerptSuggestions",
  resultHandlerFunctionName: "aipkit_handleExcerptSuggestionsResult",
  resetFunctionName: "aipkit_resetExcerptUpdateFlag",
});

window.aipkit_showLoadingExcerptModal = excerptModal.showLoadingModal;
window.aipkit_updateModalWithExcerptSuggestions =
  excerptModal.updateModalWithSuggestions;
window.aipkit_updateModalWithErrorExcerpt = excerptModal.updateModalWithError;
window.aipkit_closePostExcerptModal = excerptModal.closeModal;
