
/**
 * AIPKit Content Writer - Save as Post Handler
 * Handles collecting form data and generated content to save as a new WordPress post.
 */
(function () {
  "use strict";

  const __ = window.wp?.i18n?.__ || ((text) => text);

  const escaper =
    window.aipkit_escapeHtml ||
    function (str) {
      return str;
    };
  const escapeAttr =
    window.aipkit_escapeAttribute ||
    function (str) {
      return escaper(str);
    };

  function renderSaveAsPostStatus(statusDiv, options = {}) {
    if (!statusDiv) {
      return;
    }

    const {
      tone = "",
      message = "",
      editLink = "",
      editLabel = __("Edit post", "gpt3-ai-content-generator"),
    } = options;

    const normalizedMessage = String(message || "").trim();
    if (!normalizedMessage) {
      statusDiv.textContent = "";
      statusDiv.className = "aipkit_cw_save_status";
      return;
    }

    statusDiv.className = "aipkit_cw_save_status";
    if (tone) {
      statusDiv.classList.add(`is-${tone}`);
    }

    const parts = [
      `<span class="aipkit_cw_save_status_message">${escaper(
        normalizedMessage
      )}</span>`,
    ];

    if (editLink) {
      parts.push(
        `<a class="aipkit_cw_save_status_link" href="${escapeAttr(
          editLink
        )}" target="_blank" rel="noopener noreferrer">${escaper(
          editLabel
        )}</a>`
      );
    }

    statusDiv.innerHTML = parts.join("");
  }

  function getBinaryFieldValue(field, defaultValue = "0") {
    if (!field) {
      return defaultValue;
    }

    if (field.type === "checkbox") {
      return field.checked ? "1" : "0";
    }

    return String(field.value) === "1" ? "1" : "0";
  }

  function resetSaveAsPostStatus() {
    const statusDiv = document.getElementById("aipkit_cw_save_post_status");
    renderSaveAsPostStatus(statusDiv);
  }

  function getSaveActionLabel(form) {
    const status = String(
      form?.elements?.post_status?.value || "draft"
    ).toLowerCase();
    const labels = {
      draft: __("Save as draft", "gpt3-ai-content-generator"),
      publish: __("Publish", "gpt3-ai-content-generator"),
      future: __("Schedule", "gpt3-ai-content-generator"),
      pending: __("Submit for review", "gpt3-ai-content-generator"),
      private: __("Save privately", "gpt3-ai-content-generator"),
    };
    return labels[status] || __("Save", "gpt3-ai-content-generator");
  }

  function updateSaveActionLabels() {
    const form = document.getElementById("aipkit_content_writer_form");
    const label = getSaveActionLabel(form);
    document
      .querySelectorAll("[data-aipkit-cw-save-post-btn]")
      .forEach((button) => {
        button.dataset.aipkitDefaultLabel = label;
        const labelElement = button.querySelector(".aipkit_btn-text");
        if (labelElement && !button.disabled) {
          labelElement.textContent = label;
        } else if (labelElement && !button.classList.contains("is-loading")) {
          labelElement.textContent = label;
        }
      });
    return label;
  }

  function getSmartSeoSlug() {
    const manager = window.aipkit_contentWriterSeoAudit;
    if (!manager || typeof manager.getState !== "function") {
      return "";
    }

    const seoState = manager.getState();
    return String(seoState?.slug || "").trim();
  }

  /**
   * Initializes the "Save as Post" button.
   */
  function aipkit_initSaveAsPostButton() {
    const saveAsPostButtons = Array.from(
      document.querySelectorAll("[data-aipkit-cw-save-post-btn]")
    );
    if (!saveAsPostButtons.length) {
      console.warn("Save as Post Handler: Button not found.");
      return;
    }

    saveAsPostButtons.forEach((button) => {
      if (button.dataset.listenerAttached === "true") {
        return;
      }
      button.addEventListener("click", handleSaveAsPost);
      button.dataset.listenerAttached = "true";
    });

    const form = document.getElementById("aipkit_content_writer_form");
    const statusField = form?.elements?.post_status || null;
    if (statusField && statusField.dataset.aipkitSaveLabelBound !== "true") {
      statusField.addEventListener("change", updateSaveActionLabels);
      statusField.dataset.aipkitSaveLabelBound = "true";
    }
    updateSaveActionLabels();
  }

  /**
   * Handles the "Save as Post" button click event.
   */
  async function handleSaveAsPost() {
    const form = document.getElementById("aipkit_content_writer_form");
    const saveAsPostButtons = Array.from(
      document.querySelectorAll("[data-aipkit-cw-save-post-btn]")
    );
    const contentArea = document.getElementById(
      "aipkit_cw_generated_content_area"
    );
    const outputContainer = document.getElementById(
      "aipkit_content_writer_output_display"
    );
    const statusDiv = document.getElementById("aipkit_cw_save_post_status");

    if (
      !form ||
      !saveAsPostButtons.length ||
      !contentArea ||
      !outputContainer ||
      !statusDiv
    ) {
      console.error("Save as Post: Missing required UI elements.");
      return;
    }

    const rawContentHtml =
      typeof window.aipkit_getContentWriterRawHtml === "function"
        ? window.aipkit_getContentWriterRawHtml()
        : "";
    const contentToSave = rawContentHtml || contentArea.innerHTML;
    const hasMeaningfulContent =
      contentArea.textContent.trim().length > 0 ||
      !!contentArea.querySelector(
        "img, h1, h2, h3, h4, h5, h6, p, ul, ol, li, blockquote, pre, table, figure"
      );
    if (!hasMeaningfulContent) {
      renderSaveAsPostStatus(statusDiv, {
        tone: "error",
        message: __(
          "Nothing to save. Please generate content first.",
          "gpt3-ai-content-generator"
        ),
      });
      return;
    }

    // Get post settings from the form
    const titleDisplay = document.getElementById(
      "aipkit_cw_generated_title_display"
    );

    const postData = {
      post_title: titleDisplay
        ? titleDisplay.textContent
        : __("Untitled AI post", "gpt3-ai-content-generator"),
      post_content: contentToSave,
      post_content_format: form.elements.post_content_format?.value || "html",
      generated_excerpt: form.elements["generated_excerpt"]?.value || "",
      generated_tags: form.elements["generated_tags"]?.value || "",
      meta_description: form.elements["meta_description"]
        ? form.elements["meta_description"].value
        : "",
      focus_keyword: form.elements["focus_keyword"]
        ? form.elements["focus_keyword"].value
        : "",
      post_type: form.elements["post_type"]
        ? form.elements["post_type"].value
        : "post",
      post_author: form.elements["post_author"]
        ? form.elements["post_author"].value
        : 1,
      post_status: form.elements["post_status"]
        ? form.elements["post_status"].value
        : "draft",
      post_schedule_date: form.elements["post_schedule_date"]
        ? form.elements["post_schedule_date"].value
        : "",
      post_schedule_time: form.elements["post_schedule_time"]
        ? form.elements["post_schedule_time"].value
        : "",
      post_categories: form.elements["post_categories[]"]
        ? Array.from(form.elements["post_categories[]"].selectedOptions).map(
            (opt) => opt.value
          )
        : [],
      generate_toc: getBinaryFieldValue(form.elements["generate_toc"], "0"),
      generate_seo_slug: getBinaryFieldValue(
        form.elements["generate_seo_slug"],
        "0"
      ),
      seo_score_profile: form.elements["seo_score_profile"]?.value || "auto",
      seo_score_disabled_rules:
        form.elements["seo_score_disabled_rules"]?.value ||
        (typeof window.aipkit_getDefaultSmartSeoDisabledRules === "function"
          ? window.aipkit_getDefaultSmartSeoDisabledRules()
          : "[]"),
      smart_seo_slug: getSmartSeoSlug(),
      image_data: document.getElementById("aipkit_cw_image_data_holder")?.value,
      image_alignment: form.elements["image_alignment"]
        ? form.elements["image_alignment"].value
        : "none",
      image_size: form.elements["image_size"]
        ? form.elements["image_size"].value
        : "large",
      _ajax_nonce: document.getElementById("aipkit_content_writer_nonce")
        ? document.getElementById("aipkit_content_writer_nonce").value
        : "", // Use main CW nonce for this
    };

    renderSaveAsPostStatus(statusDiv, {
      tone: "info",
      message: __("Saving…", "gpt3-ai-content-generator"),
    });
    const defaultLabel = getSaveActionLabel(form);
    saveAsPostButtons.forEach((button) => {
      window.aipkit_setLoadingStateOpenAI(
        button,
        true,
        defaultLabel,
        __("Saving…", "gpt3-ai-content-generator")
      );
    });

    try {
      const response = await window.aipkit_apiRequest(
        "aipkit_content_writer_save_post",
        postData
      );
      renderSaveAsPostStatus(statusDiv, {
        tone: "success",
        message: __("Post saved.", "gpt3-ai-content-generator"),
        editLink: response.edit_link || "",
        editLabel: __("Edit post", "gpt3-ai-content-generator"),
      });
    } catch (error) {
      console.error("Error saving post:", error);
      renderSaveAsPostStatus(statusDiv, {
        tone: "error",
        message:
          error.message ||
          __("Couldn’t save the post.", "gpt3-ai-content-generator"),
      });
    } finally {
      saveAsPostButtons.forEach((button) => {
        window.aipkit_setLoadingStateOpenAI(button, false, defaultLabel);
      });
    }
  }

  // Expose initializer
  window.aipkit_initSaveAsPostButton = aipkit_initSaveAsPostButton;
  window.aipkit_resetSaveAsPostStatus = resetSaveAsPostStatus;
  window.aipkit_updateContentWriterSaveActionLabel = updateSaveActionLabels;
})();
