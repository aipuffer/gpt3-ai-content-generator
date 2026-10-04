/**
 * AIPKit Content Writer - Batch Generation Handler
 * Generates content inline for non-single modes and renders the batch studio.
 * 
 * The generation engine remains sequential; this file only presents its state.
 */
import {
  applyContentWriterSeoFormFields,
  applyHostedKnowledgeStoreFields,
  getBinaryFieldValue,
} from "../utils/form-data-fields.js";

(function () {
  "use strict";

  const __ = window.wp?.i18n?.__ || function (str) { return str; };
  const IMAGE_RECOVERY_MAX_ATTEMPTS = 80;
  const IMAGE_RECOVERY_POLL_DELAY_MS = 3000;
  const TITLE_REVEAL_FADE_MS = 120;
  const BATCH_STATUS_LABELS = {
    waiting: __("Queued", "gpt3-ai-content-generator"),
    running: __("Running", "gpt3-ai-content-generator"),
    success: __("Done", "gpt3-ai-content-generator"),
    error: __("Failed", "gpt3-ai-content-generator"),
    stopped: __("Stopped", "gpt3-ai-content-generator"),
  };

  const STEP_DEFS = [
    {
      key: "title",
      label: __("Title", "gpt3-ai-content-generator"),
      isEnabled: (context) => !!context.customTitlePrompt,
    },
    {
      key: "content",
      label: __("Content", "gpt3-ai-content-generator"),
      isEnabled: () => true,
    },
    {
      key: "meta",
      label: __("Meta", "gpt3-ai-content-generator"),
      isEnabled: (context) => context.generateMeta,
    },
    {
      key: "focus",
      label: __("Focus", "gpt3-ai-content-generator"),
      isEnabled: (context) => context.generateFocus,
    },
    {
      key: "excerpt",
      label: __("Excerpt", "gpt3-ai-content-generator"),
      isEnabled: (context) => context.generateExcerpt,
    },
    {
      key: "tags",
      label: __("Tags", "gpt3-ai-content-generator"),
      isEnabled: (context) => context.generateTags,
    },
    {
      key: "image",
      label: __("Image", "gpt3-ai-content-generator"),
      isEnabled: (context) => context.generateImages,
    },
    {
      key: "featured",
      label: __("Featured", "gpt3-ai-content-generator"),
      isEnabled: (context) => context.generateFeatured,
    },
    {
      key: "save",
      label: __("Save", "gpt3-ai-content-generator"),
      isEnabled: () => true,
    },
  ];

  const OUTPUT_DEFS = [
    {
      key: "title",
      label: __("Title", "gpt3-ai-content-generator"),
      isEnabled: () => true,
      getSteps: (context) => context.customTitlePrompt ? ["title"] : ["content"],
    },
    {
      key: "content",
      label: __("Content", "gpt3-ai-content-generator"),
      isEnabled: () => true,
      getSteps: () => ["content"],
    },
    {
      key: "meta",
      label: __("Meta", "gpt3-ai-content-generator"),
      isEnabled: (context) => context.generateMeta,
      getSteps: () => ["meta"],
    },
    {
      key: "focus",
      label: __("Keyword", "gpt3-ai-content-generator"),
      isEnabled: (context) => context.generateFocus,
      getSteps: () => ["focus"],
    },
    {
      key: "excerpt",
      label: __("Excerpt", "gpt3-ai-content-generator"),
      isEnabled: (context) => context.generateExcerpt,
      getSteps: () => ["excerpt"],
    },
    {
      key: "tags",
      label: __("Tags", "gpt3-ai-content-generator"),
      isEnabled: (context) => context.generateTags,
      getSteps: () => ["tags"],
    },
    {
      key: "images",
      label: __("Images", "gpt3-ai-content-generator"),
      isEnabled: (context) => context.generateImages || context.generateFeatured,
      getSteps: (context) => [
        context.generateImages ? "image" : null,
        context.generateFeatured ? "featured" : null,
      ].filter(Boolean),
    },
  ];

  const batchState = {
    active: false,
    monitorVisible: false,
    stopRequested: false,
    currentRequestController: null,
    runToken: "",
    cancelRequestPromise: null,
    startedAt: 0,
    elapsedTimerId: null,
    elapsedMs: 0,
    lastOutcome: "idle",
    mode: "",
    items: [],
    rows: [],
    totals: {
      processed: 0,
      completed: 0,
      failed: 0,
    },
  };

  const layoutState = {
    actionShellParent: null,
    actionShellNextSibling: null,
  };
  let fallbackStatusClearTimer = null;
  let batchStartOverBound = false;

  const resetActionShellLayoutState = () => {
    layoutState.actionShellParent = null;
    layoutState.actionShellNextSibling = null;
  };

  const ensureActionShellLayoutState = (refs) => {
    const parent = layoutState.actionShellParent;
    if (!parent) {
      return;
    }

    if (
      !parent.isConnected ||
      !refs?.container ||
      !refs.container.contains(parent)
    ) {
      resetActionShellLayoutState();
      return;
    }

    if (
      layoutState.actionShellNextSibling &&
      !layoutState.actionShellNextSibling.isConnected
    ) {
      layoutState.actionShellNextSibling = null;
    }
  };

  const createBatchStopError = () => {
    const error = new Error(__("Generation stopped.", "gpt3-ai-content-generator"));
    error.code = "batch_stop";
    error.name = "AbortError";
    return error;
  };

  const isBatchStopError = (error) => error?.code === "batch_stop";
  const isProviderStopError = (error) => Boolean(error?.details?.stop_batch);

  const isLongRunningRequestTimeout = (error) =>
    Boolean(
      error &&
        (error.code === "gateway_timeout" ||
          error.code === "timeout" ||
          error.status === 504 ||
          error.isGatewayTimeout)
    );

  const getLongRunningImageMessage = () =>
    __(
      "Image generation is taking longer than usual. The post will be saved without any unfinished images.",
      "gpt3-ai-content-generator"
    );

  const createImageRequestId = () => {
    if (window.crypto && typeof window.crypto.randomUUID === "function") {
      return `cw_img_${window.crypto.randomUUID().replace(/-/g, "")}`;
    }
    return `cw_img_${Date.now().toString(36)}_${Math.random()
      .toString(36)
      .slice(2, 12)}`;
  };

  const waitForImageRecoveryPoll = (delayMs) =>
    new Promise((resolve) => {
      window.setTimeout(resolve, delayMs);
    });

  const throwIfBatchStopRequested = () => {
    if (batchState.stopRequested) {
      throw createBatchStopError();
    }
  };

  const clearCurrentRequestController = (controller) => {
    if (batchState.currentRequestController === controller) {
      batchState.currentRequestController = null;
    }
  };

  const abortCurrentBatchRequest = () => {
    const controller = batchState.currentRequestController;
    batchState.currentRequestController = null;
    if (controller) {
      controller.abort();
    }
  };

  const requestServerBatchCancellation = () => {
    const runToken = String(batchState.runToken || "");
    if (!runToken || typeof window.aipkit_apiRequest !== "function") {
      return Promise.resolve();
    }

    const nonce = document.getElementById("aipkit_content_writer_nonce")?.value || "";
    const cancellation = window
      .aipkit_apiRequest(
        "aipkit_content_writer_cancel_batch",
        {
          batch_run_token: runToken,
          _ajax_nonce: nonce,
        },
        { timeout: 15000 }
      )
      .catch((error) => {
        console.error("Content Writer batch cancellation failed:", error);
        setStatusMessage(
          __(
            "The batch stopped in this browser, but the server could not confirm cancellation. Check the queue before retrying.",
            "gpt3-ai-content-generator"
          ),
          "error"
        );
      })
      .finally(() => {
        if (batchState.cancelRequestPromise === cancellation) {
          batchState.cancelRequestPromise = null;
        }
      });

    batchState.cancelRequestPromise = cancellation;
    return cancellation;
  };

  const runBatchRequest = async (action, data, options = {}) => {
    throwIfBatchStopRequested();

    const controller = new AbortController();
    batchState.currentRequestController = controller;

    try {
      const requestData = { ...data };
      if (
        batchState.runToken &&
        ![
          "aipkit_content_writer_prepare_batch",
          "aipkit_content_writer_cancel_batch",
        ].includes(action)
      ) {
        requestData.batch_run_token = batchState.runToken;
      }

      return await window.aipkit_apiRequest(action, requestData, {
        ...options,
        signal: controller.signal,
        abortMessage: __("Generation stopped.", "gpt3-ai-content-generator"),
      });
    } catch (error) {
      if (
        batchState.stopRequested &&
        (error?.code === "aborted" || error?.name === "AbortError")
      ) {
        throw createBatchStopError();
      }
      throw error;
    } finally {
      clearCurrentRequestController(controller);
    }
  };

  const getStepContext = (baseData, postConfig) => ({
    customTitlePrompt: String(baseData.custom_title_prompt || "").trim(),
    generateMeta: baseData.generate_meta_description === "1",
    generateFocus: baseData.generate_focus_keyword === "1",
    generateExcerpt: baseData.generate_excerpt === "1",
    generateTags: baseData.generate_tags === "1",
    smartSeo:
      baseData.seo_score_improvement_enabled === "1" &&
      Boolean(window.aipkit_dashboard?.isProPlan),
    seoMaxPasses: Math.max(
      1,
      Number.parseInt(baseData.seo_score_max_passes, 10) || 3
    ),
    generateImages: postConfig.generate_images_enabled === "1",
    generateFeatured: postConfig.generate_featured_image === "1",
  });

  const getOutputStatus = (rowRefs, outputDef) => {
    const statuses = outputDef
      .getSteps(rowRefs.stepContext)
      .map((key) => rowRefs.stepStatuses[key])
      .filter(Boolean);

    if (statuses.some((status) => status === "error")) return "error";
    if (statuses.some((status) => status === "running")) return "running";
    if (
      statuses.length > 0 &&
      statuses.every((status) => ["done", "skipped"].includes(status))
    ) {
      return "done";
    }
    return "waiting";
  };

  const setBatchOutputChipStatus = (chip, status) => {
    if (!chip) return;
    const normalizedStatus = ["waiting", "running", "done", "error"].includes(
      status
    )
      ? status
      : "waiting";
    const isInteractive = chip.dataset.interactive === "true";
    const classNames = [
      "aipkit_cw_batch_output",
      `aipkit_cw_batch_output--${normalizedStatus}`,
    ];
    if (isInteractive) {
      classNames.push("aipkit_cw_batch_output--interactive");
      if (chip.getAttribute("aria-expanded") === "true") {
        classNames.push("is-expanded");
      }
    }
    chip.dataset.status = normalizedStatus;
    chip.className = classNames.join(" ");
  };

  const createBatchOutputChip = ({
    key,
    label,
    status = "waiting",
    interactive = false,
    controls = "",
  } = {}) => {
    const chip = document.createElement(interactive ? "button" : "span");
    chip.dataset.outputKey = String(key || "");
    chip.dataset.interactive = interactive ? "true" : "false";

    if (interactive) {
      chip.type = "button";
      chip.setAttribute("aria-expanded", "false");
      if (controls) {
        chip.setAttribute("aria-controls", controls);
      }
    }

    const outputIcon = document.createElement("span");
    outputIcon.className = "aipkit_cw_batch_output_icon";
    outputIcon.setAttribute("aria-hidden", "true");

    const outputLabel = document.createElement("span");
    outputLabel.textContent = String(label || "");

    chip.appendChild(outputIcon);
    chip.appendChild(outputLabel);

    setBatchOutputChipStatus(chip, status);
    return chip;
  };

  const renderOutputStatuses = (rowRefs) => {
    if (!rowRefs?.outputRefs) return;

    OUTPUT_DEFS.forEach((outputDef) => {
      const chip = rowRefs.outputRefs[outputDef.key];
      if (!chip) return;
      const status = getOutputStatus(rowRefs, outputDef);
      setBatchOutputChipStatus(chip, status);
    });
    batchSeo?.renderStatus(rowRefs);
  };

  const setStepStatus = (rowRefs, key, status) => {
    if (!rowRefs?.stepStatuses || !(key in rowRefs.stepStatuses)) return;
    rowRefs.stepStatuses[key] = status;
    renderOutputStatuses(rowRefs);
  };

  const setRemainingStepsError = (rowRefs) => {
    if (!rowRefs?.stepStatuses) return;
    Object.keys(rowRefs.stepStatuses).forEach((stepKey) => {
      if (["done", "skipped"].includes(rowRefs.stepStatuses[stepKey])) return;
      rowRefs.stepStatuses[stepKey] = "error";
    });
    renderOutputStatuses(rowRefs);
  };

  // Resolve at use time: the optional paid runtime may load after admin-main.
  let batchSeo = null;
  const getBatchSeo = () => {
    if (!batchSeo && typeof window.aipkit_createContentWriterBatchSeo === "function") {
      batchSeo = window.aipkit_createContentWriterBatchSeo({
        createOutputChip: createBatchOutputChip,
        setOutputStatus: setBatchOutputChipStatus,
        setStepStatus,
        request: runBatchRequest,
        throwIfStopped: throwIfBatchStopRequested,
        isStopError: (error) => isBatchStopError(error) || isProviderStopError(error),
      });
    }
    return batchSeo;
  };

  const getRefs = () => {
    const container = document.getElementById("aipkit_content_writer_container");
    if (!container) return null;
    const queue = container.querySelector("#aipkit_cw_batch_queue");
    const list = queue ? queue.querySelector(".aipkit_cw_batch_list") : null;
    const count = queue ? queue.querySelector(".aipkit_cw_batch_count") : null;
    const countLabel = queue ? queue.querySelector(".aipkit_cw_batch_label") : null;
    const progress = queue
      ? queue.querySelector(".aipkit_cw_batch_progress")
      : null;
    const progressBar = queue
      ? queue.querySelector(".aipkit_cw_batch_progress_bar")
      : null;
    const batchHeaderActions = queue
      ? queue.querySelector(".aipkit_cw_batch_header_actions")
      : null;
    const briefTemplate = queue
      ? queue.querySelector("#aipkit_cw_batch_brief_template_value")
      : null;
    const briefModel = queue
      ? queue.querySelector("#aipkit_cw_batch_brief_model_value")
      : null;
    const briefPublish = queue
      ? queue.querySelector("#aipkit_cw_batch_brief_publish_value")
      : null;
    const briefImagesRow = queue
      ? queue.querySelector("#aipkit_cw_batch_brief_images_row")
      : null;
    const briefImages = queue
      ? queue.querySelector("#aipkit_cw_batch_brief_images_value")
      : null;
    const briefSeoRow = queue
      ? queue.querySelector("#aipkit_cw_batch_brief_seo_row")
      : null;
    const briefSeo = queue
      ? queue.querySelector("#aipkit_cw_batch_brief_seo_value")
      : null;
    const briefContextRow = queue
      ? queue.querySelector("#aipkit_cw_batch_brief_context_row")
      : null;
    const briefContext = queue
      ? queue.querySelector("#aipkit_cw_batch_brief_context_value")
      : null;
    const briefNoFeatures = queue
      ? queue.querySelector("#aipkit_cw_batch_brief_no_features")
      : null;
    const briefSource = queue
      ? queue.querySelector("#aipkit_cw_batch_brief_source_value")
      : null;
    const progressTitle = queue
      ? queue.querySelector("#aipkit_cw_batch_progress_title")
      : null;
    const elapsed = queue
      ? queue.querySelector("#aipkit_cw_batch_elapsed")
      : null;
    const startOverActions = queue
      ? queue.querySelector(".aipkit_cw_batch_session_actions")
      : null;
    const startOverBtn = queue
      ? queue.querySelector("#aipkit_cw_batch_start_over_btn")
      : null;
    const statWaiting = queue
      ? queue.querySelector("#aipkit_cw_batch_stat_waiting")
      : null;
    const statRunning = queue
      ? queue.querySelector("#aipkit_cw_batch_stat_running")
      : null;
    const statSuccess = queue
      ? queue.querySelector("#aipkit_cw_batch_stat_success")
      : null;
    const statFailed = queue
      ? queue.querySelector("#aipkit_cw_batch_stat_failed")
      : null;
    const statStopped = queue
      ? queue.querySelector("#aipkit_cw_batch_stat_stopped")
      : null;
    const status = container.querySelector("#aipkit_content_writer_form_status");
    const generateBtn = container.querySelector(
      "#aipkit_content_writer_generate_btn"
    );
    const actionShell = container.querySelector(".aipkit_cw_action_shell");
    const generateToggle = container.querySelector(".aipkit_cw_action_disclosure");
    const generateMenu = container.querySelector(".aipkit_cw_action_menu");
    const generateMenuItems = generateMenu
      ? Array.from(generateMenu.querySelectorAll(".aipkit_cw_action_menu_option"))
      : [];
    const config = {
      container,
      queue,
      list,
      count,
      countLabel,
      progress,
      progressBar,
      batchHeaderActions,
      briefTemplate,
      briefModel,
      briefPublish,
      briefImagesRow,
      briefImages,
      briefSeoRow,
      briefSeo,
      briefContextRow,
      briefContext,
      briefNoFeatures,
      briefSource,
      progressTitle,
      elapsed,
      startOverActions,
      startOverBtn,
      statWaiting,
      statRunning,
      statSuccess,
      statFailed,
      statStopped,
      status,
      generateBtn,
      actionShell,
      generateToggle,
      generateMenu,
      generateMenuItems,
    };
    return config;
  };

  const getForm = (refs) =>
    refs?.container?.querySelector("#aipkit_content_writer_form") || null;

  const getFormField = (refs, name) => {
    const form = getForm(refs);
    return form?.elements?.[name] || null;
  };

  const getLines = (value) =>
    String(value || "")
      .split(/\r?\n/)
      .map((line) => line.trim())
      .filter(Boolean);

  const truncateText = (value, max = 72) => {
    const text = stripText(value);
    if (!text) {
      return "";
    }
    return text.length > max ? `${text.slice(0, max - 1)}…` : text;
  };

  const truncateMiddle = (value, max = 24) => {
    const text = stripText(value);
    if (!text || text.length <= max) {
      return text;
    }
    const edge = Math.max(4, Math.floor((max - 1) / 2));
    return `${text.slice(0, edge)}…${text.slice(-edge)}`;
  };

  const formatCountLabel = (count, singular, plural) =>
    `${count} ${count === 1 ? singular : plural}`;

  const getTopicPreview = (value) =>
    truncateText(String(value || "").split("|")[0] || "", 72);

  const getHostLabel = (value) => {
    const rawValue = stripText(value);
    if (!rawValue) {
      return "";
    }
    try {
      return new URL(rawValue).hostname.replace(/^www\./, "");
    } catch (error) {
      return rawValue
        .replace(/^https?:\/\//i, "")
        .replace(/^www\./i, "")
        .split(/[/?#]/)[0];
    }
  };

  const summarizeHosts = (lines, max = 2) => {
    const hosts = [];
    lines.forEach((line) => {
      const host = getHostLabel(line);
      if (host && !hosts.includes(host)) {
        hosts.push(host);
      }
    });
    const visibleHosts = hosts.slice(0, max);
    if (!visibleHosts.length) {
      return "";
    }
    if (hosts.length > max) {
      return `${visibleHosts.join(", ")} +${hosts.length - max}`;
    }
    return visibleHosts.join(", ");
  };

  const getBatchSourceSnapshot = (refs, mode) => {
    const waitingLabel = __("Waiting for input", "gpt3-ai-content-generator");

    if (!mode) {
      return {
        brief: waitingLabel,
      };
    }

    if (mode === "task") {
      const topics = getLines(getFormField(refs, "content_title_bulk")?.value)
        .map(getTopicPreview)
        .filter(Boolean);
      const firstTopic = topics[0] || "";
      const remainingCount = Math.max(0, topics.length - 1);
      return {
        brief:
          firstTopic && remainingCount > 0
            ? `${firstTopic} (+${remainingCount})`
            : firstTopic || __("Manual queue", "gpt3-ai-content-generator"),
      };
    }

    if (mode === "csv") {
      const fileName = truncateText(
        refs?.container?.querySelector("[data-csv-file-name]")?.textContent || "",
        72
      );
      return {
        brief: fileName || __("Uploaded CSV", "gpt3-ai-content-generator"),
      };
    }

    if (mode === "rss") {
      const feeds = getLines(getFormField(refs, "rss_feeds")?.value);
      const hostSummary = summarizeHosts(feeds);
      return {
        brief:
          hostSummary ||
          (feeds.length
            ? formatCountLabel(
                feeds.length,
                __("feed", "gpt3-ai-content-generator"),
                __("feeds", "gpt3-ai-content-generator")
              )
            : __("RSS feeds", "gpt3-ai-content-generator")),
      };
    }

    if (mode === "url") {
      const urls = getLines(getFormField(refs, "url_list")?.value);
      const hostSummary = summarizeHosts(urls);
      return {
        brief:
          hostSummary ||
          (urls.length
            ? formatCountLabel(
                urls.length,
                __("URL", "gpt3-ai-content-generator"),
                __("URLs", "gpt3-ai-content-generator")
              )
            : __("Website URLs", "gpt3-ai-content-generator")),
      };
    }

    if (mode === "gsheets") {
      const sheetId = truncateMiddle(getFormField(refs, "gsheets_sheet_id")?.value, 22);
      return {
        brief: sheetId
          ? `${__("Sheet", "gpt3-ai-content-generator")} ${sheetId}`
          : __("Google Sheet", "gpt3-ai-content-generator"),
      };
    }

    return {
      brief: waitingLabel,
    };
  };

  const setBriefValue = (node, text, placeholder = __("Not set", "gpt3-ai-content-generator")) => {
    if (!node) {
      return;
    }

    const nextValue = stripText(text) || placeholder;
    node.textContent = nextValue;
    node.classList.toggle("is-placeholder", nextValue === placeholder);
    if (nextValue === placeholder) {
      node.removeAttribute("title");
    } else {
      node.title = nextValue;
    }
  };

  const getSelectedOptionLabel = (field) => {
    const option = field?.selectedOptions?.[0] || null;
    return stripText(option?.textContent || "");
  };

  const getTemplateSummary = (refs) => {
    const templateField = getFormField(refs, "cw_template_id");
    const selectedTemplate = templateField?.selectedOptions?.[0] || null;
    let label = selectedTemplate?.value
      ? stripText(selectedTemplate.textContent)
      : "";

    if (!label) {
      label = stripText(
        refs?.container?.querySelector("#aipkit_cw_template_picker_label")
          ?.textContent || ""
      );
    }

    label = label.replace(/\s*\([^)]*\bwords?\b[^)]*\)\s*$/i, "").trim();
    if (!label || label === __("Select template", "gpt3-ai-content-generator")) {
      label = __("Custom", "gpt3-ai-content-generator");
    }

    if (window.aipkit_cw_template_state?.currentTemplateModified) {
      label = `${label} · ${__("Edited", "gpt3-ai-content-generator")}`;
    }

    return label;
  };

  const getModelSummary = (refs) => {
    const modelSelection = refs?.container?.querySelector(
      "#aipkit_content_writer_ai_selection"
    );
    return (
      getSelectedOptionLabel(modelSelection) ||
      stripText(batchState.postConfig?.ai_model || "")
    );
  };

  const getImageSummary = (refs) => {
    const imageSelection = refs?.container?.querySelector(
      "#aipkit_content_writer_image_selection"
    );
    const providerField = getFormField(refs, "image_provider");
    const providerLabel = getSelectedOptionLabel(providerField);
    return (
      getSelectedOptionLabel(imageSelection) ||
      stripText(batchState.postConfig?.image_model || "") ||
      providerLabel ||
      stripText(batchState.postConfig?.image_provider || "")
    );
  };

  const summarizeSelectedOptions = (field) => {
    const selected = field?.selectedOptions
      ? Array.from(field.selectedOptions)
          .map((option) => stripText(option.textContent))
          .filter(Boolean)
      : [];
    if (!selected.length) {
      return "";
    }
    return selected.length > 1
      ? `${selected[0]} +${selected.length - 1}`
      : selected[0];
  };

  const getContextSummary = (refs) => {
    const provider = String(
      batchState.baseData?.vector_store_provider || "openai"
    ).toLowerCase();
    const providerLabels = {
      openai: "OpenAI",
      pinecone: "Pinecone",
      qdrant: "Qdrant",
      chroma: "Chroma",
      google: "Google",
    };
    const fieldNames = {
      pinecone: "pinecone_index_name",
      qdrant: "qdrant_collection_name",
      chroma: "chroma_collection_name",
    };

    if (provider === "openai" || provider === "google") {
      return (
        summarizeSelectedOptions(
          getFormField(
            refs,
            provider === "google"
              ? "google_file_search_store_names[]"
              : "openai_vector_store_ids[]"
          )
        ) || providerLabels[provider]
      );
    }

    const sourceField = getFormField(refs, fieldNames[provider]);
    return (
      getSelectedOptionLabel(sourceField) ||
      stripText(sourceField?.value || "") ||
      providerLabels[provider] ||
      __("Knowledge base", "gpt3-ai-content-generator")
    );
  };

  const renderOptionalBriefRow = (row, valueNode, enabled, value) => {
    if (!row || !valueNode) {
      return false;
    }
    row.hidden = !enabled;
    if (!enabled) {
      valueNode.textContent = "";
      valueNode.removeAttribute("title");
      return false;
    }

    const label = stripText(value) || __("Enabled", "gpt3-ai-content-generator");
    valueNode.textContent = label;
    valueNode.title = label;
    return true;
  };

  const getPublishTargetSummary = (postConfig) => {
    if (!postConfig) {
      return "";
    }

    const status = String(postConfig.post_status || "draft");
    const scheduleMode = String(postConfig.schedule_mode || "immediate");

    if (status === "publish") {
      if (scheduleMode === "smart") {
        return __("Publish • Smart schedule", "gpt3-ai-content-generator");
      }
      if (scheduleMode === "from_input") {
        return __("Publish • Source dates", "gpt3-ai-content-generator");
      }
      return __("Publish immediately", "gpt3-ai-content-generator");
    }

    const labels = {
      draft: __("Draft", "gpt3-ai-content-generator"),
      pending: __("Pending review", "gpt3-ai-content-generator"),
      private: __("Private", "gpt3-ai-content-generator"),
      future: __("Scheduled", "gpt3-ai-content-generator"),
    };

    return labels[status] || __("Draft", "gpt3-ai-content-generator");
  };

  const renderBatchBrief = (refs, mode) => {
    if (!refs) {
      return;
    }

    const sourceSnapshot = getBatchSourceSnapshot(refs, mode);
    setBriefValue(refs.briefTemplate, getTemplateSummary(refs));
    setBriefValue(refs.briefModel, getModelSummary(refs));
    setBriefValue(
      refs.briefPublish,
      getPublishTargetSummary(batchState.postConfig)
    );

    const imagesEnabled =
      batchState.postConfig?.generate_images_enabled === "1" ||
      batchState.postConfig?.generate_featured_image === "1";
    const seoEnabled =
      batchState.baseData?.seo_score_improvement_enabled === "1" &&
      Boolean(window.aipkit_dashboard?.isProPlan);
    const contextEnabled = batchState.baseData?.enable_vector_store === "1";
    const seoProfile = stripText(
      refs.container?.querySelector("[data-aipkit-seo-active-profile-label]")
        ?.dataset?.aipkitSeoActiveProfileLabel || ""
    );

    const enabledFeatureCount = [
      renderOptionalBriefRow(
        refs.briefImagesRow,
        refs.briefImages,
        imagesEnabled,
        getImageSummary(refs)
      ),
      renderOptionalBriefRow(
        refs.briefSeoRow,
        refs.briefSeo,
        seoEnabled,
        seoProfile || __("Smart SEO", "gpt3-ai-content-generator")
      ),
      renderOptionalBriefRow(
        refs.briefContextRow,
        refs.briefContext,
        contextEnabled,
        getContextSummary(refs)
      ),
    ].filter(Boolean).length;

    if (refs.briefNoFeatures) {
      refs.briefNoFeatures.hidden = enabledFeatureCount > 0;
    }

    setBriefValue(refs.briefSource, sourceSnapshot.brief);
  };

  const renderBatchSessionActions = (refs) => {
    if (!refs?.startOverActions) {
      return;
    }

    refs.startOverActions.hidden = batchState.active || !batchState.monitorVisible;
  };

  const renderBatchPrimaryActionVisibility = (refs) => {
    if (!refs?.actionShell) {
      return;
    }

    const shouldHidePrimaryAction =
      batchState.monitorVisible && !batchState.active;

    refs.actionShell.hidden = shouldHidePrimaryAction;
    refs.actionShell.setAttribute(
      "aria-hidden",
      shouldHidePrimaryAction ? "true" : "false"
    );
  };

  const updateBatchStudioStats = () => {
    const refs = getRefs();
    if (!refs) {
      return;
    }

    const counts = {
      waiting: 0,
      running: 0,
      success: 0,
      error: 0,
      stopped: 0,
    };

    batchState.rows.forEach((rowRefs) => {
      const status = rowRefs?.row?.dataset?.status || "waiting";
      if (Object.prototype.hasOwnProperty.call(counts, status)) {
        counts[status] += 1;
      }
    });

    const metricConfigs = [
      {
        node: refs.statWaiting,
        value: counts.waiting,
        label: BATCH_STATUS_LABELS.waiting,
      },
      {
        node: refs.statRunning,
        value: counts.running,
        label: BATCH_STATUS_LABELS.running,
      },
      {
        node: refs.statSuccess,
        value: counts.success,
        label: BATCH_STATUS_LABELS.success,
      },
      {
        node: refs.statFailed,
        value: counts.error,
        label: BATCH_STATUS_LABELS.error,
      },
      {
        node: refs.statStopped,
        value: counts.stopped,
        label: BATCH_STATUS_LABELS.stopped,
      },
    ];

    metricConfigs.forEach(({ node, value, label }) => {
      if (!node) {
        return;
      }
      const shouldShow = value > 0;
      const valueNode = node.querySelector(".aipkit_cw_batch_metric_value");
      const labelNode = node.querySelector(".aipkit_cw_batch_metric_label");
      if (valueNode) {
        valueNode.textContent = String(value);
      }
      if (labelNode) {
        labelNode.textContent = label;
      }
      node.classList.toggle("is-active", value > 0);
      node.hidden = !shouldShow;
    });

  };

  const moveGenerateControlsToQueue = (refs) => {
    if (!refs?.actionShell || !refs.batchHeaderActions) {
      return;
    }

    ensureActionShellLayoutState(refs);

    if (!layoutState.actionShellParent) {
      layoutState.actionShellParent = refs.actionShell.parentNode;
      layoutState.actionShellNextSibling = refs.actionShell.nextSibling;
    }

    if (refs.actionShell.parentNode !== refs.batchHeaderActions) {
      refs.batchHeaderActions.appendChild(refs.actionShell);
    }
  };

  const restoreGenerateControls = () => {
    const refs = getRefs();
    if (!refs?.actionShell) {
      return;
    }

    ensureActionShellLayoutState(refs);

    if (!layoutState.actionShellParent) {
      return;
    }

    const { actionShellParent, actionShellNextSibling } = layoutState;
    if (
      actionShellNextSibling &&
      actionShellNextSibling.parentNode === actionShellParent
    ) {
      actionShellParent.insertBefore(refs.actionShell, actionShellNextSibling);
    } else {
      actionShellParent.appendChild(refs.actionShell);
    }
  };

  const closeTransientUi = (refs) => {
    if (!refs?.container) {
      return;
    }

    if (typeof window.aipkit_closeContentWriterInlinePromptEditor === "function") {
      window.aipkit_closeContentWriterInlinePromptEditor();
    }

    document.dispatchEvent(
      new KeyboardEvent("keydown", { key: "Escape", bubbles: true })
    );

  };

  const setBatchRunState = (isActive, mode = "", keepVisible = false) => {
    const refs = getRefs();
    if (!refs?.container) {
      return;
    }

    batchState.monitorVisible = Boolean(isActive || keepVisible);
    if (isActive && mode) {
      batchState.mode = mode;
    } else if (!batchState.monitorVisible) {
      batchState.mode = "";
    }
    refs.container.classList.toggle(
      "aipkit_cw_batch-active",
      batchState.monitorVisible
    );
    if (batchState.monitorVisible && batchState.mode) {
      refs.container.dataset.aipkitCwBatchMode = batchState.mode;
    } else {
      delete refs.container.dataset.aipkitCwBatchMode;
    }

    if (refs.queue) {
      refs.queue.hidden = !batchState.monitorVisible;
    }
    renderBatchSessionActions(refs);
    renderBatchPrimaryActionVisibility(refs);

    if (batchState.monitorVisible) {
      closeTransientUi(refs);
      moveGenerateControlsToQueue(refs);
      renderBatchPrimaryActionVisibility(refs);
      renderBatchBrief(refs, batchState.mode);
      return;
    }

    restoreGenerateControls();
    renderBatchPrimaryActionVisibility(refs);
    renderBatchBrief(refs, "");
  };

  const resetTotals = () => {
    batchState.totals.processed = 0;
    batchState.totals.completed = 0;
    batchState.totals.failed = 0;
  };

  const formatBatchElapsed = (elapsedMs) => {
    const totalSeconds = Math.max(0, Math.floor(Number(elapsedMs || 0) / 1000));
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = String(totalSeconds % 60).padStart(2, "0");
    return `${minutes}:${seconds}`;
  };

  const renderBatchElapsed = (refs = getRefs()) => {
    if (!refs?.elapsed) {
      return;
    }
    refs.elapsed.textContent = formatBatchElapsed(batchState.elapsedMs);
  };

  const clearBatchElapsedTimer = () => {
    if (batchState.elapsedTimerId) {
      window.clearInterval(batchState.elapsedTimerId);
      batchState.elapsedTimerId = null;
    }
  };

  const startBatchElapsedTimer = () => {
    clearBatchElapsedTimer();
    batchState.startedAt = Date.now();
    batchState.elapsedMs = 0;
    renderBatchElapsed();
    batchState.elapsedTimerId = window.setInterval(() => {
      batchState.elapsedMs = Date.now() - batchState.startedAt;
      renderBatchElapsed();
    }, 1000);
  };

  const stopBatchElapsedTimer = () => {
    if (batchState.startedAt) {
      batchState.elapsedMs = Date.now() - batchState.startedAt;
    }
    clearBatchElapsedTimer();
    renderBatchElapsed();
  };

  const renderBatchProgressHeading = (refs = getRefs()) => {
    if (!refs?.progressTitle) {
      return;
    }

    const total = batchState.items.length;
    const processed = batchState.totals.processed;
    const completed = batchState.totals.completed;
    let heading = __("Generating", "gpt3-ai-content-generator");

    if (batchState.active && batchState.stopRequested) {
      heading = __("Stopping…", "gpt3-ai-content-generator");
    } else if (!batchState.active && batchState.lastOutcome === "complete") {
      heading = `${__("Generated", "gpt3-ai-content-generator")} ${completed} ${__("of", "gpt3-ai-content-generator")} ${total}`;
    } else if (!batchState.active && batchState.lastOutcome === "issues") {
      heading = `${__("Generated", "gpt3-ai-content-generator")} ${completed} ${__("of", "gpt3-ai-content-generator")} ${total} ${__("with issues", "gpt3-ai-content-generator")}`;
    } else if (!batchState.active && batchState.lastOutcome === "stopped") {
      heading = `${__("Stopped after", "gpt3-ai-content-generator")} ${processed} ${__("of", "gpt3-ai-content-generator")} ${total}`;
    }

    refs.progressTitle.textContent = heading;
  };

  const updateProgress = () => {
    const refs = getRefs();
    if (!refs || !refs.count || !refs.progressBar || !refs.progress) return;
    const total = batchState.items.length;
    const processed = batchState.totals.processed;
    refs.count.textContent = `${processed} ${__("of", "gpt3-ai-content-generator")} ${total}`;
    if (refs.countLabel) {
      refs.countLabel.textContent = __("Processed", "gpt3-ai-content-generator");
    }
    const percent = total > 0 ? Math.round((processed / total) * 100) : 0;
    refs.progressBar.style.width = `${percent}%`;
    refs.progress.setAttribute("aria-valuenow", String(percent));
    updateBatchStudioStats();
    renderBatchProgressHeading(refs);
  };

  const setStatusMessage = (text, type, autoClearMs = 0) => {
    const refs = getRefs();
    if (!refs || !refs.status) return;
    if (typeof window.aipkit_updateGeneralStatus === "function") {
      window.aipkit_updateGeneralStatus(
        text ? type || "info" : "clear",
        text || "",
        autoClearMs
      );
      return;
    }

    if (fallbackStatusClearTimer) {
      clearTimeout(fallbackStatusClearTimer);
      fallbackStatusClearTimer = null;
    }

    if (!text || type !== "error") {
      refs.status.textContent = "";
      refs.status.className = "aipkit_cw_status_badge";
      return;
    }

    refs.status.textContent = text || "";
    refs.status.classList.remove(
      "aipkit_settings_message-success",
      "aipkit_settings_message-error",
      "aipkit_settings_message-info"
    );
    if (type) {
      refs.status.classList.add(`aipkit_settings_message-${type}`);
    }

    if (text && autoClearMs > 0) {
      fallbackStatusClearTimer = setTimeout(() => {
        if (refs.status.textContent === text) {
          refs.status.textContent = "";
          refs.status.className = "aipkit_cw_status_badge";
        }
      }, autoClearMs);
    }
  };

  const getBatchEmptySourceMessage = (mode, prepareResponse = {}) => {
    if (
      mode === "gsheets" &&
      prepareResponse.empty_reason === "no_unprocessed_rows"
    ) {
      return __(
        "No unprocessed rows were found. Clear the G column for any row you want to generate again.",
        "gpt3-ai-content-generator"
      );
    }

    const messages = {
      task: __(
        "Add at least one topic before generating.",
        "gpt3-ai-content-generator"
      ),
      csv: __(
        "Upload and parse a CSV file before generating.",
        "gpt3-ai-content-generator"
      ),
      rss: __(
        "Add at least one RSS feed before generating.",
        "gpt3-ai-content-generator"
      ),
      url: __(
        "Add at least one web page before generating.",
        "gpt3-ai-content-generator"
      ),
      gsheets: __(
        "Connect a Google Sheet before generating.",
        "gpt3-ai-content-generator"
      ),
    };

    return (
      messages[mode] ||
      __("Add at least one item before generating.", "gpt3-ai-content-generator")
    );
  };

  const showBatchValidationError = (refs, message) => {
    const target = refs?.container?.querySelector(
      "#aipkit_cw_action_validation"
    );

    if (!target) {
      console.warn(
        "Content Writer action validation surface is unavailable for a batch error."
      );
      return;
    }

    if (
      typeof window.aipkit_showContentWriterValidationError === "function"
    ) {
      window.aipkit_showContentWriterValidationError(target, message);
      return;
    }

    target.textContent = message;
    target.className = "aipkit_cw_action_validation is-error";
  };

  const clearBatchValidationError = (refs) => {
    const target = refs?.container?.querySelector(
      "#aipkit_cw_action_validation"
    );
    if (!target) return;

    if (
      typeof window.aipkit_clearContentWriterValidationMessage === "function"
    ) {
      window.aipkit_clearContentWriterValidationMessage(target);
      return;
    }

    target.textContent = "";
    target.className = "aipkit_cw_action_validation";
  };

  const clearQueue = () => {
    const refs = getRefs();
    if (!refs || !refs.list) return;
    batchState.rows.forEach((rowRefs) => {
      if (rowRefs?.titleSwapTimer) {
        window.clearTimeout(rowRefs.titleSwapTimer);
      }
    });
    batchSeo?.clear();
    refs.list.innerHTML = "";
    batchState.rows = [];
    batchState.items = [];
    resetTotals();
    updateProgress();
  };

  const showQueue = () => {
    const refs = getRefs();
    if (refs?.queue) {
      refs.queue.hidden = false;
    }
  };

  const hideQueue = () => {
    const refs = getRefs();
    if (refs?.queue) {
      refs.queue.hidden = true;
    }
  };

  const createRow = (item, stepContext, index) => {
    const row = document.createElement("div");
    row.className = "aipkit_cw_batch_row";
    row.dataset.status = "waiting";

    const rowContent = document.createElement("div");
    rowContent.className = "aipkit_cw_batch_row_content";

    const header = document.createElement("div");
    header.className = "aipkit_cw_batch_row_header";

    const headerMain = document.createElement("div");
    headerMain.className = "aipkit_cw_batch_row_header_main";

    const headerSide = document.createElement("div");
    headerSide.className = "aipkit_cw_batch_row_header_side";

    const indexBadge = document.createElement("span");
    indexBadge.className = "aipkit_cw_batch_index";
    indexBadge.textContent = String(index + 1).padStart(2, "0");

    const status = document.createElement("span");
    status.className = "aipkit_cw_batch_status aipkit_cw_batch_status--waiting";
    status.textContent = BATCH_STATUS_LABELS.waiting;

    const topic = document.createElement("a");
    topic.className = "aipkit_cw_batch_topic";
    topic.target = "_blank";
    topic.rel = "noopener noreferrer";

    const topicText = document.createElement("span");
    topicText.className = "aipkit_cw_batch_topic_text";
    const originalTopic = String(item.topic || "").trim();
    topicText.textContent = originalTopic;

    topic.appendChild(topicText);

    headerMain.appendChild(indexBadge);
    headerMain.appendChild(topic);
    headerSide.appendChild(status);
    header.appendChild(headerMain);
    header.appendChild(headerSide);

    const outputs = document.createElement("div");
    outputs.className = "aipkit_cw_batch_outputs";
    const outputRefs = {};
    OUTPUT_DEFS.filter((outputDef) => outputDef.isEnabled(stepContext)).forEach(
      (outputDef) => {
        const chip = createBatchOutputChip({
          key: outputDef.key,
          label: outputDef.label,
        });

        outputs.appendChild(chip);
        outputRefs[outputDef.key] = chip;
      }
    );

    const error = document.createElement("p");
    error.className = "aipkit_cw_batch_error";
    error.hidden = true;

    rowContent.appendChild(header);
    rowContent.appendChild(outputs);
    rowContent.appendChild(error);

    row.appendChild(rowContent);

    const stepStatuses = {};
    STEP_DEFS.forEach((stepDef) => {
      stepStatuses[stepDef.key] = stepDef.isEnabled(stepContext)
        ? "waiting"
        : "skipped";
    });

    const rowRefs = {
      row,
      topic,
      topicText,
      status,
      error,
      outputs,
      outputRefs,
      stepContext,
      stepStatuses,
      originalTopic,
      displayedTitle: originalTopic,
      pendingTitle: "",
      titleSwapTimer: null,
    };

    if (stepContext.smartSeo) {
      getBatchSeo()?.attachRow(rowRefs, index);
    }

    renderOutputStatuses(rowRefs);
    return rowRefs;
  };

  const renderQueue = (items, stepContext) => {
    const refs = getRefs();
    if (!refs || !refs.list) return;
    refs.list.innerHTML = "";
    batchState.rows = items.map((item, index) => {
      const rowRefs = createRow(item, stepContext, index);
      refs.list.appendChild(rowRefs.row);
      return rowRefs;
    });
    updateBatchStudioStats();
  };

  const setRowDisplayTitle = (rowRefs, value) => {
    if (!rowRefs?.topicText) return false;
    const nextTitle = stripText(value);
    if (
      !nextTitle ||
      nextTitle === rowRefs.displayedTitle ||
      nextTitle === rowRefs.pendingTitle
    ) {
      return false;
    }

    const originalTopic = stripText(rowRefs.originalTopic);
    if (originalTopic && nextTitle !== originalTopic) {
      rowRefs.topic.setAttribute(
        "title",
        `${__("Originally", "gpt3-ai-content-generator")}: ${originalTopic}`
      );
    }

    if (rowRefs.titleSwapTimer) {
      window.clearTimeout(rowRefs.titleSwapTimer);
      rowRefs.titleSwapTimer = null;
    }

    const commitTitle = () => {
      rowRefs.topicText.textContent = nextTitle;
      rowRefs.topicText.classList.remove("is-swapping");
      rowRefs.displayedTitle = nextTitle;
      rowRefs.pendingTitle = "";
      rowRefs.titleSwapTimer = null;
    };

    const reduceMotion =
      typeof window.matchMedia === "function" &&
      window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (reduceMotion) {
      commitTitle();
      return true;
    }

    rowRefs.pendingTitle = nextTitle;
    rowRefs.topicText.classList.add("is-swapping");
    rowRefs.titleSwapTimer = window.setTimeout(
      commitTitle,
      TITLE_REVEAL_FADE_MS
    );
    return true;
  };

  const setRowStatus = (rowRefs, status, options = {}) => {
    if (!rowRefs) return;
    rowRefs.row.classList.toggle("is-active", status === "running");
    rowRefs.row.dataset.status = status;
    rowRefs.status.className = `aipkit_cw_batch_status aipkit_cw_batch_status--${status}`;

    rowRefs.status.textContent = BATCH_STATUS_LABELS[status] || status;

    if (rowRefs.error) {
      rowRefs.error.hidden = true;
      rowRefs.error.textContent = "";
      rowRefs.error.removeAttribute("title");
    }

    const finalTitle =
      status === "success" ? stripText(options.finalTitle) : "";
    const originalTopic = stripText(rowRefs.originalTopic);
    const hasReplacementTitle =
      finalTitle && originalTopic && finalTitle !== originalTopic;

    if (hasReplacementTitle) {
      setRowDisplayTitle(rowRefs, finalTitle);
    }

    if (options.viewLink) {
      rowRefs.topic.href = options.viewLink;
      rowRefs.topic.classList.add("is-linked");
      rowRefs.topic.setAttribute(
        "aria-label",
        hasReplacementTitle
          ? `${__(
              "View generated item",
              "gpt3-ai-content-generator"
            )}: ${finalTitle}. ${__(
              "Originally",
              "gpt3-ai-content-generator"
            )}: ${originalTopic}`
          : __("View generated item", "gpt3-ai-content-generator")
      );
      rowRefs.topic.setAttribute(
        "title",
        hasReplacementTitle
          ? `${__("Originally", "gpt3-ai-content-generator")}: ${originalTopic}`
          : __("View generated item", "gpt3-ai-content-generator")
      );
    } else {
      rowRefs.topic.removeAttribute("href");
      rowRefs.topic.classList.remove("is-linked");
      rowRefs.topic.removeAttribute("aria-label");
      rowRefs.topic.removeAttribute("title");
    }

    if (status === "error" && rowRefs.error) {
      const message = formatErrorSummary(options.summary);
      if (message) {
        rowRefs.error.textContent = message;
        rowRefs.error.title = stripText(options.summary);
        rowRefs.error.hidden = false;
      }
    }

    batchSeo?.setRowStatus(rowRefs, status);

    renderOutputStatuses(rowRefs);

    updateBatchStudioStats();
  };

  const stripText = (value) =>
    String(value || "")
      .replace(/<[^>]*>/g, " ")
      .replace(/\s+/g, " ")
      .trim();

  const formatErrorSummary = (message) => {
    const text = stripText(message);
    if (!text) return "";
    return text.length > 180 ? `${text.slice(0, 177)}…` : text;
  };

  const buildSummary = (content, excerpt) => {
    const summaryText = stripText(excerpt || content);
    if (!summaryText) return "";
    return summaryText.length > 180
      ? `${summaryText.slice(0, 180)}…`
      : summaryText;
  };

  const getBaseFormData = (form) => {
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    applyContentWriterSeoFormFields(data, form);
    applyHostedKnowledgeStoreFields(data, form);
    const nonce = document.getElementById("aipkit_content_writer_nonce");
    if (nonce && nonce.value) {
      data._ajax_nonce = nonce.value;
    }
    return data;
  };

  const getCurrentTaskEntryView = () => {
    const taskEntryRoot = document.querySelector(
      '#aipkit_content_writer_container [data-aipkit-bulk-source-panel="task"]'
    );
    const taskEntryShell = document.querySelector(
      "#aipkit_content_writer_container .aipkit_cw_task_entry_shell"
    );

    return (
      taskEntryRoot?.dataset.taskEntryView ||
      taskEntryShell?.dataset.taskEntryView ||
      "single"
    );
  };

  const getPostBaseConfig = (form, generationMode) => {
    if (typeof window.aipkit_syncImageProviderOptions === "function") {
      window.aipkit_syncImageProviderOptions(form);
    }

    const categoriesSelect = form.elements["post_categories[]"];
    const categories = categoriesSelect
      ? Array.from(categoriesSelect.selectedOptions).map((opt) => opt.value)
      : [];

    const taskEntryView = getCurrentTaskEntryView();
    const isManualSingle =
      generationMode === "task" && taskEntryView === "single";
    const scheduleMode =
      isManualSingle
        ? "immediate"
        : form.querySelector('input[name="schedule_mode"]:checked')?.value ||
          "immediate";

    const config = {
      ai_provider: form.elements["ai_provider"]?.value || "",
      ai_model: form.elements["ai_model"]?.value || "",
      ai_temperature: form.elements["ai_temperature"]?.value || "",
      reasoning_effort: form.elements["reasoning_effort"]?.value || "",
      cw_generation_mode: form.elements["cw_generation_mode"]?.value || "",
      content_length: form.elements["content_length"]?.value || "medium",
      post_type: form.elements["post_type"]?.value || "post",
      post_author: form.elements["post_author"]?.value || "",
      post_status: form.elements["post_status"]?.value || "draft",
      post_content_format: form.elements.post_content_format?.value || "html",
      post_schedule_date: form.elements["post_schedule_date"]?.value || "",
      post_schedule_time: form.elements["post_schedule_time"]?.value || "",
      schedule_mode: scheduleMode,
      smart_schedule_start_datetime:
        isManualSingle
          ? ""
          : form.elements["smart_schedule_start_datetime"]?.value || "",
      smart_schedule_interval_value:
        isManualSingle
          ? ""
          : form.elements["smart_schedule_interval_value"]?.value || "",
      smart_schedule_interval_unit:
        isManualSingle
          ? "hours"
          : form.elements["smart_schedule_interval_unit"]?.value || "",
      post_categories: categories,
      generate_toc: getBinaryFieldValue(form.elements["generate_toc"], "0"),
      generate_seo_slug: getBinaryFieldValue(
        form.elements["generate_seo_slug"],
        "0"
      ),
      seo_score_improvement_enabled: getBinaryFieldValue(
        form.elements["seo_score_improvement_enabled"],
        "0"
      ),
      seo_score_continue_until_target: getBinaryFieldValue(
        form.elements["seo_score_continue_until_target"],
        "1"
      ),
      seo_score_target: form.elements["seo_score_target"]?.value || "100",
      seo_score_max_passes:
        form.elements["seo_score_max_passes"]?.value || "3",
      seo_score_profile: form.elements["seo_score_profile"]?.value || "auto",
      seo_score_disabled_rules:
        form.elements["seo_score_disabled_rules"]?.value ||
        (typeof window.aipkit_getDefaultSmartSeoDisabledRules === "function"
          ? window.aipkit_getDefaultSmartSeoDisabledRules()
          : "[]"),
      generate_images_enabled: form.elements["generate_images_enabled"]?.checked
        ? "1"
        : "0",
      generate_featured_image: form.elements["generate_featured_image"]?.checked
        ? "1"
        : "0",
      image_provider: form.elements["image_provider"]?.value || "",
      image_model: form.elements["image_model"]?.value || "",
      image_provider_options: form.elements["image_provider_options"]?.value || "{}",
      image_prompt: form.elements["image_prompt"]?.value || "",
      featured_image_prompt: form.elements["featured_image_prompt"]?.value || "",
      image_count: form.elements["image_count"]?.value || "1",
      image_placement: form.elements["image_placement"]?.value || "after_first_h2",
      image_placement_param_x:
        form.elements["image_placement_param_x"]?.value || "",
      image_alignment: form.elements["image_alignment"]?.value || "none",
      image_size: form.elements["image_size"]?.value || "large",
      pexels_orientation: form.elements["pexels_orientation"]?.value || "",
      pexels_size: form.elements["pexels_size"]?.value || "",
      pexels_color: form.elements["pexels_color"]?.value || "",
      pixabay_orientation: form.elements["pixabay_orientation"]?.value || "",
      pixabay_image_type: form.elements["pixabay_image_type"]?.value || "",
      pixabay_category: form.elements["pixabay_category"]?.value || "",
      generate_image_title: form.elements["generate_image_title"]?.checked
        ? "1"
        : "0",
      generate_image_alt_text: form.elements["generate_image_alt_text"]?.checked
        ? "1"
        : "0",
      generate_image_caption: form.elements["generate_image_caption"]?.checked
        ? "1"
        : "0",
      generate_image_description:
        form.elements["generate_image_description"]?.checked ? "1" : "0",
      image_title_prompt: form.elements["image_title_prompt"]?.value || "",
      image_alt_text_prompt: form.elements["image_alt_text_prompt"]?.value || "",
      image_caption_prompt: form.elements["image_caption_prompt"]?.value || "",
      image_description_prompt:
        form.elements["image_description_prompt"]?.value || "",
    };

    return typeof window.aipkit_normalizeContentWriterSeoConfig === "function"
      ? window.aipkit_normalizeContentWriterSeoConfig(config)
      : config;
  };

  const buildContentRequest = (baseData, itemConfig) => {
    const topic = itemConfig.content_title || itemConfig.topic || "";
    const keywords = itemConfig.inline_keywords || itemConfig.keywords || "";
    const contentTitle = keywords ? `${topic} | ${keywords}` : topic;

    const data = {
      ...baseData,
      content_title: contentTitle,
    };

    if (itemConfig.rss_description) {
      data.rss_description = itemConfig.rss_description;
    }
    if (itemConfig.source_url) {
      data.source_url = itemConfig.source_url;
    }
    if (itemConfig.url_content_context) {
      data.url_content_context = itemConfig.url_content_context;
    }
    if (itemConfig.gsheets_row_index) {
      data.gsheets_row_index = itemConfig.gsheets_row_index;
    }
    return { data, topic, keywords };
  };

  const applyResolvedKeywordResponseToRequest = (
    requestData,
    payload,
    topic
  ) => {
    if (!payload || !payload.resolved_focus_keyword) {
      return "";
    }

    const resolvedKeywords = String(payload.resolved_keywords || "").trim();
    if (!resolvedKeywords) {
      return "";
    }

    if (payload.resolved_keyword_source === "inline") {
      requestData.content_title =
        payload.resolved_content_title || `${topic} | ${resolvedKeywords}`;
    } else {
      requestData.content_keywords = resolvedKeywords;
    }

    return resolvedKeywords;
  };

  const buildImageRequest = (
    postConfig,
    originalTopic,
    finalTitle,
    keywords,
    excerpt,
    nonce,
    conversationUuid
  ) => ({
    original_topic: originalTopic,
    final_title: finalTitle,
    post_title: finalTitle,
    keywords: keywords,
    excerpt: excerpt || "",
    image_provider: postConfig.image_provider,
    image_model: postConfig.image_model,
    image_provider_options: postConfig.image_provider_options,
    image_prompt: postConfig.image_prompt,
    image_alignment: postConfig.image_alignment,
    image_size: postConfig.image_size,
    featured_image_prompt: postConfig.featured_image_prompt,
    pexels_orientation: postConfig.pexels_orientation,
    pexels_size: postConfig.pexels_size,
    pexels_color: postConfig.pexels_color,
    pixabay_orientation: postConfig.pixabay_orientation,
    pixabay_image_type: postConfig.pixabay_image_type,
    pixabay_category: postConfig.pixabay_category,
    generate_image_title: postConfig.generate_image_title,
    generate_image_alt_text: postConfig.generate_image_alt_text,
    generate_image_caption: postConfig.generate_image_caption,
    generate_image_description: postConfig.generate_image_description,
    image_title_prompt: postConfig.image_title_prompt,
    image_alt_text_prompt: postConfig.image_alt_text_prompt,
    image_caption_prompt: postConfig.image_caption_prompt,
    image_description_prompt: postConfig.image_description_prompt,
    ai_provider: postConfig.ai_provider,
    ai_model: postConfig.ai_model,
    ai_temperature: postConfig.ai_temperature,
    reasoning_effort: postConfig.reasoning_effort,
    cw_generation_mode: postConfig.cw_generation_mode,
    _ajax_nonce: nonce,
    generate_images_enabled: postConfig.generate_images_enabled,
    image_count: postConfig.image_count,
    generate_featured_image: postConfig.generate_featured_image,
    image_placement: postConfig.image_placement,
    image_placement_param_x: postConfig.image_placement_param_x,
    conversation_uuid: conversationUuid,
  });

  const renderContentHtml = (content) => {
    const mdRenderer = window.aipkit_getMarkdownRenderer
      ? window.aipkit_getMarkdownRenderer()
      : null;
    return mdRenderer ? mdRenderer.render(content || "") : content || "";
  };

  const buildPostPayload = (
    postConfig,
    itemConfig,
    finalTitle,
    contentHtml,
    response,
    imageData,
    nonce
  ) => {
    const categories =
      Array.isArray(itemConfig.post_categories) && itemConfig.post_categories.length
        ? itemConfig.post_categories
        : postConfig.post_categories;

    return {
      post_title: finalTitle,
      post_content: contentHtml,
      post_content_format: postConfig.post_content_format || "html",
      generated_excerpt: response.excerpt || "",
      generated_tags: response.tags || "",
      meta_description: response.meta_description || "",
      focus_keyword: response.focus_keyword || "",
      post_type: itemConfig.post_type || postConfig.post_type,
      post_author: itemConfig.post_author || postConfig.post_author,
      post_status: postConfig.post_status,
      post_schedule_date:
        itemConfig.post_schedule_date || postConfig.post_schedule_date,
      post_schedule_time:
        itemConfig.post_schedule_time || postConfig.post_schedule_time,
      post_categories: categories,
      generate_toc: postConfig.generate_toc,
      generate_seo_slug: postConfig.generate_seo_slug,
      seo_score_profile: postConfig.seo_score_profile || "auto",
      seo_score_disabled_rules:
        postConfig.seo_score_disabled_rules ||
        (typeof window.aipkit_getDefaultSmartSeoDisabledRules === "function"
          ? window.aipkit_getDefaultSmartSeoDisabledRules()
          : "[]"),
      smart_seo_slug: response.slug || "",
      image_data: imageData ? JSON.stringify(imageData) : "",
      image_alignment: postConfig.image_alignment,
      image_size: postConfig.image_size,
      cw_generation_mode: itemConfig.cw_generation_mode || postConfig.cw_generation_mode,
      gsheets_row_index: itemConfig.gsheets_row_index || "",
      gsheets_sheet_id: itemConfig.gsheets_sheet_id || "",
      gsheets_credentials:
        typeof itemConfig.gsheets_credentials === "string"
          ? itemConfig.gsheets_credentials
          : itemConfig.gsheets_credentials
            ? JSON.stringify(itemConfig.gsheets_credentials)
            : "",
      _ajax_nonce: nonce,
    };
  };

  const processItem = async (item, baseData, postConfig, rowRefs, stepContext) => {
    throwIfBatchStopRequested();

    const itemConfig = item.item_config || {};
	    const { data: requestData, topic, keywords } = buildContentRequest(
	      baseData,
	      itemConfig
	    );
	    let effectiveKeywords = keywords;

    let finalTitle = topic;
    let conversationUuid = requestData.conversation_uuid || undefined;
    const customTitlePrompt = String(requestData.custom_title_prompt || "").trim();
    const titleEnabled = !!customTitlePrompt;

    if (titleEnabled) {
      throwIfBatchStopRequested();
      setStepStatus(rowRefs, "title", "running");
      const titleResponse = await runBatchRequest(
        "aipkit_content_writer_generate_title",
        {
          ...requestData,
          conversation_uuid: conversationUuid,
        }
      );
      throwIfBatchStopRequested();
      if (!titleResponse?.new_title) {
        throw new Error(__("Title generation failed", "gpt3-ai-content-generator"));
      }
      effectiveKeywords =
        applyResolvedKeywordResponseToRequest(
          requestData,
          titleResponse,
          topic
        ) || effectiveKeywords;
      finalTitle = titleResponse.new_title;
      conversationUuid = titleResponse.conversation_uuid || conversationUuid;
      setStepStatus(rowRefs, "title", "done");
      if (!stepContext.smartSeo) {
        setRowDisplayTitle(rowRefs, finalTitle);
      }
    } else {
      setStepStatus(rowRefs, "title", "skipped");
    }

    throwIfBatchStopRequested();
    setStepStatus(rowRefs, "content", "running");
    let providerStopError = null;
    let contentResponse;
    try {
      contentResponse = await runBatchRequest(
        "aipkit_content_writer_generate_standard",
        { ...requestData, conversation_uuid: conversationUuid }
      );
    } catch (error) {
      if (!isProviderStopError(error) || !error.details.generation_result?.content) throw error;
      providerStopError = error;
      contentResponse = error.details.generation_result;
    }
    throwIfBatchStopRequested();

    if (!contentResponse?.content) {
      throw new Error(__("Content generation failed", "gpt3-ai-content-generator"));
    }
    effectiveKeywords =
      applyResolvedKeywordResponseToRequest(
        requestData,
        contentResponse,
        topic
      ) || effectiveKeywords;

    setStepStatus(rowRefs, "content", "done");
    conversationUuid =
      contentResponse.conversation_uuid || conversationUuid || undefined;
    let contentHtml = renderContentHtml(contentResponse.content);

    setStepStatus(
      rowRefs,
      "meta",
      stepContext.generateMeta ? (providerStopError && !contentResponse.meta_description ? "error" : "done") : "skipped"
    );
    setStepStatus(
      rowRefs,
      "focus",
      stepContext.generateFocus ? (providerStopError && !contentResponse.focus_keyword ? "error" : "done") : "skipped"
    );
    setStepStatus(
      rowRefs,
      "excerpt",
      stepContext.generateExcerpt ? (providerStopError && !contentResponse.excerpt ? "error" : "done") : "skipped"
    );
    setStepStatus(
      rowRefs,
      "tags",
      stepContext.generateTags ? (providerStopError && !contentResponse.tags ? "error" : "done") : "skipped"
    );

    let imageData = null;
    const imagesEnabled =
      postConfig.generate_images_enabled === "1" ||
      postConfig.generate_featured_image === "1";

    if (imagesEnabled && !providerStopError) {
      let mergedImageData = null;
      try {
        if (stepContext.generateImages) {
          setStepStatus(rowRefs, "image", "running");
        }
        if (stepContext.generateFeatured) {
          setStepStatus(rowRefs, "featured", "running");
        }
        const keywordsForImages =
          contentResponse.focus_keyword || effectiveKeywords || baseData.content_keywords || "";
        const imageRequest = buildImageRequest(
          postConfig,
          topic,
          finalTitle,
          keywordsForImages,
          contentResponse.excerpt || "",
          baseData._ajax_nonce,
          conversationUuid
        );
        const inContentEnabled = postConfig.generate_images_enabled === "1";
        const featuredEnabled = postConfig.generate_featured_image === "1";
        const imageProvider = String(postConfig.image_provider || "").toLowerCase();
        const imageModel = String(postConfig.image_model || "").toLowerCase();
        const isGptImageModel =
          imageProvider === "openai" && imageModel.startsWith("gpt-image");
        const imageCount = inContentEnabled
          ? parseInt(postConfig.image_count, 10) || 1
          : 0;
        const shouldSplitInlineImages =
          isGptImageModel && inContentEnabled && imageCount > 1;
        const shouldSplitRequests =
          (inContentEnabled && featuredEnabled) || shouldSplitInlineImages;

        const requestImages = async (overrides = {}) => {
          const imageRequestId = overrides.image_request_id || createImageRequestId();
          const requestPayload = {
            ...imageRequest,
            ...overrides,
            image_request_id: imageRequestId,
          };
          const pollImageRequest = async () => {
            for (let attempt = 0; attempt < IMAGE_RECOVERY_MAX_ATTEMPTS; attempt++) {
              await waitForImageRecoveryPoll(
                attempt === 0 ? 2500 : IMAGE_RECOVERY_POLL_DELAY_MS
              );
              throwIfBatchStopRequested();
              const pollResponse = await runBatchRequest(
                "aipkit_content_writer_generate_images",
                {
                  ...requestPayload,
                  image_request_poll: "1",
                },
                {
                  timeout: 30000,
                }
              );
              throwIfBatchStopRequested();
              if (pollResponse?.image_data) {
                return pollResponse.image_data;
              }
              if (
                pollResponse?.image_status &&
                !["running", "missing"].includes(pollResponse.image_status)
              ) {
                throw new Error(
                  __("Image generation failed", "gpt3-ai-content-generator")
                );
              }
            }
            const timeoutError = new Error(getLongRunningImageMessage());
            timeoutError.code = "gateway_timeout";
            timeoutError.isGatewayTimeout = true;
            throw timeoutError;
          };
          const normalizeImageResponse = async (imageResponse) => {
            if (imageResponse?.image_data) {
              return imageResponse.image_data;
            }
            if (imageResponse?.image_status === "running") {
              return pollImageRequest();
            }
            throw new Error(
              __("Image generation failed", "gpt3-ai-content-generator")
            );
          };
          throwIfBatchStopRequested();
          try {
            const imageResponse = await runBatchRequest(
              "aipkit_content_writer_generate_images",
              requestPayload,
              {
                timeout: isGptImageModel ? 240000 : 180000,
              }
            );
            throwIfBatchStopRequested();
            return normalizeImageResponse(imageResponse);
          } catch (error) {
            if (isLongRunningRequestTimeout(error)) {
              return pollImageRequest();
            }
            throw error;
          }
        };

        if (shouldSplitRequests) {
          const ensureMergedBase = (sourceData) => {
            if (!mergedImageData) {
              mergedImageData = {
                ...sourceData,
                in_content_images: [],
                featured_image_id: null,
                featured_image_url: null,
              };
            }
          };

          if (inContentEnabled) {
            if (shouldSplitInlineImages) {
              for (let i = 0; i < imageCount; i++) {
                const inlineData = await requestImages({
                  generate_featured_image: "0",
                  image_count: 1,
                  image_start_index: i + 1,
                });
                throwIfBatchStopRequested();
                ensureMergedBase(inlineData);
                if (Array.isArray(inlineData.in_content_images)) {
                  mergedImageData.in_content_images.push(
                    ...inlineData.in_content_images
                  );
                }
              }
            } else {
              const inlineData = await requestImages({
                generate_featured_image: "0",
              });
              throwIfBatchStopRequested();
              ensureMergedBase(inlineData);
              if (Array.isArray(inlineData.in_content_images)) {
                mergedImageData.in_content_images.push(
                  ...inlineData.in_content_images
                );
              }
            }
            if (stepContext.generateImages) {
              setStepStatus(rowRefs, "image", "done");
            }
          }
          if (featuredEnabled) {
            throwIfBatchStopRequested();
            const featuredData = await requestImages({
              generate_images_enabled: "0",
              image_count: 0,
            });
            throwIfBatchStopRequested();
            ensureMergedBase(featuredData);
            if (featuredData.featured_image_id) {
              mergedImageData.featured_image_id = featuredData.featured_image_id;
            }
            if (featuredData.featured_image_url) {
              mergedImageData.featured_image_url = featuredData.featured_image_url;
            }
            if (stepContext.generateFeatured) {
              setStepStatus(rowRefs, "featured", "done");
            }
          }

          imageData = mergedImageData;
        } else {
          imageData = await requestImages();
          if (stepContext.generateImages) {
            setStepStatus(rowRefs, "image", "done");
          }
          if (stepContext.generateFeatured) {
            setStepStatus(rowRefs, "featured", "done");
          }
        }
      } catch (error) {
        if (isBatchStopError(error)) {
          throw error;
        }
        if (!isLongRunningRequestTimeout(error) && !isProviderStopError(error)) {
          throw error;
        }
        imageData = mergedImageData || error?.details?.image_data || imageData;
        if (isProviderStopError(error)) {
          providerStopError = error;
        }
        if (stepContext.generateImages) {
          setStepStatus(rowRefs, "image", providerStopError ? "error" : "done");
        }
        if (stepContext.generateFeatured) {
          setStepStatus(rowRefs, "featured", providerStopError ? "error" : "done");
        }
      }
    } else {
      setStepStatus(rowRefs, "image", "skipped");
      setStepStatus(rowRefs, "featured", "skipped");
    }

    if (!providerStopError && stepContext.smartSeo && getBatchSeo()) {
      try {
        ({ finalTitle, contentHtml, contentResponse } = await batchSeo.run({
          rowRefs, postConfig, baseData, topic, effectiveKeywords,
          finalTitle, contentHtml, contentResponse, imageData,
        }));
      } catch (error) {
        if (!isProviderStopError(error)) throw error;
        providerStopError = error;
      }
    }

    if (stepContext.smartSeo) {
      setRowDisplayTitle(rowRefs, finalTitle);
    }

    throwIfBatchStopRequested();
    setStepStatus(rowRefs, "save", "running");
    const postPayload = buildPostPayload(
      postConfig,
      itemConfig,
      finalTitle,
      contentHtml,
      contentResponse,
      imageData,
      baseData._ajax_nonce
    );

    if (providerStopError) postPayload.post_status = "draft";
    let saveResponse;
    try {
      saveResponse = await runBatchRequest("aipkit_content_writer_save_post", postPayload);
    } catch (error) {
      if (providerStopError && !isBatchStopError(error)) {
        error.details = { ...error.details, stop_batch: true };
      }
      throw error;
    }
    throwIfBatchStopRequested();
    setStepStatus(rowRefs, "save", "done");

    const result = {
      finalTitle,
      summary: buildSummary(contentHtml, contentResponse.excerpt),
      viewLink: saveResponse.view_link || saveResponse.edit_link,
    };
    if (providerStopError) {
      providerStopError.savedResult = result;
      throw providerStopError;
    }
    return result;
  };

  const finalizeBatchRun = (reason = "complete") => {
    const refs = getRefs();
    const total = batchState.items.length;
    batchState.active = false;
    batchState.stopRequested = false;
    batchState.currentRequestController = null;
    batchState.runToken = "";
    batchState.lastOutcome = reason;
    stopBatchElapsedTimer();
    if (refs?.generateBtn) {
      if (typeof window.aipkit_resetContentWriterButtons === "function") {
        window.aipkit_resetContentWriterButtons();
      } else {
        refs.generateBtn.disabled = false;
      }
      refs.generateBtn.removeAttribute("aria-disabled");
    }
    if (refs?.generateToggle) {
      refs.generateToggle.disabled = false;
      refs.generateToggle.removeAttribute("aria-disabled");
    }
    if (refs?.generateMenuItems?.length) {
      refs.generateMenuItems.forEach((item) => {
        item.disabled = false;
        item.removeAttribute("aria-disabled");
      });
    }
    if (total === 0) {
      setBatchRunState(false);
      if (typeof window.aipkit_updateContentWriterActionState === "function") {
        window.aipkit_updateContentWriterActionState();
      }
      return;
    }
    setBatchRunState(false, batchState.mode, true);
    renderBatchProgressHeading(refs);
    if (typeof window.aipkit_updateContentWriterActionState === "function") {
      window.aipkit_updateContentWriterActionState();
    }
  };

  const handleBatchStop = () => {
    if (!batchState.active) return;
    batchState.stopRequested = true;
    requestServerBatchCancellation();
    abortCurrentBatchRequest();
    renderBatchProgressHeading();
  };

  const markRemainingStopped = (startIndex) => {
    batchState.items.slice(startIndex).forEach((_, idx) => {
      const rowRefs = batchState.rows[startIndex + idx];
      setRowStatus(rowRefs, "stopped");
    });
  };

  const runBatch = async () => {
    for (let index = 0; index < batchState.items.length; index += 1) {
      if (batchState.stopRequested) {
        markRemainingStopped(index);
        break;
      }

      const rowRefs = batchState.rows[index];
      setRowStatus(rowRefs, "running");

      try {
        const result = await processItem(
          batchState.items[index],
          batchState.baseData,
          batchState.postConfig,
          rowRefs,
          batchState.stepContext
        );
        if (batchState.stopRequested) {
          setRowStatus(rowRefs, "stopped");
          markRemainingStopped(index + 1);
          break;
        }
        setRowStatus(rowRefs, "success", result);
        batchState.totals.completed += 1;
      } catch (error) {
        if (isBatchStopError(error)) {
          setRowStatus(rowRefs, "stopped");
          markRemainingStopped(index + 1);
          break;
        }
        console.error("Content Writer batch item failed:", error);
        setRemainingStepsError(rowRefs);
        setRowStatus(rowRefs, "error", {
          ...error?.savedResult,
          summary: error?.message || BATCH_STATUS_LABELS.error,
        });
        batchState.totals.failed += 1;
        if (isProviderStopError(error)) {
          batchState.stopRequested = true;
          markRemainingStopped(index + 1);
          batchState.totals.processed += 1;
          updateProgress();
          break;
        }
      }

      batchState.totals.processed += 1;
      updateProgress();
    }

    finalizeBatchRun(
      batchState.stopRequested
        ? "stopped"
        : batchState.totals.failed > 0
        ? "issues"
        : "complete"
    );
  };

  const aipkit_cw_handleBatchGeneration = async (form, mode) => {
    if (!form || !mode) return;

    if (!window.aipkit_apiRequest) {
      console.error("Batch generation: aipkit_apiRequest not available.");
      return;
    }

    const refs = getRefs();
    if (!refs?.generateBtn) return;

    if (batchState.active) {
      setStatusMessage(
        __("Generation already in progress.", "gpt3-ai-content-generator"),
        "info"
      );
      return;
    }

    clearBatchValidationError(refs);

    if (typeof window.aipkit_validateContentWriterSchedule === "function") {
      const scheduleValidation = window.aipkit_validateContentWriterSchedule(
        form,
        mode,
        true
      );
      if (!scheduleValidation?.valid) {
        showBatchValidationError(refs, scheduleValidation.message);
        return;
      }
    }

    const baseData = getBaseFormData(form);
    const postConfig = getPostBaseConfig(form, mode);
    const stepContext = getStepContext(baseData, postConfig);

    try {
      batchState.active = true;
      batchState.stopRequested = false;
      batchState.runToken = "";
      batchState.cancelRequestPromise = null;
      batchState.lastOutcome = "running";
      resetTotals();

      if (typeof window.aipkit_setContentWriterStopMode === "function") {
        window.aipkit_setContentWriterStopMode(true);
      } else if (typeof window.aipkit_setLoadingStateOpenAI === "function") {
        window.aipkit_setLoadingStateOpenAI(
          refs.generateBtn,
          true,
          __("Generate", "gpt3-ai-content-generator"),
          __("Preparing…", "gpt3-ai-content-generator")
        );
      } else {
        refs.generateBtn.disabled = true;
      }

      setStatusMessage("", "", 0);

      const prepareResponse = await runBatchRequest(
        "aipkit_content_writer_prepare_batch",
        {
          ...baseData,
          cw_generation_mode: mode,
        }
      );

      const items = Array.isArray(prepareResponse.items)
        ? prepareResponse.items
        : [];

      const runToken = String(prepareResponse.run_token || "");
      if (!runToken) {
        throw new Error(
          __(
            "The batch session could not be prepared. Please try again.",
            "gpt3-ai-content-generator"
          )
        );
      }
      batchState.runToken = runToken;

      if (!items.length) {
        showBatchValidationError(
          refs,
          getBatchEmptySourceMessage(mode, prepareResponse)
        );
        finalizeBatchRun("idle");
        return;
      }

      clearQueue();
      // Preparation applies the source-specific limit on the server.
      batchState.items = items;
      batchState.baseData = baseData;
      batchState.postConfig = postConfig;
      batchState.stepContext = stepContext;
      renderQueue(batchState.items, stepContext);
      updateProgress();
      setBatchRunState(true, mode);
      if (refs.queue) {
        refs.queue.dataset.cwMode = mode;
      }
      renderBatchBrief(refs, mode);
      renderBatchSessionActions(refs);
      startBatchElapsedTimer();
      renderBatchProgressHeading(refs);

      await runBatch();
    } catch (error) {
      if (isBatchStopError(error)) {
        finalizeBatchRun("stopped");
        return;
      }
      console.error("Content Writer batch request failed:", error);
      const failureMessage = __(
        "Generation couldn’t start. Your topics are still here. Review the source and try again.",
        "gpt3-ai-content-generator"
      );
      const manualEntryIssues =
        error?.details?.issues || error?.data?.details?.issues || [];
      const issuesShown =
        refs?.container?.aipkitShowManualEntryIssues?.(
          manualEntryIssues,
          failureMessage
        ) || false;
      if (!issuesShown) {
        const errorCode = String(error?.code || error?.data?.code || "");
        const isScheduleError = [
          "invalid_schedule",
          "invalid_smart_schedule",
          "invalid_item_schedule",
          "schedule_not_future",
          "missing_item_schedule",
        ].some((code) => errorCode.includes(code));
        if (isScheduleError && error?.message) {
          showBatchValidationError(refs, error.message);
        } else {
          setStatusMessage(failureMessage, "error");
        }
      }
      finalizeBatchRun("issues");
    }
  };

  const aipkit_cw_resetBatchQueue = () => {
    batchState.active = false;
    batchState.monitorVisible = false;
    batchState.stopRequested = false;
    batchState.runToken = "";
    batchState.cancelRequestPromise = null;
    batchState.startedAt = 0;
    batchState.elapsedMs = 0;
    clearBatchElapsedTimer();
    abortCurrentBatchRequest();
    batchState.lastOutcome = "idle";
    clearQueue();
    hideQueue();
    setBatchRunState(false);
    clearBatchValidationError(getRefs());
  };

  const bindBatchStartOver = () => {
    if (batchStartOverBound) {
      return;
    }

    document.addEventListener("click", (event) => {
      const button = event.target.closest("#aipkit_cw_batch_start_over_btn");
      if (!button) {
        return;
      }

      const container = button.closest("#aipkit_content_writer_container");
      if (!container) {
        return;
      }

      event.preventDefault();
      aipkit_cw_resetBatchQueue();
      setStatusMessage("", "", 0);
    });
    batchStartOverBound = true;
  };

  bindBatchStartOver();

  window.aipkit_cw_handleBatchGeneration = aipkit_cw_handleBatchGeneration;
  window.aipkit_cw_resetBatchQueue = aipkit_cw_resetBatchQueue;
  window.aipkit_cw_requestBatchStop = handleBatchStop;
  window.aipkit_cw_isBatchActive = () => batchState.active === true;
  window.aipkit_cw_isBatchMonitorVisible = () => batchState.monitorVisible === true;
  window.aipkit_cw_batchOutputChips = {
    create: createBatchOutputChip,
    setStatus: setBatchOutputChipStatus,
  };
})();
