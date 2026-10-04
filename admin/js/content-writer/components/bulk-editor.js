/**
 * AIPKit Content Writer - Manual Entry
 * Provides single-draft, batch-editor, and quick-paste views while keeping
 * the shared bulk textarea as the source of truth for generation.
 */
(function () {
  "use strict";

  const __ = window.wp?.i18n?.__ || ((text) => text);
  const _n =
    window.wp?.i18n?._n ||
    ((singular, plural, count) => (count === 1 ? singular : plural));
  const sprintf =
    window.wp?.i18n?.sprintf ||
    ((format, ...values) =>
      values.reduce(
        (output, value, index) =>
          output.replace(
            new RegExp(`%${index + 1}\\$[ds]`, "g"),
            String(value)
          ),
        format
      ));
  const MAX_ROWS = 100;
  const MIN_ROWS = 1;

  const sanitizeValue = (value, options = {}) => {
    const shouldTrim = options.trim !== false;

    const normalized = String(value || "")
      .replace(/\|/g, " ")
      .replace(/\r?\n/g, " ");

    return shouldTrim ? normalized.trim() : normalized;
  };

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
    value ? value.replace("T", " ").trim() : "";

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

  const serializeRowData = (rowData, options = {}) => {
    const shouldTrim = options.trim !== false;
    const normalizedSchedule = normalizeSchedule(rowData?.schedule || "");
    const scheduleValue = normalizedSchedule.value
      ? formatSchedule(normalizedSchedule.value)
      : normalizedSchedule.raw || "";
    const parts = [
      sanitizeValue(rowData?.topic || "", { trim: shouldTrim }),
      sanitizeValue(rowData?.keywords || "", { trim: shouldTrim }),
      sanitizeValue(rowData?.category || "", { trim: shouldTrim }),
      sanitizeValue(rowData?.author || "", { trim: shouldTrim }),
      sanitizeValue(rowData?.type || "", { trim: shouldTrim }),
      sanitizeValue(scheduleValue, { trim: shouldTrim }),
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

  function aipkit_initContentWriterBulkEditor(scopeElement) {
    const container =
      scopeElement || document.getElementById("aipkit_content_writer_container");
    if (!container) return;

    const editor = container.querySelector("[data-aipkit-bulk-editor]");
    if (!editor || editor.dataset.bulkEditorAttached === "true") return;

    const taskEntryShell = container.querySelector(".aipkit_cw_task_entry_shell");
    const taskEntryPanelRoot = taskEntryShell?.closest(
      '[data-aipkit-bulk-source-panel="task"]'
    );
    const rowsContainer = editor.querySelector("[data-aipkit-bulk-rows]");
    const template = editor.querySelector("#aipkit_cw_bulk_row_template");
    const textarea = taskEntryShell?.querySelector("#aipkit_cw_bulk_topics");

    const singlePanel = taskEntryShell?.querySelector(
      '[data-aipkit-task-entry-panel="single"]'
    );
    const batchPanel = taskEntryShell?.querySelector(
      '[data-aipkit-task-entry-panel="batch"]'
    );
    const pastePanel = taskEntryShell?.querySelector(
      '[data-aipkit-task-entry-panel="paste"]'
    );
    const entryButtons = taskEntryShell
      ? Array.from(
          taskEntryShell.querySelectorAll("[data-aipkit-task-entry-tab]")
        )
      : [];
    const batchCount = taskEntryShell?.querySelector(
      "[data-aipkit-task-entry-batch-count]"
    );
    const batchTopicCount = taskEntryShell?.querySelector(
      "[data-aipkit-batch-topic-count]"
    );
    const quickPasteValidation = taskEntryShell?.querySelector(
      "[data-aipkit-quick-paste-validation]"
    );
    const pasteSample = taskEntryShell?.querySelector(
      "[data-aipkit-paste-topics-sample]"
    );
    const addRowButton = editor.querySelector("[data-aipkit-bulk-add-row]");
    const forcedNote = taskEntryShell?.querySelector(
      "[data-aipkit-task-entry-note]"
    );
    const modeDescription = taskEntryShell?.querySelector(
      "[data-aipkit-task-entry-mode-desc]"
    );

    const singleFields = {
      topic: taskEntryShell?.querySelector("#aipkit_cw_single_compose_topic"),
      keywords: taskEntryShell?.querySelector(
        "#aipkit_cw_single_compose_keywords"
      ),
    };
    const keywordEditor = taskEntryShell?.querySelector(
      "[data-aipkit-cw-keyword-editor]"
    );
    const keywordChipList = taskEntryShell?.querySelector(
      "[data-aipkit-cw-keyword-chip-list]"
    );
    const keywordEntry = taskEntryShell?.querySelector(
      "#aipkit_cw_single_compose_keyword_entry"
    );

    if (!rowsContainer || !template || !textarea) return;

    let isSyncingTextarea = false;
    let isSyncingSingle = false;
    let pasteSyncTimer;
    let manualEntryView = "single";
    let overflowLines = [];
    let quickPasteValidationState = {
      isValid: true,
      message: "",
      issues: [],
    };
    const modeDescriptions = {
      single: __(
        "Focus on one article with a single topic and keyword set.",
        "gpt3-ai-content-generator"
      ),
      batch: __(
        "Build a queue row by row and fine-tune each item before generation.",
        "gpt3-ai-content-generator"
      ),
      paste: __(
        "Paste one topic per line. Separate optional fields with a vertical bar.",
        "gpt3-ai-content-generator"
      ),
    };

    const getNonEmptyLines = () =>
      String(textarea.value || "")
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter(Boolean);

    const getTopicLines = () =>
      getNonEmptyLines().filter((line) => Boolean(parseLine(line).topic));

    const getTopicCount = () => getTopicLines().length;
    const getPopulatedLineCount = () => getNonEmptyLines().length;
    const hasPerItemMetadata = () =>
      getNonEmptyLines().some((line) => {
        const parsed = parseLine(line);
        return Boolean(
          parsed.category ||
            parsed.author ||
            parsed.type ||
            parsed.schedule
        );
      });

    const getRows = () =>
      Array.from(rowsContainer.querySelectorAll("[data-aipkit-bulk-row]"));

    const formatTopicCount = (count) =>
      sprintf(
        _n(
          "%1$d topic",
          "%1$d topics",
          count,
          "gpt3-ai-content-generator"
        ),
        count
      );

    const validateQuickPaste = () => {
      const rawLines = String(textarea.value || "").split(/\r?\n/);
      const issues = [];
      let populatedLineCount = 0;

      rawLines.forEach((rawLine, index) => {
        if (!rawLine.trim()) {
          return;
        }

        populatedLineCount += 1;
        const parts = rawLine.split("|").map((part) => part.trim());
        const parsed = parseLine(rawLine);
        const lineNumber = index + 1;

        if (!parsed.topic) {
          issues.push(
            sprintf(
              __(
                "Line %1$d: Add a topic before the first separator.",
                "gpt3-ai-content-generator"
              ),
              lineNumber
            )
          );
        }
        if (parts.length > 6) {
          issues.push(
            sprintf(
              __(
                "Line %1$d: Remove the extra fields after the schedule.",
                "gpt3-ai-content-generator"
              ),
              lineNumber
            )
          );
        }
      });

      if (populatedLineCount > MAX_ROWS) {
        issues.unshift(
          sprintf(
            __(
              "Quick paste supports up to %1$d lines. Remove %2$d before generating.",
              "gpt3-ai-content-generator"
            ),
            MAX_ROWS,
            populatedLineCount - MAX_ROWS
          )
        );
      }

      return {
        isValid: issues.length === 0,
        message: issues.length
          ? __(
              "Fix the listed Quick paste lines before generating.",
              "gpt3-ai-content-generator"
            )
          : "",
        issues,
      };
    };

    const renderQuickPasteSummary = () => {
      const topicCount = getTopicCount();
      const countLabel = formatTopicCount(topicCount);

      if (batchTopicCount) {
        batchTopicCount.textContent = countLabel;
      }
      quickPasteValidationState = validateQuickPaste();
      quickPasteValidation?.classList.remove("is-topic-error");
      textarea.setAttribute(
        "aria-invalid",
        quickPasteValidationState.isValid ? "false" : "true"
      );

      if (!quickPasteValidation) {
        return;
      }

      quickPasteValidation.replaceChildren();
      quickPasteValidation.hidden = quickPasteValidationState.isValid;
      if (quickPasteValidationState.isValid) {
        return;
      }

      const heading = document.createElement("strong");
      heading.textContent = __(
        "Check these lines",
        "gpt3-ai-content-generator"
      );
      const list = document.createElement("ul");
      quickPasteValidationState.issues.forEach((issue) => {
        const item = document.createElement("li");
        item.textContent = issue;
        list.appendChild(item);
      });
      quickPasteValidation.append(heading, list);
    };

    const parseKeywords = (value) => {
      const seen = new Set();
      return String(value || "")
        .split(/[,\n]+/)
        .map((keyword) => keyword.trim().replace(/\s+/g, " "))
        .filter((keyword) => {
          if (!keyword) {
            return false;
          }
          const normalized = keyword.toLocaleLowerCase();
          if (seen.has(normalized)) {
            return false;
          }
          seen.add(normalized);
          return true;
        });
    };

    const resizeSingleTopic = () => {
      if (!singleFields.topic) {
        return;
      }
      singleFields.topic.style.height = "auto";
      singleFields.topic.style.height = `${Math.min(
        Math.max(singleFields.topic.scrollHeight, 88),
        160
      )}px`;
    };

    const getKeywordChips = (list) =>
      list
        ? Array.from(list.children).filter((element) =>
            element.classList.contains("aipkit_cw_keyword_chip")
          )
        : [];

    const getKeywordCursorIndex = (list, entry) => {
      const chips = getKeywordChips(list);
      if (!list || !entry || !list.contains(entry)) {
        return chips.length;
      }

      const children = Array.from(list.children);
      const entryIndex = children.indexOf(entry);
      if (entryIndex < 0) {
        return chips.length;
      }

      return children
        .slice(0, entryIndex)
        .filter((element) =>
          element.classList.contains("aipkit_cw_keyword_chip")
        ).length;
    };

    const resizeKeywordEntry = (editorElement, entry) => {
      if (!editorElement || !entry) {
        return;
      }

      if (entry.dataset.keywordPosition !== "between") {
        entry.style.width = "";
        delete entry.dataset.keywordEntryEmpty;
        return;
      }

      const isEmpty = entry.value.length === 0;
      entry.dataset.keywordEntryEmpty = isEmpty ? "true" : "false";
      if (isEmpty) {
        entry.style.width = "2px";
        return;
      }

      entry.style.width = "2px";
      entry.style.width = `${Math.min(
        Math.max(entry.scrollWidth + 2, 16),
        160
      )}px`;
    };

    const revealKeywordEntry = (editorElement, entry) => {
      if (!editorElement || !entry) {
        return;
      }

      const editorRect = editorElement.getBoundingClientRect();
      const entryRect = entry.getBoundingClientRect();
      if (entryRect.left < editorRect.left + 8) {
        editorElement.scrollLeft -= editorRect.left + 8 - entryRect.left;
      } else if (entryRect.right > editorRect.right - 8) {
        editorElement.scrollLeft += entryRect.right - (editorRect.right - 8);
      }
    };

    const positionKeywordEntry = (
      editorElement,
      list,
      entry,
      requestedIndex,
      options = {}
    ) => {
      if (!editorElement || !list || !entry) {
        return 0;
      }

      const chips = getKeywordChips(list);
      const numericIndex = Number(requestedIndex);
      const index = Math.max(
        0,
        Math.min(
          Number.isFinite(numericIndex) ? numericIndex : chips.length,
          chips.length
        )
      );
      list.insertBefore(entry, chips[index] || null);

      const isBetween = index < chips.length;
      entry.dataset.keywordCursorIndex = String(index);
      if (!entry.dataset.keywordEndPlaceholder) {
        entry.dataset.keywordEndPlaceholder = entry.placeholder || "";
      }
      entry.dataset.keywordPosition = isBetween ? "between" : "end";
      const shouldHideBulkPlaceholder =
        editorElement.hasAttribute("data-aipkit-cw-bulk-keyword-editor") &&
        chips.length > 0;
      entry.placeholder =
        isBetween || shouldHideBulkPlaceholder
          ? ""
          : entry.dataset.keywordEndPlaceholder || "";
      resizeKeywordEntry(editorElement, entry);

      if (options.focus) {
        entry.focus({ preventScroll: true });
        entry.setSelectionRange(entry.value.length, entry.value.length);
        revealKeywordEntry(editorElement, entry);
      }

      return index;
    };

    const getKeywordPointerIndex = (list, clientX) => {
      const chips = getKeywordChips(list);
      for (let index = 0; index < chips.length; index += 1) {
        const rect = chips[index].getBoundingClientRect();
        if (clientX < rect.left + rect.width / 2) {
          return index;
        }
      }
      return chips.length;
    };

    const insertKeywordsAt = (currentKeywords, additions, index) => {
      const current = parseKeywords(currentKeywords);
      const seen = new Set(
        current.map((keyword) => keyword.toLocaleLowerCase())
      );
      const accepted = parseKeywords(additions).filter((keyword) => {
        const normalized = keyword.toLocaleLowerCase();
        if (seen.has(normalized)) {
          return false;
        }
        seen.add(normalized);
        return true;
      });
      const insertionIndex = Math.max(0, Math.min(index, current.length));

      return {
        keywords: [
          ...current.slice(0, insertionIndex),
          ...accepted,
          ...current.slice(insertionIndex),
        ],
        cursorIndex: insertionIndex + accepted.length,
      };
    };

    const renderKeywordChipList = (
      list,
      value,
      removeLabel,
      options = {}
    ) => {
      if (!list) {
        return;
      }

      const editorElement = list.closest(".aipkit_cw_keyword_editor");
      const entry = editorElement?.querySelector(
        ".aipkit_cw_keyword_chip_input"
      );
      const hadPositionedEntry = Boolean(entry && list.contains(entry));
      const previousCursorIndex = hadPositionedEntry
        ? getKeywordCursorIndex(list, entry)
        : Number.POSITIVE_INFINITY;

      list.replaceChildren();
      parseKeywords(value).forEach((keyword, index) => {
        const chip = document.createElement("span");
        chip.className = "aipkit_cw_keyword_chip";
        chip.dataset.keywordIndex = String(index);

        const label = document.createElement("span");
        label.className = "aipkit_cw_keyword_chip_label";
        label.textContent = keyword;

        const removeButton = document.createElement("button");
        removeButton.type = "button";
        removeButton.className = "aipkit_cw_keyword_chip_remove";
        removeButton.dataset.keyword = keyword;
        removeButton.dataset.keywordIndex = String(index);
        removeButton.setAttribute(
          "aria-label",
          removeLabel || "Remove keyword"
        );
        removeButton.textContent = "×";

        chip.append(label, removeButton);
        list.appendChild(chip);
      });

      if (entry) {
        list.appendChild(entry);
        positionKeywordEntry(
          editorElement,
          list,
          entry,
          options.cursorIndex ?? previousCursorIndex,
          { focus: Boolean(options.focus) }
        );
      }
    };

    const renderKeywordChips = (options = {}) => {
      if (!keywordChipList || !singleFields.keywords) {
        return;
      }
      renderKeywordChipList(
        keywordChipList,
        singleFields.keywords.value,
        keywordEditor?.dataset.removeLabel,
        options
      );
    };

    const setKeywordValues = (keywords, options = {}) => {
      if (!singleFields.keywords) {
        return;
      }
      singleFields.keywords.value = parseKeywords(keywords).join(", ");
      renderKeywordChips(options);
    };

    const getRowDetailsToggle = (row) =>
      row ? row.querySelector(".aipkit_cw_bulk_toggle_row_details") : null;

    const getRowField = (row, key) =>
      row ? row.querySelector(`[data-bulk-field="${key}"]`) : null;

    const getBulkKeywordEditor = (row) =>
      row?.querySelector("[data-aipkit-cw-bulk-keyword-editor]") || null;

    const getBulkKeywordEntry = (row) =>
      row?.querySelector("[data-aipkit-cw-bulk-keyword-entry]") || null;

    const getBulkKeywordChipList = (row) =>
      row?.querySelector("[data-aipkit-cw-bulk-keyword-chip-list]") || null;

    const renderBulkKeywordChips = (row, options = {}) => {
      const field = getRowField(row, "keywords");
      const editorElement = getBulkKeywordEditor(row);
      const list = getBulkKeywordChipList(row);
      if (!field || !editorElement || !list) {
        return;
      }
      renderKeywordChipList(
        list,
        field.value,
        editorElement.dataset.removeLabel,
        options
      );
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

    const setRowData = (row, data) => {
      if (!row || !data) return;
      const setValue = (key, value) => {
        const field = getRowField(row, key);
        if (!field) return;
        field.value = value || "";
      };
      setValue("topic", data.topic || "");
      setValue("keywords", data.keywords || "");
      renderBulkKeywordChips(row);
      setValue("category", data.category || "");
      setValue("author", data.author || "");
      setValue("type", data.type || "");
      const scheduleField = getRowField(row, "schedule");
      if (scheduleField) {
        const normalized = normalizeSchedule(data.schedule || "");
        scheduleField.value = normalized.value || "";
        row.dataset.rawSchedule = normalized.raw || "";
      }
    };

    const setSingleFieldValues = (data) => {
      if (!singleFields.topic) {
        return;
      }

      isSyncingSingle = true;
      singleFields.topic.value = data?.topic || "";
      setKeywordValues(data?.keywords || "");
      isSyncingSingle = false;
      resizeSingleTopic();
    };

    const getSingleFieldData = (options = {}) => {
      const shouldTrim = options.trim !== false;
      if (!singleFields.topic) {
        return {
          topic: "",
          keywords: "",
        };
      }

      return {
        topic: sanitizeValue(singleFields.topic.value || "", {
          trim: shouldTrim,
        }),
        keywords: sanitizeValue(singleFields.keywords.value || "", {
          trim: shouldTrim,
        }),
        category: "",
        author: "",
        type: "",
        schedule: "",
      };
    };

    const syncSingleRowFromFields = (data) => {
      const rows = getRows();
      let row = rows[0];
      if (!row) {
        ensureMinimumRows();
        row = getRows()[0];
      }
      if (!row) {
        return;
      }

      setRowData(row, data);
      syncRemoveButtons();
    };

    const updateTaskEntryUi = () => {
      if (!taskEntryShell) {
        return;
      }

      const previousTaskEntryView = taskEntryShell.dataset.taskEntryView || "";
      const lineCount = getTopicCount();
      const populatedLineCount = getPopulatedLineCount();
      quickPasteValidationState = validateQuickPaste();
      const isSingleBlocked =
        populatedLineCount > 1 ||
        hasPerItemMetadata() ||
        !quickPasteValidationState.isValid;
      const isForcedSingleFallback =
        manualEntryView === "single" && isSingleBlocked;
      const activeView = isForcedSingleFallback ? "batch" : manualEntryView;

      taskEntryShell.dataset.taskEntryView = activeView;
      if (taskEntryPanelRoot) {
        taskEntryPanelRoot.dataset.taskEntryView = activeView;
      }

      if (singlePanel) {
        singlePanel.hidden = activeView !== "single";
      }
      if (batchPanel) {
        batchPanel.hidden = activeView !== "batch";
      }
      if (pastePanel) {
        pastePanel.hidden = activeView !== "paste";
      }

      if (forcedNote) {
        forcedNote.hidden = !isForcedSingleFallback;
      }

      if (batchCount) {
        batchCount.textContent = String(lineCount);
        batchCount.hidden = lineCount <= 1;
      }

      if (modeDescription) {
        modeDescription.textContent =
          modeDescriptions[activeView] || modeDescriptions.single;
      }

      renderQuickPasteSummary();

      entryButtons.forEach((button) => {
        const mode = button.getAttribute("data-aipkit-task-entry-tab") || "single";
        const isActive = mode === activeView;
        button.classList.toggle("is-active", isActive);
        button.setAttribute("aria-pressed", isActive ? "true" : "false");
        if (mode === "single") {
          button.disabled = isSingleBlocked;
        }
      });

      if (
        previousTaskEntryView !== activeView &&
        typeof window.aipkit_refreshContentWriterPublishingUI === "function"
      ) {
        window.aipkit_refreshContentWriterPublishingUI();
      }

      if (
        typeof window.aipkit_refreshContentWriterGenerateLabel === "function"
      ) {
        window.aipkit_refreshContentWriterGenerateLabel();
      }
    };

    const buildLines = () => {
      const rows = getRows();
      const lines = [];
      rows.forEach((row) => {
        const line = serializeRowData(getRowData(row));
        if (line) {
          lines.push(line);
        }
      });
      return [...lines, ...overflowLines].join("\n");
    };

    const syncSingleFromTextarea = () => {
      if (!singleFields.topic) {
        return;
      }

      const lines = getTopicLines();
      const rowData = lines.length ? parseLine(lines[0]) : {};
      setSingleFieldValues(rowData);
      updateTaskEntryUi();
    };

    const updateTextarea = () => {
      if (!textarea) return;
      isSyncingTextarea = true;
      textarea.value = buildLines();
      textarea.dispatchEvent(new Event("change", { bubbles: true }));
      isSyncingTextarea = false;
      syncSingleFromTextarea();
    };

    const setRowDetailsState = (row, isOpen) => {
      if (!row) return;
      row.classList.toggle("is-details-open", isOpen);
      const toggle = getRowDetailsToggle(row);
      if (toggle) {
        toggle.setAttribute("aria-expanded", isOpen ? "true" : "false");
      }
    };

    const createRow = (data) => {
      const fragment = document.importNode(template.content, true);
      const row = fragment.querySelector("[data-aipkit-bulk-row]");
      if (!row) return null;
      setRowData(row, data || {});
      setRowDetailsState(row, false);
      return row;
    };

    const syncRemoveButtons = () => {
      const rows = getRows();
      rowsContainer.classList.toggle(
        "aipkit_cw_bulk_rows--scrollable",
        rows.length > 6
      );
      rows.forEach((row) => {
        const button = row.querySelector(".aipkit_cw_bulk_remove_row");
        if (!button) return;
        const isOnlyEmptyRow =
          rows.length === 1 && !serializeRowData(getRowData(row));
        button.hidden = isOnlyEmptyRow;
        button.disabled = isOnlyEmptyRow;
        button.setAttribute(
          "aria-disabled",
          isOnlyEmptyRow ? "true" : "false"
        );
      });

      if (addRowButton) {
        addRowButton.disabled = rows.length >= MAX_ROWS;
      }
    };

    const ensureRows = (targetCount) => {
      const rows = getRows();
      if (rows.length >= targetCount) return;
      const needed = targetCount - rows.length;
      for (let i = 0; i < needed; i++) {
        const row = createRow();
        if (row) rowsContainer.appendChild(row);
      }
    };

    const ensureMinimumRows = () => {
      ensureRows(MIN_ROWS);
    };

    const renderFromTextarea = () => {
      const lines = getNonEmptyLines();
      rowsContainer.innerHTML = "";
      const rows = [];
      overflowLines = lines.slice(MAX_ROWS);

      lines.slice(0, MAX_ROWS).forEach((line) => {
        const row = createRow(parseLine(line));
        if (row) rows.push(row);
      });

      if (!rows.length) {
        overflowLines = [];
        ensureMinimumRows();
        syncRemoveButtons();
        updateTextarea();
        return;
      }

      rows.forEach((row) => rowsContainer.appendChild(row));
      ensureMinimumRows();
      syncRemoveButtons();
      syncSingleFromTextarea();
    };

    const syncSingleToTextarea = (shouldAutosave = false) => {
      if (!singleFields.topic || isSyncingSingle) {
        return;
      }

      const fieldData = getSingleFieldData({ trim: shouldAutosave });
      const hasPendingKeywordsWithoutTopic =
        !fieldData.topic && Boolean(fieldData.keywords);

      // The shared bulk format requires a topic in its first column. Keep
      // keyword-first input in the Single editor until a topic exists instead
      // of serializing an invalid `| keyword` row, which would force the UI
      // into Batch editor through the shared row validator.
      if (hasPendingKeywordsWithoutTopic) {
        syncSingleRowFromFields({
          topic: "",
          keywords: "",
          category: "",
          author: "",
          type: "",
          schedule: "",
        });

        if (textarea.value !== "") {
          isSyncingTextarea = true;
          textarea.value = "";
          isSyncingTextarea = false;
          textarea.dispatchEvent(new Event("change", { bubbles: true }));
        }

        updateTaskEntryUi();

        if (
          shouldAutosave &&
          typeof window.aipkit_handleContentWriterAutoSave === "function"
        ) {
          window.aipkit_handleContentWriterAutoSave(textarea);
        }
        return;
      }

      const nextValue = serializeRowData(fieldData, { trim: shouldAutosave });
      if (textarea.value !== nextValue) {
        isSyncingTextarea = true;
        textarea.value = nextValue;
        isSyncingTextarea = false;
        if (shouldAutosave) {
          renderFromTextarea();
        } else {
          syncSingleRowFromFields(fieldData);
          updateTaskEntryUi();
        }
        textarea.dispatchEvent(new Event("change", { bubbles: true }));
      } else {
        if (shouldAutosave) {
          syncSingleFromTextarea();
        }
      }

      if (
        shouldAutosave &&
        typeof window.aipkit_handleContentWriterAutoSave === "function"
      ) {
        window.aipkit_handleContentWriterAutoSave(textarea);
      }
    };

    const commitKeywordEntry = (shouldAutosave = true, options = {}) => {
      if (!keywordEntry || !singleFields.keywords) {
        return 0;
      }

      const additions = parseKeywords(keywordEntry.value);
      if (!additions.length) {
        keywordEntry.value = "";
        resizeKeywordEntry(keywordEditor, keywordEntry);
        return getKeywordCursorIndex(keywordChipList, keywordEntry);
      }

      const insertion = insertKeywordsAt(
        singleFields.keywords.value,
        additions,
        getKeywordCursorIndex(keywordChipList, keywordEntry)
      );
      setKeywordValues(insertion.keywords, {
        cursorIndex: insertion.cursorIndex,
      });
      keywordEntry.value = "";
      syncSingleToTextarea(shouldAutosave);
      positionKeywordEntry(
        keywordEditor,
        keywordChipList,
        keywordEntry,
        insertion.cursorIndex,
        { focus: options.focus !== false }
      );
      return insertion.cursorIndex;
    };

    const setBulkKeywordValues = (row, keywords, options = {}) => {
      const field = getRowField(row, "keywords");
      if (!field) {
        return;
      }
      const normalizedKeywords = Array.isArray(keywords)
        ? keywords.join(", ")
        : keywords;
      field.value = parseKeywords(normalizedKeywords).join(", ");
      renderBulkKeywordChips(row, options);
      updateTextarea();
      syncRemoveButtons();
      if (options.focus) {
        positionKeywordEntry(
          getBulkKeywordEditor(row),
          getBulkKeywordChipList(row),
          getBulkKeywordEntry(row),
          options.cursorIndex,
          { focus: true }
        );
      }
    };

    const commitBulkKeywordEntry = (row, options = {}) => {
      const entry = getBulkKeywordEntry(row);
      const field = getRowField(row, "keywords");
      if (!entry || !field) {
        return 0;
      }

      const additions = parseKeywords(entry.value);
      entry.value = "";
      if (!additions.length) {
        resizeKeywordEntry(getBulkKeywordEditor(row), entry);
        return getKeywordCursorIndex(getBulkKeywordChipList(row), entry);
      }

      const insertion = insertKeywordsAt(
        field.value,
        additions,
        getKeywordCursorIndex(getBulkKeywordChipList(row), entry)
      );
      setBulkKeywordValues(row, insertion.keywords, {
        cursorIndex: insertion.cursorIndex,
        focus: options.focus !== false,
      });
      return insertion.cursorIndex;
    };

    if (textarea.value.trim()) {
      renderFromTextarea();
    } else {
      ensureMinimumRows();
      syncRemoveButtons();
      updateTextarea();
    }

    rowsContainer.addEventListener("input", (event) => {
      const target = event.target;
      if (!target || !target.closest("[data-aipkit-bulk-row]")) return;
      if (target.matches("[data-aipkit-cw-bulk-keyword-entry]")) {
        const row = target.closest("[data-aipkit-bulk-row]");
        resizeKeywordEntry(getBulkKeywordEditor(row), target);
        return;
      }
      updateTextarea();
      syncRemoveButtons();
    });

    rowsContainer.addEventListener("change", (event) => {
      const target = event.target;
      if (!target || !target.closest("[data-aipkit-bulk-row]")) return;
      if (target.matches("[data-aipkit-cw-bulk-keyword-entry]")) return;
      updateTextarea();
      syncRemoveButtons();
    });

    rowsContainer.addEventListener("input", (event) => {
      const topicInput = event.target.closest('[data-bulk-field="topic"]');
      if (!topicInput) {
        return;
      }
      topicInput.setAttribute("aria-invalid", "false");
      const validation = topicInput
        .closest("[data-aipkit-bulk-row]")
        ?.querySelector("[data-aipkit-bulk-topic-validation]");
      if (validation) {
        validation.textContent = "";
        validation.hidden = true;
      }
    });

    rowsContainer.addEventListener("keydown", (event) => {
      const entry = event.target.closest(
        "[data-aipkit-cw-bulk-keyword-entry]"
      );
      if (!entry) {
        return;
      }

      const row = entry.closest("[data-aipkit-bulk-row]");
      if (!row) {
        return;
      }

      if (event.key === "Enter" || event.key === ",") {
        event.preventDefault();
        event.stopPropagation();
        commitBulkKeywordEntry(row);
        return;
      }

      if (entry.value) {
        return;
      }

      const list = getBulkKeywordChipList(row);
      const editorElement = getBulkKeywordEditor(row);
      const field = getRowField(row, "keywords");
      const keywords = parseKeywords(field?.value || "");
      const cursorIndex = getKeywordCursorIndex(list, entry);

      if (event.key === "ArrowLeft" && cursorIndex > 0) {
        event.preventDefault();
        positionKeywordEntry(
          editorElement,
          list,
          entry,
          cursorIndex - 1,
          { focus: true }
        );
        return;
      }

      if (event.key === "ArrowRight" && cursorIndex < keywords.length) {
        event.preventDefault();
        positionKeywordEntry(
          editorElement,
          list,
          entry,
          cursorIndex + 1,
          { focus: true }
        );
        return;
      }

      const removalIndex =
        event.key === "Backspace"
          ? cursorIndex - 1
          : event.key === "Delete"
          ? cursorIndex
          : -1;
      if (removalIndex >= 0 && removalIndex < keywords.length) {
        event.preventDefault();
        keywords.splice(removalIndex, 1);
        setBulkKeywordValues(row, keywords, {
          cursorIndex:
            event.key === "Backspace" ? cursorIndex - 1 : cursorIndex,
          focus: true,
        });
      }
    });

    rowsContainer.addEventListener("paste", (event) => {
      const entry = event.target.closest(
        "[data-aipkit-cw-bulk-keyword-entry]"
      );
      if (!entry) {
        return;
      }

      const pastedValue = event.clipboardData?.getData("text") || "";
      if (!/[\n,]/.test(pastedValue)) {
        return;
      }

      const row = entry.closest("[data-aipkit-bulk-row]");
      if (!row) {
        return;
      }

      event.preventDefault();
      entry.value = pastedValue;
      commitBulkKeywordEntry(row);
    });

    rowsContainer.addEventListener(
      "focusout",
      (event) => {
        const entry = event.target.closest(
          "[data-aipkit-cw-bulk-keyword-entry]"
        );
        if (!entry || !entry.value.trim()) {
          return;
        }
        const row = entry.closest("[data-aipkit-bulk-row]");
        const editorElement = getBulkKeywordEditor(row);
        if (
          event.relatedTarget &&
          editorElement?.contains(event.relatedTarget)
        ) {
          return;
        }
        if (row) {
          commitBulkKeywordEntry(row, { focus: false });
        }
      },
      true
    );

    rowsContainer.addEventListener("pointerdown", (event) => {
      const editorElement = event.target.closest(
        "[data-aipkit-cw-bulk-keyword-editor]"
      );
      if (
        !editorElement ||
        event.target.closest(".aipkit_cw_keyword_chip_remove")
      ) {
        return;
      }

      const row = editorElement.closest("[data-aipkit-bulk-row]");
      const entry = getBulkKeywordEntry(row);
      const list = getBulkKeywordChipList(row);
      if (!row || !entry || !list || event.target === entry) {
        return;
      }

      event.preventDefault();
      if (entry.value.trim()) {
        commitBulkKeywordEntry(row);
      }
      positionKeywordEntry(
        editorElement,
        list,
        entry,
        getKeywordPointerIndex(list, event.clientX),
        { focus: true }
      );
    });

    rowsContainer.addEventListener("click", (event) => {
      const keywordRemoveButton = event.target.closest(
        "[data-aipkit-cw-bulk-keyword-editor] .aipkit_cw_keyword_chip_remove"
      );
      if (keywordRemoveButton) {
        const row = keywordRemoveButton.closest("[data-aipkit-bulk-row]");
        if (!row) return;
        event.preventDefault();
        const field = getRowField(row, "keywords");
        const remainingKeywords = parseKeywords(field?.value || "");
        const keywordIndex = Number(keywordRemoveButton.dataset.keywordIndex);
        if (
          Number.isInteger(keywordIndex) &&
          keywordIndex >= 0 &&
          keywordIndex < remainingKeywords.length
        ) {
          remainingKeywords.splice(keywordIndex, 1);
          setBulkKeywordValues(row, remainingKeywords, {
            cursorIndex: keywordIndex,
            focus: true,
          });
        }
        return;
      }

      const keywordEditorElement = event.target.closest(
        "[data-aipkit-cw-bulk-keyword-editor]"
      );
      if (keywordEditorElement) {
        return;
      }

      const detailsButton = event.target.closest(".aipkit_cw_bulk_toggle_row_details");
      if (detailsButton) {
        const row = detailsButton.closest("[data-aipkit-bulk-row]");
        if (!row) return;
        event.preventDefault();
        const isOpen = row.classList.contains("is-details-open");
        setRowDetailsState(row, !isOpen);
        return;
      }

      const button = event.target.closest(".aipkit_cw_bulk_remove_row");
      if (!button) return;
      const row = button.closest("[data-aipkit-bulk-row]");
      if (!row) return;
      const rows = getRows();
      const rowIndex = rows.indexOf(row);
      if (rowIndex < 0 || button.disabled) return;
      row.remove();
      ensureMinimumRows();
      updateTextarea();
      if (overflowLines.length) {
        renderFromTextarea();
      }
      syncRemoveButtons();
    });

    textarea.addEventListener("input", () => {
      if (isSyncingTextarea) return;
      renderQuickPasteSummary();
      clearTimeout(pasteSyncTimer);
      pasteSyncTimer = setTimeout(() => {
        renderFromTextarea();
      }, 250);
    });

    pasteSample?.addEventListener("click", () => {
      const defaultCategoryId = String(
        pasteSample.dataset.sampleCategoryId || ""
      ).trim();
      const sample = `electric cars | battery, tesla | ${defaultCategoryId} | editor | post | 2026-08-01 09:30`;
      const current = String(textarea.value || "").trimEnd();
      textarea.value = current ? `${current}\n${sample}` : sample;
      textarea.dispatchEvent(new Event("input", { bubbles: true }));
      textarea.focus();
      textarea.setSelectionRange(textarea.value.length, textarea.value.length);
    });

    addRowButton?.addEventListener("click", () => {
      const currentRows = getRows();
      const onlyRow = currentRows.length === 1 ? currentRows[0] : null;
      if (onlyRow && !serializeRowData(getRowData(onlyRow))) {
        getRowField(onlyRow, "topic")?.focus();
        return;
      }
      if (getRows().length >= MAX_ROWS) {
        return;
      }
      const row = createRow();
      if (!row) {
        return;
      }
      rowsContainer.appendChild(row);
      syncRemoveButtons();
      getRowField(row, "topic")?.focus();
    });

    if (entryButtons.length) {
      entryButtons.forEach((button) => {
        if (button.dataset.listenerAttached === "true") {
          return;
        }
        button.addEventListener("click", (event) => {
          event.preventDefault();
          const requestedView =
            button.getAttribute("data-aipkit-task-entry-tab") || "single";
          if (manualEntryView === "paste" && requestedView !== "paste") {
            clearTimeout(pasteSyncTimer);
            renderFromTextarea();
          }
          quickPasteValidationState = validateQuickPaste();
          if (
            requestedView === "single" &&
            (getPopulatedLineCount() > 1 ||
              hasPerItemMetadata() ||
              !quickPasteValidationState.isValid)
          ) {
            return;
          }
          manualEntryView = requestedView;
          updateTaskEntryUi();
        });
        button.dataset.listenerAttached = "true";
      });
    }

    if (singleFields.topic) {
      singleFields.topic.addEventListener("input", () => {
        if (
          typeof window.aipkit_clearContentWriterTopicValidation === "function"
        ) {
          window.aipkit_clearContentWriterTopicValidation();
        }
        syncSingleToTextarea(false);
      });
      singleFields.topic.addEventListener("blur", () => {
        syncSingleToTextarea(true);
      });
    }

    singleFields.topic?.addEventListener("input", resizeSingleTopic);

    keywordEntry?.addEventListener("input", () => {
      resizeKeywordEntry(keywordEditor, keywordEntry);
    });

    keywordEntry?.addEventListener("keydown", (event) => {
      if (event.key === "Enter" || event.key === ",") {
        event.preventDefault();
        event.stopPropagation();
        commitKeywordEntry(true);
        return;
      }

      if (keywordEntry.value || !singleFields.keywords) {
        return;
      }

      const keywords = parseKeywords(singleFields.keywords.value);
      const cursorIndex = getKeywordCursorIndex(
        keywordChipList,
        keywordEntry
      );

      if (event.key === "ArrowLeft" && cursorIndex > 0) {
        event.preventDefault();
        positionKeywordEntry(
          keywordEditor,
          keywordChipList,
          keywordEntry,
          cursorIndex - 1,
          { focus: true }
        );
        return;
      }

      if (event.key === "ArrowRight" && cursorIndex < keywords.length) {
        event.preventDefault();
        positionKeywordEntry(
          keywordEditor,
          keywordChipList,
          keywordEntry,
          cursorIndex + 1,
          { focus: true }
        );
        return;
      }

      const removalIndex =
        event.key === "Backspace"
          ? cursorIndex - 1
          : event.key === "Delete"
          ? cursorIndex
          : -1;
      if (removalIndex >= 0 && removalIndex < keywords.length) {
        event.preventDefault();
        keywords.splice(removalIndex, 1);
        const nextCursorIndex =
          event.key === "Backspace" ? cursorIndex - 1 : cursorIndex;
        setKeywordValues(keywords, {
          cursorIndex: nextCursorIndex,
        });
        syncSingleToTextarea(true);
        positionKeywordEntry(
          keywordEditor,
          keywordChipList,
          keywordEntry,
          nextCursorIndex,
          { focus: true }
        );
      }
    });

    keywordEntry?.addEventListener("paste", (event) => {
      const pastedValue = event.clipboardData?.getData("text") || "";
      if (!/[\n,]/.test(pastedValue)) {
        return;
      }
      event.preventDefault();
      keywordEntry.value = pastedValue;
      commitKeywordEntry(true);
    });

    keywordEntry?.addEventListener("blur", (event) => {
      if (
        event.relatedTarget &&
        keywordEditor?.contains(event.relatedTarget)
      ) {
        return;
      }
      commitKeywordEntry(true, { focus: false });
    });

    keywordEditor?.addEventListener("pointerdown", (event) => {
      if (
        event.target === keywordEntry ||
        event.target.closest(".aipkit_cw_keyword_chip_remove")
      ) {
        return;
      }

      event.preventDefault();
      if (keywordEntry?.value.trim()) {
        commitKeywordEntry(true);
      }
      positionKeywordEntry(
        keywordEditor,
        keywordChipList,
        keywordEntry,
        getKeywordPointerIndex(keywordChipList, event.clientX),
        { focus: true }
      );
    });

    keywordEditor?.addEventListener("click", (event) => {
      const removeButton = event.target.closest(
        ".aipkit_cw_keyword_chip_remove"
      );
      if (removeButton && singleFields.keywords) {
        event.preventDefault();
        const remainingKeywords = parseKeywords(singleFields.keywords.value);
        const keywordIndex = Number(removeButton.dataset.keywordIndex);
        if (
          Number.isInteger(keywordIndex) &&
          keywordIndex >= 0 &&
          keywordIndex < remainingKeywords.length
        ) {
          remainingKeywords.splice(keywordIndex, 1);
          setKeywordValues(remainingKeywords, {
            cursorIndex: keywordIndex,
          });
          syncSingleToTextarea(true);
          positionKeywordEntry(
            keywordEditor,
            keywordChipList,
            keywordEntry,
            keywordIndex,
            { focus: true }
          );
        }
        return;
      }
    });

    getRows().forEach((row) => {
      setRowDetailsState(row, row.classList.contains("is-details-open"));
    });

    container.aipkitValidateManualEntries = () => {
      renderQuickPasteSummary();
      return {
        isValid: quickPasteValidationState.isValid,
        message: quickPasteValidationState.message,
      };
    };

    container.aipkitShowManualEntryIssues = (issues, message) => {
      const normalizedIssues = Array.isArray(issues)
        ? issues
            .map((issue) =>
              typeof issue === "string" ? issue : String(issue?.message || "")
            )
            .filter(Boolean)
        : [];

      if (!normalizedIssues.length) {
        return false;
      }

      manualEntryView = "paste";
      updateTaskEntryUi();
      quickPasteValidationState = {
        isValid: false,
        message:
          message ||
          __(
            "Fix the listed Quick paste lines before generating.",
            "gpt3-ai-content-generator"
          ),
        issues: normalizedIssues,
      };
      textarea.setAttribute("aria-invalid", "true");
      if (quickPasteValidation) {
        quickPasteValidation.replaceChildren();
        quickPasteValidation.hidden = false;
        const heading = document.createElement("strong");
        heading.textContent = __(
          "Check these lines",
          "gpt3-ai-content-generator"
        );
        const list = document.createElement("ul");
        normalizedIssues.forEach((issue) => {
          const item = document.createElement("li");
          item.textContent = issue;
          list.appendChild(item);
        });
        quickPasteValidation.append(heading, list);
      }
      quickPasteValidation?.scrollIntoView({
        behavior: "smooth",
        block: "nearest",
      });
      return true;
    };

    updateTaskEntryUi();
    renderKeywordChips();
    resizeSingleTopic();
    editor.dataset.bulkEditorAttached = "true";
  }

  window.aipkit_initContentWriterBulkEditor = aipkit_initContentWriterBulkEditor;
})();
