/**
 * AIPKit - Vector Post Processor - Embedding Model Select Utility
 *
 * Handles populating the embedding provider and model dropdowns in the modal.
 */
(function() {
    'use strict';

    const escaper = window.aipkit_escapeHtml || function(str) { return str; };
    const initializedSelects = new WeakSet();

    /**
     * Populates the embedding picker for stores that use an explicit embedding model.
     */
    function aipkit_vpp_populateEmbeddingConfigSelects() {
        aipkit_vpp_populateEmbeddingModelSelect();
    }

    /**
     * Populates the embedding model dropdown with provider groups.
     */
    function aipkit_vpp_populateEmbeddingModelSelect() {
        const modelSelect = document.getElementById('aipkit_vpp_embedding_model_select');
        if (!modelSelect) {
            console.error("VPP Embedding Util: Embedding model select not found.");
            return;
        }
        const config = window.aipkit_vpp_config || {};
        const texts = config.text || {};
        const embeddingUtils = window.aipkit_embedding_utils || {};
        const resolveProviderEntries =
            typeof embeddingUtils.resolveProviderEntries === 'function'
                ? embeddingUtils.resolveProviderEntries
                : () => [];
        const populateModelSelect =
            typeof embeddingUtils.populateModelSelect === 'function'
                ? embeddingUtils.populateModelSelect
                : null;

        if (typeof populateModelSelect !== 'function') {
            modelSelect.innerHTML = '';
            modelSelect.appendChild(
                new Option(
                    escaper(
                        texts.no_embedding_models ||
                            '(No embedding models - Sync in AI Settings)'
                    ),
                    ''
                )
            );
            modelSelect.disabled = true;
            return;
        }

        const providerEntries = resolveProviderEntries(
            config.embeddingProviderMap,
            config.embeddingModelsByProvider,
            embeddingUtils.defaultProviderMap || undefined
        );
        const defaults = window.aipkit_getNewFeatureDefaults?.() || {};
        const selectedValue = initializedSelects.has(modelSelect)
            ? modelSelect.value
            : (defaults.vector_embedding_provider && defaults.vector_embedding_model
                ? `${defaults.vector_embedding_provider}::${defaults.vector_embedding_model}` : '');

        populateModelSelect(modelSelect, {
            grouped: true,
            providerEntries,
            modelsByProvider: config.embeddingModelsByProvider,
            includePlaceholder: true,
            placeholderText: texts.select_model || 'Select a model',
            emptyText:
                texts.no_embedding_models ||
                '(No embedding models - Sync in AI Settings)',
            selectedValue,
            preserveUnknownSelected: false,
            autoSelectFirst: false,
            escaper,
            valueFormatter: (provider, model) => `${provider}::${model.id}`,
        });
        initializedSelects.add(modelSelect);
        modelSelect._aipkitUnifiedModelSync?.();
    }

    // Expose functions globally
    window.aipkit_vpp_populateEmbeddingConfigSelects = aipkit_vpp_populateEmbeddingConfigSelects;
    window.aipkit_vpp_populateEmbeddingModelSelect = aipkit_vpp_populateEmbeddingModelSelect;

})();
