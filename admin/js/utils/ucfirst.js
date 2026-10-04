/**
 * AIPKit Utilities - ucfirst
 * Capitalizes the first letter of a string.
 */
(function () {
  "use strict";
  function aipkit_ucfirst(str) {
    if (!str || typeof str !== "string") return "";
    return str.charAt(0).toUpperCase() + str.slice(1);
  }
  window.aipkit_ucfirst = aipkit_ucfirst;

  // For backward compatibility with modules that might call the non-prefixed version
  window.ucfirst = aipkit_ucfirst;
})();
