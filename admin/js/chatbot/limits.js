/** Visitor limits, action-row visibility and settings saves. */

import { bindChatbotSettingsAutosave } from "./state.js";
import { syncLimitActionRow as syncSharedLimitActionRow } from "../utils/ui/limit-action-fields.js";

export function syncChatbotLimitVisibility(limitsContainer) {
  if (!limitsContainer) {
    return;
  }
  const modeSelect = limitsContainer.querySelector(
    ".aipkit_token_limit_mode_select"
  );
  const primaryGrid = limitsContainer.querySelector(
    ".aipkit_limits_primary_grid"
  );
  const generalUserLimitField = limitsContainer.querySelector(
    ".aipkit_token_general_user_limit_field"
  );
  const roleLimitsContainer = limitsContainer.querySelector(
    ".aipkit_token_role_limits_container"
  );

  if (!modeSelect || !generalUserLimitField || !roleLimitsContainer) {
    return;
  }

  const mode = modeSelect.value;
  if (primaryGrid) {
    primaryGrid.classList.toggle(
      "aipkit_limits_primary_grid--role-based",
      mode === "role_based"
    );
  }
  generalUserLimitField.style.display = mode === "general" ? "block" : "none";
  roleLimitsContainer.style.display =
    mode === "role_based" ? "block" : "none";

  const syncLimitActionRow = (row) =>
    syncSharedLimitActionRow(row, {
      requireDependentFields: true,
      updateLayoutDataset: true,
    });

  limitsContainer
    .querySelectorAll("[data-aipkit-limit-action-row]")
    .forEach(syncLimitActionRow);
}

export function bindChatbotLimits({
  builder, limitsSettingsContainer: panel, saveStatus, updateTokenLimitVisibility,
  updateLimitsSectionSummary, persistence,
}) {
  if (!panel || !saveStatus) return;
  const names = ["token_guest_limit", "token_user_limit", "token_limit_mode",
    "token_reset_period", "token_limit_message", ...["primary", "secondary"].flatMap(slot =>
      ["type", "label", "url"].map(field => `token_limit_${slot}_action_${field}`))];
  const rolePrefix = "token_role_limits[";
  const roleInputs = () => Array.from(panel.querySelectorAll('input[name^="token_role_limits["]'));
  const isRelevant = field => field && (names.includes(field.name) || field.matches('input[name^="token_role_limits["]'));
  const readSettings = () => ({
    ...Object.fromEntries(names.map(name => [name, panel.querySelector(`[name="${name}"]`)?.value || ""])),
    ...Object.fromEntries(roleInputs().filter(field => field.getAttribute("name"))
      .map(field => [field.getAttribute("name"), field.value])),
  });
  const restoreSettings = settings => {
    for (const name of names) {
      const field = panel.querySelector(`[name="${name}"]`);
      if (field) field.value = settings[name] ?? "";
    }
    roleInputs().forEach(field => { field.value = settings[field.getAttribute("name")] ?? ""; });
  };
  const syncUi = () => {
    updateTokenLimitVisibility();
    updateLimitsSectionSummary?.();
  };
  bindChatbotSettingsAutosave({
    builder, panel, boundKey: "tokenLimitsAutosaveBound", persistence,
    action: "aipkit_update_chatbot_token_limits", readSettings,
    isUnchanged: (a, b) => JSON.stringify(a) === JSON.stringify(b),
    restoreSettings, afterHydrate: syncUi,
    // The transport uses bracketed fields; cached state uses a role map.
    savedSettings: settings => {
      const saved = {}, roles = {};
      for (const [name, value] of Object.entries(settings)) {
        if (name.startsWith(rolePrefix)) roles[name.slice(rolePrefix.length, -1)] = value;
        else saved[name] = value;
      }
      return { ...saved, token_role_limits: roles };
    },
    resetSettings: (settings, response) => Object.fromEntries(Object.entries(settings).map(([name, value]) => [name,
      String(name.startsWith(rolePrefix)
        ? response.bot.settings.token_role_limits?.[name.slice(rolePrefix.length, -1)] ?? ""
        : response.bot.settings[name] ?? value),
    ])),
    interactionNodes: () => [panel],
    bindEvents: ({ signal, isEditable, draft, save }) => {
      panel.addEventListener("input", event => {
        if (!isEditable() || !isRelevant(event.target)) return;
        draft();
      }, { signal });
      panel.addEventListener("change", event => {
        if (!isEditable() || !isRelevant(event.target)) return;
        syncUi();
        save();
      }, { signal });
    },
  });
}

/** Bind the Limits heading summary without changing saved values or saving on input. */
export function bindChatbotLimitSummary({ builder, limitsSectionSummary, __, sprintf }) {
  let disposed = false;
  const formatLimitSummaryValue = (value) => {
    const normalizedValue = String(value == null ? "" : value).trim();
    return normalizedValue || __("Unlimited", "gpt3-ai-content-generator");
  };
  const updateLimitsSectionSummary = () => {
    if (!limitsSectionSummary || disposed || !builder.isConnected) {
      return;
    }
    const fallbackSummary = limitsSectionSummary.dataset.defaultSummary || "";
    const modeSelect = builder.querySelector('[name="token_limit_mode"]');
    const modeValue = modeSelect ? String(modeSelect.value || "") : "";
    let quotaSummary = fallbackSummary;

    if (modeValue === "role_based") {
      quotaSummary = __("Role-based quota", "gpt3-ai-content-generator");
    } else if (modeValue === "general" || modeValue === "") {
      const guestLimitField = builder.querySelector('[name="token_guest_limit"]');
      const userLimitField = builder.querySelector('[name="token_user_limit"]');
      quotaSummary = sprintf(
        __("Guests %1$s · Users %2$s", "gpt3-ai-content-generator"),
        formatLimitSummaryValue(guestLimitField ? guestLimitField.value : ""),
        formatLimitSummaryValue(userLimitField ? userLimitField.value : "")
      );
    }

    const summaryText = quotaSummary || fallbackSummary;

    limitsSectionSummary.textContent = summaryText;
    limitsSectionSummary.setAttribute("title", summaryText);
  };
  if (limitsSectionSummary && builder.isConnected && !builder.dataset.limitsSectionSummaryBound) {
    const controller = new AbortController();
    const observer = new MutationObserver(() => {
      if (builder.isConnected) return;
      disposed = true;
      controller.abort();
      observer.disconnect();
      delete builder.dataset.limitsSectionSummaryBound;
    });
    const valueFields = '[name="token_guest_limit"], [name="token_user_limit"], input[name^="token_role_limits["]';
    const changeFields = `${valueFields}, [name="token_limit_mode"], [name="token_reset_period"]`;
    for (const [type, selector] of [["change", changeFields], ["input", valueFields]]) {
      builder.addEventListener(type, (event) => {
        if (event.target && event.target.matches(selector)) {
          updateLimitsSectionSummary();
        }
      }, { signal: controller.signal });
    }
    builder.dataset.limitsSectionSummaryBound = "1";
    observer.observe(builder.ownerDocument.documentElement, { childList: true, subtree: true });
  }
  return updateLimitsSectionSummary;
}
