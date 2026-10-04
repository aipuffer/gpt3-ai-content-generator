(function () {
  "use strict";

  function normalizeMessage(value) {
    return typeof value === "string" ? value.trim() : "";
  }

  function getImageGenerationErrorMessage(payload, fallback = "") {
    const data = payload && typeof payload === "object" ? payload.data : null;
    const error = payload && typeof payload === "object" ? payload.error : null;
    const candidates = [
      data && typeof data === "object" ? data.message : "",
      error && typeof error === "object" ? error.message : "",
      payload && typeof payload === "object" ? payload.message : "",
      typeof data === "string" ? data : "",
      typeof payload === "string" ? payload : "",
    ];
    const message = candidates.map(normalizeMessage).find(Boolean);
    return message || normalizeMessage(fallback) || "Error generating image.";
  }

  function createImageGenerationError(payload, fallback = "") {
    const message = getImageGenerationErrorMessage(payload, fallback);
    const error = new Error(message);
    error.aipkitDisplayMessage = message;
    error.aipkitCode = String(payload?.data?.code || "request_rejected");
    return error;
  }

  window.aipkit_createImageGenerationError = createImageGenerationError;
})();
