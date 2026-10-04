/**
 * AIPKit Chatbot - Provider/Model Select Picker
 * Replaces native selects with a popover picker (search + groups).
 */
import {
  positionFixedPopoverPanel,
  positionPopoverBelowTrigger,
  resetPopoverPanelPosition,
  updatePopoverDropupPlacement,
} from "../utils/ui/popover-position.js";
import {
  applySelectPickerProviderIconClass,
  buildSelectPickerIds,
  collectSelectOptions,
  createSelectPickerCheckItem,
  createSelectPickerLabel,
  createSelectPickerPopoverController,
  createSelectPickerProviderDropdownItem,
  createSelectPickerRadioItem,
  createSelectPickerTriggerButtonWithLabel,
  ensureSelectPickerDialogPopover,
  ensureSelectPickerList,
  ensureSelectPickerSearchInput,
  getSelectPickerLabelText,
  renderSelectPickerOptionGroups,
  setSelectPickerLabelText,
  syncSelectPickerItemState,
} from "../utils/ui/select-picker-items.js";

(function () {
  "use strict";

  const __ = window.wp?.i18n?.__ || function (str) { return str; };

  const CHAT_SELECT_SCOPE =
    ".aipkit_vector_store_provider_select, " +
    ".aipkit_builder_ai_model .aipkit_chatbot_provider_select, " +
    ".aipkit_builder_ai_model .aipkit_chatbot_model_field select";

  const isProviderSelect = (select) =>
    Boolean(
      select &&
        (select.classList.contains("aipkit_chatbot_provider_select") ||
          select.classList.contains("aipkit_vector_store_provider_select"))
    );

  const isModelStateSelect = (select) =>
    Boolean(
      select &&
        (select.dataset.aipkitModelStateSelect === "1" ||
          select.closest(".aipkit_model_state_controls"))
    );

  const isVectorStoreProviderSelect = (select) =>
    Boolean(
      select &&
        select.classList.contains("aipkit_vector_store_provider_select")
    );

  const isChatbotAiProviderSelect = (select) =>
    Boolean(select && select.classList.contains("aipkit_chatbot_provider_select"));

  const isInlineProviderSelect = (select) =>
    isVectorStoreProviderSelect(select) || isChatbotAiProviderSelect(select);

  const isEmbeddingModelSelect = (select) =>
    Boolean(
      select &&
        select.classList.contains("aipkit_vector_embedding_select") &&
        select.dataset.aipkitUniversalModelCombined !== "1"
    );

  const isChatbotAiModelSelect = (select) =>
    Boolean(
      select &&
        select.closest(".aipkit_builder_ai_model") &&
        select.closest(".aipkit_chatbot_model_field")
    );

  const isInlineModelSelect = (select) =>
    isEmbeddingModelSelect(select) || isChatbotAiModelSelect(select);

  const isInlineDropdownPopover = (popover) =>
    Boolean(
      popover &&
        (popover.classList.contains("aipkit_settings_provider_dropdown") ||
          popover.classList.contains("aipkit_settings_model_dropdown") ||
          popover.classList.contains("aipkit_vector_provider_dropdown") ||
          popover.classList.contains("aipkit_ai_provider_dropdown"))
    );

  const resetInlinePopoverPosition = resetPopoverPanelPosition;

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

    const triggerRect = popover.getBoundingClientRect();
    const viewportMaxWidth = Math.max(160, window.innerWidth - 32);
    const isSettingsProviderDropdown = popover.classList.contains(
      "aipkit_settings_provider_dropdown"
    );
    const isWidthMatchedDropdown =
      !isSettingsProviderDropdown &&
      (popover.classList.contains("aipkit_vector_provider_dropdown") ||
        popover.classList.contains("aipkit_ai_provider_dropdown") ||
        popover.classList.contains("aipkit_chatbot_ai_model_dropdown"));
    let minWidth = 260;
    if (isSettingsProviderDropdown) {
      minWidth = 220;
    } else if (isWidthMatchedDropdown) {
      minWidth = triggerRect.width;
    }
    const panelWidth = isWidthMatchedDropdown
      ? Math.min(triggerRect.width, viewportMaxWidth)
      : Math.min(Math.max(triggerRect.width, minWidth), viewportMaxWidth);
    positionFixedPopoverPanel(popover, panel, panelWidth, minWidth);
  };

  const applyProviderIconClass = (iconEl, providerValue) => {
    applySelectPickerProviderIconClass(iconEl, providerValue, {
      aliases: {
        claude_files: "claude",
      },
    });
  };

  const getLabelText = (select) => getSelectPickerLabelText(select);

  const ensurePickerElements = (select) => {
    if (!select || !select.id) return null;
    const ids = buildSelectPickerIds(select.id);
    const builder = select.closest(".aipkit_chatbot_builder");
    if (!builder) return null;

    if (isInlineProviderSelect(select)) {
      const isVectorProvider = isVectorStoreProviderSelect(select);
      const providerDropdownClass = isVectorProvider
        ? "aipkit_vector_provider_dropdown"
        : "aipkit_ai_provider_dropdown";
      const popoverClass = isVectorProvider
        ? `aipkit_popover_multiselect aipkit_vector_store_pinecone_dropdown ${providerDropdownClass}`
        : `aipkit_popover_multiselect aipkit_settings_provider_dropdown ${providerDropdownClass}`;
      const buttonClass = isVectorProvider
        ? `aipkit_popover_multiselect_btn aipkit_vector_provider_dropdown_btn ${providerDropdownClass}_btn`
        : `aipkit_popover_multiselect_btn aipkit_settings_select_picker_btn aipkit_settings_select_picker_btn--provider ${providerDropdownClass}_btn`;
      const panelClass = isVectorProvider
        ? "aipkit_popover_multiselect_panel aipkit_vector_store_pinecone_panel"
        : "aipkit_popover_multiselect_panel aipkit_settings_provider_dropdown_panel";
      const listClass = isVectorProvider
        ? "aipkit_popover_multiselect_options aipkit_vector_store_pinecone_options aipkit_settings_select_picker_list"
        : "aipkit_popover_multiselect_options aipkit_settings_select_picker_list";
      let popover = document.getElementById(ids.popoverId);
      if (
        popover &&
        (!popover.classList.contains(providerDropdownClass) ||
          (!isVectorProvider &&
            !popover.classList.contains("aipkit_settings_provider_dropdown")))
      ) {
        popover.remove();
        popover = null;
      }

      if (!popover) {
        popover = document.createElement("div");
        popover.id = ids.popoverId;
        popover.className = popoverClass;
        select.insertAdjacentElement("beforebegin", popover);
      }

      let button = document.getElementById(ids.buttonId);
      if (
        button &&
        (button.parentElement !== popover ||
          (!isVectorProvider &&
            !button.classList.contains("aipkit_settings_select_picker_btn")))
      ) {
        button.remove();
        button = null;
      }

      if (!button) {
        button = document.createElement("button");
        button.type = "button";
        button.id = ids.buttonId;
        button.className = buttonClass;
        button.setAttribute("aria-expanded", "false");

        const iconSpan = document.createElement("span");
        iconSpan.className = isVectorProvider
          ? `aipkit_settings_select_picker_icon aipkit_vector_provider_dropdown_icon ${providerDropdownClass}_icon`
          : `aipkit_settings_select_picker_icon ${providerDropdownClass}_icon`;
        iconSpan.setAttribute("aria-hidden", "true");
        applyProviderIconClass(iconSpan, select.value);
        button.appendChild(iconSpan);

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
        panel.hidden = true;
        panel.setAttribute("role", "menu");
        popover.appendChild(panel);
      }
      panel.className = panelClass;

      const list = ensureSelectPickerList(panel, ids.listId, listClass);

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
        search: null,
        list: document.getElementById(ids.listId),
      };
    }

    if (isInlineModelSelect(select)) {
      const isEmbeddingModel = isEmbeddingModelSelect(select);
      const modelDropdownVariantClass = isEmbeddingModel
        ? "aipkit_embedding_model_dropdown"
        : "aipkit_chatbot_ai_model_dropdown";
      const oppositeVariantClass = isEmbeddingModel
        ? "aipkit_chatbot_ai_model_dropdown"
        : "aipkit_embedding_model_dropdown";
      let popover = document.getElementById(ids.popoverId);
      if (popover && !popover.classList.contains("aipkit_settings_model_dropdown")) {
        popover.remove();
        popover = null;
      }

      if (!popover) {
        popover = document.createElement("div");
        popover.id = ids.popoverId;
        popover.className = `aipkit_popover_multiselect aipkit_settings_model_dropdown ${modelDropdownVariantClass}`;
        select.insertAdjacentElement("beforebegin", popover);
      } else {
        popover.classList.add("aipkit_settings_model_dropdown");
        popover.classList.add(modelDropdownVariantClass);
        popover.classList.remove(oppositeVariantClass);
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

      const legacyCaret = button.querySelector(".dashicons.dashicons-arrow-down-alt2");
      if (legacyCaret) {
        legacyCaret.remove();
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
    }

    let button = document.getElementById(ids.buttonId);
    if (!button) {
      button = createSelectPickerTriggerButtonWithLabel(
        select,
        ids,
        {
          applyProviderIconClass,
          fallbackLabel: __("Select", "gpt3-ai-content-generator"),
          isProvider: isProviderSelect(select),
        }
      );
      select.insertAdjacentElement("beforebegin", button);
    }

    if (!select.classList.contains("screen-reader-text")) {
      select.classList.add("screen-reader-text");
    }

    const labelText = getLabelText(select);
    const popover = ensureSelectPickerDialogPopover(select, ids, builder, {
      closeLabel: __("Close", "gpt3-ai-content-generator"),
      fallbackPlaceholder: __("Search...", "gpt3-ai-content-generator"),
      hideSearch: isProviderSelect(select),
      isProvider: isProviderSelect(select),
      labelText,
      titleText: labelText || __("Select", "gpt3-ai-content-generator"),
    });

    return {
      ids,
      button,
      popover,
      label: document.getElementById(ids.labelId),
      search: document.getElementById(ids.searchId),
      list: document.getElementById(ids.listId),
    };
  };

  const renderPickerList = (select, list, searchValue = "") => {
    if (!select || !list) return;
    list.innerHTML = "";

    if (isInlineProviderSelect(select)) {
      const radioPrefix = isVectorStoreProviderSelect(select)
        ? "aipkit_vector_provider_picker_"
        : "aipkit_ai_provider_picker_";
      const radioName = `${radioPrefix}${select.id}`;
      const useSettingsProviderRows = isChatbotAiProviderSelect(select);

      renderSelectPickerOptionGroups({
        collectOptions: (currentSelect) => [
          {
            groupLabel: "",
            options: Array.from(currentSelect.options || [])
              .map((opt) => ({
                value: opt.value,
                label: opt.textContent.trim(),
                disabled: opt.disabled,
              }))
              .filter((option) => option.value || !option.disabled),
          },
        ],
        emptyClassName: "aipkit_popover_multiselect_empty",
        getGroupLabel: () => "",
        itemFactory: (option) => {
          if (useSettingsProviderRows) {
            return createSelectPickerProviderDropdownItem(
              option,
              select.value,
              applyProviderIconClass
            );
          }

          const item = document.createElement("label");
          item.className =
            "aipkit_vector_provider_dropdown_item aipkit_popover_multiselect_item aipkit_vector_store_pinecone_item";
          item.setAttribute("role", "option");
          syncSelectPickerItemState(item, option, select.value);

          const icon = document.createElement("span");
          icon.className = "aipkit_settings_select_picker_icon";
          icon.setAttribute("aria-hidden", "true");
          applyProviderIconClass(icon, option.value);

          const label = document.createElement("span");
          label.className = "aipkit_popover_multiselect_text";
          label.textContent = option.label;

          const rowLabel = document.createElement("span");
          rowLabel.className = "aipkit_vector_store_pinecone_item_label";

          const radio = document.createElement("input");
          radio.type = "radio";
          radio.className = "aipkit_vector_store_pinecone_radio";
          radio.name = radioName;
          radio.value = option.value;
          radio.checked = select.value === option.value;
          radio.disabled = Boolean(option.disabled);

          rowLabel.appendChild(radio);
          rowLabel.appendChild(icon);
          rowLabel.appendChild(label);
          item.appendChild(rowLabel);
          return item;
        },
        list,
        searchValue,
        select,
      });
      return;
    }

    if (isInlineModelSelect(select)) {
      renderSelectPickerOptionGroups({
        collectOptions: collectSelectOptions,
        itemFactory: (option) =>
          createSelectPickerRadioItem(
            option,
            select.value,
            `aipkit_model_picker_${select.id}`
          ),
        list,
        searchValue,
        select,
      });
      return;
    }

    renderSelectPickerOptionGroups({
      collectOptions: collectSelectOptions,
      itemFactory: (option) =>
        createSelectPickerCheckItem(option, select.value, {
          applyProviderIconClass,
          isProvider: isProviderSelect(select),
        }),
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
    if (isProviderSelect(select)) {
      const button = labelEl.closest("button");
      const icon = button
        ? button.querySelector(".aipkit_settings_select_picker_icon")
        : null;
      applyProviderIconClass(icon, select.value);
    }
  };

  const positionPopover = (trigger, panel) => {
    positionPopoverBelowTrigger(trigger, panel);
  };

  const { bindPickerEvents, isPopoverOpen } =
    createSelectPickerPopoverController({
      bindGlobalDatasetKey: "aipkitChatPicker",
      closeOnSelectChanged: true,
      isInlineDropdownPopover,
      listItemSelector:
        ".aipkit_settings_select_picker_item, .aipkit_vector_provider_dropdown_item",
      positionInlinePopoverPanel,
      positionPopover,
      renderPickerList,
      resetInlinePopoverPosition,
      updatePickerLabel,
    });

  function aipkit_initChatSelectPickers() {
    const builder = document.querySelector(".aipkit_chatbot_builder");
    if (!builder) return;
    const selects = builder.querySelectorAll(CHAT_SELECT_SCOPE);
    if (!selects.length) return;

    selects.forEach((select) => {
      if (!select.id) return;
      if (isModelStateSelect(select)) return;
      if (select.dataset.aipkitPickerInit === "true") return;
      const refs = ensurePickerElements(select);
      if (!refs) return;
      updatePickerLabel(select, refs.label);
      bindPickerEvents(select, refs);
      select.dataset.aipkitPickerInit = "true";
    });
  }

  function aipkit_refreshChatSelectPickers() {
    const builder = document.querySelector(".aipkit_chatbot_builder");
    if (!builder) return;
    const selects = builder.querySelectorAll(CHAT_SELECT_SCOPE);
    selects.forEach((select) => {
      if (!select.id) return;
      if (isModelStateSelect(select)) return;
      const refs = ensurePickerElements(select);
      if (!refs) return;
      updatePickerLabel(select, refs.label);
      if (isPopoverOpen(refs.popover)) {
        renderPickerList(select, refs.list, refs.search ? refs.search.value : "");
      }
      bindPickerEvents(select, refs);
      select.dataset.aipkitPickerInit = "true";
    });
  }

  window.aipkit_initChatSelectPickers = aipkit_initChatSelectPickers;
  window.aipkit_refreshChatSelectPickers = aipkit_refreshChatSelectPickers;
})();
