export function createSelectPickerRadioItem(option, selectedValue, radioName) {
  const item = document.createElement("label");
  item.className =
    "aipkit_settings_select_picker_item aipkit_popover_multiselect_item aipkit_settings_model_dropdown_item";
  item.setAttribute("role", "option");
  syncSelectPickerItemState(item, option, selectedValue);

  const rowLabel = document.createElement("span");
  rowLabel.className = "aipkit_settings_model_dropdown_item_label";

  const radio = document.createElement("input");
  radio.type = "radio";
  radio.className = "aipkit_settings_select_picker_radio";
  radio.name = radioName;
  radio.value = option.value;
  radio.checked = selectedValue === option.value;
  radio.disabled = Boolean(option.disabled);

  const label = document.createElement("span");
  label.className =
    "aipkit_settings_select_picker_text aipkit_popover_multiselect_text";
  label.textContent = option.label;

  rowLabel.appendChild(radio);
  rowLabel.appendChild(label);
  item.appendChild(rowLabel);

  return item;
}

export function createSelectPickerProviderDropdownItem(
  option,
  selectedValue,
  applyIconClass
) {
  const item = document.createElement("button");
  item.type = "button";
  item.className =
    "aipkit_settings_select_picker_item aipkit_popover_multiselect_item aipkit_settings_provider_dropdown_item";
  item.setAttribute("role", "option");
  syncSelectPickerItemState(item, option, selectedValue);

  const icon = document.createElement("span");
  icon.className = "aipkit_settings_select_picker_icon";
  icon.setAttribute("aria-hidden", "true");
  if (typeof applyIconClass === "function") {
    applyIconClass(icon, option.value, option);
  }

  const label = document.createElement("span");
  label.className =
    "aipkit_settings_select_picker_text aipkit_popover_multiselect_text";
  label.textContent = option.label;

  item.appendChild(icon);
  item.appendChild(label);
  return item;
}

export function createSelectPickerCheckItem(
  option,
  selectedValue,
  {
    applyProviderIconClass = null,
    extraClassNames = [],
    isProvider = false,
  } = {}
) {
  const item = document.createElement("button");
  item.type = "button";
  item.className = "aipkit_settings_select_picker_item";
  item.setAttribute("role", "option");

  if (extraClassNames.length) {
    item.classList.add(...extraClassNames);
  }

  syncSelectPickerItemState(item, option, selectedValue);

  if (isProvider) {
    item.classList.add("aipkit_settings_select_picker_item--provider");
    const icon = document.createElement("span");
    icon.className = "aipkit_settings_select_picker_icon";
    icon.setAttribute("aria-hidden", "true");
    if (typeof applyProviderIconClass === "function") {
      applyProviderIconClass(icon, option.value, option);
    }
    item.appendChild(icon);
  }

  const label = document.createElement("span");
  label.className = "aipkit_settings_select_picker_text";
  label.textContent = option.label;

  const check = document.createElement("span");
  check.className =
    "dashicons dashicons-yes aipkit_settings_select_picker_check";

  item.appendChild(label);
  item.appendChild(check);
  return item;
}

export function syncSelectPickerItemState(item, option, selectedValue) {
  if (!item || !option) return;

  item.dataset.value = option.value;
  item.dataset.label = option.label;

  if (option.disabled) {
    item.classList.add("is-disabled");
    item.setAttribute("aria-disabled", "true");
  } else {
    item.classList.remove("is-disabled");
    item.removeAttribute("aria-disabled");
  }

  const isSelected = selectedValue === option.value;
  item.classList.toggle("is-active", isSelected);
  item.setAttribute("aria-selected", isSelected ? "true" : "false");
}

function getSelectPickerText(text) {
  const translate = window.wp?.i18n?.__ || ((value) => value);
  return translate(text, "gpt3-ai-content-generator");
}

export function getSelectPickerSearchTerm(searchValue = "") {
  return String(searchValue || "").trim().toLowerCase();
}

