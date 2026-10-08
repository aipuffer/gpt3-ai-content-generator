import { bindAccountEmail } from '../shared/account-email.js';
import { reportConnectionDiagnostic } from '../shared/connection-diagnostics.js';
import { beginCloudConnectionChange, publishCloudConnectionChange, watchCloudConnection } from '../shared/cloud-connection-sync.js';

/**
 * First-run setup (admin/views/onboarding/page.php). Steps are sections toggled by data-step;
 * Setup actions and the shared provider connection check run server-side.
 */
const __ = window.wp?.i18n?.__ || ((text) => text);
const sprintf = window.wp?.i18n?.sprintf || ((text, ...args) => args.reduce((out, arg) => out.replace(/%s/, arg), text));

const config = window.aipkitSetup || {};
const root = document.getElementById('aipkit-setup');

async function post(action, fields, keepalive = false) {
    const body = new FormData();
    body.append('action', action);
    Object.entries(fields).forEach(([key, value]) => {
        if (Array.isArray(value)) value.forEach((item) => body.append(key + '[]', item));
        else body.append(key, value);
    });
    let json = null;
    try {
        const response = await fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body, keepalive });
        json = await response.json();
    } catch (error) {
        throw new Error(__('The request failed. Check your connection and try again.', 'gpt3-ai-content-generator'));
    }
    reportConnectionDiagnostic(json?.data);
    if (!json || !json.success) {
        const error = json?.data || json?.error;
        throw Object.assign(new Error(typeof error?.message === 'string' ? error.message : __('Something went wrong. Please try again.', 'gpt3-ai-content-generator')), { data: error });
    }
    return json.data || {};
}

let cloudConnected = config.cloudConnected === true;
const outcomeOps = new Set(['goals', 'cloud', 'use_key', 'chat_result', 'publish_bot', 'draft', 'template', 'product', 'save_product', 'finish', 'skip']);
const reportProgress = () => {
    if (cloudConnected) void post('aipkit_onboarding', { _wpnonce: config.nonce, op: 'progress' }, true).catch(() => {});
};
const setup = async (op, fields = {}) => {
    const changingCloud = ['cloud', 'cloud_disconnect'].includes(op);
    const finishChange = changingCloud ? beginCloudConnectionChange() : () => {};
    try {
        const data = await post('aipkit_onboarding', { _wpnonce: config.nonce, op, ...fields });
        if (typeof data.connected === 'boolean') cloudConnected = data.connected;
        if (outcomeOps.has(op)) reportProgress();
        if (changingCloud) publishCloudConnectionChange();
        return data;
    } finally { finishChange(); }
};

