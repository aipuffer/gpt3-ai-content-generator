(function () {
  "use strict";

  function initCloudAnnouncement() {
    const notice = document.querySelector("[data-aipkit-cloud-announcement]");
    if (!notice) return;

    let dismissed = false;
    let connected = Boolean(window.aipkit_dashboard?.cloudConnected);
    const updateVisibility = () => {
      // A provider warning folded behind "n more" (ui-notice-stack.js) still counts as showing.
      const setupVisible = Array.from(document.querySelectorAll('[data-aipkit-provider-notice]')).some(item =>
        !item.hidden && !item.classList.contains('aipkit_provider_notice--hidden')
        && (item.getClientRects().length > 0 || item.hasAttribute('data-aipkit-notice-folded')));
      notice.hidden = dismissed || connected || setupVisible;
    };
    window.addEventListener('aipkit:provider-notice-visibility', updateVisibility);
    const moduleContainer = document.getElementById('aipkit_module-container');
    if (moduleContainer) {
      // Only module replacement matters here; provider changes emit their own event.
      const observer = new MutationObserver(updateVisibility);
      observer.observe(moduleContainer, { childList: true });
      window.addEventListener('pagehide', () => observer.disconnect(), { once: true });
    }
    const connect = notice.querySelector("[data-cloud-announcement-connect]");
    const dismiss = notice.querySelector("[data-cloud-announcement-dismiss]");
    const feedback = notice.querySelector("[data-cloud-announcement-feedback]");

    connect.addEventListener("click", (event) => {
      if (typeof window.aipkit_openProviderConnection !== "function") return;
      event.preventDefault();
      window.aipkit_openProviderConnection(connect, null, { provider: "AIPufferCloud" });
    });

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

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initCloudAnnouncement, { once: true });
  } else {
    initCloudAnnouncement();
  }
})();
