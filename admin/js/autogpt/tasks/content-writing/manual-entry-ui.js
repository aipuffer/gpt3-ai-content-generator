/**
 * AIPKit AutoGPT - Content Writing Manual Entry
 * Keeps the Batch Editor as the primary workspace and provides Quick Paste as
 * a synchronized alternate view of the same saved topic list.
 */
(function () {
  "use strict";

  const MAX_ROWS = 100;
  const BASE_STARTER_ROWS = 1;
  const MIN_ROWS = 1;
  const sanitizeValue = (value) =>
    String(value || "")
      .replace(/\|/g, " ")
      .replace(/\r?\n/g, " ")
      .trim();

  const normalizeSchedule = (value) => {
    const trimmed = String(value || "").trim();
    if (!trimmed) {
      return { value: "", raw: "" };
    }

    const isoMatch = trimmed.match(
      /^(\d{4}-\d{2}-\d{2})(?:[T\s])(\d{2}:\d{2})(?::\d{2})?$/
    );

    if (isoMatch) {
      return { value: `${isoMatch[1]}T${isoMatch[2]}`, raw: "" };
    }

    return { value: "", raw: trimmed };
  };

  const formatSchedule = (value) =>
    value ? String(value).replace("T", " ").trim() : "";

  const parseLine = (line) => {
    const parts = String(line || "")
      .split("|")
      .map((part) => part.trim());

    return {
      topic: parts[0] || "",
      keywords: parts[1] || "",
      category: parts[2] || "",
      author: parts[3] || "",
      type: parts[4] || "",
      schedule: parts[5] || "",
    };
  };

  const serializeRowData = (rowData) => {
    const normalizedSchedule = normalizeSchedule(rowData?.schedule || "");
    const scheduleValue = normalizedSchedule.value
      ? formatSchedule(normalizedSchedule.value)
      : normalizedSchedule.raw || "";
    const parts = [
      sanitizeValue(rowData?.topic || ""),
      sanitizeValue(rowData?.keywords || ""),
      sanitizeValue(rowData?.category || ""),
      sanitizeValue(rowData?.author || ""),
      sanitizeValue(rowData?.type || ""),
      sanitizeValue(scheduleValue),
    ];

    let lastIndex = -1;
    parts.forEach((part, index) => {
      if (part) {
        lastIndex = index;
      }
    });

    if (lastIndex === -1) {
      return "";
    }

    return parts.slice(0, lastIndex + 1).join(" | ");
  };

  const getStarterRowCount = () => BASE_STARTER_ROWS;

  function resolveManualEntryShell(scopeElement) {
    const root =
      scopeElement || document.getElementById("aipkit_autogpt_container");
    if (!root) {
      return null;
    }

    if (
      root.matches &&
      root.matches("#aipkit_task_cw_input_mode_bulk .aipkit_cw_task_entry_shell")
    ) {
      return root;
    }

    return root.querySelector(
      "#aipkit_task_cw_input_mode_bulk .aipkit_cw_task_entry_shell"
    );
  }

  function aipkit_initAutogptManualEntryUi(scopeElement) {
    const taskEntryShell = resolveManualEntryShell(scopeElement);
    if (!taskEntryShell) {
      return;
    }

    if (taskEntryShell._aipkitManualEntryUi) {
      taskEntryShell._aipkitManualEntryUi.refresh();
      return;
    }

    const rowsContainer = taskEntryShell.querySelector("[data-aipkit-bulk-rows]");
    const template = taskEntryShell.querySelector("#aipkit_task_cw_bulk_row_template");
    const textarea = taskEntryShell.querySelector("#aipkit_task_cw_content_title_bulk");
    const taskForm = taskEntryShell.closest("form");
    const batchToggle =
      taskForm?.querySelector("[data-aipkit-batch-editor-toggle]") || null;
    const pasteToggle =
      taskForm?.querySelector("[data-aipkit-paste-topics-toggle]") || null;
    const pastePanel = taskEntryShell.querySelector("[data-aipkit-paste-topics-panel]");
    const pasteInput = taskEntryShell.querySelector("[data-aipkit-paste-topics-input]");
    const pasteSample = taskEntryShell.querySelector("[data-aipkit-paste-topics-sample]");
    const bulkEditor = taskEntryShell.querySelector("[data-aipkit-bulk-editor]");
    const addTopicButton = taskEntryShell.querySelector("[data-aipkit-add-topic]");
    if (!rowsContainer || !template || !textarea) {
      return;
    }

    let overflowLines = [];
    let pasteSyncTimer = null;
    let activeView =
      taskEntryShell.dataset.taskEntryView === "paste" ? "paste" : "batch";

    const getNonEmptyLines = () =>
      String(textarea.value || "")
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter(Boolean);

    const getRows = () =>
      Array.from(rowsContainer.querySelectorAll("[data-aipkit-bulk-row]"));

    const getRowField = (row, key) =>
      row ? row.querySelector(`[data-bulk-field="${key}"]`) : null;

    const setRowDetailsState = (row, isOpen) => {
      if (!row) {
        return;
      }
      row.classList.toggle("is-details-open", isOpen);
      const toggle = row.querySelector(".aipkit_cw_bulk_toggle_row_details");
      if (toggle) {
        toggle.setAttribute("aria-expanded", isOpen ? "true" : "false");
      }
    };

    const getRowData = (row) => {
      const topic = sanitizeValue(getRowField(row, "topic")?.value || "");
      const keywords = sanitizeValue(getRowField(row, "keywords")?.value || "");
      const category = sanitizeValue(getRowField(row, "category")?.value || "");
      const author = sanitizeValue(getRowField(row, "author")?.value || "");
      const type = sanitizeValue(getRowField(row, "type")?.value || "");
      let schedule = getRowField(row, "schedule")?.value || "";

      if (schedule) {
        schedule = formatSchedule(schedule);
        row.dataset.rawSchedule = "";
      } else if (row.dataset.rawSchedule) {
        schedule = row.dataset.rawSchedule;
      }

      return { topic, keywords, category, author, type, schedule };
    };

    const syncRowNumbers = () => {
      getRows().forEach((row, index) => {
        const number = row.querySelector("[data-aipkit-topic-row-number]");
        if (number) {
          number.textContent = String(index + 1);
        }
      });
    };

    const setRowData = (row, data) => {
      if (!row || !data) {
        return;
      }

      ["topic", "keywords", "category", "author", "type"].forEach((key) => {
        const field = getRowField(row, key);
        if (field) {
          field.value = data[key] || "";
        }
      });

      const scheduleField = getRowField(row, "schedule");
      if (scheduleField) {
        const normalized = normalizeSchedule(data.schedule || "");
        scheduleField.value = normalized.value;
        row.dataset.rawSchedule = normalized.raw || "";
      }
    };

    const createRow = (data = {}) => {
      const fragment = document.importNode(template.content, true);
      const row = fragment.querySelector("[data-aipkit-bulk-row]");
      if (!row) {
        return null;
      }
      setRowData(row, data);
      setRowDetailsState(row, false);
      return row;
    };

    const syncRemoveButtons = () => {
      const rows = getRows();
      rows.forEach((row) => {
        const button = row.querySelector(".aipkit_cw_bulk_remove_row");
        if (!button) {
          return;
        }
        const isOnlyEmptyRow =
          rows.length === 1 && !serializeRowData(getRowData(row));
        button.hidden = isOnlyEmptyRow;
        button.disabled = isOnlyEmptyRow;
        button.setAttribute(
          "aria-disabled",
          isOnlyEmptyRow ? "true" : "false"
        );
      });
      syncRowNumbers();
    };

    const ensureRows = (targetCount) => {
      const rows = getRows();
      if (rows.length >= targetCount) {
        return;
      }
      const needed = targetCount - rows.length;
      for (let i = 0; i < needed; i += 1) {
        const row = createRow();
        if (row) {
          rowsContainer.appendChild(row);
        }
      }
    };

    const ensureMinimumRows = () => ensureRows(MIN_ROWS);

    const ensureStarterRows = () => ensureRows(getStarterRowCount());

    const updateTaskEntryUi = () => {
      const isPasteView = activeView === "paste";
      const currentTaskType =
        taskForm?.querySelector("#aipkit_automated_task_type")?.value || "";
      const isManualTopicTask = currentTaskType === "content_writing_bulk";
      const isPasteActive = isManualTopicTask && isPasteView;
      const isBatchActive = isManualTopicTask && !isPasteView;
      taskEntryShell.dataset.taskEntryView = activeView;
      if (pastePanel) {
        pastePanel.hidden = !isPasteView;
      }
      if (bulkEditor) {
        bulkEditor.hidden = isPasteView;
      }
      if (pasteToggle) {
        pasteToggle.setAttribute("aria-expanded", isPasteActive ? "true" : "false");
        pasteToggle.setAttribute("aria-pressed", isPasteActive ? "true" : "false");
        pasteToggle.classList.toggle("is-active", isPasteActive);
      }
      if (batchToggle) {
        batchToggle.setAttribute("aria-pressed", isBatchActive ? "true" : "false");
        batchToggle.classList.toggle("is-active", isBatchActive);
      }
      if (typeof window.aipkit_syncAutogptStageHeights === "function") {
        window.aipkit_syncAutogptStageHeights();
      }
    };

    const buildLines = () => {
      const lines = [];
      getRows().forEach((row) => {
        const line = serializeRowData(getRowData(row));
        if (line) {
          lines.push(line);
        }
      });
      return [...lines, ...overflowLines].join("\n");
    };

    const updateTextarea = () => {
      if (!textarea) {
        return;
      }
      textarea.value = buildLines();
      textarea.dispatchEvent(new Event("change", { bubbles: true }));
      updateTaskEntryUi();
    };

    const renderFromTextarea = () => {
      const lines = getNonEmptyLines();
      rowsContainer.innerHTML = "";
      overflowLines = lines.slice(MAX_ROWS);

      if (!lines.length) {
        overflowLines = [];
        ensureStarterRows();
        syncRemoveButtons();
        updateTaskEntryUi();
        return;
      }

      lines.slice(0, MAX_ROWS).forEach((line) => {
        const row = createRow(parseLine(line));
        if (row) {
          rowsContainer.appendChild(row);
        }
      });

      ensureMinimumRows();
      syncRemoveButtons();
      updateTaskEntryUi();
    };

    rowsContainer.addEventListener("input", (event) => {
      const target = event.target;
      if (!target || !target.closest("[data-aipkit-bulk-row]")) {
        return;
      }
      updateTextarea();
      syncRemoveButtons();
    });

    rowsContainer.addEventListener("change", (event) => {
      const target = event.target;
      if (!target || !target.closest("[data-aipkit-bulk-row]")) {
        return;
      }
      updateTextarea();
      syncRemoveButtons();
    });

    rowsContainer.addEventListener("click", (event) => {
      const detailsButton = event.target.closest(".aipkit_cw_bulk_toggle_row_details");
      if (detailsButton) {
        const row = detailsButton.closest("[data-aipkit-bulk-row]");
        if (!row) {
          return;
        }
        event.preventDefault();
        setRowDetailsState(row, !row.classList.contains("is-details-open"));
        return;
      }

      const removeButton = event.target.closest(".aipkit_cw_bulk_remove_row");
      if (!removeButton) {
        return;
      }

      const row = removeButton.closest("[data-aipkit-bulk-row]");
      if (!row) {
        return;
      }

      if (removeButton.disabled) {
        return;
      }

      row.remove();
      ensureMinimumRows();
      updateTextarea();
      if (overflowLines.length) {
        renderFromTextarea();
      }
      syncRemoveButtons();
    });

    addTopicButton?.addEventListener("click", () => {
      const rows = getRows();
      let targetRow = rows[rows.length - 1] || null;
      if (
        targetRow &&
        String(getRowData(targetRow).topic || "").trim() &&
        rows.length < MAX_ROWS
      ) {
        targetRow = createRow();
        if (targetRow) {
          rowsContainer.appendChild(targetRow);
        }
      }
      ensureMinimumRows();
      syncRemoveButtons();
      const finalRows = getRows();
      const rowToFocus = targetRow || finalRows[finalRows.length - 1];
      getRowField(rowToFocus, "topic")?.focus();
    });

    const setPastePanelOpen = (isOpen) => {
      if (!pastePanel) return;
      activeView = isOpen ? "paste" : "batch";
      updateTaskEntryUi();
      if (isOpen) {
        window.requestAnimationFrame(() => pasteInput?.focus());
      }
    };

    if (pasteToggle && !pasteToggle.dataset.entryView) {
      pasteToggle.addEventListener("click", () => {
        setPastePanelOpen(Boolean(pastePanel?.hidden));
      });
    }

    pasteInput?.addEventListener("input", () => {
      window.clearTimeout(pasteSyncTimer);
      pasteSyncTimer = window.setTimeout(() => {
        renderFromTextarea();
        textarea.dispatchEvent(new Event("change", { bubbles: true }));
      }, 150);
    });

    pasteSample?.addEventListener("click", () => {
      if (!pasteInput) return;
      const sample =
        "How to frost cupcakes | frosting, dessert | 1 | admin | post | 2026-08-01 09:00";
      const current = String(pasteInput.value || "").trimEnd();
      pasteInput.value = current ? `${current}\n${sample}` : sample;
      pasteInput.dispatchEvent(new Event("input", { bubbles: true }));
      pasteInput.focus();
      pasteInput.setSelectionRange(pasteInput.value.length, pasteInput.value.length);
    });

    taskEntryShell._aipkitManualEntryUi = {
      refresh() {
        renderFromTextarea();
      },
      setView(view) {
        setPastePanelOpen(view === "paste");
        textarea.dispatchEvent(new Event("change", { bubbles: true }));
      },
    };

    renderFromTextarea();
  }

  function aipkit_refreshAutogptManualEntryUi(scopeElement) {
    const taskEntryShell = resolveManualEntryShell(scopeElement);
    if (taskEntryShell && taskEntryShell._aipkitManualEntryUi) {
      taskEntryShell._aipkitManualEntryUi.refresh();
      return;
    }
    aipkit_initAutogptManualEntryUi(scopeElement);
  }

  function aipkit_setAutogptManualEntryView(view, scopeElement) {
    const taskEntryShell = resolveManualEntryShell(scopeElement);
    if (!taskEntryShell) {
      return;
    }
    if (!taskEntryShell._aipkitManualEntryUi) {
      aipkit_initAutogptManualEntryUi(taskEntryShell);
    }
    taskEntryShell._aipkitManualEntryUi?.setView(view);
  }

  window.aipkit_initAutogptManualEntryUi = aipkit_initAutogptManualEntryUi;
  window.aipkit_refreshAutogptManualEntryUi = aipkit_refreshAutogptManualEntryUi;
  window.aipkit_setAutogptManualEntryView =
    aipkit_setAutogptManualEntryView;
})();
