import { bindAccountEmail } from '../shared/account-email.js';
import { reportConnectionDiagnostic } from '../shared/connection-diagnostics.js';
import { updateProviderModelSelects } from './sync-provider-model-selects.js';
import { cloudConnectionRevision, advanceCloudConnectionRevision, beginCloudConnectionChange, publishCloudConnectionChange, watchCloudConnection } from '../shared/cloud-connection-sync.js';

const __ = window.wp?.i18n?.__ || ((text) => text);
let creditRequest = null;
let creditRequestGeneration = 0;

function readCreditNotice(html) {
    const template = document.createElement('template');
    template.innerHTML = html;
    const notice = template.content.querySelector('.aipkit_cloud_credit_notice');
    if (html && !notice) throw new Error(__('Cloud returned an unexpected response. Try again.', 'gpt3-ai-content-generator'));
    return notice;
}

function applyCreditNotice(nextNotice) {
    const shellHeader = document.querySelector('.aipkit_wrap > .aipkit_module-tabs_shell');
    if (!shellHeader) return;
    const notices = [...document.querySelectorAll('.aipkit_cloud_credit_notice')];
    if (nextNotice) {
        if (notices.length) notices.shift().replaceWith(nextNotice);
        else {
            const announcement = shellHeader.parentElement.querySelector('[data-aipkit-cloud-announcement]');
            (announcement || shellHeader).after(nextNotice);
        }
    }
    notices.forEach((notice) => notice.remove());
}

/** One balance read on entering Overview; no timer, model sync, or pack request. */
export async function refreshCloudCredits(root) {
    const section = root?.querySelector('#aipkit_usage_cloud_credits');
    if (!root?.isConnected || !section || section.getAttribute('aria-busy') === 'true'
        || window.aipkit_dashboard?.cloudConnected === false) return;
    const generation = cloudConnectionRevision();
    const apply = (response) => {
        if (!root.isConnected || !section.isConnected || generation !== cloudConnectionRevision()) return;
        if (!response.connected && Array.isArray(response.models)) {
            applyCloudConnectionResponse(response, 'balance', root);
            return;
        }
        const { replacements } = cloudAccountReplacements(response.html, root);
        replacements.forEach(({ section: previous, nextSection }) => previous.replaceWith(nextSection));
        window.aipkit_initAiProviderCards?.();
        applyCreditNotice(readCreditNotice(response.creditNoticeHtml));
        window.dispatchEvent(new CustomEvent('aipkit:cloud-connection-changed', {
            detail: { connected: response.connected, usageHtml: response.usageHtml, action: 'balance' },
        }));
    };
    const nonce = section.querySelector('[name="_wpnonce"]')?.value;
    if (!nonce) return;
    const controls = [...section.querySelectorAll('button, input')].filter((control) => !control.disabled);
    controls.forEach((control) => { control.disabled = true; });
    section.setAttribute('aria-busy', 'true');
    const refreshButton = section.querySelector('[name="cloud_action"][value="refresh"]');
    refreshButton?.classList.add('aipkit_loading');
    refreshButton?.setAttribute('aria-busy', 'true');
    try {
        if (!creditRequest) {
            creditRequestGeneration = generation;
            creditRequest = window.aipkit_apiRequest('aipkit_cloud_connection', { _wpnonce: nonce, cloud_action: 'balance' }, { isCurrent: () => generation === cloudConnectionRevision() })
                .then((response) => {
                    if (typeof response.connected !== 'boolean' || typeof response.usageHtml !== 'string'
                        || typeof response.creditNoticeHtml !== 'string' || typeof response.html !== 'string' || !Number.isFinite(response.checkedAt)
                        || (response.connected && response.refreshed !== true)) throw new Error('balance_unavailable');
                    const template = document.createElement('template');
                    template.innerHTML = response.usageHtml;
                    if (response.connected && !template.content.querySelector('#aipkit_usage_cloud_credits')) throw new Error('balance_unavailable');
                    readCreditNotice(response.creditNoticeHtml);
                    return response;
                }).finally(() => { creditRequest = null; });
        }
        const requestedGeneration = creditRequestGeneration;
        const response = await creditRequest;
        if (requestedGeneration === cloudConnectionRevision()) apply(response);
    } catch (error) {
        if (!root.isConnected || !section.isConnected || generation !== cloudConnectionRevision()) return;
        const label = section.querySelector('[data-cloud-checked-label]');
        if (label) {
            label.hidden = false;
            label.dataset.cloudCheckedText ||= label.textContent;
            label.textContent = `${label.dataset.cloudCheckedText} ${__('Could not update credits. Try Refresh.', 'gpt3-ai-content-generator')}`;
        }
    } finally {
        section.removeAttribute('aria-busy');
        refreshButton?.classList.remove('aipkit_loading');
        refreshButton?.removeAttribute('aria-busy');
        controls.forEach((control) => { control.disabled = false; });
    }
}

