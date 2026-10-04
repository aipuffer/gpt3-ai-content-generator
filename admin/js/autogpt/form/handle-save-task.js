/**
 * AIPKit Automated Tasks - Handle Save Task (Orchestrator)
 * Manages the AJAX submission of the automated task form by calling modular functions.
 */
(function () {
  "use strict";

  async function aipkit_form_handleSaveTask(event) {
    event.preventDefault();
    if (window.aipkit_automated_tasks_form_state.isSubmitting) return;

    const form = event.target;
    const primarySaveButton = document.getElementById("aipkit_save_task_btn");
    const isQuickCreate =
      form.dataset.aipkitQuickCreateSubmitting === "1";
    delete form.dataset.aipkitQuickCreateSubmitting;
    const quickCreateButton = form.querySelector(
      "[data-aipkit-builder-quick-create]"
    );
    const saveButton =
      isQuickCreate && quickCreateButton
        ? quickCreateButton
        : primarySaveButton;
    const submissionPeers = isQuickCreate
      ? [
          form.querySelector("[data-aipkit-builder-footer-previous]"),
          form.querySelector("[data-aipkit-builder-footer-next]"),
        ].filter(Boolean)
      : [];
    const peerDisabledStates = submissionPeers.map(
      (button) => button.disabled
    );
    let loadingStarted = false;

    if (primarySaveButton && primarySaveButton.dataset.action === "upgrade") {
      const upgradeUrl = window.aipkit_dashboard?.upgradeUrl;
      if (upgradeUrl) {
        window.open(upgradeUrl, "_blank");
      } else {
        console.error("Upgrade URL not found.");
      }
      return; // Stop execution
    }

    window.aipkit_automated_tasks_form_state.isSubmitting = true;

    const texts = (window.aipkit_automated_tasks_config || {}).text || {};
    const buttonOriginalText =
      saveButton?.querySelector(".aipkit_btn-text")?.textContent?.trim() ||
      texts.save_task_button ||
      "Save changes";
    const pendingButtonText = texts.saving_task || "Saving...";
    const pendingStatusText = texts.saving_task || "Saving task...";

    try {
      // 1. Extract data
      const { formData, data: baseData } =
        window.aipkit_save_task_extractBaseData(form);

      // 2. Prepare task-specific data
      let preparedData;
      if (baseData.task_type === "content_indexing") {
        preparedData = window.aipkit_save_task_handleContentIndexing(
          baseData,
          formData
        );
      } else if (baseData.task_type.startsWith("content_writing")) {
        preparedData = window.aipkit_save_task_handleContentWriting(
          baseData,
          formData
        );
      } else if (baseData.task_type === "enhance_existing_content") {
        preparedData = window.aipkit_save_task_handleContentEnhancement(
          baseData,
          formData
        );
      } else {
        preparedData = baseData; // Pass through for other types
      }

      // 3. Validate
      const validationResult =
        window.aipkit_save_task_validateData(preparedData);
      if (validationResult !== true) {
        const credentialIssue =
          window.aipkit_autogpt_provider_setup?.getTaskCredentialIssue?.(form);
        if (
          credentialIssue &&
          credentialIssue.message === validationResult
        ) {
          window.aipkit_autogpt_progressive_builder?.revealIssue?.(
            credentialIssue
          );
        }
        if (
          typeof window.aipkit_form_showAutomatedTaskFormStatus === "function"
        ) {
          window.aipkit_form_showAutomatedTaskFormStatus(
            validationResult,
            "error"
          );
        }
        throw new Error("Validation failed: " + validationResult); // Stop execution
      }

      // 4. Set loading state and submit
      submissionPeers.forEach((button) => {
        button.disabled = true;
      });
      loadingStarted = true;
      if (typeof window.aipkit_setLoadingStateOpenAI === "function") {
        window.aipkit_setLoadingStateOpenAI(
          saveButton,
          true,
          buttonOriginalText,
          pendingButtonText
        );
      }
      if (
        typeof window.aipkit_form_showAutomatedTaskFormStatus === "function"
      ) {
        window.aipkit_form_showAutomatedTaskFormStatus(
          pendingStatusText,
          "info"
        );
      }

      const response = await window.aipkit_save_task_submitRequest(
        preparedData
      );

      // 5. Handle success
      if (
        typeof window.aipkit_form_showAutomatedTaskFormStatus === "function"
      ) {
        window.aipkit_form_showAutomatedTaskFormStatus(
          response.message || "Task saved successfully!",
          "success"
        );
      }
      if (typeof window.aipkit_save_task_finalizeSuccess === "function") {
        window.aipkit_save_task_finalizeSuccess();
      }
    } catch (error) {
      console.error("Save Task Error:", error);
      if (
        typeof window.aipkit_form_showAutomatedTaskFormStatus === "function" &&
        error.message.indexOf("Validation failed:") === -1
      ) {
        window.aipkit_form_showAutomatedTaskFormStatus(
          `Error: ${error.message || "Failed to save task."}`,
          "error"
        );
      }
    } finally {
      window.aipkit_automated_tasks_form_state.isSubmitting = false;
      if (
        loadingStarted &&
        typeof window.aipkit_setLoadingStateOpenAI === "function"
      ) {
        window.aipkit_setLoadingStateOpenAI(
          saveButton,
          false,
          buttonOriginalText
        );
      }
      submissionPeers.forEach((button, index) => {
        button.disabled = peerDisabledStates[index];
      });
      if (isQuickCreate) {
        form.dispatchEvent(new Event("input"));
      }
    }
  }

  window.aipkit_form_handleSaveTask = aipkit_form_handleSaveTask;
})();
