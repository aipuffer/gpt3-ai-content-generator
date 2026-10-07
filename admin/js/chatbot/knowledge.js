/** Chatbot Knowledge capabilities, source management and training presentation. */
const NATIVE_KNOWLEDGE_PROVIDER_REQUIREMENTS = Object.freeze({
  google: "Google",
  claude_files: "Claude",
});

const NATIVE_FILE_UPLOAD_PROVIDERS = Object.freeze({
  OpenAI: "openai",
  Google: "google",
  Claude: "claude_files",
});

const TARGETED_KNOWLEDGE_PROVIDERS = new Set([
  "openai",
  "google",
  "pinecone",
  "qdrant",
  "chroma",
  "local",
]);

const EMBEDDING_KNOWLEDGE_PROVIDERS = new Set([
  "pinecone",
  "qdrant",
  "chroma",
  "local",
]);
const SUPPORTED_KNOWLEDGE_PROVIDERS = new Set([
  ...TARGETED_KNOWLEDGE_PROVIDERS,
  "claude_files",
]);

const isEnabled = (value) =>
  value === true || value === 1 || value === "1" || value === "true";

const normalizeChatbotProvider = (value) => String(value || "").trim();
const normalizeKnowledgeProvider = (value) =>
  String(value || "").trim().toLowerCase();

const getRequiredChatbotProviderForKnowledge = (knowledgeProvider) =>
  NATIVE_KNOWLEDGE_PROVIDER_REQUIREMENTS[
    normalizeKnowledgeProvider(knowledgeProvider)
  ] || "";

export const isKnowledgeProviderCompatible = (
  chatbotProvider,
  knowledgeProvider
) => {
  const requiredProvider =
    getRequiredChatbotProviderForKnowledge(knowledgeProvider);
  return (
    !requiredProvider ||
    normalizeChatbotProvider(chatbotProvider) === requiredProvider
  );
};

export const getKnowledgeProviderCompatibilityMessage = (
  chatbotProvider,
  knowledgeProvider
) => {
  const normalizedKnowledgeProvider =
    normalizeKnowledgeProvider(knowledgeProvider);
  if (
    isKnowledgeProviderCompatible(chatbotProvider, normalizedKnowledgeProvider)
  ) {
    return "";
  }
  if (normalizedKnowledgeProvider === "google") {
    return "Google knowledge requires Google as the chatbot provider.";
  }
  if (normalizedKnowledgeProvider === "claude_files") {
    return "Anthropic Files requires Anthropic as the chatbot provider.";
  }
  return "";
};

/** Keep an existing knowledge destination; otherwise initialize persistent sources from the chatbot. */
export const resolveChatbotSourceSetup = ({
  knowledgeEnabled = false,
  knowledgeProvider = "",
  knowledgeHasTarget = false,
  defaults = {},
} = {}) => {
  const provider = normalizeKnowledgeProvider(knowledgeProvider);
  const useExisting = provider && provider !== "claude_files" && (
    isEnabled(knowledgeEnabled) || knowledgeHasTarget ||
    ["pinecone", "qdrant", "chroma"].includes(provider)
  );
  return {
    provider: useExisting ? provider : defaults.vector_store_provider || "",
    useDefaults: !useExisting,
  };
};

/**
 * Derives the effective Chatbot Knowledge and file-upload state from the
 * current draft settings. Stored inventory and runtime activation are kept
 * separate so turning Knowledge off never implies that sources were deleted.
 */
export const deriveChatbotCapabilityState = ({
  chatbotProvider = "",
  knowledgeEnabled = false,
  knowledgeProvider = "",
  knowledgeHasTarget = false,
  knowledgeHasEmbedding = false,
  fileUploadEnabled = false,
  isProPlan = false,
  googleSearchEnabled = false,
} = {}) => {
  const normalizedChatbotProvider = normalizeChatbotProvider(chatbotProvider);
  const normalizedKnowledgeProvider =
    normalizeKnowledgeProvider(knowledgeProvider);
  const knowledgeRequested = isEnabled(knowledgeEnabled);
  const fileUploadRequested = isEnabled(fileUploadEnabled);
  const googleSearchRequested = isEnabled(googleSearchEnabled);
  const knowledgeCompatible = isKnowledgeProviderCompatible(
    normalizedChatbotProvider,
    normalizedKnowledgeProvider
  );
  const knowledgeRequiresTarget = TARGETED_KNOWLEDGE_PROVIDERS.has(
    normalizedKnowledgeProvider
  );
  const knowledgeProviderKnown = SUPPORTED_KNOWLEDGE_PROVIDERS.has(
    normalizedKnowledgeProvider
  );
  const knowledgeTargetReady =
    knowledgeProviderKnown &&
    (!knowledgeRequiresTarget || Boolean(knowledgeHasTarget));
  const knowledgeRequiresEmbedding = EMBEDDING_KNOWLEDGE_PROVIDERS.has(
    normalizedKnowledgeProvider
  );
  const knowledgeEmbeddingReady =
    !knowledgeRequiresEmbedding || Boolean(knowledgeHasEmbedding);
  const knowledgeConfigured =
    knowledgeProviderKnown && knowledgeTargetReady && knowledgeEmbeddingReady;
  const knowledgeRuntimeActive =
    knowledgeRequested && knowledgeCompatible && knowledgeConfigured;

  let knowledgeRuntimeStatus = "off";
  if (knowledgeRequested && !knowledgeCompatible) {
    knowledgeRuntimeStatus = "incompatible";
  } else if (knowledgeRequested && !knowledgeConfigured) {
    knowledgeRuntimeStatus = "setup_required";
  } else if (knowledgeRuntimeActive) {
    knowledgeRuntimeStatus = "active";
  }

  let fileUploadProvider = "";
  if (knowledgeRequested && knowledgeCompatible) {
    if (normalizedKnowledgeProvider === "openai") {
      fileUploadProvider = "openai";
    } else if (
      EMBEDDING_KNOWLEDGE_PROVIDERS.has(normalizedKnowledgeProvider) &&
      knowledgeTargetReady &&
      knowledgeEmbeddingReady
    ) {
      fileUploadProvider = normalizedKnowledgeProvider;
    } else if (
      normalizedKnowledgeProvider === "google" ||
      normalizedKnowledgeProvider === "claude_files"
    ) {
      fileUploadProvider = normalizedKnowledgeProvider;
    }
  }
  if (!fileUploadProvider) {
    fileUploadProvider =
      NATIVE_FILE_UPLOAD_PROVIDERS[normalizedChatbotProvider] || "";
  }

  const googleToolConflict =
    knowledgeRequested &&
    knowledgeCompatible &&
    normalizedKnowledgeProvider === "google" &&
    googleSearchRequested;

  return {
    chatbotProvider: normalizedChatbotProvider,
    knowledgeProvider: normalizedKnowledgeProvider,
    requiredChatbotProvider:
      getRequiredChatbotProviderForKnowledge(normalizedKnowledgeProvider),
    knowledgeRequested,
    knowledgeCompatible,
    knowledgeConfigured,
    knowledgeHasInventoryTarget: knowledgeTargetReady,
    knowledgeRuntimeActive,
    knowledgeRuntimeStatus,
    shouldDeactivateKnowledgeForProviderSwitch:
      knowledgeRequested && !knowledgeCompatible,
    shouldDeactivateKnowledgeForGoogleSearch: googleToolConflict,
    shouldDisableGoogleSearchForKnowledge: googleToolConflict,
    googleSearchRequested,
    googleSearchEffective: googleSearchRequested && !googleToolConflict,
    fileUploadRequested,
    fileUploadProvider,
    fileUploadAvailable: Boolean(isProPlan && fileUploadProvider),
    fileUploadEffective: Boolean(
      isProPlan && fileUploadRequested && fileUploadProvider
    ),
  };
};

