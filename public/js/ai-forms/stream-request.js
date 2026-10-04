/** AI Forms cache submission and EventSource setup. */
(function () {
  "use strict";

  /**
   * Caches the AI Form message for SSE processing.
   * @param {string} formId The ID of the form.
   * @param {object} formInputValues Key-value pairs of user inputs.
   * @param {Array<object>} imageInputs Optional image inputs for vision-capable providers.
   * @param {string} nonce The nonce for the AJAX call (specifically 'aipkit_frontend_chat_nonce').
   * @param {object} options Submission cancellation signal and rendered selection policy.
   * @returns {Promise<string>} A promise that resolves with the cache_key.
   */
  async function aipkit_cacheAIFormMessage(
    formId,
    formInputValues,
    imageInputs,
    nonce,
    options = {}
  ) {
    const __ =
      window.wp && window.wp.i18n && window.wp.i18n.__
        ? window.wp.i18n.__
        : (text) => text;
    const formData = new FormData();
    formData.append("action", "aipkit_cache_sse_message");
    formData.append("_ajax_nonce", nonce); // Use the passed nonce

    const dataToCache = {
      stream_context: "ai_forms",
      form_id: formId,
      user_input_values: formInputValues,
      selection_policy: options.selectionPolicy || "",
    };
    formData.append("message", JSON.stringify(dataToCache));
    if (Array.isArray(imageInputs) && imageInputs.length > 0) {
      formData.append("image_inputs", JSON.stringify(imageInputs));
    }

    let response;
    for (let attempt = 0; attempt < 2; attempt++) {
      if (options.signal?.aborted) throw new DOMException("Request cancelled", "AbortError");
      response = await fetch(aipkit_ai_forms_public_config.ajaxUrl, {
        method: "POST",
        body: formData,
        credentials: "same-origin",
        signal: options.signal,
      });
      if (response.ok) break;

      const errData = await response.json().catch(() => ({}));
      const message = errData?.data?.message || `HTTP error ${response.status}`;
      if (attempt !== 0 || response.status !== 403 || errData?.data?.code !== "nonce_failure_cache_sse") {
        throw new Error(message);
      }

      // Retry only a nonce rejection, before any generation has started.
      const refreshData = new FormData();
      refreshData.append("action", "aipkit_get_frontend_chat_nonce");
      const refreshed = await fetch(aipkit_ai_forms_public_config.ajaxUrl, {
        method: "POST",
        body: refreshData,
        credentials: "same-origin",
        signal: options.signal,
        cache: "no-store",
      });
      const freshData = await refreshed.json().catch(() => ({}));
      if (!refreshed.ok || !freshData.success || typeof freshData.data?.nonce !== "string" || !freshData.data.nonce.trim()) {
        throw new Error(message);
      }
      aipkit_ai_forms_public_config.ajaxNonce = freshData.data.nonce;
      formData.set("_ajax_nonce", freshData.data.nonce);
    }

    const data = await response.json();
    if (data.success && data.data?.cache_key) {
      return data.data.cache_key;
    } else {
      throw new Error(
        data.data?.message ||
          __(
            "Failed to cache form data for streaming.",
            "gpt3-ai-content-generator"
          )
      );
    }
  }

  // Expose globally
  window.aipkit_cacheAIFormMessage = aipkit_cacheAIFormMessage;

  /**
   * Caches data, creates an EventSource, and attaches handlers.
   * @param {string} formId The ID of the form.
   * @param {object} userInputs The user's input data.
   * @param {Array<object>} imageInputs Optional image inputs for vision-capable providers.
   * @param {string} sseNonce The nonce for the AJAX call.
   * @param {HTMLElement} resultsDiv The div for rendering results.
   * @param {object} request The owning submission and cancellation state.
   * @returns {Promise<void>}
   */
  async function aipkitForms_setupAndStartEventSource(
    formId,
    userInputs,
    imageInputs,
    sseNonce,
    resultsDiv,
    request
  ) {

    const cacheKey = await window.aipkit_cacheAIFormMessage(
      formId,
      userInputs,
      imageInputs,
      sseNonce,
      request
    );
    if (!request.isActive()) return;

    const streamUrl = new URL(aipkit_ai_forms_public_config.ajaxUrl);
    streamUrl.searchParams.append("action", "aipkit_frontend_chat_stream");
    streamUrl.searchParams.append("cache_key", cacheKey);
    streamUrl.searchParams.append("_ajax_nonce", aipkit_ai_forms_public_config.ajaxNonce || sseNonce);
    streamUrl.searchParams.append("_ts", Date.now().toString());

    // This ensures token management works correctly for guest users.
    if (window.aipkit_guest_uuid) {
      streamUrl.searchParams.append("session_id", window.aipkit_guest_uuid);
    }

    const source = new EventSource(streamUrl.toString());
    request.source = source;

    const formWrapper = resultsDiv.closest(".aipkit-ai-form-wrapper");
    const providerSelect = formWrapper
      ? formWrapper.querySelector(".aipkit_aiform_provider_select")
      : null;
    const effectiveProvider =
      userInputs.ai_provider ||
      (providerSelect ? providerSelect.value : "") ||
      (formWrapper ? formWrapper.dataset.aiProvider : "") ||
      "";

    const {
      onMessageHandler,
      onDoneHandler,
      onErrorHandler,
      onGroundingMetadataHandler,
      onCitationsHandler,
    } =
      window.aipkitForms_createSseEventHandlers(
        resultsDiv,
        request,
        {
          formId,
          userInputs,
          imageInputs,
          provider: effectiveProvider,
        }
      );

    source.onmessage = onMessageHandler;
    source.addEventListener(
      "grounding_metadata",
      onGroundingMetadataHandler
    );
    source.addEventListener(
      "citations",
      onCitationsHandler
    );
    source.addEventListener("done", onDoneHandler);
    source.onerror = onErrorHandler;
  }

  window.aipkitForms_setupAndStartEventSource =
    aipkitForms_setupAndStartEventSource;
})();
