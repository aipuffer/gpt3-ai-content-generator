import {
  createPopoverMultiselectItem,
  setPopoverMultiselectLabel,
  getPopoverMultiselectRefs,
  setupSearchablePopoverMultiselect,
} from "../utils/ui/popover-multiselect.js";

import {
  clearPreservedVectorStoreSelections,
  isPreservedVectorStoreSelection,
  markPreservedVectorStoreSelection,
  syncVectorStoreSelectionValues,
} from "../utils/vector-store-selection-state.js";

function createStorePickerOpenState(
  dropdown, dropdownButton, dropdownPanel,
  { prepareOpen = null, overflowSelector = "" } = {}
) {
  return (open) => {
    if (!dropdown || !dropdownButton) return;
    dropdown.classList.toggle("is-open", open);
    dropdownButton.setAttribute("aria-expanded", open ? "true" : "false");
    if (dropdownPanel) dropdownPanel.hidden = !open;
    if (open && prepareOpen) prepareOpen();
    if (overflowSelector) {
      const settingsPanel = dropdown.closest(".aipkit_chatbot_settings_panel");
      if (settingsPanel) {
        settingsPanel.classList.toggle(
          "aipkit_chatbot_settings_panel--allow-overflow",
          Boolean(settingsPanel.querySelector(overflowSelector))
        );
      }
    }
  };
}

function bindStorePickerEvents(dropdown, dropdownButton, setOpenState, provider, escapeTarget = dropdownButton) {
  if (!dropdown || !dropdownButton || dropdown.dataset.listenerAttached) return;
  dropdownButton.addEventListener("click", (event) => {
    event.preventDefault();
    if (typeof window.aipkit_refreshVectorStoreProviderList === "function") {
      window.aipkit_refreshVectorStoreProviderList(provider);
    }
    setOpenState(!dropdown.classList.contains("is-open"));
  });
  document.addEventListener("click", (event) => {
    if (!dropdown.classList.contains("is-open") || dropdown.contains(event.target)) return;
    setOpenState(false);
  });
  escapeTarget.addEventListener("keydown", (event) => {
    if (event.key === "Escape") setOpenState(false);
  });
  dropdown.dataset.listenerAttached = "true";
}

const i18n = window.wp && window.wp.i18n ? window.wp.i18n : null;

const __ =
  i18n && typeof i18n.__ === "function" ? i18n.__ : (text) => text;

const ERROR_PREFIX = __("Error:", "gpt3-ai-content-generator");

const CREATE_FAILED_TEXT = __("Create failed.", "gpt3-ai-content-generator");

const PRESERVED_SELECTION_HINT = __(
  "Unavailable. Uncheck to remove.",
  "gpt3-ai-content-generator"
);

const getChatVectorStoreScope = (element) =>
  element && typeof element.closest === "function"
    ? element.closest(".aipkit_chatbot_builder")
    : null;

function aipkit_populateOpenAIVectorStoresMultiSelect(settingsArea) {
  if (!getChatVectorStoreScope(settingsArea)) {
    return;
  }

  const selectElement = settingsArea.querySelector(
    'select[name="openai_vector_store_ids[]"]'
  );
  const escaper =
    window.aipkit_escapeHtml ||
    function (str) {
      return str;
    };
  if (!selectElement) return;

  const dropdown = settingsArea.querySelector(
    "[data-aipkit-vector-stores-dropdown]"
  );
  const {
    dropdownButton,
    dropdownLabel,
    dropdownPanel,
    dropdownOptions,
  } = getPopoverMultiselectRefs(dropdown);
  const placeholder = dropdown?.dataset.placeholder || "Select stores";
  const selectedLabel = dropdown?.dataset.selectedLabel || "selected";
  let searchableMenu = null;

  const availableStores = window.aipkit_chat_config?.openaiVectorStores || [];
  const pendingSelection = parsePendingSelection(selectElement);
  const currentSavedValues = Array.from(selectElement.options)
    .filter((opt) => opt.selected)
    .map((opt) => opt.value);
  const currentMissingValues = Array.from(selectElement.options)
    .filter((opt) => opt.disabled && opt.value)
    .map((opt) => opt.value);
  const selectedValues =
    pendingSelection && pendingSelection.length
      ? pendingSelection
      : Array.from(new Set([...currentSavedValues, ...currentMissingValues]));
  selectElement.innerHTML = "";

  if (availableStores.length > 0) {
    let storeIndex = 1;
    availableStores.forEach((store) => {
      if (store && store.id) {
        let fileCountText = "(Files: N/A)";
        if (store.file_counts && typeof store.file_counts.total === "number") {
          fileCountText = `(${store.file_counts.total} ${
            store.file_counts.total === 1 ? "File" : "Files"
          })`;
        } else if (store.file_counts) fileCountText = `(Files: ?)`;
        const storeName = store.name || `Untitled store ${storeIndex}`;
        const optionText = `${escaper(storeName)} ${fileCountText}`;
        const option = document.createElement("option");
        option.value = store.id;
        option.textContent = optionText;
        if (selectedValues.includes(store.id)) option.selected = true;
        selectElement.appendChild(option);
        storeIndex += 1;
      }
    });
  }
  selectedValues.forEach((savedId) => {
    let foundInList = availableStores.some((store) => store.id === savedId);
    if (
      !foundInList &&
      savedId &&
      !selectElement.querySelector(`option[value="${CSS.escape(savedId)}"]`)
    ) {
      const manualOption = document.createElement("option");
      manualOption.value = savedId;
      manualOption.textContent = `${savedId} (missing)`;
      manualOption.disabled = true;
      markPreservedVectorStoreSelection(manualOption);
      selectElement.appendChild(manualOption);
    }
  });
  if (selectElement.options.length === 0) {
    selectElement.innerHTML =
      '<option value="" disabled>-- No vector stores found --</option>';
  }

  const updateDropdownLabel = () => {
    setPopoverMultiselectLabel(
      dropdownLabel,
      selectElement,
      placeholder,
      selectedLabel,
      { includeDisabled: false }
    );
  };

  const syncSelectFromCheckboxes = () => {
    if (!dropdownOptions) {
      return;
    }
    const checkedInputs = Array.from(
      dropdownOptions.querySelectorAll('input[type="checkbox"]:checked:not(:disabled)')
    );
    if (checkedInputs.length > 2) {
      const lastChecked = checkedInputs[checkedInputs.length - 1];
      if (lastChecked) {
        lastChecked.checked = false;
      }
    }
    const selectedValues = Array.from(
      dropdownOptions.querySelectorAll('input[type="checkbox"]:checked:not(:disabled)')
    ).map((input) => input.value);
    syncVectorStoreSelectionValues(selectElement, selectedValues);
    selectElement.dispatchEvent(new Event("change", { bubbles: true }));
    updateDropdownLabel();
    searchableMenu?.refresh();
  };

  const renderDropdownOptions = () => {
    if (!dropdownOptions) {
      return;
    }
    dropdownOptions.innerHTML = '';
    const options = Array.from(selectElement.options);
    const usableOptions = options.filter(opt => opt.value);

    if (!usableOptions.length) {
      const empty = document.createElement('div');
      empty.className = 'aipkit_popover_multiselect_empty';
      empty.textContent = options[0]?.textContent || '-- No Stores Found --';
      dropdownOptions.appendChild(empty);
      updateDropdownLabel();
      searchableMenu?.refresh();
      return;
    }

    usableOptions.forEach(option => {
      dropdownOptions.appendChild(
        createPopoverMultiselectItem(option, {
          allowPreservedRemoval: true,
          preservedRemovalHint: PRESERVED_SELECTION_HINT,
        })
      );
    });

    updateDropdownLabel();
    searchableMenu?.refresh();
  };

  const setOpenState = createStorePickerOpenState(
    dropdown, dropdownButton, dropdownPanel, {
      prepareOpen: () => searchableMenu?.prepareOpen(),
      overflowSelector: [
        ".aipkit_popover_multiselect.is-open",
        ".aipkit_settings_model_dropdown.is-open",
        ".aipkit_ai_provider_dropdown.is-open",
        ".aipkit_vector_provider_dropdown.is-open",
      ].join(", "),
    }
  );

  searchableMenu = setupSearchablePopoverMultiselect(dropdown, {
    searchPlaceholder: __("Search vector stores", "gpt3-ai-content-generator"),
    searchAriaLabel: __("Search vector stores", "gpt3-ai-content-generator"),
    noResultsText: __("No vector stores found.", "gpt3-ai-content-generator"),
    dialogLabel: __("Vector stores", "gpt3-ai-content-generator"),
    onEscape: () => {
      setOpenState(false);
      dropdownButton?.focus();
    },
  });

  bindStorePickerEvents(dropdown, dropdownButton, setOpenState, "openai");

  if (dropdownOptions && !dropdownOptions.dataset.listenerAttached) {
    dropdownOptions.addEventListener("change", syncSelectFromCheckboxes);
    dropdownOptions.dataset.listenerAttached = "true";
  }

  renderDropdownOptions();
  initCreateStoreActions(settingsArea, {
    selectElement,
    dropdown,
    dropdownPanel,
    dropdownOptions,
    setOpenState,
    placeholder,
  });
}

