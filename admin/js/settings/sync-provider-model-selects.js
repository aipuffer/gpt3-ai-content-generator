import { selectFirstAvailableOption } from './sync-select-utils.js';

const translate = window.wp?.i18n?.__ || function(str) { return str; };

const flattenModels = (models) => {
    if (Array.isArray(models)) {
        return models.slice();
    }
    if (!models || typeof models !== 'object') {
        return [];
    }
    return Object.entries(models).flatMap(([familyLabel, familyModels], familyOrder) => {
        if (!Array.isArray(familyModels)) {
            return [];
        }
        return familyModels.map((model) => ({
            ...model,
            family_label: model?.family_label || familyLabel,
            family_order: model?.family_order ?? familyOrder,
        }));
    });
};

const decorateOption = (option, model, recommendedIds) => {
    const familyLabel = String(
        model?.family_label || translate('Other', 'gpt3-ai-content-generator')
    );
    option.dataset.recommended = model?.recommended || recommendedIds.has(model?.id)
        ? 'true'
        : 'false';
    option.dataset.familyKey = String(model?.family_key || 'other');
    option.dataset.familyLabel = familyLabel;
    option.dataset.familyOrder = String(model?.family_order ?? 999);
    option.dataset.familyCollapsed = model?.family_collapsed ? 'true' : 'false';
};

export function updateProviderModelSelects(models, config) {
    const {
        dashboardModelKey,
        selectId,
        chatSelectSuffix,
        warningLabel,
        preserveMissingOldValue = true,
        suppressMissingSelectWarning = false,
        afterUpdateOne = null,
        matchesOldValue = (modelId, oldValue) => modelId === oldValue,
        getModelLabel = (model) => model?.name || model?.id || '',
        getMissingOldOption = (oldValue) => ({
            value: oldValue,
            label: oldValue,
            queryValue: oldValue,
        }),
    } = config;

    const list = flattenModels(models);
    if (window.aipkit_dashboard) {
        window.aipkit_dashboard.models = window.aipkit_dashboard.models || {};
        window.aipkit_dashboard.models[dashboardModelKey] = models;
    }

    const select = document.getElementById(selectId);
    const chatSelects = document.querySelectorAll(`select[id^="aipkit_bot_"][id$="${chatSelectSuffix}"]`);
    if (!select && chatSelects.length === 0) {
        if (!suppressMissingSelectWarning) {
            console.warn(`${warningLabel} Sync: No ${warningLabel} model selects found (#${selectId} or chat selects).`);
        }
        return;
    }

    const recommendedModels = window.aipkit_dashboard?.recommendedModels?.[dashboardModelKey] || [];
    const recommendedIds = new Set(
        (Array.isArray(recommendedModels) ? recommendedModels : [])
            .map((model) => model?.id)
            .filter(Boolean)
    );

    const families = new Map();
    list.forEach((model) => {
        const modelId = model?.id || '';
        if (!modelId) {
            return;
        }
        const familyKey = String(model?.family_key || 'other');
        if (!families.has(familyKey)) {
            families.set(familyKey, {
                key: familyKey,
                label: String(model?.family_label || translate('Other', 'gpt3-ai-content-generator')),
                order: Number(model?.family_order ?? 999),
                collapsed: Boolean(model?.family_collapsed),
                models: [],
            });
        }
        families.get(familyKey).models.push(model);
    });
    const orderedFamilies = Array.from(families.values()).sort(
        (first, second) =>
            first.order - second.order || first.label.localeCompare(second.label)
    );
    orderedFamilies.forEach((family) => {
        family.models.sort((first, second) =>
            Number(Boolean(second?.recommended || recommendedIds.has(second?.id))) -
                Number(Boolean(first?.recommended || recommendedIds.has(first?.id))) ||
            String(first?.name || first?.id || '').localeCompare(
                String(second?.name || second?.id || ''),
                undefined,
                { numeric: true, sensitivity: 'base' }
            )
        );
    });

    const updateOne = (sel) => {
        const oldValue = sel.value;
        sel.innerHTML = '';
        let foundOldValue = false;

        orderedFamilies.forEach((family) => {
            const optgroup = document.createElement('optgroup');
            optgroup.label = family.label;
            optgroup.dataset.familyKey = family.key;
            family.models.forEach((model) => {
                const modelId = model?.id || '';
                if (!modelId) {
                    return;
                }
                const option = new Option(getModelLabel(model), modelId);
                decorateOption(option, model, recommendedIds);
                if (matchesOldValue(modelId, oldValue)) {
                    option.selected = true;
                    foundOldValue = true;
                }
                optgroup.appendChild(option);
            });
            if (optgroup.children.length > 0) {
                sel.appendChild(optgroup);
            }
        });

        if (!sel.options.length) {
            if (preserveMissingOldValue && oldValue) {
                const missingOldOption = getMissingOldOption(oldValue);
                const oldOption = new Option(
                    String(missingOldOption.label || oldValue),
                    String(missingOldOption.value || oldValue),
                    false,
                    true
                );
                oldOption.dataset.familyKey = 'other';
                oldOption.dataset.familyLabel = translate('Other', 'gpt3-ai-content-generator');
                oldOption.dataset.familyOrder = '999';
                oldOption.dataset.familyCollapsed = 'true';
                sel.appendChild(oldOption);
                sel.value = String(missingOldOption.value || oldValue);
            } else {
                sel.appendChild(new Option('No models found.', ''));
            }
            if (typeof afterUpdateOne === 'function') {
                afterUpdateOne(sel, oldValue);
            }
            return;
        }

        if (preserveMissingOldValue && !foundOldValue && oldValue) {
            const missingOldOption = getMissingOldOption(oldValue);
            if (!sel.querySelector(`option[value="${CSS.escape(missingOldOption.queryValue)}"]`)) {
                const oldOption = new Option(
                    String(missingOldOption.label || oldValue),
                    String(missingOldOption.value || oldValue),
                    false,
                    true
                );
                oldOption.dataset.familyKey = 'other';
                oldOption.dataset.familyLabel = translate('Other', 'gpt3-ai-content-generator');
                oldOption.dataset.familyOrder = '999';
                oldOption.dataset.familyCollapsed = 'true';
                sel.insertBefore(oldOption, sel.firstChild);
                sel.value = String(missingOldOption.value || oldValue);
            }
        }

        if (sel.selectedIndex === -1) {
            selectFirstAvailableOption(sel);
        }
        if (typeof afterUpdateOne === 'function') {
            afterUpdateOne(sel, oldValue);
        }
    };

    if (select) {
        updateOne(select);
    }
    chatSelects.forEach(updateOne);
}
