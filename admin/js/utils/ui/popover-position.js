export function positionPopoverBelowTrigger(
  trigger,
  panel,
  { fallbackWidth = 320 } = {}
) {
  if (!trigger || !panel) return;

  const triggerRect = trigger.getBoundingClientRect();
  const panelRect = panel.getBoundingClientRect();
  const panelWidth = panelRect.width || fallbackWidth;
  const panelHeight = panelRect.height || 0;
  const viewportWidth = window.innerWidth;
  const viewportHeight = window.innerHeight;
  const gutter = 12;

  let left = triggerRect.left;
  if (left + panelWidth > viewportWidth - gutter) {
    left = triggerRect.right - panelWidth;
  }
  left = Math.max(gutter, Math.min(left, viewportWidth - panelWidth - gutter));

  let top = triggerRect.bottom + 8;
  if (top + panelHeight > viewportHeight - gutter) {
    top = triggerRect.top - panelHeight - 8;
  }
  top = Math.max(gutter, Math.min(top, viewportHeight - panelHeight - gutter));

  panel.style.left = `${Math.round(left)}px`;
  panel.style.top = `${Math.round(top)}px`;
}

export function getFixedDropdownPanelMetrics(
  trigger,
  panel,
  panelWidth,
  { viewportPadding = 12, verticalGap = 6, horizontalAlign = "end" } = {}
) {
  if (!trigger || !panel || !Number.isFinite(panelWidth)) return null;

  const triggerRect = trigger.getBoundingClientRect();
  const panelHeight = panel.getBoundingClientRect().height || panel.scrollHeight || 0;
  const availableBelow =
    window.innerHeight - triggerRect.bottom - viewportPadding;
  const availableAbove = triggerRect.top - viewportPadding;
  const shouldDropUp =
    panelHeight > availableBelow && availableAbove > availableBelow;

  let left =
    horizontalAlign === "start"
      ? triggerRect.left
      : triggerRect.right - panelWidth;
  if (left + panelWidth > window.innerWidth - viewportPadding) {
    left = window.innerWidth - viewportPadding - panelWidth;
  }
  if (left < viewportPadding) {
    left = viewportPadding;
  }

  let top = shouldDropUp
    ? triggerRect.top - panelHeight - verticalGap
    : triggerRect.bottom + verticalGap;

  if (top + panelHeight > window.innerHeight - viewportPadding) {
    top = window.innerHeight - viewportPadding - panelHeight;
  }
  if (top < viewportPadding) {
    top = viewportPadding;
  }

  return {
    left,
    top,
    width: panelWidth,
    minWidth: panelWidth,
    maxWidth: panelWidth,
    shouldDropUp,
  };
}

export function positionSelectPickerSidePanel(trigger, panel) {
  if (!trigger || !panel) return;
  if (trigger.dataset.aipkitPopoverPlacement === "bottom") {
    positionPopoverBelowTrigger(trigger, panel);
    return;
  }

  const triggerRect = trigger.getBoundingClientRect();
  const panelRect = panel.getBoundingClientRect();
  const panelWidth = panelRect.width || 320;
  const panelHeight = panelRect.height || 0;
  const viewportWidth = window.innerWidth;
  const viewportHeight = window.innerHeight;
  const gutter = 12;

  let left = triggerRect.right + 10;
  if (left + panelWidth > viewportWidth - gutter) {
    left = triggerRect.left - panelWidth - 10;
  }
  left = Math.max(gutter, Math.min(left, viewportWidth - panelWidth - gutter));

  let top = triggerRect.top;
  if (top + panelHeight > viewportHeight - gutter) {
    top = viewportHeight - panelHeight - gutter;
  }
  top = Math.max(gutter, top);

  panel.style.left = `${Math.round(left)}px`;
  panel.style.top = `${Math.round(top)}px`;
}

