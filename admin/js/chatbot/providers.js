const CONFIGURED_STATE_KEYS = new Map([
  ["openai", "openaiConfigured"],
  ["pinecone", "pineconeConfigured"],
  ["qdrant", "qdrantConfigured"],
  ["chroma", "chromaConfigured"],
  ["claude_files", "claudeConfigured"],
  ["google", "googleConfigured"],
]);
const MISSING_CREDENTIAL_MESSAGES = [
  "not configured", "api key is required", "api key is missing",
  "endpoint is required", "endpoint is missing",
  "connection url is required", "connection url is missing",
  "base url is required", "base url is missing",
];
const normalize = (value) => String(value || "").trim().toLowerCase();

/** Read only explicit status entries; callers choose the unknown-status policy. */
export function getChatbotProviderConfigured(providerKey, fallbackValue) {
  if (!providerKey) return fallbackValue;
  const statusMap = window.aipkit_dashboard &&
    window.aipkit_dashboard.providerStatus &&
    typeof window.aipkit_dashboard.providerStatus === "object"
    ? window.aipkit_dashboard.providerStatus : null;
  return statusMap && Object.prototype.hasOwnProperty.call(statusMap, providerKey)
    ? Boolean(statusMap[providerKey]) : fallbackValue;
}

export function resolveChatbotProviderConfigured(providerKey, fallbackValue = false) {
  return getChatbotProviderConfigured(normalize(providerKey), Boolean(fallbackValue));
}

export function isMissingProviderCredentialMessage(
  rawMessage = "",
  { includeUrl = false } = {}
) {
  const message = normalize(rawMessage);
  return MISSING_CREDENTIAL_MESSAGES.some((phrase) => message.includes(phrase)) ||
    Boolean(includeUrl && (message.includes("url is required") || message.includes("url is missing")));
}

function getVectorProviderReasonFromError(providerKey, rawMessage = "") {
  const provider = normalize(providerKey), message = normalize(rawMessage);
  if (provider === "qdrant" || provider === "chroma") {
    const mentionsUrl = message.includes(`${provider} url`) ||
      message.includes("connection url") || message.includes("url is required") ||
      message.includes("url is missing");
    if (provider === "chroma") return mentionsUrl ? "chroma-url" : "";
    const mentionsKey = message.includes("qdrant api key") ||
      message.includes("api key is required") || message.includes("api key is missing");
    if (mentionsUrl && !mentionsKey) return "qdrant-url";
    if (mentionsKey && !mentionsUrl) return "qdrant-key";
  }
  if (provider === "claude_files" &&
    message.includes("requires the chatbot provider to be claude")) {
    return "claude_files-provider";
  }
  return "";
}

export function createChatbotProviderConfig({
  builder, getContextSettingsContainer, getVectorStoreToggleValue,
  openaiApiKeySet, pineconeApiKeySet, qdrantApiKeySet, qdrantUrlSet,
  chromaUrlSet, googleApiKeySet, claudeApiKeySet,
}) {
  const getVectorProviderState = () => {
    const qdrantConfigured = resolveChatbotProviderConfigured("qdrant", qdrantApiKeySet && qdrantUrlSet);
    const chromaConfigured = resolveChatbotProviderConfigured("chroma", chromaUrlSet);
    return {
      openaiConfigured: resolveChatbotProviderConfigured("openai", openaiApiKeySet),
      pineconeConfigured: resolveChatbotProviderConfigured("pinecone", pineconeApiKeySet),
      qdrantConfigured,
      qdrantKeyAvailable: qdrantConfigured ? true : Boolean(qdrantApiKeySet),
      qdrantUrlAvailable: qdrantConfigured ? true : Boolean(qdrantUrlSet),
      chromaConfigured,
      chromaUrlAvailable: chromaConfigured ? true : Boolean(chromaUrlSet),
      claudeConfigured: resolveChatbotProviderConfigured("claude", claudeApiKeySet),
      googleConfigured: resolveChatbotProviderConfigured("google", googleApiKeySet),
    };
  };

  const getVectorProviderSelectionState = () => {
    const context = getContextSettingsContainer();
    const provider = context ? context.querySelector('[name="vector_store_provider"]') : null;
    const chatbot = builder.querySelector(".aipkit_chatbot_provider_select");
    return {
      vectorEnabled: getVectorStoreToggleValue() === "1",
      vectorProvider: provider ? String(provider.value || "") : "",
      chatbotProvider: chatbot ? String(chatbot.value || "") : "",
    };
  };

  const getVectorProviderConfigIssue = () => {
    const { vectorEnabled, vectorProvider, chatbotProvider } = getVectorProviderSelectionState();
    if (!vectorEnabled || !vectorProvider) return null;
    const state = getVectorProviderState();
    let reason = "";
    if (vectorProvider === "qdrant") {
      if (!state.qdrantKeyAvailable && !state.qdrantUrlAvailable) reason = "qdrant";
      else if (!state.qdrantKeyAvailable) reason = "qdrant-key";
      else if (!state.qdrantUrlAvailable) reason = "qdrant-url";
    } else if (vectorProvider === "chroma" && !state.chromaUrlAvailable) {
      reason = "chroma-url";
    } else if (
      (vectorProvider === "claude_files" && chatbotProvider !== "Claude") ||
      (vectorProvider === "google" && chatbotProvider !== "Google")
    ) {
      reason = `${vectorProvider}-provider`;
    }
    const stateKey = CONFIGURED_STATE_KEYS.get(vectorProvider);
    if (!reason && stateKey && !state[stateKey]) reason = vectorProvider;
    return reason ? { providerKey: vectorProvider, reason } : null;
  };

  return {
    getVectorProviderState,
    getVectorProviderSelectionState,
    getVectorProviderConfigIssue,
    getVectorProviderReasonFromError,
    isVectorProviderMissingConfigError(providerKey, rawMessage = "") {
      const provider = normalize(providerKey);
      if (!provider) return false;
      const state = getVectorProviderState();
      const stateKey = CONFIGURED_STATE_KEYS.get(provider);
      return Boolean(stateKey && !state[stateKey]) ||
        isMissingProviderCredentialMessage(rawMessage, { includeUrl: true });
    },
  };
}

