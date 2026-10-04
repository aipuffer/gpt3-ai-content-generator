/** AI Forms designer canvas: field/layout rendering, dragging and column resizing. */
(function () {
  "use strict";

  function getI18n() {
    return typeof wp !== "undefined" && wp.i18n && wp.i18n.__
      ? wp.i18n.__ : (text) => text;
  }

  function clearDropIndicators(root, kind) {
    const before = `aipkit-${kind}-drop-before`;
    const after = `aipkit-${kind}-drop-after`;
    root.querySelectorAll(`.${before}, .${after}`).forEach(element => {
      element.classList.remove(before, after);
    });
  }

  function clearDraggedElement(state) {
    if (state.draggedElement) {
      state.draggedElement.classList.remove("aipkit-row-dragging-source");
      state.draggedElement = null;
    }
  }

  function showNewFieldSettings(field, container) {
    if (field && typeof window.aipkitForms_showSettingsPanel === "function") {
      window.aipkitForms_showSettingsPanel(field.internalId, container, { focusLabel: true });
    }
  }

  /**
   * Renders a single form field element inside a specified column.
   * @param {HTMLElement} parentColumnElement The column DOM element to append the field to.
   * @param {object} elementData The data object for the element from the state.
   * @param {HTMLElement|null} referenceNode The node to insert before (for reordering).
   */
  function aipkitForms_renderFieldInColumn(
    parentColumnElement,
    elementData,
    referenceNode = null
  ) {
    const __ = getI18n();
    const escaper =
      window.aipkit_escapeHtml ||
      function (str) {
        return str;
      };

    const elementWrapper = document.createElement("div");
    elementWrapper.className = "aipkit_dropped_element";
    elementWrapper.id = elementData.internalId;
    elementWrapper.dataset.elementType = elementData.type;

    const moveLabel = __("Move field", "gpt3-ai-content-generator");
    const dragHandle = `<button type="button" class="aipkit_btn aipkit_btn-small aipkit_btn-icon aipkit_dropped_element_drag_handle" draggable="true" aria-label="${moveLabel}" title="${moveLabel}">
      <span class="dashicons dashicons-move" aria-hidden="true"></span>
    </button>`;

    let fieldHTML = "";
    const uniqueFieldIdForDOM = `${elementData.internalId}-field`;
    let helpTextHTML = "";
    if (elementData.helpText) {
      helpTextHTML = `<p class="aipkit_dropped_element_help_text">${escaper(
        elementData.helpText
      )}</p>`;
    }

    const renderFieldLabel = (tag = "label") => {
      const forAttribute = tag === "label" ? ` for="${uniqueFieldIdForDOM}"` : "";
      return `<${tag} class="aipkit_dropped_element_label"${forAttribute}>${escaper(
        elementData.label
      )} ${elementData.required ? '<span class="aipkit-required-indicator">*</span>' : ""}</${tag}>`;
    };

    switch (elementData.type) {
      case "text-input":
        fieldHTML = `${renderFieldLabel()}
                     <input type="text" id="${uniqueFieldIdForDOM}" class="aipkit_form-input aipkit_dropped_element_field" placeholder="${escaper(
          elementData.placeholder
        )}" readonly>
                    ${helpTextHTML}`;
        break;
      case "textarea":
        fieldHTML = `${renderFieldLabel()}
                     <textarea id="${uniqueFieldIdForDOM}" class="aipkit_form-input aipkit_dropped_element_field" rows="2" placeholder="${escaper(
          elementData.placeholder
        )}" readonly></textarea>
                    ${helpTextHTML}`;
        break;
      case "select":
        let optionsHTML = "";
        if (elementData.placeholder) {
          optionsHTML += `<option value="">${escaper(
            elementData.placeholder
          )}</option>`;
        }
        if (elementData.options && elementData.options.length > 0) {
          elementData.options.forEach((opt) => {
            optionsHTML += `<option value="${escaper(opt.value)}">${escaper(
              opt.text
            )}</option>`;
          });
        } else {
          optionsHTML = `<option>${__(
            "No options configured",
            "gpt3-ai-content-generator"
          )}</option>`;
        }
        fieldHTML = `${renderFieldLabel()}
                     <select id="${uniqueFieldIdForDOM}" class="aipkit_form-input aipkit_dropped_element_field" disabled>${optionsHTML}</select>
                    ${helpTextHTML}`;
        break;
      case "radio-button":
      case "checkbox":
        const isCheckbox = elementData.type === "checkbox";
        let choiceOptionsHTML = "";
        if (elementData.options && elementData.options.length > 0) {
          elementData.options.forEach((opt, index) => {
            const uniqueChoiceId = `${uniqueFieldIdForDOM}-${index}`;
            choiceOptionsHTML += `<div class="aipkit_dropped_element_radio_item">
                                    <input type="${isCheckbox ? "checkbox" : "radio"}" id="${uniqueChoiceId}" class="aipkit_dropped_element_choice_input" name="preview-${
              elementData.internalId
            }${isCheckbox ? `-${index}` : ""}" disabled>
                                    <label for="${uniqueChoiceId}">${escaper(
              opt.text
            )}</label>
                                </div>`;
          });
        } else {
          choiceOptionsHTML = `<p class="aipkit-no-options-note">${__(
            "No options configured",
            "gpt3-ai-content-generator"
          )}</p>`;
        }
        fieldHTML = `<fieldset class="aipkit_dropped_element_fieldset">
                        ${renderFieldLabel("legend")}
                        ${choiceOptionsHTML}
                    </fieldset>
                    ${helpTextHTML}`;
        break;
      case "file-upload":
      case "image-upload":
        fieldHTML = `${renderFieldLabel()}
                     <input type="file" id="${uniqueFieldIdForDOM}" class="aipkit_form-input aipkit_dropped_element_field"${elementData.type === "image-upload" ? ' accept="image/jpeg,image/png,image/webp"' : ""} disabled>
                    ${helpTextHTML}`;
        break;
      default:
        fieldHTML = `<p>${escaper(elementData.label)}</p>${helpTextHTML}`;
    }

    const settingsLabel = __("Field settings", "gpt3-ai-content-generator");
    const deleteLabel = __("Delete", "gpt3-ai-content-generator");
    const settingsButton = `<button type="button" class="aipkit_btn aipkit_btn-small aipkit_btn-icon aipkit_dropped_element_settings_btn" aria-label="${settingsLabel}" title="${settingsLabel}">
                                <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
                            </button>`;
    const deleteButton = `<button type="button" class="aipkit_btn aipkit_btn-small aipkit_btn-icon aipkit_dropped_element_delete_btn" aria-label="${deleteLabel}" title="${deleteLabel}">
                                <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                            </button>`;

    elementWrapper.innerHTML = `
        <div class="aipkit_dropped_element_content">
            ${fieldHTML}
        </div>
        <div class="aipkit_dropped_element_actions_hover">
            ${dragHandle}
            ${settingsButton}
            ${deleteButton}
        </div>
    `;

    if (referenceNode) {
      parentColumnElement.insertBefore(elementWrapper, referenceNode);
    } else {
      parentColumnElement.appendChild(elementWrapper);
    }
  }

  function aipkitForms_initializeDragAndDrop(formsContainerElement) {
    const state = window.aipkitAIFormsState;
    if (!formsContainerElement) {
      console.error("AI Forms Drag&Drop: formsContainerElement not provided.");
      return;
    }

    const palette = formsContainerElement.querySelector(
      "#aipkit_ai_form_elements_palette"
    );
    const designerArea = formsContainerElement.querySelector(
      "#aipkit_ai_form_designer_area"
    );

    if (!palette || !designerArea) {
      console.warn(
        "AI Forms Admin: Drag and drop palette or designer area not found for initDragAndDrop within provided container."
      );
      return;
    }

    if (
      palette.dataset.dragListenersAttached === "true" &&
      designerArea.dataset.dragListenersAttached === "true"
    )
      return;

    const getRowInsertionTarget = (clientY, excludedRow = null) => {
      const rows = Array.from(
        designerArea.querySelectorAll(".aipkit_layout_row")
      ).filter((row) => row !== excludedRow);

      if (!rows.length) {
        return null;
      }

      const rowBeforeCursor = rows.find((row) => {
        const rect = row.getBoundingClientRect();
        return clientY < rect.top + rect.height / 2;
      });

      return rowBeforeCursor
        ? { row: rowBeforeCursor, isAfter: false }
        : { row: rows[rows.length - 1], isAfter: true };
    };

    const showRowDropIndicator = (insertionTarget) => {
      if (!insertionTarget) {
        return;
      }

      insertionTarget.row.classList.add(
        insertionTarget.isAfter
          ? "aipkit-row-drop-after"
          : "aipkit-row-drop-before"
      );
    };

    // --- Palette Drag Listeners ---
    const draggableItems = palette.querySelectorAll(
      ".aipkit_form_element_item"
    );
    draggableItems.forEach((item) => {
      if (item.dataset.dragStartListenerAttached === "true") return;
      item.addEventListener("dragstart", (event) => {
        const paletteItem = event.currentTarget;
        paletteItem.classList.add("aipkit-dragging");
        state.draggedElement = null; // Reset element drag
        // Distinguish between dragging a layout and an element
        if (paletteItem.dataset.layoutType) {
          state.draggedLayoutType = paletteItem.dataset.layoutType;
          state.draggedElementType = null;
          event.dataTransfer.setData("text/plain", state.draggedLayoutType);
          event.dataTransfer.effectAllowed = "copy";
        } else {
          state.draggedElementType = paletteItem.dataset.elementType;
          state.draggedLayoutType = null;
          event.dataTransfer.setData("text/plain", state.draggedElementType);
          event.dataTransfer.effectAllowed = "copy";
        }
      });
      item.addEventListener("dragend", (event) => {
        event.currentTarget.classList.remove("aipkit-dragging");
        state.draggedElementType = null;
        state.draggedLayoutType = null;
        clearDropIndicators(designerArea, "row");
        designerArea.classList.remove("aipkit-drag-over");
      });
      item.dataset.dragStartListenerAttached = "true";
    });
    palette.dataset.dragListenersAttached = "true";

    // --- Designer Area Drag Listeners (for Rows and Elements) ---
    if (designerArea.dataset.dragListenersAttached !== "true") {
      // Handles dragging of existing rows OR elements
      designerArea.addEventListener("dragstart", (event) => {
        const row = event.target.closest(".aipkit_layout_row");
        const element = event.target.closest(".aipkit_dropped_element");
        const rowDragHandle = event.target.closest(
          ".aipkit_layout_row_drag_handle"
        );
        const elementDragHandle = event.target.closest(
          ".aipkit_dropped_element_drag_handle"
        );

        if (row && rowDragHandle) {
          state.draggedElement = row;
          event.dataTransfer.setData("text/row-id", row.id);
          event.dataTransfer.effectAllowed = "move";
        } else if (element && elementDragHandle) {
          state.draggedElement = element;
          event.dataTransfer.setData("text/element-id", element.id);
          event.dataTransfer.effectAllowed = "move";
        } else {
          return; // Only allow dragging from handles
        }

        state.draggedElementType = null;
        state.draggedLayoutType = null;

        setTimeout(() => {
          if (state.draggedElement)
            state.draggedElement.classList.add("aipkit-row-dragging-source");
        }, 0);
      });

      designerArea.addEventListener("dragend", () => {
        clearDropIndicators(designerArea, "row");
        clearDropIndicators(designerArea, "element");
        designerArea.classList.remove("aipkit-drag-over");
        clearDraggedElement(state);
      });

      designerArea.addEventListener("dragover", (event) => {
        event.preventDefault();
        designerArea.classList.add("aipkit-drag-over");
        if (state.draggedElement) {
          event.dataTransfer.dropEffect = "move";
        } else {
          event.dataTransfer.dropEffect = "copy";
        }

        clearDropIndicators(designerArea, "row");
        const draggedRow =
          state.draggedElement &&
          state.draggedElement.classList.contains("aipkit_layout_row")
            ? state.draggedElement
            : null;

        if (state.draggedLayoutType || draggedRow) {
          showRowDropIndicator(
            getRowInsertionTarget(event.clientY, draggedRow)
          );
        }
      });

      designerArea.addEventListener("dragleave", (event) => {
        if (!designerArea.contains(event.relatedTarget)) {
          designerArea.classList.remove("aipkit-drag-over");
          clearDropIndicators(designerArea, "row");
        }
      });

      // Main drop handler for the designer area (handles rows and layouts)
      designerArea.addEventListener("drop", (event) => {
        event.preventDefault();
        event.stopPropagation();
        designerArea.classList.remove("aipkit-drag-over");
        const draggedRow =
          state.draggedElement &&
          state.draggedElement.classList.contains("aipkit_layout_row")
            ? state.draggedElement
            : null;
        const rowInsertionTarget =
          state.draggedLayoutType || draggedRow
            ? getRowInsertionTarget(event.clientY, draggedRow)
            : null;
        clearDropIndicators(designerArea, "row");
        const placeholder = designerArea.querySelector(
          "#aipkit_form_designer_placeholder"
        );

        // --- Case 1: Dropping a NEW LAYOUT BLOCK ---
        if (state.draggedLayoutType) {
          if (placeholder) placeholder.style.display = "none";
          const newLayoutData = window.aipkitForms_createNewLayoutData(
            state.draggedLayoutType
          );
          let insertionIndex = state.formStructure.length;
          let referenceNode = null;

          if (rowInsertionTarget) {
            const targetIndex = state.formStructure.findIndex(
              (row) => row.internalId === rowInsertionTarget.row.id
            );
            if (targetIndex !== -1) {
              insertionIndex =
                targetIndex + (rowInsertionTarget.isAfter ? 1 : 0);
              referenceNode = rowInsertionTarget.isAfter
                ? rowInsertionTarget.row.nextSibling
                : rowInsertionTarget.row;
            }
          }

          state.formStructure.splice(insertionIndex, 0, newLayoutData);
          window.aipkitForms_renderLayoutRow(
            designerArea,
            newLayoutData,
            referenceNode,
            formsContainerElement
          );
          state.draggedLayoutType = null;
        }
        // --- Case 2: Re-ordering an existing ROW ---
        else if (
          state.draggedElement &&
          state.draggedElement.classList.contains("aipkit_layout_row")
        ) {
          if (rowInsertionTarget) {
            const referenceNode = rowInsertionTarget.isAfter
              ? rowInsertionTarget.row.nextSibling
              : rowInsertionTarget.row;
            designerArea.insertBefore(state.draggedElement, referenceNode);
          }
          window.aipkitForms_updateFormStructureOrder();
        }
        // --- Case 3: Fallback for dropping an ELEMENT on the main canvas (not in a column) ---
        else if (state.draggedElementType) {
          if (placeholder) placeholder.style.display = "none";
          // Create a new 1-column row for it
          const newLayoutData = window.aipkitForms_createNewLayoutData("1-col");
          const newElementData = window.aipkitForms_createNewFormElementData(
            state.draggedElementType
          );
          newLayoutData.columns[0].elements.push(newElementData); // Add element to the new column's state
          state.formStructure.push(newLayoutData); // Add new row to state
          window.aipkitForms_renderLayoutRow(
            designerArea,
            newLayoutData,
            null,
            formsContainerElement
          ); // Render the entire new row
          showNewFieldSettings(newElementData, formsContainerElement);
          state.draggedElementType = null;
        }

        // Reset state
        clearDraggedElement(state);
        if (typeof window.aipkitForms_updatePromptSnippets === "function") {
          window.aipkitForms_updatePromptSnippets(formsContainerElement);
        }
        if (typeof window.aipkitForms_notifyEditorMutation === "function") {
          window.aipkitForms_notifyEditorMutation(formsContainerElement);
        }
      });
      designerArea.dataset.dragListenersAttached = "true";
    }
  }

  /**
   * Renders a layout row and its columns into the designer area.
   * @param {HTMLElement} designerArea The main designer DOM element.
   * @param {object} rowData The data object for the row from the state.
   * @param {HTMLElement|null} referenceNode The node to insert before (for reordering).
   * @param {HTMLElement} formsContainerElement The top-level container for the forms module.
   */
  function aipkitForms_renderLayoutRow(
    designerArea,
    rowData,
    referenceNode,
    formsContainerElement
  ) {
    const __ = getI18n();
    const rowWrapper = document.createElement("div");
    rowWrapper.className = "aipkit_layout_row";
    rowWrapper.id = rowData.internalId;

    const moveLayoutLabel = __("Move layout", "gpt3-ai-content-generator");
    const deleteLayoutLabel = __("Delete layout", "gpt3-ai-content-generator");
    const controlsHTML = `
            <div class="aipkit_layout_row_controls">
                <button type="button" class="aipkit_layout_row_action aipkit_layout_row_drag_handle" title="${moveLayoutLabel}" aria-label="${moveLayoutLabel}" draggable="true">
                    <span class="dashicons dashicons-move" aria-hidden="true"></span>
                </button>
                <button type="button" class="aipkit_layout_row_action aipkit_layout_row_delete_btn" title="${deleteLayoutLabel}" aria-label="${deleteLayoutLabel}">
                    <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                </button>
            </div>
        `;

    const columnsContainer = document.createElement("div");
    columnsContainer.className = "aipkit_layout_row_columns_container";

    const normalizeColumnWidths = () => {
      const fallbackWidth = 100 / Math.max(rowData.columns.length, 1);
      const parsedWidths = rowData.columns.map((column) => {
        const parsedWidth = Number.parseFloat(column.width);
        return Number.isFinite(parsedWidth) && parsedWidth > 0
          ? parsedWidth
          : fallbackWidth;
      });
      const totalWidth = parsedWidths.reduce((sum, width) => sum + width, 0);

      return parsedWidths.map((width) => (width / totalWidth) * 100);
    };

    const formatColumnWidth = (width) => `${Number(width.toFixed(2))}%`;

    const getMinimumAdjacentWidth = (pairWidth) =>
      Math.min(15, Math.max(5, pairWidth / 2 - 1));

    const applyColumnWidths = (widths) => {
      columnsContainer.style.gridTemplateColumns = widths
        .map((width) => `${width}fr`)
        .join(" ");

      let cumulativeWidth = 0;
      columnsContainer
        .querySelectorAll(".aipkit_layout_column_resize_handle")
        .forEach((handle, index) => {
          const pairWidth = widths[index] + widths[index + 1];
          const minimumWidth = getMinimumAdjacentWidth(pairWidth);
          cumulativeWidth += widths[index];
          handle.style.left = `${cumulativeWidth}%`;
          handle.setAttribute("aria-valuemin", String(Math.round(minimumWidth)));
          handle.setAttribute(
            "aria-valuemax",
            String(Math.round(pairWidth - minimumWidth))
          );
          handle.setAttribute("aria-valuenow", String(Math.round(widths[index])));
        });
    };

    const persistColumnWidths = (widths) => {
      const activeRowData = window.aipkitAIFormsState?.formStructure?.find(
        (row) => row.internalId === rowWrapper.id
      );
      const activeColumns = activeRowData?.columns || rowData.columns;

      widths.forEach((width, index) => {
        if (activeColumns[index]) {
          activeColumns[index].width = formatColumnWidth(width);
        }
      });
      applyColumnWidths(widths);
    };

    const notifyLayoutResize = () => {
      if (typeof window.aipkitForms_notifyEditorMutation === "function") {
        window.aipkitForms_notifyEditorMutation(formsContainerElement);
      }
    };

    // Create columns and attach their drop listeners
    rowData.columns.forEach((columnData) => {
      const columnDiv = document.createElement("div");
      columnDiv.className = "aipkit_layout_column";
      columnDiv.id = columnData.internalId;

      const emptyColumnHint = document.createElement("div");
      emptyColumnHint.className = "aipkit_layout_column_empty";
      emptyColumnHint.setAttribute("aria-hidden", "true");

      const emptyColumnIcon = document.createElement("span");
      emptyColumnIcon.className = "aipkit_layout_column_empty_icon";
      emptyColumnIcon.textContent = "+";

      const emptyColumnText = document.createElement("span");
      emptyColumnText.className = "aipkit_layout_column_empty_text";
      emptyColumnText.textContent = __(
        "Drag an element here",
        "gpt3-ai-content-generator"
      );

      emptyColumnHint.appendChild(emptyColumnIcon);
      emptyColumnHint.appendChild(emptyColumnText);
      columnDiv.appendChild(emptyColumnHint);

      // Make each column a drop zone for ELEMENTS
      attachColumnDropListeners(columnDiv, formsContainerElement);

      // Render any existing elements within this column
      columnData.elements.forEach((elementData) => {
        window.aipkitForms_renderFieldInColumn(columnDiv, elementData);
      });

      columnsContainer.appendChild(columnDiv);
    });

    let columnWidths = normalizeColumnWidths();

    for (
      let dividerIndex = 0;
      dividerIndex < rowData.columns.length - 1;
      dividerIndex += 1
    ) {
      const resizeLabel = __(
        "Resize adjacent columns",
        "gpt3-ai-content-generator"
      );
      const resizeHandle = document.createElement("button");
      resizeHandle.type = "button";
      resizeHandle.className = "aipkit_layout_column_resize_handle";
      resizeHandle.setAttribute("title", resizeLabel);
      resizeHandle.setAttribute("aria-label", resizeLabel);
      resizeHandle.setAttribute("role", "separator");
      resizeHandle.setAttribute("aria-orientation", "vertical");
      resizeHandle.innerHTML = `
        <span class="aipkit_layout_column_resize_line" aria-hidden="true"></span>
        <span class="aipkit_layout_column_resize_grip" aria-hidden="true">↔</span>
      `;

      const resizeAdjacentColumns = (delta) => {
        const pairWidth = columnWidths[dividerIndex] + columnWidths[dividerIndex + 1];
        const minimumWidth = getMinimumAdjacentWidth(pairWidth);
        const nextLeftWidth = Math.min(
          pairWidth - minimumWidth,
          Math.max(minimumWidth, columnWidths[dividerIndex] + delta)
        );
        const appliedDelta = nextLeftWidth - columnWidths[dividerIndex];

        columnWidths[dividerIndex] = nextLeftWidth;
        columnWidths[dividerIndex + 1] -= appliedDelta;
        persistColumnWidths(columnWidths);
      };

      resizeHandle.addEventListener("pointerdown", (event) => {
        if (event.button !== 0) {
          return;
        }

        event.preventDefault();
        event.stopPropagation();
        const startX = event.clientX;
        const initialLeftWidth = columnWidths[dividerIndex];
        const initialRightWidth = columnWidths[dividerIndex + 1];
        const availableWidth = Math.max(
          columnsContainer.getBoundingClientRect().width -
            8 * (rowData.columns.length - 1),
          1
        );
        const previousUserSelect = document.body.style.userSelect;
        const previousCursor = document.body.style.cursor;

        resizeHandle.classList.add("is-resizing");
        document.body.style.userSelect = "none";
        document.body.style.cursor = "col-resize";
        if (typeof resizeHandle.setPointerCapture === "function") {
          resizeHandle.setPointerCapture(event.pointerId);
        }

        const handlePointerMove = (moveEvent) => {
          const deltaPercent =
            ((moveEvent.clientX - startX) / availableWidth) * 100;
          columnWidths[dividerIndex] = initialLeftWidth;
          columnWidths[dividerIndex + 1] = initialRightWidth;
          resizeAdjacentColumns(deltaPercent);
        };

        const finishResize = () => {
          resizeHandle.classList.remove("is-resizing");
          document.body.style.userSelect = previousUserSelect;
          document.body.style.cursor = previousCursor;
          resizeHandle.removeEventListener("pointermove", handlePointerMove);
          resizeHandle.removeEventListener("pointerup", finishResize);
          resizeHandle.removeEventListener("pointercancel", finishResize);
          notifyLayoutResize();
        };

        resizeHandle.addEventListener("pointermove", handlePointerMove);
        resizeHandle.addEventListener("pointerup", finishResize);
        resizeHandle.addEventListener("pointercancel", finishResize);
      });

      resizeHandle.addEventListener("keydown", (event) => {
        if (event.key !== "ArrowLeft" && event.key !== "ArrowRight") {
          return;
        }
        event.preventDefault();
        resizeAdjacentColumns(event.key === "ArrowLeft" ? -2 : 2);
        notifyLayoutResize();
      });

      columnsContainer.appendChild(resizeHandle);
    }

    applyColumnWidths(columnWidths);

    rowWrapper.innerHTML = controlsHTML;
    rowWrapper.appendChild(columnsContainer);

    if (referenceNode) {
      designerArea.insertBefore(rowWrapper, referenceNode);
    } else {
      designerArea.appendChild(rowWrapper);
    }

    if (typeof window.aipkitForms_afterRenderLayoutRow === "function") {
      window.aipkitForms_afterRenderLayoutRow(
        rowWrapper,
        rowData,
        formsContainerElement
      );
    }
  }

  /**
   * Attaches drop listeners specifically to a column element.
   * @param {HTMLElement} columnElement The column div to make a drop zone.
   * @param {HTMLElement} formsContainerElement The top-level container for the forms module.
   */
  function attachColumnDropListeners(columnElement, formsContainerElement) {
    const state = window.aipkitAIFormsState;

    const getElementDropReference = (event) => {
      const targetElement = event.target.closest(".aipkit_dropped_element");
      if (
        !targetElement ||
        targetElement.parentElement !== columnElement ||
        targetElement === state.draggedElement
      ) {
        return null;
      }

      const targetRect = targetElement.getBoundingClientRect();
      return event.clientY > targetRect.top + targetRect.height / 2
        ? targetElement.nextElementSibling
        : targetElement;
    };

    columnElement.addEventListener("dragover", (event) => {
      const isElementDrag =
        state.draggedElementType ||
        (state.draggedElement &&
          state.draggedElement.classList.contains("aipkit_dropped_element"));

      if (!isElementDrag) {
        clearDropIndicators(columnElement, "element");
        columnElement.classList.remove("aipkit-drag-over");
        return;
      }

      event.preventDefault();
      event.stopPropagation();
      columnElement.classList.add("aipkit-drag-over");
      event.dataTransfer.dropEffect = state.draggedElement ? "move" : "copy";

      clearDropIndicators(columnElement, "element");
      const targetElement = event.target.closest(".aipkit_dropped_element");
      if (
        targetElement &&
        targetElement.parentElement === columnElement &&
        targetElement !== state.draggedElement
      ) {
        const targetRect = targetElement.getBoundingClientRect();
        targetElement.classList.add(
          event.clientY > targetRect.top + targetRect.height / 2
            ? "aipkit-element-drop-after"
            : "aipkit-element-drop-before"
        );
      }
    });

    columnElement.addEventListener("dragleave", (event) => {
      if (!columnElement.contains(event.relatedTarget)) {
        clearDropIndicators(columnElement, "element");
        columnElement.classList.remove("aipkit-drag-over");
      }
    });

    columnElement.addEventListener("drop", (event) => {
      event.preventDefault(); // Always prevent default browser action
      columnElement.classList.remove("aipkit-drag-over");

      // Check if this handler can process the drop (i.e., we are dropping a field element)
      if (
        state.draggedElementType ||
        (state.draggedElement &&
          state.draggedElement.classList.contains("aipkit_dropped_element"))
      ) {
        event.stopPropagation(); // This drop is for this column, stop it bubbling to the designer canvas

        const targetColumnId = columnElement.id;
        const referenceNode = getElementDropReference(event);
        clearDropIndicators(columnElement, "element");

        // Case 1: Dropping a NEW form element from the palette
        if (state.draggedElementType) {
          const newElementData = window.aipkitForms_createNewFormElementData(
            state.draggedElementType
          );

          // Find the target column in the state and add the new element
          state.formStructure.forEach((row) => {
            const targetCol = row.columns.find(
              (col) => col.internalId === targetColumnId
            );
            if (targetCol) {
              targetCol.elements.push(newElementData);
            }
          });

          window.aipkitForms_renderFieldInColumn(
            columnElement,
            newElementData,
            referenceNode,
            formsContainerElement
          );
          window.aipkitForms_updateFormStructureOrder();
          showNewFieldSettings(newElementData, formsContainerElement);
        }
        // Case 2: Moving an EXISTING form element
        else if (state.draggedElement) {
          columnElement.insertBefore(state.draggedElement, referenceNode);
          // Rebuild the state from the DOM to reflect the move
          window.aipkitForms_updateFormStructureOrder();
        }

        // Reset drag state since we've handled it
        state.draggedElementType = null;
        clearDraggedElement(state);
      }
      // If we are dragging a layout block (state.draggedLayoutType is set), this handler
      // does nothing and allows the event to bubble up to the designerArea's drop handler.
    });
  }

  window.aipkitForms_renderFieldInColumn = aipkitForms_renderFieldInColumn;
  // Preserve the existing public renderer compatibility hook.
  window.aipkitForms_renderDroppedElement = aipkitForms_renderFieldInColumn;
  window.aipkitForms_initializeDragAndDrop = aipkitForms_initializeDragAndDrop;
  window.aipkitForms_renderLayoutRow = aipkitForms_renderLayoutRow;
})();
