import {
  renderPopoverMultiselectOptions,
  syncPopoverMultiselectCheckboxesFromSelect,
  syncPopoverMultiselectSelectFromCheckboxes,
  setPopoverMultiselectLabel,
  setupSearchablePopoverMultiselect,
} from "./popover-multiselect.js";

(function () {
  "use strict";

  function aipkit_initCategoryDropdowns(scope) {
    const container = scope || document;
    if (!container) return;

    const dropdowns = container.querySelectorAll(
      "[data-aipkit-category-dropdown]"
    );
    if (!dropdowns.length) return;

    dropdowns.forEach((dropdown) => {
      const selectElement = dropdown.querySelector(
        "[data-aipkit-category-select]"
      );
      if (!selectElement) return;

      const dropdownButton = dropdown.querySelector(
        ".aipkit_popover_multiselect_btn"
      );
      const dropdownLabel = dropdown.querySelector(
        ".aipkit_popover_multiselect_label"
      );
      const dropdownPanel = dropdown.querySelector(
        ".aipkit_popover_multiselect_panel"
      );
      const dropdownOptions = dropdown.querySelector(
        ".aipkit_popover_multiselect_options"
      );
      const categorySearchInput = dropdown.querySelector(
        ".aipkit_searchable_multiselect_search_input"
      );
      const categoryNoResults = dropdown.querySelector(
        ".aipkit_searchable_multiselect_no_results"
      );
      const placeholder = dropdown.dataset.placeholder || "None";
      const selectedLabel = dropdown.dataset.selectedLabel || "selected";
      const noCategoriesText =
        categoryNoResults?.textContent?.trim() || "No categories found.";
      let searchableMenu = null;

      const updateDropdownLabel = () => {
        setPopoverMultiselectLabel(
          dropdownLabel,
          selectElement,
          placeholder,
          selectedLabel
        );
      };

      const renderDropdownOptions = () => {
        renderPopoverMultiselectOptions(
          dropdownOptions,
          selectElement,
          noCategoriesText,
          updateDropdownLabel
        );
        searchableMenu?.refresh();
      };

      const syncCheckboxesFromSelect = () => {
        syncPopoverMultiselectCheckboxesFromSelect(
          dropdownOptions,
          selectElement,
          updateDropdownLabel
        );
        searchableMenu?.refresh();
      };

      const syncSelectFromCheckboxes = () => {
        syncPopoverMultiselectSelectFromCheckboxes(
          selectElement,
          dropdownOptions,
          { updateLabel: updateDropdownLabel }
        );
        searchableMenu?.refresh();
      };

      const setOpenState = (open) => {
        if (!dropdown || !dropdownButton) return;
        dropdown.classList.toggle("is-open", open);
        dropdownButton.setAttribute("aria-expanded", open ? "true" : "false");
        if (dropdownPanel) {
          dropdownPanel.hidden = !open;
        }
        if (open) {
          syncCheckboxesFromSelect();
          searchableMenu?.prepareOpen();
        }
      };

      searchableMenu = setupSearchablePopoverMultiselect(dropdown, {
        searchPlaceholder:
          categorySearchInput?.placeholder || "Search categories",
        searchAriaLabel:
          categorySearchInput?.getAttribute("aria-label") ||
          "Search categories",
        noResultsText: noCategoriesText,
        dialogLabel: dropdownPanel?.getAttribute("aria-label") || "Categories",
        onEscape: () => {
          setOpenState(false);
          dropdownButton?.focus();
        },
      });

      renderDropdownOptions();
      syncCheckboxesFromSelect();

      if (!dropdown.dataset.categoriesMultiselectAttached) {
        if (dropdownButton) {
          dropdownButton.addEventListener("click", (event) => {
            event.preventDefault();
            setOpenState(!dropdown.classList.contains("is-open"));
          });
          dropdownButton.addEventListener("keydown", (event) => {
            if (event.key === "Escape") {
              setOpenState(false);
              dropdownButton.focus();
            }
          });
        }
        document.addEventListener("click", (event) => {
          if (!dropdown.classList.contains("is-open")) return;
          if (dropdown.contains(event.target)) return;
          setOpenState(false);
        });

        if (dropdownOptions && !dropdownOptions.dataset.listenerAttached) {
          dropdownOptions.addEventListener("change", syncSelectFromCheckboxes);
          dropdownOptions.dataset.listenerAttached = "true";
        }

        if (!selectElement.dataset.listenerAttached) {
          selectElement.addEventListener("change", syncCheckboxesFromSelect);
          selectElement.dataset.listenerAttached = "true";
        }

        dropdown.dataset.categoriesMultiselectAttached = "true";
      }
    });
  }

  window.aipkit_initCategoryDropdowns = aipkit_initCategoryDropdowns;
})();
