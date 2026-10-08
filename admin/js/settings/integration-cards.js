/**
 * Settings connections: rows that open the AI providers' side panel, and what the panel does.
 * Opening, closing and focus belong to ai-provider-cards.js; this file keeps each service's state.
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

  const formatTemplate = (template, values) =>
    String(template || "").replace(/%(?:(\d)\$)?s/g, (match, position) =>
      String(values[position ? Number(position) - 1 : 0] ?? "")
    );

  const getCredentialInput = (card) =>
    card?.querySelector("[data-aipkit-integration-credential]") || null;

  const hasInputValue = (input) =>
    String(input?.value || "").trim() !== "" ||
    input?.dataset.aipkitHasCredential === "true";

  // What connecting needs: the key when the service requires one, and an endpoint where there is one.
  const getRequiredFields = (card) =>
    Array.from(
      card?.querySelectorAll(
        "[data-aipkit-integration-required], .aipkit_settings_provider_connection_fields [required]"
      ) || []
    );

  const isCardReady = (card) => getRequiredFields(card).every(hasInputValue);

  const isCardConnected = (card) =>
    card?.dataset.aipkitIntegrationConnected === "true";

  const requiresLiveTest = (card) =>
    card?.dataset.aipkitIntegrationLiveTest === "true";

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

  // While the field has focus it shows the saved key; leaving it masks the key again.
  const setCredentialRevealed = (card, revealed) => {
    const input = getCredentialInput(card);
    if (!input) {
      return;
    }

    input.type = revealed ? "text" : "password";
    input.readOnly = false;
    syncCredentialMask(card, isCardConnected(card));
  };

  // The saved key as loaded, so peeking at it and clicking away doesn't save it again. Kept in memory only.
  const savedCredentials = new WeakMap();

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
      savedCredentials.set(input, input.value);
      // Enabled first: the saved state is read from the form, which leaves out disabled fields.
      input.disabled = false;
      window.aipkit_markSettingsFieldSaved?.(input);
      return input.value !== "";
    } catch (error) {
      setCardError(card, error?.message || "Unable to show the saved key.", true);
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

  const isAuthenticationError = (message) =>
    /(?:\b401\b|\b403\b|unauthori[sz]ed|forbidden|incorrect\s+(?:api\s+)?key|invalid\s+(?:api\s+)?(?:key|token)|api\s+key[^.]{0,40}\binvalid\b|authentication(?:_error|\s+fails?|\s+failed)?)/i.test(
      String(message || "")
    );

  const sanitizeTechnicalDetail = (message, card) => {
    let detail = String(message || "").trim();
    const credential = String(getCredentialInput(card)?.value || "").trim();
    if (credential) {
      detail = detail.split(credential).join("[credential hidden]");
    }
    return detail.replace(/\s+/g, " ").trim();
  };

  const setStatusVisibility = (card) => {
    const connected = isCardConnected(card);
    const hasError = card.classList.contains("has-provider-error");
    const invalid = card.classList.contains("has-invalid-credential");
    const statuses = {
      connected: ".aipkit_settings_provider_status--connected",
      disconnected: ".aipkit_settings_provider_status--disconnected",
      invalid: "[data-aipkit-integration-invalid-status]",
      syncError: "[data-aipkit-integration-sync-error-status]",
    };
    const show = {
      connected: !hasError && connected,
      disconnected: !hasError && !connected,
      invalid: hasError && invalid,
      syncError: hasError && !invalid,
    };
    Object.keys(statuses).forEach((key) => {
      const status = card.querySelector(statuses[key]);
      if (status) status.hidden = !show[key];
    });
  };

  const clearCardError = (card) => {
    if (!card) {
      return;
    }
    const error = card.querySelector("[data-aipkit-integration-error]");
    const details = error?.querySelector("[data-aipkit-integration-error-details]");
    const technical = error?.querySelector("[data-aipkit-integration-error-technical]");
    if (error) error.hidden = true;
    if (details) {
      details.hidden = true;
      details.open = false;
    }
    if (technical) technical.textContent = "";
    card.classList.remove("has-provider-error", "has-invalid-credential");
    setStatusVisibility(card);
  };

  // A failed sync or check shows in the panel, the way an AI provider's does; ownMessage is a reason the server already worded.
  function setCardError(card, rawMessage, ownMessage = false) {
    const error = card?.querySelector("[data-aipkit-integration-error]");
    if (!error) {
      return;
    }
    const authenticationError = isAuthenticationError(rawMessage);
    const message = error.querySelector("[data-aipkit-integration-error-message]");
    const details = error.querySelector("[data-aipkit-integration-error-details]");
    const technical = error.querySelector("[data-aipkit-integration-error-technical]");
    const technicalDetail = ownMessage ? "" : sanitizeTechnicalDetail(rawMessage, card);

    if (message) {
      message.textContent = ownMessage
        ? String(rawMessage || "")
        : authenticationError
          ? error.dataset.invalidMessage || "That key was rejected."
          : error.dataset.syncMessage || "We could not reach this service.";
    }
    if (details) {
      details.hidden = technicalDetail === "";
      details.open = false;
    }
    if (technical) technical.textContent = technicalDetail;
    error.hidden = false;
    card.classList.add("has-provider-error");
    card.classList.toggle("has-invalid-credential", authenticationError);
    setStatusVisibility(card);
  }

  // A list to review shows what the account holds: each name once, sorted, the first few by name.
  const renderReview = (review, items) => {
    const keys = String(review.dataset.keys || "name,id").split(",");
    const seen = new Map();
    (Array.isArray(items) ? items : []).forEach((item) => {
      const record = item && typeof item === "object" ? item : { name: item };
      const nameKey = keys.find((key) => {
        const value = record[key];
        return (typeof value === "string" || typeof value === "number") && String(value).trim() !== "";
      });
      const name = nameKey ? String(record[nameKey]).trim() : "";
      if (!name || seen.has(name.toLowerCase())) {
        return;
      }
      const dimension = Number(record.dimension ?? record.config?.params?.vectors?.size ?? 0);
      seen.set(name.toLowerCase(), { name, dimension: dimension > 0 ? dimension : 0 });
    });
    const entries = Array.from(seen.values()).sort((a, b) => a.name.localeCompare(b.name));

    const count = review.querySelector("[data-aipkit-integration-review-count]");
    if (count) {
      count.textContent = entries.length
        ? formatTemplate(entries.length === 1 ? review.dataset.countOne : review.dataset.countMany, [entries.length.toLocaleString()])
        : review.dataset.empty || "";
    }

    const list = review.querySelector("[data-aipkit-integration-review-list]");
    if (!list) {
      return;
    }
    list.textContent = "";
    const showItems = review.dataset.showItems === "true";
    list.hidden = !showItems || entries.length === 0;
    if (!showItems) {
      return;
    }
    const limit = Number(review.dataset.limit) || 6;
    entries.slice(0, limit).forEach((entry) => {
      const item = document.createElement("li");
      const name = document.createElement("span");
      name.className = "aipkit_settings_integration_review_name";
      name.textContent = entry.name;
      item.appendChild(name);
      if (entry.dimension) {
        const meta = document.createElement("span");
        meta.className = "aipkit_settings_integration_review_meta";
        meta.textContent = formatTemplate(review.dataset.dimensions, [entry.dimension.toLocaleString()]);
        item.appendChild(meta);
      }
      list.appendChild(item);
    });
    if (entries.length > limit) {
      const more = document.createElement("li");
      more.className = "aipkit_settings_integration_review_more";
      more.textContent = formatTemplate(review.dataset.more, [(entries.length - limit).toLocaleString()]);
      list.appendChild(more);
    }
  };

  // A connected row says what the service holds or starts with, the way an AI row names its model.
  const updateCardSummary = (card) => {
    const summary = card?.querySelector("[data-aipkit-integration-summary]");
    if (!summary) {
      return;
    }
    const reviewCount = card.querySelector("[data-aipkit-integration-review-count]");
    const source = card.querySelector("[data-aipkit-integration-summary-source]");
    summary.hidden = !isCardConnected(card);
    summary.textContent = reviewCount
      ? reviewCount.textContent
      : source?.value
        ? source.selectedOptions[0]?.textContent.trim() || source.value
        : summary.dataset.connectedLabel || "";
  };

  const setCardConnectedState = (card, connected) => {
    if (!card) {
      return;
    }

    card.dataset.aipkitIntegrationConnected = connected ? "true" : "false";
    // The AI list's styles key off this one: the status dot, Connect or the chevron, the header pill.
    card.dataset.aipkitProviderConnected = connected ? "true" : "false";

    const connectButton = card.querySelector("[data-aipkit-integration-connect]");
    const credential = getCredentialInput(card);
    if (connectButton) connectButton.hidden = connected;
    card.querySelectorAll("[data-aipkit-provider-connected-only]").forEach((part) => {
      part.hidden = !connected;
    });
    if (credential && connected && credential.value.trim() !== "") {
      credential.dataset.aipkitHasCredential = "true";
    }
    const replaceHelp = card.querySelector("[data-aipkit-integration-replace-help]");
    if (replaceHelp) {
      replaceHelp.hidden = !(connected && credential?.dataset.aipkitHasCredential === "true");
    }

    if (!connected && credential?.type === "text") {
      setCredentialRevealed(card, false);
    }
    syncCredentialMask(card, connected);
    setStatusVisibility(card);
    updateCardSummary(card);
  };

  const savedValuesMatch = (card) =>
    getRequiredFields(card)
      .concat(getCredentialInput(card) || [])
      .filter((input) => input.name)
      .every((input) => {
        const saved = window.aipkit_lastSavedData || {};
        return Object.prototype.hasOwnProperty.call(saved, input.name) && String(saved[input.name] ?? "") === input.value;
      });

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

  // After connecting, the next step is what the service holds; a service with nothing more lands on Done.
  const focusAfterConnect = (card) => {
    const next =
      card.querySelector(
        ".aipkit_settings_provider_panel_section[data-aipkit-provider-connected-only]:not([hidden]) :is(select, button)"
      ) || card.querySelector(".aipkit_settings_provider_done");
    next?.focus();
  };

  const autosaveCard = async (card, button = null) => {
    if (!card || typeof window.aipkit_handleAutoSave !== "function") {
      return;
    }

    setButtonBusy(button, true);
    try {
      await window.aipkit_handleAutoSave();
      // Only a save that carried these fields counts; a failed save leaves the old record without them.
      if (savedValuesMatch(card)) {
        const credential = getCredentialInput(card);
        if (credential?.name) {
          savedCredentials.set(credential, credential.value);
        }
        setCardConnectedState(card, isCardReady(card));
        if (button && isCardConnected(card)) {
          focusAfterConnect(card);
        }
      }
    } finally {
      setButtonBusy(button, false);
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
    savedCredentials.delete(input);
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
    savedCredentials.delete(input);
    syncCredentialMask(card, true);
  };

  // Stock photo keys are tried before they are kept, so a wrong key never replaces a working one.
  const verifyStockPhotoConnection = async (
    card,
    button = null,
    candidateCredential = ""
  ) => {
    if (!card || typeof window.aipkit_apiRequest !== "function") {
      return;
    }

    const wasConnected = isCardConnected(card);
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

      clearCardError(card);
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
      if (button) {
        focusAfterConnect(card);
      }
    } catch (error) {
      if (wasConnected && credential !== "") {
        restoreStoredCredentialState(card);
      }
      setCardError(card, error?.message || "");
    } finally {
      setButtonBusy(button, false);
    }
  };

  const reportFirstMissingField = (card) => {
    const missingField = getRequiredFields(card).find((input) => !hasInputValue(input));

    if (!missingField) {
      return false;
    }

    missingField.setCustomValidity("Complete this field before connecting.");
    missingField.reportValidity();
    missingField.focus();
    window.setTimeout(() => missingField.setCustomValidity(""), 0);
    return true;
  };

  // Disconnecting forgets what connecting needed: the key, and the endpoint where there is one.
  const removeConnection = (card, button) => {
    const credential = getCredentialInput(card);
    if (!credential) {
      return;
    }

    const remove = async () => {
      const fields = Array.from(new Set([credential, ...getRequiredFields(card)]));
      const before = fields.map((field) => ({
        field,
        name: field.name,
        value: field.value,
        hasCredential: field.dataset.aipkitHasCredential,
        loaded: field.dataset.aipkitCredentialLoaded,
      }));
      // A stock photo key is normally kept only after a check, so it skips the form save; clearing it uses that save.
      const excluded = credential.dataset.aipkitSettingsAutosaveExclude === "true";
      if (excluded) {
        delete credential.dataset.aipkitSettingsAutosaveExclude;
      }

      clearCardError(card);
      credential.type = "password";
      credential.readOnly = false;
      credential.dataset.aipkitHasCredential = "false";
      credential.dataset.aipkitCredentialLoaded = "";
      credential.dataset.aipkitCredentialDirty = "false";
      card.querySelector("[data-aipkit-integration-credential-mask]")?.setAttribute("hidden", "");
      credential.classList.remove("is-visually-masked");
      savedCredentials.delete(credential);
      fields.forEach((field) => {
        field.name = field.name || field.dataset.aipkitCredentialName || "";
        field.value = "";
      });

      try {
        await autosaveCard(card);
      } finally {
        if (excluded) {
          credential.dataset.aipkitSettingsAutosaveExclude = "true";
          credential.name = "";
          window.aipkit_updateLastSavedData?.();
        }
      }

      // If the save didn't go through, the connection is still stored: show it as before.
      if (isCardConnected(card)) {
        before.forEach((state) => {
          state.field.name = state.name;
          state.field.value = state.value;
          if (state.hasCredential !== undefined) state.field.dataset.aipkitHasCredential = state.hasCredential;
          if (state.loaded !== undefined) state.field.dataset.aipkitCredentialLoaded = state.loaded;
        });
        syncCredentialMask(card, true);
        return;
      }
      credential.focus();
    };

    if (typeof window.aipkit_showConfirmModal !== "function") {
      remove();
      return;
    }
    window.aipkit_showConfirmModal(button.dataset.confirmText || "", {
      title: button.dataset.confirmTitle || "",
      confirmText: button.dataset.confirmButton || "",
      cancelText: button.dataset.cancelButton || "",
      variant: "danger",
      onConfirm: remove,
      onCancel: () => button.isConnected && button.focus(),
    });
  };

  const getCardsForSyncProvider = (container, provider) =>
    provider
      ? Array.from(
          container.querySelectorAll(
            `[data-aipkit-integration-sync~="${CSS.escape(provider)}"]`
          )
        )
      : [];

  // A row asked for by a link or a notice opens its panel, once the Connections section is on screen.
  const openRequestedCard = (container) => {
    let requested = String(window.__aipkitRequestedIntegrationCard || "")
      .trim()
      .toLowerCase();
    window.__aipkitRequestedIntegrationCard = "";
    // A link to a row (#aipkit_settings_integration_card_…) opens it once per render, as ?aipkit_provider= does for AI.
    if (!requested && container.dataset.aipkitIntegrationHashUsed !== "true") {
      const match = /^#aipkit_settings_integration_card_([a-z0-9_-]+)$/i.exec(
        String(window.location.hash || "")
      );
      requested = match ? match[1].toLowerCase() : "";
      container.dataset.aipkitIntegrationHashUsed = "true";
    }
    if (!requested) {
      return;
    }

    const card = container.querySelector(
      `[data-aipkit-integration-card="${CSS.escape(requested)}"]`
    );
    if (!card) {
      return;
    }
    window.requestAnimationFrame(() => {
      card.scrollIntoView({ behavior: "smooth", block: "center" });
      card.querySelector("[data-aipkit-provider-settings-open]")?.click();
    });
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
      const card = event.target.closest("[data-aipkit-integration-card]");
      if (!card) {
        return;
      }
      if (card.classList.contains("has-provider-error")) {
        clearCardError(card);
      }
      const input = event.target.closest(
        "[data-aipkit-integration-credential]"
      );
      if (input && requiresLiveTest(card)) {
        input.dataset.aipkitCredentialDirty = "true";
      } else if (input) {
        input.dataset.aipkitHasCredential =
          input.value.trim() !== "" ? "true" : "false";
      }
    });

    container.addEventListener("change", (event) => {
      if (event.target.matches("[data-aipkit-integration-summary-source]")) {
        updateCardSummary(event.target.closest("[data-aipkit-integration-card]"));
      }
    });

    container.addEventListener(
      "blur",
      (event) => {
        const card = event.target.closest("[data-aipkit-integration-card]");
        if (!card) {
          return;
        }
        const input = event.target;
        if (input.matches("[data-aipkit-integration-credential]")) {
          setCredentialRevealed(card, false);
          if (savedCredentials.has(input) && savedCredentials.get(input) === input.value) {
            return;
          }

          if (requiresLiveTest(card)) {
            const shouldVerifyUpdate =
              isCardConnected(card) &&
              input.dataset.aipkitCredentialDirty === "true" &&
              input.value.trim() !== "";
            if (shouldVerifyUpdate) {
              input.dataset.aipkitCredentialDirty = "false";
              void verifyStockPhotoConnection(card, null, input.value);
            }
            return;
          }
          autosaveCard(card);
          return;
        }

        if (getRequiredFields(card).includes(input)) {
          autosaveCard(card);
        }
      },
      true
    );

    container.addEventListener("click", (event) => {
      const removeButton = event.target.closest("[data-aipkit-integration-remove]");
      if (removeButton) {
        event.preventDefault();
        removeConnection(
          removeButton.closest("[data-aipkit-integration-card]"),
          removeButton
        );
        return;
      }

      const connectButton = event.target.closest(
        "[data-aipkit-integration-connect]"
      );
      if (!connectButton) {
        return;
      }

      event.preventDefault();
      const card = connectButton.closest("[data-aipkit-integration-card]");
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

    // A finished sync refreshes what the panel shows; the sync button's own event names the list.
    window.addEventListener("aipkit:model-sync-complete", (event) => {
      const provider = String(event.detail?.provider || "");
      getCardsForSyncProvider(container, provider).forEach((card) => {
        clearCardError(card);
        const review = card.querySelector(
          `[data-aipkit-integration-review="${CSS.escape(provider)}"]`
        );
        if (review && Array.isArray(event.detail?.items)) {
          renderReview(review, event.detail.items);
        }
        updateCardSummary(card);
      });
    });

    window.addEventListener("aipkit:provider-sync-error", (event) => {
      getCardsForSyncProvider(container, String(event.detail?.provider || "")).forEach((card) => {
        setCardError(card, event.detail?.message || "");
      });
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
        setCardConnectedState(card, isCardConnected(card));
      });
    openRequestedCard(container);
  }

  window.aipkit_initIntegrationCards = initIntegrationCards;
})();
