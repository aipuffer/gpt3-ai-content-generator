/**
 * AIPKit Automated Tasks - Render Queue Table
 * Renders items into the queue table.
 * REDESIGNED: Selectable 6-column layout with combined timing and icon actions
 * Philosophy: Reduced choice overload, better chunking, cleaner visual hierarchy
 */
(function () {
  "use strict";

  function aipkit_queue_formatErrorMessage(message) {
    const rawMessage = typeof message === "string" ? message.trim() : "";
    if (!rawMessage) {
      return "";
    }

    return rawMessage.replace(
      /^(?:Title|Content) generation failed:\s*/i,
      ""
    );
  }

  function aipkit_queue_renderQueueTable(items) {
    const queueTableBody = document.getElementById(
      "aipkit_automated_task_queue_tbody"
    );
    const queueState = window.aipkit_automated_tasks_queue_state;
    const config = window.aipkit_automated_tasks_config || {};
    const texts = config.text || {};
    const escaper =
      window.aipkit_escapeHtml ||
      function (str) {
        return str;
      };
    const ucfirst =
      window.aipkit_ucfirst ||
      function (s) {
        return s ? s.charAt(0).toUpperCase() + s.slice(1) : "";
      };

    if (!queueTableBody) {
      console.warn("Render Queue Table: Table body not found.");
      return;
    }
    queueTableBody.innerHTML = "";

    const hasItems = Array.isArray(items) && items.length > 0;

    if (!hasItems) {
      queueTableBody.innerHTML = `<tr><td colspan="6" class="aipkit_text-center">${
        texts.queue_empty || "Task queue is currently empty."
      }</td></tr>`;
      if (typeof window.aipkit_queue_syncSelectionUi === "function") {
        window.aipkit_queue_syncSelectionUi();
      }
      return;
    }

    items.forEach((item) => {
      const row = queueTableBody.insertRow();
      const itemId = Number(item.id) || 0;
      const isSelected = Boolean(
        itemId && queueState?.selectedIds?.has(itemId)
      );
      row.dataset.queueItemId = String(itemId);
      row.classList.toggle("aipkit_autogpt_queue_row--selected", isSelected);

      const selectCell = row.insertCell();
      selectCell.className = "aipkit_autogpt_queue_select_cell";
      selectCell.innerHTML = `<input type="checkbox" class="aipkit_autogpt_queue_row_checkbox" value="${itemId}" aria-label="${escaper(
        texts.select_queue_item || "Select queue item"
      )}" ${isSelected ? "checked" : ""}>`;

      // Column 2: Item identifier (with link if applicable)
      const targetCell = row.insertCell();
      const tempDecoder = document.createElement("textarea");
      tempDecoder.innerHTML =
        item.target_title ||
        `${texts.target_id_prefix || "ID:"} ${item.target_identifier}`;
      const targetTitle = tempDecoder.value;

      const editUrl =
        item.status === "completed" && typeof item.post_edit_url === "string"
          ? item.post_edit_url.trim()
          : "";

      if (editUrl) {
        targetCell.innerHTML = `
          <div class="aipkit_queue_primary_cell">
            <a href="${escaper(editUrl)}" target="_blank" rel="noopener noreferrer" title="${escaper(
              texts.edit_generated_post_title || "View post"
            )}" class="aipkit_queue_primary_link">${escaper(targetTitle)}</a>
          </div>
        `;
      } else {
        targetCell.innerHTML = `
          <div class="aipkit_queue_primary_cell">
            <span class="aipkit_task_primary_text">${escaper(targetTitle)}</span>
          </div>
        `;
      }

      // Column 3: Task name
      const taskNameCell = row.insertCell();
      const taskName =
        item.task_name || `${texts.task_id_prefix || "Task"} ${item.task_id}`;
      taskNameCell.textContent = taskName;

      // Column 4: Timing (Added + Scheduled)
      const timingCell = row.insertCell();
      if (item.added_at) {
        const addedLocal = new Date(
          item.added_at.replace(" ", "T") + "Z"
        ).toLocaleString(undefined, {
          month: "short",
          day: "numeric",
          hour: "2-digit",
          minute: "2-digit",
        });
        const addedRelative =
          typeof window.aipkit_formatRelativeDateTime === "function"
            ? window.aipkit_formatRelativeDateTime(item.added_at)
            : addedLocal;
        let timingHTML = `
          <div class="aipkit_task_timing aipkit_task_timing--tasks-inline aipkit_task_timing--queue-inline">
            <div class="aipkit_task_timing_item aipkit_task_timing_last aipkit_queue_time_entry aipkit_queue_time_added aipkit_queue_time_added--inline">
              <span class="aipkit_task_timing_value" title="${escaper(
                addedLocal
              )}">${escaper(addedRelative)}</span>
            </div>
        `;
        if (item.scheduled_gmt_time) {
          const schedLocal = new Date(
            item.scheduled_gmt_time.replace(" ", "T") + "Z"
          ).toLocaleString(undefined, {
            month: "short",
            day: "numeric",
            hour: "2-digit",
            minute: "2-digit",
          });
          const schedRelative =
            typeof window.aipkit_formatFriendlyFutureDate === "function"
              ? window.aipkit_formatFriendlyFutureDate(item.scheduled_gmt_time)
              : schedLocal;
          timingHTML += `
            <div class="aipkit_task_timing_item aipkit_task_timing_next aipkit_queue_time_entry aipkit_queue_time_scheduled aipkit_queue_time_scheduled--inline">
              <span class="aipkit_task_timing_label">${escaper(
                texts.scheduled_label || "Scheduled"
              )}:</span>
              <span class="aipkit_task_timing_value" title="${escaper(
                schedLocal
              )}">${escaper(schedRelative)}</span>
            </div>
          `;
        }
        timingHTML += `</div>`;
        timingCell.innerHTML = timingHTML;
      } else {
        timingCell.textContent = texts.not_applicable || "—";
      }

      // Column 5: Status (with error info if failed)
      const statusCell = row.insertCell();
      const normalizedErrorMessage = aipkit_queue_formatErrorMessage(
        item.error_message
      );
      let badgeHTML = `<span class="aipkit_job_status_${escaper(item.status)}">${escaper(ucfirst(item.status))}`;
      if (item.status === "failed" && item.attempts > 1) {
        badgeHTML += ` <small class="aipkit_job_status_meta">(${item.attempts}x)</small>`;
      }
      badgeHTML += `</span>`;

      if (item.status === "failed" && normalizedErrorMessage) {
        statusCell.innerHTML = `
          <div class="aipkit_queue_status_row aipkit_queue_status_row--failed">
            ${badgeHTML}
            <span class="aipkit_queue_status_detail" title="${escaper(
              normalizedErrorMessage
            )}">${escaper(normalizedErrorMessage)}</span>
          </div>
        `;
      } else {
        statusCell.innerHTML = badgeHTML;
      }

      // Column 6: Compact icon actions. Tooltips and accessible labels retain clarity.
      const actionsCell = row.insertCell();
      actionsCell.className = "aipkit_actions_cell";
      let actionsHTML = `<div class="aipkit-table-actions-group aipkit-table-actions-group--queue">`;
      if (item.status === "failed") {
        actionsHTML += `<button class="aipkit_btn aipkit_btn-small aipkit_btn-icon-only aipkit_btn-spinner-only" data-action="retry_queue_item" data-item-id="${item.id}" title="${texts.retry_button || "Retry"}" aria-label="${texts.retry_button || "Retry"}"><span class="dashicons dashicons-controls-repeat" aria-hidden="true"></span><span class="aipkit_spinner" aria-hidden="true"></span></button>`;
      }
      actionsHTML += `<button class="aipkit_btn aipkit_btn-small aipkit_btn-icon-only" data-action="delete_queue_item" data-item-id="${item.id}" title="${texts.delete_button || "Delete"}" aria-label="${texts.delete_button || "Delete"}"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>`;
      actionsHTML += `</div>`;
      actionsCell.innerHTML = actionsHTML;
    });

    if (typeof window.aipkit_queue_syncSelectionUi === "function") {
      window.aipkit_queue_syncSelectionUi();
    }
  }

  window.aipkit_queue_renderQueueTable = aipkit_queue_renderQueueTable;
})();