/** Present Chatbot notices through the shared renderer or its class-only fallback. */
export function updateChatbotProviderNotice(notice, options) {
  if (!notice) return;
  if (typeof window.aipkit_updateProviderNotice === "function") {
    window.aipkit_updateProviderNotice(notice, options);
    return;
  }
  if (options.visible) {
    const message = notice.querySelector(".aipkit_provider_notice_message");
    if (message) message.textContent = options.message;
  }
  notice.classList[options.visible ? "remove" : "add"]("aipkit_provider_notice--hidden");
}

export function refreshChatbotProviderNotice(builder, getNotice) {
  if (typeof window.aipkit_initProviderKeyNotices === "function") {
    window.aipkit_initProviderKeyNotices(builder);
    return;
  }
  const notice = getNotice();
  if (notice) notice.classList.add("aipkit_provider_notice--hidden");
}

export function createChatbotProviderNotices({
  builder,
  imageGenerationRow,
  getContextSettingsContainer,
}) {
  const imageNotice = imageGenerationRow
    ? imageGenerationRow.querySelector(".aipkit_tools_image_provider_warning")
    : null;
  const imageMessage = imageNotice
    ? imageNotice.querySelector(".aipkit_provider_notice_message")
    : null;
  const getRequestNotice = () =>
    document.getElementById("aipkit_provider_notice_chatbot") ||
    builder.querySelector(".aipkit_provider_key_notice");

  const getVectorNotice = () => {
    const context = getContextSettingsContainer();
    const scoped = context
      ? context.querySelector(".aipkit_vector_provider_notice_chatbot")
      : null;
    if (scoped) return scoped;
    const notices = Array.from(
      builder.querySelectorAll(".aipkit_vector_provider_notice_chatbot")
    );
    return notices.find((notice) => notice && (
      notice.offsetParent !== null || notice.getClientRects().length > 0
    )) || notices[0] || null;
  };

  // Image notices also maintain hidden/ARIA state and retain their captured message node.
  const updateImageWarning = (visible, providerKey) => {
    if (!imageNotice) return;
    const options = visible ? {
      providerKey: normalize(providerKey),
      message: imageNotice.dataset.messageDefault ||
        "Connect an AI provider to generate images in this chatbot.",
      visible: true,
    } : { visible: false };
    if (typeof window.aipkit_updateProviderNotice === "function") {
      window.aipkit_updateProviderNotice(imageNotice, options);
    } else {
      if (visible && imageMessage) imageMessage.textContent = options.message;
      imageNotice.hidden = !visible;
      imageNotice.classList[visible ? "remove" : "add"]("aipkit_provider_notice--hidden");
    }
    imageNotice.setAttribute("aria-hidden", String(!visible));
  };

  return {
    showChatbotRequestErrorNotice(rawMessage) {
      const notice = getRequestNotice();
      if (!notice) return;
      updateChatbotProviderNotice(notice, {
        message: String(rawMessage || "").trim() || "Request failed. Please try again.",
        visible: true,
        actionVisible: false,
      });
    },
    clearChatbotRequestErrorNotice() {
      refreshChatbotProviderNotice(builder, getRequestNotice);
    },
    hideImageProviderWarning: () => updateImageWarning(false),
    showImageProviderWarning: (providerKey) => updateImageWarning(true, providerKey),
    setVectorProviderNoticeMessage(message = "", providerKey = "", actionVisible = true) {
      const notice = getVectorNotice();
      if (!notice) return;
      const nextMessage = String(message || "").trim();
      updateChatbotProviderNotice(notice, nextMessage ? {
        providerKey,
        message: nextMessage,
        visible: true,
        actionVisible,
      } : { visible: false });
    },
    getVectorProviderNotConfiguredMessage() {
      return String(getVectorNotice()?.getAttribute("data-message-default") ||
        "Connect an AI provider to use this knowledge storage.").trim();
    },
  };
}

