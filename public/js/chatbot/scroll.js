/** Chatbot message scrolling, scroll button and their shared UI lifetime. */
function getDistanceFromBottom(messagesEl) {
  return messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight;
}

const owners = new WeakMap();
const requestFrame = callback => window.requestAnimationFrame
  ? window.requestAnimationFrame(callback) : window.setTimeout(callback, 16);
const cancelFrame = id => window.requestAnimationFrame
  ? window.cancelAnimationFrame(id) : window.clearTimeout(id);

function getOwner(messagesEl) {
  if (owners.has(messagesEl)) return owners.get(messagesEl);
  let elements, button, mutationObserver, resizeObserver;
  let active = true, frame = null, settleTimer = null, pendingScroll = null, needsOffset = false;

  function updateButton() {
    if (!button) return;
    const visible = messagesEl.scrollHeight > messagesEl.clientHeight + 4 && getDistanceFromBottom(messagesEl) > 48;
    button.classList.toggle("aipkit-scroll-visible", visible);
    button.setAttribute("aria-hidden", visible ? "false" : "true");
  }

  function cancelSettle() {
    if (settleTimer !== null) window.clearTimeout(settleTimer);
    settleTimer = null;
  }

  function isActive() {
    if (messagesEl.isConnected === false) suspend();
    return active;
  }

  function scheduleUpdate() {
    if (!isActive() || frame !== null) return;
    frame = requestFrame(() => {
      frame = null;
      if (!isActive()) return;
      if (pendingScroll !== null) {
        const shouldScroll = pendingScroll || getDistanceFromBottom(messagesEl) < 100;
        pendingScroll = null;
        if (shouldScroll) {
          messagesEl.scrollTop = messagesEl.scrollHeight;
          cancelSettle();
          settleTimer = window.setTimeout(() => {
            settleTimer = null;
            if (!isActive()) return;
            messagesEl.scrollTop = messagesEl.scrollHeight;
            updateButton();
          }, 50);
        }
      }
      if (needsOffset && elements) {
        needsOffset = false;
        const inputHeight = elements.inputArea ? Math.round(elements.inputArea.getBoundingClientRect().height) : 0;
        elements.mainContentEl.style.setProperty("--aipkit-chat-scroll-button-bottom-offset", `${Math.max(96, inputHeight + 12)}px`);
      }
      updateButton();
    });
  }

  function scroll(force = false) {
    if (!elements && messagesEl.isConnected !== false) active = true;
    if (!isActive()) return;
    cancelSettle();
    pendingScroll = pendingScroll === true || !!force;
    scheduleUpdate();
  }

  function resize() {
    needsOffset = true;
    scheduleUpdate();
  }

  function click() {
    scroll(true);
  }

  function suspend() {
    active = false;
    if (frame !== null) cancelFrame(frame);
    frame = null;
    cancelSettle();
    pendingScroll = null;
    mutationObserver?.disconnect();
    resizeObserver?.disconnect();
    messagesEl.removeEventListener("scroll", scheduleUpdate);
    button?.removeEventListener("click", click);
    window.removeEventListener("resize", resize);
  }

  function resume() {
    if (active || messagesEl.isConnected === false) return;
    active = true;
    messagesEl.addEventListener("scroll", scheduleUpdate, { passive: true });
    button?.addEventListener("click", click);
    if (typeof MutationObserver === "function") {
      mutationObserver ||= new MutationObserver(scheduleUpdate);
      mutationObserver.observe(messagesEl, { childList: true, subtree: true });
    }
    if (typeof ResizeObserver === "function") {
      resizeObserver ||= new ResizeObserver(resize);
      resizeObserver.observe(messagesEl);
      if (elements?.inputArea) resizeObserver.observe(elements.inputArea);
    } else {
      window.addEventListener("resize", resize);
    }
    resize();
  }

  function bind(nextElements) {
    if (elements?.mainContentEl === nextElements.mainContentEl && elements?.inputArea === nextElements.inputArea) {
      resume();
      return lifecycle;
    }
    suspend();
    elements = nextElements;
    button = elements.mainContentEl.querySelector(".aipkit_scroll_to_bottom_btn");
    if (!button) {
      button = document.createElement("button");
      button.type = "button";
      button.className = "aipkit_scroll_to_bottom_btn";
      button.setAttribute("aria-label", "Scroll to bottom");
      button.setAttribute("aria-hidden", "true");
      button.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 13l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
      elements.mainContentEl.appendChild(button);
    }
    resume();
    return lifecycle;
  }

  const lifecycle = { suspend, resume };
  const owner = { bind, scroll, update: scheduleUpdate };
  owners.set(messagesEl, owner);
  return owner;
}

window.aipkit_chatUI_scrollToBottom = (messagesEl, force = false) => {
  if (messagesEl && messagesEl.isConnected !== false) getOwner(messagesEl).scroll(force);
};
window.aipkit_chatUI_initScrollToBottomButton = elements => {
  if (elements?.mainContentEl && elements.messagesEl) return getOwner(elements.messagesEl).bind(elements);
};
window.aipkit_chatUI_updateScrollToBottomButton = messagesEl => owners.get(messagesEl)?.update();
