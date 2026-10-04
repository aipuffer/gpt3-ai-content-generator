/**
 * AIPKit Settings - Knowledge Base UI
 * Owns shared tabs, Visibility and autosaving; paid controls are supplied by lib.
 */
(function () {
  "use strict";

  const i18n = window.wp && window.wp.i18n ? window.wp.i18n : null;
  const __ = i18n && typeof i18n.__ === "function" ? i18n.__ : (text) => text;
  const sprintf =
    i18n && typeof i18n.sprintf === "function"
      ? i18n.sprintf
      : (format, ...args) => format.replace(/%s/g, () => args.shift());
  const SAVE_FAILED_TEXT = __("Save failed.", "gpt3-ai-content-generator");

  const DEFAULT_KB_TAB = "chunking";
  const ACTIVE_KB_TAB_STORAGE_KEY = "aipkit_kb_settings_active_tab_v2";

  const getStoredKbTab = () => {
    try {
      return window.localStorage?.getItem(ACTIVE_KB_TAB_STORAGE_KEY) || "";
    } catch (error) {
      return "";
    }
  };

  const setStoredKbTab = (tabKey) => {
    try {
      window.localStorage?.setItem(ACTIVE_KB_TAB_STORAGE_KEY, tabKey);
    } catch (error) {
      // Ignore storage failures so tab switching still works.
    }
  };

  function aipkit_initKnowledgeBaseSettingsUI() {
    const page = document.getElementById("aipkit_settings_knowledge_base_page");
    if (!page) {
      return;
    }

    if (page.dataset.knowledgeBaseUiBound === "true") {
      if (typeof page.aipkitEnsureActiveTabContent === "function") {
        page.aipkitEnsureActiveTabContent();
      }
      return;
    }

    const settingsConfig = {
      nonce: page.dataset.indexingNonce || "",
      isPro:
        page.dataset.indexingIsPro === "1" ||
        page.dataset.indexingIsPro === "true",
    };
    const paidSettings =
      settingsConfig.isPro &&
      typeof window.aipkit_createKnowledgeBaseSettings === "function"
        ? window.aipkit_createKnowledgeBaseSettings({
            page,
            settingsConfig,
            __,
            sprintf,
            markCurrentSettingsSaved: () => markCurrentSettingsSaved(),
            scheduleAutosave: () => scheduleAutosave(),
          })
        : null;
    let autosaveTimer = null;
    let autosaveInFlight = false;
    let autosavePending = false;
    let lastSavedSettingsSnapshot = null;

    const isSourcesContext = Boolean(page.closest("#aipkit_sources_module_container"));
    const headerMessageContainerId = isSourcesContext
      ? "aipkit_sources_status"
      : "aipkit_settings_global_messages";
    const kbTabButtons = Array.from(page.querySelectorAll("[data-aipkit-kb-tab]"));
    const kbTabPanels = Array.from(
      page.querySelectorAll("[data-aipkit-kb-tab-panel]")
    );
    const validKbTabs = kbTabButtons
      .map((button) => button.dataset.aipkitKbTab || "")
      .filter(Boolean);

    const setInlineStatusMessage = (type, text) => {
      const messageContainer = document.getElementById(headerMessageContainerId);
      if (!messageContainer) {
        return false;
      }

      if (!messageContainer.classList.contains("aipkit_training_status")) {
        return false;
      }

      messageContainer.textContent = text || "";
      messageContainer.classList.remove(
        "is-visible",
        "is-success",
        "is-error",
        "is-warning",
        "is-loading"
      );

      if (text) {
        messageContainer.classList.add("is-visible");
      }
      if (type) {
        messageContainer.classList.add(`is-${type}`);
      }

      return true;
    };

    const showMessage = (type, text) => {
      if (setInlineStatusMessage(type, text)) {
        return;
      }
      if (typeof window.aipkit_showMessage === "function") {
        window.aipkit_showMessage(headerMessageContainerId, type, text);
      }
    };

    const clearAutosaveMessages = () => {
      if (setInlineStatusMessage("", "")) {
        return;
      }
      if (typeof window.aipkit_clearStatusMessages === "function") {
        window.aipkit_clearStatusMessages(headerMessageContainerId);
      }
    };

    const getAutosaveScope = () => {
      if (isSourcesContext) {
        const activePanel = kbTabPanels.find((panel) => !panel.hidden);
        const activeCard = activePanel?.querySelector(
          ".aipkit_settings_kb_card, .aipkit_settings_kb_search_card"
        );

        if (activeCard) {
          return activeCard;
        }
      }

      return page.closest(".aipkit_sources_workspace_panel") || page;
    };

    const setAutosaveBusy = (show, scope) => {
      if (typeof window.aipkit_setSettingsAutosaveBusy !== "function") {
        return;
      }

      window.aipkit_setSettingsAutosaveBusy(show, scope || undefined);
    };

    const getSettingsFromForm = () => {
      const settings = {};
      const showUploadsCheckbox = page.querySelector(
        "#aipkit_show_user_uploads"
      );
      settings.hide_user_uploads = showUploadsCheckbox
        ? !showUploadsCheckbox.checked
        : true;

      paidSettings?.collect(settings);

      return settings;
    };

    const getSettingsSnapshot = () => JSON.stringify(getSettingsFromForm());

    const markCurrentSettingsSaved = () => {
      lastSavedSettingsSnapshot = getSettingsSnapshot();
    };

    const saveIndexingSettings = async () => {
      if (!settingsConfig.nonce || typeof window.aipkit_apiRequest !== "function") {
        return;
      }

      const settingsSnapshot = getSettingsSnapshot();
      if (settingsSnapshot === lastSavedSettingsSnapshot) {
        return;
      }

      const autosaveScope = getAutosaveScope();
      clearAutosaveMessages();
      setAutosaveBusy(true, autosaveScope);

      try {
        await window.aipkit_apiRequest(
          "aipkit_save_cpt_indexing_options",
          {
            _ajax_nonce: settingsConfig.nonce,
            settings: settingsSnapshot,
          }
        );
        lastSavedSettingsSnapshot = settingsSnapshot;
        document.dispatchEvent(
          new CustomEvent("aipkit:knowledgeContentRulesSaved", {
            detail: { source: "content-rules" },
          })
        );
        clearAutosaveMessages();
      } catch (error) {
        showMessage(
          "error",
          error && error.message
            ? sprintf(
                __("Save failed: %s", "gpt3-ai-content-generator"),
                error.message
              )
            : SAVE_FAILED_TEXT
        );
      } finally {
        setAutosaveBusy(false, autosaveScope);
      }
    };

    const runAutosave = async () => {
      if (autosaveInFlight) {
        autosavePending = true;
        return;
      }
      autosaveInFlight = true;
      try {
        await saveIndexingSettings();
      } finally {
        autosaveInFlight = false;
      }
      if (autosavePending) {
        autosavePending = false;
        runAutosave();
      }
    };

    const scheduleAutosave = () => {
      if (!settingsConfig.nonce) {
        return;
      }
      if (autosaveTimer) {
        window.clearTimeout(autosaveTimer);
      }
      autosaveTimer = window.setTimeout(runAutosave, 600);
    };

    paidSettings?.bindRules();

    const showUploads = page.querySelector("#aipkit_show_user_uploads");
    showUploads?.addEventListener("change", () => {
      paidSettings?.sync();
      scheduleAutosave();
    });

    paidSettings?.bindFields();

    paidSettings?.sync();
    markCurrentSettingsSaved();

    const initIndexingIfVisible = () => paidSettings?.initIfVisible();

    const ensureActiveTabContent = () => {
      const activeButton = kbTabButtons.find(
        (button) => button.getAttribute("aria-selected") === "true"
      );
      if (activeButton?.dataset.aipkitKbTab === "content-rules") {
        initIndexingIfVisible();
      }
    };

    page.aipkitEnsureActiveTabContent = ensureActiveTabContent;

    const activateKnowledgeBaseTab = (tabKey, persist = true) => {
      if (!kbTabButtons.length || !kbTabPanels.length) {
        return;
      }

      const nextTab = validKbTabs.includes(tabKey) ? tabKey : DEFAULT_KB_TAB;

      kbTabButtons.forEach((button) => {
        const isActive = button.dataset.aipkitKbTab === nextTab;
        button.classList.toggle("aipkit_active", isActive);
        button.setAttribute("aria-selected", isActive ? "true" : "false");
        button.tabIndex = isActive ? 0 : -1;
      });

      kbTabPanels.forEach((panel) => {
        panel.hidden = panel.dataset.aipkitKbTabPanel !== nextTab;
      });

      if (nextTab === "content-rules") {
        initIndexingIfVisible();
      }

      if (persist) {
        setStoredKbTab(nextTab);
      }
    };

    if (kbTabButtons.length && kbTabPanels.length) {
      activateKnowledgeBaseTab(getStoredKbTab(), false);

      kbTabButtons.forEach((button) => {
        button.addEventListener("click", () => {
          activateKnowledgeBaseTab(button.dataset.aipkitKbTab || DEFAULT_KB_TAB);
        });

        button.addEventListener("keydown", (event) => {
          const currentIndex = kbTabButtons.indexOf(button);
          if (currentIndex === -1) {
            return;
          }

          let nextIndex = null;
          if (event.key === "ArrowRight") {
            nextIndex = (currentIndex + 1) % kbTabButtons.length;
          } else if (event.key === "ArrowLeft") {
            nextIndex =
              (currentIndex - 1 + kbTabButtons.length) % kbTabButtons.length;
          } else if (event.key === "Home") {
            nextIndex = 0;
          } else if (event.key === "End") {
            nextIndex = kbTabButtons.length - 1;
          }

          if (nextIndex === null) {
            return;
          }

          event.preventDefault();
          const nextButton = kbTabButtons[nextIndex];
          if (!nextButton) {
            return;
          }
          nextButton.focus();
          activateKnowledgeBaseTab(nextButton.dataset.aipkitKbTab || DEFAULT_KB_TAB);
        });
      });
    }

    page.dataset.knowledgeBaseUiBound = "true";
  }

  window.aipkit_initKnowledgeBaseSettingsUI = aipkit_initKnowledgeBaseSettingsUI;
})();