export function appendSelectPickerGroupLabel(
  container,
  label,
  className = "aipkit_settings_select_picker_group_label"
) {
  if (!container || !label) return null;

  const groupLabel = document.createElement("div");
  groupLabel.className = className;
  groupLabel.textContent = label;
  container.appendChild(groupLabel);
  return groupLabel;
}

export function appendSelectPickerEmptyState(
  container,
  searchTerm = "",
  className = "aipkit_settings_select_picker_empty"
) {
  if (!container) return null;

  const empty = document.createElement("div");
  empty.className = className;
  empty.textContent = searchTerm
    ? getSelectPickerText("No matches found.")
    : getSelectPickerText("No options available.");
  container.appendChild(empty);
  return empty;
}

export function renderSelectPickerOptionGroups({
  appendToFragment = false,
  collectOptions = collectSelectOptions,
  emptyClassName = "aipkit_settings_select_picker_empty",
  groupLabelClassName = "aipkit_settings_select_picker_group_label",
  getGroupLabel = (block) => block.groupLabel || block.label || "",
  itemFactory = null,
  list,
  matchGroupLabel = false,
  searchValue = "",
  select,
  setRenderedTerm = false,
} = {}) {
  if (!select || !list || typeof itemFactory !== "function") {
    return { hasAny: false, term: getSelectPickerSearchTerm(searchValue) };
  }

  const term = getSelectPickerSearchTerm(searchValue);
  const target = appendToFragment ? document.createDocumentFragment() : list;
  let hasAny = false;

  list.innerHTML = "";

  collectOptions(select).forEach((block) => {
    const groupLabel = String(getGroupLabel(block) || "").trim();
    const groupMatches =
      matchGroupLabel && term && groupLabel.toLowerCase().includes(term);
    const options = Array.isArray(block.options) ? block.options : [];
    const filtered =
      !term || groupMatches
        ? options
        : options.filter((option) =>
            String(option.label || "").toLowerCase().includes(term)
          );

    if (!filtered.length) {
      return;
    }

    if (groupLabel) {
      appendSelectPickerGroupLabel(target, groupLabel, groupLabelClassName);
    }

    filtered.forEach((option) => {
      const item = itemFactory(option, { block, groupLabel, select, term });
      if (!item) {
        return;
      }
      target.appendChild(item);
      hasAny = true;
    });
  });

  if (!hasAny) {
    appendSelectPickerEmptyState(target, term, emptyClassName);
  }

  if (appendToFragment) {
    list.appendChild(target);
  }
  if (setRenderedTerm) {
    list.dataset.aipkitPickerRenderedTerm = term;
  }

  return { hasAny, term };
}

export function createSelectPickerLabel(
  select,
  labelId,
  fallbackLabel = "Select",
  className = "aipkit_popover_multiselect_label aipkit_settings_select_picker_label"
) {
  const labelSpan = document.createElement("span");
  labelSpan.className = className;
  labelSpan.id = labelId;
  setSelectPickerLabelText(labelSpan, select, fallbackLabel);
  return labelSpan;
}

export function getSelectSelectedLabel(select) {
  if (!select) return "";
  const selected =
    select.selectedOptions && select.selectedOptions.length
      ? select.selectedOptions[0]
      : select.querySelector("option[selected]");
  if (selected) {
    return (
      selected.dataset.pickerLabel || selected.textContent || ""
    ).trim();
  }
  const firstOption = select.querySelector("option");
  return firstOption ? firstOption.textContent.trim() : "";
}

export function setSelectPickerLabelText(
  labelEl,
  select,
  fallbackLabel = "Select"
) {
  if (!labelEl) return;
  labelEl.textContent = getSelectSelectedLabel(select) || fallbackLabel;
}

