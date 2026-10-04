/** AI Forms streaming results, attribution and completion actions. */
(function () {
  "use strict";

  const __ =
    window.wp && window.wp.i18n && window.wp.i18n.__
      ? window.wp.i18n.__
      : (text) => text;

  /**
   * Creates the event handlers for a specific EventSource instance.
   * @param {HTMLElement} resultsDiv The div where results are rendered.
   * @param {object} request The owning submission and cancellation state.
   * @returns {object} Stream event handlers for text, completion, errors, and attribution.
   */
  function aipkitForms_createSseEventHandlers(
    resultsDiv,
    request,
    streamContext = {}
  ) {
    const state = window.aipkitAIFormsPublicState;
    const STREAMING_RENDER_INTERVAL_MS = 80;
    let fullContent = "";
    let isFirstChunk = true;
    let contentContainer = null;
    let pendingRenderHandle = null;
    let lastStreamingRenderAt = 0;
    let hasRenderedFirstDelta = false;
    let accumulatedGroundingMetadata = null;
    let accumulatedCitations = [];

    // --- HTML template for action containers ---
    const topActionsHTML = `<div class="aipkit-ai-form-results-top-actions"></div>`;
    const footerActionsHTML = `<div class="aipkit-ai-form-results-footer-actions"></div>`;
    const attributionHTML = `<div class="aipkit-ai-form-results-attribution"></div>`;
    const contentWrapperHTML = (content) =>
      `<div class="aipkit-ai-form-results-content">${content}</div>`;

    const mergeCitations = (incoming) => {
      const citations = Array.isArray(incoming) ? incoming : [];
      const normalize =
        typeof window.aipkit_chatUI_normalizeCitations === "function"
          ? window.aipkit_chatUI_normalizeCitations
          : (items) => items;
      accumulatedCitations = normalize(accumulatedCitations.concat(citations));
    };

    const renderSearchAttribution = () => {
      const provider = String(streamContext.provider || "");
      const normalizedProvider = provider.toLowerCase();
      if (
        !["google", "openrouter"].includes(normalizedProvider) ||
        typeof window.aipkit_chatUI_upsertMessageMeta !== "function"
      ) {
        return;
      }

      const attributionContainer = resultsDiv.querySelector(
        ".aipkit-ai-form-results-attribution"
      );
      if (!attributionContainer) {
        return;
      }

      window.aipkit_chatUI_upsertMessageMeta(
        attributionContainer,
        {
          groundingMetadata: accumulatedGroundingMetadata,
          citations: accumulatedCitations,
        },
        {
          provider: normalizedProvider === "google" ? "Google" : "OpenRouter",
          showSources: true,
          text: {
            source: __("Source", "gpt3-ai-content-generator"),
            sources: __("Sources", "gpt3-ai-content-generator"),
          },
        }
      );
    };

    const onGroundingMetadataHandler = (event) => {
      if (!request.isActive()) return;
      try {
        const metadata = JSON.parse(event.data);
        if (metadata && typeof metadata === "object") {
          accumulatedGroundingMetadata = metadata;
        }
      } catch (error) {
        console.error("AI Form Google Search metadata parsing failed:", error);
      }
    };

    const onCitationsHandler = (event) => {
      if (!request.isActive()) return;
      try {
        const citations = JSON.parse(event.data);
        mergeCitations(Array.isArray(citations) ? citations : [citations]);
      } catch (error) {
        console.error("AI Form citation parsing failed:", error);
      }
    };

    const escapeHtml = (text) =>
      String(text).replace(/[&<>"']/g, (char) => {
        switch (char) {
          case "&":
            return "&amp;";
          case "<":
            return "&lt;";
          case ">":
            return "&gt;";
          case '"':
            return "&quot;";
          case "'":
            return "&#039;";
          default:
            return char;
        }
      });

    const createQuotaNoticeElement = (notice) => {
      const wrapper = document.createElement("div");
      wrapper.className = "aipkit-ai-form-quota-notice";

      const message = String(notice?.message || "").trim();
      if (message) {
        const messageEl = document.createElement("div");
        messageEl.className = "aipkit-ai-form-quota-message";
        messageEl.textContent = message;
        wrapper.appendChild(messageEl);
      }

      const actions = Array.isArray(notice?.actions) ? notice.actions : [];
      const validActions = actions.filter((action) => {
        const label = String(action?.label || "").trim();
        const url = String(action?.url || "").trim();
        return label && url;
      });

      if (validActions.length > 0) {
        const actionsEl = document.createElement("div");
        actionsEl.className = "aipkit-ai-form-quota-actions";

        validActions.forEach((action) => {
          const link = document.createElement("a");
          const variant =
            action?.variant === "secondary" ? "secondary" : "primary";

          link.className = `aipkit_btn aipkit_btn-${variant} aipkit-ai-form-quota-action aipkit-ai-form-quota-action--${variant}`;
          link.href = String(action.url);
          link.target = "_blank";
          link.rel = "noopener noreferrer";
          link.textContent = String(action.label);
          actionsEl.appendChild(link);
        });

        wrapper.appendChild(actionsEl);
      }

      return wrapper;
    };

    const renderQuotaNotice = (notice) => {
      resultsDiv.style.display = "block";
      resultsDiv.classList.add("aipkit-ai-form-results--quota");
      resultsDiv.innerHTML = "";
      resultsDiv.appendChild(createQuotaNoticeElement(notice));
    };

    const isThematicBreakLine = (line) => {
      const trimmed = String(line || "").trim();
      if (trimmed.length < 3) {
        return false;
      }

      return /^(?:-{3,}|\*{3,}|_{3,})$/.test(trimmed);
    };

    const renderStreamingPreviewHtml = (text) => {
      const rulePlaceholder = "__AIPKIT_STREAM_RULE__";
      const lines = String(text || "").split("\n");
      const previewHtml = lines
        .map((line) => {
          if (isThematicBreakLine(line)) {
            return rulePlaceholder;
          }

          return escapeHtml(line);
        })
        .join("<br />");

      return previewHtml.replace(
        /(?:<br\s*\/?>\s*)*__AIPKIT_STREAM_RULE__(?:\s*<br\s*\/?>)*/g,
        '<hr class="aipkit-ai-form-stream-rule" />'
      );
    };

    const scheduleMarkdownUpgrade = () => {
      if (
        !fullContent ||
        !fullContent.trim() ||
        !resultsDiv ||
        resultsDiv.dataset.aipkitMarkdownUpgradePending === "1" ||
        !state.mdInstanceAIForms ||
        !state.mdInstanceAIForms.aipkitIsFallback ||
        !window.aipkitFormsMarkdown ||
        typeof window.aipkitFormsMarkdown.ensureRenderer !== "function"
      ) {
        return;
      }

      resultsDiv.dataset.aipkitMarkdownUpgradePending = "1";
      void window
        .aipkitFormsMarkdown.ensureRenderer()
        .then((renderer) => {
          if (!request.isCurrent()) return;
          delete resultsDiv.dataset.aipkitMarkdownUpgradePending;
          state.mdInstanceAIForms = renderer;

          if (
            !renderer ||
            renderer.aipkitIsFallback ||
            !contentContainer ||
            !contentContainer.isConnected
          ) {
            return;
          }

          contentContainer.innerHTML = renderer.render(fullContent);
          resultsDiv.scrollTop = resultsDiv.scrollHeight;
        });
    };

    const flushStreamingPreview = () => {
      pendingRenderHandle = null;
      if (!request.isCurrent()) return;
      lastStreamingRenderAt = Date.now();
      if (!contentContainer) {
        contentContainer = resultsDiv.querySelector(
          ".aipkit-ai-form-results-content"
        );
      }
      if (!contentContainer) {
        return;
      }

      // Re-render markdown during streaming, but on a throttle to avoid
      // reparsing on every tiny chunk.
      if (state.mdInstanceAIForms && typeof state.mdInstanceAIForms.render === "function") {
        contentContainer.innerHTML = state.mdInstanceAIForms.render(fullContent);
      } else {
        contentContainer.innerHTML = renderStreamingPreviewHtml(fullContent);
      }
      scheduleMarkdownUpgrade();
      resultsDiv.scrollTop = resultsDiv.scrollHeight;
    };

    const cancelPendingRender = () => {
      if (pendingRenderHandle === null) {
        return;
      }

      clearTimeout(pendingRenderHandle);
      pendingRenderHandle = null;
    };

    request.signal.addEventListener("abort", cancelPendingRender, { once: true });

    const scheduleStreamingPreview = () => {
      if (pendingRenderHandle !== null) {
        return;
      }

      const elapsed = Date.now() - lastStreamingRenderAt;
      const delay =
        elapsed >= STREAMING_RENDER_INTERVAL_MS
          ? 0
          : STREAMING_RENDER_INTERVAL_MS - elapsed;

      pendingRenderHandle = window.setTimeout(flushStreamingPreview, delay);
    };

    const onMessageHandler = (event) => {
      if (!request.isActive()) return;
      if (isFirstChunk) {
        resultsDiv.style.display = "block";
        resultsDiv.classList.remove("aipkit-ai-form-results--quota");
        // Initialize the structure with placeholders for actions and content
        resultsDiv.innerHTML =
          topActionsHTML + contentWrapperHTML("") + attributionHTML + footerActionsHTML;
        contentContainer = resultsDiv.querySelector(
          ".aipkit-ai-form-results-content"
        );
        isFirstChunk = false;
      }
      const data = JSON.parse(event.data);
      if (typeof data.delta === "string" && data.delta !== "" && contentContainer) {
        fullContent += data.delta;
        if (!hasRenderedFirstDelta) {
          hasRenderedFirstDelta = true;
          cancelPendingRender();
          flushStreamingPreview();
          return;
        }
        scheduleStreamingPreview();
      }
    };

    const finishStream = () => {
      request.signal.removeEventListener("abort", cancelPendingRender);
      request.finish();
    };

    const onDoneHandler = (event) => {
      if (!request.isActive()) return;
      cancelPendingRender();
      flushStreamingPreview();

      try {
        const doneData = JSON.parse(event.data);
        if (doneData && doneData.grounding_metadata) {
          accumulatedGroundingMetadata = doneData.grounding_metadata;
        }
        if (doneData && Array.isArray(doneData.citations)) {
          mergeCitations(doneData.citations);
        }
      } catch (error) {
        // Named stream events already carry the same attribution data.
      }

      finishStream();

      if (!contentContainer) {
        contentContainer = resultsDiv.querySelector(
          ".aipkit-ai-form-results-content"
        );
      }
      if (state.mdInstanceAIForms && contentContainer) {
        contentContainer.innerHTML =
          state.mdInstanceAIForms.render(fullContent);
      } else if (contentContainer) {
        contentContainer.innerHTML = escapeHtml(fullContent).replace(
          /\n/g,
          "<br />"
        );
      }
      scheduleMarkdownUpgrade();
      renderSearchAttribution();

      const topActionsContainer = resultsDiv.querySelector(
        ".aipkit-ai-form-results-top-actions"
      );
      const footerActionsContainer = resultsDiv.querySelector(
        ".aipkit-ai-form-results-footer-actions"
      );
      const formWrapper = resultsDiv.closest(".aipkit-ai-form-wrapper");

      if (
        fullContent.trim() !== "" &&
        formWrapper &&
        topActionsContainer &&
        footerActionsContainer
      ) {
        const showSaveButton = formWrapper.dataset.showSaveButton === "true";
        const showPdfDownload =
          formWrapper.dataset.pdfDownloadEnabled === "true";
        const showCopyButton = formWrapper.dataset.showCopyButton === "true";
        const config = window.aipkit_ai_forms_public_config || {};
        const isUserLoggedIn = config.is_user_logged_in;

        // --- Top Copy Button (Icon) ---
        const copyButton = document.createElement("button");
        copyButton.type = "button";
        copyButton.className = "aipkit-copy-results-btn";
        copyButton.title = __("Copy Results", "gpt3-ai-content-generator");
        copyButton.innerHTML =
          '<svg class="aipkit-copy-results-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="8" y="8" width="11" height="11" rx="2"></rect><path d="M5 16H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
        if (typeof window.aipkitForms_handleCopyAction === "function") {
          copyButton.addEventListener(
            "click",
            window.aipkitForms_handleCopyAction
          );
        }
        topActionsContainer.appendChild(copyButton);
        copyButton.style.display = "inline-flex";

        // --- Save As Post Button ---
        if (showSaveButton && isUserLoggedIn) {
          const saveButtonText =
            formWrapper.dataset.labelSaveButton ||
            config.text?.saveAsPost ||
            __("Save", "gpt3-ai-content-generator");
          const saveButton = document.createElement("button");
          saveButton.type = "button";
          saveButton.className =
            "aipkit_btn aipkit_btn-secondary aipkit-save-as-post-btn";
          saveButton.innerHTML = `<span class="aipkit_btn-text">${saveButtonText}</span><span class="aipkit_spinner" style="display:none;"></span>`;
          if (typeof window.aipkitForms_handleSaveAsPost === "function") {
            saveButton.addEventListener(
              "click",
              window.aipkitForms_handleSaveAsPost
            );
          }
          footerActionsContainer.appendChild(saveButton);
          saveButton.style.display = "inline-flex";
        }
        // --- Download PDF Button ---
        if (
          showPdfDownload &&
          typeof window.aipkitForms_handleDownloadPdf === "function"
        ) {
          const pdfButtonText =
            formWrapper.dataset.labelDownloadButton ||
            __("Download", "gpt3-ai-content-generator");
          const pdfButton = document.createElement("button");
          pdfButton.type = "button";
          pdfButton.className =
            "aipkit_btn aipkit_btn-secondary aipkit-download-pdf-btn";
          pdfButton.innerHTML = `<span class="aipkit_btn-text">${pdfButtonText}</span>`;
          pdfButton.addEventListener(
            "click",
            window.aipkitForms_handleDownloadPdf
          );
          footerActionsContainer.appendChild(pdfButton);
          pdfButton.style.display = "inline-flex";
        }

        // --- Conditional Bottom Copy Button ---
        if (showCopyButton) {
          const copyButtonText =
            formWrapper.dataset.labelCopyButton ||
            __("Copy", "gpt3-ai-content-generator");
          const footerCopyButton = document.createElement("button");
          footerCopyButton.type = "button";
          footerCopyButton.className =
            "aipkit_btn aipkit_btn-secondary aipkit-copy-results-footer-btn";
          footerCopyButton.innerHTML = `<span class="aipkit_btn-text">${copyButtonText}</span>`;
          if (typeof window.aipkitForms_handleCopyAction === "function") {
            footerCopyButton.addEventListener(
              "click",
              window.aipkitForms_handleCopyAction
            );
          }
          footerActionsContainer.appendChild(footerCopyButton);
          footerCopyButton.style.display = "inline-flex";
        }
      }

      const doneHandlers = window.aipkitAIFormsDoneHandlers;
      if (Array.isArray(doneHandlers)) {
        doneHandlers.forEach((handler) => {
          if (typeof handler !== "function") {
            return;
          }
          try {
            handler({
              resultsDiv,
              formWrapper,
              fullContent,
              contentContainer,
              sourceInputs: streamContext.userInputs || {},
              streamContext,
            });
          } catch (handlerError) {
            console.warn("AI Form done handler failed:", handlerError);
          }
        });
      }
    };

    const onErrorHandler = (event) => {
      if (!request.isActive()) return;
      cancelPendingRender();
      flushStreamingPreview();
      console.error("AI Form SSE Error:", event);
      let errorMsg = __(
        aipkit_ai_forms_public_config.text.error || "An error occurred.",
        "gpt3-ai-content-generator"
      );
      let quotaNotice = null;
      if (event.data) {
        try {
          const errData = JSON.parse(event.data);
          if (errData.error) errorMsg = errData.error;
          if (
            errData.quota_notice &&
            typeof errData.quota_notice === "object"
          ) {
            quotaNotice = errData.quota_notice;
          }
        } catch (e) {}
      }
      resultsDiv.style.display = "block"; // Make sure error is visible
      if (quotaNotice) {
        renderQuotaNotice(quotaNotice);
        finishStream();
        return;
      }
      resultsDiv.classList.remove("aipkit-ai-form-results--quota");
      const hasContent = fullContent.trim() !== "";
      if (hasContent) {
        let errorEl = resultsDiv.querySelector(".aipkit-ai-form-error");
        if (!errorEl) {
          errorEl = document.createElement("div");
          errorEl.className = "aipkit-ai-form-error";
          const footer = resultsDiv.querySelector(
            ".aipkit-ai-form-results-footer-actions"
          );
          if (footer && footer.parentNode) {
            footer.parentNode.insertBefore(errorEl, footer.nextSibling);
          } else {
            resultsDiv.appendChild(errorEl);
          }
        }
        errorEl.textContent = errorMsg;
        renderSearchAttribution();
      } else {
        const notice = document.createElement("p");
        notice.className = "aipkit-ai-form-error";
        notice.setAttribute("role", "alert");
        notice.textContent = errorMsg;
        resultsDiv.replaceChildren(notice);
      }

      finishStream();
    };

    return {
      onMessageHandler,
      onDoneHandler,
      onErrorHandler,
      onGroundingMetadataHandler,
      onCitationsHandler,
    };
  }

  // Expose the factory function globally
  window.aipkitForms_createSseEventHandlers =
    aipkitForms_createSseEventHandlers;
})();
