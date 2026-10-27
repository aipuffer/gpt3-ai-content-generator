import { bindAccountEmail } from '../shared/account-email.js';
import { reportConnectionDiagnostic } from '../shared/connection-diagnostics.js';
import { beginCloudConnectionChange, publishCloudConnectionChange, watchCloudConnection } from '../shared/cloud-connection-sync.js';

/**
 * First-run setup (admin/views/onboarding/page.php). Steps are sections toggled by data-step;
 * Setup actions and the shared provider connection check run server-side.
 */
const __ = window.wp?.i18n?.__ || ((text) => text);
const sprintf = window.wp?.i18n?.sprintf || ((text, ...args) => { let next = 0; return text.replace(/%(?:(\d+)\$)?s/g, (_, position) => String(args[position ? position - 1 : next++])); });

const config = window.aipkitSetup || {};
// wp_localize_script serializes top-level booleans as strings.
['cloudConnected', 'resume', 'cloudConsent', 'websiteLater'].forEach(key => { config[key] = config[key] === true || config[key] === '1'; });
const root = document.getElementById('aipkit-setup');

async function post(action, fields, keepalive = false, signal) {
    const body = new FormData();
    body.append('action', action);
    Object.entries(fields).forEach(([key, value]) => {
        if (Array.isArray(value)) value.forEach((item) => body.append(key + '[]', item));
        else body.append(key, value);
    });
    let json = null;
    let httpStatus = 0;
    try {
        const response = await fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body, keepalive, signal });
        httpStatus = response.status || 0;
        json = await response.json();
    } catch (error) {
        throw new Error(__('The request failed. Check your connection and try again.', 'gpt3-ai-content-generator'));
    }
    reportConnectionDiagnostic(json?.data);
    if (!json || !json.success) {
        const error = json?.data || json?.error;
        throw Object.assign(new Error(typeof error?.message === 'string' ? error.message : __('Something went wrong. Please try again.', 'gpt3-ai-content-generator')), { data: error, status: httpStatus });
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
        goals: Array.isArray(config.goals) ? config.goals : [],
        assistants: Array.isArray(config.assistants) ? config.assistants : [],
        assistant: '',
        power: ['cloud', 'key', 'later'].includes(config.power) ? config.power : 'cloud',
        connection: typeof config.connection === 'string' ? config.connection : '',
        aiChoice: false,
        // Website setup uses Cloud for the account; retain the separate AI provider preference.
        aiPower: ['cloud', 'key', 'later'].includes(config.power) ? config.power : 'cloud',
        availableCredits: typeof config.availableCredits === 'string' ? config.availableCredits : null,
        keyReady: false,
        busy: false,
        connectionCheck: 'idle',
        // The connection the chatbot should use, and the one it was last loaded with.
        chatModel: '',
        chatWidget: '',
        product: null,
        // Website picked with other tools: Do this later moves on to them (saved, so a reload keeps it); the checklist keeps website open.
        websiteLater: config.websiteLater === true,
        // Assistant links started on this screen, and whether the Connect click copied the address.
        linkGrants: [],
        addressCopied: false,
        // What setup already produced, for the checklist: tried, published, created.
        results: new Set(Array.isArray(config.results) ? config.results : []),
        saved: new Set(Array.isArray(config.saved) ? config.saved : []),
    };
    let checkRequest = null;
    let pageGone = false;
    const verification = { generation: 0, timer: null, requestTimer: null, controller: null, attempt: '', grant: '', until: 0, status: 'idle' };
    // A link waiting for Allow: a short automatic check while this screen is visible, and other ways to finish after a minute.
    const linkWatch = { grant: '', timer: null, slowTimer: null, since: 0, stopped: false, turn: 0, controller: null, generation: 0 };
    const backTo = { goals: 'welcome', connect: 'goals', 'try-chat': 'connect', 'try-write': 'connect', 'try-auto': 'connect', 'try-forms': 'connect', 'try-products': 'connect' };
    const firstSteps = { chatbot: 'try-chat', forms: 'try-forms', write: 'try-write' };
    const writingSteps = ['try-write', 'try-products', 'try-auto'];

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
        if (step !== 'connect') { pauseWebsiteVerification(); watchAssistantLink(null); }
        if (step === 'connect' && state.step !== 'connect' && !checkRequest) { state.connectionCheck = 'idle'; }
        if (state.step === 'try-chat' && step !== 'try-chat') pauseChatWidget();
        if (step !== 'connect' && keyRequest) { keyRequest = null; renderPower(); }
        if (step !== 'connect') accountEmail.cancel();
        state.step = step;
        root.dataset.step = step;
        showError('');
        $$('.aipkit-setup__step').forEach((section) => { section.hidden = section.dataset.step !== step; });
        renderProgress();
        root.querySelector('[data-action="skip"]').textContent = step === 'done' ? __('Close', 'gpt3-ai-content-generator') : __('Skip setup', 'gpt3-ai-content-generator');
        if (step === 'done') renderSummary();
        if (step === 'connect') renderPower();
        $$('[data-writing-step]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.writingStep === step)));
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
        $('[data-action="save-goals"]').disabled = state.busy || !count;
        // Your setup: the steps these picks run, in order (website first), and about how long they take.
        const plan = $('[data-goal-plan]');
        if (!plan) return;
        if (!count) { plan.textContent = __('Pick what you need to see your setup.', 'gpt3-ai-content-generator'); return; }
        const website = state.goals.includes('website');
        const tool = state.goals.find(goal => goal !== 'website');
        const steps = [website ? __('Account', 'gpt3-ai-content-generator') : __('Connect AI', 'gpt3-ai-content-generator')];
        if (website) steps.push(__('Website', 'gpt3-ai-content-generator'));
        if (tool) steps.push($(`[data-goal="${tool}"]`)?.dataset.moduleName || '');
        steps.push(__('Done', 'gpt3-ai-content-generator'));
        const parts = [__('Your setup:', 'gpt3-ai-content-generator')];
        steps.forEach((label, index) => {
            if (index) parts.push('→');
            const name = document.createElement('strong'); name.textContent = label; parts.push(name);
        });
        parts.push(sprintf(__('· about %s minutes', 'gpt3-ai-content-generator'), 1 + Number(website) + Number(Boolean(tool))));
        plan.replaceChildren(...parts);
    }

    const websiteGoal = () => state.goals.includes('website');
    const deferredWebsite = () => websiteGoal() && (root.dataset.websiteAddressBlocked === 'true' || root.dataset.websiteConnectionBlocked === 'true');
    const otherGoal = () => state.goals.find(goal => goal !== 'website');
    // Website first: a website pick runs its connect steps before any other tool. With other tools picked,
    // Do this later or a site that can't be reached moves on to them; website-only keeps its own blocked screen.
    const websiteFlow = () => websiteGoal() && !state.aiChoice && (!otherGoal() || (!state.websiteLater && !deferredWebsite()));
    const moduleName = (goal) => $(`[data-goal="${goal}"]`)?.dataset.moduleName || '';

    /** Setup progress: Choose, Account, Website (when picked), the first tool (when picked), Done. */
    function renderProgress() {
        const tool = otherGoal();
        const accountDone = root.dataset.cloud === 'connected' && (websiteGoal() ? root.dataset.websiteReady : root.dataset.cloudReady) === 'true';
        const shown = { goals: true, account: true, website: websiteGoal(), try: Boolean(tool), done: true };
        let current = { goals: 'goals', done: 'done' }[state.step] || (state.step.startsWith('try-') ? 'try' : '');
        if (state.step === 'connect') current = state.aiChoice ? 'try' : (websiteFlow() && root.dataset.websiteReady === 'true') || (websiteGoal() && connectedAssistant()) ? 'website' : 'account';
        const list = root.querySelector('.aipkit-setup__progress');
        list.hidden = !current;
        const items = $$('.aipkit-setup__progress li').filter(item => { item.hidden = !shown[item.dataset.for]; return !item.hidden; });
        const at = items.findIndex(item => item.dataset.for === current);
        items.forEach((item, index) => {
            // A step that was passed but not finished (website set up later) keeps its number instead of a tick.
            const passed = at >= 0 && index < at;
            const done = passed && (item.dataset.for !== 'website' || Boolean(connectedAssistant())) && (item.dataset.for !== 'account' || accountDone || state.power !== 'cloud');
            item.classList.toggle('is-current', index === at);
            item.classList.toggle('is-done', done);
            item.querySelector('.aipkit-setup__dot').textContent = done ? '✓' : String(index + 1);
        });
        const label = $('.aipkit-setup__progress [data-for="try"] [data-progress-label]');
        if (label && tool) label.textContent = moduleName(tool);
        const account = $('.aipkit-setup__progress [data-for="account"] [data-progress-label]');
        if (account) account.textContent = websiteGoal() ? __('Account', 'gpt3-ai-content-generator') : __('Connect', 'gpt3-ai-content-generator');
    }
    const connectedAssistant = () => {
        const awaiting = state.assistantAwaiting || (state.assistant && state.assistants.find(item => item.state === 'pending' && item.client_hint === state.assistant)?.grant_id);
        return state.assistants.find(item => item.state === 'active' && (!state.assistant || item.client_hint === state.assistant) && (!awaiting || item.grant_id === awaiting))
            || state.assistants.find(item => item.state === 'active' && state.linkGrants.includes(item.grant_id));
    };

    // ChatGPT and Claude get one button that copies the prompt and opens them; other apps copy only.
    const assistantHomes = { chatgpt: ['ChatGPT', 'https://chatgpt.com/'], claude: ['Claude', 'https://claude.ai/new'] };
    function renderWebsitePrompt() {
        const home = assistantHomes[connectedAssistant()?.client_hint];
        const main = $('[data-action="copy-open-assistant"]');
        main.dataset.url = home ? home[1] : '';
        main.textContent = home ? sprintf(__('Copy and open %s', 'gpt3-ai-content-generator'), home[0]) : __('Copy prompt', 'gpt3-ai-content-generator');
        renderWebsiteVerification();
    }
    const readAssistantName = () => assistantHomes[connectedAssistant()?.client_hint]?.[0] || __('your assistant', 'gpt3-ai-content-generator');

    const websiteVerificationVisible = () => state.step === 'connect' && websiteFlow() && root.dataset.websiteReady === 'true' && state.connectionCheck === 'passed' && !deferredWebsite() && !!connectedAssistant();

    function pauseWebsiteVerification() {
        verification.generation++;
        clearTimeout(verification.timer); clearTimeout(verification.requestTimer);
        verification.timer = verification.requestTimer = null;
        verification.controller?.abort(); verification.controller = null;
    }
    /** Step 3: prompt, one button and one status line; once the read is confirmed it folds to one line. */
    function renderWebsiteVerification(message = '') {
        const name = readAssistantName();
        const verified = verification.status === 'verified';
        const messages = {
            waiting: sprintf(__('Waiting for %s to read your site. This updates by itself.', 'gpt3-ai-content-generator'), name),
            timeout: sprintf(__('No read confirmed yet. Make sure AI Puffer is selected in %s, then send the prompt.', 'gpt3-ai-content-generator'), name),
            error: __('The website read could not be checked. Try again, and make sure AI Puffer is selected in your assistant.', 'gpt3-ai-content-generator'),
        };
        const step = $('[data-website-first-request]');
        step.dataset.status = verification.status;
        $('[data-read-num]').textContent = verified ? '✓' : '3';
        $('[data-read-title]').textContent = verified ? __('Website read confirmed', 'gpt3-ai-content-generator') : __('Try it now', 'gpt3-ai-content-generator');
        $('[data-website-hint]').textContent = verified
            ? sprintf(__('%s can use your site. Everything it does shows up in Website activity.', 'gpt3-ai-content-generator'), name.charAt(0).toUpperCase() + name.slice(1))
            : sprintf(__('In %s, select AI Puffer and send this. It only reads your site.', 'gpt3-ai-content-generator'), name);
        $$('[data-read-part]').forEach(part => { part.hidden = verified; });
        $('[data-website-verification]').textContent = verified ? '' : (message || messages[verification.status] || '');
        $('[data-website-verification]').dataset.status = verification.status;
        const retry = $('[data-action="retry-website-verification"]');
        retry.hidden = !['timeout', 'error'].includes(verification.status);
        retry.textContent = verification.status === 'timeout' ? __('Check again', 'gpt3-ai-content-generator') : __('Try again', 'gpt3-ai-content-generator');
    }
    async function checkWebsiteRead(mode = 'check') {
        if (pageGone || !websiteVerificationVisible() || document.visibilityState === 'hidden') return;
        if (mode === 'check' && ['verified', 'timeout', 'error'].includes(verification.status)) return;
        const grant = connectedAssistant()?.grant_id;
        pauseWebsiteVerification();
        if (!grant) { verification.status = 'error'; renderWebsiteVerification(); return; }
        if (verification.grant !== grant) { verification.attempt = ''; verification.until = 0; mode = 'start'; }
        if (mode === 'retry') { verification.attempt = ''; verification.until = 0; }
        if (verification.until && Date.now() >= verification.until) { verification.status = 'timeout'; renderWebsiteVerification(); return; }
        verification.grant = grant;
        verification.status = 'waiting'; renderWebsiteVerification();
        const generation = verification.generation;
        const controller = new AbortController(); verification.controller = controller;
        verification.requestTimer = setTimeout(() => controller.abort(), 8000);
        try {
            const data = await post('aipkit_onboarding', { _wpnonce: config.nonce, op: 'website_verification', mode, grant_id: grant, attempt_id: verification.attempt }, false, controller.signal);
            if (generation !== verification.generation || pageGone || !websiteVerificationVisible()) return;
            if (!['waiting', 'verified', 'timeout'].includes(data.status) || typeof data.attempt_id !== 'string' || !Number.isInteger(data.expires_at)) throw new Error();
            verification.attempt = data.attempt_id; verification.until = data.expires_at * 1000;
            verification.status = data.status;
            if (data.status === 'waiting' && Date.now() >= verification.until) verification.status = 'timeout';
            renderWebsiteVerification();
        } catch (error) {
            if (generation !== verification.generation) return;
            // A transport failure must not keep an unbounded watcher running.
            verification.status = 'error'; renderWebsiteVerification(error.message || '');
        } finally {
            if (generation === verification.generation) {
                clearTimeout(verification.requestTimer); verification.requestTimer = null; verification.controller = null;
                if (verification.status === 'waiting' && !pageGone && websiteVerificationVisible() && document.visibilityState !== 'hidden') {
                    verification.timer = setTimeout(() => { verification.timer = null; void checkWebsiteRead('check'); }, Math.min(5000, Math.max(0, verification.until - Date.now())));
                }
            }
        }
    }

    /**
     * The website step's heading follows where the person is: link an assistant, finish in its tab (a pill shows
     * that this page is checking), other ways to finish, or connected. Other screens keep the AI connection heading.
     */
    function renderConnectHead(website, ready, active, view) {
        const title = $('[data-connect-title]');
        const sub = $('[data-connect-sub]');
        const pill = $('[data-connect-pill]');
        const name = assistantNames[active?.client_hint || state.assistant] || '';
        const linking = ready && Boolean(name) && (view === 'waiting' || view === 'notyet') && !active;
        let head = [__('How should AI Puffer connect to AI?', 'gpt3-ai-content-generator'), sub.dataset.default || ''];
        if (website && active) {
            head = [name ? sprintf(__('%s is connected', 'gpt3-ai-content-generator'), name) : __('Your assistant is connected', 'gpt3-ai-content-generator'),
                sprintf(__('It can now read and update %s. Everything it does shows up in Website activity.', 'gpt3-ai-content-generator'), root.dataset.siteHost || __('your site', 'gpt3-ai-content-generator'))];
        } else if (linking && state.assistantSlow) {
            head = [__('Still setting up? Here are the steps', 'gpt3-ai-content-generator'), __('Adding a connector can take a few minutes. Any of these can help.', 'gpt3-ai-content-generator')];
        } else if (linking) {
            head = [sprintf(state.assistantOpened ? __('Finish in the %s tab', 'gpt3-ai-content-generator') : __('Set up %s', 'gpt3-ai-content-generator'), name),
                sprintf(__('Three short steps there. This page shows when %s is connected.', 'gpt3-ai-content-generator'), name)];
        } else if (website && ready) {
            head = [__('Let ChatGPT or Claude manage your site', 'gpt3-ai-content-generator'), __('Pick the assistant you already use. It takes about a minute, and every change it makes shows up in Website activity.', 'gpt3-ai-content-generator')];
        } else if (website) {
            head = [__('Connect your website', 'gpt3-ai-content-generator'), __('Verify your email, then connect your assistant.', 'gpt3-ai-content-generator')];
        }
        title.textContent = head[0];
        sub.textContent = head[1];
        pill.textContent = linking ? $('[data-assistant-chip]').textContent : '';
        pill.hidden = !pill.textContent;
        $('[data-connect-check]').hidden = !(website && active);
    }

    function renderPower() {
        const website = websiteFlow();
        root.dataset.website = String(website);
        if (website) state.power = 'cloud';
        $$('[data-power-option="key"], [data-power-option="later"], [data-updates-optin]').forEach(el => { el.hidden = website; });
        const connectionBlocked = deferredWebsite();
        const ready = root.dataset.cloud === 'connected' && root.dataset.websiteReady === 'true';
        $('[data-onboarding-connection-check]').hidden = !website || !ready || state.connectionCheck === 'checking';
        if (!ready) $('[data-connection-progress]').hidden = true;
        $('[data-ai-options]').hidden = website && ready;
        $('[data-assistant-setup]').hidden = !website || !ready || connectionBlocked;
        // When the website can't connect, Continue can't work: Do this later becomes the main button instead.
        const later = $('[data-action="website-later"]');
        const laterFirst = website && connectionBlocked;
        const view = renderAssistant();
        const active = connectedAssistant();
        const linked = Boolean(active) && websiteVerificationVisible();
        renderConnectHead(website, ready && !connectionBlocked, linked ? active : null, view);
        // Once an assistant is connected, Continue replaces Do this later; the first request stays visible, never required.
        later.hidden = !website || linked;
        later.className = laterFirst ? 'aipkit-setup__btn' : 'aipkit-setup__link';
        root.dataset.power = state.power;
        $$('[data-power-option]').forEach((option) => option.classList.toggle('is-on', option.dataset.powerOption === state.power));
        const next = $('[data-action="after-connect"]');
        const tool = otherGoal();
        next.textContent = state.power === 'later' ? __('Continue without AI', 'gpt3-ai-content-generator')
            : website && tool && !state.websiteReturn ? sprintf(__('Continue to %s', 'gpt3-ai-content-generator'), moduleName(tool)) : __('Continue', 'gpt3-ai-content-generator');
        // Website setup shows Continue only once an assistant is connected; never as a disabled button.
        next.hidden = laterFirst || (website && !linked);
        const aiChoice = state.aiChoice && Boolean(tool);
        $('[data-ai-credit-title]').hidden = !aiChoice;
        $('[data-ai-credit-balance]').hidden = !aiChoice;
        ['[data-cloud-offer-label]', '[data-cloud-offer]', '[data-cloud-offer-description]'].forEach(selector => { $(selector).hidden = aiChoice; });
        $('[data-ai-credit-balance]').textContent = typeof state.availableCredits === 'string'
            ? sprintf(__('%s AI credits available', 'gpt3-ai-content-generator'), state.availableCredits)
            : __('Balance unavailable', 'gpt3-ai-content-generator');
        const editingEmail = !$('[data-cloud-email-editor]').hidden;
        next.disabled = website ? state.busy || state.connectionCheck !== 'passed' || connectionBlocked || !ready || !active : state.busy || (state.power === 'cloud' && editingEmail) || (state.power !== 'later' && !(state.power === 'cloud' ? root.dataset.cloud === 'connected' && root.dataset.cloudReady === 'true' : state.keyReady));
        $('[data-action="cloud"]').textContent = cloudButtonLabel();
        $('[data-action="cloud"]').disabled = state.busy || editingEmail || (!website && !field('consent').checked);
        $('[data-action="check-email"]').disabled = state.busy || editingEmail || (!field('consent').checked && root.dataset.cloud !== 'connected');
        $('[data-action="retry-cloud"]').disabled = state.busy || editingEmail;
        // The inbox shows each link twice (card and website row); only one set is visible.
        ['inbox-confirm', 'inbox-resend', 'inbox-change'].forEach(action => { $$(`[data-action="${action}"]`).forEach(button => { button.disabled = state.busy; }); });
        $('[data-action="disconnect-cloud"]').disabled = state.busy || editingEmail;
        field('api_key').disabled = state.busy || keyRequest !== null;
        field('api_key').setAttribute('aria-busy', String(keyRequest !== null));
        $('[data-action="key"]').disabled = state.busy || keyRequest !== null;
        // Account and assistant setup; the inline read check appears after connection.
        $('[data-flow-step="connect"]').hidden = !website;
        $('[data-flow-email-content]').hidden = website && ready;
        $('[data-flow-email-summary]').hidden = !website || !ready;
        $('[data-flow-email-summary]').textContent = $('[data-cloud-email]').textContent;
        $('[data-email-num]').textContent = ready ? '✓' : '1';
        $('[data-flow-step="email"]').dataset.state = ready ? 'complete' : 'current';
        $('[data-flow-email-title]').textContent = ready ? __('Account ready', 'gpt3-ai-content-generator')
            : root.dataset.cloudPending === 'true' ? __('Confirm your email', 'gpt3-ai-content-generator') : __('Create your free account', 'gpt3-ai-content-generator');
        $('[data-flow-connect-hint]').hidden = !website || (ready && state.connectionCheck !== 'passed');
        $('[data-flow-connect-hint]').textContent = ready ? __('Your website is ready.', 'gpt3-ai-content-generator') : __('Finish email verification to connect your assistant.', 'gpt3-ai-content-generator');
        $('[data-flow-step="connect"]').dataset.state = ready ? (state.connectionCheck === 'passed' ? 'complete' : 'current') : 'locked';
        ['chatgpt', 'claude', 'other'].forEach(client => {
            $(`[data-assistant="${client}"]`).disabled = state.busy || state.connectionCheck !== 'passed' || !ready || connectionBlocked;
        });
        const showFirstRequest = websiteVerificationVisible();
        $('[data-website-first-request]').hidden = !showFirstRequest;
        // Step 2 folds to one line ("ChatGPT connected") once the assistant is connected, like "Account ready".
        const connectStep = $('[data-flow-step="connect"]');
        connectStep.dataset.connected = String(showFirstRequest);
        $('[data-connect-num]').textContent = showFirstRequest ? '✓' : '2';
        $('[data-flow-connect-title]').textContent = showFirstRequest
            ? (assistantNames[active.client_hint] ? sprintf(__('%s connected', 'gpt3-ai-content-generator'), assistantNames[active.client_hint]) : __('Assistant connected', 'gpt3-ai-content-generator'))
            : __('Connect an assistant', 'gpt3-ai-content-generator');
        if (showFirstRequest) {
            if (verification.grant !== active.grant_id || verification.status === 'idle') {
                renderWebsitePrompt();
                void checkWebsiteRead('start');
            } else if (verification.status === 'waiting' && !verification.controller && !verification.timer) {
                void checkWebsiteRead(verification.attempt ? 'check' : 'start');
            }
        } else if (verification.controller || verification.timer) {
            pauseWebsiteVerification();
        }
        // Check once after verification. Returning focus never repeats this request.
        if (state.step === 'connect' && website && ready && !state.busy && state.connectionCheck === 'idle') {
            void checkConnection().catch(error => showError(error.message));
        }
        renderProgress();
    }

    // Reuse the AI options as a separate screen, without marking a connected website as deferred.
    async function showAIChoice() {
        state.aiChoice = true;
        state.power = state.aiPower;
        pauseWebsiteVerification();
        watchAssistantLink(null);
        window.history?.replaceState(null, '', '#ai');
        go('connect');
        if (state.power === 'key') await loadProviderKey();
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

    function updateAllowanceOffer(offer) {
        if (!offer || typeof offer.label !== 'string' || typeof offer.amounts !== 'string') return;
        $('[data-cloud-offer-label]').textContent = offer.label;
        $('[data-cloud-offer]').textContent = offer.amounts;
        [['chatbot', offer.credits], ['website', offer.actions]].forEach(([goal, text]) => {
            const badge = $(`[data-goal-offer="${goal}"]`);
            if (badge && typeof text === 'string') badge.textContent = text;
        });
    }

    /** The Cloud button: Resend while an email waits, Continue in the website steps, Connect for free elsewhere. */
    function cloudButtonLabel() {
        if (root.dataset.cloudPending === 'true') return __('Resend email', 'gpt3-ai-content-generator');
        return websiteFlow() ? __('Continue', 'gpt3-ai-content-generator') : __('Connect for free', 'gpt3-ai-content-generator');
    }

    /** A short line inside the inbox card: not confirmed yet, sent again, or what went wrong. */
    function inboxNote(message, tone = 'warn') {
        const note = $('[data-inbox-note]');
        note.textContent = message;
        note.hidden = !message;
        note.dataset.tone = tone;
    }

    function updateCloud(data) {
        const accountChanged = $('[data-cloud-email]').textContent !== (data.email || '');
        if (accountChanged || (root.dataset.websiteReady === 'true') !== (data.websiteReady === true) || (root.dataset.cloud === 'connected') !== (data.connected === true)) state.connectionCheck = 'idle';
        updateAllowanceOffer(data.allowanceOffer);
        state.availableCredits = typeof data.availableCredits === 'string' ? data.availableCredits : null;
        cloudConnected = data.connected === true;
        root.dataset.cloud = data.connected ? 'connected' : 'none';
        $('[data-action="disconnect-cloud"]').hidden = !data.connected;
        root.dataset.cloudReady = String(data.ready === true);
        root.dataset.cloudPending = String(data.pendingEmail === true);
        if (!data.connected || data.websiteReady !== true) state.connectionCheck = 'idle';
        root.dataset.websiteReady = String(data.websiteReady === true);
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
        $('[data-action="cloud"]').textContent = cloudButtonLabel();
        $('[data-action="cloud"]').classList.toggle('aipkit-setup__btn--ghost', !!data.pendingEmail);
        root.dataset.resendEmailUrl = data.resendEmailUrl || '';
        $('[data-cloud-message]').textContent = data.message || '';
        $('[data-cloud-message]').hidden = !data.message || data.pendingEmail === true;
        // Check your inbox: the address, whether a resend exists for this account, and a fresh start once confirmed.
        $$('[data-inbox-email]').forEach(email => { email.textContent = data.email || ''; });
        const canResend = !(data.connected === true && !data.resendEmailUrl);
        $$('[data-action="inbox-resend"]').forEach(button => { button.hidden = !canResend; });
        // The website row's fine print is built around Resend, so it goes when Resend does.
        $$('.aipkit-setup__inbox-short.aipkit-setup__inbox-fine').forEach(line => { line.hidden = !canResend; });
        if (!data.pendingEmail) { inboxNote(''); root.dataset.inboxEditing = 'false'; }
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
        onEdit: editing => {
            if (!editing) root.dataset.inboxEditing = 'false';
            renderPower();
        },
        refresh: async () => {
            root.dataset.cloudReady = 'false';
            const data = await setup('cloud_state', { verify_email: 'yes' });
            updateCloud(data);
            publishCloudConnectionChange();
            return data;
        },
    });

    async function connectCloud(checkEmail = false, selectAI = false) {
        // Continue carries the displayed agreement only on an explicit click. Return checks reuse saved consent.
        const consent = field('consent').checked || (websiteFlow() && (!checkEmail || config.cloudConsent === true));
        const data = await setup('cloud', {
            email: field('cloud_email').value, check_email: checkEmail ? 'yes' : '',
            consent: consent ? 'yes' : 'no', website_only: websiteFlow() && !selectAI ? 'yes' : '',
            marketing: !websiteFlow() && field('marketing').checked ? 'yes' : '',
        });
        updateCloud(data);
        if (!checkEmail) root.dataset.inboxEditing = 'false';
        if (data.pendingEmail && consent) config.cloudConsent = true;
        return data;
    }

    async function checkEmailConfirmation() {
        inboxNote(__('Checking email confirmation…', 'gpt3-ai-content-generator'));
        try {
            await connectCloud(true);
            if (root.dataset.cloudPending === 'true') inboxNote(__('Open the link in your email. If you already did, wait a few seconds and try again.', 'gpt3-ai-content-generator'));
        } catch (error) {
            inboxNote(error.message, 'bad');
        }
    }

    /** The validated hosted-bridge connection page, or an error. */
    function handoff(url) {
        if (!url) return '';
        const parsed = new URL(url);
        if (parsed.protocol !== 'https:' || parsed.hostname !== 'mcp.aipuffer.org' || parsed.port || parsed.username || parsed.password || parsed.pathname !== '/connect' || parsed.hash) throw new Error(__('Invalid connection link. Try connecting again.', 'gpt3-ai-content-generator'));
        parsed.searchParams.set('launch', '1');
        parsed.searchParams.set('surface', 'onboarding');
        $$('[data-assistant-link]').forEach(link => { link.href = parsed.href; });
        return parsed.href;
    }

    const assistantNames = { chatgpt: 'ChatGPT', claude: 'Claude' };
    const pendingFor = (client) => state.assistants.find(item => item.state === 'pending' && item.client_hint === client);

    /**
     * Assistant rows, or one panel for the chosen assistant. States: waiting for Allow, back but not
     * allowed yet, connected, connect failed, and link expired (a pending connection drops out after 10 minutes).
     */
    function renderAssistant() {
        if (!state.assistant && !state.assistantChoosing) {
            const resumed = state.assistants.find(item => item.state === 'pending' && item.connect_url);
            if (resumed) state.assistant = resumed.client_hint;
        }
        const active = connectedAssistant();
        const pending = state.assistant ? pendingFor(state.assistant) : null;
        if (pending) state.assistantAwaiting = pending.grant_id;
        let view = 'form';
        if (active) view = 'connected';
        else if (state.assistantChoosing || !state.assistant) view = 'form';
        else if (state.assistantFailed) view = 'failed';
        else if (pending?.connect_url) view = state.assistantNotYet ? 'notyet' : 'waiting';
        else if (state.assistantAwaiting) view = 'expired';
        const name = assistantNames[active?.client_hint || state.assistant] || __('your assistant', 'gpt3-ai-content-generator');
        const panel = $('[data-assistant-panel]');
        const known = Boolean(assistantNames[state.assistant]);
        const linking = (view === 'waiting' || view === 'notyet') && known;
        watchAssistantLink(view === 'waiting' || view === 'notyet' ? pending : null);
        $('[data-onboarding-assistant]').hidden = view !== 'form';
        panel.hidden = view === 'form';
        panel.dataset.state = view;
        panel.dataset.client = active?.client_hint || state.assistant || '';
        panel.dataset.slow = String(linking && state.assistantSlow === true);
        // While ChatGPT or Claude is linking, the page heading carries the title and the pill; the panel holds the steps.
        panel.dataset.linking = String(linking);
        // While setting up, the panel is the guide: its title names the app, a small chip carries the state.
        const guideTitle = state.assistant === 'other' ? __('Set up another MCP app', 'gpt3-ai-content-generator')
            : state.assistantSlow ? __('Still setting up? Here are the steps', 'gpt3-ai-content-generator')
                : state.assistantOpened ? sprintf(__('Finish in the %s tab', 'gpt3-ai-content-generator'), name) : sprintf(__('Set up %s', 'gpt3-ai-content-generator'), name);
        const copy = {
            waiting: [guideTitle, known && !state.assistantSlow ? sprintf(__('Three short steps there. This page shows when %s is connected.', 'gpt3-ai-content-generator'), name) : ''],
            notyet: [guideTitle, sprintf(__('Choose Allow on the connection page in %s. Return here and select Check.', 'gpt3-ai-content-generator'), name)],
            connected: [sprintf(__('%s is connected', 'gpt3-ai-content-generator'), name), sprintf(__('You can now manage this site from %s.', 'gpt3-ai-content-generator'), name)],
            failed: [sprintf(__('%s couldn’t be connected', 'gpt3-ai-content-generator'), name), state.assistantFailed],
            expired: [__('This connection link expired', 'gpt3-ai-content-generator'), __('Links last 10 minutes. Start a new one to continue.', 'gpt3-ai-content-generator')],
            form: ['', ''],
        }[view];
        $('[data-assistant-title]').textContent = copy[0].charAt(0).toUpperCase() + copy[0].slice(1);
        $('[data-assistant-text]').textContent = copy[1];
        $('[data-assistant-text]').hidden = !copy[1];
        $('[data-assistant-icon]').textContent = view === 'connected' ? '✓' : (['waiting', 'notyet'].includes(view) ? '' : '!');
        const chip = $('[data-assistant-chip]');
        const waitingFor = !state.assistantSlow ? __('Waiting for %s…', 'gpt3-ai-content-generator')
            : linkWatch.stopped ? __('Still waiting for %s', 'gpt3-ai-content-generator') : __('Still waiting for %s · checking every few seconds', 'gpt3-ai-content-generator');
        chip.textContent = view === 'waiting' ? (known ? sprintf(waitingFor, name) : __('Waiting', 'gpt3-ai-content-generator'))
            : view === 'notyet' ? __('Not allowed yet', 'gpt3-ai-content-generator') : '';
        chip.hidden = !chip.textContent;
        root.querySelectorAll('[data-mcp-instructions]').forEach(item => { item.hidden = item.dataset.mcpInstructions !== (state.assistant || 'other'); });
        // ChatGPT and Claude get three short steps; another MCP app keeps the full manual setup.
        $('[data-mcp-setup]').hidden = !['waiting', 'notyet'].includes(view) || known;
        $$('[data-assistant-steps]').forEach(steps => { steps.hidden = !linking || state.assistantSlow === true || steps.dataset.assistantSteps !== state.assistant; });
        $$('[data-address-title]').forEach(line => { line.textContent = state.addressCopied ? line.dataset.copied : line.dataset.manual; });

        let link = '';
        try { link = view === 'waiting' || view === 'notyet' ? handoff(pending.connect_url) : ''; } catch (_) { link = ''; }
        $('[data-assistant-handoff]').hidden = !link;
        // Waiting controls: Open again, Use Claude instead and Check now; after a minute each gets a line (and the guide joins).
        const fix = (key, shown) => { const row = $(`[data-fix="${key}"]`); if (row) row.hidden = !shown; };
        fix('open', Boolean(link) && known);
        fix('guide', linking && state.assistantSlow === true);
        fix('switch', linking);
        $('[data-assistant-open]').textContent = sprintf(state.assistantOpened ? __('Open %s again', 'gpt3-ai-content-generator') : __('Open %s', 'gpt3-ai-content-generator'), name);
        $('[data-assistant-guide]').href = assistantGuides[state.assistant] || assistantGuides.other;
        const other = state.assistant === 'claude' ? 'chatgpt' : 'claude';
        $('[data-assistant-fix-other]').textContent = sprintf(__('Try %s instead', 'gpt3-ai-content-generator'), assistantNames[other]);
        const change = $('[data-action="assistant-switch"]');
        change.className = state.assistantSlow ? 'aipkit-setup__btn aipkit-setup__btn--ghost' : 'aipkit-setup__link';
        change.textContent = sprintf(state.assistantSlow ? __('Use %s', 'gpt3-ai-content-generator') : __('Use %s instead', 'gpt3-ai-content-generator'), assistantNames[other]);
        $('[data-action="check-assistant"]').textContent = view === 'notyet' || (linking && linkWatch.stopped) ? __('Check again', 'gpt3-ai-content-generator') : linking ? __('Check now', 'gpt3-ai-content-generator') : __('Check', 'gpt3-ai-content-generator');
        const again = $('[data-action="assistant-again"]');
        again.hidden = view !== 'failed' && view !== 'expired';
        again.textContent = view === 'expired' ? __('Start again', 'gpt3-ai-content-generator') : __('Try again', 'gpt3-ai-content-generator');
        // While ChatGPT or Claude is waiting, Use Claude instead covers switching; the full chooser stays for the rest.
        $('[data-action="assistant-choose"]').hidden = view === 'connected' || linking;
        return view;
    }

    const assistantGuides = {
        chatgpt: 'https://aipower.org/docs/start/connect-chatgpt/',
        claude: 'https://aipower.org/docs/start/connect-claude/',
        other: 'https://aipower.org/docs/start/connect-cursor-claude-code/',
    };

    /**
     * Setup only: while a link waits for Allow and this screen is visible, ask the bridge every few seconds whether
     * it was approved (one status read, nothing else). Hidden tabs pause and check again on return; it stops once
     * connected, skipped or left, and after five minutes Check again takes over. After a minute, other ways to finish show.
     */
    function watchAssistantLink(pending) {
        const grant = pending && state.step === 'connect' && !pageGone ? pending.grant_id : '';
        if (grant !== linkWatch.grant) {
            clearTimeout(linkWatch.timer); clearTimeout(linkWatch.slowTimer);
            linkWatch.controller?.abort(); linkWatch.controller = null; linkWatch.generation++;
            Object.assign(linkWatch, { grant, timer: null, slowTimer: null, since: Date.now(), stopped: false });
            state.assistantSlow = false;
            if (grant) linkWatch.slowTimer = setTimeout(() => { linkWatch.slowTimer = null; state.assistantSlow = true; renderPower(); }, 60000);
        }
        if (!grant || document.visibilityState === 'hidden') { clearTimeout(linkWatch.timer); linkWatch.timer = null; return; }
        if (!linkWatch.timer && !linkWatch.controller && !linkWatch.stopped) linkWatch.timer = setTimeout(() => { linkWatch.timer = null; void pollAssistantLink(grant); }, 4000);
    }

    async function pollAssistantLink(grant) {
        const generation = linkWatch.generation;
        const current = () => !pageGone && state.step === 'connect' && websiteFlow() && !state.assistantChoosing && linkWatch.grant === grant && linkWatch.generation === generation && document.visibilityState !== 'hidden';
        if (!current() || linkWatch.controller) return;
        clearTimeout(linkWatch.timer); linkWatch.timer = null;
        if (Date.now() - linkWatch.since >= 300000) { linkWatch.stopped = true; renderPower(); return; }
        if (state.busy) { renderPower(); return; }
        const controller = new AbortController(); linkWatch.controller = controller;
        const timeout = setTimeout(() => controller.abort(), 12000);
        const links = state.assistants.filter(item => item.state === 'pending' && (item.grant_id === grant || state.linkGrants.includes(item.grant_id)));
        const checked = links.length ? links[(linkWatch.turn = (linkWatch.turn || 0) + 1) % links.length].grant_id : grant;
        try {
            const data = await post('aipkit_onboarding', { _wpnonce: config.nonce, op: 'assistant_status', grant_id: checked }, false, controller.signal);
            if (current() && Array.isArray(data.assistants)) state.assistants = data.assistants;
        } catch (_) { /* Check now stays available; one later tick can retry. */ }
        finally {
            clearTimeout(timeout);
            if (linkWatch.controller === controller) linkWatch.controller = null;
            if (current()) renderPower();
        }
    }

    async function refreshWebsiteAllowance() {
        try {
            const data = await post('aipkit_mcp_settings', { _wpnonce: config.mcpNonce, surface: 'onboarding', mcp_action: 'usage' });
            if (!pageGone) updateAllowanceOffer(data.allowanceOffer);
        } catch (_) { /* An allowance refresh must never prevent finishing setup. */ }
    }

    async function assistantRequest(action, fields = {}, accept = null) {
        const data = await post('aipkit_mcp_settings', { _wpnonce: config.mcpNonce, surface: 'onboarding', mcp_action: action, ...fields });
        if (accept && !accept()) return data;
        updateAllowanceOffer(data.allowanceOffer);
        state.assistants = Array.isArray(data.assistants) ? data.assistants : [];
        const pending = state.assistants.find(item => item.state === 'pending' && (!state.assistant || item.client_hint === state.assistant));
        handoff(data.pair?.connect_url || pending?.connect_url);
        renderPower();
        if (action !== 'view') publishCloudConnectionChange();
        // Waiting is not an error: a Check that finds no Allow yet turns the panel amber instead of a banner.
        if (action === 'recheck') state.assistantNotYet = !connectedAssistant() && Boolean(pendingFor(state.assistant));
        renderPower();
        return data;
    }

    function renderCheckProgress(rows) {
        const list = $('[data-connection-progress-rows]');
        list.replaceChildren(...rows.map(row => {
            const item = document.createElement('li');
            item.dataset.status = row.status;
            const icon = document.createElement('span');
            icon.className = 'aipkit-setup__checks-icon';
            icon.setAttribute('aria-hidden', 'true');
            icon.textContent = row.status === 'passed' ? '✓' : row.status === 'checking' ? '' : '!';
            const name = document.createElement('span'); name.textContent = row.name;
            item.append(icon, name);
            return item;
        }));
    }

    function checkConnection() {
        if (checkRequest) return checkRequest;
        state.connectionCheck = 'checking';
        checkRequest = runConnectionCheck().finally(() => { checkRequest = null; renderPower(); });
        renderPower();
        return checkRequest;
    }

    async function runConnectionCheck() {
        const context = () => [root.dataset.cloud, root.dataset.websiteReady, $('[data-cloud-email]').textContent].join('\n');
        const progress = $('[data-connection-progress]');
        const saved = $('[data-onboarding-connection-check]');
        progress.hidden = false;
        progress.setAttribute('aria-busy', 'true');
        $('[data-connection-progress-title]').textContent = __('Checking your website…', 'gpt3-ai-content-generator');
        saved.hidden = true;
        saved.innerHTML = '';
        renderCheckProgress([{ name: __('Checking your website…', 'gpt3-ai-content-generator'), status: 'checking' }]);
        try {
            const checkedContext = context();
            const data = await post('aipkit_mcp_settings', { _wpnonce: config.mcpNonce, surface: 'onboarding', mcp_action: 'diagnostics' });
            if (typeof data.websiteBlocked !== 'boolean' || typeof data.addressBlocked !== 'boolean' || typeof data.diagnosticHtml !== 'string' || typeof data.ready !== 'boolean' || !Array.isArray(data.rows)) throw new Error(__('The connection check could not finish. Try again.', 'gpt3-ai-content-generator'));
            if (context() !== checkedContext) throw new Error(__('The Cloud connection changed. Check again.', 'gpt3-ai-content-generator'));
            root.dataset.websiteAddressBlocked = String(data.addressBlocked);
            root.dataset.websiteConnectionBlocked = String(data.websiteBlocked || !data.ready);
            state.connectionCheck = data.ready ? 'passed' : 'failed';
            saved.innerHTML = data.diagnosticHtml;
            progress.hidden = true;
            renderPower();
            return data;
        } catch (error) {
            state.connectionCheck = 'failed';
            root.dataset.websiteConnectionBlocked = 'true';
            $('[data-connection-progress-title]').textContent = __('The connection check could not finish. Try again.', 'gpt3-ai-content-generator');
            renderCheckProgress([{ name: __('Connection check', 'gpt3-ai-content-generator'), status: 'failed' }]);
            throw error;
        } finally {
            progress.setAttribute('aria-busy', 'false');
            saved.hidden = !websiteFlow();
        }
    }

    async function checkAssistant() {
        const item = state.assistants.find(entry => ['pending', 'active', 'paused'].includes(entry.state) && (!state.assistant || entry.client_hint === state.assistant));
        return assistantRequest(item ? 'recheck' : 'view', item ? { grant_id: item.grant_id } : {});
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
        const tools = state.goals.map((goal) => $(`[data-goal="${goal}"]`).dataset.moduleName);
        const unique = [...new Set(tools)];
        const active = connectedAssistant() || state.assistants.find(item => item.state === 'active');
        const others = unique.filter((name) => name !== $('[data-goal="website"]').dataset.moduleName);
        const joined = others.length > 1 ? sprintf(__('%1$s and %2$s', 'gpt3-ai-content-generator'), others.slice(0, -1).join(', '), others.at(-1)) : (others[0] || '');
        const websiteOnly = websiteGoal() && !otherGoal();
        const aiReady = state.power !== 'later' && Boolean(state.connection);
        let lead;
        if (websiteOnly) {
            lead = active
                ? sprintf(__('%s can now manage your site. Everything it does shows up in Website activity.', 'gpt3-ai-content-generator'), { chatgpt: 'ChatGPT', claude: 'Claude' }[active.client_hint] || __('Your assistant', 'gpt3-ai-content-generator'))
                : __('Still to do: connect ChatGPT or Claude to manage your website.', 'gpt3-ai-content-generator');
        } else {
            lead = aiReady
                ? sprintf(others.length > 1 ? __('%s are ready.', 'gpt3-ai-content-generator') : __('%s is ready.', 'gpt3-ai-content-generator'), joined)
                : __('Connect AI to finish setting up your tools.', 'gpt3-ai-content-generator');
            if (websiteGoal() && active && !aiReady) lead = sprintf(__('%s is connected. Connect AI to finish setting up your tools.', 'gpt3-ai-content-generator'), assistantHomes[active.client_hint]?.[0] || __('Your assistant', 'gpt3-ai-content-generator'));
            if (websiteGoal() && active && aiReady) lead = sprintf(others.length > 1
                ? __('%1$s is connected and %2$s are ready.', 'gpt3-ai-content-generator')
                : __('%1$s is connected and %2$s is ready.', 'gpt3-ai-content-generator'), assistantHomes[active.client_hint]?.[0] || __('Your assistant', 'gpt3-ai-content-generator'), joined);
            if (websiteGoal() && !active) lead += ' ' + __('Still to do: connect ChatGPT or Claude to manage your website.', 'gpt3-ai-content-generator');
        }
        $('[data-summary-lead]').textContent = lead;
        // The setup checklist: one row per step with its state in a few words and its own action.
        const doneRow = (row, ready, status) => {
            row.dataset.ready = String(ready);
            row.querySelector('[data-done-status]').textContent = status;
            const open = row.querySelector('[data-done-open]');
            if (open) open.hidden = !ready;
            row.querySelector('[data-done-connect]').hidden = ready;
        };
        // The free account comes first whenever Cloud runs the setup; its allowance is the status.
        const accountRow = $('[data-done-row="account"]');
        const accountReady = root.dataset.cloud === 'connected' && (websiteGoal() ? root.dataset.websiteReady : root.dataset.cloudReady) === 'true';
        accountRow.hidden = !(websiteGoal() || state.power === 'cloud');
        accountRow.querySelector('[data-done-title]').textContent = accountReady ? __('Account ready', 'gpt3-ai-content-generator') : __('Finish your free account', 'gpt3-ai-content-generator');
        doneRow(accountRow, accountReady, accountReady ? sprintf(__('%s every month', 'gpt3-ai-content-generator'), $('[data-cloud-offer]').textContent) : __('Confirm your email to start', 'gpt3-ai-content-generator'));
        const home = assistantHomes[active?.client_hint];
        const assistant = home ? home[0] : __('your assistant', 'gpt3-ai-content-generator');
        const websiteRow = $('[data-done-row="website"]');
        websiteRow.hidden = !websiteGoal();
        websiteRow.querySelector('[data-done-title]').textContent = active ? $('[data-goal="website"]').dataset.moduleName : __('Connect ChatGPT or Claude', 'gpt3-ai-content-generator');
        doneRow(websiteRow, Boolean(active), !active ? sprintf(__('Lets them manage %s for you · about 1 minute', 'gpt3-ai-content-generator'), root.dataset.siteHost || __('your site', 'gpt3-ai-content-generator'))
            : verification.status === 'verified' ? sprintf(__('Connected to %s', 'gpt3-ai-content-generator'), assistant)
                : sprintf(__('Connected to %s · website read not confirmed', 'gpt3-ai-content-generator'), assistant));
        // Each tool says what setup actually did with it: live, tested, created, or just ready.
        const toolStatus = {
            chatbot: () => state.saved.has('chatbot') ? __('Live on your site', 'gpt3-ai-content-generator')
                : state.results.has('chatbot') ? __('Tested · not on your site yet', 'gpt3-ai-content-generator') : __('Ready to try', 'gpt3-ai-content-generator'),
            forms: () => state.saved.has('forms') ? __('Form created', 'gpt3-ai-content-generator') : __('Ready', 'gpt3-ai-content-generator'),
            write: () => state.saved.has('write') ? __('Draft saved to Posts', 'gpt3-ai-content-generator')
                : state.saved.has('auto') ? __('Automation created', 'gpt3-ai-content-generator')
                    : state.saved.has('products') ? __('Product description saved', 'gpt3-ai-content-generator') : __('Ready', 'gpt3-ai-content-generator'),
        };
        ['chatbot', 'forms', 'write'].forEach((goal) => {
            const row = $(`[data-done-row="${goal}"]`);
            row.hidden = !state.goals.includes(goal);
            doneRow(row, aiReady, aiReady ? toolStatus[goal]()
                : goal === 'chatbot' ? __('Needs an AI connection to answer', 'gpt3-ai-content-generator') : __('Needs an AI connection', 'gpt3-ai-content-generator'));
        });
        // Every step done: "You're all set". Otherwise how many are done, and the open steps wait on the Dashboard.
        const rows = ['account', 'website', 'chatbot', 'forms', 'write'].map(key => $(`[data-done-row="${key}"]`)).filter(row => !row.hidden);
        const doneCount = rows.filter(row => row.dataset.ready === 'true').length;
        const complete = doneCount === rows.length;
        $('[data-done-heading]').textContent = complete ? __('You’re all set', 'gpt3-ai-content-generator')
            : sprintf(__('Almost there: %1$s of %2$s done', 'gpt3-ai-content-generator'), doneCount, rows.length);
        $('[data-done-hero]').hidden = !complete;
        const meter = $('[data-done-meter]');
        meter.hidden = complete;
        meter.style?.setProperty('--aipkit-setup-done', `${rows.length ? Math.round(doneCount / rows.length * 100) : 0}%`);
        $('[data-done-note]').hidden = complete;
        // Connected website setup: tested recipes for this site's plugins. A skipped read check starts with the safe first requests.
        const next = $('[data-done-next]');
        next.hidden = !(websiteGoal() && active);
        $('[data-done-next-title]').textContent = sprintf(__('Try next in %s', 'gpt3-ai-content-generator'), assistant);
        const readSkipped = verification.status !== 'verified';
        $('[data-done-first]').hidden = !readSkipped;
        const recipes = $$('[data-done-recipe]');
        recipes.forEach((recipe, index) => { recipe.hidden = readSkipped && index === recipes.length - 1 && recipes.length > 2; });
        // Connected website setup: keep Done available after launching the assistant; activity has its own action.
        const finish = $('[data-action="finish"]');
        finish.dataset.url = complete && websiteGoal() && active ? (home?.[1] || $('[data-done-activity]').href || '') : '';
        // An open step finishes on the Dashboard, where the checklist stays until it is done.
        finish.dataset.home = String(!complete);
        // With a step open, Connect now is the main action; going to the Dashboard is secondary.
        finish.classList.toggle('aipkit-setup__btn--ghost', !complete);
        $('[data-done-activity]').hidden = !finish.dataset.url;
        finish.textContent = !complete ? __('Go to Dashboard', 'gpt3-ai-content-generator')
            : websiteGoal() && active ? (home ? sprintf(__('Open %s', 'gpt3-ai-content-generator'), home[0]) : __('View website activity', 'gpt3-ai-content-generator'))
                : sprintf(__('Go to %s', 'gpt3-ai-content-generator'), moduleName(otherGoal()) || unique[0]);
    }

    function firstResultStep() {
        if (state.power === 'later') return 'done';
        return firstSteps[otherGoal()] || 'done';
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
            chat.addEventListener('aipkit:messageReceived', () => { state.results.add('chatbot'); void setup('chat_result').catch(() => {}); }, { once: true });
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

    async function leave(op, button, destination) {
        await busy(button, async () => {
            const data = await setup(op);
            window.location.assign(destination || data.url || config.dashboardUrl);
        });
    }

    // Shared creation result for AI form templates and the blog automation form.
    async function createTemplate(template, trigger, fields = {}) {
        const section = trigger.closest('.aipkit-setup__step');
        await busy(trigger, async () => {
            const data = await setup('template', { template, ...fields });
            state.saved.add(template === 'auto_blog' ? 'auto' : 'forms');
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
        back: (button) => {
            if (state.step === 'connect' && state.aiChoice && !deferredWebsite()) {
                return busy(button, async () => {
                    if (state.websiteLater) await setup('website_later', { later: '' });
                    state.aiPower = state.power;
                    state.aiChoice = false;
                    state.websiteLater = false;
                    window.history?.replaceState(null, '', '#connect');
                    go('connect');
                });
            }
            go(state.step === 'connect' && state.websiteReturn ? 'done' : backTo[state.step] || 'welcome');
        },
        skip: (button) => leave(state.step === 'done' ? 'finish' : 'skip', button, state.step === 'done' && $('[data-action="finish"]').dataset.home === 'true' ? config.dashboardUrl : undefined),
        finish: (button) => {
            if (state.busy) return;
            if (button.dataset.home === 'true') return leave('finish', button, config.dashboardUrl);
            if (!button.dataset.url) return leave('finish', button);
            // Reserve a tab during the click, but only launch after completion is saved. This tab stays on Done,
            // where View website activity and the recipes remain one click away.
            let tab;
            try { tab = window.open('', '_blank'); } catch (_) { /* Explain blocked popups below. */ }
            if (!tab) {
                showError(__('Your browser blocked the assistant tab. Allow popups and try again, or choose Website activity.', 'gpt3-ai-content-generator'));
                return;
            }
            tab.opener = null;
            return busy(button, async () => {
                try {
                    await setup('finish');
                    tab.location.replace(button.dataset.url);
                } catch (error) { tab.close(); throw error; }
            });
        },
        'done-open': (button) => leave('finish', button, button.href),
        done: () => go('done'),
        'save-goals': (button) => busy(button, async () => {
            state.goals = (await setup('goals', { goals: state.goals })).goals;
            Object.assign(state, { websiteLater: false, websiteReturn: false, aiChoice: false });
            window.history?.replaceState(null, '', '#connect');
            go('connect');
            if (websiteFlow() && root.dataset.websiteReady === 'true' && !deferredWebsite()) await assistantRequest('view');
        }),
        power: (button) => {
            accountEmail.cancel();
            state.power = button.dataset.power;
            state.aiPower = state.power;
            if (state.power !== 'key') keyRequest = null;
            renderPower();
            if (state.power === 'key') return loadProviderKey();
        },
        cloud: (button) => busy(button, async () => {
            if (!$('[data-cloud-email-field]').hidden && !field('cloud_email').reportValidity()) return;
            await connectCloud();
        }),
        'check-email': (button) => busy(button, () => connectCloud(true)),
        // Check your inbox: each button reuses what the account already has, so nothing new talks to Cloud or Freemius.
        'inbox-confirm': (button) => busy(button, checkEmailConfirmation),
        'inbox-resend': (button) => {
            inboxNote('');
            if (root.dataset.cloud === 'connected' && root.dataset.resendEmailUrl) {
                return accountEmail.resendEmail(button, $('[data-inbox-note]'), root.dataset.resendEmailUrl);
            }
            return busy(button, async () => {
                try { await connectCloud(); } catch (error) { inboxNote(error.message, 'bad'); return; }
                inboxNote(sprintf(__('Sent again to %s.', 'gpt3-ai-content-generator'), $('[data-inbox-email]').textContent), 'ok');
            });
        },
        'inbox-change': async (button) => {
            if (state.busy) return;
            inboxNote('');
            const edit = $('[data-action="edit-email"]');
            // A fresh SDK signup can return its pre-registration account controls. Refresh on
            // this explicit action so Change works immediately without a page reload or polling.
            if (root.dataset.cloud === 'connected' && edit?.hidden) {
                const data = await busy(button, () => setup('cloud_state'));
                if (!data) return;
                updateCloud(data);
            }
            root.dataset.inboxEditing = 'true';
            if (root.dataset.cloud === 'connected' && edit && !edit.hidden) edit.click();
            else field('cloud_email').focus?.();
        },
        'inbox-back': () => { root.dataset.inboxEditing = 'false'; },
        // Website setup: the fine-print link opens the same "What information do we collect?" details.
        'privacy-toggle': (button) => {
            const details = root.querySelector('[data-cloud-show="none"] details');
            if (!details) return;
            details.open = !details.open;
            button.setAttribute('aria-expanded', String(details.open));
            button.textContent = details.open ? __('Hide what we collect', 'gpt3-ai-content-generator') : __('What we collect', 'gpt3-ai-content-generator');
        },
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
        // Website-only finishes on Done. With other tools picked, they come next: straight to the first one when
        // Cloud already works, otherwise to the AI choices. Website stays open on the setup checklist.
        'website-later': (button) => busy(button, async () => {
            await setup('website_later', { later: 'yes' });
            state.websiteLater = true;
            if (!otherGoal() || state.websiteReturn) { go('done'); return; }
            accountEmail.cancel();
            await showAIChoice();
        }),
        // Done checklist: Connect now returns to the website step; a site that can't connect from here opens Settings.
        'website-connect': (button) => deferredWebsite() ? leave('finish', button, button.href) : busy(button, async () => {
            if (state.websiteLater) await setup('website_later', { later: '' });
            state.websiteLater = false;
            state.aiChoice = false;
            window.history?.replaceState(null, '', '#website');
            state.websiteReturn = Boolean(otherGoal());
            go('connect');
            // Like the first entry: read this site's assistants, which may have changed since.
            if (websiteFlow() && root.dataset.websiteReady === 'true') await assistantRequest('view');
        }),
        // While ChatGPT waits, switch to Claude (and back) with a new link, opened by this click.
        'assistant-switch': () => {
            const form = $('[data-onboarding-assistant]');
            const other = form.querySelector(`[data-assistant="${state.assistant === 'claude' ? 'chatgpt' : 'claude'}"]`);
            if (!other || typeof form.requestSubmit !== 'function') { state.assistantChoosing = true; renderPower(); return; }
            form.requestSubmit(other);
        },
        'writing-mode': (button) => {
            if (state.goals.includes('write') && writingSteps.includes(state.step) && writingSteps.includes(button.dataset.writingStep)) go(button.dataset.writingStep);
        },
        'check-connection': (button) => busy(button, checkConnection),
        'check-assistant': (button) => busy(button, checkAssistant),
        // Try again or Start again: the same assistant, a fresh connection, with the tab opened by this click.
        'assistant-again': () => {
            const form = $('[data-onboarding-assistant]');
            const button = form.querySelector(`[data-assistant="${state.assistant}"]`);
            if (!button || typeof form.requestSubmit !== 'function') { state.assistantChoosing = true; renderPower(); return; }
            form.requestSubmit(button);
        },
        'assistant-choose': () => {
            Object.assign(state, { assistant: '', assistantChoosing: true, assistantFailed: '', assistantNotYet: false, assistantAwaiting: '', assistantOpened: false });
            renderPower();
        },
        // Reserve the tab during the click; navigate only after the copy actually succeeds.
        'copy-open-assistant': (button) => busy(button, async () => {
            const copy = navigator.clipboard?.writeText;
            if (typeof copy !== 'function') { showError(__('Copying failed. Select the text and copy it yourself.', 'gpt3-ai-content-generator')); return; }
            const tab = button.dataset.url && typeof window.open === 'function' ? window.open('', '_blank') : null;
            if (tab) tab.opener = null;
            try {
                await navigator.clipboard.writeText($('[data-website-prompt]').textContent.trim());
                if (tab) tab.location.replace(button.dataset.url);
                else {
                    button.textContent = __('Copied', 'gpt3-ai-content-generator');
                    if (button.dataset.url) showError(__('Prompt copied. Open your assistant and paste it there.', 'gpt3-ai-content-generator'));
                }
            } catch (error) {
                tab?.close();
                showError(__('Copying failed. Select the text and copy it yourself.', 'gpt3-ai-content-generator'));
            }
        }),
        'copy-website-prompt': (button) => busy(button, async () => {
            try {
                if (typeof navigator.clipboard?.writeText !== 'function') throw new Error('clipboard_unavailable');
                await navigator.clipboard.writeText($('[data-website-prompt]').textContent.trim());
                button.textContent = __('Copied', 'gpt3-ai-content-generator');
            } catch (error) { showError(__('Copying failed. Select the text and copy it yourself.', 'gpt3-ai-content-generator')); }
        }),
        'after-connect': (button) => busy(button, async () => {
            const website = websiteFlow();
            if (website) {
                if (deferredWebsite() || !websiteVerificationVisible()) return;
                // Back from Done, the other tools are already set up: never redo their AI connection.
                await refreshWebsiteAllowance();
                if (!otherGoal() || state.websiteReturn) { go('done'); return; }
                await showAIChoice();
                return;
            }
            if (state.power === 'cloud') {
                const data = await connectCloud(false, true);
                // The website is connected but Cloud can't run AI yet (credits or models): show the AI choices and why.
                if (!data.ready) { if (website) state.websiteLater = true; return; }
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
            state.saved.add('products');
            state.product = null;
            $('[data-product-replaces]').hidden = true;
            $('[data-product-title]').textContent = data.message;
            button.hidden = true;
            const link = $('[data-product-edit]');
            link.href = data.editUrl;
            link.hidden = false;
        }),
        'retry-website-verification': () => checkWebsiteRead('retry'),
        'copy-assistant-setup': async (button) => {
            try { await navigator.clipboard.writeText(button.dataset.mcpCopy); button.textContent = __('Copied', 'gpt3-ai-content-generator'); }
            catch (_) { showError(__('Copying failed. Select the text and copy it yourself.', 'gpt3-ai-content-generator')); }
        },
        'copy-product': (button) => {
            const text = button.closest('[data-product-result]').dataset.text || '';
            navigator.clipboard?.writeText(text).then(() => { button.textContent = __('Copied', 'gpt3-ai-content-generator'); }, () => showError(__('Copying failed. Select the text and copy it yourself.', 'gpt3-ai-content-generator')));
        },
        'load-chat': () => loadChatWidget(true),
        'publish-bot': (button) => busy(button, async () => {
            await setup('publish_bot');
            state.saved.add('chatbot');
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
            state.goals = state.goals.includes(id) ? state.goals.filter((item) => item !== id) : state.goals.concat(id);
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
        const assistantForm = event.target.closest('[data-onboarding-assistant]');
        if (assistantForm) {
            event.preventDefault();
            const button = event.submitter;
            if (!button || state.busy || state.connectionCheck !== 'passed' || !assistantForm.reportValidity()) return;
            // A large ChatGPT or Claude button keeps its layout: only its name line changes on a failed attempt.
            const label = button.querySelector('strong') || button;
            button.dataset.connectLabel ||= label.textContent;
            // ChatGPT and Claude: this click copies the connection address and reserves their tab, which opens on
            // the new link once it exists. A blocked tab leaves Open ChatGPT in the panel; a failed copy shows the address to copy.
            const client = button.dataset.assistant;
            let tab = null;
            if (assistantNames[client]) {
                const address = $('[data-assistant-address]')?.textContent.trim();
                state.addressCopied = false;
                if (address && typeof navigator.clipboard?.writeText === 'function') {
                    navigator.clipboard.writeText(address).then(() => { state.addressCopied = true; renderPower(); }, () => {});
                }
                try { tab = typeof window.open === 'function' ? window.open('', '_blank') : null; } catch (_) { tab = null; }
                if (tab) tab.opener = null;
            }
            busy(button, async () => {
                state.assistant = client;
                Object.assign(state, { assistantChoosing: false, assistantFailed: '', assistantNotYet: false, assistantAwaiting: '', assistantOpened: false });
                try {
                    if (state.connectionCheck !== 'passed') { tab?.close(); return; }
                    const data = await assistantRequest('connect', { client });
                    label.textContent = button.dataset.connectLabel;
                    // Links started here stay usable: approving an earlier one (ChatGPT after switching to Claude) still counts.
                    const created = pendingFor(client)?.grant_id;
                    if (created && !state.linkGrants.includes(created)) state.linkGrants.push(created);
                    let link = '';
                    try { link = handoff(data.pair?.connect_url || pendingFor(client)?.connect_url); } catch (_) { link = ''; }
                    if (tab && link) { tab.location.replace(link); state.assistantOpened = true; } else tab?.close();
                } catch (error) {
                    tab?.close();
                    label.textContent = __('Retry connection', 'gpt3-ai-content-generator');
                    state.assistantFailed = error.message || __('Something went wrong. Try again.', 'gpt3-ai-content-generator');
                }
            });
            return;
        }
    });

    field('consent').addEventListener('change', renderPower);
    field('cloud_email').addEventListener('input', () => {
        if (root.dataset.cloudPending !== 'true') return;
        config.cloudConsent = false;
        root.dataset.cloudPending = 'false';
        $('[data-action="check-email"]').hidden = true;
        $('[data-action="cloud"]').textContent = cloudButtonLabel();
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
                state.saved.add('write');
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
    field('consent').checked = config.cloudConsent === true;
    if (window.location.hash === '#website') { state.websiteLater = false; state.websiteReturn = Boolean(otherGoal()); }
    if (window.location.hash === '#ai' && websiteGoal() && otherGoal()) { state.aiChoice = true; state.power = state.aiPower; }
    go(['#connect', '#website', '#ai'].includes(window.location.hash) || config.resume === true ? 'connect' : 'welcome');
    if (state.step === 'connect' && state.power === 'key') void loadProviderKey();
    // A return checks the same lightweight status as the bounded watcher, without usage or safety-level refreshes.
    let returning = false;
    async function resumeConnection() {
        if (state.step !== 'connect' || state.busy || returning || document.visibilityState === 'hidden') return;
        returning = true;
        try {
            if (root.dataset.cloudPending === 'true' && (config.cloudConsent === true || field('consent').checked || root.dataset.cloud === 'connected')) {
                await busy($('[data-action="inbox-confirm"]'), checkEmailConfirmation);
            }
            const client = state.assistant;
            const pending = !state.assistantChoosing && pendingFor(client);
            if (websiteFlow() && root.dataset.websiteReady === 'true' && !deferredWebsite() && pending) {
                // Background reconciliation must not lock the screen or overwrite a later user choice.
                try {
                    await pollAssistantLink(pending.grant_id);
                } catch (_) { /* Keep the current screen; manual Check remains available. */ }
            }
        } finally { returning = false; }
    }
    window.addEventListener('focus', resumeConnection);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') { pauseWebsiteVerification(); clearTimeout(linkWatch.timer); linkWatch.timer = null; }
        else { void resumeConnection(); if (websiteVerificationVisible() && verification.status === 'waiting') void checkWebsiteRead(verification.attempt ? 'check' : 'start'); }
    });
    window.addEventListener('pagehide', () => { pageGone = true; pauseWebsiteVerification(); watchAssistantLink(null); });
    window.addEventListener('pageshow', () => { pageGone = false; renderPower(); });
    if (state.step === 'connect') void resumeConnection();
}
