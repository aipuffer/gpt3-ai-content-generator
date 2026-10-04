/**
 * AIPKit Generic UI - Range Slider Handler
 *
 * Updates the display of numeric values for range sliders.
 */
(function () {
  "use strict";

  /**
   * Attaches input event listeners to all range sliders within a given container.
   * @param {string} scopeSelector CSS selector for the container element to search within.
   */
  function aipkit_attachRangeValueHandlers(scopeSelector) {
    const container = document.querySelector(scopeSelector);
    if (!container) {
      // It's possible for a module not to be on the page, so this is not an error.
      return;
    }

    const sliders = container.querySelectorAll(
      ".aipkit_form-input.aipkit_range_slider"
    );
    sliders.forEach((slider) => {
      const valueSpanId = slider.id + "_value";
      let valueSpan = null;
      // Prefer finding the sibling value element within the same wrapper for robustness
      const wrapper = slider.closest('.aipkit_slider_wrapper');
      if (wrapper) {
        valueSpan = wrapper.querySelector('.aipkit_slider_value');
      }
      // Fallback: query by computed ID within the provided container
      if (!valueSpan) {
        try {
          valueSpan = container.querySelector(`#${CSS.escape(valueSpanId)}`);
        } catch (e) {
          valueSpan = container.querySelector('#' + valueSpanId);
        }
      }

      if (valueSpan) {
        // Determine display suffix (supports data-suffix="%")
        const suffix = slider.getAttribute('data-suffix') || (slider.id.includes('confidence_threshold') ? '%' : '');
        // Initial display update
        valueSpan.textContent = slider.value + suffix;

        // Prevent attaching multiple listeners
        if (!slider.hasAttribute("data-range-listener-attached")) {
          slider.addEventListener("input", function () {
            valueSpan.textContent = this.value + suffix;
          });
          slider.setAttribute("data-range-listener-attached", "true");
        }
      }
    });
  }

  // Expose globally
  window.aipkit_attachRangeValueHandlers = aipkit_attachRangeValueHandlers;
})();
