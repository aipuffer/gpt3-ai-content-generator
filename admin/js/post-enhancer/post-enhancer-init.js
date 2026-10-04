/**
 * AIPKit Content Enhancer - Initialization
 * Orchestrates the setup for the post enhancer feature.
 */
(function () {
  "use strict";

  /**
   * Initializes the event listeners for the "AI Enhance" action and its dropdown items.
   */
  function aipkit_initPostEnhancer() {
    // Check for presence of at least one required dependency from each file group.
    const dependenciesOk = [
      "aipkit_handleTitleSuggestionsResult", // from handler-title.js
      "aipkit_handleExcerptSuggestionsResult", // from handler-excerpt.js
      "aipkit_handleMetaSuggestionsResult", // from handler-meta.js
      "aipkit_handleTagsSuggestionsResult", // from handler-tags.js
      "aipkit_initEnhancerEventListeners", // from enhancer-events.js
      "aipkit_initBulkEnhancerButton", // from enhancer-bulk-button.js
    ].every((func) => typeof window[func] === "function");

    if (!dependenciesOk) {
      console.error(
        "AIPKit Post Enhancer Init: One or more required modular functions are missing."
      );
      return;
    }

    // Call the initializers from the modular files.
    window.aipkit_initEnhancerEventListeners();
    window.aipkit_initBulkEnhancerButton();

    // --- Hover fix for row-action Assistant popover ---
    (function () {
      const HIDE_DELAY = 180; // ms
      const timeouts = new WeakMap();

      function open(container) {
        clearPending(container);
        container.classList.remove("aipkit-dismissed");
        container.classList.add("aipkit-open");
      }

      function scheduleClose(container) {
        clearPending(container);
        const t = setTimeout(() => {
          container.classList.remove("aipkit-open", "aipkit-dismissed");
        }, HIDE_DELAY);
        timeouts.set(container, t);
      }

      function clearPending(container) {
        const t = timeouts.get(container);
        if (t) {
          clearTimeout(t);
          timeouts.delete(container);
        }
      }

      function bindContainer(container) {
        if (!container || container.dataset.aipkitHoverBound === "1") return;
        container.dataset.aipkitHoverBound = "1";
        container.addEventListener("mouseenter", () => open(container));
        container.addEventListener("mouseleave", () => scheduleClose(container));
        const pop = container.querySelector(".aipkit_enhancer_popover");
        if (pop) {
          pop.addEventListener("mouseenter", () => open(container));
          pop.addEventListener("mouseleave", () => scheduleClose(container));
        }
      }

      function initHoverFix() {
        const nodes = document.querySelectorAll(".aipkit_enhancer_action");
        nodes.forEach(bindContainer);
        const obs = new MutationObserver((muts) => {
          for (const m of muts) {
            if (m.addedNodes && m.addedNodes.length) {
              m.addedNodes.forEach((n) => {
                if (n.nodeType !== 1) return;
                if (n.matches && n.matches(".aipkit_enhancer_action")) bindContainer(n);
                n.querySelectorAll && n.querySelectorAll(".aipkit_enhancer_action").forEach(bindContainer);
              });
            }
          }
        });
        obs.observe(document.body, { childList: true, subtree: true });
      }

      initHoverFix();
    })();
  }

  // --- Initialize ---
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", aipkit_initPostEnhancer);
  } else {
    aipkit_initPostEnhancer();
  }

  // Expose initializer globally (optional)
  window.aipkit_initPostEnhancer = aipkit_initPostEnhancer;
})();
