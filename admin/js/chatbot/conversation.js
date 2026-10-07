/** Starter questions and the shared consent-settings panel/gate. */
export function createChatbotConversation({
  builder,
  modelPopoverPanel,
  startersPanel,
  consentPanel,
  syncInlineSettingsPanelState,
  registerAdvancedDetailPanelCloser,
  closeOtherAdvancedDetailPanels,
  __,
  sprintf,
}) {
  const starters = {
    panel: startersPanel,
    id: "aipkit_starters_panel",
    triggerSelector: ".aipkit_starters_config_btn",
    activeTrigger: null,
  };
  const consent = {
    panel: consentPanel,
    id: "aipkit_consent_panel",
    triggerSelector: ".aipkit_consent_config_btn",
    activeTrigger: null,
  };
  const panels = [starters, consent];

  const closePanel = (state) => {
    if (!state.panel) {
      return;
    }
    state.panel.classList.remove("is-open");
    state.panel.setAttribute("aria-hidden", "true");
    syncInlineSettingsPanelState(state.panel, false);
    if (state.activeTrigger) {
      state.activeTrigger.setAttribute("aria-expanded", "false");
      state.activeTrigger = null;
    }
  };
  const closeStartersPanel = () => closePanel(starters);
  registerAdvancedDetailPanelCloser(startersPanel, closeStartersPanel);
  registerAdvancedDetailPanelCloser(consentPanel, () => closePanel(consent));

  const updateConsentControls = () => {
    const interfaceControlsRoot = modelPopoverPanel
      ? modelPopoverPanel.querySelector("[data-aipkit-interface-controls]")
      : builder.querySelector("[data-aipkit-interface-controls]");
    const controlsPanel =
      (interfaceControlsRoot &&
        interfaceControlsRoot.closest(".aipkit_settings_panel_body")) ||
      (modelPopoverPanel
        ? modelPopoverPanel.querySelector('[data-aipkit-settings-panel="appearance"]')
        : builder.querySelector('[data-aipkit-settings-panel="appearance"]'));
    if (!controlsPanel) {
      return;
    }
    const consentToggle = controlsPanel.querySelector(
      '[name="enable_consent_compliance"]'
    );
    const consentConfigBtn = controlsPanel.querySelector(consent.triggerSelector);
    const showConsentFields = consentToggle
      ? consentToggle.tagName === "SELECT"
        ? consentToggle.value === "1"
        : consentToggle.checked
      : false;
    if (consentConfigBtn) {
      consentConfigBtn.hidden = false;
      consentConfigBtn.disabled = !showConsentFields;
      consentConfigBtn.setAttribute(
        "aria-disabled",
        showConsentFields ? "false" : "true"
      );
      if (!showConsentFields) {
        consentConfigBtn.setAttribute("aria-expanded", "false");
        closePanel(consent);
      }
    }
  };

  const bindToggles = () => {
    panels.forEach((state) => {
      builder.addEventListener("click", (event) => {
        const trigger = event.target.closest(state.triggerSelector);
        if (!trigger) {
          return;
        }
        event.preventDefault();
        if (!state.panel) {
          return;
        }
        const isOpen = !state.panel.classList.contains("is-open");
        if (isOpen) {
          closeOtherAdvancedDetailPanels(state.panel);
        }
        state.panel.classList.toggle("is-open", isOpen);
        state.panel.setAttribute("aria-hidden", isOpen ? "false" : "true");
        syncInlineSettingsPanelState(state.panel, isOpen);
        if (state.activeTrigger && state.activeTrigger !== trigger) {
          state.activeTrigger.setAttribute("aria-expanded", "false");
        }
        trigger.setAttribute("aria-expanded", isOpen ? "true" : "false");
        state.activeTrigger = isOpen ? trigger : null;
      });
    });
  };

  const bindOutsideClicks = () => {
    panels.forEach((state) => {
      document.addEventListener("click", (event) => {
        if (!state.panel || !state.panel.classList.contains("is-open")) {
          return;
        }
        if (state.panel.classList.contains("aipkit_inline_settings_content")) {
          return;
        }
        if (
          event.target.closest(state.triggerSelector) ||
          event.target.closest(`[data-aipkit-inline-settings-target="${state.id}"]`) ||
          event.target.closest(`#${state.id}`)
        ) {
          return;
        }
        closePanel(state);
      });
    });
  };


  /**
   * Suggested questions as a list: type, reorder, remove, or add an idea.
   * The saved textarea (one question per line) stays the source of truth, so saving,
   * bot switching and restores work on it as before; the returned sync redraws from it.
   */
  const bindStarterList = () => {
    const editor = startersPanel?.querySelector("[data-aipkit-starter-editor]");
    if (!editor || editor.dataset.starterListBound) {
      return () => {};
    }
    editor.dataset.starterListBound = "1";
    const max = Number(editor.dataset.max) || 6;
    const list = editor.querySelector("[data-aipkit-starter-list]");
    const template = editor.querySelector("template[data-aipkit-starter-template]");
    const addButton = editor.querySelector("[data-aipkit-starter-add]");
    const count = editor.querySelector("[data-aipkit-starter-count]");
    const ideasBlock = editor.querySelector("[data-aipkit-starter-ideas]");
    const ideas = Array.from(editor.querySelectorAll("[data-aipkit-starter-idea]"));
    // Bot switches change the field's id, so find it when it is used.
    const source = () => editor.querySelector('textarea[name="conversation_starters"]');
    const rows = () => Array.from(list.children);
    const inputOf = (row) => row?.querySelector("[data-aipkit-starter-input]") || null;
    const clean = (text) => String(text || "").replace(/\s+/g, " ").trim();
    const values = () => rows().map((row) => clean(inputOf(row).value)).filter(Boolean);
    const parse = (text) => String(text || "").split(/\r?\n/).map(clean).filter(Boolean).slice(0, max);

    // Removing a focused row fires focusout; the empty-row cleanup below must not remove it a second time.
    let detaching = false;
    const detach = (removeNodes) => {
      detaching = true;
      try {
        removeNodes();
      } finally {
        detaching = false;
      }
    };

    const makeRow = (text = "") => {
      const row = template.content.firstElementChild.cloneNode(true);
      inputOf(row).value = text;
      return row;
    };

    const update = () => {
      const all = rows();
      const labels = [
        ["[data-aipkit-starter-input]", editor.dataset.questionLabel],
        ["[data-aipkit-starter-handle]", editor.dataset.moveLabel],
        ["[data-aipkit-starter-remove]", editor.dataset.removeLabel],
      ];
      all.forEach((row, index) => {
        labels.forEach(([selector, label]) => {
          row.querySelector(selector)?.setAttribute("aria-label", sprintf(label || "%d", index + 1));
        });
      });
      /* translators: 1: number of suggested questions, 2: the most allowed. */
      count.textContent = sprintf(__("%1$d of %2$d", "gpt3-ai-content-generator"), all.length, max);
      const full = all.length >= max;
      addButton.hidden = full;
      const taken = new Set(values().map((value) => value.toLowerCase()));
      ideas.forEach((idea) => {
        idea.hidden = taken.has(clean(idea.dataset.aipkitStarterIdea).toLowerCase());
      });
      ideasBlock.hidden = full || ideas.every((idea) => idea.hidden);
    };

    // Typing drafts the field; a finished edit, add, remove or move saves it.
    const write = ({ save = false } = {}) => {
      const field = source();
      if (field) {
        const next = values().join("\n");
        if (field.value !== next) {
          field.value = next;
          field.dispatchEvent(new Event("input", { bubbles: true }));
        }
        if (save) {
          field.dispatchEvent(new Event("change", { bubbles: true }));
        }
      }
      update();
    };

    const sync = () => {
      const field = source();
      if (!field) {
        return;
      }
      const items = parse(field.value);
      // A save echoes the same text back; keep the rows (and any empty one being typed) as they are.
      if (items.join("\n") !== values().join("\n")) {
        detach(() => list.replaceChildren(...items.map((text) => makeRow(text))));
      }
      update();
    };

    const addRow = (text = "", after = null) => {
      if (rows().length >= max) {
        return null;
      }
      const row = makeRow(text);
      list.insertBefore(row, after ? after.nextElementSibling : null);
      return row;
    };

    const removeRow = (row) => {
      const all = rows();
      const index = all.indexOf(row);
      detach(() => row.remove());
      write({ save: true });
      const next = rows()[Math.min(index, rows().length - 1)];
      (inputOf(next) || addButton).focus();
    };

    editor.addEventListener("input", (event) => {
      if (event.target.matches("[data-aipkit-starter-input]")) {
        write();
      }
    });
    editor.addEventListener("change", (event) => {
      if (event.target.matches("[data-aipkit-starter-input]")) {
        write({ save: true });
      }
    });
    // A row left empty goes away when focus leaves it.
    editor.addEventListener("focusout", (event) => {
      const input = event.target.closest?.("[data-aipkit-starter-input]");
      if (detaching || !input || clean(input.value) || !input.isConnected) {
        return;
      }
      const row = input.closest(".aipkit_starter_item");
      if (row && row.contains(event.relatedTarget)) {
        return;
      }
      detach(() => row?.remove());
      write({ save: true });
    });

    editor.addEventListener("click", (event) => {
      const remove = event.target.closest("[data-aipkit-starter-remove]");
      if (remove) {
        event.preventDefault();
        removeRow(remove.closest(".aipkit_starter_item"));
        return;
      }
      if (event.target.closest("[data-aipkit-starter-add]")) {
        event.preventDefault();
        inputOf(addRow())?.focus();
        update();
        return;
      }
      const idea = event.target.closest("[data-aipkit-starter-idea]");
      if (idea) {
        event.preventDefault();
        const row = addRow(clean(idea.dataset.aipkitStarterIdea));
        if (row) {
          write({ save: true });
          // The chosen idea hides itself (and the list may now be full); keep focus in the editor.
          const nextIdea = ideasBlock.hidden ? null : ideas.find((other) => !other.hidden);
          (nextIdea || (addButton.hidden ? inputOf(row) : addButton)).focus();
        }
      }
    });

    // Neighbours move around the row rather than the row itself, so it keeps focus and pointer capture.
    const move = (row, step) => {
      const neighbour = step < 0 ? row.previousElementSibling : row.nextElementSibling;
      if (!neighbour) {
        return false;
      }
      list.insertBefore(neighbour, step < 0 ? row.nextElementSibling : row);
      return true;
    };

    editor.addEventListener("keydown", (event) => {
      const handle = event.target.closest("[data-aipkit-starter-handle]");
      if (handle && (event.key === "ArrowUp" || event.key === "ArrowDown")) {
        event.preventDefault();
        if (move(handle.closest(".aipkit_starter_item"), event.key === "ArrowUp" ? -1 : 1)) {
          write({ save: true });
        }
        return;
      }
      const input = event.target.closest("[data-aipkit-starter-input]");
      if (!input) {
        return;
      }
      const row = input.closest(".aipkit_starter_item");
      if (event.key === "Enter") {
        // Enter finishes this question and starts the next one while there is room.
        event.preventDefault();
        write({ save: true });
        if (clean(input.value)) {
          inputOf(addRow("", row))?.focus();
          update();
        }
      } else if (event.key === "Backspace" && !input.value && rows().length > 0) {
        event.preventDefault();
        const previous = inputOf(row.previousElementSibling);
        detach(() => row.remove());
        write({ save: true });
        (previous || inputOf(rows()[0]) || addButton).focus();
      }
    });

    // Drag by the handle; the row moves as the pointer passes its neighbours' middles.
    editor.addEventListener("pointerdown", (event) => {
      const handle = event.target.closest("[data-aipkit-starter-handle]");
      if (!handle || event.button !== 0) {
        return;
      }
      event.preventDefault();
      const row = handle.closest(".aipkit_starter_item");
      const startOrder = values().join("\n");
      const drag = new AbortController();
      handle.setPointerCapture(event.pointerId);
      row.classList.add("is-dragging");
      const middle = (node) => {
        const rect = node.getBoundingClientRect();
        return rect.top + rect.height / 2;
      };
      handle.addEventListener("pointermove", (moveEvent) => {
        // A quick drag can pass several rows in one move.
        while (row.previousElementSibling && moveEvent.clientY < middle(row.previousElementSibling)) {
          move(row, -1);
        }
        while (row.nextElementSibling && moveEvent.clientY > middle(row.nextElementSibling)) {
          move(row, 1);
        }
      }, { signal: drag.signal });
      const finish = () => {
        drag.abort();
        row.classList.remove("is-dragging");
        if (handle.hasPointerCapture(event.pointerId)) {
          handle.releasePointerCapture(event.pointerId);
        }
        if (values().join("\n") !== startOrder) {
          write({ save: true });
        }
        handle.focus();
      };
      handle.addEventListener("pointerup", finish, { signal: drag.signal });
      handle.addEventListener("pointercancel", finish, { signal: drag.signal });
    });

    sync();
    return sync;
  };

  return {
    closeStartersPanel,
    updateConsentControls,
    bindToggles,
    bindOutsideClicks,
    bindStarterList,
  };
}
