/** Chatbot composer control states, elapsed timer and input focus. */
(function() {
    'use strict';

    /**
     * Sets the state of the action button.
     * @param {string} newState - 'send', 'clear', 'sending', 'streaming', or 'stop'.
     * @param {object} elements - UI elements: { inputField, actionButton, sendIcon, clearIcon, spinner, actionTimer, voiceInputButton, inputActionButton, webSearchToggleButton, googleSearchGroundingToggleButton }
     * @param {object} config - Chatbot config, needs { text, requireConsentCompliance }.
     * @param {object} state - Chat instance state, needs { consentGiven }.
     * @param {boolean} forceSendState - If true, ensures the 'send' button isn't disabled just because the input is empty.
     * @returns {string} The new state that was set.
     */
    function aipkit_chatUI_setButtonStateAction(newState, elements, config, state, forceSendState = false) {
        const { inputField, actionButton, sendIcon, clearIcon, spinner, actionTimer, voiceInputButton, inputActionButton, webSearchToggleButton, googleSearchGroundingToggleButton } = elements;
        const textLabels = config.text || {};

        function disableOptionalControls() {
            if (voiceInputButton) voiceInputButton.disabled = true;
            if (inputActionButton) inputActionButton.disabled = true;
            if (webSearchToggleButton) webSearchToggleButton.disabled = true;
            if (googleSearchGroundingToggleButton) googleSearchGroundingToggleButton.disabled = true;
        }


        function restoreOptionalControls() {
            if (voiceInputButton) voiceInputButton.disabled = config.requireConsentCompliance && !state.consentGiven;
            if (inputActionButton) inputActionButton.disabled = config.requireConsentCompliance && !state.consentGiven;
            if (webSearchToggleButton) webSearchToggleButton.disabled = config.requireConsentCompliance && !state.consentGiven;
            if (googleSearchGroundingToggleButton) googleSearchGroundingToggleButton.disabled = config.requireConsentCompliance && !state.consentGiven;
        }

        const setHidden = (element, shouldHide) => {
            if (!element || typeof element.setAttribute !== 'function' || typeof element.removeAttribute !== 'function') {
                return;
            }
            if (shouldHide) {
                element.setAttribute('hidden', 'hidden');
            } else {
                element.removeAttribute('hidden');
            }
        };
        const formatElapsedTime = (elapsedMs) => {
            const elapsedSeconds = Math.max(0, Math.floor(elapsedMs / 1000));
            const minutes = Math.floor(elapsedSeconds / 60);
            const seconds = elapsedSeconds % 60;
            return `${minutes}:${String(seconds).padStart(2, '0')}`;
        };
        const updateElapsedTimer = () => {
            if (!actionTimer) {
                return;
            }

            const startedAt = typeof state.streamStartTime === 'number'
                ? state.streamStartTime
                : Date.now();
            actionTimer.textContent = formatElapsedTime(Date.now() - startedAt);
            setHidden(actionTimer, false);
        };
        const startElapsedTimer = () => {
            if (typeof state.streamStartTime !== 'number' || !Number.isFinite(state.streamStartTime)) {
                state.streamStartTime = Date.now();
            }
            if (state.streamTimerIntervalId) {
                clearInterval(state.streamTimerIntervalId);
            }
            updateElapsedTimer();
            state.streamTimerIntervalId = window.setInterval(updateElapsedTimer, 1000);
        };
        const stopElapsedTimer = () => {
            if (state.streamTimerIntervalId) {
                clearInterval(state.streamTimerIntervalId);
                state.streamTimerIntervalId = null;
            }
            state.streamStartTime = null;
            if (actionTimer) {
                actionTimer.textContent = '';
                setHidden(actionTimer, true);
            }
        };

        if (!actionButton || !sendIcon || !clearIcon || !spinner || !inputField) {
            console.error("AIPKit SetButtonState: Missing required elements.");
            return null;
        }

        if (newState !== 'stop') {
            stopElapsedTimer();
        }

        setHidden(spinner, true);
        setHidden(sendIcon, true);
        setHidden(clearIcon, true);
        actionButton.disabled = false;

        let finalState = newState;

        switch (newState) {
            case 'sending':
            case 'streaming':
                inputField.disabled = true;
                actionButton.disabled = true;
                disableOptionalControls();

                setHidden(spinner, false);
                const label = (newState === 'streaming') ? (textLabels.streaming || 'Streaming...') : (textLabels.sending || 'Sending...');
                actionButton.setAttribute('aria-label', label);
                actionButton.setAttribute('title', label);
                break;

            case 'stop':
                inputField.disabled = true;
                disableOptionalControls();

                setHidden(spinner, false);
                startElapsedTimer();
                actionButton.setAttribute('aria-label', textLabels.stopResponse || 'Stop Response');
                actionButton.setAttribute('title', textLabels.stopResponse || 'Stop Response');
                actionButton.disabled = false;
                break;

            case 'clear':
                inputField.disabled = config.requireConsentCompliance && !state.consentGiven;
                setHidden(clearIcon, false);
                actionButton.setAttribute('aria-label', textLabels.clearChat || 'Clear Chat');
                actionButton.setAttribute('title', textLabels.clearChat || 'Clear Chat');
                actionButton.disabled = false; // Clear button should always be enabled when in this state
                restoreOptionalControls();
                break;

            case 'send':
            default:
                finalState = 'send'; // Ensure finalState is 'send' if default case
                inputField.disabled = config.requireConsentCompliance && !state.consentGiven;
                setHidden(sendIcon, false);
                actionButton.setAttribute('aria-label', textLabels.sendMessage || 'Send Message');
                actionButton.setAttribute('title', textLabels.sendMessage || 'Send Message');
                actionButton.disabled = !forceSendState && inputField.value.trim() === '';
                if (config.requireConsentCompliance && !state.consentGiven) {
                    actionButton.disabled = true; // Always disable if consent not given
                }
                restoreOptionalControls();
                break;
        }

        if (state.formGatePending && finalState !== 'stop') {
            inputField.disabled = true;
            actionButton.disabled = true;
            disableOptionalControls();
        }
        return finalState;
    }

    window.aipkit_chatUI_setButtonStateAction = aipkit_chatUI_setButtonStateAction;
})();

