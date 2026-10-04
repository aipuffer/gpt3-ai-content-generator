/**
 * AIPKit Automated Tasks - Save Task: Extract Base Data
 * Extracts the initial data from the form.
 */
(function () {
  "use strict";

  /**
   * Gathers FormData and a plain object from the form, and ensures task_type is current.
   * @param {HTMLFormElement} form - The main task form.
   * @returns {Object} An object containing { formData, data }.
   */
  function aipkit_save_task_extractBaseData(form) {
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());

    // FormData contains one entry per selected option. Object.fromEntries()
    // keeps only the last duplicate, so normalize every [] field back to an
    // array before task-specific handlers and validation run.
    Array.from(formData.keys())
      .filter((key, index, keys) => key.endsWith("[]") && keys.indexOf(key) === index)
      .forEach((key) => {
        const normalizedKey = key.slice(0, -2);
        data[normalizedKey] = formData.getAll(key);
        delete data[key];
      });

    // Ensure task_type is the currently selected one, as form.reset() might not update the initial state if the form was pre-filled for editing.
    data.task_type = document.getElementById(
      "aipkit_automated_task_type"
    ).value;
    return { formData, data };
  }

  window.aipkit_save_task_extractBaseData = aipkit_save_task_extractBaseData;
})();
