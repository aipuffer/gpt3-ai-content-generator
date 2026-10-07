// Page notices show one at a time: the most serious leads and the rest wait behind "n more" on it, in order of severity.
// The shell's notices (Cloud) and the module's own top notices form one stack. Each notice keeps its own
// rules for showing and dismissal; folding uses its own attribute so the two never mix.
(function () {
  "use strict";
  const RANK = { critical: 0, warning: 1, setup: 2, info: 3, promo: 4 };
  const toneOf = (notice) =>
    (notice.className.match(/aipkit_notification_bar--(critical|warning|setup|info|promo)\b/) || [])[1] || "info";
  const isShown = (notice) =>
    !notice.hidden && !notice.classList.contains("aipkit_provider_notice--hidden") && notice.style.display !== "none";
  const i18n = window.wp && window.wp.i18n ? window.wp.i18n : null;
  const __ = i18n?.__ || ((text) => text);
  const _n = i18n?._n || ((single, plural, count) => (count === 1 ? single : plural));
  const sprintf = i18n?.sprintf || ((format, ...args) => args.reduce((text, arg) => text.replace(/%[sd]/, arg), format));

  function createNoticeStack(wrap, moduleContainer = null) {
    if (!wrap) {
      return null;
    }
    let expanded = false;
    const toggle = document.createElement("button");
    toggle.type = "button";
    toggle.className = "aipkit_notification_bar__more";
    toggle.addEventListener("click", () => {
      expanded = !expanded;
      render();
    });

    const regions = [wrap, moduleContainer].filter(Boolean);
    const isNotice = (node) => node.classList?.contains("aipkit_notification_bar");
    // Shell notices keep stable references and anchors when a module is replaced.
    // Put them alongside module notices only when that module has a notice layout;
    // this preserves delegated module handlers and gives the whole stack one DOM order.
    const shell = new Map();
    const notices = () => [...new Set([...regions.flatMap(region => [...region.children].filter(isNotice)), ...shell.keys()])];
    const ranked = list => [...list].sort((a, b) => RANK[toneOf(a)] - RANK[toneOf(b)]);
    const observeRegions = () => regions.forEach(region => changes.observe(region, {childList: true}));

    const render = () => {
      changes.disconnect();
      [...wrap.children].filter(isNotice).forEach(notice => {
        if (!shell.has(notice)) {
          const anchor = document.createComment("shell notice");
          wrap.insertBefore(anchor, notice);
          shell.set(notice, anchor);
        }
      });
      const moduleNotices = moduleContainer ? [...moduleContainer.children].filter(node => isNotice(node) && !shell.has(node)) : [];
      if (!moduleNotices.length) {
        shell.forEach((anchor, notice) => {
          if (notice.parentNode !== wrap) anchor.after(notice);
        });
      }
      const region = moduleNotices.length ? moduleContainer : wrap;
      const order = ranked([...moduleNotices, ...shell.keys()]);
      // Insert before the first notice (or the module's workspace), without moving other content.
      const existing = [...region.children].filter(isNotice);
      if (order.length !== existing.length || order.some((notice, index) => notice !== existing[index])) {
        const first = existing[0] || region.firstChild;
        const marker = document.createComment("");
        region.insertBefore(marker, first);
        order.forEach(notice => region.insertBefore(notice, marker));
        marker.remove();
      }
      watch();
      observeRegions();
      const all = notices();
      const shown = all.filter(isShown);
      const lead = ranked(shown)[0] || null;
      all.forEach((notice) => {
        notice.toggleAttribute("data-aipkit-notice-folded", !expanded && notice !== lead && shown.includes(notice));
      });
      const more = shown.length - 1;
      if (!lead || more < 1) {
        expanded = false;
        toggle.remove();
        return;
      }
      const count = more.toLocaleString();
      toggle.setAttribute("aria-expanded", expanded ? "true" : "false");
      /* translators: %s: number of other notices. */
      toggle.innerHTML = `<span>${expanded ? __("Show less", "gpt3-ai-content-generator") : sprintf(_n("%s more", "%s more", more, "gpt3-ai-content-generator"), count)}</span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>`;
      const anchor = lead.querySelector(":scope > .aipkit_notification_bar__actions, :scope > .aipkit_notification_bar__action, :scope > .aipkit_notification_bar__close");
      if (toggle.parentNode !== lead || toggle.nextElementSibling !== anchor) {
        lead.insertBefore(toggle, anchor);
      }
    };

    // Notices come and go with module changes; each one shows or hides by its own attributes.
    const watched = new MutationObserver(render);
    const watch = () => {
      watched.disconnect();
      notices().forEach((notice) => watched.observe(notice, { attributes: true, attributeFilter: ["hidden", "class", "style"] }));
    };
    const changes = new MutationObserver(records => {
      // A single notice removed by its dismiss handler must not be restored like a
      // module replacement, which removes the module's workspace/notices as well.
      records.forEach(record => {
        const moduleReplaced = record.target === moduleContainer && [...record.removedNodes].some(node => !shell.has(node));
        if (moduleReplaced) return;
        [...record.removedNodes].forEach(node => {
          if (shell.has(node) && !node.parentNode) {
            shell.get(node).remove();
            shell.delete(node);
          }
        });
      });
      expanded = false;
      watch();
      render();
    });
    render();
    return {
      render,
      dispose() {
        watched.disconnect();
        changes.disconnect();
        toggle.remove();
        notices().forEach((notice) => notice.removeAttribute("data-aipkit-notice-folded"));
        shell.forEach((anchor, notice) => { anchor.after(notice); anchor.remove(); });
        shell.clear();
      },
    };
  }

  window.aipkit_createNoticeStack = createNoticeStack;
  const start = () => createNoticeStack(document.querySelector(".aipkit_wrap"), document.getElementById("aipkit_module-container"));
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start, { once: true });
  } else {
    start();
  }
})();
