/**
 * AIPKit Automated Tasks - Show List Status
 * Displays a status message for task list actions.
 */
(function() {
    'use strict';

    function aipkit_list_showAutomatedTaskStatus(message, type = 'info') {
        if (typeof window.aipkit_form_showAutomatedTaskFormStatus === 'function') {
            window.aipkit_form_showAutomatedTaskFormStatus(message, type);
            return;
        }

        const statusDiv = document.getElementById('aipkit_automated_task_form_status');
        if (!statusDiv) return;

        statusDiv.textContent = message;
        statusDiv.className = `aipkit_form-help aipkit_settings_message-${type}`;

        setTimeout(() => {
            if (statusDiv.textContent === message) {
                statusDiv.textContent = '';
                statusDiv.className = 'aipkit_form-help';
            }
        }, 5000);
    }

    window.aipkit_list_showAutomatedTaskStatus = aipkit_list_showAutomatedTaskStatus;

})();
