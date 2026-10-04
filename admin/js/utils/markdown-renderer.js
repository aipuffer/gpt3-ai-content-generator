/**
 * AIPKit Utilities - Markdown Renderer
 * Ensures a markdown-it instance is available for rendering.
 */
(function () {
  "use strict";
  /**
   * Ensures a global markdown-it instance is available.
   * If not already set, it initializes and stores it in window.aipkit_markdownitInstance.
   * @returns {object|null} The markdown-it instance or null.
   */
  function aipkit_getMarkdownRenderer() {
    if (window.aipkit_markdownitInstance) {
      return window.aipkit_markdownitInstance;
    }
    if (typeof window.markdownit === "function") {
      const newInstance = window.markdownit({
        // Allow raw HTML in Markdown so we can preserve any
        // AI-provided tags. We still sanitize in insertion code.
        html: true,
        xhtmlOut: false,
        breaks: true,
        linkify: true,
        typographer: true,
      });
      // Note: highlight.js is not a standard part of markdown-it, would need to be loaded separately.
      // This check is good practice.
      if (
        typeof window.hljs !== "undefined" &&
        typeof window.markdownitHighlight === "function"
      ) {
        newInstance.use(window.markdownitHighlight, { hljs: window.hljs });
      }
      window.aipkit_markdownitInstance = newInstance; // Set global
      return newInstance;
    } else {
      console.warn(
        "Markdown Renderer Utility: markdown-it library not found. Markdown rendering will be basic."
      );
      return null;
    }
  }
  window.aipkit_getMarkdownRenderer = aipkit_getMarkdownRenderer;
})();
