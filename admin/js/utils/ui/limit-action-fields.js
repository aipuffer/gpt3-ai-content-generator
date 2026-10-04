export function syncLimitActionRow(
  row,
  {
    dependentFieldsInParent = false,
    requireDependentFields = false,
    updateLayoutDataset = false,
  } = {}
) {
  if (!row) {
    return;
  }

  const typeSelect = row.querySelector('select[name$="_action_type"]');
  const actionKey = String(row.dataset.aipkitLimitActionRow || "").trim();
  const container = row.parentElement;
  const getDependentField = (fieldName) => {
    if (dependentFieldsInParent) {
      return actionKey && container
        ? container.querySelector(
            `[data-aipkit-limit-action-dependent-for="${actionKey}"][data-aipkit-limit-action-field="${fieldName}"]`
          )
        : null;
    }
    return row.querySelector(
      `[data-aipkit-limit-action-field="${fieldName}"]`
    );
  };

  const labelField = getDependentField("label");
  const urlField = getDependentField("url");
  const labelInput =
    labelField?.querySelector('input[name$="_action_label"]') ||
    row.querySelector('input[name$="_action_label"]');
  const urlInput =
    urlField?.querySelector('input[name$="_action_url"]') ||
    row.querySelector('input[name$="_action_url"]');

  if (!typeSelect || (requireDependentFields && (!labelField || !urlField))) {
    return;
  }

  const actionType = String(typeSelect.value || "").trim();
  const selectedOption =
    typeSelect.options && typeSelect.selectedIndex >= 0
      ? typeSelect.options[typeSelect.selectedIndex]
      : null;
  const defaultLabel = selectedOption?.dataset?.defaultLabel || "";
  const showLabel = actionType !== "none";
  const showUrl = actionType === "custom_url";

  if (labelField) {
    labelField.hidden = !showLabel;
  }
  if (urlField) {
    urlField.hidden = !showUrl;
  }

  if (updateLayoutDataset) {
    row.dataset.aipkitLimitActionLayout = showUrl
      ? "type-label-url"
      : showLabel
        ? "type-label"
        : "type-only";
  }

  if (labelInput) {
    labelInput.placeholder = defaultLabel;
  }

  const notice = row.querySelector("[data-aipkit-limit-action-notice]");
  if (notice) {
    let messageKey = "";
    const dashboardActionTypes = [
      "dashboard_usage",
      "dashboard_credits",
      "dashboard_purchases",
    ];

    if (
      actionType === "buy_credits" &&
      notice.dataset.buyCreditsReady !== "true"
    ) {
      messageKey = "buy_credits";
    } else if (
      dashboardActionTypes.includes(actionType) &&
      notice.dataset.dashboardReady !== "true"
    ) {
      messageKey = "dashboard";
    } else if (
      actionType === "custom_url" &&
      (!urlInput || !urlInput.value.trim() || !urlInput.checkValidity())
    ) {
      messageKey = "custom_url";
    }

    notice
      .querySelectorAll("[data-aipkit-limit-action-message]")
      .forEach((message) => {
        message.hidden =
          message.dataset.aipkitLimitActionMessage !== messageKey;
      });

    const settingsLink = notice.querySelector(
      "[data-aipkit-limit-action-settings-link]"
    );
    if (settingsLink) {
      settingsLink.hidden =
        !messageKey || messageKey === "custom_url";
    }
    notice.hidden = !messageKey;
  }

  if (notice && urlInput && urlInput.dataset.aipkitLimitNoticeBound !== "true") {
    urlInput.addEventListener("input", () => {
      syncLimitActionRow(row, {
        dependentFieldsInParent,
        requireDependentFields,
        updateLayoutDataset,
      });
    });
    urlInput.dataset.aipkitLimitNoticeBound = "true";
  }
}
