/**
 * AIPKit Content Writer - Delete Template Action
 */
(function () {
  "use strict";

  async function deleteSelectedTemplate(templateId) {
    const nonceField = document.getElementById(
      "aipkit_content_writer_template_nonce_field"
    );
    const templateSelect = document.getElementById("aipkit_cw_template_select");
    const showError =
      typeof window.aipkit_showError === "function"
        ? window.aipkit_showError
        : null;

    const currentTemplateId = String(
      window.aipkit_cw_template_state.currentTemplateId ||
        templateSelect?.value ||
        ""
    );
    const isDeletingCurrentTemplate =
      currentTemplateId === String(templateId);

    window.aipkit_cw_template_state.isSavingOrDeleting = true;

    try {
      await window.aipkit_apiRequest("aipkit_delete_cw_template", {
        template_id: templateId,
        _ajax_nonce: nonceField ? nonceField.value : "",
      });

      if (isDeletingCurrentTemplate) {
        sessionStorage.removeItem("aipkit_cw_last_template_id");
        sessionStorage.removeItem("aipkit_cw_last_template_label");
        window.aipkit_cw_template_state.currentTemplateId = null;
      }

      if (
        templateSelect &&
        typeof window.aipkit_fetchAndPopulateTemplates === "function"
      ) {
        await window.aipkit_fetchAndPopulateTemplates(templateSelect, {
          applyTemplateSelection: isDeletingCurrentTemplate,
        });
      }

      return true;
    } catch (error) {
      console.error("Error deleting template:", error);
      if (showError) {
        showError("Couldn’t delete the template.");
      }
      return false;
    } finally {
      window.aipkit_cw_template_state.isSavingOrDeleting = false;
    }
  }

  async function aipkit_submitDeleteContentWriterTemplate(templateId = "") {
    if (window.aipkit_cw_template_state.isSavingOrDeleting) return false;

    const templateSelect = document.getElementById("aipkit_cw_template_select");
    const resolvedTemplateId = String(
      templateId || templateSelect?.value || ""
    ).trim();
    if (
      typeof window.aipkit_apiRequest !== "function"
    ) {
      console.error(
        "Delete Template: Missing required helper functions."
      );
      if (typeof window.aipkit_showAlertModal === "function") {
        window.aipkit_showAlertModal("Cannot delete: core function missing.", {
          title: "Template",
        });
      } else {
        alert("Cannot delete: core function missing.");
      }
      return false;
    }

    if (!resolvedTemplateId) {
      return false;
    }

    return await deleteSelectedTemplate(resolvedTemplateId);
  }

  window.aipkit_submitDeleteContentWriterTemplate =
    aipkit_submitDeleteContentWriterTemplate;
})();
