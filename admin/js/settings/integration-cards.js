/**
 * Settings integration cards.
 */
(function () {
  "use strict";

  const FIXED_CREDENTIAL_MASK = "••••••••••••";

  const getContainer = () =>
    document.getElementById("aipkit_settings_container");

  const formatCredentialMask = (credential) => {
    const value = String(credential || "");
    if (value.length < 9) {
      return FIXED_CREDENTIAL_MASK;
    }

    const prefixLength = value.length >= 16 ? 8 : 3;
    const suffixLength = value.length >= 16 ? 4 : 3;
    return `${value.slice(0, prefixLength)}${FIXED_CREDENTIAL_MASK}${value.slice(-suffixLength)}`;
  };

  const getCredentialInput = (card) =>
    card?.querySelector("[data-aipkit-integration-credential]") || null;

  const hasInputValue = (input) =>
    String(input?.value || "").trim() !== "" ||
    input?.dataset.aipkitHasCredential === "true";

  const isCardReady = (card) =>
    Array.from(
      card?.querySelectorAll("[data-aipkit-integration-required]") || []
    ).every(hasInputValue);

  const requiresLiveTest = (card) =>
    card?.dataset.aipkitIntegrationLiveTest === "true";

  const showConnectionMessage = (type, message) => {
    if (typeof window.aipkit_showMessage === "function") {
      window.aipkit_showMessage(
        "aipkit_settings_global_messages",
        type,
        message
      );
    }
  };

  const syncCredentialMask = (card, connected) => {
    const input = getCredentialInput(card);
    const mask = card?.querySelector(
      "[data-aipkit-integration-credential-mask]"
    );
    if (!input || !mask) {
      return;
    }

    const hasStoredCredential =
      input.dataset.aipkitHasCredential === "true";
    const shouldMask = Boolean(
      connected &&
        (input.value.trim() !== "" || hasStoredCredential) &&
        input.type === "password"
    );
    if (shouldMask && input.value.trim() !== "") {
      mask.textContent = formatCredentialMask(input.value);
    }
    mask.hidden = !shouldMask;
    input.classList.toggle("is-visually-masked", shouldMask);
    input.readOnly = shouldMask;
  };

  const setCredentialRevealed = (card, revealed) => {
    const input = getCredentialInput(card);
    const revealButton = card?.querySelector(
      "[data-aipkit-integration-reveal]"
    );
    if (!input || !revealButton) {
      return;
    }

    input.type = revealed ? "text" : "password";
    input.readOnly = false;
    syncCredentialMask(
      card,
      card.dataset.aipkitIntegrationConnected === "true"
    );

    const label = revealed
      ? revealButton.dataset.hideLabel || "Hide API key"
      : revealButton.dataset.revealLabel || "Reveal API key";
    revealButton.setAttribute("aria-label", label);
    revealButton.setAttribute("title", label);

    const icon = revealButton.querySelector(".dashicons");
    icon?.classList.toggle("dashicons-visibility", !revealed);
    icon?.classList.toggle("dashicons-hidden", revealed);
  };

  const loadStoredCredential = async (card) => {
    const input = getCredentialInput(card);
    if (!input) {
      return false;
    }
    if (input.dataset.aipkitCredentialLoaded === "true") {
      return true;
    }
    if (input.dataset.aipkitHasCredential !== "true") {
      return true;
    }
    if (typeof window.aipkit_apiRequest !== "function") {
      return false;
    }

    input.disabled = true;
    try {
      const response = await window.aipkit_apiRequest(
        "aipkit_reveal_settings_credential",
        {
          scope: "integration",
          identifier: input.dataset.aipkitIntegrationSlug || "",
        }
      );
      input.value = String(response?.credential || "");
      input.name = input.dataset.aipkitCredentialName || "";
      input.dataset.aipkitCredentialLoaded = "true";
      return input.value !== "";
    } catch (error) {
      if (typeof window.aipkit_showMessage === "function") {
        window.aipkit_showMessage(
          "aipkit_settings_global_messages",
          "error",
          error?.message || "Unable to reveal the stored credential."
        );
      }
      return false;
    } finally {
      input.disabled = false;
    }
  };

  const revealStoredCredential = async (card) => {
    if (await loadStoredCredential(card)) {
      setCredentialRevealed(card, true);
      getCredentialInput(card)?.focus({ preventScroll: true });
    }
  };

  const setCardConnectedState = (card, connected) => {
    if (!card) {
      return;
    }

    card.dataset.aipkitIntegrationConnected = connected ? "true" : "false";

    const connectedStatus = card.querySelector(
      ".aipkit_settings_provider_status--connected"
    );
    const disconnectedStatus = card.querySelector(
      ".aipkit_settings_provider_status--disconnected"
    );
    const connectButton = card.querySelector(
      "[data-aipkit-integration-connect]"
    );
    const connectedFields = card.querySelector(
      "[data-aipkit-integration-connected-fields]"
    );
    const revealActions = card.querySelector(
      ".aipkit_settings_integration_connected_actions"
    );
    const credential = getCredentialInput(card);

    if (connectedStatus) connectedStatus.hidden = !connected;
    if (disconnectedStatus) disconnectedStatus.hidden = connected;
    if (connectButton) connectButton.hidden = connected;
    if (connectedFields) connectedFields.hidden = !connected;
    if (revealActions) {
      revealActions.hidden = !connected || !hasInputValue(credential);
    }
    if (credential && connected && credential.value.trim() !== "") {
      credential.dataset.aipkitHasCredential = "true";
    }

    if (!connected && credential?.type === "text") {
      setCredentialRevealed(card, false);
    }
    syncCredentialMask(card, connected);
  };

  const setCardExpanded = (card, expanded) => {
    const toggle = card?.querySelector("[data-aipkit-integration-toggle]");
    const panel = card?.querySelector("[data-aipkit-integration-panel]");
    if (!toggle || !panel) {
      return;
    }

    toggle.setAttribute("aria-expanded", expanded ? "true" : "false");
    panel.hidden = !expanded;
  };

  const toggleCardExpanded = (container, card) => {
    const toggle = card?.querySelector("[data-aipkit-integration-toggle]");
    const shouldExpand = toggle?.getAttribute("aria-expanded") !== "true";

    container
      .querySelectorAll("[data-aipkit-integration-card]")
      .forEach((otherCard) => {
        setCardExpanded(otherCard, otherCard === card && shouldExpand);
      });
  };

  const savedValuesMatch = (card) =>
    Array.from(
      card?.querySelectorAll(
        "[data-aipkit-integration-required][name], [data-aipkit-integration-credential][name]"
      ) || []
    ).every((input) => {
      const savedValue = String(
        window.aipkit_lastSavedData?.[input.name] ?? ""
      );
      return savedValue === input.value;
    });

  const autosaveCard = async (card, button = null) => {
    if (!card || typeof window.aipkit_handleAutoSave !== "function") {
      return;
    }

    if (button) {
      button.classList.add("aipkit_loading");
      button.disabled = true;
      button.setAttribute("aria-busy", "true");
    }

    try {
      await window.aipkit_handleAutoSave();
      if (savedValuesMatch(card)) {
        setCardConnectedState(card, isCardReady(card));
      }
    } finally {
      if (button) {
        button.classList.remove("aipkit_loading");
        button.disabled = false;
        button.removeAttribute("aria-busy");
      }
    }
  };

  const setButtonBusy = (button, busy) => {
    if (!button) {
      return;
    }
    button.classList.toggle("aipkit_loading", busy);
    button.disabled = busy;
    if (busy) {
      button.setAttribute("aria-busy", "true");
    } else {
      button.removeAttribute("aria-busy");
    }
  };

  const storeVerifiedCredentialState = (card, credential) => {
    const input = getCredentialInput(card);
    const mask = card?.querySelector(
      "[data-aipkit-integration-credential-mask]"
    );
    if (!input) {
      return;
    }

    if (mask) {
      mask.textContent = formatCredentialMask(credential);
    }
    input.value = "";
    input.name = "";
    input.type = "password";
    input.readOnly = true;
    input.dataset.aipkitHasCredential = "true";
    input.dataset.aipkitCredentialLoaded = "false";
    input.dataset.aipkitCredentialDirty = "false";
    setCardConnectedState(card, true);

    if (typeof window.aipkit_updateLastSavedData === "function") {
      window.aipkit_updateLastSavedData();
    }
  };

  const restoreStoredCredentialState = (card) => {
    const input = getCredentialInput(card);
    if (!input) {
      return;
    }

    input.value = "";
    input.name = "";
    input.type = "password";
    input.readOnly = true;
    input.dataset.aipkitCredentialLoaded = "false";
    input.dataset.aipkitCredentialDirty = "false";
    syncCredentialMask(card, true);
  };

  const verifyStockPhotoConnection = async (
    card,
    button = null,
    candidateCredential = ""
  ) => {
    if (!card || typeof window.aipkit_apiRequest !== "function") {
      return;
    }

    const wasConnected =
      card.dataset.aipkitIntegrationConnected === "true";
    const credential = String(candidateCredential || "").trim();
    setButtonBusy(button, true);

    try {
      const response = await window.aipkit_apiRequest(
        "aipkit_connect_stock_photo_provider",
        {
          provider: card.dataset.aipkitIntegrationCard || "",
          api_key: credential,
        }
      );

      if (credential !== "") {
        storeVerifiedCredentialState(card, credential);
      } else {
        setCardConnectedState(card, true);
      }

      if (
        response?.providerConnectionStates &&
        typeof window.aipkit_updateModelRegistryStates === "function"
      ) {
        window.aipkit_updateModelRegistryStates(
          response.providerConnectionStates
        );
      }
      if (typeof window.aipkit_syncProviderStatusFromSettings === "function") {
        window.aipkit_syncProviderStatusFromSettings();
      }
      showConnectionMessage(
        "success",
        response?.message || "Connection verified."
      );
    } catch (error) {
      if (wasConnected && credential !== "") {
        restoreStoredCredentialState(card);
      }
      showConnectionMessage(
        "error",
        error?.message || "Unable to verify this connection."
      );
    } finally {
      setButtonBusy(button, false);
    }
  };

  const reportFirstMissingField = (card) => {
    const missingField = Array.from(
      card?.querySelectorAll("[data-aipkit-integration-required]") || []
    ).find((input) => !hasInputValue(input));

    if (!missingField) {
      return false;
    }

    missingField.setCustomValidity("Complete this field before connecting.");
    missingField.reportValidity();
    missingField.focus();
    window.setTimeout(() => missingField.setCustomValidity(""), 0);
    return true;
  };

  const bindContainerEvents = (container) => {
    if (
      !container ||
      container.dataset.aipkitIntegrationCardsBound === "true"
    ) {
      return;
    }

    container.addEventListener("focusin", (event) => {
      const input = event.target.closest(
        "[data-aipkit-integration-credential]"
      );
      if (!input || input.type !== "password") {
        return;
      }

      const card = input.closest("[data-aipkit-integration-card]");
      if (
        input.value.trim() !== "" ||
        input.dataset.aipkitHasCredential === "true"
      ) {
        void revealStoredCredential(card);
      }
    });

    container.addEventListener("input", (event) => {
      const input = event.target.closest(
        "[data-aipkit-integration-credential]"
      );
      const card = input?.closest("[data-aipkit-integration-card]");
      if (input && requiresLiveTest(card)) {
        input.dataset.aipkitCredentialDirty = "true";
      }
    });

    container.addEventListener(
      "blur",
      (event) => {
        const input = event.target.closest(
          "[data-aipkit-integration-required], [data-aipkit-integration-credential]"
        );
        if (!input) {
          return;
        }

        const card = input.closest("[data-aipkit-integration-card]");
        if (input.matches("[data-aipkit-integration-credential]")) {
          const revealButton = card?.querySelector(
            "[data-aipkit-integration-reveal]"
          );
          if (event.relatedTarget !== revealButton) {
            setCredentialRevealed(card, false);
          }

          if (requiresLiveTest(card)) {
            const shouldVerifyUpdate =
              card?.dataset.aipkitIntegrationConnected === "true" &&
              input.dataset.aipkitCredentialDirty === "true" &&
              input.value.trim() !== "";
            if (shouldVerifyUpdate) {
              input.dataset.aipkitCredentialDirty = "false";
              void verifyStockPhotoConnection(card, null, input.value);
            }
            return;
          }
        }

        autosaveCard(card);
      },
      true
    );

    container.addEventListener("click", (event) => {
      const summaryButton = event.target.closest(
        "[data-aipkit-integration-toggle]"
      );
      if (summaryButton) {
        event.preventDefault();
        toggleCardExpanded(
          container,
          summaryButton.closest("[data-aipkit-integration-card]")
        );
        return;
      }

      const revealButton = event.target.closest(
        "[data-aipkit-integration-reveal]"
      );
      if (revealButton) {
        event.preventDefault();
        const card = revealButton.closest(
          "[data-aipkit-integration-card]"
        );
        const input = getCredentialInput(card);
        if (input) {
          if (input.type === "password") {
            void revealStoredCredential(card);
          } else {
            setCredentialRevealed(card, false);
          }
        }
        return;
      }

      const connectButton = event.target.closest(
        "[data-aipkit-integration-connect]"
      );
      if (!connectButton) {
        return;
      }

      event.preventDefault();
      const card = connectButton.closest(
        "[data-aipkit-integration-card]"
      );
      if (!card || reportFirstMissingField(card)) {
        return;
      }

      if (requiresLiveTest(card)) {
        void verifyStockPhotoConnection(
          card,
          connectButton,
          getCredentialInput(card)?.value || ""
        );
        return;
      }

      autosaveCard(card, connectButton);
    });

    container.dataset.aipkitIntegrationCardsBound = "true";
  };

  function initIntegrationCards() {
    const container = getContainer();
    if (!container) {
      return;
    }

    bindContainerEvents(container);
    container
      .querySelectorAll("[data-aipkit-integration-card]")
      .forEach((card) => {
        setCardExpanded(card, false);
        setCardConnectedState(
          card,
          card.dataset.aipkitIntegrationConnected === "true"
        );
      });

    const requestedCard = String(
      window.__aipkitRequestedIntegrationCard || ""
    )
      .trim()
      .toLowerCase();
    window.__aipkitRequestedIntegrationCard = "";

    if (requestedCard) {
      const card = container.querySelector(
        `[data-aipkit-integration-card="${CSS.escape(requestedCard)}"]`
      );
      if (card) {
        setCardExpanded(card, true);
        window.requestAnimationFrame(() => {
          card.scrollIntoView({ behavior: "smooth", block: "center" });
          card
            .querySelector("[data-aipkit-integration-toggle]")
            ?.focus({ preventScroll: true });
        });
      }
    }
  }

  window.aipkit_initIntegrationCards = initIntegrationCards;
})();
