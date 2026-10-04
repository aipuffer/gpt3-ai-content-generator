(function () {
  "use strict";

  const EXTENSION_TO_MIME = {
    jpg: "image/jpeg",
    jpeg: "image/jpeg",
    png: "image/png",
    webp: "image/webp",
    gif: "image/gif",
  };
  const MIME_TO_EXTENSION = {
    "image/jpeg": "jpg",
    "image/png": "png",
    "image/webp": "webp",
    "image/gif": "gif",
  };

  function getCurrentProvider(generatorWrapper) {
    if (
      typeof window.aipkit_getSettingValue === "function" &&
      generatorWrapper
    ) {
      return window.aipkit_getSettingValue(
        generatorWrapper,
        "aipkit_public_image_provider",
        "image_provider"
      );
    }
    const providerField = generatorWrapper
      ? generatorWrapper.querySelector("#aipkit_public_image_provider,[name=\"image_provider\"]")
      : null;
    return providerField && typeof providerField.value !== "undefined"
      ? providerField.value
      : "";
  }

  function showResultMessage(resultsContainer, message, type = "info") {
    if (!resultsContainer) {
      return;
    }
    const normalizedMessage = String(message || "").trim();
    if (!normalizedMessage) {
      return;
    }
    const normalizedType = type === "error" ? "error" : "info";
    window.aipkitImageResultUI?.notify?.(
      resultsContainer,
      normalizedMessage,
      normalizedType
    );
  }

  function resolveMimeType(rawType, fileName) {
    const normalizedType = String(rawType || "").trim().toLowerCase();
    if (normalizedType && Object.values(EXTENSION_TO_MIME).includes(normalizedType)) {
      return normalizedType;
    }
    const normalizedName = String(fileName || "").trim().toLowerCase();
    const extension = normalizedName.includes(".")
      ? normalizedName.split(".").pop()
      : "";
    return EXTENSION_TO_MIME[extension] || "";
  }

  function sanitizeFileName(fileName, mimeType) {
    const baseName = String(fileName || "")
      .trim()
      .replace(/[^\w.\-]+/g, "-")
      .replace(/-+/g, "-")
      .replace(/^\.+/, "")
      .replace(/^\-+/, "");
    if (!baseName) {
      return `aipkit-source-image.${MIME_TO_EXTENSION[mimeType] || "png"}`;
    }
    const hasKnownExtension = /\.[a-z0-9]+$/i.test(baseName);
    if (hasKnownExtension) {
      return baseName;
    }
    return `${baseName}.${MIME_TO_EXTENSION[mimeType] || "png"}`;
  }

  function setEditButtonLoadingState(button, isLoading) {
    if (!button) return;
    const dashicon = button.querySelector(".dashicons");
    const spinner = button.querySelector(".aipkit_spinner");
    if (dashicon) {
      dashicon.hidden = Boolean(isLoading);
    }
    if (spinner) {
      spinner.hidden = !isLoading;
      spinner.classList.toggle("aipkit_spinner--visible", Boolean(isLoading));
    }
    button.disabled = Boolean(isLoading);
  }

  async function fetchHistoryImageAsFile(imageUrl, fileNameHint, constraints, texts) {
    const response = await fetch(imageUrl, {
      method: "GET",
      credentials: "same-origin",
    });
    if (!response.ok) {
      throw new Error(
        texts.editHistoryLoadFailed ||
          "Could not load the selected image for editing."
      );
    }

    const blob = await response.blob();
    const mimeType = resolveMimeType(blob.type, fileNameHint || imageUrl);
    if (!mimeType || !constraints.allowedMimeTypes.has(mimeType)) {
      throw new Error(constraints.invalidTypeMessage);
    }
    if (Number(blob.size || 0) > constraints.maxBytes) {
      throw new Error(
        constraints.tooLargeMessage
      );
    }

    const finalName = sanitizeFileName(fileNameHint, mimeType);
    return new File([blob], finalName, { type: mimeType });
  }

  function assignFileToInput(fileInput, file, texts) {
    if (!fileInput || !file) {
      return false;
    }
    if (typeof DataTransfer === "undefined") {
      throw new Error(
        texts.editDropUsePicker ||
          "Could not attach selected image automatically. Click to choose file."
      );
    }
    const transfer = new DataTransfer();
    transfer.items.add(file);
    fileInput.files = transfer.files;
    fileInput.dispatchEvent(new Event("change", { bubbles: true }));
    return true;
  }

  async function aipkit_useImageAsEditSource(
    generatorWrapper,
    imageUrl,
    imageNameHint = "",
    editButton = null
  ) {
    if (!generatorWrapper) {
      return false;
    }

    const config = window.aipkit_image_generator_config_public || {};
    const texts = config.text || {};
    const resultsContainer = generatorWrapper.querySelector(
      "#aipkit_public_image_results"
    );
    const sourceInput = generatorWrapper.querySelector(
      "#aipkit_public_image_edit_source_file"
    );
    const promptInput = generatorWrapper.querySelector("#aipkit_public_image_prompt");
    const composer = generatorWrapper.querySelector(
      ".aipkit_image_generator_composer"
    );
    const normalizedImageUrl = String(imageUrl || "").trim();
    const normalizedImageName = String(imageNameHint || "").trim();

    if (!normalizedImageUrl || !sourceInput) {
      showResultMessage(
        resultsContainer,
        texts.editHistoryLoadFailed ||
          "Could not load the selected image for editing.",
        "error"
      );
      return false;
    }

    setEditButtonLoadingState(editButton, true);

    try {
      const constraints = window.aipkit_getImageEditUploadConstraints(
        config,
        getCurrentProvider(generatorWrapper),
        window.aipkit_getSettingValue(generatorWrapper, "aipkit_public_image_model", "image_model")
      );
      const sourceFile = await fetchHistoryImageAsFile(
        normalizedImageUrl,
        normalizedImageName,
        constraints,
        texts
      );
      assignFileToInput(sourceInput, sourceFile, texts);
      const attachmentChip = generatorWrapper.querySelector(
        "#aipkit_public_image_attachment_chip"
      );
      if (!attachmentChip || attachmentChip.hidden) {
        throw new Error(
          texts.editHistoryUnavailable ||
            "Image editing is not available in the current setup."
        );
      }

      if (composer && typeof composer.scrollIntoView === "function") {
        composer.scrollIntoView({ behavior: "smooth", block: "center" });
      }
      if (promptInput && typeof promptInput.focus === "function") {
        promptInput.focus();
      }

      showResultMessage(
        resultsContainer,
        texts.editHistoryLoaded ||
          "Source image attached. Describe the edit you want.",
        "info"
      );
      return true;
    } catch (error) {
      console.error("AIPKit Image History Edit Error:", error);
      showResultMessage(
        resultsContainer,
        error?.message || texts.error || "Error loading image for editing.",
        "error"
      );
      return false;
    } finally {
      setEditButtonLoadingState(editButton, false);
    }
  }

  window.aipkit_useImageAsEditSource = aipkit_useImageAsEditSource;
})();
