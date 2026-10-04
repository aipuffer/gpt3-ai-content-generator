/**
 * AIPKit Dashboard - Module Loader
 *
 * Loads modules asynchronously via AJAX and inserts the returned content
 * into the main container. Dynamically calls the module's initializer function.
 * REVISED: Uses a map for module initializers to improve clarity and reduce repetition.
 */
(function() {
    'use strict';

    const MODULE_SKELETON_DELAY_MS = 300;
    const MODULE_SKELETON_MIN_VISIBLE_MS = 220;
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

        // Keep module notices edge to edge while the workspace retains its gutters.
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

    const skeletonLine = (width = '100%', extraClass = '') => (
        `<span class="aipkit_module-skeleton-line ${extraClass}" style="--aipkit-skeleton-line-width:${width}"></span>`
    );

    const skeletonRows = (widths) => (
        `<div class="aipkit_module-skeleton-rows">${widths.map(width => skeletonLine(width)).join('')}</div>`
    );

    const skeletonPanel = (content, extraClass = '') => (
        `<div class="aipkit_module-skeleton-panel ${extraClass}">${content}</div>`
    );

    const skeletonTable = (rowCount = 5) => {
        const rows = Array.from({ length: rowCount }, (_, index) => (
            `<div class="aipkit_module-skeleton-table-row">
                ${skeletonLine(index % 2 === 0 ? '32%' : '39%')}
                ${skeletonLine('20%')}
                ${skeletonLine(index % 2 === 0 ? '13%' : '17%')}
                ${skeletonLine('9%')}
            </div>`
        )).join('');

        return `
            <div class="aipkit_module-skeleton-table">
                <div class="aipkit_module-skeleton-table-head">
                    ${skeletonLine('24%')}
                    ${skeletonLine('15%')}
                    ${skeletonLine('11%')}
                    ${skeletonLine('8%')}
                </div>
                ${rows}
            </div>
        `;
    };

    const getModuleSkeletonMarkup = (moduleName) => {
        const skeletons = {
            'content-writer': `
                <div class="aipkit_module-skeleton-grid aipkit_module-skeleton-grid--content-writer">
                    ${skeletonPanel(`${skeletonLine('48%', 'aipkit_module-skeleton-line--heading')}${skeletonRows(['76%', '62%', '70%', '57%', '68%', '52%', '72%', '59%'])}`, 'aipkit_module-skeleton-panel--rail')}
                    ${skeletonPanel(`
                        ${skeletonLine('25%', 'aipkit_module-skeleton-line--heading')}
                        <div class="aipkit_module-skeleton-pills">${skeletonLine('18%')}${skeletonLine('23%')}${skeletonLine('20%')}</div>
                        ${skeletonLine('52%')}
                        <div class="aipkit_module-skeleton-block aipkit_module-skeleton-block--topic"></div>
                        ${skeletonLine('20%')}
                        <div class="aipkit_module-skeleton-block aipkit_module-skeleton-block--input"></div>
                        <div class="aipkit_module-skeleton-action">${skeletonLine('24%')}</div>
                    `, 'aipkit_module-skeleton-panel--main')}
                    ${skeletonPanel(`${skeletonRows(['88%', '82%', '76%', '84%', '54%', '62%', '73%', '66%'])}`, 'aipkit_module-skeleton-panel--rail')}
                </div>
            `,
            'chatbot': `
                <div class="aipkit_module-skeleton-grid aipkit_module-skeleton-grid--chatbot">
                    ${skeletonPanel(`
                        <div class="aipkit_module-skeleton-toolbar">${skeletonLine('28%', 'aipkit_module-skeleton-line--heading')}${skeletonLine('34%')}${skeletonLine('7%')}</div>
                        ${skeletonRows(['23%', '100%', '18%', '100%', '27%', '100%', '16%', '100%', '34%', '100%'])}
                    `, 'aipkit_module-skeleton-panel--builder')}
                    <div class="aipkit_module-skeleton-stack">
                        ${skeletonPanel(`<div class="aipkit_module-skeleton-toolbar">${skeletonLine('31%')}${skeletonLine('18%')}${skeletonLine('18%')}</div>`, 'aipkit_module-skeleton-panel--compact')}
                        ${skeletonPanel(`${skeletonLine('20%', 'aipkit_module-skeleton-line--heading')}<div class="aipkit_module-skeleton-block aipkit_module-skeleton-block--preview"></div>`, 'aipkit_module-skeleton-panel--preview')}
                    </div>
                </div>
            `,
            'ai-forms': `
                <div class="aipkit_module-skeleton-flow">
                    <div class="aipkit_module-skeleton-heading-row">${skeletonRows(['18%', '32%'])}${skeletonLine('12%')}</div>
                    <div class="aipkit_module-skeleton-card-row">
                        ${Array.from({ length: 5 }, () => skeletonPanel(`${skeletonLine('22%', 'aipkit_module-skeleton-line--tile')}${skeletonRows(['68%', '88%', '60%'])}`, 'aipkit_module-skeleton-panel--template')).join('')}
                    </div>
                    <div class="aipkit_module-skeleton-toolbar">${skeletonLine('18%', 'aipkit_module-skeleton-line--heading')}${skeletonLine('24%')}${skeletonLine('4%')}</div>
                    ${skeletonTable(5)}
                </div>
            `,
            'autogpt': `
                <div class="aipkit_module-skeleton-flow aipkit_module-skeleton-flow--narrow">
                    <div class="aipkit_module-skeleton-heading-row">${skeletonRows(['22%', '38%'])}${skeletonLine('14%')}</div>
                    <div class="aipkit_module-skeleton-card-row aipkit_module-skeleton-card-row--three">
                        ${Array.from({ length: 3 }, () => skeletonPanel(`${skeletonLine('18%', 'aipkit_module-skeleton-line--tile')}${skeletonRows(['58%', '86%', '72%'])}`, 'aipkit_module-skeleton-panel--template')).join('')}
                    </div>
                    <div class="aipkit_module-skeleton-toolbar">${skeletonLine('20%', 'aipkit_module-skeleton-line--heading')}${skeletonLine('28%')}${skeletonLine('5%')}</div>
                    ${skeletonTable(6)}
                </div>
            `,
            'sources': `
                <div class="aipkit_module-skeleton-flow">
                    <div class="aipkit_module-skeleton-heading-row">${skeletonRows(['24%', '36%'])}${skeletonLine('13%')}</div>
                    <div class="aipkit_module-skeleton-tabs">${skeletonLine('10%')}${skeletonLine('12%')}${skeletonLine('10%')}${skeletonLine('11%')}</div>
                    <div class="aipkit_module-skeleton-toolbar">${skeletonLine('19%', 'aipkit_module-skeleton-line--heading')}${skeletonLine('25%')}${skeletonLine('5%')}</div>
                    ${skeletonTable(6)}
                </div>
            `,
            'stats': `
                <div class="aipkit_module-skeleton-flow">
                    <div class="aipkit_module-skeleton-heading-row">${skeletonRows(['14%', '31%'])}${skeletonLine('18%')}</div>
                    <div class="aipkit_module-skeleton-card-row aipkit_module-skeleton-card-row--four">
                        ${Array.from({ length: 4 }, () => skeletonPanel(`${skeletonLine('36%')}${skeletonLine('24%', 'aipkit_module-skeleton-line--metric')}${skeletonLine('52%')}`, 'aipkit_module-skeleton-panel--metric')).join('')}
                    </div>
                    ${skeletonPanel(`${skeletonLine('21%', 'aipkit_module-skeleton-line--heading')}<div class="aipkit_module-skeleton-block aipkit_module-skeleton-block--chart"></div>`, 'aipkit_module-skeleton-panel--chart')}
                </div>
            `,
            'settings': `
                <div class="aipkit_module-skeleton-flow">
                    <div class="aipkit_module-skeleton-heading-row">${skeletonRows(['15%', '42%'])}</div>
                    <div class="aipkit_module-skeleton-tabs">${skeletonLine('7%')}${skeletonLine('10%')}${skeletonLine('12%')}${skeletonLine('8%')}${skeletonLine('9%')}${skeletonLine('11%')}</div>
                    ${skeletonPanel(`${skeletonRows(['19%', '44%'])}${skeletonPanel(skeletonRows(['24%', '100%', '35%']), 'aipkit_module-skeleton-panel--nested')}${skeletonPanel(skeletonRows(['20%', '100%', '31%']), 'aipkit_module-skeleton-panel--nested')}`, 'aipkit_module-skeleton-panel--settings')}
                </div>
            `,
            'image-generator': `
                <div class="aipkit_module-skeleton-grid aipkit_module-skeleton-grid--image-generator">
                    ${skeletonPanel(`${skeletonLine('28%', 'aipkit_module-skeleton-line--heading')}${skeletonRows(['22%', '100%', '31%', '100%', '25%', '100%', '18%'])}`, 'aipkit_module-skeleton-panel--builder')}
                    ${skeletonPanel(`${skeletonLine('24%', 'aipkit_module-skeleton-line--heading')}<div class="aipkit_module-skeleton-block aipkit_module-skeleton-block--image"></div>`, 'aipkit_module-skeleton-panel--preview')}
                </div>
            `,
        };

        const fallback = `
            <div class="aipkit_module-skeleton-flow">
                <div class="aipkit_module-skeleton-heading-row">${skeletonRows(['18%', '34%'])}${skeletonLine('13%')}</div>
                <div class="aipkit_module-skeleton-card-row aipkit_module-skeleton-card-row--three">
                    ${Array.from({ length: 3 }, () => skeletonPanel(skeletonRows(['38%', '66%', '48%']), 'aipkit_module-skeleton-panel--metric')).join('')}
                </div>
                ${skeletonTable(5)}
            </div>
        `;

        return `
            <div class="aipkit_module-skeleton aipkit_module-skeleton--${moduleName}" role="status" aria-live="polite">
                ${skeletons[moduleName] || fallback}
            </div>
        `;
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
        let loadingFeedbackShownAt = 0;
        let transitionPreparedForRender = false;

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

        const applySkeletonAccessibilityLabel = (skeleton) => {
            if (!skeleton) {
                return;
            }
            const loadingText = window.aipkit_dashboard?.text?.loading
                ? window.aipkit_dashboard.text.loading.replace('%s', aipkit_moduleName)
                : `Loading ${aipkit_moduleName}`;
            skeleton.setAttribute('aria-label', loadingText);
        };

        const showModuleSkeleton = () => {
            loadingFeedbackTimer = null;
            loadingFeedbackShownAt = Date.now();
            renderModuleMarkup(aipkit_moduleContainer, getModuleSkeletonMarkup(aipkit_moduleName));
            applySkeletonAccessibilityLabel(
                aipkit_moduleContainer.querySelector('.aipkit_module-skeleton')
            );
        };

        const deferAutogptContentUntilReady = () => {
            if (isSilentLoad || aipkit_moduleName !== 'autogpt') {
                return null;
            }

            const moduleContent = aipkit_moduleContainer.querySelector('#aipkit_autogpt_container');
            if (!moduleContent || moduleContent.dataset.workspaceState !== 'checking') {
                return null;
            }

            const skeletonTemplate = document.createElement('template');
            skeletonTemplate.innerHTML = getModuleSkeletonMarkup(aipkit_moduleName).trim();
            const skeleton = skeletonTemplate.content.firstElementChild;
            if (!skeleton) {
                return null;
            }

            const contentWasHidden = moduleContent.hidden;
            let deferredSkeletonTimer = null;
            let deferredSkeletonShownAt = 0;
            moduleContent.hidden = true;
            skeleton.hidden = true;
            moduleContent.before(skeleton);
            applySkeletonAccessibilityLabel(skeleton);

            const showDeferredSkeleton = () => {
                deferredSkeletonTimer = null;
                deferredSkeletonShownAt = Date.now();
                skeleton.hidden = false;
            };

            if (loadingFeedbackShownAt) {
                deferredSkeletonShownAt = loadingFeedbackShownAt;
                skeleton.hidden = false;
            } else {
                const elapsed = Date.now() - loadingStartedAt;
                const delay = Math.max(0, MODULE_SKELETON_DELAY_MS - elapsed);
                deferredSkeletonTimer = setTimeout(showDeferredSkeleton, delay);
            }

            const clearDeferredSkeletonTimer = () => {
                if (!deferredSkeletonTimer) {
                    return;
                }
                clearTimeout(deferredSkeletonTimer);
                deferredSkeletonTimer = null;
            };

            return {
                complete: async () => {
                    clearDeferredSkeletonTimer();
                    if (deferredSkeletonShownAt) {
                        const elapsed = Date.now() - deferredSkeletonShownAt;
                        const remaining = MODULE_SKELETON_MIN_VISIBLE_MS - elapsed;
                        if (remaining > 0) {
                            await new Promise(resolve => setTimeout(resolve, remaining));
                        }
                    }
                    skeleton.remove();
                    if (moduleContent.isConnected) {
                        moduleContent.hidden = contentWasHidden;
                    }
                },
                cancel: () => {
                    clearDeferredSkeletonTimer();
                    skeleton.remove();
                },
            };
        };

        const beginLoadTransition = () => {
            if (isSilentLoad) {
                loadingFeedbackTimer = setTimeout(() => {
                    loadingFeedbackTimer = null;
                    loadingFeedbackShownAt = Date.now();
                    aipkit_moduleContainer.classList.add('aipkit_module-container--refreshing');
                }, MODULE_SKELETON_DELAY_MS);
                return;
            }

            applyLoadingMinHeight();
            aipkit_moduleContainer.classList.add('aipkit_module-container--loading');
            aipkit_moduleContainer.innerHTML = '';
            loadingFeedbackTimer = setTimeout(showModuleSkeleton, MODULE_SKELETON_DELAY_MS);
        };

        const prepareLoadTransitionForRender = async () => {
            if (transitionPreparedForRender) {
                return;
            }
            transitionPreparedForRender = true;
            clearLoadingFeedbackTimer();

            if (!loadingFeedbackShownAt || isSilentLoad) {
                return;
            }

            const elapsed = Date.now() - loadingFeedbackShownAt;
            const remaining = MODULE_SKELETON_MIN_VISIBLE_MS - elapsed;
            if (remaining > 0) {
                await new Promise(resolve => setTimeout(resolve, remaining));
            }
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
            await prepareLoadTransitionForRender();
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

            await prepareLoadTransitionForRender();

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

                if (deferredAutogptTransition) {
                    await deferredAutogptTransition.complete();
                }

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
            await prepareLoadTransitionForRender();
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
