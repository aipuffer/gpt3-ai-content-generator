(function () {
  "use strict";
  if (window.aipkit_cloudCreditNoticeBound) return;
  window.aipkit_cloudCreditNoticeBound = true;

  document.addEventListener("click", async (event) => {
    const button = event.target.closest("[data-cloud-credit-dismiss]");
    if (!button || button.disabled) return;
    const notice = button.closest(".aipkit_cloud_credit_notice");
    const feedback = notice.querySelector("[data-cloud-credit-feedback]");
    button.disabled = true;
    feedback.hidden = true;
    try {
      const data = new FormData();
      data.append("action", "aipkit_dismiss_cloud_credit_notice");
      data.append("_ajax_nonce", notice.dataset.cloudCreditNonce);
      data.append("key", notice.dataset.cloudCreditKey);
      data.append("fingerprint", notice.dataset.cloudCreditFingerprint);
      const response = await fetch(notice.dataset.cloudCreditUrl, { method: "POST", credentials: "same-origin", body: data });
      const result = await response.json();
      if (!response.ok || !result?.success) throw new Error(result?.data?.message || notice.dataset.cloudCreditError);
      notice.remove();
    } catch (error) {
      feedback.textContent = error.message;
      feedback.hidden = false;
    } finally {
      button.disabled = false;
    }
  });
})();
