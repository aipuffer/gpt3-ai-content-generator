/**
 * AIPKit Content Writer - Image Settings UI Handler
 *
 * Image settings:
 * - one visible Images control
 * - one searchable selector for AI-generated and stock-photo sources
 * - primary model, count, and placement controls
 * - a compact modal for display and provider-specific options
 *
 * The saved fields remain the same for compatibility.
 */
(function () {
  "use strict";

  const translate =
    window.wp?.i18n?.__ ||
    function (str) {
      return str;
    };

  const STOCK_IMAGE_OPTION_DEFAULTS = Object.freeze({
    pexels_orientation: "none",
    pexels_size: "none",
    pexels_color: "",
    pixabay_orientation: "all",
    pixabay_image_type: "all",
    pixabay_category: "",
  });

  function normalizeStockImageOptionControls(root) {
    if (!root?.querySelectorAll) {
      return 0;
    }

    let normalizedCount = 0;
    Object.entries(STOCK_IMAGE_OPTION_DEFAULTS).forEach(
      ([fieldName, defaultValue]) => {
        root
          .querySelectorAll(`select[name="${fieldName}"]`)
          .forEach((control) => {
            const currentValue = String(control.value ?? "");
            const hasMatchingOption = Array.from(control.options || []).some(
              (option) => String(option.value) === currentValue
            );
            if (hasMatchingOption) {
              return;
            }

            control.value = defaultValue;
            normalizedCount += 1;
          });
      }
    );

    return normalizedCount;
  }

  function normalizeProviderKey(value) {
    return String(value || "").trim().toLowerCase();
  }

  function isStockImageProvider(providerKey) {
    const normalizedKey = normalizeProviderKey(providerKey);
    return normalizedKey === "pexels" || normalizedKey === "pixabay";
  }

  function isAiImageProvider(providerKey) {
    return (
      !isStockImageProvider(providerKey) &&
      ["openai", "google", "openrouter", "azure", "xai", "replicate", "aipuffercloud"].includes(
        normalizeProviderKey(providerKey)
      )
    );
  }

  function getProviderOptionBlocks(root) {
    return Array.from(
      root?.querySelectorAll("[data-aipkit-image-provider-options]") || []
    );
  }

  function getProviderOptionBlock(root, providerKey) {
    const normalizedProvider = normalizeProviderKey(providerKey);
    return (
      getProviderOptionBlocks(root).find(
        (block) =>
          normalizeProviderKey(block.dataset.aipkitImageProviderOptions) ===
          normalizedProvider
      ) || null
    );
  }

  function getProviderOptionControlValue(control) {
    if (!control) {
      return "";
    }
    if (control.type === "checkbox") {
      return control.checked ? "1" : "0";
    }
    return String(control.value || "");
  }

  function collectImageProviderOptions(root) {
    const providerOptions = {};
    getProviderOptionBlocks(root).forEach((block) => {
      const providerKey = normalizeProviderKey(
        block.dataset.aipkitImageProviderOptions
      );
      if (!providerKey) {
        return;
      }

      const controls = block.querySelectorAll(
        "[data-aipkit-image-provider-option]"
      );
      controls.forEach((control) => {
        const optionKey = String(
          control.dataset.aipkitImageProviderOption || ""
        ).trim();
        if (!optionKey) {
          return;
        }
        if (!providerOptions[providerKey]) {
          providerOptions[providerKey] = {};
        }
        providerOptions[providerKey][optionKey] =
          getProviderOptionControlValue(control);
      });
    });

    return providerOptions;
  }

  function parseImageProviderOptions(rawOptions) {
    if (!rawOptions) {
      return {};
    }
    if (typeof rawOptions === "object") {
      return rawOptions;
    }
    try {
      const parsed = JSON.parse(String(rawOptions));
      return parsed && typeof parsed === "object" ? parsed : {};
    } catch (error) {
      return {};
    }
  }

  function setProviderOptionControlValue(control, value) {
    if (!control) {
      return;
    }
    const normalizedValue =
      value === null || typeof value === "undefined" ? "" : String(value);
    if (control.type === "checkbox") {
      control.checked =
        normalizedValue === "1" ||
        normalizedValue === "true" ||
        value === true;
      return;
    }
    control.value = normalizedValue;
  }

  function applyImageProviderOptions(root, rawOptions) {
    if (!root) {
      return;
    }

    const providerOptions = parseImageProviderOptions(rawOptions);
    getProviderOptionBlocks(root).forEach((block) => {
      const providerKey = normalizeProviderKey(
        block.dataset.aipkitImageProviderOptions
      );
      const optionsForProvider =
        providerOptions &&
        providerOptions[providerKey] &&
        typeof providerOptions[providerKey] === "object"
          ? providerOptions[providerKey]
          : null;
      if (!optionsForProvider) {
        return;
      }

      block
        .querySelectorAll("[data-aipkit-image-provider-option]")
        .forEach((control) => {
          const optionKey = String(
            control.dataset.aipkitImageProviderOption || ""
          ).trim();
          if (
            optionKey &&
            Object.prototype.hasOwnProperty.call(optionsForProvider, optionKey)
          ) {
            setProviderOptionControlValue(control, optionsForProvider[optionKey]);
          }
        });
    });
  }

  function updateOpenAICompressionRows(root) {
    getProviderOptionBlocks(root).forEach((block) => {
      if (
        normalizeProviderKey(block.dataset.aipkitImageProviderOptions) !==
        "openai"
      ) {
        return;
      }
      const formatControl = block.querySelector(
        '[data-aipkit-image-provider-option="output_format"]'
      );
      const outputFormat = normalizeProviderKey(formatControl?.value || "");
      const showCompression =
        outputFormat === "jpeg" || outputFormat === "webp";
      block
        .querySelectorAll("[data-aipkit-openai-compression-row]")
        .forEach((row) => setHidden(row, !showCompression));
    });
  }

  function openAIModelSupportsTransparentBackground(modelId) {
    const model = String(modelId || "").trim().toLowerCase();
    const unsupportedModel = String(
      window.aipkit_dashboard?.modelCatalog?.defaults?.OpenAIImage || ""
    ).toLowerCase();
    return (
      model.startsWith("gpt-image") &&
      (!unsupportedModel || !model.startsWith(unsupportedModel))
    );
  }

  function updateOpenAIBackgroundOptions(root, modelId) {
    getProviderOptionBlocks(root).forEach((block) => {
      if (
        normalizeProviderKey(block.dataset.aipkitImageProviderOptions) !==
        "openai"
      ) {
        return;
      }
      const backgroundControl = block.querySelector(
        '[data-aipkit-image-provider-option="background"]'
      );
      const outputFormat = normalizeProviderKey(
        block.querySelector(
          '[data-aipkit-image-provider-option="output_format"]'
        )?.value || ""
      );
      const supportsTransparent =
        openAIModelSupportsTransparentBackground(modelId) &&
        outputFormat !== "jpeg";
      const transparentOption = backgroundControl?.querySelector(
        '[data-aipkit-openai-transparent-background-option]'
      );
      if (!transparentOption) {
        return;
      }
      transparentOption.hidden = !supportsTransparent;
      transparentOption.disabled = !supportsTransparent;
      if (!supportsTransparent && backgroundControl.value === "transparent") {
        backgroundControl.value = "";
      }
    });
  }

  function updateAzureCompressionRows(root) {
    getProviderOptionBlocks(root).forEach((block) => {
      if (
        normalizeProviderKey(block.dataset.aipkitImageProviderOptions) !==
        "azure"
      ) {
        return;
      }
      const formatControl = block.querySelector(
        '[data-aipkit-image-provider-option="output_format"]'
      );
      const outputFormat = normalizeProviderKey(formatControl?.value || "");
      block
        .querySelectorAll("[data-aipkit-azure-compression-row]")
        .forEach((row) => setHidden(row, outputFormat !== "jpeg"));
    });
  }

  function isGoogleGeminiResponseFormatModel(modelId) {
    const model = String(modelId || "").toLowerCase();
    return model.includes("gemini") && model.includes("image");
  }

  function getGoogleAspectRatios(modelId) {
    const model = String(modelId || "").toLowerCase();
    const ratios = [
      "",
      "1:1",
      "2:3",
      "3:2",
      "3:4",
      "4:3",
      "4:5",
      "5:4",
      "9:16",
      "16:9",
      "21:9",
    ];
    if (model.includes("gemini-3.1-flash-image")) {
      return ratios.concat(["1:4", "4:1", "1:8", "8:1"]);
    }
    return isGoogleGeminiResponseFormatModel(model) ? ratios : [""];
  }

  function getGoogleImageSizes(modelId) {
    const model = String(modelId || "").toLowerCase();
    if (model.includes("gemini-3.1-flash-lite-image")) {
      return ["", "1k"];
    }
    if (model.includes("gemini-3.1-flash-image")) {
      return ["", "512", "1k", "2k", "4k"];
    }
    if (model.includes("gemini-3-pro-image")) {
      return ["", "1k", "2k", "4k"];
    }
    return [""];
  }

  function getOpenRouterModelMetadata(modelId) {
    const models = Array.isArray(
      window.aipkit_dashboard?.imageGeneratorModels?.openrouter
    )
      ? window.aipkit_dashboard.imageGeneratorModels.openrouter
      : [];
    const target = String(modelId || "").trim().toLowerCase();
    if (!target) {
      return null;
    }
    return (
      models.find((model) => {
        const id = String(model?.id || "").trim().toLowerCase();
        return id === target;
      }) || null
    );
  }

  function getOpenRouterParameterSchema(modelId) {
    const metadata = getOpenRouterModelMetadata(modelId);
    const schema = metadata?.supported_parameter_schema;
    return schema && typeof schema === "object" ? schema : {};
  }

  function getOpenRouterParameterDescriptor(modelId, parameter) {
    const descriptor = getOpenRouterParameterSchema(modelId)?.[parameter];
    return descriptor && typeof descriptor === "object" ? descriptor : null;
  }

  function openRouterModelSupportsParameter(modelId, parameter) {
    const model = String(modelId || "").trim().toLowerCase();
    if (!model) {
      return false;
    }

    const parameterSchema = getOpenRouterParameterSchema(modelId);
    if (Object.keys(parameterSchema).length > 0) {
      return Boolean(parameterSchema[parameter]);
    }

    const metadata = getOpenRouterModelMetadata(modelId);
    const supportedParameters = Array.isArray(metadata?.supported_parameters)
      ? metadata.supported_parameters.map((value) =>
          String(value || "").trim().toLowerCase()
        )
      : [];
    if (
      supportedParameters.includes(parameter) ||
      (parameter === "aspect_ratio" &&
        (supportedParameters.includes("image_config") ||
          supportedParameters.includes("image_config.aspect_ratio"))) ||
      (parameter === "resolution" &&
        (supportedParameters.includes("image_config") ||
          supportedParameters.includes("image_config.image_size")))
    ) {
      return true;
    }

    if (parameter !== "aspect_ratio" && parameter !== "resolution") {
      return false;
    }

    return (
      (model.startsWith("google/gemini-") && model.includes("image")) ||
      model.startsWith("black-forest-labs/flux") ||
      model.startsWith("recraft/") ||
      model.startsWith("sourceful/riverflow")
    );
  }

  function getOpenRouterEnumValues(modelId, parameter, fallbackValues = [""]) {
    const descriptor = getOpenRouterParameterDescriptor(modelId, parameter);
    const values = Array.isArray(descriptor?.values)
      ? descriptor.values
          .map((value) => String(value || "").trim().toLowerCase())
          .filter(Boolean)
      : [];
    return values.length > 0 ? ["", ...values] : fallbackValues;
  }

  function openRouterModelSupportsAnyImageOption(modelId) {
    return [
      "aspect_ratio",
      "resolution",
      "quality",
      "output_format",
      "output_compression",
      "background",
    ].some((parameter) => openRouterModelSupportsParameter(modelId, parameter));
  }

  const replicateProviderOptionFields = [
    "aspect_ratio",
    "width",
    "height",
    "negative_prompt",
    "guidance",
    "num_inference_steps",
    "seed",
    "output_format",
    "output_quality",
  ];

  const replicateAspectRatioFallbacks = [
    "",
    "1:1",
    "16:9",
    "21:9",
    "3:2",
    "2:3",
    "4:3",
    "3:4",
    "4:5",
    "5:4",
    "9:16",
    "1:2",
    "2:1",
    "3:1",
    "1:3",
  ];

  const replicateOutputFormatFallbacks = ["", "webp", "png", "jpg", "jpeg"];

  function getReplicateModelMetadata(modelId) {
    const models = Array.isArray(
      window.aipkit_dashboard?.imageGeneratorModels?.replicate
    )
      ? window.aipkit_dashboard.imageGeneratorModels.replicate
      : [];
    const target = String(modelId || "").trim().toLowerCase();
    if (!target) {
      return null;
    }
    return (
      models.find((model) => {
        const id = String(model?.id || "").trim().toLowerCase();
        return id === target;
      }) || null
    );
  }

  function getReplicateInputSchema(modelId) {
    const metadata = getReplicateModelMetadata(modelId);
    const schema = metadata?.input_schema || metadata?.replicate_input_schema;
    return schema && typeof schema === "object" ? schema : {};
  }

  function getReplicateInputFields(modelId) {
    const schema = getReplicateInputSchema(modelId);
    const fields = schema.fields || schema;
    return fields && typeof fields === "object" ? fields : {};
  }

  function getReplicateInputField(modelId, fieldName) {
    const fields = getReplicateInputFields(modelId);
    const field = fields[fieldName];
    return field && typeof field === "object" ? field : null;
  }

  function replicateModelSupportsInputField(modelId, fieldName) {
    return Boolean(getReplicateInputField(modelId, fieldName));
  }

  function replicateModelSupportsProviderOptions(modelId) {
    return replicateProviderOptionFields.some((fieldName) =>
      replicateModelSupportsInputField(modelId, fieldName)
    );
  }

  function getReplicateSchemaEnumValues(modelId, fieldName, fallbackValues) {
    const field = getReplicateInputField(modelId, fieldName);
    const enumValues = Array.isArray(field?.enum)
      ? field.enum
          .map((value) => String(value || "").trim())
          .filter((value) => value !== "")
      : [];
    if (enumValues.length > 0) {
      return ["", ...enumValues];
    }
    return fallbackValues;
  }

  function setNumberInputSchemaRange(input, field, fallbackMin, fallbackMax) {
    if (!input) {
      return;
    }
    const minValue = Number.isFinite(Number(field?.minimum))
      ? Number(field.minimum)
      : fallbackMin;
    const maxValue = Number.isFinite(Number(field?.maximum))
      ? Number(field.maximum)
      : fallbackMax;
    input.min = String(minValue);
    input.max = String(maxValue);
    if (input.value !== "") {
      const numericValue = Number(input.value);
      if (
        !Number.isFinite(numericValue) ||
        numericValue < minValue ||
        numericValue > maxValue
      ) {
        input.value = "";
      }
    }
  }

  function setSelectAllowedValues(select, allowedValues) {
    if (!select?.options) {
      return;
    }
    const allowed = new Set(allowedValues);
    Array.from(select.options).forEach((option) => {
      const isAllowed = allowed.has(option.value);
      option.hidden = !isAllowed;
      option.disabled = !isAllowed;
    });
    if (!allowed.has(select.value)) {
      select.value = "";
    }
  }

  function isImageProviderOptionsSupported(providerKey, modelId) {
    const provider = normalizeProviderKey(providerKey);
    if (provider === "openrouter") {
      return openRouterModelSupportsAnyImageOption(modelId);
    }
    if (provider === "replicate") {
      return replicateModelSupportsProviderOptions(modelId);
    }
    if (provider !== "google") {
      return true;
    }
    return isGoogleGeminiResponseFormatModel(modelId);
  }

  function updateImageProviderOptionRows(root, providerKey, modelId) {
    updateOpenAICompressionRows(root);
    updateOpenAIBackgroundOptions(root, modelId);
    updateAzureCompressionRows(root);

    if (normalizeProviderKey(providerKey) === "openrouter") {
      getProviderOptionBlocks(root).forEach((block) => {
        if (
          normalizeProviderKey(block.dataset.aipkitImageProviderOptions) !==
          "openrouter"
        ) {
          return;
        }

        const aspectControl = block.querySelector(
          '[data-aipkit-image-provider-option="aspect_ratio"]'
        );
        const imageSizeControl = block.querySelector(
          '[data-aipkit-image-provider-option="image_size"]'
        );
        const qualityControl = block.querySelector(
          '[data-aipkit-image-provider-option="quality"]'
        );
        const outputFormatControl = block.querySelector(
          '[data-aipkit-image-provider-option="output_format"]'
        );
        const backgroundControl = block.querySelector(
          '[data-aipkit-image-provider-option="background"]'
        );

        setSelectAllowedValues(
          aspectControl,
          getOpenRouterEnumValues(modelId, "aspect_ratio", [
            "",
            "auto",
            "1:1",
            "2:3",
            "3:2",
            "3:4",
            "4:3",
            "4:5",
            "5:4",
            "9:16",
            "16:9",
            "21:9",
          ])
        );
        setSelectAllowedValues(
          imageSizeControl,
          getOpenRouterEnumValues(modelId, "resolution", ["", "512", "1k", "2k", "4k"])
        );
        setSelectAllowedValues(
          qualityControl,
          getOpenRouterEnumValues(modelId, "quality")
        );
        setSelectAllowedValues(
          outputFormatControl,
          getOpenRouterEnumValues(modelId, "output_format")
        );
        setSelectAllowedValues(
          backgroundControl,
          getOpenRouterEnumValues(modelId, "background")
        );

        block
          .querySelectorAll("[data-aipkit-openrouter-aspect-ratio-row]")
          .forEach((row) =>
            setHidden(
              row,
              !openRouterModelSupportsParameter(modelId, "aspect_ratio")
            )
          );
        block
          .querySelectorAll("[data-aipkit-openrouter-resolution-row]")
          .forEach((row) =>
            setHidden(
              row,
              !openRouterModelSupportsParameter(modelId, "resolution")
            )
          );
        ["quality", "output_format", "background"].forEach((parameter) => {
          block
            .querySelectorAll(`[data-aipkit-openrouter-${parameter.replace("_", "-")}-row]`)
            .forEach((row) =>
              setHidden(
                row,
                !openRouterModelSupportsParameter(modelId, parameter)
              )
            );
        });
        const outputFormat = String(outputFormatControl?.value || "").toLowerCase();
        const showCompression =
          openRouterModelSupportsParameter(modelId, "output_compression") &&
          ["jpeg", "webp"].includes(outputFormat);
        block
          .querySelectorAll("[data-aipkit-openrouter-compression-row]")
          .forEach((row) => setHidden(row, !showCompression));
      });
      return;
    }

    if (normalizeProviderKey(providerKey) === "replicate") {
      getProviderOptionBlocks(root).forEach((block) => {
        if (
          normalizeProviderKey(block.dataset.aipkitImageProviderOptions) !==
          "replicate"
        ) {
          return;
        }

        replicateProviderOptionFields.forEach((fieldName) => {
          const field = getReplicateInputField(modelId, fieldName);
          block
            .querySelectorAll(
              `[data-aipkit-replicate-option-row="${fieldName}"]`
            )
            .forEach((row) => setHidden(row, !field));
        });

        setSelectAllowedValues(
          block.querySelector(
            '[data-aipkit-image-provider-option="aspect_ratio"]'
          ),
          getReplicateSchemaEnumValues(
            modelId,
            "aspect_ratio",
            replicateAspectRatioFallbacks
          )
        );
        setSelectAllowedValues(
          block.querySelector(
            '[data-aipkit-image-provider-option="output_format"]'
          ),
          getReplicateSchemaEnumValues(
            modelId,
            "output_format",
            replicateOutputFormatFallbacks
          )
        );
        setNumberInputSchemaRange(
          block.querySelector('[data-aipkit-image-provider-option="width"]'),
          getReplicateInputField(modelId, "width"),
          64,
          4096
        );
        setNumberInputSchemaRange(
          block.querySelector('[data-aipkit-image-provider-option="height"]'),
          getReplicateInputField(modelId, "height"),
          64,
          4096
        );
        setNumberInputSchemaRange(
          block.querySelector('[data-aipkit-image-provider-option="guidance"]'),
          getReplicateInputField(modelId, "guidance"),
          0,
          30
        );
        setNumberInputSchemaRange(
          block.querySelector(
            '[data-aipkit-image-provider-option="num_inference_steps"]'
          ),
          getReplicateInputField(modelId, "num_inference_steps"),
          1,
          100
        );
        setNumberInputSchemaRange(
          block.querySelector('[data-aipkit-image-provider-option="seed"]'),
          getReplicateInputField(modelId, "seed"),
          0,
          2147483647
        );
        setNumberInputSchemaRange(
          block.querySelector(
            '[data-aipkit-image-provider-option="output_quality"]'
          ),
          getReplicateInputField(modelId, "output_quality"),
          0,
          100
        );
      });
      return;
    }

    if (normalizeProviderKey(providerKey) !== "google") {
      return;
    }

    getProviderOptionBlocks(root).forEach((block) => {
      if (
        normalizeProviderKey(block.dataset.aipkitImageProviderOptions) !==
        "google"
      ) {
        return;
      }

      const supportsGeminiConfig = isGoogleGeminiResponseFormatModel(modelId);
      const aspectRatios = getGoogleAspectRatios(modelId);
      const imageSizes = getGoogleImageSizes(modelId);
      const aspectControl = block.querySelector(
        '[data-aipkit-image-provider-option="aspect_ratio"]'
      );
      const imageSizeControl = block.querySelector(
        '[data-aipkit-image-provider-option="image_size"]'
      );

      setSelectAllowedValues(aspectControl, aspectRatios);
      setSelectAllowedValues(imageSizeControl, imageSizes);

      block
        .querySelectorAll("[data-aipkit-google-aspect-ratio-row]")
        .forEach((row) => setHidden(row, !supportsGeminiConfig));
      block
        .querySelectorAll("[data-aipkit-google-image-size-row]")
        .forEach((row) => setHidden(row, imageSizes.length <= 1));
    });
  }

  function syncImageProviderOptions(root, hiddenSelector) {
    if (!root) {
      return "{}";
    }

    const hiddenField =
      root.querySelector(hiddenSelector || 'input[name="image_provider_options"]') ||
      root.elements?.image_provider_options;
    const serialized = JSON.stringify(collectImageProviderOptions(root));
    if (hiddenField) {
      hiddenField.value = serialized;
    }
    return serialized;
  }

  function getImageProviderNoticeMessage(providerKey) {
    if (isStockImageProvider(providerKey)) {
      return translate(
        "Connect the selected stock photo provider to generate images."
      );
    }
    return translate("Connect an AI provider to generate images.");
  }

  function syncGlobalImageProviderNotice(container, providerKey, shouldShow) {
    const providerNoticeTarget =
      container
        ?.querySelector("#aipkit_content_writer_provider")
        ?.getAttribute("data-aipkit-provider-notice-target") ||
      "aipkit_provider_notice_content_writer";

    if (
      !providerNoticeTarget ||
      typeof window.aipkit_setProviderNoticeOverride !== "function" ||
      typeof window.aipkit_clearProviderNoticeOverride !== "function"
    ) {
      return;
    }

    if (shouldShow) {
      const message = getImageProviderNoticeMessage(providerKey);
      if (message) {
        window.aipkit_setProviderNoticeOverride(providerNoticeTarget, {
          message,
          providerKey: normalizeProviderKey(providerKey),
        });
        return;
      }
    }

    window.aipkit_clearProviderNoticeOverride(providerNoticeTarget);
  }

  function buildImageSelectionValue(providerKey, modelId) {
    return `${encodeURIComponent(providerKey)}::${encodeURIComponent(
      modelId || ""
    )}`;
  }

  function setHidden(element, shouldHide) {
    if (!element) {
      return;
    }
    element.hidden = Boolean(shouldHide);
  }

  function setButtonDisabled(button, disabled) {
    if (!button) {
      return;
    }
    button.disabled = Boolean(disabled);
    button.setAttribute("aria-disabled", disabled ? "true" : "false");
  }

  function initializeImageUnifiedModelSelector(container) {
    const source = container?.querySelector(
      "#aipkit_content_writer_image_selection"
    );
    const selector = container?.querySelector(
      ".aipkit_cw_image_unified_model_selector"
    );
    if (
      !source ||
      !selector ||
      source.dataset.aipkitUnifiedImageBound === "true" ||
      typeof window.aipkit_createUnifiedModelSelector !== "function" ||
      typeof window.aipkit_createUnifiedModelSelectAdapter !== "function"
    ) {
      return;
    }

    const adapter = window.aipkit_createUnifiedModelSelectAdapter(source);
    const controller = window.aipkit_createUnifiedModelSelector(
      selector,
      adapter
    );
    if (!controller) {
      return;
    }

    source._aipkitUnifiedModelSync = () => controller.sync();
    source.dataset.aipkitUnifiedImageBound = "true";
    controller.sync();
  }

  function getImageSourceFamily(providerKey) {
    return isStockImageProvider(providerKey) ? "stock" : "ai";
  }

  let imageOptionsReturnFocus = null;

  function getImageOptionsModal(container) {
    return container?.querySelector("[data-aipkit-image-options-modal]") || null;
  }

  function getImageOptionsFocusableElements(modal) {
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

  function closeImageOptionsModal(container, options = {}) {
    const modal = getImageOptionsModal(container);
    if (!modal || !modal.classList.contains("aipkit-active")) {
      return;
    }

    modal.classList.remove("aipkit-active");
    modal.setAttribute("aria-hidden", "true");
    container
      .querySelector("[data-aipkit-image-options-trigger]")
      ?.setAttribute("aria-expanded", "false");

    if (
      options.returnFocus !== false &&
      imageOptionsReturnFocus?.isConnected
    ) {
      imageOptionsReturnFocus.focus();
    }
    imageOptionsReturnFocus = null;
  }

  function openImageOptionsModal(container) {
    const modal = getImageOptionsModal(container);
    const trigger = container?.querySelector(
      "[data-aipkit-image-options-trigger]"
    );
    if (!modal || !trigger || modal.classList.contains("aipkit-active")) {
      return;
    }

    imageOptionsReturnFocus = document.activeElement;
    modal.classList.add("aipkit-active");
    modal.setAttribute("aria-hidden", "false");
    trigger.setAttribute("aria-expanded", "true");

    window.setTimeout(() => {
      getImageOptionsFocusableElements(modal)[0]?.focus();
    }, 0);
  }

  function getImageModeFromFields(container) {
    const contentToggle = container.querySelector(
      "#aipkit_cw_generate_images_enabled"
    );
    const featuredToggle = container.querySelector(
      "#aipkit_cw_generate_featured_image"
    );
    const imageCountInput = container.querySelector("#aipkit_cw_image_count");
    const imageCount = Number.parseInt(imageCountInput?.value, 10);

    const contentEnabled =
      Boolean(contentToggle && contentToggle.checked) &&
      (!imageCountInput || !Number.isFinite(imageCount) || imageCount > 0);
    const featuredEnabled = Boolean(featuredToggle && featuredToggle.checked);

    if (contentEnabled && featuredEnabled) {
      return "both";
    }
    if (contentEnabled) {
      return "content";
    }
    if (featuredEnabled) {
      return "featured";
    }
    return "off";
  }

  function isProviderConfigured(providerKey) {
    const normalizedKey = normalizeProviderKey(providerKey);
    if (!normalizedKey) {
      return true;
    }
    const statusMap =
      window.aipkit_dashboard &&
      window.aipkit_dashboard.providerStatus &&
      typeof window.aipkit_dashboard.providerStatus === "object"
        ? window.aipkit_dashboard.providerStatus
        : null;
    if (
      !statusMap ||
      !Object.prototype.hasOwnProperty.call(statusMap, normalizedKey)
    ) {
      return true;
    }
    return Boolean(statusMap[normalizedKey]);
  }

  function getImageProviderEntries(providerSelect) {
    if (!providerSelect?.options) {
      return [];
    }

    const entries = [];
    Array.from(providerSelect.children).forEach((child) => {
      if (child.tagName === "OPTGROUP") {
        Array.from(child.children).forEach((option) => {
          if (!option.value || option.disabled) {
            return;
          }
          entries.push({
            key: normalizeProviderKey(option.value),
            label: option.textContent.trim(),
          });
        });
        return;
      }

      if (child.tagName === "OPTION" && child.value && !child.disabled) {
        entries.push({
          key: normalizeProviderKey(child.value),
          label: child.textContent.trim(),
        });
      }
    });

    return entries;
  }

  function findImageSelectionOption(select, providerKey, modelId) {
    if (!select?.options) {
      return null;
    }

    return (
      Array.from(select.options).find(
        (option) =>
          normalizeProviderKey(option.dataset.provider) ===
            normalizeProviderKey(providerKey) &&
          String(option.dataset.model || "") === String(modelId || "")
      ) || null
    );
  }

  function findFirstImageSelectionOptionForProvider(select, providerKey) {
    if (!select?.options) {
      return null;
    }

    return (
      Array.from(select.options).find(
        (option) =>
          normalizeProviderKey(option.dataset.provider) ===
          normalizeProviderKey(providerKey)
      ) || null
    );
  }

  function syncHiddenImageSelectionFields(selectionSelect, providerField, modelField) {
    if (!selectionSelect || !providerField || !modelField) {
      return { providerChanged: false, modelChanged: false };
    }

    const selectedOption =
      selectionSelect.selectedOptions && selectionSelect.selectedOptions.length
        ? selectionSelect.selectedOptions[0]
        : null;
    const nextProvider = normalizeProviderKey(
      selectedOption?.dataset?.provider || ""
    );
    const nextModel = String(selectedOption?.dataset?.model || "");
    const providerChanged =
      normalizeProviderKey(providerField.value) !== nextProvider;
    const modelChanged = String(modelField.value || "") !== nextModel;

    providerField.value = nextProvider;
    modelField.value = nextModel;

    return { providerChanged, modelChanged };
  }

  function populateImageModels(providerField, modelSelect) {
    if (!providerField || !modelSelect) {
      return;
    }

    const allModels = window.aipkit_dashboard?.imageGeneratorModels;
    if (!allModels) {
      console.error("Image models data not found in aipkit_dashboard object.");
      modelSelect.innerHTML =
        '<option value="">Error: Models not loaded</option>';
      return;
    }

    const providerKey = normalizeProviderKey(providerField.value);
    const modelsForProvider = allModels[providerKey] || [];
    const pendingModel =
      window.aipkit_cw_template_state?.pendingImageModelSelection || "";
    const currentModelValue = modelSelect.value;
    const valueToSet = pendingModel || currentModelValue;

    modelSelect.innerHTML = "";

    if (modelsForProvider.length > 0) {
      modelsForProvider.forEach((model) => {
        modelSelect.appendChild(new Option(model.name, model.id));
      });

      if (
        providerKey === "aipuffercloud" &&
        valueToSet &&
        !Array.from(modelSelect.options).some((option) => option.value === valueToSet)
      ) {
        modelSelect.appendChild(
          new Option(
            `${translate("Saved Cloud model unavailable", "gpt3-ai-content-generator")}: ${valueToSet}`,
            valueToSet
          )
        );
      }

      if (
        valueToSet &&
        modelSelect.querySelector(`option[value="${CSS.escape(valueToSet)}"]`)
      ) {
        modelSelect.value = valueToSet;
      } else if (modelSelect.options.length > 0) {
        modelSelect.selectedIndex = 0;
      }
    } else {
      const savedCloudModel = providerKey === "aipuffercloud" ? valueToSet : "";
      modelSelect.appendChild(
        new Option(
          savedCloudModel
            ? `${translate("Saved Cloud model unavailable", "gpt3-ai-content-generator")}: ${savedCloudModel}`
            : "",
          savedCloudModel
        )
      );
      modelSelect.value = savedCloudModel;
    }

    if (window.aipkit_cw_template_state) {
      window.aipkit_cw_template_state.pendingImageModelSelection = null;
    }
  }

  function populateCombinedImageSelection(container) {
    if (!container) {
      return;
    }

    const selectionSelect = container.querySelector(
      "#aipkit_content_writer_image_selection"
    );
    const providerField = container.querySelector("#aipkit_cw_image_provider");
    const modelField = container.querySelector("#aipkit_cw_image_model");
    if (!selectionSelect || !providerField || !modelField) {
      return;
    }

    const providerEntries = getImageProviderEntries(providerField);
    const allModels = window.aipkit_dashboard?.imageGeneratorModels || {};
    const currentProvider = normalizeProviderKey(providerField.value);
    const currentModel = String(modelField.value || "");
    const pendingModel =
      window.aipkit_cw_template_state?.pendingImageModelSelection || "";

    selectionSelect.innerHTML = "";

    let firstOption = null;

    providerEntries.forEach((providerEntry) => {
      const optgroup = document.createElement("optgroup");
      const category = getImageSourceFamily(providerEntry.key);
      optgroup.label =
        category === "stock"
          ? translate("Stock photos", "gpt3-ai-content-generator")
          : providerEntry.label;

      const modelItems = Array.isArray(allModels[providerEntry.key])
        ? allModels[providerEntry.key]
        : [];
      const savedCloudModel =
        providerEntry.key === "aipuffercloud" &&
        currentProvider === "aipuffercloud"
          ? pendingModel || currentModel
          : "";
      if (providerEntry.key === "aipuffercloud" && !modelItems.length && !savedCloudModel) {
        return;
      }
      const optionItems = modelItems.length
        ? modelItems.map((model) => ({
            id: String(model?.id || ""),
            label: String(model?.name || model?.id || providerEntry.label),
          }))
        : [
            {
              id: "",
              label: providerEntry.label,
            },
          ];

      if (
        savedCloudModel &&
        !optionItems.some((item) => item.id === savedCloudModel)
      ) {
        optionItems.push({
          id: savedCloudModel,
          label: `${translate("Saved Cloud model unavailable", "gpt3-ai-content-generator")}: ${savedCloudModel}`,
        });
      }

      optionItems.forEach((item) => {
        const option = new Option(
          item.label,
          buildImageSelectionValue(providerEntry.key, item.id)
        );
        option.dataset.provider = providerEntry.key;
        option.dataset.model = item.id;
        option.dataset.providerLabel = providerEntry.label;
        option.dataset.category = category;
        if (category === "stock") {
          option.dataset.aipkitPickerProvider = "stock_photos";
          option.dataset.aipkitPickerProviderLabel = translate(
            "Stock Photos",
            "gpt3-ai-content-generator"
          );
          option.dataset.aipkitModelPickerValue = `stock:${providerEntry.key}`;
          option.dataset.groupLabel = translate(
            "Stock Photos",
            "gpt3-ai-content-generator"
          );
        }
        optgroup.appendChild(option);
        if (!firstOption) {
          firstOption = option;
        }
      });

      selectionSelect.appendChild(optgroup);
    });

    if (!selectionSelect.options.length) {
      selectionSelect.appendChild(
        new Option(
          translate("No image sources available", "gpt3-ai-content-generator"),
          ""
        )
      );
      selectionSelect.disabled = true;
      providerField.value = "";
      modelField.value = "";
      return;
    }

    selectionSelect.disabled = false;

    const preferredOption =
      (pendingModel &&
        findImageSelectionOption(
          selectionSelect,
          currentProvider,
          pendingModel
        )) ||
      (currentModel &&
        findImageSelectionOption(
          selectionSelect,
          currentProvider,
          currentModel
        )) ||
      findFirstImageSelectionOptionForProvider(
        selectionSelect,
        currentProvider
      ) ||
      firstOption;

    if (preferredOption) {
      selectionSelect.value = preferredOption.value;
    }

    syncHiddenImageSelectionFields(selectionSelect, providerField, modelField);
    selectionSelect._aipkitUnifiedModelSync?.();

    if (typeof window.aipkit_refreshContentWriterSelectPickers === "function") {
      window.aipkit_refreshContentWriterSelectPickers();
    }
  }

  function refreshImageSourceSelection(container) {
    if (!container) {
      return;
    }

    const providerField = container.querySelector("#aipkit_cw_image_provider");
    const modelField = container.querySelector("#aipkit_cw_image_model");
    const selectionSelect = container.querySelector(
      "#aipkit_content_writer_image_selection"
    );

    if (!providerField || !modelField || !selectionSelect) {
      return;
    }

    populateImageModels(providerField, modelField);

    const nextState = `${normalizeProviderKey(providerField.value)}::${String(
      modelField.value || ""
    )}`;
    const currentState = container.dataset.aipkitImageSourceSelectionState || "";

    if (
      currentState === nextState &&
      selectionSelect.options &&
      selectionSelect.options.length > 0
    ) {
      return;
    }

    populateCombinedImageSelection(container);
    container.dataset.aipkitImageSourceSelectionState = nextState;
  }

  function aipkit_updateImageSettingsUI() {
    const container = document.getElementById("aipkit_content_writer_container");
    if (!container) {
      return;
    }

    normalizeStockImageOptionControls(container);

    const form = document.getElementById("aipkit_content_writer_form");
    const isExistingImages = form
      ? form.classList.contains("aipkit_cw_mode_existing_images")
      : false;
    const isExistingContent = form
      ? form.classList.contains("aipkit_cw_mode_existing_content")
      : false;
    const isExistingProducts = form
      ? form.classList.contains("aipkit_cw_mode_existing_products")
      : false;

    const providerSelect = container.querySelector("#aipkit_cw_image_provider");
    const modelSelect = container.querySelector("#aipkit_cw_image_model");
    if (providerSelect && modelSelect) {
      initializeImageUnifiedModelSelector(container);
      refreshImageSourceSelection(container);
    }

    const mode = getImageModeFromFields(container);
    const contentEnabled = mode === "content" || mode === "both";
    const featuredEnabled = mode === "featured" || mode === "both";
    const imagesEnabled = mode !== "off";

    const settingsContainer = container.querySelector(
      "[data-aipkit-cw-image-settings]"
    );
    const imageSourceRow = container.querySelector(
      "[data-aipkit-cw-image-source-row]"
    );
    const imageOptionsTrigger = container.querySelector(
      "[data-aipkit-image-options-trigger]"
    );
    const imagePromptsItem = container.querySelector(
      "#aipkit_cw_image_prompts_prompt_item"
    );
    const imagePromptsToggle = imagePromptsItem?.querySelector(
      "[data-aipkit-cw-image-prompts-toggle]"
    );
    const imagePromptsPanel = imagePromptsItem?.querySelector(
      "[data-aipkit-cw-image-prompts-panel]"
    );
    const imagePromptMainBlock = container.querySelector(
      "#aipkit_cw_image_prompt_main_block"
    );
    const providerOptionsBlock = container.querySelector(
      "#aipkit_cw_image_provider_options_block"
    );
    const pexelsOptions = container.querySelector("#aipkit_cw_pexels_options");
    const pixabayOptions = container.querySelector("#aipkit_cw_pixabay_options");
    const metadataBlock = container.querySelector("#aipkit_cw_image_metadata_block");
    const imagePromptField = container.querySelector("#aipkit_cw_image_prompt_field");
    const featuredImagePromptField = container.querySelector(
      "#aipkit_cw_featured_image_prompt_field"
    );
    const contentPanel = container.querySelector(
      "[data-aipkit-image-content-panel]"
    );
    const placementSelect = container.querySelector("#aipkit_cw_image_placement");
    const placementXField = container.querySelector(
      "#aipkit_cw_image_display_param_x_field"
    );
    const selectedProvider = providerSelect
      ? normalizeProviderKey(providerSelect.value)
      : "";
    const selectedImageModel = modelSelect ? String(modelSelect.value || "") : "";
    const isStockProvider = isStockImageProvider(selectedProvider);
    const selectedProviderOptionsBlock = getProviderOptionBlock(
      container,
      selectedProvider
    );
    const selectedProviderOptionsSupported = isImageProviderOptionsSupported(
      selectedProvider,
      selectedImageModel
    );
    const supportsPromptSectionImageControls =
      !isExistingContent && !isExistingProducts;

    const sourceVisible =
      supportsPromptSectionImageControls && !isExistingImages;
    const generalVisible = sourceVisible && imagesEnabled;
    const metadataVisible =
      isExistingImages ||
      (supportsPromptSectionImageControls && imagesEnabled);
    const showImageProviderNotice =
      generalVisible &&
      (isAiImageProvider(selectedProvider) ||
        isStockImageProvider(selectedProvider)) &&
      !isProviderConfigured(selectedProvider);

    const showDisplaySettings = sourceVisible && contentEnabled;
    const showAiProviderSettings =
      generalVisible &&
      Boolean(selectedProviderOptionsBlock) &&
      selectedProviderOptionsSupported;
    const showStockProviderSettings =
      generalVisible &&
      (selectedProvider === "pexels" || selectedProvider === "pixabay");
    const showProviderSettings =
      showAiProviderSettings || showStockProviderSettings;
    const showMetadataSettings =
      metadataVisible && (isExistingImages || !isStockProvider);
    const showImagePrompts = showMetadataSettings;
    const showImagePromptMain = sourceVisible;

    setHidden(settingsContainer, !(sourceVisible || showImagePrompts));
    setHidden(imageSourceRow, !sourceVisible);
    setHidden(imageOptionsTrigger, !imagesEnabled);
    if (!imagesEnabled) {
      closeImageOptionsModal(container, { restoreFocus: false });
    }
    setHidden(imagePromptsItem, !showImagePrompts);
    setHidden(imagePromptMainBlock, !showImagePromptMain);
    setHidden(metadataBlock, !showMetadataSettings);
    const wasFlatImagePrompts = Boolean(
      imagePromptsItem?.classList.contains("is-flat-fields")
    );
    imagePromptsItem?.classList.toggle("is-flat-fields", isExistingImages);
    if (imagePromptsToggle) {
      imagePromptsToggle.hidden = isExistingImages;
    }
    if (isExistingImages && imagePromptsPanel) {
      imagePromptsPanel.hidden = false;
      imagePromptsToggle?.setAttribute("aria-expanded", "true");
      imagePromptsItem?.classList.add("is-group-open");
    } else if (wasFlatImagePrompts && imagePromptsPanel) {
      imagePromptsPanel.hidden = true;
      imagePromptsToggle?.setAttribute("aria-expanded", "false");
      imagePromptsItem?.classList.remove("is-group-open");
      window.aipkit_closeContentWriterInlinePromptEditor?.();
    }
    setHidden(providerOptionsBlock, !showProviderSettings);
    getProviderOptionBlocks(container).forEach((block) => {
      setHidden(
        block,
        !(
          showAiProviderSettings &&
          normalizeProviderKey(block.dataset.aipkitImageProviderOptions) ===
            selectedProvider
        )
      );
    });
    setHidden(
      pexelsOptions,
      !(showStockProviderSettings && selectedProvider === "pexels")
    );
    setHidden(
      pixabayOptions,
      !(showStockProviderSettings && selectedProvider === "pixabay")
    );
    updateImageProviderOptionRows(
      container,
      selectedProvider,
      selectedImageModel
    );
    syncImageProviderOptions(container);
    syncGlobalImageProviderNotice(
      container,
      selectedProvider,
      showImageProviderNotice
    );

    setHidden(contentPanel, !showDisplaySettings);
    setHidden(imagePromptField, !sourceVisible);
    setHidden(featuredImagePromptField, !sourceVisible);
    if (imagePromptField) {
      imagePromptField.dataset.aipkitPromptInstructionsAvailable = isStockProvider
        ? "false"
        : "true";
    }
    if (featuredImagePromptField) {
      featuredImagePromptField.dataset.aipkitPromptInstructionsAvailable =
        isStockProvider ? "false" : "true";
    }

    const placementRequiresX =
      placementSelect &&
      ["after_every_x_h2", "after_every_x_h3", "after_every_x_p"].includes(
        placementSelect.value
      );
    const placementXInput = placementXField?.querySelector(
      "#aipkit_cw_image_placement_param_x"
    );
    if (placementRequiresX && placementXInput) {
      const minimum = Number.parseInt(placementXInput.min, 10);
      const current = Number.parseInt(placementXInput.value, 10);
      const normalizedMinimum = Number.isFinite(minimum) ? minimum : 1;
      if (!Number.isFinite(current) || current < normalizedMinimum) {
        placementXInput.value = String(normalizedMinimum);
      }
    }
    setHidden(
      placementXField,
      !(showDisplaySettings && placementRequiresX)
    );

    Array.from(
      container.querySelectorAll(".aipkit_cw_image_metadata_subtoggle")
    ).forEach((toggle) => {
      const promptButton = toggle
        .closest(".aipkit_cw_prompt_field")
        ?.querySelector(".aipkit_cw_image_metadata_prompt_btn");
      const enabled = showMetadataSettings && toggle.checked;
      setButtonDisabled(promptButton, !enabled);
    });

    if (typeof window.aipkit_syncContentWriterInlinePrompts === "function") {
      window.aipkit_syncContentWriterInlinePrompts();
    }

    if (
      typeof window.aipkit_refreshContentWriterSelectWidths === "function"
    ) {
      window.aipkit_refreshContentWriterSelectWidths(container);
    }
  }

  function aipkit_initImageSettingsHandler() {
    const container = document.getElementById("aipkit_content_writer_container");
    if (!container || container.dataset.imageHandlerAttached === "true") {
      return;
    }

    container.addEventListener("click", (event) => {
      const stepButton = event.target.closest(
        "[data-aipkit-image-count-step], [data-aipkit-image-placement-step]"
      );
      if (stepButton && container.contains(stepButton)) {
        const isPlacementStep = stepButton.hasAttribute(
          "data-aipkit-image-placement-step"
        );
        const input = container.querySelector(
          isPlacementStep
            ? "#aipkit_cw_image_placement_param_x"
            : "#aipkit_cw_image_count"
        );
        if (!input) {
          return;
        }
        const step = Number.parseInt(
          isPlacementStep
            ? stepButton.dataset.aipkitImagePlacementStep
            : stepButton.dataset.aipkitImageCountStep,
          10
        );
        const minimum = Number.parseInt(input.min, 10);
        const maximum = Number.parseInt(input.max, 10);
        const current = Number.parseInt(input.value, 10);
        const nextValue = Math.min(
          Number.isFinite(maximum) ? maximum : Number.POSITIVE_INFINITY,
          Math.max(
            Number.isFinite(minimum) ? minimum : 1,
            (Number.isFinite(current) ? current : 1) +
              (Number.isFinite(step) ? step : 0)
          )
        );
        if (String(nextValue) !== input.value) {
          input.value = String(nextValue);
          input.dispatchEvent(new Event("change", { bubbles: true }));
        }
        return;
      }

      const imageOptionsTrigger = event.target.closest(
        "[data-aipkit-image-options-trigger]"
      );
      if (imageOptionsTrigger && container.contains(imageOptionsTrigger)) {
        event.preventDefault();
        openImageOptionsModal(container);
        return;
      }

      const imageOptionsClose = event.target.closest(
        "[data-aipkit-image-options-close]"
      );
      if (imageOptionsClose && container.contains(imageOptionsClose)) {
        event.preventDefault();
        closeImageOptionsModal(container);
        return;
      }

      const imageOptionsModal = getImageOptionsModal(container);
      if (event.target === imageOptionsModal) {
        closeImageOptionsModal(container);
      }
    });

    container.addEventListener("change", (event) => {
      const target = event.target;
      if (!target) {
        return;
      }

      if (target.matches("#aipkit_content_writer_image_selection")) {
        const providerField = container.querySelector("#aipkit_cw_image_provider");
        const modelField = container.querySelector("#aipkit_cw_image_model");
        const selectedImageOption = target.selectedOptions?.[0];
        const selectedImageProvider = normalizeProviderKey(
          selectedImageOption?.dataset?.provider || ""
        );
        const selectedImageModel = String(
          selectedImageOption?.dataset?.model || ""
        );
        if (
          providerField &&
          normalizeProviderKey(providerField.value) !== selectedImageProvider &&
          window.aipkit_cw_template_state
        ) {
          window.aipkit_cw_template_state.pendingImageModelSelection =
            selectedImageModel;
        }
        const {
          providerChanged,
          modelChanged,
        } = syncHiddenImageSelectionFields(target, providerField, modelField);

        if (providerChanged && providerField) {
          providerField.dispatchEvent(new Event("change", { bubbles: true }));
        }
        if (modelChanged && modelField) {
          modelField.dispatchEvent(new Event("change", { bubbles: true }));
        }

        aipkit_updateImageSettingsUI();
        return;
      }

      if (
        target.matches(
            "#aipkit_cw_generate_images_enabled, " +
            "#aipkit_cw_generate_featured_image, " +
            "#aipkit_cw_image_count, " +
            "#aipkit_cw_image_provider, " +
            "#aipkit_cw_image_model, " +
            "#aipkit_cw_image_placement, " +
            ".aipkit_cw_image_metadata_subtoggle, " +
            "[data-aipkit-image-provider-option]"
        )
      ) {
        aipkit_updateImageSettingsUI();
        return;
      }

    });

    container.addEventListener(
      "invalid",
      (event) => {
        if (event.target.closest("[data-aipkit-image-options-modal]")) {
          openImageOptionsModal(container);
        }
      },
      true
    );

    document.addEventListener("keydown", (event) => {
      const imageOptionsModal = getImageOptionsModal(container);
      if (!imageOptionsModal?.classList.contains("aipkit-active")) {
        return;
      }

      if (event.key === "Escape") {
        event.preventDefault();
        closeImageOptionsModal(container);
        return;
      }

      if (event.key !== "Tab") {
        return;
      }

      const focusableElements =
        getImageOptionsFocusableElements(imageOptionsModal);
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

    initializeImageUnifiedModelSelector(container);
    aipkit_updateImageSettingsUI();
    container.dataset.imageHandlerAttached = "true";
  }

  window.aipkit_getImageProviderEntries = getImageProviderEntries;
  window.aipkit_buildImageSelectionValue = buildImageSelectionValue;
  window.aipkit_findImageSelectionOption = findImageSelectionOption;
  window.aipkit_findFirstImageSelectionOptionForProvider =
    findFirstImageSelectionOptionForProvider;
  window.aipkit_syncHiddenImageSelectionFields =
    syncHiddenImageSelectionFields;
  window.aipkit_populateImageModels = populateImageModels;
  window.aipkit_isImageProviderOptionsSupported =
    isImageProviderOptionsSupported;
  window.aipkit_updateImageProviderOptionRows = updateImageProviderOptionRows;
  window.aipkit_applyImageProviderOptions = applyImageProviderOptions;
  window.aipkit_syncImageProviderOptions = syncImageProviderOptions;
  window.aipkit_normalizeStockImageOptionControls =
    normalizeStockImageOptionControls;
  window.aipkit_initImageSettingsHandler = aipkit_initImageSettingsHandler;
  window.aipkit_updateImageSettingsUI = aipkit_updateImageSettingsUI;
  window.aipkit_refreshContentWriterImageSelection = function () {
    const container = document.getElementById("aipkit_content_writer_container");
    if (!container) {
      return;
    }
    container.dataset.aipkitImageSourceSelectionState = "";
    refreshImageSourceSelection(container);
  };
})();
