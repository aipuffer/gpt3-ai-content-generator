export function getBinaryFieldValue(field, defaultValue = "0") {
  if (!field) {
    return defaultValue;
  }

  if (field.type === "checkbox") {
    return field.checked ? "1" : "0";
  }

  return String(field.value) === "1" ? "1" : "0";
}

export function applyContentWriterSeoFormFields(data, form) {
  if (!data || !form) {
    return data;
  }

  data.seo_score_improvement_enabled = getBinaryFieldValue(
    form.elements["seo_score_improvement_enabled"],
    "0"
  );
  data.seo_score_continue_until_target = getBinaryFieldValue(
    form.elements["seo_score_continue_until_target"],
    "1"
  );
  data.seo_score_target = form.elements["seo_score_target"]?.value || "100";
  data.seo_score_max_passes =
    form.elements["seo_score_max_passes"]?.value || "3";
  data.seo_score_profile = form.elements["seo_score_profile"]?.value || "auto";
  data.seo_score_disabled_rules =
    form.elements["seo_score_disabled_rules"]?.value ||
    (typeof window.aipkit_getDefaultSmartSeoDisabledRules === "function"
      ? window.aipkit_getDefaultSmartSeoDisabledRules()
      : "[]");

  if (typeof window.aipkit_normalizeContentWriterSeoConfig === "function") {
    Object.assign(data, window.aipkit_normalizeContentWriterSeoConfig(data));
  }

  return data;
}

export function applyHostedKnowledgeStoreFields(data, form) {
  if (!data || !form) {
    return data;
  }

  const openaiVsSelect = form.elements["openai_vector_store_ids[]"];
  data.openai_vector_store_ids = openaiVsSelect
    ? Array.from(openaiVsSelect.selectedOptions).map((opt) => opt.value)
    : [];
  delete data["openai_vector_store_ids[]"];
  const googleStoresSelect = form.elements["google_file_search_store_names[]"];
  data.google_file_search_store_names = googleStoresSelect
    ? Array.from(googleStoresSelect.selectedOptions).map((option) => option.value)
    : [];
  delete data["google_file_search_store_names[]"];
  return data;
}
