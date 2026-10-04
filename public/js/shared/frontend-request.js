/** Shared frontend AJAX requests with conversation and provider context. */
(function () {
  "use strict";

  const audioPending = new Map();
  const freshNonces = new Map();
  const audioKey = (action, config) => `aipkit_audio:${config.ajaxUrl}:${config.botId}:${action}`;
  function pendingAudio(key, value) {
    if (value !== undefined) {
      if (value) audioPending.set(key, value); else audioPending.delete(key);
      try { if (value) window.sessionStorage?.setItem(key, value); else window.sessionStorage?.removeItem(key); } catch {}
    }
    try { return audioPending.get(key) || window.sessionStorage?.getItem(key) || ''; } catch { return audioPending.get(key) || ''; }
  }
  function uncertainAudio(key, id, config) {
    const error = new Error(config.text?.audioOutcomeUnknown || 'The audio response was interrupted. Check its status before starting another request.');
    error.code = 'cloud_outcome_unknown';
    error.operationId = id;
    error.allowNewRequest = () => { if (pendingAudio(key) === id) pendingAudio(key, ''); };
    error.checkStatus = async () => {
      const result = await aipkit_frontendApiRequest('aipkit_speech_request_status', { operation_id: id }, config);
      if (['settled', 'released'].includes(result.state)) error.allowNewRequest();
      return result.state === 'settled'
        ? config.text?.audioSettled || 'This request used credits, but its audio or transcript could not be recovered. Starting again uses credits for a new request.'
        : result.state === 'released'
          ? config.text?.audioReleased || 'This request did not use credits. You can try again.'
          : config.text?.audioPending || 'This request is still processing. Check again later before starting another request.';
    };
    return error;
  }

  /**
   * Generic helper for making AJAX requests from the frontend chat UI.
   * @param {string} action - The AJAX action name.
   * @param {object} [data={}] - Data to send.
   * @param {object} [config={}] - Configuration object.
   *                               Expected: ajaxUrl, nonce, botId.
   *                               Optional: activeFileContext (OpenAI, Pinecone, Qdrant, or Claude file context).
   */
  function aipkit_frontendApiRequest(action, data = {}, config = {}, requestOptions = {}) {
    return new Promise((resolve, reject) => {
      if (!config.ajaxUrl)
        return reject(
          new Error("AIPKit Frontend AJAX: ajaxUrl missing in config.")
        );
      const nonceKey = `${config.ajaxUrl}:${config.botId}`;
      if (freshNonces.has(nonceKey)) config.nonce = freshNonces.get(nonceKey);
      const cloudAudio = (action === 'aipkit_generate_speech' && config.ttsProvider === 'AIPufferCloud')
        || (action === 'aipkit_transcribe_audio' && config.sttProvider === 'AIPufferCloud');
      const key = audioKey(action, config);
      const pending = cloudAudio && pendingAudio(key);
      if (pending) return reject(uncertainAudio(key, pending, config));
      if (cloudAudio) {
        data = { ...data, operation_id: window.crypto.randomUUID ? window.crypto.randomUUID()
          : '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, digit =>
            (digit ^ (window.crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (digit / 4)))).toString(16)) };
        pendingAudio(key, data.operation_id);
      }
      const formData = new FormData();
      formData.append("action", action);
      if (config.nonce) formData.append("_ajax_nonce", config.nonce);
      else
        console.warn(
          `AIPKit Frontend AJAX: Nonce not found in config for action "${action}".`
        );
      if (config.botId) formData.append("bot_id", config.botId);
      if (window.aipkit_guest_uuid)
        formData.append("session_id", window.aipkit_guest_uuid);
      if (window.aipkit_current_conversation_uuid)
        formData.append(
          "conversation_uuid",
          window.aipkit_current_conversation_uuid
        );
      if (window.aipkit_current_post_id) {
        formData.append("post_id", window.aipkit_current_post_id);
      }
      if (
        config.provider === "OpenAI" &&
        config.enableOpenAIConversationState &&
        window.aipkit_current_openai_response_id
      ) {
        formData.append(
          "previous_openai_response_id",
          window.aipkit_current_openai_response_id
        );
      }
      if (
        config.provider === "Google" &&
        config.enableGoogleConversationState &&
        window.aipkit_current_google_interaction_id
      ) {
        formData.append(
          "previous_google_interaction_id",
          window.aipkit_current_google_interaction_id
        );
      }
      if (data.frontend_web_search_active === true) {
        formData.append("frontend_web_search_active", "true");
      }
      if (data.frontend_google_search_grounding_active === true) {
        formData.append("frontend_google_search_grounding_active", "true");
      }

      if (config.activeFileContext) {
        if (config.activeFileContext.provider !== "Google" && config.activeFileContext.context_token) {
          formData.append("active_file_context_token", config.activeFileContext.context_token);
        }

        if (
          config.activeFileContext.provider === "OpenAI" &&
          config.activeFileContext.vector_store_id
        ) {
          formData.append(
            "active_openai_vs_id",
            config.activeFileContext.vector_store_id
          );
        }
        if (
          config.activeFileContext.provider === "Pinecone" &&
          config.activeFileContext.index_name &&
          config.activeFileContext.namespace
        ) {
          formData.append(
            "active_pinecone_index_name",
            config.activeFileContext.index_name
          );
          formData.append(
            "active_pinecone_namespace",
            config.activeFileContext.namespace
          );
        }
        if (
          config.activeFileContext.provider === "Qdrant" &&
          config.activeFileContext.collection_name &&
          config.activeFileContext.file_upload_context_id
        ) {
          formData.append(
            "active_qdrant_collection_name",
            config.activeFileContext.collection_name
          );
          formData.append(
            "active_qdrant_file_upload_context_id",
            config.activeFileContext.file_upload_context_id
          );
        }
        if (
          config.activeFileContext.provider === "Chroma" &&
          config.activeFileContext.collection_name &&
          config.activeFileContext.file_upload_context_id
        ) {
          formData.append(
            "active_chroma_collection_name",
            config.activeFileContext.collection_name
          );
          formData.append(
            "active_chroma_file_upload_context_id",
            config.activeFileContext.file_upload_context_id
          );
        }
        if (
          config.activeFileContext.provider === "Claude" &&
          config.activeFileContext.file_id
        ) {
          formData.append(
            "active_claude_file_id",
            config.activeFileContext.file_id
          );
        }
      }
      for (const key in data)
        if (
          Object.prototype.hasOwnProperty.call(data, key) &&
          key !== "frontend_web_search_active" &&
          key !== "frontend_google_search_grounding_active" &&
          key !== "active_openai_vs_id" &&
          key !== "active_pinecone_index_name" &&
          key !== "active_pinecone_namespace" &&
          key !== "active_qdrant_collection_name" &&
          key !== "active_qdrant_file_upload_context_id" &&
          key !== "active_chroma_collection_name" &&
          key !== "active_chroma_file_upload_context_id" &&
          key !== "active_claude_file_id"
        ) {
          // Support Blob/File for audio upload (avoid base64) - do not stringify
          if (key === 'audio_file' && data[key] instanceof Blob) {
            formData.append(key, data[key], 'speech.' + (data.audio_format || 'webm'));
          } else {
            formData.append(key, data[key]);
          }
        }

      const send = async (refreshed = false) => {
        const response = await fetch(config.ajaxUrl, {
          method: "POST", body: formData, credentials: "same-origin",
          ...(requestOptions.keepalive ? { keepalive: true } : {}),
          ...(requestOptions.signal ? { signal: requestOptions.signal } : {}),
        });
        const json = await response.json();
        // Only retry an explicit pre-dispatch nonce refusal, and only once.
        if (!json.success && response.status === 403 && json.data?.code === 'nonce_failure'
            && !refreshed && ['aipkit_generate_speech', 'aipkit_transcribe_audio', 'aipkit_speech_request_status'].includes(action)) {
          const nonceData = new FormData();
          nonceData.append('action', window.aipkit_getChatNonceAction || 'aipkit_get_frontend_chat_nonce');
          nonceData.append('bot_id', config.botId);
          const nonceResponse = await fetch(config.ajaxUrl, { method: 'POST', body: nonceData, credentials: 'same-origin', ...(requestOptions.signal ? { signal: requestOptions.signal } : {}) });
          const nonceJson = await nonceResponse.json();
          if (nonceJson.success && typeof nonceJson.data?.nonce === 'string' && nonceJson.data.nonce) {
            config.nonce = nonceJson.data.nonce;
            freshNonces.set(nonceKey, config.nonce);
            formData.set('_ajax_nonce', config.nonce);
            return send(true);
          }
        }
        if (!json.success) {
          const error = new Error(json.data?.message || 'The request could not be completed.');
          error.code = json.data?.code || '';
          error.status = response.status;
          error.operationId = json.data?.operation_id;
          throw error;
        }
        if (cloudAudio && (action === 'aipkit_generate_speech'
            ? !json.data?.audio_data_base64 || !json.data?.mime_type
            : typeof json.data?.transcription !== 'string')) {
          throw new Error('Incomplete audio response');
        }
        return json.data;
      };
      send().then(result => {
        if (cloudAudio) pendingAudio(key, '');
        resolve(result);
      }).catch(error => {
        if (cloudAudio) {
          const uncertain = !error.status || ['cloud_outcome_unknown', 'already_dispatched_or_final', 'idempotency_conflict',
            'gateway_aborted', 'gateway_response_unverified', 'cloud_request_failed', 'cloud_speech_invalid_response', 'cloud_transcript_invalid_response'].includes(error.code);
          if (uncertain) error = uncertainAudio(key, data.operation_id, config);
          else pendingAudio(key, '');
        }
        reject(error);
      });
    });
  }

  window.aipkit_frontendApiRequest = aipkit_frontendApiRequest;
})();
