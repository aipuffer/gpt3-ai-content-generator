/**
 * AIPKit Content Writer - Prompt Mode Manager
 * Keeps separate prompt sets for create vs. update-existing modes.
 */
(function () {
  "use strict";

  const PROMPT_FIELDS = [
    "custom_title_prompt",
    "custom_content_prompt",
    "custom_meta_prompt",
    "custom_keyword_prompt",
    "custom_excerpt_prompt",
    "custom_tags_prompt",
    "image_prompt",
    "featured_image_prompt",
    "image_title_prompt",
    "image_alt_text_prompt",
    "image_caption_prompt",
    "image_description_prompt",
  ];

  const promptSets = {
    create: {},
    update: {},
  };

  const updateOverrides = {};
  let promptElements = [];
  let activeSet = "create";
  let isApplying = false;

  const normalizeMode = (mode) =>
    mode && mode !== "single" ? mode : "task";

  const getActiveMode = () => {
    const modeSelect = document.getElementById("aipkit_cw_mode_select");
    return normalizeMode(modeSelect?.value || "task");
  };

  const getTargetSet = (mode) =>
    mode && mode.indexOf("existing") === 0 ? "update" : "create";

  const resolveUpdatePrompt = (updateValue, createValue, hasOverride) => {
    if (hasOverride) {
      return String(updateValue || "");
    }
    const fallback = String(updateValue || "").trim()
      ? updateValue
      : createValue || "";
    return String(fallback || "");
  };

  const syncFromUI = () => {
    if (isApplying) return;
    const isUpdate = activeSet === "update";

    promptElements.forEach(({ key, textarea, updateInput }) => {
      if (!textarea) return;
      const value = textarea.value || "";
      if (!isUpdate) {
        promptSets.create[key] = value;
        return;
      }
      if (!updateOverrides[key]) {
        return;
      }
      promptSets.update[key] = value;
      if (updateInput) {
        updateInput.value = value;
      }
    });
  };

  const applyToUI = () => {
    isApplying = true;
    const isUpdate = activeSet === "update";

    promptElements.forEach(({ key, textarea, updateInput }) => {
      if (!textarea) return;
      const createValue = promptSets.create[key] || "";
      const updateValue = promptSets.update[key] || "";
      const hasOverride = Boolean(updateOverrides[key]);
      const valueToUse = isUpdate
        ? resolveUpdatePrompt(updateValue, createValue, hasOverride)
        : createValue;

      textarea.value = valueToUse || "";

      if (isUpdate && updateInput) {
        updateInput.value = hasOverride ? updateValue : "";
      }
    });

    isApplying = false;
  };

  const setMode = (mode) => {
    const targetSet = getTargetSet(normalizeMode(mode));
    if (targetSet === activeSet) {
      return;
    }
    syncFromUI();
    activeSet = targetSet;
    applyToUI();
  };

  const init = (scopeElement) => {
    if (!scopeElement || scopeElement.dataset.aipkitPromptModeInit === "true") {
      return;
    }

    promptElements = PROMPT_FIELDS.map((key) => {
      const textareaId = `aipkit_cw_${key}`;
      const updateInputId = `${textareaId}_update`;
      const textarea =
        scopeElement.querySelector(`#${textareaId}`) ||
        document.getElementById(textareaId);
      const updateInput =
        scopeElement.querySelector(`#${updateInputId}`) ||
        document.getElementById(updateInputId);
      return {
        key,
        textarea,
        updateInput,
      };
    });

    if (!promptElements.some((field) => field.textarea)) {
      return;
    }

    promptElements.forEach((field) => {
      const currentValue = field.textarea ? field.textarea.value || "" : "";
      promptSets.create[field.key] = currentValue;

      const updateValue = field.updateInput ? field.updateInput.value || "" : "";
      promptSets.update[field.key] = updateValue;
      updateOverrides[field.key] = Boolean(updateValue);

      if (field.textarea && !field.textarea.dataset.aipkitPromptModeListener) {
        field.textarea.addEventListener("input", () => {
          if (isApplying) return;
          const nextValue = field.textarea.value || "";
          if (activeSet === "update") {
            promptSets.update[field.key] = nextValue;
            updateOverrides[field.key] = true;
            if (field.updateInput) {
              field.updateInput.value = nextValue;
            }
          } else {
            promptSets.create[field.key] = nextValue;
          }
        });
        field.textarea.dataset.aipkitPromptModeListener = "true";
      }
    });

    scopeElement.dataset.aipkitPromptModeInit = "true";
    setMode(getActiveMode());
  };

  const applyPromptSets = (config) => {
    if (!config || typeof config !== "object") {
      return;
    }
    const hasOwn = (key) => Object.prototype.hasOwnProperty.call(config, key);

    promptElements.forEach((field) => {
      const key = field.key;
      const updateKey = `${key}_update`;

      if (hasOwn(key)) {
        promptSets.create[key] = config[key] || "";
      }

      if (hasOwn(updateKey)) {
        const updateValue = config[updateKey] || "";
        promptSets.update[key] = updateValue;
        updateOverrides[key] = Boolean(updateValue);
        if (field.updateInput) {
          field.updateInput.value = updateOverrides[key] ? updateValue : "";
        }
      }
    });

    applyToUI();
  };

  const getPromptSets = () => ({
    create: { ...promptSets.create },
    update: { ...promptSets.update },
  });

  window.aipkit_initContentWriterPromptModeManager = init;
  window.aipkit_setContentWriterPromptMode = setMode;
  window.aipkit_applyContentWriterPromptSets = applyPromptSets;
  window.aipkit_getContentWriterPromptSets = getPromptSets;
})();
