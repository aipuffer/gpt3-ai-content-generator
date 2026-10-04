/** Chatbot initial state, greeting display and setup finalization. */
import { composeGreetingText } from "./conversation.js";

(function() {
    'use strict';

    /**
     * Initializes and returns the state object for a chat instance.
     * @param {object} config - The chatbot configuration object.
     * @returns {object} The initial state object.
     */
    function aipkit_chatUI_initState(config) {
        // Determine initial consent state (could be pre-set if consentUI ran before)
        const botId = config.botId || 'default';
        const consentStorageKey = `aipkit_chatbot_consent_given_${botId}`;
        const initialConsentGiven = localStorage.getItem(consentStorageKey) === 'true';
        const webToggleDefaultOn = config.webToggleDefaultOn === true;
        const allowProviderWebSearchTool =
            config.allowWebSearchTool &&
            (config.provider === 'OpenAI' || config.provider === 'Claude' || config.provider === 'OpenRouter' || config.provider === 'xAI');
        const allowGoogleSearchGrounding =
            config.allowGoogleSearchGrounding && config.provider === 'Google';

        return {
            buttonState: 'send', // 'send', 'clear', 'sending', 'streaming', 'stop'
            isPopupOpen: false,  // For popup mode
            isSending: false,
            formGatePending: false,
            pendingFormElement: null,
            currentStreamId: null, // For identifying the SSE stream if multiple on page
            isFullscreen: false,
            fullscreenTransitioning: false,
            fullscreenMotionTimer: null,
            fullscreenPlaceholder: null,
            fullscreenWrapper: null,
            fullscreenBackdrop: null,
            fullscreenSourceRect: null,
            fullscreenSourceRadius: null,
            fullscreenIsAdminPreview: null,
            isSidebarOpen: false,
            currentStreamMessageId: null, // Tracks the ID of the message being streamed
            activeEventSourceRef: null, // Holds the current SSE EventSource ref while streaming
            activeStreamState: null, // Shared reference to the active SSE stream state object
            activeStreamWasNewConversation: false, // Tracks whether the active stream started a fresh thread
            streamStartTime: null, // Timestamp used by the elapsed response timer
            streamTimerIntervalId: null, // Interval ID for the elapsed response timer
            ignoreNextStreamError: false, // Suppresses the close-induced SSE error after a manual stop
            ignoredStreamErrorRef: null, // Tracks which closed EventSource should have its terminal error ignored
            isActionMenuOpen: false,      // Tracks if the input action menu (PDF/Image) is open
            activeInputActionMenu: null,  // Reference to the currently open action menu DOM element
            activeInputActionTrigger: null, // Reference to the button that triggered the action menu
            closeActionMenuHandler: null, // Stores the bound event handler for closing the action menu
            consentGiven: initialConsentGiven, // True if user has given consent
            isStartingRecording: false,  // True while loading STT and requesting microphone access
            isRecording: false,          // True if voice input is currently recording
            isTranscribing: false,       // True while recorded audio is being transcribed
            voiceRecordingCancelled: false, // Cancels an in-flight microphone start for this chatbot
            webSearchToolActive: webToggleDefaultOn && allowProviderWebSearchTool,  // True if provider web search is toggled on
            googleSearchGroundingActive: webToggleDefaultOn && allowGoogleSearchGrounding, // True if Google Search Grounding is toggled on
        };
    }

    window.aipkit_chatUI_initState = aipkit_chatUI_initState;

})();

