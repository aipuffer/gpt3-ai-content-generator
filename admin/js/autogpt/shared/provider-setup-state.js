/**
 * Automated Tasks provider setup state.
 *
 * The localized status map describes whether the required settings are present;
 * it does not claim that a remote credential has been verified successfully.
 */
(function () {
  "use strict";

  const PROVIDER_LABELS = {
    aipuffercloud: "AI Puffer Cloud",
    openai: "OpenAI",
    google: "Google",
    claude: "Anthropic",
    openrouter: "OpenRouter",
    azure: "Azure",
    ollama: "Ollama",
    deepseek: "DeepSeek",
    xai: "xAI",
    replicate: "Replicate",
    pexels: "Pexels",
    pixabay: "Pixabay",
    pinecone: "Pinecone",
    qdrant: "Qdrant",
    chroma: "Chroma",
  };

  const normalizeProvider = (value) =>
    String(value || "").trim().toLowerCase();

  const getStatusMap = () => {
    const map =
      window.aipkit_provider_status ||
      window.aipkit_dashboard?.providerStatus;
    return map && typeof map === "object" ? map : {};
  };

  const getSetupState = (provider) => {
    const key = normalizeProvider(provider);
    const map = getStatusMap();
    if (!key || !Object.prototype.hasOwnProperty.call(map, key)) {
      return "unknown";
    }
    return map[key] ? "configured" : "missing";
  };

  const isConfigured = (provider) => getSetupState(provider) === "configured";

  const getProviderLabel = (provider, fallback = "") => {
    const key = normalizeProvider(provider);
    return PROVIDER_LABELS[key] || String(fallback || provider || "Provider");
  };

  const getSetupMessage = (provider) =>
    `Connect ${getProviderLabel(provider)} to use it in this automation.`;

  const sortProviderEntries = (entries) =>
    (Array.isArray(entries) ? entries : [])
      .map((entry, index) => ({
        ...entry,
        _aipkitSetupIndex: index,
        setupState: getSetupState(entry?.key),
      }))
      .sort((first, second) => {
        const rank = { configured: 0, unknown: 1, missing: 2 };
        return (
          (rank[first.setupState] ?? 1) - (rank[second.setupState] ?? 1) ||
          first._aipkitSetupIndex - second._aipkitSetupIndex
        );
      })
      .map(({ _aipkitSetupIndex, ...entry }) => entry);

  function resolveNewTaskProvider(providerSelect, preferredProvider) {
    const preferred = normalizeProvider(preferredProvider || "openai");
    const availableProviders = Array.from(providerSelect?.options || [])
      .filter((option) => option.value && !option.disabled)
      .map((option) => normalizeProvider(option.value));
    const normalizedPreferred = availableProviders.includes(preferred)
      ? preferred
      : availableProviders[0] || preferred;
    const preferredState = getSetupState(normalizedPreferred);

    if (preferredState !== "missing") {
      return {
        provider: normalizedPreferred,
        preferredProvider: normalizedPreferred,
        fellBack: false,
        needsSetup: false,
      };
    }

    const fallback = availableProviders.find(isConfigured);
    if (fallback) {
      return {
        provider: fallback,
        preferredProvider: normalizedPreferred,
        fellBack: fallback !== normalizedPreferred,
        needsSetup: false,
      };
    }

    return {
      provider: normalizedPreferred,
      preferredProvider: normalizedPreferred,
      fellBack: false,
      needsSetup: true,
    };
  }

  function syncFallbackNote(providerSelect, resolution) {
    const modelRow = providerSelect
      ?.closest(".aipkit_autogpt_setup_fields")
      ?.querySelector(".aipkit_cw_ai_row");
    if (!modelRow) return;

    let note = modelRow.nextElementSibling;
    if (!note?.matches?.("[data-aipkit-provider-fallback-note]")) {
      note = document.createElement("p");
      note.className = "aipkit_autogpt_provider_fallback_note";
      note.dataset.aipkitProviderFallbackNote = "1";
      modelRow.insertAdjacentElement("afterend", note);
    }

    if (!resolution?.fellBack) {
      note.hidden = true;
      note.textContent = "";
      return;
    }

    note.textContent = `Using ${getProviderLabel(
      resolution.provider
    )} because ${getProviderLabel(resolution.preferredProvider)} needs setup.`;
    note.hidden = false;
  }

  function syncControlFallbackNote(control, resolution) {
    const row = control?.closest(
      ".aipkit_ci_target_row, .aipkit_cw_kb_row, .aipkit_autogpt_question_row"
    );
    if (!row) return;
    let note = row.nextElementSibling;
    if (!note?.matches?.("[data-aipkit-control-fallback-note]")) {
      note = document.createElement("p");
      note.className = "aipkit_autogpt_provider_fallback_note";
      note.dataset.aipkitControlFallbackNote = "1";
      row.insertAdjacentElement("afterend", note);
    }
    if (!resolution?.fellBack) {
      note.hidden = true;
      note.textContent = "";
      return;
    }
    note.textContent = `Using ${getProviderLabel(
      resolution.provider
    )} because ${getProviderLabel(resolution.preferredProvider)} needs setup.`;
    note.hidden = false;
  }

  const selectedEmbeddingProvider = (field) => {
    const option = field?.selectedOptions?.[0];
    return normalizeProvider(
      option?.dataset?.provider || String(field?.value || "").split("::")[0]
    );
  };

  function annotateProviderOptions(select, { nativeLabels = false } = {}) {
    if (!select?.options) return;
    Array.from(select.options).forEach((option) => {
      const provider = normalizeProvider(
        option.dataset.provider || String(option.value || "").split("::")[0]
      );
      if (!provider || getSetupState(provider) === "unknown") return;
      const needsSetup = getSetupState(provider) === "missing";
      option.dataset.setupRequired = needsSetup ? "true" : "false";
      if (nativeLabels && option.value) {
        const originalLabel =
          option.dataset.aipkitOriginalLabel || String(option.textContent || "").trim();
        option.dataset.aipkitOriginalLabel = originalLabel;
        option.textContent = needsSetup
          ? `${originalLabel} — Needs setup`
          : originalLabel;
      }
    });
    select._aipkitUnifiedModelSync?.();
  }

  function getTaskCredentialIssue(form) {
    if (!form) return null;
    const taskType = String(form.elements?.task_type?.value || "");
    const issue = (provider, step, control) => {
      const key = normalizeProvider(provider);
      return getSetupState(key) === "missing"
        ? { provider: key, step, control, message: getSetupMessage(key) }
        : null;
    };

    let result = null;
    if (taskType.startsWith("content_writing")) {
      result = issue(form.elements?.ai_provider?.value, "ai", form.elements?.ai_provider);
    } else if (taskType === "enhance_existing_content") {
      result = issue(
        form.elements?.ce_ai_provider?.value,
        "ai",
        form.elements?.ce_ai_provider
      );
    } else if (taskType === "community_reply_comments") {
      result = issue(
        form.elements?.cc_ai_provider?.value,
        "ai",
        form.elements?.cc_ai_provider
      );
    }
    if (result) return result;

    if (taskType.startsWith("content_writing")) {
      const imagesEnabled =
        form.elements?.generate_images_enabled?.checked ||
        form.elements?.generate_featured_image?.checked;
      if (imagesEnabled) {
        result = issue(
          form.elements?.image_provider?.value,
          "images",
          form.elements?.image_provider
        );
        if (result) return result;
      }

      if (form.elements?.enable_vector_store?.checked) {
        result = issue(
          form.elements?.vector_store_provider?.value,
          "knowledge",
          form.querySelector("#aipkit_task_cw_kb_mode_control") ||
            form.elements?.vector_store_provider
        );
        if (result) return result;
        const provider = normalizeProvider(
          form.elements?.vector_store_provider?.value
        );
        if (["pinecone", "qdrant", "chroma", "local"].includes(provider)) {
          result = issue(
            form.elements?.vector_embedding_provider?.value,
            "knowledge",
            form.elements?.vector_embedding_model ||
              form.elements?.vector_embedding_provider
          );
          if (result) return result;
        }
      }
    }

    if (taskType === "enhance_existing_content" && form.elements?.ce_enable_vector_store?.checked) {
      result = issue(
        form.elements?.ce_vector_store_provider?.value,
        "knowledge",
        form.querySelector("#aipkit_task_ce_kb_mode_control") ||
          form.elements?.ce_vector_store_provider
      );
      if (result) return result;
      const provider = normalizeProvider(
        form.elements?.ce_vector_store_provider?.value
      );
      if (["pinecone", "qdrant", "chroma", "local"].includes(provider)) {
        result = issue(
          form.elements?.ce_vector_embedding_provider?.value,
          "knowledge",
          form.elements?.ce_vector_embedding_model ||
            form.elements?.ce_vector_embedding_provider
        );
        if (result) return result;
      }
    }

    if (taskType === "content_indexing") {
      result = issue(
        form.elements?.target_store_provider?.value,
        "content",
        form.elements?.target_store_provider
      );
      if (result) return result;
      const provider = normalizeProvider(
        form.elements?.target_store_provider?.value
      );
      if (["pinecone", "qdrant", "chroma", "local"].includes(provider)) {
        const embeddingField = form.elements?.embedding_model;
        result = issue(
          selectedEmbeddingProvider(embeddingField),
          "content",
          embeddingField
        );
        if (result) return result;
      }
    }

    return null;
  }

  function getDataCredentialIssue(data) {
    if (!data || typeof data !== "object") return null;
    const issue = (provider) => {
      const key = normalizeProvider(provider);
      return getSetupState(key) === "missing" ? getSetupMessage(key) : "";
    };
    const taskType = String(data.task_type || "");
    let message = "";

    if (taskType.startsWith("content_writing")) {
      message = issue(data.ai_provider);
      if (message) return message;
      if (data.generate_images_enabled === "1" || data.generate_featured_image === "1") {
        message = issue(data.image_provider);
        if (message) return message;
      }
      if (data.enable_vector_store === "1") {
        message = issue(data.vector_store_provider);
        if (message) return message;
        if (["pinecone", "qdrant", "chroma", "local"].includes(normalizeProvider(data.vector_store_provider))) {
          message = issue(data.vector_embedding_provider);
          if (message) return message;
        }
      }
    } else if (taskType === "enhance_existing_content") {
      message = issue(data.ce_ai_provider || data.ai_provider);
      if (message) return message;
      if (data.ce_enable_vector_store === "1" || data.enable_vector_store === "1") {
        const vectorProvider = data.ce_vector_store_provider || data.vector_store_provider;
        message = issue(vectorProvider);
        if (message) return message;
        if (["pinecone", "qdrant", "chroma", "local"].includes(normalizeProvider(vectorProvider))) {
          message = issue(data.ce_vector_embedding_provider || data.vector_embedding_provider);
          if (message) return message;
        }
      }
    } else if (taskType === "community_reply_comments") {
      message = issue(data.cc_ai_provider);
      if (message) return message;
    } else if (taskType === "content_indexing") {
      message = issue(data.target_store_provider);
      if (message) return message;
      if (["pinecone", "qdrant", "chroma", "local"].includes(normalizeProvider(data.target_store_provider))) {
        message = issue(data.embedding_provider);
        if (message) return message;
      }
    }
    return "";
  }

  function getSavedTaskCredentialIssue(taskType, taskConfig) {
    const data = {
      ...(taskConfig && typeof taskConfig === "object" ? taskConfig : {}),
      task_type: taskType,
    };
    if (taskType === "community_reply_comments") {
      data.cc_ai_provider = data.ai_provider || data.cc_ai_provider;
    }
    return getDataCredentialIssue(data);
  }

  function syncInlineCredentialNotices(form = document) {
    const activeIssue = getTaskCredentialIssue(form);
    form
      .querySelectorAll("[data-aipkit-inline-credential-notice]")
      .forEach((notice) => notice.remove());
    if (!activeIssue?.control) return;

    const row = activeIssue.control.closest(
      ".aipkit_ci_target_row, .aipkit_popover_option_row, .aipkit_cw_kb_row"
    );
    if (!row) return;
    const notice = document.createElement("p");
    notice.className = "aipkit_autogpt_inline_credential_notice";
    notice.dataset.aipkitInlineCredentialNotice = "1";
    notice.setAttribute("role", "status");
    notice.textContent = activeIssue.message;
    row.insertAdjacentElement("afterend", notice);
  }

  window.aipkit_autogpt_provider_setup = {
    annotateProviderOptions,
    getDataCredentialIssue,
    getSavedTaskCredentialIssue,
    getProviderLabel,
    getSetupMessage,
    getSetupState,
    getTaskCredentialIssue,
    isConfigured,
    normalizeProvider,
    resolveNewTaskProvider,
    sortProviderEntries,
    syncControlFallbackNote,
    syncFallbackNote,
    syncInlineCredentialNotices,
  };
})();
