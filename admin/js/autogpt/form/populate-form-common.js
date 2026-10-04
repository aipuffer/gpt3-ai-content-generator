/**
 * AIPKit Automated Tasks - Form Common Population
 * Populates fields common to both new and edit modes of the task form.
 */
(function () {
  "use strict";

  /**
   * Populates common form fields like title, buttons, and basic inputs.
   * @param {HTMLFormElement} form - The main task form element.
   * @param {Object|null} taskData - The task data if editing, or null if new.
   */
  function aipkit_form_populateCommonFields(form, taskData) {
    const saveButton = document.getElementById("aipkit_save_task_btn");
    const saveButtonText = saveButton
      ? saveButton.querySelector(".aipkit_btn-text")
      : null;
    const taskIdInput = form.elements["task_id"];
    const taskNameInput = form.elements["task_name"];
    const taskTypeSelect = form.elements["task_type"];
    const taskStatusSelect = form.elements["task_status"];
    const cancelEditButton = document.getElementById(
      "aipkit_cancel_edit_task_btn"
    );

    const config = window.aipkit_automated_tasks_config || {};
    const texts = config.text || {};

    const headerTitleEditor = document.getElementById(
      "aipkit_autogpt_header_title_editor"
    );
    const titleDisplay = document.getElementById("aipkit_autogpt_title_display");
    const titleText = document.getElementById("aipkit_autogpt_title_text");
    const titleInput = document.getElementById(
      "aipkit_autogpt_task_title_input"
    );
    const defaultTaskLabel =
      (titleText && titleText.dataset.defaultLabel) ||
      (titleInput && titleInput.placeholder) ||
      "New Task";

    const updateTitleDisplay = () => {
      const value = titleInput ? titleInput.value.trim() : "";
      const label = value || defaultTaskLabel;
      if (titleText) {
        titleText.textContent = label;
      }
      if (titleDisplay) {
        titleDisplay.setAttribute("title", label);
        titleDisplay.classList.toggle(
          "is-placeholder",
          !value || value === defaultTaskLabel
        );
      }
      if (taskNameInput) {
        taskNameInput.value = label;
      }
    };

    const syncTitleInputWidth = () => {
      if (!titleInput) return;
      const probe = document.createElement("span");
      const inputStyle = window.getComputedStyle(titleInput);
      probe.textContent = titleInput.value || titleInput.placeholder || defaultTaskLabel;
      Object.assign(probe.style, {
        position: "fixed",
        inset: "auto auto -9999px -9999px",
        visibility: "hidden",
        whiteSpace: "pre",
        fontFamily: inputStyle.fontFamily,
        fontSize: inputStyle.fontSize,
        fontStyle: inputStyle.fontStyle,
        fontWeight: inputStyle.fontWeight,
        fontStretch: inputStyle.fontStretch,
        letterSpacing: inputStyle.letterSpacing,
        lineHeight: inputStyle.lineHeight,
      });
      document.body.appendChild(probe);
      titleInput.style.width = `${Math.ceil(probe.getBoundingClientRect().width)}px`;
      probe.remove();
    };

    const showTitleInput = () => {
      if (!titleDisplay || !titleInput) return;
      syncTitleInputWidth();
      titleDisplay.style.display = "none";
      titleInput.style.display = "inline-block";
      titleInput.focus();
      titleInput.select();
    };

    const showTitleDisplay = () => {
      if (!titleDisplay || !titleInput) return;
      titleInput.style.display = "none";
      titleDisplay.style.display = "inline-flex";
      updateTitleDisplay();
    };

    if (headerTitleEditor) headerTitleEditor.style.display = "flex";

    if (
      headerTitleEditor &&
      headerTitleEditor.dataset.inlineTitleBound !== "true" &&
      titleDisplay &&
      titleInput
    ) {
      titleDisplay.addEventListener("click", showTitleInput);
      titleInput.addEventListener("blur", showTitleDisplay);
      titleInput.addEventListener("keydown", (event) => {
        if (event.key === "Enter") {
          event.preventDefault();
          titleInput.blur();
        }
      });
      titleInput.addEventListener("input", updateTitleDisplay);
      titleInput.addEventListener("input", syncTitleInputWidth);
      headerTitleEditor.dataset.inlineTitleBound = "true";
    }

    if (taskData) {
      // Editing an existing task
      if (saveButtonText)
        saveButtonText.textContent = texts.save_task_button || "Save Task";
      if (taskIdInput) taskIdInput.value = taskData.id || "";
      if (titleInput) titleInput.value = taskData.task_name || defaultTaskLabel;
      updateTitleDisplay();
      if (taskTypeSelect)
        taskTypeSelect.value = taskData.task_type || "content_indexing";
      if (taskStatusSelect)
        taskStatusSelect.value = taskData.status || "active";
      if (typeof window.aipkit_syncTaskStatusToggle === "function") {
        window.aipkit_syncTaskStatusToggle();
      }
      if (cancelEditButton) cancelEditButton.style.display = "inline-flex";
    } else {
      // Creating a new task
      if (saveButtonText)
        saveButtonText.textContent = texts.save_task_button || "Save Task";
      if (taskIdInput) taskIdInput.value = "";
      if (titleInput) titleInput.value = defaultTaskLabel;
      updateTitleDisplay();
      if (taskTypeSelect) taskTypeSelect.value = "";
      if (taskStatusSelect) taskStatusSelect.value = "active";
      if (typeof window.aipkit_syncTaskStatusToggle === "function") {
        window.aipkit_syncTaskStatusToggle();
      }
      if (cancelEditButton) cancelEditButton.style.display = "inline-flex";
    }
  }

  window.aipkit_form_populateCommonFields = aipkit_form_populateCommonFields;
})();
