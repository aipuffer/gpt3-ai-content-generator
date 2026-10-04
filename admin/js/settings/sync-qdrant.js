import { updateVectorStoreSelects } from './sync-vector-store-selects.js';

(function() {
    'use strict';

    function aipkit_updateQdrantCollections(collections, selectElementId = 'aipkit_qdrant_default_collection') {
        updateVectorStoreSelects(collections, {
            provider: 'qdrant',
            targetSelectId: selectElementId,
            defaultSelectId: 'aipkit_qdrant_default_collection',
            selectors: '#aipkit_qdrant_default_collection, select[name="qdrant_collection_names[]"], select[name="qdrant_collection_name"]',
            defaultLabel: '-- Select Synced Collection --',
            emptyLabel: '(No collections found - Sync again)',
            chatConfigKey: 'qdrantCollections',
            vppConfigKey: 'qdrant_collections',
            automatedTasksConfigKey: 'qdrant_collections',
            postEnhancerConfigKey: 'qdrant_collections',
            refreshCallbackName: 'aipkit_populateQdrantCollectionsMultiSelect',
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
            getSortLabel: (collection) => collection?.name || collection?.id || '',
            getOptionValue: (collection) => collection?.name || collection?.id || '',
        });
    }

    window.aipkit_updateQdrantCollections = aipkit_updateQdrantCollections;
})();
