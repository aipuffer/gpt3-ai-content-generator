(function () {
  "use strict";

  const AI_FORMS_MARKDOWN_OPTIONS = Object.freeze({
    html: false,
    xhtmlOut: false,
    breaks: true,
    linkify: true,
    typographer: true,
    quotes: "“”‘’",
  });

  function createFallbackRenderer() {
    if (typeof window.aipkit_createFallbackMarkdownRenderer === "function") {
      return window.aipkit_createFallbackMarkdownRenderer();
    }

    return {
      aipkitIsFallback: true,
      render: function (text) {
        const escaper =
          window.aipkit_escapeHtml ||
          function (str) {
            return str;
          };
        return escaper(String(text || "")).replace(/\n/g, "<br />");
      },
    };
  }

  function createRenderer() {
    if (typeof window.aipkit_createMarkdownRenderer === "function") {
      return window.aipkit_createMarkdownRenderer(AI_FORMS_MARKDOWN_OPTIONS);
    }

    return createFallbackRenderer();
  }

  function ensureRenderer() {
    if (typeof window.aipkit_ensureMarkdownRenderer === "function") {
      return window
        .aipkit_ensureMarkdownRenderer(AI_FORMS_MARKDOWN_OPTIONS)
        .then(function (renderer) {
          return renderer || createRenderer();
        })
        .catch(function () {
          return createRenderer();
        });
    }

    return Promise.resolve(createRenderer());
  }

  window.aipkitFormsMarkdown = {
    getOptions: function () {
      return AI_FORMS_MARKDOWN_OPTIONS;
    },
    createRenderer,
    ensureRenderer,
  };
})();
