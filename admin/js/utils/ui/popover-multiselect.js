export function createPopoverMultiselectItem(
  option,
  { allowPreservedRemoval = false, preservedRemovalHint = "" } = {}
) {
  const row = document.createElement("label");
  row.className = "aipkit_popover_multiselect_item";

  const isPreservedSelection =
    option?.dataset?.aipkitPreservedSelection === "1";
  const canRemovePreservedSelection =
    allowPreservedRemoval && isPreservedSelection;

  const checkbox = document.createElement("input");
  checkbox.type = "checkbox";
  checkbox.value = option.value;
  checkbox.checked = option.selected;
  checkbox.disabled = option.disabled && !canRemovePreservedSelection;

  const text = document.createElement("span");
  text.className = "aipkit_popover_multiselect_text";
  text.textContent = option.textContent;

  row.appendChild(checkbox);

  if (canRemovePreservedSelection) {
    row.classList.add("aipkit_popover_multiselect_item--preserved");
    checkbox.dataset.aipkitPreservedSelection = "1";

    const copy = document.createElement("span");
    copy.className = "aipkit_popover_multiselect_copy";
    copy.appendChild(text);

    if (preservedRemovalHint) {
      const hint = document.createElement("span");
      hint.className = "aipkit_popover_multiselect_hint";
      hint.textContent = preservedRemovalHint;
      copy.appendChild(hint);
      checkbox.setAttribute(
        "aria-label",
        `${option.textContent}. ${preservedRemovalHint}`
      );
    }

    row.appendChild(copy);
  } else {
    row.appendChild(text);
  }

  return row;
}

export function renderPopoverMultiselectOptions(
  dropdownOptions,
  selectElement,
  emptyFallbackText,
  updateLabel = () => {}
) {
  if (!dropdownOptions || !selectElement) return;

  dropdownOptions.innerHTML = "";
  const options = Array.from(selectElement.options);
  const usableOptions = options.filter((opt) => opt.value);

  if (!usableOptions.length) {
    const empty = document.createElement("div");
    empty.className = "aipkit_popover_multiselect_empty";
    empty.textContent = options[0]?.textContent || emptyFallbackText;
    dropdownOptions.appendChild(empty);
    updateLabel();
    return;
  }

  usableOptions.forEach((option) => {
    dropdownOptions.appendChild(createPopoverMultiselectItem(option));
  });

  updateLabel();
}

/**
 * Adds the shared searchable-menu treatment to a popover multiselect.
 * Existing panels are upgraded in place so feature modules can keep their
 * original select names, values, and persistence logic.
 */
export function setupSearchablePopoverMultiselect(
  dropdown,
  {
    searchPlaceholder = "Search",
    searchAriaLabel = searchPlaceholder,
    noResultsText = "No results found.",
    dialogLabel = "Options",
    onEscape = null,
  } = {}
) {
  const panel = dropdown?.querySelector(".aipkit_popover_multiselect_panel");
  const options = dropdown?.querySelector(
    ".aipkit_popover_multiselect_options"
  );
  if (!dropdown || !panel || !options) return null;

  dropdown.classList.add("aipkit_searchable_multiselect");
  panel.classList.add("aipkit_searchable_multiselect_panel");
  panel.setAttribute("role", "dialog");
  panel.setAttribute("aria-label", dialogLabel);

  const trigger = dropdown.querySelector("button[aria-controls]");
  trigger?.setAttribute("aria-haspopup", "dialog");

  let searchWrap = panel.querySelector(
    ".aipkit_searchable_multiselect_search"
  );
  if (!searchWrap) {
    searchWrap = document.createElement("div");
    searchWrap.className = "aipkit_searchable_multiselect_search";

    const searchIcon = document.createElement("span");
    searchIcon.className = "dashicons dashicons-search";
    searchIcon.setAttribute("aria-hidden", "true");

    const searchInput = document.createElement("input");
    searchInput.type = "search";
    searchInput.className = "aipkit_searchable_multiselect_search_input";
    searchInput.autocomplete = "off";

    searchWrap.append(searchIcon, searchInput);
    panel.insertBefore(searchWrap, panel.firstChild);
  }

  const searchInput = searchWrap.querySelector(
    ".aipkit_searchable_multiselect_search_input"
  );
  if (searchInput) {
    searchInput.placeholder = searchPlaceholder;
    searchInput.setAttribute("aria-label", searchAriaLabel);
    searchInput._aipkitSearchableMultiselectOnEscape = onEscape;
  }

  let noResults = panel.querySelector(
    ".aipkit_searchable_multiselect_no_results"
  );
  if (!noResults) {
    noResults = document.createElement("div");
    noResults.className = "aipkit_searchable_multiselect_no_results";
    noResults.hidden = true;
    options.insertAdjacentElement("afterend", noResults);
  }
  noResults.textContent = noResultsText;

  const getItems = () =>
    Array.from(options.querySelectorAll(".aipkit_popover_multiselect_item"));

  const registerItemOrder = () => {
    getItems().forEach((item, index) => {
      if (!item.dataset.aipkitMultiselectOrder) {
        item.dataset.aipkitMultiselectOrder = String(index + 1);
      }
    });
  };

  const updateSelectedStyles = () => {
    getItems().forEach((item) => {
      const checkbox = item.querySelector('input[type="checkbox"]');
      item.classList.toggle("is-selected", Boolean(checkbox?.checked));
    });
  };

  const filterOptions = () => {
    const query = (searchInput?.value || "").trim().toLocaleLowerCase();
    let visibleCount = 0;

    getItems().forEach((item) => {
      const label = (
        item.querySelector(".aipkit_popover_multiselect_text")?.textContent ||
        ""
      ).toLocaleLowerCase();
      const matches = !query || label.includes(query);
      item.hidden = !matches;
      if (matches) visibleCount += 1;
    });

    const hasEmptyState = Boolean(
      options.querySelector(".aipkit_popover_multiselect_empty")
    );
    noResults.hidden = visibleCount > 0 || hasEmptyState || !query;
  };

  const sortSelectedFirst = () => {
    getItems()
      .sort((first, second) => {
        const firstSelected = Boolean(
          first.querySelector('input[type="checkbox"]')?.checked
        );
        const secondSelected = Boolean(
          second.querySelector('input[type="checkbox"]')?.checked
        );
        if (firstSelected !== secondSelected) {
          return firstSelected ? -1 : 1;
        }
        return (
          Number(first.dataset.aipkitMultiselectOrder || 0) -
          Number(second.dataset.aipkitMultiselectOrder || 0)
        );
      })
      .forEach((item) => options.appendChild(item));
  };

  const refresh = () => {
    registerItemOrder();
    updateSelectedStyles();
    filterOptions();
  };

  const prepareOpen = () => {
    refresh();
    sortSelectedFirst();
    if (searchInput) searchInput.value = "";
    filterOptions();
    window.requestAnimationFrame(() => searchInput?.focus());
  };

  if (searchInput && !searchInput.dataset.listenerAttached) {
    searchInput.addEventListener("input", filterOptions);
    searchInput.addEventListener("keydown", (event) => {
      if (event.key !== "Escape") return;
      event.preventDefault();
      const escapeHandler =
        searchInput._aipkitSearchableMultiselectOnEscape;
      if (typeof escapeHandler === "function") escapeHandler();
    });
    searchInput.dataset.listenerAttached = "true";
  }

  return { refresh, prepareOpen };
}

