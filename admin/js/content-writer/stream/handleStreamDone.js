/**
 * AIPKit Content Writer - Done Event Handler
 * Handles the custom 'done' event from the stream.
 * UPDATED: Checks for and populates the meta_description textarea.
 * MODIFIED: Consolidated multiple image generation requests into a single AJAX call to ensure unique images are fetched from stock providers like Pexels and Pixabay. Provides batch progress feedback.
 */
(function () {
  "use strict";

  /**
   * Processes the 'done' event from the SSE stream.
   * @param {Event} event - The 'done' event.
   * @param {object} state - An object containing state: { fullContent: string, isDone: boolean }.
   * @param {HTMLElement} contentArea - The element to display the content in.
   * @param {object} markdownRenderer - The markdown-it instance.
   * @param {HTMLElement} statusDiv - The status message element.
   * @param {object} esInstanceRef - The reference to the EventSource instance.
   */
  async function aipkit_stream_handleDone( // Made async
    event,
    state,
    contentArea,
    markdownRenderer,
    statusDiv,
    esInstanceRef
  ) {
    if (state.isDone) {
      return;
    }

    state.isDone = true;
    const isAbortError =
      typeof window.aipkit_isContentWriterAbortError === "function"
        ? window.aipkit_isContentWriterAbortError
        : function (error) {
            return Boolean(
              error && (error.name === "AbortError" || error.code === "aborted")
            );
          };
    const isStopRequested = function () {
      return (
        typeof window.aipkit_isContentWriterStopRequested === "function" &&
        window.aipkit_isContentWriterStopRequested()
      );
    };
    const stopIfRequested = function () {
      if (!isStopRequested()) {
        return false;
      }

      if (typeof window.aipkit_finalizeStream === "function") {
        window.aipkit_finalizeStream(esInstanceRef);
      }
      return true;
    };
    const createStopAbortError = function () {
      const error = new Error("Generation stopped");
      error.name = "AbortError";
      error.code = "aborted";
      return error;
    };
    const isLongRunningRequestTimeout = function (error) {
      return Boolean(
        error &&
          (error.code === "gateway_timeout" ||
            error.code === "timeout" ||
            error.status === 504 ||
            error.isGatewayTimeout)
      );
    };
    const getLongRunningImageMessage = function () {
      return "Image generation is taking longer than usual. The article is ready, and any completed images were kept.";
    };
    const IMAGE_RECOVERY_MAX_ATTEMPTS = 80;
    const IMAGE_RECOVERY_POLL_DELAY_MS = 3000;
    const createImageRequestId = function () {
      if (window.crypto && typeof window.crypto.randomUUID === "function") {
        return `cw_img_${window.crypto.randomUUID().replace(/-/g, "")}`;
      }
      return `cw_img_${Date.now().toString(36)}_${Math.random()
        .toString(36)
        .slice(2, 12)}`;
    };
    const waitForImageRecoveryPoll = function (delayMs) {
      return new Promise((resolve) => {
        window.setTimeout(resolve, delayMs);
      });
    };
    let providerStopError = null;
    const runAbortableRequest = async (step, action, payload, requestOptions = {}) => {
      if (isStopRequested()) {
        throw createStopAbortError();
      }
      if (providerStopError) throw providerStopError;

      const controller =
        typeof window.aipkit_beginContentWriterAbortableRequest === "function"
          ? window.aipkit_beginContentWriterAbortableRequest(step)
          : new AbortController();

      try {
        return await window.aipkit_apiRequest(action, payload, {
          ...requestOptions,
          signal: controller.signal,
          abortMessage: "Generation stopped",
        });
      } catch (error) {
        if (error?.details?.stop_batch) providerStopError = error;
        throw error;
      } finally {
        if (
          typeof window.aipkit_finishContentWriterAbortableRequest ===
          "function"
        ) {
          window.aipkit_finishContentWriterAbortableRequest(controller);
        } else if (window.aipkit_cw_singleRequestController === controller) {
          window.aipkit_cw_singleRequestController = null;
        }
      }
    };
    const getVectorRequestData = function (form) {
      const enableVectorCheckbox = form.elements["enable_vector_store"];
      const openaiVsSelect = form.elements["openai_vector_store_ids[]"];
      const googleStoresSelect = form.elements["google_file_search_store_names[]"];

      return {
        enable_vector_store:
          enableVectorCheckbox && enableVectorCheckbox.checked ? "1" : "0",
        vector_store_provider: form.elements["vector_store_provider"]?.value,
        openai_vector_store_ids: openaiVsSelect
          ? Array.from(openaiVsSelect.selectedOptions).map((opt) => opt.value)
          : [],
        google_file_search_store_names: googleStoresSelect
          ? Array.from(googleStoresSelect.selectedOptions).map((option) => option.value)
          : [],
        pinecone_index_name: form.elements["pinecone_index_name"]?.value,
        qdrant_collection_name:
          form.elements["qdrant_collection_name"]?.value,
        chroma_collection_name:
          form.elements["chroma_collection_name"]?.value,
        local_store_id: form.elements["local_store_id"]?.value,
        vector_store_top_k: form.elements["vector_store_top_k"]?.value,
        vector_store_confidence_threshold:
          form.elements["vector_store_confidence_threshold"]?.value,
        vector_embedding_provider:
          form.elements["vector_embedding_provider"]?.value,
        vector_embedding_model:
          form.elements["vector_embedding_model"]?.value,
      };
    };
    const runSeoAuditAfterStreamingGeneration = async (form) => {
      if (providerStopError) return true;
      const manager = window.aipkit_contentWriterSeoAudit;
      if (!manager || typeof manager.runAudit !== "function") {
        return true;
      }

      const enabled =
        typeof manager.isEnabled === "function" && manager.isEnabled(form);
      if (!enabled) {
        return true;
      }

      if (typeof window.aipkit_updateCwGenerationStatus === "function") {
        window.aipkit_updateCwGenerationStatus("seo", "generating", "Auditing...");
      }

      try {
        const audit = await manager.runAudit();
        if (isStopRequested()) {
          if (typeof window.aipkit_updateCwGenerationStatus === "function") {
            window.aipkit_updateCwGenerationStatus("seo", "skipped", "Stopped");
          }
          stopIfRequested();
          return false;
        }

        if (audit && typeof window.aipkit_updateCwGenerationStatus === "function") {
          const auditState =
            typeof manager.getState === "function" ? manager.getState() : null;
          const versions = Array.isArray(auditState?.draftVersions)
            ? auditState.draftVersions
            : [];
          const originalAudit = versions.find(
            (version) => version?.id === "original"
          )?.audit;
          const normalizeScore = (value) => {
            const score = Number.parseInt(value?.score, 10);
            return Number.isFinite(score)
              ? Math.max(0, Math.min(score, 100))
              : null;
          };
          const originalScore = normalizeScore(originalAudit);
          const finalScore = normalizeScore(audit);
          const scoreDetail =
            originalScore !== null &&
            finalScore !== null &&
            originalScore !== finalScore
              ? `${originalScore} → ${finalScore}`
              : finalScore !== null
                ? `Score ${finalScore}`
                : "";

          window.aipkit_updateCwGenerationStatus(
            "seo",
            "success",
            scoreDetail
          );
        } else if (typeof window.aipkit_updateCwGenerationStatus === "function") {
          window.aipkit_updateCwGenerationStatus("seo", "skipped");
        }
      } catch (error) {
        if (isAbortError(error) && isStopRequested()) {
          if (typeof window.aipkit_updateCwGenerationStatus === "function") {
            window.aipkit_updateCwGenerationStatus("seo", "skipped", "Stopped");
          }
          stopIfRequested();
          return false;
        }

        console.error("Content Writer SEO audit error:", error);
        if (typeof window.aipkit_updateCwGenerationStatus === "function") {
          window.aipkit_updateCwGenerationStatus(
            "seo",
            "error",
            error?.message || "Audit failed"
          );
        }
      }

      return true;
    };

    // Close the EventSource immediately to avoid reconnects overwriting status
    if (esInstanceRef && esInstanceRef.current) {
      esInstanceRef.current.close();
      esInstanceRef.current = null;
    }

    if (
      typeof window.aipkit_flushContentWriterStreamingPreview === "function"
    ) {
      window.aipkit_flushContentWriterStreamingPreview(
        state,
        contentArea,
        markdownRenderer
      );
    } else if (state.pendingRenderHandle != null) {
      window.clearTimeout(state.pendingRenderHandle);
      state.pendingRenderHandle = null;
    }

    // Remove streaming animation class
    if (contentArea.classList.contains('is-streaming')) {
      contentArea.classList.remove('is-streaming');
    }
    if (
      typeof window.aipkit_finishContentWriterStreamFollow === "function"
    ) {
      window.aipkit_finishContentWriterStreamFollow();
    }

    if (
      !markdownRenderer &&
      typeof window.aipkit_getMarkdownRenderer === "function"
    ) {
      markdownRenderer = window.aipkit_getMarkdownRenderer();
    }

    if (markdownRenderer) {
      contentArea.innerHTML = markdownRenderer.render(state.fullContent);
    }
    if (typeof window.aipkit_setContentWriterRawHtml === "function") {
      window.aipkit_setContentWriterRawHtml(contentArea.innerHTML);
    }

    if (typeof window.aipkit_updateContentWriterPreviewCounter === "function") {
      window.aipkit_updateContentWriterPreviewCounter();
    }

    if (typeof window.aipkit_updateCwGenerationStatus === "function") {
      window.aipkit_updateCwGenerationStatus("content", "success");
    }
    if (typeof window.aipkit_scrollContentWriterPreviewToTop === "function") {
      window.aipkit_scrollContentWriterPreviewToTop();
    }
    if (typeof window.aipkit_setContentWriterCanvasState === "function") {
      window.aipkit_setContentWriterCanvasState("ready", {
        hasContent: true,
      });
    }
    if (stopIfRequested()) {
      return;
    }

    const form = document.getElementById("aipkit_content_writer_form");
    const generateMetaCheckbox = form
      ? form.elements["generate_meta_description"]
      : null;
    const generateKeywordCheckbox = form
      ? form.elements["generate_focus_keyword"]
      : null;
    const generateExcerptCheckbox = form
      ? form.elements["generate_excerpt"]
      : null;
    const generateTagsCheckbox = form ? form.elements["generate_tags"] : null;

    const nonce = document.getElementById("aipkit_content_writer_nonce")?.value;
    const provider = form.elements["ai_provider"]?.value;
    const model = form.elements["ai_model"]?.value;
    const promptMode =
      form.querySelector('input[name="prompt_mode"]:checked')?.value ||
      "custom";
    const titleDisplay = document.getElementById(
      "aipkit_cw_generated_title_display"
    );
    const finalTitle = titleDisplay
      ? titleDisplay.textContent
      : form.elements["content_title"]?.value.split("|")[0].trim() ||
        "Untitled";
    const globalKeywords =
      form.elements["content_keywords"]?.value.trim() || "";
    let inlineKeywords = "";
    const titleInput = document.getElementById("aipkit_content_writer_title");
    if (titleInput) {
      const titleParts = titleInput.value.split("|") || [];
      if (titleParts.length > 1) {
        inlineKeywords = titleParts[1].trim();
      }
    }
    const userProvidedKeywords = inlineKeywords || globalKeywords;
    let keywordsForPrompts = userProvidedKeywords;

    if (
      generateKeywordCheckbox?.checked &&
      !userProvidedKeywords &&
      state.fullContent.trim() !== ""
    ) {
      if (typeof window.aipkit_updateCwGenerationStatus === "function") {
        window.aipkit_updateCwGenerationStatus("keyword", "generating");
      }
      try {
        const rawSeoConfig = {
          seo_score_improvement_enabled: form.elements[
            "seo_score_improvement_enabled"
          ]?.checked
            ? "1"
            : "0",
          seo_score_continue_until_target: "1",
          seo_score_target: "100",
          seo_score_max_passes: "3",
          seo_score_profile: "auto",
          seo_score_disabled_rules:
            form.elements["seo_score_disabled_rules"]?.value ||
            (typeof window.aipkit_getDefaultSmartSeoDisabledRules ===
            "function"
              ? window.aipkit_getDefaultSmartSeoDisabledRules()
              : "[]"),
        };
        const seoConfig =
          typeof window.aipkit_normalizeContentWriterSeoConfig === "function"
            ? window.aipkit_normalizeContentWriterSeoConfig(rawSeoConfig)
            : rawSeoConfig;

        const keywordRequestData = {
          generated_content: state.fullContent,
          final_title: finalTitle,
          provider: provider,
          model: model,
          prompt_mode: promptMode,
          custom_keyword_prompt: form.elements["custom_keyword_prompt"].value,
          ...getVectorRequestData(form),
          ...seoConfig,
          conversation_uuid: window.aipkit_current_conversation_uuid || undefined,
          _ajax_nonce: nonce,
        };
        const keywordResponse = await runAbortableRequest(
          "keyword",
          "aipkit_content_writer_generate_focus_keyword",
          keywordRequestData
        );
        if (keywordResponse && keywordResponse.focus_keyword) {
          keywordsForPrompts = keywordResponse.focus_keyword;
          const focusKeywordInput = document.getElementById(
            "aipkit_cw_generated_focus_keyword"
          );
          const keywordWrapper = document.getElementById(
            "aipkit_cw_focus_keyword_output_wrapper"
          );
          if (focusKeywordInput && keywordWrapper) {
            if (typeof window.aipkit_revealContentWriterMetaField === "function") {
              window.aipkit_revealContentWriterMetaField(
                "aipkit_cw_focus_keyword_output_wrapper",
                "aipkit_cw_generated_focus_keyword",
                keywordsForPrompts
              );
            } else {
              focusKeywordInput.value = keywordsForPrompts;
              keywordWrapper.style.display = "flex";
            }
          }
          if (typeof window.aipkit_updateCwGenerationStatus === "function") {
            window.aipkit_updateCwGenerationStatus("keyword", "success");
          }
        } else {
          throw new Error("AI did not return a focus keyword.");
        }
      } catch (e) {
        if (isAbortError(e) && stopIfRequested()) {
          return;
        }
        if (typeof window.aipkit_updateCwGenerationStatus === "function") {
          window.aipkit_updateCwGenerationStatus(
            "keyword",
            "error",
            e.message || "Failed"
          );
        }
      }
    } else if (userProvidedKeywords) {
      const focusKeywordInput = document.getElementById(
        "aipkit_cw_generated_focus_keyword"
      );
      const keywordWrapper = document.getElementById(
        "aipkit_cw_focus_keyword_output_wrapper"
      );
      if (focusKeywordInput && keywordWrapper) {
        if (typeof window.aipkit_revealContentWriterMetaField === "function") {
          window.aipkit_revealContentWriterMetaField(
            "aipkit_cw_focus_keyword_output_wrapper",
            "aipkit_cw_generated_focus_keyword",
            userProvidedKeywords.split(",")[0].trim()
          );
        } else {
          focusKeywordInput.value = userProvidedKeywords.split(",")[0].trim();
          keywordWrapper.style.display = "flex";
        }
      }
      if (typeof window.aipkit_updateCwGenerationStatus === "function") {
        window.aipkit_updateCwGenerationStatus("keyword", "success");
      }
    } else {
      if (typeof window.aipkit_updateCwGenerationStatus === "function") {
        window.aipkit_updateCwGenerationStatus("keyword", "skipped");
      }
    }

    // --- START: Single Image Generation Call ---
    const generateImagesCheckbox = form
      ? form.elements["generate_images_enabled"]
      : null;
    const generateFeaturedCheckbox = form
      ? form.elements["generate_featured_image"]
      : null;
    const shouldGenerateAnyImage =
      (generateImagesCheckbox?.checked || generateFeaturedCheckbox?.checked) &&
      state.fullContent.trim() !== "";

    if (shouldGenerateAnyImage && !providerStopError) {
      const inContentEnabled = generateImagesCheckbox?.checked;
      const featuredEnabled = generateFeaturedCheckbox?.checked;
      const imageProvider = String(
        form.elements["image_provider"]?.value || ""
      ).toLowerCase();
      const imageModel = String(
        form.elements["image_model"]?.value || ""
      ).toLowerCase();
      const isGptImageModel =
        imageProvider === "openai" && imageModel.startsWith("gpt-image");
      const imageCount = inContentEnabled
        ? parseInt(form.elements["image_count"]?.value, 10) || 1
        : 0;
      const excerptInput = document.getElementById(
        "aipkit_cw_generated_excerpt"
      );
      if (typeof window.aipkit_syncImageProviderOptions === "function") {
        window.aipkit_syncImageProviderOptions(form);
      }
      const totalImagesToGenerate = imageCount + (featuredEnabled ? 1 : 0);
      const baseProgressMessage = (() => {
        if (inContentEnabled && featuredEnabled) {
          if (imageCount > 1) {
            return `Generating ${imageCount} content images and 1 featured image...`;
          }
          return "Generating 1 content image and 1 featured image...";
        }
        if (featuredEnabled) {
          return "Generating featured image...";
        }
        if (inContentEnabled) {
          return imageCount > 1
            ? `Generating ${imageCount} content images...`
            : "Generating content image...";
        }
        if (totalImagesToGenerate > 1) {
          return `Generating ${totalImagesToGenerate} images...`;
        }
        if (totalImagesToGenerate === 1) {
          return "Generating 1 image...";
        }
        return "Generating images...";
      })();

      const imageRequestData = {
        original_topic: titleInput
          ? titleInput.value.split("|")[0].trim()
          : finalTitle,
        final_title: finalTitle,
        post_title: finalTitle,
        keywords: keywordsForPrompts,
        excerpt: excerptInput ? excerptInput.value : "",
        ai_provider: form.elements["ai_provider"]?.value,
        ai_model: form.elements["ai_model"]?.value,
        ai_temperature: form.elements["ai_temperature"]?.value,
        reasoning_effort: form.elements["reasoning_effort"]?.value,
        image_provider: form.elements["image_provider"]?.value,
        image_model: form.elements["image_model"]?.value,
        image_provider_options:
          form.elements["image_provider_options"]?.value || "{}",
        image_prompt: form.elements["image_prompt"]?.value,
        image_alignment: form.elements["image_alignment"]?.value,
        image_size: form.elements["image_size"]?.value,
        featured_image_prompt: form.elements["featured_image_prompt"]?.value,
        generate_image_title: form.elements["generate_image_title"]?.checked
          ? "1"
          : "0",
        generate_image_alt_text: form.elements["generate_image_alt_text"]?.checked
          ? "1"
          : "0",
        generate_image_caption: form.elements["generate_image_caption"]?.checked
          ? "1"
          : "0",
        generate_image_description:
          form.elements["generate_image_description"]?.checked ? "1" : "0",
        image_title_prompt: form.elements["image_title_prompt"]?.value,
        image_alt_text_prompt: form.elements["image_alt_text_prompt"]?.value,
        image_caption_prompt: form.elements["image_caption_prompt"]?.value,
        image_description_prompt:
          form.elements["image_description_prompt"]?.value,
        pexels_orientation: form.elements["pexels_orientation"]?.value,
        pexels_size: form.elements["pexels_size"]?.value,
        pexels_color: form.elements["pexels_color"]?.value,
        pixabay_orientation: form.elements["pixabay_orientation"]?.value,
        pixabay_image_type: form.elements["pixabay_image_type"]?.value,
        pixabay_category: form.elements["pixabay_category"]?.value,
        _ajax_nonce: nonce,
        generate_images_enabled: inContentEnabled ? "1" : "0",
        image_count: imageCount,
        generate_featured_image: featuredEnabled ? "1" : "0",
        image_placement: form.elements["image_placement"]?.value,
        image_placement_param_x:
          form.elements["image_placement_param_x"]?.value,
        cw_generation_mode: form.elements["cw_generation_mode"]?.value,
        conversation_uuid: window.aipkit_current_conversation_uuid || undefined,
      };

      const applyImageData = (imageData) => {
        const imageDataHolder = document.getElementById(
          "aipkit_cw_image_data_holder"
        );
        if (imageDataHolder) {
          imageDataHolder.value = JSON.stringify(imageData);
        }
        if (
          typeof window.aipkit_renderContentWriterImagePreview === "function"
        ) {
          window.aipkit_renderContentWriterImagePreview(imageData);
        }
      };

      const requestImages = async (overrides = {}, statusMessage = "") => {
        const imageRequestId = overrides.image_request_id || createImageRequestId();
        const requestPayload = {
          ...imageRequestData,
          ...overrides,
          image_request_id: imageRequestId,
        };
        const pollImageRequest = async () => {
          if (typeof window.aipkit_updateCwGenerationStatus === "function") {
            window.aipkit_updateCwGenerationStatus(
              "image",
              "generating",
              "Image generation is taking longer. Still checking…"
            );
          }
          for (let attempt = 0; attempt < IMAGE_RECOVERY_MAX_ATTEMPTS; attempt++) {
            await waitForImageRecoveryPoll(
              attempt === 0 ? 2500 : IMAGE_RECOVERY_POLL_DELAY_MS
            );
            const pollResponse = await runAbortableRequest(
              "image",
              "aipkit_content_writer_generate_images",
              {
                ...requestPayload,
                image_request_poll: "1",
              },
              {
                timeout: 30000,
              }
            );
            if (pollResponse?.image_data) {
              return pollResponse.image_data;
            }
            if (
              pollResponse?.image_status &&
              !["running", "missing"].includes(pollResponse.image_status)
            ) {
              throw new Error("Image generation failed or returned no data.");
            }
          }
          const timeoutError = new Error(getLongRunningImageMessage());
          timeoutError.code = "gateway_timeout";
          timeoutError.isGatewayTimeout = true;
          throw timeoutError;
        };
        const normalizeImageResponse = async (imageResponse) => {
          if (imageResponse?.image_data) {
            return imageResponse.image_data;
          }
          if (imageResponse?.image_status === "running") {
            return pollImageRequest();
          }
          throw new Error("Image generation failed or returned no data.");
        };
        if (
          typeof window.aipkit_updateCwGenerationStatus === "function" &&
          statusMessage
        ) {
          window.aipkit_updateCwGenerationStatus(
            "image",
            "generating",
            statusMessage
          );
        }
        try {
          const imageResponse = await runAbortableRequest(
            "image",
            "aipkit_content_writer_generate_images",
            requestPayload,
            {
              timeout: isGptImageModel ? 240000 : 180000,
            }
          );
          return normalizeImageResponse(imageResponse);
        } catch (error) {
          if (isLongRunningRequestTimeout(error)) {
            return pollImageRequest();
          }
          throw error;
        }
      };

      window.aipkit_regenerateContentWriterImage = async ({
        type = "inline",
        index = 0,
      } = {}) => {
        providerStopError = null;
        const imageDataHolder = document.getElementById(
          "aipkit_cw_image_data_holder"
        );
        let currentImageData = {};
        if (imageDataHolder?.value) {
          try {
            currentImageData = JSON.parse(imageDataHolder.value) || {};
          } catch (error) {
            currentImageData = {};
          }
        }

        if (type === "featured") {
          if (
            typeof window.aipkit_showContentWriterFeaturedImageLoading ===
            "function"
          ) {
            window.aipkit_showContentWriterFeaturedImageLoading();
          }
          const regenerated = await requestImages({
            generate_images_enabled: "0",
            image_count: 0,
            generate_featured_image: "1",
          });
          const featuredKeys = [
            "featured_image_id",
            "featured_image_url",
            "featured_image_alt",
            "featured_image_title",
            "featured_image_caption",
            "featured_image_description",
            "featured_image",
            "featured_image_data",
          ];
          featuredKeys.forEach((key) => {
            if (Object.prototype.hasOwnProperty.call(regenerated, key)) {
              currentImageData[key] = regenerated[key];
            }
          });
        } else {
          const regenerated = await requestImages({
            generate_images_enabled: "1",
            generate_featured_image: "0",
            image_count: 1,
            image_start_index: Number(index) + 1,
          });
          const replacement = Array.isArray(regenerated.in_content_images)
            ? regenerated.in_content_images[0]
            : null;
          if (!replacement) {
            throw new Error("Image generation returned no content image.");
          }
          const inlineImages = Array.isArray(
            currentImageData.in_content_images
          )
            ? currentImageData.in_content_images.slice()
            : [];
          if (!inlineImages[index]) {
            throw new Error("The selected content image is no longer available.");
          }
          inlineImages[index] = replacement;
          currentImageData.in_content_images = inlineImages;
        }

        applyImageData(currentImageData);
        return currentImageData;
      };

      let mergedImageData = null;
      try {
        const shouldSplitRequests = inContentEnabled && featuredEnabled;
        const shouldSplitInlineImages =
          isGptImageModel && inContentEnabled && imageCount > 1;

        const ensureMergedBase = (sourceData) => {
          if (!mergedImageData) {
            mergedImageData = {
              ...sourceData,
              in_content_images: [],
              featured_image_id: null,
              featured_image_url: null,
            };
          }
        };
        if (shouldSplitRequests) {
          if (inContentEnabled && imageCount > 0) {
            if (shouldSplitInlineImages && imageCount > 1) {
              for (let i = 0; i < imageCount; i++) {
                const inlineData = await requestImages(
                  {
                    generate_featured_image: "0",
                    image_count: 1,
                    image_start_index: i + 1,
                  },
                  `Generating content image ${i + 1} of ${imageCount}...`
                );
                ensureMergedBase(inlineData);
                if (Array.isArray(inlineData.in_content_images)) {
                  mergedImageData.in_content_images.push(
                    ...inlineData.in_content_images
                  );
                }
                applyImageData(mergedImageData);
              }
            } else {
              const inlineData = await requestImages(
                { generate_featured_image: "0" },
                imageCount > 1
                  ? `Generating ${imageCount} content images...`
                  : "Generating content image..."
              );
              ensureMergedBase(inlineData);
              mergedImageData.in_content_images =
                inlineData.in_content_images || [];
              applyImageData(mergedImageData);
            }
          }

          if (featuredEnabled) {
            if (
              typeof window.aipkit_showContentWriterFeaturedImageLoading ===
              "function"
            ) {
              window.aipkit_showContentWriterFeaturedImageLoading();
            }
            const featuredData = await requestImages(
              { generate_images_enabled: "0", image_count: 0 },
              "Generating featured image..."
            );
            ensureMergedBase(featuredData);
            [
              "featured_image_id",
              "featured_image_url",
              "featured_image_alt",
              "featured_image_title",
              "featured_image_caption",
              "featured_image_description",
              "featured_image",
              "featured_image_data",
            ].forEach((key) => {
              if (Object.prototype.hasOwnProperty.call(featuredData, key)) {
                mergedImageData[key] = featuredData[key];
              }
            });
          }
        } else {
          if (typeof window.aipkit_updateCwGenerationStatus === "function") {
            window.aipkit_updateCwGenerationStatus(
              "image",
              "generating",
              baseProgressMessage
            );
          }
          if (
            featuredEnabled &&
            typeof window.aipkit_showContentWriterFeaturedImageLoading ===
              "function"
          ) {
            window.aipkit_showContentWriterFeaturedImageLoading();
          }
          mergedImageData = await requestImages();
        }

        if (mergedImageData) {
          applyImageData(mergedImageData);
          if (typeof window.aipkit_updateCwGenerationStatus === "function") {
            window.aipkit_updateCwGenerationStatus("image", "success");
          }
        } else {
          throw new Error("Image generation failed or returned no data.");
        }
      } catch (error) {
        if (isAbortError(error) && stopIfRequested()) {
          return;
        }
        if (isLongRunningRequestTimeout(error)) {
          if (mergedImageData) {
            applyImageData(mergedImageData);
          }
          if (typeof window.aipkit_updateCwGenerationStatus === "function") {
            window.aipkit_updateCwGenerationStatus(
              "image",
              "warning",
              getLongRunningImageMessage()
            );
          }
          console.warn("Content Writer image generation timed out:", error);
          return;
        }
        const completedImages = mergedImageData || error?.details?.image_data;
        if (completedImages) {
          applyImageData(completedImages);
        } else if (
          typeof window.aipkit_clearContentWriterImagePreview === "function"
        ) {
          window.aipkit_clearContentWriterImagePreview();
        }
        if (typeof window.aipkit_updateCwGenerationStatus === "function") {
          window.aipkit_updateCwGenerationStatus(
            "image",
            "error",
            error.message || "Failed"
          );
        }
      }
    } else {
      if (
        typeof window.aipkit_clearContentWriterImagePreview === "function"
      ) {
        window.aipkit_clearContentWriterImagePreview();
      }
      if (typeof window.aipkit_updateCwGenerationStatus === "function") {
        window.aipkit_updateCwGenerationStatus("image", "skipped", shouldGenerateAnyImage && providerStopError ? "Skipped after an earlier step failed." : "");
      }
    }
    // --- END: Single Image Generation Call ---
    if (stopIfRequested()) {
      return;
    }

    if (generateExcerptCheckbox?.checked && state.fullContent.trim() !== "" && !providerStopError) {
      if (typeof window.aipkit_updateCwGenerationStatus === "function")
        window.aipkit_updateCwGenerationStatus("excerpt", "generating");
      try {
        const excerptRequestData = {
          generated_content: state.fullContent,
          final_title: finalTitle,
          keywords: keywordsForPrompts,
          provider: provider,
          model: model,
          prompt_mode: promptMode,
          custom_excerpt_prompt: form.elements["custom_excerpt_prompt"].value,
          ...getVectorRequestData(form),
          conversation_uuid: window.aipkit_current_conversation_uuid || undefined,
          _ajax_nonce: nonce,
        };
        const excerptResponse = await runAbortableRequest(
          "excerpt",
          "aipkit_content_writer_generate_excerpt",
          excerptRequestData
        );
        if (excerptResponse && excerptResponse.excerpt) {
          const excerptTextarea = document.getElementById(
            "aipkit_cw_generated_excerpt"
          );
          const excerptWrapper = document.getElementById(
            "aipkit_cw_excerpt_output_wrapper"
          );
          if (excerptTextarea && excerptWrapper) {
            if (typeof window.aipkit_revealContentWriterMetaField === "function") {
              window.aipkit_revealContentWriterMetaField(
                "aipkit_cw_excerpt_output_wrapper",
                "aipkit_cw_generated_excerpt",
                excerptResponse.excerpt
              );
            } else {
              excerptTextarea.value = excerptResponse.excerpt;
              excerptWrapper.style.display = "flex";
            }
          }
          if (typeof window.aipkit_updateCwGenerationStatus === "function")
            window.aipkit_updateCwGenerationStatus("excerpt", "success");
        } else {
          throw new Error("AI did not return an excerpt.");
        }
      } catch (error) {
        if (isAbortError(error) && stopIfRequested()) {
          return;
        }
        if (typeof window.aipkit_updateCwGenerationStatus === "function") {
          window.aipkit_updateCwGenerationStatus(
            "excerpt",
            "error",
            error.message || "Failed"
          );
        }
      }
    } else {
      if (typeof window.aipkit_updateCwGenerationStatus === "function") {
        window.aipkit_updateCwGenerationStatus("excerpt", "skipped", generateExcerptCheckbox?.checked && providerStopError ? "Skipped after an earlier step failed." : "");
      }
    }
    if (stopIfRequested()) {
      return;
    }

    if (generateTagsCheckbox?.checked && state.fullContent.trim() !== "" && !providerStopError) {
      if (typeof window.aipkit_updateCwGenerationStatus === "function")
        window.aipkit_updateCwGenerationStatus("tags", "generating");
      try {
        const tagsRequestData = {
          generated_content: state.fullContent,
          final_title: finalTitle,
          keywords: keywordsForPrompts,
          provider: provider,
          model: model,
          prompt_mode: promptMode,
          custom_tags_prompt: form.elements["custom_tags_prompt"].value,
          ...getVectorRequestData(form),
          conversation_uuid: window.aipkit_current_conversation_uuid || undefined,
          _ajax_nonce: nonce,
        };
        const tagsResponse = await runAbortableRequest(
          "tags",
          "aipkit_content_writer_generate_tags",
          tagsRequestData
        );
        if (tagsResponse && tagsResponse.tags) {
          const tagsTextarea = document.getElementById(
            "aipkit_cw_generated_tags"
          );
          const tagsWrapper = document.getElementById(
            "aipkit_cw_tags_output_wrapper"
          );
          if (tagsTextarea && tagsWrapper) {
            if (typeof window.aipkit_revealContentWriterMetaField === "function") {
              window.aipkit_revealContentWriterMetaField(
                "aipkit_cw_tags_output_wrapper",
                "aipkit_cw_generated_tags",
                tagsResponse.tags
              );
            } else {
              tagsTextarea.value = tagsResponse.tags;
              tagsWrapper.style.display = "flex";
            }
          }
          if (typeof window.aipkit_updateCwGenerationStatus === "function")
            window.aipkit_updateCwGenerationStatus("tags", "success");
        } else {
          throw new Error("AI did not return any tags.");
        }
      } catch (error) {
        if (isAbortError(error) && stopIfRequested()) {
          return;
        }
        if (typeof window.aipkit_updateCwGenerationStatus === "function") {
          window.aipkit_updateCwGenerationStatus(
            "tags",
            "error",
            error.message || "Failed"
          );
        }
      }
    } else {
      if (typeof window.aipkit_updateCwGenerationStatus === "function") {
        window.aipkit_updateCwGenerationStatus("tags", "skipped", generateTagsCheckbox?.checked && providerStopError ? "Skipped after an earlier step failed." : "");
      }
    }
    if (stopIfRequested()) {
      return;
    }

    if (generateMetaCheckbox?.checked && state.fullContent.trim() !== "" && !providerStopError) {
      if (typeof window.aipkit_updateCwGenerationStatus === "function") {
        window.aipkit_updateCwGenerationStatus("meta", "generating");
      }
      try {
        const metaRequestData = {
          generated_content: state.fullContent,
          final_title: finalTitle,
          keywords: keywordsForPrompts,
          provider: provider,
          model: model,
          prompt_mode: promptMode,
          custom_meta_prompt: form.elements["custom_meta_prompt"].value,
          ...getVectorRequestData(form),
          conversation_uuid: window.aipkit_current_conversation_uuid || undefined,
          _ajax_nonce: nonce,
        };
        const metaResponse = await runAbortableRequest(
          "meta",
          "aipkit_content_writer_generate_meta_desc",
          metaRequestData
        );
        if (metaResponse && metaResponse.meta_description) {
          const metaTextarea = document.getElementById(
            "aipkit_cw_generated_meta_desc"
          );
          const metaWrapper = document.getElementById(
            "aipkit_cw_meta_desc_output_wrapper"
          );
          if (metaTextarea && metaWrapper) {
            if (typeof window.aipkit_revealContentWriterMetaField === "function") {
              window.aipkit_revealContentWriterMetaField(
                "aipkit_cw_meta_desc_output_wrapper",
                "aipkit_cw_generated_meta_desc",
                metaResponse.meta_description
              );
            } else {
              metaTextarea.value = metaResponse.meta_description;
              metaWrapper.style.display = "flex";
            }
          }
          if (typeof window.aipkit_updateCwGenerationStatus === "function")
            window.aipkit_updateCwGenerationStatus("meta", "success");
        } else {
          throw new Error("AI did not return a meta description.");
        }
      } catch (error) {
        if (isAbortError(error) && stopIfRequested()) {
          return;
        }
        if (typeof window.aipkit_updateCwGenerationStatus === "function") {
          window.aipkit_updateCwGenerationStatus(
            "meta",
            "error",
            error.message || "Failed"
          );
        }
      }
    } else {
      if (typeof window.aipkit_updateCwGenerationStatus === "function") {
        window.aipkit_updateCwGenerationStatus("meta", "skipped", generateMetaCheckbox?.checked && providerStopError ? "Skipped after an earlier step failed." : "");
      }
    }

    const shouldFinalize = await runSeoAuditAfterStreamingGeneration(form);
    if (!shouldFinalize) {
      return;
    }

    if (typeof window.aipkit_refreshContentWriterSmartSeoLockedCard === "function") {
      window.aipkit_refreshContentWriterSmartSeoLockedCard();
    }

    if (typeof window.aipkit_finalizeStream === "function") {
      window.aipkit_finalizeStream(esInstanceRef);
    }
  }

  window.aipkit_stream_handleDone = aipkit_stream_handleDone;
})();
