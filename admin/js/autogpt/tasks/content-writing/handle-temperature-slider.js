/**
 * AIPKit AutoGPT - Content Writing Task: Temperature Slider Handler
 */
(function () {
  "use strict";
  function aipkit_task_cw_handleTemperatureSlider(tempSlider) {
    if (!tempSlider) return;
    const wrapper = tempSlider.closest(".aipkit_slider_wrapper");
    let tempValueSpan = null;
    if (wrapper) {
      tempValueSpan = wrapper.querySelector(".aipkit_slider_value");
    }
    if (!tempValueSpan) {
      const control = tempSlider.closest(".aipkit_ai_temperature_control");
      if (control) {
        tempValueSpan = control.querySelector(".aipkit_ai_behavior_value");
      }
    }
    if (tempValueSpan) {
      const updateValue = () => {
        if (typeof tempSlider._aipkitTemperatureSync === "function") {
          tempSlider._aipkitTemperatureSync();
        } else {
          tempValueSpan.textContent = tempSlider.value;
        }
      };
      updateValue();
      if (!tempSlider.dataset.listenerAttached) {
        tempSlider.addEventListener("input", updateValue);
        tempSlider.dataset.listenerAttached = "true";
      }
    }
  }
  window.aipkit_task_cw_handleTemperatureSlider =
    aipkit_task_cw_handleTemperatureSlider;
})();
