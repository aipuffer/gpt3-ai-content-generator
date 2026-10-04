/**
 * AIPKit Automated Tasks - Hide Task Form
 * Handles the UI logic to hide the task form and show the task list.
 */
(function () {
  "use strict";

  function aipkit_form_hideAutomatedTaskForm(
    formWrapper,
    taskListWrapper,
    addTaskButton
  ) {
    const queueWrapper = document.getElementById(
      "aipkit_automated_task_queue_wrapper"
    );

    if (formWrapper) formWrapper.style.display = "none";
    if (taskListWrapper) taskListWrapper.style.display = "block";
    if (queueWrapper) queueWrapper.style.display = "block";
    if (addTaskButton) addTaskButton.style.display = "inline-flex";

    const headerActions = document.getElementById(
      "aipkit_autogpt_editor_actions"
    );
    if (headerActions) headerActions.style.display = "none";

    const cronInfo = document.getElementById("aipkit_autogpt_cron_info");
    if (cronInfo) cronInfo.style.removeProperty("display");

    const form = document.getElementById("aipkit_automated_task_form");
    const taskIdInput = document.getElementById("aipkit_automated_task_id");
    const taskCategorySelect = document.getElementById(
      "aipkit_automated_task_category"
    );
    const headerTitleEditor = document.getElementById(
      "aipkit_autogpt_header_title_editor"
    );
    const titleDisplay = document.getElementById(
      "aipkit_autogpt_title_display"
    );
    const titleInput = document.getElementById(
      "aipkit_autogpt_task_title_input"
    );

    if (form) form.reset();
    if (taskIdInput) taskIdInput.value = "";

    if (headerTitleEditor) headerTitleEditor.style.display = "none";
    if (titleDisplay) titleDisplay.style.display = "inline-flex";
    if (titleInput) titleInput.style.display = "none";

    // Reset task category to default, which will trigger a change event to reset the task type dropdown
    if (taskCategorySelect) {
      taskCategorySelect.value = "";
      taskCategorySelect.dispatchEvent(new Event("change", { bubbles: true }));
    }

    // Reset the wizard to the first step
    if (typeof window.aipkit_resetWizard === "function") {
      window.aipkit_resetWizard();
    }
    if (typeof window.aipkit_resetAutogptProgressiveBuilder === "function") {
      window.aipkit_resetAutogptProgressiveBuilder({ isEditing: false });
    }

    window.aipkit_autogpt_empty_workspace_controller?.handleFormHidden?.();
  }

  window.aipkit_form_hideAutomatedTaskForm = aipkit_form_hideAutomatedTaskForm;
})();
