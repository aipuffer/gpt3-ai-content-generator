const SOURCE_EDITOR_TARGET_FIELDS = [
  "provider", "storeId", "vectorId", "logId", "embeddingProvider", "embeddingModel",
];

/** Shared text/Q&A dialog controls. Each module owns its save and recovery flow. */
export function createSourceEditor({ editorModal, __ }) {
  const editorTextarea = editorModal
    ? editorModal.querySelector(".aipkit_sources_editor_textarea")
    : null;
  const editorQuestion = editorModal
    ? editorModal.querySelector(".aipkit_sources_editor_question")
    : null;
  const editorAnswer = editorModal
    ? editorModal.querySelector(".aipkit_sources_editor_answer")
    : null;
  const editorTextPanel = editorModal
    ? editorModal.querySelector(".aipkit_sources_editor_panel--text")
    : null;
  const editorQaPanel = editorModal
    ? editorModal.querySelector(".aipkit_sources_editor_panel--qa")
    : null;
  const editorSubtitle = editorModal
    ? editorModal.querySelector(".aipkit_sources_editor_subtitle")
    : null;
  const editorClose = editorModal
    ? editorModal.querySelector(".aipkit_sources_editor_close")
    : null;
  const editorCancel = editorModal
    ? editorModal.querySelector(".aipkit_sources_editor_cancel")
    : null;
  const editorSave = editorModal
    ? editorModal.querySelector(".aipkit_sources_editor_save")
    : null;
  const contentFields = [
    { field: editorTextarea, value: "text", baseline: "initialText" },
    { field: editorQuestion, value: "question", baseline: "initialQuestion" },
    { field: editorAnswer, value: "answer", baseline: "initialAnswer" },
  ];

  const closeEditorModal = () => {
    if (!editorModal) {
      return;
    }
    editorModal.classList.remove("aipkit-active");
    editorModal.setAttribute("aria-hidden", "true");
    SOURCE_EDITOR_TARGET_FIELDS.forEach((key) => {
      editorModal.dataset[key] = "";
    });
    editorModal.dataset.sourceKind = "";
    contentFields.forEach(({ baseline }) => {
      editorModal.dataset[baseline] = "";
    });
    contentFields.forEach(({ field }) => {
      if (field) field.value = "";
    });
    if (editorTextPanel) {
      editorTextPanel.hidden = false;
    }
    if (editorQaPanel) {
      editorQaPanel.hidden = true;
    }
    if (editorSave) {
      editorSave.disabled = true;
    }
  };

  const parseEditorContent = (content) => {
    if (typeof window.aipkit_parseSourceEditorContent === "function") {
      return window.aipkit_parseSourceEditorContent(content);
    }
    const text = String(content || "").replace(/\r\n?/g, "\n").trim();
    const qaMatch = text.match(/^Q\s*:\s*([\s\S]*?)\nA\s*:\s*([\s\S]*)$/i);
    return qaMatch
      ? {
          type: "qa",
          text,
          question: qaMatch[1].trim(),
          answer: qaMatch[2].trim(),
        }
      : { type: "text", text, question: "", answer: "" };
  };

  const updateEditorSaveState = () => {
    if (!editorModal || !editorSave) {
      return;
    }
    const sourceKind =
      editorModal.dataset.sourceKind === "qa" ? "qa" : "text";
    if (sourceKind === "qa") {
      const question = editorQuestion
        ? editorQuestion.value.trim()
        : "";
      const answer = editorAnswer
        ? editorAnswer.value.trim()
        : "";
      const changed =
        question !== (editorModal.dataset.initialQuestion || "") ||
        answer !== (editorModal.dataset.initialAnswer || "");
      editorSave.disabled = !question || !answer || !changed;
      return;
    }

    const text = editorTextarea
      ? editorTextarea.value.trim()
      : "";
    editorSave.disabled =
      !text || text === (editorModal.dataset.initialText || "");
  };

  const openEditorModal = (button) => {
    if (
      !editorModal ||
      !editorTextarea ||
      !editorQuestion ||
      !editorAnswer
    ) {
      return;
    }
    SOURCE_EDITOR_TARGET_FIELDS.forEach((key) => {
      editorModal.dataset[key] = button.dataset[key] || "";
    });
    const content = button.dataset.content
      ? decodeURIComponent(button.dataset.content)
      : "";
    const parsed = parseEditorContent(content);
    const sourceKind =
      button.dataset.sourceKind === "qa" || parsed.type === "qa"
        ? "qa"
        : "text";
    editorModal.dataset.sourceKind = sourceKind;
    contentFields.forEach(({ value, baseline }) => {
      editorModal.dataset[baseline] = parsed[value] || "";
    });
    contentFields.forEach(({ field, value }) => {
      field.value = parsed[value] || "";
    });
    if (editorTextPanel) {
      editorTextPanel.hidden = sourceKind !== "text";
    }
    if (editorQaPanel) {
      editorQaPanel.hidden = sourceKind !== "qa";
    }
    if (editorSubtitle) {
      editorSubtitle.textContent =
        sourceKind === "qa"
          ? __(
              "Update the question and answer, then save to retrain this entry.",
              "gpt3-ai-content-generator"
            )
          : __(
              "Update the source text and save to retrain this entry.",
              "gpt3-ai-content-generator"
            );
    }
    updateEditorSaveState();
    editorModal.classList.add("aipkit-active");
    editorModal.setAttribute("aria-hidden", "false");
    setTimeout(
      () =>
        (sourceKind === "qa"
          ? editorQuestion
          : editorTextarea
        ).focus(),
      0
    );
  };

  const bind = () => {
    [editorClose, editorCancel].forEach((button) => {
      if (button) button.addEventListener("click", closeEditorModal);
    });
    contentFields.forEach(({ field }) => {
      if (field) field.addEventListener("input", updateEditorSaveState);
    });
    if (editorModal && !editorModal.dataset.bound) {
      document.addEventListener("keydown", (event) => {
        if (
          event.key === "Escape" &&
          editorModal.classList.contains("aipkit-active")
        ) {
          closeEditorModal();
        }
      });
      editorModal.dataset.bound = "1";
    }
  };

  return {
    open: openEditorModal,
    close: closeEditorModal,
    bind,
    fields: { editorTextarea, editorQuestion, editorAnswer, editorSave },
  };
}
