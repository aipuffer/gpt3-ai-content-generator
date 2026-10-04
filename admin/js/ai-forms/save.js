/** AI Forms save payload, validation and manual/autosave requests. */
import { captureEditorSession } from "./editor.js";

(function () {
  "use strict";

  let statusTimer = null;

  // Query and payload order are part of the saved form contract.
  const searchFieldSpecs = [
    ["openai_web_search_enabled", "checkbox", null],
    ["claude_web_search_enabled", "checkbox", null],
    ["openrouter_web_search_enabled", "checkbox", null],
    ["xai_web_search_enabled", "checkbox", null],
    ["google_search_grounding_enabled", "checkbox", null],
    ["openai_web_search_context_size", "select", "medium"],
    ["openai_web_search_loc_type", "select", "none"],
    ["openai_web_search_loc_country", "input", ""],
    ["openai_web_search_loc_city", "input", ""],
    ["openai_web_search_loc_region", "input", ""],
    ["openai_web_search_loc_timezone", "input", ""],
    ["claude_web_search_max_uses", "input", "5"],
    ["claude_web_search_loc_type", "select", "none"],
    ["claude_web_search_loc_country", "input", ""],
    ["claude_web_search_loc_city", "input", ""],
    ["claude_web_search_loc_region", "input", ""],
    ["claude_web_search_loc_timezone", "input", ""],
    ["claude_web_search_allowed_domains", "input", ""],
    ["claude_web_search_blocked_domains", "input", ""],
    ["claude_web_search_cache_ttl", "select", "none"],
    ["openrouter_web_search_engine", "select", "auto"],
    ["openrouter_web_search_max_results", "input", "5"],
    ["openrouter_web_search_max_uses", "input", "1"],
    ["openrouter_web_search_max_total_results", "input", "10"],
    ["openrouter_web_search_context_size", "select", "auto"],
    ["openrouter_web_search_allowed_domains", "input", ""],
    ["openrouter_web_search_excluded_domains", "input", ""],
  ];

  function captureSearchFields(editorContainer) {
    return searchFieldSpecs.map(([key, type, fallback]) => ({
      key,
      checkbox: type === "checkbox",
      fallback,
      element: editorContainer.querySelector(
        `${type === "checkbox" ? "input" : type}[name="${key}"]`
      ),
    }));
  }

  function serializeSearchFields(fields) {
    return Object.fromEntries(fields.map(({ key, checkbox, fallback, element }) => [
      key,
      checkbox ? (element?.checked ? "1" : "0") : element?.value || fallback,
    ]));
  }

  const getI18n = () =>
    typeof wp !== "undefined" && wp.i18n && wp.i18n.__
      ? wp.i18n.__
      : (text) => text;

  const hasStructureElements = (structure) => {
    if (!Array.isArray(structure) || structure.length === 0) {
      return false;
    }

    for (const row of structure) {
      if (!row || !Array.isArray(row.columns)) {
        continue;
      }
      for (const column of row.columns) {
        if (!column || !Array.isArray(column.elements)) {
          continue;
        }
        if (column.elements.length > 0) {
          return true;
        }
      }
    }

    return false;
  };

  const getStatusElement = (formsContainerElement) => {
    if (!formsContainerElement) {
      return null;
    }
    return formsContainerElement.querySelector("#aipkit_ai_forms_status");
  };

  const setHeaderStatus = (formsContainerElement, message, tone) => {
    if (statusTimer) {
      window.clearTimeout(statusTimer);
      statusTimer = null;
    }
    const statusEl = getStatusElement(formsContainerElement);
    if (!statusEl) {
      return;
    }

    const usesTrainingStyles = statusEl.classList.contains(
      "aipkit_training_status"
    );
    statusEl.textContent = message || "";

    if (usesTrainingStyles) {
      statusEl.classList.remove(
        "is-visible",
        "is-success",
        "is-error",
        "is-warning",
        "is-loading"
      );
      if (message) {
        statusEl.classList.add("is-visible");
      }
      if (tone) {
        statusEl.classList.add(`is-${tone}`);
      }
      return;
    }

    statusEl.classList.remove("aipkit_form-help-success", "aipkit_form-help-error");
    if (tone === "success") {
      statusEl.classList.add("aipkit_form-help-success");
    } else if (tone === "error") {
      statusEl.classList.add("aipkit_form-help-error");
    }
  };

  const clearHeaderStatusSoon = (formsContainerElement, delay = 2500) => {
    if (statusTimer) {
      window.clearTimeout(statusTimer);
    }
    statusTimer = window.setTimeout(() => {
      setHeaderStatus(formsContainerElement, "", "");
    }, delay);
  };

  const requestConfirmation = (message, options = {}) => {
    const __ = getI18n();

    return new Promise((resolve) => {
      if (typeof window.aipkit_showConfirmModal === "function") {
        window.aipkit_showConfirmModal(message, {
          title: options.title || __("Confirm", "gpt3-ai-content-generator"),
          confirmText:
            options.confirmText || __("Confirm", "gpt3-ai-content-generator"),
          cancelText:
            options.cancelText || __("Cancel", "gpt3-ai-content-generator"),
          variant: options.variant || "default",
          onConfirm: () => resolve(true),
          onCancel: () => resolve(false),
        });
        return;
      }

      resolve(window.confirm(message));
    });
  };

  function aipkitForms_buildSavePayload(formsContainerElement, options = {}) {
    const state = window.aipkitAIFormsState;
    const config = window.aipkit_ai_forms_config || { text: {} };
    const __ = getI18n();

    if (!formsContainerElement) {
      return {
        error: {
          code: "missing_container",
          message: "AI Forms saveForm: formsContainerElement not provided.",
        },
      };
    }

    const editorContainer = formsContainerElement.querySelector(
      "#aipkit_form_editor_container"
    );
    if (!editorContainer) {
      return {
        error: {
          code: "missing_editor",
          message:
            "AI Forms saveForm: editorContainer not found in provided formsContainerElement.",
        },
      };
    }

    const formTitleInput = formsContainerElement.querySelector("#aipkit_ai_form_title");
    const promptTemplateTextarea = editorContainer.querySelector(
      "#aipkit_ai_form_prompt_template"
    );
    const formIdInput = editorContainer.querySelector("#aipkit_ai_form_id");
    const templateKeyInput = editorContainer.querySelector(
      "#aipkit_ai_form_template_key"
    );

    const providerSelect = editorContainer.querySelector("#aipkit_ai_form_ai_provider");
    const modelSelect = editorContainer.querySelector("#aipkit_ai_form_ai_model");
    const temperatureInput = editorContainer.querySelector("#aipkit_ai_form_temperature");
    const maxTokensInput = editorContainer.querySelector("#aipkit_ai_form_max_tokens");
    const topPInput = editorContainer.querySelector("#aipkit_ai_form_top_p");
    const frequencyPenaltyInput = editorContainer.querySelector(
      "#aipkit_ai_form_frequency_penalty"
    );
    const presencePenaltyInput = editorContainer.querySelector(
      "#aipkit_ai_form_presence_penalty"
    );
    const reasoningEffortSelect = editorContainer.querySelector(
      "#aipkit_ai_form_reasoning_effort"
    );
    const conversationUiPresetSelect = editorContainer.querySelector(
      "#aipkit_ai_form_conversation_ui_preset"
    );

    const enableVectorStoreCheckbox = editorContainer.querySelector(
      'input[name="enable_vector_store"]'
    );
    const vectorStoreProviderSelect = editorContainer.querySelector(
      'select[name="vector_store_provider"]'
    );
    const openAiVectorStoresSelect = editorContainer.querySelector(
      'select[name="openai_vector_store_ids[]"]'
    );
    const googleFileSearchStoresSelect = editorContainer.querySelector(
      'select[name="google_file_search_store_names[]"]'
    );
    const pineconeIndexSelect = editorContainer.querySelector(
      'select[name="pinecone_index_name"]'
    );
    const qdrantCollectionSelect = editorContainer.querySelector(
      'select[name="qdrant_collection_name"]'
    );
    const chromaCollectionSelect = editorContainer.querySelector(
      'select[name="chroma_collection_name"]'
    );
    const localStoreSelect = editorContainer.querySelector(
      'select[name="local_store_id"]'
    );
    const embeddingProviderSelect = editorContainer.querySelector(
      'select[name="vector_embedding_provider"]'
    );
    const embeddingModelSelect = editorContainer.querySelector(
      'select[name="vector_embedding_model"]'
    );
    const topKInput = editorContainer.querySelector('input[name="vector_store_top_k"]');
    const vectorConfidenceThresholdInput = editorContainer.querySelector(
      'input[name="vector_store_confidence_threshold"]'
    );

    const searchFields = captureSearchFields(editorContainer);

    if (
      !formTitleInput ||
      !promptTemplateTextarea ||
      !formIdInput ||
      !providerSelect ||
      !modelSelect ||
      !temperatureInput ||
      !maxTokensInput ||
      !topPInput ||
      !frequencyPenaltyInput ||
      !presencePenaltyInput
    ) {
      return {
        error: {
          code: "missing_inputs",
          message:
            "AI Forms saveForm: One or more critical form editor inputs not found in editorContainer.",
        },
      };
    }

    let formTitle = formTitleInput.value.trim();
    const promptTemplate = promptTemplateTextarea.value.trim();
    const currentFormIdValue = formIdInput.value;

    if (!formTitle) {
      formTitle = __("New Form", "gpt3-ai-content-generator");
      formTitleInput.value = formTitle;
      if (!options.skipSyntheticInputEvent) {
        formTitleInput.dispatchEvent(new Event("input", { bubbles: true }));
      }
    }

    if (!promptTemplate) {
      return {
        error: {
          code: "prompt_required",
          message:
            config.text.promptTemplateRequired || "Prompt template is required.",
        },
      };
    }

    const fieldIds = new Set();
    if (Array.isArray(state?.formStructure)) {
      for (const row of state.formStructure) {
        if (!row || !Array.isArray(row.columns)) {
          continue;
        }
        for (const column of row.columns) {
          if (!column || !Array.isArray(column.elements)) {
            continue;
          }
          for (const element of column.elements) {
            if (!element) {
              continue;
            }
            if (!element.fieldId || !/^[a-zA-Z0-9_]+$/.test(element.fieldId)) {
              return {
                error: {
                  code: "invalid_field_id",
                  message: __(
                    `Invalid Field Variable Name: "${
                      element.fieldId || ""
                    }" for element "${
                      element.label || ""
                    }". Only letters, numbers, and underscores allowed, and it cannot be empty.`,
                    "gpt3-ai-content-generator"
                  ),
                  elementInternalId: element.internalId,
                },
              };
            }
            if (fieldIds.has(element.fieldId)) {
              return {
                error: {
                  code: "duplicate_field_id",
                  message: __(
                    `Duplicate Field Variable Name: "${element.fieldId}". Each field variable name must be unique.`,
                    "gpt3-ai-content-generator"
                  ),
                  elementInternalId: element.internalId,
                },
              };
            }
            fieldIds.add(element.fieldId);
          }
        }
      }
    }

    const structure = Array.isArray(state?.formStructure) ? state.formStructure : [];
    if (
      currentFormIdValue &&
      !options.allowEmptyStructure &&
      !hasStructureElements(structure)
    ) {
      return {
        error: {
          code: "empty_structure_confirmation_required",
          message: __(
            config.text.confirmSaveEmptyForm ||
              "This form currently has no fields. Saving now will remove previously configured fields. Do you want to continue?",
            "gpt3-ai-content-generator"
          ),
        },
      };
    }

    const openAiVectorStoreIds = openAiVectorStoresSelect
      ? Array.from(openAiVectorStoresSelect.selectedOptions).map(
          (opt) => opt.value
        )
      : [];
    const googleFileSearchStoreNames = googleFileSearchStoresSelect
      ? Array.from(googleFileSearchStoresSelect.selectedOptions)
          .filter((option) => !option.disabled && option.value)
          .map((option) => option.value)
      : [];

    const labelInputs = editorContainer.querySelectorAll('input[name^="labels["]');
    const labelsData = {};
    labelInputs.forEach((input) => {
      const keyMatch = input.name.match(/\[(.*?)\]/);
      if (keyMatch && keyMatch[1]) {
        labelsData[keyMatch[1]] = input.value;
      }
    });

    const getBoundedNumberValue = (input, fallback = "") => {
      if (!input) return fallback;
      const parsedValue = Number.parseFloat(input.value);
      const fallbackValue = Number.parseFloat(input.defaultValue || fallback);
      const min = Number.parseFloat(input.min);
      const max = Number.parseFloat(input.max);
      const step = Number.parseFloat(input.step);
      let value = Number.isFinite(parsedValue)
        ? parsedValue
        : Number.isFinite(fallbackValue)
          ? fallbackValue
          : 0;

      if (Number.isFinite(min)) value = Math.max(min, value);
      if (Number.isFinite(max)) value = Math.min(max, value);

      if (Number.isFinite(step) && step >= 1) {
        value = Math.round(value);
      } else if (Number.isFinite(step) && step > 0) {
        const precision = String(input.step).split(".")[1]?.length || 0;
        value = Number(value.toFixed(precision));
      }

      input.value = String(value);
      return input.value;
    };

    const data = {
      _ajax_nonce: config.nonce_manage_forms,
      form_id: currentFormIdValue || null,
      template_key: templateKeyInput?.value || "",
      title: formTitle,
      prompt_template: promptTemplate,
      form_structure: JSON.stringify(structure),
      ai_provider: providerSelect.value,
      ai_model: modelSelect.value,
      temperature: getBoundedNumberValue(temperatureInput, "1"),
      max_tokens: getBoundedNumberValue(maxTokensInput, "2000"),
      top_p: getBoundedNumberValue(topPInput, "1"),
      frequency_penalty: getBoundedNumberValue(frequencyPenaltyInput, "0"),
      presence_penalty: getBoundedNumberValue(presencePenaltyInput, "0"),
      reasoning_effort: reasoningEffortSelect?.value || "",
      enable_vector_store: enableVectorStoreCheckbox?.checked ? "1" : "0",
      vector_store_provider: vectorStoreProviderSelect?.value,
      openai_vector_store_ids: openAiVectorStoreIds,
      google_file_search_store_names: googleFileSearchStoreNames,
      pinecone_index_name: pineconeIndexSelect?.value || "",
      qdrant_collection_name: qdrantCollectionSelect?.value || "",
      chroma_collection_name: chromaCollectionSelect?.value || "",
      local_store_id: localStoreSelect?.value || "",
      vector_embedding_provider: embeddingProviderSelect?.value,
      vector_embedding_model: embeddingModelSelect?.value || "",
      vector_store_top_k: topKInput?.value || 3,
      vector_store_confidence_threshold: vectorConfidenceThresholdInput?.value || 20,
      ...serializeSearchFields(searchFields),
      labels: JSON.stringify(labelsData),
    };

    if (conversationUiPresetSelect) {
      data.conversation_ui_preset = conversationUiPresetSelect.value || "full";
    }

    if (options.allowEmptyStructure) {
      data.allow_empty_structure = "1";
    }

    const saveDataCollectors = window.aipkitAIFormsSaveDataCollectors;
    if (Array.isArray(saveDataCollectors)) {
      try {
        saveDataCollectors.forEach((collector) => {
          if (typeof collector !== "function") {
            return;
          }
          const extraData = collector({
            formsContainerElement,
            editorContainer,
            data,
            options,
            formStructure: structure,
          });
          if (
            extraData &&
            typeof extraData === "object" &&
            !Array.isArray(extraData)
          ) {
            Object.assign(data, extraData);
          }
        });
      } catch (collectorError) {
        console.warn("AI Forms save data collector failed:", collectorError);
        return {
          error: {
            code: "save_extension_failed",
            message:
              collectorError?.message ||
              __("Unable to collect form extension settings.", "gpt3-ai-content-generator"),
          },
        };
      }
    }

    return {
      data,
      formIdInput,
      currentFormIdValue,
      formStructure: structure,
      editorContainer,
    };
  }

  function focusFieldIdInputForElement(editorContainer, formsContainerElement, elementId) {
    if (!editorContainer || !elementId) {
      return;
    }
    if (typeof window.aipkitForms_showSettingsPanel === "function") {
      window.aipkitForms_showSettingsPanel(elementId, formsContainerElement);
    }
    const fieldIdInputEl = editorContainer.querySelector(
      `#setting-field-id-${elementId}`
    );
    if (fieldIdInputEl) {
      fieldIdInputEl.focus();
    }
  }

  function aipkitForms_saveForm(saveButton, formsContainerElement, options = {}) {
    const config = window.aipkit_ai_forms_config || { text: {} };
    const __ = getI18n();
    const isAutosave = options.isAutosave === true;

    if (!formsContainerElement) {
      console.error("AI Forms saveForm: formsContainerElement not provided.");
      return Promise.reject(
        new Error("AI Forms saveForm: formsContainerElement not provided.")
      );
    }

    if (typeof window.aipkit_apiRequest !== "function") {
      const err = new Error("AI Forms saveForm: aipkit_apiRequest not found.");
      setHeaderStatus(
        formsContainerElement,
        __("Error saving form.", "gpt3-ai-content-generator"),
        "error"
      );
      return Promise.reject(err);
    }

    const payload = aipkitForms_buildSavePayload(formsContainerElement, {
      suppressValidationAlerts: options.suppressValidationAlerts === true,
      allowEmptyStructure: options.allowEmptyStructure === true,
      skipSyntheticInputEvent: isAutosave,
    });

    if (payload.error) {
      const error = new Error(payload.error.message || "Unable to save form.");
      error.code = payload.error.code || "save_validation_failed";
      error.elementInternalId = payload.error.elementInternalId || "";

      if (
        error.code === "empty_structure_confirmation_required" &&
        !isAutosave &&
        options.allowEmptyStructure !== true
      ) {
        const isCurrentEditor = captureEditorSession(formsContainerElement);
        return requestConfirmation(error.message, {
          title: __("Save empty form?", "gpt3-ai-content-generator"),
          confirmText: __("Save empty form", "gpt3-ai-content-generator"),
          cancelText: __("Cancel", "gpt3-ai-content-generator"),
          variant: "warning",
        }).then((confirmed) => {
          if (!confirmed || !isCurrentEditor()) {
            const cancelError = new Error(
              __("Save cancelled.", "gpt3-ai-content-generator")
            );
            cancelError.code = "save_cancelled";
            return Promise.reject(cancelError);
          }
          return aipkitForms_saveForm(saveButton, formsContainerElement, {
            ...options,
            allowEmptyStructure: true,
          });
        });
      }

      if (isAutosave) {
        setHeaderStatus(
          formsContainerElement,
          __("Unsaved changes", "gpt3-ai-content-generator"),
          "warning"
        );
      } else if (options.suppressValidationAlerts !== true) {
        setHeaderStatus(formsContainerElement, error.message, "error");
        if (error.code === "invalid_field_id" || error.code === "duplicate_field_id") {
          focusFieldIdInputForElement(
            formsContainerElement.querySelector("#aipkit_form_editor_container"),
            formsContainerElement,
            error.elementInternalId
          );
        }
      }

      return Promise.reject(error);
    }

    const { data, currentFormIdValue, formIdInput } = payload;
    const saveState =
      typeof window.aipkitForms_captureSaveState === "function"
        ? window.aipkitForms_captureSaveState(formsContainerElement)
        : null;
    const buttonEl = saveButton && saveButton.nodeType === 1 ? saveButton : null;
    const buttonTextEl = buttonEl?.querySelector(".aipkit_btn-text") || null;
    const spinnerEl = buttonEl?.querySelector(".aipkit_spinner") || null;
    const originalButtonText = buttonTextEl
      ? buttonTextEl.textContent
      : buttonEl
      ? buttonEl.textContent
      : "";

    if (buttonEl && !isAutosave) {
      buttonEl.disabled = true;
      if (spinnerEl) {
        spinnerEl.style.display = "inline-block";
      }
      if (buttonTextEl) {
        buttonTextEl.textContent = __(
          options.loadingText || config.text.savingForm || "Saving...",
          "gpt3-ai-content-generator"
        );
      }
    }

    setHeaderStatus(
      formsContainerElement,
      __("Saving...", "gpt3-ai-content-generator"),
      "loading"
    );

    return window
      .aipkit_apiRequest("aipkit_save_ai_form", data)
      .then((response) => {
        if (saveState && !saveState.isCurrent()) return response;
        if (!currentFormIdValue && response.form_id) {
          if (formIdInput) {
            formIdInput.value = response.form_id;
          }
          if (window.aipkitAIFormsState) {
            window.aipkitAIFormsState.currentFormId = response.form_id;
          }
        }

        let hasPendingChanges = false;
        if (typeof window.aipkitForms_onFormSaveSuccess === "function") {
          try {
            hasPendingChanges =
              window.aipkitForms_onFormSaveSuccess(formsContainerElement, response, {
                isAutosave,
                requestData: data,
                saveState,
              }) === true;
          } catch (hookError) {
            console.warn("AI Forms save hook failed:", hookError);
          }
        }

        if (typeof window.aipkitForms_renderConnectedApps === "function") {
          window.aipkitForms_renderConnectedApps(
            formsContainerElement,
            response.connected_apps && typeof response.connected_apps === "object"
              ? response.connected_apps
              : null,
            { isUnsaved: false }
          );
        }

        const successMessage = hasPendingChanges
          ? __("Unsaved changes", "gpt3-ai-content-generator")
          : isAutosave
          ? __("All changes saved", "gpt3-ai-content-generator")
          : response.message ||
            __(config.text.formSaved || "Form saved!", "gpt3-ai-content-generator");

        setHeaderStatus(
          formsContainerElement,
          successMessage,
          hasPendingChanges ? "warning" : "success"
        );
        if (!hasPendingChanges) {
          clearHeaderStatusSoon(formsContainerElement, isAutosave ? 1600 : 2500);
        }

        if (!isAutosave && options.skipFetchList !== true) {
          if (typeof window.aipkitForms_fetchForms === "function") {
            window.aipkitForms_fetchForms();
          }
        }

        return response;
      })
      .catch((error) => {
        if (saveState && !saveState.isCurrent()) throw error;
        const message =
          error?.message ||
          __(config.text.errorSavingForm || "Error saving form.", "gpt3-ai-content-generator");
        setHeaderStatus(formsContainerElement, message, "error");
        clearHeaderStatusSoon(formsContainerElement, 4000);
        throw error;
      })
      .finally(() => {
        if (buttonEl && !isAutosave) {
          buttonEl.disabled = false;
          if (spinnerEl) {
            spinnerEl.style.display = "none";
          }
          if (buttonTextEl) {
            buttonTextEl.textContent = originalButtonText;
          } else if (buttonEl) {
            buttonEl.textContent = originalButtonText;
          }
          if (
            buttonEl.matches("#aipkit_preview_ai_form_btn") &&
            typeof window.aipkitForms_syncPreviewButtonState === "function"
          ) {
            window.aipkitForms_syncPreviewButtonState(formsContainerElement);
          }
        }
      });
  }

  window.aipkitForms_buildSavePayload = aipkitForms_buildSavePayload;
  window.aipkitForms_saveForm = aipkitForms_saveForm;
  window.aipkitForms_setHeaderStatus = setHeaderStatus;
})();
