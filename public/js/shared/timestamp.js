/** Shared local-time formatting for Unix timestamps. */
(function() {
    'use strict';

    /**
     * Formats a Unix timestamp into HH:MM:SS or returns empty string.
     * @param {number|string|null|undefined} unixTimestamp Unix timestamp.
     * @returns {string} Formatted time or empty string.
     */
    function aipkit_formatTimestamp(unixTimestamp) {
        // Ensure input is a number and not zero/null/undefined
        const ts = Number(unixTimestamp);
        if (!ts || isNaN(ts)) return '';

        try {
            // Multiply by 1000 for JavaScript Date object (expects milliseconds)
            const date = new Date(ts * 1000);
            // Check if the date object is valid after conversion
            if (isNaN(date.getTime())) return '';

            const hh = String(date.getHours()).padStart(2, '0');
            const mm = String(date.getMinutes()).padStart(2, '0');
            const ss = String(date.getSeconds()).padStart(2, '0');
            return `${hh}:${mm}:${ss}`;
        } catch (e) {
            console.error("Error formatting timestamp:", unixTimestamp, e);
            return ''; // Return empty string on error
        }
    }

    // Expose globally
    window.aipkit_formatTimestamp = aipkit_formatTimestamp;

})();
