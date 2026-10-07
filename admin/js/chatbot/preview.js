/** Admin preview HTML, cache, loading feedback and deferred initialization. */
(function () {
  "use strict";
  const normalizeId = value => {
    const id = parseInt(value, 10);
    return Number.isFinite(id) && id > 0 ? id : 0;
  };
  let owner;

  function getOwner() {
    const container = document.getElementById("aipkit_admin_chat_preview_container");
    const column = container?.closest(".aipkit_chatbot-preview-column");
    if (owner?.matches(container, column)) return owner;
    owner?.dispose();
    owner = container?.isConnected && column?.isConnected ? createPreview(container, column) : null;
    return owner;
  }

  function createPreview(container, column) {
    const builder = container.closest(".aipkit_chatbot_builder");
    const entries = new Map(), primers = new Set();
    let disposed = false, token = 0, viewId = 0, renderedEntry, wrapper;
    let loadingTimer, loadingFinishTimer, loadingShownAt, initializationTimer;
    const alive = () => !disposed && container.isConnected && column.isConnected && (!builder || builder.isConnected);
    const selectedId = () => {
      const select = builder?.querySelector("#aipkit_chatbot_builder_bot_select");
      return normalizeId(select ? select.value : builder?.getAttribute("data-active-bot-id"));
    };
    const clearInitialization = () => { clearTimeout(initializationTimer); initializationTimer = null; };
    const resetLoading = () => {
      clearTimeout(loadingTimer); clearTimeout(loadingFinishTimer);
      loadingTimer = loadingFinishTimer = loadingShownAt = null;
      column.classList.remove("aipkit_loading");
    };
    const cancelView = () => { token++; resetLoading(); clearInitialization(); };
    const clearHtml = () => {
      clearInitialization(); wrapper = renderedEntry = null;
      container.replaceChildren();
      container.classList.remove("aipkit-preview-inline", "aipkit-preview-popup");
      container.style.removeProperty("--aipkit-chat-container-border-radius");
    };
    const placeholder = (message, error = false, target = container) => {
      if (target === container) clearHtml();
      const node = document.createElement("p");
      node.className = "aipkit_preview_placeholder";
      node.textContent = message;
      if (error) node.style.color = "red";
      target.replaceChildren(node);
    };
    const startLoading = () => {
      loadingTimer = setTimeout(() => {
        loadingTimer = null;
        if (!alive()) return;
        loadingShownAt = Date.now();
        column.classList.add("aipkit_loading");
      }, 160);
    };
    const finishLoading = () => {
      clearTimeout(loadingTimer); loadingTimer = null;
      const remaining = loadingShownAt === null ? 0 : 240 - (Date.now() - loadingShownAt);
      if (remaining > 0) loadingFinishTimer = setTimeout(resetLoading, remaining);
      else resetLoading();
    };
    const ownsWrapper = node => alive() && wrapper === node && node.isConnected && container.contains(node);
    const initializationError = (node, message) => {
      if (!ownsWrapper(node)) return;
      renderedEntry = null;
      placeholder(message, true, node);
    };
    const initialize = (node, retries = 5) => {
      if (!ownsWrapper(node)) return;
      if (typeof window.aipkit_initializeChatInstance === "function") {
        try {
          Promise.resolve(window.aipkit_initializeChatInstance(node))
            .catch(() => initializationError(node, "Error initializing preview."));
        }
        catch { initializationError(node, "Error initializing preview."); }
      } else if (retries > 0) {
        initializationTimer = setTimeout(() => { initializationTimer = null; initialize(node, retries - 1); }, 100);
      } else initializationError(node, "Error loading preview function.");
    };
    const render = (entry, html) => {
      if (renderedEntry === entry && wrapper?.isConnected && container.contains(wrapper) && !wrapper.classList.contains("aipkit-initialization-failed")) return true;
      clearHtml();
      container.innerHTML = html;
      wrapper = container.querySelector(".aipkit_chat_container, .aipkit_popup_wrapper");
      if (!wrapper) {
        placeholder("Error loading preview wrapper element.", true);
        return false;
      }
      renderedEntry = entry;
      container.classList.toggle("aipkit-preview-inline", wrapper.classList.contains("aipkit_chat_container"));
      container.classList.toggle("aipkit-preview-popup", wrapper.classList.contains("aipkit_popup_wrapper"));
      const radius = window.getComputedStyle(wrapper).getPropertyValue("--aipkit-chat-container-border-radius").trim();
      if (radius && !["initial", "inherit", "unset"].includes(radius)) {
        container.style.setProperty("--aipkit-chat-container-border-radius", radius);
      }
      initialize(wrapper);
      return true;
    };
    const evict = id => {
      entries.get(id)?.controller?.abort();
      entries.delete(id);
    };
    const request = (id, force = false) => {
      if (force) evict(id);
      const cached = entries.get(id);
      if (cached && (cached.html !== undefined || cached.promise)) return cached;
      const entry = { controller: new AbortController() };
      entries.set(id, entry);
      entry.promise = (async () => {
        const url = new URL(window.aipkit_dashboard.ajaxurl);
        url.searchParams.append("action", "aipkit_get_chatbot_shortcode");
        url.searchParams.append("bot_id", String(id));
        url.searchParams.append("_ajax_nonce", window.aipkit_dashboard.nonce);
        const response = await fetch(url, { signal: entry.controller.signal });
        const data = await response.json();
        if (!(data.success && data.data?.html)) {
          throw new Error(data.data?.message || "Invalid response received for shortcode preview.");
        }
        const html = String(data.data.html);
        if (alive() && entries.get(id) === entry) entry.html = html;
        return html;
      })().finally(() => { entry.promise = entry.controller = null; });
      return entry;
    };
    const show = (id, options = {}) => {
      if (!alive()) return Promise.resolve(false);
      token++; resetLoading();
      if (viewId !== id) clearInitialization();
      viewId = id;
      const scope = token, showLoading = options.showLoading !== false;
      if (!id) {
        placeholder(options.placeholder || window.aipkit_dashboard?.text?.previewPlaceholderSelect || "Select a chatbot to see the preview.");
        return Promise.resolve(false);
      }
      const entry = request(id, Boolean(options.force));
      const current = () => alive() && scope === token && entries.get(id) === entry;
      if (entry.html !== undefined) return Promise.resolve(render(entry, entry.html));
      if (showLoading) { clearHtml(); startLoading(); }
      return entry.promise.then(html => current() ? render(entry, html) : false)
        .catch(error => {
          if (current() && (showLoading || !container.innerHTML.trim())) placeholder(`Failed to load preview: ${error.message}`, true);
          return false;
        }).finally(() => { if (alive() && scope === token) finishLoading(); });
    };
    const invalidate = id => {
      if (id) evict(id);
      else { entries.forEach(entry => entry.controller?.abort()); entries.clear(); }
      if (!id || id === viewId) cancelView();
    };
    const prime = (ids, options = {}) => new Promise(resolve => {
      if (!alive()) { resolve(false); return; }
      let scheduled, usesIdle = false;
      const job = { cancel: () => {
        if (usesIdle) window.cancelIdleCallback(scheduled);
        else clearTimeout(scheduled);
        primers.delete(job); resolve(false);
      } };
      primers.add(job);
      const run = async () => {
        scheduled = null;
        try {
          for (const id of ids) {
            if (!alive()) break;
            const entry = request(id);
            await entry.promise?.catch(() => false);
          }
        } finally { primers.delete(job); resolve(alive()); }
      };
      if (!options.idle) { run(); return; }
      usesIdle = typeof window.requestIdleCallback === "function" && typeof window.cancelIdleCallback === "function";
      scheduled = usesIdle ? window.requestIdleCallback(run) : setTimeout(run, 300);
    });
    const dispose = () => {
      if (disposed) return;
      disposed = true;
      observer.disconnect(); cancelView();
      entries.forEach(entry => entry.controller?.abort()); entries.clear();
      primers.forEach(job => job.cancel());
    };
    const observer = new MutationObserver(() => { if (!alive()) dispose(); });
    observer.observe(container.ownerDocument.documentElement, { childList: true, subtree: true });
    return { show, prime, invalidate, dispose, selectedId,
      matches: (node, parent) => alive() && node === container && parent === column,
      refresh: (id, options) => {
        if (id && id !== (builder ? selectedId() : viewId)) {
          invalidate(id);
          return Promise.resolve(false);
        }
        return show(id, { ...options, force: true });
      },
    };
  }

  window.aipkit_showChatPreview = (botId, options) => getOwner()?.show(normalizeId(botId), options) || Promise.resolve(false);
  window.aipkit_refreshChatPreview = (botId, options) => getOwner()?.refresh(normalizeId(botId), options) || Promise.resolve(false);
  window.aipkit_invalidateChatPreviewCache = botId => getOwner()?.invalidate(normalizeId(botId));
  window.aipkit_loadInitialChatPreview = () => {
    const preview = getOwner();
    return preview?.show(preview.selectedId()) || Promise.resolve(false);
  };
  window.aipkit_primeChatPreviewCache = (botIds = [], options) => {
    const ids = [...new Set((Array.isArray(botIds) ? botIds : [botIds]).map(normalizeId))].filter(Boolean);
    return getOwner()?.prime(ids, options) || Promise.resolve(false);
  };
})();

