/**
 * AIPKit Settings - Semantic Search UI Handler
 *
 * Handles the dynamic population of the Index/Collection dropdown
 * based on the selected vector database provider, and also handles
 * the embedding provider hidden field based on the selected embedding model.
 */
(function () {
  "use strict";

  let pineconeIndexes = [];
  let qdrantCollections = [];
  let chromaCollections = [];
  let localStores = [];

  const __ = window.wp?.i18n?.__ || ((text) => text);
  const SAVE_FAILED_TEXT = __("Save failed.", "gpt3-ai-content-generator");
  const SAVE_FAILED_PREFIX = __("Save failed: ", "gpt3-ai-content-generator");

  function parseJsonList(raw) {
    if (!raw) {
      return [];
    }
    try {
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : [];
    } catch (error) {
      return [];
    }
  }

  function safeOptionSelector(value) {
    if (window.CSS && typeof window.CSS.escape === "function") {
      return window.CSS.escape(value);
    }
    return String(value || "").replace(/"/g, '\\"');
  }

  function getSemanticSearchPage() {
    return document.getElementById("aipkit_semantic_search_page");
  }

  function getSemanticOwnerContainer() {
    return (
      document.getElementById("aipkit_settings_container") ||
      document.getElementById("aipkit_sources_module_container")
    );
  }

  function getTargetLabel(provider) {
    if (provider === "local") {
      return __("Knowledge base", "gpt3-ai-content-generator");
    }
    return provider === "qdrant" || provider === "chroma"
      ? __("Collection", "gpt3-ai-content-generator")
      : __("Index", "gpt3-ai-content-generator");
  }

  /**
   * Populates the target select dropdown based on the selected provider.
   * @param {string} provider - 'pinecone', 'qdrant', or 'chroma'.
   */
  function populateTargetSelect(provider) {
    const targetSelect = document.getElementById(
      "aipkit_semantic_search_target_id"
    );
    if (!targetSelect) return;

    const currentVal = targetSelect.value;
    targetSelect.innerHTML = '<option value="">-- Select --</option>';

    let items = [];
    if (provider === "local") {
      items = localStores;
    } else if (provider === "pinecone") {
      items = pineconeIndexes;
    } else if (provider === "qdrant") {
      items = qdrantCollections;
    } else if (provider === "chroma") {
      items = chromaCollections;
    }

    if (Array.isArray(items)) {
      items.forEach((item) => {
        const name =
          item.name || item.id || (typeof item === "string" ? item : null);
        if (name) {
          const option = document.createElement("option");
          // site knowledge bases are saved by id and shown by name.
          option.value = provider === "local" && item.id ? item.id : name;
          option.textContent = name;
          targetSelect.appendChild(option);
        }
      });
    }

    // Try to re-select the previous value if it exists in the new list
    if (
      targetSelect.querySelector(`option[value="${safeOptionSelector(currentVal)}"]`)
    ) {
      targetSelect.value = currentVal;
    } else if (currentVal) {
      const option = document.createElement("option");
      option.value = currentVal;
      option.textContent = currentVal;
      targetSelect.appendChild(option);
      targetSelect.value = currentVal;
    }
  }

  function updateTargetHelper(provider) {
    const helper = document.querySelector("[data-aipkit-semantic-target-helper]");
    if (helper) {
      helper.textContent = provider === "local"
        ? __("Knowledge base to search.", "gpt3-ai-content-generator")
        : __("Target index or collection.", "gpt3-ai-content-generator");
    }
  }

  function updateTargetLabel(provider) {
    const targetLabel = document.getElementById(
      "aipkit_semantic_search_target_label"
    );
    if (targetLabel) {
      const labelText = targetLabel.querySelector(
        "[data-aipkit-semantic-target-label-text]"
      );
      if (labelText) {
        labelText.textContent = getTargetLabel(provider);
      } else {
        targetLabel.textContent = getTargetLabel(provider);
      }
    }
  }

  /**
   * Updates the hidden embedding provider field based on the selected model.
   * @param {HTMLSelectElement} modelSelect - The embedding model select element.
   */
  function updateEmbeddingProvider(modelSelect) {
    const providerInput = document.getElementById(
      "aipkit_semantic_search_embedding_provider"
    );
    if (!providerInput || !modelSelect) return;

    const selectedOption = modelSelect.options[modelSelect.selectedIndex];
    if (selectedOption && selectedOption.dataset.provider) {
      providerInput.value = selectedOption.dataset.provider;
    }
  }

  function collectSemanticSearchSettings() {
    const providerSelect = document.getElementById(
      "aipkit_semantic_search_vector_provider"
    );
    const targetSelect = document.getElementById(
      "aipkit_semantic_search_target_id"
    );
    const embeddingProviderInput = document.getElementById(
      "aipkit_semantic_search_embedding_provider"
    );
    const embeddingModelSelect = document.getElementById(
      "aipkit_semantic_search_embedding_model"
    );
    const resultsInput = document.getElementById(
      "aipkit_semantic_search_num_results"
    );
    const noResultsInput = document.getElementById(
      "aipkit_semantic_search_no_results_text"
    );

    return {
      semantic_search_vector_provider: providerSelect?.value || "",
      semantic_search_target_id: targetSelect?.value || "",
      semantic_search_embedding_provider: embeddingProviderInput?.value || "",
      semantic_search_embedding_model: embeddingModelSelect?.value || "",
      semantic_search_num_results: resultsInput?.value || "",
      semantic_search_no_results_text: noResultsInput?.value || "",
    };
  }

  /**
   * Initializes the semantic search UI components.
   */
  function aipkit_initSemanticSearchUI() {
    const container = getSemanticOwnerContainer();
    if (!container) return;

    // This listener only needs to be attached once to the container.
    const listenerAttr = "data-semantic-search-listener-attached";
    const semanticSearchPage = getSemanticSearchPage();
    const listenerOwner = semanticSearchPage || container;
    if (listenerOwner.getAttribute(listenerAttr)) return;

    const providerSelect = document.getElementById(
      "aipkit_semantic_search_vector_provider"
    );
    const embeddingModelSelect = document.getElementById(
      "aipkit_semantic_search_embedding_model"
    );
    const messageContainerId = "aipkit_sources_status";
    let semanticAutosaveTimer = null;
    let semanticAutosaveInFlight = false;
    let semanticAutosavePending = false;

    if (!providerSelect) return;

    if (semanticSearchPage?.dataset?.semanticPineconeIndexes) {
      pineconeIndexes = parseJsonList(
        semanticSearchPage.dataset.semanticPineconeIndexes
      );
    }
    if (semanticSearchPage?.dataset?.semanticQdrantCollections) {
      qdrantCollections = parseJsonList(
        semanticSearchPage.dataset.semanticQdrantCollections
      );
    }
    if (semanticSearchPage?.dataset?.semanticLocalStores) {
      localStores = parseJsonList(semanticSearchPage.dataset.semanticLocalStores);
    }
    if (semanticSearchPage?.dataset?.semanticChromaCollections) {
      chromaCollections = parseJsonList(
        semanticSearchPage.dataset.semanticChromaCollections
      );
    }

    const setInlineStatusMessage = (type, text) => {
      const messageContainer = document.getElementById(messageContainerId);
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

    const clearSemanticMessages = () => {
      if (setInlineStatusMessage("", "")) {
        return;
      }
      if (typeof window.aipkit_clearStatusMessages === "function") {
        window.aipkit_clearStatusMessages(messageContainerId);
      }
    };

    const showSemanticError = (message) => {
      if (setInlineStatusMessage("error", message)) {
        return;
      }
      if (typeof window.aipkit_showMessage === "function") {
        window.aipkit_showMessage(messageContainerId, "error", message);
      }
    };

    const getSemanticAutosaveScope = () =>
      semanticSearchPage?.closest(".aipkit_sources_workspace_panel") ||
      semanticSearchPage ||
      null;

    const setSemanticAutosaveBusy = (show) => {
      if (typeof window.aipkit_setSettingsAutosaveBusy !== "function") {
        return;
      }
      window.aipkit_setSettingsAutosaveBusy(show, getSemanticAutosaveScope());
    };

    const saveSemanticSearchSettings = async () => {
      if (typeof window.aipkit_apiRequest !== "function") {
        return;
      }
      clearSemanticMessages();
      setSemanticAutosaveBusy(true);
      try {
        await window.aipkit_apiRequest(
          "aipkit_save_semantic_search_settings",
          collectSemanticSearchSettings()
        );
        clearSemanticMessages();
      } catch (error) {
        showSemanticError(
          error && error.message
            ? SAVE_FAILED_PREFIX + error.message
            : SAVE_FAILED_TEXT
        );
      } finally {
        setSemanticAutosaveBusy(false);
      }
    };

    const runSemanticAutosave = async () => {
      if (semanticAutosaveInFlight) {
        semanticAutosavePending = true;
        return;
      }

      semanticAutosaveInFlight = true;
      try {
        await saveSemanticSearchSettings();
      } finally {
        semanticAutosaveInFlight = false;
      }

      if (semanticAutosavePending) {
        semanticAutosavePending = false;
        runSemanticAutosave();
      }
    };

    const scheduleSemanticAutosave = () => {
      if (semanticAutosaveTimer) {
        window.clearTimeout(semanticAutosaveTimer);
      }
      semanticAutosaveTimer = window.setTimeout(runSemanticAutosave, 600);
    };

    // Add change listener for Vector DB provider
    if (providerSelect) {
      providerSelect.addEventListener("change", (e) => {
        updateTargetLabel(e.target.value);
        const targetSelect = document.getElementById(
          "aipkit_semantic_search_target_id"
        );
        if (targetSelect) {
          targetSelect.value = "";
        }
        populateTargetSelect(e.target.value);
        updateTargetHelper(e.target.value);
        scheduleSemanticAutosave();
      });
      // Initial population for the target select
      updateTargetLabel(providerSelect.value);
      populateTargetSelect(providerSelect.value);
      updateTargetHelper(providerSelect.value);
    }

    // Add change listener for Embedding Model select
    if (embeddingModelSelect) {
      embeddingModelSelect.addEventListener("change", (e) => {
        updateEmbeddingProvider(e.target);
        scheduleSemanticAutosave();
      });
      // Initial set of hidden provider value
      updateEmbeddingProvider(embeddingModelSelect);
    }

    [
      "aipkit_semantic_search_target_id",
      "aipkit_semantic_search_num_results",
      "aipkit_semantic_search_no_results_text",
    ].forEach((fieldId) => {
      const field = document.getElementById(fieldId);
      if (!field) {
        return;
      }
      field.addEventListener("change", scheduleSemanticAutosave);
      field.addEventListener("blur", scheduleSemanticAutosave);
      if (field.tagName.toLowerCase() === "input") {
        field.addEventListener("input", scheduleSemanticAutosave);
      }
    });

    const shortcodeSnippet = document.getElementById(
      "aipkit_semantic_search_shortcode_display"
    );
    if (shortcodeSnippet) {
      shortcodeSnippet.addEventListener("click", () => {
        if (typeof window.aipkit_copyShortcode === "function") {
          const shortcode =
            shortcodeSnippet.dataset.shortcode ||
            shortcodeSnippet.querySelector(".aipkit_semantic_shortcode_text")
              ?.textContent ||
            shortcodeSnippet.textContent ||
            "[aipkit_semantic_search]";
          window.aipkit_copyShortcode(shortcode.trim(), shortcodeSnippet);
        }
      });
    }

    listenerOwner.setAttribute(listenerAttr, "true");
  }

  // Expose the initializer
  window.aipkit_initSemanticSearchUI = aipkit_initSemanticSearchUI;
})();
