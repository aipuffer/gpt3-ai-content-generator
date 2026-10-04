/**
 * AIPKit Content Writer - Rename Template Action
 * Renames an owned template without replacing its saved configuration.
 */
(function () {
  "use strict";

  async function aipkit_submitRenameContentWriterTemplate(
    templateId,
    templateName
  ) {
    if (window.aipkit_cw_template_state.isSavingOrDeleting) {
      return false;
    }

    const resolvedTemplateId = String(templateId || "").trim();
    const resolvedName = String(templateName || "").trim();
    const templateSelect = document.getElementById("aipkit_cw_template_select");
    const nonceField = document.getElementById(
      "aipkit_content_writer_template_nonce_field"
    );
    const showError =
      typeof window.aipkit_showError === "function"
        ? window.aipkit_showError
        : null;

    if (
      !resolvedTemplateId ||
      !resolvedName ||
      typeof window.aipkit_apiRequest !== "function"
    ) {
      return false;
    }

    window.aipkit_cw_template_state.isSavingOrDeleting = true;

    try {
      await window.aipkit_apiRequest("aipkit_rename_cw_template", {
        template_id: resolvedTemplateId,
        template_name: resolvedName,
        _ajax_nonce: nonceField ? nonceField.value : "",
      });

      if (
        templateSelect &&
        typeof window.aipkit_fetchAndPopulateTemplates === "function"
      ) {
        await window.aipkit_fetchAndPopulateTemplates(templateSelect, {
          applyTemplateSelection: false,
        });
      }

      return true;
    } catch (error) {
      console.error("Error renaming template:", error);
      if (showError) {
        showError("Couldn’t rename the template.");
      }
      return false;
    } finally {
      window.aipkit_cw_template_state.isSavingOrDeleting = false;
    }
  }

  window.aipkit_submitRenameContentWriterTemplate =
    aipkit_submitRenameContentWriterTemplate;
})();
