/**
 * Chatbot popup lifecycle, hints, desktop dragging and mobile viewport sizing.
 */
(function () {
  "use strict";

  const MOBILE_BREAKPOINT = 767;

  const lifetimes = new WeakMap();

  function getLifetime(container) {
    if (lifetimes.has(container)) return lifetimes.get(container);
    let active = true;
    const listeners = [], pending = new Map(), features = new Set();
    const onSuspend = [], onResume = [];
    const isActive = () => active && container.isConnected !== false;
    const cancel = key => {
      const job = pending.get(key);
      if (job) { job.cancel.call(window, job.id); pending.delete(key); }
    };
    const schedule = (key, callback, delay) => {
      if (!isActive()) return;
      if (delay === undefined && pending.has(key)) return;
      cancel(key);
      const job = { cancel: delay === undefined ? cancelAnimationFrame : clearTimeout };
      const run = () => {
        if (pending.get(key) !== job) return;
        pending.delete(key);
        if (isActive()) callback();
      };
      job.id = delay === undefined ? requestAnimationFrame(run) : setTimeout(run, delay);
      pending.set(key, job);
    };
    const owner = {
      isActive, cancel, onSuspend, onResume,
      once(feature) { if (features.has(feature)) return false; features.add(feature); return true; },
      listen(target, event, callback, options) {
        const handler = (...args) => { if (isActive()) callback(...args); };
        listeners.push([target, event, handler, options]);
        if (active) target.addEventListener(event, handler, options);
      },
      after: (key, callback, delay) => schedule(key, callback, delay),
      frame: (key, callback) => schedule(key, callback),
      suspend() {
        if (!active) return;
        active = false;
        for (const key of pending.keys()) cancel(key);
        for (const [target, ...args] of listeners) target.removeEventListener(...args);
        onSuspend.forEach(callback => callback());
      },
      resume() {
        if (active || container.isConnected === false) return;
        active = true;
        for (const [target, ...args] of listeners) target.addEventListener(...args);
        onResume.forEach(callback => callback());
      },
    };
    lifetimes.set(container, owner);
    return owner;
  }

  function getPopupViewportWidth(viewport) {
    return viewport && viewport.width ? viewport.width : window.innerWidth;
  }

  const MANAGED_FOCUSABLE_SELECTOR = [
    "a[href]",
    "area[href]",
    "button",
    "input",
    "select",
    "textarea",
    "iframe",
    "object",
    "embed",
    "audio[controls]",
    "video[controls]",
    "[contenteditable]:not([contenteditable='false'])",
    "[tabindex]",
  ].join(",");
  const MANAGED_TABINDEX_ATTR = "data-aipkit-hidden-tabindex";

  function setManagedDescendantsTabbable(element, isTabbable) {
    if (!element || typeof element.querySelectorAll !== "function") {
      return;
    }

    element
      .querySelectorAll(MANAGED_FOCUSABLE_SELECTOR)
      .forEach((focusable) => {
        if (!focusable || typeof focusable.setAttribute !== "function") {
          return;
        }

        if (!isTabbable) {
          if (!focusable.hasAttribute(MANAGED_TABINDEX_ATTR)) {
            const currentTabindex = focusable.getAttribute("tabindex");
            focusable.setAttribute(
              MANAGED_TABINDEX_ATTR,
              currentTabindex === null ? "" : currentTabindex
            );
          }
          focusable.setAttribute("tabindex", "-1");
          return;
        }

        if (!focusable.hasAttribute(MANAGED_TABINDEX_ATTR)) {
          return;
        }

        const previousTabindex = focusable.getAttribute(MANAGED_TABINDEX_ATTR);
        if (previousTabindex === "") {
          focusable.removeAttribute("tabindex");
        } else {
          focusable.setAttribute("tabindex", previousTabindex);
        }
        focusable.removeAttribute(MANAGED_TABINDEX_ATTR);
      });
  }

  function aipkit_chatUI_setHiddenAccessibilityState(element, isHidden) {
    if (!element) {
      return;
    }

    const hiddenState = !!isHidden;

    if (hiddenState) {
      element.setAttribute("inert", "");
      setManagedDescendantsTabbable(element, false);
      element.setAttribute("aria-hidden", "true");
    } else {
      element.removeAttribute("inert");
      element.setAttribute("aria-hidden", "false");
      setManagedDescendantsTabbable(element, true);
    }
  }

  function isPopupIntroEligible(messagesEl) {
    if (!messagesEl) {
      return false;
    }

    return (
      messagesEl.querySelectorAll(
        ".aipkit_chat_message:not(.aipkit_initial_greeting)"
      ).length === 0
    );
  }

  function replayPopupIntroAnimations(container, messagesEl, owner) {
    const startersContainer = container.querySelector(".aipkit_conversation_starters");
    owner.frame("intro", () => owner.frame("intro", () => {
      const greetingEl = messagesEl?.querySelector(".aipkit_initial_greeting");
      if (greetingEl && typeof window.aipkit_chatUI_triggerMessageAnimation === "function") {
        window.aipkit_chatUI_triggerMessageAnimation(greetingEl);
      }
      if (startersContainer && !startersContainer.classList.contains("aipkit_hidden") &&
          typeof window.aipkit_chatUI_refreshStartersAnimation === "function") {
        window.aipkit_chatUI_refreshStartersAnimation(startersContainer);
      }
      owner.introPlayed = true;
    }));
  }

  /**
   * Synchronizes popup launcher visual and accessibility state.
   * @param {HTMLElement} container - The chat container element (.aipkit_chat_container).
   * @param {object} stateRef - Shared popup state object.
   * @param {boolean} isOpen - Whether popup is open.
   */
  function aipkit_chatUI_setPopupLauncherState(container, stateRef, isOpen) {
    if (!container || !stateRef) {
      return;
    }

    const openState = !!isOpen;
    container.classList.toggle("aipkit-popup-open", openState);
    aipkit_chatUI_setHiddenAccessibilityState(container, !openState);
    stateRef.isPopupOpen = openState;

    const wrapper = container.closest(".aipkit_popup_wrapper");
    if (!wrapper) {
      return;
    }

    wrapper.classList.toggle("aipkit-popup-open", openState);
    const triggerButton = wrapper.querySelector(".aipkit_popup_trigger");
    if (!triggerButton) {
      return;
    }

    triggerButton.setAttribute("aria-expanded", openState ? "true" : "false");

    const openLabel =
      triggerButton.getAttribute("data-label-open") || "Open Chat";
    const closeLabel =
      triggerButton.getAttribute("data-label-close") || "Close Chat";
    const activeLabel = openState ? closeLabel : openLabel;
    triggerButton.setAttribute("aria-label", activeLabel);
    triggerButton.setAttribute("title", activeLabel);
  }

  /**
   * Opens the popup chat window.
   * @param {HTMLElement} container - The chat container element (.aipkit_chat_container).
   * @param {HTMLElement} messagesEl - The messages display element.
   * @param {HTMLElement} inputField - The input field element.
   * @param {object} stateRef - A reference to the shared state object { isPopupOpen }.
   */
  function aipkit_chatUI_openPopup(
    container,
    messagesEl,
    inputField,
    stateRef
  ) {
    if (!container || !inputField || !stateRef || stateRef.isPopupOpen) return;
    const owner = getLifetime(container);
    if (!owner.isActive()) return;
    owner.cancel("close");
    container.classList.remove("aipkit-popup-closing");
    container.closest(".aipkit_popup_wrapper")?.classList.remove("aipkit-popup-closing");
    if (owner.once("intro")) {
      owner.listen(container, "aipkit:chatCleared", () => { owner.introPlayed = false; });
    }
    const replayIntro = !owner.introPlayed && isPopupIntroEligible(messagesEl);
    owner.hint?.handlePopupOpen();
    aipkit_chatUI_setPopupLauncherState(container, stateRef, true);
    container.dispatchEvent(
      new CustomEvent("aipkit:popupOpened", {
        detail: { replayIntro },
      })
    );

    if (replayIntro) {
      replayPopupIntroAnimations(container, messagesEl, owner);
    }

    // Ensure scrollToBottom is available globally
    if (typeof window.aipkit_chatUI_scrollToBottom === "function") {
      window.aipkit_chatUI_scrollToBottom(messagesEl);
    } else {
      console.error("AIPKit Popup Open: scrollToBottom function not found.");
    }

    // Only focus if not on mobile frontend.
    // Admin preview (even on mobile emulation) can focus if desired, but general mobile frontend should not autofocus.
    const isAdminPreview = container.closest(
      "#aipkit_admin_chat_preview_container"
    );
    const viewportWidth =
      getPopupViewportWidth(window.visualViewport);
    const isMobile = viewportWidth <= MOBILE_BREAKPOINT;

    if (!isMobile || isAdminPreview) {
      // Desktop chats and admin previews retain input focus.
      // Delay slightly for layout to settle, especially after UI changes.
      owner.after("focus", () => {
        if (stateRef.isPopupOpen && inputField.isConnected !== false && typeof inputField.focus === "function") {
          inputField.focus();
        }
      }, 50);
    }
  }

  // Expose function to the global scope
  window.aipkit_chatUI_setHiddenAccessibilityState =
    aipkit_chatUI_setHiddenAccessibilityState;
  window.aipkit_chatUI_setPopupLauncherState =
    aipkit_chatUI_setPopupLauncherState;
  window.aipkit_chatUI_openPopup = aipkit_chatUI_openPopup;

  function aipkit_chatUI_closePopup(container, stateRef) {
    if (!container || !stateRef || !stateRef.isPopupOpen) return;
    const owner = getLifetime(container);
    if (!owner.isActive()) return;
    const wrapper = container.closest(".aipkit_popup_wrapper");
    owner.cancel("focus");
    owner.cancel("intro");
    container._aipkitPopupDrag?.resetPosition();
    container.classList.add("aipkit-popup-closing");
    wrapper?.classList.add("aipkit-popup-closing");
    aipkit_chatUI_setPopupLauncherState(container, stateRef, false);
    const duration = typeof window.matchMedia === "function" && window.matchMedia("(prefers-reduced-motion: reduce)").matches ? 100 : 180;
    owner.after("close", () => {
      container.classList.remove("aipkit-popup-closing");
      wrapper?.classList.remove("aipkit-popup-closing");
      container.dispatchEvent(new CustomEvent("aipkit:popupClosed"));
    }, duration);
  }

  window.aipkit_chatUI_closePopup = aipkit_chatUI_closePopup;

  /**
   * Attaches popup drag behavior to a standard popup instance.
   * Dragging is limited to desktop viewports and disabled for fullscreen
   * and direct voice mode.
   *
   * @param {HTMLElement} container - The popup chat container.
   * @param {object} stateRef - Shared mutable chat state.
   * @param {object} config - Chatbot configuration object.
   */
  function aipkit_chatUI_setupPopupDrag(container, stateRef, config) {
    if (!container || !stateRef || !config || config.directVoiceMode) {
      return;
    }

    const wrapper = container.closest(".aipkit_popup_wrapper");
    const headerEl = container.querySelector(".aipkit_chat_header");
    const dragZone = headerEl
      ? headerEl.querySelector(".aipkit_header_drag_zone")
      : null;
    const previewRoot = container.closest("#aipkit_admin_chat_preview_container");

    if (
      !wrapper ||
      !wrapper.classList.contains("aipkit-popup-standard") ||
      !headerEl ||
      !dragZone
    ) {
      return;
    }

    const owner = getLifetime(container);
    if (!owner.once("drag")) return;

    const MOVE_THRESHOLD_PX = 3;
    const CLICK_SUPPRESS_MS = 250;
    const dragState = {
      isDragging: false,
      didMove: false,
      startMouseX: 0,
      startMouseY: 0,
      startTop: 0,
      startLeft: 0,
      rectWidth: 0,
      rectHeight: 0,
      lastDragEndedAt: 0,
      bodyCursor: "",
      bodyUserSelect: "",
    };

    function isDesktopViewport() {
      const viewportWidth =
        getPopupViewportWidth(window.visualViewport);
      return viewportWidth > MOBILE_BREAKPOINT;
    }

    function getAdminBarOffset() {
      const adminBar = document.getElementById("wpadminbar");
      if (!adminBar) {
        return 0;
      }

      const rect = adminBar.getBoundingClientRect();
      if (rect.height <= 0 || rect.bottom <= 0) {
        return 0;
      }

      return rect.height;
    }

    function isDragEligible() {
      return (
        owner.isActive() && wrapper.classList.contains("aipkit-popup-standard") &&
        stateRef.isPopupOpen === true &&
        stateRef.isFullscreen !== true &&
        !wrapper.classList.contains("aipkit-fullscreen-wrapper") &&
        !container.classList.contains("aipkit-fullscreen") &&
        isDesktopViewport()
      );
    }

    function getCurrentPosition(rect) {
      if (previewRoot) {
        const previewRect = previewRoot.getBoundingClientRect();
        return {
          top: rect.top - previewRect.top,
          left: rect.left - previewRect.left,
        };
      }

      return {
        top: rect.top,
        left: rect.left,
      };
    }

    function clampPosition(top, left) {
      let minTop = 0;
      let minLeft = 0;
      let maxTop = 0;
      let maxLeft = 0;

      if (previewRoot) {
        const previewRect = previewRoot.getBoundingClientRect();
        maxTop = Math.max(0, previewRect.height - dragState.rectHeight);
        maxLeft = Math.max(0, previewRect.width - dragState.rectWidth);
      } else {
        minTop = getAdminBarOffset();
        maxTop = Math.max(minTop, window.innerHeight - dragState.rectHeight);
        maxLeft = Math.max(0, window.innerWidth - dragState.rectWidth);
      }

      return {
        top: Math.min(Math.max(top, minTop), maxTop),
        left: Math.min(Math.max(left, minLeft), maxLeft),
      };
    }

    function applyPosition(top, left) {
      container.style.top = `${Math.round(top)}px`;
      container.style.left = `${Math.round(left)}px`;
      container.style.right = "auto";
      container.style.bottom = "auto";
      wrapper.classList.add("aipkit-popup-dragged");
    }

    function clearPosition() {
      container.style.top = "";
      container.style.left = "";
      container.style.right = "";
      container.style.bottom = "";
      wrapper.classList.remove("aipkit-popup-dragged");
    }

    function removeDragListeners() {
      document.removeEventListener("mousemove", handleMouseMove);
      document.removeEventListener("mouseup", stopDrag);
      window.removeEventListener("blur", stopDrag);
    }

    function restoreBodyInteraction() {
      document.body.style.cursor = dragState.bodyCursor;
      document.body.style.userSelect = dragState.bodyUserSelect;
      wrapper.classList.remove("aipkit-popup-dragging");
    }

    function stopDrag() {
      if (!dragState.isDragging) {
        return;
      }

      dragState.isDragging = false;
      restoreBodyInteraction();
      removeDragListeners();

      if (dragState.didMove) {
        dragState.lastDragEndedAt = Date.now();
      }
    }

    function resetPosition() {
      stopDrag();
      clearPosition();
      dragState.didMove = false;
      dragState.lastDragEndedAt = 0;
    }

    function handleMouseMove(event) {
      if (!owner.isActive()) { stopDrag(); return; }
      if (!dragState.isDragging) {
        return;
      }

      const deltaX = event.clientX - dragState.startMouseX;
      const deltaY = event.clientY - dragState.startMouseY;

      if (
        !dragState.didMove &&
        Math.abs(deltaX) < MOVE_THRESHOLD_PX &&
        Math.abs(deltaY) < MOVE_THRESHOLD_PX
      ) {
        return;
      }

      dragState.didMove = true;

      const nextPosition = clampPosition(
        dragState.startTop + deltaY,
        dragState.startLeft + deltaX
      );

      applyPosition(nextPosition.top, nextPosition.left);
    }

    function handleWindowResize() {
      if (!wrapper.classList.contains("aipkit-popup-dragged")) {
        return;
      }

      resetPosition();
    }

    function handleMouseDown(event) {
      if (event.button !== 0 || !isDragEligible()) {
        return;
      }

      const rect = container.getBoundingClientRect();
      const currentPosition = getCurrentPosition(rect);

      dragState.isDragging = true;
      dragState.didMove = false;
      dragState.startMouseX = event.clientX;
      dragState.startMouseY = event.clientY;
      dragState.startTop = currentPosition.top;
      dragState.startLeft = currentPosition.left;
      dragState.rectWidth = rect.width;
      dragState.rectHeight = rect.height;
      dragState.bodyCursor = document.body.style.cursor || "";
      dragState.bodyUserSelect = document.body.style.userSelect || "";

      wrapper.classList.add("aipkit-popup-dragging");
      document.body.style.cursor = "grabbing";
      document.body.style.userSelect = "none";

      document.addEventListener("mousemove", handleMouseMove);
      document.addEventListener("mouseup", stopDrag);
      window.addEventListener("blur", stopDrag);

      event.preventDefault();
      event.stopPropagation();
    }

    container._aipkitPopupDrag = {
      resetPosition,
      shouldIgnoreDocumentClick: () =>
        Date.now() - dragState.lastDragEndedAt < CLICK_SUPPRESS_MS,
    };

    owner.listen(dragZone, "mousedown", handleMouseDown);
    owner.listen(window, "resize", handleWindowResize);
    owner.onSuspend.push(resetPosition);
  }

  window.aipkit_chatUI_setupPopupDrag = aipkit_chatUI_setupPopupDrag;

  const CSS_VAR_NAMES = [
    "--aipkit-popup-mobile-top-offset",
    "--aipkit-popup-mobile-bottom-offset",
    "--aipkit-popup-mobile-height",
  ];

  function aipkit_chatUI_setupPopupMobileViewport(container, stateRef) {
    if (!container || !stateRef) {
      return;
    }

    const wrapper = container.closest(".aipkit_popup_wrapper");
    const isAdminPreview = !!container.closest(
      "#aipkit_admin_chat_preview_container"
    );

    if (!wrapper || isAdminPreview) {
      return;
    }

    const owner = getLifetime(container);
    if (!owner.once("viewport")) return;
    const viewport = window.visualViewport || null;

    function isMobileViewport() {
      return getPopupViewportWidth(viewport) <= MOBILE_BREAKPOINT;
    }

    function isPopupFullscreen() {
      return (
        stateRef.isFullscreen === true ||
        container.classList.contains("aipkit-fullscreen") ||
        wrapper.classList.contains("aipkit-fullscreen-wrapper")
      );
    }

    function clearViewportVars() {
      CSS_VAR_NAMES.forEach((name) => {
        container.style.removeProperty(name);
      });
    }

    function updateViewportVars() {
      if (!isMobileViewport() || isPopupFullscreen()) {
        clearViewportVars();
        return;
      }

      const layoutViewportHeight =
        window.innerHeight || document.documentElement.clientHeight || 0;
      const topOffset = viewport ? Math.max(0, Math.round(viewport.offsetTop || 0)) : 0;
      const height = viewport && viewport.height
        ? Math.max(0, Math.round(viewport.height))
        : Math.max(0, layoutViewportHeight);
      const bottomOffset = Math.max(
        0,
        Math.round(layoutViewportHeight - topOffset - height)
      );

      container.style.setProperty(
        "--aipkit-popup-mobile-top-offset",
        `${topOffset}px`
      );
      container.style.setProperty(
        "--aipkit-popup-mobile-bottom-offset",
        `${bottomOffset}px`
      );
      container.style.setProperty(
        "--aipkit-popup-mobile-height",
        `${height}px`
      );
    }

    const scheduleViewportUpdate = () => owner.frame("viewport", updateViewportVars);
    owner.listen(window, "resize", scheduleViewportUpdate, { passive: true });
    if (viewport) {
      owner.listen(viewport, "resize", scheduleViewportUpdate, { passive: true });
      owner.listen(viewport, "scroll", scheduleViewportUpdate, { passive: true });
    }
    for (const event of ["aipkit:popupOpened", "aipkit:popupClosed", "aipkit:fullscreenToggled"]) {
      owner.listen(container, event, scheduleViewportUpdate);
    }
    container._aipkitPopupMobileViewport = { refresh: scheduleViewportUpdate };
    owner.onSuspend.push(clearViewportVars);
    owner.onResume.push(scheduleViewportUpdate);
    scheduleViewportUpdate();
  }

  window.aipkit_chatUI_setupPopupMobileViewport =
    aipkit_chatUI_setupPopupMobileViewport;

    /**
     * Attaches event listeners specific to the popup functionality.
     * @param {HTMLElement} container - The chat container element (.aipkit_chat_container).
     * @param {HTMLElement} triggerButton - The button that triggers the popup.
     * @param {HTMLElement} messagesEl - The messages display element.
     * @param {HTMLElement} inputField - The input field element.
     * @param {object} stateRef - A reference to the shared state object { isPopupOpen }.
     * @param {object} config - The chatbot configuration object.
     */
    function aipkit_chatUI_setupPopupHandlers(container, triggerButton, messagesEl, inputField, stateRef, config) {
        if (!container || !triggerButton || !stateRef || !config) {
            console.warn("AIPKit Chat Popup Handlers: Missing elements for setup (container, trigger, stateRef, or config).");
            return;
        }
        // Ensure helper functions are globally available
        if (typeof window.aipkit_chatUI_openPopup !== 'function' || typeof window.aipkit_chatUI_closePopup !== 'function') {
            console.error("AIPKit Chat Popup Handlers: Missing openPopup or closePopup helper functions.");
            return;
        }

        const owner = getLifetime(container);
        if (!owner.once("handlers")) { owner.resume(); return owner; }
        try {
        const isAdminPreview = !!container.closest('#aipkit_admin_chat_preview_container');

        try {
            const wrapper = container.closest('.aipkit_popup_wrapper');
            const hintEl = wrapper ? wrapper.querySelector('.aipkit_popup_hint') : null;

            const isMobileViewport = () => getPopupViewportWidth(window.visualViewport) <= MOBILE_BREAKPOINT;

            // Helper: storage keys
            const version = (config.popupLabelVersion && String(config.popupLabelVersion).trim()) || 'v1';
            const seenKey = `aipkit_popup_hint_seen_${config.botId}_${version}`;
            const dismissedKey = `aipkit_popup_hint_dismissed_${config.botId}_${version}`;

            const allowedFrequencies = ['always', 'once_per_session', 'once_per_visitor'];
            const configuredFrequency = allowedFrequencies.includes(config.popupLabelFrequency)
                ? config.popupLabelFrequency
                : 'once_per_visitor';
            const frequency = isAdminPreview ? 'always' : configuredFrequency;
            const allowedModes = ['always', 'on_delay', 'until_open', 'until_dismissed'];
            const mode = allowedModes.includes(config.popupLabelMode) ? config.popupLabelMode : 'on_delay';
            const delayMs = (config.popupLabelDelaySeconds > 0 ? config.popupLabelDelaySeconds : 0) * 1000;
            const autoHideMs = (config.popupLabelAutoHideSeconds > 0 ? config.popupLabelAutoHideSeconds : 0) * 1000;
            const dismissible = !!config.popupLabelDismissible;

            // Storage helpers
            const store = (() => {
                if (frequency === 'always') {
                    return null;
                }
                return frequency === 'once_per_session' ? window.sessionStorage : window.localStorage;
            })();
            const safeGet = (key) => {
                if (!store) {
                    return null;
                }
                try {
                    return store.getItem(key);
                } catch (e) {
                    return null;
                }
            };
            const safeSet = (key, value) => {
                if (!store) {
                    return;
                }
                try {
                    store.setItem(key, value);
                } catch (e) {}
            };
            let runtimeSeen = false;
            let runtimeDismissed = false;
            const hasSeen = () => runtimeSeen || safeGet(seenKey) === '1';
            const hasDismissed = () => runtimeDismissed || safeGet(dismissedKey) === '1';

            const canShowByLifecycle = () => {
                if (mode === 'until_dismissed') {
                    return !hasDismissed();
                }
                if (mode === 'until_open') {
                    return !hasSeen();
                }
                if (frequency === 'always') {
                    return true;
                }
                return !hasSeen();
            };
            const markSeen = () => {
                runtimeSeen = true;
                safeSet(seenKey, '1');
            };
            const markDismissed = () => {
                runtimeDismissed = true;
                safeSet(dismissedKey, '1');
            };

            let resumeVisible = false;
            const setHintAccessibilityState = isHidden => aipkit_chatUI_setHiddenAccessibilityState(hintEl, isHidden);
            const clearShowTimeout = () => owner.cancel("hint-show");
            const clearHideTimeout = () => owner.cancel("hint-hide");
            const showHint = () => {
                if (!hintEl || hintEl.isConnected === false || !owner.isActive() || stateRef.isPopupOpen || !shouldRenderByViewport()) return;
                hintEl.removeAttribute('hidden');
                hintEl.classList.add('aipkit-visible');
                setHintAccessibilityState(false);
                if (mode === 'on_delay' || mode === 'always') {
                    markSeen();
                }
                if (autoHideMs > 0) {
                    clearHideTimeout();
                    owner.after("hint-hide", hideHint, autoHideMs);
                }
            };
            const hideHint = () => {
                if (!hintEl) return;
                hintEl.classList.remove('aipkit-visible');
                setHintAccessibilityState(true);
                hintEl.setAttribute('hidden', '');
                clearHideTimeout();
            };
            const shouldRenderByViewport = () => {
                const isMobile = isMobileViewport();
                if (isMobile && !config.popupLabelShowOnMobile) return false;
                if (!isMobile && !config.popupLabelShowOnDesktop) return false;
                return true;
            };
            const scheduleShow = () => {
                if (!hintEl || !owner.isActive()) return;
                if (!config.popupLabelEnabled || !config.popupLabelText) return;
                if (stateRef.isPopupOpen) return;
                if (!shouldRenderByViewport()) return;
                if (!canShowByLifecycle()) return;
                clearShowTimeout();
                const effectiveDelayMs = mode === 'always' ? 0 : delayMs;
                if (effectiveDelayMs <= 0) {
                    showHint();
                    return;
                }
                owner.after("hint-show", showHint, effectiveDelayMs);
            };
            const handlePopupOpen = () => {
                clearShowTimeout();
                hideHint();
                if (mode === 'until_open') {
                    markSeen();
                }
            };
            const dismissHintFromUser = () => {
                clearShowTimeout();
                hideHint();
                if (mode === 'until_dismissed') {
                    markDismissed();
                } else if (frequency !== 'always') {
                    // For other modes, consider it seen if frequency gating applies
                    markSeen();
                }
            };

            // Attach dismiss handler
            if (hintEl && dismissible) {
                const closeBtn = hintEl.querySelector('.aipkit_popup_hint_close');
                if (closeBtn) {
                    owner.listen(closeBtn, 'click', (e) => {
                        e.stopPropagation();
                        dismissHintFromUser();
                    });
                }
            }

            // Keep hidden state synchronized from the start for SR and keyboard users.
            hideHint();

            // Initial scheduling
            scheduleShow();

            owner.hint = {
                scheduleShow, handlePopupOpen, dismissHintFromUser,
                isDismissible: dismissible,
                isVisible: () => !!(hintEl && hintEl.classList.contains('aipkit-visible'))
            };
            owner.listen(container, 'aipkit:popupClosed', scheduleShow);
            owner.onSuspend.push(() => {
                resumeVisible = owner.hint.isVisible();
                clearShowTimeout();
                hideHint();
            });
            owner.onResume.push(() => {
                if (resumeVisible) showHint();
                else scheduleShow();
                resumeVisible = false;
            });
        } catch (err) {
            console.warn('AIPKit Popup Hint: setup error', err);
        }

        if (typeof window.aipkit_chatUI_setPopupLauncherState === 'function') {
            window.aipkit_chatUI_setPopupLauncherState(container, stateRef, !!stateRef.isPopupOpen);
        }

        if (typeof window.aipkit_chatUI_setupPopupMobileViewport === 'function') {
            window.aipkit_chatUI_setupPopupMobileViewport(container, stateRef);
        }

        const closePopupAndReshowHint = (restoreFocus = false) => {
            window.aipkit_chatUI_closePopup(container, stateRef);
            if (restoreFocus && typeof triggerButton.focus === 'function') {
                triggerButton.focus();
            } else if (typeof triggerButton.blur === 'function') {
                triggerButton.blur();
            }
        };

        owner.onSuspend.push(() => {
            container.classList.remove('aipkit-popup-closing');
            triggerButton.closest('.aipkit_popup_wrapper')?.classList.remove('aipkit-popup-closing');
        });

        // Close popup or dismiss hint on Escape.
        owner.listen(document, 'keydown', (event) => {
            if (event.key !== 'Escape' || event.defaultPrevented) {
                return;
            }
            if (stateRef.isPopupOpen) {
                closePopupAndReshowHint(true);
                return;
            }
            if (!owner.hint || !owner.hint.isDismissible) {
                return;
            }
            if (!owner.hint.isVisible?.()) {
                return;
            }
            owner.hint.dismissHintFromUser?.();
            if (typeof triggerButton.focus === 'function') {
                triggerButton.focus();
            }
        });

        const closeButton = container.querySelector('.aipkit_popup_close_btn');
        if (closeButton) {
            owner.listen(closeButton, 'click', (event) => {
                event.stopPropagation();
                if (stateRef.isPopupOpen) {
                    closePopupAndReshowHint();
                }
            });
        }

        if (typeof window.aipkit_chatUI_setupPopupDrag === 'function') {
            window.aipkit_chatUI_setupPopupDrag(container, stateRef, config);
        }

        // If direct voice mode is enabled, do not attach the popup toggle listener here.
        // The realtime voice initializer will attach its own handler to the trigger button.
        if (config.directVoiceMode) return owner;

        // Toggle popup on trigger click
        owner.listen(triggerButton, 'click', (e) => {
            e.stopPropagation();
            if (stateRef.isPopupOpen) {
                closePopupAndReshowHint(false);
            } else {
                window.aipkit_chatUI_openPopup(container, messagesEl, inputField, stateRef);
            }
        });

        // Close popup on outside click
        owner.listen(document, 'click', (event) => {
            if (!stateRef.isPopupOpen) {
                return;
            }
            if (
                container._aipkitPopupDrag &&
                typeof container._aipkitPopupDrag.shouldIgnoreDocumentClick === 'function' &&
                container._aipkitPopupDrag.shouldIgnoreDocumentClick()
            ) {
                return;
            }
            // Keep popup state stable in admin preview when users interact with builder controls.
            if (isAdminPreview) {
                return;
            }
            if (container.contains(event.target) || triggerButton.contains(event.target)) {
                return;
            }
            closePopupAndReshowHint(false);
        }, true);
        return owner;
        } catch (error) {
            owner.suspend();
            throw error;
        }
    }

    // Expose function to the global scope
    window.aipkit_chatUI_setupPopupHandlers = aipkit_chatUI_setupPopupHandlers;
})();
