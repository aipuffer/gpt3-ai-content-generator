/**
 * AIPKit Settings - WordPress AI Client Connector Control
 */
(function () {
  "use strict";

  const __ = window.wp?.i18n?.__ || ((text) => text);
  const MESSAGE_CONTAINER_ID = "aipkit_settings_global_messages";

  function showMessage(type, message) {
    if (typeof window.aipkit_showMessage === "function") {
      window.aipkit_showMessage(MESSAGE_CONTAINER_ID, type, message);
      return;
    }

    if (type === "error") {
      console.error(message);
    }
  }

  function setBusy(control, busy) {
    control.querySelectorAll("[data-aipkit-wpai-toggle]").forEach((element) => {
      if ("disabled" in element) {
        element.disabled = busy;
      } else {
        if (busy) {
          element.setAttribute("aria-disabled", "true");
        } else {
          element.removeAttribute("aria-disabled");
        }
        element.style.pointerEvents = busy ? "none" : "";
      }
    });
  }

  function setAutosaveBusy(busy, control) {
    if (typeof window.aipkit_setSettingsAutosaveBusy === "function") {
      window.aipkit_setSettingsAutosaveBusy(busy, control.closest("[data-aipkit-settings-page]"));
    }
  }

  // The row and the panel header follow the switch: On, or Off.
  function updateControl(control, mode) {
    const managed = mode === "managed";
    const toggle = control.querySelector("[data-aipkit-wpai-toggle]");

    control.dataset.mode = managed ? "managed" : "observe";
    control.dataset.aipkitProviderConnected = managed ? "true" : "false";

    if (toggle) {
      toggle.checked = managed;
    }
    const summary = control.querySelector("[data-aipkit-developer-summary]");
    if (summary) {
      summary.textContent = (managed ? summary.dataset.on : summary.dataset.off) || summary.textContent;
    }
  }

  // A failure shows in the panel, by the switch; the page's message area is the fallback.
  function showError(control, message) {
    const box = control.querySelector("[data-aipkit-developer-error]");
    if (!box) {
      if (message) {
        showMessage("error", message);
      }
      return;
    }
    const text = box.querySelector("[data-aipkit-developer-error-text]");
    if (text) {
      text.textContent = message;
    }
    box.hidden = !message;
  }

  function extractErrorMessage(payload) {
    if (payload?.data?.message) {
      return String(payload.data.message);
    }
    if (payload?.message) {
      return String(payload.message);
    }
    return __("Unable to update WordPress AI connector management.", "gpt3-ai-content-generator");
  }

  async function submitMode(control, mode) {
    const ajaxUrl = control.dataset.ajaxUrl || window.ajaxurl;
    const nonce = control.dataset.nonce || "";

    if (!ajaxUrl || !nonce) {
      throw new Error(__("Connector management is not available on this screen.", "gpt3-ai-content-generator"));
    }

    const body = new URLSearchParams();
    body.set("action", "aipkit_wp_ai_client_set_mode");
    body.set("nonce", nonce);
    body.set("mode", mode);

    const response = await window.fetch(ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: body.toString(),
    });

    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload?.success) {
      throw new Error(extractErrorMessage(payload));
    }

    return payload.data || {};
  }

  async function applyMode(control, mode, previousMode) {
    setBusy(control, true);
    setAutosaveBusy(true, control);
    showError(control, "");
    try {
      const data = await submitMode(control, mode);
      updateControl(control, data.mode === "managed" ? "managed" : "observe");
    } catch (error) {
      updateControl(control, previousMode);
      showError(control, error.message || __("Unable to update WordPress AI connector management.", "gpt3-ai-content-generator"));
    } finally {
      setAutosaveBusy(false, control);
      setBusy(control, false);
    }
  }

  function bindControl(control) {
    if (!control || control.dataset.aipkitWpaiBound === "1") {
      return;
    }

    const toggle = control.querySelector("[data-aipkit-wpai-toggle]");
    if (!toggle) {
      return;
    }

    toggle.addEventListener("change", async () => {
      const previousMode = control.dataset.mode === "managed" ? "managed" : "observe";
      const mode = toggle.checked ? "managed" : "observe";
      await applyMode(control, mode, previousMode);
    });

    control.dataset.aipkitWpaiBound = "1";
  }

  function aipkit_initWpAiClientControl() {
    document.querySelectorAll("[data-aipkit-wpai-control]").forEach(bindControl);
  }

  window.aipkit_initWpAiClientControl = aipkit_initWpAiClientControl;
})();
