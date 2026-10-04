import { createChatbotStateCache } from "./state.js";
import { ensureChatbotAudioData, removeChatbotAudioData } from "./audio.js";
import { bindChatbotSettingsAutosave } from "./state.js";

/** Chatbot naming, creation, duplication, reset and deletion controls. */
const bindings = new WeakMap();

export function bindChatbotName({
  builder, botNameField, botSelect, saveStatus, persistence, getSavedName, updateAvailableBotsDataset,
}) {
  if (!botNameField || !saveStatus) return;
  const resolvedName = (settings, response) => String(
    response?.bot?.bot_name || response?.new_name || settings.new_name
  ).trim();
  const confirmedName = (settings, response) => ({ new_name: resolvedName(settings, response) });
  bindChatbotSettingsAutosave({
    builder, panel: botNameField, boundKey: "botNameAutosaveBound", action: "aipkit_rename_chatbot",
    readSettings: () => ({ new_name: botNameField.value.trim() }),
    isUnchanged: (a, b) => a.new_name === b.new_name,
    canSave: settings => Boolean(settings.new_name),
    restoreSettings: settings => { botNameField.value = settings.new_name; },
    savedSettings: confirmedName, resetSettings: confirmedName, confirmedSettings: confirmedName,
    interactionNodes: () => [botNameField],
    persistence: {
      ...persistence,
      handleBotSaveSuccess(botId, response, options) {
        const name = options.settings.new_name;
        // A rename owns record identity, not the settings in a full server reply.
        persistence.handleBotSaveSuccess(botId, response, { ...options, settings: {}, botName: name });
        const option = Array.from(botSelect?.options || []).find(option =>
          String(option.value || "").trim() === String(botId || "").trim());
        if (option) option.text = name;
        updateAvailableBotsDataset();
      },
    },
    onSaved: (settings, response) => {
      botNameField.value = resolvedName(settings, response);
      persistence.setSavedStatus(response);
    },
    bindEvents: ({ signal, isEditable, draft, save }) => {
      const editable = () => isEditable() && !botNameField.disabled && !botNameField.closest('[inert]');
      botNameField.addEventListener("input", () => { if (editable()) draft(); }, { signal });
      botNameField.addEventListener("keydown", event => {
        if (event.key === "Enter" && editable()) {
          event.preventDefault();
          botNameField.blur();
        }
      }, { signal });
      botNameField.addEventListener("blur", () => {
        if (!editable()) return;
        if (!botNameField.value.trim()) {
          botNameField.value = String(getSavedName(persistence.getSelectedBuilderBotId()) || "").trim();
          draft();
          save();
          persistence.setSaveStatus("Name cannot be empty.", "error");
          return;
        }
        save();
      }, { signal });
    },
  });
}