/** Chatbot training status, count report and action presentation. No job requests. */
export function createChatbotTrainingStatus({
  builder, __, trainingMainAddButton, trainingSheetAddButton,
  getActiveTrainingTabKey, isOtherTrainingTab, getIsTraining, clearWebsiteTask,
  _n = (single, plural, count) => (count === 1 ? single : plural),
}) {
  const trainingState = builder.querySelector("[data-aipkit-training-state]");
  const trainingStateValue = builder.querySelector(
    "[data-aipkit-training-state-value]"
  );
  const trainingStateMenu = builder.querySelector(
    "[data-aipkit-training-state-menu]"
  );
  const trainingStateReportStatus = builder.querySelector(
    "[data-aipkit-training-report-status]"
  );
  const trainingStateReportTrained = builder.querySelector(
    "[data-aipkit-training-report-trained]"
  );
  const trainingStateReportProcessing = builder.querySelector(
    "[data-aipkit-training-report-processing]"
  );
  const trainingStateReportFailed = builder.querySelector(
    "[data-aipkit-training-report-failed]"
  );
  const trainingStateProcessingMetric = trainingStateReportProcessing?.closest(
    ".aipkit_training_state_metric"
  );
  const trainingStateFailedMetric = trainingStateReportFailed?.closest(
    ".aipkit_training_state_metric"
  );
  const trainingStopButton = builder.querySelector(
    "[data-aipkit-stop-training]"
  );
  let inventorySummary = { text: "", total: 0 };
  let durableTrainingStatusKey = "";
  let trainingStatusSnapshot = {
    count: 0,
    queue: {
      pending: 0,
      processing: 0,
      failed: 0,
      active: 0,
    },
    training_status: null,
  };
  // Filters first: changing them refreshes the list, which then opens already filtered.
  const openSources = ({ type = "", status = "" } = {}) => {
    [["aipkit_chatbot_sources_type", type], ["aipkit_chatbot_sources_status_filter", status]].forEach(([id, value]) => {
      const field = document.getElementById(id);
      if (field && field.value !== value) {
        field.value = value;
        field.dispatchEvent(new Event("change", { bubbles: true }));
      }
    });
    builder.querySelector(".aipkit_training_sources_btn")?.click();
  };
  // Labels translate when shown, so building the status reads nothing early.
  const knowledgeStripActions = {
    stop: { label: () => __("Stop", "gpt3-ai-content-generator"), run: () => trainingStopButton?.click() },
    review: { label: () => __("Review", "gpt3-ai-content-generator"), run: () => openSources({ status: "failed" }) },
    "turn-on": {
      label: () => __("Turn on", "gpt3-ai-content-generator"),
      run: () => {
        const toggle = builder.querySelector(".aipkit_vector_store_enable_select");
        if (toggle && !toggle.checked) {
          toggle.checked = true;
          toggle.dispatchEvent(new Event("change", { bubbles: true }));
        }
      },
    },
    settings: {
      label: () => __("Search settings", "gpt3-ai-content-generator"),
      run: () => builder.querySelector('[data-aipkit-feature-open="search"]')?.click(),
    },
  };
  /** One plain sentence on what answers use, from the durable status, the queue and the grouped sources. */
  let knowledgeStrip = null;
  const renderKnowledgeStrip = () => {
    // Looked up when first drawn; the card renders after this status object is built.
    knowledgeStrip = knowledgeStrip || builder.querySelector("[data-aipkit-knowledge-strip]") || null;
    const knowledgeStripText = knowledgeStrip?.querySelector?.("[data-aipkit-knowledge-strip-text]");
    if (!knowledgeStrip || !knowledgeStripText) {
      return;
    }
    const knowledgeStripAction = knowledgeStrip.querySelector("[data-aipkit-knowledge-strip-action]");
    if (knowledgeStripAction && !knowledgeStripAction.dataset.bound) {
      knowledgeStripAction.dataset.bound = "1";
      knowledgeStripAction.addEventListener("click", (event) => {
        event.preventDefault();
        knowledgeStripActions[knowledgeStripAction.dataset.action]?.run();
      });
    }
    const queue = trainingStatusSnapshot.queue || {};
    const failed = Math.max(Number(queue.failed || 0), inventorySummary.failed || 0);
    const waiting = Number(queue.pending || 0) + Number(queue.processing || 0);
    const hasSources = Number(inventorySummary.total) > 0;
    const noKnowledge = __("No knowledge yet. It answers from the AI model only.", "gpt3-ai-content-generator");
    let tone = "neutral";
    let text = noKnowledge;
    let action = "";
    switch (durableTrainingStatusKey) {
      case "":
      case "checking":
        // Refreshes check every few seconds; keep the last sentence instead of flickering.
        if (!knowledgeStrip.hidden) {
          return;
        }
        text = __("Checking knowledge...", "gpt3-ai-content-generator");
        break;
      case "training":
        tone = "adding";
        text = waiting > 0
          ? _n("Adding knowledge. %d item left.", "Adding knowledge. %d items left.", waiting, "gpt3-ai-content-generator").replace("%d", String(waiting))
          : __("Adding knowledge...", "gpt3-ai-content-generator");
        action = trainingStopButton ? "stop" : "";
        break;
      case "trained":
        if (failed > 0) {
          tone = "warn";
          text = _n("%d item couldn't be added.", "%d items couldn't be added.", failed, "gpt3-ai-content-generator").replace("%d", String(failed));
          action = "review";
        } else {
          tone = "ready";
          text = inventorySummary.text
            ? __("Ready. It answers from %s.", "gpt3-ai-content-generator").replace("%s", inventorySummary.text)
            : __("Ready.", "gpt3-ai-content-generator");
        }
        break;
      case "failed":
        tone = "warn";
        text = __("Your sources couldn't be added.", "gpt3-ai-content-generator");
        action = "review";
        break;
      case "off":
        if (hasSources) {
          text = __("Knowledge is off, so answers don't use it.", "gpt3-ai-content-generator");
          action = "turn-on";
        }
        break;
      case "incompatible":
        text = __("Knowledge doesn't work with the chosen AI model.", "gpt3-ai-content-generator");
        action = "settings";
        break;
      case "setup_required":
        text = __("Knowledge needs setting up first.", "gpt3-ai-content-generator");
        action = "settings";
        break;
      case "unavailable":
        tone = "warn";
        text = __("Couldn't check knowledge right now.", "gpt3-ai-content-generator");
        break;
      default:
        break;
    }
    knowledgeStrip.hidden = false;
    knowledgeStrip.dataset.tone = tone;
    knowledgeStripText.textContent = text;
    if (knowledgeStripAction) {
      const config = knowledgeStripActions[action];
      knowledgeStripAction.hidden = !config;
      knowledgeStripAction.dataset.action = action;
      knowledgeStripAction.textContent = config ? config.label() : "";
    }
  };
  const setInventorySummary = (summary = {}) => {
    inventorySummary = { text: String(summary.text || ""), total: Number(summary.total || 0), failed: Number(summary.failed || 0) };
    renderKnowledgeStrip();
  };
  const closeTrainingStateMenu = () => {
    if (!trainingStateMenu || !trainingState) {
      return;
    }
    trainingStateMenu.hidden = true;
    trainingState.classList.remove("is-open");
    trainingState.setAttribute("aria-expanded", "false");
  };
  const setTrainingStateMenuOpen = (isOpen) => {
    if (!trainingStateMenu || !trainingState) {
      return;
    }
    const canOpen =
      trainingState.classList.contains("is-actionable");
    const shouldOpen = Boolean(isOpen) && canOpen;
    trainingStateMenu.hidden = !shouldOpen;
    trainingState.classList.toggle("is-open", shouldOpen);
    trainingState.setAttribute("aria-expanded", shouldOpen ? "true" : "false");
  };
  const setTrainingStatusSnapshot = (response = {}) => {
    const queue = response.queue && typeof response.queue === "object"
      ? response.queue
      : {};
    trainingStatusSnapshot = {
      count: Number(response.count || 0),
      countIsCapped: Boolean(response.count_is_capped),
      queue: {
        pending: Number(queue.pending || 0),
        processing: Number(queue.processing || 0),
        failed: Number(queue.failed || 0),
        active: Number(queue.active || 0),
        pendingIsCapped: Boolean(queue.pending_is_capped),
        processingIsCapped: Boolean(queue.processing_is_capped),
        failedIsCapped: Boolean(queue.failed_is_capped),
      },
      training_status: response.training_status || null,
    };
    updateTrainingStateReport(trainingStatusSnapshot);
    renderKnowledgeStrip();
  };
  const setReportCount = (field, count, display, metric = null) => {
    if (field) {
      field.textContent = Number.isNaN(count) ? "0" : display;
    }
    metric?.classList.toggle("has-value", !Number.isNaN(count) && count > 0);
  };
  const updateTrainingStateReport = (snapshot = trainingStatusSnapshot) => {
    const queue = snapshot && snapshot.queue ? snapshot.queue : {};
    const count = Number(snapshot?.count || 0);
    const processing = Number(queue.processing || 0);
    const failed = Number(queue.failed || 0);
    const displayedCount = snapshot?.countIsCapped ? `${count}+` : String(count);
    const displayedProcessing = queue.processingIsCapped
      ? `${processing}+`
      : String(processing);
    const displayedFailed = queue.failedIsCapped
      ? `${failed}+`
      : String(failed);
    if (trainingStateReportStatus) {
      trainingStateReportStatus.textContent =
        durableTrainingStatusKey === "training"
          ? __("Adding knowledge", "gpt3-ai-content-generator")
          : __("Knowledge details", "gpt3-ai-content-generator");
    }
    setReportCount(trainingStateReportTrained, count, displayedCount);
    setReportCount(
      trainingStateReportProcessing, processing, displayedProcessing,
      trainingStateProcessingMetric
    );
    setReportCount(
      trainingStateReportFailed, failed, displayedFailed,
      trainingStateFailedMetric
    );
  };
  const getTrainingActionButton = (tabKey = "") => {
    const activeTabKey = tabKey || getActiveTrainingTabKey();
    if (isOtherTrainingTab(activeTabKey)) {
      return trainingSheetAddButton || trainingMainAddButton;
    }
    return trainingMainAddButton || trainingSheetAddButton;
  };
  const getTrainingActionLabels = (tabKey) => {
    switch (tabKey || getActiveTrainingTabKey()) {
      case "qa":
      case "text":
        return {
          idle: __("Add", "gpt3-ai-content-generator"),
          loading: __("Adding", "gpt3-ai-content-generator"),
        };
      case "website":
        return {
          idle: __("Start learning", "gpt3-ai-content-generator"),
          loading: __("Learning", "gpt3-ai-content-generator"),
        };
      case "files":
        return {
          idle: __("Add files", "gpt3-ai-content-generator"),
          loading: __("Uploading", "gpt3-ai-content-generator"),
        };
      default:
        return {
          idle: __("Add Data", "gpt3-ai-content-generator"),
          loading: __("Adding", "gpt3-ai-content-generator"),
      };
    }
  };
  const setTrainingActionButtonText = (button, text) => {
    if (!button) {
      return;
    }
    const label = button.querySelector(".aipkit_training_action_text");
    if (label) {
      label.textContent = text;
      return;
    }
    button.textContent = text;
  };
  const setTrainingActionButtonLoading = (button, isLoading) => {
    if (!button) {
      return;
    }
    button.classList.toggle("is-loading", Boolean(isLoading));
    if (isLoading) {
      button.setAttribute("aria-busy", "true");
    } else {
      button.removeAttribute("aria-busy");
    }
  };
  const applyDurableTrainingActionState = () => {
    const websiteTrainingButton = getTrainingActionButton("website");
    if (!websiteTrainingButton || getIsTraining()) {
      return;
    }
    const shouldDisable = durableTrainingStatusKey === "training";
    websiteTrainingButton.disabled = shouldDisable;
    if (shouldDisable) {
      websiteTrainingButton.setAttribute("aria-disabled", "true");
    } else {
      websiteTrainingButton.removeAttribute("aria-disabled");
    }
    const labels = getTrainingActionLabels("website");
    setTrainingActionButtonText(
      websiteTrainingButton,
      shouldDisable ? labels.loading : labels.idle
    );
    setTrainingActionButtonLoading(websiteTrainingButton, shouldDisable);
  };
  const setDurableTrainingStatus = (status = {}) => {
    if (!trainingStateValue) {
      return;
    }
    const statusKey = status.key || "not_trained";
    const statusType = status.type || "neutral";
    durableTrainingStatusKey = statusKey;
    if (statusKey !== "training") {
      clearWebsiteTask();
    }
    trainingStateValue.textContent =
      status.label || __("No knowledge yet", "gpt3-ai-content-generator");
    if (trainingState) {
      trainingState.dataset.trainingStatus = statusKey;
      trainingState.classList.remove("is-pending");
      trainingState.setAttribute("aria-busy", "false");
      const isActionable = Boolean(trainingStateMenu);
      trainingState.disabled = !isActionable;
      trainingState.classList.toggle("is-actionable", isActionable);
      if (!isActionable) {
        closeTrainingStateMenu();
      }
      trainingState.classList.remove(
        "is-success",
        "is-error",
        "is-loading",
        "is-neutral"
      );
      trainingState.classList.add(`is-${statusType}`);
    }
    if (trainingStopButton) {
      trainingStopButton.hidden = statusKey !== "training";
    }
    updateTrainingStateReport();
    applyDurableTrainingActionState();
    renderKnowledgeStrip();
  };
  const updateTrainingActionLabel = (
    tabKey,
    isLoading = false,
    customLabel = ""
  ) => {
    const button = getTrainingActionButton(tabKey);
    if (!button) {
      return;
    }
    const labels = getTrainingActionLabels(tabKey);
    const text = customLabel || (isLoading ? labels.loading : labels.idle);
    setTrainingActionButtonText(button, text);
    setTrainingActionButtonLoading(button, isLoading);
    if (!isLoading) {
      button.removeAttribute("data-aipkit-training-running");
      button.removeAttribute("aria-label");
      button.removeAttribute("title");
    }
    applyDurableTrainingActionState();
  };
  const updateTrainingActionProgress = (
    progressLabel,
    { canStop = false, tabKey = "" } = {}
  ) => {
    const button = getTrainingActionButton(tabKey);
    if (!button) {
      return;
    }
    if (canStop) {
      setTrainingActionButtonText(
        button,
        __("Stop", "gpt3-ai-content-generator")
      );
      setTrainingActionButtonLoading(button, false);
      button.dataset.aipkitTrainingRunning = "true";
      button.setAttribute(
        "aria-label",
        __("Stop training", "gpt3-ai-content-generator")
      );
      button.setAttribute("title", __("Stop training", "gpt3-ai-content-generator"));
    } else {
      setTrainingActionButtonText(button, progressLabel);
      setTrainingActionButtonLoading(button, true);
      button.removeAttribute("data-aipkit-training-running");
      button.removeAttribute("aria-label");
      button.removeAttribute("title");
    }
  };

  return {
    trainingState, trainingStateMenu, trainingStopButton, trainingStateReportTrained,
    closeTrainingStateMenu, setTrainingStateMenuOpen, setTrainingStatusSnapshot,
    setDurableTrainingStatus, getTrainingActionButton, getTrainingActionLabels,
    setTrainingActionButtonText, updateTrainingActionLabel, updateTrainingActionProgress,
    getSnapshot: () => trainingStatusSnapshot,
    getStatusKey: () => durableTrainingStatusKey,
    setInventorySummary, openSources,
  };
}