export function positionSettingsPopover(trigger, panel) {
  if (!trigger || !panel) return;

  const triggerRect = trigger.getBoundingClientRect();
  const panelRect = panel.getBoundingClientRect();
  const panelWidth = panelRect.width || 400;
  const panelHeight = panelRect.height || 0;
  const viewportWidth = window.innerWidth;
  const viewportHeight = window.innerHeight;
  const gutter = 12;
  const placement = trigger.dataset.aipkitPopoverPlacement || "";
  let left;
  let top;

  if (placement === "bottom") {
    left = triggerRect.left;
    if (left + panelWidth > viewportWidth - gutter) {
      left = triggerRect.right - panelWidth;
    }
    top = triggerRect.bottom + 8;
    if (top + panelHeight > viewportHeight - gutter) {
      top = triggerRect.top - panelHeight - 8;
    }
  } else if (placement === "top") {
    left = triggerRect.right - panelWidth;
    top = triggerRect.top - panelHeight - 8;
    if (top < gutter) {
      top = triggerRect.bottom + 8;
    }
  } else if (placement === "left") {
    left = triggerRect.left - panelWidth - 10;
    if (left < gutter) {
      left = triggerRect.right + 10;
    }
    top = triggerRect.top;
    if (top + panelHeight > viewportHeight - gutter) {
      top = viewportHeight - panelHeight - gutter;
    }
    top = Math.max(gutter, top);
  } else {
    left = triggerRect.right + 10;
    if (left + panelWidth > viewportWidth - gutter) {
      left = triggerRect.right - panelWidth - 10;
    }
    top = triggerRect.top;
    if (top + panelHeight > viewportHeight - gutter) {
      top = viewportHeight - panelHeight - gutter;
    }
    top = Math.max(gutter, top);
  }

  left = Math.max(gutter, Math.min(left, viewportWidth - panelWidth - gutter));
  if (placement === "bottom" || placement === "top") {
    top = Math.max(gutter, Math.min(top, viewportHeight - panelHeight - gutter));
  }

  panel.style.left = `${Math.round(left)}px`;
  panel.style.top = `${Math.round(top)}px`;
}

export function positionFixedPopoverPanel(popover, panel, panelWidth, minWidth) {
  if (!popover || !panel) return;

  const viewportPadding = 12;
  const triggerRect = popover.getBoundingClientRect();

  panel.style.position = "fixed";
  panel.style.width = `${Math.round(panelWidth)}px`;
  panel.style.minWidth = `${Math.round(Math.min(minWidth, panelWidth))}px`;
  panel.style.maxWidth = `${Math.round(panelWidth)}px`;

  const panelHeight =
    panel.getBoundingClientRect().height || panel.scrollHeight || 0;
  const isDropUp = popover.classList.contains("aipkit_settings_dropdown--dropup");

  let top = isDropUp
    ? triggerRect.top - panelHeight - 6
    : triggerRect.bottom + 6;

  if (top + panelHeight > window.innerHeight - viewportPadding) {
    top = window.innerHeight - viewportPadding - panelHeight;
  }
  if (top < viewportPadding) {
    top = viewportPadding;
  }

  let left = triggerRect.left;
  if (left + panelWidth > window.innerWidth - viewportPadding) {
    left = window.innerWidth - viewportPadding - panelWidth;
  }
  if (left < viewportPadding) {
    left = viewportPadding;
  }

  panel.style.left = `${Math.round(left)}px`;
  panel.style.top = `${Math.round(top)}px`;
  panel.style.bottom = "auto";
}

export function resetPopoverPanelPosition(
  panel,
  properties = [
    "position",
    "left",
    "right",
    "top",
    "bottom",
    "width",
    "max-width",
    "min-width",
  ]
) {
  if (!panel) return;

  properties.forEach((property) => panel.style.removeProperty(property));
}

export function updatePopoverDropupPlacement(
  popover,
  panel,
  { trigger = null, dropupClass = "aipkit_settings_dropdown--dropup", viewportPadding = 12 } = {}
) {
  if (!popover || !panel) return false;

  popover.classList.remove(dropupClass);

  const triggerElement = trigger || popover;
  const triggerRect = triggerElement.getBoundingClientRect();
  const panelHeight =
    panel.getBoundingClientRect().height || panel.scrollHeight || 0;
  const availableBelow = window.innerHeight - triggerRect.bottom - viewportPadding;
  const availableAbove = triggerRect.top - viewportPadding;
  const shouldDropUp = panelHeight > availableBelow && availableAbove > availableBelow;

  if (shouldDropUp) {
    popover.classList.add(dropupClass);
  }

  return shouldDropUp;
}
