/**
 * Chatbot message controls: copy, feedback, button rendering and delegated events.
 * Existing window APIs and runtime delivery remain unchanged.
 */
(function () {
    'use strict';

    function normalizeFeedback(value) {
        return value === 'up' || value === 'down' ? value : '';
    }

    /**
     * Helper function to get text content from a message bubble, attempting to preserve line breaks.
     * @param {HTMLElement} bubble - The chat bubble element.
     * @returns {string} The extracted text content.
     */
    function aipkit_chatUI_extractTextFromBubble(bubble) {
        if (!bubble) return '';
        let messageText = '';
        const nodes = bubble.childNodes;

        function getNodeText(node) {
            if (node.nodeType === Node.TEXT_NODE) {
                return node.textContent;
            } else if (node.nodeName === 'BR') {
                return '\n';
            } else if (node.nodeType === Node.ELEMENT_NODE) {
                let text = '';
                // Preserve block spacing in clipboard text.
                const isBlockElement = ['P', 'UL', 'OL', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'PRE', 'BLOCKQUOTE', 'HR', 'DIV'].includes(node.tagName);
                if (isBlockElement) {
                    text += '\n';
                }
                if (node.tagName === 'LI') { // Handle list items
                    text += '- '; // Keep the clipboard's plain-bullet list format.
                }
                node.childNodes.forEach(child => {
                    text += getNodeText(child);
                });
                if (isBlockElement || node.tagName === 'LI') {
                    text += '\n';
                }
                return text;
            }
            return '';
        }

        nodes.forEach(node => {
            messageText += getNodeText(node);
        });

        // Consolidate multiple newlines and trim ends
        return messageText.replace(/\n\s*\n/g, '\n\n').replace(/^\s+|\s+$/g, '');
    }

    window.aipkit_chatUI_extractTextFromBubble = aipkit_chatUI_extractTextFromBubble;

    /**
     * Temporarily changes a button's icon to a checkmark for feedback.
     * @param {HTMLElement} buttonEl The button element.
     * @param {number} [duration=1500] Duration in milliseconds to show the checkmark.
     */
    function aipkit_chatUI_showSuccessIcon(buttonEl, duration = 1500) {
        const iconContainer = buttonEl; // The button itself is the container
        if (!iconContainer) {
            console.warn("AIPKit showSuccessIcon: Button element not found:", buttonEl);
            return;
        }

        // Store original icon HTML if not already stored
        if (!iconContainer.dataset.originalIconHtml) {
            iconContainer.dataset.originalIconHtml = iconContainer.innerHTML;
        }
        if (!iconContainer.dataset.originalAriaLabel) {
            iconContainer.dataset.originalAriaLabel = iconContainer.getAttribute('aria-label') || '';
        }
        if (!iconContainer.dataset.originalTitle) {
            iconContainer.dataset.originalTitle = iconContainer.getAttribute('title') || '';
        }

        // A standard checkmark SVG
        const successIconSvg = `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-check"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>`;

        // Prevent re-triggering if already showing success or if button is disabled
        if (buttonEl.dataset.successTimeoutId || buttonEl.disabled) {
            return;
        }

        // Replace the button's content with the success icon
        iconContainer.innerHTML = successIconSvg;
        const newSvg = iconContainer.querySelector('svg');
        const isMessageCopyAction = buttonEl.classList.contains('aipkit_copy_btn');
        if (newSvg && !isMessageCopyAction) {
            newSvg.style.color = 'var(--aipkit_status-success, #38A169)'; // Apply green color
        }
        if (isMessageCopyAction) {
            const successLabel = buttonEl.getAttribute('data-success-label') || 'Copied!';
            buttonEl.classList.add('aipkit_copy_success');
            buttonEl.setAttribute('aria-label', successLabel);
            buttonEl.setAttribute('title', successLabel);
        }

        // Set a timeout to revert the icon
        const timeoutId = setTimeout(() => {
            const originalIconHtml = iconContainer.dataset.originalIconHtml;
            if (originalIconHtml) {
                iconContainer.innerHTML = originalIconHtml; // Restore original SVG
            }
            if (isMessageCopyAction) {
                buttonEl.classList.remove('aipkit_copy_success');
                buttonEl.setAttribute('aria-label', iconContainer.dataset.originalAriaLabel || 'Copy response');
                buttonEl.setAttribute('title', iconContainer.dataset.originalTitle || 'Copy response');
            }
            delete iconContainer.dataset.originalIconHtml; // Clean up
            delete iconContainer.dataset.originalAriaLabel;
            delete iconContainer.dataset.originalTitle;
            delete buttonEl.dataset.successTimeoutId;
        }, duration);
        buttonEl.dataset.successTimeoutId = timeoutId.toString();
    }

    window.aipkit_chatUI_showSuccessIcon = aipkit_chatUI_showSuccessIcon;

    /**
     * Handles clicks on the copy button. Changes icon on success.
     * @param {Event} event The click event.
     * @param {object} config The chatbot configuration object (not directly used here but kept for consistency).
     */
    function aipkit_chatUI_handleCopyAction(event, config) {
        const copyButton = event.target.closest('.aipkit_copy_btn');
        if (!copyButton || copyButton.disabled) return;

        // Prevent re-triggering if already showing success icon
        if (copyButton.dataset.successTimeoutId) {
             return;
        }

        const messageContainer = copyButton.closest('.aipkit_chat_message');
        const bubble = messageContainer ? messageContainer.querySelector('.aipkit_chat_bubble') : null;

        if (!messageContainer || !bubble) {
            console.error("AIPKit Message Actions: Could not find parent message container or bubble for copy button.");
            return;
        }

        // Ensure dependencies exist
        if (typeof window.aipkit_chatUI_extractTextFromBubble !== 'function' ||
            typeof window.aipkit_chatUI_showSuccessIcon !== 'function') {
            console.error("AIPKit Copy Action: Missing required helper functions (extractTextFromBubble or showSuccessIcon).");
            return;
        }

        const textToCopy = window.aipkit_chatUI_extractTextFromBubble(bubble);

        if (!textToCopy) {
            console.warn("AIPKit Copy Action: No text extracted from bubble to copy.");
            return;
        }

        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            navigator.clipboard.writeText(textToCopy).then(() => {
                window.aipkit_chatUI_showSuccessIcon(copyButton);
            }).catch(err => {
                console.error("AIPKit Message Actions: Clipboard API failed:", err);
            });
        } else {
            console.warn("AIPKit Message Actions: Using fallback copy method.");
            try {
                const textArea = document.createElement("textarea");
                textArea.value = textToCopy;
                textArea.style.position = "fixed"; textArea.style.top = "-9999px"; textArea.style.left = "-9999px"; textArea.style.opacity = "0";
                document.body.appendChild(textArea);
                textArea.focus(); textArea.select();
                const successful = document.execCommand('copy');
                document.body.removeChild(textArea);
                if (successful) window.aipkit_chatUI_showSuccessIcon(copyButton);
                else throw new Error('execCommand failed');
            } catch (err) {
                console.error("AIPKit Message Actions: Fallback copy method failed:", err);
            }
        }
    }

    window.aipkit_chatUI_handleCopyAction = aipkit_chatUI_handleCopyAction;

    function getCurrentFeedback(actionsContainer) {
        const savedValue = actionsContainer.dataset.currentFeedback || '';
        if (savedValue === 'up' || savedValue === 'down') {
            return savedValue;
        }
        const selectedButton = actionsContainer.querySelector(
            '.aipkit_feedback_btn.aipkit_feedback_selected'
        );
        return selectedButton ? selectedButton.getAttribute('data-feedback') || '' : '';
    }

    function applyFeedbackState(actionsContainer, feedbackValue) {
        const normalizedValue = normalizeFeedback(feedbackValue);
        actionsContainer.dataset.currentFeedback = normalizedValue;
        actionsContainer.querySelectorAll('.aipkit_feedback_btn').forEach((button) => {
            const isSelected = button.getAttribute('data-feedback') === normalizedValue;
            button.classList.toggle('aipkit_feedback_selected', isSelected);
            button.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
        });
    }

    function setFeedbackPending(actionsContainer, isPending) {
        actionsContainer.classList.toggle('aipkit_feedback_pending', isPending);
        actionsContainer.querySelectorAll('.aipkit_feedback_btn').forEach((button) => {
            button.disabled = isPending;
        });
    }

    function createFeedbackOperationId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }

        return [Date.now().toString(36), Math.random().toString(36).slice(2)].join('-');
    }

    /**
     * Handles clicks on the feedback buttons (like/dislike).
     * @param {Event} event The click event.
     * @param {object} config The chatbot configuration object.
     */
    function aipkit_chatUI_handleFeedbackAction(event, config) {
        const feedbackButton = event.target.closest('.aipkit_feedback_btn');
        if (!feedbackButton || feedbackButton.disabled) return;

        const requestedFeedback = feedbackButton.getAttribute('data-feedback');
        const messageContainer = feedbackButton.closest('.aipkit_chat_message');
        const messageId = messageContainer ? messageContainer.getAttribute('data-message-id') : null;
        const actionsContainer = feedbackButton.closest('.aipkit_message_actions');

        if (!messageId || !requestedFeedback || !actionsContainer) {
            console.error("AIPKit Feedback: Missing message ID, feedback type, or actions container.");
            return;
        }

        // Ensure window.aipkit_frontendApiRequest is available
        if (typeof window.aipkit_frontendApiRequest !== 'function') {
            console.error('AIPKit Feedback Error: aipkit_frontendApiRequest function not found.');
            return;
        }

        const previousFeedback = getCurrentFeedback(actionsContainer);
        const nextFeedback = previousFeedback === requestedFeedback ? 'none' : requestedFeedback;
        const retryingSameOperation =
            actionsContainer.dataset.feedbackOperationTarget === nextFeedback &&
            actionsContainer.dataset.feedbackOperationId;
        const feedbackOperationId = retryingSameOperation
            ? actionsContainer.dataset.feedbackOperationId
            : createFeedbackOperationId();
        actionsContainer.dataset.feedbackOperationTarget = nextFeedback;
        actionsContainer.dataset.feedbackOperationId = feedbackOperationId;
        applyFeedbackState(actionsContainer, nextFeedback);
        setFeedbackPending(actionsContainer, true);

        const data = {
            message_id: messageId,
            feedback_type: nextFeedback,
            feedback_operation_id: feedbackOperationId
        };
        window.aipkit_frontendApiRequest('aipkit_store_feedback', data, config)
            .then((response) => {
                const savedFeedback = response && response.feedback
                    ? response.feedback
                    : nextFeedback;
                applyFeedbackState(actionsContainer, savedFeedback);
                delete actionsContainer.dataset.feedbackOperationTarget;
                delete actionsContainer.dataset.feedbackOperationId;
            })
            .catch(error => {
                console.error(`AIPKit Feedback (${config.botId}): Error storing feedback for ${messageId}:`, error);
                applyFeedbackState(actionsContainer, previousFeedback);
            })
            .finally(() => {
                setFeedbackPending(actionsContainer, false);
            });
    }

    window.aipkit_chatUI_handleFeedbackAction = aipkit_chatUI_handleFeedbackAction;

    /**
     * Creates the HTML string for the message action buttons container.
     * @param {object} config The chatbot configuration object.
     * @returns {string} HTML string for the actions container, or empty string if no actions enabled.
     */
    function aipkit_chatUI_createActionsContainerHTML(config, savedFeedback = "") {
        const playSvg = `<svg  xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="currentColor"  stroke-width="2"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-player-play"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 4v16l13 -8z" /></svg>`;
        const copySvg = `<svg  xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="currentColor"  stroke-width="2"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-copy"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 7m0 2.667a2.667 2.667 0 0 1 2.667 -2.667h8.666a2.667 2.667 0 0 1 2.667 2.667v8.666a2.667 2.667 0 0 1 -2.667 2.667h-8.666a2.667 2.667 0 0 1 -2.667 -2.667z" /><path d="M4.012 16.737a2.005 2.005 0 0 1 -1.012 -1.737v-10c0 -1.1 .9 -2 2 -2h10c.75 0 1.158 .385 1.5 1" /></svg>`;
        const thumbUpSvg = `<svg  xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="currentColor"  stroke-width="2"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-thumb-up"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 11v8a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1v-7a1 1 0 0 1 1 -1h3a4 4 0 0 0 4 -4v-1a2 2 0 0 1 4 0v5h3a2 2 0 0 1 2 2l-1 5a2 3 0 0 1 -2 2h-7a3 3 0 0 1 -3 -3" /></svg>`;
        const thumbDownSvg = `<svg  xmlns="http://www.w3.org/2000/svg"  width="24"  height="24"  viewBox="0 0 24 24"  fill="none"  stroke="currentColor"  stroke-width="2"  stroke-linecap="round"  stroke-linejoin="round"  class="icon icon-tabler icons-tabler-outline icon-tabler-thumb-down"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 13v-8a1 1 0 0 0 -1 -1h-2a1 1 0 0 0 -1 1v7a1 1 0 0 0 1 1h3a4 4 0 0 1 4 4v1a2 2 0 0 0 4 0v-5h3a2 2 0 0 0 2 -2l-1 -5a2 3 0 0 0 -2 -2h-7a3 3 0 0 0 -3 3" /></svg>`;

        let utilityActionsHTML = "";
        let feedbackActionsHTML = "";
        const texts = config.text || {}; // Get text labels
        const escapeAttribute =
          typeof window.aipkit_escapeAttribute === "function"
            ? window.aipkit_escapeAttribute
            : (value) =>
                String(value ?? "")
                  .replace(/&/g, "&amp;")
                  .replace(/"/g, "&quot;")
                  .replace(/</g, "&lt;")
                  .replace(/>/g, "&gt;");
        const normalizedFeedback = normalizeFeedback(savedFeedback);

        // TTS Play Button
        if (config.ttsEnabled) {
            const playTitle = texts.playActionLabel || "Play audio";
            const pauseTitle = texts.pauseActionLabel || "Pause audio";
            utilityActionsHTML += `<button type="button" class="aipkit_action_btn aipkit_play_btn" title="${escapeAttribute(playTitle)}" aria-label="${escapeAttribute(playTitle)}" aria-pressed="false" data-play-label="${escapeAttribute(playTitle)}" data-pause-label="${escapeAttribute(pauseTitle)}">${playSvg}</button>`;
        }

        // Copy Button
        if (config.enableCopyButton) {
            const copyTitle = texts.copyActionLabel || "Copy response";
            const copySuccessTitle = texts.copySuccess || "Copied!";
            utilityActionsHTML += `<button type="button" class="aipkit_action_btn aipkit_copy_btn" title="${escapeAttribute(copyTitle)}" aria-label="${escapeAttribute(copyTitle)}" data-success-label="${escapeAttribute(copySuccessTitle)}">${copySvg}</button>`;
        }

        // Feedback Buttons
        if (config.enableFeedback) {
            const likeTitle = texts.feedbackLikeLabel || "Like response";
            const dislikeTitle = texts.feedbackDislikeLabel || "Dislike response";
            const upSelected = normalizedFeedback === "up";
            const downSelected = normalizedFeedback === "down";
            feedbackActionsHTML += `<button type="button" class="aipkit_action_btn aipkit_feedback_btn aipkit_thumb_up_btn${upSelected ? " aipkit_feedback_selected" : ""}" title="${escapeAttribute(likeTitle)}" aria-label="${escapeAttribute(likeTitle)}" aria-pressed="${upSelected ? "true" : "false"}" data-feedback="up">${thumbUpSvg}</button>`;
            feedbackActionsHTML += `<button type="button" class="aipkit_action_btn aipkit_feedback_btn aipkit_thumb_down_btn${downSelected ? " aipkit_feedback_selected" : ""}" title="${escapeAttribute(dislikeTitle)}" aria-label="${escapeAttribute(dislikeTitle)}" aria-pressed="${downSelected ? "true" : "false"}" data-feedback="down">${thumbDownSvg}</button>`;
        }

        if (utilityActionsHTML || feedbackActionsHTML) {
            const utilityGroup = utilityActionsHTML
              ? `<span class="aipkit_message_action_group aipkit_message_action_group--utility">${utilityActionsHTML}</span>`
              : "";
            const feedbackGroup = feedbackActionsHTML
              ? `<span class="aipkit_message_action_group aipkit_message_action_group--feedback">${feedbackActionsHTML}</span>`
              : "";
            return `<div class="aipkit_message_actions" data-current-feedback="${normalizedFeedback}">${utilityGroup}${feedbackGroup}</div>`;
        }
        return "";
    }

    // Expose the function globally
    window.aipkit_chatUI_createActionsContainerHTML = aipkit_chatUI_createActionsContainerHTML;

    /**
     * Attaches event listeners for copy and feedback buttons using delegation.
     * @param {HTMLElement} messagesEl - The main messages container element where events are delegated from.
     * @param {object} config - The chatbot configuration object.
     */
    function aipkit_chatUI_initMessageActions(messagesEl, config) {
        if (!messagesEl) {
            console.error("AIPKit Init Message Actions: Messages container element not provided.");
            return;
        }

        // Prevent duplicate listeners if already attached
        if (messagesEl.dataset.aipkitMessageActionsListenerAttached === 'true') {
             return;
        }
        messagesEl.dataset.aipkitMessageActionsListenerAttached = 'true';


        messagesEl.addEventListener('click', function(event) {
            // Copy Action
            if (event.target.closest('.aipkit_copy_btn')) {
                if (typeof window.aipkit_chatUI_handleCopyAction === 'function') {
                    window.aipkit_chatUI_handleCopyAction(event, config);
                } else {
                    console.error("AIPKit Init Message Actions: handleCopyAction function not found.");
                }
                return;
            }
            // Feedback Action
            if (event.target.closest('.aipkit_feedback_btn')) {
                 if (typeof window.aipkit_chatUI_handleFeedbackAction === 'function') {
                    window.aipkit_chatUI_handleFeedbackAction(event, config);
                } else {
                    console.error("AIPKit Init Message Actions: handleFeedbackAction function not found.");
                }
                return;
            }
        });
    }

    // Expose the main listener attachment function
    window.aipkit_chatUI_initMessageActions = aipkit_chatUI_initMessageActions;
})();
