/**
 * AIPKit Chatbot - Textarea Sizing
 */
(function() {
    'use strict';

    /**
     * Auto-resizes a textarea element based on its content, respecting CSS min/max-height.
     * @param {HTMLTextAreaElement} textarea - The textarea element to resize.
     */
    function aipkit_autoResizeTextarea(textarea) {
        if (!textarea || typeof textarea.style === 'undefined') {
            return;
        }
        textarea.style.height = 'auto'; // Temporarily shrink to get correct scrollHeight
        const scrollHeight = textarea.scrollHeight;
        const computedStyle = window.getComputedStyle(textarea);
        const minHeight = parseInt(computedStyle.minHeight, 10) || 0;
        const maxHeight = parseInt(computedStyle.maxHeight, 10) || Infinity;

        let targetHeight = Math.max(minHeight, scrollHeight);
        targetHeight = Math.min(targetHeight, maxHeight);

        textarea.style.height = `${targetHeight}px`;

        const isScrollable = targetHeight >= maxHeight;
        textarea.style.overflowY = isScrollable ? 'auto' : 'hidden';
        textarea.classList[isScrollable ? 'add' : 'remove']('aipkit-textarea-scrollable');
    }

    window.aipkit_autoResizeTextarea = aipkit_autoResizeTextarea;

})();