/** Delegated provider field updates; registration does not read the DOM. */
const boundContainers = new WeakSet();

const settingsSelector = ".aipkit_chatbot-settings-area";

const settingRoutes = [
  [".aipkit_vector_store_toggle_switch", "aipkit_toggleVectorStoreSettingsVisibility"],
  [".aipkit_vector_store_provider_select", "aipkit_toggleVectorStoreProviderFields"],
  [".aipkit_vector_embedding_provider_select", "aipkit_populateVectorEmbeddingModelSelect"],
  [".aipkit_openai_web_search_enable_toggle", "aipkit_toggleOpenAIWebSearchSubSettings"],
  [".aipkit_openai_web_search_loc_type_select", "aipkit_toggleOpenAIWebSearchLocationDetails"],
];

function handleProviderChange(event) {
  const target = event.target;
  const settingsArea = target.closest(settingsSelector);
  if (!settingsArea) return;

  if (target.matches(".aipkit_chatbot_provider_select")) {
    window.aipkit_toggleChatbotModelFields(settingsArea);
    window.aipkit_toggleOpenAISpecificSettingsVisibility(settingsArea);
    if (typeof window.aipkit_toggleVectorStoreProviderFields === "function") {
      window.aipkit_toggleVectorStoreProviderFields(settingsArea);
    }
    return;
  }
  if (target.matches('select[name$="_model"], select[name$="_deployment"]')) {
    if (typeof window.aipkit_toggleReasoningEffort === "function") {
      window.aipkit_toggleReasoningEffort(settingsArea);
    }
    return;
  }

  const sttProviderSelect = target.closest(".aipkit_stt_provider_select");
  if (sttProviderSelect) {
    const audioArea = sttProviderSelect.closest(
      ".aipkit_builder_audio_settings_modal, .aipkit_chatbot-settings-area"
    );
    if (audioArea) window.aipkit_toggleSttModelFields(audioArea);
    return;
  }

  for (const [selector, hook] of settingRoutes) {
    if (target.closest(selector)) {
      window[hook](settingsArea);
      return;
    }
  }
}

function aipkit_initProviderSelectToggles() {
  const contentContainer = document.getElementById(
    "aipkit_chatbot_main_tab_content_container"
  );
  if (!contentContainer) {
    console.warn(
      "AIPKit Chat Fields: Main content container (#aipkit_chatbot_main_tab_content_container) not found for delegation."
    );
    return;
  }

  if (!boundContainers.has(contentContainer)) {
    contentContainer.addEventListener("change", handleProviderChange);
    boundContainers.add(contentContainer);
  }

  contentContainer.querySelectorAll(settingsSelector).forEach((area) => {
    if (area.querySelector(".aipkit_chatbot_provider_select")) {
      window.aipkit_toggleChatbotModelFields(area);
      window.aipkit_toggleOpenAISpecificSettingsVisibility(area);
    }
    if (area.querySelector(".aipkit_stt_provider_select")) {
      window.aipkit_toggleSttModelFields(area);
    }
    if (area.querySelector(".aipkit_vector_store_toggle_switch")) {
      window.aipkit_toggleVectorStoreSettingsVisibility(area);
    }
  });
}

window.aipkit_initProviderSelectToggles = aipkit_initProviderSelectToggles;
