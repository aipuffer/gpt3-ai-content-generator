import {
  renderPopoverMultiselectOptions,
  syncPopoverMultiselectCheckboxesFromSelect,
  syncPopoverMultiselectSelectFromCheckboxes,
  setPopoverMultiselectLabel,
  setupSearchablePopoverMultiselect,
} from "../../utils/ui/popover-multiselect.js";
import { getFixedDropdownPanelMetrics } from "../../utils/ui/popover-position.js";

(function () {
  "use strict";

  const vectorStoreRuntimeRules = new Map();

  function syncVectorStoreRuntimeStyleTag() {
    let styleTag = document.getElementById(
      "aipkit_content_writer_vector_store_runtime_styles"
    );

    if (!vectorStoreRuntimeRules.size) {
      if (styleTag) {
        styleTag.remove();
      }
      return;
    }

    if (!styleTag) {
      styleTag = document.createElement("style");
      styleTag.id = "aipkit_content_writer_vector_store_runtime_styles";
      document.head.appendChild(styleTag);
    }

    styleTag.textContent = Array.from(vectorStoreRuntimeRules.values()).join(
      "\n"
    );
  }

  function clearVectorStoreRuntimeRule(panel) {
    if (!panel?.id) return;
    vectorStoreRuntimeRules.delete(panel.id);
    syncVectorStoreRuntimeStyleTag();
  }

  function setVectorStoreRuntimeRule(panel, metrics, container) {
    if (!panel?.id || !metrics) return;

    const escapedId =
      window.CSS && typeof window.CSS.escape === "function"
        ? window.CSS.escape(panel.id)
        : panel.id;

    const selectorParts = [];
    const escapedContainerId =
      container?.id && window.CSS && typeof window.CSS.escape === "function"
        ? window.CSS.escape(container.id)
        : container?.id || "";

    if (escapedContainerId) {
      selectorParts.push(`#${escapedContainerId} #${escapedId}`);
    }

    selectorParts.push(
      `#aipkit_content_writer_container #${escapedId}`,
      `#aipkit_autogpt_container #${escapedId}`,
      `#aipkit_ai_forms_container #${escapedId}`,
      `#aipkit_form_editor_container #${escapedId}`,
      `.aipkit_enhancer_bulk_modal #${escapedId}`
    );

    const selector = Array.from(new Set(selectorParts)).join(", ");

    vectorStoreRuntimeRules.set(
      panel.id,
      `${selector}{position:fixed !important;left:${Math.round(
        metrics.left
      )}px !important;right:auto !important;top:${Math.round(
        metrics.top
      )}px !important;bottom:auto !important;width:${Math.round(
        metrics.width
      )}px !important;min-width:${Math.round(
        metrics.minWidth
      )}px !important;max-width:${Math.round(
        metrics.maxWidth
      )}px !important;box-sizing:border-box;}`
    );
    syncVectorStoreRuntimeStyleTag();
  }

  function aipkit_initContentWriterVectorStoreMultiSelect(scope) {
    const container =
      scope || document.getElementById("aipkit_content_writer_container");
    if (!container) return;

    const dropdowns = container.querySelectorAll(
      "[data-aipkit-vector-stores-dropdown]"
    );
    if (!dropdowns.length) return;

    dropdowns.forEach((dropdown) => {
      // Provider fields keep the visual picker and its persistence select in the
      // same wrapper. Resolve that local contract instead of coupling the shared
      // picker to provider-specific wrapper class names.
      const selectElement =
        dropdown.parentElement?.querySelector(
          ".aipkit_popover_multiselect_select"
        ) ||
        dropdown
          .closest(".aipkit_popover_option_main")
          ?.querySelector(".aipkit_popover_multiselect_select");
      if (!selectElement) return;

      const dropdownButton = dropdown.querySelector(
        ".aipkit_popover_multiselect_btn, .aipkit_enhancer_vector_multiselect_btn"
      );
      const dropdownLabel = dropdown.querySelector(
        ".aipkit_popover_multiselect_label"
      );
      const dropdownPanel = dropdown.querySelector(
        ".aipkit_popover_multiselect_panel"
      );
      const dropdownOptions = dropdown.querySelector(
        ".aipkit_popover_multiselect_options"
      );
      if (!dropdownButton || !dropdownPanel || !dropdownOptions) return;

      const placeholder = dropdown.dataset.placeholder || "Select stores";
      const selectedLabel = dropdown.dataset.selectedLabel || "selected";
      const __ = window.wp?.i18n?.__ || ((text) => text);
      let resizeRafId = 0;
      let searchableMenu = null;

      const positionDropdownPanel = () => {
        if (!dropdown || !dropdownButton || !dropdownPanel) return;

        const containerStyles = window.getComputedStyle(container);
        const preferredWidth = parseFloat(
          containerStyles.getPropertyValue(
            "--aipkit-cw-dropdown-panel-min-width"
          ) || ""
        );
        const viewportMaxWidth = Math.max(160, window.innerWidth - 32);
        const useTriggerWidth = dropdown.dataset.aipkitDropdownWidth === "trigger";
        const triggerWidth = dropdownButton.getBoundingClientRect().width;
        const panelWidth = Math.min(
          useTriggerWidth
            ? triggerWidth
            : Number.isFinite(preferredWidth)
              ? preferredWidth
              : 320,
          viewportMaxWidth
        );
        const metrics = getFixedDropdownPanelMetrics(
          dropdownButton,
          dropdownPanel,
          panelWidth,
          {
            horizontalAlign:
              dropdown.dataset.aipkitDropdownAlign === "start"
                ? "start"
                : "end",
          }
        );
        if (!metrics) return;

        setVectorStoreRuntimeRule(dropdownPanel, metrics, container);
      };

      const schedulePanelPosition = () => {
        if (!dropdown.classList.contains("is-open")) return;
        if (resizeRafId) {
          cancelAnimationFrame(resizeRafId);
        }
        resizeRafId = requestAnimationFrame(() => {
          resizeRafId = 0;
          positionDropdownPanel();
        });
      };

      const updateDropdownLabel = () => {
        setPopoverMultiselectLabel(
          dropdownLabel,
          selectElement,
          placeholder,
          selectedLabel
        );
      };

      const renderDropdownOptions = () => {
        renderPopoverMultiselectOptions(
          dropdownOptions,
          selectElement,
          "-- No Stores Found --",
          updateDropdownLabel
        );
        searchableMenu?.refresh();
      };

      const syncCheckboxesFromSelect = () => {
        syncPopoverMultiselectCheckboxesFromSelect(
          dropdownOptions,
          selectElement,
          updateDropdownLabel
        );
        searchableMenu?.refresh();
      };

      const syncSelectFromCheckboxes = (event) => {
        syncPopoverMultiselectSelectFromCheckboxes(
          selectElement,
          dropdownOptions,
          { event, maxSelections: 2, updateLabel: updateDropdownLabel }
        );
        searchableMenu?.refresh();
      };

      const setOpenState = (open) => {
        if (!dropdown || !dropdownButton) return;
        dropdown.classList.toggle("is-open", open);
        dropdownButton.setAttribute("aria-expanded", open ? "true" : "false");
        if (dropdownPanel) {
          dropdownPanel.hidden = !open;
        }
        if (open) {
          syncCheckboxesFromSelect();
          positionDropdownPanel();
          schedulePanelPosition();
          searchableMenu?.prepareOpen();
          return;
        }
        clearVectorStoreRuntimeRule(dropdownPanel);
        if (resizeRafId) {
          cancelAnimationFrame(resizeRafId);
          resizeRafId = 0;
        }
      };

      searchableMenu = setupSearchablePopoverMultiselect(dropdown, {
        searchPlaceholder: __(
          "Search vector stores",
          "gpt3-ai-content-generator"
        ),
        searchAriaLabel: __(
          "Search vector stores",
          "gpt3-ai-content-generator"
        ),
        noResultsText: __(
          "No vector stores found.",
          "gpt3-ai-content-generator"
        ),
        dialogLabel: __("Vector stores", "gpt3-ai-content-generator"),
        onEscape: () => {
          setOpenState(false);
          dropdownButton.focus();
        },
      });

      renderDropdownOptions();
      syncCheckboxesFromSelect();

      if (!dropdown.dataset.vectorStoreMultiselectAttached) {
        if (dropdownButton) {
          dropdownButton.addEventListener("click", (event) => {
            event.preventDefault();
            event.stopPropagation();
            setOpenState(!dropdown.classList.contains("is-open"));
          });
          dropdownButton.addEventListener("keydown", (event) => {
            if (event.key === "Escape") {
              setOpenState(false);
            }
          });
        }
        document.addEventListener("click", (event) => {
          if (!dropdown.classList.contains("is-open")) return;
          if (dropdown.contains(event.target)) return;
          setOpenState(false);
        });
        window.addEventListener("resize", schedulePanelPosition);
        window.addEventListener("scroll", schedulePanelPosition, true);
        if (dropdownOptions && !dropdownOptions.dataset.listenerAttached) {
          dropdownOptions.addEventListener("change", syncSelectFromCheckboxes);
          dropdownOptions.dataset.listenerAttached = "true";
        }
        if (
          dropdownPanel &&
          !dropdownPanel.dataset.vectorStoreClickListenerAttached
        ) {
          dropdownPanel.addEventListener("click", (event) => {
            event.stopPropagation();
          });
          dropdownPanel.dataset.vectorStoreClickListenerAttached = "true";
        }

        if (!selectElement.dataset.listenerAttached) {
          selectElement.addEventListener("change", syncCheckboxesFromSelect);
          selectElement.dataset.listenerAttached = "true";
        }

        dropdown.dataset.vectorStoreMultiselectAttached = "true";
      }
    });
  }

  window.aipkit_initContentWriterVectorStoreMultiSelect =
    aipkit_initContentWriterVectorStoreMultiSelect;
})();
