/**
 * AIPKit Content Writer Stream - EventSource Connection Setup
 * Handles creating the EventSource object with the correct URL.
 */
(function () {
  "use strict";

  function aipkit_stream_setupEventSourceConnection(cacheKey, esInstanceRef) {
    const frontendStreamNonceInput = document.getElementById(
      "aipkit_content_writer_frontend_stream_nonce"
    );
    if (!frontendStreamNonceInput || !frontendStreamNonceInput.value) {
      throw new Error("Security token missing for stream. Please reload.");
    }

    const streamUrl = new URL(window.aipkit_dashboard.ajaxurl);
    streamUrl.searchParams.append("action", "aipkit_frontend_chat_stream");
    streamUrl.searchParams.append("cache_key", cacheKey);
    streamUrl.searchParams.append(
      "_ajax_nonce",
      frontendStreamNonceInput.value
    );

    if (esInstanceRef.current) {
      esInstanceRef.current.close();
    }

    try {
      const newEventSource = new EventSource(streamUrl.toString());
      newEventSource.userStopped = false; // Initialize flag
      return newEventSource;
    } catch (e) {
      console.error(
        "EventSource Connection Setup: EventSource creation error:",
        e
      );
      throw new Error("Could not connect to stream. " + e.message);
    }
  }

  window.aipkit_stream_setupEventSourceConnection =
    aipkit_stream_setupEventSourceConnection;
})();