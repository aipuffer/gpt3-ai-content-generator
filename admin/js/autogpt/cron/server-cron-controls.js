/**
 * Opt-in external server-cron setup and health controls.
 */
(function () {
  "use strict";

  const CHECK_INTERVAL_MS = 5000;
  const CHECK_TIMEOUT_MS = 75000;

  const wait = (duration) =>
    new Promise((resolve) => window.setTimeout(resolve, duration));

  const formatTimestamp = (timestamp) => {
    const normalized = Number(timestamp || 0);
    if (!normalized) return "";
    return new Date(normalized * 1000).toLocaleString(undefined, {
      month: "short",
      day: "numeric",
      hour: "2-digit",
      minute: "2-digit",
    });
  };

  const replaceResultTemplate = (template, tasks, items, failures) =>
    String(template || "")
      .replace("%1$d", String(tasks))
      .replace("%2$d", String(items))
      .replace("%3$d", String(failures));

  const setFeedback = (panel, message, state = "") => {
    const feedback = panel.querySelector("[data-aipkit-server-cron-feedback]");
    if (!feedback) return;
    feedback.textContent = message || "";
    if (state) {
      feedback.dataset.state = state;
    } else {
      delete feedback.dataset.state;
    }
  };

  const setButtonBusy = (button, busy) => {
    if (!button) return;
    if (busy) {
      button.dataset.aipkitOriginalText = button.textContent.trim();
      button.disabled = true;
      button.classList.add("is-busy");
      return;
    }
    button.disabled = false;
    button.classList.remove("is-busy");
    if (button.dataset.aipkitOriginalText) {
      button.textContent = button.dataset.aipkitOriginalText;
      delete button.dataset.aipkitOriginalText;
    }
  };

  const getBadgeLabel = (panel, status) => {
    if (status.healthy) return panel.dataset.aipkitLabelActive || "Active";
    if (status.awaiting_first_run) {
      return panel.dataset.aipkitLabelWaiting || "Waiting for first run";
    }
    if (status.delayed) return panel.dataset.aipkitLabelDelayed || "Delayed";
    return panel.dataset.aipkitLabelDisabled || "Disabled";
  };

  const getBadgeState = (status) => {
    if (status.healthy) return "active";
    if (status.awaiting_first_run) return "pending";
    if (status.delayed) return "delayed";
    return "disabled";
  };

  const updateRunnerNotice = (status) => {
    const notice = document.querySelector("[data-aipkit-cron-runner-notice]");
    if (!notice) return;

    if (status.healthy) {
      notice.hidden = true;
      return;
    }

    const state = status.enabled
      ? status.delayed
        ? "delayed"
        : "pending"
      : "disabled";
    const message = notice.querySelector("[data-aipkit-cron-notice-message]");
    const wpCronLink = notice.querySelector(
      "[data-aipkit-cron-notice-wp-link]"
    );
    const action = notice.querySelector("[data-aipkit-cron-notice-action]");
    const messageKey =
      state === "pending"
        ? "aipkitMessagePending"
        : state === "delayed"
          ? "aipkitMessageDelayed"
          : "aipkitMessageDisabled";
    const actionKey =
      state === "pending"
        ? "aipkitActionPending"
        : state === "delayed"
          ? "aipkitActionDelayed"
          : "aipkitActionDisabled";

    if (message) message.textContent = notice.dataset[messageKey] || "";
    if (wpCronLink) wpCronLink.hidden = state !== "disabled";
    if (action) action.textContent = notice.dataset[actionKey] || "";
    notice.hidden = false;
  };

  const updateCronCard = (container, panel, status) => {
    if (panel.dataset.aipkitServerCronWpDisabled !== "1") return;

    const menu = container.querySelector("#aipkit_autogpt_cron_info");
    const metric = menu?.querySelector(".aipkit_autogpt_cron_metric_status");
    const healthTitle = menu?.querySelector(
      ".aipkit_autogpt_cron_health_copy strong"
    );
    const healthCopy = menu?.querySelector(
      "[data-aipkit-cron-health-message]"
    );
    const healthWpCronLink = menu?.querySelector(
      "[data-aipkit-cron-health-wp-link]"
    );
    const healthIcon = menu?.querySelector(
      ".aipkit_autogpt_cron_health_icon .dashicons"
    );
    updateRunnerNotice(status);
    if (!menu || !metric) return;

    if (status.healthy) {
      menu.dataset.cronState = "server";
      metric.textContent = panel.dataset.aipkitLabelCardServer || "Server cron";
      if (healthTitle) healthTitle.textContent = metric.textContent;
      if (healthCopy) {
        healthCopy.textContent =
          panel.dataset.aipkitLabelHealthServer ||
          "The authenticated server scheduler is running.";
      }
      if (healthWpCronLink) healthWpCronLink.hidden = true;
      if (healthIcon) {
        healthIcon.classList.remove("dashicons-warning");
        healthIcon.classList.add("dashicons-yes-alt");
      }
      return;
    }

    if (status.enabled) {
      menu.dataset.cronState = status.delayed
        ? "server_delayed"
        : "server_pending";
      metric.textContent = status.delayed
        ? panel.dataset.aipkitLabelCardDelayed || "Server delayed"
        : panel.dataset.aipkitLabelCardPending || "Setup incomplete";
      if (healthTitle) healthTitle.textContent = metric.textContent;
      if (healthCopy) {
        healthCopy.textContent = status.delayed
          ? panel.dataset.aipkitLabelHealthDelayed ||
            "The server scheduler is delayed."
          : panel.dataset.aipkitLabelHealthPending ||
            "Server cron is enabled but hasn’t checked in.";
      }
      if (healthWpCronLink) healthWpCronLink.hidden = true;
      if (healthIcon) {
        healthIcon.classList.remove("dashicons-yes-alt");
        healthIcon.classList.add("dashicons-warning");
      }
      return;
    }

    menu.dataset.cronState = "disabled";
    metric.textContent = panel.dataset.aipkitLabelDisabled || "Disabled";
    if (healthTitle) healthTitle.textContent = metric.textContent;
    if (healthCopy) {
      healthCopy.textContent =
        panel.dataset.aipkitLabelHealthDisabled ||
        "Automated tasks won’t run until a cron method is enabled.";
    }
    if (healthWpCronLink) healthWpCronLink.hidden = false;
    if (healthIcon) {
      healthIcon.classList.remove("dashicons-yes-alt");
      healthIcon.classList.add("dashicons-warning");
    }
  };

  const applyStatus = (container, panel, status, revealCommand = false) => {
    const enabled = Boolean(status?.enabled);
    panel.dataset.aipkitServerCronLastSuccess = String(
      status?.last_success_at || 0
    );

    const details = panel.querySelector("[data-aipkit-server-cron-details]");
    const intro = panel.querySelector("[data-aipkit-server-cron-intro]");
    const enableButton = panel.querySelector("[data-aipkit-server-cron-enable]");
    const enabledButtons = panel.querySelectorAll(
      "[data-aipkit-server-cron-check], [data-aipkit-server-cron-rotate], [data-aipkit-server-cron-disable]"
    );
    const badge = panel.querySelector("[data-aipkit-server-cron-badge]");
    const hint = panel.querySelector("[data-aipkit-server-cron-secret-hint]");
    const lastRun = panel.querySelector(
      "[data-aipkit-server-cron-last-run-label]"
    );
    const resultLabel = panel.querySelector("[data-aipkit-server-cron-result]");
    const commandBox = panel.querySelector("[data-aipkit-server-cron-command]");
    const commandValue = panel.querySelector(
      "[data-aipkit-server-cron-command-value]"
    );
    const crontabValue = panel.querySelector(
      "[data-aipkit-server-cron-crontab-value]"
    );

    if (details) details.hidden = !enabled;
    if (intro) intro.hidden = enabled;
    if (enableButton) enableButton.hidden = enabled;
    enabledButtons.forEach((button) => {
      button.hidden = !enabled;
    });
    panel.dataset.aipkitServerCronState = getBadgeState(status || {});
    if (badge) badge.textContent = getBadgeLabel(panel, status || {});

    if (hint) {
      hint.textContent = enabled && status.secret_hint
        ? (panel.dataset.aipkitLabelSecretTemplate || "Ending in %s").replace(
            "%s",
            status.secret_hint
          )
        : "";
    }

    if (lastRun) {
      lastRun.textContent = status?.last_run_at
        ? formatTimestamp(status.last_run_at)
        : panel.dataset.aipkitLabelNever || "Never";
    }

    const lastResult = status?.last_result || {};
    if (resultLabel) {
      if (Object.keys(lastResult).length) {
        const failures =
          Number(lastResult.failed_tasks || 0) +
          Number(lastResult.failed_items || 0);
        resultLabel.textContent = replaceResultTemplate(
          panel.dataset.aipkitLabelResultTemplate ||
            "%1$d tasks triggered, %2$d items processed, %3$d failed",
          Number(lastResult.triggered_tasks || 0),
          Number(lastResult.processed_items || 0),
          failures
        );
      } else {
        resultLabel.textContent =
          panel.dataset.aipkitLabelNoResult || "No run recorded";
      }
    }

    if (revealCommand && status.command && commandBox && commandValue) {
      commandValue.value = status.command;
      if (crontabValue) crontabValue.value = status.crontab_command || "";
      commandBox.hidden = false;
      const cronMenu = container.querySelector("#aipkit_autogpt_cron_info");
      if (cronMenu && !cronMenu.open) cronMenu.open = true;
    } else if (!enabled && commandBox) {
      commandBox.hidden = true;
      if (commandValue) commandValue.value = "";
      if (crontabValue) crontabValue.value = "";
    }

    updateCronCard(container, panel, status || {});
    window.requestAnimationFrame(() => {
      window.dispatchEvent(new Event("resize"));
    });
  };

  const request = async (panel, operation) => {
    if (typeof window.aipkit_apiRequest !== "function") {
      throw new Error(
        panel.dataset.aipkitLabelError || "Server cron request failed."
      );
    }

    const nonce = panel.dataset.aipkitServerCronNonce || "";
    if (!nonce) {
      throw new Error(
        panel.dataset.aipkitLabelError || "Server cron request failed."
      );
    }

    return window.aipkit_apiRequest("aipkit_manage_automation_server_cron", {
      operation,
      _ajax_nonce: nonce,
    });
  };

  const copyCommand = async (panel, button) => {
    const targetSelector = button.dataset.aipkitCopyTarget || "";
    const textarea = targetSelector ? panel.querySelector(targetSelector) : null;
    if (!textarea?.value) return;

    let copied = false;
    try {
      await navigator.clipboard.writeText(textarea.value);
      copied = true;
    } catch {
      textarea.focus();
      textarea.select();
      copied = document.execCommand("copy");
    }

    if (!copied) return;
    const originalLabel = button.getAttribute("aria-label") || "";
    const icon = button.querySelector(".dashicons");
    button.classList.add("is-copied");
    button.setAttribute(
      "aria-label",
      panel.dataset.aipkitCopiedLabel || "Cron command copied"
    );
    icon?.classList.replace("dashicons-clipboard", "dashicons-yes-alt");
    window.setTimeout(() => {
      if (!document.body.contains(button)) return;
      button.classList.remove("is-copied");
      button.setAttribute("aria-label", originalLabel);
      icon?.classList.replace("dashicons-yes-alt", "dashicons-clipboard");
    }, 1500);
  };

  const confirmAction = (panel, message, callback, options = {}) => {
    if (typeof window.aipkit_showConfirmModal === "function") {
      window.aipkit_showConfirmModal(message, {
        title:
          options.title ||
          panel.dataset.aipkitLabelConfirmTitle ||
          "Server cron",
        confirmText:
          options.confirmText ||
          panel.dataset.aipkitLabelContinue ||
          "Continue",
        cancelText: panel.dataset.aipkitLabelCancel || "Cancel",
        variant: options.variant || "warning",
        onConfirm: callback,
      });
      return;
    }
    if (window.confirm(message)) callback();
  };

  const runMutation = async (
    container,
    panel,
    button,
    operation,
    successLabel
  ) => {
    setButtonBusy(button, true);
    setFeedback(panel, "", "");
    try {
      const status = await request(panel, operation);
      applyStatus(container, panel, status, true);
      setFeedback(panel, successLabel, "success");
    } catch (error) {
      setFeedback(
        panel,
        error?.message ||
          panel.dataset.aipkitLabelError ||
          "Server cron request failed.",
        "error"
      );
    } finally {
      setButtonBusy(button, false);
    }
  };

  const checkConnection = async (container, panel, button) => {
    const baseline = Number(panel.dataset.aipkitServerCronLastSuccess || 0);
    const deadline = Date.now() + CHECK_TIMEOUT_MS;
    setButtonBusy(button, true);
    setFeedback(
      panel,
      panel.dataset.aipkitCheckingLabel ||
        "Waiting for the next server request...",
      "waiting"
    );

    try {
      while (Date.now() < deadline && document.body.contains(panel)) {
        await wait(CHECK_INTERVAL_MS);
        const status = await request(panel, "status");
        applyStatus(container, panel, status);
        if (status.healthy && Number(status.last_success_at || 0) > baseline) {
          setFeedback(
            panel,
            panel.dataset.aipkitLabelConnectedFeedback ||
              "Server cron connection detected.",
            "success"
          );
          return;
        }
      }

      if (document.body.contains(panel)) {
        setFeedback(
          panel,
          panel.dataset.aipkitCheckTimeoutLabel ||
            "No new server request was detected. Check the scheduler command and try again.",
          "error"
        );
      }
    } catch (error) {
      setFeedback(
        panel,
        error?.message ||
          panel.dataset.aipkitLabelError ||
          "Server cron request failed.",
        "error"
      );
    } finally {
      if (document.body.contains(button)) setButtonBusy(button, false);
    }
  };

  function initServerCronControls(container) {
    if (!container || container.dataset.aipkitServerCronBound === "1") return;

    const panel = container.querySelector("[data-aipkit-server-cron-settings]");
    if (!panel) return;

    const enableButton = panel.querySelector("[data-aipkit-server-cron-enable]");
    const checkButton = panel.querySelector("[data-aipkit-server-cron-check]");
    const rotateButton = panel.querySelector("[data-aipkit-server-cron-rotate]");
    const disableButton = panel.querySelector("[data-aipkit-server-cron-disable]");
    const copyButtons = panel.querySelectorAll("[data-aipkit-copy-server-cron]");

    enableButton?.addEventListener("click", () =>
      runMutation(
        container,
        panel,
        enableButton,
        "enable",
        panel.dataset.aipkitLabelEnabledFeedback || "Server cron enabled."
      )
    );
    checkButton?.addEventListener("click", () =>
      checkConnection(container, panel, checkButton)
    );
    rotateButton?.addEventListener("click", () => {
      confirmAction(panel, panel.dataset.aipkitRotateConfirm || "Rotate secret?", () =>
        runMutation(
          container,
          panel,
          rotateButton,
          "rotate",
          panel.dataset.aipkitLabelRotatedFeedback || "Secret rotated."
        )
      );
    });
    disableButton?.addEventListener("click", () => {
      const disableServerCron = async () => {
        await runMutation(
          container,
          panel,
          disableButton,
          "disable",
          panel.dataset.aipkitLabelDisabledFeedback || "Server cron disabled."
        );
        const cronMenu = container.querySelector("#aipkit_autogpt_cron_info");
        if (cronMenu) cronMenu.open = true;
        window.requestAnimationFrame(() => {
          window.dispatchEvent(new Event("resize"));
        });
      };

      if (panel.dataset.aipkitServerCronWpDisabled === "1") {
        confirmAction(
          panel,
          panel.dataset.aipkitDisableConfirm ||
            "Automated tasks will stop running until another cron method is enabled.",
          disableServerCron,
          {
            title:
              panel.dataset.aipkitDisableConfirmTitle ||
              "Disable server cron?",
            confirmText:
              panel.dataset.aipkitLabelDisable || "Disable server cron",
          }
        );
        return;
      }

      disableServerCron();
    });
    copyButtons.forEach((copyButton) => {
      copyButton.addEventListener("click", () => copyCommand(panel, copyButton));
    });

    document.querySelectorAll("[data-aipkit-open-server-cron]").forEach((button) => {
      button.addEventListener("click", () => {
        const details = container.querySelector("#aipkit_autogpt_cron_info");
        const taskList = container.querySelector(
          "#aipkit_automated_task_list_wrapper"
        );
        const emptyWorkspaceFocus =
          container.dataset.workspaceState === "empty" &&
          taskList &&
          window.getComputedStyle(taskList).display === "none";

        if (emptyWorkspaceFocus) {
          taskList.style.display = "block";
          taskList.classList.add("aipkit_autogpt_server_cron_focus");
          const restoreEmptyWorkspace = () => {
            if (details?.open) return;
            details?.removeEventListener("toggle", restoreEmptyWorkspace);
            taskList.classList.remove("aipkit_autogpt_server_cron_focus");
            taskList.style.display = "none";
          };
          details?.addEventListener("toggle", restoreEmptyWorkspace);
        }

        if (details) details.open = true;
        window.requestAnimationFrame(() => {
          panel.scrollIntoView({ behavior: "smooth", block: "nearest" });
        });
      });
    });

    container.dataset.aipkitServerCronBound = "1";
    request(panel, "status")
      .then((status) => {
        if (document.body.contains(panel)) {
          applyStatus(container, panel, status);
        }
      })
      .catch(() => {
        // Keep the server-rendered status when a background refresh is unavailable.
      });
  }

  window.aipkit_initServerCronControls = initServerCronControls;
})();
