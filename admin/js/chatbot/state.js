import { applyConfiguredVectorStoreValues } from "../utils/vector-store-selection-state.js";

/** Preserve non-preset auto-open delays when an existing bot hydrates or a save is restored. */
export function preservePopupDelayOption(field, value) {
  if (field?.tagName !== "SELECT" || field.name !== "popup_delay") return;
  const saved = String(value ?? "");
  if (!/^\d+$/.test(saved) || Array.from(field.options).some(option => option.value === saved)) return;
  const label = (field.dataset.savedValueLabel || "%d sec").replace("%d", saved);
  field.appendChild(new Option(label, saved));
}

/** Coordinates record actions with feature-owned saves across live editors. */
export function createChatbotRecordActions() {
  const bindings = new Set(), locks = new Map(), pending = new Map();
  const key = id => String(id || "").trim();
  const register = binding => { bindings.add(binding); return () => bindings.delete(binding); };
  const isLocked = id => Boolean(locks.get(key(id))?.ready);
  const track = (id, request) => {
    id = key(id);
    if (!pending.has(id)) pending.set(id, new Set());
    const requests = pending.get(id);
    requests.add(request);
    const release = () => {
      requests.delete(request);
      if (!requests.size && pending.get(id) === requests) pending.delete(id);
    };
    request.then(release, release);
  };
  const run = async (action, id, request) => {
    id = key(id);
    if (locks.has(id)) {
      const __ = window.wp?.i18n?.__ || (text => text);
      throw new Error(__("Wait for the current chatbot action to finish.", "gpt3-ai-content-generator"));
    }
    const lock = { ready: false };
    let succeeded = false, preparationError;
    locks.set(id, lock);
    try {
      // Prepare every feature before waiting. Synchronous preparation may flush
      // drafts; subsequent user events and replacement editors inherit the lock.
      for (const binding of bindings) {
        try { binding.prepareAction(action, id); }
        catch (error) { preparationError ||= error; }
      }
      lock.ready = true;
      bindings.forEach(binding => binding.syncInteraction());
      // Sent requests remain tracked after their editor unregisters. Re-check
      // after settling in case preparation extended an existing request chain.
      while (pending.get(id)?.size) await Promise.allSettled([...pending.get(id)]);
      if (preparationError) throw preparationError;
      if (action === "duplicate") bindings.forEach(binding => binding.checkAction(id));
      const response = await request();
      succeeded = true;
      bindings.forEach(binding => binding.replace(action, id, response));
      return response;
    } finally {
      if (locks.get(id) === lock) locks.delete(id);
      bindings.forEach(binding => binding.finishAction(id, !succeeded && action !== "duplicate"));
    }
  };
  const syncSettings = (lane, patches) => bindings.forEach(binding => binding.receiveSettings?.(lane, patches));
  return { register, isLocked, track, run, syncSettings };
}

