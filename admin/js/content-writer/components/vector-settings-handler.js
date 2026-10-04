(function () {
  "use strict";

  const escaper =
    window.aipkit_escapeHtml ||
    function (str) {
      return str;
    };
  const __ = window.wp?.i18n?.__ || function (str) { return str; };

  const PROVIDERS = new Set(["local", "openai", "google", "pinecone", "qdrant", "chroma"]);

  function getRoot(node) {
    if (!node) {
      return document.querySelector(
        "#aipkit_content_writer_container .aipkit_cw_vector_section"
      );
    }

    return (
      node.closest(".aipkit_cw_vector_section") ||
      document.querySelector(
        "#aipkit_content_writer_container .aipkit_cw_vector_section"
      )
    );
  }

  function getElements(root) {
    if (!root) return {};

    return {
      modeControl: root.querySelector("#aipkit_cw_kb_mode_control"),
      enableCheckbox: root.querySelector('[name="enable_vector_store"]'),
      providerField: root.querySelector('[name="vector_store_provider"]'),
      sourceRow: root.querySelector("#aipkit_cw_kb_source_row"),
      sourceLabel: root.querySelector("#aipkit_cw_kb_source_label"),
      openaiField: root.querySelector(".aipkit_cw_vector_openai_field"),
      googleField: root.querySelector(".aipkit_cw_vector_google_field"),
      pineconeField: root.querySelector(".aipkit_cw_vector_pinecone_field"),
      qdrantField: root.querySelector(".aipkit_cw_vector_qdrant_field"),
      chromaField: root.querySelector(".aipkit_cw_vector_chroma_field"),
      localField: root.querySelector(".aipkit_cw_vector_local_field"),
      embeddingSection: root.querySelector("#aipkit_cw_kb_embedding_section"),
      embeddingProvider: root.querySelector("#aipkit_cw_vector_embedding_provider"),
      embeddingModel: root.querySelector("#aipkit_cw_vector_embedding_model"),
      embeddingSelection: root.querySelector(
        "#aipkit_cw_vector_embedding_selection"
      ),
    };
  }

  function setHidden(element, shouldHide) {
    if (!element) {
      return;
    }

    element.hidden = Boolean(shouldHide);
  }

  function normalizeProvider(value) {
    const provider = String(value || "").trim().toLowerCase();
    return PROVIDERS.has(provider) ? provider : "openai";
  }

  function getModeFromFields(root) {
    const { enableCheckbox, providerField } = getElements(root);
    if (!enableCheckbox || !enableCheckbox.checked) {
      return "off";
    }

    return normalizeProvider(providerField ? providerField.value : "openai");
  }

  function getEmbeddingProviderEntries(selection, embeddingModels) {
    let providerLabels = {};
    try {
      providerLabels = JSON.parse(
        selection?.dataset?.aipkitProviderLabels || "{}"
      );
    } catch {
      providerLabels = {};
    }

    const providerKeys = new Set([
      ...Object.keys(providerLabels || {}),
      ...Object.keys(embeddingModels || {}),
    ]);

    return Array.from(providerKeys)
      .map((providerKey) => {
        const key = String(providerKey || "").trim().toLowerCase();
        return {
          key,
          label: String(providerLabels?.[providerKey] || providerKey),
        };
      })
      .filter((provider) => provider.key);
  }

  function findEmbeddingOption(selection, provider, model) {
    return (
      Array.from(selection?.options || []).find(
        (option) =>
          String(option.dataset.provider || "") === String(provider || "") &&
          String(option.dataset.model || "") === String(model || "")
      ) || null
    );
  }

  function findFirstEmbeddingOptionForProvider(selection, provider) {
    return (
      Array.from(selection?.options || []).find(
        (option) =>
          option.value &&
          String(option.dataset.provider || "") === String(provider || "")
      ) || null
    );
  }

  function syncEmbeddingSelectionFields(root) {
    const { embeddingProvider, embeddingModel, embeddingSelection } =
      getElements(root);
    const selectedOption = embeddingSelection?.selectedOptions?.[0];
    if (!embeddingProvider || !embeddingModel || !selectedOption?.value) {
      return;
    }

    embeddingProvider.value = String(selectedOption.dataset.provider || "");
    embeddingModel.value = String(selectedOption.dataset.model || "");
    embeddingSelection.title = selectedOption.textContent.trim();
  }

  function bindEmbeddingSelection(root) {
    const { embeddingSelection } = getElements(root);
    if (
      !embeddingSelection ||
      embeddingSelection.dataset.aipkitEmbeddingSelectionBound === "true"
    ) {
      return;
    }

    embeddingSelection.addEventListener("change", () => {
      syncEmbeddingSelectionFields(root);
    });
    embeddingSelection.dataset.aipkitEmbeddingSelectionBound = "true";
  }

  function populateEmbeddingSelection(root) {
    const { embeddingProvider, embeddingModel, embeddingSelection } =
      getElements(root);
    if (!embeddingProvider || !embeddingModel || !embeddingSelection) {
      return;
    }

    const embeddingModels = window.aipkit_dashboard?.embeddingModels || {};
    const providerEntries = getEmbeddingProviderEntries(
      embeddingSelection,
      embeddingModels
    );
    const preferredProvider = String(embeddingProvider.value || "")
      .trim()
      .toLowerCase();
    const preferredModel =
      embeddingModel.dataset.aipkitRequestedValue ||
      embeddingModel.value ||
      window.aipkit_cw_template_state?.initialConfigForSelectedTemplate
        ?.vector_embedding_model ||
      "";

    embeddingSelection.innerHTML = "";
    let firstOption = null;

    providerEntries.forEach((provider) => {
      const models = Array.isArray(embeddingModels[provider.key])
        ? embeddingModels[provider.key]
        : [];

      models.forEach((model) => {
        if (!model?.id) {
          return;
        }

        const modelId = String(model.id);
        const modelLabel = String(model.name || model.id);
        const option = new Option(
          escaper(`${provider.label} · ${modelLabel}`),
          `${encodeURIComponent(provider.key)}::${encodeURIComponent(modelId)}`
        );
        option.dataset.provider = provider.key;
        option.dataset.model = modelId;
        embeddingSelection.appendChild(option);
        if (!firstOption) {
          firstOption = option;
        }
      });
    });

    if (preferredProvider === "aipuffercloud" && preferredModel &&
        !findEmbeddingOption(embeddingSelection, preferredProvider, preferredModel)) {
      const unavailable = new Option(
        escaper(__("Saved AI Puffer model unavailable", "gpt3-ai-content-generator")),
        `${encodeURIComponent(preferredProvider)}::${encodeURIComponent(preferredModel)}`
      );
      unavailable.dataset.provider = preferredProvider;
      unavailable.dataset.model = preferredModel;
      embeddingSelection.appendChild(unavailable);
      embeddingSelection.value = unavailable.value;
      embeddingSelection.disabled = false;
      delete embeddingModel.dataset.aipkitRequestedValue;
      embeddingSelection._aipkitUnifiedModelSync?.();
      return;
    }

    if (!firstOption) {
      embeddingSelection.appendChild(
        new Option(
          escaper(__("No embedding models available", "gpt3-ai-content-generator")),
          ""
        )
      );
      embeddingSelection.disabled = true;
      embeddingProvider.value = "";
      embeddingModel.value = "";
      delete embeddingModel.dataset.aipkitRequestedValue;
      return;
    }

    if (!preferredProvider || !preferredModel) {
      embeddingSelection.prepend(new Option(escaper(__('Select embedding model', 'gpt3-ai-content-generator')), ''));
      embeddingSelection.value = '';
      embeddingSelection.disabled = false;
      embeddingSelection._aipkitUnifiedModelSync?.();
      return;
    }

    const defaultProvider = providerEntries.some(
      (provider) => provider.key === "openai"
    )
      ? "openai"
      : providerEntries[0]?.key || "";
    const normalizedProvider = providerEntries.some(
      (provider) => provider.key === preferredProvider
    )
      ? preferredProvider
      : defaultProvider;
    const preferredOption =
      findEmbeddingOption(
        embeddingSelection,
        normalizedProvider,
        preferredModel
      ) ||
      findFirstEmbeddingOptionForProvider(
        embeddingSelection,
        normalizedProvider
      ) ||
      firstOption;

    embeddingSelection.disabled = false;
    embeddingSelection.value = preferredOption.value;
    syncEmbeddingSelectionFields(root);
    delete embeddingModel.dataset.aipkitRequestedValue;
  }

  let contextOptionsReturnFocus = null;

  function getContextOptionsModal(root) {
    return (
      root?.querySelector("[data-aipkit-context-options-modal]") || null
    );
  }

  function getContextOptionsFocusableElements(modal) {
    return Array.from(
      modal?.querySelectorAll(
        'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
      ) || []
    ).filter(
      (element) =>
        !element.hidden &&
        element.getAttribute("aria-hidden") !== "true" &&
        element.offsetParent !== null
    );
  }

  function closeContextOptionsModal(root, options = {}) {
    const modal = getContextOptionsModal(root);
    if (!modal || !modal.classList.contains("aipkit-active")) {
      return;
    }

    modal.classList.remove("aipkit-active");
    modal.setAttribute("aria-hidden", "true");
    root
      ?.querySelector("[data-aipkit-context-options-trigger]")
      ?.setAttribute("aria-expanded", "false");

    if (
      options.returnFocus !== false &&
      contextOptionsReturnFocus?.isConnected
    ) {
      contextOptionsReturnFocus.focus();
    }
    contextOptionsReturnFocus = null;
  }

  function openContextOptionsModal(root) {
    const modal = getContextOptionsModal(root);
    const trigger = root?.querySelector(
      "[data-aipkit-context-options-trigger]"
    );
    if (!modal || !trigger || modal.classList.contains("aipkit-active")) {
      return;
    }

    contextOptionsReturnFocus = document.activeElement;
    modal.classList.add("aipkit-active");
    modal.setAttribute("aria-hidden", "false");
    trigger.setAttribute("aria-expanded", "true");

    window.setTimeout(() => {
      getContextOptionsFocusableElements(modal)[0]?.focus();
    }, 0);
  }

  function syncModeFields(root, mode, dispatchChange) {
    const { enableCheckbox, providerField } = getElements(root);
    if (!enableCheckbox || !providerField) return;

    const normalizedMode = PROVIDERS.has(mode) ? mode : "off";
    enableCheckbox.checked = normalizedMode !== "off";
    if (normalizedMode !== "off") {
      providerField.value = normalizedMode;
    } else if (!providerField.value) {
      providerField.value = "openai";
    }

    if (dispatchChange) {
      enableCheckbox.dispatchEvent(new Event("change", { bubbles: true }));
      providerField.dispatchEvent(new Event("change", { bubbles: true }));
    }
  }

  function updateVectorSettingsUI(scope) {
    const root = getRoot(scope);
    if (!root) return;

    const {
      modeControl,
      sourceRow,
      sourceLabel,
      openaiField,
      googleField,
      pineconeField,
      qdrantField,
      chromaField,
      localField,
      embeddingSection,
      embeddingSelection,
    } = getElements(root);

    if (!modeControl || !sourceRow) return;

    const mode = getModeFromFields(root);
    const contentWriterForm = root.closest("form");
    const aiProvider = String(contentWriterForm?.elements?.ai_provider?.value || "").toLowerCase();
    const googleModeOption = Array.from(modeControl.options || []).find(
      (option) => option.value === "google"
    );
    if (googleModeOption) {
      googleModeOption.disabled = aiProvider !== "google";
    }
    const isEnabled = mode !== "off";

    if (modeControl.value !== mode) {
      modeControl.value = mode;
    }
    if (
      typeof window.aipkit_refreshContentWriterSelectWidths === "function"
    ) {
      window.aipkit_refreshContentWriterSelectWidths(modeControl);
    }

    sourceRow.hidden = !isEnabled;
    sourceRow.dataset.aipkitKbSourceMode = mode;

    if (!isEnabled) {
      closeContextOptionsModal(root, { returnFocus: false });
      setHidden(openaiField, true);
      setHidden(googleField, true);
      setHidden(pineconeField, true);
      setHidden(qdrantField, true);
      setHidden(chromaField, true);
      setHidden(localField, true);
      setHidden(embeddingSection, true);
      return;
    }

    if (typeof window.aipkit_refreshVectorStoreProviderList === "function") {
      window.aipkit_refreshVectorStoreProviderList(mode);
    }

    const sourceLabels = {
      local: {
        text: __("Knowledge base", "gpt3-ai-content-generator"),
        fieldId: "aipkit_cw_local_store_id",
      },
      openai: {
        text: __("Vector stores", "gpt3-ai-content-generator"),
        fieldId: "aipkit_cw_openai_vector_store_ids",
      },
      google: {
        text: __("Stores", "gpt3-ai-content-generator"),
        fieldId: "aipkit_cw_google_file_search_store_names",
      },
      pinecone: {
        text: __("Index", "gpt3-ai-content-generator"),
        fieldId: "aipkit_cw_pinecone_index_name",
      },
      qdrant: {
        text: __("Collection", "gpt3-ai-content-generator"),
        fieldId: "aipkit_cw_qdrant_collection_name",
      },
      chroma: {
        text: __("Collection", "gpt3-ai-content-generator"),
        fieldId: "aipkit_cw_chroma_collection_name",
      },
    };
    const sourceMeta = sourceLabels[mode] || sourceLabels.openai;
    if (sourceLabel) {
      sourceLabel.textContent = sourceMeta.text;
      sourceLabel.htmlFor = sourceMeta.fieldId;
    }

    setHidden(openaiField, mode !== "openai");
    setHidden(googleField, mode !== "google");
    setHidden(pineconeField, mode !== "pinecone");
    setHidden(qdrantField, mode !== "qdrant");
    setHidden(chromaField, mode !== "chroma");
    setHidden(localField, mode !== "local");

    const needsEmbedding = mode === "local" || mode === "pinecone" || mode === "qdrant" || mode === "chroma";
    setHidden(embeddingSection, !needsEmbedding);
    if ((mode === "openai" || mode === "google") && typeof window.aipkit_initContentWriterVectorStoreMultiSelect === "function") {
      window.aipkit_initContentWriterVectorStoreMultiSelect(root);
    }
    if (needsEmbedding && embeddingSelection) {
      bindEmbeddingSelection(root);
      populateEmbeddingSelection(root);
    }
  }

  function toggleVectorSettingsContainer(scope) {
    updateVectorSettingsUI(scope);
  }

  function toggleProviderSpecificFields(scope) {
    updateVectorSettingsUI(scope);
  }

  function initContentWriterVectorSettings() {
    const container = document.getElementById("aipkit_content_writer_container");
    if (!container || container.dataset.vectorSettingsHandlerAttached) {
      return;
    }

    container.addEventListener("change", function (event) {
      const target = event.target;
      const root = getRoot(target);
      if (!root) return;

      if (target.matches("#aipkit_cw_kb_mode_control")) {
        syncModeFields(root, target.value, true);
        updateVectorSettingsUI(root);
        return;
      }

      if (
        target.matches('[name="enable_vector_store"]') ||
        target.matches('[name="vector_store_provider"]') ||
        target.matches('[name="ai_provider"]')
      ) {
        updateVectorSettingsUI(root);
        return;
      }

      if (
        target.matches("#aipkit_cw_vector_embedding_provider") ||
        target.matches("#aipkit_cw_vector_embedding_model")
      ) {
        populateEmbeddingSelection(root);
      }
    });

    container.addEventListener("click", function (event) {
      const trigger = event.target.closest(
        "[data-aipkit-context-options-trigger]"
      );
      if (trigger && container.contains(trigger)) {
        event.preventDefault();
        openContextOptionsModal(getRoot(trigger));
        return;
      }

      const closeButton = event.target.closest(
        "[data-aipkit-context-options-close]"
      );
      if (closeButton && container.contains(closeButton)) {
        event.preventDefault();
        closeContextOptionsModal(getRoot(closeButton));
        return;
      }

      const root = getRoot(event.target);
      const modal = getContextOptionsModal(root);
      if (event.target === modal) {
        closeContextOptionsModal(root);
      }
    });

    container.addEventListener(
      "invalid",
      function (event) {
        const modal = event.target.closest(
          "[data-aipkit-context-options-modal]"
        );
        if (modal) {
          openContextOptionsModal(getRoot(modal));
        }
      },
      true
    );

    document.addEventListener("keydown", function (event) {
      const root = getRoot(container);
      const modal = getContextOptionsModal(root);
      if (!modal?.classList.contains("aipkit-active")) {
        return;
      }

      if (event.key === "Escape") {
        event.preventDefault();
        closeContextOptionsModal(root);
        return;
      }

      if (event.key !== "Tab") {
        return;
      }

      const focusableElements = getContextOptionsFocusableElements(modal);
      if (!focusableElements.length) {
        event.preventDefault();
        return;
      }

      const firstElement = focusableElements[0];
      const lastElement = focusableElements[focusableElements.length - 1];
      if (event.shiftKey && document.activeElement === firstElement) {
        event.preventDefault();
        lastElement.focus();
      } else if (
        !event.shiftKey &&
        document.activeElement === lastElement
      ) {
        event.preventDefault();
        firstElement.focus();
      }
    });

    const root = getRoot(container);
    if (root) {
      updateVectorSettingsUI(root);
    }

    container.dataset.vectorSettingsHandlerAttached = "true";
  }

  window.aipkit_initContentWriterVectorSettings =
    initContentWriterVectorSettings;
  window.aipkit_cw_toggleVectorSettingsContainer =
    toggleVectorSettingsContainer;
})();
