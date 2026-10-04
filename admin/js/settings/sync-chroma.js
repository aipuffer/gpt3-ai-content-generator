import { updateVectorStoreSelects } from './sync-vector-store-selects.js';

(function () {
    'use strict';

    function aipkit_updateChromaCollections(collections, selectElementId = 'aipkit_chroma_default_collection') {
        updateVectorStoreSelects(collections, {
            provider: 'chroma',
            targetSelectId: selectElementId,
            defaultSelectId: 'aipkit_chroma_default_collection',
            selectors: '#aipkit_chroma_default_collection, select[name="chroma_collection_names[]"], select[name="chroma_collection_name"]',
            defaultLabel: '-- Select Synced Collection --',
            emptyLabel: '(No collections found - Sync again)',
            chatConfigKey: 'chromaCollections',
            vppConfigKey: 'chroma_collections',
            automatedTasksConfigKey: 'chroma_collections',
            postEnhancerConfigKey: 'chroma_collections',
            refreshCallbackName: 'aipkit_populateChromaCollectionsMultiSelect',
            refreshAreaSelectors: [
                '.aipkit_popover_options_list',
                '.aipkit_popover_option_main',
            ],
            emptyRefreshAreaSelectors: [
                '.aipkit_popover_options_list',
                '.aipkit_popover_option_main',
                '.aipkit_settings_panel_body',
            ],
            sortInPlace: false,
            getSortLabel: (collection) => collection?.name || collection?.collection_name || collection?.id || '',
            getOptionValue: (collection) => collection?.name || collection?.collection_name || (typeof collection === 'string' ? collection : ''),
        });
    }

    window.aipkit_updateChromaCollections = aipkit_updateChromaCollections;
})();
