/** AI Forms saved preview, on-demand assets and preview/exit controls. */
import { bindDialogKeydown } from "./editor-dialogs.js";

(function () {
  "use strict";

  const loadedCss = new Set();
  const scriptLoads = new Map();
  const previewLoads = new WeakMap();

  const getI18n = () =>
    typeof wp !== "undefined" && typeof wp.i18n?.__ === "function"
      ? wp.i18n.__ : (text) => text;
  const getContainer = (container) =>
    container || document.getElementById("aipkit_ai_forms_container");

  const escapeHtml = window.aipkit_escapeHtml || ((text) => text);

  const hasPreviewableStructure = (structure) => {
    if (!Array.isArray(structure)) {
      return false;
    }

    return structure.some((row) =>
      Array.isArray(row?.columns) &&
      row.columns.some(
        (column) => Array.isArray(column?.elements) && column.elements.length > 0
      )
    );
  };

  const aipkitForms_syncPreviewButtonState = (formsContainerElement) => {
    const container = getContainer(formsContainerElement);
    const previewButton = container?.querySelector(
      "#aipkit_preview_ai_form_btn"
    );
    const canPreview = hasPreviewableStructure(
      window.aipkitAIFormsState?.formStructure
    );

    if (previewButton) {
      previewButton.disabled = !canPreview;
    }

    return canPreview;
  };

  const aipkitForms_setEditorExitState = (
    formsContainerElement,
    state = "cancel"
  ) => {
    const __ = getI18n();
    const container = getContainer(formsContainerElement);
    const exitButton = container?.querySelector(
      "#aipkit_cancel_edit_ai_form_btn"
    );
    const exitText = exitButton?.querySelector(".aipkit_btn-text");
    if (!exitButton || !exitText) {
      return;
    }

    const isCommitted = state === "close";
    exitButton.dataset.aipkitExitState = isCommitted ? "close" : "cancel";
    exitText.textContent = isCommitted
      ? __("Close", "gpt3-ai-content-generator")
      : __("Cancel", "gpt3-ai-content-generator");
  };

  const loadCss = (url) => {
    if (!url || loadedCss.has(url)) return;
    if (document.querySelector(`link[href="${url}"]`)) {
      loadedCss.add(url);
      return;
    }
    const link = document.createElement("link");
    link.rel = "stylesheet";
    link.href = url;
    document.head.appendChild(link);
    loadedCss.add(url);
  };

  const loadJs = (url) => {
    if (!url) return Promise.resolve();
    if (scriptLoads.has(url)) return scriptLoads.get(url);
    const loading = new Promise((resolve, reject) => {
      if (document.querySelector(`script[src="${url}"]`)) {
        resolve();
        return;
      }
      const script = document.createElement("script");
      script.src = url;
      script.async = true;
      script.onload = () => resolve();
      script.onerror = () => {
        script.remove();
        scriptLoads.delete(url);
        reject(new Error(`Failed to load script: ${url}`));
      };
      document.body.appendChild(script);
    });
    scriptLoads.set(url, loading);
    return loading;
  };

  const setPreviewMessage = (container, message, type = "info") => {
    if (!container) return;
    const messageClass =
      type === "error"
        ? "aipkit_ai_forms_preview_message aipkit_ai_forms_preview_message--error"
        : "aipkit_ai_forms_preview_message";
    const spinnerHtml =
      type === "error"
        ? ""
        : '<span class="aipkit_spinner" aria-hidden="true"></span>';
    container.innerHTML = `
      <div class="${messageClass}">
        ${spinnerHtml}
        <span>${escapeHtml(message)}</span>
      </div>
    `;
  };

  const aipkitForms_loadFormPreview = (formId, previewContainer, options = {}) => {
    const __ = getI18n();
    const config = window.aipkit_ai_forms_config || {};
    const loadingText =
      options.loadingText || __("Loading preview...", "gpt3-ai-content-generator");

    if (!previewContainer) {
      return Promise.reject(new Error("Preview container not found."));
    }

    const request = {};
    previewLoads.set(previewContainer, request);
    const isCurrentPreview = () =>
      previewContainer.isConnected && previewLoads.get(previewContainer) === request;

    if (!formId) {
      setPreviewMessage(
        previewContainer,
        __("Missing form ID for preview.", "gpt3-ai-content-generator"),
        "error"
      );
      return Promise.reject(new Error("Form ID missing."));
    }

    if (typeof window.aipkit_apiRequest !== "function") {
      setPreviewMessage(
        previewContainer,
        __("Preview loader is unavailable.", "gpt3-ai-content-generator"),
        "error"
      );
      return Promise.reject(new Error("API request helper missing."));
    }

    setPreviewMessage(previewContainer, loadingText);

    return window
      .aipkit_apiRequest("aipkit_get_form_preview", {
        form_id: formId,
        _ajax_nonce: config.nonce_manage_forms,
      })
      .then((data) => {
        if (!isCurrentPreview()) return;
        if (data.assetUrls) {
          window.aipkitPublicAssetUrls = Object.assign(
            {},
            window.aipkitPublicAssetUrls || {},
            data.assetUrls
          );
        }
        if (data.config) {
          window.aipkit_ai_forms_public_config = data.config;
        }
        if (data.models) {
          window.aipkit_ai_forms_models = data.models;
        }
        if (data.assets && data.assets.css) {
          Object.values(data.assets.css).forEach(loadCss);
        }
        previewContainer.innerHTML = data.html || "";
        if (data.assets && data.assets.js) {
          return Promise.all(Object.values(data.assets.js).map(loadJs));
        }
        return null;
      })
      .then(() => {
        if (isCurrentPreview() && typeof window.aipkit_initAIFormsPublic === "function") {
          window.aipkit_initAIFormsPublic();
        }
      })
      .catch((error) => {
        const message = error && error.message ? error.message : "Unknown error.";
        if (isCurrentPreview()) setPreviewMessage(
          previewContainer,
          `${__("Preview failed:", "gpt3-ai-content-generator")} ${message}`,
          "error"
        );
        throw error;
      });
  };

  const getSheetElements = (formsContainerElement) => {
    const container = getContainer(formsContainerElement);
    if (!container) return null;
    const overlay = container.querySelector("#aipkit_ai_forms_preview_sheet");
    if (!overlay) return null;
    return {
      container,
      overlay,
      closeButton: overlay.querySelector("#aipkit_ai_forms_preview_sheet_close"),
      title: overlay.querySelector("#aipkit_ai_forms_preview_sheet_title"),
      description: overlay.querySelector(
        "#aipkit_ai_forms_preview_sheet_description"
      ),
      previewFrame: overlay.querySelector("#aipkit_ai_forms_preview_frame"),
    };
  };

  const aipkitForms_openPreviewSheet = (
    formId,
    formTitle,
    formsContainerElement
  ) => {
    const __ = getI18n();
    const elements = getSheetElements(formsContainerElement);
    if (!elements) return;

    if (elements.title) {
      elements.title.textContent = __("Preview", "gpt3-ai-content-generator");
    }
    if (elements.description) {
      elements.description.textContent = "";
      elements.description.removeAttribute("title");
      elements.description.setAttribute("hidden", "true");
    }

    elements.overlay.classList.add("aipkit-active");
    elements.overlay.setAttribute("aria-hidden", "false");

    if (elements.previewFrame) {
      aipkitForms_loadFormPreview(formId, elements.previewFrame).catch(() => {});
    }

    if (elements.closeButton) {
      elements.closeButton.focus();
    }
  };

  const aipkitForms_closePreviewSheet = (formsContainerElement) => {
    const elements = getSheetElements(formsContainerElement);
    if (!elements) return;
    elements.overlay.classList.remove("aipkit-active");
    elements.overlay.setAttribute("aria-hidden", "true");
    if (elements.previewFrame) {
      previewLoads.delete(elements.previewFrame);
      elements.previewFrame.innerHTML = "";
    }
  };

  const aipkitForms_initPreviewSheet = (formsContainerElement) => {
    const elements = getSheetElements(formsContainerElement);
    if (!elements || elements.overlay.dataset.previewSheetInitialized === "true") {
      return;
    }

    if (elements.closeButton) {
      elements.closeButton.addEventListener("click", (event) => {
        event.preventDefault();
        aipkitForms_closePreviewSheet(elements.container);
      });
    }

    elements.overlay.addEventListener("click", (event) => {
      if (event.target === elements.overlay) {
        aipkitForms_closePreviewSheet(elements.container);
      }
    });

    bindDialogKeydown(elements.overlay, (event) => {
      if (event.key === "Escape") {
        aipkitForms_closePreviewSheet(elements.container);
      }
    });

    elements.overlay.dataset.previewSheetInitialized = "true";
  };

  window.aipkitForms_loadFormPreview = aipkitForms_loadFormPreview;
  window.aipkitForms_openPreviewSheet = aipkitForms_openPreviewSheet;
  window.aipkitForms_closePreviewSheet = aipkitForms_closePreviewSheet;
  window.aipkitForms_initPreviewSheet = aipkitForms_initPreviewSheet;
  window.aipkitForms_syncPreviewButtonState =
    aipkitForms_syncPreviewButtonState;
  window.aipkitForms_setEditorExitState = aipkitForms_setEditorExitState;
})();
