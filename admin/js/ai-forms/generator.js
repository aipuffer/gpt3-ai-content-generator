/** AI Forms prompt-to-form generation, draft application and modal lifecycle. */
import { createDesignerPlaceholder } from "./structure.js";
import { bindDialogKeydown, cancelDialogFocus, deferDialogFocus } from "./editor-dialogs.js";

(function () {
  "use strict";

  const getI18n = () =>
    typeof wp !== "undefined" && wp.i18n && wp.i18n.__
      ? wp.i18n.__
      : (text) => text;

  const generationRequests = new WeakMap();

  const getConfig = () => window.aipkit_ai_forms_config || { text: {} };
  const INSPIRATION_PROMPTS = [
    "Create a blog brief generator with fields for topic, audience, tone, keywords, target length, and internal notes for the writer.",
    "Create a customer support reply builder with fields for the customer's message, product name, issue type, response tone, and internal guidance.",
    "Create a social media campaign planner with fields for campaign goal, target audience, brand voice, platforms, offer, and call to action.",
    "Create a job description writer with fields for job title, department, seniority level, responsibilities, required skills, and benefits.",
    "Create a product comparison form with fields for product A, product B, comparison criteria, target buyer, tone, and recommendation style.",
    "Create a real estate listing generator with fields for property type, location, key features, price range, target buyer, and tone of description.",
    "Create a lesson plan builder with fields for subject, grade level, lesson objective, time available, teaching style, and materials.",
    "Create a travel itinerary planner with fields for destination, trip length, budget, travel style, must-see interests, and group type.",
    "Create an email sequence planner with fields for campaign goal, audience segment, offer, tone, number of emails, and CTA style.",
    "Create a recipe creator form with fields for cuisine, main ingredient, dietary restrictions, skill level, cooking time, and serving size.",
  ];

  const hasStructureElements = (structure) =>
    Array.isArray(structure) && structure.some(row =>
      Array.isArray(row?.columns) && row.columns.some(column =>
        Array.isArray(column?.elements) && column.elements.length > 0
      )
    );

  const getModal = (container) => container
    ? container.querySelector("#aipkit_ai_form_generator_modal")
    : document.getElementById("aipkit_ai_form_generator_modal");

  const getRandomInspirationPrompt = (modal) => {
    const previousIndex = Number.parseInt(
      modal?.dataset.lastInspirationIndex || "-1",
      10
    );
    let nextIndex = Math.floor(Math.random() * INSPIRATION_PROMPTS.length);

    if (nextIndex === previousIndex) {
      nextIndex = (nextIndex + 1) % INSPIRATION_PROMPTS.length;
    }

    modal.dataset.lastInspirationIndex = String(nextIndex);

    return INSPIRATION_PROMPTS[nextIndex];
  };

  const replaceInspirationPrompt = (promptInput, modal) => {
    if (!promptInput) return;
    const prompt = getRandomInspirationPrompt(modal);
    promptInput.value = prompt;
    promptInput.dispatchEvent(new Event("input", { bubbles: true }));
    promptInput.focus();
    promptInput.setSelectionRange(prompt.length, prompt.length);
  };

  const setModalStatus = (modal, message, tone = "") => {
    const statusEl = modal?.querySelector("#aipkit_ai_form_generator_status");
    if (!statusEl) {
      return;
    }

    statusEl.textContent = message || "";
    statusEl.classList.remove("aipkit_form-help-success", "aipkit_form-help-error");
    if (tone === "error") {
      statusEl.classList.add("aipkit_form-help-error");
    }
  };

  const countGeneratedElements = (structure) => {
    if (!Array.isArray(structure)) {
      return 0;
    }

    let count = 0;
    structure.forEach((row) => {
      if (!row || !Array.isArray(row.columns)) {
        return;
      }
      row.columns.forEach((column) => {
        if (!column || !Array.isArray(column.elements)) {
          return;
        }
        count += column.elements.length;
      });
    });

    return count;
  };

  const editorHasDraftContent = (formsContainerElement) => {
    const titleInput = formsContainerElement?.querySelector("#aipkit_ai_form_title");
    const promptTextarea = formsContainerElement?.querySelector(
      "#aipkit_ai_form_prompt_template"
    );
    const structure = window.aipkitAIFormsState?.formStructure || [];

    return Boolean(
      titleInput?.value.trim() ||
        promptTextarea?.value.trim() ||
        hasStructureElements(structure)
    );
  };

  const requestConfirmation = (message) => {
    const __ = getI18n();

    return new Promise((resolve) => {
      if (typeof window.aipkit_showConfirmModal === "function") {
        window.aipkit_showConfirmModal(message, {
          title: __("Replace current draft?", "gpt3-ai-content-generator"),
          confirmText: __("Replace draft", "gpt3-ai-content-generator"),
          cancelText: __("Cancel", "gpt3-ai-content-generator"),
          variant: "warning",
          onConfirm: () => resolve(true),
          onCancel: () => resolve(false),
        });
        return;
      }

      resolve(window.confirm(message));
    });
  };

  const setLoadingState = (modal, isLoading) => {
    const generateButton = modal?.querySelector("#aipkit_ai_form_generate_draft_btn");
    const buttonText = generateButton?.querySelector(".aipkit_btn-text");
    const spinner = generateButton?.querySelector(".aipkit_spinner");
    const config = getConfig();
    const __ = getI18n();

    if (modal) {
      modal.dataset.loading = isLoading ? "1" : "0";
    }
    if (!generateButton) {
      return;
    }

    generateButton.disabled = isLoading;
    if (spinner) {
      spinner.style.display = isLoading ? "inline-block" : "none";
    }
    if (buttonText) {
      buttonText.textContent = isLoading
        ? __(
            config.text.generatingForm || "Generating form draft...",
            "gpt3-ai-content-generator"
          )
        : __("Generate", "gpt3-ai-content-generator");
    }
  };

  const renderGeneratedStructure = (formsContainerElement, structure) => {
    const editorContainer = formsContainerElement?.querySelector(
      "#aipkit_form_editor_container"
    );
    const designerArea = editorContainer?.querySelector(
      "#aipkit_ai_form_designer_area"
    );
    const state = window.aipkitAIFormsState;

    if (!designerArea || !state) {
      return;
    }

    if (typeof window.aipkitForms_hideSettingsPanel === "function") {
      window.aipkitForms_hideSettingsPanel(formsContainerElement);
    }

    state.formStructure = Array.isArray(structure) ? JSON.parse(JSON.stringify(structure)) : [];
    state.selectedDesignerElementId = null;
    designerArea.innerHTML = "";

    if (!state.formStructure.length || typeof window.aipkitForms_renderLayoutRow !== "function") {
      designerArea.appendChild(createDesignerPlaceholder(getI18n()));
      if (!state.formStructure.length) state.droppedElementCounter = 0;
      return;
    }

    state.formStructure.forEach((rowData) => {
      window.aipkitForms_renderLayoutRow(
        designerArea,
        rowData,
        null,
        formsContainerElement
      );
    });

    state.droppedElementCounter = countGeneratedElements(state.formStructure);
    if (typeof window.aipkitForms_onEditorReady === "function") {
      window.aipkitForms_onEditorReady(formsContainerElement);
    }
  };

  const applyGeneratedDraft = (formsContainerElement, generatedForm) => {
    const titleInput = formsContainerElement?.querySelector("#aipkit_ai_form_title");
    const promptTextarea = formsContainerElement?.querySelector(
      "#aipkit_ai_form_prompt_template"
    );
    const validationResults = formsContainerElement?.querySelector(
      "#aipkit_prompt_validation_results"
    );

    if (!titleInput || !promptTextarea || !generatedForm) {
      return;
    }

    titleInput.value = generatedForm.title || "";
    titleInput.dispatchEvent(new Event("input", { bubbles: true }));

    promptTextarea.value = generatedForm.prompt_template || "";
    if (validationResults) {
      validationResults.innerHTML = "";
      validationResults.classList.remove(
        "aipkit_form-help-success",
        "aipkit_form-help-error"
      );
    }

    const conversationUiPresetSelect = formsContainerElement.querySelector(
      "#aipkit_ai_form_conversation_ui_preset"
    );
    if (
      conversationUiPresetSelect &&
      generatedForm.conversation_ui_preset &&
      ["full", "compact", "minimal", "none"].includes(
        generatedForm.conversation_ui_preset
      )
    ) {
      conversationUiPresetSelect.value = generatedForm.conversation_ui_preset;
      conversationUiPresetSelect.dispatchEvent(new Event("change", { bubbles: true }));
    }

    renderGeneratedStructure(formsContainerElement, generatedForm.structure || []);

    if (typeof window.aipkitForms_updatePromptSnippets === "function") {
      window.aipkitForms_updatePromptSnippets(formsContainerElement);
    }

    if (typeof window.aipkitForms_notifyEditorMutation === "function") {
      window.aipkitForms_notifyEditorMutation(formsContainerElement, {
        queueSave: false,
        immediate: false,
      });
    }
  };

  function aipkitForms_openFormGeneratorModal(formsContainerElement) {
    const modal = getModal(formsContainerElement);
    const promptInput = modal?.querySelector("#aipkit_ai_form_generator_prompt");

    if (!modal || modal.dataset.loading === "1") {
      return;
    }

    setModalStatus(modal, "", "");
    modal.classList.add("aipkit-active");
    modal.setAttribute("aria-hidden", "false");

    deferDialogFocus(modal, () => {
      if (promptInput && getModal(formsContainerElement) === modal) {
        promptInput.focus();
        const nextLength = promptInput.value.length;
        promptInput.setSelectionRange(nextLength, nextLength);
      }
    });
  }

  const hideModal = (modal) => {
    cancelDialogFocus(modal);
    modal.classList.remove("aipkit-active");
    modal.setAttribute("aria-hidden", "true");
    setModalStatus(modal, "");
  };

  function aipkitForms_closeFormGeneratorModal(formsContainerElement, options = {}) {
    const modal = getModal(formsContainerElement);
    if (!modal || (modal.dataset.loading === "1" && options.force !== true)) return;
    generationRequests.delete(modal);
    hideModal(modal);
    if (modal.dataset.loading === "1") setLoadingState(modal, false);
  }

  async function aipkitForms_generateDraftFromPrompt(formsContainerElement) {
    const config = getConfig();
    const __ = getI18n();
    const modal = getModal(formsContainerElement);
    const promptInput = modal?.querySelector("#aipkit_ai_form_generator_prompt");
    const editorContainer = formsContainerElement?.querySelector(
      "#aipkit_form_editor_container"
    );
    const providerSelect = editorContainer?.querySelector("#aipkit_ai_form_ai_provider");
    const modelSelect = editorContainer?.querySelector("#aipkit_ai_form_ai_model");

    if (!formsContainerElement || !modal || !promptInput || !editorContainer) {
      return Promise.reject(new Error("AI Forms generator UI is not available."));
    }

    if (generationRequests.has(modal)) {
      const error = new Error(__("Form generation is already in progress.", "gpt3-ai-content-generator"));
      error.code = "generation_in_progress";
      throw error;
    }

    const generationPrompt = promptInput.value.trim();
    if (!generationPrompt) {
      const message = __(
        config.text.generatorPromptRequired ||
          "Describe the AI task before generating a form draft.",
        "gpt3-ai-content-generator"
      );
      setModalStatus(modal, message, "error");
      return Promise.reject(new Error(message));
    }

    const request = { loading: false };
    generationRequests.set(modal, request);
    const isCurrent = () =>
      generationRequests.get(modal) === request && modal.isConnected &&
      editorContainer.isConnected && getModal(formsContainerElement) === modal &&
      formsContainerElement.querySelector("#aipkit_form_editor_container") === editorContainer;

    try {
      if (editorHasDraftContent(formsContainerElement)) {
        const confirmed = await requestConfirmation(
          __(
            config.text.confirmReplaceGeneratedDraft ||
              "Generating a new draft will replace the current title, prompt, and fields in the editor. Continue?",
            "gpt3-ai-content-generator"
          )
        );

        if (!isCurrent() || !confirmed) {
          const cancelError = new Error(__("Generation cancelled.", "gpt3-ai-content-generator"));
          cancelError.code = "generation_cancelled";
          return Promise.reject(cancelError);
        }
      }

      if (typeof window.aipkit_apiRequest !== "function") {
        const error = new Error("aipkit_apiRequest not found.");
        setModalStatus(
          modal,
          __(
            config.text.errorGeneratingForm || "Error generating form draft.",
            "gpt3-ai-content-generator"
          ),
          "error"
        );
        return Promise.reject(error);
      }

      request.loading = true;
      setLoadingState(modal, true);
      setModalStatus(
        modal,
        __(
          config.text.generatingForm || "Generating form draft...",
          "gpt3-ai-content-generator"
        )
      );
      if (typeof window.aipkitForms_setHeaderStatus === "function") {
        window.aipkitForms_setHeaderStatus(
          formsContainerElement,
          __(
            config.text.generatingForm || "Generating form draft...",
            "gpt3-ai-content-generator"
          ),
          "loading"
        );
      }

      try {
        const response = await window.aipkit_apiRequest(
          "aipkit_generate_ai_form_from_prompt",
          {
            _ajax_nonce: config.nonce_manage_forms,
            generation_prompt: generationPrompt,
            ai_provider: providerSelect?.value || "",
            ai_model: modelSelect && !modelSelect.disabled ? modelSelect.value || "" : "",
          }
        );

        if (!isCurrent()) {
          const error = new Error(__("Generation cancelled.", "gpt3-ai-content-generator"));
          error.code = "generation_cancelled";
          throw error;
        }

        if (!response?.form) {
          throw new Error(
            __(
              config.text.errorGeneratingForm || "Error generating form draft.",
              "gpt3-ai-content-generator"
            )
          );
        }

        applyGeneratedDraft(formsContainerElement, response.form);
        hideModal(modal);

        if (typeof window.aipkitForms_setHeaderStatus === "function") {
          window.aipkitForms_setHeaderStatus(
            formsContainerElement,
            response.message ||
              __(
                config.text.formGenerated ||
                  "Form draft generated. Review and save it when ready.",
                "gpt3-ai-content-generator"
              ),
            "success"
          );
        }

        return response;
      } catch (error) {
        const message =
          error?.message ||
          __(
            config.text.errorGeneratingForm || "Error generating form draft.",
            "gpt3-ai-content-generator"
          );

        if (isCurrent()) {
          setModalStatus(modal, message, "error");
          if (typeof window.aipkitForms_setHeaderStatus === "function") {
            window.aipkitForms_setHeaderStatus(formsContainerElement, message, "error");
          }
        }
        throw error;
      }
    } finally {
      if (generationRequests.get(modal) === request) {
        generationRequests.delete(modal);
        if (request.loading) setLoadingState(modal, false);
      }
    }
  }

  function aipkitForms_initFormGeneratorModal(formsContainerElement) {
    const modal = getModal(formsContainerElement);
    if (!formsContainerElement || !modal || modal.dataset.bound === "1") {
      return;
    }

    const promptInput = modal.querySelector("#aipkit_ai_form_generator_prompt");
    const inspireButton = modal.querySelector(
      "#aipkit_ai_form_generator_inspire_btn"
    );

    if (inspireButton && inspireButton.dataset.bound !== "1") {
      inspireButton.addEventListener("click", (event) => {
        event.preventDefault();
        replaceInspirationPrompt(promptInput, modal);
      });
      inspireButton.dataset.bound = "1";
    }

    bindDialogKeydown(modal, (event) => {
      if (event.key === "Escape") {
        aipkitForms_closeFormGeneratorModal(formsContainerElement);
      }
    });

    modal.dataset.bound = "1";
  }

  window.aipkitForms_initFormGeneratorModal = aipkitForms_initFormGeneratorModal;
  window.aipkitForms_openFormGeneratorModal = aipkitForms_openFormGeneratorModal;
  window.aipkitForms_closeFormGeneratorModal = aipkitForms_closeFormGeneratorModal;
  window.aipkitForms_generateDraftFromPrompt =
    aipkitForms_generateDraftFromPrompt;
  window.aipkitForms_applyGeneratedDraft = applyGeneratedDraft;
})();
