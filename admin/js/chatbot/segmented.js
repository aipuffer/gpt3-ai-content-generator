/**
 * Segmented buttons that set a saved field (a select or number input); the field stays the source of truth.
 * A group may add a Custom button: it reveals the field itself, which also shows whenever the saved
 * value matches no preset. A group marked data-aipkit-segmented-options draws its buttons from its
 * select's options instead, so they follow options other scripts change (thinking levels differ by model).
 */

// Number fields may hold "1" or "1.0"; compare them as numbers so presets still match.
const sameValue = (a, b) => {
  const left = String(a ?? "").trim();
  const right = String(b ?? "").trim();
  if (left !== "" && right !== "" && !Number.isNaN(Number(left)) && !Number.isNaN(Number(right))) {
    return Number(left) === Number(right);
  }
  return left === right;
};

export function bindChatbotSegmented(builder) {
  if (builder.dataset.segmentedBound) {
    return;
  }
  builder.dataset.segmentedBound = "1";

  const groups = () => Array.from(builder.querySelectorAll("[data-aipkit-segmented-for]"));
  const fieldFor = (group) => document.getElementById(group.dataset.aipkitSegmentedFor);
  const customFieldFor = (group) => group.parentElement?.querySelector("[data-aipkit-segmented-custom-field]") || null;

  // Scripts replace a select's options without events, so watch the selects that groups draw from.
  const observed = new WeakSet();
  const optionsObserver = new MutationObserver(() => {
    if (!builder.isConnected) {
      optionsObserver.disconnect();
      return;
    }
    sync();
  });
  const drawOptions = (group, field) => {
    if (!group.hasAttribute("data-aipkit-segmented-options") || field.tagName !== "SELECT") {
      return;
    }
    if (!observed.has(field)) {
      observed.add(field);
      optionsObserver.observe(field, { childList: true });
    }
    let labels = {};
    try {
      labels = JSON.parse(group.dataset.aipkitSegmentedLabels || "{}");
    } catch (error) {
      labels = {};
    }
    // Option texts may be short codes; the group names them, falling back to the text itself.
    const options = Array.from(field.options).map((option) => {
      const text = option.textContent.trim();
      return [option.value, labels[text.toLowerCase()] || text];
    });
    const signature = JSON.stringify(options);
    if (group.dataset.aipkitSegmentedDrawn === signature) {
      return;
    }
    group.dataset.aipkitSegmentedDrawn = signature;
    if (options.length === 1) {
      // A single choice is not a choice: show it as text.
      const fixed = document.createElement("span");
      fixed.className = "aipkit_segmented_fixed";
      fixed.dataset.label = options[0][1];
      fixed.textContent = (group.dataset.aipkitSegmentedFixed || "%s").replace("%s", options[0][1]);
      group.replaceChildren(fixed);
      return;
    }
    group.replaceChildren(...options.map(([value, label]) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "aipkit_segmented_option";
      button.dataset.value = value;
      button.setAttribute("aria-pressed", "false");
      button.textContent = label;
      return button;
    }));
  };

  const sync = () => {
    groups().forEach((group) => {
      const field = fieldFor(group);
      if (!field) {
        return;
      }
      drawOptions(group, field);
      const presets = Array.from(group.querySelectorAll("[data-value]"));
      const matched = presets.find((option) => sameValue(option.dataset.value, field.value));
      const customButton = group.querySelector("[data-custom]");
      const showCustom = Boolean(customButton) && (group.dataset.customOpen === "1" || !matched);
      presets.forEach((option) => {
        option.setAttribute("aria-pressed", !showCustom && option === matched ? "true" : "false");
      });
      customButton?.setAttribute("aria-pressed", showCustom ? "true" : "false");
      const customField = customFieldFor(group);
      if (customField) {
        customField.hidden = !showCustom;
      }
    });
  };

  builder.addEventListener("click", (event) => {
    const option = event.target.closest("[data-aipkit-segmented-for] :is([data-value], [data-custom])");
    if (!option) {
      return;
    }
    const group = option.closest("[data-aipkit-segmented-for]");
    const field = fieldFor(group);
    if (!field || field.disabled) {
      return;
    }
    if (option.hasAttribute("data-custom")) {
      // Custom keeps the current value and hands over to the field.
      group.dataset.customOpen = "1";
      sync();
      customFieldFor(group)?.querySelector("input, select")?.focus();
      return;
    }
    delete group.dataset.customOpen;
    if (!sameValue(field.value, option.dataset.value)) {
      field.value = option.dataset.value;
      // Autosave drafts on input and saves on change, as when someone types in the field.
      field.dispatchEvent(new Event("input", { bubbles: true }));
      field.dispatchEvent(new Event("change", { bubbles: true }));
    }
    sync();
  });

  // Typing a number that happens to match a preset keeps Custom open rather than hiding the field mid-edit.
  // Preset clicks fire synthetic input events, which must not reopen Custom.
  const keepCustomOpen = (event) => {
    if (!event.isTrusted) {
      return;
    }
    const container = event.target.closest?.("[data-aipkit-segmented-custom-field]");
    const group = container?.parentElement?.querySelector("[data-aipkit-segmented-for]");
    if (group) {
      group.dataset.customOpen = "1";
    }
  };
  builder.addEventListener("input", keepCustomOpen, true);
  // A script setting the saved field (a reset, a model limit) starts its group from the new value,
  // as loading another bot does, so a matching preset shows instead of an open Custom field.
  const followScriptedValue = (event) => {
    if (event.isTrusted) {
      return;
    }
    groups().forEach((group) => {
      if (fieldFor(group) === event.target) {
        delete group.dataset.customOpen;
      }
    });
  };
  builder.addEventListener("input", followScriptedValue, true);
  builder.addEventListener("change", followScriptedValue, true);
  builder.addEventListener("change", sync);
  builder.addEventListener("input", sync);
  // Loading another bot sets fields without an event, so resync after bot state lands too,
  // starting each bot from its own value rather than an open Custom field.
  builder.addEventListener("aipkit:bot-state-applied", () => {
    groups().forEach((group) => delete group.dataset.customOpen);
    sync();
  });
  sync();
}
