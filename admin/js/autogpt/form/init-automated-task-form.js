/**
 * AIPKit Automated Tasks - Form Initialization
 * Initializes the main task form and its general event listeners.
 */
(function () {
  "use strict";

  const promptDraftStorageKey = "aipkit_autogpt_prompt_drafts_v1";
  const promptDraftMaxAge = 7 * 24 * 60 * 60 * 1000;
  const promptDraftFieldMap = {
    content_writing: [
      "aipkit_task_cw_custom_title_prompt",
      "aipkit_task_cw_custom_content_prompt",
      "aipkit_task_cw_custom_meta_prompt",
      "aipkit_task_cw_custom_keyword_prompt",
      "aipkit_task_cw_custom_excerpt_prompt",
      "aipkit_task_cw_custom_tags_prompt",
      "aipkit_task_cw_image_prompt",
      "aipkit_task_cw_featured_image_prompt",
      "aipkit_task_cw_generate_meta_desc",
      "aipkit_task_cw_generate_focus_keyword",
      "aipkit_task_cw_generate_excerpt",
      "aipkit_task_cw_generate_tags",
    ],
    enhance_existing_content: [
      "aipkit_task_ce_title_prompt",
      "aipkit_task_ce_excerpt_prompt",
      "aipkit_task_ce_content_prompt",
      "aipkit_task_ce_meta_prompt",
      "aipkit_task_ce_update_title",
      "aipkit_task_ce_update_excerpt",
      "aipkit_task_ce_update_content",
      "aipkit_task_ce_update_meta",
    ],
    community_reply_comments: ["aipkit_task_cc_custom_content_prompt"],
  };
  const promptDraftFieldIdSet = new Set(
    Object.values(promptDraftFieldMap).flat()
  );

  const getPromptDraftType = (taskType) => {
    const normalized = String(taskType || "");
    if (normalized.startsWith("content_writing")) {
      return "content_writing";
    }
    if (normalized === "enhance_existing_content") {
      return "enhance_existing_content";
    }
    if (normalized === "community_reply_comments") {
      return "community_reply_comments";
    }
    return "";
  };

  const loadPromptDraftStore = () => {
    try {
      const raw = localStorage.getItem(promptDraftStorageKey);
      if (!raw) {
        return {};
      }
      const parsed = JSON.parse(raw);
      return parsed && typeof parsed === "object" ? parsed : {};
    } catch (error) {
      console.warn("AutoGPT: failed to load prompt drafts.", error);
      return {};
    }
  };

  const savePromptDraftStore = (store) => {
    try {
      if (!store || !Object.keys(store).length) {
        localStorage.removeItem(promptDraftStorageKey);
        return;
      }
      localStorage.setItem(promptDraftStorageKey, JSON.stringify(store));
    } catch (error) {
      console.warn("AutoGPT: failed to save prompt drafts.", error);
    }
  };

  const collectPromptDraftValues = (form, draftType) => {
    const fieldIds = promptDraftFieldMap[draftType] || [];
    const values = {};
    let hasChanges = false;
    fieldIds.forEach((fieldId) => {
      const field = form.querySelector(`#${fieldId}`);
      if (!field) {
        return;
      }
      if (field.tagName === "SELECT" && field.multiple) {
        const selectedValues = Array.from(field.selectedOptions).map(
          (option) => option.value
        );
        values[fieldId] = selectedValues;
        const defaultSelected = Array.from(field.options)
          .filter((option) => option.defaultSelected)
          .map((option) => option.value);
        if (selectedValues.join("|") !== defaultSelected.join("|")) {
          hasChanges = true;
        }
        return;
      }
      if (field.type === "checkbox") {
        values[fieldId] = !!field.checked;
        if (!!field.checked !== !!field.defaultChecked) {
          hasChanges = true;
        }
        return;
      }
      values[fieldId] = field.value;
      if (String(field.value || "") !== String(field.defaultValue || "")) {
        hasChanges = true;
      }
    });
    return { values, hasChanges };
  };

  const saveActivePromptDraft = (form) => {
    if (!form) {
      return;
    }
    const taskId = String(form.elements["task_id"]?.value || "").trim();
    if (taskId) {
      return;
    }
    const taskType = form.elements["task_type"]?.value || "";
    const draftType = getPromptDraftType(taskType);
    if (!draftType) {
      return;
    }

    const store = loadPromptDraftStore();
    const { values, hasChanges } = collectPromptDraftValues(form, draftType);
    if (!hasChanges) {
      delete store[draftType];
      savePromptDraftStore(store);
      return;
    }

    store[draftType] = {
      updatedAt: Date.now(),
      taskType: String(taskType || ""),
      values,
    };
    savePromptDraftStore(store);
  };

  function aipkit_restoreAutogptPromptDraft(options = {}) {
    const form = document.getElementById("aipkit_automated_task_form");
    if (!form) {
      return false;
    }
    const { isEditing = false, silent = false } = options;
    if (isEditing || String(form.elements["task_id"]?.value || "").trim()) {
      return false;
    }

    const taskType = form.elements["task_type"]?.value || "";
    const draftType = getPromptDraftType(taskType);
    if (!draftType) {
      return false;
    }

    const store = loadPromptDraftStore();
    const draft = store[draftType];
    if (!draft || typeof draft !== "object") {
      return false;
    }
    if (
      typeof draft.updatedAt !== "number" ||
      Date.now() - draft.updatedAt > promptDraftMaxAge
    ) {
      delete store[draftType];
      savePromptDraftStore(store);
      return false;
    }

    const draftTaskType = String(draft.taskType || "");
    if (
      draftType === "content_writing" &&
      draftTaskType &&
      !draftTaskType.startsWith("content_writing")
    ) {
      return false;
    }
    if (
      draftType !== "content_writing" &&
      draftTaskType &&
      draftTaskType !== taskType
    ) {
      return false;
    }

    let restored = false;
    Object.entries(draft.values || {}).forEach(([fieldId, fieldValue]) => {
      const field = form.querySelector(`#${fieldId}`);
      if (!field) {
        return;
      }

      if (field.tagName === "SELECT" && field.multiple && Array.isArray(fieldValue)) {
        const selected = new Set(fieldValue.map(String));
        Array.from(field.options).forEach((option) => {
          option.selected = selected.has(String(option.value));
        });
        field.dispatchEvent(new Event("change", { bubbles: true }));
        restored = true;
        return;
      }

      if (field.type === "checkbox") {
        field.checked = !!fieldValue;
        field.dispatchEvent(new Event("change", { bubbles: true }));
        restored = true;
        return;
      }

      field.value = String(fieldValue || "");
      field.dispatchEvent(new Event("input", { bubbles: true }));
      restored = true;
    });

    if (
      restored &&
      !silent &&
      typeof window.aipkit_form_showAutomatedTaskFormStatus === "function"
    ) {
      window.aipkit_form_showAutomatedTaskFormStatus(
        "Prompt draft restored.",
        "success"
      );
    }

    return restored;
  }

  function aipkit_clearActiveAutogptPromptDraft() {
    const form = document.getElementById("aipkit_automated_task_form");
    if (!form) {
      return;
    }
    const taskType = form.elements["task_type"]?.value || "";
    const draftType = getPromptDraftType(taskType);
    if (!draftType) {
      return;
    }
    const store = loadPromptDraftStore();
    if (!Object.prototype.hasOwnProperty.call(store, draftType)) {
      return;
    }
    delete store[draftType];
    savePromptDraftStore(store);
  }

  function aipkit_form_initAutomatedTaskForm() {
    const form = document.getElementById("aipkit_automated_task_form");
    const statusToggle = document.getElementById(
      "aipkit_autogpt_task_status_toggle"
    );
    const statusInput = document.getElementById(
      "aipkit_autogpt_task_status_input"
    );
    const scheduleCard = document.getElementById("aipkit_autogpt_schedule_card");
    const taskCategorySelect = document.getElementById(
      "aipkit_automated_task_category"
    );
    const categorySelector = document.querySelector(
      "[data-aipkit-autogpt-category-selector]"
    );
    const categoryCards = categorySelector
      ? Array.from(categorySelector.querySelectorAll(".aipkit_cw_mode_card"))
      : [];
    const taskTypeSelect = document.getElementById(
      "aipkit_automated_task_type"
    );
    const cancelEditButton = document.getElementById(
      "aipkit_cancel_edit_task_btn"
    );
    const formWrapper = document.getElementById(
      "aipkit_automated_task_form_wrapper"
    );
    const taskListWrapper = document.getElementById(
      "aipkit_automated_task_list_wrapper"
    );
    const addTaskButton = document.getElementById("aipkit_add_new_task_btn");
    const leftStage = document.querySelector(
      "#aipkit_autogpt_container .aipkit_autogpt_form_left"
    );
    const mainStage = document.querySelector(
      "#aipkit_autogpt_container .aipkit_autogpt_form_main"
    );

    const syncStageHeights = () => {
      if (!leftStage || !mainStage) {
        return;
      }

      if (
        window.innerWidth <= 1200 ||
        leftStage.offsetParent === null ||
        mainStage.offsetParent === null
      ) {
        mainStage.style.minHeight = "";
        return;
      }

      const leftHeight = Math.ceil(leftStage.getBoundingClientRect().height);
      if (leftHeight > 0) {
        mainStage.style.minHeight = `${leftHeight}px`;
      }
    };

    let stageSyncFrame = null;
    const scheduleStageHeightSync = () => {
      if (stageSyncFrame !== null) {
        cancelAnimationFrame(stageSyncFrame);
      }
      stageSyncFrame = requestAnimationFrame(() => {
        stageSyncFrame = null;
        syncStageHeights();
      });
    };

    window.aipkit_syncAutogptStageHeights = scheduleStageHeightSync;

    if (form && typeof window.aipkit_form_handleSaveTask === "function") {
      form.addEventListener("submit", window.aipkit_form_handleSaveTask);
    } else if (form) {
      console.error(
        "Automated Task Form Init: handleSaveTask function not found."
      );
    }

    if (
      taskCategorySelect &&
      typeof window.aipkit_form_handleCategoryChange === "function"
    ) {
      taskCategorySelect.addEventListener(
        "change",
        window.aipkit_form_handleCategoryChange
      );
    } else if (taskCategorySelect) {
      console.error(
        "Automated Task Form Init: handleCategoryChange function not found."
      );
    }

    const syncCategoryCards = (selectedCategory, selectedTaskType = "") => {
      if (!categoryCards.length) {
        return;
      }
      const selectedSource = ["content_writing_bulk", "content_writing_csv"].includes(
        selectedTaskType
      )
        ? "topic"
        : {
            content_writing_rss: "rss",
            content_writing_url: "url",
            content_writing_gsheets: "spreadsheet",
          }[selectedTaskType] || "";
      if (categorySelector) {
        categorySelector.dataset.selectedSource = selectedSource;
      }
      const selectedEntryView =
        form?.querySelector("[data-task-entry-view]")?.dataset.taskEntryView ||
        "batch";
      categoryCards.forEach((card) => {
        const cardTaskType = card.dataset.taskType || "";
        const cardEntryView = card.dataset.entryView || "";
        const sourceGroup = card.dataset.aipkitSourceGroup || "";
        const entryMode = card.dataset.aipkitEntryMode || "";
        let isActive = false;
        if (sourceGroup) {
          isActive = sourceGroup === selectedSource;
        } else if (entryMode) {
          isActive =
            cardTaskType === selectedTaskType &&
            (!cardEntryView || cardEntryView === selectedEntryView);
        } else if (cardTaskType) {
          isActive =
            cardTaskType === selectedTaskType &&
            (!cardEntryView || cardEntryView === selectedEntryView);
        } else {
          isActive = card.dataset.category === selectedCategory;
        }
        card.classList.toggle("is-active", isActive);
        card.setAttribute("aria-pressed", isActive ? "true" : "false");
      });
    };

    if (taskCategorySelect && taskTypeSelect && categoryCards.length) {
      categoryCards.forEach((card) => {
        card.addEventListener("click", () => {
          const nextCategory = card.dataset.category || "";
          const nextTaskType = card.dataset.taskType || "";
          const nextEntryView = card.dataset.entryView || "";
          if (!nextCategory) {
            return;
          }
          const categoryChanged = nextCategory !== taskCategorySelect.value;

          if (categoryChanged) {
            taskCategorySelect.value = nextCategory;
            taskCategorySelect.dispatchEvent(
              new Event("change", { bubbles: true })
            );
          }

          if (nextTaskType && taskTypeSelect.value !== nextTaskType) {
            taskTypeSelect.value = nextTaskType;
            taskTypeSelect.dispatchEvent(new Event("change", { bubbles: true }));
          } else if (!categoryChanged) {
            syncCategoryCards(taskCategorySelect.value, taskTypeSelect.value);
          }

          if (
            nextEntryView &&
            typeof window.aipkit_setAutogptManualEntryView === "function"
          ) {
            window.aipkit_setAutogptManualEntryView(nextEntryView);
            syncCategoryCards(taskCategorySelect.value, taskTypeSelect.value);
          }
        });
      });
      taskCategorySelect.addEventListener("change", () => {
        syncCategoryCards(taskCategorySelect.value, taskTypeSelect.value);
        scheduleStageHeightSync();
      });
      taskTypeSelect.addEventListener("change", () => {
        syncCategoryCards(taskCategorySelect.value, taskTypeSelect.value);
        scheduleStageHeightSync();
      });
      syncCategoryCards(taskCategorySelect.value || "", taskTypeSelect.value || "");
    }

    if (
      taskTypeSelect &&
      typeof window.aipkit_form_handleTaskTypeChange === "function"
    ) {
      taskTypeSelect.addEventListener(
        "change",
        window.aipkit_form_handleTaskTypeChange
      );
      // Initial call to set correct section visibility
      window.aipkit_form_handleTaskTypeChange({ target: taskTypeSelect });
      scheduleStageHeightSync();
    } else if (taskTypeSelect) {
      console.error(
        "Automated Task Form Init: handleTaskTypeChange function not found."
      );
    }

    if (
      cancelEditButton &&
      typeof window.aipkit_form_hideAutomatedTaskForm === "function"
    ) {
      cancelEditButton.addEventListener("click", () => {
        window.aipkit_form_hideAutomatedTaskForm(
          formWrapper,
          taskListWrapper,
          addTaskButton
        );
      });
    } else if (cancelEditButton) {
      console.error(
        "Automated Task Form Init: hideAutomatedTaskForm function not found."
      );
    }

    if (typeof window.aipkit_initContentIndexingTaskFormUI === "function") {
      window.aipkit_initContentIndexingTaskFormUI();
    }

    const syncStatusToggle = () => {
      if (!statusToggle || !statusInput) {
        return;
      }
      const isActive = (statusInput.value || "active") === "active";
      statusToggle.checked = isActive;
      if (scheduleCard) {
        scheduleCard.dataset.status = isActive ? "active" : "paused";
      }
    };

    window.aipkit_syncTaskStatusToggle = syncStatusToggle;

    if (statusToggle && statusInput) {
      if (!statusToggle.dataset.statusListenerAttached) {
        statusToggle.addEventListener("change", () => {
          statusInput.value = statusToggle.checked ? "active" : "paused";
          syncStatusToggle();
        });
        statusToggle.dataset.statusListenerAttached = "true";
      }
      syncStatusToggle();
    }

    if (form && form.dataset.promptDraftListenerAttached !== "true") {
      let promptDraftTimer = null;
      const schedulePromptDraftSave = () => {
        if (promptDraftTimer) {
          clearTimeout(promptDraftTimer);
        }
        promptDraftTimer = setTimeout(() => {
          promptDraftTimer = null;
          saveActivePromptDraft(form);
        }, 250);
      };

      const handlePromptDraftEvent = (event) => {
        const target = event.target;
        if (!target || !target.id || !promptDraftFieldIdSet.has(target.id)) {
          return;
        }
        schedulePromptDraftSave();
      };

      form.addEventListener("input", handlePromptDraftEvent);
      form.addEventListener("change", handlePromptDraftEvent);
      form.dataset.promptDraftListenerAttached = "true";
    }

    if (form && form.dataset.providerSetupListenerAttached !== "true") {
      const syncProviderSetup = () => {
        window.aipkit_updateAutogptProviderNotice?.();
        window.aipkit_autogpt_provider_setup?.syncInlineCredentialNotices?.(form);
      };
      form.addEventListener("change", syncProviderSetup);
      form.dataset.providerSetupListenerAttached = "true";
    }

    if (window.aipkit_autogptStageHeightListenerAttached !== true) {
      window.addEventListener("resize", scheduleStageHeightSync);
      window.aipkit_autogptStageHeightListenerAttached = true;
    }
  }

  window.aipkit_restoreAutogptPromptDraft = aipkit_restoreAutogptPromptDraft;
  window.aipkit_clearActiveAutogptPromptDraft =
    aipkit_clearActiveAutogptPromptDraft;
  window.aipkit_form_initAutomatedTaskForm = aipkit_form_initAutomatedTaskForm;
})();
