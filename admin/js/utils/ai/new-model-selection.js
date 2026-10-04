/**
 * Resolve the provider/model used by fresh text-generation configurations.
 * Saved items must keep their explicit provider/model and bypass this helper.
 */
(function () {
  "use strict";

  const normalizeProviderKey = (value) => {
    const key = String(value || "").trim().toLowerCase();
    return key === "anthropic" ? "claude" : key;
  };

  const collectModelIds = (value, target = []) => {
    if (!value) {
      return target;
    }
    if (typeof value === "string") {
      target.push(value);
      return target;
    }
    if (Array.isArray(value)) {
      value.forEach((item) => collectModelIds(item, target));
      return target;
    }
    if (typeof value === "object") {
      const modelId = String(value.id || "").trim();
      if (modelId) {
        target.push(modelId);
        return target;
      }
      Object.values(value).forEach((item) => collectModelIds(item, target));
    }
    return target;
  };

  const uniqueIds = (values) => Array.from(new Set(values.filter(Boolean)));

  const getProviderState = (dashboard, providerKey) => {
    const state = dashboard?.modelRegistry?.providerStates?.[providerKey];
    return state && typeof state === "object" ? state : {};
  };

  const isProviderConfigured = (dashboard, providerKey) => {
    const state = getProviderState(dashboard, providerKey);
    if (state.locked) {
      return false;
    }
    if (typeof state.configured === "boolean") {
      return state.configured;
    }
    return Boolean(dashboard?.providerStatus?.[providerKey]);
  };

  const isProviderSynced = (dashboard, providerKey) => {
    const state = getProviderState(dashboard, providerKey);
    return (
      !state.connection_changed &&
      Number(state.last_success || 0) > 0 &&
      Number(state.model_count || 0) > 0
    );
  };

  const getProviderEntries = (dashboard, capability, allowedProviders) => {
    const providerCatalogs = dashboard?.modelCatalog?.providerCatalogs || {};
    const configuredPriority =
      dashboard?.modelCatalog?.providerPriority?.[capability];
    const priorityValues = Array.isArray(configuredPriority)
      ? configuredPriority
      : Object.keys(providerCatalogs);
    const rawAllowedProviders = Array.isArray(allowedProviders)
      ? allowedProviders
      : [];
    const hasAllowedList = rawAllowedProviders.some((provider) =>
      Boolean(
        String(
          provider && typeof provider === "object"
            ? provider.value || provider.key || ""
            : provider || ""
        ).trim()
      )
    );
    const allowedEntries = rawAllowedProviders
      .map((provider) => {
        if (provider && typeof provider === "object") {
          const value = String(provider.value || provider.key || "").trim();
          return { key: normalizeProviderKey(value), value };
        }
        const value = String(provider || "").trim();
        return { key: normalizeProviderKey(value), value };
      })
      .filter(
        (entry) =>
          entry.key && providerCatalogs?.[entry.key]?.[capability]
      );
    const allowedLookup = new Map(
      allowedEntries.map((entry) => [entry.key, entry.value])
    );
    const entries = [];
    const seen = new Set();

    priorityValues.forEach((provider) => {
      const providerValue = String(provider || "").trim();
      const key = normalizeProviderKey(providerValue);
      if (
        !key ||
        seen.has(key) ||
        !providerCatalogs?.[key]?.[capability] ||
        (hasAllowedList && !allowedLookup.has(key))
      ) {
        return;
      }
      seen.add(key);
      entries.push({
        key,
        value: allowedLookup.get(key) || providerValue || key,
      });
    });

    allowedEntries.forEach((entry) => {
      if (seen.has(entry.key)) {
        return;
      }
      seen.add(entry.key);
      entries.push(entry);
    });

    return entries;
  };

  const resolveModel = (dashboard, providerKey, capability) => {
    const catalogKey =
      dashboard?.modelCatalog?.providerCatalogs?.[providerKey]?.[capability] ||
      "";
    const serverSelection = dashboard?.newAiSelection;
    const savedDefault = capability === 'text_generation' &&
      normalizeProviderKey(serverSelection?.provider_key) === providerKey &&
      serverSelection?.source === 'saved_default' ? serverSelection.model : '';
    const defaultModel = String(
      dashboard?.modelCatalog?.defaults?.[catalogKey] || ""
    ).trim();
    const seedIds = uniqueIds(
      collectModelIds(dashboard?.modelCatalog?.seeds?.[catalogKey] || [])
    );
    const synced = isProviderSynced(dashboard, providerKey);
    const modelIds = uniqueIds(
      collectModelIds(
        synced
          ? dashboard?.models?.[providerKey] || []
          : dashboard?.modelCatalog?.seeds?.[catalogKey] || []
      )
    );
    const recommendedIds = uniqueIds(
      collectModelIds(
        synced
          ? dashboard?.recommendedModels?.[providerKey] || []
          : dashboard?.modelCatalog?.seeds?.[catalogKey] || []
      )
    );
    const availableIds = uniqueIds([...modelIds, ...recommendedIds]);
    const availableLookup = new Set(availableIds);

    if (savedDefault && availableLookup.has(savedDefault)) {
      return { modelId: savedDefault, catalogKey, source: 'saved_default' };
    }

    if (
      defaultModel &&
      (availableLookup.has(defaultModel) || (!synced && seedIds.includes(defaultModel)))
    ) {
      return { modelId: defaultModel, catalogKey, source: "catalog_default" };
    }

    const recommendedModel = recommendedIds.find((id) =>
      availableLookup.has(id)
    );
    if (recommendedModel) {
      return {
        modelId: recommendedModel,
        catalogKey,
        source: "recommended",
      };
    }

    if (modelIds[0]) {
      return { modelId: modelIds[0], catalogKey, source: "available" };
    }

    if (
      normalizeProviderKey(serverSelection?.provider_key) === providerKey &&
      serverSelection?.model
    ) {
      return {
        modelId: String(serverSelection.model),
        catalogKey: String(serverSelection.catalog_key || catalogKey),
        source: String(serverSelection.source || "server"),
      };
    }

    return { modelId: "", catalogKey, source: "none" };
  };

  function aipkit_resolveNewAiSelection(options = {}) {
    const dashboard = options.dashboard || window.aipkit_dashboard || {};
    const capability = String(
      options.capability || "text_generation"
    ).trim();
    const entries = getProviderEntries(
      dashboard,
      capability,
      options.allowedProviders
    );
    const preferredKey = normalizeProviderKey(
      options.preferredProvider || dashboard.main_provider
    );
    const server = dashboard.newAiSelection;
    const allowed = (options.allowedProviders || []).map((item) => typeof item === 'object' ? item.value || item.key : item).filter(Boolean);
    const serverKey = normalizeProviderKey(server?.provider_key || server?.provider);
    if (capability === 'text_generation' && serverKey === preferredKey && server?.model
        && isProviderConfigured(dashboard, serverKey)
        && (!allowed.length || allowed.some((value) => normalizeProviderKey(value) === serverKey))) {
      return { provider: serverKey, providerKey: serverKey,
        providerValue: allowed.find((value) => normalizeProviderKey(value) === serverKey) || server.provider,
        modelId: server.model, catalogKey: server.catalog_key || '', source: server.source || 'server',
        configured: true, synced: Boolean(server.synced), needsSetup: false, preferredProvider: preferredKey, fellBack: false };
    }
    const resolutions = entries.filter((entry) => entry.key !== 'aipuffercloud' || preferredKey === 'aipuffercloud').map((entry) => {
      const model = resolveModel(dashboard, entry.key, capability);
      const configured = isProviderConfigured(dashboard, entry.key);
      return {
        provider: entry.key,
        providerKey: entry.key,
        providerValue: entry.value,
        modelId: model.modelId,
        catalogKey: model.catalogKey,
        source: model.source,
        configured,
        synced: isProviderSynced(dashboard, entry.key),
        needsSetup: !configured || !model.modelId,
      };
    });

    if (preferredKey === 'aipuffercloud' && !isProviderConfigured(dashboard, preferredKey)) {
      return { provider: preferredKey, providerKey: preferredKey, providerValue: entries.find((entry) => entry.key === preferredKey)?.value || 'AIPufferCloud',
        modelId: '', configured: false, synced: false, needsSetup: true, preferredProvider: preferredKey, fellBack: false };
    }
    let selection = resolutions.find(
      (item) =>
        item.providerKey === preferredKey && item.configured && item.modelId
    );
    if (!selection) {
      selection = resolutions.find((item) => item.configured && item.modelId);
    }
    if (!selection) {
      selection = resolutions.find((item) => item.modelId);
    }
    if (!selection) {
      selection = resolutions[0] || {
        provider: "",
        providerKey: "",
        providerValue: "",
        modelId: "",
        catalogKey: "",
        source: "none",
        configured: false,
        synced: false,
        needsSetup: true,
      };
    }

    return {
      ...selection,
      preferredProvider: preferredKey,
      fellBack: Boolean(
        preferredKey && selection.providerKey !== preferredKey
      ),
    };
  }

  window.aipkit_resolveNewAiSelection = aipkit_resolveNewAiSelection;

  // Server-owned policy; read only when initializing a new form, never while loading a saved item.
  window.aipkit_getNewFeatureDefaults = () => ({ ...(window.aipkit_dashboard?.newFeatureDefaults || {}) });
  window.aipkit_applyNewFeatureDefaults = (root, prefix = '') => {
    const defaults = window.aipkit_getNewFeatureDefaults();
    ['vector_store_provider', 'vector_embedding_provider', 'vector_embedding_model', 'image_provider', 'image_model'].forEach((name) => {
      const field = root?.querySelector(`[name="${prefix}${name}"]`);
      if (!field || defaults[name] === undefined) return;
      const value = defaults[name];
      if (field.tagName === 'SELECT' && !Array.from(field.options).some((option) => option.value === value)) {
        const label = name.endsWith('_model') ? 'Select a model' : 'Select a provider';
        field.appendChild(new Option(value || (window.wp?.i18n?.__(label, 'gpt3-ai-content-generator') || label), value));
      }
      field.value = value;
    });
    return defaults;
  };


})();
