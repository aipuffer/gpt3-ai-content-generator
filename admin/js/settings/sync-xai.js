import { updateProviderModelSelects } from './sync-provider-model-selects.js';

(function() {
    'use strict';

    function aipkit_updateXAIModels(models) {
        updateProviderModelSelects(models, {
            dashboardModelKey: 'xai',
            selectId: 'aipkit_xai_model',
            chatSelectSuffix: '_xai_model',
            warningLabel: 'xAI',
        });
    }

    window.aipkit_updateXAIModels = aipkit_updateXAIModels;
})();
