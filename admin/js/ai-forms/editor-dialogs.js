/** AI Forms prompt editor and model, knowledge-base and web-search draft dialogs. */
const dialogKeyHandlers = new WeakMap();
const dialogIds = new Set();
const dialogFocusTimers = new WeakMap();

export function cancelDialogFocus(modal) {
  window.clearTimeout(dialogFocusTimers.get(modal));
  dialogFocusTimers.delete(modal);
}

/** Only the current opening may apply deferred focus. */
export function deferDialogFocus(modal, focus) {
  cancelDialogFocus(modal);
  dialogFocusTimers.set(modal, window.setTimeout(() => {
    dialogFocusTimers.delete(modal);
    if (modal.isConnected && document.getElementById(modal.id) === modal &&
        modal.classList.contains("aipkit-active")) {
      focus();
    }
  }, 0));
}

/** Route keys to current dialogs without retaining removed panels. */
export function bindDialogKeydown(modal, handler) {
  dialogKeyHandlers.set(modal, handler);
  if (!dialogIds.size) {
    document.addEventListener("keydown", (event) => {
      if (event.key !== "Escape" && event.key !== "Tab") return;
      for (const id of dialogIds) {
        const current = document.getElementById(id);
        if (current?.classList.contains("aipkit-active")) {
          dialogKeyHandlers.get(current)?.(event);
        }
      }
    });
  }
  dialogIds.add(modal.id);
}

