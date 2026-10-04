/**
 * Chatbot page initialization and dynamic container discovery.
 * Restores shared conversation state, prepares required features and delegates UI to instance.js.
 */

(function () {
  // IIFE to avoid global scope pollution
  "use strict";

  window.aipkit_current_conversation_uuid = null;
  window.aipkit_is_fresh_session = true;
  window.aipkit_current_post_id = 0;
  window.aipkit_current_openai_response_id = null;
  window.aipkit_current_google_interaction_id = null;
  window.aipkit_active_file_context_provider = null;
  window.aipkit_active_file_context_data = null;

  window.aipkit_regenerateConversationUUID = function () {
    const newUUID = ([1e7] + -1e3 + -4e3 + -8e3 + -1e11).replace(
      /[018]/g,
      (c) =>
        (
          c ^
          (crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (c / 4)))
        ).toString(16)
    );
    sessionStorage.setItem("aipkit_current_conversation_uuid", newUUID);
    window.aipkit_current_conversation_uuid = newUUID;

    window.aipkit_current_openai_response_id = null;
    sessionStorage.removeItem("aipkit_current_openai_response_id");
    window.aipkit_current_google_interaction_id = null;
    sessionStorage.removeItem("aipkit_current_google_interaction_id");

    window.aipkit_chatUI_clearFileContext();
    return newUUID;
  };

  if (typeof window.aipkit_getMarkdownRenderer === "function") {
    window.aipkit_md = window.aipkit_getMarkdownRenderer();
  } else if (!window.aipkit_md || typeof window.aipkit_md.render !== "function") {
    window.aipkit_md = {
      aipkitIsFallback: true,
      render: function (text) {
        const escaper =
          window.aipkit_escapeHtml ||
          function (str) {
            if (typeof str !== "string") return "";
            return str
              .replace(/&/g, "&amp;")
              .replace(/</g, "&lt;")
              .replace(/>/g, "&gt;")
              .replace(/"/g, "&quot;")
              .replace(/'/g, "&#39;");
          };
        return escaper(text).replace(/\n/g, "<br>");
      },
    };
  }

  function resetConversationState() {
    window.aipkit_current_conversation_uuid = null;
    window.aipkit_is_fresh_session = true;
    window.aipkit_current_openai_response_id = null;
    sessionStorage.removeItem("aipkit_current_openai_response_id");
    window.aipkit_current_google_interaction_id = null;
    sessionStorage.removeItem("aipkit_current_google_interaction_id");
    window.aipkit_chatUI_clearFileContext();
  }

  // One owner per UI, including a popup container moved to fullscreen.
  const owners = new Map();
  const instances = new WeakMap();

  function releaseOwner(owner) {
    if (owners.get(owner.wrapper) !== owner) return;
    if (owner.openTimer) clearTimeout(owner.openTimer);
    owners.delete(owner.wrapper);
    owner.lifecycle?.suspend();
    owner.wrapper.classList.remove("aipkit-initializing");
    if (owner.record && window.aipkitChatInstances?.[owner.container.id] === owner.record) {
      delete window.aipkitChatInstances[owner.container.id];
    }
  }

  function isCurrent(owner) {
    if (owners.get(owner.wrapper) !== owner) return false;
    if (owner.wrapper.isConnected === false && !owner.container?.isConnected) {
      releaseOwner(owner);
      return false;
    }
    return true;
  }

  async function aipkit_initializeChatInstance(wrapper) {
    if (!wrapper || wrapper.isConnected === false) return;
    const existing = instances.get(wrapper);
    if (existing) {
      if (!owners.has(existing.wrapper)) {
        owners.set(existing.wrapper, existing);
        window.aipkitChatInstances[existing.container.id] = existing.record;
        existing.lifecycle?.resume();
      }
      return;
    }
    if (["aipkit-initializing", "aipkit-initialization-failed", "aipkit-initialized"]
      .some(name => wrapper.classList.contains(name))) return;

    const owner = { wrapper };
    owners.set(wrapper, owner);
    wrapper.classList.add("aipkit-initializing");
    const botId = wrapper.getAttribute("data-bot-id");
    const configAttr = wrapper.getAttribute("data-config");
    let config = null;
    const fail = (message, error, required = false) => {
      if (!isCurrent(owner)) return;
      console.error(`AIPKit Chat Init (${botId || "Unknown"}): ${message}`, error);
      const target = wrapper.querySelector(required ? ".aipkit_chat_messages" : ".aipkit_chat_container") || wrapper;
      if (typeof window.aipkit_chatUI_renderUIState === "function") {
        window.aipkit_chatUI_renderUIState(target, { message, type: "error", scope: "messages" });
      } else {
        target.textContent = message;
      }
      if (required) {
        wrapper.querySelectorAll(".aipkit_chat_input input, .aipkit_chat_input textarea, .aipkit_chat_input button")
          .forEach(control => { control.disabled = true; });
      }
      wrapper.classList.add("aipkit-initialization-failed");
      instances.delete(wrapper);
      if (owner.container) instances.delete(owner.container);
      releaseOwner(owner);
    };

    try {
      if (configAttr) {
        config = JSON.parse(configAttr);
        if (botId) config.botId = botId;
      }
    } catch (error) {
      fail("Chatbot configuration error.", error);
      return;
    }
    if (!config) {
      fail("Chatbot config load error.");
      return;
    }
    if (!config.botId && botId) config.botId = botId;
    // Required paid behavior must be ready before binding input or restoring forms.
    // Free bots stay synchronous and request no paid runtime.
    if (config.requireConsentCompliance || config.formGateEnabled) {
      try {
        await window.aipkit_chatUI_prepareRequiredFeatures(config);
      } catch (error) {
        fail(config.text?.connError || "Chatbot could not load a required feature. Please reload the page.", error, true);
        return;
      }
      if (!isCurrent(owner)) return;
    }
    window.aipkit_current_post_id = config.postId || 0;

    if (config.enableSidebar) {
      resetConversationState();
    } else {
      const storedUUID = sessionStorage.getItem(
        "aipkit_current_conversation_uuid"
      );
      const storedOpenAIRespId = sessionStorage.getItem(
        "aipkit_current_openai_response_id"
      );
      const storedGoogleInteractionId = sessionStorage.getItem(
        "aipkit_current_google_interaction_id"
      );

      if (storedUUID) {
        window.aipkit_current_conversation_uuid = storedUUID;
        window.aipkit_is_fresh_session = false;
        window.aipkit_current_openai_response_id = storedOpenAIRespId || null;
        window.aipkit_current_google_interaction_id =
          storedGoogleInteractionId || null;
        window.aipkit_chatUI_restoreFileContextForConversation(storedUUID, {
          invalidLogPrefix: `AIPKit Chat Init (${config.botId})`,
          errorMessage:
            "AIPKit Chat Init: Error loading file context from localStorage:",
        });
      } else {
        resetConversationState();
      }
    }

    if (typeof aipkit_setupChatInstanceUI !== "function") {
      fail("Chatbot script error.");
      return;
    }

    const chatContainer = wrapper.classList.contains("aipkit_chat_container")
      ? wrapper
      : wrapper.querySelector(".aipkit_chat_container");

    if (!chatContainer) {
      fail("Chatbot structure error.");
      return;
    }
    const triggerButton = config.popupEnabled ? wrapper.querySelector(".aipkit_popup_trigger") : null;
    if (config.popupEnabled && !triggerButton) {
      fail("Chatbot structure error: popup trigger button not found.");
      return;
    }

    config.guestUUID = window.aipkit_guest_uuid;
    config.conversationUUID = window.aipkit_current_conversation_uuid;
    config.previousOpenAIResponseId = window.aipkit_current_openai_response_id;
    config.previousGoogleInteractionId =
      window.aipkit_current_google_interaction_id;
    config.activeFileContextProvider =
      window.aipkit_active_file_context_provider;
    config.activeFileContextData = window.aipkit_active_file_context_data;

    if (!config.botId || !config.ajaxUrl || !config.text || !config.guestUUID) {
      fail("Chatbot configuration error.");
      return;
    }

    if (
      typeof window.aipkit_chatUI_applyCustomThemeStyles === "function" &&
      config.theme === "custom" &&
      config.customThemeSettings &&
      Object.keys(config.customThemeSettings).length > 0
    ) {
      const customThemeOptions = {
        skipDimensionSync: !!config.customThemePresetKey,
      };
      window.aipkit_chatUI_applyCustomThemeStyles(
        chatContainer,
        config.customThemeSettings,
        customThemeOptions
      );
    } else if (config.theme === "custom") {
      console.warn(
        `AIPKit Chat Init (${config.botId}): Custom theme selected but aipkit_chatUI_applyCustomThemeStyles not found or no settings provided.`
      );
    }

    let setupResult;
    try {
      setupResult = aipkit_setupChatInstanceUI(chatContainer, config);
    } catch (error) {
      fail("Chatbot script error.", error);
      return;
    }
    if (!setupResult?.publicApi || !setupResult.internalElements || !setupResult.internalActions) {
      fail("Chatbot UI error.");
      return;
    }
    owner.lifecycle = setupResult.lifecycle;
    if (!isCurrent(owner)) {
      owner.lifecycle?.suspend();
      return;
    }
    const uiInstance = setupResult.publicApi;

    if (!window.aipkitChatInstances) window.aipkitChatInstances = {};
    owner.container = chatContainer;
    owner.record = window.aipkitChatInstances[chatContainer.id] = {
      instance: uiInstance,
      elements: setupResult.internalElements,
      actions: setupResult.internalActions,
      state: setupResult.internalState || null,
      config,
    };

    instances.set(wrapper, owner);
    instances.set(chatContainer, owner);

    if (
      config.formGateEnabled &&
      window.aipkit_current_conversation_uuid &&
      typeof window.aipkit_chatUI_restorePendingForm === "function"
    ) {
      void window.aipkit_chatUI_restorePendingForm(
        setupResult.internalElements,
        config,
        setupResult.internalState,
        setupResult.internalActions
      );
    }

    if (config.popupEnabled) {
      if (uiInstance.setupPopupHandlers) {
        try {
          uiInstance.setupPopupHandlers(triggerButton);
        } catch (error) {
          fail("Chatbot script error.", error);
          return;
        }
        const delay = config.popupDelay;
        if (delay > 0 && !config.directVoiceMode && !wrapper.dataset.aipkitAutoOpened) {
          wrapper.dataset.aipkitAutoOpened = "true";
          owner.openTimer = setTimeout(() => {
            owner.openTimer = null;
            if (
              isCurrent(owner) && wrapper.isConnected &&
              uiInstance.isPopupOpen &&
              !uiInstance.isPopupOpen()
            ) {
              uiInstance.openPopup();
            }
          }, delay * 1000);
        }
      } else {
        console.error(
          `AIPKit Chat Init (${config.botId}): setupPopupHandlers method missing in UI instance.`
        );
      }
    }

    wrapper.classList.remove("aipkit-initializing");
    wrapper.classList.add("aipkit-initialized");
  }

  function scanAndInitialize() {
    document.querySelectorAll(".aipkit_chat_container, .aipkit_popup_wrapper").forEach(wrapper => {
      if (wrapper.classList.contains("aipkit_popup_wrapper") || !wrapper.closest(".aipkit_popup_wrapper")) {
        void aipkit_initializeChatInstance(wrapper);
      }
    });
  }

  document.addEventListener("DOMContentLoaded", scanAndInitialize);

  if (typeof MutationObserver === "function") {
    const discoverySelector = ".aipkit_chat_container, .aipkit_popup_wrapper, [id^='aipkit-chatbot-container-']";
    let scanTimeout;
    const observer = new MutationObserver(mutations => {
      if (mutations.some(mutation => mutation.removedNodes.length)) {
        for (const owner of owners.values()) isCurrent(owner);
      }
      if (mutations.some(mutation => [...mutation.addedNodes].some(node =>
        node.nodeType === 1 && (node.matches(discoverySelector) || node.querySelector(discoverySelector))
      ))) {
        clearTimeout(scanTimeout);
        scanTimeout = setTimeout(scanAndInitialize, 50);
      }
    });
    observer.observe(document.body, { childList: true, subtree: true });
  } else {
    console.warn("AIPKit Chat Init: MutationObserver not supported; dynamically added chatbots might not initialize automatically.");
  }

  window.aipkit_initializeChatInstance = aipkit_initializeChatInstance;

})();