export function getSelectPickerLabelText(
  select,
  {
    labelContainerSelector = ".aipkit_form-group",
    labelSelector = ".aipkit_form-label",
    removeSelector = "",
    normalizeWhitespace = false,
  } = {}
) {
  if (!select) return "";

  const explicit = select.getAttribute("data-aipkit-picker-title");
  if (explicit) return explicit;

  const label =
    (select.id && document.querySelector(`label[for="${select.id}"]`)) ||
    select.closest(labelContainerSelector)?.querySelector(labelSelector);
  if (!label) return "";

  if (!removeSelector) {
    const text = label.textContent || "";
    return normalizeWhitespace ? text.replace(/\s+/g, " ").trim() : text.trim();
  }

  const clone = label.cloneNode(true);
  clone.querySelectorAll(removeSelector).forEach((element) => {
    element.remove();
  });
  const text = clone.textContent || "";
  return normalizeWhitespace ? text.replace(/\s+/g, " ").trim() : text.trim();
}

export function isSelectPickerPopoverOpen(
  popover,
  { inlineOnly = false, inlinePredicate = null } = {}
) {
  if (!popover) return false;

  if (inlineOnly) {
    return popover.classList.contains("is-open");
  }

  if (typeof inlinePredicate === "function") {
    return inlinePredicate(popover)
      ? popover.classList.contains("is-open")
      : popover.classList.contains("aipkit-active");
  }

  return (
    popover.classList.contains("aipkit-active") ||
    popover.classList.contains("is-open")
  );
}

export function collectSelectOptions(
  select,
  { groupLabelKey = "groupLabel", includeSelected = true } = {}
) {
  const blocks = [];
  if (!select) return blocks;

  Array.from(select.children).forEach((child) => {
    if (child.tagName === "OPTGROUP") {
      const options = Array.from(child.children)
        .filter((opt) => opt.tagName === "OPTION")
        .map((opt) => {
          const option = {
            value: opt.value,
            label: opt.textContent.trim(),
            disabled: opt.disabled,
          };
          if (includeSelected) {
            option.selected = opt.selected;
          }
          return option;
        });
      blocks.push({
        [groupLabelKey]: child.label || "",
        options,
      });
    } else if (child.tagName === "OPTION") {
      const option = {
        value: child.value,
        label: child.textContent.trim(),
        disabled: child.disabled,
      };
      if (includeSelected) {
        option.selected = child.selected;
      }
      blocks.push({
        [groupLabelKey]: "",
        options: [option],
      });
    }
  });

  return blocks;
}

export function ensureSelectPickerList(
  panel,
  listId,
  className = "aipkit_popover_multiselect_options aipkit_settings_select_picker_list"
) {
  if (!panel) return null;
  let list = panel.querySelector(".aipkit_settings_select_picker_list");
  if (!list) {
    list = document.createElement("div");
    panel.appendChild(list);
  }
  list.className = className;
  list.id = listId;
  list.setAttribute("role", "listbox");
  return list;
}

export function ensureSelectPickerSearchInput(
  container,
  searchId,
  {
    labelText = "",
    fallbackPlaceholder = "Search...",
    inputClassName = "aipkit_form-input",
    insertFirst = false,
    scopedLookup = false,
  } = {}
) {
  if (!container || !searchId) return { searchWrap: null, searchInput: null };

  let searchWrap = container.querySelector(".aipkit_settings_select_picker_search");
  if (!searchWrap) {
    searchWrap = document.createElement("div");
    searchWrap.className = "aipkit_settings_select_picker_search";
    if (insertFirst && container.firstChild) {
      container.insertBefore(searchWrap, container.firstChild);
    } else {
      container.appendChild(searchWrap);
    }
  }

  let searchInput = scopedLookup
    ? searchWrap.querySelector(`#${searchId}`)
    : document.getElementById(searchId);
  if (!searchInput || searchInput.parentElement !== searchWrap) {
    searchInput = document.createElement("input");
    searchInput.type = "search";
    searchInput.id = searchId;
    searchInput.className = inputClassName;
    searchWrap.appendChild(searchInput);
  }

  searchInput.setAttribute(
    "placeholder",
    labelText ? `Search ${labelText.toLowerCase()}...` : fallbackPlaceholder
  );
  searchWrap.style.display = "";

  return { searchWrap, searchInput };
}

