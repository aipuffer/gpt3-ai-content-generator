/**
 * AIPKit - Shared Embedding Utilities
 *
 * Centralizes provider/model normalization and select population logic used
 * across Chatbot, AutoGPT, Sources, and vector workflows.
 */
(function () {
  "use strict";

  const DEFAULT_PROVIDER_MAP = {
    openai: "OpenAI",
    google: "Google",
    azure: "Azure",
    openrouter: "OpenRouter",
  };

  function asString(value) {
    return String(value || "").trim();
  }

  function normalizeProviderKey(value) {
    return asString(value).toLowerCase();
  }

  function normalizeModelRows(models) {
    const normalized = [];
    const seenModelIds = new Set();

    const pushRow = (row) => {
      if (!row || typeof row !== "object") {
        return;
      }

      const modelId = asString(row.id || row.name);
      if (!modelId || seenModelIds.has(modelId)) {
        return;
      }

      seenModelIds.add(modelId);
      normalized.push({
        ...row,
        id: modelId,
        name: asString(row.name || modelId),
      });
    };

    const walk = (entry) => {
      if (!entry) {
        return;
      }

      if (typeof entry === "string") {
        pushRow({ id: entry, name: entry });
        return;
      }

      if (Array.isArray(entry)) {
        entry.forEach(walk);
        return;
      }

      if (typeof entry !== "object") {
        return;
      }

      if (Array.isArray(entry.models)) {
        entry.models.forEach(walk);
        return;
      }

      if (Array.isArray(entry.items)) {
        entry.items.forEach(walk);
        return;
      }

      if (Array.isArray(entry.data)) {
        entry.data.forEach(walk);
        return;
      }

      if (
        Object.prototype.hasOwnProperty.call(entry, "id") ||
        Object.prototype.hasOwnProperty.call(entry, "name")
      ) {
        pushRow(entry);
        return;
      }

      Object.values(entry).forEach((value) => {
        if (
          typeof value === "string" ||
          Array.isArray(value) ||
          (value && typeof value === "object")
        ) {
          walk(value);
        }
      });
    };

    walk(models);
    return normalized;
  }

  function resolveModelsForProvider(modelsByProviderRaw, providerKey) {
    const normalizedKey = normalizeProviderKey(providerKey);
    if (!normalizedKey || !modelsByProviderRaw || typeof modelsByProviderRaw !== "object") {
      return [];
    }

    let providerModels = modelsByProviderRaw[normalizedKey];
    if (typeof providerModels === "undefined") {
      const matchedKey = Object.keys(modelsByProviderRaw).find(
        (key) => normalizeProviderKey(key) === normalizedKey
      );
      providerModels = matchedKey ? modelsByProviderRaw[matchedKey] : undefined;
    }

    return normalizeModelRows(providerModels || []);
  }

  function resolveProviderEntries(
    providerMapRaw,
    modelsByProviderRaw,
    fallbackProviderMap = DEFAULT_PROVIDER_MAP
  ) {
    const entriesFromMap =
      providerMapRaw && typeof providerMapRaw === "object"
        ? Object.entries(providerMapRaw)
            .map(([providerKey, providerLabel]) => {
              const key = normalizeProviderKey(providerKey);
              return {
                key,
                label: asString(providerLabel || providerKey),
              };
            })
            .filter((entry) => entry.key && entry.label)
        : [];

    if (entriesFromMap.length) {
      return entriesFromMap;
    }

    const fallbackMap =
      fallbackProviderMap && typeof fallbackProviderMap === "object"
        ? fallbackProviderMap
        : DEFAULT_PROVIDER_MAP;
    const normalizedFallbackMap = Object.entries(fallbackMap).reduce(
      (acc, [providerKey, providerLabel]) => {
        const key = normalizeProviderKey(providerKey);
        if (!key) {
          return acc;
        }
        acc[key] = asString(providerLabel || providerKey);
        return acc;
      },
      {}
    );

    const providerKeys =
      modelsByProviderRaw && typeof modelsByProviderRaw === "object"
        ? Object.keys(modelsByProviderRaw)
            .map((providerKey) => normalizeProviderKey(providerKey))
            .filter((providerKey) => providerKey !== "")
        : [];

    return Array.from(new Set(providerKeys)).map((providerKey) => ({
      key: providerKey,
      label: normalizedFallbackMap[providerKey] || providerKey,
    }));
  }

  function findOptionByValue(selectElement, value) {
    const targetValue = asString(value);
    if (!targetValue || !selectElement) {
      return null;
    }
    return (
      Array.from(selectElement.options).find(
        (option) => String(option.value) === targetValue
      ) || null
    );
  }

  function normalizeProviderEntries(providerEntries) {
    if (!Array.isArray(providerEntries)) {
      return [];
    }

    return providerEntries
      .map((entry) => {
        if (Array.isArray(entry)) {
          const key = normalizeProviderKey(entry[0]);
          return {
            key,
            label: asString(entry[1] || entry[0]),
          };
        }

        if (!entry || typeof entry !== "object") {
          return null;
        }

        const key = normalizeProviderKey(
          entry.key || entry.providerKey || entry.provider || ""
        );
        return {
          key,
          label: asString(entry.label || entry.name || key),
        };
      })
      .filter((entry) => entry && entry.key && entry.label);
  }

  function populateModelSelect(selectElement, options = {}) {
    if (!selectElement) {
      return { hasModels: false, matchedSelected: false, addedManual: false };
    }

    const escaper =
      typeof options.escaper === "function" ? options.escaper : (str) => str;
    const grouped = Boolean(options.grouped);
    const includePlaceholder = options.includePlaceholder !== false;
    const placeholderText = asString(options.placeholderText || "-- Select Model --");
    const emptyText = asString(options.emptyText || "No models found");
    const selectedValue = asString(options.selectedValue || "");
    const preserveUnknownSelected = options.preserveUnknownSelected !== false;
    const autoSelectFirst = Boolean(options.autoSelectFirst);
    const valueFormatter =
      typeof options.valueFormatter === "function"
        ? options.valueFormatter
        : (_, model) => asString(model?.id || "");
    const modelLabelFormatter =
      typeof options.modelLabelFormatter === "function"
        ? options.modelLabelFormatter
        : (model) => asString(model?.name || model?.id || "");

    const modelsByProviderRaw =
      options.modelsByProvider && typeof options.modelsByProvider === "object"
        ? options.modelsByProvider
        : {};

    selectElement.innerHTML = "";

    if (includePlaceholder) {
      const placeholder = new Option(escaper(placeholderText), "");
      if (options.placeholderHidden) {
        placeholder.hidden = true;
      }
      if (options.placeholderDisabled) {
        placeholder.disabled = true;
      }
      selectElement.appendChild(placeholder);
    }

    let hasModels = false;

    if (grouped) {
      const providerEntries = normalizeProviderEntries(options.providerEntries);
      providerEntries.forEach((providerEntry) => {
        const providerKey = providerEntry.key;
        const providerModels = resolveModelsForProvider(
          modelsByProviderRaw,
          providerKey
        );
        if (!providerModels.length) {
          return;
        }

        const group = document.createElement("optgroup");
        group.label = escaper(providerEntry.label);

        providerModels.forEach((model) => {
          const modelId = asString(model.id);
          if (!modelId) {
            return;
          }
          const optionValue = asString(valueFormatter(providerKey, model));
          if (!optionValue) {
            return;
          }
          const option = new Option(
            escaper(modelLabelFormatter(model, providerKey)),
            optionValue
          );
          option.dataset.provider = providerKey;
          option.dataset.model = modelId;
          group.appendChild(option);
        });

        if (group.children.length) {
          selectElement.appendChild(group);
          hasModels = true;
        }
      });
    } else {
      const providerKey = normalizeProviderKey(options.providerKey || "");
      const providerModels = resolveModelsForProvider(modelsByProviderRaw, providerKey);

      providerModels.forEach((model) => {
        const modelId = asString(model.id);
        if (!modelId) {
          return;
        }
        const optionValue = asString(valueFormatter(providerKey, model));
        if (!optionValue) {
          return;
        }
        const option = new Option(
          escaper(modelLabelFormatter(model, providerKey)),
          optionValue
        );
        option.dataset.provider = providerKey;
        option.dataset.model = modelId;
        selectElement.appendChild(option);
        hasModels = true;
      });
    }

    let matchedSelected = false;
    let addedManual = false;

    if (selectedValue) {
      const selectedOption = findOptionByValue(selectElement, selectedValue);
      if (selectedOption) {
        selectElement.value = selectedValue;
        matchedSelected = true;
      }
    }

    if (!matchedSelected && selectedValue && preserveUnknownSelected) {
      const unknownLabel = asString(options.unknownOptionLabel || selectedValue);
      const unknownOption = new Option(escaper(unknownLabel), selectedValue, false, true);
      const unknownProviderKey = normalizeProviderKey(
        options.unknownProviderKey || options.providerKey || "manual"
      );
      if (unknownProviderKey) {
        unknownOption.dataset.provider = unknownProviderKey;
      }
      selectElement.appendChild(unknownOption);
      matchedSelected = true;
      addedManual = true;
      hasModels = true;
    }

    if (!hasModels) {
      selectElement.innerHTML = "";
      selectElement.appendChild(new Option(escaper(emptyText), ""));
      selectElement.disabled = true;
      return { hasModels: false, matchedSelected: false, addedManual: false };
    }

    selectElement.disabled = false;

    if (!matchedSelected && autoSelectFirst) {
      const firstModelOption = Array.from(selectElement.options).find(
        (option) => !option.disabled && asString(option.value) !== ""
      );
      if (firstModelOption) {
        selectElement.value = firstModelOption.value;
      }
    }

    return { hasModels: true, matchedSelected, addedManual };
  }

  /** Refresh mounted embedding pickers without changing or saving their selection. */
  function syncProviderModels(providerKey, models, label) {
    providerKey = normalizeProviderKey(providerKey);
    const rows = normalizeModelRows(models);
    document.querySelectorAll('select[data-aipkit-universal-model-combined="1"]').forEach((select) => {
      if (!/embedding/.test(`${select.id} ${select.name}`)) return;
      const selected = select.selectedOptions[0];
      const previousValue = select.value;
      let previousModel = selected?.dataset.model || previousValue.split('::').slice(1).join('::');
      try { previousModel = decodeURIComponent(previousModel); } catch { /* Keep literal model IDs. */ }
      const previousProvider = normalizeProviderKey(selected?.dataset.provider || previousValue.split('::')[0]);
      Array.from(select.options).forEach((option) => {
        if (normalizeProviderKey(option.dataset.provider || option.value.split('::')[0]) === providerKey) option.remove();
      });
      select.querySelectorAll('optgroup').forEach((group) => {
        if (!group.children.length) group.remove();
      });
      if (rows.length) {
        const group = document.createElement('optgroup');
        group.label = label;
        for (const model of rows) {
          const option = new Option(model.name, `${providerKey}::${model.id}`);
          option.dataset.provider = providerKey;
          option.dataset.model = model.id;
          // Keep the caller's existing value encoding when restoring its selection.
          if (previousProvider === providerKey && previousModel === model.id) option.value = previousValue;
          group.appendChild(option);
        }
        select.appendChild(group);
      }
      if (previousValue && !Array.from(select.options).some((option) => option.value === previousValue)) {
        const unavailable = new Option(selected?.textContent || previousValue, previousValue);
        unavailable.dataset.provider = previousProvider;
        unavailable.dataset.model = previousModel;
        unavailable.disabled = true;
        unavailable.hidden = true;
        select.appendChild(unavailable);
      }
      select.value = previousValue;
      select.disabled = !Array.from(select.options).some((option) => option.value && !option.disabled);
    });
    document.querySelectorAll('select[name$="embedding_provider"]').forEach((select) => {
      const value = select.value;
      Array.from(select.options).filter((option) => option.value === providerKey).forEach((option) => option.remove());
      if (rows.length || value === providerKey) {
        const option = new Option(label, providerKey);
        option.disabled = !rows.length;
        option.hidden = !rows.length;
        select.appendChild(option);
      }
      select.value = value;
    });
  }

  window.aipkit_embedding_utils = {
    ...(window.aipkit_embedding_utils || {}),
    defaultProviderMap: { ...DEFAULT_PROVIDER_MAP },
    normalizeProviderKey,
    normalizeModelRows,
    resolveModelsForProvider,
    resolveProviderEntries,
    populateModelSelect,
    syncProviderModels,
  };
})();
