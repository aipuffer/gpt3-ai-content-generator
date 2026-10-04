(function () {
  "use strict";

  const wp = window.wp;
  if (
    !wp ||
    !wp.blocks ||
    !wp.blockEditor ||
    !wp.components ||
    !wp.element ||
    !wp.i18n
  ) {
    return;
  }

  const { registerBlockType } = wp.blocks;
  const { InspectorControls, useBlockProps } = wp.blockEditor;
  const { PanelBody, Placeholder, SelectControl, ToggleControl, Notice } =
    wp.components;
  const { createElement: el, Fragment } = wp.element;
  const { __, sprintf } = wp.i18n;

  const config = window.aipkit_blocks_data || {};
  const chatbotItems = Array.isArray(config.chatbots) ? config.chatbots : [];
  const formItems = Array.isArray(config.forms) ? config.forms : [];
  const access = config.access || {};
  const modules = config.modules || {};
  const isProPlan = Boolean(config.isProPlan);

  function renderAiPufferIcon(size = 24) {
    return el("img", {
      src: config.logoUrl,
      width: size,
      height: size,
      alt: "",
      "aria-hidden": true,
      style: { display: "block", objectFit: "contain" },
    });
  }

  function findItem(items, id) {
    const numericId = Number(id || 0);
    return items.find((item) => Number(item.id) === numericId) || null;
  }

  function buildEntityOptions(items, emptyLabel) {
    const options = [{ label: emptyLabel, value: "0" }];

    items.forEach((item) => {
      if (!item || !item.id) {
        return;
      }

      let label = item.title || String(item.id);
      if (item.status === "draft") {
        label = sprintf(
          /* translators: %s: item title */
          __("%s (Draft)", "gpt3-ai-content-generator"),
          label
        );
      }

      options.push({
        label,
        value: String(item.id),
      });
    });

    return options;
  }

  function renderAccessNotice(moduleKey) {
    if (access[moduleKey] !== false) {
      return null;
    }

    return el(
      Notice,
      {
        status: "warning",
        isDismissible: false,
      },
      __("You do not currently have access to this module.", "gpt3-ai-content-generator")
    );
  }

  function renderCodeSnippet(shortcode) {
    if (!shortcode) {
      return null;
    }

    return el("code", null, shortcode);
  }

  registerBlockType("aipkit/chatbot", {
    apiVersion: 3,
    title: __("AI Puffer Chatbot", "gpt3-ai-content-generator"),
    description: __("Embed one of your AI Puffer chatbots.", "gpt3-ai-content-generator"),
    category: "widgets",
    icon: renderAiPufferIcon(),
    attributes: {
      botId: {
        type: "number",
        default: 0,
      },
    },
    edit: function EditChatbotBlock({ attributes, setAttributes }) {
      const blockProps = useBlockProps();
      const selectedBot = findItem(chatbotItems, attributes.botId);
      const options = buildEntityOptions(
        chatbotItems,
        __("Select a chatbot", "gpt3-ai-content-generator")
      );

      return el(
        Fragment,
        null,
        el(
          InspectorControls,
          null,
          el(
            PanelBody,
            {
              title: __("Chatbot", "gpt3-ai-content-generator"),
              initialOpen: true,
            },
            el(SelectControl, {
              label: __("Chatbot", "gpt3-ai-content-generator"),
              value: String(attributes.botId || 0),
              options,
              onChange: (value) => {
                setAttributes({ botId: Number.parseInt(value, 10) || 0 });
              },
            }),
            access.chatbot === false
              ? el(
                  Notice,
                  { status: "warning", isDismissible: false },
                  __("Chatbot access is disabled for your role.", "gpt3-ai-content-generator")
                )
              : null
          )
        ),
        el(
          "div",
          blockProps,
          el(
            Placeholder,
            {
              icon: renderAiPufferIcon(),
              label: __("AI Puffer Chatbot", "gpt3-ai-content-generator"),
              instructions: __(
                "Choose which chatbot should appear on the frontend.",
                "gpt3-ai-content-generator"
              ),
            },
            renderAccessNotice("chatbot"),
            selectedBot
              ? el(
                  "p",
                  null,
                  sprintf(
                    /* translators: %s: chatbot title */
                    __("Selected chatbot: %s", "gpt3-ai-content-generator"),
                    selectedBot.title
                  )
                )
              : el(
                  "p",
                  null,
                  chatbotItems.length
                    ? __("Select a chatbot in the block settings.", "gpt3-ai-content-generator")
                    : __("No chatbots are currently available.", "gpt3-ai-content-generator")
                ),
            renderCodeSnippet(
              attributes.botId
                ? `[aipkit_chatbot id=${attributes.botId}]`
                : ""
            )
          )
        )
      );
    },
    save: function () {
      return null;
    },
  });

  registerBlockType("aipkit/ai-form", {
    apiVersion: 3,
    title: __("AI Puffer AI Form", "gpt3-ai-content-generator"),
    description: __("Embed one of your AI Puffer AI forms.", "gpt3-ai-content-generator"),
    category: "widgets",
    icon: renderAiPufferIcon(),
    attributes: {
      formId: {
        type: "number",
        default: 0,
      },
      theme: {
        type: "string",
        default: "light",
      },
      showProvider: {
        type: "boolean",
        default: false,
      },
      showModel: {
        type: "boolean",
        default: false,
      },
      saveButton: {
        type: "boolean",
        default: false,
      },
      pdfDownload: {
        type: "boolean",
        default: false,
      },
      copyButton: {
        type: "boolean",
        default: false,
      },
    },
    edit: function EditAiFormBlock({ attributes, setAttributes }) {
      const blockProps = useBlockProps();
      const selectedForm = findItem(formItems, attributes.formId);
      const options = buildEntityOptions(
        formItems,
        __("Select an AI form", "gpt3-ai-content-generator")
      );
      const shortcodeParts = [];

      if (attributes.formId) {
        shortcodeParts.push(`[aipkit_ai_form id=${attributes.formId}`);
        if (attributes.showProvider) {
          shortcodeParts.push(' show_provider="true"');
        }
        if (attributes.showModel) {
          shortcodeParts.push(' show_model="true"');
        }
        if (attributes.copyButton) {
          shortcodeParts.push(' copy_button="true"');
        }
        if (attributes.saveButton) {
          shortcodeParts.push(' save_button="true"');
        }
        if (attributes.pdfDownload) {
          shortcodeParts.push(' pdf_download="true"');
        }
        if (attributes.theme && attributes.theme !== "light") {
          shortcodeParts.push(` theme="${attributes.theme}"`);
        }
        shortcodeParts.push("]");
      }

      return el(
        Fragment,
        null,
        el(
          InspectorControls,
          null,
          el(
            PanelBody,
            {
              title: __("AI Form", "gpt3-ai-content-generator"),
              initialOpen: true,
            },
            el(SelectControl, {
              label: __("Form", "gpt3-ai-content-generator"),
              value: String(attributes.formId || 0),
              options,
              onChange: (value) => {
                setAttributes({ formId: Number.parseInt(value, 10) || 0 });
              },
            }),
            el(ToggleControl, {
              label: __("Show provider select", "gpt3-ai-content-generator"),
              checked: Boolean(attributes.showProvider),
              onChange: (value) => setAttributes({ showProvider: value }),
            }),
            el(ToggleControl, {
              label: __("Show model select", "gpt3-ai-content-generator"),
              checked: Boolean(attributes.showModel),
              onChange: (value) => setAttributes({ showModel: value }),
            }),
            el(ToggleControl, {
              label: __("Show copy button", "gpt3-ai-content-generator"),
              checked: Boolean(attributes.copyButton),
              onChange: (value) => setAttributes({ copyButton: value }),
            }),
            el(ToggleControl, {
              label: __("Show save-as-post button", "gpt3-ai-content-generator"),
              checked: Boolean(attributes.saveButton),
              onChange: (value) => setAttributes({ saveButton: value }),
            }),
            el(ToggleControl, {
              label: __("Enable PDF download", "gpt3-ai-content-generator"),
              checked: Boolean(attributes.pdfDownload),
              disabled: !isProPlan,
              help: !isProPlan
                ? __("PDF download requires the Pro plan.", "gpt3-ai-content-generator")
                : undefined,
              onChange: (value) => setAttributes({ pdfDownload: value }),
            }),
            el(SelectControl, {
              label: __("Theme", "gpt3-ai-content-generator"),
              value: attributes.theme || "light",
              options: [
                {
                  label: __("Light (default)", "gpt3-ai-content-generator"),
                  value: "light",
                },
                {
                  label: __("Dark", "gpt3-ai-content-generator"),
                  value: "dark",
                },
                {
                  label: __("Custom", "gpt3-ai-content-generator"),
                  value: "custom",
                },
              ],
              onChange: (value) => setAttributes({ theme: value || "light" }),
            }),
            access.aiForms === false
              ? el(
                  Notice,
                  { status: "warning", isDismissible: false },
                  __("AI Forms access is disabled for your role.", "gpt3-ai-content-generator")
                )
              : null
          )
        ),
        el(
          "div",
          blockProps,
          el(
            Placeholder,
            {
              icon: renderAiPufferIcon(),
              label: __("AI Puffer AI Form", "gpt3-ai-content-generator"),
              instructions: __(
                "Choose which AI form to embed and configure its frontend controls.",
                "gpt3-ai-content-generator"
              ),
            },
            renderAccessNotice("aiForms"),
            selectedForm
              ? el(
                  "p",
                  null,
                  sprintf(
                    /* translators: %s: AI form title */
                    __("Selected form: %s", "gpt3-ai-content-generator"),
                    selectedForm.title
                  )
                )
              : el(
                  "p",
                  null,
                  formItems.length
                    ? __("Select an AI form in the block settings.", "gpt3-ai-content-generator")
                    : __("No published AI forms are currently available.", "gpt3-ai-content-generator")
                ),
            renderCodeSnippet(shortcodeParts.join(""))
          )
        )
      );
    },
    save: function () {
      return null;
    },
  });

  registerBlockType("aipkit/image-generator", {
    apiVersion: 3,
    title: __("AI Puffer Image Generator", "gpt3-ai-content-generator"),
    description: __("Embed the AI Puffer image generator UI.", "gpt3-ai-content-generator"),
    category: "widgets",
    icon: renderAiPufferIcon(),
    attributes: {
      allowModelSelection: {
        type: "boolean",
      },
      showProvider: {
        type: "boolean",
        default: true,
      },
      showModel: {
        type: "boolean",
        default: true,
      },
      history: {
        type: "boolean",
        default: false,
      },
      mode: {
        type: "string",
        default: "generate",
      },
      defaultMode: {
        type: "string",
        default: "generate",
      },
      showModeSwitch: {
        type: "boolean",
        default: true,
      },
      theme: {
        type: "string",
        default: "light",
      },
      font: {
        type: "string",
        default: "system",
      },
    },
    edit: function EditImageGeneratorBlock({ attributes, setAttributes }) {
      const blockProps = useBlockProps();
      const hasModelSelectionSetting =
        typeof attributes.allowModelSelection === "boolean";
      const allowModelSelection = hasModelSelectionSetting
        ? attributes.allowModelSelection
        : Boolean(attributes.showProvider || attributes.showModel);
      let shortcode = "[aipkit_image_generator";

      if (hasModelSelectionSetting) {
        if (!allowModelSelection) {
          shortcode += ' show_provider="false" show_model="false"';
        }
      } else {
        if (!attributes.showProvider) {
          shortcode += ' show_provider="false"';
        }
        if (!attributes.showModel) {
          shortcode += ' show_model="false"';
        }
      }
      if (attributes.history) {
        shortcode += ' history="true"';
      }
      if (attributes.mode && attributes.mode !== "generate") {
        shortcode += ` mode="${attributes.mode}"`;
      }
      if (
        attributes.mode === "both" &&
        attributes.defaultMode === "edit"
      ) {
        shortcode += ' default_mode="edit"';
      }
      if (
        attributes.mode === "both" &&
        attributes.showModeSwitch === false
      ) {
        shortcode += ' show_mode_switch="false"';
      }
      if (attributes.theme && attributes.theme !== "light") {
        shortcode += ` theme="${attributes.theme}"`;
      }
      if (attributes.font && attributes.font !== "system") {
        shortcode += ` font="${attributes.font}"`;
      }
      shortcode += "]";

      return el(
        Fragment,
        null,
        el(
          InspectorControls,
          null,
          el(
            PanelBody,
            {
              title: __("Image Generator", "gpt3-ai-content-generator"),
              initialOpen: true,
            },
            el(ToggleControl, {
              label: __("Allow visitor model selection", "gpt3-ai-content-generator"),
              help: __(
                "Visitors can choose from models enabled under Frontend Models.",
                "gpt3-ai-content-generator"
              ),
              checked: Boolean(allowModelSelection),
              onChange: (value) =>
                setAttributes({ allowModelSelection: Boolean(value) }),
            }),
            el(ToggleControl, {
              label: __("Show logged-in user history", "gpt3-ai-content-generator"),
              checked: Boolean(attributes.history),
              onChange: (value) => setAttributes({ history: value }),
            }),
            el(SelectControl, {
              label: __("Available actions", "gpt3-ai-content-generator"),
              help: __(
                "Choose what visitors can do.",
                "gpt3-ai-content-generator"
              ),
              value: attributes.mode || "generate",
              options: [
                {
                  label: __("Generate images", "gpt3-ai-content-generator"),
                  value: "generate",
                },
                {
                  label: __("Edit images", "gpt3-ai-content-generator"),
                  value: "edit",
                },
                {
                  label: __("Generate and edit", "gpt3-ai-content-generator"),
                  value: "both",
                },
              ],
              onChange: (value) =>
                setAttributes({ mode: value || "generate" }),
            }),
            el(SelectControl, {
              label: __("Theme", "gpt3-ai-content-generator"),
              value: attributes.theme || "light",
              options: [
                {
                  label: __("Light (default)", "gpt3-ai-content-generator"),
                  value: "light",
                },
                {
                  label: __("Dark", "gpt3-ai-content-generator"),
                  value: "dark",
                },
                {
                  label: __("Custom", "gpt3-ai-content-generator"),
                  value: "custom",
                },
              ],
              onChange: (value) => setAttributes({ theme: value || "light" }),
            }),
            el(SelectControl, {
              label: __("Font", "gpt3-ai-content-generator"),
              value: attributes.font || "system",
              options: [
                {
                  label: __("System UI (default)", "gpt3-ai-content-generator"),
                  value: "system",
                },
                {
                  label: __("Match site theme", "gpt3-ai-content-generator"),
                  value: "theme",
                },
              ],
              onChange: (value) => setAttributes({ font: value || "system" }),
            }),
            access.imageGenerator === false
              ? el(
                  Notice,
                  { status: "warning", isDismissible: false },
                  __("Image Generator access is disabled for your role.", "gpt3-ai-content-generator")
                )
              : null,
            modules.imageGeneratorEnabled === false
              ? el(
                  Notice,
                  { status: "warning", isDismissible: false },
                  __("The Image Generator module is currently disabled in plugin settings.", "gpt3-ai-content-generator")
                )
              : null
          )
        ),
        el(
          "div",
          blockProps,
          el(
            Placeholder,
            {
              icon: renderAiPufferIcon(),
              label: __("AI Puffer Image Generator", "gpt3-ai-content-generator"),
              instructions: __(
                "Configure which image generator controls should appear on the frontend.",
                "gpt3-ai-content-generator"
              ),
            },
            renderAccessNotice("imageGenerator"),
            modules.imageGeneratorEnabled === false
              ? el(
                  Notice,
                  { status: "warning", isDismissible: false },
                  __("This block will not render until the Image Generator module is enabled.", "gpt3-ai-content-generator")
                )
              : null,
            el(
              "p",
              null,
              sprintf(
                /* translators: 1: selected mode, 2: theme, 3: font mode */
                __("Mode: %1$s. Theme: %2$s. Font: %3$s.", "gpt3-ai-content-generator"),
                attributes.mode || "generate",
                attributes.theme || "light",
                attributes.font || "system"
              )
            ),
            renderCodeSnippet(shortcode)
          )
        )
      );
    },
    save: function () {
      return null;
    },
  });
})();