/** Replace only Cloud options in mounted combined pickers; preserve the caller's selection. */
function syncCloudPickerSources(response) {
    const sources = new Map();
    document.querySelectorAll('[data-aipkit-unified-model-selector][data-aipkit-unified-model-source-id]').forEach((picker) => {
        const source = document.getElementById(picker.dataset.aipkitUnifiedModelSourceId);
        if (source) sources.set(source, picker.dataset.aipkitModelCapability);
    });
    document.querySelectorAll('select[data-aipkit-universal-model-combined="1"]').forEach((source) => {
        sources.set(source, source.dataset.aipkitUniversalModelCapability);
    });
    for (const [source, capability] of sources) {
        if (!['text_generation', 'image_generation'].includes(capability)) continue;
        const models = capability === 'text_generation' ? response.models
            : response.mediaModels.filter(model => model.operation === 'image_generate');
        const value = source.value;
        const selected = source.selectedOptions[0];
        const selectedCloud = selected?.dataset.provider?.toLowerCase() === 'aipuffercloud';
        for (const option of Array.from(source.options)) {
            if (option.dataset.provider?.toLowerCase() === 'aipuffercloud') option.remove();
        }
        source.querySelectorAll('optgroup').forEach(group => { if (!group.children.length) group.remove(); });
        if (models.length) {
            const group = document.createElement('optgroup');
            group.label = 'AI Puffer';
            group.dataset.provider = 'AIPufferCloud';
            for (const model of models) {
                const nativeImage = source.name === 'chat_image_model_id';
                const option = new Option(model.name || model.id, nativeImage ? model.id : `aipuffercloud::${model.id}`);
                option.dataset.provider = 'AIPufferCloud';
                option.dataset.model = model.id;
                if (selectedCloud && selected.dataset.model === model.id) option.value = value;
                group.appendChild(option);
            }
            source.appendChild(group);
        }
        if (value && !Array.from(source.options).some(option => option.value === value) && selectedCloud) {
            selected.disabled = true;
            selected.hidden = true;
            source.appendChild(selected);
        }
        source.value = value;
        source.disabled = !Array.from(source.options).some(option => option.value && !option.disabled);
    }
}

/** Prepare each mounted account body once, including the panel opened directly over Usage. */
function cloudAccountReplacements(html, root) {
    const accountRoots = new Set(document.querySelectorAll('#aipkit_settings_container, #aipkit_cloud_panel, [data-aipkit-connect-dialog]'));
    if (root?.id === 'aipkit_settings_container' || root?.id === 'aipkit_cloud_panel' || root?.matches('[data-aipkit-connect-dialog]')) accountRoots.add(root);
    const replacements = [];
    let accountChanged = false;
    const seen = new Set();
    for (const accountRoot of accountRoots) {
        const section = accountRoot.querySelector('#aipkit_cloud_connection');
        if (!section || seen.has(section)) continue;
        seen.add(section);
        const template = document.createElement('template');
        template.innerHTML = html;
        const nextSection = template.content.querySelector('#aipkit_cloud_connection');
        if (!nextSection) {
            throw new Error(__('Cloud returned an unexpected response. Try again.', 'gpt3-ai-content-generator'));
        }
        const previousEmail = section.querySelector('[data-cloud-email]')?.textContent?.trim();
        const nextEmail = nextSection.querySelector('[data-cloud-email]')?.textContent?.trim();
        accountChanged ||= Boolean(previousEmail && nextEmail && previousEmail !== nextEmail);
        replacements.push({ section, nextSection, focus: accountRoot === root && section.contains(document.activeElement) });
    }
    return { replacements, accountChanged };
}