function parsePendingSelection(selectElement) {
  if (!selectElement || !selectElement.dataset.aipkitPendingVectorSelection) {
    return [];
  }
  let parsed = [];
  try {
    const raw = JSON.parse(selectElement.dataset.aipkitPendingVectorSelection);
    if (Array.isArray(raw)) {
      parsed = raw
        .map((value) => String(value || "").trim())
        .filter(Boolean)
        .slice(-2);
    }
  } catch (error) {
    parsed = [];
  }
  delete selectElement.dataset.aipkitPendingVectorSelection;
  return parsed;
}

function initCreateStoreActions(settingsArea, context) {
  const {
    selectElement,
    dropdown,
    dropdownPanel,
    dropdownOptions,
    setOpenState,
    placeholder,
  } = context || {};
  if (!dropdown || !dropdownPanel || !dropdownOptions || !selectElement) {
    return;
  }

  let actionsWrap = dropdownPanel.querySelector(
    ".aipkit_vector_store_dropdown_actions"
  );
  if (!actionsWrap) {
    actionsWrap = document.createElement("div");
    actionsWrap.className = "aipkit_vector_store_dropdown_actions";
    actionsWrap.innerHTML = `
        <div class="aipkit_vector_store_dropdown_action_list">
          <button type="button" class="aipkit_btn aipkit_btn-secondary aipkit_btn-small aipkit_vector_store_dropdown_new_btn">
            <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
            ${__("New Store", "gpt3-ai-content-generator")}
          </button>
        </div>
        <div class="aipkit_vector_store_dropdown_inline_form" style="display:none;">
          <input
            type="text"
            class="aipkit_form-input aipkit_popover_option_input aipkit_popover_option_input--wide aipkit_popover_option_input--framed aipkit_vector_store_dropdown_name_input"
            placeholder="${__("e.g. Product FAQ", "gpt3-ai-content-generator")}"
          />
          <div class="aipkit_vector_store_dropdown_inline_actions">
            <button type="button" class="aipkit_btn aipkit_btn-secondary aipkit_btn-small aipkit_vector_store_dropdown_cancel_btn">
              ${__("Cancel", "gpt3-ai-content-generator")}
            </button>
            <button type="button" class="aipkit_btn aipkit_btn-primary aipkit_btn-small aipkit_vector_store_dropdown_create_btn">
              ${__("Create", "gpt3-ai-content-generator")}
            </button>
          </div>
        </div>
        <p class="aipkit_form-help aipkit_vector_store_dropdown_status" style="display:none;" aria-live="polite"></p>
      `;
    dropdownPanel.insertBefore(actionsWrap, dropdownOptions);
  }

  const actionList = actionsWrap.querySelector(
    ".aipkit_vector_store_dropdown_action_list"
  );
  const inlineForm = actionsWrap.querySelector(
    ".aipkit_vector_store_dropdown_inline_form"
  );
  const createBtn = actionsWrap.querySelector(
    ".aipkit_vector_store_dropdown_create_btn"
  );
  const cancelBtn = actionsWrap.querySelector(
    ".aipkit_vector_store_dropdown_cancel_btn"
  );
  const newBtn = actionsWrap.querySelector(
    ".aipkit_vector_store_dropdown_new_btn"
  );
  const nameInput = actionsWrap.querySelector(
    ".aipkit_vector_store_dropdown_name_input"
  );
  const statusEl = actionsWrap.querySelector(
    ".aipkit_vector_store_dropdown_status"
  );

  const clearStatusTimer = () => {
    if (
      actionsWrap &&
      typeof actionsWrap._aipkitVectorStoreStatusTimer === "number" &&
      actionsWrap._aipkitVectorStoreStatusTimer
    ) {
      window.clearTimeout(actionsWrap._aipkitVectorStoreStatusTimer);
      actionsWrap._aipkitVectorStoreStatusTimer = 0;
    }
  };

  const scheduleStatusClear = (delayMs = 3200) => {
    clearStatusTimer();
    if (!actionsWrap) {
      return;
    }
    actionsWrap._aipkitVectorStoreStatusTimer = window.setTimeout(() => {
      setStatus("");
    }, delayMs);
  };

  const setStatus = (message, tone = "", autoDismissMs = 0) => {
    clearStatusTimer();
    if (!statusEl) {
      return;
    }
    statusEl.textContent = message || "";
    statusEl.style.display = message ? "block" : "none";
    statusEl.classList.remove(
      "aipkit_color_notice_success",
      "aipkit_color_notice_error",
      "aipkit_color_notice_info"
    );
    if (tone === "success") {
      statusEl.classList.add("aipkit_color_notice_success");
    } else if (tone === "error") {
      statusEl.classList.add("aipkit_color_notice_error");
    } else if (tone === "info") {
      statusEl.classList.add("aipkit_color_notice_info");
    }

    if (message && Number(autoDismissMs) > 0) {
      scheduleStatusClear(Number(autoDismissMs));
    }
  };

  const resetInlineForm = () => {
    if (nameInput) {
      nameInput.value = "";
    }
    if (actionList) {
      actionList.style.display = "";
    }
    if (inlineForm) {
      inlineForm.style.display = "none";
    }
    setStatus("");
  };

  const showInlineForm = () => {
    if (actionList) {
      actionList.style.display = "none";
    }
    if (inlineForm) {
      inlineForm.style.display = "block";
    }
    setStatus("");
    if (nameInput) {
      nameInput.focus();
      nameInput.select();
    }
  };

  const handleCreateStore = async () => {
    if (!createBtn || !nameInput) {
      return;
    }
    const rawName = String(nameInput.value || "").trim();
    if (!rawName) {
      setStatus(
        __("Store name is required.", "gpt3-ai-content-generator"),
        "error",
        3000
      );
      return;
    }
    if (typeof window.aipkit_apiRequest !== "function") {
      setStatus(
        __("API request helper is unavailable.", "gpt3-ai-content-generator"),
        "error",
        3000
      );
      return;
    }
    const nonceEl = document.getElementById("aipkit_vector_store_nonce_openai");
    const nonce = nonceEl ? String(nonceEl.value || "").trim() : "";
    if (!nonce) {
      setStatus(
        __("OpenAI nonce is missing.", "gpt3-ai-content-generator"),
        "error",
        3000
      );
      return;
    }

    const defaultLabel = createBtn.textContent;
    createBtn.disabled = true;
    createBtn.textContent = __("Creating...", "gpt3-ai-content-generator");
    if (cancelBtn) {
      cancelBtn.disabled = true;
    }
    setStatus("");

    try {
      const response = await window.aipkit_apiRequest(
        "aipkit_create_vector_store_openai",
        {
          _ajax_nonce: nonce,
          name: rawName,
          source_type: "chatbot_training_text",
        }
      );
      const createdStore = response?.store || response;
      if (!createdStore || !createdStore.id) {
        throw new Error(
          __("OpenAI vector store creation failed.", "gpt3-ai-content-generator")
        );
      }

      if (!window.aipkit_chat_config) {
        window.aipkit_chat_config = {};
      }
      if (!Array.isArray(window.aipkit_chat_config.openaiVectorStores)) {
        window.aipkit_chat_config.openaiVectorStores = [];
      }
      const existingStores = window.aipkit_chat_config.openaiVectorStores;
      const hasStore = existingStores.some(
        (store) => store && store.id === createdStore.id
      );
      if (!hasStore) {
        existingStores.unshift(createdStore);
      }

      const currentlySelectedIds = Array.from(selectElement.options)
        .filter((option) => option.selected && option.value)
        .map((option) => option.value);
      const nextSelectedIds = currentlySelectedIds
        .filter((value) => value && value !== createdStore.id)
        .slice(-1);
      nextSelectedIds.push(createdStore.id);
      selectElement.dataset.aipkitPendingVectorSelection = JSON.stringify(
        nextSelectedIds
      );

      aipkit_populateOpenAIVectorStoresMultiSelect(settingsArea);
      setOpenState(true);
      setStatus(
        response?.message ||
          __("Vector store created.", "gpt3-ai-content-generator"),
        "success",
        2800
      );
    } catch (error) {
      const message =
        error && error.message
          ? error.message
          : CREATE_FAILED_TEXT;
      setStatus(
        `${ERROR_PREFIX} ${message}`,
        "error",
        3200
      );
    } finally {
      createBtn.disabled = false;
      createBtn.textContent = defaultLabel;
      if (cancelBtn) {
        cancelBtn.disabled = false;
      }
    }
  };

  const canCreate = typeof window.aipkit_apiRequest === "function";
  if (newBtn) {
    newBtn.disabled = !canCreate;
    if (!canCreate) {
      newBtn.setAttribute(
        "title",
        __("Creation is unavailable right now.", "gpt3-ai-content-generator")
      );
    } else {
      newBtn.removeAttribute("title");
    }
  }

  if (!actionsWrap.dataset.listenerAttached) {
    if (newBtn) {
      newBtn.addEventListener("click", () => {
        showInlineForm();
      });
    }
    if (cancelBtn) {
      cancelBtn.addEventListener("click", () => {
        resetInlineForm();
      });
    }
    if (createBtn) {
      createBtn.addEventListener("click", handleCreateStore);
    }
    if (nameInput) {
      nameInput.addEventListener("keydown", (event) => {
        if (event.key === "Enter") {
          event.preventDefault();
          handleCreateStore();
          return;
        }
        if (event.key === "Escape") {
          event.preventDefault();
          resetInlineForm();
        }
      });
    }
    actionsWrap.dataset.listenerAttached = "true";
  }

  if (!dropdownOptions.children.length) {
    setStatus(
      __("No stores found. Create your first one.", "gpt3-ai-content-generator"),
      "info"
    );
    if (newBtn) {
      newBtn.textContent = __("Create Store", "gpt3-ai-content-generator");
    }
  } else {
    if (newBtn) {
      newBtn.textContent = __("New Store", "gpt3-ai-content-generator");
    }
    if (!inlineForm || inlineForm.style.display === "none") {
      setStatus("");
    }
  }

  if (!selectElement.value && placeholder) {
    if (dropdown && !dropdown.classList.contains("is-open")) {
      setOpenState(false);
    }
  }
}

