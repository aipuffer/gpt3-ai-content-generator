/**
 * Knowledge picker: one control for where knowledge is stored, laid out like the model picker.
 *
 * Providers sit on the left and their knowledge bases on the right. The picker owns no saved state:
 * it reads and writes the standard fields beside it (vector_store_provider and each provider's store
 * select) and fires change on them, so the screen's own saving and visibility rules still apply.
 */

import { syncVectorStoreSelectionValues } from "../utils/vector-store-selection-state.js";

const STORE_SELECTS = {
  local: 'select[name="local_store_ids[]"]',
  openai: 'select[name="openai_vector_store_ids[]"]',
  google: 'select[name="google_file_search_store_names[]"]',
  pinecone: 'select[name="pinecone_index_name"]',
  qdrant: 'select[name="qdrant_collection_names[]"]',
  chroma: 'select[name="chroma_collection_names[]"]',
};

// OpenAI file search accepts two stores per request.
const STORE_LIMITS = { openai: 2 };

const LOGO_SLUGS = {
  local: "local",
  openai: "openai",
  google: "google",
  pinecone: "pinecone",
  qdrant: "qdrant",
  chroma: "chroma",
  claude_files: "claude",
};

const ALL = "__all";

/**
 * "Name (25 chunks)" → { name: "Name", meta: "25 chunks" }. Knowledge bases created with a first source
 * carry a random tag after "knowledge" to keep them unique; it is left out of what people read.
 */
export const splitStoreLabel = (text) => {
  const label = String(text || "").trim();
  const match = label.match(/^(.*?)\s*\(([^()]*)\)\s*$/);
  const parts = match && match[1] ? { name: match[1], meta: match[2] } : { name: label, meta: "" };
  parts.name = parts.name.replace(/(\bknowledge) [a-z0-9]{6,10}$/i, "$1");
  return parts;
};