/** Apply one complete catalog snapshot to all mounted provider interfaces. */
export function applyCloudConnectionResponse(response, action, root = null) {
    if (typeof response.connected !== 'boolean' || typeof response.html !== 'string' || typeof response.usageHtml !== 'string'
        || typeof response.creditNoticeHtml !== 'string'
        || !Array.isArray(response.models) || !Array.isArray(response.embeddingModels) || !Array.isArray(response.mediaModels)) {
        throw new Error(__('Cloud returned an unexpected response. Try again.', 'gpt3-ai-content-generator'));
    }
    const nextNotice = readCreditNotice(response.creditNoticeHtml);
    const { replacements, accountChanged } = cloudAccountReplacements(response.html, root);
    // Every bundle shares the revision, so a late Usage balance cannot undo a disconnect.
    advanceCloudConnectionRevision();
    window.aipkit_cloudAudioModels = response.mediaModels;
    const dashboard = window.aipkit_dashboard;
    if (dashboard) {
        dashboard.cloudConnected = Boolean(response.connected);
        const imageModels = response.mediaModels.filter((model) => model.operation === 'image_generate');
        dashboard.imageGeneratorModels = dashboard.imageGeneratorModels || {};
        dashboard.imageGeneratorModels.aipuffercloud = imageModels;
        dashboard.embeddingModels = dashboard.embeddingModels || {};
        dashboard.embeddingModels.aipuffercloud = response.embeddingModels;
        dashboard.embeddingProviderMap = dashboard.embeddingProviderMap || {};
        if (response.embeddingModels.length) {
            dashboard.embeddingProviderMap.aipuffercloud = 'AIPufferCloud';
        } else {
            delete dashboard.embeddingProviderMap.aipuffercloud;
        }
    }
    for (const config of [window.aipkit_chat_config, window.aipkit_automated_tasks_config, window.aipkit_vpp_config, window.aipkit_ai_forms_config, window.aipkit_post_enhancer]) {
        if (!config) continue;
        for (const key of ['embeddingModels', 'embeddingModelsByProvider', 'embedding_models_by_provider']) {
            if (config[key]) config[key].aipuffercloud = response.embeddingModels;
        }
        for (const key of ['embeddingProviderMap', 'embedding_provider_map']) {
            if (!config[key]) continue;
            if (response.embeddingModels.length) config[key].aipuffercloud = 'AIPufferCloud';
            else delete config[key].aipuffercloud;
        }
    }
    if (window.aipkit_image_generator_config_public) {
        window.aipkit_image_generator_config_public.cloud_image_models = response.mediaModels.filter(
            (model) => model.operation === 'image_generate' || model.operation === 'image_edit'
        );
    }
    window.aipkit_embedding_utils?.syncProviderModels('aipuffercloud', response.embeddingModels, 'AI Puffer Cloud');
    updateProviderModelSelects(response.models, {
        dashboardModelKey: 'aipuffercloud',
        selectId: 'aipkit_aipuffercloud_model',
        chatSelectSuffix: '_aipuffercloud_model',
        warningLabel: 'AI Puffer Cloud',
        preserveMissingOldValue: true,
        suppressMissingSelectWarning: true,
        afterUpdateOne: (select, oldValue) => {
            if (!oldValue && response.connected && select.id === 'aipkit_aipuffercloud_model' && typeof response.selectedModel === 'string') {
                select.value = response.selectedModel;
            }
        },
    });
    if (response?.newConfiguration && window.aipkit_dashboard) {
        Object.assign(window.aipkit_dashboard, response.newConfiguration);
    }
    window.aipkit_applyProviderStatus?.(response.providerStatus, { invalidateCaches: false });
    syncCloudPickerSources(response);
    window.aipkit_updateModelRegistryStates?.(response.provider_states);
    for (const { section, nextSection, focus } of replacements) {
        section.replaceWith(nextSection);
        if (focus) nextSection.querySelector(`[name="cloud_action"][value="${action === 'sync' ? 'sync' : response.connected ? 'disconnect' : 'connect'}"]`)?.focus({ preventScroll: true });
    }
    window.aipkit_initAiProviderCards?.();
    window.dispatchEvent(new CustomEvent('aipkit:model-sync-complete', {
        detail: { provider: 'AIPufferCloud', connected: response.connected, syncedAt: Math.floor(Date.now() / 1000) },
    }));
    applyCreditNotice(nextNotice);
    window.dispatchEvent(new CustomEvent('aipkit:cloud-connection-changed', {
        detail: { connected: Boolean(response.connected), usageHtml: response.usageHtml, action, accountChanged, emailVerified: response.emailVerified, hasCredits: response.hasCredits },
    }));
    if (action !== 'view') publishCloudConnectionChange();
}

