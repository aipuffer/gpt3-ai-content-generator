import { updateProviderModelSelects } from './sync-provider-model-selects.js';

(function() {
    'use strict';

    function aipkit_updateAzureDeployments(deployments) {
        updateProviderModelSelects(deployments, {
            dashboardModelKey: 'azure',
            selectId: 'aipkit_azure_deployment',
            chatSelectSuffix: '_azure_deployment',
            warningLabel: 'Azure',
            getModelLabel: (deployment) => {
                const deploymentId = deployment?.id || '';
                const modelName = deployment?.name || deploymentId;
                return deploymentId + (
                    modelName && modelName !== deploymentId
                        ? ` (model: ${modelName})`
                        : ''
                );
            },
        });
    }

    window.aipkit_updateAzureDeployments = aipkit_updateAzureDeployments;
})();
