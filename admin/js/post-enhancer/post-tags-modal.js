import { createRowAssistantModalController } from "./row-assistant-modal.js";

const tagsModal = createRowAssistantModalController({
  titleTextKey: "modal_title_tags",
  defaultTitle: "Tag suggestions",
  loadingTextKey: "loading_tags",
  defaultLoading: "Generating tag suggestions…",
  errorTextKey: "error_loading_tags",
  defaultError: "Error loading tag suggestions.",
  fieldName: "tags",
  currentLabelTextKey: "current_tags",
  defaultCurrentLabel: "Current tags",
  emptyCurrentTextKey: "no_current_tags",
  defaultEmptyCurrent: "No current tags.",
  suggestionsLabelTextKey: "modal_title_tags",
  applyTextKey: "apply_tags",
  defaultApply: "Apply tags",
  dataAttribute: "data-new-tags",
  applyFunctionName: "aipkit_applyTagsSuggestion",
  fetchFunctionName: "aipkit_fetchTagsSuggestions",
  resultHandlerFunctionName: "aipkit_handleTagsSuggestionsResult",
  resetFunctionName: "aipkit_resetTagsUpdateFlag",
});

window.aipkit_showLoadingTagsModal = tagsModal.showLoadingModal;
window.aipkit_updateModalWithTagsSuggestions =
  tagsModal.updateModalWithSuggestions;
window.aipkit_updateModalWithErrorTags = tagsModal.updateModalWithError;
window.aipkit_closePostTagsModal = tagsModal.closeModal;
