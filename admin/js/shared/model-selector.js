/**
 * Shared universal model selector.
 *
 * Product-specific adapters continue to own persisted provider/model state.
 * This component owns discovery, provider state, accessibility, and interaction.
 */
window.aipkit_initUnifiedModelSelector = function () {
  "use strict";
  const positionUnifiedPopover = ({ selector, trigger, popover, modelBody, providersList, list, mountPopover, onLayout = () => {} }) => {
    if (!popover || popover.hidden) {
      return;
    }
    let compactPanel = popover.classList.contains("is-compact");
    let bottomSheet = popover.classList.contains("is-bottom-sheet");
    const panelScrollPosition = {
      providerTop: providersList?.scrollTop || 0,
      providerLeft: providersList?.scrollLeft || 0,
      listTop: list?.scrollTop || 0,
      listLeft: list?.scrollLeft || 0,
    };
    const restorePanelScrollPosition = () => {
      if (providersList) {
        providersList.scrollTop = panelScrollPosition.providerTop;
        providersList.scrollLeft = panelScrollPosition.providerLeft;
      }
      if (list) {
        list.scrollTop = panelScrollPosition.listTop;
        list.scrollLeft = panelScrollPosition.listLeft;
      }
    };
    mountPopover();
    const gutter = 12;
    const gap = 8;
    const viewportWidth = document.documentElement.clientWidth;
    const viewportHeight = document.documentElement.clientHeight;
    const triggerRect = trigger.getBoundingClientRect();
    const nextBottomSheet = window.matchMedia("(max-width: 640px)").matches;

    popover.style.position = "fixed";
    popover.style.right = "auto";
    popover.style.transform = "none";
    ["height", "min-height"].forEach((property) =>
      modelBody?.style.removeProperty(property)
    );

    if (nextBottomSheet) {
      const layoutChanged = !compactPanel || !bottomSheet;
      compactPanel = true;
      bottomSheet = true;
      popover.classList.add("is-compact", "is-bottom-sheet");
      popover.classList.remove("opens-up");
      popover.setAttribute("aria-modal", "true");
      popover.style.top = "auto";
      popover.style.bottom = "0px";
      popover.style.left = "0px";
      popover.style.width = `${Math.max(0, viewportWidth)}px`;
      if (layoutChanged) {
        onLayout(compactPanel, bottomSheet);
      }
      restorePanelScrollPosition();
      return;
    }

    bottomSheet = false;
    popover.classList.remove("is-bottom-sheet");
    popover.removeAttribute("aria-modal");
    popover.style.bottom = "auto";

    let leftBoundary = gutter;
    let rightBoundary = viewportWidth - gutter;
    let topBoundary = gutter;
    let bottomBoundary = viewportHeight - gutter;
    const adminBar = document.getElementById("wpadminbar");
    if (adminBar) {
      const adminBarRect = adminBar.getBoundingClientRect();
      if (adminBarRect.bottom > 0) {
        topBoundary = Math.max(topBoundary, adminBarRect.bottom + gutter);
      }
    }
    const panelWidth = Math.min(600, rightBoundary - leftBoundary);
    const nextCompactPanel = panelWidth < 560;
    const layoutChanged = compactPanel !== nextCompactPanel;
    compactPanel = nextCompactPanel;
    popover.classList.toggle("is-compact", compactPanel);
    popover.style.width = `${Math.max(0, panelWidth)}px`;
    if (layoutChanged) {
      onLayout(compactPanel, bottomSheet);
    }

    let popoverRect = popover.getBoundingClientRect();
    const spaceBelow = bottomBoundary - triggerRect.bottom - gap;
    const spaceAbove = triggerRect.top - topBoundary - gap;
    const prefersTop = selector.dataset.aipkitPopoverPlacement === "top";
    const opensUp = prefersTop
      ? spaceAbove >= Math.min(120, popoverRect.height)
      : spaceBelow < popoverRect.height && spaceAbove > spaceBelow;
    const availableHeight = opensUp ? spaceAbove : spaceBelow;
    if (
      modelBody &&
      availableHeight > 0 &&
      popoverRect.height > availableHeight
    ) {
      const modelBodyRect = modelBody.getBoundingClientRect();
      const fixedHeight = popoverRect.height - modelBodyRect.height;
      modelBody.style.height = `${Math.max(
        120,
        Math.floor(availableHeight - fixedHeight)
      )}px`;
      modelBody.style.minHeight = "0px";
      popoverRect = popover.getBoundingClientRect();
    }
    const left = Math.min(
      Math.max(triggerRect.left, leftBoundary),
      Math.max(leftBoundary, rightBoundary - popoverRect.width)
    );
    const top = opensUp
      ? Math.max(topBoundary, triggerRect.top - popoverRect.height - gap)
      : Math.min(
          triggerRect.bottom + gap,
          Math.max(topBoundary, bottomBoundary - popoverRect.height)
        );

    popover.style.left = `${Math.round(left)}px`;
    popover.style.top = `${Math.round(top)}px`;
    popover.classList.toggle("opens-up", opensUp);
    restorePanelScrollPosition();
  };

  if (typeof window.aipkit_createUnifiedModelSelector === "function") {
    return;
  }

  const translate =
    window.wp?.i18n?.__ ||
    function (text) {
      return text;
    };

  const controllers = new Set();
  const syncingProviders = new Set();
  const nativeSelectorQuery = [
    "select[data-aipkit-universal-model-provider]",
    "select[data-aipkit-universal-model-combined='1']",
    "select[data-aipkit-universal-model-provider-source]",
  ].join(", ");
  const providerOrder = Array.isArray(
    window.aipkit_dashboard?.modelCatalog?.providerPriority?.text_generation
  )
    ? window.aipkit_dashboard.modelCatalog.providerPriority.text_generation.map(
        (provider) => String(provider || "").toLowerCase()
      )
    : [];
  const syncableProviders = new Set([
    "aipuffercloud",
    "openai",
    "openrouter",
    "google",
    "azure",
    "claude",
    "deepseek",
    "xai",
    "ollama",
    "elevenlabs",
    "replicate",
  ]);
  const staticSourceProviders = new Set(["pexels", "pixabay"]);
  const logoSlugs = {
    aipuffercloud: "aipuffercloud",
    openai: "openai",
    openrouter: "openrouter",
    google: "google",
    azure: "azure",
    claude: "claude",
    anthropic: "claude",
    deepseek: "deepseek",
    xai: "xai",
    ollama: "ollama",
    elevenlabs: "elevenlabs",
    replicate: "replicate",
    pexels: "pexels",
    pixabay: "pixabay",
  };
  const logoAliases = {
    "google-vertex": "google",
    "google-vertex-anthropic": "claude",
    "meta-llama": "ollama",
    "moonshotai-cn": "",
    "zai-coding-plan": "",
    "github-copilot": "",
    "github-models": "",
    "amazon-bedrock": "",
    "cloudflare-workers-ai": "",
    "fireworks-ai": "",
  };
  const logoKeys = new Set(Object.values(logoSlugs));
  const monochromeLogoKeys = new Set([
    "openai",
    "claude",
    "xai",
    "ollama",
    "replicate",
  ]);
  const providerLabels = {
    aipuffercloud: "AI Puffer",
    openai: "OpenAI",
    openrouter: "OpenRouter",
    google: "Google",
    azure: "Azure",
    claude: "Anthropic",
    anthropic: "Anthropic",
    deepseek: "DeepSeek",
    xai: "xAI",
    ollama: "Ollama",
    elevenlabs: "ElevenLabs",
    replicate: "Replicate",
    pexels: "Pexels",
    pixabay: "Pixabay",
    "stock-photos": "Stock Photos",
  };
  let catalogStateRequest = null;
  let catalogStateFetchedAt = 0;

  const normalizeSlug = (value) =>
    String(value || "")
      .trim()
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-+|-+$/g, "");

  const normalizeProviderKey = (provider) => {
    const normalized = normalizeSlug(provider);
    return normalized === "anthropic" ? "claude" : normalized;
  };

  const cloudUnavailable = () =>
    window.aipkit_dashboard?.providerStatus?.aipuffercloud === false;
  const providerIsAvailable = (provider) =>
    normalizeProviderKey(provider) !== "aipuffercloud" || !cloudUnavailable();

  const sameProvider = (first, second) =>
    normalizeProviderKey(first) === normalizeProviderKey(second);

  const canDiscoverCloud = (capability, diagnostics) =>
    diagnostics && typeof window.aipkit_openProviderConnection === "function"
      && Boolean(window.aipkit_dashboard?.modelCatalog?.providerCatalogs?.aipuffercloud?.[capability]);

  const getProviderLabel = (provider, fallback = "") => {
    const key = normalizeProviderKey(provider);
    return providerLabels[key] || String(fallback || provider || "").trim();
  };

  const getLogoSlug = (provider) => {
    const normalizedProvider = normalizeSlug(provider);
    let slug = logoSlugs[normalizedProvider] || normalizedProvider;
    slug = Object.prototype.hasOwnProperty.call(logoAliases, slug)
      ? logoAliases[slug]
      : slug;
    return logoKeys.has(slug) ? slug : "";
  };

  const setLogo = (logo, provider) => {
    if (!logo) {
      return;
    }
    const providerLabel = String(provider || "").trim();
    logo.innerHTML = "";
    logo.classList.remove("is-fallback", "is-monochrome");
    Array.from(logo.classList).forEach((className) => {
      if (className.indexOf("aipkit_settings_select_picker_icon--") === 0) {
        logo.classList.remove(className);
      }
    });
    if (!providerLabel) {
      logo.hidden = true;
      return;
    }
    logo.hidden = false;
    const slug = getLogoSlug(providerLabel);
    if (slug) {
      logo.classList.add(`aipkit_settings_select_picker_icon--${slug}`);
      logo.classList.toggle("is-monochrome", monochromeLogoKeys.has(slug));
      return;
    }
    logo.classList.add("is-fallback");
    logo.textContent = getProviderLabel(providerLabel).charAt(0).toUpperCase();
  };

  const formatGroupLabel = (rawLabel, fallbackLabel = "") => {
    const cleaned = String(rawLabel || "")
      .trim()
      .replace(/^[~_\s-]+/, "")
      .replace(/[_-]+/g, " ")
      .replace(/\s+/g, " ")
      .trim();
    if (!cleaned) {
      return fallbackLabel;
    }
    const specialLabels = {
      ai21: "AI21",
      anthropic: "Anthropic",
      azure: "Azure",
      deepseek: "DeepSeek",
      google: "Google",
      moonshotai: "Moonshot AI",
      openai: "OpenAI",
      openrouter: "OpenRouter",
      "stock photos": "Stock Photos",
      xai: "xAI",
    };
    const lower = cleaned.toLowerCase();
    if (specialLabels[lower]) {
      return specialLabels[lower];
    }
    return cleaned
      .split(" ")
      .map(
        (part) =>
          specialLabels[part.toLowerCase()] ||
          `${part.charAt(0).toUpperCase()}${part.slice(1)}`
      )
      .join(" ");
  };

  const getProviderStateMap = () => {
    const states = window.aipkit_dashboard?.modelRegistry?.providerStates;
    return states && typeof states === "object" ? states : {};
  };

  const updateProviderStates = (states) => {
    if (!states || typeof states !== "object") {
      return;
    }
    window.aipkit_dashboard = window.aipkit_dashboard || {};
    window.aipkit_dashboard.modelRegistry =
      window.aipkit_dashboard.modelRegistry || {};
    const current = getProviderStateMap();
    Object.entries(states).forEach(([key, state]) => {
      if (!state || typeof state !== "object") {
        return;
      }
      const providerKey = normalizeProviderKey(state.provider || key);
      if (providerKey) {
        current[providerKey] = { ...(current[providerKey] || {}), ...state };
      }
    });
    window.aipkit_dashboard.modelRegistry.providerStates = current;
    window.dispatchEvent(
      new CustomEvent("aipkit:model-registry-state-updated", {
        detail: { providerStates: current },
      })
    );
  };

  const getProviderState = (provider, entries = []) => {
    const key = normalizeProviderKey(provider);
    const stateMap = getProviderStateMap();
    const raw = stateMap[key] || stateMap[provider] || {};
    let status = String(raw.status || "").trim().toLowerCase();
    if (!status) {
      status = entries.some((entry) => entry.setupRequired)
        ? "not_configured"
        : "ready";
    }
    if (
      status === "configured_unsynced" &&
      staticSourceProviders.has(key) &&
      entries.length > 0
    ) {
      status = entries.some((entry) => entry.setupRequired)
        ? "not_configured"
        : "ready";
    }
    if (raw.locked) {
      status = "locked";
    }
    if (key === "aipuffercloud" && cloudUnavailable()) status = "not_configured";
    return {
      ...raw,
      provider: raw.provider || provider,
      status,
      configured: Boolean(raw.configured),
      locked: status === "locked",
    };
  };

  const getLockedProProviderAccess = (provider, capability) => {
    const planValue = window.aipkit_dashboard?.isProPlan;
    const hasPlanValue = planValue !== undefined && planValue !== null;
    const isProPlan = [true, 1, "1", "true"].includes(planValue);
    if (!hasPlanValue || isProPlan) {
      return null;
    }
    const providerKey = normalizeProviderKey(provider);
    const normalizedCapability = normalizeSlug(capability).replace(/-/g, "_");
    const access =
      window.aipkit_dashboard?.modelCatalog?.providerAccess?.[providerKey];
    const capabilityAccess = access?.capabilities?.[normalizedCapability];
    if (!access?.requiresPro || !capabilityAccess) {
      return null;
    }
    return {
      ...access,
      provider: access.provider || provider,
      capability: normalizedCapability,
    };
  };

  const getProviderCapabilityAccess = (capability) => {
    const providerAccess =
      window.aipkit_dashboard?.modelCatalog?.providerAccess;
    if (!providerAccess || typeof providerAccess !== "object") {
      return [];
    }
    const normalizedCapability = normalizeSlug(capability).replace(/-/g, "_");
    if (!normalizedCapability) {
      return [];
    }
    return Object.values(providerAccess).filter(
      (access) => Boolean(access?.capabilities?.[normalizedCapability])
    );
  };

  const refreshCatalogState = (providers = []) => {
    if (typeof window.aipkit_apiRequest !== "function") {
      return Promise.resolve(null);
    }
    const now = Date.now();
    if (catalogStateRequest) {
      return catalogStateRequest;
    }
    if (now - catalogStateFetchedAt < 60000) {
      return Promise.resolve(null);
    }
    catalogStateRequest = window
      .aipkit_apiRequest("aipkit_get_model_catalog", {
        providers,
        include_resources: "false",
        page: 1,
        per_page: 1,
      })
      .then((response) => {
        updateProviderStates(response?.provider_states || {});
        catalogStateFetchedAt = Date.now();
        return response;
      })
      .catch(() => null)
      .finally(() => {
        catalogStateRequest = null;
      });
    return catalogStateRequest;
  };

  const normalizeGroups = (value) => {
    const recommended = Array.isArray(value?.recommended)
      ? value.recommended
      : [];
    const groups =
      value?.groups instanceof Map
        ? value.groups
        : new Map(Object.entries(value?.groups || {}));
    return { recommended, groups };
  };

  const getEntryKey = (entry) =>
    `${normalizeProviderKey(entry.provider)}::${String(entry.modelValue || "")}`;

  const localizedFamilyCache = new Map();
  const normalizeModelLookupKey = (modelId) =>
    String(modelId || "")
      .trim()
      .toLowerCase()
      .replace(/^models\//, "");

  const getLocalizedModelFamily = (provider, modelValue) => {
    const providerKey = normalizeProviderKey(provider);
    const dashboard = window.aipkit_dashboard || {};
    const sources = [
      dashboard.models?.[providerKey],
      dashboard.embeddingModels?.[providerKey],
      dashboard.embeddingModelsByProvider?.[providerKey],
      dashboard.imageGeneratorModels?.[providerKey],
      dashboard.imageGeneratorVideoModels?.[providerKey],
    ].filter(Boolean);
    const recommendedSource =
      dashboard.recommendedModels?.[providerKey];
    const cached = localizedFamilyCache.get(providerKey);
    if (
      !cached ||
      cached.sources.length !== sources.length ||
      cached.sources.some((source, index) => source !== sources[index]) ||
      cached.recommendedSource !== recommendedSource
    ) {
      const lookup = new Map();
      const appendRows = (rows, parentLabel = "", parentOrder = 999) => {
        if (!Array.isArray(rows)) {
          return;
        }
        rows.forEach((model) => {
          if (!model || typeof model !== "object" || !model.id) {
            return;
          }
          const familyLabel = String(model.family_label || parentLabel || "");
          lookup.set(normalizeModelLookupKey(model.id), {
            familyKey: String(
              model.family_key || normalizeSlug(familyLabel) || "other"
            ),
            familyLabel,
            familyOrder: Number(model.family_order ?? parentOrder ?? 999),
            familyCollapsed: Boolean(model.family_collapsed),
            recommended: Boolean(model.recommended),
            credits: typeof model.credits === "string" ? model.credits : "",
            creditsDescription: typeof model.credits_description === "string" ? model.credits_description : "",
            creditsPerReply: Number(model.estimates?.chatReply) || 0,
          });
        });
      };
      sources.forEach((source) => {
        if (Array.isArray(source)) {
          appendRows(source);
        } else if (source && typeof source === "object") {
          Object.entries(source).forEach(([familyLabel, rows], index) =>
            appendRows(rows, familyLabel, index)
          );
        }
      });
      appendRows(Array.isArray(recommendedSource) ? recommendedSource : []);
      localizedFamilyCache.set(providerKey, {
        sources,
        recommendedSource,
        lookup,
      });
    }
    return (
      localizedFamilyCache
        .get(providerKey)
        ?.lookup.get(normalizeModelLookupKey(modelValue)) || null
    );
  };

  const allProviderKey = "__all__";
  const favoritesProviderKey = "__favorites__";
  const allDefaultLimit = 30;
  const allDefaultMinimum = 12;
  const recentModelsLimit = 8;
  const userStorageSuffix = String(
    window.aipkit_dashboard?.currentUserId || "guest"
  );
  const favoritesStorageKey = `aipkit_unified_model_favorites_v1_${String(
    userStorageSuffix
  )}`;
  const recentModelsStorageKey = `aipkit_unified_model_recent_v1_${String(
    userStorageSuffix
  )}`;
  const readFavoriteModelKeys = () => {
    try {
      const saved = JSON.parse(
        window.localStorage.getItem(favoritesStorageKey) || "[]"
      );
      return new Set(
        Array.isArray(saved)
          ? saved.filter((key) => typeof key === "string" && key !== "")
          : []
      );
    } catch (error) {
      return new Set();
    }
  };
  let favoriteModelKeys = readFavoriteModelKeys();

  const readRecentModelKeys = () => {
    try {
      const saved = JSON.parse(
        window.localStorage.getItem(recentModelsStorageKey) || "[]"
      );
      return Array.isArray(saved)
        ? saved.filter((key) => typeof key === "string" && key !== "")
        : [];
    } catch (error) {
      return [];
    }
  };
  let recentModelKeys = readRecentModelKeys();

  const isFavoriteModel = (entry) =>
    favoriteModelKeys.has(getEntryKey(entry));

  const toggleFavoriteModel = (provider, modelValue) => {
    const key = getEntryKey({ provider, modelValue });
    if (!normalizeProviderKey(provider) || !modelValue) {
      return;
    }
    if (favoriteModelKeys.has(key)) {
      favoriteModelKeys.delete(key);
    } else {
      favoriteModelKeys.add(key);
    }
    try {
      window.localStorage.setItem(
        favoritesStorageKey,
        JSON.stringify(Array.from(favoriteModelKeys))
      );
    } catch (error) {
      // The current session still retains favorites when browser storage is unavailable.
    }
    controllers.forEach((controller) => controller.render());
  };

  const rememberRecentModel = (provider, modelValue) => {
    const key = getEntryKey({ provider, modelValue });
    if (!normalizeProviderKey(provider) || !modelValue) {
      return;
    }
    recentModelKeys = [
      key,
      ...recentModelKeys.filter((recentKey) => recentKey !== key),
    ].slice(0, recentModelsLimit);
    try {
      window.localStorage.setItem(
        recentModelsStorageKey,
        JSON.stringify(recentModelKeys)
      );
    } catch (error) {
      // The current session still retains recent models when storage is unavailable.
    }
  };

  const collectCuratedAllEntries = (entries, selection) => {
    const entryMap = new Map(entries.map((entry) => [getEntryKey(entry), entry]));
    const curated = new Map();
    const addEntry = (entry) => {
      if (entry && curated.size < allDefaultLimit) {
        curated.set(getEntryKey(entry), entry);
      }
    };

    addEntry(entryMap.get(getEntryKey(selection)));
    entries.filter(isFavoriteModel).slice(0, 10).forEach(addEntry);
    recentModelKeys.forEach((key) => addEntry(entryMap.get(key)));
    entries.filter((entry) => entry.recommended).forEach(addEntry);

    if (curated.size < allDefaultMinimum) {
      entries.slice(0, allDefaultMinimum).forEach(addEntry);
    }
    return Array.from(curated.values()).slice(0, allDefaultLimit);
  };

  const collectEntries = (adapter) => {
    const { recommended, groups } = normalizeGroups(adapter.getGroups?.() || {});
    const entries = new Map();
    const addEntry = (entry, extra = {}) => {
      if (!entry?.provider || !entry?.modelValue || !providerIsAvailable(entry.provider)) {
        return;
      }
      const key = getEntryKey(entry);
      entries.set(key, {
        ...(entries.get(key) || {}),
        ...entry,
        ...extra,
        providerLabel: getProviderLabel(
          entry.provider,
          entry.providerLabel || entry.provider
        ),
      });
    };
    recommended.forEach((entry) => addEntry(entry, { recommended: true }));
    groups.forEach((groupEntries, groupLabel) => {
      (groupEntries || []).forEach((entry) =>
        addEntry(entry, { groupLabel: String(groupLabel || "") })
      );
    });
    return Array.from(entries.values());
  };

  const getNeutralProviderState = (provider) => ({
    provider,
    status: "ready",
    configured: true,
    locked: false,
  });

  const collectProviders = (
    adapter,
    entries,
    selection,
    capability = "",
    providerDiagnosticsEnabled = true
  ) => {
    const providers = new Map();
    const addProvider = (value) => {
      const item = typeof value === "string" ? { provider: value } : value || {};
      const provider = String(item.provider || item.value || "").trim();
      const key = normalizeProviderKey(provider);
      if (!provider || !key) {
        return;
      }
      const current = providers.get(key) || {};
      providers.set(key, {
        ...current,
        ...item,
        provider,
        providerLabel: getProviderLabel(
          provider,
          item.providerLabel || item.label || provider
        ),
      });
    };
    (adapter.getProviders?.() || []).forEach(addProvider);
    // Admin setup can discover Cloud without a connected account or a fabricated model list.
    if (canDiscoverCloud(capability, providerDiagnosticsEnabled)) {
      addProvider("AIPufferCloud");
    }
    entries.forEach((entry) => addProvider(entry));
    if (providerDiagnosticsEnabled) {
      getProviderCapabilityAccess(capability).forEach((access) => {
        const proAccess = getLockedProProviderAccess(
          access?.provider || "",
          capability
        );
        addProvider({
          provider: access.provider,
          providerLabel: getProviderLabel(access.provider),
          ...(proAccess ? { proAccess } : {}),
        });
      });
    }
    if (selection.provider) {
      addProvider({
        provider: selection.provider,
        providerLabel: selection.providerLabel || selection.provider,
      });
    }
    const sourceOrder = new Map(
      Array.from(providers.values()).map((record, index) => [
        normalizeProviderKey(record.provider),
        index,
      ])
    );
    const records = Array.from(providers.values()).map((record) => {
      const providerEntries = entries.filter((entry) =>
        sameProvider(entry.provider, record.provider)
      );
      const providerState = providerDiagnosticsEnabled
        ? getProviderState(record.provider, providerEntries)
        : getNeutralProviderState(record.provider);
      const proAccess = providerDiagnosticsEnabled
        ? record.proAccess ||
          getLockedProProviderAccess(record.provider, capability)
        : null;
      return {
        ...record,
        entries: providerEntries,
        proAccess,
        state:
          proAccess
            ? { ...providerState, status: "locked", locked: true }
            : record.disabled && !providerState.locked
            ? { ...providerState, status: "locked", locked: true }
            : providerState,
      };
    });
    return records.sort((first, second) => {
      const firstOrder = providerOrder.indexOf(normalizeProviderKey(first.provider));
      const secondOrder = providerOrder.indexOf(normalizeProviderKey(second.provider));
      return (
        (firstOrder === -1 ? 999 : firstOrder) -
          (secondOrder === -1 ? 999 : secondOrder) ||
        (sourceOrder.get(normalizeProviderKey(first.provider)) ?? 999) -
          (sourceOrder.get(normalizeProviderKey(second.provider)) ?? 999) ||
        first.providerLabel.localeCompare(second.providerLabel)
      );
    });
  };

  const entryMatches = (entry, searchTerm, activeFilter) => {
    if (
      activeFilter &&
      activeFilter !== "all" &&
      String(entry.category || "") !== activeFilter
    ) {
      return false;
    }
    if (!searchTerm) {
      return true;
    }
    const searchText =
      entry.searchText ||
      `${entry.providerLabel || entry.provider || ""} ${entry.groupLabel || ""} ${entry.familyLabel || ""} ${
        entry.modelLabel || ""
      } ${entry.modelValue || ""}`.toLowerCase();
    return searchText.includes(searchTerm);
  };

  const createProviderButton = (record, activeProvider, visibleCount) => {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "aipkit_unified_model_provider";
    button.dataset.provider = record.provider;
    button.setAttribute("role", "option");
    const isActive = sameProvider(record.provider, activeProvider);
    const isAll = record.provider === allProviderKey;
    const isFavorites = record.provider === favoritesProviderKey;
    const isProLocked = Boolean(record.proAccess);
    button.classList.toggle("aipkit_unified_model_provider--all", isAll);
    button.classList.toggle(
      "aipkit_unified_model_provider--favorites",
      isFavorites
    );
    button.classList.toggle("is-active", isActive);
    button.classList.toggle("is-pro-locked", isProLocked);
    button.setAttribute("aria-selected", isActive ? "true" : "false");
    if (!isFavorites && record.state.locked && !isProLocked) {
      button.setAttribute("aria-disabled", "true");
    }
    if (isProLocked) {
      button.setAttribute(
        "aria-label",
        `${record.providerLabel}, ${translate(
          "Pro feature",
          "gpt3-ai-content-generator"
        )}`
      );
    }

    const logo = document.createElement("span");
    logo.className = isAll
      ? "aipkit_unified_model_all_icon dashicons dashicons-screenoptions"
      : isFavorites
        ? "aipkit_unified_model_favorites_icon dashicons dashicons-star-filled"
        : "aipkit_unified_model_logo";
    logo.setAttribute("aria-hidden", "true");
    if (!isAll && !isFavorites) {
      setLogo(logo, record.provider);
    }

    const label = document.createElement("span");
    label.className = "aipkit_unified_model_provider_name";
    label.textContent = record.providerLabel;
    label.title = record.providerLabel;

    const meta = document.createElement("span");
    meta.className = `aipkit_unified_model_provider_meta is-${
      isAll ? "all" : isFavorites ? "favorites" : record.state.status
    }`;
    if (isAll) {
      meta.hidden = true;
    } else if (isFavorites) {
      meta.textContent = String(visibleCount);
    } else if (record.state.locked) {
      meta.innerHTML = '<span class="dashicons dashicons-lock" aria-hidden="true"></span>';
      meta.setAttribute(
        "aria-label",
        translate("Requires Pro", "gpt3-ai-content-generator")
      );
    } else if (
      record.state.status === "not_configured" ||
      record.state.status === "configured_unsynced" ||
      record.state.status === "stale" ||
      record.state.status === "error"
    ) {
      const dot = document.createElement("span");
      dot.className = "aipkit_unified_model_provider_dot";
      dot.setAttribute("aria-hidden", "true");
      meta.appendChild(dot);
      if (visibleCount > 0) {
        meta.appendChild(document.createTextNode(String(visibleCount)));
      }
    } else {
      meta.textContent = String(visibleCount);
    }

    button.append(logo, label, meta);
    return button;
  };

  const createProUpgradeView = (record) => {
    const access = record?.proAccess || {};
    const view = document.createElement("div");
    view.className = "aipkit_unified_model_pro_view";

    const card = document.createElement("section");
    card.className = "aipkit_unified_model_pro_card";
    const icon = document.createElement("span");
    icon.className =
      "aipkit_unified_model_pro_icon dashicons dashicons-lock";
    icon.setAttribute("aria-hidden", "true");
    const copy = document.createElement("div");
    copy.className = "aipkit_unified_model_pro_copy";
    const title = document.createElement("h3");
    title.textContent = translate(
      "Ollama is a Pro feature",
      "gpt3-ai-content-generator"
    );
    const description = document.createElement("p");
    description.textContent = translate(
      "Connect local Ollama models to run open-source LLMs directly from your own server.",
      "gpt3-ai-content-generator"
    );
    const upgradeLink = document.createElement("a");
    upgradeLink.className =
      "aipkit_unified_model_upgrade_cta aipkit_pro_upgrade_button";
    upgradeLink.href =
      window.aipkit_dashboard?.upgradeUrl ||
      "admin.php?page=wpaicg-pricing";
    upgradeLink.textContent = translate(
      "Upgrade to Pro",
      "gpt3-ai-content-generator"
    );
    copy.append(title, description, upgradeLink);
    card.append(icon, copy);

    view.appendChild(card);
    return view;
  };

  const createModelItem = (
    entry,
    selection,
    providerState,
    { showProvider = false, compact = false, showFavorite = true } = {}
  ) => {
    const row = document.createElement("div");
    row.className = "aipkit_unified_model_item_row";
    row.classList.toggle(
      "aipkit_unified_model_item_row--without-favorite",
      !showFavorite
    );
    row.setAttribute("role", "listitem");
    const item = document.createElement("button");
    item.type = "button";
    item.className = "aipkit_unified_model_item";
    item.dataset.provider = String(entry.provider || "");
    item.dataset.model = String(entry.modelValue || "");
    item.dataset.category = String(entry.category || "");
    const isSelected =
      sameProvider(entry.provider, selection.provider) &&
      String(entry.modelValue || "") === String(selection.modelValue || "");
    if (isSelected) {
      item.setAttribute("aria-current", "true");
    }
    row.classList.toggle("is-selected", isSelected);
    item.classList.toggle("is-selected", isSelected);
    if (providerState.locked) {
      item.disabled = true;
    }

    if (compact && showProvider) {
      const modelLogo = document.createElement("span");
      modelLogo.className = "aipkit_unified_model_logo aipkit_unified_model_item_logo";
      modelLogo.setAttribute("aria-hidden", "true");
      setLogo(modelLogo, entry.logoProvider || entry.provider);
      item.appendChild(modelLogo);
    }

    // Cloud prices are examples; billing uses the tokens actually consumed.
    const cloudPrice = getLocalizedModelFamily(entry.provider, entry.modelValue);
    const credits = cloudPrice?.credits || "";
    if (credits && cloudPrice?.creditsDescription) {
      item.title = cloudPrice.creditsDescription;
      item.setAttribute("aria-description", cloudPrice.creditsDescription);
    }

    const content = document.createElement("span");
    content.className = "aipkit_unified_model_item_content";
    const name = document.createElement("span");
    name.className = "aipkit_unified_model_item_name";
    name.textContent = entry.modelLabel || entry.modelValue || "";
    content.appendChild(name);

    if (compact && showProvider) {
      const meta = document.createElement("span");
      meta.className = "aipkit_unified_model_item_meta";
      const metaParts = [
        getProviderLabel(
          entry.provider,
          entry.providerLabel || entry.provider
        ),
      ];
      if (credits) {
        metaParts.push(credits);
      } else if (entry.recommended) {
        metaParts.push(
          translate("recommended", "gpt3-ai-content-generator")
        );
      } else if (entry.savedOnly) {
        metaParts.push(translate("saved", "gpt3-ai-content-generator"));
      }
      meta.textContent = metaParts.join(" · ");
      content.appendChild(meta);
    }
    item.appendChild(content);

    if (showProvider && !compact) {
      const providerBadge = document.createElement("span");
      providerBadge.className = "aipkit_unified_model_badge is-provider";
      providerBadge.textContent = getProviderLabel(
        entry.provider,
        entry.providerLabel || entry.provider
      );
      item.appendChild(providerBadge);
    }

    if (isSelected) {
      const check = document.createElement("span");
      check.className = "aipkit_unified_model_check";
      check.setAttribute("aria-hidden", "true");
      check.textContent = "✓";
      item.appendChild(check);
    }

    if ((credits || entry.recommended) && !compact) {
      const badge = document.createElement("span");
      badge.className = "aipkit_unified_model_badge is-recommended";
      badge.textContent =
        credits || translate("recommended", "gpt3-ai-content-generator");
      item.appendChild(badge);
    }
    if (entry.savedOnly && !compact) {
      const badge = document.createElement("span");
      badge.className = "aipkit_unified_model_badge is-saved";
      badge.textContent = translate("saved", "gpt3-ai-content-generator");
      item.appendChild(badge);
    }

    row.appendChild(item);
    if (showFavorite) {
      const favoriteButton = document.createElement("button");
      favoriteButton.type = "button";
      favoriteButton.className = "aipkit_unified_model_favorite";
      favoriteButton.dataset.provider = String(entry.provider || "");
      favoriteButton.dataset.model = String(entry.modelValue || "");
      const isFavorite = isFavoriteModel(entry);
      favoriteButton.classList.toggle("is-favorite", isFavorite);
      favoriteButton.setAttribute(
        "aria-pressed",
        isFavorite ? "true" : "false"
      );
      const favoriteLabel = isFavorite
        ? translate("Remove from favorites", "gpt3-ai-content-generator")
        : translate("Add to favorites", "gpt3-ai-content-generator");
      favoriteButton.setAttribute(
        "aria-label",
        `${favoriteLabel}: ${entry.modelLabel || entry.modelValue || ""}`
      );
      favoriteButton.title = favoriteLabel;
      const favoriteIcon = document.createElement("span");
      favoriteIcon.className = `dashicons ${
        isFavorite ? "dashicons-star-filled" : "dashicons-star-empty"
      }`;
      favoriteIcon.setAttribute("aria-hidden", "true");
      favoriteButton.appendChild(favoriteIcon);
      row.appendChild(favoriteButton);
    }
    return row;
  };

  const getNoticeContent = (record) => {
    const status = record?.state?.status || "ready";
    if (syncingProviders.has(normalizeProviderKey(record?.provider))) {
      return {
        tone: "syncing",
        text: translate("Syncing models…", "gpt3-ai-content-generator"),
        action: "",
        actionLabel: "",
      };
    }
    if (status === "not_configured") {
      if (normalizeProviderKey(record?.provider) === "aipuffercloud") {
        return {
          tone: "setup",
          text: translate("Connect AI Puffer Cloud to load available models. No API key needed.", "gpt3-ai-content-generator"),
          action: "connect_cloud",
          actionLabel: translate("Connect", "gpt3-ai-content-generator"),
        };
      }
      return {
        tone: "setup",
        text: translate(
          "Add an API key to use this provider.",
          "gpt3-ai-content-generator"
        ),
        action: "manage",
        actionLabel: translate("Configure", "gpt3-ai-content-generator"),
      };
    }
    if (status === "configured_unsynced") {
      return {
        tone: "setup",
        text: translate(
          "Your connection is saved. Sync to load the models available to this account.",
          "gpt3-ai-content-generator"
        ),
        action: "sync",
        actionLabel: translate("Sync models", "gpt3-ai-content-generator"),
      };
    }
    if (status === "stale") {
      return {
        tone: "warning",
        text: translate(
          "Showing the last successful model list. Refresh it when convenient.",
          "gpt3-ai-content-generator"
        ),
        action: "sync",
        actionLabel: translate("Refresh", "gpt3-ai-content-generator"),
      };
    }
    if (status === "error") {
      return {
        tone: "error",
        text:
          record.state.error_message ||
          translate(
            "The last sync failed. Your previous or bootstrapped list remains available.",
            "gpt3-ai-content-generator"
          ),
        action: "sync",
        actionLabel: translate("Retry", "gpt3-ai-content-generator"),
      };
    }
    if (status === "locked") {
      return {
        tone: "locked",
        text: translate(
          "This provider is available with AI Puffer Pro.",
          "gpt3-ai-content-generator"
        ),
        action: "manage",
        actionLabel: translate("View provider", "gpt3-ai-content-generator"),
      };
    }
    return null;
  };

  const ensureModernMarkup = (selector) => {
    const popover = selector?.querySelector(
      "[data-aipkit-unified-model-popover]"
    );
    if (!popover) {
      return;
    }
    const providerDiagnosticsEnabled =
      selector.dataset.aipkitProviderDiagnostics !== "0";
    const showManageLink = selector.dataset.aipkitShowManageLink !== "0";

    if (!popover.querySelector("[data-aipkit-unified-model-providers]")) {
      popover.setAttribute("role", "dialog");
      popover.setAttribute(
        "aria-label",
        translate("Choose an AI model", "gpt3-ai-content-generator")
      );
      const search = popover.querySelector(".aipkit_unified_model_search");
      const searchBar = document.createElement("div");
      searchBar.className = "aipkit_unified_model_search_bar";
      if (search) {
        popover.insertBefore(searchBar, search);
        searchBar.appendChild(search);
        if (!search.querySelector("kbd")) {
          const shortcut = document.createElement("kbd");
          shortcut.setAttribute("aria-hidden", "true");
          shortcut.textContent = "⌘K";
          search.appendChild(shortcut);
        }
      } else {
        popover.prepend(searchBar);
      }

      const body = document.createElement("div");
      body.className = "aipkit_unified_model_body";
      const providers = document.createElement("div");
      providers.className = "aipkit_unified_model_providers";
      providers.dataset.aipkitUnifiedModelProviders = "";
      providers.setAttribute("role", "listbox");
      providers.setAttribute(
        "aria-label",
        translate("AI providers", "gpt3-ai-content-generator")
      );
      const results = document.createElement("div");
      results.className = "aipkit_unified_model_results";

      if (providerDiagnosticsEnabled) {
        const notice = document.createElement("div");
        notice.className = "aipkit_unified_model_provider_notice";
        notice.dataset.aipkitUnifiedModelProviderNotice = "";
        notice.setAttribute("role", "status");
        notice.setAttribute("aria-live", "polite");
        notice.hidden = true;
        const noticeIcon = document.createElement("span");
        noticeIcon.className = "aipkit_unified_model_provider_notice_icon";
        noticeIcon.setAttribute("aria-hidden", "true");
        const noticeText = document.createElement("span");
        noticeText.className = "aipkit_unified_model_provider_notice_text";
        noticeText.dataset.aipkitUnifiedModelProviderNoticeText = "";
        const noticeAction = document.createElement("button");
        noticeAction.type = "button";
        noticeAction.className = "aipkit_unified_model_provider_notice_action";
        noticeAction.dataset.aipkitUnifiedModelProviderAction = "";
        noticeAction.hidden = true;
        notice.append(noticeIcon, noticeText, noticeAction);
        results.appendChild(notice);
      }

      [
        popover.querySelector("[data-aipkit-unified-model-filters]"),
        popover.querySelector("[data-aipkit-unified-model-list]"),
        popover.querySelector("[data-aipkit-unified-model-empty]"),
      ].forEach((element) => {
        if (element) {
          results.appendChild(element);
        }
      });
      body.append(providers, results);
      popover.appendChild(body);

      const footer = document.createElement("div");
      footer.className = "aipkit_unified_model_footer";
      const keyboardHint = document.createElement("span");
      keyboardHint.className = "aipkit_unified_model_keyboard_hint";
      keyboardHint.setAttribute("aria-hidden", "true");
      keyboardHint.textContent = `↑↓ ${translate(
        "navigate",
        "gpt3-ai-content-generator"
      )}   ↵ ${translate("select", "gpt3-ai-content-generator")}`;
      footer.appendChild(keyboardHint);
      if (showManageLink) {
        const manageLink = document.createElement("a");
        manageLink.className = "aipkit_unified_model_manage_link";
        const settingsUrl = new URL("admin.php", window.location.href);
        settingsUrl.search = "";
        settingsUrl.searchParams.set("page", "wpaicg");
        settingsUrl.searchParams.set("aipkit_module", "settings");
        settingsUrl.searchParams.set("aipkit_settings_page", "ai");
        manageLink.href = settingsUrl.toString();
        manageLink.dataset.aipkitOpenModule = "settings";
        manageLink.dataset.aipkitSettingsPage = "ai";
        manageLink.textContent = `${translate(
          "Manage providers",
          "gpt3-ai-content-generator"
        )} ↗`;
        footer.appendChild(manageLink);
      }
      popover.appendChild(footer);
    }

    let panel = popover.querySelector(":scope > [data-aipkit-unified-model-panel]");
    if (!panel) {
      panel = document.createElement("div");
      panel.className = "aipkit_unified_model_panel";
      panel.dataset.aipkitUnifiedModelPanel = "";
      while (popover.firstChild) {
        panel.appendChild(popover.firstChild);
      }
      popover.appendChild(panel);
    }
    if (!panel.querySelector(".aipkit_unified_model_sheet_handle")) {
      const handle = document.createElement("span");
      handle.className = "aipkit_unified_model_sheet_handle";
      handle.setAttribute("aria-hidden", "true");
      panel.prepend(handle);
    }
    const footer = panel.querySelector(".aipkit_unified_model_footer");
    if (footer && !footer.querySelector("[data-aipkit-unified-model-summary]")) {
      const summary = document.createElement("span");
      summary.className = "aipkit_unified_model_summary";
      summary.dataset.aipkitUnifiedModelSummary = "";
      summary.setAttribute("aria-live", "polite");
      footer.insertBefore(
        summary,
        footer.querySelector(".aipkit_unified_model_manage_link")
      );
    }
    if (!providerDiagnosticsEnabled) {
      selector
        .querySelectorAll("[data-aipkit-unified-model-provider-notice]")
        .forEach((element) => element.remove());
    }
    if (!showManageLink) {
      selector
        .querySelectorAll(".aipkit_unified_model_manage_link")
        .forEach((element) => element.remove());
    }
  };

  function createUnifiedModelSelector(selector, adapter) {
    if (!selector || !adapter) {
      return null;
    }
    if (selector._aipkitUnifiedModelController) {
      selector._aipkitUnifiedModelController.setAdapter(adapter);
      selector._aipkitUnifiedModelController.sync();
      return selector._aipkitUnifiedModelController;
    }

    ensureModernMarkup(selector);

    const trigger = selector.querySelector(
      "[data-aipkit-unified-model-trigger]"
    );
    const popover = selector.querySelector(
      "[data-aipkit-unified-model-popover]"
    );
    const panel = popover?.querySelector(
      "[data-aipkit-unified-model-panel]"
    );
    const modelBody = popover?.querySelector(".aipkit_unified_model_body");
    const search = selector.querySelector(
      "[data-aipkit-unified-model-search]"
    );
    const providersList = selector.querySelector(
      "[data-aipkit-unified-model-providers]"
    );
    const list = selector.querySelector("[data-aipkit-unified-model-list]");
    const empty = selector.querySelector("[data-aipkit-unified-model-empty]");
    const defaultEmptyText =
      String(empty?.textContent || "").trim() ||
      translate("No models found", "gpt3-ai-content-generator");
    list?.setAttribute("role", "list");
    const name = selector.querySelector("[data-aipkit-unified-model-name]");
    const logo = selector.querySelector("[data-aipkit-unified-model-logo]");
    const notice = selector.querySelector(
      "[data-aipkit-unified-model-provider-notice]"
    );
    const noticeText = selector.querySelector(
      "[data-aipkit-unified-model-provider-notice-text]"
    );
    const noticeAction = selector.querySelector(
      "[data-aipkit-unified-model-provider-action]"
    );
    const manageLink = selector.querySelector(
      ".aipkit_unified_model_manage_link"
    );
    const providerName = selector.querySelector(
      "[data-aipkit-unified-model-provider]"
    );
    const syncButton = selector.querySelector(
      "[data-aipkit-unified-model-sync]"
    );
    const syncLabel = syncButton?.querySelector(
      "[data-aipkit-unified-model-sync-label]"
    );
    const summary = popover?.querySelector(
      "[data-aipkit-unified-model-summary]"
    );
    const filtersContainer = selector.querySelector(
      "[data-aipkit-unified-model-filters]"
    );
    const filterButtons = Array.from(
      selector.querySelectorAll("[data-aipkit-unified-model-filter]")
    );
    const modelCapability = String(
      selector.dataset.aipkitModelCapability || ""
    ).trim();
    const providerDiagnosticsEnabled =
      selector.dataset.aipkitProviderDiagnostics !== "0";
    let activeFilter =
      filterButtons.find(
        (button) => button.getAttribute("aria-pressed") === "true"
      )?.dataset.aipkitUnifiedModelFilter || "all";
    let activeProvider = "";
    let compactPanel = false;
    let searchHadValue = false;
    let providerBeforeSearch = "";
    let currentAdapter = adapter;
    const familyCollapseOverrides = new Map();
    const popoverAnchor = document.createComment(
      "aipkit-unified-model-popover-anchor"
    );
    popover?.parentNode?.insertBefore(popoverAnchor, popover);

    const mountPopover = () => {
      if (!popover || popover.parentNode === document.body) {
        return;
      }
      popover.classList.add("is-portaled");
      document.body.appendChild(popover);
    };

    const restorePopover = () => {
      if (!popover) {
        return;
      }
      [
        "position",
        "top",
        "right",
        "bottom",
        "left",
        "width",
        "height",
        "max-height",
        "transform",
      ].forEach((property) => popover.style.removeProperty(property));
      ["height", "min-height"].forEach((property) =>
        modelBody?.style.removeProperty(property)
      );
      popover.classList.remove(
        "is-portaled",
        "is-compact",
        "is-bottom-sheet",
        "opens-up"
      );
      popover.removeAttribute("aria-modal");
      compactPanel = false;
      if (popoverAnchor.parentNode) {
        popoverAnchor.parentNode.insertBefore(popover, popoverAnchor.nextSibling);
      } else {
        popover.remove();
      }
    };

    const getSelection = () => ({
      provider: "",
      modelValue: "",
      modelLabel: translate("Select model", "gpt3-ai-content-generator"),
      category: "",
      ...(currentAdapter.getSelection?.() || {}),
    });

    const getViewData = () => {
      const selection = getSelection();
      const entries = collectEntries(currentAdapter);
      const providers = collectProviders(
        currentAdapter,
        entries,
        selection,
        modelCapability,
        providerDiagnosticsEnabled
      );
      const modelFavoritesEnabled =
        selector.dataset.aipkitShowModelFavorites !== "0";
      if (!modelFavoritesEnabled && activeProvider === favoritesProviderKey) {
        activeProvider = selection.provider || providers[0]?.provider || "";
      }
      if (
        activeProvider !== allProviderKey &&
        activeProvider !== favoritesProviderKey &&
        (!activeProvider ||
          !providers.some((record) =>
            sameProvider(record.provider, activeProvider)
          ))
      ) {
        activeProvider =
          providers.find((record) => sameProvider(record.provider, selection.provider))
            ?.provider ||
          providers.find((record) => record.state.status === "ready")?.provider ||
          providers[0]?.provider ||
          "";
      }
      return { selection, entries, providers };
    };

    const setActiveFilter = (nextFilter, shouldRender = true) => {
      if (!filterButtons.length) {
        return;
      }
      const normalizedFilter = String(nextFilter || "all");
      activeFilter = filterButtons.some(
        (button) =>
          button.dataset.aipkitUnifiedModelFilter === normalizedFilter
      )
        ? normalizedFilter
        : "all";
      filterButtons.forEach((button) => {
        const isActive =
          button.dataset.aipkitUnifiedModelFilter === activeFilter;
        button.classList.toggle("is-active", isActive);
        button.setAttribute("aria-pressed", isActive ? "true" : "false");
      });
      if (shouldRender) {
        render();
        if (list) {
          list.scrollTop = 0;
        }
      }
    };

    const renderNotice = (record) => {
      if (!providerDiagnosticsEnabled) {
        return;
      }
      const content = getNoticeContent(record);
      if (!notice || !noticeText || !noticeAction) {
        return;
      }
      notice.hidden = !content;
      notice.className = "aipkit_unified_model_provider_notice";
      if (!content) {
        noticeText.textContent = "";
        noticeAction.hidden = true;
        return;
      }
      notice.classList.add(`is-${content.tone}`);
      noticeText.textContent = content.text;
      noticeAction.hidden = !content.action;
      noticeAction.dataset.action = content.action;
      noticeAction.dataset.provider = record.provider;
      noticeAction.textContent = content.actionLabel;
      noticeAction.disabled = content.tone === "syncing";
    };

    // Footer sync: the provider being browsed, or the chosen model's provider in All and Favorites.
    // A provider whose notice already offers a sync keeps that one action.
    const renderSyncButton = (record, noticeShown = false) => {
      if (!syncButton) {
        return;
      }
      const key = normalizeProviderKey(record?.provider);
      const status = record?.state?.status || "";
      const syncing = Boolean(record) && syncingProviders.has(key);
      const syncable =
        providerDiagnosticsEnabled &&
        Boolean(record) &&
        syncableProviders.has(key) &&
        (status === "ready" ||
          (!noticeShown &&
            ["stale", "error", "configured_unsynced"].includes(status)));
      syncButton.hidden = !syncing && !syncable;
      syncButton.dataset.provider = record?.provider || "";
      syncButton.setAttribute("aria-disabled", syncing ? "true" : "false");
      syncButton.title = record
        ? translate("Sync %s models", "gpt3-ai-content-generator").replace(
            "%s",
            record.providerLabel || getProviderLabel(record.provider)
          )
        : "";
      if (syncLabel) {
        syncLabel.textContent = syncing
          ? translate("Syncing…", "gpt3-ai-content-generator")
          : translate("Sync models", "gpt3-ai-content-generator");
      }
    };

    const render = () => {
      if (!list || !providersList) {
        return;
      }
      const providerScrollTop = providersList.scrollTop;
      const providerScrollLeft = providersList.scrollLeft;
      const { selection, entries, providers } = getViewData();
      const modelFavoritesEnabled =
        selector.dataset.aipkitShowModelFavorites !== "0";
      const searchTerm = String(search?.value || "").trim().toLowerCase();
      const selectionExists = entries.some(
        (entry) =>
          sameProvider(entry.provider, selection.provider) &&
          String(entry.modelValue || "") === String(selection.modelValue || "")
      );
      const favoriteSelectionIsMissing = Boolean(
        !selectionExists &&
        providerIsAvailable(selection.provider) &&
        selection.provider &&
        selection.modelValue &&
        isFavoriteModel(selection) &&
        entryMatches(selection, searchTerm, activeFilter)
      );
      const favoriteCount =
        entries.filter(
          (entry) =>
            isFavoriteModel(entry) &&
            entryMatches(entry, searchTerm, activeFilter)
        ).length + (favoriteSelectionIsMissing ? 1 : 0);

      const providerFragment = document.createDocumentFragment();
      providerFragment.appendChild(
        createProviderButton(
          {
            provider: allProviderKey,
            providerLabel: translate("All", "gpt3-ai-content-generator"),
            state: { status: "all", locked: false },
          },
          activeProvider,
          entries.filter((entry) =>
            entryMatches(entry, searchTerm, activeFilter)
          ).length
        )
      );
      if (modelFavoritesEnabled) {
        providerFragment.appendChild(
          createProviderButton(
            {
              provider: favoritesProviderKey,
              providerLabel: translate(
                "Favorites",
                "gpt3-ai-content-generator"
              ),
              state: { status: "favorites", locked: false },
            },
            activeProvider,
            favoriteCount
          )
        );
      }
      providers.forEach((record) => {
        const count = record.entries.filter((entry) =>
          entryMatches(entry, searchTerm, activeFilter)
        ).length;
        providerFragment.appendChild(
          createProviderButton(record, activeProvider, count)
        );
      });
      providersList.replaceChildren(providerFragment);
      providersList.scrollTop = providerScrollTop;
      providersList.scrollLeft = providerScrollLeft;

      const activeRecord =
        providers.find((record) => sameProvider(record.provider, activeProvider)) ||
        null;
      const isAllView = activeProvider === allProviderKey;
      const isFavoritesView = activeProvider === favoritesProviderKey;
      const activeProAccess =
        !isAllView && !isFavoritesView ? activeRecord?.proAccess || null : null;
      if (filtersContainer) {
        filtersContainer.hidden = Boolean(activeProAccess);
      }
      if (manageLink) {
        manageLink.hidden = Boolean(activeProAccess);
      }
      if (activeProAccess) {
        list.setAttribute("role", "region");
        list.setAttribute(
          "aria-label",
          translate("Ollama Pro upgrade", "gpt3-ai-content-generator")
        );
        list.replaceChildren(createProUpgradeView(activeRecord));
        if (empty) {
          empty.hidden = true;
        }
        if (summary) {
          summary.textContent = `${providers.length} ${translate(
            "providers",
            "gpt3-ai-content-generator"
          )} · ${entries.length} ${translate(
            "models",
            "gpt3-ai-content-generator"
          )}`;
        }
        renderNotice(null);
        renderSyncButton(null);
        return;
      }
      list.setAttribute("role", "list");
      list.removeAttribute("aria-label");
      let activeEntries = isAllView
        ? searchTerm
          ? entries.filter((entry) =>
              entryMatches(entry, searchTerm, activeFilter)
            )
          : collectCuratedAllEntries(entries, selection).filter((entry) =>
              entryMatches(entry, "", activeFilter)
            )
        : entries
            .filter((entry) =>
              isFavoritesView
                ? isFavoriteModel(entry)
                : sameProvider(entry.provider, activeProvider)
            )
            .filter((entry) => entryMatches(entry, searchTerm, activeFilter));

      if (
        !selectionExists &&
        providerIsAvailable(selection.provider) &&
        selection.provider &&
        selection.modelValue &&
        (isAllView ||
          (isFavoritesView
          ? isFavoriteModel(selection)
          : sameProvider(selection.provider, activeProvider))) &&
        entryMatches(selection, searchTerm, activeFilter)
      ) {
        activeEntries.unshift({
          ...selection,
          providerLabel: getProviderLabel(selection.provider),
          savedOnly: true,
        });
      }

      // AI Puffer models run cheapest first (typical credits per reply); other models have no credits.
      const creditsPerReply = (entry) =>
        getLocalizedModelFamily(entry.provider, entry.modelValue)
          ?.creditsPerReply || Infinity;
      activeEntries.sort(
        (first, second) =>
          Number(Boolean(second.recommended)) -
            Number(Boolean(first.recommended)) ||
          creditsPerReply(first) - creditsPerReply(second) ||
          String(first.modelLabel || first.modelValue).localeCompare(
            String(second.modelLabel || second.modelValue),
            undefined,
            { numeric: true, sensitivity: "base" }
          )
      );
      const fragment = document.createDocumentFragment();
      const appendEntries = (items, target = fragment) => items.forEach((entry) => {
        const providerState =
          providers.find((record) => sameProvider(record.provider, entry.provider))
            ?.state ||
          (providerDiagnosticsEnabled
            ? getProviderState(entry.provider)
            : getNeutralProviderState(entry.provider));
        target.appendChild(
          createModelItem(entry, selection, providerState, {
            showProvider: compactPanel || isAllView || isFavoritesView,
            compact: compactPanel,
            showFavorite: modelFavoritesEnabled,
          })
        );
      });
      const familyGroups = new Map();
      if (!isAllView && !isFavoritesView) {
        activeEntries.forEach((entry) => {
          const familyKey = String(entry.familyKey || "other");
          if (!familyGroups.has(familyKey)) {
            familyGroups.set(familyKey, {
              key: familyKey,
              label:
                entry.familyLabel ||
                translate("Other", "gpt3-ai-content-generator"),
              order: Number(entry.familyOrder ?? 999),
              collapsed: Boolean(entry.familyCollapsed),
              entries: [],
            });
          }
          familyGroups.get(familyKey).entries.push(entry);
        });
      }

      if (familyGroups.size > 1) {
        Array.from(familyGroups.values())
          .sort(
            (first, second) =>
              first.order - second.order ||
              String(first.label).localeCompare(String(second.label))
          )
          .forEach((family) => {
            const collapseKey = `${normalizeProviderKey(activeProvider)}::${family.key}`;
            const containsSelection = family.entries.some(
              (entry) =>
                sameProvider(entry.provider, selection.provider) &&
                String(entry.modelValue || "") ===
                  String(selection.modelValue || "")
            );
            let isCollapsed = familyCollapseOverrides.has(collapseKey)
              ? familyCollapseOverrides.get(collapseKey)
              : family.collapsed;
            if (
              searchTerm ||
              (containsSelection && !familyCollapseOverrides.has(collapseKey))
            ) {
              isCollapsed = false;
            }

            const section = document.createElement("section");
            section.className = "aipkit_unified_model_family";
            section.classList.toggle("is-collapsed", isCollapsed);
            const header = document.createElement("button");
            header.type = "button";
            header.className = "aipkit_unified_model_family_header";
            header.setAttribute("aria-expanded", isCollapsed ? "false" : "true");
            header.setAttribute(
              "aria-label",
              `${family.label}, ${family.entries.length} ${translate(
                "models",
                "gpt3-ai-content-generator"
              )}`
            );
            const label = document.createElement("span");
            label.className = "aipkit_unified_model_family_label";
            label.textContent = family.label;
            const count = document.createElement("span");
            count.className = "aipkit_unified_model_family_count";
            count.textContent = String(family.entries.length);
            const chevron = document.createElement("span");
            chevron.className =
              "aipkit_unified_model_family_chevron dashicons dashicons-arrow-down-alt2";
            chevron.setAttribute("aria-hidden", "true");
            header.append(label, count, chevron);

            const body = document.createElement("div");
            body.className = "aipkit_unified_model_family_models";
            body.hidden = isCollapsed;
            appendEntries(family.entries, body);
            header.addEventListener("click", () => {
              const scrollTop = list?.scrollTop || 0;
              familyCollapseOverrides.set(collapseKey, !isCollapsed);
              render();
              if (list) {
                list.scrollTop = scrollTop;
              }
            });
            section.append(header, body);
            fragment.appendChild(section);
          });
      } else {
        appendEntries(activeEntries);
      }
      list.replaceChildren(fragment);
      if (empty) {
        const awaitingCloudConnection = !searchTerm && !isAllView && !isFavoritesView
          && sameProvider(activeProvider, "AIPufferCloud")
          && providers.find(record => sameProvider(record.provider, activeProvider))?.state?.status === "not_configured";
        empty.hidden = activeEntries.length > 0 || awaitingCloudConnection;
        empty.textContent = isFavoritesView
          ? searchTerm
            ? translate(
                "No favorite models match your search.",
                "gpt3-ai-content-generator"
              )
            : translate(
                "No favorites yet. Select a star to add a model here.",
                "gpt3-ai-content-generator"
              )
          : defaultEmptyText;
      }
      if (summary) {
        summary.textContent = `${providers.length} ${translate(
          "providers",
          "gpt3-ai-content-generator"
        )} · ${entries.length} ${translate(
          "models",
          "gpt3-ai-content-generator"
        )}`;
      }
      renderNotice(isAllView || isFavoritesView ? null : activeRecord);
      renderSyncButton(
        isAllView || isFavoritesView
          ? providers.find((record) => sameProvider(record.provider, selection.provider)) || null
          : activeRecord,
        !isAllView && !isFavoritesView
      );
    };

    const positionPopover = () => positionUnifiedPopover({
      selector, trigger, popover, modelBody, providersList, list, mountPopover,
      onLayout: (compact) => {
        compactPanel = compact;
        render();
      },
    });

    const setOpen = (isOpen) => {
      if (!trigger || !popover) {
        return;
      }
      if (isOpen) {
        const activeController = window._aipkitActiveUnifiedModelController;
        if (activeController && activeController !== controller) {
          activeController.close();
        }
        controllers.add(controller);
        controllers.forEach((controller) => {
          if (controller.selector !== selector) {
            controller.close();
          }
        });
        window._aipkitActiveUnifiedModelController = controller;
        mountPopover();
      }
      popover.hidden = !isOpen;
      trigger.setAttribute("aria-expanded", isOpen ? "true" : "false");
      selector.classList.toggle("is-open", isOpen);
      if (isOpen) {
        const selection = getSelection();
        positionPopover();
        activeProvider = compactPanel
          ? allProviderKey
          : selection.provider || activeProvider;
        setActiveFilter(selection.category || activeFilter || "all", false);
        render();
        if (providerDiagnosticsEnabled) {
          const providers = getViewData().providers.map(
            (record) => record.provider
          );
          void refreshCatalogState(providers).then(() => {
            if (!popover.hidden) {
              render();
            }
          });
        }
        window.requestAnimationFrame(() => {
          positionPopover();
          search?.focus();
          search?.select();
          list
            ?.querySelector(".aipkit_unified_model_item.is-selected")
            ?.scrollIntoView({ block: "nearest" });
        });
      } else {
        if (search) {
          search.value = "";
        }
        searchHadValue = false;
        providerBeforeSearch = "";
        restorePopover();
        if (window._aipkitActiveUnifiedModelController === controller) {
          delete window._aipkitActiveUnifiedModelController;
        }
      }
    };

    const sync = () => {
      const selection = getSelection();
      if (!selector.classList.contains("is-open") && selection.provider) {
        activeProvider = selection.provider;
      }
      const label =
        !providerIsAvailable(selection.provider)
          ? window.aipkit_dashboard?.cloudConnected
            ? translate("AI Puffer models unavailable", "gpt3-ai-content-generator")
            : translate("AI Puffer Cloud disconnected", "gpt3-ai-content-generator")
          : selection.modelLabel ||
            selection.modelValue ||
            translate("Select model", "gpt3-ai-content-generator");
      if (name) {
        name.textContent = label;
        name.title = label;
      }
      if (providerName) {
        providerName.textContent = selection.provider
          ? getProviderLabel(selection.logoProvider || selection.provider)
          : "";
      }
      if (trigger) {
        // Empty admin catalogs must still allow provider setup; busy populated pickers stay disabled.
        const emptySetup = canDiscoverCloud(modelCapability, providerDiagnosticsEnabled)
          && collectEntries(currentAdapter).length === 0;
        trigger.disabled = Boolean(currentAdapter.isDisabled?.()) && !emptySetup;
      }
      setLogo(logo, selection.logoProvider || selection.provider);
      render();
    };

    const runProviderSync = async (provider) => {
      if (!providerDiagnosticsEnabled) {
        return;
      }
      const key = normalizeProviderKey(provider);
      if (!provider || !syncableProviders.has(key) || syncingProviders.has(key)) {
        return;
      }
      const syncHandler = currentAdapter.onSync || window.aipkit_queueProviderSync;
      if (typeof syncHandler !== "function") {
        manageLink?.click();
        return;
      }
      syncingProviders.add(key);
      render();
      try {
        const response = currentAdapter.onSync
          ? await currentAdapter.onSync(provider)
          : await window.aipkit_queueProviderSync(provider, {
              silent: true,
              showErrors: false,
              showSuccess: false,
              showLocalStatus: false,
              propagateError: true,
            });
        if (response?.provider_state) {
          updateProviderStates({ [key]: response.provider_state });
        }
        currentAdapter.onSyncComplete?.(provider, response);
        catalogStateFetchedAt = 0;
      } catch (error) {
        const current = getProviderState(provider);
        updateProviderStates({
          [key]: {
            ...current,
            provider,
            status: current.last_success ? "stale" : "error",
            error_message:
              error?.message ||
              translate("Model sync failed.", "gpt3-ai-content-generator"),
          },
        });
      } finally {
        syncingProviders.delete(key);
        sync();
      }
    };

    trigger?.addEventListener("click", () => {
      setOpen(trigger.getAttribute("aria-expanded") !== "true");
    });
    trigger?.addEventListener("keydown", (event) => {
      if (event.key === "ArrowDown") {
        event.preventDefault();
        setOpen(true);
      }
    });
    const handleSearchInput = () => {
      const hasSearch = Boolean(String(search?.value || "").trim());
      if (hasSearch && !searchHadValue) {
        providerBeforeSearch = activeProvider;
        activeProvider = allProviderKey;
      } else if (!hasSearch && searchHadValue) {
        activeProvider = compactPanel
          ? allProviderKey
          : providerBeforeSearch || getSelection().provider || allProviderKey;
        providerBeforeSearch = "";
      }
      searchHadValue = hasSearch;
      render();
      if (list) {
        list.scrollTop = 0;
      }
    };
    search?.addEventListener("input", handleSearchInput);
    search?.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        event.preventDefault();
        setOpen(false);
        trigger?.focus();
      } else if (event.key === "ArrowDown") {
        event.preventDefault();
        (
          list?.querySelector(".aipkit_unified_model_item:not([disabled])") ||
          list?.querySelector(".aipkit_unified_model_upgrade_cta")
        )?.focus();
      }
    });
    filterButtons.forEach((button) => {
      button.addEventListener("click", () => {
        setActiveFilter(button.dataset.aipkitUnifiedModelFilter || "all");
      });
    });
    providersList?.addEventListener("click", (event) => {
      const target = event.target instanceof Element ? event.target : null;
      const providerButton = target?.closest(".aipkit_unified_model_provider");
      if (!providerButton) {
        return;
      }
      activeProvider = providerButton.dataset.provider || "";
      if (searchHadValue) {
        providerBeforeSearch = activeProvider;
      }
      if (
        selector.dataset.aipkitProviderOnly === "1" &&
        activeProvider !== allProviderKey &&
        activeProvider !== favoritesProviderKey
      ) {
        const matchingEntry = collectEntries(currentAdapter).find((entry) =>
          sameProvider(entry.provider, activeProvider)
        );
        if (matchingEntry) {
          rememberRecentModel(
            matchingEntry.provider,
            matchingEntry.modelValue
          );
          currentAdapter.onSelect?.(
            matchingEntry.provider,
            matchingEntry.modelValue
          );
          sync();
          setOpen(false);
          trigger?.focus();
          return;
        }
      }
      render();
      if (list) {
        list.scrollTop = 0;
      }
      list
        ?.querySelector(
          ".aipkit_unified_model_item:not([disabled]), .aipkit_unified_model_upgrade_cta"
        )
        ?.focus({ preventScroll: true });
    });
    providersList?.addEventListener("keydown", (event) => {
      const target = event.target instanceof Element ? event.target : null;
      const buttons = Array.from(
        providersList.querySelectorAll(".aipkit_unified_model_provider")
      );
      const index = buttons.indexOf(target);
      if (index === -1) {
        return;
      }
      if (event.key === "ArrowDown" || event.key === "ArrowUp") {
        event.preventDefault();
        const direction = event.key === "ArrowDown" ? 1 : -1;
        const next = buttons[(index + direction + buttons.length) % buttons.length];
        next?.focus();
        next?.click();
      } else if (event.key === "ArrowRight") {
        event.preventDefault();
        (
          list?.querySelector(".aipkit_unified_model_item:not([disabled])") ||
          list?.querySelector(".aipkit_unified_model_upgrade_cta")
        )?.focus();
      } else if (event.key === "Escape") {
        event.preventDefault();
        setOpen(false);
        trigger?.focus();
      }
    });
    list?.addEventListener("click", (event) => {
      const target = event.target instanceof Element ? event.target : null;
      const favoriteButton = target?.closest(
        ".aipkit_unified_model_favorite"
      );
      if (favoriteButton) {
        const provider = favoriteButton.dataset.provider || "";
        const modelValue = favoriteButton.dataset.model || "";
        toggleFavoriteModel(provider, modelValue);
        window.requestAnimationFrame(() => {
          const matchingButton = Array.from(
            list.querySelectorAll(".aipkit_unified_model_favorite")
          ).find(
            (button) =>
              sameProvider(button.dataset.provider, provider) &&
              String(button.dataset.model || "") === String(modelValue)
          );
          (
            matchingButton ||
            list.querySelector(".aipkit_unified_model_favorite") ||
            providersList.querySelector(
              ".aipkit_unified_model_provider.is-active"
            )
          )?.focus();
        });
        return;
      }
      if (target?.closest(".aipkit_unified_model_upgrade_cta")) {
        setOpen(false);
        return;
      }
      const item = target?.closest(".aipkit_unified_model_item");
      if (!item || item.disabled) {
        return;
      }
      rememberRecentModel(item.dataset.provider, item.dataset.model);
      currentAdapter.onSelect?.(item.dataset.provider, item.dataset.model);
      sync();
      setOpen(false);
      trigger?.focus();
    });
    list?.addEventListener("keydown", (event) => {
      const target = event.target instanceof Element ? event.target : null;
      if (
        target?.matches(".aipkit_unified_model_upgrade_cta") &&
        event.key === "ArrowLeft"
      ) {
        event.preventDefault();
        providersList
          ?.querySelector(".aipkit_unified_model_provider.is-active")
          ?.focus();
        return;
      }
      const items = Array.from(
        list.querySelectorAll(".aipkit_unified_model_item:not([disabled])")
      );
      const index = items.indexOf(target);
      if (index === -1) {
        return;
      }
      if (event.key === "ArrowDown" || event.key === "ArrowUp") {
        event.preventDefault();
        const direction = event.key === "ArrowDown" ? 1 : -1;
        items[(index + direction + items.length) % items.length]?.focus();
      } else if (event.key === "ArrowLeft") {
        event.preventDefault();
        providersList
          ?.querySelector(".aipkit_unified_model_provider.is-active")
          ?.focus();
      } else if (event.key === "Escape") {
        event.preventDefault();
        setOpen(false);
        trigger?.focus();
      }
    });
    manageLink?.addEventListener("click", () => {
      setOpen(false);
    });
    syncButton?.addEventListener("click", () => {
      if (syncButton.getAttribute("aria-disabled") !== "true") {
        void runProviderSync(syncButton.dataset.provider || "");
      }
    });
    noticeAction?.addEventListener("click", () => {
      const action = noticeAction.dataset.action || "";
      const provider = noticeAction.dataset.provider || activeProvider;
      if (action === "manage" || action === "connect_cloud") {
        if (typeof window.aipkit_openProviderConnection === "function") {
          setOpen(false);
          window.aipkit_openProviderConnection(trigger, controller,
            action === "connect_cloud" ? { initialProvider: "AIPufferCloud" } : {});
        } else {
          manageLink?.click();
        }
      } else if (action === "sync") {
        void runProviderSync(provider);
      }
    });

    panel?.addEventListener(
      "keydown",
      (event) => {
        if (event.key === "Escape") {
          event.preventDefault();
          event.stopPropagation();
          setOpen(false);
          trigger?.focus();
          return;
        }

        if (event.key === "Tab") {
          const providerTarget = providersList?.querySelector(
            ".aipkit_unified_model_provider.is-active"
          );
          const modelTarget =
            list?.querySelector(
              ".aipkit_unified_model_item[aria-current='true']:not([disabled])"
            ) ||
            list?.querySelector(".aipkit_unified_model_item:not([disabled])");
          const upgradeTarget = list?.querySelector(
            ".aipkit_unified_model_upgrade_cta"
          );
          const focusGroups = [
            search,
            providerTarget,
            modelTarget || upgradeTarget || notice?.querySelector("button"),
            manageLink,
          ].filter((element) => element && !element.hidden);
          if (!focusGroups.length) {
            return;
          }
          event.preventDefault();
          event.stopPropagation();
          const activeElement = document.activeElement;
          let currentIndex = focusGroups.findIndex((element) => {
            if (element === activeElement) {
              return true;
            }
            if (element === providerTarget) {
              return providersList?.contains(activeElement);
            }
            if (element === modelTarget) {
              return list?.contains(activeElement);
            }
            return false;
          });
          if (currentIndex < 0) {
            currentIndex = event.shiftKey ? 0 : -1;
          }
          const direction = event.shiftKey ? -1 : 1;
          const nextIndex =
            (currentIndex + direction + focusGroups.length) %
            focusGroups.length;
          focusGroups[nextIndex]?.focus();
          return;
        }

        const target = event.target instanceof Element ? event.target : null;
        const isTextControl = Boolean(
          target?.matches("input, textarea, select, [contenteditable='true']")
        );
        if (
          !isTextControl &&
          event.key.length === 1 &&
          event.key !== " " &&
          !event.metaKey &&
          !event.ctrlKey &&
          !event.altKey
        ) {
          event.preventDefault();
          search?.focus();
          if (search) {
            search.value += event.key;
            handleSearchInput();
          }
        }
      },
      true
    );

    const controller = {
      selector,
      popover,
      close: () => setOpen(false),
      open: () => setOpen(true),
      position: positionPopover,
      getProviders: () => getViewData().providers,
      useConnectedProvider(provider, defaultModel = '') {
        if (!selector.isConnected) return;
        const entries = collectEntries(currentAdapter).filter((entry) => sameProvider(entry.provider, provider) && !entry.disabled);
        const selection = currentAdapter.getSelection?.() || {};
        const preferred = sameProvider(selection.provider, provider) ? selection.modelValue : '';
        const entry = entries.find((item) => item.modelValue === preferred)
          || entries.find((item) => item.modelValue === defaultModel);
        if (entry) {
          currentAdapter.onSelect?.(entry.provider, entry.modelValue);
          sync();
          return;
        }
        setOpen(true);
        activeProvider = provider;
        if (search) search.value = '';
        setActiveFilter('all', false);
        render();
        positionPopover();
      },
      render,
      destroy() {
        setOpen(false);
        popoverAnchor.remove();
        controllers.delete(controller);
        delete selector._aipkitUnifiedModelController;
      },
      setAdapter(nextAdapter) {
        if (nextAdapter) {
          currentAdapter = nextAdapter;
        }
      },
      sync,
    };
    selector._aipkitUnifiedModelController = controller;
    selector.dataset.aipkitUnifiedModelBound = "1";
    controllers.add(controller);
    sync();
    return controller;
  }

  function createSelectAdapter(select) {
    if (!select) {
      return null;
    }
    const audioPanel = select.closest?.('[data-cloud-audio-models]');
    const audioKind = /^(tts|stt)_[a-z]+_model_id$/.exec(select.name || '')?.[1];
    const audioProvider = audioPanel && audioKind
      ? audioPanel.querySelector(`[name="${audioKind}_provider"]`) : null;
    const audioSelects = audioProvider ? Array.from(audioPanel.querySelectorAll(
      `select[data-aipkit-universal-model-capability="${audioKind}"]`
    )) : [];
    const getOptions = () => (audioProvider ? audioSelects : [select]).flatMap(field => {
      if (audioProvider) annotateNativeSelectOptions(field, field.dataset.aipkitUniversalModelProvider);
      return Array.from(field.options || []);
    });
    const getOptionPickerValue = (option) =>
      String(
        option?.dataset?.aipkitModelPickerValue ??
          option?.dataset?.model ??
          option?.value ??
          ""
      );
    const getOptionPickerProvider = (option) =>
      String(
        option?.dataset?.aipkitPickerProvider ??
          option?.dataset?.provider ??
          ""
      );
    const getOptionPickerProviderLabel = (option) =>
      String(
        option?.dataset?.aipkitPickerProviderLabel ??
          option?.dataset?.providerLabel ??
          getOptionPickerProvider(option)
      );
    const getEntries = () => {
      const recommended = [];
      const groups = new Map();
      const seen = new Set();
      getOptions().forEach((option) => {
        const provider = getOptionPickerProvider(option);
        const modelValue = getOptionPickerValue(option);
        const category = String(option.dataset.category || "");
        const entryKey = `${normalizeProviderKey(provider)}::${modelValue}`;
        if (
          option.disabled ||
          !option.value ||
          !provider ||
          seen.has(entryKey)
        ) {
          return;
        }
        seen.add(entryKey);
        const providerLabel = getProviderLabel(
          provider,
          getOptionPickerProviderLabel(option)
        );
        const parentLabel =
          option.parentElement?.tagName === "OPTGROUP"
            ? option.parentElement.label
            : "";
        const groupLabel = formatGroupLabel(
          option.dataset.groupLabel || parentLabel,
          providerLabel
        );
        const modelLabel = String(option.textContent || modelValue).trim();
        const localizedFamily = getLocalizedModelFamily(provider, modelValue);
        const familyLabel = formatGroupLabel(
          option.dataset.familyLabel || localizedFamily?.familyLabel || "",
          groupLabel
        );
        const familyKey = String(
          option.dataset.familyKey ||
            localizedFamily?.familyKey ||
            normalizeSlug(familyLabel) ||
            "other"
        );
        const entry = {
          provider,
          providerLabel,
          logoProvider: option.dataset.provider || provider,
          modelValue,
          modelLabel,
          setupRequired: option.dataset.setupRequired === "true",
          category,
          familyKey,
          familyLabel,
          familyOrder: Number(
            option.dataset.familyOrder ?? localizedFamily?.familyOrder ?? 999
          ),
          familyCollapsed:
            option.dataset.familyCollapsed === "true" ||
            (option.dataset.familyCollapsed === undefined &&
              Boolean(localizedFamily?.familyCollapsed)),
          searchText: `${providerLabel} ${groupLabel} ${familyLabel} ${modelLabel} ${modelValue}`.toLowerCase(),
        };
        if (
          option.dataset.recommended === "true" ||
          localizedFamily?.recommended ||
          String(parentLabel || "").toLowerCase() === "recommended"
        ) {
          recommended.push(entry);
          return;
        }
        if (!groups.has(groupLabel)) {
          groups.set(groupLabel, []);
        }
        groups.get(groupLabel).push(entry);
      });
      return { recommended, groups };
    };

    return {
      isDisabled: () => Boolean(select.disabled),
      getGroups: getEntries,
      getProviders() {
        const providers = new Map();
        const addProvider = (provider, providerLabel = "") => {
          const key = normalizeProviderKey(provider);
          if (!provider || !key || providers.has(key)) {
            return;
          }
          providers.set(key, {
            provider,
            providerLabel: getProviderLabel(
              provider,
              providerLabel || provider
            ),
          });
        };
        Array.from(select.querySelectorAll("optgroup[data-provider]")).forEach(
          (group) => {
            addProvider(
              String(group.dataset.provider || "").trim(),
              String(group.label || "").trim()
            );
          }
        );
        getOptions().forEach((option) => {
          const provider = getOptionPickerProvider(option).trim();
          addProvider(provider, getOptionPickerProviderLabel(option));
        });
        if (audioProvider) Array.from(audioProvider.options).forEach(option =>
          addProvider(option.value, option.textContent));
        return Array.from(providers.values());
      },
      getSelection() {
        const activeSelect = audioProvider ? audioSelects.find(field =>
          sameProvider(field.dataset.aipkitUniversalModelProvider, audioProvider.value)) : select;
        const option = activeSelect?.selectedOptions?.[0] || null;
        return {
          provider: getOptionPickerProvider(option),
          providerLabel: getOptionPickerProviderLabel(option),
          logoProvider: option?.dataset?.provider || "",
          modelValue: getOptionPickerValue(option),
          category: option?.dataset?.category || "",
          modelLabel:
            String(option?.textContent || "").trim() ||
            translate("Select model", "gpt3-ai-content-generator"),
        };
      },
      onSelect(provider, modelValue) {
        const option = getOptions().find(
          (candidate) =>
            sameProvider(getOptionPickerProvider(candidate), provider) &&
            getOptionPickerValue(candidate) === String(modelValue || "")
        );
        if (!option) {
          return;
        }
        const target = audioProvider ? audioSelects.find(field =>
          sameProvider(field.dataset.aipkitUniversalModelProvider, provider)) : select;
        if (!target) return;
        target.value = option.value;
        if (audioProvider && audioProvider.value !== provider) {
          audioProvider.value = provider;
          audioProvider.dispatchEvent(new Event("change", { bubbles: true }));
        }
        target.dispatchEvent(new Event("change", { bubbles: true }));
      },
    };
  }

  const createNativeSelectorElement = (select, provider) => {
    const selector = document.createElement("div");
    selector.className =
      "aipkit_unified_model_selector aipkit_native_universal_model_selector";
    selector.dataset.aipkitUnifiedModelSelector = "";
    const modelCapability = String(
      select.dataset.aipkitModelCapability ||
        select.dataset.aipkitUniversalModelCapability ||
        ""
    ).trim();
    if (modelCapability) {
      selector.dataset.aipkitModelCapability = modelCapability;
    }
    const triggerId = `${select.id || `aipkit_model_${Date.now()}`}_universal_trigger`;
    const trigger = document.createElement("button");
    trigger.type = "button";
    trigger.id = triggerId;
    trigger.className = "aipkit_unified_model_trigger";
    trigger.dataset.aipkitUnifiedModelTrigger = "";
    trigger.setAttribute("aria-expanded", "false");
    trigger.setAttribute("aria-haspopup", "dialog");
    const popoverId = `${triggerId}_popover`;
    trigger.setAttribute("aria-controls", popoverId);
    const logo = document.createElement("span");
    logo.className = "aipkit_unified_model_logo";
    logo.dataset.aipkitUnifiedModelLogo = "";
    logo.setAttribute("aria-hidden", "true");
    setLogo(logo, provider);
    const name = document.createElement("span");
    name.className = "aipkit_unified_model_name";
    name.dataset.aipkitUnifiedModelName = "";
    name.textContent =
      select.selectedOptions?.[0]?.textContent?.trim() ||
      translate("Select model", "gpt3-ai-content-generator");
    trigger.append(logo, name);

    const popover = document.createElement("div");
    popover.id = popoverId;
    popover.className = "aipkit_unified_model_popover";
    popover.dataset.aipkitUnifiedModelPopover = "";
    popover.hidden = true;
    const search = document.createElement("div");
    search.className = "aipkit_unified_model_search";
    const searchIcon = document.createElement("span");
    searchIcon.className = "dashicons dashicons-search";
    searchIcon.setAttribute("aria-hidden", "true");
    const searchInput = document.createElement("input");
    searchInput.type = "search";
    searchInput.className = "aipkit_unified_model_search_input";
    searchInput.placeholder = translate(
      "Search models…",
      "gpt3-ai-content-generator"
    );
    searchInput.setAttribute("aria-label", searchInput.placeholder);
    searchInput.dataset.aipkitUnifiedModelSearch = "";
    search.append(searchIcon, searchInput);
    const list = document.createElement("div");
    list.className = "aipkit_unified_model_list";
    list.dataset.aipkitUnifiedModelList = "";
    list.setAttribute("role", "list");
    const empty = document.createElement("div");
    empty.className = "aipkit_unified_model_empty";
    empty.dataset.aipkitUnifiedModelEmpty = "";
    empty.textContent = translate("No models found", "gpt3-ai-content-generator");
    empty.hidden = true;
    popover.append(search, list, empty);
    selector.append(trigger, popover);
    return selector;
  };

  const annotateNativeSelectOptions = (select, fixedProvider = "") => {
    Array.from(select.options || []).forEach((option) => {
      if (!option.value) {
        return;
      }
      const valueParts = String(option.value).split("::");
      const provider = fixedProvider || (valueParts.length > 1 ? valueParts.shift() : "");
      const model = fixedProvider ? option.value : valueParts.join("::");
      if (!provider || !model) {
        return;
      }
      const groupLabel =
        option.parentElement?.tagName === "OPTGROUP"
          ? option.parentElement.label
          : "";
      option.dataset.provider = provider;
      option.dataset.providerLabel = getProviderLabel(provider, groupLabel);
      option.dataset.model = model;
    });
  };

  const bindNativeUniversalModelSelect = (select) => {
    if (!select || select.dataset.aipkitNativeUniversalModelBound === "1") {
      return;
    }
    const audioKind = /^(tts|stt)_openai_model_id$/.exec(select.name || '')?.[1];
    const audioPanel = select.closest('[data-cloud-audio-models]');
    const audioProvider = audioPanel && audioKind ? audioPanel.querySelector(`[name="${audioKind}_provider"]`) : null;
    const audioFields = audioProvider ? Array.from(audioPanel.querySelectorAll(
      `select[data-aipkit-universal-model-capability="${audioKind}"]`
    )) : [];
    if (audioPanel && /^(tts|stt)_[a-z]+_model_id$/.test(select.name || '') && !audioProvider) return;
    const declaredProvider = String(
      select.dataset.aipkitUniversalModelProvider || ""
    ).trim();
    const isCombined = select.dataset.aipkitUniversalModelCombined === "1";
    const providerSourceId = String(
      select.dataset.aipkitUniversalModelProviderSource || ""
    ).trim();
    const providerSource = providerSourceId
      ? document.getElementById(providerSourceId)
      : null;
    const getFixedProvider = () =>
      String(providerSource?.value || declaredProvider || "").trim();
    if (!declaredProvider && !isCombined && !providerSource) {
      return;
    }
    annotateNativeSelectOptions(select, getFixedProvider());
    const selectedProvider =
      select.selectedOptions?.[0]?.dataset?.provider || getFixedProvider();
    const provider = selectedProvider || getFixedProvider();
    const selector = createNativeSelectorElement(select, provider);
    select.hidden = true;
    select.setAttribute("aria-hidden", "true");
    select.tabIndex = -1;
    select.insertAdjacentElement("afterend", selector);
    if (audioProvider) {
      const host = audioProvider.parentElement;
      audioProvider.hidden = true;
      host.append(selector);
      host.classList.add('aipkit_audio_settings_field--wide');
      const providerLabel = host.querySelector('label');
      if (providerLabel) {
        providerLabel.textContent = translate('Model', 'gpt3-ai-content-generator');
        providerLabel.htmlFor = selector.querySelector('button').id;
      }
      audioFields.forEach(field => field.parentElement.classList.add('aipkit_audio_model_source'));
    }
    const label = select.id
      ? select.parentElement?.querySelector(`label[for="${CSS.escape(select.id)}"]`)
      : null;
    if (label && !audioProvider) {
      label.htmlFor = selector.querySelector("button")?.id || label.htmlFor;
    }
    const adapter = createSelectAdapter(select);
    if (!adapter) {
      selector.remove();
      return;
    }
    adapter.isDisabled = () => false;
    const baseGetProviders = adapter.getProviders;
    adapter.getProviders = () => {
      const records = baseGetProviders().map((record) => ({
        ...record,
        disabled: select.dataset.aipkitUniversalModelLocked === "1",
      }));
      const currentProvider = getFixedProvider();
      if (
        currentProvider &&
        !records.some((record) => sameProvider(record.provider, currentProvider))
      ) {
        records.push({
          provider: currentProvider,
          providerLabel: getProviderLabel(currentProvider),
          disabled: select.dataset.aipkitUniversalModelLocked === "1",
        });
      }
      return records;
    };
    const controller = createUnifiedModelSelector(selector, adapter);
    if (!controller) {
      selector.remove();
      return;
    }
    const syncSelection = () => controller.sync();
    select._aipkitUnifiedModelSync = syncSelection;
    select.addEventListener("change", syncSelection);
    const handleProviderChange = () => {
      annotateNativeSelectOptions(select, getFixedProvider());
      controller.sync();
    };
    providerSource?.addEventListener("change", handleProviderChange);
    const observer = new MutationObserver(() => {
      annotateNativeSelectOptions(select, getFixedProvider());
      controller.sync();
    });
    const observedFields = audioProvider ? [...audioFields, audioProvider] : [select];
    observedFields.forEach(field => {
      observer.observe(field, { childList: true, subtree: true });
      if (field !== select) field.addEventListener('change', syncSelection);
    });
    select._aipkitNativeUniversalModelObserver = observer;
    select._aipkitNativeUniversalModelCleanup = () => {
      observer.disconnect();
      observedFields.forEach(field => field.removeEventListener('change', syncSelection));
      select.removeEventListener("change", syncSelection);
      providerSource?.removeEventListener("change", handleProviderChange);
      controller.destroy();
      delete select._aipkitUnifiedModelSync;
      delete select._aipkitNativeUniversalModelObserver;
      delete select._aipkitNativeUniversalModelCleanup;
      delete select.dataset.aipkitNativeUniversalModelBound;
    };
    select.dataset.aipkitNativeUniversalModelBound = "1";
  };

  const cleanupNativeUniversalModelSelects = (root) => {
    // Reparenting controls into a sheet is not removal from the document.
    if (!(root instanceof Element) || root.isConnected) {
      return;
    }
    const selects = root.matches(nativeSelectorQuery)
      ? [root]
      : Array.from(root.querySelectorAll(nativeSelectorQuery));
    selects.forEach((select) => select._aipkitNativeUniversalModelCleanup?.());
  };

  const initNativeUniversalModelSelects = (root = document) => {
    const scope = root?.querySelectorAll ? root : document;
    if (
      scope.matches?.(nativeSelectorQuery)
    ) {
      bindNativeUniversalModelSelect(scope);
    }
    scope.querySelectorAll(nativeSelectorQuery).forEach(bindNativeUniversalModelSelect);
  };

  const initNativeSelectorObserver = () => {
    initNativeUniversalModelSelects(document);
    const observerRoot =
      document.getElementById("aipkit_module-container") || document.body;
    if (!observerRoot || observerRoot._aipkitUniversalModelObserver) {
      return;
    }
    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        mutation.removedNodes.forEach(cleanupNativeUniversalModelSelects);
        mutation.addedNodes.forEach((node) => {
          if (node instanceof Element) {
            initNativeUniversalModelSelects(node);
          }
        });
      });
    });
    observer.observe(observerRoot, { childList: true, subtree: true });
    observerRoot._aipkitUniversalModelObserver = observer;
  };

  document.addEventListener("click", (event) => {
    const eventPath =
      typeof event.composedPath === "function" ? event.composedPath() : [];
    controllers.forEach((controller) => {
      if (!document.documentElement.contains(controller.selector)) {
        controller.destroy();
        return;
      }
      const clickedInside =
        eventPath.includes(controller.selector) ||
        eventPath.includes(controller.popover) ||
        controller.selector.contains(event.target) ||
        controller.popover?.contains(event.target);
      if (!clickedInside) {
        controller.close();
      }
    });
  });

  let positionFrame = 0;
  const positionOpenSelectors = (event) => {
    if (
      event?.type === "scroll" &&
      event.target instanceof Element &&
      event.target.closest(".aipkit_unified_model_popover")
    ) {
      return;
    }
    if (positionFrame) {
      return;
    }
    positionFrame = window.requestAnimationFrame(() => {
      positionFrame = 0;
      controllers.forEach((controller) => controller.position());
    });
  };
  window.addEventListener("resize", positionOpenSelectors);
  window.addEventListener("scroll", positionOpenSelectors, true);

  window.addEventListener("storage", (event) => {
    if (
      event.key !== favoritesStorageKey &&
      event.key !== recentModelsStorageKey
    ) {
      return;
    }
    if (event.key === favoritesStorageKey) {
      favoriteModelKeys = readFavoriteModelKeys();
    }
    if (event.key === recentModelsStorageKey) {
      recentModelKeys = readRecentModelKeys();
    }
    controllers.forEach((controller) => controller.render());
  });

  ["aipkit:model-sync-complete", "aipkit:model-registry-state-updated"].forEach(
    (eventName) => {
      window.addEventListener(eventName, () => {
        controllers.forEach((controller) => {
          if (!document.documentElement.contains(controller.selector)) {
            controller.destroy();
            return;
          }
          controller.sync();
        });
      });
    }
  );

  window.aipkit_positionUnifiedPopover = positionUnifiedPopover;
  window.aipkit_createUnifiedModelSelector = createUnifiedModelSelector;
  window.aipkit_createUnifiedModelSelectAdapter = createSelectAdapter;
  window.aipkit_initNativeUniversalModelSelects =
    initNativeUniversalModelSelects;
  window.aipkit_formatUnifiedModelGroupLabel = formatGroupLabel;
  window.aipkit_setUnifiedModelLogo = setLogo;
  window.aipkit_updateModelRegistryStates = updateProviderStates;
  window.aipkit_getModelRegistryProviderState = getProviderState;

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initNativeSelectorObserver, {
      once: true,
    });
  } else {
    initNativeSelectorObserver();
  }
};
