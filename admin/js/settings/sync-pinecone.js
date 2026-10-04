import { updateVectorStoreSelects } from './sync-vector-store-selects.js';

(function () {
    'use strict';

    function aipkit_updatePineconeIndexes(indexes, selectElementId = 'aipkit_pinecone_default_index') {
        updateVectorStoreSelects(indexes, {
            provider: 'pinecone',
            targetSelectId: selectElementId,
            defaultSelectId: 'aipkit_pinecone_default_index',
            selectors: '#aipkit_pinecone_default_index, select[name="pinecone_index_name"]',
            defaultLabel: '-- Select Synced Index --',
            emptyLabel: '(No indexes found - Sync again)',
            chatConfigKey: 'pineconeIndexes',
            vppConfigKey: 'pinecone_indexes',
            automatedTasksConfigKey: 'pinecone_indexes',
            postEnhancerConfigKey: 'pinecone_indexes',
            refreshCallbackName: 'aipkit_populatePineconeIndexSelect',
            refreshAreaSelectors: [
                '.aipkit_popover_options_list',
                '.aipkit_popover_option_main',
                '.aipkit_settings_panel_body',
            ],
            emptyRefreshAreaSelectors: [
                '.aipkit_popover_options_list',
                '.aipkit_popover_option_main',
                '.aipkit_settings_panel_body',
            ],
            sortInPlace: true,
            getSortLabel: (index) => index?.name || index?.id || '',
            getOptionValue: (index) => index?.name || index?.id || (typeof index === 'string' ? index : ''),
        });
    }

    window.aipkit_updatePineconeIndexes = aipkit_updatePineconeIndexes;
})();
