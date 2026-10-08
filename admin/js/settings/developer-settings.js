/**
 * Settings > Developers credential controls.
 */
(function () {
  "use strict";

  const __ = window.wp?.i18n?.__ || ((text) => text);
  const MESSAGE_CONTAINER_ID = "aipkit_settings_global_messages";
  const copyFeedbackTimers = new WeakMap();

  function formatCredentialMask(value) {
    const credential = String(value || "").trim();
    if (!credential) {
      return "";
    }

    const prefixLength = credential.startsWith("aipk_live_") ? 10 : 6;
    if (credential.length <= prefixLength + 4) {
      return `${credential.slice(0, 3)}••••••••••••`;
    }

    return `${credential.slice(0, prefixLength)}••••••••••••${credential.slice(-4)}`;
  }

  // A feature's own panel: the first one in its row. Webhooks keeps its endpoints' panels after it.
  const panelOf = (group) => group?.querySelector(".aipkit_settings_provider_modal") || group;

  // Errors show in the feature's panel, where the switch or key is; the page's message area is the fallback.
  function showError(message, group = null) {
    const box = panelOf(group)?.querySelector?.("[data-aipkit-developer-error]");
    if (box) {
      const text = box.querySelector("[data-aipkit-developer-error-text]");
      if (text) {
        text.textContent = message;
      }
      box.hidden = !message;
      return;
    }
    if (!message) {
      return;
    }
    if (typeof window.aipkit_showMessage === "function") {
      window.aipkit_showMessage(MESSAGE_CONTAINER_ID, "error", message);
      return;
    }

    console.error(message);
  }

  function setGroupBusy(group, busy) {
    panelOf(group).querySelectorAll("button:not([data-aipkit-provider-modal-close]), input").forEach((control) => {
      control.disabled = busy;
    });
    group.classList.toggle("is-busy", busy);

    if (typeof window.aipkit_setSettingsAutosaveBusy === "function") {
      const panel = group.closest("[data-aipkit-settings-page]") || group;
      window.aipkit_setSettingsAutosaveBusy(busy, panel);
    }
  }

  function setCredentialValue(group, value) {
    const input = group.querySelector("[data-aipkit-developer-credential-input]");
    if (!input) {
      return;
    }

    const credential = String(value || "");
    const mask = formatCredentialMask(credential);
    input.dataset.credentialValue = credential;
    input.dataset.credentialMask = mask;
    input.dataset.hasCredential = credential !== "" ? "true" : "false";
    input.dataset.revealed = "false";
    input.value = mask;

    const revealButton = group.querySelector("[data-aipkit-developer-reveal]");
    const icon = revealButton?.querySelector(".dashicons");
    if (icon) {
      icon.className = "dashicons dashicons-visibility";
    }
    if (revealButton) {
      const revealLabel =
        revealButton.dataset.aipkitDeveloperRevealLabel ||
        __("Reveal credential", "gpt3-ai-content-generator");
      revealButton.setAttribute("aria-label", revealLabel);
      const text = revealButton.querySelector("[data-aipkit-developer-reveal-text]");
      if (text) {
        text.textContent = text.dataset.show || text.textContent;
      }
    }
    updateRow(group);
  }

  // The row says On or Off, and for the REST API which key it uses; webhooks counts its own endpoints.
  function updateRow(group) {
    const enabled = group.dataset.enabled === "true";
    group.dataset.aipkitProviderConnected = enabled ? "true" : "false";
    const summary = group.querySelector("[data-aipkit-developer-summary]");
    if (summary && group.dataset.aipkitDeveloperCredential === "rest_api") {
      const input = group.querySelector("[data-aipkit-developer-credential-input]");
      const credential = input?.dataset.credentialValue || input?.dataset.credentialMask || "";
      const last4 = /•/.test(credential.slice(-4)) ? "" : credential.slice(-4);
      summary.textContent = !enabled
        ? summary.dataset.off || ""
        : last4
          ? String(summary.dataset.on || "").replace("%s", last4)
          : summary.dataset.onPlain || "";
    }
    if (typeof window.CustomEvent === "function") {
      group.dispatchEvent(new window.CustomEvent("aipkit:developer-state", { bubbles: true }));
    }
  }

  function syncGroupVisibility(group, enabled) {
    group.dataset.enabled = enabled ? "true" : "false";
    group.querySelectorAll("[data-aipkit-developer-dependent]").forEach((element) => {
      element.hidden = !enabled;
    });

    const toggle = group.querySelector("[data-aipkit-developer-enabled]");
    if (toggle) {
      toggle.checked = enabled;
    }
    updateRow(group);
  }

  async function updateCredential(group, operation) {
    if (typeof window.aipkit_apiRequest !== "function") {
      throw new Error(__("Developer credential controls are unavailable.", "gpt3-ai-content-generator"));
    }

    const credential = group.dataset.aipkitDeveloperCredential || "";
    return window.aipkit_apiRequest("aipkit_update_developer_credential", {
      credential,
      operation,
    });
  }

  async function loadCredential(group) {
    const input = group.querySelector("[data-aipkit-developer-credential-input]");
    if (!input) {
      return "";
    }
    if (input.dataset.credentialValue) {
      return input.dataset.credentialValue;
    }
    if (
      input.dataset.hasCredential !== "true" ||
      typeof window.aipkit_apiRequest !== "function"
    ) {
      return "";
    }

    setGroupBusy(group, true);
    try {
      const response = await window.aipkit_apiRequest(
        "aipkit_reveal_settings_credential",
        {
          scope: "developer",
          identifier: group.dataset.aipkitDeveloperCredential || "",
        }
      );
      const credential = String(response?.credential || "");
      input.dataset.credentialValue = credential;
      input.dataset.hasCredential = credential !== "" ? "true" : "false";
      return credential;
    } catch (error) {
      showError(
        error?.message ||
          __("Unable to reveal the developer credential.", "gpt3-ai-content-generator"),
        group
      );
      return "";
    } finally {
      setGroupBusy(group, false);
    }
  }

  async function applyCredentialAction(group, operation) {
    showError("", group);
    setGroupBusy(group, true);
    let updated = false;
    try {
      const response = await updateCredential(group, operation);
      setCredentialValue(group, response?.credential || "");
      syncGroupVisibility(group, Boolean(response?.enabled));
      updated = true;
      return true;
    } catch (error) {
      showError(
        error?.message ||
          __("Unable to update the developer credential.", "gpt3-ai-content-generator"),
        group
      );
      return false;
    } finally {
      setGroupBusy(group, false);
      if (updated && typeof window.aipkit_updateLastSavedData === "function") {
        window.aipkit_updateLastSavedData();
      }
    }
  }

  async function toggleReveal(group) {
    const input = group.querySelector("[data-aipkit-developer-credential-input]");
    const button = group.querySelector("[data-aipkit-developer-reveal]");
    if (!input || !button) {
      return;
    }

    const revealed = input.dataset.revealed === "true";
    if (!revealed && !(await loadCredential(group))) {
      return;
    }
    input.dataset.revealed = revealed ? "false" : "true";
    input.value = revealed
      ? input.dataset.credentialMask || ""
      : input.dataset.credentialValue || "";

    const icon = button.querySelector(".dashicons");
    if (icon) {
      icon.className = revealed
        ? "dashicons dashicons-visibility"
        : "dashicons dashicons-hidden";
    }
    const nextLabel = revealed
      ? button.dataset.aipkitDeveloperRevealLabel ||
        __("Reveal credential", "gpt3-ai-content-generator")
      : button.dataset.aipkitDeveloperHideLabel ||
        __("Hide credential", "gpt3-ai-content-generator");
    button.setAttribute("aria-label", nextLabel);
    const text = button.querySelector("[data-aipkit-developer-reveal-text]");
    if (text) {
      text.textContent = (revealed ? text.dataset.show : text.dataset.hide) || text.textContent;
    }
  }

  async function copyCredential(group, button) {
    const input = group.querySelector("[data-aipkit-developer-credential-input]");
    const value = button.dataset.aipkitDeveloperCopyValue || input?.dataset.credentialValue || (await loadCredential(group));
    if (!value) {
      return;
    }

    try {
      await navigator.clipboard.writeText(value);
      showCopyFeedback(button);
    } catch (error) {
      showError(__("Could not copy the credential.", "gpt3-ai-content-generator"), group);
    }
  }

  function showCopyFeedback(button) {
    const icon = button.querySelector(".dashicons");
    const copiedLabel = __("Copied", "gpt3-ai-content-generator");

    if (!button.dataset.aipkitDeveloperCopyLabel) {
      button.dataset.aipkitDeveloperCopyLabel =
        button.getAttribute("aria-label") ||
        button.title ||
        __("Copy credential", "gpt3-ai-content-generator");
    }
    if (icon && !icon.dataset.aipkitDeveloperCopyIcon) {
      icon.dataset.aipkitDeveloperCopyIcon = icon.className;
    }

    const activeTimer = copyFeedbackTimers.get(button);
    if (activeTimer) {
      window.clearTimeout(activeTimer);
    }

    button.classList.add("is-copied");
    button.setAttribute("aria-label", copiedLabel);
    if (icon) {
      icon.className = "dashicons dashicons-yes-alt";
    }
    const text = button.querySelector("[data-aipkit-developer-copy-text]");
    if (text && !text.dataset.copyText) {
      text.dataset.copyText = text.textContent;
    }
    if (text) {
      text.textContent = text.dataset.copied || copiedLabel;
    }

    const timer = window.setTimeout(() => {
      const originalLabel =
        button.dataset.aipkitDeveloperCopyLabel ||
        __("Copy credential", "gpt3-ai-content-generator");
      button.classList.remove("is-copied");
      button.setAttribute("aria-label", originalLabel);
      if (text) {
        text.textContent = text.dataset.copyText || text.textContent;
      }
      if (icon) {
        icon.className =
          icon.dataset.aipkitDeveloperCopyIcon ||
          "dashicons dashicons-admin-page";
      }
      copyFeedbackTimers.delete(button);
    }, 1200);

    copyFeedbackTimers.set(button, timer);
  }

  function confirmRegenerate(group) {
    const credentialType = group.dataset.aipkitDeveloperCredential || "";
    const isWebhook = credentialType === "webhook";
    const title = isWebhook
      ? __("Make a new signing secret?", "gpt3-ai-content-generator")
      : __("Make a new API key?", "gpt3-ai-content-generator");
    const message = isWebhook
      ? __("The old secret stops working now. Your endpoints can’t check requests until they use the new one.", "gpt3-ai-content-generator")
      : __("The old key stops working now. Apps and scripts that use it lose access until they use the new one.", "gpt3-ai-content-generator");

    const execute = () => {
      void applyCredentialAction(group, "regenerate");
    };

    if (typeof window.aipkit_showConfirmModal === "function") {
      window.aipkit_showConfirmModal(message, {
        title,
        confirmText: isWebhook
          ? __("Make a new secret", "gpt3-ai-content-generator")
          : __("Make a new key", "gpt3-ai-content-generator"),
        cancelText: __("Cancel", "gpt3-ai-content-generator"),
        variant: "danger",
        onConfirm: execute,
      });
      return;
    }

    if (window.confirm(message)) {
      execute();
    }
  }

  function bindCredentialGroup(group) {
    if (!group || group.dataset.aipkitDeveloperBound === "true") {
      return;
    }

    const toggle = group.querySelector("[data-aipkit-developer-enabled]");
    if (toggle) {
      toggle.addEventListener("change", async () => {
        const previousEnabled = group.dataset.enabled === "true";
        const operation = toggle.checked ? "enable" : "disable";
        const success = await applyCredentialAction(group, operation);
        if (!success) {
          syncGroupVisibility(group, previousEnabled);
        }
      });
    }

    group.addEventListener("click", (event) => {
      const revealButton = event.target.closest("[data-aipkit-developer-reveal]");
      if (revealButton) {
        void toggleReveal(group);
        return;
      }

      const copyButton = event.target.closest("[data-aipkit-developer-copy], [data-aipkit-developer-copy-value]");
      if (copyButton) {
        void copyCredential(group, copyButton);
        return;
      }

      if (event.target.closest("[data-aipkit-developer-regenerate]")) {
        confirmRegenerate(group);
      }
    });

    syncGroupVisibility(group, group.dataset.enabled === "true");
    group.dataset.aipkitDeveloperBound = "true";
  }

  function aipkit_initDeveloperSettings() {
    document
      .querySelectorAll("[data-aipkit-developer-credential]")
      .forEach(bindCredentialGroup);
  }

  window.aipkit_initDeveloperSettings = aipkit_initDeveloperSettings;
})();