export function buildSelectPickerIds(selectId) {
  return {
    buttonId: `${selectId}_picker_btn`,
    labelId: `${selectId}_picker_label`,
    popoverId: `${selectId}_picker_popover`,
    titleId: `${selectId}_picker_title`,
    searchId: `${selectId}_picker_search`,
    listId: `${selectId}_picker_list`,
  };
}

export function getSelectPickerProviderIconKey(value, aliases = {}) {
  const normalized = String(value || "").trim().toLowerCase();
  return aliases[normalized] || normalized;
}

export function applySelectPickerProviderIconClass(
  iconEl,
  providerValue,
  {
    aliases = {},
    allowedKeys = null,
    emptyClass = "is-hidden",
    prefix = "aipkit_settings_select_picker_icon--",
    useHiddenAttribute = false,
  } = {}
) {
  if (!iconEl) return "";

  iconEl.classList.forEach((cls) => {
    if (cls.startsWith(prefix)) {
      iconEl.classList.remove(cls);
    }
  });

  if (emptyClass) {
    iconEl.classList.remove(emptyClass);
  }
  if (useHiddenAttribute) {
    iconEl.hidden = false;
  }

  const key = getSelectPickerProviderIconKey(providerValue, aliases);
  const isAllowed =
    !allowedKeys ||
    (allowedKeys instanceof Set ? allowedKeys.has(key) : allowedKeys.includes(key));

  if (!key || !isAllowed) {
    if (useHiddenAttribute) {
      iconEl.hidden = true;
    } else if (emptyClass) {
      iconEl.classList.add(emptyClass);
    }
    return "";
  }

  iconEl.classList.add(`${prefix}${key}`);
  return key;
}

export function createSelectPickerTriggerButton(ids) {
  const button = document.createElement("button");
  button.type = "button";
  button.id = ids.buttonId;
  button.className =
    "aipkit_popover_option_btn aipkit_settings_select_picker_btn";
  button.setAttribute("data-aipkit-popover-target", ids.popoverId);
  button.setAttribute("aria-controls", ids.popoverId);
  button.setAttribute("aria-expanded", "false");
  button.dataset.aipkitPopoverPlacement = "bottom";
  return button;
}

export function createSelectPickerTriggerButtonWithLabel(
  select,
  ids,
  {
    applyProviderIconClass = null,
    fallbackLabel = "Select",
    isProvider = false,
  } = {}
) {
  const button = createSelectPickerTriggerButton(ids);

  if (isProvider) {
    button.classList.add("aipkit_settings_select_picker_btn--provider");
    const iconSpan = document.createElement("span");
    iconSpan.className = "aipkit_settings_select_picker_icon";
    iconSpan.setAttribute("aria-hidden", "true");
    if (typeof applyProviderIconClass === "function") {
      applyProviderIconClass(iconSpan, select?.value || "", select);
    }
    button.appendChild(iconSpan);
  }

  button.appendChild(
    createSelectPickerLabel(
      select,
      ids.labelId,
      fallbackLabel,
      "aipkit_settings_select_picker_label"
    )
  );

  const caret = document.createElement("span");
  caret.className = "dashicons dashicons-arrow-down-alt2";
  caret.setAttribute("aria-hidden", "true");
  button.appendChild(caret);

  return button;
}

export function createSelectPickerCloseButton(closeLabel = "Close") {
  const closeBtn = document.createElement("button");
  closeBtn.type = "button";
  closeBtn.className = "aipkit_model_settings_popover_close";
  closeBtn.setAttribute("aria-label", closeLabel);
  closeBtn.innerHTML = '<span class="dashicons dashicons-no-alt"></span>';
  return closeBtn;
}

