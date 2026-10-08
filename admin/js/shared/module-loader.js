/**
 * AIPKit Dashboard - Module Loader
 *
 * Loads modules asynchronously via AJAX and inserts the returned content
 * into the main container. Dynamically calls the module's initializer function.
 * REVISED: Uses a map for module initializers to improve clarity and reduce repetition.
 */
(function() {
    'use strict';

    // Pages usually load well under a second, so loading feedback waits a second; a quicker indicator only flashes.
    const MODULE_LOADING_DELAY_MS = 1000;
    const MODULE_REFRESH_DELAY_MS = 300;
    const MODULE_CACHE_TTL_MS = 5 * 60 * 1000;
    const moduleHtmlCache = new Map();
    const modulePrefetchPromises = new Map();
    const moduleCacheGenerations = new Map();
    const moduleScriptPromises = new Map();
    const moduleStylePromises = new Map();
    let hasPrimedNavigationCache = false;

    /**
     * Map of module slugs to their JavaScript initializer function names.
     * This centralizes the mapping and makes adding new modules cleaner.
     */
    const moduleInitializers = {
        'settings': 'aipkit_initSettings',
        'chatbot': 'aipkit_initChatbot',
        'stats': 'aipkit_initStats',
        'image-generator': 'aipkit_initImageGenerator',
        'sources': 'aipkit_initSources',
        'ai-forms': 'aipkit_initAiForms',
        'autogpt': 'aipkit_initAutogpt', // General autogpt module initializer
        'content-writer': 'aipkit_initContentWriter', // Content writer module initializer
    };

    const LAST_MODULE_STORAGE_KEY = 'aipkit_last_module';
    const applyModuleLayoutState = (moduleContainer, moduleName) => {
        if (!moduleContainer) {
            return;
        }

        const isSettingsModule = moduleName === 'settings';
        moduleContainer.classList.toggle('aipkit_module-container--settings-active', isSettingsModule);

        const wrap = moduleContainer.closest('.aipkit_wrap');
        if (wrap) {
            wrap.classList.toggle('aipkit_wrap--settings-active', isSettingsModule);
        }
    };

    const renderModuleMarkup = (moduleContainer, html) => {
        moduleContainer.innerHTML = html;
        const notices = Array.from(moduleContainer.children).filter(
            element => element.classList.contains('aipkit_notification_bar')
        );
        moduleContainer.classList.toggle('aipkit_main-content--with-notices', notices.length > 0);
        if (!notices.length) return;

        // Module notices stay above the workspace, in the same gutters (shared/base.css), as one stack.
        const workspace = document.createElement('div');
        workspace.className = 'aipkit_module-workspace';
        Array.from(moduleContainer.childNodes).forEach(node => {
            if (!notices.includes(node)) workspace.append(node);
        });
        moduleContainer.append(workspace);
    };

    const buildModuleCacheKey = (moduleName, extraData = {}) => {
        const keys = Object.keys(extraData || {}).sort();
        if (!keys.length) {
            return `${moduleName}::default`;
        }
        const normalized = {};
        keys.forEach(key => {
            normalized[key] = extraData[key];
        });
        return `${moduleName}::${JSON.stringify(normalized)}`;
    };

    const getModuleCacheGeneration = (moduleName) => {
        return moduleCacheGenerations.get(moduleName) || 0;
    };

    const bumpModuleCacheGeneration = (moduleName) => {
        const nextGeneration = getModuleCacheGeneration(moduleName) + 1;
        moduleCacheGenerations.set(moduleName, nextGeneration);
        return nextGeneration;
    };

    const getCachedModuleEntry = (cacheKey) => {
        if (!moduleHtmlCache.has(cacheKey)) {
            return null;
        }
        const entry = moduleHtmlCache.get(cacheKey);
        if (!entry || typeof entry.html !== 'string') {
            moduleHtmlCache.delete(cacheKey);
            return null;
        }
        if (Date.now() - (entry.timestamp || 0) > MODULE_CACHE_TTL_MS) {
            moduleHtmlCache.delete(cacheKey);
            return null;
        }
        return entry;
    };

    const storeModuleCacheEntry = (cacheKey, html, moduleName = '', expectedGeneration = null) => {
        if (!cacheKey || typeof html !== 'string') {
            return;
        }
        if (
            moduleName &&
            expectedGeneration !== null &&
            expectedGeneration !== getModuleCacheGeneration(moduleName)
        ) {
            return;
        }
        moduleHtmlCache.set(cacheKey, {
            html,
            timestamp: Date.now(),
        });
    };

    const primeNavigationModuleCache = (currentModule) => {
        if (hasPrimedNavigationCache || typeof window.aipkit_apiRequest !== 'function') {
            return;
        }
        hasPrimedNavigationCache = true;

        const visibleModules = Array.from(document.querySelectorAll('.aipkit_module-link'))
            .filter(link => link && link.offsetParent !== null)
            .map(link => String(link.getAttribute('data-module') || '').trim())
            .filter(moduleName => moduleName && moduleName !== currentModule);

        if (!visibleModules.length) {
            return;
        }

        const schedule =
            typeof window.requestIdleCallback === 'function'
                ? window.requestIdleCallback.bind(window)
                : (callback) => setTimeout(callback, 400);

        const runPrefetch = (index = 0) => {
            if (index >= visibleModules.length) {
                return;
            }
            const moduleName = visibleModules[index];
            const requestData = { module: moduleName };

            if (moduleName === 'chatbot') {
                try {
                    const savedChatbotId = localStorage.getItem('aipkit_last_selected_chatbot');
                    if (savedChatbotId) {
                        requestData.force_active_bot_id = savedChatbotId;
                    }
                } catch (error) {
                    // Ignore storage access failures.
                }
                try {
                    const params = new URLSearchParams(window.location.search);
                    if (params.get('aipkit_chatbot_layout') === 'next') {
                        requestData.layout = 'next';
                    }
                } catch (error) {
                    // Ignore URL parsing failures.
                }
            }

            const cacheData = { ...requestData };
            delete cacheData.module;
            const cacheKey = buildModuleCacheKey(moduleName, cacheData);
            const generationAtRequest = getModuleCacheGeneration(moduleName);

            if (getCachedModuleEntry(cacheKey) || modulePrefetchPromises.has(cacheKey)) {
                schedule(() => runPrefetch(index + 1));
                return;
            }

            const prefetchPromise = window
                .aipkit_apiRequest('aipkit_dashboard_load_module', requestData)
                .then((responseData) => {
                    if (responseData && typeof responseData.html === 'string') {
                        storeModuleCacheEntry(cacheKey, responseData.html, moduleName, generationAtRequest);
                    }
                })
                .catch(() => {})
                .finally(() => {
                    modulePrefetchPromises.delete(cacheKey);
                    schedule(() => runPrefetch(index + 1));
                });

            modulePrefetchPromises.set(cacheKey, prefetchPromise);
        };

        schedule(() => runPrefetch(0));
    };

    const createModuleLoading = () => {
        const loading = document.createElement('div');
        loading.className = 'aipkit_module-loading';
        loading.setAttribute('role', 'status');
        loading.textContent = window.aipkit_dashboard?.text?.loading || 'Loading…';
        return loading;
    };

    const rememberLastVisitedModule = (moduleName) => {
        if (!moduleName || moduleName === 'settings') {
            return;
        }

        try {
            localStorage.setItem(LAST_MODULE_STORAGE_KEY, moduleName);
        } catch (error) {
            // Ignore storage failures (privacy mode, quota, etc.).
        }
    };

    const getModuleAssetUrls = (moduleName, configKey) => {
        const moduleAssets = window.aipkit_dashboard && window.aipkit_dashboard[configKey];
        if (!moduleName || !moduleAssets || !Object.prototype.hasOwnProperty.call(moduleAssets, moduleName)) {
            return [];
        }

        const configuredAssets = Array.isArray(moduleAssets[moduleName])
            ? moduleAssets[moduleName]
            : [moduleAssets[moduleName]];

        return configuredAssets.filter(url => typeof url === 'string' && url.trim() !== '');
    };

    const loadModuleStyle = (styleUrl, moduleName) => {
        if (moduleStylePromises.has(styleUrl)) {
            return moduleStylePromises.get(styleUrl);
        }

        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = styleUrl;
        link.dataset.aipkitModuleStyle = styleUrl;
        const promise = new Promise((resolve, reject) => {
            link.onload = resolve;
            link.onerror = () => reject(new Error(`Failed to load module stylesheet: ${styleUrl}`));

            // Extend the module's own base styles, before later site overrides.
            let styleAnchor = document.getElementById(`aipkit-admin-${moduleName}-css-css`)
                || document.getElementById('aipkit-admin-main-css-css');
            while (styleAnchor?.nextSibling?.dataset?.aipkitModuleStyle) {
                styleAnchor = styleAnchor.nextSibling;
            }
            const target = styleAnchor?.parentNode || document.head || document.body;
            if (!target) {
                reject(new Error('No document target available for module stylesheet.'));
                return;
            }
            target.insertBefore(link, styleAnchor?.nextSibling || null);
        }).catch(error => {
            moduleStylePromises.delete(styleUrl);
            link.remove();
            throw error;
        });

        moduleStylePromises.set(styleUrl, promise);
        return promise;
    };

    const loadModuleStyles = async (moduleName) => {
        await Promise.all(getModuleAssetUrls(moduleName, 'moduleStyles').map(url => loadModuleStyle(url, moduleName)));
    };

    const loadModuleScript = (scriptUrl) => {
        if (moduleScriptPromises.has(scriptUrl)) {
            return moduleScriptPromises.get(scriptUrl);
        }

        const scriptPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = scriptUrl;
            script.async = true;
            script.dataset.aipkitModuleScript = scriptUrl;

            script.onload = () => {
                resolve();
            };
            script.onerror = () => {
                moduleScriptPromises.delete(scriptUrl);
                reject(new Error(`Failed to load module script: ${scriptUrl}`));
            };

            const scriptTarget = document.head || document.body;
            if (!scriptTarget) {
                reject(new Error('No document target available for module script.'));
                return;
            }

            scriptTarget.appendChild(script);
        });

        moduleScriptPromises.set(scriptUrl, scriptPromise);
        return scriptPromise;
    };

    const loadModuleScripts = async (moduleName) => {
        const scriptUrls = getModuleAssetUrls(moduleName, 'moduleScripts');
        if (!scriptUrls.length) {
            return;
        }

        await Promise.all(scriptUrls.map(loadModuleScript));
    };

    const flushSettingsAutosaveBeforeModuleChange = async (targetModuleName) => {
        const settingsContainer = document.getElementById('aipkit_settings_container');
        if (!settingsContainer || targetModuleName === 'settings') {
            return;
        }

        const activeElement = document.activeElement;
        if (
            activeElement instanceof HTMLElement &&
            settingsContainer.contains(activeElement)
        ) {
            activeElement.blur();
        }

        if (typeof window.aipkit_handleAutoSave === 'function') {
            await window.aipkit_handleAutoSave();
        }
    };


    /**
     * Load modules (or settings) via AJAX into #aipkit_module-container
     *
     * @param {string}  aipkit_moduleName    The folder name of the module to load (e.g., 'settings', 'chatbot')
     * @param {object|function} [dataOrCallback] If an object, merges into AJAX data. If a function, used as afterLoadCallback.
     * @param {function} [afterLoadCallback]  Optional callback function invoked after successful load
     * @returns {Promise<void>} A promise that resolves on success or rejects on error.
     */
    async function aipkit_loadModule(aipkit_moduleName, dataOrCallback, afterLoadCallback) {
        let extraData = {};
        if (typeof dataOrCallback === 'function') {
            afterLoadCallback = dataOrCallback;
        } else if (typeof dataOrCallback === 'object' && dataOrCallback !== null) {
            extraData = { ...dataOrCallback };
        }

        const isSilentLoad = extraData.aipkit_silent === true;
        if (Object.prototype.hasOwnProperty.call(extraData, 'aipkit_silent')) {
            delete extraData.aipkit_silent;
        }

        const forceRefresh = extraData.aipkit_force_refresh === true;
        if (Object.prototype.hasOwnProperty.call(extraData, 'aipkit_force_refresh')) {
            delete extraData.aipkit_force_refresh;
        }

        const bypassLeaveGuard = extraData.aipkit_bypass_leave_guard === true;
        if (Object.prototype.hasOwnProperty.call(extraData, 'aipkit_bypass_leave_guard')) {
            delete extraData.aipkit_bypass_leave_guard;
        }

        await flushSettingsAutosaveBeforeModuleChange(aipkit_moduleName);

        if (!bypassLeaveGuard && typeof window.aipkit_beforeModuleChange === 'function') {
            try {
                const canLeaveCurrentModule = await window.aipkit_beforeModuleChange(aipkit_moduleName);
                if (canLeaveCurrentModule === false) {
                    if (typeof afterLoadCallback === 'function') {
                        afterLoadCallback(new Error('Module switch cancelled.'));
                    }
                    return Promise.resolve();
                }
            } catch (guardError) {
                console.error('AIPKit Loader: Module leave guard failed.', guardError);
                if (typeof afterLoadCallback === 'function') {
                    afterLoadCallback(guardError);
                }
                return Promise.reject(guardError);
            }
        }

        if (aipkit_moduleName === 'chatbot' && !extraData.force_active_bot_id && !extraData.force_active_tab) {
            try {
                const savedChatbotId = localStorage.getItem('aipkit_last_selected_chatbot');
                if (savedChatbotId) {
                    extraData.force_active_bot_id = savedChatbotId;
                }
            } catch (error) {
                console.warn('AIPKit Loader: Failed to retrieve saved chatbot from localStorage:', error);
            }
        }

        if (aipkit_moduleName === 'chatbot' && !extraData.layout) {
            try {
                const params = new URLSearchParams(window.location.search);
                const layoutParam = params.get('aipkit_chatbot_layout');
                if (layoutParam === 'next') {
                    extraData.layout = 'next';
                }
            } catch (error) {
                console.warn('AIPKit Loader: Failed to read chatbot layout flag from URL:', error);
            }
        }

        const navLinks = document.querySelectorAll('.aipkit_module-link');
        navLinks.forEach(link => link.classList.remove('aipkit_active'));
        const activeLinks = document.querySelectorAll(`.aipkit_module-link[data-module="${aipkit_moduleName}"]`);
        activeLinks.forEach(link => link.classList.add('aipkit_active'));

        const aipkit_moduleContainer = document.getElementById('aipkit_module-container');
        if (!aipkit_moduleContainer) {
            console.error("AIPKit Loader: Module container #aipkit_module-container not found.");
            if (typeof afterLoadCallback === 'function') afterLoadCallback(new Error("Module container not found."));
            return Promise.reject(new Error("Module container not found."));
        }
        applyModuleLayoutState(aipkit_moduleContainer, aipkit_moduleName);

        const loadingStartedAt = Date.now();
        let loadingFeedbackTimer = null;

        const applyLoadingMinHeight = () => {
            const currentHeight = aipkit_moduleContainer.offsetHeight || 0;
            const viewportTarget = Math.round(window.innerHeight * 0.55);
            const targetHeight = Math.max(currentHeight, viewportTarget, 280);
            aipkit_moduleContainer.style.minHeight = `${targetHeight}px`;
        };

        const clearLoadingMinHeight = () => {
            aipkit_moduleContainer.style.minHeight = '';
        };

        const clearLoadingFeedbackTimer = () => {
            if (!loadingFeedbackTimer) {
                return;
            }
            clearTimeout(loadingFeedbackTimer);
            loadingFeedbackTimer = null;
        };

        const showModuleLoading = () => {
            loadingFeedbackTimer = null;
            aipkit_moduleContainer.replaceChildren(createModuleLoading());
        };

        // Automations checks its workspace after rendering: keep it hidden until ready, with the same late indicator.
        const deferAutogptContentUntilReady = () => {
            if (isSilentLoad || aipkit_moduleName !== 'autogpt') {
                return null;
            }

            const moduleContent = aipkit_moduleContainer.querySelector('#aipkit_autogpt_container');
            if (!moduleContent || moduleContent.dataset.workspaceState !== 'checking') {
                return null;
            }

            const loading = createModuleLoading();
            const contentWasHidden = moduleContent.hidden;
            moduleContent.hidden = true;
            loading.hidden = true;
            moduleContent.before(loading);
            const loadingTimer = setTimeout(() => {
                loading.hidden = false;
            }, Math.max(0, MODULE_LOADING_DELAY_MS - (Date.now() - loadingStartedAt)));

            const removeLoading = () => {
                clearTimeout(loadingTimer);
                loading.remove();
            };

            return {
                complete: () => {
                    removeLoading();
                    if (moduleContent.isConnected) {
                        moduleContent.hidden = contentWasHidden;
                    }
                },
                cancel: removeLoading,
            };
        };

        const beginLoadTransition = () => {
            if (isSilentLoad) {
                loadingFeedbackTimer = setTimeout(() => {
                    loadingFeedbackTimer = null;
                    aipkit_moduleContainer.classList.add('aipkit_module-container--refreshing');
                }, MODULE_REFRESH_DELAY_MS);
                return;
            }

            applyLoadingMinHeight();
            aipkit_moduleContainer.classList.add('aipkit_module-container--loading');
            aipkit_moduleContainer.innerHTML = '';
            loadingFeedbackTimer = setTimeout(showModuleLoading, MODULE_LOADING_DELAY_MS);
        };

        const finalizeLoadTransition = () => {
            clearLoadingFeedbackTimer();
            aipkit_moduleContainer.classList.remove('aipkit_module-container--loading');
            aipkit_moduleContainer.classList.remove('aipkit_module-container--refreshing');
            clearLoadingMinHeight();
        };

        beginLoadTransition();

        if (typeof window.aipkit_apiRequest !== 'function') {
            const apiErrorMsg = 'Core API function missing. Cannot load module.';
            console.error(`AIPKit Loader: aipkit_apiRequest function not found for module '${aipkit_moduleName}'.`);
            clearLoadingFeedbackTimer();
            if (typeof window.aipkit_renderModuleError === 'function') {
                window.aipkit_renderModuleError(aipkit_moduleContainer, aipkit_moduleName, apiErrorMsg);
            }
            if (typeof afterLoadCallback === 'function') afterLoadCallback(new Error(apiErrorMsg));
            finalizeLoadTransition();
            return Promise.reject(new Error(apiErrorMsg));
        }

        const dataToSend = { module: aipkit_moduleName, ...extraData };
        const cacheKey = buildModuleCacheKey(aipkit_moduleName, extraData);
        const generationAtRequest = getModuleCacheGeneration(aipkit_moduleName);

        const renderAndInitializeModule = async (responseData) => {
            if (!responseData || typeof responseData.html !== 'string') {
                throw new Error('Missing or invalid "html" in response data');
            }

            clearLoadingFeedbackTimer();

            if (
                responseData.providerStatus &&
                typeof responseData.providerStatus === 'object' &&
                typeof window.aipkit_applyProviderStatus === 'function'
            ) {
                window.aipkit_applyProviderStatus(responseData.providerStatus, {
                    invalidateCaches: false,
                });
            }

            renderModuleMarkup(aipkit_moduleContainer, responseData.html);
            // Settle the module's setup notice with its markup, so the shell's Cloud invitation
            // (ui-cloud-announcement.js) shows or stays hidden in the same frame as the content.
            if (typeof window.aipkit_refreshProviderNotices === 'function') {
                window.aipkit_refreshProviderNotices(aipkit_moduleContainer);
            }
            window.dispatchEvent(new CustomEvent('aipkit:module-rendered', { detail: { module: aipkit_moduleName } }));
            const deferredAutogptTransition = deferAutogptContentUntilReady();

            try {
                if (aipkit_moduleName === 'chatbot' && responseData.openaiVectorStores) {
                    if (window.aipkit_chat_config) {
                        window.aipkit_chat_config.openaiVectorStores = responseData.openaiVectorStores;
                    } else {
                        console.warn("AIPKit Loader: window.aipkit_chat_config object not found; cannot update openaiVectorStores.");
                    }
                }

                await loadModuleScripts(aipkit_moduleName);

                const initializerFunctionName = moduleInitializers[aipkit_moduleName];
                if (initializerFunctionName && typeof window[initializerFunctionName] === 'function') {
                    try {
                        const initializationResult = window[initializerFunctionName]();
                        if (
                            aipkit_moduleName === 'autogpt' &&
                            initializationResult &&
                            typeof initializationResult.then === 'function'
                        ) {
                            await initializationResult;
                        }
                    } catch (initError) {
                        const initErrorMsg = `Error initializing ${aipkit_moduleName} module scripts.`;
                        throw new Error(`${initErrorMsg} (Details: ${initError.message})`);
                    }
                }

                if (typeof window.aipkit_initProviderKeyNotices === 'function') {
                    window.aipkit_initProviderKeyNotices(aipkit_moduleContainer);
                }
                if (typeof window.aipkit_initDismissibleNotices === 'function') {
                    window.aipkit_initDismissibleNotices(aipkit_moduleContainer);
                }

                deferredAutogptTransition?.complete();

                rememberLastVisitedModule(aipkit_moduleName);
            } catch (error) {
                deferredAutogptTransition?.cancel();
                throw error;
            }
        };

        try {
            const cachedEntry = forceRefresh ? null : getCachedModuleEntry(cacheKey);
            if (cachedEntry) {
                await loadModuleStyles(aipkit_moduleName);
                await renderAndInitializeModule({ html: cachedEntry.html });
                if (typeof afterLoadCallback === 'function') afterLoadCallback();
                finalizeLoadTransition();
                primeNavigationModuleCache(aipkit_moduleName);
                return Promise.resolve();
            }

            let responseData;
            let responseGeneration = generationAtRequest;
            for (let attempt = 0; attempt < 3; attempt++) {
                [responseData] = await Promise.all([
                    window.aipkit_apiRequest('aipkit_dashboard_load_module', dataToSend),
                    loadModuleStyles(aipkit_moduleName),
                ]);
                if (responseGeneration === getModuleCacheGeneration(aipkit_moduleName)) break;
                if (attempt === 2) throw new Error('Module changed while loading. Please try again.');
                responseGeneration = getModuleCacheGeneration(aipkit_moduleName);
            }
            await renderAndInitializeModule(responseData);
            if (responseData && typeof responseData.html === 'string') {
                storeModuleCacheEntry(cacheKey, responseData.html, aipkit_moduleName, responseGeneration);
            }

            if (typeof afterLoadCallback === 'function') afterLoadCallback();
            finalizeLoadTransition();
            primeNavigationModuleCache(aipkit_moduleName);
            return Promise.resolve();

        } catch (error) {
            clearLoadingFeedbackTimer();
            if (error && error.debug) {
                console.error(`AIPKit Loader: Module loading error for '${aipkit_moduleName}':`, error.message, '\nServer debug:', error.debug);
            } else {
                console.error(`AIPKit Loader: Module loading error for '${aipkit_moduleName}':`, error);
            }
            if (typeof window.aipkit_renderModuleError === 'function') {
                window.aipkit_renderModuleError(aipkit_moduleContainer, aipkit_moduleName, error.message);
            }
            if (typeof afterLoadCallback === 'function') afterLoadCallback(error);
            finalizeLoadTransition();
            return Promise.reject(error);
        }
    }

    /**
     * Invalidate all cached module HTML entries whose key starts with the given module name.
     * Call this after any operation that changes the module's data (e.g. creating/deleting bots)
     * so that subsequent loads fetch fresh HTML from the server.
     *
     * @param {string} moduleName  The module prefix to invalidate (e.g. 'chatbot').
     */
    const invalidateModuleCache = (moduleName) => {
        if (!moduleName) {
            return;
        }
        bumpModuleCacheGeneration(moduleName);
        const prefix = `${moduleName}::`;
        for (const key of Array.from(moduleHtmlCache.keys())) {
            if (key.startsWith(prefix)) {
                moduleHtmlCache.delete(key);
            }
        }
    };

    // Expose the loader function globally
    window.aipkit_loadModule = aipkit_loadModule;
    window.aipkit_invalidateModuleCache = invalidateModuleCache;

})();