/** Handles Cloud connection in Settings and account actions in Usage. */
/** One shared balance read for all mounted surfaces; no polling or inference-credit coupling. */
export async function refreshWebsiteActions(root = document, force = false) {
    const hosts = [...root.querySelectorAll('[data-aipkit-website-usage]')];
    if (!hosts.length) return;
    const state = window.aipkitWebsiteUsageState ||= { request: null, checkedAt: 0, generation: -1 };
    const generation = cloudConnectionRevision();
    if (!force && state.generation === generation && Date.now() - state.checkedAt < 60000) return;
    if (state.request) return state.request;
    const nonce = hosts[0].querySelector('[data-website-usage-nonce]')?.value;
    if (!nonce || typeof window.aipkit_apiRequest !== 'function') return;
    document.querySelectorAll('[data-website-usage-refresh]').forEach(button => { button.disabled = true; });
    state.request = (async () => {
        try {
            const response = await window.aipkit_apiRequest('aipkit_mcp_settings', { _wpnonce: nonce, mcp_action: 'usage' });
            if (generation !== cloudConnectionRevision()) return;
            if (typeof response.enabled !== 'boolean' || typeof response.card !== 'string' || typeof response.panel !== 'string') throw new Error('invalid_usage');
            const fragments = {};
            for (const surface of ['usage', 'panel']) {
                const template = document.createElement('template');
                template.innerHTML = response[surface === 'usage' ? 'card' : 'panel'];
                const node = template.content.querySelector(`[data-aipkit-website-usage="${surface}"]`);
                if (!node || node.dataset.mcpEnabled !== (response.enabled ? '1' : '0')) throw new Error('invalid_usage');
                fragments[surface] = node;
            }
            document.querySelectorAll('[data-aipkit-website-usage]').forEach(host => {
                const focused = host.contains(document.activeElement);
                const next = fragments[host.dataset.aipkitWebsiteUsage]?.cloneNode(true);
                if (!next) return;
                host.replaceWith(next);
                if (focused && response.enabled) next.querySelector('[data-website-usage-refresh]')?.focus({ preventScroll: true });
            });
            state.checkedAt = Date.now(); state.generation = generation;
            window.dispatchEvent(new CustomEvent('aipkit:website-usage-changed'));
        } catch (error) {
            if (generation !== cloudConnectionRevision()) return;
            document.querySelectorAll('[data-website-usage-status]').forEach(label => {
                label.textContent = __('Could not update website actions. Try Refresh.', 'gpt3-ai-content-generator');
            });
        } finally {
            document.querySelectorAll('[data-website-usage-refresh]').forEach(button => { button.disabled = false; });
            state.request = null;
            if (generation !== cloudConnectionRevision()) void refreshWebsiteActions(document, true);
        }
    })();
    return state.request;
}

