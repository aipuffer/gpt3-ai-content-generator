import { createRowAssistantUpdater } from "./row-assistant-updater.js";

const titleUpdater = createRowAssistantUpdater({
  datasetKey: "newTitle",
  nonceKey: "nonce_update_title",
  action: "aipkit_update_post_title",
  closeFunctionName: "aipkit_closePostTitleModal",
  updatingTextKey: "updating_title",
  defaultUpdating: "Updating title…",
  errorTextKey: "error_updating_title",
  defaultError: "Could not update the title.",
  missingDataMessage:
    "AIPKit Post Enhancer Updater: Missing data for title update.",
  errorLogPrefix: "AIPKit Post Enhancer Update Error (Title):",
  onSuccess(postId, newTitle) {
    const rowTitleElement = document.querySelector(
      `#post-${postId} .row-title`
    );
    if (rowTitleElement) {
      rowTitleElement.textContent = newTitle;
    }

    const quickEditTitle = document.querySelector(
      `#post-${postId} #inline_${postId} .post_title`
    );
    if (quickEditTitle) {
      quickEditTitle.textContent = newTitle;
    }
  },
});

window.aipkit_applyTitleSuggestion = titleUpdater.applySuggestion;
window.aipkit_resetTitleUpdateFlag = titleUpdater.reset;
