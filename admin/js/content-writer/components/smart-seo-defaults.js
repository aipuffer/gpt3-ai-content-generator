const DEFAULT_DISABLED_RULES_FALLBACK = "[]";
const DISABLED_RULES_INPUT_SELECTOR =
  'input[name="seo_score_disabled_rules"][data-aipkit-smart-seo-disabled-rules]';

window.aipkit_getDefaultSmartSeoDisabledRules = function () {
  const hidden = document.querySelector(DISABLED_RULES_INPUT_SELECTOR);
  return (
    hidden?.defaultValue ||
    hidden?.getAttribute("value") ||
    DEFAULT_DISABLED_RULES_FALLBACK
  );
};
