/**
 * AIPKit Settings - Others Page Actions
 * Handles backup and maintenance actions.
 */
(function () {
  "use strict";

  const __ = window.wp?.i18n?.__ || ((text) => text);
  const OTHERS_MESSAGE_CONTAINER_ID = "aipkit_settings_global_messages";

  function getSettingsContainer() {
    return document.getElementById("aipkit_settings_container");
  }

  function getOthersPanel() {
    const container = getSettingsContainer();
    if (!container) {
      return null;
    }
    return container.querySelector(".aipkit_settings_simple_form--others");
  }

  function showOthersMessage(type, message) {
    if (typeof window.aipkit_showMessage === "function") {
      window.aipkit_showMessage(OTHERS_MESSAGE_CONTAINER_ID, type, message);
      return;
    }
    if (type === "error") {
      console.error(message);
    }
  }

  function setButtonLoading(button, loading, loadingText = "") {
    if (!button) {
      return;
    }

    if (loading) {
      if (!button.dataset.originalLabel) {
        button.dataset.originalLabel = button.textContent || "";
      }
      button.disabled = true;
      button.classList.add("aipkit_loading");

      let spinner = button.querySelector(".aipkit_spinner");
      if (!spinner) {
        spinner = document.createElement("span");
        spinner.className = "aipkit_spinner";
        button.appendChild(spinner);
      }
      spinner.style.display = "inline-block";

      if (loadingText) {
        button.textContent = loadingText;
        button.appendChild(spinner);
      }
      return;
    }

    button.disabled = false;
    button.classList.remove("aipkit_loading");
    if (button.dataset.originalLabel) {
      button.textContent = button.dataset.originalLabel;
      delete button.dataset.originalLabel;
    }
    const spinner = button.querySelector(".aipkit_spinner");
    if (spinner) {
      spinner.style.display = "none";
      button.appendChild(spinner);
    }
  }

  function downloadJsonFile(data, filename) {
    const json = JSON.stringify(data, null, 2);
    const blob = new Blob([json], { type: "application/json" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  }

  async function handleExportBackup(button) {
    if (typeof window.aipkit_apiRequest !== "function") {
      showOthersMessage("error", __("API helper missing.", "gpt3-ai-content-generator"));
      return;
    }
    setButtonLoading(button, true, __("Exporting...", "gpt3-ai-content-generator"));
    try {
      const response = await window.aipkit_apiRequest("aipkit_export_settings_backup");
      if (!response || typeof response !== "object" || !response.export_data) {
        throw new Error(__("Export payload was empty.", "gpt3-ai-content-generator"));
      }
      const filename =
        response.filename ||
        `aipkit-settings-backup-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, "-")}.json`;
      downloadJsonFile(response.export_data, filename);
      showOthersMessage(
        "success",
        response.message || __("Settings export completed.", "gpt3-ai-content-generator")
      );
    } catch (error) {
      showOthersMessage(
        "error",
        __("Export failed: ", "gpt3-ai-content-generator") + (error?.message || "")
      );
    } finally {
      setButtonLoading(button, false);
    }
  }

  async function handleImportBackup(fileInput, triggerButton) {
    const file = fileInput?.files?.[0];
    if (!file) {
      return;
    }
    if (typeof window.aipkit_apiRequest !== "function") {
      showOthersMessage("error", __("API helper missing.", "gpt3-ai-content-generator"));
      return;
    }

    setButtonLoading(
      triggerButton,
      true,
      __("Importing...", "gpt3-ai-content-generator")
    );
    showOthersMessage("info", __("Importing settings...", "gpt3-ai-content-generator"));

    try {
      const response = await window.aipkit_apiRequest("aipkit_import_settings_backup", {
        settings_backup_file: file,
      });

      showOthersMessage(
        "success",
        response?.message ||
          __("Settings imported. Reloading...", "gpt3-ai-content-generator")
      );
      setButtonLoading(triggerButton, false);

      setTimeout(() => {
        window.location.reload();
      }, 750);
    } catch (error) {
      showOthersMessage(
        "error",
        __("Import failed: ", "gpt3-ai-content-generator") + (error?.message || "")
      );
      setButtonLoading(triggerButton, false);
    } finally {
      if (fileInput) {
        fileInput.value = "";
      }
    }
  }

  function confirmImportBackup(fileInput, triggerButton) {
    if (!fileInput?.files?.[0]) {
      return;
    }

    const message = __(
      "This replaces your current Settings configuration with the selected backup. Create a restore point first if you may need to roll back.",
      "gpt3-ai-content-generator"
    );
    const execute = () => {
      void handleImportBackup(fileInput, triggerButton);
    };
    const cancel = () => {
      fileInput.value = "";
    };

    if (typeof window.aipkit_showConfirmModal === "function") {
      window.aipkit_showConfirmModal(message, {
        title: __("Import settings backup?", "gpt3-ai-content-generator"),
        confirmText: __("Import backup", "gpt3-ai-content-generator"),
        cancelText: __("Cancel", "gpt3-ai-content-generator"),
        variant: "danger",
        onConfirm: execute,
        onCancel: cancel,
      });
      return;
    }

    if (window.confirm(message)) {
      execute();
    } else {
      cancel();
    }
  }

  async function handleSimpleAction(button, action, successFallback) {
    if (typeof window.aipkit_apiRequest !== "function") {
      showOthersMessage("error", __("API helper missing.", "gpt3-ai-content-generator"));
      return false;
    }
    setButtonLoading(button, true, __("Working...", "gpt3-ai-content-generator"));
    try {
      const response = await window.aipkit_apiRequest(action);
      showOthersMessage("success", response?.message || successFallback);
      if (typeof window.aipkit_updateLastSavedData === "function") {
        window.aipkit_updateLastSavedData();
      }
      return true;
    } catch (error) {
      showOthersMessage(
        "error",
        __("Action failed: ", "gpt3-ai-content-generator") + (error?.message || "")
      );
      return false;
    } finally {
      setButtonLoading(button, false);
    }
  }

  function confirmRestorePoint(button) {
    if (typeof window.aipkit_showConfirmModal !== "function") {
      showOthersMessage(
        "error",
        __("Confirmation dialog is not available.", "gpt3-ai-content-generator")
      );
      return;
    }

    window.aipkit_showConfirmModal(
      __(
        "This overwrites your current settings with the last saved restore point. This cannot be undone.",
        "gpt3-ai-content-generator"
      ),
      {
        title: __("Restore last point?", "gpt3-ai-content-generator"),
        confirmText: __("Restore point", "gpt3-ai-content-generator"),
        cancelText: __("Cancel", "gpt3-ai-content-generator"),
        variant: "danger",
        onConfirm: () => {
          handleSimpleAction(
            button,
            "aipkit_restore_settings_restore_point",
            __("Restore point applied. Reloading...", "gpt3-ai-content-generator")
          ).then((success) => {
            if (success) {
              setTimeout(() => {
                window.location.reload();
              }, 750);
            }
          });
        },
      }
    );
  }

  async function handleSyncAllModels(button) {
    if (
      typeof window.aipkit_apiRequest !== "function" ||
      typeof window.aipkit_queueProviderSync !== "function"
    ) {
      showOthersMessage(
        "error",
        __("Model sync helper is not available.", "gpt3-ai-content-generator")
      );
      return;
    }

    setButtonLoading(button, true, __("Syncing...", "gpt3-ai-content-generator"));
    try {
      const response = await window.aipkit_apiRequest(
        "aipkit_get_model_sync_targets"
      );
      const readyTargets = Array.isArray(response?.targets)
        ? response.targets.filter(
            (target) => target?.provider && target?.label
          )
        : [];

      if (!readyTargets.length) {
        showOthersMessage(
          "info",
          __("No connected providers are ready to sync yet.", "gpt3-ai-content-generator")
        );
        return;
      }

      showOthersMessage(
        "info",
        __("Running sync for all connected providers...", "gpt3-ai-content-generator")
      );

      const failures = [];
      const synced = [];

      for (const target of readyTargets) {
        try {
          await window.aipkit_queueProviderSync(target.provider, {
            silent: true,
            showErrors: false,
            showSuccess: false,
            propagateError: true,
          });
          synced.push(target.label);
        } catch (error) {
          failures.push({
            label: target.label,
            message:
              error?.message ||
              __("Unknown error", "gpt3-ai-content-generator"),
          });
        }
      }

      if (typeof window.aipkit_updateLastSavedData === "function") {
        window.aipkit_updateLastSavedData();
      }

      if (failures.length > 0) {
        const failedLabels = failures.map((item) => item.label).join(", ");
        showOthersMessage(
          "error",
          __("Sync completed with errors: ", "gpt3-ai-content-generator") +
            failedLabels
        );
      } else {
        showOthersMessage(
          "success",
          __("Models synced for: ", "gpt3-ai-content-generator") +
            synced.join(", ")
        );
      }
    } catch (error) {
      showOthersMessage(
        "error",
        __("Unable to load connected providers: ", "gpt3-ai-content-generator") +
          (error?.message || "")
      );
    } finally {
      setButtonLoading(button, false);
    }
  }

  function aipkit_initSettingsOthersActions() {
    const panel = getOthersPanel();
    if (!panel || panel.dataset.aipkitOthersActionsBound === "true") {
      return;
    }

    const importTrigger = panel.querySelector("#aipkit_settings_import_trigger");
    const importFileInput = panel.querySelector("#aipkit_settings_import_file");

    panel.addEventListener("click", (event) => {
      const button = event.target.closest("[data-aipkit-settings-action]");
      if (!button || button.disabled) {
        return;
      }

      const action = button.getAttribute("data-aipkit-settings-action");
      if (!action) {
        return;
      }

      event.preventDefault();

      switch (action) {
        case "export-backup":
          handleExportBackup(button);
          break;
        case "import-trigger":
          if (importFileInput) {
            importFileInput.click();
          }
          break;
        case "create-restore-point":
          handleSimpleAction(
            button,
            "aipkit_create_settings_restore_point",
            __("Restore point created.", "gpt3-ai-content-generator")
          );
          break;
        case "restore-restore-point":
          confirmRestorePoint(button);
          break;
        case "sync-all-models":
          handleSyncAllModels(button);
          break;
        default:
          break;
      }
    });

    if (importFileInput) {
      importFileInput.addEventListener("change", () => {
        confirmImportBackup(importFileInput, importTrigger);
      });
    }

    panel.dataset.aipkitOthersActionsBound = "true";
  }

  window.aipkit_initSettingsOthersActions = aipkit_initSettingsOthersActions;
})();
