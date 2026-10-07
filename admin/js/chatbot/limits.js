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
  const generalUserLimitField = limitsContainer.querySelector(
    ".aipkit_token_general_user_limit_field"
  );
  const roleLimitsContainer = limitsContainer.querySelector(
    ".aipkit_token_role_limits_container"
  );

  if (!modeSelect || !generalUserLimitField || !roleLimitsContainer) {
    return;
  }

  // By role swaps the one logged-in limit for the role list.
  const mode = modeSelect.value;
  generalUserLimitField.hidden = mode !== "general";
  roleLimitsContainer.hidden = mode !== "role_based";

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
  persistence,
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
