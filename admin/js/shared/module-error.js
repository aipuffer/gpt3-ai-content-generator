/**
 * AIPKit Dashboard - Module Error Renderer
 *
 * Provides a standardized way to display error messages when a module fails to load.
 */
(function() {
    'use strict';

    /**
     * Renders an error message within the specified module container.
     * @param {HTMLElement} container The container element (#aipkit_module-container).
     * @param {string} moduleName The name of the module that failed to load.
     * @param {string} errorMessage The specific error message to display.
     */
    function aipkit_renderModuleError(container, moduleName, errorMessage) {
        if (!container) return;
        container.classList.remove('aipkit_main-content--with-notices');

        const texts = window.aipkit_dashboard?.text || {}; // Get localized texts

        const errorTitle = texts.errorLoadingModuleTitle || 'Error Loading Module';
        const errorMsgTemplate = texts.errorLoadingModuleMsg || 'An error occurred while loading the \'%s\' module. Please try again later or check the console.';
        const errorDetailsLabel = texts.errorDetails || 'Details:';

        const formattedErrorMsg = errorMsgTemplate.replace('%s', moduleName);

        container.innerHTML = `
            <div class="aipkit_container">
                <div class="aipkit_container-header">
                    <div class="aipkit_container-title" style="color: var(--aipkit_status-warning, red);">${errorTitle}</div>
                </div>
                <div class="aipkit_container-body">
                    <p>${formattedErrorMsg}</p>
                    <p><strong>${errorDetailsLabel}</strong></p>
                    <pre style="white-space: pre-wrap; word-wrap: break-word; background-color: #f8f8f8; border: 1px solid #eee; padding: 5px;">${errorMessage || 'No specific error details available.'}</pre>
                </div>
            </div>`;
    }

    // Expose globally
    window.aipkit_renderModuleError = aipkit_renderModuleError;

})();