/** Start the active preview and prime inactive bots once per editor. */
export function startChatbotEditorPreview({builder, getSelectableBotIds, getSelectedBuilderBotId}) {
  const schedulePreviewCachePrime = (excludeBotId = "") => {
    if (builder.dataset.previewCachePrimed === "1" || typeof window.aipkit_primeChatPreviewCache !== "function") {
      return;
    }
    const normalizedExcludeBotId = String(excludeBotId || "").trim();
    const remainingBotIds = getSelectableBotIds().filter(botId => botId !== normalizedExcludeBotId);
    builder.dataset.previewCachePrimed = "1";
    if (!remainingBotIds.length) {
      return;
    }
    window.aipkit_primeChatPreviewCache(remainingBotIds, {
      idle: true
    });
  };
  const selectedBotId = getSelectedBuilderBotId();
  if (selectedBotId) {
    if (typeof window.aipkit_showChatPreview === "function") {
      window.aipkit_showChatPreview(selectedBotId);
      schedulePreviewCachePrime(selectedBotId);
      return;
    }
    if (typeof window.aipkit_refreshChatPreview === "function") {
      window.aipkit_refreshChatPreview(selectedBotId);
      schedulePreviewCachePrime(selectedBotId);
      return;
    }
    return;
  }
  const fallbackText = window.aipkit_dashboard?.text?.previewPlaceholderSelect || "Select a bot to see the preview.";
  window.aipkit_showChatPreview("", {
    placeholder: fallbackText
  });
}

/** Preview width: desktop, or a phone-sized frame. */

export function bindChatbotPreviewDevice(builder) {
  const group = builder.querySelector("[data-aipkit-preview-device]");
  const frame = builder.querySelector(".aipkit_builder_preview_frame");
  if (!group || !frame || group.dataset.deviceBound) {
    return;
  }
  group.dataset.deviceBound = "1";
  group.addEventListener("click", (event) => {
    const option = event.target.closest("[data-device]");
    if (!option) {
      return;
    }
    group.querySelectorAll("[data-device]").forEach((button) => {
      button.setAttribute("aria-pressed", button === option ? "true" : "false");
    });
    frame.classList.toggle("is-mobile", option.dataset.device === "mobile");
  });
}
