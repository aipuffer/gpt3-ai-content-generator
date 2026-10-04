/**
 * AIPKit AutoGPT - Shared Knowledge Base UI Handler
 * Supports both content-writing and content-enhancement advanced panel context UIs.
 */
(function () {
  "use strict";

  const escaper = window.aipkit_escapeHtml || ((str) => str);
  const PROVIDERS = new Set(["local", "openai", "google", "pinecone", "qdrant", "chroma"]);
  const KB_DEFINITIONS = {
    cw: {
      rootSelector: ".aipkit_task_cw_kb_section",
      modeControl: "#aipkit_task_cw_kb_mode_control",
      enableCheckbox: "#aipkit_task_cw_enable_vector_store",
      providerField: "#aipkit_task_cw_vector_store_provider",
      sourceRow: "#aipkit_task_cw_kb_source_row",
      sourceLabel: "#aipkit_task_cw_kb_source_label",
      openaiField: ".aipkit_task_cw_vector_openai_field",
      googleField: ".aipkit_task_cw_vector_google_field",
      aiProvider: "#aipkit_task_cw_ai_provider",
      pineconeField: ".aipkit_task_cw_vector_pinecone_field",
      qdrantField: ".aipkit_task_cw_vector_qdrant_field",
      chromaField: ".aipkit_task_cw_vector_chroma_field",
      localField: ".aipkit_task_cw_vector_local_field",
      embeddingSection: "#aipkit_task_cw_kb_embedding_section",
      embeddingProvider: "#aipkit_task_cw_vector_embedding_provider",
      embeddingModel: "#aipkit_task_cw_vector_embedding_model",
      inlineSettings: "[data-aipkit-kb-inline-settings]",
      sourceFieldIds: {
        openai: "aipkit_task_cw_openai_vector_store_ids",
        google: "aipkit_task_cw_google_file_search_store_names",
        pinecone: "aipkit_task_cw_pinecone_index_name",
        qdrant: "aipkit_task_cw_qdrant_collection_name",
        chroma: "aipkit_task_cw_chroma_collection_name",
        local: "aipkit_task_cw_local_store_id",
      },
      openaiSelectSelectors: [
        "#aipkit_task_cw_openai_vector_store_ids",
        'select[name="openai_vector_store_ids[]"]',
      ],
    },
    ce: {
      rootSelector: ".aipkit_task_ce_kb_section",
      modeControl: "#aipkit_task_ce_kb_mode_control",
      enableCheckbox: "#aipkit_task_ce_enable_vector_store",
      providerField: "#aipkit_task_ce_vector_store_provider",
      sourceRow: "#aipkit_task_ce_kb_source_row",
      sourceLabel: "#aipkit_task_ce_kb_source_label",
      openaiField: ".aipkit_task_ce_vector_openai_field",
      googleField: ".aipkit_task_ce_vector_google_field",
      aiProvider: "#aipkit_task_ce_ai_provider",
      pineconeField: ".aipkit_task_ce_vector_pinecone_field",
      qdrantField: ".aipkit_task_ce_vector_qdrant_field",
      chromaField: ".aipkit_task_ce_vector_chroma_field",
      localField: ".aipkit_task_ce_vector_local_field",
      embeddingSection: "#aipkit_task_ce_kb_embedding_section",
      embeddingProvider: "#aipkit_task_ce_vector_embedding_provider",
      embeddingModel: "#aipkit_task_ce_vector_embedding_model",
      inlineSettings: "[data-aipkit-kb-inline-settings]",
      sourceFieldIds: {
        openai: "aipkit_task_ce_openai_vector_store_ids",
        google: "aipkit_task_ce_google_file_search_store_names",
        pinecone: "aipkit_task_ce_pinecone_index_name",
        qdrant: "aipkit_task_ce_qdrant_collection_name",
        chroma: "aipkit_task_ce_chroma_collection_name",
        local: "aipkit_task_ce_local_store_id",
      },
      openaiSelectSelectors: [
        "#aipkit_task_ce_openai_vector_store_ids",
        'select[name="ce_openai_vector_store_ids[]"]',
      ],
    },
  };
  const ROOT_SELECTOR = Object.values(KB_DEFINITIONS)
    .map((definition) => definition.rootSelector)
    .join(", ");

  function getSharedOpenaiStores() {
    const sharedConfig = window.aipkit_chat_config;
    if (!sharedConfig || typeof sharedConfig !== "object") {
      return null;
    }
    if (
      !Object.prototype.hasOwnProperty.call(sharedConfig, "openaiVectorStores")
    ) {
      return null;
    }
    return Array.isArray(sharedConfig.openaiVectorStores)
      ? sharedConfig.openaiVectorStores
      : [];
  }

  function getAutogptOpenaiStores() {
    const sharedStores = getSharedOpenaiStores();
    if (sharedStores !== null) {
      if (
        !window.aipkit_automated_tasks_config ||
        typeof window.aipkit_automated_tasks_config !== "object"
      ) {
        window.aipkit_automated_tasks_config = {};
      }
      window.aipkit_automated_tasks_config.openai_vector_stores = sharedStores;
      return sharedStores;
    }

    const configStores =
      window.aipkit_automated_tasks_config?.openai_vector_stores;
    return Array.isArray(configStores) ? configStores : [];
  }

  function buildOpenaiStoreOptionLabel(store, index) {
    const storeName = String(
      store?.name || store?.id || `Untitled store ${index}`
    );
    const fileCount = store?.file_counts?.total;
    if (typeof fileCount === "number") {
      return `${escaper(storeName)} (${fileCount} ${
        fileCount === 1 ? "File" : "Files"
      })`;
    }
    return `${escaper(storeName)} (Files: N/A)`;
  }

  function populateOpenaiStoreSelect(selectElement, stores) {
    if (!selectElement) {
      return;
    }

    const selectedValues = new Set(
      Array.from(selectElement.options)
        .filter((option) => option.selected && option.value)
        .map((option) => option.value)
    );

    selectElement.innerHTML = "";
    let storeIndex = 1;

    (Array.isArray(stores) ? stores : []).forEach((store) => {
      if (!store || typeof store !== "object") {
        return;
      }

      const storeId = String(store.id || store.vector_store_id || "").trim();
      if (!storeId) {
        return;
      }

      const option = new Option(
        buildOpenaiStoreOptionLabel(store, storeIndex),
        storeId
      );
      if (selectedValues.has(storeId)) {
        option.selected = true;
      }
      selectElement.appendChild(option);
      storeIndex += 1;
    });

    selectedValues.forEach((value) => {
      if (!value) {
        return;
      }

      const exists = Array.from(selectElement.options).some(
        (option) => option.value === value
      );
      if (!exists) {
        const option = new Option(value, value);
        option.selected = true;
        selectElement.appendChild(option);
      }
    });

    if (!selectElement.options.length) {
      const emptyOption = new Option("No stores found", "");
      emptyOption.disabled = true;
      selectElement.appendChild(emptyOption);
    }
  }

  function syncOpenaiStoreSelects(container, storesOverride = null) {
    if (!container) {
      return;
    }

    const stores = Array.isArray(storesOverride)
      ? storesOverride
      : getAutogptOpenaiStores();
    const seen = new Set();

    Object.values(KB_DEFINITIONS).forEach((definition) => {
      definition.openaiSelectSelectors.forEach((selector) => {
        container.querySelectorAll(selector).forEach((selectElement) => {
          if (seen.has(selectElement)) {
            return;
          }
          seen.add(selectElement);
          populateOpenaiStoreSelect(selectElement, stores);
        });
      });
    });

    if (
      typeof window.aipkit_initContentWriterVectorStoreMultiSelect === "function"
    ) {
      window.aipkit_initContentWriterVectorStoreMultiSelect(container);
    }
  }

  function getDefinitionFromRoot(root) {
    if (!root) {
      return null;
    }

    if (root.matches(KB_DEFINITIONS.ce.rootSelector)) {
      return KB_DEFINITIONS.ce;
    }
    if (root.matches(KB_DEFINITIONS.cw.rootSelector)) {
      return KB_DEFINITIONS.cw;
    }
    return null;
  }

  function getContextContainer() {
    return document.getElementById("aipkit_autogpt_container") || document;
  }

  function queryContextElement(root, selector) {
    if (!selector) {
      return null;
    }
    return (
      root?.querySelector(selector) ||
      getContextContainer().querySelector(selector)
    );
  }

  function getRoot(node, scopeKey = "") {
    const container = document.getElementById("aipkit_autogpt_container");
    if (!container) {
      return null;
    }

    if (scopeKey && KB_DEFINITIONS[scopeKey]) {
      const scopedRoot = node?.closest?.(KB_DEFINITIONS[scopeKey].rootSelector);
      return (
        scopedRoot ||
        container.querySelector(KB_DEFINITIONS[scopeKey].rootSelector) ||
        null
      );
    }

    if (node?.closest) {
      const matchedRoot = node.closest(ROOT_SELECTOR);
      if (matchedRoot) {
        return matchedRoot;
      }
    }

    return container.querySelector(ROOT_SELECTOR);
  }

  function getElements(root) {
    const definition = getDefinitionFromRoot(root);
    if (!root || !definition) {
      return {};
    }

    return {
      definition,
      modeControl: queryContextElement(root, definition.modeControl),
      enableCheckbox: queryContextElement(root, definition.enableCheckbox),
      providerField: queryContextElement(root, definition.providerField),
      sourceRow: queryContextElement(root, definition.sourceRow),
      sourceLabel: queryContextElement(root, definition.sourceLabel),
      openaiField: queryContextElement(root, definition.openaiField),
      googleField: queryContextElement(root, definition.googleField),
      aiProvider: queryContextElement(root, definition.aiProvider),
      pineconeField: queryContextElement(root, definition.pineconeField),
      qdrantField: queryContextElement(root, definition.qdrantField),
      chromaField: queryContextElement(root, definition.chromaField),
      localField: queryContextElement(root, definition.localField),
      embeddingSection: queryContextElement(root, definition.embeddingSection),
      embeddingProvider: queryContextElement(root, definition.embeddingProvider),
      embeddingModel: queryContextElement(root, definition.embeddingModel),
      inlineSettings: queryContextElement(root, definition.inlineSettings),
    };
  }

  function setHidden(element, shouldHide) {
    if (element) {
      element.hidden = Boolean(shouldHide);
    }
  }

  function normalizeProvider(value) {
    const normalized = String(value || "").trim().toLowerCase();
    return PROVIDERS.has(normalized) ? normalized : "openai";
  }

  function getModeFromFields(root) {
    const { enableCheckbox, providerField } = getElements(root);
    if (!enableCheckbox || !enableCheckbox.checked) {
      return "off";
    }

    return normalizeProvider(providerField ? providerField.value : "openai");
  }

  function populateEmbeddingModelSelect(root) {
    const { embeddingModel, embeddingProvider } = getElements(root);
    if (!embeddingModel || !embeddingProvider) {
      return;
    }

    const config = window.aipkit_automated_tasks_config || {};
    const formState = window.aipkit_automated_tasks_form_state || {};
    const embeddingUtils = window.aipkit_embedding_utils || {};
    const populateModelSelect =
      typeof embeddingUtils.populateModelSelect === "function"
        ? embeddingUtils.populateModelSelect
        : null;
    const resolveProviderEntries =
      typeof embeddingUtils.resolveProviderEntries === "function"
        ? embeddingUtils.resolveProviderEntries
        : null;

    if (typeof populateModelSelect !== "function") {
      embeddingModel.innerHTML = "";
      embeddingModel.add(new Option("No models found", ""));
      embeddingModel.disabled = true;
      return;
    }

    const pendingValue = formState.pendingEmbeddingModelSelection || "";
    const currentProvider = embeddingProvider.value || "";
    const currentValue = embeddingModel.value || "";
    const selectedValue = pendingValue || currentValue;
    const providerEntries = Array.from(embeddingProvider.options || [])
      .map((option) => ({
        key: option.value,
        label: option.textContent?.trim() || option.value,
      }))
      .filter((entry) => entry.key);
    const rawResolvedProviderEntries = providerEntries.length
      ? providerEntries
      : resolveProviderEntries?.(
          config.embedding_provider_map,
          config.embedding_models_by_provider,
          embeddingUtils.defaultProviderMap || undefined
        ) || [];
    const resolvedProviderEntries =
      window.aipkit_autogpt_provider_setup?.sortProviderEntries?.(
        rawResolvedProviderEntries
      ) || rawResolvedProviderEntries;
    const result = populateModelSelect(embeddingModel, {
      grouped: true,
      providerEntries: resolvedProviderEntries,
      modelsByProvider: config.embedding_models_by_provider,
      includePlaceholder: true,
      placeholderText: "-- Select Model --",
      emptyText: "No models found",
      selectedValue,
      preserveUnknownSelected: true,
      autoSelectFirst: false,
      escaper,
      valueFormatter: (_, model) => model.id,
      unknownProviderKey: currentProvider,
    });

    if (selectedValue) {
      const exactOption = Array.from(embeddingModel.options || []).find(
        (option) =>
          option.value === selectedValue &&
          option.dataset.provider === currentProvider
      );
      if (exactOption) {
        exactOption.selected = true;
      }
    }

    const selectedOption = embeddingModel.selectedOptions?.[0] || null;
    const selectedProvider = selectedOption?.dataset?.provider || "";
    if (selectedProvider) {
      embeddingProvider.value = selectedProvider;
    }
    embeddingModel._aipkitUnifiedModelSync?.();
    window.aipkit_autogpt_provider_setup?.annotateProviderOptions?.(
      embeddingModel
    );

    if (pendingValue && result.matchedSelected) {
      formState.pendingEmbeddingModelSelection = null;
    }
  }

  function syncModeFields(root, mode, dispatchChange) {
    const { enableCheckbox, providerField } = getElements(root);
    if (!enableCheckbox || !providerField) {
      return;
    }

    const normalizedMode = PROVIDERS.has(mode) ? mode : "off";
    enableCheckbox.checked = normalizedMode !== "off";

    if (normalizedMode !== "off") {
      providerField.value = normalizedMode;
    } else if (!providerField.value) {
      providerField.value = "openai";
    }

    if (dispatchChange) {
      enableCheckbox.dispatchEvent(new Event("change", { bubbles: true }));
      providerField.dispatchEvent(new Event("change", { bubbles: true }));
    }
  }

  function updateVectorSettingsUI(root) {
    const {
      definition,
      modeControl,
      sourceRow,
      sourceLabel,
      openaiField,
      googleField,
      aiProvider,
      pineconeField,
      qdrantField,
      chromaField,
      localField,
      embeddingSection,
      embeddingProvider,
      inlineSettings,
    } = getElements(root);

    if (!definition || !modeControl || !sourceRow) {
      return;
    }

    window.aipkit_autogpt_provider_setup?.annotateProviderOptions?.(
      modeControl,
      { nativeLabels: true }
    );

    const mode = getModeFromFields(root);
    const googleOption = Array.from(modeControl.options || []).find(
      (option) => option.value === "google"
    );
    if (googleOption) {
      googleOption.disabled = String(aiProvider?.value || "").toLowerCase() !== "google";
    }
    const isEnabled = mode !== "off";

    if (modeControl.value !== mode) {
      modeControl.value = mode;
    }

    setHidden(sourceRow, !isEnabled);
    sourceRow.dataset.aipkitKbSourceMode = mode;

    if (!isEnabled) {
      setHidden(inlineSettings, true);
      setHidden(openaiField, true);
      setHidden(googleField, true);
      setHidden(pineconeField, true);
      setHidden(qdrantField, true);
      setHidden(chromaField, true);
      setHidden(localField, true);
      setHidden(embeddingSection, true);
      return;
    }

    setHidden(inlineSettings, false);

    const sourceMetaMap = {
      local: {
        text: "Knowledge base",
        fieldId: definition.sourceFieldIds.local,
      },
      openai: {
        text: "Vector stores",
        fieldId: definition.sourceFieldIds.openai,
      },
      google: {
        text: "Stores",
        fieldId: definition.sourceFieldIds.google,
      },
      pinecone: {
        text: "Index",
        fieldId: definition.sourceFieldIds.pinecone,
      },
      qdrant: {
        text: "Collection",
        fieldId: definition.sourceFieldIds.qdrant,
      },
      chroma: {
        text: "Collection",
        fieldId: definition.sourceFieldIds.chroma,
      },
    };
    const sourceMeta = sourceMetaMap[mode] || sourceMetaMap.openai;

    if (sourceLabel) {
      sourceLabel.textContent = sourceMeta.text;
      sourceLabel.htmlFor = sourceMeta.fieldId;
    }

    setHidden(openaiField, mode !== "openai");
    setHidden(googleField, mode !== "google");
    setHidden(pineconeField, mode !== "pinecone");
    setHidden(qdrantField, mode !== "qdrant");
    setHidden(chromaField, mode !== "chroma");
    setHidden(localField, mode !== "local");

    const needsEmbedding =
      mode === "local" || mode === "pinecone" || mode === "qdrant" || mode === "chroma";
    setHidden(embeddingSection, !needsEmbedding);

    if (needsEmbedding && embeddingProvider) {
      const defaultProvider = window.aipkit_getNewFeatureDefaults?.().vector_embedding_provider || '';
      const normalizedEmbeddingProvider = Array.from(
        embeddingProvider.options || []
      ).some((option) => option.value === embeddingProvider.value)
        ? embeddingProvider.value
        : defaultProvider;

      if (embeddingProvider.value !== normalizedEmbeddingProvider) {
        embeddingProvider.value = normalizedEmbeddingProvider;
      }

      populateEmbeddingModelSelect(root);
    }

  }

  function matchesAnySelector(target, selectorList) {
    return selectorList.some((selector) => target?.matches?.(selector));
  }

  function getSelectorList(key) {
    return Object.values(KB_DEFINITIONS).map((definition) => definition[key]);
  }

  function getRoots(container, scopeKey = "") {
    if (!container) {
      return [];
    }

    if (scopeKey && KB_DEFINITIONS[scopeKey]) {
      return Array.from(
        container.querySelectorAll(KB_DEFINITIONS[scopeKey].rootSelector)
      );
    }

    return Array.from(container.querySelectorAll(ROOT_SELECTOR));
  }

  function initVectorSettings(container, scopeKey = "") {
    if (!container) {
      return;
    }

    syncOpenaiStoreSelects(container);
    getRoots(container, scopeKey).forEach((root) => updateVectorSettingsUI(root));
  }

  function initKnowledgeBaseListeners(container) {
    if (!container || container.dataset.kbListenerAttached === "true") {
      return;
    }

    container.addEventListener("change", (event) => {
      const target = event.target;
      const providerScope = target?.matches?.(KB_DEFINITIONS.ce.aiProvider)
        ? "ce"
        : target?.matches?.(KB_DEFINITIONS.cw.aiProvider)
          ? "cw"
          : "";
      const root = getRoot(target, providerScope);
      if (!root) {
        return;
      }

      if (matchesAnySelector(target, getSelectorList("modeControl"))) {
        syncModeFields(root, target.value, true);
        updateVectorSettingsUI(root);
        return;
      }

      if (
        matchesAnySelector(target, getSelectorList("enableCheckbox")) ||
        matchesAnySelector(target, getSelectorList("providerField")) ||
        matchesAnySelector(target, getSelectorList("aiProvider"))
      ) {
        updateVectorSettingsUI(root);
        return;
      }

      if (matchesAnySelector(target, getSelectorList("embeddingProvider"))) {
        populateEmbeddingModelSelect(root);
        return;
      }

      if (matchesAnySelector(target, getSelectorList("embeddingModel"))) {
        const { embeddingProvider } = getElements(root);
        const selectedProvider =
          target.selectedOptions?.[0]?.dataset?.provider || "";
        if (embeddingProvider && selectedProvider) {
          embeddingProvider.value = selectedProvider;
        }
      }
    });

    container.dataset.kbListenerAttached = "true";
  }

  function initScopedKnowledgeBaseUI(scopeKey = "") {
    const container = document.getElementById("aipkit_autogpt_container");
    if (!container) {
      return;
    }

    initKnowledgeBaseListeners(container);
    initVectorSettings(container, scopeKey);
  }

  function updateScopedKnowledgeBaseUI(scopeKey = "") {
    const container = document.getElementById("aipkit_autogpt_container");
    if (!container) {
      return;
    }

    initScopedKnowledgeBaseUI(scopeKey);
    syncOpenaiStoreSelects(container);

    getRoots(container, scopeKey).forEach((root) => {
      updateVectorSettingsUI(root);
      populateEmbeddingModelSelect(root);
    });
  }

  window.aipkit_initCwKnowledgeBaseUI = () => initScopedKnowledgeBaseUI("cw");
  window.aipkit_updateCwKnowledgeBaseUI = () =>
    updateScopedKnowledgeBaseUI("cw");
  window.aipkit_initCeKnowledgeBaseUI = () => initScopedKnowledgeBaseUI("ce");
  window.aipkit_updateCeKnowledgeBaseUI = () =>
    updateScopedKnowledgeBaseUI("ce");
  window.aipkit_autogptSyncOpenaiVectorStoreSelects = syncOpenaiStoreSelects;
})();
