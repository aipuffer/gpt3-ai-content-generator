import { updateProviderModelSelects } from './sync-provider-model-selects.js';

(function() {
    'use strict';

    function aipkit_updateDeepSeekModels(models) {
        updateProviderModelSelects(models, {
            dashboardModelKey: 'deepseek',
            selectId: 'aipkit_deepseek_model',
            chatSelectSuffix: '_deepseek_model',
            warningLabel: 'DeepSeek',
        });
    }

    window.aipkit_updateDeepSeekModels = aipkit_updateDeepSeekModels;
})();
