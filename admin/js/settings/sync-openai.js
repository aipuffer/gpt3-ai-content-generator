import { updateProviderModelSelects } from './sync-provider-model-selects.js';

(function() {
    'use strict';

    function aipkit_updateOpenAIModels(models) {
        updateProviderModelSelects(models, {
            dashboardModelKey: 'openai',
            selectId: 'aipkit_openai_model',
            chatSelectSuffix: '_openai_model',
            warningLabel: 'OpenAI',
        });

        const cwProviderSelect = document.getElementById('aipkit_content_writer_provider');
        const cwModelSelect = document.getElementById('aipkit_content_writer_model');
        if (
            cwProviderSelect &&
            cwModelSelect &&
            cwProviderSelect.value === 'openai' &&
            typeof window.aipkit_populateModelsForContentWriter === 'function'
        ) {
            window.aipkit_populateModelsForContentWriter(cwProviderSelect, cwModelSelect);
        }
    }

    window.aipkit_updateOpenAIModels = aipkit_updateOpenAIModels;
})();