window.aipkit_populateOpenAIVectorStoresMultiSelect = aipkit_populateOpenAIVectorStoresMultiSelect;

window.addEventListener("aipkit:vector-store-list-updated", (event) => {
  const provider = String(event?.detail?.provider || "").toLowerCase();
  if (provider !== "openai") {
    return;
  }

  const selects = Array.from(
    document.querySelectorAll('select[name="openai_vector_store_ids[]"]')
  );
  selects.forEach((selectElement) => {
    const chatScope = getChatVectorStoreScope(selectElement);
    if (!chatScope) {
      return;
    }
    const settingsArea =
      selectElement.closest(".aipkit_settings_panel_body") ||
      selectElement.closest(".aipkit_modal_body") ||
      selectElement.closest(".aipkit_chatbot-settings-area") ||
      selectElement.closest(".aipkit_chatbot_builder") ||
      chatScope;
    if (settingsArea) {
      aipkit_populateOpenAIVectorStoresMultiSelect(settingsArea);
    }
  });
});

const PROVIDERS = {
  google: {
    selectName: 'google_file_search_store_names[]',
    dropdownSelector: "[data-aipkit-google-file-search-stores-dropdown]",
    globalName: "aipkit_populateGoogleFileSearchStoresMultiSelect",
  },
  chroma: {
    selectName: 'chroma_collection_names[]',
    dropdownSelector: "[data-aipkit-chroma-collections-dropdown]",
    globalName: "aipkit_populateChromaCollectionsMultiSelect",
  },
  qdrant: {
    selectName: 'qdrant_collection_names[]',
    dropdownSelector: "[data-aipkit-qdrant-collections-dropdown]",
    globalName: "aipkit_populateQdrantCollectionsMultiSelect",
  },
  local: {
    selectName: 'local_store_ids[]',
    dropdownSelector: "[data-aipkit-local-stores-dropdown]",
    globalName: "aipkit_populateLocalStoresMultiSelect",
  },
};

