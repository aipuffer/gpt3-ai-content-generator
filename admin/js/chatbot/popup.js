/** Popup and welcome-hint controls, visibility and saved settings. */
import { bindChatbotSettingsAutosave } from "./state.js";
const POPUP_LABEL_FIELDS = [
  "popup_label_text",
  "popup_label_mode",
  "popup_label_size",
  "popup_label_delay_seconds",
  "popup_label_auto_hide_seconds",
  "popup_label_dismissible",
  "popup_label_show_on_desktop",
  "popup_label_show_on_mobile",
  "popup_label_frequency",
  "popup_label_version",
];

export function createChatbotPopup({
  builder,
  popupSettingsPanel,
  appearance,
  closeStaticInlineSettingsRow,
}) {
  const { syncQuickDesignShortcuts } = appearance;

  const updatePopupSettingsVisibility = () => {
    if (!popupSettingsPanel) {
      return;
    }

    appearance.syncMediaVisibility();

    const hintToggle = popupSettingsPanel.querySelector(
      'select[name="popup_label_enabled"]'
    );
    const showHint = hintToggle
      ? hintToggle.tagName === "SELECT"
        ? hintToggle.value === "1"
        : hintToggle.checked
      : false;
    builder
      .querySelectorAll(".aipkit_popup_hint_toggle_checkbox")
      .forEach((hintCheckbox) => {
        hintCheckbox.checked = showHint;
      });
    builder
      .querySelectorAll(".aipkit_widget_launcher_message")
      .forEach((messageControl) => {
        const messageInput = messageControl.querySelector(
          'input[name="popup_label_text"]'
        );
        messageControl.classList.toggle("is-disabled", !showHint);
        if (messageInput) {
          messageInput.disabled = !showHint;
        }
      });
    const hintConfigButtons = builder.querySelectorAll(
      ".aipkit_display_settings_row--welcome [data-aipkit-static-inline-settings-toggle]"
    );
    hintConfigButtons.forEach((hintConfigBtn) => {
      hintConfigBtn.hidden = false;
      hintConfigBtn.disabled = !showHint;
      if (!showHint) {
        hintConfigBtn.setAttribute("aria-expanded", "false");
      }
    });
    if (!showHint) {
      popupSettingsPanel
        .querySelectorAll(".aipkit_display_settings_row--welcome")
        .forEach((row) => closeStaticInlineSettingsRow(row));
    }
    popupSettingsPanel
      .querySelectorAll(".aipkit_popup_hint_behavior_row")
      .forEach((row) => {
        row.removeAttribute("hidden");
        row.classList.toggle("is-disabled", !showHint);
        row
          .querySelectorAll(".aipkit_popup_hint_enabled_copy")
          .forEach((element) => {
            element.hidden = !showHint;
          });
        row
          .querySelectorAll(".aipkit_popup_hint_disabled_copy")
          .forEach((element) => {
            element.hidden = showHint;
          });
      });
    const hintRow = popupSettingsPanel.querySelector(".aipkit_popup_hint_conditional_row");
    if (hintRow) {
      if (showHint) {
        hintRow.removeAttribute("hidden");
      } else {
        hintRow.setAttribute("hidden", "");
      }
    }
  };

  const bindPersistence = (persistence) => {
    if (!popupSettingsPanel) return;
    const findPopupField = (selector) =>
      popupSettingsPanel.querySelector(selector) || builder.querySelector(selector);
    const media = [
      ["popup_icon", "popup_icon_custom_url"],
      ["header_avatar", "header_avatar_url"],
    ];
    const getPopupSettings = () => {
      const settings = {};
      const collectFields = (names, toggles = []) => {
        for (const name of names) {
          const field = findPopupField(`[name="${name}"]`);
          if (!field) continue;
          settings[name] = toggles.includes(name)
            ? (field.tagName === "SELECT" ? field.value === "1" : field.checked) ? "1" : "0"
            : field.value;
        }
      };
      collectFields(["popup_position", "popup_delay", "popup_icon_style", "popup_icon_size"]);
      for (const [prefix, urlName] of media) {
        const radio = findPopupField(`[name="${prefix}_default"]:checked`);
        if (radio) {
          const type = radio.value === "__custom__" ? "custom" :
            prefix === "header_avatar" && radio.value === "__inherit__" ? "inherit" : "default";
          settings[`${prefix}_type`] = type;
          if (type === "default") settings[`${prefix}_default`] = radio.value;
        }
        collectFields([urlName]);
      }
      collectFields(["header_online_text"]);
      collectFields(["popup_label_enabled", ...POPUP_LABEL_FIELDS], [
        "popup_label_enabled", "popup_label_dismissible", "popup_label_show_on_desktop", "popup_label_show_on_mobile",
      ]);
      return settings;
    };

    const savedSettings = (settings, response) => {
      const saved = { ...settings };
      for (const [prefix, urlName] of media) {
        const type = settings[`${prefix}_type`];
        delete saved[`${prefix}_default`];
        if (prefix === "popup_icon") delete saved[urlName];
        // PHP stores a canonical value, not the selected radio or URL field.
        if (type || urlName in settings) {
          const valueName = `${prefix}_value`;
          const normalized = response?.bot?.settings;
          if (normalized && valueName in normalized) saved[valueName] = normalized[valueName];
          else if (type) saved[valueName] = type === "custom" ? settings[urlName] || "" :
            settings[`${prefix}_default`] || "chat-bubble";
        }
      }
      return saved;
    };
    const restoreSettings = (settings) => {
      const fields = { ...settings };
      for (const [prefix] of media) {
        const type = settings[`${prefix}_type`];
        fields[`${prefix}_default`] = type === "default" ?
          settings[`${prefix}_default`] : type ? `__${type}__` : "";
      }
      for (const [name, value] of Object.entries(fields)) {
        const selector = `[name="${name}"]`, field = findPopupField(selector);
        if (!field) continue;
        if (field.type === "radio") {
          const scope = popupSettingsPanel.querySelector(selector) ? popupSettingsPanel : builder;
          scope.querySelectorAll(selector).forEach(radio => { radio.checked = radio.value === value; });
        } else if (field.type === "checkbox") field.checked = value === "1";
        else field.value = value;
      }
    };
    const resetSettings = (settings, response) => {
      const saved = response.bot.settings;
      const next = Object.fromEntries(Object.entries(settings).map(([name, value]) =>
        [name, String(saved[name] ?? value)]));
      for (const [prefix, urlName] of media) {
        const type = saved[`${prefix}_type`];
        if (!type || !(`${prefix}_type` in settings)) continue;
        delete next[`${prefix}_default`];
        if (type === "default") next[`${prefix}_default`] = String(saved[`${prefix}_value`] ?? "chat-bubble");
        if (urlName in settings) next[urlName] = type === "custom" ?
          String(saved[urlName] ?? saved[`${prefix}_value`] ?? "") : "";
      }
      return next;
    };
    const popupLabelSettingsSelector = POPUP_LABEL_FIELDS
      .map((name) => `[name="${name}"]`)
      .join(", ");
    const isPopupLabelSettingsTarget = (target) =>
      Boolean(target && target.matches(popupLabelSettingsSelector));
    const syncPopupHintSelectFromCheckbox = (target) => {
      const hintSelect = popupSettingsPanel.querySelector(
        'select[name="popup_label_enabled"]'
      );
      if (!hintSelect) {
        return;
      }
      const nextValue = target.checked ? "1" : "0";
      if (hintSelect.value !== nextValue) {
        hintSelect.value = nextValue;
        hintSelect.dispatchEvent(new Event("change", { bubbles: true }));
      } else {
        updatePopupSettingsVisibility();
      }
    };

    const panelFields = ["popup_position", "popup_delay", "popup_icon_style", "popup_icon_size",
      "popup_icon_default", "popup_icon_custom_url", "header_avatar_default", "header_avatar_url",
      "header_online_text", "popup_label_enabled", ...POPUP_LABEL_FIELDS];
    const isSettingsTarget = target => target && (popupSettingsPanel.contains(target)
      ? panelFields.includes(target.name) : isPopupLabelSettingsTarget(target));
    const syncPopupSettingsUiState = () => {
      updatePopupSettingsVisibility();
      syncQuickDesignShortcuts();
    };
    bindChatbotSettingsAutosave({
      builder, panel: popupSettingsPanel, boundKey: "popupSettingsAutosaveBound", persistence,
      action: "aipkit_update_chatbot_popup_settings", readSettings: getPopupSettings,
      isUnchanged: (a, b) => JSON.stringify(a) === JSON.stringify(b),
      canSave: settings => Object.keys(settings).length > 0,
      savedSettings, restoreSettings, resetSettings, afterHydrate: syncPopupSettingsUiState,
      interactionNodes: () => [popupSettingsPanel,
        ...builder.querySelectorAll('.aipkit_popup_hint_toggle_checkbox, .aipkit_widget_launcher_message, [data-aipkit-avatar-quick-upload], [data-aipkit-avatar-use-widget], [data-aipkit-widget-icon-upload], input[name="aipkit_widget_icon_quick"]'),
        ...POPUP_LABEL_FIELDS.map(name => findPopupField(`[name="${name}"]`))],
      bindEvents: ({ signal, isEditable, draft, save }) => {
        const onChange = (event) => {
          const target = event.target;
          if (!isEditable() || !target) return;
          if (target.matches(".aipkit_popup_hint_toggle_checkbox")) {
            syncPopupHintSelectFromCheckbox(target);
            return;
          }
          if (!isSettingsTarget(target)) return;
          if (popupSettingsPanel.contains(target)) {
            if (target.matches('[name="popup_icon_default"], select[name="popup_icon_style"], [name="header_avatar_default"], .aipkit_popup_hint_toggle_switch')) {
              syncPopupSettingsUiState();
            } else if (target.matches('[name="popup_icon_custom_url"], [name="header_avatar_url"]')) {
              syncQuickDesignShortcuts();
            }
          }
          save();
        };
        popupSettingsPanel.addEventListener("change", onChange, { signal });
        builder.addEventListener("change", event => {
          if (!popupSettingsPanel.contains(event.target)) onChange(event);
        }, { signal });
        const onInput = event => { if (isEditable() && isSettingsTarget(event.target)) draft(); };
        popupSettingsPanel.addEventListener("input", onInput, { signal });
        builder.addEventListener("input", event => {
          if (!popupSettingsPanel.contains(event.target)) onInput(event);
        }, { signal });
      },
    });
    return syncPopupSettingsUiState;
  };

  return { updatePopupSettingsVisibility, bindPersistence };
}
