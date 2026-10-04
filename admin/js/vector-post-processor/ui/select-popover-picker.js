/**
 * AIPKit Vector Post Processor - Select Picker
 * Mirrors the standard Settings/Chatbot dropdown structure while preserving
 * the original selects for existing provider logic.
 */
import {
  applySelectPickerProviderIconClass,
  bindSelectPickerEvents,
  buildSelectPickerIds,
  collectSelectOptions,
  createSelectPickerProviderDropdownItem,
  createSelectPickerRadioItem,
  ensureSelectPickerList,
  ensureSelectPickerSearchInput,
  getSelectPickerLabelText,
  getSelectSelectedLabel,
  isSelectPickerPopoverOpen,
  renderSelectPickerOptionGroups,
  setSelectPickerLabelText,
} from "../../utils/ui/select-picker-items.js";
import {
  resetPopoverPanelPosition,
  updatePopoverDropupPlacement,
} from "../../utils/ui/popover-position.js";

(function () {
  "use strict";

  const __ = window.wp?.i18n?.__ || function (str) { return str; };

  const MODAL_SELECTOR = "#aipkit_vpp_modal";
  const SELECT_SCOPE = [
    "#aipkit_vpp_vector_store_provider",
    "#aipkit_vpp_openai_vector_store_id",
    "#aipkit_vpp_google_file_search_store_name",
    "#aipkit_vpp_pinecone_target_index_select",
    "#aipkit_vpp_qdrant_target_collection_select",
    "#aipkit_vpp_chroma_target_collection_select",
    "#aipkit_vpp_local_store_select",
  ].join(", ");

  let activePopover = null;
  let activeTrigger = null;
  let activePanel = null;
  let repositionScheduled = false;

  const providerSelectIds = new Set(["aipkit_vpp_vector_store_provider"]);
  const modelStyleSelectIds = new Set([
    "aipkit_vpp_openai_vector_store_id",
    "aipkit_vpp_google_file_search_store_name",
    "aipkit_vpp_pinecone_target_index_select",
    "aipkit_vpp_qdrant_target_collection_select",
    "aipkit_vpp_chroma_target_collection_select",
    "aipkit_vpp_local_store_select",
  ]);

  const isProviderSelect = (select) =>
    Boolean(select && select.id && providerSelectIds.has(select.id));

  const isModelStyleSelect = (select) =>
    Boolean(select && select.id && modelStyleSelectIds.has(select.id));

  const isInlineDropdownPopover = (popover) =>
    Boolean(
      popover &&
        (popover.classList.contains("aipkit_settings_provider_dropdown") ||
          popover.classList.contains("aipkit_settings_model_dropdown"))
    );

  const isPopoverOpen = (popover) =>
    isSelectPickerPopoverOpen(popover, { inlineOnly: true });

  const resetInlinePopoverPosition = (panel) => {
    resetPopoverPanelPosition(panel, [
      "position",
      "left",
      "right",
      "top",
      "bottom",
      "width",
      "max-width",
      "min-width",
      "max-height",
      "visibility",
    ]);
  };

  const portalPanelToModal = (popover, panel) => {
    if (!popover || !panel) return;
    const modal = popover.closest(MODAL_SELECTOR);
    if (!modal || panel.parentElement === modal) return;
    panel.dataset.aipkitVppPanelOwner = popover.id || "";
    panel.classList.add("aipkit_vpp_dropdown_panel_portal");
    // Remove the panel from the overlay's flex layout before moving it.
    // Otherwise the modal shifts for one frame and the trigger is measured
    // against that temporary position.
    panel.style.position = "fixed";
    panel.style.visibility = "hidden";
    modal.appendChild(panel);
  };

  const restorePanelToPopover = (popover, panel) => {
    if (!popover || !panel || panel.parentElement === popover) return;
    panel.classList.remove("aipkit_vpp_dropdown_panel_portal");
    panel.removeAttribute("data-aipkit-vpp-panel-owner");
    popover.appendChild(panel);
  };

  const updateInlinePopoverPlacement = (popover, panel) => {
    if (!popover || !panel) return;

    const trigger =
      popover.querySelector(".aipkit_popover_multiselect_btn") || popover;
    updatePopoverDropupPlacement(popover, panel, { trigger });
  };

  const positionInlinePopoverPanel = (popover, panel) => {
    if (!popover || !panel) return;

    resetInlinePopoverPosition(panel);
    // A portaled panel must be out of the overlay's flex flow before the
    // trigger rectangle is read.
    panel.style.position = "fixed";

    const viewportPadding = 12;
    const dropdownGap = 6;
    const trigger =
      popover.querySelector(".aipkit_popover_multiselect_btn") || popover;
    const triggerRect = trigger.getBoundingClientRect();
    const maxViewportWidth = Math.max(
      180,
      window.innerWidth - viewportPadding * 2
    );
    const panelWidth = Math.min(triggerRect.width, maxViewportWidth);
    const left = Math.max(
      viewportPadding,
      Math.min(triggerRect.left, window.innerWidth - panelWidth - viewportPadding)
    );

    panel.style.left = `${left}px`;
    panel.style.right = "auto";
    panel.style.setProperty("width", `${panelWidth}px`, "important");
    panel.style.setProperty("min-width", `${panelWidth}px`, "important");
    panel.style.setProperty("max-width", `${panelWidth}px`, "important");
    panel.style.maxHeight = `${Math.max(
      160,
      window.innerHeight - viewportPadding * 2
    )}px`;

    updateInlinePopoverPlacement(popover, panel);

    const panelHeight =
      panel.getBoundingClientRect().height || panel.scrollHeight || 0;
    let top = popover.classList.contains("aipkit_settings_dropdown--dropup")
      ? triggerRect.top - panelHeight - dropdownGap
      : triggerRect.bottom + dropdownGap;

    if (top + panelHeight > window.innerHeight - viewportPadding) {
      top = window.innerHeight - viewportPadding - panelHeight;
    }
    top = Math.max(viewportPadding, top);

    panel.style.top = `${top}px`;
    panel.style.bottom = "auto";
    panel.style.visibility = "visible";
  };

  const applyProviderIconClass = (iconEl, providerValue) => {
    applySelectPickerProviderIconClass(iconEl, providerValue);
  };

  const getLabelText = (select) =>
    getSelectPickerLabelText(select, { normalizeWhitespace: true });

  const ensurePickerElements = (select, modal) => {
    if (!select || !select.id || !modal) return null;
    const ids = buildSelectPickerIds(select.id);
    const isProvider = isProviderSelect(select);
    const isModelStyle = isModelStyleSelect(select);
    const showSearch =
      !isProvider && select.dataset.aipkitPickerSearch !== "false";

    if (!isProvider && !isModelStyle) return null;

    let popover = document.getElementById(ids.popoverId);
    if (
      popover &&
      (!popover.classList.contains("aipkit_popover_multiselect") ||
        popover.parentElement !== select.parentElement)
    ) {
      popover.remove();
      popover = null;
    }

    const popoverClass = isProvider
      ? "aipkit_popover_multiselect aipkit_settings_provider_dropdown aipkit_vpp_provider_dropdown"
      : "aipkit_popover_multiselect aipkit_settings_model_dropdown aipkit_vpp_model_dropdown";

    if (!popover) {
      popover = document.createElement("div");
      popover.id = ids.popoverId;
      select.insertAdjacentElement("beforebegin", popover);
    }
    popover.className = popoverClass;

    const strayButton = document.getElementById(ids.buttonId);
    if (strayButton && strayButton.parentElement !== popover) {
      strayButton.remove();
    }

    let button = popover.querySelector(`#${ids.buttonId}`);
    if (!button) {
      button = document.createElement("button");
      button.type = "button";
      button.id = ids.buttonId;
      popover.appendChild(button);
    }

    button.className = isProvider
      ? "aipkit_popover_multiselect_btn aipkit_settings_select_picker_btn aipkit_settings_select_picker_btn--provider aipkit_vpp_provider_dropdown_btn"
      : "aipkit_popover_multiselect_btn aipkit_settings_select_picker_btn aipkit_vpp_model_dropdown_btn";
    button.setAttribute("aria-expanded", "false");
    button.disabled = select.disabled;

    if (isProvider) {
      let iconSpan = button.querySelector(".aipkit_settings_select_picker_icon");
      if (!iconSpan) {
        iconSpan = document.createElement("span");
        iconSpan.className = "aipkit_settings_select_picker_icon";
        iconSpan.setAttribute("aria-hidden", "true");
        button.insertBefore(iconSpan, button.firstChild);
      }
      applyProviderIconClass(iconSpan, select.value);
    } else {
      button
        .querySelectorAll(".aipkit_settings_select_picker_icon")
        .forEach((icon) => icon.remove());
    }

    let labelSpan = button.querySelector(`#${ids.labelId}`);
    if (!labelSpan) {
      labelSpan = document.createElement("span");
      labelSpan.id = ids.labelId;
      button.appendChild(labelSpan);
    }
    labelSpan.className =
      "aipkit_popover_multiselect_label aipkit_settings_select_picker_label";
    labelSpan.textContent =
      getSelectSelectedLabel(select) ||
      __("Select", "gpt3-ai-content-generator");

    button
      .querySelectorAll(".dashicons-arrow-down-alt2")
      .forEach((caret) => caret.remove());

    let panel = popover.querySelector(".aipkit_popover_multiselect_panel");
    if (!panel) {
      panel = document.createElement("div");
      popover.appendChild(panel);
    }
    panel.className = isProvider
      ? "aipkit_popover_multiselect_panel aipkit_settings_provider_dropdown_panel"
      : "aipkit_popover_multiselect_panel aipkit_settings_model_dropdown_panel";
    panel.hidden = true;
    panel.setAttribute("role", "menu");

    let searchWrap = panel.querySelector(".aipkit_settings_select_picker_search");
    if (showSearch) {
      ({ searchWrap } = ensureSelectPickerSearchInput(panel, ids.searchId, {
        labelText: getLabelText(select),
        fallbackPlaceholder: __("Search...", "gpt3-ai-content-generator"),
        insertFirst: true,
        scopedLookup: true,
      }));
    } else if (searchWrap) {
      searchWrap.remove();
      searchWrap = null;
    }

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
      label: labelSpan,
      search: showSearch ? panel.querySelector(`#${ids.searchId}`) : null,
      list,
    };
  };

  const collectOptions = (select) =>
    collectSelectOptions(select, { includeSelected: false });

  const renderPickerList = (select, list, searchValue = "") => {
    if (!select || !list) return;
    renderSelectPickerOptionGroups({
      collectOptions,
      emptyClassName:
        "aipkit_popover_multiselect_empty aipkit_settings_select_picker_empty",
      itemFactory: (option) =>
        isProviderSelect(select)
          ? createSelectPickerProviderDropdownItem(
            option,
            select.value,
            applyProviderIconClass
          )
          : createSelectPickerRadioItem(
              option,
              select.value,
              `aipkit_vpp_picker_${select.id}`
            ),
      list,
      searchValue,
      select,
    });
  };

  const updatePickerLabel = (select, refs) => {
    if (!select || !refs || !refs.label) return;
    setSelectPickerLabelText(
      refs.label,
      select,
      __("Select", "gpt3-ai-content-generator")
    );
    if (refs.button) {
      refs.button.disabled = select.disabled;
    }
    if (isProviderSelect(select)) {
      const icon = refs.button
        ? refs.button.querySelector(".aipkit_settings_select_picker_icon")
        : null;
      applyProviderIconClass(icon, select.value);
    }
  };

  const scheduleReposition = () => {
    if (!activePopover || repositionScheduled) return;
    repositionScheduled = true;
    window.requestAnimationFrame(() => {
      if (!activePopover) {
        repositionScheduled = false;
        return;
      }
      const panel =
        activePanel ||
        activePopover.querySelector(".aipkit_popover_multiselect_panel");
      positionInlinePopoverPanel(activePopover, panel);
      repositionScheduled = false;
    });
  };

  const closeActivePopover = () => {
    if (!activePopover) return;

    activePopover.classList.remove("is-open");
    activePopover.classList.remove("aipkit_settings_dropdown--dropup");

    const panel =
      activePanel ||
      activePopover.querySelector(".aipkit_popover_multiselect_panel");
    if (panel) {
      panel.hidden = true;
      resetInlinePopoverPosition(panel);
      restorePanelToPopover(activePopover, panel);
    }

    if (activeTrigger) {
      activeTrigger.setAttribute("aria-expanded", "false");
    }

    (panel || activePopover)
      .querySelectorAll(".aipkit_settings_select_picker_list")
      .forEach((list) => {
        list.innerHTML = "";
      });

    activePopover = null;
    activeTrigger = null;
    activePanel = null;
  };

  const openPopover = (trigger, popover, select) => {
    if (!trigger || !popover) return;
    if (activePopover && activePopover !== popover) {
      closeActivePopover();
    }

    activePopover = popover;
    activeTrigger = trigger;
    popover.classList.add("is-open");
    trigger.setAttribute("aria-expanded", "true");

    const panel = popover.querySelector(".aipkit_popover_multiselect_panel");
    if (panel) {
      panel.hidden = false;
    }

    const searchInput = (panel || popover).querySelector(
      ".aipkit_settings_select_picker_search input"
    );
    const list = (panel || popover).querySelector(
      ".aipkit_settings_select_picker_list"
    );
    if (list) {
      renderPickerList(select, list, searchInput ? searchInput.value : "");
    }

    if (panel) {
      activePanel = panel;
      portalPanelToModal(popover, panel);
      window.requestAnimationFrame(() => {
        positionInlinePopoverPanel(popover, panel);
      });
    }

    if (searchInput && searchInput.offsetParent !== null) {
      searchInput.focus();
      searchInput.select();
    }
  };

  const updatePickerState = (select, refs) => {
    updatePickerLabel(select, refs);
    if (isPopoverOpen(refs.popover)) {
      renderPickerList(select, refs.list, refs.search ? refs.search.value : "");
    }
  };

  const bindPickerEvents = (select, refs) => {
    bindSelectPickerEvents(select, refs, {
      closeActivePopover,
      isActivePopover: (popover) => activePopover === popover,
      observeOverlay: false,
      onBeforeOpen: ({ button }) => !button.disabled,
      onItemSelected: ({ refs, select }) => {
        updatePickerLabel(select, refs);
        closeActivePopover();
      },
      onSelectChanged: ({ refs, select }) => {
        updatePickerState(select, refs);
      },
      onSelectMutated: ({ refs, select }) => {
        updatePickerState(select, refs);
        if (isPopoverOpen(refs.popover)) {
          scheduleReposition();
        }
      },
      openPopover,
      observerOptions: {
        attributes: true,
        attributeFilter: ["disabled"],
        childList: true,
        subtree: true,
      },
      renderPickerList,
    });

    if (!document.body.dataset.aipkitVppPickerEscapeBound) {
      document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && activePopover) {
          closeActivePopover();
        }
      });
      document.body.dataset.aipkitVppPickerEscapeBound = "true";
    }

    if (!document.body.dataset.aipkitVppPickerOutsideBound) {
      document.addEventListener("mousedown", (event) => {
        if (!activePopover || !isInlineDropdownPopover(activePopover)) return;
        const target = event.target;
        if (
          activePopover.contains(target) ||
          (activePanel && activePanel.contains(target)) ||
          (activeTrigger && activeTrigger.contains(target))
        ) {
          return;
        }
        closeActivePopover();
      });
      document.body.dataset.aipkitVppPickerOutsideBound = "true";
    }

    if (!document.body.dataset.aipkitVppPickerRepositionBound) {
      window.addEventListener("resize", scheduleReposition);
      window.addEventListener("scroll", scheduleReposition, true);
      document.body.dataset.aipkitVppPickerRepositionBound = "true";
    }
  };

  function initVppSelectPickers(modal) {
    if (!modal || modal.dataset.aipkitVppPickerInit === "true") return;
    const selects = modal.querySelectorAll(SELECT_SCOPE);
    if (!selects.length) return;

    selects.forEach((select) => {
      if (!select.id) return;
      if (select.dataset.aipkitPickerInit === "true") return;
      const refs = ensurePickerElements(select, modal);
      if (!refs) return;
      updatePickerLabel(select, refs);
      bindPickerEvents(select, refs);
      select.dataset.aipkitPickerInit = "true";
    });

    modal.dataset.aipkitVppPickerInit = "true";
  }

  function scanForModal() {
    if (activePopover && !activePopover.isConnected) {
      closeActivePopover();
    }
    document.querySelectorAll(MODAL_SELECTOR).forEach(initVppSelectPickers);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", scanForModal);
  } else {
    scanForModal();
  }

  const observer = new MutationObserver(scanForModal);
  observer.observe(document.body, { childList: true, subtree: true });

  window.aipkit_initVppSelectPickers = initVppSelectPickers;
})();
