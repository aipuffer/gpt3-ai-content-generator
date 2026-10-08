/**
 * AIPKit Settings - Autosave Logic
 * Handles the core logic of checking for changes and triggering the save AJAX request.
 */
(function() {
    'use strict';

    const savedByPage = new WeakMap();
    const pendingByPage = new Map();
    let inFlightPage = null;
    window.aipkit_isSaving = false;
    window.aipkit_currentSavePromise = null;
    window.aipkit_lastSavedData = null;

    function getDataScope() {
        return (typeof window.aipkit_getSettingsDataScope === 'function' && window.aipkit_getSettingsDataScope())
            || document.getElementById('aipkit_settings_container');
    }

    function aipkit_updateLastSavedData(initializeOnly = false) {
        const page = getDataScope();
        if (!page || typeof window.aipkit_getCurrentFormData !== 'function') return;
        if (!savedByPage.has(page) || (!initializeOnly && inFlightPage !== page && !pendingByPage.has(page))) {
            savedByPage.set(page, window.aipkit_getCurrentFormData());
        }
        window.aipkit_lastSavedData = savedByPage.get(page);
    }

    // A saved key loaded into its field is already saved: it joins the page's saved state, so looking at it saves nothing.
    function aipkit_markSettingsFieldSaved(input) {
        const page = input && input.closest ? input.closest('.aipkit_settings_scope[data-aipkit-settings-page]') : null;
        if (!page || !input.name || !savedByPage.has(page) || typeof window.aipkit_getCurrentFormData !== 'function') return;
        const previous = savedByPage.get(page);
        const current = window.aipkit_getCurrentFormData({ root: page });
        const next = {};
        // Field order follows the form, as each save's data does, so the two still compare equal.
        Object.keys(current).forEach((key) => {
            next[key] = key === input.name || !Object.prototype.hasOwnProperty.call(previous, key) ? current[key] : previous[key];
        });
        savedByPage.set(page, next);
        if (getDataScope() === page) window.aipkit_lastSavedData = next;
    }

    // Every section is on screen at once, so each one gets its starting point when the page opens.
    function aipkit_initSettingsSavedData() {
        const scopes = typeof window.aipkit_getSettingsDataScopes === 'function' ? window.aipkit_getSettingsDataScopes() : [];
        scopes.forEach((scope) => {
            if (!savedByPage.has(scope) && typeof window.aipkit_getCurrentFormData === 'function') {
                savedByPage.set(scope, window.aipkit_getCurrentFormData({ root: scope }));
            }
        });
        aipkit_updateLastSavedData(true);
    }

    function getDefaultAutosaveScope() {
        const activeModalContent = document.querySelector(
            '#aipkit_settings_container .aipkit-modal-overlay.aipkit-active .aipkit-modal-content'
        );
        if (activeModalContent) {
            return activeModalContent;
        }

        return getDataScope();
    }

    function createAutosaveOverlay(scope) {
        if (!scope) {
            return null;
        }

        let overlay = scope.querySelector(':scope > .aipkit_settings_autosave_overlay');
        if (overlay) {
            return overlay;
        }

        overlay = document.createElement('div');
        overlay.className = 'aipkit_settings_autosave_overlay';
        overlay.setAttribute('aria-hidden', 'true');
        overlay.setAttribute('aria-label', 'Saving settings');
        overlay.setAttribute('role', 'status');
        overlay.hidden = true;

        const spinner = document.createElement('span');
        spinner.className = 'aipkit_settings_autosave_overlay_spinner';
        spinner.setAttribute('aria-hidden', 'true');
        overlay.appendChild(spinner);

        scope.appendChild(overlay);
        return overlay;
    }

    function aipkit_setSettingsAutosaveBusy(isBusy, scope) {
        const targetScope = scope instanceof Element ? scope : getDefaultAutosaveScope();
        if (!targetScope) {
            return;
        }

        const overlay = createAutosaveOverlay(targetScope);
        if (!overlay) {
            return;
        }

        const parsedCount = parseInt(
            targetScope.dataset.aipkitAutosaveBusyCount || '0',
            10
        );
        const currentCount = Number.isFinite(parsedCount) ? parsedCount : 0;
        const nextCount = isBusy
            ? currentCount + 1
            : Math.max(0, currentCount - 1);
        targetScope.dataset.aipkitAutosaveBusyCount = String(nextCount);

        const active = nextCount > 0;
        targetScope.classList.toggle('aipkit_settings_autosave_busy_scope', active);
        overlay.hidden = !active;
        overlay.setAttribute('aria-hidden', active ? 'false' : 'true');
    }

    function clearSettingsAutosaveMessages(containerId) {
        if (typeof window.aipkit_clearStatusMessages === 'function') {
            window.aipkit_clearStatusMessages(containerId);
        }
    }

    function getSaveErrorMessage(error) {
        const message = error && error.message ? error.message : 'Unknown error.';
        return 'Save failed: ' + message;
    }

    /** Capture changes before navigation and serialize saves across Settings pages. */
    function aipkit_handleAutoSave() {
        if (typeof window.aipkit_showMessage !== 'function' ||
            typeof window.aipkit_apiRequest !== 'function' ||
            typeof window.aipkit_getCurrentFormData !== 'function') {
            return Promise.resolve(false);
        }
        const page = getDataScope();
        if (!page) return Promise.resolve(false);
        const data = window.aipkit_getCurrentFormData();
        if (inFlightPage !== page && JSON.stringify(data) === JSON.stringify(savedByPage.get(page))) {
            pendingByPage.delete(page);
            return window.aipkit_currentSavePromise || Promise.resolve(true);
        }
        pendingByPage.set(page, { page, data, scope: getDefaultAutosaveScope() });
        if (window.aipkit_currentSavePromise) return window.aipkit_currentSavePromise;

        window.aipkit_isSaving = true;
        window.aipkit_currentSavePromise = Promise.resolve().then(async () => {
            let saved = true;
            const messageContainerId = 'aipkit_settings_global_messages';
            clearSettingsAutosaveMessages(messageContainerId);
            try {
                while (pendingByPage.size) {
                    const [key, request] = pendingByPage.entries().next().value;
                    pendingByPage.delete(key);
                    const { page, data, scope } = request;
                    const previousData = savedByPage.get(page) || {};
                    if (JSON.stringify(data) === JSON.stringify(previousData)) continue;
                    inFlightPage = page;
                    aipkit_setSettingsAutosaveBusy(true, scope);
                    try {
                        const response = await window.aipkit_apiRequest('aipkit_save_ai_settings', data);
                        savedByPage.set(page, data);
                        if (getDataScope() === page) window.aipkit_lastSavedData = data;
                        if (response?.providerConnectionStates && typeof window.aipkit_updateModelRegistryStates === 'function') {
                            window.aipkit_updateModelRegistryStates(response.providerConnectionStates);
                        }
                        if (typeof window.aipkit_syncProviderStatusFromSettings === 'function') {
                            window.aipkit_syncProviderStatusFromSettings();
                        }
                        if (typeof window.aipkit_queueProviderAutoSync === 'function') {
                            window.aipkit_queueProviderAutoSync(previousData, data);
                        }
                    } catch (error) {
                        saved = false;
                        window.aipkit_showMessage(messageContainerId, 'error', getSaveErrorMessage(error));
                        console.error('AIPKit Auto-Save Error:', error);
                    } finally {
                        aipkit_setSettingsAutosaveBusy(false, scope);
                        inFlightPage = null;
                    }
                }
            } finally {
                window.aipkit_isSaving = false;
                window.aipkit_currentSavePromise = null;
            }
            return saved;
        });
        return window.aipkit_currentSavePromise;
    }

    // Expose the handler globally
    window.aipkit_updateLastSavedData = aipkit_updateLastSavedData;
    window.aipkit_initSettingsSavedData = aipkit_initSettingsSavedData;
    window.aipkit_markSettingsFieldSaved = aipkit_markSettingsFieldSaved;
    window.aipkit_handleAutoSave = aipkit_handleAutoSave;
    window.aipkit_setSettingsAutosaveBusy = aipkit_setSettingsAutosaveBusy;

})();
