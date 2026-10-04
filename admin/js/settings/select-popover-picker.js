/**
 * Dashboard pricing AI selection popover.
 * Keeps the original <select> as the state and form-data source.
 */
import {
  positionFixedPopoverPanel,
  positionSelectPickerSidePanel,
  resetPopoverPanelPosition,
  updatePopoverDropupPlacement,
} from "../utils/ui/popover-position.js";
import {
  buildSelectPickerIds,
  collectSelectOptions,
  createSelectPickerLabel,
  createSelectPickerPopoverController,
  createSelectPickerRadioItem,
  ensureSelectPickerList,
  ensureSelectPickerSearchInput,
  getSelectPickerLabelText,
  isSelectPickerPopoverOpen,
  renderSelectPickerOptionGroups,
  setSelectPickerLabelText,
} from "../utils/ui/select-picker-items.js";

(function () {
  "use strict";

  const __ = window.wp?.i18n?.__ || function (str) { return str; };

  const SETTINGS_SELECT_SCOPE =
    "#aipkit_stats_container #aipkit_stats_pricing_ai_selection";

  const getPickerRootContainers = () =>
    ["aipkit_settings_container", "aipkit_stats_container"]
      .map((id) => document.getElementById(id))
      .filter(Boolean);

  const resetInlinePopoverPosition = resetPopoverPanelPosition;

  const isModalInlineDropdown = (popover) =>
    Boolean(popover && popover.closest(".aipkit-modal-content"));

  const updateInlinePopoverPlacement = (popover, panel) => {
    if (!popover || !panel) {
      return;
    }

    updatePopoverDropupPlacement(popover, panel);
  };

  const positionInlinePopoverPanel = (popover, panel) => {
    if (!popover || !panel) {
      return;
    }

    updateInlinePopoverPlacement(popover, panel);

    if (isModalInlineDropdown(popover)) {
      resetInlinePopoverPosition(panel);
      panel.style.position = "absolute";
      panel.style.left = "0";
      panel.style.right = "auto";
      panel.style.width = "100%";
      panel.style.minWidth = "100%";
      panel.style.maxWidth = "100%";

      if (popover.classList.contains("aipkit_settings_dropdown--dropup")) {
        panel.style.top = "auto";
        panel.style.bottom = "calc(100% + 6px)";
      } else {
        panel.style.top = "calc(100% + 6px)";
        panel.style.bottom = "auto";
      }

      return;
    }

    const triggerRect = popover.getBoundingClientRect();
    const minWidth = 260;
    const viewportMaxWidth = Math.max(160, window.innerWidth - 32);
    const panelWidth = Math.min(
      Math.max(triggerRect.width, minWidth),
      viewportMaxWidth
    );
    positionFixedPopoverPanel(popover, panel, panelWidth, minWidth);
  };

  const getLabelText = (select) =>
    getSelectPickerLabelText(select, {
      removeSelector: ".aipkit_form-label-helper",
      normalizeWhitespace: true,
    });

  const isInlineDropdownPopover = (popover) =>
    Boolean(
      popover &&
        popover.classList.contains("aipkit_settings_model_dropdown")
    );

  const isPopoverOpen = (popover) =>
    isSelectPickerPopoverOpen(popover, {
      inlinePredicate: isInlineDropdownPopover,
    });

  const ensurePickerElements = (select) => {
    if (!select || !select.id) return null;
    const ids = buildSelectPickerIds(select.id);
    const rootContainer = select.closest("#aipkit_settings_container, #aipkit_stats_container");
    if (!rootContainer) return null;

    let popover = document.getElementById(ids.popoverId);
    if (popover && !popover.classList.contains("aipkit_settings_model_dropdown")) {
      popover.remove();
      popover = null;
    }

    if (!popover) {
      popover = document.createElement("div");
      popover.id = ids.popoverId;
      popover.className = "aipkit_popover_multiselect aipkit_settings_model_dropdown";
      select.insertAdjacentElement("beforebegin", popover);
    }

    let button = document.getElementById(ids.buttonId);
    if (button && button.parentElement !== popover) {
      button.remove();
      button = null;
    }

    if (!button) {
      button = document.createElement("button");
      button.type = "button";
      button.id = ids.buttonId;
      button.className =
        "aipkit_popover_multiselect_btn aipkit_settings_select_picker_btn";
      button.setAttribute("aria-expanded", "false");

      button.appendChild(
        createSelectPickerLabel(
          select,
          ids.labelId,
          __("Select", "gpt3-ai-content-generator")
        )
      );

      popover.appendChild(button);
    }

    let panel = popover.querySelector(".aipkit_popover_multiselect_panel");
    if (!panel) {
      panel = document.createElement("div");
      panel.className =
        "aipkit_popover_multiselect_panel aipkit_settings_model_dropdown_panel";
      panel.hidden = true;
      panel.setAttribute("role", "menu");
      popover.appendChild(panel);
    }

    ensureSelectPickerSearchInput(panel, ids.searchId, {
      labelText: getLabelText(select),
      fallbackPlaceholder: __("Search...", "gpt3-ai-content-generator"),
    });

    const list = ensureSelectPickerList(panel, ids.listId);

    const panelId = `${ids.popoverId}_panel`;
    panel.id = panelId;
    button.setAttribute("aria-controls", panelId);

    if (!select.classList.contains("screen-reader-text")) {
      select.classList.add("screen-reader-text");
    }

    return {
      ids,
      button,
      popover,
      label: document.getElementById(ids.labelId),
      search: document.getElementById(ids.searchId),
      list: document.getElementById(ids.listId),
    };
  };

  const collectOptions = collectSelectOptions;

  const renderPickerList = (select, list, searchValue = "") => {
    if (!select || !list) return;

    renderSelectPickerOptionGroups({
      collectOptions,
      itemFactory: (option) =>
        createSelectPickerRadioItem(
          option,
          select.value,
          `aipkit_settings_picker_${select.id}`
        ),
      list,
      searchValue,
      select,
    });
  };

  const updatePickerLabel = (select, labelEl) => {
    if (!select || !labelEl) return;
    setSelectPickerLabelText(
      labelEl,
      select,
      __("Select", "gpt3-ai-content-generator")
    );
  };

  const positionPopover = (trigger, panel) => {
    if (!trigger || !panel) return;
    if (panel.closest(".aipkit_settings_model_dropdown")) {
      panel.style.left = "";
      panel.style.top = "";
      return;
    }
    positionSelectPickerSidePanel(trigger, panel);
  };

  const { bindPickerEvents } = createSelectPickerPopoverController({
    bindGlobalDatasetKey: "aipkitSettingsPicker",
    clearAllListsOnClose: true,
    focusBeforeInlinePosition: false,
    isInlineDropdownPopover,
    isPopoverOpen,
    positionInlinePopoverPanel,
    positionPopover,
    renderPickerList,
    resetInlinePopoverPosition,
    updatePickerLabel,
    updateStateOnItemSelected: true,
  });

  function aipkit_initSettingsSelectPickers() {
    const containers = getPickerRootContainers();
    if (!containers.length) return;

    containers.forEach((container) => {
      const selects = container.querySelectorAll(SETTINGS_SELECT_SCOPE);
      if (!selects.length) return;

      selects.forEach((select) => {
        if (!select.id) return;
        if (select.dataset.aipkitPickerInit === "true") return;
        const refs = ensurePickerElements(select);
        if (!refs) return;
        updatePickerLabel(select, refs.label);
        bindPickerEvents(select, refs);
        select.dataset.aipkitPickerInit = "true";
      });
    });
  }

  function aipkit_refreshSettingsSelectPickers() {
    const containers = getPickerRootContainers();
    if (!containers.length) return;

    containers.forEach((container) => {
      const selects = container.querySelectorAll(SETTINGS_SELECT_SCOPE);
      selects.forEach((select) => {
        if (!select.id) return;
        const refs = ensurePickerElements(select);
        if (!refs) return;
        updatePickerLabel(select, refs.label);
        if (isPopoverOpen(refs.popover)) {
          renderPickerList(select, refs.list, refs.search ? refs.search.value : "");
        }
        bindPickerEvents(select, refs);
        select.dataset.aipkitPickerInit = "true";
      });
    });
  }

  window.aipkit_initSettingsSelectPickers = aipkit_initSettingsSelectPickers;
  window.aipkit_refreshSettingsSelectPickers = aipkit_refreshSettingsSelectPickers;
})();
