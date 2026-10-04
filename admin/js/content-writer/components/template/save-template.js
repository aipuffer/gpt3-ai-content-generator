/**
 * AIPKit Content Writer - Save Template
 * Handles the AJAX request to save or update a template.
 */
(function () {
  "use strict";

  async function aipkit_saveContentWriterTemplate(
    templateId,
    templateName,
    config
  ) {
    if (window.aipkit_cw_template_state.isSavingOrDeleting) return false;
    window.aipkit_cw_template_state.isSavingOrDeleting = true;

    const nonceField = document.getElementById(
      "aipkit_content_writer_template_nonce_field"
    );
    const showError =
      typeof window.aipkit_showError === "function" ? window.aipkit_showError : null;

    try {
      const response = await window.aipkit_apiRequest(
        "aipkit_save_cw_template",
        {
          template_id: templateId,
          template_name: templateName,
          config: JSON.stringify(config),
          _ajax_nonce: nonceField ? nonceField.value : "",
        }
      );

      window.aipkit_cw_template_state.initialConfigForSelectedTemplate =
        JSON.parse(JSON.stringify(response.template_config || config));
      window.aipkit_cw_template_state.currentTemplateModified = false;
      if (
        typeof window.aipkit_updateContentWriterAutosaveBaseline === "function"
      ) {
        window.aipkit_updateContentWriterAutosaveBaseline();
      }

      const templateSelect = document.getElementById(
        "aipkit_cw_template_select"
      );
      const savedTemplateId = response.template_id
        ? String(response.template_id)
        : "";
      if (savedTemplateId) {
        window.aipkit_cw_template_state.currentTemplateId = savedTemplateId;
        sessionStorage.setItem("aipkit_cw_last_template_id", savedTemplateId);
      }
      if (
        typeof window.aipkit_fetchAndPopulateTemplates === "function" &&
        templateSelect
      ) {
        await window.aipkit_fetchAndPopulateTemplates(templateSelect);
        if (savedTemplateId) {
          templateSelect.value = savedTemplateId;
          if (typeof window.aipkit_updateTemplatePickerSelection === "function") {
            window.aipkit_updateTemplatePickerSelection(savedTemplateId);
          }
        }
      }
      return true;
    } catch (error) {
      console.error("Error saving template:", error);
      if (showError) {
        showError("Couldn’t save the template.");
      }
      return false;
    } finally {
      window.aipkit_cw_template_state.isSavingOrDeleting = false;
    }
  }

  window.aipkit_saveContentWriterTemplate = aipkit_saveContentWriterTemplate;
})();
