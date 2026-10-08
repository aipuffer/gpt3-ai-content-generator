/**
 * AIPKit Settings - Backup and maintenance actions.
 */
(function () {
  "use strict";

  const __ = window.wp?.i18n?.__ || ((text) => text);
  const OTHERS_MESSAGE_CONTAINER_ID = "aipkit_settings_global_messages";

  function getSettingsContainer() {
    return document.getElementById("aipkit_settings_container");
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

  // A button may name its text part, so an icon beside it survives the loading label.
  function setButtonLoading(button, loading, loadingText = "") {
    if (!button) {
      return;
    }
    const label = button.querySelector("[data-aipkit-button-label]") || button;

    if (loading) {
      if (!button.dataset.originalLabel) {
        button.dataset.originalLabel = label.textContent || "";
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
        label.textContent = loadingText;
        button.appendChild(spinner);
      }
      return;
    }

    button.disabled = false;
    button.classList.remove("aipkit_loading");
    if (button.dataset.originalLabel) {
      label.textContent = button.dataset.originalLabel;
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

  // ---------- Backups: results show under the list; every restore can be undone ----------

  const BACKUP_NOTICE_KEY = "aipkit_settings_backup_notice";
  const MAX_BACKUP_BYTES = 5 * 1024 * 1024;

  const fill = (template, value) => String(template || "").replace("%s", () => String(value));
  const isPlainObject = (value) => Boolean(value) && typeof value === "object" && !Array.isArray(value);

  // Each new result replaces the last, including the notice left by a restore.
  function showBackupMessage(text, isError = false) {
    const notice = getSettingsContainer()?.querySelector("[data-aipkit-backup-notice]");
    if (notice) {
      notice.hidden = true;
    }
    const message = getSettingsContainer()?.querySelector("[data-aipkit-backup-message]");
    if (!message) {
      if (text) {
        showOthersMessage(isError ? "error" : "success", text);
      }
      return;
    }
    message.textContent = text || "";
    message.hidden = !text;
    message.classList.toggle("is-error", Boolean(text) && isError);
  }

  const failure = (button, error) => `${button?.dataset.failed || ""} ${error?.message || ""}`.trim();

  // The words of each warning live in the view, so they can be translated.
  function backupDialog(selector) {
    const template = getSettingsContainer()?.querySelector("template[data-aipkit-backup-dialogs]");
    return template?.content?.querySelector(selector) || null;
  }

  function confirmBackupStep(spec, message, options) {
    if (typeof window.aipkit_showConfirmModal === "function") {
      window.aipkit_showConfirmModal(message, {
        title: spec?.dataset.title || "",
        confirmText: spec?.dataset.confirm || __("Continue", "gpt3-ai-content-generator"),
        cancelText: spec?.dataset.cancel || __("Cancel", "gpt3-ai-content-generator"),
        ...options,
      });
      return;
    }
    if (window.confirm(message)) {
      options.onConfirm?.();
    } else {
      options.onCancel?.();
    }
  }

  // The page reloads after a restore, so what happened is kept for the next page, once.
  function rememberBackupNotice(text) {
    try {
      window.sessionStorage.setItem(BACKUP_NOTICE_KEY, text);
    } catch (error) {
      // Without storage the restore point's own line still says what happened.
    }
  }

  function showRememberedBackupNotice(panel) {
    let text = "";
    try {
      text = window.sessionStorage.getItem(BACKUP_NOTICE_KEY) || "";
      window.sessionStorage.removeItem(BACKUP_NOTICE_KEY);
    } catch (error) {
      text = "";
    }
    const notice = panel.querySelector("[data-aipkit-backup-notice]");
    const restore = panel.querySelector("#aipkit_settings_restore_restore_point");
    if (!text || !notice) {
      return;
    }
    notice.querySelector("[data-aipkit-backup-notice-text]").textContent = text;
    notice.querySelector('[data-aipkit-settings-action="go-back-restore-point"]').hidden = !restore || restore.hidden;
    notice.hidden = false;
  }

  function reloadAfterRestore(text) {
    if (text) {
      rememberBackupNotice(text);
    }
    const url = new URL(window.location.href);
    url.hash = "aipkit_settings_backups";
    window.history.replaceState(null, "", url);
    window.location.reload();
  }

  async function handleExportBackup(button) {
    if (typeof window.aipkit_apiRequest !== "function") {
      showBackupMessage(__("API helper missing.", "gpt3-ai-content-generator"), true);
      return;
    }
    showBackupMessage("");
    setButtonLoading(button, true, button.dataset.busyLabel || __("Downloading…", "gpt3-ai-content-generator"));
    try {
      const response = await window.aipkit_apiRequest("aipkit_export_settings_backup");
      if (!response || typeof response !== "object" || !response.export_data) {
        throw new Error(__("Export payload was empty.", "gpt3-ai-content-generator"));
      }
      const filename =
        response.filename ||
        `aipkit-settings-backup-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, "-")}.json`;
      downloadJsonFile(response.export_data, filename);
      showBackupMessage(fill(button.dataset.done || "%s", filename));
    } catch (error) {
      showBackupMessage(failure(button, error), true);
    } finally {
      setButtonLoading(button, false);
    }
  }

  async function handleImportBackup(fileInput, triggerButton) {
    const file = fileInput?.files?.[0];
    if (!file || typeof window.aipkit_apiRequest !== "function") {
      return;
    }
    showBackupMessage("");
    setButtonLoading(triggerButton, true, triggerButton?.dataset.busyLabel || __("Restoring…", "gpt3-ai-content-generator"));
    try {
      await window.aipkit_apiRequest("aipkit_import_settings_backup", {
        settings_backup_file: file,
      });
      const notice = getSettingsContainer()?.querySelector("[data-aipkit-backup-notice]");
      reloadAfterRestore(fill(notice?.dataset.fileDone || "%s", file.name));
    } catch (error) {
      showBackupMessage(failure(triggerButton, error), true);
      setButtonLoading(triggerButton, false);
    } finally {
      if (fileInput) {
        fileInput.value = "";
      }
    }
  }

  const siteOf = (url) => {
    try {
      return new URL(String(url || "")).host;
    } catch (error) {
      return "";
    }
  };

  const dateOf = (value) => {
    const date = new Date(String(value || ""));
    return Number.isNaN(date.getTime())
      ? ""
      : date.toLocaleString(document.documentElement.lang || undefined, { dateStyle: "medium", timeStyle: "short" });
  };

  function rejectBackupFile(fileInput, triggerButton, tooBig) {
    const spec = backupDialog("[data-aipkit-backup-bad-file-dialog]");
    fileInput.value = "";
    confirmBackupStep(spec, (tooBig ? spec?.dataset.tooBig : spec?.dataset.message) || "", {
      variant: "warning",
      onConfirm: () => fileInput.click(),
      onCancel: () => triggerButton?.focus(),
    });
  }

  // Before anything is replaced: which file it is, where and when it was made, and what it replaces.
  async function reviewBackupFile(fileInput, triggerButton) {
    const file = fileInput?.files?.[0];
    if (!file) {
      return;
    }
    showBackupMessage("");
    if (file.size > MAX_BACKUP_BYTES) {
      rejectBackupFile(fileInput, triggerButton, true);
      return;
    }
    let payload = null;
    try {
      payload = JSON.parse(await file.text());
    } catch (error) {
      payload = null;
    }
    // A backup from Download, or the settings block on its own, as older versions saved it.
    if (!isPlainObject(payload) || !(isPlainObject(payload.aipkit_options) && isPlainObject(payload.aipkit_options.providers) || isPlainObject(payload.providers))) {
      rejectBackupFile(fileInput, triggerButton, false);
      return;
    }

    const spec = backupDialog("[data-aipkit-backup-file-dialog]");
    const details = spec?.firstElementChild?.cloneNode(true) || null;
    if (details) {
      const site = siteOf(payload.site_url);
      const meta = [
        site ? fill(spec.dataset.from, site) : "",
        dateOf(payload.exported_at),
        payload.plugin_version ? fill(spec.dataset.version, payload.plugin_version) : "",
      ].filter(Boolean);
      details.querySelector("[data-aipkit-backup-file-name]").textContent = file.name;
      const metaLine = details.querySelector("[data-aipkit-backup-file-meta]");
      metaLine.textContent = meta.join(" · ");
      metaLine.hidden = meta.length === 0;
      const warning = details.querySelector("[data-aipkit-backup-file-warning]");
      warning.textContent = spec.dataset.otherSite || "";
      warning.hidden = !site || site === window.location.host;
    }
    confirmBackupStep(spec, spec?.dataset.message || "", {
      icon: "upload",
      content: details,
      onConfirm: () => void handleImportBackup(fileInput, triggerButton),
      onCancel: () => {
        fileInput.value = "";
        triggerButton?.focus();
      },
    });
  }

  async function handleSaveRestorePoint(button) {
    if (typeof window.aipkit_apiRequest !== "function") {
      showBackupMessage(__("API helper missing.", "gpt3-ai-content-generator"), true);
      return;
    }
    const panel = getSettingsContainer();
    showBackupMessage("");
    setButtonLoading(button, true, button.dataset.busyLabel || __("Saving…", "gpt3-ai-content-generator"));
    try {
      const response = await window.aipkit_apiRequest("aipkit_create_settings_restore_point");
      const point = response?.restore_point || {};
      const text = panel?.querySelector("[data-aipkit-restore-point-text]");
      if (text && point.text) {
        text.textContent = point.text;
      }
      const restore = panel?.querySelector("#aipkit_settings_restore_restore_point");
      if (restore) {
        restore.hidden = point.saved === false;
        restore.dataset.pointDate = point.date || "";
      }
    } catch (error) {
      showBackupMessage(failure(button, error), true);
    } finally {
      setButtonLoading(button, false);
    }
  }

  async function handleRestorePoint(button, date) {
    if (typeof window.aipkit_apiRequest !== "function") {
      return;
    }
    showBackupMessage("");
    setButtonLoading(button, true, button.dataset.busyLabel || __("Restoring…", "gpt3-ai-content-generator"));
    try {
      await window.aipkit_apiRequest("aipkit_restore_settings_restore_point");
      const notice = getSettingsContainer()?.querySelector("[data-aipkit-backup-notice]");
      reloadAfterRestore(date ? fill(notice?.dataset.pointDone, date) : notice?.dataset.pointDoneUndated || "");
    } catch (error) {
      showBackupMessage(failure(button, error), true);
      setButtonLoading(button, false);
    }
  }

  // Restore, and the notice's Go back, both ask first; neither can lose anything, since the current settings are kept.
  function confirmRestorePoint(trigger) {
    const button = getSettingsContainer()?.querySelector("#aipkit_settings_restore_restore_point");
    if (!button || button.hidden || button.disabled) {
      return;
    }
    const spec = backupDialog("[data-aipkit-backup-restore-dialog]");
    const date = button.dataset.pointDate || "";
    confirmBackupStep(spec, date ? fill(spec?.dataset.message, date) : spec?.dataset.messageUndated || "", {
      icon: "backup",
      content: spec?.firstElementChild?.cloneNode(true) || null,
      onConfirm: () => void handleRestorePoint(button, date),
      onCancel: () => trigger?.focus(),
    });
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
    const panel = getSettingsContainer();
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
          handleSaveRestorePoint(button);
          break;
        case "restore-restore-point":
        case "go-back-restore-point":
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
        void reviewBackupFile(importFileInput, importTrigger);
      });
    }

    showRememberedBackupNotice(panel);
    panel.dataset.aipkitOthersActionsBound = "true";
  }

  window.aipkit_initSettingsOthersActions = aipkit_initSettingsOthersActions;
})();
