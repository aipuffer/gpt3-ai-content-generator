/**
 * AIPKit Public Chat - Conversation Starters Handler
 *
 * Handles displaying and interacting with conversation starter prompts.
 * Shows prompts when the chat is empty and hides them when interaction starts.
 * **REVISED**: Checks consent requirement and status before showing.
 */
(function() {
    'use strict';

    /**
     * Checks if the chat messages area is empty (only contains initial greeting).
     * @param {HTMLElement} messagesEl The messages container element.
     * @returns {boolean} True if empty, false otherwise.
     */
    function isChatEmpty(messagesEl) {
        if (!messagesEl) return true;
        // Check if there are any messages other than the initial greeting
        return messagesEl.querySelectorAll('.aipkit_chat_message:not(.aipkit_initial_greeting)').length === 0;
    }

    /**
     * Hides the conversation starters container.
     * @param {HTMLElement} startersContainer The container element for starters.
     */
    function hideStarters(startersContainer) {
        if (startersContainer) {
            startersContainer.classList.add('aipkit_hidden');
            startersContainer.classList.remove('aipkit_starters_ready');
        }
    }

    /**
     * Shows the conversation starters container.
     * @param {HTMLElement} startersContainer The container element for starters.
     */
    function showStarters(startersContainer, animate = true) {
        if (startersContainer) {
            startersContainer.classList.remove('aipkit_hidden');
            if (animate) {
                refreshStartersAnimation(startersContainer);
            }
        }
    }

    /**
     * Replays the starter animation when the container is visible.
     * @param {HTMLElement} startersContainer The container element for starters.
     */
    function refreshStartersAnimation(startersContainer) {
        if (!startersContainer || startersContainer.classList.contains('aipkit_hidden')) {
            return;
        }
        startersContainer.classList.remove('aipkit_starters_ready');
        void startersContainer.offsetHeight;
        requestAnimationFrame(() => {
            if (!startersContainer.classList.contains('aipkit_hidden')) {
                startersContainer.classList.add('aipkit_starters_ready');
            }
        });
    }

    /**
     * Initializes the conversation starters functionality for a chat instance.
     * @param {HTMLElement} container - The main chat container element (.aipkit_chat_container).
     * @param {object} config - The chatbot configuration object (must include 'starters' array).
     * @param {object} elements - Object containing references to UI elements { startersContainer, messagesEl, inputField }.
     * @param {function} sendMessageActionCallback - The function from main UI to send a message directly.
     * @param {boolean} consentRequired - Whether consent is required for this chatbot.
     * @param {function} isConsentGivenFunc - A function that returns the current consent status (true/false).
     */
    function aipkit_initConversationStarters(container, config, elements, sendMessageActionCallback, consentRequired, isConsentGivenFunc) {
        const { startersContainer, messagesEl, inputField } = elements;
        const botId = config.botId;
        const starters = config.starters || [];

        if (!container || !config || !startersContainer || !messagesEl || !inputField) {
            console.error(`AIPKit Starters (${botId}): Missing container, config, startersContainer, messagesEl, or inputField.`);
            return;
        }

        if (typeof sendMessageActionCallback !== 'function') {
            console.error(`AIPKit Starters (${botId}): sendMessageActionCallback is not a function.`);
            hideStarters(startersContainer); // Ensure it's hidden if callback missing
            return;
        }

         if (typeof isConsentGivenFunc !== 'function') {
            console.error(`AIPKit Starters (${botId}): isConsentGivenFunc is not a function.`);
            hideStarters(startersContainer); // Ensure it's hidden if consent check missing
            return;
        }

        if (starters.length === 0) {
            hideStarters(startersContainer); // Ensure it's hidden if no starters
            return;
        }

        // Clear any existing starters (e.g., on admin preview refresh)
        startersContainer.innerHTML = '';

        // Create starter buttons
        starters.forEach((text, index) => {
            if (typeof text === 'string' && text.trim() !== '') {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'aipkit_starter_btn';
                button.textContent = text.trim();
                button.style.setProperty('--aipkit-starter-index', String(index));
                button.addEventListener('click', (e) => {
                    e.preventDefault();
                    // --- Consent Check Before Sending ---
                    if (consentRequired && !isConsentGivenFunc()) {
                        console.warn(`AIPKit Starters (${botId}): Starter clicked, but consent not given.`);
                        // Optionally, briefly flash the consent box or input field?
                        // For now, just log and do nothing.
                        return;
                    }
                    // --- End Consent Check ---

                    const starterText = button.textContent;
                    sendMessageActionCallback(starterText); // Call the callback
                    hideStarters(startersContainer); // Hide immediately on click
                });
                startersContainer.appendChild(button);
            }
        });

        // --- Revised showIfNeededHandler with consent check ---
        const showIfNeededHandler = (event) => {
            const shouldAnimate = !(
                event &&
                event.type === 'aipkit:popupOpened' &&
                event.detail &&
                event.detail.replayIntro === false
            );

            // Check consent FIRST
            if (consentRequired && !isConsentGivenFunc()) {
                hideStarters(startersContainer);
                return; // Do not show if consent missing
            }
            // If consent okay, check if chat is empty
            if (isChatEmpty(messagesEl)) {
                showStarters(startersContainer, shouldAnimate);
            } else {
                 hideStarters(startersContainer);
            }
        };

        // --- Event Listeners on Main Container ---
        const hideHandler = () => hideStarters(startersContainer);
        container.addEventListener('aipkit:messageSent', hideHandler);
        container.addEventListener('aipkit:messageReceived', hideHandler);
        container.addEventListener('aipkit:messageError', hideHandler); // Also hide on error

        container.addEventListener('aipkit:chatCleared', showIfNeededHandler);
        container.addEventListener('aipkit:chatLoaded', showIfNeededHandler);
        container.addEventListener('aipkit:popupOpened', showIfNeededHandler);

        container.addEventListener('aipkit:consentGiven', () => {
            showIfNeededHandler(); // Re-evaluate visibility after consent
        });

        showIfNeededHandler();
    }

    // Expose the initialization function
    window.aipkit_initConversationStarters = aipkit_initConversationStarters;
    window.aipkit_chatUI_refreshStartersAnimation = refreshStartersAnimation;

})();
