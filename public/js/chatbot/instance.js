/**
 * Chatbot UI orchestration for a single instance.
 * Element discovery lives in elements.js; control listeners live in events.js.
 */

function aipkit_setupChatInstanceUI(container, config) {
    // 1. Find core DOM elements (now includes potential creation of image elements by findElements)
    const elements = window.aipkit_chatUI_findElements(container, config); // findElements now handles image elements
    if (!elements) {
        const errorTarget = container.querySelector('.aipkit_chat_container') || container;
        if (errorTarget && typeof window.aipkit_chatUI_renderUIState === 'function') {
            window.aipkit_chatUI_renderUIState(errorTarget, {
                message: 'Chatbot UI error (DOM elements missing).',
                type: 'error',
                scope: 'messages'
            });
        } else if (errorTarget) {
            errorTarget.textContent = 'Chatbot UI error (DOM elements missing).';
        }
        return null;
    }

    const botId = config.botId;
    const isAdminPreview = !!container.closest('#aipkit_admin_chat_preview_container');

    // 2. Initialize State
    const state = window.aipkit_chatUI_initState(config);
    state.consentUIInstance = null; // Will be set by consent UI init if needed

    // 3. Define Core Action Functions (passed to other components)
    const actions = {
        setButtonStateAction: (newState, forceSendState = false) => window.aipkit_chatUI_setButtonStateAction(newState, elements, config, state, forceSendState),
        focusInputAction: () => window.aipkit_chatUI_focusInputAction(elements.inputField, config, state),
        sendMessageAction: (textToSend = null) => window.aipkit_chatUI_sendMessageAction(elements, config, state, actions, textToSend),
        stopStreamAction: (focusInput = true) => window.aipkit_chatUI_stopStreamAction(elements, config, state,
            focusInput ? actions : { ...actions, focusInputAction() {} }),
        clearChatAction: () => window.aipkit_chatUI_clearChatAction(elements, config, state, actions),
        handleError: (errorMsg, isNetworkError, forceScroll = true) => window.aipkit_chatUI_handleError(errorMsg, isNetworkError, forceScroll, elements, config, state, actions),
        handleCompletion: (botMessageId, wasNewConversation = false) => window.aipkit_chatUI_handleCompletion(botMessageId, wasNewConversation, elements, config, state, actions),
        toggleFullscreenAction: () => window.aipkit_chatUI_toggleFullscreen(elements, state, config),
        downloadTranscriptTxtAction: () => window.aipkit_chatUI_downloadTranscriptActionTxt(elements, config),
        downloadTranscriptPdfAction: async (printWindow = null) => {
            const closePrintWindow = () => {
                if (printWindow && !printWindow.closed && typeof printWindow.close === 'function') {
                    printWindow.close();
                }
            };
            if (typeof window.aipkit_chatUI_preparePdfFeature === 'function') {
                try {
                    const ready = await window.aipkit_chatUI_preparePdfFeature(config);
                    if (!ready) {
                        console.warn(`AIPKit Chat Main (${botId}): PDF download feature is unavailable.`);
                        closePrintWindow();
                        return null;
                    }
                } catch (error) {
                    console.error(`AIPKit Chat Main (${botId}): Failed to lazy-load PDF download feature.`, error);
                    closePrintWindow();
                    if (config?.text?.pdfError) {
                        alert(config.text.pdfError);
                    }
                    return null;
                }
            }
            if (typeof window.aipkit_chatUI_downloadTranscriptActionPdf === 'function') {
                return window.aipkit_chatUI_downloadTranscriptActionPdf(elements, config, printWindow);
            }
            console.warn(`AIPKit Chat Main (${botId}): PDF download action is unavailable.`);
            closePrintWindow();
            return null;
        },
        handlePlayAction: (playButton) => messageLifecycle?.play(playButton),
    };

    // 4. Initialize Consent UI (if required)
    if (config.requireConsentCompliance && typeof window.aipkit_initConsentUI === 'function') {
        state.consentUIInstance = window.aipkit_initConsentUI(elements.mainContentEl, config, state, () => {
            // This callback is executed when consent is given
            elements.inputField.disabled = false;
            actions.setButtonStateAction('send', false); // Relies on 'actions' being defined below
            actions.focusInputAction();
            // Re-enable feature buttons if they exist
            if (elements.voiceInputButton) elements.voiceInputButton.disabled = false;
            if (elements.inputActionButton) elements.inputActionButton.disabled = false;
            if (elements.webSearchToggleButton) elements.webSearchToggleButton.disabled = false;
            if (elements.googleSearchGroundingToggleButton) elements.googleSearchGroundingToggleButton.disabled = false;
        });
    } else if (config.requireConsentCompliance) {
        console.error(`AIPKit Chat Main (${botId}): Consent required, but aipkit_initConsentUI function not found.`);
    }

    // 5. Setup specific UI features and listeners
    if (config.imageUploadEnabledUI) {
        if (elements.container) elements.container.classList.add('aipkit-image-upload-enabled');
        if (!elements.imageUploadInput || !elements.imagePreviewContainer) {
            console.warn(`AIPKit Chat UI Main (${botId}): Image Upload UI enabled, but input or preview elements were not found/created by findElements.`);
        }
    }

    if (typeof window.aipkit_chatUI_setupInputActionButton === 'function') {
        window.aipkit_chatUI_setupInputActionButton(elements, config, state);
    } else {
        console.error(`AIPKit Chat Main (${botId}): setupInputActionButton function not found.`);
        if (elements.inputActionButton) elements.inputActionButton.hidden = true;
        if (elements.inputActionMenu) {
            elements.inputActionMenu.classList.remove('aipkit-action-menu-open');
            elements.inputActionMenu.setAttribute('aria-hidden', 'true');
            elements.inputActionMenu.setAttribute('hidden', 'hidden');
        }
    }

    if (typeof window.aipkit_chatUI_attachEventListeners === 'function') {
        window.aipkit_chatUI_attachEventListeners(elements, config, state, actions);
    } else {
        console.error(`AIPKit Chat Main (${botId}): attachEventListeners not defined.`);
    }

    if (typeof window.aipkit_chatUI_initMessageActions === 'function') {
        window.aipkit_chatUI_initMessageActions(elements.messagesEl, config);
    } else {
        console.warn(`AIPKit Chat Main (${botId}): aipkit_chatUI_initMessageActions missing; message actions might not work.`);
    }

    let scrollLifecycle;
    if (typeof window.aipkit_chatUI_initScrollToBottomButton === 'function') {
        scrollLifecycle = window.aipkit_chatUI_initScrollToBottomButton(elements, config, state);
    } else {
        console.warn(`AIPKit Chat Main (${botId}): aipkit_chatUI_initScrollToBottomButton missing; scroll button won't render.`);
    }

    let popupLifecycle, formLifecycle, messageLifecycle;
    const lifecycle = {
        suspend() {
            window.aipkit_chatUI_cancelSpeechInput?.(elements.container, state);
            if (typeof window.aipkit_chatUI_stopStreamAction === 'function') actions.stopStreamAction(false);
            messageLifecycle?.suspend(); formLifecycle?.suspend(); popupLifecycle?.suspend(); scrollLifecycle?.suspend();
            state.realtimeLifecycle?.suspend();
        },
        resume() { scrollLifecycle?.resume(); popupLifecycle?.resume(); formLifecycle?.resume(); messageLifecycle?.resume(); state.realtimeLifecycle?.resume(); }
    };

    try {
        if (config.ttsEnabled && typeof window.aipkit_chatUI_initMessageOutcomes === 'function') {
            messageLifecycle = window.aipkit_chatUI_initMessageOutcomes(elements, config, state);
        }
        if (config.formGateEnabled && typeof window.aipkit_chatUI_initForms === 'function') {
            formLifecycle = window.aipkit_chatUI_initForms(elements, config, state, actions);
        }
        if (config.enableStarters) {
            const isConsentGivenFunc = state.consentUIInstance ? state.consentUIInstance.isConsentGiven : () => !config.requireConsentCompliance || state.consentGiven;
            const initializeStarters = () => {
                if (typeof window.aipkit_chatUI_prepareStartersFeature === 'function') {
                    return window.aipkit_chatUI_prepareStartersFeature(
                        container,
                        config,
                        elements,
                        actions.sendMessageAction,
                        config.requireConsentCompliance,
                        isConsentGivenFunc
                    ).catch((error) => {
                        console.error(`AIPKit Chat Main (${botId}): Failed to lazy-load starters feature.`, error);
                    });
                }
                console.warn(`AIPKit Chat Main (${botId}): Starters feature loader is unavailable.`);
                return Promise.resolve();
            };

            if (config.popupEnabled && !isAdminPreview) {
                container.addEventListener('aipkit:popupOpened', () => {
                    void initializeStarters();
                }, { once: true });
            } else if (elements.startersContainer) {
                void initializeStarters();
            } else {
                console.warn(`AIPKit Chat Main (${botId}): Starters enabled but container is missing.`);
            }
        }

        if (config.enableSidebar && elements.sidebarEl && elements.sidebarToggleBtn) {
            if (typeof window.aipkit_chatUI_prepareSidebarFeature === 'function') {
                void window.aipkit_chatUI_prepareSidebarFeature(container, elements, config, actions).catch((error) => {
                    console.error(`AIPKit Chat Main (${botId}): Failed to prepare sidebar feature.`, error);
                });
            } else if (typeof window.aipkit_initConversationSidebar === 'function') {
                window.aipkit_initConversationSidebar(container, elements, config, actions);
                container.dataset.aipkitSidebarInitialized = 'true';
            } else {
                console.warn(`AIPKit Chat Main (${botId}): Sidebar enabled but init function is missing.`);
            }
        } else if (config.enableSidebar) {
            console.warn(`AIPKit Chat Main (${botId}): Sidebar enabled but sidebar elements are missing.`);
        }

        // 6. Define the public API before initializing features that might use it
        const publicApi = {
            openPopup: config.popupEnabled && typeof window.aipkit_chatUI_openPopup === 'function' ? () => window.aipkit_chatUI_openPopup(container, elements.messagesEl, elements.inputField, state) : () => {},
            closePopup: config.popupEnabled && typeof window.aipkit_chatUI_closePopup === 'function' ? () => window.aipkit_chatUI_closePopup(container, state) : () => {},
            setupPopupHandlers: config.popupEnabled && typeof window.aipkit_chatUI_setupPopupHandlers === 'function' ? (triggerButton) => popupLifecycle = window.aipkit_chatUI_setupPopupHandlers(container, triggerButton, elements.messagesEl, elements.inputField, state, config) : () => {},
            isPopupOpen: () => state.isPopupOpen,
            scrollToBottom: () => window.aipkit_chatUI_scrollToBottom(elements.messagesEl, true),
            sendMessage: actions.sendMessageAction,
            clearChat: actions.clearChatAction,
            toggleFullscreen: actions.toggleFullscreenAction,
        };

        // 7. Attach a lightweight realtime bootstrap so the full runtime loads on first use.
        if (config.enableRealtimeVoiceUI) {
            const wrapper = elements.container.closest('.aipkit_popup_wrapper');
            const triggerButton = wrapper ? wrapper.querySelector('.aipkit_popup_trigger') : null;
            const realtimeButton = config.directVoiceMode ? triggerButton : elements.container.querySelector('.aipkit_realtime_voice_agent_btn');

            if (realtimeButton) {
                const bootstrapRealtime = async (event) => {
                    event.preventDefault();
                    event.stopPropagation();

                    if (typeof window.aipkit_chatUI_prepareRealtimeFeature !== 'function') {
                        console.error(`AIPKit Chat Main (${botId}): Realtime feature loader is unavailable.`);
                        return;
                    }

                    try {
                        const ready = await window.aipkit_chatUI_prepareRealtimeFeature(elements, config, state, actions, publicApi);
                        if (ready && typeof realtimeButton.click === 'function') {
                            realtimeButton.removeEventListener('click', bootstrapRealtime);
                            realtimeButton.click();
                        }
                    } catch (error) {
                        console.error(`AIPKit Chat Main (${botId}): Failed to lazy-load realtime voice feature.`, error);
                    }
                };

                realtimeButton.addEventListener('click', bootstrapRealtime);
            } else {
                console.warn(`AIPKit Chat Main (${botId}): Realtime Voice enabled but no trigger button was found.`);
            }
        }

        // 8. Display Initial Message
        if (typeof window.aipkit_chatUI_displayInitialMessage === 'function') {
            window.aipkit_chatUI_displayInitialMessage(elements.messagesEl, config);
        } else {
            console.error(`AIPKit Chat Main (${botId}): displayInitialMessage function not found.`);
        }

        // 9. Finalize Setup (initial button state, scroll, event dispatch)
        if (typeof window.aipkit_chatUI_finalizeSetup === 'function') {
            window.aipkit_chatUI_finalizeSetup(elements, config, state, actions);
        } else {
            console.error(`AIPKit Chat Main (${botId}): finalizeSetup function not found.`);
        }

        // 10. Return public API and internal references
        return {
            publicApi: publicApi,
            internalElements: elements,
            internalActions: actions,
            internalState: state,
            lifecycle
        };
    } catch (error) {
        lifecycle.suspend();
        throw error;
    }
}

window.aipkit_setupChatInstanceUI = aipkit_setupChatInstanceUI;
