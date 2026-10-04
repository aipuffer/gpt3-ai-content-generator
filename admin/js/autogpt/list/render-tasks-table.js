/**
 * AIPKit Automated Tasks - Render Tasks Table
 * Renders the list of tasks into the table.
 * REDESIGNED: 5-column layout with combined timing and compact icon actions
 * Philosophy: Reduced choice overload, better chunking, cleaner visual hierarchy
 */
(function () {
  "use strict";

  function aipkit_list_renderTasksTable(tasks) {
    const tableBody = document.getElementById("aipkit_automated_tasks_tbody");
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

    const frequencyMap = config.frequencies || {};
    if (!tableBody) {
      console.warn("Render Tasks Table: Table body not found.");
      return;
    }
    tableBody.innerHTML = "";

    if (!tasks || tasks.length === 0) {
      tableBody.innerHTML = `<tr><td colspan="5" class="aipkit_text-center">${
        texts.no_tasks_configured || "No automated tasks configured yet."
      }</td></tr>`;
      return;
    }

    tasks.forEach((task) => {
      const taskConfig = JSON.parse(task.task_config || "{}");
      const setupIssue =
        window.aipkit_autogpt_provider_setup?.getSavedTaskCredentialIssue?.(
          task.task_type,
          taskConfig
        ) || "";
      const row = tableBody.insertRow();
      row.classList.toggle("aipkit_task_needs_setup", Boolean(setupIssue));

      // Column 1: Name and task-type icon (primary identifier)
      const nameCell = row.insertCell();
      const taskDetails = config.task_types?.[task.task_type] || {};
      const taskTypeLabel =
        taskDetails.label || ucfirst(task.task_type.replace(/_/g, " "));
      const configuredIcon = String(taskDetails.icon || "");
      const taskTypeIcon = /^dashicons-[a-z0-9-]+$/.test(configuredIcon)
        ? configuredIcon
        : "dashicons-admin-generic";
      nameCell.className = "aipkit_task_name_cell";
      nameCell.innerHTML = `
        <div class="aipkit_task_name_content">
          <span class="aipkit_task_type_icon dashicons ${taskTypeIcon}" role="img" aria-label="${escaper(taskTypeLabel)}" title="${escaper(taskTypeLabel)}"></span>
          <span class="aipkit_task_primary_text" title="${escaper(task.task_name || "")}">${escaper(task.task_name || "")}</span>
        </div>
      `;

      // Column 2: Frequency
      const rawFrequency =
        taskConfig.indexing_frequency || taskConfig.task_frequency || "N/A";
      const friendlyFrequency =
        frequencyMap[rawFrequency] || ucfirst(rawFrequency.replace(/_/g, " "));
      const frequencyCell = row.insertCell();
      frequencyCell.innerHTML = `<span class="aipkit_task_frequency_value">${escaper(
        friendlyFrequency
      )}</span>`;

      // Column 3: Status badge
      const statusCell = row.insertCell();
      statusCell.innerHTML = `<span class="aipkit_job_status_${escaper(
        task.status
      )}">${escaper(ucfirst(task.status))}</span>${
        setupIssue
          ? `<span class="aipkit_task_setup_badge" title="${escaper(
              setupIssue
            )}">Needs setup</span>`
          : ""
      }`;

      // Column 4: Schedule (combined Last Run + Next Run)
      const timingCell = row.insertCell();
      const lastRunText = task.last_run_time
        ? new Date(task.last_run_time.replace(" ", "T") + "Z").toLocaleString(undefined, { 
            month: 'short', 
            day: 'numeric', 
            hour: '2-digit', 
            minute: '2-digit' 
          })
        : texts.never_run || "Never";
      const nextRunText =
        task.next_run_time &&
        typeof window.aipkit_formatFriendlyFutureDate === "function"
          ? window.aipkit_formatFriendlyFutureDate(task.next_run_time)
          : task.next_run_time
          ? new Date(task.next_run_time.replace(" ", "T") + "Z").toLocaleString(undefined, { 
              month: 'short', 
              day: 'numeric', 
              hour: '2-digit', 
              minute: '2-digit' 
            })
          : texts.not_scheduled || "Not Scheduled";
      
      timingCell.innerHTML = `
        <div class="aipkit_task_timing aipkit_task_timing--tasks-inline">
          <div class="aipkit_task_timing_item aipkit_task_timing_last">
            <span class="aipkit_task_timing_label">${texts.last_label || "Last"}:</span>
            <span class="aipkit_task_timing_value${!task.last_run_time ? ' aipkit_never' : ''}">${escaper(lastRunText)}</span>
          </div>
          <div class="aipkit_task_timing_item aipkit_task_timing_next">
            <span class="aipkit_task_timing_label">${texts.next_label || "Next"}:</span>
            <span class="aipkit_task_timing_value">${escaper(nextRunText)}</span>
          </div>
        </div>
      `;

      // Column 5: Compact icon actions. Tooltips and accessible labels retain clarity.
      const actionsCell = row.insertCell();
      actionsCell.className = "aipkit_actions_cell";
      actionsCell.innerHTML = `
        <div class="aipkit-table-actions-group aipkit-table-actions-group--tasks">
          <button class="aipkit_btn aipkit_btn-small aipkit_btn-icon-only" data-action="edit" data-task-id="${task.id}" title="${texts.edit_button || "Edit"}" aria-label="${texts.edit_button || "Edit"}">
            <span class="dashicons dashicons-edit" aria-hidden="true"></span>
          </button>
          <button class="aipkit_btn aipkit_btn-small aipkit_btn-icon-only aipkit_btn-spinner-only" data-action="${task.status === "active" ? "pause" : "resume"}" data-task-id="${task.id}" title="${task.status === "active" ? texts.pause_button || "Pause" : texts.resume_button || "Resume"}" aria-label="${task.status === "active" ? texts.pause_button || "Pause" : texts.resume_button || "Resume"}">
            <span class="dashicons ${task.status === "active" ? "dashicons-controls-pause" : "dashicons-controls-play"}" aria-hidden="true"></span>
            <span class="aipkit_spinner" aria-hidden="true"></span>
          </button>
          <button class="aipkit_btn aipkit_btn-small aipkit_btn-icon-only aipkit_btn-spinner-only" data-action="run_now" data-task-id="${task.id}" ${task.status !== "active" || setupIssue ? "disabled" : ""} title="${setupIssue ? escaper(setupIssue) : task.status !== "active" ? texts.task_not_active_run_title || "Task must be active to run" : texts.run_now_button || "Run Now"}" aria-label="${setupIssue ? escaper(setupIssue) : texts.run_now_button || "Run Now"}">
            <span class="dashicons dashicons-controls-skipforward" aria-hidden="true"></span>
            <span class="aipkit_spinner" aria-hidden="true"></span>
          </button>
          <button class="aipkit_btn aipkit_btn-small aipkit_btn-icon-only" data-action="delete" data-task-id="${task.id}" title="${texts.delete_button || "Delete"}" aria-label="${texts.delete_button || "Delete"}">
            <span class="dashicons dashicons-trash" aria-hidden="true"></span>
          </button>
        </div>
      `;
    });
  }

  window.aipkit_list_renderTasksTable = aipkit_list_renderTasksTable;
})();
