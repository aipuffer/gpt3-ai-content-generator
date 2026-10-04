import { createRowAssistantUpdater } from "./row-assistant-updater.js";

const tagsUpdater = createRowAssistantUpdater({
  datasetKey: "newTags",
  nonceKey: "nonce_update_tags",
  action: "aipkit_update_post_tags",
  closeFunctionName: "aipkit_closePostTagsModal",
  updatingTextKey: "updating_tags",
  defaultUpdating: "Updating tags…",
  errorTextKey: "error_updating_tags",
  defaultError: "Could not update the tags.",
  missingDataMessage:
    "AIPKit Post Enhancer Updater: Missing data for tags update.",
  errorLogPrefix: "AIPKit Post Enhancer Update Error (Tags):",
  onSuccess(postId, newTags) {
    const tagsCell = document.querySelector(`#post-${postId} .column-tags`);
    if (tagsCell) {
      tagsCell.textContent = newTags.split(",").join(", ");
    }
  },
});

window.aipkit_applyTagsSuggestion = tagsUpdater.applySuggestion;
window.aipkit_resetTagsUpdateFlag = tagsUpdater.reset;
