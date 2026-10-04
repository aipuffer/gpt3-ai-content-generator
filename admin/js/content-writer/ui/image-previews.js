/**
 * AIPKit Content Writer - Image Preview Renderer
 * Keeps document images in the article preview and image controls in the Media inspector.
 */
(function () {
  "use strict";

  const __ =
    window.wp && window.wp.i18n && typeof window.wp.i18n.__ === "function"
      ? window.wp.i18n.__
      : (value) => value;

  const getImageUrl = (imageItem) => {
    if (!imageItem || typeof imageItem !== "object") return "";
    return (
      imageItem.media_library_url ||
      imageItem.url ||
      imageItem.src ||
      imageItem.image_url ||
      imageItem.preview_url ||
      ""
    );
  };

  const getImageAlt = (imageItem, fallback = "") => {
    if (!imageItem || typeof imageItem !== "object") return fallback;
    return (
      imageItem.alt ||
      imageItem.alt_text ||
      imageItem.generated_alt_text ||
      imageItem.image_alt ||
      imageItem.caption ||
      fallback
    );
  };

  const escapeAttr = (value) => {
    if (value === undefined || value === null) return "";
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/"/g, "&quot;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;");
  };

  const parseImageData = (value) => {
    if (value && typeof value === "object") {
      return value;
    }
    if (typeof value !== "string" || !value.trim()) {
      return {};
    }
    try {
      const parsed = JSON.parse(value);
      return parsed && typeof parsed === "object" ? parsed : {};
    } catch (error) {
      return {};
    }
  };

  const getImageDataHolder = () =>
    document.getElementById("aipkit_cw_image_data_holder");

  const getCurrentImageData = () =>
    parseImageData(getImageDataHolder()?.value || "");

  const writeImageData = (imageData, shouldRender = true) => {
    const holder = getImageDataHolder();
    if (holder) {
      holder.value = JSON.stringify(imageData || {});
    }
    if (shouldRender) {
      renderPreview(imageData || {});
    }
  };

  const getRawContentHtml = () => {
    if (typeof window.aipkit_getContentWriterRawHtml === "function") {
      return window.aipkit_getContentWriterRawHtml() || "";
    }
    const contentArea = document.getElementById(
      "aipkit_cw_generated_content_area"
    );
    return contentArea ? contentArea.innerHTML : "";
  };

  const restoreContentFromRaw = () => {
    const contentArea = document.getElementById(
      "aipkit_cw_generated_content_area"
    );
    if (!contentArea) return;
    const rawHtml = getRawContentHtml();
    if (rawHtml) {
      contentArea.innerHTML = rawHtml;
    }
  };

  const getRefs = () => ({
    mediaPanel: document.getElementById("aipkit_cw_output_media_panel"),
    featuredWrap: document.getElementById("aipkit_cw_featured_image"),
    featuredImg: document.getElementById("aipkit_cw_featured_image_img"),
    featuredLoading: document.getElementById("aipkit_cw_featured_image_loading"),
    featuredAlt: document.getElementById("aipkit_cw_featured_alt_input"),
    featuredRegenerate: document.getElementById(
      "aipkit_cw_featured_regenerate_btn"
    ),
    featuredReplace: document.getElementById(
      "aipkit_cw_featured_replace_btn"
    ),
    contentSection: document.getElementById(
      "aipkit_cw_content_images_section"
    ),
    contentCount: document.getElementById("aipkit_cw_content_images_count"),
    inlineGrid: document.getElementById("aipkit_cw_inline_images_grid"),
    articleImageCount: document.getElementById(
      "aipkit_cw_article_image_count"
    ),
    contentArea: document.getElementById("aipkit_cw_generated_content_area"),
  });

  const setVisibility = (element, isVisible, displayValue = "") => {
    if (!element) return;
    element.hidden = !isVisible;
    element.style.display = isVisible ? displayValue : "none";
  };

  const showActionError = (message, title) => {
    if (typeof window.aipkit_showAlertModal === "function") {
      window.aipkit_showAlertModal(message, { title });
      return;
    }
    console.error(`${title}: ${message}`);
  };

  const updateImageCount = (refs, featuredCount, inlineCount) => {
    const total = featuredCount + inlineCount;
    if (refs.articleImageCount) {
      refs.articleImageCount.textContent =
        total === 1
          ? __("1 image", "gpt3-ai-content-generator")
          : `${total} ${__("images", "gpt3-ai-content-generator")}`;
      setVisibility(refs.articleImageCount, total > 0, "inline-flex");
    }
    if (refs.contentCount) {
      refs.contentCount.textContent = String(inlineCount);
    }
  };

  const clearInlineGrid = (inlineGrid) => {
    if (inlineGrid) {
      inlineGrid.innerHTML = "";
    }
  };

  const hideOutputMediaSkeleton = () => {
    if (
      typeof window.aipkit_hideContentWriterMediaSkeleton === "function"
    ) {
      window.aipkit_hideContentWriterMediaSkeleton();
      return;
    }

    const skeleton = document.querySelector(
      "[data-aipkit-cw-media-skeleton]"
    );
    if (skeleton) {
      skeleton.hidden = true;
    }
  };

  const clearPreview = () => {
    const refs = getRefs();
    hideOutputMediaSkeleton();
    refs.featuredWrap?.classList.remove("is-loading");
    if (refs.featuredImg) {
      refs.featuredImg.removeAttribute("src");
      refs.featuredImg.hidden = true;
    }
    if (refs.featuredLoading) {
      refs.featuredLoading.hidden = true;
    }
    if (refs.featuredAlt) {
      refs.featuredAlt.value = "";
    }
    setVisibility(refs.featuredWrap, false, "flex");
    setVisibility(refs.contentSection, false, "block");
    setVisibility(refs.mediaPanel, false, "block");
    clearInlineGrid(refs.inlineGrid);
    updateImageCount(refs, 0, 0);
    restoreContentFromRaw();
  };

  const showFeaturedImageLoading = () => {
    const refs = getRefs();
    if (!refs.featuredWrap || !refs.mediaPanel) return;

    hideOutputMediaSkeleton();

    if (refs.featuredImg) {
      refs.featuredImg.removeAttribute("src");
      refs.featuredImg.hidden = true;
    }
    if (refs.featuredLoading) {
      refs.featuredLoading.hidden = false;
    }

    refs.featuredWrap.classList.add("is-loading");
    setVisibility(refs.mediaPanel, true, "block");
    setVisibility(refs.featuredWrap, true, "flex");
  };

  const hideFeaturedImageLoading = () => {
    const refs = getRefs();
    if (refs.featuredLoading) {
      refs.featuredLoading.hidden = true;
    }
    refs.featuredWrap?.classList.remove("is-loading");

    const hasFeaturedImage = Boolean(
      refs.featuredImg && refs.featuredImg.getAttribute("src")
    );
    const hasContentImages = Boolean(
      refs.inlineGrid && refs.inlineGrid.children.length
    );

    if (refs.featuredImg) {
      refs.featuredImg.hidden = !hasFeaturedImage;
    }
    if (!hasFeaturedImage) {
      setVisibility(refs.featuredWrap, false, "flex");
    }
    setVisibility(refs.mediaPanel, hasFeaturedImage || hasContentImages, "block");
  };

  const buildInlineImageHtml = (imageItem, alignment, size, index) => {
    const url = getImageUrl(imageItem);
    if (!url) return "";
    const altText = getImageAlt(
      imageItem,
      `${__("Generated content image", "gpt3-ai-content-generator")} ${index + 1}`
    );
    const classList = ["aipkit_cw_inline_image_block"];
    if (["left", "right", "center", "none"].includes(alignment)) {
      classList.push(`align${alignment}`);
    }
    classList.push(`size-${size || "large"}`);

    return [
      `<figure id="aipkit-cw-inline-image-${index}" class="${escapeAttr(
        classList.join(" ")
      )}" data-aipkit-cw-inline-image-index="${index}">`,
      `<img src="${escapeAttr(url)}" alt="${escapeAttr(
        altText
      )}" loading="lazy" decoding="async" />`,
      '<div class="aipkit_cw_inline_image_toolbar" aria-label="Image actions">',
      `<button type="button" class="aipkit_cw_inline_image_action" data-aipkit-image-action="regenerate" data-aipkit-image-index="${index}" aria-label="${escapeAttr(
        __("Regenerate image", "gpt3-ai-content-generator")
      )}" title="${escapeAttr(
        __("Regenerate image", "gpt3-ai-content-generator")
      )}"><span class="dashicons dashicons-update-alt" aria-hidden="true"></span></button>`,
      `<button type="button" class="aipkit_cw_inline_image_action" data-aipkit-image-action="replace" data-aipkit-image-index="${index}" aria-label="${escapeAttr(
        __("Replace image", "gpt3-ai-content-generator")
      )}" title="${escapeAttr(
        __("Replace image", "gpt3-ai-content-generator")
      )}"><span class="dashicons dashicons-upload" aria-hidden="true"></span></button>`,
      `<button type="button" class="aipkit_cw_inline_image_action aipkit_cw_inline_image_action--delete" data-aipkit-image-action="delete" data-aipkit-image-index="${index}" aria-label="${escapeAttr(
        __("Remove image", "gpt3-ai-content-generator")
      )}" title="${escapeAttr(
        __("Remove image", "gpt3-ai-content-generator")
      )}"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>`,
      "</div>",
      "</figure>",
    ].join("");
  };

  const appendImagesAtEnd = (html, imagesHtml) => {
    if (!imagesHtml.length) return html;
    return `${html}\n\n${imagesHtml.join("\n\n")}`;
  };

  const insertAfterFirstTag = (html, imagesHtml, tagName) => {
    if (!imagesHtml.length) return html;
    const firstImage = imagesHtml.shift();
    const lower = html.toLowerCase();
    const closeTag = `</${tagName}>`;
    const idx = lower.indexOf(closeTag);
    if (idx !== -1) {
      html =
        html.slice(0, idx + closeTag.length) +
        `\n\n${firstImage}\n\n` +
        html.slice(idx + closeTag.length);
    } else {
      html += `\n\n${firstImage}`;
    }
    return appendImagesAtEnd(html, imagesHtml);
  };

  const insertAfterEveryTag = (html, imagesHtml, tagName, step) => {
    if (!imagesHtml.length) return html;
    if (!step || step <= 0) {
      return appendImagesAtEnd(html, imagesHtml);
    }
    const parts = html.split(new RegExp(`(</${tagName}>)`, "ig"));
    let result = "";
    let count = 0;
    let imageIndex = 0;
    for (let i = 0; i < parts.length; i += 1) {
      result += parts[i];
      if (i % 2 === 1) {
        count += 1;
        if (count % step === 0 && imageIndex < imagesHtml.length) {
          result += `\n\n${imagesHtml[imageIndex]}\n\n`;
          imageIndex += 1;
        }
      }
    }
    while (imageIndex < imagesHtml.length) {
      result += `\n\n${imagesHtml[imageIndex]}`;
      imageIndex += 1;
    }
    return result;
  };

  const injectInlineImagesPreview = (imageData) => {
    const contentArea = document.getElementById(
      "aipkit_cw_generated_content_area"
    );
    if (!contentArea) return;

    const inlineImages = Array.isArray(imageData?.in_content_images)
      ? imageData.in_content_images
      : [];
    if (!inlineImages.length) {
      restoreContentFromRaw();
      return;
    }

    let rawHtml = getRawContentHtml();
    if (!rawHtml) {
      rawHtml = contentArea.innerHTML;
    }
    if (!rawHtml) return;

    const form = document.getElementById("aipkit_content_writer_form");
    const placement =
      imageData?.placement_settings?.placement ||
      form?.elements["image_placement"]?.value ||
      "after_first_h2";
    const paramXRaw =
      imageData?.placement_settings?.param_x ||
      form?.elements["image_placement_param_x"]?.value ||
      2;
    const paramX = parseInt(paramXRaw, 10) || 2;
    const alignment = form?.elements["image_alignment"]?.value || "none";
    const size = form?.elements["image_size"]?.value || "large";

    const imagesHtml = inlineImages
      .map((imageItem, index) =>
        buildInlineImageHtml(imageItem, alignment, size, index)
      )
      .filter(Boolean);
    if (!imagesHtml.length) {
      restoreContentFromRaw();
      return;
    }

    let injectedHtml = rawHtml;
    switch (placement) {
      case "after_first_h2":
        injectedHtml = insertAfterFirstTag(injectedHtml, imagesHtml, "h2");
        break;
      case "after_first_h3":
        injectedHtml = insertAfterFirstTag(injectedHtml, imagesHtml, "h3");
        break;
      case "after_every_x_h2":
        injectedHtml = insertAfterEveryTag(
          injectedHtml,
          imagesHtml,
          "h2",
          paramX
        );
        break;
      case "after_every_x_h3":
        injectedHtml = insertAfterEveryTag(
          injectedHtml,
          imagesHtml,
          "h3",
          paramX
        );
        break;
      case "after_every_x_p":
        injectedHtml = insertAfterEveryTag(
          injectedHtml,
          imagesHtml,
          "p",
          paramX
        );
        break;
      case "at_end":
      default:
        injectedHtml = appendImagesAtEnd(injectedHtml, imagesHtml);
        break;
    }

    contentArea.innerHTML = injectedHtml;
    if (typeof window.aipkit_updateContentWriterPreviewCounter === "function") {
      window.aipkit_updateContentWriterPreviewCounter();
    }
  };

  const getSectionLabel = (imageIndex) => {
    const imageBlock = document.querySelector(
      `[data-aipkit-cw-inline-image-index="${imageIndex}"]`
    );
    if (!imageBlock) {
      return __("In article", "gpt3-ai-content-generator");
    }
    let sibling = imageBlock.previousElementSibling;
    while (sibling) {
      if (/^H[1-4]$/.test(sibling.tagName)) {
        const heading = String(sibling.textContent || "").trim();
        if (heading) {
          const shortened =
            heading.length > 28 ? `${heading.slice(0, 27).trim()}…` : heading;
          return `${__("In", "gpt3-ai-content-generator")} “${shortened}”`;
        }
      }
      sibling = sibling.previousElementSibling;
    }
    return __("In article", "gpt3-ai-content-generator");
  };

  const renderInlineImages = (refs, inlineImages) => {
    if (!refs.inlineGrid || !refs.contentSection || !inlineImages.length) {
      return false;
    }
    clearInlineGrid(refs.inlineGrid);
    const fragment = document.createDocumentFragment();

    inlineImages.forEach((imageItem, index) => {
      const url = getImageUrl(imageItem);
      if (!url) return;

      const button = document.createElement("button");
      button.type = "button";
      button.className = "aipkit_cw_inline_image_thumb";
      button.dataset.aipkitImageIndex = String(index);
      button.setAttribute(
        "aria-label",
        `${__("Jump to content image", "gpt3-ai-content-generator")} ${
          index + 1
        }`
      );

      const frame = document.createElement("span");
      frame.className = "aipkit_cw_inline_image_thumb_frame";
      const img = document.createElement("img");
      img.src = url;
      img.alt = getImageAlt(imageItem, "");
      img.loading = "lazy";
      img.decoding = "async";
      frame.appendChild(img);

      const caption = document.createElement("span");
      caption.className = "aipkit_cw_inline_image_thumb_caption";
      caption.textContent = getSectionLabel(index);

      button.append(frame, caption);
      fragment.appendChild(button);
    });

    refs.inlineGrid.appendChild(fragment);
    setVisibility(refs.contentSection, refs.inlineGrid.children.length > 0, "block");
    return refs.inlineGrid.children.length > 0;
  };

  const getFeaturedAlt = (imageData) =>
    imageData.featured_image_alt ||
    getImageAlt(imageData.featured_image_data, "") ||
    getImageAlt(imageData.featured_image, "");

  const renderFeaturedImage = (refs, imageData, url) => {
    if (!refs.featuredWrap || !refs.featuredImg || !url) return false;
    refs.featuredWrap.classList.remove("is-loading");
    if (refs.featuredLoading) {
      refs.featuredLoading.hidden = true;
    }
    const altText = getFeaturedAlt(imageData);
    refs.featuredImg.src = url;
    refs.featuredImg.alt =
      altText || __("Featured image preview", "gpt3-ai-content-generator");
    refs.featuredImg.hidden = false;
    if (refs.featuredAlt) {
      refs.featuredAlt.value = altText;
    }
    setVisibility(refs.featuredWrap, true, "flex");
    return true;
  };

  const getMediaAttachmentData = (attachment) => ({
    attachment_id: attachment.id || null,
    media_library_url: attachment.url || "",
    url: attachment.url || "",
    alt: attachment.alt || "",
    title: attachment.title || "",
    caption: attachment.caption || "",
    description: attachment.description || "",
  });

  const openMediaPicker = (onSelect) => {
    if (!window.wp || typeof window.wp.media !== "function") {
      showActionError(
        __(
          "The WordPress media library is not available. Refresh the page and try again.",
          "gpt3-ai-content-generator"
        ),
        __("Unable to choose image", "gpt3-ai-content-generator")
      );
      return;
    }

    const frame = window.wp.media({
      title: __("Choose an image", "gpt3-ai-content-generator"),
      button: { text: __("Use image", "gpt3-ai-content-generator") },
      library: { type: "image" },
      multiple: false,
    });
    frame.on("select", () => {
      const attachment = frame.state().get("selection").first()?.toJSON();
      if (attachment) {
        onSelect(attachment);
      }
    });
    frame.open();
  };

  const replaceFeaturedImage = () => {
    openMediaPicker((attachment) => {
      const imageData = getCurrentImageData();
      const selected = getMediaAttachmentData(attachment);
      imageData.featured_image_id = selected.attachment_id;
      imageData.featured_image_url = selected.url;
      imageData.featured_image_alt = selected.alt;
      imageData.featured_image_title = selected.title;
      imageData.featured_image_caption = selected.caption;
      imageData.featured_image_description = selected.description;
      imageData.featured_image_data = selected;
      writeImageData(imageData);
    });
  };

  const replaceInlineImage = (index) => {
    openMediaPicker((attachment) => {
      const imageData = getCurrentImageData();
      const inlineImages = Array.isArray(imageData.in_content_images)
        ? imageData.in_content_images.slice()
        : [];
      if (!inlineImages[index]) return;
      inlineImages[index] = {
        ...inlineImages[index],
        ...getMediaAttachmentData(attachment),
      };
      imageData.in_content_images = inlineImages;
      writeImageData(imageData);
    });
  };

  const removeInlineImage = (index) => {
    const imageData = getCurrentImageData();
    const inlineImages = Array.isArray(imageData.in_content_images)
      ? imageData.in_content_images.slice()
      : [];
    if (!inlineImages[index]) return;
    inlineImages.splice(index, 1);
    imageData.in_content_images = inlineImages;
    writeImageData(imageData);
  };

  const regenerateImage = async (type, index, trigger) => {
    if (typeof window.aipkit_regenerateContentWriterImage !== "function") {
      showActionError(
        __(
          "Image regeneration is not available for this draft. Generate the article again to create new images.",
          "gpt3-ai-content-generator"
        ),
        __("Unable to regenerate image", "gpt3-ai-content-generator")
      );
      return;
    }

    if (trigger) {
      trigger.disabled = true;
      trigger.classList.add("is-busy");
    }
    try {
      await window.aipkit_regenerateContentWriterImage({ type, index });
    } catch (error) {
      if (
        type === "featured" &&
        typeof window.aipkit_hideContentWriterFeaturedImageLoading ===
          "function"
      ) {
        window.aipkit_hideContentWriterFeaturedImageLoading();
      }
      showActionError(
        error?.message ||
          __("The image could not be regenerated.", "gpt3-ai-content-generator"),
        __("Unable to regenerate image", "gpt3-ai-content-generator")
      );
    } finally {
      if (trigger) {
        trigger.disabled = false;
        trigger.classList.remove("is-busy");
      }
    }
  };

  const jumpToInlineImage = (index) => {
    const target = document.querySelector(
      `[data-aipkit-cw-inline-image-index="${index}"]`
    );
    if (!target) return;
    target.scrollIntoView({ behavior: "smooth", block: "center" });
    target.classList.add("is-highlighted");
    window.setTimeout(() => target.classList.remove("is-highlighted"), 1400);
  };

  const attachListeners = (refs) => {
    if (
      refs.featuredAlt &&
      refs.featuredAlt.dataset.listenerAttached !== "true"
    ) {
      refs.featuredAlt.addEventListener("input", () => {
        const imageData = getCurrentImageData();
        imageData.featured_image_alt = refs.featuredAlt.value;
        if (
          imageData.featured_image_data &&
          typeof imageData.featured_image_data === "object"
        ) {
          imageData.featured_image_data.alt = refs.featuredAlt.value;
        }
        writeImageData(imageData, false);
        if (refs.featuredImg) {
          refs.featuredImg.alt = refs.featuredAlt.value;
        }
      });
      refs.featuredAlt.dataset.listenerAttached = "true";
    }

    if (
      refs.featuredReplace &&
      refs.featuredReplace.dataset.listenerAttached !== "true"
    ) {
      refs.featuredReplace.addEventListener("click", replaceFeaturedImage);
      refs.featuredReplace.dataset.listenerAttached = "true";
    }

    if (
      refs.featuredRegenerate &&
      refs.featuredRegenerate.dataset.listenerAttached !== "true"
    ) {
      refs.featuredRegenerate.addEventListener("click", () =>
        regenerateImage("featured", 0, refs.featuredRegenerate)
      );
      refs.featuredRegenerate.dataset.listenerAttached = "true";
    }

    if (
      refs.inlineGrid &&
      refs.inlineGrid.dataset.listenerAttached !== "true"
    ) {
      refs.inlineGrid.addEventListener("click", (event) => {
        const button = event.target.closest("[data-aipkit-image-index]");
        if (!button) return;
        jumpToInlineImage(parseInt(button.dataset.aipkitImageIndex, 10));
      });
      refs.inlineGrid.dataset.listenerAttached = "true";
    }

    if (
      refs.contentArea &&
      refs.contentArea.dataset.imageActionsAttached !== "true"
    ) {
      refs.contentArea.addEventListener("click", (event) => {
        const actionButton = event.target.closest("[data-aipkit-image-action]");
        if (!actionButton) return;
        const index = parseInt(actionButton.dataset.aipkitImageIndex, 10);
        const action = actionButton.dataset.aipkitImageAction;
        if (action === "replace") {
          replaceInlineImage(index);
        } else if (action === "delete") {
          removeInlineImage(index);
        } else if (action === "regenerate") {
          regenerateImage("inline", index, actionButton);
        }
      });
      refs.contentArea.dataset.imageActionsAttached = "true";
    }
  };

  function renderPreview(imageData) {
    const refs = getRefs();
    if (!refs.contentArea) return;
    attachListeners(refs);
    clearPreview();

    const data = parseImageData(imageData);
    const inlineImages = Array.isArray(data.in_content_images)
      ? data.in_content_images
      : [];
    const featuredUrl =
      data.featured_image_url ||
      getImageUrl(data.featured_image) ||
      getImageUrl(data.featured_image_data);

    injectInlineImagesPreview(data);
    const hasFeatured = renderFeaturedImage(refs, data, featuredUrl);
    const hasInline = renderInlineImages(refs, inlineImages);
    const inlineCount = refs.inlineGrid
      ? refs.inlineGrid.children.length
      : 0;

    updateImageCount(refs, hasFeatured ? 1 : 0, inlineCount);
    setVisibility(refs.mediaPanel, hasFeatured || hasInline, "block");
  }

  window.aipkit_renderContentWriterImagePreview = renderPreview;
  window.aipkit_clearContentWriterImagePreview = clearPreview;
  window.aipkit_showContentWriterFeaturedImageLoading = showFeaturedImageLoading;
  window.aipkit_hideContentWriterFeaturedImageLoading = hideFeaturedImageLoading;
})();
