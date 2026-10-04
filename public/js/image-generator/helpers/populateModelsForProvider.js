(function () {
  "use strict";

  const PROVIDERS = [
    { key: "openai", value: "OpenAI", label: "OpenAI" },
    { key: "google", value: "Google", label: "Google" },
    { key: "openrouter", value: "OpenRouter", label: "OpenRouter" },
    { key: "aipuffercloud", value: "AIPufferCloud", label: "AI Puffer Cloud" },
    { key: "xai", value: "xAI", label: "xAI" },
    { key: "azure", value: "Azure", label: "Azure" },
    { key: "replicate", value: "Replicate", label: "Replicate" },
  ];

  const OPENROUTER_IMAGE_CAPABILITY_DEFAULTS = {
    image_input: true,
    image_output: true,
    image_generation: true,
  };

  const normalize = (value) => String(value || "").trim().toLowerCase();
  const normalizeProvider = (provider) =>
    normalize(provider).replace(/[^a-z0-9]+/g, "");

  const normalizeModel = (model) => {
    if (typeof model === "string") {
      return { id: model, name: model };
    }
    return model && typeof model === "object" ? model : null;
  };

  const parseAllowedModels = (allowedModelsStr) => {
    const values = String(allowedModelsStr || "")
      .split(",")
      .map(normalize)
      .filter(Boolean);
    return values.length > 0 ? new Set(values) : null;
  };

  const isAllowed = (allowedModels, providerKey, modelId) =>
    !allowedModels ||
    allowedModels.has(providerKey) ||
    allowedModels.has(normalize(modelId));

  const openrouterSupportsGeneration = (model) => {
    const rawCapabilities = model?.capabilities;
    if (!rawCapabilities || typeof rawCapabilities !== "object") {
      return true;
    }
    const capabilities = {
      ...OPENROUTER_IMAGE_CAPABILITY_DEFAULTS,
      ...rawCapabilities,
    };
    return Boolean(capabilities.image_output || capabilities.image_generation);
  };

  const openrouterSupportsEditing = (model) => {
    const rawCapabilities = model?.capabilities;
    if (!rawCapabilities || typeof rawCapabilities !== "object") {
      return true;
    }
    const capabilities = {
      ...OPENROUTER_IMAGE_CAPABILITY_DEFAULTS,
      ...rawCapabilities,
    };
    return Boolean(
      capabilities.image_input &&
        (capabilities.image_output || capabilities.image_generation)
    );
  };

  const googleSupportsEditing = (model) => {
    const modelId = normalize(model?.id);
    return Boolean(
      modelId &&
        !modelId.includes("veo") &&
        modelId.includes("gemini") &&
        (modelId.includes("image-generation") ||
          modelId.includes("flash-image") ||
          modelId.includes("pro-image"))
    );
  };

  const openaiSupportsEditing = (model) =>
    normalize(model?.id).startsWith("gpt-image");

  const xaiSupportsGeneration = (model) => {
    const outputModalities = Array.isArray(model?.output_modalities)
      ? model.output_modalities.map(normalize)
      : [];
    return Boolean(
      normalize(model?.id) &&
        (outputModalities.length === 0 || outputModalities.includes("image"))
    );
  };

  const xaiSupportsEditing = (model) => {
    if (!xaiSupportsGeneration(model)) {
      return false;
    }
    const inputModalities = Array.isArray(model?.input_modalities)
      ? model.input_modalities.map(normalize)
      : [];
    return inputModalities.length === 0 || inputModalities.includes("image");
  };

  const getFamilyData = (model, fallbackLabel, fallbackKey = "other") => ({
    familyKey: String(model?.family_key || fallbackKey || "other"),
    familyLabel: String(model?.family_label || fallbackLabel || ""),
    familyOrder: Number(model?.family_order ?? 999),
    familyCollapsed: Boolean(model?.family_collapsed),
    recommended: Boolean(model?.recommended),
  });

  const createEntry = (
    provider,
    model,
    groupLabel = "",
    familyLabel = "",
    familyKey = "other"
  ) => {
    const normalizedModel = normalizeModel(model);
    if (!provider || !normalizedModel?.id) {
      return null;
    }
    const modelId = String(normalizedModel.id);
    return {
      provider: provider.value,
      providerKey: provider.key,
      providerLabel: provider.label,
      modelValue: modelId,
      modelLabel: String(normalizedModel.name || modelId),
      groupLabel: groupLabel || provider.label,
      category: "ai",
      ...getFamilyData(
        normalizedModel,
        familyLabel || groupLabel || provider.label,
        familyKey
      ),
    };
  };

  const getProviderModels = (config, provider, mode) => {
    const isEditMode = mode === "edit";
    const rows = [];

    if (provider.key === "openai") {
      const models = Array.isArray(config.openai_models)
        ? config.openai_models.map(normalizeModel).filter(Boolean)
        : [];
      const seedOrder = Array.isArray(config.model_catalog?.seeds?.OpenAIImage)
        ? config.model_catalog.seeds.OpenAIImage.map(normalize)
        : [];
      const order = new Map(seedOrder.map((modelId, index) => [modelId, index]));
      models
        .filter((model) => !isEditMode || openaiSupportsEditing(model))
        .sort(
          (first, second) =>
            (order.get(normalize(first.id)) ?? Number.MAX_SAFE_INTEGER) -
              (order.get(normalize(second.id)) ?? Number.MAX_SAFE_INTEGER) ||
            String(first.name || first.id).localeCompare(
              String(second.name || second.id)
            )
        )
        .forEach((model) => rows.push(createEntry(provider, model)));
    } else if (provider.key === "google") {
      const googleModels =
        config.google_models && typeof config.google_models === "object"
          ? config.google_models
          : {};
      const imageModels = Array.isArray(googleModels.image)
        ? googleModels.image.map(normalizeModel).filter(Boolean)
        : [];
      const videoModels = Array.isArray(googleModels.video)
        ? googleModels.video.map(normalizeModel).filter(Boolean)
        : [];
      imageModels
        .filter((model) => !isEditMode || googleSupportsEditing(model))
        .sort((first, second) =>
          String(first.name || first.id).localeCompare(
            String(second.name || second.id)
          )
        )
        .forEach((model) =>
          rows.push(
            createEntry(provider, model, "Image models", "Image models", "image")
          )
        );
      if (!isEditMode) {
        videoModels
          .sort((first, second) =>
            String(first.name || first.id).localeCompare(
              String(second.name || second.id)
            )
          )
          .forEach((model) =>
            rows.push(
              createEntry(provider, model, "Video models", "Video models", "video")
            )
          );
      }
    } else if (provider.key === "aipuffercloud") {
      const operation = isEditMode ? "image_edit" : "image_generate";
      const models = Array.isArray(config.cloud_image_models)
        ? config.cloud_image_models.filter((model) => model.operation === operation)
        : [];
      models.forEach((model) => rows.push(createEntry(provider, model)));
    } else if (provider.key === "openrouter") {
      const models = Array.isArray(config.openrouter_image_models)
        ? config.openrouter_image_models.map(normalizeModel).filter(Boolean)
        : [];
      models
        .filter((model) =>
          isEditMode
            ? openrouterSupportsEditing(model)
            : openrouterSupportsGeneration(model)
        )
        .sort((first, second) =>
          String(first.name || first.id).localeCompare(
            String(second.name || second.id)
          )
        )
        .forEach((model) => rows.push(createEntry(provider, model)));
    } else if (provider.key === "xai") {
      const models = Array.isArray(config.xai_image_models)
        ? config.xai_image_models.map(normalizeModel).filter(Boolean)
        : [];
      models
        .filter((model) =>
          isEditMode ? xaiSupportsEditing(model) : xaiSupportsGeneration(model)
        )
        .sort((first, second) =>
          String(first.name || first.id).localeCompare(
            String(second.name || second.id)
          )
        )
        .forEach((model) => rows.push(createEntry(provider, model)));
    } else if (provider.key === "azure") {
      if (!isEditMode) {
        const models = Array.isArray(config.azure_models)
          ? config.azure_models.map(normalizeModel).filter(Boolean)
          : [];
        models.forEach((model) => rows.push(createEntry(provider, model)));
      }
    } else if (provider.key === "replicate" && !isEditMode) {
      const models = Array.isArray(config.replicate_models)
        ? config.replicate_models.map(normalizeModel).filter(Boolean)
        : [];
      models
        .sort((first, second) =>
          String(first.name || first.id).localeCompare(
            String(second.name || second.id)
          )
        )
        .forEach((model) => {
          const rawName = String(model.name || model.id);
          const owner = rawName.includes("/") ? rawName.split("/")[0] : "Replicate";
          const displayModel = {
            ...model,
            name: rawName.includes("/")
              ? rawName.split("/").slice(1).join("/")
              : rawName,
          };
          rows.push(createEntry(provider, displayModel, owner, owner, owner));
        });
    }

    return rows.filter(Boolean);
  };

  function aipkit_getPublicImageModelEntries(
    config = {},
    allowedModelsStr = "",
    mode = "generate",
    providerFilter = ""
  ) {
    const normalizedMode = normalize(mode) === "edit" ? "edit" : "generate";
    const normalizedProviderFilter = normalizeProvider(providerFilter);
    const allowedModels = parseAllowedModels(allowedModelsStr);

    return PROVIDERS.flatMap((provider) => {
      if (normalizedProviderFilter && provider.key !== normalizedProviderFilter) {
        return [];
      }
      return getProviderModels(config, provider, normalizedMode).filter((entry) =>
        isAllowed(allowedModels, provider.key, entry.modelValue)
      );
    });
  }

  const appendEntryOption = (group, entry, label) => {
    const option = new Option(label, `${entry.provider}::${entry.modelValue}`);
    option.dataset.provider = entry.provider;
    option.dataset.providerLabel = entry.providerLabel;
    option.dataset.model = entry.modelValue;
    option.dataset.category = entry.category;
    option.dataset.groupLabel = entry.groupLabel;
    option.dataset.familyKey = entry.familyKey;
    option.dataset.familyLabel = entry.familyLabel;
    option.dataset.familyOrder = String(entry.familyOrder);
    option.dataset.familyCollapsed = entry.familyCollapsed ? "true" : "false";
    option.dataset.recommended = entry.recommended ? "true" : "false";
    group.appendChild(option);
    return option;
  };

  function aipkit_populatePublicImageModelPicker(
    sourceSelect,
    config = {},
    options = {}
  ) {
    if (!sourceSelect) {
      return null;
    }
    const showProvider = options.showProvider !== false;
    const showModel = options.showModel !== false;
    const currentProvider = String(options.provider || "").trim();
    const currentModel = String(options.model || "").trim();
    const providerFilter = showProvider ? "" : currentProvider;
    let entries = aipkit_getPublicImageModelEntries(
      config,
      options.allowedModels || "",
      options.mode || "generate",
      providerFilter
    );

    if (!showModel) {
      const byProvider = new Map();
      entries.forEach((entry) => {
        const existing = byProvider.get(entry.providerKey);
        if (
          !existing ||
          (normalizeProvider(entry.provider) ===
            normalizeProvider(currentProvider) &&
            normalize(entry.modelValue) === normalize(currentModel))
        ) {
          byProvider.set(entry.providerKey, entry);
        }
      });
      entries = Array.from(byProvider.values());
    }

    sourceSelect.replaceChildren();
    const groups = new Map();
    entries.forEach((entry) => {
      if (!groups.has(entry.providerKey)) {
        const group = document.createElement("optgroup");
        group.label = entry.providerLabel;
        group.dataset.provider = entry.provider;
        sourceSelect.appendChild(group);
        groups.set(entry.providerKey, group);
      }
      appendEntryOption(
        groups.get(entry.providerKey),
        entry,
        showModel ? entry.modelLabel : entry.providerLabel
      );
    });

    const sourceOptions = Array.from(sourceSelect.options);
    const exactMatch = sourceOptions.find(
      (option) =>
        normalizeProvider(option.dataset.provider) ===
          normalizeProvider(currentProvider) &&
        normalize(option.dataset.model) === normalize(currentModel)
    );
    const providerMatch = sourceOptions.find(
      (option) =>
        normalizeProvider(option.dataset.provider) ===
        normalizeProvider(currentProvider)
    );
    const selectedOption = exactMatch || ((!showModel && currentModel) ? null : providerMatch) || (!currentProvider ? sourceOptions[0] : null) || null;
    sourceSelect.value = selectedOption ? selectedOption.value : "";
    sourceSelect.disabled = sourceOptions.length === 0;

    return selectedOption
      ? {
          provider: selectedOption.dataset.provider || "",
          model: selectedOption.dataset.model || "",
          label: String(selectedOption.textContent || "").trim(),
          count: sourceOptions.length,
        }
      : null;
  }

  window.aipkit_getPublicImageModelEntries =
    aipkit_getPublicImageModelEntries;
  window.aipkit_populatePublicImageModelPicker =
    aipkit_populatePublicImageModelPicker;
})();
