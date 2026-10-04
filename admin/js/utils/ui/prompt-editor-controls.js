export function setPromptActionButtonVariant(button, variant) {
  if (!button) {
    return;
  }

  button.classList.remove(
    "aipkit_btn-primary",
    "aipkit_btn-secondary",
    "aipkit_btn-ghost"
  );

  switch (variant) {
    case "primary":
      button.classList.add("aipkit_btn-primary");
      break;
    case "secondary":
      button.classList.add("aipkit_btn-secondary");
      break;
    default:
      button.classList.add("aipkit_btn-ghost");
      break;
  }
}

export function getPromptEditorControls(select) {
  const toolbar = select?.closest(".aipkit_cw_prompt_editor_toolbar");
  if (!toolbar) {
    return {
      manageButton: null,
      applyButton: null,
    };
  }

  return {
    manageButton: toolbar.querySelector(".aipkit_cw_prompt_library_manage_btn"),
    applyButton: toolbar.querySelector(".aipkit_cw_prompt_library_apply_btn"),
  };
}

export function updatePromptEditorControlState(
  select,
  {
    hasPendingChanges = false,
    managerFunctionName = "aipkit_openPromptLibraryManager",
    promptLibraryApi = null,
  } = {}
) {
  if (!select) {
    return;
  }
  const { manageButton, applyButton } = getPromptEditorControls(select);
  if (manageButton) {
    setPromptActionButtonVariant(manageButton, "secondary");
    manageButton.disabled =
      !promptLibraryApi || typeof window[managerFunctionName] !== "function";
  }
  if (applyButton) {
    setPromptActionButtonVariant(applyButton, "primary");
    applyButton.hidden = !hasPendingChanges;
    applyButton.disabled = !hasPendingChanges;
  }
}

export function ensurePromptEditorControls(select) {
  const toolbar = select.closest(".aipkit_cw_prompt_editor_toolbar");
  if (!toolbar || toolbar.querySelector(".aipkit_cw_prompt_library_actions")) {
    return;
  }

  const actionsWrap = document.createElement("span");
  actionsWrap.className = "aipkit_cw_prompt_library_actions";

  const manageButton = document.createElement("button");
  manageButton.type = "button";
  manageButton.className =
    "aipkit_cw_prompt_library_manage_btn aipkit_btn aipkit_btn-ghost aipkit_btn-small";
  manageButton.textContent =
    select.dataset.aipkitPromptLibraryLabel || "Library";

  const applyButton = document.createElement("button");
  applyButton.type = "button";
  applyButton.className =
    "aipkit_cw_prompt_library_apply_btn aipkit_btn aipkit_btn-secondary aipkit_btn-small";
  applyButton.textContent = "Apply changes";
  applyButton.hidden = true;

  actionsWrap.appendChild(manageButton);
  actionsWrap.appendChild(applyButton);
  toolbar.appendChild(actionsWrap);
}

const escapePromptText = (value) =>
  String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");

export function renderPromptPlaceholderChips(
  container,
  placeholders,
  defaultLabel = "Variables:"
) {
  if (!container) return;
  const label = container.dataset.aipkitHidePlaceholderLabel === "true"
    ? ""
    : container.dataset.label || defaultLabel;
  const copyTitle = container.dataset.copyTitle || "Click to copy";

  container.innerHTML = label ? `${escapePromptText(label)} ` : "";
  placeholders.forEach((placeholder, index) => {
    const code = document.createElement("code");
    code.className = "aipkit-placeholder";
    code.setAttribute("role", "button");
    code.tabIndex = 0;
    code.setAttribute("aria-label", `Copy ${placeholder}`);
    code.title = copyTitle;
    code.textContent = placeholder;
    container.appendChild(code);
    if (index < placeholders.length - 1) {
      container.appendChild(document.createTextNode(" "));
    }
  });
}

