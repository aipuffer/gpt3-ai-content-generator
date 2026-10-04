/** AI Forms designer structure creation, ordering and field removal. */
// Callers retain their own translation capture and empty-canvas policy.
export function createDesignerPlaceholder(__) {
  const placeholder = document.createElement("div");
  placeholder.className = "aipkit_form_designer_placeholder";
  placeholder.id = "aipkit_form_designer_placeholder";
  placeholder.innerHTML = `
    <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
    <span class="aipkit_form_designer_placeholder_title">${__(
      "This form doesn't have any fields yet",
      "gpt3-ai-content-generator"
    )}</span>
    <span class="aipkit_form_designer_placeholder_hint">${__(
      "Drag a field from the left into this area to add it to your form.",
      "gpt3-ai-content-generator"
    )}</span>`;
  return placeholder;
}

export function showDesignerPlaceholder(designerArea, __) {
  let placeholder = designerArea.querySelector("#aipkit_form_designer_placeholder");
  if (!placeholder) {
    placeholder = createDesignerPlaceholder(__);
    designerArea.appendChild(placeholder);
  }
  placeholder.style.display = "flex";
}

(function () {
  "use strict";

  window.aipkitAIFormsState = {
    currentFormId: null,
    draggedElementType: null,
    draggedLayoutType: null,
    draggedElement: null,
    droppedElementCounter: 0,
    formStructure: [],
    selectedDesignerElementId: null,
  };

  function aipkitForms_updateFormStructureOrder() {
    const state = window.aipkitAIFormsState;
    const designerArea = document.getElementById("aipkit_ai_form_designer_area");
    if (!designerArea) return;

    // Keep the first match within a column and the last matching column,
    // matching the saved-data lookup used before rebuilding the DOM order.
    const elementsById = new Map();
    state.formStructure.forEach(row => {
      row.columns.forEach(column => {
        const seenIds = new Set();
        column.elements.forEach(element => {
          if (!seenIds.has(element.internalId)) {
            elementsById.set(element.internalId, element);
            seenIds.add(element.internalId);
          }
        });
      });
    });

    const newStructure = [];
    const rowElements = designerArea.querySelectorAll(".aipkit_layout_row");

    rowElements.forEach(rowEl => {
      // Find the corresponding row data object in the old state
      const rowId = rowEl.id;
      const originalRowData = state.formStructure.find(r => r.internalId === rowId);
      if (!originalRowData) return; // Skip if row data not found (shouldn't happen)

      const { columns: _ignoredColumns, ...rowMeta } = originalRowData;
      const newRowData = {
        ...rowMeta,
        internalId: rowId,
        type: "layout-row",
        columns: []
      };

      const columnElements = rowEl.querySelectorAll(".aipkit_layout_column");
      columnElements.forEach(colEl => {
        const colId = colEl.id;
        const originalColData = originalRowData.columns.find(c => c.internalId === colId);
        if (!originalColData) return; // Skip if column data not found

        const newColData = {
          internalId: colId,
          width: originalColData.width, // Preserve original width
          elements: []
        };

        const droppedElements = colEl.querySelectorAll(".aipkit_dropped_element");
        droppedElements.forEach(droppedEl => {
          const elementData = elementsById.get(droppedEl.id);

          if (elementData) {
            newColData.elements.push(elementData);
          }
        });

        newRowData.columns.push(newColData);
      });

      newStructure.push(newRowData);
    });

    // Replace the old state with the newly built, ordered state
    state.formStructure = newStructure;

    if (typeof window.aipkitForms_notifyEditorMutation === "function") {
      window.aipkitForms_notifyEditorMutation(
        document.getElementById("aipkit_ai_forms_container")
      );
    }
  }

  function aipkitForms_createNewFormElementData(type) {
    const state = window.aipkitAIFormsState;
    state.droppedElementCounter++;
    let fieldId = `field_${type.replace(/-/g, "_")}_${state.droppedElementCounter}`;
    let label;
    let helpText = "";
    let isChoice = false;

    switch (type) {
      case "text-input":
        label = "New Text Input";
        break;
      case "textarea":
        label = "New Textarea";
        break;
      case "checkbox":
      case "select":
      case "radio-button":
        isChoice = true;
        label = type === "select" ? "New Dropdown"
          : type === "radio-button" ? "New Radio Buttons" : "New Checkbox Group";
        fieldId = `${type === "radio-button" ? "radio" : type}_${state.droppedElementCounter}`;
        break;
      case "file-upload":
        label = "File Upload";
        fieldId = `file_upload_${state.droppedElementCounter}`;
        helpText = "Upload a file (txt, pdf).";
        break;
      case "image-upload":
        label = "Image Upload";
        fieldId = `image_upload_${state.droppedElementCounter}`;
        helpText = "Upload an image (jpg, png, webp).";
        break;
      default:
        return null;
    }

    const field = {
      internalId: `el-${Date.now()}-${state.droppedElementCounter}`,
      type,
      label,
    };
    if (type === "text-input" || type === "textarea") {
      field.placeholder = "";
    }
    field.fieldId = fieldId;
    field.fieldIdManuallyEdited = false;
    field.required = false;
    if (isChoice) {
      field.placeholder = type === "select" ? "Choose an option" : "";
      field.options = [
        { value: "option1", text: "Option 1" },
        { value: "option2", text: "Option 2" },
      ];
    }
    field.helpText = helpText;
    return field;
  }

  function aipkitForms_createNewLayoutData(layoutType) {
    const timestamp = Date.now();
    const count = layoutType === "2-col-50-50" ? 2
      : layoutType === "3-col-33-33-33" ? 3 : 1;
    const width = count === 3 ? "33.33%" : `${100 / count}%`;
    return {
      internalId: `row-${timestamp}`,
      type: "layout-row",
      columns: Array.from({ length: count }, (_, index) => ({
        internalId: `col-${timestamp}-${index + 1}`,
        width,
        elements: [],
      })),
    };
  }

  function aipkitForms_showEmptyDesignerPlaceholder(formsContainerElement) {
    const __ =
      typeof wp !== "undefined" && wp.i18n && wp.i18n.__
        ? wp.i18n.__
        : (text) => text;
    const designerArea = formsContainerElement?.querySelector(
      "#aipkit_ai_form_designer_area"
    );
    if (
      !designerArea ||
      designerArea.querySelector(".aipkit_layout_row, .aipkit_dropped_element")
    ) {
      return;
    }

    showDesignerPlaceholder(designerArea, __);
  }

  function aipkitForms_deleteDroppedElement(
    elementInternalId,
    formsContainerElement
  ) {
    const state = window.aipkitAIFormsState;
    if (!formsContainerElement) {
      console.error(
        "AI Forms deleteDroppedElement: formsContainerElement not provided."
      );
      return;
    }
    const designerArea = formsContainerElement.querySelector(
      "#aipkit_ai_form_designer_area"
    );
    if (!designerArea) {
      console.warn(
        "AI Forms deleteDroppedElement: Designer area not found in provided container."
      );
      return;
    }

    const elementWrapper = designerArea.querySelector(
      `#${CSS.escape(elementInternalId)}`
    );
    if (elementWrapper) {
      elementWrapper.remove();

      state.formStructure.forEach((row) => {
        row.columns.forEach((col) => {
          col.elements = col.elements.filter(
            (el) => el.internalId !== elementInternalId
          );
        });
      });

      if (typeof window.aipkitForms_updatePromptSnippets === "function") {
        window.aipkitForms_updatePromptSnippets(formsContainerElement);
      }

      if (state.selectedDesignerElementId === elementInternalId) {
        if (typeof window.aipkitForms_hideSettingsPanel === "function")
          window.aipkitForms_hideSettingsPanel(formsContainerElement);
        else
          console.error(
            "AI Forms deleteDroppedElement: hideSettingsPanel function missing."
          );
      }

      aipkitForms_showEmptyDesignerPlaceholder(formsContainerElement);

      if (typeof window.aipkitForms_notifyEditorMutation === "function") {
        window.aipkitForms_notifyEditorMutation(formsContainerElement);
      }
    }
  }

  window.aipkitForms_updateFormStructureOrder = aipkitForms_updateFormStructureOrder;
  window.aipkitForms_createNewFormElementData = aipkitForms_createNewFormElementData;
  window.aipkitForms_createNewLayoutData = aipkitForms_createNewLayoutData;
  window.aipkitForms_showEmptyDesignerPlaceholder = aipkitForms_showEmptyDesignerPlaceholder;
  window.aipkitForms_deleteDroppedElement = aipkitForms_deleteDroppedElement;
})();
