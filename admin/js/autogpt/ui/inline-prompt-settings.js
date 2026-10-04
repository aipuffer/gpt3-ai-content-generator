/**
 * AIPKit AutoGPT - Compact inline prompt settings.
 */
(function () {
  "use strict";

  const ROOT_SELECTOR = "[data-aipkit-inline-prompts]";

  const normalizePrompt = (value) =>
    String(value || "")
      .replace(/\r\n/g, "\n")
      .trim();

  const getTextarea = (item) =>
    item?.querySelector("[data-aipkit-inline-prompt-textarea]") || null;

  const isCustomPrompt = (textarea) => {
    if (!textarea) return false;
    const value = normalizePrompt(textarea.value);
    return Boolean(value) && value !== normalizePrompt(textarea.defaultValue);
  };

  const isControlEnabled = (form, name) => {
    if (!name) return true;
    const control = form?.elements[name];
    if (!control) return false;
    if (typeof control.checked === "boolean") return control.checked;
    return Array.from(control).some((entry) => entry.checked);
  };

  const emitPromptChange = (textarea) => {
    if (!textarea) return;
    textarea.dispatchEvent(new Event("input", { bubbles: true }));
    textarea.dispatchEvent(new Event("change", { bubbles: true }));
  };

  const getItems = (root) =>
    Array.from(root?.querySelectorAll("[data-aipkit-inline-prompt-item]") || []);

  const isRowLayout = (root) =>
    root?.dataset.aipkitInlinePromptLayout === "rows";

  const getRowFields = (root) =>
    Array.from(root?.querySelectorAll("[data-aipkit-content-field]") || []);

  const getItemByKey = (root, key) =>
    getItems(root).find((item) => item.dataset.aipkitPromptKey === key) || null;

  let activeRowModal = null;
  let activePanelModal = null;

  const getRowItem = (field) =>
    field?.querySelector("[data-aipkit-inline-prompt-item]") ||
    (activeRowModal?.field === field ? activeRowModal.item : null);

  const syncRowField = (root, field) => {
    const form = root?.closest("form");
    const controlName = field?.dataset.aipkitVisibilityControl || "";
    const available = isControlEnabled(form, controlName);
    const trigger = field?.querySelector("[data-aipkit-row-prompt-toggle]");
    const status = field?.querySelector("[data-aipkit-row-prompt-status]");
    const item = getRowItem(field);
    const isCustom = isCustomPrompt(getTextarea(item));

    if (trigger) {
      trigger.hidden = !available;
      trigger.setAttribute("aria-hidden", available ? "false" : "true");
    }
    if (!available && activeRowModal?.field === field) {
      closeRowModal(false);
    }

    if (status) {
      status.textContent = isCustom
        ? status.dataset.customLabel || "Custom"
        : status.dataset.builtInLabel || "Built-in";
      status.dataset.currentState = isCustom ? "custom" : "built-in";
    }

    const reset = item?.querySelector("[data-aipkit-inline-prompt-reset]");
    if (reset) reset.hidden = !isCustom;
  };

  const syncRowLayout = (root) => {
    const fields = getRowFields(root);
    fields.forEach((field) => syncRowField(root, field));
    root.classList.toggle(
      "has-custom-prompts",
      fields.some((field) => isCustomPrompt(getTextarea(getRowItem(field))))
    );
  };

  const getFocusableElements = (modal) =>
    Array.from(
      modal?.querySelectorAll(
        'button:not([disabled]):not([hidden]), select:not([disabled]), textarea:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])'
      ) || []
    ).filter((element) => !element.hidden && element.offsetParent !== null);

  const closeRowModal = (shouldSave) => {
    const state = activeRowModal;
    if (!state) return;

    const textarea = getTextarea(state.item);
    if (!shouldSave && textarea) {
      textarea.value = state.initialValue;
      state.selectValues.forEach(({ select, value }) => {
        select.value = value;
      });
    }

    state.field.appendChild(state.panel);
    state.panel.hidden = true;
    state.item.hidden = true;
    state.field.classList.remove("is-prompt-modal-open");
    state.modal.hidden = true;
    state.modal.setAttribute("aria-hidden", "true");
    document.body.classList.remove("aipkit-instructions-modal-open");
    activeRowModal = null;

    emitPromptChange(textarea);
    syncRowLayout(state.root);
    state.trigger.focus({ preventScroll: true });
  };

  const openRowModal = (root, field) => {
    if (!root || !field) return;
    if (activeRowModal) closeRowModal(false);

    const trigger = field.querySelector("[data-aipkit-row-prompt-toggle]");
    const panel = field.querySelector("[data-aipkit-row-prompt-panel]");
    const item = panel?.querySelector("[data-aipkit-inline-prompt-item]");
    const modal = root.querySelector("[data-aipkit-instructions-modal]");
    const modalBody = modal?.querySelector("[data-aipkit-instructions-modal-body]");
    const modalTitle = modal?.querySelector("[data-aipkit-instructions-modal-title]");
    const textarea = getTextarea(item);
    if (!trigger || !panel || !item || !modal || !modalBody || !textarea) return;

    const fieldLabel = field.dataset.aipkitInstructionLabel ||
      field.querySelector(".aipkit_autogpt_content_field_label")
        ?.textContent?.trim() || "";
    if (modalTitle) {
      const titleTemplate = modal.dataset.titleTemplate || "%s instructions";
      modalTitle.textContent = fieldLabel
        ? titleTemplate.replace("%s", fieldLabel)
        : "Instructions";
    }

    activeRowModal = {
      root,
      field,
      trigger,
      panel,
      item,
      modal,
      initialValue: textarea.value,
      selectValues: Array.from(item.querySelectorAll("select")).map((select) => ({
        select,
        value: select.value,
      })),
    };

    field.classList.add("is-prompt-modal-open");
    modalBody.appendChild(panel);
    panel.hidden = false;
    item.hidden = false;
    modal.hidden = false;
    modal.setAttribute("aria-hidden", "false");
    document.body.classList.add("aipkit-instructions-modal-open");

    window.requestAnimationFrame(() => textarea.focus({ preventScroll: true }));
  };

  const getAvailableOptions = (root) => {
    const form = root?.closest("form");
    const selector = root?.querySelector("[data-aipkit-prompt-field-selector]");
    if (!selector) return [];

    return Array.from(selector.options).filter((option) => {
      const controlName = option.dataset.aipkitVisibilityControl || "";
      const available = isControlEnabled(form, controlName);
      option.hidden = !available;
      option.disabled = !available;
      return available;
    });
  };

  const syncFieldSelector = (root) => {
    const selector = root?.querySelector("[data-aipkit-prompt-field-selector]");
    if (!selector) return [];
    const options = getAvailableOptions(root);

    if (!options.some((option) => option.value === selector.value)) {
      selector.value = options[0]?.value || "";
    }

    options.forEach((option) => {
      const item = getItemByKey(root, option.value);
      const baseLabel = option.dataset.baseLabel || option.textContent || "";
      option.textContent = isCustomPrompt(getTextarea(item))
        ? `${baseLabel} · Custom`
        : baseLabel;
    });
    selector._aipkitSegmentedSync?.();

    return options;
  };

  const syncSelectedEditor = (root) => {
    const selector = root?.querySelector("[data-aipkit-prompt-field-selector]");
    const selectedKey = selector?.value || "";
    const form = root?.closest("form");

    getItems(root).forEach((item) => {
      const controlName = item.dataset.aipkitVisibilityControl || "";
      const available = isControlEnabled(form, controlName);
      item.hidden = !available || item.dataset.aipkitPromptKey !== selectedKey;
    });
  };

  const syncSummary = (root) => {
    const form = root?.closest("form");
    const summary = root?.querySelector("[data-aipkit-instruction-summary]");
    if (!summary) return;

    const customCount = getItems(root).filter((item) => {
      const controlName = item.dataset.aipkitVisibilityControl || "";
      return isControlEnabled(form, controlName) && isCustomPrompt(getTextarea(item));
    }).length;

    getItems(root).forEach((item) => {
      const reset = item.querySelector("[data-aipkit-inline-prompt-reset]");
      if (reset) reset.hidden = !isCustomPrompt(getTextarea(item));
    });

    summary.textContent = customCount === 0
      ? summary.dataset.builtInLabel || "Built-in"
      : customCount === 1
        ? summary.dataset.customOneLabel || "1 customized"
        : (summary.dataset.customManyLabel || "%d customized").replace("%d", customCount);
    root.classList.toggle("has-custom-prompts", customCount > 0);
  };

  const closePanelModal = (shouldSave) => {
    const state = activePanelModal;
    if (!state) return;

    if (!shouldSave) {
      state.textareaValues.forEach(({ textarea, value }) => {
        textarea.value = value;
      });
      state.selectValues.forEach(({ select, value }) => {
        select.value = value;
      });
    }

    if (state.nextSibling?.parentNode === state.originalParent) {
      state.originalParent.insertBefore(state.panel, state.nextSibling);
    } else {
      state.originalParent.appendChild(state.panel);
    }
    state.panel.hidden = true;
    state.modal.hidden = true;
    state.modal.setAttribute("aria-hidden", "true");
    document.body.classList.remove("aipkit-instructions-modal-open");
    activePanelModal = null;

    state.textareaValues.forEach(({ textarea }) => emitPromptChange(textarea));
    syncRoot(state.root);
    state.toggle.focus({ preventScroll: true });
  };

  const openPanelModal = (root) => {
    if (!root) return;
    if (activePanelModal) closePanelModal(false);

    const toggle = root?.querySelector("[data-aipkit-instruction-panel-toggle]");
    const panel = root?.querySelector("[data-aipkit-instruction-panel]");
    const modal = root?.querySelector("[data-aipkit-panel-instructions-modal]");
    const modalBody = modal?.querySelector(
      "[data-aipkit-panel-instructions-modal-body]"
    );
    if (!toggle || !panel || !modal || !modalBody) return;

    syncSelectedEditor(root);
    activePanelModal = {
      root,
      toggle,
      panel,
      modal,
      originalParent: panel.parentNode,
      nextSibling: panel.nextSibling,
      textareaValues: getItems(root).map((item) => {
        const textarea = getTextarea(item);
        return { textarea, value: textarea?.value || "" };
      }).filter(({ textarea }) => Boolean(textarea)),
      selectValues: Array.from(panel.querySelectorAll("select")).map((select) => ({
        select,
        value: select.value,
      })),
    };

    modalBody.appendChild(panel);
    panel.hidden = false;
    modal.hidden = false;
    modal.setAttribute("aria-hidden", "false");
    document.body.classList.add("aipkit-instructions-modal-open");

    window.requestAnimationFrame(() => {
      const selector = root.querySelector("[data-aipkit-prompt-field-selector]");
      selector?.focus({ preventScroll: true });
    });
  };

  const syncRoot = (root) => {
    if (!root) return;
    if (isRowLayout(root)) {
      syncRowLayout(root);
      return;
    }

    const options = syncFieldSelector(root);
    syncSelectedEditor(root);
    syncSummary(root);

    if (root.dataset.aipkitInlinePrompts === "images") {
      const imageSettings = root.closest("[data-aipkit-image-instructions]");
      if (imageSettings) imageSettings.hidden = options.length === 0;
      if (!options.length && activePanelModal?.root === root) {
        closePanelModal(false);
      }
    }
  };

  const resetSelectedPrompt = (root) => {
    const selector = root?.querySelector("[data-aipkit-prompt-field-selector]");
    const textarea = getTextarea(getItemByKey(root, selector?.value || ""));
    if (!textarea || textarea.value === textarea.defaultValue) return;
    textarea.value = textarea.defaultValue;
    emitPromptChange(textarea);
  };

  const resetPromptItem = (item) => {
    const textarea = getTextarea(item);
    if (!textarea || textarea.value === textarea.defaultValue) return;
    textarea.value = textarea.defaultValue;
    emitPromptChange(textarea);
  };

  const initRoot = (root) => {
    if (!root || root.dataset.aipkitInlinePromptsAttached === "true") return;

    if (isRowLayout(root)) {
      root.addEventListener("click", (event) => {
        if (event.target.closest("[data-aipkit-instructions-modal-save]")) {
          closeRowModal(true);
          return;
        }

        if (
          event.target.closest("[data-aipkit-instructions-modal-cancel]") ||
          event.target.closest("[data-aipkit-instructions-modal-close]")
        ) {
          closeRowModal(false);
          return;
        }

        const reset = event.target.closest("[data-aipkit-inline-prompt-reset]");
        if (reset) {
          resetPromptItem(reset.closest("[data-aipkit-inline-prompt-item]"));
          return;
        }

        const trigger = event.target.closest("[data-aipkit-row-prompt-toggle]");
        if (!trigger) return;
        const field = trigger.closest("[data-aipkit-content-field]");
        openRowModal(root, field);
      });

      root.addEventListener("input", (event) => {
        if (!event.target.matches("[data-aipkit-inline-prompt-textarea]")) return;
        const reset = event.target
          .closest("[data-aipkit-inline-prompt-item]")
          ?.querySelector("[data-aipkit-inline-prompt-reset]");
        if (reset) reset.hidden = !isCustomPrompt(event.target);
      });

      root.querySelector("[data-aipkit-instructions-modal]")?.addEventListener(
        "keydown",
        (event) => {
          if (!activeRowModal || activeRowModal.root !== root) return;
          if (event.key !== "Tab") return;

          const focusable = getFocusableElements(activeRowModal.modal);
          if (!focusable.length) return;
          const first = focusable[0];
          const last = focusable[focusable.length - 1];
          if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
          } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
          }
        }
      );

      root.addEventListener("change", (event) => {
        if (!event.target.matches("[data-aipkit-inline-prompt-textarea]")) return;
        const reset = event.target
          .closest("[data-aipkit-inline-prompt-item]")
          ?.querySelector("[data-aipkit-inline-prompt-reset]");
        if (reset) reset.hidden = !isCustomPrompt(event.target);
      });

      root.dataset.aipkitInlinePromptsAttached = "true";
      syncRowLayout(root);
      return;
    }

    root.querySelector("[data-aipkit-instruction-panel-toggle]")?.addEventListener(
      "click",
      () => openPanelModal(root)
    );

    root.querySelector("[data-aipkit-prompt-field-selector]")?.addEventListener("change", () => {
      syncSelectedEditor(root);
    });

    root.addEventListener("input", (event) => {
      if (!event.target.matches("[data-aipkit-inline-prompt-textarea]")) return;
      syncFieldSelector(root);
      syncSummary(root);
    });

    root.addEventListener("click", (event) => {
      if (event.target.closest("[data-aipkit-panel-instructions-modal-save]")) {
        closePanelModal(true);
        return;
      }
      if (
        event.target.closest("[data-aipkit-panel-instructions-modal-cancel]") ||
        event.target.closest("[data-aipkit-panel-instructions-modal-close]")
      ) {
        closePanelModal(false);
        return;
      }
      if (event.target.closest("[data-aipkit-inline-prompt-reset]")) {
        resetSelectedPrompt(root);
        syncRoot(root);
      }
    });

    root.querySelector("[data-aipkit-panel-instructions-modal]")?.addEventListener(
      "keydown",
      (event) => {
        if (!activePanelModal || activePanelModal.root !== root) return;
        if (event.key !== "Tab") return;

        const focusable = getFocusableElements(activePanelModal.modal);
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      }
    );

    root.dataset.aipkitInlinePromptsAttached = "true";
    syncRoot(root);
  };

  const init = (scopeElement) => {
    const scope = scopeElement || document.getElementById("aipkit_autogpt_container") || document;
    scope.querySelectorAll(ROOT_SELECTOR).forEach(initRoot);

    const form = scope.querySelector?.("#aipkit_automated_task_form") ||
      document.getElementById("aipkit_automated_task_form");
    if (form && form.dataset.aipkitInlinePromptVisibilityAttached !== "true") {
      form.addEventListener("change", (event) => {
        if (!event.target?.name) return;
        const selector = `[data-aipkit-visibility-control="${CSS.escape(event.target.name)}"]`;
        if (!form.querySelector(selector)) return;
        form.querySelectorAll(ROOT_SELECTOR).forEach(syncRoot);
      });
      form.dataset.aipkitInlinePromptVisibilityAttached = "true";
    }
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => init());
  } else {
    init();
  }

  window.aipkit_initAutogptInlinePrompts = init;
  window.aipkit_syncAutogptInlinePrompts = () => {
    document.querySelectorAll(ROOT_SELECTOR).forEach(syncRoot);
  };
})();
