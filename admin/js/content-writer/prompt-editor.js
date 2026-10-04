/**
 * Content Writer inline prompt settings.
 * Keeps prompt editing inside the inspector and limits the section to one open editor.
 */
import {
  appendPromptLibraryOptions,
  getPromptTextareaForSelect,
  openPromptLibraryManagerForSelect,
  syncPromptTextareaFromSelect,
} from "../utils/ui/prompt-editor-controls.js";

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
              result.replace(new RegExp(`%${index + 1}\\$s|%s`), String(value)),
            format
          );

  const TOPIC_PLACEHOLDERS = ["{topic}", "{keywords}"];
  const SUMMARY_PLACEHOLDERS = [...TOPIC_PLACEHOLDERS, "{content_summary}"];
  const IMAGE_METADATA_KEYS = [
    "image_title", "image_alt_text", "image_caption", "image_description",
  ];
  const IMAGE_METADATA_PLACEHOLDERS = [
    ...TOPIC_PLACEHOLDERS, "{post_title}", "{excerpt}", "{file_name}",
  ];
  const PLACEHOLDER_SETS = {
    title: TOPIC_PLACEHOLDERS,
    content: TOPIC_PLACEHOLDERS,
    meta: ["{topic}", "{content_summary}", "{keywords}"],
    keyword: ["{topic}", "{content_summary}"],
    excerpt: SUMMARY_PLACEHOLDERS,
    tags: SUMMARY_PLACEHOLDERS,
    image: ["{topic}", "{keywords}", "{excerpt}", "{post_title}"],
    featured_image: ["{topic}", "{post_title}", "{excerpt}", "{keywords}"],
  };

  const UPDATE_SOURCE_PLACEHOLDERS = [
    "{original_title}",
    "{original_content}",
    "{original_excerpt}",
    "{original_meta_description}",
    "{original_focus_keyword}",
    "{original_tags}",
    "{categories}",
  ];

  const PRODUCT_PLACEHOLDERS = [
    ...UPDATE_SOURCE_PLACEHOLDERS,
    "{price}",
    "{regular_price}",
    "{sale_price}",
    "{sku}",
    "{attributes}",
    "{stock_quantity}",
    "{stock_status}",
    "{weight}",
    "{length}",
    "{width}",
    "{height}",
    "{short_description}",
    "{purchase_note}",
    "{total_sales}",
    "{product_categories}",
  ];

  const MODE_EXTRAS = {
    rss: ["{description}", "{source_url}"],
    url: ["{url_content}", "{source_url}"],
  };

  const ALL_TEXT_PROMPT_KEYS = [
    "title",
    "content",
    "meta",
    "keyword",
    "excerpt",
    "tags",
  ];

  const SOURCE_PROMPT_REQUIREMENTS = {
    task: {
      fieldKeys: ALL_TEXT_PROMPT_KEYS,
      modeLabel: __("Manual entry", "gpt3-ai-content-generator"),
      placeholder: "{topic}",
      sourceDescription: __("Manual entry uses the topic as its source", "gpt3-ai-content-generator"),
    },
    csv: {
      fieldKeys: ALL_TEXT_PROMPT_KEYS,
      modeLabel: __("CSV", "gpt3-ai-content-generator"),
      placeholder: "{topic}",
      sourceDescription: __("Each CSV row provides a topic", "gpt3-ai-content-generator"),
    },
    gsheets: {
      fieldKeys: ALL_TEXT_PROMPT_KEYS,
      modeLabel: __("Google Sheets", "gpt3-ai-content-generator"),
      placeholder: "{topic}",
      sourceDescription: __("Each Google Sheets row provides a topic", "gpt3-ai-content-generator"),
    },
    rss: {
      fieldKeys: ["title", "content"],
      modeLabel: __("RSS", "gpt3-ai-content-generator"),
      placeholder: "{description}",
      sourceDescription: __("RSS feeds provide their source content as a description", "gpt3-ai-content-generator"),
    },
    url: {
      fieldKeys: ["title", "content"],
      modeLabel: __("URL", "gpt3-ai-content-generator"),
      placeholder: "{url_content}",
      sourceDescription: __("URL mode provides the extracted page content", "gpt3-ai-content-generator"),
    },
  };

  const PRODUCT_REQUIRED_BY_FIELD = {
    title: ["{original_title}"],
    content: ["{original_content}", "{short_description}"],
    meta: ["{original_title}", "{short_description}", "{original_content}"],
    keyword: ["{original_title}", "{original_content}"],
    excerpt: ["{short_description}", "{original_content}", "{original_excerpt}"],
    tags: ["{original_content}", "{attributes}", "{product_categories}"],
  };

  const IMAGE_PLACEHOLDERS = [
    "{original_title}",
    "{original_caption}",
    "{original_description}",
    "{original_alt}",
    "{file_name}",
  ];

  const IMAGE_METADATA_PROMPT_TARGETS = new Set(
    IMAGE_METADATA_KEYS.map((key) => `aipkit_cw_${key}_prompt`)
  );

  const UPDATE_PROMPT_REQUIREMENTS = {
    "existing-content": {
      fieldKeys: ALL_TEXT_PROMPT_KEYS,
      modeLabel: __("Rewrite content", "gpt3-ai-content-generator"),
      placeholders: UPDATE_SOURCE_PLACEHOLDERS,
      sourceDescription: __("Rewrite content needs source context", "gpt3-ai-content-generator"),
    },
    "existing-products": {
      fieldKeys: ALL_TEXT_PROMPT_KEYS,
      modeLabel: __("WooCommerce", "gpt3-ai-content-generator"),
      placeholdersByField: PRODUCT_REQUIRED_BY_FIELD,
      sourceDescription: __("WooCommerce updates need product source context", "gpt3-ai-content-generator"),
    },
    "existing-images": {
      fieldKeys: IMAGE_METADATA_KEYS,
      modeLabel: __("Image alt text", "gpt3-ai-content-generator"),
      placeholders: IMAGE_PLACEHOLDERS,
      sourceDescription: __("Image optimization needs attachment source context", "gpt3-ai-content-generator"),
    },
  };

  const getFieldEditor = (field) =>
    field.querySelector(":scope > [data-aipkit-cw-prompt-editor]");
  const getFieldTrigger = (field) =>
    field.querySelector(":scope > .aipkit_cw_prompt_field_row [data-aipkit-cw-prompt-toggle]");
  const getFieldToggle = (field) =>
    field.querySelector(":scope > .aipkit_cw_prompt_field_row input[type='checkbox']");

  const getActiveMode = () => {
    const selected = document.getElementById("aipkit_cw_mode_select")?.value || "task";
    return selected === "single" ? "task" : selected;
  };

  const isUpdateMode = (mode = getActiveMode()) =>
    String(mode).indexOf("existing") === 0;

  const getPromptType = (select) => {
    const variables = select
      ?.closest(".aipkit_cw_prompt_editor")
      ?.querySelector(".aipkit_cw_prompt_variable_chips[data-prompt-type]");
    return variables?.dataset.promptType || "";
  };

  const getPromptTypeVariants = (select) => {
    const targetId = select?.dataset.aipkitPromptTarget || "";
    const primaryType = getPromptType(select);
    return {
      primaryType,
      secondaryType: IMAGE_METADATA_PROMPT_TARGETS.has(targetId)
        ? `${primaryType}_update`
        : "",
    };
  };

  const getActivePromptType = (select) => {
    const { primaryType, secondaryType } = getPromptTypeVariants(select);
    return secondaryType && getActiveMode() === "existing-images"
      ? secondaryType
      : primaryType;
  };

  const getPlaceholders = (promptType, mode) => {
    if (mode === "existing-products") {
      return PRODUCT_PLACEHOLDERS;
    }
    if (mode === "existing-images") {
      return IMAGE_PLACEHOLDERS;
    }
    if (mode === "existing-content") {
      return UPDATE_SOURCE_PLACEHOLDERS;
    }
    const values = [
      ...(IMAGE_METADATA_KEYS.includes(promptType)
        ? IMAGE_METADATA_PLACEHOLDERS
        : PLACEHOLDER_SETS[promptType] || []),
      ...(MODE_EXTRAS[mode] || []),
    ];
    return values.filter((value, index) => values.indexOf(value) === index);
  };

  const renderVariables = (container, promptType, mode) => {
    if (!container) return;
    container.replaceChildren();
    getPlaceholders(promptType, mode).forEach((variable) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "aipkit_cw_prompt_variable_chip";
      button.dataset.aipkitPromptVariable = variable;
      button.textContent = variable;
      button.setAttribute(
        "aria-label",
        sprintf(__("Copy %1$s", "gpt3-ai-content-generator"), variable)
      );
      container.appendChild(button);
    });
  };

  const copyText = async (text) => {
    if (navigator.clipboard?.writeText) {
      try {
        await navigator.clipboard.writeText(text);
        return true;
      } catch {
        // Fall through to the legacy copy path when browser permissions block the API.
      }
    }

    const copyTarget = document.createElement("textarea");
    copyTarget.value = text;
    copyTarget.readOnly = true;
    copyTarget.style.position = "fixed";
    copyTarget.style.inset = "-9999px auto auto -9999px";
    document.body.appendChild(copyTarget);
    copyTarget.select();
    const copied = document.execCommand("copy");
    copyTarget.remove();
    return copied;
  };

  const showVariableCopyFeedback = (chip, variable) => {
    window.clearTimeout(Number(chip.dataset.aipkitCopyFeedbackTimer || 0));
    chip.textContent = __("Copied!", "gpt3-ai-content-generator");
    chip.setAttribute(
      "aria-label",
      sprintf(__("%1$s copied", "gpt3-ai-content-generator"), variable)
    );
    chip.dataset.aipkitCopyFeedbackTimer = String(
      window.setTimeout(() => {
        if (!chip.isConnected) return;
        chip.textContent = variable;
        chip.setAttribute(
          "aria-label",
          sprintf(__("Copy %1$s", "gpt3-ai-content-generator"), variable)
        );
        delete chip.dataset.aipkitCopyFeedbackTimer;
      }, 1200)
    );
  };

  const copyVariable = async (chip) => {
    const variable = chip.dataset.aipkitPromptVariable || "";
    if (!variable) return;
    try {
      if (await copyText(variable)) {
        showVariableCopyFeedback(chip, variable);
      }
    } catch (error) {
      console.error("Prompt variable copy failed:", error);
    }
  };

  const getDefaultPrompt = (promptType, mode) => {
    const defaults = window.aipkit_content_writer_config?.default_prompts || {};
    if (mode === "existing-images" && defaults[`${promptType}_update`]) {
      return String(defaults[`${promptType}_update`]);
    }
    return String(defaults[promptType] || "");
  };

  const syncPresetSelection = (select, textarea) => {
    if (!select || !textarea) return;
    const customOption = select.querySelector("[data-aipkit-custom-option]");
    const match = Array.from(select.options).find(
      (option) =>
        !option.hidden &&
        !option.hasAttribute("data-aipkit-custom-option") &&
        String(option.value || "") === String(textarea.value || "")
    );
    if (match) {
      select.value = match.value;
      if (customOption) customOption.hidden = true;
      return;
    }
    if (customOption) {
      customOption.hidden = false;
      select.value = customOption.value;
    }
  };

  const syncModeOptions = (select, textarea, mode) => {
    const { primaryType, secondaryType } = getPromptTypeVariants(select);
    const defaultOption = select.options?.[0] || null;
    if (defaultOption && secondaryType) {
      if (!select.dataset.aipkitDefaultCreate) {
        select.dataset.aipkitDefaultCreate = defaultOption.value || "";
      }
      defaultOption.value = mode === "existing-images"
        ? getDefaultPrompt(primaryType, mode) || defaultOption.value
        : select.dataset.aipkitDefaultCreate;
    }
    Array.from(select.options).forEach((option, index) => {
      if (index === 0 || option.hasAttribute("data-aipkit-custom-option")) return;
      const optionMode = option.dataset.aipkitMode || "both";
      option.hidden = isUpdateMode(mode)
        ? optionMode === "create"
        : optionMode === "update";
    });
    syncPresetSelection(select, textarea);
  };

  function initContentWriterInlinePrompts(scopeElement) {
    const root = scopeElement?.querySelector("[data-aipkit-cw-inline-prompts]");
    if (!root || root.dataset.aipkitInlinePromptInit === "true") return;

    const promptLibraryApi = window.aipkit_prompt_library_api || null;
    const fields = () => Array.from(root.querySelectorAll("[data-aipkit-cw-prompt-field]"));
    const selects = () => Array.from(root.querySelectorAll(".aipkit_cw_prompt_library_select"));
    const promptModal = scopeElement.querySelector(
      "[data-aipkit-cw-prompt-editor-modal]"
    );
    const promptModalTextarea = promptModal?.querySelector(
      "[data-aipkit-cw-prompt-editor-textarea]"
    );
    const promptModalTitle = promptModal?.querySelector(
      "#aipkit_cw_prompt_editor_modal_title"
    );
    const promptModalCount = promptModal?.querySelector(
      "[data-aipkit-cw-prompt-editor-count]"
    );
    let activeField = null;
    let promptModalSource = null;
    let promptModalReturnFocus = null;

    const getPromptModalFocusableElements = () =>
      Array.from(
        promptModal?.querySelectorAll(
          'button:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        ) || []
      ).filter(
        (element) =>
          !element.hidden &&
          element.getAttribute("aria-hidden") !== "true" &&
          element.offsetParent !== null
      );

    const updatePromptModalCount = (value) => {
      if (promptModalCount) {
        promptModalCount.textContent = `${value.length.toLocaleString()} characters`;
      }
    };

    const closePromptModal = () => {
      if (!promptModal?.classList.contains("aipkit-active")) return;

      if (promptModalSource && promptModalTextarea) {
        const modalValue = promptModalTextarea.value;
        if (modalValue !== promptModalSource.value) {
          promptModalSource.value = modalValue;
          promptModalSource.dispatchEvent(new Event("input", { bubbles: true }));
          window.aipkit_handleContentWriterAutoSave?.(promptModalSource);
        }
      }

      promptModal.classList.remove("aipkit-active");
      promptModal.setAttribute("aria-hidden", "true");
      promptModalReturnFocus?.setAttribute("aria-expanded", "false");

      if (promptModalReturnFocus?.isConnected) {
        promptModalReturnFocus.focus();
      }
      promptModalSource = null;
      promptModalReturnFocus = null;
    };

    const openPromptModal = (trigger) => {
      const editor = trigger?.closest(".aipkit_cw_prompt_editor");
      const textarea = editor?.querySelector(".aipkit_cw_prompt_inline_textarea");
      if (!promptModal || !promptModalTextarea || !textarea) return;

      promptModalSource = textarea;
      promptModalReturnFocus = trigger;
      promptModalTextarea.value = textarea.value || "";
      updatePromptModalCount(promptModalTextarea.value);

      const fieldLabel = trigger
        .closest("[data-aipkit-cw-prompt-field]")
        ?.querySelector(":scope > .aipkit_cw_prompt_field_row .aipkit_cw_prompt_field_label")
        ?.textContent?.trim();
      if (promptModalTitle) {
        promptModalTitle.textContent = fieldLabel
          ? sprintf(
              __("%1$s prompt", "gpt3-ai-content-generator"),
              fieldLabel
            )
          : __("Prompt editor", "gpt3-ai-content-generator");
      }

      promptModal.classList.add("aipkit-active");
      promptModal.setAttribute("aria-hidden", "false");
      trigger.setAttribute("aria-expanded", "true");
      window.setTimeout(() => promptModalTextarea.focus(), 0);
    };

    const closeField = (field = activeField) => {
      if (!field) return;
      const editor = getFieldEditor(field);
      const trigger = getFieldTrigger(field);
      if (editor) editor.hidden = true;
      field.classList.remove("is-editor-open");
      trigger?.setAttribute("aria-expanded", "false");
      if (activeField === field) activeField = null;
    };

    const openField = (field) => {
      if (!field || field.classList.contains("is-disabled")) return;
      if (activeField && activeField !== field) closeField(activeField);
      const editor = getFieldEditor(field);
      const trigger = getFieldTrigger(field);
      if (!editor || !trigger) return;
      if (activeField === field && !editor.hidden) {
        closeField(field);
        return;
      }
      editor.hidden = false;
      field.classList.add("is-editor-open");
      trigger.setAttribute("aria-expanded", "true");
      activeField = field;
    };

    const isFieldEnabled = (field) => {
      if (field.hidden) return false;
      const hiddenPromptAncestor = field.parentElement?.closest("[hidden]");
      const isCollapsedPromptContainer =
        hiddenPromptAncestor?.hasAttribute(
          "data-aipkit-cw-inspector-disclosure-panel"
        ) ||
        hiddenPromptAncestor?.hasAttribute(
          "data-aipkit-cw-image-prompts-panel"
        );
      if (
        hiddenPromptAncestor &&
        root.contains(hiddenPromptAncestor) &&
        !isCollapsedPromptContainer
      ) {
        return false;
      }
      const toggle = getFieldToggle(field);
      if (!toggle) return true;
      const switchLabel = toggle.closest(".aipkit_switch");
      if (switchLabel?.classList.contains("aipkit_prompt_update_only") && !isUpdateMode()) {
        return true;
      }
      return toggle.checked;
    };

    const syncFieldStates = () => {
      fields().forEach((field) => {
        const fieldToggle = getFieldToggle(field);
        const switchLabel = fieldToggle?.closest(".aipkit_switch");
        const isRequiredToggle = switchLabel?.classList.contains(
          "aipkit_prompt_update_only"
        );
        const isReadonly = isRequiredToggle && !isUpdateMode();
        if (fieldToggle && isRequiredToggle) {
          if (isReadonly) {
            fieldToggle.checked = true;
            fieldToggle.setAttribute("aria-disabled", "true");
            fieldToggle.tabIndex = -1;
          } else {
            fieldToggle.removeAttribute("aria-disabled");
            fieldToggle.removeAttribute("tabindex");
          }
          switchLabel.classList.toggle("is-readonly", isReadonly);
        }

        const enabled = isFieldEnabled(field);
        const instructionsAvailable =
          field.dataset.aipkitPromptInstructionsAvailable !== "false";
        const triggerEnabled = enabled && instructionsAvailable;
        const trigger = getFieldTrigger(field);
        field.classList.toggle("is-disabled", !enabled);
        if (trigger) {
          trigger.hidden = !triggerEnabled;
          trigger.disabled = !triggerEnabled;
          trigger.setAttribute(
            "aria-disabled",
            triggerEnabled ? "false" : "true"
          );
        }
        if (!triggerEnabled && activeField === field) closeField(field);
      });
    };

    const getFieldLabel = (field) =>
      field
        ?.querySelector(
          ":scope > .aipkit_cw_prompt_field_row .aipkit_cw_prompt_field_label"
        )
        ?.textContent?.trim() || __("Prompt", "gpt3-ai-content-generator");

    const updateRequirementDescription = (textarea, warning, missing) => {
      if (!textarea || !warning?.id) return;
      const describedBy = new Set(
        String(textarea.getAttribute("aria-describedby") || "")
          .split(/\s+/)
          .filter(Boolean)
      );
      if (missing) {
        describedBy.add(warning.id);
        textarea.setAttribute("aria-invalid", "true");
      } else {
        describedBy.delete(warning.id);
        textarea.removeAttribute("aria-invalid");
      }
      if (describedBy.size) {
        textarea.setAttribute("aria-describedby", Array.from(describedBy).join(" "));
      } else {
        textarea.removeAttribute("aria-describedby");
      }
    };

    const buildRequirementTooltip = (issues) => {
      if (!issues.length) return "";
      const requiredValue =
        issues[0].placeholders?.length === 1
          ? issues[0].placeholders[0]
          : __("a source variable", "gpt3-ai-content-generator");
      const labels = issues.map((issue) => issue.label);
      let fieldSummary = "";
      if (labels.length === 1) {
        fieldSummary = labels[0];
      } else if (labels.length === 2) {
        fieldSummary = sprintf(
          __("%1$s and %2$s", "gpt3-ai-content-generator"),
          labels[0],
          labels[1]
        );
      } else {
        fieldSummary = sprintf(
          __("%1$s, %2$s, and %3$s more", "gpt3-ai-content-generator"),
          labels[0],
          labels[1],
          labels.length - 2
        );
      }
      return sprintf(
        _n(
          "Add %1$s to your %2$s prompt to continue.",
          "Add %1$s to your %2$s prompts to continue.",
          labels.length,
          "gpt3-ai-content-generator"
        ),
        requiredValue,
        fieldSummary
      );
    };

    const getRequiredPlaceholders = (requirement, fieldKey) => {
      if (!requirement) return [];
      if (requirement.placeholdersByField) {
        return requirement.placeholdersByField[fieldKey] || [];
      }
      if (Array.isArray(requirement.placeholders)) {
        return requirement.placeholders;
      }
      return requirement.placeholder ? [requirement.placeholder] : [];
    };

    const syncGenerateRequirementTooltip = (issues) => {
      const generateBtn = scopeElement.querySelector(
        "#aipkit_content_writer_generate_btn"
      );
      if (!generateBtn) return;
      const previousTooltip =
        generateBtn.dataset.aipkitPromptRequirementTooltip || "";
      if (
        previousTooltip &&
        generateBtn.getAttribute("title") === previousTooltip
      ) {
        generateBtn.removeAttribute("title");
      }
      const nextTooltip = buildRequirementTooltip(issues);
      if (nextTooltip) {
        generateBtn.title = nextTooltip;
        generateBtn.dataset.aipkitPromptRequirementTooltip = nextTooltip;
      } else {
        delete generateBtn.dataset.aipkitPromptRequirementTooltip;
      }
    };

    const syncPromptRequirements = (requestedMode = getActiveMode()) => {
      const mode = requestedMode === "single" ? "task" : requestedMode;
      const requirement =
        SOURCE_PROMPT_REQUIREMENTS[mode] ||
        UPDATE_PROMPT_REQUIREMENTS[mode] ||
        null;
      const issues = [];

      fields().forEach((field) => {
        const fieldKey = field.dataset.aipkitPromptKey || "";
        const textarea = field.querySelector(
          ":scope > [data-aipkit-cw-prompt-editor] .aipkit_cw_prompt_inline_textarea"
        );
        const warning = field.querySelector(
          ":scope > [data-aipkit-cw-prompt-editor] [data-aipkit-cw-prompt-requirement]"
        );
        const chips = Array.from(
          field.querySelectorAll(
            ":scope > [data-aipkit-cw-prompt-editor] [data-aipkit-prompt-variable]"
          )
        );

        field.classList.remove("has-prompt-requirement-error");
        delete field.dataset.aipkitRequiredPlaceholders;
        chips.forEach((chip) => {
          chip.classList.remove("is-required-missing");
          chip.removeAttribute("title");
          const variable = chip.dataset.aipkitPromptVariable || "";
          chip.setAttribute(
            "aria-label",
            sprintf(__("Copy %1$s", "gpt3-ai-content-generator"), variable)
          );
        });
        if (warning) {
          warning.hidden = true;
          warning.textContent = "";
        }
        updateRequirementDescription(textarea, warning, false);

        if (
          !requirement ||
          !requirement.fieldKeys.includes(fieldKey) ||
          !isFieldEnabled(field) ||
          !textarea
        ) {
          return;
        }

        const requiredPlaceholders = getRequiredPlaceholders(
          requirement,
          fieldKey
        );
        if (!requiredPlaceholders.length) return;

        const requiredChips = chips.filter((chip) =>
          requiredPlaceholders.includes(
            chip.dataset.aipkitPromptVariable || ""
          )
        );
        requiredChips.forEach((chip) => {
          const variable = chip.dataset.aipkitPromptVariable || "";
          chip.title =
            requiredPlaceholders.length === 1
              ? sprintf(
                  __("%1$s is required for %2$s", "gpt3-ai-content-generator"),
                  variable,
                  requirement.modeLabel
                )
              : sprintf(
                  __("%1$s can provide source context for %2$s", "gpt3-ai-content-generator"),
                  variable,
                  requirement.modeLabel
                );
          chip.setAttribute(
            "aria-label",
            sprintf(
              __("Copy %1$s. Source variable for %2$s.", "gpt3-ai-content-generator"),
              variable,
              requirement.modeLabel
            )
          );
        });

        field.dataset.aipkitRequiredPlaceholders =
          requiredPlaceholders.join(" ");
        const promptValue = String(textarea.value || "");
        const missing = !requiredPlaceholders.some((placeholder) =>
          promptValue.includes(placeholder)
        );
        if (!missing) return;

        requiredChips.forEach((chip) =>
          chip.classList.add("is-required-missing")
        );

        const label = getFieldLabel(field);
        const message =
          requiredPlaceholders.length === 1
            ? sprintf(
                __("%1$s — add %2$s to this %3$s prompt to continue.", "gpt3-ai-content-generator"),
                requirement.sourceDescription,
                requiredPlaceholders[0],
                label
              )
            : sprintf(
                __("%1$s — add one highlighted source variable to this %2$s prompt to continue.", "gpt3-ai-content-generator"),
                requirement.sourceDescription,
                label
              );
        field.classList.add("has-prompt-requirement-error");
        if (warning) {
          warning.textContent = message;
          warning.hidden = false;
        }
        updateRequirementDescription(textarea, warning, true);
        issues.push({
          field,
          fieldKey,
          label,
          message,
          mode,
          modeLabel: requirement.modeLabel,
          placeholder: requiredPlaceholders[0],
          placeholders: requiredPlaceholders,
          textarea,
        });
      });

      syncGenerateRequirementTooltip(issues);
      return issues;
    };

    const validatePromptRequirements = (
      form,
      mode = getActiveMode()
    ) => {
      if (form && !scopeElement.contains(form)) {
        return { isValid: true, issues: [] };
      }
      const issues = syncPromptRequirements(mode);
      return {
        isValid: issues.length === 0,
        issues,
        firstIssue: issues[0] || null,
        tooltip: buildRequirementTooltip(issues),
      };
    };

    const focusPromptRequirement = (issue) => {
      const target = issue?.field?.isConnected
        ? issue
        : syncPromptRequirements()[0];
      if (!target?.field || !target.textarea) return false;

      const disclosure = target.field.closest(
        "[data-aipkit-cw-mode-section]"
      );
      const disclosureToggle = disclosure?.querySelector(
        "[data-aipkit-cw-inspector-disclosure-toggle]"
      );
      if (disclosureToggle?.getAttribute("aria-expanded") !== "true") {
        disclosureToggle.click();
      }

      const imageGroup = target.field.closest(
        ".aipkit_cw_prompt_field--image-group"
      );
      const imageGroupPanel = imageGroup?.querySelector(
        "[data-aipkit-cw-image-prompts-panel]"
      );
      const imageGroupToggle = imageGroup?.querySelector(
        "[data-aipkit-cw-image-prompts-toggle]"
      );
      if (imageGroupPanel?.hidden) {
        imageGroupToggle?.click();
      }

      const editor = getFieldEditor(target.field);
      if (editor?.hidden || activeField !== target.field) {
        openField(target.field);
      }

      target.field.classList.add("is-requirement-target");
      window.setTimeout(() => {
        target.field?.classList.remove("is-requirement-target");
      }, 1800);
      window.requestAnimationFrame(() => {
        target.field.scrollIntoView({ behavior: "smooth", block: "center" });
        window.setTimeout(() => {
          target.textarea.focus({ preventScroll: true });
        }, 180);
      });
      return true;
    };

    const syncAll = () => {
      const mode = getActiveMode();
      selects().forEach((select) => {
        const textarea = getPromptTextareaForSelect(select, root);
        syncModeOptions(select, textarea, mode);
        const variables = select
          .closest(".aipkit_cw_prompt_editor")
          ?.querySelector(".aipkit_cw_prompt_variable_chips");
        renderVariables(variables, getPromptType(select), mode);
      });
      syncFieldStates();
      syncPromptRequirements(mode);
      const imageGroup = root.querySelector("#aipkit_cw_image_prompts_prompt_item");
      if (imageGroup?.hidden) {
        if (activeField && imageGroup.contains(activeField)) closeField(activeField);
        const imagePanel = imageGroup.querySelector("[data-aipkit-cw-image-prompts-panel]");
        const imageToggle = imageGroup.querySelector("[data-aipkit-cw-image-prompts-toggle]");
        if (imagePanel) imagePanel.hidden = true;
        imageToggle?.setAttribute("aria-expanded", "false");
        imageGroup.classList.remove("is-group-open");
      }
    };

    const populateSelect = (select, libraryByType) => {
      const { primaryType, secondaryType } = getPromptTypeVariants(select);
      if (!primaryType || !select.options?.length) return;
      const defaultOption = select.options[0].cloneNode(true);
      const customOption = select.querySelector("[data-aipkit-custom-option]")?.cloneNode(true);
      const textarea = getPromptTextareaForSelect(select, root);
      select.replaceChildren(defaultOption);
      appendPromptLibraryOptions(select, libraryByType[primaryType] || [], secondaryType ? "create" : "both");
      if (secondaryType) {
        appendPromptLibraryOptions(select, libraryByType[secondaryType] || [], "update");
      }
      if (customOption) select.appendChild(customOption);
      syncModeOptions(select, textarea, getActiveMode());
    };

    const refreshPromptLibrary = async (force = false) => {
      if (!promptLibraryApi) return;
      try {
        const data = await promptLibraryApi.list({ force });
        const libraryByType = data?.library || {};
        selects().forEach((select) => populateSelect(select, libraryByType));
      } catch (error) {
        // Static server-rendered presets remain available if the shared library cannot refresh.
        console.error("Prompt library refresh failed:", error);
      }
    };

    root.addEventListener("click", (event) => {
      const promptToggle = event.target.closest("[data-aipkit-cw-prompt-toggle]");
      if (promptToggle && root.contains(promptToggle)) {
        event.preventDefault();
        openField(promptToggle.closest("[data-aipkit-cw-prompt-field]"));
        return;
      }

      const imageToggle = event.target.closest("[data-aipkit-cw-image-prompts-toggle]");
      if (imageToggle && root.contains(imageToggle)) {
        event.preventDefault();
        const panel = root.querySelector("[data-aipkit-cw-image-prompts-panel]");
        if (!panel) return;
        const willOpen = panel.hidden;
        if (!willOpen && activeField && panel.contains(activeField)) closeField(activeField);
        panel.hidden = !willOpen;
        imageToggle.setAttribute("aria-expanded", willOpen ? "true" : "false");
        imageToggle.closest(".aipkit_cw_prompt_field--image-group")?.classList.toggle("is-group-open", willOpen);
        syncFieldStates();
        return;
      }

      const libraryLink = event.target.closest("[data-aipkit-cw-prompt-library-link]");
      if (libraryLink && root.contains(libraryLink)) {
        event.preventDefault();
        const editor = libraryLink.closest(".aipkit_cw_prompt_editor");
        const select = editor?.querySelector(".aipkit_cw_prompt_library_select");
        const textarea = select ? getPromptTextareaForSelect(select, root) : null;
        openPromptLibraryManagerForSelect(select, textarea, {
          fallbackPromptType: () => getActivePromptType(select),
        });
        return;
      }

      const promptExpand = event.target.closest(
        "[data-aipkit-cw-prompt-expand]"
      );
      if (promptExpand && root.contains(promptExpand)) {
        event.preventDefault();
        openPromptModal(promptExpand);
        return;
      }

      const variableChip = event.target.closest("[data-aipkit-prompt-variable]");
      if (variableChip && root.contains(variableChip)) {
        event.preventDefault();
        event.stopPropagation();
        copyVariable(variableChip);
      }
    });

    root.addEventListener("change", (event) => {
      const select = event.target.closest(".aipkit_cw_prompt_library_select");
      if (select && root.contains(select)) {
        const selected = select.selectedOptions?.[0];
        if (!selected?.hasAttribute("data-aipkit-custom-option")) {
          const textarea = getPromptTextareaForSelect(select, root);
          if (syncPromptTextareaFromSelect(select, textarea)) {
            window.aipkit_handleContentWriterAutoSave?.(textarea);
          }
        }
        return;
      }
      if (event.target.matches("input[type='checkbox']")) {
        syncFieldStates();
        syncPromptRequirements();
      }
    });

    root.addEventListener("input", (event) => {
      if (!event.target.matches(".aipkit_cw_prompt_inline_textarea")) return;
      const editor = event.target.closest(".aipkit_cw_prompt_editor");
      const select = editor?.querySelector(".aipkit_cw_prompt_library_select");
      syncPresetSelection(select, event.target);
      syncPromptRequirements();
    });

    promptModal?.addEventListener("click", (event) => {
      if (
        event.target === promptModal ||
        event.target.closest("[data-aipkit-cw-prompt-editor-close]")
      ) {
        event.preventDefault();
        closePromptModal();
      }
    });

    promptModalTextarea?.addEventListener("input", () => {
      updatePromptModalCount(promptModalTextarea.value);
    });

    document.addEventListener("keydown", (event) => {
      if (!promptModal?.classList.contains("aipkit-active")) return;

      if (event.key === "Escape") {
        event.preventDefault();
        closePromptModal();
        return;
      }
      if (event.key !== "Tab") return;

      const focusableElements = getPromptModalFocusableElements();
      if (!focusableElements.length) {
        event.preventDefault();
        return;
      }
      const firstElement = focusableElements[0];
      const lastElement = focusableElements[focusableElements.length - 1];
      if (event.shiftKey && document.activeElement === firstElement) {
        event.preventDefault();
        lastElement.focus();
      } else if (!event.shiftKey && document.activeElement === lastElement) {
        event.preventDefault();
        firstElement.focus();
      }
    });

    document.getElementById("aipkit_cw_mode_select")?.addEventListener("change", syncAll);
    window.addEventListener("aipkit_prompt_library_updated", () => refreshPromptLibrary(true));

    root.dataset.aipkitInlinePromptInit = "true";
    root.aipkitPromptRequirementApi = {
      focus: focusPromptRequirement,
      sync: syncPromptRequirements,
      validate: validatePromptRequirements,
    };
    window.aipkit_syncContentWriterInlinePrompts = syncAll;
    window.aipkit_syncContentWriterPromptRequirements = syncPromptRequirements;
    window.aipkit_validateContentWriterPromptRequirements =
      validatePromptRequirements;
    window.aipkit_focusContentWriterPromptRequirement = focusPromptRequirement;
    window.aipkit_closeContentWriterInlinePromptEditor = () => closeField(activeField);
    syncAll();
    refreshPromptLibrary(true);
  }

  window.aipkit_initContentWriterInlinePrompts = initContentWriterInlinePrompts;
})();
