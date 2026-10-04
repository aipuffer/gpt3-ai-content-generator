/** Chatbot complete/streamed message rendering and inline stream cursor. */
(function () {
  "use strict";

  function applyBotEnhancements(bubble, config, citations) {
    const displayCitations =
      typeof window.aipkit_chatUI_filterDisplayCitations === "function"
        ? window.aipkit_chatUI_filterDisplayCitations(citations)
        : citations;

    if (
      config.showSources !== false &&
      Array.isArray(displayCitations) &&
      displayCitations.length > 0 &&
      typeof window.aipkit_chatUI_applyInlineCitationMarkers === "function"
    ) {
      window.aipkit_chatUI_applyInlineCitationMarkers(bubble, displayCitations);
    }
    if (typeof window.aipkit_chatUI_attachCodeCopyButtons === "function") {
      window.aipkit_chatUI_attachCodeCopyButtons(bubble, config);
    }
  }

  function removeInitialGreetings(messagesEl) {
    const initialGreetings = messagesEl.querySelectorAll(
      ".aipkit_initial_greeting"
    );
    initialGreetings.forEach((greeting) => greeting.remove());
  }

  function appendMessageRow(messagesEl, messageEl) {
    messagesEl.appendChild(messageEl);
    if (typeof window.aipkit_chatUI_triggerMessageAnimation === "function") {
      window.aipkit_chatUI_triggerMessageAnimation(messageEl);
    }
  }

  (function () {
    "use strict";

    function appendPlainTextLines(container, text) {
      const lines = String(text || "").split("\n");
      lines.forEach((line, index) => {
        container.appendChild(document.createTextNode(line));
        if (index < lines.length - 1) {
          container.appendChild(document.createElement("br"));
        }
      });
    }

    function renderBotMessageContent(bubble, content, config, citations) {
      const renderer =
        typeof window.aipkit_getMarkdownRenderer === "function"
          ? window.aipkit_getMarkdownRenderer()
          : window.aipkit_md;

      bubble.setAttribute("data-raw-text", content);

      if (renderer && typeof renderer.render === "function") {
        bubble.innerHTML = renderer.render(content);
        applyBotEnhancements(bubble, config, citations);
      } else {
        appendPlainTextLines(bubble, content);
      }

      if (
        renderer &&
        renderer.aipkitIsFallback &&
        typeof window.aipkit_ensureMarkdownParser === "function" &&
        bubble.dataset.aipkitMarkdownUpgradePending !== "1"
      ) {
        bubble.dataset.aipkitMarkdownUpgradePending = "1";
        void window.aipkit_ensureMarkdownParser().then(function (upgradedRenderer) {
          delete bubble.dataset.aipkitMarkdownUpgradePending;
          if (
            !bubble.isConnected ||
            !upgradedRenderer ||
            upgradedRenderer.aipkitIsFallback
          ) {
            return;
          }

          const rawText = bubble.getAttribute("data-raw-text") || content;
          bubble.innerHTML = upgradedRenderer.render(rawText);
          applyBotEnhancements(bubble, config, citations);
        });
      }
    }

    function getQuotaNoticeData(content) {
      if (!content || typeof content !== "object") {
        return null;
      }

      if (content.type === "quota_notice" && content.notice && typeof content.notice === "object") {
        return content.notice;
      }

      if (content.type === "quota_notice") {
        return content;
      }

      return null;
    }

    function appendQuotaNoticeContent(bubble, content, config) {
      const notice = getQuotaNoticeData(content) || {};
      const title = String(notice.title || "").trim();
      const message = String(notice.message || "").trim();
      const actions = Array.isArray(notice.actions) ? notice.actions : [];
      const wrapper = document.createElement("div");
      const spokenParts = [];

      bubble.classList.add("aipkit_chat_bubble--quota");
      wrapper.className = "aipkit_chat_quota_notice";

      if (title) {
        const titleEl = document.createElement("div");
        titleEl.className = "aipkit_chat_quota_title";
        titleEl.textContent = title;
        wrapper.appendChild(titleEl);
        spokenParts.push(title);
      }

      if (message) {
        const messageEl = document.createElement("div");
        messageEl.className = "aipkit_chat_quota_message";
        appendPlainTextLines(messageEl, message);
        wrapper.appendChild(messageEl);
        spokenParts.push(message);
      }

      const validActions = actions.filter((action) => {
        const label = String(action?.label || "").trim();
        const url = String(action?.url || "").trim();
        return label && url;
      });

      if (validActions.length > 0) {
        const actionsEl = document.createElement("div");
        actionsEl.className = "aipkit_chat_quota_actions";

        validActions.forEach((action) => {
          const actionLink = document.createElement("a");
          const variant =
            action.variant === "secondary" ? "secondary" : "primary";

          actionLink.className = `aipkit_chat_quota_action aipkit_chat_quota_action--${variant}`;
          actionLink.href = String(action.url);
          actionLink.target = "_blank";
          actionLink.rel = "noopener noreferrer";
          actionLink.textContent = String(action.label);
          actionsEl.appendChild(actionLink);
          spokenParts.push(String(action.label));
        });

        wrapper.appendChild(actionsEl);
      }

      bubble.appendChild(wrapper);

      return spokenParts.join(". ");
    }

    /**
     * Appends a complete chat message bubble and its action container.
     * Used for user messages and non-streaming bot responses.
     * Handles text content OR an image data object.
     * @param {HTMLElement} messagesEl - The messages container.
     * @param {string|object} content - The message text OR an image data object {type:'image', images:[{url, media_library_url, b64_json, revised_prompt}], prompt} for bot, OR {text: "...", user_image: {base64_data, mime_type}} for user.
     * @param {string} sender - 'user' or 'bot'.
     * @param {object} config - Chatbot config.
     * @param {boolean} isError - If true, adds an error class.
     * @param {boolean} isInitialGreeting - If true, marks as the initial greeting.
     * @param {string|null} messageId - Optional message ID to assign to the element.
     * @param {boolean} forceScroll - Force scroll after adding message.
     * @param {object|null} groundingMetadata - Optional Google Search Grounding metadata.
     * @param {array|null} citations - Optional citation metadata for the message.
     * @param {string|null} feedback - Optional saved feedback state ('up' or 'down').
     */
    function aipkit_chatUI_appendMessage(
      messagesEl,
      content,
      sender,
      config,
      isError = false,
      isInitialGreeting = false,
      messageId = null,
      forceScroll = false,
      groundingMetadata = null,
      citations = null,
      feedback = null
    ) {
      if (!messagesEl || !config) return;

      if (!isInitialGreeting) {
        removeInitialGreetings(messagesEl);
      }

      const validSender = sender === "user" ? "user" : "bot";
      if (typeof window.aipkit_chatUI_createMessageRow !== "function") {
        console.error("AIPKit appendMessage: Message row factory is unavailable.");
        return;
      }
      const { messageEl, bubble } = window.aipkit_chatUI_createMessageRow(
        validSender
      );
      if (isError) messageEl.classList.add("aipkit_chat_message-error");
      if (isInitialGreeting) messageEl.classList.add("aipkit_initial_greeting");
      if (messageId) {
        messageEl.id = messageId;
        messageEl.setAttribute("data-message-id", messageId);
      }

      let rawTextForTTS = "";
      const quotaNoticeData = getQuotaNoticeData(content);

      if (validSender === 'user' && typeof content === 'object' && content !== null && content.user_image) {
          if (content.user_image.base64_data && content.user_image.mime_type) {
              const userImg = document.createElement('img');
              userImg.src = `data:${content.user_image.mime_type};base64,${content.user_image.base64_data}`;
              userImg.alt = content.text || 'User uploaded image';
              userImg.className = 'aipkit_user_sent_image_thumbnail';
              bubble.appendChild(userImg);
          }
          if (content.text && content.text.trim() !== '') {
              const textNode = document.createElement('div');
              textNode.className = 'aipkit_user_message_text_content';
              textNode.textContent = content.text;
              bubble.appendChild(textNode);
              rawTextForTTS = content.text;
          } else if (!content.text && content.user_image) {
               rawTextForTTS = "Image sent by user.";
          }
      }
      else if (validSender === "bot" && quotaNoticeData) {
        rawTextForTTS = appendQuotaNoticeContent(bubble, content, config);
      } else if (
        typeof content === "object" &&
        content?.type === "image" &&
        content.images?.length > 0
      ) {
        const imageData = content.images[0];
        const displayPrompt = String(content.prompt || "Generated image");
        const revisedPrompt =
          typeof imageData.revised_prompt === "string"
            ? imageData.revised_prompt
            : "";
        const appendImageNode = ({ src, href, alt }) => {
          const imageEl = document.createElement("img");
          imageEl.src = src;
          imageEl.alt = alt;
          imageEl.className = "aipkit_bot_generated_image";

          if (href) {
            const linkEl = document.createElement("a");
            linkEl.href = href;
            linkEl.target = "_blank";
            linkEl.rel = "noopener noreferrer";
            linkEl.appendChild(imageEl);
            bubble.appendChild(linkEl);
            return;
          }

          bubble.appendChild(imageEl);
        };

        if (imageData.media_library_url) {
          appendImageNode({
            src: String(imageData.media_library_url),
            href: String(imageData.media_library_url),
            alt: displayPrompt,
          });
        } else if (imageData.url) {
          appendImageNode({
            src: String(imageData.url),
            href: String(imageData.url),
            alt: displayPrompt,
          });
        } else if (imageData.b64_json) {
          appendImageNode({
            src: `data:image/png;base64,${imageData.b64_json}`,
            alt: displayPrompt,
          });
        } else {
          const unavailableText =
            content.content || (config.text?.noImageData || "Image data unavailable.");
          const unavailableNode = document.createElement("p");
          unavailableNode.className = "aipkit_image_unavailable_text";
          unavailableNode.textContent = unavailableText;
          bubble.appendChild(unavailableNode);
        }

        bubble.classList.add("aipkit_chat_bubble--image");
        bubble.title = `Prompt: ${displayPrompt}`;
        rawTextForTTS = `Image generated for prompt: ${displayPrompt}`;

        if (revisedPrompt) {
          const revisedP = document.createElement("p");
          revisedP.className = "aipkit_image_revised_prompt";
          revisedP.textContent = `Revised: ${revisedPrompt}`;
          bubble.appendChild(revisedP);
          rawTextForTTS += `. Revised prompt: ${revisedPrompt}`;
        }
      } else if (typeof content === "string") {
        rawTextForTTS = content;
        if (validSender === "bot" && !isError) {
          renderBotMessageContent(bubble, content, config, citations);
        } else {
          appendPlainTextLines(bubble, content);
        }
      } else {
        console.error(
          "AIPKit appendMessage: Invalid content type provided.",
          content
        );
        bubble.textContent = "[Invalid Content]";
        rawTextForTTS = "Invalid Content";
      }

      bubble.setAttribute("data-raw-text", rawTextForTTS);
      if (validSender === "bot" && !isInitialGreeting && !isError && !quotaNoticeData) {
        messageEl.classList.add("aipkit_message_complete");
        if (typeof window.aipkit_chatUI_createActionsContainerHTML === "function") {
          const actionsHTML = window.aipkit_chatUI_createActionsContainerHTML(
            config,
            feedback
          );
          if (actionsHTML) {
            messageEl.insertAdjacentHTML("beforeend", actionsHTML);
            messageEl.classList.add("aipkit_has_message_actions");
          }
        } else {
          console.error(
            "AIPKit DOM: aipkit_chatUI_createActionsContainerHTML function not found globally."
          );
        }
      }

      if (
        validSender === "bot" &&
        !isError &&
        !quotaNoticeData &&
        typeof window.aipkit_chatUI_upsertMessageMeta === "function"
      ) {
        window.aipkit_chatUI_upsertMessageMeta(
          messageEl,
          {
            groundingMetadata,
            citations,
          },
          config
        );
      }

      appendMessageRow(messagesEl, messageEl);
      if (typeof window.aipkit_chatUI_scrollToBottom === "function") {
        window.aipkit_chatUI_scrollToBottom(messagesEl, forceScroll);
      }
    }

    window.aipkit_chatUI_appendMessage = aipkit_chatUI_appendMessage;
  })();

  (function () {
    "use strict";

    function shouldIgnoreNode(node, indicatorClass) {
      if (!node) {
        return true;
      }

      if (node.nodeType === Node.ELEMENT_NODE) {
        const element = /** @type {HTMLElement} */ (node);
        if (element.classList.contains(indicatorClass)) {
          return true;
        }

        return Boolean(
          element.closest(
            ".aipkit_code_copy_btn, .aipkit_citations_widget, .aipkit_grounding_widget"
          )
        );
      }

      if (node.nodeType === Node.TEXT_NODE) {
        const parentElement = node.parentElement;
        if (!parentElement) {
          return true;
        }

        return Boolean(
          parentElement.closest(
            ".aipkit_code_copy_btn, .aipkit_citations_widget, .aipkit_grounding_widget"
          )
        );
      }

      return true;
    }

    function findLastTextNode(container, indicatorClass) {
      const walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT);
      let lastTextNode = null;
      let currentNode = walker.nextNode();

      while (currentNode) {
        if (
          !shouldIgnoreNode(currentNode, indicatorClass) &&
          typeof currentNode.textContent === "string" &&
          currentNode.textContent.trim() !== ""
        ) {
          lastTextNode = currentNode;
        }
        currentNode = walker.nextNode();
      }

      return lastTextNode;
    }

    function findLastElementNode(container, indicatorClass) {
      const walker = document.createTreeWalker(container, NodeFilter.SHOW_ELEMENT);
      let lastElementNode = null;
      let currentNode = walker.nextNode();

      while (currentNode) {
        if (!shouldIgnoreNode(currentNode, indicatorClass)) {
          lastElementNode = currentNode;
        }
        currentNode = walker.nextNode();
      }

      return lastElementNode;
    }

    /**
     * Positions the streaming indicator at the actual end of rendered content.
     * This avoids stale placements when markdown changes structure mid-stream.
     * @param {HTMLElement} bubble
     * @param {string} indicatorClass
     */
    function positionStreamingIndicator(bubble, indicatorClass) {
      if (!bubble) {
        return;
      }

      let indicator = bubble.querySelector(`.${indicatorClass}`);
      if (!indicator) {
        indicator = document.createElement("span");
        indicator.className = indicatorClass;
      } else if (indicator.parentNode) {
        indicator.parentNode.removeChild(indicator);
      }

      const lastTextNode = findLastTextNode(bubble, indicatorClass);
      if (lastTextNode) {
        const range = document.createRange();
        range.setStart(lastTextNode, lastTextNode.textContent.length);
        range.collapse(true);
        range.insertNode(indicator);
        return;
      }

      const lastElementNode = findLastElementNode(bubble, indicatorClass);
      if (lastElementNode && lastElementNode.parentNode) {
        if (lastElementNode.nextSibling) {
          lastElementNode.parentNode.insertBefore(indicator, lastElementNode.nextSibling);
        } else {
          lastElementNode.parentNode.appendChild(indicator);
        }
        return;
      }

      bubble.appendChild(indicator);
    }

    window.positionStreamingIndicator = positionStreamingIndicator;
  })();

  (function () {
    "use strict";

    function renderStreamedContent(bubble, content, config, citations) {
      const renderer =
        typeof window.aipkit_getMarkdownRenderer === "function"
          ? window.aipkit_getMarkdownRenderer()
          : window.aipkit_md;

      if (renderer && typeof renderer.render === "function") {
        bubble.innerHTML = renderer.render(content);
        applyBotEnhancements(bubble, config, citations);
      } else {
        bubble.innerHTML = String(content || "").replace(/\n/g, "<br>");
      }

      if (
        renderer &&
        renderer.aipkitIsFallback &&
        typeof window.aipkit_ensureMarkdownParser === "function" &&
        bubble.dataset.aipkitMarkdownUpgradePending !== "1"
      ) {
        bubble.dataset.aipkitMarkdownUpgradePending = "1";
        void window.aipkit_ensureMarkdownParser().then(function (upgradedRenderer) {
          delete bubble.dataset.aipkitMarkdownUpgradePending;
          if (
            !bubble.isConnected ||
            !upgradedRenderer ||
            upgradedRenderer.aipkitIsFallback
          ) {
            return;
          }

          const latestContent =
            bubble.dataset.aipkitFullContent ||
            bubble.getAttribute("data-raw-text") ||
            content;
          bubble.innerHTML = upgradedRenderer.render(latestContent);
          applyBotEnhancements(bubble, config, citations);
        });
      }
    }

    /**
     * Appends or updates a message bubble, used for streaming responses.
     * Renders markdown incrementally. Adds/removes pulsing indicator.
     * Assigns the message ID to the element. Adds actions on completion.
     * @param {HTMLElement} messagesEl - The messages container.
     * @param {string|null} messageId - A unique ID for the message being streamed. Should be provided from the start.
     * @param {string} textDelta - The chunk of text to append.
     * @param {string} sender - Usually 'bot'.
     * @param {object} config - Chatbot config.
     * @param {boolean} isComplete - True if this is the final update for the message.
     * @param {boolean} isError - If true, adds an error class.
     * @param {object|null} groundingMetadata - Optional Google Search Grounding metadata (for final update).
     * @param {array|null} citations - Optional citation metadata (for final update).
     */
    function aipkit_chatUI_appendOrUpdateMessage(
      messagesEl,
      messageId,
      textDelta,
      sender,
      config,
      isComplete = false,
      isError = false,
      groundingMetadata = null,
      citations = null
    ) {
      if (!messagesEl || !config || !messageId) {
        console.error(
          "AIPKit appendOrUpdateMessage: Missing messagesEl, config, or messageId.",
          { messagesEl, messageId, config }
        );
        return;
      }

      let messageEl = messagesEl.querySelector(`#${CSS.escape(messageId)}`);
      let bubble;
      let currentFullContent = "";
      const indicatorClass = "aipkit_stream_indicator";
      const validSender = sender === "user" ? "user" : "bot";

      if (!messageEl) {
        removeInitialGreetings(messagesEl);

        if (typeof window.aipkit_chatUI_createMessageRow !== "function") {
          console.error("AIPKit appendOrUpdateMessage: Message row factory is unavailable.");
          return;
        }
        const row = window.aipkit_chatUI_createMessageRow(validSender);
        messageEl = row.messageEl;
        bubble = row.bubble;
        messageEl.id = messageId;
        messageEl.setAttribute("data-message-id", messageId);
        if (isError) messageEl.classList.add("aipkit_chat_message-error");
        bubble.dataset.aipkitFullContent = "";
        bubble.setAttribute("data-raw-text", "");

        appendMessageRow(messagesEl, messageEl);
      } else {
        bubble = messageEl.querySelector(".aipkit_chat_bubble");
        if (!bubble) return;
        if (
          isError &&
          !messageEl.classList.contains("aipkit_chat_message-error")
        ) {
          messageEl.classList.add("aipkit_chat_message-error");
        }
      }

      if (bubble.dataset.aipkitFullContent === undefined)
        bubble.dataset.aipkitFullContent = "";
      if (bubble.getAttribute("data-raw-text") === null)
        bubble.setAttribute("data-raw-text", "");
      bubble.dataset.aipkitFullContent += textDelta;
      bubble.setAttribute(
        "data-raw-text",
        bubble.getAttribute("data-raw-text") + textDelta
      );
      currentFullContent = bubble.dataset.aipkitFullContent;

      if (isError) {
        bubble.textContent = currentFullContent;
      } else {
        renderStreamedContent(bubble, currentFullContent, config, citations);
      }

      messageEl.classList.remove("aipkit_message_streaming");
      if (!isComplete && !isError) {
          if (typeof window.positionStreamingIndicator === 'function') {
              window.positionStreamingIndicator(bubble, indicatorClass);
          } else {
              console.error("AIPKit appendOrUpdateMessage: positionStreamingIndicator function not found.");
              let indicator = bubble.querySelector(`.${indicatorClass}`);
              if (!indicator) {
                  indicator = document.createElement("span");
                  indicator.className = indicatorClass;
                  bubble.appendChild(indicator);
              }
          }
        messageEl.classList.add("aipkit_message_streaming");
      } else {
        const existingIndicator = bubble.querySelector(`.${indicatorClass}`);
        if (existingIndicator && existingIndicator.parentNode)
          existingIndicator.parentNode.removeChild(existingIndicator);

        if (typeof window.aipkit_chatUI_upsertMessageMeta === "function") {
          window.aipkit_chatUI_upsertMessageMeta(
            messageEl,
            {
              statusText: null,
              groundingMetadata: isComplete && !isError ? groundingMetadata : null,
              citations: isComplete && !isError ? citations : null,
            },
            config
          );
        }

        if (
          isComplete &&
          !isError &&
          validSender === "bot" &&
          !messageEl.querySelector(".aipkit_message_actions")
        ) {
          messageEl.classList.remove("aipkit_message_streaming");
          messageEl.classList.add("aipkit_message_complete");
          if (typeof window.aipkit_chatUI_createActionsContainerHTML === "function") {
            const actionsHTML = window.aipkit_chatUI_createActionsContainerHTML(config);
            if (actionsHTML) {
              messageEl.insertAdjacentHTML("beforeend", actionsHTML);
              messageEl.classList.add("aipkit_has_message_actions");
            }
          } else {
            console.error(
              "AIPKit DOM: aipkit_chatUI_createActionsContainerHTML function not found globally."
            );
          }
          if (typeof window.aipkit_chatUI_upsertMessageMeta === "function") {
            window.aipkit_chatUI_upsertMessageMeta(
              messageEl,
              {
                groundingMetadata,
                citations,
              },
              config
            );
          }
        }
      }

      if (typeof window.aipkit_chatUI_scrollToBottom === "function") {
        window.aipkit_chatUI_scrollToBottom(messagesEl, isComplete || isError);
      }
    }

    window.aipkit_chatUI_appendOrUpdateMessage =
      aipkit_chatUI_appendOrUpdateMessage;
  })();
})();
