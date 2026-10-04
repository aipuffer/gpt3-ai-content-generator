(function () {
  "use strict";

  async function aipkit_toggleGeneratedMediaFavorite(
    generatorWrapper,
    attachmentId,
    favorite
  ) {
    const config = window.aipkit_image_generator_config_public || {};
    const ajaxUrl = config.ajaxUrl || window.aipkit_dashboard?.ajaxurl;
    const nonce = generatorWrapper?.querySelector(
      "#aipkit_image_generator_public_nonce"
    )?.value;
    const normalizedAttachmentId = Number(attachmentId || 0);

    if (
      !generatorWrapper ||
      generatorWrapper.dataset.userLoggedIn !== "1" ||
      !ajaxUrl ||
      !nonce ||
      normalizedAttachmentId <= 0
    ) {
      throw new Error(
        config.text?.favoriteFailed || "Could not update favorite."
      );
    }

    const request = new FormData();
    request.append("action", "aipkit_toggle_generated_media_favorite");
    request.append("_ajax_nonce", nonce);
    request.append("attachment_id", String(normalizedAttachmentId));
    request.append("favorite", favorite ? "1" : "0");

    const response = await fetch(ajaxUrl, {
      method: "POST",
      body: request,
      credentials: "same-origin",
    });
    const json = await response.json();
    if (!response.ok || !json.success) {
      throw new Error(
        json.data?.message ||
          config.text?.favoriteFailed ||
          "Could not update favorite."
      );
    }

    return {
      attachmentId: Number(json.data?.attachment_id || normalizedAttachmentId),
      favorite: Boolean(json.data?.favorite),
    };
  }

  window.aipkit_toggleGeneratedMediaFavorite =
    aipkit_toggleGeneratedMediaFavorite;
})();