const vectorStoreUpdateListeners = new Set();

function getSettingsAreaForSelect(selectElement) {
  return (
    selectElement.closest(".aipkit_settings_panel_body") ||
    selectElement.closest(".aipkit_popover_options_list") ||
    selectElement.closest(".aipkit_popover_option_main") ||
    selectElement.parentElement
  );
}

function populateCollectionsMultiSelect(settingsArea, providerKey) {
  const provider = PROVIDERS[providerKey];
  if (!settingsArea || !provider) return;

  const selectElement = settingsArea.querySelector(
    `select[name="${provider.selectName}"]`
  );
  if (!selectElement) return;

  const dropdown = settingsArea.querySelector(provider.dropdownSelector);
  const {
    dropdownButton,
    dropdownLabel,
    dropdownPanel,
    dropdownOptions,
  } = getPopoverMultiselectRefs(dropdown);
  if (!dropdown || !dropdownOptions) return;

  const placeholder = dropdown.dataset.placeholder || "Select collections";
  const selectedLabel = dropdown.dataset.selectedLabel || "selected";

  const updateDropdownLabel = () => {
    setPopoverMultiselectLabel(
      dropdownLabel,
      selectElement,
      placeholder,
      selectedLabel,
      { includeDisabled: false }
    );
  };

  const syncSelectFromCheckboxes = () => {
    const selectedValues = Array.from(
      dropdownOptions.querySelectorAll(
        'input[type="checkbox"]:checked:not(:disabled)'
      )
    ).map((input) => input.value);
    syncVectorStoreSelectionValues(selectElement, selectedValues);
    selectElement.dispatchEvent(new Event("change", { bubbles: true }));
    updateDropdownLabel();
  };

  const renderDropdownOptions = () => {
    dropdownOptions.innerHTML = "";
    const options = Array.from(selectElement.options);
    const usableOptions = options.filter((opt) => opt.value);

    if (!usableOptions.length) {
      const empty = document.createElement("div");
      empty.className = "aipkit_popover_multiselect_empty";
      empty.textContent = options[0]?.textContent || "-- No Collections Found --";
      dropdownOptions.appendChild(empty);
      updateDropdownLabel();
      return;
    }

    usableOptions.forEach((option) => {
      dropdownOptions.appendChild(
        createPopoverMultiselectItem(option, {
          allowPreservedRemoval: true,
          preservedRemovalHint: PRESERVED_SELECTION_HINT,
        })
      );
    });

    updateDropdownLabel();
  };

  const setOpenState = createStorePickerOpenState(
    dropdown, dropdownButton, dropdownPanel,
    { overflowSelector: ".aipkit_popover_multiselect.is-open" }
  );
  bindStorePickerEvents(dropdown, dropdownButton, setOpenState, providerKey);

  if (!dropdownOptions.dataset.listenerAttached) {
    dropdownOptions.addEventListener("change", syncSelectFromCheckboxes);
    dropdownOptions.dataset.listenerAttached = "true";
  }

  renderDropdownOptions();
}

function attachVectorStoreUpdateListener(providerKey) {
  const provider = PROVIDERS[providerKey];
  if (!provider || vectorStoreUpdateListeners.has(providerKey)) {
    return;
  }

  window.addEventListener("aipkit:vector-store-list-updated", (event) => {
    const updatedProvider = String(event?.detail?.provider || "").toLowerCase();
    if (updatedProvider !== providerKey) {
      return;
    }
    document
      .querySelectorAll(`select[name="${provider.selectName}"]`)
      .forEach((selectElement) => {
        const settingsArea = getSettingsAreaForSelect(selectElement);
        if (settingsArea) {
          populateCollectionsMultiSelect(settingsArea, providerKey);
        }
      });
  });

  vectorStoreUpdateListeners.add(providerKey);
}

Object.entries(PROVIDERS).forEach(([providerKey, provider]) => {
  window[provider.globalName] = (settingsArea) => {
    populateCollectionsMultiSelect(settingsArea, providerKey);
  };
  attachVectorStoreUpdateListener(providerKey);
});

/**
 * Re-reads the built-in knowledge bases and rebuilds every "AI Puffer" knowledge picker, keeping selections.
 * Returns the stores. Used after a store is created, filled or deleted.
 */
