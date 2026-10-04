/** AI Forms delegated actions and one-off shortcode options. */
import { getFixedDropdownPanelMetrics } from "../utils/ui/popover-position.js";

(function () {
  "use strict";

  let activeActionsMenu = null;

  const closeActionsMenu = (menuItem, resetStyle = true) => {
    const wrapper = menuItem?.closest(".aipkit_actions_menu");
    const menu = menuItem?.matches(".aipkit_actions_dropdown_menu")
      ? menuItem : wrapper?.querySelector(".aipkit_actions_dropdown_menu");
    if (menu) {
      if (resetStyle) menu.removeAttribute("style");
      else menu.style.display = "none";
    }
    wrapper?.classList.remove("is-open");
    wrapper?.querySelector(".aipkit_actions_menu_toggle")?.setAttribute("aria-expanded", "false");
    if (wrapper === activeActionsMenu) {
      activeActionsMenu = null;
      document.removeEventListener("click", dismissActionsMenu, true);
    }
  };

  const dismissActionsMenu = (event) => {
    if (activeActionsMenu && !activeActionsMenu.contains(event.target)) {
      closeActionsMenu(activeActionsMenu, false);
    }
  };

  let activeShortcodeConfigurator = null;
  let activeShortcodeTrigger = null;

  const positionShortcodeConfigurator = () => {
    if (!activeShortcodeConfigurator) return;
    if (!activeShortcodeConfigurator.isConnected || !activeShortcodeTrigger?.isConnected) {
      closeShortcodeConfigurator();
      return;
    }

    const panelWidth =
      activeShortcodeConfigurator.getBoundingClientRect().width || 348;
    const metrics = getFixedDropdownPanelMetrics(
      activeShortcodeTrigger,
      activeShortcodeConfigurator,
      panelWidth,
      { verticalGap: 8 }
    );
    if (!metrics) return;

    activeShortcodeConfigurator.style.left = `${Math.round(metrics.left)}px`;
    activeShortcodeConfigurator.style.top = `${Math.round(metrics.top)}px`;
    const triggerRect = activeShortcodeTrigger.getBoundingClientRect();
    const panelRect = activeShortcodeConfigurator.getBoundingClientRect();
    const caretLeft = Math.max(
      18,
      Math.min(
        panelRect.width - 18,
        triggerRect.left + triggerRect.width / 2 - panelRect.left
      )
    );
    activeShortcodeConfigurator.style.setProperty(
      "--aipkit-shortcode-caret-left",
      `${Math.round(caretLeft)}px`
    );
    activeShortcodeConfigurator.dataset.placement =
      metrics.shouldDropUp ? "top" : "bottom";
  };

  const closeShortcodeConfigurator = ({ restoreFocus = false } = {}) => {
    if (!activeShortcodeConfigurator) return;

    setShortcodeListeners(false);
    const row = activeShortcodeConfigurator.closest("tr");
    activeShortcodeConfigurator.style.display = "none";
    activeShortcodeConfigurator.style.removeProperty("visibility");
    activeShortcodeConfigurator.setAttribute("aria-hidden", "true");
    row?.classList.remove("aipkit_shortcode_row_open");

    if (activeShortcodeTrigger) {
      activeShortcodeTrigger.setAttribute("aria-expanded", "false");
      if (restoreFocus && activeShortcodeTrigger.isConnected) {
        activeShortcodeTrigger.focus();
      }
    }
    activeShortcodeConfigurator = null;
    activeShortcodeTrigger = null;
  };

  const dismissShortcode = (event) => {
    if (activeShortcodeConfigurator &&
        !activeShortcodeConfigurator.contains(event.target) &&
        !activeShortcodeTrigger?.contains(event.target)) {
      closeShortcodeConfigurator();
    }
  };

  const handleShortcodeKey = (event) => {
    if (event.key !== "Escape" || !activeShortcodeConfigurator) return;
    if (activeShortcodeConfigurator.isConnected && activeShortcodeTrigger?.isConnected) {
      event.preventDefault();
      closeShortcodeConfigurator({ restoreFocus: true });
    } else {
      closeShortcodeConfigurator();
    }
  };

  function setShortcodeListeners(enabled) {
    const method = enabled ? "addEventListener" : "removeEventListener";
    for (const [target, type, handler, capture] of [
      [document, "click", dismissShortcode, true],
      [document, "keydown", handleShortcodeKey],
      [window, "resize", positionShortcodeConfigurator],
      [window, "scroll", positionShortcodeConfigurator, true],
    ]) target[method](type, handler, capture);
  }

  /**
   * Attaches a delegated click event listener to the main forms container.
   * @param {HTMLElement} formsContainerElement The main container element.
   */
  function aipkitForms_attachClickEventListeners(formsContainerElement) {
    const __ =
      typeof wp !== "undefined" && wp.i18n && wp.i18n.__
        ? wp.i18n.__
        : (text) => text;

    if (formsContainerElement.dataset.clickListenerAttached === "true") {
      return; // Listener already attached
    }

    const runWithLeaveGuard = (proceed) => {
      if (typeof window.aipkitForms_runWithLeaveGuard === "function") {
        window.aipkitForms_runWithLeaveGuard(
          formsContainerElement,
          proceed,
          { keepDraftOnDiscard: false }
        );
      } else {
        proceed();
      }
    };

    const showFieldSettings = (element) => {
      if (element && typeof aipkitForms_showSettingsPanel === "function") {
        aipkitForms_showSettingsPanel(element.id, formsContainerElement);
      }
    };

    const showError = (message) => {
      if (typeof window.aipkitForms_displayMessage === "function") {
        window.aipkitForms_displayMessage(
          formsContainerElement,
          "error",
          message
        );
        return;
      }
      console.error(message);
    };

    const requestConfirmation = (message, options, onConfirm) => {
      if (typeof window.aipkit_showConfirmModal !== "function") {
        console.error("AI Forms confirmation modal is not available.");
        return;
      }

      window.aipkit_showConfirmModal(message, {
        title: options.title,
        confirmText: options.confirmText,
        cancelText: __("Cancel", "gpt3-ai-content-generator"),
        variant: options.variant || "danger",
        onConfirm,
      });
    };

    closeShortcodeConfigurator();
    closeActionsMenu(activeActionsMenu, false);
    window.aipkitForms_closeShortcodeConfigurator = closeShortcodeConfigurator;

    formsContainerElement.addEventListener("click", function (event) {
      const target = event.target;
      const targetClosest = (selector) => target.closest(selector);
      const editorContainer = formsContainerElement.querySelector(
        "#aipkit_form_editor_container"
      );

      // Create New Form
      if (targetClosest("#aipkit_create_new_ai_form_btn")) {
        event.preventDefault();
        const openNewForm = () => {
          if (typeof aipkitForms_showFormEditor === "function") {
            aipkitForms_showFormEditor(null, formsContainerElement);
          }
        };
        runWithLeaveGuard(openNewForm);
      }
      else if (targetClosest(".aipkit_actions_menu_toggle")) {
        event.preventDefault();
        event.stopPropagation();

        const menuWrapper = target.closest(".aipkit_actions_menu");
        if (!menuWrapper) return;

        const dropdownMenu = menuWrapper.querySelector(
          ".aipkit_actions_dropdown_menu"
        );
        if (!dropdownMenu) return;

        const isVisible =
          dropdownMenu.style.display && dropdownMenu.style.display !== "none";

        // Hide all other dropdowns on the page
        document
          .querySelectorAll(".aipkit_actions_dropdown_menu")
          .forEach((menu) => {
            if (menu !== dropdownMenu) {
              closeActionsMenu(menu, false);
            }
          });

        // Toggle the current one
        dropdownMenu.style.display = isVisible ? "none" : "flex";
        menuWrapper.classList.toggle("is-open", !isVisible);
        targetClosest(".aipkit_actions_menu_toggle")?.setAttribute(
          "aria-expanded",
          isVisible ? "false" : "true"
        );

        if (isVisible) {
          closeActionsMenu(menuWrapper, false);
        } else {
          activeActionsMenu = menuWrapper;
          document.addEventListener("click", dismissActionsMenu, true);
        }
      }
      else if (targetClosest("#aipkit_import_ai_forms_btn")) {
        event.preventDefault();
        closeActionsMenu(targetClosest("#aipkit_import_ai_forms_btn"));
        const importFileInput = formsContainerElement.querySelector(
          "#aipkit_ai_forms_import_file_input"
        );
        if (importFileInput) {
          importFileInput.click(); // Trigger the file selection dialog
        }
      }
      // Edit Form
      else if (targetClosest(".aipkit_edit_ai_form_btn")) {
        event.preventDefault();
        const editButton = targetClosest(".aipkit_edit_ai_form_btn");
        const formId = editButton.dataset.formId;
        const openExistingForm = () => {
          if (typeof aipkitForms_showFormEditor === "function") {
            aipkitForms_showFormEditor(formId, formsContainerElement);
          }
        };
        runWithLeaveGuard(openExistingForm);
      }
      // Preview Form (List)
      else if (targetClosest(".aipkit_preview_ai_form_btn")) {
        event.preventDefault();
        const previewButton = targetClosest(".aipkit_preview_ai_form_btn");
        const formId = previewButton.dataset.formId;
        const formTitle = previewButton.dataset.formTitle || "";
        if (typeof window.aipkitForms_openPreviewSheet === "function") {
          window.aipkitForms_openPreviewSheet(
            formId,
            formTitle,
            formsContainerElement
          );
        }
      }
      // Export All Forms
      else if (targetClosest("#aipkit_export_all_ai_forms_btn")) {
        event.preventDefault();
        const exportAllButton = targetClosest(
          "#aipkit_export_all_ai_forms_btn"
        );
        closeActionsMenu(exportAllButton);
        if (typeof window.aipkitForms_exportAllForms === "function") {
          window.aipkitForms_exportAllForms(
            exportAllButton,
            formsContainerElement
          );
        }
      }
      // Copy prompt variable snippets in the editor.
      else if (targetClosest(".aipkit_ai_form_shortcode_code")) {
        event.preventDefault();
        const shortcodeButton = targetClosest(".aipkit_ai_form_shortcode_code");
        const shortcode =
          shortcodeButton.dataset.shortcode ||
          shortcodeButton.querySelector(".aipkit_ai_form_shortcode_text")
            ?.textContent ||
          shortcodeButton.textContent ||
          "";
        if (shortcode && typeof window.aipkit_copyShortcode === "function") {
          window.aipkit_copyShortcode(shortcode.trim(), shortcodeButton);
        }
      }
      // Toggle Shortcode Configurator
      else if (targetClosest(".aipkit_aiform_settings_toggle")) {
        event.preventDefault();
        event.stopPropagation();
        const toggleButton = targetClosest(".aipkit_aiform_settings_toggle");
        const formId = toggleButton.dataset.formId;
        const configurator = formsContainerElement.querySelector(
          `#aif-configurator-${formId}`
        );
        if (!configurator) return;

        if (
          activeShortcodeConfigurator === configurator &&
          configurator.style.display === "flex"
        ) {
          closeShortcodeConfigurator({ restoreFocus: true });
          return;
        }

        closeShortcodeConfigurator();
        if (
          typeof window.aipkitForms_resetShortcodeConfigurator === "function"
        ) {
          window.aipkitForms_resetShortcodeConfigurator(
            formId,
            formsContainerElement
          );
        }

        activeShortcodeConfigurator = configurator;
        activeShortcodeTrigger = toggleButton;
        configurator.style.display = "flex";
        configurator.style.visibility = "hidden";
        configurator.setAttribute("aria-hidden", "false");
        toggleButton.setAttribute("aria-expanded", "true");
        configurator.closest("tr")?.classList.add("aipkit_shortcode_row_open");
        setShortcodeListeners(true);
        positionShortcodeConfigurator();
        configurator.style.visibility = "visible";
      }
      // Reset one-off shortcode options to the saved defaults.
      else if (targetClosest(".aipkit_ai_form_shortcode_reset")) {
        event.preventDefault();
        const resetButton = targetClosest(".aipkit_ai_form_shortcode_reset");
        if (
          typeof window.aipkitForms_resetShortcodeConfigurator === "function"
        ) {
          window.aipkitForms_resetShortcodeConfigurator(
            resetButton.dataset.formId,
            formsContainerElement
          );
        }
      }
      else if (targetClosest("#aipkit_validate_prompt_btn")) {
        event.preventDefault();
        if (typeof window.aipkitForms_validatePrompt === "function") {
          window.aipkitForms_validatePrompt();
        }
      }
      // Open Prompt-to-Form Generator
      else if (targetClosest("#aipkit_generate_ai_form_btn")) {
        event.preventDefault();
        if (typeof window.aipkitForms_openFormGeneratorModal === "function") {
          window.aipkitForms_openFormGeneratorModal(formsContainerElement);
        }
      }
      // Close Prompt-to-Form Generator
      else if (targetClosest(".aipkit_ai_form_generator_modal_close")) {
        event.preventDefault();
        if (typeof window.aipkitForms_closeFormGeneratorModal === "function") {
          window.aipkitForms_closeFormGeneratorModal(formsContainerElement);
        }
      }
      // Generate Prompt-to-Form Draft
      else if (targetClosest("#aipkit_ai_form_generate_draft_btn")) {
        event.preventDefault();
        if (typeof window.aipkitForms_generateDraftFromPrompt === "function") {
          window.aipkitForms_generateDraftFromPrompt(formsContainerElement).catch(
            () => {}
          );
        }
      }
      // Save Form
      else if (targetClosest("#aipkit_save_ai_form_btn")) {
        event.preventDefault();
        const saveButton = targetClosest("#aipkit_save_ai_form_btn");
        if (typeof aipkitForms_saveForm === "function") {
          aipkitForms_saveForm(saveButton, formsContainerElement).catch(
            () => {}
          );
        }
      }
      // Cancel Edit
      else if (targetClosest("#aipkit_cancel_edit_ai_form_btn")) {
        event.preventDefault();
        const closeEditor = () => {
          if (typeof aipkitForms_hideFormEditor === "function") {
            aipkitForms_hideFormEditor(formsContainerElement);
          }
        };
        runWithLeaveGuard(closeEditor);
      }
      // Preview Form
      else if (targetClosest("#aipkit_preview_ai_form_btn")) {
        event.preventDefault();
        const previewButton = targetClosest("#aipkit_preview_ai_form_btn");
        const canPreview =
          typeof window.aipkitForms_syncPreviewButtonState === "function"
            ? window.aipkitForms_syncPreviewButtonState(formsContainerElement)
            : !previewButton.disabled;
        if (!canPreview) {
          return;
        }

        const validationResult =
          typeof window.aipkitForms_validatePrompt === "function"
            ? window.aipkitForms_validatePrompt({ autoDismiss: false })
            : { isValid: true };
        if (!validationResult?.isValid) {
          return;
        }

        if (typeof window.aipkitForms_saveForm !== "function") {
          showError(
            __("Unable to save this form for preview.", "gpt3-ai-content-generator")
          );
          return;
        }

        window
          .aipkitForms_saveForm(previewButton, formsContainerElement, {
            skipFetchList: false,
            loadingText: __("Saving...", "gpt3-ai-content-generator"),
          })
          .then((response) => {
            const formId =
              editorContainer.querySelector("#aipkit_ai_form_id")?.value ||
              response?.form_id ||
              "";
            const formTitleInput = formsContainerElement.querySelector(
              "#aipkit_ai_form_title"
            );
            const formTitle = formTitleInput ? formTitleInput.value : "";

            if (!formId) {
              throw new Error(
                __("The saved form ID is missing.", "gpt3-ai-content-generator")
              );
            }

            if (typeof window.aipkitForms_openPreviewSheet === "function") {
              window.aipkitForms_openPreviewSheet(
                formId,
                formTitle,
                formsContainerElement
              );
              return;
            }

            throw new Error("AI Forms Preview: Preview sheet helper not available.");
          })
          .catch((error) => {
            if (error?.code) {
              return;
            }
            showError(
              error?.message ||
                __("Unable to open the form preview.", "gpt3-ai-content-generator")
            );
          });
      }
      // Open settings for this field from its contextual toolbar.
      else if (targetClosest(".aipkit_dropped_element_settings_btn")) {
        event.preventDefault();
        showFieldSettings(targetClosest(".aipkit_dropped_element"));
      }
      // Delete Dropped Element Button
      else if (targetClosest(".aipkit_dropped_element_delete_btn")) {
        event.preventDefault();
        const elementWrapper = targetClosest(".aipkit_dropped_element");
        if (elementWrapper) {
          requestConfirmation(
            __(
              "This permanently removes this field from the form. This cannot be undone.",
              "gpt3-ai-content-generator"
            ),
            {
              title: __("Delete field", "gpt3-ai-content-generator"),
              confirmText: __("Delete", "gpt3-ai-content-generator"),
              variant: "danger",
            },
            () => {
              if (typeof aipkitForms_deleteDroppedElement === "function") {
                aipkitForms_deleteDroppedElement(
                  elementWrapper.id,
                  formsContainerElement
                );
              }
            }
          );
        }
      }
      // Selecting a field reveals its editing state and settings.
      else if (targetClosest(".aipkit_dropped_element")) {
        event.preventDefault();
        showFieldSettings(targetClosest(".aipkit_dropped_element"));
      }
      else if (targetClosest(".aipkit_layout_row_delete_btn")) {
        event.preventDefault();
        const rowWrapper = targetClosest(".aipkit_layout_row");
        if (rowWrapper) {
          requestConfirmation(
            __(
              "This permanently removes this layout and every field inside it. This cannot be undone.",
              "gpt3-ai-content-generator"
            ),
            {
              title: __("Delete layout", "gpt3-ai-content-generator"),
              confirmText: __("Delete", "gpt3-ai-content-generator"),
              variant: "danger",
            },
            () => {
              // Keep the settings panel from pointing at a field removed with the layout.
              const elementIdsInRow = Array.from(
                rowWrapper.querySelectorAll(".aipkit_dropped_element")
              ).map((el) => el.id);
              if (
                elementIdsInRow.includes(
                  window.aipkitAIFormsState.selectedDesignerElementId
                )
              ) {
                aipkitForms_hideSettingsPanel(formsContainerElement);
              }
              rowWrapper.remove();
              window.aipkitForms_updateFormStructureOrder();
              window.aipkitForms_updatePromptSnippets(formsContainerElement);
              if (
                typeof window.aipkitForms_showEmptyDesignerPlaceholder ===
                "function"
              ) {
                window.aipkitForms_showEmptyDesignerPlaceholder(
                  formsContainerElement
                );
              }
            }
          );
        }
      }
      // Settings Panel Close Button
      else if (targetClosest("#aipkit_settings_panel_close_btn")) {
        event.preventDefault();
        if (typeof aipkitForms_hideSettingsPanel === "function") {
          aipkitForms_hideSettingsPanel(formsContainerElement);
        }
      }
      // Add/Remove Select Option
      else if (targetClosest(".aipkit_remove_option_btn")) {
        event.preventDefault();
        const optionRow = targetClosest(".aipkit_select_option_row");
        if (optionRow) {
          optionRow.remove();
          if (
            window.aipkitAIFormsState.selectedDesignerElementId &&
            typeof aipkitForms_updateElementFromSettingsPanel === "function"
          ) {
            aipkitForms_updateElementFromSettingsPanel(
              window.aipkitAIFormsState.selectedDesignerElementId,
              formsContainerElement
            );
          }
        }
      } else if (targetClosest("#aipkit_add_select_option_btn")) {
        event.preventDefault();
        if (typeof aipkitForms_addSelectOptionRow === "function") {
          aipkitForms_addSelectOptionRow(formsContainerElement);
        }
      }
    });

    formsContainerElement.dataset.clickListenerAttached = "true";
  }

  // Expose globally
  window.aipkitForms_attachClickEventListeners =
    aipkitForms_attachClickEventListeners;

  const SHORTCODE_CONFIG_STORAGE_PREFIX =
    "aipkit_ai_forms_shortcode_config_v1_";
  const DEFAULT_SHORTCODE_CONFIG = Object.freeze({
    show_provider: false,
    show_model: false,
    copy_button: false,
    save_button: false,
    pdf_download: false,
    theme: "light",
  });

  function loadStoredShortcodeConfig(formId) {
    if (!formId || !window.localStorage) {
      return null;
    }
    try {
      const raw = window.localStorage.getItem(
        `${SHORTCODE_CONFIG_STORAGE_PREFIX}${formId}`
      );
      if (!raw) return null;
      const parsed = JSON.parse(raw);
      return parsed && typeof parsed === "object" ? parsed : null;
    } catch (error) {
      return null;
    }
  }

  function getSavedShortcodeConfig(formId) {
    const storedConfig = loadStoredShortcodeConfig(formId) || {};
    const validThemes = new Set(["light", "dark", "custom"]);
    const isPro = Boolean(window.aipkit_dashboard?.isProPlan);

    return {
      show_provider: Boolean(storedConfig.show_provider),
      show_model: Boolean(storedConfig.show_model),
      copy_button: Boolean(storedConfig.copy_button),
      save_button: Boolean(storedConfig.save_button),
      pdf_download: isPro && Boolean(storedConfig.pdf_download),
      theme: validThemes.has(storedConfig.theme)
        ? storedConfig.theme
        : DEFAULT_SHORTCODE_CONFIG.theme,
    };
  }

  function buildShortcode(formId, shortcodeConfig) {
    const attributes = [];
    [
      "show_provider",
      "show_model",
      "copy_button",
      "save_button",
      "pdf_download",
    ].forEach((attributeName) => {
      if (shortcodeConfig[attributeName]) {
        attributes.push(`${attributeName}="true"`);
      }
    });

    if (shortcodeConfig.theme && shortcodeConfig.theme !== "light") {
      attributes.push(`theme="${shortcodeConfig.theme}"`);
    }

    return `[aipkit_ai_form id=${formId}${
      attributes.length ? ` ${attributes.join(" ")}` : ""
    }]`;
  }

  function setShortcodeDisplay(element, shortcode) {
    if (!element) return;

    if (element.matches("button")) {
      element.dataset.shortcode = shortcode;
      return;
    }

    const textNode = element.querySelector?.(".aipkit_ai_form_shortcode_text");
    if (textNode) {
      textNode.textContent = shortcode;
    } else {
      element.textContent = shortcode;
    }
    element.title = shortcode;
    element.dataset.shortcode = shortcode;
  }

  function readPopoverConfig(configurator) {
    const shortcodeConfig = { ...DEFAULT_SHORTCODE_CONFIG };
    configurator
      .querySelectorAll(".aipkit-aiform-copy-option")
      .forEach((input) => {
        const attributeName = input.dataset.attrName;
        if (!attributeName) return;

        if (input.type === "checkbox") {
          shortcodeConfig[attributeName] =
            !input.disabled && Boolean(input.checked);
        } else if (input.type === "select-one") {
          shortcodeConfig[attributeName] = input.value;
        }
      });
    return shortcodeConfig;
  }

  function applyConfigToPopover(configurator, shortcodeConfig) {
    configurator
      .querySelectorAll(".aipkit-aiform-copy-option")
      .forEach((input) => {
        const attributeName = input.dataset.attrName;
        if (!attributeName) return;

        if (input.type === "checkbox") {
          input.checked =
            !input.disabled && Boolean(shortcodeConfig[attributeName]);
        } else if (input.type === "select-one") {
          input.value = shortcodeConfig[attributeName] || "light";
        }
      });
  }

  function updateShortcodePreview(formId, formsContainerElement) {
    if (!formId || !formsContainerElement) return "";

    const configurator = formsContainerElement.querySelector(
      `#aif-configurator-${formId}`
    );
    if (!configurator) return "";

    const shortcode = buildShortcode(
      formId,
      readPopoverConfig(configurator)
    );
    const preview = configurator.querySelector(
      ".aipkit_ai_form_shortcode_preview"
    );
    const copyButton = configurator.querySelector(
      ".aipkit_ai_form_shortcode_variant_copy"
    );
    setShortcodeDisplay(preview, shortcode);
    setShortcodeDisplay(copyButton, shortcode);
    return shortcode;
  }

  function resetShortcodeConfigurator(formId, formsContainerElement) {
    if (!formId || !formsContainerElement) return "";

    const configurator = formsContainerElement.querySelector(
      `#aif-configurator-${formId}`
    );
    if (!configurator) return "";

    applyConfigToPopover(configurator, getSavedShortcodeConfig(formId));
    return updateShortcodePreview(formId, formsContainerElement);
  }

  function applyStoredShortcodeConfig(formId, formsContainerElement) {
    if (!formId || !formsContainerElement) return;

    const shortcode = buildShortcode(
      formId,
      getSavedShortcodeConfig(formId)
    );
    formsContainerElement
      .querySelectorAll(`[data-aipkit-shortcode-display="${formId}"]`)
      .forEach((element) => setShortcodeDisplay(element, shortcode));
    resetShortcodeConfigurator(formId, formsContainerElement);
  }

  function aipkitForms_attachChangeEventListeners(formsContainerElement) {
    if (formsContainerElement.dataset.changeListenerAttached === "true") {
      return;
    }

    formsContainerElement.addEventListener("change", function (event) {
      const target = event.target;

      if (target.matches(".aipkit-aiform-copy-option")) {
        updateShortcodePreview(target.dataset.formId, formsContainerElement);
      } else if (target.id === "aipkit_ai_forms_import_file_input") {
        if (typeof window.aipkitForms_importForms === "function") {
          window.aipkitForms_importForms(target);
        }
        target.value = "";
      }
    });

    formsContainerElement.dataset.changeListenerAttached = "true";
  }

  window.aipkitForms_attachChangeEventListeners =
    aipkitForms_attachChangeEventListeners;
  window.aipkitForms_applyStoredShortcodeConfig =
    applyStoredShortcodeConfig;
  window.aipkitForms_resetShortcodeConfigurator =
    resetShortcodeConfigurator;
})();
