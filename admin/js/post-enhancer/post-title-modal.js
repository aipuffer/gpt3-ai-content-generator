import { createRowAssistantModalController } from "./row-assistant-modal.js";

const titleModal = createRowAssistantModalController({
  titleTextKey: "modal_title_title",
  defaultTitle: "Title suggestions",
  loadingTextKey: "loading_title",
  defaultLoading: "Generating title suggestions…",
  errorTextKey: "error_loading_title",
  defaultError: "Error loading title suggestions.",
  fieldName: "title",
  currentLabelTextKey: "current_title",
  defaultCurrentLabel: "Current title",
  emptyCurrentTextKey: "no_current_title",
  defaultEmptyCurrent: "No current title.",
  suggestionsLabelTextKey: "modal_title_title",
  applyTextKey: "apply_title",
  defaultApply: "Apply title",
  dataAttribute: "data-new-title",
  applyFunctionName: "aipkit_applyTitleSuggestion",
  fetchFunctionName: "aipkit_fetchTitleSuggestions",
  resultHandlerFunctionName: "aipkit_handleTitleSuggestionsResult",
  resetFunctionName: "aipkit_resetTitleUpdateFlag",
});

window.aipkit_showLoadingModal = titleModal.showLoadingModal;
window.aipkit_updateModalWithSuggestions =
  titleModal.updateModalWithSuggestions;
window.aipkit_updateModalWithError = titleModal.updateModalWithError;
window.aipkit_closePostTitleModal = titleModal.closeModal;
