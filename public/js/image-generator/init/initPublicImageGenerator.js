(function () {
  "use strict";
  const PROMPT_MAX_HEIGHT = 120;

  function getShortcodeMode(generatorWrapper) {
    const mode = String(generatorWrapper?.dataset?.imageMode || "generate")
      .trim()
      .toLowerCase();
    return ["generate", "edit", "both"].includes(mode) ? mode : "generate";
  }

  function getAllowedModelsValue(generatorWrapper) {
    return String(generatorWrapper?.dataset?.allowedModels || "").trim();
  }

  function resolveInitialImageMode(generatorWrapper) {
    const initialMode = String(
      generatorWrapper?.dataset?.initialImageMode || ""
    )
      .trim()
      .toLowerCase();
    if (initialMode === "edit" && getShortcodeMode(generatorWrapper) !== "generate") {
      return "edit";
    }
    return getShortcodeMode(generatorWrapper) === "edit" ? "edit" : "generate";
  }

  function applyImageModeUI(generatorWrapper, mode) {
    if (!generatorWrapper) return;
    const normalizedMode = mode === "edit" ? "edit" : "generate";
    const modeInput = generatorWrapper.querySelector("#aipkit_public_image_mode");
    const promptInput = generatorWrapper.querySelector("#aipkit_public_image_prompt");
    const composerPill = generatorWrapper.querySelector(
      "[data-aipkit-image-composer-pill]"
    );
    const generateButton = generatorWrapper.querySelector(
      "#aipkit_public_generate_image_btn"
    );
    const generatePlaceholder =
      generatorWrapper.dataset.generatePlaceholder ||
      "Describe your image…";
    const editPlaceholder =
      generatorWrapper.dataset.editPlaceholder ||
      "Describe the edit…";
    const generateLabel = generatorWrapper.dataset.generateLabel || "Generate";
    const editLabel = generatorWrapper.dataset.editLabel || "Edit Image";

    if (modeInput) {
      modeInput.value = normalizedMode;
    }

    if (promptInput) {
      promptInput.setAttribute(
        "placeholder",
        normalizedMode === "edit" ? editPlaceholder : generatePlaceholder
      );
    }

    if (composerPill) {
      composerPill.dataset.mode = normalizedMode;
    }

    if (generateButton) {
      const actionLabel = normalizedMode === "edit" ? editLabel : generateLabel;
      generateButton.dataset.actionLabel = actionLabel;
      if (generateButton.dataset.generating !== "true") {
        generateButton.setAttribute("aria-label", actionLabel);
      }
    }
  }

  function getImageSettingValue(generatorWrapper, fieldId, fieldName) {
    if (
      typeof window.aipkit_getSettingValue === "function" &&
      generatorWrapper
    ) {
      return window.aipkit_getSettingValue(generatorWrapper, fieldId, fieldName);
    }
    const byId = generatorWrapper
      ? generatorWrapper.querySelector(`#${fieldId}`)
      : null;
    if (byId && typeof byId.value !== "undefined") {
      return byId.value;
    }
    const byName = generatorWrapper
      ? generatorWrapper.querySelector(`[name="${fieldName}"]`)
      : null;
    if (byName && typeof byName.value !== "undefined") {
      return byName.value;
    }
    return "";
  }

  function setImageSettingValue(generatorWrapper, fieldId, fieldName, value) {
    const byId = generatorWrapper
      ? generatorWrapper.querySelector(`#${fieldId}`)
      : null;
    if (byId && typeof byId.value !== "undefined") {
      byId.value = value;
      return;
    }
    const byName = generatorWrapper
      ? generatorWrapper.querySelector(`[name="${fieldName}"]`)
      : null;
    if (byName && typeof byName.value !== "undefined") {
      byName.value = value;
    }
  }

  function getCurrentImageMode(generatorWrapper) {
    const modeInput = generatorWrapper.querySelector("#aipkit_public_image_mode");
    return String(modeInput && modeInput.value ? modeInput.value : "generate")
      .trim()
      .toLowerCase() === "edit"
      ? "edit"
      : "generate";
  }

  function getCurrentImageProvider(generatorWrapper) {
    return String(
      getImageSettingValue(
        generatorWrapper,
        "aipkit_public_image_provider",
        "image_provider"
      ) || ""
    ).trim();
  }

  function syncComposerSubmitState(generatorWrapper) {
    if (!generatorWrapper) return;
    const promptInput = generatorWrapper.querySelector(
      "#aipkit_public_image_prompt"
    );
    const generateButton = generatorWrapper.querySelector(
      "#aipkit_public_generate_image_btn"
    );
    if (!generateButton) return;

    const hasPrompt = Boolean(String(promptInput?.value || "").trim());
    const hasModel = generateButton.dataset.modelAvailable !== "0";
    const isGenerating = generateButton.dataset.generating === "true" || generatorWrapper.dataset.cloudRequestPending === "true";
    generateButton.disabled = isGenerating || !hasPrompt || !hasModel;
    generateButton.classList.toggle(
      "is-ready",
      hasPrompt && hasModel && !isGenerating
    );
  }

  function resizePromptInput(generatorWrapper) {
    const promptInput = generatorWrapper?.querySelector(
      "#aipkit_public_image_prompt"
    );
    const composerPill = generatorWrapper?.querySelector(
      "[data-aipkit-image-composer-pill]"
    );
    if (!promptInput || !composerPill) return;

    if (!promptInput.value) {
      promptInput.style.height = "20px";
      promptInput.style.overflowY = "hidden";
      composerPill.classList.remove("is-expanded");
      return;
    }

    promptInput.style.height = "auto";
    const measuredHeight = Number(promptInput.scrollHeight || 0);
    const nextHeight = Math.min(
      PROMPT_MAX_HEIGHT,
      Math.max(20, measuredHeight)
    );
    promptInput.style.height = `${nextHeight}px`;
    promptInput.style.overflowY =
      measuredHeight > PROMPT_MAX_HEIGHT ? "auto" : "hidden";
    composerPill.classList.toggle("is-expanded", nextHeight > 36);
  }

  function rememberGenerateSelection(generatorWrapper) {
    generatorWrapper.dataset.lastGenerateProvider = getCurrentImageProvider(
      generatorWrapper
    );
    generatorWrapper.dataset.lastGenerateModel = String(
      getImageSettingValue(
        generatorWrapper,
        "aipkit_public_image_model",
        "image_model"
      ) || ""
    ).trim();
  }

  function getRememberedGenerateSelection(generatorWrapper) {
    const provider = String(
      generatorWrapper.dataset.lastGenerateProvider || ""
    ).trim();
    const model = String(
      generatorWrapper.dataset.lastGenerateModel || ""
    ).trim();
    return provider && model ? { provider, model } : null;
  }

  function switchComposerMode(
    generatorWrapper,
    mode,
    config,
    allowedModelsStr
  ) {
    const nextMode = mode === "edit" ? "edit" : "generate";
    const previousMode = getCurrentImageMode(generatorWrapper);
    let preferredSelection = null;

    if (previousMode === "generate" && nextMode === "edit") {
      rememberGenerateSelection(generatorWrapper);
    } else if (previousMode === "edit" && nextMode === "generate") {
      preferredSelection = getRememberedGenerateSelection(generatorWrapper);
    }

    applyImageModeUI(generatorWrapper, nextMode);
    syncEditModeAvailability(
      generatorWrapper,
      config,
      allowedModelsStr,
      preferredSelection
    );
    syncComposerSubmitState(generatorWrapper);
    return getCurrentImageMode(generatorWrapper) === nextMode;
  }

  function initComposerAttachment(generatorWrapper, config) {
    const fileInput = generatorWrapper.querySelector(
      "#aipkit_public_image_edit_source_file"
    );
    const attachButton = generatorWrapper.querySelector(
      "#aipkit_public_image_attach_btn"
    );
    const attachmentChip = generatorWrapper.querySelector(
      "#aipkit_public_image_attachment_chip"
    );
    const filePreview = generatorWrapper.querySelector(
      "#aipkit_public_image_edit_file_preview"
    );
    const fileName = generatorWrapper.querySelector(
      "#aipkit_public_image_edit_file_name"
    );
    const removeButton = generatorWrapper.querySelector(
      "#aipkit_public_image_edit_file_remove"
    );
    const feedback = generatorWrapper.querySelector(
      "#aipkit_public_image_edit_upload_feedback"
    );
    const composerPill = generatorWrapper.querySelector(
      "[data-aipkit-image-composer-pill]"
    );
    if (!fileInput || !attachButton || !attachmentChip) return;
    if (fileInput.dataset.attachmentListenerAttached === "true") return;

    const texts = config && typeof config.text === "object" ? config.text : {};
    let currentPreviewObjectUrl = "";
    let currentFile = null;

    const clearPreview = () => {
      if (
        currentPreviewObjectUrl &&
        typeof URL !== "undefined" &&
        typeof URL.revokeObjectURL === "function"
      ) {
        URL.revokeObjectURL(currentPreviewObjectUrl);
      }
      currentPreviewObjectUrl = "";
      if (filePreview) {
        filePreview.removeAttribute("src");
        filePreview.hidden = true;
      }
    };

    const setFeedback = (message) => {
      if (!feedback) return;
      const normalizedMessage = String(message || "").trim();
      feedback.textContent = normalizedMessage;
      feedback.hidden = normalizedMessage === "";
      composerPill?.classList.toggle(
        "has-attachment-error",
        normalizedMessage !== ""
      );
    };

    const validateFile = (file) => {
      if (!file) {
        return {
          valid: false,
          message: texts.editUploadRequired || "Please upload an image to edit.",
        };
      }
      const mimeType = String(file.type || "").trim().toLowerCase();
      const constraints = window.aipkit_getImageEditUploadConstraints(
        config,
        getCurrentImageProvider(generatorWrapper),
        getImageSettingValue(generatorWrapper, "aipkit_public_image_model", "image_model")
      );
      if (!constraints.allowedMimeTypes.has(mimeType)) {
        return { valid: false, message: constraints.invalidTypeMessage };
      }
      if (Number(file.size || 0) > constraints.maxBytes) {
        return {
          valid: false,
          message: constraints.tooLargeMessage,
        };
      }
      return { valid: true, message: "" };
    };

    const assignFileToInput = (file) => {
      if (!file || typeof DataTransfer === "undefined") return false;
      try {
        const transfer = new DataTransfer();
        transfer.items.add(file);
        fileInput.files = transfer.files;
        return true;
      } catch (error) {
        return false;
      }
    };

    const showSelectedFile = (file) => {
      attachmentChip.hidden = false;
      if (fileName) {
        fileName.textContent = file.name;
        fileName.setAttribute("title", file.name);
      }
      if (
        filePreview &&
        typeof URL !== "undefined" &&
        typeof URL.createObjectURL === "function"
      ) {
        clearPreview();
        currentPreviewObjectUrl = URL.createObjectURL(file);
        filePreview.src = currentPreviewObjectUrl;
        filePreview.hidden = false;
      }
      currentFile = file;
      setFeedback("");
    };

    const clearSelectedFile = () => {
      fileInput.value = "";
      clearPreview();
      attachmentChip.hidden = true;
      if (fileName) {
        fileName.textContent = "";
        fileName.removeAttribute("title");
      }
      currentFile = null;
      setFeedback("");
      if (getShortcodeMode(generatorWrapper) === "both") {
        switchComposerMode(
          generatorWrapper,
          "generate",
          config,
          getAllowedModelsValue(generatorWrapper)
        );
      }
    };

    const applySelectedFile = (file) => {
      const previousMode = getCurrentImageMode(generatorWrapper);
      if (
        previousMode === "generate" &&
        !switchComposerMode(
          generatorWrapper,
          "edit",
          config,
          getAllowedModelsValue(generatorWrapper)
        )
      ) {
        fileInput.value = "";
        setFeedback(
          texts.noEditCapableModels ||
            "No edit-capable models are available."
        );
        return false;
      }

      const validation = validateFile(file);
      if (!validation.valid) {
        if (currentFile) {
          assignFileToInput(currentFile);
        } else {
          fileInput.value = "";
          if (
            previousMode === "generate" &&
            getShortcodeMode(generatorWrapper) === "both"
          ) {
            switchComposerMode(
              generatorWrapper,
              "generate",
              config,
              getAllowedModelsValue(generatorWrapper)
            );
          }
        }
        setFeedback(validation.message);
        return false;
      }

      showSelectedFile(file);
      return true;
    };

    const syncConstraints = () => {
      const constraints = window.aipkit_getImageEditUploadConstraints(
        config,
        getCurrentImageProvider(generatorWrapper),
        getImageSettingValue(generatorWrapper, "aipkit_public_image_model", "image_model")
      );
      if (constraints.accept) {
        fileInput.setAttribute("accept", constraints.accept);
      }
      if (currentFile) {
        const validation = validateFile(currentFile);
        setFeedback(validation.valid ? "" : validation.message);
      }
    };

    attachButton.addEventListener("click", () => fileInput.click());
    fileInput.addEventListener("change", () => {
      const selectedFile = fileInput.files?.[0] || null;
      if (!selectedFile) {
        clearSelectedFile();
        return;
      }
      applySelectedFile(selectedFile);
    });
    removeButton?.addEventListener("click", (event) => {
      event.preventDefault();
      clearSelectedFile();
    });

    if (fileInput.files?.[0]) {
      applySelectedFile(fileInput.files[0]);
    }

    fileInput.dataset.attachmentListenerAttached = "true";
    generatorWrapper._aipkitImageAttachmentController = { syncConstraints };
    syncConstraints();
    window.addEventListener("beforeunload", clearPreview, { once: true });
  }

  function initComposerPrompt(generatorWrapper, generateButton) {
    const promptInput = generatorWrapper.querySelector(
      "#aipkit_public_image_prompt"
    );
    if (!promptInput) return;

    resizePromptInput(generatorWrapper);
    syncComposerSubmitState(generatorWrapper);
    if (promptInput.dataset.composerListenerAttached === "true") return;

    promptInput.addEventListener("input", () => {
      resizePromptInput(generatorWrapper);
      syncComposerSubmitState(generatorWrapper);
    });
    promptInput.addEventListener("keydown", (event) => {
      if (
        event.key !== "Enter" ||
        event.shiftKey ||
        event.isComposing ||
        event.keyCode === 229
      ) {
        return;
      }
      event.preventDefault();
      syncComposerSubmitState(generatorWrapper);
      if (!generateButton.disabled) {
        generateButton.click();
      }
    });
    promptInput.dataset.composerListenerAttached = "true";
  }

  const normalizeSelectionValue = (value) =>
    String(value || "").trim().toLowerCase();

  function getImagePickerVisibility(generatorWrapper) {
    return {
      showProvider: generatorWrapper.dataset.showProvider !== "0",
      showModel: generatorWrapper.dataset.showModel !== "0",
    };
  }

  function setImageSelection(generatorWrapper, provider, model) {
    setImageSettingValue(
      generatorWrapper,
      "aipkit_public_image_provider",
      "image_provider",
      String(provider || "")
    );
    setImageSettingValue(
      generatorWrapper,
      "aipkit_public_image_model",
      "image_model",
      String(model || "")
    );
  }

  function getPublicImageEntries(
    config,
    allowedModelsStr,
    mode,
    providerFilter = ""
  ) {
    if (typeof window.aipkit_getPublicImageModelEntries !== "function") {
      return [];
    }
    return window.aipkit_getPublicImageModelEntries(
      config,
      allowedModelsStr,
      mode,
      providerFilter
    );
  }

  function findMatchingEntry(entries, provider, model) {
    const normalizedProvider = normalizeSelectionValue(provider);
    const normalizedModel = normalizeSelectionValue(model);
    return (
      entries.find(
        (entry) =>
          normalizeSelectionValue(entry.provider) === normalizedProvider &&
          normalizeSelectionValue(entry.modelValue) === normalizedModel
      ) || null
    );
  }

  function applyPickerSelection(generatorWrapper, sourceSelect) {
    const option = sourceSelect?.selectedOptions?.[0] || null;
    if (!option) {
      const visibility = getImagePickerVisibility(generatorWrapper);
      const fixedProvider = visibility.showProvider
        ? ""
        : getCurrentImageProvider(generatorWrapper);
      setImageSelection(generatorWrapper, fixedProvider, "");
      return null;
    }
    const selection = {
      provider: option.dataset.provider || "",
      model: option.dataset.model || "",
    };
    setImageSelection(generatorWrapper, selection.provider, selection.model);
    return selection;
  }

  function decoratePublicModelPopover(generatorWrapper, selector) {
    const popover = selector?.querySelector(
      "[data-aipkit-unified-model-popover]"
    );
    if (!popover) {
      return;
    }
    const supportedThemes = ["light", "dark", "custom"];
    const themeFromClass = supportedThemes.find((themeName) =>
      generatorWrapper.classList.contains(`aipkit-theme-${themeName}`)
    );
    const requestedTheme = String(generatorWrapper.dataset.theme || "light")
      .trim()
      .toLowerCase();
    const theme =
      themeFromClass ||
      (supportedThemes.includes(requestedTheme) ? requestedTheme : "light");
    const supportedFontModes = ["theme", "system"];
    const fontFromClass = supportedFontModes.find((fontMode) =>
      generatorWrapper.classList.contains(`aipkit-font-${fontMode}`)
    );
    const requestedFont = String(generatorWrapper.dataset.font || "system")
      .trim()
      .toLowerCase();
    const fontMode =
      fontFromClass ||
      (supportedFontModes.includes(requestedFont) ? requestedFont : "system");
    const wrapperStyles = window.getComputedStyle(generatorWrapper);
    supportedThemes.forEach((themeName) => {
      popover.classList.remove(`aipkit-theme-${themeName}`);
    });
    supportedFontModes.forEach((supportedFontMode) => {
      popover.classList.remove(`aipkit-font-${supportedFontMode}`);
    });
    popover.classList.add(
      "aipkit_image_generator_model_popover",
      `aipkit-theme-${theme}`,
      `aipkit-font-${fontMode}`
    );
    popover.style.fontFamily = wrapperStyles.fontFamily;
    popover.style.fontSize = wrapperStyles.fontSize;
    if (theme === "custom") {
      [
        "--aipkit-image-surface",
        "--aipkit-image-surface-subtle",
        "--aipkit-image-surface-raised",
        "--aipkit-image-border",
        "--aipkit-image-border-strong",
        "--aipkit-image-text",
        "--aipkit-image-muted",
        "--aipkit-image-faint",
        "--aipkit-image-accent",
        "--aipkit-image-focus",
      ].forEach((propertyName) => {
        const propertyValue = wrapperStyles.getPropertyValue(propertyName).trim();
        if (propertyValue) {
          popover.style.setProperty(propertyName, propertyValue);
        }
      });
    }
    const visibility = getImagePickerVisibility(generatorWrapper);
    popover.classList.toggle(
      "aipkit_image_generator_model_popover--provider-hidden",
      !visibility.showProvider
    );
    popover.classList.toggle(
      "aipkit_image_generator_model_popover--model-hidden",
      !visibility.showModel
    );
  }

  function observePublicModelPickerTheme(generatorWrapper) {
    const selector = generatorWrapper.querySelector(
      ".aipkit_image_generator_model_picker"
    );
    if (
      !selector ||
      generatorWrapper._aipkitPublicImageThemeObserver instanceof MutationObserver
    ) {
      return;
    }
    decoratePublicModelPopover(generatorWrapper, selector);
    const observer = new MutationObserver((mutations) => {
      if (mutations.some((mutation) => mutation.attributeName === "class")) {
        decoratePublicModelPopover(generatorWrapper, selector);
      }
    });
    observer.observe(generatorWrapper, {
      attributes: true,
      attributeFilter: ["class"],
    });
    generatorWrapper._aipkitPublicImageThemeObserver = observer;
  }

  function initializePublicModelPicker(generatorWrapper, sourceSelect) {
    const selector = generatorWrapper.querySelector(
      ".aipkit_image_generator_model_picker"
    );
    if (
      !selector ||
      !sourceSelect ||
      typeof window.aipkit_createUnifiedModelSelector !== "function" ||
      typeof window.aipkit_createUnifiedModelSelectAdapter !== "function"
    ) {
      return null;
    }
    if (generatorWrapper.dataset.showModel === "0") {
      selector.dataset.aipkitProviderOnly = "1";
    }
    decoratePublicModelPopover(generatorWrapper, selector);
    const adapter = window.aipkit_createUnifiedModelSelectAdapter(sourceSelect);
    const controller = window.aipkit_createUnifiedModelSelector(
      selector,
      adapter
    );
    if (!controller) {
      return null;
    }
    sourceSelect._aipkitPublicImagePickerController = controller;
    return controller;
  }

  function syncPublicModelPickerDensity(generatorWrapper, sourceSelect) {
    const selector = generatorWrapper.querySelector(
      ".aipkit_image_generator_model_picker"
    );
    const staticModel = generatorWrapper.querySelector(
      "[data-aipkit-image-single-model]"
    );
    if (!selector || !staticModel || !sourceSelect) {
      return;
    }

    const options = Array.from(sourceSelect.options || []);
    const providers = new Set(
      options
        .map((option) => normalizeSelectionValue(option.dataset.provider))
        .filter(Boolean)
    );
    const visibility = getImagePickerVisibility(generatorWrapper);
    const modelFavoritesAvailable =
      visibility.showProvider && visibility.showModel && providers.size > 1;
    const singleModel = options.length === 1 && generatorWrapper.dataset.isAdminPreview !== "1";
    const popover = selector.querySelector(
      "[data-aipkit-unified-model-popover]"
    );

    selector.dataset.aipkitShowModelFavorites = modelFavoritesAvailable
      ? "1"
      : "0";
    popover?.classList.toggle(
      "aipkit_image_generator_model_popover--single-provider",
      providers.size === 1
    );
    selector.hidden = singleModel;
    staticModel.hidden = !singleModel;

    if (!singleModel) {
      return;
    }

    const selectedOption = sourceSelect.selectedOptions?.[0] || options[0];
    const staticName = staticModel.querySelector(
      "[data-aipkit-image-single-model-name]"
    );
    const staticLogo = staticModel.querySelector(
      "[data-aipkit-image-single-model-logo]"
    );
    const triggerLogo = selector.querySelector(
      "[data-aipkit-unified-model-logo]"
    );

    if (staticName) {
      staticName.textContent = String(selectedOption?.textContent || "").trim();
    }
    if (staticLogo) {
      if (triggerLogo) {
        staticLogo.className = triggerLogo.className;
        staticLogo.classList.add("aipkit_image_generator_single_model_logo");
        staticLogo.replaceChildren(
          ...Array.from(triggerLogo.childNodes).map((node) =>
            node.cloneNode(true)
          )
        );
        staticLogo.hidden = triggerLogo.hidden;
      } else {
        staticLogo.hidden = true;
      }
    }
  }

  function setModeNotice(generatorWrapper, message = "") {
    const notice = generatorWrapper.querySelector(
      "#aipkit_public_image_edit_mode_notice"
    );
    if (!notice) {
      return;
    }
    const normalizedMessage = String(message || "").trim();
    notice.textContent = normalizedMessage;
    notice.hidden = normalizedMessage === "";
  }

  function syncModeControlAvailability(
    generatorWrapper,
    config,
    allowedModelsStr,
    hasSelection
  ) {
    const visibility = getImagePickerVisibility(generatorWrapper);
    const currentProvider = getCurrentImageProvider(generatorWrapper);
    const editProviderFilter = visibility.showProvider ? "" : currentProvider;
    const hasAnyEditSupport =
      getPublicImageEntries(
        config,
        allowedModelsStr,
        "edit",
        editProviderFilter
      ).length > 0;
    const attachButton = generatorWrapper.querySelector(
      "#aipkit_public_image_attach_btn"
    );
    const generateButton = generatorWrapper.querySelector(
      "#aipkit_public_generate_image_btn"
    );
    const currentMode = getCurrentImageMode(generatorWrapper);

    if (attachButton) {
      attachButton.disabled = !hasAnyEditSupport;
      attachButton.setAttribute(
        "aria-disabled",
        hasAnyEditSupport ? "false" : "true"
      );
    }
    if (generateButton) {
      generateButton.dataset.modelAvailable = hasSelection ? "1" : "0";
    }
    if (!hasSelection) {
      setModeNotice(
        generatorWrapper,
        currentMode === "edit"
          ? config.text?.noEditCapableModels ||
              "No edit-capable models available."
          : config.text?.noModelsAvailable || "No models available."
      );
    } else {
      setModeNotice(generatorWrapper);
    }
    syncComposerSubmitState(generatorWrapper);
    return hasAnyEditSupport;
  }

  function syncEditModeAvailability(
    generatorWrapper,
    config,
    allowedModelsStr,
    preferredSelection = null
  ) {
    if (!generatorWrapper) {
      return;
    }
    const shortcodeMode = normalizeSelectionValue(
      generatorWrapper.dataset.imageMode || "generate"
    );
    let currentMode = getCurrentImageMode(generatorWrapper);
    const visibility = getImagePickerVisibility(generatorWrapper);
    const currentProvider = String(
      preferredSelection?.provider || getCurrentImageProvider(generatorWrapper)
    ).trim();
    const currentModel = String(
      preferredSelection?.model ||
        getImageSettingValue(
          generatorWrapper,
          "aipkit_public_image_model",
          "image_model"
        ) ||
        ""
    ).trim();
    const editProviderFilter = visibility.showProvider ? "" : currentProvider;
    const editEntries = getPublicImageEntries(
      config,
      allowedModelsStr,
      "edit",
      editProviderFilter
    );

    if (currentMode === "edit" && editEntries.length === 0 && shortcodeMode === "both") {
      applyImageModeUI(generatorWrapper, "generate");
      currentMode = "generate";
    }

    const sourceSelect = generatorWrapper.querySelector(
      "#aipkit_public_image_model_picker_source"
    );
    let selection = null;

    if (
      sourceSelect &&
      typeof window.aipkit_populatePublicImageModelPicker === "function"
    ) {
      selection = window.aipkit_populatePublicImageModelPicker(
        sourceSelect,
        config,
        {
          allowedModels: allowedModelsStr,
          mode: currentMode,
          provider: currentProvider,
          model: currentModel,
          showProvider: visibility.showProvider,
          showModel: visibility.showModel,
        }
      );
      applyPickerSelection(generatorWrapper, sourceSelect);
      syncPublicModelPickerDensity(generatorWrapper, sourceSelect);
      const controller =
        sourceSelect._aipkitPublicImagePickerController ||
        initializePublicModelPicker(generatorWrapper, sourceSelect);
      controller?.sync();
    } else {
      const providerFilter = visibility.showProvider ? "" : currentProvider;
      const entries = getPublicImageEntries(
        config,
        allowedModelsStr,
        currentMode,
        providerFilter
      );
      const exactEntry = findMatchingEntry(entries, currentProvider, currentModel);
      const fallbackEntry = currentModel && !visibility.showModel ? null :
        entries.find((entry) => String(entry.provider).toLowerCase() === currentProvider.toLowerCase()) || (!currentProvider ? entries[0] : null);
      const resolvedEntry = exactEntry || fallbackEntry;
      if (resolvedEntry) {
        setImageSelection(
          generatorWrapper,
          resolvedEntry.provider,
          resolvedEntry.modelValue
        );
        selection = resolvedEntry;
      } else {
        setImageSelection(
          generatorWrapper,
          visibility.showProvider ? "" : currentProvider,
          ""
        );
      }
    }

    generatorWrapper._aipkitImageAttachmentController?.syncConstraints?.();
    syncModeControlAvailability(
      generatorWrapper,
      config,
      allowedModelsStr,
      Boolean(selection?.provider && (selection.model || selection.modelValue))
    );
  }

  /**
   * Initializes the public image generator UI and event listeners.
   */
  function aipkit_initPublicImageGenerator() {
    const generatorWrapper = document.getElementById(
      "aipkit_public_image_generator"
    );
    if (!generatorWrapper) return;

    const generateButton = generatorWrapper.querySelector(
      "#aipkit_public_generate_image_btn"
    );
    const resultsContainer = generatorWrapper.querySelector(
      "#aipkit_public_image_results"
    );
    const modelPickerSource = generatorWrapper.querySelector(
      "#aipkit_public_image_model_picker_source"
    );
    const config = window.aipkit_image_generator_config_public || {};

    if (resultsContainer && typeof window.aipkitImageResultUI?.init === "function") {
      window.aipkitImageResultUI.init(resultsContainer);
    }

    if (!generateButton || !resultsContainer) {
      console.error(
        "AIPKit Image Generator (Public): Could not find essential UI elements (button, results)."
      );
      if (
        resultsContainer &&
        typeof window.aipkitImageResultUI?.renderError === "function"
      ) {
        window.aipkitImageResultUI.renderError(
          resultsContainer,
          config?.text?.coreUiMissing || "The generator could not be loaded."
        );
      }
      return;
    }

    observePublicModelPickerTheme(generatorWrapper);

    if (
      modelPickerSource &&
      modelPickerSource.dataset.publicImagePickerListenerAttached !== "true"
    ) {
      modelPickerSource.addEventListener("change", () => {
        const selection = applyPickerSelection(
          generatorWrapper,
          modelPickerSource
        );
        generatorWrapper._aipkitImageAttachmentController?.syncConstraints?.();
        syncModeControlAvailability(
          generatorWrapper,
          config,
          getAllowedModelsValue(generatorWrapper),
          Boolean(selection?.provider && selection?.model)
        );
      });
      modelPickerSource.dataset.publicImagePickerListenerAttached = "true";
    }

    if (generateButton.dataset.listenerAttached !== "true") {
      generateButton.addEventListener(
        "click",
        window.aipkit_handlePublicImageGeneration
      );
      generateButton.dataset.listenerAttached = "true";
    }

    const initialMode = resolveInitialImageMode(generatorWrapper);
    applyImageModeUI(generatorWrapper, initialMode);
    syncEditModeAvailability(
      generatorWrapper,
      config,
      getAllowedModelsValue(generatorWrapper)
    );
    initComposerAttachment(generatorWrapper, config);
    initComposerPrompt(generatorWrapper, generateButton);

    window.aipkit_initImageHistory?.(generatorWrapper);

    resultsContainer.style.removeProperty("display");
  }

  // DOM ready check and global exposure
  if (document.readyState === "loading") {
    document.addEventListener(
      "DOMContentLoaded",
      aipkit_initPublicImageGenerator
    );
  } else {
    aipkit_initPublicImageGenerator();
  }

  window.addEventListener("aipkit:model-sync-complete", () => {
    const generatorWrapper = document.getElementById("aipkit_public_image_generator");
    if (!generatorWrapper) return;
    // Refresh the catalog without resetting the prompt, attachment or current mode.
    syncEditModeAvailability(
      generatorWrapper,
      window.aipkit_image_generator_config_public || {},
      getAllowedModelsValue(generatorWrapper)
    );
  });

  window.aipkit_initPublicImageGenerator = aipkit_initPublicImageGenerator;
})();
