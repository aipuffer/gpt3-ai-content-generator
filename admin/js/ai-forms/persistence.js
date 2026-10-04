/**
 * AIPKit AI Forms - Editor Persistence, Autosave, and Unsaved-Changes Guards
 */
import { showDesignerPlaceholder } from "./structure.js";

(function () {
  "use strict";

  const DRAFT_STORAGE_PREFIX = "aipkit_ai_forms_editor_draft_v1_";
  const AUTOSAVE_DELAY_MS = 1500;

  const runtime = {
    activeContainer: null,
    editorGeneration: 0,
    baselineSignature: "",
    autosaveTimer: null,
    autosaveInFlight: false,
    pendingAutosave: false,
    suppressTracking: false,
    activeDraftKey: "",
    restorePromptedKey: "",
    unloadBound: false,
  };

  const getI18n = () =>
    typeof wp !== "undefined" && wp.i18n && wp.i18n.__
      ? wp.i18n.__
      : (text) => text;

  const getFormsContainer = (formsContainerElement) => {
    if (formsContainerElement && formsContainerElement.nodeType === 1) {
      return formsContainerElement;
    }
    return document.getElementById("aipkit_ai_forms_container");
  };

  const getEditorContainer = (formsContainerElement) => {
    if (!formsContainerElement) {
      return null;
    }
    return formsContainerElement.querySelector("#aipkit_form_editor_container");
  };

  const isEditorVisible = (formsContainerElement) => {
    const editorContainer = getEditorContainer(formsContainerElement);
    if (!editorContainer) {
      return false;
    }
    return window.getComputedStyle(editorContainer).display !== "none";
  };

  const getCurrentFormId = (formsContainerElement) => {
    const editorContainer = getEditorContainer(formsContainerElement);
    const formIdInput = editorContainer?.querySelector("#aipkit_ai_form_id");
    return formIdInput?.value ? String(formIdInput.value) : "";
  };

  const getCurrentUserId = () => {
    const config = window.aipkit_ai_forms_config || {};
    return String(config.current_user_id || 0);
  };

  const getDraftKey = (formsContainerElement) => {
    const formId = getCurrentFormId(formsContainerElement);
    const normalizedFormId = formId ? `form_${formId}` : "new";
    return `${DRAFT_STORAGE_PREFIX}${getCurrentUserId()}_${normalizedFormId}`;
  };

  const accessDraftStorage = (method, key, ...args) => {
    try {
      const storage = window.localStorage;
      if (key && storage) {
        return storage[method](key, ...args);
      }
    } catch (error) {
      // Ignore unavailable storage and quota/privacy errors.
    }
    return method === "getItem" ? null : undefined;
  };

  const deepClone = (value) => {
    try {
      return JSON.parse(JSON.stringify(value));
    } catch (error) {
      return Array.isArray(value) ? [] : {};
    }
  };

  const stableStringify = (value) => {
    if (Array.isArray(value)) {
      return `[${value.map((item) => stableStringify(item)).join(",")}]`;
    }
    if (value && typeof value === "object") {
      const keys = Object.keys(value).sort();
      return `{${keys
        .map((key) => `${JSON.stringify(key)}:${stableStringify(value[key])}`)
        .join(",")}}`;
    }
    return JSON.stringify(value);
  };

  const getSnapshotSignature = (snapshot) => {
    if (!snapshot || typeof snapshot !== "object") {
      return "";
    }
    const normalized = {
      ...snapshot,
      savedAt: 0,
    };
    return stableStringify(normalized);
  };

  const getMaxElementCounter = (structure) => {
    let maxCounter = 0;
    if (!Array.isArray(structure)) {
      return maxCounter;
    }

    structure.forEach((row) => {
      if (!row || !Array.isArray(row.columns)) {
        return;
      }
      row.columns.forEach((col) => {
        if (!col || !Array.isArray(col.elements)) {
          return;
        }
        col.elements.forEach((el) => {
          if (!el || typeof el.internalId !== "string") {
            return;
          }
          const parts = el.internalId.split("-");
          const num = parseInt(parts[parts.length - 1], 10) || 0;
          if (num > maxCounter) {
            maxCounter = num;
          }
        });
      });
    });

    return maxCounter;
  };

  const collectControlValues = (editorContainer) => {
    const values = {};
    if (!editorContainer) {
      return values;
    }

    const controls = editorContainer.querySelectorAll(
      "input[name], select[name], textarea[name]"
    );

    controls.forEach((control) => {
      if (!control || !control.name) {
        return;
      }
      if (control.closest(".aipkit_dropped_element")) {
        return;
      }
      if (control.type === "file") {
        return;
      }

      if (control.tagName === "SELECT" && control.multiple) {
        values[control.name] = Array.from(control.selectedOptions).map(
          (option) => option.value
        );
        return;
      }

      if (control.type === "checkbox") {
        values[control.name] = control.checked ? "1" : "0";
        return;
      }

      if (control.type === "radio") {
        if (control.checked) {
          values[control.name] = control.value;
        }
        return;
      }

      values[control.name] = control.value;
    });

    return values;
  };

  const collectEditorSnapshot = (formsContainerElement) => {
    const formsContainer = getFormsContainer(formsContainerElement);
    if (!formsContainer) {
      return null;
    }

    const editorContainer = getEditorContainer(formsContainer);
    if (!editorContainer) {
      return null;
    }

    const state = window.aipkitAIFormsState || {};
    const titleInput = formsContainer.querySelector("#aipkit_ai_form_title");
    const promptInput = editorContainer.querySelector("#aipkit_ai_form_prompt_template");
    const formIdInput = editorContainer.querySelector("#aipkit_ai_form_id");

    return {
      version: 1,
      formId: formIdInput?.value ? String(formIdInput.value) : "",
      title: titleInput?.value || "",
      promptTemplate: promptInput?.value || "",
      structure: deepClone(state.formStructure || []),
      controls: collectControlValues(editorContainer),
      savedAt: Date.now(),
    };
  };

  const captureSaveState = (formsContainerElement) => {
    const formsContainer = getFormsContainer(formsContainerElement);
    const generation = runtime.editorGeneration;
    return {
      snapshot: collectEditorSnapshot(formsContainer),
      isCurrent: () =>
        runtime.activeContainer === formsContainer &&
        runtime.editorGeneration === generation,
    };
  };

  const setHeaderStatus = (formsContainerElement, message, tone) => {
    if (typeof window.aipkitForms_setHeaderStatus === "function") {
      window.aipkitForms_setHeaderStatus(formsContainerElement, message, tone);
      return;
    }

    const statusEl = formsContainerElement?.querySelector("#aipkit_ai_forms_status");
    if (!statusEl) {
      return;
    }

    statusEl.textContent = message || "";
    statusEl.classList.remove("is-visible", "is-success", "is-error", "is-warning", "is-loading");
    if (message) {
      statusEl.classList.add("is-visible");
    }
    if (tone) {
      statusEl.classList.add(`is-${tone}`);
    }
  };

  const readDraft = (key) => {
    const raw = accessDraftStorage("getItem", key);
    if (!raw) {
      return null;
    }

    try {
      const parsed = JSON.parse(raw);
      if (!parsed || typeof parsed !== "object") {
        return null;
      }
      if (!parsed.snapshot || typeof parsed.snapshot !== "object") {
        return null;
      }
      return parsed;
    } catch (error) {
      return null;
    }
  };

  const writeDraft = (key, payload) => {
    if (!key || !payload || typeof payload !== "object") {
      return;
    }
    accessDraftStorage("setItem", key, JSON.stringify(payload));
  };

  const refreshActiveDraftKey = (formsContainerElement) => {
    const formsContainer = getFormsContainer(formsContainerElement);
    if (!formsContainer) {
      runtime.activeDraftKey = "";
      return "";
    }

    const nextKey = getDraftKey(formsContainer);
    if (!runtime.activeDraftKey) {
      runtime.activeDraftKey = nextKey;
      return nextKey;
    }

    if (runtime.activeDraftKey !== nextKey) {
      const existingNext = readDraft(nextKey);
      const existingOld = readDraft(runtime.activeDraftKey);
      if (!existingNext && existingOld) {
        writeDraft(nextKey, existingOld);
      }
      accessDraftStorage("removeItem", runtime.activeDraftKey);
      runtime.activeDraftKey = nextKey;
    }

    return runtime.activeDraftKey;
  };

  const persistDraft = (formsContainerElement, snapshot = null) => {
    const formsContainer = getFormsContainer(formsContainerElement);
    if (!formsContainer) {
      return;
    }

    const nextSnapshot = snapshot || collectEditorSnapshot(formsContainer);
    if (!nextSnapshot) {
      return;
    }

    const key = refreshActiveDraftKey(formsContainer);
    if (!key) {
      return;
    }

    writeDraft(key, {
      version: 1,
      savedAt: Date.now(),
      snapshot: nextSnapshot,
    });
  };

  const clearDraftForCurrent = (formsContainerElement) => {
    const formsContainer = getFormsContainer(formsContainerElement);
    if (!formsContainer) {
      return;
    }

    const currentKey = refreshActiveDraftKey(formsContainer);
    accessDraftStorage("removeItem", currentKey);
  };

  const setBaselineFromCurrent = (
    formsContainerElement,
    { resetRestorePrompt = false } = {}
  ) => {
    const formsContainer = getFormsContainer(formsContainerElement);
    if (!formsContainer) {
      return;
    }

    const snapshot = collectEditorSnapshot(formsContainer);
    runtime.baselineSignature = getSnapshotSignature(snapshot);

    runtime.pendingAutosave = false;

    if (resetRestorePrompt) {
      runtime.restorePromptedKey = "";
    }
  };

  const hasUnsavedChanges = (formsContainerElement) => {
    const formsContainer = getFormsContainer(formsContainerElement);
    if (!formsContainer || !isEditorVisible(formsContainer)) {
      return false;
    }

    const snapshot = collectEditorSnapshot(formsContainer);
    const currentSignature = getSnapshotSignature(snapshot);

    if (!runtime.baselineSignature) {
      return false;
    }

    return currentSignature !== runtime.baselineSignature;
  };

  const clearAutosaveTimer = () => {
    if (runtime.autosaveTimer) {
      window.clearTimeout(runtime.autosaveTimer);
      runtime.autosaveTimer = null;
    }
  };

  const queueAutosave = (formsContainerElement, immediate = false) => {
    const formsContainer = getFormsContainer(formsContainerElement);
    if (!formsContainer || runtime.suppressTracking) {
      return;
    }

    clearAutosaveTimer();
    runtime.pendingAutosave = true;

    const delay = immediate ? 80 : AUTOSAVE_DELAY_MS;
    runtime.autosaveTimer = window.setTimeout(() => {
      runtime.autosaveTimer = null;
      runAutosave(formsContainer);
    }, delay);
  };

  const evaluateDirtyState = (
    formsContainerElement,
    { queueSave = true, immediate = false } = {}
  ) => {
    const formsContainer = getFormsContainer(formsContainerElement);
    window.aipkitForms_syncPreviewButtonState?.(formsContainer);
    if (!formsContainer || runtime.suppressTracking || !isEditorVisible(formsContainer)) {
      return;
    }

    const snapshot = collectEditorSnapshot(formsContainer);
    const signature = getSnapshotSignature(snapshot);

    if (!runtime.baselineSignature) {
      runtime.baselineSignature = signature;

      return;
    }

    const isDirty = signature !== runtime.baselineSignature;

    if (!isDirty) {
      return;
    }

    window.aipkitForms_setEditorExitState?.(formsContainer, "cancel");

    persistDraft(formsContainer, snapshot);
    if (!runtime.autosaveInFlight) {
      setHeaderStatus(
        formsContainer,
        getI18n()("Unsaved changes", "gpt3-ai-content-generator"),
        "warning"
      );
    }

    if (queueSave) {
      queueAutosave(formsContainer, immediate);
    }
  };

  async function runAutosave(formsContainerElement) {
    const formsContainer = getFormsContainer(formsContainerElement);
    if (!formsContainer) {
      return;
    }

    if (!isEditorVisible(formsContainer) || !hasUnsavedChanges(formsContainer)) {
      runtime.pendingAutosave = false;
      return;
    }

    if (runtime.autosaveInFlight) {
      runtime.pendingAutosave = true;
      return;
    }

    if (typeof window.aipkitForms_saveForm !== "function") {
      return;
    }

    const generation = runtime.editorGeneration;
    runtime.autosaveInFlight = true;
    runtime.pendingAutosave = false;

    const snapshotBeforeSave = collectEditorSnapshot(formsContainer);
    persistDraft(formsContainer, snapshotBeforeSave);

    try {
      await window.aipkitForms_saveForm(null, formsContainer, {
        isAutosave: true,
        suppressValidationAlerts: true,
        skipFetchList: true,
      });
    } catch (error) {
      if (generation !== runtime.editorGeneration) return;
      const message =
        error?.code === "prompt_required" ||
        error?.code === "invalid_field_id" ||
        error?.code === "duplicate_field_id" ||
        error?.code === "empty_structure_confirmation_required"
          ? getI18n()("Unsaved changes", "gpt3-ai-content-generator")
          : error?.message ||
            getI18n()("Autosave failed.", "gpt3-ai-content-generator");

      setHeaderStatus(formsContainer, message, "warning");
    } finally {
      if (generation === runtime.editorGeneration) {
        runtime.autosaveInFlight = false;
        if (runtime.pendingAutosave && hasUnsavedChanges(formsContainer)) {
          queueAutosave(formsContainer, true);
        }
      }
    }
  }

  const applySnapshotStructure = (formsContainerElement, structure) => {
    const formsContainer = getFormsContainer(formsContainerElement);
    if (!formsContainer) {
      return;
    }

    const editorContainer = getEditorContainer(formsContainer);
    const designerArea = editorContainer?.querySelector("#aipkit_ai_form_designer_area");
    if (!designerArea || !window.aipkitAIFormsState) {
      return;
    }

    window.aipkitAIFormsState.formStructure = Array.isArray(structure)
      ? deepClone(structure)
      : [];

    designerArea.innerHTML = "";

    if (!Array.isArray(window.aipkitAIFormsState.formStructure) || window.aipkitAIFormsState.formStructure.length === 0) {
      showDesignerPlaceholder(designerArea, getI18n());
      window.aipkitAIFormsState.droppedElementCounter = 0;
      return;
    }

    if (typeof window.aipkitForms_renderLayoutRow !== "function") {
      showDesignerPlaceholder(designerArea, getI18n());
      return;
    }

    window.aipkitAIFormsState.formStructure.forEach((rowData) => {
      window.aipkitForms_renderLayoutRow(
        designerArea,
        rowData,
        null,
        formsContainer
      );
    });

    window.aipkitAIFormsState.droppedElementCounter = getMaxElementCounter(
      window.aipkitAIFormsState.formStructure
    );
  };

  const applySnapshotControls = (editorContainer, controls) => {
    if (!editorContainer || !controls || typeof controls !== "object") {
      return;
    }

    const allNamedControls = Array.from(
      editorContainer.querySelectorAll("input[name], select[name], textarea[name]")
    );

    const controlsByName = allNamedControls.reduce((acc, control) => {
      if (!control || !control.name) {
        return acc;
      }
      if (!acc[control.name]) {
        acc[control.name] = [];
      }
      acc[control.name].push(control);
      return acc;
    }, {});

    Object.keys(controls).forEach((name) => {
      const nodes = controlsByName[name] || [];
      if (!nodes.length) {
        return;
      }

      const storedValue = controls[name];
      nodes.forEach((node) => {
        if (node.tagName === "SELECT" && node.multiple) {
          const targetValues = Array.isArray(storedValue)
            ? storedValue.map((item) => String(item))
            : [];
          Array.from(node.options).forEach((option) => {
            option.selected = targetValues.includes(option.value);
          });
          return;
        }

        if (node.type === "checkbox") {
          node.checked =
            storedValue === "1" || storedValue === true || storedValue === "true";
          return;
        }

        if (node.type === "radio") {
          node.checked = String(storedValue) === String(node.value);
          return;
        }

        node.value = storedValue ?? "";
      });
    });

    const providerSelect = editorContainer.querySelector("#aipkit_ai_form_ai_provider");
    const modelSelect = editorContainer.querySelector("#aipkit_ai_form_ai_model");
    if (
      providerSelect &&
      modelSelect &&
      typeof window.aipkitForms_populateAiModels === "function"
    ) {
      const desiredProvider = providerSelect.value || "OpenAI";
      const desiredModel = controls.ai_model || modelSelect.value || "";
      window.aipkitForms_populateAiModels(desiredProvider, desiredModel);
    }

    const changeSelectors = [
      "#aipkit_ai_form_ai_provider",
      'input[name="enable_vector_store"]',
      'input[name="openai_web_search_enabled"]',
      'input[name="claude_web_search_enabled"]',
      'input[name="openrouter_web_search_enabled"]',
      'input[name="xai_web_search_enabled"]',
      'input[name="google_search_grounding_enabled"]',
    ];

    changeSelectors.forEach((selector) => {
      const field = editorContainer.querySelector(selector);
      if (field) {
        field.dispatchEvent(new Event("change", { bubbles: true }));
      }
    });

    if (typeof window.aipkitForms_toggleReasoningEffort === "function") {
      window.aipkitForms_toggleReasoningEffort(editorContainer);
    }
  };

  const applyDraftSnapshot = (formsContainerElement, snapshot) => {
    const formsContainer = getFormsContainer(formsContainerElement);
    if (!formsContainer || !snapshot || typeof snapshot !== "object") {
      return;
    }

    const editorContainer = getEditorContainer(formsContainer);
    if (!editorContainer) {
      return;
    }

    runtime.suppressTracking = true;

    const titleInput = formsContainer.querySelector("#aipkit_ai_form_title");
    const promptInput = editorContainer.querySelector("#aipkit_ai_form_prompt_template");
    const formIdInput = editorContainer.querySelector("#aipkit_ai_form_id");

    if (titleInput) {
      titleInput.value = snapshot.title || "";
      titleInput.dispatchEvent(new Event("input", { bubbles: true }));
    }
    if (promptInput) {
      promptInput.value = snapshot.promptTemplate || "";
    }
    if (formIdInput && snapshot.formId) {
      formIdInput.value = String(snapshot.formId);
    }

    applySnapshotStructure(formsContainer, snapshot.structure || []);
    applySnapshotControls(editorContainer, snapshot.controls || {});

    if (typeof window.aipkitForms_updatePromptSnippets === "function") {
      window.aipkitForms_updatePromptSnippets(formsContainer);
    }

    runtime.suppressTracking = false;
  };

  const requestConfirm = (message, options = {}) => {
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

  const maybeRestoreDraft = async (formsContainerElement) => {
    const formsContainer = getFormsContainer(formsContainerElement);
    if (!formsContainer || !isEditorVisible(formsContainer)) {
      return;
    }

    const key = refreshActiveDraftKey(formsContainer);
    if (!key || runtime.restorePromptedKey === key) {
      return;
    }
    runtime.restorePromptedKey = key;

    const draftData = readDraft(key);
    if (!draftData || !draftData.snapshot) {
      return;
    }

    const currentSnapshot = collectEditorSnapshot(formsContainer);
    const currentSignature = getSnapshotSignature(currentSnapshot);
    const draftSignature = getSnapshotSignature(draftData.snapshot);

    if (!draftSignature || draftSignature === currentSignature) {
      accessDraftStorage("removeItem", key);
      return;
    }

    const __ = getI18n();
    const restoreMessage = draftData.savedAt
      ? __(
          "A local unsaved draft was found. Do you want to restore it?",
          "gpt3-ai-content-generator"
        )
      : __("Restore local unsaved draft?", "gpt3-ai-content-generator");

    const shouldRestore = await requestConfirm(restoreMessage, {
      title: __("Restore draft", "gpt3-ai-content-generator"),
      confirmText: __("Restore", "gpt3-ai-content-generator"),
      cancelText: __("Discard", "gpt3-ai-content-generator"),
      variant: "warning",
    });

    if (!shouldRestore) {
      accessDraftStorage("removeItem", key);
      return;
    }

    applyDraftSnapshot(formsContainer, draftData.snapshot);
    evaluateDirtyState(formsContainer, { queueSave: true, immediate: true });
    setHeaderStatus(
      formsContainer,
      __("Recovered local draft", "gpt3-ai-content-generator"),
      "warning"
    );
  };

  const requestLeaveDecision = async () => {
    const __ = getI18n();

    const message = __(
      "You have unsaved AI Form changes. Choose what to do before leaving.",
      "gpt3-ai-content-generator"
    );

    if (typeof window.aipkit_showConfirmModal === "function") {
      return new Promise((resolve) => {
        window.aipkit_showConfirmModal(message, {
          title: __("Unsaved changes", "gpt3-ai-content-generator"),
          confirmText: __("Save and leave", "gpt3-ai-content-generator"),
          secondaryText: __("Leave without saving", "gpt3-ai-content-generator"),
          cancelText: __("Stay", "gpt3-ai-content-generator"),
          variant: "warning",
          onConfirm: () => resolve("save"),
          onSecondary: () => resolve("discard"),
          onCancel: () => resolve("stay"),
        });
      });
    }

    const shouldSave = await requestConfirm(message, {
      title: __("Unsaved changes", "gpt3-ai-content-generator"),
      confirmText: __("Save and leave", "gpt3-ai-content-generator"),
      cancelText: __("Leave without saving", "gpt3-ai-content-generator"),
      variant: "warning",
    });

    if (shouldSave) {
      return "save";
    }

    const shouldDiscard = await requestConfirm(
      __(
        "Leave without saving your recent changes?",
        "gpt3-ai-content-generator"
      ),
      {
        title: __("Unsaved changes", "gpt3-ai-content-generator"),
        confirmText: __("Leave without saving", "gpt3-ai-content-generator"),
        cancelText: __("Stay", "gpt3-ai-content-generator"),
        variant: "warning",
      }
    );

    return shouldDiscard ? "discard" : "stay";
  };

  const requestLeaveEditor = async (formsContainerElement, options = {}) => {
    const formsContainer = getFormsContainer(formsContainerElement);
    if (!formsContainer || !isEditorVisible(formsContainer)) {
      return true;
    }

    if (!hasUnsavedChanges(formsContainer)) {
      return true;
    }

    const generation = runtime.editorGeneration;
    const decision = await requestLeaveDecision();
    if (generation !== runtime.editorGeneration) {
      return false;
    }

    if (decision === "stay") {
      return false;
    }

    if (decision === "discard") {
      clearAutosaveTimer();
      runtime.pendingAutosave = false;

      runtime.baselineSignature = getSnapshotSignature(
        collectEditorSnapshot(formsContainer)
      );
      if (options.keepDraftOnDiscard !== true) {
        clearDraftForCurrent(formsContainer);
      }
      return true;
    }

    if (decision === "save") {
      if (typeof window.aipkitForms_saveForm !== "function") {
        return false;
      }
      try {
        const skipFetchListOnSave = options.skipFetchListOnSave === true;
        await window.aipkitForms_saveForm(null, formsContainer, {
          isAutosave: false,
          suppressValidationAlerts: false,
          skipFetchList: skipFetchListOnSave,
        });
        return (
          generation === runtime.editorGeneration &&
          !hasUnsavedChanges(formsContainer)
        );
      } catch (error) {
        return false;
      }
    }

    return false;
  };

  const handleBeforeUnload = (event) => {
    const formsContainer = getFormsContainer(runtime.activeContainer);
    if (!formsContainer || !isEditorVisible(formsContainer)) {
      return;
    }

    if (!hasUnsavedChanges(formsContainer)) {
      return;
    }

    persistDraft(formsContainer);
    event.preventDefault();
    event.returnValue = "";
    return "";
  };

  const handlePageHide = () => {
    const formsContainer = getFormsContainer(runtime.activeContainer);
    if (!formsContainer || !isEditorVisible(formsContainer)) {
      return;
    }

    if (hasUnsavedChanges(formsContainer)) {
      persistDraft(formsContainer);
    }
  };

  const ensureUnloadBindings = () => {
    if (runtime.unloadBound) {
      return;
    }

    window.addEventListener("beforeunload", handleBeforeUnload);
    window.addEventListener("pagehide", handlePageHide);
    runtime.unloadBound = true;
  };

  const bindEditorListeners = (formsContainerElement) => {
    const formsContainer = getFormsContainer(formsContainerElement);
    if (!formsContainer) {
      return;
    }

    if (formsContainer.dataset.aipkitEditorPersistenceBound === "true") {
      runtime.activeContainer = formsContainer;
      ensureUnloadBindings();
      return;
    }

    const mutationHandler = (event) => {
      if (runtime.suppressTracking || !event?.isTrusted) {
        return;
      }

      const target = event.target;
      if (!target || typeof target.closest !== "function") {
        return;
      }

      if (!target.matches("input, select, textarea")) {
        return;
      }

      if (!target.closest("#aipkit_form_editor_container")) {
        return;
      }

      // Settings modals are edited as drafts. Each modal explicitly notifies
      // persistence only when its Save action commits the values.
      if (
        target.closest("#aipkit_ai_form_model_settings_modal") ||
        target.closest("#aipkit_ai_form_knowledge_base_settings_modal") ||
        target.closest("#aipkit_ai_form_web_search_settings_modal")
      ) {
        return;
      }

      if (target.closest(".aipkit_dropped_element")) {
        return;
      }

      if (!isEditorVisible(formsContainer)) {
        return;
      }

      evaluateDirtyState(formsContainer, { queueSave: true, immediate: false });
    };

    formsContainer.addEventListener("input", mutationHandler, true);
    formsContainer.addEventListener("change", mutationHandler, true);

    formsContainer.dataset.aipkitEditorPersistenceBound = "true";
    runtime.activeContainer = formsContainer;
    ensureUnloadBindings();
  };

  const onEditorReady = (formsContainerElement) => {
    const formsContainer = getFormsContainer(formsContainerElement);
    if (!formsContainer) {
      return;
    }

    runtime.activeContainer = formsContainer;
    runtime.editorGeneration += 1;
    runtime.activeDraftKey = "";
    runtime.autosaveInFlight = false;
    runtime.suppressTracking = true;
    clearAutosaveTimer();
    runtime.pendingAutosave = false;
    refreshActiveDraftKey(formsContainer);
    setBaselineFromCurrent(formsContainer, { resetRestorePrompt: true });
    runtime.suppressTracking = false;

    window.aipkitForms_syncPreviewButtonState?.(formsContainer);

    void maybeRestoreDraft(formsContainer);
  };

  const onEditorHidden = (formsContainerElement) => {
    const formsContainer = getFormsContainer(formsContainerElement);
    if (!formsContainer) {
      return;
    }

    runtime.editorGeneration += 1;
    clearAutosaveTimer();
    runtime.pendingAutosave = false;
    runtime.autosaveInFlight = false;

    runtime.baselineSignature = "";
    runtime.restorePromptedKey = "";
    runtime.activeContainer = formsContainer;
  };

  const onFormSaveSuccess = (formsContainerElement, response, { saveState } = {}) => {
    const formsContainer = getFormsContainer(formsContainerElement);
    if (!formsContainer || (saveState && !saveState.isCurrent())) {
      return false;
    }

    refreshActiveDraftKey(formsContainer);
    if (saveState?.snapshot) {
      const snapshot = {
        ...saveState.snapshot,
        controls: { ...saveState.snapshot.controls },
      };
      if (!snapshot.formId && response?.form_id) {
        snapshot.formId = String(response.form_id);
        const idInput = getEditorContainer(formsContainer)?.querySelector(
          "#aipkit_ai_form_id"
        );
        if (idInput?.name) snapshot.controls[idInput.name] = snapshot.formId;
      }
      runtime.baselineSignature = getSnapshotSignature(snapshot);
    } else {
      setBaselineFromCurrent(formsContainer);
    }

    const hasPendingChanges = hasUnsavedChanges(formsContainer);
    runtime.pendingAutosave = hasPendingChanges;
    if (hasPendingChanges) {
      persistDraft(formsContainer);
      if (!runtime.autosaveInFlight) queueAutosave(formsContainer, true);
    } else {
      clearDraftForCurrent(formsContainer);
    }
    window.aipkitForms_syncPreviewButtonState?.(formsContainer);
    window.aipkitForms_setEditorExitState?.(
      formsContainer,
      hasPendingChanges ? "cancel" : "close"
    );
    return hasPendingChanges;
  };

  const notifyEditorMutation = (formsContainerElement, options = {}) => {
    const formsContainer = getFormsContainer(formsContainerElement);
    if (!formsContainer || runtime.suppressTracking || !isEditorVisible(formsContainer)) {
      return;
    }

    evaluateDirtyState(formsContainer, {
      queueSave: options.queueSave !== false,
      immediate: options.immediate === true,
    });
  };

  const runWithLeaveGuard = async (
    formsContainerElement,
    proceedCallback,
    options = {}
  ) => {
    const formsContainer = getFormsContainer(formsContainerElement);
    const canProceed = await requestLeaveEditor(formsContainer, options);
    if (!canProceed) {
      return false;
    }

    if (typeof proceedCallback === "function") {
      proceedCallback();
    }
    return true;
  };

  const aiFormsBeforeModuleChange = async (targetModuleName) => {
    const formsContainer = document.getElementById("aipkit_ai_forms_container");
    if (!formsContainer) {
      return true;
    }

    if (targetModuleName === "ai-forms") {
      return true;
    }

    if (!isEditorVisible(formsContainer)) {
      return true;
    }

    return requestLeaveEditor(formsContainer, {
      keepDraftOnDiscard: false,
      skipFetchListOnSave: true,
    });
  };

  const previousBeforeModuleChange =
    typeof window.aipkit_beforeModuleChange === "function"
      ? window.aipkit_beforeModuleChange
      : null;

  window.aipkit_beforeModuleChange = async function (targetModuleName) {
    if (previousBeforeModuleChange) {
      const previousResult = await previousBeforeModuleChange(targetModuleName);
      if (previousResult === false) {
        return false;
      }
    }
    return aiFormsBeforeModuleChange(targetModuleName);
  };

  window.aipkitForms_captureSaveState = captureSaveState;
  window.aipkitForms_initEditorPersistence = bindEditorListeners;
  window.aipkitForms_onEditorReady = onEditorReady;
  window.aipkitForms_onEditorHidden = onEditorHidden;
  window.aipkitForms_onFormSaveSuccess = onFormSaveSuccess;
  window.aipkitForms_notifyEditorMutation = notifyEditorMutation;
  window.aipkitForms_runWithLeaveGuard = runWithLeaveGuard;
})();
