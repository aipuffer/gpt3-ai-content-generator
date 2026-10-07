import { bindChatbotSettingsAutosave } from "./state.js";

const escapeHtml = text => text.replace(/[&<>"']/g, char => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[char]);

/**
 * Inline and expanded instructions share one draft; blur or close saves it. The larger editor shows its length,
 * marks the variables in the text and adds them at the cursor, and offers templates to read before one replaces
 * the text (with an Undo until the next edit).
 */
export function bindChatbotInstructions({
  builder, instructionsField, instructionsExpandBtn, instructionsModal,
  instructionsModalCloseBtn, instructionsModalTextarea, instructionsModalCount,
  saveStatus, persistence,
  __ = text => text,
  _n = (single, plural, count) => (count === 1 ? single : plural),
  sprintf = (format, ...args) => args.reduce((text, arg) => text.replace("%s", arg), format),
}) {
  if (!instructionsField || !saveStatus) return;
  const doneBtn = instructionsModal?.querySelector(".aipkit_builder_instructions_done");
  const marks = instructionsModal?.querySelector(".aipkit_instructions_marks");
  const variableList = instructionsModal?.querySelector(".aipkit_instructions_variables_list");
  const templateList = instructionsModal?.querySelector(".aipkit_instructions_templates");
  const templates = [...(instructionsModal?.querySelectorAll("[data-aipkit-instruction-template]") || [])];
  const undoBar = instructionsModal?.querySelector("[data-aipkit-instructions-undo]");
  const undoText = undoBar?.querySelector("[data-aipkit-instructions-undo-text]");
  const undoBtn = undoBar?.querySelector("[data-aipkit-instructions-undo-btn]");
  // Variables are plain bracketed words, so they survive escaping unchanged and can be marked afterwards.
  const variables = [...(variableList?.querySelectorAll("[data-aipkit-instruction-variable]") || [])]
    .map(button => button.dataset.aipkitInstructionVariable).filter(Boolean);
  const variablePattern = variables.length ? new RegExp(variables.map(name => name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")).join("|"), "g") : null;
  let focusTimer, undo = null, replacing = false;
  let resetEditor = () => {};
  const cancelFocus = () => { clearTimeout(focusTimer); focusTimer = null; };
  const updateCount = () => {
    const length = instructionsField.value.length;
    /* translators: %s: number of characters in the instructions. */
    if (instructionsModalCount) instructionsModalCount.textContent = sprintf(_n("%s character", "%s characters", length, "gpt3-ai-content-generator"), length.toLocaleString());
  };
  // The marks layer sits behind the transparent textarea with the same text, so only the highlights show.
  const paintMarks = () => {
    if (!marks || !instructionsModalTextarea) return;
    const html = escapeHtml(instructionsModalTextarea.value);
    marks.innerHTML = `${variablePattern ? html.replace(variablePattern, name => `<mark>${name}</mark>`) : html} `;
    marks.scrollTop = instructionsModalTextarea.scrollTop;
  };
  const showTemplate = open => templates.forEach(template => {
    const head = template.querySelector(".aipkit_instructions_template_head");
    const body = template.querySelector(".aipkit_instructions_template_body");
    template.classList.toggle("is-open", template === open);
    head?.setAttribute("aria-expanded", template === open ? "true" : "false");
    if (body) body.hidden = template !== open;
  });
  const setUndo = next => {
    undo = next;
    if (!undoBar) return;
    undoBar.hidden = !undo;
    /* translators: %s: template name, such as "Customer support". */
    if (undoText) undoText.textContent = undo ? sprintf(__("%s template used.", "gpt3-ai-content-generator"), undo.name) : "";
  };
  const syncModal = () => {
    cancelFocus();
    setUndo(null);
    resetEditor();
    if (instructionsModalTextarea) instructionsModalTextarea.value = instructionsField.value;
    updateCount();
    paintMarks();
  };
  const hideModal = () => {
    cancelFocus();
    setUndo(null);
    showTemplate(null);
    instructionsModal?.classList.remove("aipkit-active");
    instructionsModal?.setAttribute("aria-hidden", "true");
  };
  // insertText keeps the browser's own undo (Ctrl+Z) working; setting the value is the fallback.
  const writeText = (text, start, end) => {
    const area = instructionsModalTextarea;
    area.focus();
    area.setSelectionRange(start, end);
    const written = text ? document.execCommand?.("insertText", false, text) : document.execCommand?.("delete");
    if (written) return;
    area.setRangeText(text, start, end, "end");
    area.dispatchEvent(new Event("input", { bubbles: true }));
  };
  const replaceText = text => {
    replacing = true;
    try {
      writeText(text, 0, instructionsModalTextarea.value.length);
      instructionsModalTextarea.setSelectionRange(text.length, text.length);
    } finally {
      replacing = false;
    }
  };
  bindChatbotSettingsAutosave({
    builder, panel: builder, boundKey: "instructionsAutosaveBound",
    action: "aipkit_update_chatbot_instructions", persistence,
    readSettings: () => ({ instructions: instructionsField.value }),
    isUnchanged: (a, b) => a.instructions === b.instructions,
    savedSettings: (settings, response) => ({
      instructions: response?.bot?.settings?.instructions ?? settings.instructions,
    }),
    restoreSettings: settings => { instructionsField.value = settings.instructions; },
    afterHydrate: syncModal,
    interactionNodes: () => [instructionsField, instructionsModalTextarea, instructionsExpandBtn, variableList, templateList, undoBtn],
    bindEvents: ({ signal, isEditable, draft, save }) => {
      const options = { signal };
      let editorEvents;
      // Assigning .value does not clear native undo transactions. A new textarea
      // gives every hydrated/opened session its own history, including bot switches.
      resetEditor = () => {
        if (!instructionsModalTextarea) return;
        editorEvents?.abort();
        const fresh = instructionsModalTextarea.cloneNode(false);
        fresh.inert = false;
        fresh.removeAttribute("aria-busy");
        instructionsModalTextarea.replaceWith(fresh);
        instructionsModalTextarea = fresh;
        editorEvents = new AbortController();
        instructionsModalTextarea?.addEventListener("input", () => {
          if (!isEditable()) return;
          instructionsField.value = instructionsModalTextarea.value;
          updateCount();
          paintMarks();
          // Undo returns to the text before the template, so it ends once the text is edited.
          if (!replacing) setUndo(null);
          draft();
        }, { signal: editorEvents.signal });
        instructionsModalTextarea?.addEventListener("scroll", () => {
          if (marks) marks.scrollTop = instructionsModalTextarea.scrollTop;
        }, { signal: editorEvents.signal });
      };
      signal.addEventListener("abort", () => editorEvents?.abort(), {once: true});
      instructionsField.addEventListener("input", () => draft(), options);
      instructionsField.addEventListener("blur", save, options);
      instructionsExpandBtn?.addEventListener("click", () => {
        if (!isEditable() || !instructionsModal || !instructionsModalTextarea) return;
        syncModal();
        instructionsModal.classList.add("aipkit-active");
        instructionsModal.setAttribute("aria-hidden", "false");
        focusTimer = setTimeout(() => {
          focusTimer = null;
          if (isEditable() && instructionsModal.classList.contains("aipkit-active")) instructionsModalTextarea.focus();
        }, 0);
      }, options);
      variableList?.addEventListener("click", event => {
        const button = event.target.closest("[data-aipkit-instruction-variable]");
        if (!button || !isEditable() || !instructionsModalTextarea) return;
        const { value, selectionStart: start, selectionEnd: end } = instructionsModalTextarea;
        // A variable added against a word gets a space on that side.
        const before = start > 0 && !/[\s([{"'“‘]/.test(value[start - 1]) ? " " : "";
        const after = end < value.length && /[\p{L}\p{N}[]/u.test(value[end]) ? " " : "";
        writeText(`${before}${button.dataset.aipkitInstructionVariable}${after}`, start, end);
      }, options);
      templateList?.addEventListener("click", event => {
        const template = event.target.closest("[data-aipkit-instruction-template]");
        if (!template || !isEditable() || !instructionsModalTextarea) return;
        if (event.target.closest(".aipkit_instructions_template_head")) {
          showTemplate(template.classList.contains("is-open") ? null : template);
        } else if (event.target.closest("[data-aipkit-instruction-template-cancel]")) {
          showTemplate(null);
          template.querySelector(".aipkit_instructions_template_head")?.focus();
        } else if (event.target.closest("[data-aipkit-instruction-template-use]")) {
          // Using one template after another still undoes back to what was written before the first.
          const before = undo ? undo.text : instructionsModalTextarea.value;
          showTemplate(null);
          replaceText(template.dataset.templateText || "");
          setUndo({ text: before, name: template.dataset.templateName || "" });
        }
      }, options);
      undoBtn?.addEventListener("click", () => {
        if (!undo || !isEditable() || !instructionsModalTextarea) return;
        const { text } = undo;
        setUndo(null);
        replaceText(text);
      }, options);
      const close = () => { hideModal(); save(); };
      instructionsModalCloseBtn?.addEventListener("click", close, options);
      doneBtn?.addEventListener("click", close, options);
      builder.ownerDocument.addEventListener("keydown", event => {
        if (event.key === "Escape" && instructionsModal?.classList.contains("aipkit-active")) close();
      }, options);
      signal.addEventListener("abort", hideModal, { once: true });
    },
  });
  updateCount();
}