export function createPromptLibraryOption(entry, mode) {
  const label = String(entry?.label || "").trim();
  const prompt = String(entry?.prompt || "");
  if (!label || !prompt) {
    return null;
  }

  const source = entry?.source || (entry?.is_builtin ? "builtin" : "custom");
  const option = document.createElement("option");
  option.value = prompt;
  option.textContent = source === "custom" ? `${label} (Custom)` : label;
  option.dataset.promptId = String(entry?.id || "");
  option.dataset.promptType = String(entry?.type || "");
  option.dataset.promptSource = String(source || "");
  option.dataset.promptLabel = label;
  if (mode !== undefined) {
    option.dataset.aipkitMode = mode;
  }
  return option;
}

export function appendPromptLibraryOptions(select, entries, mode) {
  if (!select || !Array.isArray(entries) || !entries.length) {
    return;
  }
  entries.forEach((entry) => {
    const option = createPromptLibraryOption(entry, mode);
    if (option) {
      select.appendChild(option);
    }
  });
}

export function getPromptTextareaForSelect(select, scopeElement = document) {
  const targetId = select?.getAttribute("data-aipkit-prompt-target");
  if (!targetId) {
    return null;
  }
  return scopeElement?.querySelector(`#${targetId}`) || null;
}

export function getPromptTypeForSelect(select, fallbackTargets = {}) {
  const editor = select?.closest(".aipkit_cw_prompt_editor");
  const placeholder = editor?.querySelector(
    ".aipkit_cw_prompt_editor_placeholders[data-prompt-type]"
  );
  if (placeholder?.dataset?.promptType) {
    return String(placeholder.dataset.promptType);
  }
  const targetId = select?.getAttribute("data-aipkit-prompt-target") || "";
  return fallbackTargets[targetId] || "";
}

export function getSelectedPromptMeta(select) {
  const selected = select?.selectedOptions?.[0];
  if (!selected) {
    return {
      promptId: "",
      promptType: "",
      label: "",
      source: "",
      isCustom: false,
      prompt: "",
    };
  }
  return {
    promptId: selected.dataset.promptId || "",
    promptType: selected.dataset.promptType || "",
    label: selected.dataset.promptLabel || selected.textContent || "",
    source: selected.dataset.promptSource || "",
    isCustom: (selected.dataset.promptSource || "") === "custom",
    prompt: String(selected.value || ""),
  };
}

export function openPromptLibraryManagerForSelect(
  select,
  textarea,
  {
    fallbackPromptType = "",
    managerFunctionName = "aipkit_openPromptLibraryManager",
  } = {}
) {
  if (typeof window[managerFunctionName] !== "function") {
    return;
  }
  const selectedMeta = getSelectedPromptMeta(select);
  const promptType =
    selectedMeta.promptType ||
    (typeof fallbackPromptType === "function"
      ? fallbackPromptType(select, selectedMeta)
      : fallbackPromptType);
  const promptId =
    selectedMeta.isCustom && selectedMeta.promptId ? selectedMeta.promptId : "";
  const label = String(selectedMeta.label || "")
    .replace(/\s+\(Custom\)\s*$/i, "")
    .trim();

  window[managerFunctionName]({
    promptType,
    promptId,
    label,
    promptText: String(textarea?.value || ""),
  });
}

export function syncPromptTextareaFromSelect(select, textarea) {
  if (!textarea) {
    return false;
  }
  const promptValue = String(select?.value || "");
  if (promptValue === textarea.value) {
    return false;
  }
  textarea.value = promptValue;
  textarea.dispatchEvent(new Event("input", { bubbles: true }));
  return true;
}

export function restorePromptLibrarySelection(
  select,
  selectedPromptId,
  selectedValue = ""
) {
  if (!select) {
    return false;
  }

  if (selectedPromptId) {
    const option = Array.from(select.options).find(
      (entry) => (entry.dataset.promptId || "") === selectedPromptId
    );
    if (option) {
      select.value = option.value;
      return true;
    }
  }

  if (!selectedValue) {
    return false;
  }

  const existing = Array.from(select.options).find(
    (option) => String(option.value || "") === selectedValue
  );
  if (existing) {
    select.value = existing.value;
    return true;
  }

  select.selectedIndex = 0;
  return false;
}