// Feature-owned drafts share save ordering, hydration, disposal and record actions.
// Only sent request chains outlive a binding; drafts and listeners belong to it.
const settingsSaveRequests = new Map();
export function bindChatbotSettingsAutosave({
  builder, panel, boundKey, action, readSettings, isUnchanged,
  isRelevant, beforeRead = () => {}, canSave = () => true, persistence,
  lane = action, makePayload = settings => settings, savedSettings, confirmedSettings,
  restoreSettings, resetSettings, afterChange, afterHydrate, onSaved, mergePending, interactionNodes,
  ownedSettings = settings => settings, bindEvents, sharedLane = false, responsePatches,
}) {
  if (!panel || !builder.isConnected || builder.dataset[boundKey]) return;
  const { recordActions } = persistence;
  const controller = new AbortController();
  const records = new Map();
  const interactions = new Map();
  const selectedId = () => persistence.getSelectedBuilderBotId();
  const keyFor = (id) => sharedLane ? lane : `${lane}:${id}`;
  let active, generation = 0, disposed = false, hydrating = false, ownsBusy = false;
  const alive = () => !disposed && builder.isConnected && panel.isConnected !== false;
  const releaseBusy = () => {
    if (ownsBusy) persistence.setSaveStatus("", "");
    ownsBusy = false;
  };
  const restore = (settings) => {
    if (restoreSettings) { restoreSettings(settings); return; }
    for (const [name, value] of Object.entries(ownedSettings(settings))) {
      const field = panel.querySelector(`[name="${name}"]`);
      if (!field) continue;
      if (field.type === "checkbox") field.checked = value === "1";
      else field.value = value;
    }
  };
  const syncInteraction = () => {
    if (recordActions.isLocked(selectedId())) {
      const nodes = interactionNodes ? interactionNodes() :
        Object.keys(ownedSettings(active?.desired || {})).map(name => panel.querySelector(`[name="${name}"]`));
      for (const node of nodes) {
        if (!node || interactions.has(node)) continue;
        interactions.set(node, { inert: node.inert, busy: node.getAttribute("aria-busy") });
        node.inert = true;
        node.setAttribute("aria-busy", "true");
      }
    } else restoreInteraction();
  };
  const restoreInteraction = () => {
    for (const [node, state] of interactions) {
      node.inert = state.inert;
      if (state.busy === null) node.removeAttribute("aria-busy");
      else node.setAttribute("aria-busy", state.busy);
    }
    interactions.clear();
  };
  const hydrate = (event) => {
    if (!alive()) { dispose(); return; }
    hydrating = true;
    generation += 1;
    releaseBusy();
    try {
      const id = selectedId(), settings = readSettings();
      const record = records.get(String(id));
      if ((!event?.detail?.source || event.detail.source === "switch") && record &&
          (record.pending || record.uncertain || !isUnchanged(record.saved, record.desired))) {
        active = record;
        restore(record.desired);
      } else {
        active = { id, saved: settings, desired: settings, pending: false, version: 0 };
        records.set(String(id), active);
      }
      afterHydrate?.();
    } finally {
      hydrating = false;
      syncInteraction();
    }
  };
  const queue = (settings, record = active) => {
    if (!alive()) { dispose(); return; }
    if (hydrating || !record?.id || recordActions.isLocked(record.id)) return;
    if (mergePending && (record.pending || record.uncertain)) settings = mergePending(record.desired, settings);
    if (isUnchanged(settings, record.pending ? record.desired : record.saved) &&
        (record.pending || !record.uncertain)) return;
    record.desired = settings;
    record.error = null;
    const version = ++record.version, scope = generation;
    // Invalid input still supersedes a queued value. It remains an unsaved draft
    // until corrected, without allowing that earlier value to run afterwards.
    record.pending = canSave(settings);
    if (!record.pending) { releaseBusy(); return; }
    const current = () => alive() && records.get(String(record.id)) === record && record.version === version;
    const visible = () => current() && !recordActions.isLocked(record.id) && active === record && generation === scope && String(selectedId()) === String(record.id);
    const key = keyFor(record.id), previous = settingsSaveRequests.get(key);
    let finish;
    const request = new Promise(resolve => { finish = resolve; });
    settingsSaveRequests.set(key, request);
    recordActions.track(record.id, request);
    const send = async () => {
      let savedResponse;
      try {
        if (!current()) return;
        if (visible()) { ownsBusy = true; persistence.setSaveStatus("Saving", ""); }
        record.uncertain = true;
        const response = await window.aipkit_apiRequest(action, { bot_id: record.id, ...makePayload(settings) });
        // Cross-record side effects still matter when a newer draft superseded
        // this request or its original editor was removed.
        if (responsePatches) recordActions.syncSettings(lane, responsePatches(response));
        if (!current()) return;
        savedResponse = response;
        record.saved = confirmedSettings ? confirmedSettings(settings, response) : settings;
        if (confirmedSettings) record.desired = record.saved;
        record.uncertain = false;
        persistence.handleBotSaveSuccess(record.id, response, {
          settings: savedSettings ? savedSettings(settings, response) : ownedSettings(settings),
        });
        if (visible()) {
          ownsBusy = false;
          if (onSaved) onSaved(settings, response, visible);
          else persistence.setSavedStatus(response);
        }
      } catch (error) {
        if (current()) record.error = error;
        if (visible()) { ownsBusy = false; persistence.handleActiveSaveError(record.id, error); }
      } finally {
        if (current()) record.pending = false;
        if (settingsSaveRequests.get(key) === request) settingsSaveRequests.delete(key);
        finish(savedResponse);
      }
    };
    if (previous) previous.then(send);
    else send();
  };
  const dirty = record => record.pending || record.uncertain || !isUnchanged(record.saved, record.desired);
  const receiveSettings = (sourceLane, patches) => {
    if (sourceLane !== lane || !alive()) return;
    for (const { botId, settings } of patches) {
      persistence.handleBotSaveSuccess(botId, { bot: { bot_id: botId, settings } }, { settings });
      const record = records.get(String(botId));
      if (!record || dirty(record)) continue;
      record.saved = record.desired = { ...record.saved, ...settings };
      if (record !== active || String(selectedId()) !== String(botId)) continue;
      hydrating = true;
      try { restore(record.desired); afterHydrate?.({ source: "related-save" }); } finally { hydrating = false; }
    }
  };
  const prepareAction = (actionName, id) => {
    // Deployment writes share site-wide ownership across bots. A record action
    // must also wait for already queued writes that can affect this bot.
    const sharedRequest = sharedLane && settingsSaveRequests.get(keyFor(id));
    if (sharedRequest) recordActions.track(id, sharedRequest);
    const record = records.get(id);
    if (!record) return;
    if (actionName === "duplicate") {
      if (dirty(record)) queue(record.desired, record);
    } else {
      record.uncertain ||= record.pending;
      record.version += 1;
      record.pending = false;
    }
    if (record === active) releaseBusy();
  };
  const checkAction = id => {
    const record = records.get(id);
    if (record && dirty(record)) {
      const __ = window.wp?.i18n?.__ || (text => text);
      throw record.error || new Error(__("Save the chatbot settings before trying this action again.", "gpt3-ai-content-generator"));
    }
  };
  const replace = (actionName, id, response) => {
    const record = records.get(id);
    if (!record || actionName === "duplicate") return;
    records.delete(id);
    if (active === record) active = null;
    if (actionName !== "reset" || !response?.bot?.settings) return;
    const settings = resetSettings ? resetSettings(record.desired, response) : Object.fromEntries(Object.entries(makePayload(record.desired)).map(([name, value]) =>
      [name, String(response.bot.settings[name] ?? value)]));
    const next = { id: record.id, saved: settings, desired: settings, pending: false, version: 0 };
    records.set(id, next);
    persistence.handleBotSaveSuccess(record.id, response, {
      settings: savedSettings ? savedSettings(settings, response) : ownedSettings(settings), refreshPreview: false,
    });
    if (String(selectedId()) === id) {
      active = next;
      hydrating = true;
      try { restore(settings); afterHydrate?.(); } finally { hydrating = false; }
    }
  };
  const finishAction = (id, resumeDraft) => {
    syncInteraction();
    const record = records.get(id);
    if (resumeDraft && record && dirty(record)) queue(record.desired, record);
  };
  function dispose() {
    if (disposed) return;
    disposed = true;
    controller.abort();
    observer.disconnect();
    releaseBusy();
    records.clear();
    active = null;
    restoreInteraction();
    unregister();
    delete builder.dataset[boundKey];
  }
  const observer = new MutationObserver(() => { if (!alive()) dispose(); });
  const isEditable = () => alive() && !hydrating && active &&
    String(selectedId()) === String(active.id) && !recordActions.isLocked(selectedId());
  if (bindEvents) bindEvents({
    signal: controller.signal, isEditable,
    // Training must confirm its captured settings, not merely wait for a busy flag.
    saveAndWait: async (settings, assertCurrent = () => {}) => {
      const record = active, scope = generation;
      const assertOwner = () => {
        assertCurrent();
        if (!record?.id || !isEditable() || active !== record || generation !== scope) {
          const __ = window.wp?.i18n?.__ || (text => text);
          throw new Error(__("Chatbot settings changed. Please try again.", "gpt3-ai-content-generator"));
        }
      };
      assertOwner();
      queue(settings);
      const version = record.version;
      const request = settingsSaveRequests.get(keyFor(record.id));
      const response = request ? await request : null;
      assertOwner();
      if (record.version !== version || record.error || dirty(record)) {
        const __ = window.wp?.i18n?.__ || (text => text);
        throw record.error || new Error(__("Save the chatbot settings before trying this action again.", "gpt3-ai-content-generator"));
      }
      return response;
    },
    save: () => { if (isEditable()) queue(readSettings()); },
    draft: () => {
      if (!isEditable()) return;
      const settings = readSettings();
      if (isUnchanged(settings, active.desired)) return;
      // Capture typing without saving on each keystroke. Superseded requests
      // remain tracked, but cannot acknowledge or overwrite the newer draft.
      active.uncertain ||= active.pending;
      active.version += 1;
      active.pending = false;
      active.desired = settings;
      active.error = null;
      releaseBusy();
    },
  });
  else panel.addEventListener("change", (event) => {
    if (!isEditable() || !event.target || !isRelevant(event.target)) return;
    beforeRead(event);
    queue(readSettings(event));
    afterChange?.(event);
  }, { signal: controller.signal });
  builder.addEventListener("aipkit:bot-state-applied", hydrate, { signal: controller.signal });
  const unregister = recordActions.register({ prepareAction, checkAction, replace, syncInteraction, finishAction, receiveSettings });
  builder.dataset[boundKey] = "1";
  hydrate();
  observer.observe(builder.ownerDocument.documentElement, { childList: true, subtree: true });
}