window.aipkit_refreshLocalStores = async function () {
  const nonce = document.getElementById("aipkit_vector_store_local_nonce_management")?.value || "";
  if (!nonce || typeof window.aipkit_apiRequest !== "function") return [];
  let stores = [];
  try {
    const response = await window.aipkit_apiRequest("aipkit_local_list_stores", { _ajax_nonce: nonce });
    stores = Array.isArray(response?.stores) ? response.stores : [];
  } catch (error) {
    return [];
  }
  const label = (store) => {
    const chunks = Number(store.chunk_count || 0);
    return `${store.name} (${chunks.toLocaleString()} ${chunks === 1 ? "chunk" : "chunks"})`;
  };
  document.querySelectorAll('select[name="local_store_ids[]"], select[data-aipkit-local-store-select]').forEach((select) => {
    const selected = new Set(Array.from(select.selectedOptions).map((option) => option.value));
    select.innerHTML = "";
    if (!select.multiple && stores.length) {
      select.appendChild(new Option("-- Select knowledge base --", ""));
    }
    stores.forEach((store) => {
      const option = new Option(label(store), store.id, false, selected.has(store.id));
      select.appendChild(option);
    });
    if (!stores.length) {
      const empty = new Option("-- No knowledge bases yet --", "");
      empty.disabled = true;
      select.appendChild(empty);
    }
  });
  window.dispatchEvent(new CustomEvent("aipkit:vector-store-list-updated", { detail: { provider: "local", stores } }));
  return stores;
};

function initializeExistingMultiSelects() {
  Object.entries(PROVIDERS).forEach(([providerKey, provider]) => {
    document
      .querySelectorAll(`select[name="${provider.selectName}"]`)
      .forEach((selectElement) => {
        const settingsArea = getSettingsAreaForSelect(selectElement);
        if (settingsArea) {
          populateCollectionsMultiSelect(settingsArea, providerKey);
        }
      });
  });
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", initializeExistingMultiSelects, {
    once: true,
  });
} else {
  initializeExistingMultiSelects();
}

function aipkit_populatePineconeIndexSelect(settingsArea) {
  if (!settingsArea) {
    return;
  }

  const selectElement = settingsArea.querySelector(
    'select[name="pinecone_index_name"]'
  );
  if (!selectElement) {
    return;
  }

  const dropdown = settingsArea.querySelector(
    "[data-aipkit-pinecone-index-dropdown]"
  );
  const {
    dropdownButton,
    dropdownLabel,
    dropdownPanel,
    dropdownOptions,
  } = getPopoverMultiselectRefs(dropdown);

  const escaper =
    window.aipkit_escapeHtml ||
    function (str) {
      return str;
    };
  const availableIndexes = window.aipkit_chat_config?.pineconeIndexes || [];
  const currentMissingValue = String(
    Array.from(selectElement.options || []).find(
      (option) => option.disabled && option.value
    )?.value || ""
  ).trim();
  const currentSavedValue = String(
    selectElement.value || currentMissingValue || ""
  ).trim();
  const placeholder =
    (dropdown && dropdown.dataset && dropdown.dataset.placeholder) ||
    "Select index";

  const optionRows = [];
  const seenValues = new Set();

  if (Array.isArray(availableIndexes)) {
    availableIndexes.forEach((index) => {
      const indexId = String(index?.name || index?.id || "").trim();
      if (!indexId || seenValues.has(indexId)) {
        return;
      }
      seenValues.add(indexId);

      const displayName = String(index?.name || indexId);
      const vectorCount = index?.total_vector_count;
      const hasNumericCount =
        vectorCount !== undefined &&
        vectorCount !== null &&
        vectorCount !== "Error" &&
        vectorCount !== "No Host";
      const vectorCountDisplay = hasNumericCount
        ? ` (Vectors: ${Number(vectorCount).toLocaleString()})`
        : vectorCount === "Error" || vectorCount === "No Host"
          ? " (Stats Err)"
          : " (Vectors: ?)";

      optionRows.push({
        value: indexId,
        label: escaper(`${displayName}${vectorCountDisplay}`),
        disabled: false,
      });
    });
  }

  if (currentSavedValue && !seenValues.has(currentSavedValue)) {
    optionRows.push({
      value: currentSavedValue,
      label: escaper(`${currentSavedValue} (missing)`),
      disabled: true,
    });
    seenValues.add(currentSavedValue);
  }

  if (!optionRows.length) {
    optionRows.push({
      value: "",
      label: "-- No Indexes Found (Sync in AI Settings) --",
      disabled: true,
    });
  }

  selectElement.innerHTML = "";
  const placeholderOption = new Option(placeholder, "");
  placeholderOption.selected = !currentSavedValue;
  selectElement.appendChild(placeholderOption);

  let hasSelectedValue = false;
  optionRows.forEach((row) => {
    const option = new Option(row.label, row.value);
    if (row.disabled) {
      option.disabled = true;
      if (row.value === currentSavedValue) {
        markPreservedVectorStoreSelection(option);
      }
    }
    if (!row.disabled && row.value && row.value === currentSavedValue) {
      option.selected = true;
      hasSelectedValue = true;
    }
    selectElement.appendChild(option);
  });

  if (currentSavedValue && !hasSelectedValue) {
    selectElement.value = "";
  }

  const updateDropdownLabel = () => {
    if (!dropdownLabel) {
      return;
    }
    const selectedOption =
      selectElement.selectedOptions && selectElement.selectedOptions.length
        ? selectElement.selectedOptions[0]
        : null;
    const preservedOption = Array.from(selectElement.options).find(
      isPreservedVectorStoreSelection
    );
    const labelText =
      selectedOption && !selectedOption.disabled && selectedOption.textContent
        ? selectedOption.textContent.trim()
        : preservedOption?.textContent?.trim() || "";
    dropdownLabel.textContent = labelText || placeholder;
  };

  const renderDropdownOptions = () => {
    if (!dropdownOptions) {
      return;
    }
    dropdownOptions.innerHTML = "";

    const usableOptions = Array.from(selectElement.options).filter(
      (option) => option.value
    );

    if (!usableOptions.length) {
      const empty = document.createElement("div");
      empty.className = "aipkit_popover_multiselect_empty";
      empty.textContent =
        selectElement.options[0]?.textContent ||
        "-- No Indexes Found (Sync in AI Settings) --";
      dropdownOptions.appendChild(empty);
      updateDropdownLabel();
      return;
    }

    const radioName =
      dropdown.dataset.pineconeRadioName ||
      `aipkit_pinecone_index_${Date.now()}_${Math.random()
        .toString(36)
        .slice(2, 8)}`;
    dropdown.dataset.pineconeRadioName = radioName;

    usableOptions.forEach((option) => {
      const isPreservedSelection = isPreservedVectorStoreSelection(option);
      if (isPreservedSelection) {
        const row = createPopoverMultiselectItem(option, {
          allowPreservedRemoval: true,
          preservedRemovalHint: PRESERVED_SELECTION_HINT,
        });
        row.classList.add("aipkit_vector_store_pinecone_item");
        const checkbox = row.querySelector('input[type="checkbox"]');
        if (checkbox) {
          checkbox.classList.add("aipkit_vector_store_pinecone_radio");
          checkbox.checked = true;
        }
        dropdownOptions.appendChild(row);
        return;
      }

      const row = document.createElement("label");
      row.className =
        "aipkit_popover_multiselect_item aipkit_vector_store_pinecone_item";

      const rowLabel = document.createElement("span");
      rowLabel.className = "aipkit_vector_store_pinecone_item_label";

      const radio = document.createElement("input");
      radio.type = "radio";
      radio.name = radioName;
      radio.className = "aipkit_vector_store_pinecone_radio";
      radio.value = option.value;
      radio.checked = option.selected && !option.disabled;
      radio.disabled = option.disabled;

      const text = document.createElement("span");
      text.className = "aipkit_popover_multiselect_text";
      text.textContent = option.textContent;

      rowLabel.appendChild(radio);
      rowLabel.appendChild(text);
      row.appendChild(rowLabel);
      dropdownOptions.appendChild(row);
    });

    updateDropdownLabel();
  };

  const syncSelectFromRadio = (event) => {
    if (!dropdownOptions) {
      return;
    }
    const target = event?.target;
    if (
      target?.dataset?.aipkitPreservedSelection === "1" &&
      !target.checked
    ) {
      clearPreservedVectorStoreSelections(selectElement);
      selectElement.value = "";
      selectElement.dispatchEvent(new Event("change", { bubbles: true }));
      renderDropdownOptions();
      return;
    }

    const checkedRadio = dropdownOptions.querySelector(
      '.aipkit_vector_store_pinecone_radio:checked:not(:disabled)'
    );
    if (!checkedRadio) {
      return;
    }

    const selectedValue = checkedRadio.value || "";
    clearPreservedVectorStoreSelections(selectElement);
    if ((selectElement.value || "") !== selectedValue) {
      selectElement.value = selectedValue;
      selectElement.dispatchEvent(new Event("change", { bubbles: true }));
    } else {
      updateDropdownLabel();
    }
  };

  const syncRadiosFromSelect = () => {
    if (!dropdownOptions) {
      updateDropdownLabel();
      return;
    }
    const selectedValue = selectElement.value || "";
    dropdownOptions
      .querySelectorAll(".aipkit_vector_store_pinecone_radio")
      .forEach((radio) => {
        radio.checked =
          radio.dataset.aipkitPreservedSelection === "1" ||
          radio.value === selectedValue;
      });
    updateDropdownLabel();
  };

  const setOpenState = createStorePickerOpenState(dropdown, dropdownButton, dropdownPanel);
  bindStorePickerEvents(dropdown, dropdownButton, setOpenState, "pinecone", document);

  if (dropdownOptions && !dropdownOptions.dataset.listenerAttached) {
    dropdownOptions.addEventListener("change", (event) => {
      if (
        event.target &&
        event.target.matches(".aipkit_vector_store_pinecone_radio")
      ) {
        syncSelectFromRadio(event);
        setOpenState(false);
      }
    });
    dropdownOptions.dataset.listenerAttached = "true";
  }

  if (selectElement.dataset.pineconeDropdownBound !== "1") {
    selectElement.addEventListener("change", syncRadiosFromSelect);
    selectElement.dataset.pineconeDropdownBound = "1";
  }

  renderDropdownOptions();
  syncRadiosFromSelect();
}