export function syncPopoverMultiselectCheckboxesFromSelect(
  dropdownOptions,
  selectElement,
  updateLabel = () => {}
) {
  if (!dropdownOptions || !selectElement) {
    updateLabel();
    return;
  }

  const selectedValues = new Set(
    Array.from(selectElement.options)
      .filter((opt) => opt.selected)
      .map((opt) => opt.value)
  );
  dropdownOptions
    .querySelectorAll('input[type="checkbox"]')
    .forEach((input) => {
      input.checked = selectedValues.has(input.value);
    });
  updateLabel();
}

export function syncPopoverMultiselectSelectFromCheckboxes(
  selectElement,
  dropdownOptions,
  { event = null, maxSelections = 0, updateLabel = () => {} } = {}
) {
  if (!dropdownOptions || !selectElement) return;

  const checkedInputs = Array.from(
    dropdownOptions.querySelectorAll('input[type="checkbox"]:checked')
  );
  if (maxSelections > 0 && checkedInputs.length > maxSelections) {
    const target = event && event.target;
    if (target && target.checked) {
      target.checked = false;
    } else {
      const lastChecked = checkedInputs[checkedInputs.length - 1];
      if (lastChecked) {
        lastChecked.checked = false;
      }
    }
  }

  const selectedValues = Array.from(
    dropdownOptions.querySelectorAll('input[type="checkbox"]:checked')
  ).map((input) => input.value);
  Array.from(selectElement.options).forEach((opt) => {
    opt.selected = selectedValues.includes(opt.value);
  });
  selectElement.dispatchEvent(new Event("change", { bubbles: true }));
  updateLabel();
}

export function getPopoverMultiselectRefs(dropdown) {
  return {
    dropdownButton: dropdown
      ? dropdown.querySelector(".aipkit_popover_multiselect_btn")
      : null,
    dropdownLabel: dropdown
      ? dropdown.querySelector(".aipkit_popover_multiselect_label")
      : null,
    dropdownPanel: dropdown
      ? dropdown.querySelector(".aipkit_popover_multiselect_panel")
      : null,
    dropdownOptions: dropdown
      ? dropdown.querySelector(".aipkit_popover_multiselect_options")
      : null,
  };
}

export function getPopoverMultiselectLabelText(
  selectElement,
  placeholder,
  selectedLabel,
  { includeDisabled = true } = {}
) {
  const selectedOptions = Array.from(selectElement?.options || []).filter(
    (option) =>
      option.selected &&
      option.value &&
      (includeDisabled || !option.disabled)
  );
  if (!selectedOptions.length) {
    return placeholder;
  }

  const labels = selectedOptions
    .map((option) => option.textContent.trim())
    .filter(Boolean);
  return labels.length <= 2 ? labels.join(", ") : `${labels.length} ${selectedLabel}`;
}

export function setPopoverMultiselectLabel(
  dropdownLabel,
  selectElement,
  placeholder,
  selectedLabel,
  options
) {
  if (!dropdownLabel) {
    return;
  }
  const labelText = getPopoverMultiselectLabelText(
    selectElement,
    placeholder,
    selectedLabel,
    options
  );
  dropdownLabel.textContent = labelText;
  dropdownLabel.title = labelText;

  const dropdownButton = dropdownLabel.closest(
    ".aipkit_popover_multiselect_btn"
  );
  if (dropdownButton) {
    dropdownButton.title = labelText;
  }
}