/** Chatbot state payloads, cache ownership and deduplicated reads. */
export function createChatbotStateCache({ builder, getSelectableBotIds }) {
  const cache = new Map();
  const pending = new Map();
  const versions = new Map();
  let defaultVersion = 0;
  let primePromise = null;

  const normalizeState = (payload) => {
    if (!payload || typeof payload !== "object") {
      return null;
    }
    const botId = String(payload.bot_id || "").trim();
    if (!botId) {
      return null;
    }
    return {
      bot_id: botId,
      bot_name: String(payload.bot_name || ""),
      is_default: Boolean(payload.is_default),
      settings:
        payload.settings && typeof payload.settings === "object"
          ? { ...payload.settings }
          : {},
      deploy_mode: String(payload.deploy_mode || "inline"),
      shortcode: String(payload.shortcode || ""),
      embed_code: String(payload.embed_code || ""),
      embed_allowed_domains: String(payload.embed_allowed_domains || ""),
      conversation_starters_text: String(
        payload.conversation_starters_text || ""
      ),
      triggers_json: String(payload.triggers_json || "[]"),
      connected_apps:
        payload.connected_apps && typeof payload.connected_apps === "object"
          ? {
              count: Number(payload.connected_apps.count || 0),
              summary: String(payload.connected_apps.summary || ""),
              recipes: Array.isArray(payload.connected_apps.recipes)
                ? payload.connected_apps.recipes.map((recipe) => ({
                    id: String(recipe?.id || ""),
                    name: String(recipe?.name || ""),
                    app_slug: String(recipe?.app_slug || ""),
                    app_label: String(recipe?.app_label || ""),
                    connection_label: String(recipe?.connection_label || ""),
                    event_name: String(recipe?.event_name || ""),
                    event_label: String(recipe?.event_label || ""),
                    action_label: String(recipe?.action_label || ""),
                    status_key: String(recipe?.status_key || "warning"),
                    status_label: String(recipe?.status_label || ""),
                    validation_summary: String(
                      recipe?.validation_summary || ""
                    ),
                    is_enabled: Boolean(recipe?.is_enabled),
                    scope_label: String(recipe?.scope_label || ""),
                    scope_key: String(recipe?.scope_key || ""),
                  }))
                : [],
            }
          : null,
    };
  };

  const store = (payload, readVersions = null) => {
    const state = normalizeState(payload);
    if (!state) return null;
    const id = state.bot_id;
    const version = versions.get(id) || 0;
    // A completed save, reset or removal wins over an earlier state read.
    if (readVersions && version !== (readVersions.get(id) || 0)) {
      return cache.get(id) || null;
    }
    cache.set(id, state);
    if (!readVersions) versions.set(id, version + 1);
    return state;
  };
  const syncDefault = (payload, readDefaultVersion = null) => {
    const id = payload.default_bot_id;
    if (id === undefined || id === null || id === "") return;
    if (readDefaultVersion !== null && readDefaultVersion !== defaultVersion) return;
    builder.setAttribute("data-default-bot-id", String(id));
    if (readDefaultVersion === null) defaultVersion += 1;
  };
  const storeCollection = (bots, readVersions = null) => {
    let count = 0;
    Object.values(bots).forEach((state) => {
      if (store(state, readVersions)) count += 1;
    });
    return count;
  };
  const syncResponse = (response, readVersions = null, readDefaultVersion = null) => {
    if (!response || typeof response !== "object") return null;
    syncDefault(response, readDefaultVersion);
    const primary = response.bot ? store(response.bot, readVersions) : null;
    if (Array.isArray(response.updated_bots)) {
      response.updated_bots.forEach((state) => store(state, readVersions));
    }
    return primary;
  };
  const seed = () => {
    const node = document.getElementById("aipkit_chatbot_switch_state_json");
    let payload;
    try {
      payload = node && JSON.parse(node.textContent || node.innerText || "");
    } catch (error) {
      return 0;
    }
    if (!payload || !payload.bots || typeof payload.bots !== "object") return 0;
    const count = storeCollection(payload.bots);
    syncDefault(payload);
    return count;
  };
  const get = (botId) => cache.get(String(botId || "").trim());
  const remove = (botId) => {
    const id = String(botId || "").trim();
    if (!id) return;
    cache.delete(id);
    pending.delete(id);
    versions.set(id, (versions.get(id) || 0) + 1);
    defaultVersion += 1;
    if (typeof window.aipkit_invalidateChatPreviewCache === "function") {
      window.aipkit_invalidateChatPreviewCache(id);
    }
  };
  const fetch = (botId = "") => {
    const id = String(botId || "").trim();
    if (id && cache.has(id)) return Promise.resolve(cache.get(id));
    if (id && pending.has("__all__")) {
      return pending.get("__all__").then(() => cache.get(id) || null);
    }
    const key = id || "__all__";
    if (pending.has(key)) return pending.get(key);
    if (typeof window.aipkit_apiRequest !== "function") {
      return Promise.reject(new Error("Chatbot state API unavailable."));
    }
    const readVersions = new Map(versions);
    const readDefaultVersion = defaultVersion;
    const request = window
      .aipkit_apiRequest("aipkit_get_chatbot_switch_state", id ? { bot_id: id } : {})
      .then((response) => {
        if (!builder.isConnected) return null;
        syncResponse(response, readVersions, readDefaultVersion);
        if (response && response.bots && typeof response.bots === "object") {
          storeCollection(response.bots, readVersions);
        }
        return id ? cache.get(id) || null : response;
      })
      .finally(() => {
        if (pending.get(key) === request) pending.delete(key);
      });
    pending.set(key, request);
    return request;
  };
  const prime = () => {
    if (primePromise) return primePromise;
    const ids = getSelectableBotIds();
    if (ids.length < 2 || ids.every((id) => cache.has(id))) return Promise.resolve();
    primePromise = fetch().catch(() => {}).finally(() => { primePromise = null; });
    return primePromise;
  };

  return { get, store, syncResponse, seed, remove, fetch, prime };
}

