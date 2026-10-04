/** AI Forms editor loading, field population, snippets and show/hide lifecycle. */
import { createDesignerPlaceholder } from "./structure.js";

let editorRevision = 0;

/** Capture ownership before a delayed editor action can resume. */
export function captureEditorSession(formsContainer) {
  const revision = editorRevision;
  const editor = formsContainer.querySelector("#aipkit_form_editor_container");
  const idInput = editor?.querySelector("#aipkit_ai_form_id");
  const formId = idInput?.value;
  return () =>
    revision === editorRevision &&
    editor?.isConnected &&
    formsContainer.querySelector("#aipkit_form_editor_container") === editor &&
    editor.querySelector("#aipkit_ai_form_id") === idInput &&
    idInput?.value === formId;
}

(function () {
  "use strict";

  const getI18n = () =>
    typeof wp !== "undefined" && wp.i18n && wp.i18n.__
      ? wp.i18n.__
      : (text) => text;

  const SEARCH_PROVIDER_KEYS = [
    "openai_web_search", "claude_web_search", "openrouter_web_search",
    "xai_web_search", "google_search_grounding",
  ];
  const SEARCH_FIELDS = [
    ["openai", [
      ["select", "context_size", "medium"], ["select", "loc_type", "none"],
      ["input", "loc_country", ""], ["input", "loc_city", ""],
      ["input", "loc_region", ""], ["input", "loc_timezone", ""],
    ]],
    ["claude", [
      ["input", "max_uses", "5"], ["select", "loc_type", "none"],
      ["input", "loc_country", ""], ["input", "loc_city", ""],
      ["input", "loc_region", ""], ["input", "loc_timezone", ""],
      ["input", "allowed_domains", ""], ["input", "blocked_domains", ""],
      ["select", "cache_ttl", "none"],
    ]],
    ["openrouter", [
      ["select", "engine", "auto"], ["input", "max_results", "5"],
      ["input", "max_uses", "1"], ["input", "max_total_results", "10"],
      ["select", "context_size", "auto"], ["input", "allowed_domains", ""],
      ["input", "excluded_domains", ""],
    ]],
  ];

  const populateSavedSearch = (editor, form) => {
    const toggles = SEARCH_PROVIDER_KEYS.map((key) => ({
      key,
      field: editor.querySelector(`input[name="${key}_enabled"]`),
    }));
    toggles.forEach(({ key, field }) => {
      if (field) field.checked = form[`${key}_enabled`] === "1";
    });
    SEARCH_FIELDS.forEach(([provider, fields]) => {
      // Capture each provider's controls before assigning their saved values.
      fields.map(([tag, suffix, fallback]) => {
        const key = `${provider}_web_search_${suffix}`;
        return { field: editor.querySelector(`${tag}[name="${key}"]`), key, fallback };
      }).forEach(({ field, key, fallback }) => {
        if (field) field.value = form[key] || fallback;
      });
    });
    return toggles;
  };

  /**
   * Updates the display of prompt variable snippets based on the current form structure.
   * @param {HTMLElement} formsContainerElement The main container for the AI Forms module.
   */
  function aipkitForms_updatePromptSnippets(formsContainerElement) {
    const state = window.aipkitAIFormsState;
    if (!formsContainerElement) return;

    const snippetsContainer = formsContainerElement.querySelector(
      "#aipkit_prompt_snippets_container"
    );
    if (!snippetsContainer) return;

    snippetsContainer.innerHTML = ""; // Clear existing snippets
    const allFieldIds = new Set(); // Use a Set to avoid duplicates if any

    // Traverse the nested structure to find all elements with a fieldId
    state.formStructure.forEach((row) => {
      row.columns.forEach((col) => {
        col.elements.forEach((el) => {
          if (el.fieldId) {
            allFieldIds.add(el.fieldId);
          }
        });
      });
    });

    if (allFieldIds.size > 0) {
      const __ = getI18n();

      allFieldIds.forEach((fieldId) => {
        const snippet = document.createElement("button");
        snippet.type = "button";
        snippet.className = "aipkit_prompt_snippet aipkit_ai_form_shortcode_code";
        snippet.dataset.fieldId = fieldId;
        snippet.dataset.shortcode = `{${fieldId}}`;
        snippet.title = __("Click to copy", "gpt3-ai-content-generator");
        snippet.setAttribute(
          "aria-label",
          __("Click to copy variable", "gpt3-ai-content-generator")
        );

        const icon = document.createElement("span");
        icon.className = "dashicons dashicons-clipboard";
        icon.setAttribute("aria-hidden", "true");

        const text = document.createElement("span");
        text.className = "aipkit_ai_form_shortcode_text";
        text.textContent = `{${fieldId}}`;

        snippet.appendChild(icon);
        snippet.appendChild(text);
        snippetsContainer.appendChild(snippet);
      });
    }
  }

  function aipkitForms_runLoadedFormHandlers(formsContainerElement, context = {}) {
    const handlers = window.aipkitAIFormsLoadedFormHandlers;
    if (!Array.isArray(handlers) || !formsContainerElement) {
      return;
    }

    const editorContainer = formsContainerElement.querySelector(
      "#aipkit_form_editor_container"
    );

    handlers.forEach((handler) => {
      if (typeof handler !== "function") {
        return;
      }

      try {
        handler({
          formsContainerElement,
          editorContainer,
          ...context,
        });
      } catch (handlerError) {
        console.warn("AI Forms loaded form handler failed:", handlerError);
      }
    });
  }

  /**
   * Initializes the prompt snippets functionality.
   * Attaches a delegated click listener to the snippets container.
   * @param {HTMLElement} formsContainerElement The main container for the AI Forms module.
   */
  function aipkitForms_initPromptSnippets(formsContainerElement) {
    if (!formsContainerElement) return;

    const editorContainer = formsContainerElement.querySelector(
      "#aipkit_form_editor_container"
    );

    if (!editorContainer) {
      console.warn(
        "AI Forms: Editor container not found for prompt snippets init."
      );
      return;
    }

    const snippetsContainer = editorContainer.querySelector(
      "#aipkit_prompt_snippets_container"
    );
    if (!snippetsContainer) {
      console.warn("AI Forms: Prompt snippets container not found for init.");
      return;
    }

    if (snippetsContainer.dataset.listenerAttached === "true") return;

    snippetsContainer.addEventListener("click", function (event) {
      const snippet = event.target.closest(".aipkit_prompt_snippet");
      if (!snippet) return;
      event.preventDefault();
      event.stopPropagation();

      const fieldId = snippet.dataset.fieldId;
      if (fieldId && typeof window.aipkit_copyShortcode === "function") {
        window.aipkit_copyShortcode(`{${fieldId}}`, snippet);
      }
    });

    snippetsContainer.dataset.listenerAttached = "true";
  }

  function aipkitForms_syncProviderNotice(editorContainer, shouldArm) {
    if (!editorContainer) return;
    const providerSelect = editorContainer.querySelector(
      "#aipkit_ai_form_ai_provider"
    );
    if (!providerSelect) return;

    if (providerSelect.getAttribute("data-aipkit-provider-notice-defer") === "1") {
      if (shouldArm) {
        providerSelect.dataset.aipkitProviderNoticeArmed = "true";
      } else {
        delete providerSelect.dataset.aipkitProviderNoticeArmed;
      }
    }

    if (typeof window.aipkit_initProviderKeyNotices === "function") {
      window.aipkit_initProviderKeyNotices(editorContainer);
    }
  }

  function aipkitForms_showFormEditor(
    formId = null,
    formsContainerElement,
    initialDraft = null
  ) {
    const state = window.aipkitAIFormsState;
    const config = window.aipkit_ai_forms_config || { text: {} };
    const __ = getI18n();
    if (!formsContainerElement) {
      console.error(
        "AI Forms Admin (showFormEditor): formsContainerElement not provided."
      );
      return;
    }

    const revision = ++editorRevision;
    window.aipkitForms_closeFormGeneratorModal?.(formsContainerElement, { force: true });
    state.currentFormId = formId;
    state.droppedElementCounter = 0;
    state.formStructure = [];
    state.selectedDesignerElementId = null;
    window.aipkitForms_setEditorExitState?.(
      formsContainerElement,
      "cancel"
    );
    formsContainerElement.classList.add("aipkit_ai_forms_container--editor-open");
    window.aipkitForms_renderConnectedApps?.(formsContainerElement, null, {
      isUnsaved: !formId,
    });
    if (typeof window.aipkitForms_hideSettingsPanel === "function")
      window.aipkitForms_hideSettingsPanel(formsContainerElement);
    else
      console.error(
        "AI Forms showFormEditor: hideSettingsPanel function missing."
      );

    const editorContainer = formsContainerElement.querySelector(
      "#aipkit_form_editor_container"
    );
    const listContainer = formsContainerElement.querySelector(
      "#aipkit_ai_forms_list_container"
    );
    const headerActions = formsContainerElement.querySelector(
      "#aipkit_ai_forms_editor_actions"
    );
    const createButton = formsContainerElement.querySelector(
      "#aipkit_create_new_ai_form_btn"
    );

    const isCurrentEditor = () =>
      revision === editorRevision && editorContainer.isConnected &&
      formsContainerElement.querySelector("#aipkit_form_editor_container") === editorContainer;

    // Make the editor visible first
    listContainer.style.display = "none";
    editorContainer.style.display = "block";
    if (headerActions) headerActions.style.display = "flex";
    if (createButton) createButton.style.display = "none";

    // Wait for the visible editor layout before querying its controls.
    requestAnimationFrame(() => {
      if (!isCurrentEditor()) return;
      const refreshModelPickers = () => {
        if (typeof window.aipkit_refreshContentWriterSelectPickers === "function") {
          window.aipkit_refreshContentWriterSelectPickers();
        }
      };

      // Query for elements within the editorContainer now that it's visible.
      const formTitleInput = formsContainerElement.querySelector(
        "#aipkit_ai_form_title"
      );
      const promptTemplateTextarea = editorContainer.querySelector(
        "#aipkit_ai_form_prompt_template"
      );
      const hiddenFormIdInput =
        editorContainer.querySelector("#aipkit_ai_form_id");
      const templateKeyInput = editorContainer.querySelector(
        "#aipkit_ai_form_template_key"
      );
      const designerArea = editorContainer.querySelector(
        "#aipkit_ai_form_designer_area"
      );
      const providerSelect = editorContainer.querySelector(
        "#aipkit_ai_form_ai_provider"
      );
      const modelSelect = editorContainer.querySelector(
        "#aipkit_ai_form_ai_model"
      );
      const temperatureInput = editorContainer.querySelector(
        "#aipkit_ai_form_temperature"
      );
      const maxTokensInput = editorContainer.querySelector(
        "#aipkit_ai_form_max_tokens"
      );
      const topPInput = editorContainer.querySelector("#aipkit_ai_form_top_p");
      const freqPenaltyInput = editorContainer.querySelector(
        "#aipkit_ai_form_frequency_penalty"
      );
      const presPenaltyInput = editorContainer.querySelector(
        "#aipkit_ai_form_presence_penalty"
      );
      const previewButton = formsContainerElement.querySelector(
        "#aipkit_preview_ai_form_btn"
      );

      if (
        !editorContainer ||
        !listContainer ||
        !formTitleInput ||
        !promptTemplateTextarea ||
        !hiddenFormIdInput ||
        !designerArea ||
        !providerSelect ||
        !modelSelect ||
        !temperatureInput ||
        !maxTokensInput ||
        !previewButton ||
        !topPInput ||
        !freqPenaltyInput ||
        !presPenaltyInput
      ) {
        console.error(
          "AI Forms Admin (showFormEditor): One or more editor child elements not found within the provided container.",
          { containerHTML: formsContainerElement.innerHTML.substring(0, 500) }
        );
        return;
      }

      const headerTitleDefault = formsContainerElement.querySelector(
        "#aipkit_ai_forms_header_title_default"
      );
      const headerTitleEditor = formsContainerElement.querySelector(
        "#aipkit_ai_forms_header_title_editor"
      );
      const titleDisplay = formsContainerElement.querySelector(
        "#aipkit_ai_forms_title_display"
      );
      const titleText = formsContainerElement.querySelector(
        "#aipkit_ai_forms_title_text"
      );
      const newFormLabel = __("New Form", "gpt3-ai-content-generator");

      const updateTitleDisplay = () => {
        if (!titleText || !titleDisplay || !formTitleInput) return;
        const value = formTitleInput.value.trim();
        const label = value || newFormLabel;
        titleText.textContent = label;
        titleDisplay.setAttribute("title", label);
      };

      const showTitleInput = () => {
        if (!titleDisplay || !formTitleInput) return;
        titleDisplay.style.display = "none";
        formTitleInput.style.display = "inline-block";
        formTitleInput.focus();
        formTitleInput.select();
      };

      const showTitleDisplay = () => {
        if (!titleDisplay || !formTitleInput) return;
        formTitleInput.style.display = "none";
        titleDisplay.style.display = "inline-flex";
        updateTitleDisplay();
      };

      if (headerTitleDefault) {
        headerTitleDefault.textContent = __(
          "Form Editor",
          "gpt3-ai-content-generator"
        );
        headerTitleDefault.style.display = "";
      }
      if (headerTitleEditor) headerTitleEditor.style.display = "flex";

      if (
        headerTitleEditor &&
        headerTitleEditor.dataset.inlineTitleBound !== "true" &&
        titleDisplay &&
        formTitleInput
      ) {
        titleDisplay.addEventListener("click", showTitleInput);
        formTitleInput.addEventListener("blur", showTitleDisplay);
        formTitleInput.addEventListener("keydown", (event) => {
          if (event.key === "Enter") {
            event.preventDefault();
            formTitleInput.blur();
          }
        });
        formTitleInput.addEventListener("input", updateTitleDisplay);
        headerTitleEditor.dataset.inlineTitleBound = "true";
      }

      // Reset fields to default for a "New Form"
      formTitleInput.value = "";
      promptTemplateTextarea.value = "";
      hiddenFormIdInput.value = "";
      if (templateKeyInput) {
        templateKeyInput.value = "";
      }
      designerArea.innerHTML = "";
      designerArea.appendChild(createDesignerPlaceholder(__));
      showTitleDisplay();
      aipkitForms_updatePromptSnippets(formsContainerElement);

      // Reset label fields to default values
      const defaultLabelValues = {
        generate_button: "Generate",
        stop_button: "Stop",
        download_button: "Download",
        save_button: "Save",
        copy_button: "Copy",
        provider_label: "Engine",
        model_label: "Model",
        conversation_back_button: "Back",
        conversation_next_button: "Next",
        conversation_step_title: "Step {number}",
        conversation_step_progress: "Step {current} of {total}",
        conversation_validation_message:
          "Please complete this step before continuing.",
      };
      editorContainer
        .querySelectorAll('input[name^="labels["]')
        .forEach((input) => {
          // Extract the label key from the input name
          const match = input.name.match(/labels\[(.+)\]/);
          const key = match ? match[1] : null;
          if (key && defaultLabelValues[key] !== undefined) {
            input.value = defaultLabelValues[key];
          } else {
            input.value = "";
        }
      });

      const conversationUiPresetSelect = editorContainer.querySelector(
        "#aipkit_ai_form_conversation_ui_preset"
      );
      if (conversationUiPresetSelect) {
        conversationUiPresetSelect.value = "full";
      }

      const parameterFields = [
        [temperatureInput, "temperature", "temperature", 1.0],
        [maxTokensInput, "max_completion_tokens", "max_tokens", 2000],
        [topPInput, "top_p", "top_p", 1.0],
        [freqPenaltyInput, "frequency_penalty", "frequency_penalty", 0.0],
        [presPenaltyInput, "presence_penalty", "presence_penalty", 0.0],
      ];

      // Apply defaults for a new form.
      if (!formId) {
        const allowedProviders = Array.from(providerSelect.options || [])
          .filter((option) => option.value && !option.disabled)
          .map((option) => option.value);
        const selection = window.aipkit_resolveNewAiSelection?.({
          allowedProviders,
        }) || {
          providerValue:
            window.aipkit_dashboard?.newAiSelection?.provider || "OpenAI",
          modelId: window.aipkit_dashboard?.newAiSelection?.model || "",
        };
        const defaultProvider = selection.providerValue || "OpenAI";
        editorContainer.querySelectorAll('[name="enable_vector_store"]').forEach((field) => { field.checked = false; });
        ['local_store_id', 'openai_vector_store_ids[]', 'google_file_search_store_names[]', 'pinecone_index_name', 'qdrant_collection_name', 'chroma_collection_name'].forEach((name) => {
          const field = editorContainer.querySelector(`[name="${name}"]`);
          if (field?.multiple) Array.from(field.options).forEach((option) => { option.selected = false; });
          else if (field) field.value = '';
        });
        window.aipkit_applyNewFeatureDefaults?.(editorContainer);

        providerSelect.value = defaultProvider;
        parameterFields.forEach(([field, defaultKey, , fallback]) => {
          field.value = window.aipkit_dashboard?.ai_params?.[defaultKey] || fallback;
        });

        if (typeof window.aipkitForms_populateAiModels === "function") {
          window.aipkitForms_populateAiModels(
            defaultProvider,
            selection.modelId || ""
          );
          // Sync reasoning controls after the model changes.
          if (typeof window.aipkitForms_toggleReasoningEffort === 'function') {
            window.aipkitForms_toggleReasoningEffort(editorContainer);
          }
        }
        if (typeof window.aipkitForms_updateWebSearchVisibility === "function") {
          window.aipkitForms_updateWebSearchVisibility(
            providerSelect.value,
            editorContainer
          );
        }

        if (
          initialDraft &&
          typeof window.aipkitForms_applyGeneratedDraft === "function"
        ) {
          if (templateKeyInput) {
            templateKeyInput.value = initialDraft.template_key || "";
          }
          window.aipkitForms_applyGeneratedDraft(
            formsContainerElement,
            initialDraft
          );
          showTitleDisplay();
        }

        refreshModelPickers();
        aipkitForms_syncProviderNotice(editorContainer, true);
        if (typeof window.aipkitForms_onEditorReady === "function") {
          window.aipkitForms_onEditorReady(formsContainerElement);
        }
        aipkitForms_runLoadedFormHandlers(formsContainerElement, {
          form: null,
          isNew: true,
        });
      }

      if (formId) {
        window.aipkitForms_syncPreviewButtonState?.(formsContainerElement);
        hiddenFormIdInput.value = formId;
        designerArea.innerHTML = `<p style="text-align:center; padding:20px;"><span class="aipkit_spinner" style="display:inline-block; width:16px; height:16px;"></span> ${__(
          "Loading form details...",
          "gpt3-ai-content-generator"
        )}</p>`;

        window
          .aipkit_apiRequest("aipkit_get_ai_form", {
            form_id: formId,
            _ajax_nonce: config.nonce_manage_forms,
          })
          .then((data) => {
            if (!isCurrentEditor()) return;
            if (data.form) {
              formTitleInput.value = data.form.title || "";
              if (templateKeyInput) {
                templateKeyInput.value = data.form.template_key || "";
              }
              showTitleDisplay();
              promptTemplateTextarea.value = data.form.prompt_template || "";

              providerSelect.value = data.form.ai_provider || "OpenAI"; // Fallback to OpenAI if not set
              parameterFields.forEach(([field, , savedKey]) => {
                field.value = data.form[savedKey];
              });

              // --- Populate Labels ---
              const labels = data.form.labels || {};
              Object.keys(defaultLabelValues).forEach((key) => {
                const input = editorContainer.querySelector(
                  `input[name="labels[${key}]"]`
                );
                if (input) {
                  input.value = labels[key] || "";
                }
              });

              if (typeof window.aipkitForms_populateAiModels === "function") {
                window.aipkitForms_populateAiModels(
                  data.form.ai_provider || "OpenAI",
                  data.form.ai_model
                );
              }

              const conversationUiPresetSelect = editorContainer.querySelector(
                "#aipkit_ai_form_conversation_ui_preset"
              );
              if (conversationUiPresetSelect) {
                conversationUiPresetSelect.value =
                  data.form.conversation_ui_preset || "full";
              }

              // --- Populate Reasoning Effort ---
              const reasoningEffortSelect = editorContainer.querySelector(
                '#aipkit_ai_form_reasoning_effort'
              );
              if (reasoningEffortSelect) {
                reasoningEffortSelect.value = data.form.reasoning_effort || '';
              }
              // Trigger visibility check for reasoning effort
              if (typeof window.aipkitForms_toggleReasoningEffort === 'function') {
                  window.aipkitForms_toggleReasoningEffort(editorContainer);
              }

              // Populate saved vector configuration.
              const enableVectorStoreCheckbox = editorContainer.querySelector(
                'input[name="enable_vector_store"]'
              );
              const contextPanel = enableVectorStoreCheckbox
                ? enableVectorStoreCheckbox.closest(
                    '[data-aipkit-settings-panel="context"]'
                  )
                : null;

              if (contextPanel) {
                const vectorProviderSelect =
                  contextPanel.querySelector(
                    'select[name="vector_store_provider"]'
                  );
                const openaiStoresSelect = contextPanel.querySelector(
                  'select[name="openai_vector_store_ids[]"]'
                );
                const googleStoresSelect = contextPanel.querySelector(
                  'select[name="google_file_search_store_names[]"]'
                );
                const pineconeIndexSelect =
                  contextPanel.querySelector(
                    'select[name="pinecone_index_name"]'
                  );
                const qdrantCollectionSelect =
                  contextPanel.querySelector(
                    'select[name="qdrant_collection_name"]'
                  );
                const chromaCollectionSelect =
                  contextPanel.querySelector(
                    'select[name="chroma_collection_name"]'
                  );
                const localStoreSelect =
                  contextPanel.querySelector('select[name="local_store_id"]');
                const embeddingSelect =
                  contextPanel.querySelector(".aipkit_vector_embedding_select");
                const embeddingProviderSelect =
                  contextPanel.querySelector(
                    'select[name="vector_embedding_provider"]'
                  );
                const embeddingModelSelect =
                  contextPanel.querySelector(
                    'select[name="vector_embedding_model"]'
                  );
                const topKInput = contextPanel.querySelector(
                  'input[name="vector_store_top_k"]'
                );
                const confidenceThresholdInput = contextPanel.querySelector(
                  'input[name="vector_store_confidence_threshold"]'
                );

                // 1. Set all the values from the fetched data first
                enableVectorStoreCheckbox.checked =
                  data.form.enable_vector_store === "1";
                if (vectorProviderSelect)
                  vectorProviderSelect.value =
                    data.form.vector_store_provider || "openai";
                if (pineconeIndexSelect)
                  pineconeIndexSelect.value =
                    data.form.pinecone_index_name || "";
                if (
                  pineconeIndexSelect &&
                  data.form.pinecone_index_name &&
                  !Array.from(pineconeIndexSelect.options).some(
                    (opt) => opt.value === data.form.pinecone_index_name
                  )
                ) {
                  const manualOption = new Option(
                    `${data.form.pinecone_index_name}`,
                    data.form.pinecone_index_name,
                    false,
                    true
                  );
                  pineconeIndexSelect.appendChild(manualOption);
                  pineconeIndexSelect.value = data.form.pinecone_index_name;
                }
                if (qdrantCollectionSelect) {
                  const savedCollection = Array.isArray(
                    data.form.qdrant_collection_names
                  )
                    ? data.form.qdrant_collection_names[0]
                    : data.form.qdrant_collection_name || "";
                  if (
                    savedCollection &&
                    !Array.from(qdrantCollectionSelect.options).some(
                      (opt) => opt.value === savedCollection
                    )
                  ) {
                    const manualOption = new Option(
                      `${savedCollection}`,
                      savedCollection,
                      false,
                      true
                    );
                    qdrantCollectionSelect.appendChild(manualOption);
                  }
                  if (savedCollection) {
                    qdrantCollectionSelect.value = savedCollection;
                  }
                }
                if (chromaCollectionSelect) {
                  const savedChromaCollection = Array.isArray(
                    data.form.chroma_collection_names
                  )
                    ? data.form.chroma_collection_names[0]
                    : data.form.chroma_collection_name || "";
                  if (
                    savedChromaCollection &&
                    !Array.from(chromaCollectionSelect.options).some(
                      (opt) => opt.value === savedChromaCollection
                    )
                  ) {
                    const manualOption = new Option(
                      `${savedChromaCollection}`,
                      savedChromaCollection,
                      false,
                      true
                    );
                    chromaCollectionSelect.appendChild(manualOption);
                  }
                  if (savedChromaCollection) {
                    chromaCollectionSelect.value = savedChromaCollection;
                  }
                }
                if (localStoreSelect) {
                  const savedLocalStore = Array.isArray(data.form.local_store_ids)
                    ? data.form.local_store_ids[0] || ""
                    : "";
                  localStoreSelect.value = savedLocalStore;
                }
                if (embeddingProviderSelect) {
                  const savedProvider = data.form.vector_embedding_provider ?? "openai";
                  if (!Array.from(embeddingProviderSelect.options || []).some((option) => option.value === savedProvider)) {
                    embeddingProviderSelect.appendChild(new Option(savedProvider, savedProvider));
                  }
                  embeddingProviderSelect.value = savedProvider;
                }
                if (topKInput) {
                  const topKVal = data.form.vector_store_top_k || 3;
                  topKInput.value = topKVal;
                }
                if (confidenceThresholdInput) {
                  const confidenceVal = data.form.vector_store_confidence_threshold || 20;
                  confidenceThresholdInput.value = confidenceVal;
                }

                // 2. Populate OpenAI multi-select
                if (
                  openaiStoresSelect &&
                  data.form.openai_vector_store_ids &&
                  Array.isArray(data.form.openai_vector_store_ids)
                ) {
                  const existingValues = new Set(
                    Array.from(openaiStoresSelect.options).map(
                      (opt) => opt.value
                    )
                  );
                  data.form.openai_vector_store_ids.forEach((storeId) => {
                    if (storeId && !existingValues.has(storeId)) {
                      const manualOption = new Option(
                        storeId,
                        storeId,
                        false,
                        true
                      );
                      openaiStoresSelect.appendChild(manualOption);
                      existingValues.add(storeId);
                    }
                  });
                  Array.from(openaiStoresSelect.options).forEach((opt) => {
                    opt.selected = data.form.openai_vector_store_ids.includes(
                      opt.value
                    );
                  });
                }
                if (
                  googleStoresSelect &&
                  Array.isArray(data.form.google_file_search_store_names)
                ) {
                  const existingGoogleValues = new Set(
                    Array.from(googleStoresSelect.options).map((option) => option.value)
                  );
                  data.form.google_file_search_store_names.forEach((storeName) => {
                    if (storeName && !existingGoogleValues.has(storeName)) {
                      googleStoresSelect.appendChild(
                        new Option(`${storeName} (missing)`, storeName, false, true)
                      );
                      existingGoogleValues.add(storeName);
                    }
                  });
                  Array.from(googleStoresSelect.options).forEach((option) => {
                    option.selected = data.form.google_file_search_store_names.includes(option.value);
                  });
                }

                // 3. Populate embedding models based on the now-set provider
                if (
                  typeof window.aipkitForms_populateVectorEmbeddingModels ===
                  "function"
                ) {
                  window.aipkitForms_populateVectorEmbeddingModels(contextPanel);
                }

                // 4. Set the embedding model select value (now that options are populated)
                if (embeddingModelSelect) {
                  const savedModel = data.form.vector_embedding_model || "";
                  if (savedModel && !Array.from(embeddingModelSelect.options || []).some((option) => option.value === savedModel)) {
                    embeddingModelSelect.appendChild(new Option(savedModel, savedModel));
                  }
                  embeddingModelSelect.value = savedModel;
                }

                // 5. Sync combined embedding select
                if (
                  typeof window.aipkitForms_syncEmbeddingSelectFromInputs ===
                  "function"
                ) {
                  window.aipkitForms_syncEmbeddingSelectFromInputs(
                    contextPanel,
                    embeddingSelect
                  );
                }

                // 6. Finally, trigger the visibility toggle using the checkbox's change event
                enableVectorStoreCheckbox.dispatchEvent(
                  new Event("change", { bubbles: true })
                );
              }
              const searchToggles = populateSavedSearch(editorContainer, data.form);

              // Update web search visibility based on provider
              if (typeof window.aipkitForms_updateWebSearchVisibility === "function") {
                window.aipkitForms_updateWebSearchVisibility(
                  providerSelect.value,
                  editorContainer
                );
              }

              searchToggles.forEach(({ field }) => {
                if (field) field.dispatchEvent(new Event("change", { bubbles: true }));
              });

              if (data.form.structure && Array.isArray(data.form.structure)) {
                state.formStructure = data.form.structure;
                designerArea.innerHTML = "";
                if (state.formStructure.length === 0) {
                  designerArea.appendChild(createDesignerPlaceholder(__));
                } else {
                  state.formStructure.forEach((rowData) => {
                    window.aipkitForms_renderLayoutRow(
                      designerArea,
                      rowData,
                      null,
                      formsContainerElement
                    );
                  });

                  let maxCounter = 0;
                  state.formStructure.forEach((row) => {
                    row.columns.forEach((col) => {
                      col.elements.forEach((el) => {
                        const parts = el.internalId.split("-");
                        const num = parseInt(parts[parts.length - 1], 10) || 0;
                        if (num > maxCounter) maxCounter = num;
                      });
                    });
                  });
                  state.droppedElementCounter = maxCounter;
                }
              } else {
                designerArea.innerHTML = "";
                designerArea.appendChild(createDesignerPlaceholder(__));
              }

              refreshModelPickers();
              aipkitForms_syncProviderNotice(editorContainer, true);
              aipkitForms_updatePromptSnippets(formsContainerElement);
              window.aipkitForms_renderConnectedApps?.(
                formsContainerElement,
                data.connected_apps && typeof data.connected_apps === "object"
                  ? data.connected_apps
                  : null,
                { isUnsaved: false }
              );
              if (typeof window.aipkitForms_onEditorReady === "function") {
                window.aipkitForms_onEditorReady(formsContainerElement);
              }
              aipkitForms_runLoadedFormHandlers(formsContainerElement, {
                form: data.form,
                isNew: false,
              });
            } else {
              throw new Error(
                data.message ||
                  __("Could not load form data.", "gpt3-ai-content-generator")
              );
            }
          })
          .catch((error) => {
            if (!isCurrentEditor()) return;
            if (typeof window.aipkitForms_displayMessage === "function")
              window.aipkitForms_displayMessage(
                formsContainerElement,
                "error",
                error.message
              );
            else
              console.error(
                "AI Forms showFormEditor: displayMessage function missing."
              );

            if (typeof window.aipkitForms_hideFormEditor === "function")
              window.aipkitForms_hideFormEditor(formsContainerElement);
            else
              console.error(
                "AI Forms showFormEditor: hideFormEditor function missing."
              );
          });
      } else {
        window.aipkitForms_syncPreviewButtonState?.(formsContainerElement);
        showTitleDisplay();
      }
    }); // --- END requestAnimationFrame wrapper ---
  }

  // Expose globally
  window.aipkitForms_showFormEditor = aipkitForms_showFormEditor;
  window.aipkitForms_updatePromptSnippets = aipkitForms_updatePromptSnippets;
  window.aipkitForms_initPromptSnippets = aipkitForms_initPromptSnippets;

  function aipkitForms_hideFormEditor(formsContainerElement) {
    const state = window.aipkitAIFormsState;
    const __ = getI18n();

    if (!formsContainerElement) {
      console.error(
        "AI Forms hideFormEditor: formsContainerElement not provided."
      );
      return;
    }

    editorRevision += 1;
    window.aipkitForms_closeFormGeneratorModal?.(formsContainerElement, { force: true });
    state.currentFormId = null;
    state.selectedDesignerElementId = null;
    formsContainerElement.classList.remove(
      "aipkit_ai_forms_container--editor-open"
    );
    if (typeof window.aipkitForms_hideSettingsPanel === "function")
      window.aipkitForms_hideSettingsPanel(formsContainerElement);
    else
      console.error(
        "AI Forms hideFormEditor: hideSettingsPanel function missing."
      );

    const editorContainer = formsContainerElement.querySelector(
      "#aipkit_form_editor_container"
    );
    const listContainer = formsContainerElement.querySelector(
      "#aipkit_ai_forms_list_container"
    );
    const headerActions = formsContainerElement.querySelector(
      "#aipkit_ai_forms_editor_actions"
    );
    const createButton = formsContainerElement.querySelector(
      "#aipkit_create_new_ai_form_btn"
    );
    const headerTitleDefault = formsContainerElement.querySelector(
      "#aipkit_ai_forms_header_title_default"
    );
    const headerTitleEditor = formsContainerElement.querySelector(
      "#aipkit_ai_forms_header_title_editor"
    );

    if (editorContainer) editorContainer.style.display = "none";
    if (listContainer) listContainer.style.display = "block";
    if (headerActions) headerActions.style.display = "none";
    if (createButton) createButton.style.display = "";
    if (headerTitleDefault) {
      headerTitleDefault.textContent = __("AI Forms", "gpt3-ai-content-generator");
      headerTitleDefault.style.display = "";
    }
    if (headerTitleEditor) headerTitleEditor.style.display = "none";
    if (typeof window.aipkitForms_closePreviewSheet === "function") {
      window.aipkitForms_closePreviewSheet(formsContainerElement);
    }
    ["Model", "KnowledgeBase", "WebSearch"].forEach((name) => {
      const hook = `aipkitForms_close${name}SettingsModal`;
      if (typeof window[hook] === "function") {
        window[hook](formsContainerElement, { returnFocus: false });
      }
    });

    if (editorContainer) {
      const providerSelect = editorContainer.querySelector(
        "#aipkit_ai_form_ai_provider"
      );
      if (providerSelect) {
        delete providerSelect.dataset.aipkitProviderNoticeArmed;
      }
    }
    const noticeEl = document.getElementById("aipkit_provider_notice_ai_forms");
    if (noticeEl) {
      noticeEl.classList.add("aipkit_provider_notice--hidden");
    }
    if (typeof window.aipkit_initProviderKeyNotices === "function") {
      window.aipkit_initProviderKeyNotices(formsContainerElement);
    }

    if (typeof window.aipkitForms_renderConnectedApps === "function") {
      window.aipkitForms_renderConnectedApps(formsContainerElement, null, {
        isUnsaved: true,
      });
    }

    if (typeof window.aipkitForms_onEditorHidden === "function") {
      window.aipkitForms_onEditorHidden(formsContainerElement);
    }
  }

  // Expose globally
  window.aipkitForms_hideFormEditor = aipkitForms_hideFormEditor;
})();