window.aipkit_populatePineconeIndexSelect = aipkit_populatePineconeIndexSelect;

window.addEventListener("aipkit:vector-store-list-updated", (event) => {
  const provider = String(event?.detail?.provider || "").toLowerCase();
  if (provider !== "pinecone") {
    return;
  }
  document
    .querySelectorAll('select[name="pinecone_index_name"]')
    .forEach((selectElement) => {
      const settingsArea = getSettingsAreaForSelect(selectElement);
      if (settingsArea) {
        aipkit_populatePineconeIndexSelect(settingsArea);
      }
    });
});

/** Embedding model selection and dropdown lifetime. */
{
  const __ = window.wp?.i18n?.__ || function (str) { return str; };

  const EMBEDDING_SELECT_SCOPE =
    ".aipkit_vector_embedding_select:not([data-aipkit-universal-model-combined='1'])";

  let activeDropdown = null;

  const buildPickerIds = (selectId) => ({
    buttonId: `${selectId}_picker_btn`,
    labelId: `${selectId}_picker_label`,
    popoverId: `${selectId}_picker_popover`,
    searchId: `${selectId}_picker_search`,
    listId: `${selectId}_picker_list`,
  });

  const getSelectedLabel = (select) => {
    if (!select) {
      return "";
    }
    const selected =
      select.selectedOptions && select.selectedOptions.length
        ? select.selectedOptions[0]
        : select.querySelector("option[selected]");
    if (selected) {
      return selected.textContent.trim();
    }
    const firstOption = select.querySelector("option");
    return firstOption ? firstOption.textContent.trim() : "";
  };

  const ensurePickerElements = (select) => {
    if (!select || !select.id) {
      return null;
    }

    const ids = buildPickerIds(select.id);
    const builder = select.closest(".aipkit_chatbot_builder");
    if (!builder) {
      return null;
    }

    let dropdown = document.getElementById(ids.popoverId);
    if (
      dropdown &&
      !dropdown.classList.contains("aipkit_vector_store_pinecone_dropdown")
    ) {
      dropdown.remove();
      dropdown = null;
    }

    if (!dropdown) {
      dropdown = document.createElement("div");
      dropdown.id = ids.popoverId;
      dropdown.className =
        "aipkit_popover_multiselect aipkit_vector_store_pinecone_dropdown aipkit_vector_embedding_dropdown";
      dropdown.dataset.placeholder =
        __("Select embedding", "gpt3-ai-content-generator");
      select.insertAdjacentElement("beforebegin", dropdown);
    }

    let button = document.getElementById(ids.buttonId);
    if (button && button.parentElement !== dropdown) {
      button.remove();
      button = null;
    }

    if (!button) {
      button = document.createElement("button");
      button.type = "button";
      button.id = ids.buttonId;
      button.className = "aipkit_popover_multiselect_btn";
      button.setAttribute("aria-expanded", "false");

      const labelSpan = document.createElement("span");
      labelSpan.className = "aipkit_popover_multiselect_label";
      labelSpan.id = ids.labelId;
      labelSpan.textContent =
        getSelectedLabel(select) ||
        dropdown.dataset.placeholder ||
        __("Select embedding", "gpt3-ai-content-generator");
      button.appendChild(labelSpan);

      dropdown.appendChild(button);
    }

    let panel = dropdown.querySelector(".aipkit_popover_multiselect_panel");
    if (!panel) {
      panel = document.createElement("div");
      panel.className =
        "aipkit_popover_multiselect_panel aipkit_vector_store_pinecone_panel";
      panel.hidden = true;
      panel.setAttribute("role", "menu");
      dropdown.appendChild(panel);
    }

    let searchWrap = panel.querySelector(".aipkit_settings_select_picker_search");
    if (!searchWrap) {
      searchWrap = document.createElement("div");
      searchWrap.className = "aipkit_settings_select_picker_search";
      panel.appendChild(searchWrap);
    }

    let searchInput = document.getElementById(ids.searchId);
    if (!searchInput || searchInput.parentElement !== searchWrap) {
      searchInput = document.createElement("input");
      searchInput.type = "search";
      searchInput.id = ids.searchId;
      searchInput.className = "aipkit_form-input";
      searchInput.setAttribute(
        "placeholder",
        __("Search embeddings...", "gpt3-ai-content-generator")
      );
      searchWrap.appendChild(searchInput);
    }

    let list = document.getElementById(ids.listId);
    if (!list || list.parentElement !== panel) {
      list = document.createElement("div");
      list.id = ids.listId;
      list.className =
        "aipkit_popover_multiselect_options aipkit_vector_store_pinecone_options";
      panel.appendChild(list);
    }

    const panelId = `${ids.popoverId}_panel`;
    panel.id = panelId;
    button.setAttribute("aria-controls", panelId);

    if (!select.classList.contains("screen-reader-text")) {
      select.classList.add("screen-reader-text");
    }

    return {
      dropdown,
      button,
      panel,
      label: document.getElementById(ids.labelId),
      search: document.getElementById(ids.searchId),
      list,
    };
  };

  const collectOptions = (select) => {
    const blocks = [];
    if (!select) {
      return blocks;
    }
    const children = Array.from(select.children);
    children.forEach((child) => {
      if (child.tagName === "OPTGROUP") {
        const options = Array.from(child.children)
          .filter((opt) => opt.tagName === "OPTION")
          .map((opt) => ({
            value: opt.value,
            label: opt.textContent.trim(),
            disabled: opt.disabled,
            selected: opt.selected,
          }));
        blocks.push({
          groupLabel: child.label || "",
          options,
        });
      } else if (child.tagName === "OPTION") {
        blocks.push({
          groupLabel: "",
          options: [
            {
              value: child.value,
              label: child.textContent.trim(),
              disabled: child.disabled,
              selected: child.selected,
            },
          ],
        });
      }
    });
    return blocks;
  };

  const renderDropdownOptions = (select, refs, searchTerm = "") => {
    if (!select || !refs || !refs.list) {
      return;
    }

    const list = refs.list;
    list.innerHTML = "";

    const term = String(searchTerm || "").trim().toLowerCase();
    const blocks = collectOptions(select);
    let hasAny = false;

    const radioName =
      refs.dropdown.dataset.embeddingRadioName ||
      `aipkit_embedding_${select.id}_${Math.random().toString(36).slice(2, 8)}`;
    refs.dropdown.dataset.embeddingRadioName = radioName;

    blocks.forEach((block) => {
      const filteredOptions = block.options.filter((option) => {
        if (option.disabled || !option.value) {
          return false;
        }
        if (!term) {
          return true;
        }
        return option.label.toLowerCase().includes(term);
      });

      if (!filteredOptions.length) {
        return;
      }

      if (block.groupLabel) {
        const groupLabel = document.createElement("div");
        groupLabel.className = "aipkit_settings_select_picker_group_label";
        groupLabel.textContent = block.groupLabel;
        list.appendChild(groupLabel);
      }

      filteredOptions.forEach((option) => {
        const row = document.createElement("label");
        row.className =
          "aipkit_popover_multiselect_item aipkit_vector_store_pinecone_item";

        const rowLabel = document.createElement("span");
        rowLabel.className = "aipkit_vector_store_pinecone_item_label";

        const radio = document.createElement("input");
        radio.type = "radio";
        radio.name = radioName;
        radio.className = "aipkit_vector_store_pinecone_radio";
        radio.value = option.value;
        radio.checked = select.value === option.value;

        const text = document.createElement("span");
        text.className = "aipkit_popover_multiselect_text";
        text.textContent = option.label;

        rowLabel.appendChild(radio);
        rowLabel.appendChild(text);
        row.appendChild(rowLabel);
        list.appendChild(row);
        hasAny = true;
      });
    });

    if (!hasAny) {
      const empty = document.createElement("div");
      empty.className = "aipkit_popover_multiselect_empty";
      empty.textContent = term
        ? __("No matches found.", "gpt3-ai-content-generator")
        : __("No options available.", "gpt3-ai-content-generator");
      list.appendChild(empty);
    }
  };

  const updateDropdownLabel = (select, refs) => {
    if (!select || !refs || !refs.label) {
      return;
    }
    const labelText = getSelectedLabel(select);
    refs.label.textContent =
      labelText ||
      refs.dropdown.dataset.placeholder ||
      __("Select embedding", "gpt3-ai-content-generator");
  };

  const syncSettingsPanelOverflowState = (refs) => {
    const settingsPanel = refs?.dropdown?.closest(".aipkit_chatbot_settings_panel");
    if (!settingsPanel) {
      return;
    }
    const hasOpenDropdown = Boolean(
      settingsPanel.querySelector(".aipkit_popover_multiselect.is-open")
    );
    settingsPanel.classList.toggle(
      "aipkit_chatbot_settings_panel--allow-overflow",
      hasOpenDropdown
    );
  };

  const positionPanel = (refs) => {
    if (!refs || !refs.panel || refs.panel.hidden) {
      return;
    }

    refs.panel.style.left = "";
    refs.panel.style.right = "";

    const viewportPadding = 12;
    const rect = refs.panel.getBoundingClientRect();
    if (rect.right > window.innerWidth - viewportPadding) {
      refs.panel.style.left = "auto";
      refs.panel.style.right = "0";
    }
    if (rect.left < viewportPadding) {
      refs.panel.style.left = "0";
      refs.panel.style.right = "auto";
    }
  };

  const closeActiveDropdown = () => {
    if (!activeDropdown) {
      return;
    }
    activeDropdown.dropdown.classList.remove("is-open");
    activeDropdown.button.setAttribute("aria-expanded", "false");
    activeDropdown.panel.hidden = true;
    syncSettingsPanelOverflowState(activeDropdown);
    activeDropdown = null;
  };

  const setOpenState = (refs, open) => {
    if (!refs || !refs.dropdown || !refs.button || !refs.panel) {
      return;
    }

    if (open) {
      if (activeDropdown && activeDropdown.dropdown !== refs.dropdown) {
        closeActiveDropdown();
      }
      refs.dropdown.classList.add("is-open");
      refs.button.setAttribute("aria-expanded", "true");
      refs.panel.hidden = false;
      activeDropdown = refs;
      syncSettingsPanelOverflowState(refs);
      window.requestAnimationFrame(() => {
        positionPanel(refs);
      });
      if (refs.search && refs.search.offsetParent !== null) {
        refs.search.focus();
        refs.search.select();
      }
      return;
    }

    refs.dropdown.classList.remove("is-open");
    refs.button.setAttribute("aria-expanded", "false");
    refs.panel.hidden = true;
    if (activeDropdown && activeDropdown.dropdown === refs.dropdown) {
      activeDropdown = null;
    }
    syncSettingsPanelOverflowState(refs);
  };

  const bindPickerEvents = (select, refs) => {
    if (!select || !refs) {
      return;
    }

    if (
      refs.button &&
      refs.button.dataset.embeddingPickerListenerAttached !== "true"
    ) {
      refs.button.addEventListener("click", (event) => {
        event.preventDefault();
        const shouldOpen = !refs.dropdown.classList.contains("is-open");
        setOpenState(refs, shouldOpen);
      });
      refs.button.dataset.embeddingPickerListenerAttached = "true";
    }

    if (refs.list && refs.list.dataset.embeddingPickerListenerAttached !== "true") {
      refs.list.addEventListener("change", (event) => {
        const input = event.target;
        if (!input || !input.matches(".aipkit_vector_store_pinecone_radio")) {
          return;
        }
        select.value = input.value || "";
        select.dispatchEvent(new Event("change", { bubbles: true }));
        updateDropdownLabel(select, refs);
        renderDropdownOptions(select, refs, refs.search ? refs.search.value : "");
        setOpenState(refs, false);
      });
      refs.list.dataset.embeddingPickerListenerAttached = "true";
    }

    if (
      refs.search &&
      refs.search.dataset.embeddingPickerListenerAttached !== "true"
    ) {
      refs.search.addEventListener("input", () => {
        renderDropdownOptions(select, refs, refs.search.value);
        positionPanel(refs);
      });
      refs.search.dataset.embeddingPickerListenerAttached = "true";
    }

    if (select.dataset.embeddingPickerListenerAttached !== "true") {
      select.addEventListener("change", () => {
        updateDropdownLabel(select, refs);
        renderDropdownOptions(select, refs, refs.search ? refs.search.value : "");
      });
      select.dataset.embeddingPickerListenerAttached = "true";
    }

    if (select.dataset.embeddingPickerObserverAttached !== "true") {
      let queued = false;
      const observer = new MutationObserver(() => {
        if (queued) {
          return;
        }
        queued = true;
        window.requestAnimationFrame(() => {
          updateDropdownLabel(select, refs);
          renderDropdownOptions(select, refs, refs.search ? refs.search.value : "");
          queued = false;
        });
      });
      observer.observe(select, { childList: true, subtree: true });
      select.dataset.embeddingPickerObserverAttached = "true";
    }

    if (!document.body.dataset.aipkitChatEmbeddingPickerOutsideBound) {
      document.addEventListener("mousedown", (event) => {
        if (!activeDropdown || !activeDropdown.dropdown) {
          return;
        }
        if (activeDropdown.dropdown.contains(event.target)) {
          return;
        }
        closeActiveDropdown();
      });
      document.body.dataset.aipkitChatEmbeddingPickerOutsideBound = "true";
    }

    if (!document.body.dataset.aipkitChatEmbeddingPickerEscapeBound) {
      document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
          closeActiveDropdown();
        }
      });
      document.body.dataset.aipkitChatEmbeddingPickerEscapeBound = "true";
    }

    if (!document.body.dataset.aipkitChatEmbeddingPickerRepositionBound) {
      const handleReposition = () => {
        if (activeDropdown) {
          positionPanel(activeDropdown);
        }
      };
      window.addEventListener("resize", handleReposition);
      window.addEventListener("scroll", handleReposition, true);
      document.body.dataset.aipkitChatEmbeddingPickerRepositionBound = "true";
    }
  };

  function refreshEmbeddingPickers(refresh = false) {
    const builder = document.querySelector(".aipkit_chatbot_builder");
    if (!builder) return;

    const selects = builder.querySelectorAll(EMBEDDING_SELECT_SCOPE);
    selects.forEach((select) => {
      if (!select.id || (!refresh && select.dataset.aipkitEmbeddingPickerInit === "true")) {
        return;
      }
      const refs = ensurePickerElements(select);
      if (!refs) return;

      updateDropdownLabel(select, refs);
      renderDropdownOptions(select, refs, refs.search ? refs.search.value : "");
      bindPickerEvents(select, refs);
      select.dataset.aipkitEmbeddingPickerInit = "true";
    });
  }

  function aipkit_initChatEmbeddingPickers() {
    refreshEmbeddingPickers();
  }

  function aipkit_refreshChatEmbeddingPickers() {
    refreshEmbeddingPickers(true);
  }

  window.aipkit_initChatEmbeddingPickers = aipkit_initChatEmbeddingPickers;

  window.aipkit_refreshChatEmbeddingPickers = aipkit_refreshChatEmbeddingPickers;
}
