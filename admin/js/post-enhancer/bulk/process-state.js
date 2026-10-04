/**
 * AIPKit Content Enhancer - Bulk Process State Management
 */
(function () {
  "use strict";

  window.aipkit_enhancer_bulkState = {
    queue: [],
    isRunning: false,
    completed: 0,
    failed: 0,
    total: 0,
    enhancementsConfig: {},
  };
})();
