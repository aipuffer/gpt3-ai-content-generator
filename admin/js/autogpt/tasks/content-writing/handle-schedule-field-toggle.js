/**
 * AIPKit AutoGPT - Content Writing Task: Schedule Fields Toggle Handler
 */
(function () {
  "use strict";

  function getCurrentManualEntryView(formSection) {
    const scope = formSection || document;
    const taskEntryShell =
      scope.querySelector(
        "#aipkit_task_cw_input_mode_bulk .aipkit_cw_task_entry_shell"
      ) ||
      document.querySelector(
        "#aipkit_task_cw_input_mode_bulk .aipkit_cw_task_entry_shell"
      );

    return taskEntryShell?.dataset.taskEntryView || "batch";
  }

  function aipkit_toggleTaskCwScheduleFields(event) {
    const eventTarget = event.target;
    const formSection =
      eventTarget.closest(".aipkit_post_settings_redesigned") ||
      eventTarget.closest(".aipkit_wizard_content_step") ||
      document;
    const statusSelect =
      formSection.querySelector("#aipkit_task_cw_post_status") ||
      document.getElementById("aipkit_task_cw_post_status");

    const scheduleOptionsWrapper = formSection.querySelector(
      "#aipkit_task_cw_schedule_options_wrapper"
    );
    const smartScheduleFields = formSection.querySelector(
      "#aipkit_task_cw_smart_schedule_fields"
    );
    const fromInputOption = formSection.querySelector(
      ".aipkit_task_schedule_from_input_option"
    );
    const fromInputLabel = fromInputOption
      ? fromInputOption.querySelector(".aipkit_post_schedule_radio_text")
      : null;
    const fromInputTooltip = fromInputOption
      ? fromInputOption.querySelector(".aipkit_popover_warning[data-tooltip]")
      : null;

    if (!scheduleOptionsWrapper) return;

    const showFields = statusSelect && statusSelect.value === "publish";
    scheduleOptionsWrapper.hidden = !showFields;

    const taskTypeSelect = document.getElementById("aipkit_automated_task_type");
    const allowedFromInputTypes = new Set([
      "content_writing_bulk",
      "content_writing_csv",
      "content_writing_gsheets",
    ]);
    const tooltipSuffix =
      "Accepted formats: YYYY-MM-DD HH:MM[:SS], YYYY/MM/DD HH:MM, MM/DD/YYYY HH:MM, DD/MM/YYYY HH:MM, or ISO 8601.\n\nTimes use the site timezone unless an offset/Z is provided.";
    const fromInputCopyByType = {
      content_writing_csv: {
        label: "Input dates",
        tooltip:
          "Use when your CSV includes a publish date column.\n\n" +
          tooltipSuffix,
      },
      content_writing_gsheets: {
        label: "Input dates",
        tooltip:
          "Use when your Google Sheets includes a publish date column.\n\n" +
          tooltipSuffix,
      },
    };
    const defaultFromInputCopy = {
      label: "Input dates",
      tooltip:
        "Use when your input includes a publish date.\n\n" + tooltipSuffix,
    };
    const syncFromInputOptionState = () => {
      const isFromInputAllowed =
        taskTypeSelect && allowedFromInputTypes.has(taskTypeSelect.value);

      if (fromInputOption) {
        updateFromInputCopy();
        fromInputOption.hidden = !isFromInputAllowed;

        if (!isFromInputAllowed) {
          const fromInputRadio = fromInputOption.querySelector(
            'input[name="schedule_mode"][value="from_input"]'
          );
          if (fromInputRadio && fromInputRadio.checked) {
            const immediateRadio = formSection.querySelector(
              'input[name="schedule_mode"][value="immediate"]'
            );
            if (immediateRadio) {
              immediateRadio.checked = true;
              immediateRadio.dispatchEvent(
                new Event("change", { bubbles: true })
              );
            }
          }
        }
      }

      return isFromInputAllowed;
    };
    const updateFromInputCopy = () => {
      if (!taskTypeSelect) return;
      const taskType = taskTypeSelect.value;
      const manualEntryView = getCurrentManualEntryView(formSection);
      let copy = fromInputCopyByType[taskType] || defaultFromInputCopy;

      if (taskType === "content_writing_bulk") {
        copy =
          manualEntryView === "paste"
            ? {
                label: "Input dates",
                tooltip:
                  "Use when your pasted lines include a publish date at the end of each line.\n\n" +
                  tooltipSuffix,
              }
            : {
                label: "Input dates",
                tooltip:
                  "Use the Schedule field in Batch Editor.\n\n" +
                  tooltipSuffix,
          };
      }

      if (fromInputLabel) {
        fromInputLabel.textContent = copy.label;
      }
      if (fromInputTooltip) {
        fromInputTooltip.setAttribute("data-tooltip", copy.tooltip);
      }
    };
    syncFromInputOptionState();

    // Radio button handler
    if (taskTypeSelect && !taskTypeSelect.dataset.fromInputCopyListener) {
      taskTypeSelect.addEventListener("change", () => {
        syncFromInputOptionState();
      });
      taskTypeSelect.dataset.fromInputCopyListener = "true";
    }

    const radios = formSection.querySelectorAll('input[name="schedule_mode"]');
    radios.forEach((radio) => {
      if (!radio.dataset.listenerAttached) {
        radio.addEventListener("change", function () {
          if (smartScheduleFields) {
            smartScheduleFields.hidden = this.value !== "smart";
          }
        });
        radio.dataset.listenerAttached = "true";
      }
    });

    formSection
      .querySelectorAll("[data-aipkit-schedule-interval-step]")
      .forEach((button) => {
        if (button.dataset.scheduleIntervalStepListenerAttached) return;
        button.addEventListener("click", function () {
          const input = formSection.querySelector(
            "#aipkit_task_cw_smart_schedule_interval_value"
          );
          if (!input) return;

          const direction = Number(
            button.dataset.aipkitScheduleIntervalStep || 0
          );
          const current = Number(input.value || input.min || 1);
          const min = Number(input.min || 1);
          const max = input.max
            ? Number(input.max)
            : Number.POSITIVE_INFINITY;
          input.value = String(
            Math.min(max, Math.max(min, current + direction))
          );
          input.dispatchEvent(new Event("input", { bubbles: true }));
          input.dispatchEvent(new Event("change", { bubbles: true }));
        });
        button.dataset.scheduleIntervalStepListenerAttached = "true";
      });

    // Trigger initial state for radio buttons
    const checkedRadio = formSection.querySelector(
      'input[name="schedule_mode"]:checked'
    );
    if (checkedRadio && smartScheduleFields) {
      smartScheduleFields.hidden = checkedRadio.value !== "smart";
    }
  }
  window.aipkit_toggleTaskCwScheduleFields = aipkit_toggleTaskCwScheduleFields;
})();
