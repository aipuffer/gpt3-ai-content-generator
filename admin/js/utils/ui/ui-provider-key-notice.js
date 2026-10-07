/**
 * Provider API Key Notice
 *
 * Shows a reusable notice when a selected provider is missing required setup.
 */
(function () {
  "use strict";

  const SELECTOR = "select[data-aipkit-provider-notice-target]";
  const LINK_SELECTOR = "a[data-aipkit-provider-action]";
  const noticeOverrides = new Map();
  const dismissedNotices = new Map();
  const dismissalStorageKey = notice => `aipkit_provider_notice_${window.aipkit_dashboard?.currentUserId || 0}_${notice.id}`;
  const readDismissal = notice => {
    try { return window.localStorage.getItem(dismissalStorageKey(notice)) || dismissedNotices.get(notice.id); }
    catch { return dismissedNotices.get(notice.id); }
  };
  const writeDismissal = (notice, value) => {
    if (value) dismissedNotices.set(notice.id, value);
    else dismissedNotices.delete(notice.id);
    try {
      if (value) window.localStorage.setItem(dismissalStorageKey(notice), value);
      else window.localStorage.removeItem(dismissalStorageKey(notice));
    } catch { /* Retain the current-view dismissal without browser storage. */ }
  };
  const notifyVisibility = () => window.dispatchEvent(new CustomEvent('aipkit:provider-notice-visibility'));

  const PROVIDER_STATUS_REQUIREMENTS = {
    aipuffercloud: [],
    openai: ["openai_api_key"],
    openrouter: ["openrouter_api_key"],
    google: ["google_api_key"],
    azure: ["azure_api_key", "azure_endpoint"],
    claude: ["claude_api_key"],
    deepseek: ["deepseek_api_key"],
    xai: ["xai_api_key"],
    ollama: ["ollama_base_url"],
    replicate: ["replicate_api_key"],
    elevenlabs: ["elevenlabs_api_key"],
    pexels: ["pexels_api_key"],
    pixabay: ["pixabay_api_key"],
    pinecone: ["pinecone_api_key"],
    qdrant: ["qdrant_api_key", "qdrant_url"],
    chroma: ["chroma_url"],
  };
  const PROVIDER_DEPENDENT_MODULES = [
    "chatbot",
    "content-writer",
    "autogpt",
    "ai-forms",
    "image-generator",
    "sources",
  ];
  const PROVIDER_ROUTES = {
    aipuffercloud: { page: "ai", card: "AIPufferCloud", kind: "provider" },
    openai: { page: "ai", card: "OpenAI", kind: "provider" },
    openrouter: { page: "ai", card: "OpenRouter", kind: "provider" },
    google: { page: "ai", card: "Google", kind: "provider" },
    azure: { page: "ai", card: "Azure", kind: "provider" },
    claude: { page: "ai", card: "Claude", kind: "provider" },
    deepseek: { page: "ai", card: "DeepSeek", kind: "provider" },
    xai: { page: "ai", card: "xAI", kind: "provider" },
    ollama: { page: "ai", card: "Ollama", kind: "provider" },
    replicate: { page: "integrations", card: "replicate", kind: "integration" },
    pexels: { page: "integrations", card: "pexels", kind: "integration" },
    pixabay: { page: "integrations", card: "pixabay", kind: "integration" },
    pinecone: { page: "integrations", card: "pinecone", kind: "integration" },
    qdrant: { page: "integrations", card: "qdrant", kind: "integration" },
    chroma: { page: "integrations", card: "chroma", kind: "integration" },
    elevenlabs: {
      page: "integrations",
      card: "elevenlabs",
      kind: "integration",
    },
  };

  function getProviderStatusMap() {
    const map =
      window.aipkit_provider_status ||
      (window.aipkit_dashboard && window.aipkit_dashboard.providerStatus);
    return map && typeof map === "object" ? map : {};
  }

  function setProviderStatusMap(nextMap) {
    window.aipkit_provider_status = nextMap;
    if (!window.aipkit_dashboard) {
      window.aipkit_dashboard = {};
    }
    window.aipkit_dashboard.providerStatus = nextMap;
  }

  function applyProviderStatusMap(nextMap, { invalidateCaches = true } = {}) {
    if (!nextMap || typeof nextMap !== "object") {
      return false;
    }

    const previousStatusMap = { ...getProviderStatusMap() };
    const statusMap = { ...previousStatusMap };
    Object.keys(PROVIDER_STATUS_REQUIREMENTS).forEach((providerKey) => {
      if (Object.prototype.hasOwnProperty.call(nextMap, providerKey)) {
        statusMap[providerKey] = Boolean(nextMap[providerKey]);
      }
    });

    setProviderStatusMap(statusMap);
    const changed = Object.keys(PROVIDER_STATUS_REQUIREMENTS).some(
      (providerKey) =>
        Boolean(previousStatusMap[providerKey]) !==
        Boolean(statusMap[providerKey])
    );

    if (
      changed &&
      invalidateCaches &&
      typeof window.aipkit_invalidateModuleCache === "function"
    ) {
      PROVIDER_DEPENDENT_MODULES.forEach((moduleName) => {
        window.aipkit_invalidateModuleCache(moduleName);
      });
    }

    refreshNotices(document);
    if (changed) {
      window.dispatchEvent(
        new CustomEvent("aipkit:provider-status-updated", {
          detail: { providerStatus: { ...statusMap } },
        })
      );
    }

    return changed;
  }

  function normalizeProviderKey(value) {
    if (!value) {
      return "";
    }
    return String(value).trim().toLowerCase();
  }

  function getActionProviderKey(providerKey) {
    const normalized = normalizeProviderKey(providerKey);
    const aliases = {
      anthropic: "claude",
      claude_files: "claude",
      "claude_files-provider": "claude",
      "qdrant-key": "qdrant",
      "qdrant-url": "qdrant",
      "chroma-url": "chroma",
    };
    return aliases[normalized] || normalized;
  }

  function isProviderConfigured(providerKey, statusMap) {
    if (!providerKey || !statusMap) {
      return null;
    }
    if (!Object.prototype.hasOwnProperty.call(statusMap, providerKey)) {
      return null;
    }
    return Boolean(statusMap[providerKey]);
  }

  function getNoticeMessage(noticeEl) {
    if (!noticeEl) {
      return "";
    }
    return noticeEl.getAttribute("data-message-default") || "";
  }

  function setNoticeMessage(noticeEl, message) {
    if (!noticeEl) {
      return;
    }
    const messageSlot = noticeEl.querySelector(".aipkit_provider_notice_message");
    if (messageSlot) {
      messageSlot.textContent = message || "";
    }
  }

  function buildSettingsUrl(noticeEl, route) {
    const fallback = String(
      noticeEl?.dataset.aipkitSettingsUrl || window.location.href
    );
    try {
      const url = new URL(fallback, window.location.href);
      url.searchParams.set("aipkit_module", "settings");
      url.searchParams.set("aipkit_settings_page", route.page);
      url.hash =
        route.kind === "integration"
          ? `aipkit_settings_integration_card_${route.card}`
          : `aipkit_settings_provider_card_${String(route.card).toLowerCase()}`;
      return url.toString();
    } catch (error) {
      return fallback;
    }
  }

  function updateNoticeAction(noticeEl, providerKey) {
    if (!noticeEl) {
      return;
    }
    const link = noticeEl.querySelector(LINK_SELECTOR);
    if (!link) {
      return;
    }

    const normalizedProvider = normalizeProviderKey(providerKey);
    if (normalizedProvider === "claude_files-provider") {
      link.hidden = true;
      return;
    }
    const actionKey = getActionProviderKey(normalizedProvider);
    const route = PROVIDER_ROUTES[actionKey];
    if (!route) {
      link.hidden = true;
      return;
    }

    link.textContent =
      noticeEl.getAttribute("data-action-default") ||
      link.textContent ||
      "Connect a provider";
    link.dataset.aipkitSettingsPage = route.page;
    link.dataset.aipkitSettingsCard = route.card;
    link.dataset.aipkitSettingsCardKind = route.kind;
    link.href = buildSettingsUrl(noticeEl, route);
    link.hidden = false;
  }

  function getNoticeOverride(targetId) {
    if (!targetId || !noticeOverrides.has(targetId)) {
      return null;
    }
    const override = noticeOverrides.get(targetId);
    if (!override || !override.message) {
      return null;
    }
    return override;
  }

  function applyNoticeOverride(noticeEl, override) {
    if (!noticeEl || !override) {
      return;
    }
    setNoticeMessage(noticeEl, override.message);
    noticeEl.dataset.aipkitNoticeProvider = normalizeProviderKey(
      override.providerKey || noticeEl.dataset.aipkitNoticeProvider
    );
    updateNoticeAction(
      noticeEl,
      override.providerKey || noticeEl.dataset.aipkitNoticeProvider || ""
    );
    showNotice(noticeEl);
  }

  function hideNotice(noticeEl, resolved = false) {
    if (!noticeEl) {
      return;
    }
    if (resolved) writeDismissal(noticeEl, null);
    noticeEl.classList.add("aipkit_provider_notice--hidden");
    notifyVisibility();
  }

  function noticeDismissalKey(noticeEl) {
    return `${normalizeProviderKey(noticeEl.dataset.aipkitNoticeProvider)}:${noticeEl.querySelector(".aipkit_provider_notice_message")?.textContent.trim() || ""}`;
  }

  function showNotice(noticeEl) {
    if (!noticeEl) {
      return;
    }
    if (readDismissal(noticeEl) === noticeDismissalKey(noticeEl)) {
      noticeEl.classList.add("aipkit_provider_notice--hidden");
      notifyVisibility();
      return;
    }
    writeDismissal(noticeEl, null);
    noticeEl.classList.remove("aipkit_provider_notice--hidden");
    notifyVisibility();
  }

  function updateNoticeForSelect(selectEl, statusMap) {
    if (!selectEl) {
      return;
    }

    const targetId = selectEl.getAttribute(
      "data-aipkit-provider-notice-target"
    );
    if (!targetId) {
      return;
    }

    const noticeEl = document.getElementById(targetId);
    if (!noticeEl) {
      return;
    }

    const override = getNoticeOverride(targetId);
    if (override) {
      applyNoticeOverride(noticeEl, override);
      return;
    }

    const deferNotice =
      selectEl.getAttribute("data-aipkit-provider-notice-defer") === "1";
    if (deferNotice && selectEl.dataset.aipkitProviderNoticeArmed !== "true") {
      hideNotice(noticeEl);
      return;
    }

    const providerKey = normalizeProviderKey(selectEl.value);
    if (!providerKey) {
      hideNotice(noticeEl);
      return;
    }
    const configured = isProviderConfigured(providerKey, statusMap);

    if (configured === null) {
      hideNotice(noticeEl);
      return;
    }

    if (configured) {
      hideNotice(noticeEl, true);
      return;
    }

    const messageSlot = noticeEl.querySelector(
      ".aipkit_provider_notice_message"
    );
    if (messageSlot) {
      messageSlot.textContent = getNoticeMessage(noticeEl);
    }
    noticeEl.dataset.aipkitNoticeProvider = providerKey;
    updateNoticeAction(noticeEl, providerKey);
    showNotice(noticeEl);
  }

  function updateNoticeForTargetId(targetId, statusMap) {
    if (!targetId) {
      return;
    }

    const noticeEl = document.getElementById(targetId);
    if (!noticeEl) {
      return;
    }

    const override = getNoticeOverride(targetId);
    if (override) {
      applyNoticeOverride(noticeEl, override);
      return;
    }

    const groupedSelects = Array.from(
      document.querySelectorAll(
        `${SELECTOR}[data-aipkit-provider-notice-target="${targetId}"]`
      )
    );
    const visibleSelect = groupedSelects.find(isSelectVisible) || groupedSelects[0];
    if (!visibleSelect) {
      hideNotice(noticeEl);
      return;
    }

    updateNoticeForSelect(visibleSelect, statusMap);
  }

  function isSelectVisible(selectEl) {
    if (!selectEl) {
      return false;
    }
    if (selectEl.offsetParent !== null) {
      return true;
    }
    return selectEl.getClientRects().length > 0;
  }

  function refreshNotices(rootEl) {
    const statusMap = getProviderStatusMap();
    const scope = rootEl || document;
    const selects = Array.from(scope.querySelectorAll(SELECTOR));
    const groupedTargetIds = new Set();

    selects.forEach((selectEl) => {
      const targetId = selectEl.getAttribute(
        "data-aipkit-provider-notice-target"
      );
      if (!targetId) {
        return;
      }
      groupedTargetIds.add(targetId);
    });

    noticeOverrides.forEach((_, targetId) => {
      groupedTargetIds.add(targetId);
    });

    groupedTargetIds.forEach((targetId) => {
      updateNoticeForTargetId(targetId, statusMap);
    });
  }

  function updateProviderStatusFromSettings() {
    const settingsContainer = document.getElementById(
      "aipkit_settings_container"
    );
    if (!settingsContainer) {
      return false;
    }

    const statusMap = { ...getProviderStatusMap() };
    const namedFields = new Map();
    let hasAnyField = false;

    settingsContainer.querySelectorAll("[name]").forEach((field) => {
      if (field.name && !namedFields.has(field.name)) {
        namedFields.set(field.name, field);
      }
    });

    Object.entries(PROVIDER_STATUS_REQUIREMENTS).forEach(
      ([providerKey, requiredFieldNames]) => {
        const fields = requiredFieldNames.map((name) => namedFields.get(name));
        if (!fields.some(Boolean)) {
          return;
        }

        hasAnyField = true;
        statusMap[providerKey] = fields.every(
          (field) => field && String(field.value || "").trim() !== ""
        );
      }
    );

    if (!hasAnyField) {
      return false;
    }

    applyProviderStatusMap(statusMap);

    return true;
  }

  function bindModuleLinks(rootEl) {
    const scope = rootEl || document;
    const links = scope.querySelectorAll(LINK_SELECTOR);
    links.forEach((link) => {
      if (link.dataset.aipkitProviderNoticeLinkBound === "true") {
        return;
      }
      link.addEventListener("click", (event) => {
        if (typeof window.aipkit_loadModule !== "function") {
          return;
        }
        event.preventDefault();
        const settingsPage = String(
          link.dataset.aipkitSettingsPage || "ai"
        ).trim();
        const card = String(link.dataset.aipkitSettingsCard || "").trim();
        const kind = String(
          link.dataset.aipkitSettingsCardKind || "provider"
        ).trim();

        if (kind === "provider" && typeof window.aipkit_openProviderConnection === "function") {
          window.aipkit_openProviderConnection(link);
          return;
        }

        window.__aipkitRequestedSettingsPage = settingsPage;
        if (kind === "integration") {
          window.__aipkitRequestedIntegrationCard = card.toLowerCase();
        } else {
          window.__aipkitRequestedProviderCard = card;
        }
        window.aipkit_loadModule("settings");
      });
      link.dataset.aipkitProviderNoticeLinkBound = "true";
    });
  }

  function initProviderKeyNotices(scopeEl) {
    const scope = scopeEl || document;
    const selects = scope.querySelectorAll(SELECTOR);

    scope.querySelectorAll("[data-aipkit-provider-notice]").forEach((notice) => {
      const dismiss = notice.querySelector("[data-aipkit-dismiss-provider-notice]");
      if (!dismiss || dismiss.dataset.aipkitDismissBound === "true") return;
      dismiss.addEventListener("click", () => {
        const dismissalKey = noticeDismissalKey(notice);
        writeDismissal(notice, dismissalKey);
        hideNotice(notice);
      });
      dismiss.dataset.aipkitDismissBound = "true";
    });

    selects.forEach((selectEl) => {
      if (selectEl.dataset.aipkitProviderNoticeBound !== "true") {
        selectEl.addEventListener("change", () => {
          if (
            selectEl.getAttribute("data-aipkit-provider-notice-defer") === "1"
          ) {
            selectEl.dataset.aipkitProviderNoticeArmed = "true";
          }
          updateNoticeForSelect(selectEl, getProviderStatusMap());
        });
        selectEl.dataset.aipkitProviderNoticeBound = "true";
      }
    });

    window.aipkit_syncProviderStatusFromSettings();
    refreshNotices(scope);

    bindModuleLinks(scope);
  }

  window.aipkit_initProviderKeyNotices = initProviderKeyNotices;
  window.aipkit_applyProviderStatus = applyProviderStatusMap;
  window.aipkit_syncProviderStatusFromSettings = function () {
    const updated = updateProviderStatusFromSettings();
    if (updated) {
      refreshNotices(document);
    }
    return updated;
  };
  window.aipkit_setProviderNoticeOverride = function (targetId, override = null) {
    const normalizedTargetId = String(targetId || "").trim();
    if (!normalizedTargetId) {
      return;
    }

    if (override && typeof override === "object" && String(override.message || "").trim()) {
      noticeOverrides.set(normalizedTargetId, {
        ...override,
        message: String(override.message || "").trim(),
      });
    } else {
      noticeOverrides.delete(normalizedTargetId);
    }

    updateNoticeForTargetId(normalizedTargetId, getProviderStatusMap());
  };
  window.aipkit_updateProviderNotice = function (
    noticeEl,
    {
      providerKey = "",
      message = "",
      visible = true,
      actionVisible = true,
    } = {}
  ) {
    if (!noticeEl) {
      return;
    }
    const normalizedProvider = normalizeProviderKey(providerKey);
    const nextMessage =
      String(message || "").trim() ||
      getNoticeMessage(noticeEl);
    if (nextMessage) {
      setNoticeMessage(noticeEl, nextMessage);
    }
    noticeEl.dataset.aipkitNoticeProvider = normalizedProvider;
    updateNoticeAction(noticeEl, normalizedProvider);
    const actionLink = noticeEl.querySelector(LINK_SELECTOR);
    if (actionLink && !actionVisible) {
      actionLink.hidden = true;
    }
    noticeEl.hidden = !visible;
    if (visible) {
      showNotice(noticeEl);
    } else {
      hideNotice(noticeEl, true);
    }
  };
  window.aipkit_clearProviderNoticeOverride = function (targetId) {
    const normalizedTargetId = String(targetId || "").trim();
    if (!normalizedTargetId) {
      return;
    }
    noticeOverrides.delete(normalizedTargetId);
    updateNoticeForTargetId(normalizedTargetId, getProviderStatusMap());
  };

  document.addEventListener("DOMContentLoaded", function () {
    initProviderKeyNotices(document);
  });
})();
