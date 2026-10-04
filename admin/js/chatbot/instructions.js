import { bindChatbotSettingsAutosave } from "./state.js";

/** Inline and expanded instructions share one draft; blur or close saves it. */
export function bindChatbotInstructions({
  builder, instructionsField, instructionsExpandBtn, instructionsModal,
  instructionsModalCloseBtn, instructionsModalTextarea, instructionsModalCount,
  saveStatus, persistence,
}) {
  if (!instructionsField || !saveStatus) return;
  let focusTimer;
  const cancelFocus = () => { clearTimeout(focusTimer); focusTimer = null; };
  const updateCount = () => {
    if (instructionsModalCount && instructionsModalTextarea) {
      instructionsModalCount.textContent = `${instructionsModalTextarea.value.length.toLocaleString()} characters`;
    }
  };
  const syncModal = () => {
    cancelFocus();
    if (instructionsModalTextarea) instructionsModalTextarea.value = instructionsField.value;
    updateCount();
  };
  const hideModal = () => {
    cancelFocus();
    instructionsModal?.classList.remove("aipkit-active");
    instructionsModal?.setAttribute("aria-hidden", "true");
  };
  bindChatbotSettingsAutosave({
    builder, panel: builder, boundKey: "instructionsAutosaveBound",
    action: "aipkit_update_chatbot_instructions", persistence,
    readSettings: () => ({ instructions: instructionsField.value }),
    isUnchanged: (a, b) => a.instructions === b.instructions,
    savedSettings: (settings, response) => ({
      instructions: response?.bot?.settings?.instructions ?? settings.instructions,
    }),
    restoreSettings: settings => { instructionsField.value = settings.instructions; },
    afterHydrate: syncModal,
    interactionNodes: () => [instructionsField, instructionsModalTextarea, instructionsExpandBtn],
    bindEvents: ({ signal, isEditable, draft, save }) => {
      const options = { signal };
      instructionsField.addEventListener("input", draft, options);
      instructionsField.addEventListener("blur", save, options);
      instructionsExpandBtn?.addEventListener("click", () => {
        if (!isEditable() || !instructionsModal || !instructionsModalTextarea) return;
        syncModal();
        instructionsModal.classList.add("aipkit-active");
        instructionsModal.setAttribute("aria-hidden", "false");
        focusTimer = setTimeout(() => {
          focusTimer = null;
          if (isEditable() && instructionsModal.classList.contains("aipkit-active")) instructionsModalTextarea.focus();
        }, 0);
      }, options);
      instructionsModalTextarea?.addEventListener("input", () => {
        if (!isEditable()) return;
        instructionsField.value = instructionsModalTextarea.value;
        updateCount();
        draft();
      }, options);
      const close = () => { hideModal(); save(); };
      instructionsModalCloseBtn?.addEventListener("click", close, options);
      builder.ownerDocument.addEventListener("keydown", event => {
        if (event.key === "Escape" && instructionsModal?.classList.contains("aipkit-active")) close();
      }, options);
      signal.addEventListener("abort", hideModal, { once: true });
    },
  });
}