/** Current source attempts, kept separate from successful, answerable sources. */
export function groupChatbotSources(logs = []) {
  const getTrainingSourceGroupKey = (log = {}) => {
    const message = String(log.message || "").toLowerCase();
    const content = String(log.indexed_content || "").trim();
    const fileId = String(log.file_id || "").toLowerCase();
    if (
      log.post_id ||
      message.includes("wordpress post content") ||
      fileId.startsWith("wp_post_")
    ) {
      return "website";
    }
    if (
      message.includes("file content") ||
      message.includes("file uploaded") ||
      message.includes("original filename:") ||
      fileId.includes("file_") ||
      /\.(pdf|docx?|txt|md|csv|json|html?|xlsx?)\b/i.test(
        String(log.post_title || "")
      )
    ) {
      return "files";
    }
    const normalizedContent = content
      .replace(/<br\s*\/?>/gi, "\n")
      .replace(/<\/p>/gi, "\n")
      .replace(/<[^>]+>/g, "")
      .trim();
    if (/^Q\s*:\s*\S[\s\S]*\bA\s*:\s*\S/i.test(normalizedContent)) {
      return "qa";
    }
    return "text";
  };

  const getTrainingSourceIdentity = (log, key) => {
    if (key === "website") {
      const postId = Number(log.post_id) > 0 ? log.post_id : null;
      return `post:${postId || log.file_id || log.id || ""}`;
    }
    if (key === "files") {
      const title = String(log.post_title || "").trim();
      const fileId = String(log.file_id || "")
        .replace(/(?:[_-]chunk)?[_-]?\d+(?:[_-]of[_-]\d+)?$/i, "")
        .trim();
      return `file:${title || fileId || log.id || ""}`;
    }
    // Short complete text identifies a retry even if it received a new file ID.
    // Truncated previews are not safe identifiers for different long documents.
    const content = String(log.indexed_content || "").replace(/\r\n/g, "\n").trim();
    return `${key}:${content && content.length < 997 && !content.endsWith("...")
      ? content : log.file_id || log.id || content}`;
  };

  const latest = new Map();
  logs.forEach((log) => {
    const kind = getTrainingSourceGroupKey(log);
    const sourceKey = JSON.stringify([log.provider || "", log.vector_store_id || "", getTrainingSourceIdentity(log, kind)]);
    // File chunks are independent: a successful chunk must not hide a failed one.
    const chunk = kind === "files" ? String(log.file_id || "").match(/(?:[_-]chunk)[_-]?(\d+)(?:[_-]of[_-]\d+)?$/i)?.[1] || "" : "";
    const key = JSON.stringify([sourceKey, chunk]);
    const previous = latest.get(key);
    const time = String(log.timestamp || "");
    const previousTime = String(previous?.log.timestamp || "");
    if (!previous || time > previousTime || (time === previousTime && Number(log.id || 0) > Number(previous.log.id || 0))) {
      latest.set(key, { kind, sourceKey, log });
    }
  });
  const groups = Object.fromEntries(["website", "qa", "text", "files"].map((key) => [key, {
    count: 0, ready: 0, failed: 0, statuses: new Set(), latest: "",
  }]));
  const sources = new Map();
  latest.forEach(({ kind, sourceKey, log }) => {
    if (!sources.has(sourceKey)) sources.set(sourceKey, { kind, logs: [] });
    sources.get(sourceKey).logs.push(log);
  });
  const readyStatuses = new Set(["indexed", "success", "ready", "completed", "skipped_already_indexed"]);
  sources.forEach(({ kind, logs: attempts }) => {
    const group = groups[kind];
    const statuses = attempts.map((log) => String(log.status || "").toLowerCase());
    group.count++;
    statuses.forEach((status) => group.statuses.add(status));
    if (statuses.every((status) => readyStatuses.has(status))) {
      group.ready++;
      attempts.forEach((log) => {
        if (String(log.timestamp || "") > group.latest) group.latest = String(log.timestamp);
      });
    }
    if (statuses.includes("failed")) group.failed++;
  });
  return groups;
}