if (root) {
    reportProgress();
    const $ = (selector) => root.querySelector(selector);
    const $$ = (selector) => [...root.querySelectorAll(selector)];
    const field = (name) => $(`[data-field="${name}"]`);
    const errorBox = $('.aipkit-setup__error');
    const providerKeys = new Map();
    let keyRequest = null;
    if (field('api_key').value) providerKeys.set(field('provider').value, field('api_key').value);

    const state = {
        step: 'welcome',
        goals: Array.isArray(config.goals) && config.goals.length ? config.goals : ['chatbot'],
        power: 'cloud',
        connection: '',
        keyReady: false,
        busy: false,
        // The connection the chatbot should use, and the one it was last loaded with.
        chatModel: '',
        chatWidget: '',
        product: null,
    };
    const backTo = { goals: 'welcome', connect: 'goals', 'try-chat': 'connect', 'try-write': 'connect', 'try-auto': 'connect', 'try-forms': 'connect', 'try-products': 'connect' };
    const progressIndex = { goals: 0, connect: 1, 'try-chat': 2, 'try-write': 2, 'try-auto': 2, 'try-forms': 2, 'try-products': 2 };
    const firstSteps = { chatbot: 'try-chat', write: 'try-write', auto: 'try-auto', forms: 'try-forms', products: 'try-products' };

    function showError(message) {
        errorBox.textContent = message || '';
        errorBox.hidden = !message;
    }

    async function busy(button, work) {
        if (!button || state.busy) return undefined;
        state.busy = true;
        const pausedChat = state.step === 'try-chat';
        if (pausedChat) pauseChatWidget();
        const pausedChatGeneration = chatLoads;
        // The widget owns its controls, including streaming and consent state.
        const controls = $$('button, input, select, textarea').filter(control => !control.closest('.aipkit_chat_container')).map((control) => [control, control.disabled]);
        controls.forEach(([control]) => { control.disabled = true; });
        button.setAttribute('aria-busy', 'true');
        showError('');
        try {
            return await work();
        } catch (error) {
            showError(error.message);
            return undefined;
        } finally {
            controls.forEach(([control, disabled]) => { control.disabled = disabled; });
            button.removeAttribute('aria-busy');
            state.busy = false;
            if (pausedChat && state.step === 'try-chat' && chatLoads === pausedChatGeneration) void loadChatWidget();
            renderGoals();
            renderPower();
            if ((button.hidden || button.isConnected === false) && state.power === 'cloud' && root.dataset.cloudReady === 'true') {
                $('[data-action="after-connect"]').focus();
            }
        }
    }

    function go(step) {
        if (state.step === 'try-chat' && step !== 'try-chat') pauseChatWidget();
        if (step !== 'connect' && keyRequest) { keyRequest = null; renderPower(); }
        if (step !== 'connect') accountEmail.cancel();
        state.step = step;
        root.dataset.step = step;
        showError('');
        $$('.aipkit-setup__step').forEach((section) => { section.hidden = section.dataset.step !== step; });
        const current = progressIndex[step];
        root.querySelector('.aipkit-setup__progress').hidden = current === undefined;
        $$('.aipkit-setup__progress li').forEach((item, index) => {
            item.classList.toggle('is-current', index === current);
            item.classList.toggle('is-done', current !== undefined && index < current);
            item.querySelector('.aipkit-setup__dot').textContent = current !== undefined && index < current ? '✓' : String(index + 1);
        });
        root.querySelector('[data-action="skip"]').textContent = step === 'done' ? __('Close', 'gpt3-ai-content-generator') : __('Skip setup', 'gpt3-ai-content-generator');
        if (step === 'done') renderSummary();
        if (step === 'try-chat') {
            $$('[data-connection-label]').forEach((el) => { el.textContent = sprintf(__('Uses %s', 'gpt3-ai-content-generator'), state.connection); });
            void loadChatWidget();
        }
        const heading = $(`.aipkit-setup__step[data-step="${step}"] h1`);
        if (heading) { heading.setAttribute('tabindex', '-1'); heading.focus({ preventScroll: true }); }
        root.scrollTop = 0;
    }

    function renderGoals() {
        $$('[data-goal]').forEach((card) => card.setAttribute('aria-pressed', state.goals.includes(card.dataset.goal) ? 'true' : 'false'));
        const count = state.goals.length;
        $('[data-goal-hint]').textContent = count ? sprintf(__('%s selected', 'gpt3-ai-content-generator'), String(count)) : __('Choose at least one', 'gpt3-ai-content-generator');
        $('[data-action="save-goals"]').disabled = state.busy || !count;
    }

    function renderPower() {
        root.dataset.power = state.power;
        $$('[data-power-option]').forEach((option) => option.classList.toggle('is-on', option.dataset.powerOption === state.power));
        const next = $('[data-action="after-connect"]');
        next.textContent = state.power === 'later' ? __('Continue without AI', 'gpt3-ai-content-generator') : __('Continue', 'gpt3-ai-content-generator');
        const editingEmail = !$('[data-cloud-email-editor]').hidden;
        next.disabled = state.busy || (state.power === 'cloud' && editingEmail) || (state.power !== 'later' && !(state.power === 'cloud' ? root.dataset.cloud === 'connected' && root.dataset.cloudReady === 'true' : state.keyReady));
        $('[data-action="cloud"]').disabled = state.busy || editingEmail || !field('consent').checked;
        $('[data-action="check-email"]').disabled = state.busy || editingEmail || !field('consent').checked;
        $('[data-action="retry-cloud"]').disabled = state.busy || editingEmail;
        $('[data-action="disconnect-cloud"]').disabled = state.busy || editingEmail;
        $$('[data-cloud-recovery] button').forEach(button => { button.disabled = state.busy || editingEmail; });
        field('api_key').disabled = state.busy || keyRequest !== null;
        field('api_key').setAttribute('aria-busy', String(keyRequest !== null));
        $('[data-action="key"]').disabled = state.busy || keyRequest !== null;
    }

    async function loadProviderKey() {
        const provider = field('provider').value;
        if (keyRequest?.provider === provider) return;
        keyRequest = null;
        const input = field('api_key');
        input.type = 'password';
        input.value = providerKeys.get(provider) || '';
        if (providerKeys.has(provider) || field('provider').selectedOptions[0]?.dataset.hasKey !== 'true') {
            providerKeys.set(provider, input.value);
            renderPower();
            return;
        }
        const request = { provider };
        keyRequest = request;
        renderPower();
        try {
            const data = await post('aipkit_reveal_settings_credential', {
                _ajax_nonce: config.syncNonce, scope: 'provider', identifier: provider,
            });
            if (keyRequest !== request) return;
            input.value = String(data.credential || '');
            providerKeys.set(provider, input.value);
        } catch (error) {
            if (keyRequest === request) showError(error.message);
        } finally {
            if (keyRequest === request) { keyRequest = null; renderPower(); }
        }
    }

    function updateCloud(data) {
        cloudConnected = data.connected === true;
        root.dataset.cloud = data.connected ? 'connected' : 'none';
        $('[data-action="disconnect-cloud"]').hidden = !data.connected;
        root.dataset.cloudReady = String(data.ready === true);
        root.dataset.cloudPending = String(data.pendingEmail === true);
        $('[data-cloud-account-controls]').hidden = !data.registered;
        $('[data-cloud-email-field]').hidden = !!data.registered;
        $('[data-cloud-marketing]').hidden = !!data.registered;
        $('[data-cloud-email]').textContent = data.email || '';
        const avatar = root.querySelector('.aipkit_cloud_account_avatar');
        if (avatar) avatar.textContent = ([...(data.email || '')][0] || '').toLocaleUpperCase();
        const caption = root.querySelector('.aipkit_cloud_account_caption');
        if (caption) caption.hidden = !!data.connected;
        const verified = root.querySelector('.aipkit_cloud_account_verified');
        if (verified) verified.hidden = !data.connected || data.emailVerified !== true;
        const accountChange = root.querySelector('[data-cloud-account-change]');
        if (accountChange) {
            accountChange.textContent = data.accountChangeMessage || '';
            accountChange.hidden = !data.accountChangeMessage;
        }
        $('[data-cloud-manage-email]').href = data.manageEmailUrl || '';
        $('[data-cloud-manage-email]').hidden = !!data.emailUpdate || !data.manageEmailUrl;
        $('[data-action="edit-email"]').hidden = !data.emailUpdate;
        const editor = $('[data-cloud-email-editor]');
        editor.dataset.emailAction = data.emailUpdate?.action || '';
        editor.dataset.emailSecurity = data.emailUpdate?.security || '';
        editor.dataset.emailModule = data.emailUpdate?.moduleId || '';
        $('[data-action="check-email"]').hidden = !data.pendingEmail;
        $('[data-action="cloud"]').textContent = data.pendingEmail ? __('Resend email', 'gpt3-ai-content-generator') : __('Get 25 free credits', 'gpt3-ai-content-generator');
        $('[data-action="cloud"]').classList.toggle('aipkit-setup__btn--ghost', !!data.pendingEmail);
        $('[data-cloud-recovery]').innerHTML = data.emailRecoveryHtml || '';
        $('[data-cloud-message]').textContent = data.message || '';
        $('[data-cloud-message]').hidden = !data.message;
        $('[data-action="retry-cloud"]').hidden = !data.retry;
        if (!data.connected && state.chatModel.startsWith('AIPufferCloud:')) {
            resetChatWidget(); state.connection = ''; state.chatModel = '';
        }
        if (data.ready && data.provider && data.model && state.power === 'cloud') useConnection(data);
        renderPower();
    }

    watchCloudConnection('onboarding', () => post('aipkit_onboarding', { _wpnonce: config.nonce, op: 'cloud_state' }), updateCloud);

    const accountEmail = bindAccountEmail(root, {
        busy,
        onEdit: renderPower,
        refresh: async () => {
            root.dataset.cloudReady = 'false';
            const data = await setup('cloud_state');
            updateCloud(data);
            publishCloudConnectionChange();
            return data;
        },
    });

    async function connectCloud(checkEmail = false) {
        const data = await setup('cloud', {
            email: field('cloud_email').value, check_email: checkEmail ? 'yes' : '',
            consent: field('consent').checked ? 'yes' : '',
            marketing: field('marketing').checked ? 'yes' : '',
        });
        updateCloud(data);
        return data;
    }

    function useConnection(data) {
        const model = `${data.provider}:${data.model}`;
        if (state.chatModel && state.chatModel !== model) {
            resetChatWidget();
            window.aipkit_regenerateConversationUUID?.();
        }
        state.chatModel = model;
        state.connection = data.label;
    }

    function renderSummary() {
        const tools = state.goals.includes('explore')
            ? [__('All tools', 'gpt3-ai-content-generator')]
            : state.goals.map((goal) => $(`[data-goal="${goal}"]`).dataset.moduleName);
        const unique = [...new Set(tools)];
        const list = $('[data-summary-tools]');
        list.replaceChildren(...unique.map((name) => Object.assign(document.createElement('span'), { className: 'aipkit-setup__pill', textContent: name })));
        $('[data-summary-connection]').textContent = state.power === 'later' || !state.connection ? __('Not connected yet', 'gpt3-ai-content-generator') : state.connection;
        $('[data-summary-note]').hidden = state.goals.includes('explore');
        $('[data-action="finish"]').textContent = sprintf(__('Go to %s', 'gpt3-ai-content-generator'), state.goals.includes('explore') ? __('dashboard', 'gpt3-ai-content-generator') : unique[0]);
    }

    function firstResultStep() {
        if (state.power === 'later') return 'done';
        return firstSteps[state.goals[0]] || 'done';
    }

    // The real chatbot (the Chatbot module's widget), loaded for the connection chosen in this setup.
    let chatLoads = 0, chatNode = null;
    const chatRecord = () => chatNode && window.aipkitChatInstances?.[chatNode.id];
    function pauseChatWidget() {
        chatLoads++;
        chatRecord()?.lifecycle?.suspend();
        // A load still in flight must not initialize a hidden chatbot after leaving the step.
        if (!state.chatWidget) { $('[data-chat-widget]').innerHTML = ''; chatNode = null; }
    }
    function resetChatWidget() {
        pauseChatWidget();
        state.chatWidget = '';
        chatNode = null;
        $('[data-chat-widget]').innerHTML = '';
    }
    function chatStatus(message, failed = false) {
        $('[data-chat-message]').textContent = message;
        $('[data-chat-status]').hidden = !message;
        $('[data-action="load-chat"]').hidden = !failed;
    }

    async function loadChatWidget(force = false) {
        const box = $('[data-chat-widget]');
        if (!force && state.chatWidget && state.chatWidget === state.chatModel) {
            chatRecord()?.lifecycle?.resume();
            return;
        }
        resetChatWidget();
        const load = ++chatLoads;
        const model = state.chatModel;
        const current = () => load === chatLoads && state.step === 'try-chat' && state.chatModel === model;
        chatStatus(__('Loading your chatbot…', 'gpt3-ai-content-generator'));
        try {
            const data = await setup('chat_widget');
            if (!current()) return;
            box.innerHTML = data.html || '';
            const chat = box.querySelector('.aipkit_chat_container');
            if (!chat || typeof window.aipkit_initializeChatInstance !== 'function') throw new Error(__('Your chatbot could not be shown. Open it in Chatbots instead.', 'gpt3-ai-content-generator'));
            chatNode = chat;
            // Keep the first-result metric tied to a completed reply, never a failed submission.
            chat.addEventListener('aipkit:messageReceived', () => { void setup('chat_result').catch(() => {}); }, { once: true });
            await window.aipkit_initializeChatInstance(chat);
            if (!current()) return;
            if (!chat.classList.contains('aipkit-initialized')) throw new Error(__('Your chatbot could not be shown. Try again or open it in Chatbots.', 'gpt3-ai-content-generator'));
            state.chatWidget = model;
            chatStatus('');
        } catch (error) {
            if (!current()) return;
            resetChatWidget();
            chatStatus(error.message, true);
        }
    }

    async function leave(op, button) {
        await busy(button, async () => {
            const data = await setup(op);
            window.location.assign(data.url || config.dashboardUrl);
        });
    }

    // Shared creation result for AI form templates and the blog automation form.
    async function createTemplate(template, trigger, fields = {}) {
        const section = trigger.closest('.aipkit-setup__step');
        await busy(trigger, async () => {
            const data = await setup('template', { template, ...fields });
            section.querySelectorAll('[data-templates], [data-template-options]').forEach((el) => { el.hidden = true; });
            section.querySelector('[data-template-skip]').hidden = true;
            const result = section.querySelector('[data-template-result]');
            result.querySelector('[data-result-message]').textContent = data.message;
            [['primary', data.primary, data.primaryLabel], ['secondary', data.secondary, data.secondaryLabel]].forEach(([key, url, label]) => {
                const link = result.querySelector(`[data-result-${key}]`);
                link.hidden = !url;
                if (url) { link.href = url; link.textContent = label; }
            });
            result.hidden = false;
            result.querySelector('[data-result-primary]:not([hidden]), [data-action="done"]').focus();
        });
    }

    const actions = {
        start: () => go('goals'),
        back: () => go(backTo[state.step] || 'welcome'),
        skip: (button) => leave(state.step === 'done' ? 'finish' : 'skip', button),
        finish: (button) => leave('finish', button),
        done: () => go('done'),
        'save-goals': (button) => busy(button, async () => {
            state.goals = (await setup('goals', { goals: state.goals })).goals;
            go('connect');
        }),
        power: (button) => {
            accountEmail.cancel();
            state.power = button.dataset.power;
            if (state.power !== 'key') keyRequest = null;
            renderPower();
            if (state.power === 'key') return loadProviderKey();
        },
        cloud: (button) => busy(button, async () => {
            if (!$('[data-cloud-email-field]').hidden && !field('cloud_email').reportValidity()) return;
            await connectCloud();
        }),
        'check-email': (button) => busy(button, () => connectCloud(true)),
        'retry-cloud': (button) => busy(button, () => connectCloud()),
        'disconnect-cloud': (button) => busy(button, async () => {
            const data = await setup('cloud_disconnect');
            updateCloud(data);
        }),
        key: (button) => keyRequest ? undefined : busy(button, async () => {
            const provider = field('provider').value;
            const apiKey = field('api_key').value.trim();
            const providerLabel = field('provider').selectedOptions[0].textContent;
            const message = $('[data-key-message]');
            message.hidden = true;
            state.keyReady = false;
            renderPower();
            await post('aipkit_provider_connection', { _ajax_nonce: config.syncNonce, operation: 'connect', provider, api_key: apiKey });
            if (field('provider').value !== provider || field('api_key').value.trim() !== apiKey) return;
            providerKeys.set(provider, apiKey);
            state.keyReady = true;
            message.querySelector('span').textContent = sprintf(__('Key works. %s is ready to use.', 'gpt3-ai-content-generator'), providerLabel);
            message.hidden = false;
            renderPower();
        }),
        'after-connect': (button) => busy(button, async () => {
            if (state.power === 'cloud') {
                const data = await connectCloud();
                if (!data.ready) return;
            }
            else if (state.power === 'key') {
                if (!state.keyReady) throw new Error(__('Check your API key before continuing.', 'gpt3-ai-content-generator'));
                useConnection(await setup('use_key', { provider: field('provider').value }));
            }
            // Cloud connect already registers the site; the optional updates box covers the other paths.
            if (state.power !== 'cloud' && field('updates')?.checked && !state.updatesSent) {
                state.updatesSent = true;
                await setup('updates').catch(() => {});
            }
            go(firstResultStep());
        }),
        'save-product': (button) => busy(button, async () => {
            if (!state.product) return;
            const data = await setup('save_product', state.product);
            state.product = null;
            $('[data-product-replaces]').hidden = true;
            $('[data-product-title]').textContent = data.message;
            button.hidden = true;
            const link = $('[data-product-edit]');
            link.href = data.editUrl;
            link.hidden = false;
        }),
        'copy-product': (button) => {
            const text = button.closest('[data-product-result]').dataset.text || '';
            navigator.clipboard?.writeText(text).then(() => { button.textContent = __('Copied', 'gpt3-ai-content-generator'); }, () => showError(__('Copying failed. Select the text and copy it yourself.', 'gpt3-ai-content-generator')));
        },
        'load-chat': () => loadChatWidget(true),
        'publish-bot': (button) => busy(button, async () => {
            await setup('publish_bot');
            const item = $('[data-bot-visibility] span');
            item.textContent = __('Live on your site as a chat bubble', 'gpt3-ai-content-generator');
            button.hidden = true;
            button.nextElementSibling.textContent = __('Continue', 'gpt3-ai-content-generator');
        }),
    };

    root.addEventListener('click', (event) => {
        if (state.busy) { event.preventDefault(); return; }
        const goal = event.target.closest('[data-goal]');
        if (goal) {
            const id = goal.dataset.goal;
            if (id === 'explore') state.goals = state.goals.includes('explore') ? [] : ['explore'];
            else {
                state.goals = state.goals.filter((item) => item !== 'explore');
                state.goals = state.goals.includes(id) ? state.goals.filter((item) => item !== id) : state.goals.concat(id);
            }
            renderGoals();
            return;
        }
        const template = event.target.closest('[data-template]');
        if (template) { createTemplate(template.dataset.template, template); return; }
        const idea = event.target.closest('[data-idea]');
        if (idea) { field('topic').value = idea.dataset.idea; return; }
        const button = event.target.closest('[data-action]');
        if (button && actions[button.dataset.action]) {
            event.preventDefault();
            actions[button.dataset.action](button);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || event.defaultPrevented || event.isComposing) return;
        const record = chatRecord();
        if (state.step === 'try-chat' && (record?.state?.isFullscreen || chatNode?.contains(event.target))) {
            event.preventDefault();
            if (record?.state?.isFullscreen) record.actions.toggleFullscreenAction();
            return;
        }
        // Never abandon a connect/check/draft request halfway.
        if (state.busy) return;
        event.preventDefault();
        // Same as the Skip setup button (Close on the last screen).
        actions.skip(root.querySelector('[data-action="skip"]'));
    });

    root.addEventListener('submit', (event) => {
        if (!event.target.closest('[data-cloud-recovery]')) return;
        event.preventDefault();
        const button = event.submitter;
        if (!button || state.busy) return;
        busy(button, () => connectCloud());
    });

    field('consent').addEventListener('change', renderPower);
    field('cloud_email').addEventListener('input', () => {
        if (root.dataset.cloudPending !== 'true') return;
        root.dataset.cloudPending = 'false';
        $('[data-action="check-email"]').hidden = true;
        $('[data-action="cloud"]').textContent = __('Get 25 free credits', 'gpt3-ai-content-generator');
        $('[data-action="cloud"]').classList.remove('aipkit-setup__btn--ghost');
        $('[data-cloud-message]').hidden = true;
    });
    const invalidateKey = () => { state.keyReady = false; $('[data-key-message]').hidden = true; renderPower(); };
    field('provider').addEventListener('change', () => {
        invalidateKey();
        showError('');
        return loadProviderKey();
    });
    field('api_key').addEventListener('input', () => {
        keyRequest = null;
        providerKeys.set(field('provider').value, field('api_key').value);
        invalidateKey();
    });
    field('api_key').addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); actions.key($('[data-action="key"]')); } });

    const productSelect = field('product_id');
    productSelect?.addEventListener('change', () => {
        $('[data-product-name]').hidden = productSelect.value !== '0';
        $('[data-product-result]').hidden = true;
        state.product = null;
    });

    $('[data-product-form]')?.addEventListener('submit', (event) => {
        event.preventDefault();
        const wait = $('[data-product-wait]');
        const result = $('[data-product-result]');
        busy(event.target.querySelector('button'), async () => {
            wait.hidden = false;
            result.hidden = true;
            state.product = null;
            try {
                const data = await setup('product', {
                    product_id: productSelect?.value || '0',
                    name: field('name').value,
                    details: field('details').value,
                });
                result.dataset.text = data.text;
                state.product = { result_id: data.result_id, product_id: data.product_id };
                $('[data-product-title]').textContent = data.name;
                const short = $('[data-product-short]');
                short.textContent = data.short;
                short.hidden = !data.short;
                $('[data-product-description]').innerHTML = data.description;
                $('[data-product-replaces]').hidden = !data.replaces;
                $('[data-action="save-product"]').hidden = !Number(data.product_id);
                $('[data-product-edit]').hidden = true;
                $('[data-action="copy-product"]').textContent = __('Copy text', 'gpt3-ai-content-generator');
                result.hidden = false;
            } finally {
                wait.hidden = true;
            }
        });
    });

    $('[data-template-options="auto_blog"]').addEventListener('submit', (event) => {
        event.preventDefault();
        const form = event.target;
        createTemplate('auto_blog', form.querySelector('button[type="submit"]'), {
            topics: field('topics').value,
            publish: form.querySelector('input[name="aipkit_setup_publish"]:checked')?.value || '',
        });
    });

    $('[data-draft-form]').addEventListener('submit', (event) => {
        event.preventDefault();
        const topic = field('topic').value.trim();
        if (!topic) return;
        const wait = $('[data-draft-wait]');
        busy(event.target.querySelector('button'), async () => {
            wait.hidden = false;
            $('[data-draft]').hidden = true;
            try {
                const data = await setup('draft', { topic });
                $('[data-draft-title]').textContent = data.title;
                $('[data-draft-excerpt]').textContent = data.excerpt;
                $('[data-draft-meta]').textContent = sprintf(__('Draft saved to Posts · About %s words', 'gpt3-ai-content-generator'), Number(data.words).toLocaleString());
                $('[data-draft-edit]').href = data.editUrl;
                $('[data-draft]').hidden = false;
                $('[data-draft-skip]').hidden = true;
            } finally {
                wait.hidden = true;
            }
        });
    });

    renderGoals();
    renderPower();
    go(window.location.hash === '#connect' ? 'connect' : 'welcome');
}
