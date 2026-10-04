/**
 * AIPKit Content Writer Stream - Message Handler
 * Handles the 'onmessage' event from the stream.
 */
(function () {
  "use strict";

  const STREAMING_RENDER_INTERVAL_MS = 80;

  const escaper =
    window.aipkit_escapeHtml ||
    function (str) {
      return str;
    };

  /**
   * Checks if content area has overflow and adds indicator class
   */
  function checkOverflow(contentArea) {
    const canvas = contentArea.parentElement;
    const chunk = canvas?.closest('.aipkit_cw_output_chunk--primary');
    if (chunk) {
      if (canvas.scrollHeight > canvas.clientHeight) {
        chunk.classList.add('has-overflow');
      } else {
        chunk.classList.remove('has-overflow');
      }
    }
  }

  function renderStreamingPreviewHtml(text) {
    return escaper(String(text || "")).replace(/\n/g, "<br>");
  }

  function flushStreamingPreview(state, contentArea, markdownRenderer) {
    if (state.pendingRenderHandle != null) {
      window.clearTimeout(state.pendingRenderHandle);
      state.pendingRenderHandle = null;
    }
    state.lastRenderAt = Date.now();

    if (
      !markdownRenderer &&
      typeof window.aipkit_getMarkdownRenderer === "function"
    ) {
      markdownRenderer = window.aipkit_getMarkdownRenderer();
    }

    if (markdownRenderer && typeof markdownRenderer.render === "function") {
      contentArea.innerHTML = markdownRenderer.render(state.fullContent);
    } else {
      contentArea.innerHTML = renderStreamingPreviewHtml(state.fullContent);
    }

    // The article skeleton only represents the wait for the first real body
    // content. Remove it as soon as the stream paints that first content.
    if (
      String(state.fullContent || "").trim() &&
      typeof window.aipkit_hideContentWriterArticleSkeletonPart === "function"
    ) {
      window.aipkit_hideContentWriterArticleSkeletonPart("body");
    }

    if (typeof window.aipkit_setContentWriterRawHtml === "function") {
      window.aipkit_setContentWriterRawHtml(contentArea.innerHTML);
    }

    if (typeof window.aipkit_followContentWriterStream === "function") {
      window.aipkit_followContentWriterStream();
    } else {
      const container = contentArea.parentElement;
      if (container) {
        container.scrollTop = container.scrollHeight;
      }
    }

    checkOverflow(contentArea);

    if (typeof window.aipkit_updateContentWriterPreviewCounter === "function") {
      window.aipkit_updateContentWriterPreviewCounter();
    }
  }

  function scheduleStreamingPreview(state, contentArea, markdownRenderer) {
    if (state.pendingRenderHandle != null) {
      return;
    }

    const elapsed = Date.now() - (state.lastRenderAt || 0);
    const delay =
      elapsed >= STREAMING_RENDER_INTERVAL_MS
        ? 0
        : STREAMING_RENDER_INTERVAL_MS - elapsed;

    state.pendingRenderHandle = window.setTimeout(function () {
      flushStreamingPreview(state, contentArea, markdownRenderer);
    }, delay);
  }

  /**
   * Processes a message event from the SSE stream.
   * @param {MessageEvent} event - The message event.
   * @param {object} state - An object containing state: { fullContent: string }.
   * @param {HTMLElement} contentArea - The element to display the content in.
   * @param {object} markdownRenderer - The markdown-it instance.
   */
  function aipkit_stream_handleMessage(
    event,
    state,
    contentArea,
    markdownRenderer
  ) {
    if (!event.data) return;

    try {
      const data = JSON.parse(event.data);
      if (typeof data.delta === "string" && data.delta !== "") {
        state.fullContent += data.delta;

        // Flag streaming state for the preview without animating the article body.
        if (!contentArea.classList.contains('is-streaming')) {
          contentArea.classList.add('is-streaming');
        }

        if (
          typeof window.aipkit_setContentWriterCanvasState === "function" &&
          typeof window.aipkit_getContentWriterCanvasState === "function" &&
          window.aipkit_getContentWriterCanvasState() !== "partial"
        ) {
          window.aipkit_setContentWriterCanvasState("partial", {
            hasContent: true,
          });
        }

        if (!state.hasRenderedFirstDelta) {
          state.hasRenderedFirstDelta = true;
          flushStreamingPreview(state, contentArea, markdownRenderer);
          return;
        }

        scheduleStreamingPreview(state, contentArea, markdownRenderer);
      }
    } catch (e) {
      console.error("Stream Message Handler: Error parsing event data.", e);
    }
  }

  window.aipkit_flushContentWriterStreamingPreview = flushStreamingPreview;
  window.aipkit_stream_handleMessage = aipkit_stream_handleMessage;
})();
