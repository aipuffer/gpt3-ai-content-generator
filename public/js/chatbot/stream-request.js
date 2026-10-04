/** Chatbot stream request caching, nonce recovery and EventSource construction. */
(function () {
  "use strict";

  function appendFileContext(target, context) {
    if (
      context.provider === "OpenAI" &&
      context.vector_store_id
    ) {
      target.append(
        "active_openai_vs_id",
        context.vector_store_id
      );
    }
    if (
      context.provider === "Pinecone" &&
      context.index_name &&
      context.namespace
    ) {
      target.append(
        "active_pinecone_index_name",
        context.index_name
      );
      target.append(
        "active_pinecone_namespace",
        context.namespace
      );
    }
    if (
      context.provider === "Qdrant" &&
      context.collection_name &&
      context.file_upload_context_id
    ) {
      target.append(
        "active_qdrant_collection_name",
        context.collection_name
      );
      target.append(
        "active_qdrant_file_upload_context_id",
        context.file_upload_context_id
      );
    }
    if (
      context.provider === "Chroma" &&
      context.collection_name &&
      context.file_upload_context_id
    ) {
      target.append(
        "active_chroma_collection_name",
        context.collection_name
      );
      target.append(
        "active_chroma_file_upload_context_id",
        context.file_upload_context_id
      );
    }
    if (
      context.provider === "Claude" &&
      context.file_id
    ) {
      target.append(
        "active_claude_file_id",
        context.file_id
      );
    }
  }

  /**
   * Caches the user message, image data, active file context, and client-generated user message ID via AJAX.
   * @param {string} userText - The user's text message.
   * @param {object} config - Chatbot configuration object (must include ajaxUrl and nonce).
   * @param {object|null} imageDataPayload - Optional payload for image data.
   * @param {object|null} activeFileContext - Optional active file context data.
   * @param {string|null} clientUserMessageId - Optional client-generated ID for the user's message.
   * @param {object|null} streamOptions - Optional stream behavior flags.
   * @returns {Promise<string>} - A promise that resolves with the cache key.
   */
  function aipkit_chatUI_cacheSseMessage(
    userText,
    config,
    imageDataPayload,
    activeFileContext,
    clientUserMessageId,
    streamOptions = null
  ) {
    return new Promise((resolve, reject) => {
      if (!config.ajaxUrl)
        return reject(new Error("Missing ajaxUrl in config for SSE cache."));
      if (!config.nonce)
        return reject(new Error("Missing nonce in config for SSE cache."));

      const formData = new FormData();
      formData.append("action", "aipkit_cache_sse_message");
      formData.append("message", userText);
      formData.append("_ajax_nonce", config.nonce);

      // Add bot_id for CORS checking
      if (config.botId) {
        formData.append("bot_id", config.botId);
      }

      if (imageDataPayload) {
        formData.append("image_inputs", JSON.stringify([imageDataPayload]));
      }

      if (clientUserMessageId) {
        formData.append("user_client_message_id", clientUserMessageId);
      }

      if (streamOptions && typeof streamOptions === "object") {
        if (streamOptions.resumeAfterFormSubmission) {
          formData.append("resume_after_form_submission", "1");
        }
        if (streamOptions.formSubmissionContext) {
          formData.append(
            "form_submission_context",
            JSON.stringify(streamOptions.formSubmissionContext)
          );
        }
        if (streamOptions.formResumeToken) {
          formData.append("form_resume_token", streamOptions.formResumeToken);
        }
      }

      let finalActiveFileContext = activeFileContext;
      // Attempt to load from localStorage if not passed directly AND a conversation exists
      if (!finalActiveFileContext && window.aipkit_current_conversation_uuid) {
        try {
          const storedContextJson = localStorage.getItem(
            `aipkit_file_context_${window.aipkit_current_conversation_uuid}`
          );
          if (storedContextJson) {
            finalActiveFileContext = JSON.parse(storedContextJson);
          }
        } catch (e) {
          console.error(
            "CacheSSE: Error reading file context from localStorage:",
            e
          );
        }
      }

      if (finalActiveFileContext) {
        appendFileContext(formData, finalActiveFileContext);
        if (finalActiveFileContext.provider !== "Google" && finalActiveFileContext.context_token) {
          formData.append("active_file_context_token", finalActiveFileContext.context_token);
        }
        if (
          finalActiveFileContext.provider === "Google" &&
          finalActiveFileContext.context_token
        ) {
          formData.append(
            "active_google_file_context_token",
            finalActiveFileContext.context_token
          );
        }
      }

      function retryWithFreshNonce() {
        return aipkit_refreshFrontendChatNonce(config)
          .then(() => {
            formData.set && formData.set('_ajax_nonce', config.nonce);
            return fetch(config.ajaxUrl, { method: 'POST', body: formData });
          })
          .then((retryResp) => {
            if (!retryResp.ok) return retryResp.text().then((t) => { throw new Error(t || `HTTP error ${retryResp.status} caching message.`); });
            return retryResp.json();
          });
      }

      const doRequest = () =>
        fetch(config.ajaxUrl, { method: "POST", body: formData })
        .then((response) => {
          if (!response.ok) {
            return response
              .text()
              .then((responseText) => {
                try {
                  const errData = JSON.parse(responseText);
                  // Handle specific error codes with user-friendly messages
                  if (errData?.data?.code === 'embed_not_available') {
                    throw new Error("The embed feature is not available with your current plan. Please upgrade to use embedded chatbots.");
                  }
                  if (errData?.data?.code === 'cors_denied') {
                    throw new Error("This domain is not permitted to access the chatbot. Please check your embed settings.");
                  }
                  // Auto-refresh nonce and retry once if specific nonce failure code detected
                  if (errData?.data?.code === 'nonce_failure_cache_sse') {
                    return retryWithFreshNonce();
                  }
                  throw new Error(
                    errData?.data?.message ||
                      `HTTP error ${response.status} caching message.`
                  );
                } catch (parseError) {
                  if (response.status === 403) {
                    // Attempt a single nonce refresh and retry
                    return retryWithFreshNonce();
                  }
                  throw new Error(
                    `Failed to prepare message for streaming. Please check your internet connection and try again. (HTTP ${response.status})`
                  );
                }
              });
          }
          return response.json();
        })
        .then((data) => {
          if (data.success && data.data?.cache_key) {
            resolve(data.data.cache_key);
          } else {
            reject(
              new Error(
                data.data?.message || "Failed to cache message for streaming."
              )
            );
          }
        })
        .catch((error) => {
          console.error("AIPKit SSE Cache Request Error:", error);
          reject(error);
        });

      // Helper to refresh nonce (shared shape with public-main bundle override)
      function aipkit_refreshFrontendChatNonce(cfg) {
        return new Promise((resolve, reject) => {
          try {
            if (!cfg || !cfg.ajaxUrl) return reject(new Error('No ajaxUrl for nonce refresh'));
            const fd = new FormData();
            fd.append('action', typeof window.aipkit_getChatNonceAction === 'string' && window.aipkit_getChatNonceAction ? window.aipkit_getChatNonceAction : 'aipkit_get_frontend_chat_nonce');
            if (cfg.botId) fd.append('bot_id', cfg.botId);
            fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
              .then(r => r.json())
              .then(j => {
                if (j && j.success && j.data && j.data.nonce) { cfg.nonce = j.data.nonce; resolve(j.data.nonce); }
                else reject(new Error('Nonce refresh failed'));
              })
              .catch(() => reject(new Error('Nonce refresh network error')));
          } catch (e) { reject(e); }
        });
      }

      // Kick off request flow
      doRequest();
    });
  }

  window.aipkit_chatUI_cacheSseMessage = aipkit_chatUI_cacheSseMessage;


  /**
   * Creates and returns an EventSource instance for streaming.
   * @param {string} cacheKey - The cache key for the message data.
   * @param {string} botId - The ID of the chatbot.
   * @param {object} config - Chatbot configuration object.
   * @param {string} guestUUID - Guest UUID.
   * @param {string} currentConversationUUID - Current conversation UUID.
   * @param {number} currentPostId - Current post ID.
   * @param {string|null} currentOpenAIRespId - Current OpenAI response ID.
   * @param {string|null} currentGoogleInteractionId - Current Google interaction ID.
   * @param {boolean} webSearchActive - Whether OpenAI web search is active.
   * @param {boolean} googleSearchGroundingActive - Whether Google Search Grounding is active.
   * @param {object|null} activeFileContext - Optional active file context.
   *                                        Supports OpenAI, Pinecone, Qdrant, Chroma, and Claude file context shapes.
   * @returns {EventSource|Error} The EventSource object or an Error if creation fails.
   */
  function aipkit_chatUI_createEventSource(
    cacheKey,
    botId,
    config,
    guestUUID,
    currentConversationUUID,
    currentPostId,
    currentOpenAIRespId,
    currentGoogleInteractionId,
    webSearchActive,
    googleSearchGroundingActive,
    activeFileContext
  ) {
    if (!config.ajaxUrl || !config.nonce) {
      return new Error("Missing ajaxUrl or nonce in config for EventSource.");
    }

    const streamUrl = new URL(config.ajaxUrl);
    streamUrl.searchParams.append("action", "aipkit_frontend_chat_stream");
    streamUrl.searchParams.append("cache_key", cacheKey);
    streamUrl.searchParams.append("bot_id", botId);
    streamUrl.searchParams.append("session_id", guestUUID || "");
    streamUrl.searchParams.append("conversation_uuid", currentConversationUUID);
    if (currentPostId > 0) {
      streamUrl.searchParams.append("post_id", currentPostId);
    }
    // Cache-buster to avoid any intermediary caching of SSE URL
    try { streamUrl.searchParams.append("_ts", Date.now().toString()); } catch (e) {}
    if (
      config.provider === "OpenAI" &&
      config.enableOpenAIConversationState &&
      currentOpenAIRespId
    ) {
      streamUrl.searchParams.append(
        "previous_openai_response_id",
        currentOpenAIRespId
      );
    }
    if (
      config.provider === "Google" &&
      config.enableGoogleConversationState &&
      currentGoogleInteractionId
    ) {
      streamUrl.searchParams.append(
        "previous_google_interaction_id",
        currentGoogleInteractionId
      );
    }
    if (
      webSearchActive &&
      config.allowWebSearchTool &&
      (config.provider === "OpenAI" ||
        config.provider === "Claude" ||
        config.provider === "OpenRouter" ||
        config.provider === "xAI")
    ) {
      streamUrl.searchParams.append("frontend_web_search_active", "true");
    }
    if (
      googleSearchGroundingActive &&
      config.provider === "Google" &&
      config.allowGoogleSearchGrounding
    ) {
      streamUrl.searchParams.append(
        "frontend_google_search_grounding_active",
        "true"
      );
    }

    if (activeFileContext) {
      appendFileContext(streamUrl.searchParams, activeFileContext);
    }

    streamUrl.searchParams.append("_ajax_nonce", config.nonce);

    try {
      const eventSource = new EventSource(streamUrl.toString());
      return eventSource;
    } catch (e) {
      console.error(
        `[AIPKit Stream CreateEventSource (${botId})] Failed to create EventSource instance:`,
        e
      );
      return e;
    }
  }

  window.aipkit_chatUI_createEventSource = aipkit_chatUI_createEventSource;
})();
