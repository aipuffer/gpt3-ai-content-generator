/**
 * AIPKit UI Utils - Restore Default Value Icon Handler
 */
(function() {
    'use strict';

    /**
     * Initialize restore default icons using event delegation.
     * @param {string} scopeSelector CSS selector for the container to scope the search.
     */
    function aipkit_initRestoreDefaultIcons(scopeSelector) {
        const scope = document.querySelector(scopeSelector);
        if (!scope) return;

        // Use event delegation
        const listenerAttr = 'data-restore-icon-listener-attached';
        if (scope.getAttribute(listenerAttr)) return; // Already attached

        scope.addEventListener('click', function(event) {
            const icon = event.target.closest('.aipkit_restore-default-icon');
            if (!icon) return;

            const targetInputId = icon.getAttribute('data-target-input');
            const defaultValue = icon.getAttribute('data-default-value');
            if (!targetInputId || defaultValue === null) {
                 console.warn("AIPKit Restore Default: Missing target input ID or default value.", icon);
                 return;
            }

            const inputElement = document.getElementById(targetInputId); // Find globally by ID
            if (inputElement) {
                const oldValue = inputElement.value;
                if (oldValue !== defaultValue) {
                    inputElement.value = defaultValue;

                    // Manually trigger change and blur events for compatibility with autosave/other listeners
                    const changeEvent = new Event('change', { bubbles: true, cancelable: true });
                    const blurEvent = new Event('blur', { bubbles: true, cancelable: true });
                    inputElement.dispatchEvent(changeEvent);
                    inputElement.dispatchEvent(blurEvent);

                    // Update associated range slider display if applicable
                    if (inputElement.type === 'range') {
                        const valueSpan = document.getElementById(`${targetInputId}_value`);
                        if (valueSpan) {
                            valueSpan.textContent = defaultValue;
                        }
                    }
                }
            } else {
                console.warn(`AIPKit Restore Default: Target input element #${targetInputId} not found.`);
            }
        });
        scope.setAttribute(listenerAttr, 'true');
    }

    // Expose globally
    window.aipkit_initRestoreDefaultIcons = aipkit_initRestoreDefaultIcons;

})();