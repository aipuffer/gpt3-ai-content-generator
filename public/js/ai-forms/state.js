/**
 * AIPKit AI Forms - Public Shared State
 */
(function () {
  "use strict";
  window.aipkitAIFormsPublicState = {
    submissions: new WeakMap(),
    mdInstanceAIForms: null,
  };
  window.aipkitForms_beginSubmission = (form, restore) => {
    const submissions = window.aipkitAIFormsPublicState.submissions;
    submissions.get(form)?.cancel();
    const controller = new AbortController();
    const request = {
      signal: controller.signal,
      source: null,
      finished: false,
      selectionPolicy: form.closest(".aipkit-ai-form-wrapper")?.dataset.selectionPolicy || "",
      isCurrent: () => submissions.get(form) === request && !controller.signal.aborted,
      isActive: () => request.isCurrent() && !request.finished,
      finish() {
        if (request.finished) return;
        request.finished = true;
        request.source?.close();
        restore();
      },
      cancel() {
        controller.abort();
        request.finish();
      },
    };
    submissions.set(form, request);
    return request;
  };
})();
