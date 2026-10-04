import { markPreservedVectorStoreSelection } from '../utils/vector-store-selection-state.js';

const CACHE_MODULES = ['chatbot', 'sources', 'content-writer', 'autogpt', 'ai-forms'];

export function updateVectorStoreSelects(stores, config) {
    const storeList = Array.isArray(stores) ? stores : [];
    publishVectorStoreList(storeList, config);

    const targetSelectId = config.targetSelectId || config.defaultSelectId;
    const select = document.getElementById(targetSelectId);
    const selects = select ? [select] : Array.from(document.querySelectorAll(config.selectors));
    selects.forEach((selectElement) => {
        updateVectorStoreSelect(selectElement, storeList, config);
    });
    dispatchVectorStoreListUpdated(storeList, config);
}

function updateVectorStoreSelect(select, stores, config) {
    const oldValues = getExistingValues(select);
    const isMultiple = select.multiple;
    select.innerHTML = '';

    if (!isMultiple) {
        select.appendChild(new Option(config.defaultLabel, ''));
    }

    if (!stores.length) {
        select.appendChild(new Option(config.emptyLabel, ''));
        appendMissingOptions(select, oldValues, new Set());
        refreshCustomSelect(select, config, false);
        return;
    }

    const foundOldValues = new Set();
    const sortedStores = config.sortInPlace ? stores : stores.slice();
    sortedStores.sort((a, b) => {
        const aLabel = config.getSortLabel(a) || '';
        const bLabel = config.getSortLabel(b) || '';
        return aLabel.localeCompare(bLabel);
    });

    sortedStores.forEach((store) => {
        const value = config.getOptionValue(store);
        if (!value) {
            return;
        }

        const shouldSelect = oldValues.includes(value);
        const label = typeof config.getOptionLabel === 'function'
            ? config.getOptionLabel(store) || value
            : value;
        const option = new Option(label, value, false, shouldSelect);
        if (shouldSelect) {
            foundOldValues.add(value);
        }
        select.appendChild(option);
    });

    appendMissingOptions(select, oldValues, foundOldValues);
    refreshCustomSelect(select, config, true);
}

function getExistingValues(select) {
    const selectedValues = select.multiple
        ? Array.from(select.selectedOptions)
            .filter((option) => !option.disabled)
            .map((option) => option.value)
        : [select.value];
    const missingValues = Array.from(select.options || [])
        .filter((option) => option.disabled && option.value)
        .map((option) => option.value);

    return Array.from(new Set([...selectedValues, ...missingValues].filter(Boolean)));
}

function appendMissingOptions(select, oldValues, foundOldValues) {
    oldValues.forEach((value) => {
        if (
            value &&
            !foundOldValues.has(value) &&
            !select.querySelector(`option[value="${CSS.escape(value)}"]`)
        ) {
            const oldOption = new Option(`${value} (missing)`, value);
            oldOption.disabled = true;
            markPreservedVectorStoreSelection(oldOption);
            select.appendChild(oldOption);
        }
    });
}

function refreshCustomSelect(select, config, hasStores) {
    const callback = window[config.refreshCallbackName];
    if (typeof callback !== 'function') {
        return;
    }

    const settingsArea = findSettingsArea(
        select,
        hasStores ? config.refreshAreaSelectors : config.emptyRefreshAreaSelectors
    );
    callback(settingsArea);
}

function findSettingsArea(select, selectors = []) {
    for (const selector of selectors) {
        const area = select.closest(selector);
        if (area) {
            return area;
        }
    }
    return document;
}

function publishVectorStoreList(stores, config) {
    window.aipkit_chat_config = window.aipkit_chat_config || {};
    window.aipkit_chat_config[config.chatConfigKey] = stores;
    window.aipkit_vpp_config = window.aipkit_vpp_config || {};
    window.aipkit_vpp_config[config.vppConfigKey] = stores;
    window.aipkit_automated_tasks_config = window.aipkit_automated_tasks_config || {};
    window.aipkit_automated_tasks_config[config.automatedTasksConfigKey] = stores;
    window.aipkit_post_enhancer = window.aipkit_post_enhancer || {};
    window.aipkit_post_enhancer[config.postEnhancerConfigKey] = stores;

    if (typeof window.aipkit_invalidateModuleCache === 'function') {
        CACHE_MODULES.forEach((moduleName) => window.aipkit_invalidateModuleCache(moduleName));
    }

}

function dispatchVectorStoreListUpdated(stores, config) {
    if (typeof window.dispatchEvent === 'function') {
        window.dispatchEvent(
            new CustomEvent('aipkit:vector-store-list-updated', {
                detail: {
                    provider: config.provider,
                    stores,
                },
            })
        );
    }
}
