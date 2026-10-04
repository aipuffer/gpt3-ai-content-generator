/** Typing and image-generation loading rows for the chatbot. */
(function () {
  "use strict";

  function removeIndicatorNode(messagesEl, indicator, diagnosticName, detail) {
    try {
      messagesEl.removeChild(indicator);
    } catch (e) {
      if (e.name !== "NotFoundError") {
        console.warn("AIPKit " + diagnosticName + ": " + detail, e);
      }
    }
  }

  function removeLoadingIndicator(
    messagesEl, indicatorEl, idPrefix, selector, diagnosticName
  ) {
    if (!messagesEl) return;

    // If a specific indicator element is provided, try to remove that
    if (indicatorEl && indicatorEl.parentNode === messagesEl) {
      removeIndicatorNode(messagesEl, indicatorEl, diagnosticName, "Error removing provided indicator:");
      return;
    }

    // Fallback: try to find by ID based on botId (if available)
    const botId =
      messagesEl.closest(".aipkit_chat_container")?.dataset.botId ||
      messagesEl.closest(".aipkit_popup_wrapper")?.dataset.botId ||
      "default"; // Fallback botId for ID construction
    const indicatorId = `${idPrefix}${botId}`;
    const existingIndicatorById = messagesEl.querySelector(
      `#${CSS.escape(indicatorId)}`
    );
    if (existingIndicatorById) {
      removeIndicatorNode(messagesEl, existingIndicatorById, diagnosticName, "Error removing indicator by ID:");
    }
    // As a last resort, find by generic class (might remove other indicators if IDs are missing)
    const genericIndicator = messagesEl.querySelector(selector);
    if (genericIndicator) {
      removeIndicatorNode(messagesEl, genericIndicator, diagnosticName, "Error removing indicator by class:");
    }
  }

  function clearLoadingIndicators(messagesEl) {
    if (typeof window.aipkit_chatUI_removeTypingIndicator === "function") {
      window.aipkit_chatUI_removeTypingIndicator(messagesEl); // Remove previous typing loader
    }
    if (typeof window.aipkit_chatUI_removeImageLoadingIndicator === "function") {
      window.aipkit_chatUI_removeImageLoadingIndicator(messagesEl);
    }
  }

  function appendLoadingIndicator(messagesEl, indicator) {
    messagesEl.appendChild(indicator);
    if (typeof window.aipkit_chatUI_scrollToBottom === "function") {
      window.aipkit_chatUI_scrollToBottom(messagesEl, true);
    }
    return indicator;
  }

  function aipkit_chatUI_removeTypingIndicator(messagesEl, indicatorEl = null) {
    removeLoadingIndicator(
      messagesEl, indicatorEl,
      "aipkit-typing-indicator-", ".aipkit_typing-indicator", "removeTypingIndicator"
    );
  }
  window.aipkit_chatUI_removeTypingIndicator = aipkit_chatUI_removeTypingIndicator;

  function aipkit_chatUI_removeImageLoadingIndicator(messagesEl, indicatorEl = null) {
    removeLoadingIndicator(
      messagesEl, indicatorEl,
      "aipkit-image-loading-indicator-", ".aipkit_image_loading_indicator", "removeImageLoadingIndicator"
    );
  }
  window.aipkit_chatUI_removeImageLoadingIndicator = aipkit_chatUI_removeImageLoadingIndicator;

  function aipkit_chatUI_showTypingIndicator(messagesEl, config) {
    if (!messagesEl || !config || !config.text) return null;

    // Clear other indicators first
    clearLoadingIndicators(messagesEl);

    const botId = config.botId || "default";
    const indicatorId = `aipkit-typing-indicator-${botId}`;

    if (typeof window.aipkit_chatUI_createMessageRow !== "function") {
      return null;
    }
    const { messageEl: indicator, bubble } =
      window.aipkit_chatUI_createMessageRow("bot", ["aipkit_typing-indicator"]);
    indicator.id = indicatorId;

    // If custom typing text provided, show text; otherwise show animated dots
    const _typingText = (config.customTypingText || "").trim();
    if (_typingText !== "") {
      const safeText = (window.aipkit_escapeHtml || ((s)=>String(s)))(_typingText);
      bubble.innerHTML = `
        <span class="aipkit_typing-text">${safeText}</span>
        <span class="aipkit_typing-ellipsis-text" aria-hidden="true"><span>.</span><span>.</span><span>.</span></span>
      `;
    } else {
      bubble.innerHTML = `
        <span class="aipkit_typing-dot"></span>
        <span class="aipkit_typing-dot"></span>
        <span class="aipkit_typing-dot"></span>
      `;
    }

    return appendLoadingIndicator(messagesEl, indicator);
  }
  window.aipkit_chatUI_showTypingIndicator = aipkit_chatUI_showTypingIndicator;

  function aipkit_chatUI_showImageLoadingIndicator(messagesEl, config) {
    if (!messagesEl || !config) return null;

    // Clear other indicators first
    clearLoadingIndicators(messagesEl);

    const botId = config.botId || "default";
    const indicatorId = `aipkit-image-loading-indicator-${botId}`;

    if (typeof window.aipkit_chatUI_createMessageRow !== "function") {
      return null;
    }
    const { messageEl: indicator, bubble } =
      window.aipkit_chatUI_createMessageRow(
        "bot",
        ["aipkit_image_loading_indicator"],
        ["aipkit_image_loader_bubble_thumbnail"]
      );
    indicator.id = indicatorId;
    bubble.innerHTML =
      '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M15 8h.01" /><path d="M4 4m0 2a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2z" /><path d="M4 15l4 -4a3 5 0 0 1 3 0l5 5" /><path d="M14 14l1 -1a3 5 0 0 1 3 0l2 2" /></svg>';

    return appendLoadingIndicator(messagesEl, indicator);
  }
  window.aipkit_chatUI_showImageLoadingIndicator = aipkit_chatUI_showImageLoadingIndicator;
})();
