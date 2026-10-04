/** Chatbot message-row structure and entry-animation restart. */
(function () {
  "use strict";

  function aipkit_chatUI_triggerMessageAnimation(messageEl) {
    if (!messageEl) return;

    const inlineAnimation = messageEl.style.animation;
    messageEl.style.animation = "none";
    void messageEl.offsetHeight;
    if (inlineAnimation) {
      messageEl.style.animation = inlineAnimation;
    } else {
      messageEl.style.removeProperty("animation");
    }
  }

  window.aipkit_chatUI_triggerMessageAnimation =
    aipkit_chatUI_triggerMessageAnimation;
})();

(function () {
  "use strict";

  function createMessageElement(baseClass, classes) {
    const element = document.createElement("div");
    element.className = baseClass;
    classes.filter(Boolean).forEach((className) => {
      element.classList.add(className);
    });
    return element;
  }

  /**
   * Creates the shared message row and its bubble without inserting the row.
   * @param {string} sender 'user' or 'bot'.
   * @param {string[]} extraClasses Additional row classes.
   * @param {string[]} bubbleClasses Additional bubble classes.
   * @returns {{messageEl: HTMLElement, bubble: HTMLElement}}
   */
  function aipkit_chatUI_createMessageRow(
    sender,
    extraClasses = [],
    bubbleClasses = []
  ) {
    const validSender = sender === "user" ? "user" : "bot";
    const messageEl = createMessageElement(
      `aipkit_chat_message aipkit_chat_message-${validSender}`,
      extraClasses
    );
    const bubble = createMessageElement("aipkit_chat_bubble", bubbleClasses);
    messageEl.appendChild(bubble);

    return { messageEl, bubble };
  }

  window.aipkit_chatUI_createMessageRow = aipkit_chatUI_createMessageRow;
})();
