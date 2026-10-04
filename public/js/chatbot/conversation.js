/** Chatbot conversation reset and shared greeting composition. */
export function composeGreetingText(greetingText, subgreetingText) {
    if (!subgreetingText) {
        return greetingText;
    }
    return greetingText
        ? `${greetingText}\n\n${subgreetingText}`
        : subgreetingText;
}

(function() {
    'use strict';

    /**
     * Clears all messages from the chat display, except for the initial greeting.
     * @param {HTMLElement} messagesEl - The messages container element.
     */
    function aipkit_chatUI_clearMessages(messagesEl) {
        if (!messagesEl) return;
        const messagesToRemove = messagesEl.querySelectorAll(
            ".aipkit_chat_message:not(.aipkit_initial_greeting)"
        );
        messagesToRemove.forEach((msg) => {
            try {
                messagesEl.removeChild(msg);
            } catch (e) {
                if (e.name !== "NotFoundError") // Ignore if node already removed by other means
                    console.warn("AIPKit clearMessages: Error removing node:", e);
            }
        });
        if (typeof window.aipkit_chatUI_scrollToBottom === "function") {
            window.aipkit_chatUI_scrollToBottom(messagesEl, true);
        }
    }

    window.aipkit_chatUI_clearMessages = aipkit_chatUI_clearMessages;

})();

