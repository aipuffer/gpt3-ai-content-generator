/**
 * AIPKit Content Assistant - inline prompt and advanced controls.
 */
import {
  appendPromptLibraryOptions,
  ensurePromptEditorControls,
  getPromptEditorControls,
  getPromptTextareaForSelect,
  getPromptTypeForSelect,
  openPromptLibraryManagerForSelect,
  renderPromptPlaceholderChips,
  restorePromptLibrarySelection,
  syncPromptTextareaFromSelect,
  updatePromptEditorControlState,
} from "../../utils/ui/prompt-editor-controls.js";

(function () {
  "use strict";

  const BASE_PLACEHOLDERS = [
    "{original_title}",
    "{original_content}",
    "{original_excerpt}",
    "{original_focus_keyword}",
    "{original_tags}",
    "{categories}",
  ];

  const META_PLACEHOLDERS = [
    "{original_title}",
    "{original_content}",
    "{original_focus_keyword}",
    "{original_meta_description}",
    "{original_tags}",
    "{categories}",
  ];

  const PRODUCT_PLACEHOLDERS = [
    "{price}",
    "{regular_price}",
    "{sku}",
    "{attributes}",
    "{stock_quantity}",
    "{stock_status}",
    "{weight}",
    "{length}",
    "{width}",
    "{height}",
    "{purchase_note}",
    "{product_categories}",
  ];

  const getPostType = (scopeElement) =>
    scopeElement
      ?.querySelector(".aipkit_enhancer_bulk_modal")
      ?.dataset?.postType || "";

  const getPlaceholdersForPrompt = (promptType, postType) => {
    const placeholders = [
      ...(promptType === "meta" ? META_PLACEHOLDERS : BASE_PLACEHOLDERS),
    ];
    if (postType === "product") {
      placeholders.push(...PRODUCT_PLACEHOLDERS);
    }
    return placeholders.filter(
      (placeholder, index) => placeholders.indexOf(placeholder) === index
    );
  };

  const initAdvancedSettings = (scopeElement) => {
    const toggle = scopeElement.querySelector(
      "#aipkit_enhancer_advanced_settings_toggle"
    );
    const panel = scopeElement.querySelector(
      "#aipkit_enhancer_advanced_settings"
    );
    if (!toggle || !panel || toggle.dataset.listenerAttached === "true") {
      return;
    }

    toggle.addEventListener("click", () => {
      const isOpen = toggle.getAttribute("aria-expanded") === "true";
      toggle.setAttribute("aria-expanded", isOpen ? "false" : "true");
      panel.hidden = isOpen;
    });
    toggle.dataset.listenerAttached = "true";
  };

  const initPromptEditors = (scopeElement) => {
    const postType = getPostType(scopeElement);
    const promptLibraryApi = window.aipkit_prompt_library_api || null;
    const selects = scopeElement.querySelectorAll(
      ".aipkit_cw_prompt_library_select"
    );

    scopeElement
      .querySelectorAll("[data-aipkit-inline-prompt-toggle]")
      .forEach((button) => {
        if (button.dataset.listenerAttached === "true") {
          return;
        }
        button.addEventListener("click", () => {
          const targetId = button.dataset.aipkitInlinePromptToggle || "";
          const panel = targetId
            ? scopeElement.querySelector(`#${targetId}`)
            : null;
          if (!panel) {
            return;
          }
          const isOpen = button.getAttribute("aria-expanded") === "true";
          button.setAttribute("aria-expanded", isOpen ? "false" : "true");
          panel.hidden = isOpen;
          if (!isOpen) {
            const textarea = panel.querySelector("textarea");
            window.requestAnimationFrame(() => {
              textarea?.focus({ preventScroll: true });
              if (textarea) {
                textarea.scrollTop = 0;
              }
            });
          }
        });
        button.dataset.listenerAttached = "true";
      });

    selects.forEach((select) => {
      const textarea = getPromptTextareaForSelect(select, scopeElement);
      const placeholders = select
        .closest(".aipkit_cw_prompt_editor")
        ?.querySelector(".aipkit_bulk_prompt_placeholders");
      if (placeholders) {
        renderPromptPlaceholderChips(
          placeholders,
          getPlaceholdersForPrompt(
            placeholders.dataset.promptType || "",
            postType
          ),
          "Placeholders:"
        );
      }

      ensurePromptEditorControls(select);
      updatePromptEditorControlState(select, {
        promptLibraryApi,
      });

      if (select.dataset.promptLibraryAttached === "true") {
        return;
      }

      const { manageButton } = getPromptEditorControls(select);
      if (
        manageButton &&
        manageButton.dataset.promptLibraryAttached !== "true"
      ) {
        manageButton.addEventListener("click", () => {
          openPromptLibraryManagerForSelect(select, textarea, {
            fallbackPromptType: getPromptTypeForSelect(select),
          });
        });
        manageButton.dataset.promptLibraryAttached = "true";
      }

      select.addEventListener("change", () => {
        syncPromptTextareaFromSelect(select, textarea);
      });
      select.dataset.promptLibraryAttached = "true";
    });

    const refreshPromptLibrary = async (force = false) => {
      if (!promptLibraryApi || !selects.length) {
        return;
      }
      try {
        const data = await promptLibraryApi.list({ force });
        const library = data?.library || {};
        selects.forEach((select) => {
          const initialOption = select.options?.[0]?.cloneNode(true);
          if (!initialOption) {
            return;
          }
          const selectedPromptId =
            select.selectedOptions?.[0]?.dataset.promptId || "";
          const selectedValue = String(select.value || "");
          const promptType = getPromptTypeForSelect(select);
          select.innerHTML = "";
          select.appendChild(initialOption);
          appendPromptLibraryOptions(select, library[promptType] || []);
          restorePromptLibrarySelection(
            select,
            selectedPromptId,
            selectedValue
          );
        });
      } catch (error) {
        // Static prompt options remain available if the library request fails.
        console.error("Content Assistant prompt library refresh failed:", error);
      }
    };

    refreshPromptLibrary(true);
    if (scopeElement.dataset.promptLibrarySyncAttached !== "true") {
      window.addEventListener("aipkit_prompt_library_updated", () => {
        refreshPromptLibrary(true);
      });
      scopeElement.dataset.promptLibrarySyncAttached = "true";
    }
  };

  function initBulkInlineControls(scopeElement) {
    if (!scopeElement) {
      return;
    }
    initAdvancedSettings(scopeElement);
    initPromptEditors(scopeElement);
  }

  window.aipkit_enhancer_initBulkInlineControls =
    initBulkInlineControls;
})();
