/**
 * AIPKit Automated Tasks - Handle Queue Pagination Click
 * Manages click events on queue pagination buttons.
 */
(function() {
    'use strict';

    function aipkit_queue_handleQueuePaginationClick(event) {
        const button = event.target.closest('button[data-page]');
        if (button && !button.disabled) {
            const page = parseInt(button.dataset.page, 10);
            if (typeof window.aipkit_queue_fetchAndRenderQueueItems === 'function') {
                window.aipkit_queue_fetchAndRenderQueueItems(page);
            } else {
                console.error("Queue Pagination Click: fetchAndRenderQueueItems function not found.");
            }
        }
    }

    window.aipkit_queue_handleQueuePaginationClick = aipkit_queue_handleQueuePaginationClick;

})();