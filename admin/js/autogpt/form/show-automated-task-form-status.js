/**
 * AIPKit Automated Tasks - Show Form Status
 * Displays a status message within the task form.
 */
(function () {
  "use strict";

  function aipkit_form_showAutomatedTaskFormStatus(message, type = "info") {
    const statusDiv = document.getElementById(
      "aipkit_automated_task_form_status"
    );
    if (!statusDiv) return;

    const usesTrainingStyles = statusDiv.classList.contains(
      "aipkit_training_status"
    );

    const clearStatus = () => {
      statusDiv.textContent = "";
      if (usesTrainingStyles) {
        statusDiv.classList.remove(
          "is-visible",
          "is-success",
          "is-error",
          "is-warning",
          "is-loading"
        );
      } else {
        statusDiv.className = "aipkit_form-help";
      }
    };

    if (!message) {
      clearStatus();
      return;
    }

    const normalizedType = type === "info" ? "loading" : type;
    if (normalizedType !== "error" && normalizedType !== "warning") {
      clearStatus();
      return;
    }

    statusDiv.textContent = message;
    if (usesTrainingStyles) {
      statusDiv.classList.remove(
        "is-success",
        "is-error",
        "is-warning",
        "is-loading"
      );
      statusDiv.classList.add("is-visible");
      if (normalizedType) {
        statusDiv.classList.add(`is-${normalizedType}`);
      }
    } else {
      statusDiv.className = `aipkit_form-help aipkit_settings_message-${type}`;
    }

    // Auto-clear after a delay
    setTimeout(() => {
      if (statusDiv.textContent === message) {
        clearStatus();
      }
    }, 5000);
  }

  window.aipkit_form_showAutomatedTaskFormStatus =
    aipkit_form_showAutomatedTaskFormStatus;
})();
