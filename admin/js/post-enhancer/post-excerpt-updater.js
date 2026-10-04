import { createRowAssistantUpdater } from "./row-assistant-updater.js";

const excerptUpdater = createRowAssistantUpdater({
  datasetKey: "newExcerpt",
  nonceKey: "nonce_update_excerpt",
  action: "aipkit_update_post_excerpt",
  closeFunctionName: "aipkit_closePostExcerptModal",
  updatingTextKey: "updating_excerpt",
  defaultUpdating: "Updating excerpt…",
  errorTextKey: "error_updating_excerpt",
  defaultError: "Could not update the excerpt.",
  missingDataMessage:
    "AIPKit Post Enhancer Updater: Missing data for excerpt update.",
  errorLogPrefix: "AIPKit Post Enhancer Update Error (Excerpt):",
});

window.aipkit_applyExcerptSuggestion = excerptUpdater.applySuggestion;
window.aipkit_resetExcerptUpdateFlag = excerptUpdater.reset;
