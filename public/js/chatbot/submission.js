/**
 * AIPKit Public Chat - Message Submission
 *
 * Sends user messages and cancels active streams while retaining partial responses.
 */
(function () {
  "use strict";

  const submissions = new WeakMap();

  function setInputControlsDisabled(elements, disabled) {
    for (const name of ["inputField", "voiceInputButton", "inputActionButton", "webSearchToggleButton", "googleSearchGroundingToggleButton"]) {
      if (elements[name]) elements[name].disabled = disabled;
    }
  }

  function hasActiveFileContext() {
    return window.aipkit_active_file_context_data &&
      typeof window.aipkit_active_file_context_data === "object" &&
      window.aipkit_active_file_context_data !== null &&
      Object.keys(window.aipkit_active_file_context_data).length > 0 &&
      window.aipkit_active_file_context_data.provider;
  }

  /**
   * Main action to send a user message.
   * @param {object} elements - UI elements.
   * @param {object} config - Chatbot config.
   * @param {object} state - Chat instance state.
   * @param {object} actions - Other core actions { setButtonStateAction, handleError, handleCompletion, focusInputAction }.
   * @param {string} [textToSend=null] - Optional text to send (e.g., from starter).
   */
  async function aipkit_chatUI_sendMessageAction(
    elements,
    config,
    state,
    actions,
    textToSend = null
  ) {
    const { inputField, messagesEl, container, inputArea } = elements;
    const botId = config.botId;

    if (state.formGatePending) {
      if (typeof window.aipkit_chatUI_focusPendingForm === "function") {
        window.aipkit_chatUI_focusPendingForm(container, {
          elements,
          config,
          state,
          actions,
        });
      }
      return;
    }

    let activeFileContext = null;
    if (
      hasActiveFileContext()
    ) {
      // Check for required fields based on provider
      const contextData = window.aipkit_active_file_context_data;
      const isValidContext =
        typeof window.aipkit_chatUI_isValidFileContext === "function"
          ? window.aipkit_chatUI_isValidFileContext(contextData)
          : false;

      if (isValidContext) {
        activeFileContext = { ...contextData }; // Create a shallow copy
      } else {
        console.warn(
          `SendMessageAction (${botId}): File context data found on window object, but missing required fields for provider: ${
            contextData.provider || "unknown"
          }. Context ignored. Data:`,
          contextData
        );
        activeFileContext = null;
        if (contextData.provider === "Google") {
          if (
            typeof window.aipkit_chatUI_removeStoredFileContext === "function"
          ) {
            window.aipkit_chatUI_removeStoredFileContext(
              window.aipkit_current_conversation_uuid
            );
          }
          window.aipkit_chatUI_clearFileContext();
        }
      }
    }

    let imageDataPayload = null;
    if (
      config.imageUploadEnabledUI &&
      window.chatImageUpload &&
      typeof window.chatImageUpload.getImageData === "function"
    ) {
      imageDataPayload = window.chatImageUpload.getImageData();
      if (
        imageDataPayload &&
        elements.imagePreviewContainer &&
        elements.imageUploadInput &&
        typeof window.chatImageUpload.reset === "function"
      ) {
        window.chatImageUpload.reset(
          elements.imagePreviewContainer,
          elements.imageUploadInput
        );
      }
    }

    if (
      window.aipkitChatFileUpload &&
      typeof window.aipkitChatFileUpload.resetUI === "function" &&
      elements.fileUploadInput &&
      inputArea
    ) {
      const fileState = window.aipkitFileUploadState;
      if (activeFileContext || (fileState && fileState.currentFile)) {
        window.aipkitChatFileUpload.resetUI(
          elements.fileUploadInput,
          inputArea
        );
      }
    }

    const consentUI = state.consentUIInstance;
    if (
      consentUI &&
      typeof consentUI.showConsentBoxIfNeeded === "function" &&
      consentUI.showConsentBoxIfNeeded()
    ) {
      console.warn(
        `AIPKit SendMessage (${botId}): Cannot send message, consent required and box shown.`
      );
      return;
    }
    if (config.requireConsentCompliance && !state.consentGiven) {
      console.warn(
        `AIPKit SendMessage (${botId}): Consent required but not given.`
      );
      return;
    }

    const userText =
      textToSend !== null ? textToSend.trim() : inputField.value.trim();

    if (
      state.isSending ||
      (!userText && !imageDataPayload && !activeFileContext)
    ) {
      // Don't send if no text, no image, and no active file context
      return;
    }
    if (
      textToSend === null &&
      (state.buttonState !== "send" || elements.actionButton.disabled)
    ) {
      return;
    }

    let wasNewConversation = false;
    if (
      window.aipkit_is_fresh_session ||
      !window.aipkit_current_conversation_uuid
    ) {
      if (typeof window.aipkit_regenerateConversationUUID === "function") {
        if (!window.aipkit_current_conversation_uuid) {
          window.aipkit_regenerateConversationUUID();
          // Re-capture activeFileContext AFTER UUID regeneration for a new chat,
          // especially if a file was just uploaded and `sendMessageAction` is called immediately.
          if (
            hasActiveFileContext()
          ) {
            activeFileContext = { ...window.aipkit_active_file_context_data };
          }
        }
      }
      wasNewConversation = true;
    }

    const userMessageId = window.aipkit_generateClientMessageId(botId);
    let userMessageContentForUI = userText;
    if (imageDataPayload) {
      userMessageContentForUI = {
        text: userText,
        user_image: imageDataPayload,
      };
    }
    window.aipkit_chatUI_appendMessage(
      messagesEl,
      userMessageContentForUI,
      "user",
      config,
      false,
      false,
      userMessageId,
      true
    );

    if (textToSend === null) {
      inputField.value = "";
      if (typeof window.aipkit_autoResizeTextarea === "function") {
        window.aipkit_autoResizeTextarea(inputField);
      }
    }

    if (wasNewConversation) {
      window.aipkit_is_fresh_session = false;
    }

    if (typeof window.aipkit_ensureMarkdownParser === "function") {
      void window.aipkit_ensureMarkdownParser();
    }

    const configuredImageTriggers = (config.imageTriggers || "/image")
      .toLowerCase()
      .split(",")
      .map((t) => t.trim())
      .filter((t) => t.length > 0 && t.startsWith("/"));
    const lowerUserText = userText.toLowerCase();
    let matchedTrigger = null;
    for (const trigger of configuredImageTriggers) {
      if (
        lowerUserText.startsWith(trigger + " ") ||
        lowerUserText === trigger
      ) {
        matchedTrigger = trigger;
        break;
      }
    }

    const imagePrompt = matchedTrigger ? userText.substring(matchedTrigger.length).trim() : "";
    if (matchedTrigger && !imagePrompt && lowerUserText !== matchedTrigger) {
      const emptyPromptError = (
        config.text.imageCommandEmptyPrompt ||
        "Please provide description after /image."
      ).replace("[trigger]", matchedTrigger);
      actions.handleError(emptyPromptError, false, true);
      if (textToSend === null)
        state.buttonState = actions.setButtonStateAction("send", false);
      return;
    }

    const submission = {};
    submissions.set(state, submission);
    const conversation = window.aipkit_current_conversation_uuid;
    const record = window.aipkitChatInstances?.[container.id];
    let streamRef;
    const isCurrent = () => submissions.get(state) === submission && state.isSending &&
      container.isConnected !== false && messagesEl.isConnected !== false &&
      container.contains?.(messagesEl) !== false &&
      window.aipkit_current_conversation_uuid === conversation &&
      window.aipkitChatInstances?.[container.id] === record &&
      (!streamRef || state.activeEventSourceRef === streamRef);
    const terminal = (callback, ...args) => {
      if (!isCurrent()) return;
      submissions.delete(state);
      callback(...args);
    };
    const fail = error => terminal(actions.handleError,
      error?.message || config.text.streamError || "Failed to prepare response.", false, true);

    state.isSending = true;
    state.currentStreamMessageId = null;
    state.activeEventSourceRef = null;
    state.activeStreamState = null;
    state.activeStreamWasNewConversation = wasNewConversation;
    state.ignoreNextStreamError = false;
    setInputControlsDisabled(elements, true);

    state.buttonState = actions.setButtonStateAction("stop", true);
    if (!isCurrent()) return;
    if (matchedTrigger) {
      const controller = new AbortController();
      streamRef = { current: null, cancel() {
        if (submissions.get(state) === submission) submissions.delete(state);
        controller.abort();
      } };
      state.activeEventSourceRef = streamRef;
      try {
        if (typeof window.aipkit_chatUI_prepareImageCommandFeature === "function") {
          await window.aipkit_chatUI_prepareImageCommandFeature();
        }
        if (!isCurrent()) return;
        if (typeof window.aipkit_chatUI_handleImageGeneration !== "function") {
          throw new Error("Image generation feature unavailable.");
        }
        await window.aipkit_chatUI_handleImageGeneration(
          imagePrompt, userText, userMessageId, wasNewConversation,
          { elements, config, requestOptions: { signal: controller.signal, isCurrent, conversationUUID: conversation } }
        );
        terminal(() => {
          state.isSending = false;
          state.activeEventSourceRef = null;
          state.activeStreamWasNewConversation = false;
          setInputControlsDisabled(elements, config.requireConsentCompliance && !state.consentGiven);
          state.buttonState = actions.setButtonStateAction("send", false, state);
          if (!submissions.has(state) && container.isConnected !== false &&
              window.aipkit_current_conversation_uuid === conversation &&
              window.aipkitChatInstances?.[container.id] === record &&
              (!config.requireConsentCompliance || state.consentGiven)) actions.focusInputAction();
        });
      } catch (error) {
        terminal(actions.handleError, "Image generation failed: " + (error?.message || "Unknown error"), false, true);
      } finally {
        controller.abort();
      }
      return;
    }

    container.dispatchEvent(
      new CustomEvent("aipkit:messageSent", {
        detail: { messageId: userMessageId },
      })
    );

    if (!isCurrent()) return;
    if (typeof window.aipkit_chatUI_streamMessage === "function") {
      window.aipkit_chatUI_showTypingIndicator(messagesEl, config);
      if (!isCurrent()) return;
      try {
        const streaming = window.aipkit_chatUI_streamMessage(
          userText,
          botId,
          config,
          messagesEl,
          (...args) => terminal(actions.handleError, ...args),
          () => terminal(actions.handleCompletion,
            state.currentStreamMessageId, wasNewConversation),
          state.webSearchToolActive,
          state.googleSearchGroundingActive,
          imageDataPayload,
          activeFileContext,
          userMessageId,
          state,
          { isCurrent }
        );
        streamRef = state.activeEventSourceRef;
        Promise.resolve(streaming).catch(fail);
      } catch (error) {
        fail(error);
      }
    } else {
      const errorMsg =
        config.text.streamError || "Streaming function not loaded.";
      console.error(
        `AIPKit SendMessage (${botId}): Required send function not available.`
      );
      terminal(actions.handleError, errorMsg, false);
    }
  }

  window.aipkit_chatUI_sendMessageAction = aipkit_chatUI_sendMessageAction;

  function aipkit_chatUI_stopStreamAction(elements, config, state, actions) {
    const { inputField, messagesEl } = elements;

    if (!state || !state.isSending || state.buttonState !== "stop") {
      return;
    }
    submissions.delete(state);

    const streamState = state.activeStreamState;
    const eventSourceRef = state.activeEventSourceRef;
    const stoppedMessageId =
      (streamState && streamState.currentStreamMessageId) ||
      state.currentStreamMessageId ||
      null;
    const hasRenderedStreamContent = Boolean(
      streamState &&
        streamState.dataReceived &&
        stoppedMessageId &&
        messagesEl &&
        messagesEl.querySelector(`#${CSS.escape(stoppedMessageId)}`)
    );

    state.ignoreNextStreamError = true;
    eventSourceRef?.cancel?.();
    if (eventSourceRef && eventSourceRef.current) {
      state.ignoredStreamErrorRef = eventSourceRef;
      try {
        eventSourceRef.current.close();
      } catch (error) {
        console.warn(
          "AIPKit StopStreamAction: Failed to close active EventSource.",
          error
        );
      }
      eventSourceRef.current = null;
    }

    if (
      hasRenderedStreamContent &&
      typeof window.aipkit_chatUI_appendOrUpdateMessage === "function"
    ) {
      window.aipkit_chatUI_appendOrUpdateMessage(
        messagesEl,
        stoppedMessageId,
        "",
        "bot",
        config,
        true,
        false,
        streamState.accumulatedGroundingMetadata || null,
        streamState.accumulatedCitations || null
      );
    } else if (typeof window.aipkit_chatUI_removeTypingIndicator === "function") {
      window.aipkit_chatUI_removeTypingIndicator(messagesEl);
    }

    if (typeof window.aipkit_chatUI_scrollToBottom === "function") {
      window.aipkit_chatUI_scrollToBottom(messagesEl, true);
    }

    state.isSending = false;
    state.currentStreamId = null;
    state.currentStreamMessageId = null;
    state.activeEventSourceRef = null;
    state.activeStreamState = null;
    state.activeStreamWasNewConversation = false;

    setInputControlsDisabled(elements, config.requireConsentCompliance && !state.consentGiven);

    const isEmpty = !inputField || inputField.value.trim() === "";
    const hasSomeMessages =
      messagesEl.querySelectorAll(
        ".aipkit_chat_message:not(.aipkit_initial_greeting)"
      ).length > 0;

    state.buttonState = actions.setButtonStateAction(
      isEmpty && hasSomeMessages ? "clear" : "send",
      false,
      state
    );

    if (typeof actions.focusInputAction === "function") {
      actions.focusInputAction();
    }
  }

  window.aipkit_chatUI_stopStreamAction = aipkit_chatUI_stopStreamAction;
})();
