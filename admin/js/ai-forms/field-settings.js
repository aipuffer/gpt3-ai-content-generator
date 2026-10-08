/** AI Forms field settings: rendering, variable names, option editing and state updates. */
(function () {
  "use strict";

  const defaultElementLabels = new Set([
    "New Text Input", "New Textarea", "New Dropdown", "New Checkbox Group",
    "New Radio Buttons", "File Upload", "Image Upload",
  ]);

  function findField(state, elementId) {
    for (const row of state.formStructure) {
      for (const column of row.columns) {
        const element = column.elements.find((item) => item.internalId === elementId);
        if (element) return element;
      }
    }
    return null;
  }

  function fieldVariableHelp(followsLabel, usesDefaultLabel, __) {
    if (!followsLabel) {
      return __("Custom name — label changes will not overwrite it.", "gpt3-ai-content-generator");
    }
    return usesDefaultLabel
      ? __("Updates automatically when you rename this field.", "gpt3-ai-content-generator")
      : __("Auto-filled from the label — edit it to use a different name.", "gpt3-ai-content-generator");
  }

  function optionInputs(index, __, option, escape) {
    const saved = arguments.length > 2;
    const builderClass = saved ? " aipkit_builder_input" : "";
    return `
      <input type="text" class="aipkit_form-input${builderClass} aipkit-setting-field" data-setting="option-text" data-option-index="${index}"${saved ? ` value="${escape(option.text)}"` : ""} placeholder="${__("Option label", "gpt3-ai-content-generator")}">
      <input type="text" class="aipkit_form-input${builderClass} aipkit-setting-field aipkit_option_value_input" data-setting="option-value" data-option-index="${index}"${saved ? ` value="${escape(option.value)}"` : ""} placeholder="${__("Option value", "gpt3-ai-content-generator")}">
      <button type="button" class="aipkit_btn aipkit_btn-small aipkit_btn-icon aipkit_remove_option_btn" title="${__("Remove option", "gpt3-ai-content-generator")}" aria-label="${__("Remove option", "gpt3-ai-content-generator")}"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>`;
  }

  function showSettingsPanel(
    elementInternalId,
    formsContainerElement,
    options = {}
  ) {
    const state = window.aipkitAIFormsState;
    const __ =
      typeof wp !== "undefined" && wp.i18n && wp.i18n.__
        ? wp.i18n.__
        : (text) => text;
    const escaper =
      window.aipkit_escapeHtml ||
      function (str) {
        return str;
      };

    if (!formsContainerElement) {
      console.error(
        "AI Forms showSettingsPanel: formsContainerElement not provided."
      );
      return;
    }

    formsContainerElement
      .querySelectorAll(".aipkit_dropped_element.aipkit-element-selected")
      .forEach((el) => el.classList.remove("aipkit-element-selected"));
    const designerElement = formsContainerElement.querySelector(
      `#${CSS.escape(elementInternalId)}`
    ); // Query within container
    if (designerElement)
      designerElement.classList.add("aipkit-element-selected");

    state.selectedDesignerElementId = elementInternalId;

    const elementData = findField(state, elementInternalId);

    if (!elementData) {
      console.error(
        `showSettingsPanel: Could not find element data for ID ${elementInternalId} in state.`,
        state.formStructure
      );
      return; // Early return if data not found
    }

    const palette = formsContainerElement.querySelector(
      "#aipkit_ai_form_elements_palette"
    );
    const settingsPanel = formsContainerElement.querySelector(
      "#aipkit_ai_form_element_settings_panel"
    );
    const settingsFieldsContainer = settingsPanel
      ? settingsPanel.querySelector("#aipkit_settings_panel_fields")
      : null;
    const elementTypeDisplay = settingsPanel
      ? settingsPanel.querySelector("#aipkit_settings_panel_element_type")
      : null;

    if (
      !palette ||
      !settingsPanel ||
      !settingsFieldsContainer ||
      !elementTypeDisplay
    ) {
      console.warn(
        "AI Forms showSettingsPanel: Palette, settings panel, fields container, or type display not found in formsContainerElement."
      );
      return;
    }

    const elementTypeLabels = {
      "text-input": __("Text input", "gpt3-ai-content-generator"),
      textarea: __("Textarea", "gpt3-ai-content-generator"),
      select: __("Dropdown", "gpt3-ai-content-generator"),
      checkbox: __("Checkbox group", "gpt3-ai-content-generator"),
      "radio-button": __("Radio buttons", "gpt3-ai-content-generator"),
      "file-upload": __("File upload", "gpt3-ai-content-generator"),
      "image-upload": __("Image upload", "gpt3-ai-content-generator"),
    };
    const elementTypeLabel = elementData.type.replace(/-/g, " ");
    elementTypeDisplay.textContent =
      elementTypeLabels[elementData.type] ||
      elementTypeLabel.charAt(0).toUpperCase() + elementTypeLabel.slice(1);
    settingsPanel.dataset.elementType = elementData.type;
    settingsFieldsContainer.innerHTML = "";

    const hasFieldVariable = typeof elementData.fieldId === "string";
    const fieldIdFollowsLabel =
      hasFieldVariable && elementData.fieldIdManuallyEdited === false;
    const usesDefaultElementLabel = defaultElementLabels.has(
      elementData.label
    );
    const fieldIdLabel = __(
      "Field variable name (for prompt)",
      "gpt3-ai-content-generator"
    );
    const fieldIdHelp = hasFieldVariable
      ? fieldVariableHelp(fieldIdFollowsLabel, usesDefaultElementLabel, __)
      : __(
          "Use this name in the prompt. It must be unique and contain only letters, numbers, and underscores.",
          "gpt3-ai-content-generator"
        );
    const labelText = __("Label text", "gpt3-ai-content-generator");

    if (elementData.type === "text-input") {
      settingsFieldsContainer.innerHTML += `
        <div class="aipkit_form-group aipkit_element_setting_group">
          <label class="aipkit_form-label" for="setting-input-type-${elementData.internalId}">${__("Input format", "gpt3-ai-content-generator")}</label>
          <select id="setting-input-type-${elementData.internalId}" class="aipkit_form-input aipkit-setting-field" data-setting="inputType">
            <option value="text" ${elementData.inputType !== "email" ? "selected" : ""}>${__("Text", "gpt3-ai-content-generator")}</option>
            <option value="email" ${elementData.inputType === "email" ? "selected" : ""}>${__("Email", "gpt3-ai-content-generator")}</option>
          </select>
        </div>`;
    }

    settingsFieldsContainer.innerHTML += `
            <div class="aipkit_form-group aipkit_element_setting_group aipkit_element_setting_group--label">
                <label class="aipkit_form-label" for="setting-label-${
                  elementData.internalId
                }">${labelText}</label>
                <input type="text" id="setting-label-${
                  elementData.internalId
                }" class="aipkit_form-input aipkit_builder_input aipkit-setting-field" data-setting="label" value="${escaper(
      elementData.label
    )}">
            </div>
            <div class="aipkit_form-group aipkit_element_setting_group aipkit_element_setting_group--field-id">
                <label class="aipkit_form-label" for="setting-field-id-${
                  elementData.internalId
                }">${fieldIdLabel}</label>
                <input type="text" id="setting-field-id-${
                  elementData.internalId
                }" class="aipkit_form-input aipkit_builder_input aipkit-setting-field aipkit_field_id_input${
                  fieldIdFollowsLabel ? " is-auto" : ""
                }" data-setting="fieldId" data-field-id-mode="${
                  fieldIdFollowsLabel ? "auto" : "manual"
                }" value="${escaper(
      elementData.fieldId
    )}" pattern="[a-zA-Z0-9_]+" title="${__(
      "Only letters, numbers, and underscores allowed. No spaces.",
      "gpt3-ai-content-generator"
    )}">
                <p class="aipkit_form-help aipkit_field_id_help" data-field-id-help>${fieldIdHelp}</p>
            </div>
        `;

    if (
      elementData.type === "text-input" ||
      elementData.type === "textarea" ||
      elementData.type === "select"
    ) {
      const placeholderLabel =
        elementData.type === "select"
          ? __("Empty option text", "gpt3-ai-content-generator")
          : __("Placeholder text", "gpt3-ai-content-generator");
      const placeholderHelp =
        elementData.type === "select"
          ? __(
              "Shown as the first empty dropdown option.",
              "gpt3-ai-content-generator"
            )
          : "";
      const placeholderExamples = {
        "text-input": __(
          "e.g., Enter your full name",
          "gpt3-ai-content-generator"
        ),
        textarea: __(
          "e.g., Tell us how we can help",
          "gpt3-ai-content-generator"
        ),
        select: __("e.g., Choose an option", "gpt3-ai-content-generator"),
      };
      settingsFieldsContainer.innerHTML += `
                <div class="aipkit_form-group aipkit_element_setting_group aipkit_element_setting_group--placeholder">
                    <label class="aipkit_form-label" for="setting-placeholder-${
                      elementData.internalId
                    }">${placeholderLabel}</label>
                    <input type="text" id="setting-placeholder-${
                      elementData.internalId
                    }" class="aipkit_form-input aipkit_builder_input aipkit-setting-field" data-setting="placeholder" value="${escaper(
        elementData.placeholder || ""
      )}"${
        placeholderExamples[elementData.type]
          ? ` placeholder="${escaper(
              placeholderExamples[elementData.type]
            )}"`
          : ""
      }>
                    ${
                      placeholderHelp
                        ? `<p class="aipkit_form-help">${escaper(
                            placeholderHelp
                          )}</p>`
                        : ""
                    }
                </div>`;
    }

    settingsFieldsContainer.innerHTML += `
            <div class="aipkit_form-group aipkit_element_setting_group aipkit_element_setting_group--required">
                <label class="aipkit_form-label aipkit_checkbox-label" for="setting-required-${
                  elementData.internalId
                }">
                    <input type="checkbox" id="setting-required-${
                      elementData.internalId
                    }" class="aipkit-setting-field" data-setting="required" ${
      elementData.required ? "checked" : ""
    }>
                    ${__("Required field", "gpt3-ai-content-generator")}
                </label>
            </div>
            <div class="aipkit_form-group aipkit_element_setting_group aipkit_element_setting_group--help-text">
                <label class="aipkit_form-label" for="setting-help-text-${
                  elementData.internalId
                }">${__(
      "Help text (optional)",
      "gpt3-ai-content-generator"
    )}</label>
                <textarea id="setting-help-text-${
                  elementData.internalId
                }" class="aipkit_form-input aipkit_builder_textarea aipkit-setting-field" data-setting="helpText" rows="3" placeholder="${escaper(
      __("This text will appear under the field.", "gpt3-ai-content-generator")
    )}">${escaper(elementData.helpText || "")}</textarea>
            </div>
        `;

    if (
      elementData.type === "select" ||
      elementData.type === "radio-button" ||
      elementData.type === "checkbox"
    ) {
      let optionsHTML = `<hr class="aipkit_hr"><div class="aipkit_form-group aipkit_element_setting_group aipkit_element_setting_group--options"><label class="aipkit_form-label">${__(
        "Options",
        "gpt3-ai-content-generator"
      )}</label><p class="aipkit_form-help">${__(
        "Labels are shown to visitors; values are sent to the prompt.",
        "gpt3-ai-content-generator"
      )}</p><div id="aipkit_select_options_container">`; // ID used for event delegation
      (elementData.options || []).forEach((opt, index) => {
        optionsHTML += `<div class="aipkit_select_option_row">${optionInputs(index, __, opt, escaper)}</div>`;
      });
      optionsHTML += `</div><button type="button" id="aipkit_add_select_option_btn" class="aipkit_btn aipkit_btn-small aipkit_btn-secondary">${__(
        "Add option",
        "gpt3-ai-content-generator"
      )}</button></div>`;
      settingsFieldsContainer.innerHTML += optionsHTML;
    }

    palette.style.display = "none";
    settingsPanel.style.display = "block";

    if (options.focusLabel === true) {
      window.requestAnimationFrame(() => {
        const labelInput = settingsPanel.querySelector(
          'input[data-setting="label"]'
        );
        if (labelInput) {
          labelInput.focus();
          labelInput.select();
        }
      });
    }
  }

  // Expose globally
  window.aipkitForms_showSettingsPanel = showSettingsPanel;

  function addSelectOptionRow(formsContainerElement) {
    const __ =
      typeof wp !== "undefined" && wp.i18n && wp.i18n.__
        ? wp.i18n.__
        : (text) => text;

    if (!formsContainerElement) {
      console.error(
        "AI Forms addSelectOptionRow: formsContainerElement not provided."
      );
      return;
    }
    // Query within the formsContainerElement for the settings panel, then for the options container
    const settingsPanel = formsContainerElement.querySelector(
      "#aipkit_ai_form_element_settings_panel"
    );
    if (!settingsPanel) {
      console.warn(
        "AI Forms addSelectOptionRow: Settings panel not found in provided container."
      );
      return;
    }
    const optionsContainer = settingsPanel.querySelector(
      "#aipkit_select_options_container"
    );
    if (!optionsContainer) {
      console.warn(
        "AI Forms addSelectOptionRow: Options container not found in settings panel."
      );
      return;
    }

    const newIndex = optionsContainer.querySelectorAll(
      ".aipkit_select_option_row"
    ).length;
    const optionRow = document.createElement("div");
    optionRow.className = "aipkit_select_option_row";
    optionRow.innerHTML = optionInputs(newIndex, __);
    optionsContainer.appendChild(optionRow);
    optionRow.querySelector('input[data-setting="option-text"]')?.focus();
  }

  // Expose globally
  window.aipkitForms_addSelectOptionRow = addSelectOptionRow;

  function hideSettingsPanel(formsContainerElement) {
    const state = window.aipkitAIFormsState;

    if (!formsContainerElement) {
      console.error(
        "AI Forms hideSettingsPanel: formsContainerElement not provided."
      );
      return;
    }

    formsContainerElement
      .querySelectorAll(".aipkit_dropped_element.aipkit-element-selected")
      .forEach((el) => el.classList.remove("aipkit-element-selected"));
    state.selectedDesignerElementId = null;

    const palette = formsContainerElement.querySelector(
      "#aipkit_ai_form_elements_palette"
    );
    const settingsPanel = formsContainerElement.querySelector(
      "#aipkit_ai_form_element_settings_panel"
    );

    if (palette) palette.style.display = "block";
    if (settingsPanel) settingsPanel.style.display = "none";
  }

  // Expose globally
  window.aipkitForms_hideSettingsPanel = hideSettingsPanel;

  function updateElementFromSettingsPanel(
    elementInternalId,
    formsContainerElement
  ) {
    const state = window.aipkitAIFormsState;

    if (!formsContainerElement) {
      console.error(
        "AI Forms updateElementFromSettingsPanel: formsContainerElement not provided."
      );
      return;
    }

    const elementData = findField(state, elementInternalId);

    if (!elementData) {
      console.error(
        `updateElementFromSettingsPanel: Element data for ID ${elementInternalId} not found.`
      );
      return;
    }

    const settingsPanel = formsContainerElement.querySelector(
      "#aipkit_ai_form_element_settings_panel"
    );
    if (!settingsPanel) {
      console.warn(
        "AI Forms updateElementFromSettingsPanel: Settings panel not found in provided container."
      );
      return;
    }

    const labelInput = settingsPanel.querySelector(
      `input[data-setting="label"]`
    );
    if (labelInput) elementData.label = labelInput.value;

    const fieldIdInput = settingsPanel.querySelector(
      `input[data-setting="fieldId"]`
    );
    if (fieldIdInput) {
      let newFieldId = fieldIdInput.value.trim().replace(/[^a-zA-Z0-9_]/g, "");
      if (!newFieldId) {
        let labelBasedId = elementData.label
          .toLowerCase()
          .replace(/\s+/g, "_")
          .replace(/[^a-zA-Z0-9_]/g, "");
        if (!labelBasedId) labelBasedId = elementData.type.replace(/-/g, "_");
        newFieldId = `${labelBasedId}_${elementInternalId.split("-").pop()}`;
      }

      let isUnique = true;
      outerLoop: for (const row of state.formStructure) {
        for (const col of row.columns) {
          if (
            col.elements.some(
              (el) =>
                el.internalId !== elementInternalId && el.fieldId === newFieldId
            )
          ) {
            isUnique = false;
            break outerLoop;
          }
        }
      }

      if (isUnique) {
        elementData.fieldId = newFieldId;
        fieldIdInput.value = newFieldId;
        fieldIdInput.classList.remove("aipkit_input_error");
      } else {
        fieldIdInput.classList.add("aipkit_input_error");
      }
    }

    const requiredCheckbox = settingsPanel.querySelector(
      `input[data-setting="required"]`
    );
    if (requiredCheckbox) elementData.required = requiredCheckbox.checked;

    const helpTextInput = settingsPanel.querySelector(
      `textarea[data-setting="helpText"]`
    );
    if (helpTextInput) elementData.helpText = helpTextInput.value;

    if (elementData.type === "text-input") {
      const inputTypeSelect = settingsPanel.querySelector('[data-setting="inputType"]');
      if (inputTypeSelect) elementData.inputType = inputTypeSelect.value === "email" ? "email" : "text";
    }

    if (
      elementData.type === "text-input" ||
      elementData.type === "textarea" ||
      elementData.type === "select"
    ) {
      const placeholderInput = settingsPanel.querySelector(
        `input[data-setting="placeholder"]`
      );
      if (placeholderInput) elementData.placeholder = placeholderInput.value;
    }

    if (
      elementData.type === "select" ||
      elementData.type === "radio-button" ||
      elementData.type === "checkbox"
    ) {
      elementData.options = [];
      const optionRows = settingsPanel.querySelectorAll(
        ".aipkit_select_option_row"
      );
      optionRows.forEach((row) => {
        const valueInput = row.querySelector(
          'input[data-setting="option-value"]'
        );
        const textInput = row.querySelector(
          'input[data-setting="option-text"]'
        );
        if (valueInput && textInput && valueInput.value.trim() !== "") {
          elementData.options.push({
            value: valueInput.value,
            text: textInput.value,
          });
        } else if (
          valueInput &&
          textInput &&
          valueInput.value.trim() === "" &&
          textInput.value.trim() !== ""
        ) {
          elementData.options.push({
            value: textInput.value,
            text: textInput.value,
          });
        }
      });
    }

    const designerArea = formsContainerElement.querySelector(
      "#aipkit_ai_form_designer_area"
    );
    const designerElement = designerArea
      ? designerArea.querySelector(`#${CSS.escape(elementInternalId)}`)
      : null;

    if (designerElement && designerArea) {
      const parentColumn = designerElement.parentElement;
      const currentScrollTop = designerArea.scrollTop;
      const wasSelected = designerElement.classList.contains(
        "aipkit-element-selected"
      );

      const nextSibling = designerElement.nextElementSibling;
      designerElement.remove();
      if (typeof window.aipkitForms_renderFieldInColumn === "function") {
        window.aipkitForms_renderFieldInColumn(
          parentColumn,
          elementData,
          nextSibling
        );
      } else {
        console.error(
          "AI Forms updateElement: renderFieldInColumn function missing."
        );
      }

      if (wasSelected) {
        const newDesignerElement = designerArea.querySelector(
          `#${CSS.escape(elementInternalId)}`
        );
        if (newDesignerElement)
          newDesignerElement.classList.add("aipkit-element-selected");
      }
      designerArea.scrollTop = currentScrollTop;
    } else {
      console.warn(
        "AI Forms updateElement: Designer element or area not found in provided container for re-render."
      );
    }

    if (typeof window.aipkitForms_updatePromptSnippets === "function") {
      window.aipkitForms_updatePromptSnippets(formsContainerElement);
    }

    if (typeof window.aipkitForms_notifyEditorMutation === "function") {
      window.aipkitForms_notifyEditorMutation(formsContainerElement);
    }
  }

  // Expose globally
  window.aipkitForms_updateElementFromSettingsPanel =
    updateElementFromSettingsPanel;

  function attachSettingsPanelListeners(formsContainerElement) {
    const state = window.aipkitAIFormsState;
    const __ =
      typeof wp !== "undefined" && wp.i18n && wp.i18n.__
        ? wp.i18n.__
        : (text) => text;
    const settingsPanel = formsContainerElement.querySelector(
      "#aipkit_ai_form_element_settings_panel"
    );

    if (!settingsPanel) {
      console.warn(
        "AI Forms Init: Settings panel not found, cannot attach listeners."
      );
      return;
    }
    if (settingsPanel.dataset.inputListenerAttached === "true") {
      return; // Listeners already attached
    }

    const getSelectedElementData = () => state.selectedDesignerElementId
      ? findField(state, state.selectedDesignerElementId)
      : null;

    const slugifyLabel = (label) => {
      const normalizedLabel = String(label || "").normalize
        ? String(label || "").normalize("NFKD")
        : String(label || "");

      return normalizedLabel
        .replace(/[\u0300-\u036f]/g, "")
        .toLowerCase()
        .trim()
        .replace(/[’']/g, "")
        .replace(/[^a-z0-9]+/g, "_")
        .replace(/^_+|_+$/g, "");
    };

    const getUniqueFieldId = (baseFieldId, currentElementId) => {
      const usedFieldIds = new Set();
      state.formStructure.forEach((row) => {
        row.columns.forEach((column) => {
          column.elements.forEach((element) => {
            if (
              element.internalId !== currentElementId &&
              element.fieldId
            ) {
              usedFieldIds.add(element.fieldId);
            }
          });
        });
      });

      if (!usedFieldIds.has(baseFieldId)) {
        return baseFieldId;
      }

      let suffix = 2;
      while (usedFieldIds.has(`${baseFieldId}_${suffix}`)) {
        suffix += 1;
      }
      return `${baseFieldId}_${suffix}`;
    };

    const updateFieldIdModeUi = (followsLabel, labelValue = "") => {
      const fieldIdInput = settingsPanel.querySelector(
        'input[data-setting="fieldId"]'
      );
      const fieldIdHelp = settingsPanel.querySelector("[data-field-id-help]");
      if (!fieldIdInput || !fieldIdHelp) {
        return;
      }

      fieldIdInput.dataset.fieldIdMode = followsLabel ? "auto" : "manual";
      fieldIdInput.classList.toggle("is-auto", followsLabel);
      fieldIdHelp.textContent = fieldVariableHelp(
        followsLabel,
        !labelValue.trim() || defaultElementLabels.has(labelValue),
        __
      );
    };

    const updateHandler = () => {
      if (
        state.selectedDesignerElementId &&
        typeof aipkitForms_updateElementFromSettingsPanel === "function"
      ) {
        aipkitForms_updateElementFromSettingsPanel(
          state.selectedDesignerElementId,
          formsContainerElement
        );
      }
    };

    settingsPanel.addEventListener("input", (event) => {
      if (event.target.matches(".aipkit-setting-field")) {
        const elementData = getSelectedElementData();
        if (elementData && typeof elementData.fieldId === "string") {
          if (event.target.matches('[data-setting="fieldId"]')) {
            elementData.fieldIdManuallyEdited = true;
            updateFieldIdModeUi(false);
          } else if (
            event.target.matches('[data-setting="label"]') &&
            elementData.fieldIdManuallyEdited === false
          ) {
            const baseFieldId = slugifyLabel(event.target.value);
            const fieldIdInput = settingsPanel.querySelector(
              'input[data-setting="fieldId"]'
            );
            if (baseFieldId && fieldIdInput) {
              fieldIdInput.value = getUniqueFieldId(
                baseFieldId,
                elementData.internalId
              );
            }
            updateFieldIdModeUi(true, event.target.value);
          }
        }
        updateHandler();
      }
    });
    settingsPanel.addEventListener("change", (event) => {
      if (event.target.matches('.aipkit-setting-field[type="checkbox"]') || event.target.matches('[data-setting="inputType"]')) {
        updateHandler();
      }
    });

    settingsPanel.dataset.inputListenerAttached = "true";
  }

  // Expose globally
  window.aipkitForms_attachSettingsPanelListeners =
    attachSettingsPanelListeners;
})();
