/**
 * AIPKit Settings - Model Sync Initializer & Coordinator
 */
import { applyCloudConnectionResponse } from './cloud-connection.js';

(function () {
  "use strict";

  function normalizeSyncProvider(provider) {
    const value = String(provider || "").trim();
    const key = value.toLowerCase();
    if (key === "anthropic") return "Claude";
    return [
      "AIPufferCloud", "OpenAI", "OpenRouter", "Google", "Azure", "Claude",
      "DeepSeek", "xAI", "Ollama", "ElevenLabs", "ElevenLabsModels",
      "OpenAIVectorStores", "GoogleFileSearchStores", "PineconeIndexes",
      "QdrantCollections", "ChromaCollections", "Replicate",
    ].find((name) => name.toLowerCase() === key) || value;
  }

  /**
   * Sync models/voices from the selected provider via AJAX. Coordinates calling specific update functions.
   * @param {HTMLElement} button The sync button that was clicked.
   * @param {string} provider The provider name ('OpenAI', 'OpenRouter', etc., or 'ElevenLabsModels', 'PineconeIndexes', 'QdrantCollections', 'ChromaCollections').
   */
  function aipkit_syncModels(button, provider, options = {}) {
    provider = normalizeSyncProvider(provider);
    const syncOptions =
      options && typeof options === "object" ? options : {};
    const silent = Boolean(syncOptions.silent);
    if (
      typeof window.aipkit_clearStatusMessages !== "function" ||
      typeof window.aipkit_showSavingIndicator !== "function" ||
      typeof window.aipkit_showMessage !== "function" ||
      typeof window.aipkit_apiRequest !== "function"
    ) {
      console.error(
        "AIPKit Model Sync Init: Missing required UI or API helper functions."
      );
      if (!silent) {
        alert(
          "AIPKit Error: Cannot sync models/voices, required functions missing."
        );
      }
      return null;
    }

    const showErrors = syncOptions.showErrors !== false;
    const showSuccess = syncOptions.showSuccess === true;
    const showLocalStatus = syncOptions.showLocalStatus !== false;
    const hasButton = Boolean(button);
    const settingsCardProviderMap = {
      OpenAIVectorStores: "OpenAI",
      GoogleFileSearchStores: "Google",
    };
    const settingsCardProvider = settingsCardProviderMap[provider] || provider;
    const settingsProviderCard = document.querySelector(
      `#aipkit_settings_container [data-aipkit-provider-card="${CSS.escape(settingsCardProvider)}"]`
    );

    const updateOpenAIVectorStores = (stores) => {
      const storeList = Array.isArray(stores) ? stores : [];
      window.aipkit_chat_config = window.aipkit_chat_config || {};
      window.aipkit_chat_config.openaiVectorStores = storeList;
      window.aipkit_vpp_config = window.aipkit_vpp_config || {};
      window.aipkit_vpp_config.openai_vector_stores = storeList;
      window.aipkit_automated_tasks_config =
        window.aipkit_automated_tasks_config || {};
      window.aipkit_automated_tasks_config.openai_vector_stores = storeList;
      window.aipkit_post_enhancer = window.aipkit_post_enhancer || {};
      window.aipkit_post_enhancer.openai_vector_stores = storeList;

      // Ensure subsequent module loads do not use stale HTML snapshots.
      if (typeof window.aipkit_invalidateModuleCache === "function") {
        ["chatbot", "sources", "content-writer", "autogpt", "ai-forms"].forEach(
          (moduleName) => {
            window.aipkit_invalidateModuleCache(moduleName);
          }
        );
      }

      // Let any active module views react to updated vector-store data.
      if (typeof window.dispatchEvent === "function") {
        window.dispatchEvent(
          new CustomEvent("aipkit:vector-store-list-updated", {
            detail: {
              provider: "openai",
              stores: storeList,
            },
          })
        );
      }
    };

    const updaterMap = {
      OpenAI: window.aipkit_updateOpenAIModels,
      OpenRouter: window.aipkit_updateOpenRouterModels,
      Google: window.aipkit_updateGoogleModels,
      GoogleFileSearchStores: window.aipkit_updateGoogleFileSearchStores,
      OpenAIVectorStores: updateOpenAIVectorStores,
      Azure: window.aipkit_updateAzureDeployments,
      Claude: window.aipkit_updateClaudeModels,
      DeepSeek: window.aipkit_updateDeepSeekModels,
      xAI: window.aipkit_updateXAIModels,
      Ollama: window.aipkit_updateOllamaModels,
      ElevenLabs: window.aipkit_updateElevenLabsVoices,
      ElevenLabsModels: window.aipkit_updateElevenLabsModelsSelect,
      PineconeIndexes: window.aipkit_updatePineconeIndexes,
      QdrantCollections: window.aipkit_updateQdrantCollections,
      ChromaCollections: window.aipkit_updateChromaCollections,
      Replicate: window.aipkit_updateReplicateModels,
    };
    if (provider !== "AIPufferCloud" && typeof updaterMap[provider] !== "function") {
      console.error(
        `AIPKit Model Sync Init: Missing update function for provider ${provider}.`
      );
      alert(`AIPKit Error: Cannot update UI for ${provider}.`);
      return;
    }

    const messageContainerId = "aipkit_settings_global_messages";
    const isPopoverSync = hasButton
      ? Boolean(button.closest(".aipkit_popover_options_list"))
      : false;
    if (
      settingsProviderCard &&
      document.getElementById(messageContainerId)
    ) {
      window.aipkit_clearStatusMessages(messageContainerId);
    }
    // Only touch the dashboard message area if it exists on this screen
    if (
      !silent &&
      !settingsProviderCard &&
      !isPopoverSync &&
      document.getElementById(messageContainerId)
    ) {
      window.aipkit_clearStatusMessages(messageContainerId);
      window.aipkit_showSavingIndicator(messageContainerId, false);
    }

    const buttonTextSpan = hasButton
      ? button.querySelector(".aipkit_btn-text")
      : null;
    const targetSelectId = hasButton
      ? button.getAttribute("data-target-select")
      : syncOptions.targetSelectId || "";
    // Local status span in Chat UI — optional.
    let statusSpan = null;
    const getNextLayoutStatusSpan = (root) => {
      if (!root || !(root instanceof Element)) {
        return null;
      }
      return root.querySelector(
        ".aipkit_builder_card_status .aipkit_model_sync_status"
      );
    };

    if (isPopoverSync) {
      const flyoutBody = button.closest(".aipkit_popover_flyout_body");
      const flyoutHeader =
        flyoutBody?.closest(".aipkit-modal-content")?.querySelector(".aipkit_popover_flyout_header") ||
        button.closest(".aipkit_popover_flyout_header");
      if (flyoutHeader) {
        statusSpan = flyoutHeader.querySelector(
          ".aipkit_popover_status_inline"
        );
      }
      if (!statusSpan) {
        const popover = button.closest(".aipkit_model_settings_popover");
        if (popover) {
          statusSpan = popover.querySelector(
            ".aipkit_model_settings_popover_header .aipkit_popover_status_inline.aipkit_model_sync_status"
          );
        }
      }
      if (!statusSpan) {
        const popoverRow = button.closest(".aipkit_popover_option_row");
        if (popoverRow) {
          statusSpan = popoverRow.querySelector(
            ".aipkit_popover_status_inline"
          );
        }
      }
      if (!statusSpan) {
        const panel = button.closest(".aipkit_model_settings_panel");
        if (panel) {
          statusSpan = panel.querySelector(
            ".aipkit_popover_status_inline.aipkit_model_sync_status"
          );
        }
      }
    } else {
      const field = hasButton
        ? button.closest(".aipkit_chatbot_model_field")
        : null;
      let builder = hasButton ? button.closest(".aipkit_chatbot_builder") : null;
      if (!builder && targetSelectId) {
        const targetSelect = document.getElementById(targetSelectId);
        builder = targetSelect
          ? targetSelect.closest(".aipkit_chatbot_builder")
          : null;
      }
      if (!builder) {
        builder = document.querySelector(
          '.aipkit_chatbot_builder[data-aipkit-chatbot-layout="next"]'
        );
      }
      if (
        builder &&
        builder.getAttribute("data-aipkit-chatbot-layout") === "next"
      ) {
        statusSpan = getNextLayoutStatusSpan(builder);
      }
      if (!statusSpan) {
        statusSpan = field
          ? field.querySelector(".aipkit_model_sync_status")
          : null;
      }
    }
    if (!statusSpan) {
      const nextLayoutBuilder = document.querySelector(
        '.aipkit_chatbot_builder[data-aipkit-chatbot-layout="next"]'
      );
      if (nextLayoutBuilder) {
        statusSpan = getNextLayoutStatusSpan(nextLayoutBuilder);
      }
    }
    if (!statusSpan && provider) {
      const modelField = document.querySelector(
        `.aipkit_chatbot_model_field[data-provider="${provider}"]`
      );
      if (modelField) {
        statusSpan = modelField.querySelector(".aipkit_model_sync_status");
      }
    }
    const setLocalStatus = (text, cls, title) => {
      if (!statusSpan) return;
      // Clear sibling save status to avoid showing both at once
      const siblingStatus = statusSpan.closest('.aipkit_model_status_slot')?.querySelector('.aipkit_save_status_container');
      if (siblingStatus && text) {
        siblingStatus.textContent = '';
        siblingStatus.className = 'aipkit_save_status_container';
      }
      statusSpan.textContent = text || '';
      statusSpan.classList.remove('success', 'error', 'loading');
      if (cls) statusSpan.classList.add(cls);
      if (title) {
        statusSpan.setAttribute('title', title);
      } else {
        statusSpan.removeAttribute('title');
      }
      if (statusSpan.classList.contains('aipkit_popover_status_inline')) {
        statusSpan.style.display = text ? 'inline-block' : 'none';
      }
    };
    const shouldShowSyncStatus = showLocalStatus && (!silent || showSuccess);
    if (shouldShowSyncStatus) {
      setLocalStatus("Syncing…", "loading");
    }
    const originalButtonText = buttonTextSpan
      ? buttonTextSpan.textContent
      : provider.includes("ElevenLabs") ||
        provider.includes("Pinecone") ||
        provider.includes("Qdrant") ||
        provider.includes("Chroma") ||
        provider.includes("VectorStores") ||
        provider.includes("Replicate")
      ? "Sync Items"
      : "Sync Models";
    const syncingButtonText = hasButton
      ? button.getAttribute("data-aipkit-syncing-label") || ""
      : "";
    let spinner = null;
    if (hasButton) {
      spinner = button.querySelector(".aipkit_spinner");
      if (!spinner) {
        spinner = document.createElement("span");
        spinner.className = "aipkit_spinner";
        button.appendChild(spinner);
      }
      spinner.style.display = "inline-block";

      button.classList.add("aipkit_loading");
      button.disabled = true;
      button.setAttribute("aria-busy", "true");
      if (buttonTextSpan && syncingButtonText) {
        buttonTextSpan.textContent = syncingButtonText;
      }
    }

    const syncRequest = syncOptions.connectionFields
      ? window.aipkit_apiRequest("aipkit_provider_connection", { ...syncOptions.connectionFields, provider, operation: "connect" })
      : window.aipkit_apiRequest("aipkit_sync_models", { provider });
    const syncPromise = syncRequest.then((response) => {
        if (provider === "AIPufferCloud") {
          applyCloudConnectionResponse(response, 'sync');
        } else {
          window.aipkit_applyProviderStatus?.(response.providerStatus);
          if (response?.newConfiguration && window.aipkit_dashboard) {
            Object.assign(window.aipkit_dashboard, response.newConfiguration);
          }
          if (
            response?.provider_state &&
            typeof window.aipkit_updateModelRegistryStates === "function"
          ) {
            window.aipkit_updateModelRegistryStates({
              [settingsCardProvider]: response.provider_state,
            });
          }
        }
        if (
          provider === "OpenRouter" &&
          Array.isArray(response?.embedding_models)
        ) {
          window.aipkit_dashboard = window.aipkit_dashboard || {};
          window.aipkit_dashboard.embeddingModels =
            window.aipkit_dashboard.embeddingModels || {};
          window.aipkit_dashboard.embeddingModels.openrouter =
            response.embedding_models;
        }
        if (provider === "OpenRouter" && Array.isArray(response?.image_models)) {
          window.aipkit_dashboard = window.aipkit_dashboard || {};
          window.aipkit_dashboard.imageGeneratorModels =
            window.aipkit_dashboard.imageGeneratorModels || {};
          window.aipkit_dashboard.imageGeneratorModels.openrouter =
            response.image_models;
        }
        if (provider === "Google") {
          window.aipkit_dashboard = window.aipkit_dashboard || {};
          if (Array.isArray(response?.image_models)) {
            window.aipkit_dashboard.imageGeneratorModels =
              window.aipkit_dashboard.imageGeneratorModels || {};
            window.aipkit_dashboard.imageGeneratorModels.google =
              response.image_models;
          }
          if (Array.isArray(response?.video_models)) {
            window.aipkit_dashboard.imageGeneratorVideoModels =
              window.aipkit_dashboard.imageGeneratorVideoModels || {};
            window.aipkit_dashboard.imageGeneratorVideoModels.google =
              response.video_models;
          }
          if (Array.isArray(response?.embedding_models)) {
            window.aipkit_dashboard.embeddingModels =
              window.aipkit_dashboard.embeddingModels || {};
            window.aipkit_dashboard.embeddingModels.google =
              response.embedding_models;
          }
          if (Array.isArray(response?.tts_models)) {
            document
              .querySelectorAll('select[name="tts_google_model_id"]')
              .forEach((select) => {
                const currentValue = select.value;
                select.replaceChildren(
                  ...response.tts_models.map(
                    (model) => new Option(model.name || model.id, model.id)
                  )
                );
                if (
                  currentValue &&
                  Array.from(select.options).some(
                    (option) => option.value === currentValue
                  )
                ) {
                  select.value = currentValue;
                }
              });
          }
        }
        if (
          provider === "OpenRouter" &&
          Array.isArray(response?.image_models)
        ) {
          window.aipkit_dashboard = window.aipkit_dashboard || {};
          window.aipkit_dashboard.imageGeneratorModels =
            window.aipkit_dashboard.imageGeneratorModels || {};
          window.aipkit_dashboard.imageGeneratorModels.openrouter =
            response.image_models;
          window.aipkit_image_generator_config_public =
            window.aipkit_image_generator_config_public || {};
          window.aipkit_image_generator_config_public.openrouter_image_models =
            response.image_models;
        }
        if (provider === "xAI" && Array.isArray(response?.image_models)) {
          window.aipkit_dashboard = window.aipkit_dashboard || {};
          window.aipkit_dashboard.imageGeneratorModels =
            window.aipkit_dashboard.imageGeneratorModels || {};
          window.aipkit_dashboard.imageGeneratorModels.xai =
            response.image_models;
          window.aipkit_image_generator_config_public =
            window.aipkit_image_generator_config_public || {};
          window.aipkit_image_generator_config_public.xai_image_models =
            response.image_models;
        }

        const responseItems =
          provider === "OpenAIVectorStores" ||
          provider === "GoogleFileSearchStores"
            ? response.stores
            : response.models;

        const recommendedItems = Array.isArray(response?.recommended_models)
          ? response.recommended_models
          : [];
        const providerRecommendedKeyMap = {
          OpenAI: "openai",
          OpenRouter: "openrouter",
          Google: "google",
          Azure: "azure",
          Claude: "claude",
          DeepSeek: "deepseek",
          xAI: "xai",
          Ollama: "ollama",
        };
        const recommendedKey = providerRecommendedKeyMap[provider] || "";
        if (recommendedKey) {
          window.aipkit_dashboard = window.aipkit_dashboard || {};
          window.aipkit_dashboard.recommendedModels =
            window.aipkit_dashboard.recommendedModels || {};
          window.aipkit_dashboard.recommendedModels[recommendedKey] =
            recommendedItems;
        }

        if (provider !== "AIPufferCloud" && !syncOptions.skipUpdate) {
          if (targetSelectId) {
            updaterMap[provider](responseItems, targetSelectId);
          } else {
            updaterMap[provider](responseItems); // 'models' key used for all responses for consistency
          }
        }
        if (typeof window.aipkit_refreshSettingsSelectPickers === "function") {
          window.aipkit_refreshSettingsSelectPickers();
        }

        if (
          (provider === "PineconeIndexes" || provider === "QdrantCollections" || provider === "ChromaCollections") &&
          typeof window.aipkit_refreshTrainingSourcesCount === "function"
        ) {
          window.aipkit_refreshTrainingSourcesCount();
        }

        if (typeof window.aipkit_updateLastSavedData === "function") {
          window.aipkit_updateLastSavedData();
        } else if (!silent) {
          console.warn("Model Sync Init: Cannot update autosave baseline.");
        }

        if (provider !== "AIPufferCloud" && typeof window.dispatchEvent === "function") {
          window.dispatchEvent(
            new CustomEvent("aipkit:model-sync-complete", {
              detail: {
                provider,
                syncedAt:
                  Number(response?.synced_at) || Math.floor(Date.now() / 1000),
              },
            })
          );
        }

        let itemType = "models";
        if (provider === "ElevenLabs") itemType = "voices";
        else if (provider === "ElevenLabsModels") itemType = "synthesis models";
        else if (provider === "GoogleFileSearchStores") itemType = "stores";
        else if (provider === "PineconeIndexes") itemType = "indexes";
        else if (provider === "QdrantCollections") itemType = "collections";
        else if (provider === "ChromaCollections") itemType = "collections";
        else if (provider === "OpenAIVectorStores") itemType = "vector stores";

        if (
          (!silent || showSuccess) &&
          !settingsProviderCard &&
          !isPopoverSync &&
          document.getElementById(messageContainerId)
        ) {
          window.aipkit_showMessage(
            messageContainerId,
            "success",
            `${provider.replace(
              /Indexes|Collections|Models/,
              ""
            )} ${itemType} synced!`
          );
        }
        if (shouldShowSyncStatus) {
          setLocalStatus("Saved", "success");
          if (statusSpan) setTimeout(() => setLocalStatus("", null), 2500);
        }
        return response;
      })
      .catch((error) => {
        const errorMessage = error?.message || "Sync failed.";
        if (
          settingsProviderCard &&
          showErrors &&
          typeof window.dispatchEvent === "function"
        ) {
          window.dispatchEvent(
            new CustomEvent("aipkit:provider-sync-error", {
              detail: {
                provider,
                message: errorMessage,
              },
            })
          );
        }
        if (
          showErrors &&
          !settingsProviderCard &&
          !isPopoverSync &&
          document.getElementById(messageContainerId)
        ) {
          window.aipkit_showMessage(
            messageContainerId,
            "error",
            `Sync failed: ${errorMessage}`
          );
        }
        console.error(
          `AIPKit Sync Error (${provider}):`,
          error
        );
        if (showLocalStatus) {
          setLocalStatus(errorMessage, "error", errorMessage);
          if (statusSpan) {
            setTimeout(() => setLocalStatus("", null), 5000);
          }
        }
        if (syncOptions.propagateError) {
          throw error;
        }
      })
      .finally(() => {
        if (hasButton) {
          button.disabled = false;
          button.classList.remove("aipkit_loading");
          button.setAttribute("aria-busy", "false");
          const finalSpinner = button.querySelector(".aipkit_spinner");
          if (finalSpinner) finalSpinner.style.display = "none";
          if (buttonTextSpan) buttonTextSpan.textContent = originalButtonText;
        }
      });

    return syncPromise;
  }

  /**
   * Initializes sync model/voice button listeners using event delegation.
   */
  function attachSyncListener(container) {
    if (!container) return;
    const listenerAttr = "data-sync-listener-attached";
    if (container.getAttribute(listenerAttr)) return;
    container.addEventListener("click", function (event) {
      const button = event.target.closest(".aipkit_sync_btn");
      if (!button || button.disabled) return;
      // Cloud account forms own their submit actions, including catalog sync.
      if (button.matches('[name="cloud_action"]')) return;

      event.preventDefault();
      // Prefer button data-provider; fallback to enclosing chat model field's data-provider
      let provider = button.getAttribute("data-provider") || "";
      if (!provider) {
        const field = button.closest('.aipkit_chatbot_model_field');
        if (field && field.getAttribute('data-provider')) {
          provider = field.getAttribute('data-provider');
        }
      }
      if (provider) {
        aipkit_syncModels(button, provider);
      } else {
        console.warn(
          "AIPKit Model Sync Init: Sync button missing data-provider attribute:",
          button
        );
      }
    });
    container.setAttribute(listenerAttr, "true");
  }

  function aipkit_initSyncButtons() {
    // Attach to both Dashboard and Chat containers (either or both may exist)
    attachSyncListener(document.getElementById("aipkit_module-container"));
    attachSyncListener(document.getElementById("aipkit_chatbot_main_tab_content_container"));
  }

  const autoSyncQueue = [];
  let autoSyncInProgress = false;

  function getValueForKey(data, key) {
    if (!data || typeof data !== "object") {
      return "";
    }
    const rawValue = data[key];
    if (rawValue === undefined || rawValue === null) {
      return "";
    }
    return String(rawValue).trim();
  }

  function hasFieldChanges(prevData, nextData, keys) {
    return keys.some(
      (key) => getValueForKey(prevData, key) !== getValueForKey(nextData, key)
    );
  }

  function shouldSyncAzure(data) {
    return (
      getValueForKey(data, "azure_api_key") !== "" &&
      getValueForKey(data, "azure_endpoint") !== ""
    );
  }

  function isProviderReadyForAutoSync(provider, data) {
    const providerName = String(provider || "").trim();
    if (!providerName) {
      return false;
    }

    if (providerName === "Ollama") {
      return true;
    }

    if (providerName === "Azure") {
      return shouldSyncAzure(data);
    }

    const providerKeyMap = {
      OpenAI: "openai_api_key",
      OpenRouter: "openrouter_api_key",
      Google: "google_api_key",
      GoogleFileSearchStores: "google_api_key",
      Claude: "claude_api_key",
      DeepSeek: "deepseek_api_key",
      xAI: "xai_api_key",
    };

    const keyName = providerKeyMap[providerName];
    if (!keyName) {
      return false;
    }

    return getValueForKey(data, keyName) !== "";
  }

  function getAutoSyncProviders(prevData, nextData) {
    const rules = [
      {
        provider: "OpenAI",
        keys: ["openai_api_key", "openai_base_url", "openai_api_version"],
        ready: (data) => getValueForKey(data, "openai_api_key") !== "",
      },
      {
        provider: "OpenAIVectorStores",
        keys: ["openai_api_key", "openai_base_url", "openai_api_version"],
        ready: (data) => getValueForKey(data, "openai_api_key") !== "",
      },
      {
        provider: "OpenRouter",
        keys: [
          "openrouter_api_key",
          "openrouter_base_url",
          "openrouter_api_version",
        ],
        ready: (data) => getValueForKey(data, "openrouter_api_key") !== "",
      },
      {
        provider: "Google",
        keys: ["google_api_key", "google_base_url", "google_api_version"],
        ready: (data) => getValueForKey(data, "google_api_key") !== "",
      },
      {
        provider: "GoogleFileSearchStores",
        keys: ["google_api_key", "google_base_url", "google_api_version"],
        ready: (data) => getValueForKey(data, "google_api_key") !== "",
      },
      {
        provider: "Azure",
        keys: [
          "azure_api_key",
          "azure_endpoint",
          "azure_api_version_authoring",
          "azure_api_version_inference",
        ],
        ready: (data) => shouldSyncAzure(data),
      },
      {
        provider: "Claude",
        keys: ["claude_api_key", "claude_base_url", "claude_api_version"],
        ready: (data) => getValueForKey(data, "claude_api_key") !== "",
      },
      {
        provider: "DeepSeek",
        keys: ["deepseek_api_key", "deepseek_base_url", "deepseek_api_version"],
        ready: (data) => getValueForKey(data, "deepseek_api_key") !== "",
      },
      {
        provider: "xAI",
        keys: ["xai_api_key", "xai_base_url", "xai_api_version"],
        ready: (data) => getValueForKey(data, "xai_api_key") !== "",
      },
      {
        provider: "Ollama",
        keys: ["ollama_base_url"],
        ready: (data) => getValueForKey(data, "ollama_base_url") !== "",
      },
      {
        provider: "ElevenLabs",
        keys: ["elevenlabs_api_key"],
        ready: (data) => getValueForKey(data, "elevenlabs_api_key") !== "",
      },
      {
        provider: "ElevenLabsModels",
        keys: ["elevenlabs_api_key"],
        ready: (data) => getValueForKey(data, "elevenlabs_api_key") !== "",
      },
      {
        provider: "Replicate",
        keys: ["replicate_api_key"],
        ready: (data) => getValueForKey(data, "replicate_api_key") !== "",
      },
      {
        provider: "PineconeIndexes",
        keys: ["pinecone_api_key"],
        ready: (data) => getValueForKey(data, "pinecone_api_key") !== "",
      },
      {
        provider: "QdrantCollections",
        keys: ["qdrant_url", "qdrant_api_key"],
        ready: (data) =>
          getValueForKey(data, "qdrant_url") !== "" &&
          getValueForKey(data, "qdrant_api_key") !== "",
      },
      {
        provider: "ChromaCollections",
        keys: ["chroma_url", "chroma_api_key", "chroma_tenant", "chroma_database"],
        ready: (data) => getValueForKey(data, "chroma_url") !== "",
      },
    ];

    return rules
      .filter(
        (rule) =>
          hasFieldChanges(prevData, nextData, rule.keys) && rule.ready(nextData)
      )
      .map((rule) => rule.provider);
  }

  function findSyncButton(provider) {
    if (!provider) {
      return null;
    }
    return document.querySelector(
      `.aipkit_sync_btn[data-provider="${provider}"]`
    );
  }

  let currentSync = null;

  function processAutoSyncQueue() {
    if (autoSyncInProgress) {
      return;
    }
    const nextItem = autoSyncQueue.shift();
    if (!nextItem) {
      return;
    }

    autoSyncInProgress = true;
    const { provider, options, resolve, reject } = nextItem;
    const button =
      options?.button instanceof Element
        ? options.button
        : findSyncButton(provider);
    const syncPromise = aipkit_syncModels(button, provider, options);
    currentSync = { provider, promise: syncPromise };

    if (syncPromise && typeof syncPromise.finally === "function") {
      syncPromise
        .then((response) => {
          if (typeof resolve === "function") {
            resolve(response);
          }
        })
        .catch((error) => {
          if (typeof reject === "function") {
            reject(error);
          }
        })
        .finally(() => {
          currentSync = null;
        autoSyncInProgress = false;
        processAutoSyncQueue();
      });
    } else {
      currentSync = null;
      if (typeof resolve === "function") {
        resolve(null);
      }
      autoSyncInProgress = false;
      processAutoSyncQueue();
    }
  }

  function queueProviderSync(provider, options = {}) {
    provider = normalizeSyncProvider(provider);
    if (!provider) {
      return null;
    }
    if (
      currentSync &&
      currentSync.provider === provider &&
      currentSync.promise
    ) {
      return currentSync.promise;
    }
    const alreadyQueued = autoSyncQueue.some(
      (item) => item.provider === provider
    );
    if (alreadyQueued) {
      const existingItem = autoSyncQueue.find(
        (item) => item.provider === provider
      );
      return existingItem && existingItem.promise
        ? existingItem.promise
        : null;
    }
    let resolvePromise;
    let rejectPromise;
    const promise = new Promise((resolve, reject) => {
      resolvePromise = resolve;
      rejectPromise = reject;
    });
    autoSyncQueue.push({
      provider,
      options,
      resolve: resolvePromise,
      reject: rejectPromise,
      promise,
    });
    processAutoSyncQueue();
    return promise;
  }

  function queueProviderAutoSync(prevData, nextData) {
    if (!prevData || !nextData) {
      return;
    }
    const providers = getAutoSyncProviders(prevData, nextData);
    providers.forEach((provider) =>
      queueProviderSync(provider, { silent: true, showSuccess: false })
    );
  }

  const vectorStoreProviderSyncMap = {
    openai: "OpenAIVectorStores",
    google: "GoogleFileSearchStores",
    pinecone: "PineconeIndexes",
    qdrant: "QdrantCollections",
    chroma: "ChromaCollections",
  };
  const vectorStoreProviderLastRefreshAt = {};

  function refreshVectorStoreProviderList(provider, options = {}) {
    const normalizedProvider = String(provider || "").trim().toLowerCase();
    // Built-in knowledge bases live in this site's database: re-read them, nothing to sync.
    if (normalizedProvider === "local") {
      return typeof window.aipkit_refreshLocalStores === "function"
        ? window.aipkit_refreshLocalStores()
        : null;
    }
    const syncProvider = vectorStoreProviderSyncMap[normalizedProvider];
    if (!syncProvider) {
      return null;
    }
    const refreshOptions =
      options && typeof options === "object" ? options : {};
    const minIntervalMs = Number.isFinite(Number(refreshOptions.minIntervalMs))
      ? Number(refreshOptions.minIntervalMs)
      : 30000;
    const now = Date.now();
    if (
      !refreshOptions.force &&
      vectorStoreProviderLastRefreshAt[syncProvider] &&
      now - vectorStoreProviderLastRefreshAt[syncProvider] < minIntervalMs
    ) {
      return null;
    }
    vectorStoreProviderLastRefreshAt[syncProvider] = now;

    const syncPromise = queueProviderSync(syncProvider, {
      silent: true,
      showErrors: false,
      showSuccess: false,
      showLocalStatus: false,
    });
    if (syncPromise && typeof syncPromise.catch === "function") {
      syncPromise.catch(() => {});
    }
    return syncPromise;
  }

  window.aipkit_initSyncButtons = aipkit_initSyncButtons;
  window.aipkit_syncModels = aipkit_syncModels;
  window.aipkit_queueProviderSync = queueProviderSync;
  window.aipkit_queueProviderAutoSync = queueProviderAutoSync;
  window.aipkit_isProviderReadyForAutoSync = isProviderReadyForAutoSync;
  window.aipkit_refreshVectorStoreProviderList =
    refreshVectorStoreProviderList;
})();
