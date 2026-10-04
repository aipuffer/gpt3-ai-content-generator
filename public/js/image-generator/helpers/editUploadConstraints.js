(function () {
  "use strict";

  function getImageEditUploadConstraints(config, provider = "", model = "") {
    const normalizedProvider = String(provider).trim().toLowerCase();
    let types = ["image/jpeg", "image/png", "image/webp", "image/gif"];
    let maxBytes = 10 * 1024 * 1024;
    if (normalizedProvider === "openai") types = types.filter((type) => type !== "image/gif");
    if (normalizedProvider === "xai") types = ["image/jpeg", "image/png"];
    if (normalizedProvider === "aipuffercloud") {
      const models = (config?.cloud_image_models || []).filter((entry) => String(entry.id || "").startsWith("aipuffer/image_edit/"));
      const selected = models.find((entry) => entry.id === model) ||
        (!String(model).startsWith("aipuffer/image_edit/") ? models[0] : null);
      const formats = selected?.capabilities?.inputFormats || [];
      types = ["image/jpeg", "image/png", "image/webp"].filter((type) => formats.includes(type.slice(6)));
      maxBytes = Math.max(0, Math.min(maxBytes, Number(selected?.capabilities?.maxInputBytes) || 0));
    }
    const formatNames = types.map((type) => type.slice(6).replace("jpeg", "jpg").toUpperCase()).join(", ");
    const size = `${(maxBytes / 1024 / 1024).toFixed(2)} MB`;
    return {
      allowedMimeTypes: new Set(types),
      accept: types.join(","),
      maxBytes,
      invalidTypeMessage: (config?.text?.editUploadTypes || "Invalid image type. Allowed types: %s.").replace("%s", formatNames),
      tooLargeMessage: (config?.text?.editUploadSize || "Source image is too large. Maximum allowed size is %s.").replace("%s", size),
    };
  }

  window.aipkit_getImageEditUploadConstraints = getImageEditUploadConstraints;
})();
