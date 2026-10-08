/**
 * Webhooks in For developers: endpoints are rows in the Webhooks panel, and each opens its own panel over it,
 * with where it sends, what it receives, a test send, and any sends that didn't go through.
 */
(function () {
  "use strict";

  const __ = window.wp?.i18n?.__ || ((text) => text);
  const ENDPOINT_MODAL_PREFIX = "dev-endpoint-";

  const getSection = () =>
    document.getElementById("aipkit_settings_container")?.querySelector("#aipkit_settings_event_webhooks_section") || null;
  const getList = (section) => section?.querySelector("[data-aipkit-event-webhook-list]") || null;
  const getRows = (section) => section?.querySelector("[data-aipkit-event-webhook-rows]") || null;
  const getEndpoints = (section) => Array.from(getList(section)?.querySelectorAll("[data-aipkit-event-webhook-endpoint]") || []);
  const field = (endpoint, name) => endpoint?.querySelector(`[data-aipkit-endpoint-field="${name}"]`) || null;
  const eventInputs = (endpoint) => Array.from(endpoint?.querySelectorAll('[data-aipkit-endpoint-field="event"]') || []);
  const endpointId = (endpoint) => field(endpoint, "id")?.value || "";
  const format = (template, value) => String(template || "").replace(/%[sd]/, () => String(value));

  const hostOf = (url) => {
    const text = String(url || "").trim();
    try {
      return new URL(text).host || text;
    } catch (error) {
      return text;
    }
  };

  const wordsCache = new WeakMap();
  function words(section) {
    if (!section) {
      return {};
    }
    if (!wordsCache.has(section)) {
      let parsed = {};
      try {
        parsed = JSON.parse(section.querySelector("[data-aipkit-event-webhook-words]")?.textContent || "{}") || {};
      } catch (error) {
        parsed = {};
      }
      wordsCache.set(section, parsed);
    }
    return wordsCache.get(section);
  }

  function createEndpointId() {
    if (window.crypto?.randomUUID) {
      return `endpoint_${window.crypto.randomUUID().replace(/-/g, "")}`;
    }
    const randomPart = window.crypto?.getRandomValues
      ? Array.from(window.crypto.getRandomValues(new Uint32Array(2))).map((value) => value.toString(36)).join("")
      : Math.random().toString(36).slice(2);
    return `endpoint_${Date.now().toString(36)}${randomPart}`;
  }

  function eventsLabel(section, count) {
    const text = words(section);
    if (count === 0) {
      return text.noEvents || "";
    }
    if (Number(text.total) > 0 && count >= Number(text.total)) {
      return text.allEvents || "";
    }
    return format(count === 1 ? text.oneEvent : text.manyEvents, count);
  }

  const rowFor = (section, endpoint) => {
    const id = endpointId(endpoint);
    return id ? getRows(section)?.querySelector(`[data-aipkit-event-webhook-row="${CSS.escape(id)}"]`) || null : null;
  };

  // ---------- failed sends ----------

  const readFailures = (box) => {
    try {
      const ids = JSON.parse(box?.dataset.aipkitWebhookFailures || "[]");
      return Array.isArray(ids) ? ids.filter((id) => typeof id === "string" && id !== "") : [];
    } catch (error) {
      return [];
    }
  };

  const failureBoxes = (scope) => Array.from(scope?.querySelectorAll("[data-aipkit-webhook-failures]:not([hidden])") || []);

  // ---------- an endpoint's row, its panel header and the Webhooks row ----------

  function statusOf(section, endpoint) {
    const text = words(section);
    const box = failureBoxes(endpoint).find((candidate) => readFailures(candidate).length > 0);
    if (box) {
      return { kind: "failed", label: box.dataset.chipLabel || "" };
    }
    if (!field(endpoint, "enabled")?.checked) {
      return { kind: "paused", label: text.paused || "" };
    }
    if (endpoint.dataset.sentLabel) {
      return { kind: "sent", label: endpoint.dataset.sentLabel };
    }
    return { kind: "none", label: text.notSent || "" };
  }

  function updateEndpoint(section, endpoint) {
    if (!endpoint) {
      return;
    }
    const text = words(section);
    const name = String(field(endpoint, "name")?.value || "").trim();
    const host = hostOf(field(endpoint, "url")?.value);
    const selected = eventInputs(endpoint).filter((input) => input.checked).length;
    const title = name || host || text.newEndpoint || "";
    const events = eventsLabel(section, selected);
    const status = statusOf(section, endpoint);

    const heading = endpoint.querySelector("[data-aipkit-event-webhook-endpoint-title]");
    if (heading) {
      heading.textContent = title;
    }
    const hint = endpoint.querySelector("[data-aipkit-event-webhook-endpoint-hint]");
    if (hint) {
      hint.textContent = [text.webhooks, events].filter(Boolean).join(" · ");
    }
    const row = rowFor(section, endpoint);
    if (row) {
      const rowName = row.querySelector("[data-aipkit-event-webhook-row-name]");
      if (rowName) {
        rowName.textContent = title;
      }
      const meta = row.querySelector("[data-aipkit-event-webhook-row-meta]");
      if (meta) {
        meta.textContent = [host, events].filter(Boolean).join(" · ");
      }
    }
    [endpoint, row].forEach((scope) => {
      scope?.querySelectorAll("[data-aipkit-webhook-status]").forEach((element) => {
        element.dataset.aipkitWebhookStatus = status.kind;
        element.textContent = status.label;
      });
    });
  }

  // "2 endpoints · 1 failed send" in red, "On · 2 endpoints", "On · No endpoints yet", or "Off".
  function updateSummary(section) {
    const summary = section?.querySelector("[data-aipkit-developer-summary]");
    if (!section || !summary) {
      return;
    }
    const enabled = section.dataset.enabled === "true";
    const count = getEndpoints(section).length;
    const failed = new Set(failureBoxes(getList(section)).flatMap(readFailures)).size;
    const data = summary.dataset;
    let label = data.off || "";
    if (enabled && failed > 0) {
      label = `${format(count === 1 ? data.countOne : data.countMany, count)} · ${format(failed === 1 ? data.failedOne : data.failedMany, failed)}`;
    } else if (enabled) {
      label = count === 0 ? data.none || "" : format(count === 1 ? data.one : data.many, count);
    }
    summary.textContent = label;
    section.classList.toggle("has-provider-error", enabled && failed > 0);
  }

  function updateAll(section) {
    getEndpoints(section).forEach((endpoint) => updateEndpoint(section, endpoint));
    updateSummary(section);
  }

  // ---------- form names: the save reads endpoints as a complete, ordered list ----------

  function reindex(section) {
    getEndpoints(section).forEach((endpoint, index) => {
      endpoint.dataset.endpointIndex = String(index);
      const idField = field(endpoint, "id");
      const previousId = idField?.value || "";
      if (idField && !previousId) {
        // A stable identity before the first save, so each edit doesn't make a new endpoint.
        idField.value = createEndpointId();
      }
      const id = idField?.value || "";
      const row = previousId ? getRows(section)?.querySelector(`[data-aipkit-event-webhook-row="${CSS.escape(previousId)}"]`) : null;
      endpoint.dataset.aipkitProviderModal = ENDPOINT_MODAL_PREFIX + id;
      if (row) {
        row.dataset.aipkitProviderSettingsOpen = ENDPOINT_MODAL_PREFIX + id;
      }

      endpoint.querySelectorAll("[data-aipkit-endpoint-field]").forEach((input) => {
        const name = input.getAttribute("data-aipkit-endpoint-field");
        if (name === "event") {
          const key = input.getAttribute("data-aipkit-event-field-key");
          if (!key) {
            return;
          }
          input.name = `event_webhooks[endpoints][${index}][events][${key}]`;
          const label = input.closest("label");
          input.id = `aipkit_event_webhook_endpoint_${index}_event_${key}`;
          label?.setAttribute("for", input.id);
          return;
        }
        input.name = `event_webhooks[endpoints][${index}][${name}]`;
        if (input.type !== "hidden") {
          const previous = input.id;
          input.id = `aipkit_event_webhook_endpoint_${index}_${name}`;
          endpoint.querySelectorAll(`label[for="${CSS.escape(previous)}"]`).forEach((label) => label.setAttribute("for", input.id));
        }
      });
      const title = endpoint.querySelector("[data-aipkit-event-webhook-endpoint-title]");
      if (title) {
        title.id = `aipkit_event_webhook_endpoint_${index}_title`;
        endpoint.querySelector('[role="dialog"]')?.setAttribute("aria-labelledby", title.id);
      }
    });
  }

  function saveNow() {
    if (typeof window.aipkit_handleAutoSave === "function") {
      return window.aipkit_handleAutoSave();
    }
    return Promise.resolve(false);
  }

  // ---------- panels: an endpoint opens over Webhooks, and Back, Escape or Done return to it ----------

  function openParent(endpoint) {
    const section = endpoint?.closest("#aipkit_settings_event_webhooks_section");
    const parent = endpoint?.dataset.aipkitDeveloperParent;
    if (!section || !parent || typeof window.aipkit_openProviderModal !== "function") {
      return;
    }
    const row = rowFor(section, endpoint);
    window.aipkit_openProviderModal(parent, section.querySelector(".aipkit_settings_provider_card_action"));
    if (row) {
      window.setTimeout(() => row.focus(), 20);
    }
  }

  function addEndpoint(section, trigger) {
    const list = getList(section);
    const template = section.querySelector("#aipkit_event_webhook_endpoint_template");
    const rowTemplate = section.querySelector("template[data-aipkit-event-webhook-row-template]");
    const addItem = section.querySelector("#aipkit_add_event_webhook_endpoint_btn")?.closest("li");
    if (!list || !template || !rowTemplate || !addItem) {
      return;
    }
    const endpoint = document.importNode(template.content, true).querySelector("[data-aipkit-event-webhook-endpoint]");
    const rowItem = document.importNode(rowTemplate.content, true).querySelector("li");
    if (!endpoint || !rowItem) {
      return;
    }
    list.appendChild(endpoint);
    reindex(section);
    const id = endpointId(endpoint);
    const row = rowItem.querySelector("[data-aipkit-event-webhook-row]");
    row.dataset.aipkitEventWebhookRow = id;
    row.dataset.aipkitProviderSettingsOpen = ENDPOINT_MODAL_PREFIX + id;
    addItem.before(rowItem);
    updateEndpoint(section, endpoint);
    updateSummary(section);
    void saveNow();
    if (typeof window.aipkit_openProviderModal === "function") {
      window.aipkit_openProviderModal(ENDPOINT_MODAL_PREFIX + id, trigger);
      window.setTimeout(() => field(endpoint, "name")?.focus(), 30);
    }
  }

  function removeEndpoint(section, endpoint, button) {
    const text = words(section);
    const execute = () => {
      const row = rowFor(section, endpoint);
      window.aipkit_closeProviderModal?.();
      endpoint.remove();
      row?.closest("li")?.remove();
      reindex(section);
      updateSummary(section);
      void saveNow();
      window.aipkit_openProviderModal?.("dev-webhooks", section.querySelector(".aipkit_settings_provider_card_action"));
      window.setTimeout(() => section.querySelector("#aipkit_add_event_webhook_endpoint_btn")?.focus(), 30);
    };
    if (typeof window.aipkit_showConfirmModal === "function") {
      window.aipkit_showConfirmModal(text.deleteText || "", {
        title: text.deleteTitle || "",
        confirmText: text.deleteButton || __("Delete endpoint", "gpt3-ai-content-generator"),
        cancelText: text.cancel || __("Cancel", "gpt3-ai-content-generator"),
        variant: "danger",
        onConfirm: execute,
        onCancel: () => button?.isConnected && button.focus(),
      });
      return;
    }
    if (window.confirm(text.deleteText || "")) {
      execute();
    }
  }

  // ---------- a test send, and sending failed ones again ----------

  function showResult(endpoint, message, isError) {
    const result = endpoint.querySelector("[data-aipkit-webhook-result]");
    if (!result) {
      return;
    }
    result.textContent = message || "";
    result.hidden = !message;
    result.classList.toggle("is-error", Boolean(message) && isError);
  }

  function setBusy(button, busy) {
    if (!button) {
      return;
    }
    button.disabled = busy;
    button.classList.toggle("aipkit_loading", busy);
    const label = button.querySelector("[data-aipkit-webhook-test-label]");
    if (label) {
      if (busy) {
        label.dataset.idle = label.textContent;
        label.textContent = button.dataset.busyLabel || label.textContent;
      } else if (label.dataset.idle) {
        label.textContent = label.dataset.idle;
      }
    }
  }

  async function sendTest(section, endpoint, button) {
    if (typeof window.aipkit_apiRequest !== "function") {
      return;
    }
    showResult(endpoint, "");
    setBusy(button, true);
    try {
      // The test goes to the saved endpoint, so what's typed is saved first.
      if (await saveNow() === false) {
        throw new Error(__("Save the endpoint successfully before sending a test event.", "gpt3-ai-content-generator"));
      }
      const response = await window.aipkit_apiRequest("aipkit_send_event_webhook_test", { endpoint_id: endpointId(endpoint) });
      if (response?.status === "delivered") {
        const text = words(section);
        endpoint.dataset.sentLabel = format(text.sent, text.justNow);
        showResult(endpoint, response.message || "", false);
      } else {
        showResult(endpoint, response?.issue?.reason || response?.message || "", true);
      }
    } catch (error) {
      showResult(endpoint, error?.message || "", true);
    } finally {
      setBusy(button, false);
      updateEndpoint(section, endpoint);
    }
  }

  // The box keeps what's left: its title counts them, and the newest one says what went wrong.
  function applyFailures(section, box, ids, latest = null, message = "") {
    box.dataset.aipkitWebhookFailures = JSON.stringify(ids);
    box.hidden = ids.length === 0;
    const set = (selector, value) => {
      const element = box.querySelector(selector);
      if (element && value !== undefined && value !== null) {
        element.textContent = value;
      }
    };
    set("[data-aipkit-webhook-failure-title]", ids.length > 1 ? format(box.dataset.titleMany, ids.length) : box.dataset.titleOne);
    set("[data-aipkit-webhook-failure-retry-label]", ids.length > 1 ? box.dataset.retryMany : box.dataset.retryOne);
    if (latest) {
      if (latest.when) {
        box.dataset.chipLabel = format(box.dataset.chipTemplate, latest.when);
      }
      set("[data-aipkit-webhook-failure-reason]", latest.reason);
      set("[data-aipkit-webhook-failure-when]", [latest.when, latest.event].filter(Boolean).join(" · "));
      set("[data-aipkit-webhook-failure-details]", latest.details);
    } else if (message) {
      set("[data-aipkit-webhook-failure-reason]", message);
    }
    const endpoint = box.closest("[data-aipkit-event-webhook-endpoint]");
    updateEndpoint(section, endpoint);
  }

  function holdButtons(box, button, busy) {
    box.querySelectorAll("button").forEach((other) => {
      other.disabled = busy;
    });
    if (button) {
      button.classList.toggle("aipkit_loading", busy);
    }
  }

  // Each failed send is its own event, so all of them go again, one after another.
  async function handleFailures(section, box, button, retry) {
    if (!box || typeof window.aipkit_apiRequest !== "function") {
      return;
    }
    const endpoint = box.closest("[data-aipkit-event-webhook-endpoint]");
    const remaining = [];
    const gone = new Set();
    let latest = null;
    let message = "";
    showResult(endpoint, "");
    holdButtons(box, button, true);
    try {
      for (const id of readFailures(box)) {
        try {
          if (retry) {
            const response = await window.aipkit_apiRequest("aipkit_retry_event_webhook_delivery_issue", { job_uuid: id, endpoint_id: endpointId(endpoint) });
            if (response?.status === "failed") {
              remaining.push(id);
              latest = latest || response.issue || null;
              continue;
            }
            if (response?.status !== "resolved") {
              remaining.push(id);
              message = message || response?.message || __("The retry is queued. Delivery is not confirmed yet.", "gpt3-ai-content-generator");
              continue;
            }
          } else {
            await window.aipkit_apiRequest("aipkit_clear_event_webhook_delivery_issue", { job_uuid: id });
          }
          gone.add(id);
        } catch (error) {
          remaining.push(id);
          message = message || error?.message || "";
        }
      }
    } finally {
      holdButtons(box, button, false);
    }
    applyFailures(section, box, remaining, latest, message);
    // A send that failed for several endpoints is gone from all of them.
    failureBoxes(getList(section)).forEach((other) => {
      if (other !== box && readFailures(other).some((id) => gone.has(id))) {
        applyFailures(section, other, readFailures(other).filter((id) => !gone.has(id)));
      }
    });
    updateSummary(section);
    if (box.hidden) {
      if (retry) {
        showResult(endpoint, box.dataset.done || "", false);
      }
      const target = endpoint.querySelector("[data-aipkit-webhook-result]:not([hidden])") || endpoint.querySelector("[data-aipkit-event-webhook-endpoint-title]");
      if (target) {
        target.setAttribute("tabindex", "-1");
        target.focus();
      }
    }
  }

  // ---------- events ----------

  function initEventWebhookSettingsUI() {
    const section = getSection();
    if (!section) {
      return;
    }
    reindex(section);
    updateAll(section);
    if (section.dataset.aipkitEventWebhooksBound === "true") {
      return;
    }

    section.addEventListener("click", (event) => {
      const target = event.target;
      const add = target.closest("#aipkit_add_event_webhook_endpoint_btn");
      if (add) {
        event.preventDefault();
        addEndpoint(section, add);
        return;
      }
      const endpoint = target.closest("[data-aipkit-event-webhook-endpoint]");
      if (!endpoint) {
        return;
      }
      if (target.closest("[data-aipkit-developer-back]")) {
        event.preventDefault();
        openParent(endpoint);
        return;
      }
      const remove = target.closest("[data-aipkit-remove-event-webhook-endpoint]");
      if (remove) {
        event.preventDefault();
        removeEndpoint(section, endpoint, remove);
        return;
      }
      const all = target.closest("[data-aipkit-event-webhook-events-select-all]");
      const none = target.closest("[data-aipkit-event-webhook-events-clear]");
      if (all || none) {
        event.preventDefault();
        let changed = false;
        eventInputs(endpoint).forEach((input) => {
          if (input.checked !== Boolean(all)) {
            input.checked = Boolean(all);
            changed = true;
          }
        });
        updateEndpoint(section, endpoint);
        if (changed) {
          void saveNow();
        }
        return;
      }
      const test = target.closest("[data-aipkit-webhook-test]");
      if (test) {
        event.preventDefault();
        void sendTest(section, endpoint, test);
        return;
      }
      const retry = target.closest("[data-aipkit-webhook-failure-retry]");
      const dismiss = target.closest("[data-aipkit-webhook-failure-dismiss]");
      if (retry || dismiss) {
        event.preventDefault();
        void handleFailures(section, (retry || dismiss).closest("[data-aipkit-webhook-failures]"), retry || dismiss, Boolean(retry));
      }
    });

    section.addEventListener("input", (event) => {
      if (event.target.matches?.('[data-aipkit-endpoint-field="name"], [data-aipkit-endpoint-field="url"]')) {
        updateEndpoint(section, event.target.closest("[data-aipkit-event-webhook-endpoint]"));
      }
    });

    section.addEventListener("change", (event) => {
      const endpoint = event.target.closest?.("[data-aipkit-event-webhook-endpoint]");
      if (!endpoint) {
        return;
      }
      updateEndpoint(section, endpoint);
      // Events aren't autosave fields of their own, so a tick saves right away.
      if (event.target.matches('[data-aipkit-endpoint-field="event"]')) {
        void saveNow();
      }
    });

    // The Webhooks switch lives in developer-settings.js; the row's summary follows it.
    section.addEventListener("aipkit:developer-state", () => updateSummary(section));

    if (!window.aipkit_developerPanelsBackBound) {
      window.aipkit_developerPanelsBackBound = true;
      window.addEventListener("aipkit:provider-modal-closed", (event) => {
        const child = event.detail?.modal;
        if (!child?.matches?.("[data-aipkit-developer-parent]") || !child.isConnected) {
          return;
        }
        window.setTimeout(() => {
          if (!document.querySelector("#aipkit_settings_container .aipkit_settings_provider_modal.aipkit-active")) {
            openParent(child);
          }
        }, 0);
      });
    }

    section.dataset.aipkitEventWebhooksBound = "true";
  }

  // Failed sends now live in each endpoint's panel and are bound with the rest of webhooks.
  function initEventWebhookDeliveryIssuesUI() {
    initEventWebhookSettingsUI();
  }

  window.aipkit_initEventWebhookSettingsUI = initEventWebhookSettingsUI;
  window.aipkit_initEventWebhookDeliveryIssuesUI = initEventWebhookDeliveryIssuesUI;
})();
