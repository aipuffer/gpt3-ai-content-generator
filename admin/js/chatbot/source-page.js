/**
 * What it knows, one source at a time: its page takes the list's place inside the sheet, with a way back.
 * Website pages and files show what was read; questions and answers and text are edited in place. Update and
 * Remove are the list's own buttons (same classes and data), so the sheet's handlers run them.
 *
 * An entry is the list row's source: {log, kind, kindLabel, tone, statusLabel, updated: {label, title}, display,
 * target: {provider, storeId, vectorId, logId}}. onSave({entry, kind, text}) returns a promise.
 */
const READ_PARTS = 6;

export function createChatbotSourcePage({
  sheet, section, __, sprintf, escaper,
  _n = (single, plural, count) => (count === 1 ? single : plural),
  parsePreview = () => null,
  parseEditorContent = text => ({type: "text", text, question: "", answer: ""}),
  onSave = () => Promise.resolve(),
}) {
  const back = sheet?.querySelector("[data-aipkit-known-back]");
  const title = sheet?.querySelector(".aipkit_builder_sheet_title");
  const description = sheet?.querySelector(".aipkit_builder_sheet_description");
  const footer = sheet?.querySelector("[data-aipkit-known-footer]");
  const listView = section?.querySelector('[data-aipkit-known-view="list"]');
  const page = section?.querySelector('[data-aipkit-known-view="source"]');
  const remove = footer?.querySelector(".aipkit_known_remove");
  const save = footer?.querySelector("[data-aipkit-known-save]");
  if (!title || !description || !listView || !page) {
    return {open: () => false, close: () => {}, isOpen: () => false, currentId: () => "", refresh: () => {}};
  }
  let current = null;
  let listHeading = null;

  // Footer buttons name the views they belong to: the list, a page to read, or a page being edited.
  const setMode = mode => footer?.querySelectorAll("[data-aipkit-known-for]").forEach(node => {
    node.hidden = !node.dataset.aipkitKnownFor.split(" ").includes(mode);
  });

  const statusLine = entry => {
    const {log, tone} = entry;
    /* translators: %s: when the source was last updated, such as "10 hours ago". */
    const state = tone !== "ready" ? entry.statusLabel : entry.kind === "site"
      /* translators: %s: when the page was learned, such as "10 hours ago". */
      ? sprintf(__("Ready · learned %s", "gpt3-ai-content-generator"), entry.updated.label)
      : sprintf(__("Ready · updated %s", "gpt3-ai-content-generator"), entry.updated.label);
    const update = entry.kind === "site" && log.post_id
      ? `<button type="button" class="aipkit_btn aipkit_btn-secondary aipkit_sources_action_retrain"
          data-provider="${escaper(entry.target.provider)}" data-store-id="${escaper(entry.target.storeId)}"
          data-vector-id="${escaper(entry.target.vectorId)}" data-log-id="${escaper(entry.target.logId)}"
          data-post-id="${escaper(log.post_id)}" data-embedding-provider="${escaper(log.embedding_provider || "")}"
          data-embedding-model="${escaper(log.embedding_model || "")}"${tone === "adding" ? " disabled" : ""}>
          <span class="dashicons dashicons-update" aria-hidden="true"></span><span>${escaper(__("Learn this page again", "gpt3-ai-content-generator"))}</span>
        </button>`
      : "";
    return `<div class="aipkit_known_source_status">
        <span class="aipkit_known_source_state is-${escaper(tone)}" title="${escaper(entry.updated.title)}"><span class="aipkit_known_source_dot" aria-hidden="true"></span>${escaper(state)}</span>
        ${update}
      </div>
      ${tone === "failed" && log.message ? `<p class="aipkit_known_source_reason">${escaper(log.message)}</p>` : ""}`;
  };

  // What was read: an excerpt leads, the rest follows; named parts keep their names.
  const reading = preview => {
    const parts = (preview?.sections || []).slice(0, READ_PARTS).map(part => {
      const label = String(part.label || "").trim();
      const name = label.toLowerCase();
      if (name === "excerpt") {
        return `<p class="aipkit_known_read_lead">${escaper(part.content)}</p>`;
      }
      return name === "content" || !label
        ? `<p>${escaper(part.content)}</p>`
        : `<p><span class="aipkit_known_read_name">${escaper(label)}</span>${escaper(part.content)}</p>`;
    }).join("");
    const terms = [...new Set([...(preview?.categories || []), ...(preview?.tags || [])])];
    const chips = terms.slice(0, READ_PARTS).map(term => `<span class="aipkit_known_chip">${escaper(term)}</span>`).join("")
      + (terms.length > READ_PARTS ? `<span class="aipkit_known_chip">+${(terms.length - READ_PARTS).toLocaleString()}</span>` : "");
    const facts = (preview?.additional || []).slice(0, READ_PARTS)
      .map(fact => `<div><dt>${escaper(fact.label)}</dt><dd>${escaper(fact.value || "—")}</dd></div>`).join("");
    return `<h4 class="aipkit_known_source_label">${escaper(__("What it read", "gpt3-ai-content-generator"))}</h4>
      <div class="aipkit_known_read">${parts || `<p class="aipkit_known_read_empty">${escaper(__("No preview was saved for this source.", "gpt3-ai-content-generator"))}</p>`}</div>
      ${preview?.limited ? `<p class="aipkit_known_source_note">${escaper(__("The first 1,000 characters it read.", "gpt3-ai-content-generator"))}</p>` : ""}
      ${facts ? `<dl class="aipkit_known_facts">${facts}</dl>` : ""}
      ${chips ? `<div class="aipkit_known_chips">${chips}</div>` : ""}`;
  };

  const field = (name, label, hint, value, rows) => `<label class="aipkit_known_field">
      <span class="aipkit_known_field_label">${escaper(label)}${hint ? ` <span class="aipkit_known_field_hint">${escaper(hint)}</span>` : ""}</span>
      <textarea class="aipkit_builder_textarea" rows="${rows}" data-aipkit-known-field="${name}">${escaper(value)}</textarea>
    </label>`;

  const fields = parsed => (parsed.type === "qa"
    ? field("question", __("Question", "gpt3-ai-content-generator"), __("What visitors may ask.", "gpt3-ai-content-generator"), parsed.question, 2)
      + field("answer", __("Answer", "gpt3-ai-content-generator"), __("What the chatbot says.", "gpt3-ai-content-generator"), parsed.answer, 7)
    : field("text", __("Text", "gpt3-ai-content-generator"), "", parsed.text, 12))
    + `<p class="aipkit_known_source_note">${escaper(__("Saving replaces the old version; the chatbot uses the new one once it is ready.", "gpt3-ai-content-generator"))}</p>`;

  const editedText = () => {
    const value = name => page.querySelector(`[data-aipkit-known-field="${name}"]`)?.value.trim() || "";
    return current?.parsed.type === "qa"
      ? (value("question") && value("answer") ? `Q: ${value("question")}\nA: ${value("answer")}` : "")
      : value("text");
  };
  const syncSave = () => {
    if (save && current?.editable) {
      const text = editedText();
      save.disabled = current.saving || !text || text === current.baseline;
    }
  };

  // An uploaded file is read in overlapping parts, so a part can start mid-sentence or repeat the last heading
  // before it. Name each part by a heading of its own, else as the rest of the section it is in, else by its
  // first words after an ellipsis.
  const plainText = text => String(text).replace(/^[\s>#]+/gm, "").replace(/[`*|]+/g, " ").replace(/\s+/g, " ").trim();
  const headingsOf = text => [...text.matchAll(/^\s{0,3}#{1,6}\s+(.+)$/gm)].map(match => plainText(match[1])).filter(Boolean);
  const partTitles = chunks => {
    let section = "";
    return chunks.map((chunk, index) => {
      const text = String(chunk.indexed_content || "");
      const headings = headingsOf(text);
      const before = index ? headingsOf(String(chunks[index - 1].indexed_content || "")) : [];
      const own = headings.find(heading => !before.includes(heading));
      const words = plainText(text);
      const title = own
        /* translators: %s: the heading of the section a part of a file continues, such as "2. All new files". */
        || (section ? sprintf(__("%s, continued", "gpt3-ai-content-generator"), section) : "")
        || (words && !/^(\p{Lu}|[0-9"“(\[])/u.test(words) ? "…" : "") + words;
      if (headings.length) section = headings[headings.length - 1];
      return title.slice(0, 140);
    });
  };

  const fileParts = chunks => {
    const label = `<h4 class="aipkit_known_source_label">${escaper(__("What it read", "gpt3-ai-content-generator"))}</h4>`;
    if (chunks.length === 1) {
      return `${label}<div class="aipkit_known_read aipkit_known_part_single"><p>${escaper(chunks[0].indexed_content || "")}</p></div>`;
    }
    /* translators: %s: number of parts an uploaded file was split into. */
    const count = sprintf(_n("Read in %s part.", "Read in %s parts.", chunks.length, "gpt3-ai-content-generator"), chunks.length.toLocaleString());
    const titles = partTitles(chunks);
    const rows = chunks.map((chunk, index) => `<details class="aipkit_known_part">
        <summary>
          <span class="aipkit_known_part_number"><span class="screen-reader-text">${escaper(__("Part", "gpt3-ai-content-generator"))} </span>${escaper(String(chunk.number))}</span>
          <span class="aipkit_known_part_title">${escaper(titles[index])}</span>
          <span class="aipkit_known_part_chevron dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
        </summary>
        <p class="aipkit_known_part_text">${escaper(chunk.indexed_content || "")}</p>
      </details>`).join("");
    return `${label}<div class="aipkit_known_parts">
        <p class="aipkit_known_parts_head"><strong>${escaper(count)}</strong> ${escaper(__("Long files are split so an answer can use just the part it needs.", "gpt3-ai-content-generator"))}</p>
        ${rows}
      </div>`;
  };

  const open = entry => {
    if (!entry?.log) {
      return false;
    }
    if (!current) {
      listHeading = {title: title.textContent, description: description.textContent};
    }
    const {log} = entry;
    const content = String(log.indexed_content || "");
    const preview = parsePreview(log, content);
    const parsed = entry.kind === "text" ? parseEditorContent(content) : null;
    // A preview cut at 1,000 characters is not the whole text, so saving it would lose the rest.
    const editable = Boolean(parsed) && entry.tone !== "adding" && !preview?.limited;
    current = {entry, parsed, editable, saving: false, baseline: ""};

    title.textContent = entry.display;
    const url = entry.kind === "site" ? String(preview?.sourceUrl || "") : "";
    if (url) {
      description.innerHTML = `<a class="aipkit_known_source_link" href="${escaper(url)}" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span><span>${escaper(url.replace(/^https?:\/\//, ""))}</span></a>`;
    } else {
      description.textContent = entry.kindLabel;
    }
    if (back) {
      back.hidden = false;
    }
    const filePreview = log.file_chunks?.length ? fileParts(log.file_chunks) : "";
    page.innerHTML = statusLine(entry) + (filePreview || (editable ? fields(parsed)
      : reading(preview) + (parsed && preview?.limited
        ? `<p class="aipkit_known_source_note">${escaper(__("This text is too long to edit here. To change it, remove it and add it again.", "gpt3-ai-content-generator"))}</p>`
        : "")));
    listView.hidden = true;
    page.hidden = false;
    page.closest(".aipkit_builder_sheet_body")?.scrollTo?.(0, 0);

    setMode(editable ? "edit" : "read");
    if (remove) {
      Object.assign(remove.dataset, entry.target);
      remove.hidden = !log.file_id;
      remove.disabled = entry.tone === "adding";
    }
    if (save) save.textContent = __("Save", "gpt3-ai-content-generator");
    if (editable) {
      current.baseline = editedText();
      syncSave();
      page.querySelector("[data-aipkit-known-field]")?.focus();
    } else {
      back?.focus();
    }
    return true;
  };

  const refresh = entry => {
    if (!current || current.entry.target.logId !== entry?.target.logId || current.saving) return;
    if (JSON.stringify(current.entry) === JSON.stringify(entry)) return;
    // Never replace an active editor: preserve its draft, cursor and baseline.
    if (current.editable) {
      current.entry = entry;
      const status = page.querySelector(".aipkit_known_source_status");
      page.querySelector(".aipkit_known_source_reason")?.remove();
      if (status) status.outerHTML = statusLine(entry);
      return;
    }
    open(entry);
  };

  const close = () => {
    if (!current) {
      return;
    }
    current = null;
    title.textContent = listHeading.title;
    description.textContent = listHeading.description;
    if (back) {
      back.hidden = true;
    }
    page.hidden = true;
    page.replaceChildren();
    listView.hidden = false;
    setMode("list");
  };

  page.addEventListener("input", syncSave);
  save?.addEventListener("click", async () => {
    const text = editedText();
    if (!current?.editable || current.saving || !text || text === current.baseline) {
      return;
    }
    const editing = current;
    const label = save.textContent;
    editing.saving = true;
    const fields = [...page.querySelectorAll("[data-aipkit-known-field]")];
    fields.forEach(field => { field.disabled = true; });
    if (remove) remove.disabled = true;
    save.disabled = true;
    save.textContent = __("Saving...", "gpt3-ai-content-generator");
    try {
      await onSave({entry: editing.entry, kind: editing.parsed.type === "qa" ? "qa" : "text", text});
      if (current === editing) {
        close();
      }
    } catch (error) {
      // The sheet's status line says what went wrong; the edit stays for another try.
    } finally {
      editing.saving = false;
      fields.forEach(field => { field.disabled = false; });
      if (current === editing) {
        if (remove) remove.disabled = editing.entry.tone === "adding";
        save.textContent = label;
      } else if (!current) save.textContent = label;
      syncSave();
    }
  });

  return {open, close, refresh, isOpen: () => Boolean(current), currentId: () => current?.entry.target.logId || ""};
}
