(function () {
  "use strict";

  const stores = new WeakMap();
  const TRANSITION_MS = 220;
  const ALT_TEXT_LIMIT = 300;

  const ICONS = {
    download:
      '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 20h14" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    favorite:
      '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m12 3 2.75 5.57 6.15.9-4.45 4.33 1.05 6.12L12 17.03l-5.5 2.89 1.05-6.12L3.1 9.47l6.15-.9L12 3Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>',
    edit:
      '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m14.5 5.5 4 4M4 20l4.1-1 10.4-10.4a2.12 2.12 0 0 0-3-3L5.1 16 4 20Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    expand:
      '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 4H4v5m11-5h5v5M9 20H4v-5m11 5h5v-5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    delete:
      '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 7h16m-10 4v5m4-5v5M9 7l1-3h4l1 3m3 0-1 13H7L6 7" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    copy:
      '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="8" y="8" width="11" height="11" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2" stroke="currentColor" stroke-width="1.6"/></svg>',
    check:
      '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m5 12.5 4.25 4.25L19 7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    warning:
      '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M10.3 4.1 2.8 17a2 2 0 0 0 1.73 3h14.94a2 2 0 0 0 1.73-3L13.7 4.1a2 2 0 0 0-3.4 0Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M12 9v4m0 3h.01" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
  };

  function getTexts() {
    return window.aipkit_image_generator_config_public?.text || {};
  }

  function escapeHtml(value) {
    const escaper = window.aipkit_escapeHtml;
    if (typeof escaper === "function") {
      return escaper(String(value ?? ""));
    }
    const node = document.createElement("div");
    node.textContent = String(value ?? "");
    return node.innerHTML;
  }

  function getStore(container) {
    if (!stores.has(container)) {
      stores.set(container, {
        activeIndex: 0,
        items: [],
        prompt: "",
        modelLabel: "",
        displayLabel: "",
        retry: null,
        copyTimer: null,
        feedbackTimer: null,
        transitionTimer: null,
      });
    }
    return stores.get(container);
  }

  function announce(container, message) {
    const announcer = container?.querySelector("[data-aipkit-result-announcer]");
    if (!announcer || !message) {
      return;
    }
    announcer.textContent = "";
    window.requestAnimationFrame(() => {
      announcer.textContent = String(message);
    });
  }

  function notify(container, message, type = "info") {
    if (!container || !message) {
      return;
    }
    const store = getStore(container);
    const feedback = container.querySelector("[data-aipkit-result-feedback]");
    announce(container, message);
    if (!feedback) {
      return;
    }
    window.clearTimeout(store.feedbackTimer);
    feedback.textContent = String(message);
    feedback.dataset.type = type === "error" ? "error" : "info";
    feedback.hidden = false;
    store.feedbackTimer = window.setTimeout(() => {
      feedback.hidden = true;
      feedback.textContent = "";
    }, 3200);
  }

  function resetResultChrome(container) {
    [
      "[data-aipkit-result-actions-overlay]",
      "[data-aipkit-result-actions-touch]",
      "[data-aipkit-result-pagination]",
      "[data-aipkit-result-caption]",
    ].forEach((selector) => {
      const element = container.querySelector(selector);
      if (!element) {
        return;
      }
      element.hidden = true;
      element.innerHTML = "";
    });
  }

  function transitionState(container, stateName, stateClassName, html, message) {
    const shell = container?.querySelector("[data-aipkit-result-shell]");
    if (!shell) {
      return null;
    }
    const store = getStore(container);
    window.clearTimeout(store.transitionTimer);

    const currentStates = Array.from(
      shell.querySelectorAll("[data-aipkit-result-state]")
    );
    const nextState = document.createElement("div");
    nextState.className = `aipkit_image_result_state ${stateClassName}`;
    nextState.dataset.aipkitResultState = stateName;
    nextState.innerHTML = html;
    const overlayActions = shell.querySelector(
      "[data-aipkit-result-actions-overlay]"
    );
    shell.insertBefore(nextState, overlayActions || null);

    container.dataset.state = stateName;
    container.setAttribute("aria-busy", stateName === "loading" ? "true" : "false");
    resetResultChrome(container);

    window.requestAnimationFrame(() => {
      currentStates.forEach((element) => {
        element.classList.remove("is-active");
        element.classList.add("is-leaving");
      });
      nextState.classList.add("is-active");
    });

    store.transitionTimer = window.setTimeout(() => {
      currentStates.forEach((element) => element.remove());
    }, TRANSITION_MS);

    if (message) {
      announce(container, message);
    }
    return nextState;
  }

  function renderEmpty(container) {
    if (!container) {
      return;
    }
    const store = getStore(container);
    const shell = container.querySelector("[data-aipkit-result-shell]");
    window.clearTimeout(store.transitionTimer);
    shell
      ?.querySelectorAll("[data-aipkit-result-state]")
      .forEach((element) => element.remove());
    store.items = [];
    store.activeIndex = 0;
    store.retry = null;
    container.dataset.state = "empty";
    container.setAttribute("aria-busy", "false");
    resetResultChrome(container);
  }

  function renderLoading(container, options = {}) {
    if (!container) {
      return;
    }
    const texts = getTexts();
    const isVideo = Boolean(options.isVideo);
    const message = String(
      options.message ||
        (isVideo
          ? texts.videoGenerationInProgress || "Video generation in progress…"
          : texts.generating || "Generating…")
    );
    const current = container.querySelector(
      '.aipkit_image_result_state.is-active[data-aipkit-result-state="loading"]'
    );
    if (current) {
      const status = current.querySelector("[data-aipkit-result-loading-label]");
      if (status) {
        status.textContent = message;
      }
      return;
    }
    const statusClass = isVideo
      ? " aipkit_image_result_loading_status--video"
      : "";
    const html = `<div class="aipkit_image_result_loading_status${statusClass}"><span class="aipkit_spinner aipkit_spinner--visible" aria-hidden="true"></span><span data-aipkit-result-loading-label>${escapeHtml(
      message
    )}</span></div>`;
    transitionState(
      container,
      "loading",
      "aipkit_image_result_state--loading",
      html,
      isVideo
        ? texts.videoGenerationInProgress || "Video generation is in progress."
        : texts.generationStarted || "Image generation started."
    );
  }

  function renderError(container, message, options = {}) {
    if (!container) {
      return;
    }
    const texts = getTexts();
    const safeMessage = String(
      message || texts.error || "The image could not be generated."
    ).trim();
    const retryLabel = options.retryLabel || texts.tryAgain || "Try again";
    const store = getStore(container);
    const retryable = options.retryable !== false;
    store.retry = retryable
      ? typeof options.retry === "function"
        ? options.retry
        : () => window.aipkit_handlePublicImageGeneration?.()
      : null;
    const retryHtml = retryable
      ? `<button type="button" class="aipkit_image_result_retry" data-aipkit-result-retry>${escapeHtml(
          retryLabel
        )}</button>`
      : "";
    const html = `<div class="aipkit_image_result_error_content"><span class="aipkit_image_result_error_icon" aria-hidden="true">${ICONS.warning}</span><p class="aipkit_image_result_error_message">${escapeHtml(
      safeMessage
    )}</p>${retryHtml}</div>`;
    transitionState(
      container,
      "error",
      "aipkit_image_result_state--error",
      html,
      safeMessage
    );
  }

  function renderQuota(container, notice) {
    if (!container || !notice || typeof notice !== "object") {
      return false;
    }
    const message = String(notice.message || "").trim();
    const actions = Array.isArray(notice.actions) ? notice.actions : [];
    const actionsHtml = actions
      .filter((action) => String(action?.label || "").trim() && String(action?.url || "").trim())
      .map((action) => {
        const variant = action?.variant === "secondary" ? "secondary" : "primary";
        return `<a class="aipkit_image_quota_action aipkit_image_quota_action--${variant}" href="${escapeHtml(
          String(action.url)
        )}" target="_blank" rel="noopener noreferrer">${escapeHtml(
          String(action.label)
        )}</a>`;
      })
      .join("");
    const html = `<div class="aipkit_image_result_quota_content"><span class="aipkit_image_result_error_icon" aria-hidden="true">${ICONS.warning}</span><p class="aipkit_image_quota_message">${escapeHtml(
      message
    )}</p>${
      actionsHtml
        ? `<div class="aipkit_image_quota_actions">${actionsHtml}</div>`
        : ""
    }</div>`;
    const store = getStore(container);
    store.retry = null;
    transitionState(
      container,
      "error",
      "aipkit_image_result_state--error",
      html,
      message
    );
    return true;
  }

  function normalizeMediaItem(mediaData, isVideo, index) {
    const src = isVideo
      ? String(mediaData?.url || "")
      : String(
          mediaData?.url ||
            (mediaData?.b64_json
              ? `data:image/png;base64,${mediaData.b64_json}`
              : "")
        );
    const linkUrl = String(mediaData?.media_library_url || mediaData?.url || src);
    const item = {
      attachmentId: Number(mediaData?.attachment_id || 0),
      favorite:
        mediaData?.favorite === true ||
        mediaData?.favorite === 1 ||
        mediaData?.favorite === "1",
      fileName: `aipkit-${isVideo ? "video" : "image"}-${index + 1}.${
        isVideo ? "mp4" : "png"
      }`,
      isVideo,
      linkUrl,
      src,
    };
    return item;
  }

  function truncateAltText(prompt) {
    const normalized = String(prompt || "Generated image").trim();
    if (normalized.length <= ALT_TEXT_LIMIT) {
      return normalized;
    }
    return `${normalized.slice(0, ALT_TEXT_LIMIT - 1).trimEnd()}…`;
  }

  function buildMediaItemHtml(item, index, options) {
    const activeClass = index === 0 ? " is-active" : "";
    const ariaHidden = index === 0 ? "false" : "true";
    const modelBadge = options.displayLabel
      ? `<span class="aipkit_image_result_badge aipkit_image_result_badge--model" title="${escapeHtml(
          options.displayLabel
        )}">${escapeHtml(options.displayLabel)}</span>`
      : "";
    const dimensionBadge =
      '<span class="aipkit_image_result_badge aipkit_image_result_badge--dimensions" data-aipkit-result-dimensions hidden></span>';
    const mediaHtml = item.isVideo
      ? `<video class="aipkit_image_result_video" src="${escapeHtml(
          item.src
        )}" controls preload="metadata" tabindex="${
          index === 0 ? "0" : "-1"
        }" data-aipkit-result-media></video>`
      : `<img class="aipkit_image_result_media" src="${escapeHtml(
          item.src
        )}" alt="${escapeHtml(
          truncateAltText(options.prompt)
        )}" data-aipkit-result-media>`;
    return `<div class="aipkit_image_result_media_item is-loading${activeClass}" data-aipkit-result-index="${index}" aria-hidden="${ariaHidden}">${mediaHtml}${modelBadge}${dimensionBadge}</div>`;
  }

  function actionButton(action, label, icon, pressed = null) {
    const pressedAttribute =
      pressed === null ? "" : ` aria-pressed="${pressed ? "true" : "false"}"`;
    return `<button type="button" class="aipkit_image_result_action" data-aipkit-result-action="${action}" aria-label="${escapeHtml(
      label
    )}" title="${escapeHtml(label)}"${pressedAttribute}>${icon}</button>`;
  }

  function buildActionsHtml(container) {
    const texts = getTexts();
    const item = getActiveItem(container);
    if (!item) {
      return "";
    }
    const wrapper = container.closest("#aipkit_public_image_generator");
    const canManageSavedMedia =
      wrapper?.dataset.userLoggedIn === "1" &&
      Number(item.attachmentId || 0) > 0;
    const editAvailable = ["edit", "both"].includes(
      String(wrapper?.dataset.imageMode || "generate").toLowerCase()
    );
    const actions = [
      actionButton(
        "download",
        texts.downloadResult || (item.isVideo ? "Download video" : "Download image"),
        ICONS.download
      ),
    ];
    if (canManageSavedMedia) {
      actions.push(
        actionButton(
          "favorite",
          item.favorite
            ? texts.removeFavorite || "Remove from favorites"
            : texts.addFavorite || "Add to favorites",
          ICONS.favorite,
          item.favorite
        )
      );
    }
    if (!item.isVideo && editAvailable) {
      actions.push(
        actionButton(
          "edit",
          texts.useAsEditSource || "Use as edit source",
          ICONS.edit
        )
      );
    }
    actions.push(
      actionButton(
        "expand",
        texts.expandResult || "Expand to fullscreen",
        ICONS.expand
      )
    );
    if (canManageSavedMedia) {
      actions.push(
        actionButton(
          "delete",
          texts.deleteResult ||
            (item.isVideo ? "Delete video" : "Delete image"),
          ICONS.delete
        )
      );
    }
    return actions.join("");
  }

  function renderActions(container) {
    const html = buildActionsHtml(container);
    [
      "[data-aipkit-result-actions-overlay]",
      "[data-aipkit-result-actions-touch]",
    ].forEach((selector) => {
      const slot = container.querySelector(selector);
      if (!slot) {
        return;
      }
      slot.innerHTML = html;
      slot.hidden = !html;
    });
  }

  function renderPagination(container) {
    const store = getStore(container);
    const slot = container.querySelector("[data-aipkit-result-pagination]");
    if (!slot) {
      return;
    }
    if (store.items.length <= 1) {
      slot.hidden = true;
      slot.innerHTML = "";
      return;
    }
    const texts = getTexts();
    slot.innerHTML = store.items
      .map((_, index) => {
        const current = index === store.activeIndex;
        const label = `${texts.showResult || "Show result"} ${index + 1}`;
        return `<button type="button" class="aipkit_image_result_page" data-aipkit-result-page="${index}" aria-label="${escapeHtml(
          label
        )}" aria-current="${current ? "true" : "false"}"></button>`;
      })
      .join("");
    slot.hidden = false;
  }

  function renderCaption(container) {
    const store = getStore(container);
    const slot = container.querySelector("[data-aipkit-result-caption]");
    if (!slot) {
      return;
    }
    window.clearTimeout(store.copyTimer);
    store.copyTimer = null;
    const texts = getTexts();
    const modelHtml = store.modelLabel
      ? `<span class="aipkit_image_result_caption_model" title="${escapeHtml(
          store.modelLabel
        )}">${escapeHtml(store.modelLabel)}</span>`
      : "";
    slot.innerHTML = `<span class="aipkit_image_result_caption_prompt" title="${escapeHtml(
      store.prompt
    )}">${escapeHtml(store.prompt)}</span><button type="button" class="aipkit_image_result_copy" data-aipkit-result-copy aria-label="${escapeHtml(
      texts.copyPrompt || "Copy prompt"
    )}" title="${escapeHtml(texts.copyPrompt || "Copy prompt")}">${
      ICONS.copy
    }</button>${modelHtml}`;
    slot.hidden = false;
  }

  function setActiveItem(container, index, shouldAnnounce = true) {
    const store = getStore(container);
    if (index < 0 || index >= store.items.length) {
      return;
    }
    store.activeIndex = index;
    container
      .querySelectorAll("[data-aipkit-result-index]")
      .forEach((element) => {
        const isActive = Number(element.dataset.aipkitResultIndex) === index;
        element.classList.toggle("is-active", isActive);
        element.setAttribute("aria-hidden", isActive ? "false" : "true");
        const video = element.querySelector("video");
        if (video) {
          video.tabIndex = isActive ? 0 : -1;
        }
      });
    renderActions(container);
    renderPagination(container);
    if (shouldAnnounce && store.items.length > 1) {
      announce(container, `Result ${index + 1} of ${store.items.length}.`);
    }
  }

  function bindMediaLoadState(container, stateElement) {
    stateElement.querySelectorAll("[data-aipkit-result-media]").forEach((media) => {
      const itemElement = media.closest("[data-aipkit-result-index]");
      const dimensions = itemElement?.querySelector(
        "[data-aipkit-result-dimensions]"
      );
      const markLoaded = () => {
        const width = media.tagName === "VIDEO" ? media.videoWidth : media.naturalWidth;
        const height = media.tagName === "VIDEO" ? media.videoHeight : media.naturalHeight;
        media.classList.add("is-loaded");
        itemElement?.classList.remove("is-loading");
        if (dimensions && width && height) {
          dimensions.textContent = `${width}×${height}`;
          dimensions.hidden = false;
        }
      };
      const fail = () => {
        itemElement?.classList.remove("is-loading");
        if (itemElement?.classList.contains("is-active")) {
          renderError(
            container,
            getTexts().mediaLoadFailed || "The generated media could not be displayed."
          );
        }
      };
      if (media.tagName === "VIDEO") {
        media.addEventListener("loadedmetadata", markLoaded, { once: true });
      } else if (media.complete && media.naturalWidth > 0) {
        markLoaded();
      } else {
        media.addEventListener("load", markLoaded, { once: true });
      }
      media.addEventListener("error", fail, { once: true });
    });
  }

  function renderSuccess(container, mediaItems, options = {}) {
    if (!container || !Array.isArray(mediaItems) || mediaItems.length === 0) {
      renderError(
        container,
        getTexts().noMediaReturned || "No media was returned. Try again.",
        { retry: options.retry }
      );
      return;
    }
    const store = getStore(container);
    store.items = mediaItems
      .map((item, index) => normalizeMediaItem(item, Boolean(options.isVideo), index))
      .filter((item) => item.src);
    if (store.items.length === 0) {
      renderError(
        container,
        getTexts().noMediaReturned || "No media was returned. Try again.",
        { retry: options.retry }
      );
      return;
    }
    store.activeIndex = 0;
    store.prompt = String(options.prompt || "");
    store.modelLabel = String(options.modelLabel || "");
    store.displayLabel = String(options.displayLabel || "");
    store.retry = typeof options.retry === "function" ? options.retry : null;

    const mediaHtml = store.items
      .map((item, index) =>
        buildMediaItemHtml(item, index, {
          displayLabel: store.displayLabel,
          prompt: store.prompt,
        })
      )
      .join("");
    const html = `<div class="aipkit_image_result_media_stage">${mediaHtml}</div><div class="aipkit_image_result_scrim" aria-hidden="true"></div>`;
    const stateElement = transitionState(
      container,
      "success",
      "aipkit_image_result_state--success",
      html,
      options.isVideo
        ? getTexts().videoReady || "Your video is ready."
        : getTexts().imageReady || "Your image is ready."
    );
    if (!stateElement) {
      return;
    }
    renderActions(container);
    renderPagination(container);
    renderCaption(container);
    bindMediaLoadState(container, stateElement);
  }

  function getActiveItem(container) {
    const store = getStore(container);
    return store.items[store.activeIndex] || null;
  }

  function downloadItem(item) {
    return fetch(item.src, { credentials: "same-origin" })
      .then((response) => {
        if (!response.ok) {
          throw new Error("download_failed");
        }
        return response.blob();
      })
      .then((blob) => {
        const objectUrl = URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.href = objectUrl;
        link.download = item.fileName;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
      });
  }

  function toggleFavorite(container, button) {
    const item = getActiveItem(container);
    const wrapper = container.closest("#aipkit_public_image_generator");
    if (
      !item ||
      !wrapper ||
      wrapper.dataset.userLoggedIn !== "1" ||
      !item.attachmentId ||
      typeof window.aipkit_toggleGeneratedMediaFavorite !== "function"
    ) {
      return;
    }
    const texts = getTexts();
    const nextValue = !item.favorite;
    button.disabled = true;
    window
      .aipkit_toggleGeneratedMediaFavorite(
        wrapper,
        item.attachmentId,
        nextValue
      )
      .then((state) => {
        item.favorite = state.favorite;
        renderActions(container);
        notify(
          container,
          state.favorite
            ? texts.favoriteAdded || "Added to favorites."
            : texts.favoriteRemoved || "Removed from favorites."
        );
        wrapper.dispatchEvent(
          new CustomEvent("aipkit:image-favorite-changed", {
            detail: state,
          })
        );
      })
      .catch(() => {
        button.disabled = false;
        notify(container, texts.favoriteFailed || "Could not update favorite.", "error");
      });
  }

  function removeActiveItem(container) {
    const store = getStore(container);
    store.items.splice(store.activeIndex, 1);
    if (store.items.length === 0) {
      renderEmpty(container);
      return;
    }
    renderSuccess(
      container,
      store.items.map((item) => ({
        attachment_id: item.attachmentId,
        favorite: item.favorite,
        media_library_url: item.linkUrl,
        url: item.src,
      })),
      {
        displayLabel: store.displayLabel,
        isVideo: store.items[0].isVideo,
        modelLabel: store.modelLabel,
        prompt: store.prompt,
        retry: store.retry,
      }
    );
  }

  function deleteItem(container, button) {
    const item = getActiveItem(container);
    if (!item) {
      return;
    }
    if (!item.attachmentId) {
      removeActiveItem(container);
      return;
    }
    const texts = getTexts();
    const wrapper = container.closest("#aipkit_public_image_generator");
    const nonce = wrapper?.querySelector("#aipkit_image_generator_public_nonce")?.value;
    const ajaxUrl =
      window.aipkit_image_generator_config_public?.ajaxUrl ||
      window.aipkit_dashboard?.ajaxurl;
    if (!nonce || !ajaxUrl) {
      notify(container, texts.deleteFailed || "Could not delete this result.", "error");
      return;
    }
    button.disabled = true;
    const request = new FormData();
    request.append("action", "aipkit_delete_generated_image");
    request.append("_ajax_nonce", nonce);
    request.append("attachment_id", String(item.attachmentId));
    fetch(ajaxUrl, {
      method: "POST",
      body: request,
      credentials: "same-origin",
    })
      .then((response) => response.json())
      .then((json) => {
        if (!json.success) {
          throw new Error("delete_failed");
        }
        removeActiveItem(container);
        window.aipkit_refreshImageHistory?.(wrapper);
      })
      .catch(() => {
        button.disabled = false;
        notify(container, texts.deleteFailed || "Could not delete this result.", "error");
      });
  }

  function showCopySuccess(container, button) {
    if (!button) {
      return;
    }
    const store = getStore(container);
    const texts = getTexts();
    const copiedLabel = texts.copied || "Copied";
    const copyLabel = texts.copyPrompt || "Copy prompt";
    window.clearTimeout(store.copyTimer);
    button.classList.add("is-copied");
    button.innerHTML = ICONS.check;
    button.setAttribute("aria-label", copiedLabel);
    button.setAttribute("title", copiedLabel);
    store.copyTimer = window.setTimeout(() => {
      if (!button.isConnected) {
        store.copyTimer = null;
        return;
      }
      button.classList.remove("is-copied");
      button.innerHTML = ICONS.copy;
      button.setAttribute("aria-label", copyLabel);
      button.setAttribute("title", copyLabel);
      store.copyTimer = null;
    }, 1600);
  }

  function copyPrompt(container, button, releasePointerFocus = false) {
    const prompt = getStore(container).prompt;
    const texts = getTexts();
    const fallbackCopy = () => {
      const input = document.createElement("textarea");
      input.value = prompt;
      input.style.position = "fixed";
      input.style.opacity = "0";
      document.body.appendChild(input);
      input.select();
      const copied = document.execCommand("copy");
      input.remove();
      return copied;
    };
    const operation = navigator.clipboard?.writeText
      ? navigator.clipboard.writeText(prompt)
      : Promise.resolve().then(() => {
          if (!fallbackCopy()) {
            throw new Error("copy_failed");
          }
        });
    Promise.resolve(operation)
      .then(() => {
        showCopySuccess(container, button);
        if (releasePointerFocus) {
          button.blur();
        }
        notify(container, texts.promptCopied || "Prompt copied.");
      })
      .catch(() => notify(container, texts.copyFailed || "Could not copy prompt.", "error"));
  }

  function handleAction(container, button) {
    const action = button.dataset.aipkitResultAction;
    const item = getActiveItem(container);
    if (!action || !item) {
      return;
    }
    const texts = getTexts();
    if (action === "download") {
      button.disabled = true;
      downloadItem(item)
        .catch(() => {
          window.open(item.linkUrl || item.src, "_blank", "noopener,noreferrer");
        })
        .finally(() => {
          button.disabled = false;
        });
      return;
    }
    if (action === "favorite") {
      toggleFavorite(container, button);
      return;
    }
    if (action === "edit") {
      const wrapper = container.closest("#aipkit_public_image_generator");
      if (typeof window.aipkit_useImageAsEditSource === "function") {
        window.aipkit_useImageAsEditSource(
          wrapper,
          item.linkUrl || item.src,
          item.fileName,
          button
        );
      } else {
        notify(
          container,
          texts.editHistoryUnavailable || "Image editing is not available in the current setup.",
          "error"
        );
      }
      return;
    }
    if (action === "expand") {
      const shell = container.querySelector("[data-aipkit-result-shell]");
      if (shell?.requestFullscreen) {
        shell.requestFullscreen().catch(() => {
          window.open(item.linkUrl || item.src, "_blank", "noopener,noreferrer");
        });
      } else {
        window.open(item.linkUrl || item.src, "_blank", "noopener,noreferrer");
      }
      return;
    }
    if (action === "delete") {
      deleteItem(container, button);
    }
  }

  function init(container) {
    if (!container || container.dataset.aipkitResultUiReady === "true") {
      return;
    }
    getStore(container);
    const wrapper = container.closest("#aipkit_public_image_generator");
    wrapper?.addEventListener("aipkit:image-favorite-changed", (event) => {
      const attachmentId = Number(event.detail?.attachmentId || 0);
      if (!attachmentId) {
        return;
      }
      const store = getStore(container);
      let changed = false;
      store.items.forEach((item) => {
        if (item.attachmentId === attachmentId) {
          item.favorite = Boolean(event.detail?.favorite);
          changed = true;
        }
      });
      if (changed) {
        renderActions(container);
      }
    });
    container.addEventListener("click", (event) => {
      const actionButton = event.target.closest("[data-aipkit-result-action]");
      if (actionButton && container.contains(actionButton)) {
        event.preventDefault();
        handleAction(container, actionButton);
        return;
      }
      const pageButton = event.target.closest("[data-aipkit-result-page]");
      if (pageButton && container.contains(pageButton)) {
        event.preventDefault();
        setActiveItem(container, Number(pageButton.dataset.aipkitResultPage));
        return;
      }
      const retryButton = event.target.closest("[data-aipkit-result-retry]");
      if (retryButton && container.contains(retryButton)) {
        event.preventDefault();
        const retry = getStore(container).retry;
        if (typeof retry === "function") {
          retry();
        }
        return;
      }
      const copyButton = event.target.closest("[data-aipkit-result-copy]");
      if (copyButton && container.contains(copyButton)) {
        event.preventDefault();
        copyPrompt(container, copyButton, event.detail > 0);
      }
    });
    container.dataset.aipkitResultUiReady = "true";
  }

  window.aipkitImageResultUI = {
    announce,
    init,
    notify,
    renderEmpty,
    renderError,
    renderLoading,
    renderQuota,
    renderSuccess,
  };
})();
