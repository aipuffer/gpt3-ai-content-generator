/**
 * AIPKit Content Writer - Update Existing Content panel
 * Fetches posts and updates selected items using current Content Writer settings.
 */
(function () {
  "use strict";

  const i18n = window.wp?.i18n || {};
  const __ = typeof i18n.__ === "function" ? i18n.__ : (text) => text;
  const _n =
    typeof i18n._n === "function"
      ? i18n._n
      : (single, plural, count) => (count === 1 ? single : plural);
  const sprintf =
    typeof i18n.sprintf === "function"
      ? i18n.sprintf
      : (format, ...values) =>
          values.reduce(
            (result, value, index) =>
              result.replace(new RegExp(`%${index + 1}\\$[ds]|%[ds]`), String(value)),
            format
          );

  const escaper =
    window.aipkit_escapeHtml ||
    function (str) {
      return String(str || "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
    };

  const DEFAULT_FIELD_ORDER = [
    "keyword",
    "title",
    "excerpt",
    "content",
    "meta",
    "tags",
  ];

  const DEFAULT_FIELD_LABELS = {
    keyword: __("Focus keyword", "gpt3-ai-content-generator"),
    title: __("Title", "gpt3-ai-content-generator"),
    excerpt: __("Excerpt", "gpt3-ai-content-generator"),
    content: __("Content", "gpt3-ai-content-generator"),
    meta: __("Meta description", "gpt3-ai-content-generator"),
    tags: __("Tags", "gpt3-ai-content-generator"),
  };

  const IMAGE_FIELD_LABELS = {
    keyword: __("Alt text", "gpt3-ai-content-generator"),
    title: __("Image title", "gpt3-ai-content-generator"),
    excerpt: __("Caption", "gpt3-ai-content-generator"),
    content: __("Description", "gpt3-ai-content-generator"),
  };

  const PROGRESS_FIELD_LABELS = {
    meta: __("Meta", "gpt3-ai-content-generator"),
  };

  const PROGRESS_STATUS_LABELS = {
    waiting: __("Queued", "gpt3-ai-content-generator"),
    running: __("Running", "gpt3-ai-content-generator"),
    success: __("Done", "gpt3-ai-content-generator"),
    error: __("Failed", "gpt3-ai-content-generator"),
    stopped: __("Stopped", "gpt3-ai-content-generator"),
  };

  const PER_PAGE_OPTIONS = [10, 25, 50, 100, 1000];
  const PER_PAGE_STORAGE_KEY = "aipkit_cw_existing_per_page";

  const MODE_CONFIGS = {
    "existing-content": {
      fieldOrder: DEFAULT_FIELD_ORDER,
      fieldLabels: DEFAULT_FIELD_LABELS,
      postTypeOverride: "",
    },
    "existing-images": {
      fieldOrder: ["keyword", "title", "excerpt", "content"],
      fieldLabels: IMAGE_FIELD_LABELS,
      postTypeOverride: "attachment",
      useImageMetadataPrompts: true,
    },
    "existing-products": {
      fieldOrder: DEFAULT_FIELD_ORDER,
      fieldLabels: DEFAULT_FIELD_LABELS,
      postTypeOverride: "product",
    },
  };

  const lengthMap = {
    short: 2000,
    medium: 4000,
    long: 6000,
  };

  const isPro =
    window.aipkit_dashboard && window.aipkit_dashboard.isProPlan;
  const upgradeUrl =
    window.aipkit_dashboard && window.aipkit_dashboard.upgradeUrl
      ? window.aipkit_dashboard.upgradeUrl
      : "";

  function requestFetch(page = 1) {
    if (typeof window.aipkit_cw_existing_handleFetch === "function") {
      window.aipkit_cw_existing_handleFetch(page);
      return;
    }
    window.aipkit_cw_existing_pendingFetch = page;
  }

  function aipkit_initContentWriterExistingContent(scopeElement) {
    if (!scopeElement) {
      return;
    }

    const panel = scopeElement.querySelector("[data-aipkit-existing-panel]");
    if (!panel || panel.dataset.init === "true") {
      return;
    }

    const postTypeSelect = panel.querySelector("#aipkit_cw_existing_post_type");
    const mediaFilterSelect = panel.querySelector(
      "#aipkit_cw_existing_media_filter"
    );
    const searchInput = panel.querySelector("#aipkit_cw_existing_search");
    const controls = panel.querySelector(".aipkit_cw_existing_controls");
    const runProgress = panel.querySelector(
      "[data-aipkit-existing-run-progress]"
    );
    const runProgressLabel = panel.querySelector(
      "#aipkit_cw_existing_run_progress_label"
    );
    const runProgressElapsed = panel.querySelector(
      "#aipkit_cw_existing_run_elapsed"
    );
    const runProgressTrack = panel.querySelector(
      "#aipkit_cw_existing_run_progress_track"
    );
    const runProgressBar = panel.querySelector(
      "#aipkit_cw_existing_run_progress_bar"
    );
    const perPageSelect = panel.querySelector("#aipkit_cw_existing_per_page");
    const selectAllCheckbox = panel.querySelector(
      "#aipkit_cw_existing_select_all"
    );
    const titleHeader = panel.querySelector(
      ".aipkit_cw_existing_col_title"
    );
    const tableBody = panel.querySelector("#aipkit_cw_existing_posts_body");
    const pageStatus = panel.querySelector("#aipkit_cw_existing_page_status");
    const prevButton = panel.querySelector("#aipkit_cw_existing_page_prev");
    const nextButton = panel.querySelector("#aipkit_cw_existing_page_next");
    const selectedCount = panel.querySelector(
      "#aipkit_cw_existing_selected_count"
    );
    const summarySeparator = panel.querySelector(
      ".aipkit_cw_existing_summary_separator"
    );
    const totalCount = panel.querySelector("#aipkit_cw_existing_total_count");
    const actionButton = document.getElementById(
      "aipkit_content_writer_generate_btn"
    );
    const actionShell = actionButton
      ? actionButton.closest(".aipkit_cw_action_shell")
      : null;
    const modeSelect = document.getElementById("aipkit_cw_mode_select");

    if (!tableBody) {
      return;
    }

    const nonceField = document.getElementById("aipkit_content_writer_nonce");
    const normalizePerPage = (value) => {
      const parsed = parseInt(value, 10);
      return PER_PAGE_OPTIONS.includes(parsed) ? parsed : PER_PAGE_OPTIONS[0];
    };
    const readStoredPerPage = () => {
      try {
        return normalizePerPage(window.localStorage.getItem(PER_PAGE_STORAGE_KEY));
      } catch (error) {
        return PER_PAGE_OPTIONS[0];
      }
    };
    const persistPerPage = (value) => {
      try {
        window.localStorage.setItem(PER_PAGE_STORAGE_KEY, String(value));
      } catch (error) {
        // Ignore storage failures and keep the current in-memory choice.
      }
    };
    let perPage = perPageSelect
      ? normalizePerPage(perPageSelect.value || readStoredPerPage())
      : readStoredPerPage();
    let currentPage = 1;
    let totalPages = 1;
    let totalItems = 0;
    let searchTimer = null;
    let isLoading = false;
    let isUpdating = false;
    let isPreparingRun = false;
    let stopRequested = false;
    let activeRunToken = "";
    let currentUpdateRequestController = null;
    let currentRows = [];
    const selectedIds = new Set();
    const selectedItems = new Map();
    const progressByPostId = new Map();
    let runProgressState = null;
    let runProgressTimer = null;
    let lastFreePostType = postTypeSelect ? postTypeSelect.value : "";
    let actionIsUpgrade = false;
    const actionLabelEl = actionButton
      ? actionButton.querySelector(".aipkit_btn-text")
      : null;
    const normalizeExistingMode = (mode) => {
      if (!mode) return "";
      if (mode === "existing") return "existing-content";
      if (mode.indexOf("existing-") === 0) return mode;
      return "";
    };

    const getExistingMode = () =>
      normalizeExistingMode(modeSelect ? modeSelect.value : "");

    const getDefaultExistingAction = () =>
      getExistingMode() ? "update" : actionButton?.dataset.action || "generate";

    const getModeConfig = () =>
      MODE_CONFIGS[getExistingMode()] || MODE_CONFIGS["existing-content"];

    const isExistingMode = () => Boolean(getExistingMode());

    const statusBadge = document.getElementById(
      "aipkit_content_writer_form_status"
    );

    const setUpdateStatus = (message, tone) => {
      const shouldShow = Boolean(message) && tone === "error";
      if (typeof window.aipkit_updateGeneralStatus === "function") {
        window.aipkit_updateGeneralStatus(
          shouldShow ? "error" : "clear",
          shouldShow ? message : ""
        );
        return;
      }
      if (!statusBadge) return;
      statusBadge.textContent = shouldShow ? message : "";
      statusBadge.className = shouldShow
        ? "aipkit_cw_status_badge aipkit_settings_message-error"
        : "aipkit_cw_status_badge";
    };

    const requestStop = () => {
      if (!isUpdating) return;
      stopRequested = true;
      if (currentUpdateRequestController) {
        currentUpdateRequestController.abort();
        currentUpdateRequestController = null;
      }
      if (
        activeRunToken &&
        typeof window.aipkit_apiRequest === "function"
      ) {
        window
          .aipkit_apiRequest("aipkit_content_writer_cancel_existing_update", {
            _ajax_nonce: nonceField ? nonceField.value : "",
            run_token: activeRunToken,
          })
          .catch((error) => {
            if (error?.code !== "update_run_stopped") {
              console.error("Content Writer Existing: stop failed", error);
            }
          });
      }
      if (runProgressState) {
        runProgressState.stopping = true;
        renderAggregateProgress();
      }
      setUpdateStatus(__("Stopping…", "gpt3-ai-content-generator"), "info");
    };

    const setActionUpgradeState = (enabled) => {
      actionIsUpgrade = Boolean(enabled);
      if (!actionButton) return;
      actionButton.classList.toggle(
        "aipkit_pro_upgrade_button",
        actionIsUpgrade
      );
      const nextAction = actionIsUpgrade ? "upgrade" : getDefaultExistingAction();
      actionButton.dataset.action = nextAction;
      if (actionShell) {
        actionShell.dataset.aipkitCwPrimaryAction = nextAction;
      }
      if (actionLabelEl && isExistingMode()) {
        const updateLabel =
          selectedIds.size > 1
            ? sprintf(
                __("Update %1$d", "gpt3-ai-content-generator"),
                selectedIds.size
              )
            : __("Update", "gpt3-ai-content-generator");
        actionLabelEl.textContent = actionIsUpgrade
          ? __("Upgrade", "gpt3-ai-content-generator")
          : updateLabel;
      }
    };

    const selectionHasProduct = (ids) =>
      ids.some((id) => {
        const row =
          selectedItems.get(id) ||
          currentRows.find((item) => Number(item.id) === id);
        return row?.type === "product";
      });

    const updateProActionState = () => {
      if (!isExistingMode()) {
        setActionUpgradeState(false);
        return;
      }
      if (isPro) {
        setActionUpgradeState(false);
        return;
      }

      const mode = getExistingMode();
      if (mode === "existing-products") {
        setActionUpgradeState(true);
        return;
      }
      if (mode === "existing-images") {
        setActionUpgradeState(selectedIds.size > 1);
        return;
      }

      const selected = Array.from(selectedIds);
      setActionUpgradeState(selected.length > 1 && selectionHasProduct(selected));
    };

    const setLoadingRow = (message) => {
      if (!tableBody) return;
      currentRows = [];
      totalItems = 0;
      tableBody.innerHTML = `
        <tr class="aipkit_cw_existing_empty">
          <td colspan="5">${escaper(message)}</td>
        </tr>
      `;
      updateSelectionUI();
    };

    const getItemCountLabel = (count) => {
      const mode = getExistingMode();
      if (mode === "existing-images") {
        return sprintf(
          _n("%1$d image", "%1$d images", count, "gpt3-ai-content-generator"),
          count
        );
      }
      if (mode === "existing-products") {
        return sprintf(
          _n("%1$d product", "%1$d products", count, "gpt3-ai-content-generator"),
          count
        );
      }
      return sprintf(
        _n("%1$d item", "%1$d items", count, "gpt3-ai-content-generator"),
        count
      );
    };

    const setActionDisabled = (disabled) => {
      actionButton.disabled = disabled;
      actionButton.setAttribute("aria-disabled", disabled ? "true" : "false");
      actionShell?.classList.toggle("is-disabled", disabled);
      if (disabled) {
        actionButton.dataset.aipkitExistingDisabled = "true";
      } else {
        delete actionButton.dataset.aipkitExistingDisabled;
      }
    };

    const updateSelectionUI = () => {
      const count = selectedIds.size;
      updateProActionState();
      if (selectedCount) {
        selectedCount.textContent = sprintf(
          __("%1$d selected", "gpt3-ai-content-generator"),
          count
        );
        selectedCount.hidden = count === 0;
      }
      if (summarySeparator) {
        summarySeparator.hidden = count === 0;
      }
      if (totalCount) {
        totalCount.textContent = getItemCountLabel(totalItems);
      }
      if (actionButton && isExistingMode()) {
        const shouldDisable =
          isUpdating || (count === 0 && !actionIsUpgrade);
        setActionDisabled(shouldDisable);
      }
      if (selectAllCheckbox) {
        const allSelected =
          currentRows.length > 0 &&
          currentRows.every((row) => selectedIds.has(row.id));
        selectAllCheckbox.checked = allSelected;
      }
    };

    const updatePaginationUI = () => {
      if (pageStatus) {
        pageStatus.textContent = `${currentPage}/${totalPages}`;
      }
      if (perPageSelect) {
        perPageSelect.value = String(perPage);
        perPageSelect.disabled = isUpdating;
      }
      if (prevButton) {
        prevButton.disabled = isUpdating || currentPage <= 1;
      }
      if (nextButton) {
        nextButton.disabled = isUpdating || currentPage >= totalPages;
      }
    };

    const formatElapsedTime = (milliseconds) => {
      const seconds = Math.max(0, Math.floor(milliseconds / 1000));
      const minutes = Math.floor(seconds / 60);
      return `${minutes}:${String(seconds % 60).padStart(2, "0")}`;
    };

    const renderAggregateProgress = () => {
      if (!runProgressState) return;
      const { total, current, processed, startedAt, stopping } =
        runProgressState;
      const progressValue = Math.min(total, Math.max(0, current));
      const percentage = total > 0 ? (progressValue / total) * 100 : 0;

      if (runProgressLabel) {
        runProgressLabel.textContent = stopping
          ? sprintf(
              __("Stopping · %1$d of %2$d processed", "gpt3-ai-content-generator"),
              processed,
              total
            )
          : sprintf(
              __("Updating %1$d of %2$d", "gpt3-ai-content-generator"),
              progressValue,
              total
            );
      }
      if (runProgressElapsed) {
        runProgressElapsed.textContent = formatElapsedTime(Date.now() - startedAt);
      }
      if (runProgressTrack) {
        runProgressTrack.setAttribute("aria-valuemax", String(total));
        runProgressTrack.setAttribute("aria-valuenow", String(progressValue));
      }
      if (runProgressBar) {
        runProgressBar.style.width = `${percentage}%`;
      }
    };

    const stopRunProgressTimer = () => {
      if (!runProgressTimer) return;
      window.clearInterval(runProgressTimer);
      runProgressTimer = null;
    };

    const beginAggregateProgress = (total) => {
      stopRunProgressTimer();
      runProgressState = {
        total,
        current: 0,
        processed: 0,
        startedAt: Date.now(),
        stopping: false,
      };
      if (controls) controls.hidden = true;
      if (runProgress) runProgress.hidden = false;
      renderAggregateProgress();
      runProgressTimer = window.setInterval(renderAggregateProgress, 1000);
    };

    const finishAggregateProgress = () => {
      stopRunProgressTimer();
      runProgressState = null;
      if (runProgress) runProgress.hidden = true;
      if (controls) controls.hidden = false;
    };

    const renderRowProgress = (postId) => {
      const row = panel.querySelector(`#post-${postId}`);
      if (!row) return;
      const progressRow = panel.querySelector(`#post-${postId}-progress`);
      const progressSlot = row.querySelector(
        ".aipkit_cw_existing_progress_slot"
      );
      const fieldsSlot =
        row.querySelector(".aipkit_cw_existing_progress_fields") ||
        progressRow?.querySelector(".aipkit_cw_existing_progress_fields");
      if (!progressSlot || !fieldsSlot) return;

      const progress = progressByPostId.get(postId) || null;
      const isRunning = progress?.state === "running";
      const hasRecovery = typeof progress?.generatedValue === "string" && progress.generatedValue !== "";
      row.classList.toggle("aipkit_cw_existing_row--running", isRunning);
      if (progressRow) {
        progressRow.hidden = !(isRunning || hasRecovery);
        progressRow.setAttribute("aria-hidden", isRunning || hasRecovery ? "false" : "true");
        progressRow.classList.toggle(
          "aipkit_cw_existing_row--running",
          isRunning
        );
      }
      progressSlot.replaceChildren();
      fieldsSlot.replaceChildren();
      progressSlot.removeAttribute("title");
      if (!progress) return;

      const pill = document.createElement("span");
      pill.className = `aipkit_cw_batch_status aipkit_cw_batch_status--${progress.state} aipkit_cw_existing_progress_pill`;
      pill.textContent = progress.label || PROGRESS_STATUS_LABELS[progress.state];
      progressSlot.appendChild(pill);

      if (progress.message) {
        progressSlot.title = progress.message;
      }

      if (hasRecovery) {
        const recovery = document.createElement("details");
        recovery.className = "aipkit_cw_existing_recovery";
        const summary = document.createElement("summary");
        summary.textContent = __("Generated text", "gpt3-ai-content-generator");
        const text = document.createElement("textarea");
        text.className = "aipkit_form-input";
        text.rows = 6;
        text.readOnly = true;
        text.setAttribute("aria-label", summary.textContent);
        text.value = progress.generatedValue;
        recovery.append(summary, text);
        fieldsSlot.appendChild(recovery);
      }

      if (isRunning) {
        const chipApi = window.aipkit_cw_batchOutputChips;
        if (chipApi && typeof chipApi.create === "function") {
          progress.fields.forEach((field) => {
            fieldsSlot.appendChild(
              chipApi.create({
                key: field.key,
                label: field.label,
                status: field.status,
              })
            );
          });
        }
      }
    };

    const renderVisibleRowProgress = () => {
      currentRows.forEach((item) => renderRowProgress(Number(item.id)));
    };

    const initializeRowProgress = (ids, fields) => {
      progressByPostId.clear();
      ids.forEach((postId) => {
        progressByPostId.set(postId, {
          state: "waiting",
          label: PROGRESS_STATUS_LABELS.waiting,
          message: "",
          fields: fields.map((field) => ({
            ...field,
            status: "waiting",
          })),
        });
      });
      renderVisibleRowProgress();
    };

    const setRowProgress = (postId, state, options = {}) => {
      const progress = progressByPostId.get(postId);
      if (!progress) return;
      progress.state = state;
      progress.label = options.label || PROGRESS_STATUS_LABELS[state] || state;
      progress.message = options.message || "";
      progress.generatedValue = options.generatedValue || "";
      renderRowProgress(postId);
    };

    const setFieldProgress = (postId, fieldKey, status) => {
      const progress = progressByPostId.get(postId);
      const field = progress?.fields.find((item) => item.key === fieldKey);
      if (!field) return;
      field.status = status;
      if (progress.state === "running") {
        renderRowProgress(postId);
      }
    };

    const renderRows = (posts, pagination) => {
      currentRows = Array.isArray(posts) ? posts : [];
      currentPage = pagination?.page || 1;
      totalPages = pagination?.total_pages || 1;
      totalItems = Number(pagination?.total || 0);
      perPage = normalizePerPage(pagination?.per_page || perPage);
      persistPerPage(perPage);
      currentRows.forEach((row) => {
        const postId = Number(row.id);
        if (selectedIds.has(postId)) {
          selectedItems.set(postId, row);
        }
      });

      if (!currentRows.length) {
        setLoadingRow(__("No posts found.", "gpt3-ai-content-generator"));
        updatePaginationUI();
        updateSelectionUI();
        return;
      }

      const isImageMode = getExistingMode() === "existing-images";
      const rowsHtml = currentRows
        .map((post) => {
          const postId = Number(post.id);
          const title = escaper(
            post.title || __("(Untitled)", "gpt3-ai-content-generator")
          );
          const editLink = escaper(post.edit_link || "#");
          const statusLabel = escaper(post.status_label || "");
          const statusKey = String(post.status || "")
            .toLowerCase()
            .replace(/[^a-z0-9_-]+/g, "-")
            .replace(/^-+|-+$/g, "");
          const statusClass = statusKey
            ? ` aipkit_cw_existing_post_status--${statusKey}`
            : "";
          const altText = isImageMode ? escaper(post.alt_text || "") : "";
          const captionText = isImageMode ? escaper(post.caption || "") : "";
          const descriptionText = isImageMode
            ? escaper(post.description || "")
            : "";
          const thumbUrl = isImageMode ? escaper(post.thumb_url || "") : "";
          const fileName = isImageMode ? escaper(post.file_name || "") : "";
          const isChecked = selectedIds.has(postId) ? "checked" : "";
          const postType = escaper(post.type || "post");
          const titleAttr = title ? ` title="${title}"` : "";
          const altAttr = altText ? ` title="${altText}"` : "";
          const captionAttr = captionText ? ` title="${captionText}"` : "";
          const descriptionAttr = descriptionText
            ? ` title="${descriptionText}"`
            : "";
          const thumbHtml = thumbUrl
            ? `<span class="aipkit_cw_existing_thumb"><img src="${thumbUrl}" alt="" loading="lazy"></span>`
            : `<span class="aipkit_cw_existing_thumb aipkit_cw_existing_thumb--empty" aria-hidden="true"></span>`;
          const titleCell = isImageMode
            ? `
                <div class="aipkit_cw_existing_title_stack">
                  ${thumbHtml}
                  <div class="aipkit_cw_existing_title_meta">
                    <a class="row-title aipkit_cw_existing_truncate" href="${editLink}" target="_blank" rel="noopener noreferrer"${titleAttr}>${title}</a>
                    ${fileName ? `<span class="aipkit_cw_existing_item_meta aipkit_cw_existing_truncate" title="${fileName}">${fileName}</span>` : ""}
                  </div>
                </div>
              `
            : `
                <div class="aipkit_cw_existing_title_meta">
                  <a class="row-title aipkit_cw_existing_truncate" href="${editLink}" target="_blank" rel="noopener noreferrer"${titleAttr}>${title}</a>
                </div>
              `;

          return `
            <tr id="post-${postId}" data-post-type="${postType}">
              <td class="aipkit_cw_existing_col_check">
                <input type="checkbox" class="aipkit_cw_existing_row_checkbox" value="${postId}" ${isChecked}>
              </td>
              <td class="aipkit_cw_existing_title" colspan="${isImageMode ? "1" : "4"}">
                <div class="aipkit_cw_existing_row_main">
                  <div class="aipkit_cw_existing_title_primary">
                    ${titleCell}
                  </div>
                  <span class="aipkit_cw_existing_post_status${statusClass}">${statusLabel}</span>
                  <span class="aipkit_cw_existing_progress_slot" aria-live="polite"></span>
                </div>
                ${isImageMode ? "" : '<div class="aipkit_cw_batch_outputs aipkit_cw_existing_progress_fields"></div>'}
              </td>
              ${isImageMode ? `
                <td class="aipkit_cw_existing_col_alt"><span class="aipkit_cw_existing_truncate"${altAttr}>${altText}</span></td>
                <td class="aipkit_cw_existing_col_caption"><span class="aipkit_cw_existing_truncate"${captionAttr}>${captionText}</span></td>
                <td class="aipkit_cw_existing_col_description"><span class="aipkit_cw_existing_truncate"${descriptionAttr}>${descriptionText}</span></td>
              ` : ""}
            </tr>
            ${isImageMode ? `
              <tr id="post-${postId}-progress" class="aipkit_cw_existing_progress_row" aria-hidden="true" hidden>
                <td colspan="5">
                  <div class="aipkit_cw_batch_outputs aipkit_cw_existing_progress_fields"></div>
                </td>
              </tr>
            ` : ""}
          `;
        })
        .join("");

      tableBody.innerHTML = rowsHtml;
      renderVisibleRowProgress();
      updatePaginationUI();
      updateSelectionUI();
    };

    const normalizeTitle = (value) => {
      const cleaned = String(value || "").trim();
      return cleaned
        ? cleaned
        : __("(Untitled)", "gpt3-ai-content-generator");
    };

    const updateRowTitle = (postId, titleValue) => {
      const normalized = normalizeTitle(titleValue);
      const rowData = currentRows.find((item) => Number(item.id) === postId);
      if (rowData) {
        rowData.title = normalized;
      }
      const selectedItem = selectedItems.get(postId);
      if (selectedItem) {
        selectedItem.title = normalized;
      }
      const row = panel.querySelector(`#post-${postId}`);
      const titleLink = row?.querySelector(".row-title");
      if (titleLink) {
        titleLink.textContent = normalized;
        titleLink.setAttribute("title", normalized);
      }
    };

    const updateRowImageField = (postId, field, value) => {
      let selector = "";
      let dataKey = "";
      if (field === "keyword") {
        selector = ".aipkit_cw_existing_col_alt .aipkit_cw_existing_truncate";
        dataKey = "alt_text";
      } else if (field === "excerpt") {
        selector =
          ".aipkit_cw_existing_col_caption .aipkit_cw_existing_truncate";
        dataKey = "caption";
      } else if (field === "content") {
        selector =
          ".aipkit_cw_existing_col_description .aipkit_cw_existing_truncate";
        dataKey = "description";
      } else {
        return;
      }
      const normalized = String(value || "").trim();
      const rowData = currentRows.find((item) => Number(item.id) === postId);
      if (rowData && dataKey) {
        rowData[dataKey] = normalized;
      }
      const selectedItem = selectedItems.get(postId);
      if (selectedItem && dataKey) {
        selectedItem[dataKey] = normalized;
      }
      const row = panel.querySelector(`#post-${postId}`);
      const target = row?.querySelector(selector);
      if (!target) {
        return;
      }
      target.textContent = normalized;
      if (normalized) {
        target.setAttribute("title", normalized);
      } else {
        target.removeAttribute("title");
      }
    };

    const setUpdatingState = (shouldLock) => {
      isUpdating = shouldLock;
      panel.classList.toggle(
        "aipkit_cw_existing_panel--updating",
        shouldLock
      );
      if (actionButton && isExistingMode()) {
        const stopModeActive =
          actionButton.dataset && actionButton.dataset.aipkitStopMode === "true";
        const shouldDisable =
          (shouldLock && !stopModeActive) ||
          (!shouldLock && selectedIds.size === 0 && !actionIsUpgrade);
        setActionDisabled(shouldDisable);
      }
      if (postTypeSelect) {
        const modeConfig = getModeConfig();
        postTypeSelect.disabled =
          shouldLock || Boolean(modeConfig.postTypeOverride);
      }
      if (mediaFilterSelect) mediaFilterSelect.disabled = shouldLock;
      if (searchInput) searchInput.disabled = shouldLock;
      if (perPageSelect) perPageSelect.disabled = shouldLock;
      if (prevButton) prevButton.disabled = shouldLock || currentPage <= 1;
      if (nextButton) nextButton.disabled = shouldLock || currentPage >= totalPages;
      if (selectAllCheckbox) selectAllCheckbox.disabled = shouldLock;
      tableBody
        .querySelectorAll(".aipkit_cw_existing_row_checkbox")
        .forEach((checkbox) => {
          checkbox.disabled = shouldLock;
        });
    };

    const applyModeOverrides = () => {
      const modeConfig = getModeConfig();
      if (titleHeader) {
        titleHeader.colSpan = getExistingMode() === "existing-images" ? 1 : 4;
      }
      if (postTypeSelect) {
        if (modeConfig.postTypeOverride) {
          postTypeSelect.value = modeConfig.postTypeOverride;
          postTypeSelect.disabled = true;
        } else {
          postTypeSelect.disabled = false;
          postTypeSelect.value = lastFreePostType || "";
        }
      }
    };

    const setActionLoadingState = (isLoading) => {
      if (!actionButton || !isExistingMode()) {
        return;
      }
      if (actionButton.dataset && actionButton.dataset.aipkitStopMode === "true") {
        return;
      }
      if (typeof window.aipkit_setLoadingStateOpenAI === "function") {
        window.aipkit_setLoadingStateOpenAI(
          actionButton,
          isLoading,
          __("Update", "gpt3-ai-content-generator"),
          __("Updating…", "gpt3-ai-content-generator")
        );
      } else {
        actionButton.disabled = isLoading;
      }
    };

    const getPromptValue = (config, key) => {
      const updateKey = `${key}_update`;
      const raw =
        (config && config[updateKey]) || (config && config[key]) || "";
      return String(raw || "").trim();
    };

    const buildEnhancementsConfig = (modeConfig) => {
      const form = document.getElementById("aipkit_content_writer_form");
      if (!form || typeof window.aipkit_getContentWriterFormConfig !== "function") {
        return {
          error: __("Content Writer form is not ready yet.", "gpt3-ai-content-generator"),
        };
      }
      const config = window.aipkit_getContentWriterFormConfig(form);
      const useImageMetadataPrompts = Boolean(modeConfig.useImageMetadataPrompts);

      if (!isPro && modeConfig.postTypeOverride === "product") {
        return {
          error: __("Product optimization is a Pro feature. Upgrade to continue.", "gpt3-ai-content-generator"),
        };
      }

      const shouldGenerate = (field) => {
        if (useImageMetadataPrompts) {
          if (field === "title") return config.generate_image_title === "1";
          if (field === "keyword") return config.generate_image_alt_text === "1";
          if (field === "excerpt") return config.generate_image_caption === "1";
          if (field === "content") return config.generate_image_description === "1";
          return false;
        }
        if (field === "title") return config.generate_title !== "0";
        if (field === "content") return config.generate_content !== "0";
        if (field === "meta") return config.generate_meta_description === "1";
        if (field === "keyword") return config.generate_focus_keyword === "1";
        if (field === "excerpt") return config.generate_excerpt === "1";
        if (field === "tags") return config.generate_tags === "1";
        return false;
      };

      const promptKeys = useImageMetadataPrompts
        ? {
            title: "image_title_prompt",
            content: "image_description_prompt",
            meta: "",
            keyword: "image_alt_text_prompt",
            excerpt: "image_caption_prompt",
            tags: "",
          }
        : {
            title: "custom_title_prompt",
            content: "custom_content_prompt",
            meta: "custom_meta_prompt",
            keyword: "custom_keyword_prompt",
            excerpt: "custom_excerpt_prompt",
            tags: "custom_tags_prompt",
          };
      const prompts = {};
      Object.entries(promptKeys).forEach(([field, key]) => {
        prompts[field] = shouldGenerate(field) ? getPromptValue(config, key) : "";
      });

      const errors = [];
      const getFieldLabel = (field) =>
        modeConfig.fieldLabels?.[field] || DEFAULT_FIELD_LABELS[field] || field;

      Object.keys(prompts).forEach((field) => {
        if (shouldGenerate(field) && !prompts[field]) {
          errors.push(`${getFieldLabel(field)} prompt is required.`);
        }
      });

      if (errors.length) {
        return { error: errors.join(" ") };
      }

      const contentLengthKey = String(config.content_length || "medium");
      const maxTokens = lengthMap[contentLengthKey] || lengthMap.medium;

      const baseConfig = {
        ai_provider: config.ai_provider || "openai",
        ai_model: config.ai_model || "",
        temperature: parseFloat(config.ai_temperature || "1"),
        max_tokens: maxTokens,
        reasoning_effort: config.reasoning_effort || "",
        enable_vector_store: config.enable_vector_store || "0",
        vector_store_provider: config.vector_store_provider || "openai",
        vector_store_top_k: config.vector_store_top_k || "3",
        openai_vector_store_ids: config.openai_vector_store_ids || [],
        google_file_search_store_names: config.google_file_search_store_names || [],
        pinecone_index_name: config.pinecone_index_name || "",
        qdrant_collection_name: config.qdrant_collection_name || "",
        chroma_collection_name: config.chroma_collection_name || "",
        vector_embedding_provider: config.vector_embedding_provider || "",
        vector_embedding_model: config.vector_embedding_model || "",
      };

      return { prompts, baseConfig };
    };

    const buildFieldPayload = (field, prompt, baseConfig) => ({
      [field]: { prompt },
      ...baseConfig,
    });

    const fetchPosts = async (page = 1) => {
      if (isLoading) {
        return;
      }
      if (typeof window.aipkit_apiRequest !== "function") {
        console.error("Content Writer Existing: aipkit_apiRequest not found.");
        return;
      }

      isLoading = true;
      setLoadingRow(__("Loading posts…", "gpt3-ai-content-generator"));

      try {
        const modeConfig = getModeConfig();
        const postTypeValue = modeConfig.postTypeOverride ||
          (postTypeSelect ? postTypeSelect.value : "");

        const response = await window.aipkit_apiRequest(
          "aipkit_content_writer_fetch_existing_posts",
          {
            _ajax_nonce: nonceField ? nonceField.value : "",
            post_type: postTypeValue,
            media_filter: mediaFilterSelect ? mediaFilterSelect.value : "",
            search: searchInput ? searchInput.value.trim() : "",
            paged: page,
            per_page: perPage,
          }
        );
        renderRows(response.posts || [], response.pagination || {});
      } catch (error) {
        console.error("Content Writer Existing: fetch failed", error);
        setLoadingRow(
          __("Unable to load posts. Please try again.", "gpt3-ai-content-generator")
        );
      } finally {
        isLoading = false;
      }
    };

    const resetSelection = () => {
      selectedIds.clear();
      selectedItems.clear();
      updateSelectionUI();
    };

    tableBody.addEventListener("change", (event) => {
      const checkbox = event.target;
      if (!checkbox || !checkbox.classList.contains("aipkit_cw_existing_row_checkbox")) {
        return;
      }
      const postId = Number(checkbox.value || 0);
      if (!postId) return;
      if (checkbox.checked) {
        selectedIds.add(postId);
        const item = currentRows.find((row) => Number(row.id) === postId);
        if (item) {
          selectedItems.set(postId, item);
        }
      } else {
        selectedIds.delete(postId);
        selectedItems.delete(postId);
      }
      updateSelectionUI();
    });

    if (selectAllCheckbox) {
      selectAllCheckbox.addEventListener("change", () => {
        const shouldSelect = selectAllCheckbox.checked;
        currentRows.forEach((row) => {
          if (shouldSelect) {
            selectedIds.add(Number(row.id));
            selectedItems.set(Number(row.id), row);
          } else {
            selectedIds.delete(Number(row.id));
            selectedItems.delete(Number(row.id));
          }
        });
        const checkboxes = tableBody.querySelectorAll(
          ".aipkit_cw_existing_row_checkbox"
        );
        checkboxes.forEach((checkbox) => {
          checkbox.checked = shouldSelect;
        });
        updateSelectionUI();
      });
    }

    if (prevButton) {
      prevButton.addEventListener("click", () => {
        if (currentPage > 1) {
          fetchPosts(currentPage - 1);
        }
      });
    }

    if (nextButton) {
      nextButton.addEventListener("click", () => {
        if (currentPage < totalPages) {
          fetchPosts(currentPage + 1);
        }
      });
    }

    if (postTypeSelect) {
      postTypeSelect.addEventListener("change", () => {
        if (!getModeConfig().postTypeOverride) {
          lastFreePostType = postTypeSelect.value;
        }
        resetSelection();
        fetchPosts(1);
      });
    }

    if (mediaFilterSelect) {
      mediaFilterSelect.addEventListener("change", () => {
        resetSelection();
        fetchPosts(1);
      });
    }

    if (searchInput) {
      searchInput.addEventListener("input", () => {
        if (searchTimer) {
          clearTimeout(searchTimer);
        }
        searchTimer = setTimeout(() => {
          resetSelection();
          fetchPosts(1);
        }, 300);
      });
    }

    if (perPageSelect) {
      perPageSelect.value = String(perPage);
      perPageSelect.addEventListener("change", () => {
        perPage = normalizePerPage(perPageSelect.value);
        perPageSelect.value = String(perPage);
        persistPerPage(perPage);
        resetSelection();
        fetchPosts(1);
      });
    }

    const redirectToPromptRequirement = () => {
      const form = document.getElementById("aipkit_content_writer_form");
      const promptRoot =
        form?.querySelector("[data-aipkit-cw-inline-prompts]") ||
        scopeElement.querySelector("[data-aipkit-cw-inline-prompts]");
      const promptRequirementApi =
        promptRoot?.aipkitPromptRequirementApi || null;
      const validatePromptRequirements =
        promptRequirementApi?.validate ||
        window.aipkit_validateContentWriterPromptRequirements;
      if (typeof validatePromptRequirements !== "function") {
        return false;
      }

      const promptRequirements = validatePromptRequirements(
        form,
        getExistingMode()
      );
      if (promptRequirements.isValid) {
        return false;
      }

      const focusPromptRequirement =
        promptRequirementApi?.focus ||
        window.aipkit_focusContentWriterPromptRequirement;
      focusPromptRequirement?.(promptRequirements.firstIssue);
      return true;
    };

    const showAlert = (message) => {
      if (typeof window.aipkit_showAlertModal === "function") {
        window.aipkit_showAlertModal(message, {
          title: __("Update existing", "gpt3-ai-content-generator"),
        });
      } else {
        alert(message);
      }
    };

    const showSelectItemsDialog = () => {
      const texts = window.aipkit_post_enhancer?.text || {};
      const title =
        texts.select_posts_title || __("Select items", "gpt3-ai-content-generator");
      const message =
        texts.select_posts_message ||
        __("Please select at least one item.", "gpt3-ai-content-generator");

      if (typeof window.aipkit_showConfirmModal === "function") {
        window.aipkit_showConfirmModal(message, {
          title,
          confirmText: texts.ok || __("OK", "gpt3-ai-content-generator"),
          variant: "warning",
          hideCancel: true,
        });
        return;
      }

      if (typeof window.aipkit_showAlertModal === "function") {
        window.aipkit_showAlertModal(message, { title });
        return;
      }

      alert(message);
    };

    const openUpgradePage = () => {
      if (upgradeUrl) {
        window.open(upgradeUrl, "_blank", "noopener,noreferrer");
      } else {
        console.error("Content Writer upgrade URL is unavailable.");
      }
    };

    const getProgressFields = (modeConfig, prompts) =>
      modeConfig.fieldOrder
        .filter((field) => Boolean(prompts[field]))
        .map((field) => ({
          key: field,
          label:
            PROGRESS_FIELD_LABELS[field] ||
            modeConfig.fieldLabels[field] ||
            field,
        }));

    const prepareUpdateRun = async (ids, fields) => {
      const response = await window.aipkit_apiRequest(
        "aipkit_content_writer_prepare_existing_update",
        {
          _ajax_nonce: nonceField ? nonceField.value : "",
          mode: getExistingMode(),
          post_ids: JSON.stringify(ids),
          fields: JSON.stringify(fields),
        }
      );
      const runToken = String(response?.run_token || "");
      if (!runToken) {
        throw new Error(
          __("The update session could not be prepared.", "gpt3-ai-content-generator")
        );
      }
      return runToken;
    };

    const processSinglePost = async (
      postId,
      prompts,
      baseConfig,
      nonce,
      modeConfig,
      runToken
    ) => {
      const convUuid = `cw-existing-${postId}-${Date.now()}`;
      let processedAny = false;

      if (stopRequested) {
        setRowProgress(postId, "stopped");
        return { success: false, stopped: true };
      }

      for (const field of modeConfig.fieldOrder) {
        const prompt = prompts[field];
        if (!prompt) continue;

        if (stopRequested) {
          setRowProgress(postId, "stopped");
          return { success: false, stopped: true };
        }

        setFieldProgress(postId, field, "running");

        const payload = buildFieldPayload(field, prompt, baseConfig);
        const requestData = {
          _ajax_nonce: nonce,
          post_id: postId,
          field,
          enhancer_source: "content_writer",
          content_writer_run_token: runToken,
          enhancements: JSON.stringify(payload),
          conversation_uuid: convUuid,
        };

        const requestController = new AbortController();
        currentUpdateRequestController = requestController;

        try {
          const response = await window.aipkit_apiRequest(
            "aipkit_bulk_process_single_field",
            requestData,
            {
              timeout: 180000,
              signal: requestController.signal,
              abortMessage: __("Update stopped.", "gpt3-ai-content-generator"),
            }
          );
          if (
            response &&
            Object.prototype.hasOwnProperty.call(response, "updated_value")
          ) {
            if (field === "title") {
              updateRowTitle(postId, response.updated_value);
            } else {
              updateRowImageField(postId, field, response.updated_value);
            }
          }
          processedAny = true;
          setFieldProgress(postId, field, "done");
        } catch (error) {
          if (
            stopRequested &&
            (error?.code === "aborted" ||
              error?.code === "update_run_stopped" ||
              error?.name === "AbortError")
          ) {
            setFieldProgress(postId, field, "stopped");
            setRowProgress(postId, "stopped");
            return { success: false, stopped: true };
          }
          const errorMessage =
            (error && error.message) ||
            __("Update failed. Please try again.", "gpt3-ai-content-generator");
          setFieldProgress(postId, field, "error");
          setRowProgress(postId, "error", { message: errorMessage, generatedValue: error.data?.generated_value });
          return { success: false, stopBatch: !!error.data?.stop_batch };
        } finally {
          if (currentUpdateRequestController === requestController) {
            currentUpdateRequestController = null;
          }
        }
      }

      if (stopRequested) {
        setRowProgress(postId, "stopped");
        return { success: false, stopped: true };
      }

      if (processedAny) {
        return { success: true };
      } else {
        setRowProgress(postId, "error", {
          message: __("No prompts selected.", "gpt3-ai-content-generator"),
        });
        return { success: false };
      }
    };

    const runUpdateForSelection = async () => {
      if (isUpdating || isPreparingRun) return;
      if (!isExistingMode()) return;
      const ids = Array.from(selectedIds);
      if (!ids.length) {
        showSelectItemsDialog();
        return;
      }

      if (!isPro && getExistingMode() === "existing-content") {
        if (ids.length > 1 && selectionHasProduct(ids)) {
          openUpgradePage();
          updateSelectionUI();
          return;
        }
      }

      if (!isPro && getExistingMode() === "existing-images" && ids.length > 1) {
        openUpgradePage();
        return;
      }

      if (redirectToPromptRequirement()) {
        return;
      }

      if (typeof window.aipkit_apiRequest !== "function") {
        showAlert(__("Update service is not available yet.", "gpt3-ai-content-generator"));
        return;
      }

      const nonce = window.aipkit_post_enhancer?.nonce_generate_title;
      if (!nonce) {
        showAlert(
          __("Update permission is missing. Please reload the page.", "gpt3-ai-content-generator")
        );
        return;
      }

      const modeConfig = getModeConfig();
      const { prompts, baseConfig, error } = buildEnhancementsConfig(modeConfig);
      if (error) {
        if (!isPro && modeConfig.postTypeOverride === "product") {
          openUpgradePage();
        } else {
          showAlert(error);
        }
        return;
      }

      const hasPrompts = modeConfig.fieldOrder.some((field) => !!prompts[field]);
      if (!hasPrompts) {
        showAlert(__("No prompts selected for update.", "gpt3-ai-content-generator"));
        return;
      }

      stopRequested = false;
      const progressFields = getProgressFields(modeConfig, prompts);
      let runToken = "";
      isPreparingRun = true;
      try {
        runToken = await prepareUpdateRun(
          ids,
          progressFields.map((field) => field.key)
        );
      } catch (error) {
        showAlert(
          (error && error.message) ||
            __("The update could not be prepared. Please try again.", "gpt3-ai-content-generator")
        );
        return;
      } finally {
        isPreparingRun = false;
      }
      activeRunToken = runToken;
      initializeRowProgress(ids, progressFields);

      let completed = 0;
      let failed = 0;
      const total = ids.length;
      let stopIndex = null;

      beginAggregateProgress(total);
      setUpdateStatus("", "clear");
      if (typeof window.aipkit_setContentWriterStopMode === "function") {
        window.aipkit_setContentWriterStopMode(true);
      } else {
        setActionLoadingState(true);
      }
      setUpdatingState(true);
      for (let index = 0; index < ids.length; index += 1) {
        const postId = ids[index];
        if (stopRequested) {
          stopIndex = index;
          break;
        }
        if (runProgressState) {
          runProgressState.current = index + 1;
          renderAggregateProgress();
        }
        setRowProgress(postId, "running");
        const result = await processSinglePost(
          postId,
          prompts,
          baseConfig,
          nonce,
          modeConfig,
          runToken
        );
        if (result && result.stopped) {
          stopRequested = true;
          stopIndex = index + 1;
          break;
        }
        if (result && result.success) {
          completed += 1;
          setRowProgress(postId, "success");
        } else {
          failed += 1;
        }
        if (runProgressState) {
          runProgressState.processed = completed + failed;
          renderAggregateProgress();
        }
        if (result?.stopBatch) {
          stopRequested = true;
          stopIndex = index + 1;
          break;
        }
      }
      setUpdatingState(false);
      activeRunToken = "";
      currentUpdateRequestController = null;
      finishAggregateProgress();
      if (typeof window.aipkit_resetContentWriterButtons === "function") {
        window.aipkit_resetContentWriterButtons();
      } else {
        setActionLoadingState(false);
      }
      if (stopRequested) {
        const remainingIds =
          stopIndex !== null ? ids.slice(stopIndex) : [];
        remainingIds.forEach((postId) => {
          setRowProgress(postId, "stopped");
        });
        const summaryParts = [
          sprintf(
            __("Update stopped: %1$d done", "gpt3-ai-content-generator"),
            completed
          ),
        ];
        if (failed) {
          summaryParts.push(
            sprintf(
              _n(
                "%1$d error",
                "%1$d errors",
                failed,
                "gpt3-ai-content-generator"
              ),
              failed
            )
          );
        }
        setUpdateStatus(summaryParts.join(", ") + ".", "info");
      } else if (failed) {
        setUpdateStatus(
          sprintf(
            _n(
              "Update complete: %1$d done, %2$d error.",
              "Update complete: %1$d done, %2$d errors.",
              failed,
              "gpt3-ai-content-generator"
            ),
            completed,
            failed
          ),
          "error"
        );
      } else {
        setUpdateStatus(
          sprintf(
            __("Update complete: %1$d done.", "gpt3-ai-content-generator"),
            completed
          ),
          "success"
        );
      }
      updateSelectionUI();
    };

    panel.dataset.init = "true";

    window.aipkit_cw_existing_handleFetch = fetchPosts;
    window.aipkit_runContentWriterExistingUpdate = runUpdateForSelection;
    window.aipkit_cw_isExistingUpdateActive = () => isUpdating;
    window.aipkit_cw_requestExistingUpdateStop = requestStop;
    if (modeSelect && !modeSelect.dataset.aipkitExistingListener) {
      modeSelect.addEventListener(
        "change",
        () => {
          if (searchTimer) {
            clearTimeout(searchTimer);
            searchTimer = null;
          }
          if (searchInput) {
            searchInput.value = "";
          }
          if (mediaFilterSelect) {
            mediaFilterSelect.value = "";
          }
          applyModeOverrides();
          resetSelection();
          progressByPostId.clear();
          renderVisibleRowProgress();
        },
        true
      );
      modeSelect.dataset.aipkitExistingListener = "true";
    }
    applyModeOverrides();
    if (window.aipkit_cw_existing_pendingFetch) {
      fetchPosts(window.aipkit_cw_existing_pendingFetch);
      window.aipkit_cw_existing_pendingFetch = null;
    }
  }

  window.aipkit_initContentWriterExistingContent =
    aipkit_initContentWriterExistingContent;
  window.aipkit_fetchContentWriterExistingPosts = requestFetch;
})();
