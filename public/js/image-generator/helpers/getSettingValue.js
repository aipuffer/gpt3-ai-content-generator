(function () {
  "use strict";

  /**
   * Gets the value from a setting field, which could be a visible select
   * or a hidden input as a fallback.
   * @param {HTMLElement} wrapper - The parent container to search within.
   * @param {string} selectId - The ID of the <select> element.
   * @param {string} inputName - The name attribute of the hidden <input> element.
   * @returns {string|null} The value of the setting, or null if not found.
   */
  function aipkit_getSettingValue(wrapper, selectId, inputName) {
    const selectElement = wrapper.querySelector(`#${selectId}`);
    // Check if the element is visible in the layout
    if (selectElement && selectElement.offsetParent !== null) {
      return selectElement.value;
    } else {
      const hiddenInput = wrapper.querySelector(
        `input[type="hidden"][name="${inputName}"]`
      );
      return hiddenInput ? hiddenInput.value : null;
    }
  }

  window.aipkit_getSettingValue = aipkit_getSettingValue;
})();