/** Applies saved Chatbot fields without firing change/autosave events. */
export function createChatbotStateHydration({ syncUnifiedModelSelector }) {
  const escapeSelectorValue = (value) => {
    if (
      typeof window !== "undefined" &&
      window.CSS &&
      typeof window.CSS.escape === "function"
    ) {
      return window.CSS.escape(String(value));
    }
    return String(value).replace(
      /([ #;?%&,.+*~\\':"!^$\[\]()=>|/@])/g,
      "\\$1"
    );
  };
  const toStringArray = (value) => {
    if (Array.isArray(value)) {
      return value.map((item) => String(item ?? ""));
    }
    if (value === null || value === undefined || value === "") {
      return [];
    }
    return [String(value)];
  };
  const isTruthyToggleValue = (value) => {
    if (value === true || value === 1) {
      return true;
    }
    const normalized = String(value ?? "")
      .trim()
      .toLowerCase();
    return (
      normalized === "1" ||
      normalized === "true" ||
      normalized === "yes" ||
      normalized === "on"
    );
  };
  const vectorStoreTargetFieldNames = new Set([
    "openai_vector_store_ids[]",
    "google_file_search_store_names[]",
    "pinecone_index_name",
    "qdrant_collection_names[]",
    "chroma_collection_names[]",
    "local_store_ids[]",
  ]);
  const setElementValue = (element, value) => {
    if (!element) {
      return;
    }
    const tagName = (element.tagName || "").toUpperCase();
    const type = (element.type || "").toLowerCase();
    if (type === "checkbox") {
      if (Array.isArray(value)) {
        const selectedValues = toStringArray(value);
        element.checked = selectedValues.includes(String(element.value || "1"));
        return;
      }
      element.checked = isTruthyToggleValue(value);
      return;
    }
    if (type === "radio") {
      element.checked = String(element.value || "") === String(value ?? "");
      return;
    }
    if (
      tagName === "SELECT" &&
      vectorStoreTargetFieldNames.has(String(element.name || ""))
    ) {
      applyConfiguredVectorStoreValues(element, toStringArray(value));
      return;
    }
    if (tagName === "SELECT" && element.multiple) {
      const selectedValues = toStringArray(value);
      Array.from(element.options || []).forEach((option) => {
        option.selected = selectedValues.includes(String(option.value || ""));
      });
      return;
    }
    const fieldValue = value === null || value === undefined ? "" : String(value);
    if (tagName === "SELECT" && fieldValue &&
        ["tts_elevenlabs_voice_id", "tts_elevenlabs_model_id", "vector_embedding_provider", "vector_embedding_model"].includes(element.name) &&
        !Array.from(element.options).some(option => option.value === fieldValue)) {
      // Keep saved catalog IDs available until the provider list is refreshed. The embedding lists hold
      // the first chatbot's provider, so another chatbot's model would otherwise be blanked and saved empty.
      element.appendChild(new Option(fieldValue, fieldValue));
    }
    preservePopupDelayOption(element, fieldValue);
    element.value = fieldValue;
  };
  const applyField = (scope, fieldName, value) => {
    if (!scope || !fieldName) {
      return false;
    }
    const selector = `[name="${escapeSelectorValue(fieldName)}"]`;
    const fields = Array.from(scope.querySelectorAll(selector));
    if (!fields.length) {
      return false;
    }
    fields.forEach((field) => setElementValue(field, value));
    return true;
  };

  const applySettings = (scope, settings) => {
    Object.entries(settings).forEach(([key, value]) => {
      if (!key || key === "reasoning_effort") {
        return;
      }
      if (key === "token_role_limits") {
        scope.querySelectorAll('input[name^="token_role_limits["]').forEach(field => setElementValue(field, ""));
        Object.entries(value && typeof value === "object" ? value : {}).forEach(([roleSlug, roleLimit]) => {
          applyField(
            scope,
            `token_role_limits[${roleSlug}]`,
            roleLimit
          );
        });
        return;
      }
      const applied = applyField(scope, key, value);
      if (!applied && Array.isArray(value) && !key.endsWith("[]")) {
        applyField(scope, `${key}[]`, value);
      }
    });
  };
  const applyVisuals = (scope, settings) => {
    if (!scope || !settings || typeof settings !== "object") return;
    const iconType = String(settings.popup_icon_type || "default");
    const iconValue = String(settings.popup_icon_value || "chat-bubble");
    const avatarType = String(settings.header_avatar_type || "inherit");
    const avatarValue = String(settings.header_avatar_value || "chat-bubble");
    const fields = {
      popup_icon_default: iconType === "custom" ? "__custom__" : iconValue,
      popup_icon_custom_url: iconType === "custom" ? iconValue : "",
      header_avatar_default: avatarType === "inherit" ? "__inherit__" :
        avatarType === "custom" ? "__custom__" : avatarValue,
      header_avatar_url: avatarType === "custom" ?
        String(settings.header_avatar_url || avatarValue) : "",
    };
    Object.entries(fields).forEach(([name, value]) => applyField(scope, name, value));
  };
  const applyModel = (scope, provider, model) => {
    if (!scope) return false;
    const providerValue = String(provider || "").trim();
    const modelValue = String(model || "").trim();
    const providerSelect = scope.querySelector(".aipkit_chatbot_provider_select");
    if (providerSelect) providerSelect.value = providerValue;
    scope.querySelectorAll(
      '.aipkit_chatbot_model_field select option[data-aipkit-dynamic-option="1"]'
    ).forEach((option) => option.remove());
    const field = providerValue && scope.querySelector(
      `.aipkit_chatbot_model_field[data-provider="${escapeSelectorValue(providerValue)}"]`
    );
    const select = field && field.querySelector("select");
    if (select) {
      const options = Array.from(select.options || []);
      if (!modelValue) {
        if (options.some((option) => String(option.value || "") === "")) {
          select.value = "";
        } else if (options.length) {
          select.selectedIndex = 0;
        } else {
          select.value = "";
        }
      } else {
        const googlePrefix = providerValue === "Google" && modelValue.startsWith("models/");
        const candidates = [modelValue];
        if (providerValue === "Google") {
          candidates.push(googlePrefix ? modelValue.slice(7) : `models/${modelValue}`);
        }
        const match = options.find((option) => candidates.includes(String(option.value || "")));
        if (match) {
          select.value = match.value;
        } else {
          const label = googlePrefix ? modelValue.slice(7) : modelValue;
          const option = new Option(label || modelValue, modelValue, true, true);
          option.dataset.aipkitDynamicOption = "1";
          select.appendChild(option);
          select.value = modelValue;
        }
      }
    }
    syncUnifiedModelSelector(scope);
    return Boolean(select || providerSelect);
  };
  const retarget = (roots, previousBotId, nextBotId) => {
    const fromId = String(previousBotId || "").trim();
    const toId = String(nextBotId || "").trim();
    if (!fromId || !toId || fromId === toId) return;
    const tokens = [
      "aipkit_bot_", "aipkit_trigger_builder_", "aipkit_trigger_list_for_",
      "aipkit_theme_choice_", "aipkit_embed_code_", "aipkit_embed_allowed_domains_",
      "aipkit_reset_confirmation_", "aipkit_delete_confirmation_",
      "aipkit_elevenlabs_voices_json_", "aipkit_elevenlabs_models_json_",
    ];
    const escapedId = fromId.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
    // IDs may share a numeric prefix (6 and 60); only retarget the complete ID.
    const pattern = new RegExp(`(${tokens.join("|")})${escapedId}(?!\\d)`, "g");
    const attributes = ["id", "for", "aria-controls", "aria-labelledby", "data-target", "data-aipkit-segmented-for"];
    const selector = attributes.map((name) => `[${name}]`).join(",");
    const visited = new Set();
    roots.filter(Boolean).forEach((root) => {
      [root, ...root.querySelectorAll(selector)].forEach((target) => {
        if (visited.has(target)) return;
        visited.add(target);
        attributes.forEach((name) => {
          const value = target.getAttribute(name);
          if (value === null) return;
          const next = value.replace(pattern, (_, token) => token + toId);
          if (next !== value) target.setAttribute(name, next);
        });
      });
    });
  };

  return { applyField, applySettings, applyVisuals, applyModel, retarget };
}

/** Owns selected-bot transitions, cache commits and ordered field hydration. */
export function createChatbotSession({
  builder,
  botSelect,
  activeBotId,
  botStateCache,
  sheetOverlay,
  instructionsModal,
  botNameField,
  syncUnifiedModelSelector,
  syncDeleteActionsState,
  storageKey: LAST_CHATBOT_STORAGE_KEY,
  prepareTriggers,
  applyThemeSettings,
  applyDeploymentSettings,
  syncFeatureState
}) {
  let activeBotScopeId = String(activeBotId || "");
  let isApplyingBotSwitchState = false;
  let lastBotSwitchFailureReason = "";
  let lastBotSwitchFailureError = null;
  const BOT_STATE_APPLIED_EVENT = "aipkit:bot-state-applied";
  const setBotSwitchFailure = (reason = "", error = null) => {
    lastBotSwitchFailureReason = String(reason || "").trim();
    lastBotSwitchFailureError = error || null;
    return false;
  };
  const clearBotSwitchFailure = () => {
    lastBotSwitchFailureReason = "";
    lastBotSwitchFailureError = null;
  };
  const dispatchBotStateApplied = (botId, botState, source = "switch") => {
    builder.dispatchEvent(new CustomEvent(BOT_STATE_APPLIED_EVENT, {
      detail: {
        botId: String(botId || "").trim(),
        botState: botState || null,
        source: String(source || "switch").trim() || "switch"
      }
    }));
  };
  const bindBotStateAppliedReset = resetFn => {
    if (typeof resetFn !== "function") {
      return;
    }
    builder.addEventListener(BOT_STATE_APPLIED_EVENT, () => {
      resetFn();
    });
  };
  const stateHydration = createChatbotStateHydration({
    syncUnifiedModelSelector
  });
  const handleBotSaveSuccess = (botId, response, options = {}) => {
    const normalizedBotId = String(botId || response?.bot?.bot_id || "").trim();
    let savedState;
    if (options.settings) {
      const cached = botStateCache.get(normalizedBotId);
      const settings = {
        ...cached?.settings
      };
      for (const [key, value] of Object.entries(options.settings)) {
        settings[key] = response?.bot?.settings?.[key] ?? value;
      }
      const state = {
        ...cached,
        settings
      };
      if (Object.prototype.hasOwnProperty.call(options, "botName")) {
        state.bot_name = options.botName;
      }
      for (const key of [ "deploy_mode", "embed_allowed_domains" ]) {
        if (Object.prototype.hasOwnProperty.call(options.settings, key)) {
          state[key] = response?.bot?.[key] ?? settings[key];
        }
      }
      if (Object.prototype.hasOwnProperty.call(options.settings, "conversation_starters")) {
        state.conversation_starters_text = response?.bot?.conversation_starters_text ?? String(options.settings.conversation_starters || "");
      }
      savedState = cached ? botStateCache.store(state) : null;
    } else {
      savedState = botStateCache.syncResponse(response);
    }
    const previewOptions = options.previewOptions && typeof options.previewOptions === "object" ? options.previewOptions : {
      showLoading: false
    };
    if (options.refreshPreview === false) {
      return savedState;
    }
    if (normalizedBotId && typeof window.aipkit_refreshChatPreview === "function") {
      window.aipkit_refreshChatPreview(normalizedBotId, previewOptions);
    }
    return savedState;
  };
  botStateCache.seed();
  const applyBotSwitchState = (botId, botState, options = {}) => {
    const normalizedBotId = String(botId || "").trim();
    if (!normalizedBotId || !botState) {
      return false;
    }
    const applySource = String(options.source || "switch").trim() || "switch";
    const shouldPersistLastSelected = options.persistLastSelected !== false;
    const shouldSyncTrainingUi = options.syncTrainingUi !== false;
    const shouldUpdatePreview = options.updatePreview !== false;
    const previewOptions = options.previewOptions && typeof options.previewOptions === "object" ? {
      ...options.previewOptions
    } : null;
    const form = builder.querySelector("form.aipkit_chatbot_settings_form");
    if (!form) {
      return false;
    }
    const triggersJson = prepareTriggers(normalizedBotId, botState.triggers_json, applySource) ?? botState.triggers_json;
    const previousScopeId = String(activeBotScopeId || form.dataset.botId || normalizedBotId).trim();
    if (previousScopeId && previousScopeId !== normalizedBotId) {
      stateHydration.retarget([ builder, sheetOverlay, instructionsModal ], previousScopeId, normalizedBotId);
    }
    activeBotScopeId = normalizedBotId;
    builder.setAttribute("data-active-bot-id", normalizedBotId);
    form.dataset.botId = normalizedBotId;
    if (botSelect && String(botSelect.value || "") !== normalizedBotId) {
      botSelect.value = normalizedBotId;
    }
    const settings = botState.settings && typeof botState.settings === "object" ? botState.settings : {};
    stateHydration.applySettings(builder, settings);
    applyThemeSettings(settings);
    stateHydration.applyVisuals(builder, settings);
    stateHydration.applyModel(builder, settings.provider, settings.model);
    if (typeof window.aipkit_toggleReasoningEffort === "function") {
      window.aipkit_toggleReasoningEffort(builder, settings.reasoning_effort);
    }
    if (botNameField) {
      botNameField.value = botState.bot_name || "";
    }
    stateHydration.applyField(builder, "conversation_starters", botState.conversation_starters_text);
    stateHydration.applyField(builder, "triggers_json", triggersJson);
    window.aipkit_syncTriggerBuilderScope?.(builder, normalizedBotId, triggersJson);
    stateHydration.applyField(builder, "embed_allowed_domains", botState.embed_allowed_domains);
    applyDeploymentSettings(normalizedBotId, botState, settings);
    syncDeleteActionsState(normalizedBotId);
    const chatbotSettingsArea = builder.querySelector(".aipkit_chatbot-settings-area");
    if (chatbotSettingsArea) {
      if (typeof window.aipkit_toggleChatbotModelFields === "function") {
        window.aipkit_toggleChatbotModelFields(chatbotSettingsArea);
      }
      if (typeof window.aipkit_toggleOpenAISpecificSettingsVisibility === "function") {
        window.aipkit_toggleOpenAISpecificSettingsVisibility(chatbotSettingsArea);
      }
    }
    if (typeof window.aipkit_refreshChatSelectPickers === "function") {
      window.aipkit_refreshChatSelectPickers();
    }
    syncUnifiedModelSelector(builder);
    if (typeof window.aipkit_refreshChatEmbeddingPickers === "function") {
      window.aipkit_refreshChatEmbeddingPickers();
    }
    syncFeatureState(botState, shouldSyncTrainingUi);
    dispatchBotStateApplied(normalizedBotId, botState, applySource);
    if (shouldPersistLastSelected) {
      try {
        localStorage.setItem(LAST_CHATBOT_STORAGE_KEY, normalizedBotId);
      } catch (error) {
        // Ignore storage failures silently.
      }
    }
    if (shouldUpdatePreview) {
      if (typeof window.aipkit_showChatPreview === "function") {
        window.aipkit_showChatPreview(normalizedBotId, previewOptions || {});
      } else if (typeof window.aipkit_refreshChatPreview === "function") {
        window.aipkit_refreshChatPreview(normalizedBotId, previewOptions || {});
      }
    }
    return true;
  };
  const switchToBotState = async (botId, options = {}) => {
    const normalizedBotId = String(botId || "").trim();
    clearBotSwitchFailure();
    if (!normalizedBotId || normalizedBotId === "__new__") {
      return setBotSwitchFailure("invalid_bot");
    }
    if (isApplyingBotSwitchState) {
      return setBotSwitchFailure("switch_in_progress");
    }
    if (botStateCache.get(normalizedBotId)) {
      if (botSelect && String(botSelect.value || "") !== normalizedBotId) {
        return setBotSwitchFailure("selection_changed");
      }
      isApplyingBotSwitchState = true;
      try {
        const applied = applyBotSwitchState(normalizedBotId, botStateCache.get(normalizedBotId), {
          previewOptions: options.previewOptions
        });
        return applied || setBotSwitchFailure("apply_failed");
      } catch (error) {
        return setBotSwitchFailure("apply_failed", error);
      } finally {
        isApplyingBotSwitchState = false;
      }
    }
    let fetchedState = null;
    try {
      fetchedState = await botStateCache.fetch(normalizedBotId);
    } catch (error) {
      return setBotSwitchFailure("fetch_failed", error);
    }
    if (!fetchedState) {
      return setBotSwitchFailure("missing_state");
    }
    if (botSelect && String(botSelect.value || "") !== normalizedBotId) {
      return setBotSwitchFailure("selection_changed");
    }
    isApplyingBotSwitchState = true;
    try {
      const applied = applyBotSwitchState(normalizedBotId, fetchedState, {
        previewOptions: options.previewOptions
      });
      return applied || setBotSwitchFailure("apply_failed");
    } catch (error) {
      return setBotSwitchFailure("apply_failed", error);
    } finally {
      isApplyingBotSwitchState = false;
    }
  };
  return {
    stateHydration,
    handleBotSaveSuccess,
    switchToBotState,
    bindBotStateAppliedReset,
    getBotSwitchFailure: () => ({
      reason: lastBotSwitchFailureReason,
      error: lastBotSwitchFailureError
    })
  };
}
