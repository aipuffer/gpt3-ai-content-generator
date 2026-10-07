/*
* AI Power: Complete AI Pack
*
* This file is part of the AI Power plugin.
*
* AIPKit - API Request Helper
*/
(function() { // Wrap in IIFE to keep scope clean
    'use strict';

    /**
     * Returns module cache keys that should be invalidated after a successful action.
     * Keeps module switch views fresh without a full page reload.
     *
     * @param {string} action
     * @returns {string[]}
     */
    function getModulesToInvalidateForAction(action) {
        if (typeof action !== 'string' || !action) {
            return [];
        }

        const modules = new Set();

        // Global settings affect multiple modules.
        if (action === 'aipkit_provider_connection' || action === 'aipkit_save_ai_settings' || action === 'aipkit_cloud_connection') {
            [
                'settings',
                'chatbot',
                'sources',
                'content-writer',
                'autogpt',
                'ai-forms',
                'image-generator',
            ].forEach(moduleName => modules.add(moduleName));
            if (action === 'aipkit_cloud_connection') {
                modules.add('stats');
            }
        }

        if (action === 'aipkit_connect_stock_photo_provider') {
            ['settings', 'content-writer', 'autogpt'].forEach(moduleName => modules.add(moduleName));
        }

        if (action === 'aipkit_save_semantic_search_settings') {
            modules.add('settings');
            modules.add('sources');
        }

        if (action === 'aipkit_update_module_setting') {
            modules.add('settings');
        }

        if (action === 'aipkit_update_developer_credential') {
            modules.add('settings');
        }

        // Chatbot writes should always refresh chatbot cache.
        if (
            action === 'aipkit_save_chatbot_settings' ||
            action === 'aipkit_stop_chatbot_training' ||
            /^aipkit_update_chatbot_/.test(action) ||
            /^aipkit_(create|delete|duplicate|reset|rename)_chatbot$/.test(action)
        ) {
            modules.add('chatbot');
        }

        // AI Forms writes should refresh forms list/editor module cache.
        if (/^aipkit_(save|duplicate|delete|delete_all|delete_selected|import)_ai_forms?$/.test(action)) {
            modules.add('ai-forms');
        }

        // Automations writes should refresh automations module cache.
        if (
            action === 'aipkit_stop_chatbot_training' ||
            /^aipkit_(save|delete)_automated_task$/.test(action) ||
            /^aipkit_update_automated_task_/.test(action)
        ) {
            modules.add('autogpt');
        }

        // Content Writer template writes should refresh template-driven UI.
        if (
            /^aipkit_(save|delete)_cw_template$/.test(action) ||
            action === 'aipkit_reset_cw_starter_templates'
        ) {
            modules.add('content-writer');
        }

        return Array.from(modules);
    }

    /**
     * Makes an AJAX call to wp-admin/admin-ajax.php using the action provided.
     * @param {string} action - The WP AJAX action (e.g. 'aipkit_update_module_setting')
     * @param {Object} data   - Key/value data to send (converted to FormData).
     * @param {Object} options - Additional options like timeout.
     * @returns {Promise<any>} A promise that resolves with the JSON response or rejects with an error.
     */
    function aipkit_apiRequest(action, data = {}, options = {}) {
        return new Promise((resolve, reject) => {
            if (!window.aipkit_dashboard || !window.aipkit_dashboard.ajaxurl) {
                return reject(new Error('AIPKit AJAX is not properly initialized (missing aipkit_dashboard object)'));
            }

            const formData = new FormData();
            formData.append('action', action);

            if (!Object.prototype.hasOwnProperty.call(data, '_ajax_nonce') && window.aipkit_dashboard && window.aipkit_dashboard.nonce) {
                formData.append('_ajax_nonce', window.aipkit_dashboard.nonce);
            }

            for (const key in data) {
                if (Object.prototype.hasOwnProperty.call(data, key)) {
                    if (Array.isArray(data[key])) {
                        data[key].forEach(value => {
                            formData.append(key + '[]', value);
                        });
                    } else {
                        formData.append(key, data[key]);
                    }
                }
            }

            // Create AbortController for timeout handling
            const controller = new AbortController();
            let abortedByTimeout = false;
            let abortedByCaller = false;
            let detachExternalAbort = null;

            if (options.signal) {
                if (options.signal.aborted) {
                    abortedByCaller = true;
                    controller.abort();
                } else {
                    const handleExternalAbort = () => {
                        abortedByCaller = true;
                        controller.abort();
                    };
                    options.signal.addEventListener('abort', handleExternalAbort, { once: true });
                    detachExternalAbort = () => {
                        options.signal.removeEventListener('abort', handleExternalAbort);
                    };
                }
            }

            const timeoutId = setTimeout(() => {
                abortedByTimeout = true;
                controller.abort();
            }, options.timeout || 120000); // Default 2 minutes, increased from browser default

            const cleanupAbortState = () => {
                clearTimeout(timeoutId);
                if (detachExternalAbort) {
                    detachExternalAbort();
                    detachExternalAbort = null;
                }
            };

            fetch(window.aipkit_dashboard.ajaxurl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                signal: controller.signal
            })
            .then(async response => {
                cleanupAbortState();

                const responseText = await response.text();
                let json;

                try {
                    json = responseText ? JSON.parse(responseText) : {};
                } catch (parseError) {
                    const trimmedResponse = String(responseText || '').trim();
                    const looksLikeHtml = trimmedResponse.startsWith('<');
                    const gatewayTimeoutStatus = [502, 503, 504, 524].includes(response.status);
                    const gatewayTimeoutBody = /(?:504|524|gateway[\s-]*time[\s-]*out|gateway timeout|upstream timed out)/i.test(trimmedResponse);
                    const isGatewayTimeout = looksLikeHtml && (gatewayTimeoutStatus || gatewayTimeoutBody);
                    const invalidResponseError = new Error(
                        isGatewayTimeout
                            ? 'The server timed out while waiting for this long-running request.'
                            : looksLikeHtml
                            ? 'Server returned HTML instead of JSON. The request may have been blocked by a firewall or proxy.'
                            : 'Server returned an invalid JSON response.'
                    );

                    invalidResponseError.code = isGatewayTimeout
                        ? 'gateway_timeout'
                        : looksLikeHtml
                        ? 'invalid_html_response'
                        : 'invalid_json_response';
                    invalidResponseError.status = response.status;
                    invalidResponseError.isGatewayTimeout = isGatewayTimeout;
                    invalidResponseError.responseText = trimmedResponse.slice(0, 500);
                    throw invalidResponseError;
                }

                if (!response.ok || !json?.success) {
                    const data = json.data || {};
                    const gatewayTimeoutStatus = [502, 503, 504, 524].includes(response.status);
                    const msg = gatewayTimeoutStatus && !data.message
                        ? 'The server timed out while waiting for this long-running request.'
                        : data.message || 'Unknown error';
                    const err = new Error(msg);
                    // Attach server debug info for caller logging if present
                    if (data.debug_error) {
                        err.debug = data.debug_error;
                    }
                    err.data = data;
                    if (gatewayTimeoutStatus) {
                        err.code = data.code || 'gateway_timeout';
                        err.isGatewayTimeout = true;
                    } else if (data.code) {
                        err.code = data.code;
                    }
                    if (data.details) {
                        err.details = data.details;
                    }
                    throw err;
                }

                return json.data;
            })
            .then(data => {
                if (data?.newConfiguration && window.aipkit_dashboard) {
                    Object.assign(window.aipkit_dashboard, data.newConfiguration);
                }
                if (typeof data?.navStatusHtml === 'string') {
                    window.aipkit_applyNavStatus?.(data.navStatusHtml);
                }
                if (typeof window.aipkit_invalidateModuleCache === 'function') {
                    const modulesToInvalidate = getModulesToInvalidateForAction(action);
                    modulesToInvalidate.forEach(moduleName => {
                        window.aipkit_invalidateModuleCache(moduleName);
                    });
                }
                if (
                    action === 'aipkit_save_ai_settings' &&
                    data &&
                    data.providerStatus &&
                    typeof window.aipkit_applyProviderStatus === 'function'
                ) {
                    window.aipkit_applyProviderStatus(data.providerStatus, {
                        invalidateCaches: false,
                    });
                }
                resolve(data);
            })
            .catch(error => {
                cleanupAbortState();
                if (error.name === 'AbortError' || controller.signal.aborted) {
                    const abortError = new Error(
                        abortedByTimeout
                            ? 'Request timeout'
                            : options.abortMessage || 'Request canceled'
                    );
                    abortError.name = 'AbortError';
                    abortError.code = abortedByTimeout ? 'timeout' : 'aborted';
                    abortError.abortedByTimeout = abortedByTimeout;
                    abortError.abortedByCaller = abortedByCaller;
                    reject(abortError);
                } else {
                    reject(error);
                }
            });
        });
    }

    // Expose to global window object
    window.aipkit_apiRequest = aipkit_apiRequest;

})();
