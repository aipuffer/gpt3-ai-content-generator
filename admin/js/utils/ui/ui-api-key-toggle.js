/**
 * AIPKit UI Utils - API Key Visibility Toggle
 */
(function() {
    'use strict';

    /**
     * Initializes API key visibility toggle buttons using event delegation.
     * @param {string} scopeSelector CSS selector for the container to scope the search.
     */
    function aipkit_initApiKeyToggles(scopeSelector) {
        const scope = document.querySelector(scopeSelector);
        if (!scope) return;

        // Use event delegation on the scope container
        const listenerAttr = 'data-apikey-toggle-listener-attached';
        if (scope.getAttribute(listenerAttr)) return; // Already attached

        scope.addEventListener('click', function(event) {
            const btn = event.target.closest('.aipkit_api-key-toggle');
            if (!btn) return;

            const wrapper = btn.closest('.aipkit_api-key-wrapper');
            if (!wrapper) return;
            const input = wrapper.querySelector('.aipkit_form-input'); // Target the input field directly
            const icon = btn.querySelector('.dashicons'); // Target the icon within the button
            if (!input || !icon) return;

            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('dashicons-visibility');
                icon.classList.add('dashicons-hidden');
            } else {
                input.type = 'password';
                icon.classList.remove('dashicons-hidden');
                icon.classList.add('dashicons-visibility');
            }
        });

        scope.setAttribute(listenerAttr, 'true');
    }

    // Expose globally
    window.aipkit_initApiKeyToggles = aipkit_initApiKeyToggles;

})();