/** AI Forms prompt-variable validation and feedback. */
(function () {
  "use strict";

  const summary = (icon, title, description) =>
    `<div class="aipkit_prompt_validation_summary"><span class="dashicons dashicons-${icon}" aria-hidden="true"></span><div><strong>${title}</strong><span>${description}</span></div></div>`;
  const messageList = (messages) => `<ul>${messages.map(message => `<li>${message}</li>`).join("")}</ul>`;

  /**
   * Validates the prompt template against the current form structure.
   */
  function aipkitForms_validatePrompt(options = {}) {
    const state = window.aipkitAIFormsState;
    const __ = wp.i18n && wp.i18n.__ ? wp.i18n.__ : (text) => text;
    const editorContainer = document.getElementById(
      "aipkit_form_editor_container"
    );
    if (!editorContainer) {
      return { isValid: false, errors: [], suggestions: [] };
    }

    const promptTextarea = editorContainer.querySelector(
      "#aipkit_ai_form_prompt_template"
    );
    const resultsContainer = editorContainer.querySelector(
      "#aipkit_prompt_validation_results"
    );
    if (!promptTextarea || !resultsContainer) {
      return { isValid: false, errors: [], suggestions: [] };
    }

    const promptText = promptTextarea.value;
    const isPromptEmpty = promptText.trim().length === 0;
    const errors = [];
    const suggestions = [];
    const escapeHtml = (value) =>
      String(value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
    const variableCode = (value) => `<code>{${escapeHtml(value)}}</code>`;

    // 1. Get all defined field IDs from the state
    const definedFieldIds = new Set();
    state.formStructure.forEach((row) => {
      row.columns.forEach((col) => {
        col.elements.forEach((el) => {
          if (el.fieldId) {
            definedFieldIds.add(el.fieldId);
          }
        });
      });
    });

    // 2. Find all well-formed placeholders in the prompt
    const placeholderRegex = /{[a-zA-Z0-9_]+}/g;
    const foundPlaceholders = (promptText.match(placeholderRegex) || []).map(
      (p) => p.slice(1, -1)
    );
    const foundPlaceholdersSet = new Set(foundPlaceholders);

    if (isPromptEmpty) {
      errors.push(
        __("Prompt instructions are required.", "gpt3-ai-content-generator")
      );
    }

    // 3. Check for placeholders in prompt that are not defined in the form
    foundPlaceholders.forEach((placeholder) => {
      if (!definedFieldIds.has(placeholder)) {
        errors.push(
          `Variable ${variableCode(
            placeholder
          )} is used in the prompt but not defined as a form field.`
        );
      }
    });

    // 4. Check for defined fields that are not used in the prompt
    definedFieldIds.forEach((fieldId) => {
      if (!foundPlaceholdersSet.has(fieldId)) {
        suggestions.push(
          `Field ${variableCode(fieldId)} is defined but not used in the prompt.`
        );
      }
    });

    // 5. Check for malformed placeholders
    // Remove all valid placeholders, then search for any remaining curly braces.
    if (/[{}]/.test(promptText.replace(placeholderRegex, ""))) {
      errors.push(
        "Malformed variable detected. Check for mismatched or empty curly braces like '{}', or lone '{' or '}'."
      );
    }

    const usedFieldCount = Array.from(definedFieldIds).filter((fieldId) =>
      foundPlaceholdersSet.has(fieldId)
    ).length;
    const hasWarnings = suggestions.length > 0;

    // 6. Build and display the result message
    resultsContainer.className = "aipkit_form-help"; // Reset class
    resultsContainer.innerHTML = "";

    if (errors.length > 0) {
      resultsContainer.classList.add("aipkit-validation-error");
      let html = summary(
        "warning",
        isPromptEmpty
          ? __("Prompt is empty", "gpt3-ai-content-generator")
          : __("Check prompt variables", "gpt3-ai-content-generator"),
        isPromptEmpty
          ? __("Add instructions before saving or previewing.", "gpt3-ai-content-generator")
          : __("Fix these before saving or previewing.", "gpt3-ai-content-generator")
      );
      if (!isPromptEmpty || errors.length > 1) html += messageList(errors);
      resultsContainer.innerHTML = html;
    } else {
      resultsContainer.classList.add(hasWarnings ? "aipkit-validation-warning" : "aipkit-validation-success");
      let html = summary(
        hasWarnings ? "warning" : "yes-alt",
        hasWarnings
          ? __("Prompt needs attention", "gpt3-ai-content-generator")
          : __("Prompt looks good", "gpt3-ai-content-generator"),
        `${usedFieldCount} of ${definedFieldIds.size} ${__("fields used", "gpt3-ai-content-generator")}`
      );
      if (hasWarnings) {
        html += `<div class="aipkit_prompt_validation_subtitle">${__("Unused fields", "gpt3-ai-content-generator")}</div>`;
        html += messageList(suggestions);
      }
      resultsContainer.innerHTML = html;
    }

    resultsContainer.style.display = "block";

    // Successful feedback can stay transient. Preview failures remain visible
    // so the user can fix them before trying again.
    if (resultsContainer.dismissTimer) {
      clearTimeout(resultsContainer.dismissTimer);
    }
    if (options.autoDismiss !== false || errors.length === 0) {
      const dismissDelay = errors.length > 0 || hasWarnings ? 8000 : 5000;
      resultsContainer.dismissTimer = setTimeout(() => {
        resultsContainer.style.display = "none";
        resultsContainer.innerHTML = "";
      }, dismissDelay);
    }

    return {
      isValid: errors.length === 0,
      hasWarnings,
      errors,
      suggestions,
    };
  }

  // Expose globally
  window.aipkitForms_validatePrompt = aipkitForms_validatePrompt;
})();
