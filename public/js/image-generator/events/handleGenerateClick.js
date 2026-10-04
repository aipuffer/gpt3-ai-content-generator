(function () {
  "use strict";
  const pendingCloudRequests = new WeakMap();

  async function sendImageRequest(wrapper, data, refreshed = false) {
    const config = window.aipkit_image_generator_config_public || {};
    const response = await fetch(config.ajaxUrl || window.aipkit_dashboard?.ajaxurl, {
      method: "POST", body: data, credentials: "same-origin",
    });
    const json = await response.json();
    if (!refreshed && !json.success && json.data?.code === "nonce_failure") {
      const refresh = new FormData();
      refresh.append("action", "aipkit_image_nonce");
      refresh.append("selection_policy", wrapper.dataset.selectionPolicy || "");
      let fresh;
      try {
        fresh = await fetch(config.ajaxUrl || window.aipkit_dashboard?.ajaxurl, {
          method: "POST", body: refresh, credentials: "same-origin",
        }).then((result) => result.json());
      } catch (error) {
        return json; // The rejected request was never dispatched.
      }
      if (fresh.success && fresh.data?.nonce) {
        wrapper.querySelector("#aipkit_image_generator_public_nonce").value = fresh.data.nonce;
        data.set("_ajax_nonce", fresh.data.nonce);
        return sendImageRequest(wrapper, data, true);
      }
    }
    return json;
  }

  const OPENROUTER_IMAGE_CAPABILITY_DEFAULTS = {
    image_input: true,
    image_output: true,
    image_generation: true,
  };
  function aipkit_getOpenRouterImageCapabilities(config, modelId) {
    const models = Array.isArray(config?.openrouter_image_models)
      ? config.openrouter_image_models
      : [];
    const normalizedTarget = String(modelId || "").trim().toLowerCase();
    if (!normalizedTarget) {
      return OPENROUTER_IMAGE_CAPABILITY_DEFAULTS;
    }
    const matchedModel = models.find((model) => {
      const id = model && model.id ? String(model.id).trim().toLowerCase() : "";
      return id === normalizedTarget;
    });
    if (!matchedModel || typeof matchedModel.capabilities !== "object") {
      return OPENROUTER_IMAGE_CAPABILITY_DEFAULTS;
    }
    return {
      ...OPENROUTER_IMAGE_CAPABILITY_DEFAULTS,
      ...matchedModel.capabilities,
    };
  }

  function aipkit_openrouterModelSupportsImageGeneration(config, modelId) {
    const capabilities = aipkit_getOpenRouterImageCapabilities(config, modelId);
    return Boolean(capabilities.image_output || capabilities.image_generation);
  }

  function aipkit_openrouterModelSupportsImageEditing(config, modelId) {
    const capabilities = aipkit_getOpenRouterImageCapabilities(config, modelId);
    return Boolean(
      capabilities.image_input &&
        (capabilities.image_output || capabilities.image_generation)
    );
  }

  function aipkit_xaiModelSupportsImageEditing(config, modelId) {
    const models = Array.isArray(config?.xai_image_models)
      ? config.xai_image_models
      : [];
    const normalizedTarget = String(modelId || "").trim().toLowerCase();
    if (!normalizedTarget) {
      return false;
    }
    const matchedModel = models.find((model) => {
      const id = model && model.id ? String(model.id).trim().toLowerCase() : "";
      return id === normalizedTarget;
    });
    if (!matchedModel) {
      return true;
    }
    const outputModalities = Array.isArray(matchedModel.output_modalities)
      ? matchedModel.output_modalities.map((value) =>
          String(value || "").trim().toLowerCase()
        )
      : [];
    if (outputModalities.length > 0 && !outputModalities.includes("image")) {
      return false;
    }
    const inputModalities = Array.isArray(matchedModel.input_modalities)
      ? matchedModel.input_modalities.map((value) =>
          String(value || "").trim().toLowerCase()
        )
      : [];
    return inputModalities.length === 0 || inputModalities.includes("image");
  }

  function aipkit_openaiModelSupportsImageEditing(modelId) {
    const normalizedModel = String(modelId || "")
      .trim()
      .toLowerCase();
    return normalizedModel.startsWith("gpt-image");
  }

  function aipkit_googleModelSupportsImageEditing(modelId) {
    const normalizedModel = String(modelId || "")
      .trim()
      .toLowerCase();
    return (
      normalizedModel.includes("gemini") &&
      (normalizedModel.includes("image-generation") ||
        normalizedModel.includes("flash-image") ||
        normalizedModel.includes("pro-image"))
    );
  }

  function aipkit_setSpinnerVisibility(spinnerElement, isVisible) {
    if (!spinnerElement) {
      return;
    }
    spinnerElement.hidden = !isVisible;
  }

  function aipkit_renderResultError(
    resultsContainer,
    message,
    retry,
    options = {}
  ) {
    if (typeof window.aipkitImageResultUI?.renderError === "function") {
      window.aipkitImageResultUI.renderError(resultsContainer, message, {
        retry,
        retryable: options.retryable !== false,
        retryLabel: options.retryLabel,
      });
      return;
    }
    if (resultsContainer) {
      resultsContainer.textContent = String(message || "");
    }
  }

  function aipkit_renderResultsLoading(resultsContainer, options = {}) {
    window.aipkitImageResultUI?.renderLoading?.(resultsContainer, options);
  }

  function aipkit_renderQuotaNotice(resultsContainer, notice) {
    return Boolean(
      window.aipkitImageResultUI?.renderQuota?.(resultsContainer, notice)
    );
  }

  function aipkit_getResultMetadata(generatorWrapper, provider, model) {
    const sourceSelect = generatorWrapper.querySelector(
      "#aipkit_public_image_model_picker_source"
    );
    const matchingOption = sourceSelect
      ? Array.from(sourceSelect.options || []).find(
          (option) =>
            String(option.dataset.provider || "").toLowerCase() ===
              String(provider || "").toLowerCase() &&
            String(option.dataset.model || option.value || "") ===
              String(model || "")
        )
      : null;
    const currentMode = window.aipkit_getSettingValue?.(
      generatorWrapper,
      "aipkit_public_image_mode",
      "image_mode"
    );
    const matchingEntry =
      !matchingOption &&
      typeof window.aipkit_getPublicImageModelEntries === "function"
        ? window
            .aipkit_getPublicImageModelEntries(
              window.aipkit_image_generator_config_public || {},
              generatorWrapper.dataset.allowedModels || "",
              currentMode || "generate"
            )
            .find(
              (entry) =>
                String(entry.provider || "").toLowerCase() ===
                  String(provider || "").toLowerCase() &&
                String(entry.modelValue || "") === String(model || "")
            ) || null
        : null;
    const providerLabel = String(
      matchingOption?.dataset.providerLabel ||
        matchingEntry?.providerLabel ||
        provider ||
        ""
    ).trim();
    const modelLabel = String(
      matchingOption?.textContent || matchingEntry?.modelLabel || model || ""
    ).trim();
    const displayParts = [providerLabel, modelLabel].filter(Boolean);
    return {
      displayLabel: displayParts.join(" · "),
      modelLabel,
    };
  }

  function aipkit_restoreGenerateButton(
    generateButton,
    spinner
  ) {
    generateButton.dataset.generating = "false";
    generateButton.setAttribute("aria-busy", "false");
    generateButton.setAttribute(
      "aria-label",
      generateButton.dataset.actionLabel || "Generate"
    );
    aipkit_setSpinnerVisibility(spinner, false);
    const generatorWrapper = generateButton.closest(
      "#aipkit_public_image_generator"
    );
    const promptInput = generatorWrapper?.querySelector(
      "#aipkit_public_image_prompt"
    );
    const provider = generatorWrapper
      ? window.aipkit_getSettingValue(
          generatorWrapper,
          "aipkit_public_image_provider",
          "image_provider"
        )
      : "";
    const model = generatorWrapper
      ? window.aipkit_getSettingValue(
          generatorWrapper,
          "aipkit_public_image_model",
          "image_model"
        )
      : "";
    const canSubmit = Boolean(
      String(promptInput?.value || "").trim() && provider && model && generatorWrapper?.dataset.cloudRequestPending !== "true"
    );
    generateButton.disabled = !canSubmit;
    generateButton.classList.toggle("is-ready", canSubmit);
  }

  /**
   * Handles the image generation request via AJAX.
   */
  function aipkit_handlePublicImageGeneration(options = null) {
    const generatorWrapper = document.getElementById(
      "aipkit_public_image_generator"
    );
    if (!generatorWrapper) return;
    if (pendingCloudRequests.has(generatorWrapper)) {
      pendingCloudRequests.get(generatorWrapper)();
      return;
    }

    const promptInput = generatorWrapper.querySelector(
      "#aipkit_public_image_prompt"
    );
    const resultsContainer = generatorWrapper.querySelector(
      "#aipkit_public_image_results"
    );
    const generateButton = generatorWrapper.querySelector(
      "#aipkit_public_generate_image_btn"
    );
    const nonceInput = generatorWrapper.querySelector(
      "#aipkit_image_generator_public_nonce"
    );

    const config = window.aipkit_image_generator_config_public || {};
    const texts = config.text || {};
    const ajaxUrl = config.ajaxUrl || window.aipkit_dashboard?.ajaxurl;
    const nonce = nonceInput ? nonceInput.value : null;
    if (generateButton?.dataset.generating === "true") return;
    const retryRequest = options?.aipkitRetryRequest || null;
    const retryCurrent = () => aipkit_handlePublicImageGeneration();

    if (
      !promptInput ||
      !resultsContainer ||
      !generateButton ||
      !ajaxUrl ||
      !nonce
    ) {
      console.error(
        "AIPKit Image Gen (Public): Missing required elements or config for AJAX."
      );
      if (resultsContainer) {
        aipkit_renderResultError(
          resultsContainer,
          texts.configurationMissing || "The generator is not configured yet.",
          retryCurrent
        );
      }
      return;
    }

    const provider = retryRequest?.provider ||
      window.aipkit_getSettingValue(
        generatorWrapper,
        "aipkit_public_image_provider",
        "image_provider"
      );
    const model = retryRequest?.model ||
      window.aipkit_getSettingValue(
        generatorWrapper,
        "aipkit_public_image_model",
        "image_model"
      );
    const requestedMode = retryRequest?.mode ||
      window.aipkit_getSettingValue(
        generatorWrapper,
        "aipkit_public_image_mode",
        "image_mode"
      );
    const mode = requestedMode === "edit" ? "edit" : "generate";
    const sourceImageInput = generatorWrapper.querySelector(
      "#aipkit_public_image_edit_source_file"
    );
    const isEditMode = mode === "edit";
    const sourceFile = retryRequest?.sourceFile || sourceImageInput?.files?.[0] || null;
    const prompt = String(
      retryRequest?.prompt ?? promptInput.value
    ).trim();
    const providerLower = String(provider || "")
      .trim()
      .toLowerCase();
    const modelLower = String(model || "")
      .trim()
      .toLowerCase();
    
    // Check if this is a video model using synced list when available
    let isVideoModel = false;
    const googleModels = (config.google_models && typeof config.google_models === 'object') ? config.google_models : {};
    if (providerLower === 'google' && googleModels.video && Array.isArray(googleModels.video)) {
      isVideoModel = googleModels.video.some(
        (m) => m && String(m.id || "").trim().toLowerCase() === modelLower
      );
    } else {
      // Fallback heuristic
      isVideoModel = modelLower.includes('veo');
    }

    if (!prompt) {
      aipkit_renderResultError(
        resultsContainer,
        texts.noPrompt || "Enter a prompt before generating.",
        retryCurrent
      );
      return;
    }

    if (isEditMode) {
      const hasSourceFile = Boolean(
        sourceFile
      );
      const uploadRequiredMessage =
        generatorWrapper.dataset.editUploadRequired ||
        texts.editUploadRequired ||
        "Please upload an image to edit.";
      if (!hasSourceFile) {
        aipkit_renderResultError(
          resultsContainer,
          uploadRequiredMessage,
          retryCurrent
        );
        return;
      }

      const editUploadConstraints = window.aipkit_getImageEditUploadConstraints(
        config,
        providerLower, model
      );

      if (
        !editUploadConstraints.allowedMimeTypes.has(
          String(sourceFile.type || "").toLowerCase()
        )
      ) {
        aipkit_renderResultError(
          resultsContainer,
          editUploadConstraints.invalidTypeMessage,
          retryCurrent
        );
        return;
      }

      if (Number(sourceFile.size || 0) > editUploadConstraints.maxBytes) {
        aipkit_renderResultError(
          resultsContainer,
          editUploadConstraints.tooLargeMessage,
          retryCurrent
        );
        return;
      }
    }

    if (!provider || !model) {
      console.error(
        "AIPKit Image Gen (Public): Missing required settings values (provider or model).",
        { provider, model }
      );
      aipkit_renderResultError(
        resultsContainer,
        texts.missingRequiredSettings ||
          "Choose an available image model before generating.",
        retryCurrent
      );
      return;
    }

    if (
      !isEditMode &&
      providerLower === "openrouter" &&
      !aipkit_openrouterModelSupportsImageGeneration(config, modelLower)
    ) {
      aipkit_renderResultError(
        resultsContainer,
        texts.openrouterModelUnsupported ||
          "The selected model does not support image generation.",
        retryCurrent
      );
      return;
    }

    if (
      isEditMode &&
      providerLower !== "google" &&
      providerLower !== "openai" &&
      providerLower !== "openrouter" &&
      providerLower !== "aipuffercloud" &&
      providerLower !== "xai"
    ) {
      aipkit_renderResultError(
        resultsContainer,
        texts.editProviderUnsupported ||
          "The selected provider does not support image editing.",
        retryCurrent
      );
      return;
    }

    if (
      isEditMode &&
      ((providerLower === "google" &&
        (isVideoModel || !aipkit_googleModelSupportsImageEditing(modelLower))) ||
        (providerLower === "openai" &&
          !aipkit_openaiModelSupportsImageEditing(modelLower)) ||
        (providerLower === "openrouter" &&
          !aipkit_openrouterModelSupportsImageEditing(config, modelLower)) ||
        (providerLower === "aipuffercloud" && !modelLower.startsWith("aipuffer/image_edit/")) ||
        (providerLower === "xai" &&
          !aipkit_xaiModelSupportsImageEditing(config, modelLower)))
    ) {
      aipkit_renderResultError(
        resultsContainer,
        texts.editModelUnsupported ||
          "The selected model does not support image editing.",
        retryCurrent
      );
      return;
    }

    const requestSnapshot = { mode, model, prompt, provider, sourceFile };
    const operationId = providerLower === "aipuffercloud" ? "10000000-1000-4000-8000-100000000000".replace(/[018]/g, (digit) => (digit ^ (window.crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (digit / 4)))).toString(16)) : "";
    const retryGeneration = () =>
      aipkit_handlePublicImageGeneration({
        aipkitRetryRequest: requestSnapshot,
      });
    const resultMetadata = aipkit_getResultMetadata(
      generatorWrapper,
      provider,
      model
    );
    const generationFailureMessage = isVideoModel
      ? texts.videoGenerationFailedShort ||
        "We couldn't generate that video. Try again."
      : texts.error || "We couldn't generate that image. Try again.";

    generateButton.disabled = true;
    generateButton.dataset.generating = "true";
    generateButton.setAttribute("aria-busy", "true");
    const spinner = generateButton.querySelector(".aipkit_spinner");
    generateButton.classList.remove("is-ready");
    generateButton.setAttribute(
      "aria-label",
      isEditMode
        ? texts.editing || "Editing…"
        : isVideoModel
        ? texts.generatingVideo || "Generating video…"
        : texts.generating || "Generating…"
    );
    aipkit_setSpinnerVisibility(spinner, true);

    aipkit_renderResultsLoading(resultsContainer, {
      isVideo: isVideoModel,
      message: isVideoModel
        ? texts.videoGenerationInProgress || "Video generation in progress…"
        : texts.generating || "Generating…",
    });

    const requestData = new FormData();
    requestData.append("action", "aipkit_generate_image");
    requestData.append("_ajax_nonce", nonce);
    requestData.append("selection_policy", generatorWrapper.dataset.selectionPolicy || "");
    if (operationId) requestData.append("operation_id", operationId);
    requestData.append("prompt", prompt);
    requestData.append("image_mode", mode);
    requestData.append("provider", provider);
    requestData.append("model", model);
    if (window.aipkit_guest_uuid) {
      requestData.append("session_id", window.aipkit_guest_uuid);
    }

    if (isEditMode && sourceFile) requestData.append("source_image", sourceFile);

    const showCloudRecovery = () => {
      let checking = false;
      const checkStatus = async () => {
        if (checking) return;
        checking = true;
        const data = new FormData();
        data.append("action", "aipkit_image_request_status");
        data.append("_ajax_nonce", nonceInput.value);
        data.append("operation_id", operationId);
        try {
          const json = await sendImageRequest(generatorWrapper, data);
          if (!json.success) throw window.aipkit_createImageGenerationError(json);
          const terminal = ["settled", "released"].includes(json.data?.state);
          if (terminal) {
            pendingCloudRequests.delete(generatorWrapper);
            delete generatorWrapper.dataset.cloudRequestPending;
            aipkit_restoreGenerateButton(generateButton, spinner);
            aipkit_renderResultError(resultsContainer,
              json.data.state === "settled"
                ? texts.cloudImageSettled || "This request completed and used credits, but its image was not received. Check your image history. Generating again starts a new request."
                : texts.cloudImageFinished || "This request has finished. You can start a new image request.",
              retryGeneration, { retryLabel: texts.generateNewImage || "Generate new image" });
          } else {
            aipkit_renderResultError(resultsContainer,
              texts.cloudImagePending || "This request is still processing or needs review. Check again before generating another image.",
              checkStatus, { retryLabel: texts.checkStatus || "Check status" });
          }
        } catch (error) {
          aipkit_renderResultError(resultsContainer,
            error?.aipkitDisplayMessage || texts.cloudStatusUnavailable || "The request status could not be checked. Check again before starting another request.",
            checkStatus, { retryLabel: texts.checkStatus || "Check status" });
        } finally {
          checking = false;
        }
      };
      pendingCloudRequests.set(generatorWrapper, checkStatus);
      generatorWrapper.dataset.cloudRequestPending = "true";
      aipkit_renderResultError(resultsContainer,
        texts.cloudImageUnknown || "The image response was interrupted. This request may have used credits. Check its status before generating again.",
        checkStatus, { retryLabel: texts.checkStatus || "Check status" });
    };

    sendImageRequest(generatorWrapper, requestData)
      .then((json) => {
        if (!json.success) {
          const quotaNotice =
            json.data && typeof json.data === "object"
              ? json.data.quota_notice
              : null;
          if (quotaNotice && aipkit_renderQuotaNotice(resultsContainer, quotaNotice)) {
            aipkit_restoreGenerateButton(
              generateButton,
              spinner
            );
            return;
          }
          const errorCode =
            json.data && typeof json.data === "object"
              ? String(json.data.code || "")
              : "";
          if (errorCode === "token_limit_exceeded_guest_logic") {
            aipkit_renderResultError(
              resultsContainer,
              texts.guestAccessDisabled ||
                "Image generation is not available for guests.",
              null,
              { retryable: false }
            );
            aipkit_restoreGenerateButton(generateButton, spinner);
            return;
          }
          throw window.aipkit_createImageGenerationError(
            json,
            generationFailureMessage
          );
        }
        
        // Check if this is an async video operation
        if (json.data.status === 'processing' && json.data.operation_name) {
          pollVideoStatus(json.data.operation_name, {
            generateButton,
            isVideoModel,
            generatorWrapper,
            prompt,
            resultMetadata,
            resultsContainer,
            retryGeneration,
            spinner,
            texts,
          });
          return;
        }
        
        handleCompletedGeneration(json.data, {
          generateButton,
          isVideoModel,
          prompt,
          resultMetadata,
          resultsContainer,
          retryGeneration,
          spinner,
          texts,
        });
      })
      .catch((error) => {
        console.error("AIPKit Image Generation Error (Public):", error);
        const uncertain = !error?.aipkitCode || ["cloud_outcome_unknown", "cloud_request_failed", "already_dispatched_or_final", "gateway_aborted", "gateway_response_unverified", "cloud_image_invalid_response"].includes(error.aipkitCode);
        if (operationId && uncertain) {
          showCloudRecovery();
        } else {
          aipkit_renderResultError(resultsContainer, error?.aipkitDisplayMessage || generationFailureMessage, retryGeneration);
        }
        aipkit_restoreGenerateButton(
          generateButton,
          spinner
        );
      });
  }

  /**
   * Polls the video generation status until completion.
   */
  function pollVideoStatus(operationName, context) {
    const {
      generateButton,
      isVideoModel,
      generatorWrapper,
      prompt,
      resultMetadata,
      resultsContainer,
      retryGeneration,
      spinner,
      texts,
    } = context;
    const maxPolls = 60; // Maximum number of polls
    const pollInterval = 5000; // 5 seconds between polls
    let pollCount = 0;
    
    // Update UI to show polling status
    aipkit_renderResultsLoading(
      resultsContainer,
      {
        isVideo: true,
        message:
          texts.videoGenerationInProgress ||
          "Video generation in progress…",
      }
    );
    
    const retryStatus = () => { pollCount = 0; doPoll(); };
    function doPoll() {
      if (!generatorWrapper.isConnected) return;
      if (pollCount >= maxPolls) {
        // Timeout
        aipkit_renderResultError(
          resultsContainer,
          texts.videoGenerationTimedOut || "The video is taking longer than expected. Check its status before starting another video.",
          retryStatus, { retryLabel: texts.checkStatus || "Check status" }
        );
        aipkit_restoreGenerateButton(
          generateButton,
          spinner
        );
        return;
      }
      
      pollCount++;

      // Update UI with progress
      const progress = Math.min(pollCount / maxPolls * 100, 95); // Max 95% until completion
      aipkit_renderResultsLoading(
        resultsContainer,
        {
          isVideo: true,
          message: `${
            texts.generatingVideoProgress || "Generating video…"
          } (${Math.round(progress)}%)`,
        }
      );
      
      const pollData = new FormData();
      pollData.append("action", "aipkit_check_video_status");
      pollData.append("_ajax_nonce", generatorWrapper.querySelector("#aipkit_image_generator_public_nonce").value);
      pollData.append("operation_name", operationName);
      sendImageRequest(generatorWrapper, pollData)
        .then((json) => {
          if (json.success) {
            if (json.data.status === 'completed') {
              handleCompletedGeneration(json.data, {
                generateButton,
                isVideoModel,
                prompt,
                resultMetadata,
                resultsContainer,
                retryGeneration,
                spinner,
                texts,
              });
            } else if (json.data.status === 'processing') {
              // Still processing, continue polling
              setTimeout(doPoll, pollInterval);
            } else {
              // Unknown status
              throw new Error(`Unknown status: ${json.data.status}`);
            }
          } else {
            throw window.aipkit_createImageGenerationError(
              json,
              texts.videoGenerationFailedShort ||
                "We couldn't generate that video. Try again."
            );
          }
        })
        .catch((error) => {
          console.error("AIPKit Video Status Check Error:", error);
          aipkit_renderResultError(
            resultsContainer,
            error?.aipkitDisplayMessage ||
              texts.videoGenerationFailedShort ||
              "We couldn't check that video. Check its status again.",
            retryStatus, { retryLabel: texts.checkStatus || "Check status" }
          );
          aipkit_restoreGenerateButton(
            generateButton,
            spinner
          );
        });
    }
    
    // Start polling after a short delay
    setTimeout(doPoll, pollInterval);
  }

  /**
   * Handles the completion of image/video generation and displays results.
   */
  function handleCompletedGeneration(data, context) {
    const {
      generateButton,
      isVideoModel,
      prompt,
      resultMetadata,
      resultsContainer,
      retryGeneration,
      spinner,
      texts,
    } = context;
    const mediaItems = data.images || data.videos || [];
    if (mediaItems.length > 0) {
      window.aipkitImageResultUI?.renderSuccess?.(resultsContainer, mediaItems, {
        displayLabel: resultMetadata.displayLabel,
        isVideo: isVideoModel,
        modelLabel: resultMetadata.modelLabel,
        prompt,
        retry: retryGeneration,
      });
      const generatorWrapper = resultsContainer.closest(
        "#aipkit_public_image_generator"
      );
      window.aipkit_refreshImageHistory?.(generatorWrapper);
    } else {
      aipkit_renderResultError(
        resultsContainer,
        texts.noMediaReturned || "No media was returned. Try again.",
        retryGeneration
      );
    }

    aipkit_restoreGenerateButton(
      generateButton,
      spinner
    );
  }

  window.aipkit_handlePublicImageGeneration =
    aipkit_handlePublicImageGeneration;
})();
