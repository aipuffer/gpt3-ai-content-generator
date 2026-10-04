export function createRowAssistantUpdater(options) {
  let isUpdating = false;

  const reset = () => {
    isUpdating = false;
  };

  const setControlsDisabled = (modalContent, disabled) => {
    modalContent
      ?.querySelectorAll(
        ".aipkit-suggestions-list input, [data-aipkit-row-assistant-action]"
      )
      .forEach((control) => {
        control.disabled = disabled;
      });
    modalContent
      ?.querySelectorAll(".aipkit-suggestions-list li")
      .forEach((item) => item.classList.toggle("aipkit-disabled", disabled));
  };

  const setStatus = (statusDiv, message, type = "loading") => {
    if (!statusDiv) return;
    statusDiv.replaceChildren();
    if (type === "loading") {
      const spinner = document.createElement("span");
      spinner.className = "aipkit_spinner";
      spinner.setAttribute("aria-hidden", "true");
      statusDiv.append(spinner, document.createTextNode(` ${message}`));
      return;
    }

    const error = document.createElement("span");
    error.className = "error";
    error.textContent = message;
    statusDiv.appendChild(error);
  };

  const applySuggestion = (listItem) => {
    if (isUpdating || !listItem) return;

    const modalContent = listItem.closest(".aipkit-modal-content");
    const postId = modalContent?.dataset.postId;
    const newValue = listItem.dataset[options.datasetKey];
    const statusDiv = modalContent?.querySelector(".aipkit-modal-status");
    const texts = window.aipkit_post_enhancer?.text || {};
    const nonce = window.aipkit_post_enhancer?.[options.nonceKey];
    const closeModal = window[options.closeFunctionName];

    if (
      !postId ||
      newValue === undefined ||
      !statusDiv ||
      !nonce ||
      typeof window.aipkit_apiRequest !== "function" ||
      typeof closeModal !== "function"
    ) {
      console.error(options.missingDataMessage);
      setStatus(
        statusDiv,
        texts[options.errorTextKey] || options.defaultError,
        "error"
      );
      return;
    }

    isUpdating = true;
    setControlsDisabled(modalContent, true);
    setStatus(
      statusDiv,
      texts[options.updatingTextKey] || options.defaultUpdating
    );

    window
      .aipkit_apiRequest(options.action, {
        _ajax_nonce: nonce,
        post_id: postId,
        new_value: newValue,
      })
      .then(() => {
        options.onSuccess?.(postId, newValue);
        closeModal();
      })
      .catch((error) => {
        console.error(options.errorLogPrefix, error);
        setControlsDisabled(modalContent, false);
        setStatus(
          statusDiv,
          error.message ||
            texts[options.errorTextKey] ||
            options.defaultError,
          "error"
        );
      })
      .finally(() => {
        isUpdating = false;
      });
  };

  return { applySuggestion, reset };
}
