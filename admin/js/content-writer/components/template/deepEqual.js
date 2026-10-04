/**
 * AIPKit Content Writer - Deep Equal Utility
 * Utility function to deeply compare two objects.
 */
(function () {
  "use strict";

  /**
   * Deep comparison of two objects.
   * @param {*} obj1
   * @param {*} obj2
   * @returns {boolean}
   */
  function aipkit_deepEqual(obj1, obj2) {
    if (obj1 === obj2) return true;
    if (
      obj1 == null ||
      typeof obj1 !== "object" ||
      obj2 == null ||
      typeof obj2 !== "object"
    )
      return false;

    const keys1 = Object.keys(obj1);
    const keys2 = Object.keys(obj2);
    if (keys1.length !== keys2.length) return false;

    for (const key of keys1) {
      if (!keys2.includes(key) || !aipkit_deepEqual(obj1[key], obj2[key]))
        return false;
    }
    return true;
  }

  window.aipkit_deepEqual = aipkit_deepEqual;
})();
