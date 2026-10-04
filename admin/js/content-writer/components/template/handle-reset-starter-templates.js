/**
 * AIPKit Content Writer - Reset Starter Templates
 */
(function () {
  "use strict";

  const showError =
    typeof window.aipkit_showError === "function"
      ? window.aipkit_showError
      : null;

  async function performReset() {
    if (!window.aipkit_cw_template_state) return false;
    if (window.aipkit_cw_template_state.isSavingOrDeleting) return false;

    const autosaveWasSuspended = window.aipkit_cw_autosave_suspended === true;
    const nonceField = document.getElementById(
      "aipkit_content_writer_template_nonce_field"
    );
    const templateSelect = document.getElementById(
      "aipkit_cw_template_select"
    );
    const applyTemplate =
      typeof window.aipkit_applyContentWriterTemplate === "function"
        ? window.aipkit_applyContentWriterTemplate
        : null;
    const templatePanel = document.getElementById(
      "aipkit_cw_template_picker_panel"
    );
    const setBusyOverlay =
      typeof window.aipkit_cw_setBusyOverlay === "function"
        ? window.aipkit_cw_setBusyOverlay
        : null;
    const overlayStartedAt = window.performance.now();

    window.aipkit_cw_autosave_suspended = true;
    window.aipkit_cw_template_state.isSavingOrDeleting = true;
    setBusyOverlay?.(templatePanel, true);

    try {
      await window.aipkit_apiRequest("aipkit_reset_cw_starter_templates", {
        _ajax_nonce: nonceField ? nonceField.value : "",
      });

      sessionStorage.removeItem("aipkit_cw_last_template_id");
      sessionStorage.removeItem("aipkit_cw_last_template_label");
      window.aipkit_cw_template_state.currentTemplateId = null;
      window.aipkit_cw_template_state.currentTemplateModified = false;

      if (
        templateSelect &&
        typeof window.aipkit_fetchAndPopulateTemplates === "function"
      ) {
        const selectedTemplateId =
          await window.aipkit_fetchAndPopulateTemplates(templateSelect, {
            applyTemplateSelection: false,
          });

        if (selectedTemplateId && applyTemplate) {
          applyTemplate(selectedTemplateId, true);
        }
      }

      return true;
    } catch (error) {
      console.error("Error resetting starter templates:", error);
      if (showError) {
        showError("Couldn’t reset starter templates.");
      }
      return false;
    } finally {
      const minimumOverlayDuration = 400;
      const elapsed = window.performance.now() - overlayStartedAt;
      if (elapsed < minimumOverlayDuration) {
        await new Promise((resolve) => {
          window.setTimeout(resolve, minimumOverlayDuration - elapsed);
        });
      }
      setBusyOverlay?.(templatePanel, false);
      window.aipkit_cw_template_state.isSavingOrDeleting = false;
      window.aipkit_cw_autosave_suspended = autosaveWasSuspended;
    }
  }

  async function aipkit_submitResetStarterTemplates() {
    if (window.aipkit_cw_template_state?.isSavingOrDeleting) {
      return false;
    }
    return await performReset();
  }

  window.aipkit_submitResetStarterTemplates =
    aipkit_submitResetStarterTemplates;
})();