function bindWebsiteActions() {
    if (window.aipkitWebsiteUsageBound) return;
    window.aipkitWebsiteUsageBound = true;
    document.addEventListener('click', event => {
        if (event.target.closest('[data-website-usage-refresh]')) void refreshWebsiteActions(document, true);
    });
    window.addEventListener('aipkit:cloud-connection-changed', event => {
        if (event.detail?.action === 'balance' && event.detail?.connected) { void refreshWebsiteActions(); return; }
        // Read-only refreshes keep the last known card visible, like AI credits.
        // A disconnected or replaced account must not show the previous account's balance.
        const readOnlyActions = ['view', 'refresh', 'sync', 'verify', 'check_email'];
        if (!event.detail?.connected || event.detail?.accountChanged || !readOnlyActions.includes(event.detail?.action)) {
            document.querySelectorAll('[data-aipkit-website-usage]').forEach(host => { host.hidden = true; });
        }
        void refreshWebsiteActions(document, true);
    });
    window.addEventListener('aipkit:provider-modal-opened', event => {
        void refreshWebsiteActions(event.detail.modal);
    });
}

export function bindCloudConnection(root) {
    bindWebsiteActions();
    if (!root || root.dataset.cloudConnectionBound) return;
    root.dataset.cloudConnectionBound = 'true';

    const updateConsent = () => {
        root.querySelectorAll('form.aipkit_cloud_connect').forEach(form => {
            form.querySelectorAll('[name="cloud_action"]').forEach(button => {
                button.disabled = !form.elements.cloud_consent.checked || accountEmail.isEditing();
            });
        });
    };
    const accountEmail = bindAccountEmail(root, {
        onEdit: updateConsent,
        busy: async (button, work) => {
            const section = button.closest('.aipkit_cloud_connection');
            if (!section || section.getAttribute('aria-busy') === 'true') return;
            const controls = [...section.querySelectorAll('button, input')].filter(control => !control.disabled);
            section.setAttribute('aria-busy', 'true');
            controls.forEach(control => { control.disabled = true; });
            button.setAttribute('aria-busy', 'true');
            button.classList.add('aipkit_loading');
            try { await work(); }
            finally {
                section.removeAttribute('aria-busy');
                controls.forEach(control => { control.disabled = false; });
                button.removeAttribute('aria-busy');
                button.classList.remove('aipkit_loading');
            }
        },
        refresh: async () => {
            const nonce = root.querySelector('#aipkit_cloud_connection [name="_wpnonce"]')?.value;
            const response = await window.aipkit_apiRequest('aipkit_cloud_connection', { _wpnonce: nonce, cloud_action: 'view' });
            applyCloudConnectionResponse(response, 'email', root);
            return response;
        },
    });

    root.addEventListener('change', event => {
        if (event.target.matches('[name="cloud_consent"]')) updateConsent();
    });
    root.addEventListener('input', event => {
        if (!event.target.matches('[name="cloud_email"]')) return;
        const form = event.target.closest('form');
        const check = form.querySelector('[value="check_email"]');
        if (check) check.hidden = true;
        const connect = form.querySelector('[name="cloud_action"][value="connect"]');
        if (connect) connect.firstChild.textContent = __('Connect', 'gpt3-ai-content-generator');
    });

    async function update(action, form, button) {
        const section = form.closest('.aipkit_cloud_connection');
        if (!section || section.getAttribute('aria-busy') === 'true' || accountEmail.isEditing()) return;
        const fields = {
            _wpnonce: form.elements._wpnonce.value,
            cloud_action: action,
            cloud_email: form.elements.cloud_email?.value || '',
            cloud_consent: form.elements.cloud_consent?.checked ? 'yes' : '',
            cloud_marketing: form.elements.cloud_marketing?.checked ? 'yes' : '',
        };
        const finishChange = action === 'checkout' ? () => {} : beginCloudConnectionChange();
        const checkoutWindow = action === 'checkout' ? window.open('', '_blank') : null;
        if (checkoutWindow) checkoutWindow.opener = null;
        const feedback = section.querySelector('[data-aipkit-cloud-feedback]');
        feedback.className = 'aipkit_model_sync_status aipkit_cloud_feedback loading';
        feedback.textContent = action === 'connect' && !section.closest('[data-aipkit-provider-modal]')
            ? __('Connecting… this can take a few seconds.', 'gpt3-ai-content-generator')
            : '';
        const controls = [...section.querySelectorAll('button, input')].filter((control) => !control.disabled);
        controls.forEach((control) => { control.disabled = true; });
        section.setAttribute('aria-busy', 'true');
        if (button) button.disabled = true;
        button?.classList.add('aipkit_loading');
        button?.setAttribute('aria-busy', 'true');
        try {
            const response = await window.aipkit_apiRequest('aipkit_cloud_connection', fields);
            reportConnectionDiagnostic(response);
            if (action === 'checkout') {
                const url = new URL(response.checkout_url);
                if (url.protocol !== 'https:' || url.hostname !== 'checkout.freemius.com') throw new Error(__('Cloud returned an invalid checkout link.', 'gpt3-ai-content-generator'));
                if (checkoutWindow) {
                    checkoutWindow.location.replace(url.href);
                    feedback.className = 'aipkit_model_sync_status aipkit_cloud_feedback';
                } else {
                    feedback.className = 'aipkit_model_sync_status aipkit_cloud_feedback success';
                    feedback.replaceChildren(document.createTextNode(__('Open checkout: ', 'gpt3-ai-content-generator')));
                    const link = document.createElement('a');
                    link.href = url.href;
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';
                    link.textContent = __('Open checkout', 'gpt3-ai-content-generator');
                    feedback.append(link);
                }
                return;
            }
            applyCloudConnectionResponse(response, action, root);
        } catch (error) {
            reportConnectionDiagnostic(error.data);
            checkoutWindow?.close();
            feedback.className = 'aipkit_model_sync_status aipkit_cloud_feedback error';
            feedback.textContent = error.message || __('Cloud could not be updated. Try again.', 'gpt3-ai-content-generator');
        } finally {
            section.removeAttribute('aria-busy');
            controls.forEach((control) => { control.disabled = false; });
            if (button) button.disabled = false;
            button?.classList.remove('aipkit_loading');
            button?.removeAttribute('aria-busy');
            updateConsent();
            finishChange();
        }
    }
    root.addEventListener('submit', (event) => {
        const form = event.target;
        if (!form.closest('.aipkit_cloud_connection')) return;
        event.preventDefault();
        const button = event.submitter;
        if (!button?.matches('[name="cloud_action"]') || !form.reportValidity()) return;
        // The panel footer's Disconnect asks first, as every provider's does.
        if (button.dataset?.confirmText && typeof window.aipkit_showConfirmModal === 'function') {
            window.aipkit_showConfirmModal(button.dataset.confirmText, {
                title: button.dataset.confirmTitle || '',
                confirmText: button.dataset.confirmButton || '',
                cancelText: button.dataset.cancelButton || '',
                variant: 'danger',
                onConfirm: () => update(button.value, form, button),
                onCancel: () => button.isConnected && button.focus(),
            });
            return;
        }
        return update(button.value, form, button);
    });
    checkEmailOnReturn(root);
    return accountEmail;
}

