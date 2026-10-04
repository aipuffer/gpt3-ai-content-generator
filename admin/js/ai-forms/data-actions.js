/**
 * AIPKit AI Forms - bulk deletion and JSON import/export.
 */
(function () {
  "use strict";

  const getI18n = () =>
    typeof wp !== "undefined" && wp.i18n && wp.i18n.__
      ? wp.i18n.__
      : (text) => text;

  const displayError = (formsContainer, message, requireContainer = false) => {
    if (
      (!requireContainer || formsContainer) &&
      typeof window.aipkitForms_displayMessage === "function"
    ) {
      window.aipkitForms_displayMessage(formsContainer, "error", message);
    } else {
      window.alert(message);
    }
  };

  const normalizeSelectedIds = (values) => Array.from(values || [])
    .map((formId) => Number(formId))
    .filter((formId) => formId > 0);

  const setBusy = (button, busy) => {
    button.disabled = busy;
    button.classList.toggle("is-busy", busy);
  };

  async function aipkitForms_deleteSelectedForms(
    button,
    formsContainerElement
  ) {
    const listState = window.aipkitAIFormsListState;
    const selectedIds = normalizeSelectedIds(listState?.selectedIds);
    if (!button || button.disabled || selectedIds.length === 0) {
      return;
    }

    const __ = getI18n();
    const config = window.aipkit_ai_forms_config || { text: {} };
    const visibleFormCount = document.querySelectorAll(
      "#aipkit_ai_forms_list_tbody .aipkit_ai_forms_row_checkbox"
    ).length;
    const pageAfterDelete =
      listState.currentPage > 1 && selectedIds.length === visibleFormCount
        ? listState.currentPage - 1
        : listState.currentPage;
    const confirmationMessage = `${__(
      "Permanently delete",
      "gpt3-ai-content-generator"
    )} ${selectedIds.length.toLocaleString()} ${
      selectedIds.length === 1
        ? __("selected form?", "gpt3-ai-content-generator")
        : __("selected forms?", "gpt3-ai-content-generator")
    } ${__("This cannot be undone.", "gpt3-ai-content-generator")}`;

    const executeDelete = async () => {
      setBusy(button, true);
      try {
        const response = await window.aipkit_apiRequest(
          "aipkit_delete_selected_ai_forms",
          {
            _ajax_nonce: config.nonce_manage_forms,
            form_ids: JSON.stringify(selectedIds),
          }
        );
        if (Array.isArray(response.failed_ids) && response.failed_ids.length > 0) {
          displayError(
            formsContainerElement,
            response.error_message ||
              __("Some selected forms could not be deleted.", "gpt3-ai-content-generator")
          );
        }
        listState.selectedIds.clear();
        if (typeof window.aipkitForms_syncSelectionUi === "function") {
          window.aipkitForms_syncSelectionUi();
        }
        listState.currentPage = pageAfterDelete;
        await window.aipkitForms_fetchForms();
      } catch (error) {
        displayError(
          formsContainerElement,
          error.message ||
            __("Error deleting selected forms.", "gpt3-ai-content-generator")
        );
      } finally {
        setBusy(button, false);
      }
    };

    if (typeof window.aipkit_showConfirmModal === "function") {
      window.aipkit_showConfirmModal(confirmationMessage, {
        title: __("Delete selected forms", "gpt3-ai-content-generator"),
        confirmText: __("Delete", "gpt3-ai-content-generator"),
        cancelText: __("Cancel", "gpt3-ai-content-generator"),
        variant: "danger",
        onConfirm: () => {
          void executeDelete();
        },
      });
      return;
    }

    if (window.confirm(confirmationMessage)) {
      await executeDelete();
    }
  }

  window.aipkitForms_deleteSelectedForms = aipkitForms_deleteSelectedForms;

  const downloadExport = (forms, filenamePrefix) => {
    const exportableForms = forms.map((form) => {
      const exportableForm = { ...form };
      delete exportableForm.id;
      delete exportableForm.status;
      return exportableForm;
    });
    const timestamp = new Date()
      .toISOString()
      .slice(0, 19)
      .replace(/[:T]/g, "-");
    const blob = new Blob([JSON.stringify(exportableForms, null, 2)], {
      type: "application/json",
    });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = `${filenamePrefix}-${timestamp}.json`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  };

  async function exportForms({
    action,
    button,
    data,
    filenamePrefix,
    formsContainer,
  }) {
    const __ = getI18n();
    const config = window.aipkit_ai_forms_config || { text: {} };
    if (!button || button.disabled) {
      return false;
    }

    setBusy(button, true);
    try {
      const response = await window.aipkit_apiRequest(action, {
        _ajax_nonce: config.nonce_manage_forms,
        ...data,
      });
      if (!Array.isArray(response.forms) || response.forms.length === 0) {
        throw new Error(
          __("No forms were available to export.", "gpt3-ai-content-generator")
        );
      }
      downloadExport(response.forms, filenamePrefix);
      return true;
    } catch (error) {
      displayError(
        formsContainer,
        `${__("Error exporting forms:", "gpt3-ai-content-generator")} ${
          error.message || __("Unknown error", "gpt3-ai-content-generator")
        }`,
        true
      );
      return false;
    } finally {
      setBusy(button, false);
    }
  }

  function aipkitForms_exportAllForms(button, formsContainer = null) {
    return exportForms({
      action: "aipkit_export_all_ai_forms",
      button,
      data: {},
      filenamePrefix: "aipkit-forms-export",
      formsContainer:
        formsContainer || document.getElementById("aipkit_ai_forms_container"),
    });
  }

  function aipkitForms_exportSelectedForms(
    button,
    selectedIds,
    formsContainer = null
  ) {
    const formIds = normalizeSelectedIds(selectedIds);
    if (formIds.length === 0) {
      return Promise.resolve(false);
    }
    return exportForms({
      action: "aipkit_export_selected_ai_forms",
      button,
      data: { form_ids: JSON.stringify(formIds) },
      filenamePrefix: "aipkit-selected-forms-export",
      formsContainer:
        formsContainer || document.getElementById("aipkit_ai_forms_container"),
    });
  }

  window.aipkitForms_exportAllForms = aipkitForms_exportAllForms;
  window.aipkitForms_exportSelectedForms = aipkitForms_exportSelectedForms;

  function showImportError(message) {
    displayError(document.getElementById("aipkit_ai_forms_container"), message);
  }

  async function aipkitForms_importForms(fileInput) {
    const config = window.aipkit_ai_forms_config || { text: {} };
    const __ = window.wp?.i18n?.__ || ((text) => text);

    const files = fileInput.files;
    if (!files.length) {
      return;
    }
    const file = files[0];

    const reader = new FileReader();
    reader.onload = async function (e) {
      try {
        const content = e.target.result;
        let forms = JSON.parse(content);
        // If it's a single object (from a single form export), wrap it in an array
        if (typeof forms === "object" && forms !== null && !Array.isArray(forms)) {
          forms = [forms];
        }

        if (!Array.isArray(forms)) {
          throw new Error(
            __(
              "Import file is not a valid JSON array.",
              "gpt3-ai-content-generator"
            )
          );
        }

        if (forms.length === 0) {
          showImportError(
            __("File contains no forms to import.", "gpt3-ai-content-generator")
          );
          return;
        }

        const response = await window.aipkit_apiRequest(
          "aipkit_import_ai_forms",
          {
            _ajax_nonce: config.nonce_manage_forms,
            forms_json: JSON.stringify(forms),
          }
        );

        if (response.failed_count > 0) {
          showImportError(response.message);
        }

        if (typeof window.aipkitForms_fetchForms === "function") {
          window.aipkitForms_fetchForms();
        }
      } catch (error) {
        showImportError(
          __("Error processing import file: ", "gpt3-ai-content-generator") +
            error.message
        );
      }
    };
    reader.onerror = function () {
      showImportError(
        __("Error reading the selected file.", "gpt3-ai-content-generator")
      );
    };
    reader.readAsText(file);
  }

  window.aipkitForms_importForms = aipkitForms_importForms;
})();
