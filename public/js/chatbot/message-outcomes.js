/** Chatbot completion/error UI lifecycle and shared control restoration. */
(function() {
    'use strict';

    const owners = new WeakMap();
    const cancelEvents = ['aipkit:messageSent', 'aipkit:chatCleared', 'aipkit:historyLoaded', 'aipkit:formGateChanged', 'aipkit:popupClosed'];

    function cancelPlayback(owner) {
        if (!owner?.work) return;
        const work = owner.work;
        owner.work = null;
        clearTimeout(work.timer);
        work.controller.abort();
    }

    function getOwner(elements, config, state) {
        const { container } = elements;
        let owner = owners.get(container);
        if (owner && (owner.state !== state || owner.elements !== elements)) {
            owner.lifecycle.suspend();
            owner = null;
        }
        if (!owner) {
            owner = { elements, config, state, active: false, work: null };
            const cancel = () => cancelPlayback(owner);
            owner.lifecycle = {
                suspend() {
                    owner.active = false;
                    cancel();
                    for (const name of cancelEvents) container.removeEventListener?.(name, cancel);
                },
                resume() {
                    if (owner.active || container.isConnected === false || owners.get(container) !== owner) return;
                    owner.active = true;
                    for (const name of cancelEvents) container.addEventListener?.(name, cancel);
                },
                play(button) {
                    if (owner.work?.button === button && (button.disabled || window.aipkitTtsState?.currentlyPlayingButton === button)) {
                        cancel();
                        return;
                    }
                    return preparePlayback(owner, button);
                },
            };
            owners.set(container, owner);
            owner.lifecycle.resume();
        }
        return owner;
    }

    function isCurrent(work) {
        const { owner, button, record, conversation } = work;
        const { elements, config, state } = owner;
        return owner.active && owners.get(elements.container) === owner && owner.work === work &&
            !work.controller.signal.aborted && elements.container.isConnected !== false &&
            elements.messagesEl.contains(button) && button.isConnected !== false && !state.formGatePending &&
            config.ttsEnabled && (!config.requireConsentCompliance || state.consentGiven) &&
            window.aipkit_current_conversation_uuid === conversation &&
            window.aipkitChatInstances?.[elements.container.id] === record;
    }

    function beginPlayback(owner, button) {
        cancelPlayback(owner);
        const work = { owner, button, controller: new AbortController(),
            conversation: window.aipkit_current_conversation_uuid,
            record: window.aipkitChatInstances?.[owner.elements.container.id] };
        owner.work = work;
        return work;
    }

    async function preparePlayback(owner, button, work = beginPlayback(owner, button)) {
        if (!isCurrent(work)) return;
        try {
            if (typeof window.aipkit_chatUI_prepareTtsFeature === 'function') {
                await window.aipkit_chatUI_prepareTtsFeature();
            }
            if (!isCurrent(work)) return;
            if (typeof window.aipkit_handlePlayAction === 'function') {
                return await window.aipkit_handlePlayAction(button, {
                    signal: work.controller.signal, isCurrent: () => isCurrent(work),
                });
            }
        } catch (error) {
            if (isCurrent(work)) console.error('AIPKit: Could not prepare message audio.', error);
        }
    }

    window.aipkit_chatUI_initMessageOutcomes = (elements, config, state) => getOwner(elements, config, state).lifecycle;

    function resetStreamState(state) {
        state.isSending = false;
        state.currentStreamId = null; // For SSE
        state.currentStreamMessageId = null; // For SSE
        state.activeEventSourceRef = null;
        state.activeStreamState = null;
        state.activeStreamWasNewConversation = false;
        state.ignoreNextStreamError = false;
    }

    function restoreControls(inputField, voiceInputButton, inputActionButton, webSearchToggleButton, googleSearchGroundingToggleButton, config, state) {
        inputField.disabled = config.requireConsentCompliance && !state.consentGiven;
        if (voiceInputButton) voiceInputButton.disabled = config.requireConsentCompliance && !state.consentGiven;
        if (inputActionButton) inputActionButton.disabled = config.requireConsentCompliance && !state.consentGiven;
        if (webSearchToggleButton && config.allowWebSearchTool && (config.provider === 'OpenAI' || config.provider === 'Claude' || config.provider === 'OpenRouter' || config.provider === 'xAI')) webSearchToggleButton.disabled = config.requireConsentCompliance && !state.consentGiven;
        if (googleSearchGroundingToggleButton && config.allowGoogleSearchGrounding && config.provider === 'Google') googleSearchGroundingToggleButton.disabled = config.requireConsentCompliance && !state.consentGiven;
    }

    /**
     * Handles the completion of a message generation (both streaming and AJAX).
     * @param {string|null} botMessageId - The ID of the bot message that was completed.
     * @param {boolean} wasNewConversation - Indicates if this completion started a new conversation.
     * @param {object} elements - UI elements.
     * @param {object} config - Chatbot config.
     * @param {object} state - Chat instance state.
     * @param {object} actions - Core action functions { setButtonStateAction, focusInputAction }.
     */
    function aipkit_chatUI_handleCompletion(botMessageId, wasNewConversation, elements, config, state, actions) {
        const { inputField, messagesEl, container, voiceInputButton, inputActionButton, webSearchToggleButton, googleSearchGroundingToggleButton } = elements;

        cancelPlayback(owners.get(container));
        const button = config.ttsEnabled && config.ttsAutoPlay && botMessageId
            ? messagesEl.querySelector(`#${CSS.escape(botMessageId)}`)?.querySelector('.aipkit_play_btn') : null;
        const work = button ? beginPlayback(getOwner(elements, config, state), button) : null;
        resetStreamState(state);

        restoreControls(inputField, voiceInputButton, inputActionButton, webSearchToggleButton, googleSearchGroundingToggleButton, config, state);


        const isEmpty = inputField.value.trim() === '';
        const hasSomeMessages = messagesEl.querySelectorAll('.aipkit_chat_message:not(.aipkit_initial_greeting)').length > 0;
        state.buttonState = actions.setButtonStateAction(isEmpty && hasSomeMessages ? 'clear' : 'send', false, state);

        actions.focusInputAction(); // This will check consent and popup state internally

        container.dispatchEvent(new CustomEvent('aipkit:messageReceived', {
            detail: {
                messageId: botMessageId,
                conversationUUID: window.aipkit_current_conversation_uuid,
                newConversation: wasNewConversation
            }
        }));

        if (work && isCurrent(work)) {
            work.timer = setTimeout(() => { void preparePlayback(work.owner, button, work); }, 100);
        }
    }


    window.aipkit_chatUI_handleCompletion = aipkit_chatUI_handleCompletion;

    /**
     * Handles errors during message sending or streaming.
     * Appends an error message to the chat and resets UI state.
     * @param {string|object} errorMsg - The error message or structured quota notice payload to display.
     * @param {boolean} isNetworkError - Indicates if it's a network/connection error.
     * @param {boolean} [forceScroll=true] - Whether to force scroll to bottom.
     * @param {object} elements - UI elements.
     * @param {object} config - Chatbot config.
     * @param {object} state - Chat instance state.
     * @param {object} actions - Core action functions { setButtonStateAction, focusInputAction }.
     */
    function aipkit_chatUI_handleError(errorMsg, isNetworkError, forceScroll = true, elements, config, state, actions) {
        const { messagesEl, inputField, container, voiceInputButton, inputActionButton, webSearchToggleButton, googleSearchGroundingToggleButton } = elements;
        const botId = config.botId;
        const isQuotaNotice = Boolean(
            errorMsg &&
            typeof errorMsg === 'object' &&
            (
                errorMsg.type === 'quota_notice' ||
                (errorMsg.notice && typeof errorMsg.notice === 'object')
            )
        );

        cancelPlayback(owners.get(container));
        resetStreamState(state);

        const errorClientMsgId = window.aipkit_generateClientMessageId(botId);
        window.aipkit_chatUI_appendMessage(messagesEl, errorMsg, 'bot', config, !isQuotaNotice, false, errorClientMsgId, forceScroll);
        window.aipkit_chatUI_removeTypingIndicator(messagesEl);

        restoreControls(inputField, voiceInputButton, inputActionButton, webSearchToggleButton, googleSearchGroundingToggleButton, config, state);

        state.buttonState = actions.setButtonStateAction('send', false, state);
        actions.focusInputAction();

        container.dispatchEvent(new CustomEvent('aipkit:messageError', {
            detail: {
                messageId: errorClientMsgId,
                error: errorMsg
            }
        }));
    }

    window.aipkit_chatUI_handleError = aipkit_chatUI_handleError;

})();
