/**
 * AIPKit Content Writer - Alert Modal
 * Displays a lightweight modal for non-blocking alerts.
 */
(function () {
  "use strict";

  let alertOverlay = null;
  let alertSeq = 0;

  function closeAlertModal() {
    if (!alertOverlay) return;

    const overlay = alertOverlay;
    alertOverlay = null;

    overlay.classList.remove("aipkit-active");

    if (overlay.__aipkitAlertKeydown) {
      document.removeEventListener("keydown", overlay.__aipkitAlertKeydown);
      overlay.__aipkitAlertKeydown = null;
    }

    const cleanup = () => {
      if (overlay.parentNode) {
        overlay.parentNode.removeChild(overlay);
      }
    };

    overlay.addEventListener("transitionend", cleanup, { once: true });
    window.setTimeout(cleanup, 250);
  }

  function createModalShell(modalId, title, onDismiss = null) {
    const overlay = document.createElement("div");
    overlay.className = "aipkit-modal-overlay aipkit-alert-modal-overlay";
    overlay.addEventListener("click", (event) => {
      if (event.target === overlay) {
        closeAlertModal();
        if (onDismiss) onDismiss();
      }
    });

    const content = document.createElement("div");
    content.className = "aipkit-modal-content aipkit_cw_alert_modal_content";
    content.setAttribute("role", "dialog");
    content.setAttribute("aria-modal", "true");
    content.setAttribute("aria-labelledby", `${modalId}_title`);

    const header = document.createElement("div");
    header.className = "aipkit-modal-header";

    const titleEl = document.createElement("h3");
    titleEl.className = "aipkit-modal-title";
    titleEl.id = `${modalId}_title`;
    titleEl.textContent = title;

    const closeBtn = document.createElement("button");
    closeBtn.type = "button";
    closeBtn.className = "aipkit-modal-close-btn";
    closeBtn.setAttribute("aria-label", "Close");
    closeBtn.textContent = "×";

    const body = document.createElement("div");
    body.className = "aipkit-modal-body";

    header.appendChild(titleEl);
    header.appendChild(closeBtn);
    content.appendChild(header);
    content.appendChild(body);
    overlay.appendChild(content);

    return { overlay, content, header, titleEl, body, closeBtn };
  }

  function showAlertModal(message, options = {}) {
    const title = options.title || "Notice";
    const okText = options.okText || "OK";
    const okClass = options.okClass || "aipkit_btn-primary";
    const actionsConfig = Array.isArray(options.actions) ? options.actions : null;
    const bullets = Array.isArray(options.bullets) ? options.bullets : null;

    if (alertOverlay) {
      closeAlertModal();
    }

    const modalId = `aipkit_cw_alert_${Date.now()}_${alertSeq++}`;
    const { overlay, body, closeBtn } = createModalShell(modalId, title);

    const messageEl = document.createElement("p");
    messageEl.className = "aipkit_cw_alert_modal_message";
    messageEl.textContent = message || "";

    if (bullets && bullets.length) {
      const list = document.createElement("ul");
      list.className = "aipkit_cw_alert_modal_list";
      bullets.forEach((item) => {
        const li = document.createElement("li");
        li.textContent = item;
        list.appendChild(li);
      });
      body.appendChild(messageEl);
      body.appendChild(list);
    } else {
      body.appendChild(messageEl);
    }

    const actions = document.createElement("div");
    actions.className = "aipkit_cw_alert_modal_actions";

    let focusTarget = null;

    if (actionsConfig && actionsConfig.length) {
      actionsConfig.forEach((action, index) => {
        const isLink = Boolean(action && action.href);
        const label = action?.label || (index === 0 ? okText : "Close");
        const className = action?.className || (index === 0 ? "aipkit_btn aipkit_btn-primary" : "aipkit_btn aipkit_btn-secondary");

        const control = document.createElement(isLink ? "a" : "button");
        control.className = className;
        control.textContent = label;

        if (isLink) {
          control.href = action.href;
          control.target = action.target || "_blank";
          control.rel = action.rel || "noopener noreferrer";
        } else {
          control.type = "button";
        }

        const handleClick = () => {
          if (typeof action?.onClick === "function") {
            action.onClick();
          }
          if (action?.closeOnClick !== false) {
            closeAlertModal();
          }
        };

        control.addEventListener("click", handleClick);
        actions.appendChild(control);
        if (!focusTarget) focusTarget = control;
      });
    } else {
      const okBtn = document.createElement("button");
      okBtn.type = "button";
      okBtn.className = `aipkit_btn ${okClass}`;
      okBtn.textContent = okText;
      okBtn.addEventListener("click", closeAlertModal);
      actions.appendChild(okBtn);
      focusTarget = okBtn;
    }

    body.appendChild(actions);

    document.body.appendChild(overlay);

    const keydownHandler = (event) => {
      if (event.key === "Escape") {
        closeAlertModal();
      }
    };
    overlay.__aipkitAlertKeydown = keydownHandler;
    document.addEventListener("keydown", keydownHandler);

    closeBtn.addEventListener("click", closeAlertModal);
    window.requestAnimationFrame(() => {
      overlay.classList.add("aipkit-active");
      if (focusTarget) {
        focusTarget.focus();
      }
    });

    alertOverlay = overlay;
  }

  function showConfirmModal(message, options = {}) {
    const title = options.title || "Confirm";
    const confirmText = options.confirmText || "Confirm";
    const variant = ["danger", "warning"].includes(options.variant)
      ? options.variant
      : "default";
    const secondaryText = options.secondaryText || "";
    const emphasisText = options.emphasisText || "";
    const questionText = options.questionText || "";
    const cancelText = options.cancelText || "Cancel";
    const preferCancel = options.preferCancel === true;
    const hideCancel = options.hideCancel === true;
    const secondaryClass =
      options.secondaryClass || "aipkit_btn aipkit_btn-secondary";
    const onConfirm =
      typeof options.onConfirm === "function" ? options.onConfirm : null;
    const onSecondary =
      typeof options.onSecondary === "function" ? options.onSecondary : null;
    const onCancel =
      typeof options.onCancel === "function" ? options.onCancel : null;

    if (alertOverlay) {
      closeAlertModal();
    }

    const modalId = `aipkit_cw_confirm_${Date.now()}_${alertSeq++}`;
    const { overlay, content, header, titleEl, body, closeBtn } = createModalShell(
      modalId,
      title,
      onCancel
    );

    overlay.classList.add(
      "aipkit-alert-modal-overlay--confirm",
      `aipkit-alert-modal-overlay--${variant}`
    );
    content.classList.add(
      "aipkit_cw_confirm_modal_content",
      "aipkit_cw_confirm_modal_content--modern",
      `aipkit_cw_confirm_modal_content--${variant}`
    );
    header.classList.add("aipkit_cw_confirm_modal_header--modern");
    body.classList.add(
      "aipkit_cw_confirm_modal_body",
      "aipkit_cw_confirm_modal_body--modern"
    );

    const icon = document.createElement("span");
    icon.className = `aipkit_cw_confirm_modal_icon aipkit_cw_confirm_modal_icon--${variant}`;
    icon.setAttribute("aria-hidden", "true");

    const iconGlyph = document.createElement("span");
    // A caller may name its own dashicon, e.g. "upload"; otherwise the variant's.
    iconGlyph.className = /^[a-z0-9-]+$/.test(String(options.icon || ""))
      ? `dashicons dashicons-${options.icon}`
      : variant === "default"
        ? "dashicons dashicons-info-outline"
        : "dashicons dashicons-warning";
    icon.appendChild(iconGlyph);
    header.insertBefore(icon, titleEl);
    closeBtn.hidden = true;

    const messageEl = document.createElement("p");
    messageEl.className = "aipkit_cw_alert_modal_message";
    messageEl.id = `${modalId}_description`;
    messageEl.textContent = message || "";
    content.setAttribute("aria-describedby", messageEl.id);

    const emphasisEl = document.createElement("p");
    emphasisEl.className = "aipkit_cw_alert_modal_emphasis";
    emphasisEl.textContent = emphasisText;

    const questionEl = document.createElement("p");
    questionEl.className = "aipkit_cw_alert_modal_question";
    questionEl.textContent = questionText;

    const actions = document.createElement("div");
    actions.className =
      "aipkit_cw_alert_modal_actions aipkit_cw_alert_modal_actions--confirm";
    if (variant === "danger") {
      actions.classList.add("aipkit_cw_alert_modal_actions--danger");
    }

    let cancelBtn = null;
    if (!hideCancel) {
      cancelBtn = document.createElement("button");
      cancelBtn.type = "button";
      cancelBtn.className = preferCancel
        ? "aipkit_btn aipkit_btn-primary"
        : "aipkit_btn aipkit_btn-secondary";
      cancelBtn.textContent = cancelText;
    }

    const secondaryBtn = document.createElement("button");
    secondaryBtn.type = "button";
    secondaryBtn.className = secondaryClass;
    secondaryBtn.textContent = secondaryText;

    const confirmBtn = document.createElement("button");
    confirmBtn.type = "button";
    confirmBtn.className =
      variant === "danger"
        ? "aipkit_btn aipkit_btn-danger"
        : preferCancel
          ? "aipkit_btn aipkit_btn-secondary"
          : "aipkit_btn aipkit_btn-primary";
    confirmBtn.textContent = confirmText;

    if (preferCancel) {
      if (secondaryText) {
        actions.appendChild(secondaryBtn);
      }
      actions.appendChild(confirmBtn);
      if (cancelBtn) {
        actions.appendChild(cancelBtn);
      }
    } else {
      if (cancelBtn) {
        actions.appendChild(cancelBtn);
      }
      if (secondaryText) {
        actions.appendChild(secondaryBtn);
      }
      actions.appendChild(confirmBtn);
    }
    body.appendChild(messageEl);
    // More than a sentence, when the caller built it: e.g. which backup file, and what it replaces.
    if (typeof Node !== "undefined" && options.content instanceof Node) {
      body.appendChild(options.content);
    }
    if (emphasisText) {
      body.appendChild(emphasisEl);
    }
    if (questionText) {
      body.appendChild(questionEl);
    }
    body.appendChild(actions);

    document.body.appendChild(overlay);

    const keydownHandler = (event) => {
      if (event.key === "Escape") {
        closeAlertModal();
        if (onCancel) onCancel();
      }
    };
    overlay.__aipkitAlertKeydown = keydownHandler;
    document.addEventListener("keydown", keydownHandler);

    closeBtn.addEventListener("click", () => {
      closeAlertModal();
      if (onCancel) onCancel();
    });
    if (cancelBtn) {
      cancelBtn.addEventListener("click", () => {
        closeAlertModal();
        if (onCancel) onCancel();
      });
    }
    if (secondaryText) {
      secondaryBtn.addEventListener("click", () => {
        closeAlertModal();
        if (onSecondary) onSecondary();
      });
    }
    confirmBtn.addEventListener("click", () => {
      closeAlertModal();
      if (onConfirm) onConfirm();
    });

    window.requestAnimationFrame(() => {
      overlay.classList.add("aipkit-active");
      const focusTarget =
        cancelBtn && (preferCancel || variant === "danger")
          ? cancelBtn
          : confirmBtn;
      focusTarget.focus();
    });

    alertOverlay = overlay;
  }

  function showPromptModal(options = {}) {
    const title = options.title || "Create";
    const message = options.message || "";
    const label = options.label || "Name";
    const placeholder = options.placeholder || "";
    const initialValue = options.initialValue || "";
    const confirmText = options.confirmText || "Create";
    const cancelText = options.cancelText || "Cancel";
    const requiredMessage = options.requiredMessage || "Enter a name.";
    const maxLength = Number.isFinite(Number(options.maxLength))
      ? Math.max(1, Number(options.maxLength))
      : 100;
    const onConfirm =
      typeof options.onConfirm === "function" ? options.onConfirm : null;
    const onCancel =
      typeof options.onCancel === "function" ? options.onCancel : null;

    if (alertOverlay) {
      closeAlertModal();
    }

    const modalId = `aipkit_cw_prompt_${Date.now()}_${alertSeq++}`;
    const { overlay, content, header, titleEl, body, closeBtn } = createModalShell(
      modalId,
      title,
      onCancel
    );

    overlay.classList.add("aipkit-alert-modal-overlay--prompt");
    content.classList.add("aipkit_cw_prompt_modal_content");
    header.classList.add("aipkit_cw_prompt_modal_header");
    body.classList.add("aipkit_cw_prompt_modal_body");

    const icon = document.createElement("span");
    icon.className = "aipkit_cw_prompt_modal_icon";
    icon.setAttribute("aria-hidden", "true");

    const iconGlyph = document.createElement("span");
    iconGlyph.className = "dashicons dashicons-format-chat";
    icon.appendChild(iconGlyph);
    header.insertBefore(icon, titleEl);
    closeBtn.hidden = true;

    if (message) {
      const messageEl = document.createElement("p");
      messageEl.className = "aipkit_cw_prompt_modal_message";
      messageEl.id = `${modalId}_description`;
      messageEl.textContent = message;
      content.setAttribute("aria-describedby", messageEl.id);
      body.appendChild(messageEl);
    }

    const form = document.createElement("form");
    form.className = "aipkit_cw_prompt_modal_form";
    form.noValidate = true;

    const field = document.createElement("div");
    field.className = "aipkit_cw_prompt_modal_field";

    const inputId = `${modalId}_input`;
    const errorId = `${modalId}_error`;
    const labelEl = document.createElement("label");
    labelEl.className = "aipkit_cw_prompt_modal_label";
    labelEl.htmlFor = inputId;
    labelEl.textContent = label;

    const input = document.createElement("input");
    input.type = "text";
    input.id = inputId;
    input.name = options.inputName || "aipkit_prompt_value";
    input.className = "aipkit_cw_prompt_modal_input";
    input.placeholder = placeholder;
    input.value = initialValue;
    input.maxLength = maxLength;
    input.required = true;
    input.autocomplete = options.autocomplete || "off";

    const errorEl = document.createElement("p");
    errorEl.className = "aipkit_cw_prompt_modal_error";
    errorEl.id = errorId;
    errorEl.setAttribute("role", "alert");
    errorEl.textContent = requiredMessage;
    errorEl.hidden = true;

    field.appendChild(labelEl);
    field.appendChild(input);
    field.appendChild(errorEl);

    const actions = document.createElement("div");
    actions.className =
      "aipkit_cw_alert_modal_actions aipkit_cw_prompt_modal_actions";

    const cancelBtn = document.createElement("button");
    cancelBtn.type = "button";
    cancelBtn.className = "aipkit_btn aipkit_btn-secondary";
    cancelBtn.textContent = cancelText;

    const confirmBtn = document.createElement("button");
    confirmBtn.type = "submit";
    confirmBtn.className = "aipkit_btn aipkit_btn-primary";
    confirmBtn.textContent = confirmText;

    actions.appendChild(cancelBtn);
    actions.appendChild(confirmBtn);
    form.appendChild(field);
    form.appendChild(actions);
    body.appendChild(form);

    const clearValidation = () => {
      input.removeAttribute("aria-invalid");
      input.removeAttribute("aria-describedby");
      errorEl.hidden = true;
    };
    const showValidation = () => {
      input.setAttribute("aria-invalid", "true");
      input.setAttribute("aria-describedby", errorId);
      errorEl.hidden = false;
      input.focus();
    };
    const cancel = () => {
      closeAlertModal();
      if (onCancel) onCancel();
    };

    input.addEventListener("input", clearValidation);
    input.addEventListener("keydown", (event) => {
      if (event.key === "Enter" && !event.isComposing) {
        event.preventDefault();
        form.requestSubmit();
      }
    });
    form.addEventListener("submit", (event) => {
      event.preventDefault();
      const value = input.value.trim();
      if (!value) {
        showValidation();
        return;
      }

      closeAlertModal();
      if (onConfirm) onConfirm(value);
    });
    cancelBtn.addEventListener("click", cancel);
    closeBtn.addEventListener("click", cancel);

    const keydownHandler = (event) => {
      if (event.key === "Escape") {
        cancel();
      }
    };
    overlay.__aipkitAlertKeydown = keydownHandler;
    document.addEventListener("keydown", keydownHandler);

    document.body.appendChild(overlay);
    alertOverlay = overlay;

    window.requestAnimationFrame(() => {
      overlay.classList.add("aipkit-active");
      input.focus();
      input.select();
    });
  }

  window.aipkit_showAlertModal = showAlertModal;
  window.aipkit_showConfirmModal = showConfirmModal;
  window.aipkit_showPromptModal = showPromptModal;
})();
