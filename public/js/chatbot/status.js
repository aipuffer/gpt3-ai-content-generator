/** Chatbot inline notices and empty/loading/error state blocks. */
(function () {
  "use strict";

  const NOTICE_CLASS = "aipkit_chat_ui_notice";
  const NOTICE_VISIBLE_CLASS = "aipkit_chat_ui_notice--visible";
  const TYPE_CLASSES = new Set([
    "info",
    "success",
    "error",
    "processing",
    "warning",
  ]);
  const noticeAutoHideTimers = new WeakMap();
  const noticeHideTimers = new WeakMap();

  function resolveInputArea(context) {
    if (!context) {
      return null;
    }

    if (context.inputArea && context.inputArea.nodeType === 1) {
      return context.inputArea;
    }

    if (context.elements && context.elements.inputArea) {
      return context.elements.inputArea;
    }

    if (context.container && context.container.nodeType === 1) {
      return context.container.querySelector(".aipkit_chat_input");
    }

    const messagesEl = context.messagesEl
      ? context.messagesEl
      : context.elements && context.elements.messagesEl
      ? context.elements.messagesEl
      : null;
    if (messagesEl && messagesEl.nodeType === 1) {
      const chatContainer = messagesEl.closest(".aipkit_chat_container");
      if (chatContainer) {
        return chatContainer.querySelector(".aipkit_chat_input");
      }
    }

    return null;
  }

  function getOrCreateNoticeEl(inputArea) {
    let noticeEl = inputArea.querySelector(`.${NOTICE_CLASS}`);
    if (noticeEl) {
      return noticeEl;
    }

    noticeEl = document.createElement("div");
    noticeEl.className = NOTICE_CLASS;
    noticeEl.setAttribute("role", "status");
    noticeEl.setAttribute("aria-live", "polite");

    const inputWrapper = inputArea.querySelector(".aipkit_chat_input_wrapper");
    if (inputWrapper) {
      inputArea.insertBefore(noticeEl, inputWrapper);
    } else {
      inputArea.appendChild(noticeEl);
    }

    return noticeEl;
  }

  function clearTimer(timers, noticeEl) {
    const timerId = timers.get(noticeEl);
    if (timerId) {
      clearTimeout(timerId);
      timers.delete(noticeEl);
    }
  }

  function clearNoticeTimer(noticeEl) {
    clearTimer(noticeAutoHideTimers, noticeEl);
    clearTimer(noticeHideTimers, noticeEl);
  }

  function hideNotice(noticeEl) {
    if (!noticeEl) {
      return;
    }

    clearNoticeTimer(noticeEl);

    const completeHide = () => {
      noticeEl.textContent = "";
      noticeEl.style.display = "none";
      noticeEl.className = NOTICE_CLASS;
      noticeHideTimers.delete(noticeEl);
    };

    noticeEl.classList.remove(NOTICE_VISIBLE_CLASS);
    const hideTimerId = window.setTimeout(completeHide, 220);
    noticeHideTimers.set(noticeEl, hideTimerId);
  }

  /**
   * Displays an inline, non-blocking notice in the chat input area.
   * @param {string} message
   * @param {string} [type='info'] Supported: info|success|error|processing|warning
   * @param {object} [context] Context object (elements|inputArea|container|messagesEl)
   * @param {boolean} [autoHide] Whether to auto-hide notice. Defaults to true except processing.
   * @param {number} [autoHideMs] Delay for auto-hide.
   */
  function aipkit_chatUI_showInlineNotice(
    message,
    type = "info",
    context = {},
    autoHide,
    autoHideMs
  ) {
    const normalizedMessage = String(message || "").trim();
    if (!normalizedMessage) {
      return;
    }

    const normalizedType = TYPE_CLASSES.has(type) ? type : "info";
    const inputArea = resolveInputArea(context);

    if (!inputArea) {
      console.warn(
        "AIPKit Inline Notice: Could not resolve input area for message:",
        normalizedMessage
      );
      return;
    }

    const noticeEl = getOrCreateNoticeEl(inputArea);
    clearNoticeTimer(noticeEl);

    noticeEl.textContent = normalizedMessage;
    if (context.action) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'aipkit_chat_notice_action';
      button.textContent = context.action.label;
      button.addEventListener('click', async () => {
        button.disabled = true;
        try { await context.action.run(); } finally { button.disabled = false; }
      });
      noticeEl.appendChild(button);
    }
    noticeEl.className = `${NOTICE_CLASS} aipkit_status-${normalizedType}`;
    noticeEl.style.display = "block";
    requestAnimationFrame(() => {
      if (noticeEl.style.display !== "none") {
        noticeEl.classList.add(NOTICE_VISIBLE_CLASS);
      }
    });

    const shouldAutoHide =
      typeof autoHide === "boolean"
        ? autoHide
        : normalizedType !== "processing";

    if (!shouldAutoHide) {
      return;
    }

    const delay = Number.isFinite(autoHideMs)
      ? Math.max(1000, autoHideMs)
      : normalizedType === "error" || normalizedType === "warning"
      ? 7000
      : 4500;

    const timerId = window.setTimeout(() => {
      hideNotice(noticeEl);
    }, delay);

    noticeAutoHideTimers.set(noticeEl, timerId);
  }

  window.aipkit_chatUI_showInlineNotice = aipkit_chatUI_showInlineNotice;
  window.aipkit_chatUI_showSpeechError = (error, context) => {
    const action = error.checkStatus ? {
      label: context.config?.text?.audioCheckStatus || 'Check status',
      run: async () => {
        if (context.isCurrent && !context.isCurrent()) return;
        try {
          const message = await error.checkStatus();
          if (!context.isCurrent || context.isCurrent()) aipkit_chatUI_showInlineNotice(message, 'info', { ...context, action }, false);
        } catch (failure) {
          if (context.isCurrent && !context.isCurrent()) return;
          if (failure.code === 'speech_request_missing' && error.allowNewRequest) {
            aipkit_chatUI_showInlineNotice(context.config?.text?.audioMissing || 'The original request cannot be checked. Starting again may use additional credits.', 'warning', { ...context, action: {
              label: context.config?.text?.audioAllowNew || 'Allow a new request',
              run: () => {
                if (context.isCurrent && !context.isCurrent()) return;
                error.allowNewRequest();
                aipkit_chatUI_showInlineNotice(context.config?.text?.audioNewAllowed || 'You can try again. This starts a new request.', 'info', context);
              },
            } }, false);
          } else aipkit_chatUI_showInlineNotice(failure.message, 'error', { ...context, action }, false);
        }
      },
    } : null;
    aipkit_chatUI_showInlineNotice(error.message, 'error', { ...context, action }, !action);
  };
})();

