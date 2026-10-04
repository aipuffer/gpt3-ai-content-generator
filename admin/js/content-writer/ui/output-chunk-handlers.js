/**
 * AIPKit Content Writer - Output Chunk Handlers
 * Manages the SEO/Metadata section visibility and character count.
 */
(function () {
  "use strict";
  let rawContentHtml = "";
  const streamFollowState = {
    following: true,
    programmaticScroll: false,
    bound: false,
    frameId: 0,
  };
  const metaGroupPanels = {
    seo: {
      panelId: "aipkit_cw_meta_seo_panel",
    },
    details: {
      panelId: "aipkit_cw_generated_details_panel",
    },
  };
  const terminalOutputStates = new Set([
    "success",
    "error",
    "warning",
    "skipped",
  ]);
  function getCanvasRefs() {
    const canvas = document.getElementById("aipkit_content_writer_output_display");
    const contentArea = document.getElementById(
      "aipkit_cw_generated_content_area"
    );

    if (!canvas) {
      return null;
    }

    return {
      canvas,
      contentArea,
    };
  }

  function getJumpToLatestButton() {
    return document.getElementById("aipkit_cw_jump_to_latest");
  }

  function syncJumpToLatestVisibility() {
    const refs = getCanvasRefs();
    const button = getJumpToLatestButton();
    if (!refs || !button) return;

    const isStreaming = Boolean(
      refs.contentArea?.classList.contains("is-streaming")
    );
    button.hidden = !isStreaming || streamFollowState.following;
  }

  function setStreamFollowing(following) {
    streamFollowState.following = Boolean(following);
    syncJumpToLatestVisibility();
  }

  function resetStreamFollow() {
    if (streamFollowState.frameId) {
      window.cancelAnimationFrame(streamFollowState.frameId);
      streamFollowState.frameId = 0;
    }
    streamFollowState.programmaticScroll = false;
    setStreamFollowing(true);
  }

  function finishStreamFollow() {
    streamFollowState.programmaticScroll = false;
    setStreamFollowing(true);
  }

  function followStreamToLatest() {
    const refs = getCanvasRefs();
    if (!refs || !streamFollowState.following) return;

    if (streamFollowState.frameId) {
      window.cancelAnimationFrame(streamFollowState.frameId);
    }
    streamFollowState.frameId = window.requestAnimationFrame(() => {
      streamFollowState.frameId = 0;
      streamFollowState.programmaticScroll = true;
      refs.canvas.scrollTop = refs.canvas.scrollHeight;
      window.requestAnimationFrame(() => {
        streamFollowState.programmaticScroll = false;
      });
    });
  }

  function initStreamFollow() {
    const refs = getCanvasRefs();
    const button = getJumpToLatestButton();
    if (!refs || !button || streamFollowState.bound) return;

    const pauseFollowing = () => {
      if (refs.contentArea?.classList.contains("is-streaming")) {
        setStreamFollowing(false);
      }
    };

    refs.canvas.addEventListener("wheel", pauseFollowing, { passive: true });
    refs.canvas.addEventListener("touchstart", pauseFollowing, {
      passive: true,
    });
    refs.canvas.addEventListener(
      "scroll",
      () => {
        if (streamFollowState.programmaticScroll) return;
        const distanceFromBottom =
          refs.canvas.scrollHeight -
          refs.canvas.scrollTop -
          refs.canvas.clientHeight;
        setStreamFollowing(distanceFromBottom <= 48);
      },
      { passive: true }
    );
    button.addEventListener("click", () => {
      setStreamFollowing(true);
      followStreamToLatest();
    });

    streamFollowState.bound = true;
    syncJumpToLatestVisibility();
  }

  function getSessionCardRefs() {
    const container = document.getElementById("aipkit_content_writer_container");
    const card = document.getElementById("aipkit_cw_status_display_container");
    const title = document.getElementById("aipkit_cw_session_card_title");
    const hint = document.getElementById("aipkit_cw_session_card_hint");
    const workflow = card?.querySelector(".aipkit_cw_generation_status_indicators");
    const outputActions = document.querySelector(
      ".aipkit_content_writer_output_actions"
    );

    if (!container || !card || !title || !workflow || !outputActions) {
      return null;
    }

    return {
      container,
      card,
      title,
      hint,
      workflow,
      outputActions,
    };
  }

  function syncSessionCardState() {
    const refs = getSessionCardRefs();
    if (!refs) {
      return;
    }

    const isRunning = refs.container.classList.contains(
      "aipkit_cw_single-run-active"
    );
    const actionsVisible =
      window.getComputedStyle(refs.outputActions).display !== "none";
    const mode = actionsVisible && !isRunning ? "actions" : "progress";

    refs.card.dataset.aipkitCardMode = mode;

    if (mode === "actions") {
      const canvas = getCanvasRefs()?.canvas;
      const state = canvas?.dataset.canvasState;
      refs.title.textContent = state === "error"
        ? "Generation failed"
        : state === "stopped"
        ? "Generation stopped"
        : refs.card.dataset.actionsTitle || "Actions";
      if (refs.hint) {
        refs.hint.textContent = state === "error" || state === "stopped"
          ? canvas.dataset.canvasMessage || ""
          : refs.card.dataset.actionsHint || "";
      }
      return;
    }

    refs.title.textContent =
      refs.card.dataset.progressTitle || "Progress";
    if (refs.hint) {
      refs.hint.textContent = refs.card.dataset.progressHint || "";
    }
  }

  function observeSessionCardState() {
    const refs = getSessionCardRefs();
    if (!refs || refs.card.dataset.aipkitSessionCardBound === "true") {
      return;
    }

    let frameRequested = false;
    const scheduleSync = () => {
      if (frameRequested) {
        return;
      }
      frameRequested = true;
      window.requestAnimationFrame(() => {
        frameRequested = false;
        syncSessionCardState();
      });
    };

    const observer = new MutationObserver(() => {
      scheduleSync();
    });

    observer.observe(refs.outputActions, {
      attributes: true,
      attributeFilter: ["style", "class", "hidden"],
    });
    observer.observe(refs.container, {
      attributes: true,
      attributeFilter: ["class"],
    });

    refs.card.dataset.aipkitSessionCardBound = "true";
    scheduleSync();
  }

  function elementHasVisibleContent(element) {
    if (!element) {
      return false;
    }

    const text = String(element.textContent || "").replace(/\s+/g, " ").trim();
    if (text.length > 0) {
      return true;
    }

    return Boolean(
      element.querySelector(
        "img, h1, h2, h3, h4, h5, h6, p, ul, ol, li, blockquote, pre, table, figure"
      )
    );
  }

  function elementIsDisplayed(element) {
    return Boolean(
      element &&
        !element.hidden &&
        element.getClientRects().length > 0 &&
        window.getComputedStyle(element).display !== "none"
    );
  }

  function hasMeaningfulCanvasContent() {
    const refs = getCanvasRefs();
    if (!refs) {
      return false;
    }

    return (
      elementHasVisibleContent(refs.contentArea) || elementIsDisplayed(refs.imagePreview)
    );
  }

  function setCanvasState(state, options = {}) {
    const refs = getCanvasRefs();
    if (!refs) {
      return;
    }

    const normalizedState = String(state || "").trim() || "empty";
    const hasContent =
      typeof options.hasContent === "boolean"
        ? options.hasContent
        : hasMeaningfulCanvasContent();

    refs.canvas.dataset.canvasState = normalizedState;
    refs.canvas.dataset.canvasHasContent = hasContent ? "true" : "false";
    if (typeof options.description === "string") {
      refs.canvas.dataset.canvasMessage = options.description;
    } else if (normalizedState !== "error" && normalizedState !== "stopped") {
      delete refs.canvas.dataset.canvasMessage;
    }
    syncSessionCardState();
  }

  function resetCanvasState() {
    setCanvasState("ready", { hasContent: false });
  }

  function getCurrentCanvasState() {
    const refs = getCanvasRefs();
    return refs?.canvas?.dataset?.canvasState || "empty";
  }

  function setRawContentHtml(html) {
    rawContentHtml = typeof html === "string" ? html : "";
  }

  function getRawContentHtml() {
    return rawContentHtml || "";
  }

  function resetRawContentHtml() {
    rawContentHtml = "";
  }

  function queueMetaChunkReveal(metaChunk) {
    if (!metaChunk || metaChunk.dataset.aipkitRevealQueued === "true") return;
    if (metaChunk.classList.contains("is-visible")) return;
    metaChunk.dataset.aipkitRevealQueued = "true";
    window.requestAnimationFrame(() => {
      if (window.getComputedStyle(metaChunk).display === "none") {
        delete metaChunk.dataset.aipkitRevealQueued;
        return;
      }

      // Let the browser paint the card's initial opacity/offset before moving
      // it to the visible state. Without the second frame, display and
      // is-visible can be applied in one paint and the card appears abruptly.
      window.requestAnimationFrame(() => {
        delete metaChunk.dataset.aipkitRevealQueued;
        if (window.getComputedStyle(metaChunk).display === "none") {
          return;
        }
        metaChunk.classList.add("is-visible");
      });
    });
  }

  function syncMetaFieldRevealState(field) {
    if (!field) return;
    const isDisplayed = window.getComputedStyle(field).display !== "none";
    if (!isDisplayed) {
      field.classList.remove("is-visible");
      return;
    }
    window.requestAnimationFrame(() => {
      if (window.getComputedStyle(field).display !== "none") {
        field.classList.add("is-visible");
      }
    });
  }

  function isManagedMetaFieldVisible(field) {
    if (!field || field.hidden) {
      return false;
    }
    return window.getComputedStyle(field).display !== "none";
  }

  function getManagedMetaFields(metaChunk) {
    const fields = new Set();
    if (metaChunk) {
      metaChunk
        .querySelectorAll(
          ".aipkit_cw_meta_field, .aipkit_cw_seo_audit_summary, .aipkit_cw_smart_seo_locked_card, .aipkit_cw_smart_seo_run_card"
        )
        .forEach((field) => fields.add(field));
    }
    document
      .querySelectorAll(
        "#aipkit_cw_meta_seo_panel .aipkit_cw_seo_audit_summary, #aipkit_cw_meta_seo_panel .aipkit_cw_smart_seo_locked_card, #aipkit_cw_meta_seo_panel .aipkit_cw_smart_seo_run_card"
      )
      .forEach((field) => fields.add(field));
    return Array.from(fields);
  }

  function updateMetaGroupVisibility(metaChunk) {
    const groupVisibility = {};

    Object.keys(metaGroupPanels).forEach((groupKey) => {
      groupVisibility[groupKey] = 0;
    });

    getManagedMetaFields(metaChunk).forEach((field) => {
      if (!isManagedMetaFieldVisible(field)) {
        return;
      }
      const groupKey = field.getAttribute("data-aipkit-meta-group");
      if (!groupKey || !(groupKey in groupVisibility)) {
        return;
      }
      groupVisibility[groupKey] += 1;
    });

    Object.entries(metaGroupPanels).forEach(([groupKey, config]) => {
      const panel = document.getElementById(config.panelId);
      const visibleCount = groupVisibility[groupKey] || 0;

      if (!panel) {
        return;
      }

      if (visibleCount > 0) {
        panel.hidden = false;
        panel.style.display = "flex";
        queueMetaChunkReveal(panel);
        return;
      }

      panel.hidden = true;
      panel.style.display = "none";
      panel.classList.remove("is-visible");
    });

    return Array.from(
      metaChunk.querySelectorAll(
        ".aipkit_cw_meta_field, .aipkit_cw_seo_audit_summary, .aipkit_cw_smart_seo_locked_card, .aipkit_cw_smart_seo_run_card"
      )
    ).some(isManagedMetaFieldVisible);
  }

  function syncVisibleMetaFieldSequence(metaChunk) {
    if (!metaChunk) {
      return;
    }

    metaChunk
      .querySelectorAll(".aipkit_cw_meta_group_fields")
      .forEach((groupFields) => {
        const fields = Array.from(
          groupFields.querySelectorAll(".aipkit_cw_meta_field")
        );
        let visibleIndex = 0;

        fields.forEach((field) => {
          const isVisible =
            window.getComputedStyle(field).display !== "none";

          field.classList.remove(
            "is-first-visible",
            "has-visible-predecessor"
          );

          if (!isVisible) {
            return;
          }

          if (visibleIndex === 0) {
            field.classList.add("is-first-visible");
          } else {
            field.classList.add("has-visible-predecessor");
          }

          visibleIndex += 1;
        });
      });
  }

  function resizeMetaTextarea(textarea) {
    if (!(textarea instanceof HTMLTextAreaElement)) {
      return false;
    }

    const isVisible =
      textarea.getClientRects().length > 0 &&
      window.getComputedStyle(textarea).display !== "none";

    if (!isVisible) {
      textarea.style.height = "";
      return false;
    }

    textarea.style.height = "auto";
    textarea.style.height = `${textarea.scrollHeight}px`;
    return true;
  }

  function queueMetaTextareaResize(textarea) {
    if (!(textarea instanceof HTMLTextAreaElement)) {
      return;
    }

    window.requestAnimationFrame(() => {
      if (resizeMetaTextarea(textarea)) {
        return;
      }

      window.requestAnimationFrame(() => {
        resizeMetaTextarea(textarea);
      });
    });
  }

  function initMetaTextareaAutoGrow(textarea) {
    if (!(textarea instanceof HTMLTextAreaElement)) {
      return;
    }

    if (textarea.dataset.aipkitAutoGrowBound !== "true") {
      textarea.addEventListener("input", () => {
        resizeMetaTextarea(textarea);
      });
      textarea.dataset.aipkitAutoGrowBound = "true";
    }

    queueMetaTextareaResize(textarea);
  }

  function initMetaTextareasAutoGrow() {
    [
      document.getElementById("aipkit_cw_generated_excerpt"),
      document.getElementById("aipkit_cw_generated_meta_desc"),
    ].forEach((textarea) => {
      initMetaTextareaAutoGrow(textarea);
    });
  }

  function parseTagsValue(value) {
    if (typeof value !== "string") {
      return [];
    }

    const seen = new Set();
    return value
      .split(",")
      .map((tag) => tag.trim())
      .filter((tag) => {
        if (!tag) {
          return false;
        }
        const normalized = tag.toLowerCase();
        if (seen.has(normalized)) {
          return false;
        }
        seen.add(normalized);
        return true;
      });
  }

  function getTagsEditorRefs() {
    const textarea = document.getElementById("aipkit_cw_generated_tags");
    const chipList = document.getElementById("aipkit_cw_tags_chip_list");
    const chipInput = document.getElementById("aipkit_cw_tags_chip_input");
    const editor = document.querySelector("[data-aipkit-cw-tags-editor]");

    if (!textarea || !chipList || !chipInput || !editor) {
      return null;
    }

    return { textarea, chipList, chipInput, editor };
  }

  function triggerContentWriterAutosave(triggerElement = null) {
    if (typeof window.aipkit_handleContentWriterAutoSave === "function") {
      window.aipkit_handleContentWriterAutoSave(triggerElement);
    }
  }

  function setTagsTextareaValue(textarea, tags, triggerAutosave) {
    if (!textarea) {
      return;
    }

    const normalizedTags = Array.isArray(tags) ? tags : [];
    textarea.value = normalizedTags.join(", ");
    if (triggerAutosave) {
      const refs = getTagsEditorRefs();
      triggerContentWriterAutosave(refs?.editor || textarea);
    }
  }

  function renderTagsChips(chipList, tags) {
    if (!chipList) {
      return;
    }

    chipList.innerHTML = "";

    tags.forEach((tag) => {
      const chip = document.createElement("span");
      chip.className = "aipkit_cw_tag_chip";
      chip.setAttribute("data-aipkit-tag-value", tag);

      const chipLabel = document.createElement("span");
      chipLabel.className = "aipkit_cw_tag_chip_label";
      chipLabel.textContent = tag;

      const removeButton = document.createElement("button");
      removeButton.type = "button";
      removeButton.className = "aipkit_cw_tag_chip_remove";
      removeButton.setAttribute("data-aipkit-remove-tag", tag);
      removeButton.setAttribute("aria-label", `Remove tag ${tag}`);
      removeButton.textContent = "×";

      chip.appendChild(chipLabel);
      chip.appendChild(removeButton);
      chipList.appendChild(chip);
    });
  }

  function syncTagsEditorFromTextarea(clearDraft = true) {
    const refs = getTagsEditorRefs();
    if (!refs) {
      return;
    }

    const tags = parseTagsValue(refs.textarea.value);
    renderTagsChips(refs.chipList, tags);
    refs.editor.classList.toggle("is-empty", tags.length === 0);

    if (clearDraft) {
      refs.chipInput.value = "";
    }
  }

  function commitTags(tags, options = {}) {
    const { triggerAutosave = false, clearDraft = true } = options;
    const refs = getTagsEditorRefs();
    if (!refs) {
      return;
    }

    const normalizedTags = parseTagsValue((tags || []).join(", "));
    setTagsTextareaValue(refs.textarea, normalizedTags, triggerAutosave);
    renderTagsChips(refs.chipList, normalizedTags);
    refs.editor.classList.toggle("is-empty", normalizedTags.length === 0);

    if (clearDraft) {
      refs.chipInput.value = "";
    }
  }

  function commitDraftTag(triggerAutosave = true) {
    const refs = getTagsEditorRefs();
    if (!refs) {
      return;
    }

    const draftValue = refs.chipInput.value.trim().replace(/,+$/, "");
    if (!draftValue) {
      refs.chipInput.value = "";
      return;
    }

    const nextTags = parseTagsValue(
      `${refs.textarea.value}${refs.textarea.value ? ", " : ""}${draftValue}`
    );
    commitTags(nextTags, { triggerAutosave, clearDraft: true });
  }

  function removeTag(tagToRemove) {
    const refs = getTagsEditorRefs();
    if (!refs) {
      return;
    }

    const nextTags = parseTagsValue(refs.textarea.value).filter(
      (tag) => tag.toLowerCase() !== String(tagToRemove).toLowerCase()
    );
    commitTags(nextTags, { triggerAutosave: true, clearDraft: false });
  }

  function initTagsEditor() {
    const refs = getTagsEditorRefs();
    if (!refs) {
      return false;
    }

    if (refs.editor.dataset.aipkitTagsEditorBound === "true") {
      return true;
    }

    refs.chipInput.addEventListener("keydown", (event) => {
      if (event.key === "Enter" || event.key === ",") {
        event.preventDefault();
        commitDraftTag(true);
        return;
      }

      if (
        event.key === "Backspace" &&
        refs.chipInput.value === "" &&
        refs.chipList.lastElementChild
      ) {
        const removeButton = refs.chipList.lastElementChild.querySelector(
          ".aipkit_cw_tag_chip_remove"
        );
        if (removeButton) {
          event.preventDefault();
          removeTag(removeButton.getAttribute("data-aipkit-remove-tag"));
        }
      }
    });

    refs.chipInput.addEventListener("blur", () => {
      commitDraftTag(true);
    });

    refs.chipInput.addEventListener("paste", (event) => {
      const pastedText = event.clipboardData?.getData("text");
      if (!pastedText || !pastedText.includes(",")) {
        return;
      }

      event.preventDefault();
      const nextTags = parseTagsValue(
        `${refs.textarea.value}${refs.textarea.value ? ", " : ""}${pastedText}`
      );
      commitTags(nextTags, { triggerAutosave: true, clearDraft: true });
    });

    refs.chipList.addEventListener("click", (event) => {
      const target = event.target;
      if (!(target instanceof Element)) {
        return;
      }
      const removeButton = target.closest(".aipkit_cw_tag_chip_remove");
      if (!removeButton) {
        return;
      }
      removeTag(removeButton.getAttribute("data-aipkit-remove-tag"));
    });

    syncTagsEditorFromTextarea(true);
    refs.editor.dataset.aipkitTagsEditorBound = "true";
    return true;
  }

  function revealMetaField(wrapperId, inputId, value) {
    const wrapper = document.getElementById(wrapperId);
    const input = inputId ? document.getElementById(inputId) : null;

    if (input && value !== undefined) {
      input.value = value;
    }
    if (!wrapper) return;

    wrapper.style.display = "flex";
    if (inputId === "aipkit_cw_generated_tags") {
      initTagsEditor();
      syncTagsEditorFromTextarea(true);
    }
    syncMetaFieldRevealState(wrapper);
    updateMetaChunkVisibility();
    if (input instanceof HTMLTextAreaElement) {
      queueMetaTextareaResize(input);
    }
  }

  function getExpectedGenerationSteps() {
    return new Set(
      Array.from(
        document.querySelectorAll("#aipkit_cw_progress_workflow [data-step]")
      )
        .map((row) => String(row.dataset.step || "").trim())
        .filter(Boolean)
    );
  }

  function getArticleSkeletonRefs() {
    return {
      title: document.querySelector("[data-aipkit-cw-skeleton-title]"),
      body: document.querySelector("[data-aipkit-cw-skeleton-body]"),
    };
  }

  function hideArticleSkeletonPart(part) {
    const refs = getArticleSkeletonRefs();
    if (!refs[part]) return;
    refs[part].hidden = true;
  }

  function hideMediaSkeleton() {
    const skeleton = document.querySelector("[data-aipkit-cw-media-skeleton]");
    if (skeleton) skeleton.hidden = true;
  }

  function resetOutputSkeletons() {
    const article = getArticleSkeletonRefs();
    if (article.title) article.title.hidden = true;
    if (article.body) article.body.hidden = true;

    hideMediaSkeleton();
  }

  function prepareOutputSkeletons() {
    resetOutputSkeletons();
    const expectedSteps = getExpectedGenerationSteps();
    const article = getArticleSkeletonRefs();

    if (article.title) article.title.hidden = !expectedSteps.has("title");
    if (article.body) article.body.hidden = !expectedSteps.has("content");

    if (expectedSteps.has("image")) {
      const mediaPanel = document.getElementById("aipkit_cw_output_media_panel");
      const mediaSkeleton = document.querySelector(
        "[data-aipkit-cw-media-skeleton]"
      );
      if (mediaPanel) {
        mediaPanel.hidden = false;
        mediaPanel.style.display = "block";
      }
      if (mediaSkeleton) mediaSkeleton.hidden = false;
    }

    updateMetaChunkVisibility();
  }

  function settleOutputSkeletonStep(step, status) {
    if (!terminalOutputStates.has(String(status || ""))) return;

    if (step === "title") {
      const title = document.getElementById(
        "aipkit_cw_generated_title_display"
      );
      if (status !== "success" || String(title?.textContent || "").trim()) {
        hideArticleSkeletonPart("title");
      }
    }
    if (step === "content") {
      const content = document.getElementById(
        "aipkit_cw_generated_content_area"
      );
      if (
        status !== "success" ||
        (elementHasVisibleContent(content) &&
          !content?.querySelector(".aipkit_spinner"))
      ) {
        hideArticleSkeletonPart("body");
      }
    }

    if (step === "image" && status !== "success") {
      const mediaPanel = document.getElementById("aipkit_cw_output_media_panel");
      hideMediaSkeleton();
      if (mediaPanel) {
        mediaPanel.hidden = true;
        mediaPanel.style.display = "none";
      }
    }

    updateMetaChunkVisibility();
  }

  /**
   * Show the meta chunk when at least one meta field is visible
   */
  function updateMetaChunkVisibility() {
    const metaChunk = document.getElementById("aipkit_cw_meta_chunk");
    if (!metaChunk) return;

    // Check if any managed field is visible (not hidden).
    const allFields = getManagedMetaFields(metaChunk);
    allFields.forEach((field) => {
      syncMetaFieldRevealState(field);
    });
    syncVisibleMetaFieldSequence(metaChunk);
    const hasVisibleField = updateMetaGroupVisibility(metaChunk);

    if (hasVisibleField) {
      metaChunk.style.display = "flex";
      metaChunk.hidden = false;
      queueMetaChunkReveal(metaChunk);
      return;
    }

    metaChunk.hidden = true;
    metaChunk.classList.remove("is-visible");
    metaChunk.style.display = "none";
  }

  /**
   * Update the article preview counter (words only).
   * Counts text content only (no HTML/Markdown).
   */
  function updatePreviewCounter() {
    const contentArea = document.getElementById(
      "aipkit_cw_generated_content_area"
    );
    if (!contentArea) return;

    if (contentArea.querySelector(".aipkit_spinner")) {
      delete contentArea.dataset.aipkitWordCount;
      return;
    }

    const text = contentArea.textContent || "";
    const trimmed = text.trim();
    const words = trimmed ? trimmed.split(/\s+/).length : 0;

    if (!words) {
      delete contentArea.dataset.aipkitWordCount;
      return;
    }

    const wordLabel = words === 1 ? "word" : "words";
    const wordCount = `${words} ${wordLabel}`;
    contentArea.dataset.aipkitWordCount = wordCount;

    if (typeof window.aipkit_updateCwGenerationStepDetail === "function") {
      window.aipkit_updateCwGenerationStepDetail(
        "content",
        wordCount
      );
    }
  }

  function scrollPreviewCanvasToTop() {
    const canvas = document.getElementById("aipkit_content_writer_output_display");
    if (!canvas || typeof canvas.scrollTo !== "function") {
      return;
    }

    window.requestAnimationFrame(() => {
      window.requestAnimationFrame(() => {
        canvas.scrollTo({
          top: 0,
          behavior: "smooth",
        });
      });
    });
  }

  /**
   * Observe content changes to update the preview counter live.
   */
  function observePreviewCounter() {
    const contentArea = document.getElementById(
      "aipkit_cw_generated_content_area"
    );
    if (!contentArea) return;

    let updateScheduled = false;
    const scheduleUpdate = () => {
      if (updateScheduled) return;
      updateScheduled = true;
      window.requestAnimationFrame(() => {
        updateScheduled = false;
        updatePreviewCounter();
        if (
          elementHasVisibleContent(contentArea) &&
          !contentArea.querySelector(".aipkit_spinner")
        ) {
          hideArticleSkeletonPart("body");
        }
      });
    };

    const observer = new MutationObserver(() => {
      scheduleUpdate();
    });

    observer.observe(contentArea, {
      childList: true,
      subtree: true,
      characterData: true,
    });

    scheduleUpdate();
  }

  function observeArticleTitleResolution() {
    const title = document.getElementById("aipkit_cw_generated_title_display");
    if (!title) return;

    const resolveTitleSkeleton = () => {
      if (String(title.textContent || "").trim()) {
        hideArticleSkeletonPart("title");
      }
    };
    const observer = new MutationObserver(resolveTitleSkeleton);
    observer.observe(title, {
      childList: true,
      subtree: true,
      characterData: true,
      attributes: true,
      attributeFilter: ["style", "hidden"],
    });
    resolveTitleSkeleton();
  }

  /**
   * Initialize character count display for meta description
   */
  function initCharacterCount() {
    const metaDescInput = document.getElementById(
      "aipkit_cw_generated_meta_desc"
    );
    const charCountSpan = document.querySelector(
      "#aipkit_content_writer_container .aipkit_cw_meta_char_count"
    );

    if (!metaDescInput || !charCountSpan) return;

    function updateCount() {
      const length = metaDescInput.value.length;
      const optimal = length >= 120 && length <= 160;
      charCountSpan.textContent = `${length}/160`;
      charCountSpan.style.color = optimal ? "#16a34a" : length > 160 ? "#dc2626" : "#94a3b8";
    }

    metaDescInput.addEventListener("input", updateCount);
    updateCount(); // Initial count
  }

  /**
   * Attach mutation observer to watch for meta field visibility changes
   */
  function observeMetaFieldVisibility() {
    const metaChunk = document.getElementById("aipkit_cw_meta_chunk");
    if (!metaChunk) return;

    const observer = new MutationObserver(function (mutations) {
      mutations.forEach(function (mutation) {
        if (
          mutation.type === "attributes" &&
          (mutation.attributeName === "style" ||
            mutation.attributeName === "hidden")
        ) {
          updateMetaChunkVisibility();
        }
      });
    });

    // Observe all meta field wrappers
    const fieldWrappers = [
      "aipkit_cw_excerpt_output_wrapper",
      "aipkit_cw_tags_output_wrapper",
      "aipkit_cw_focus_keyword_output_wrapper",
      "aipkit_cw_meta_desc_output_wrapper",
      "aipkit_cw_seo_audit_summary",
      "aipkit_cw_smart_seo_locked_card",
      "aipkit_cw_smart_seo_run_card",
    ];

    fieldWrappers.forEach((id) => {
      const el = document.getElementById(id);
      if (el) {
        observer.observe(el, { attributes: true, attributeFilter: ["style", "hidden"] });
      }
    });
  }

  /**
   * Initialize all output chunk handlers
   */
  function init() {
    initCharacterCount();
    observeMetaFieldVisibility();
    observePreviewCounter();
    observeArticleTitleResolution();
    observeSessionCardState();
    initStreamFollow();
    initTagsEditor();
    initMetaTextareasAutoGrow();
    resetCanvasState();
    syncSessionCardState();
  }

  // Run on DOMContentLoaded
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }

  // Expose functions for external use
  window.aipkit_updateMetaChunkVisibility = updateMetaChunkVisibility;
  window.aipkit_revealContentWriterMetaField = revealMetaField;
  window.aipkit_updateContentWriterPreviewCounter = updatePreviewCounter;
  window.aipkit_initContentWriterTagsEditor = initTagsEditor;
  window.aipkit_setContentWriterRawHtml = setRawContentHtml;
  window.aipkit_getContentWriterRawHtml = getRawContentHtml;
  window.aipkit_resetContentWriterRawHtml = resetRawContentHtml;
  window.aipkit_syncContentWriterTagsEditor = syncTagsEditorFromTextarea;
  window.aipkit_refreshContentWriterMetaTextareas = initMetaTextareasAutoGrow;
  window.aipkit_setContentWriterCanvasState = setCanvasState;
  window.aipkit_resetContentWriterCanvasState = resetCanvasState;
  window.aipkit_getContentWriterCanvasState = getCurrentCanvasState;
  window.aipkit_hasContentWriterCanvasContent = hasMeaningfulCanvasContent;
  window.aipkit_syncContentWriterSessionCardState = syncSessionCardState;
  window.aipkit_scrollContentWriterPreviewToTop = scrollPreviewCanvasToTop;
  window.aipkit_followContentWriterStream = followStreamToLatest;
  window.aipkit_resetContentWriterStreamFollow = resetStreamFollow;
  window.aipkit_finishContentWriterStreamFollow = finishStreamFollow;
  window.aipkit_prepareContentWriterOutputSkeletons = prepareOutputSkeletons;
  window.aipkit_hideContentWriterArticleSkeletonPart =
    hideArticleSkeletonPart;
  window.aipkit_settleContentWriterOutputSkeletonStep =
    settleOutputSkeletonStep;
  window.aipkit_hideContentWriterMediaSkeleton = hideMediaSkeleton;
  window.aipkit_resetContentWriterOutputSkeletons = resetOutputSkeletons;
  window.aipkit_resetContentWriterPreviewCounter = function () {
    const contentArea = document.getElementById(
      "aipkit_cw_generated_content_area"
    );
    if (!contentArea) return;
    delete contentArea.dataset.aipkitWordCount;
  };
})();
