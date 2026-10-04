/**
 * AIPKit Chatbot - Optional Feature Loader
 *
 * Lazily loads heavyweight chat feature bundles so popup-only pages
 * do not pay for voice/upload/TTS/image-command/realtime code upfront.
 */
(function () {
  "use strict";

  const featureLoadPromises = Object.create(null);

  const FEATURE_BUNDLES = {
    sidebar: {
      urlKeys: ["chatSidebar", "sidebar"],
      styleUrlKey: "chatSidebarCss",
      isReady: () =>
        typeof window.aipkit_initConversationSidebar === "function",
    },
    stt: {
      urlKeys: ["chatStt", "stt"],
      isReady: () =>
        typeof window.aipkit_chatUI_handleVoiceInputAction === "function",
    },
    uploads: {
      urlKeys: ["chatUploads", "uploads"],
      isReady: () =>
        !!(
          window.chatImageUpload &&
          typeof window.chatImageUpload.init === "function"
        ),
    },
    fileUploads: {
      urlKeys: ["chatFileUploads"],
      styleUrlKey: "chatFileUploadsCss",
      isReady: () =>
        !!(
          window.aipkitChatFileUpload &&
          typeof window.aipkitChatFileUpload.processFile === "function"
        ),
    },
    consent: {
      urlKeys: ["chatConsent"],
      styleUrlKey: "chatConsentCss",
      isReady: () => typeof window.aipkit_initConsentUI === "function",
    },
    forms: {
      urlKeys: ["chatForms"],
      styleUrlKey: "chatFormsCss",
      isReady: () =>
        ["setFormGateState", "renderChatForm", "handleDisplayFormEvent", "handleChatFormSubmission", "restorePendingForm", "initForms"].every(
          name => typeof window[`aipkit_chatUI_${name}`] === "function"
        ),
    },
    tts: {
      urlKeys: ["chatTts", "tts"],
      isReady: () =>
        typeof window.aipkit_handlePlayAction === "function" &&
        !!window.aipkitTtsState,
    },
    starters: {
      urlKeys: ["chatStarters", "starters"],
      isReady: () => typeof window.aipkit_initConversationStarters === "function",
    },
    imageCommand: {
      urlKeys: ["chatImageCommand", "imageCommand"],
      isReady: () =>
        typeof window.aipkit_chatUI_handleImageGeneration === "function",
    },
    realtime: {
      urlKeys: ["chatRealtime", "realtime"],
      styleUrlKey: "chatRealtimeCss",
      isReady: () =>
        typeof window.aipkit_initRealtimeVoiceAgent === "function",
    },
    pdf: {
      urlKeys: ["chatPdf", "pdf"],
      isReady: () =>
        typeof window.aipkit_chatUI_downloadTranscriptActionPdf === "function",
    },
  };

  function getPublicAssetUrls() {
    return window.aipkitPublicAssetUrls &&
      typeof window.aipkitPublicAssetUrls === "object"
      ? window.aipkitPublicAssetUrls
      : {};
  }

  function resolveFeatureUrl(featureName) {
    const feature = FEATURE_BUNDLES[featureName];
    if (!feature) {
      return "";
    }

    const assetUrls = getPublicAssetUrls();
    for (const key of feature.urlKeys) {
      if (typeof assetUrls[key] === "string" && assetUrls[key]) {
        return assetUrls[key];
      }
    }

    return "";
  }

  function loadAssetByUrl(assetUrl, featureName, stylesheet = false) {
    return new Promise((resolve, reject) => {
      if (!assetUrl) {
        reject(
          new Error(
            `AIPKit Chat feature "${featureName}" is missing a public asset URL.`
          )
        );
        return;
      }

      let absoluteUrl = assetUrl;
      try {
        absoluteUrl = new URL(assetUrl, window.location.href).href;
      } catch (error) {
        absoluteUrl = assetUrl;
      }

      const handleLoad = () => resolve();
      const handleError = () =>
        reject(
          new Error(
            `AIPKit Chat feature "${featureName}" failed to load from ${assetUrl}.`
          )
        );

      const existingAsset = Array.from(
        document.querySelectorAll(stylesheet ? 'link[rel="stylesheet"][href]' : "script[src]")
      ).find((asset) => {
        try {
          return new URL(stylesheet ? asset.href : asset.src, window.location.href).href === absoluteUrl;
        } catch (error) {
          return (stylesheet ? asset.href : asset.src) === assetUrl;
        }
      });

      if (existingAsset) {
        if (existingAsset.dataset.aipkitErrored === "true") {
          existingAsset.remove();
        } else if (existingAsset.dataset.aipkitLoaded === "true" || (stylesheet && existingAsset.sheet)) {
          resolve();
          return;
        } else {
          existingAsset.addEventListener("load", () => {
            existingAsset.dataset.aipkitLoaded = "true";
            handleLoad();
          }, { once: true });
          existingAsset.addEventListener("error", () => {
            existingAsset.dataset.aipkitErrored = "true";
            handleError();
          }, { once: true });
          return;
        }
      }

      const asset = document.createElement(stylesheet ? "link" : "script");
      if (stylesheet) {
        asset.rel = "stylesheet";
        asset.href = assetUrl;
        asset.dataset.aipkitChatStyle = featureName;
      } else {
        asset.src = assetUrl;
        asset.async = true;
        asset.dataset.aipkitChatFeature = featureName;
      }
      asset.addEventListener(
        "load",
        () => {
          asset.dataset.aipkitLoaded = "true";
          handleLoad();
        },
        { once: true }
      );
      asset.addEventListener(
        "error",
        () => {
          asset.dataset.aipkitErrored = "true";
          handleError();
        },
        { once: true }
      );
      document.head.appendChild(asset);
    });
  }

  function aipkit_chatUI_ensureFeatureBundle(featureName) {
    const feature = FEATURE_BUNDLES[featureName];
    if (!feature) {
      return Promise.reject(
        new Error(`Unknown AIPKit chat feature bundle: ${featureName}`)
      );
    }

    if (feature.isReady() && !feature.styleUrlKey) {
      return Promise.resolve(true);
    }

    if (featureLoadPromises[featureName]) {
      return featureLoadPromises[featureName];
    }

    const featureUrl = resolveFeatureUrl(featureName);
    featureLoadPromises[featureName] = Promise.all([
      ...(feature.styleUrlKey ? [loadAssetByUrl(getPublicAssetUrls()[feature.styleUrlKey], featureName, true)] : []),
      ...(feature.isReady() ? [] : [loadAssetByUrl(featureUrl, featureName)]),
    ])
      .then(() => {
        if (!feature.isReady()) {
          throw new Error(
            `AIPKit chat feature "${featureName}" loaded, but expected globals were not registered.`
          );
        }
        return true;
      })
      .catch((error) => {
        delete featureLoadPromises[featureName];
        console.warn(
          `AIPKit Chat Feature Loader: Failed to prepare "${featureName}".`,
          error
        );
        throw error;
      });

    return featureLoadPromises[featureName];
  }

  async function aipkit_chatUI_prepareUploadsFeature(elements, config, uploadType = null) {
    if (!config || (!config.imageUploadEnabledUI && !config.fileUploadEnabledUI)) {
      return false;
    }

    const needsImage = config.imageUploadEnabledUI && uploadType !== "file";
    const needsFile = config.fileUploadEnabledUI && uploadType !== "image";
    if (!needsImage && !needsFile) return false;
    await Promise.all([
      ...(needsImage ? [aipkit_chatUI_ensureFeatureBundle("uploads")] : []),
      ...(needsFile ? [aipkit_chatUI_ensureFeatureBundle("fileUploads")] : []),
    ]);

    if (
      needsImage &&
      window.chatImageUpload &&
      typeof window.chatImageUpload.init === "function" &&
      elements &&
      elements.imageUploadInput &&
      elements.imagePreviewContainer
    ) {
      window.chatImageUpload.init(
        elements.imageUploadInput,
        elements.imagePreviewContainer
      );
    }

    if (elements && elements.container && needsImage) {
      elements.container.classList.add("aipkit-image-upload-enabled");
    }

    return true;
  }

  async function aipkit_chatUI_prepareRequiredFeatures(config) {
    await Promise.all([
      ...(config.requireConsentCompliance ? [aipkit_chatUI_ensureFeatureBundle("consent")] : []),
      ...(config.formGateEnabled ? [aipkit_chatUI_ensureFeatureBundle("forms")] : []),
    ]);
  }

  async function aipkit_chatUI_prepareSidebarFeature(
    container,
    elements,
    config,
    actions
  ) {
    if (
      !config ||
      !config.enableSidebar ||
      !container ||
      !elements ||
      !elements.sidebarEl ||
      !elements.sidebarToggleBtn
    ) {
      return false;
    }

    if (
      container.dataset.aipkitSidebarInitialized === "true" &&
      typeof window.aipkit_initConversationSidebar === "function"
    ) {
      return true;
    }

    await aipkit_chatUI_ensureFeatureBundle("sidebar");
    if (typeof window.aipkit_initConversationSidebar !== "function") {
      return false;
    }

    if (container.dataset.aipkitSidebarInitialized !== "true") {
      window.aipkit_initConversationSidebar(container, elements, config, actions);
      container.dataset.aipkitSidebarInitialized = "true";
    }

    return true;
  }

  async function aipkit_chatUI_prepareSttFeature() {
    await aipkit_chatUI_ensureFeatureBundle("stt");
    return typeof window.aipkit_chatUI_handleVoiceInputAction === "function";
  }

  async function aipkit_chatUI_prepareTtsFeature() {
    await aipkit_chatUI_ensureFeatureBundle("tts");
    return typeof window.aipkit_handlePlayAction === "function";
  }

  async function aipkit_chatUI_prepareStartersFeature(
    container,
    config,
    elements,
    sendMessageActionCallback,
    consentRequired,
    isConsentGivenFunc
  ) {
    if (!config || !config.enableStarters || !container) {
      return false;
    }

    await aipkit_chatUI_ensureFeatureBundle("starters");
    if (typeof window.aipkit_initConversationStarters !== "function") {
      return false;
    }

    if (container.dataset.aipkitStartersInitialized !== "true") {
      window.aipkit_initConversationStarters(
        container,
        config,
        elements,
        sendMessageActionCallback,
        consentRequired,
        isConsentGivenFunc
      );
      container.dataset.aipkitStartersInitialized = "true";
      return true;
    }

    if (
      elements &&
      elements.startersContainer &&
      typeof window.aipkit_chatUI_refreshStartersAnimation === "function" &&
      !elements.startersContainer.classList.contains("aipkit_hidden")
    ) {
      window.aipkit_chatUI_refreshStartersAnimation(elements.startersContainer);
    }

    return true;
  }

  async function aipkit_chatUI_prepareImageCommandFeature() {
    await aipkit_chatUI_ensureFeatureBundle("imageCommand");
    return typeof window.aipkit_chatUI_handleImageGeneration === "function";
  }

  async function aipkit_chatUI_preparePdfFeature(config) {
    if (!config || config.pdfDownloadActive !== true) {
      return false;
    }

    await aipkit_chatUI_ensureFeatureBundle("pdf");

    return typeof window.aipkit_chatUI_downloadTranscriptActionPdf === "function";
  }

  async function aipkit_chatUI_prepareRealtimeFeature(
    elements,
    config,
    state,
    actions,
    publicApi
  ) {
    if (!config || !config.enableRealtimeVoiceUI || !elements || !elements.container) {
      return false;
    }

    if (
      elements.container.dataset.aipkitRealtimeInitialized === "true" &&
      typeof window.aipkit_initRealtimeVoiceAgent === "function"
    ) {
      return true;
    }

    await aipkit_chatUI_ensureFeatureBundle("realtime");
    if (typeof window.aipkit_initRealtimeVoiceAgent !== "function") {
      return false;
    }

    if (elements.container.dataset.aipkitRealtimeInitialized !== "true") {
      state.realtimeLifecycle = window.aipkit_initRealtimeVoiceAgent(
        elements,
        config,
        state,
        actions,
        publicApi
      );
      elements.container.dataset.aipkitRealtimeInitialized = "true";
    }

    return true;
  }

  window.aipkit_chatUI_ensureFeatureBundle = aipkit_chatUI_ensureFeatureBundle;
  window.aipkit_chatUI_prepareRequiredFeatures = aipkit_chatUI_prepareRequiredFeatures;
  window.aipkit_chatUI_prepareUploadsFeature =
    aipkit_chatUI_prepareUploadsFeature;
  window.aipkit_chatUI_prepareSidebarFeature =
    aipkit_chatUI_prepareSidebarFeature;
  window.aipkit_chatUI_prepareSttFeature = aipkit_chatUI_prepareSttFeature;
  window.aipkit_chatUI_prepareTtsFeature = aipkit_chatUI_prepareTtsFeature;
  window.aipkit_chatUI_prepareStartersFeature =
    aipkit_chatUI_prepareStartersFeature;
  window.aipkit_chatUI_prepareImageCommandFeature =
    aipkit_chatUI_prepareImageCommandFeature;
  window.aipkit_chatUI_preparePdfFeature = aipkit_chatUI_preparePdfFeature;
  window.aipkit_chatUI_prepareRealtimeFeature =
    aipkit_chatUI_prepareRealtimeFeature;
})();
