/**
 * AIPKit Automated Tasks - Save Task: Submit Task Request
 * Handles the API request to save the task data.
 */
(function () {
  "use strict";

  /**
   * Sends the prepared data to the backend via AJAX.
   * @param {Object} data - The final, validated data object to send.
   * @returns {Promise<any>} The promise from the aipkit_apiRequest call.
   */
  async function aipkit_save_task_submitRequest(data) {
    const config = window.aipkit_automated_tasks_config || {};

    const dataToSend = { ...data, _ajax_nonce: config.nonce_manage_tasks };

    if (typeof window.aipkit_apiRequest !== "function") {
      return Promise.reject(new Error("API request function is missing."));
    }

    return await window.aipkit_apiRequest(
      "aipkit_save_automated_task",
      dataToSend
    );
  }

  window.aipkit_save_task_submitRequest = aipkit_save_task_submitRequest;
})();
