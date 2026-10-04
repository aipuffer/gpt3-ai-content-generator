/**
 * AIPKit AutoGPT - Task Sections Visibility Handler
 * Manages task sections for creating/editing tasks.
 */
(function () {
  "use strict";

  let currentTaskType = "";

  const COLLAPSIBLE_CARD_KEYS = ["schedule", "setup", "media", "advanced"];
  const INSPECTOR_CARD_STATE_STORAGE_KEY = "aipkit_autogpt_inspector_cards_v1";

  const wizardConfig = {
    content_indexing: {
      contentMap: {
        setup: "task_form_setup",
        status: "task_config_status",
      },
    },
    content_writing: {
      contentMap: {
        setup: "task_form_setup",
        status: "task_config_status",
      },
    },
    enhance_existing_content: {
      contentMap: {
        setup: "task_form_setup",
        status: "task_config_status",
      },
    },
    community_reply_comments: {
      contentMap: {
        setup: "task_form_setup",
        status: "task_config_status",
        filters: "task_config_comment_reply",
      },
    },
  };

  function updateAutogptProviderNotice() {
    const form = document.getElementById("aipkit_automated_task_form");
    const noticeEl = document.getElementById("aipkit_provider_notice_autogpt");
    if (!form || !noticeEl) {
      return;
    }

    const deferredSelects = form.querySelectorAll(
      'select[data-aipkit-provider-notice-target="aipkit_provider_notice_autogpt"][data-aipkit-provider-notice-defer="1"]'
    );
    deferredSelects.forEach((selectEl) => {
      delete selectEl.dataset.aipkitProviderNoticeArmed;
    });

    const taskType = String(form.elements?.task_type?.value || "");
    const providerId = taskType.startsWith("content_writing")
      ? "aipkit_task_cw_ai_provider"
      : taskType === "enhance_existing_content"
        ? "aipkit_task_ce_ai_provider"
        : taskType === "community_reply_comments"
          ? "aipkit_task_cc_ai_provider"
          : "";
    const activeSelect = providerId ? document.getElementById(providerId) : null;
    if (!activeSelect) {
      noticeEl.classList.add("aipkit_provider_notice--hidden");
      window.aipkit_autogpt_provider_setup?.syncInlineCredentialNotices?.(form);
      return;
    }

    activeSelect.dataset.aipkitProviderNoticeArmed = "true";

    const setupState =
      window.aipkit_autogpt_provider_setup?.getSetupState?.(
        activeSelect.value
      ) || "unknown";
    if (setupState === "missing") {
      const provider = String(activeSelect.value || "").toLowerCase();
      const message =
        noticeEl.getAttribute("data-message-default") ||
        "";
      if (typeof window.aipkit_updateProviderNotice === "function") {
        window.aipkit_updateProviderNotice(noticeEl, {
          providerKey: provider,
          message,
          visible: true,
        });
      } else {
        const messageSlot = noticeEl.querySelector(
          ".aipkit_provider_notice_message"
        );
        if (messageSlot) messageSlot.textContent = message;
        noticeEl.classList.remove("aipkit_provider_notice--hidden");
      }
    } else {
      if (typeof window.aipkit_updateProviderNotice === "function") {
        window.aipkit_updateProviderNotice(noticeEl, { visible: false });
      } else {
        noticeEl.classList.add("aipkit_provider_notice--hidden");
      }
    }
    window.aipkit_autogpt_provider_setup?.syncInlineCredentialNotices?.(form);
  }

  function getWizardConfigForType(taskType) {
    if (!taskType) {
      return null;
    }

    let configKey = "content_writing";
    if (taskType === "content_indexing") {
      configKey = "content_indexing";
    } else if (taskType === "community_reply_comments") {
      configKey = "community_reply_comments";
    } else if (taskType === "enhance_existing_content") {
      configKey = "enhance_existing_content";
    }

    return wizardConfig[configKey] || null;
  }

  function getInspectorStorageUserKey() {
    const userId =
      window.aipkit_automated_tasks_config?.current_user_id ||
      window.aipkit_dashboard?.currentUserId ||
      0;

    return String(userId || 0);
  }

  function getInspectorStorageScope(taskType) {
    if (!taskType) {
      return "";
    }

    if (taskType.startsWith("content_writing")) {
      return "content_writing";
    }

    if (taskType === "enhance_existing_content") {
      return "enhance_existing_content";
    }

    if (taskType === "community_reply_comments") {
      return "community_reply_comments";
    }

    if (taskType === "content_indexing") {
      return "content_indexing";
    }

    return String(taskType);
  }

  function loadInspectorCardStateStore() {
    try {
      const raw = window.localStorage.getItem(INSPECTOR_CARD_STATE_STORAGE_KEY);
      if (!raw) {
        return {};
      }
      const parsed = JSON.parse(raw);
      return parsed && typeof parsed === "object" ? parsed : {};
    } catch (error) {
      return {};
    }
  }

  function saveInspectorCardStateStore(store) {
    try {
      if (!store || !Object.keys(store).length) {
        window.localStorage.removeItem(INSPECTOR_CARD_STATE_STORAGE_KEY);
        return;
      }
      window.localStorage.setItem(
        INSPECTOR_CARD_STATE_STORAGE_KEY,
        JSON.stringify(store)
      );
    } catch (error) {
      // Ignore localStorage failures silently.
    }
  }

  function getStoredInspectorCardState(taskType) {
    const scope = getInspectorStorageScope(taskType);
    if (!scope) {
      return {};
    }

    const store = loadInspectorCardStateStore();
    const userKey = getInspectorStorageUserKey();
    const userStore = store[userKey];

    if (!userStore || typeof userStore !== "object") {
      return {};
    }

    const scopeStore = userStore[scope];
    return scopeStore && typeof scopeStore === "object" ? scopeStore : {};
  }

  function persistInspectorCardState(taskType, cardKey, isCollapsed) {
    const scope = getInspectorStorageScope(taskType);
    if (!scope || !COLLAPSIBLE_CARD_KEYS.includes(cardKey)) {
      return;
    }

    const store = loadInspectorCardStateStore();
    const userKey = getInspectorStorageUserKey();
    if (!store[userKey] || typeof store[userKey] !== "object") {
      store[userKey] = {};
    }
    if (!store[userKey][scope] || typeof store[userKey][scope] !== "object") {
      store[userKey][scope] = {};
    }

    store[userKey][scope][cardKey] = !!isCollapsed;
    saveInspectorCardStateStore(store);
  }

  function syncInspectorCards(taskType) {
    const cards = {
      schedule: document.getElementById("aipkit_autogpt_schedule_card"),
      setup: document.getElementById("aipkit_autogpt_setup_card"),
      media: document.getElementById("aipkit_autogpt_media_card"),
      advanced: document.getElementById("aipkit_autogpt_advanced_card"),
    };

    const visibility = {
      schedule: false,
      setup: false,
      media: false,
      advanced: false,
    };

    if (taskType) {
      visibility.schedule = true;

      if (taskType.startsWith("content_writing")) {
        visibility.setup = true;
        visibility.media = true;
        visibility.advanced = true;
      } else if (taskType === "enhance_existing_content") {
        visibility.setup = true;
        visibility.advanced = true;
      } else if (taskType === "community_reply_comments") {
        visibility.setup = true;
      } else if (taskType === "content_indexing") {
        visibility.schedule = true;
      }
    }

    Object.entries(cards).forEach(([key, card]) => {
      if (!card) return;
      card.style.display = visibility[key] ? "" : "none";
    });

    syncInspectorCardCollapseState(taskType, visibility);
  }

  function getInspectorDefaultOpenState(taskType) {
    const defaults = {
      schedule: false,
      setup: false,
      media: false,
      advanced: false,
    };

    if (!taskType) {
      return defaults;
    }

    defaults.schedule = true;

    if (taskType.startsWith("content_writing")) {
      defaults.setup = true;
      return defaults;
    }

    if (taskType === "enhance_existing_content") {
      defaults.setup = true;
      return defaults;
    }

    if (taskType === "community_reply_comments") {
      defaults.setup = true;
      return defaults;
    }

    return defaults;
  }

  function syncAutogptStageHeights() {
    if (typeof window.aipkit_syncAutogptStageHeights === "function") {
      window.aipkit_syncAutogptStageHeights();
    }
  }

  function setInspectorCardCollapsed(cardKey, collapsed, options = {}) {
    const { syncHeights = true } = options;
    const card = document.getElementById(`aipkit_autogpt_${cardKey}_card`);
    if (!card) {
      return;
    }

    const toggle = card.querySelector("[data-aipkit-autogpt-card-toggle]");
    const body = document.getElementById(`aipkit_autogpt_${cardKey}_card_body`);
    const isCollapsed = !!collapsed;

    card.dataset.collapsed = isCollapsed ? "true" : "false";

    if (toggle) {
      toggle.setAttribute("aria-expanded", isCollapsed ? "false" : "true");
    }

    if (body) {
      body.hidden = isCollapsed;
    }

    if (syncHeights) {
      requestAnimationFrame(syncAutogptStageHeights);
    }
  }

  function syncInspectorCardCollapseState(taskType, visibility) {
    const defaults = getInspectorDefaultOpenState(taskType);
    const storedState = getStoredInspectorCardState(taskType);

    COLLAPSIBLE_CARD_KEYS.forEach((cardKey) => {
      const shouldShow = !!visibility[cardKey];
      const card = document.getElementById(`aipkit_autogpt_${cardKey}_card`);
      const disclosureIsManagedByBuilder = Boolean(
        card?.closest("[data-aipkit-builder-step]")
      );
      const hasStoredValue = Object.prototype.hasOwnProperty.call(
        storedState,
        cardKey
      );
      const shouldBeCollapsed = disclosureIsManagedByBuilder
        ? false
        : shouldShow
          ? hasStoredValue
            ? !!storedState[cardKey]
            : !defaults[cardKey]
          : true;
      setInspectorCardCollapsed(cardKey, shouldBeCollapsed, {
        syncHeights: false,
      });
    });

    requestAnimationFrame(syncAutogptStageHeights);
  }

  function syncScopedSections(taskType, selector, datasetKey) {
    const sections = document.querySelectorAll(selector);

    if (!sections.length) {
      return;
    }

    const normalizedTaskKey = !taskType
      ? ""
      : taskType.startsWith("content_writing")
        ? "content_writing"
        : String(taskType);

    sections.forEach((section) => {
      const sectionKey = section.dataset[datasetKey] || "";
      const shouldShow = sectionKey === normalizedTaskKey;
      section.hidden = !shouldShow;
    });
  }

  function showContentSections(taskType) {
    const contentStepsContainer = document.querySelector(
      ".aipkit_wizard_content_container"
    );
    const saveBtn = document.getElementById("aipkit_save_task_btn");
    if (!contentStepsContainer) {
      return;
    }

    const config = getWizardConfigForType(taskType);
    if (!config) {
      contentStepsContainer
        .querySelectorAll(".aipkit_wizard_content_step")
        .forEach((contentEl) => {
          contentEl.style.display = "none";
          contentEl.classList.remove("aipkit_active");
        });
      if (saveBtn) {
        saveBtn.style.display = "none";
      }
      syncInspectorCards("");
      updateAutogptProviderNotice();
      return;
    }

    const activeContentIds = new Set(Object.values(config.contentMap));

    contentStepsContainer
      .querySelectorAll(".aipkit_wizard_content_step")
      .forEach((contentEl) => {
        const contentId = contentEl.dataset.contentId || "";
        if (activeContentIds.has(contentId)) {
          contentEl.style.display = "block";
          contentEl.classList.add("aipkit_active");
        } else {
          contentEl.style.display = "none";
          contentEl.classList.remove("aipkit_active");
        }
      });

    if (saveBtn) {
      saveBtn.style.display = "inline-flex";
    }

    syncInspectorCards(taskType);
    syncScopedSections(
      taskType,
      "[data-aipkit-autogpt-setup-section]",
      "aipkitAutogptSetupSection"
    );
    syncScopedSections(
      taskType,
      "[data-aipkit-autogpt-advanced-section]",
      "aipkitAutogptAdvancedSection"
    );
    syncScopedSections(
      taskType,
      "[data-aipkit-autogpt-schedule-section]",
      "aipkitAutogptScheduleSection"
    );
    updateAutogptProviderNotice();
  }

  /**
   * Renders the wizard steps based on the selected task type.
   * @param {string} taskType - The selected task type (e.g., 'content_indexing', 'content_writing_bulk').
   */
  function aipkit_renderWizard(taskType) {
    currentTaskType = taskType;
    showContentSections(taskType);
  }

  document.addEventListener("click", (event) => {
    const toggle = event.target.closest("[data-aipkit-autogpt-card-toggle]");
    if (!toggle) {
      return;
    }

    const cardKey = toggle.dataset.aipkitAutogptCardToggle || "";
    if (!COLLAPSIBLE_CARD_KEYS.includes(cardKey)) {
      return;
    }

    const isExpanded = toggle.getAttribute("aria-expanded") === "true";
    setInspectorCardCollapsed(cardKey, isExpanded);
    persistInspectorCardState(currentTaskType, cardKey, isExpanded);
  });

  window.aipkit_renderWizard = aipkit_renderWizard;
  window.aipkit_updateAutogptProviderNotice = updateAutogptProviderNotice;
  window.aipkit_resetWizard = () => {
    currentTaskType = "";
    showContentSections("");
  };
})();
