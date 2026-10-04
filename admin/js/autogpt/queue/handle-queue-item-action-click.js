/**
 * AIPKit Automated Tasks - Handle Queue Item Action Click
 * Manages click events on queue item action buttons (Delete, Retry).
 */
(function() {
    'use strict';

    async function aipkit_queue_handleQueueItemActionClick(event) {
        const button = event.target.closest('button[data-action]');
        if (!button) return;

        const action = button.dataset.action;
        const itemId = button.dataset.itemId;
        if (!itemId) return;

        // Access config and texts dynamically
        const config = window.aipkit_automated_tasks_config || {};
        const texts = config.text || {};
        const currentQueuePage = window.aipkit_automated_tasks_queue_state ? window.aipkit_automated_tasks_queue_state.page : 1;

        const showHeaderStatus = (message, tone) => {
            if (typeof window.aipkit_form_showAutomatedTaskFormStatus === 'function') {
                window.aipkit_form_showAutomatedTaskFormStatus(message, tone);
                return;
            }
            if (typeof window.aipkit_list_showAutomatedTaskStatus === 'function') {
                window.aipkit_list_showAutomatedTaskStatus(message, tone);
            }
        };

        if (action === 'delete_queue_item') {
            const executeDelete = async () => {
                if (typeof window.aipkit_setLoadingStateOpenAI === 'function') window.aipkit_setLoadingStateOpenAI(button, true, texts.delete_button || 'Delete');
                try {
                    if (typeof window.aipkit_apiRequest !== 'function') throw new Error("API request function missing.");
                    await window.aipkit_apiRequest('aipkit_delete_automated_task_queue_item', { item_id: itemId, _ajax_nonce: config.nonce_manage_tasks });
                    showHeaderStatus(texts.queue_item_deleted || 'Queue item deleted.', 'success');
                    if (typeof window.aipkit_queue_fetchAndRenderQueueItems === 'function') window.aipkit_queue_fetchAndRenderQueueItems(currentQueuePage);
                } catch (error) {
                    showHeaderStatus(`${texts.error_deleting_queue_item || 'Error deleting item:'} ${error.message || 'Unknown error'}`, 'error');
                } finally {
                    if (typeof window.aipkit_setLoadingStateOpenAI === 'function') window.aipkit_setLoadingStateOpenAI(button, false, texts.delete_button || 'Delete');
                }
            };

            const itemName = button
                .closest('tr')
                ?.querySelector('.aipkit_task_primary_text, .aipkit_queue_primary_link')
                ?.textContent?.trim() || 'this item';
            const confirmMessage = (
                texts.confirm_delete_queue_item ||
                'This removes “%s” from the queue. This cannot be undone.'
            ).replace('%s', itemName);

            if (typeof window.aipkit_showConfirmModal === 'function') {
                window.aipkit_showConfirmModal(confirmMessage, {
                    title: texts.confirm_delete_queue_item_title || 'Remove item from queue',
                    confirmText: texts.delete_button || 'Delete',
                    cancelText: texts.cancel_button || 'Cancel',
                    variant: 'danger',
                    onConfirm: () => {
                        void executeDelete();
                    }
                });
                return;
            }

            if (!confirm(confirmMessage)) {
                return;
            }

            await executeDelete();
        } else if (action === 'retry_queue_item') {
            if (typeof window.aipkit_setLoadingStateOpenAI === 'function') window.aipkit_setLoadingStateOpenAI(button, true, texts.retry_button || 'Retry');
            try {
                if (typeof window.aipkit_apiRequest !== 'function') throw new Error("API request function missing.");
                await window.aipkit_apiRequest('aipkit_retry_automated_task_queue_item', { item_id: itemId, _ajax_nonce: config.nonce_manage_tasks });
                showHeaderStatus(texts.item_marked_retry || 'Item marked for retry. Queue processing will pick it up.', 'success');
                if (typeof window.aipkit_queue_fetchAndRenderQueueItems === 'function') window.aipkit_queue_fetchAndRenderQueueItems(currentQueuePage);
            } catch (error) {
                showHeaderStatus(`${texts.error_retrying_item || 'Error retrying item:'} ${error.message || 'Unknown error'}`, 'error');
            } finally {
                if (typeof window.aipkit_setLoadingStateOpenAI === 'function') window.aipkit_setLoadingStateOpenAI(button, false, texts.retry_button || 'Retry');
            }
        }
    }

    window.aipkit_queue_handleQueueItemActionClick = aipkit_queue_handleQueueItemActionClick;

})();