(function () {
  "use strict";

  const MODEL_FIELD_IDS = [
    "aipkit_ai_form_temperature",
    "aipkit_ai_form_max_tokens",
    "aipkit_ai_form_top_p",
    "aipkit_ai_form_frequency_penalty",
    "aipkit_ai_form_presence_penalty",
    "aipkit_ai_form_reasoning_effort",
  ];

  const isVisible = (element) =>
    !element.hidden &&
    element.getAttribute("aria-hidden") !== "true" &&
    element.offsetParent !== null;

  const getFocusableElements = (modal) =>
    Array.from(
      modal.querySelectorAll(
        'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
      )
    ).filter(isVisible);

  function registerDialog(key, apiName, panelName, triggerKey = key) {
    const prefix = `aipkit_ai_form_${key}_settings`;
    const triggerSelector = `#aipkit_ai_form_${triggerKey}_settings_trigger`;
    const isModel = key === "model";
    const isContext = key === "knowledge_base";
    let openingValues = null;
    let returnFocusTarget = null;

    const getModal = () => document.getElementById(`${prefix}_modal`);
    const getPanel = (modal) =>
      modal?.querySelector(`[data-aipkit-settings-panel="${panelName}"]`) || null;
    const getFields = (modal) =>
      isModel
        ? MODEL_FIELD_IDS.map((id) => modal?.querySelector(`#${id}`)).filter(Boolean)
        : Array.from(getPanel(modal)?.querySelectorAll("input, select, textarea") || [])
            .filter((field) => field.id);
    const isMultiple = (field) =>
      isContext && field.tagName === "SELECT" && field.multiple;
    const isChecked = (field) =>
      !isModel && (field.type === "checkbox" || field.type === "radio");

    const readValues = (modal) =>
      getFields(modal).reduce((values, field) => {
        values[field.id] = isMultiple(field)
          ? Array.from(field.options).filter((option) => option.selected).map((option) => option.value)
          : isChecked(field) ? Boolean(field.checked) : field.value;
        return values;
      }, {});

    const valuesChanged = (modal) => {
      if (!openingValues) return false;
      const currentValues = readValues(modal);
      return isModel
        ? MODEL_FIELD_IDS.some((id) => currentValues[id] !== openingValues[id])
        : JSON.stringify(currentValues) !== JSON.stringify(openingValues);
    };

    const syncWebSearchActions = (modal) => {
      const cancelButton = modal.querySelector(`#${prefix}_cancel_btn`);
      const saveButton = modal.querySelector(`#${prefix}_save_btn`);
      const description = modal.querySelector(`#${prefix}_modal_description`);
      const hasEditableFields = getFields(modal).some(isVisible);

      if (saveButton) {
        saveButton.hidden = !hasEditableFields;
        saveButton.style.display = hasEditableFields ? "" : "none";
      }
      if (cancelButton) {
        cancelButton.textContent = hasEditableFields
          ? cancelButton.dataset.cancelLabel || "Cancel"
          : cancelButton.dataset.closeLabel || "Close";
      }
      if (description) {
        description.textContent = hasEditableFields
          ? description.dataset.editableCopy || description.textContent
          : description.dataset.readonlyCopy || description.textContent;
      }
      modal.toggleAttribute("data-readonly", !hasEditableFields);
    };

    const refreshUi = (formsContainerElement, modal) => {
      const panel = getPanel(modal);
      if (isContext) {
        if (panel) {
          window.aipkitForms_syncEmbeddingSelectFromInputs?.(panel);
          window.aipkitForms_updateVectorStoreVisibility?.(panel);
          window.aipkit_initContentWriterVectorStoreMultiSelect?.(panel);
        }
      } else if (!isModel) {
        const editor = formsContainerElement?.querySelector("#aipkit_form_editor_container");
        const provider = editor?.querySelector("#aipkit_ai_form_ai_provider")?.value || "";
        if (editor) {
          window.aipkitForms_updateWebSearchVisibility?.(provider, editor);
        }
        if (panel) {
          window.aipkitForms_toggleOpenAIWebSearchLocationDetails?.(panel);
          window.aipkitForms_toggleClaudeWebSearchLocationDetails?.(panel);
        }
        syncWebSearchActions(modal);
      }
    };

    const restoreValues = (formsContainerElement, modal) => {
      if (!openingValues) return;
      getFields(modal).forEach((field) => {
        if (!Object.prototype.hasOwnProperty.call(openingValues, field.id)) return;
        const value = openingValues[field.id];
        if (isMultiple(field)) {
          const selectedValues = new Set(Array.isArray(value) ? value : []);
          Array.from(field.options).forEach((option) => {
            option.selected = selectedValues.has(option.value);
          });
        } else if (isChecked(field)) {
          field.checked = Boolean(value);
        } else {
          field.value = isModel ? value : value ?? "";
        }
      });
      if (!isModel) refreshUi(formsContainerElement, modal);
    };

    const closeModal = (formsContainerElement, options = {}) => {
      const modal = getModal();
      if (!modal || !modal.classList.contains("aipkit-active")) return;
      cancelDialogFocus(modal);

      const shouldCommit = options.commit === true;
      // Context checks its draft before closing nested dropdowns; model checks
      // after hiding. Keep these timings for the existing field/UI callbacks.
      let hasChanges = shouldCommit && !isModel && valuesChanged(modal);
      if (isContext) {
        modal.querySelectorAll('[data-aipkit-vector-stores-dropdown].is-open')
          .forEach((dropdown) => {
            dropdown.querySelector(".aipkit_popover_multiselect_btn")?.click();
          });
      }
      if (!shouldCommit) restoreValues(formsContainerElement, modal);

      modal.classList.remove("aipkit-active");
      modal.setAttribute("aria-hidden", "true");
      formsContainerElement?.querySelector(triggerSelector)?.setAttribute("aria-expanded", "false");
      if (shouldCommit && isModel) hasChanges = valuesChanged(modal);
      if (hasChanges) {
        window.aipkitForms_notifyEditorMutation?.(formsContainerElement, {
          queueSave: true,
          immediate: false,
        });
      }
      openingValues = null;
      if (options.returnFocus !== false && returnFocusTarget?.isConnected) {
        returnFocusTarget.focus();
      }
      returnFocusTarget = null;
    };

    const openModal = (formsContainerElement) => {
      const modal = getModal();
      const trigger = formsContainerElement?.querySelector(triggerSelector);
      if (!modal || !trigger || modal.classList.contains("aipkit-active")) return;

      if (isContext) refreshUi(formsContainerElement, modal);
      if (isModel || isContext) openingValues = readValues(modal);
      returnFocusTarget = document.activeElement;
      modal.classList.add("aipkit-active");
      modal.setAttribute("aria-hidden", "false");
      trigger.setAttribute("aria-expanded", "true");
      // Search visibility depends on the displayed provider controls.
      if (!isModel && !isContext) {
        refreshUi(formsContainerElement, modal);
        openingValues = readValues(modal);
      }

      deferDialogFocus(modal, () => {
        if (isModel) {
          const firstField = getFields(modal).find(
            (field) => !field.hidden && field.offsetParent !== null
          );
          firstField?.focus();
          if (firstField?.select) firstField.select();
        } else {
          const focusable = getFocusableElements(modal);
          const firstField = focusable.find((element) => element.matches("select, input, textarea"));
          const target = isContext
            ? firstField
            : firstField || modal.querySelector(`#${prefix}_cancel_btn`) || focusable[0];
          target?.focus();
        }
      });
    };

    const initialize = (formsContainerElement) => {
      const modal = getModal();
      const trigger = formsContainerElement?.querySelector(triggerSelector);
      if (!formsContainerElement || !modal || !trigger || modal.dataset.bound === "1") return;

      trigger.addEventListener("click", (event) => {
        event.preventDefault();
        openModal(formsContainerElement);
      });
      modal.querySelectorAll(`.${prefix}_cancel`).forEach((button) => {
        button.addEventListener("click", () => closeModal(formsContainerElement));
      });
      modal.querySelector(`#${prefix}_save_btn`)?.addEventListener("click", () => {
        const invalidField = getFields(modal).find(
          (field) => (isModel || field.offsetParent !== null) &&
            typeof field.checkValidity === "function" && !field.checkValidity()
        );
        if (invalidField) {
          invalidField.reportValidity();
          invalidField.focus();
          return;
        }
        closeModal(formsContainerElement, { commit: true });
      });
      modal.addEventListener("click", (event) => {
        if (event.target === modal) closeModal(formsContainerElement);
      });
      bindDialogKeydown(modal, (event) => {
        if (event.key === "Escape") {
          event.preventDefault();
          closeModal(formsContainerElement);
          return;
        }
        if (event.key !== "Tab") return;
        const focusable = getFocusableElements(modal);
        if (!focusable.length) {
          event.preventDefault();
          return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      });
      modal.dataset.bound = "1";
    };

    window[`aipkitForms_init${apiName}SettingsModal`] = initialize;
    window[`aipkitForms_close${apiName}SettingsModal`] = closeModal;
  }

  function aipkitForms_initPromptModal(formsContainerElement) {
    if (!formsContainerElement) {
      return;
    }

    const editorContainer = formsContainerElement.querySelector(
      "#aipkit_form_editor_container"
    );
    if (!editorContainer) {
      return;
    }

    const promptTextarea = editorContainer.querySelector(
      "#aipkit_ai_form_prompt_template"
    );
    const expandBtn = editorContainer.querySelector(
      ".aipkit_ai_form_prompt_expand"
    );
    const modal = document.getElementById("aipkit_ai_form_prompt_modal");
    if (!promptTextarea || !expandBtn || !modal) {
      return;
    }

    const modalTextarea = modal.querySelector(
      ".aipkit_ai_form_prompt_modal_textarea"
    );
    const modalCount = modal.querySelector(".aipkit_ai_form_prompt_count");
    const modalCloseBtns = modal.querySelectorAll(
      ".aipkit_ai_form_prompt_modal_close"
    );

    if (modal.dataset.bound === "1") {
      return;
    }

    const updateCount = (value) => {
      if (!modalCount) {
        return;
      }
      modalCount.textContent = `${value.length.toLocaleString()} characters`;
    };

    const openModal = () => {
      if (!modalTextarea) {
        return;
      }
      modalTextarea.value = promptTextarea.value || "";
      updateCount(modalTextarea.value);
      modal.classList.add("aipkit-active");
      modal.setAttribute("aria-hidden", "false");
      deferDialogFocus(modal, () => modalTextarea.focus());
    };

    const closeModal = () => {
      cancelDialogFocus(modal);
      modal.classList.remove("aipkit-active");
      modal.setAttribute("aria-hidden", "true");

      if (!modalTextarea) {
        return;
      }

      const modalValue = modalTextarea.value;
      if (modalValue !== promptTextarea.value) {
        promptTextarea.value = modalValue;
        promptTextarea.dispatchEvent(new Event("input", { bubbles: true }));
      }
    };

    expandBtn.addEventListener("click", (event) => {
      event.preventDefault();
      openModal();
    });

    modalCloseBtns.forEach((modalCloseBtn) => {
      modalCloseBtn.addEventListener("click", closeModal);
    });

    bindDialogKeydown(modal, (event) => {
      if (event.key === "Escape") {
        closeModal();
      }
    });

    if (modalTextarea && modalTextarea.dataset.bound !== "1") {
      modalTextarea.addEventListener("input", () => {
        updateCount(modalTextarea.value);
      });
      modalTextarea.dataset.bound = "1";
    }

    modal.dataset.bound = "1";
  }

  window.aipkitForms_initPromptModal = aipkitForms_initPromptModal;

  registerDialog("model", "Model");
  registerDialog("knowledge_base", "KnowledgeBase", "context", "context");
  registerDialog("web_search", "WebSearch", "tools");
})();
