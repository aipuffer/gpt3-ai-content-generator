/**
 * Settings AI provider cards and provider configuration modals.
 */
(function () {
  "use strict";

  const FIXED_CREDENTIAL_MASK = "••••••••••••";
  let activeModal = null;
  let returnFocusTarget = null;

  const getContainer = () =>
    document.getElementById("aipkit_settings_container");

  const focusRequestedProviderCard = (container) => {
    let requestedProvider = String(
      window.__aipkitRequestedProviderCard || ""
    ).trim();
    window.__aipkitRequestedProviderCard = "";
    // Links such as the Cloud credit notice open a provider's panel via ?aipkit_provider=.
    if (!requestedProvider && !container.dataset.aipkitProviderParamUsed) {
      try {
        requestedProvider = String(new URLSearchParams(window.location.search).get("aipkit_provider") || "").trim();
      } catch (error) {
        requestedProvider = "";
      }
      container.dataset.aipkitProviderParamUsed = "true";
    }
    if (!requestedProvider || !container) {
      return;
    }

    const card = Array.from(
      container.querySelectorAll("[data-aipkit-provider-card]")
    ).find(
      (candidate) =>
        String(candidate.dataset.aipkitProviderCard || "").toLowerCase() ===
        requestedProvider.toLowerCase()
    );
    if (!card) return;

    window.requestAnimationFrame(() => {
      card.scrollIntoView({ behavior: "smooth", block: "center" });
      const trigger = card.querySelector("[data-aipkit-provider-settings-open]");
      if (trigger) {
        openModal(container, card.dataset.aipkitProviderCard, trigger);
      } else {
        card.querySelector("button, a")?.focus({ preventScroll: true });
      }
    });
  };

  const getCardProviderForSyncProvider = (provider) => {
    const providerMap = {
      OpenAIVectorStores: "OpenAI",
    };
    return providerMap[provider] || provider;
  };

  const formatCredentialMask = (credential) => {
    const value = String(credential || "");
    if (value.length < 9) {
      return FIXED_CREDENTIAL_MASK;
    }

    const prefixLength = value.length >= 16 ? 8 : 3;
    const suffixLength = value.length >= 16 ? 4 : 3;
    return `${value.slice(0, prefixLength)}${FIXED_CREDENTIAL_MASK}${value.slice(-suffixLength)}`;
  };

  const syncCredentialMask = (card, connected) => {
    const input = card?.querySelector("[data-aipkit-provider-credential]");
    const mask = card?.querySelector("[data-aipkit-provider-credential-mask]");
    if (!input?.classList.contains("is-secret") || !mask) {
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
    const input = card?.querySelector("[data-aipkit-provider-credential]");
    const revealButton = card?.querySelector("[data-aipkit-provider-reveal]");
    if (!input?.classList.contains("is-secret") || !revealButton) {
      return;
    }

    input.type = revealed ? "text" : "password";
    input.readOnly = false;
    syncCredentialMask(
      card,
      card?.dataset.aipkitProviderConnected === "true"
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
    const input = card?.querySelector("[data-aipkit-provider-credential]");
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
          scope: "provider",
          identifier: String(
            card.dataset.aipkitProviderCard || ""
          ).toLowerCase(),
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
      card
        ?.querySelector("[data-aipkit-provider-credential]")
        ?.focus({ preventScroll: true });
    }
  };

  const syncGlobalSettingControls = (container, source) => {
    const settingName =
      source?.dataset.aipkitGlobalSetting ||
      source?.dataset.aipkitGlobalSettingSource ||
      "";
    if (!settingName) {
      return;
    }

    const canonical = container.querySelector(
      `[data-aipkit-global-setting-source="${CSS.escape(settingName)}"]`
    );
    const value = String(source?.value ?? canonical?.value ?? "");
    if (canonical) {
      canonical.value = value;
    }
    container
      .querySelectorAll(
        `[data-aipkit-global-setting="${CSS.escape(settingName)}"]`
      )
      .forEach((input) => {
        if (input !== source) {
          input.value = value;
        }
      });
  };

  const syncAllGlobalSettingControls = (container) => {
    container
      .querySelectorAll("[data-aipkit-global-setting-source]")
      .forEach((source) => syncGlobalSettingControls(container, source));
  };

  const clearCardSyncError = (card) => {
    if (!card) {
      return;
    }

    const error = card.querySelector("[data-aipkit-provider-error]");
    const invalidStatus = card.querySelector(
      "[data-aipkit-provider-invalid-status]"
    );
    const syncErrorStatus = card.querySelector(
      "[data-aipkit-provider-sync-error-status]"
    );
    const details = error?.querySelector(
      "[data-aipkit-provider-error-details]"
    );
    const technical = error?.querySelector(
      "[data-aipkit-provider-error-technical]"
    );

    if (error) {
      error.hidden = true;
      error.classList.remove("is-warning");
    }
    if (invalidStatus) invalidStatus.hidden = true;
    if (syncErrorStatus) syncErrorStatus.hidden = true;
    if (details) {
      details.hidden = true;
      details.open = false;
    }
    if (technical) technical.textContent = "";
    card.classList.remove("has-provider-error", "has-invalid-credential");
  };

  const isAuthenticationError = (message) =>
    /(?:\b401\b|unauthori[sz]ed|incorrect\s+(?:api\s+)?key|invalid\s+(?:x-api-key|api\s+key|subscription\s+key)|api\s+key\s+not\s+valid|authentication(?:_error|\s+fails?|\s+failed)|api\s+key[^.]{0,40}\binvalid\b)/i.test(
      String(message || "")
    );

  const sanitizeTechnicalDetail = (message, card) => {
    let detail = String(message || "").trim();
    const credential = String(
      card?.querySelector("[data-aipkit-provider-credential]")?.value || ""
    ).trim();

    if (credential) {
      detail = detail.split(credential).join("[credential hidden]");
    }

    return detail
      .replace(
        /((?:(?:incorrect\s+)?api\s+key\s+provided|your\s+api\s+key)\s*:\s*)[a-z0-9_*.-]+/gi,
        "$1[credential hidden]"
      )
      .replace(/\bs(?:k)?-[a-z0-9_*.-]{6,}\b/gi, "[credential hidden]")
      .replace(/\bAIza[a-z0-9_-]{8,}\b/gi, "[credential hidden]")
      .replace(/\s*You can find your API key at\s+https?:\/\/\S+\.?/gi, "")
      .replace(/\s+/g, " ")
      .trim();
  };

  const setCardSyncError = (container, syncProvider, rawMessage) => {
    const provider = getCardProviderForSyncProvider(syncProvider);
    const card = container?.querySelector(
      `[data-aipkit-provider-card="${CSS.escape(provider)}"]`
    );
    if (!card || card.classList.contains("is-locked")) {
      return;
    }

    const authenticationError = isAuthenticationError(rawMessage);
    const connectedStatus = card.querySelector(
      ".aipkit_settings_provider_status--connected"
    );
    const disconnectedStatus = card.querySelector(
      ".aipkit_settings_provider_status--disconnected"
    );
    const invalidStatus = card.querySelector(
      "[data-aipkit-provider-invalid-status]"
    );
    const syncErrorStatus = card.querySelector(
      "[data-aipkit-provider-sync-error-status]"
    );
    const error = card.querySelector("[data-aipkit-provider-error]");
    const message = error?.querySelector("[data-aipkit-provider-error-message]");
    const details = error?.querySelector(
      "[data-aipkit-provider-error-details]"
    );
    const technical = error?.querySelector(
      "[data-aipkit-provider-error-technical]"
    );
    const technicalDetail = sanitizeTechnicalDetail(rawMessage, card);

    if (connectedStatus) connectedStatus.hidden = true;
    if (disconnectedStatus) disconnectedStatus.hidden = true;
    if (invalidStatus) invalidStatus.hidden = !authenticationError;
    if (syncErrorStatus) syncErrorStatus.hidden = authenticationError;
    if (message && error) {
      message.textContent = authenticationError
        ? error.dataset.invalidMessage || "That key was rejected."
        : error.dataset.syncMessage || "We could not sync this provider.";
    }
    if (details) {
      details.hidden = technicalDetail === "";
      details.open = false;
    }
    if (technical) technical.textContent = technicalDetail;
    if (error) {
      error.hidden = false;
      error.classList.remove("is-warning");
    }

    card.classList.add("has-provider-error");
    card.classList.toggle("has-invalid-credential", authenticationError);
  };

  const updateCardSummary = (card) => {
    const summary = card?.querySelector("[data-aipkit-provider-summary]");
    if (!summary) return;
    const connected = card.dataset.aipkitProviderConnected === "true";
    const select = card.querySelector("[data-aipkit-provider-model-block] select");
    summary.hidden = !connected;
    summary.textContent = select?.value
      ? select.selectedOptions[0]?.textContent || select.value
      : summary.dataset.emptyLabel;
    const manage = card.querySelector("[data-aipkit-provider-settings-open]");
    if (manage) {
      manage.textContent = connected ? manage.dataset.manageLabel : manage.dataset.connectLabel;
    }
  };

  const setCardConnectedState = (container, provider, connected) => {
    const card = container?.querySelector(
      `[data-aipkit-provider-card="${CSS.escape(provider)}"]`
    );
    if (!card || card.classList.contains("is-locked")) {
      return;
    }

    card.dataset.aipkitProviderConnected = connected ? "true" : "false";
    const connectedStatus = card.querySelector(
      ".aipkit_settings_provider_status--connected"
    );
    const disconnectedStatus = card.querySelector(
      ".aipkit_settings_provider_status--disconnected"
    );
    const connectedActions = card.querySelector(
      ".aipkit_settings_provider_connected_actions"
    );
    const connectButton = card.querySelector(
      "[data-aipkit-provider-connect]"
    );
    const modelBlock = card.querySelector(
      "[data-aipkit-provider-model-block]"
    );

    clearCardSyncError(card);
    if (connectedStatus) connectedStatus.hidden = !connected;
    if (disconnectedStatus) disconnectedStatus.hidden = connected;
    if (connectedActions) connectedActions.hidden = !connected;
    if (connectButton) connectButton.hidden = connected;
    if (modelBlock) modelBlock.hidden = !connected;
    const credential = card.querySelector("[data-aipkit-provider-credential]");
    if (credential && connected && credential.value.trim() !== "") {
      credential.dataset.aipkitHasCredential = "true";
    }
    syncCredentialMask(card, connected);
    updateCardSummary(card);
  };

  const setDefaultProviderState = (container, provider) => {
    const cards = container?.querySelectorAll("[data-aipkit-provider-card]") || [];
    cards.forEach((card) => {
      const isDefault = card.dataset.aipkitProviderCard === provider;
      const status = card.querySelector("[data-aipkit-provider-default-status]");
      const action = card.querySelector("[data-aipkit-provider-set-default]");
      if (status) {
        status.hidden = !isDefault;
      }
      if (action) {
        action.hidden = isDefault;
      }
    });

    const cardsContainer = container?.querySelector(
      ".aipkit_settings_provider_cards"
    );
    if (cardsContainer) {
      cardsContainer.dataset.aipkitCurrentProvider = provider;
    }
  };

  const saveDefaultProvider = async (container, button) => {
    const input = container?.querySelector("[data-aipkit-default-provider-input]");
    const provider = button?.dataset.aipkitProviderSetDefault || "";
    if (
      !input ||
      !provider ||
      typeof window.aipkit_handleAutoSave !== "function"
    ) {
      return;
    }

    const previousProvider = input.value;
    input.value = provider;
    button.disabled = true;
    button.classList.add("aipkit_loading");
    button.setAttribute("aria-busy", "true");

    try {
      await window.aipkit_handleAutoSave();
      if (String(window.aipkit_lastSavedData?.provider || "") === provider) {
        setDefaultProviderState(container, provider);
        return;
      }
      input.value = previousProvider;
    } finally {
      button.disabled = false;
      button.classList.remove("aipkit_loading");
      button.removeAttribute("aria-busy");
    }
  };

  const autosaveCredential = async (container, input, button = null) => {
    if (
      !input ||
      !input.name ||
      typeof window.aipkit_handleAutoSave !== "function"
    ) {
      return;
    }

    if (button) {
      button.classList.add("aipkit_loading");
      button.disabled = true;
      button.setAttribute("aria-busy", "true");
    }

    try {
      await window.aipkit_handleAutoSave();
      const savedValue = String(window.aipkit_lastSavedData?.[input.name] ?? "");
      const card = input.closest("[data-aipkit-provider-card]");
      if (
        savedValue === input.value &&
        !card?.classList.contains("has-provider-error")
      ) {
        setCardConnectedState(
          container,
          input.dataset.aipkitProviderCredential || "",
          input.value.trim() !== ""
        );
        if (button && input.value.trim() !== "") {
          card
            ?.querySelector(
              "[data-aipkit-provider-model-block] .aipkit_unified_model_trigger"
            )
            ?.focus();
        }
      }
    } finally {
      if (button) {
        button.classList.remove("aipkit_loading");
        button.disabled = false;
        button.removeAttribute("aria-busy");
      }
    }
  };

  const updateModerationVisibility = (modal) => {
    const toggle = modal?.querySelector(
      'input[name="security[openai_moderation_enabled]"]'
    );
    const row = modal?.querySelector(
      "#aipkit_settings_openai_moderation_message_row"
    );
    if (toggle && row) {
      row.hidden = !toggle.checked;
    }
  };

  const normalizeOpenAIApiMode = (value) =>
    value === "chat_completions" ? "chat_completions" : "responses";

  const syncOpenAIApiModeControls = (container) => {
    const modal = container?.querySelector(
      '[data-aipkit-provider-modal="OpenAI"]'
    );
    const modeSelect = modal?.querySelector('select[name="openai_api_mode"]');
    if (!modeSelect) {
      return;
    }

    const mode = normalizeOpenAIApiMode(modeSelect.value);
    const isCompatibilityMode = mode === "chat_completions";
    const notice = modal.querySelector("[data-aipkit-openai-api-mode-notice]");
    const storageRow = modal.querySelector(
      '[data-aipkit-provider-field-id="store_conversation"]'
    );
    const storageToggle = storageRow?.querySelector(
      'input[name="openai_store_conversation"]'
    );

    modeSelect.value = mode;
    if (notice) {
      notice.hidden = !isCompatibilityMode;
    }
    if (storageRow) {
      storageRow.classList.toggle("is-api-mode-disabled", isCompatibilityMode);
      storageRow.setAttribute(
        "aria-disabled",
        isCompatibilityMode ? "true" : "false"
      );
    }
    if (storageToggle) {
      storageToggle.disabled = isCompatibilityMode;
    }

    window.aipkit_dashboard = window.aipkit_dashboard || {};
    window.aipkit_dashboard.openaiApiMode = mode;
  };

  const getFocusableElements = (modal) =>
    Array.from(
      modal?.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
      ) || []
    ).filter(
      (element) =>
        !element.hidden &&
        element.getAttribute("aria-hidden") !== "true" &&
        element.offsetParent !== null
    );

  const closeModal = () => {
    if (!activeModal) {
      return;
    }

    const modalToClose = activeModal;
    modalToClose.querySelectorAll(".aipkit_settings_provider_unified_model_selector").forEach((selector) => {
      selector._aipkitUnifiedModelController?.close();
    });
    modalToClose.classList.remove("aipkit-active");
    modalToClose.setAttribute("aria-hidden", "true");
    activeModal = null;

    if (returnFocusTarget?.isConnected) {
      returnFocusTarget.focus();
    }
    returnFocusTarget = null;
  };

  const openModal = (container, provider, trigger) => {
    const modal = container?.querySelector(
      `[data-aipkit-provider-modal="${CSS.escape(provider)}"]`
    );
    if (!modal || modal.classList.contains("aipkit-active")) {
      return;
    }

    closeModal();
    activeModal = modal;
    returnFocusTarget = trigger || document.activeElement;
    updateModerationVisibility(modal);
    syncOpenAIApiModeControls(container);
    modal.classList.add("aipkit-active");
    modal.setAttribute("aria-hidden", "false");

    if (typeof window.aipkit_refreshSettingsSelectPickers === "function") {
      window.aipkit_refreshSettingsSelectPickers();
    }

    window.setTimeout(() => {
      const card = modal.closest("[data-aipkit-provider-card]");
      const credential =
        card?.dataset.aipkitProviderConnected === "false"
          ? modal.querySelector("[data-aipkit-provider-credential]")
          : null;
      (credential || getFocusableElements(modal)[0])?.focus();
    }, 0);
  };

  const formatLastSynced = (timestamp) => {
    if (typeof window.aipkit_formatModelLastSynced === "function") {
      return window.aipkit_formatModelLastSynced(timestamp);
    }
    return Number(timestamp) > 0 ? "Last synced" : "Not synced yet";
  };

  const prepareProviderModelOptions = (select) => {
    const provider = select?.dataset.aipkitSettingsProviderModel || "";
    if (!select || !provider) {
      return;
    }

    Array.from(select.options || []).forEach((option) => {
      if (!option.value) {
        return;
      }
      option.dataset.provider = provider;
      option.dataset.providerLabel = provider;
      option.dataset.model = option.value;
    });
  };

  const syncProviderModelSelector = (container, syncProvider) => {
    const provider = getCardProviderForSyncProvider(syncProvider);
    const card = container?.querySelector(
      `[data-aipkit-provider-card="${CSS.escape(provider)}"]`
    );
    const select = card?.querySelector("[data-aipkit-settings-provider-model]");
    const selector = card?.querySelector(
      ".aipkit_settings_provider_unified_model_selector"
    );
    if (!select || !selector) {
      return;
    }
    prepareProviderModelOptions(select);
    selector._aipkitUnifiedModelController?.sync();
    updateCardSummary(card);
  };

  const initProviderModelSelectors = (container) => {
    if (
      typeof window.aipkit_createUnifiedModelSelector !== "function" ||
      typeof window.aipkit_createUnifiedModelSelectAdapter !== "function"
    ) {
      return;
    }

    container
      ?.querySelectorAll(".aipkit_settings_provider_unified_model_selector")
      .forEach((selector) => {
        const sourceId = selector.dataset.aipkitUnifiedModelSourceId || "";
        const select = sourceId ? document.getElementById(sourceId) : null;
        if (!select) {
          return;
        }

        prepareProviderModelOptions(select);
        const controller = window.aipkit_createUnifiedModelSelector(
          selector,
          window.aipkit_createUnifiedModelSelectAdapter(select)
        );
        if (!controller || select.dataset.aipkitSettingsUnifiedModelBound === "1") {
          controller?.sync();
          return;
        }

        select.addEventListener("change", () => {
          prepareProviderModelOptions(select);
          controller.sync();
          updateCardSummary(select.closest("[data-aipkit-provider-card]"));
        });
        select.dataset.aipkitSettingsUnifiedModelBound = "1";
        controller.sync();
        updateCardSummary(select.closest("[data-aipkit-provider-card]"));
      });
  };

  const updateLastSyncedLabels = (container) => {
    container
      ?.querySelectorAll("[data-aipkit-provider-last-synced]")
      .forEach((label) => {
        label.textContent = formatLastSynced(label.dataset.syncedAt);
        label.hidden = label.textContent === "";
      });
  };

  const bindContainerEvents = (container) => {
    container.addEventListener("focusin", (event) => {
      const input = event.target.closest("[data-aipkit-provider-credential]");
      if (!input || input.disabled || input.type !== "password") {
        return;
      }

      const card = input.closest("[data-aipkit-provider-card]");
      if (
        input.value.trim() !== "" ||
        input.dataset.aipkitHasCredential === "true"
      ) {
        void revealStoredCredential(card);
      }
    });

    container.addEventListener("input", (event) => {
      const credential = event.target.closest(
        "[data-aipkit-provider-credential]"
      );
      if (credential) {
        const card = credential.closest("[data-aipkit-provider-card]");
        if (card?.classList.contains("has-provider-error")) {
          setCardConnectedState(
            container,
            card.dataset.aipkitProviderCard || "",
            credential.value.trim() !== ""
          );
        }
      }

      if (event.target.matches("[data-aipkit-global-setting]")) {
        syncGlobalSettingControls(container, event.target);
      }
    });

    container.addEventListener("change", (event) => {
      if (!activeModal || !activeModal.contains(event.target)) {
        return;
      }

      if (
        event.target.matches('input[name="security[openai_moderation_enabled]"]')
      ) {
        updateModerationVisibility(activeModal);
      }

      if (event.target.matches('select[name="openai_api_mode"]')) {
        syncOpenAIApiModeControls(container);
      }

      if (event.target.matches("[data-aipkit-global-setting]")) {
        syncGlobalSettingControls(container, event.target);
      }
    });

    container.addEventListener(
      "blur",
      (event) => {
        if (event.target.matches("[data-aipkit-provider-credential]")) {
          const card = event.target.closest("[data-aipkit-provider-card]");
          const revealButton = card?.querySelector(
            "[data-aipkit-provider-reveal]"
          );
          if (event.relatedTarget !== revealButton) {
            setCredentialRevealed(card, false);
          }
          autosaveCredential(container, event.target);
        }
      },
      true
    );

    container.addEventListener("click", (event) => {
      const revealButton = event.target.closest("[data-aipkit-provider-reveal]");
      if (revealButton) {
        event.preventDefault();
        const card = revealButton.closest("[data-aipkit-provider-card]");
        const input = card?.querySelector("[data-aipkit-provider-credential]");
        if (!input) {
          return;
        }

        if (input.type === "password") {
          void revealStoredCredential(card);
        } else {
          setCredentialRevealed(card, false);
        }
        return;
      }

      const connectButton = event.target.closest("[data-aipkit-provider-connect]");
      if (connectButton) {
        event.preventDefault();
        const provider = connectButton.dataset.aipkitProviderConnect || "";
        const input = container.querySelector(
          `[data-aipkit-provider-credential="${CSS.escape(provider)}"]`
        );
        if (!input || input.value.trim() === "") {
          input?.setCustomValidity("Enter a connection value first.");
          input?.reportValidity();
          input?.focus();
          window.setTimeout(() => input?.setCustomValidity(""), 0);
          return;
        }
        autosaveCredential(container, input, connectButton);
        return;
      }

      const defaultProviderButton = event.target.closest(
        "[data-aipkit-provider-set-default]"
      );
      if (defaultProviderButton) {
        event.preventDefault();
        saveDefaultProvider(container, defaultProviderButton);
        return;
      }

      const modalTrigger = event.target.closest(
        "[data-aipkit-provider-settings-open]"
      );
      if (modalTrigger) {
        event.preventDefault();
        openModal(
          container,
          modalTrigger.dataset.aipkitProviderSettingsOpen || "",
          modalTrigger
        );
        return;
      }

      const resetButton = event.target.closest("[data-aipkit-reset-target]");
      if (resetButton && activeModal?.contains(resetButton)) {
        event.preventDefault();
        const target = activeModal.querySelector(
          `#${CSS.escape(resetButton.dataset.aipkitResetTarget || "")}`
        );
        if (target) {
          target.value = resetButton.dataset.defaultValue || "";
          target.dispatchEvent(new Event("change", { bubbles: true }));
          target.focus();
        }
        return;
      }

      const closeButton = event.target.closest("[data-aipkit-provider-modal-close]");
      if (closeButton && activeModal?.contains(closeButton)) {
        event.preventDefault();
        closeModal();
        return;
      }

      if (activeModal && event.target === activeModal) {
        closeModal();
      }
    });

    document.addEventListener("keydown", (event) => {
      if (!activeModal) {
        return;
      }
      if (event.key === "Escape") {
        event.preventDefault();
        closeModal();
        return;
      }
      if (event.key !== "Tab") {
        return;
      }

      const focusable = getFocusableElements(activeModal);
      if (!focusable.length) {
        event.preventDefault();
        return;
      }
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });

    window.addEventListener("aipkit:model-sync-complete", (event) => {
      const syncProvider = event.detail?.provider || "";
      const provider = getCardProviderForSyncProvider(syncProvider);
      const card = container.querySelector(
        `[data-aipkit-provider-card="${CSS.escape(provider)}"]`
      );
      if (card) {
        setCardConnectedState(container, provider, event.detail?.connected !== false);
        if (typeof window.aipkit_clearStatusMessages === "function") {
          window.aipkit_clearStatusMessages("aipkit_settings_global_messages");
        }
      }
      const label = container.querySelector(
        `[data-aipkit-provider-last-synced="${CSS.escape(syncProvider)}"]`
      );
      syncProviderModelSelector(container, syncProvider);
      if (!label) {
        return;
      }
      label.dataset.syncedAt = String(
        Number(event.detail?.syncedAt) || Math.floor(Date.now() / 1000)
      );
      label.textContent = formatLastSynced(label.dataset.syncedAt);
      label.hidden = label.textContent === "";
    });

    window.addEventListener("aipkit:provider-sync-error", (event) => {
      setCardSyncError(
        container,
        event.detail?.provider || "",
        event.detail?.message || ""
      );
      if (typeof window.aipkit_clearStatusMessages === "function") {
        window.aipkit_clearStatusMessages("aipkit_settings_global_messages");
      }
    });

  };

  function aipkit_initAiProviderCards() {
    const container = getContainer();
    if (!container) {
      return;
    }

    if (container.dataset.aipkitAiProviderCardsBound !== "true") {
      container.querySelectorAll("[data-aipkit-provider-card]").forEach((card) => {
        setCardConnectedState(
          container,
          card.dataset.aipkitProviderCard || "",
          card.dataset.aipkitProviderConnected === "true"
        );
      });
      updateLastSyncedLabels(container);
      syncAllGlobalSettingControls(container);
      syncOpenAIApiModeControls(container);
      bindContainerEvents(container);
      container.dataset.aipkitAiProviderCardsBound = "true";
    }

    initProviderModelSelectors(container);
    focusRequestedProviderCard(container);
  }

  window.aipkit_initAiProviderCards = aipkit_initAiProviderCards;
  window.aipkit_closeProviderModal = closeModal;
})();
