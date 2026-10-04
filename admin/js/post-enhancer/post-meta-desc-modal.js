import { createRowAssistantModalController } from "./row-assistant-modal.js";

const metaModal = createRowAssistantModalController({
  variant: "long-form",
  titleTextKey: "modal_title_meta",
  defaultTitle: "Meta description suggestions",
  loadingTextKey: "loading_meta",
  defaultLoading: "Generating meta description suggestions…",
  errorTextKey: "error_loading_meta",
  defaultError: "Error loading meta description suggestions.",
  fieldName: "meta",
  currentLabelTextKey: "current_meta",
  defaultCurrentLabel: "Current meta description",
  emptyCurrentTextKey: "no_current_meta",
  defaultEmptyCurrent: "No current meta description.",
  suggestionsLabelTextKey: "modal_title_meta",
  applyTextKey: "apply_meta",
  defaultApply: "Apply meta description",
  dataAttribute: "data-new-meta",
  applyFunctionName: "aipkit_applyMetaSuggestion",
  fetchFunctionName: "aipkit_fetchMetaSuggestions",
  resultHandlerFunctionName: "aipkit_handleMetaSuggestionsResult",
  resetFunctionName: "aipkit_resetMetaUpdateFlag",
});

window.aipkit_showLoadingMetaModal = metaModal.showLoadingModal;
window.aipkit_updateModalWithMetaSuggestions =
  metaModal.updateModalWithSuggestions;
window.aipkit_updateModalWithErrorMeta = metaModal.updateModalWithError;
window.aipkit_closePostMetaModal = metaModal.closeModal;