export function bindChatbotActions({
  builder, botSelect, newBotBtn, botActions, botActionsTrigger, botActionsMenu,
  __, setSaveStatus, setSavedStatus, setSaveErrorStatus,
  applyInsertedBotResponse, getCurrentActiveBotId, syncDeleteActionsState,
  isDefaultBotId, applyBotStateResponse, getPreferredBotAfterDeleteUi,
  applyDeleteBotInPlace, storageKey,
  withRecordAction = (_action, _id, request) => request(),
}) {
  if (bindings.has(builder)) return bindings.get(builder);
  if (!botSelect && !newBotBtn && !(botActions && botActionsTrigger && botActionsMenu)) return null;
  const controller = new AbortController();
  let disposed = false;
  let generation = 0;
  let busy = false;
  let confirmation = null;
  let confirmationTimer = null;
  const alive = () => !disposed && builder.isConnected;
  const buttons = () => Array.from(botActionsMenu?.querySelectorAll('[data-aipkit-bot-action]') || []);
  const labelFor = button => button.querySelector('.aipkit_widget_bot_actions_label') || button;
  const labels = new Map(buttons().map(button => [button, labelFor(button).textContent.trim()]));
  const listen = (node, event, callback) => node?.addEventListener(event, callback, { signal: controller.signal });
  const resetConfirmation = () => {
    window.clearTimeout(confirmationTimer);
    confirmationTimer = null;
    confirmation = null;
    labels.forEach((label, button) => {
      labelFor(button).textContent = label;
      button.classList.remove('is-confirming');
      button.removeAttribute('data-confirming');
    });
  };
  const closeMenu = () => {
    botActionsTrigger?.setAttribute('aria-expanded', 'false');
    if (botActionsMenu) botActionsMenu.hidden = true;
    botActions?.classList.remove('is-open');
    resetConfirmation();
  };
  const setBusy = value => {
    busy = value;
    if (newBotBtn) newBotBtn.disabled = value;
    if (botActionsTrigger) botActionsTrigger.disabled = value;
    buttons().forEach(button => {
      button.disabled = value;
      button.setAttribute('aria-disabled', value ? 'true' : 'false');
    });
    if (!value && botActions) syncDeleteActionsState(getCurrentActiveBotId());
  };
  const persistSelection = id => {
    try {
      if (id) localStorage.setItem(storageKey, id);
      else localStorage.removeItem(storageKey);
    } catch { /* Storage may be unavailable. */ }
  };
  const canRequest = () => alive() && !busy && typeof window.aipkit_apiRequest === 'function';
  const run = async (action, botId, name = '') => {
    if (!canRequest()) return;
    const scope = generation;
    const current = () => alive() && generation === scope && String(getCurrentActiveBotId() || '') === String(botId || '');
    const preferredNextId = action === 'delete' ? getPreferredBotAfterDeleteUi(botId) : '';
    closeMenu();
    setBusy(true);
    try {
      const request = () => {
        if (!alive()) throw new Error('Chatbot editor removed.');
        setSaveStatus(action === 'create' ? __('Creating chatbot...', 'gpt3-ai-content-generator') : {
          duplicate: 'Duplicating...', reset: 'Resetting...', delete: 'Deleting...',
        }[action], '');
        return window.aipkit_apiRequest({
          create: 'aipkit_create_chatbot', duplicate: 'aipkit_duplicate_chatbot',
          reset: 'aipkit_reset_chatbot_settings', delete: 'aipkit_delete_chatbot',
        }[action], action === 'create' ? { bot_name: name } : { bot_id: botId });
      };
      const response = await (action === 'create' ? request() : withRecordAction(action, botId, request));
      if (!alive()) return;
      const activate = current();
      if (action === 'create' && !response?.bot_id) {
        throw new Error(__('Chatbot created but no bot ID was returned.', 'gpt3-ai-content-generator'));
      }
      if (activate) {
        if (action === 'create') {
          setSaveStatus(response.message || __('Chatbot created successfully.', 'gpt3-ai-content-generator'), 'success');
        } else setSavedStatus(response, action === 'duplicate' ? 'Duplicated' : action === 'delete' ? 'Deleted' : 'Saved');
      }
      if (action === 'create' || action === 'duplicate') {
        const id = String(response?.bot_id || '');
        if (activate && id) persistSelection(id);
        await applyInsertedBotResponse(response, action === 'create' ? name : response?.bot_name || '', { activate });
      } else if (action === 'reset') {
        await applyBotStateResponse(botId, response?.bot || null, { invalidatePreview: true, activate });
      } else {
        if (activate) persistSelection(preferredNextId);
        await applyDeleteBotInPlace(botId, preferredNextId, { activate });
      }
    } catch (error) {
      if (!current()) return;
      if (action === 'create') {
        setSaveStatus(`Error: ${error.message || __('Failed to create chatbot.', 'gpt3-ai-content-generator')}`, 'error');
      } else setSaveErrorStatus(error, { duplicate: 'Failed to duplicate.', reset: 'Failed to reset.', delete: 'Failed to delete.' }[action]);
    } finally {
      if (alive()) setBusy(false);
    }
  };
  const openCreate = () => {
    if (!canRequest()) return;
    const scope = generation;
    const opener = document.activeElement instanceof HTMLElement ? document.activeElement : newBotBtn;
    const restoreFocus = () => { if (alive() && generation === scope && opener?.isConnected) opener.focus(); };
    const create = name => {
      const value = String(name || '').trim();
      if (value && generation === scope) return run('create', getCurrentActiveBotId(), value);
    };
    if (typeof window.aipkit_showPromptModal === 'function') {
      window.aipkit_showPromptModal({
        title: __('Create chatbot', 'gpt3-ai-content-generator'),
        message: __('Give your chatbot a name. You can change it later.', 'gpt3-ai-content-generator'),
        label: __('Chatbot name', 'gpt3-ai-content-generator'),
        placeholder: __('e.g. Customer Support', 'gpt3-ai-content-generator'),
        inputName: 'aipkit_new_chatbot_name', maxLength: 100,
        confirmText: __('Create chatbot', 'gpt3-ai-content-generator'),
        cancelText: __('Cancel', 'gpt3-ai-content-generator'),
        requiredMessage: __('Enter a chatbot name.', 'gpt3-ai-content-generator'),
        onConfirm: create, onCancel: restoreFocus,
      });
    } else {
      const name = window.prompt(__('Chatbot name', 'gpt3-ai-content-generator'), '');
      if (name !== null && name.trim()) create(name);
      else restoreFocus();
    }
  };
  const confirmAction = (action, id, button) => {
    if (confirmation?.action === action && confirmation.id === id) return true;
    resetConfirmation();
    confirmation = { action, id };
    button.classList.add('is-confirming');
    button.setAttribute('data-confirming', 'true');
    labelFor(button).textContent = action === 'delete'
      ? __('Click again to delete', 'gpt3-ai-content-generator')
      : __('Click again to restore', 'gpt3-ai-content-generator');
    confirmationTimer = window.setTimeout(resetConfirmation, 4500);
    return false;
  };
  const changeScope = () => {
    generation += 1;
    closeMenu();
  };
  const dispose = () => {
    if (disposed) return;
    disposed = true;
    controller.abort();
    observer.disconnect();
    closeMenu();
    bindings.delete(builder);
  };
  const observer = new MutationObserver(() => { if (!builder.isConnected) dispose(); });
  listen(newBotBtn, 'click', openCreate);
  if (botActions && botActionsTrigger && botActionsMenu) {
    listen(botActionsTrigger, 'click', event => {
      event.preventDefault();
      if (!alive() || busy) return;
      if (!botActionsMenu.hidden) closeMenu();
      else {
        botActionsTrigger.setAttribute('aria-expanded', 'true');
        botActionsMenu.hidden = false;
        botActions.classList.add('is-open');
      }
    });
    listen(botActionsMenu, 'click', event => {
      const button = event.target.closest('[data-aipkit-bot-action]');
      if (!button || !botActionsMenu.contains(button)) return;
      event.preventDefault();
      event.stopPropagation();
      const action = button.dataset.aipkitBotAction;
      const id = getCurrentActiveBotId();
      if (!canRequest() || !id || button.disabled || !['duplicate', 'reset', 'delete'].includes(action)) return;
      if (action === 'delete' && isDefaultBotId(id)) return;
      if (action !== 'duplicate' && !confirmAction(action, id, button)) return;
      void run(action, id);
    });
    listen(document, 'click', event => { if (!botActions.contains(event.target)) closeMenu(); });
    listen(document, 'keydown', event => {
      if (event.key === 'Escape' && !botActionsMenu.hidden) {
        closeMenu();
        if (alive()) botActionsTrigger.focus();
      }
    });
  }
  setBusy(false);
  listen(botSelect, 'change', changeScope);
  listen(builder, 'aipkit:bot-state-applied', changeScope);
  observer.observe(builder.ownerDocument.documentElement, { childList: true, subtree: true });
  const binding = { openCreate, signal: controller.signal };
  bindings.set(builder, binding);
  return binding;
}

