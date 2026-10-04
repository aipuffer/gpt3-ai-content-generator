/**
 * AIPKit Public Chat - UI Stream Message Orchestrator (SSE)
 *
 * Main entry point for initiating an SSE stream.
 * Calls modularized helper functions for caching, EventSource creation, and event handling.
 * MODIFIED: Accepts activeFileContext and passes it to createEventSource.
 * MODIFIED: Passes the activeFileContext (which may include Pinecone details) to aipkit_chatUI_cacheSseMessage.
 */
(function () {
  "use strict";

  /**
   * Initiates and manages an SSE stream for a chat message.
   * @param {string} userText - The user's text message.
   * @param {string} botId - The ID of the chatbot.
   * @param {object} config - Chatbot configuration object.
   * @param {HTMLElement} messagesEl - The messages container element.
   * @param {function} onErrorCallback - Callback for handling errors: onError(errorMessage, isNetworkError, forceScroll).
   * @param {function} onCompleteCallback - Callback for when the stream is complete.
   * @param {boolean} webSearchActive - Current state of the OpenAI web search toggle.
   * @param {boolean} googleSearchGroundingActive - Current state of the Google Search Grounding toggle.
   * @param {object|null} imageDataPayload - Optional payload for image data.
   * @param {object|null} activeFileContext - Optional active file context from message submission.
   *                                        For OpenAI: { provider, vector_store_id }
   *                                        For Pinecone: { provider, index_name, namespace }
   *                                        For Qdrant: { provider, collection_name, file_upload_context_id }
   * @param {string|null} clientUserMessageId - Optional client-generated ID for the user's message.
   * @param {object|null} sharedState - Optional shared chat state for exposing the active stream to the UI.
   * @param {object|null} streamOptions - Optional stream behavior flags.
   */
  async function aipkit_chatUI_streamMessage(
    userText,
    botId,
    config,
    messagesEl,
    onErrorCallback,
    onCompleteCallback,
    webSearchActive,
    googleSearchGroundingActive,
    imageDataPayload,
    activeFileContext,
    clientUserMessageId,
    sharedState = null,
    streamOptions = null
  ) {
    const textLabels = config.text || {};
    const streamState = {
      dataReceived: false,
      currentStreamMessageId: null,
      accumulatedGroundingMetadata: null,
      accumulatedCitations: [],
      currentStatusText: null,
    };
    const eventSourceRef = { current: null };

    const signal = streamOptions?.signal;
    const owned = Boolean(signal || streamOptions?.isCurrent);
    let stopped = false;
    const close = () => {
      stopped = true;
      signal?.removeEventListener('abort', close);
      eventSourceRef.current?.close();
      eventSourceRef.current = null;
    };
    const isCurrent = () => {
      if (owned && (stopped || signal?.aborted ||
        (streamOptions.isCurrent && !streamOptions.isCurrent()) ||
        (sharedState && sharedState.activeEventSourceRef !== eventSourceRef))) {
        close();
        return false;
      }
      return true;
    };
    if (owned) {
      if (signal?.aborted || (streamOptions.isCurrent && !streamOptions.isCurrent())) return;
      eventSourceRef.cancel = close;
      // Publish preparation ownership too, so Stop can invalidate a pending cache reply.
      if (sharedState) sharedState.activeEventSourceRef = eventSourceRef;
    }
    const listen = (name, callback, terminal = false) => {
      const handler = owned ? (event) => {
        if (!isCurrent()) return;
        try { callback(event); }
        finally { if (terminal) close(); }
      } : callback;
      if (name === 'message' || name === 'error') eventSourceRef.current['on' + name] = handler;
      else eventSourceRef.current.addEventListener(name, handler);
    };
    const removeTypingIndicator = () => {
      if (!owned || !sharedState || sharedState.activeEventSourceRef === eventSourceRef) {
        window.aipkit_chatUI_removeTypingIndicator?.(messagesEl);
      }
    };

    const requiredHelpers = [
      "aipkit_chatUI_cacheSseMessage",
      "aipkit_chatUI_createEventSource",
      "aipkit_chatUI_handleMessageStartEvent",
      "aipkit_chatUI_handleOpenAIResponseIdEvent",
      "aipkit_chatUI_handleGoogleInteractionIdEvent",
      "aipkit_chatUI_handleGroundingMetadataEvent",
      "aipkit_chatUI_handleStatusEvent",
      "aipkit_chatUI_handleCitationsEvent",
      "aipkit_chatUI_handleOnMessageEvent",
      "aipkit_chatUI_handleDoneEvent",
      "aipkit_chatUI_handleWarningEvent",
      "aipkit_chatUI_handleErrorEvent",
      "aipkit_chatUI_removeTypingIndicator",
    ];
    if (config.formGateEnabled) requiredHelpers.push("aipkit_chatUI_handleDisplayFormEvent");
    for (const helper of requiredHelpers) {
      if (typeof window[helper] !== "function") {
        console.error(
          `[AIPKit Stream Orchestrator (${botId})] Missing required helper function: ${helper}`
        );
        if (typeof onErrorCallback === "function") {
          onErrorCallback(
            `${
              textLabels.errorPrefix || "Error:"
            } Critical script component missing.`,
            false,
            true
          );
        }
        removeTypingIndicator();
        return;
      }
    }

    const currentConversationUUID = window.aipkit_current_conversation_uuid;
    const guestUUID = window.aipkit_guest_uuid;
    const currentPostId = window.aipkit_current_post_id;
    const currentOpenAIRespId = window.aipkit_current_openai_response_id;
    const currentGoogleInteractionId =
      window.aipkit_current_google_interaction_id;

    if (!currentConversationUUID) {
      console.error(
        `[AIPKit Stream Orchestrator (${botId})] Missing currentConversationUUID.`
      );
      if (typeof onErrorCallback === "function")
        onErrorCallback(
          textLabels.errorPrefix +
            " " +
            (textLabels.connError || "Configuration error (Conv ID)."),
          false
        );
      return;
    }

    let cacheKey = null;
    try {
      cacheKey = await window.aipkit_chatUI_cacheSseMessage(
        userText,
        config,
        imageDataPayload,
        activeFileContext,
        clientUserMessageId,
        streamOptions
      );
    } catch (error) {
      if (!isCurrent()) return;
      // --- MODIFIED: Enhanced Error Logging ---
      console.error(
        `[AIPKit Stream Orchestrator (${botId})] Failed to cache message (see details below):`,
        error.message || "Unknown error during cache attempt." // Log the primary message
      );
      console.error(
        // Log the full error object which might contain code from wp_send_json_error
        `[AIPKit Stream Orchestrator (${botId})] Full error object from cache failure:`,
        error
      );
      if (typeof onErrorCallback === "function") {
        // Construct a user-facing message, potentially including the error code if available
        let userErrorMsg = `${
          textLabels.errorPrefix || "Error:"
        } Failed to prepare message for streaming.`;
        if (error && typeof error === "object" && error.code) {
          // Check if error object has a code property
          userErrorMsg += ` (Code: ${error.code})`;
        } else if (error && error.message) {
          userErrorMsg += ` (${error.message})`;
        } else {
          userErrorMsg += ` (Unknown error)`;
        }
        onErrorCallback(userErrorMsg);
      }
      removeTypingIndicator();
      return;
    }

    if (!isCurrent()) return;
    const eventSourceOrError = window.aipkit_chatUI_createEventSource(
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
      activeFileContext // Pass the captured context to createEventSource
    );

    if (eventSourceOrError instanceof Error) {
      console.error(
        `[AIPKit Stream Orchestrator (${botId})] Error creating EventSource:`,
        eventSourceOrError
      );
      if (typeof onErrorCallback === "function") {
        onErrorCallback(
          textLabels.connError || "Connection error. Please try again.",
          false,
          true
        );
      }
      removeTypingIndicator();
      return;
    }
    eventSourceRef.current = eventSourceOrError;
    if (!isCurrent()) return;
    signal?.addEventListener('abort', close, { once: true });
    if (sharedState && typeof sharedState === "object") {
      sharedState.activeEventSourceRef = eventSourceRef;
      sharedState.activeStreamState = streamState;
      sharedState.currentStreamId = cacheKey;
      sharedState.currentStreamMessageId = null;
    }

    const syncSharedStreamState = () => {
      if (!isCurrent() || !sharedState || typeof sharedState !== "object") {
        return;
      }
      sharedState.currentStreamId = cacheKey;
      sharedState.currentStreamMessageId = streamState.currentStreamMessageId;
    };

    listen("message_start", (event) => {
      window.aipkit_chatUI_handleMessageStartEvent(
        event,
        config,
        streamState,
        messagesEl
      );
      syncSharedStreamState();
    });
    listen("openai_response_id", (event) =>
      window.aipkit_chatUI_handleOpenAIResponseIdEvent(event, config)
    );
    listen("google_interaction_id", (event) =>
      window.aipkit_chatUI_handleGoogleInteractionIdEvent(event, config)
    );
    listen("grounding_metadata", (event) =>
      window.aipkit_chatUI_handleGroundingMetadataEvent(event, streamState)
    );
    listen("status", (event) =>
      window.aipkit_chatUI_handleStatusEvent(
        event,
        botId,
        config,
        messagesEl,
        streamState
      )
    );
    listen("citations", (event) =>
      window.aipkit_chatUI_handleCitationsEvent(
        event,
        botId,
        config,
        messagesEl,
        streamState
      )
    );
    if (config.formGateEnabled) listen("display_form_event", (event) =>
      window.aipkit_chatUI_handleDisplayFormEvent(
        event,
        botId,
        config,
        messagesEl,
        streamState
      )
    );
    listen("message", (event) => {
      window.aipkit_chatUI_handleOnMessageEvent(
        event,
        botId,
        config,
        messagesEl,
        streamState
      );
      syncSharedStreamState();
    });
    listen("done", (event) => {
      syncSharedStreamState();
      window.aipkit_chatUI_handleDoneEvent(
        event,
        botId,
        config,
        messagesEl,
        onCompleteCallback,
        eventSourceRef,
        streamState
      );
      if (sharedState && typeof sharedState === "object" && (!owned || sharedState.activeEventSourceRef === eventSourceRef)) {
        sharedState.ignoreNextStreamError = false;
      }
    }, true);
    listen("warning", (event) =>
      window.aipkit_chatUI_handleWarningEvent(
        event,
        botId,
        config,
        messagesEl,
        streamState
      )
    );
    listen("error", (event) => {
      if (
        sharedState &&
        (sharedState.ignoredStreamErrorRef === eventSourceRef ||
          sharedState.ignoreNextStreamError)
      ) {
        sharedState.ignoreNextStreamError = false;
        if (sharedState.ignoredStreamErrorRef === eventSourceRef) {
          sharedState.ignoredStreamErrorRef = null;
        }
        if (eventSourceRef.current) {
          eventSourceRef.current.close();
          eventSourceRef.current = null;
        }
        return;
      }

      syncSharedStreamState();
      window.aipkit_chatUI_handleErrorEvent(
        event,
        botId,
        config,
        messagesEl,
        onErrorCallback,
        eventSourceRef,
        streamState
      );
    }, true);
  }

  window.aipkit_chatUI_streamMessage = aipkit_chatUI_streamMessage;
})();
