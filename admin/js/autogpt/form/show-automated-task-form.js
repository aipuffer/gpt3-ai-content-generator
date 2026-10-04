/**
 * AIPKit Automated Tasks - Show Task Form (Orchestrator)
 * Handles showing the task form and orchestrates populating it for new or existing tasks
 * by calling modular helper functions.
 */
(function () {
  "use strict";

  function aipkit_form_showAutomatedTaskForm(
    formWrapper,
    taskListWrapper,
    addTaskButton,
    taskData = null
  ) {
    const form = document.getElementById("aipkit_automated_task_form");
    const queueWrapper = document.getElementById(
      "aipkit_automated_task_queue_wrapper"
    );
    if (!form) {
      console.error("Show Task Form: Core form element not found.");
      return;
    }

    // 1. Reset form and wizard to the first step
    if (typeof window.aipkit_resetWizard === "function") {
      window.aipkit_resetWizard();
    }
    form.reset();
    if (!taskData) {
      ['', 'ce_', 'cc_'].forEach((prefix) => window.aipkit_applyNewFeatureDefaults?.(form, prefix));
    }

    if (form.elements["task_id"]) form.elements["task_id"].value = "";
    if (window.aipkit_automated_tasks_form_state) {
      window.aipkit_automated_tasks_form_state.pendingModelSelection = null;
      window.aipkit_automated_tasks_form_state.pendingImageModelSelection = null;
      window.aipkit_automated_tasks_form_state.pendingEmbeddingModelSelection = null;
      window.aipkit_automated_tasks_form_state.pendingTargetStoreSelection = null;
    }
    if (typeof window.aipkit_form_showAutomatedTaskFormStatus === "function") {
      window.aipkit_form_showAutomatedTaskFormStatus("", "info"); // Clear status
    }

    // Pre-select category when editing, so the Task Type dropdown can populate.
    if (taskData) {
      const allTaskTypes =
        (window.aipkit_automated_tasks_config || {}).task_types || {};
      const taskDetails = allTaskTypes[taskData.task_type];
      if (taskDetails) {
        const categorySelect = form.elements["task_category"];
        if (categorySelect) {
          categorySelect.value = taskDetails.category;
          // Programmatically trigger the change event to populate the task type dropdown
          const changeEvent = new Event("change", { bubbles: true });
          categorySelect.dispatchEvent(changeEvent);
        }
      }
    } else {
      const categorySelect = form.elements["task_category"];
      const taskTypeSelect = form.elements["task_type"];
      if (categorySelect && taskTypeSelect) {
        categorySelect.value = "";
        categorySelect.dispatchEvent(new Event("change", { bubbles: true }));
      }
    }

    // 2. Populate common fields (title, buttons, base inputs)
    if (typeof window.aipkit_form_populateCommonFields === "function") {
      window.aipkit_form_populateCommonFields(form, taskData);
    } else {
      console.error(
        "Show Task Form: aipkit_form_populateCommonFields function is missing."
      );
    }

    // Start new automations at the most common, immediately useful setup.
    // This runs after common population so the defaults are not cleared again.
    if (!taskData) {
      const categorySelect = form.elements["task_category"];
      const taskTypeSelect = form.elements["task_type"];
      if (categorySelect && taskTypeSelect) {
        categorySelect.value = "content_creation";
        categorySelect.dispatchEvent(new Event("change", { bubbles: true }));
        if (
          Array.from(taskTypeSelect.options).some(
            (option) => option.value === "content_writing_bulk"
          )
        ) {
          taskTypeSelect.value = "content_writing_bulk";
          taskTypeSelect.dispatchEvent(new Event("change", { bubbles: true }));
        }
      }
    }

    // 3. Trigger initial task type UI change based on common population
    const taskTypeSelect = form.elements["task_type"];
    if (
      taskTypeSelect &&
      typeof window.aipkit_form_handleTaskTypeChange === "function"
    ) {
      window.aipkit_form_handleTaskTypeChange({ target: taskTypeSelect });
    }

    // 4. If editing, populate task-specific fields
    if (taskData) {
      const taskConfig = JSON.parse(taskData.task_config || "{}");

      if (taskData.task_type === "content_indexing") {
        if (
          typeof window.aipkit_form_populateContentIndexingFields === "function"
        ) {
          window.aipkit_form_populateContentIndexingFields(taskConfig);
        } else {
          console.error(
            "Show Task Form: aipkit_form_populateContentIndexingFields function is missing."
          );
        }
      } else if (taskData.task_type.startsWith("content_writing")) {
        if (
          typeof window.aipkit_form_populateContentWritingFields === "function"
        ) {
          window.aipkit_form_populateContentWritingFields(taskConfig);
        } else {
          console.error(
            "Show Task Form: aipkit_form_populateContentWritingFields function is missing."
          );
        }
      } else if (taskData.task_type === "community_reply_comments") {
        if (
          typeof window.aipkit_form_populateCommentReplyFields === "function"
        ) {
          window.aipkit_form_populateCommentReplyFields(taskConfig);
        } else {
          console.error(
            "Show Task Form: aipkit_form_populateCommentReplyFields function is missing."
          );
        }
      } else if (taskData.task_type === "enhance_existing_content") {
        if (
          typeof window.aipkit_form_populateContentEnhancementFields ===
          "function"
        ) {
          window.aipkit_form_populateContentEnhancementFields(taskConfig);
        } else {
          console.error(
            "Show Task Form: aipkit_form_populateContentEnhancementFields function is missing."
          );
        }
      }
    }

    // 5. Show/hide the relevant wrappers
    if (formWrapper) formWrapper.style.display = "block";
    if (taskListWrapper) taskListWrapper.style.display = "none";
    if (queueWrapper) queueWrapper.style.display = "none";
    if (addTaskButton) addTaskButton.style.display = "none";

    const headerActions = document.getElementById(
      "aipkit_autogpt_editor_actions"
    );
    if (headerActions) headerActions.style.display = taskData ? "flex" : "none";

    const cronInfo = document.getElementById("aipkit_autogpt_cron_info");
    if (cronInfo) cronInfo.style.display = "none";

    if (typeof window.aipkit_initAutogptUnifiedModelSelectors === "function") {
      window.aipkit_initAutogptUnifiedModelSelectors(form);
    }
    window.setTimeout(() => {
      window.aipkit_updateAutogptProviderNotice?.();
      window.aipkit_autogpt_provider_setup?.syncInlineCredentialNotices?.(form);
    }, 0);

    if (typeof window.aipkit_syncAutogptStageHeights === "function") {
      window.aipkit_syncAutogptStageHeights();
      setTimeout(() => {
        window.aipkit_syncAutogptStageHeights();
      }, 0);
    }

    if (typeof window.aipkit_restoreAutogptPromptDraft === "function") {
      setTimeout(() => {
        window.aipkit_restoreAutogptPromptDraft({
          isEditing: !!taskData,
          silent: !!taskData,
        });
      }, 120);
    }

    if (typeof window.aipkit_resetAutogptProgressiveBuilder === "function") {
      window.aipkit_resetAutogptProgressiveBuilder({
        isEditing: !!taskData,
      });
      setTimeout(() => {
        window.aipkit_autogpt_progressive_builder?.updateSummaries?.();
      }, 180);
    }

    window.aipkit_autogpt_empty_workspace_controller?.handleFormShown?.();
  }

  window.aipkit_form_showAutomatedTaskForm = aipkit_form_showAutomatedTaskForm;
})();
