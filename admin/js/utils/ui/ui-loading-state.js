/**
 * AIPKit UI Utilities - Button Loading State
 * Shared helper for toggling loading states across admin modules.
 */
(function() {
    'use strict';

    function aipkit_setLoadingStateOpenAI(button, isLoading, defaultText = 'Submit', loadingText = 'Processing...') {
        if (!button) return;
        const btnTextEl = button.querySelector('.aipkit_btn-text') || button.querySelector('.aipkit_btn_text');
        const spinnerEl = button.querySelector('.aipkit_spinner');
        const dashiconEl = button.querySelector('.dashicons');

        if (isLoading) {
            button.disabled = true;
            if (!button.dataset.originalText) {
                if (btnTextEl) button.dataset.originalText = btnTextEl.textContent;
                else {
                    let textContent = '';
                    button.childNodes.forEach(node => { if (node.nodeType === Node.TEXT_NODE) textContent += node.textContent.trim(); });
                    button.dataset.originalText = textContent.trim();
                }
            }
            if (dashiconEl) {
                if (!dashiconEl.dataset.originalDisplay) {
                    dashiconEl.dataset.originalDisplay = dashiconEl.style.display || 'inline-block';
                }
                dashiconEl.style.display = 'none';
            }
            const effectiveLoadingText = (defaultText === '') ? '' : (button.dataset.loadingText || loadingText);
            if (btnTextEl) {
                if (!button.dataset.originalText) {
                    button.dataset.originalText = btnTextEl.textContent;
                }
                btnTextEl.textContent = effectiveLoadingText;
            } else if ((!dashiconEl || dashiconEl.style.display === 'none') && effectiveLoadingText) {
                Array.from(button.childNodes).forEach(node => { if (node.nodeType === Node.TEXT_NODE) node.remove(); });
                if (effectiveLoadingText) button.insertBefore(document.createTextNode(effectiveLoadingText), button.firstChild);
            }
            if (spinnerEl) spinnerEl.style.display = 'inline-block';
        } else {
            button.disabled = false;
            const originalText = (defaultText === '') ? '' : (button.dataset.originalText || defaultText);
            if (dashiconEl && dashiconEl.dataset.originalDisplay) {
                dashiconEl.style.display = dashiconEl.dataset.originalDisplay;
                delete dashiconEl.dataset.originalDisplay;
            }
            if (btnTextEl) {
                btnTextEl.textContent = originalText;
            } else if (defaultText !== '') {
                Array.from(button.childNodes).forEach(node => { if (node.nodeType === Node.TEXT_NODE) node.remove(); });
                if (originalText) {
                    if (dashiconEl) {
                        dashiconEl.insertAdjacentText('afterend', ' ' + originalText);
                    } else {
                        button.insertBefore(document.createTextNode(originalText), button.firstChild);
                    }
                }
            }
            if (spinnerEl) spinnerEl.style.display = 'none';
            delete button.dataset.originalText;
        }
    }

    window.aipkit_setLoadingStateOpenAI = aipkit_setLoadingStateOpenAI;
})();
