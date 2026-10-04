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
        return document.querySelector('#aipkit_settings_container [data-aipkit-settings-page]:not([hidden])')
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

    function getDefaultAutosaveScope() {
        const activeModalContent = document.querySelector(
            '#aipkit_settings_container .aipkit-modal-overlay.aipkit-active .aipkit-modal-content'
        );
        if (activeModalContent) {
            return activeModalContent;
        }

        const activeSettingsPage = document.querySelector(
            '#aipkit_settings_container .aipkit_settings_page_section:not([hidden])'
        );
        if (activeSettingsPage) {
            return activeSettingsPage;
        }

        return document.querySelector('#aipkit_settings_container .aipkit_settings_pages_shell');
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
            return Promise.resolve();
        }
        const page = getDataScope();
        if (!page) return Promise.resolve();
        const data = window.aipkit_getCurrentFormData();
        if (inFlightPage !== page && JSON.stringify(data) === JSON.stringify(savedByPage.get(page))) {
            pendingByPage.delete(page);
            return window.aipkit_currentSavePromise || Promise.resolve();
        }
        pendingByPage.set(page, { page, data, scope: getDefaultAutosaveScope() });
        if (window.aipkit_currentSavePromise) return window.aipkit_currentSavePromise;

        window.aipkit_isSaving = true;
        window.aipkit_currentSavePromise = Promise.resolve().then(async () => {
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
        });
        return window.aipkit_currentSavePromise;
    }

    // Expose the handler globally
    window.aipkit_updateLastSavedData = aipkit_updateLastSavedData;
    window.aipkit_handleAutoSave = aipkit_handleAutoSave;
    window.aipkit_setSettingsAutosaveBusy = aipkit_setSettingsAutosaveBusy;

})();
