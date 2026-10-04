/**
 * AIPKit Utilities - Button & Message Helpers
 */
(function () {
	'use strict';

	/**
	 * Swap the button label while preserving any spinner element.
	 * @param {HTMLElement} button The button element.
	 * @param {string} text The new text for the button.
	 */
	function aipkit_setButtonText(button, text) {
        if (!button) return;
		const span = button.querySelector('.aipkit_btn-text');
		if (span) {
             span.textContent = text;
             return;
        }

		// Fallback if no .aipkit_btn-text span exists
		const spinner = button.querySelector('.aipkit_spinner');
        // Find the first text node and update it, or prepend new text
        let textNode = Array.from(button.childNodes).find(node => node.nodeType === Node.TEXT_NODE);
        if (textNode) {
            textNode.textContent = text + (spinner ? ' ' : ''); // Add space if spinner exists
        } else {
             button.insertBefore(document.createTextNode(text + (spinner ? ' ' : '')), button.firstChild);
        }
        // Ensure spinner is at the end if it exists
		if (spinner) button.appendChild(spinner);
	}

	/**
	 * Show a temporary success/error message underneath a field or in a designated area.
	 * @param {HTMLElement} el The element where the message should appear (e.g., a help text div).
	 * @param {'success'|'error'|'info'} type The type of message.
	 * @param {string} text The message text.
	 * @param {number} [delay=4000] Duration in milliseconds before fading out.
	 */
	function aipkit_showTemporaryMessage(el, type, text, delay = 4000) {
		if (!el) return;
        const baseClass = el.dataset.baseClass || 'aipkit_form-help'; // Get base class or default
        if(!el.dataset.baseClass) el.dataset.baseClass = baseClass; // Store if not set

        // Clear previous timers if any
         if (el.messageTimeoutId) {
            clearTimeout(el.messageTimeoutId);
            el.messageTimeoutId = null;
         }

		el.textContent = text;
        // Reset classes first, then add the correct one
        el.className = baseClass; // Reset to base class
		el.classList.add(`aipkit_form-help-${type}`); // Add specific type class
        el.style.opacity = '1'; // Ensure visible
        el.style.display = 'block'; // Ensure it's displayed

		// Set new timeout
		el.messageTimeoutId = setTimeout(() => {
			el.style.opacity = '0'; // Fade out
            // Reset after fade out
             setTimeout(() => {
                if (el.textContent === text) { // Check if message hasn't changed again quickly
                    el.textContent = '';
                    el.className = baseClass; // Restore base class
                    el.style.display = ''; // Restore default display
                    el.messageTimeoutId = null;
                }
            }, 300); // Matches typical CSS transition duration
		}, delay);
	}

	/* ---------- export to global ---------- */
	window.aipkit_setButtonText = aipkit_setButtonText;
	window.aipkit_showTemporaryMessage = aipkit_showTemporaryMessage;

})();