import "./enhancer-utils.js";

/** Shared editor menu, generation preview and action management. */
(function () {
  "use strict";

  const getTexts = () => window.aipkit_post_enhancer?.text || {};

  const escapeHtml = window.aipkit_escapeHtml || ((value) =>
    String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;"));

  const positionLabel = (position) => {
    const texts = getTexts();
    if (position === "before") {
      return texts.insert_before || "Insert before";
    }
    if (position === "after") {
      return texts.insert_after || "Insert after";
    }
    return texts.replace_selection || "Replace selection";
  };

  (function () {
    "use strict";

    if (window.aipkit_openEditorAssistantMenu) {
      return;
    }

    let actionMenu = null;
    let actionMenuAnchor = null;
    let actionMenuPositionFrame = null;
    let actionMenuEditorDocuments = [];
    let previewOverlay = null;

    const closeActionMenu = () => {
      if (!actionMenu) {
        return;
      }
      actionMenuEditorDocuments.forEach((editorDocument) => {
        editorDocument.removeEventListener("pointerdown", handleEditorDocumentInteraction, true);
        editorDocument.removeEventListener("keydown", handleEditorDocumentInteraction, true);
      });
      actionMenuEditorDocuments = [];
      actionMenu.removeEventListener("focusout", handleActionMenuFocusOut);
      actionMenu.remove();
      actionMenu = null;
      actionMenuAnchor = null;
      if (actionMenuPositionFrame) {
        window.cancelAnimationFrame(actionMenuPositionFrame);
        actionMenuPositionFrame = null;
      }
      document.removeEventListener("pointerdown", handleOutsideMenuPointer, true);
      document.removeEventListener("keydown", handleMenuKeydown, true);
      window.removeEventListener("resize", handleActionMenuViewportChange);
      window.removeEventListener("scroll", handleActionMenuViewportChange, true);
    };

    function handleOutsideMenuPointer(event) {
      if (actionMenu && !actionMenu.contains(event.target)) {
        closeActionMenu();
      }
    }

    function handleMenuKeydown(event) {
      if (event.key === "Escape") {
        event.preventDefault();
        closeActionMenu();
      }
    }

    function handleActionMenuFocusOut(event) {
      const menuAtBlur = actionMenu;
      window.setTimeout(() => {
        if (!menuAtBlur || actionMenu !== menuAtBlur) {
          return;
        }
        const nextTarget = event.relatedTarget || document.activeElement;
        if (!nextTarget || !menuAtBlur.contains(nextTarget)) {
          closeActionMenu();
        }
      }, 0);
    }

    function handleEditorDocumentInteraction() {
      closeActionMenu();
    }

    const bindEditorDocumentDismissal = () => {
      actionMenuEditorDocuments = Array.from(document.querySelectorAll("iframe"))
        .map((frame) => {
          try {
            return frame.contentDocument;
          } catch (error) {
            return null;
          }
        })
        .filter(Boolean);

      actionMenuEditorDocuments.forEach((editorDocument) => {
        editorDocument.addEventListener("pointerdown", handleEditorDocumentInteraction, true);
        editorDocument.addEventListener("keydown", handleEditorDocumentInteraction, true);
      });
    };

    const placeActionMenu = (anchor) => {
      if (!actionMenu || !anchor?.getBoundingClientRect) {
        return;
      }
      const rect = anchor.getBoundingClientRect();
      const width = Math.min(280, window.innerWidth - 24);
      const menuRect = actionMenu.getBoundingClientRect();
      const left = Math.max(12, Math.min(rect.left, window.innerWidth - width - 12));
      const preferredTop = rect.bottom + 8;
      const top = preferredTop + menuRect.height <= window.innerHeight - 12
        ? preferredTop
        : Math.max(12, rect.top - menuRect.height - 8);

      actionMenu.style.width = `${width}px`;
      actionMenu.style.left = `${left}px`;
      actionMenu.style.top = `${top}px`;
    };

    function handleActionMenuViewportChange() {
      if (!actionMenu || !actionMenuAnchor || actionMenuPositionFrame) {
        return;
      }
      actionMenuPositionFrame = window.requestAnimationFrame(() => {
        actionMenuPositionFrame = null;
        placeActionMenu(actionMenuAnchor);
      });
    }

    window.aipkit_openEditorAssistantMenu = ({
      anchor,
      actions = [],
      onAction,
      onCustomize,
    } = {}) => {
      closeActionMenu();
      actionMenuAnchor = anchor;
      const texts = getTexts();
      const availableActions = Array.isArray(actions)
        ? actions.filter((action) => action?.label && action?.prompt)
        : [];

      actionMenu = document.createElement("div");
      actionMenu.className = "aipkit_editor_assistant_action_menu";
      actionMenu.setAttribute("role", "menu");
      actionMenu.setAttribute("aria-label", texts.assistant_menu_title || "Assistant menu");
      actionMenu.innerHTML = `
      <div class="aipkit_editor_assistant_action_search_wrap">
        <span class="dashicons dashicons-search" aria-hidden="true"></span>
        <input type="search" class="aipkit_editor_assistant_action_search" placeholder="${escapeHtml(texts.search_actions || "Search actions")}" aria-label="${escapeHtml(texts.search_actions || "Search actions")}" />
      </div>
      <div class="aipkit_editor_assistant_action_items"></div>
      ${typeof onCustomize === "function" ? `
        <button type="button" class="aipkit_editor_assistant_action_customize" role="menuitem">
          <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
          <span>${escapeHtml(texts.customize_actions || "Customize menu")}</span>
        </button>
      ` : ""}
    `;

      const items = actionMenu.querySelector(".aipkit_editor_assistant_action_items");
      const renderActions = (query = "") => {
        const normalizedQuery = query.trim().toLocaleLowerCase();
        const matches = availableActions.filter((action) =>
          !normalizedQuery || String(action.label).toLocaleLowerCase().includes(normalizedQuery)
        );
        items.innerHTML = "";
        matches.forEach((action) => {
          const button = document.createElement("button");
          button.type = "button";
          button.className = "aipkit_editor_assistant_action_item";
          button.setAttribute("role", "menuitem");
          button.textContent = action.label;
          button.addEventListener("click", () => {
            closeActionMenu();
            if (typeof onAction === "function") {
              onAction(action);
            }
          });
          items.appendChild(button);
        });
        if (!matches.length) {
          const empty = document.createElement("div");
          empty.className = "aipkit_editor_assistant_action_empty";
          empty.textContent = texts.no_actions || "No matching actions";
          items.appendChild(empty);
        }
      };

      actionMenu.querySelector(".aipkit_editor_assistant_action_search")
        ?.addEventListener("input", (event) => renderActions(event.target.value));
      actionMenu.querySelector(".aipkit_editor_assistant_action_customize")
        ?.addEventListener("click", () => {
          closeActionMenu();
          onCustomize();
        });

      document.body.appendChild(actionMenu);
      renderActions();
      placeActionMenu(anchor);
      bindEditorDocumentDismissal();
      document.addEventListener("pointerdown", handleOutsideMenuPointer, true);
      document.addEventListener("keydown", handleMenuKeydown, true);
      actionMenu.addEventListener("focusout", handleActionMenuFocusOut);
      window.addEventListener("resize", handleActionMenuViewportChange);
      window.addEventListener("scroll", handleActionMenuViewportChange, true);
      window.requestAnimationFrame(() => {
        actionMenu?.querySelector(".aipkit_editor_assistant_action_search")?.focus();
      });
    };

    window.aipkit_formatEditorAssistantPrompt = (template, selectedText) =>
      String(template || "")
        .replace(/\{selected_text\}/g, String(selectedText || ""))
        .replace(/%s/g, String(selectedText || ""));

    const closePreview = () => {
      if (!previewOverlay) {
        return;
      }
      previewOverlay.remove();
      previewOverlay = null;
    };

    window.aipkit_runEditorAssistantPreview = ({
      actionLabel = "Assistant",
      insertPosition = "replace",
      generate,
    } = {}) => new Promise((resolve) => {
      closePreview();
      const texts = getTexts();
      let currentResult = "";
      let requestVersion = 0;
      let settled = false;

      const finish = (result) => {
        if (settled) {
          return;
        }
        settled = true;
        closePreview();
        resolve(result);
      };

      previewOverlay = document.createElement("div");
      previewOverlay.className = "aipkit_editor_assistant_preview";
      previewOverlay.innerHTML = `
      <div class="aipkit_editor_assistant_preview_shell" role="dialog" aria-modal="true" aria-labelledby="aipkit_editor_assistant_preview_title" tabindex="-1">
        <div class="aipkit_editor_assistant_preview_header">
          <div class="aipkit_editor_assistant_preview_heading">
            <span class="aipkit_assistant_symbol" aria-hidden="true"></span>
            <strong id="aipkit_editor_assistant_preview_title">${escapeHtml(actionLabel)}</strong>
          </div>
          <span class="aipkit_editor_assistant_preview_position">${escapeHtml(positionLabel(insertPosition))}</span>
        </div>
        <div class="aipkit_editor_assistant_preview_body"></div>
      </div>
    `;
      document.body.appendChild(previewOverlay);
      const owner = previewOverlay;

      const body = previewOverlay.querySelector(".aipkit_editor_assistant_preview_body");

      const renderLoading = () => {
        body.innerHTML = `
        <div class="aipkit_editor_assistant_preview_loading">
          <span class="aipkit_spinner" aria-hidden="true"></span>
          <span>${escapeHtml(texts.rewriting_selection || "Rewriting selection…")}</span>
        </div>
        <div class="aipkit_editor_assistant_preview_loading_actions">
          <button type="button" class="aipkit_editor_assistant_ui_btn aipkit_editor_assistant_ui_btn--secondary" data-preview-discard>${escapeHtml(texts.cancel || "Cancel")}</button>
        </div>
      `;
        body.querySelector("[data-preview-discard]")?.addEventListener("click", () => finish(null));
      };

      const renderResult = () => {
        body.innerHTML = `
        <div class="aipkit_editor_assistant_preview_result"></div>
        <div class="aipkit_editor_assistant_preview_actions">
          <div class="aipkit_editor_assistant_preview_actions_start">
            <button type="button" class="aipkit_editor_assistant_ui_btn aipkit_editor_assistant_ui_btn--primary" data-preview-accept>${escapeHtml(texts.accept || "Accept")}</button>
            <button type="button" class="aipkit_editor_assistant_ui_btn aipkit_editor_assistant_ui_btn--secondary" data-preview-retry>
              <span class="dashicons dashicons-update" aria-hidden="true"></span>
              ${escapeHtml(texts.try_again || "Try again")}
            </button>
          </div>
          <button type="button" class="aipkit_editor_assistant_ui_btn aipkit_editor_assistant_ui_btn--secondary" data-preview-discard>${escapeHtml(texts.discard || "Discard")}</button>
        </div>
      `;
        body.querySelector(".aipkit_editor_assistant_preview_result").innerHTML = window.aipkit_sanitizeAssistantHtml(currentResult);
        body.querySelector("[data-preview-accept]")?.addEventListener("click", () => finish(currentResult));
        body.querySelector("[data-preview-retry]")?.addEventListener("click", runGeneration);
        body.querySelector("[data-preview-discard]")?.addEventListener("click", () => finish(null));
      };

      const renderError = (error) => {
        const action = window.aipkit_assistantErrorAction(error);
        const message = window.aipkit_assistantErrorMessage(error);
        const label = action === "check" ? texts.check_status || "Check status"
          : action === "generate" ? texts.generate_again || "Generate again" : texts.try_again || "Try again";
        body.innerHTML = `
        <div class="aipkit_editor_assistant_preview_error" role="alert">
          <span class="dashicons dashicons-warning" aria-hidden="true"></span>
          <div>
            <strong>${escapeHtml(texts.generation_failed || "Could not generate a result.")}</strong>
            <span>${escapeHtml(message)}</span>
          </div>
        </div>
        <div class="aipkit_editor_assistant_preview_actions aipkit_editor_assistant_preview_actions--error">
          ${action ? `<button type="button" class="aipkit_editor_assistant_ui_btn aipkit_editor_assistant_ui_btn--secondary" data-preview-retry>${escapeHtml(label)}</button>` : ""}
          <button type="button" class="aipkit_editor_assistant_ui_btn aipkit_editor_assistant_ui_btn--secondary" data-preview-discard>${escapeHtml(texts.discard || "Discard")}</button>
        </div>
      `;
        body.querySelector("[data-preview-retry]")?.addEventListener("click", async (event) => {
          if (action !== "check") { runGeneration(); return; }
          event.currentTarget.disabled = true;
          const checkedError = await window.aipkit_checkAssistantRequest(error);
          if (!settled && owner === previewOverlay) renderError(checkedError);
        });
        body.querySelector("[data-preview-discard]")?.addEventListener("click", () => finish(null));
      };

      async function runGeneration() {
        const version = ++requestVersion;
        renderLoading();
        try {
          if (typeof generate !== "function") {
            throw new Error("Assistant request is not available.");
          }
          const result = await generate();
          if (settled || version !== requestVersion) {
            return;
          }
          currentResult = String(result || "").trim();
          if (!currentResult) {
            throw new Error("AI did not return any text.");
          }
          renderResult();
        } catch (error) {
          if (!settled && version === requestVersion) {
            renderError(error);
          }
        }
      }

      previewOverlay.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
          event.preventDefault();
          finish(null);
        }
      });
      window.requestAnimationFrame(() =>
        previewOverlay?.querySelector(".aipkit_editor_assistant_preview_shell")?.focus()
      );
      runGeneration();
    });
  })();

  (function () {
    "use strict";

    if (window.aipkit_openEnhancerActionsManager) {
      return;
    }

    const POSITIONS = ["replace", "after", "before"];
    const DRAFT_PREFIX = "new-";
    let overlay = null;
    let selectedId = "";
    let draftAction = null;
    let updateCallback = null;
    let lastFocusedElement = null;
    let busy = false;
    let draggedId = "";

    const getConfig = () => window.aipkit_post_enhancer || {};

    const normalizePosition = (position) =>
      POSITIONS.includes(String(position || "")) ? String(position) : "replace";
    const normalizeAction = (action = {}) => ({
      id: String(action.id || ""),
      label: String(action.label || ""),
      prompt: String(action.prompt || "").replace(/%s/g, "{selected_text}"),
      insert_position: normalizePosition(action.insert_position),
    });
    const normalizeActions = (actions) =>
      Array.isArray(actions)
        ? actions.map(normalizeAction).filter((action) => action.id)
        : [];
    const getActions = () => normalizeActions(getConfig().actions || []);
    const getMaxActions = () => {
      const max = parseInt(getConfig().max_actions, 10);
      return Number.isFinite(max) && max > 0 ? max : 20;
    };
    const isDraftSelected = () => draftAction?.id === selectedId;
    const getSelectedAction = () =>
      isDraftSelected()
        ? normalizeAction(draftAction)
        : getActions().find((action) => action.id === selectedId) || null;

    const request = async (action, data = {}) => {
      if (typeof window.aipkit_apiRequest !== "function") {
        throw new Error("API function is not available.");
      }
      return window.aipkit_apiRequest(action, {
        ...data,
        _ajax_nonce: getConfig().nonce_manage_actions,
      });
    };

    const setActions = (actions) => {
      const config = getConfig();
      config.actions = normalizeActions(actions);
      window.aipkit_post_enhancer = config;
      window.dispatchEvent(new CustomEvent("aipkit:enhancerActionsUpdated", {
        detail: { actions: config.actions },
      }));
      if (typeof updateCallback === "function") {
        updateCallback(config.actions);
      }
    };

    const refs = () => ({
      list: overlay?.querySelector(".aipkit_editor_assistant_action_list"),
      editorTitle: overlay?.querySelector(".aipkit_editor_assistant_editor_title"),
      label: overlay?.querySelector(".aipkit_editor_assistant_label"),
      position: overlay?.querySelector(".aipkit_editor_assistant_position"),
      prompt: overlay?.querySelector(".aipkit_editor_assistant_prompt"),
      add: overlay?.querySelector(".aipkit_editor_assistant_add"),
      save: overlay?.querySelector(".aipkit_editor_assistant_save"),
      status: overlay?.querySelector(".aipkit_editor_assistant_manager_status"),
      errors: overlay?.querySelector(".aipkit_editor_assistant_errors"),
    });

    const showStatus = (type = "", message = "") => {
      const status = refs().status;
      if (!status) {
        return;
      }
      status.textContent = message;
      status.className = "aipkit_editor_assistant_manager_status";
      if (type && message) {
        status.classList.add(`is-${type}`);
      }
    };

    const setBusy = (nextBusy, message = "") => {
      busy = nextBusy;
      overlay?.classList.toggle("is-busy", busy);
      overlay?.querySelectorAll("button, input, select, textarea").forEach((control) => {
        control.disabled = busy && !control.classList.contains("aipkit_editor_assistant_modal_close");
      });
      if (nextBusy && message) {
        showStatus("loading", message);
      }
    };

    const clearErrors = () => {
      const currentRefs = refs();
      if (currentRefs.errors) {
        currentRefs.errors.innerHTML = "";
      }
      [currentRefs.label, currentRefs.prompt].forEach((field) => {
        field?.classList.remove("aipkit_editor_assistant_field_error");
        field?.removeAttribute("aria-invalid");
      });
    };

    const addError = (field, message) => {
      field?.classList.add("aipkit_editor_assistant_field_error");
      field?.setAttribute("aria-invalid", "true");
      const errors = refs().errors;
      if (errors) {
        const row = document.createElement("div");
        row.textContent = message;
        errors.appendChild(row);
      }
    };

    const renderList = () => {
      const list = refs().list;
      if (!list) {
        return;
      }
      const actions = draftAction ? [...getActions(), normalizeAction(draftAction)] : getActions();
      list.innerHTML = "";
      actions.forEach((action) => {
        const row = document.createElement("div");
        row.className = "aipkit_editor_assistant_action_row";
        row.classList.toggle("is-selected", action.id === selectedId);
        row.dataset.actionId = action.id;
        row.draggable = !action.id.startsWith(DRAFT_PREFIX);
        row.innerHTML = `
        <button type="button" class="aipkit_editor_assistant_drag_handle" aria-label="Reorder ${escapeHtml(action.label || getTexts().new_action || "New action")}">
          <span class="dashicons dashicons-menu-alt3" aria-hidden="true"></span>
        </button>
        <button type="button" class="aipkit_editor_assistant_action_select" data-select-action>
          <strong>${escapeHtml(action.label || getTexts().new_action || "New action")}</strong>
          <span>${escapeHtml(positionLabel(action.insert_position))}</span>
        </button>
        <button type="button" class="aipkit_editor_assistant_action_delete" data-delete-action aria-label="${escapeHtml(getTexts().delete_action || "Delete action")}">
          <span class="dashicons dashicons-trash" aria-hidden="true"></span>
        </button>
      `;
        const dragHandle = row.querySelector(".aipkit_editor_assistant_drag_handle");
        const canReorder = !action.id.startsWith(DRAFT_PREFIX);
        dragHandle.disabled = !canReorder;
        list.appendChild(row);
      });
    };

    const populateEditor = () => {
      const action = getSelectedAction();
      const currentRefs = refs();
      if (currentRefs.editorTitle) {
        currentRefs.editorTitle.textContent = action
          ? `Editing “${action.label || getTexts().new_action || "New action"}”`
          : "";
      }
      if (currentRefs.label) {
        currentRefs.label.value = action?.label || "";
      }
      if (currentRefs.position) {
        currentRefs.position.value = action?.insert_position || "replace";
      }
      if (currentRefs.prompt) {
        currentRefs.prompt.value = action?.prompt || "";
        currentRefs.prompt.scrollTop = 0;
      }
      [currentRefs.label, currentRefs.position, currentRefs.prompt, currentRefs.save]
        .forEach((control) => {
          if (control) {
            control.disabled = busy || !action;
          }
        });
      clearErrors();
      renderList();
    };

    const selectAction = (id) => {
      const exists = draftAction?.id === id || getActions().some((action) => action.id === id);
      if (!exists) {
        return;
      }
      if (draftAction && id !== draftAction.id) {
        draftAction = null;
      }
      selectedId = id;
      populateEditor();
    };

    const closeManager = () => {
      if (!overlay) {
        return;
      }
      overlay.classList.remove("aipkit-active");
      overlay.setAttribute("aria-hidden", "true");
      draftAction = null;
      showStatus();
      if (lastFocusedElement?.focus) {
        lastFocusedElement.focus();
      }
      lastFocusedElement = null;
      updateCallback = null;
    };

    const createDraft = () => {
      if (busy) {
        return;
      }
      if (getActions().length >= getMaxActions()) {
        showStatus("error", getTexts().max_actions_reached || "Maximum actions reached.");
        return;
      }
      draftAction = {
        id: `${DRAFT_PREFIX}${Date.now()}`,
        label: "",
        prompt: "Use this selected text: {selected_text}",
        insert_position: "replace",
      };
      selectedId = draftAction.id;
      populateEditor();
      refs().label?.focus();
    };

    const deleteAction = async (id) => {
      if (busy) {
        return;
      }
      if (id.startsWith(DRAFT_PREFIX)) {
        draftAction = null;
        selectedId = getActions()[0]?.id || "";
        populateEditor();
        return;
      }
      const actions = getActions();
      const index = actions.findIndex((action) => action.id === id);
      if (index < 0) {
        return;
      }
      setBusy(true, getTexts().deleting_action || "Deleting…");
      try {
        const response = await request("aipkit_delete_enhancer_action", { id });
        const nextActions = normalizeActions(
          response.actions || actions.filter((action) => action.id !== id)
        );
        setActions(nextActions);
        selectedId = nextActions[Math.min(index, nextActions.length - 1)]?.id || "";
        showStatus();
        populateEditor();
      } catch (error) {
        showStatus("error", error?.message || "Could not delete the action.");
      } finally {
        setBusy(false);
      }
    };

    const persistOrder = async (nextActions, previousActions) => {
      setActions(nextActions);
      renderList();
      setBusy(true, getTexts().saving_order || "Saving order…");
      try {
        const response = await request("aipkit_reorder_enhancer_actions", {
          order: nextActions.map((action) => action.id),
        });
        setActions(response.actions || nextActions);
        showStatus();
      } catch (error) {
        setActions(previousActions);
        showStatus("error", error?.message || "Could not save the order.");
      } finally {
        setBusy(false);
        populateEditor();
      }
    };

    const resetActions = async () => {
      setBusy(true, getTexts().resetting_actions || "Restoring…");
      try {
        const response = await request("aipkit_reset_enhancer_actions");
        setActions(response.actions || []);
        draftAction = null;
        selectedId = getActions()[0]?.id || "";
        showStatus();
        populateEditor();
      } catch (error) {
        showStatus("error", error?.message || "Could not restore the default actions.");
      } finally {
        setBusy(false);
      }
    };

    const confirmReset = () => {
      const texts = getTexts();
      if (typeof window.aipkit_showConfirmModal === "function") {
        window.aipkit_showConfirmModal(
          texts.confirm_reset_actions || "Restore the default actions? This replaces current customizations.",
          {
            title: texts.reset_actions || "Restore default actions",
            confirmText: texts.reset_actions || "Restore default actions",
            cancelText: texts.cancel || "Cancel",
            variant: "warning",
            onConfirm: () => void resetActions(),
          }
        );
        return;
      }
      void resetActions();
    };

    const saveAction = async () => {
      const current = getSelectedAction();
      const currentRefs = refs();
      if (busy || !current || !currentRefs.label || !currentRefs.prompt) {
        return;
      }
      clearErrors();
      const label = currentRefs.label.value.trim();
      const prompt = currentRefs.prompt.value.trim();
      const insertPosition = normalizePosition(currentRefs.position?.value);
      if (!label) {
        addError(currentRefs.label, getTexts().label_required || "Label is required.");
      }
      if (!prompt) {
        addError(currentRefs.prompt, getTexts().prompt_required || "Prompt is required.");
      }
      if (!label || !prompt) {
        return;
      }

      setBusy(true, getTexts().saving_action || "Saving…");
      try {
        const response = await request("aipkit_save_enhancer_action", {
          id: current.id,
          label,
          prompt,
          insert_position: insertPosition,
        });
        const saved = normalizeAction(response.action || {
          ...current,
          label,
          prompt,
          insert_position: insertPosition,
        });
        const currentActions = getActions();
        const previousIndex = currentActions.findIndex((action) => action.id === current.id);
        let nextActions;
        if (previousIndex >= 0) {
          nextActions = currentActions.slice();
          nextActions[previousIndex] = saved;
        } else {
          nextActions = [...currentActions, saved];
        }
        setActions(nextActions);
        draftAction = null;
        selectedId = saved.id;
        showStatus();
        populateEditor();
      } catch (error) {
        addError(null, error?.message || "Could not save the action.");
      } finally {
        setBusy(false);
      }
    };

    const refreshActions = async () => {
      setBusy(true, getTexts().loading_actions || "Loading actions…");
      try {
        const response = await request("aipkit_get_enhancer_actions");
        setActions(response.actions || []);
        draftAction = null;
        if (!getActions().some((action) => action.id === selectedId)) {
          selectedId = getActions()[0]?.id || "";
        }
        showStatus();
        populateEditor();
      } catch (error) {
        showStatus("error", error?.message || getTexts().loading_failed || "Failed to load actions.");
      } finally {
        setBusy(false);
      }
    };

    const ensureManager = () => {
      if (overlay && document.body.contains(overlay)) {
        return;
      }
      const texts = getTexts();
      overlay = document.createElement("div");
      overlay.className = "aipkit_editor_assistant_manager";
      overlay.setAttribute("aria-hidden", "true");
      overlay.innerHTML = `
      <div class="aipkit_editor_assistant_manager_shell" role="dialog" aria-modal="true" aria-labelledby="aipkit_editor_assistant_manager_title">
        <div class="aipkit_editor_assistant_manager_header">
          <div>
            <h2 class="aipkit_editor_assistant_manager_title" id="aipkit_editor_assistant_manager_title">${escapeHtml(texts.assistant_menu_title || "Assistant menu")}</h2>
            <p class="aipkit_editor_assistant_manager_copy">${escapeHtml(texts.assistant_menu_description || "Customize the actions shown in the editor menu.")}</p>
          </div>
          <button type="button" class="aipkit_editor_assistant_modal_close" aria-label="${escapeHtml(texts.close || "Close")}">
            <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
          </button>
        </div>
        <form class="aipkit_editor_assistant_form">
          <div class="aipkit_editor_assistant_manager_body">
            <div class="aipkit_editor_assistant_action_list" aria-label="${escapeHtml(texts.assistant_menu_items || "Actions")}"></div>
            <button type="button" class="aipkit_editor_assistant_ui_btn aipkit_editor_assistant_ui_btn--secondary aipkit_editor_assistant_add">
              <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
              ${escapeHtml(texts.add_action || "Add action")}
            </button>
            <section class="aipkit_editor_assistant_edit_area">
              <h3 class="aipkit_editor_assistant_editor_title"></h3>
              <div class="aipkit_editor_assistant_editor_grid">
                <label class="aipkit_editor_assistant_field_label">
                  <span>${escapeHtml(texts.action_label || "Label")}</span>
                  <input type="text" class="aipkit_editor_assistant_label" maxlength="50" autocomplete="off" />
                </label>
                <label class="aipkit_editor_assistant_field_label">
                  <span>${escapeHtml(texts.insert_position || "Position")}</span>
                  <select class="aipkit_editor_assistant_position">
                    <option value="replace">${escapeHtml(texts.replace_selection || "Replace selection")}</option>
                    <option value="after">${escapeHtml(texts.insert_after || "Insert after")}</option>
                    <option value="before">${escapeHtml(texts.insert_before || "Insert before")}</option>
                  </select>
                </label>
              </div>
              <label class="aipkit_editor_assistant_field_label">
                <span>${escapeHtml(texts.action_prompt || "Prompt")}</span>
                <textarea class="aipkit_editor_assistant_prompt" rows="5"></textarea>
              </label>
              <button type="button" class="aipkit_editor_assistant_token" data-insert-token>${escapeHtml(texts.selected_text_token || "{selected_text}")}</button>
              <div class="aipkit_editor_assistant_errors" aria-live="polite"></div>
            </section>
          </div>
          <div class="aipkit_editor_assistant_footer">
            <button type="button" class="aipkit_editor_assistant_ui_btn aipkit_editor_assistant_ui_btn--secondary aipkit_editor_assistant_reset">${escapeHtml(texts.reset_actions || "Restore default actions")}</button>
            <div class="aipkit_editor_assistant_manager_status" aria-live="polite"></div>
            <div class="aipkit_editor_assistant_footer_actions">
              <button type="button" class="aipkit_editor_assistant_ui_btn aipkit_editor_assistant_ui_btn--secondary aipkit_editor_assistant_cancel">${escapeHtml(texts.cancel || "Cancel")}</button>
              <button type="submit" class="aipkit_editor_assistant_ui_btn aipkit_editor_assistant_ui_btn--primary aipkit_editor_assistant_save">${escapeHtml(texts.save_action || "Save")}</button>
            </div>
          </div>
        </form>
      </div>
    `;

      overlay.addEventListener("click", (event) => {
        const row = event.target.closest(".aipkit_editor_assistant_action_row");
        if (event.target.closest(".aipkit_editor_assistant_modal_close, .aipkit_editor_assistant_cancel")) {
          closeManager();
        } else if (event.target.closest(".aipkit_editor_assistant_add")) {
          createDraft();
        } else if (event.target.closest(".aipkit_editor_assistant_reset")) {
          confirmReset();
        } else if (event.target.closest("[data-insert-token]")) {
          const prompt = refs().prompt;
          if (prompt) {
            const token = "{selected_text}";
            const start = prompt.selectionStart;
            prompt.setRangeText(token, start, prompt.selectionEnd, "end");
            prompt.focus();
          }
        } else if (event.target.closest("[data-delete-action]") && row) {
          void deleteAction(row.dataset.actionId);
        } else if (event.target.closest("[data-select-action]") && row) {
          selectAction(row.dataset.actionId);
        }
      });

      overlay.addEventListener("dragstart", (event) => {
        const row = event.target.closest(".aipkit_editor_assistant_action_row");
        if (!row || row.dataset.actionId.startsWith(DRAFT_PREFIX)) {
          event.preventDefault();
          return;
        }
        draggedId = row.dataset.actionId;
        row.classList.add("is-dragging");
        if (event.dataTransfer) {
          event.dataTransfer.effectAllowed = "move";
          event.dataTransfer.setData("text/plain", draggedId);
          event.dataTransfer.setDragImage(row, 17, 24);
        }
      });
      overlay.addEventListener("dragend", () => {
        overlay.querySelectorAll(".is-dragging, .is-drag-over")
          .forEach((row) => row.classList.remove("is-dragging", "is-drag-over"));
        draggedId = "";
      });
      overlay.addEventListener("dragover", (event) => {
        const row = event.target.closest(".aipkit_editor_assistant_action_row");
        if (!draggedId || !row || row.dataset.actionId === draggedId) {
          return;
        }
        event.preventDefault();
        overlay.querySelectorAll(".is-drag-over").forEach((item) => item.classList.remove("is-drag-over"));
        row.classList.add("is-drag-over");
      });
      overlay.addEventListener("drop", (event) => {
        const row = event.target.closest(".aipkit_editor_assistant_action_row");
        if (!draggedId || !row || row.dataset.actionId === draggedId) {
          return;
        }
        event.preventDefault();
        const previous = getActions();
        const next = previous.slice();
        const from = next.findIndex((action) => action.id === draggedId);
        const to = next.findIndex((action) => action.id === row.dataset.actionId);
        if (from < 0 || to < 0) {
          return;
        }
        const [moved] = next.splice(from, 1);
        next.splice(to, 0, moved);
        void persistOrder(next, previous);
      });
      overlay.querySelector(".aipkit_editor_assistant_form")?.addEventListener("submit", (event) => {
        event.preventDefault();
        void saveAction();
      });
      document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && overlay?.classList.contains("aipkit-active")) {
          closeManager();
        }
      });
      document.body.appendChild(overlay);
    };

    window.aipkit_openEnhancerActionsManager = (options = {}) => {
      const config = getConfig();
      if (!config.can_manage_actions || !config.nonce_manage_actions) {
        return;
      }
      updateCallback = typeof options.onUpdated === "function" ? options.onUpdated : null;
      lastFocusedElement = document.activeElement instanceof HTMLElement
        ? document.activeElement
        : null;
      ensureManager();
      draftAction = null;
      selectedId = getActions()[0]?.id || "";
      populateEditor();
      showStatus();
      overlay.setAttribute("aria-hidden", "false");
      overlay.classList.add("aipkit-active");
      refs().list?.querySelector("[data-select-action]")?.focus();
      void refreshActions();
    };
  })();
})();
