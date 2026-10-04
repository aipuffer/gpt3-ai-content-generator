/**
 * AIPKit Public Chat - File Context State
 */
(function () {
  "use strict";

  const PROVIDER_KEY = "aipkit_active_file_context_provider";
  const DATA_KEY = "aipkit_active_file_context_data";
  const validateCollectionContext = (data) =>
    data.collection_name && data.file_upload_context_id;

  const validators = {
    OpenAI: (data) => data.vector_store_id,
    Pinecone: (data) => data.index_name && data.namespace,
    Qdrant: validateCollectionContext,
    Chroma: validateCollectionContext,
    Local: (data) => validateCollectionContext(data) && data.context_token,
    Claude: (data) => data.file_id,
    Google: (data) =>
      data.context_token &&
      Number.isFinite(Number(data.expires_at)) &&
      Number(data.expires_at) * 1000 > Date.now(),
  };

  function clearFileContext() {
    window[PROVIDER_KEY] = null;
    window[DATA_KEY] = null;
    sessionStorage.removeItem(PROVIDER_KEY);
    sessionStorage.removeItem(DATA_KEY);
    document.dispatchEvent(new CustomEvent("aipkit:fileContextCleared"));
  }

  function setFileContext(fileContextData) {
    window[PROVIDER_KEY] = fileContextData.provider;
    window[DATA_KEY] = fileContextData;
    sessionStorage.setItem(
      PROVIDER_KEY,
      window[PROVIDER_KEY]
    );
    sessionStorage.setItem(
      DATA_KEY,
      JSON.stringify(window[DATA_KEY])
    );
  }

  function removeStoredFileContext(conversationUUID) {
    if (!conversationUUID) {
      return;
    }
    try {
      localStorage.removeItem(`aipkit_file_context_${conversationUUID}`);
    } catch (error) {
      console.error("AIPKit Chat: Could not remove stored file context:", error);
    }
  }

  function isValidFileContext(fileContextData) {
    if (!fileContextData || !fileContextData.provider) {
      return false;
    }
    const validator = validators[fileContextData.provider];
    return typeof validator === "function" && !!validator(fileContextData);
  }

  function restoreFileContextForConversation(conversationUUID, options = {}) {
    const invalidLogPrefix = options.invalidLogPrefix || "AIPKit Chat";
    const errorMessage =
      options.errorMessage ||
      "AIPKit Chat: Error loading file context from localStorage:";

    try {
      const storedFileContextJson = localStorage.getItem(
        `aipkit_file_context_${conversationUUID}`
      );
      if (!storedFileContextJson) {
        clearFileContext();
        return false;
      }

      const fileContextData = JSON.parse(storedFileContextJson);
      if (!isValidFileContext(fileContextData)) {
        console.warn(
          `${invalidLogPrefix}: Invalid file context data in localStorage for conv ${conversationUUID}. Clearing. Data:`,
          fileContextData
        );
        localStorage.removeItem(`aipkit_file_context_${conversationUUID}`);
        clearFileContext();
        return false;
      }

      setFileContext(fileContextData);
      document.dispatchEvent(
        new CustomEvent("aipkit:fileContextRestored", {
          detail: fileContextData,
        })
      );
      return true;
    } catch (e) {
      console.error(errorMessage, e);
      clearFileContext();
      return false;
    }
  }

  window.aipkit_chatUI_clearFileContext = clearFileContext;
  window.aipkit_chatUI_setFileContext = setFileContext;
  window.aipkit_chatUI_removeStoredFileContext = removeStoredFileContext;
  window.aipkit_chatUI_isValidFileContext = isValidFileContext;
  window.aipkit_chatUI_restoreFileContextForConversation =
    restoreFileContextForConversation;
})();
