/**
 * AIPKit AutoGPT - Handle Task Type Change
 * Shows/hides task-specific configuration sections based on the selected task type.
 * UPDATED: Now dynamically re-renders the wizard steps by calling window.aipkit_renderWizard.
 */
(function () {
  "use strict";

  const updateAutogptPromptPlaceholders = () => {
    if (typeof window.aipkit_updateAutogptPromptPlaceholders === "function") {
      window.aipkit_updateAutogptPromptPlaceholders();
    }
  };

  /**
   * Handles changes to the new Task Category dropdown.
   */
  function aipkit_form_handleCategoryChange(event) {
    const selectedCategory = event.target.value;
    const taskTypeSelect = document.getElementById(
      "aipkit_automated_task_type"
    );
    const allTaskTypes =
      (window.aipkit_automated_tasks_config || {}).task_types || {};
    const formState =
      window.aipkit_automated_tasks_form_state ||
      (window.aipkit_automated_tasks_form_state = {});
    const lastTaskTypeByCategory = formState.lastTaskTypeByCategory || {};
    formState.lastTaskTypeByCategory = lastTaskTypeByCategory;
    const escaper =
      window.aipkit_escapeHtml ||
      function (str) {
        return str;
      };

    taskTypeSelect.innerHTML = "";
    taskTypeSelect.disabled = true;

    // --- Start Fix: Scope the section hiding to the first wizard step pane ---
    const setupPane = document.querySelector(
      '.aipkit_wizard_content_step[data-content-id="task_form_setup"]'
    );
    if (setupPane) {
      setupPane
        .querySelectorAll(".aipkit_task_config_section")
        .forEach((section) => {
          if (section.id === "aipkit_task_cw_input_modes_wrapper") {
            section
              .querySelectorAll(".aipkit_task_cw_input_mode_section")
              .forEach((subSection) => {
                subSection.style.display = "none";
              });
          }
          section.style.display = "none";
        });
    }
    // --- End Fix ---

    if (!selectedCategory) {
      taskTypeSelect.appendChild(
        new Option(escaper("-- Select a category first --"), "")
      );
      taskTypeSelect.dispatchEvent(new Event("change", { bubbles: true }));
      return;
    }

    const filteredTasks = Object.entries(allTaskTypes).filter(
      ([slug, details]) => details.category === selectedCategory
    );

    if (filteredTasks.length === 0) {
      taskTypeSelect.appendChild(
        new Option(escaper("-- No tasks in this category --"), "")
      );
    } else {
      taskTypeSelect.appendChild(
        new Option(escaper("-- Select a Task Type --"), "")
      );
      filteredTasks.forEach(([slug, details]) => {
        const option = new Option(escaper(details.label), slug);
        if (details.disabled) {
          option.disabled = true;
        }
        taskTypeSelect.appendChild(option);
      });
      taskTypeSelect.disabled = false;
    }

    taskTypeSelect.dispatchEvent(new Event("change", { bubbles: true }));
  }

  /**
   * Handles changes to the Task Type dropdown.
   */
  function aipkit_form_handleTaskTypeChange(event) {
    const selectedType = event.target.value;
    const taskIdInput = document.getElementById("aipkit_automated_task_id");
    const isNewTask = !taskIdInput || !taskIdInput.value;

    // --- PRO GUARD LOGIC ---
    const nextBtn = document.getElementById("aipkit_wizard_next_btn");
    const nextButtonText = nextBtn
      ? nextBtn.querySelector(".aipkit_btn-text")
      : null;
    const saveButton = document.getElementById("aipkit_save_task_btn");
    const saveButtonText = saveButton
      ? saveButton.querySelector(".aipkit_btn-text")
      : null;
    const editorActions = document.getElementById(
      "aipkit_autogpt_editor_actions"
    );
    const isPro = window.aipkit_dashboard && window.aipkit_dashboard.isProPlan;
    const allTaskTypes =
      (window.aipkit_automated_tasks_config || {}).task_types || {};
    const selectedTaskDetails = allTaskTypes[selectedType];
    const isProTask = selectedTaskDetails && selectedTaskDetails.pro === true;
    const texts = (window.aipkit_automated_tasks_config || {}).text || {};
    if (selectedTaskDetails) {
      const formState =
        window.aipkit_automated_tasks_form_state ||
        (window.aipkit_automated_tasks_form_state = {});
      const lastTaskTypeByCategory = formState.lastTaskTypeByCategory || {};
      lastTaskTypeByCategory[selectedTaskDetails.category] = selectedType;
      formState.lastTaskTypeByCategory = lastTaskTypeByCategory;
    }

    // --- END PRO GUARD LOGIC ---

    // Re-render the wizard based on the selected type
    if (typeof window.aipkit_renderWizard === "function") {
      window.aipkit_renderWizard(selectedType);
    } else {
      console.error(
        "Task Type Change: aipkit_renderWizard function is missing."
      );
    }

    const frequencyGroup = document.querySelector(
      ".aipkit_task_schedule_frequency"
    );
    const frequencySelect = document.getElementById(
      "aipkit_automated_task_frequency"
    );
    if (frequencyGroup && frequencySelect) {
      const formState =
        window.aipkit_automated_tasks_form_state ||
        (window.aipkit_automated_tasks_form_state = {});
      const isStaticSource =
        selectedType === "content_writing_bulk" ||
        selectedType === "content_writing_csv";
      if (isStaticSource) {
        if (!formState.lastVisibleFrequency && frequencySelect.value) {
          formState.lastVisibleFrequency = frequencySelect.value;
        }
        frequencySelect.value = "one-time";
        frequencyGroup.style.display = "none";
      } else {
        frequencyGroup.style.display = "";
        if (
          formState.lastVisibleFrequency &&
          frequencySelect.value === "one-time"
        ) {
          frequencySelect.value = formState.lastVisibleFrequency;
        }
        formState.lastVisibleFrequency = frequencySelect.value;
      }
    }

    const publishingFields = document.querySelectorAll(
      "[data-aipkit-task-publishing]"
    );
    if (publishingFields.length) {
      const isContentWritingType =
        selectedType && selectedType.startsWith("content_writing");
      publishingFields.forEach((field) => {
        field.style.display = isContentWritingType ? "" : "none";
      });
    }

    if (isProTask && !isPro) {
      if (nextButtonText && nextBtn) {
        nextBtn.dataset.action = "next";
        nextButtonText.textContent = texts.wizard_next_button || "Next";
        nextBtn.style.display = "none";
      }
      if (saveButtonText && saveButton) {
        saveButton.dataset.action = "save";
        saveButtonText.textContent = texts.save_task_button || "Save Task";
        saveButton.style.display = "none";
      }
      if (editorActions) {
        editorActions.style.display = "none";
      }
    } else {
      if (nextButtonText && nextBtn) {
        nextBtn.dataset.action = "next";
        nextButtonText.textContent = texts.wizard_next_button || "Next";
        nextBtn.style.display = "";
      }
      if (saveButtonText && saveButton) {
        saveButton.dataset.action = "save";
        saveButtonText.textContent = texts.save_task_button || "Save Task";
        saveButton.style.display = "";
      }
      if (editorActions) {
        editorActions.style.display = "flex";
      }
    }

    // Get the setup pane to scope our show/hide logic
    const setupPane = document.querySelector(
      '.aipkit_wizard_content_step[data-content-id="task_form_setup"]'
    );
    if (!setupPane) {
      console.error("Task Type Change: Could not find setup pane.");
      return;
    }

    // Hide all sub-sections within the setup pane
    const ciSourceWrapper = setupPane.querySelector(
      "#aipkit_task_ci_source_wrapper"
    );
    const cwInputModesWrapper = setupPane.querySelector(
      "#aipkit_task_cw_input_modes_wrapper"
    );
    const ccSourceWrapper = setupPane.querySelector(
      "#aipkit_task_cc_source_wrapper"
    );
    const ceContentSelectionWrapper = setupPane.querySelector(
      "#aipkit_task_ce_content_selection_wrapper"
    );

    if (ciSourceWrapper) ciSourceWrapper.style.display = "none";
    if (cwInputModesWrapper) cwInputModesWrapper.style.display = "none";
    if (ccSourceWrapper) ccSourceWrapper.style.display = "none";
    if (ceContentSelectionWrapper)
      ceContentSelectionWrapper.style.display = "none";

    if (!selectedType) {
      return; // Nothing selected, keep everything hidden
    }

    // Show the correct input mode section within the setup step
    if (selectedType === "content_indexing") {
      if (ciSourceWrapper) ciSourceWrapper.style.display = "block";
    } else if (selectedType.startsWith("content_writing")) {
      updateAutogptPromptPlaceholders();
      if (cwInputModesWrapper) {
        cwInputModesWrapper.style.display = "block";
        // Also manage the sub-sections within the content writing modes wrapper
        const mode = selectedType.replace("content_writing_", "");
        const generationMode = mode === "content_writing" ? "bulk" : mode;
        const generationModeInput = document.getElementById(
          "aipkit_task_cw_generation_mode"
        );
        if (generationModeInput) {
          generationModeInput.value = generationMode;
        }
        cwInputModesWrapper
          .querySelectorAll(".aipkit_task_cw_input_mode_section")
          .forEach((subSection) => {
            if (
              subSection.id === `aipkit_task_cw_input_mode_${generationMode}`
            ) {
              subSection.style.display = "block";
            } else {
              subSection.style.display = "none";
            }
          });
      }
    } else if (selectedType === "community_reply_comments") {
      if (ccSourceWrapper) {
        ccSourceWrapper.style.display = "block";
      }
    } else if (selectedType === "enhance_existing_content") {
      if (ceContentSelectionWrapper) {
        ceContentSelectionWrapper.style.display = "block";
      }
      if (
        typeof window.aipkit_syncContentEnhancementQueueNowVisibility ===
        "function"
      ) {
        window.aipkit_syncContentEnhancementQueueNowVisibility({
          isEditing: !isNewTask,
          resetForNew: isNewTask,
        });
      }
      const setupPane = document.querySelector(
        '.aipkit_wizard_content_step[data-content-id="task_form_setup"]'
      );
      const taskForm = document.getElementById("aipkit_automated_task_form");

      if (setupPane && taskForm) {
        const postTypesSelect = setupPane.querySelector(
          "#aipkit_task_ce_post_types"
        );
        if (
          postTypesSelect &&
          !postTypesSelect.dataset.placeholderListenerAttached
        ) {
          const updateProductPlaceholdersVisibility = () => {
            const selectedOptions = Array.from(
              postTypesSelect.selectedOptions
            ).map((opt) => opt.value);
            const showProductPlaceholders = selectedOptions.includes("product");

            const placeholderSpans = taskForm.querySelectorAll(
              ".aipkit-product-placeholders"
            );
            placeholderSpans.forEach((span) => {
              span.style.display = showProductPlaceholders ? "inline" : "none";
            });
          };
          postTypesSelect.addEventListener(
            "change",
            updateProductPlaceholdersVisibility
          );
          postTypesSelect.dataset.placeholderListenerAttached = "true";
          updateProductPlaceholdersVisibility(); // Initial check
        }
      }
    }
    // Note: 'enhance_existing_content' now has its own pane, so no logic is needed here to show a sub-section.

    if (isNewTask) {
      if (selectedType === "content_indexing") {
        const providerSelect = document.getElementById(
          "aipkit_task_content_indexing_target_store_provider"
        );
        const defaults = window.aipkit_getNewFeatureDefaults?.() || {};
        if (window.aipkit_automated_tasks_form_state) {
          window.aipkit_automated_tasks_form_state.pendingEmbeddingModelSelection = defaults.vector_embedding_model
            ? `${defaults.vector_embedding_provider}::${defaults.vector_embedding_model}` : '';
        }
        const setup = window.aipkit_autogpt_provider_setup;
        if (providerSelect && setup?.resolveNewTaskProvider) {
          const resolution = setup.resolveNewTaskProvider(
            providerSelect,
            window.aipkit_getNewFeatureDefaults?.().vector_store_provider || "local"
          );
          providerSelect.value = resolution.provider;
          setup.syncControlFallbackNote?.(providerSelect, resolution);
        }
      } else if (selectedType.startsWith("content_writing")) {
        if (
          typeof window.aipkit_form_setContentWritingDefaults === "function"
        ) {
          window.aipkit_form_setContentWritingDefaults();
        }
      } else if (selectedType === "community_reply_comments") {
        if (typeof window.aipkit_form_setCommentReplyDefaults === "function") {
          window.aipkit_form_setCommentReplyDefaults();
        }
      } else if (selectedType === "enhance_existing_content") {
        if (
          typeof window.aipkit_form_setContentEnhancementDefaults === "function"
        ) {
          window.aipkit_form_setContentEnhancementDefaults();
        }
      }
    }

    // Trigger sub-UI initializers now that sections are potentially visible
    if (selectedType === "content_indexing") {
      if (typeof window.aipkit_initContentIndexingTaskFormUI === "function") {
        window.aipkit_initContentIndexingTaskFormUI();
      }
    } else if (selectedType.startsWith("content_writing")) {
      if (typeof window.aipkit_initContentWritingTaskFormUI === "function") {
        window.aipkit_initContentWritingTaskFormUI();
      }
    } else if (selectedType === "community_reply_comments") {
      if (typeof window.aipkit_initCommentReplyTaskFormUI === "function") {
        window.aipkit_initCommentReplyTaskFormUI();
      }
    } else if (selectedType === "enhance_existing_content") {
      if (
        typeof window.aipkit_initContentEnhancementTaskFormUI === "function"
      ) {
        setTimeout(() => window.aipkit_initContentEnhancementTaskFormUI(), 0);
      } else {
        console.error(
          `[handleTaskTypeChange] aipkit_initContentEnhancementTaskFormUI not found!`
        );
      }
    }
  }

  window.aipkit_form_handleCategoryChange = aipkit_form_handleCategoryChange;
  window.aipkit_form_handleTaskTypeChange = aipkit_form_handleTaskTypeChange;
})();
