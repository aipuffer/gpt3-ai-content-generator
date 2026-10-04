/**
 * Shared Settings event webhooks and delivery controls.
 */
(function () {
  "use strict";

  const __ = window.wp?.i18n?.__ || ((text) => text);
  let eventWebhookGlobalListenersBound = false;
  let activeEventWebhookEventsModal = null;
  let eventWebhookEventsReturnFocus = null;
  const eventWebhookEventsStatusTimers = new WeakMap();

  function getEventWebhooksSection() {
    const settingsContainer = document.getElementById("aipkit_settings_container");
    if (!settingsContainer) {
      return null;
    }

    return settingsContainer.querySelector("#aipkit_settings_event_webhooks_section");
  }

  function getEventWebhookList(section) {
    return section?.querySelector("[data-aipkit-event-webhook-list]") || null;
  }

  function getEventWebhookEventsControl(endpoint) {
    return endpoint?.querySelector("[data-aipkit-event-webhook-events-control]") || null;
  }

  function getEventWebhookEventInputs(endpoint) {
    return Array.from(
      endpoint?.querySelectorAll('[data-aipkit-endpoint-field="event"]') || []
    );
  }

  function formatEventWebhookEventsLabel(control, selectedCount, totalCount) {
    const placeholder = control?.dataset.placeholder || "Select events";
    const allLabel = control?.dataset.allLabel || "All events selected";
    const singularLabel = control?.dataset.singularLabel || "%d event selected";
    const pluralLabel = control?.dataset.pluralLabel || "%d events selected";

    if (selectedCount === 0) {
      return placeholder;
    }

    if (totalCount > 0 && selectedCount === totalCount) {
      return allLabel;
    }

    return String(selectedCount === 1 ? singularLabel : pluralLabel).replace(
      "%d",
      String(selectedCount)
    );
  }

  function updateEventWebhookEventsLabel(endpoint) {
    const control = getEventWebhookEventsControl(endpoint);
    const label = control?.querySelector("[data-aipkit-event-webhook-events-label]");
    const count = control?.querySelector("[data-aipkit-event-webhook-events-count]");
    if (!control || !label) {
      return;
    }

    const inputs = getEventWebhookEventInputs(endpoint);
    const selectedCount = inputs.filter((input) => input.checked).length;
    label.textContent = formatEventWebhookEventsLabel(
      control,
      selectedCount,
      inputs.length
    );
    if (count) {
      const singularLabel =
        control.dataset.selectedSingularLabel || "%d selected";
      const pluralLabel =
        control.dataset.selectedPluralLabel || "%d selected";
      count.textContent = String(
        selectedCount === 1 ? singularLabel : pluralLabel
      ).replace("%d", String(selectedCount));
    }
  }

  function getEventWebhookEventsModalFocusable(modal) {
    return Array.from(
      modal?.querySelectorAll(
        'button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])'
      ) || []
    ).filter(
      (element) =>
        !element.hidden &&
        element.getAttribute("aria-hidden") !== "true" &&
        element.offsetParent !== null
    );
  }

  function filterEventWebhookEvents(modal, query = "") {
    if (!modal) {
      return;
    }

    const normalizedQuery = String(query).trim().toLowerCase();
    const options = Array.from(
      modal.querySelectorAll("[data-aipkit-event-webhook-event-option]")
    );
    options.forEach((option) => {
      const searchText = String(
        option.dataset.searchText || option.textContent || ""
      ).toLowerCase();
      option.hidden =
        normalizedQuery !== "" && !searchText.includes(normalizedQuery);
    });

    modal
      .querySelectorAll("[data-aipkit-event-webhook-events-group]")
      .forEach((group) => {
        group.hidden = !Array.from(
          group.querySelectorAll("[data-aipkit-event-webhook-event-option]")
        ).some((option) => !option.hidden);
      });

    const emptyState = modal.querySelector(
      "[data-aipkit-event-webhook-events-empty]"
    );
    if (emptyState) {
      emptyState.hidden = !options.every((option) => option.hidden);
    }
  }

  function closeEventWebhookEventsModal(restoreFocus = true) {
    if (!activeEventWebhookEventsModal) {
      return;
    }

    const modal = activeEventWebhookEventsModal;
    const control = modal.closest("[data-aipkit-event-webhook-events-control]");
    const button = control?.querySelector(
      "[data-aipkit-event-webhook-events-toggle]"
    );

    modal.classList.remove("aipkit-active");
    modal.setAttribute("aria-hidden", "true");
    button?.setAttribute("aria-expanded", "false");
    activeEventWebhookEventsModal = null;

    if (restoreFocus && eventWebhookEventsReturnFocus?.isConnected) {
      eventWebhookEventsReturnFocus.focus();
    }
    eventWebhookEventsReturnFocus = null;
  }

  function openEventWebhookEventsModal(control, trigger) {
    const modal = control?.querySelector(
      "[data-aipkit-event-webhook-events-modal]"
    );
    if (!modal || modal.classList.contains("aipkit-active")) {
      return;
    }

    closeEventWebhookEventsModal(false);
    activeEventWebhookEventsModal = modal;
    eventWebhookEventsReturnFocus = trigger || document.activeElement;
    modal.classList.add("aipkit-active");
    modal.setAttribute("aria-hidden", "false");
    trigger?.setAttribute("aria-expanded", "true");

    const search = modal.querySelector(
      "[data-aipkit-event-webhook-events-search]"
    );
    if (search) {
      search.value = "";
      filterEventWebhookEvents(modal);
    }

    window.setTimeout(() => search?.focus(), 0);
  }

  async function saveEventWebhookEventsSelection(endpoint) {
    const control = getEventWebhookEventsControl(endpoint);
    const status = control?.querySelector(
      "[data-aipkit-event-webhook-events-saved]"
    );
    if (!control || !status) {
      return;
    }

    const requestId = Number(control.dataset.saveRequestId || "0") + 1;
    control.dataset.saveRequestId = String(requestId);
    window.clearTimeout(eventWebhookEventsStatusTimers.get(status));
    status.textContent = __("Saving…", "gpt3-ai-content-generator");
    status.hidden = false;

    if (typeof window.aipkit_handleAutoSave !== "function") {
      status.hidden = true;
      return;
    }

    await window.aipkit_handleAutoSave();
    if (Number(control.dataset.saveRequestId || "0") !== requestId) {
      return;
    }

    const currentData =
      typeof window.aipkit_getCurrentFormData === "function"
        ? window.aipkit_getCurrentFormData()
        : null;
    const didSave =
      currentData &&
      JSON.stringify(currentData) === JSON.stringify(window.aipkit_lastSavedData);
    if (!didSave) {
      status.hidden = true;
      return;
    }

    status.textContent = `✓ ${__("Saved", "gpt3-ai-content-generator")}`;
    status.hidden = false;
    const timer = window.setTimeout(() => {
      status.hidden = true;
      eventWebhookEventsStatusTimers.delete(status);
    }, 1400);
    eventWebhookEventsStatusTimers.set(status, timer);
  }

  function updateEventWebhookEventsSelection(endpoint, checked) {
    const inputs = getEventWebhookEventInputs(endpoint);
    let changed = false;

    inputs.forEach((input) => {
      if (input.checked !== checked) {
        input.checked = checked;
        changed = true;
      }
    });

    updateEventWebhookEventsLabel(endpoint);
    if (changed) {
      saveEventWebhookEventsSelection(endpoint);
    }
  }

  function updateEventWebhookEndpointNames(section) {
    const list = getEventWebhookList(section);
    if (!list) {
      return;
    }

    const setEndpointFieldId = (field, id) => {
      field.id = id;
      const label = field.closest("label");
      if (label) {
        label.setAttribute("for", id);
      }
    };

    const createEndpointId = () => {
      if (window.crypto?.randomUUID) {
        return `endpoint_${window.crypto.randomUUID().replace(/-/g, "")}`;
      }

      const randomPart =
        window.crypto?.getRandomValues
          ? Array.from(window.crypto.getRandomValues(new Uint32Array(2)))
              .map((value) => value.toString(36))
              .join("")
          : Math.random().toString(36).slice(2);
      return `endpoint_${Date.now().toString(36)}${randomPart}`;
    };

    const endpoints = list.querySelectorAll("[data-aipkit-event-webhook-endpoint]");
    list.classList.toggle("is-empty", endpoints.length === 0);

    endpoints.forEach((endpoint, index) => {
      endpoint.dataset.endpointIndex = String(index);

      const endpointNumber = endpoint.querySelector("[data-aipkit-event-webhook-endpoint-number]");
      if (endpointNumber) {
        endpointNumber.textContent = ` ${index + 1}`;
      }

      endpoint.querySelectorAll("[data-aipkit-endpoint-field]").forEach((field) => {
        const fieldName = field.getAttribute("data-aipkit-endpoint-field");
        if (!fieldName) {
          return;
        }

        if (fieldName === "id" && String(field.value || "").trim() === "") {
          // Give new endpoint rows a stable client-side identity before their
          // first autosave. Otherwise the backend would generate a different
          // ID on every edit because the hidden field remained empty.
          field.value = createEndpointId();
        }

        if (fieldName === "event") {
          const eventFieldKey = field.getAttribute("data-aipkit-event-field-key");
          if (!eventFieldKey) {
            return;
          }

          field.name = `event_webhooks[endpoints][${index}][events][${eventFieldKey}]`;
          setEndpointFieldId(
            field,
            `aipkit_event_webhook_endpoint_${index}_event_${eventFieldKey}`
          );
          return;
        }

        field.name = `event_webhooks[endpoints][${index}][${fieldName}]`;

        if (field.type !== "hidden") {
          setEndpointFieldId(field, `aipkit_event_webhook_endpoint_${index}_${fieldName}`);
        }
      });

      const eventsControl = getEventWebhookEventsControl(endpoint);
      if (eventsControl) {
        const eventsModalId = `aipkit_event_webhook_endpoint_${index}_events_modal`;
        const eventsModalTitleId = `${eventsModalId}_title`;
        const eventsButton = eventsControl.querySelector(
          "[data-aipkit-event-webhook-events-toggle]"
        );
        const eventsModal = eventsControl.querySelector(
          "[data-aipkit-event-webhook-events-modal]"
        );
        const eventsModalTitle = eventsModal?.querySelector(
          ".aipkit-modal-shell-title"
        );

        if (eventsModal) {
          eventsModal.id = eventsModalId;
          eventsModal
            .querySelector('[role="dialog"]')
            ?.setAttribute("aria-labelledby", eventsModalTitleId);
        }
        if (eventsModalTitle) {
          eventsModalTitle.id = eventsModalTitleId;
        }
        if (eventsButton) {
          eventsButton.setAttribute("aria-controls", eventsModalId);
        }
        updateEventWebhookEventsLabel(endpoint);
      }
    });
  }

  function addEventWebhookEndpoint(section) {
    const list = getEventWebhookList(section);
    const template = section?.querySelector("#aipkit_event_webhook_endpoint_template");
    if (!list || !template) {
      return;
    }

    const fragment = document.importNode(template.content, true);
    list.appendChild(fragment);
    updateEventWebhookEndpointNames(section);

    const lastEndpoint = list.querySelector("[data-aipkit-event-webhook-endpoint]:last-child");
    const firstInput = lastEndpoint?.querySelector('input[type="text"], input[type="url"]');
    if (firstInput) {
      firstInput.focus();
    }

    if (typeof window.aipkit_updateLastSavedData === "function") {
      window.aipkit_updateLastSavedData();
    }
  }

  function removeEventWebhookEndpoint(section, endpoint) {
    if (!section || !endpoint) {
      return;
    }

    endpoint.remove();
    updateEventWebhookEndpointNames(section);

    if (typeof window.aipkit_handleAutoSave === "function") {
      window.aipkit_handleAutoSave();
    }
  }

  function initEventWebhookSettingsUI() {
    const section = getEventWebhooksSection();
    if (!section) {
      return;
    }

    updateEventWebhookEndpointNames(section);

    if (section.dataset.aipkitEventWebhooksBound === "true") {
      return;
    }

    const addButton = section.querySelector("#aipkit_add_event_webhook_endpoint_btn");
    if (addButton) {
      addButton.addEventListener("click", () => {
        addEventWebhookEndpoint(section);
      });
    }

    section.addEventListener("click", (event) => {
      const eventsToggle = event.target.closest("[data-aipkit-event-webhook-events-toggle]");
      if (eventsToggle) {
        event.preventDefault();
        const control = eventsToggle.closest("[data-aipkit-event-webhook-events-control]");
        if (!control) {
          return;
        }

        openEventWebhookEventsModal(control, eventsToggle);
        return;
      }

      const closeEventsModalButton = event.target.closest(
        "[data-aipkit-event-webhook-events-close]"
      );
      if (closeEventsModalButton) {
        event.preventDefault();
        closeEventWebhookEventsModal();
        return;
      }

      if (event.target.matches("[data-aipkit-event-webhook-events-modal]")) {
        closeEventWebhookEventsModal();
        return;
      }

      const selectAllEventsButton = event.target.closest(
        "[data-aipkit-event-webhook-events-select-all]"
      );
      const clearEventsButton = event.target.closest("[data-aipkit-event-webhook-events-clear]");
      if (selectAllEventsButton || clearEventsButton) {
        event.preventDefault();
        const endpoint = event.target.closest("[data-aipkit-event-webhook-endpoint]");
        updateEventWebhookEventsSelection(endpoint, Boolean(selectAllEventsButton));
        return;
      }

      const removeButton = event.target.closest("[data-aipkit-remove-event-webhook-endpoint]");
      if (!removeButton) {
        return;
      }

      const endpoint = removeButton.closest("[data-aipkit-event-webhook-endpoint]");
      if (!endpoint) {
        return;
      }

      const executeRemove = () => {
        removeEventWebhookEndpoint(section, endpoint);
      };

      if (typeof window.aipkit_showConfirmModal === "function") {
        window.aipkit_showConfirmModal(
          __(
            "This endpoint will stop receiving events. This cannot be undone.",
            "gpt3-ai-content-generator"
          ),
          {
            title: __("Delete endpoint?", "gpt3-ai-content-generator"),
            confirmText: __("Delete endpoint", "gpt3-ai-content-generator"),
            cancelText: __("Cancel", "gpt3-ai-content-generator"),
            variant: "danger",
            onConfirm: executeRemove,
          }
        );
        return;
      }

      if (
        window.confirm(
          __("Delete this endpoint? This cannot be undone.", "gpt3-ai-content-generator")
        )
      ) {
        executeRemove();
      }
    });

    section.addEventListener("change", (event) => {
      if (!event.target.matches('[data-aipkit-endpoint-field="event"]')) {
        return;
      }

      const endpoint = event.target.closest("[data-aipkit-event-webhook-endpoint]");
      updateEventWebhookEventsLabel(endpoint);
      saveEventWebhookEventsSelection(endpoint);
    });

    section.addEventListener("input", (event) => {
      if (!event.target.matches("[data-aipkit-event-webhook-events-search]")) {
        return;
      }

      filterEventWebhookEvents(
        event.target.closest("[data-aipkit-event-webhook-events-modal]"),
        event.target.value
      );
    });

    if (!eventWebhookGlobalListenersBound) {
      document.addEventListener("keydown", (event) => {
        if (!activeEventWebhookEventsModal) {
          return;
        }

        if (event.key === "Escape") {
          event.preventDefault();
          closeEventWebhookEventsModal();
          return;
        }

        if (event.key !== "Tab") {
          return;
        }

        const focusable = getEventWebhookEventsModalFocusable(
          activeEventWebhookEventsModal
        );
        if (!focusable.length) {
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

      eventWebhookGlobalListenersBound = true;
    }

    section.dataset.aipkitEventWebhooksBound = "true";
  }

  function getEventWebhookDeliveryIssuesSection() {
    const settingsContainer = document.getElementById("aipkit_settings_container");
    if (!settingsContainer) {
      return null;
    }

    return settingsContainer.querySelector("#aipkit_settings_event_webhook_delivery_issues_section");
  }

  function initEventWebhookDeliveryIssuesUI() {
    const section = getEventWebhookDeliveryIssuesSection();
    if (!section || section.dataset.aipkitEventWebhookDeliveryIssuesBound === "true") {
      return;
    }

    const setIssueButtonPending = (button, pendingLabel) => {
      const label = button.querySelector(".aipkit_btn-text");
      const previousLabel = label?.textContent || "";
      button.disabled = true;
      if (label && pendingLabel) {
        label.textContent = pendingLabel;
      }

      return { label, previousLabel };
    };

    const resetIssueButtonPending = (button, state) => {
      button.disabled = false;
      if (state?.label) {
        state.label.textContent = state.previousLabel;
      }
    };

    const showIssueMessage = (type, message) => {
      if (typeof window.aipkit_showMessage === "function") {
        window.aipkit_showMessage("aipkit_settings_global_messages", type, String(message || ""));
      }
    };

    const getIssueList = () => section.querySelector("[data-aipkit-event-webhook-delivery-issue-list]");

    const syncIssueSectionVisibility = () => {
      const issueList = getIssueList();
      const row = section.closest("#aipkit_settings_event_webhook_delivery_issues_row");
      if (!issueList || !issueList.querySelector("[data-aipkit-event-webhook-delivery-issue]")) {
        if (row) {
          row.remove();
          return;
        }

        section.remove();
      }
    };

    const createIssueElement = (html) => {
      const template = document.createElement("template");
      template.innerHTML = String(html || "").trim();
      const nextIssue = template.content.firstElementChild;
      return nextIssue instanceof HTMLElement ? nextIssue : null;
    };

    section.addEventListener("click", async (event) => {
      const clearButton = event.target.closest("[data-aipkit-clear-event-webhook-delivery-issue]");
      if (clearButton) {
        const jobUuid = String(clearButton.getAttribute("data-job-uuid") || "").trim();
        if (!jobUuid || typeof window.aipkit_apiRequest !== "function") {
          return;
        }

        const issueCard = clearButton.closest("[data-aipkit-event-webhook-delivery-issue]");
        const buttonState = setIssueButtonPending(clearButton, "Clearing...");

        try {
          const response = await window.aipkit_apiRequest("aipkit_clear_event_webhook_delivery_issue", {
            job_uuid: jobUuid,
          });

          if (issueCard) {
            issueCard.remove();
            syncIssueSectionVisibility();
          }

          showIssueMessage("success", response?.message || "Webhook delivery issue cleared.");
        } catch (error) {
          resetIssueButtonPending(clearButton, buttonState);
          showIssueMessage("error", error?.message || "Failed to clear webhook delivery issue.");
        }
        return;
      }

      const retryButton = event.target.closest("[data-aipkit-retry-event-webhook-delivery-issue]");
      if (!retryButton || typeof window.aipkit_apiRequest !== "function") {
        return;
      }

      const jobUuid = String(retryButton.getAttribute("data-job-uuid") || "").trim();
      if (!jobUuid) {
        return;
      }

      const issueCard = retryButton.closest("[data-aipkit-event-webhook-delivery-issue]");
      const buttonState = setIssueButtonPending(retryButton, "Retrying...");

      try {
        const response = await window.aipkit_apiRequest("aipkit_retry_event_webhook_delivery_issue", {
          job_uuid: jobUuid,
        });

        if (response?.status === "resolved") {
          if (issueCard) {
            issueCard.remove();
            syncIssueSectionVisibility();
          }

          showIssueMessage("success", response?.message || "Webhook delivery retry succeeded.");
          return;
        }

        if (response?.status === "failed" && issueCard) {
          const replacementIssue = createIssueElement(response?.replacement_html || "");
          if (replacementIssue) {
            issueCard.replaceWith(replacementIssue);
          } else {
            resetIssueButtonPending(retryButton, buttonState);
          }

          showIssueMessage("error", response?.message || "Webhook delivery retry failed.");
          return;
        }

        resetIssueButtonPending(retryButton, buttonState);
        showIssueMessage(
          response?.status === "resolved" ? "success" : "info",
          response?.message || "Webhook delivery retry was queued."
        );
      } catch (error) {
        resetIssueButtonPending(retryButton, buttonState);
        showIssueMessage("error", error?.message || "Failed to retry webhook delivery issue.");
      }
    });

    section.dataset.aipkitEventWebhookDeliveryIssuesBound = "true";
  }

  window.aipkit_initEventWebhookSettingsUI = initEventWebhookSettingsUI;
  window.aipkit_initEventWebhookDeliveryIssuesUI = initEventWebhookDeliveryIssuesUI;
})();
