/*
 * AIPKit Public Chat - Fullscreen Logic
 *
 * Moves the chat to the document body to escape host stacking contexts, then
 * animates its real layout rectangle so chat content reflows while expanding.
 */
(function () {
  "use strict";

  const FULLSCREEN_EXPAND_DURATION_MS = 280;
  const FULLSCREEN_RESTORE_DURATION_MS = 220;
  const FULLSCREEN_SOURCE_VARIABLES = [
    "--aipkit-fullscreen-source-top",
    "--aipkit-fullscreen-source-left",
    "--aipkit-fullscreen-source-width",
    "--aipkit-fullscreen-source-height",
    "--aipkit-fullscreen-source-radius",
  ];

  const maximizeIconSvg =
    '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-arrows-maximize"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M16 4l4 0l0 4" /><path d="M14 10l6 -6" /><path d="M8 20l-4 0l0 -4" /><path d="M4 20l6 -6" /><path d="M16 20l4 0l0 -4" /><path d="M14 14l6 6" /><path d="M8 4l-4 0l0 4" /><path d="M4 4l6 6" /></svg>';
  const minimizeIconSvg =
    '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-arrows-minimize"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 9l4 0l0 -4" /><path d="M3 3l6 6" /><path d="M5 15l4 0l0 4" /><path d="M3 21l6 -6" /><path d="M19 9l-4 0l0 -4" /><path d="M15 9l6 -6" /><path d="M19 15l-4 0l0 4" /><path d="M15 15l6 6" /></svg>';

  function visitPageOverflow(body, docEl, visit) {
    for (const [element, prefix] of [[body, ""], [docEl, "Html"]]) {
      for (const axis of ["", "X", "Y"]) {
        visit(element, `overflow${axis}`, `aipkitScrollLock${prefix}Overflow${axis}`);
      }
    }
  }

  function lockPageScroll() {
    const body = document.body;
    const docEl = document.documentElement;
    const lockCount = Number(body.dataset.aipkitScrollLockCount || "0");

    if (lockCount === 0) {
      // Capture every value before assigning the overflow shorthand or axes.
      visitPageOverflow(body, docEl, (element, property, key) => {
        body.dataset[key] = element.style[property] || "";
      });
      visitPageOverflow(body, docEl, (element, property) => {
        element.style[property] = "hidden";
      });
    }

    body.dataset.aipkitScrollLockCount = String(lockCount + 1);
  }

  function unlockPageScroll() {
    const body = document.body;
    const docEl = document.documentElement;
    const lockCount = Number(body.dataset.aipkitScrollLockCount || "0");

    if (lockCount <= 1) {
      visitPageOverflow(body, docEl, (element, property, key) => {
        element.style[property] = body.dataset[key] || "";
      });
      // Remove saved values only after both elements have been restored.
      visitPageOverflow(body, docEl, (element, property, key) => {
        delete body.dataset[key];
      });
      delete body.dataset.aipkitScrollLockCount;
    } else {
      body.dataset.aipkitScrollLockCount = String(lockCount - 1);
    }
  }

  function isDesktopViewport() {
    return (
      typeof window.matchMedia === "function" &&
      window.matchMedia("(min-width: 768px)").matches
    );
  }

  function prefersReducedMotion() {
    return (
      typeof window.matchMedia === "function" &&
      window.matchMedia("(prefers-reduced-motion: reduce)").matches
    );
  }

  function shouldAnimateFullscreen() {
    return isDesktopViewport() && !prefersReducedMotion();
  }

  function rectToObject(rect) {
    return {
      top: rect.top,
      left: rect.left,
      width: rect.width,
      height: rect.height,
    };
  }

  function applySourceVariables(container, rect, radius) {
    container.style.setProperty(
      "--aipkit-fullscreen-source-top",
      `${rect.top}px`
    );
    container.style.setProperty(
      "--aipkit-fullscreen-source-left",
      `${rect.left}px`
    );
    container.style.setProperty(
      "--aipkit-fullscreen-source-width",
      `${rect.width}px`
    );
    container.style.setProperty(
      "--aipkit-fullscreen-source-height",
      `${rect.height}px`
    );
    container.style.setProperty(
      "--aipkit-fullscreen-source-radius",
      radius || "0px"
    );
  }

  function clearSourceVariables(container) {
    FULLSCREEN_SOURCE_VARIABLES.forEach((name) => {
      container.style.removeProperty(name);
    });
  }

  function createPlaceholder(container, sourceRect, isPopup) {
    const placeholder = document.createElement("div");
    const computedStyle = window.getComputedStyle(container);

    placeholder.id = `aipkit_fs_placeholder_${container.id}`;
    placeholder.setAttribute("aria-hidden", "true");

    if (isPopup) {
      placeholder.style.display = "none";
    } else {
      placeholder.style.display = computedStyle.display;
      placeholder.style.width = `${sourceRect.width}px`;
      placeholder.style.height = `${sourceRect.height}px`;
      placeholder.style.maxWidth = computedStyle.maxWidth;
      placeholder.style.marginTop = computedStyle.marginTop;
      placeholder.style.marginRight = computedStyle.marginRight;
      placeholder.style.marginBottom = computedStyle.marginBottom;
      placeholder.style.marginLeft = computedStyle.marginLeft;
      placeholder.style.flex = computedStyle.flex;
      placeholder.style.alignSelf = computedStyle.alignSelf;
      placeholder.style.boxSizing = "border-box";
      placeholder.style.visibility = "hidden";
      placeholder.style.pointerEvents = "none";
    }

    container.parentNode.insertBefore(placeholder, container);
    return placeholder;
  }

  function createBackdrop() {
    const backdrop = document.createElement("div");
    backdrop.className = "aipkit_fullscreen_backdrop";
    backdrop.setAttribute("aria-hidden", "true");
    document.body.appendChild(backdrop);
    return backdrop;
  }

  function getRestoreRect(state) {
    const placeholder = state.fullscreenPlaceholder;
    if (placeholder && placeholder.style.display !== "none") {
      const rect = placeholder.getBoundingClientRect();
      if (rect.width > 0 && rect.height > 0) {
        return rectToObject(rect);
      }
    }
    return state.fullscreenSourceRect;
  }

  function scheduleMotionEnd(state, duration, callback) {
    if (state.fullscreenMotionTimer) {
      window.clearTimeout(state.fullscreenMotionTimer);
    }
    state.fullscreenMotionTimer = window.setTimeout(() => {
      state.fullscreenMotionTimer = null;
      callback();
    }, duration);
  }

  function nextFrame(callback) {
    window.requestAnimationFrame(callback);
  }

  function updateFullscreenButton(fullscreenButton, isFullscreen, textLabels) {
    fullscreenButton.innerHTML = isFullscreen
      ? minimizeIconSvg
      : maximizeIconSvg;
    fullscreenButton.title = isFullscreen
      ? textLabels.exitFullscreen || "Exit Fullscreen"
      : textLabels.fullscreen || "Fullscreen";
    fullscreenButton.setAttribute("aria-label", fullscreenButton.title);
    fullscreenButton.setAttribute("aria-expanded", isFullscreen.toString());
  }

  function dispatchFullscreenState(container, isFullscreen) {
    container.dispatchEvent(
      new CustomEvent("aipkit:fullscreenToggled", {
        detail: { isFullscreen },
      })
    );
  }

  function refreshFullscreenContent(elements, isFullscreen) {
    const { messagesEl, inputField, startersContainer } = elements;

    if (
      isFullscreen &&
      typeof window.aipkit_chatUI_scrollToBottom === "function"
    ) {
      window.aipkit_chatUI_scrollToBottom(messagesEl, true);
    }

    if (inputField) {
      try {
        inputField.focus({ preventScroll: true });
      } catch {
        inputField.focus();
      }
    }

    if (
      typeof window.aipkit_chatUI_triggerMessageAnimation === "function" &&
      messagesEl
    ) {
      const greetingEl = messagesEl.querySelector(".aipkit_initial_greeting");
      if (greetingEl) {
        window.aipkit_chatUI_triggerMessageAnimation(greetingEl);
      }
    }

    if (
      typeof window.aipkit_chatUI_refreshStartersAnimation === "function" &&
      startersContainer
    ) {
      window.aipkit_chatUI_refreshStartersAnimation(startersContainer);
    }
  }

  function finishEnteringFullscreen(elements, state) {
    const { container } = elements;
    container.classList.remove(
      "aipkit-fullscreen-transitioning",
      "aipkit-fullscreen-entering",
      "aipkit-fullscreen-start"
    );
    state.fullscreenTransitioning = false;
    refreshFullscreenContent(elements, true);
  }

  function finishRestoringFullscreen(elements, state, isAdminPreview) {
    const { container } = elements;
    const placeholder = state.fullscreenPlaceholder;
    const wrapper = state.fullscreenWrapper;
    const backdrop = state.fullscreenBackdrop;

    if (placeholder && placeholder.parentNode) {
      placeholder.parentNode.insertBefore(container, placeholder);
    }

    if (wrapper) {
      wrapper.classList.remove("aipkit-fullscreen-wrapper");
    }

    container.classList.remove(
      "aipkit-fullscreen",
      "aipkit-fullscreen-transitioning",
      "aipkit-fullscreen-exiting",
      "aipkit-fullscreen-restoring"
    );
    clearSourceVariables(container);

    if (placeholder) {
      placeholder.remove();
    }
    if (backdrop) {
      backdrop.remove();
    }

    if (!isAdminPreview) {
      unlockPageScroll();
    }

    state.fullscreenPlaceholder = null;
    state.fullscreenWrapper = null;
    state.fullscreenBackdrop = null;
    state.fullscreenSourceRect = null;
    state.fullscreenSourceRadius = null;
    state.fullscreenIsAdminPreview = null;
    state.fullscreenTransitioning = false;
    if (
      container._aipkitPopupMobileViewport &&
      typeof container._aipkitPopupMobileViewport.refresh === "function"
    ) {
      container._aipkitPopupMobileViewport.refresh();
    }
    refreshFullscreenContent(elements, false);
  }

  function enterFullscreen(elements, state, isAdminPreview) {
    const { container } = elements;
    const sourceRect = rectToObject(container.getBoundingClientRect());
    const sourceRadius = window.getComputedStyle(container).borderRadius;
    const wrapper = container.closest(".aipkit_popup_wrapper");
    const isPopup = container.classList.contains("aipkit_popup_content");
    const animate = shouldAnimateFullscreen();

    state.fullscreenSourceRect = sourceRect;
    state.fullscreenSourceRadius = sourceRadius;
    state.fullscreenWrapper = wrapper;
    state.fullscreenIsAdminPreview = isAdminPreview;
    state.fullscreenPlaceholder = createPlaceholder(
      container,
      sourceRect,
      isPopup
    );
    state.fullscreenBackdrop = animate ? createBackdrop() : null;
    state.fullscreenTransitioning = true;
    state.isFullscreen = true;

    applySourceVariables(container, sourceRect, sourceRadius);
    if (wrapper) {
      wrapper.classList.add("aipkit-fullscreen-wrapper");
    }
    if (!isAdminPreview) {
      lockPageScroll();
    }

    document.body.appendChild(container);
    container.classList.add("aipkit-fullscreen");

    if (!animate) {
      finishEnteringFullscreen(elements, state);
      return;
    }

    container.classList.add(
      "aipkit-fullscreen-transitioning",
      "aipkit-fullscreen-entering",
      "aipkit-fullscreen-start"
    );

    // Establish the source rectangle before switching to the viewport target.
    void container.offsetWidth;
    nextFrame(() => {
      container.classList.remove("aipkit-fullscreen-start");
      state.fullscreenBackdrop.classList.add(
        "aipkit-fullscreen-backdrop-visible"
      );
      scheduleMotionEnd(
        state,
        FULLSCREEN_EXPAND_DURATION_MS,
        () => finishEnteringFullscreen(elements, state)
      );
    });
  }

  function restoreFullscreen(elements, state, isAdminPreview) {
    const { container } = elements;
    const restoreRect = getRestoreRect(state);
    const animate = shouldAnimateFullscreen() && !!restoreRect;
    const backdrop = state.fullscreenBackdrop;

    state.fullscreenTransitioning = true;
    state.isFullscreen = false;

    if (!animate) {
      finishRestoringFullscreen(elements, state, isAdminPreview);
      return;
    }

    applySourceVariables(
      container,
      restoreRect,
      state.fullscreenSourceRadius || "0px"
    );
    container.classList.add(
      "aipkit-fullscreen-transitioning",
      "aipkit-fullscreen-exiting"
    );
    if (backdrop) {
      backdrop.classList.add("aipkit-fullscreen-backdrop-exiting");
    }

    // Register the restore timing before applying the smaller target rectangle.
    void container.offsetWidth;
    nextFrame(() => {
      container.classList.add("aipkit-fullscreen-restoring");
      if (backdrop) {
        backdrop.classList.remove("aipkit-fullscreen-backdrop-visible");
      }
      scheduleMotionEnd(
        state,
        FULLSCREEN_RESTORE_DURATION_MS,
        () => finishRestoringFullscreen(elements, state, isAdminPreview)
      );
    });
  }

  /**
   * Toggles the fullscreen state of the chat container.
   * @param {object} elements - Chat UI element references.
   * @param {object} state - Shared mutable chat state.
   * @param {object} config - Chatbot configuration and labels.
   */
  function aipkit_chatUI_toggleFullscreen(elements, state, config) {
    const { container, fullscreenButton } = elements;
    const textLabels = config.text || {};

    if (!container || !fullscreenButton) {
      console.error(
        "AIPKit Fullscreen: Container or fullscreen button element not found."
      );
      return;
    }
    if (state.fullscreenTransitioning) {
      return;
    }

    if (
      container._aipkitPopupDrag &&
      typeof container._aipkitPopupDrag.resetPosition === "function"
    ) {
      container._aipkitPopupDrag.resetPosition();
    }

    const enteringFullscreen = !state.isFullscreen;
    const isAdminPreview = enteringFullscreen
      ? !!container.closest("#aipkit_admin_chat_preview_container")
      : state.fullscreenIsAdminPreview === true;

    if (enteringFullscreen) {
      enterFullscreen(elements, state, isAdminPreview);
    } else {
      restoreFullscreen(elements, state, isAdminPreview);
    }

    updateFullscreenButton(
      fullscreenButton,
      state.isFullscreen,
      textLabels
    );
    dispatchFullscreenState(container, state.isFullscreen);
  }

  window.aipkit_chatUI_toggleFullscreen = aipkit_chatUI_toggleFullscreen;
})();