/** Managed knowledge inventory, grouped summaries and status refresh. */
export function bindChatbotSourceInventory({
  trainingManagedSources, trainingSourceEmpty, trainingSourceEmptyTitle,
  trainingSourceEmptyDescription, trainingAddSourceButton, trainingSourcesButton,
  trainingCard, trainingPresentation, getChatbotCapabilityState,
  getSelectedBuilderBotId, buildTrainingStatusPayload, __,
  _n, sprintf,
}) {
  const { trainingStateReportTrained, setTrainingStatusSnapshot, setDurableTrainingStatus } = trainingPresentation;
  const escapeTrainingSourceText = (value) =>
    String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");

  const trainingSourceEmptyAction = trainingSourceEmpty?.querySelector?.(
    "[data-aipkit-training-source-add-again]"
  ) || null;
  // The empty state offers the website as a first source; loading and error copy do not.
  const setTrainingSourceEmptyCopy = (
    title = __("Start with your website", "gpt3-ai-content-generator"),
    description = __(
      "Most sites are learned in about a minute.",
      "gpt3-ai-content-generator"
    ),
    showAction = true
  ) => {
    if (trainingSourceEmptyTitle) {
      trainingSourceEmptyTitle.textContent = title;
    }
    if (trainingSourceEmptyDescription) {
      trainingSourceEmptyDescription.textContent = description;
    }
    if (trainingSourceEmptyAction) {
      trainingSourceEmptyAction.hidden = !showAction;
    }
  };

  // The Add source menu's website choice: where to start before anything is added, then what it learned.
  // A null total (loading, or the storage could not be read) shows neither.
  const websiteChoice = trainingCard?.querySelector('[data-aipkit-training-source-picker] [data-aipkit-training-source-option="website"]');
  const setWebsiteChoice = (pages, total) => {
    const hint = websiteChoice?.querySelector("[data-aipkit-training-source-hint]");
    const start = websiteChoice?.querySelector("[data-aipkit-training-source-start]");
    if (hint) {
      if (hint.dataset.defaultHint === undefined) {
        hint.dataset.defaultHint = hint.textContent;
      }
      hint.textContent = pages
        /* translators: %s: number of website pages it has learned. */
        ? sprintf(_n("%s page learned · add more or learn again", "%s pages learned · add more or learn again", pages, "gpt3-ai-content-generator"), pages.toLocaleString())
        : hint.dataset.defaultHint;
    }
    if (start) {
      start.hidden = total !== 0;
    }
  };

  const setTrainingInventoryLoading = () => {
    if (!trainingManagedSources || !trainingSourceEmpty) {
      return;
    }
    setWebsiteChoice(0, null);
    managedTrainingSourceCount = null;
    trainingManagedSources.hidden = true;
    trainingManagedSources.innerHTML = "";
    setTrainingSourceEmptyCopy(
      __("Loading sources", "gpt3-ai-content-generator"),
      __("Checking the selected knowledge storage.", "gpt3-ai-content-generator"),
      false
    );
    trainingSourceEmpty.hidden = false;
  };

  const renderManagedTrainingSources = (
    logs = [],
    capabilityState = getChatbotCapabilityState()
  ) => {
    if (!trainingManagedSources || !trainingSourceEmpty) {
      return;
    }

    const groups = groupChatbotSources(logs);
    const websiteCount = groups.website.count;
    const qaCount = groups.qa.count;
    const textCount = groups.text.count;
    const fileCount = groups.files.count;
    const syncedAgo = groups.website.latest && typeof window.aipkit_formatRelativeDateTime === "function"
      ? window.aipkit_formatRelativeDateTime(groups.website.latest)
      : "";
    const items = [];
    if (websiteCount) {
      const pages = sprintf(_n("%d page", "%d pages", websiteCount, "gpt3-ai-content-generator"), websiteCount);
      items.push({
        key: "website",
        label: __("Your website", "gpt3-ai-content-generator"),
        detail: syncedAgo && syncedAgo !== "N/A" && syncedAgo !== "Invalid Date"
          /* translators: 1: number of pages, such as "14 pages", 2: when they were learned, such as "1 hour ago". */
          ? sprintf(__("%1$s · learned %2$s", "gpt3-ai-content-generator"), pages, syncedAgo)
          : pages,
        summary: groups.website.ready ? sprintf(_n("%d page", "%d pages", groups.website.ready, "gpt3-ai-content-generator"), groups.website.ready) : "",
        icon: "dashicons-admin-site-alt3",
        statuses: groups.website.statuses,
        view: "site",
        addKey: "website",
        addIcon: "dashicons-update",
        addLabel: __("Learn again", "gpt3-ai-content-generator"),
      });
    }
    if (qaCount) {
      items.push({
        key: "qa",
        label: __("Questions and answers", "gpt3-ai-content-generator"),
        detail: sprintf(_n("%d question", "%d questions", qaCount, "gpt3-ai-content-generator"), qaCount),
        summary: groups.qa.ready ? sprintf(_n("%d Q&A", "%d Q&As", groups.qa.ready, "gpt3-ai-content-generator"), groups.qa.ready) : "",
        icon: "dashicons-format-chat",
        statuses: groups.qa.statuses,
        view: "text",
        addKey: "qa",
        addIcon: "dashicons-plus-alt2",
        addLabel: __("Add", "gpt3-ai-content-generator"),
      });
    }
    if (textCount) {
      items.push({
        key: "text",
        label: __("Text", "gpt3-ai-content-generator"),
        detail: sprintf(_n("%d text", "%d texts", textCount, "gpt3-ai-content-generator"), textCount),
        summary: groups.text.ready ? sprintf(_n("%d text", "%d texts", groups.text.ready, "gpt3-ai-content-generator"), groups.text.ready) : "",
        icon: "dashicons-media-text",
        statuses: groups.text.statuses,
        view: "text",
        addKey: "text",
        addIcon: "dashicons-plus-alt2",
        addLabel: __("Add", "gpt3-ai-content-generator"),
      });
    }
    if (fileCount) {
      items.push({
        key: "files",
        label: __("Files", "gpt3-ai-content-generator"),
        detail: sprintf(_n("%d file", "%d files", fileCount, "gpt3-ai-content-generator"), fileCount),
        summary: groups.files.ready ? sprintf(_n("%d file", "%d files", groups.files.ready, "gpt3-ai-content-generator"), groups.files.ready) : "",
        icon: "dashicons-media-document",
        statuses: groups.files.statuses,
        view: "file",
        addKey: "files",
        addIcon: "dashicons-upload",
        addLabel: __("Upload", "gpt3-ai-content-generator"),
      });
    }

    if (!items.length) {
      managedTrainingSourceCount = 0;
      setWebsiteChoice(0, 0);
      trainingManagedSources.hidden = true;
      trainingManagedSources.innerHTML = "";
      setTrainingSourceEmptyCopy();
      trainingSourceEmpty.hidden = false;
      trainingPresentation.setInventorySummary?.({ text: "", total: 0 });
      return;
    }

    managedTrainingSourceCount =
      (websiteCount ? 1 : 0) + textCount + qaCount + fileCount;
    setWebsiteChoice(websiteCount, managedTrainingSourceCount);
    if (trainingStateReportTrained) {
      trainingStateReportTrained.textContent = String(
        managedTrainingSourceCount
      );
    }
    const summaries = items.map((item) => item.summary).filter(Boolean);
    trainingPresentation.setInventorySummary?.({
      text: summaries.length > 1
        ? sprintf(
          __("%1$s and %2$s", "gpt3-ai-content-generator"),
          summaries.slice(0, -1).join(", "),
          summaries[summaries.length - 1]
        )
        : summaries[0],
      total: managedTrainingSourceCount,
      failed: Object.values(groups).reduce((sum, group) => sum + group.failed, 0),
    });

    trainingManagedSources.innerHTML = items
      .map((item) => {
        const isProcessing =
          item.statuses.has("processing") ||
          item.statuses.has("queued") ||
          item.statuses.has("pending");
        const hasFailed = item.statuses.has("failed");
        const isRuntimeActive = capabilityState.knowledgeRuntimeActive;
        // The status line explains an inactive knowledge base, so its rows only dim.
        const [stateClass, stateLabel] = !isRuntimeActive
          ? ["is-inactive", ""]
          : hasFailed
            ? ["is-warning", __("Needs a look", "gpt3-ai-content-generator")]
            : isProcessing
              // The website is learned; the other sources are added.
              ? ["is-processing", item.key === "website" ? __("Learning...", "gpt3-ai-content-generator") : __("Adding...", "gpt3-ai-content-generator")]
              : ["is-ready", ""];
        const state = stateLabel
          ? `<span class="aipkit_training_managed_source_state ${stateClass}">${escapeTrainingSourceText(stateLabel)}</span>`
          : "";
        return `<div class="aipkit_training_managed_source ${stateClass}" data-aipkit-training-managed-source="${escapeTrainingSourceText(item.key)}">
              <span class="aipkit_training_managed_source_icon dashicons ${escapeTrainingSourceText(item.icon)}" aria-hidden="true"></span>
              <button type="button" class="aipkit_training_managed_source_view" data-aipkit-training-source-view="${escapeTrainingSourceText(item.view)}">
                <strong>${escapeTrainingSourceText(item.label)}</strong>
                <span>${escapeTrainingSourceText(item.detail)}</span>
              </button>
              ${state}
              <button type="button" class="aipkit_training_managed_source_add" data-aipkit-training-source-add-again="${escapeTrainingSourceText(item.addKey)}">
                <span class="dashicons ${escapeTrainingSourceText(item.addIcon)}" aria-hidden="true"></span>
                <span>${escapeTrainingSourceText(item.addLabel)}</span>
              </button>
              <span class="aipkit_training_managed_source_chevron dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
            </div>`;
      })
      .join("");
    trainingSourceEmpty.hidden = true;
    trainingManagedSources.hidden = false;
  };

  let managedTrainingSourcesSignature = "";
  let managedTrainingSourceCount = null;
  let trainingRefreshGeneration = 0;
  let activeTrainingRefresh = null;
  const refreshTrainingSourcesAfterMutation = (contextSettings = null) => {
    managedTrainingSourcesSignature = "";
    return updateTrainingSourcesCount(contextSettings, true);
  };
  const isTrainingRefreshCurrent = (generation, botId) =>
    generation === trainingRefreshGeneration &&
    String(getSelectedBuilderBotId() || "") === String(botId || "");
  const updateManagedTrainingSources = async (
    contextSettings = null,
    capabilityState = getChatbotCapabilityState(),
    generation = trainingRefreshGeneration
  ) => {
    if (
      !trainingManagedSources ||
      !trainingSourceEmpty ||
      typeof window.aipkit_apiRequest !== "function"
    ) {
      return;
    }
    const payload = buildTrainingStatusPayload(contextSettings);
    if (!payload || !capabilityState.knowledgeHasInventoryTarget) {
      managedTrainingSourcesSignature = "";
      managedTrainingSourceCount = 0;
      renderManagedTrainingSources([], capabilityState);
      return;
    }
    const queue = trainingPresentation.getSnapshot()?.queue || {};
    const signature = JSON.stringify({
      bot: payload.bot_id || 0,
      provider: contextSettings?.vector_store_provider || "",
      stores: contextSettings?.openai_vector_store_ids || [],
      google: contextSettings?.google_file_search_store_names || [],
      pinecone: contextSettings?.pinecone_index_name || "",
      qdrant: contextSettings?.qdrant_collection_names || [],
      chroma: contextSettings?.chroma_collection_names || [],
      local: contextSettings?.local_store_ids || [],
      count: Number(trainingPresentation.getSnapshot()?.count || 0),
      pending: Number(queue.pending || 0),
      processing: Number(queue.processing || 0),
      failed: Number(queue.failed || 0),
      runtime: capabilityState.knowledgeRuntimeStatus,
    });
    if (signature === managedTrainingSourcesSignature) {
      if (
        trainingStateReportTrained &&
        Number.isFinite(managedTrainingSourceCount)
      ) {
        trainingStateReportTrained.textContent = String(
          managedTrainingSourceCount
        );
      }
      return;
    }
    setTrainingInventoryLoading();
    payload.page = 1;
    payload.per_page = 50;
    payload.include_total = "0";
    payload.include_inactive = "1";
    try {
      const firstPage = await window.aipkit_apiRequest(
        "aipkit_get_chatbot_training_sources",
        payload
      );
      if (!isTrainingRefreshCurrent(generation, payload.bot_id)) {
        return;
      }
      const logs = Array.isArray(firstPage.logs) ? [...firstPage.logs] : [];
      let hasMore = Boolean(firstPage.pagination?.has_more);
      for (let page = 2; page <= 20 && hasMore; page += 1) {
        const nextPage = await window.aipkit_apiRequest(
          "aipkit_get_chatbot_training_sources",
          { ...payload, page }
        );
        if (!isTrainingRefreshCurrent(generation, payload.bot_id)) {
          return;
        }
        if (Array.isArray(nextPage.logs)) {
          logs.push(...nextPage.logs);
        }
        hasMore = Boolean(nextPage.pagination?.has_more);
      }
      managedTrainingSourcesSignature = signature;
      renderManagedTrainingSources(logs, capabilityState);
    } catch (error) {
      if (!isTrainingRefreshCurrent(generation, payload.bot_id)) {
        return;
      }
      managedTrainingSourcesSignature = "";
      managedTrainingSourceCount = null;
      renderManagedTrainingSources([], capabilityState);
      setWebsiteChoice(0, null);
      setTrainingSourceEmptyCopy(
        __("Sources unavailable", "gpt3-ai-content-generator"),
        __("Could not load the selected knowledge storage.", "gpt3-ai-content-generator"),
        false
      );
    }
  };

  const openTrainingSourceAgain = (sourceKey) => {
    trainingAddSourceButton?.click();
    if (sourceKey && sourceKey !== "picker") {
      window.aipkit_selectTrainingSource?.(sourceKey);
    }
  };

  if (trainingManagedSources) {
    trainingManagedSources.addEventListener("click", (event) => {
      const addAgain = event.target.closest(
        "[data-aipkit-training-source-add-again]"
      );
      if (addAgain) {
        event.preventDefault();
        event.stopPropagation();
        openTrainingSourceAgain(addAgain.dataset.aipkitTrainingSourceAddAgain || "picker");
        return;
      }

      const view = event.target.closest("[data-aipkit-training-source-view]");
      if (view) {
        event.preventDefault();
        if (typeof trainingPresentation.openSources === "function") {
          trainingPresentation.openSources({ type: view.dataset.aipkitTrainingSourceView || "" });
        } else {
          trainingSourcesButton?.click();
        }
      }
    });
  }
  if (trainingSourceEmptyAction) {
    trainingSourceEmptyAction.addEventListener("click", (event) => {
      event.preventDefault();
      event.stopPropagation();
      openTrainingSourceAgain(trainingSourceEmptyAction.dataset.aipkitTrainingSourceAddAgain || "website");
    });
  }

  const setTrainingSourcesCount = (count, isCapped = false) => {
    if (!trainingSourcesButton) {
      return;
    }
    const baseLabel =
      trainingSourcesButton.dataset.baseLabel ||
      trainingSourcesButton
        .querySelector(".aipkit_training_sources_label")
        ?.textContent.trim() ||
      trainingSourcesButton.textContent.replace(/\(\d+\+?\)/, "").trim();
    const safeCount = Number.isFinite(count) ? count : 0;
    const displayedCount = isCapped ? `${safeCount}+` : String(safeCount);
    const countBadge = trainingSourcesButton.querySelector(
      ".aipkit_training_sources_count"
    );
    if (countBadge) {
      countBadge.textContent = displayedCount;
      trainingSourcesButton.setAttribute(
        "aria-label",
        `${baseLabel} (${displayedCount})`
      );
    } else {
      trainingSourcesButton.textContent = `${baseLabel} (${displayedCount})`;
    }
  };

  const updateTrainingSourcesCount = (contextSettings = null, refreshSourceStats = false) => {
    if (
      !trainingSourcesButton ||
      typeof window.aipkit_apiRequest !== "function"
    ) {
      return Promise.resolve();
    }
    const capabilityState = getChatbotCapabilityState();
    const botId = getSelectedBuilderBotId();
    const payload = buildTrainingStatusPayload(contextSettings);
    const contextSignature = JSON.stringify([
      payload,
      capabilityState.knowledgeRuntimeStatus,
    ]);
    // Polling must let the current paginated load finish. Context changes and
    // explicit refreshes still invalidate results from the previous load.
    if (
      !refreshSourceStats &&
      activeTrainingRefresh?.contextSignature === contextSignature
    ) {
      return activeTrainingRefresh.promise;
    }
    const generation = ++trainingRefreshGeneration;
    const refresh = { contextSignature, promise: null };
    activeTrainingRefresh = refresh;
    refresh.promise = Promise.resolve().then(async () => {
      if (trainingCard) {
        trainingCard.dataset.knowledgeRuntime =
          capabilityState.knowledgeRuntimeStatus;
      }
      const inactiveStatusMap = {
        off: {
          key: "off",
          label: __("Knowledge off", "gpt3-ai-content-generator"),
          type: "neutral",
        },
        incompatible: {
          key: "incompatible",
          label: __("Knowledge unavailable", "gpt3-ai-content-generator"),
          type: "neutral",
        },
        setup_required: {
          key: "setup_required",
          label: __("Setup required", "gpt3-ai-content-generator"),
          type: "neutral",
        },
      };
      const applyInactiveTrainingStatus = (status) => {
        setTrainingSourcesCount(0);
        setTrainingStatusSnapshot({
          count: 0,
          queue: {
            pending: 0,
            processing: 0,
            failed: 0,
            active: 0,
          },
          training_status: status,
        });
        setDurableTrainingStatus(status);
      };
      if (!capabilityState.knowledgeRuntimeActive) {
        const inactiveStatus =
          inactiveStatusMap[capabilityState.knowledgeRuntimeStatus] ||
          inactiveStatusMap.off;
        applyInactiveTrainingStatus(inactiveStatus);
        await updateManagedTrainingSources(
          contextSettings,
          capabilityState,
          generation
        );
        return;
      }
      if (!payload) {
        applyInactiveTrainingStatus(inactiveStatusMap.setup_required);
        renderManagedTrainingSources([], capabilityState);
        return;
      }
      if (refreshSourceStats) payload.refresh_source_stats = "1";
      setDurableTrainingStatus({
        key: "checking",
        label: __("Checking knowledge", "gpt3-ai-content-generator"),
        type: "loading",
      });
      try {
        const response = await window.aipkit_apiRequest(
          "aipkit_get_chatbot_training_status",
          payload
        );
        if (!isTrainingRefreshCurrent(generation, botId)) {
          return;
        }
        const count = Number(response.count || 0);
        setTrainingSourcesCount(
          Number.isNaN(count) ? 0 : count,
          Boolean(response.count_is_capped)
        );
        setTrainingStatusSnapshot(response);
        if (response.training_status) {
          setDurableTrainingStatus(response.training_status);
        }
        await updateManagedTrainingSources(
          contextSettings,
          capabilityState,
          generation
        );
      } catch (error) {
        if (!isTrainingRefreshCurrent(generation, botId)) {
          return;
        }
        applyInactiveTrainingStatus({
          key: "unavailable",
          label: __("Status unavailable", "gpt3-ai-content-generator"),
          type: "error",
        });
        await updateManagedTrainingSources(
          contextSettings,
          capabilityState,
          generation
        );
      }
    }).finally(() => {
      if (activeTrainingRefresh === refresh) {
        activeTrainingRefresh = null;
      }
    });
    return refresh.promise;
  };

  return { updateTrainingSourcesCount, setTrainingSourcesCount, refreshTrainingSourcesAfterMutation };
}

