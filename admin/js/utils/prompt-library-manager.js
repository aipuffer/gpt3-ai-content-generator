/**
 * Shared Prompt Library Manager modal.
 */
(function () {
  "use strict";

  const TYPE_LABEL_OVERRIDES = {
    meta: "Meta Description",
    keyword: "Focus Keyword",
    reply: "Comment Reply",
    featured_image: "Featured Image",
    image_alt_text: "Image Alt Text",
    image_caption: "Image Caption",
    image_description: "Image Description",
    image_title: "Image Title",
    image_title_update: "Image Title (Update)",
    image_alt_text_update: "Image Alt Text (Update)",
    image_caption_update: "Image Caption (Update)",
    image_description_update: "Image Description (Update)",
  };

  const state = {
    isOpen: false,
    isBusy: false,
    library: {},
    types: [],
    selectedType: "",
    selectedPromptId: "",
    pendingDraft: null,
    lastFocusedElement: null,
    scrollPosition: null,
    bodyScrollLockStyles: null,
  };

  const refs = {
    modal: null,
    typeSelect: null,
    list: null,
    emptyState: null,
    nameInput: null,
    promptTextarea: null,
    status: null,
    newButton: null,
    duplicateButton: null,
    saveButton: null,
    deleteButton: null,
    closeButton: null,
  };

  const stripCustomSuffix = (label) =>
    String(label || "")
      .replace(/\s+\(Custom\)\s*$/i, "")
      .trim();

  const toTypeLabel = (promptType) => {
    const normalized = String(promptType || "").trim();
    if (!normalized) {
      return "";
    }
    if (TYPE_LABEL_OVERRIDES[normalized]) {
      return TYPE_LABEL_OVERRIDES[normalized];
    }
    return normalized
      .split("_")
      .filter(Boolean)
      .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
      .join(" ");
  };

  const getPromptLibraryApi = () => window.aipkit_prompt_library_api || null;

  const getEntriesForType = (promptType) => {
    if (!promptType || !state.library || !Array.isArray(state.library[promptType])) {
      return [];
    }
    return state.library[promptType];
  };

  const getSelectedEntry = () => {
    if (!state.selectedType || !state.selectedPromptId) {
      return null;
    }
    return (
      getEntriesForType(state.selectedType).find(
        (entry) => String(entry?.id || "") === state.selectedPromptId
      ) || null
    );
  };

  const getEntrySource = (entry) =>
    String(entry?.source || (entry?.is_builtin ? "builtin" : "custom"));

  const setStatus = (message, isError = false) => {
    if (!refs.status) {
      return;
    }
    refs.status.textContent = message ? String(message) : "";
    refs.status.classList.toggle("is-error", !!isError);
  };

  const clearEditor = () => {
    if (refs.nameInput) {
      refs.nameInput.value = "";
    }
    if (refs.promptTextarea) {
      refs.promptTextarea.value = "";
    }
    setStatus("");
  };

  const setEditorFromEntry = (entry) => {
    if (!entry) {
      return;
    }
    if (refs.nameInput) {
      refs.nameInput.value = stripCustomSuffix(entry.label || "");
    }
    if (refs.promptTextarea) {
      refs.promptTextarea.value = String(entry.prompt || "");
    }
  };

  const renderTypeOptions = () => {
    if (!refs.typeSelect) {
      return;
    }
    refs.typeSelect.innerHTML = "";

    if (!state.types.length) {
      const emptyOption = document.createElement("option");
      emptyOption.value = "";
      emptyOption.textContent = "No prompt types";
      refs.typeSelect.appendChild(emptyOption);
      refs.typeSelect.disabled = true;
      return;
    }

    state.types.forEach((promptType) => {
      const option = document.createElement("option");
      option.value = promptType;
      option.textContent = toTypeLabel(promptType);
      refs.typeSelect.appendChild(option);
    });

    if (!state.selectedType || !state.types.includes(state.selectedType)) {
      state.selectedType = state.types[0];
    }

    refs.typeSelect.disabled = false;
    refs.typeSelect.value = state.selectedType;
  };

  const renderList = () => {
    if (!refs.list || !refs.emptyState) {
      return;
    }

    const entries = getEntriesForType(state.selectedType);
    refs.list.innerHTML = "";

    if (!entries.length) {
      refs.emptyState.textContent = "No prompts available for this type yet.";
      refs.emptyState.hidden = false;
      return;
    }

    refs.emptyState.hidden = true;
    entries.forEach((entry) => {
      const promptId = String(entry?.id || "");
      const source = getEntrySource(entry);
      const promptPreview = String(entry?.prompt || "")
        .replace(/\s+/g, " ")
        .trim();
      const item = document.createElement("button");
      item.type = "button";
      item.className = "aipkit_prompt_library_entry";
      if (promptId && promptId === state.selectedPromptId) {
        item.classList.add("is-active");
      }
      item.dataset.promptId = promptId;
      item.dataset.promptType = String(entry?.type || state.selectedType || "");

      const content = document.createElement("span");
      content.className = "aipkit_prompt_library_entry_content";

      const title = document.createElement("span");
      title.className = "aipkit_prompt_library_entry_title";
      title.textContent = String(entry?.label || "Untitled prompt");

      const preview = document.createElement("span");
      preview.className = "aipkit_prompt_library_entry_preview";
      preview.textContent =
        promptPreview || "No prompt text saved for this preset yet.";

      const badge = document.createElement("span");
      badge.className = "aipkit_prompt_library_entry_badge";
      badge.textContent = source === "custom" ? "Saved" : "Default";

      content.appendChild(title);
      content.appendChild(preview);
      item.appendChild(content);
      item.appendChild(badge);
      refs.list.appendChild(item);
    });
  };

  const getEditorSnapshot = () => ({
    label: String(refs.nameInput?.value || "").trim(),
    promptText: String(refs.promptTextarea?.value || "").trim(),
  });

  const getBaselineSnapshot = () => {
    const selectedEntry = getSelectedEntry();
    if (!selectedEntry) {
      return {
        label: "",
        promptText: "",
      };
    }
    return {
      label: stripCustomSuffix(selectedEntry.label || ""),
      promptText: String(selectedEntry.prompt || "").trim(),
    };
  };

  const hasEditorChanges = () => {
    const current = getEditorSnapshot();
    const baseline = getBaselineSnapshot();
    return (
      current.label !== baseline.label || current.promptText !== baseline.promptText
    );
  };

  const buildDuplicateLabel = (label) => {
    const baseLabel = stripCustomSuffix(label || "");
    if (!baseLabel) {
      return "Untitled Copy";
    }
    return /\bcopy\b$/i.test(baseLabel) ? baseLabel : `${baseLabel} Copy`;
  };

  const updateButtonStates = () => {
    const selectedEntry = getSelectedEntry();
    const hasType = !!state.selectedType;
    const nameValue = String(refs.nameInput?.value || "").trim();
    const promptValue = String(refs.promptTextarea?.value || "").trim();
    const hasValues = nameValue !== "" && promptValue !== "";
    const isDirty = hasEditorChanges();
    const hasSelectedEntry = !!selectedEntry;
    const isCustomSelected =
      !!selectedEntry && getEntrySource(selectedEntry) === "custom";

    if (refs.newButton) {
      refs.newButton.disabled = state.isBusy || !hasType;
    }
    if (refs.duplicateButton) {
      refs.duplicateButton.disabled = state.isBusy || !hasSelectedEntry;
    }
    if (refs.saveButton) {
      refs.saveButton.disabled = state.isBusy || !hasType || !hasValues || !isDirty;
      refs.saveButton.textContent = "Save";
    }
    if (refs.deleteButton) {
      refs.deleteButton.disabled = state.isBusy || !isCustomSelected;
    }
    if (refs.typeSelect) {
      refs.typeSelect.disabled = state.isBusy || !state.types.length;
    }
    if (refs.nameInput) {
      refs.nameInput.disabled = state.isBusy || !hasType;
    }
    if (refs.promptTextarea) {
      refs.promptTextarea.disabled = state.isBusy || !hasType;
    }
  };

  const selectEntryById = (promptId, promptType = "") => {
    const targetType = promptType || state.selectedType;
    const targetId = String(promptId || "");
    if (!targetType || !targetId) {
      return false;
    }

    const entry = getEntriesForType(targetType).find(
      (item) => String(item?.id || "") === targetId
    );
    if (!entry) {
      return false;
    }

    state.selectedType = targetType;
    state.selectedPromptId = targetId;
    renderTypeOptions();
    renderList();
    setEditorFromEntry(entry);
    setStatus("");
    updateButtonStates();
    window.requestAnimationFrame(() => {
      if (!refs.list || !state.selectedPromptId || typeof CSS === "undefined") {
        return;
      }
      const activeEntry = refs.list.querySelector(
        `.aipkit_prompt_library_entry[data-prompt-id="${CSS.escape(
          state.selectedPromptId
        )}"]`
      );
      if (activeEntry && typeof activeEntry.scrollIntoView === "function") {
        activeEntry.scrollIntoView({ block: "nearest" });
      }
    });
    return true;
  };

  const selectFirstEntryOrReset = () => {
    const entries = getEntriesForType(state.selectedType);
    if (entries.length) {
      const firstEntryId = String(entries[0]?.id || "");
      if (firstEntryId) {
        selectEntryById(firstEntryId, state.selectedType);
        return;
      }
    }

    state.selectedPromptId = "";
    renderList();
    clearEditor();
    updateButtonStates();
  };

  const beginNewDraft = () => {
    state.selectedPromptId = "";
    renderList();
    clearEditor();
    updateButtonStates();
    refs.nameInput?.focus();
  };

  const beginDuplicateDraft = () => {
    const selectedEntry = getSelectedEntry();
    if (!selectedEntry) {
      return;
    }

    const current = getEditorSnapshot();
    state.selectedPromptId = "";
    renderList();

    if (refs.nameInput) {
      refs.nameInput.value = buildDuplicateLabel(current.label || selectedEntry.label || "");
    }
    if (refs.promptTextarea) {
      refs.promptTextarea.value = current.promptText || String(selectedEntry.prompt || "");
    }

    setStatus("Duplicate ready. Click Save when you're done.");
    updateButtonStates();
    refs.nameInput?.focus();
    refs.nameInput?.select?.();
  };

  const setBusy = (busy) => {
    state.isBusy = !!busy;
    if (refs.modal) {
      refs.modal.classList.toggle("is-busy", state.isBusy);
    }
    updateButtonStates();
  };

  const readEditorValues = () => {
    return {
      promptType: String(state.selectedType || "").trim(),
      label: String(refs.nameInput?.value || "").trim(),
      promptText: String(refs.promptTextarea?.value || "").trim(),
    };
  };

  const refreshLibrary = async (force = false) => {
    const api = getPromptLibraryApi();
    if (!api) {
      throw new Error("Prompt library API is not available.");
    }

    const data = await api.list({ force });
    state.library = data?.library && typeof data.library === "object" ? data.library : {};
    state.types = Array.isArray(data?.types)
      ? data.types.map((item) => String(item || "").trim()).filter(Boolean)
      : Object.keys(state.library);

    if (!state.selectedType || !state.types.includes(state.selectedType)) {
      state.selectedType = state.types[0] || "";
    }

    if (state.selectedPromptId) {
      const stillExists = getEntriesForType(state.selectedType).some(
        (entry) => String(entry?.id || "") === state.selectedPromptId
      );
      if (!stillExists) {
        state.selectedPromptId = "";
      }
    }

    renderTypeOptions();
    renderList();
    updateButtonStates();
  };

  const applyPendingDraft = () => {
    const draft = state.pendingDraft;
    state.pendingDraft = null;
    setStatus("");

    if (!draft) {
      selectFirstEntryOrReset();
      return;
    }

    const requestedType = String(draft.promptType || "").trim();
    if (requestedType && state.types.includes(requestedType)) {
      state.selectedType = requestedType;
    }

    renderTypeOptions();
    renderList();

    const draftLabel = stripCustomSuffix(draft.label || "");
    const draftPrompt = String(draft.promptText || "").trim();
    const draftId = String(draft.promptId || "").trim();
    if (draftId && selectEntryById(draftId, state.selectedType)) {
      if (draftLabel && refs.nameInput) {
        refs.nameInput.value = draftLabel;
      }
      if (draftPrompt && refs.promptTextarea) {
        refs.promptTextarea.value = draftPrompt;
      }
      updateButtonStates();
      return;
    }

    if (draftLabel || draftPrompt) {
      state.selectedPromptId = "";
      if (refs.nameInput) {
        refs.nameInput.value = draftLabel;
      }
      if (refs.promptTextarea) {
        refs.promptTextarea.value = draftPrompt;
      }
      renderList();
      updateButtonStates();
      return;
    }

    selectFirstEntryOrReset();
  };

  const closeManager = () => {
    if (!refs.modal || !state.isOpen) {
      return;
    }
    refs.modal.classList.remove("is-open");
    refs.modal.setAttribute("aria-hidden", "true");
    state.isOpen = false;
    setBusy(false);

    document.body.classList.remove("aipkit_prompt_library_open");
    document.documentElement.classList.remove("aipkit_prompt_library_open");

    const scrollPosition = state.scrollPosition;
    const bodyScrollLockStyles = state.bodyScrollLockStyles;
    if (bodyScrollLockStyles) {
      document.body.style.position = bodyScrollLockStyles.position;
      document.body.style.top = bodyScrollLockStyles.top;
      document.body.style.left = bodyScrollLockStyles.left;
      document.body.style.width = bodyScrollLockStyles.width;
    }
    if (scrollPosition) {
      window.scrollTo(scrollPosition.left, scrollPosition.top);
    }
    if (state.lastFocusedElement && typeof state.lastFocusedElement.focus === "function") {
      window.requestAnimationFrame(() => {
        state.lastFocusedElement.focus({ preventScroll: true });
        if (scrollPosition) {
          window.scrollTo(scrollPosition.left, scrollPosition.top);
        }
      });
    }
    state.scrollPosition = null;
    state.bodyScrollLockStyles = null;
  };

  const handleSave = async () => {
    const api = getPromptLibraryApi();
    if (!api) {
      window.alert("Prompt library API is not available.");
      return;
    }

    const { promptType, label, promptText } = readEditorValues();
    if (!promptType || !label || !promptText) {
      setStatus("Prompt type, name, and prompt text are required.", true);
      return;
    }

    const selectedEntry = getSelectedEntry();
    const shouldUpdate =
      !!selectedEntry && getEntrySource(selectedEntry) === "custom";

    setBusy(true);
    setStatus(shouldUpdate ? "Saving changes..." : "Saving preset...");
    try {
      let savedId = "";
      let savedType = promptType;

      if (shouldUpdate) {
        await api.update({
          promptId: String(selectedEntry.id || ""),
          promptType,
          label,
          prompt: promptText,
        });
        savedId = String(selectedEntry.id || "");
      } else {
        const result = await api.create({
          promptType,
          label,
          prompt: promptText,
        });
        savedId = String(result?.item?.id || "");
        savedType = String(result?.item?.type || promptType);
      }

      await refreshLibrary(true);
      if (savedId) {
        selectEntryById(savedId, savedType);
      } else {
        selectFirstEntryOrReset();
      }
      setStatus(shouldUpdate ? "Preset updated." : "New preset saved.");
    } catch (error) {
      const message =
        error && typeof error.message === "string" && error.message.trim()
          ? error.message.trim()
          : "Could not save the prompt preset.";
      setStatus(message, true);
    } finally {
      setBusy(false);
    }
  };

  const handleDelete = async () => {
    const api = getPromptLibraryApi();
    if (!api) {
      window.alert("Prompt library API is not available.");
      return;
    }

    const selectedEntry = getSelectedEntry();
    if (!selectedEntry || getEntrySource(selectedEntry) !== "custom") {
      setStatus("Select a custom preset to delete.", true);
      return;
    }

    const confirmed = window.confirm("Delete the selected custom preset?");
    if (!confirmed) {
      return;
    }

    const promptId = String(selectedEntry.id || "");
    const promptType = String(state.selectedType || "");

    setBusy(true);
    setStatus("Deleting preset...");
    try {
      await api.remove({ promptId, promptType });
      state.selectedPromptId = "";
      await refreshLibrary(true);
      selectFirstEntryOrReset();
      setStatus("Prompt preset deleted.");
    } catch (error) {
      const message =
        error && typeof error.message === "string" && error.message.trim()
          ? error.message.trim()
          : "Could not delete the prompt preset.";
      setStatus(message, true);
    } finally {
      setBusy(false);
    }
  };

  const ensureModal = () => {
    if (refs.modal) {
      return;
    }

    const existing = document.getElementById("aipkit_prompt_library_modal");
    if (existing) {
      refs.modal = existing;
    } else {
      const wrapper = document.createElement("div");
      wrapper.id = "aipkit_prompt_library_modal";
      wrapper.className = "aipkit_prompt_library_modal";
      wrapper.setAttribute("aria-hidden", "true");
      wrapper.innerHTML = `
        <div class="aipkit_prompt_library_backdrop" data-aipkit-close-prompt-library="true"></div>
        <div class="aipkit_prompt_library_panel" role="dialog" aria-modal="true" aria-labelledby="aipkit_prompt_library_title">
          <div class="aipkit_prompt_library_header">
            <div class="aipkit_prompt_library_heading">
              <h2 id="aipkit_prompt_library_title">Prompt Library</h2>
              <p>Choose a preset or save your own version.</p>
            </div>
            <button type="button" class="aipkit_prompt_library_close" aria-label="Close prompt library">&times;</button>
          </div>
          <div class="aipkit_prompt_library_content">
            <aside class="aipkit_prompt_library_sidebar">
              <div class="aipkit_prompt_library_sidebar_head">
                <label class="aipkit_prompt_library_field">
                  <span>Prompt Type</span>
                  <select id="aipkit_prompt_library_type_select" name="prompt_type" class="aipkit_prompt_library_type_select"></select>
                </label>
              </div>
              <div class="aipkit_prompt_library_list_wrap">
                <div class="aipkit_prompt_library_list" role="list"></div>
                <p class="aipkit_prompt_library_empty"></p>
              </div>
            </aside>
            <section class="aipkit_prompt_library_editor_panel">
              <div class="aipkit_prompt_library_editor_head">
                <h3>Preset Editor</h3>
                <p>Edit the selected preset or create a new one.</p>
              </div>
              <div class="aipkit_prompt_library_editor">
                <label class="aipkit_prompt_library_field">
                  <span>Name</span>
                  <input id="aipkit_prompt_library_name" name="prompt_label" type="text" class="aipkit_prompt_library_name" maxlength="180" placeholder="Prompt name">
                </label>
                <label class="aipkit_prompt_library_field aipkit_prompt_library_field--grow">
                  <span>Prompt</span>
                  <textarea id="aipkit_prompt_library_prompt" name="prompt_text" class="aipkit_prompt_library_prompt" rows="12" placeholder="Write your prompt"></textarea>
                </label>
              </div>
            </section>
          </div>
          <div class="aipkit_prompt_library_footer">
            <div class="aipkit_prompt_library_status" role="status" aria-live="polite"></div>
            <div class="aipkit_prompt_library_footer_actions">
              <button type="button" class="aipkit_btn aipkit_btn-ghost aipkit_btn-small aipkit_prompt_library_new_btn">New</button>
              <button type="button" class="aipkit_btn aipkit_btn-ghost aipkit_btn-small aipkit_prompt_library_duplicate_btn">Duplicate</button>
              <button type="button" class="aipkit_btn aipkit_btn-primary aipkit_btn-small aipkit_prompt_library_save_btn">Save</button>
              <button type="button" class="aipkit_btn aipkit_btn-danger aipkit_btn-small aipkit_prompt_library_delete_btn">Delete</button>
            </div>
          </div>
        </div>
      `;
      document.body.appendChild(wrapper);
      refs.modal = wrapper;
    }

    refs.typeSelect = refs.modal.querySelector(".aipkit_prompt_library_type_select");
    refs.list = refs.modal.querySelector(".aipkit_prompt_library_list");
    refs.emptyState = refs.modal.querySelector(".aipkit_prompt_library_empty");
    refs.nameInput = refs.modal.querySelector(".aipkit_prompt_library_name");
    refs.promptTextarea = refs.modal.querySelector(".aipkit_prompt_library_prompt");
    refs.status = refs.modal.querySelector(".aipkit_prompt_library_status");
    refs.newButton = refs.modal.querySelector(".aipkit_prompt_library_new_btn");
    refs.duplicateButton = refs.modal.querySelector(".aipkit_prompt_library_duplicate_btn");
    refs.saveButton = refs.modal.querySelector(".aipkit_prompt_library_save_btn");
    refs.deleteButton = refs.modal.querySelector(".aipkit_prompt_library_delete_btn");
    refs.closeButton = refs.modal.querySelector(".aipkit_prompt_library_close");

    refs.typeSelect?.addEventListener("change", () => {
      state.selectedType = String(refs.typeSelect.value || "");
      state.selectedPromptId = "";
      renderList();
      clearEditor();
      updateButtonStates();
    });

    refs.list?.addEventListener("click", (event) => {
      if (!(event.target instanceof Element)) {
        return;
      }
      const entryButton = event.target.closest(".aipkit_prompt_library_entry");
      if (!entryButton) {
        return;
      }

      const promptId = String(entryButton.dataset.promptId || "");
      const promptType = String(entryButton.dataset.promptType || state.selectedType || "");
      selectEntryById(promptId, promptType);
    });

    refs.newButton?.addEventListener("click", () => {
      beginNewDraft();
    });

    refs.duplicateButton?.addEventListener("click", () => {
      beginDuplicateDraft();
    });

    refs.nameInput?.addEventListener("input", () => {
      setStatus("");
      updateButtonStates();
    });

    refs.promptTextarea?.addEventListener("input", () => {
      setStatus("");
      updateButtonStates();
    });

    refs.saveButton?.addEventListener("click", () => {
      handleSave();
    });

    refs.deleteButton?.addEventListener("click", () => {
      handleDelete();
    });

    refs.closeButton?.addEventListener("click", (event) => {
      event.preventDefault();
      closeManager();
    });

    refs.modal
      .querySelector('[data-aipkit-close-prompt-library="true"]')
      ?.addEventListener("click", () => {
        closeManager();
      });

    refs.modal.addEventListener("click", (event) => {
      if (event.target === refs.modal) {
        closeManager();
      }
    });

    if (document.body.dataset.aipkitPromptLibraryEscapeAttached !== "true") {
      document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && state.isOpen) {
          closeManager();
        }
      });
      document.body.dataset.aipkitPromptLibraryEscapeAttached = "true";
    }
  };

  const openManager = async (options = {}) => {
    const api = getPromptLibraryApi();
    if (!api) {
      window.alert("Prompt library API is not available on this page.");
      return;
    }

    ensureModal();

    state.pendingDraft = {
      promptType: String(options.promptType || "").trim(),
      promptId: String(options.promptId || "").trim(),
      label: stripCustomSuffix(String(options.label || "")),
      promptText: String(options.promptText || ""),
    };
    state.selectedPromptId = "";

    state.lastFocusedElement = document.activeElement;
    state.scrollPosition = {
      left: window.scrollX,
      top: window.scrollY,
    };
    state.bodyScrollLockStyles = {
      position: document.body.style.position,
      top: document.body.style.top,
      left: document.body.style.left,
      width: document.body.style.width,
    };
    document.body.style.position = "fixed";
    document.body.style.top = `-${state.scrollPosition.top}px`;
    document.body.style.left = `-${state.scrollPosition.left}px`;
    document.body.style.width = "100%";
    refs.modal.classList.add("is-open");
    refs.modal.setAttribute("aria-hidden", "false");
    state.isOpen = true;
    document.documentElement.classList.add("aipkit_prompt_library_open");
    document.body.classList.add("aipkit_prompt_library_open");

    setBusy(true);
    setStatus("Loading prompt library...");

    try {
      await refreshLibrary(true);
      applyPendingDraft();
      if (refs.nameInput && !refs.nameInput.value) {
        refs.nameInput?.focus({ preventScroll: true });
      } else {
        refs.promptTextarea?.focus({ preventScroll: true });
      }
    } catch (error) {
      const message =
        error && typeof error.message === "string" && error.message.trim()
          ? error.message.trim()
          : "Could not load the prompt library.";
      setStatus(message, true);
    } finally {
      setBusy(false);
    }
  };

  window.aipkit_openPromptLibraryManager = openManager;

  if (document.body && document.body.dataset.aipkitPromptLibraryTriggerAttached !== "true") {
    document.addEventListener("click", (event) => {
      if (!(event.target instanceof Element)) {
        return;
      }
      const trigger = event.target.closest("[data-aipkit-open-prompt-library]");
      if (!trigger) {
        return;
      }
      event.preventDefault();
      openManager({
        promptType: trigger.getAttribute("data-aipkit-prompt-type") || "",
        label: trigger.getAttribute("data-aipkit-prompt-label") || "",
        promptText: trigger.getAttribute("data-aipkit-prompt-value") || "",
      });
    });
    document.body.dataset.aipkitPromptLibraryTriggerAttached = "true";
  }

  window.addEventListener("aipkit_prompt_library_updated", (event) => {
    if (!state.isOpen) {
      return;
    }
    const detail = event?.detail || {};
    const message = String(detail?.action || "")
      ? `Prompt library updated (${detail.action}).`
      : "Prompt library updated.";
    setStatus(message);
    refreshLibrary(true)
      .then(() => {
        if (state.selectedPromptId) {
          selectEntryById(state.selectedPromptId, state.selectedType);
        } else {
          renderList();
          updateButtonStates();
        }
      })
      .catch(() => {
        setStatus("Prompt library changed, but refresh failed.", true);
      });
  });
})();
