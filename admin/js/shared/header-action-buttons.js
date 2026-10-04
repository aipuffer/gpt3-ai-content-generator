(function () {
  if (window.aipkitHeaderButtonsInitialized) {
    return;
  } // prevent double run
  window.aipkitHeaderButtonsInitialized = true;

  const BUTTON_CONTAINER_CLASS = "aipkit-inline-actions";
  const DATA_KEY = "aipkitHeaderButtons";
  const MAX_WAIT_MS = 15000; // stop observing after this
  const CHECK_INTERVAL = 250; // fallback interval

  function getButtonsData() {
    try {
      return Array.isArray(window[DATA_KEY]) ? window[DATA_KEY] : [];
    } catch (e) {
      return [];
    }
  }

  function ensureContainer() {
    let existing = document.querySelector("." + BUTTON_CONTAINER_CLASS);
    if (existing) {
      return existing;
    }
    const titleEl = document.querySelector(
      ".wp-header-end, .wrap h1.wp-heading-inline"
    );
    if (!titleEl) {
      return null;
    }

    // Anchor reference: try to find Add New button sibling
    const addNew = document.querySelector(".wrap .page-title-action");

    const span = document.createElement("span");
    span.className = BUTTON_CONTAINER_CLASS;

    if (addNew && addNew.parentNode) {
      addNew.parentNode.insertBefore(span, addNew.nextSibling);
    } else if (titleEl.parentNode) {
      titleEl.parentNode.insertBefore(span, titleEl.nextSibling);
    }
    return span;
  }

  function buttonAlreadyPlaced(id) {
    return !!document.getElementById(id);
  }

  function createButtonEl(btn) {
    // Use anchor to match core styling behavior (same classes as Add New)
    const a = document.createElement("a");
    a.id = btn.id;
    a.className = "page-title-action";
    a.href = btn.href ? btn.href : "javascript:void(0)";
    a.textContent = btn.label || btn.id;
    if (btn.title) {
      a.title = btn.title;
    }
    if (btn.dataset) {
      Object.keys(btn.dataset).forEach((k) => {
        a.dataset[k] = btn.dataset[k];
      });
    }
    if (btn.onClick) {
      a.addEventListener("click", function (e) {
        if (btn.href === undefined) {
          e.preventDefault();
        }
        try {
          new Function(btn.onClick).call(this, e);
        } catch (err) {
          console.error(err);
        }
      });
    }
    return a;
  }

  function injectButtons() {
    const data = getButtonsData();
    if (!data.length) {
      return false;
    }
    const container = ensureContainer();
    if (!container) {
      return false;
    }

    data.forEach((btn) => {
      if (!btn.id) {
        return;
      }
      if (buttonAlreadyPlaced(btn.id)) {
        return;
      }
      const el = createButtonEl(btn);
      container.appendChild(el);
    });
    // Return whether all desired buttons are now present
    const allPresent = data.every((b) => b.id && buttonAlreadyPlaced(b.id));
    return allPresent;
  }

  function init() {
    injectButtons();
    const start = Date.now();

    const target =
      document.querySelector("#wpbody-content") ||
      document.querySelector(".wrap") ||
      document.body;
    const observer = new MutationObserver(() => {
      const done = injectButtons();
      if (done || Date.now() - start > MAX_WAIT_MS) {
        observer.disconnect();
      }
    });

    if (target) {
      observer.observe(target, { childList: true, subtree: true });
    }

    // Fallback interval in case observer misses (shouldn't) or early loads
    const interval = setInterval(() => {
      const done = injectButtons();
      if (done || Date.now() - start > MAX_WAIT_MS) {
        clearInterval(interval);
      }
    }, CHECK_INTERVAL);

    window.addEventListener("load", injectButtons);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
