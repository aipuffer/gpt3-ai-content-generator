/** Shared HTML and attribute escaping for admin and public renderers. */
(function() {
    'use strict';

    /**
     * Basic HTML escaping. Replaces special characters with their HTML entities.
     * @param {string|null|undefined} unsafe String to escape.
     * @returns {string} Escaped string.
     */
    function aipkit_escapeHtml(unsafe) {
        if (unsafe === null || typeof unsafe === 'undefined') return '';
        const str = String(unsafe); // Ensure it's a string
        return str
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
     }

    /**
     * Escapes a string for use within an HTML attribute value.
     * @param {string|null|undefined} unsafe String to escape.
     * @returns {string} Escaped string suitable for attributes.
     */
    function aipkit_escapeAttribute(unsafe) {
        return aipkit_escapeHtml(unsafe);
    }

    // --- Expose globally ---
    window.aipkit_escapeHtml = aipkit_escapeHtml;
    window.aipkit_escapeAttribute = aipkit_escapeAttribute;

})();
