/**
 * AIPKit UI Utilities - Status Messages
 * Shared status renderer for vector store actions.
 */
(function() {
    'use strict';

    function aipkit_showOpenAIVectorStatus(message, type = 'info', containerId = 'aipkit_knowledge_base_global_status') {
        const statusDiv = document.getElementById(containerId);
        if (!statusDiv) return;

        if (statusDiv.messageTimeoutId) clearTimeout(statusDiv.messageTimeoutId);
        statusDiv.messageTimeoutId = null;

        const trimmedMessage = message ? String(message).trim() : '';
        if (trimmedMessage === '') {
            statusDiv.textContent = '';
            statusDiv.className = 'aipkit_form-help';
            statusDiv.style.display = 'none';
            statusDiv.style.opacity = '0';
            statusDiv.classList.remove('aipkit-active');
            return;
        }

        const isCurrentlyError = statusDiv.classList.contains('aipkit_settings_message-error');
        let effectiveType = type;
        if (isCurrentlyError && type !== 'error') {
            effectiveType = 'error';
        }

        statusDiv.className = 'aipkit_form-help';
        if (effectiveType === 'success') statusDiv.classList.add('aipkit_settings_message-success');
        else if (effectiveType === 'error') statusDiv.classList.add('aipkit_settings_message-error');
        else statusDiv.classList.add('aipkit_settings_message-info');

        if (effectiveType === 'error' && statusDiv.textContent) {
            statusDiv.textContent = statusDiv.textContent + '\n' + trimmedMessage;
        } else if (isCurrentlyError && type !== 'error' && statusDiv.textContent) {
            statusDiv.textContent = statusDiv.textContent + '\n' + trimmedMessage;
        } else {
            statusDiv.textContent = trimmedMessage;
        }

        statusDiv.style.display = 'block';
        statusDiv.style.opacity = '1';
        statusDiv.classList.add('aipkit-active');

        if (effectiveType !== 'error') {
            statusDiv.messageTimeoutId = setTimeout(() => {
                statusDiv.style.opacity = '0';
                setTimeout(() => {
                    if (statusDiv.textContent.includes(trimmedMessage)) {
                        statusDiv.textContent = '';
                        statusDiv.className = 'aipkit_form-help';
                        statusDiv.style.display = 'none';
                        statusDiv.classList.remove('aipkit-active');
                    }
                }, 300);
            }, 5000);
        }
    }

    window.aipkit_showOpenAIVectorStatus = aipkit_showOpenAIVectorStatus;
})();
