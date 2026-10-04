import { updateProviderModelSelects } from './sync-provider-model-selects.js';

(function() {
    'use strict';

    function aipkit_updateClaudeModels(models) {
        updateProviderModelSelects(models, {
            dashboardModelKey: 'claude',
            selectId: 'aipkit_claude_model',
            chatSelectSuffix: '_claude_model',
            warningLabel: 'Claude',
        });

        const cwProviderSelect = document.getElementById('aipkit_content_writer_provider');
        const cwModelSelect = document.getElementById('aipkit_content_writer_model');
        if (
            cwProviderSelect &&
            cwModelSelect &&
            cwProviderSelect.value === 'claude' &&
            typeof window.aipkit_populateModelsForContentWriter === 'function'
        ) {
            window.aipkit_populateModelsForContentWriter(cwProviderSelect, cwModelSelect);
        }
    }

    window.aipkit_updateClaudeModels = aipkit_updateClaudeModels;
})();
