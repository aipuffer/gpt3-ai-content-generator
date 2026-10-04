/**
 * AIPKit Content Writer - Autosave Logic
 * Saves the currently selected template when form values change.
 */
(function () {
  "use strict";

  window.aipkit_cw_isAutosaving = false;
  window.aipkit_cw_autosave_pending = false;
  window.aipkit_cw_autosave_pending_trigger = null;
  window.aipkit_cw_lastSavedData = null;

  const autosaveScopeSelector = [
    ".aipkit_model_settings_popover_panel",
    ".aipkit_cw_inspector_card",
    ".aipkit_cw_sources_card",
    ".aipkit_cw_mode_container",
    ".aipkit_cw_output_chunk",
    ".aipkit_cw_output_dock",
  ].join(",");

  let activeAutosaveScope = null;

  function getAutosaveTriggerElement(triggerElement) {
    if (triggerElement instanceof Element) {
      return triggerElement;
    }

    if (document.activeElement instanceof Element) {
      return document.activeElement;
    }

    return null;
  }

  function getAutosaveScope(triggerElement) {
    const trigger = getAutosaveTriggerElement(triggerElement);
    if (trigger) {
      const scope = trigger.closest(autosaveScopeSelector);
      if (scope) {
        return scope;
      }
    }

    return document.querySelector(
      "#aipkit_content_writer_container .aipkit_content_writer_layout"
    );
  }

  function ensureAutosaveOverlay(scope) {
    if (!scope) {
      return null;
    }

    let overlay = scope.querySelector(":scope > .aipkit_cw_autosave_overlay");
    if (overlay) {
      return overlay;
    }

    overlay = document.createElement("div");
    overlay.className = "aipkit_cw_autosave_overlay";
    overlay.setAttribute("aria-hidden", "true");
    overlay.hidden = true;

    const spinner = document.createElement("span");
    spinner.className = "aipkit_cw_autosave_overlay_spinner";
    spinner.setAttribute("aria-hidden", "true");
    overlay.appendChild(spinner);

    scope.appendChild(overlay);
    return overlay;
  }

  function setBusyOverlay(scope, isBusy) {
    if (!scope) {
      return;
    }

    const overlay = ensureAutosaveOverlay(scope);
    if (!overlay) {
      return;
    }

    scope.classList.toggle("aipkit_cw_autosave_busy_scope", Boolean(isBusy));
    scope.setAttribute("aria-busy", isBusy ? "true" : "false");
    overlay.hidden = !isBusy;
    overlay.setAttribute("aria-hidden", isBusy ? "false" : "true");
  }

  function setAutosaveBusy(triggerElement, isBusy) {
    const scope = isBusy ? getAutosaveScope(triggerElement) : activeAutosaveScope;
    if (!scope) {
      return;
    }

    setBusyOverlay(scope, isBusy);
    activeAutosaveScope = isBusy ? scope : null;
  }

  function getCurrentTemplateMeta() {
    const state = window.aipkit_cw_template_state;
    const templateId = state?.currentTemplateId;
    if (!templateId) {
      return null;
    }

    let templateName = "";
    let templateOwnerId = "";
    if (Array.isArray(state?.currentTemplates)) {
      const match = state.currentTemplates.find(
        (template) => String(template.id) === String(templateId)
      );
      if (match?.template_name) {
        templateName = match.template_name;
      }
      if (match?.user_id !== undefined && match?.user_id !== null) {
        templateOwnerId = String(match.user_id);
      }
    }

    const currentUserId = String(
      window.aipkit_dashboard?.currentUserId || ""
    );
    if (
      templateOwnerId &&
      currentUserId &&
      templateOwnerId !== currentUserId
    ) {
      return null;
    }

    if (!templateName) {
      const selectEl = document.getElementById("aipkit_cw_template_select");
      if (selectEl?.selectedOptions?.length) {
        templateName = selectEl.selectedOptions[0].textContent.trim();
      }
    }

    const parsedId = parseInt(templateId, 10);
    if (!templateName || Number.isNaN(parsedId)) {
      return null;
    }

    return { id: parsedId, name: templateName };
  }

  function getCurrentFormConfig() {
    if (typeof window.aipkit_getContentWriterFormConfig !== "function") {
      return null;
    }
    const form = document.getElementById("aipkit_content_writer_form");
    if (!form) {
      return null;
    }
    return window.aipkit_getContentWriterFormConfig(form);
  }

  async function aipkit_handleContentWriterAutoSave(triggerElement = null) {
    if (window.aipkit_cw_autosave_suspended) {
      return { ok: false, skipped: true, reason: "suspended" };
    }
    if (
      typeof window.aipkit_apiRequest !== "function" ||
      typeof window.aipkit_getContentWriterFormConfig !== "function"
    ) {
      return { ok: false, skipped: true, reason: "missing_dependencies" };
    }

    if (window.aipkit_cw_isAutosaving) {
      window.aipkit_cw_autosave_pending = true;
      window.aipkit_cw_autosave_pending_trigger = getAutosaveTriggerElement(
        triggerElement
      );
      return { ok: false, skipped: true, reason: "in_flight" };
    }

    const templateState = window.aipkit_cw_template_state;
    if (templateState?.isSavingOrDeleting) {
      return { ok: false, skipped: true, reason: "template_locked" };
    }

    const currentConfig = getCurrentFormConfig();
    if (!currentConfig) {
      return { ok: false, skipped: true, reason: "missing_config" };
    }

    const lastSaved = window.aipkit_cw_lastSavedData || {};
    if (JSON.stringify(currentConfig) === JSON.stringify(lastSaved)) {
      return { ok: true, skipped: true, reason: "unchanged" };
    }

    const templateMeta = getCurrentTemplateMeta();
    if (!templateMeta) {
      return { ok: false, skipped: true, reason: "missing_template" };
    }

    const showError =
      typeof window.aipkit_showError === "function" ? window.aipkit_showError : null;

    window.aipkit_cw_isAutosaving = true;
    if (typeof window.aipkit_clearGeneralStatus === "function") {
      window.aipkit_clearGeneralStatus();
    }
    setAutosaveBusy(triggerElement, true);

    try {
      const nonceField = document.getElementById(
        "aipkit_content_writer_template_nonce_field"
      );

      const response = await window.aipkit_apiRequest("aipkit_save_cw_template", {
        template_id: templateMeta.id,
        template_name: templateMeta.name,
        template_type: "content_writer",
        config: JSON.stringify(currentConfig),
        _ajax_nonce: nonceField ? nonceField.value : "",
      });

      window.aipkit_cw_lastSavedData = currentConfig;
      if (templateState) {
        const savedConfig = response.template_config || currentConfig;
        const cachedTemplate = templateState.currentTemplates?.find(
          (template) => String(template.id) === String(templateMeta.id)
        );
        if (cachedTemplate) {
          cachedTemplate.config = JSON.parse(JSON.stringify(savedConfig));
          if (cachedTemplate.is_starter === true || String(cachedTemplate.is_starter) === "1") {
            cachedTemplate.has_saved_settings = true;
          }
        }
        templateState.initialConfigForSelectedTemplate = JSON.parse(
          JSON.stringify(savedConfig)
        );
        templateState.currentTemplateModified = false;
      }

      return { ok: true };
    } catch (error) {
      const errorMessage = "Couldn’t save changes.";
      if (showError) {
        showError(errorMessage);
      }
      console.error("Content Writer Auto-Save Error:", error);
      return { ok: false, error: errorMessage };
    } finally {
      window.aipkit_cw_isAutosaving = false;
      setAutosaveBusy(null, false);
      if (window.aipkit_cw_autosave_pending) {
        const pendingTrigger = window.aipkit_cw_autosave_pending_trigger;
        window.aipkit_cw_autosave_pending = false;
        window.aipkit_cw_autosave_pending_trigger = null;
        window.setTimeout(() => {
          if (typeof window.aipkit_handleContentWriterAutoSave === "function") {
            window.aipkit_handleContentWriterAutoSave(pendingTrigger);
          }
        }, 0);
      }
    }
  }

  function aipkit_updateContentWriterAutosaveBaseline() {
    const currentConfig = getCurrentFormConfig();
    if (!currentConfig) {
      return;
    }
    window.aipkit_cw_lastSavedData = currentConfig;
  }

  window.aipkit_handleContentWriterAutoSave = aipkit_handleContentWriterAutoSave;
  window.aipkit_updateContentWriterAutosaveBaseline =
    aipkit_updateContentWriterAutosaveBaseline;
  window.aipkit_cw_setBusyOverlay = setBusyOverlay;
})();
