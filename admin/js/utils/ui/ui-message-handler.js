/**
 * AIPKit UI Utils - Message Display Handler
 * Handles displaying and managing temporary status messages.
 */
(function() {
    'use strict';
    const SETTINGS_GLOBAL_CONTAINER_ID = 'aipkit_settings_global_messages';
    const SETTINGS_LEGACY_CONTAINER_IDS = new Set([
        'aipkit_settings_messages',
        'aipkit_settings_others_messages'
    ]);
    const SETTINGS_MESSAGE_CONTAINER_IDS = new Set([
        SETTINGS_GLOBAL_CONTAINER_ID,
        'aipkit_settings_messages',
        'aipkit_settings_others_messages'
    ]);

    function resolveMessageContainerId(containerId) {
        if (!containerId) {
            return '';
        }

        if (
            SETTINGS_LEGACY_CONTAINER_IDS.has(containerId) &&
            document.getElementById(SETTINGS_GLOBAL_CONTAINER_ID)
        ) {
            return SETTINGS_GLOBAL_CONTAINER_ID;
        }

        return containerId;
    }

    /**
     * Fades out and removes an element (used for auto-dismiss and manual close).
     */
    function aipkit_fadeOutAndRemove(el) {
        if (!el || !el.parentNode || el.classList.contains('aipkit_message_fadeout')) return;
        el.classList.add('aipkit_message_fadeout');
        el.addEventListener('animationend', () => {
            // Check parentNode again in case it was removed by other means
            if (el.parentNode) { el.parentNode.removeChild(el); }
        }, { once: true });
         // Fallback removal in case animationend doesn't fire
         setTimeout(() => {
            if (el && el.parentNode) { el.parentNode.removeChild(el); }
        }, 500); // Adjust timing slightly longer than animation
    }

    /**
     * Display a success/error message in a designated container.
     * Ensures only one message is shown at a time by clearing previous ones *within that container*.
     * @param {string} containerId The ID of the container element for messages.
     * @param {'success'|'error'|'warning'|'info'} type The type of message.
     * @param {string} text The message text.
     */
    function aipkit_showMessage(containerId, type, text) {
        const resolvedContainerId = resolveMessageContainerId(containerId);
        const messagesContainer = document.getElementById(resolvedContainerId);
        if (!messagesContainer) {
            console.error(`AIPKit Message Handler: Container #${resolvedContainerId} not found.`);
            return;
        }
        const isSettingsMessageArea = SETTINGS_MESSAGE_CONTAINER_IDS.has(resolvedContainerId);

        // *** Clear existing messages *within this specific container* ***
        const existingMessages = messagesContainer.querySelectorAll('.aipkit_settings_message'); // Use the standard message class
        existingMessages.forEach(msg => {
             if (msg.dataset.timerId) { clearTimeout(parseInt(msg.dataset.timerId, 10)); }
             msg.remove();
        });
        // Clear existing saving indicator within this container
        const indicator = messagesContainer.querySelector('.aipkit_saving-indicator'); // Use the standard indicator class
        if (indicator) indicator.remove();
        // --- End Clear ---

        const messageEl = document.createElement('div');
        messageEl.className = `aipkit_settings_message aipkit_settings_message-${type}`; // Standard class names
        if (isSettingsMessageArea) {
            messageEl.textContent = text;
        } else {
            const iconClass = type === 'success'
                ? 'dashicons-yes-alt'
                : (type === 'error' || type === 'warning' ? 'dashicons-warning' : 'dashicons-info-alt');

            const iconSpan = document.createElement('span');
            iconSpan.className = `dashicons ${iconClass}`;
            iconSpan.style.marginRight = '5px';
            messageEl.appendChild(iconSpan);
            messageEl.appendChild(document.createTextNode(` ${text}`));

            const closeBtn = document.createElement('span');
            closeBtn.className = 'dashicons dashicons-dismiss aipkit_settings_message-close'; // Standard close class
            closeBtn.setAttribute('aria-label', 'Dismiss message');
            closeBtn.title = 'Dismiss message';
            messageEl.appendChild(closeBtn);
        }

        messagesContainer.appendChild(messageEl);

        if (isSettingsMessageArea) {
            if (type !== 'error') {
                const timerId = setTimeout(() => {
                    if (messageEl && messageEl.parentNode) {
                        messageEl.parentNode.removeChild(messageEl);
                    }
                }, 3000);
                messageEl.dataset.timerId = timerId;
            }
            return;
        }

        // Auto-dismiss after a delay using fade-out
        const timerId = setTimeout(() => { aipkit_fadeOutAndRemove(messageEl); }, 5000);
        messageEl.dataset.timerId = timerId;
    }

    /**
     * Immediately clear all status indicators (saving, success, error)
     * from a specific messages container.
     * @param {string} containerId The ID of the container element for messages.
     */
    function aipkit_clearStatusMessages(containerId) {
        const resolvedContainerId = resolveMessageContainerId(containerId);
        const container = document.getElementById(resolvedContainerId);
        if (container) {
            // Remove saving indicator directly
            const indicator = container.querySelector('.aipkit_saving-indicator'); // Standard class
            if (indicator) {
                indicator.remove();
            }
            // Remove success/error messages directly
            const messages = container.querySelectorAll('.aipkit_settings_message'); // Standard class
            messages.forEach(msg => {
                // Clear associated timer if it exists
                if (msg.dataset.timerId) {
                    clearTimeout(parseInt(msg.dataset.timerId, 10));
                }
                // Remove element immediately
                msg.remove();
            });
        } else {
            console.warn(`AIPKit Message Handler: Container #${resolvedContainerId} not found for clearing messages.`);
        }
    }

    // Expose functions globally
    window.aipkit_showMessage = aipkit_showMessage;
    window.aipkit_clearStatusMessages = aipkit_clearStatusMessages;

})();
