// MODIFIED FILE - Added generic show/hide functions

/**
 * AIPKit UI Utils - Saving Indicator Handler
 */
(function() {
    'use strict';

     /**
     * Show/hide "Saving..." indicator within a specific message container.
     * Ensures only one status (saving or message) is visible at a time within that container.
     * @param {string} containerId The ID of the container element for messages/indicators.
     * @param {boolean} show True to show, false to hide.
     */
    function aipkit_showSavingIndicator(containerId, show) {
        const messagesContainer = document.getElementById(containerId);
        if (!messagesContainer) {
            console.error(`AIPKit Saving Indicator: Container #${containerId} not found.`);
            return;
        }

        let indicator = messagesContainer.querySelector('.aipkit_saving-indicator'); // Standard class name

        if (show) {
            if (!indicator) {
                // Ensure any existing success/error messages in this container are cleared first
                if (typeof window.aipkit_clearStatusMessages === 'function') {
                    window.aipkit_clearStatusMessages(containerId);
                } else {
                    console.warn("AIPKit Saving Indicator: Cannot clear messages, dependency missing.");
                }

                indicator = document.createElement('span');
                indicator.className = 'aipkit_saving-indicator'; // Use the base class
                indicator.innerHTML = `Saving... <span class="aipkit_spinner" style="display:inline-block;"></span>`;
                messagesContainer.appendChild(indicator);
                // Force adding active class AFTER element is in DOM for transition
                requestAnimationFrame(() => {
                    // Re-find element in case of race condition
                     const currentIndicator = messagesContainer.querySelector('.aipkit_saving-indicator');
                     if (currentIndicator) {
                         currentIndicator.classList.add('aipkit_active');
                     }
                });
            } else {
                // If indicator somehow exists, ensure it's visible and active
                indicator.style.display = 'inline-flex';
                indicator.classList.add('aipkit_active');
                const spinner = indicator.querySelector('.aipkit_spinner');
                if (spinner) spinner.style.display = 'inline-block';
            }
        } else {
            // Hide the indicator immediately by removing it
            if (indicator) {
                 // Optional: Add fade-out animation before removal
                 indicator.classList.remove('aipkit_active'); // Start fade-out if transition defined
                 setTimeout(() => { if (indicator && indicator.parentNode) indicator.remove(); }, 200); // Remove after slight delay
            }
        }
    }

    // Expose functions globally
    window.aipkit_showSavingIndicator = aipkit_showSavingIndicator;
})();
