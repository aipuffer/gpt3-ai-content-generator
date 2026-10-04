(function () {
  "use strict";

  function initCloudAnnouncement() {
    const notice = document.querySelector("[data-aipkit-cloud-announcement]");
    if (!notice) return;

    let dismissed = false;
    let connected = Boolean(window.aipkit_dashboard?.cloudConnected);
    const updateVisibility = () => { notice.hidden = dismissed || connected; };
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