export function ensureSelectPickerDialogPopover(
  select,
  ids,
  mountPoint,
  {
    closeLabel = "Close",
    fallbackPlaceholder = "Search...",
    hideSearch = false,
    isProvider = false,
    labelText = "",
    titleText = "Select",
  } = {}
) {
  if (!select || !ids || !mountPoint) return null;

  let popover = mountPoint.querySelector(`#${ids.popoverId}`);
  if (!popover) {
    popover = document.createElement("div");
    popover.className =
      "aipkit_model_settings_popover aipkit_settings_select_picker_popover";
    if (isProvider) {
      popover.classList.add("aipkit_settings_select_picker_popover--provider");
    }
    popover.id = ids.popoverId;
    popover.setAttribute("aria-hidden", "true");

    const panel = document.createElement("div");
    panel.className =
      "aipkit_model_settings_popover_panel aipkit_settings_select_picker_popover_panel";
    panel.setAttribute("role", "dialog");
    panel.setAttribute("aria-labelledby", ids.titleId);

    const header = document.createElement("div");
    header.className = "aipkit_model_settings_popover_header";

    const title = document.createElement("span");
    title.className = "aipkit_model_settings_popover_title";
    title.id = ids.titleId;
    title.textContent = titleText;

    const closeBtn = createSelectPickerCloseButton(closeLabel);
    header.appendChild(title);
    header.appendChild(closeBtn);

    const body = document.createElement("div");
    body.className = "aipkit_model_settings_popover_body";

    const { searchWrap } = ensureSelectPickerSearchInput(body, ids.searchId, {
      labelText,
      fallbackPlaceholder,
    });

    const list = document.createElement("div");
    list.className = "aipkit_settings_select_picker_list";
    list.id = ids.listId;
    list.setAttribute("role", "listbox");

    body.appendChild(list);
    panel.appendChild(header);
    panel.appendChild(body);
    popover.appendChild(panel);
    mountPoint.appendChild(popover);

    if (hideSearch) {
      searchWrap.style.display = "none";
    }
  }

  return popover;
}

