import { getFixedDropdownPanelMetrics } from "../../../utils/ui/popover-position.js";

/**
 * AIPKit Content Writer - Template Picker
 * Searchable, grouped picker with per-row actions for owned custom templates.
 */
(function () {
  "use strict";

  const __ = window.wp?.i18n?.__ || ((value) => value);
  const sprintf =
    window.wp?.i18n?.sprintf ||
    ((format, value) => String(format || "").replace("%s", value));

  let activeDropdown = null;
  let templateDropdownRuntimeRule = "";

  function getStoredTemplateLabel() {
    try {
      return (
        sessionStorage.getItem("aipkit_cw_last_template_label") || ""
      ).trim();
    } catch (error) {
      return "";
    }
  }

  function getTemplatePresentation(template) {
    const rawName = String(template?.template_name || "").trim();
    const wordRange = rawName.match(
      /^(.*?)\s*\(\s*([\d,]+)\s*[-–]\s*([\d,]+)\s+words?\s*\)\s*$/i
    );

    if (!wordRange) {
      return {
        label: rawName,
        meta: "",
      };
    }

    return {
      label: wordRange[1].trim(),
      meta: `${wordRange[2]}–${wordRange[3]} ${__(
        "words",
        "gpt3-ai-content-generator"
      )}`,
    };
  }

  function getCompactTemplateLabel(label) {
    return String(label || "")
      .replace(/\s*\([^)]*\bwords?\b[^)]*\)\s*$/i, "")
      .trim();
  }

  function getTemplateRuntimeStyleTag() {
    return document.getElementById(
      "aipkit_content_writer_template_dropdown_runtime_styles"
    );
  }

  function syncTemplateRuntimeStyleTag() {
    let styleTag = getTemplateRuntimeStyleTag();

    if (!templateDropdownRuntimeRule) {
      if (styleTag) {
        styleTag.remove();
      }
      return;
    }

    if (!styleTag) {
      styleTag = document.createElement("style");
      styleTag.id = "aipkit_content_writer_template_dropdown_runtime_styles";
      document.head.appendChild(styleTag);
    }

    styleTag.textContent = templateDropdownRuntimeRule;
  }

  function clearTemplateDropdownRuntimeRule() {
    templateDropdownRuntimeRule = "";
    syncTemplateRuntimeStyleTag();
  }

  function setTemplateDropdownRuntimeRule(metrics) {
    templateDropdownRuntimeRule =
      `#aipkit_cw_template_picker_panel{position:fixed !important;left:${Math.round(
        metrics.left
      )}px !important;right:auto !important;top:${Math.round(
        metrics.top
      )}px !important;bottom:auto !important;width:${Math.round(
        metrics.width
      )}px !important;min-width:${Math.round(
        metrics.minWidth
      )}px !important;max-width:${Math.round(
        metrics.maxWidth
      )}px !important;box-sizing:border-box;}`;
    syncTemplateRuntimeStyleTag();
  }

  function positionTemplateDropdownPanel(dropdown, panel, button) {
    if (!dropdown || !panel || !button) {
      return;
    }

    const triggerRect = button.getBoundingClientRect();
    const container = dropdown.closest("#aipkit_content_writer_container");
    const containerStyles = container
      ? window.getComputedStyle(container)
      : null;
    const preferredWidth = parseFloat(
      containerStyles?.getPropertyValue("--aipkit-cw-dropdown-panel-width") ||
        ""
    );
    const preferredMinWidth = parseFloat(
      containerStyles?.getPropertyValue(
        "--aipkit-cw-dropdown-panel-min-width"
      ) || ""
    );
    const viewportMaxWidth = Math.max(160, window.innerWidth - 32);
    const resolvedPreferredWidth = Number.isFinite(preferredWidth)
      ? preferredWidth
      : 360;
    const resolvedPreferredMinWidth = Number.isFinite(preferredMinWidth)
      ? preferredMinWidth
      : 320;
    const measuredTriggerWidth = Math.max(
      triggerRect.width || 0,
      button.offsetWidth || 0,
      button.clientWidth || 0
    );
    const panelWidth = Math.min(
      Math.max(Math.ceil(measuredTriggerWidth), resolvedPreferredMinWidth),
      Math.min(resolvedPreferredWidth, viewportMaxWidth)
    );
    const metrics = getFixedDropdownPanelMetrics(button, panel, panelWidth);

    if (metrics) {
      setTemplateDropdownRuntimeRule(metrics);
    }
  }

  function getTemplateControls() {
    return {
      select: document.getElementById("aipkit_cw_template_select"),
      dropdown: document.getElementById("aipkit_cw_template_dropdown"),
      button: document.getElementById("aipkit_cw_template_picker_btn"),
      panel: document.getElementById("aipkit_cw_template_picker_panel"),
      label: document.getElementById("aipkit_cw_template_picker_label"),
      search: document.getElementById("aipkit_cw_template_search"),
      list: document.getElementById("aipkit_cw_template_picker_list"),
      footer: document.querySelector(".aipkit_cw_template_footer"),
      saveAsButton: document.getElementById("aipkit_cw_save_as_template_btn"),
      resetButton: document.getElementById(
        "aipkit_cw_reset_starter_templates_btn"
      ),
      inlinePanel: document.getElementById("aipkit_cw_tpl_inline_panel"),
      inlineTitle: document.getElementById("aipkit_cw_tpl_inline_title"),
      inlineField: document.getElementById("aipkit_cw_tpl_inline_field"),
      inlineInput: document.getElementById("aipkit_cw_tpl_inline_input"),
      inlineError: document.getElementById("aipkit_cw_tpl_inline_error"),
      inlineCancel: document.getElementById("aipkit_cw_tpl_inline_cancel"),
      inlineConfirm: document.getElementById("aipkit_cw_tpl_inline_confirm"),
    };
  }

  function getStarterOrder(template) {
    if (
      template?.starter_order !== undefined &&
      template?.starter_order !== null &&
      template?.starter_order !== ""
    ) {
      const parsed = Number(template.starter_order);
      if (Number.isFinite(parsed)) {
        return parsed;
      }
    }

    const match = String(template?.template_name || "").match(/(\d[\d,]*)/);
    if (!match) {
      return null;
    }

    const parsedName = parseInt(match[1].replace(/,/g, ""), 10);
    return Number.isFinite(parsedName) ? parsedName : null;
  }

  function isStarterTemplate(template) {
    return (
      String(template?.is_starter) === "1" || template?.is_starter === true
    );
  }

  function canManageTemplate(template, currentUserId) {
    return (
      String(template?.user_id) === currentUserId &&
      String(template?.is_default) !== "1" &&
      !isStarterTemplate(template)
    );
  }

  function buildTemplateGroups(templates, currentUserId) {
    const starterTemplates = [];
    const myTemplates = [];
    const sharedGroups = new Map();

    templates.forEach((template) => {
      if (String(template.user_id) === currentUserId) {
        if (isStarterTemplate(template)) {
          starterTemplates.push(template);
        } else {
          myTemplates.push(template);
        }
        return;
      }

      const displayName = String(template.display_name || "").trim();
      if (!displayName) {
        return;
      }
      if (!sharedGroups.has(displayName)) {
        sharedGroups.set(displayName, []);
      }
      sharedGroups.get(displayName).push(template);
    });

    starterTemplates.sort((a, b) => {
      const orderA = getStarterOrder(a);
      const orderB = getStarterOrder(b);
      if (orderA !== null && orderB !== null) {
        return orderA - orderB;
      }
      if (orderA !== null) {
        return -1;
      }
      if (orderB !== null) {
        return 1;
      }
      return String(a.template_name).localeCompare(String(b.template_name));
    });

    myTemplates.sort((a, b) =>
      String(a.template_name).localeCompare(String(b.template_name))
    );

    return {
      starterTemplates,
      myTemplates,
      sharedGroups: new Map(
        Array.from(sharedGroups.entries())
          .sort(([nameA], [nameB]) => nameA.localeCompare(nameB))
          .map(([name, groupTemplates]) => [
            name,
            groupTemplates.sort((a, b) =>
              String(a.template_name).localeCompare(String(b.template_name))
            ),
          ])
      ),
    };
  }

  function appendOptionGroup(selectElement, label, templates) {
    if (!templates.length) {
      return;
    }

    const optgroup = document.createElement("optgroup");
    optgroup.label = label;
    templates.forEach((template) => {
      optgroup.appendChild(
        new Option(String(template.template_name), String(template.id))
      );
    });
    selectElement.appendChild(optgroup);
  }

  function updatePickerLabel(select, labelElement) {
    if (!select || !labelElement) {
      return;
    }

    const selected = select.selectedOptions?.[0] || null;
    const selectedValue = String(selected?.value || "").trim();
    const selectedLabel = selectedValue
      ? String(selected?.textContent || "").trim()
      : "";
    const fullLabel =
      selectedLabel ||
      getStoredTemplateLabel() ||
      __("Select template", "gpt3-ai-content-generator");

    labelElement.textContent =
      getCompactTemplateLabel(fullLabel) || fullLabel;
  }

  function getEnabledTemplateItems(list) {
    if (!list) {
      return [];
    }
    return Array.from(
      list.querySelectorAll(".aipkit_cw_template_item")
    ).filter((item) => !item.classList.contains("is-disabled"));
  }

  function focusTemplateItemByIndex(list, index) {
    const items = getEnabledTemplateItems(list);
    if (!items.length) {
      return false;
    }
    items[Math.max(0, Math.min(index, items.length - 1))].focus();
    return true;
  }

  function updateTemplateListScrollState(list) {
    if (!list) {
      return;
    }

    const panel = list.closest(".aipkit_popover_multiselect_panel");
    const isVisible =
      !!panel && !panel.hidden && list.getClientRects().length > 0;
    list.classList.toggle(
      "is-scrollable",
      isVisible && list.scrollHeight - list.clientHeight > 8
    );
  }

  function selectTemplateValue(select, value) {
    if (!select) {
      return;
    }
    select.value = value;
    select.dispatchEvent(new Event("change", { bubbles: true }));
  }

  function clearInlineError(refs) {
    if (!refs.inlineError) {
      return;
    }
    refs.inlineError.textContent = "";
    refs.inlineError.hidden = true;
  }

  function setInlineError(refs, message) {
    if (!refs.inlineError) {
      return;
    }
    refs.inlineError.textContent = message;
    refs.inlineError.hidden = !message;
  }

  function hideInlineAction(refs, restoreFocus = false) {
    if (!refs.inlinePanel || !refs.footer) {
      return;
    }

    refs.inlinePanel.hidden = true;
    refs.footer.hidden = false;
    refs.inlinePanel.dataset.action = "";
    refs.inlinePanel.dataset.templateId = "";
    clearInlineError(refs);

    if (refs.inlineField) {
      refs.inlineField.hidden = true;
    }
    if (refs.inlineInput) {
      refs.inlineInput.value = "";
      refs.inlineInput.placeholder = "";
      refs.inlineInput.disabled = false;
      refs.inlineInput.removeAttribute("aria-label");
    }
    if (refs.inlineConfirm) {
      refs.inlineConfirm.disabled = false;
      refs.inlineConfirm.textContent = __("Save", "gpt3-ai-content-generator");
    }
    if (refs.inlineCancel) {
      refs.inlineCancel.disabled = false;
    }

    if (restoreFocus) {
      const triggerId = refs.inlinePanel.dataset.returnFocusId || "";
      const focusTarget = triggerId
        ? document.getElementById(triggerId)
        : refs.search;
      focusTarget?.focus();
    }

    refs.inlinePanel.dataset.returnFocusId = "";
  }

  function showInlineAction(refs, config) {
    if (!refs.inlinePanel || !refs.footer || !refs.inlineConfirm) {
      return;
    }

    refs.footer.hidden = true;
    refs.inlinePanel.hidden = false;
    refs.inlinePanel.dataset.action = config.action || "";
    refs.inlinePanel.dataset.templateId = config.templateId || "";
    refs.inlinePanel.dataset.returnFocusId = config.returnFocusId || "";

    if (refs.inlineTitle) {
      refs.inlineTitle.textContent = config.title || "";
    }
    if (refs.inlineField && refs.inlineInput) {
      const needsInput = Boolean(config.needsInput);
      refs.inlineField.hidden = !needsInput;
      refs.inlineInput.value = needsInput ? config.value || "" : "";
      refs.inlineInput.placeholder = needsInput
        ? config.placeholder || ""
        : "";
      refs.inlineInput.setAttribute(
        "aria-label",
        config.inputLabel || config.title || ""
      );
    }

    clearInlineError(refs);
    refs.inlineConfirm.textContent =
      config.confirmText || __("Save", "gpt3-ai-content-generator");

    window.requestAnimationFrame(() => {
      if (config.needsInput && refs.inlineInput) {
        refs.inlineInput.focus();
        refs.inlineInput.select();
      } else {
        refs.inlineConfirm.focus();
      }
    });
  }

  function createTemplateAction(template, action, icon, label) {
    const button = document.createElement("button");
    button.type = "button";
    button.className = `aipkit_cw_template_row_action aipkit_cw_template_row_action--${action}`;
    button.dataset.templateAction = action;
    button.dataset.templateId = String(template.id);
    button.id = `aipkit_cw_template_${action}_${template.id}`;
    button.setAttribute("aria-label", `${label}: ${template.template_name}`);
    button.title = label;

    const iconElement = document.createElement("span");
    iconElement.className = `dashicons ${icon}`;
    iconElement.setAttribute("aria-hidden", "true");
    button.appendChild(iconElement);
    return button;
  }

  function appendTemplateGroup(
    list,
    groupLabel,
    templates,
    query,
    select,
    currentUserId
  ) {
    const filteredTemplates = templates.filter((template) =>
      String(template.template_name || "")
        .toLocaleLowerCase()
        .includes(query)
    );

    if (!filteredTemplates.length) {
      return false;
    }

    const group = document.createElement("div");
    group.className = "aipkit_cw_template_group";

    const heading = document.createElement("div");
    heading.className = "aipkit_cw_template_group_label";
    heading.textContent = groupLabel;
    group.appendChild(heading);

    filteredTemplates.forEach((template) => {
      const presentation = getTemplatePresentation(template);
      const item = document.createElement("div");
      const isActive = String(select.value) === String(template.id);
      item.className = "aipkit_cw_template_item";
      item.classList.toggle("is-active", isActive);
      item.dataset.value = String(template.id);
      item.dataset.label = String(template.template_name);
      item.setAttribute("role", "option");
      item.setAttribute("aria-selected", isActive ? "true" : "false");
      item.tabIndex = 0;

      const check = document.createElement("span");
      check.className = "aipkit_cw_template_check dashicons dashicons-yes";
      check.setAttribute("aria-hidden", "true");

      const name = document.createElement("span");
      name.className = "aipkit_cw_template_item_name";
      name.textContent = presentation.label;

      const meta = document.createElement("span");
      meta.className = "aipkit_cw_template_item_meta";
      meta.textContent = presentation.meta;

      const actions = document.createElement("span");
      actions.className = "aipkit_cw_template_row_actions";
      if (canManageTemplate(template, currentUserId)) {
        actions.appendChild(
          createTemplateAction(
            template,
            "rename",
            "dashicons-edit",
            __("Rename template", "gpt3-ai-content-generator")
          )
        );
        actions.appendChild(
          createTemplateAction(
            template,
            "delete",
            "dashicons-trash",
            __("Delete template", "gpt3-ai-content-generator")
          )
        );
      }

      item.appendChild(check);
      item.appendChild(name);
      item.appendChild(meta);
      item.appendChild(actions);
      group.appendChild(item);
    });

    list.appendChild(group);
    return true;
  }

  function renderPickerList(select, list) {
    if (!select || !list) {
      return;
    }

    const refs = getTemplateControls();
    const templates =
      window.aipkit_cw_template_state?.currentTemplates || [];
    const currentUserId =
      window.aipkit_dashboard?.currentUserId?.toString() || "0";
    const query = String(refs.search?.value || "")
      .trim()
      .toLocaleLowerCase();
    const groups = buildTemplateGroups(templates, currentUserId);
    let hasAny = false;

    list.innerHTML = "";
    hasAny =
      appendTemplateGroup(
        list,
        __("Starter templates", "gpt3-ai-content-generator"),
        groups.starterTemplates,
        query,
        select,
        currentUserId
      ) || hasAny;
    hasAny =
      appendTemplateGroup(
        list,
        __("My templates", "gpt3-ai-content-generator"),
        groups.myTemplates,
        query,
        select,
        currentUserId
      ) || hasAny;

    groups.sharedGroups.forEach((sharedTemplates, displayName) => {
      const groupLabel = sprintf(
        __("Shared by %s", "gpt3-ai-content-generator"),
        displayName
      );
      hasAny =
        appendTemplateGroup(
          list,
          groupLabel,
          sharedTemplates,
          query,
          select,
          currentUserId
        ) || hasAny;
    });

    if (!hasAny) {
      const empty = document.createElement("div");
      empty.className = "aipkit_settings_select_picker_empty";
      empty.textContent = __("No templates found.", "gpt3-ai-content-generator");
      list.appendChild(empty);
    }

    updateTemplateListScrollState(list);
  }

  function closeActiveDropdown() {
    if (!activeDropdown) {
      return;
    }

    const button = activeDropdown.querySelector(
      ".aipkit_popover_multiselect_btn"
    );
    const panel = activeDropdown.querySelector(
      ".aipkit_popover_multiselect_panel"
    );
    hideInlineAction(getTemplateControls(), false);
    activeDropdown.classList.remove("is-open");
    if (panel) {
      panel.hidden = true;
    }
    button?.setAttribute("aria-expanded", "false");
    clearTemplateDropdownRuntimeRule();
    activeDropdown = null;
  }

  function openDropdown(dropdown, button, panel, select, list) {
    if (!dropdown || !button || !panel) {
      return;
    }

    if (activeDropdown && activeDropdown !== dropdown) {
      closeActiveDropdown();
    }

    const refs = getTemplateControls();
    activeDropdown = dropdown;
    dropdown.classList.add("is-open", "aipkit_settings_dropdown--measuring");
    panel.hidden = false;
    button.setAttribute("aria-expanded", "true");
    hideInlineAction(refs, false);
    if (refs.search) {
      refs.search.value = "";
    }
    renderPickerList(select, list);
    positionTemplateDropdownPanel(dropdown, panel, button);
    dropdown.classList.remove("aipkit_settings_dropdown--measuring");

    window.requestAnimationFrame(() => {
      if (activeDropdown === dropdown && !panel.hidden) {
        positionTemplateDropdownPanel(dropdown, panel, button);
        updateTemplateListScrollState(list);
        (refs.search || getEnabledTemplateItems(list)[0])?.focus();
      }
    });
  }

  function renderTemplateOptions(selectElement, preferredTemplateId = "") {
    if (!selectElement) {
      return;
    }

    const templates =
      window.aipkit_cw_template_state?.currentTemplates || [];
    const currentUserId =
      window.aipkit_dashboard?.currentUserId?.toString() || "0";
    const selectedId = String(
      preferredTemplateId ||
        selectElement.value ||
        window.aipkit_cw_template_state?.currentTemplateId ||
        ""
    );
    const refs = getTemplateControls();
    const groups = buildTemplateGroups(templates, currentUserId);

    selectElement.innerHTML = "";
    selectElement.appendChild(
      new Option(__("Select template", "gpt3-ai-content-generator"), "")
    );

    appendOptionGroup(
      selectElement,
      __("Starter templates", "gpt3-ai-content-generator"),
      groups.starterTemplates
    );
    appendOptionGroup(
      selectElement,
      __("My templates", "gpt3-ai-content-generator"),
      groups.myTemplates
    );
    groups.sharedGroups.forEach((sharedTemplates, displayName) => {
      appendOptionGroup(
        selectElement,
        sprintf(
          __("Shared by %s", "gpt3-ai-content-generator"),
          displayName
        ),
        sharedTemplates
      );
    });

    selectElement.disabled = !templates.length;
    selectElement.value =
      selectedId &&
      selectElement.querySelector(
        `option[value="${CSS.escape(selectedId)}"]`
      )
        ? selectedId
        : "";

    updatePickerLabel(selectElement, refs.label);
    renderPickerList(selectElement, refs.list);
  }

  function bindDropdownEvents(refs) {
    const {
      select,
      dropdown,
      button,
      panel,
      search,
      list,
      label,
      saveAsButton,
      resetButton,
      inlineInput,
      inlineCancel,
      inlineConfirm,
    } = refs;

    if (!select || !dropdown || !button || !panel || !list) {
      return;
    }

    if (button.dataset.listenerAttached !== "true") {
      button.addEventListener("click", (event) => {
        event.preventDefault();
        if (activeDropdown === dropdown) {
          closeActiveDropdown();
        } else {
          openDropdown(dropdown, button, panel, select, list);
        }
      });
      button.dataset.listenerAttached = "true";
    }

    if (search && search.dataset.listenerAttached !== "true") {
      search.addEventListener("input", () => renderPickerList(select, list));
      search.addEventListener("keydown", (event) => {
        if (event.key === "ArrowDown") {
          event.preventDefault();
          focusTemplateItemByIndex(list, 0);
        } else if (event.key === "Escape") {
          event.preventDefault();
          closeActiveDropdown();
          button.focus();
        }
      });
      search.dataset.listenerAttached = "true";
    }

    if (list.dataset.listenerAttached !== "true") {
      list.addEventListener("click", async (event) => {
        const actionButton = event.target.closest(
          ".aipkit_cw_template_row_action"
        );

        if (actionButton) {
          event.preventDefault();
          event.stopPropagation();
          const templateId = actionButton.dataset.templateId || "";
          const template =
            window.aipkit_cw_template_state?.currentTemplates?.find(
              (candidate) => String(candidate.id) === templateId
            ) || null;

          if (!template) {
            return;
          }

          if (actionButton.dataset.templateAction === "rename") {
            showInlineAction(refs, {
              action: "rename",
              templateId,
              title: __("Rename template", "gpt3-ai-content-generator"),
              confirmText: __("Save", "gpt3-ai-content-generator"),
              needsInput: true,
              value: template.template_name || "",
              placeholder: __("Template name", "gpt3-ai-content-generator"),
              inputLabel: __("Template name", "gpt3-ai-content-generator"),
              returnFocusId: actionButton.id,
            });
          } else if (
            actionButton.dataset.templateAction === "delete" &&
            typeof window.aipkit_submitDeleteContentWriterTemplate ===
              "function"
          ) {
            actionButton.disabled = true;
            await window.aipkit_submitDeleteContentWriterTemplate(templateId);
            actionButton.disabled = false;
          }
          return;
        }

        const item = event.target.closest(".aipkit_cw_template_item");
        if (!item || item.classList.contains("is-disabled")) {
          return;
        }
        selectTemplateValue(select, item.dataset.value || "");
        closeActiveDropdown();
      });

      list.addEventListener("keydown", (event) => {
        if (event.target.closest(".aipkit_cw_template_row_action")) {
          return;
        }

        const item = event.target.closest(".aipkit_cw_template_item");
        if (!item || item.classList.contains("is-disabled")) {
          return;
        }

        const items = getEnabledTemplateItems(list);
        const currentIndex = items.indexOf(item);
        if (currentIndex === -1) {
          return;
        }

        if (event.key === "ArrowDown") {
          event.preventDefault();
          focusTemplateItemByIndex(list, currentIndex + 1);
        } else if (event.key === "ArrowUp") {
          event.preventDefault();
          focusTemplateItemByIndex(list, currentIndex - 1);
        } else if (event.key === "Home") {
          event.preventDefault();
          focusTemplateItemByIndex(list, 0);
        } else if (event.key === "End") {
          event.preventDefault();
          focusTemplateItemByIndex(list, items.length - 1);
        } else if (event.key === "Enter" || event.key === " ") {
          event.preventDefault();
          selectTemplateValue(select, item.dataset.value || "");
          closeActiveDropdown();
        } else if (event.key === "Escape") {
          event.preventDefault();
          closeActiveDropdown();
          button.focus();
        }
      });
      list.dataset.listenerAttached = "true";
    }

    if (select.dataset.listenerAttached !== "true") {
      select.addEventListener("change", () => {
        hideInlineAction(refs, false);
        updatePickerLabel(select, label);
        renderPickerList(select, list);
      });
      select.dataset.listenerAttached = "true";
    }

    if (select.dataset.observerAttached !== "true") {
      let renderQueued = false;
      const observer = new MutationObserver(() => {
        if (renderQueued) {
          return;
        }
        renderQueued = true;
        requestAnimationFrame(() => {
          updatePickerLabel(select, label);
          renderPickerList(select, list);
          renderQueued = false;
        });
      });
      observer.observe(select, { childList: true, subtree: true });
      select.dataset.observerAttached = "true";
    }

    if (!document.body.dataset.aipkitCwTemplateDropdownEscapeBound) {
      document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && activeDropdown) {
          closeActiveDropdown();
        }
      });
      document.body.dataset.aipkitCwTemplateDropdownEscapeBound = "true";
    }

    if (!document.body.dataset.aipkitCwTemplateDropdownOutsideBound) {
      document.addEventListener("mousedown", (event) => {
        const activePanel = getTemplateControls().panel;
        if (
          activeDropdown &&
          !activeDropdown.contains(event.target) &&
          !activePanel?.contains(event.target)
        ) {
          closeActiveDropdown();
        }
      });
      document.body.dataset.aipkitCwTemplateDropdownOutsideBound = "true";
    }

    if (!window.__aipkitCwTemplateDropdownViewportBound) {
      let resizeRafId = 0;
      const handleViewportChange = () => {
        if (resizeRafId || !activeDropdown) {
          return;
        }
        resizeRafId = requestAnimationFrame(() => {
          resizeRafId = 0;
          const activeRefs = getTemplateControls();
          if (
            activeRefs.dropdown === activeDropdown &&
            activeRefs.panel &&
            activeRefs.button &&
            !activeRefs.panel.hidden
          ) {
            positionTemplateDropdownPanel(
              activeRefs.dropdown,
              activeRefs.panel,
              activeRefs.button
            );
            updateTemplateListScrollState(activeRefs.list);
          }
        });
      };
      window.addEventListener("resize", handleViewportChange);
      window.addEventListener("scroll", handleViewportChange, true);
      window.__aipkitCwTemplateDropdownViewportBound = true;
    }

    if (saveAsButton && saveAsButton.dataset.listenerAttached !== "true") {
      saveAsButton.addEventListener("click", () => {
        if (window.aipkit_cw_template_state?.isSavingOrDeleting) {
          return;
        }
        showInlineAction(refs, {
          action: "new",
          title: __("New template", "gpt3-ai-content-generator"),
          confirmText: __("Save", "gpt3-ai-content-generator"),
          needsInput: true,
          value: "",
          placeholder: __("Template name", "gpt3-ai-content-generator"),
          inputLabel: __("Template name", "gpt3-ai-content-generator"),
          returnFocusId: saveAsButton.id,
        });
      });
      saveAsButton.dataset.listenerAttached = "true";
    }

    if (resetButton && resetButton.dataset.listenerAttached !== "true") {
      resetButton.addEventListener("click", async () => {
        if (
          !window.aipkit_cw_template_state?.isSavingOrDeleting &&
          typeof window.aipkit_submitResetStarterTemplates === "function"
        ) {
          await window.aipkit_submitResetStarterTemplates();
        }
      });
      resetButton.dataset.listenerAttached = "true";
    }

    if (inlineCancel && inlineCancel.dataset.listenerAttached !== "true") {
      inlineCancel.addEventListener("click", () =>
        hideInlineAction(refs, true)
      );
      inlineCancel.dataset.listenerAttached = "true";
    }

    if (inlineInput && inlineInput.dataset.listenerAttached !== "true") {
      inlineInput.addEventListener("input", () => clearInlineError(refs));
      inlineInput.addEventListener("keydown", (event) => {
        if (event.key === "Enter") {
          event.preventDefault();
          event.stopPropagation();
          inlineConfirm?.click();
        } else if (event.key === "Escape") {
          event.preventDefault();
          event.stopPropagation();
          hideInlineAction(refs, true);
        }
      });
      inlineInput.dataset.listenerAttached = "true";
    }

    if (inlineConfirm && inlineConfirm.dataset.listenerAttached !== "true") {
      inlineConfirm.addEventListener("click", async () => {
        const action = refs.inlinePanel?.dataset.action || "";
        const templateId = refs.inlinePanel?.dataset.templateId || "";
        const name = String(inlineInput?.value || "").trim();

        if (!name) {
          setInlineError(
            refs,
            __("Please enter a template name.", "gpt3-ai-content-generator")
          );
          inlineInput?.focus();
          return;
        }

        inlineConfirm.disabled = true;
        if (inlineCancel) {
          inlineCancel.disabled = true;
        }
        if (inlineInput) {
          inlineInput.disabled = true;
        }

        let result = false;
        try {
          if (
            action === "new" &&
            typeof window.aipkit_submitSaveAsContentWriterTemplate ===
              "function"
          ) {
            result = await window.aipkit_submitSaveAsContentWriterTemplate(name);
          } else if (
            action === "rename" &&
            typeof window.aipkit_submitRenameContentWriterTemplate ===
              "function"
          ) {
            result =
              await window.aipkit_submitRenameContentWriterTemplate(
                templateId,
                name
              );
          }
        } finally {
          inlineConfirm.disabled = false;
          if (inlineCancel) {
            inlineCancel.disabled = false;
          }
          if (inlineInput) {
            inlineInput.disabled = false;
          }
        }

        if (result) {
          hideInlineAction(refs, false);
          refs.search?.focus();
        }
      });
      inlineConfirm.dataset.listenerAttached = "true";
    }
  }

  function aipkit_initTemplatePicker() {
    closeActiveDropdown();
    const refs = getTemplateControls();
    if (!refs.select) {
      return;
    }
    bindDropdownEvents(refs);
    updatePickerLabel(refs.select, refs.label);
    renderPickerList(refs.select, refs.list);
  }

  function aipkit_updateTemplatePickerSelection(templateId) {
    const refs = getTemplateControls();
    if (!refs.select) {
      return;
    }

    if (
      templateId &&
      refs.select.querySelector(
        `option[value="${CSS.escape(String(templateId))}"]`
      )
    ) {
      refs.select.value = String(templateId);
    } else if (!templateId) {
      refs.select.value = "";
    }

    updatePickerLabel(refs.select, refs.label);
    renderPickerList(refs.select, refs.list);
  }

  window.aipkit_initTemplatePicker = aipkit_initTemplatePicker;
  window.aipkit_renderTemplatePicker = renderTemplateOptions;
  window.aipkit_updateTemplatePickerSelection =
    aipkit_updateTemplatePickerSelection;
})();
