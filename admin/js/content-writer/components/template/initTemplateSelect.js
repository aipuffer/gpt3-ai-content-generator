/**
 * AIPKit Content Writer - Initialize Template Select
 * Handles initializing and attaching event listeners to the template dropdown.
 */
(function() {
    'use strict';

    function aipkit_initTemplateSelect() {
        const templateSelect = document.getElementById('aipkit_cw_template_select');
        if (!templateSelect) {
            console.warn("initTemplateSelect: Template select element not found.");
            return;
        }

        // Fetch and populate templates on initialization
        if (typeof window.aipkit_fetchAndPopulateTemplates === 'function') {
            window.aipkit_fetchAndPopulateTemplates(templateSelect, { restoreSavedSettings: true });
        } else {
            console.error("initTemplateSelect: fetchAndPopulateTemplates function missing.");
        }

        // Bind change event to apply the selected template
        if (typeof window.aipkit_applyContentWriterTemplate === 'function' && templateSelect.dataset.listenerAttached !== 'true') {
            templateSelect.addEventListener('change', (event) => window.aipkit_applyContentWriterTemplate(event.target.value));
            templateSelect.dataset.listenerAttached = 'true';
        } else {
            if (typeof window.aipkit_applyContentWriterTemplate !== 'function') {
                console.error("initTemplateSelect: applyContentWriterTemplate function missing.");
            }
        }
    }

    window.aipkit_initTemplateSelect = aipkit_initTemplateSelect;
})();
