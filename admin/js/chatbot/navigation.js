/** Active Chatbot selection and switch fallback coordination. */

import { bindChatbotActions } from "./actions.js";

const bindings = new WeakMap();

export function bindChatbotNavigation(options) {
  const {
    builder, botSelect, layout, setSaveStatus, consumeBotSwitchPreviewOptions,
    switchToBotState, primeBotSwitchStateCache, getBotSwitchFailure, storageKey,
  } = options;
  const actions = bindChatbotActions(options);
  if (!actions || bindings.get(builder) === actions) return;
  bindings.set(builder, actions);
  const triggerCreateNewBot = actions.openCreate;
  let selectionVersion = 0;
  let retryTimer = null;
  actions.signal.addEventListener("abort", () => window.clearTimeout(retryTimer), { once: true });

  if (botSelect) {
    let lastSelectedBotId = botSelect.value || "";

    botSelect.addEventListener("change", async () => {
      const version = ++selectionVersion;
      // Clear stale loading overlays from async saves started on a different bot.
      setSaveStatus("", "");

      const botId = botSelect.value;
      if (!botId) {
        return;
      }

      if (botId === "__new__") {
        if (typeof triggerCreateNewBot === "function") {
          triggerCreateNewBot();
        }
        const fallbackBotId =
          lastSelectedBotId ||
          builder.getAttribute("data-active-bot-id") ||
          "";
        if (fallbackBotId) {
          botSelect.value = fallbackBotId;
        } else {
          const firstRealOption = Array.from(botSelect.options).find(
            (option) =>
              option.value && option.value !== "__new__" && !option.disabled
          );
          if (firstRealOption) {
            botSelect.value = firstRealOption.value;
            lastSelectedBotId = firstRealOption.value;
          }
        }

        return;
      }

      lastSelectedBotId = botId;

      const requestedPreviewOptions = consumeBotSwitchPreviewOptions();

      try {
        localStorage.setItem(storageKey, botId);
      } catch (error) {
        console.warn(
          "AIPKit Chat Builder: Failed to store selected chatbot:",
          error
        );
      }

      let switched = false;
      try {
        switched = await switchToBotState(botId, {
          previewOptions: requestedPreviewOptions,
        });
      } catch (error) {
        switched = false;
      }

      if (actions.signal.aborted || version !== selectionVersion || botSelect.value !== botId) return;

      if (!switched) {
        const { reason, error: lastBotSwitchFailureError } = getBotSwitchFailure();
        const switchFailureReason = String(reason || "");
        if (
          switchFailureReason === "selection_changed" ||
          switchFailureReason === "invalid_bot"
        ) {
          return;
        }
        if (switchFailureReason === "switch_in_progress") {
          window.clearTimeout(retryTimer);
          retryTimer = window.setTimeout(() => {
            retryTimer = null;
            if (actions.signal.aborted || !builder.isConnected) return;
            const retryBotId = String(botSelect?.value || botId || "").trim();
            if (
              !retryBotId ||
              retryBotId === "__new__" ||
              retryBotId !== String(botSelect?.value || "").trim()
            ) {
              return;
            }
            switchToBotState(retryBotId, {
              previewOptions: requestedPreviewOptions,
            }).catch(() => {});
          }, 0);
          return;
        }
        if (lastBotSwitchFailureError) {
          console.error(
            "AIPKit Chat Builder: Bot switch failed, falling back to full module reload.",
            {
              botId,
              reason: switchFailureReason || "unknown",
              error: lastBotSwitchFailureError,
            }
          );
        }
      }

      if (!switched && typeof window.aipkit_loadModule === "function") {
        if (typeof window.aipkit_invalidateModuleCache === "function") {
          window.aipkit_invalidateModuleCache("chatbot");
        }
        window.aipkit_loadModule("chatbot", {
          force_active_bot_id: botId,
          layout: layout,
          aipkit_silent: true,
          aipkit_force_refresh: true,
        });
      }
    }, { signal: actions.signal });

    primeBotSwitchStateCache();
  }

}
