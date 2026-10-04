/**
 * AIPKit Content Writer - SEO Feature Gate
 * Normalizes SEO improvement settings and keeps Pro-only fields off on free plans.
 */
(function () {
  "use strict";

  const SEO_KEYS = {
    enabled: "seo_score_improvement_enabled",
    continueUntilTarget: "seo_score_continue_until_target",
    target: "seo_score_target",
    maxPasses: "seo_score_max_passes",
    profile: "seo_score_profile",
    disabledRules: "seo_score_disabled_rules",
  };

  function isProPlan() {
    return Boolean(window.aipkit_dashboard && window.aipkit_dashboard.isProPlan);
  }

  function normalizeBinary(value, fallback) {
    if (value === "1" || value === 1 || value === true) {
      return "1";
    }
    if (value === "0" || value === 0 || value === false) {
      return "0";
    }
    return fallback;
  }

  function normalizeDisabledRules(value) {
    const fallback =
      typeof window.aipkit_getDefaultSmartSeoDisabledRules === "function"
        ? window.aipkit_getDefaultSmartSeoDisabledRules()
        : "[]";
    if (typeof value !== "string" || !value.trim()) {
      return fallback;
    }
    try {
      return Array.isArray(JSON.parse(value)) ? value : fallback;
    } catch (error) {
      return fallback;
    }
  }

  function getFormField(form, key) {
    return form?.elements ? form.elements[key] : null;
  }

  function getBinaryFieldValue(field, fallback) {
    if (!field) {
      return fallback;
    }
    if (field.type === "checkbox") {
      return field.checked ? "1" : "0";
    }
    return normalizeBinary(field.value, fallback);
  }

  function normalizeContentWriterSeoConfig(config) {
    const next = config && typeof config === "object" ? { ...config } : {};

    next[SEO_KEYS.enabled] = normalizeBinary(next[SEO_KEYS.enabled], "0");
    next[SEO_KEYS.continueUntilTarget] = "1";
    next[SEO_KEYS.target] = "100";
    next[SEO_KEYS.maxPasses] = "3";
    next[SEO_KEYS.profile] = "auto";
    next[SEO_KEYS.disabledRules] = normalizeDisabledRules(
      next[SEO_KEYS.disabledRules]
    );

    if (!isProPlan()) {
      next[SEO_KEYS.enabled] = "0";
    }

    return next;
  }

  function readCurrentFormConfig(form) {
    return normalizeContentWriterSeoConfig({
      [SEO_KEYS.enabled]: getBinaryFieldValue(
        getFormField(form, SEO_KEYS.enabled),
        "0"
      ),
      [SEO_KEYS.continueUntilTarget]: getBinaryFieldValue(
        getFormField(form, SEO_KEYS.continueUntilTarget),
        "1"
      ),
      [SEO_KEYS.target]: getFormField(form, SEO_KEYS.target)?.value,
      [SEO_KEYS.maxPasses]: getFormField(form, SEO_KEYS.maxPasses)?.value,
      [SEO_KEYS.profile]: getFormField(form, SEO_KEYS.profile)?.value,
      [SEO_KEYS.disabledRules]: getFormField(form, SEO_KEYS.disabledRules)?.value,
    });
  }

  function setBinaryFieldValue(field, value) {
    if (!field) {
      return;
    }
    if (field.type === "checkbox") {
      field.checked = value === "1";
    } else {
      field.value = value;
    }
  }

  function setFieldValue(field, value) {
    if (!field) {
      return;
    }
    field.value = value;
  }

  function setSeoControlsDisabled(form, disabled) {
    form.querySelectorAll("[data-aipkit-seo-control]").forEach((control) => {
      if (control.type === "hidden") {
        control.disabled = false;
        delete control.dataset.aipkitProOnly;
        return;
      }
      control.disabled = disabled;
      if (disabled) {
        control.dataset.aipkitProOnly = "1";
      } else {
        delete control.dataset.aipkitProOnly;
      }
    });
  }

  function updateContentWriterSeoSettingsUI(form, config) {
    if (!form) {
      return;
    }

    const row = form.querySelector("[data-aipkit-seo-settings-row]");
    const pro = isProPlan();
    const enabled = pro && config?.[SEO_KEYS.enabled] === "1";

    if (row) {
      row.classList.toggle("is-enabled", enabled);
      row.classList.toggle("is-pro-locked", !pro);
    }

    const rulesAction = form.querySelector(
      "[data-aipkit-smart-seo-rules-action]"
    );
    if (rulesAction) {
      rulesAction.hidden = !enabled;
    }
  }

  function bindSeoSettingsListeners(form) {
    if (!form || form.dataset.aipkitSeoSettingsBound === "1") {
      return;
    }

    const refresh = (event) => {
      if (!event.target?.matches?.("[data-aipkit-seo-control]")) {
        return;
      }
      applyContentWriterSeoFeatureGate(form);
    };

    form.addEventListener("change", refresh);
    form.addEventListener("input", refresh);
    form.dataset.aipkitSeoSettingsBound = "1";
  }

  function applyContentWriterSeoFeatureGate(form) {
    if (!form) {
      return;
    }

    bindSeoSettingsListeners(form);

    const currentConfig = readCurrentFormConfig(form);
    const enabledField = getFormField(form, SEO_KEYS.enabled);
    const continueField = getFormField(form, SEO_KEYS.continueUntilTarget);
    const targetField = getFormField(form, SEO_KEYS.target);
    const maxPassesField = getFormField(form, SEO_KEYS.maxPasses);
    const profileField = getFormField(form, SEO_KEYS.profile);
    const disabledRulesField = getFormField(form, SEO_KEYS.disabledRules);

    setBinaryFieldValue(
      enabledField,
      currentConfig[SEO_KEYS.enabled]
    );
    setBinaryFieldValue(
      continueField,
      currentConfig[SEO_KEYS.continueUntilTarget]
    );
    setFieldValue(targetField, currentConfig[SEO_KEYS.target]);
    setFieldValue(maxPassesField, currentConfig[SEO_KEYS.maxPasses]);
    setFieldValue(profileField, currentConfig[SEO_KEYS.profile]);
    setFieldValue(disabledRulesField, currentConfig[SEO_KEYS.disabledRules]);

    setSeoControlsDisabled(form, !isProPlan());
    updateContentWriterSeoSettingsUI(form, currentConfig);
  }

  window.aipkit_normalizeContentWriterSeoConfig =
    normalizeContentWriterSeoConfig;
  window.aipkit_applyContentWriterSeoFeatureGate =
    applyContentWriterSeoFeatureGate;
})();
