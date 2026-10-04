import { updateProviderModelSelects } from './sync-provider-model-selects.js';

(function() {
    'use strict';

    /**
     * Update the Ollama model <select>.
     * @param {Array} models Array of model objects [{ id: '...', name: '...' }, ...].
     */
    function aipkit_updateOllamaModels(models) {
        updateProviderModelSelects(models, {
            dashboardModelKey: 'ollama',
            selectId: 'aipkit_ollama_model',
            chatSelectSuffix: '_ollama_model',
            warningLabel: 'Ollama',
            preserveMissingOldValue: false,
            afterUpdateOne: (select, oldValue) => {
                const nextValue = select.value || '';
                if (nextValue && nextValue !== oldValue) {
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                }
            },
        });
    }

    // Expose globally for AJAX callbacks and inline handlers
    window.aipkit_updateOllamaModels = aipkit_updateOllamaModels;
})();
