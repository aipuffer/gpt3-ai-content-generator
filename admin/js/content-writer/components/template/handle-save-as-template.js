/**
 * AIPKit Content Writer - Save As Template Action
 * Validates an inline template name and triggers the save operation.
 */
(function () {
  "use strict";

  async function aipkit_submitSaveAsContentWriterTemplate(templateName) {
    if (window.aipkit_cw_template_state.isSavingOrDeleting) return false;

    const form = document.getElementById("aipkit_content_writer_form");
    if (
      !form ||
      typeof window.aipkit_getContentWriterFormConfig !== "function" ||
      typeof window.aipkit_saveContentWriterTemplate !== "function"
    ) {
      console.error("Save As Template: Missing required form helpers.");
      if (typeof window.aipkit_showAlertModal === "function") {
        window.aipkit_showAlertModal(
          "An error occurred. Please refresh the page.",
          { title: "Template" }
        );
      } else {
        alert("An error occurred. Please refresh the page.");
      }
      return false;
    }

    if (!String(templateName || "").trim()) {
      return false;
    }

    const config = window.aipkit_getContentWriterFormConfig(form);
    return await window.aipkit_saveContentWriterTemplate(
      0,
      String(templateName).trim(),
      config
    );
  }

  window.aipkit_submitSaveAsContentWriterTemplate =
    aipkit_submitSaveAsContentWriterTemplate;
})();
