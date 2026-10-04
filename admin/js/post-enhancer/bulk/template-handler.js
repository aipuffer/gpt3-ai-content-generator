/**
 * AIPKit Content Enhancer - Bulk Modal Template Handler
 *
 * This self-contained module manages the complete template functionality
 * for the bulk enhancer modal. It is initialized when the modal is created.
 */
(function () {
  "use strict";

  // State object for enhancer templates
  const state = {
    templates: [],
    currentTemplateId: null,
    isSavingOrDeleting: false,
    isAutosaving: false,
    autosavePending: false,
    autosaveSuspended: false,
    autosaveTimer: null,
    autosaveDelay: 350,
    lastSavedConfig: null,
    persistenceFeedbackTimer: null,
    persistenceFeedbackShownAt: 0,
    persistenceFeedbackMinimumDuration: 700,
    retryPersistenceAction: null,
    modalElement: null,
  };

  // Local storage key for remembering selected template
  const STORAGE_KEY = 'aipkit_bulk_enhancer_selected_template';

  const escaper =
    window.aipkit_escapeHtml ||
    function (str) {
      return str;
    };

  const contentLengthOptions = [
    { key: "short", label: "Short", words: "600-800", tokens: 2000 },
    { key: "medium", label: "Medium", words: "1200-1600", tokens: 4000 },
    { key: "long", label: "Long", words: "2000-2500", tokens: 6000 },
  ];

  const getDefaultProviderKey = () =>
    String(window.aipkit_post_enhancer?.default_ai_provider || "")
      .trim()
      .toLowerCase();

  const resolveContentLengthOption = (rawValue) => {
    const numeric = parseInt(rawValue, 10);
    if (Number.isFinite(numeric)) {
      if (numeric >= 1 && numeric <= contentLengthOptions.length) {
        return contentLengthOptions[numeric - 1];
      }
      const tokenMatch = contentLengthOptions.find(
        (option) => option.tokens === numeric
      );
      if (tokenMatch) {
        return tokenMatch;
      }
    }
    return contentLengthOptions[1];
  };

  const resolveContentLengthFromConfig = (config) => {
    const rawTokenValue =
      config?.content_max_tokens ??
      config?.max_tokens ??
      config?.max_completion_tokens ??
      "";
    const numericToken = parseInt(rawTokenValue, 10);
    if (Number.isFinite(numericToken)) {
      const isStepValue =
        numericToken >= 1 && numericToken <= contentLengthOptions.length;
      const isExactToken = contentLengthOptions.some(
        (option) => option.tokens === numericToken
      );
      if (isStepValue || isExactToken) {
        return resolveContentLengthOption(numericToken);
      }
      if (numericToken <= contentLengthOptions[0].tokens) {
        return contentLengthOptions[0];
      }
      if (numericToken <= contentLengthOptions[1].tokens) {
        return contentLengthOptions[1];
      }
      return contentLengthOptions[2];
    }

    const key = String(config?.content_length || "")
      .trim()
      .toLowerCase();
    const keyOption = contentLengthOptions.find(
      (option) => option.key === key
    );
    if (keyOption) {
      return keyOption;
    }

    const defaultTokens = parseInt(
      window.aipkit_post_enhancer?.default_ai_params?.max_completion_tokens ??
        window.aipkit_post_enhancer?.default_ai_params?.max_tokens ??
        "",
      10
    );
    if (Number.isFinite(defaultTokens)) {
      if (defaultTokens <= contentLengthOptions[0].tokens) {
        return contentLengthOptions[0];
      }
      if (defaultTokens <= contentLengthOptions[1].tokens) {
        return contentLengthOptions[1];
      }
      return contentLengthOptions[2];
    }

    return contentLengthOptions[1];
  };

  // --- Local Storage Functions ---

  function saveSelectedTemplateToStorage(templateId) {
    try {
      if (templateId && String(templateId) !== "") {
        localStorage.setItem(STORAGE_KEY, String(templateId));
      } else {
        localStorage.removeItem(STORAGE_KEY);
      }
    } catch (error) {
      console.warn('Failed to save template to localStorage:', error);
    }
  }

  function loadSelectedTemplateFromStorage() {
    try {
      return localStorage.getItem(STORAGE_KEY);
    } catch (error) {
      console.warn('Failed to load template from localStorage:', error);
      return null;
    }
  }

  // --- Configuration Functions ---

  function getEnhancerFormConfig() {
    const modal = state.modalElement;
    if (!modal) return {};

    const config = {};
    const allFields = [
      "title",
      "excerpt",
      "content",
      "meta",
      "keyword",
      "tags",
    ];

    allFields.forEach((fieldType) => {
      const checkbox = modal.querySelector(`#aipkit_bulk_enhance_${fieldType}`);
      const promptTextarea = modal.querySelector(
        `#aipkit_bulk_prompt_${fieldType}`
      );

      if (checkbox && promptTextarea) {
        config[fieldType] = {
          enabled: checkbox.checked ? "1" : "0",
          prompt: promptTextarea.value,
        };
      }
    });

    config.ai_provider =
      modal.querySelector("#aipkit_bulk_ai_provider")?.value || "openai";
    config.ai_model = modal.querySelector("#aipkit_bulk_ai_model")?.value || "";
    config.ai_temperature = modal.querySelector(
      "#aipkit_bulk_ai_temperature"
    )?.value;
    config.ai_top_p =
      modal.querySelector("#aipkit_bulk_ai_top_p")?.value || "1";
    const contentLengthInput = modal.querySelector(
      "#aipkit_bulk_content_max_tokens"
    );
    const contentLengthOption = resolveContentLengthOption(
      contentLengthInput ? contentLengthInput.value : ""
    );
    config.content_max_tokens = String(contentLengthOption.tokens);
    config.reasoning_effort =
      modal.querySelector("#aipkit_bulk_reasoning_effort")?.value || "";

    config.generate_seo_slug = modal.querySelector(
      "#aipkit_bulk_generate_seo_slug"
    )?.checked
      ? "1"
      : "0";

    // Add vector settings to config.
    const enableVectorCheckbox = modal.querySelector(
      'input[name="enable_vector_store"]'
    );
    const vectorProviderValue =
      modal.querySelector("#aipkit_bulk_vector_store_provider")?.value || "off";
    if (
      enableVectorCheckbox &&
      enableVectorCheckbox.checked &&
      vectorProviderValue !== "off"
    ) {
      config.enable_vector_store = "1";
      config.vector_store_provider = vectorProviderValue || "openai";
      config.vector_store_top_k =
        modal.querySelector("#aipkit_bulk_vector_store_top_k")?.value || "3";

      if (config.vector_store_provider === "openai") {
        const openaiSelect = modal.querySelector(
          "#aipkit_bulk_openai_vector_store_ids"
        );
        if (openaiSelect) {
          config.openai_vector_store_ids = Array.from(
            openaiSelect.selectedOptions
          ).map((opt) => opt.value);
        }
      } else if (config.vector_store_provider === "google") {
        const googleSelect = modal.querySelector(
          "#aipkit_bulk_google_file_search_store_names"
        );
        if (googleSelect) {
          config.google_file_search_store_names = Array.from(
            googleSelect.selectedOptions
          ).map((opt) => opt.value);
        }
      } else if (config.vector_store_provider === "pinecone") {
        config.pinecone_index_name =
          modal.querySelector("#aipkit_bulk_pinecone_index_name")?.value || "";
      } else if (config.vector_store_provider === "qdrant") {
        config.qdrant_collection_name =
          modal.querySelector("#aipkit_bulk_qdrant_collection_name")?.value ||
          "";
      } else if (config.vector_store_provider === "chroma") {
        config.chroma_collection_name =
          modal.querySelector("#aipkit_bulk_chroma_collection_name")?.value ||
          "";
      } else if (config.vector_store_provider === "local") {
        config.local_store_id =
          modal.querySelector("#aipkit_bulk_local_store_id")?.value || "";
      }

      if (
        config.vector_store_provider === "pinecone" ||
        config.vector_store_provider === "qdrant" ||
        config.vector_store_provider === "chroma" ||
        config.vector_store_provider === "local"
      ) {
        config.vector_embedding_provider =
          modal.querySelector("#aipkit_bulk_vector_embedding_provider")
            ?.value || "openai";
        config.vector_embedding_model =
          modal.querySelector("#aipkit_bulk_vector_embedding_model")?.value ||
          "";
      }
    } else {
      config.enable_vector_store = "0";
    }
    // --- END: NEW ---

    return config;
  }

  function applyEnhancerTemplate(templateId) {
    state.autosaveSuspended = true;
    state.currentTemplateId = templateId;
    
    // Save selected template to localStorage
    saveSelectedTemplateToStorage(templateId);

    if (window.aipkit_bulk_template_state) {
      window.aipkit_bulk_template_state.currentTemplateId = templateId;
    }

    if (typeof window.aipkit_updateBulkTemplatePickerSelection === "function") {
      window.aipkit_updateBulkTemplatePickerSelection(
        templateId,
        state.modalElement
      );
    }
    
    const selectedTemplate = state.templates.find(
      (t) => String(t.id) === String(templateId)
    );

    const config = selectedTemplate
      ? JSON.parse(JSON.stringify(selectedTemplate.config))
      : (window.aipkit_getNewFeatureDefaults?.() || {});

    if (!config.content_max_tokens && config.content_length) {
      const legacyOption = contentLengthOptions.find(
        (option) => option.key === String(config.content_length).toLowerCase()
      );
      config.content_max_tokens = String(
        legacyOption?.tokens || contentLengthOptions[1].tokens
      );
    }
    if (Object.prototype.hasOwnProperty.call(config, "content_length")) {
      delete config.content_length;
    }

    const modal = state.modalElement;
    if (!modal) return;

    // --- Populate AI settings ---
    const providerSelect = modal.querySelector("#aipkit_bulk_ai_provider");
    const modelSelect = modal.querySelector("#aipkit_bulk_ai_model");
    const temperatureInput = modal.querySelector(
      "#aipkit_bulk_ai_temperature"
    );
    const topPInput = modal.querySelector("#aipkit_bulk_ai_top_p");
    const contentLengthSelect = modal.querySelector(
      "#aipkit_bulk_content_max_tokens"
    );
    const reasoningSelect = modal.querySelector(
      "#aipkit_bulk_reasoning_effort"
    );

    if (providerSelect) {
      const defaultProviderKey = getDefaultProviderKey();
      providerSelect.value =
        config.ai_provider || defaultProviderKey || "openai";
      providerSelect.dispatchEvent(new Event("change", { bubbles: true }));
    }

    if (modelSelect && config.ai_model) {
      setTimeout(() => {
        if (
          modelSelect.querySelector(
            `option[value="${CSS.escape(config.ai_model)}"]`
          )
        ) {
          modelSelect.value = config.ai_model;
          modelSelect.dispatchEvent(new Event("change"));
        }
      }, 150);
    }

    if (temperatureInput) {
      temperatureInput.value = config.ai_temperature || "1.0";
    }
    if (topPInput) {
      topPInput.value = config.ai_top_p || "1";
    }
    if (contentLengthSelect) {
      const contentLengthOption = resolveContentLengthFromConfig(config);
      const optionIndex = Math.max(
        0,
        contentLengthOptions.findIndex(
          (option) => option.key === contentLengthOption.key
        )
      );
      contentLengthSelect.value = String(optionIndex + 1);
    }
    if (reasoningSelect) {
      reasoningSelect.value =
        config.reasoning_effort === "minimal"
          ? "low"
          : config.reasoning_effort || "";
      if (typeof window.aipkit_enhancer_toggleReasoningEffort === "function") {
        window.aipkit_enhancer_toggleReasoningEffort(modal);
      }
    }

    // --- Populate checkboxes and textareas ---
    ["title", "excerpt", "content", "meta", "keyword", "tags"].forEach(
      (field) => {
        const checkbox = modal.querySelector(`#aipkit_bulk_enhance_${field}`);
        const textarea = modal.querySelector(`#aipkit_bulk_prompt_${field}`);

        if (checkbox && textarea) {
          const isEnabled = config[field]?.enabled === "1";
          if (checkbox.checked !== isEnabled) {
            checkbox.checked = isEnabled;
            checkbox.dispatchEvent(new Event("change"));
          }
          textarea.value = config[field]?.prompt || textarea.defaultValue;
        }
      }
    );

    const generateSeoSlugCheckbox = modal.querySelector(
      "#aipkit_bulk_generate_seo_slug"
    );
    if (generateSeoSlugCheckbox) {
      const isEnabled = config.generate_seo_slug === "1";
      if (generateSeoSlugCheckbox.checked !== isEnabled) {
        generateSeoSlugCheckbox.checked = isEnabled;
        generateSeoSlugCheckbox.dispatchEvent(new Event("change"));
      }
    }

    if (!selectedTemplate) {
      window.aipkit_applyNewFeatureDefaults?.(modal);
      window.aipkit_enhancer_setEmbeddingSelection?.(modal, config.vector_embedding_provider, config.vector_embedding_model);
    }

    // Apply vector settings.
    const enableVectorCheckbox = modal.querySelector(
      'input[name="enable_vector_store"]'
    );
    if (enableVectorCheckbox) {
      enableVectorCheckbox.checked = config.enable_vector_store === "1";
      // Dispatch change event to toggle visibility of the container
      enableVectorCheckbox.dispatchEvent(new Event("change"));
    }
    const vectorProviderSelect = modal.querySelector(
      "#aipkit_bulk_vector_store_provider"
    );
    if (vectorProviderSelect) {
      vectorProviderSelect.value =
        config.enable_vector_store === "1"
          ? config.vector_store_provider || "openai"
          : "off";
      vectorProviderSelect.dispatchEvent(new Event("change", { bubbles: true }));
    }
    if (config.enable_vector_store === "1") {
      const topKInput = modal.querySelector("#aipkit_bulk_vector_store_top_k");
      if (topKInput) {
        topKInput.value = config.vector_store_top_k || "3";
      }

      // Set values after a short delay to allow the UI to update from the change event
      setTimeout(() => {
        if (config.vector_store_provider === "openai") {
          const openaiSelect = modal.querySelector(
            "#aipkit_bulk_openai_vector_store_ids"
          );
          if (openaiSelect && Array.isArray(config.openai_vector_store_ids)) {
            Array.from(openaiSelect.options).forEach((opt) => {
              opt.selected = config.openai_vector_store_ids.includes(opt.value);
            });
            openaiSelect.dispatchEvent(new Event("change", { bubbles: true }));
          }
        } else if (config.vector_store_provider === "google") {
          const googleSelect = modal.querySelector(
            "#aipkit_bulk_google_file_search_store_names"
          );
          if (
            googleSelect &&
            Array.isArray(config.google_file_search_store_names)
          ) {
            Array.from(googleSelect.options).forEach((opt) => {
              opt.selected = config.google_file_search_store_names.includes(
                opt.value
              );
            });
            googleSelect.dispatchEvent(new Event("change", { bubbles: true }));
          }
        } else if (config.vector_store_provider === "pinecone") {
          const pineconeSelect = modal.querySelector(
            "#aipkit_bulk_pinecone_index_name"
          );
          if (pineconeSelect) {
            pineconeSelect.value = config.pinecone_index_name || "";
            pineconeSelect.dispatchEvent(new Event("change", { bubbles: true }));
          }
        } else if (config.vector_store_provider === "qdrant") {
          const qdrantSelect = modal.querySelector(
            "#aipkit_bulk_qdrant_collection_name"
          );
          if (qdrantSelect) {
            qdrantSelect.value = config.qdrant_collection_name || "";
            qdrantSelect.dispatchEvent(new Event("change", { bubbles: true }));
          }
        } else if (config.vector_store_provider === "chroma") {
          const chromaSelect = modal.querySelector(
            "#aipkit_bulk_chroma_collection_name"
          );
          if (chromaSelect) {
            chromaSelect.value = config.chroma_collection_name || "";
            chromaSelect.dispatchEvent(new Event("change", { bubbles: true }));
          }
        } else if (config.vector_store_provider === "local") {
          const localStoreSelect = modal.querySelector("#aipkit_bulk_local_store_id");
          if (localStoreSelect) {
            localStoreSelect.value = config.local_store_id || "";
            localStoreSelect.dispatchEvent(new Event("change", { bubbles: true }));
          }
        }

        if (
          config.vector_store_provider === "pinecone" ||
          config.vector_store_provider === "qdrant" ||
          config.vector_store_provider === "chroma" ||
          config.vector_store_provider === "local"
        ) {
          if (
            typeof window.aipkit_enhancer_setEmbeddingSelection === "function"
          ) {
            window.aipkit_enhancer_setEmbeddingSelection(
              modal,
              config.vector_embedding_provider || "openai",
              config.vector_embedding_model || ""
            );
          }
        }
      }, 100); // Small delay
    }
    // --- END: NEW ---

    window.clearTimeout(state.autosaveTimer);
    state.autosaveTimer = null;
    window.setTimeout(() => {
      state.lastSavedConfig = getEnhancerFormConfig();
      state.autosaveSuspended = false;
    }, 200);
  }

  function checkFormForModifications() {
    scheduleAutosave();
  }

  function getPersistenceFeedbackElements() {
    const feedback = state.modalElement?.querySelector(
      "#aipkit_enhancer_persistence_feedback"
    );
    if (!feedback) {
      return null;
    }

    return {
      feedback,
      message: feedback.querySelector(
        ".aipkit_enhancer_persistence_message"
      ),
      retryButton: feedback.querySelector(
        ".aipkit_enhancer_persistence_retry"
      ),
    };
  }

  function clearPersistenceFeedbackTimer() {
    if (!state.persistenceFeedbackTimer) {
      return;
    }
    window.clearTimeout(state.persistenceFeedbackTimer);
    state.persistenceFeedbackTimer = null;
  }

  function hidePersistenceFeedback(clearRetryAction = true) {
    clearPersistenceFeedbackTimer();
    const elements = getPersistenceFeedbackElements();
    if (elements) {
      elements.feedback.hidden = true;
      elements.feedback.classList.remove("is-error");
      elements.feedback.setAttribute("aria-hidden", "true");
      elements.feedback.setAttribute("aria-live", "polite");
      elements.feedback.removeAttribute("role");
      if (elements.message) {
        elements.message.textContent = "Saving…";
      }
      if (elements.retryButton) {
        elements.retryButton.hidden = true;
      }
    }
    state.modalElement?.removeAttribute("aria-busy");
    state.persistenceFeedbackShownAt = 0;
    if (clearRetryAction) {
      state.retryPersistenceAction = null;
    }
  }

  function beginPersistenceFeedback(retryAction) {
    hidePersistenceFeedback(false);
    state.retryPersistenceAction =
      typeof retryAction === "function" ? retryAction : null;
    state.modalElement?.setAttribute("aria-busy", "true");
    const elements = getPersistenceFeedbackElements();
    if (!elements || !state.modalElement?.isConnected) {
      return;
    }
    state.persistenceFeedbackShownAt = Date.now();
    elements.feedback.hidden = false;
    elements.feedback.setAttribute("aria-hidden", "false");
  }

  function completePersistenceFeedback() {
    const elapsed = state.persistenceFeedbackShownAt
      ? Date.now() - state.persistenceFeedbackShownAt
      : state.persistenceFeedbackMinimumDuration;
    const remaining = Math.max(
      0,
      state.persistenceFeedbackMinimumDuration - elapsed
    );
    if (remaining === 0) {
      hidePersistenceFeedback(true);
      return;
    }
    clearPersistenceFeedbackTimer();
    state.persistenceFeedbackTimer = window.setTimeout(() => {
      hidePersistenceFeedback(true);
    }, remaining);
  }

  function failPersistenceFeedback(
    message = "Couldn’t save changes.",
    retryAction = null
  ) {
    clearPersistenceFeedbackTimer();
    state.retryPersistenceAction =
      typeof retryAction === "function" ? retryAction : null;
    const elements = getPersistenceFeedbackElements();
    if (!elements) {
      return;
    }

    elements.feedback.classList.add("is-error");
    elements.feedback.hidden = false;
    elements.feedback.setAttribute("aria-hidden", "false");
    elements.feedback.setAttribute("aria-live", "assertive");
    elements.feedback.setAttribute("role", "alert");
    state.modalElement?.removeAttribute("aria-busy");
    state.persistenceFeedbackShownAt = 0;
    if (elements.message) {
      elements.message.textContent = message;
    }
    if (elements.retryButton) {
      elements.retryButton.hidden = !state.retryPersistenceAction;
    }
  }

  function setModalStatus(message, type = "info") {
    const status = state.modalElement?.querySelector(
      "#aipkit_enhancer_footer_status"
    );
    if (!status) {
      return;
    }

    const defaultText = status.dataset.defaultText || "";
    status.classList.remove("is-error");
    if (!message) {
      status.textContent = defaultText;
      return;
    }

    if (type === "error") {
      status.textContent = message;
      status.classList.add("is-error");
    }
  }

  function getCurrentTemplateMeta() {
    const selectEl = state.modalElement?.querySelector(
      "#aipkit_enhancer_template_select"
    );
    const templateId = state.currentTemplateId || selectEl?.value || "";
    if (!templateId) {
      return null;
    }

    let templateName = "";
    const match = state.templates.find(
      (template) => String(template.id) === String(templateId)
    );
    if (match?.template_name) {
      templateName = match.template_name;
    }

    if (!templateName) {
      if (selectEl?.selectedOptions?.length) {
        templateName = selectEl.selectedOptions[0].textContent.trim();
      }
    }

    const parsedId = parseInt(templateId, 10);
    if (!templateName || Number.isNaN(parsedId)) {
      return null;
    }

    return { id: parsedId, name: templateName };
  }

  async function runAutosave() {
    if (state.autosaveSuspended || state.isSavingOrDeleting) return;
    if (state.isAutosaving) {
      state.autosavePending = true;
      return;
    }

    const currentConfig = getEnhancerFormConfig();
    if (!currentConfig) return;

    if (
      state.lastSavedConfig &&
      window.aipkit_deepEqual(currentConfig, state.lastSavedConfig)
    ) {
      return;
    }

    const templateMeta = getCurrentTemplateMeta();
    if (!templateMeta) {
      return;
    }

    state.isAutosaving = true;
    const retryAutosave = () => runAutosave();
    beginPersistenceFeedback(retryAutosave);

    try {
      const nonce = window.aipkit_post_enhancer?.nonce_manage_templates;
      if (!nonce) {
        throw new Error("Security nonce for templates not found.");
      }
      await window.aipkit_apiRequest("aipkit_save_cw_template", {
        template_id: templateMeta.id,
        template_name: templateMeta.name,
        config: JSON.stringify(currentConfig),
        template_type: "enhancer",
        _ajax_nonce: nonce,
      });

      state.lastSavedConfig = JSON.parse(JSON.stringify(currentConfig));
      completePersistenceFeedback();
    } catch (error) {
      failPersistenceFeedback("Couldn’t save changes.", retryAutosave);
      console.error("Bulk Enhancer Auto-Save Error:", error);
    } finally {
      state.isAutosaving = false;
      if (state.autosavePending) {
        state.autosavePending = false;
        window.setTimeout(runAutosave, 0);
      }
    }
  }

  function scheduleAutosave() {
    if (state.autosaveSuspended || state.isSavingOrDeleting) return;
    if (!getCurrentTemplateMeta()) return;

    if (state.autosaveTimer) {
      clearTimeout(state.autosaveTimer);
    }
    state.autosaveTimer = setTimeout(() => {
      state.autosaveTimer = null;
      runAutosave();
    }, state.autosaveDelay);
  }

  // --- AJAX Handlers ---

  async function retryTemplateFetch(failureMessage) {
    const retryFetch = () => retryTemplateFetch(failureMessage);
    beginPersistenceFeedback(retryFetch);
    const refreshed = await fetchAndPopulateTemplates(false);
    if (refreshed) {
      completePersistenceFeedback();
      return;
    }
    failPersistenceFeedback(failureMessage, retryFetch);
  }

  async function fetchAndPopulateTemplates(showFailure = true) {
    const selectEl = state.modalElement.querySelector(
      "#aipkit_enhancer_template_select"
    );
    selectEl.innerHTML = `<option value="">Loading...</option>`;
    selectEl.disabled = true;

    try {
      const nonce = window.aipkit_post_enhancer?.nonce_manage_templates;
      if (!nonce) {
        throw new Error("Security nonce for templates not found.");
      }
      const response = await window.aipkit_apiRequest(
        "aipkit_list_cw_templates",
        {
          template_type: "enhancer",
          _ajax_nonce: nonce,
        }
      );
      state.templates = response.templates || [];
      selectEl.innerHTML = `<option value="">Select a template</option>`;
      state.templates.forEach((t) => {
        selectEl.appendChild(new Option(escaper(t.template_name), t.id));
      });
      selectEl.disabled = false;
      
      // Auto-select previously saved template if it exists
      const savedTemplateId = loadSelectedTemplateFromStorage();
      if (savedTemplateId) {
        const templateExists = state.templates.some(t => String(t.id) === String(savedTemplateId));
        if (templateExists) {
          selectEl.value = savedTemplateId;
          applyEnhancerTemplate(savedTemplateId);
        } else {
          // Template no longer exists, remove from localStorage
          saveSelectedTemplateToStorage(null);
        }
      }

      window.aipkit_bulk_template_state = {
        templates: state.templates,
        currentTemplateId: state.currentTemplateId,
      };
      if (typeof window.aipkit_renderBulkTemplatePicker === "function") {
        window.aipkit_renderBulkTemplatePicker(state.modalElement);
      }
      return true;
    } catch (error) {
      selectEl.innerHTML = `<option value="">Templates unavailable</option>`;
      selectEl.disabled = false;
      if (showFailure) {
        failPersistenceFeedback(
          "Couldn’t load templates.",
          () => retryTemplateFetch("Couldn’t load templates.")
        );
      }
      console.error("Bulk Enhancer Template Load Error:", error);
      return false;
    }
  }

  async function saveTemplate(id, name) {
    if (state.isSavingOrDeleting) return false;
    state.isSavingOrDeleting = true;
    const retrySave = () => saveTemplate(id, name);
    beginPersistenceFeedback(retrySave);
    try {
      const config = getEnhancerFormConfig();
      const nonce = window.aipkit_post_enhancer?.nonce_manage_templates;
      if (!nonce) {
        throw new Error("Security nonce for templates not found.");
      }
      const response = await window.aipkit_apiRequest(
        "aipkit_save_cw_template",
        {
          template_id: id,
          template_name: name,
          config: JSON.stringify(config),
          template_type: "enhancer",
          _ajax_nonce: nonce,
        }
      );
      saveSelectedTemplateToStorage(response.template_id);
      state.currentTemplateId = response.template_id;
      const templatesRefreshed = await fetchAndPopulateTemplates(false);
      state.lastSavedConfig = JSON.parse(JSON.stringify(config));
      if (
        typeof window.aipkit_updateBulkTemplatePickerSelection === "function"
      ) {
        window.aipkit_updateBulkTemplatePickerSelection(
          response.template_id,
          state.modalElement
        );
      }
      if (templatesRefreshed) {
        completePersistenceFeedback();
      } else {
        failPersistenceFeedback(
          "Couldn’t refresh templates.",
          () => retryTemplateFetch("Couldn’t refresh templates.")
        );
      }
      return true;
    } catch (error) {
      failPersistenceFeedback("Couldn’t save changes.", retrySave);
      console.error("Bulk Enhancer Template Save Error:", error);
      return false;
    } finally {
      state.isSavingOrDeleting = false;
    }
  }

  async function deleteTemplate(templateId) {
    if (!templateId || state.isSavingOrDeleting) return false;
    state.isSavingOrDeleting = true;
    const retryDelete = () => deleteTemplate(templateId);
    beginPersistenceFeedback(retryDelete);
    try {
      const nonce = window.aipkit_post_enhancer?.nonce_manage_templates;
      if (!nonce) {
        throw new Error("Security nonce for templates not found.");
      }
      await window.aipkit_apiRequest("aipkit_delete_cw_template", {
        template_id: templateId,
        _ajax_nonce: nonce,
      });
      const deletedSelectedTemplate =
        String(state.currentTemplateId || "") === String(templateId);
      const savedTemplateId = loadSelectedTemplateFromStorage();
      if (savedTemplateId && String(savedTemplateId) === String(templateId)) {
        saveSelectedTemplateToStorage(null);
      }
      if (deletedSelectedTemplate) {
        state.currentTemplateId = null;
        state.lastSavedConfig = null;
      }
      const templatesRefreshed = await fetchAndPopulateTemplates(false);
      if (templatesRefreshed) {
        completePersistenceFeedback();
      } else {
        failPersistenceFeedback(
          "Couldn’t refresh templates.",
          () => retryTemplateFetch("Couldn’t refresh templates.")
        );
      }
      return true;
    } catch (error) {
      failPersistenceFeedback("Couldn’t delete template.", retryDelete);
      console.error("Bulk Enhancer Template Delete Error:", error);
      return false;
    } finally {
      state.isSavingOrDeleting = false;
    }
  }

  // --- Main Initializer for Template Controls ---

  function initTemplateControls(modal) {
    state.modalElement = modal;

    const selectEl = modal.querySelector("#aipkit_enhancer_template_select");
    selectEl.addEventListener("change", (e) => {
      setModalStatus("");
      applyEnhancerTemplate(e.target.value);
    });

    const retryButton = modal.querySelector(
      ".aipkit_enhancer_persistence_retry"
    );
    retryButton?.addEventListener("click", () => {
      const retryAction = state.retryPersistenceAction;
      if (typeof retryAction === "function") {
        retryAction();
      }
    });

    const newButton = modal.querySelector("#aipkit_enhancer_new_template_btn");
    const newForm = modal.querySelector(
      "#aipkit_enhancer_template_new_form"
    );
    const newName = modal.querySelector(
      "#aipkit_enhancer_template_new_name"
    );
    const newSave = modal.querySelector(
      "#aipkit_enhancer_template_new_save"
    );
    const newCancel = modal.querySelector(
      "#aipkit_enhancer_template_new_cancel"
    );

    const showNewTemplateForm = () => {
      if (!newForm || !newName) return;
      if (typeof window.aipkit_openBulkTemplatePicker === "function") {
        window.aipkit_openBulkTemplatePicker(modal);
      }
      newButton?.setAttribute("hidden", "");
      newForm.hidden = false;
      newName.value = "";
      window.requestAnimationFrame(() => newName.focus());
    };
    const hideNewTemplateForm = () => {
      if (!newForm || !newName) return;
      newForm.hidden = true;
      newName.value = "";
      newButton?.removeAttribute("hidden");
    };
    const saveNewTemplate = async () => {
      const name = String(newName?.value || "").trim();
      if (!name) {
        newName?.focus();
        newName?.setCustomValidity("Enter a template name.");
        newName?.reportValidity();
        return;
      }
      newName?.setCustomValidity("");
      const saved = await saveTemplate(0, name);
      if (saved) {
        hideNewTemplateForm();
      }
    };

    newButton?.addEventListener("click", showNewTemplateForm);
    newCancel?.addEventListener("click", hideNewTemplateForm);
    newSave?.addEventListener("click", saveNewTemplate);
    newName?.addEventListener("keydown", (event) => {
      if (event.key === "Enter") {
        event.preventDefault();
        saveNewTemplate();
      } else if (event.key === "Escape") {
        hideNewTemplateForm();
      }
    });
    newName?.addEventListener("input", () => {
      newName.setCustomValidity("");
    });

    const pickerList = modal.querySelector(
      "#aipkit_enhancer_template_picker_list"
    );
    pickerList?.addEventListener("click", async (event) => {
      const actionButton = event.target.closest("[data-template-action]");
      if (!actionButton) return;
      event.preventDefault();
      event.stopPropagation();

      const item = actionButton.closest(".aipkit_cw_template_list_item");
      if (!item || item.dataset.templateOwned !== "true") return;
      const templateId = parseInt(item.dataset.templateId || "", 10);
      const renameForm = item.querySelector("[data-template-rename-form]");
      const renameInput = item.querySelector("[data-template-rename-input]");

      switch (actionButton.dataset.templateAction) {
        case "rename":
          if (renameForm) renameForm.hidden = false;
          window.requestAnimationFrame(() => {
            renameInput?.focus();
            renameInput?.select();
          });
          break;
        case "rename-cancel":
          if (renameForm) renameForm.hidden = true;
          if (renameInput) renameInput.value = item.dataset.templateName || "";
          break;
        case "rename-save": {
          const name = String(renameInput?.value || "").trim();
          if (!name || Number.isNaN(templateId)) {
            renameInput?.focus();
            renameInput?.setCustomValidity("Enter a template name.");
            renameInput?.reportValidity();
            return;
          }
          renameInput?.setCustomValidity("");
          await saveTemplate(templateId, name);
          break;
        }
        case "delete":
          if (renameForm) renameForm.hidden = true;
          if (!Number.isNaN(templateId)) {
            await deleteTemplate(templateId);
          }
          break;
      }
    });

    // Initial population
    fetchAndPopulateTemplates();

    if (typeof window.aipkit_initBulkTemplatePicker === "function") {
      window.aipkit_initBulkTemplatePicker(modal);
    }

    // Listen for form changes
    const handleFormChange = (event) => {
      const target = event.target;
      if (target?.id === "aipkit_enhancer_template_select") {
        return;
      }
      checkFormForModifications();
    };
    modal.addEventListener("input", handleFormChange);
    modal.addEventListener("change", handleFormChange);
    modal.addEventListener("input", (event) => {
      if (event.target.matches("[data-template-rename-input]")) {
        event.target.setCustomValidity("");
      }
    });
  }

  // Expose the initializer
  window.aipkit_enhancer_initTemplateControls = initTemplateControls;
  window.aipkit_enhancer_setModalStatus = setModalStatus;
  window.aipkit_enhancer_commitPromptDraft = checkFormForModifications;
})();