/** Website source selection. Requests and training remain with the training controller. */
export function createChatbotWebsiteSources({
  builder,
  trainingWpTargetSelect,
  trainingWpStatusSelect,
  trainingWpBulkPanel,
  getContextSettings,
}) {
  const getWebsitePostTypeSelect = () => document.getElementById("aipkit_vs_wp_content_post_types");

  const firstSelected = (values) =>
    Array.isArray(values) ? values.filter(Boolean)[0] || "" : "";

  const syncWebsiteTargetSelect = (contextSettings = null) => {
    if (!trainingWpTargetSelect) {
      return;
    }
    const settings = contextSettings || getContextSettings();
    if (!settings || settings.enable_vector_store !== "1") {
      trainingWpTargetSelect.innerHTML = '<option value=""></option>';
      trainingWpTargetSelect.value = "";
      return;
    }
    let storeId = "";
    if (settings.vector_store_provider === "openai") {
      storeId = firstSelected(settings.openai_vector_store_ids);
    } else if (settings.vector_store_provider === "google") {
      storeId = firstSelected(settings.google_file_search_store_names);
    } else if (settings.vector_store_provider === "pinecone") {
      storeId = settings.pinecone_index_name || "";
    } else if (settings.vector_store_provider === "qdrant") {
      storeId = firstSelected(settings.qdrant_collection_names);
    } else if (settings.vector_store_provider === "chroma") {
      storeId = firstSelected(settings.chroma_collection_names);
    } else if (settings.vector_store_provider === "local") {
      storeId = firstSelected(settings.local_store_ids);
    }
    trainingWpTargetSelect.innerHTML = storeId
      ? `<option value="${storeId}">${storeId}</option>`
      : '<option value=""></option>';
    trainingWpTargetSelect.value = storeId;
    trainingWpTargetSelect.dispatchEvent(new Event("change"));
  };

  const getWebsitePostTypes = () => {
    const select = getWebsitePostTypeSelect();
    if (!select) {
      return [];
    }
    return Array.from(select.selectedOptions)
      .map((option) => option.value)
      .filter(Boolean);
  };

  const getWebsiteStatusValue = () =>
    trainingWpStatusSelect ? trainingWpStatusSelect.value || "publish" : "publish";

  const syncTypeCheckboxes = (dropdown) => {
    if (!dropdown) {
      return;
    }
    const panel = dropdown.querySelector(".aipkit_training_site_dropdown_panel");
    const hiddenSelect = getWebsitePostTypeSelect();
    if (!panel || !hiddenSelect) {
      return;
    }

    const selectedValues = new Set(
      Array.from(hiddenSelect.selectedOptions || [])
        .map((option) => option.value)
        .filter(Boolean)
    );
    panel.querySelectorAll('input[type="checkbox"]').forEach((input) => {
      input.checked = selectedValues.has(input.value);
    });
  };

  const syncTypes = () => {
    builder
      .querySelectorAll(".aipkit_training_site_dropdown")
      .forEach((dropdown) => {
        syncTypeCheckboxes(dropdown);
      });
  };

  const bindTypes = () => {
    const dropdowns = builder.querySelectorAll(
      ".aipkit_training_site_dropdown"
    );
    dropdowns.forEach((dropdown) => {
      const panel = dropdown.querySelector(
        ".aipkit_training_site_dropdown_panel"
      );
      const hiddenSelect = getWebsitePostTypeSelect();
      if (!panel || !hiddenSelect) {
        return;
      }

      const syncHiddenSelect = () => {
        const checkedValues = Array.from(
          panel.querySelectorAll('input[type="checkbox"]:checked')
        ).map((input) => input.value);
        Array.from(hiddenSelect.options).forEach((option) => {
          option.selected = checkedValues.includes(option.value);
        });
      };

      panel.addEventListener("change", () => {
        syncHiddenSelect();
        syncTypeCheckboxes(dropdown);
      });

      syncTypeCheckboxes(dropdown);
    });
  };

  const bind = () => {
    if (trainingWpBulkPanel && !builder.dataset.trainingWebsiteBound) {
      syncWebsiteTargetSelect(getContextSettings());
      bindTypes();
      trainingWpBulkPanel.hidden = false;
      builder.dataset.trainingWebsiteBound = "1";
    }
  };

  return {
    syncTarget: syncWebsiteTargetSelect,
    getPostTypes: getWebsitePostTypes,
    getStatus: getWebsiteStatusValue,
    syncTypes,
    bind,
  };
}

