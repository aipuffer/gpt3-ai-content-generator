/**
 * AIPKit Chatbot - Client Message IDs
 */
(function() {
    'use strict';

    /**
     * Generates a unique client-side ID for messages.
     * @param {string|number} botId The ID of the bot this message belongs to.
     * @returns {string} A unique message ID.
     */
    function aipkit_generateClientMessageId(botId) {
        const safeBotId = String(botId || 'default').replace(/[^a-zA-Z0-9_-]/g, '');
        return `aipkit-client-msg-${safeBotId}-${Date.now()}-${Math.random()
          .toString(36)
          .substring(2, 7)}`;
    }

    window.aipkit_generateClientMessageId = aipkit_generateClientMessageId;

})();
