/** AI Forms input collection and required-field validation. */
(function () {
  "use strict";

  /**
   * Validates form inputs, checking for required fields.
   * @param {HTMLFormElement} form The form element.
   * @returns {{isValid: boolean, userInputs: object, imageInputs: Array, firstInvalidField: HTMLElement|null}} Result object.
   */
  function aipkitForms_validateSseInputs(form) {
    const formFields = form.elements;
    let hasRequiredEmpty = false;
    let firstInvalidField = null;
    const userInputs = {};
    const imageInputs = [];
    const validatedGroups = {}; // To prevent re-validating a radio/checkbox group
    const isFieldSkippedByConversation = (field) =>
      !field || field.disabled;

    // First pass: build the userInputs object
    for (const field of formFields) {
      if (!field.name || isFieldSkippedByConversation(field)) continue;
      // Corrected regex to handle both single and array fields properly
      const match = field.name.match(/aipkit_form_field\[(.+?)\](\[\])?$/);
      if (!match || !match[1]) continue;

      const key = match[1];
      const isArrayField = match[2] === "[]";

      if (isArrayField) {
        if (!userInputs[key]) {
          userInputs[key] = [];
        }
        if (field.checked) {
          userInputs[key].push(field.value);
        }
      } else if (field.type === "radio") {
        if (field.checked) {
          userInputs[key] = field.value;
        }
      } else {
        userInputs[key] = field.value;
        if (field.classList.contains("aipkit-image-hidden-content")) {
          const payloadJson = field.dataset ? field.dataset.imagePayload : "";
          if (payloadJson) {
            try {
              const payload = JSON.parse(payloadJson);
              if (payload && payload.mime_type && payload.base64_data) {
                imageInputs.push(payload);
              }
            } catch (error) {
              console.error("AI Forms: Invalid image payload data.", error);
            }
          }
        }
      }
    }

    // Second pass: validate and style fields
    for (const field of formFields) {
      if (isFieldSkippedByConversation(field)) continue;

      // Clear previous error styles
      const fieldset = field.closest("fieldset");
      if (fieldset) {
        const legend = fieldset.querySelector("legend");
        if (legend) legend.style.color = "";
      } else {
        field.style.borderColor = "";
      }

      const isRequired =
        field.required ||
        (field.dataset && field.dataset.isRequired === "true");

      if (!isRequired) continue;

      let isEmpty = false;
      // Use the corrected regex here as well for consistency
      const match = field.name.match(/aipkit_form_field\[(.+?)\](\[\])?$/);
      if (!match || !match[1]) continue;
      let fieldKey = match[1];

      if (validatedGroups[fieldKey]) continue; // Only check group once

      if (field.type === "checkbox") {
        isEmpty = !userInputs[fieldKey] || userInputs[fieldKey].length === 0;
        validatedGroups[fieldKey] = true;
      } else if (field.type === "radio") {
        isEmpty = !userInputs[fieldKey]; // a checked radio would have a value
        validatedGroups[fieldKey] = true;
      } else if (
        field.classList.contains("aipkit-file-hidden-content") ||
        field.classList.contains("aipkit-image-hidden-content")
      ) {
        isEmpty = field.value.trim() === "";
      } else if (
        field.type !== "file" &&
        field.type !== "submit" &&
        field.type !== "button"
      ) {
        isEmpty = field.value.trim() === "";
      }

      if (isEmpty) {
        hasRequiredEmpty = true;
        let fieldToHighlight = field;
        if (field.type === "radio" || field.type === "checkbox") {
          fieldToHighlight = field.closest("fieldset");
        } else if (
          field.classList.contains("aipkit-file-hidden-content") ||
          field.classList.contains("aipkit-image-hidden-content")
        ) {
          const uploadSelector = field.classList.contains(
            "aipkit-image-hidden-content"
          )
            ? ".aipkit-image-upload-input"
            : ".aipkit-file-upload-input";
          fieldToHighlight = form.querySelector(
            `${uploadSelector}[data-field-id="${fieldKey}"]`
          );
        }

        if (!firstInvalidField) {
          firstInvalidField = fieldToHighlight;
        }

        // Apply error styles
        if (
          fieldToHighlight?.type !== "checkbox" &&
          fieldToHighlight?.type !== "radio" &&
          fieldToHighlight
        ) {
          fieldToHighlight.style.borderColor = "red";
        } else if (fieldToHighlight) {
          const legend = fieldToHighlight.querySelector("legend");
          if (legend) legend.style.color = "red";
        }
      }
    }

    return {
      isValid: !hasRequiredEmpty,
      userInputs: userInputs,
      imageInputs: imageInputs,
      firstInvalidField: firstInvalidField,
    };
  }

  // Expose globally
  window.aipkitForms_validateSseInputs = aipkitForms_validateSseInputs;
})();