(function() {
    'use strict';

    /**
     * Focuses the chat input field.
     * @param {HTMLElement} inputField - The input field element.
     * @param {object} config - Chatbot configuration { popupEnabled }.
     * @param {object} state - Chat instance state { isPopupOpen, consentGiven }.
     */
    function aipkit_chatUI_focusInputAction(inputField, config, state) {
        if (!inputField) return;

        if (state.formGatePending) {
            return;
        }

        const isAdminPreview = inputField.closest('#aipkit_admin_chat_preview_container');
        const isMobile = window.innerWidth <= 760;

        // Consent check: Do not focus if consent is required but not given
        if (config.requireConsentCompliance && !state.consentGiven) {
            return;
        }

        // Popup check: Do not focus if popup mode is enabled but the popup is not open
        if (config.popupEnabled && !state.isPopupOpen) {
            return;
        }

        // Admin preview or non-mobile: Focus normally
        // On mobile, generally avoid auto-focusing as it can be disruptive (keyboard popping up).
        // However, if the user explicitly interacts (e.g. sends message, clears), focus might be desired.
        // This function is usually called after such interactions.
        if (isAdminPreview || !isMobile || state.userInitiatedAction) { // Added state.userInitiatedAction check
            // Delay slightly for layout to settle, especially after UI changes.
            setTimeout(() => {
                if (inputField && typeof inputField.focus === 'function') {
                    inputField.focus();
                }
            }, 50);
        }
         // Reset the userInitiatedAction flag after attempting focus
         if (state.userInitiatedAction) {
            state.userInitiatedAction = false;
        }
    }

    window.aipkit_chatUI_focusInputAction = aipkit_chatUI_focusInputAction;

})();
