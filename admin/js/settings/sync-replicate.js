/**
 * AIPKit Settings - Replicate Model Select Updater
 */
import { selectFirstAvailableOption } from "./sync-select-utils.js";

(function () {
  "use strict";

  /**
   * Update the Replicate model <select>.
   * @param {Array} models Array of model objects [{ id: '...', name: '...' }, ...].
   */
  function aipkit_updateReplicateModels(models, targetSelectId = "") {
    const selectId = targetSelectId || "aipkit_replicate_model";
    const select = document.getElementById(selectId);

    // Update global image model list if available (used by Content Writer/AutoGPT)
    if (window.aipkit_dashboard && window.aipkit_dashboard.imageGeneratorModels) {
      window.aipkit_dashboard.imageGeneratorModels.replicate = models;
    }

    if (typeof window.aipkit_refreshContentWriterImageSelection === "function") {
      window.aipkit_refreshContentWriterImageSelection();
    }

    if (window.aipkit_image_generator_config_public) {
      window.aipkit_image_generator_config_public.replicate_models = models;
    }

    if (!select) {
      if (targetSelectId) {
        console.warn(
          `Replicate Sync: Select element #${selectId} not found.`
        );
      }
      return;
    }
    const oldValue = select.value;
    select.innerHTML = ""; // Clear existing options

    if (!models || !models.length) {
      select.appendChild(new Option("(No models found - Sync again)", ""));
      if (oldValue && oldValue !== "") {
        select.appendChild(
          new Option(oldValue + "", oldValue, false, true)
        );
        select.value = oldValue;
      }
      return;
    }

    let foundOldValue = false;
    // Sort models by name for better display
    models.sort((a, b) =>
      (a.name || a.id || "").localeCompare(b.name || b.id || "")
    );

    models.forEach((m) => {
      const modelId = m.id || "";
      const modelName = m.name || modelId;
      if (!modelId) return; // Skip if ID is missing
      const option = new Option(modelName, modelId);
      if (modelId === oldValue) {
        option.selected = true;
        foundOldValue = true;
      }
      select.appendChild(option);
    });

    // Re-add old value if not found
    if (!foundOldValue && oldValue && oldValue !== "") {
      if (!select.querySelector(`option[value="${CSS.escape(oldValue)}"]`)) {
        const oldOption = new Option(
          oldValue + "",
          oldValue,
          false,
          true
        );
        select.insertBefore(oldOption, select.firstChild);
        select.value = oldValue; // Reselect manual option
      }
    }

    // Select first option if none selected
    if (!select.value && select.options.length > 0) {
      selectFirstAvailableOption(select);
    }
  }

  // Expose globally
  window.aipkit_updateReplicateModels = aipkit_updateReplicateModels;
})();
