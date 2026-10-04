/**
 * AIPKit AI Forms - list state, rendering, requests and interactions.
 */
(function () {
  "use strict";

  const ROWS_PER_PAGE_OPTIONS = [10, 25, 50, 100];
  const DEFAULT_ROWS_PER_PAGE = 10;

  function normalizeRowsPerPage(value) {
    const parsed = parseInt(value, 10);
    return ROWS_PER_PAGE_OPTIONS.includes(parsed)
      ? parsed
      : DEFAULT_ROWS_PER_PAGE;
  }

  window.aipkitAIFormsListState = {
    currentPage: 1,
    perPage: DEFAULT_ROWS_PER_PAGE,
    searchQuery: "",
    sortColumn: "modified", // Default sort column
    sortDirection: "desc", // Default sort direction
    isLoading: false,
    selectedIds: new Set(),
  };
  window.aipkitAIFormsRowsPerPageOptions = ROWS_PER_PAGE_OPTIONS;
  window.aipkitAIFormsNormalizeRowsPerPage = normalizeRowsPerPage;

  const parseUtcDateTime = (value) => {
    if (!value) {
      return null;
    }
    const date = new Date(`${String(value).replace(" ", "T")}Z`);
    return Number.isNaN(date.getTime()) ? null : date;
  };

  const formatUpdated = (value) => {
    if (typeof window.aipkit_formatRelativeDateTime !== "function") {
      return "—";
    }
    const label = window.aipkit_formatRelativeDateTime(value, {
      numeric: "always",
    });
    if (!label || label === "Invalid Date" || label === "N/A") {
      return "—";
    }
    return label.charAt(0).toUpperCase() + label.slice(1);
  };

  const buildShortcodeConfigurator = (form, escaper, __) => {
    const isPro = window.aipkit_dashboard?.isProPlan ?? false;
    const formId = String(form.id || "");
    const themeSelectId = `aipkit_ai_form_theme_${form.id}`;
    const proReason = __(
      "PDF download is available on the Pro plan.",
      "gpt3-ai-content-generator"
    );

    const toggleRow = (label, attrName, { disabled = false } = {}) => `
      <div class="aipkit_ai_form_shortcode_option${disabled ? " is-disabled" : ""}"${disabled ? ` title="${escaper(proReason)}"` : ""}>
        <span class="aipkit_ai_form_shortcode_option_label">${label}</span>
        <label class="aipkit_switch">
          <input type="checkbox" class="aipkit_toggle_switch aipkit-aiform-copy-option" data-form-id="${escaper(formId)}" data-attr-name="${attrName}" value="true" aria-label="${escaper(label)}"${disabled ? " disabled" : ""}>
          <span class="aipkit_switch_slider"></span>
        </label>
      </div>`;

    return `
      <div class="aipkit_shortcode_configurator aipkit_ai_form_shortcode_popover" id="aif-configurator-${escaper(formId)}" style="display: none;" role="dialog" aria-modal="false" aria-hidden="true" aria-labelledby="aif-shortcode-title-${escaper(formId)}" aria-describedby="aif-shortcode-description-${escaper(formId)}" data-form-id="${escaper(formId)}">
        <div class="aipkit_ai_form_shortcode_popover_header">
          <h3 class="aipkit_ai_form_shortcode_popover_title" id="aif-shortcode-title-${escaper(formId)}">${__("Copy with options", "gpt3-ai-content-generator")}</h3>
          <p class="aipkit_ai_form_shortcode_popover_description" id="aif-shortcode-description-${escaper(formId)}">${__("Only this copy changes. Saved defaults stay unchanged.", "gpt3-ai-content-generator")}</p>
        </div>
        <div class="aipkit_ai_form_shortcode_options">
          ${toggleRow(__("Show provider", "gpt3-ai-content-generator"), "show_provider")}
          ${toggleRow(__("Show model", "gpt3-ai-content-generator"), "show_model")}
          ${toggleRow(__("Copy button", "gpt3-ai-content-generator"), "copy_button")}
          ${toggleRow(__("Save button", "gpt3-ai-content-generator"), "save_button")}
          ${toggleRow(__("PDF download", "gpt3-ai-content-generator"), "pdf_download", { disabled: !isPro })}
          <div class="aipkit_ai_form_shortcode_option aipkit_ai_form_shortcode_theme_row">
            <label class="aipkit_ai_form_shortcode_option_label" for="${themeSelectId}">${__("Theme", "gpt3-ai-content-generator")}</label>
            <select id="${themeSelectId}" class="aipkit_ai_form_shortcode_theme_select aipkit-aiform-copy-option" data-form-id="${escaper(formId)}" data-attr-name="theme">
              <option value="light">${__("Light", "gpt3-ai-content-generator")}</option>
              <option value="dark">${__("Dark", "gpt3-ai-content-generator")}</option>
              <option value="custom">${__("Custom", "gpt3-ai-content-generator")}</option>
            </select>
          </div>
        </div>
        <output class="aipkit_ai_form_shortcode_preview" data-shortcode="" aria-live="polite"></output>
        <div class="aipkit_ai_form_shortcode_popover_actions">
          <button type="button" class="aipkit_ai_form_shortcode_variant_copy" data-shortcode="">
            <span class="aipkit_ai_form_shortcode_copy_default">${__("Copy shortcode", "gpt3-ai-content-generator")}</span>
            <span class="aipkit_ai_form_shortcode_copy_success">${__("Copied", "gpt3-ai-content-generator")}</span>
          </button>
          <button type="button" class="aipkit_ai_form_shortcode_reset" data-form-id="${escaper(formId)}">${__("Reset", "gpt3-ai-content-generator")}</button>
        </div>
      </div>`;
  };

  function aipkitForms_renderTable(forms) {
    const tableBody = document.getElementById("aipkit_ai_forms_list_tbody");
    const tableWrapper = document.querySelector(".aipkit_ai_forms_list_table");
    const formsContainer = document.getElementById("aipkit_ai_forms_container");
    const escaper = window.aipkit_escapeHtml || ((value) => value);
    const __ = getI18n();

    if (!tableBody || !tableWrapper) {
      return;
    }

    if (typeof window.aipkitForms_closeShortcodeConfigurator === "function") {
      window.aipkitForms_closeShortcodeConfigurator();
    }
    tableBody.innerHTML = "";
    tableWrapper.classList.toggle("is-empty", !forms || forms.length === 0);
    if (!forms || forms.length === 0) {
      if (typeof window.aipkitForms_syncSelectionUi === "function") {
        window.aipkitForms_syncSelectionUi();
      }
      return;
    }

    forms.forEach((form) => {
      const row = tableBody.insertRow();
      const formId = String(form.id || "");
      const numericFormId = Number(formId) || 0;
      const formTitle = form.title || __("Untitled", "gpt3-ai-content-generator");
      const escapedTitle = escaper(formTitle);
      const shortcode = form.shortcode || `[aipkit_ai_form id=${formId}]`;
      const escapedShortcode = escaper(shortcode);
      const updatedRaw = form.updated_at || form.updatedAt || form.updated || "";
      const updatedDate = parseUtcDateTime(updatedRaw);
      const updatedTitle = updatedDate ? updatedDate.toLocaleString() : "";
      const responseCountRaw =
        form.submissions_count ?? form.submission_count ?? form.submissionsCount ?? 0;
      const responseCount = Number.isFinite(Number(responseCountRaw))
        ? Number(responseCountRaw)
        : 0;
      const isSelected = Boolean(
        numericFormId &&
          window.aipkitAIFormsListState?.selectedIds?.has(numericFormId)
      );

      row.dataset.formId = formId;
      row.classList.toggle("aipkit_ai_forms_row--selected", isSelected);

      const selectCell = row.insertCell();
      selectCell.className = "aipkit_ai_forms_select_cell";
      selectCell.innerHTML = `<input type="checkbox" class="aipkit_ai_forms_row_checkbox" value="${formId}" aria-label="${__("Select", "gpt3-ai-content-generator")}: ${escapedTitle}" ${isSelected ? "checked" : ""}>`;

      const nameCell = row.insertCell();
      nameCell.className = "aipkit_ai_form_title_cell";
      nameCell.innerHTML = `
        <span class="aipkit_ai_form_name">
          <span class="aipkit_ai_form_title_text" title="${escapedTitle}">${escapedTitle}</span>
        </span>`;

      const shortcodeCell = row.insertCell();
      shortcodeCell.className = "aipkit_shortcode_cell aipkit_ai_form_shortcode_cell";
      shortcodeCell.innerHTML = `
        <div class="aipkit_ai_form_shortcode_group">
          <span class="aipkit_ai_form_shortcode_chip" title="${escapedShortcode}" data-aipkit-shortcode-display="${formId}" data-shortcode="${escapedShortcode}">
            <code class="aipkit_ai_form_shortcode_text">${escapedShortcode}</code>
          </span>
          <button type="button" class="aipkit_ai_form_shortcode_copy" data-aipkit-shortcode-display="${formId}" data-shortcode="${escapedShortcode}" title="${__("Copy shortcode", "gpt3-ai-content-generator")}" aria-label="${__("Copy shortcode", "gpt3-ai-content-generator")}">
            <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
          </button>
        </div>
        ${buildShortcodeConfigurator(form, escaper, __)}`;
      if (
        formsContainer &&
        typeof window.aipkitForms_applyStoredShortcodeConfig === "function"
      ) {
        window.aipkitForms_applyStoredShortcodeConfig(form.id, formsContainer);
      }

      const responsesCell = row.insertCell();
      responsesCell.className = "aipkit_ai_form_responses_cell";
      responsesCell.textContent = responseCount.toLocaleString();

      const updatedCell = row.insertCell();
      updatedCell.className = "aipkit_ai_form_updated_cell";
      updatedCell.title = updatedTitle;
      updatedCell.textContent = formatUpdated(updatedRaw);

      const actionsCell = row.insertCell();
      actionsCell.className = "aipkit_form_actions_cell";
      actionsCell.innerHTML = `
        <div class="aipkit_ai_form_row_actions">
          <button type="button" class="aipkit_ai_form_row_action aipkit_edit_ai_form_btn" data-form-id="${formId}" title="${__("Edit", "gpt3-ai-content-generator")}" aria-label="${__("Edit", "gpt3-ai-content-generator")}: ${escapedTitle}">
            <span class="dashicons dashicons-edit" aria-hidden="true"></span>
          </button>
          <button type="button" class="aipkit_ai_form_row_action aipkit_preview_ai_form_btn" data-form-id="${formId}" data-form-title="${escapedTitle}" title="${__("Preview", "gpt3-ai-content-generator")}" aria-label="${__("Preview", "gpt3-ai-content-generator")}: ${escapedTitle}">
            <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
          </button>
          <button type="button" class="aipkit_ai_form_row_action aipkit_ai_form_shortcode_options_action aipkit_aiform_settings_toggle" data-form-id="${formId}" title="${__("Copy with options", "gpt3-ai-content-generator")}" aria-label="${__("Copy with options", "gpt3-ai-content-generator")}: ${escapedTitle}" aria-haspopup="dialog" aria-expanded="false" aria-controls="aif-configurator-${formId}">
            <span class="dashicons dashicons-shortcode" aria-hidden="true"></span>
          </button>
          <button type="button" class="aipkit_ai_form_row_action aipkit_ai_form_row_action--delete aipkit_delete_ai_form_btn" data-form-id="${formId}" data-form-title="${escapedTitle}" title="${__("Delete", "gpt3-ai-content-generator")}" aria-label="${__("Delete", "gpt3-ai-content-generator")}: ${escapedTitle}">
            <span class="dashicons dashicons-trash" aria-hidden="true"></span>
          </button>
        </div>`;
    });

    if (typeof window.aipkitForms_syncSelectionUi === "function") {
      window.aipkitForms_syncSelectionUi();
    }
  }

  window.aipkitForms_renderTable = aipkitForms_renderTable;

  function aipkitForms_renderPagination(paginationData) {
    const listState = window.aipkitAIFormsListState;
    const paginationContainer = document.getElementById(
      "aipkit_ai_forms_pagination"
    );
    if (!paginationContainer || !paginationData || !listState) return;
    const __ = wp.i18n && wp.i18n.__ ? wp.i18n.__ : (text) => text;
    const _n = wp.i18n && wp.i18n._n ? wp.i18n._n : (singular, plural, count) => (
      count === 1 ? singular : plural
    );

    paginationContainer.innerHTML = ""; // Clear previous controls

    const { total_forms, total_pages } = paginationData;
    const currentPage = listState.currentPage;

    if (total_forms <= 0) {
      return; // Do not show pagination if there are no results
    }

    const countSpan = document.createElement("span");
    countSpan.className = "aipkit_pagination-count";
    countSpan.textContent = `${total_forms.toLocaleString()} ${_n(
      "form",
      "forms",
      total_forms,
      "gpt3-ai-content-generator"
    )}`;
    paginationContainer.appendChild(countSpan);

    const controlsSpan = document.createElement("span");
    controlsSpan.className = "aipkit_ai_forms_pagination_controls";

    const normalizeRowsPerPage =
      window.aipkitAIFormsNormalizeRowsPerPage ||
      ((value) => parseInt(value, 10) || 10);
    const rowsPerPageOptions = window.aipkitAIFormsRowsPerPageOptions || [
      10,
      25,
      50,
      100,
    ];
    const perPageLabel = document.createElement("label");
    perPageLabel.className = "aipkit_ai_forms_per_page";
    perPageLabel.appendChild(
      document.createTextNode(__("Rows per page", "gpt3-ai-content-generator"))
    );

    const perPageSelect = document.createElement("select");
    perPageSelect.className = "aipkit_ai_forms_per_page_select";
    rowsPerPageOptions.forEach((value) => {
      const option = document.createElement("option");
      option.value = String(value);
      option.textContent = String(value);
      perPageSelect.appendChild(option);
    });
    perPageSelect.value = String(normalizeRowsPerPage(listState.perPage));
    perPageSelect.addEventListener("change", () => {
      listState.perPage = normalizeRowsPerPage(perPageSelect.value);
      listState.currentPage = 1;
      window.aipkitForms_fetchForms();
    });

    perPageLabel.appendChild(perPageSelect);
    controlsSpan.appendChild(perPageLabel);

    const linksSpan = document.createElement("span");
    linksSpan.className = "aipkit_pagination-links";

    const prevButton = document.createElement("button");
    prevButton.type = "button";
    prevButton.className =
      "aipkit_btn aipkit_btn-secondary aipkit_btn-small aipkit_pagination_prev";
    prevButton.textContent = __("Prev", "gpt3-ai-content-generator");
    prevButton.disabled = currentPage <= 1;
    linksSpan.appendChild(prevButton);

    const currentSpan = document.createElement("span");
    currentSpan.className = "aipkit_pagination-current";
    currentSpan.textContent = `${currentPage}/${Math.max(1, total_pages)}`;
    linksSpan.appendChild(currentSpan);

    const nextButton = document.createElement("button");
    nextButton.type = "button";
    nextButton.className =
      "aipkit_btn aipkit_btn-secondary aipkit_btn-small aipkit_pagination_next";
    nextButton.textContent = __("Next", "gpt3-ai-content-generator");
    nextButton.disabled = currentPage >= total_pages;
    linksSpan.appendChild(nextButton);

    controlsSpan.appendChild(linksSpan);

    paginationContainer.appendChild(controlsSpan);
  }

  window.aipkitForms_renderPagination = aipkitForms_renderPagination;

  const appendStateRow = (tableBody, content, isError = false) => {
    const row = document.createElement("tr");
    row.className = "aipkit_ai_forms_table_state_row";
    const cell = document.createElement("td");
    cell.colSpan = 6;
    cell.className = "aipkit_ai_forms_table_state_cell" +
      (isError ? " aipkit_ai_forms_table_state_cell--error" : "");
    cell.append(...content);
    row.appendChild(cell);
    tableBody.appendChild(row);
  };

  function aipkitForms_fetchForms() {
    const listState = window.aipkitAIFormsListState;
    if (!listState || listState.isLoading) {
      return;
    }
    listState.isLoading = true;
    listState.selectedIds?.clear();
    if (typeof window.aipkitForms_syncSelectionUi === "function") {
      window.aipkitForms_syncSelectionUi();
    }
    const __ =
      window.wp && window.wp.i18n && window.wp.i18n.__
        ? window.wp.i18n.__
        : (text) => text;
    const loadingLabel = __("Loading...", "gpt3-ai-content-generator");

    const listTableBody = document.getElementById("aipkit_ai_forms_list_tbody");
    const noFormsMessage = document.getElementById(
      "aipkit_no_ai_forms_message"
    );
    const tableWrapper = document.querySelector(".aipkit_ai_forms_list_table");
    const paginationContainer = document.getElementById(
      "aipkit_ai_forms_pagination"
    );

    // Show loading state
    if (listTableBody) {
      tableWrapper?.classList.remove("is-empty");
      listTableBody.innerHTML = "";
      const spinner = document.createElement("span");
      spinner.className = "aipkit_spinner aipkit_ai_forms_table_spinner";
      spinner.setAttribute("aria-hidden", "true");
      const loadingText = document.createElement("span");
      loadingText.textContent = loadingLabel;
      appendStateRow(listTableBody, [spinner, loadingText]);
    }
    if (noFormsMessage) noFormsMessage.hidden = true;
    if (paginationContainer) paginationContainer.innerHTML = "";

    const config = window.aipkit_ai_forms_config || {};
    const data = {
      _ajax_nonce: config.nonce_manage_forms,
      page: listState.currentPage,
      per_page: listState.perPage,
      search: listState.searchQuery,
      sort_by: listState.sortColumn,
      sort_order: listState.sortDirection,
    };

    window
      .aipkit_apiRequest("aipkit_list_ai_forms", data)
      .then((response) => {
        if (typeof window.aipkitForms_renderTable === "function") {
          window.aipkitForms_renderTable(response.forms || []);
        }
        if (typeof window.aipkitForms_renderPagination === "function") {
          window.aipkitForms_renderPagination(response.pagination || {});
        }
        if (
          noFormsMessage &&
          (!response.forms || response.forms.length === 0)
        ) {
          noFormsMessage.hidden = false;
          const hasFilters =
            Boolean(listState.searchQuery) &&
            listState.searchQuery.trim().length > 0;
          const emptyTitle = noFormsMessage.querySelector(
            ".aipkit_ai_forms_empty_title"
          );
          const emptyDescription = noFormsMessage.querySelector(
            ".aipkit_ai_forms_empty_description"
          );
          const emptyIcon = noFormsMessage.querySelector(
            ".aipkit_ai_forms_empty_icon .dashicons"
          );
          const emptyCreateButton = noFormsMessage.querySelector(
            ".aipkit_ai_forms_empty_create"
          );
          noFormsMessage.classList.toggle("is-filtered", hasFilters);
          if (emptyTitle) {
            emptyTitle.textContent = hasFilters
              ? __("No matching forms", "gpt3-ai-content-generator")
              : __("Build your first form", "gpt3-ai-content-generator");
          }
          if (emptyDescription) {
            emptyDescription.textContent = hasFilters
              ? __("Try a different search term.", "gpt3-ai-content-generator")
              : __("Turn visitor answers into AI-generated results.", "gpt3-ai-content-generator");
          }
          if (emptyIcon) {
            emptyIcon.classList.toggle("dashicons-search", hasFilters);
            emptyIcon.classList.toggle("dashicons-feedback", !hasFilters);
          }
          if (emptyCreateButton) {
            emptyCreateButton.hidden = hasFilters;
          }
        }
      })
      .catch((error) => {
        if (listTableBody) {
          listTableBody.innerHTML = "";
          appendStateRow(listTableBody, [`${__(
            "Error loading forms:",
            "gpt3-ai-content-generator"
          )} ${error.message}`], true);
        }
      })
      .finally(() => {
        listState.isLoading = false;
      });
  }

  window.aipkitForms_fetchForms = aipkitForms_fetchForms;

  let searchTimeout;
  let copyResetTimeout;

  const getI18n = () =>
    typeof wp !== "undefined" && wp.i18n && wp.i18n.__
      ? wp.i18n.__
      : (text) => text;

  const displayMessage = (formsContainer, type, message) => {
    if (typeof window.aipkitForms_displayMessage === "function") {
      window.aipkitForms_displayMessage(formsContainer, type, message);
    } else if (type === "error") {
      window.alert(message);
    }
  };

  const requestConfirmation = (message) => {
    const __ = getI18n();

    return new Promise((resolve) => {
      if (typeof window.aipkit_showConfirmModal === "function") {
        window.aipkit_showConfirmModal(message, {
          title: __("Delete form", "gpt3-ai-content-generator"),
          confirmText: __("Delete", "gpt3-ai-content-generator"),
          cancelText: __("Cancel", "gpt3-ai-content-generator"),
          variant: "danger",
          onConfirm: () => resolve(true),
          onCancel: () => resolve(false),
        });
        return;
      }

      resolve(window.confirm(message));
    });
  };

  const copyText = async (value) => {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(value);
      return;
    }

    const textarea = document.createElement("textarea");
    textarea.value = value;
    textarea.setAttribute("readonly", "");
    textarea.style.position = "fixed";
    textarea.style.opacity = "0";
    document.body.appendChild(textarea);
    textarea.select();
    const copied = document.execCommand("copy");
    document.body.removeChild(textarea);
    if (!copied) {
      throw new Error("Unable to copy shortcode.");
    }
  };

  const showCopyFeedback = (button) => {
    const icon = button.querySelector(".dashicons");
    if (copyResetTimeout) {
      window.clearTimeout(copyResetTimeout);
    }
    button.classList.add("is-copied");
    if (icon) {
      icon.classList.remove("dashicons-admin-page");
      icon.classList.add("dashicons-yes-alt");
    }
    copyResetTimeout = window.setTimeout(() => {
      button.classList.remove("is-copied");
      if (icon) {
        icon.classList.remove("dashicons-yes-alt");
        icon.classList.add("dashicons-admin-page");
      }
    }, 1500);
  };

  function aipkitForms_attachListEventListeners() {
    const listContainer = document.getElementById(
      "aipkit_ai_forms_list_container"
    );
    if (!listContainer || listContainer.dataset.listListenersAttached) {
      return;
    }
    listContainer.dataset.listListenersAttached = "true";

    const listState = window.aipkitAIFormsListState;
    if (!listState) {
      console.error("AI Forms Events: List state object is not available.");
      return;
    }

    const formsContainer =
      document.getElementById("aipkit_ai_forms_container") || listContainer;
    const config = window.aipkit_ai_forms_config || { text: {} };
    const __ = getI18n();
    const filters = document.getElementById("aipkit_ai_forms_filters");
    const selection = document.getElementById("aipkit_ai_forms_selection");
    const selectionCount = document.getElementById(
      "aipkit_ai_forms_selection_count"
    );
    const selectAllCheckbox = document.getElementById(
      "aipkit_ai_forms_select_all"
    );

    const getVisibleRowCheckboxes = () =>
      Array.from(
        listContainer.querySelectorAll(".aipkit_ai_forms_row_checkbox")
      );

    const setRowSelected = (formId, checked) => {
      if (checked) {
        listState.selectedIds.add(formId);
      } else {
        listState.selectedIds.delete(formId);
      }
    };

    const syncSelectionUi = () => {
      const selectedIds = listState.selectedIds;
      if (!selectedIds) {
        return;
      }

      const selectedTotal = selectedIds.size;
      const hasSelection = selectedTotal > 0;
      if (filters) {
        filters.hidden = hasSelection;
      }
      if (selection) {
        selection.hidden = !hasSelection;
      }
      if (selectionCount) {
        selectionCount.textContent = `${selectedTotal.toLocaleString()} ${__(
          "selected",
          "gpt3-ai-content-generator"
        )}`;
      }

      const visibleCheckboxes = getVisibleRowCheckboxes();
      let visibleSelectedTotal = 0;
      visibleCheckboxes.forEach((checkbox) => {
        const formId = Number(checkbox.value);
        const isSelected = formId > 0 && selectedIds.has(formId);
        checkbox.checked = isSelected;
        checkbox
          .closest("tr")
          ?.classList.toggle("aipkit_ai_forms_row--selected", isSelected);
        if (isSelected) {
          visibleSelectedTotal += 1;
        }
      });

      if (selectAllCheckbox) {
        selectAllCheckbox.checked =
          visibleCheckboxes.length > 0 &&
          visibleSelectedTotal === visibleCheckboxes.length;
        selectAllCheckbox.indeterminate =
          visibleSelectedTotal > 0 &&
          visibleSelectedTotal < visibleCheckboxes.length;
        selectAllCheckbox.disabled = visibleCheckboxes.length === 0;
      }
    };

    window.aipkitForms_syncSelectionUi = syncSelectionUi;

    const openNewForm = (draft = null) => {
      const openEditor = () => {
        if (typeof window.aipkitForms_showFormEditor === "function") {
          window.aipkitForms_showFormEditor(null, formsContainer, draft);
        }
      };

      if (typeof window.aipkitForms_runWithLeaveGuard === "function") {
        window.aipkitForms_runWithLeaveGuard(formsContainer, openEditor, {
          keepDraftOnDiscard: false,
        });
      } else {
        openEditor();
      }
    };

    const switchWorkspacePanel = (trigger) => {
      const nextTab = trigger.dataset.aipkitAiFormsTab || "forms";
      listContainer
        .querySelectorAll(".aipkit_ai_forms_workspace_tab")
        .forEach((tab) => {
          const isActive =
            (tab.dataset.aipkitAiFormsTab || "forms") === nextTab;
          tab.classList.toggle("is-active", isActive);
          tab.setAttribute("aria-selected", isActive ? "true" : "false");
        });
      listContainer
        .querySelectorAll(".aipkit_ai_forms_workspace_panel")
        .forEach((panel) => {
          const isActive =
            panel.id ===
            `aipkit_ai_forms_${nextTab === "forms" ? "data" : nextTab}_panel`;
          panel.classList.toggle("is-active", isActive);
          panel.hidden = !isActive;
        });
      listContainer.classList.toggle(
        "aipkit_ai_forms_list_container--settings",
        nextTab === "settings"
      );

      if (
        nextTab === "settings" &&
        typeof window.aipkitForms_initializeTokenUI === "function"
      ) {
        window.aipkitForms_initializeTokenUI(listContainer);
      }
    };

    const deleteForm = async (button) => {
      if (!window.aipkit_apiRequest || button.disabled) {
        return;
      }

      const formId = button.dataset.formId || "";
      const formTitle = button.dataset.formTitle || __("this form", "gpt3-ai-content-generator");
      const confirmed = await requestConfirmation(
        `${__("Delete", "gpt3-ai-content-generator")} “${formTitle}”? ${__(
          "This cannot be undone.",
          "gpt3-ai-content-generator"
        )}`
      );
      if (!confirmed) {
        return;
      }

      button.disabled = true;
      try {
        await window.aipkit_apiRequest("aipkit_delete_ai_form", {
          _ajax_nonce: config.nonce_manage_forms,
          form_id: formId,
        });
        window.aipkitForms_fetchForms();
      } catch (error) {
        displayMessage(
          formsContainer,
          "error",
          error.message || __("Error deleting form.", "gpt3-ai-content-generator")
        );
        button.disabled = false;
      }
    };

    listContainer.addEventListener("click", async (event) => {
      const target = event.target;

      const workspaceTab = target.closest(".aipkit_ai_forms_workspace_tab");
      if (workspaceTab) {
        event.preventDefault();
        switchWorkspacePanel(workspaceTab);
        return;
      }

      const templateAction = target.closest(".aipkit_ai_forms_template_action");
      if (templateAction) {
        event.preventDefault();
        const templateKey = templateAction.dataset.aipkitTemplateKey || "";
        const draft =
          typeof window.aipkitForms_getTemplateDraft === "function"
            ? window.aipkitForms_getTemplateDraft(templateKey)
            : null;
        if (draft) {
          openNewForm(draft);
        }
        return;
      }

      const emptyCreateButton = target.closest(".aipkit_ai_forms_empty_create");
      if (emptyCreateButton) {
        event.preventDefault();
        openNewForm();
        return;
      }

      const copyButton = target.closest(
        ".aipkit_ai_form_shortcode_copy, .aipkit_ai_form_shortcode_variant_copy"
      );
      if (copyButton) {
        event.preventDefault();
        event.stopPropagation();
        try {
          await copyText(copyButton.dataset.shortcode || "");
          showCopyFeedback(copyButton);
        } catch (error) {
          displayMessage(
            formsContainer,
            "error",
            error.message || __("Unable to copy shortcode.", "gpt3-ai-content-generator")
          );
        }
        return;
      }

      const deleteButton = target.closest(".aipkit_delete_ai_form_btn");
      if (deleteButton) {
        event.preventDefault();
        event.stopPropagation();
        deleteForm(deleteButton);
        return;
      }

      const exportSelectedButton = target.closest(
        "#aipkit_export_selected_ai_forms_btn"
      );
      if (exportSelectedButton) {
        event.preventDefault();
        const selectedIds = Array.from(listState.selectedIds || []);
        if (
          selectedIds.length > 0 &&
          typeof window.aipkitForms_exportSelectedForms === "function"
        ) {
          window.aipkitForms_exportSelectedForms(
            exportSelectedButton,
            selectedIds,
            formsContainer
          );
        }
        return;
      }

      const deleteSelectedButton = target.closest(
        "#aipkit_delete_selected_ai_forms_btn"
      );
      if (deleteSelectedButton) {
        event.preventDefault();
        if (typeof window.aipkitForms_deleteSelectedForms === "function") {
          window.aipkitForms_deleteSelectedForms(
            deleteSelectedButton,
            formsContainer
          );
        }
        return;
      }

      const clearSelectionButton = target.closest(
        "#aipkit_clear_ai_forms_selection_btn"
      );
      if (clearSelectionButton) {
        event.preventDefault();
        listState.selectedIds?.clear();
        syncSelectionUi();
        return;
      }

      const sortableHeader = target.closest(".aipkit-sortable-col");
      if (sortableHeader) {
        const newSortColumn = sortableHeader.dataset.sortKey;
        if (listState.sortColumn === newSortColumn) {
          listState.sortDirection =
            listState.sortDirection === "asc" ? "desc" : "asc";
        } else {
          listState.sortColumn = newSortColumn;
          listState.sortDirection = "asc";
        }
        listState.currentPage = 1;
        listContainer
          .querySelectorAll(".aipkit-sortable-col")
          .forEach((header) => header.removeAttribute("data-sort-direction"));
        sortableHeader.setAttribute(
          "data-sort-direction",
          listState.sortDirection
        );
        window.aipkitForms_fetchForms();
        return;
      }

      if (target.matches(".aipkit_pagination_prev")) {
        if (listState.currentPage > 1) {
          listState.currentPage -= 1;
          window.aipkitForms_fetchForms();
        }
        return;
      }
      if (target.matches(".aipkit_pagination_next")) {
        listState.currentPage += 1;
        window.aipkitForms_fetchForms();
      }
    });

    listContainer.addEventListener("change", (event) => {
      const rowCheckbox = event.target.closest(
        ".aipkit_ai_forms_row_checkbox"
      );
      if (rowCheckbox) {
        const formId = Number(rowCheckbox.value);
        if (formId <= 0) {
          return;
        }
        setRowSelected(formId, rowCheckbox.checked);
        syncSelectionUi();
        return;
      }

      if (event.target === selectAllCheckbox) {
        getVisibleRowCheckboxes().forEach((checkbox) => {
          const formId = Number(checkbox.value);
          if (formId <= 0) {
            return;
          }
          setRowSelected(formId, selectAllCheckbox.checked);
        });
        syncSelectionUi();
      }
    });

    const searchInput = listContainer.querySelector(
      "#aipkit_ai_forms_search_input"
    );
    if (searchInput) {
      searchInput.addEventListener("input", function () {
        window.clearTimeout(searchTimeout);
        searchTimeout = window.setTimeout(() => {
          listState.searchQuery = this.value.trim();
          listState.currentPage = 1;
          window.aipkitForms_fetchForms();
        }, 400);
      });
    }

    syncSelectionUi();
  }

  window.aipkitForms_attachListEventListeners =
    aipkitForms_attachListEventListeners;

  function aipkitForms_initListView() {
    if (
      typeof window.aipkitForms_attachListEventListeners !== "function" ||
      typeof window.aipkitForms_fetchForms !== "function"
    ) {
      return;
    }

    window.aipkitForms_attachListEventListeners();
    window.aipkitForms_fetchForms(); // Initial data load
  }

  window.aipkitForms_initListView = aipkitForms_initListView;
})();
