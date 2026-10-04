/**
 * AIPKit Content Assistant - bulk progress UI.
 */
(function () {
  "use strict";

  const fieldLabels = {
    keyword: "Focus keyword",
    title: "Title",
    excerpt: "Excerpt",
    content: "Content",
    meta: "Meta description",
    tags: "Tags",
    slug: "Optimize URL",
  };

  function truncateTitle(title, maxLength = 60) {
    const value = String(title || "");
    return value.length <= maxLength
      ? value
      : `${value.substring(0, maxLength - 1)}…`;
  }

  const createDashicon = (name, className = "") => {
    const icon = document.createElement("span");
    icon.className = `dashicons dashicons-${name}${className ? ` ${className}` : ""}`;
    icon.setAttribute("aria-hidden", "true");
    return icon;
  };

  function logToModal(message, type = "info") {
    const log = document.getElementById("aipkit-enhancer-bulk-status-log");
    if (!log) return;
    const entry = document.createElement("div");
    entry.className = `aipkit_enhancer_bulk_notice aipkit_enhancer_bulk_notice--${type}`;
    entry.textContent = String(message || "");
    log.appendChild(entry);
    log.scrollTop = log.scrollHeight;
  }

  function updateProgressBar() {
    const state = window.aipkit_enhancer_bulkState || {};
    const total = Number(state.total || 0);
    if (total < 1) return;

    const completed = Number(state.completed || 0);
    const failed = Number(state.failed || 0);
    const processed = Math.min(total, completed + failed);
    const percent = Math.round((processed / total) * 100);
    const current = Math.min(total, processed + 1);
    const progressBar = document.getElementById(
      "aipkit-enhancer-bulk-progress-bar"
    );
    const progressText = document.getElementById(
      "aipkit-enhancer-progress-text"
    );
    const progressStats = document.getElementById(
      "aipkit-enhancer-progress-stats"
    );
    const footerStatus = document.getElementById(
      "aipkit_enhancer_footer_status"
    );

    if (progressBar) progressBar.style.width = `${percent}%`;
    if (progressText) {
      progressText.textContent =
        state.stopReason
          ? "Processing stopped"
          : processed >= total
            ? "Update complete"
            : `Processing ${current} of ${total}…`;
    }
    if (progressStats) progressStats.textContent = `${percent}%`;
    if (footerStatus) {
      footerStatus.textContent = `${completed} completed · ${failed} failed · ${Math.max(0, total - processed)} pending`;
    }
  }

  const setPostExpanded = (postContainer, expanded) => {
    if (!postContainer) return;
    const summary = postContainer.querySelector(
      ".aipkit_enhancer_post_summary"
    );
    const steps = postContainer.querySelector(
      ".aipkit_enhancer_steps_container"
    );
    const chevron = postContainer.querySelector(
      ".aipkit_enhancer_post_chevron"
    );
    summary?.setAttribute("aria-expanded", expanded ? "true" : "false");
    if (steps) steps.hidden = !expanded;
    chevron?.classList.toggle("dashicons-arrow-up-alt2", expanded);
    chevron?.classList.toggle("dashicons-arrow-down-alt2", !expanded);
  };

  const setPostStatus = (postContainer, status, label) => {
    if (!postContainer) return;
    postContainer.dataset.status = status;
    const summary = postContainer.querySelector(
      ".aipkit_enhancer_post_summary"
    );
    const statusLabel = postContainer.querySelector(
      ".aipkit_enhancer_post_status"
    );
    const iconWrap = postContainer.querySelector(
      ".aipkit_enhancer_post_state_icon"
    );
    if (statusLabel) statusLabel.textContent = label;
    if (summary) {
      if (status === "processing") {
        summary.setAttribute("aria-disabled", "true");
      } else {
        summary.removeAttribute("aria-disabled");
      }
    }
    if (iconWrap) {
      iconWrap.replaceChildren(
        createDashicon(
          status === "completed"
            ? "yes-alt"
            : status === "error"
              ? "warning"
              : "update",
          status === "processing" ? "aipkit_enhancer_spin" : ""
        )
      );
    }
  };

  function initStepProgress(postId) {
    const log = document.getElementById("aipkit-enhancer-bulk-status-log");
    if (!log) return;

    log
      .querySelectorAll(".aipkit_enhancer_post_steps")
      .forEach((item) => {
        if (item.dataset.status !== "processing") {
          setPostExpanded(item, false);
        }
      });

    const postTitle = truncateTitle(
      window.aipkit_enhancer_postTitles?.[postId] || `Post #${postId}`
    );
    const postContainer = document.createElement("section");
    postContainer.className = "aipkit_enhancer_post_steps";
    postContainer.id = `aipkit_enhancer_post_${postId}_steps`;
    postContainer.dataset.status = "processing";

    const summary = document.createElement("button");
    summary.type = "button";
    summary.className = "aipkit_enhancer_post_summary";
    summary.setAttribute("aria-expanded", "true");
    summary.setAttribute("aria-disabled", "true");

    const stateIcon = document.createElement("span");
    stateIcon.className = "aipkit_enhancer_post_state_icon";
    stateIcon.appendChild(
      createDashicon("update", "aipkit_enhancer_spin")
    );
    const title = document.createElement("strong");
    title.className = "aipkit_enhancer_post_title";
    title.textContent = postTitle;
    const status = document.createElement("span");
    status.className = "aipkit_enhancer_post_status";
    status.textContent = "Processing";
    const chevron = createDashicon(
      "arrow-up-alt2",
      "aipkit_enhancer_post_chevron"
    );
    summary.append(stateIcon, title, status, chevron);

    const stepsContainer = document.createElement("div");
    stepsContainer.className = "aipkit_enhancer_steps_container";
    stepsContainer.id = `aipkit_enhancer_steps_${postId}`;

    const config =
      window.aipkit_enhancer_bulkState?.enhancementsConfig || {};
    const selectedFields = [
      "keyword",
      "title",
      "excerpt",
      "content",
      "meta",
      "tags",
    ].filter((field) => config[field]?.prompt);
    if (config.generate_seo_slug === "1") {
      selectedFields.push("slug");
    }

    selectedFields.forEach((field) => {
      const step = document.createElement("div");
      step.className = "aipkit_enhancer_step";
      step.id = `aipkit_enhancer_step_${postId}_${field}`;
      step.dataset.status = "pending";
      const icon = document.createElement("span");
      icon.className = "aipkit_enhancer_step_icon";
      icon.appendChild(createDashicon("clock"));
      const name = document.createElement("span");
      name.className = "aipkit_enhancer_step_name";
      name.textContent = fieldLabels[field] || field;
      const stepStatus = document.createElement("span");
      stepStatus.className = "aipkit_enhancer_step_status";
      stepStatus.textContent = "Pending";
      step.append(icon, name, stepStatus);
      stepsContainer.appendChild(step);
    });

    summary.addEventListener("click", () => {
      if (postContainer.dataset.status === "processing") {
        setPostExpanded(postContainer, true);
        return;
      }

      const shouldExpand = summary.getAttribute("aria-expanded") !== "true";
      if (shouldExpand) {
        log
          .querySelectorAll(".aipkit_enhancer_post_steps")
          .forEach((item) => {
            if (
              item !== postContainer &&
              item.dataset.status !== "processing"
            ) {
              setPostExpanded(item, false);
            }
          });
      }
      setPostExpanded(postContainer, shouldExpand);
    });
    postContainer.append(summary, stepsContainer);
    log.appendChild(postContainer);
    log.scrollTop = log.scrollHeight;
  }

  function updateFieldStatus(postId, field, status, message = "", generatedValue = null) {
    const step = document.getElementById(
      `aipkit_enhancer_step_${postId}_${field}`
    );
    if (!step) return;

    const config = {
      pending: { icon: "clock", label: "Pending" },
      processing: { icon: "update", label: "Processing…" },
      completed: { icon: "yes-alt", label: "Completed" },
      error: { icon: "warning", label: "Failed" },
    }[status] || { icon: "clock", label: "Pending" };

    step.dataset.status = status;
    const iconWrap = step.querySelector(".aipkit_enhancer_step_icon");
    iconWrap?.replaceChildren(
      createDashicon(
        config.icon,
        status === "processing" ? "aipkit_enhancer_spin" : ""
      )
    );
    const statusLabel = step.querySelector(".aipkit_enhancer_step_status");
    if (statusLabel) statusLabel.textContent = config.label;
    step.title = message ? String(message) : "";
    let errorDetail = step.querySelector(".aipkit_enhancer_step_error");
    if (status === "error" && message) {
      if (!errorDetail) {
        errorDetail = document.createElement("span");
        errorDetail.className = "aipkit_enhancer_step_error";
        step.appendChild(errorDetail);
      }
      errorDetail.textContent = String(message);
    } else {
      errorDetail?.remove();
    }
    step.querySelector(".aipkit_enhancer_generated_value")?.remove();
    if (status === "error" && typeof generatedValue === "string" && generatedValue !== "") {
      const recovery = document.createElement("details");
      recovery.className = "aipkit_enhancer_generated_value";
      const summary = document.createElement("summary");
      summary.textContent = "Generated text";
      const text = document.createElement("textarea");
      text.className = "aipkit_form-input";
      text.rows = 6;
      text.readOnly = true;
      text.setAttribute("aria-label", "Generated text");
      text.value = generatedValue;
      recovery.appendChild(summary);
      recovery.appendChild(text);
      step.appendChild(recovery);
    }
  }

  function showPostCompletion(postId, processedFields, failedFields) {
    const postContainer = document.getElementById(
      `aipkit_enhancer_post_${postId}_steps`
    );
    if (!postContainer) return;
    if (failedFields.length) {
      setPostStatus(
        postContainer,
        "error",
        processedFields.length
          ? `${failedFields.length} failed`
          : "Failed"
      );
    } else {
      setPostStatus(postContainer, "completed", "Completed");
    }
    setPostExpanded(postContainer, failedFields.length > 0);
  }

  function displayStepResults(postId, steps) {
    if (!Array.isArray(steps)) return;
    steps.forEach((step) => {
      updateFieldStatus(
        postId,
        step.field,
        step.status,
        step.message || ""
      );
    });
    const completed = steps.filter(
      (step) => step.status === "completed"
    ).map((step) => step.field);
    const failed = steps.filter(
      (step) => step.status === "error"
    ).map((step) => step.field);
    showPostCompletion(postId, completed, failed);
  }

  function showStepError(postId, errorMessage) {
    const postContainer = document.getElementById(
      `aipkit_enhancer_post_${postId}_steps`
    );
    if (!postContainer) return;
    postContainer
      .querySelectorAll(".aipkit_enhancer_step:not([data-status='completed'])")
      .forEach((step) => {
        const field = String(step.id || "").split("_").pop();
        updateFieldStatus(postId, field, "error", errorMessage);
      });
    setPostStatus(postContainer, "error", "Failed");
    setPostExpanded(postContainer, true);
  }

  window.aipkit_enhancer_logToModal = logToModal;
  window.aipkit_enhancer_updateProgressBar = updateProgressBar;
  window.aipkit_enhancer_initStepProgress = initStepProgress;
  window.aipkit_enhancer_updateFieldStatus = updateFieldStatus;
  window.aipkit_enhancer_showPostCompletion = showPostCompletion;
  window.aipkit_enhancer_displayStepResults = displayStepResults;
  window.aipkit_enhancer_showStepError = showStepError;
})();