(function () {
  "use strict";

  /**
   * Handles the action of clearing the chat interface.
   * @param {object} elements - UI elements { messagesEl, inputField, imagePreviewContainer, imageUploadInput, fileUploadInput, inputArea }.
   * @param {object} config - The chatbot configuration object.
   * @param {object} state - The chat instance's state object.
   * @param {object} actions - An object containing action functions: { setButtonStateAction, focusInputAction }.
   */
  function aipkit_chatUI_clearChatAction(elements, config, state, actions) {
    const { messagesEl, inputField } = elements;
    window.aipkit_chatUI_cancelSpeechInput?.(elements.container, state);

    if (typeof window.aipkit_chatUI_setFormGateState === "function") {
      window.aipkit_chatUI_setFormGateState(
        elements.container,
        false,
        null,
        { elements, config, state, actions }
      );
    }

    if (typeof window.aipkit_chatUI_removeTypingIndicator !== "function") {
      console.error(
        "AIPKit Clear Action: Dependency window.aipkit_chatUI_removeTypingIndicator not found."
      );
    } else {
      window.aipkit_chatUI_removeTypingIndicator(messagesEl);
    }

    state.activeEventSourceRef?.cancel?.();
    if (state.activeEventSourceRef && state.activeEventSourceRef.current) {
      state.ignoreNextStreamError = true;
      state.ignoredStreamErrorRef = state.activeEventSourceRef;
      try {
        state.activeEventSourceRef.current.close();
      } catch (error) {
        console.warn(
          "AIPKit Clear Action: Failed to close active stream before clearing.",
          error
        );
      }
      state.activeEventSourceRef.current = null;
    }

    if (typeof window.aipkit_chatUI_clearMessages !== "function") {
      console.error(
        "AIPKit Clear Action: Dependency window.aipkit_chatUI_clearMessages not found."
      );
    } else {
      window.aipkit_chatUI_clearMessages(messagesEl);
    }

    const existingGreeting = messagesEl.querySelector(
      ".aipkit_initial_greeting"
    );
    if (
      !existingGreeting &&
      typeof window.aipkit_chatUI_appendMessage === "function"
    ) {
      const greetingText = (config?.text?.initialGreeting || "Hello there!").trim();
      const subgreetingText = (config?.text?.initialSubgreeting || "").trim();
      const initialGreetingText = composeGreetingText(greetingText, subgreetingText);
      if (typeof window.aipkit_generateClientMessageId === "function") {
        window.aipkit_chatUI_appendMessage(
          messagesEl,
          initialGreetingText,
          "bot",
          config,
          false,
          true,
          window.aipkit_generateClientMessageId(config.botId)
        );
      } else {
        console.error(
          "AIPKit Clear Action: window.aipkit_generateClientMessageId is missing, cannot append initial greeting reliably."
        );
      }
    } else if (!existingGreeting) {
      console.error(
        "AIPKit Clear Action: Initial greeting missing after clear, and appendMessage function unavailable."
      );
    }

    if (inputField) {
      inputField.value = "";
      inputField.disabled =
        config.requireConsentCompliance && !state.consentGiven;
      if (typeof window.aipkit_autoResizeTextarea === "function") {
        window.aipkit_autoResizeTextarea(inputField);
      }
    }

    if (
      config.imageUploadEnabledUI &&
      window.chatImageUpload &&
      typeof window.chatImageUpload.reset === "function"
    ) {
      if (elements.imagePreviewContainer && elements.imageUploadInput) {
        window.chatImageUpload.reset(
          elements.imagePreviewContainer,
          elements.imageUploadInput
        );
      } else {
        console.warn(
          "AIPKit Clear Action: Image upload UI reset skipped, preview or input element missing from 'elements' object passed to clearChatAction."
        );
      }
    } else if (
      config.imageUploadEnabledUI &&
      typeof window.chatImageUpload === "undefined"
    ) {
      console.warn(
        "AIPKit Clear Action: Image upload UI reset skipped, chatImageUpload script not loaded."
      );
    }

    if (
      config.fileUploadEnabledUI &&
      window.aipkitChatFileUpload &&
      typeof window.aipkitChatFileUpload.resetUI === "function"
    ) {
      if (elements.fileUploadInput && elements.inputArea) {
        window.aipkitChatFileUpload.resetUI(
          elements.fileUploadInput,
          elements.inputArea
        );
      } else {
        console.warn(
          "AIPKit Clear Action: File upload UI reset skipped, file input or input area missing from 'elements' object."
        );
      }
    } else if (
      config.fileUploadEnabledUI &&
      typeof window.aipkitChatFileUpload === "undefined"
    ) {
      console.warn(
        "AIPKit Clear Action: File upload UI reset skipped, aipkitChatFileUpload script not loaded."
      );
    }

    if (typeof actions.setButtonStateAction === "function") {
      state.buttonState = actions.setButtonStateAction("send", false);
    } else {
      console.error(
        "AIPKit Clear Action: actions.setButtonStateAction function not provided."
      );
    }
    const webToggleDefaultOn = config.webToggleDefaultOn === true;

    if (typeof window.aipkit_syncWebSearchToggleState === "function") {
      window.aipkit_syncWebSearchToggleState(
        elements,
        config,
        state,
        webToggleDefaultOn
      );
    }
    if (typeof window.aipkit_syncGoogleSearchGroundingToggleState === "function") {
      window.aipkit_syncGoogleSearchGroundingToggleState(
        elements,
        config,
        state,
        webToggleDefaultOn
      );
    }

    state.isSending = false;
    state.currentStreamId = null;
    state.currentStreamMessageId = null;
    state.activeEventSourceRef = null;
    state.activeStreamState = null;
    state.activeStreamWasNewConversation = false;

    window.aipkit_current_conversation_uuid = null;
    window.aipkit_is_fresh_session = true;
    sessionStorage.removeItem("aipkit_current_conversation_uuid");
    window.aipkit_current_openai_response_id = null;
    sessionStorage.removeItem("aipkit_current_openai_response_id");
    window.aipkit_current_google_interaction_id = null;
    sessionStorage.removeItem("aipkit_current_google_interaction_id");

    window.aipkit_chatUI_clearFileContext();

    if (typeof window.aipkit_regenerateConversationUUID === "function") {
      window.aipkit_regenerateConversationUUID();
    }

    if (
      typeof actions.focusInputAction === "function" &&
      (!config.requireConsentCompliance || state.consentGiven)
    ) {
      actions.focusInputAction();
    } else if (typeof actions.focusInputAction !== "function") {
      console.error(
        "AIPKit Clear Action: actions.focusInputAction function not provided."
      );
    }

    const container =
      messagesEl.closest(".aipkit_chat_container") ||
      messagesEl.closest(".aipkit_popup_wrapper");
    if (container) {
      container.dispatchEvent(new CustomEvent("aipkit:chatCleared"));
    }
  }

  window.aipkit_chatUI_clearChatAction = aipkit_chatUI_clearChatAction;
})();
