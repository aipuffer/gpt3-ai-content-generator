import { updateProviderModelSelects } from './sync-provider-model-selects.js';

(function() {
    'use strict';

    function normalizeGoogleModelValue(value) {
        const modelValue = String(value || '');
        return modelValue.startsWith('models/') ? modelValue.substring(7) : modelValue;
    }

    function aipkit_updateGoogleModels(models) {
        updateProviderModelSelects(models, {
            dashboardModelKey: 'google',
            selectId: 'aipkit_google_model',
            chatSelectSuffix: '_google_model',
            warningLabel: 'Google',
            matchesOldValue: (modelId, oldValue) => {
                const cleanOldValue = normalizeGoogleModelValue(oldValue);
                return modelId === oldValue || modelId === cleanOldValue;
            },
            getMissingOldOption: (oldValue) => {
                const cleanOldValue = normalizeGoogleModelValue(oldValue);
                const displayValue = cleanOldValue || oldValue;
                return {
                    value: oldValue,
                    label: displayValue,
                    queryValue: displayValue,
                };
            },
        });
    }

    window.aipkit_updateGoogleModels = aipkit_updateGoogleModels;
})();
