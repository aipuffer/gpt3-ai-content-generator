/**
 * AIPKit Content Assistant - inline template picker.
 */
import {
  resetPopoverPanelPosition,
} from "../../utils/ui/popover-position.js";

(function () {
  "use strict";

  const getScope = (scope) =>
    scope || document.querySelector(".aipkit_enhancer_bulk_modal");

  function getPickerElements(scope) {
    const root = getScope(scope);
    return {
      root,
      select: root?.querySelector("#aipkit_enhancer_template_select") || null,
      button:
        root?.querySelector("#aipkit_enhancer_template_picker_btn") || null,
      label:
        root?.querySelector("#aipkit_enhancer_template_picker_label") || null,
      list:
        root?.querySelector("#aipkit_enhancer_template_picker_list") || null,
      popover:
        root?.querySelector("#aipkit_enhancer_template_picker_popover") || null,
      panel:
        root?.querySelector("#aipkit_enhancer_template_picker_popover_panel") ||
        null,
    };
  }

  const positionTemplateDropdownPanel = (popover, panel) => {
    if (!popover || !panel) {
      return;
    }
    panel.style.position = "absolute";
    panel.style.left = "0";
    panel.style.top = "calc(100% + 6px)";
    panel.style.bottom = "auto";
    panel.style.width = "100%";
    panel.style.minWidth = "100%";
    panel.style.maxWidth = "100%";
  };

  function closeTemplatePicker(scope) {
    const { popover, panel, button } = getPickerElements(scope);
    if (!popover || !panel) {
      return;
    }
    popover.classList.remove("is-open");
    panel.hidden = true;
    resetPopoverPanelPosition(panel);
    button?.setAttribute("aria-expanded", "false");
  }

  function openTemplatePicker(scope) {
    const { popover, panel, button } = getPickerElements(scope);
    if (!popover || !panel) {
      return;
    }
    popover.classList.add("is-open");
    panel.hidden = false;
    button?.setAttribute("aria-expanded", "true");
    window.requestAnimationFrame(() => {
      positionTemplateDropdownPanel(popover, panel);
    });
  }

  const isTemplatePickerOpen = (scope) =>
    Boolean(
      getPickerElements(scope).popover?.classList.contains("is-open")
    );

  function buildTemplateGroups(templates, currentUserId) {
    const own = [];
    const shared = {};

    templates.forEach((template) => {
      if (
        String(template.is_starter) === "1" ||
        template.is_starter === true
      ) {
        return;
      }
      if (
        currentUserId &&
        String(template.user_id) === String(currentUserId)
      ) {
        own.push(template);
        return;
      }
      const owner = template.display_name || `User ${template.user_id}`;
      shared[owner] ||= [];
      shared[owner].push(template);
    });

    const byName = (a, b) =>
      String(a.template_name || "").localeCompare(
        String(b.template_name || "")
      );
    own.sort((a, b) => {
      if (String(a.is_default) === "1") return -1;
      if (String(b.is_default) === "1") return 1;
      return byName(a, b);
    });
    Object.values(shared).forEach((items) => items.sort(byName));

    return { own, shared };
  }

  function updateTemplatePickerLabel(select, scope) {
    const { label } = getPickerElements(scope);
    if (!label || !select) {
      return;
    }
    const selected = select.options[select.selectedIndex];
    label.textContent =
      String(selected?.value || "") && selected?.text
        ? selected.text
        : "Select a template";
  }

  function setActiveTemplateItem(list, templateId) {
    if (!list) {
      return;
    }
    const activeId = String(templateId || "");
    list.querySelectorAll(".aipkit_cw_template_list_item").forEach((item) => {
      const isActive = String(item.dataset.templateId || "") === activeId;
      item.classList.toggle("is-active", isActive);
      item.setAttribute("aria-selected", isActive ? "true" : "false");
    });
  }

  const createIconButton = (action, icon, label) => {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "aipkit_enhancer_template_row_action";
    button.dataset.templateAction = action;
    button.title = label;
    button.setAttribute("aria-label", label);
    const glyph = document.createElement("span");
    glyph.className = `dashicons ${icon}`;
    glyph.setAttribute("aria-hidden", "true");
    button.appendChild(glyph);
    return button;
  };

  function createTemplateItem(template, isOwned) {
    const item = document.createElement("div");
    item.className = "aipkit_cw_template_list_item";
    item.dataset.templateId = String(template.id);
    item.dataset.templateName = String(template.template_name || "");
    item.dataset.templateOwned = isOwned ? "true" : "false";
    item.setAttribute("role", "option");
    item.setAttribute("aria-selected", "false");

    const row = document.createElement("div");
    row.className = "aipkit_enhancer_template_row";

    const selectButton = document.createElement("button");
    selectButton.type = "button";
    selectButton.className = "aipkit_cw_template_select_btn";
    selectButton.dataset.templateSelect = "true";

    const icon = document.createElement("span");
    icon.className =
      "aipkit_cw_template_list_icon dashicons dashicons-media-text";
    icon.setAttribute("aria-hidden", "true");
    const name = document.createElement("span");
    name.className = "aipkit_cw_template_list_name";
    name.textContent = String(template.template_name || "");
    const check = document.createElement("span");
    check.className =
      "aipkit_cw_template_list_check dashicons dashicons-yes";
    check.setAttribute("aria-hidden", "true");
    selectButton.append(icon, name, check);
    row.appendChild(selectButton);

    if (isOwned) {
      const actions = document.createElement("span");
      actions.className = "aipkit_enhancer_template_row_actions";
      actions.append(
        createIconButton("rename", "dashicons-edit", "Rename template"),
        createIconButton("delete", "dashicons-trash", "Delete template")
      );
      row.appendChild(actions);
    }
    item.appendChild(row);

    if (isOwned) {
      const renameForm = document.createElement("div");
      renameForm.className = "aipkit_cw_template_row_form";
      renameForm.dataset.templateRenameForm = "true";
      renameForm.hidden = true;
      const renameInput = document.createElement("input");
      const renameInputId = `aipkit_enhancer_template_rename_${template.id}`;
      renameInput.type = "text";
      renameInput.id = renameInputId;
      renameInput.className = "aipkit_form-input";
      renameInput.value = String(template.template_name || "");
      renameInput.dataset.templateRenameInput = "true";
      renameInput.setAttribute("aria-label", "Template name");
      const renameLabel = document.createElement("label");
      renameLabel.className = "aipkit_enhancer_template_form_label";
      renameLabel.htmlFor = renameInputId;
      renameLabel.textContent = "Template name";
      const renameSave = document.createElement("button");
      renameSave.type = "button";
      renameSave.className =
        "aipkit_enhancer_template_form_button aipkit_enhancer_template_form_button--primary";
      renameSave.dataset.templateAction = "rename-save";
      renameSave.textContent = "Save";
      const renameCancel = document.createElement("button");
      renameCancel.type = "button";
      renameCancel.className =
        "aipkit_enhancer_template_form_button aipkit_enhancer_template_form_button--secondary";
      renameCancel.dataset.templateAction = "rename-cancel";
      renameCancel.textContent = "Cancel";
      const renameActions = document.createElement("div");
      renameActions.className = "aipkit_enhancer_template_form_actions";
      renameActions.append(renameCancel, renameSave);
      renameForm.append(renameLabel, renameInput, renameActions);
      item.appendChild(renameForm);
    }

    return item;
  }

  function renderTemplatePicker(scope) {
    const { select, list } = getPickerElements(scope);
    if (!select || !list) {
      return;
    }
    const templates = window.aipkit_bulk_template_state?.templates || [];
    const currentUserId = window.aipkit_dashboard?.currentUserId?.toString();
    list.innerHTML = "";

    if (!templates.length) {
      const empty = document.createElement("div");
      empty.className = "aipkit_cw_template_picker_empty";
      empty.textContent =
        select.disabled &&
        select.options?.[0]?.text?.toLowerCase().includes("loading")
          ? "Loading templates…"
          : "No templates yet.";
      list.appendChild(empty);
      updateTemplatePickerLabel(select, scope);
      return;
    }

    const groups = buildTemplateGroups(templates, currentUserId);
    const appendGroup = (label, items, isOwned) => {
      if (!items.length) {
        return;
      }
      const group = document.createElement("div");
      group.className = "aipkit_cw_template_group";
      const heading = document.createElement("div");
      heading.className = "aipkit_cw_template_group_label";
      heading.textContent = label;
      group.appendChild(heading);
      items.forEach((template) => {
        group.appendChild(createTemplateItem(template, isOwned));
      });
      list.appendChild(group);
    };

    appendGroup("MY TEMPLATES", groups.own, true);
    Object.entries(groups.shared).forEach(([owner, items]) => {
      appendGroup(String(owner).toUpperCase(), items, false);
    });
    if (!list.children.length) {
      const empty = document.createElement("div");
      empty.className = "aipkit_cw_template_picker_empty";
      empty.textContent = "No templates yet.";
      list.appendChild(empty);
    }

    updateTemplatePickerLabel(select, scope);
    setActiveTemplateItem(list, select.value);
  }

  function initTemplatePicker(scope) {
    const { select, list, button, popover } = getPickerElements(scope);
    if (!select || !list) {
      return;
    }

    if (button && button.dataset.templatePickerListenerAttached !== "true") {
      button.addEventListener("click", (event) => {
        event.preventDefault();
        if (isTemplatePickerOpen(scope)) {
          closeTemplatePicker(scope);
        } else {
          openTemplatePicker(scope);
        }
      });
      button.dataset.templatePickerListenerAttached = "true";
    }

    if (list.dataset.selectionListenerAttached !== "true") {
      list.addEventListener("click", (event) => {
        const selectButton = event.target.closest("[data-template-select]");
        const item = selectButton?.closest(".aipkit_cw_template_list_item");
        if (!item) {
          return;
        }
        select.value = item.dataset.templateId || "";
        select.dispatchEvent(new Event("change", { bubbles: true }));
        setActiveTemplateItem(list, select.value);
        closeTemplatePicker(scope);
      });
      list.dataset.selectionListenerAttached = "true";
    }

    if (select.dataset.selectionListenerAttached !== "true") {
      select.addEventListener("change", () => {
        updateTemplatePickerLabel(select, scope);
        setActiveTemplateItem(list, select.value);
      });
      select.dataset.selectionListenerAttached = "true";
    }

    if (popover && popover.dataset.outsideListenerAttached !== "true") {
      document.addEventListener("mousedown", (event) => {
        if (
          isTemplatePickerOpen(scope) &&
          !getPickerElements(scope).popover?.contains(event.target)
        ) {
          closeTemplatePicker(scope);
        }
      });
      document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && isTemplatePickerOpen(scope)) {
          closeTemplatePicker(scope);
        }
      });
      const reposition = () => {
        const { popover: currentPopover, panel } = getPickerElements(scope);
        if (isTemplatePickerOpen(scope) && currentPopover && panel) {
          positionTemplateDropdownPanel(currentPopover, panel);
        }
      };
      window.addEventListener("resize", reposition);
      window.addEventListener("scroll", reposition, true);
      popover.dataset.outsideListenerAttached = "true";
    }

    renderTemplatePicker(scope);
  }

  function updateTemplatePickerSelection(templateId, scope) {
    const { select, list } = getPickerElements(scope);
    if (!select) {
      return;
    }
    if (templateId !== undefined) {
      select.value = templateId;
    }
    updateTemplatePickerLabel(select, scope);
    setActiveTemplateItem(list, select.value);
  }

  window.aipkit_initBulkTemplatePicker = initTemplatePicker;
  window.aipkit_renderBulkTemplatePicker = renderTemplatePicker;
  window.aipkit_updateBulkTemplatePickerSelection =
    updateTemplatePickerSelection;
  window.aipkit_openBulkTemplatePicker = openTemplatePicker;
})();
