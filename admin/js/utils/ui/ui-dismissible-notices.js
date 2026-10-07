// Operational notices remember the condition, not an unconditional dismissal.
(function () {
  "use strict";
  const storageKey = key => `aipkit_dismissed_notice_${key}`;
  const read = key => {
    try { return window.localStorage.getItem(storageKey(key)); } catch { return null; }
  };
  const write = (key, state) => {
    try {
      if (state === null) window.localStorage.removeItem(storageKey(key));
      else window.localStorage.setItem(storageKey(key), state);
    } catch { /* Dismissal still works for this view when storage is unavailable. */ }
  };
  function setNoticeState(notice, state) {
    const key = notice?.dataset?.aipkitDismissibleNotice;
    if (!key) return;
    notice.dataset.aipkitNoticeState = state || '';
    if (!state) {
      write(key, null);
      delete notice.dataset.aipkitDismissedState;
    }
    const hidden = !state || read(key) === state || notice.dataset.aipkitDismissedState === state;
    notice.hidden = hidden;
    notice.style.display = hidden ? 'none' : '';
  }
  function initDismissibleNotices(scope = document) {
    scope.querySelectorAll('[data-aipkit-notice-resolved]').forEach(marker =>
      write(marker.dataset.aipkitNoticeResolved, null));
    scope.querySelectorAll('[data-aipkit-dismissible-notice]').forEach(notice => {
      setNoticeState(notice, notice.dataset.aipkitNoticeState ?? 'active');
      if (notice.dataset.aipkitDismissibleBound === 'true') return;
      const close = notice.querySelector('[data-aipkit-dismiss-notice]');
      if (!close) return;
      close.addEventListener('click', () => {
        const state = notice.dataset.aipkitNoticeState;
        write(notice.dataset.aipkitDismissibleNotice, state);
        notice.dataset.aipkitDismissedState = state;
        setNoticeState(notice, state);
      });
      notice.dataset.aipkitDismissibleBound = 'true';
    });
  }
  window.aipkit_setDismissibleNoticeState = setNoticeState;
  window.aipkit_initDismissibleNotices = initDismissibleNotices;
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initDismissibleNotices());
  } else initDismissibleNotices();
})();
