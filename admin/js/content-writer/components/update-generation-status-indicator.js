/**
 * AIPKit Content Writer - Generation progress checklist.
 * Keeps the full, applicable pipeline visible so a single run feels bounded.
 */
(function () {
  "use strict";

  const __ = window.wp?.i18n?.__ || ((text) => text);

  const escaper =
    window.aipkit_escapeHtml ||
    function (str) {
      return str;
    };

  const stepMeta = {
    title: { label: __("Title", "gpt3-ai-content-generator"), order: 10 },
    content: { label: __("Article", "gpt3-ai-content-generator"), order: 20 },
    keyword: { label: __("Focus keyword", "gpt3-ai-content-generator"), order: 30 },
    image: { label: __("Images", "gpt3-ai-content-generator"), order: 40 },
    excerpt: { label: __("Excerpt", "gpt3-ai-content-generator"), order: 50 },
    tags: { label: __("Tags", "gpt3-ai-content-generator"), order: 60 },
    meta: { label: __("Meta description", "gpt3-ai-content-generator"), order: 70 },
    seo: { label: __("SEO audit", "gpt3-ai-content-generator"), order: 80 },
  };

  let statusEl = null;

  function getStatusWorkflowRefs() {
    const wrapper = document.querySelector(
      ".aipkit_cw_generation_status_indicators"
    );
    const workflow = document.getElementById("aipkit_cw_progress_workflow");
    return wrapper && workflow ? { wrapper, workflow } : null;
  }

  function syncWorkflowVisibility() {
    const refs = getStatusWorkflowRefs();
    if (!refs) return;
    refs.wrapper.hidden = refs.workflow.children.length === 0;
  }

  function buildStepRow(step) {
    const meta = stepMeta[step] || {
      label: __("Step", "gpt3-ai-content-generator"),
      order: 999,
    };
    const row = document.createElement("div");
    row.className = "aipkit_cw_status_step status-pending";
    row.dataset.step = step;
    row.dataset.stepOrder = String(meta.order);
    row.innerHTML = `
      <span class="aipkit_cw_status_marker" aria-hidden="true">
        <span class="aipkit_cw_status_marker_icon"></span>
      </span>
      <span class="aipkit_cw_status_step_label">${escaper(meta.label)}</span>
      <span class="aipkit_cw_status_step_detail"></span>
    `;
    return row;
  }

  function insertStepRow(workflow, row) {
    const targetOrder = Number(row.dataset.stepOrder || 999);
    const nextSibling = Array.from(workflow.children).find(
      (child) => Number(child.dataset.stepOrder || 999) > targetOrder
    );
    workflow.insertBefore(row, nextSibling || null);
  }

  function ensureStepRow(step) {
    const refs = getStatusWorkflowRefs();
    if (!refs) return null;

    let row = refs.workflow.querySelector(`[data-step="${step}"]`);
    if (!row) {
      row = buildStepRow(step);
      insertStepRow(refs.workflow, row);
    }
    refs.wrapper.hidden = false;
    return row;
  }

  function setStepRowState(row, status, message = "") {
    row.classList.remove(
      "status-pending",
      "status-generating",
      "status-success",
      "status-error",
      "status-warning",
      "status-skipped"
    );
    row.classList.add(`status-${status}`);

    const detail = row.querySelector(".aipkit_cw_status_step_detail");
    if (!detail) return;

    const defaultDetails = {
      pending: "",
      generating: __("Working…", "gpt3-ai-content-generator"),
      success: "",
      skipped: __("Skipped", "gpt3-ai-content-generator"),
      warning: __("Needs attention", "gpt3-ai-content-generator"),
      error: __("Failed", "gpt3-ai-content-generator"),
    };
    detail.textContent = String(message || defaultDetails[status] || "");
  }

  function fieldIsEnabled(id) {
    const field = document.getElementById(id);
    if (!field) return false;
    if (field.type === "checkbox") return field.checked;
    return String(field.value || "") === "1";
  }

  function namedFieldIsEnabled(name) {
    const field = document.querySelector(`[name="${name}"]`);
    if (!field) return false;
    if (field.type === "checkbox") return field.checked;
    return String(field.value || "") === "1";
  }

  function initializeGenerationChecklist(options = {}) {
    clearGenerationStatusIndicators();

    const titleEnabled =
      typeof options.title === "boolean"
        ? options.title
        : Boolean(
            document
              .getElementById("aipkit_cw_custom_title_prompt")
              ?.value.trim()
          );
    const imagesEnabled =
      fieldIsEnabled("aipkit_cw_generate_images_enabled") ||
      fieldIsEnabled("aipkit_cw_generate_featured_image");
    const enabledSteps = [
      ...(titleEnabled ? ["title"] : []),
      "content",
      ...(fieldIsEnabled("aipkit_cw_generate_focus_keyword")
        ? ["keyword"]
        : []),
      ...(imagesEnabled ? ["image"] : []),
      ...(fieldIsEnabled("aipkit_cw_generate_excerpt") ? ["excerpt"] : []),
      ...(fieldIsEnabled("aipkit_cw_generate_tags") ? ["tags"] : []),
      ...(fieldIsEnabled("aipkit_cw_generate_meta_desc") ? ["meta"] : []),
      ...(fieldIsEnabled("aipkit_cw_smart_seo_enabled") ||
      fieldIsEnabled("aipkit_cw_seo_score_improvement_enabled") ||
      namedFieldIsEnabled("seo_score_improvement_enabled")
        ? ["seo"]
        : []),
    ];

    enabledSteps.forEach((step) => {
      const row = ensureStepRow(step);
      if (row) setStepRowState(row, "pending");
    });
    syncWorkflowVisibility();
  }

  function clearGenerationStatusIndicators() {
    const refs = getStatusWorkflowRefs();
    if (refs) {
      refs.workflow.innerHTML = "";
      refs.wrapper.hidden = true;
    }

    if (!statusEl) {
      statusEl = document.getElementById("aipkit_content_writer_form_status");
    }
    if (statusEl) {
      statusEl.textContent = "";
      statusEl.className = "aipkit_cw_status_badge";
    }
  }

  function updateGenerationStepDetail(step, detail) {
    const row = getStatusWorkflowRefs()?.workflow?.querySelector(
      `[data-step="${step}"]`
    );
    const detailElement = row?.querySelector(
      ".aipkit_cw_status_step_detail"
    );
    if (detailElement) {
      detailElement.textContent = String(detail || "");
    }
  }

  function updateGenerationStatusIndicator(step, status, message = "") {
    const refs = getStatusWorkflowRefs();
    const existingRow = refs?.workflow?.querySelector(`[data-step="${step}"]`);

    // Optional steps that were not selected should stay out of the checklist.
    if (status === "skipped" && !existingRow && !message) {
      return;
    }

    const row = existingRow || ensureStepRow(step);
    if (row) {
      if (step === "content" && status === "success" && !message) {
        const wordCount = document.getElementById(
          "aipkit_cw_generated_content_area"
        )?.dataset.aipkitWordCount;
        message = String(wordCount || "").trim();
      }
      setStepRowState(row, status, message);
    }
    if (
      typeof window.aipkit_settleContentWriterOutputSkeletonStep ===
      "function"
    ) {
      window.aipkit_settleContentWriterOutputSkeletonStep(step, status);
    }
    syncWorkflowVisibility();
  }

  window.aipkit_initializeCwGenerationChecklist =
    initializeGenerationChecklist;
  window.aipkit_updateCwGenerationStatus =
    updateGenerationStatusIndicator;
  window.aipkit_updateCwGenerationStepDetail =
    updateGenerationStepDetail;
  window.aipkit_clearCwGenerationStatusIndicators =
    clearGenerationStatusIndicators;
})();
