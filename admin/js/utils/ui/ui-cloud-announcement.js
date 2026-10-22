(function () {
  "use strict";

  // Recheck local eligibility on return/reopen, without replacing the modal or polling. Tabs exchange only an
  // invalidation; each fetch checks its own permission, nonce and per-user dismissal on the server.
  let tipRevision = 0;
  let tipRequest = null;
  let tipModule = '';
  let tipDirty = false;
  const hasTipSurface = () => Boolean(document.querySelector('[data-aipkit-website-tip-modal]')
    || (['content-writer', 'autogpt'].includes(tipModule) && document.querySelector('[data-aipkit-website-tip]')));
  const tipStorageKey = window.aipkit_dashboard?.ajaxurl
    ? `aipkit:website-tip:${window.aipkit_dashboard.ajaxurl}` : null;
  const applyTip = (payload) => {
    if (window.aipkit_post_enhancer) window.aipkit_post_enhancer.website_tip = payload;
    document.querySelectorAll?.('[data-aipkit-website-tip-modal]').forEach((row) => { row.hidden = !payload; });
    window.dispatchEvent?.(new CustomEvent('aipkit:website-tip-changed', { detail: { eligible: Boolean(payload) } }));
  };
  const refreshTip = () => {
    tipDirty = true;
    ++tipRevision;
    if (!hasTipSurface() || typeof window.aipkit_apiRequest !== 'function') return Promise.resolve();
    if (tipRequest) return tipRequest;
    tipRequest = (async () => {
      try {
        while (true) {
          const revision = tipRevision;
          const response = await window.aipkit_apiRequest('aipkit_get_website_tip');
          if (!hasTipSurface()) return;
          if (revision !== tipRevision) continue;
          if (!response || !Object.prototype.hasOwnProperty.call(response, 'tip') ||
              (response.tip !== null && (typeof response.tip !== 'object' || !response.tip.text))) return;
          applyTip(response.tip);
          tipDirty = false;
          return response.tip;
        }
      } catch (_) {
        // An unavailable promotion must not interrupt Content Assistant. Retry on the next return or open.
      } finally {
        tipRequest = null;
      }
    })();
    return tipRequest;
  };
  window.aipkitWebsiteTip = {
    refresh: refreshTip,
    dismissed() {
      ++tipRevision;
      applyTip(null);
      if (tipStorageKey) {
        try { window.localStorage.setItem(tipStorageKey, `${Date.now()}:${Math.random()}`); } catch (_) { /* Storage may be disabled. */ }
      }
    },
  };
  window.addEventListener('aipkit:module-rendered', (event) => {
    tipModule = event.detail?.module || '';
    // A focus/storage change on an unrelated screen waits until a tip can actually show.
    if (tipDirty && hasTipSurface()) void refreshTip();
  });
  window.addEventListener('focus', () => { void refreshTip(); });
  window.addEventListener('storage', (event) => {
    if (tipStorageKey && event.key === tipStorageKey && event.newValue) void refreshTip();
  });
  window.addEventListener('aipkit:website-management-changed', (event) => {
    ++tipRevision;
    if (event.detail?.enabled) applyTip(null);
    else void refreshTip();
  });

  function initCloudAnnouncement() {
    const notice = document.querySelector("[data-aipkit-cloud-announcement]");
    if (!notice) return;

    let dismissed = false;
    let connected = Boolean(window.aipkit_dashboard?.cloudConnected);
    // The shell renders it hidden. It is decided when a module renders, together with that
    // module's setup notice (module-loader.js), so a "Connect an AI provider" warning never
    // replaces it a moment after the page loads; provider changes emit their own event.
    let moduleRendered = !document.getElementById('aipkit_module-container');
    const updateVisibility = () => {
      // A provider warning folded behind "n more" (ui-notice-stack.js) still counts as showing.
      const setupVisible = Array.from(document.querySelectorAll('[data-aipkit-provider-notice]')).some(item =>
        !item.hidden && !item.classList.contains('aipkit_provider_notice--hidden')
        && (item.getClientRects().length > 0 || item.hasAttribute('data-aipkit-notice-folded')));
      notice.hidden = dismissed || connected || setupVisible || !moduleRendered;
    };
    window.addEventListener('aipkit:provider-notice-visibility', updateVisibility);
    window.addEventListener('aipkit:module-rendered', () => {
      moduleRendered = true;
      updateVisibility();
    });
    // Connect for free is a website opener (data-aipkit-cloud-open, settings/cloud-panel.js): it opens the website
    // sheet over the current workspace. Its link is the non-JavaScript fallback.
    const dismiss = notice.querySelector("[data-cloud-announcement-dismiss]");
    const feedback = notice.querySelector("[data-cloud-announcement-feedback]");

    dismiss.addEventListener("click", async () => {
      if (dismiss.disabled) return;
      dismiss.disabled = true;
      feedback.hidden = true;
      try {
        await window.aipkit_apiRequest("aipkit_dismiss_cloud_announcement");
        dismissed = true;
        updateVisibility();
      } catch (error) {
        feedback.textContent = error.message;
        feedback.hidden = false;
      } finally {
        dismiss.disabled = false;
      }
    });

    window.addEventListener("aipkit:cloud-connection-changed", (event) => {
      if (typeof event.detail?.connected !== "boolean") return;
      connected = event.detail.connected;
      updateVisibility();
    });
    updateVisibility();
  }

  // The website-management tip (Cloud\Announcement::render_website_tip): one shell notice with a line for Content
  // Writer and one for Automations. It shows the open screen's line, never beside the Cloud banner (the same
  // invitation), and goes once website management is set up here or the tip is dismissed (for every screen).
  function initWebsiteTip() {
    const tip = document.querySelector("[data-aipkit-website-tip]");
    if (!tip) return;

    let module = "";
    let dismissed = false;
    let eligible = true;
    const banner = document.querySelector("[data-aipkit-cloud-announcement]");
    const updateVisibility = () => {
      const lines = Array.from(tip.querySelectorAll("[data-aipkit-website-tip-for]"));
      const line = lines.find((item) => item.dataset.aipkitWebsiteTipFor === module) || null;
      lines.forEach((item) => { item.hidden = item !== line; });
      tip.hidden = dismissed || !eligible || !line || Boolean(banner && !banner.hidden);
    };
    window.addEventListener("aipkit:module-rendered", (event) => {
      module = event.detail?.module || "";
      updateVisibility();
    });
    window.addEventListener('aipkit:website-tip-changed', (event) => {
      eligible = Boolean(event.detail?.eligible);
      updateVisibility();
    });
    // The banner decides its own visibility (above); the tip follows it.
    if (banner) new MutationObserver(updateVisibility).observe(banner, { attributes: true, attributeFilter: ["hidden"] });
    const dismiss = tip.querySelector("[data-aipkit-website-tip-dismiss]");
    const feedback = tip.querySelector("[data-website-tip-feedback]");
    dismiss.addEventListener("click", async () => {
      if (dismiss.disabled) return;
      dismiss.disabled = true;
      feedback.hidden = true;
      try {
        await window.aipkit_apiRequest("aipkit_dismiss_website_tip");
        window.aipkitWebsiteTip.dismissed();
        dismissed = true;
        updateVisibility();
      } catch (error) {
        feedback.textContent = error.message;
        feedback.hidden = false;
      } finally {
        dismiss.disabled = false;
      }
    });
    updateVisibility();
  }

  const init = () => {
    initCloudAnnouncement();
    initWebsiteTip();
  };
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    init();
  }
})();