/**
 * Back from Freemius's email confirmation, routed here (marker `aipkit_email_return=1`) because its Account page
 * doesn't exist while the SDK is anonymous: run the existing nonce-protected "I've verified my email" action once,
 * which asks Cloud for a fresh, uncached verification status (status(true)). The marker is removed first, so a reload
 * never repeats it. The page's own load refresh is cached and isn't enough on its own.
 */
function checkEmailOnReturn(root) {
    const url = new URL(window.location.href);
    if (url.searchParams.get('aipkit_email_return') !== '1') return;
    url.searchParams.delete('aipkit_email_return');
    window.history.replaceState(window.history.state, '', url.toString());
    const verify = root?.querySelector('#aipkit_cloud_connection .aipkit_cloud_email_recovery button[name="cloud_action"][value="verify"]');
    if (verify?.form) verify.form.requestSubmit(verify);
}

window.aipkit_initCloudConnection = () => bindCloudConnection(document.getElementById('aipkit_settings_container'));

if (window.aipkit_dashboard?.cloudNonce) {
    watchCloudConnection('dashboard', (isCurrent) => window.aipkit_apiRequest('aipkit_cloud_connection', {
        _wpnonce: window.aipkit_dashboard.cloudNonce, cloud_action: 'view',
    }, { isCurrent }), (response) => applyCloudConnectionResponse(response, 'view'));
}
