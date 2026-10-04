// AIPKit UI Utils - Dismissible module notices
(function () {
  "use strict";

  const STORAGE_PREFIX = "aipkit_dismissed_notice_";

  const getStorageKey = (notice) => {
    const key = notice?.dataset?.aipkitDismissibleNotice;
    return key ? `${STORAGE_PREFIX}${key}` : "";
  };

  const isDismissed = (storageKey) => {
    if (!storageKey) return false;
    try {
      return window.localStorage.getItem(storageKey) === "1";
    } catch (error) {
      return false;
    }
  };

  const persistDismissal = (storageKey) => {
    if (!storageKey) return;
    try {
      window.localStorage.setItem(storageKey, "1");
    } catch (error) {
      // Ignore storage failures; the notice is still dismissed for this view.
    }
  };

  const hideNotice = (notice) => {
    if (!notice) return;
    notice.hidden = true;
    notice.style.display = "none";
  };

  function initDismissibleNotices(scope) {
    const root = scope || document;
    const notices = root.querySelectorAll("[data-aipkit-dismissible-notice]");
    notices.forEach((notice) => {
      const storageKey = getStorageKey(notice);
      if (isDismissed(storageKey)) {
        hideNotice(notice);
        return;
      }

      if (notice.dataset.aipkitDismissibleBound === "true") {
        return;
      }

      const closeButton = notice.querySelector("[data-aipkit-dismiss-notice]");
      if (!closeButton) {
        return;
      }

      closeButton.addEventListener("click", () => {
        persistDismissal(storageKey);
        hideNotice(notice);
      });
      notice.dataset.aipkitDismissibleBound = "true";
    });
  }

  window.aipkit_initDismissibleNotices = initDismissibleNotices;

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => initDismissibleNotices());
  } else {
    initDismissibleNotices();
  }
})();
