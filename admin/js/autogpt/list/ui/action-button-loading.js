/**
 * AIPKit Automated Tasks - Action Button Loading Helper
 * Toggles spinner-only loading state for task action buttons.
 */
(function () {
  "use strict";

  function aipkit_setAutogptActionButtonLoading(button, isLoading) {
    if (!button) return;

    button.classList.toggle("aipkit_loading", Boolean(isLoading));
    button.disabled = Boolean(isLoading);
  }

  window.aipkit_setAutogptActionButtonLoading =
    aipkit_setAutogptActionButtonLoading;
})();
