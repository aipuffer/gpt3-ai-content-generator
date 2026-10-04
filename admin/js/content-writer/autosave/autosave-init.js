/**
 * AIPKit Content Writer - Autosave Initializer
 * Attaches listeners to autosave enabled fields.
 */
(function () {
  "use strict";

  function aipkit_initContentWriterAutosave() {
    const form = document.getElementById("aipkit_content_writer_form");
    if (!form) {
      return;
    }

    const listenerAttr = "data-aipkit-cw-autosave-attached";
    if (form.getAttribute(listenerAttr)) {
      return;
    }

    form.addEventListener("change", (event) => {
      const target = event.target;
      if (!target || !target.matches(".aipkit_autosave_trigger")) {
        return;
      }

      const tagName = target.tagName.toLowerCase();
      if (
        tagName === "select" ||
        target.type === "checkbox" ||
        target.type === "hidden" ||
        target.type === "range" ||
        target.type === "number"
      ) {
        if (typeof window.aipkit_handleContentWriterAutoSave === "function") {
          window.aipkit_handleContentWriterAutoSave(target);
        }
      }
    });

    form.addEventListener(
      "blur",
      (event) => {
        const target = event.target;
        if (!target || !target.matches(".aipkit_autosave_trigger")) {
          return;
        }

        const tagName = target.tagName.toLowerCase();
        if (
          (tagName === "input" || tagName === "textarea") &&
          target.type !== "range" &&
          target.type !== "checkbox" &&
          target.type !== "number"
        ) {
          if (
            typeof window.aipkit_handleContentWriterAutoSave === "function"
          ) {
            window.aipkit_handleContentWriterAutoSave(target);
          }
        }
      },
      true
    );

    if (
      typeof window.aipkit_updateContentWriterAutosaveBaseline === "function"
    ) {
      window.aipkit_updateContentWriterAutosaveBaseline();
    }

    form.setAttribute(listenerAttr, "true");
  }

  window.aipkit_initContentWriterAutosave = aipkit_initContentWriterAutosave;
})();