export function createSelectPickerPopoverController({
  bindGlobalDatasetKey,
  clearAllListsOnClose = false,
  closeOnSelectChanged = false,
  focusBeforeInlinePosition = true,
  isInlineDropdownPopover = () => false,
  isPopoverOpen = isSelectPickerPopoverOpen,
  listItemSelector = ".aipkit_settings_select_picker_item",
  positionInlinePopoverPanel = () => {},
  positionPopover = () => {},
  renderPickerList = () => {},
  resetInlinePopoverPosition = () => {},
  updatePickerLabel = () => {},
  updateStateOnItemSelected = false,
} = {}) {
  let activePopover = null;
  let activeTrigger = null;
  let repositionScheduled = false;

  const clearPickerLists = (popover) => {
    if (!popover) return;
    const lists = clearAllListsOnClose
      ? popover.querySelectorAll(".aipkit_settings_select_picker_list")
      : [popover.querySelector(".aipkit_settings_select_picker_list")].filter(Boolean);
    lists.forEach((list) => {
      list.innerHTML = "";
    });
  };

  const updatePickerState = (select, refs) => {
    updatePickerLabel(select, refs.label);
    if (isPopoverOpen(refs.popover)) {
      renderPickerList(select, refs.list, refs.search ? refs.search.value : "");
    }
  };

  const closeActivePopover = () => {
    if (!activePopover) return;

    if (isInlineDropdownPopover(activePopover)) {
      activePopover.classList.remove("is-open");
      activePopover.classList.remove("aipkit_settings_dropdown--dropup");
      const panel = activePopover.querySelector(".aipkit_popover_multiselect_panel");
      if (panel) {
        panel.hidden = true;
        resetInlinePopoverPosition(panel);
      }
    } else {
      activePopover.classList.remove("aipkit-active");
      activePopover.setAttribute("aria-hidden", "true");
    }

    if (activeTrigger) {
      activeTrigger.setAttribute("aria-expanded", "false");
    }
    clearPickerLists(activePopover);
    activePopover = null;
    activeTrigger = null;
  };

  const focusSearchInput = (searchInput) => {
    if (searchInput && searchInput.offsetParent !== null) {
      searchInput.focus();
      searchInput.select();
    }
  };

  const openPopover = (trigger, popover, select) => {
    if (!trigger || !popover) return;
    if (activePopover && activePopover !== popover) {
      closeActivePopover();
    }

    activePopover = popover;
    activeTrigger = trigger;
    const isInlineDropdown = isInlineDropdownPopover(popover);

    if (isInlineDropdown) {
      popover.classList.add("is-open");
      const dropdownPanel = popover.querySelector(".aipkit_popover_multiselect_panel");
      if (dropdownPanel) {
        dropdownPanel.hidden = false;
      }
    } else {
      popover.classList.add("aipkit-active");
      popover.setAttribute("aria-hidden", "false");
    }

    trigger.setAttribute("aria-expanded", "true");

    const searchInput = popover.querySelector(
      ".aipkit_settings_select_picker_search input"
    );
    const list = popover.querySelector(".aipkit_settings_select_picker_list");
    if (list) {
      renderPickerList(select, list, searchInput ? searchInput.value : "");
    }
    if (focusBeforeInlinePosition) {
      focusSearchInput(searchInput);
    }

    if (isInlineDropdown) {
      const dropdownPanel = popover.querySelector(".aipkit_popover_multiselect_panel");
      if (dropdownPanel) {
        window.requestAnimationFrame(() => {
          positionInlinePopoverPanel(popover, dropdownPanel);
        });
      }
      if (!focusBeforeInlinePosition) {
        focusSearchInput(searchInput);
      }
      return;
    }

    if (!focusBeforeInlinePosition) {
      focusSearchInput(searchInput);
    }

    const panel = popover.querySelector(".aipkit_model_settings_popover_panel");
    window.requestAnimationFrame(() => {
      positionPopover(trigger, panel);
    });
  };

  const scheduleReposition = () => {
    if (!activePopover || !activeTrigger || repositionScheduled) return;
    repositionScheduled = true;
    window.requestAnimationFrame(() => {
      if (!activePopover || !activeTrigger) {
        repositionScheduled = false;
        return;
      }
      if (isInlineDropdownPopover(activePopover)) {
        const inlinePanel = activePopover.querySelector(
          ".aipkit_popover_multiselect_panel"
        );
        if (inlinePanel && !inlinePanel.hidden) {
          positionInlinePopoverPanel(activePopover, inlinePanel);
        }
        repositionScheduled = false;
        return;
      }
      const panel = activePopover.querySelector(
        ".aipkit_model_settings_popover_panel"
      );
      positionPopover(activeTrigger, panel);
      repositionScheduled = false;
    });
  };

  const bindPickerEvents = (select, refs) => {
    bindSelectPickerEvents(select, refs, {
      closeActivePopover,
      isActivePopover: (popover) => activePopover === popover,
      isInlineDropdownPopover,
      listItemSelector,
      onItemSelected: ({ refs, select }) => {
        if (updateStateOnItemSelected) {
          updatePickerState(select, refs);
        } else {
          updatePickerLabel(select, refs.label);
        }
        closeActivePopover();
      },
      onSelectChanged: ({ refs, select }) => {
        updatePickerState(select, refs);
        if (closeOnSelectChanged && activePopover === refs.popover) {
          closeActivePopover();
        }
      },
      onSelectMutated: ({ refs, select }) => {
        updatePickerState(select, refs);
      },
      openPopover,
      renderPickerList,
    });

    if (!bindGlobalDatasetKey) {
      return;
    }

    const escapeKey = `${bindGlobalDatasetKey}EscapeBound`;
    if (!document.body.dataset[escapeKey]) {
      document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && activePopover) {
          closeActivePopover();
        }
      });
      document.body.dataset[escapeKey] = "true";
    }

    const outsideKey = `${bindGlobalDatasetKey}OutsideBound`;
    if (!document.body.dataset[outsideKey]) {
      document.addEventListener("mousedown", (event) => {
        if (!activePopover || !isInlineDropdownPopover(activePopover)) {
          return;
        }

        const target = event.target;
        if (
          activePopover.contains(target) ||
          (activeTrigger && activeTrigger.contains(target))
        ) {
          return;
        }

        closeActivePopover();
      });
      document.body.dataset[outsideKey] = "true";
    }

    const repositionKey = `${bindGlobalDatasetKey}RepositionBound`;
    if (!document.body.dataset[repositionKey]) {
      window.addEventListener("resize", scheduleReposition);
      window.addEventListener("scroll", scheduleReposition, true);
      document.body.dataset[repositionKey] = "true";
    }
  };

  return {
    bindPickerEvents,
    closeActivePopover,
    isPopoverOpen,
    openPopover,
    scheduleReposition,
    updatePickerState,
  };
}

