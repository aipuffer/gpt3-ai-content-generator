import { createRowAssistantUpdater } from "./row-assistant-updater.js";

const metaUpdater = createRowAssistantUpdater({
  datasetKey: "newMeta",
  nonceKey: "nonce_update_meta",
  action: "aipkit_update_post_meta_desc",
  closeFunctionName: "aipkit_closePostMetaModal",
  updatingTextKey: "updating_meta",
  defaultUpdating: "Updating meta description…",
  errorTextKey: "error_updating_meta",
  defaultError: "Could not update the meta description.",
  missingDataMessage:
    "AIPKit Post Enhancer Updater: Missing data for meta description update.",
  errorLogPrefix: "AIPKit Post Enhancer Update Error (Meta):",
  onSuccess(postId, newMetaDescription) {
    const metaCell = document.querySelector(
      `#post-${postId} .column-wpseo-metadesc`
    );
    if (metaCell) {
      metaCell.textContent = newMetaDescription;
    }
  },
});

window.aipkit_applyMetaSuggestion = metaUpdater.applySuggestion;
window.aipkit_resetMetaUpdateFlag = metaUpdater.reset;