(function() {
    'use strict';

    /**
     * Displays the initial greeting message if not already present.
     * @param {HTMLElement} messagesEl - The messages container element.
     * @param {object} config - The chatbot configuration object.
     */
    function aipkit_chatUI_displayInitialMessage(messagesEl, config) {
        if (!messagesEl || !config || !config.text) {
            console.error("AIPKit InitialMessage: Missing messagesEl or config.");
            return;
        }

        const botId = config.botId || 'default';

        // Remove any statically rendered messages that are not the initial greeting
        const staticMessages = messagesEl.querySelectorAll('.aipkit_chat_message:not(.aipkit_initial_greeting)');
        staticMessages.forEach(msg => {
            try {
                messagesEl.removeChild(msg);
            } catch (e) { /* Ignore if already removed */ }
        });

        // Ensure initial greeting is present
        if (!messagesEl.querySelector('.aipkit_initial_greeting')) {
            const greetingText = (config.text.initialGreeting || 'Hello there!').trim();
            const subgreetingText = (config.text.initialSubgreeting || '').trim();
            const initialGreetingText = composeGreetingText(greetingText, subgreetingText);
            if (typeof window.aipkit_chatUI_appendMessage !== 'function' || typeof window.aipkit_generateClientMessageId !== 'function') {
                console.error("AIPKit InitialMessage: appendMessage or generateClientMessageId function not found.");
                return;
            }
            window.aipkit_chatUI_appendMessage(
                messagesEl,
                initialGreetingText,
                'bot',
                config,
                false,
                true,
                window.aipkit_generateClientMessageId(botId)
            );
        }
    }

    window.aipkit_chatUI_displayInitialMessage = aipkit_chatUI_displayInitialMessage;

})();

(function() {
    'use strict';

    /**
     * Performs final setup steps for the chat instance.
     * @param {object} elements - UI elements.
     * @param {object} config - Chatbot config.
     * @param {object} state - Chat instance state.
     * @param {object} actions - Core action functions { setButtonStateAction }.
     */
    function aipkit_chatUI_finalizeSetup(elements, config, state, actions) {
        const { inputField, messagesEl, container } = elements;
        const botId = config.botId;
        const webToggleDefaultOn = config.webToggleDefaultOn === true;

        // 1. Set initial button state (usually 'send', disabled if input is empty)
        state.buttonState = actions.setButtonStateAction('send', false, state);

        // 2. Auto-resize textarea if applicable
        if (typeof window.aipkit_autoResizeTextarea === 'function') {
            window.aipkit_autoResizeTextarea(inputField);
        }

        // 3. Initial scroll to bottom (only if not in popup mode, or if popup is already open - though usually it's not at this stage)
        if (!config.popupEnabled) {
            if (typeof window.aipkit_chatUI_scrollToBottom === 'function') {
                window.aipkit_chatUI_scrollToBottom(messagesEl, true);
            }
        }

        // 4. Initial consent-based input field state
        inputField.disabled = config.requireConsentCompliance && !state.consentGiven;
        if (elements.voiceInputButton) elements.voiceInputButton.disabled = config.requireConsentCompliance && !state.consentGiven;
        if (elements.inputActionButton) elements.inputActionButton.disabled = config.requireConsentCompliance && !state.consentGiven;
        if (elements.webSearchToggleButton && config.allowWebSearchTool && (config.provider === 'OpenAI' || config.provider === 'Claude' || config.provider === 'OpenRouter' || config.provider === 'xAI')) elements.webSearchToggleButton.disabled = config.requireConsentCompliance && !state.consentGiven;
        if (elements.googleSearchGroundingToggleButton && config.allowGoogleSearchGrounding && config.provider === 'Google') elements.googleSearchGroundingToggleButton.disabled = config.requireConsentCompliance && !state.consentGiven;

        if (typeof window.aipkit_syncWebSearchToggleState === 'function') {
            window.aipkit_syncWebSearchToggleState(elements, config, state, webToggleDefaultOn);
        }
        if (typeof window.aipkit_syncGoogleSearchGroundingToggleState === 'function') {
            window.aipkit_syncGoogleSearchGroundingToggleState(elements, config, state, webToggleDefaultOn);
        }

        // 5. Dispatch 'chatLoaded' event
        container.dispatchEvent(new CustomEvent('aipkit:chatLoaded', { detail: { botId: botId }}));

    }

    window.aipkit_chatUI_finalizeSetup = aipkit_chatUI_finalizeSetup;

})();
