/** Shared assistant error actions and safe formatted output. */
(function () {
  "use strict";
  if (window.aipkit_assistantErrorAction) return;

  const uncertainCodes = new Set([
    "cloud_outcome_unknown", "already_dispatched_or_final", "idempotency_conflict",
    "gateway_aborted", "gateway_response_unverified",
  ]);
  const texts = () => window.aipkit_post_enhancer?.text || {};
  const isUncertain = (error) => {
    const data = error?.data || {};
    const code = data.provider_error_code || data.code || error?.code;
    return data.outcome_unknown || uncertainCodes.has(code)
      || ["dispatched", "reconciliation_pending", "settled"].includes(data.cloud_request_state)
      || ((!code || ["timeout", "aborted", "gateway_timeout", "invalid_html_response", "invalid_json_response"].includes(code))
        && window.aipkit_post_enhancer?.default_ai_provider === "AIPufferCloud");
  };

  window.aipkit_assistantErrorAction = (error) => {
    if (error?.aipkitOutcomeResolved) return "generate";
    if (isUncertain(error)) {
      return error?.data?.cloud_operation_id && error.data.request_status_nonce ? "check" : null;
    }
    return error?.data?.stop_batch ? null : "retry";
  };

  window.aipkit_assistantErrorMessage = (error) => {
    if (isUncertain(error) && !error?.data?.cloud_operation_id) {
      return texts().uncertain_request || "The response was interrupted. Credit usage may still be processing. Check your credits before starting another request.";
    }
    return error?.message || String(error || "");
  };

  window.aipkit_checkAssistantRequest = async (error) => {
    let message;
    let resolved = false;
    try {
      const result = await window.aipkit_apiRequest("aipkit_enhancer_request_status", {
        operation_id: error.data.cloud_operation_id,
        _ajax_nonce: error.data.request_status_nonce,
      });
      resolved = ["settled", "released"].includes(result.state);
      message = result.state === "settled"
        ? texts().request_settled || "The original request completed and its credit usage was recorded. Generating again starts a new request and uses credits."
        : result.state === "released"
        ? texts().request_released || "The original request finished without using credits. You can generate again."
        : texts().request_pending || "The original request is still being resolved. Check its status again before generating.";
    } catch (statusError) {
      message = statusError.message || texts().status_unavailable || "The request status could not be checked. Please check again.";
    }
    return Object.assign(new Error(message), { data: error.data, aipkitOutcomeResolved: resolved });
  };

  window.aipkit_sanitizeAssistantHtml = (value) => {
    const template = document.createElement("template");
    template.innerHTML = String(value || "");
    const allowedTags = new Set([
      "STRONG", "B", "EM", "I", "U", "A", "P", "BR", "H1", "H2", "H3",
      "H4", "H5", "H6", "UL", "OL", "LI", "BLOCKQUOTE", "PRE", "CODE",
    ]);
    const cleanse = (node) => {
      Array.from(node.childNodes).forEach((child) => {
        if (child.nodeType === 3) return;
        if (child.nodeType !== 1 || ["SCRIPT", "STYLE", "IFRAME", "OBJECT", "SVG", "MATH"].includes(child.tagName)) {
          child.remove();
          return;
        }
        cleanse(child);
        if (!allowedTags.has(child.tagName)) {
          child.replaceWith(...Array.from(child.childNodes));
          return;
        }
        Array.from(child.attributes).forEach(({ name, value: attributeValue }) => {
          const href = attributeValue.replace(/[\u0000-\u0020\u007f]/g, "");
          const safeHref = /^(https?:|mailto:|tel:)/i.test(href) || !/^[a-zA-Z][a-zA-Z0-9+.-]*:/.test(href);
          if (!(child.tagName === "A" && name === "href" && safeHref)) child.removeAttribute(name);
        });
      });
    };
    cleanse(template.content);
    return template.innerHTML;
  };
})();
