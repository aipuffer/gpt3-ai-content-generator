const PRESERVED_SELECTION_DATA_KEY = "aipkitPreservedSelection";

const getOptions = (selectElement) =>
  Array.from(selectElement?.options || []);

const getOptionValue = (option) => String(option?.value || "").trim();

export const isPreservedVectorStoreSelection = (option) =>
  option?.dataset?.[PRESERVED_SELECTION_DATA_KEY] === "1";

export const markPreservedVectorStoreSelection = (option) => {
  if (!option) {
    return option;
  }
  option.dataset[PRESERVED_SELECTION_DATA_KEY] = "1";
  option.selected = true;
  return option;
};

export const clearPreservedVectorStoreSelections = (selectElement) => {
  getOptions(selectElement)
    .filter(isPreservedVectorStoreSelection)
    .forEach((option) => option.remove());
};

export const syncVectorStoreSelectionValues = (selectElement, values) => {
  const selectedValues = new Set(
    (Array.isArray(values) ? values : [values])
      .map((value) => String(value ?? "").trim())
      .filter(Boolean)
  );

  getOptions(selectElement).forEach((option) => {
    const optionValue = getOptionValue(option);
    const shouldSelect = selectedValues.has(optionValue);
    if (isPreservedVectorStoreSelection(option) && !shouldSelect) {
      option.remove();
      return;
    }
    option.selected = shouldSelect;
  });
};

export const applyConfiguredVectorStoreValues = (
  selectElement,
  values,
  { missingLabelSuffix = " (missing)" } = {}
) => {
  if (!selectElement) {
    return;
  }
  const configuredValues = Array.from(
    new Set(
      (Array.isArray(values) ? values : [values])
        .map((value) => String(value ?? "").trim())
        .filter(Boolean)
    )
  );
  clearPreservedVectorStoreSelections(selectElement);

  const matchedValues = new Set();
  getOptions(selectElement).forEach((option) => {
    const optionValue = getOptionValue(option);
    const shouldSelect = configuredValues.includes(optionValue);
    option.selected = shouldSelect;
    if (shouldSelect) {
      matchedValues.add(optionValue);
    }
  });

  configuredValues.forEach((value) => {
    if (matchedValues.has(value)) {
      return;
    }
    const option = selectElement.ownerDocument.createElement("option");
    option.value = value;
    option.textContent = `${value}${missingLabelSuffix}`;
    option.disabled = true;
    markPreservedVectorStoreSelection(option);
    selectElement.appendChild(option);
  });
};

/**
 * Returns the saved target even while the provider's live inventory is still
 * loading. Preserved disabled options are server-known configuration, not a
 * statement that the remote store has been deleted.
 */
export const getConfiguredVectorStoreValues = (selectElement) => {
  const values = getOptions(selectElement)
    .filter(
      (option) =>
        getOptionValue(option) &&
        ((option.selected && !option.disabled) ||
          isPreservedVectorStoreSelection(option))
    )
    .map(getOptionValue);

  return Array.from(new Set(values));
};

export const getConfiguredVectorStoreValue = (selectElement) =>
  getConfiguredVectorStoreValues(selectElement)[0] || "";