/** Owns the bot catalog and applies create/reset/delete responses to it. */
export function createChatbotCatalog({builder, botSelect, __, switchToBotState, storageKey: LAST_CHATBOT_STORAGE_KEY}) {
  let pendingBotSwitchPreviewOptions = null;
  const queueBotSwitchPreviewOptions = (options = null) => {
    if (options && typeof options === "object") {
      pendingBotSwitchPreviewOptions = {
        ...options
      };
      return;
    }
    pendingBotSwitchPreviewOptions = null;
  };
  const consumeBotSwitchPreviewOptions = () => {
    if (!pendingBotSwitchPreviewOptions || typeof pendingBotSwitchPreviewOptions !== "object") {
      pendingBotSwitchPreviewOptions = null;
      return null;
    }
    const previewOptions = {
      ...pendingBotSwitchPreviewOptions
    };
    pendingBotSwitchPreviewOptions = null;
    return previewOptions;
  };
  const botSwitcher = builder.querySelector(".aipkit_widget_bot_switcher");
  const getSelectableBotIds = () => {
    if (!botSelect) {
      return [];
    }
    return Array.from(botSelect.options || []).map(option => String(option && option.value || "").trim()).filter(value => value && value !== "__new__");
  };
  const syncBotSwitcherVisibility = () => {
    if (!botSwitcher || !botSelect) {
      return;
    }
    botSwitcher.hidden = getSelectableBotIds().length < 2;
  };
  const updateAvailableBotsDataset = () => {
    const availableBotsNode = document.getElementById("aipkit_available_bots_json");
    if (!botSelect) {
      return;
    }
    const botList = Array.from(botSelect.options || []).filter(option => option && option.value && option.value !== "__new__" && !option.disabled).map(option => ({
      id: parseInt(option.value, 10),
      title: (option.textContent || "").trim()
    })).filter(entry => Number.isFinite(entry.id) && entry.id > 0);
    if (availableBotsNode) {
      availableBotsNode.dataset.bots = JSON.stringify(botList);
    }
    syncBotSwitcherVisibility();
  };
  syncBotSwitcherVisibility();
  const insertNewBotIntoUi = (botId, botName) => {
    const normalizedBotId = String(botId || "").trim();
    const normalizedBotName = String(botName || "").trim();
    if (!normalizedBotId || !normalizedBotName || !botSelect) {
      return false;
    }
    const existingOption = Array.from(botSelect.options || []).find(option => String(option.value || "").trim() === normalizedBotId);
    if (existingOption) {
      existingOption.textContent = normalizedBotName;
      ensureChatbotAudioData(normalizedBotId);
      updateAvailableBotsDataset();
      return true;
    }
    const newOption = new Option(normalizedBotName, normalizedBotId);
    const realOptions = Array.from(botSelect.options || []).filter(option => option && option.value && option.value !== "__new__" && !option.disabled);
    const insertBeforeOption = realOptions.find(option => {
      const optionValue = String(option.value || "").trim();
      if (!optionValue) {
        return false;
      }
      const defaultBotId = String(builder.getAttribute("data-default-bot-id") || "").trim();
      if (optionValue === defaultBotId) {
        return false;
      }
      return normalizedBotName.localeCompare((option.textContent || "").trim(), undefined, {
        sensitivity: "base"
      }) < 0;
    });
    if (insertBeforeOption) {
      botSelect.insertBefore(newOption, insertBeforeOption);
    } else {
      botSelect.appendChild(newOption);
    }
    ensureChatbotAudioData(normalizedBotId);
    updateAvailableBotsDataset();
    return true;
  };
  const getCurrentActiveBotId = () => {
    const candidateBotIds = [ botSelect ? String(botSelect.value || "").trim() : "", String(builder.getAttribute("data-active-bot-id") || "").trim(), String((builder.querySelector("form.aipkit_chatbot_settings_form") || {}).dataset?.botId || "").trim() ];
    const selectableBotIds = getSelectableBotIds();
    for (const candidate of candidateBotIds) {
      if (!candidate || candidate === "__new__") {
        continue;
      }
      if (!/^\d+$/.test(candidate)) {
        continue;
      }
      if (!selectableBotIds.length || selectableBotIds.includes(candidate)) {
        return candidate;
      }
    }
    return selectableBotIds[0] || "";
  };
  const isDefaultBotId = botId => {
    const normalizedBotId = String(botId || "").trim();
    const defaultBotId = String(builder.getAttribute("data-default-bot-id") || "").trim();
    if (!normalizedBotId || !defaultBotId) {
      return false;
    }
    return normalizedBotId === defaultBotId;
  };
  const syncDeleteActionsState = (botId = "") => {
    const normalizedBotId = String(botId || getCurrentActiveBotId() || "").trim();
    const shouldDisableDelete = !normalizedBotId || isDefaultBotId(normalizedBotId);
    const deleteTooltip = shouldDisableDelete ? __("Default bot cannot be deleted.", "gpt3-ai-content-generator") : __("Delete chatbot", "gpt3-ai-content-generator");
    document.querySelectorAll(".aipkit_widget_bot_delete_btn").forEach(item => {
      if (typeof item.disabled === "boolean") {
        item.disabled = shouldDisableDelete;
      } else if (shouldDisableDelete) {
        item.setAttribute("disabled", "disabled");
      } else {
        item.removeAttribute("disabled");
      }
      item.setAttribute("aria-disabled", shouldDisableDelete ? "true" : "false");
      item.setAttribute("title", deleteTooltip);
    });
    return shouldDisableDelete;
  };
  const isBotCurrentlyActive = botId => String(botId || "").trim() !== "" && String(getCurrentActiveBotId() || "").trim() === String(botId || "").trim();
  const getPreferredBotAfterDeleteUi = deletedBotId => {
    const deletedId = String(deletedBotId || "");
    const selectableBotIds = Array.from(botSelect && botSelect.options || []).map(option => String(option && option.value || "")).filter(value => value && value !== "__new__");
    if (!selectableBotIds.length) {
      return "";
    }
    const currentIndex = selectableBotIds.indexOf(deletedId);
    const defaultBotId = String(builder.getAttribute("data-default-bot-id") || "").trim();
    if (currentIndex === -1) {
      if (defaultBotId && defaultBotId !== deletedId) {
        return defaultBotId;
      }
      return selectableBotIds[0] || "";
    }
    for (let index = currentIndex - 1; index >= 0; index -= 1) {
      const previousId = selectableBotIds[index];
      if (previousId && previousId !== deletedId) {
        return previousId;
      }
    }
    for (let index = currentIndex + 1; index < selectableBotIds.length; index += 1) {
      const nextId = selectableBotIds[index];
      if (nextId && nextId !== deletedId) {
        return nextId;
      }
    }
    if (defaultBotId && defaultBotId !== deletedId) {
      return defaultBotId;
    }
    return "";
  };
  const removeBotFromUi = botId => {
    const normalizedBotId = String(botId || "").trim();
    if (!normalizedBotId) {
      return false;
    }
    let removed = false;
    if (botSelect) {
      const optionToRemove = Array.from(botSelect.options || []).find(option => String(option.value || "").trim() === normalizedBotId);
      if (optionToRemove) {
        optionToRemove.remove();
        removed = true;
      }
    }
    removeChatbotAudioData(normalizedBotId);
    updateAvailableBotsDataset();
    return removed;
  };
  const applyBotStateResponse = (botId, botState, options = {}) => {
    const normalizedBotId = String(botId || "").trim();
    const cachedState = botState ? botStateCache.store(botState) : null;
    const previewOptions = options.previewOptions && typeof options.previewOptions === "object" ? {
      ...options.previewOptions
    } : {
      showLoading: false
    };
    if (!normalizedBotId || !cachedState) {
      return Promise.resolve(false);
    }
    if (options.invalidatePreview && typeof window.aipkit_invalidateChatPreviewCache === "function") {
      window.aipkit_invalidateChatPreviewCache(normalizedBotId);
    }
    if (options.activate === false) return Promise.resolve(true);
    if (botSelect) {
      queueBotSwitchPreviewOptions(previewOptions);
      botSelect.value = normalizedBotId;
      botSelect.dispatchEvent(new Event("change", {
        bubbles: true
      }));
      return Promise.resolve(true);
    }
    return Promise.resolve(switchToBotState(normalizedBotId, {
      previewOptions
    }));
  };
  const applyInsertedBotResponse = (response, fallbackName = "", options = {}) => {
    const botState = botStateCache.syncResponse(response);
    const botId = String(botState && botState.bot_id || response && response.bot_id || "").trim();
    const botName = String(botState && botState.bot_name || response && response.bot_name || fallbackName || "").trim();
    const previewOptions = options.previewOptions && typeof options.previewOptions === "object" ? {
      ...options.previewOptions
    } : {
      showLoading: false
    };
    if (!botId || !botName) {
      return Promise.resolve(false);
    }
    insertNewBotIntoUi(botId, botName);
    if (typeof window.aipkit_invalidateChatPreviewCache === "function") {
      window.aipkit_invalidateChatPreviewCache(botId);
    }
    if (options.activate === false) return Promise.resolve(true);
    if (botSelect) {
      queueBotSwitchPreviewOptions(previewOptions);
      botSelect.value = botId;
      botSelect.dispatchEvent(new Event("change", {
        bubbles: true
      }));
      return Promise.resolve(true);
    }
    return Promise.resolve(switchToBotState(botId, {
      previewOptions
    }));
  };
  const applyDeleteBotInPlace = (botId, preferredNextBotId = "", options = {}) => {
    const normalizedBotId = String(botId || "").trim();
    if (!normalizedBotId) {
      return Promise.resolve(false);
    }
    const nextBotId = String(preferredNextBotId || "").trim() || getPreferredBotAfterDeleteUi(normalizedBotId);
    botStateCache.remove(normalizedBotId);
    removeBotFromUi(normalizedBotId);
    if (options.activate === false) return Promise.resolve(true);
    if (nextBotId && nextBotId !== normalizedBotId) {
      try {
        localStorage.setItem(LAST_CHATBOT_STORAGE_KEY, nextBotId);
      } catch (error) {
        // Ignore storage failures silently.
      }
      if (botSelect) {
        botSelect.value = nextBotId;
        botSelect.dispatchEvent(new Event("change", {
          bubbles: true
        }));
        return Promise.resolve(true);
      }
      return Promise.resolve(switchToBotState(nextBotId));
    }
    try {
      localStorage.removeItem(LAST_CHATBOT_STORAGE_KEY);
    } catch (error) {
      // Ignore storage failures silently.
    }
    builder.setAttribute("data-active-bot-id", "");
    if (botSelect) {
      botSelect.value = "";
    }
    syncDeleteActionsState("");
    window.aipkit_showChatPreview("", {
      placeholder: window.aipkit_dashboard?.text?.noBotsPlaceholder || "No chatbots found. Create one to get started!"
    });
    return Promise.resolve(false);
  };
  const botStateCache = createChatbotStateCache({
    builder,
    getSelectableBotIds
  });
  return {
    consumeBotSwitchPreviewOptions,
    getSelectableBotIds,
    updateAvailableBotsDataset,
    getCurrentActiveBotId,
    isDefaultBotId,
    syncDeleteActionsState,
    isBotCurrentlyActive,
    getPreferredBotAfterDeleteUi,
    applyBotStateResponse,
    applyInsertedBotResponse,
    applyDeleteBotInPlace,
    botStateCache
  };
}