/** Source picker, Q&A suggestions and draft confirmation. Requests stay with training. */
export function bindChatbotSourcePicker({
  builder, __, trainingCommonQuestionsToggle,
  trainingCommonQuestionsPanel, trainingCommonQuestionButtons, trainingQaQuestion,
  trainingQaAnswer, trainingTextInput, trainingSourcePopover,
  trainingAddSourceButton, trainingSourcePicker, trainingSourceForm,
  trainingWebsitePanel, trainingOtherPanelsContainer, trainingOtherPanels,
  trainingOtherFooter, trainingOtherSourceOptions, trainingOtherSourceSelect,
  trainingDiscardPrompt, trainingDiscardTitle, trainingDiscardMessage,
  trainingDiscardKeepButton, trainingDiscardConfirmButton, trainingSelectedFiles,
  trainingFileRows, trainingFileList, trainingFilesInput,
  trainingFilesDropzone, trainingFileQueue, trainingFileCount,
  getIsTraining, getActiveTrainingSource, setActiveTrainingSourceKey,
  requestTrainingCancel, onDiscard = () => {}, isOtherTrainingTab, updateTrainingActionLabel,
  updateTrainingActionProgress, updateFileUploadActionAvailability,
}) {
  let trainingWebsiteDismissBaseline = [];
  let finalizeTrainingSourceDiscard;
  const sheetHeader = trainingSourceForm?.querySelector?.("[data-aipkit-training-source-sheet-header]") || null;
  const sheetTitle = sheetHeader?.querySelector("[data-aipkit-training-source-sheet-title]") || null;
  const sheetHint = sheetHeader?.querySelector("[data-aipkit-training-source-sheet-hint]") || null;
  const sheetCloseButtons = Array.from(
    trainingSourcePopover?.querySelectorAll?.("[data-aipkit-training-source-close], [data-aipkit-training-source-cancel]") || []
  );
  const addAnotherButton = trainingSourcePopover?.querySelector?.("[data-aipkit-training-add-another]") || null;
  const websiteSummary = trainingWebsitePanel?.querySelector?.("[data-aipkit-training-website-summary]") || null;
  // "Save and add another" keeps the panel open after the next successful save only.
  let keepOpenAfterSave = false;
  const updateWebsiteSummary = () => {
    if (!websiteSummary) {
      return;
    }
    const total = Array.from(
      trainingWebsitePanel.querySelectorAll(".aipkit_wp_type_cb:checked")
    ).reduce((sum, checkbox) => sum + Math.max(0, Number(checkbox.dataset.count) || 0), 0);
    websiteSummary.textContent = total === 1
      ? websiteSummary.dataset.one || ""
      : (websiteSummary.dataset.many || "").replace("%s", total.toLocaleString());
  };
  const setCommonQuestionsOpen = (isOpen) => {
    if (!trainingCommonQuestionsToggle || !trainingCommonQuestionsPanel) {
      return;
    }
    trainingCommonQuestionsPanel.hidden = !isOpen;
    trainingCommonQuestionsToggle.setAttribute(
      "aria-expanded",
      isOpen ? "true" : "false"
    );
  };

  const appendCommonQuestionToQaField = (question) => {
    if (!trainingQaQuestion || !question) {
      return;
    }
    const currentValue = trainingQaQuestion.value.trimEnd();
    trainingQaQuestion.value = currentValue
      ? `${currentValue}\n${question}`
      : question;
    trainingQaQuestion.dispatchEvent(new Event("input", { bubbles: true }));
    trainingQaQuestion.focus();
    const caretPosition = trainingQaQuestion.value.length;
    if (typeof trainingQaQuestion.setSelectionRange === "function") {
      trainingQaQuestion.setSelectionRange(caretPosition, caretPosition);
    }
  };

  if (trainingCommonQuestionsToggle && trainingCommonQuestionsPanel) {
    trainingCommonQuestionsToggle.addEventListener("click", (event) => {
      event.preventDefault();
      setCommonQuestionsOpen(trainingCommonQuestionsPanel.hidden);
    });

    document.addEventListener("click", (event) => {
      if (trainingCommonQuestionsPanel.hidden) {
        return;
      }
      if (
        trainingCommonQuestionsPanel.contains(event.target) ||
        trainingCommonQuestionsToggle.contains(event.target)
      ) {
        return;
      }
      setCommonQuestionsOpen(false);
    });

    document.addEventListener("keydown", (event) => {
      if (event.key !== "Escape" || trainingCommonQuestionsPanel.hidden) {
        return;
      }
      setCommonQuestionsOpen(false);
      trainingCommonQuestionsToggle.focus();
    });
  }

  trainingCommonQuestionButtons.forEach((button) => {
    button.addEventListener("click", () => {
      appendCommonQuestionToQaField(button.dataset.aipkitCommonQuestion || "");
      setCommonQuestionsOpen(false);
    });
  });

  {
    const trainingSourcePopoverGap = 8;
    const trainingSourcePopoverViewportMargin = 8;
    const trainingSourcePopoverMaxHeight = 620;

    const positionTrainingSourcePopover = () => {
      if (
        !trainingSourcePopover ||
        trainingSourcePopover.hidden ||
        !trainingAddSourceButton
      ) {
        return;
      }
      if (trainingSourcePopover.classList.contains("is-form-view")) {
        ["top", "right", "bottom", "left", "maxHeight"].forEach((property) => {
          trainingSourcePopover.style[property] = "";
        });
        delete trainingSourcePopover.dataset.placement;
        return;
      }

      const triggerRect = trainingAddSourceButton.getBoundingClientRect();
      const viewportWidth =
        document.documentElement.clientWidth || window.innerWidth || 0;
      const viewportHeight =
        document.documentElement.clientHeight || window.innerHeight || 0;
      const popoverWidth = trainingSourcePopover.offsetWidth;
      trainingSourcePopover.style.maxHeight = "";
      const naturalHeight = Math.min(
        trainingSourcePopover.offsetHeight,
        trainingSourcePopoverMaxHeight
      );
      const spaceBelow = Math.max(
        0,
        viewportHeight -
          triggerRect.bottom -
          trainingSourcePopoverGap -
          trainingSourcePopoverViewportMargin
      );
      const spaceAbove = Math.max(
        0,
        triggerRect.top -
          trainingSourcePopoverGap -
          trainingSourcePopoverViewportMargin
      );
      const placeAbove = spaceBelow < naturalHeight && spaceAbove > 0;
      const availableHeight = placeAbove ? spaceAbove : spaceBelow;
      const maxHeight = Math.max(
        96,
        Math.min(trainingSourcePopoverMaxHeight, availableHeight)
      );
      if (viewportWidth <= 600) {
        trainingSourcePopover.style.left = `${trainingSourcePopoverViewportMargin}px`;
        trainingSourcePopover.style.right = `${trainingSourcePopoverViewportMargin}px`;
      } else {
        const preferredRight = viewportWidth - triggerRect.right;
        const maxRight = Math.max(
          trainingSourcePopoverViewportMargin,
          viewportWidth -
            popoverWidth -
            trainingSourcePopoverViewportMargin
        );
        const right = Math.min(
          Math.max(preferredRight, trainingSourcePopoverViewportMargin),
          maxRight
        );

        trainingSourcePopover.style.left = "auto";
        trainingSourcePopover.style.right = `${Math.round(right)}px`;
      }
      trainingSourcePopover.style.maxHeight = `${Math.round(maxHeight)}px`;
      trainingSourcePopover.dataset.placement = placeAbove ? "top" : "bottom";

      if (placeAbove) {
        trainingSourcePopover.style.top = "auto";
        trainingSourcePopover.style.bottom = `${Math.round(
          viewportHeight - triggerRect.top + trainingSourcePopoverGap
        )}px`;
      } else {
        trainingSourcePopover.style.top = `${Math.round(
          triggerRect.bottom + trainingSourcePopoverGap
        )}px`;
        trainingSourcePopover.style.bottom = "auto";
      }
    };

    const resetTrainingSourcePicker = () => {
      setActiveTrainingSourceKey("");
      trainingSourcePopover?.classList.remove("is-form-view");
      if (trainingSourcePicker) {
        trainingSourcePicker.hidden = false;
      }
      if (trainingSourceForm) {
        trainingSourceForm.hidden = true;
      }
      if (trainingWebsitePanel) {
        trainingWebsitePanel.hidden = true;
        trainingWebsitePanel.classList.remove("is-active");
      }
      if (trainingOtherPanelsContainer) {
        trainingOtherPanelsContainer.hidden = true;
      }
      trainingOtherPanels.forEach((panel) => {
        panel.hidden = true;
        panel.classList.remove("is-active");
      });
      if (trainingOtherFooter) {
        trainingOtherFooter.hidden = true;
      }
      trainingOtherSourceOptions.forEach((option) => {
        option.classList.remove("is-active");
        option.setAttribute("aria-pressed", "false");
        if (option.getAttribute("role") === "tab") {
          option.setAttribute("aria-selected", "false");
        }
      });
      setCommonQuestionsOpen(false);
    };

    const getTrainingWebsiteSelection = () =>
      Array.from(
        trainingWebsitePanel?.querySelectorAll(".aipkit_wp_type_cb:checked") || []
      )
        .map((checkbox) => checkbox.value)
        .filter(Boolean)
        .sort();

    const setTrainingWebsiteDismissBaseline = () => {
      trainingWebsiteDismissBaseline = getTrainingWebsiteSelection();
    };

    const hasTrainingSourceDraft = () =>
      Boolean(
        getIsTraining() ||
          (trainingTextInput && trainingTextInput.value.trim()) ||
          (trainingQaQuestion && trainingQaQuestion.value.trim()) ||
          (trainingQaAnswer && trainingQaAnswer.value.trim()) ||
          trainingSelectedFiles.size ||
          JSON.stringify(getTrainingWebsiteSelection()) !==
            JSON.stringify(trainingWebsiteDismissBaseline)
      );

    const getTrainingDiscardCopy = () => {
      if (getIsTraining() && getActiveTrainingSource() === "files") {
        return {
          title: __("Cancel file uploads?", "gpt3-ai-content-generator"),
          message: __(
            "The active upload will be cancelled. Files already added will remain in your knowledge base.",
            "gpt3-ai-content-generator"
          ),
        };
      }
      if (getIsTraining()) {
        return {
          title: __("Cancel adding this source?", "gpt3-ai-content-generator"),
          message: __(
            "The active request will be cancelled. Any item already added will remain in your knowledge base.",
            "gpt3-ai-content-generator"
          ),
        };
      }
      if (trainingSelectedFiles.size) {
        return {
          title: __("Discard these files?", "gpt3-ai-content-generator"),
          message: __(
            "The files you selected have not been added yet and will be removed.",
            "gpt3-ai-content-generator"
          ),
        };
      }
      if (
        (trainingQaQuestion && trainingQaQuestion.value.trim()) ||
        (trainingQaAnswer && trainingQaAnswer.value.trim())
      ) {
        return {
          title: __("Discard this source?", "gpt3-ai-content-generator"),
          message: __(
            "The question and answer you entered have not been added yet and will be lost.",
            "gpt3-ai-content-generator"
          ),
        };
      }
      if (trainingTextInput && trainingTextInput.value.trim()) {
        return {
          title: __("Discard this source?", "gpt3-ai-content-generator"),
          message: __(
            "The text you entered has not been added yet and will be lost.",
            "gpt3-ai-content-generator"
          ),
        };
      }
      return {
        title: __("Discard these changes?", "gpt3-ai-content-generator"),
        message: __(
          "Your source settings have not been saved and will be lost.",
          "gpt3-ai-content-generator"
        ),
      };
    };

    const setTrainingDiscardPromptOpen = (isOpen) => {
      if (!trainingDiscardPrompt) {
        return;
      }
      trainingDiscardPrompt.hidden = !isOpen;
      trainingSourcePopover?.classList.toggle("is-confirming-discard", isOpen);
      if (trainingSourcePicker) {
        trainingSourcePicker.inert = isOpen;
      }
      if (trainingSourceForm) {
        trainingSourceForm.inert = isOpen;
      }
      if (isOpen) {
        const copy = getTrainingDiscardCopy();
        if (trainingDiscardTitle) {
          trainingDiscardTitle.textContent = copy.title;
        }
        if (trainingDiscardMessage) {
          trainingDiscardMessage.textContent = copy.message;
        }
        window.setTimeout(() => trainingDiscardKeepButton?.focus(), 0);
      } else {
        if (trainingDiscardKeepButton) {
          trainingDiscardKeepButton.disabled = false;
        }
        if (trainingDiscardConfirmButton) {
          trainingDiscardConfirmButton.disabled = false;
          trainingDiscardConfirmButton.textContent = __(
            "Discard",
            "gpt3-ai-content-generator"
          );
        }
      }
    };

    const clearTrainingFiles = () => {
      trainingSelectedFiles.clear();
      trainingFileRows.clear();
      if (trainingFileList) {
        trainingFileList.innerHTML = "";
      }
      if (trainingFilesInput) {
        trainingFilesInput.value = "";
      }
      if (trainingFilesDropzone) {
        trainingFilesDropzone.style.display = "";
        trainingFilesDropzone.classList.remove("has-files");
      }
      if (trainingFileQueue) {
        trainingFileQueue.hidden = true;
      }
      if (trainingFileCount) {
        trainingFileCount.textContent = "";
      }
    };

    const clearTrainingSourceDraft = () => {
      [trainingTextInput, trainingQaQuestion, trainingQaAnswer].forEach((field) => {
        if (field) field.value = "";
      });
      clearTrainingFiles();
      const selectedTypes = new Set(trainingWebsiteDismissBaseline);
      trainingWebsitePanel?.querySelectorAll(".aipkit_wp_type_cb").forEach(
        (checkbox) => {
          checkbox.checked = selectedTypes.has(checkbox.value);
        }
      );
      const hiddenPostTypeSelect = builder.querySelector(
        "#aipkit_vs_wp_content_post_types"
      );
      if (hiddenPostTypeSelect) {
        Array.from(hiddenPostTypeSelect.options).forEach((option) => {
          option.selected = selectedTypes.has(option.value);
        });
      }
      updateFileUploadActionAvailability();
    };

    const forceCloseTrainingSourcePopover = ({ resetFiles = true } = {}) => {
      if (!trainingSourcePopover) {
        return;
      }
      setTrainingDiscardPromptOpen(false);
      trainingSourcePopover.hidden = true;
      trainingAddSourceButton?.setAttribute("aria-expanded", "false");
      setCommonQuestionsOpen(false);
      if (resetFiles) {
        clearTrainingFiles();
      }
    };

    const requestTrainingSourcePopoverDismiss = (options = {}) => {
      if (!trainingSourcePopover || trainingSourcePopover.hidden) {
        return;
      }
      if (!trainingDiscardPrompt?.hidden) {
        trainingDiscardKeepButton?.focus();
        return;
      }
      if (!hasTrainingSourceDraft()) {
        forceCloseTrainingSourcePopover(options);
        return;
      }
      setTrainingDiscardPromptOpen(true);
      window.requestAnimationFrame(positionTrainingSourcePopover);
    };

    finalizeTrainingSourceDiscard = () => {
      onDiscard();
      clearTrainingSourceDraft();
      forceCloseTrainingSourcePopover({ resetFiles: false });
    };

    const showTrainingSourcePicker = () => {
      keepOpenAfterSave = false;
      resetTrainingSourcePicker();
      setTrainingDiscardPromptOpen(false);
      setTrainingWebsiteDismissBaseline();
      if (trainingSourcePopover) {
        trainingSourcePopover.hidden = false;
      }
      trainingAddSourceButton?.setAttribute("aria-expanded", "true");
      window.requestAnimationFrame(() => {
        positionTrainingSourcePopover();
        trainingSourcePicker
          ?.querySelector("[data-aipkit-training-source-option]")
          ?.focus();
      });
    };

    const setActiveTrainingSource = (tabKey) => {
      const nextKey = tabKey || "website";
      if (nextKey !== "website" && !isOtherTrainingTab(nextKey)) {
        return;
      }
      setActiveTrainingSourceKey(nextKey);
      trainingSourcePopover?.classList.add("is-form-view");
      if (sheetHeader) {
        if (sheetTitle) {
          sheetTitle.textContent = sheetHeader.dataset[`title${nextKey.charAt(0).toUpperCase()}${nextKey.slice(1)}`] || "";
        }
        if (sheetHint) {
          sheetHint.textContent = sheetHeader.dataset[`hint${nextKey.charAt(0).toUpperCase()}${nextKey.slice(1)}`] || "";
        }
      }
      if (addAnotherButton) {
        addAnotherButton.hidden = nextKey !== "qa";
      }
      if (nextKey === "website") {
        updateWebsiteSummary();
      }

      if (trainingSourcePicker) {
        trainingSourcePicker.hidden = true;
      }
      if (trainingSourceForm) {
        trainingSourceForm.hidden = false;
      }
      if (trainingWebsitePanel) {
        const isWebsite = nextKey === "website";
        trainingWebsitePanel.classList.toggle("is-active", isWebsite);
        trainingWebsitePanel.hidden = !isWebsite;
      }
      if (trainingOtherPanelsContainer) {
        trainingOtherPanelsContainer.hidden = !isOtherTrainingTab(nextKey);
      }
      trainingOtherPanels.forEach((panel) => {
        const panelKey = panel.dataset.aipkitPanel || "";
        const isActivePanel = isOtherTrainingTab(nextKey) && panelKey === nextKey;
        panel.hidden = !isActivePanel;
        panel.classList.toggle("is-active", isActivePanel);
      });
      if (trainingOtherFooter) {
        trainingOtherFooter.hidden = !isOtherTrainingTab(nextKey);
      }
      if (nextKey !== "qa") {
        setCommonQuestionsOpen(false);
      }
      if (trainingOtherSourceSelect && isOtherTrainingTab(nextKey)) {
        trainingOtherSourceSelect.value = nextKey;
      }
      trainingOtherSourceOptions.forEach((option) => {
        const isActiveOption =
          option.dataset.aipkitTrainingSourceOption === nextKey;
        option.classList.toggle("is-active", isActiveOption);
        option.setAttribute("aria-pressed", isActiveOption ? "true" : "false");
        if (option.getAttribute("role") === "tab") {
          option.setAttribute("aria-selected", isActiveOption ? "true" : "false");
        }
      });
      if (!getIsTraining()) {
        updateTrainingActionLabel(nextKey);
        updateFileUploadActionAvailability();
      }

      window.requestAnimationFrame(() => {
        positionTrainingSourcePopover();
        const activePanel = trainingOtherPanels.find(
          (panel) => (panel.dataset.aipkitPanel || "") === nextKey
        );
        const firstField = nextKey === "website"
          ? trainingWebsitePanel?.querySelector(".aipkit_wp_type_cb")
          : activePanel?.querySelector("textarea, input") || activePanel?.querySelector("button");
        const activeTab = trainingSourceForm?.querySelector(
          `[role="tab"][data-aipkit-training-source-option="${nextKey}"]`
        );
        (firstField || activeTab)?.focus();
      });
    };

    resetTrainingSourcePicker();
    window.aipkit_openTrainingSourcePicker = showTrainingSourcePicker;
    window.aipkit_closeTrainingSourcePopover = (options = {}) => {
      if (keepOpenAfterSave) {
        keepOpenAfterSave = false;
        trainingQaQuestion?.focus();
        return;
      }
      if (options.saved) {
        setTrainingWebsiteDismissBaseline();
        forceCloseTrainingSourcePopover(options);
        return;
      }
      requestTrainingSourcePopoverDismiss(options);
    };
    window.aipkit_selectTrainingSource = setActiveTrainingSource;

    if (
      trainingOtherSourceSelect &&
      trainingOtherSourceSelect.dataset.aipkitTrainingSourceSelectBound !== "1"
    ) {
      trainingOtherSourceSelect.addEventListener("change", () => {
        setActiveTrainingSource(trainingOtherSourceSelect.value || "text");
      });
      trainingOtherSourceSelect.dataset.aipkitTrainingSourceSelectBound = "1";
    }

    trainingOtherSourceOptions.forEach((option) => {
      if (option.dataset.aipkitTrainingSourceOptionBound === "1") {
        return;
      }
      option.addEventListener("click", () => {
        const sourceKey = option.dataset.aipkitTrainingSourceOption || "text";
        if (sourceKey !== "website" && !isOtherTrainingTab(sourceKey)) {
          return;
        }
        if (trainingOtherSourceSelect && isOtherTrainingTab(sourceKey)) {
          trainingOtherSourceSelect.value = sourceKey;
        }
        setActiveTrainingSource(sourceKey);
      });
      option.dataset.aipkitTrainingSourceOptionBound = "1";
    });

    if (trainingAddSourceButton && trainingSourcePopover) {
      trainingAddSourceButton.addEventListener("click", (event) => {
        event.preventDefault();
        event.stopPropagation();
        if (!trainingSourcePopover.hidden) {
          requestTrainingSourcePopoverDismiss();
          return;
        }
        showTrainingSourcePicker();
      });

      document.addEventListener("click", (event) => {
        if (
          trainingSourcePopover.hidden ||
          trainingSourcePopover.contains(event.target) ||
          trainingAddSourceButton.contains(event.target)
        ) {
          return;
        }
        if (hasTrainingSourceDraft()) {
          event.preventDefault();
          event.stopPropagation();
        }
        requestTrainingSourcePopoverDismiss();
      }, true);

      document.addEventListener("keydown", (event) => {
        if (event.key !== "Escape" || trainingSourcePopover.hidden) {
          return;
        }
        event.preventDefault();
        if (!trainingDiscardPrompt?.hidden) {
          setTrainingDiscardPromptOpen(false);
          return;
        }
        requestTrainingSourcePopoverDismiss();
        if (trainingDiscardPrompt?.hidden) {
          trainingAddSourceButton.focus();
        }
      });

      window.addEventListener(
        "scroll",
        (event) => {
          if (trainingSourcePopover.hidden) {
            return;
          }
          if (
            event.target instanceof Element &&
            trainingSourcePopover.contains(event.target)
          ) {
            return;
          }
          window.requestAnimationFrame(positionTrainingSourcePopover);
        },
        { capture: true, passive: true }
      );

      window.addEventListener("resize", () => {
        if (!trainingSourcePopover.hidden) {
          positionTrainingSourcePopover();
        }
      });

      trainingSourcePopover.addEventListener("transitionend", (event) => {
        if (
          event.propertyName === "width" &&
          !trainingSourcePopover.hidden
        ) {
          positionTrainingSourcePopover();
        }
      });

      trainingDiscardKeepButton?.addEventListener("click", () => {
        setTrainingDiscardPromptOpen(false);
        window.requestAnimationFrame(positionTrainingSourcePopover);
      });

      sheetCloseButtons.forEach((button) => {
        button.addEventListener("click", (event) => {
          event.preventDefault();
          keepOpenAfterSave = false;
          requestTrainingSourcePopoverDismiss();
          if (trainingSourcePopover.hidden) {
            trainingAddSourceButton.focus();
          }
        });
      });

      const sheetAddButton = trainingOtherFooter?.querySelector?.("[data-aipkit-training-sheet-action]") || null;
      if (addAnotherButton && sheetAddButton) {
        let addAnotherPending = false;
        // A plain Add (or Stop) cancels a keep-open request that never reached a save.
        sheetAddButton.addEventListener("click", () => {
          if (!addAnotherPending) {
            keepOpenAfterSave = false;
          }
          addAnotherPending = false;
        }, true);
        addAnotherButton.addEventListener("click", (event) => {
          event.preventDefault();
          if (getIsTraining()) {
            return;
          }
          addAnotherPending = true;
          keepOpenAfterSave = true;
          sheetAddButton.click();
        });
      }

      trainingWebsitePanel?.addEventListener?.("change", (event) => {
        if (event.target?.matches?.(".aipkit_wp_type_cb")) {
          updateWebsiteSummary();
        }
      });

      trainingDiscardConfirmButton?.addEventListener("click", () => {
        if (getIsTraining()) {
          requestTrainingCancel();
          trainingDiscardKeepButton.disabled = true;
          trainingDiscardConfirmButton.disabled = true;
          trainingDiscardConfirmButton.textContent = __(
            "Cancelling...",
            "gpt3-ai-content-generator"
          );
          updateTrainingActionProgress(
            __("Cancelling...", "gpt3-ai-content-generator"),
            { tabKey: getActiveTrainingSource() }
          );
          return;
        }
        finalizeTrainingSourceDiscard();
      });
    }
  }

  return finalizeTrainingSourceDiscard;
}

