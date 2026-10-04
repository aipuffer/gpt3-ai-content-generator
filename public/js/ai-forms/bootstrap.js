/** AI Forms public dependencies, model controls and initialization. */
import "../shared/html.js";
import "../shared/guest-identity.js";
import "../shared/markdown.js";
import "../chatbot/message-meta.js";
import "./state.js";
import "./markdown.js";
import "./stream-request.js";
import "./submission-controls.js";
import "./inputs.js";
import "./stream-events.js";
import "./result-actions.js";
import "./submission.js";

(function () {
  "use strict";

  const uploadAssetPromises = {};
  const pendingUploads = new WeakMap();

  function loadUploadAsset(url, stylesheet = false) {
    const type = stylesheet ? "style" : "script";
    if (uploadAssetPromises[type]) return uploadAssetPromises[type];
    if (!url) return Promise.reject(new Error("Missing upload runtime."));

    uploadAssetPromises[type] = new Promise((resolve, reject) => {
      const asset = document.createElement(stylesheet ? "link" : "script");
      if (stylesheet) {
        asset.rel = "stylesheet";
        asset.href = url;
      } else {
        asset.src = url;
        asset.async = true;
      }
      asset.onload = () => {
        if (
          stylesheet ||
          (typeof window.aipkitForms_handleFileUpload === "function" &&
            typeof window.aipkitForms_handleImageUpload === "function")
        ) {
          resolve();
        } else {
          asset.remove();
          reject(new Error("Incomplete upload runtime."));
        }
      };
      asset.onerror = () => {
        asset.remove();
        reject(new Error("Upload runtime failed to load."));
      };
      const sharedStyle = stylesheet && document.querySelector(
        'link#aipkit-public-ai-forms-css, link[href*="/public-ai-forms.bundle.css"]'
      );
      if (sharedStyle) {
        sharedStyle.after(asset);
      } else {
        document.head.appendChild(asset);
      }
    }).catch((error) => {
      delete uploadAssetPromises[type];
      throw error;
    });
    return uploadAssetPromises[type];
  }

  async function dispatchUpload(event, handlerName) {
    const input = event.target;
    const ticket = {};
    pendingUploads.set(input, ticket);
    const group = input.closest(
      ".aipkit_form_group-file-upload, .aipkit_form_group-image-upload"
    );
    if (!group) return;

    try {
      await Promise.all([
        loadUploadAsset(group.dataset.uploadStyle, true),
        typeof window[handlerName] === "function"
          ? Promise.resolve()
          : loadUploadAsset(group.dataset.uploadScript),
      ]);
    } catch (error) {
      if (pendingUploads.get(input) !== ticket || !input.isConnected) return;
      const status = group.querySelector(".aipkit-file-upload-status");
      const statusWrapper = group.querySelector(".aipkit-file-status-wrapper");
      const __ = window.wp?.i18n?.__ || ((text) => text);
      input.value = "";
      if (status) {
        status.textContent = __(
          "Unable to load upload controls. Please select your file again to retry.",
          "gpt3-ai-content-generator"
        );
        status.className = "aipkit-file-upload-status aipkit-status-error";
      }
      if (statusWrapper) statusWrapper.style.display = "flex";
      return;
    }

    if (pendingUploads.get(input) !== ticket || !input.isConnected) return;
    window[handlerName](event);
  }

  function resetUpload(event, kind, handlerName) {
    if (event.target.matches(`.aipkit-${kind}-remove-btn, .aipkit-${kind}-remove-btn *`)) {
      const formGroup = event.target.closest(`.aipkit_form_group-${kind}-upload`);
      if (formGroup) {
        if (typeof window[handlerName] === "function") {
          window[handlerName](formGroup);
        }
      }
    }
  }

  function populateModels(provider, modelSelect) {
    const allModelsData = window.aipkit_ai_forms_models || {};
    const config = window.aipkit_ai_forms_public_config || {};
    const providerKey = provider.toLowerCase();
    let modelsForProvider = allModelsData[providerKey] || [];
    const currentModelValue = providerKey === (modelSelect.dataset.provider || "").toLowerCase()
      ? modelSelect.dataset.currentValue || ""
      : "";
    const allowedModels = new Set((config.allowed_models || "").split(",").map((m) => m.trim()).filter(Boolean));

    if (config.allowed_models && config.allowed_models.trim() !== "") {
      if (Array.isArray(modelsForProvider)) {
        // Flat list like Google, OpenRouter
        modelsForProvider = modelsForProvider.filter((model) =>
          allowedModels.has(model.id)
        );
      } else {
        // Grouped object like OpenAI
        const filteredGroups = {};
        for (const groupName in modelsForProvider) {
          const filteredModelsInGroup = modelsForProvider[groupName].filter(
            (model) => allowedModels.has(model.id)
          );
          if (filteredModelsInGroup.length > 0) {
            filteredGroups[groupName] = filteredModelsInGroup;
          }
        }
        modelsForProvider = filteredGroups;
      }
    }
    modelSelect.innerHTML = "";

    if (
      !modelsForProvider ||
      (Array.isArray(modelsForProvider) && modelsForProvider.length === 0) ||
      (!Array.isArray(modelsForProvider) &&
        Object.keys(modelsForProvider).length === 0)
    ) {
      const option = new Option("No models available for this provider.", "");
      modelSelect.appendChild(option);
      return;
    }

    let foundSelected = false;
    function appendModelOption(model, parent) {
      const option = new Option(model.name, model.id);
      if (model.id === currentModelValue) {
        option.selected = true;
        foundSelected = true;
      }
      parent.appendChild(option);
    }

    if (!Array.isArray(modelsForProvider)) {
      // Grouped object like OpenAI
      Object.keys(modelsForProvider).forEach((groupName) => {
        const optgroup = document.createElement("optgroup");
        optgroup.label = groupName;
        modelsForProvider[groupName].forEach((model) => {
          appendModelOption(model, optgroup);
        });
        modelSelect.appendChild(optgroup);
      });
    } else {
      // Flat array like Google, OpenRouter
      modelsForProvider.forEach((model) => {
        appendModelOption(model, modelSelect);
      });
    }

    if (!foundSelected && currentModelValue && (!allowedModels.size || allowedModels.has(currentModelValue))) {
      const manualOption = new Option(
        `${currentModelValue} (Manual)`,
        currentModelValue,
        false,
        true
      );
      modelSelect.insertBefore(manualOption, modelSelect.firstChild);
    }

    if (modelSelect.selectedIndex === -1 && modelSelect.options.length > 0) {
      const firstOptgroup = modelSelect.querySelector("optgroup");
      if (firstOptgroup && firstOptgroup.options.length > 0) {
        modelSelect.value = firstOptgroup.options[0].value;
      } else if (modelSelect.options[0].value) {
        modelSelect.selectedIndex = 0;
      }
    }
  }

  function initializeSingleForm(formWrapper) {
    const providerSelect = formWrapper.querySelector(
      ".aipkit_aiform_provider_select"
    );
    const modelSelect = formWrapper.querySelector(
      ".aipkit_aiform_model_select"
    );

    if (!modelSelect) {
      return;
    }

    if (providerSelect) {
      // Case 1: Both Provider and Model dropdowns are visible.
      populateModels(providerSelect.value, modelSelect); // Initial population
      providerSelect.addEventListener("change", function () {
        populateModels(this.value, modelSelect);
      });
    } else {
      // Case 2: Only Model dropdown is visible.
      const defaultProvider = modelSelect.dataset.provider;
      if (defaultProvider) {
        populateModels(defaultProvider, modelSelect);
      } else {
        console.warn(
          "AI Forms: Model select is present, but no provider information was found (missing data-provider attribute)."
        );
      }
    }
  }

  function aipkit_initAIFormsPublic() {
    const state = window.aipkitAIFormsPublicState;

    if (typeof window.aipkit_handleFormSubmitSSE !== "function") {
      console.error(
        "AIPKit AI Forms Init: handleFormSubmitSSE is not defined."
      );
      return;
    }

    const forms = document.querySelectorAll(".aipkit-ai-form-wrapper");
    forms.forEach((formWrapper) => {
      initializeSingleForm(formWrapper);
      const formElement = formWrapper.querySelector("form.aipkit-ai-form-main");
      if (formElement && !formElement.dataset.sseInitialized) {
        formElement.addEventListener(
          "submit",
          window.aipkit_handleFormSubmitSSE
        );
        formElement.dataset.sseInitialized = "true";
      }

      if (formElement && !formElement.dataset.fileUploadInitialized) {
        formElement.addEventListener("change", (event) => {
          if (event.target.matches(".aipkit-file-upload-input")) {
            void dispatchUpload(event, "aipkitForms_handleFileUpload");
          }
          if (event.target.matches(".aipkit-image-upload-input")) {
            void dispatchUpload(event, "aipkitForms_handleImageUpload");
          }
        });
        formElement.addEventListener("click", (event) => {
          resetUpload(event, "file", "aipkitForms_resetFileUploadUI");
          resetUpload(event, "image", "aipkitForms_resetImageUploadUI");
        });
        formElement.dataset.fileUploadInitialized = "true";
      }
    });

    if (typeof window.aipkit_initAIFormsConversation === "function") {
      window.aipkit_initAIFormsConversation();
    }

    if (
      window.aipkitFormsMarkdown &&
      typeof window.aipkitFormsMarkdown.createRenderer === "function"
    ) {
      state.mdInstanceAIForms = window.aipkitFormsMarkdown.createRenderer();
    } else if (typeof window.aipkit_createFallbackMarkdownRenderer === "function") {
      state.mdInstanceAIForms = window.aipkit_createFallbackMarkdownRenderer();
    }
  }

  // Expose globally
  window.aipkit_initAIFormsPublic = aipkit_initAIFormsPublic;

  if (document.readyState === "loading") {
    document.addEventListener(
      "DOMContentLoaded",
      window.aipkit_initAIFormsPublic
    );
  } else {
    window.aipkit_initAIFormsPublic();
  }
})();