export function bindKnowledgePicker(root, {
  settingsArea,
  stateTarget = settingsArea,
  __ = (text) => text,
  isConfigured = () => true,
  lockedReason = () => "",
} = {}) {
  if (!root || !settingsArea || root.dataset.knowledgePickerBound) {
    return null;
  }
  root.dataset.knowledgePickerBound = "1";
  const controller = new AbortController();
  const listen = (target, type, handler, options = {}) => target?.addEventListener(type, handler, { ...options, signal: controller.signal });
  let disposed = false;
  let focusFrame = null;

  const trigger = root.querySelector("[data-aipkit-knowledge-picker-trigger]");
  const popover = root.querySelector("[data-aipkit-knowledge-picker-popover]");
  const nameNode = root.querySelector("[data-aipkit-knowledge-picker-name]");
  const metaNode = root.querySelector("[data-aipkit-knowledge-picker-meta]");
  const search = root.querySelector("[data-aipkit-knowledge-picker-search]");
  const rail = root.querySelector("[data-aipkit-knowledge-picker-providers]");
  const list = root.querySelector("[data-aipkit-knowledge-picker-list]");
  const hint = root.querySelector("[data-aipkit-knowledge-picker-hint]");
  const providerSelect = () => settingsArea.querySelector('[name="vector_store_provider"]');
  const enableToggle = () => settingsArea.querySelector(".aipkit_vector_store_enable_select");
  let activeProvider = ALL;

  const providerLabels = {
    local: __("Built-in", "gpt3-ai-content-generator"),
    openai: "OpenAI",
    google: "Google",
    pinecone: "Pinecone",
    qdrant: "Qdrant",
    chroma: "Chroma",
    claude_files: "Anthropic Files",
  };

  const storesFor = (key) => {
    const select = STORE_SELECTS[key] ? settingsArea.querySelector(STORE_SELECTS[key]) : null;
    if (!select) {
      return [];
    }
    return Array.from(select.options)
      .filter((option) => option.value)
      .map((option) => {
        const { name, meta } = splitStoreLabel(option.textContent);
        const missing = option.disabled && option.dataset.aipkitPreservedSelection === "1";
        return {
          value: option.value,
          name: missing ? option.value : name,
          meta: missing ? __("Not found", "gpt3-ai-content-generator") : meta,
          selected: option.selected,
          missing,
        };
      });
  };

  /** Providers in the order of the saved select, with what a person can do with each. */
  const providers = () => {
    const select = providerSelect();
    if (!select) {
      return [];
    }
    return Array.from(select.options).map((option) => {
      const key = option.value;
      const reason = option.disabled ? lockedReason(key) || __("Not available with this model", "gpt3-ai-content-generator") : "";
      return {
        key,
        label: providerLabels[key] || option.textContent.trim(),
        locked: Boolean(reason),
        reason,
        configured: isConfigured(key),
        hasStores: Boolean(STORE_SELECTS[key]),
        stores: storesFor(key),
      };
    });
  };

  const currentSelection = () => {
    const key = providerSelect()?.value || "";
    const stores = storesFor(key).filter((store) => store.selected);
    return { key, stores };
  };

  const logo = (key) => {
    const span = document.createElement("span");
    span.className = `aipkit_unified_model_logo aipkit_settings_select_picker_icon--${LOGO_SLUGS[key] || "local"}`;
    span.setAttribute("aria-hidden", "true");
    return span;
  };

  const updateTrigger = () => {
    const { key, stores } = currentSelection();
    const enabled = enableToggle()?.checked;
    const label = providerLabels[key] || key;
    if (!enabled || (STORE_SELECTS[key] && !stores.length)) {
      nameNode.textContent = __("Choose where knowledge is stored", "gpt3-ai-content-generator");
      metaNode.textContent = enabled ? label : "";
      return;
    }
    if (!STORE_SELECTS[key]) {
      nameNode.textContent = label;
      metaNode.textContent = __("Files shared in the chat", "gpt3-ai-content-generator");
      return;
    }
    nameNode.textContent = stores.map((store) => store.name).join(", ");
    metaNode.textContent = [label, stores.length === 1 ? stores[0].meta : ""].filter(Boolean).join(" · ");
  };

  // Turns knowledge on, switches provider if needed, then marks the chosen stores; each step fires change.
  const choose = (key, value = "", additive = false) => {
    const storeSelect = STORE_SELECTS[key] ? settingsArea.querySelector(STORE_SELECTS[key]) : null;
    const chosen = storesFor(key).find((store) => store.value === value);
    const current = storesFor(key).filter((store) => store.selected).map((store) => store.value);
    // Missing selections are removable, never newly selectable. Remove the preserved option too,
    // otherwise saved-value readers and the next catalog refresh would restore it.
    if (chosen?.missing) {
      if (chosen.selected && storeSelect) {
        syncVectorStoreSelectionValues(storeSelect, current.filter((item) => item !== value));
        storeSelect.dispatchEvent(new Event("change", { bubbles: true }));
      }
      return;
    }
    const toggle = enableToggle();
    if (toggle && !toggle.checked) {
      toggle.checked = true;
      toggle.dispatchEvent(new Event("change", { bubbles: true }));
    }
    const select = providerSelect();
    if (select && select.value !== key) {
      select.value = key;
      select.dispatchEvent(new Event("change", { bubbles: true }));
    }
    if (storeSelect && value) {
      let values = [value];
      if (additive && storeSelect.multiple) {
        values = current.includes(value) ? current.filter((item) => item !== value) : [...current, value];
        if (STORE_LIMITS[key] && values.length > STORE_LIMITS[key]) {
          values = values.slice(-STORE_LIMITS[key]);
        }
      }
      syncVectorStoreSelectionValues(storeSelect, values);
      storeSelect.dispatchEvent(new Event("change", { bubbles: true }));
    }
    updateTrigger();
  };

  const groupHeader = (label, count) => {
    const header = document.createElement("div");
    header.className = "aipkit_unified_model_family_header aipkit_knowledge_picker_group";
    const name = document.createElement("span");
    name.className = "aipkit_unified_model_family_label";
    name.textContent = label;
    header.appendChild(name);
    if (count !== "") {
      const number = document.createElement("span");
      number.className = "aipkit_unified_model_family_count";
      number.textContent = String(count);
      header.appendChild(number);
    }
    return header;
  };

  const storeRow = (provider, store) => {
    const row = document.createElement("div");
    row.className = "aipkit_unified_model_item_row aipkit_unified_model_item_row--without-favorite";
    row.setAttribute("role", "listitem");
    const item = document.createElement("button");
    item.type = "button";
    item.className = "aipkit_unified_model_item";
    item.dataset.provider = provider.key;
    item.dataset.store = store.value;
    item.disabled = (store.missing && !store.selected) || provider.locked;
    const selected = store.selected && providerSelect()?.value === provider.key && enableToggle()?.checked;
    row.classList.toggle("is-selected", Boolean(selected));
    item.classList.toggle("is-selected", Boolean(selected));
    if (selected) {
      item.setAttribute("aria-current", "true");
    }
    const content = document.createElement("span");
    content.className = "aipkit_unified_model_item_content";
    const name = document.createElement("span");
    name.className = "aipkit_unified_model_item_name";
    name.textContent = store.name;
    content.appendChild(name);
    if (store.meta) {
      const meta = document.createElement("span");
      meta.className = "aipkit_unified_model_item_meta";
      meta.textContent = store.missing && store.selected
        ? __("Unavailable. Click to remove.", "gpt3-ai-content-generator")
        : store.meta;
      content.appendChild(meta);
    }
    item.appendChild(content);
    if (selected) {
      const check = document.createElement("span");
      check.className = "aipkit_unified_model_check dashicons dashicons-yes";
      check.setAttribute("aria-hidden", "true");
      item.appendChild(check);
    }
    row.appendChild(item);
    return row;
  };

  const noteRow = (text, action = null) => {
    const row = document.createElement("div");
    row.className = "aipkit_knowledge_picker_note";
    const copy = document.createElement("span");
    copy.textContent = text;
    row.appendChild(copy);
    if (action) {
      row.appendChild(action);
    }
    return row;
  };

  const connectLink = () => {
    const link = document.createElement("a");
    link.className = "aipkit_knowledge_picker_connect";
    link.href = root.dataset.connectUrl || "#";
    link.dataset.aipkitOpenModule = "settings";
    link.dataset.aipkitSettingsPage = "integrations";
    link.textContent = __("Connect", "gpt3-ai-content-generator");
    return link;
  };

  const providerOnlyRow = (provider) => {
    const row = document.createElement("div");
    row.className = "aipkit_unified_model_item_row aipkit_unified_model_item_row--without-favorite";
    const item = document.createElement("button");
    item.type = "button";
    item.className = "aipkit_unified_model_item";
    item.dataset.provider = provider.key;
    const selected = providerSelect()?.value === provider.key && enableToggle()?.checked;
    item.classList.toggle("is-selected", Boolean(selected));
    row.classList.toggle("is-selected", Boolean(selected));
    const content = document.createElement("span");
    content.className = "aipkit_unified_model_item_content";
    const name = document.createElement("span");
    name.className = "aipkit_unified_model_item_name";
    name.textContent = sprintfLabel(__("Use %s", "gpt3-ai-content-generator"), provider.label);
    const meta = document.createElement("span");
    meta.className = "aipkit_unified_model_item_meta";
    meta.textContent = __("Files shared in the chat", "gpt3-ai-content-generator");
    content.append(name, meta);
    item.appendChild(content);
    row.appendChild(item);
    return row;
  };

  const sprintfLabel = (template, value) => String(template).replace("%s", value);

  const render = () => {
    const term = (search?.value || "").trim().toLowerCase();
    const all = providers();
    const selectedKey = providerSelect()?.value || "";

    rail.replaceChildren();
    const railButton = (key, label, count, extra = {}) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "aipkit_unified_model_provider";
      button.dataset.provider = key;
      button.setAttribute("role", "option");
      const active = activeProvider === key;
      button.classList.toggle("is-active", active);
      button.classList.toggle("aipkit_unified_model_provider--all", key === ALL);
      button.setAttribute("aria-selected", active ? "true" : "false");
      if (extra.muted) {
        button.classList.add("is-muted");
      }
      const icon = key === ALL ? document.createElement("span") : logo(key);
      if (key === ALL) {
        icon.className = "aipkit_unified_model_all_icon dashicons dashicons-screenoptions";
        icon.setAttribute("aria-hidden", "true");
      }
      const name = document.createElement("span");
      name.className = "aipkit_unified_model_provider_name";
      name.textContent = label;
      button.append(icon, name);
      if (count !== "") {
        const badge = document.createElement("span");
        badge.className = "aipkit_unified_model_provider_count";
        badge.textContent = String(count);
        button.appendChild(badge);
      }
      rail.appendChild(button);
    };
    railButton(ALL, __("All", "gpt3-ai-content-generator"), "");
    all.forEach((provider) => {
      railButton(provider.key, provider.label, provider.hasStores && provider.configured && !provider.locked ? provider.stores.length : "", {
        muted: provider.locked || !provider.configured,
      });
    });

    const shown = activeProvider === ALL ? all : all.filter((provider) => provider.key === activeProvider);
    const fragment = document.createDocumentFragment();
    const matches = (store) => !term || `${store.name} ${store.meta}`.toLowerCase().includes(term);
    const usable = shown.filter((provider) => !provider.locked && provider.configured);
    const setup = shown.filter((provider) => !provider.locked && !provider.configured);
    const locked = shown.filter((provider) => provider.locked);
    // The selected provider leads, so the current knowledge base is the first thing seen.
    usable.sort((a, b) => (b.key === selectedKey) - (a.key === selectedKey));

    usable.forEach((provider) => {
      if (!provider.hasStores) {
        if (!term) {
          fragment.appendChild(groupHeader(provider.label, ""));
          fragment.appendChild(providerOnlyRow(provider));
        }
        return;
      }
      const stores = provider.stores.filter(matches);
      if (term && !stores.length) {
        return;
      }
      fragment.appendChild(groupHeader(provider.label, stores.length));
      if (!stores.length) {
        fragment.appendChild(noteRow(provider.key === "local"
          ? __("None yet. Adding your first source creates one.", "gpt3-ai-content-generator")
          : __("No knowledge bases found.", "gpt3-ai-content-generator")));
      }
      stores.forEach((store) => fragment.appendChild(storeRow(provider, store)));
    });
    if (!term && setup.length) {
      fragment.appendChild(groupHeader(__("Not set up", "gpt3-ai-content-generator"), ""));
      setup.forEach((provider) => {
        const row = noteRow(provider.label, connectLink());
        row.prepend(logo(provider.key));
        fragment.appendChild(row);
      });
    }
    if (!term && locked.length) {
      fragment.appendChild(groupHeader(__("Not available with this model", "gpt3-ai-content-generator"), ""));
      locked.forEach((provider) => {
        const reason = document.createElement("span");
        reason.className = "aipkit_knowledge_picker_reason";
        reason.textContent = provider.reason;
        const row = noteRow(provider.label, reason);
        row.prepend(logo(provider.key));
        row.classList.add("is-locked");
        fragment.appendChild(row);
      });
    }
    if (!fragment.childNodes.length) {
      fragment.appendChild(noteRow(__("No knowledge bases found.", "gpt3-ai-content-generator")));
    }
    list.replaceChildren(fragment);

    if (hint) {
      const limit = STORE_LIMITS[selectedKey];
      hint.textContent = limit
        ? __("Shift-click to use two stores.", "gpt3-ai-content-generator")
        : __("Shift-click to use more than one.", "gpt3-ai-content-generator");
    }
  };

  const anchor = document.createComment("aipkit-knowledge-popover-anchor");
  popover.before(anchor);
  const place = () => {
    if (popover.hidden || disposed) return;
    window.aipkit_positionUnifiedPopover({
      selector: root, trigger, popover,
      modelBody: popover.querySelector('.aipkit_unified_model_body'),
      providersList: rail, list,
      mountPopover: () => {
        popover.classList.add('is-portaled');
        if (popover.parentNode !== document.body) document.body.appendChild(popover);
      },
    });
  };
  listen(window, 'resize', place);
  listen(window, 'scroll', event => {
    if (!popover.contains(event.target)) place();
  }, { capture: true });

  const open = () => {
    activeProvider = ALL;
    if (search) {
      search.value = "";
    }
    render();
    popover.hidden = false;
    trigger.setAttribute("aria-expanded", "true");
    root.classList.add("is-open");
    place();
    focusFrame = window.requestAnimationFrame(() => {
      focusFrame = null;
      if (!disposed && root.isConnected && !popover.hidden) search?.focus();
    });
    refreshCatalog(providerSelect()?.value);
  };

  const refreshCatalog = (key) => {
    if (STORE_SELECTS[key] && isConfigured(key)) {
      window.aipkit_refreshVectorStoreProviderList?.(key);
    }
  };

  const refresh = () => {
    if (disposed) return;
    updateTrigger();
    if (!popover.hidden) {
      render();
      place();
    }
  };

  const close = ({ focus = false } = {}) => {
    window.cancelAnimationFrame(focusFrame);
    focusFrame = null;
    if (popover.hidden) {
      return;
    }
    popover.hidden = true;
    anchor.after(popover);
    popover.classList.remove('is-portaled', 'is-compact', 'is-bottom-sheet', 'opens-up');
    popover.removeAttribute('aria-modal');
    popover.removeAttribute('style');
    popover.querySelector('.aipkit_unified_model_body')?.removeAttribute('style');
    trigger.setAttribute("aria-expanded", "false");
    root.classList.remove("is-open");
    if (focus) {
      trigger.focus();
    }
  };

  listen(trigger, "click", (event) => {
    event.preventDefault();
    if (popover.hidden) {
      open();
    } else {
      close();
    }
  });
  listen(rail, "click", (event) => {
    const button = event.target.closest(".aipkit_unified_model_provider");
    if (!button) {
      return;
    }
    activeProvider = button.dataset.provider || ALL;
    render();
    refreshCatalog(activeProvider);
  });
  listen(list, "click", (event) => {
    const item = event.target.closest(".aipkit_unified_model_item");
    if (!item || item.disabled) {
      return;
    }
    const additive = event.shiftKey || event.metaKey || event.ctrlKey;
    choose(item.dataset.provider || "", item.dataset.store || "", additive);
    if (additive) {
      render();
      return;
    }
    close({ focus: true });
  });
  listen(search, "input", render);
  listen(document, "click", (event) => {
    // Rendering can replace the clicked row before this event reaches document.
    // Its original event path still identifies the click as inside the picker.
    if (!popover.hidden && !event.composedPath().includes(root) && !event.composedPath().includes(popover)) {
      close();
    }
  });
  listen(document, "keydown", (event) => {
    if (event.key === "Escape" && !popover.hidden) {
      event.preventDefault();
      close({ focus: true });
    }
  });
  // A side panel holding the picker closes it first on Escape, and with itself.
  listen(popover, "aipkit:dismiss", (event) => close({ focus: Boolean(event.detail?.focus) }));
  // Saved values change outside the picker too: bot switches, store lists loading, model changes.
  listen(settingsArea, "change", (event) => {
    if (event.target?.matches?.('[name="vector_store_provider"], .aipkit_vector_store_enable_select, select[name$="[]"], select[name="pinecone_index_name"]')) {
      refresh();
    }
  });

  listen(stateTarget, "aipkit:bot-state-applied", () => {
    close();
    refresh();
  });
  const storeLists = new MutationObserver(refresh);
  [...Object.values(STORE_SELECTS), '[name="vector_store_provider"]'].forEach((selector) => {
    const select = settingsArea.querySelector(selector);
    if (select) {
      storeLists.observe(select, { childList: true, subtree: true, attributes: true, attributeFilter: ["disabled", "selected", "label"] });
    }
  });

  const dispose = () => {
    if (disposed) return;
    disposed = true;
    close();
    controller.abort();
    storeLists.disconnect();
    removal.disconnect();
    anchor.remove();
    delete root._aipkitKnowledgePicker;
    delete root.dataset.knowledgePickerBound;
  };
  // Modules replace their entire DOM when navigating. Release document handlers and observers too.
  const removal = new MutationObserver(() => {
    if (!root.isConnected) dispose();
  });
  removal.observe(root.ownerDocument.documentElement, { childList: true, subtree: true });

  updateTrigger();
  root._aipkitKnowledgePicker = { refresh, close, dispose, popover };
  return root._aipkitKnowledgePicker;
}
