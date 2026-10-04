/**
 * Chatbot streaming event handling and response state updates.
 */
(function () {
    'use strict';

    function storeConversationId(key, value) {
        window[key] = value;
        sessionStorage.setItem(key, value);
    }

    function accumulateCitations(stateRef, incomingCitations) {
        const normalize = typeof window.aipkit_chatUI_normalizeCitations === 'function'
            ? window.aipkit_chatUI_normalizeCitations
            : (items) => items;
        stateRef.accumulatedCitations = normalize((stateRef.accumulatedCitations || []).concat(incomingCitations));
    }

    function appendStreamDelta(messagesEl, messageId, delta, config) {
        window.aipkit_chatUI_appendOrUpdateMessage(messagesEl, messageId, delta, 'bot', config, false, false, null);
        window.aipkit_chatUI_scrollToBottom(messagesEl, true);
    }

    /**
     * Handles the 'message_start' event from the SSE stream.
     * @param {Event} event - The SSE event object.
     * @param {object} config - Chatbot configuration (for provider, enableOpenAIConversationState).
     * @param {object} stateRef - A reference object { currentStreamMessageId: null } to update.
     * @param {HTMLElement} messagesEl - The messages container element.
     */
    function aipkit_chatUI_handleMessageStartEvent(event, config, stateRef, messagesEl) {
        try {
            const startData = JSON.parse(event.data);
            const newStreamMessageId = startData.message_id;

            if (newStreamMessageId) {
                if (stateRef.currentStreamMessageId && stateRef.currentStreamMessageId !== newStreamMessageId && messagesEl) {
                    const oldMessageEl = messagesEl.querySelector(`#${CSS.escape(stateRef.currentStreamMessageId)}`);
                    if (oldMessageEl) {
                        const oldBubble = oldMessageEl.querySelector('.aipkit_chat_bubble');
                        if (oldBubble) {
                            const oldIndicator = oldBubble.querySelector('.aipkit_stream_indicator');
                            if (oldIndicator && oldIndicator.parentNode) {
                                oldIndicator.parentNode.removeChild(oldIndicator);
                            }
                        }
                        oldMessageEl.classList.remove('aipkit_message_streaming');
                        oldMessageEl.classList.add('aipkit_message_complete'); // Mark as complete
                    }
                }
                stateRef.currentStreamMessageId = newStreamMessageId;
            }

            if (config.provider === 'OpenAI' && config.enableOpenAIConversationState && startData.openai_response_id) {
                storeConversationId('aipkit_current_openai_response_id', startData.openai_response_id);
            }
        } catch (e) {
            console.error(`[AIPKit Stream HandleMessageStart] Error parsing message_start data:`, event.data, e);
        }
    }

    window.aipkit_chatUI_handleMessageStartEvent = aipkit_chatUI_handleMessageStartEvent;

    /**
     * Handles the 'openai_response_id' event from the SSE stream.
     * @param {Event} event - The SSE event object.
     * @param {object} config - Chatbot configuration (for provider, enableOpenAIConversationState).
     */
    function aipkit_chatUI_handleOpenAIResponseIdEvent(event, config) {
        try {
            const idData = JSON.parse(event.data);
            if (config.provider === 'OpenAI' && config.enableOpenAIConversationState && idData.id) {
                storeConversationId('aipkit_current_openai_response_id', idData.id);
            }
        } catch (e) {
            console.error(`[AIPKit Stream HandleOpenAIResponseId] Error parsing data:`, event.data, e);
        }
    }

    window.aipkit_chatUI_handleOpenAIResponseIdEvent = aipkit_chatUI_handleOpenAIResponseIdEvent;

  function aipkit_chatUI_handleGoogleInteractionIdEvent(event, config) {
    try {
      const idData = JSON.parse(event.data);
      if (
        config.provider === "Google" &&
        config.enableGoogleConversationState &&
        idData.id
      ) {
        storeConversationId('aipkit_current_google_interaction_id', idData.id);
      }
    } catch (error) {
      console.error(
        "[AIPKit Stream HandleGoogleInteractionId] Error parsing data:",
        event.data,
        error
      );
    }
  }

  window.aipkit_chatUI_handleGoogleInteractionIdEvent =
    aipkit_chatUI_handleGoogleInteractionIdEvent;

    /**
     * Handles the 'grounding_metadata' event from the SSE stream.
     * @param {Event} event - The SSE event object.
     * @param {object} stateRef - A reference object { accumulatedGroundingMetadata: null } to update.
     */
    function aipkit_chatUI_handleGroundingMetadataEvent(event, stateRef) {
        try {
            const metadata = JSON.parse(event.data);
            stateRef.accumulatedGroundingMetadata = metadata; // Store it
        } catch (e) {
            console.error(`[AIPKit Stream HandleGroundingMetadata] Error parsing data:`, event.data, e);
        }
    }

    window.aipkit_chatUI_handleGroundingMetadataEvent = aipkit_chatUI_handleGroundingMetadataEvent;

  function isGenericStatusText(value, textLabels) {
    const normalized = typeof value === "string" ? value.trim().toLowerCase() : "";
    if (normalized === "") {
      return false;
    }

    const genericLabels = [
      textLabels.statusProcessing,
      textLabels.streaming,
      "processing",
      "processing...",
      "streaming",
      "streaming...",
    ]
      .filter((label) => typeof label === "string" && label.trim() !== "")
      .map((label) => label.trim().toLowerCase());

    return genericLabels.includes(normalized);
  }

  function resolveStatusText(statusData, config) {
    const textLabels = config && config.text ? config.text : {};

    if (typeof statusData?.text === "string" && statusData.text.trim() !== "") {
      return isGenericStatusText(statusData.text, textLabels)
        ? ""
        : statusData.text.trim();
    }

    if (
      typeof statusData?.label === "string" &&
      statusData.label.trim() !== ""
    ) {
      return isGenericStatusText(statusData.label, textLabels)
        ? ""
        : statusData.label.trim();
    }

    const statusType = String(statusData?.type || "").toLowerCase();
    const blockType = String(statusData?.content_block_type || "").toLowerCase();
    const toolName = String(statusData?.name || "").toLowerCase();

    if (
      statusType.includes("web_search") ||
      blockType.includes("web_search") ||
      toolName.includes("web_search")
    ) {
      return textLabels.statusSearchingWeb || "Searching web...";
    }

    if (
      statusType.includes("file_search") ||
      blockType.includes("file_search") ||
      toolName.includes("file_search")
    ) {
      return textLabels.statusRetrievingContext || "";
    }

    if (
      statusType.includes("tool") ||
      statusType.includes("function_call") ||
      statusType.includes("image_generation_call") ||
      blockType === "tool_use" ||
      blockType === "server_tool_use" ||
      statusData?.tool_use_id
    ) {
      return textLabels.statusCallingTool || "Calling tool...";
    }

    return "";
  }

  function getStatusTarget(messagesEl, stateRef, botId) {
    if (!messagesEl) {
      return null;
    }

    if (stateRef.currentStreamMessageId) {
      const messageEl = messagesEl.querySelector(
        `#${CSS.escape(stateRef.currentStreamMessageId)}`
      );
      if (messageEl) {
        return messageEl;
      }
    }

    const indicatorId = `aipkit-typing-indicator-${botId}`;
    return messagesEl.querySelector(`#${CSS.escape(indicatorId)}`);
  }

  function aipkit_chatUI_handleStatusEvent(
    event,
    botId,
    config,
    messagesEl,
    stateRef
  ) {
    if (stateRef && stateRef.dataReceived) {
      return;
    }

    try {
      const statusData = JSON.parse(event.data);
      const statusText = resolveStatusText(statusData, config);
      if (statusText === "") {
        return;
      }

      stateRef.currentStatusText = statusText;

      const targetMessage = getStatusTarget(messagesEl, stateRef, botId);
      if (
        targetMessage &&
        typeof window.aipkit_chatUI_upsertMessageMeta === "function"
      ) {
        window.aipkit_chatUI_upsertMessageMeta(
          targetMessage,
          { statusText },
          config
        );
      }
    } catch (error) {
      console.error(
        `[AIPKit Stream HandleStatus (${botId})] Error parsing status data:`,
        event.data,
        error
      );
    }
  }

  window.aipkit_chatUI_handleStatusEvent = aipkit_chatUI_handleStatusEvent;

  function aipkit_chatUI_handleCitationsEvent(
    event,
    botId,
    config,
    messagesEl,
    stateRef
  ) {
    try {
      const payload = JSON.parse(event.data);
      const incomingCitations = Array.isArray(payload) ? payload : [payload];
      accumulateCitations(stateRef, incomingCitations);
    } catch (error) {
      console.error(
        `[AIPKit Stream HandleCitations (${botId})] Error parsing citations data:`,
        event.data,
        error
      );
    }
  }

  window.aipkit_chatUI_handleCitationsEvent = aipkit_chatUI_handleCitationsEvent;

    function renderStreamingBubbleContent(bubble, content, config) {
        const renderer = typeof window.aipkit_getMarkdownRenderer === 'function'
            ? window.aipkit_getMarkdownRenderer()
            : window.aipkit_md;

        bubble.setAttribute('data-raw-text', content);

        if (renderer && typeof renderer.render === 'function') {
            bubble.innerHTML = renderer.render(content);
            if (typeof window.aipkit_chatUI_attachCodeCopyButtons === 'function') {
                window.aipkit_chatUI_attachCodeCopyButtons(bubble, config);
            }
        } else {
            bubble.innerHTML = String(content || '').replace(/\n/g, '<br>');
        }

        if (
            renderer &&
            renderer.aipkitIsFallback &&
            typeof window.aipkit_ensureMarkdownParser === 'function' &&
            bubble.dataset.aipkitMarkdownUpgradePending !== '1'
        ) {
            bubble.dataset.aipkitMarkdownUpgradePending = '1';
            void window.aipkit_ensureMarkdownParser().then(function(upgradedRenderer) {
                delete bubble.dataset.aipkitMarkdownUpgradePending;
                if (!bubble.isConnected || !upgradedRenderer || upgradedRenderer.aipkitIsFallback) {
                    return;
                }

                const latestContent = bubble.dataset.aipkitFullContent || bubble.getAttribute('data-raw-text') || content;
                bubble.innerHTML = upgradedRenderer.render(latestContent);
                if (typeof window.aipkit_chatUI_attachCodeCopyButtons === 'function') {
                    window.aipkit_chatUI_attachCodeCopyButtons(bubble, config);
                }
            });
        }
    }

    /**
     * Handles the default 'message' event from the SSE stream (for data chunks).
     * @param {Event} event - The SSE event object.
     * @param {string} botId - The ID of the chatbot.
     * @param {object} config - Chatbot configuration.
     * @param {HTMLElement} messagesEl - The messages container element.
     * @param {object} stateRef - A reference object { dataReceived: boolean, currentStreamMessageId: string|null } to update/read.
     */
    function aipkit_chatUI_handleOnMessageEvent(event, botId, config, messagesEl, stateRef) {
        try {
            const eventData = JSON.parse(event.data);
            const delta = eventData.delta;

            if (delta !== undefined && stateRef.currentStreamMessageId) {
                if (!stateRef.dataReceived) {
                    stateRef.dataReceived = true;
                    stateRef.currentStatusText = null;
                    const indicatorId = `aipkit-typing-indicator-${botId}`;
                    const indicatorEl = messagesEl.querySelector(`#${CSS.escape(indicatorId)}`);

                    if (indicatorEl) {
                        const bubble = indicatorEl.querySelector('.aipkit_chat_bubble');
                        if (bubble) {
                            indicatorEl.id = stateRef.currentStreamMessageId;
                            indicatorEl.setAttribute('data-message-id', stateRef.currentStreamMessageId);
                            indicatorEl.classList.remove('aipkit_typing-indicator');
                            if (typeof window.aipkit_chatUI_upsertMessageMeta === 'function') {
                                window.aipkit_chatUI_upsertMessageMeta(indicatorEl, { statusText: null }, config);
                            }

                            bubble.dataset.aipkitFullContent = delta;
                            renderStreamingBubbleContent(bubble, delta, config);
                            if (typeof window.positionStreamingIndicator === 'function') {
                                window.positionStreamingIndicator(bubble, 'aipkit_stream_indicator');
                            }

                            const actionsHTML = typeof window.aipkit_chatUI_createActionsContainerHTML === 'function'
                                ? window.aipkit_chatUI_createActionsContainerHTML(config)
                                : '';
                            if (actionsHTML && !indicatorEl.querySelector('.aipkit_message_actions')) {
                                indicatorEl.insertAdjacentHTML('beforeend', actionsHTML);
                                indicatorEl.classList.add('aipkit_has_message_actions');
                            }
                            window.aipkit_chatUI_scrollToBottom(messagesEl, true);
                        } else {
                            window.aipkit_chatUI_removeTypingIndicator(messagesEl, indicatorEl);
                            appendStreamDelta(messagesEl, stateRef.currentStreamMessageId, delta, config);
                        }
                    } else {
                        appendStreamDelta(messagesEl, stateRef.currentStreamMessageId, delta, config);
                    }
                } else {
                    appendStreamDelta(messagesEl, stateRef.currentStreamMessageId, delta, config);
                }
            } else {
                console.warn(`[AIPKit Stream OnMessage (${botId})] Received message without delta or missing message ID:`, eventData);
            }
        } catch (e) {
            console.error(`[AIPKit Stream OnMessage (${botId})] Error parsing message data:`, event.data, e);
        }
    }

    window.aipkit_chatUI_handleOnMessageEvent = aipkit_chatUI_handleOnMessageEvent;

    /**
     * Handles the 'done' event from the SSE stream.
     * @param {Event} event - The SSE event object.
     * @param {string} botId - The ID of the chatbot.
     * @param {object} config - Chatbot configuration.
     * @param {HTMLElement} messagesEl - The messages container element.
     * @param {function} onCompleteCallback - Callback function to execute on completion.
     * @param {object} eventSourceRef - A reference object { current: EventSource|null } to manage the EventSource.
     * @param {object} stateRef - A reference object { currentStreamMessageId: string|null, accumulatedGroundingMetadata: object|null } to read from.
     */
    function aipkit_chatUI_handleDoneEvent(event, botId, config, messagesEl, onCompleteCallback, eventSourceRef, stateRef) {
        try {
            const doneData = JSON.parse(event.data);
            if (!stateRef.accumulatedGroundingMetadata && doneData && doneData.grounding_metadata) {
                stateRef.accumulatedGroundingMetadata = doneData.grounding_metadata;
            }
            if (doneData && Array.isArray(doneData.citations) && doneData.citations.length) {
                accumulateCitations(stateRef, doneData.citations);
            }
        } catch (error) {
            // Ignore done payload parsing failures and continue stream finalization.
        }
        if (typeof window.aipkit_chatUI_appendOrUpdateMessage === 'function' && stateRef.currentStreamMessageId) {
            window.aipkit_chatUI_appendOrUpdateMessage(
                messagesEl,
                stateRef.currentStreamMessageId,
                '', // No more text delta
                'bot',
                config,
                true, // isComplete
                false, // isError
                stateRef.accumulatedGroundingMetadata, // Pass accumulated metadata
                stateRef.accumulatedCitations || null
            );
        }
        if (typeof window.aipkit_chatUI_scrollToBottom === 'function') {
             window.aipkit_chatUI_scrollToBottom(messagesEl, true);
        }
        if (typeof onCompleteCallback === 'function') {
            onCompleteCallback();
        }
        if (eventSourceRef.current) {
            eventSourceRef.current.close();
            eventSourceRef.current = null;
        }
    }

    window.aipkit_chatUI_handleDoneEvent = aipkit_chatUI_handleDoneEvent;

    /**
     * Handles the 'warning' event from the SSE stream.
     * @param {Event} event - The SSE event object.
     * @param {string} botId - The ID of the chatbot.
     * @param {object} config - Chatbot configuration.
     * @param {HTMLElement} messagesEl - The messages container element.
     * @param {object} stateRef - A reference object { dataReceived: boolean, currentStreamMessageId: string|null } to update/read.
     */
    function aipkit_chatUI_handleWarningEvent(event, botId, config, messagesEl, stateRef) {
        console.warn(`[AIPKit Stream HandleWarning (${botId})] Received 'warning' event:`, event.data);
        if (!stateRef.dataReceived) stateRef.dataReceived = true; // Mark data as received even for a warning
        try {
            const warnData = JSON.parse(event.data);
            if (warnData.error && stateRef.currentStreamMessageId) {
                if (typeof window.aipkit_chatUI_appendOrUpdateMessage === 'function') {
                    window.aipkit_chatUI_appendOrUpdateMessage(
                        messagesEl,
                        stateRef.currentStreamMessageId,
                        `\n\n*${warnData.error}*`,
                        'bot',
                        config,
                        false, // isComplete
                        true,  // isError (treat warning as an error display for simplicity)
                        null   // no grounding data for warning
                    );
                    window.aipkit_chatUI_scrollToBottom(messagesEl, true);
                }
            }
        } catch (e) {
            console.error(`[AIPKit Stream HandleWarning (${botId})] Error parsing warning data:`, event.data, e);
        }
    }

    window.aipkit_chatUI_handleWarningEvent = aipkit_chatUI_handleWarningEvent;

    /**
     * Handles the 'onerror' event from the EventSource object.
     * @param {Event} event - The SSE error event object.
     * @param {string} botId - The ID of the chatbot.
     * @param {object} config - Chatbot configuration (for text labels).
     * @param {HTMLElement} messagesEl - The messages container element.
     * @param {function} onErrorCallback - Callback function to execute on error.
     * @param {object} eventSourceRef - A reference object { current: EventSource|null } to manage the EventSource.
     * @param {object} stateRef - A reference object { dataReceived: boolean, currentStreamMessageId: string|null } to update.
     */
    function aipkit_chatUI_handleErrorEvent(event, botId, config, messagesEl, onErrorCallback, eventSourceRef, stateRef) {
        window.aipkit_chatUI_removeTypingIndicator(messagesEl);
        const textLabels = config.text || {};
        console.error(`[AIPKit Stream HandleError (${botId})] EventSource error.`, event);

        let specificErrorMsg = null;
        let quotaNotice = null;
        if (event.data) { // Note: EventSource error events don't always have 'data' in the same way 'message' events do.
            try {
                const errorData = JSON.parse(event.data);
                if (errorData.error) {
                    specificErrorMsg = errorData.error;
                }
                if (errorData.quota_notice) {
                    quotaNotice = errorData.quota_notice;
                }
            } catch (e) {
                if (typeof event.data === 'string' && event.data.length > 0 && event.data.length < 200) {
                    specificErrorMsg = event.data;
                }
            }
        } else if (event.target && event.target.readyState === EventSource.CLOSED) {
            specificErrorMsg = "Connection was closed.";
        } else if (event.message) { // Some browsers might populate event.message on network errors
            specificErrorMsg = event.message;
        }


        let finalErrorMsg = '';
        if (specificErrorMsg) {
            finalErrorMsg = `${textLabels.errorPrefix || 'Error:'} ${specificErrorMsg}`;
        } else {
            finalErrorMsg = stateRef.dataReceived
                ? (textLabels.streamError || 'Stream error. Please try again.')
                : (textLabels.connError || 'Connection error. Please check setup or try again.');
        }

        if (typeof onErrorCallback === 'function') {
            if (quotaNotice) {
                onErrorCallback({
                    type: 'quota_notice',
                    notice: quotaNotice,
                    message: specificErrorMsg || quotaNotice.message || ''
                }, false, true);
            } else {
                onErrorCallback(finalErrorMsg, false, true); // isNetworkError = false (as it's an EventSource error event), forceScroll = true
            }
        }

        stateRef.currentStreamMessageId = null; // Reset message ID on error
        if (eventSourceRef.current) {
            eventSourceRef.current.close();
            eventSourceRef.current = null;
        }
    }

    window.aipkit_chatUI_handleErrorEvent = aipkit_chatUI_handleErrorEvent;
})();
