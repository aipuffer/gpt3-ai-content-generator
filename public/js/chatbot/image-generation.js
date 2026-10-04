/** Optional chatbot image-command requests; submission owns controls and cancellation. */
(function () {
  'use strict';

  async function aipkit_chatUI_handleImageGeneration(imagePrompt, originalUserText, userMessageId, wasNewConversation, context) {
    const { elements, config, requestOptions } = context;
    const { messagesEl, container } = elements;
    const { signal, isCurrent, conversationUUID } = requestOptions;
    if (!isCurrent()) return;

    const indicator = typeof window.aipkit_chatUI_showImageLoadingIndicator === 'function'
      ? window.aipkit_chatUI_showImageLoadingIndicator(messagesEl, config)
      : window.aipkit_chatUI_showTypingIndicator?.(messagesEl, config);
    // Remove only this request's node, even when a newer request replaced the loader.
    const removeIndicator = () => indicator?.remove();
    signal.addEventListener('abort', removeIndicator, { once: true });
    try {
      if (!isCurrent()) return;
      if (typeof window.aipkit_frontendApiRequest !== 'function') {
        throw new Error('Internal script error (image gen).');
      }
      const data = await window.aipkit_frontendApiRequest('aipkit_chat_generate_image', {
        prompt: imagePrompt,
        original_user_text: originalUserText,
        user_message_id: userMessageId,
      }, config, { signal });
      if (!isCurrent()) return;
      if (data?.type !== 'image' || !data.images?.length) {
        throw new Error(data?.message || 'Invalid response for image generation (no images).');
      }
      removeIndicator();
      window.aipkit_chatUI_appendMessage(messagesEl, {
        type: 'image', images: data.images, prompt: imagePrompt,
      }, 'bot', config, false, false, data.message_id, true);
      if (!isCurrent()) return;
      container.dispatchEvent(new CustomEvent('aipkit:messageReceived', {
        detail: { messageId: data.message_id, conversationUUID, newConversation: wasNewConversation },
      }));
    } finally {
      signal.removeEventListener('abort', removeIndicator);
      removeIndicator();
    }
  }

  window.aipkit_chatUI_handleImageGeneration = aipkit_chatUI_handleImageGeneration;
})();
