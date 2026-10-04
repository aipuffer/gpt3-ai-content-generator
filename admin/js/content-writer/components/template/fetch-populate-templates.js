
/**
 * AIPKit Content Writer - Template Fetch and Populate
 * Fetches template data, renders grouped native select options, and applies
 * the preferred template selection.
 */
(function () {
  "use strict";

  const escaper =
    window.aipkit_escapeHtml ||
    function (str) {
      return str;
    };

  function getDefaultTemplateId(templates) {
    const currentUserId = window.aipkit_dashboard?.currentUserId?.toString();
    const defaultTemplate = Array.isArray(templates)
      ? templates.find(
          (template) =>
            String(template.is_default) === "1" &&
            String(template.user_id) === currentUserId
        )
      : null;
    return defaultTemplate ? String(defaultTemplate.id) : "";
  }

  function markTemplateRenderReady() {
    const modeContainer = document.querySelector(
      "#aipkit_content_writer_container .aipkit_cw_mode_container"
    );
    const sourceSelector = document.querySelector(
      "#aipkit_content_writer_container .aipkit_cw_source_selector_wrapper"
    );
    if (modeContainer) {
      modeContainer.dataset.templateReady = "1";
    }
    if (sourceSelector) {
      sourceSelector.dataset.templateReady = "1";
    }
  }

  async function aipkit_fetchAndPopulateTemplates(selectElement, options = {}) {
    if (!selectElement) {
      console.error("Fetch & Populate Templates: Select element is missing.");
      return "";
    }

    const nonceField = document.getElementById(
      "aipkit_content_writer_template_nonce_field"
    );
    const clearStatus =
      typeof window.aipkit_clearGeneralStatus === "function"
        ? window.aipkit_clearGeneralStatus
        : null;
    const showError =
      typeof window.aipkit_showError === "function"
        ? window.aipkit_showError
        : null;
    const applyTemplate =
      typeof window.aipkit_applyContentWriterTemplate === "function"
        ? window.aipkit_applyContentWriterTemplate
        : null;
    const shouldApplySelection = options.applyTemplateSelection !== false;

    selectElement.disabled = true;
    if (clearStatus) {
      clearStatus();
    }

    try {
      const response = await window.aipkit_apiRequest("aipkit_list_cw_templates", {
        _ajax_nonce: nonceField ? nonceField.value : "",
      });

      const templates = response.templates || [];
      const state = window.aipkit_cw_template_state || {};
      state.currentTemplates = templates;

      const preferredTemplateId =
        (sessionStorage.getItem("aipkit_cw_last_template_id") || "").trim() ||
        String(state.currentTemplateId || "").trim() ||
        getDefaultTemplateId(templates);

      if (typeof window.aipkit_renderTemplatePicker === "function") {
        window.aipkit_renderTemplatePicker(selectElement, preferredTemplateId);
      }

      const selectedTemplateId =
        preferredTemplateId &&
        selectElement.querySelector(
          `option[value="${CSS.escape(preferredTemplateId)}"]`
        )
          ? preferredTemplateId
          : "";

      if (selectedTemplateId) {
        selectElement.value = selectedTemplateId;
      }

      if (selectedTemplateId && shouldApplySelection && applyTemplate) {
        applyTemplate(selectedTemplateId, true, {
          restoreSavedSettings: options.restoreSavedSettings === true,
        });
      } else {
        markTemplateRenderReady();
      }

      return selectedTemplateId;
    } catch (error) {
      console.error("Error fetching templates:", error);
      selectElement.innerHTML = `<option value="">${escaper(
        "Error loading templates"
      )}</option>`;
      selectElement.disabled = true;
      if (showError) {
        showError("Couldn’t load templates.");
      }
      markTemplateRenderReady();
      return "";
    } finally {
      selectElement.disabled = false;
    }
  }

  window.aipkit_fetchAndPopulateTemplates = aipkit_fetchAndPopulateTemplates;
})();