/** Owns one Knowledge action and its background reads until every task settles. */
export function createChatbotKnowledgeRun({ builder, botSelect, getBotId, onInvalidate }) {
  const controller = new AbortController();
  const botId = String(getBotId() || "");
  let invalidated = false;
  let tasks = 1;
  const isOwner = () => !invalidated && builder.isConnected && String(getBotId() || "") === botId;
  const invalidate = () => {
    if (invalidated) return;
    invalidated = true;
    controller.abort();
    cleanup();
    onInvalidate();
  };
  const onMutation = () => {
    if (!builder.isConnected) invalidate();
  };
  const observer = new MutationObserver(onMutation);
  const cleanup = () => {
    observer.disconnect();
    botSelect?.removeEventListener("change", invalidate);
    builder.removeEventListener("aipkit:bot-state-applied", invalidate);
  };
  const release = () => {
    tasks -= 1;
    if (tasks === 0) cleanup();
  };
  botSelect?.addEventListener("change", invalidate);
  builder.addEventListener("aipkit:bot-state-applied", invalidate);
  observer.observe(builder.ownerDocument.documentElement, { childList: true, subtree: true });
  return {
    signal: controller.signal,
    isOwner,
    invalidate,
    abort: () => controller.abort(),
    assertCurrent: () => {
      if (!isOwner() || controller.signal.aborted) {
        throw new DOMException("Adding stopped.", "AbortError");
      }
    },
    retain: () => {
      tasks += 1;
      let released = false;
      return () => {
        if (released) return;
        released = true;
        release();
      };
    },
    release,
  };
}