(function () {
  "use strict";

  const ALLOWED_TYPES = new Set(["muted", "info", "error", "loading"]);

  function createStateElement(options = {}) {
    const type = ALLOWED_TYPES.has(options.type) ? options.type : "muted";
    const message = String(options.message || "").trim();
    const scope = options.scope === "messages" ? "messages" : "sidebar";
    const showSpinner = !!options.showSpinner;

    const wrapper = document.createElement("div");
    wrapper.className = `aipkit_ui_state aipkit_ui_state--${type} aipkit_ui_state--${scope}`;
    if (showSpinner && !message) {
      wrapper.classList.add("aipkit_ui_state--spinner-only");
    }

    if (message) {
      const text = document.createElement("p");
      text.className = "aipkit_ui_state_text";
      text.textContent = message;
      wrapper.appendChild(text);
    }

    if (showSpinner) {
      const spinnerWrap = document.createElement("span");
      spinnerWrap.className = "aipkit_ui_state_spinner";
      const spinner = document.createElement("span");
      spinner.className = "aipkit_spinner";
      spinner.setAttribute("aria-hidden", "true");
      spinnerWrap.appendChild(spinner);
      wrapper.appendChild(spinnerWrap);
    }

    return wrapper;
  }

  /**
   * Renders a state block inside target and clears previous content.
   * @param {HTMLElement} target
   * @param {object} options
   */
  function aipkit_chatUI_renderUIState(target, options = {}) {
    if (!target) {
      return;
    }

    target.innerHTML = "";
    target.appendChild(createStateElement(options));
  }

  window.aipkit_chatUI_createStateElement = createStateElement;
  window.aipkit_chatUI_renderUIState = aipkit_chatUI_renderUIState;
})();
