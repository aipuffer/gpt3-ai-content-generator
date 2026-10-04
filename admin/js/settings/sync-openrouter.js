import { updateProviderModelSelects } from './sync-provider-model-selects.js';

(function() {
    'use strict';

    function aipkit_updateOpenRouterModels(models) {
        updateProviderModelSelects(models, {
            dashboardModelKey: 'openrouter',
            selectId: 'aipkit_openrouter_model',
            chatSelectSuffix: '_openrouter_model',
            warningLabel: 'OpenRouter',
        });
    }

    window.aipkit_updateOpenRouterModels = aipkit_updateOpenRouterModels;
})();