export function bindSelectPickerEvents(select, refs, config = {}) {
  if (!select || !refs) return;

  const { button, popover, list, search } = refs;
  const {
    closeActivePopover = () => {},
    isActivePopover = () => false,
    isInlineDropdownPopover = () => false,
    listItemSelector = ".aipkit_settings_select_picker_item",
    observeOverlay = true,
    observerOptions = { childList: true, subtree: true },
    onBeforeOpen = () => true,
    onItemSelected = () => {},
    onSelectChanged = () => {},
    onSelectMutated = () => {},
    openPopover = () => {},
    renderPickerList = () => {},
  } = config;

  if (button && button.dataset.pickerListenerAttached !== "true") {
    button.addEventListener("click", (event) => {
      event.preventDefault();
      if (onBeforeOpen({ button, event, popover, refs, select }) === false) {
        return;
      }
      if (isActivePopover(popover)) {
        closeActivePopover();
        return;
      }
      openPopover(button, popover, select);
    });
    button.dataset.pickerListenerAttached = "true";
  }

  if (
    observeOverlay &&
    popover &&
    !isInlineDropdownPopover(popover) &&
    popover.dataset.overlayListenerAttached !== "true"
  ) {
    popover.addEventListener("click", (event) => {
      if (event.target === popover) {
        closeActivePopover();
      }
    });
    const closeBtn = popover.querySelector(".aipkit_model_settings_popover_close");
    if (closeBtn) {
      closeBtn.addEventListener("click", (event) => {
        event.preventDefault();
        closeActivePopover();
      });
    }
    popover.dataset.overlayListenerAttached = "true";
  }

  if (list && list.dataset.pickerListenerAttached !== "true") {
    list.addEventListener("click", (event) => {
      const item = event.target.closest(listItemSelector);
      if (!item || item.classList.contains("is-disabled")) return;

      const value = item.dataset.value ?? "";
      select.value = value;
      select.dispatchEvent(new Event("change", { bubbles: true }));
      onItemSelected({ event, item, refs, select, value });
    });
    list.dataset.pickerListenerAttached = "true";
  }

  if (search && search.dataset.pickerListenerAttached !== "true") {
    search.addEventListener("input", () => {
      renderPickerList(select, list, search.value);
    });
    search.dataset.pickerListenerAttached = "true";
  }

  if (select.dataset.pickerListenerAttached !== "true") {
    select.addEventListener("change", () => {
      onSelectChanged({ refs, select });
    });
    select.dataset.pickerListenerAttached = "true";
  }

  if (select.dataset.pickerObserverAttached !== "true") {
    let renderQueued = false;
    const observer = new MutationObserver(() => {
      if (renderQueued) return;
      renderQueued = true;
      window.requestAnimationFrame(() => {
        onSelectMutated({ refs, select });
        renderQueued = false;
      });
    });
    observer.observe(select, observerOptions);
    select.dataset.pickerObserverAttached = "true";
  }
}
